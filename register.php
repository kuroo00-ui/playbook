<?php 
include 'connect.php';
$error="";
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $first = trim($_POST['first_name']);
    $last    = trim($_POST['last_name']);
    $email   = trim($_POST['email']);
    $contact = trim($_POST['contact_number']);
    $pass = $_POST['password'];
    $confirm = $_POST['confirm_password'];

    if ($pass === '' || $pass !== $confirm){
        $error = "Passwords must match and not be empty.";
    } else {
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE email=?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0){
            $error = "That email is already registered.";
        }else {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (first_name, last_name, email, contact_number, password_hash, role)
                              VALUES (?, ?, ?, ?, ?, 'customer')");
            $stmt->bind_param("sssss", $first, $last, $email, $contact, $hash);
            $stmt->execute();

            header("Location: login.php");
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Register - Playbook</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container"><a class="navbar-brand" href="login.php">Playbook</a></div>
</nav>

<div class="container" style="max-width:480px">
  <h4>Create an Account</h4>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" class="bg-white p-3 border rounded">

    <label class="form-label">First Name</label>
    <input type="text" name="first_name" class="form-control mb-3" required>

    <label class="form-label">Last Name</label>
    <input type="text" name="last_name" class="form-control mb-3" required>

    <label class="form-label">Email</label>
    <input type="email" name="email" class="form-control mb-3" required>

    <label class="form-label">Contact Number</label>
    <input type="text" name="contact_number" class="form-control mb-3">

    <label class="form-label">Password</label>
    <input type="password" name="password" class="form-control mb-3" required>

    <label class="form-label">Confirm Password</label>
    <input type="password" name="confirm_password" class="form-control mb-3" required>

    <button type="submit" class="btn btn-success w-100">Register</button>
  </form>

  <p class="mt-3 text-center">Already have an account? <a href="login.php">Log in</a></p>
</div>

</body>
</html>