<?php
// auth/verify-otp.php — Step 2: Enter OTP → get reset link
require_once __DIR__ . '/../includes/auth.php';
startSession();

if (!empty($_SESSION['user_id'])) redirect(APP_URL . '/dashboard.php');

// Must arrive here via forgot-password.php
if (empty($_SESSION['otp_email']) || empty($_SESSION['otp_hash'])) {
    redirect(APP_URL . '/auth/forgot-password.php');
}

define('OTP_TTL', 60);

$email   = $_SESSION['otp_email'];
$error   = '';
$notice  = '';
$resetLink = '';

// Dev-mode notice (if mail failed)
if (!empty($_SESSION['otp_dev_notice'])) {
    $notice = $_SESSION['otp_dev_notice'];
    unset($_SESSION['otp_dev_notice']);
}

$secsLeft = max(0, (int)($_SESSION['otp_expires'] ?? 0) - time());

// ── POST: verify OTP ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'verify';

    // Resend request — go back to email step
    if ($action === 'resend') {
        unset(
            $_SESSION['otp_email'], $_SESSION['otp_hash'],
            $_SESSION['otp_expires'], $_SESSION['otp_user_id'],
            $_SESSION['otp_verified'], $_SESSION['reset_token_pending']
        );
        redirect(APP_URL . '/auth/forgot-password.php');
    }

    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please refresh and try again.';
    } else {
        // Check expiry
        if (time() > (int)($_SESSION['otp_expires'] ?? 0)) {
            $error = 'OTP has expired. Please request a new one.';
        } else {
            $entered = trim(str_replace(' ', '', $_POST['otp'] ?? ''));

            if (strlen($entered) !== 6 || !ctype_digit($entered)) {
                $error = 'Please enter the complete 6-digit OTP.';
            } elseif (!password_verify($entered, $_SESSION['otp_hash'])) {
                $error = 'Incorrect OTP. Please check and try again.';
            } else {
                // ✅ OTP correct — show reset link
                $_SESSION['otp_verified'] = true;
                $resetLink = APP_URL . '/auth/reset-password.php?token=' . ($_SESSION['reset_token_pending'] ?? '');
            }
        }
    }
}

$verified = !empty($_SESSION['otp_verified']) && !empty($_SESSION['reset_token_pending']);
if ($verified && !$resetLink) {
    $resetLink = APP_URL . '/auth/reset-password.php?token=' . $_SESSION['reset_token_pending'];
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Verify OTP — <?= APP_NAME ?></title>
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

/* OTP boxes */
.otp-inputs { display:flex; gap:.5rem; justify-content:center; margin:1.25rem 0; }
.otp-box {
  width:46px; height:54px; text-align:center;
  font-size:1.5rem; font-weight:800;
  border:2px solid var(--border); border-radius:10px;
  background:var(--surface); color:var(--text);
  transition:border-color .15s, box-shadow .15s;
  font-family:'Plus Jakarta Sans',sans-serif;
  -moz-appearance:textfield;
}
.otp-box::-webkit-outer-spin-button,
.otp-box::-webkit-inner-spin-button { -webkit-appearance:none; }
.otp-box:focus { outline:none; border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.15); }
.otp-box.filled { border-color:#2563eb; background:var(--primary-light); }
.otp-box.error  { border-color:#ef4444 !important; animation:shake .4s ease; }

@keyframes shake {
  0%,100%{ transform:translateX(0); }
  20%{ transform:translateX(-6px); } 40%{ transform:translateX(6px); }
  60%{ transform:translateX(-4px); } 80%{ transform:translateX(4px); }
}

/* Countdown ring */
.timer-wrap { display:flex; align-items:center; justify-content:center; gap:.5rem; margin-bottom:1rem; }
.ring-wrap { position:relative; width:50px; height:50px; }
.ring-wrap svg { transform:rotate(-90deg); }
.ring-track { fill:none; stroke:var(--border); stroke-width:3; }
.ring-fill  {
  fill:none; stroke:#2563eb; stroke-width:3; stroke-linecap:round;
  stroke-dasharray:132; stroke-dashoffset:0;
  transition:stroke-dashoffset 1s linear, stroke .3s;
}
.ring-text {
  position:absolute; inset:0; display:flex;
  align-items:center; justify-content:center;
  font-size:.8rem; font-weight:800; color:var(--text);
}
.timer-info { font-size:.8rem; color:var(--text-muted); }

/* Reset link box */
.reset-link-box {
  background:var(--primary-light);
  border:1.5px solid rgba(37,99,235,.25);
  border-radius:var(--radius);
  padding:1.25rem;
  text-align:center;
}
.reset-link-box .link-btn {
  display:inline-flex; align-items:center; gap:.5rem;
  background:var(--primary); color:#fff; font-weight:700;
  padding:.65rem 1.5rem; border-radius:10px;
  text-decoration:none; font-size:.9rem;
  transition:opacity .15s;
}
.reset-link-box .link-btn:hover { opacity:.9; color:#fff; }
</style>
</head>
<body class="login-page">
<div class="d-flex align-items-center justify-content-center min-vh-100 px-3 py-4">
  <div class="login-card" style="max-width:440px;">

    <div class="login-logo mb-3">
      <?= $verified ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-shield-lock"></i>' ?>
    </div>
    <h2 class="text-center fw-800 mb-1" style="font-size:1.3rem;">
      <?= $verified ? 'OTP Verified!' : 'Verify OTP' ?>
    </h2>
    <p class="text-center text-muted small mb-4">
      <?= $verified
        ? 'Click the button below to set your new password.'
        : 'Enter the 6-digit code sent to <strong>' . e($email) . '</strong>' ?>
    </p>

    <!-- Step bar -->
    <div class="step-bar mb-4">
      <div class="step-item done">
        <div class="step-num"><i class="bi bi-check2" style="font-size:.75rem;"></i></div>
        <span class="step-lbl">Email</span>
      </div>
      <div class="step-item <?= $verified ? 'done' : 'active' ?>">
        <div class="step-num">
          <?php if ($verified): ?><i class="bi bi-check2" style="font-size:.75rem;"></i><?php else: ?>2<?php endif; ?>
        </div>
        <span class="step-lbl">OTP</span>
      </div>
      <div class="step-item <?= $verified ? 'active' : '' ?>">
        <div class="step-num">3</div>
        <span class="step-lbl">Password</span>
      </div>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger py-2 small d-flex align-items-center gap-2 mb-3">
      <i class="bi bi-exclamation-circle-fill flex-shrink-0"></i><?= e($error) ?>
    </div>
    <?php endif; ?>

    <?php if ($notice): ?>
    <div class="alert alert-warning py-2 small mb-3">
      <i class="bi bi-info-circle-fill me-2"></i><?= $notice ?>
    </div>
    <?php endif; ?>

    <?php if ($verified): ?>
    <!-- ── VERIFIED: show reset link ─────────────────────────── -->
    <div class="reset-link-box mb-4">
      <div class="text-success fw-700 mb-2">
        <i class="bi bi-check-circle-fill me-2"></i>Identity confirmed
      </div>
      <p class="text-muted small mb-3">
        Your OTP was verified. Click below to create your new password.
      </p>
      <a href="<?= e($resetLink) ?>" class="link-btn">
        <i class="bi bi-key-fill"></i>Reset My Password
      </a>
    </div>

    <?php else: ?>
    <!-- ── OTP ENTRY FORM ─────────────────────────────────────── -->

    <!-- Countdown timer -->
    <div class="timer-wrap" id="timerWrap">
      <div class="ring-wrap">
        <svg viewBox="0 0 44 44" width="50" height="50">
          <circle class="ring-track" cx="22" cy="22" r="21"/>
          <circle class="ring-fill"  cx="22" cy="22" r="21" id="ringFill"/>
        </svg>
        <div class="ring-text" id="ringText">1:00</div>
      </div>
      <div class="timer-info">
        <div id="timerLbl">OTP expires in</div>
        <div id="expiredMsg" class="text-danger fw-600 small" style="display:none;">OTP expired</div>
      </div>
    </div>

    <form method="POST" id="otpForm">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action"     value="verify">
      <input type="hidden" name="otp"        id="otpHidden">

      <div class="otp-inputs" id="otpInputs">
        <?php for ($i = 0; $i < 6; $i++): ?>
        <input type="number" class="otp-box" id="otp<?= $i ?>"
               maxlength="1" min="0" max="9"
               inputmode="numeric" autocomplete="one-time-code"
               placeholder="·">
        <?php endfor; ?>
      </div>

      <button type="submit" class="btn btn-primary w-100 fw-700 py-2 mb-3" id="verifyBtn">
        <i class="bi bi-shield-check me-2"></i>Verify OTP
      </button>
    </form>

    <!-- Resend -->
    <form method="POST" class="text-center">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action"     value="resend">
      <button type="submit" class="btn btn-link btn-sm text-muted p-0" style="font-size:.8rem;">
        <i class="bi bi-arrow-clockwise me-1"></i>Didn't receive it? Request new OTP
      </button>
    </form>
    <?php endif; ?>

    <div class="text-center mt-4">
      <a href="<?= APP_URL ?>/login.php" class="small text-muted text-decoration-none">
        <i class="bi bi-arrow-left me-1"></i>Back to Login
      </a>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php if (!$verified): ?>
<script>
// ── OTP boxes ────────────────────────────────────────────────
const boxes  = Array.from(document.querySelectorAll('.otp-box'));
const hidden = document.getElementById('otpHidden');

function syncHidden() {
  hidden.value = boxes.map(b => b.value).join('');
  boxes.forEach(b => b.classList.toggle('filled', b.value !== ''));
}

boxes.forEach((box, i) => {
  box.addEventListener('input', function() {
    this.value = this.value.slice(-1).replace(/\D/,'');
    syncHidden();
    if (this.value && i < 5) boxes[i+1].focus();
  });
  box.addEventListener('keydown', function(e) {
    if (e.key === 'Backspace' && !this.value && i > 0) {
      boxes[i-1].value = '';
      boxes[i-1].focus();
      syncHidden();
    }
  });
  box.addEventListener('focus', function() { this.select(); });
});

// Paste support (from email/SMS)
document.getElementById('otpInputs').addEventListener('paste', function(e) {
  e.preventDefault();
  const pasted = (e.clipboardData || window.clipboardData)
                   .getData('text').replace(/\D/g,'').slice(0,6);
  pasted.split('').forEach((ch, i) => { if (boxes[i]) boxes[i].value = ch; });
  syncHidden();
  (boxes.find(b => !b.value) || boxes[5]).focus();
});

// Form submit validation
document.getElementById('otpForm').addEventListener('submit', function(e) {
  syncHidden();
  if (hidden.value.length !== 6 || !/^\d{6}$/.test(hidden.value)) {
    e.preventDefault();
    boxes.forEach(b => b.classList.add('error'));
    setTimeout(() => boxes.forEach(b => b.classList.remove('error')), 600);
    return;
  }
  const btn = document.getElementById('verifyBtn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Verifying…';
});

// Auto-focus first box
boxes[0].focus();

// ── Countdown timer ──────────────────────────────────────────
const TOTAL    = <?= OTP_TTL ?>;
const CIRC     = 2 * Math.PI * 21; // ≈ 131.9
let remaining  = <?= $secsLeft ?>;
const ringFill = document.getElementById('ringFill');
const ringText = document.getElementById('ringText');
const timerLbl = document.getElementById('timerLbl');
const expMsg   = document.getElementById('expiredMsg');
const verifyBtn= document.getElementById('verifyBtn');

ringFill.style.strokeDasharray = CIRC;

function tick() {
  if (remaining <= 0) {
    ringFill.style.strokeDashoffset = CIRC;
    ringFill.style.stroke = '#ef4444';
    ringText.textContent = '0:00';
    ringText.style.color = '#ef4444';
    timerLbl.style.display = 'none';
    expMsg.style.display   = 'block';
    verifyBtn.disabled     = true;
    return;
  }
  const mins = Math.floor(remaining / 60);
  const secs = remaining % 60;
  ringText.textContent = mins + ':' + String(secs).padStart(2,'0');

  const pct    = remaining / TOTAL;
  const offset = CIRC * (1 - pct);
  ringFill.style.strokeDashoffset = offset;

  if (pct > 0.5)       ringFill.style.stroke = '#22c55e';
  else if (pct > 0.2)  ringFill.style.stroke = '#f59e0b';
  else                 ringFill.style.stroke = '#ef4444';

  remaining--;
  setTimeout(tick, 1000);
}
tick();
</script>
<?php endif; ?>
</body>
</html>