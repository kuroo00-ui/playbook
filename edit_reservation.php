<?php
/*
 * edit_reservation.php  (simple version)
 * A customer reschedules their OWN reservation:
 * pick a new date and start time. The length stays the same.
 */
include 'auth.php';
require_login();

$error = "";
$id = (int)($_GET['id'] ?? $_POST['reservation_id'] ?? 0);

// 1. Get the reservation (must be yours, pending/approved, and not in the past)
$stmt = $conn->prepare(
  "SELECT r.*, c.court_name, c.opening_time, c.closing_time, c.status AS court_status
   FROM reservations r
   JOIN courts c ON c.court_id = r.court_id
   WHERE r.reservation_id = ? AND r.user_id = ?
     AND r.status IN ('pending','approved') AND r.reservation_date >= CURDATE()");
$stmt->bind_param("ii", $id, $_SESSION['user_id']);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();

if (!$res) {
  die("This reservation was not found or can't be changed. <a href='index.php'>Back</a>");
}

// Length of the booking in hours (this stays the same)
$hours = (int)ceil((strtotime($res['end_time']) - strtotime($res['start_time'])) / 3600);

// 2. The form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $date   = $_POST['reservation_date'];
  $start  = $_POST['start_time'];
  $end_ts = strtotime($start) + $hours * 3600;
  $end    = date('H:i:s', $end_ts);

  if ($date < date('Y-m-d')) {
    $error = "You cannot move a reservation to a past date.";
  } elseif ($res['court_status'] !== 'available') {
    $error = "This court is not available right now.";
  } elseif ($start < $res['opening_time'] || $end_ts > strtotime($res['closing_time'])) {
    $error = "Outside operating hours (" . substr($res['opening_time'], 0, 5) . " - " . substr($res['closing_time'], 0, 5) . ").";
  } else {
    // 3. Overlap check, ignoring THIS reservation
    $stmt = $conn->prepare(
      "SELECT COUNT(*) AS n FROM reservations
       WHERE court_id = ? AND reservation_date = ?
         AND status IN ('pending','approved')
         AND reservation_id <> ?
         AND ? < end_time AND ? > start_time");
    $stmt->bind_param("isiss", $res['court_id'], $date, $id, $start, $end);
    $stmt->execute();
    $n = $stmt->get_result()->fetch_assoc()['n'];

    if ($n > 0) {
      $error = "That time slot overlaps with an existing reservation.";
    } else {
      // 4. Save the new date and time (an admin must approve it again)
      $stmt = $conn->prepare(
        "UPDATE reservations
         SET reservation_date = ?, start_time = ?, end_time = ?, status = 'pending'
         WHERE reservation_id = ? AND user_id = ?");
      $stmt->bind_param("sssii", $date, $start, $end, $id, $_SESSION['user_id']);
      $stmt->execute();

      $_SESSION['flash_reservation'] = "Reservation rescheduled. It needs to be approved again.";
      header("Location: index.php");
      exit;
    }
  }
}

// Hours the court is open (for the start-time dropdown)
$open_h  = (int)substr($res['opening_time'], 0, 2);
$close_h = (int)substr($res['closing_time'], 0, 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Reschedule - Playbook</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container"><a class="navbar-brand" href="index.php">Playbook</a></div>
</nav>

<div class="container" style="max-width:520px">
  <h4>Reschedule Reservation</h4>
  <p class="text-muted">
    <?= htmlspecialchars($res['court_name']) ?> &middot; now
    <?= $res['reservation_date'] ?>, <?= substr($res['start_time'], 0, 5) ?> - <?= substr($res['end_time'], 0, 5) ?>
    (<?= $hours ?> hour<?= $hours > 1 ? 's' : '' ?>)
  </p>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" class="bg-white p-3 border rounded">
    <input type="hidden" name="reservation_id" value="<?= $id ?>">

    <label class="form-label">New Date</label>
    <input type="date" name="reservation_date" class="form-control mb-3"
           min="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($_POST['reservation_date'] ?? $res['reservation_date']) ?>" required>

    <label class="form-label">New Start Time</label>
    <select name="start_time" class="form-select mb-3" required>
      <?php for ($h = $open_h; $h + $hours <= $close_h; $h++): $v = sprintf('%02d:00:00', $h); ?>
        <option value="<?= $v ?>" <?= ($_POST['start_time'] ?? $res['start_time']) === $v ? 'selected' : '' ?>>
          <?= date('g:i A', strtotime($v)) ?>
        </option>
      <?php endfor; ?>
    </select>

    <p class="small text-muted">The length stays the same. After you save, an admin must approve the new time.</p>

    <button class="btn btn-success w-100 mb-2">Save</button>
    <a href="index.php" class="btn btn-outline-secondary w-100">Back</a>
  </form>
</div>
</body>
</html>
