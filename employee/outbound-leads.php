<?php
// employee/outbound-leads.php — Employee: My Assigned Outbound Leads
require_once __DIR__ . '/../includes/auth.php';
requireRole(['employee']);

$pageTitle  = 'Outbound Leads';
$activePage = 'outbound-leads';

$uid = $_SESSION['user_id'];

$statuses  = ['Untouched','New','Contacted','Follow-up','Interested','Converted','Closed','Rejected'];
$fStatus   = $_GET['status']   ?? '';
$fPriority = $_GET['priority'] ?? '';
$fSearch   = trim($_GET['q']   ?? '');

$where  = ['l.assigned_to = ?', "l.lead_type = 'Outbound Leads'"];
$params = [$uid];
if ($fStatus)   { $where[] = 'l.status=?';   $params[] = $fStatus; }
if ($fPriority) { $where[] = 'l.priority=?'; $params[] = $fPriority; }
if ($fSearch)   {
    $where[] = '(l.name LIKE ? OR l.phone LIKE ? OR l.email LIKE ?)';
    $s = "%$fSearch%";
    $params = array_merge($params,[$s,$s,$s]);
}
$whereStr = implode(' AND ', $where);

$perPage = 20;
$page    = max(1,(int)($_GET['p']??1));
$offset  = ($page-1)*$perPage;

$total = DB::fetchOne("SELECT COUNT(*) c FROM leads l WHERE $whereStr",$params)['c'];
$pages = max(1,ceil($total/$perPage));

$leads = DB::fetchAll(
    "SELECT l.*, s.name AS source_name FROM leads l
     LEFT JOIN lead_sources s ON s.id=l.source_id
     WHERE $whereStr ORDER BY
       FIELD(l.priority,'High','Medium','Low'),
       l.created_at DESC
     LIMIT $perPage OFFSET $offset",
    $params);

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Filter -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2">
      <div class="col-12 col-md-4">
        <input type="text" name="q" class="form-control form-control-sm"
               placeholder="Search name, phone, email..." value="<?= e($fSearch) ?>">
      </div>
      <div class="col-6 col-md-2">
        <select name="status" class="form-select form-select-sm">
          <option value="">All Status</option>
          <?php foreach ($statuses as $s): ?>
          <option value="<?= $s ?>" <?= $fStatus===$s?'selected':'' ?>><?= $s ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <select name="priority" class="form-select form-select-sm">
          <option value="">All Priority</option>
          <?php foreach (['High','Medium','Low'] as $p): ?>
          <option value="<?= $p ?>" <?= $fPriority===$p?'selected':'' ?>><?= $p ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
        <a href="<?= APP_URL ?>/employee/outbound-leads.php" class="btn btn-outline-secondary btn-sm">Reset</a>
      </div>
      <div class="col-auto ms-auto">
        <a href="<?= APP_URL ?>/employee/lead-form.php?lead_type=Outbound+Leads" class="btn btn-sm btn-primary">
          <i class="bi bi-plus-lg me-1"></i>Add Lead
        </a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <i class="bi bi-box-arrow-up-right me-2 text-primary"></i>My Outbound Leads
    <span class="badge bg-primary"><?= $total ?></span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr><th>#</th><th>Name</th><th>Phone</th><th>Service</th>
              <th>Priority</th><th>Status</th><th>Last Updated</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($leads as $i => $lead): ?>
          <tr>
            <td><?= $offset+$i+1 ?></td>
            <td>
              <a href="<?= APP_URL ?>/admin/lead-detail.php?id=<?= $lead['id'] ?>"
                 class="fw-600 text-decoration-none"><?= e($lead['name']) ?></a>
              <div class="text-muted" style="font-size:.73rem;"><?= e($lead['email']) ?></div>
            </td>
            <td><?= e($lead['phone']) ?></td>
            <td><?= e($lead['service']) ?></td>
            <td><span class="fw-600 priority-<?= e($lead['priority']) ?>"><?= e($lead['priority']) ?></span></td>
            <td>
              <select class="form-select form-select-sm status-select" style="min-width:130px"
                      data-id="<?= $lead['id'] ?>">
                <?php foreach ($statuses as $s): ?>
                <option value="<?= $s ?>" <?= $lead['status']===$s?'selected':'' ?>><?= $s ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td><?= date('d M y', strtotime($lead['updated_at'])) ?></td>
            <td>
              <a href="<?= APP_URL ?>/admin/lead-detail.php?id=<?= $lead['id'] ?>"
                 class="btn btn-xs btn-outline-primary" title="View & Follow-up">
                <i class="bi bi-eye"></i>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$leads): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">
            <i class="bi bi-inbox display-6 d-block mb-2 opacity-50"></i>No outbound leads assigned.
          </td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php if ($pages > 1): ?>
  <div class="card-footer">
    <nav><ul class="pagination pagination-sm mb-0">
      <?php for ($pg=1;$pg<=$pages;$pg++): ?>
      <li class="page-item <?= $pg===$page?'active':'' ?>">
        <a class="page-link" href="?<?= http_build_query(array_merge($_GET,['p'=>$pg])) ?>"><?= $pg ?></a>
      </li>
      <?php endfor; ?>
    </ul></nav>
  </div>
  <?php endif; ?>
</div>

<style>.btn-xs{padding:.2rem .45rem;font-size:.75rem;border-radius:6px}</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>