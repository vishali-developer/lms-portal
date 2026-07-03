<?php
// login.php — Public login page
require_once __DIR__ . '/includes/auth.php';
startSession();

// Already logged in? Go to dashboard
if (!empty($_SESSION['user_id'])) {
    redirect(APP_URL . '/dashboard.php');
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid form submission. Please refresh and try again.';
    } else {
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!$email || !$password) {
            $error = 'Both email and password are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            $user = attemptLogin($email, $password);
            if ($user) {
                loginUser($user);
                setFlash('success', 'Welcome back, ' . $user['name'] . '!');
                redirect(APP_URL . '/dashboard.php');
            } else {
                $error = 'Invalid email or password. Please try again.';
                logActivity(null, 'Failed Login', 'Failed attempt for: ' . $email);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login — <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css">
<style>
body { 
  background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 50%, #0f172a 100%); 
  font-family:'Plus Jakarta Sans',sans-serif; 
}
.particle {
  position: fixed;
  width: 2px; height: 2px;
  background: rgba(255,255,255,.25);
  border-radius: 50%;
  animation: floatUp linear infinite;
  pointer-events: none;
}
@keyframes floatUp {
  0%   { transform: translateY(100vh) scale(0); opacity:0; }
  10%  { opacity: 1; }
  90%  { opacity: .6; }
  100% { transform: translateY(-5vh) translateX(40px) scale(1.5); opacity:0; }
}
</style>
</head>
<body class="login-page" >

<!-- Animated particles -->
<div id="particles" aria-hidden="true"></div>

<div class="d-flex align-items-center justify-content-center min-vh-100 px-3" style="position:relative;z-index:1;">
  <div class="login-card">

    <div class="login-logo">
      <i class="bi bi-bullseye"></i>
    </div>

    <h1 class="text-center fw-800 mb-1" style="font-size:1.55rem; letter-spacing:-.02em;">
      <?= APP_NAME ?>
    </h1>
    <p class="text-center text-muted mb-4" style="font-size:.85rem;">
      Sign in to manage your leads
    </p>

    <?php if ($error): ?>
    <div class="alert alert-danger d-flex align-items-center gap-2 py-2" style="font-size:.84rem;">
      <i class="bi bi-exclamation-circle-fill flex-shrink-0"></i>
      <?= e($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="" novalidate>
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

      <div class="mb-3">
        <label class="form-label" for="emailInput">Email Address</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-envelope"></i></span>
          <input type="email" id="emailInput" name="email" class="form-control"
                 placeholder="admin@lms.com"
                 value="<?= e($email) ?>" required autofocus
                 autocomplete="email">
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label" for="passwordInput">Password</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-lock"></i></span>
          <input type="password" id="passwordInput" name="password" class="form-control"
                 placeholder="••••••••" required autocomplete="current-password">
          <button class="btn btn-outline-secondary" type="button" id="togglePwd"
                  aria-label="Show/hide password">
            <i class="bi bi-eye" id="eyeIcon"></i>
          </button>
        </div>
      </div>

      <div class="d-flex justify-content-between align-items-center mb-4">
        <div class="form-check mb-0">
          <input class="form-check-input" type="checkbox" id="rememberMe" name="remember">
          <label class="form-check-label" for="rememberMe" style="font-size:.82rem;">
            Remember me
          </label>
        </div>
        <a href="<?= APP_URL ?>/auth/forgot-password.php" style="font-size:.82rem;">
          Forgot password?
        </a>
      </div>

      <button type="submit" class="btn btn-primary w-100 py-2 fw-700" style="font-size:.95rem;">
        <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
      </button>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Password show/hide
document.getElementById('togglePwd').addEventListener('click', function() {
  const inp  = document.getElementById('passwordInput');
  const icon = document.getElementById('eyeIcon');
  inp.type   = inp.type === 'password' ? 'text' : 'password';
  icon.className = inp.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
});

// Generate floating particles
(function() {
  const container = document.getElementById('particles');
  for (let i = 0; i < 45; i++) {
    const p = document.createElement('div');
    p.className = 'particle';
    const size = 1 + Math.random() * 3;
    p.style.cssText = `
      left: ${Math.random() * 100}%;
      width: ${size}px; height: ${size}px;
      animation-duration: ${5 + Math.random() * 9}s;
      animation-delay:    ${Math.random() * 6}s;
      opacity: 0;
    `;
    container.appendChild(p);
  }
})();
</script>
</body>
</html>