<?php include 'connect.php'; ?>
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
    <a class="btn btn-success" href="reserve.php">Reserve a Court</a>
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
    <thead><tr><th>Court</th><th>Date</th><th>Time</th><th>Purpose</th><th>Status</th></tr></thead>
    <tbody>
    <?php
      $stmt = $conn->prepare(
        "SELECT r.*, c.court_name FROM reservations r
         JOIN courts c ON c.court_id = r.court_id
         WHERE r.user_id = ? ORDER BY r.reservation_date DESC, r.start_time DESC");
      $stmt->bind_param("i", $_SESSION['user_id']);
      $stmt->execute();
      $res = $stmt->get_result();
      while ($r = $res->fetch_assoc()) {
        echo "<tr><td>" . htmlspecialchars($r['court_name']) . "</td>"
           . "<td>{$r['reservation_date']}</td>"
           . "<td>" . substr($r['start_time'],0,5) . " - " . substr($r['end_time'],0,5) . "</td>"
           . "<td>" . htmlspecialchars($r['purpose']) . "</td>"
           . "<td>{$r['status']}</td></tr>";
      }
    ?>
    </tbody>
  </table>
</div>
</body>
</html>
