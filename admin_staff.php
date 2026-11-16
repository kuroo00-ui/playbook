<?php
include 'auth.php';
require_role('admin');
if (($_SESSION['view'] ?? 'admin') !== 'admin') { header("Location: index.php"); exit; }

$error = ""; $ok = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'add_staff') {
    $first   = trim($_POST['first_name']);
    $last    = trim($_POST['last_name']);
    $email   = trim($_POST['email']);
    $contact = trim($_POST['contact_number']);
    $pass    = $_POST['password'];
    $court   = (int)$_POST['staff_court_id'];

    $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();

    if ($pass === '' || $court <= 0) {
      $error = "Enter a password and choose a court.";
    } elseif ($stmt->num_rows > 0) {
      $error = "That email is already registered.";
    } else {
      $hash = password_hash($pass, PASSWORD_DEFAULT);
      $stmt = $conn->prepare(
        "INSERT INTO users (first_name, last_name, email, contact_number, password_hash, role, staff_court_id)
         VALUES (?, ?, ?, ?, ?, 'staff', ?)");
      $stmt->bind_param("sssssi", $first, $last, $email, $contact, $hash, $court);
      $stmt->execute();
      $ok = "Staff account created for " . $email . ".";
    }

  } elseif ($action === 'change_court') {
    $uid = (int)$_POST['user_id']; $court = (int)$_POST['staff_court_id'];
    $stmt = $conn->prepare("UPDATE users SET staff_court_id = ? WHERE user_id = ? AND role = 'staff'");
    $stmt->bind_param("ii", $court, $uid);
    $stmt->execute();
    $ok = "Court updated.";

  } elseif ($action === 'remove_staff') {
    // Turns the account back into a normal customer account (keeps their history)
    $uid = (int)$_POST['user_id'];
    $stmt = $conn->prepare("UPDATE users SET role = 'customer', staff_court_id = NULL WHERE user_id = ? AND role = 'staff'");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $ok = "Staff access removed.";
  }
}

$courts = $conn->query("SELECT court_id, court_name FROM courts ORDER BY court_name")->fetch_all(MYSQLI_ASSOC);
$staff  = $conn->query(
  "SELECT u.*, c.court_name FROM users u
   LEFT JOIN courts c ON c.court_id = u.staff_court_id
   WHERE u.role = 'staff' ORDER BY u.first_name")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Staff - Playbook</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container">
    <a class="navbar-brand" href="index.php">Playbook</a>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-light" href="admin_approvals.php">Approvals</a>
      <a class="btn btn-outline-light" href="admin_equipment.php">Equipment</a>
      <a class="btn btn-outline-light" href="admin_courts.php">Courts</a>

    <a class="btn btn-outline-light" href="calendar.php">Schedule</a>

      <?php include 'view_switcher.php'; ?>
      <a class="btn btn-outline-light" href="logout.php">Log Out</a>
    </div>
  </div>
</nav>

<div class="container">
  <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="alert alert-success"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

  <h4>Add Staff Account</h4>
  <form method="POST" class="row g-2 bg-white p-3 border rounded mb-4">
    <input type="hidden" name="action" value="add_staff">
    <div class="col-md-3"><input type="text" name="first_name" class="form-control" placeholder="First name" required></div>
    <div class="col-md-3"><input type="text" name="last_name" class="form-control" placeholder="Last name" required></div>
    <div class="col-md-3"><input type="email" name="email" class="form-control" placeholder="Email" required></div>
    <div class="col-md-3"><input type="text" name="contact_number" class="form-control" placeholder="Contact number"></div>
    <div class="col-md-4"><input type="text" name="password" class="form-control" placeholder="Starting password" required></div>
    <div class="col-md-5">
      <select name="staff_court_id" class="form-select" required>
        <option value="">Assign to court</option>
        <?php foreach ($courts as $c): ?>
          <option value="<?= $c['court_id'] ?>"><?= htmlspecialchars($c['court_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3"><button class="btn btn-success w-100">Create Staff</button></div>
  </form>

  <h4>Staff Accounts</h4>
  <table class="table table-bordered bg-white align-middle">
    <thead><tr><th>Name</th><th>Email</th><th>Court</th><th></th></tr></thead>
    <tbody>
    <?php if (!$staff): ?>
      <tr><td colspan="4" class="text-muted text-center">No staff accounts yet.</td></tr>
    <?php endif; ?>
    <?php foreach ($staff as $s): ?>
      <tr>
        <td><?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?></td>
        <td><?= htmlspecialchars($s['email']) ?></td>
        <td>
          <form method="POST" class="d-flex gap-2 m-0">
            <input type="hidden" name="action" value="change_court">
            <input type="hidden" name="user_id" value="<?= $s['user_id'] ?>">
            <select name="staff_court_id" class="form-select form-select-sm">
              <?php foreach ($courts as $c): ?>
                <option value="<?= $c['court_id'] ?>" <?= $s['staff_court_id'] == $c['court_id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($c['court_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-primary btn-sm">Save</button>
          </form>
        </td>
        <td>
          <form method="POST" class="m-0" onsubmit="return confirm('Remove staff access for this account?')">
            <input type="hidden" name="action" value="remove_staff">
            <input type="hidden" name="user_id" value="<?= $s['user_id'] ?>">
            <button class="btn btn-outline-danger btn-sm">Remove staff</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
</body>
</html>