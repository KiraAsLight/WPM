<?php
declare(strict_types=1);

session_start();

require_once 'config.php';

$appName = APP_NAME;

// If already logged in, redirect to dashboard
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
  header('Location: dashboard.php');
  exit;
}

$errors = [];
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    // Authenticate against database
    $user = fetchOne('SELECT * FROM users WHERE username = ?', [$username]);
    if ($user && password_verify($password, $user['password_hash'])) {
      $_SESSION['logged_in'] = true;
      $_SESSION['username'] = $username;
      $_SESSION['user_id'] = $user['id'];
      $_SESSION['full_name'] = $user['full_name'];
      header('Location: dashboard.php');
      exit;
    } else {
      $errors['login'] = 'Username atau password salah';
    }
  }
?>
<!DOCTYPE html>
<html lang="id">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login - <?= h($appName) ?></title>
  <link rel="stylesheet" href="assets/css/login.css?v=<?= file_exists('assets/css/login.css') ? filemtime('assets/css/login.css') : time() ?>">
</head>

<body>
  <div class="login-container">
    <div class="logo">W</div>
    <h1>Login</h1>
    <p>Masuk ke sistem manajemen proyek <?= h($appName) ?></p>

    <?php if (isset($errors['login'])): ?>
      <div class="error"><?= h($errors['login']) ?></div>
    <?php endif; ?>

    <form method="post">
      <div class="form-group">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" required autofocus>
      </div>

      <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required>
      </div>

      <button type="submit" class="btn-login">Masuk</button>
    </form>

    <div class="footer">
      <p>© <?= date('Y') ?> <?= h($appName) ?> • Sistem Manajemen Proyek</p>
      <p style="margin:5px 0 0 0;font-size:11px">Username: adminwgj, Password: wgj@2025#</p>
    </div>
  </div>
</body>

</html>
