<?php
/*
 * report_damage.php
 * Staff records the outcome of equipment that was checked out:
 *   good / fair / damaged / missing
 *
 * Uses these tables from schema.sql:
 *   equipment_borrowings (checkout_time, return_time, qty_returned, return_condition)
 *   equipment            (total_quantity, available_qty)
 *   maintenance_reports  (equipment_id, reported_by, issue)
 *
 * "Still out" = checkout_time is set AND return_time is empty.
 * (Checkout is built by a groupmate; until then, see the test SQL in the notes.)
 */
include 'auth.php';
require_role('staff', 'admin');

$message = "";
$error   = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $borrow_id = (int)$_POST['borrowing_id'];
  $outcome   = $_POST['outcome'] ?? '';
  $qty_bad   = (int)($_POST['qty_affected'] ?? 0);
  $notes     = trim($_POST['notes'] ?? '');

  if (!in_array($outcome, ['good', 'fair', 'damaged', 'missing'])) {
    $error = "Choose an outcome.";
  } else {
    try {
      $conn->begin_transaction();   // all steps succeed together, or none do

      // 1. Load the borrowing (must be checked out and not yet returned)
      $stmt = $conn->prepare(
        "SELECT b.equipment_id, b.quantity, e.equipment_name
         FROM equipment_borrowings b
         JOIN equipment e ON e.equipment_id = b.equipment_id
         WHERE b.borrowing_id = ? AND b.checkout_time IS NOT NULL AND b.return_time IS NULL
         FOR UPDATE");
      $stmt->bind_param("i", $borrow_id);
      $stmt->execute();
      $b = $stmt->get_result()->fetch_assoc();
      if (!$b) { throw new Exception("This item was already returned or hasn't been checked out."); }

      // 2. How many are damaged/missing?
      if ($outcome === 'good' || $outcome === 'fair') { $qty_bad = 0; }
      if (($outcome === 'damaged' || $outcome === 'missing') && ($qty_bad < 1 || $qty_bad > $b['quantity'])) {
        throw new Exception("Quantity must be between 1 and " . $b['quantity'] . ".");
      }

      // 3. Work out the numbers
      //    damaged: all items are physically back, but the damaged ones are NOT put back in stock
      //    missing: the missing ones never came back, so they leave the inventory total
      $qty_returned = ($outcome === 'missing') ? $b['quantity'] - $qty_bad : $b['quantity'];
      $add_available = $b['quantity'] - $qty_bad;                  // good items back on the shelf
      $lose_total    = ($outcome === 'missing') ? $qty_bad : 0;    // lost for good

      // 4. Close the borrowing
      $stmt = $conn->prepare(
        "UPDATE equipment_borrowings
         SET return_time = NOW(), qty_returned = ?, return_condition = ?
         WHERE borrowing_id = ?");
      $stmt->bind_param("isi", $qty_returned, $outcome, $borrow_id);
      $stmt->execute();

      // 5. Update the stock
      $stmt = $conn->prepare(
        "UPDATE equipment
         SET available_qty = available_qty + ?, total_quantity = total_quantity - ?
         WHERE equipment_id = ?");
      $stmt->bind_param("iii", $add_available, $lose_total, $b['equipment_id']);
      $stmt->execute();

      // 6. Record a maintenance/damage report
      if ($qty_bad > 0) {
        $issue = ucfirst($outcome) . " on return: " . $qty_bad . " x " . $b['equipment_name']
               . ($notes !== '' ? " - " . $notes : "") . " (borrowing #" . $borrow_id . ")";
        $issue = mb_substr($issue, 0, 255);
        $stmt = $conn->prepare(
          "INSERT INTO maintenance_reports (equipment_id, reported_by, issue) VALUES (?, ?, ?)");
        $stmt->bind_param("iis", $b['equipment_id'], $_SESSION['user_id'], $issue);
        $stmt->execute();
      }

      $conn->commit();
      $message = "Return recorded.";
    } catch (Throwable $e) {
      $conn->rollback();
      $error = $e->getMessage();
    }
  }
}

// Items that are still out
$items = $conn->query(
  "SELECT b.borrowing_id, b.quantity, e.equipment_name,
          u.first_name, u.last_name, r.reservation_date
   FROM equipment_borrowings b
   JOIN equipment e    ON e.equipment_id   = b.equipment_id
   JOIN reservations r ON r.reservation_id = b.reservation_id
   JOIN users u        ON u.user_id        = r.user_id
   WHERE b.checkout_time IS NOT NULL AND b.return_time IS NULL
   ORDER BY r.reservation_date");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Equipment Returns - Playbook</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container">
    <a class="navbar-brand" href="index.php">Playbook</a>
    <a class="btn btn-outline-light" href="logout.php">Log Out</a>
  </div>
</nav>

<div class="container">
  <h4>Equipment Returns</h4>

  <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <table class="table table-bordered bg-white align-middle">
    <thead>
      <tr><th>Equipment</th><th>Qty out</th><th>Borrower</th><th>Date</th><th>Return outcome</th></tr>
    </thead>
    <tbody>
    <?php while ($i = $items->fetch_assoc()): ?>
      <tr>
        <td><?= htmlspecialchars($i['equipment_name']) ?></td>
        <td><?= (int)$i['quantity'] ?></td>
        <td><?= htmlspecialchars($i['first_name'] . ' ' . $i['last_name']) ?></td>
        <td><?= $i['reservation_date'] ?></td>
        <td>
          <form method="POST" class="d-flex gap-2">
            <input type="hidden" name="borrowing_id" value="<?= $i['borrowing_id'] ?>">
            <select name="outcome" class="form-select form-select-sm" style="width:120px">
              <option value="good">Good</option>
              <option value="fair">Fair</option>
              <option value="damaged">Damaged</option>
              <option value="missing">Missing</option>
            </select>
            <input type="number" name="qty_affected" min="0" max="<?= (int)$i['quantity'] ?>"
                   value="0" class="form-control form-control-sm" style="width:70px"
                   title="How many are damaged or missing">
            <input type="text" name="notes" placeholder="Notes" class="form-control form-control-sm">
            <button class="btn btn-primary btn-sm">Save</button>
          </form>
        </td>
      </tr>
    <?php endwhile; ?>
    </tbody>
  </table>
</div>
</body>
</html>
