<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';

// Already logged in? Go to dashboard.
if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $creds    = auth_credentials();

    if ($username === $creds['user'] && $password === $creds['pass']) {
        $_SESSION['auth_logged_in'] = true;
        $_SESSION['auth_user']      = $username;
        header('Location: index.php');
        exit;
    } else {
        $error = 'Invalid username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login — PinoyRide Admin</title>
<link href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.3/dist/cosmo/bootstrap.min.css" rel="stylesheet">
<style>
  body { background: #f4f6f9; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
  .login-card { width: 100%; max-width: 380px; }
</style>
</head>
<body>

<div class="login-card">
  <div class="card shadow-sm">
    <div class="card-body p-4">
      <h4 class="text-center mb-4">PinoyRide Admin</h4>

      <?php if ($error !== ''): ?>
        <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="post">
        <div class="mb-3">
          <label class="form-label">Username</label>
          <input type="text" name="username" class="form-control" required autofocus>
        </div>
        <div class="mb-3">
          <label class="form-label">Password</label>
          <input type="password" name="password" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary w-100">Log In</button>
      </form>
    </div>
  </div>
</div>

</body>
</html>
