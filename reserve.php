<?php
include 'auth.php';
require_login();
if ($_SESSION['role'] !== 'customer') { header("Location: index.php"); exit; }

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $court_id = (int)$_POST['court_id'];
  $date     = $_POST['reservation_date'];
  $start    = $_POST['start_time'];
  $end      = $_POST['end_time'];
  $purpose  = trim($_POST['purpose']);

  if (strlen($start) === 5) { $start .= ':00'; }
  if (strlen($end)   === 5) { $end   .= ':00'; }

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

        $stmt = $conn->prepare(
        "SELECT
           (SELECT COUNT(*) FROM reservations
             WHERE court_id = ? AND reservation_date = ?
               AND status IN ('pending','approved') AND ? < end_time AND ? > start_time)
         + (SELECT COUNT(*) FROM schedules
             WHERE court_id = ? AND schedule_date = ?
               AND schedule_status = 'blocked' AND ? < end_time AND ? > start_time) AS n");
      $stmt->bind_param("isssisss", $court_id, $date, $start, $end, $court_id, $date, $start, $end);
      $stmt->execute();
      $n = $stmt->get_result()->fetch_assoc()['n'];

            if ($n > 0) {
        $error = "That time slot overlaps with an existing reservation.";
      } else {
        // Collect the equipment the customer asked for (quantity above 0)
        $items = [];
        foreach (($_POST['equip'] ?? []) as $eid => $qty) {
          if ((int)$qty > 0) { $items[(int)$eid] = (int)$qty; }
        }

        // Check there is enough stock for each item
        foreach ($items as $eid => $qty) {
          $stmt = $conn->prepare("SELECT equipment_name, available_qty FROM equipment WHERE equipment_id = ?");
          $stmt->bind_param("i", $eid);
          $stmt->execute();
          $eq = $stmt->get_result()->fetch_assoc();
          if (!$eq || $qty > $eq['available_qty']) {
            $error = "Not enough " . ($eq['equipment_name'] ?? 'equipment') . " available.";
            break;
          }
        }

        // No problems, so save the reservation and its equipment
        if ($error === "") {
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
          header("Location: index.php");
          exit;
        }
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
    <select name="court_id" id="court_id" class="form-select mb-3" required>
      <?php
        $courts = $conn->query("SELECT court_id, court_name FROM courts WHERE status = 'available'");
        while ($c = $courts->fetch_assoc()) {
          echo "<option value='{$c['court_id']}'>" . htmlspecialchars($c['court_name']) . "</option>";
        }
      ?>
    </select>

    <label class="form-label">Date</label>
    <input type="date" name="reservation_date" id="reservation_date" class="form-control mb-3" min="<?= date('Y-m-d') ?>" required>

    <div class="row mb-1">
      <div class="col">
        <label class="form-label">Start</label>
        <div class="input-group">
          <select id="start_h" class="form-select" required></select>
          <select id="start_m" class="form-select" required></select>
        </div>
      </div>
      <div class="col">
        <label class="form-label">End</label>
        <div class="input-group">
          <select id="end_h" class="form-select" required></select>
          <select id="end_m" class="form-select" required></select>
        </div>
      </div>
    </div>
    <input type="hidden" name="start_time" id="start_time">
    <input type="hidden" name="end_time" id="end_time">
    <div id="slotInfo" class="form-text mb-3">Choose a court and date to see available hours.</div>

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
          (₱<?= number_format($e['rental_fee'], 2) ?>, <?= $e['available_qty'] ?> available)
        </span>
        <input type="number" name="equip[<?= $e['equipment_id'] ?>]"
               class="form-control" style="width:80px" min="0" max="<?= $e['available_qty'] ?>" value="0">
      </div>
    <?php endwhile; ?>

    <button class="btn btn-success w-100">Submit Request</button>
  </form>
</div>

<script>
const courtEl = document.getElementById('court_id');
const dateEl  = document.getElementById('reservation_date');
const sh = document.getElementById('start_h'), sm = document.getElementById('start_m');
const eh = document.getElementById('end_h'),   em = document.getElementById('end_m');
const startEl = document.getElementById('start_time');
const endEl   = document.getElementById('end_time');
const infoEl  = document.getElementById('slotInfo');
let slots = null, earliest = 0;

const pad = n => String(n).padStart(2, '0');
const fmt = m => pad(Math.floor(m / 60)) + ':' + pad(m % 60);

function fillSelect(el, items, placeholder) {
  const prev = el.value;
  el.innerHTML = '<option value="">' + placeholder + '</option>' +
    items.map(v => `<option value="${v}">${pad(v)}</option>`).join('');
  if (items.map(String).includes(prev)) el.value = prev;
}

// lo and hi are minutes since midnight; the lists only contain times from lo to hi
function build(hEl, mEl, lo, hi) {
  const hours = [];
  for (let h = Math.floor(lo / 60); h <= Math.floor(hi / 60); h++) hours.push(h);
  fillSelect(hEl, hours, 'HH');

  const mins = [];
  if (hEl.value !== '') {
    const h = +hEl.value;
    const from = (h === Math.floor(lo / 60)) ? lo % 60 : 0;
    const to   = (h === Math.floor(hi / 60)) ? hi % 60 : 59;
    for (let m = from; m <= to; m++) mins.push(m);
  }
  fillSelect(mEl, mins, 'MM');
}

function refresh() {
  em.setCustomValidity('');
  if (!slots || slots.error) { startEl.value = endEl.value = ''; return; }

  const lo = Math.max(slots.open, earliest);

  // Start: from the opening time up to one minute before closing
  build(sh, sm, lo, slots.close - 1);
  const s = (sh.value !== '' && sm.value !== '') ? (+sh.value) * 60 + (+sm.value) : null;

  // End: after the chosen start, up to the closing time
  build(eh, em, (s !== null ? s + 1 : lo + 1), slots.close);
  const e = (eh.value !== '' && em.value !== '') ? (+eh.value) * 60 + (+em.value) : null;

  startEl.value = s !== null ? fmt(s) + ':00' : '';
  endEl.value   = e !== null ? fmt(e) + ':00' : '';

  if (s !== null && e !== null && slots.booked.some(([bs, be]) => s < be && e > bs)) {
    em.setCustomValidity('That time overlaps an existing booking.');
  }
}

async function loadSlots() {
  slots = null;
  if (!courtEl.value || !dateEl.value) { refresh(); return; }

  const res = await fetch(`get_slots.php?court_id=${courtEl.value}&date=${dateEl.value}`);
  slots = await res.json();
  if (slots.error) { infoEl.textContent = 'That court is not available.'; refresh(); return; }

  // For today, times that already passed are not offered
  const now = new Date();
  earliest = (dateEl.value === now.toLocaleDateString('en-CA')) ? now.getHours() * 60 + now.getMinutes() : 0;

  let text = `Open ${fmt(slots.open)} to ${fmt(slots.close)}.`;
  if (slots.booked.length) {
    text += ' Not available: ' + slots.booked
      .sort((a, b) => a[0] - b[0])
      .map(([s, e]) => fmt(s) + '-' + fmt(e)).join(', ') + '.';
  }
  infoEl.textContent = text;
  refresh();
}

[sh, sm, eh, em].forEach(el => el.addEventListener('change', () => {
  refresh();
  if (el === em && !em.checkValidity()) em.reportValidity();
}));
courtEl.addEventListener('change', loadSlots);
dateEl.addEventListener('change', loadSlots);
refresh();
loadSlots();
</script>
</body>
</html>
