<?php
include 'auth.php';
require_role('admin');          


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $id       = (int)$_POST['reservation_id'];
  $decision = $_POST['decision'];            

  if (in_array($decision, ['approved', 'rejected'])) {
    
    $stmt = $conn->prepare("UPDATE reservations SET status = ? WHERE reservation_id = ? AND status = 'pending'");
    $stmt->bind_param("si", $decision, $id);
    $stmt->execute();

    
    if ($stmt->affected_rows > 0) {
      $stmt = $conn->prepare("INSERT INTO approvals (reservation_id, approver_id, decision) VALUES (?, ?, ?)");
      $stmt->bind_param("iis", $id, $_SESSION['user_id'], $decision);
      $stmt->execute();

      // A rejected booking owes nothing, so void its pending payment
      if ($decision === 'rejected') {
        $stmt = $conn->prepare("UPDATE payments SET status = 'cancelled' WHERE reservation_id = ? AND status = 'pending'");
        $stmt->bind_param("i", $id);
        $stmt->execute();
      }
      $stmt = $conn->prepare(
        "SELECT r.user_id, r.reservation_date, r.start_time, c.court_name
         FROM reservations r JOIN courts c ON c.court_id = r.court_id
         WHERE r.reservation_id = ?");
      $stmt->bind_param("i", $id);
      $stmt->execute();
      $info = $stmt->get_result()->fetch_assoc();
      notify($conn, $info['user_id'],
        "Your reservation for {$info['court_name']} on {$info['reservation_date']} at "
        . substr($info['start_time'], 0, 5) . " was $decision.");
    }
  }
  header("Location: admin_approvals.php");   
  exit;
}


$pending = $conn->query(
  "SELECT r.*, c.court_name, u.first_name, u.last_name
   FROM reservations r
   JOIN courts c ON c.court_id = r.court_id
   JOIN users u  ON u.user_id  = r.user_id
   WHERE r.status = 'pending'
   ORDER BY r.reservation_date, r.start_time");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Approvals - Playbook</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container">
    <a class="navbar-brand" href="index.php">Playbook</a>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-light" href="admin_equipment.php">Equipment</a>
      <a class="btn btn-outline-light" href="maintenance.php">Maintenance</a>
      <a class="btn btn-outline-light" href="admin_payments.php">Payments</a>
      <a class="btn btn-outline-light" href="logout.php">Log Out</a>
    </div>
  </div>
</nav>

<div class="container">
  <h4>Pending Reservations</h4>
  <table class="table table-bordered bg-white">
    <thead>
      <tr><th>Customer</th><th>Court</th><th>Date</th><th>Time</th><th>Purpose</th><th>Action</th></tr>
    </thead>
    <tbody>
    <?php while ($r = $pending->fetch_assoc()): ?>
      <tr>
        <td><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></td>
        <td><?= htmlspecialchars($r['court_name']) ?></td>
        <td><?= $r['reservation_date'] ?></td>
        <td><?= substr($r['start_time'], 0, 5) ?> - <?= substr($r['end_time'], 0, 5) ?></td>
        <td><?= htmlspecialchars($r['purpose']) ?></td>
        <td>
          <form method="POST" class="d-flex gap-2">
            <input type="hidden" name="reservation_id" value="<?= $r['reservation_id'] ?>">
            <button name="decision" value="approved" class="btn btn-success btn-sm">Approve</button>
            <button name="decision" value="rejected" class="btn btn-danger btn-sm">Reject</button>
          </form>
        </td>
      </tr>
    <?php endwhile; ?>
    </tbody>
  </table>
</div>
</body>
</html>