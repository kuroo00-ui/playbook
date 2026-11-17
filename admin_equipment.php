<?php
include 'auth.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  if ($_POST['action'] === 'add') {
    $name = trim($_POST['equipment_name']);
    $qty  = (int)$_POST['total_quantity'];
    $fee  = (float)$_POST['rental_fee'];
    $stmt = $conn->prepare(
      "INSERT INTO equipment (equipment_name, total_quantity, available_qty, rental_fee) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("siid", $name, $qty, $qty, $fee);   // s=text, i=whole number, d=decimal
    $stmt->execute();

  } elseif ($_POST['action'] === 'update') {
    $id    = (int)$_POST['equipment_id'];
    $total = (int)$_POST['total_quantity'];
    $fee   = (float)$_POST['rental_fee'];
    $cond  = $_POST['equipment_condition'];

    $stmt = $conn->prepare("SELECT total_quantity, available_qty FROM equipment WHERE equipment_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $cur = $stmt->get_result()->fetch_assoc();
    $away = $cur ? $cur['total_quantity'] - $cur['available_qty'] : 0;

    if ($cur && $total < $away) {
      $_SESSION['flash'] = ['danger', "Total can't go below $away: that many are checked out, under repair or missing."];
    } elseif ($cur && in_array($cond, ['good', 'fair', 'damaged'])) {
      // available_qty is adjusted by the change in total. MySQL reads the SET
      // list left to right, so available_qty must come BEFORE total_quantity.
      $stmt = $conn->prepare(
        "UPDATE equipment
         SET available_qty = available_qty + (? - total_quantity),
             total_quantity = ?, rental_fee = ?, equipment_condition = ?
         WHERE equipment_id = ?");
      $stmt->bind_param("iidsi", $total, $total, $fee, $cond, $id);
      $stmt->execute();
    }
  }
  header("Location: admin_equipment.php");
  exit;
}

$items = $conn->query("SELECT * FROM equipment ORDER BY equipment_name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Equipment - Playbook</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container">
    <a class="navbar-brand" href="index.php">Playbook</a>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-light" href="admin_approvals.php">Approvals</a>
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
  <h4>Add Equipment</h4>
  <form method="POST" class="row g-2 bg-white p-3 border rounded mb-4">
    <input type="hidden" name="action" value="add">
    <div class="col-md-5"><input type="text" name="equipment_name" class="form-control" placeholder="Name" required></div>
    <div class="col-md-3"><input type="number" name="total_quantity" class="form-control" placeholder="Quantity" min="0" required></div>
    <div class="col-md-2"><input type="number" step="0.01" name="rental_fee" class="form-control" placeholder="Fee" min="0" required></div>
    <div class="col-md-2"><button class="btn btn-success w-100">Add</button></div>
  </form>

  <h4>Equipment Inventory</h4>
  <table class="table table-bordered bg-white align-middle">
    <thead><tr><th>Name</th><th>Available</th><th>Total</th><th>Fee</th><th>Condition</th><th></th></tr></thead>
    <tbody>
    <?php while ($e = $items->fetch_assoc()): ?>
      <tr>
        <form method="POST">
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="equipment_id" value="<?= $e['equipment_id'] ?>">
          <td><?= htmlspecialchars($e['equipment_name']) ?></td>
          <td><?= $e['available_qty'] ?></td>
          <td><input type="number" name="total_quantity" value="<?= $e['total_quantity'] ?>" min="0" class="form-control form-control-sm"></td>
          <td><input type="number" step="0.01" name="rental_fee" value="<?= $e['rental_fee'] ?>" min="0" class="form-control form-control-sm"></td>
          <td>
            <select name="equipment_condition" class="form-select form-select-sm">
              <?php foreach (['good', 'fair', 'damaged'] as $c): ?>
                <option value="<?= $c ?>" <?= $e['equipment_condition'] === $c ? 'selected' : '' ?>><?= $c ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td><button class="btn btn-primary btn-sm">Save</button></td>
        </form>
      </tr>
    <?php endwhile; ?>
    </tbody>
  </table>
</div>
</body>
</html>