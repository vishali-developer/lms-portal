<?php
// dashboard.php — Main dashboard (Mobile Responsive)
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$pageTitle  = 'Dashboard';
$activePage = 'dashboard';
$user = currentUser();
$role = $_SESSION['user_role'];
$uid  = $_SESSION['user_id'];

if (in_array($role, ['admin','manager'])) {
    $totalLeads     = DB::fetchOne("SELECT COUNT(*) c FROM leads")['c'];
    $newLeads       = DB::fetchOne("SELECT COUNT(*) c FROM leads WHERE status='New'")['c'];
    $convertedLeads = DB::fetchOne("SELECT COUNT(*) c FROM leads WHERE status='Converted'")['c'];
    $totalEmployees = DB::fetchOne("SELECT COUNT(*) c FROM users WHERE role='employee'")['c'];
    $totalClients      = DB::fetchOne("SELECT COUNT(*) c FROM clients")['c'];
    $activeClientValue = (float)DB::fetchOne("SELECT COALESCE(SUM(contract_value),0) v FROM clients WHERE status='Active'")['v'];
} else {
    $totalLeads     = DB::fetchOne("SELECT COUNT(*) c FROM leads WHERE assigned_to=?",[$uid])['c'];
    $newLeads       = DB::fetchOne("SELECT COUNT(*) c FROM leads WHERE assigned_to=? AND status='New'",[$uid])['c'];
    $convertedLeads = DB::fetchOne("SELECT COUNT(*) c FROM leads WHERE assigned_to=? AND status='Converted'",[$uid])['c'];
    $totalEmployees = null;
    $totalClients      = DB::fetchOne("SELECT COUNT(*) c FROM clients WHERE account_manager=?",[$uid])['c'];
    $activeClientValue = (float)DB::fetchOne("SELECT COALESCE(SUM(contract_value),0) v FROM clients WHERE account_manager=? AND status='Active'",[$uid])['v'];
}
$pendingFollowups = DB::fetchOne(
    "SELECT COUNT(*) c FROM followups WHERE next_followup_date <= CURDATE()" .
    ($role==='employee' ? " AND employee_id=$uid" : ''))['c'];

$recentSQL = "SELECT l.*, u.name AS assigned_name, s.name AS source_name
              FROM leads l
              LEFT JOIN users u ON u.id = l.assigned_to
              LEFT JOIN lead_sources s ON s.id = l.source_id";
if ($role === 'employee') $recentSQL .= " WHERE l.assigned_to = $uid";
$recentSQL .= " ORDER BY l.created_at DESC LIMIT 8";
$recentLeads = DB::fetchAll($recentSQL);

require_once __DIR__ . '/includes/header.php';
?>

<style>
/* ── Period filter ─────────────────────────────────────── */
.period-tabs { display:flex; gap:1.5rem; flex-wrap:wrap; }
.period-btn {
  padding:.3rem .75rem; border-radius:20px;
  border:1.5px solid var(--border); background:transparent;
  color:var(--text-muted); font-size:.75rem; font-weight:600;
  cursor:pointer; transition:all .15s; white-space:nowrap;
  font-family:var(--font);
}
.period-btn:hover  { border-color:var(--primary);color:var(--primary);background:var(--primary-light); }
.period-btn.active { border-color:var(--primary);background:var(--primary);color:#fff; }
.custom-range { display:none; align-items:center; gap:.4rem; flex-wrap:wrap; margin-top:.4rem; }
.custom-range.show { display:flex; }
.custom-range input {
  font-size:.76rem; padding:.28rem .5rem; border-radius:8px;
  border:1.5px solid var(--border); background:var(--surface); color:var(--text);
  max-width:130px; width:100%;
}
.custom-range input:focus { outline:none; border-color:var(--primary); }
.btn-apply {
  padding:.28rem .7rem; font-size:.76rem; font-weight:600;
  border-radius:8px; border:none; background:var(--primary); color:#fff; cursor:pointer;
}

/* ── Period mini stats ─────────────────────────────────── */
.period-stats { display:flex; gap:2rem; flex-wrap:wrap; }
.ps-item { display:flex; flex-direction:column; }
.ps-val  { font-size:1.2rem; font-weight:800; line-height:1; letter-spacing:-.02em; }
.ps-lbl  { font-size:.67rem; color:var(--text-muted); margin-top:1px; }

/* ── Chart loading ─────────────────────────────────────── */
.chart-wrap { position:relative; }
.chart-loading {
  position:absolute; inset:0; display:none; z-index:10;
  align-items:center; justify-content:center;
  background:rgba(255,255,255,.75); border-radius:var(--radius);
  backdrop-filter:blur(2px);
}
[data-bs-theme="dark"] .chart-loading { background:rgba(22,27,34,.75); }
.chart-loading.show { display:flex; }
.chart-empty {
  display:none; flex-direction:column; align-items:center;
  justify-content:center; min-height:140px;
  color:var(--text-muted); font-size:.8rem; gap:.4rem;
}
.chart-empty i { font-size:1.8rem; opacity:.22; }

/* ── Status legend ─────────────────────────────────────── */
.status-legend { list-style:none; padding:0; margin:0; font-size:.74rem; }
.status-legend li {
  display:flex; align-items:center; gap:.4rem;
  padding:.22rem 0; border-bottom:1px solid var(--border);
}
.status-legend li:last-child { border:none; }
.sl-dot  { width:9px; height:9px; border-radius:50%; flex-shrink:0; }
.sl-name { flex:1; color:var(--text-muted); }
.sl-cnt  { font-weight:700; }
.sl-pct  { color:var(--text-muted); min-width:32px; text-align:right; }

/* ── Donut centre ──────────────────────────────────────── */
.donut-wrap { position:relative; max-width:175px; width:100%; }
.donut-center {
  position:absolute; inset:0; pointer-events:none;
  display:flex; flex-direction:column;
  align-items:center; justify-content:center; line-height:1.2;
}
.donut-center-val { font-size:1.5rem; font-weight:800; }
.donut-center-lbl { font-size:.67rem; color:var(--text-muted); }

/* ── Recent leads — mobile card view ──────────────────── */
.recent-desktop { display:block; }
.recent-mobile  { display:none; }

@media(max-width:767px) {
  .recent-desktop { display:none; }
  .recent-mobile  { display:block; padding:.5rem; }
  /* Stack chart cards vertically on mobile */
  .chart-card-row > [class*="col-xl"] { margin-bottom:0; }
  /* Period tabs wrap nicely */
  .period-tabs { gap:.85rem; }
  .period-btn  { font-size:.7rem; padding:.25rem .6rem; }
  /* Period mini-stats smaller */
  .ps-val { font-size:1rem; }
}

/* Mobile lead card */
.rlmc {
  background:var(--surface); border:1px solid var(--border);
  border-radius:var(--radius); padding:.75rem 1rem; margin-bottom:.5rem;
}
.rlmc-name  { font-weight:700; font-size:.86rem; color:var(--primary); text-decoration:none; }
.rlmc-meta  { display:flex; flex-wrap:wrap; gap:.25rem .6rem;
              font-size:.74rem; color:var(--text-muted); margin:.35rem 0; }
.rlmc-footer{ display:flex; justify-content:space-between; align-items:center;
              flex-wrap:wrap; gap:.35rem; }
</style>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
  <div class="col-6 col-xl-3">
    <a href="<?= APP_URL ?>/<?= $role==='employee' ? 'employee' : 'admin' ?>/leads.php" class="stat-card text-decoration-none" style="color:inherit;">
      <div class="stat-icon blue"><i class="bi bi-funnel-fill"></i></div>
      <div><div class="stat-number"><?= $totalLeads ?></div><div class="stat-label">Total Leads</div></div>
</a>
  </div>
  <div class="col-6 col-xl-3">
    <a href="<?= APP_URL ?>/<?= $role==='employee' ? 'employee' : 'admin' ?>/leads.php" class="stat-card text-decoration-none" style="color:inherit;">
      <div class="stat-icon amber"><i class="bi bi-plus-circle-fill"></i></div>
      <div><div class="stat-number"><?= $newLeads ?></div><div class="stat-label">New Leads</div></div>
</a>
  </div>
  <div class="col-6 col-xl-3">
    <a href="<?= APP_URL ?>/<?= $role==='employee' ? 'employee' : 'admin' ?>/leads.php" class="stat-card text-decoration-none" style="color:inherit;">
      <div class="stat-icon green"><i class="bi bi-check-circle-fill"></i></div>
      <div><div class="stat-number"><?= $convertedLeads ?></div><div class="stat-label">Converted</div></div>
    </a>
  </div>
  <div class="col-6 col-xl-3">
    <a href="<?= APP_URL ?>/<?= $role==='employee' ? 'employee' : 'admin' ?>/followups.php" class="stat-card text-decoration-none" style="color:inherit;">
      <div class="stat-icon red"><i class="bi bi-bell-fill"></i></div>
      <div><div class="stat-number"><?= $pendingFollowups ?></div><div class="stat-label">Follow-ups</div></div>
    </a>
  </div>
  <?php if ($totalEmployees !== null): ?>
  <div class="col-6 col-xl-3">
     <a href="<?= APP_URL ?>/admin/employees.php" class="stat-card text-decoration-none" style="color:inherit;">
      <div class="stat-icon purple"><i class="bi bi-people-fill"></i></div>
      <div><div class="stat-number"><?= $totalEmployees ?></div><div class="stat-label">Employees</div></div>
  </a>
  </div>
  <?php endif; ?>
  <div class="col-6 col-xl-3">
    <a href="<?= APP_URL ?>/<?= $role==='employee' ? 'employee' : 'admin' ?>/client-services/clients.php"
       class="stat-card text-decoration-none" style="color:inherit;">
      <div class="stat-icon blue"><i class="bi bi-briefcase-fill"></i></div>
      <div><div class="stat-number"><?= $totalClients ?></div><div class="stat-label"><?= $role==='employee' ? 'My Clients' : 'Total Clients' ?></div></div>
    </a>
  </div>
  <div class="col-6 col-xl-3">
    <a href="<?= APP_URL ?>/<?= $role==='employee' ? 'employee' : 'admin' ?>/client-services/clients.php?status=Active"
       class="stat-card text-decoration-none" style="color:inherit;">
      <div class="stat-icon green"><i class="bi bi-cash-stack"></i></div>
      <div>
        <div class="stat-number" style="font-size:<?= $activeClientValue >= 1000000 ? '1.1rem' : '1.35rem' ?>;">
          ₹<?= $activeClientValue >= 100000 ? number_format($activeClientValue/100000, 1).'L' : number_format($activeClientValue) ?>
        </div>
        <div class="stat-label">Active Contract Value</div>
      </div>
    </a>
  </div>
</div>

<!-- Charts Row -->
<div class="row g-3 mb-4 chart-card-row">
  <!-- Trend chart -->
  <div class="col-12 col-xl-8">
    <div class="card h-100">
      <div class="card-header p-2 px-3">
        <!-- Title + mini stats -->
        <div class="fw-700 mb-2" style="font-size:.87rem;">
          <i class="bi bi-bar-chart-line me-2 text-primary"></i>Lead Trend
        </div>
        <div class="period-stats mb-2">
          <div class="ps-item"><span class="ps-val" id="ps-total">—</span><span class="ps-lbl">Leads</span></div>
          <div class="ps-item"><span class="ps-val" id="ps-new">—</span><span class="ps-lbl">New</span></div>
          <div class="ps-item"><span class="ps-val" id="ps-conv">—</span><span class="ps-lbl">Conv.</span></div>
          <div class="ps-item"><span class="ps-val" id="ps-rate">—</span><span class="ps-lbl">Rate</span></div>
        </div>
        <!-- Period tabs -->
        <div class="period-tabs" id="periodTabs">
          <button class="period-btn" data-period="today">Today</button>
          <button class="period-btn" data-period="week">Week</button>
          <button class="period-btn active" data-period="month">Month</button>
          <button class="period-btn" data-period="3months">3M</button>
          <button class="period-btn" data-period="6months">6M</button>
          <button class="period-btn" data-period="custom"><i class="bi bi-calendar-range"></i></button>
        </div>
        <div class="custom-range" id="customRange">
          <input type="date" id="customFrom" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-01') ?>">
          <span class="text-muted small">→</span>
          <input type="date" id="customTo" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
          <button class="btn-apply" id="applyCustom">Apply</button>
        </div>
      </div>
      <div class="card-body p-2">
        <div class="chart-wrap">
          <div class="chart-loading" id="chartLoading">
            <div class="text-center">
              <div class="spinner-border spinner-border-sm text-primary mb-1"></div>
              <div style="font-size:.72rem;color:var(--text-muted);">Loading…</div>
            </div>
          </div>
          <div class="chart-empty" id="chartEmpty">
            <i class="bi bi-bar-chart-line"></i>No leads in this period
          </div>
          <canvas id="trendChart"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- Status donut -->
  <div class="col-12 col-xl-4">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-700" style="font-size:.87rem;">
          <i class="bi bi-pie-chart me-2 text-primary"></i>Lead Status
        </span>
        <span id="statusPeriodLabel" class="badge bg-primary" style="font-size:.65rem;"></span>
      </div>
      <div class="card-body p-0 d-flex flex-column">
        <div class="d-flex justify-content-center p-3">
          <div class="donut-wrap">
            <canvas id="statusChart"></canvas>
            <div class="donut-center" id="donutCenter">
              <span class="donut-center-val" id="donutTotal"></span>
              <span class="donut-center-lbl">leads</span>
            </div>
          </div>
        </div>
        <div class="px-3 pb-3 flex-fill" style="overflow-y:auto;max-height:220px;">
          <ul class="status-legend" id="statusLegend"></ul>
          <div class="chart-empty" id="statusEmpty">
            <i class="bi bi-pie-chart"></i>No data
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Recent Leads -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span class="fw-700" style="font-size:.87rem;">
      <i class="bi bi-clock-history me-2 text-primary"></i>Recent Leads
    </span>
    <a href="<?= APP_URL ?>/<?= $role==='admin'?'admin':'employee' ?>/leads.php"
       class="btn btn-sm btn-primary">View All</a>
  </div>

  <!-- Desktop table -->
  <div class="recent-desktop card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" style="font-size:.82rem;">
        <thead>
          <tr><th>#</th><th>Name</th><th>Phone</th><th>Service</th>
              <th>Source</th><th>Priority</th><th>Status</th><th>Assigned</th><th>Date</th></tr>
        </thead>
        <tbody>
          <?php foreach ($recentLeads as $i => $lead): ?>
          <tr>
            <td><?= $i+1 ?></td>
            <td>
              <a href="<?= APP_URL ?>/<?= $role==='admin'?'admin':'employee' ?>/lead-detail.php?id=<?= $lead['id'] ?>"
                 class="fw-600 text-decoration-none"><?= e($lead['name']) ?></a>
            </td>
            <td><?= e($lead['phone']) ?></td>
            <td><?= e($lead['service']) ?></td>
            <td><?= e($lead['source_name'] ?? '—') ?></td>
            <td><span class="fw-600 priority-<?= e($lead['priority']) ?>"><?= e($lead['priority']) ?></span></td>
            <td><span class="badge-status status-<?= str_replace(' ','-',e($lead['status'])) ?>"><?= e($lead['status']) ?></span></td>
            <td><?= e($lead['assigned_name'] ?? 'Unassigned') ?></td>
            <td><?= date('d M', strtotime($lead['created_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$recentLeads): ?>
          <tr><td colspan="9" class="text-center text-muted py-4">No leads found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Mobile cards -->
  <div class="recent-mobile">
    <?php foreach ($recentLeads as $lead): ?>
    <div class="rlmc">
      <a href="<?= APP_URL ?>/<?= $role==='admin'?'admin':'employee' ?>/lead-detail.php?id=<?= $lead['id'] ?>"
         class="rlmc-name"><?= e($lead['name']) ?></a>
      <div class="rlmc-meta">
        <?php if ($lead['phone']): ?>
        <span><i class="bi bi-telephone me-1"></i><?= e($lead['phone']) ?></span>
        <?php endif; ?>
        <?php if ($lead['service']): ?>
        <span><i class="bi bi-tools me-1"></i><?= e($lead['service']) ?></span>
        <?php endif; ?>
        <span><i class="bi bi-person me-1"></i><?= e($lead['assigned_name'] ?? 'Unassigned') ?></span>
        <span><i class="bi bi-calendar3 me-1"></i><?= date('d M', strtotime($lead['created_at'])) ?></span>
      </div>
      <div class="rlmc-footer">
        <span class="badge-status status-<?= str_replace(' ','-',e($lead['status'])) ?>"><?= e($lead['status']) ?></span>
        <span class="fw-600 priority-<?= e($lead['priority']) ?>" style="font-size:.74rem;"><?= e($lead['priority']) ?></span>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if (!$recentLeads): ?>
    <div class="text-center text-muted py-4">No leads found.</div>
    <?php endif; ?>
  </div>
</div>

<script>
const STATUS_COLORS = {
  'Untouched':'#64748b','New':'#3b82f6','Contacted':'#6366f1','Follow-up':'#f59e0b',
  'Interested':'#10b981','Converted':'#22c55e','Closed':'#94a3b8','Rejected':'#ef4444'
};
const PERIOD_LABELS = {
  today:'Today',week:'This Week',month:'This Month',
  '3months':'Last 3 Months','6months':'Last 6 Months',custom:'Custom'
};
let trendChart = null, statusChart = null, currentPeriod = 'month';

const gridColor  = () => document.documentElement.getAttribute('data-bs-theme')==='dark'
  ? 'rgba(255,255,255,.07)':'rgba(148,163,184,.15)';
const surfaceCol = () => document.documentElement.getAttribute('data-bs-theme')==='dark'
  ? '#161b22':'#ffffff';

function buildTrend(labels, counts) {
  if (trendChart) trendChart.destroy();
  const maxV = Math.max(...counts, 0);
  trendChart = new Chart(document.getElementById('trendChart'), {
    type:'bar',
    data:{ labels, datasets:[{
      label:'Leads', data:counts,
      backgroundColor: counts.map(v => v===maxV&&v>0?'#1d4ed8':'#3b82f6'),
      borderRadius:5, borderSkipped:false
    }]},
    options:{
      responsive:true, maintainAspectRatio:true,
      animation:{duration:420},
      plugins:{legend:{display:false},
        tooltip:{callbacks:{label:c=>` ${c.parsed.y} lead${c.parsed.y!==1?'s':''}`}}},
      scales:{
        y:{beginAtZero:true,ticks:{stepSize:1,precision:0},grid:{color:gridColor()}},
        x:{grid:{display:false},ticks:{maxRotation:40,font:{size:10},maxTicksLimit:18}}
      }
    }
  });
}

function buildStatus(rows) {
  if (statusChart) statusChart.destroy();
  const labels = rows.map(r=>r.status);
  const data   = rows.map(r=>parseInt(r.cnt));
  const colors = rows.map(r=>STATUS_COLORS[r.status]||'#94a3b8');
  const total  = data.reduce((a,b)=>a+b,0);
  statusChart = new Chart(document.getElementById('statusChart'), {
    type:'doughnut',
    data:{labels,datasets:[{data,backgroundColor:colors,borderWidth:2,borderColor:surfaceCol(),hoverOffset:6}]},
    options:{cutout:'70%',animation:{duration:420},plugins:{legend:{display:false},
      tooltip:{callbacks:{label:c=>` ${c.label}: ${c.parsed} (${total>0?Math.round(c.parsed/total*100):0}%)`}}}}
  });
  document.getElementById('donutTotal').textContent = total;
  document.getElementById('statusLegend').innerHTML = rows.map(r => {
    const pct = total>0?Math.round(r.cnt/total*100):0;
    return `<li><span class="sl-dot" style="background:${STATUS_COLORS[r.status]||'#94a3b8'};"></span>
      <span class="sl-name">${r.status}</span><span class="sl-cnt">${r.cnt}</span><span class="sl-pct">${pct}%</span></li>`;
  }).join('');
}

function vis(cid,eid,show){
  document.getElementById(cid).style.display=show?'block':'none';
  document.getElementById(eid).style.display=show?'none':'flex';
}
function visStatus(show){
  ['statusChart','statusLegend'].forEach(id=>document.getElementById(id).style.display=show?'block':'none');
  document.getElementById('donutCenter').style.display=show?'flex':'none';
  document.getElementById('statusEmpty').style.display=show?'none':'flex';
}

async function loadChartData(period, from='', to='') {
  currentPeriod = period;
  document.getElementById('chartLoading').classList.add('show');
  let url = `<?= APP_URL ?>/api/chart-data.php?period=${period}`;
  if (from) url+=`&from=${from}`;
  if (to)   url+=`&to=${to}`;
  try {
    const res = await fetch(url);
    const d   = await res.json();
    const hasBar = d.counts && d.counts.some(v=>v>0);
    const hasSt  = d.statusData && d.statusData.length>0;
    vis('trendChart','chartEmpty',hasBar);
    visStatus(hasSt);
    if (hasBar) buildTrend(d.labels,d.counts);
    if (hasSt)  buildStatus(d.statusData);
    document.getElementById('ps-total').textContent = d.period_total;
    document.getElementById('ps-new').textContent   = d.period_new;
    document.getElementById('ps-conv').textContent  = d.period_conv;
    document.getElementById('ps-rate').textContent  = d.conv_rate+'%';
    document.getElementById('statusPeriodLabel').textContent = PERIOD_LABELS[period]||period;
  } catch(e){ console.error(e); }
  finally { document.getElementById('chartLoading').classList.remove('show'); }
}

document.querySelectorAll('.period-btn').forEach(btn => {
  btn.addEventListener('click', function(){
    document.querySelectorAll('.period-btn').forEach(b=>b.classList.remove('active'));
    this.classList.add('active');
    const p = this.dataset.period;
    const cr = document.getElementById('customRange');
    if (p==='custom'){ cr.classList.add('show'); return; }
    cr.classList.remove('show');
    loadChartData(p);
  });
});
document.getElementById('applyCustom').addEventListener('click',()=>{
  const f=document.getElementById('customFrom').value;
  const t=document.getElementById('customTo').value;
  if(!f||!t){showToast('warning','Select both dates.');return;}
  if(f>t){showToast('warning','Start must be before end.');return;}
  loadChartData('custom',f,t);
});
document.addEventListener('DOMContentLoaded',()=>loadChartData('month'));
document.getElementById('themeToggle')?.addEventListener('click',()=>{
  setTimeout(()=>{if(trendChart){trendChart.options.scales.y.grid.color=gridColor();trendChart.update();}},50);
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>