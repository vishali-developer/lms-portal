<?php
// admin/reports.php — Reports & Analytics (Dynamic Period Filter)
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin','manager']);

$pageTitle  = 'Reports & Analytics';
$activePage = 'reports';

// All-time KPIs — initial paint for the "Total Leads" tab (whole database).
// JS re-fetches and overwrites these via api/chart-data.php whenever the
// Total / Inbound / Outbound tab changes.
$allTotal     = (int)DB::fetchOne("SELECT COUNT(*) c FROM leads")['c'];
$allConverted = (int)DB::fetchOne("SELECT COUNT(*) c FROM leads WHERE status='Converted'")['c'];
$allRejected  = (int)DB::fetchOne("SELECT COUNT(*) c FROM leads WHERE status='Rejected'")['c'];
$allRate      = $allTotal > 0 ? round(($allConverted / $allTotal) * 100, 1) : 0;

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ── Period filter tabs ─────────────────────────────────────── */
.period-tabs { display:flex; gap:.3rem; flex-wrap:wrap; align-items:center; }
.period-btn {
  padding:.3rem .85rem; border-radius:20px;
  border:1.5px solid var(--border); background:transparent;
  color:var(--text-muted); font-size:.77rem; font-weight:600;
  cursor:pointer; transition:all .15s; white-space:nowrap;
  font-family:var(--font);
}
.period-btn:hover  { border-color:var(--primary); color:var(--primary); background:var(--primary-light); }
.period-btn.active { border-color:var(--primary); background:var(--primary); color:#fff; }

/* Custom date picker */
.custom-range { display:none; align-items:center; gap:.4rem; flex-wrap:wrap; margin-top:.5rem; }
.custom-range.show { display:flex; }
.custom-range input {
  font-size:.77rem; padding:.28rem .55rem;
  border-radius:8px; border:1.5px solid var(--border);
  background:var(--surface); color:var(--text); max-width:130px;
}
.custom-range input:focus { outline:none; border-color:var(--primary); }
.btn-apply {
  padding:.28rem .75rem; font-size:.77rem; font-weight:600;
  border-radius:8px; border:none; background:var(--primary); color:#fff; cursor:pointer;
}

/* ── Period KPI strip ──────────────────────────────────────── */
.period-kpi-strip {
  display:flex; gap:1.25rem; flex-wrap:wrap;
  padding:.5rem 1.25rem;
  background:var(--primary-light);
  border-top:1px solid var(--border);
  border-bottom:1px solid var(--border);
}
.pkpi { display:flex; flex-direction:column; }
.pkpi-val { font-size:1.2rem; font-weight:800; line-height:1; letter-spacing:-.02em; color:var(--text); }
.pkpi-lbl { font-size:.67rem; color:var(--text-muted); margin-top:1px; }

/* ── Chart loading overlay ────────────────────────────────── */
.chart-wrap { position:relative; }
.chart-loading {
  position:absolute; inset:0; display:none; z-index:10;
  align-items:center; justify-content:center;
  background:rgba(255,255,255,.75); border-radius:var(--radius);
  backdrop-filter:blur(2px);
}
[data-bs-theme="dark"] .chart-loading { background:rgba(22,27,34,.75); }
.chart-loading.show { display:flex; }

/* ── Empty chart state ────────────────────────────────────── */
.chart-empty {
  display:none; flex-direction:column;
  align-items:center; justify-content:center;
  min-height:130px; color:var(--text-muted);
  font-size:.8rem; gap:.4rem; text-align:center;
}
.chart-empty i { font-size:2rem; opacity:.22; }

/* ── Status / Source legend lists ─────────────────────────── */
.stat-legend      { list-style:none; padding:0; margin:0; font-size:.74rem; }
.stat-legend li   {
  display:flex; align-items:center; gap:.45rem;
  padding:.22rem 0; border-bottom:1px solid var(--border);
}
.stat-legend li:last-child { border:none; }
.leg-dot  { width:9px; height:9px; border-radius:50%; flex-shrink:0; }
.leg-name { flex:1; color:var(--text-muted); }
.leg-cnt  { font-weight:700; min-width:20px; text-align:right; }
.leg-pct  { color:var(--text-muted); min-width:34px; text-align:right; }

/* ── Employee performance table ────────────────────────────── */
.emp-prog { height:6px; border-radius:3px; background:var(--border); overflow:hidden; min-width:60px; }
.emp-prog-bar { height:100%; background:var(--primary); border-radius:3px; transition:width .5s ease; }

/* ── Donut centre label ────────────────────────────────────── */
.donut-wrap { position:relative; max-width:175px; width:100%; }
.donut-center {
  position:absolute; inset:0; pointer-events:none;
  display:flex; flex-direction:column;
  align-items:center; justify-content:center; line-height:1.2;
}
.donut-center-val { font-size:1.55rem; font-weight:800; }
.donut-center-lbl { font-size:.67rem; color:var(--text-muted); }
</style>

<!-- ═══════════════════════════════════════════════════════════
     Report capture area (everything inside gets screenshotted
     by the Download Report button)
═══════════════════════════════════════════════════════════ -->
<div id="reportCaptureArea">

<!-- ═══════════════════════════════════════════════════════════
     All-time KPI cards
═══════════════════════════════════════════════════════════ -->
<div class="row g-3 mb-4">
  <div class="col-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon blue"><i class="bi bi-funnel-fill"></i></div>
      <div>
        <div class="stat-number" id="allTotal"><?= $allTotal ?></div>
        <div class="stat-label">Total Leads (All-time)</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green"><i class="bi bi-check-circle-fill"></i></div>
      <div>
        <div class="stat-number" id="allConverted"><?= $allConverted ?></div>
        <div class="stat-label">Converted (All-time)</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon amber"><i class="bi bi-graph-up-arrow"></i></div>
      <div>
        <div class="stat-number" id="allRate"><?= $allRate ?>%</div>
        <div class="stat-label">Conversion Rate</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon red"><i class="bi bi-x-circle-fill"></i></div>
      <div>
        <div class="stat-number" id="allRejected"><?= $allRejected ?></div>
        <div class="stat-label">Rejected (All-time)</div>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     Lead Type tabs — Total / Inbound / Outbound
═══════════════════════════════════════════════════════════ -->
<div class="card mb-3">
  <div class="card-body py-2 px-3">
    <div class="text-muted fw-700 small mb-2">
      <i class="bi bi-funnel me-1"></i>Lead Type
    </div>
    <div class="period-tabs" id="leadTypeTabs">
      <button class="period-btn active" data-leadtype="">Total Leads</button>
      <button class="period-btn" data-leadtype="Inbound Leads">Inbound Leads</button>
      <button class="period-btn" data-leadtype="Outbound Leads">Outbound Leads</button>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     Period filter bar
═══════════════════════════════════════════════════════════ -->
<div class="card mb-3">
  <div class="card-body py-2 px-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
      <div>
        <div class="text-muted fw-700 small mb-2">
          <i class="bi bi-calendar3 me-1"></i>Filter Charts by Period
        </div>
        <div class="period-tabs" id="periodTabs">
          <button class="period-btn"        data-period="today">Today</button>
          <button class="period-btn"        data-period="week">This Week</button>
          <button class="period-btn active" data-period="month">This Month</button>
          <button class="period-btn"        data-period="3months">3 Months</button>
          <button class="period-btn"        data-period="6months">6 Months</button>
          <button class="period-btn"        data-period="custom">
            <i class="bi bi-calendar-range me-1"></i>Custom
          </button>
        </div>
        <div class="custom-range" id="customRange">
          <input type="date" id="customFrom" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-01') ?>">
          <span class="text-muted small">→</span>
          <input type="date" id="customTo"   max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
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
  <!-- Period KPI strip (updated by JS) -->
  <div class="period-kpi-strip" id="periodKpiStrip">
    <div class="pkpi"><span class="pkpi-val" id="pk-total">—</span><span class="pkpi-lbl">Leads in Period</span></div>
    <div class="pkpi"><span class="pkpi-val" id="pk-new">—</span><span class="pkpi-lbl">New</span></div>
    <div class="pkpi"><span class="pkpi-val" id="pk-conv">—</span><span class="pkpi-lbl">Converted</span></div>
    <div class="pkpi"><span class="pkpi-val" id="pk-rate">—</span><span class="pkpi-lbl">Conv. Rate</span></div>
    <div class="pkpi ms-auto">
      <span class="pkpi-val" id="pk-daterange" style="font-size:.78rem;font-weight:600;color:var(--text-muted);">—</span>
      <span class="pkpi-lbl">Date Range</span>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     Row 1 : Lead Trend  +  Status Donut
═══════════════════════════════════════════════════════════ -->
<div class="row g-3 mb-3">

  <!-- Lead Trend bar -->
  <div class="col-12 col-xl-8">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-700" style="font-size:.87rem;">
          <i class="bi bi-bar-chart-line me-2 text-primary"></i>Lead Trend
        </span>
        <span id="trendBadge" class="badge bg-primary" style="font-size:.67rem;"></span>
      </div>
      <div class="card-body">
        <div class="chart-wrap">
          <div class="chart-loading" id="trendLoading">
            <div class="text-center">
              <div class="spinner-border spinner-border-sm text-primary mb-1"></div>
              <div style="font-size:.72rem;color:var(--text-muted);">Loading…</div>
            </div>
          </div>
          <div class="chart-empty" id="trendEmpty">
            <i class="bi bi-bar-chart-line"></i>
            No leads in this period
          </div>
          <canvas id="trendChart"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- Status donut -->
  <div class="col-12 col-xl-4">
    <div class="card h-100">
      <div class="card-header fw-700" style="font-size:.87rem;">
        <i class="bi bi-pie-chart me-2 text-primary"></i>By Status
      </div>
      <div class="card-body p-0 d-flex flex-column">
        <!-- Donut canvas -->
        <div class="d-flex justify-content-center align-items-center p-3">
          <div class="donut-wrap">
            <canvas id="statusChart"></canvas>
            <div class="donut-center" id="donutCenter">
              <span class="donut-center-val" id="donutTotal"></span>
              <span class="donut-center-lbl">leads</span>
            </div>
          </div>
        </div>
        <!-- Legend -->
        <div class="px-3 pb-3 flex-fill" style="overflow-y:auto;">
          <ul class="stat-legend" id="statusLegend"></ul>
          <div class="chart-empty" id="statusEmpty">
            <i class="bi bi-pie-chart"></i>No data for this period
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     Row 2 : Lead Sources  +  Employee Performance
═══════════════════════════════════════════════════════════ -->
<div class="row g-3 mb-3">

  <!-- Source horizontal bar -->
  <div class="col-12 col-xl-6">
    <div class="card h-100">
      <div class="card-header fw-700" style="font-size:.87rem;">
        <i class="bi bi-broadcast me-2 text-primary"></i>Lead Sources
      </div>
      <div class="card-body">
        <div class="chart-wrap" id="sourceWrap" style="min-height:140px;">
          <div class="chart-loading" id="sourceLoading">
            <div class="spinner-border spinner-border-sm text-primary"></div>
          </div>
          <div class="chart-empty" id="sourceEmpty">
            <i class="bi bi-broadcast"></i>No source data
          </div>
          <canvas id="sourceChart"></canvas>
        </div>
        <ul class="stat-legend mt-3" id="sourceLegend"></ul>
      </div>
    </div>
  </div>

  <!-- Employee performance (dynamic — updates with period) -->
  <div class="col-12 col-xl-6">
    <div class="card h-100">
      <div class="card-header fw-700" style="font-size:.87rem;">
        <i class="bi bi-people me-2 text-primary"></i>Employee Performance
        <span id="empBadge" class="badge bg-primary ms-2" style="font-size:.67rem;"></span>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0" style="font-size:.82rem;">
            <thead>
              <tr>
                <th>Employee</th>
                <th class="text-center">Leads</th>
                <th class="text-center">Conv.</th>
                <th class="text-center">Rej.</th>
                <th>Rate</th>
              </tr>
            </thead>
            <tbody id="empTableBody">
              <tr>
                <td colspan="5" class="text-center text-muted py-3">
                  <div class="spinner-border spinner-border-sm text-primary"></div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

</div>

</div><!-- /#reportCaptureArea -->

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script>
/* ───────────────────────────────────────────────────────────
   Constants
─────────────────────────────────────────────────────────── */
const STATUS_COLORS = {
  'Untouched':  '#64748b',
  'New':        '#3b82f6',
  'Contacted':  '#6366f1',
  'Follow-up':  '#f59e0b',
  'Interested': '#10b981',
  'Converted':  '#22c55e',
  'Closed':     '#94a3b8',
  'Rejected':   '#ef4444',
};
const SOURCE_PALETTE = [
  '#2563eb','#7c3aed','#db2777','#d97706',
  '#059669','#0891b2','#dc2626','#84cc16','#f97316','#64748b'
];
const PERIOD_LABELS = {
  today:'Today', week:'This Week', month:'This Month',
  '3months':'Last 3 Months', '6months':'Last 6 Months', custom:'Custom Range'
};

/* ───────────────────────────────────────────────────────────
   Chart instances
─────────────────────────────────────────────────────────── */
let trendChart  = null;
let statusChart = null;
let sourceChart = null;

const gridColor  = () => document.documentElement.getAttribute('data-bs-theme')==='dark'
  ? 'rgba(255,255,255,.07)' : 'rgba(148,163,184,.15)';
const surfaceCol = () => document.documentElement.getAttribute('data-bs-theme')==='dark'
  ? '#161b22' : '#ffffff';

/* ───────────────────────────────────────────────────────────
   Build Trend bar chart
─────────────────────────────────────────────────────────── */
function buildTrend(labels, counts) {
  if (trendChart) trendChart.destroy();
  const maxV = Math.max(...counts, 0);
  trendChart = new Chart(document.getElementById('trendChart'), {
    type: 'bar',
    data: {
      labels,
      datasets: [{
        label: 'Leads',
        data:  counts,
        backgroundColor: counts.map(v => v === maxV && v > 0 ? '#1d4ed8' : '#3b82f6'),
        borderRadius: 5,
        borderSkipped: false,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: true,
      animation: { duration: 450, easing: 'easeOutQuart' },
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: c => ` ${c.parsed.y} lead${c.parsed.y!==1?'s':''}` } }
      },
      scales: {
        y: {
          beginAtZero: true,
          ticks: { stepSize: 1, precision: 0 },
          grid:  { color: gridColor() },
        },
        x: {
          grid: { display: false },
          ticks: { maxRotation: 40, font: { size: 10 }, maxTicksLimit: 20 }
        }
      }
    }
  });
}

/* ───────────────────────────────────────────────────────────
   Build Status donut
─────────────────────────────────────────────────────────── */
function buildStatus(rows) {
  if (statusChart) statusChart.destroy();
  const labels = rows.map(r => r.status);
  const data   = rows.map(r => parseInt(r.cnt));
  const colors = rows.map(r => STATUS_COLORS[r.status] || '#94a3b8');
  const total  = data.reduce((a,b)=>a+b, 0);

  statusChart = new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: { labels, datasets:[{
      data, backgroundColor: colors,
      borderWidth: 2, borderColor: surfaceCol(), hoverOffset: 6
    }]},
    options: {
      cutout: '70%',
      animation: { duration: 450 },
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: {
          label: c => ` ${c.label}: ${c.parsed} (${total>0?Math.round(c.parsed/total*100):0}%)`
        }}
      }
    }
  });

  document.getElementById('donutTotal').textContent = total;

  // Legend
  document.getElementById('statusLegend').innerHTML = rows.map(r => {
    const pct = total > 0 ? Math.round(r.cnt/total*100) : 0;
    return `<li>
      <span class="leg-dot" style="background:${STATUS_COLORS[r.status]||'#94a3b8'};"></span>
      <span class="leg-name">${r.status}</span>
      <span class="leg-cnt">${r.cnt}</span>
      <span class="leg-pct">${pct}%</span>
    </li>`;
  }).join('');
}

/* ───────────────────────────────────────────────────────────
   Build Source horizontal bar
─────────────────────────────────────────────────────────── */
function buildSource(rows) {
  if (sourceChart) sourceChart.destroy();
  const labels = rows.map(r => r.sname);
  const data   = rows.map(r => parseInt(r.cnt));
  const total  = data.reduce((a,b)=>a+b, 0);

  // Dynamic height so bars don't look squished
  const h = Math.max(120, rows.length * 38);
  document.getElementById('sourceWrap').style.minHeight = h + 'px';

  sourceChart = new Chart(document.getElementById('sourceChart'), {
    type: 'bar',
    data: { labels, datasets:[{
      label: 'Leads', data,
      backgroundColor: labels.map((_,i) => SOURCE_PALETTE[i % SOURCE_PALETTE.length]),
      borderRadius: 4, borderSkipped: false,
    }]},
    options: {
      indexAxis: 'y',
      responsive: true,
      maintainAspectRatio: false,
      animation: { duration: 450 },
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: c => ` ${c.parsed.x} leads` } }
      },
      scales: {
        x: { beginAtZero:true, ticks:{stepSize:1,precision:0}, grid:{color:gridColor()} },
        y: { grid:{ display:false }, ticks:{ font:{size:11} } }
      }
    }
  });

  // Source legend
  document.getElementById('sourceLegend').innerHTML = rows.map((r,i) => {
    const pct = total > 0 ? Math.round(r.cnt/total*100) : 0;
    return `<li>
      <span class="leg-dot" style="background:${SOURCE_PALETTE[i%SOURCE_PALETTE.length]};"></span>
      <span class="leg-name">${r.sname}</span>
      <span class="leg-cnt">${r.cnt}</span>
      <span class="leg-pct">${pct}%</span>
    </li>`;
  }).join('');
}

/* ───────────────────────────────────────────────────────────
   Build Employee table (dynamic)
─────────────────────────────────────────────────────────── */
function buildEmpTable(rows, periodLabel) {
  document.getElementById('empBadge').textContent = periodLabel;
  const tbody = document.getElementById('empTableBody');
  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">No data</td></tr>';
    return;
  }
  tbody.innerHTML = rows.map(r => {
    const rate = r.total > 0 ? Math.round(r.converted / r.total * 100) : 0;
    return `<tr>
      <td class="fw-600">${r.name}</td>
      <td class="text-center"><span class="badge bg-primary">${r.total}</span></td>
      <td class="text-center"><span class="badge bg-success">${r.converted}</span></td>
      <td class="text-center"><span class="badge bg-danger">${r.rejected}</span></td>
      <td>
        <div class="d-flex align-items-center gap-2">
          <div class="emp-prog flex-fill">
            <div class="emp-prog-bar" style="width:${rate}%;"></div>
          </div>
          <small class="text-muted">${rate}%</small>
        </div>
      </td>
    </tr>`;
  }).join('');
}

/* ───────────────────────────────────────────────────────────
   Toggle empty / canvas visibility
─────────────────────────────────────────────────────────── */
function vis(canvasId, emptyId, show) {
  document.getElementById(canvasId).style.display = show ? 'block' : 'none';
  document.getElementById(emptyId).style.display  = show ? 'none'  : 'flex';
}
function visStatus(show) {
  ['statusChart','statusLegend'].forEach(id =>
    document.getElementById(id).style.display = show ? 'block' : 'none');
  document.getElementById('donutCenter').style.display = show ? 'flex' : 'none';
  document.getElementById('statusEmpty').style.display = show ? 'none' : 'flex';
}

/* ───────────────────────────────────────────────────────────
   Update period KPI strip
─────────────────────────────────────────────────────────── */
function updateKPIs(d, label) {
  document.getElementById('pk-total').textContent    = d.period_total;
  document.getElementById('pk-new').textContent      = d.period_new;
  document.getElementById('pk-conv').textContent     = d.period_conv;
  document.getElementById('pk-rate').textContent     = d.conv_rate + '%';
  document.getElementById('pk-daterange').textContent =
    d.date_from + '  →  ' + d.date_to;
  document.getElementById('trendBadge').textContent  = label;

  // All-time cards follow the Lead Type tab (Total/Inbound/Outbound), independent of period
  document.getElementById('allTotal').textContent     = d.all_total;
  document.getElementById('allConverted').textContent = d.all_converted;
  document.getElementById('allRejected').textContent  = d.all_rejected;
  document.getElementById('allRate').textContent      = d.all_rate + '%';
}

/* ───────────────────────────────────────────────────────────
   Main load function — calls both APIs in parallel
─────────────────────────────────────────────────────────── */
let currentPeriod   = 'month';
let currentLeadType = '';

async function loadReports(period, from = '', to = '') {
  currentPeriod = period;
  const label   = PERIOD_LABELS[period] || period;

  // Show loaders
  ['trendLoading','sourceLoading'].forEach(id =>
    document.getElementById(id).classList.add('show'));

  let qs = `period=${period}`;
  if (from) qs += `&from=${from}`;
  if (to)   qs += `&to=${to}`;
  if (currentLeadType) qs += `&lead_type=${encodeURIComponent(currentLeadType)}`;

  try {
    // Parallel fetch both APIs
    const [r1, r2] = await Promise.all([
      fetch(`<?= APP_URL ?>/api/chart-data.php?${qs}`),
      fetch(`<?= APP_URL ?>/api/source-data.php?${qs}`)
    ]);
    const d   = await r1.json();
    const src = await r2.json();

    // ── Trend bar ─────────────────────────────────────────────
    const hasBar = d.counts && d.counts.some(v => v > 0);
    vis('trendChart', 'trendEmpty', hasBar);
    if (hasBar) buildTrend(d.labels, d.counts);

    // ── Status donut ──────────────────────────────────────────
    const hasSt = d.statusData && d.statusData.length > 0;
    visStatus(hasSt);
    if (hasSt) buildStatus(d.statusData);

    // ── Source bar ────────────────────────────────────────────
    const hasSrc = src.rows && src.rows.length > 0;
    vis('sourceChart', 'sourceEmpty', hasSrc);
    if (hasSrc) buildSource(src.rows);

    // ── Employee table ────────────────────────────────────────
    buildEmpTable(src.empPerf || [], label);

    // ── KPI strip ─────────────────────────────────────────────
    updateKPIs(d, label);

  } catch (e) {
    console.error('Report load error:', e);
    showToast('danger', 'Failed to load report data.');
  } finally {
    ['trendLoading','sourceLoading'].forEach(id =>
      document.getElementById(id).classList.remove('show'));
  }
}

/* ───────────────────────────────────────────────────────────
   Period tab click (scoped to #periodTabs so it doesn't clash
   with the Lead Type tabs, which share the same .period-btn style)
─────────────────────────────────────────────────────────── */
document.querySelectorAll('#periodTabs .period-btn').forEach(btn => {
  btn.addEventListener('click', function () {
    document.querySelectorAll('#periodTabs .period-btn').forEach(b => b.classList.remove('active'));
    this.classList.add('active');
    const period = this.dataset.period;
    const cr = document.getElementById('customRange');
    if (period === 'custom') { cr.classList.add('show'); return; }
    cr.classList.remove('show');
    loadReports(period);
  });
});

/* ───────────────────────────────────────────────────────────
   Lead Type tab click — Total / Inbound / Outbound
─────────────────────────────────────────────────────────── */
document.querySelectorAll('#leadTypeTabs .period-btn').forEach(btn => {
  btn.addEventListener('click', function () {
    document.querySelectorAll('#leadTypeTabs .period-btn').forEach(b => b.classList.remove('active'));
    this.classList.add('active');
    currentLeadType = this.dataset.leadtype;
    const cr = document.getElementById('customRange');
    if (currentPeriod === 'custom' && cr.classList.contains('show')) {
      const from = document.getElementById('customFrom').value;
      const to   = document.getElementById('customTo').value;
      if (from && to) { loadReports('custom', from, to); return; }
    }
    loadReports(currentPeriod);
  });
});

/* ───────────────────────────────────────────────────────────
   Custom date apply
─────────────────────────────────────────────────────────── */
document.getElementById('applyCustom').addEventListener('click', () => {
  const from = document.getElementById('customFrom').value;
  const to   = document.getElementById('customTo').value;
  if (!from || !to) { showToast('warning','Please select both dates.'); return; }
  if (from > to)    { showToast('warning','Start date must be before end date.'); return; }
  loadReports('custom', from, to);
});

/* ───────────────────────────────────────────────────────────
   Theme toggle — refresh grid colours
─────────────────────────────────────────────────────────── */
document.getElementById('themeToggle')?.addEventListener('click', () => {
  setTimeout(() => {
    [trendChart, sourceChart].forEach(ch => {
      if (!ch) return;
      if (ch.options.scales?.y) ch.options.scales.y.grid.color = gridColor();
      if (ch.options.scales?.x) ch.options.scales.x.grid.color = gridColor();
      ch.update();
    });
    if (statusChart) {
      statusChart.data.datasets[0].borderColor = surfaceCol();
      statusChart.update();
    }
  }, 60);
});

/* ───────────────────────────────────────────────────────────
   Download Report — full screenshot of the capture area
─────────────────────────────────────────────────────────── */
document.getElementById('downloadReportBtn').addEventListener('click', async function () {
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
    const typeSlug = currentLeadType ? currentLeadType.toLowerCase().replace(/\s+/g, '-') + '-' : '';
    link.download = `${typeSlug}leads-report-${new Date().toISOString().slice(0, 10)}.png`;
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

/* ───────────────────────────────────────────────────────────
   Boot
─────────────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => loadReports('month'));
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>