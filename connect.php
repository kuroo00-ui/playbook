<?php
date_default_timezone_set('Asia/Manila');
// Database connection: included by every page that needs the database.
$conn = new mysqli("localhost", "root", "", "playbook");
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");
session_start();

?>
