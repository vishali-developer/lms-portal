<?php

// auth/forgot-password.php — Step 1: Enter email → OTP sent
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';
startSession();

if (!empty($_SESSION['user_id'])) redirect(APP_URL . '/dashboard.php');

// OTP TTL = 60 seconds
define('OTP_TTL', 60);

$error  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please refresh and try again.';
    } else {
        $email = strtolower(trim($_POST['email'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            $user = DB::fetchOne(
                "SELECT id, name, email FROM users WHERE email = ? AND status = 'active'",
                [$email]
            );

            // Always clear any stale OTP session first
            unset(
                $_SESSION['otp_email'], $_SESSION['otp_hash'],
                $_SESSION['otp_expires'], $_SESSION['otp_user_id'],
                $_SESSION['otp_verified'], $_SESSION['reset_token_pending']
            );

            if ($user) {
                // Generate 6-digit OTP
                $otp     = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $expires = time() + OTP_TTL;

                // Store hashed OTP in session — never plain text
                $_SESSION['otp_email']   = $email;
                $_SESSION['otp_hash']    = password_hash($otp, PASSWORD_BCRYPT, ['cost' => 10]);
                $_SESSION['otp_expires'] = $expires;
                $_SESSION['otp_user_id'] = $user['id'];

                // Also write a reset token to DB (used after OTP verified)
                $resetToken = bin2hex(random_bytes(32));
                DB::query(
                    "UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?",
                    [$resetToken, date('Y-m-d H:i:s', $expires + 3600), $user['id']]
                );
                $_SESSION['reset_token_pending'] = $resetToken;

                // Send OTP email
                $body = '
                <p>Hi <strong>' . htmlspecialchars($user['name']) . '</strong>,</p>
                <p>You requested a password reset for your <strong>' . APP_NAME . '</strong> account.</p>
                <p>Your One-Time Password (OTP) is:</p>
                <div style="text-align:center;margin:28px 0;">
                  <div style="display:inline-block;background:#eff6ff;border:2px dashed #2563eb;
                              border-radius:12px;padding:18px 48px;">
                    <span style="font-size:2.6rem;font-weight:800;letter-spacing:12px;
                                 color:#2563eb;font-family:monospace;">' . $otp . '</span>
                  </div>
                </div>
                <p style="text-align:center;color:#ef4444;font-weight:600;">
                  ⏳ This OTP expires in <strong>60 seconds</strong>
                </p>
                <p style="color:#94a3b8;font-size:.83rem;">
                  If you did not request a password reset, ignore this email. Your account is safe.
                </p>';

                $result = sendMail(
                    $user['email'],
                    'Password Reset OTP — ' . APP_NAME,
                    $body,
                    $user['name']
                );

                if ($result !== true) {
                    // Log the real error so admin can diagnose in php_error.log
                    error_log('OTP email FAILED for ' . $email . ' — ' . $result);
                    // Dev-only: write OTP to error_log too (never to screen in production)
                    error_log('DEV OTP for ' . $email . ': ' . $otp);
                }

                logActivity($user['id'], 'Password Reset OTP Sent', 'OTP sent to ' . $email);
            } else {
                // Fake session so attacker can't enumerate emails
                $_SESSION['otp_email']   = $email;
                $_SESSION['otp_hash']    = password_hash('000000', PASSWORD_BCRYPT, ['cost' => 10]);
                $_SESSION['otp_expires'] = time() + OTP_TTL;
                $_SESSION['otp_user_id'] = 0;
            }

            // Always redirect — never reveal on this page whether the email exists
            redirect(APP_URL . '/auth/verify-otp.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Forgot Password — <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css">
<style>
body { background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 50%,#0f172a 100%); font-family:'Plus Jakarta Sans',sans-serif; }
.step-bar { display:flex; align-items:center; gap:0; margin-bottom:1.75rem; }
.step-item { display:flex; flex-direction:column; align-items:center; flex:1; position:relative; }
.step-item:not(:last-child)::after {
  content:''; position:absolute; top:14px; left:50%; right:-50%;
  height:2px; background:var(--border); z-index:0;
}
.step-num {
  width:28px; height:28px; border-radius:50%;
  display:grid; place-items:center; font-size:.72rem; font-weight:700;
  border:2px solid var(--border); background:var(--surface); color:var(--text-muted);
  position:relative; z-index:1;
}
.step-item.active .step-num { border-color:#2563eb; background:#2563eb; color:#fff; }
.step-lbl { font-size:.65rem; font-weight:600; color:var(--text-muted); margin-top:4px; }
.step-item.active .step-lbl { color:#2563eb; }
</style>
</head>
<body class="login-page">
<div class="d-flex align-items-center justify-content-center min-vh-100 px-3 py-4">
  <div class="login-card" style="max-width:420px;">

    <div class="login-logo mb-3"><i class="bi bi-shield-lock"></i></div>
    <h2 class="text-center fw-800 mb-1" style="font-size:1.3rem;">Forgot Password</h2>
    <p class="text-center text-muted small mb-4">Enter your email to receive a one-time password.</p>

    <!-- Step bar -->
    <div class="step-bar mb-4">
      <div class="step-item active"><div class="step-num">1</div><span class="step-lbl">Email</span></div>
      <div class="step-item"><div class="step-num">2</div><span class="step-lbl">OTP</span></div>
      <div class="step-item"><div class="step-num">3</div><span class="step-lbl">Password</span></div>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger py-2 small d-flex align-items-center gap-2 mb-3">
      <i class="bi bi-exclamation-circle-fill flex-shrink-0"></i><?= e($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" id="emailForm">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <div class="mb-4">
        <label class="form-label">Email Address</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-envelope"></i></span>
          <input type="email" name="email" class="form-control"
                 placeholder="you@company.com" required autofocus>
        </div>
        <div class="form-text">We'll send a 6-digit OTP to this address.</div>
      </div>
      <button type="submit" class="btn btn-primary w-100 fw-700 py-2" id="sendBtn">
        <i class="bi bi-send me-2"></i>Send OTP
      </button>
    </form>

    <div class="text-center mt-4">
      <a href="<?= APP_URL ?>/login.php" class="small text-muted text-decoration-none">
        <i class="bi bi-arrow-left me-1"></i>Back to Login
      </a>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('emailForm').addEventListener('submit', function() {
  const btn = document.getElementById('sendBtn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Sending OTP…';
});
</script>
</body>
</html>