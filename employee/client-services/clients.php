<?php
// employee/client-services/clients.php — Employee: My Clients (Grid Card View)
require_once __DIR__ . '/../../includes/auth.php';
requireRole(['employee']);

$pageTitle  = 'My Clients';
$activePage = 'clients';
$uid = $_SESSION['user_id'];

$statusOptions = ['Active','On Hold','Inactive','Completed'];
$serviceOptions = [
    'Search Engine Optimization',
    'Online Reputation Management',
    'Social Media Monitoring & Listening',
    'Ecommerce SEO',
    'Social Media Management',
    'Digital Brand Launch',
    'Review Management',
    'Local SEO',
    'Campaign Management',
    'Website Designing',
    'Crisis Management',
];

$fSearch  = trim($_GET['q'] ?? '');
$fStatus  = $_GET['status']  ?? '';
$fService = $_GET['service'] ?? '';

$where  = ['c.account_manager = ?'];
$params = [$uid];
if ($fSearch) {
    $where[] = '(c.company LIKE ? OR c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)';
    $s = "%$fSearch%";
    $params = array_merge($params, [$s,$s,$s,$s]);
}
if ($fStatus)  { $where[] = 'c.status=?';                $params[] = $fStatus; }
if ($fService) { $where[] = 'FIND_IN_SET(?, c.services)'; $params[] = $fService; }
$ws = implode(' AND ', $where);

$perPage = 12;
$page    = max(1, (int)($_GET['p'] ?? 1));
$offset  = ($page - 1) * $perPage;

$total = DB::fetchOne("SELECT COUNT(*) c FROM clients c WHERE $ws", $params)['c'];
$pages = max(1, ceil($total / $perPage));

$clients = DB::fetchAll(
    "SELECT c.* FROM clients c
     WHERE $ws
     ORDER BY c.created_at DESC
     LIMIT $perPage OFFSET $offset",
    $params
);

// KPI strip — own clients only
$kpiTotal  = (int)DB::fetchOne("SELECT COUNT(*) c FROM clients WHERE account_manager=?", [$uid])['c'];
$kpiActive = (int)DB::fetchOne("SELECT COUNT(*) c FROM clients WHERE account_manager=? AND status='Active'", [$uid])['c'];
$kpiHold   = (int)DB::fetchOne("SELECT COUNT(*) c FROM clients WHERE account_manager=? AND status='On Hold'", [$uid])['c'];
$kpiValue  = (float)DB::fetchOne("SELECT COALESCE(SUM(contract_value),0) v FROM clients WHERE account_manager=? AND status='Active'", [$uid])['v'];

require_once __DIR__ . '/../../includes/header.php';
?>

<style>
.cs-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(280px,1fr)); gap:1rem; }

.cs-card {
  background:var(--surface); border:1px solid var(--border); border-radius:var(--radius);
  padding:1.1rem; display:flex; flex-direction:column; gap:.65rem;
  transition:box-shadow .15s, border-color .15s;
}
.cs-card:hover { box-shadow:var(--shadow-md); border-color:var(--primary); }

.cs-card-top  { display:flex; align-items:flex-start; gap:.75rem; }
.cs-avatar    {
  width:48px; height:48px; border-radius:14px; flex-shrink:0;
  background:var(--primary-light); color:var(--primary);
  display:grid; place-items:center; font-weight:800; font-size:1.1rem;
}
.cs-company   { font-weight:800; font-size:.95rem; line-height:1.25; }
.cs-contact   { font-size:.78rem; color:var(--text-muted); margin-top:1px; }

.cs-status-badge {
  font-size:.66rem; font-weight:700; padding:.18rem .6rem; border-radius:20px;
  white-space:nowrap; margin-left:auto;
}
.cs-status-Active    { background:#d1fae5; color:#059669; }
.cs-status-On-Hold   { background:#fef3c7; color:#d97706; }
.cs-status-Inactive  { background:#f1f5f9; color:#64748b; }
.cs-status-Completed { background:#dbeafe; color:#2563eb; }

.cs-services { display:flex; flex-wrap:wrap; gap:.3rem; }
.cs-chip {
  font-size:.66rem; background:var(--bg); border:1px solid var(--border); color:var(--text-muted);
  padding:.12rem .5rem; border-radius:6px; white-space:nowrap;
}

.cs-meta-row  { display:flex; justify-content:space-between; align-items:center; font-size:.78rem; color:var(--text-muted); }
.cs-value     { font-weight:700; color:var(--text); }

.cs-card-actions {
  display:flex; gap:.4rem; margin-top:.2rem; border-top:1px solid var(--border); padding-top:.65rem;
}
.cs-card-actions .btn { flex:1; }

.cs-filter-grid { display:grid; gap:.5rem; grid-template-columns:1fr 1fr; }
@media(min-width:768px){ .cs-filter-grid{ grid-template-columns:2fr 1fr 1fr auto; } }

.btn-xs { padding:.2rem .45rem; font-size:.73rem; border-radius:5px; }
</style>

<!-- KPI strip -->
<div class="row g-3 mb-3">
  <div class="col-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon blue"><i class="bi bi-briefcase-fill"></i></div>
      <div><div class="stat-number"><?= $kpiTotal ?></div><div class="stat-label">My Clients</div></div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green"><i class="bi bi-check-circle-fill"></i></div>
      <div><div class="stat-number"><?= $kpiActive ?></div><div class="stat-label">Active</div></div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon amber"><i class="bi bi-pause-circle-fill"></i></div>
      <div><div class="stat-number"><?= $kpiHold ?></div><div class="stat-label">On Hold</div></div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon blue"><i class="bi bi-cash-stack"></i></div>
      <div><div class="stat-number" style="font-size:1.15rem;">₹<?= number_format($kpiValue) ?></div><div class="stat-label">My Active Value</div></div>
    </div>
  </div>
</div>

<!-- Filter bar -->
<div class="card mb-3">
  <div class="card-body py-2 px-3">
    <form method="GET" class="cs-filter-grid">
      <input type="text" name="q" class="form-control form-control-sm"
             placeholder="Search company, contact, phone, email..." value="<?= e($fSearch) ?>">
      <select name="status" class="form-select form-select-sm">
        <option value="">All Status</option>
        <?php foreach ($statusOptions as $s): ?>
        <option value="<?= e($s) ?>" <?= $fStatus===$s?'selected':'' ?>><?= e($s) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="service" class="form-select form-select-sm">
        <option value="">All Services</option>
        <?php foreach ($serviceOptions as $svc): ?>
        <option value="<?= e($svc) ?>" <?= $fService===$svc?'selected':'' ?>><?= e($svc) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="d-flex gap-1">
        <button type="submit" class="btn btn-primary btn-sm flex-fill">
          <i class="bi bi-search me-1"></i>Filter
        </button>
        <a href="<?= APP_URL ?>/employee/client-services/clients.php" class="btn btn-outline-secondary btn-sm px-2" title="Reset">
          <i class="bi bi-x-lg"></i>
        </a>
      </div>
    </form>
  </div>
</div>

<div class="d-flex justify-content-between align-items-center mb-3">
  <span class="fw-700"><i class="bi bi-briefcase me-2 text-primary"></i>My Clients <span class="badge bg-primary"><?= $total ?></span></span>
  <a href="<?= APP_URL ?>/employee/client-services/client-form.php" class="btn btn-sm btn-primary">
    <i class="bi bi-plus-lg me-1"></i>Add Client
  </a>
</div>

<!-- Grid -->
<div class="cs-grid">
  <?php foreach ($clients as $c): ?>
  <?php
    $svcList = $c['services'] ? array_filter(array_map('trim', explode(',', $c['services']))) : [];
    $svcShown = array_slice($svcList, 0, 3);
    $svcMore  = count($svcList) - count($svcShown);
    $statusClass = str_replace(' ', '-', $c['status']);
  ?>
  <div class="cs-card">
    <div class="cs-card-top">
      <div class="cs-avatar"><?= strtoupper(substr($c['company'], 0, 1)) ?></div>
      <div class="flex-grow-1 overflow-hidden">
        <div class="cs-company text-truncate"><?= e($c['company']) ?></div>
        <div class="cs-contact text-truncate"><?= e($c['name']) ?></div>
      </div>
      <span class="cs-status-badge cs-status-<?= e($statusClass) ?>"><?= e($c['status']) ?></span>
    </div>

    <?php if ($svcShown): ?>
    <div class="cs-services">
      <?php foreach ($svcShown as $svc): ?>
      <span class="cs-chip"><?= e($svc) ?></span>
      <?php endforeach; ?>
      <?php if ($svcMore > 0): ?>
      <span class="cs-chip">+<?= $svcMore ?> more</span>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="text-muted small">No services assigned yet</div>
    <?php endif; ?>

    <div class="cs-meta-row">
      <span><i class="bi bi-telephone me-1"></i><?= e($c['phone']) ?></span>
      <?php if ($c['contract_value']): ?>
      <span class="cs-value">₹<?= number_format($c['contract_value']) ?><?= $c['billing_cycle'] ? '/'.strtolower(substr($c['billing_cycle'],0,2)) : '' ?></span>
      <?php endif; ?>
    </div>

    <?php if ($c['start_date']): ?>
    <div class="cs-meta-row">
      <span><i class="bi bi-calendar3 me-1"></i>Client since <?= date('d M Y', strtotime($c['start_date'])) ?></span>
    </div>
    <?php endif; ?>

    <div class="cs-card-actions">
      <a href="<?= APP_URL ?>/admin/client-services/client-detail.php?id=<?= $c['id'] ?>"
         class="btn btn-xs btn-outline-primary"><i class="bi bi-eye me-1"></i>View</a>
      <a href="<?= APP_URL ?>/employee/client-services/client-form.php?id=<?= $c['id'] ?>"
         class="btn btn-xs btn-outline-secondary"><i class="bi bi-pencil me-1"></i>Edit</a>
      <button type="button" class="btn btn-xs btn-outline-danger delete-client-btn"
              data-id="<?= $c['id'] ?>" data-name="<?= e($c['company']) ?>"
              data-bs-toggle="modal" data-bs-target="#deleteClientModal">
        <i class="bi bi-trash"></i>
      </button>
    </div>
  </div>
  <?php endforeach; ?>

  <?php if (!$clients): ?>
  <div class="text-center text-muted py-5" style="grid-column:1/-1;">
    <i class="bi bi-briefcase" style="font-size:2.5rem;display:block;opacity:.25;margin-bottom:.75rem;"></i>
    No clients assigned to you yet.
  </div>
  <?php endif; ?>
</div>

<?php if ($pages > 1): ?>
<nav class="mt-3"><ul class="pagination pagination-sm justify-content-center mb-0">
  <?php for ($pg=1; $pg<=$pages; $pg++): ?>
  <li class="page-item <?= $pg===$page?'active':'' ?>">
    <a class="page-link" href="?<?= http_build_query(array_merge($_GET,['p'=>$pg])) ?>"><?= $pg ?></a>
  </li>
  <?php endfor; ?>
</ul></nav>
<?php endif; ?>

<!-- Delete confirmation -->
<div class="modal fade" id="deleteClientModal" tabindex="-1">
  <div class="modal-dialog modal-sm modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title"><i class="bi bi-trash text-danger me-2"></i>Delete Client</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body py-2">Delete <strong id="deleteClientName"></strong>? This cannot be undone.</div>
      <div class="modal-footer border-0 pt-0 d-flex gap-2">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <a id="deleteClientConfirmBtn" href="#" class="btn btn-danger btn-sm"><i class="bi bi-trash me-1"></i>Delete</a>
      </div>
    </div>
  </div>
</div>

<script>
document.getElementById('deleteClientModal').addEventListener('show.bs.modal', function (ev) {
  const btn = ev.relatedTarget;
  document.getElementById('deleteClientName').textContent = btn.dataset.name;
  document.getElementById('deleteClientConfirmBtn').href =
    '<?= APP_URL ?>/api/clients.php?action=delete&id=' + btn.dataset.id;
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>