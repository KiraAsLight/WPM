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
    $_SESSION['role_id'] = $user['role_id']; // PASTIKAN INI ADA
    $_SESSION['role_name'] = fetchOne('SELECT name FROM roles WHERE id = ?', [$user['role_id']])['name'] ?? 'User';
    
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
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/login.css?v=<?= file_exists('assets/css/login.css') ? filemtime('assets/css/login.css') : time() ?>">
</head>

<body>
  <div class="login-container">
    <div class="logo-container">
      <div class="logo">W</div>
      <h1>Selamat Datang</h1>
      <p class="subtitle">Masuk ke sistem <?= h($appName) ?></p>
    </div>

    <?php if (isset($errors['login'])): ?>
      <div class="error">
        <?= h($errors['login']) ?>
      </div>
    <?php endif; ?>

    <form method="post">
      <div class="form-group">
        <label for="username">Username</label>
        <div class="input-wrapper">
          <input 
            type="text" 
            id="username" 
            name="username" 
            placeholder="Masukkan username Anda"
            required 
            autofocus
            value="<?= h($_POST['username'] ?? '') ?>"
          >
          <div class="icon">👤</div>
        </div>
      </div>

      <div class="form-group">
        <label for="password">Password</label>
        <div class="input-wrapper">
          <input 
            type="password" 
            id="password" 
            name="password" 
            placeholder="Masukkan password Anda"
            required
          >
          <div class="icon">🔒</div>
        </div>
      </div>

      <button type="submit" class="btn-login">
        Masuk ke Sistem
      </button>
    </form>

    <div class="footer">
      <p>© <?= date('Y') ?> <?= h($appName) ?></p>
      <p>Sistem Manajemen Proyek Terintegrasi</p>
    </div>
  </div>

  <script>
    // Simple form enhancement
    document.addEventListener('DOMContentLoaded', function() {
      const form = document.querySelector('form');
      const submitBtn = document.querySelector('.btn-login');
      
      form.addEventListener('submit', function() {
        submitBtn.classList.add('loading');
        submitBtn.disabled = true;
      });

      // Add input focus effects
      const inputs = document.querySelectorAll('input');
      inputs.forEach(input => {
        input.addEventListener('focus', function() {
          this.parentElement.style.transform = 'scale(1.02)';
        });
        
        input.addEventListener('blur', function() {
          this.parentElement.style.transform = 'scale(1)';
        });
      });
    });
  </script>
</body>

</html>