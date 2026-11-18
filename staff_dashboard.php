<?php
require_once __DIR__ . '/staff_dashboard_helpers.php';
require_once __DIR__ . '/auth.php';
require_role('staff', 'admin');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
    header('Allow: GET, HEAD, POST');
    http_response_code(405);
    exit('Unsupported request method.');
}
$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
$today = $now->format('Y-m-d');
$selectedDate = $today;
$statusFilter = 'all';
$courtFilter = '';
$page = 1;
$approvalLabels = ['pending' => 'Pending', 'approved' => 'Approved', 'cancelled' => 'Cancelled', 'rejected' => 'Rejected'];
$errors = [];
$currentUser = null;
$courts = [];
$reservations = [];
$dataReady = false;
$progressReady = false;
$flash = $_SESSION['staff_progress_flash'] ?? '';
unset($_SESSION['staff_progress_flash']);
if (empty($_SESSION['staff_progress_csrf'])) {
    $_SESSION['staff_progress_csrf'] = bin2hex(random_bytes(32));
}

if (isset($_GET['date'])) {
    if (sp_valid_date($_GET['date'])) { $selectedDate = $_GET['date']; }
    else { $errors[] = 'Invalid date. Showing today instead.'; }
}
// Always include all booking statuses/courts. Old filter URLs must not hide
// newly submitted requests now that this layout has no filter controls.
if (isset($_GET['page'])) { $page = sp_integer($_GET['page'], 1) ?: 1; }

try {
    $userId = (int) $_SESSION['user_id'];
    $stmt = $conn->prepare('SELECT user_id, first_name, role FROM users WHERE user_id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $currentUser = $stmt->get_result()->fetch_assoc();
    if (!$currentUser || !in_array($currentUser['role'], ['staff', 'admin'], true)) {
        http_response_code(403);
        exit('Only staff and administrators can access this dashboard.');
    }
} catch (mysqli_sql_exception $e) {
    error_log('Playbook staff dashboard: account lookup failed, code ' . $e->getCode());
    http_response_code(503);
    exit('The dashboard is temporarily unavailable. Please contact your administrator.');
}

if ($method === 'POST') {
    $token = sp_post('csrf_token');
    if ($token === '' || !hash_equals($_SESSION['staff_progress_csrf'], $token)) {
        http_response_code(403);
        exit('This form has expired. Return to the dashboard, refresh, and try again.');
    }
    $reservationId = sp_integer(sp_post('reservation_id'), 1);
    $expectedVersion = sp_integer(sp_post('progress_version'), 0);
    $targetProgress = sp_post('progress');
    $saveErrors = [];
    if ($reservationId === false || $expectedVersion === false ||
        $targetProgress !== 'completed') {
        $saveErrors[] = 'Invalid progress update. Refresh the dashboard and try again.';
    }
    $inTransaction = false;
    if (!$saveErrors) {
        try {
            // Both tables must support row locks/rollback for concurrency checks.
            $engines = $conn->query(
                "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('reservations', 'reservation_staff_progress')"
            )->fetch_all(MYSQLI_ASSOC);
            $engineMap = [];
            foreach ($engines as $engine) { $engineMap[$engine['TABLE_NAME']] = strtolower((string) $engine['ENGINE']); }
            if (($engineMap['reservations'] ?? '') !== 'innodb' || ($engineMap['reservation_staff_progress'] ?? '') !== 'innodb') {
                error_log('Playbook staff progress requires reservations and reservation_staff_progress to use InnoDB.');
                throw new RuntimeException('Progress updates are not ready. Please ask your administrator to complete the setup.');
            }
            $conn->begin_transaction();
            $inTransaction = true;
            $stmt = $conn->prepare('SELECT reservation_id, reservation_date, status FROM reservations WHERE reservation_id = ? FOR UPDATE');
            $stmt->bind_param('i', $reservationId);
            $stmt->execute();
            $booking = $stmt->get_result()->fetch_assoc();
            if (!$booking) { throw new RuntimeException('That reservation no longer exists.'); }
            if ($booking['status'] !== 'approved') {
                throw new RuntimeException('Only approved reservations can be completed. Refresh to see the latest approval status.');
            }
            // Use a fresh date after acquiring the lock, including across midnight.
            $writeNow = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
            $writeToday = $writeNow->format('Y-m-d');
            if ($booking['reservation_date'] !== $writeToday) {
                throw new RuntimeException('Progress can be changed only on the reservation date.');
            }
            $stmt = $conn->prepare('SELECT progress, version, started_at, completed_at FROM reservation_staff_progress WHERE reservation_id = ? FOR UPDATE');
            $stmt->bind_param('i', $reservationId);
            $stmt->execute();
            $stored = $stmt->get_result()->fetch_assoc();
            $currentProgress = $stored ? $stored['progress'] : 'not_started';
            $currentVersion = $stored ? (int) $stored['version'] : 0;

            if ($targetProgress === $currentProgress) {
                $conn->commit();
                $inTransaction = false;
                $_SESSION['staff_progress_flash'] = 'Reservation #' . $reservationId . ' is already ' . strtolower(sp_progress_label($currentProgress)) . '.';
            } else {
                if ($expectedVersion !== $currentVersion) {
                    throw new RuntimeException('Another staff member updated this reservation. Review its latest progress and try again.');
                }
                if (!sp_transition_allowed($booking['status'], $booking['reservation_date'], $writeToday, $currentProgress, $targetProgress)) {
                    throw new RuntimeException('That progress change is not allowed. Completed reservations cannot be reopened here.');
                }
                $utcNow = $writeNow->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                $startedAt = $stored['started_at'] ?? null;
                $completedAt = $stored['completed_at'] ?? null;
                if ($targetProgress === 'completed') { $completedAt = $utcNow; }
                // Direct completion does not invent a start timestamp.
                if ($stored) {
                    $stmt = $conn->prepare(
                        'UPDATE reservation_staff_progress
                         SET progress = ?, version = version + 1, started_at = ?, completed_at = ?, updated_by = ?, updated_at = ?
                         WHERE reservation_id = ? AND version = ?'
                    );
                    $stmt->bind_param('sssisii', $targetProgress, $startedAt, $completedAt, $userId, $utcNow, $reservationId, $expectedVersion);
                    $stmt->execute();
                    if ($stmt->affected_rows !== 1) { throw new RuntimeException('The progress changed. Refresh and try again.'); }
                } else {
                    $stmt = $conn->prepare(
                        'INSERT INTO reservation_staff_progress
                         (reservation_id, progress, version, started_at, completed_at, updated_by, updated_at)
                         VALUES (?, ?, 1, ?, ?, ?, ?)'
                    );
                    $stmt->bind_param('isssis', $reservationId, $targetProgress, $startedAt, $completedAt, $userId, $utcNow);
                    $stmt->execute();
                }
                $conn->commit();
                $inTransaction = false;
                $_SESSION['staff_progress_flash'] = 'Reservation #' . $reservationId . ' marked ' . strtolower(sp_progress_label($targetProgress)) . '.';
            }
            header('Location: ' . sp_view_url($selectedDate, $today, $statusFilter, $courtFilter, $page), true, 303);
            exit;
        } catch (mysqli_sql_exception $e) {
            if ($inTransaction) { $conn->rollback(); }
            error_log('Playbook staff progress save failed, code ' . $e->getCode());
            $saveErrors[] = 'The progress could not be saved. Refresh and try again, or contact your administrator.';
        } catch (RuntimeException $e) {
            if ($inTransaction) { $conn->rollback(); }
            $saveErrors[] = $e->getMessage();
        }
    }
    $errors = array_merge($errors, $saveErrors);
}

try {
    $courts = $conn->query('SELECT court_id, court_name FROM courts ORDER BY court_name')->fetch_all(MYSQLI_ASSOC);
    try {
        $conn->query('SELECT reservation_id FROM reservation_staff_progress LIMIT 0');
        $progressReady = true;
    } catch (mysqli_sql_exception $e) {
        if ((int) $e->getCode() !== 1146) { throw $e; }
        $errors[] = 'Progress controls are not ready yet. Ask your administrator to complete setup. Reservations are still shown below.';
    }
    if ($progressReady) {
        $query = "SELECT r.reservation_id, r.court_id, r.reservation_date, r.start_time, r.end_time,
                         r.purpose, r.status, c.court_name, u.first_name, u.last_name,
                         COALESCE(p.progress, 'not_started') AS staff_progress,
                         COALESCE(p.version, 0) AS progress_version, p.started_at, p.completed_at
                  FROM reservations r
                  LEFT JOIN courts c ON c.court_id = r.court_id
                  LEFT JOIN users u ON u.user_id = r.user_id
                  LEFT JOIN reservation_staff_progress p ON p.reservation_id = r.reservation_id
                  WHERE r.reservation_date = ?
                  ORDER BY r.start_time, r.end_time, r.reservation_id";
    } else {
        $query = "SELECT r.reservation_id, r.court_id, r.reservation_date, r.start_time, r.end_time,
                         r.purpose, r.status, c.court_name, u.first_name, u.last_name,
                         NULL AS staff_progress, 0 AS progress_version, NULL AS started_at, NULL AS completed_at
                  FROM reservations r
                  LEFT JOIN courts c ON c.court_id = r.court_id
                  LEFT JOIN users u ON u.user_id = r.user_id
                  WHERE r.reservation_date = ?
                  ORDER BY r.start_time, r.end_time, r.reservation_id";
    }
    $stmt = $conn->prepare($query);
    $stmt->bind_param('s', $selectedDate);
    $stmt->execute();
    $reservations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $dataReady = true;
} catch (mysqli_sql_exception $e) {
    error_log('Playbook staff dashboard load failed, code ' . $e->getCode());
    $errors[] = 'Reservations could not be loaded. Please contact your administrator.';
    http_response_code(503);
}

$courtNames = [];
foreach ($courts as $court) { $courtNames[(int) $court['court_id']] = $court['court_name']; }
foreach ($reservations as $r) {
    if (!isset($courtNames[(int) $r['court_id']])) {
        $courtNames[(int) $r['court_id']] = 'Court #' . (int) $r['court_id'] . ' (record unavailable)';
    }
}
if ($dataReady && $courtFilter !== '' && !isset($courtNames[(int) $courtFilter])) {
    $errors[] = 'That court could not be found. Showing all courts.';
    $courtFilter = '';
}
$filtered = [];
foreach ($reservations as $r) {
    if ($statusFilter !== 'all' && $r['status'] !== $statusFilter) { continue; }
    if ($courtFilter !== '' && (int) $r['court_id'] !== (int) $courtFilter) { continue; }
    $filtered[] = $r;
}
$total = count($filtered);
$pageCount = max(1, (int) ceil($total / 20));
$page = min($page, $pageCount);
$pageRows = array_slice($filtered, ($page - 1) * 20, 20);
$pageUrl = sp_view_url($selectedDate, $today, $statusFilter, $courtFilter, $page);
$isAdmin = $currentUser['role'] === 'admin';
// Optional menu links: use the real filenames from your group's project.
// Empty values display an unavailable menu item instead of a broken link.
// Destination pages keep their own access checks; this does not grant roles.
$menuLinks = [
    'Equipment' => $isAdmin ? 'admin_equipment.php' : '',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Playbook — Staff Dashboard</title>
  <!-- Styles are embedded so a missing assets folder cannot remove the layout. -->
  <style>
* { box-sizing: border-box; }
    html { font-size: clamp(10px, 1.171875vw, 24px); }
    body {
      margin: 0;
      background: #f8f9fa;
      color: #212529;
      font-family: Arial, Helvetica, sans-serif;
      line-height: 1.5;
    }
    .container { width: 76.2%; max-width: 1560px; margin: 0 auto; }
    .navbar { background: #212529; color: #fff; }
    .navbar .container {
      min-height: 3.0833rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
    }
    .brand { color: #f8f9fa; font-size: 1rem; text-decoration: none; }
    .nav-links { display: flex; align-items: center; gap: .4rem; }
    .nav-link {
      display: inline-block;
      padding: .3rem .625rem;
      border: 1px solid #adb5bd;
      border-radius: .25rem;
      color: #f8f9fa;
      font-size: .8333rem;
      text-decoration: none;
      white-space: nowrap;
    }
    .nav-link:hover { color: #212529; background: #f8f9fa; }
    main { padding-top: 1.25rem; }
    h1 { margin: 0 0 .3rem; font-size: 1.25rem; line-height: 1.3; font-weight: 500; }
    .table-wrap { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; background: #fff; table-layout: fixed; }
    th, td {
      border: 1px solid #dee2e6;
      padding: .4167rem;
      text-align: left;
      vertical-align: middle;
      font-size: .75rem;
      overflow-wrap: anywhere;
    }
    th { font-weight: 700; }
    .complete-button {
      border: 1px solid #198754;
      border-radius: .2rem;
      padding: .25rem .5rem;
      color: #fff;
      background: #198754;
      font: inherit;
      line-height: 1.5;
      cursor: pointer;
      white-space: nowrap;
    }
    .complete-button:hover { background: #157347; border-color: #146c43; }
    .completed { color: #198754; font-weight: 700; white-space: nowrap; }
    a:focus-visible, button:focus-visible { outline: 2px solid #0d6efd; outline-offset: 3px; }
    @media (max-width: 600px) {
      html { font-size: 16px; }
      .container { width: calc(100% - 28px); }
      .navbar .container { align-items: flex-start; flex-direction: column; padding: 10px 0; gap: 8px; }
      .nav-links { flex-wrap: wrap; }
      .nav-link { font-size: 12px; }
      table { min-width: 660px; }
    }
  
/* Live PHP controls, feedback, and pagination. */
form { margin: 0; }
.complete-button:disabled { opacity: .5; cursor: not-allowed; }
.complete-button:disabled:hover { background: #198754; border-color: #198754; }
.completion-unavailable { display: inline-block; cursor: not-allowed; }
.completion-unavailable:focus-visible { outline: 2px solid #0d6efd; outline-offset: 3px; }
.nav-unavailable { cursor: not-allowed; }
.nav-unavailable:hover { color: #f8f9fa; background: transparent; }
.message { margin: 0 0 .75rem; padding: .5rem .75rem; border: 1px solid; border-radius: .2rem; font-size: .75rem; }
.success { color: #0f5132; background: #d1e7dd; border-color: #badbcc; }
.error { color: #842029; background: #f8d7da; border-color: #f5c2c7; }
.pagination { display: flex; justify-content: flex-end; align-items: center; gap: 1rem; margin-top: .75rem; font-size: .75rem; }
.pagination a { color: #0d6efd; }
.visually-hidden { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
  </style>
</head>
<body data-viewing-today="<?= $selectedDate === $today ? '1' : '0' ?>" data-auto-refresh="<?= $method === 'GET' && !$errors ? '1' : '0' ?>">
  <header class="navbar">
    <nav class="container" aria-label="Staff navigation">
      <a class="brand" href="staff_dashboard.php">Playbook</a>
      <div class="nav-links">
        <?php foreach ($menuLinks as $label => $link): ?>
          <?php if ($link !== ''): ?>
            <a class="nav-link" href="<?= sp_escape($link) ?>"><?= sp_escape($label) ?></a>
          <?php else: ?>
            <span class="nav-link nav-unavailable" aria-disabled="true" title="Currently unavailable"><?= sp_escape($label) ?></span>
          <?php endif; ?>
        <?php endforeach; ?>
        <a class="nav-link" href="logout.php">Log Out</a>
      </div>
    </nav>
  </header>

  <main class="container">
    <?php if ($flash !== ''): ?><div class="message success" role="status"><?= sp_escape($flash) ?></div><?php endif; ?>
    <?php foreach (array_unique($errors) as $error): ?><div class="message error" role="alert"><?= sp_escape($error) ?></div><?php endforeach; ?>
    <h1><?= $selectedDate === $today ? "Today's Reservations" : 'Reservations for ' . sp_escape($selectedDate) ?></h1>
    <?php if ($dataReady): ?>
    <div class="table-wrap">
      <table aria-label="Reservations">
        <colgroup>
          <col style="width:22.4%">
          <col style="width:14.9%">
          <col style="width:13.1%">
          <col style="width:13.5%">
          <col style="width:19.6%">
          <col style="width:16.5%">
        </colgroup>
        <thead>
          <tr>
            <th scope="col">Customer</th>
            <th scope="col">Court</th>
            <th scope="col">Date</th>
            <th scope="col">Time</th>
            <th scope="col">Purpose</th>
            <th scope="col">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pageRows as $r): ?>
          <?php
            $customer = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
            $progress = $r['staff_progress'];
            $canComplete = $progressReady && $r['status'] === 'approved'
                && $selectedDate === $today && in_array('completed', sp_next_progress($progress), true);
            if (!$progressReady) { $unavailableReason = 'Completion setup is required.'; }
            elseif ($r['status'] === 'pending') { $unavailableReason = 'Awaiting administrator approval.'; }
            elseif ($r['status'] !== 'approved') { $unavailableReason = 'Booking is ' . ($approvalLabels[$r['status']] ?? 'inactive') . '.'; }
            elseif ($selectedDate !== $today) { $unavailableReason = 'Available on the reservation date.'; }
            else { $unavailableReason = 'Completion is unavailable for this reservation.'; }
          ?>
          <tr>
            <td title="Reservation #<?= (int) $r['reservation_id'] ?>"><?= sp_escape($customer !== '' ? $customer : 'Customer record unavailable') ?></td>
            <td><?= sp_escape($courtNames[(int) $r['court_id']]) ?></td>
            <td><?= sp_escape($r['reservation_date']) ?></td>
            <td><?= sp_escape(sp_time_label($r['start_time'])) ?>–<?= sp_escape(sp_time_label($r['end_time'])) ?></td>
            <td><?= sp_escape($r['purpose'] ?? '') ?></td>
            <td>
              <?php if ($canComplete): ?>
                <form method="post" action="<?= sp_escape($pageUrl) ?>">
                  <input type="hidden" name="csrf_token" value="<?= sp_escape($_SESSION['staff_progress_csrf']) ?>">
                  <input type="hidden" name="reservation_id" value="<?= (int) $r['reservation_id'] ?>">
                  <input type="hidden" name="progress_version" value="<?= (int) $r['progress_version'] ?>">
                  <input type="hidden" name="progress" value="completed">
                  <button class="complete-button" type="submit" aria-label="Mark reservation <?= (int) $r['reservation_id'] ?> complete">Mark Complete</button>
                </form>
              <?php elseif ($progressReady && $progress === 'completed'): ?>
                <span class="completed" title="<?= sp_escape(sp_timestamp_label($r['completed_at'])) ?>">✓ Completed</span>
              <?php else: ?>
                <span class="completion-unavailable" tabindex="0" title="<?= sp_escape($unavailableReason) ?>" aria-label="<?= sp_escape($unavailableReason) ?>">
                  <button class="complete-button" type="button" disabled>Mark Complete</button>
                </span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (!$pageRows): ?><p class="visually-hidden" role="status">No reservations for <?= sp_escape($selectedDate) ?>.</p><?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($pageCount > 1): ?><nav class="pagination" aria-label="Reservation pages">
      <?php if ($page > 1): ?><a href="<?= sp_escape(sp_view_url($selectedDate, $today, $statusFilter, $courtFilter, $page - 1)) ?>">Previous</a><?php endif; ?>
      <span>Page <?= $page ?> of <?= $pageCount ?></span>
      <?php if ($page < $pageCount): ?><a href="<?= sp_escape(sp_view_url($selectedDate, $today, $statusFilter, $courtFilter, $page + 1)) ?>">Next</a><?php endif; ?>
    </nav><?php endif; ?>
  </main>
  <script>
(function () {
  'use strict';
  var dirty = false;
  var note = document.getElementById('sp-refresh-note');
  var refresh = document.getElementById('sp-refresh');
  var canRefresh = document.body.getAttribute('data-auto-refresh') === '1';
  document.addEventListener('change', function (event) {
    if (event.target.closest && event.target.closest('form')) {
      dirty = true;
      if (note) note.textContent = 'Automatic refresh paused while you edit';
    }
  });
  document.addEventListener('input', function (event) {
    if (event.target.closest && event.target.closest('form')) {
      dirty = true;
      if (note) note.textContent = 'Automatic refresh paused while you edit';
    }
  });
  document.addEventListener('submit', function () {
    // Do not refresh away from a completion request while it is being sent.
    dirty = true;
    if (note) note.textContent = 'Saving / loading your selection…';
  });
  if (refresh) {
    refresh.addEventListener('click', function (event) {
      if (dirty && !window.confirm('Discard the unsaved selection and refresh?')) event.preventDefault();
    });
  }
  if (!canRefresh) return;
  window.setInterval(function () {
    if (dirty || document.hidden) return;
    var url = new URL(window.location.href);
    // Keep a today's view following Philippine midnight instead of pinning an old date.
    if (document.body.getAttribute('data-viewing-today') === '1') url.searchParams.delete('date');
    window.location.replace(url.toString());
  }, 30000);
})();
  </script>
</body>
</html>
