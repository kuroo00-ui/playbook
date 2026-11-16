<?php
include 'auth.php';
require_role('staff', 'admin');
if (($_SESSION['view'] ?? $_SESSION['role']) !== 'staff') { header("Location: index.php"); exit; }

if ($_SESSION['role'] === 'admin') {
  $allCourts = $conn->query("SELECT court_id, court_name FROM courts ORDER BY court_name")->fetch_all(MYSQLI_ASSOC);
  if (isset($_GET['court_id'])) { $_SESSION['view_court_id'] = (int)$_GET['court_id']; }
  $pick = $_SESSION['view_court_id'] ?? ($allCourts[0]['court_id'] ?? 0);
  $stmt = $conn->prepare("SELECT court_id, court_name FROM courts WHERE court_id = ?");
  $stmt->bind_param("i", $pick);
} else {
  $stmt = $conn->prepare(
    "SELECT c.court_id, c.court_name FROM users u
     LEFT JOIN courts c ON c.court_id = u.staff_court_id WHERE u.user_id = ?");
  $stmt->bind_param("i", $_SESSION['user_id']);
}
$stmt->execute();
$court = $stmt->get_result()->fetch_assoc();
if ($_SESSION['role'] === 'admin' && $court) { $_SESSION['view_court_id'] = $court['court_id']; }

$reservations = []; $borrow = []; $equipment = [];
$reports = []; $settledCount = 0;

// Report filters (from the URL)
$f_item   = $_GET['r_item']   ?? 'all';
$f_type   = $_GET['r_type']   ?? 'all';
$f_status = $_GET['r_status'] ?? 'all';
$f_from   = $_GET['r_from']   ?? '';
$f_to     = $_GET['r_to']     ?? '';
$f_tfrom  = $_GET['r_tfrom']  ?? '';
$f_tto    = $_GET['r_tto']    ?? '';

if ($court && $court['court_id']) {
  $stmt = $conn->prepare(
    "SELECT r.*, u.first_name, u.last_name FROM reservations r
     JOIN users u ON u.user_id = r.user_id
     WHERE r.court_id = ? AND r.reservation_date = CURDATE() AND r.status = 'approved'
     ORDER BY r.start_time");
  $stmt->bind_param("i", $court['court_id']);
  $stmt->execute();
  $reservations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

  foreach ($reservations as $r) {
    $stmt = $conn->prepare(
      "SELECT b.*, e.equipment_name FROM equipment_borrowings b
       JOIN equipment e ON e.equipment_id = b.equipment_id WHERE b.reservation_id = ?");
    $stmt->bind_param("i", $r['reservation_id']);
    $stmt->execute();
    $borrow[$r['reservation_id']] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  }
  $equipment = $conn->query("SELECT equipment_id, equipment_name FROM equipment ORDER BY equipment_name")
                    ->fetch_all(MYSQLI_ASSOC);

  // ---- Problem reports, with filters ----
  $sql = "SELECT m.*, e.equipment_name, u.first_name, u.last_name
          FROM maintenance_reports m
          LEFT JOIN equipment e ON e.equipment_id = m.equipment_id
          JOIN users u ON u.user_id = m.reported_by
          WHERE m.hidden_by_staff = 0 AND (m.court_id = ? OR m.equipment_id IS NOT NULL)";
  $types = "i"; $args = [$court['court_id']];

  if ($f_item === 'court') {
    $sql .= " AND m.equipment_id IS NULL";
  } elseif (ctype_digit($f_item)) {
    $sql .= " AND m.equipment_id = ?"; $types .= "i"; $args[] = (int)$f_item;
  }
  if (in_array($f_type, ['Damaged', 'Missing', 'Defective'])) {
    $sql .= " AND m.issue_type = ?"; $types .= "s"; $args[] = $f_type;
  }
  if (in_array($f_status, ['reported', 'in_repair', 'fixed'])) {
    $sql .= " AND m.repair_status = ?"; $types .= "s"; $args[] = $f_status;
  }
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_from)) {
    $sql .= " AND DATE(m.date_reported) >= ?"; $types .= "s"; $args[] = $f_from;
  }
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_to)) {
    $sql .= " AND DATE(m.date_reported) <= ?"; $types .= "s"; $args[] = $f_to;
  }
  if (preg_match('/^\d{2}:\d{2}$/', $f_tfrom)) {
    $sql .= " AND TIME(m.date_reported) >= ?"; $types .= "s"; $args[] = $f_tfrom . ':00';
  }
  if (preg_match('/^\d{2}:\d{2}$/', $f_tto)) {
    $sql .= " AND TIME(m.date_reported) <= ?"; $types .= "s"; $args[] = $f_tto . ':59';
  }
  $sql .= " ORDER BY m.date_reported DESC";

  $stmt = $conn->prepare($sql);
  $stmt->bind_param($types, ...$args);
  $stmt->execute();
  $reports = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

  // How many settled reports could be cleared (ignores the filters)
  $stmt = $conn->prepare(
    "SELECT COUNT(*) AS n FROM maintenance_reports
     WHERE repair_status = 'fixed' AND hidden_by_staff = 0 AND (court_id = ? OR equipment_id IS NOT NULL)");
  $stmt->bind_param("i", $court['court_id']);
  $stmt->execute();
  $settledCount = (int)$stmt->get_result()->fetch_assoc()['n'];
}
$statusLabel = ['reported' => 'Open', 'in_repair' => 'In repair', 'fixed' => 'Settled'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Staff - Playbook</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container">
    <a class="navbar-brand" href="staff_dashboard.php">Playbook (Staff)</a>
    <div class="d-flex gap-2">

    <a class="btn btn-outline-light" href="calendar.php">Schedule</a>

      <?php include 'view_switcher.php'; ?>
      <a class="btn btn-outline-light" href="logout.php">Log Out</a>
    </div>
  </div>
</nav>

<div class="container">
<?php if ($_SESSION['role'] === 'admin'): ?>
  <form method="GET" class="mb-3" style="max-width:420px">
    <select name="court_id" class="form-select" onchange="this.form.submit()">
      <?php foreach ($allCourts as $c): ?>
        <option value="<?= $c['court_id'] ?>" <?= ($court && $court['court_id'] == $c['court_id']) ? 'selected' : '' ?>>
          <?= htmlspecialchars($c['court_name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </form>
<?php endif; ?>

<?php if (!$court || !$court['court_id']): ?>
  <div class="alert alert-warning">No court is assigned to your account yet.</div>
<?php else: ?>
  <h4>Today's Reservations: <?= htmlspecialchars($court['court_name']) ?> (<?= date('M j, Y') ?>)</h4>

  <?php if (!$reservations): ?>
    <div class="alert alert-secondary">No approved reservations today.</div>
  <?php endif; ?>

  <?php foreach ($reservations as $r): ?>
    <div class="card mb-3">
      <div class="card-header">
        <strong><?= substr($r['start_time'], 0, 5) ?> - <?= substr($r['end_time'], 0, 5) ?></strong>
        &middot; <?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?>
        <?php if ($r['purpose']): ?>&middot; <?= htmlspecialchars($r['purpose']) ?><?php endif; ?>
      </div>
      <ul class="list-group list-group-flush">
      <?php if (empty($borrow[$r['reservation_id']])): ?>
        <li class="list-group-item text-muted">No equipment requested.</li>
      <?php endif; ?>
      <?php foreach ($borrow[$r['reservation_id']] ?? [] as $b): ?>
        <li class="list-group-item d-flex align-items-center gap-3">
          <span class="flex-grow-1"><?= htmlspecialchars($b['equipment_name']) ?> x<?= $b['quantity'] ?></span>

          <?php if (!$b['checkout_time']): ?>
            <form method="POST" action="staff_actions.php" class="m-0">
              <input type="hidden" name="action" value="checkout">
              <input type="hidden" name="borrowing_id" value="<?= $b['borrowing_id'] ?>">
              <button class="btn btn-primary btn-sm">Check Out</button>
            </form>

          <?php elseif (!$b['return_time']): ?>
            <form method="POST" action="staff_actions.php" class="d-flex gap-2 m-0">
              <input type="hidden" name="action" value="return">
              <input type="hidden" name="borrowing_id" value="<?= $b['borrowing_id'] ?>">
              <input type="number" name="qty_returned" class="form-control form-control-sm" style="width:80px"
                     min="0" max="<?= $b['quantity'] ?>" value="<?= $b['quantity'] ?>">
              <select name="return_condition" class="form-select form-select-sm" style="width:120px">
                <option value="good">Good</option><option value="fair">Fair</option>
                <option value="damaged">Damaged</option><option value="missing">Missing</option>
              </select>
              <button class="btn btn-success btn-sm">Return</button>
            </form>

          <?php else: ?>
            <span class="text-muted">Returned <?= $b['qty_returned'] ?>/<?= $b['quantity'] ?> (<?= ucfirst($b['return_condition']) ?>)</span>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
      </ul>
    </div>
  <?php endforeach; ?>

  <h4 class="mt-4">Report a Problem</h4>
  <form method="POST" action="staff_actions.php" class="row g-2 bg-white p-3 border rounded mb-4">
    <input type="hidden" name="action" value="report">
    <div class="col-md-4">
      <select name="equipment_id" class="form-select" required>
        <option value="">Item</option>
        <?php foreach ($equipment as $e): ?>
          <option value="<?= $e['equipment_id'] ?>"><?= htmlspecialchars($e['equipment_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <select name="issue_type" class="form-select" required>
        <option value="">What happened</option>
        <option value="Damaged">Damaged</option>
        <option value="Missing">Missing</option>
        <option value="Defective">Defective</option>
      </select>
    </div>
    <div class="col-md-4"><input type="text" name="details" class="form-control" placeholder="Details" maxlength="200" required></div>
    <div class="col-md-2"><button class="btn btn-danger w-100">Report</button></div>
  </form>

  <button type="button" id="reportsToggle" class="btn btn-outline-primary mb-3">Show Problem Reports</button>

  <div id="reportsPanel" style="display:none">

  <!-- ===== Problem Reports ===== -->
  <div id="reports" class="d-flex justify-content-between align-items-center mb-2">
    <h4 class="m-0">Problem Reports</h4>
    <form method="POST" action="staff_actions.php"
          onsubmit="return confirm('Clear <?= $settledCount ?> settled report(s) from this list?\n\nOnly settled reports are cleared. Open and in-repair reports stay.');">
      <input type="hidden" name="action" value="clear_reports">
      <button class="btn btn-outline-danger btn-sm" <?= $settledCount ? '' : 'disabled' ?>>
        Clear settled reports (<?= $settledCount ?>)
      </button>
    </form>
  </div>

  <form method="GET" action="staff_dashboard.php#reports" class="row g-2 bg-white p-3 border rounded mb-3">
    <div class="col-md-3">
      <label class="form-label small mb-0">Item</label>
      <select name="r_item" class="form-select form-select-sm">
        <option value="all">All items</option>
        <option value="court" <?= $f_item === 'court' ? 'selected' : '' ?>>Court: <?= htmlspecialchars($court['court_name']) ?></option>
        <?php foreach ($equipment as $e): ?>
          <option value="<?= $e['equipment_id'] ?>" <?= $f_item == $e['equipment_id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($e['equipment_name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label small mb-0">What happened</label>
      <select name="r_type" class="form-select form-select-sm">
        <option value="all">All</option>
        <?php foreach (['Damaged', 'Missing', 'Defective'] as $t): ?>
          <option value="<?= $t ?>" <?= $f_type === $t ? 'selected' : '' ?>><?= $t ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label small mb-0">Status</label>
      <select name="r_status" class="form-select form-select-sm">
        <option value="all">All</option>
        <?php foreach ($statusLabel as $k => $label): ?>
          <option value="<?= $k ?>" <?= $f_status === $k ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label small mb-0">Date from</label>
      <input type="date" name="r_from" value="<?= htmlspecialchars($f_from) ?>" class="form-control form-control-sm">
    </div>
    <div class="col-md-2">
      <label class="form-label small mb-0">Date to</label>
      <input type="date" name="r_to" value="<?= htmlspecialchars($f_to) ?>" class="form-control form-control-sm">
    </div>
    <div class="col-md-1"></div>
    <div class="col-md-2">
      <label class="form-label small mb-0">Time from</label>
      <input type="time" name="r_tfrom" value="<?= htmlspecialchars($f_tfrom) ?>" class="form-control form-control-sm">
    </div>
    <div class="col-md-2">
      <label class="form-label small mb-0">Time to</label>
      <input type="time" name="r_tto" value="<?= htmlspecialchars($f_tto) ?>" class="form-control form-control-sm">
    </div>
    <div class="col-md-3 d-flex align-items-end gap-2">
      <button class="btn btn-primary btn-sm">Filter</button>
      <a class="btn btn-outline-secondary btn-sm" href="staff_dashboard.php#reports">Reset</a>
    </div>
  </form>

  <table class="table table-bordered bg-white align-middle mb-5">
    <thead>
      <tr><th>Date &amp; time</th><th>Item</th><th>What happened</th><th>Details</th><th>Reported by</th><th>Status</th><th>Action</th></tr>
    </thead>
    <tbody>
    <?php if (!$reports): ?>
      <tr><td colspan="7" class="text-center text-muted">No reports match.</td></tr>
    <?php endif; ?>
    <?php foreach ($reports as $m): ?>
      <tr>
        <td><?= date('M j, Y g:i A', strtotime($m['date_reported'])) ?></td>
        <td><?= $m['equipment_name'] ? htmlspecialchars($m['equipment_name']) : 'Court: ' . htmlspecialchars($court['court_name']) ?></td>
        <td><?= htmlspecialchars($m['issue_type'] ?? '—') ?></td>
        <td><?= htmlspecialchars($m['issue']) ?></td>
        <td><?= htmlspecialchars($m['first_name'] . ' ' . $m['last_name']) ?></td>
        <td><?= $statusLabel[$m['repair_status']] ?></td>
        <td>
          <?php if ($m['repair_status'] !== 'fixed'): ?>
            <form method="POST" action="staff_actions.php" class="d-flex gap-1 m-0">
              <input type="hidden" name="action" value="set_status">
              <input type="hidden" name="report_id" value="<?= $m['report_id'] ?>">
              <?php if ($m['repair_status'] === 'reported'): ?>
                <button name="status" value="in_repair" class="btn btn-outline-secondary btn-sm">In repair</button>
              <?php endif; ?>
              <button name="status" value="fixed" class="btn btn-success btn-sm">Mark settled</button>
            </form>
          <?php else: ?>
            <span class="text-muted">Settled</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>
<script>
const panel = document.getElementById('reportsPanel');
const btn   = document.getElementById('reportsToggle');

function setOpen(open) {
  if (!panel) return;
  panel.style.display = open ? '' : 'none';
  btn.textContent = open ? 'Hide Problem Reports' : 'Show Problem Reports';
}

if (btn) {
  btn.addEventListener('click', () => setOpen(panel.style.display === 'none'));
  // Opens automatically after filtering, resetting, marking settled or clearing
  if (location.hash === '#reports') {
    setOpen(true);
    panel.scrollIntoView();
  }
}
</script>
</body>
</html>