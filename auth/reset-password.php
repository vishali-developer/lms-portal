<?php
// auth/reset-password.php — Step 3: Set new password via token
require_once __DIR__ . '/../includes/auth.php';
startSession();

if (!empty($_SESSION['user_id'])) redirect(APP_URL . '/dashboard.php');

$token   = trim($_GET['token'] ?? '');
$error   = '';
$success = false;

// Validate token — must match DB and not be expired
$user = $token
    ? DB::fetchOne(
        "SELECT id, name FROM users
         WHERE reset_token = ? AND reset_expires > NOW() AND status = 'active'",
        [$token])
    : null;

// Also require that the OTP was actually verified in this session
$otpVerified = !empty($_SESSION['otp_verified'])
            && !empty($_SESSION['reset_token_pending'])
            && $_SESSION['reset_token_pending'] === $token;

if (($user && !$otpVerified)) {
    // Token is valid but the session OTP step was skipped — bounce to verify
    redirect(APP_URL . '/auth/verify-otp.php');
}

if (!$user && $token) {
    $error = 'This reset link is invalid or has expired. ' .
             '<a href="' . APP_URL . '/auth/forgot-password.php">Request a new one</a>.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user && $otpVerified) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request.';
    } else {
        $pw  = $_POST['password']         ?? '';
        $pw2 = $_POST['confirm_password'] ?? '';
        if (strlen($pw) < 6) {
            $error = 'Password must be at least 6 characters.';
        } elseif ($pw !== $pw2) {
            $error = 'Passwords do not match.';
        } else {
            DB::query(
                "UPDATE users SET password=?, reset_token=NULL, reset_expires=NULL WHERE id=?",
                [hashPassword($pw), $user['id']]
            );
            logActivity($user['id'], 'Password Reset', 'Password changed via OTP reset');

            // Clear all OTP session data
            unset(
                $_SESSION['otp_email'], $_SESSION['otp_hash'],
                $_SESSION['otp_expires'], $_SESSION['otp_user_id'],
                $_SESSION['otp_verified'], $_SESSION['reset_token_pending']
            );

            $success = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reset Password — <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css">
<style>
body { background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 50%,#0f172a 100%); font-family:'Plus Jakarta Sans',sans-serif; }

/* Step bar */
.step-bar { display:flex; align-items:center; gap:0; margin-bottom:1.75rem; }
.step-item { display:flex; flex-direction:column; align-items:center; flex:1; position:relative; }
.step-item:not(:last-child)::after {
  content:''; position:absolute; top:14px; left:50%; right:-50%;
  height:2px; background:var(--border); z-index:0;
}
.step-item.done:not(:last-child)::after { background:#2563eb; }
.step-num {
  width:28px; height:28px; border-radius:50%;
  display:grid; place-items:center; font-size:.72rem; font-weight:700;
  border:2px solid var(--border); background:var(--surface); color:var(--text-muted);
  position:relative; z-index:1;
}
.step-item.active .step-num { border-color:#2563eb; background:#2563eb; color:#fff; }
.step-item.done   .step-num { border-color:#10b981; background:#10b981; color:#fff; }
.step-lbl { font-size:.65rem; font-weight:600; color:var(--text-muted); margin-top:4px; }
.step-item.active .step-lbl { color:#2563eb; }
.step-item.done   .step-lbl { color:#10b981; }

/* Password eye toggle */
.pwd-wrap { position:relative; }
.pwd-wrap .form-control { padding-right:2.6rem; }
.pwd-eye {
  position:absolute; right:0; top:0; bottom:0; width:2.6rem;
  display:flex; align-items:center; justify-content:center;
  background:none; border:none; color:var(--text-muted);
  cursor:pointer; font-size:.95rem; transition:color .15s;
  border-radius:0 8px 8px 0;
}
.pwd-eye:hover { color:var(--primary); }

/* Strength bar */
.str-bar { height:4px; border-radius:2px; background:var(--border); overflow:hidden; margin-top:.35rem; }
.str-fill { height:100%; border-radius:2px; transition:width .3s,background .3s; }

/* Match indicator */
.match-ok  { color:#22c55e; font-size:.68rem; }
.match-err { color:#ef4444; font-size:.68rem; }
</style>
</head>
<body class="login-page">
<div class="d-flex align-items-center justify-content-center min-vh-100 px-3 py-4">
  <div class="login-card" style="max-width:420px;">

    <div class="login-logo mb-3">
      <?= $success ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-key-fill text-primary"></i>' ?>
    </div>
    <h2 class="text-center fw-800 mb-1" style="font-size:1.3rem;">
      <?= $success ? 'Password Changed!' : 'Set New Password' ?>
    </h2>
    <p class="text-center text-muted small mb-4">
      <?= $success ? 'You can now log in with your new password.' : 'Choose a strong new password.' ?>
    </p>

    <!-- Step bar -->
    <div class="step-bar mb-4">
      <div class="step-item done">
        <div class="step-num"><i class="bi bi-check2" style="font-size:.75rem;"></i></div>
        <span class="step-lbl">Email</span>
      </div>
      <div class="step-item done">
        <div class="step-num"><i class="bi bi-check2" style="font-size:.75rem;"></i></div>
        <span class="step-lbl">OTP</span>
      </div>
      <div class="step-item <?= $success ? 'done' : 'active' ?>">
        <div class="step-num">
          <?php if ($success): ?><i class="bi bi-check2" style="font-size:.75rem;"></i><?php else: ?>3<?php endif; ?>
        </div>
        <span class="step-lbl">Password</span>
      </div>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger small"><?= $error ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
    <!-- ── SUCCESS ──────────────────────────────────────────── -->
    <div class="alert alert-success text-center mb-4">
      <i class="bi bi-check-circle-fill d-block" style="font-size:2rem;margin-bottom:.5rem;color:#22c55e;"></i>
      <strong>All done!</strong> Your password has been updated.
    </div>
    <a href="<?= APP_URL ?>/login.php" class="btn btn-primary w-100 fw-700 py-2">
      <i class="bi bi-box-arrow-in-right me-2"></i>Go to Login
    </a>

    <?php elseif ($user && $otpVerified): ?>
    <!-- ── PASSWORD FORM ────────────────────────────────────── -->
    <form method="POST" id="resetForm">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

      <div class="mb-3">
        <label class="form-label">New Password *</label>
        <div class="pwd-wrap">
          <input type="password" id="pwdNew" name="password" class="form-control"
                 placeholder="Min 6 characters" required autofocus>
          <button type="button" class="pwd-eye" onclick="togglePwd('pwdNew','eyeNew')" tabindex="-1">
            <i class="bi bi-eye" id="eyeNew"></i>
          </button>
        </div>
        <!-- Strength bar -->
        <div class="str-bar mt-2">
          <div class="str-fill" id="strFill" style="width:0%;"></div>
        </div>
        <div id="strLabel" style="font-size:.67rem;margin-top:3px;"></div>
      </div>

      <div class="mb-4">
        <label class="form-label">Confirm New Password *</label>
        <div class="pwd-wrap">
          <input type="password" id="pwdConfirm" name="confirm_password" class="form-control"
                 placeholder="Repeat password" required>
          <button type="button" class="pwd-eye" onclick="togglePwd('pwdConfirm','eyeConf')" tabindex="-1">
            <i class="bi bi-eye" id="eyeConf"></i>
          </button>
        </div>
        <div id="matchLabel" class="mt-1"></div>
      </div>

      <button type="submit" class="btn btn-success w-100 fw-700 py-2" id="resetBtn">
        <i class="bi bi-check-circle me-2"></i>Change Password
      </button>
    </form>

    <?php elseif (!$token): ?>
    <div class="alert alert-warning small">No reset token provided.</div>
    <?php endif; ?>

    <div class="text-center mt-4">
      <a href="<?= APP_URL ?>/login.php" class="small text-muted text-decoration-none">
        <i class="bi bi-arrow-left me-1"></i>Back to Login
      </a>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php if ($user && $otpVerified && !$success): ?>
<script>
function togglePwd(inputId, iconId) {
  const inp  = document.getElementById(inputId);
  const icon = document.getElementById(iconId);
  inp.type   = inp.type === 'password' ? 'text' : 'password';
  icon.className = inp.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
  inp.focus();
}

// Strength meter
document.getElementById('pwdNew').addEventListener('input', function() {
  const v = this.value;
  let score = 0;
  if (v.length >= 6)   score++;
  if (v.length >= 10)  score++;
  if (/[A-Z]/.test(v)) score++;
  if (/[0-9]/.test(v)) score++;
  if (/[^A-Za-z0-9]/.test(v)) score++;

  const levels = [
    {w:'0%',  c:'transparent',l:''},
    {w:'20%', c:'#ef4444',    l:'Very Weak'},
    {w:'40%', c:'#f97316',    l:'Weak'},
    {w:'60%', c:'#f59e0b',    l:'Fair'},
    {w:'80%', c:'#22c55e',    l:'Strong'},
    {w:'100%',c:'#10b981',    l:'Very Strong'},
  ];
  const lv = v.length === 0 ? levels[0] : levels[Math.max(1, Math.min(score, 5))];
  const fill  = document.getElementById('strFill');
  const label = document.getElementById('strLabel');
  fill.style.width      = lv.w;
  fill.style.background = lv.c;
  label.textContent     = lv.l;
  label.style.color     = lv.c;
  checkMatch();
});

// Match checker
function checkMatch() {
  const p1  = document.getElementById('pwdNew').value;
  const p2  = document.getElementById('pwdConfirm').value;
  const lbl = document.getElementById('matchLabel');
  if (!p2) { lbl.textContent = ''; return; }
  if (p1 === p2) {
    lbl.innerHTML = '<span class="match-ok"><i class="bi bi-check2 me-1"></i>Passwords match</span>';
  } else {
    lbl.innerHTML = '<span class="match-err"><i class="bi bi-x-lg me-1"></i>Passwords do not match</span>';
  }
}
document.getElementById('pwdConfirm').addEventListener('input', checkMatch);

// Submit guard
document.getElementById('resetForm').addEventListener('submit', function(e) {
  const p1 = document.getElementById('pwdNew').value;
  const p2 = document.getElementById('pwdConfirm').value;
  if (p1.length < 6) { e.preventDefault(); alert('Password must be at least 6 characters.'); return; }
  if (p1 !== p2)     { e.preventDefault(); alert('Passwords do not match.'); return; }
  const btn = document.getElementById('resetBtn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Updating…';
});
</script>
<?php endif; ?>
</body>
</html>
