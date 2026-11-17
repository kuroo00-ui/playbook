<?php
include 'auth.php';
require_role('staff', 'admin');
$today = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'checkout') {
  $bid = (int)$_POST['borrowing_id'];
  $now = date('Y-m-d H:i:s');

  $stmt = $conn->prepare(
    "SELECT b.equipment_id, b.quantity, b.checkout_time,
            r.status, r.reservation_date, e.available_qty
     FROM equipment_borrowings b
     JOIN reservations r ON r.reservation_id = b.reservation_id
     JOIN equipment e    ON e.equipment_id   = b.equipment_id
     WHERE b.borrowing_id = ?");
  $stmt->bind_param("i", $bid);
  $stmt->execute();
  $b = $stmt->get_result()->fetch_assoc();

    if (!$b) {
    $_SESSION['flash'] = ['danger', 'Borrowing not found.'];
  } elseif ($b['checkout_time'] !== null) {
    $_SESSION['flash'] = ['warning', 'This item is already checked out.'];
  } elseif ($b['status'] !== 'approved') {
    $_SESSION['flash'] = ['danger', 'This reservation is no longer approved.'];
  } elseif ($b['reservation_date'] !== $today) {
    $_SESSION['flash'] = ['danger', 'Checkout is only allowed on the reservation date.'];
  } elseif ($b['available_qty'] < $b['quantity']) {
    $_SESSION['flash'] = ['danger', 'Not enough stock: ' . $b['available_qty'] . ' available, ' . $b['quantity'] . ' requested.'];
  } else {
        $conn->begin_transaction();

    $stmt = $conn->prepare(
      "UPDATE equipment SET available_qty = available_qty - ?
       WHERE equipment_id = ? AND available_qty >= ?");
    $stmt->bind_param("iii", $b['quantity'], $b['equipment_id'], $b['quantity']);
    $stmt->execute();
    $stock_ok = ($stmt->affected_rows === 1);

    $stmt = $conn->prepare(
      "UPDATE equipment_borrowings SET checkout_time = ?
       WHERE borrowing_id = ? AND checkout_time IS NULL");
    $stmt->bind_param("si", $now, $bid);
    $stmt->execute();
    $borrow_ok = ($stmt->affected_rows === 1);

    if ($stock_ok && $borrow_ok) {
      $conn->commit();
      $_SESSION['flash'] = ['success', 'Item checked out.'];
    } else {
      $conn->rollback();
      $_SESSION['flash'] = ['danger', 'Checkout failed. Nothing was changed.'];
    }
  }

  header("Location: staff_equipment.php");
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'return') {
  $bid  = (int)$_POST['borrowing_id'];
  $qret = (int)$_POST['qty_returned'];
  $cond = $_POST['return_condition'] ?? '';
  $now  = date('Y-m-d H:i:s');

  if ($cond === 'missing') {
    $qret = 0;
  }

  $stmt = $conn->prepare(
    "SELECT equipment_id, quantity, checkout_time, return_time
     FROM equipment_borrowings WHERE borrowing_id = ?");
  $stmt->bind_param("i", $bid);
  $stmt->execute();
  $b = $stmt->get_result()->fetch_assoc();

    if (!$b) {
    $_SESSION['flash'] = ['danger', 'Borrowing not found.'];
  } elseif ($b['checkout_time'] === null) {
    $_SESSION['flash'] = ['danger', 'This item was never checked out.'];
  } elseif ($b['return_time'] !== null) {
    $_SESSION['flash'] = ['warning', 'This item is already returned.'];
  } elseif (!in_array($cond, ['good', 'fair', 'damaged', 'missing'], true)) {
    $_SESSION['flash'] = ['danger', 'Choose a condition.'];
  } elseif ($cond !== 'missing' && ($qret < 1 || $qret > $b['quantity'])) {
    $_SESSION['flash'] = ['danger', 'Returned quantity must be between 1 and ' . $b['quantity'] . '.'];
  } else {

      $conn->begin_transaction();

    $stmt = $conn->prepare(
      "UPDATE equipment_borrowings
       SET qty_returned = ?, return_condition = ?, return_time = ?
       WHERE borrowing_id = ? AND return_time IS NULL");
    $stmt->bind_param("issi", $qret, $cond, $now, $bid);
    $stmt->execute();
    $ok = ($stmt->affected_rows === 1);

        if ($ok && in_array($cond, ['good', 'fair'], true)) {
      $stmt = $conn->prepare(
        "UPDATE equipment SET available_qty = available_qty + ? WHERE equipment_id = ?");
      $stmt->bind_param("ii", $qret, $b['equipment_id']);
      $stmt->execute();
      $ok = ($stmt->affected_rows === 1);
    }

        if ($ok && $cond === 'damaged') {
      $issue = 'Returned damaged: ' . $qret . ' of ' . $b['quantity'] . ' (borrowing #' . $bid . ')';
      $stmt = $conn->prepare(
        "INSERT INTO maintenance_reports (equipment_id, reported_by, issue) VALUES (?, ?, ?)");
      $stmt->bind_param("iis", $b['equipment_id'], $_SESSION['user_id'], $issue);
      $stmt->execute();
      $ok = ($stmt->affected_rows === 1);

      $stmt = $conn->prepare(
        "UPDATE equipment SET equipment_condition = 'damaged' WHERE equipment_id = ?");
      $stmt->bind_param("i", $b['equipment_id']);
      $stmt->execute();
    }

        if ($ok) {
      $conn->commit();
      $_SESSION['flash'] = ['success', 'Return recorded.'];
    } else {
      $conn->rollback();
      $_SESSION['flash'] = ['danger', 'Return failed. Nothing was changed.'];
    }
  }

  header("Location: staff_equipment.php");
  exit;
}

$stmt = $conn->prepare(
    "SELECT b.borrowing_id, b.quantity, b.checkout_time, b.return_time,
          b.qty_returned, b.return_condition,
          e.equipment_name, e.available_qty,
          r.reservation_date, r.start_time, r.end_time,
          c.court_name, u.first_name, u.last_name
   FROM equipment_borrowings b
   JOIN reservations r ON r.reservation_id = b.reservation_id
   JOIN equipment e    ON e.equipment_id   = b.equipment_id
   JOIN courts c       ON c.court_id       = r.court_id
   JOIN users u        ON u.user_id        = r.user_id
   WHERE (r.status = 'approved' AND r.reservation_date = ?)
      OR (b.checkout_time IS NOT NULL AND b.return_time IS NULL)
   ORDER BY r.reservation_date, r.start_time");
$stmt->bind_param("s", $today);
$stmt->execute();
$rows = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Equipment Checkout - Playbook</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container">
    <a class="navbar-brand" href="index.php">Playbook</a>
    <div class="d-flex gap-2">
      <?php if ($_SESSION['role'] === 'admin'): ?>
        <a class="btn btn-outline-light" href="admin_approvals.php">Approvals</a>
        <a class="btn btn-outline-light" href="admin_equipment.php">Equipment</a>
        <a class="btn btn-outline-light" href="admin_payments.php">Payments</a>
        <a class="btn btn-outline-light" href="maintenance.php">Maintenance</a>
      <?php endif; ?>
      <a class="btn btn-outline-light" href="logout.php">Log Out</a>
    </div>
  </div>
</nav>

<div class="container">
  <h4>Equipment Checkout and Return</h4>
      <?php if (isset($_SESSION['flash'])): ?>
    <?php [$ftype, $ftext] = $_SESSION['flash']; unset($_SESSION['flash']); ?>
    <div class="alert alert-<?= $ftype ?>"><?= htmlspecialchars($ftext) ?></div>
  <?php endif; ?>
    <?php if ($rows->num_rows === 0): ?>
    <p class="text-muted">No equipment to check out or return today.</p>
  <?php else: ?>
        <table class="table table-bordered bg-white align-middle">
            <thead>
        <tr><th>Customer</th><th>Court</th><th>Date</th><th>Time</th><th>Item</th><th>Qty</th><th>Status</th><th>Action</th></tr>
      </thead>
      <tbody>
              <?php while ($b = $rows->fetch_assoc()): ?>
        <tr>
          <td><?= htmlspecialchars($b['first_name'] . ' ' . $b['last_name']) ?></td>
          <td><?= htmlspecialchars($b['court_name']) ?></td>
          <td><?= $b['reservation_date'] ?></td>
          <td><?= substr($b['start_time'], 0, 5) ?> - <?= substr($b['end_time'], 0, 5) ?></td>
          <td><?= htmlspecialchars($b['equipment_name']) ?></td>
          <td><?= $b['quantity'] ?></td>
          <td>
            <?php if ($b['return_time']): ?>
              <span class="badge bg-secondary">Returned</span>
            <?php elseif ($b['checkout_time']): ?>
              <span class="badge bg-warning text-dark">Checked out</span>
            <?php else: ?>
              <span class="badge bg-info text-dark">Waiting</span>
            <?php endif; ?>
          </td>
                    <td>
            <?php if (!$b['checkout_time']): ?>
              <form method="POST" onsubmit="return confirm('Check out this item?')">
                <input type="hidden" name="action" value="checkout">
                <input type="hidden" name="borrowing_id" value="<?= $b['borrowing_id'] ?>">
                <button class="btn btn-sm btn-success">Check Out</button>
              </form>
            <?php elseif (!$b['return_time']): ?>
              <form method="POST" class="d-flex gap-1" onsubmit="return confirm('Record this return?')">
                <input type="hidden" name="action" value="return">
                <input type="hidden" name="borrowing_id" value="<?= $b['borrowing_id'] ?>">
                <input type="number" name="qty_returned" value="<?= $b['quantity'] ?>"
                       min="0" max="<?= $b['quantity'] ?>" class="form-control form-control-sm" style="width:70px">
                <select name="return_condition" class="form-select form-select-sm" required>
                  <option value="">Condition</option>
                  <option value="good">good</option>
                  <option value="fair">fair</option>
                  <option value="damaged">damaged</option>
                  <option value="missing">missing</option>
                </select>
                <button class="btn btn-sm btn-primary">Return</button>
              </form>
            <?php else: ?>
              <?= $b['qty_returned'] ?> of <?= $b['quantity'] ?> back, <?= htmlspecialchars($b['return_condition']) ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endwhile; ?>
            </tbody>
    </table>
  <?php endif; ?>
</div>

</body>
</html>