<?php
// manager/users.php — Manager: Employee Management
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin','manager']);

$pageTitle  = 'User Management';
$activePage = 'users';
$role       = $_SESSION['user_role'];
$uid        = $_SESSION['user_id'];

// Managers can only see/manage employees
// Admins can see everyone
$fRole   = $_GET['role']   ?? '';
$fStatus = $_GET['status'] ?? '';
$fSearch = trim($_GET['q'] ?? '');

$where  = ['u.id != ?']; // Never show the current user in the list
$params = [$uid];

// Managers can only ever manage employees
if ($role === 'manager') {
    $where[] = "u.role = 'employee'";
} elseif ($fRole) {
    $where[] = 'u.role = ?';
    $params[] = $fRole;
}

if ($fStatus) { $where[] = 'u.status = ?'; $params[] = $fStatus; }
if ($fSearch) {
    $where[] = '(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
    $s = "%$fSearch%";
    $params = array_merge($params, [$s, $s, $s]);
}
$ws = implode(' AND ', $where);

$users = DB::fetchAll(
    "SELECT u.*
     FROM users u
     WHERE $ws
     ORDER BY FIELD(u.role,'admin','manager','employee'), u.name ASC",
    $params
);

// KPI counts — admin sees all, manager only sees employees
if ($role === 'admin') {
    $kpiTotal    = (int)DB::fetchOne("SELECT COUNT(*) c FROM users WHERE id != ?", [$uid])['c'];
    $kpiAdmins   = (int)DB::fetchOne("SELECT COUNT(*) c FROM users WHERE role='admin' AND id != ?", [$uid])['c'];
    $kpiManagers = (int)DB::fetchOne("SELECT COUNT(*) c FROM users WHERE role='manager'")['c'];
    $kpiEmps     = (int)DB::fetchOne("SELECT COUNT(*) c FROM users WHERE role='employee'")['c'];
} else {
    $kpiTotal    = (int)DB::fetchOne("SELECT COUNT(*) c FROM users WHERE role='employee'")['c'];
    $kpiAdmins   = null;
    $kpiManagers = null;
    $kpiEmps     = $kpiTotal;
}

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.role-badge-admin   { background:#ede9fe; color:#7c3aed; }
.role-badge-manager { background:#fef3c7; color:#d97706; }
.role-badge-employee{ background:#d1fae5; color:#059669; }
.role-badge {
  font-size:.67rem; font-weight:700; padding:.2rem .6rem;
  border-radius:20px; white-space:nowrap;
}
.status-active   { background:#d1fae5; color:#059669; }
.status-inactive { background:#fee2e2; color:#dc2626; }
.status-badge {
  font-size:.67rem; font-weight:700; padding:.2rem .55rem;
  border-radius:20px; white-space:nowrap;
}
.user-avatar-sm {
  width:34px; height:34px; border-radius:10px; flex-shrink:0;
  background:var(--primary-light); color:var(--primary);
  display:grid; place-items:center; font-weight:800; font-size:.85rem;
}
.btn-xs { padding:.2rem .45rem; font-size:.73rem; border-radius:5px; }
.filter-grid { display:grid; gap:.5rem; grid-template-columns:1fr 1fr; }
@media(min-width:768px){ .filter-grid{ grid-template-columns:2fr 1fr 1fr auto; } }
</style>

<!-- KPI strip -->
<div class="row g-3 mb-3">
  <?php if ($role === 'admin'): ?>
  <div class="col-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon blue"><i class="bi bi-people-fill"></i></div>
      <div><div class="stat-number"><?= $kpiTotal ?></div><div class="stat-label">Total Users</div></div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon purple"><i class="bi bi-shield-fill"></i></div>
      <div><div class="stat-number"><?= $kpiAdmins ?></div><div class="stat-label">Admins</div></div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon amber"><i class="bi bi-person-badge-fill"></i></div>
      <div><div class="stat-number"><?= $kpiManagers ?></div><div class="stat-label">Managers</div></div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green"><i class="bi bi-person-fill"></i></div>
      <div><div class="stat-number"><?= $kpiEmps ?></div><div class="stat-label">Employees</div></div>
    </div>
  </div>
  <?php else: ?>
  <div class="col-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon green"><i class="bi bi-people-fill"></i></div>
      <div><div class="stat-number"><?= $kpiTotal ?></div><div class="stat-label">Total Employees</div></div>
    </div>
  </div>
  <div class="col-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon blue"><i class="bi bi-person-check-fill"></i></div>
      <div>
        <div class="stat-number"><?= DB::fetchOne("SELECT COUNT(*) c FROM users WHERE role='employee' AND status='active'")['c'] ?></div>
        <div class="stat-label">Active</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon red"><i class="bi bi-person-x-fill"></i></div>
      <div>
        <div class="stat-number"><?= DB::fetchOne("SELECT COUNT(*) c FROM users WHERE role='employee' AND status='inactive'")['c'] ?></div>
        <div class="stat-label">Inactive</div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Filter bar -->
<div class="card mb-3">
  <div class="card-body py-2 px-3">
    <form method="GET" class="filter-grid">
      <input type="text" name="q" class="form-control form-control-sm"
             placeholder="Search name, email, phone..." value="<?= e($fSearch) ?>">
      <?php if ($role === 'admin'): ?>
      <select name="role" class="form-select form-select-sm">
        <option value="">All Roles</option>
        <option value="admin"    <?= $fRole==='admin'   ?'selected':'' ?>>Admin</option>
        <option value="manager"  <?= $fRole==='manager' ?'selected':'' ?>>Manager</option>
        <option value="employee" <?= $fRole==='employee'?'selected':'' ?>>Employee</option>
      </select>
      <?php else: ?>
      <input type="hidden" name="role" value="employee">
      <?php endif; ?>
      <select name="status" class="form-select form-select-sm">
        <option value="">All Status</option>
        <option value="active"   <?= $fStatus==='active'  ?'selected':'' ?>>Active</option>
        <option value="inactive" <?= $fStatus==='inactive'?'selected':'' ?>>Inactive</option>
      </select>
      <div class="d-flex gap-1">
        <button type="submit" class="btn btn-primary btn-sm flex-fill">
          <i class="bi bi-search me-1"></i>Filter
        </button>
        <a href="<?= APP_URL ?>/manager/users.php" class="btn btn-outline-secondary btn-sm px-2" title="Reset">
          <i class="bi bi-x-lg"></i>
        </a>
      </div>
    </form>
  </div>
</div>

<!-- Table header -->
<div class="d-flex justify-content-between align-items-center mb-3">
  <span class="fw-700">
    <i class="bi bi-people me-2 text-primary"></i>
    <?= $role === 'manager' ? 'Employees' : 'Users' ?>
    <span class="badge bg-primary"><?= count($users) ?></span>
  </span>
  <a href="<?= APP_URL ?>/manager/user-form.php" class="btn btn-sm btn-primary">
    <i class="bi bi-plus-lg me-1"></i>
    <?= $role === 'manager' ? 'Add Employee' : 'Add User' ?>
  </a>
</div>

<!-- Users table -->
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" style="font-size:.84rem;">
        <thead>
          <tr>
            <th width="40">#</th>
            <th>Name</th>
            <th>Email</th>
            <th>Phone</th>
            <th>Role</th>
            <th>Status</th>
            <th>Created</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $i => $u): ?>
          <tr>
            <td class="text-muted"><?= $i + 1 ?></td>
            <td>
              <div class="d-flex align-items-center gap-2">
                <div class="user-avatar-sm">
                  <?php if (!empty($u['profile_image'])): ?>
                    <img src="<?= UPLOAD_URL . e($u['profile_image']) ?>" alt=""
                         style="width:34px;height:34px;border-radius:10px;object-fit:cover;">
                  <?php else: ?>
                    <?= strtoupper(substr($u['name'], 0, 1)) ?>
                  <?php endif; ?>
                </div>
                <span class="fw-600"><?= e($u['name']) ?></span>
              </div>
            </td>
            <td><?= e($u['email']) ?></td>
            <td><?= e($u['phone'] ?? '—') ?></td>
            <td>
              <span class="role-badge role-badge-<?= e($u['role']) ?>">
                <?= ucfirst($u['role']) ?>
              </span>
            </td>
            <td>
              <span class="status-badge status-<?= e($u['status']) ?>">
                <?= ucfirst($u['status']) ?>
              </span>
            </td>
            <td class="text-muted"><?= date('d M Y', strtotime($u['created_at'])) ?></td>
            <td>
              <div class="d-flex gap-1">
                <a href="<?= APP_URL ?>/manager/user-form.php?id=<?= $u['id'] ?>"
                   class="btn btn-xs btn-outline-secondary" title="Edit">
                  <i class="bi bi-pencil"></i>
                </a>
                <?php
                  // Manager can delete employees only
                  // Admin can delete anyone except themselves
                  $canDelete = ($role === 'admin') ||
                               ($role === 'manager' && $u['role'] === 'employee');
                ?>
                <?php if ($canDelete): ?>
                <button type="button" class="btn btn-xs btn-outline-danger"
                        data-bs-toggle="modal" data-bs-target="#deleteUserModal"
                        data-id="<?= $u['id'] ?>" data-name="<?= e($u['name']) ?>"
                        data-role="<?= e($u['role']) ?>" title="Delete">
                  <i class="bi bi-trash"></i>
                </button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$users): ?>
          <tr>
            <td colspan="9" class="text-center text-muted py-5">
              <i class="bi bi-people" style="font-size:2.5rem;display:block;opacity:.25;margin-bottom:.75rem;"></i>
              No users found.
            </td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Delete modal -->
<div class="modal fade" id="deleteUserModal" tabindex="-1">
  <div class="modal-dialog modal-sm modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title"><i class="bi bi-trash text-danger me-2"></i>Delete User</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body py-2">
        Delete <strong id="deleteUserName"></strong>?
        <div class="text-muted small mt-1">
          Their assigned leads and clients will become unassigned. This cannot be undone.
        </div>
      </div>
      <div class="modal-footer border-0 pt-0 d-flex gap-2">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <a id="deleteUserConfirm" href="#" class="btn btn-danger btn-sm">
          <i class="bi bi-trash me-1"></i>Delete
        </a>
      </div>
    </div>
  </div>
</div>

<script>
document.getElementById('deleteUserModal').addEventListener('show.bs.modal', function(ev) {
  const btn = ev.relatedTarget;
  document.getElementById('deleteUserName').textContent = btn.dataset.name;
  document.getElementById('deleteUserConfirm').href =
    '<?= APP_URL ?>/api/users.php?action=delete&id=' + btn.dataset.id;
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>