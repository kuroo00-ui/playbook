<?php
include 'auth.php';
require_role('staff', 'admin');
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
  <p class="text-muted">No reservations loaded yet.</p>
</div>

</body>
</html>