<?php
include 'connect.php';
$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $court_id = (int)$_POST['court_id'];
  $date     = $_POST['reservation_date'];
  $start    = $_POST['start_time'];
  $end      = $_POST['end_time'];
  $purpose  = trim($_POST['purpose']);

  // 1. Basic validation
  if ($end <= $start) {
    $error = "End time must be after start time.";
  } elseif ($date < date('Y-m-d')) {
    $error = "You cannot reserve a date in the past.";
  } else {
    // 2. Court must be available and the slot inside operating hours
    $stmt = $conn->prepare("SELECT opening_time, closing_time, status FROM courts WHERE court_id = ?");
    $stmt->bind_param("i", $court_id);
    $stmt->execute();
    $court = $stmt->get_result()->fetch_assoc();

    if (!$court || $court['status'] !== 'available') {
      $error = "That court is not available.";
    } elseif ($start < $court['opening_time'] || $end > $court['closing_time']) {
      $error = "Outside operating hours (" . substr($court['opening_time'],0,5) . " - " . substr($court['closing_time'],0,5) . ").";
    } else {
      // 3. Double-booking check: overlap = new_start < old_end AND new_end > old_start
      $stmt = $conn->prepare(
        "SELECT COUNT(*) AS n FROM reservations
         WHERE court_id = ? AND reservation_date = ?
           AND status IN ('pending','approved')
           AND ? < end_time AND ? > start_time");
      $stmt->bind_param("isss", $court_id, $date, $start, $end);
      $stmt->execute();
      $n = $stmt->get_result()->fetch_assoc()['n'];

      if ($n > 0) {
        $error = "That time slot overlaps with an existing reservation.";
      } else {
        // 4. Save the reservation (status = pending until an admin approves)
        $stmt = $conn->prepare(
          "INSERT INTO reservations (user_id, court_id, reservation_date, start_time, end_time, purpose)
           VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iissss", $_SESSION['user_id'], $court_id, $date, $start, $end, $purpose);
        $stmt->execute();
        header("Location: index.php");
        exit;
      }
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Reserve a Court - Playbook</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container"><a class="navbar-brand" href="index.php">Playbook</a></div>
</nav>

<div class="container" style="max-width:520px">
  <h4>Reserve a Court</h4>
  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" class="bg-white p-3 border rounded">
    <label class="form-label">Court</label>
    <select name="court_id" class="form-select mb-3" required>
      <?php
        $courts = $conn->query("SELECT court_id, court_name FROM courts WHERE status = 'available'");
        while ($c = $courts->fetch_assoc()) {
          echo "<option value='{$c['court_id']}'>" . htmlspecialchars($c['court_name']) . "</option>";
        }
      ?>
    </select>

    <label class="form-label">Date</label>
    <input type="date" name="reservation_date" class="form-control mb-3" min="<?= date('Y-m-d') ?>" required>

    <div class="row mb-3">
      <div class="col"><label class="form-label">Start</label>
        <input type="time" name="start_time" class="form-control" required></div>
      <div class="col"><label class="form-label">End</label>
        <input type="time" name="end_time" class="form-control" required></div>
    </div>

    <label class="form-label">Purpose</label>
    <input type="text" name="purpose" class="form-control mb-3" maxlength="255">

    <button class="btn btn-success w-100">Submit Request</button>
  </form>
</div>
</body>
</html>
