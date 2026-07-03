<?php
// admin/employee-report.php
// Admin / manager: all employees list + drill into any individual
// Employee: own report only (the ?emp= override is ignored for this role below)
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin','manager','employee']);

$role = $_SESSION['user_role'];
$uid  = $_SESSION['user_id'];

// Decide whose report to show
if ($role === 'employee') {
    // Employees always see only themselves — ?emp= is intentionally never read for this role
    $empId = $uid;
} else {  
    // Admin / manager: ?emp=ID or default to first employee
    $empId = (int)($_GET['emp'] ?? 0);
}

$pageTitle  = 'Employee Report';
$activePage = 'employee-report';

// ── Period resolution ─────────────────────────────────────────
$period = $_GET['period'] ?? 'month';
$from   = $_GET['from']   ?? '';
$to     = $_GET['to']     ?? '';

switch ($period) {
    case 'today':
        $dateFrom = date('Y-m-d'); $dateTo = date('Y-m-d'); break;
    case 'week':
        $dateFrom = date('Y-m-d', strtotime('monday this week')); $dateTo = date('Y-m-d'); break;
    case 'month':
        $dateFrom = date('Y-m-01'); $dateTo = date('Y-m-d'); break;
    case '3months':
        $dateFrom = date('Y-m-d', strtotime('-3 months')); $dateTo = date('Y-m-d'); break;
    case '6months':
        $dateFrom = date('Y-m-d', strtotime('-6 months')); $dateTo = date('Y-m-d'); break;
    case 'custom':
        $dateFrom = $from ?: date('Y-m-01'); $dateTo = $to ?: date('Y-m-d'); break;
    default:
        $dateFrom = date('Y-m-01'); $dateTo = date('Y-m-d'); $period = 'month';
}
if ($dateFrom > $dateTo) $dateFrom = $dateTo;

$periodLabels = [
    'today'=>'Today','week'=>'This Week','month'=>'This Month',
    '3months'=>'Last 3 Months','6months'=>'Last 6 Months','custom'=>'Custom Range',
];

// ── Lead Type filter (Total / Inbound / Outbound) ──────────────
$leadType = $_GET['lead_type'] ?? '';
if (!in_array($leadType, ['Inbound Leads','Outbound Leads'], true)) {
    $leadType = '';
}
$typeCond  = $leadType ? ' AND lead_type=?'   : ''; // queries against the bare `leads` table
$typeCondL = $leadType ? ' AND l.lead_type=?' : ''; // queries that alias leads as l
$leadTypeLabels = ['' => 'Total Leads', 'Inbound Leads' => 'Inbound Leads', 'Outbound Leads' => 'Outbound Leads'];

function appendType(array $params, string $cond, string $leadType): array {
    return $cond ? array_merge($params, [$leadType]) : $params;
}

// ── Employee list for admin selector ─────────────────────────
$allEmployees = [];
if (in_array($role, ['admin','manager'])) {
    $allEmployees = DB::fetchAll(
        "SELECT id, name, email, profile_image FROM users
         WHERE role='employee' AND status='active' ORDER BY name"
    );
    if (!$empId && $allEmployees) {
        $empId = $allEmployees[0]['id'];
    }
}

// ── Fetch the selected employee ───────────────────────────────
$emp = null;
if ($empId) {
    $emp = DB::fetchOne(
        "SELECT id, name, email, phone, profile_image, created_at, last_login
         FROM users WHERE id=? AND role='employee'", [$empId]
    );
}
// Security: employee can only see themselves
if ($role === 'employee' && $emp && $emp['id'] != $uid) {
    redirect(APP_URL . '/admin/employee-report.php');
}

// ── Core KPIs ─────────────────────────────────────────────────
$kpi = [];
if ($emp) {
    $id = $emp['id'];

    // Period-filtered
    $kpi['assigned']  = (int)DB::fetchOne(
        "SELECT COUNT(*) c FROM leads WHERE assigned_to=?
         AND DATE(created_at) BETWEEN ? AND ?$typeCond",
        appendType([$id,$dateFrom,$dateTo], $typeCond, $leadType))['c'];
    $kpi['converted'] = (int)DB::fetchOne(
        "SELECT COUNT(*) c FROM leads WHERE assigned_to=? AND status='Converted'
         AND DATE(created_at) BETWEEN ? AND ?$typeCond",
        appendType([$id,$dateFrom,$dateTo], $typeCond, $leadType))['c'];
    $kpi['rejected']  = (int)DB::fetchOne(
        "SELECT COUNT(*) c FROM leads WHERE assigned_to=? AND status='Rejected'
         AND DATE(created_at) BETWEEN ? AND ?$typeCond",
        appendType([$id,$dateFrom,$dateTo], $typeCond, $leadType))['c'];
    $kpi['new']       = (int)DB::fetchOne(
        "SELECT COUNT(*) c FROM leads WHERE assigned_to=? AND status='New'
         AND DATE(created_at) BETWEEN ? AND ?$typeCond",
        appendType([$id,$dateFrom,$dateTo], $typeCond, $leadType))['c'];
    $kpi['contacted'] = (int)DB::fetchOne(
        "SELECT COUNT(*) c FROM leads WHERE assigned_to=? AND status='Contacted'
         AND DATE(created_at) BETWEEN ? AND ?$typeCond",
        appendType([$id,$dateFrom,$dateTo], $typeCond, $leadType))['c'];
    $kpi['followup']  = (int)DB::fetchOne(
        "SELECT COUNT(*) c FROM leads WHERE assigned_to=? AND status='Follow-up'
         AND DATE(created_at) BETWEEN ? AND ?$typeCond",
        appendType([$id,$dateFrom,$dateTo], $typeCond, $leadType))['c'];
    $kpi['interested']= (int)DB::fetchOne(
        "SELECT COUNT(*) c FROM leads WHERE assigned_to=? AND status='Interested'
         AND DATE(created_at) BETWEEN ? AND ?$typeCond",
        appendType([$id,$dateFrom,$dateTo], $typeCond, $leadType))['c'];
    $kpi['closed']    = (int)DB::fetchOne(
        "SELECT COUNT(*) c FROM leads WHERE assigned_to=? AND status='Closed'
         AND DATE(created_at) BETWEEN ? AND ?$typeCond",
        appendType([$id,$dateFrom,$dateTo], $typeCond, $leadType))['c'];

    // Follow-ups done in period
    $kpi['followups_done'] = (int)DB::fetchOne(
        "SELECT COUNT(*) c FROM followups f JOIN leads l ON l.id=f.lead_id
         WHERE f.employee_id=? AND DATE(f.created_at) BETWEEN ? AND ?$typeCondL",
        appendType([$id,$dateFrom,$dateTo], $typeCondL, $leadType))['c'];

    // Overdue follow-ups (always current)
    $kpi['overdue'] = (int)DB::fetchOne(
        "SELECT COUNT(*) c FROM followups f JOIN leads l ON l.id=f.lead_id
         WHERE f.employee_id=?
         AND f.next_followup_date < CURDATE() AND f.next_followup_date IS NOT NULL$typeCondL",
        appendType([$id], $typeCondL, $leadType))['c'];

    // Today's scheduled follow-ups
    $kpi['due_today'] = (int)DB::fetchOne(
        "SELECT COUNT(*) c FROM followups f JOIN leads l ON l.id=f.lead_id
         WHERE f.employee_id=? AND f.next_followup_date=CURDATE()$typeCondL",
        appendType([$id], $typeCondL, $leadType))['c'];

    // Conversion rate
    $kpi['conv_rate'] = $kpi['assigned'] > 0
        ? round(($kpi['converted'] / $kpi['assigned']) * 100, 1) : 0;

    // All-time totals
    $kpi['total_alltime'] = (int)DB::fetchOne(
        "SELECT COUNT(*) c FROM leads WHERE assigned_to=?$typeCond",
        appendType([$id], $typeCond, $leadType))['c'];
    $kpi['conv_alltime']  = (int)DB::fetchOne(
        "SELECT COUNT(*) c FROM leads WHERE assigned_to=? AND status='Converted'$typeCond",
        appendType([$id], $typeCond, $leadType))['c'];
    $kpi['followup_alltime'] = (int)DB::fetchOne(
        "SELECT COUNT(*) c FROM followups f JOIN leads l ON l.id=f.lead_id
         WHERE f.employee_id=?$typeCondL",
        appendType([$id], $typeCondL, $leadType))['c'];

    // High priority leads assigned
    $kpi['high_priority'] = (int)DB::fetchOne(
        "SELECT COUNT(*) c FROM leads WHERE assigned_to=? AND priority='High'
         AND DATE(created_at) BETWEEN ? AND ?$typeCond",
        appendType([$id,$dateFrom,$dateTo], $typeCond, $leadType))['c'];

    // Inbound / Outbound split — intentionally NOT filtered by the Lead Type tab,
    // since this pair of cards is itself the breakdown the tab lets you drill into.
    $kpi['inbound']  = (int)DB::fetchOne(
        "SELECT COUNT(*) c FROM leads WHERE assigned_to=? AND lead_type='Inbound Leads'
         AND DATE(created_at) BETWEEN ? AND ?", [$id,$dateFrom,$dateTo])['c'];
    $kpi['outbound'] = (int)DB::fetchOne(
        "SELECT COUNT(*) c FROM leads WHERE assigned_to=? AND lead_type='Outbound Leads'
         AND DATE(created_at) BETWEEN ? AND ?", [$id,$dateFrom,$dateTo])['c'];

    // ── Daily trend for chart ─────────────────────────────────
    $trendLabels = []; $trendAssigned = []; $trendConverted = [];

    // Choose grouping based on period
    $days = max(1, (strtotime($dateTo) - strtotime($dateFrom)) / 86400);
    if ($days <= 1) {
        for ($h = 0; $h <= (int)date('H'); $h++) {
            $trendLabels[]    = sprintf('%02d:00', $h);
            $trendAssigned[]  = (int)DB::fetchOne(
                "SELECT COUNT(*) c FROM leads WHERE assigned_to=?
                 AND DATE(created_at)=? AND HOUR(created_at)=?$typeCond",
                appendType([$id, $dateFrom, $h], $typeCond, $leadType))['c'];
            $trendConverted[] = (int)DB::fetchOne(
                "SELECT COUNT(*) c FROM leads WHERE assigned_to=? AND status='Converted'
                 AND DATE(created_at)=? AND HOUR(created_at)=?$typeCond",
                appendType([$id, $dateFrom, $h], $typeCond, $leadType))['c'];
        }
    } elseif ($days <= 60) {
        $cur = strtotime($dateFrom);
        $end = strtotime($dateTo);
        while ($cur <= $end) {
            $d = date('Y-m-d', $cur);
            $trendLabels[]    = date('d M', $cur);
            $trendAssigned[]  = (int)DB::fetchOne(
                "SELECT COUNT(*) c FROM leads WHERE assigned_to=? AND DATE(created_at)=?$typeCond",
                appendType([$id, $d], $typeCond, $leadType))['c'];
            $trendConverted[] = (int)DB::fetchOne(
                "SELECT COUNT(*) c FROM leads WHERE assigned_to=? AND status='Converted'
                 AND DATE(created_at)=?$typeCond", appendType([$id, $d], $typeCond, $leadType))['c'];
            $cur = strtotime('+1 day', $cur);
        }
    } else {
        $cur = strtotime(date('Y-m-01', strtotime($dateFrom)));
        $end = strtotime(date('Y-m-01', strtotime($dateTo)));
        while ($cur <= $end) {
            $m = date('Y-m', $cur);
            $trendLabels[]    = date('M Y', $cur);
            $trendAssigned[]  = (int)DB::fetchOne(
                "SELECT COUNT(*) c FROM leads WHERE assigned_to=?
                 AND DATE_FORMAT(created_at,'%Y-%m')=?$typeCond",
                appendType([$id, $m], $typeCond, $leadType))['c'];
            $trendConverted[] = (int)DB::fetchOne(
                "SELECT COUNT(*) c FROM leads WHERE assigned_to=? AND status='Converted'
                 AND DATE_FORMAT(created_at,'%Y-%m')=?$typeCond",
                appendType([$id, $m], $typeCond, $leadType))['c'];
            $cur = strtotime('+1 month', $cur);
        }
    }

    // ── Status distribution ───────────────────────────────────
    $statusDist = DB::fetchAll(
        "SELECT status, COUNT(*) cnt FROM leads
         WHERE assigned_to=? AND DATE(created_at) BETWEEN ? AND ?$typeCond
         GROUP BY status ORDER BY cnt DESC",
        appendType([$id, $dateFrom, $dateTo], $typeCond, $leadType)
    );

    // ── Source breakdown ──────────────────────────────────────
    $sourceDist = DB::fetchAll(
        "SELECT COALESCE(s.name,'Unknown') AS sname, COUNT(*) cnt
         FROM leads l LEFT JOIN lead_sources s ON s.id=l.source_id
         WHERE l.assigned_to=? AND DATE(l.created_at) BETWEEN ? AND ?$typeCondL
         GROUP BY l.source_id ORDER BY cnt DESC LIMIT 8",
        appendType([$id, $dateFrom, $dateTo], $typeCondL, $leadType)
    );

    // ── Recent follow-ups ─────────────────────────────────────
    $recentFollowups = DB::fetchAll(
        "SELECT f.*, l.name AS lead_name, l.status AS lead_status, l.priority
         FROM followups f JOIN leads l ON l.id=f.lead_id
         WHERE f.employee_id=? AND DATE(f.created_at) BETWEEN ? AND ?$typeCondL
         ORDER BY f.created_at DESC LIMIT 10",
        appendType([$id, $dateFrom, $dateTo], $typeCondL, $leadType)
    );

    // ── Recent leads ──────────────────────────────────────────
    $recentLeads = DB::fetchAll(
        "SELECT l.*, COALESCE(s.name,'—') AS source_name
         FROM leads l LEFT JOIN lead_sources s ON s.id=l.source_id
         WHERE l.assigned_to=? AND DATE(l.created_at) BETWEEN ? AND ?$typeCondL
         ORDER BY l.created_at DESC LIMIT 8",
        appendType([$id, $dateFrom, $dateTo], $typeCondL, $leadType)
    );
}

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ── Employee selector sidebar (admin only) ───────────────── */
.emp-selector {
  display: flex; flex-direction: column; gap: .35rem;
  max-height: 480px; overflow-y: auto;
  scrollbar-width: thin; scrollbar-color: var(--border) transparent;
}
.emp-selector-item {
  display: flex; align-items: center; gap: .6rem;
  padding: .55rem .75rem; border-radius: 8px;
  text-decoration: none; color: var(--text);
  border: 1px solid transparent; transition: all .13s;
}
.emp-selector-item:hover { background: var(--bg); border-color: var(--border); color: var(--text); }
.emp-selector-item.active { background: var(--primary-light); border-color: var(--primary); color: var(--primary); }
.emp-sel-avatar {
  width: 34px; height: 34px; border-radius: 50%; background: var(--primary);
  color: #fff; font-weight: 700; font-size: .82rem;
  display: grid; place-items: center; flex-shrink: 0;
}
.emp-sel-name  { font-weight: 600; font-size: .83rem; }
.emp-sel-email { font-size: .7rem; color: var(--text-muted); }

/* ── Period filter tabs ───────────────────────────────────── */
.period-tabs { display: flex; gap: .3rem; flex-wrap: wrap; }
.period-btn {
  padding: .3rem .8rem; border-radius: 20px;
  border: 1.5px solid var(--border); background: transparent;
  color: var(--text-muted); font-size: .76rem; font-weight: 600;
  cursor: pointer; transition: all .15s; font-family: var(--font);
}
.period-btn:hover  { border-color:var(--primary); color:var(--primary); background:var(--primary-light); }
.period-btn.active { border-color:var(--primary); background:var(--primary); color:#fff; }
.custom-range { display:none; align-items:center; gap:.4rem; flex-wrap:wrap; margin-top:.5rem; }
.custom-range.show { display:flex; }
.custom-range input {
  font-size:.76rem; padding:.28rem .5rem; border-radius:8px;
  border:1.5px solid var(--border); background:var(--surface); color:var(--text); max-width:130px;
}
.custom-range input:focus { outline:none; border-color:var(--primary); }
.btn-apply {
  padding:.28rem .7rem; font-size:.76rem; font-weight:600;
  border-radius:8px; border:none; background:var(--primary); color:#fff; cursor:pointer;
}

/* ── Profile header card ──────────────────────────────────── */
.emp-profile-card {
  background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 100%);
  border-radius: var(--radius);
  padding: 1.5rem;
  color: #fff;
  position: relative;
  overflow: hidden;
}
.emp-profile-card::before {
  content: '';
  position: absolute; right: -30px; top: -30px;
  width: 160px; height: 160px;
  background: rgba(255,255,255,.07);
  border-radius: 50%;
}
.emp-profile-card::after {
  content: '';
  position: absolute; right: 40px; bottom: -50px;
  width: 120px; height: 120px;
  background: rgba(255,255,255,.05);
  border-radius: 50%;
}
.ep-avatar {
  width: 64px; height: 64px; border-radius: 50%;
  background: rgba(255,255,255,.2);
  border: 3px solid rgba(255,255,255,.4);
  display: grid; place-items: center;
  font-size: 1.6rem; font-weight: 800; flex-shrink: 0;
  overflow: hidden;
}
.ep-avatar img { width: 100%; height: 100%; object-fit: cover; }
.ep-name  { font-size: 1.2rem; font-weight: 800; }
.ep-meta  { font-size: .78rem; opacity: .8; }
.ep-badge {
  display: inline-block; background: rgba(255,255,255,.2);
  padding: .2rem .7rem; border-radius: 20px;
  font-size: .7rem; font-weight: 700; letter-spacing: .05em;
}
.ep-stat {
  text-align: center; padding: .5rem .75rem;
  border-left: 1px solid rgba(255,255,255,.15);
}
.ep-stat:first-child { border: none; }
.ep-stat-val { font-size: 1.5rem; font-weight: 800; line-height: 1; }
.ep-stat-lbl { font-size: .67rem; opacity: .75; margin-top: 2px; }

/* ── KPI cards ────────────────────────────────────────────── */
.kpi-card {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--radius); padding: 1rem 1.1rem;
  display: flex; align-items: center; gap: .85rem;
  box-shadow: var(--shadow-sm); transition: transform .15s, box-shadow .15s;
}
.kpi-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
.kpi-icon {
  width: 44px; height: 44px; border-radius: 12px;
  display: grid; place-items: center; font-size: 1.2rem; flex-shrink: 0;
}
.kpi-val  { font-size: 1.6rem; font-weight: 800; line-height: 1; letter-spacing: -.02em; }
.kpi-lbl  { font-size: .72rem; color: var(--text-muted); margin-top: 2px; }

/* ── Progress bar ─────────────────────────────────────────── */
.prog-bar-wrap { height: 7px; background: var(--border); border-radius: 4px; overflow: hidden; }
.prog-bar-fill { height: 100%; border-radius: 4px; transition: width .6s ease; }

/* ── Timeline ─────────────────────────────────────────────── */
.fu-timeline { position: relative; padding-left: 1.5rem; }
.fu-timeline::before {
  content: ''; position: absolute; left: 7px; top: 4px; bottom: 0;
  width: 2px; background: var(--border);
}
.fu-item   { position: relative; margin-bottom: 1rem; }
.fu-dot    {
  position: absolute; left: -1.5rem; top: 4px;
  width: 14px; height: 14px; border-radius: 50%;
  background: var(--primary); border: 2px solid var(--surface);
}
.fu-body   {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: 8px; padding: .65rem .9rem;
}
.fu-lead   { font-weight: 600; font-size: .83rem; }
.fu-note   { font-size: .76rem; color: var(--text-muted); margin-top: 2px; }
.fu-date   { font-size: .7rem; color: var(--text-muted); }

/* ── No employee selected ─────────────────────────────────── */
.no-emp-state {
  text-align: center; padding: 4rem 2rem;
  color: var(--text-muted);
}
.no-emp-state i { font-size: 3rem; opacity: .2; display: block; margin-bottom: 1rem; }

/* ── Responsive ───────────────────────────────────────────── */
@media(max-width:767px) {
  .ep-stat-val { font-size: 1.2rem; }
  .kpi-val     { font-size: 1.35rem; }
  .period-btn  { font-size: .7rem; padding: .25rem .6rem; }
}
</style>

<?php if (!$emp && in_array($role,['admin','manager'])): ?>
<!-- No employees yet -->
<div class="no-emp-state">
  <i class="bi bi-people"></i>
  <h5>No Active Employees</h5>
  <p class="small">Add employees first from the Employees page.</p>
  <a href="<?= APP_URL ?>/admin/employees.php" class="btn btn-primary btn-sm">
    <i class="bi bi-person-plus me-1"></i>Add Employee
  </a>
</div>
<?php exit; endif; ?>

<div class="row g-3">

  <!-- ══════════════════════════════════════════════════════════
       LEFT: Employee Selector (admin only)
  ══════════════════════════════════════════════════════════ -->
  <?php if (in_array($role,['admin','manager'])): ?>
  <div class="col-12 col-xl-3">
    <div class="card">
      <div class="card-header fw-700" style="font-size:.85rem;">
        <i class="bi bi-people me-2 text-primary"></i>Select Employee
      </div>
      <div class="card-body p-2">
        <div class="emp-selector">
          <?php foreach ($allEmployees as $e):
            $lc = (int)DB::fetchOne("SELECT COUNT(*) c FROM leads WHERE assigned_to=?",[$e['id']])['c'];
          ?>
          <a href="?emp=<?= $e['id'] ?>&period=<?= e($period) ?>&from=<?= e($from) ?>&to=<?= e($to) ?>&lead_type=<?= urlencode($leadType) ?>"
             class="emp-selector-item <?= $e['id']==$empId?'active':'' ?>">
            <div class="emp-sel-avatar">
              <?php if (!empty($e['profile_image'])): ?>
              <img src="<?= UPLOAD_URL . e($e['profile_image']) ?>" alt="">
              <?php else: ?>
              <?= strtoupper(substr($e['name'],0,1)) ?>
              <?php endif; ?>
            </div>
            <div class="overflow-hidden">
              <div class="emp-sel-name text-truncate"><?= e($e['name']) ?></div>
              <div class="emp-sel-email text-truncate"><?= e($e['email']) ?></div>
            </div>
            <span class="badge bg-primary ms-auto flex-shrink-0"
                  style="font-size:.63rem;"><?= $lc ?></span>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ══════════════════════════════════════════════════════════
       RIGHT: Report Content
  ══════════════════════════════════════════════════════════ -->
  <div class="col-12 <?= in_array($role,['admin','manager'])?'col-xl-9':'' ?>">

    <!-- Everything inside gets screenshotted by Download Report -->
    <div id="reportCaptureArea">

    <!-- Employee profile header -->
    <div class="emp-profile-card mb-3">
      <div class="d-flex align-items-center gap-3 mb-3" style="position:relative;z-index:1;">
        <div class="ep-avatar">
          <?php if (!empty($emp['profile_image'])): ?>
          <img src="<?= UPLOAD_URL . e($emp['profile_image']) ?>" alt="">
          <?php else: ?>
          <?= strtoupper(substr($emp['name'],0,1)) ?>
          <?php endif; ?>
        </div>
        <div>
          <div class="ep-name"><?= e($emp['name']) ?></div>
          <div class="ep-meta mb-1"><?= e($emp['email']) ?></div>
          <span class="ep-badge">Employee</span>
          <?php if ($emp['phone']): ?>
          <span class="ep-badge ms-1"><i class="bi bi-telephone me-1"></i><?= e($emp['phone']) ?></span>
          <?php endif; ?>
        </div>
        <div class="ms-auto d-flex flex-wrap" style="position:relative;z-index:1;">
          <div class="ep-stat">
            <div class="ep-stat-val"><?= $kpi['total_alltime'] ?></div>
            <div class="ep-stat-lbl">All-time Leads</div>
          </div>
          <div class="ep-stat">
            <div class="ep-stat-val"><?= $kpi['conv_alltime'] ?></div>
            <div class="ep-stat-lbl">All-time Conv.</div>
          </div>
          <div class="ep-stat">
            <div class="ep-stat-val"><?= $kpi['followup_alltime'] ?></div>
            <div class="ep-stat-lbl">Total Follow-ups</div>
          </div>
          <?php if ($emp['last_login']): ?>
          <div class="ep-stat">
            <div class="ep-stat-val" style="font-size:.85rem;"><?= date('d M', strtotime($emp['last_login'])) ?></div>
            <div class="ep-stat-lbl">Last Login</div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Lead Type filter -->
    <div class="card mb-3">
      <div class="card-body py-2 px-3">
        <div class="text-muted small fw-700 mb-2">
          <i class="bi bi-funnel me-1"></i>Lead Type
        </div>
        <div class="period-tabs" id="leadTypeTabs">
          <?php foreach ($leadTypeLabels as $val => $lbl): ?>
          <button class="period-btn <?= $leadType===$val?'active':'' ?>" data-leadtype="<?= e($val) ?>">
            <?= e($lbl) ?>
          </button>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Period filter -->
    <div class="card mb-3">
      <div class="card-body py-2 px-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
          <div>
            <div class="text-muted small fw-700 mb-2">
              <i class="bi bi-calendar3 me-1"></i>Report Period
            </div>
            <div class="period-tabs" id="periodTabs">
              <?php foreach ($periodLabels as $k=>$lbl): ?>
              <button class="period-btn <?= $period===$k?'active':'' ?>"
                      data-period="<?= $k ?>">
                <?= $k==='custom'?'<i class="bi bi-calendar-range me-1"></i>':'' ?><?= $lbl ?>
              </button>
              <?php endforeach; ?>
            </div>
            <div class="custom-range <?= $period==='custom'?'show':'' ?>" id="customRange">
              <input type="date" id="customFrom" max="<?= date('Y-m-d') ?>"
                     value="<?= e($dateFrom) ?>">
              <span class="text-muted small">→</span>
              <input type="date" id="customTo" max="<?= date('Y-m-d') ?>"
                     value="<?= e($dateTo) ?>">
              <button class="btn-apply" id="applyCustom">Apply</button>
            </div>
          </div>
          <div class="d-flex gap-2">
            <button id="downloadReportBtn" class="btn btn-sm btn-outline-success">
              <i class="bi bi-camera me-1"></i>Download Report
            </button>
            <button class="btn btn-sm btn-outline-secondary" onclick="window.print()">
              <i class="bi bi-printer me-1"></i>Print
            </button>
          </div>
        </div>
      </div>
      <!-- Period label strip -->
      <div class="px-3 py-2 border-top d-flex align-items-center gap-3"
           style="background:var(--primary-light);font-size:.78rem;">
        <i class="bi bi-calendar-range text-primary"></i>
        <span class="fw-600 text-primary"><?= $periodLabels[$period] ?></span>
        <span class="text-muted">
          <?= date('d M Y', strtotime($dateFrom)) ?> → <?= date('d M Y', strtotime($dateTo)) ?>
        </span>
      </div>
    </div>

    <!-- ── KPI Grid ──────────────────────────────────────── -->
    <div class="row g-2 mb-3">
      <?php
      $kpiCards = [
        ['val'=>$kpi['assigned'],     'lbl'=>'Leads Assigned',    'icon'=>'funnel-fill',           'bg'=>'#eff6ff','ic'=>'#2563eb'],
        ['val'=>$kpi['converted'],    'lbl'=>'Converted',         'icon'=>'check-circle-fill',     'bg'=>'#dcfce7','ic'=>'#16a34a'],
        ['val'=>$kpi['conv_rate'].'%','lbl'=>'Conversion Rate',   'icon'=>'graph-up-arrow',        'bg'=>'#fffbeb','ic'=>'#d97706'],
        ['val'=>$kpi['followups_done'],'lbl'=>'Follow-ups Done',  'icon'=>'chat-text-fill',        'bg'=>'#f0fdf4','ic'=>'#15803d'],
        ['val'=>$kpi['followup'],     'lbl'=>'In Follow-up',      'icon'=>'calendar-check-fill',   'bg'=>'#fef3c7','ic'=>'#b45309'],
        ['val'=>$kpi['interested'],   'lbl'=>'Interested',        'icon'=>'star-fill',             'bg'=>'#fdf4ff','ic'=>'#9333ea'],
        ['val'=>$kpi['rejected'],     'lbl'=>'Rejected',          'icon'=>'x-circle-fill',         'bg'=>'#fee2e2','ic'=>'#dc2626'],
        ['val'=>$kpi['overdue'],      'lbl'=>'Overdue Follow-ups','icon'=>'exclamation-circle-fill','bg'=>'#fff1f2','ic'=>'#e11d48'],
        ['val'=>$kpi['due_today'],    'lbl'=>'Due Today',         'icon'=>'alarm-fill',            'bg'=>'#fff7ed','ic'=>'#ea580c'],
        ['val'=>$kpi['high_priority'],'lbl'=>'High Priority',     'icon'=>'lightning-fill',        'bg'=>'#fef2f2','ic'=>'#b91c1c'],
        ['val'=>$kpi['inbound'],      'lbl'=>'Inbound Leads',     'icon'=>'arrow-down-circle-fill','bg'=>'#eff6ff','ic'=>'#1d4ed8'],
        ['val'=>$kpi['outbound'],     'lbl'=>'Outbound Leads',    'icon'=>'arrow-up-circle-fill',  'bg'=>'#fffbeb','ic'=>'#92400e'],
      ];
      foreach ($kpiCards as $card):
      ?>
      <div class="col-6 col-sm-4 col-xl-3">
        <div class="kpi-card">
          <div class="kpi-icon" style="background:<?= $card['bg'] ?>;color:<?= $card['ic'] ?>;">
            <i class="bi bi-<?= $card['icon'] ?>"></i>
          </div>
          <div>
            <div class="kpi-val"><?= $card['val'] ?></div>
            <div class="kpi-lbl"><?= $card['lbl'] ?></div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- ── Lead Status Progress Bars ──────────────────────── -->
    <div class="card mb-3">
      <div class="card-header fw-700" style="font-size:.86rem;">
        <i class="bi bi-bar-chart-steps me-2 text-primary"></i>Lead Status Breakdown
      </div>
      <div class="card-body">
        <?php
        $statusMap = [
          'New'=>['#3b82f6','#dbeafe'],'Contacted'=>['#6366f1','#e0e7ff'],
          'Follow-up'=>['#f59e0b','#fef3c7'],'Interested'=>['#10b981','#d1fae5'],
          'Converted'=>['#16a34a','#dcfce7'],'Closed'=>['#64748b','#f1f5f9'],
          'Rejected'=>['#ef4444','#fee2e2'],
        ];
        $total = $kpi['assigned'] ?: 1;
        $statusVals = [
          'New'=>$kpi['new'],'Contacted'=>$kpi['contacted'],
          'Follow-up'=>$kpi['followup'],'Interested'=>$kpi['interested'],
          'Converted'=>$kpi['converted'],'Closed'=>$kpi['closed'],
          'Rejected'=>$kpi['rejected'],
        ];
        ?>
        <div class="row g-3">
          <?php foreach ($statusVals as $st => $cnt):
            [$fillC,$bgC] = $statusMap[$st];
            $pct = round(($cnt / $total) * 100, 1);
          ?>
          <div class="col-12 col-sm-6">
            <div class="d-flex justify-content-between align-items-center mb-1">
              <span style="font-size:.8rem;font-weight:600;"><?= $st ?></span>
              <span style="font-size:.78rem;color:var(--text-muted);">
                <?= $cnt ?> <span style="opacity:.6;">(<?= $pct ?>%)</span>
              </span>
            </div>
            <div class="prog-bar-wrap">
              <div class="prog-bar-fill"
                   style="width:<?= $pct ?>%;background:<?= $fillC ?>;"
                   data-target="<?= $pct ?>"></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- ── Charts Row ─────────────────────────────────────── -->
    <div class="row g-3 mb-3">
      <!-- Trend chart -->
      <div class="col-12 col-xl-8">
        <div class="card h-100">
          <div class="card-header fw-700" style="font-size:.86rem;">
            <i class="bi bi-bar-chart-line me-2 text-primary"></i>Activity Trend
          </div>
          <div class="card-body">
            <?php if (!empty($trendAssigned) && array_sum($trendAssigned) > 0): ?>
            <canvas id="trendChart" height="100"></canvas>
            <?php else: ?>
            <div class="text-center text-muted py-4">
              <i class="bi bi-bar-chart-line" style="font-size:2rem;opacity:.2;display:block;margin-bottom:.5rem;"></i>
              No lead activity in this period
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Status donut -->
      <div class="col-12 col-xl-4">
        <div class="card h-100">
          <div class="card-header fw-700" style="font-size:.86rem;">
            <i class="bi bi-pie-chart me-2 text-primary"></i>Status Mix
          </div>
          <div class="card-body d-flex flex-column align-items-center justify-content-center p-2">
            <?php if (!empty($statusDist)): ?>
            <div style="position:relative;max-width:170px;width:100%;">
              <canvas id="statusChart"></canvas>
              <div style="position:absolute;inset:0;display:flex;flex-direction:column;
                          align-items:center;justify-content:center;pointer-events:none;">
                <span style="font-size:1.5rem;font-weight:800;"><?= $kpi['assigned'] ?></span>
                <span style="font-size:.67rem;color:var(--text-muted);">leads</span>
              </div>
            </div>
            <ul style="list-style:none;padding:0;margin:.75rem 0 0;font-size:.72rem;width:100%;">
              <?php foreach ($statusDist as $s):
                [$fc] = $statusMap[$s['status']] ?? ['#94a3b8'];
                $p = $kpi['assigned'] > 0 ? round($s['cnt']/$kpi['assigned']*100) : 0;
              ?>
              <li class="d-flex align-items-center gap-2 py-1 border-bottom">
                <span style="width:9px;height:9px;background:<?= $fc ?>;border-radius:50%;flex-shrink:0;"></span>
                <span class="flex-fill text-muted"><?= $s['status'] ?></span>
                <span class="fw-700"><?= $s['cnt'] ?></span>
                <span class="text-muted"><?= $p ?>%</span>
              </li>
              <?php endforeach; ?>
            </ul>
            <?php else: ?>
            <div class="text-center text-muted py-3">
              <i class="bi bi-pie-chart" style="font-size:2rem;opacity:.2;display:block;margin-bottom:.5rem;"></i>
              No data
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Source breakdown + Follow-up timeline ──────────── -->
    <div class="row g-3 mb-3">
      <!-- Source chart -->
      <div class="col-12 col-xl-6">
        <div class="card h-100">
          <div class="card-header fw-700" style="font-size:.86rem;">
            <i class="bi bi-broadcast me-2 text-primary"></i>Lead Sources
          </div>
          <div class="card-body">
            <?php if (!empty($sourceDist)): ?>
            <div style="min-height:<?= count($sourceDist)*36 ?>px;position:relative;">
              <canvas id="sourceChart"></canvas>
            </div>
            <?php else: ?>
            <div class="text-center text-muted py-4">
              <i class="bi bi-broadcast" style="font-size:2rem;opacity:.2;display:block;margin-bottom:.5rem;"></i>
              No source data
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Follow-up timeline -->
      <div class="col-12 col-xl-6">
        <div class="card h-100">
          <div class="card-header d-flex justify-content-between align-items-center">
            <span class="fw-700" style="font-size:.86rem;">
              <i class="bi bi-clock-history me-2 text-primary"></i>Recent Follow-ups
            </span>
            <span class="badge bg-primary"><?= count($recentFollowups) ?></span>
          </div>
          <div class="card-body" style="max-height:320px;overflow-y:auto;">
            <?php if ($recentFollowups): ?>
            <div class="fu-timeline">
              <?php foreach ($recentFollowups as $fu):
                [$fc] = $statusMap[$fu['lead_status']] ?? ['#94a3b8'];
              ?>
              <div class="fu-item">
                <div class="fu-dot" style="background:<?= $fc ?>;"></div>
                <div class="fu-body">
                  <div class="d-flex justify-content-between align-items-start gap-1">
                    <span class="fu-lead"><?= e($fu['lead_name']) ?></span>
                    <span class="fu-date"><?= date('d M', strtotime($fu['created_at'])) ?></span>
                  </div>
                  <div class="fu-note text-truncate-2"><?= e($fu['note']) ?></div>
                  <?php if ($fu['next_followup_date']): ?>
                  <div class="mt-1">
                    <span class="badge" style="font-size:.63rem;background:var(--primary-light);color:var(--primary);">
                      <i class="bi bi-calendar me-1"></i>
                      Next: <?= date('d M Y', strtotime($fu['next_followup_date'])) ?>
                    </span>
                  </div>
                  <?php endif; ?>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="text-center text-muted py-3">
              <i class="bi bi-chat-text" style="font-size:2rem;opacity:.2;display:block;margin-bottom:.5rem;"></i>
              No follow-ups in this period
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Recent Leads Table ──────────────────────────────── -->
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-700" style="font-size:.86rem;">
          <i class="bi bi-funnel me-2 text-primary"></i>Recent Leads in Period
        </span>
        <?php if (in_array($role,['admin','manager'])): ?>
        <a href="<?= APP_URL ?>/admin/leads.php?employee=<?= $empId ?>"
           class="btn btn-xs btn-outline-primary">View All</a>
        <?php endif; ?>
      </div>
      <div class="card-body p-0">
        <!-- Desktop -->
        <div class="d-none d-md-block">
          <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:.82rem;">
              <thead>
                <tr><th>#</th><th>Name</th><th>Phone</th><th>Service</th>
                    <th>Source</th><th>Priority</th><th>Status</th><th>Date</th></tr>
              </thead>
              <tbody>
                <?php foreach ($recentLeads as $i => $l): ?>
                <tr>
                  <td><?= $i+1 ?></td>
                  <td>
                    <a href="<?= APP_URL ?>/admin/lead-detail.php?id=<?= $l['id'] ?>"
                       class="fw-600 text-decoration-none"><?= e($l['name']) ?></a>
                  </td>
                  <td><?= e($l['phone']) ?></td>
                  <td><?= e($l['service']) ?></td>
                  <td><?= e($l['source_name']) ?></td>
                  <td><span class="fw-700 priority-<?= e($l['priority']) ?>"><?= e($l['priority']) ?></span></td>
                  <td>
                    <?php [$bg,$co] = $statusMap[$l['status']] ?? ['#f1f5f9','#475569']; ?>
                    <span class="badge-status" style="background:<?= $bg ?>;color:<?= $co ?>;">
                      <?= e($l['status']) ?>
                    </span>
                  </td>
                  <td><?= date('d M y', strtotime($l['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$recentLeads): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">
                  <i class="bi bi-inbox" style="font-size:2rem;display:block;opacity:.2;margin-bottom:.5rem;"></i>
                  No leads in this period.
                </td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
        <!-- Mobile cards -->
        <div class="d-md-none p-2">
          <?php foreach ($recentLeads as $l):
            [$bg,$co] = $statusMap[$l['status']] ?? ['#f1f5f9','#475569'];
          ?>
          <div class="d-flex justify-content-between align-items-start p-2 border-bottom">
            <div>
              <a href="<?= APP_URL ?>/admin/lead-detail.php?id=<?= $l['id'] ?>"
                 class="fw-700 text-decoration-none d-block" style="font-size:.85rem;">
                <?= e($l['name']) ?>
              </a>
              <div style="font-size:.73rem;color:var(--text-muted);">
                <?= e($l['phone']) ?> · <?= e($l['service']) ?> · <?= date('d M y', strtotime($l['created_at'])) ?>
              </div>
            </div>
            <div class="d-flex flex-column align-items-end gap-1">
              <span class="fw-700 priority-<?= e($l['priority']) ?>" style="font-size:.71rem;"><?= e($l['priority']) ?></span>
              <span class="badge-status" style="background:<?= $bg ?>;color:<?= $co ?>;font-size:.65rem;"><?= e($l['status']) ?></span>
            </div>
          </div>
          <?php endforeach; ?>
          <?php if (!$recentLeads): ?>
          <div class="text-center text-muted py-4 small">No leads in this period.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    </div><!-- /#reportCaptureArea -->

  </div><!-- /col report -->
</div><!-- /row -->

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
const STATUS_COLORS = {
  'New':'#3b82f6','Contacted':'#6366f1','Follow-up':'#f59e0b',
  'Interested':'#10b981','Converted':'#22c55e','Closed':'#94a3b8','Rejected':'#ef4444'
};
const SOURCE_PAL = ['#2563eb','#7c3aed','#db2777','#d97706','#059669','#0891b2','#dc2626','#84cc16'];
const gridCol = () => document.documentElement.getAttribute('data-bs-theme') ==='dark'
  ? 'rgba(255,255,255,.07)':'rgba(148,163,184,.15)';

// ── Trend Chart ───────────────────────────────────────────────
<?php if (!empty($trendAssigned) && array_sum($trendAssigned) > 0): ?>
new Chart(document.getElementById('trendChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($trendLabels) ?>,
    datasets: [
      {
        label: 'Assigned',
        data:  <?= json_encode($trendAssigned) ?>,
        backgroundColor: '#3b82f6',
        borderRadius: 4, borderSkipped: false, order: 2,
      },
      {
        label: 'Converted',
        data:  <?= json_encode($trendConverted) ?>,
        backgroundColor: '#22c55e',
        borderRadius: 4, borderSkipped: false, order: 1,
      }
    ]
  },
  options: {
    responsive: true, maintainAspectRatio: true,
    animation: { duration: 450 },
    plugins: { legend: { position:'bottom', labels:{ boxWidth:10, font:{size:11} } } },
    scales: {
      y: { beginAtZero:true, ticks:{stepSize:1,precision:0}, grid:{color:gridCol()} },
      x: { grid:{display:false}, ticks:{maxRotation:40,font:{size:10},maxTicksLimit:18} }
    }
  }
});
<?php endif; ?>

// ── Status Donut ──────────────────────────────────────────────
<?php if (!empty($statusDist)): ?>
new Chart(document.getElementById('statusChart'), {
  type: 'doughnut',
  data: {
    labels: <?= json_encode(array_column($statusDist,'status')) ?>,
    datasets: [{
      data: <?= json_encode(array_column($statusDist,'cnt')) ?>,
      backgroundColor: <?= json_encode(array_map(fn($r)=>$statusMap[$r['status']][0]??'#94a3b8',$statusDist)) ?>,
      borderWidth: 2,
      borderColor: document.documentElement.getAttribute('data-bs-theme')==='dark'?'#161b22':'#ffffff',
      hoverOffset: 6
    }]
  },
  options: {
    cutout: '70%', animation:{duration:450},
    plugins:{ legend:{display:false},
      tooltip:{callbacks:{label:c=>` ${c.label}: ${c.parsed}`}} }
  }
});
<?php endif; ?>

// ── Source Chart ──────────────────────────────────────────────
<?php if (!empty($sourceDist)): ?>
const srcWrap = document.getElementById('sourceChart');
if (srcWrap) {
  srcWrap.parentElement.style.minHeight = (<?= count($sourceDist) ?> * 38 + 20) + 'px';
  new Chart(srcWrap, {
    type: 'bar',
    data: {
      labels: <?= json_encode(array_column($sourceDist,'sname')) ?>,
      datasets: [{
        label: 'Leads',
        data: <?= json_encode(array_column($sourceDist,'cnt')) ?>,
        backgroundColor: <?= json_encode(array_map(fn($i)=>$i < 8 ? ['#2563eb','#7c3aed','#db2777','#d97706','#059669','#0891b2','#dc2626','#84cc16'][$i] : '#94a3b8', array_keys($sourceDist))) ?>,
        borderRadius: 4, borderSkipped: false
      }]
    },
    options: {
      indexAxis: 'y', responsive: true, maintainAspectRatio: false,
      animation:{duration:450},
      plugins:{legend:{display:false}},
      scales:{
        x:{beginAtZero:true,ticks:{stepSize:1,precision:0},grid:{color:gridCol()}},
        y:{grid:{display:false},ticks:{font:{size:11}}}
      }
    }
  });
}
<?php endif; ?>

// ── Period & Lead Type tab navigation ──────────────────────────
const empId              = <?= $empId ?>;
const currentPeriodVal    = <?= json_encode($period) ?>;
const currentLeadTypeVal  = <?= json_encode($leadType) ?>;
const dateFromVal         = <?= json_encode($dateFrom) ?>;
const dateToVal           = <?= json_encode($dateTo) ?>;

document.querySelectorAll('#periodTabs .period-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    document.querySelectorAll('#periodTabs .period-btn').forEach(b=>b.classList.remove('active'));
    this.classList.add('active');
    const p = this.dataset.period;
    const cr = document.getElementById('customRange');
    if (p === 'custom') { cr.classList.add('show'); return; }
    cr.classList.remove('show');
    navigatePeriod(p, '', '', currentLeadTypeVal);
  });
});

document.querySelectorAll('#leadTypeTabs .period-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    document.querySelectorAll('#leadTypeTabs .period-btn').forEach(b=>b.classList.remove('active'));
    this.classList.add('active');
    const lt = this.dataset.leadtype;
    if (currentPeriodVal === 'custom') {
      navigatePeriod('custom', dateFromVal, dateToVal, lt);
    } else {
      navigatePeriod(currentPeriodVal, '', '', lt);
    }
  });
});

document.getElementById('applyCustom').addEventListener('click', () => {
  const f = document.getElementById('customFrom').value;
  const t = document.getElementById('customTo').value;
  if (!f || !t) { showToast('warning','Select both dates.'); return; }
  if (f > t)    { showToast('warning','Start must be before end.'); return; }
  navigatePeriod('custom', f, t, currentLeadTypeVal);
});

function navigatePeriod(period, from, to, leadType) {
  const base = '<?= APP_URL ?>/admin/employee-report.php';
  let url = `${base}?emp=${empId}&period=${period}`;
  if (from)    url += `&from=${from}`;
  if (to)      url += `&to=${to}`;
  if (leadType) url += `&lead_type=${encodeURIComponent(leadType)}`;
  window.location.href = url;
}

// ── Download Report — full screenshot of the capture area ─────
const downloadBtn = document.getElementById('downloadReportBtn');
if (downloadBtn) {
  downloadBtn.addEventListener('click', async function () {
    const btn = this;
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Capturing…';

    try {
      const target = document.getElementById('reportCaptureArea');
      const isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
      const canvas = await html2canvas(target, {
        scale: 2,
        useCORS: true,
        backgroundColor: isDark ? '#0d1117' : '#ffffff'
      });
      const link = document.createElement('a');
      const typeSlug = currentLeadTypeVal ? currentLeadTypeVal.toLowerCase().replace(/\s+/g, '-') + '-' : '';
      const empSlug  = <?= json_encode($emp ? strtolower(preg_replace('/\s+/', '-', $emp['name'])) : 'employee') ?>;
      link.download = `${typeSlug}${empSlug}-report-${new Date().toISOString().slice(0, 10)}.png`;
      link.href = canvas.toDataURL('image/png');
      link.click();
    } catch (e) {
      console.error('Report capture failed:', e);
      showToast('danger', 'Could not capture the report. Please try again.');
    } finally {
      btn.disabled = false;
      btn.innerHTML = original;
    }
  });
}
}); // end DOMContentLoaded
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>