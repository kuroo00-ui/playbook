<?php
include 'auth.php';
require_role('staff', 'admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $pid = (int)$_POST['payment_id'];

  if ($_POST['action'] === 'paid' && in_array($_POST['method'], ['Cash', 'GCash', 'Bank Transfer'])) {
    // Only pending payments on approved/completed reservations can be marked paid
    $stmt = $conn->prepare(
      "UPDATE payments p JOIN reservations r ON r.reservation_id = p.reservation_id
       SET p.status = 'paid', p.method = ?, p.payment_date = NOW()
       WHERE p.payment_id = ? AND p.status = 'pending' AND r.status IN ('approved','completed')");
    $stmt->bind_param("si", $_POST['method'], $pid);
    $stmt->execute();

  } elseif ($_POST['action'] === 'refund') {
    // Only paid payments can be refunded
    $stmt = $conn->prepare("UPDATE payments SET status = 'refunded' WHERE payment_id = ? AND status = 'paid'");
    $stmt->bind_param("i", $pid);
    $stmt->execute();
  }
  header("Location: admin_payments.php");
  exit;
}

$list = $conn->query(
  "SELECT p.*, r.reservation_date, r.status AS res_status, c.court_name, u.first_name, u.last_name
   FROM payments p
   JOIN reservations r ON r.reservation_id = p.reservation_id
   JOIN courts c       ON c.court_id = r.court_id
   JOIN users u        ON u.user_id = r.user_id
   ORDER BY (p.status = 'pending') DESC, p.payment_id DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Payments - Playbook</title>
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
        <a class="btn btn-outline-light" href="maintenance.php">Maintenance</a>
      <?php endif; ?>
      <a class="btn btn-outline-light" href="logout.php">Log Out</a>
    </div>
  </div>
</nav>

<div class="container">
  <h4>Payments</h4>
  <table class="table table-bordered bg-white align-middle">
    <thead>
      <tr><th>Customer</th><th>Court</th><th>Date</th><th>Reservation</th><th>Amount</th><th>Payment</th><th>Method</th><th>Action</th></tr>
    </thead>
    <tbody>
    <?php while ($p = $list->fetch_assoc()): ?>
      <tr>
        <td><?= htmlspecialchars($p['first_name'] . ' ' . $p['last_name']) ?></td>
        <td><?= htmlspecialchars($p['court_name']) ?></td>
        <td><?= $p['reservation_date'] ?></td>
        <td><?= $p['res_status'] ?></td>
        <td>₱<?= number_format($p['amount'], 2) ?></td>
        <td><?= $p['status'] ?></td>
        <td><?= htmlspecialchars($p['method'] ?? '-') ?></td>
        <td>
          <?php if ($p['status'] === 'pending' && in_array($p['res_status'], ['approved', 'completed'])): ?>
            <form method="POST" class="d-flex gap-1">
              <input type="hidden" name="action" value="paid">
              <input type="hidden" name="payment_id" value="<?= $p['payment_id'] ?>">
              <select name="method" class="form-select form-select-sm">
                <option>Cash</option><option>GCash</option><option>Bank Transfer</option>
              </select>
              <button class="btn btn-success btn-sm">Mark Paid</button>
            </form>
          <?php elseif ($p['status'] === 'paid' && in_array($p['res_status'], ['cancelled', 'rejected'])): ?>
            <form method="POST" onsubmit="return confirm('Refund this payment?')">
              <input type="hidden" name="action" value="refund">
              <input type="hidden" name="payment_id" value="<?= $p['payment_id'] ?>">
              <button class="btn btn-warning btn-sm">Refund</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endwhile; ?>
    </tbody>
  </table>
</div>
</body>
</html>