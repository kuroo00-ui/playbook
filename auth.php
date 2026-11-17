<?php
include 'connect.php';

function require_login() {
    if (!isset($_SESSION['user_id'])) {
        header("location: login.php");
        exit;
    }
}

function require_role(...$roles){
require_login();
if (!in_array($_SESSION['role'], $roles)) {
    http_response_code(403);
    die("You don't have access to this page.");
    }
}
function notify($conn, $user_id, $message) {
  $stmt = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
  $stmt->bind_param("is", $user_id, $message);
  $stmt->execute();
}
function notify_admins($conn, $message) {
  $admins = $conn->query("SELECT user_id FROM users WHERE role = 'admin'");
  while ($a = $admins->fetch_assoc()) {
    notify($conn, $a['user_id'], $message);
  }
}
?>