<?php
// admin/user-form.php — Add / Edit User (Admin & Manager)
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin','manager']);

$selfRole = $_SESSION['user_role'];
$selfId   = $_SESSION['user_id'];

$id   = (int)($_GET['id'] ?? 0);
$user = $id ? DB::fetchOne("SELECT * FROM users WHERE id=?", [$id]) : null;

// Managers can only create/edit employees
if ($user) {
    if ($selfRole === 'manager' && $user['role'] !== 'employee') {
        setFlash('danger', 'Managers can only edit employee accounts.');
        redirect(APP_URL . '/admin/users.php');
    }
    // Nobody can edit themselves via this page (use profile.php instead)
    if ($user['id'] === $selfId) {
        setFlash('danger', 'Edit your own account via My Profile.');
        redirect(APP_URL . '/auth/profile.php');
    }
}

$isEdit = (bool)$user;
$pageTitle  = $isEdit ? 'Edit User' : 'Add User';
$activePage = 'users';

// Role options based on who is logged in
$roleOptions = $selfRole === 'admin'
    ? ['admin' => 'Admin', 'manager' => 'Manager', 'employee' => 'Employee']
    : ['employee' => 'Employee']; // Managers can only create employees

$errors = [];
$data   = $user ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'CSRF token error.';
    } else {
        $data = [
            'name'   => trim($_POST['name']   ?? ''),
            'email'  => strtolower(trim($_POST['email']  ?? '')),
            'phone'  => trim($_POST['phone']  ?? ''),
            'role'   => $_POST['role']   ?? '',
            'status' => $_POST['status'] ?? 'active',
        ];
        $pw  = $_POST['password']         ?? '';
        $pw2 = $_POST['confirm_password'] ?? '';

        // Validations
        if (!$data['name'])  $errors[] = 'Full name is required.';
        if (!$data['email']) $errors[] = 'Email is required.';
        if ($data['email'] && !filter_var($data['email'], FILTER_VALIDATE_EMAIL))
            $errors[] = 'Invalid email address.';

        // Role must be within what this user is allowed to set
        if (!array_key_exists($data['role'], $roleOptions))
            $errors[] = 'You are not permitted to assign that role.';

        // Email uniqueness
        if ($data['email']) {
            $emailCheck = DB::fetchOne(
                "SELECT id FROM users WHERE email=? AND id!=?",
                [$data['email'], $id ?: 0]
            );
            if ($emailCheck) $errors[] = 'This email address is already in use.';
        }

        // Password rules
        if (!$isEdit && !$pw) {
            $errors[] = 'Password is required for new users.';
        }
        if ($pw) {
            if (strlen($pw) < 6) $errors[] = 'Password must be at least 6 characters.';
            if ($pw !== $pw2)    $errors[] = 'Passwords do not match.';
        }

        if (!$errors) {
            if ($isEdit) {
                $setClause = "name=?,email=?,phone=?,role=?,status=?";
                $setParams = [
                    $data['name'], $data['email'], $data['phone'],
                    $data['role'], $data['status']
                ];
                if ($pw) {
                    $setClause .= ",password=?";
                    $setParams[] = hashPassword($pw);
                }
                $setParams[] = $id;
                DB::query("UPDATE users SET $setClause WHERE id=?", $setParams);
                logActivity($selfId, 'Edit User', "User #{$id} '{$data['name']}' updated by {$selfRole}");
                setFlash('success', 'User updated successfully.');
            } else {
                DB::query(
                    "INSERT INTO users (name,email,phone,role,status,password)
                     VALUES (?,?,?,?,?,?)",
                    [
                        $data['name'], $data['email'], $data['phone'],
                        $data['role'], $data['status'],
                        hashPassword($pw),
                    ]
                );
                $newId = DB::lastInsertId();
                logActivity($selfId, 'Create User', "User #{$newId} '{$data['name']}' ({$data['role']}) created by {$selfRole}");
                setFlash('success', ucfirst($data['role']) . ' account created successfully.');
            }
            redirect(APP_URL . '/admin/users.php');
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center">
  <div class="col-12 col-xl-8">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>
          <i class="bi bi-<?= $isEdit ? 'pencil' : 'person-plus' ?> me-2 text-primary"></i>
          <?= $pageTitle ?>
        </span>
        <a href="<?= APP_URL ?>/admin/users.php" class="btn btn-sm btn-outline-secondary">
          <i class="bi bi-arrow-left me-1"></i>Back
        </a>
      </div>
      <div class="card-body">

        <?php foreach ($errors as $err): ?>
        <div class="alert alert-danger py-2">
          <i class="bi bi-exclamation-triangle me-2"></i><?= e($err) ?>
        </div>
        <?php endforeach; ?>

        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

          <div class="row g-3">
            <!-- Personal Info -->
            <div class="col-12">
              <h6 class="text-muted fw-600 border-bottom pb-1">Personal Information</h6>
            </div>

            <div class="col-md-6">
              <label class="form-label">Full Name <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control"
                     value="<?= e($data['name'] ?? '') ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Email Address <span class="text-danger">*</span></label>
              <input type="email" name="email" class="form-control"
                     value="<?= e($data['email'] ?? '') ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Phone</label>
              <input type="tel" name="phone" class="form-control"
                     value="<?= e($data['phone'] ?? '') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Role <span class="text-danger">*</span></label>
              <select name="role" class="form-select" <?= count($roleOptions) === 1 ? 'disabled' : '' ?>>
                <?php if (count($roleOptions) > 1): ?>
                <option value="">— Select Role —</option>
                <?php endif; ?>
                <?php foreach ($roleOptions as $val => $label): ?>
                <option value="<?= e($val) ?>"
                  <?= ($data['role'] ?? (count($roleOptions) === 1 ? array_key_first($roleOptions) : '')) === $val ? 'selected' : '' ?>>
                  <?= e($label) ?>
                </option>
                <?php endforeach; ?>
              </select>
              <?php if (count($roleOptions) === 1): ?>
              <input type="hidden" name="role" value="<?= e(array_key_first($roleOptions)) ?>">
              <?php endif; ?>
            </div>
            <div class="col-md-3">
              <label class="form-label">Status</label>
              <select name="status" class="form-select">
                <option value="active"   <?= ($data['status'] ?? 'active') === 'active'  ?'selected':'' ?>>Active</option>
                <option value="inactive" <?= ($data['status'] ?? '') === 'inactive' ?'selected':'' ?>>Inactive</option>
              </select>
            </div>

            <!-- Password -->
            <div class="col-12 mt-2">
              <h6 class="text-muted fw-600 border-bottom pb-1">
                <?= $isEdit ? 'Change Password' : 'Set Password' ?>
                <?php if ($isEdit): ?>
                <span class="fw-400 small ms-2">(leave blank to keep current)</span>
                <?php endif; ?>
              </h6>
            </div>

            <div class="col-md-6">
              <label class="form-label">
                Password <?= !$isEdit ? '<span class="text-danger">*</span>' : '' ?>
              </label>
              <div class="input-group">
                <input type="password" id="pwNew" name="password" class="form-control"
                       placeholder="Min 6 characters" <?= !$isEdit ? 'required' : '' ?>>
                <button type="button" class="btn btn-outline-secondary" onclick="togglePwd('pwNew','eyeNew')">
                  <i class="bi bi-eye" id="eyeNew"></i>
                </button>
              </div>
              <!-- Strength bar -->
              <div style="height:3px;border-radius:2px;background:var(--border);margin-top:.35rem;overflow:hidden;">
                <div id="strFill" style="height:100%;border-radius:2px;width:0;transition:width .3s,background .3s;"></div>
              </div>
              <div id="strLabel" style="font-size:.67rem;margin-top:2px;"></div>
            </div>
            <div class="col-md-6">
              <label class="form-label">
                Confirm Password <?= !$isEdit ? '<span class="text-danger">*</span>' : '' ?>
              </label>
              <div class="input-group">
                <input type="password" id="pwConf" name="confirm_password" class="form-control"
                       placeholder="Repeat password" <?= !$isEdit ? 'required' : '' ?>>
                <button type="button" class="btn btn-outline-secondary" onclick="togglePwd('pwConf','eyeConf')">
                  <i class="bi bi-eye" id="eyeConf"></i>
                </button>
              </div>
              <div id="matchLabel" style="font-size:.7rem;margin-top:.3rem;"></div>
            </div>

            <!-- Role permission info card -->
            <div class="col-12" id="roleInfoWrap">
              <!-- Filled by JS based on selected role -->
            </div>

            <div class="col-12 d-flex gap-2 justify-content-end mt-2">
              <a href="<?= APP_URL ?>/admin/users.php" class="btn btn-outline-secondary">Cancel</a>
              <button type="submit" class="btn btn-primary px-4">
                <i class="bi bi-check-lg me-1"></i>
                <?= $isEdit ? 'Update User' : 'Create User' ?>
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
// Password eye toggle
function togglePwd(inputId, iconId) {
  const inp = document.getElementById(inputId);
  const ico = document.getElementById(iconId);
  inp.type  = inp.type === 'password' ? 'text' : 'password';
  ico.className = inp.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
}

// Strength meter
document.getElementById('pwNew').addEventListener('input', function() {
  const v = this.value;
  let score = 0;
  if (v.length >= 6)              score++;
  if (v.length >= 10)             score++;
  if (/[A-Z]/.test(v))            score++;
  if (/[0-9]/.test(v))            score++;
  if (/[^A-Za-z0-9]/.test(v))     score++;
  const levels = [
    {w:'0%',  c:'transparent', l:''},
    {w:'20%', c:'#ef4444',     l:'Very Weak'},
    {w:'40%', c:'#f97316',     l:'Weak'},
    {w:'60%', c:'#f59e0b',     l:'Fair'},
    {w:'80%', c:'#22c55e',     l:'Strong'},
    {w:'100%',c:'#10b981',     l:'Very Strong'},
  ];
  const lv = v.length === 0 ? levels[0] : levels[Math.max(1, Math.min(score, 5))];
  document.getElementById('strFill').style.width      = lv.w;
  document.getElementById('strFill').style.background = lv.c;
  document.getElementById('strLabel').textContent     = lv.l;
  document.getElementById('strLabel').style.color     = lv.c;
  checkMatch();
});

// Match check
function checkMatch() {
  const p1  = document.getElementById('pwNew').value;
  const p2  = document.getElementById('pwConf').value;
  const lbl = document.getElementById('matchLabel');
  if (!p2) { lbl.innerHTML = ''; return; }
  lbl.innerHTML = p1 === p2
    ? '<span style="color:#22c55e"><i class="bi bi-check2 me-1"></i>Passwords match</span>'
    : '<span style="color:#ef4444"><i class="bi bi-x-lg me-1"></i>Passwords do not match</span>';
}
document.getElementById('pwConf').addEventListener('input', checkMatch);

// Role info cards
const roleInfo = {
  admin: {
    icon: 'bi-shield-fill',
    color: '#7c3aed',
    bg: '#ede9fe',
    title: 'Admin — Full Access',
    perms: [
      'View, add, edit, delete all leads and clients',
      'Assign leads and clients to any employee or manager',
      'Add, edit, delete all users (admin / manager / employee)',
      'Access reports, activity log, settings',
      'Manage lead sources and all system configuration',
    ]
  },
  manager: {
    icon: 'bi-person-badge-fill',
    color: '#d97706',
    bg: '#fef3c7',
    title: 'Manager — Team Access',
    perms: [
      'View, add, and edit all leads and clients',
      'Assign leads and clients to employees',
      'Add and edit employee accounts',
      'Access reports and follow-ups',
      'Cannot change system settings or delete admins/managers',
    ]
  },
  employee: {
    icon: 'bi-person-fill',
    color: '#059669',
    bg: '#d1fae5',
    title: 'Employee — Own Data Only',
    perms: [
      'View and manage their own assigned leads and clients',
      'Add follow-up notes on their own leads',
      'View their own performance report',
      'Cannot assign leads to others or access team data',
    ]
  }
};

const roleSelect = document.querySelector('select[name="role"]');
const infoWrap   = document.getElementById('roleInfoWrap');

function updateRoleInfo(role) {
  if (!role || !roleInfo[role]) { infoWrap.innerHTML = ''; return; }
  const r = roleInfo[role];
  infoWrap.innerHTML = `
    <div style="background:${r.bg};border-radius:10px;padding:1rem 1.1rem;border-left:3px solid ${r.color};">
      <div style="font-weight:700;font-size:.85rem;color:${r.color};margin-bottom:.5rem;">
        <i class="bi ${r.icon} me-2"></i>${r.title}
      </div>
      <ul style="margin:0;padding-left:1.2rem;font-size:.8rem;color:#374151;line-height:1.7;">
        ${r.perms.map(p => `<li>${p}</li>`).join('')}
      </ul>
    </div>`;
}

if (roleSelect) {
  roleSelect.addEventListener('change', function() { updateRoleInfo(this.value); });
  updateRoleInfo(roleSelect.value);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>