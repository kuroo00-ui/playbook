<?php
/*
 * admin_courts.php  (admin only)
 * Add a court, edit a court, and change its status
 * (available / maintenance / closed).
 *
 * Uses the courts table from schema.sql:
 *   court_id, court_name, capacity, opening_time, closing_time, rental_rate, status
 */
include 'auth.php';
require_role('admin');

$error   = "";
$message = $_SESSION['flash'] ?? "";
unset($_SESSION['flash']);

$statuses = ['available', 'maintenance', 'closed'];

// Values shown in the form (empty = "add" mode)
$form = [
  'court_id' => 0, 'court_name' => '', 'capacity' => '',
  'opening_time' => '08:00', 'closing_time' => '21:00',
  'rental_rate' => '', 'status' => 'available',
];

// ---- Handle form submissions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'add' || $action === 'edit') {
    // 1. Read the fields
    $form['court_id']     = (int)($_POST['court_id'] ?? 0);
    $form['court_name']   = trim($_POST['court_name'] ?? '');
    $form['capacity']     = (int)($_POST['capacity'] ?? 0);
    $form['opening_time'] = $_POST['opening_time'] ?? '';
    $form['closing_time'] = $_POST['closing_time'] ?? '';
    $form['rental_rate']  = $_POST['rental_rate'] ?? '';
    $form['status']       = $_POST['status'] ?? 'available';

    // 2. Validate
    if ($form['court_name'] === '') {
      $error = "Court name is required.";
    } elseif ($form['capacity'] < 1) {
      $error = "Capacity must be at least 1.";
    } elseif ($form['opening_time'] === '' || $form['closing_time'] === ''
              || $form['closing_time'] <= $form['opening_time']) {
      $error = "Closing time must be later than opening time.";
    } elseif (!is_numeric($form['rental_rate']) || $form['rental_rate'] < 0) {
      $error = "Rental rate must be 0 or more.";
    } elseif (!in_array($form['status'], $statuses)) {
      $error = "Invalid status.";
    } else {
      $rate = (float)$form['rental_rate'];

      if ($action === 'add') {
        $stmt = $conn->prepare(
          "INSERT INTO courts (court_name, capacity, opening_time, closing_time, rental_rate, status)
           VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sissds", $form['court_name'], $form['capacity'],
                          $form['opening_time'], $form['closing_time'], $rate, $form['status']);
        $stmt->execute();
        $_SESSION['flash'] = "Court added.";
      } else {
        $stmt = $conn->prepare(
          "UPDATE courts SET court_name = ?, capacity = ?, opening_time = ?, closing_time = ?,
                             rental_rate = ?, status = ?
           WHERE court_id = ?");
        $stmt->bind_param("sissdsi", $form['court_name'], $form['capacity'],
                          $form['opening_time'], $form['closing_time'], $rate,
                          $form['status'], $form['court_id']);
        $stmt->execute();
        $_SESSION['flash'] = "Court updated.";
      }
      header("Location: admin_courts.php");
      exit;
    }

  } elseif ($action === 'status') {
    // Quick status change from the table (e.g. close for maintenance)
    $id     = (int)($_POST['court_id'] ?? 0);
    $status = $_POST['status'] ?? '';

    if (in_array($status, $statuses)) {
      $stmt = $conn->prepare("UPDATE courts SET status = ? WHERE court_id = ?");
      $stmt->bind_param("si", $status, $id);
      $stmt->execute();
      $msg = "Court status changed to " . $status . ".";

      // Warn the admin if there are upcoming bookings on a court that is no longer available
      if ($status !== 'available') {
        $stmt = $conn->prepare(
          "SELECT COUNT(*) AS n FROM reservations
           WHERE court_id = ? AND status IN ('pending','approved') AND reservation_date >= CURDATE()");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $n = $stmt->get_result()->fetch_assoc()['n'];
        if ($n > 0) {
          $msg .= " Warning: " . $n . " upcoming reservation(s) on this court still need attention.";
        }
      }
      $_SESSION['flash'] = $msg;
    }
    header("Location: admin_courts.php");
    exit;

  } elseif ($action === 'delete') {
    // Delete only if nothing else in the database points to this court
    $id = (int)($_POST['court_id'] ?? 0);
    $stmt = $conn->prepare(
      "SELECT (SELECT COUNT(*) FROM reservations       WHERE court_id = ?)
            + (SELECT COUNT(*) FROM schedules          WHERE court_id = ?)
            + (SELECT COUNT(*) FROM maintenance_reports WHERE court_id = ?) AS n");
    $stmt->bind_param("iii", $id, $id, $id);
    $stmt->execute();
    $n = $stmt->get_result()->fetch_assoc()['n'];

    if ($n > 0) {
      $_SESSION['flash'] = "Can't delete this court because it has reservations or maintenance history. Close it instead.";
    } else {
      $stmt = $conn->prepare("DELETE FROM courts WHERE court_id = ?");
      $stmt->bind_param("i", $id);
      $stmt->execute();
      $_SESSION['flash'] = "Court deleted.";
    }
    header("Location: admin_courts.php");
    exit;
  }
}

// ---- Edit mode: load the court into the form (?edit=ID) ----
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edit'])) {
  $stmt = $conn->prepare("SELECT * FROM courts WHERE court_id = ?");
  $stmt->bind_param("i", $_GET['edit']);
  $stmt->execute();
  $c = $stmt->get_result()->fetch_assoc();
  if ($c) {
    $form = [
      'court_id' => $c['court_id'], 'court_name' => $c['court_name'],
      'capacity' => $c['capacity'],
      'opening_time' => substr($c['opening_time'], 0, 5),
      'closing_time' => substr($c['closing_time'], 0, 5),
      'rental_rate' => $c['rental_rate'], 'status' => $c['status'],
    ];
  }
}
$editing = $form['court_id'] > 0;

$courts = $conn->query("SELECT * FROM courts ORDER BY court_name");
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
    <a class="navbar-brand" href="index.php">Playbook</a>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-light" href="admin_approvals.php">Approvals</a>
      <a class="btn btn-outline-light" href="logout.php">Log Out</a>
    </div>
  </div>
</nav>

<div class="container">
  <h4>Manage Courts</h4>

  <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <!-- Courts list -->
  <table class="table table-bordered bg-white align-middle">
    <thead>
      <tr><th>Court</th><th>Capacity</th><th>Hours</th><th>Rate</th><th>Status</th><th>Actions</th></tr>
    </thead>
    <tbody>
    <?php while ($c = $courts->fetch_assoc()): ?>
      <tr>
        <td><?= htmlspecialchars($c['court_name']) ?></td>
        <td><?= (int)$c['capacity'] ?></td>
        <td><?= substr($c['opening_time'], 0, 5) ?> - <?= substr($c['closing_time'], 0, 5) ?></td>
        <td>₱<?= number_format($c['rental_rate'], 2) ?></td>
        <td><?= htmlspecialchars($c['status']) ?></td>
        <td class="d-flex gap-2">
          <a class="btn btn-outline-primary btn-sm" href="admin_courts.php?edit=<?= $c['court_id'] ?>">Edit</a>
          <form method="POST" class="d-flex gap-2"
                onsubmit="return confirm('Change the status of this court?')">
            <input type="hidden" name="action" value="status">
            <input type="hidden" name="court_id" value="<?= $c['court_id'] ?>">
            <?php if ($c['status'] === 'available'): ?>
              <button name="status" value="maintenance" class="btn btn-warning btn-sm">Close for maintenance</button>
            <?php else: ?>
              <button name="status" value="available" class="btn btn-success btn-sm">Reopen</button>
            <?php endif; ?>
          </form>
          <form method="POST" onsubmit="return confirm('Delete this court permanently?')">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="court_id" value="<?= $c['court_id'] ?>">
            <button class="btn btn-danger btn-sm">Delete</button>
          </form>
        </td>
      </tr>
    <?php endwhile; ?>
    </tbody>
  </table>

  <!-- Add / Edit form -->
  <h5 class="mt-4"><?= $editing ? 'Edit Court' : 'Add a Court' ?></h5>
  <form method="POST" class="bg-white p-3 border rounded" style="max-width:520px">
    <input type="hidden" name="action" value="<?= $editing ? 'edit' : 'add' ?>">
    <input type="hidden" name="court_id" value="<?= (int)$form['court_id'] ?>">

    <label class="form-label">Court Name</label>
    <input type="text" name="court_name" class="form-control mb-3" required
           value="<?= htmlspecialchars($form['court_name']) ?>">

    <label class="form-label">Capacity</label>
    <input type="number" name="capacity" min="1" class="form-control mb-3" required
           value="<?= htmlspecialchars((string)$form['capacity']) ?>">

    <div class="row">
      <div class="col">
        <label class="form-label">Opening Time</label>
        <input type="time" name="opening_time" class="form-control mb-3" required
               value="<?= htmlspecialchars($form['opening_time']) ?>">
      </div>
      <div class="col">
        <label class="form-label">Closing Time</label>
        <input type="time" name="closing_time" class="form-control mb-3" required
               value="<?= htmlspecialchars($form['closing_time']) ?>">
      </div>
    </div>

    <label class="form-label">Rental Rate (₱ per hour)</label>
    <input type="number" name="rental_rate" min="0" step="0.01" class="form-control mb-3" required
           value="<?= htmlspecialchars((string)$form['rental_rate']) ?>">

    <label class="form-label">Status</label>
    <select name="status" class="form-select mb-3">
      <?php foreach ($statuses as $s): ?>
        <option value="<?= $s ?>" <?= $form['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
      <?php endforeach; ?>
    </select>

    <button type="submit" class="btn btn-success"><?= $editing ? 'Save Changes' : 'Add Court' ?></button>
    <?php if ($editing): ?>
      <a href="admin_courts.php" class="btn btn-outline-secondary">Cancel</a>
    <?php endif; ?>
  </form>
</div>
</body>
</html>