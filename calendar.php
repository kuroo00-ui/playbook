<?php
include 'auth.php';
require_login();   // any logged-in user: customer, staff or admin

$view = $_SESSION['view'] ?? $_SESSION['role'];
// Only staff/admin (and only while NOT in customer view) see who booked.
$showNames = in_array($_SESSION['role'], ['staff', 'admin']) && $view !== 'customer';

// ---- Which court and which month? ----
$courts = $conn->query("SELECT court_id, court_name, status, opening_time, closing_time FROM courts ORDER BY court_name")->fetch_all(MYSQLI_ASSOC);
$court_id = (int)($_GET['court_id'] ?? ($courts[0]['court_id'] ?? 0));

$court = null;
foreach ($courts as $c) { if ($c['court_id'] == $court_id) { $court = $c; } }
if (!$court && $courts) { $court = $courts[0]; $court_id = (int)$court['court_id']; }

$month = $_GET['month'] ?? date('Y-m');
$first = DateTime::createFromFormat('Y-m-d', $month . '-01');
if (!$first || $first->format('Y-m') !== $month) { $first = new DateTime(date('Y-m-01')); }
$month    = $first->format('Y-m');
$daysIn   = (int)$first->format('t');
$offset   = (int)$first->format('w');          // 0 = Sunday
$startStr = $first->format('Y-m-01');
$endStr   = $first->format('Y-m-t');
$prev     = (clone $first)->modify('-1 month')->format('Y-m');
$next     = (clone $first)->modify('+1 month')->format('Y-m');

// ---- Collect everything that happens in this month, grouped by date ----
$byDay = [];

if ($court) {
  $stmt = $conn->prepare(
    "SELECT r.reservation_date, r.start_time, r.end_time, r.status, r.purpose, u.first_name, u.last_name
     FROM reservations r JOIN users u ON u.user_id = r.user_id
     WHERE r.court_id = ? AND r.reservation_date BETWEEN ? AND ?
       AND r.status IN ('pending','approved')");
  $stmt->bind_param("iss", $court_id, $startStr, $endStr);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) {
    $label = $showNames
      ? trim($r['first_name'] . ' ' . $r['last_name'] . ($r['purpose'] ? ' - ' . $r['purpose'] : ''))
      : ($r['status'] === 'pending' ? 'Pending' : 'Reserved');
    $byDay[$r['reservation_date']][] = [
      'start' => $r['start_time'], 'end' => $r['end_time'],
      'type'  => $r['status'],     'label' => $label
    ];
  }

  $stmt = $conn->prepare(
    "SELECT schedule_date, start_time, end_time FROM schedules
     WHERE court_id = ? AND schedule_status = 'blocked' AND schedule_date BETWEEN ? AND ?");
  $stmt->bind_param("iss", $court_id, $startStr, $endStr);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($s = $res->fetch_assoc()) {
    $byDay[$s['schedule_date']][] = [
      'start' => $s['start_time'], 'end' => $s['end_time'], 'type' => 'blocked', 'label' => 'Unavailable'
    ];
  }
  foreach ($byDay as &$list) { usort($list, fn($a, $b) => strcmp($a['start'], $b['start'])); }
  unset($list);
}

$badge = ['approved' => 'bg-success', 'pending' => 'bg-warning text-dark', 'blocked' => 'bg-secondary'];
$today = date('Y-m-d');
$totalCells = (int)(ceil(($offset + $daysIn) / 7) * 7);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Court Schedule - Playbook</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .cal td { height: 120px; width: 14.28%; vertical-align: top; padding: 4px; }
    .day-cell { cursor: pointer; }
    .day-cell:hover { background: #e9f7ef; }
    .cal .daynum { font-weight: 600; font-size: .85rem; }
    .cal .past { background: #f1f1f1; }
    .cal .today { outline: 2px solid #198754; outline-offset: -2px; }
    .cal .slot { display: block; font-size: .9rem; margin-top: 4px; padding: .4em .5em; white-space: normal; text-align: left; }
  </style>
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container">
    <a class="navbar-brand" href="index.php">Playbook</a>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-light" href="index.php">Home</a>
      <?php include 'view_switcher.php'; ?>
      <a class="btn btn-outline-light" href="logout.php">Log Out</a>
    </div>
  </div>
</nav>

<div class="container">
  <h4>Court Schedule</h4>

  <?php if (!$court): ?>
    <div class="alert alert-secondary">No courts have been added yet.</div>
  <?php else: ?>

  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <form method="GET" class="m-0">
      <input type="hidden" name="month" value="<?= $month ?>">
      <select name="court_id" class="form-select" onchange="this.form.submit()">
        <?php foreach ($courts as $c): ?>
          <option value="<?= $c['court_id'] ?>" <?= $c['court_id'] == $court_id ? 'selected' : '' ?>>
            <?= htmlspecialchars($c['court_name']) ?><?= $c['status'] !== 'available' ? ' (' . $c['status'] . ')' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>

    <div class="d-flex align-items-center gap-2">
      <a class="btn btn-outline-secondary" href="?court_id=<?= $court_id ?>&month=<?= $prev ?>">&laquo; Prev</a>
      <strong style="min-width:150px;text-align:center"><?= $first->format('F Y') ?></strong>
      <a class="btn btn-outline-secondary" href="?court_id=<?= $court_id ?>&month=<?= $next ?>">Next &raquo;</a>
      <a class="btn btn-outline-success" href="?court_id=<?= $court_id ?>">Today</a>
    </div>
  </div>

  <?php if ($court['status'] !== 'available'): ?>
    <div class="alert alert-warning">This court is currently marked <strong><?= htmlspecialchars($court['status']) ?></strong> and cannot be reserved.</div>
  <?php endif; ?>

  <div class="mb-3 d-flex flex-wrap gap-2">
  <span class="badge fs-6 bg-success">Reserved</span>
  <span class="badge fs-6 bg-warning text-dark">Pending approval</span>
  <span class="badge fs-6 bg-secondary">Unavailable / blocked</span>
    </div>

  <table class="table table-bordered bg-white cal">
    <thead class="table-dark text-center">
      <tr><th>Sun</th><th>Mon</th><th>Tue</th><th>Wed</th><th>Thu</th><th>Fri</th><th>Sat</th></tr>
    </thead>
    <tbody>
    <?php for ($i = 0; $i < $totalCells; $i++): ?>
      <?php if ($i % 7 === 0): ?><tr><?php endif; ?>
      <?php $d = $i - $offset + 1; ?>
      <?php if ($d < 1 || $d > $daysIn): ?>
        <td class="bg-light"></td>
      <?php else: ?>
        <?php $date = sprintf('%s-%02d', $month, $d); ?>
        <?php
         $cnt = ['approved' => 0, 'pending' => 0, 'blocked' => 0];
        foreach ($byDay[$date] ?? [] as $s) { $cnt[$s['type']]++; }
        $short = ['approved' => 'Reserved', 'pending' => 'Pending', 'blocked' => 'Blocked'];
        ?>
    <td class="day-cell <?= $date < $today ? 'past' : '' ?> <?= $date === $today ? 'today' : '' ?>"
        data-date="<?= $date ?>" title="Click to view this day's schedule">
    <div class="daynum"><?= $d ?></div>
    <?php foreach ($cnt as $type => $n): if ($n > 0): ?>
        <span class="badge slot <?= $badge[$type] ?>"><?= $n ?> <?= $short[$type] ?></span>
    <?php endif; endforeach; ?>
    </td>
      <?php endif; ?>
      <?php if ($i % 7 === 6): ?></tr><?php endif; ?>
    <?php endfor; ?>
    </tbody>
  </table>

    <div class="modal fade" id="dayModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <div>
            <h4 class="modal-title" id="dayTitle"></h4>
            <div class="fs-6 text-muted" id="daySub"></div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" id="dayBody"></div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    const DAY   = <?= json_encode($byDay, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const COURT = <?= json_encode($court['court_name'], JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const TODAY = <?= json_encode($today) ?>;
    const HOURS = <?= json_encode(substr($court['opening_time'], 0, 5) . ' - ' . substr($court['closing_time'], 0, 5)) ?>;
    const KINDS = {
      approved: ['Reserved', 'success'],
      pending:  ['Pending approval', 'warning text-dark'],
      blocked:  ['Unavailable', 'secondary']
    };
    const modal = new bootstrap.Modal(document.getElementById('dayModal'));

    document.querySelectorAll('.day-cell').forEach(td => td.addEventListener('click', () => {
      const date  = td.dataset.date;
      const items = DAY[date] || [];

      document.getElementById('dayTitle').textContent =
        new Date(date + 'T00:00:00').toLocaleDateString('en-US',
          { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
      document.getElementById('daySub').textContent = COURT + ' · Open ' + HOURS;

    const body = document.getElementById('dayBody');
    body.replaceChildren();
    body.className = 'modal-body';

    const isPast = date < TODAY;

    if (isPast) {
        const note = document.createElement('div');
        note.className = 'alert alert-secondary fs-5';
        note.textContent = 'This day has already passed.';
        body.append(note);
    }

    if (!items.length) {
        const msg = document.createElement('div');
        msg.className = 'fs-5';
        msg.textContent = isPast
        ? 'There were no reservations or pending requests. The whole day was free.'
        : 'No reservations or blocks. The whole day is free.';
        body.append(msg);
    } else {
        const list = document.createElement('ul');
        list.className = 'list-group';
        items.forEach(s => {
        const [text, color] = KINDS[s.type];
        const li = document.createElement('li');
        li.className = 'list-group-item d-flex justify-content-between align-items-center gap-3 py-3';

        const left = document.createElement('div');
        const time = document.createElement('strong');
        time.className = 'fs-5';
        time.textContent = s.start.slice(0, 5) + ' - ' + s.end.slice(0, 5);
        const label = document.createElement('div');
        label.className = 'fs-6 text-muted';
        label.textContent = s.label;          // textContent keeps names/purposes safe
        left.append(time, label);

        const badge = document.createElement('span');
        badge.className = 'badge fs-6 bg-' + color;
        badge.textContent = text;

        li.append(left, badge);
        list.append(li);
        });
        body.append(list);
      }
      modal.show();
    }));
  </script>

  <?php if ($_SESSION['role'] === 'customer'): ?>
    <a class="btn btn-success" href="reserve.php">Reserve a Court</a>
  <?php endif; ?>

  <?php endif; ?>
</div>
</body>
</html>