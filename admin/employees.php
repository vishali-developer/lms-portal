<?php
// admin/employees.php — Employee Management (Mobile Responsive)
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin']);

$pageTitle  = 'Employee Management';
$activePage = 'employees';

if (isset($_GET['toggle'])) {
    if (!verifyCsrf($_GET['csrf'] ?? '')) { setFlash('danger','CSRF error'); redirect(APP_URL.'/admin/employees.php'); }
    $tid = (int)$_GET['toggle'];
    $emp = DB::fetchOne("SELECT status FROM users WHERE id=? AND role='employee'", [$tid]);
    if ($emp) {
        $ns = $emp['status']==='active' ? 'inactive' : 'active';
        DB::query("UPDATE users SET status=? WHERE id=?", [$ns, $tid]);
        logActivity($_SESSION['user_id'], 'Toggle Employee', "Employee #$tid set to $ns");
        setFlash('success', 'Employee status updated.');
    }
    redirect(APP_URL.'/admin/employees.php');
}

if (isset($_GET['delete'])) {
    if (!verifyCsrf($_GET['csrf'] ?? '')) { setFlash('danger','CSRF error'); redirect(APP_URL.'/admin/employees.php'); }
    $did = (int)$_GET['delete'];
    DB::query("DELETE FROM users WHERE id=? AND role='employee'", [$did]);
    logActivity($_SESSION['user_id'], 'Delete Employee', "Employee #$did deleted");
    setFlash('success', 'Employee deleted.');
    redirect(APP_URL.'/admin/employees.php');
}

$errors = [];
$formData = [];
$editId   = (int)($_GET['edit'] ?? 0);
$editUser = $editId ? DB::fetchOne("SELECT * FROM users WHERE id=? AND role='employee'",[$editId]) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) { $errors[] = 'CSRF error'; }
    else {
        $formData = [
            'name'  => trim($_POST['name']  ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'phone' => trim($_POST['phone'] ?? ''),
        ];
        $password = $_POST['password'] ?? '';
        $pid      = (int)($_POST['edit_id'] ?? 0);

        if (!$formData['name'])  $errors[] = 'Name required.';
        if (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email required.';
        if (!$pid && !$password) $errors[] = 'Password required for new employee.';
        if ($password && strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';

        $existing = DB::fetchOne(
            "SELECT id FROM users WHERE email=?" . ($pid ? " AND id!=?" : ""),
            $pid ? [$formData['email'],$pid] : [$formData['email']]
        );
        if ($existing) $errors[] = 'Email already in use.';

        if (!$errors) {
            if ($pid) {
                $sql = "UPDATE users SET name=?,email=?,phone=?";
                $p   = [$formData['name'],$formData['email'],$formData['phone']];
                if ($password) { $sql .= ",password=?"; $p[] = hashPassword($password); }
                $sql .= " WHERE id=?"; $p[] = $pid;
                DB::query($sql, $p);
                logActivity($_SESSION['user_id'],'Edit Employee',"Employee #$pid updated");
                setFlash('success','Employee updated.');
            } else {
                DB::query(
                    "INSERT INTO users (name,email,phone,password,role) VALUES (?,?,?,?,?)",
                    [$formData['name'],$formData['email'],$formData['phone'],hashPassword($password),'employee']
                );
                logActivity($_SESSION['user_id'],'Add Employee','New employee added');
                setFlash('success','Employee added.');
            }
            redirect(APP_URL.'/admin/employees.php');
        }
    }
}

$employees = DB::fetchAll(
    "SELECT u.*,
      (SELECT COUNT(*) FROM leads WHERE assigned_to=u.id)                       AS lead_count,
      (SELECT COUNT(*) FROM leads WHERE assigned_to=u.id AND status='Converted') AS converted
     FROM users u WHERE u.role='employee' ORDER BY u.created_at DESC"
);
$csrf = csrfToken();

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ── Mobile: form above, list below ───────────────────── */
@media(max-width:767px) {
  .emp-form-col  { order:1; }
  .emp-list-col  { order:2; }
}

/* ── Desktop table / Mobile card toggle ───────────────── */
.emp-desktop { display:block; }
.emp-mobile  { display:none;  }
@media(max-width:767px) {
  .emp-desktop { display:none; }
  .emp-mobile  { display:block; padding:.5rem; }
}

/* ── Mobile employee card ──────────────────────────────── */
.emc {
  background:var(--surface); border:1px solid var(--border);
  border-radius:var(--radius); padding:.85rem 1rem; margin-bottom:.55rem;
}
.emc-header  { display:flex; align-items:center; gap:.75rem; margin-bottom:.5rem; }
.emc-avatar  {
  width:42px; height:42px; background:var(--primary); color:#fff;
  border-radius:50%; display:grid; place-items:center;
  font-size:1rem; font-weight:700; flex-shrink:0;
}
.emc-name    { font-weight:700; font-size:.88rem; }
.emc-email   { font-size:.73rem; color:var(--text-muted); }
.emc-stats   { display:flex; gap:.65rem; flex-wrap:wrap; font-size:.74rem;
               margin-bottom:.55rem; color:var(--text-muted); }
.emc-footer  { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:.35rem; }

.btn-xs { padding:.2rem .45rem; font-size:.73rem; border-radius:5px; }

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
  <!-- Form column -->
  <div class="col-12 col-xl-4 emp-form-col">
    <div class="card">
      <div class="card-header fw-700" style="font-size:.87rem;">
        <i class="bi bi-person-plus me-2 text-primary"></i>
        <?= $editUser ? 'Edit Employee' : 'Add Employee' ?>
      </div>
      <div class="card-body">
        <?php foreach ($errors as $err): ?>
        <div class="alert alert-danger py-2 small d-flex align-items-center gap-2">
          <i class="bi bi-exclamation-circle-fill flex-shrink-0"></i><?= e($err) ?>
        </div>
        <?php endforeach; ?>

        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="edit_id" value="<?= $editUser ? $editUser['id'] : 0 ?>">

          <div class="mb-3">
            <label class="form-label">Full Name *</label>
            <input type="text" name="name" class="form-control"
                   value="<?= e($editUser ? $editUser['name'] : ($formData['name']??'')) ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Email *</label>
            <input type="email" name="email" class="form-control"
                   value="<?= e($editUser ? $editUser['email'] : ($formData['email']??'')) ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Phone</label>
            <input type="tel" name="phone" class="form-control"
                   value="<?= e($editUser ? $editUser['phone'] : ($formData['phone']??'')) ?>">
          </div>
          <div class="mb-4">
            <label class="form-label">
              Password <?= $editUser ? '<span class="text-muted small">(leave blank to keep)</span>' : '*' ?>
            </label>
            <div class="pwd-wrap">
              <input type="password" id="empPassword" name="password" class="form-control"
                     placeholder="Min 6 characters" <?= $editUser ? '' : 'required' ?>>
              <button type="button" class="pwd-eye" onclick="togglePwd('empPassword','empEyeIcon')"
                      tabindex="-1" aria-label="Toggle password visibility">
                <i class="bi bi-eye" id="empEyeIcon"></i>
              </button>
            </div>
          </div>
          <div class="d-flex gap-2">
            <?php if ($editUser): ?>
            <a href="<?= APP_URL ?>/admin/employees.php" class="btn btn-outline-secondary flex-fill">
              Cancel
            </a>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary flex-fill">
              <i class="bi bi-check me-1"></i>
              <?= $editUser ? 'Update Employee' : 'Add Employee' ?>
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- List column -->
  <div class="col-12 col-xl-8 emp-list-col">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-700" style="font-size:.87rem;">
          <i class="bi bi-people me-2 text-primary"></i>
          Employees
        </span>
        <span class="badge bg-primary"><?= count($employees) ?></span>
      </div>

      <!-- Desktop table -->
      <div class="emp-desktop">
        <div class="table-responsive">
          <table class="table table-hover mb-0" style="font-size:.82rem;">
            <thead>
              <tr>
                <th>#</th><th>Name</th><th>Contact</th>
                <th class="text-center">Leads</th>
                <th class="text-center">Converted</th>
                <th>Status</th><th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($employees as $i => $emp): ?>
              <tr>
                <td><?= $i+1 ?></td>
                <td>
                  <div class="d-flex align-items-center gap-2">
                    <div class="mini-avatar"><?= strtoupper(substr($emp['name'],0,1)) ?></div>
                    <div>
                      <div class="fw-600 small"><?= e($emp['name']) ?></div>
                      <div class="text-muted" style="font-size:.7rem;">
                        <?= date('d M y', strtotime($emp['created_at'])) ?>
                      </div>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="small"><?= e($emp['email']) ?></div>
                  <div class="text-muted" style="font-size:.73rem;"><?= e($emp['phone']) ?></div>
                </td>
                <td class="text-center"><span class="badge bg-primary"><?= $emp['lead_count'] ?></span></td>
                <td class="text-center"><span class="badge bg-success"><?= $emp['converted'] ?></span></td>
                <td>
                  <a href="?toggle=<?= $emp['id'] ?>&csrf=<?= $csrf ?>"
                     class="badge text-decoration-none <?= $emp['status']==='active'?'bg-success':'bg-secondary' ?>">
                    <?= ucfirst($emp['status']) ?>
                  </a>
                </td>
                <td>
                  <div class="d-flex gap-1">
                    <a href="?edit=<?= $emp['id'] ?>" class="btn btn-xs btn-outline-secondary" title="Edit">
                      <i class="bi bi-pencil"></i>
                    </a>
                    <a href="?delete=<?= $emp['id'] ?>&csrf=<?= $csrf ?>"
                       class="btn btn-xs btn-outline-danger"
                       onclick="return confirm('Delete <?= e($emp['name']) ?>?')" title="Delete">
                      <i class="bi bi-trash"></i>
                    </a>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if (!$employees): ?>
              <tr><td colspan="7" class="text-center text-muted py-4">No employees yet.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Mobile cards -->
      <div class="emp-mobile">
        <?php foreach ($employees as $emp): ?>
        <div class="emc">
          <div class="emc-header">
            <div class="emc-avatar"><?= strtoupper(substr($emp['name'],0,1)) ?></div>
            <div>
              <div class="emc-name"><?= e($emp['name']) ?></div>
              <div class="emc-email"><?= e($emp['email']) ?></div>
            </div>
          </div>
          <div class="emc-stats">
            <?php if ($emp['phone']): ?>
            <span><i class="bi bi-telephone me-1"></i><?= e($emp['phone']) ?></span>
            <?php endif; ?>
            <span><i class="bi bi-funnel me-1"></i><?= $emp['lead_count'] ?> leads</span>
            <span><i class="bi bi-check-circle me-1 text-success"></i><?= $emp['converted'] ?> converted</span>
            <span><i class="bi bi-calendar3 me-1"></i>Joined <?= date('d M y', strtotime($emp['created_at'])) ?></span>
          </div>
          <div class="emc-footer">
            <a href="?toggle=<?= $emp['id'] ?>&csrf=<?= $csrf ?>"
               class="badge text-decoration-none <?= $emp['status']==='active'?'bg-success':'bg-secondary' ?>">
              <?= ucfirst($emp['status']) ?>
            </a>
            <div class="d-flex gap-1">
              <a href="?edit=<?= $emp['id'] ?>" class="btn btn-xs btn-outline-secondary">
                <i class="bi bi-pencil me-1"></i>Edit
              </a>
              <a href="?delete=<?= $emp['id'] ?>&csrf=<?= $csrf ?>"
                 class="btn btn-xs btn-outline-danger"
                 onclick="return confirm('Delete <?= e($emp['name']) ?>?')">
                <i class="bi bi-trash"></i>
              </a>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if (!$employees): ?>
        <div class="text-center text-muted py-4">No employees yet.</div>
        <?php endif; ?>
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
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>