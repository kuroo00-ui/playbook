<?php
include 'auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)$_POST['reservation_id'];

    $stmt = $conn->prepare(
        "UPDATE reservations SET status = 'cancelled' 
        WHERE reservation_id = ? AND user_id = ? AND status IN ('pending', 'approved')");
    $stmt->bind_param("ii", $id, $_SESSION['user_id']);
    $stmt->execute();
    
}
header("Location: index.php");
exit;