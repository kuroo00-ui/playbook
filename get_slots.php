<?php
include 'auth.php';
require_login();
if ($_SESSION['role'] !== 'customer') { http_response_code(403); exit; }

header('Content-Type: application/json');

$court_id = (int)($_GET['court_id'] ?? 0);
$date     = $_GET['date'] ?? '';

function mins($t) { [$h, $m] = explode(':', $t); return $h * 60 + $m; }

// The court's operating hours (what the admin set)
$stmt = $conn->prepare("SELECT opening_time, closing_time FROM courts WHERE court_id = ? AND status = 'available'");
$stmt->bind_param("i", $court_id);
$stmt->execute();
$court = $stmt->get_result()->fetch_assoc();

if (!$court) {
  echo json_encode(['error' => 'Court not available']);
  exit;
}

$booked = [];

// Already reserved
$stmt = $conn->prepare(
  "SELECT start_time, end_time FROM reservations
   WHERE court_id = ? AND reservation_date = ? AND status IN ('pending','approved')");
$stmt->bind_param("is", $court_id, $date);
$stmt->execute();
$result = $stmt->get_result();
while ($r = $result->fetch_assoc()) { $booked[] = [mins($r['start_time']), mins($r['end_time'])]; }

// Blocked by the admin
$stmt = $conn->prepare(
  "SELECT start_time, end_time FROM schedules
   WHERE court_id = ? AND schedule_date = ? AND schedule_status = 'blocked'");
$stmt->bind_param("is", $court_id, $date);
$stmt->execute();
$result = $stmt->get_result();
while ($r = $result->fetch_assoc()) { $booked[] = [mins($r['start_time']), mins($r['end_time'])]; }

echo json_encode([
  'open'   => mins($court['opening_time']),
  'close'  => mins($court['closing_time']),
  'booked' => $booked
]);