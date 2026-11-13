<?php include 'auth.php'; require_login(); ?>
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
    <?php
      $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM notifications WHERE user_id = ? AND notification_status = 'unread'");
      $stmt->bind_param("i", $_SESSION['user_id']);
      $stmt->execute();
      $unread = $stmt->get_result()->fetch_assoc()['n'];
    ?>
    <div class="d-flex gap-2">
      <a class="btn btn-success" href="reserve.php">Reserve a Court</a>
      <a class="btn btn-outline-light" href="notifications.php">
        Notifications
        <?php if ($unread > 0): ?><span class="badge bg-danger"><?= $unread ?></span><?php endif; ?>
      </a>
      <a class="btn btn-outline-light" href="logout.php">Log Out</a>
    </div>
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
           . "<td>{$c['status']}</td></tr>";
      }
    ?>
    </tbody>
  </table>

      <h4 class="mt-4">My Reservations</h4>
  <table class="table table-bordered bg-white">
    <thead>
      <tr><th>Court</th><th>Date</th><th>Time</th><th>Purpose</th><th>Equipment</th><th>Payment</th><th>Status</th><th>Action</th></tr>
    </thead>
    <tbody>
    <?php
      $stmt = $conn->prepare(
        "SELECT r.*, c.court_name, p.amount, p.status AS payment_status,
           (SELECT GROUP_CONCAT(CONCAT(e.equipment_name, ' x', b.quantity) SEPARATOR ', ')
            FROM equipment_borrowings b
            JOIN equipment e ON e.equipment_id = b.equipment_id
            WHERE b.reservation_id = r.reservation_id) AS equipment_list
         FROM reservations r
         JOIN courts c ON c.court_id = r.court_id
         LEFT JOIN payments p ON p.reservation_id = r.reservation_id
         WHERE r.user_id = ? ORDER BY r.reservation_date DESC, r.start_time DESC");
      $stmt->bind_param("i", $_SESSION['user_id']);
      $stmt->execute();
      $res = $stmt->get_result();
      while ($r = $res->fetch_assoc()):
    ?>
      <tr>
        <td><?= htmlspecialchars($r['court_name']) ?></td>
        <td><?= $r['reservation_date'] ?></td>
        <td><?= substr($r['start_time'], 0, 5) ?> - <?= substr($r['end_time'], 0, 5) ?></td>
        <td><?= htmlspecialchars($r['purpose']) ?></td>
        <td><?= htmlspecialchars($r['equipment_list'] ?? '-') ?></td>
        <td>
          <?php if ($r['amount'] !== null): ?>
            ₱<?= number_format($r['amount'], 2) ?>
            <span class="badge bg-<?= $r['payment_status'] === 'paid' ? 'success' : ($r['payment_status'] === 'refunded' ? 'secondary' : 'warning text-dark') ?>">
              <?= $r['payment_status'] ?>
            </span>
          <?php else: ?>-<?php endif; ?>
        </td>
        <td><?= $r['status'] ?></td>
        <td>
          <?php if (in_array($r['status'], ['pending', 'approved'])): ?>
            <form method="POST" action="cancel.php" onsubmit="return confirm('Cancel this reservation?')">
              <input type="hidden" name="reservation_id" value="<?= $r['reservation_id'] ?>">
              <button class="btn btn-danger btn-sm">Cancel</button>
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
