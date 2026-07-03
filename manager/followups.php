<?php
// manager/followups.php — Manager: Follow-up Management
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin','manager']);

$pageTitle  = 'Follow-up Management';
$activePage = 'followups';

$fEmp  = (int)($_GET['employee'] ?? 0);
$fDate = $_GET['date'] ?? '';
$tab   = $_GET['tab']  ?? 'today';

$where  = ['1=1'];
$params = [];
if ($fEmp)  { $where[] = 'f.employee_id=?';       $params[] = $fEmp; }
if ($fDate) { $where[] = 'f.next_followup_date=?'; $params[] = $fDate; }
$ws = implode(' AND ', $where);

$employees = DB::fetchAll("SELECT id,name FROM users WHERE role='employee' AND status='active' ORDER BY name");

$todayCount   = DB::fetchOne("SELECT COUNT(*) c FROM followups f WHERE f.next_followup_date=CURDATE() AND $ws", $params)['c'];
$overdueCount = DB::fetchOne("SELECT COUNT(*) c FROM followups f WHERE f.next_followup_date < CURDATE() AND f.next_followup_date IS NOT NULL AND $ws", $params)['c'];
$upcomingCnt  = DB::fetchOne("SELECT COUNT(*) c FROM followups f WHERE f.next_followup_date > CURDATE() AND $ws", $params)['c'];
$allCount     = DB::fetchOne("SELECT COUNT(*) c FROM followups f WHERE $ws", $params)['c'];

$baseSQL = "SELECT f.*, l.name AS lead_name, l.phone, l.status AS lead_status, l.priority,
                   u.name AS emp_name
            FROM followups f
            JOIN leads l ON l.id=f.lead_id
            JOIN users u ON u.id=f.employee_id
            WHERE $ws";

switch ($tab) {
    case 'overdue':
        $rows = DB::fetchAll("$baseSQL AND f.next_followup_date < CURDATE() AND f.next_followup_date IS NOT NULL ORDER BY f.next_followup_date ASC", $params);
        break;
    case 'upcoming':
        $rows = DB::fetchAll("$baseSQL AND f.next_followup_date > CURDATE() ORDER BY f.next_followup_date ASC LIMIT 50", $params);
        break;
    case 'all':
        $rows = DB::fetchAll("$baseSQL ORDER BY f.created_at DESC LIMIT 100", $params);
        break;
    default:
        $rows = DB::fetchAll("$baseSQL AND f.next_followup_date = CURDATE() ORDER BY f.created_at DESC", $params);
        $tab  = 'today';
}

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ── Filter grid ───────────────────────────────────────── */
.fu-filter-grid {
  display:grid; gap:.5rem;
  grid-template-columns: 1fr 1fr;
}
@media(min-width:576px){ .fu-filter-grid{ grid-template-columns:2fr 1fr auto; } }
@media(min-width:768px){ .fu-filter-grid{ grid-template-columns:2fr 1fr 1fr auto; } }

/* ── Stat cards as tab links ───────────────────────────── */
.fu-stat-tabs { display:grid; grid-template-columns:repeat(2,1fr); gap:.6rem; margin-bottom:1rem; }
@media(min-width:576px){ .fu-stat-tabs{ grid-template-columns:repeat(4,1fr); } }

.fu-stat-tab {
  display:flex; align-items:center; gap:.65rem;
  background:var(--surface); border:2px solid var(--border);
  border-radius:var(--radius); padding:.75rem 1rem;
  text-decoration:none; color:var(--text);
  transition:border-color .15s, box-shadow .15s;
  cursor:pointer;
}
.fu-stat-tab:hover        { border-color:var(--primary); box-shadow:var(--shadow-md); color:var(--text); }
.fu-stat-tab.active       { border-color:var(--primary); background:var(--primary-light); }
.fu-stat-tab.active-red   { border-color:#ef4444; background:#fee2e2; }
.fu-stat-tab.active-amber { border-color:#f59e0b; background:#fffbeb; }
.fu-stat-tab.active-green { border-color:#10b981; background:#d1fae5; }

.fst-icon {
  width:40px; height:40px; border-radius:12px;
  display:grid; place-items:center; font-size:1.1rem; flex-shrink:0;
}
.fst-icon.red    { background:#fee2e2; color:#ef4444; }
.fst-icon.amber  { background:#fffbeb; color:#f59e0b; }
.fst-icon.blue   { background:#eff6ff; color:#3b82f6; }
.fst-icon.green  { background:#d1fae5; color:#10b981; }
.fst-num  { font-size:1.4rem; font-weight:800; line-height:1; letter-spacing:-.02em; }
.fst-lbl  { font-size:.72rem; color:var(--text-muted); margin-top:1px; }

/* ── Desktop table / Mobile card toggle ───────────────── */
.fu-desktop { display:block; }
.fu-mobile  { display:none; }
@media(max-width:767px) {
  .fu-desktop { display:none; }
  .fu-mobile  { display:block; padding:.5rem; }
}

/* ── Mobile follow-up card ─────────────────────────────── */
.fum-card {
  background:var(--surface); border:1px solid var(--border);
  border-radius:var(--radius); padding:.85rem 1rem; margin-bottom:.55rem;
  border-left:3px solid var(--primary);
}
.fum-card.overdue { border-left-color:#ef4444; background:rgba(239,68,68,.03); }
.fum-header  { display:flex; justify-content:space-between; align-items:flex-start;
               gap:.5rem; margin-bottom:.4rem; }
.fum-name    { font-weight:700; font-size:.88rem; }
.fum-meta    { display:flex; flex-wrap:wrap; gap:.25rem .65rem;
               font-size:.74rem; color:var(--text-muted); margin-bottom:.45rem; }
.fum-note    { font-size:.78rem; color:var(--text); background:var(--bg);
               border-radius:6px; padding:.4rem .6rem; margin-bottom:.45rem;
               border-left:2px solid var(--primary); }
.fum-footer  { display:flex; justify-content:space-between; align-items:center;
               flex-wrap:wrap; gap:.35rem; }
.fum-date    { font-size:.75rem; font-weight:600; }
.fum-date.overdue { color:#ef4444; }
.fum-date.upcoming { color:#10b981; }
.fum-date.today    { color:#f59e0b; }

/* ── Pagination wrap ───────────────────────────────────── */
.pagi-wrap { display:flex; justify-content:space-between; align-items:center;
             flex-wrap:wrap; gap:.5rem; }

.btn-xs { padding:.2rem .45rem; font-size:.73rem; border-radius:5px; }
</style>

<!-- Filter bar -->
<div class="card mb-3">
  <div class="card-body py-2 px-3">
    <form method="GET">
      <input type="hidden" name="tab" value="<?= e($tab) ?>">
      <div class="fu-filter-grid">
        <select name="employee" class="form-select form-select-sm">
          <option value="">All Employees</option>
          <?php foreach ($employees as $emp): ?>
          <option value="<?= $emp['id'] ?>" <?= $fEmp==$emp['id']?'selected':'' ?>>
            <?= e($emp['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <input type="date" name="date" class="form-control form-control-sm" value="<?= e($fDate) ?>">
        <div class="d-none d-md-block"><!-- spacer on mobile --></div>
        <div class="d-flex gap-1">
          <button type="submit" class="btn btn-primary btn-sm flex-fill">
            <i class="bi bi-search me-1"></i>Filter
          </button>
          <a href="<?= APP_URL ?>/manager/followups.php" class="btn btn-outline-secondary btn-sm px-2" title="Reset">
            <i class="bi bi-x-lg"></i>
          </a>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Tab stat cards -->
<div class="fu-stat-tabs">
  <a href="?tab=overdue<?= $fEmp?"&employee=$fEmp":'' ?><?= $fDate?"&date=$fDate":'' ?>"
     class="fu-stat-tab <?= $tab==='overdue'?'active-red':'' ?>">
    <div class="fst-icon red"><i class="bi bi-exclamation-circle-fill"></i></div>
    <div><div class="fst-num"><?= $overdueCount ?></div><div class="fst-lbl">Overdue</div></div>
  </a>
  <a href="?tab=today<?= $fEmp?"&employee=$fEmp":'' ?><?= $fDate?"&date=$fDate":'' ?>"
     class="fu-stat-tab <?= $tab==='today'?'active-amber':'' ?>">
    <div class="fst-icon amber"><i class="bi bi-calendar-day"></i></div>
    <div><div class="fst-num"><?= $todayCount ?></div><div class="fst-lbl">Today</div></div>
  </a>
  <a href="?tab=upcoming<?= $fEmp?"&employee=$fEmp":'' ?><?= $fDate?"&date=$fDate":'' ?>"
     class="fu-stat-tab <?= $tab==='upcoming'?'active':'' ?>">
    <div class="fst-icon blue"><i class="bi bi-calendar-week"></i></div>
    <div><div class="fst-num"><?= $upcomingCnt ?></div><div class="fst-lbl">Upcoming</div></div>
  </a>
  <a href="?tab=all<?= $fEmp?"&employee=$fEmp":'' ?><?= $fDate?"&date=$fDate":'' ?>"
     class="fu-stat-tab <?= $tab==='all'?'active-green':'' ?>">
    <div class="fst-icon green"><i class="bi bi-list-check"></i></div>
    <div><div class="fst-num"><?= $allCount ?></div><div class="fst-lbl">All</div></div>
  </a>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span class="fw-700" style="font-size:.87rem;">
      <i class="bi bi-calendar-check me-2 text-primary"></i>
      <?= ucfirst($tab) ?> Follow-ups
    </span>
    <span class="badge bg-primary"><?= count($rows) ?></span>
  </div>

  <!-- Desktop table -->
  <div class="fu-desktop">
    <div class="table-responsive">
      <table class="table table-hover mb-0" style="font-size:.82rem;">
        <thead>
          <tr>
            <th>#</th><th>Lead</th><th>Phone</th><th>Employee</th>
            <th>Note</th><th>Next Date</th><th>Priority</th><th>Status</th>
            <th>Added</th><th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $i => $f): ?>
          <?php $isOverdue = $f['next_followup_date'] && strtotime($f['next_followup_date']) < strtotime('today'); ?>
          <tr <?= $isOverdue ? 'class="table-danger"' : '' ?>>
            <td class="text-muted"><?= $i+1 ?></td>
            <td class="fw-600"><?= e($f['lead_name']) ?></td>
            <td><?= e($f['phone']) ?></td>
            <td><?= e($f['emp_name']) ?></td>
            <td class="text-muted" style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
              <?= e($f['note']) ?>
            </td>
            <td style="white-space:nowrap;">
              <?php if ($f['next_followup_date']): ?>
              <span class="fw-600 <?= $isOverdue?'text-danger':'' ?>">
                <?= date('d M Y', strtotime($f['next_followup_date'])) ?>
              </span>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td>
              <span class="fw-600 priority-<?= e($f['priority']) ?>"><?= e($f['priority']) ?></span>
            </td>
            <td>
              <span class="badge-status status-<?= str_replace(' ','-',e($f['lead_status'])) ?>">
                <?= e($f['lead_status']) ?>
              </span>
            </td>
            <td class="text-muted" style="white-space:nowrap;">
              <?= date('d M y', strtotime($f['created_at'])) ?>
            </td>
            <td>
              <a href="<?= APP_URL ?>/admin/lead-detail.php?id=<?= $f['lead_id'] ?>"
                 class="btn btn-xs btn-outline-primary" title="View Lead">
                <i class="bi bi-eye"></i>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?>
          <tr>
            <td colspan="10" class="text-center text-muted py-5">
              <i class="bi bi-calendar-check" style="font-size:2.5rem;display:block;opacity:.25;margin-bottom:.75rem;"></i>
              No follow-ups in this category.
            </td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Mobile cards -->
  <div class="fu-mobile">
    <?php if ($rows): ?>
      <?php foreach ($rows as $f):
        $isOverdue  = $f['next_followup_date'] && strtotime($f['next_followup_date']) < strtotime('today');
        $isToday    = $f['next_followup_date'] && $f['next_followup_date'] === date('Y-m-d');
        $isUpcoming = $f['next_followup_date'] && strtotime($f['next_followup_date']) > strtotime('today');
        $dateClass  = $isOverdue ? 'overdue' : ($isToday ? 'today' : ($isUpcoming ? 'upcoming' : ''));
      ?>
      <div class="fum-card <?= $isOverdue ? 'overdue' : '' ?>">
        <div class="fum-header">
          <div>
            <div class="fum-name"><?= e($f['lead_name']) ?></div>
            <div style="font-size:.73rem;color:var(--text-muted);"><?= e($f['emp_name']) ?></div>
          </div>
          <div class="d-flex flex-column align-items-end gap-1">
            <span class="fw-600 priority-<?= e($f['priority']) ?>" style="font-size:.72rem;">
              <?= e($f['priority']) ?>
            </span>
            <span class="badge-status status-<?= str_replace(' ','-',e($f['lead_status'])) ?>">
              <?= e($f['lead_status']) ?>
            </span>
          </div>
        </div>
        <div class="fum-meta">
          <?php if ($f['phone']): ?>
          <span><i class="bi bi-telephone me-1"></i><?= e($f['phone']) ?></span>
          <?php endif; ?>
          <span><i class="bi bi-calendar3 me-1"></i>
            Added <?= date('d M y', strtotime($f['created_at'])) ?>
          </span>
        </div>
        <?php if ($f['note']): ?>
        <div class="fum-note"><?= e(mb_strimwidth($f['note'], 0, 120, '…')) ?></div>
        <?php endif; ?>
        <div class="fum-footer">
          <?php if ($f['next_followup_date']): ?>
          <span class="fum-date <?= $dateClass ?>">
            <i class="bi bi-alarm me-1"></i>
            <?php if ($isOverdue): ?>Overdue:
            <?php elseif ($isToday): ?>Today:
            <?php else: ?>Next: <?php endif; ?>
            <?= date('d M Y', strtotime($f['next_followup_date'])) ?>
          </span>
          <?php else: ?>
          <span class="text-muted small">No next date set</span>
          <?php endif; ?>
          <a href="<?= APP_URL ?>/admin/lead-detail.php?id=<?= $f['lead_id'] ?>"
             class="btn btn-xs btn-outline-primary">
            <i class="bi bi-eye me-1"></i>View Lead
          </a>
        </div>
      </div>
      <?php endforeach; ?>
    <?php else: ?>
    <div class="text-center text-muted py-5">
      <i class="bi bi-calendar-check" style="font-size:2.5rem;display:block;opacity:.25;margin-bottom:.75rem;"></i>
      No follow-ups in this category.
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>