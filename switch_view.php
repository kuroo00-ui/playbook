<?php
include 'auth.php';
require_role('staff', 'admin');

$role    = $_SESSION['role'];
// Customer view is not allowed. Only admins may switch (admin <-> staff).
$allowed = ($role === 'admin') ? ['admin', 'staff'] : ['staff'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $target = $_POST['view'] ?? '';
  if (in_array($target, $allowed)) { $_SESSION['view'] = $target; }
}

// Fix any old session left in customer view
if (!in_array($_SESSION['view'] ?? '', $allowed)) { $_SESSION['view'] = $role; }

$pages = ['staff' => 'staff_dashboard.php', 'admin' => 'admin_approvals.php'];
header("Location: " . $pages[$_SESSION['view']]);
exit;