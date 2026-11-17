<?php
include 'auth.php';
require_role('staff', 'admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  // ----- Report something as broken -----
  if ($_POST['action'] === 'report') {
    // The dropdown value looks like "court:2" or "equipment:3"
    [$type, $id] = array_pad(explode(':', $_POST['item']), 2, 0);
    $id    = (int)$id;
    $issue = trim($_POST['issue']);
    $qty   = (int)($_POST['qty'] ?? 1);

    if ($issue !== '' && in_array($type, ['court', 'equipment'])) {
      if ($type === 'court') {
        $stmt = $conn->prepare("INSERT INTO maintenance_reports (court_id, reported_by, issue) VALUES (?, ?, ?)");
        $stmt->bind_param("iis", $id, $_SESSION['user_id'], $issue);
        $stmt->execute();
        $stmt = $conn->prepare("UPDATE courts SET status = 'maintenance' WHERE court_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        notify_admins($conn, 'Court reported broken: ' . $issue);
      } else {        
        if ($qty < 1) {
          $_SESSION['flash'] = ['danger', 'Enter how many units are broken.'];
        } else {
          $conn->begin_transaction();

          $stmt = $conn->prepare(
            "UPDATE equipment
             SET available_qty = available_qty - ?, equipment_condition = 'damaged'
             WHERE equipment_id = ? AND available_qty >= ?");
          $stmt->bind_param("iii", $qty, $id, $qty);
          $stmt->execute();

          if ($stmt->affected_rows === 1) {
            $stmt = $conn->prepare(
              "INSERT INTO maintenance_reports (equipment_id, qty_damaged, reported_by, issue)
               VALUES (?, ?, ?, ?)");
            $stmt->bind_param("iiis", $id, $qty, $_SESSION['user_id'], $issue);
            $stmt->execute();
            notify_admins($conn, 'Equipment reported broken (' . $qty . ' units): ' . $issue);
            $conn->commit();
          } else {
            $conn->rollback();
            $_SESSION['flash'] = ['danger', 'Not enough units on the shelf to report that many.'];
          }
        }
      }
    }

  // ----- Mark a report as fixed -----
   // ----- Mark a report as fixed -----
  } elseif ($_POST['action'] === 'fixed') {

    // Read the report
    $rid = (int)$_POST['report_id'];
    $n   = (int)($_POST['qty_fixed'] ?? 0);

    $stmt = $conn->prepare(
      "SELECT court_id, equipment_id, qty_damaged, qty_fixed
       FROM maintenance_reports
       WHERE report_id = ? AND repair_status <> 'fixed'"
    );
    $stmt->bind_param("i", $rid);
    $stmt->execute();
    $rep = $stmt->get_result()->fetch_assoc();

    if ($rep) {

      // Calculate remaining broken quantity
      $counted = ($rep['qty_damaged'] !== null);

      $left = $counted
        ? (int)$rep['qty_damaged'] - (int)$rep['qty_fixed']
        : 0;

      if (!$counted) {
        $n = 0;
      }

      // Validate repair quantity
      if ($counted && ($n < 1 || $n > $left)) {

        $_SESSION['flash'] = [
          'danger',
          "Fixed quantity must be between 1 and $left."
        ];

      } else {

        $done   = ($n === $left);
        $status = $done ? 'fixed' : 'in_repair';

        $conn->begin_transaction();

        // Update maintenance report
        $stmt = $conn->prepare(
          "UPDATE maintenance_reports
           SET qty_fixed = qty_fixed + ?, repair_status = ?
           WHERE report_id = ?
           AND repair_status <> 'fixed'
           AND qty_fixed = ?"
        );

        $stmt->bind_param(
          "isii",
          $n,
          $status,
          $rid,
          $rep['qty_fixed']
        );

        $stmt->execute();

        $ok = ($stmt->affected_rows === 1);

        // Return repaired equipment to available inventory
        if ($ok && $rep['court_id']) {

          $stmt = $conn->prepare(
            "UPDATE courts
             SET status = 'available'
             WHERE court_id = ?"
          );

          $stmt->bind_param("i", $rep['court_id']);
          $ok = $stmt->execute();

        } elseif ($ok && $counted) {

          $stmt = $conn->prepare(
            "UPDATE equipment
             SET available_qty = available_qty + ?
             WHERE equipment_id = ?"
          );

          $stmt->bind_param(
            "ii",
            $n,
            $rep['equipment_id']
          );

          $ok = $stmt->execute();

          // Update condition only after the final repair
          if ($ok && $done) {

            $stmt = $conn->prepare(
              "UPDATE equipment
               SET equipment_condition = 'good'
               WHERE equipment_id = ?"
            );

            $stmt->bind_param("i", $rep['equipment_id']);
            $ok = $stmt->execute();
          }
        }

        // Commit or rollback transaction
        if ($ok) {

          $conn->commit();

          $_SESSION['flash'] = [
            'success',
            $counted
              ? "$n unit(s) successfully repaired and returned to available inventory."
              : "Maintenance report marked as fixed."
          ];

        } else {

          $conn->rollback();

          $_SESSION['flash'] = [
            'warning',
            'This report changed or the update failed. Refresh and try again.'
          ];
        }
      }
    } else {
      $_SESSION['flash'] = [
        'warning',
        'This report is already fixed.'
      ];
    }
  }
  header("Location: maintenance.php");
  exit;
}

// Data for the page
$courts    = $conn->query("SELECT court_id, court_name FROM courts ORDER BY court_name");
$equipment = $conn->query("SELECT equipment_id, equipment_name FROM equipment ORDER BY equipment_name");
$reports   = $conn->query(
  "SELECT m.*, c.court_name, e.equipment_name, u.first_name
   FROM maintenance_reports m
   LEFT JOIN courts c    ON c.court_id = m.court_id
   LEFT JOIN equipment e ON e.equipment_id = m.equipment_id
   JOIN users u          ON u.user_id = m.reported_by
   ORDER BY (m.repair_status = 'fixed'), m.date_reported DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Maintenance - Playbook</title>
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
      <?php endif; ?>
      <a class="btn btn-outline-light" href="logout.php">Log Out</a>
    </div>
  </div>
</nav>

<div class="container">
    <?php if (isset($_SESSION['flash'])): ?>
    <?php [$ftype, $ftext] = $_SESSION['flash']; unset($_SESSION['flash']); ?>
    <div class="alert alert-<?= $ftype ?>"><?= htmlspecialchars($ftext) ?></div>
  <?php endif; ?>
  <h4>Report a Problem</h4>
  <form method="POST" class="row g-2 bg-white p-3 border rounded mb-4">
    <input type="hidden" name="action" value="report">
    <div class="col-md-4">
      <select name="item" class="form-select" required>
        <optgroup label="Courts">
          <?php while ($c = $courts->fetch_assoc()): ?>
            <option value="court:<?= $c['court_id'] ?>"><?= htmlspecialchars($c['court_name']) ?></option>
          <?php endwhile; ?>
        </optgroup>
        <optgroup label="Equipment">
          <?php while ($e = $equipment->fetch_assoc()): ?>
            <option value="equipment:<?= $e['equipment_id'] ?>"><?= htmlspecialchars($e['equipment_name']) ?></option>
          <?php endwhile; ?>
        </optgroup>
      </select>
    </div>
    <div class="col-md-4">
  <input type="text" name="issue" class="form-control" placeholder="What is broken? (e.g. torn net, flat ball)" maxlength="255" required>
</div>

<div class="col-md-2">
  <input type="number" name="qty" value="1" min="1" class="form-control" title="How many units (equipment only)">
</div>
    <div class="col-md-2"><button class="btn btn-danger w-100">Report Broken</button></div>
  </form>

  <h4>Maintenance Reports</h4>
  <table class="table table-bordered bg-white align-middle">
    <thead>
      <tr>
  <th>Item</th>
  <th>Problem</th>
  <th>Qty</th>
  <th>Reported by</th>
  <th>Date</th>
  <th>Status</th>
  <th></th>
</tr>
    </thead>
    <tbody>
    <?php while ($r = $reports->fetch_assoc()): ?>
      <tr>
        <td><?= htmlspecialchars($r['court_name'] ?? $r['equipment_name']) ?></td>
        <td><?= htmlspecialchars($r['issue']) ?></td>
        <td>
  <?php if ($r['qty_damaged'] !== null): ?>
    <?= (int)$r['qty_fixed'] ?> of <?= (int)$r['qty_damaged'] ?> fixed
  <?php else: ?>
    -
  <?php endif; ?>
</td>
        <td><?= htmlspecialchars($r['first_name']) ?></td>
        <td><?= substr($r['date_reported'], 0, 10) ?></td>
        <td>
          <?php if ($r['repair_status'] === 'fixed'): ?>
            <span class="badge bg-success">Fixed</span>
          <?php else: ?>
            <span class="badge bg-danger">Broken</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($r['repair_status'] !== 'fixed'): ?>
            <form method="POST" class="d-flex gap-1">
              <input type="hidden" name="action" value="fixed">
              <input type="hidden" name="report_id" value="<?= $r['report_id'] ?>">
              <?php if ($r['qty_damaged'] !== null): ?>
                <input type="number" name="qty_fixed" value="1" min="1"
                       max="<?= $r['qty_damaged'] - $r['qty_fixed'] ?>"
                       class="form-control form-control-sm" style="width:70px">
              <?php endif; ?>
              <button class="btn btn-success btn-sm">Mark Fixed</button>
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