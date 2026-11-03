<?php
// Database connection: included by every page that needs the database.
$conn = new mysqli("localhost", "root", "", "playbook");
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");
session_start();
// TEMPORARY: pretend user 1 is logged in until the login page is built.
if (!isset($_SESSION['user_id'])) { $_SESSION['user_id'] = 1; }
?>
