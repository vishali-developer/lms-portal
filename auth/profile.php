<?php
// auth/profile.php — My Profile: edit info, change password, activity log
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$pageTitle  = 'My Profile';
$activePage = 'profile';

$user   = currentUser();
$errors = [];

/* ── Update profile info ─────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'CSRF token mismatch.';
    } else {
        $name  = trim($_POST['name']  ?? '');
        $phone = trim($_POST['phone'] ?? '');
        if (!$name) $errors[] = 'Full name is required.';

        // Avatar upload
        $avatarFile = null;
        if (!empty($_FILES['avatar']['name'])) {
            $ext     = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','gif','webp'];
            $maxSize = 2 * 1024 * 1024; // 2MB
            if (!in_array($ext, $allowed)) {
                $errors[] = 'Image must be JPG, PNG, GIF or WebP.';
            } elseif ($_FILES['avatar']['size'] > $maxSize) {
                $errors[] = 'Image must be under 2 MB.';
            } elseif ($_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'Upload error. Check server configuration.';
            } else {
                $avatarFile = 'avatar_' . $user['id'] . '_' . time() . '.' . $ext;
                if (!move_uploaded_file($_FILES['avatar']['tmp_name'], UPLOAD_PATH . $avatarFile)) {
                    $errors[] = 'Could not save image. Check uploads/ folder permissions.';
                    $avatarFile = null;
                }
            }
        }

        if (!$errors) {
            if ($avatarFile) {
                // Remove old avatar
                if (!empty($user['profile_image'])) {
                    @unlink(UPLOAD_PATH . $user['profile_image']);
                }
                DB::query(
                    "UPDATE users SET name=?, phone=?, profile_image=? WHERE id=?",
                    [$name, $phone, $avatarFile, $user['id']]
                );
            } else {
                DB::query(
                    "UPDATE users SET name=?, phone=? WHERE id=?",
                    [$name, $phone, $user['id']]
                );
            }
            $_SESSION['user_name'] = $name;
            logActivity($user['id'], 'Profile Updated', 'Profile information updated');
            setFlash('success', 'Profile updated successfully!');
            redirect(APP_URL . '/auth/profile.php');
        }
    }
}

/* ── Change password ─────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'CSRF error.';
    } else {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!password_verify($current, $user['password'])) {
            $errors[] = 'Current password is incorrect.';
        } elseif (strlen($new) < 6) {
            $errors[] = 'New password must be at least 6 characters.';
        } elseif ($new !== $confirm) {
            $errors[] = 'New passwords do not match.';
        } else {
            DB::query("UPDATE users SET password=? WHERE id=?",
                      [hashPassword($new), $user['id']]);
            logActivity($user['id'], 'Password Changed', 'Password updated via profile');
            setFlash('success', 'Password changed successfully!');
            redirect(APP_URL . '/auth/profile.php');
        }
    }
}

// Re-fetch after any update
$user = currentUser();

// Stats (for employees)
$myStats = null;
if ($_SESSION['user_role'] === 'employee') {
    $uid = $user['id'];
    $myStats = [
        'total'      => DB::fetchOne("SELECT COUNT(*) c FROM leads WHERE assigned_to=?", [$uid])['c'],
        'converted'  => DB::fetchOne("SELECT COUNT(*) c FROM leads WHERE assigned_to=? AND status='Converted'", [$uid])['c'],
        'followups'  => DB::fetchOne("SELECT COUNT(*) c FROM followups WHERE employee_id=?", [$uid])['c'],
        'pending'    => DB::fetchOne("SELECT COUNT(*) c FROM leads WHERE assigned_to=? AND status IN ('New','Contacted','Follow-up')", [$uid])['c'],
    ];
}

// Activity log
$logs = DB::fetchAll(
    "SELECT * FROM activity_logs WHERE user_id=? ORDER BY created_at DESC LIMIT 20",
    [$user['id']]
);

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ── Password show/hide toggle ─────────────────────────── */
.pwd-wrap { position: relative; }
.pwd-wrap .form-control { padding-right: 2.6rem; }
.pwd-eye {
  position: absolute; right: 0; top: 0; bottom: 0;
  width: 2.6rem;
  display: flex; align-items: center; justify-content: center;
  background: none; border: none;
  color: var(--text-muted); cursor: pointer;
  font-size: .95rem; border-radius: 0 8px 8px 0;
  transition: color .15s;
}
.pwd-eye:hover { color: var(--primary); }
</style>

<div class="row g-3">
  <!-- Left: Avatar + info card -->
  <div class="col-12 col-xl-3">
    <div class="card text-center mb-3">
      <div class="card-body py-4">
        <div class="position-relative d-inline-block mb-3">
          <?php if (!empty($user['profile_image'])): ?>
            <img src="<?= UPLOAD_URL . e($user['profile_image']) ?>"
                 class="rounded-circle border shadow-sm"
                 width="96" height="96" style="object-fit:cover;" alt="Avatar">
          <?php else: ?>
            <div class="mx-auto rounded-circle bg-primary text-white fw-800 d-flex align-items-center justify-content-center"
                 style="width:96px;height:96px;font-size:2.2rem;">
              <?= strtoupper(substr($user['name'], 0, 1)) ?>
            </div>
          <?php endif; ?>
          <span class="position-absolute bottom-0 end-0 bg-success border border-2 border-white rounded-circle"
                style="width:14px;height:14px;"></span>
        </div>
        <h5 class="fw-700 mb-1"><?= e($user['name']) ?></h5>
        <span class="role-badge role-<?= e($user['role']) ?>"><?= ucfirst($user['role']) ?></span>
        <div class="mt-3 text-muted" style="font-size:.82rem;">
          <div class="mb-1"><i class="bi bi-envelope me-2"></i><?= e($user['email']) ?></div>
          <?php if ($user['phone']): ?>
          <div class="mb-1"><i class="bi bi-telephone me-2"></i><?= e($user['phone']) ?></div>
          <?php endif; ?>
          <div class="mb-1">
            <i class="bi bi-calendar-plus me-2"></i>
            Joined <?= date('d M Y', strtotime($user['created_at'])) ?>
          </div>
          <?php if ($user['last_login']): ?>
          <div>
            <i class="bi bi-clock-history me-2"></i>
            Last login <?= date('d M y, h:i A', strtotime($user['last_login'])) ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if ($myStats): ?>
    <div class="card">
      <div class="card-header"><i class="bi bi-bar-chart me-2 text-primary"></i>My Stats</div>
      <div class="card-body p-0">
        <?php $statsMap = [
          ['label'=>'Assigned Leads', 'val'=>$myStats['total'],     'color'=>'primary'],
          ['label'=>'Converted',      'val'=>$myStats['converted'], 'color'=>'success'],
          ['label'=>'Pending',        'val'=>$myStats['pending'],   'color'=>'warning'],
          ['label'=>'Follow-ups Done','val'=>$myStats['followups'], 'color'=>'info'],
        ]; ?>
        <?php foreach ($statsMap as $i => $s): ?>
        <div class="d-flex justify-content-between align-items-center px-3 py-2
                    <?= $i < count($statsMap)-1 ? 'border-bottom' : '' ?>">
          <span class="small text-muted"><?= $s['label'] ?></span>
          <span class="badge bg-<?= $s['color'] ?>"><?= $s['val'] ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Right: Forms + activity -->
  <div class="col-12 col-xl-9">

    <?php foreach ($errors as $err): ?>
    <div class="alert alert-danger py-2 small d-flex align-items-center gap-2">
      <i class="bi bi-exclamation-circle-fill flex-shrink-0"></i><?= e($err) ?>
    </div>
    <?php endforeach; ?>

    <!-- Edit Profile -->
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-pencil me-2 text-primary"></i>Edit Profile</div>
      <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token"      value="<?= csrfToken() ?>">
          <input type="hidden" name="update_profile"  value="1">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Full Name *</label>
              <input type="text" name="name" class="form-control"
                     value="<?= e($user['name']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Phone</label>
              <input type="tel" name="phone" class="form-control"
                     value="<?= e($user['phone'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Email <small class="text-muted">(read-only)</small></label>
              <input type="email" class="form-control" value="<?= e($user['email']) ?>" disabled>
            </div>
            <div class="col-md-6">
              <label class="form-label">Profile Picture</label>
              <input type="file" name="avatar" class="form-control" accept="image/*">
              <div class="form-text">Max 2 MB. JPG, PNG, GIF, WebP accepted.</div>
            </div>
            <div class="col-12 text-end">
              <button type="submit" class="btn btn-primary fw-600">
                <i class="bi bi-save me-1"></i>Save Profile
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>

    <!-- Change Password -->
    <div class="card mb-3" id="change-password">
      <div class="card-header"><i class="bi bi-shield-lock me-2 text-primary"></i>Change Password</div>
      <div class="card-body">
        <form method="POST">
          <input type="hidden" name="csrf_token"     value="<?= csrfToken() ?>">
          <input type="hidden" name="change_password" value="1">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Current Password *</label>
              <div class="pwd-wrap">
                <input type="password" id="pwdCurrent" name="current_password" class="form-control"
                       placeholder="Your current password" required>
                <button type="button" class="pwd-eye"
                        onclick="togglePwd('pwdCurrent','eyeCurrent')"
                        tabindex="-1" aria-label="Toggle">
                  <i class="bi bi-eye" id="eyeCurrent"></i>
                </button>
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label">New Password *</label>
              <div class="pwd-wrap">
                <input type="password" id="pwdNew" name="new_password" class="form-control"
                       placeholder="Min 6 characters" required>
                <button type="button" class="pwd-eye"
                        onclick="togglePwd('pwdNew','eyeNew')"
                        tabindex="-1" aria-label="Toggle">
                  <i class="bi bi-eye" id="eyeNew"></i>
                </button>
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label">Confirm New Password *</label>
              <div class="pwd-wrap">
                <input type="password" id="pwdConfirm" name="confirm_password" class="form-control"
                       placeholder="Repeat new password" required>
                <button type="button" class="pwd-eye"
                        onclick="togglePwd('pwdConfirm','eyeConfirm')"
                        tabindex="-1" aria-label="Toggle">
                  <i class="bi bi-eye" id="eyeConfirm"></i>
                </button>
              </div>
            </div>
            <div class="col-12 text-end">
              <button type="submit" class="btn btn-warning fw-600">
                <i class="bi bi-key me-1"></i>Update Password
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>

    <!-- Recent Activity Log -->
    <div class="card">
      <div class="card-header"><i class="bi bi-activity me-2 text-primary"></i>Recent Activity</div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover table-sm mb-0">
            <thead>
              <tr><th>Action</th><th>Description</th><th>IP</th><th>When</th></tr>
            </thead>
            <tbody>
              <?php foreach ($logs as $log): ?>
              <tr>
                <td class="fw-600 small"><?= e($log['action']) ?></td>
                <td class="small text-muted text-truncate-1" style="max-width:240px;">
                  <?= e($log['description']) ?>
                </td>
                <td><code style="font-size:.73rem;"><?= e($log['ip_address'] ?? '—') ?></code></td>
                <td class="small text-muted" style="white-space:nowrap;">
                  <?= fmtDate($log['created_at'], 'd M y, h:i A') ?>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if (!$logs): ?>
              <tr>
                <td colspan="4" class="text-center text-muted py-3 small">
                  No activity recorded yet.
                </td>
              </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div>
</div>

<script>
function togglePwd(inputId, iconId) {
  const inp  = document.getElementById(inputId);
  const icon = document.getElementById(iconId);
  if (!inp) return;
  if (inp.type === 'password') {
    inp.type = 'text';
    icon.className = 'bi bi-eye-slash';
  } else {
    inp.type = 'password';
    icon.className = 'bi bi-eye';
  }
  inp.focus();
}

// Password strength indicator on "New Password" field
const pwdNewInput = document.getElementById('pwdNew');
if (pwdNewInput) {
  // Insert strength bar after the pwd-wrap div
  const wrap = pwdNewInput.closest('.pwd-wrap');
  const bar = document.createElement('div');
  bar.innerHTML = `
    <div style="margin-top:.35rem;">
      <div style="height:4px;border-radius:2px;background:var(--border);overflow:hidden;">
        <div id="strengthBar" style="height:100%;border-radius:2px;width:0%;transition:width .3s,background .3s;"></div>
      </div>
      <div id="strengthLabel" style="font-size:.68rem;color:var(--text-muted);margin-top:3px;"></div>
    </div>`;
  wrap.parentNode.insertBefore(bar, wrap.nextSibling);

  pwdNewInput.addEventListener('input', function() {
    const val = this.value;
    let score = 0;
    if (val.length >= 6)  score++;
    if (val.length >= 10) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;

    const levels = [
      { label: '', color: 'transparent', w: '0%' },
      { label: 'Very Weak',  color: '#ef4444', w: '20%' },
      { label: 'Weak',       color: '#f97316', w: '40%' },
      { label: 'Fair',       color: '#f59e0b', w: '60%' },
      { label: 'Strong',     color: '#22c55e', w: '80%' },
      { label: 'Very Strong',color: '#10b981', w: '100%' },
    ];
    const lv = val.length === 0 ? levels[0] : levels[Math.max(1, Math.min(score, 5))];
    document.getElementById('strengthBar').style.width      = lv.w;
    document.getElementById('strengthBar').style.background = lv.color;
    document.getElementById('strengthLabel').textContent    = lv.label;
    document.getElementById('strengthLabel').style.color    = lv.color;
  });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>