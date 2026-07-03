<?php
// manager/leads.php — Manager: All Leads
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin','manager']);

$pageTitle  = 'Lead Management';
$activePage = 'leads';

// ── Build filters ─────────────────────────────────────────────
$where  = ['1=1'];
$params = [];

$fStatus   = $_GET['status']   ?? '';
$fEmployee = $_GET['employee'] ?? '';
$fSource   = $_GET['source']   ?? '';
$fPriority = $_GET['priority'] ?? '';
$fFrom     = $_GET['from']     ?? '';
$fTo       = $_GET['to']       ?? '';
$fSearch   = trim($_GET['q']   ?? '');

if ($fStatus)   { $where[] = 'l.status = ?';            $params[] = $fStatus; }
if ($fEmployee) { $where[] = 'l.assigned_to = ?';       $params[] = $fEmployee; }
if ($fSource)   { $where[] = 'l.source_id = ?';         $params[] = $fSource; }
if ($fPriority) { $where[] = 'l.priority = ?';          $params[] = $fPriority; }
if ($fFrom)     { $where[] = 'DATE(l.created_at) >= ?'; $params[] = $fFrom; }
if ($fTo)       { $where[] = 'DATE(l.created_at) <= ?'; $params[] = $fTo; }
if ($fSearch)   {
    $where[] = '(l.name LIKE ? OR l.phone LIKE ? OR l.email LIKE ? OR l.company LIKE ?)';
    $s = "%$fSearch%";
    $params = array_merge($params, [$s,$s,$s,$s]);
}
$whereStr = implode(' AND ', $where);

// ── Pagination ────────────────────────────────────────────────
$perPage = 20;
$page    = max(1, (int)($_GET['p'] ?? 1));
$offset  = ($page - 1) * $perPage;

$total = DB::fetchOne("SELECT COUNT(*) c FROM leads l WHERE $whereStr", $params)['c'];
$pages = max(1, ceil($total / $perPage));

$leads = DB::fetchAll(
    "SELECT l.*, u.name AS assigned_name, s.name AS source_name
     FROM leads l
     LEFT JOIN users u ON u.id = l.assigned_to
     LEFT JOIN lead_sources s ON s.id = l.source_id
     WHERE $whereStr
     ORDER BY l.created_at DESC
     LIMIT $perPage OFFSET $offset",
    $params);

$employees = DB::fetchAll("SELECT id,name FROM users WHERE role='employee' AND status='active' ORDER BY name");
$sources   = DB::fetchAll("SELECT id,name FROM lead_sources WHERE status='active' ORDER BY name");
$statuses  = ['Untouched','New','Contacted','Follow-up','Interested','Converted','Closed','Rejected'];
$priorities= ['High','Medium','Low'];

// ── Bulk delete ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['bulk_ids'])) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) { setFlash('danger','CSRF error'); redirect($_SERVER['REQUEST_URI']); }
    $ids = array_map('intval', explode(',', $_POST['bulk_ids']));
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    DB::query("DELETE FROM leads WHERE id IN ($ph)", $ids);
    logActivity($_SESSION['user_id'], 'Bulk Delete Leads', count($ids) . ' leads deleted');
    setFlash('success', count($ids) . ' lead(s) deleted.');
    redirect($_SERVER['REQUEST_URI']);
}

// ── Bulk status change ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['bulk_status_ids']) && !empty($_POST['bulk_new_status'])) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) { setFlash('danger','CSRF error'); redirect($_SERVER['REQUEST_URI']); }
    $ids  = array_map('intval', explode(',', $_POST['bulk_status_ids']));
    $ns   = $_POST['bulk_new_status'];
    if (in_array($ns, $statuses)) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        DB::query("UPDATE leads SET status=? WHERE id IN ($ph)", array_merge([$ns], $ids));
        logActivity($_SESSION['user_id'], 'Bulk Status Change', count($ids) . ' leads → ' . $ns);
        setFlash('success', count($ids) . ' lead(s) updated to ' . $ns . '.');
    }
    redirect($_SERVER['REQUEST_URI']);
}

// ── Bulk assign ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['bulk_assign_ids']) && isset($_POST['bulk_assign_to'])) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) { setFlash('danger','CSRF error'); redirect($_SERVER['REQUEST_URI']); }
    $ids  = array_map('intval', explode(',', $_POST['bulk_assign_ids']));
    $at   = (int)$_POST['bulk_assign_to'] ?: null;
    $ph   = implode(',', array_fill(0, count($ids), '?'));
    DB::query("UPDATE leads SET assigned_to=? WHERE id IN ($ph)", array_merge([$at], $ids));
    logActivity($_SESSION['user_id'], 'Bulk Assign', count($ids) . ' leads assigned');
    setFlash('success', count($ids) . ' lead(s) assigned.');
    redirect($_SERVER['REQUEST_URI']);
}

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ── Filter grid ─────────────────────────────────────────────── */
.filter-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: .5rem;
}
@media (min-width:576px)  { .filter-grid { grid-template-columns: repeat(3,1fr); } }
@media (min-width:768px)  { .filter-grid { grid-template-columns: repeat(4,1fr); } }
@media (min-width:992px)  { .filter-grid { grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr 1fr auto; } }

/* ── Card header ─────────────────────────────────────────────── */
.leads-card-header {
  display:     flex;
  align-items: center;
  gap:         .5rem;
  flex-wrap:   wrap;
  padding:     .6rem 1rem;
}
.leads-title {
  font-weight: 700;
  font-size:   .9rem;
  margin-right: auto;   /* pushes everything else to the right */
}

/* ── Context action group — hidden until a row is selected ───── */
.ctx-actions {
  display:     none;            /* JS sets display:flex when count>0  */
  align-items: center;
  gap:         .35rem;
  animation:   ctxIn .18s ease both;
}
@keyframes ctxIn {
  from { opacity:0; transform:translateX(8px); }
  to   { opacity:1; transform:translateX(0); }
}

/* Thin separator between action groups */
.ctx-sep {
  width: 1px; height: 20px;
  background: var(--border, #e2e8f0);
  flex-shrink: 0;
}

/* Selected-count badge inside the bar */
.ctx-count {
  display:       inline-flex;
  align-items:   center;
  gap:           .25rem;
  background:    var(--primary, #2563eb);
  color:         #fff;
  font-size:     .72rem;
  font-weight:   700;
  padding:       .2rem .55rem;
  border-radius: 20px;
  white-space:   nowrap;
}

/* Compact selects inside the action bar */
.ctx-select {
  font-size:     .75rem !important;
  padding:       .25rem .45rem !important;
  border-radius: 5px !important;
  height:        30px;
  min-width:     110px;
  max-width:     150px;
}

/* All buttons in the bar — uniform height */
.leads-card-header .btn,
.ctx-actions .btn {
  height:      30px;
  font-size:   .76rem;
  /* padding-top: 0;
  padding-bottom: 0; */
  white-space: nowrap;
}

/* ── Table ───────────────────────────────────────────────────── */
.leads-table-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }
.leads-table-wrap::-webkit-scrollbar { height:4px; }
.leads-table-wrap::-webkit-scrollbar-thumb { background:var(--border); border-radius:4px; }

#leadsTable th, #leadsTable td { white-space:nowrap; vertical-align:middle; }
#leadsTable .col-check  { width:36px; }
#leadsTable .col-num    { width:40px; }
#leadsTable .col-name   { min-width:150px; white-space:normal; }
#leadsTable .col-phone  { min-width:110px; }
#leadsTable .col-co     { min-width:110px; }
#leadsTable .col-svc    { min-width:90px; }
#leadsTable .col-pri    { width:70px; text-align:center; }
#leadsTable .col-status { min-width:130px; }
#leadsTable .col-assign { min-width:130px; }
#leadsTable .col-date   { width:80px; text-align:center; }
/* NO .col-act — actions column removed */

/* Row highlight when checked */
#leadsTable tbody tr.row-selected { background: var(--primary-light,#eff6ff) !important; }

/* Inline selects */
.select-inline {
  font-size:.75rem !important; padding:.2rem .4rem !important;
  min-width:0 !important; width:100% !important; border-radius:6px !important;
}

/* ── Mobile card ─────────────────────────────────────────────── */
.lead-mobile-card { display:none; }
@media (max-width:767px) {
  .lead-mobile-card   { display:block; }
  .lead-desktop-table { display:none; }
  .ctx-select { min-width:90px; }
}
.lmc { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); padding:1rem; margin-bottom:.65rem; transition:box-shadow .15s; }
.lmc:hover { box-shadow:var(--shadow-md); }
.lmc-top    { display:flex; justify-content:space-between; align-items:flex-start; gap:.5rem; margin-bottom:.5rem; }
.lmc-name   { font-weight:700; font-size:.9rem; color:var(--primary); text-decoration:none; }
.lmc-meta   { display:flex; flex-wrap:wrap; gap:.4rem .75rem; font-size:.78rem; color:var(--text-muted); margin-bottom:.6rem; }
.lmc-meta i { font-size:.75rem; }
.lmc-bottom { display:flex; justify-content:space-between; align-items:center; gap:.5rem; flex-wrap:wrap; margin-top:.6rem; }
.lmc-btns   { display:flex; gap:.35rem; }
.btn-xs     { padding:.2rem .45rem; font-size:.73rem; border-radius:5px; }

/* ── Pagination ──────────────────────────────────────────────── */
.pagination-wrap { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:.5rem; }
</style>

<!-- ── Filter Bar ─────────────────────────────────────────────── -->
<div class="card mb-3">
  <div class="card-body py-2 px-3">
    <form method="GET" id="filterForm">
      <div class="filter-grid mb-2">
        <input type="text" name="q" class="form-control form-control-sm"
               placeholder="&#128269; Search name, phone, email…" value="<?= e($fSearch) ?>">
        <select name="status" class="form-select form-select-sm">
          <option value="">All Status</option>
          <?php foreach ($statuses as $s): ?>
          <option value="<?= $s ?>" <?= $fStatus===$s?'selected':'' ?>><?= $s ?></option>
          <?php endforeach; ?>
        </select>
        <select name="priority" class="form-select form-select-sm">
          <option value="">All Priority</option>
          <?php foreach ($priorities as $p): ?>
          <option value="<?= $p ?>" <?= $fPriority===$p?'selected':'' ?>><?= $p ?></option>
          <?php endforeach; ?>
        </select>
        <select name="employee" class="form-select form-select-sm">
          <option value="">All Employees</option>
          <?php foreach ($employees as $emp): ?>
          <option value="<?= $emp['id'] ?>" <?= $fEmployee==$emp['id']?'selected':'' ?>><?= e($emp['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="source" class="form-select form-select-sm">
          <option value="">All Sources</option>
          <?php foreach ($sources as $src): ?>
          <option value="<?= $src['id'] ?>" <?= $fSource==$src['id']?'selected':'' ?>><?= e($src['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="date" name="from" class="form-control form-control-sm" title="From date" value="<?= e($fFrom) ?>">
        <input type="date" name="to"   class="form-control form-control-sm" title="To date"   value="<?= e($fTo) ?>">
        <div class="d-flex gap-1">
          <button type="submit" class="btn btn-primary btn-sm px-3">
            <i class="bi bi-search me-1"></i>Filter
          </button>
          <a href="<?= APP_URL ?>/manager/leads.php" class="btn btn-outline-secondary btn-sm px-2">
            <i class="bi bi-x-lg"></i>
          </a>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ── Leads Card ─────────────────────────────────────────────── -->
<div class="card">

  <!--
    ════════════════════════════════════════════════════════════════
    CARD HEADER — single flex row, items right-aligned
    Left:   "Leads N" title
    Right:  [ctx-actions — hidden] | Export CSV | Add Lead
    ctx-actions slides in BETWEEN Export CSV and the title
    when at least one row is selected.
    ════════════════════════════════════════════════════════════════
  -->
  <div class="card-header leads-card-header">

    <!-- Title -->
    <span class="leads-title">
      <i class="bi bi-funnel me-2 text-primary"></i>
      Leads <span class="badge bg-primary ms-1"><?= $total ?></span>
    </span>

    <!--
      ── CONTEXT ACTIONS (appear when selection > 0) ────────────
      Order in DOM: count · sep · view/edit (single only) · sep ·
                    status-change · sep · assign · sep · delete · sep · clear
      All hidden by default; JS reveals with display:flex
    -->
    <div class="ctx-actions" id="ctxActions">

      <!-- How many selected -->
      <span class="ctx-count" id="ctxCount"><i class="bi bi-check2-square"></i> <span id="ctxNum">0</span></span>

      <div class="ctx-sep"></div>

      <!-- View / Edit — only shown when exactly 1 row selected -->
      <div id="ctxSingle" style="display:none;align-items:center;gap:.35rem;">
        <a id="ctxViewBtn" href="#" class="btn btn-sm btn-outline-primary" title="View lead">
          <i class="bi bi-eye me-1"></i><span class="d-none d-md-inline">View</span>
        </a>
        <a id="ctxEditBtn" href="#" class="btn btn-sm btn-outline-secondary" title="Edit lead">
          <i class="bi bi-pencil me-1"></i><span class="d-none d-md-inline">Edit</span>
        </a>
        <div class="ctx-sep"></div>
      </div>

      <!-- Change Status (works for 1 or many) -->
      <form method="POST" id="ctxStatusForm" class="d-flex align-items-center gap-1">
        <input type="hidden" name="csrf_token"      value="<?= csrfToken() ?>">
        <input type="hidden" name="bulk_status_ids" id="ctxStatusIds">
        <select name="bulk_new_status" id="ctxStatusSel" class="form-select ctx-select">
          <option value="" disabled selected>Status…</option>
          <?php foreach ($statuses as $s): ?>
          <option value="<?= $s ?>"><?= $s ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" id="ctxStatusBtn" class="btn btn-sm btn-outline-primary" disabled title="Apply status">
          <i class="bi bi-arrow-repeat"></i>
        </button>
      </form>

      <div class="ctx-sep"></div>

      <!-- Assign Employee (works for 1 or many) -->
      <form method="POST" id="ctxAssignForm" class="d-flex align-items-center gap-1">
        <input type="hidden" name="csrf_token"      value="<?= csrfToken() ?>">
        <input type="hidden" name="bulk_assign_ids" id="ctxAssignIds">
        <select name="bulk_assign_to" id="ctxAssignSel" class="form-select ctx-select">
          <option value="" disabled selected>Assign…</option>
          <option value="0">Unassigned</option>
          <?php foreach ($employees as $emp): ?>
          <option value="<?= $emp['id'] ?>"><?= e($emp['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" id="ctxAssignBtn" class="btn btn-sm btn-outline-secondary" disabled title="Apply assign">
          <i class="bi bi-person-check"></i>
        </button>
      </form>

      <div class="ctx-sep"></div>

      <!-- Delete (works for 1 or many) -->
      <button class="btn btn-sm btn-outline-danger" id="ctxDeleteBtn"
              data-bs-toggle="modal" data-bs-target="#bulkDeleteModal" title="Delete selected">
        <i class="bi bi-trash me-1"></i><span class="d-none d-md-inline">Delete</span>
      </button>

      <div class="ctx-sep"></div>

      <!-- Clear selection -->
      <button class="btn btn-sm btn-link text-muted p-1" id="ctxClearBtn" title="Clear selection" style="line-height:1;">
        <i class="bi bi-x-lg" style="font-size:.8rem;"></i>
      </button>

    </div>
    <!-- /ctx-actions -->

    <!-- ── Always-visible buttons ─────────────────────────────── -->
    <a href="<?= APP_URL ?>/api/export.php?type=leads&<?= http_build_query($_GET) ?>"
       class="btn btn-sm btn-outline-success" title="Export filtered leads to CSV">
      <i class="bi bi-download me-1"></i><span class="d-none d-sm-inline">Export CSV</span>
    </a>
    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal"
            data-bs-target="#importModal" title="Import leads from a CSV file">
      <i class="bi bi-upload me-1"></i><span class="d-none d-sm-inline">Import</span>
    </button>
    <a href="<?= APP_URL ?>/manager/lead-form.php" class="btn btn-sm btn-primary">
      <i class="bi bi-plus-lg me-1"></i><span class="d-none d-sm-inline">Add Lead</span>
    </a>


  </div>
  <!-- /card-header -->

  <!-- ── DESKTOP TABLE ─────────────────────────────────────────── -->
  <div class="lead-desktop-table">
    <div class="leads-table-wrap">
      <table class="table table-hover mb-0" id="leadsTable">
        <thead>
          <tr>
            <th class="col-check"><input type="checkbox" id="selectAll" class="form-check-input"></th>
            <th class="col-num">#</th>
            <th class="col-name">Name</th>
            <th class="col-phone">Phone</th>
            <th class="col-co">Company</th>
            <th class="col-svc">Service</th>
            <th class="col-pri">Priority</th>
            <th class="col-status">Status</th>
            <th class="col-assign">Assigned</th>
            <th class="col-date">Date</th>
            <!-- ✂ ACTIONS column removed — buttons live in header now -->
          </tr>
        </thead>
        <tbody>
          <?php foreach ($leads as $i => $lead): ?>
          <tr data-id="<?= $lead['id'] ?>"
              data-view="<?= APP_URL ?>/admin/lead-detail.php?id=<?= $lead['id'] ?>"
              data-edit="<?= APP_URL ?>/manager/lead-form.php?id=<?= $lead['id'] ?>"
              data-name="<?= e($lead['name']) ?>">
            <td class="col-check">
              <input type="checkbox" class="form-check-input row-check" value="<?= $lead['id'] ?>">
            </td>
            <td class="col-num text-muted"><?= $offset+$i+1 ?></td>
            <td class="col-name">
              <a href="<?= APP_URL ?>/admin/lead-detail.php?id=<?= $lead['id'] ?>"
                 class="fw-600 text-decoration-none d-block"><?= e($lead['name']) ?></a>
              <?php if ($lead['email']): ?>
              <div style="font-size:.72rem;color:var(--text-muted);"><?= e($lead['email']) ?></div>
              <?php endif; ?>
            </td>
            <td class="col-phone"><?= e($lead['phone']) ?></td>
            <td class="col-co" style="max-width:120px;overflow:hidden;text-overflow:ellipsis;"><?= e($lead['company']) ?></td>
            <td class="col-svc"><?= e($lead['service']) ?></td>
            <td class="col-pri">
              <span class="fw-700 priority-<?= e($lead['priority']) ?>" style="font-size:.75rem;"><?= e($lead['priority']) ?></span>
            </td>
            <td class="col-status">
              <select class="form-select select-inline status-select" data-id="<?= $lead['id'] ?>">
                <?php foreach ($statuses as $s): ?>
                <option value="<?= $s ?>" <?= $lead['status']===$s?'selected':'' ?>><?= $s ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td class="col-assign">
              <select class="form-select select-inline assign-select" data-id="<?= $lead['id'] ?>">
                <option value="">Unassigned</option>
                <?php foreach ($employees as $emp): ?>
                <option value="<?= $emp['id'] ?>" <?= $lead['assigned_to']==$emp['id']?'selected':'' ?>><?= e($emp['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td class="col-date" style="font-size:.75rem;"><?= date('d M y', strtotime($lead['created_at'])) ?></td>
            <!-- no <td> for actions — column removed -->
          </tr>
          <?php endforeach; ?>
          <?php if (!$leads): ?>
          <tr>
            <td colspan="10" class="text-center text-muted py-5">
              <i class="bi bi-inbox" style="font-size:2.5rem;display:block;opacity:.3;margin-bottom:.75rem;"></i>
              No leads found. <a href="<?= APP_URL ?>/manager/leads.php">Clear filters</a>
            </td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ── MOBILE CARD LIST ───────────────────────────────────────── -->
  <div class="lead-mobile-card px-2 py-2">
    <?php if ($leads): ?>
    <?php foreach ($leads as $lead): ?>
    <div class="lmc">
      <div class="lmc-top">
        <div>
          <a href="<?= APP_URL ?>/admin/lead-detail.php?id=<?= $lead['id'] ?>" class="lmc-name"><?= e($lead['name']) ?></a>
          <?php if ($lead['email']): ?>
          <div style="font-size:.72rem;color:var(--text-muted);"><?= e($lead['email']) ?></div>
          <?php endif; ?>
        </div>
        <span class="fw-700 priority-<?= e($lead['priority']) ?>" style="font-size:.72rem;white-space:nowrap;"><?= e($lead['priority']) ?></span>
      </div>
      <div class="lmc-meta">
        <?php if ($lead['phone']): ?><span><i class="bi bi-telephone"></i> <?= e($lead['phone']) ?></span><?php endif; ?>
        <?php if ($lead['company']): ?><span><i class="bi bi-building"></i> <?= e($lead['company']) ?></span><?php endif; ?>
        <?php if ($lead['service']): ?><span><i class="bi bi-tools"></i> <?= e($lead['service']) ?></span><?php endif; ?>
        <?php if ($lead['source_name']): ?><span><i class="bi bi-broadcast"></i> <?= e($lead['source_name']) ?></span><?php endif; ?>
        <span><i class="bi bi-calendar3"></i> <?= date('d M y', strtotime($lead['created_at'])) ?></span>
        <span><i class="bi bi-person"></i> <?= e($lead['assigned_name'] ?? 'Unassigned') ?></span>
      </div>
      <div class="lmc-bottom">
        <select class="form-select form-select-sm status-select" style="max-width:140px;font-size:.75rem;" data-id="<?= $lead['id'] ?>">
          <?php foreach ($statuses as $s): ?>
          <option value="<?= $s ?>" <?= $lead['status']===$s?'selected':'' ?>><?= $s ?></option>
          <?php endforeach; ?>
        </select>
        <!-- Mobile keeps inline buttons since there's no table header bar on mobile -->
        <div class="lmc-btns">
          <a href="<?= APP_URL ?>/admin/lead-detail.php?id=<?= $lead['id'] ?>" class="btn btn-xs btn-outline-primary" title="View"><i class="bi bi-eye"></i></a>
          <a href="<?= APP_URL ?>/manager/lead-form.php?id=<?= $lead['id'] ?>"   class="btn btn-xs btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>
          <button class="btn btn-xs btn-outline-danger delete-lead"
                  data-id="<?= $lead['id'] ?>" data-name="<?= e($lead['name']) ?>" title="Delete">
            <i class="bi bi-trash"></i>
          </button>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    <?php else: ?>
    <div class="text-center text-muted py-5">
      <i class="bi bi-inbox" style="font-size:2.5rem;display:block;opacity:.3;margin-bottom:.75rem;"></i>
      No leads found. <a href="<?= APP_URL ?>/manager/leads.php">Clear filters</a>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── Pagination ─────────────────────────────────────────────── -->
  <?php if ($pages > 1): ?>
  <div class="card-footer">
    <div class="pagination-wrap">
      <small class="text-muted">Showing <?= $offset+1 ?>–<?= min($offset+$perPage,$total) ?> of <?= $total ?></small>
      <nav>
        <ul class="pagination pagination-sm mb-0 flex-wrap">
          <li class="page-item <?= $page<=1?'disabled':'' ?>">
            <a class="page-link" href="?<?= http_build_query(array_merge($_GET,['p'=>$page-1])) ?>"><i class="bi bi-chevron-left"></i></a>
          </li>
          <?php
            $start = max(1, $page-3); $end = min($pages, $page+3);
            if ($start>1): ?>
              <li class="page-item"><a class="page-link" href="?<?= http_build_query(array_merge($_GET,['p'=>1])) ?>">1</a></li>
              <?php if ($start>2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif;
          endif;
          for ($pg=$start; $pg<=$end; $pg++): ?>
            <li class="page-item <?= $pg===$page?'active':'' ?>">
              <a class="page-link" href="?<?= http_build_query(array_merge($_GET,['p'=>$pg])) ?>"><?= $pg ?></a>
            </li>
          <?php endfor;
          if ($end<$pages): ?>
            <?php if ($end<$pages-1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
            <li class="page-item"><a class="page-link" href="?<?= http_build_query(array_merge($_GET,['p'=>$pages])) ?>"><?= $pages ?></a></li>
          <?php endif; ?>
          <li class="page-item <?= $page>=$pages?'disabled':'' ?>">
            <a class="page-link" href="?<?= http_build_query(array_merge($_GET,['p'=>$page+1])) ?>"><i class="bi bi-chevron-right"></i></a>
          </li>
        </ul>
      </nav>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- ── Bulk Delete Modal ──────────────────────────────────────── -->
<div class="modal fade" id="bulkDeleteModal" tabindex="-1">
  <div class="modal-dialog modal-sm modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title"><i class="bi bi-trash text-danger me-2"></i>Delete Leads</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body py-2">
        Delete <strong id="bulkCount">0</strong> selected lead(s)? This cannot be undone.
      </div>
      <div class="modal-footer border-0 pt-0">
        <form method="POST" class="d-flex gap-2">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="bulk_ids"   id="bulkIdsInput">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-trash me-1"></i>Delete</button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- ── Single Delete Modal (mobile cards) ────────────────────── -->
<div class="modal fade" id="deleteModal" tabindex="-1">
  <div class="modal-dialog modal-sm modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title"><i class="bi bi-trash text-danger me-2"></i>Delete Lead</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body py-2">Delete <strong id="deleteName"></strong>? This cannot be undone.</div>
      <div class="modal-footer border-0 pt-0 d-flex gap-2">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <a id="deleteConfirmBtn" href="#" class="btn btn-danger btn-sm"><i class="bi bi-trash me-1"></i>Delete</a>
      </div>
    </div>
  </div>
</div>

<!-- ── Import Leads Modal ───────────────────────────────────── -->
<div class="modal fade" id="importModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title"><i class="bi bi-upload text-primary me-2"></i>Import Leads</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="<?= APP_URL ?>/api/leads-import.php" enctype="multipart/form-data">
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="return_to" value="<?= APP_URL ?>/manager/leads.php">
          <p class="small text-muted mb-3">
            Upload a CSV with columns: First Name, Last Name, Phone, Email, Company, Location,
            Service, Lead Type, Lead Source, Status, Priority, Assigned To, Message.
            First Name, Last Name and Phone are required; everything else is optional.
            Each row's <strong>Lead Type</strong> column decides whether it lands in Inbound or Outbound Leads.
          </p>
          <a href="<?= APP_URL ?>/api/leads-import.php?template=1" class="small">
            <i class="bi bi-download me-1"></i>Download CSV template
          </a>
          <input type="file" name="import_file" accept=".csv" class="form-control mt-3" required>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-upload me-1"></i>Import</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
/* ================================================================
   Context Action Bar Controller
   - Watches all .row-check checkboxes
   - Shows/hides #ctxActions in the card header
   - When 1 row: shows View + Edit buttons (single-lead actions)
   - Always:     Status, Assign, Delete work on all selected
   - Clear btn resets everything
   ================================================================ */
(function () {
  'use strict';

  // ── Refs ─────────────────────────────────────────────────────
  const selectAll   = document.getElementById('selectAll');
  const ctxActions  = document.getElementById('ctxActions');
  const ctxNum      = document.getElementById('ctxNum');
  const bulkCount   = document.getElementById('bulkCount');
  const bulkIds     = document.getElementById('bulkIdsInput');
  const ctxStatusIds= document.getElementById('ctxStatusIds');
  const ctxAssignIds= document.getElementById('ctxAssignIds');
  const ctxStatusSel= document.getElementById('ctxStatusSel');
  const ctxStatusBtn= document.getElementById('ctxStatusBtn');
  const ctxAssignSel= document.getElementById('ctxAssignSel');
  const ctxAssignBtn= document.getElementById('ctxAssignBtn');
  const ctxSingle   = document.getElementById('ctxSingle');
  const ctxViewBtn  = document.getElementById('ctxViewBtn');
  const ctxEditBtn  = document.getElementById('ctxEditBtn');
  const ctxClearBtn = document.getElementById('ctxClearBtn');

  // ── Core sync ─────────────────────────────────────────────────
  function sync() {
    const checked = [...document.querySelectorAll('.row-check:checked')];
    const count   = checked.length;
    const ids     = checked.map(c => c.value);
    const idsStr  = ids.join(',');

    // Show / hide entire context bar
    ctxActions.style.display = count > 0 ? 'flex' : 'none';

    // Count labels
    if (ctxNum)    ctxNum.textContent    = count;
    if (bulkCount) bulkCount.textContent = count;

    // Hidden inputs for forms & modal
    if (bulkIds)      bulkIds.value      = idsStr;
    if (ctxStatusIds) ctxStatusIds.value = idsStr;
    if (ctxAssignIds) ctxAssignIds.value = idsStr;

    // Row highlight
    document.querySelectorAll('#leadsTable tbody tr').forEach(tr => {
      const cb = tr.querySelector('.row-check');
      tr.classList.toggle('row-selected', !!(cb && cb.checked));
    });

    // selectAll header state
    const all = document.querySelectorAll('.row-check');
    if (selectAll) {
      selectAll.indeterminate = count > 0 && count < all.length;
      selectAll.checked       = count > 0 && count === all.length;
    }

    // Single-lead actions: View + Edit
    if (count === 1) {
      const tr      = checked[0].closest('tr');
      const viewUrl = tr?.dataset.view || '#';
      const editUrl = tr?.dataset.edit || '#';
      if (ctxViewBtn) ctxViewBtn.href = viewUrl;
      if (ctxEditBtn) ctxEditBtn.href = editUrl;
      if (ctxSingle)  ctxSingle.style.display = 'flex';
    } else {
      if (ctxSingle) ctxSingle.style.display = 'none';
    }
  }

  // ── Select all ────────────────────────────────────────────────
  selectAll?.addEventListener('change', function () {
    document.querySelectorAll('.row-check').forEach(cb => cb.checked = this.checked);
    sync();
  });

  // ── Row checkbox delegation ───────────────────────────────────
  document.addEventListener('change', e => {
    if (e.target.classList.contains('row-check')) sync();
  });

  // ── Click anywhere on a table row to toggle its checkbox ──────
  document.querySelectorAll('#leadsTable tbody tr').forEach(tr => {
    tr.addEventListener('click', e => {
      if (e.target.closest('a, button, select, input, label')) return;
      const cb = tr.querySelector('.row-check');
      if (cb) { cb.checked = !cb.checked; sync(); }
    });
    // Pointer cursor hint
    tr.style.cursor = 'pointer';
  });

  // ── Enable apply buttons only when a value is chosen ─────────
  ctxStatusSel?.addEventListener('change', () => {
    if (ctxStatusBtn) ctxStatusBtn.disabled = !ctxStatusSel.value;
  });
  ctxAssignSel?.addEventListener('change', () => {
    if (ctxAssignBtn) ctxAssignBtn.disabled = (ctxAssignSel.value === '' || ctxAssignSel.value === null);
  });

  // ── Clear selection ───────────────────────────────────────────
  ctxClearBtn?.addEventListener('click', () => {
    document.querySelectorAll('.row-check').forEach(cb => cb.checked = false);
    if (selectAll) { selectAll.checked = false; selectAll.indeterminate = false; }
    if (ctxStatusSel) ctxStatusSel.selectedIndex = 0;
    if (ctxAssignSel) ctxAssignSel.selectedIndex = 0;
    if (ctxStatusBtn) ctxStatusBtn.disabled = true;
    if (ctxAssignBtn) ctxAssignBtn.disabled = true;
    sync();
  });

  // ── Mobile: single delete buttons ────────────────────────────
  document.querySelectorAll('.delete-lead').forEach(btn => {
    btn.addEventListener('click', () => {
      document.getElementById('deleteName').textContent = btn.dataset.name;
      document.getElementById('deleteConfirmBtn').href  =
        '<?= APP_URL ?>/api/leads.php?action=delete&id=' + btn.dataset.id;
      new bootstrap.Modal(document.getElementById('deleteModal')).show();
    });
  });

  // ── Inline assign select (per-row AJAX) ──────────────────────
  document.querySelectorAll('.assign-select').forEach(sel => {
    sel.addEventListener('change', async function () {
      const fd = new FormData();
      fd.append('action', 'assign');
      fd.append('id', this.dataset.id);
      fd.append('employee_id', this.value);
      try {
        const res = await fetch('<?= APP_URL ?>/api/leads.php', { method: 'POST', body: fd });
        const d   = await res.json();
        showToast(d.success ? 'success' : 'danger', d.message);
      } catch { showToast('danger', 'Network error'); }
    });
  });

  // Initial state
  sync();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>