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
?>