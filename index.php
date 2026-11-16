<?php
include 'auth.php';
require_login();
$view = $_SESSION['view'] ?? $_SESSION['role'];
if ($view === 'staff') { header("Location: staff_dashboard.php"); exit; }
if ($view === 'admin') { header("Location: admin_approvals.php"); exit; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Playbook</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container">
    <a class="navbar-brand" href="index.php">Playbook</a>
    <?php if ($_SESSION['role'] === 'customer'): ?>
      <a class="btn btn-success" href="reserve.php">Reserve a Court</a>
    <?php endif; ?>
    
    <a class="btn btn-outline-light" href="calendar.php">Schedule</a>

    <?php include 'view_switcher.php'; ?>
    <a class="btn btn-outline-light" href="logout.php">Log Out</a>
  </div>
</nav>

<div class="container">
  <h4>Available Courts</h4>
  <table class="table table-bordered bg-white">
    <thead><tr><th>Court</th><th>Capacity</th><th>Hours</th><th>Rate</th><th>Status</th></tr></thead>
    <tbody>
    <?php
      $courts = $conn->query("SELECT * FROM courts");
      while ($c = $courts->fetch_assoc()) {
        echo "<tr><td>" . htmlspecialchars($c['court_name']) . "</td>"
           . "<td>{$c['capacity']}</td>"
           . "<td>" . substr($c['opening_time'],0,5) . " - " . substr($c['closing_time'],0,5) . "</td>"
           . "<td>₱" . number_format($c['rental_rate'], 2) . "</td>"
           . "<td>" . ucfirst($c['status']) . "</td></tr>";
      }
    ?>
    </tbody>
  </table>

  <?php
  $stmt = $conn->prepare(
    "SELECT r.*, c.court_name,
       (SELECT GROUP_CONCAT(CONCAT(e.equipment_name, ' x', b.quantity) SEPARATOR ', ')
          FROM equipment_borrowings b
          JOIN equipment e ON e.equipment_id = b.equipment_id
         WHERE b.reservation_id = r.reservation_id) AS equipment_list,
       (r.status <> 'pending' AND (r.reservation_date < CURDATE()
          OR (r.reservation_date = CURDATE() AND r.end_time < CURTIME()))) AS is_past
     FROM reservations r
     JOIN courts c ON c.court_id = r.court_id
     WHERE r.user_id = ? AND r.hidden_by_customer = 0
     ORDER BY r.reservation_date DESC, r.start_time DESC");
  $stmt->bind_param("i", $_SESSION['user_id']);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

  $counts = []; $clearable = 0;
  foreach ($rows as $r) {
    $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;
    if ($r['is_past']) { $clearable++; }
  }
  $statuses = ['pending', 'approved', 'rejected', 'cancelled', 'completed'];
  $showAction = false;
  foreach ($rows as $r) {
    if (in_array($r['status'], ['pending', 'approved'])) { $showAction = true; break; }
  }
?>
  <div class="d-flex justify-content-between align-items-center mt-4 mb-2">
    <h4 class="m-0">My Reservations</h4>

    <?php if ($_SESSION['role'] === 'customer'): ?>
      <form method="POST" action="clear_past.php"
            onsubmit="return confirm('Clear <?= $clearable ?> past reservation(s) from your list?\n\nThey will be removed from your My Reservations view. Pending requests are not affected.');">
        <button class="btn btn-outline-danger btn-sm" <?= $clearable ? '' : 'disabled' ?>>
          Clear past reservations (<?= $clearable ?>)
        </button>
      </form>
    <?php endif; ?>
  </div>

  <div class="btn-group flex-wrap mb-3" role="group">
    <button type="button" class="btn btn-outline-secondary active" data-filter="all">
      All Reservations (<?= count($rows) ?>)
    </button>
    <?php foreach ($statuses as $s): ?>
      <button type="button" class="btn btn-outline-secondary" data-filter="<?= $s ?>">
        <?= ucfirst($s) ?> (<?= $counts[$s] ?? 0 ?>)
      </button>
    <?php endforeach; ?>
  </div>

  <table class="table table-bordered bg-white" id="resTable">
    <thead>
      <tr><th>Court</th><th>Date</th><th>Time</th><th>Purpose</th><th>Equipment</th><th>Status</th><?php if ($showAction): ?><th>Action</th><?php endif; ?></tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr data-status="<?= htmlspecialchars($r['status']) ?>">
        <td><?= htmlspecialchars($r['court_name']) ?></td>
        <td><?= $r['reservation_date'] ?></td>
        <td><?= substr($r['start_time'], 0, 5) ?> - <?= substr($r['end_time'], 0, 5) ?></td>
        <td><?= htmlspecialchars($r['purpose']) ?></td>
        <td><?= $r['equipment_list'] ? htmlspecialchars($r['equipment_list']) : '—' ?></td>
        <td><?= ucfirst($r['status']) ?></td>
                <?php if ($showAction): ?>
        <td>
          <?php if (in_array($r['status'], ['pending', 'approved'])): ?>
            <form method="POST" action="cancel.php" onsubmit="return confirm('Cancel this reservation?')">
              <input type="hidden" name="reservation_id" value="<?= $r['reservation_id'] ?>">
              <button class="btn btn-danger btn-sm">Cancel</button>
            </form>
          <?php endif; ?>
        </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
      <tr id="noRows" <?= $rows ? 'style="display:none"' : '' ?>>
                <td colspan="<?= $showAction ? 7 : 6 ?>" class="text-center text-muted">No reservations to show.</td>
      </tr>
    </tbody>
  </table>
</div>
<script>
const filterBtns = document.querySelectorAll('[data-filter]');
const resRows    = document.querySelectorAll('#resTable tbody tr[data-status]');
const noRows     = document.getElementById('noRows');

filterBtns.forEach(btn => btn.addEventListener('click', () => {
  filterBtns.forEach(b => b.classList.remove('active'));
  btn.classList.add('active');

  let shown = 0;
  resRows.forEach(row => {
    const match = btn.dataset.filter === 'all' || row.dataset.status === btn.dataset.filter;
    row.style.display = match ? '' : 'none';
    if (match) shown++;
  });
  noRows.style.display = shown ? 'none' : '';
}));
</script>
</body>
</html>
