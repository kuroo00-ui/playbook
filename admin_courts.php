<?php
include 'auth.php';
require_role('admin');
if (($_SESSION['view'] ?? 'admin') !== 'admin') { header("Location: index.php"); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'add_court') {
    $stmt = $conn->prepare("INSERT INTO courts (court_name, capacity, opening_time, closing_time, rental_rate) VALUES (?, ?, ?, ?, ?)");
    $name = trim($_POST['court_name']); $cap = (int)$_POST['capacity'];
    $open = $_POST['opening_time'] . ':00'; $close = $_POST['closing_time'] . ':00'; $rate = (float)$_POST['rental_rate'];
    $stmt->bind_param("sissd", $name, $cap, $open, $close, $rate);
    $stmt->execute();

  } elseif ($action === 'update_court') {
    $status = $_POST['status'];
    $open = $_POST['opening_time']; $close = $_POST['closing_time'];
    if (in_array($status, ['available', 'maintenance', 'closed']) && $close > $open) {
      $stmt = $conn->prepare("UPDATE courts SET opening_time = ?, closing_time = ?, rental_rate = ?, status = ? WHERE court_id = ?");
      $rate = (float)$_POST['rental_rate']; $id = (int)$_POST['court_id'];
      $stmt->bind_param("ssdsi", $open, $close, $rate, $status, $id);
      $stmt->execute();
    }

  } elseif ($action === 'block') {
    $cid = (int)$_POST['court_id']; $date = $_POST['schedule_date'];
    $start = $_POST['start_time']; $end = $_POST['end_time'];
    if ($end > $start && $date >= date('Y-m-d')) {
      $stmt = $conn->prepare(
        "SELECT COUNT(*) AS n FROM reservations WHERE court_id = ? AND reservation_date = ?
         AND status IN ('pending','approved') AND ? < end_time AND ? > start_time");
      $stmt->bind_param("isss", $cid, $date, $start, $end);
      $stmt->execute();
      if ($stmt->get_result()->fetch_assoc()['n'] == 0) {
        $stmt = $conn->prepare(
          "INSERT INTO schedules (court_id, schedule_date, start_time, end_time, schedule_status) VALUES (?, ?, ?, ?, 'blocked')");
        $stmt->bind_param("isss", $cid, $date, $start, $end);
        $stmt->execute();
      }
    }

  } elseif ($action === 'unblock') {
    $sid = (int)$_POST['schedule_id'];
    $stmt = $conn->prepare("UPDATE schedules SET schedule_status = 'released' WHERE schedule_id = ? AND reservation_id IS NULL");
    $stmt->bind_param("i", $sid);
    $stmt->execute();
  }
  header("Location: admin_courts.php");
  exit;
}

$courts = $conn->query("SELECT * FROM courts ORDER BY court_name")->fetch_all(MYSQLI_ASSOC);
$blocks = $conn->query(
  "SELECT s.*, c.court_name FROM schedules s JOIN courts c ON c.court_id = s.court_id
   WHERE s.schedule_status = 'blocked' AND s.schedule_date >= CURDATE()
   ORDER BY s.schedule_date, s.start_time");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Courts - Playbook</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container">
    <a class="navbar-brand" href="index.php">Playbook</a>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-light" href="admin_approvals.php">Approvals</a>
      <a class="btn btn-outline-light" href="admin_equipment.php">Equipment</a>
      <a class="btn btn-outline-light" href="admin_staff.php">Staff</a>

      <a class="btn btn-outline-light" href="calendar.php">Schedule</a>

      <?php include 'view_switcher.php'; ?>
      <a class="btn btn-outline-light" href="logout.php">Log Out</a>
    </div>
  </div>
</nav>

<div class="container">
  <h4>Add Court</h4>
  <form method="POST" class="row g-2 bg-white p-3 border rounded mb-4">
    <input type="hidden" name="action" value="add_court">
    <div class="col-md-3"><input type="text" name="court_name" class="form-control" placeholder="Name" required></div>
    <div class="col-md-2"><input type="number" name="capacity" class="form-control" placeholder="Capacity" min="1" required></div>
    <div class="col-md-2"><input type="time" name="opening_time" class="form-control" value="08:00" required></div>
    <div class="col-md-2"><input type="time" name="closing_time" class="form-control" value="21:00" required></div>
    <div class="col-md-2"><input type="number" step="0.01" name="rental_rate" class="form-control" placeholder="Rate" min="0" required></div>
    <div class="col-md-1"><button class="btn btn-success w-100">Add</button></div>
  </form>

  <h4>Courts and Operating Hours</h4>
  <table class="table table-bordered bg-white align-middle">
    <thead><tr><th>Court</th><th>Opens</th><th>Closes</th><th>Rate</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($courts as $c): ?>
      <tr>
        <form method="POST">
          <input type="hidden" name="action" value="update_court">
          <input type="hidden" name="court_id" value="<?= $c['court_id'] ?>">
          <td><?= htmlspecialchars($c['court_name']) ?></td>
          <td><input type="time" name="opening_time" value="<?= substr($c['opening_time'], 0, 5) ?>" class="form-control form-control-sm"></td>
          <td><input type="time" name="closing_time" value="<?= substr($c['closing_time'], 0, 5) ?>" class="form-control form-control-sm"></td>
          <td><input type="number" step="0.01" name="rental_rate" value="<?= $c['rental_rate'] ?>" class="form-control form-control-sm"></td>
          <td>
            <select name="status" class="form-select form-select-sm">
              <?php foreach (['available', 'maintenance', 'closed'] as $s): ?>
                <option value="<?= $s ?>" <?= $c['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td><button class="btn btn-primary btn-sm">Save</button></td>
        </form>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <h4 class="mt-4">Block a Time Slot</h4>
  <form method="POST" class="row g-2 bg-white p-3 border rounded mb-3">
    <input type="hidden" name="action" value="block">
    <div class="col-md-3">
      <select name="court_id" class="form-select" required>
        <?php foreach ($courts as $c): ?>
          <option value="<?= $c['court_id'] ?>"><?= htmlspecialchars($c['court_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3"><input type="date" name="schedule_date" class="form-control" min="<?= date('Y-m-d') ?>" required></div>
    <div class="col-md-2"><input type="time" name="start_time" class="form-control" required></div>
    <div class="col-md-2"><input type="time" name="end_time" class="form-control" required></div>
    <div class="col-md-2"><button class="btn btn-danger w-100">Block</button></div>
  </form>

  <table class="table table-bordered bg-white">
    <thead><tr><th>Court</th><th>Date</th><th>Time</th><th></th></tr></thead>
    <tbody>
    <?php while ($s = $blocks->fetch_assoc()): ?>
      <tr>
        <td><?= htmlspecialchars($s['court_name']) ?></td>
        <td><?= $s['schedule_date'] ?></td>
        <td><?= substr($s['start_time'], 0, 5) ?> - <?= substr($s['end_time'], 0, 5) ?></td>
        <td>
          <form method="POST" class="m-0">
            <input type="hidden" name="action" value="unblock">
            <input type="hidden" name="schedule_id" value="<?= $s['schedule_id'] ?>">
            <button class="btn btn-outline-secondary btn-sm">Unblock</button>
          </form>
        </td>
      </tr>
    <?php endwhile; ?>
    </tbody>
  </table>
</div>
</body>
</html>