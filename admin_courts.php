<?php
include 'auth.php';
require_role('admin');

// Whole hours only, because reserve.php builds its dropdowns from whole hours
function hour_ok($t) { return preg_match('/^([01]\d|2[0-3]):00:00$/', $t) === 1; }
function hour_label($h) { return (($h + 11) % 12 + 1) . ':00 ' . ($h % 24 >= 12 ? 'PM' : 'AM'); }

$statuses = ['available', 'maintenance', 'closed'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action   = $_POST['action'] ?? '';
  $name     = trim($_POST['court_name'] ?? '');
  $capacity = (int)($_POST['capacity'] ?? 0);
  $open     = $_POST['opening_time'] ?? '';
  $close    = $_POST['closing_time'] ?? '';
  $rate     = (float)($_POST['rental_rate'] ?? -1);

  if ($name === '' || strlen($name) > 100 || $capacity < 1 || $rate < 0) {
    $_SESSION['flash'] = ['danger', 'Fill in a name, a capacity of at least 1 and a rate of 0 or more.'];
  } elseif (!hour_ok($open) || !hour_ok($close)) {
    $_SESSION['flash'] = ['danger', 'Opening and closing times must be whole hours.'];
  } elseif ($close <= $open) {
    $_SESSION['flash'] = ['danger', 'Closing time must be after opening time.'];
  } elseif ($action === 'add') {
    $stmt = $conn->prepare(
      "INSERT INTO courts (court_name, capacity, opening_time, closing_time, rental_rate)
       VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sissd", $name, $capacity, $open, $close, $rate);
    $stmt->execute();
    $_SESSION['flash'] = ['success', "Added $name."];
  } elseif ($action === 'update') {
    $court_id = (int)$_POST['court_id'];
    $status   = $_POST['status'] ?? '';
    if (!in_array($status, $statuses, true)) {
      $_SESSION['flash'] = ['danger', 'Invalid status.'];
    } else {
      $stmt = $conn->prepare(
        "UPDATE courts SET court_name = ?, capacity = ?, opening_time = ?, closing_time = ?,
                           rental_rate = ?, status = ?
         WHERE court_id = ?");
      $stmt->bind_param("sissdsi", $name, $capacity, $open, $close, $rate, $status, $court_id);
      $stmt->execute();
      $_SESSION['flash'] = ['success', "Saved $name."];
    }
  }
  header("Location: admin_courts.php");
  exit;
}

$courts = $conn->query("SELECT * FROM courts ORDER BY court_name")->fetch_all(MYSQLI_ASSOC);

// <option> list of whole hours; $sel is the value to preselect
function hour_options($sel) {
  $out = '';
  for ($h = 0; $h <= 23; $h++) {
    $v = sprintf('%02d:00:00', $h);
    $out .= '<option value="' . $v . '"' . ($v === $sel ? ' selected' : '') . '>' . hour_label($h) . '</option>';
  }
  return $out;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Manage Courts - Playbook</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container">
<<<<<<< HEAD
    <a class="navbar-brand" href="index.php">Playbook</a>
=======
    <a class="navbar-brand" href="<?= home_url() ?>">Playbook</a>
>>>>>>> 7e3adea (added the court and tested)
    <div class="d-flex gap-2">
      <a class="btn btn-outline-light" href="admin_approvals.php">Approvals</a>
      <a class="btn btn-outline-light" href="admin_equipment.php">Equipment</a>
      <a class="btn btn-outline-light" href="maintenance.php">Maintenance</a>
      <a class="btn btn-outline-light" href="admin_payments.php">Payments</a>
      <a class="btn btn-outline-light" href="logout.php">Log Out</a>
    </div>
  </div>
</nav>

<div class="container">
  <?php if (isset($_SESSION['flash'])): ?>
    <?php [$ftype, $ftext] = $_SESSION['flash']; unset($_SESSION['flash']); ?>
    <div class="alert alert-<?= $ftype ?>"><?= htmlspecialchars($ftext) ?></div>
  <?php endif; ?>

  <h4>Manage Courts</h4>

  <form method="POST" class="bg-white p-3 border rounded mb-4">
    <input type="hidden" name="action" value="add">
    <h6>Add a court</h6>
    <div class="row g-2 align-items-end">
      <div class="col-md-3"><label class="form-label">Name</label>
        <input type="text" name="court_name" class="form-control" maxlength="100" required></div>
      <div class="col-md-1"><label class="form-label">Capacity</label>
        <input type="number" name="capacity" class="form-control" min="1" required></div>
      <div class="col-md-2"><label class="form-label">Opens</label>
        <select name="opening_time" class="form-select"><?= hour_options('08:00:00') ?></select></div>
      <div class="col-md-2"><label class="form-label">Closes</label>
        <select name="closing_time" class="form-select"><?= hour_options('21:00:00') ?></select></div>
      <div class="col-md-2"><label class="form-label">Rate / hour (₱)</label>
        <input type="number" name="rental_rate" class="form-control" min="0" step="0.01" required></div>
      <div class="col-md-2"><button class="btn btn-success w-100">Add Court</button></div>
    </div>
  </form>

  <div class="bg-white border rounded p-3">
    <div class="table-responsive">
      <table class="table align-middle mb-0">
        <thead>
          <tr><th>Name</th><th>Capacity</th><th>Opens</th><th>Closes</th><th>Rate / hour (₱)</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($courts as $c): ?>
          <?php $f = 'f' . (int)$c['court_id']; ?>
          <tr>
            <td>
              <form method="POST" id="<?= $f ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="court_id" value="<?= (int)$c['court_id'] ?>">
              </form>
              <input form="<?= $f ?>" type="text" name="court_name" class="form-control" maxlength="100"
                     value="<?= htmlspecialchars($c['court_name']) ?>" required>
            </td>
            <td><input form="<?= $f ?>" type="number" name="capacity" class="form-control" style="width:90px" min="1"
                       value="<?= (int)$c['capacity'] ?>" required></td>
            <td><select form="<?= $f ?>" name="opening_time" class="form-select"><?= hour_options($c['opening_time']) ?></select></td>
            <td><select form="<?= $f ?>" name="closing_time" class="form-select"><?= hour_options($c['closing_time']) ?></select></td>
            <td><input form="<?= $f ?>" type="number" name="rental_rate" class="form-control" style="width:120px" min="0" step="0.01"
                       value="<?= htmlspecialchars($c['rental_rate']) ?>" required></td>
            <td><select form="<?= $f ?>" name="status" class="form-select">
              <?php foreach ($statuses as $s): ?>
                <option value="<?= $s ?>" <?= $c['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
              <?php endforeach; ?>
            </select></td>
            <td><button form="<?= $f ?>" class="btn btn-primary btn-sm">Save</button></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="form-text mt-2">Courts are never deleted because reservations refer to them. Set a court to Closed instead.
      A new rate only applies to future bookings.</div>
  </div>
</div>
</body>
<<<<<<< HEAD
</html>
=======
</html>
>>>>>>> 7e3adea (added the court and tested)
