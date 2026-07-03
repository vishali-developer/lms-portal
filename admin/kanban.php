<?php
// admin/kanban.php — Kanban Board (Fully Responsive)
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$pageTitle  = 'Kanban Board';
$activePage = 'kanban';
$uid  = $_SESSION['user_id'];
$role = $_SESSION['user_role'];

$statuses = ['Untouched','New','Contacted','Follow-up','Interested','Converted','Closed','Rejected'];
$colors   = [
    'Untouched'  => '#64748b',
    'New'        => '#3b82f6',
    'Contacted'  => '#6366f1',
    'Follow-up'  => '#f59e0b',
    'Interested' => '#10b981',
    'Converted'  => '#22c55e',
    'Closed'     => '#94a3b8',
    'Rejected'   => '#ef4444',
];

$cond = $role === 'employee' ? " AND l.assigned_to = $uid" : '';
$boards = [];
foreach ($statuses as $s) {
    $boards[$s] = DB::fetchAll(
        "SELECT l.id, l.name, l.phone, l.company, l.service,
                l.priority, l.created_at, u.name AS assigned_name
         FROM leads l
         LEFT JOIN users u ON u.id = l.assigned_to
         WHERE l.status = ? $cond
         ORDER BY FIELD(l.priority,'High','Medium','Low'), l.created_at DESC
         LIMIT 50",
        [$s]
    );
}

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ── Toolbar ──────────────────────────────────────────────── */
.kanban-toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: .5rem;
  margin-bottom: .75rem;
}

/* ══════════════════════════════════════════════════════════
   MOBILE LAYOUT  (< 768px)
══════════════════════════════════════════════════════════ */
.kanban-mobile   { display: none; }
.kanban-desktop  { display: block; }

@media (max-width: 767px) {
  .kanban-mobile  { display: block; }
  .kanban-desktop { display: none; }
}

/* ── Status pill tabs (mobile) ──────────────────────────── */
.ks-tabs-wrap {
  overflow-x: auto;
  white-space: nowrap;
  padding-bottom: .4rem;
  margin-bottom: .75rem;
  scrollbar-width: none;
}
.ks-tabs-wrap::-webkit-scrollbar { display: none; }

.ks-tabs {
  display: inline-flex;
  gap: .35rem;
}

.ks-tab {
  display: inline-flex;
  align-items: center;
  gap: .3rem;
  padding: .32rem .8rem;
  border-radius: 20px;
  border: 1.5px solid var(--border);
  background: var(--surface);
  color: var(--text-muted);
  font-size: .74rem;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
  transition: all .15s;
  font-family: var(--font);
  flex-shrink: 0;
}
.ks-tab:hover { border-color: var(--primary); color: var(--primary); }
.ks-tab.active {
  background: var(--primary);
  border-color: var(--primary);
  color: #fff;
}
.ks-tab .ks-dot {
  width: 7px; height: 7px;
  border-radius: 50%;
  flex-shrink: 0;
}
.ks-tab .ks-cnt {
  background: rgba(255,255,255,.25);
  border-radius: 10px;
  padding: 0 5px;
  font-size: .65rem;
  min-width: 18px;
  text-align: center;
}
.ks-tab:not(.active) .ks-cnt {
  background: var(--border);
  color: var(--text-muted);
}

/* ── Mobile column panel ────────────────────────────────── */
.ks-panel { display: none; }
.ks-panel.active { display: block; }

/* ── Mobile card ────────────────────────────────────────── */
.km-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: .9rem 1rem;
  margin-bottom: .55rem;
  cursor: pointer;
  transition: box-shadow .15s, transform .15s;
  position: relative;
}
.km-card:hover  { box-shadow: var(--shadow-md); transform: translateY(-1px); }
.km-card-top    { display: flex; justify-content: space-between; align-items: flex-start; gap: .5rem; margin-bottom: .4rem; }
.km-card-name   {
  font-weight: 700; font-size: .88rem;
  color: var(--primary); text-decoration: none;
  line-height: 1.3;
}
.km-card-name:hover { text-decoration: underline; }
.km-card-meta   {
  display: flex; flex-wrap: wrap;
  gap: .25rem .65rem;
  font-size: .75rem;
  color: var(--text-muted);
  margin-bottom: .45rem;
}
.km-card-meta i { font-size: .72rem; }
.km-card-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: .35rem;
  font-size: .72rem;
  color: var(--text-muted);
  padding-top: .4rem;
  border-top: 1px solid var(--border);
  margin-top: .1rem;
}
.km-avatar {
  width: 22px; height: 22px;
  background: var(--primary); color: #fff;
  border-radius: 50%;
  display: grid; place-items: center;
  font-size: .6rem; font-weight: 700;
  flex-shrink: 0;
}

/* Empty column state */
.km-empty {
  text-align: center;
  padding: 3rem 1rem;
  color: var(--text-muted);
  font-size: .83rem;
  background: var(--surface);
  border: 1px dashed var(--border);
  border-radius: var(--radius);
}
.km-empty i { font-size: 2rem; display: block; margin-bottom: .5rem; opacity: .25; }

/* ══════════════════════════════════════════════════════════
   DESKTOP LAYOUT  (≥ 768px)
══════════════════════════════════════════════════════════ */
.kanban-outer {
  width: 100%;
  overflow-x: auto;
  padding-bottom: .75rem;
  scrollbar-width: thin;
  scrollbar-color: var(--border) transparent;
}
.kanban-outer::-webkit-scrollbar       { height: 6px; }
.kanban-outer::-webkit-scrollbar-thumb { background: var(--border); border-radius: 6px; }

.kanban-board {
  display: inline-flex;
  gap: .85rem;
  min-height: 540px;
  padding: .25rem .1rem 1rem;
  width: 100%;
  min-width: max-content;
}

.kanban-col {
  width: 210px; flex: 1 1 210px;
  min-width: 185px; max-width: 270px;
  background: var(--bg);
  border-radius: var(--radius);
  padding: .75rem;
  border: 1px solid var(--border);
  display: flex; flex-direction: column;
}
.kanban-col-header {
  font-size: .72rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .07em;
  margin-bottom: .6rem;
  display: flex; align-items: center; justify-content: space-between;
  flex-shrink: 0;
}
.kanban-dot         { display: inline-block; width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
.kanban-count-badge { font-size: .67rem; font-weight: 700; padding: .15rem .45rem; border-radius: 20px; }

.kanban-col-body {
  flex: 1; overflow-y: auto; max-height: 65vh;
  scrollbar-width: thin; scrollbar-color: var(--border) transparent;
}
.kanban-col-body::-webkit-scrollbar       { width: 3px; }
.kanban-col-body::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }

.kanban-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: .7rem .75rem;
  margin-bottom: .45rem;
  cursor: pointer;
  transition: transform .15s, box-shadow .15s;
}
.kanban-card:hover     { transform: translateY(-2px); box-shadow: var(--shadow-md); }
.kanban-card:last-child { margin-bottom: 0; }
.kanban-card-name      { font-weight: 600; font-size: .83rem; margin-bottom: .2rem; line-height: 1.3; }
.kanban-card-meta      {
  font-size: .71rem; color: var(--text-muted); margin-top: .18rem;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.kanban-card-footer {
  display: flex; justify-content: space-between; align-items: center;
  margin-top: .5rem; font-size: .7rem; color: var(--text-muted);
}
.kanban-avatar {
  width: 20px; height: 20px; background: var(--primary);
  border-radius: 50%; display: grid; place-items: center;
  color: #fff; font-size: .58rem; font-weight: 700; flex-shrink: 0;
}
.kanban-empty {
  text-align: center; padding: 2rem 0;
  color: var(--text-muted); font-size: .78rem;
}
.kanban-empty i { font-size: 1.6rem; display: block; margin-bottom: .4rem; opacity: .3; }

@media (min-width: 1400px) { .kanban-col { min-width: 0; max-width: none; } }
@media (max-width: 1199px) { .kanban-col { flex: 0 0 200px; } }

/* btn-xs */
.btn-xs { padding: .2rem .45rem; font-size: .73rem; border-radius: 5px; }
</style>

<!-- Toolbar -->
<div class="kanban-toolbar">
  <div class="d-flex gap-2 flex-wrap">
    <a href="<?= APP_URL ?>/admin/leads.php" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-table me-1"></i>Table View
    </a>
    <a href="<?= APP_URL ?>/admin/kanban.php" class="btn btn-sm btn-primary">
      <i class="bi bi-kanban me-1"></i>Kanban View
    </a>
  </div>
  <?php if (in_array($role, ['admin','manager'])): ?>
  <a href="<?= APP_URL ?>/admin/lead-form.php" class="btn btn-sm btn-primary">
    <i class="bi bi-plus-lg me-1"></i>Add Lead
  </a>
  <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════════
     MOBILE VIEW
══════════════════════════════════════════════════════ -->
<div class="kanban-mobile">

  <!-- Scrollable status tab pills -->
  <div class="ks-tabs-wrap">
    <div class="ks-tabs" id="ksTabs">
      <?php foreach ($statuses as $i => $s): ?>
      <button class="ks-tab <?= $i===0?'active':'' ?>"
              data-status="<?= $s ?>"
              onclick="showKsPanel('<?= $s ?>')">
        <span class="ks-dot" style="background:<?= $colors[$s] ?>;"></span>
        <?= $s ?>
        <span class="ks-cnt"><?= count($boards[$s]) ?></span>
      </button>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- One panel per status — only active is visible -->
  <?php foreach ($statuses as $i => $status):
    $col = $boards[$status]; ?>
  <div class="ks-panel <?= $i===0?'active':'' ?>" id="ksPanel_<?= $status ?>">

    <?php if ($col): ?>
      <?php foreach ($col as $lead): ?>
      <div class="km-card"
           onclick="location.href='<?= APP_URL ?>/admin/lead-detail.php?id=<?= $lead['id'] ?>'">

        <div class="km-card-top">
          <span class="km-card-name"><?= e($lead['name']) ?></span>
          <span class="fw-700 priority-<?= e($lead['priority']) ?>"
                style="font-size:.71rem; white-space:nowrap; flex-shrink:0;">
            <?= e($lead['priority']) ?>
          </span>
        </div>

        <div class="km-card-meta">
          <?php if ($lead['phone']): ?>
          <span><i class="bi bi-telephone"></i> <?= e($lead['phone']) ?></span>
          <?php endif; ?>
          <?php if ($lead['company']): ?>
          <span><i class="bi bi-building"></i> <?= e($lead['company']) ?></span>
          <?php endif; ?>
          <?php if ($lead['service']): ?>
          <span><i class="bi bi-tools"></i> <?= e($lead['service']) ?></span>
          <?php endif; ?>
        </div>

        <div class="km-card-footer">
          <?php if ($lead['assigned_name']): ?>
          <div class="d-flex align-items-center gap-1">
            <div class="km-avatar"><?= strtoupper(substr($lead['assigned_name'],0,1)) ?></div>
            <span><?= e(explode(' ',$lead['assigned_name'])[0]) ?></span>
          </div>
          <?php else: ?>
          <span>Unassigned</span>
          <?php endif; ?>
          <span><?= date('d M', strtotime($lead['created_at'])) ?></span>
        </div>

      </div>
      <?php endforeach; ?>

    <?php else: ?>
    <div class="km-empty">
      <i class="bi bi-inbox"></i>
      No leads with status "<?= $status ?>"
    </div>
    <?php endif; ?>

  </div><!-- /.ks-panel -->
  <?php endforeach; ?>

</div><!-- /.kanban-mobile -->


<!-- ══════════════════════════════════════════════════════
     DESKTOP VIEW
══════════════════════════════════════════════════════ -->
<div class="kanban-desktop">
  <div class="kanban-outer">
    <div class="kanban-board">
      <?php foreach ($statuses as $status):
        $col = $boards[$status]; ?>
      <div class="kanban-col" data-status="<?= $status ?>">

        <div class="kanban-col-header">
          <span class="d-flex align-items-center gap-2">
            <span class="kanban-dot" style="background:<?= $colors[$status] ?>;"></span>
            <span style="color:<?= $colors[$status] ?>"><?= $status ?></span>
          </span>
          <span class="kanban-count-badge"
                style="background:<?= $colors[$status] ?>22; color:<?= $colors[$status] ?>;">
            <?= count($col) ?>
          </span>
        </div>

        <div class="kanban-col-body">
          <?php foreach ($col as $lead): ?>
          <div class="kanban-card"
               onclick="location.href='<?= APP_URL ?>/admin/lead-detail.php?id=<?= $lead['id'] ?>'">
            <div class="d-flex justify-content-between align-items-start mb-1">
              <div class="kanban-card-name"><?= e($lead['name']) ?></div>
              <span class="priority-<?= e($lead['priority']) ?>"
                    style="font-size:.68rem;font-weight:700;white-space:nowrap;flex-shrink:0;">
                <?= e($lead['priority']) ?>
              </span>
            </div>
            <?php if ($lead['company']): ?>
            <div class="kanban-card-meta"><i class="bi bi-building me-1"></i><?= e($lead['company']) ?></div>
            <?php endif; ?>
            <?php if ($lead['service']): ?>
            <div class="kanban-card-meta"><i class="bi bi-tools me-1"></i><?= e($lead['service']) ?></div>
            <?php endif; ?>
            <div class="kanban-card-meta"><i class="bi bi-telephone me-1"></i><?= e($lead['phone']) ?></div>
            <div class="kanban-card-footer">
              <?php if ($lead['assigned_name']): ?>
              <div class="d-flex align-items-center gap-1">
                <div class="kanban-avatar"><?= strtoupper(substr($lead['assigned_name'],0,1)) ?></div>
                <span><?= e(explode(' ',$lead['assigned_name'])[0]) ?></span>
              </div>
              <?php else: ?><span>Unassigned</span><?php endif; ?>
              <span><?= date('d M', strtotime($lead['created_at'])) ?></span>
            </div>
          </div>
          <?php endforeach; ?>

          <?php if (!$col): ?>
          <div class="kanban-empty">
            <i class="bi bi-inbox"></i>
            <div>No leads</div>
          </div>
          <?php endif; ?>
        </div>

      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script>
function showKsPanel(status) {
  // Hide all panels
  document.querySelectorAll('.ks-panel').forEach(p => p.classList.remove('active'));
  // Show target panel
  const panel = document.getElementById('ksPanel_' + status);
  if (panel) panel.classList.add('active');

  // Update tab buttons
  document.querySelectorAll('.ks-tab').forEach(b => {
    b.classList.toggle('active', b.dataset.status === status);
  });

  // Scroll active tab into view
  const activeBtn = document.querySelector('.ks-tab.active');
  if (activeBtn) {
    activeBtn.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
  }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>