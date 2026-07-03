<?php
// admin/activity-log.php — Full Activity Log (Mobile Responsive)
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin']);

$pageTitle  = 'Activity Log';
$activePage = 'activity';

$perPage = 30;
$page    = max(1,(int)($_GET['p']??1));
$offset  = ($page-1)*$perPage;

$fUser   = (int)($_GET['user'] ?? 0);
$fAction = trim($_GET['action'] ?? '');
$fDate   = $_GET['date'] ?? '';

$where  = ['1=1'];
$params = [];
if ($fUser)   { $where[] = 'al.user_id=?';         $params[] = $fUser; }
if ($fAction) { $where[] = 'al.action LIKE ?';      $params[] = "%$fAction%"; }
if ($fDate)   { $where[] = 'DATE(al.created_at)=?'; $params[] = $fDate; }
$ws = implode(' AND ', $where);

$total = DB::fetchOne("SELECT COUNT(*) c FROM activity_logs al WHERE $ws", $params)['c'];
$pages = max(1, ceil($total / $perPage));

$logs = DB::fetchAll(
    "SELECT al.*, u.name AS user_name, u.role AS user_role
     FROM activity_logs al
     LEFT JOIN users u ON u.id=al.user_id
     WHERE $ws ORDER BY al.created_at DESC
     LIMIT $perPage OFFSET $offset",
    $params);

$users = DB::fetchAll("SELECT id,name FROM users ORDER BY name");

$actionIcons = [
    'Login'=>'box-arrow-in-right','Logout'=>'box-arrow-right',
    'Create Lead'=>'plus-circle','Edit Lead'=>'pencil','Delete Lead'=>'trash',
    'Status Update'=>'arrow-repeat','Lead Assigned'=>'person-check',
    'Add Follow-up'=>'chat-text','Add Employee'=>'person-plus',
    'Export CSV'=>'download','Settings Updated'=>'gear',
    'Profile Updated'=>'person-circle','Password Changed'=>'shield-lock',
];

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ── Filter grid ───────────────────────────────────────── */
.al-filter-grid {
  display:grid; gap:.5rem;
  grid-template-columns:1fr 1fr;
}
@media(min-width:576px){ .al-filter-grid{ grid-template-columns:1fr 1fr 1fr; } }
@media(min-width:768px){ .al-filter-grid{ grid-template-columns:2fr 2fr 1fr auto; } }

/* ── Desktop table ─────────────────────────────────────── */
.al-desktop { display:block; }
.al-mobile  { display:none; }
@media(max-width:767px){
  .al-desktop { display:none; }
  .al-mobile  { display:block; padding:.5rem; }
}

/* ── Mobile activity card ──────────────────────────────── */
.alm-card {
  background:var(--surface); border:1px solid var(--border);
  border-radius:var(--radius); padding:.8rem 1rem; margin-bottom:.5rem;
  border-left:3px solid var(--primary);
}
.alm-header { display:flex; justify-content:space-between; align-items:flex-start; gap:.5rem; margin-bottom:.4rem; }
.alm-action { font-weight:700; font-size:.85rem; display:flex; align-items:center; gap:.4rem; }
.alm-time   { font-size:.7rem; color:var(--text-muted); white-space:nowrap; }
.alm-meta   { display:flex; flex-wrap:wrap; gap:.25rem .7rem; font-size:.74rem; color:var(--text-muted); }

/* ── Pagination wrap ───────────────────────────────────── */
.pagi-wrap { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:.5rem; }
</style>

<!-- Filter -->
<div class="card mb-3">
  <div class="card-body py-2 px-3">
    <form method="GET">
      <div class="al-filter-grid mb-0">
        <select name="user" class="form-select form-select-sm">
          <option value="">All Users</option>
          <?php foreach ($users as $u): ?>
          <option value="<?= $u['id'] ?>" <?= $fUser==$u['id']?'selected':'' ?>><?= e($u['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="action" class="form-control form-control-sm"
               placeholder="Search action…" value="<?= e($fAction) ?>">
        <input type="date" name="date" class="form-control form-control-sm" value="<?= e($fDate) ?>">
        <div class="d-flex gap-1">
          <button type="submit" class="btn btn-primary btn-sm flex-fill">
            <i class="bi bi-search me-1"></i>Filter
          </button>
          <a href="<?= APP_URL ?>/admin/activity-log.php"
             class="btn btn-outline-secondary btn-sm px-2" title="Reset">
            <i class="bi bi-x-lg"></i>
          </a>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span class="fw-700" style="font-size:.87rem;">
      <i class="bi bi-activity me-2 text-primary"></i>Activity Log
    </span>
    <span class="badge bg-secondary"><?= number_format($total) ?></span>
  </div>

  <!-- Desktop table -->
  <div class="al-desktop">
    <div class="table-responsive">
      <table class="table table-hover mb-0" style="font-size:.82rem;">
        <thead>
          <tr>
            <th>#</th><th>User</th><th>Action</th>
            <th>Description</th><th>IP</th><th>Date/Time</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($logs as $i => $log):
            $icon = $actionIcons[$log['action']] ?? 'circle'; ?>
          <tr>
            <td class="text-muted"><?= $offset+$i+1 ?></td>
            <td>
              <?php if ($log['user_name']): ?>
              <div class="d-flex align-items-center gap-2">
                <div class="mini-avatar"><?= strtoupper(substr($log['user_name'],0,1)) ?></div>
                <div>
                  <div class="small fw-600"><?= e($log['user_name']) ?></div>
                  <div class="text-muted" style="font-size:.7rem;"><?= ucfirst($log['user_role']??'') ?></div>
                </div>
              </div>
              <?php else: ?>
              <span class="text-muted small"><i class="bi bi-gear me-1"></i>System</span>
              <?php endif; ?>
            </td>
            <td>
              <span class="d-flex align-items-center gap-1">
                <i class="bi bi-<?= $icon ?> text-primary"></i>
                <span class="small fw-600"><?= e($log['action']) ?></span>
              </span>
            </td>
            <td class="small text-muted" style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
              <?= e($log['description'] ?? '') ?>
            </td>
            <td><code style="font-size:.72rem;"><?= e($log['ip_address'] ?? '—') ?></code></td>
            <td class="small text-muted" style="white-space:nowrap;">
              <?= date('d M y, h:i A', strtotime($log['created_at'])) ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$logs): ?>
          <tr>
            <td colspan="6" class="text-center text-muted py-5">
              <i class="bi bi-inbox" style="font-size:2.5rem;display:block;opacity:.25;margin-bottom:.75rem;"></i>
              No activity logs found.
            </td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Mobile cards -->
  <div class="al-mobile">
    <?php if ($logs): ?>
      <?php foreach ($logs as $log):
        $icon = $actionIcons[$log['action']] ?? 'circle'; ?>
      <div class="alm-card">
        <div class="alm-header">
          <div class="alm-action">
            <i class="bi bi-<?= $icon ?> text-primary"></i>
            <?= e($log['action']) ?>
          </div>
          <span class="alm-time"><?= date('d M y, h:i A', strtotime($log['created_at'])) ?></span>
        </div>
        <div class="alm-meta">
          <?php if ($log['user_name']): ?>
          <span>
            <i class="bi bi-person me-1"></i>
            <?= e($log['user_name']) ?>
            <span class="ms-1 opacity-60">(<?= ucfirst($log['user_role']??'') ?>)</span>
          </span>
          <?php endif; ?>
          <?php if ($log['description']): ?>
          <span><i class="bi bi-chat-text me-1"></i><?= e($log['description']) ?></span>
          <?php endif; ?>
          <?php if ($log['ip_address']): ?>
          <span><i class="bi bi-globe me-1"></i><?= e($log['ip_address']) ?></span>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    <?php else: ?>
    <div class="text-center text-muted py-5">
      <i class="bi bi-inbox" style="font-size:2.5rem;display:block;opacity:.25;margin-bottom:.75rem;"></i>
      No activity logs found.
    </div>
    <?php endif; ?>
  </div>

  <!-- Pagination -->
  <?php if ($pages > 1): ?>
  <div class="card-footer py-2">
    <div class="pagi-wrap">
      <small class="text-muted">
        Showing <?= $offset+1 ?>–<?= min($offset+$perPage,$total) ?> of <?= number_format($total) ?>
      </small>
      <nav>
        <ul class="pagination pagination-sm mb-0">
          <li class="page-item <?= $page<=1?'disabled':'' ?>">
            <a class="page-link" href="?<?= http_build_query(array_merge($_GET,['p'=>$page-1])) ?>">
              <i class="bi bi-chevron-left"></i>
            </a>
          </li>
          <?php
            $start = max(1,$page-3); $end = min($pages,$page+3);
            if($start>1): ?><li class="page-item"><a class="page-link" href="?<?= http_build_query(array_merge($_GET,['p'=>1])) ?>">1</a></li>
              <?php if($start>2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
          <?php endif;
            for($pg=$start;$pg<=$end;$pg++): ?>
            <li class="page-item <?= $pg===$page?'active':'' ?>">
              <a class="page-link" href="?<?= http_build_query(array_merge($_GET,['p'=>$pg])) ?>"><?= $pg ?></a>
            </li>
          <?php endfor;
            if($end<$pages): ?>
              <?php if($end<$pages-1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
              <li class="page-item"><a class="page-link" href="?<?= http_build_query(array_merge($_GET,['p'=>$pages])) ?>"><?= $pages ?></a></li>
          <?php endif; ?>
          <li class="page-item <?= $page>=$pages?'disabled':'' ?>">
            <a class="page-link" href="?<?= http_build_query(array_merge($_GET,['p'=>$page+1])) ?>">
              <i class="bi bi-chevron-right"></i>
            </a>
          </li>
        </ul>
      </nav>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>