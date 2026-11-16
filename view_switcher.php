<?php
$role = $_SESSION['role'] ?? '';
$cur  = $_SESSION['view'] ?? $role;
// Only admins can switch, and only between Admin and Staff view.
if ($role === 'admin'):
  $options = ['admin' => 'Admin View', 'staff' => 'Staff View'];
?>
<form method="POST" action="switch_view.php" class="m-0 d-flex gap-1">
  <?php foreach ($options as $v => $label): if ($v === $cur) continue; ?>
    <button name="view" value="<?= $v ?>" class="btn btn-warning">Switch to <?= $label ?></button>
  <?php endforeach; ?>
</form>
<?php endif; ?>