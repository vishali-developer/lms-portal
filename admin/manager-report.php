<?php
// admin/manager-report.php — Manager performance report
// Admin: sees a selector for all managers, can drill into any one.
// Manager: always sees their own report.
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin','manager']);

$selfRole = $_SESSION['user_role'];
$selfId   = $_SESSION['user_id'];

// Decide whose report to show
if ($selfRole === 'manager') {
    $mgId = $selfId; // Managers always see themselves
} else {
    $mgId = (int)($_GET['mgr'] ?? 0);
}

// Load manager list for the selector (admin only)
$managerList = ($selfRole === 'admin')
    ? DB::fetchAll("SELECT id,name,email,profile_image FROM users WHERE role='manager' AND status='active' ORDER BY name")
    : [];

// Default to first manager if admin hasn't picked one
if ($selfRole === 'admin' && !$mgId && $managerList) {
    $mgId = $managerList[0]['id'];
}
if ($selfRole === 'admin' && !$mgId) {
    // No managers exist
    $pageTitle  = 'Manager Report';
    $activePage = 'manager-report';
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="alert alert-warning"><i class="bi bi-info-circle me-2"></i>No active managers found. <a href="' . APP_URL . '/admin/users.php">Create one</a>.</div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// Load the selected manager's record
$mgr = DB::fetchOne("SELECT * FROM users WHERE id=? AND role='manager'", [$mgId]);
if (!$mgr) {
    setFlash('danger', 'Manager not found.');
    redirect(APP_URL . '/admin/manager-report.php');
}

// ── Date range / period ───────────────────────────────────────
$period = $_GET['period'] ?? 'month';
$from   = $_GET['from']   ?? '';
$to     = $_GET['to']     ?? '';

switch ($period) {
    case 'today':    $dateFrom = date('Y-m-d');                           $dateTo = date('Y-m-d');   break;
    case 'week':     $dateFrom = date('Y-m-d', strtotime('monday this week')); $dateTo = date('Y-m-d'); break;
    case 'month':    $dateFrom = date('Y-m-01');                          $dateTo = date('Y-m-d');   break;
    case '3months':  $dateFrom = date('Y-m-d', strtotime('-3 months'));   $dateTo = date('Y-m-d');   break;
    case '6months':  $dateFrom = date('Y-m-d', strtotime('-6 months'));   $dateTo = date('Y-m-d');   break;
    case 'custom':   $dateFrom = $from ?: date('Y-m-01'); $dateTo = $to ?: date('Y-m-d');            break;
    default:         $dateFrom = date('Y-m-01');                          $dateTo = date('Y-m-d'); $period = 'month';
}
if ($dateFrom > $dateTo) $dateFrom = $dateTo;

$periodLabels = [
    'today'=>'Today','week'=>'This Week','month'=>'This Month',
    '3months'=>'Last 3 Months','6months'=>'Last 6 Months','custom'=>'Custom Range',
];

// ── Lead Type filter ──────────────────────────────────────────
$leadType  = $_GET['lead_type'] ?? '';
if (!in_array($leadType, ['Inbound Leads','Outbound Leads'], true)) $leadType = '';
$typeCond  = $leadType ? ' AND lead_type=?'   : '';
$typeCondL = $leadType ? ' AND l.lead_type=?' : '';
$leadTypeLabels = ['' => 'Total Leads', 'Inbound Leads' => 'Inbound Leads', 'Outbound Leads' => 'Outbound Leads'];

function mgrAppendType(array $params, string $cond, string $leadType): array {
    return $cond ? array_merge($params, [$leadType]) : $params;
}

// ── KPI counts ────────────────────────────────────────────────
$id  = $mgId;
$kpi = [];
$kpi['assigned']        = (int)DB::fetchOne("SELECT COUNT(*) c FROM leads WHERE assigned_to=? AND DATE(created_at) BETWEEN ? AND ?$typeCond", mgrAppendType([$id,$dateFrom,$dateTo],$typeCond,$leadType))['c'];
$kpi['converted']       = (int)DB::fetchOne("SELECT COUNT(*) c FROM leads WHERE assigned_to=? AND status='Converted' AND DATE(created_at) BETWEEN ? AND ?$typeCond", mgrAppendType([$id,$dateFrom,$dateTo],$typeCond,$leadType))['c'];
$kpi['rejected']        = (int)DB::fetchOne("SELECT COUNT(*) c FROM leads WHERE assigned_to=? AND status='Rejected' AND DATE(created_at) BETWEEN ? AND ?$typeCond", mgrAppendType([$id,$dateFrom,$dateTo],$typeCond,$leadType))['c'];
$kpi['followups_done']  = (int)DB::fetchOne("SELECT COUNT(*) c FROM followups f JOIN leads l ON l.id=f.lead_id WHERE f.employee_id=? AND DATE(f.created_at) BETWEEN ? AND ?$typeCondL", mgrAppendType([$id,$dateFrom,$dateTo],$typeCondL,$leadType))['c'];
$kpi['overdue']         = (int)DB::fetchOne("SELECT COUNT(*) c FROM followups f JOIN leads l ON l.id=f.lead_id WHERE f.employee_id=? AND f.next_followup_date < CURDATE() AND f.next_followup_date IS NOT NULL$typeCondL", mgrAppendType([$id],$typeCondL,$leadType))['c'];
$kpi['due_today']       = (int)DB::fetchOne("SELECT COUNT(*) c FROM followups f JOIN leads l ON l.id=f.lead_id WHERE f.employee_id=? AND f.next_followup_date=CURDATE()$typeCondL", mgrAppendType([$id],$typeCondL,$leadType))['c'];
$kpi['total_alltime']   = (int)DB::fetchOne("SELECT COUNT(*) c FROM leads WHERE assigned_to=?$typeCond", mgrAppendType([$id],$typeCond,$leadType))['c'];
$kpi['conv_alltime']    = (int)DB::fetchOne("SELECT COUNT(*) c FROM leads WHERE assigned_to=? AND status='Converted'$typeCond", mgrAppendType([$id],$typeCond,$leadType))['c'];
$kpi['followup_alltime']= (int)DB::fetchOne("SELECT COUNT(*) c FROM followups f JOIN leads l ON l.id=f.lead_id WHERE f.employee_id=?$typeCondL", mgrAppendType([$id],$typeCondL,$leadType))['c'];
$kpi['high_priority']   = (int)DB::fetchOne("SELECT COUNT(*) c FROM leads WHERE assigned_to=? AND priority='High' AND DATE(created_at) BETWEEN ? AND ?$typeCond", mgrAppendType([$id,$dateFrom,$dateTo],$typeCond,$leadType))['c'];
$kpi['inbound']         = (int)DB::fetchOne("SELECT COUNT(*) c FROM leads WHERE assigned_to=? AND lead_type='Inbound Leads' AND DATE(created_at) BETWEEN ? AND ?", [$id,$dateFrom,$dateTo])['c'];
$kpi['outbound']        = (int)DB::fetchOne("SELECT COUNT(*) c FROM leads WHERE assigned_to=? AND lead_type='Outbound Leads' AND DATE(created_at) BETWEEN ? AND ?", [$id,$dateFrom,$dateTo])['c'];
$convRate = $kpi['assigned'] > 0 ? round(($kpi['converted'] / $kpi['assigned']) * 100, 1) : 0;

// ── Trend chart data ─────────────────────────────────────────
$trendLabels = []; $trendAssigned = []; $trendConverted = [];
$days = max(1, (strtotime($dateTo) - strtotime($dateFrom)) / 86400);
if ($days <= 1) {
    for ($h = 0; $h <= (int)date('H'); $h++) {
        $trendLabels[]    = sprintf('%02d:00',$h);
        $trendAssigned[]  = (int)DB::fetchOne("SELECT COUNT(*) c FROM leads WHERE assigned_to=? AND DATE(created_at)=? AND HOUR(created_at)=?$typeCond", mgrAppendType([$id,$dateFrom,$h],$typeCond,$leadType))['c'];
        $trendConverted[] = (int)DB::fetchOne("SELECT COUNT(*) c FROM leads WHERE assigned_to=? AND status='Converted' AND DATE(created_at)=? AND HOUR(created_at)=?$typeCond", mgrAppendType([$id,$dateFrom,$h],$typeCond,$leadType))['c'];
    }
} elseif ($days <= 60) {
    $cur = strtotime($dateFrom); $end = strtotime($dateTo);
    while ($cur <= $end) {
        $d = date('Y-m-d',$cur);
        $trendLabels[]    = date('d M',$cur);
        $trendAssigned[]  = (int)DB::fetchOne("SELECT COUNT(*) c FROM leads WHERE assigned_to=? AND DATE(created_at)=?$typeCond", mgrAppendType([$id,$d],$typeCond,$leadType))['c'];
        $trendConverted[] = (int)DB::fetchOne("SELECT COUNT(*) c FROM leads WHERE assigned_to=? AND status='Converted' AND DATE(created_at)=?$typeCond", mgrAppendType([$id,$d],$typeCond,$leadType))['c'];
        $cur = strtotime('+1 day',$cur);
    }
} else {
    $cur = strtotime(date('Y-m-01',strtotime($dateFrom))); $end = strtotime(date('Y-m-01',strtotime($dateTo)));
    while ($cur <= $end) {
        $m = date('Y-m',$cur);
        $trendLabels[]    = date('M Y',$cur);
        $trendAssigned[]  = (int)DB::fetchOne("SELECT COUNT(*) c FROM leads WHERE assigned_to=? AND DATE_FORMAT(created_at,'%Y-%m')=?$typeCond", mgrAppendType([$id,$m],$typeCond,$leadType))['c'];
        $trendConverted[] = (int)DB::fetchOne("SELECT COUNT(*) c FROM leads WHERE assigned_to=? AND status='Converted' AND DATE_FORMAT(created_at,'%Y-%m')=?$typeCond", mgrAppendType([$id,$m],$typeCond,$leadType))['c'];
        $cur = strtotime('+1 month',$cur);
    }
}

// ── Status distribution ───────────────────────────────────────
$statusDist = DB::fetchAll("SELECT status, COUNT(*) cnt FROM leads WHERE assigned_to=? AND DATE(created_at) BETWEEN ? AND ?$typeCond GROUP BY status ORDER BY cnt DESC", mgrAppendType([$id,$dateFrom,$dateTo],$typeCond,$leadType));

// ── Source breakdown ──────────────────────────────────────────
$sourceDist = DB::fetchAll("SELECT COALESCE(s.name,'Unknown') AS sname, COUNT(*) cnt FROM leads l LEFT JOIN lead_sources s ON s.id=l.source_id WHERE l.assigned_to=? AND DATE(l.created_at) BETWEEN ? AND ?$typeCondL GROUP BY l.source_id ORDER BY cnt DESC LIMIT 8", mgrAppendType([$id,$dateFrom,$dateTo],$typeCondL,$leadType));

// ── Recent follow-ups & leads ─────────────────────────────────
$recentFollowups = DB::fetchAll("SELECT f.*, l.name AS lead_name, l.status AS lead_status, l.priority FROM followups f JOIN leads l ON l.id=f.lead_id WHERE f.employee_id=? AND DATE(f.created_at) BETWEEN ? AND ?$typeCondL ORDER BY f.created_at DESC LIMIT 10", mgrAppendType([$id,$dateFrom,$dateTo],$typeCondL,$leadType));
$recentLeads     = DB::fetchAll("SELECT l.*, COALESCE(s.name,'—') AS source_name FROM leads l LEFT JOIN lead_sources s ON s.id=l.source_id WHERE l.assigned_to=? AND DATE(l.created_at) BETWEEN ? AND ?$typeCondL ORDER BY l.created_at DESC LIMIT 8", mgrAppendType([$id,$dateFrom,$dateTo],$typeCondL,$leadType));

$pageTitle  = ($selfRole === 'manager') ? 'My Report' : 'Manager Report: ' . $mgr['name'];
$activePage = 'manager-report';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.emp-selector-list { display:flex; flex-direction:column; gap:.35rem; max-height:320px; overflow-y:auto; }
.emp-selector-item {
  display:flex; align-items:center; gap:.65rem; padding:.55rem .75rem;
  border-radius:10px; text-decoration:none; color:var(--text);
  border:1.5px solid transparent; transition:all .15s;
}
.emp-selector-item:hover  { background:var(--bg); border-color:var(--border); color:var(--text); }
.emp-selector-item.active { background:var(--primary-light); border-color:var(--primary); color:var(--primary); }
.emp-avatar {
  width:36px; height:36px; border-radius:10px; background:var(--primary-light);
  color:var(--primary); display:grid; place-items:center; font-weight:800; flex-shrink:0;
}
.emp-profile-card {
  background:linear-gradient(135deg,#1e3a5f 0%,#2563eb 100%);
  border-radius:var(--radius); padding:1.4rem 1.6rem; color:#fff; margin-bottom:1rem; position:relative; overflow:hidden;
}
.emp-profile-card::before { content:''; position:absolute; top:-30px; right:-30px; width:120px; height:120px; border-radius:50%; background:rgba(255,255,255,.07); }
.profile-avatar { width:64px; height:64px; border-radius:18px; background:rgba(255,255,255,.2); display:grid; place-items:center; font-size:1.6rem; font-weight:800; flex-shrink:0; }
.period-tabs { display:flex; gap:.3rem; flex-wrap:wrap; }
.period-btn { padding:.35rem .85rem; border-radius:20px; font-size:.78rem; font-weight:600; cursor:pointer; border:1.5px solid var(--border); background:var(--surface); color:var(--text-muted); transition:all .15s; }
.period-btn.active { background:var(--primary); color:#fff; border-color:var(--primary); }
.period-btn:hover:not(.active) { border-color:var(--primary); color:var(--primary); }
.customRange { display:none; }
.customRange.show { display:flex; }
.btn-apply { padding:.35rem .85rem; border-radius:20px; font-size:.78rem; font-weight:600; background:var(--primary); color:#fff; border:none; cursor:pointer; }
.kpi-mini-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:.6rem; }
@media(min-width:576px){ .kpi-mini-grid{ grid-template-columns:repeat(4,1fr); } }
.kpi-mini { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); padding:.7rem .85rem; text-align:center; }
.kpi-mini-num { font-size:1.35rem; font-weight:800; letter-spacing:-.02em; }
.kpi-mini-lbl { font-size:.67rem; color:var(--text-muted); margin-top:1px; }
.timeline { display:flex; flex-direction:column; gap:0; }
.timeline-item { display:flex; gap:1rem; padding-bottom:1rem; position:relative; }
.timeline-item:not(:last-child)::before { content:''; position:absolute; left:15px; top:28px; bottom:0; width:2px; background:var(--border); }
.timeline-dot { width:32px; height:32px; border-radius:50%; background:var(--primary-light); border:2px solid var(--primary); flex-shrink:0; display:grid; place-items:center; }
.timeline-body { flex:1; min-width:0; }
.timeline-date { font-size:.72rem; color:var(--text-muted); }
.badge-status { font-size:.67rem; font-weight:700; padding:.18rem .55rem; border-radius:20px; white-space:nowrap; }
.btn-xs { padding:.2rem .45rem; font-size:.73rem; border-radius:5px; }
</style>

<div class="row g-3">
  <!-- LEFT: Manager Selector (admin only) -->
  <?php if ($selfRole === 'admin' && $managerList): ?>
  <div class="col-12 col-xl-3">
    <div class="card">
      <div class="card-header"><i class="bi bi-people me-2 text-primary"></i>Select Manager</div>
      <div class="card-body p-2">
        <div class="emp-selector-list">
          <?php foreach ($managerList as $m): ?>
          <a href="?mgr=<?= $m['id'] ?>&period=<?= e($period) ?>&from=<?= e($from) ?>&to=<?= e($to) ?>&lead_type=<?= urlencode($leadType) ?>"
             class="emp-selector-item <?= $m['id']==$mgId?'active':'' ?>">
            <div class="emp-avatar">
              <?php if (!empty($m['profile_image'])): ?>
                <img src="<?= UPLOAD_URL . e($m['profile_image']) ?>" style="width:36px;height:36px;border-radius:10px;object-fit:cover;">
              <?php else: ?>
                <?= strtoupper(substr($m['name'],0,1)) ?>
              <?php endif; ?>
            </div>
            <div class="overflow-hidden">
              <div class="fw-600 small text-truncate"><?= e($m['name']) ?></div>
              <div style="font-size:.7rem;color:var(--text-muted);" class="text-truncate"><?= e($m['email']) ?></div>
            </div>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- RIGHT: Report Content -->
  <div class="col-12 <?= ($selfRole === 'admin' && $managerList) ? 'col-xl-9' : '' ?>">

    <div id="reportCaptureArea">

    <!-- Lead Type tabs -->
    <div class="card mb-3">
      <div class="card-body py-2 px-3">
        <div class="text-muted fw-700 small mb-2"><i class="bi bi-funnel me-1"></i>Lead Type</div>
        <div class="period-tabs" id="leadTypeTabs">
          <?php foreach ($leadTypeLabels as $val => $lbl): ?>
          <button class="period-btn <?= $leadType===$val?'active':'' ?>" data-leadtype="<?= e($val) ?>"><?= e($lbl) ?></button>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Period filter -->
    <div class="card mb-3">
      <div class="card-body py-2 px-3">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
          <div class="period-tabs" id="periodTabs">
            <?php foreach (['today'=>'Today','week'=>'Week','month'=>'Month','3months'=>'3 Months','6months'=>'6 Months','custom'=>'Custom'] as $pv=>$pl): ?>
            <button class="period-btn <?= $period===$pv?'active':'' ?>" data-period="<?= $pv ?>"><?= $pl ?></button>
            <?php endforeach; ?>
          </div>
          <div class="customRange gap-2 align-items-center <?= $period==='custom'?'show':'' ?>" id="customRange">
            <input type="date" id="customFrom" class="form-control form-control-sm" value="<?= e($dateFrom) ?>">
            <span class="text-muted small">to</span>
            <input type="date" id="customTo"   class="form-control form-control-sm" value="<?= e($dateTo) ?>">
            <button class="btn-apply" id="applyCustom">Apply</button>
          </div>
          <div class="d-flex gap-2">
            <button id="downloadReportBtn" class="btn btn-sm btn-outline-success">
              <i class="bi bi-camera me-1"></i>Download
            </button>
            <button class="btn btn-sm btn-outline-secondary" onclick="window.print()">
              <i class="bi bi-printer me-1"></i>Print
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Manager profile header -->
    <div class="emp-profile-card">
      <div class="d-flex align-items-center gap-3">
        <div class="profile-avatar">
          <?php if (!empty($mgr['profile_image'])): ?>
            <img src="<?= UPLOAD_URL . e($mgr['profile_image']) ?>" style="width:64px;height:64px;border-radius:18px;object-fit:cover;">
          <?php else: ?>
            <?= strtoupper(substr($mgr['name'],0,1)) ?>
          <?php endif; ?>
        </div>
        <div>
          <div style="font-size:1.2rem;font-weight:800;"><?= e($mgr['name']) ?></div>
          <div style="opacity:.8;font-size:.83rem;margin-top:2px;">
            <i class="bi bi-person-badge me-1"></i>Manager
            <?php if ($mgr['email']): ?>&bull; <?= e($mgr['email']) ?><?php endif; ?>
          </div>
          <div style="opacity:.7;font-size:.78rem;margin-top:4px;">
            <i class="bi bi-calendar3 me-1"></i><?= $periodLabels[$period] ?> &bull;
            <?= date('d M Y', strtotime($dateFrom)) ?> → <?= date('d M Y', strtotime($dateTo)) ?>
          </div>
        </div>
      </div>
      <!-- All-time strip inside profile card -->
      <div class="row g-2 mt-3">
        <?php foreach ([
          ['Total Leads (All-time)', $kpi['total_alltime']],
          ['Converted (All-time)',   $kpi['conv_alltime']],
          ['Total Follow-ups',       $kpi['followup_alltime']],
          ['Conversion Rate',        $convRate . '%'],
        ] as [$lbl, $val]): ?>
        <div class="col-6 col-md-3">
          <div style="background:rgba(255,255,255,.15);border-radius:10px;padding:.65rem 1rem;">
            <div style="font-size:1.35rem;font-weight:800;"><?= $val ?></div>
            <div style="font-size:.7rem;opacity:.8;"><?= $lbl ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Period KPI mini-grid -->
    <div class="kpi-mini-grid mb-3">
      <?php foreach ([
        ['Assigned',       $kpi['assigned'],       'bi-funnel-fill',           '#2563eb'],
        ['Converted',      $kpi['converted'],      'bi-check-circle-fill',     '#10b981'],
        ['Rejected',       $kpi['rejected'],       'bi-x-circle-fill',         '#ef4444'],
        ['High Priority',  $kpi['high_priority'],  'bi-exclamation-circle-fill','#dc2626'],
        ['Follow-ups Done',$kpi['followups_done'], 'bi-calendar-check-fill',   '#6366f1'],
        ['Overdue',        $kpi['overdue'],        'bi-alarm-fill',            '#f59e0b'],
        ['Inbound',        $kpi['inbound'],        'bi-box-arrow-in-down',     '#0891b2'],
        ['Outbound',       $kpi['outbound'],       'bi-box-arrow-up-right',    '#7c3aed'],
      ] as [$lbl, $val, $ico, $col]): ?>
      <div class="kpi-mini">
        <i class="bi <?= $ico ?>" style="color:<?= $col ?>;font-size:1rem;display:block;margin-bottom:.2rem;"></i>
        <div class="kpi-mini-num" style="color:<?= $col ?>;"><?= $val ?></div>
        <div class="kpi-mini-lbl"><?= $lbl ?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Charts row -->
    <div class="row g-3">
      <div class="col-12 col-xl-8">
        <div class="card">
          <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-graph-up-arrow me-2 text-primary"></i>Activity Trend</span>
            <span class="badge bg-primary" style="font-size:.68rem;"><?= $periodLabels[$period] ?></span>
          </div>
          <div class="card-body"><canvas id="trendChart" height="110"></canvas></div>
        </div>
      </div>
      <div class="col-12 col-xl-4">
        <div class="card h-100">
          <div class="card-header"><i class="bi bi-pie-chart-fill me-2 text-primary"></i>Status Mix</div>
          <div class="card-body d-flex flex-column align-items-center justify-content-center">
            <?php if (array_sum(array_column($statusDist,'cnt')) > 0): ?>
            <canvas id="statusChart" width="180" height="180" style="max-height:180px;"></canvas>
            <div class="mt-2 w-100" style="font-size:.78rem;">
              <?php foreach ($statusDist as $sd): ?>
              <div class="d-flex justify-content-between mb-1">
                <span><span class="me-1 status-dot-<?= md5($sd['status']) ?>">●</span><?= e($sd['status']) ?></span>
                <strong><?= $sd['cnt'] ?></strong>
              </div>
              <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="text-muted small py-4">No leads in this period.</div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Source breakdown -->
    <div class="card mt-3">
      <div class="card-header"><i class="bi bi-broadcast me-2 text-primary"></i>Lead Sources</div>
      <div class="card-body">
        <?php if ($sourceDist): $maxCnt = max(array_column($sourceDist,'cnt')); ?>
        <?php foreach ($sourceDist as $src): ?>
        <div class="d-flex align-items-center gap-2 mb-2" style="font-size:.82rem;">
          <div style="width:130px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= e($src['sname']) ?></div>
          <div class="flex-grow-1" style="background:var(--bg);border-radius:4px;height:8px;overflow:hidden;">
            <div style="width:<?= round(($src['cnt']/$maxCnt)*100) ?>%;height:100%;background:var(--primary);border-radius:4px;"></div>
          </div>
          <div style="width:24px;text-align:right;font-weight:700;"><?= $src['cnt'] ?></div>
        </div>
        <?php endforeach; ?>
        <?php else: ?>
        <div class="text-muted small text-center py-3">No lead data for this period.</div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Recent follow-ups + Recent leads -->
    <div class="row g-3 mt-0">
      <div class="col-12 col-xl-6">
        <div class="card">
          <div class="card-header"><i class="bi bi-clock-history me-2 text-primary"></i>Recent Follow-ups <span class="badge bg-primary ms-1"><?= count($recentFollowups) ?></span></div>
          <div class="card-body" style="max-height:300px;overflow-y:auto;">
            <?php if ($recentFollowups): ?>
            <div class="timeline">
              <?php foreach ($recentFollowups as $fu): ?>
              <div class="timeline-item">
                <div class="timeline-dot"><i class="bi bi-calendar-check" style="font-size:.65rem;color:var(--primary);"></i></div>
                <div class="timeline-body">
                  <div class="d-flex justify-content-between mb-1">
                    <span class="fw-600 small"><?= e($fu['lead_name']) ?></span>
                    <span class="timeline-date"><?= date('d M Y', strtotime($fu['created_at'])) ?></span>
                  </div>
                  <p class="mb-1 small text-muted" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= e($fu['note']) ?></p>
                  <?php if ($fu['next_followup_date']): ?>
                  <span class="badge bg-warning text-dark" style="font-size:.68rem;">Next: <?= date('d M Y', strtotime($fu['next_followup_date'])) ?></span>
                  <?php endif; ?>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
            <?php else: ?><div class="text-muted small text-center py-3">No follow-ups in this period.</div><?php endif; ?>
          </div>
        </div>
      </div>
      <div class="col-12 col-xl-6">
        <div class="card">
          <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-funnel me-2 text-primary"></i>Recent Leads</span>
            <a href="<?= APP_URL ?>/<?= $selfRole === 'admin' ? 'admin' : 'manager' ?>/leads.php?assigned=<?= $mgId ?>" class="btn btn-xs btn-outline-primary">View All</a>
          </div>
          <div class="card-body p-0" style="max-height:300px;overflow-y:auto;">
            <?php if ($recentLeads): ?>
            <table class="table table-hover table-sm mb-0" style="font-size:.8rem;">
              <thead><tr><th>Name</th><th>Status</th><th>Priority</th><th></th></tr></thead>
              <tbody>
                <?php foreach ($recentLeads as $l): ?>
                <tr>
                  <td class="fw-600"><?= e($l['name']) ?></td>
                  <td><span class="badge-status status-<?= str_replace(' ','-',e($l['status'])) ?>"><?= e($l['status']) ?></span></td>
                  <td><span class="fw-600 priority-<?= e($l['priority']) ?>"><?= e($l['priority']) ?></span></td>
                  <td><a href="<?= APP_URL ?>/admin/lead-detail.php?id=<?= $l['id'] ?>" class="btn btn-xs btn-outline-primary"><i class="bi bi-eye"></i></a></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <?php else: ?><div class="text-center text-muted py-4 small">No leads in this period.</div><?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    </div><!-- /#reportCaptureArea -->
  </div><!-- /col -->
</div><!-- /row -->

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script>
const STATUS_COLORS = {
  'Untouched':'#64748b','New':'#3b82f6','Contacted':'#6366f1','Follow-up':'#f59e0b',
  'Interested':'#10b981','Converted':'#22c55e','Closed':'#94a3b8','Rejected':'#ef4444',
};
const selfRole       = <?= json_encode($selfRole) ?>;
const currentMgrId   = <?= $mgId ?>;
const currentPeriod  = <?= json_encode($period) ?>;
const currentLeadType= <?= json_encode($leadType) ?>;
const dateFromVal    = <?= json_encode($dateFrom) ?>;
const dateToVal      = <?= json_encode($dateTo) ?>;

// ── Trend chart ───────────────────────────────────────────────
new Chart(document.getElementById('trendChart').getContext('2d'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($trendLabels) ?>,
    datasets: [
      { label:'Assigned',  data:<?= json_encode($trendAssigned) ?>,  backgroundColor:'rgba(37,99,235,.7)', borderRadius:4 },
      { label:'Converted', data:<?= json_encode($trendConverted) ?>, backgroundColor:'rgba(16,185,129,.7)', borderRadius:4 },
    ]
  },
  options: { responsive:true, plugins:{ legend:{ position:'top' } }, scales:{ x:{ grid:{ display:false } } } }
});

// ── Status donut ──────────────────────────────────────────────
<?php if (array_sum(array_column($statusDist,'cnt')) > 0): ?>
const statusData = <?= json_encode($statusDist) ?>;
new Chart(document.getElementById('statusChart').getContext('2d'), {
  type: 'doughnut',
  data: {
    labels: statusData.map(r=>r.status),
    datasets: [{ data:statusData.map(r=>r.cnt), backgroundColor:statusData.map(r=>STATUS_COLORS[r.status]||'#94a3b8'), borderWidth:2 }]
  },
  options: { cutout:'65%', plugins:{ legend:{ display:false } } }
});
<?php endif; ?>

// ── Period tabs ───────────────────────────────────────────────
document.querySelectorAll('#periodTabs .period-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    document.querySelectorAll('#periodTabs .period-btn').forEach(b=>b.classList.remove('active'));
    this.classList.add('active');
    const p = this.dataset.period;
    const cr = document.getElementById('customRange');
    if (p === 'custom') { cr.classList.add('show'); return; }
    cr.classList.remove('show');
    navigate(p, '', '', currentLeadType);
  });
});

document.getElementById('applyCustom').addEventListener('click', () => {
  const f = document.getElementById('customFrom').value;
  const t = document.getElementById('customTo').value;
  if (!f || !t) return;
  navigate('custom', f, t, currentLeadType);
});

// ── Lead Type tabs ────────────────────────────────────────────
document.querySelectorAll('#leadTypeTabs .period-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    document.querySelectorAll('#leadTypeTabs .period-btn').forEach(b=>b.classList.remove('active'));
    this.classList.add('active');
    navigate(currentPeriod, dateFromVal, dateToVal, this.dataset.leadtype);
  });
});

function navigate(period, from, to, leadType) {
  const base = selfRole === 'admin'
    ? '<?= APP_URL ?>/admin/manager-report.php'
    : '<?= APP_URL ?>/manager/manager-report.php';
  let url = `${base}?period=${period}`;
  if (selfRole === 'admin') url += `&mgr=${currentMgrId}`;
  if (from) url += `&from=${from}`;
  if (to)   url += `&to=${to}`;
  if (leadType) url += `&lead_type=${encodeURIComponent(leadType)}`;
  window.location.href = url;
}

// ── Download Report ───────────────────────────────────────────
document.getElementById('downloadReportBtn').addEventListener('click', async function() {
  const btn = this; const orig = btn.innerHTML;
  btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Capturing…';
  try {
    const canvas = await html2canvas(document.getElementById('reportCaptureArea'), { scale:2, useCORS:true, backgroundColor:'#ffffff' });
    const a = document.createElement('a');
    a.download = `manager-report-<?= e($mgr['name']) ?>-<?= date('Y-m-d') ?>.png`;
    a.href = canvas.toDataURL('image/png'); a.click();
  } catch(e) { console.error(e); }
  finally { btn.disabled = false; btn.innerHTML = orig; }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>