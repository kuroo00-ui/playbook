<?php
include 'auth.php';
require_role('staff', 'admin');

if ($_SESSION['role'] === 'admin') {
  $court_id = (int)($_SESSION['view_court_id'] ?? 0);   // the court the admin is viewing
} else {
  $stmt = $conn->prepare("SELECT staff_court_id FROM users WHERE user_id = ?");
  $stmt->bind_param("i", $_SESSION['user_id']);
  $stmt->execute();
  $court_id = (int)$stmt->get_result()->fetch_assoc()['staff_court_id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $court_id > 0) {
  $action = $_POST['action'] ?? '';

  if ($action === 'checkout' || $action === 'return') {
    $stmt = $conn->prepare(
      "SELECT b.* FROM equipment_borrowings b
       JOIN reservations r ON r.reservation_id = b.reservation_id
       WHERE b.borrowing_id = ? AND r.court_id = ? AND r.status = 'approved'");
    $bid = (int)$_POST['borrowing_id'];
    $stmt->bind_param("ii", $bid, $court_id);
    $stmt->execute();
    $b = $stmt->get_result()->fetch_assoc();

    if ($b && $action === 'checkout' && !$b['checkout_time']) {
      $stmt = $conn->prepare(
        "UPDATE equipment SET available_qty = available_qty - ? WHERE equipment_id = ? AND available_qty >= ?");
      $stmt->bind_param("iii", $b['quantity'], $b['equipment_id'], $b['quantity']);
      $stmt->execute();
      if ($stmt->affected_rows > 0) {
        $stmt = $conn->prepare("UPDATE equipment_borrowings SET checkout_time = NOW() WHERE borrowing_id = ?");
        $stmt->bind_param("i", $bid);
        $stmt->execute();
      }

    } elseif ($b && $action === 'return' && $b['checkout_time'] && !$b['return_time']) {
      $cond = $_POST['return_condition'] ?? 'good';
      if (!in_array($cond, ['good', 'fair', 'damaged', 'missing'])) { $cond = 'good'; }
      $qty = max(0, min((int)$_POST['qty_returned'], (int)$b['quantity']));

      $stmt = $conn->prepare(
        "UPDATE equipment_borrowings SET return_time = NOW(), qty_returned = ?, return_condition = ?
         WHERE borrowing_id = ?");
      $stmt->bind_param("isi", $qty, $cond, $bid);
      $stmt->execute();

      if ($cond === 'good' || $cond === 'fair') {
        $stmt = $conn->prepare("UPDATE equipment SET available_qty = available_qty + ? WHERE equipment_id = ?");
        $stmt->bind_param("ii", $qty, $b['equipment_id']);
        $stmt->execute();
      }

      if ($cond === 'damaged' || $cond === 'missing' || $qty < $b['quantity']) {
        $issue = "Return check: $cond, $qty of {$b['quantity']} returned (reservation #{$b['reservation_id']})";
        $itype = ($cond === 'damaged') ? 'Damaged' : 'Missing';
        $stmt = $conn->prepare("INSERT INTO maintenance_reports (equipment_id, reported_by, issue, issue_type) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iiss", $b['equipment_id'], $_SESSION['user_id'], $issue, $itype);
        $stmt->execute();
      }
    }

  } elseif ($action === 'report') {
    $eid     = (int)$_POST['equipment_id'];
    $type    = in_array($_POST['issue_type'], ['Damaged', 'Missing', 'Defective']) ? $_POST['issue_type'] : 'Damaged';
    $issue   = $type . ': ' . trim($_POST['details']);
    $cid     = ($eid === 0) ? $court_id : null;
    $eid_val = ($eid === 0) ? null : $eid;
    $stmt = $conn->prepare(
      "INSERT INTO maintenance_reports (court_id, equipment_id, reported_by, issue, issue_type) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("iiiss", $cid, $eid_val, $_SESSION['user_id'], $issue, $type);
    $stmt->execute();

  } elseif ($action === 'set_status') {
    // reported -> in_repair -> fixed (settled), only for reports this court can see
    $rid    = (int)$_POST['report_id'];
    $status = $_POST['status'] ?? '';
    if (in_array($status, ['in_repair', 'fixed'])) {
      $stmt = $conn->prepare(
        "UPDATE maintenance_reports SET repair_status = ?
         WHERE report_id = ? AND (court_id = ? OR equipment_id IS NOT NULL)");
      $stmt->bind_param("sii", $status, $rid, $court_id);
      $stmt->execute();
    }

  } elseif ($action === 'clear_reports') {
    // Hide ONLY settled reports. Open and in-repair reports are never touched.
    $stmt = $conn->prepare(
      "UPDATE maintenance_reports SET hidden_by_staff = 1
       WHERE repair_status = 'fixed' AND hidden_by_staff = 0
         AND (court_id = ? OR equipment_id IS NOT NULL)");
    $stmt->bind_param("i", $court_id);
    $stmt->execute();
  }
}
header("Location: staff_dashboard.php#reports");
exit;