<?php
include 'auth.php';
require_role('customer');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Hide only this customer's own reservations that have already ended.
  // Pending ones are kept so a request that is still waiting is never hidden.
  $stmt = $conn->prepare(
    "UPDATE reservations SET hidden_by_customer = 1
     WHERE user_id = ? AND hidden_by_customer = 0 AND status <> 'pending'
       AND (reservation_date < CURDATE()
            OR (reservation_date = CURDATE() AND end_time < CURTIME()))");
  $stmt->bind_param("i", $_SESSION['user_id']);
  $stmt->execute();
}
header("Location: index.php");
exit;