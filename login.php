<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
include 'connect.php';   
$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['email']);
  $pass  = $_POST['password'];


  $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
  $stmt->bind_param("s", $email);
  $stmt->execute();
  $user = $stmt->get_result()->fetch_assoc();

    if ($user && password_verify($pass, $user['password_hash'])) {

    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['user_id'];
    $_SESSION['role']    = $user['role'];
    $_SESSION['name']    = $user['first_name'];

    $_SESSION['view']    = $user['role'];

    if ($user['role'] === 'admin') {
      header("Location: admin_approvals.php");
    } elseif ($user['role'] === 'staff') {
      header("Location: staff_dashboard.php");
    } else {
      header("Location: index.php");
    }
    exit;

  } else {
      $error = "Invalid email or password.";
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Log In - Playbook</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container"><a class="navbar-brand" href="login.php">Playbook</a></div>
</nav>

<div class="container" style="max-width:480px">
  <h4>Log In</h4>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" class="bg-white p-3 border rounded">

    <label class="form-label">Email</label>
    <input type="email" name="email" class="form-control mb-3" required>

    <label class="form-label">Password</label>
    <input type="password" name="password" class="form-control mb-3" required>

    <button type="submit" class="btn btn-success w-100">Log In</button>
  </form>

  <p class="mt-3 text-center">No account yet? <a href="register.php">Register</a></p>
</div>

</body>
</html>