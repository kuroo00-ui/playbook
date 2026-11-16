<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
// Database connection: included by every page that needs the database.
$conn = new mysqli('127.0.0.1', 'root', '', 'playbook'); // $conn = new mysqli("localhost", "root", "", "playbook");
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");
session_start();

?>
