<?php
include 'auth.php';
require_login();
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
  } elseif (substr($start, 3, 2) !== '00' || substr($end, 3, 2) !== '00') {
    $error = "Bookings must start and end on the hour.";
  } elseif ($date < date('Y-m-d')) {
    $error = "You cannot reserve a date in the past.";
  } else {
    // 2. Court must be available and the slot inside operating hours
    $stmt = $conn->prepare("SELECT opening_time, closing_time, status, rental_rate FROM courts WHERE court_id = ?");
    $stmt->bind_param("i", $court_id);
    $stmt->execute();
    $court = $stmt->get_result()->fetch_assoc();

    if (!$court || $court['status'] !== 'available') {
      $error = "That court is not available.";
    } elseif ($start < $court['opening_time'] || $end > $court['closing_time']) {
      $error = "Outside operating hours (" . substr($court['opening_time'],0,5) . " - " . substr($court['closing_time'],0,5) . ").";
    } else {
      // Collect the equipment the customer asked for (quantity above 0).
      // ksort so rows are always locked in the same order (avoids deadlocks).
      $items = [];
      foreach (($_POST['equip'] ?? []) as $eid => $qty) {
        if ((int)$qty > 0) { $items[(int)$eid] = (int)$qty; }
      }
      ksort($items);

      // Everything from here is one transaction, so two people booking at the
      // same moment cannot both pass the checks.
      $conn->begin_transaction();
      try {
        // Lock this court's row: bookings for the same court now run one at a time
        $stmt = $conn->prepare("SELECT court_id FROM courts WHERE court_id = ? FOR UPDATE");
        $stmt->bind_param("i", $court_id);
        $stmt->execute();
        $stmt->get_result();

        // 3. Double-booking check: overlap = new_start < old_end AND new_end > old_start
        $stmt = $conn->prepare(
          "SELECT COUNT(*) AS n FROM reservations
           WHERE court_id = ? AND reservation_date = ?
             AND status IN ('pending','approved')
             AND ? < end_time AND ? > start_time");
        $stmt->bind_param("isss", $court_id, $date, $start, $end);
        $stmt->execute();
        $n = $stmt->get_result()->fetch_assoc()['n'];

        $equip_total = 0;
        if ($n > 0) {
          $error = "That time slot overlaps with an existing reservation.";
        } else {
          // 4. Equipment: stock minus what overlapping reservations already requested
          foreach ($items as $eid => $qty) {
            $stmt = $conn->prepare(
              "SELECT equipment_name, available_qty, rental_fee
               FROM equipment WHERE equipment_id = ? FOR UPDATE");
            $stmt->bind_param("i", $eid);
            $stmt->execute();
            $eq = $stmt->get_result()->fetch_assoc();
            if (!$eq) { $error = "That equipment does not exist."; break; }

            // Requests on overlapping pending/approved bookings that are NOT checked
            // out yet. Checked-out items are already subtracted from available_qty,
            // so counting them again would double-count.
            $stmt = $conn->prepare(
              "SELECT COALESCE(SUM(b.quantity), 0) AS reserved
               FROM equipment_borrowings b
               JOIN reservations r ON r.reservation_id = b.reservation_id
               WHERE b.equipment_id = ? AND b.checkout_time IS NULL
                 AND r.reservation_date = ? AND r.status IN ('pending','approved')
                 AND ? < r.end_time AND ? > r.start_time");
            $stmt->bind_param("isss", $eid, $date, $start, $end);
            $stmt->execute();
            $reserved = (int)$stmt->get_result()->fetch_assoc()['reserved'];

            $free = (int)$eq['available_qty'] - $reserved;
            if ($qty > $free) {
              $error = "Not enough " . $eq['equipment_name'] . " for that time (" . max(0, $free) . " left).";
              break;
            }
            $equip_total += $qty * $eq['rental_fee'];
          }
        }

        if ($error !== "") {
          $conn->rollback();
        } else {
          // No problems, so save the reservation, its equipment and its payment
          $stmt = $conn->prepare(
            "INSERT INTO reservations (user_id, court_id, reservation_date, start_time, end_time, purpose)
             VALUES (?, ?, ?, ?, ?, ?)");
          $stmt->bind_param("iissss", $_SESSION['user_id'], $court_id, $date, $start, $end, $purpose);
          $stmt->execute();
          $reservation_id = $conn->insert_id;

          foreach ($items as $eid => $qty) {
            $stmt = $conn->prepare(
              "INSERT INTO equipment_borrowings (reservation_id, equipment_id, quantity) VALUES (?, ?, ?)");
            $stmt->bind_param("iii", $reservation_id, $eid, $qty);
            $stmt->execute();
          }

          $hours  = ceil((strtotime($end) - strtotime($start)) / 3600);
          $amount = $hours * $court['rental_rate'] + $equip_total;
          $stmt = $conn->prepare("INSERT INTO payments (reservation_id, amount) VALUES (?, ?)");
          $stmt->bind_param("id", $reservation_id, $amount);
          $stmt->execute();

          $conn->commit();
          header("Location: index.php");
          exit;
        }
      } catch (Throwable $e) {
        $conn->rollback();
        error_log($e->getMessage());
        $error = "Something went wrong saving your reservation. Please try again.";
      }
    }
  }
}

// Courts for the dropdown, with the whole hours each one is open
$court_rows = $conn->query(
  "SELECT court_id, court_name, opening_time, closing_time FROM courts WHERE status = 'available' ORDER BY court_name"
)->fetch_all(MYSQLI_ASSOC);

$court_hours = [];
foreach ($court_rows as $c) {
  $open  = (int)substr($c['opening_time'], 0, 2) + (substr($c['opening_time'], 3, 2) !== '00' ? 1 : 0); // first whole hour
  $close = (int)substr($c['closing_time'], 0, 2);                                                        // last whole hour
  $court_hours[$c['court_id']] = ['open' => $open, 'close' => $close];
}
// Keep what the person picked if the form comes back with an error
$picked = [
  'court' => (int)($_POST['court_id'] ?? 0),
  'start' => $_POST['start_time'] ?? '',
  'end'   => $_POST['end_time'] ?? '',
];
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
    <select name="court_id" id="court" class="form-select mb-3" required>
      <?php foreach ($court_rows as $c): ?>
        <option value="<?= (int)$c['court_id'] ?>"><?= htmlspecialchars($c['court_name']) ?></option>
      <?php endforeach; ?>
    </select>

    <label class="form-label">Date</label>
    <input type="date" name="reservation_date" class="form-control mb-3" min="<?= date('Y-m-d') ?>" required>

    <div class="row mb-3">
      <div class="col">
        <label class="form-label">Start</label>
        <select name="start_time" id="start" class="form-select" required></select>
      </div>
      <div class="col">
        <label class="form-label">End</label>
        <select name="end_time" id="end" class="form-select" required></select>
      </div>
    </div>

    <label class="form-label">Purpose</label>
    <input type="text" name="purpose" class="form-control mb-3" maxlength="255">
    <label class="form-label">Equipment (optional)</label>
    <?php
      $equip = $conn->query("SELECT equipment_id, equipment_name, available_qty, rental_fee FROM equipment");
      while ($e = $equip->fetch_assoc()):
    ?>
      <div class="d-flex align-items-center mb-2">
        <span class="flex-grow-1">
          <?= htmlspecialchars($e['equipment_name']) ?>
          (₱<?= number_format($e['rental_fee'], 2) ?>, <?= $e['available_qty'] ?> in stock)
        </span>
        <input type="number" name="equip[<?= $e['equipment_id'] ?>]"
               class="form-control" style="width:80px" min="0" max="<?= $e['available_qty'] ?>" value="0">
      </div>
    <?php endwhile; ?>
    <div class="form-text mb-3">Equipment is checked against other bookings at your chosen time when you submit.</div>

    <button class="btn btn-success w-100">Submit Request</button>
  </form>
</div>

<script>
// Each court's opening and closing hour come from the database
const HOURS  = <?= json_encode($court_hours) ?>;
const PICKED = <?= json_encode($picked) ?>;

const courtSel = document.getElementById('court');
const startSel = document.getElementById('start');
const endSel   = document.getElementById('end');

const pad = h => String(h).padStart(2, '0') + ':00:00';     // sent as HH:00:00, as before
const label = h => ((h + 11) % 12 + 1) + ':00 ' + (h % 24 >= 12 ? 'PM' : 'AM');

function fill(select, from, to, keep) {
  select.innerHTML = '';
  for (let h = from; h <= to; h++) {
    const o = document.createElement('option');
    o.value = pad(h);
    o.textContent = label(h);
    select.appendChild(o);
  }
  if (keep && [...select.options].some(o => o.value === keep)) select.value = keep;
}

function buildStart(keep) {
  const hrs = HOURS[courtSel.value];
  if (!hrs) { startSel.innerHTML = ''; endSel.innerHTML = ''; return; }
  fill(startSel, hrs.open, hrs.close - 1, keep);
  buildEnd(keep === PICKED.start ? PICKED.end : endSel.value);
}

function buildEnd(keep) {
  const hrs = HOURS[courtSel.value];
  if (!hrs) return;
  const startH = parseInt(startSel.value, 10);
  fill(endSel, startH + 1, hrs.close, keep);
}

courtSel.addEventListener('change', () => buildStart(startSel.value));
startSel.addEventListener('change', () => buildEnd(endSel.value));

if (PICKED.court && [...courtSel.options].some(o => +o.value === PICKED.court)) courtSel.value = PICKED.court;
buildStart(PICKED.start);
</script>
</body>
</html>
