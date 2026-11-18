<?php
include 'auth.php';
require_login();

// Get this user's notifications, newest first
$stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY date_sent DESC");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Mark them all as read (we fetched first, so this page can still show which were new)
$stmt = $conn->prepare("UPDATE notifications SET notification_status = 'read' WHERE user_id = ? AND notification_status = 'unread'");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Notifications - Playbook</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container">
    <a class="navbar-brand" href="<?= home_url() ?>">Playbook</a>
<div class="d-flex gap-2">
  <?php if ($_SESSION['role'] !== 'customer'): ?>
    <a class="btn btn-outline-light" href="<?= home_url() ?>">Back</a>
  <?php endif; ?>
  <a class="btn btn-outline-light" href="logout.php">Log Out</a>
</div>
</nav>

<div class="container" style="max-width:700px">
  <h4>Notifications</h4>
  <div class="list-group">
    <?php foreach ($list as $n): ?>
      <div class="list-group-item">
        <?php if ($n['notification_status'] === 'unread'): ?>
          <span class="badge bg-danger">New</span>
        <?php endif; ?>
        <?= htmlspecialchars($n['message']) ?>
        <div class="text-muted small"><?= $n['date_sent'] ?></div>
      </div>
    <?php endforeach; ?>
    <?php if (count($list) === 0): ?>
      <div class="list-group-item text-muted">No notifications yet.</div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>