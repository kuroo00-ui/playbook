<?php
include 'auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $id = (int)$_POST['reservation_id'];

  // Only cancel your OWN reservation, and only if it is still pending or approved
  $stmt = $conn->prepare(
    "UPDATE reservations SET status = 'cancelled'
     WHERE reservation_id = ? AND user_id = ? AND status IN ('pending','approved')");
  $stmt->bind_param("ii", $id, $_SESSION['user_id']);
  $stmt->execute();

  // If a reservation really was cancelled and it was already paid, mark it refunded
  if ($stmt->affected_rows > 0) {
    $stmt = $conn->prepare("UPDATE payments SET status = 'refunded' WHERE reservation_id = ? AND status = 'paid'");
    $stmt->bind_param("i", $id);
    $stmt->execute();
  }
}
header("Location: index.php");
exit;