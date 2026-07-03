<?php
// employee/leads.php — Employee: My Assigned Leads
require_once __DIR__ . '/../includes/auth.php';
requireRole(['employee']);

$pageTitle  = 'My Leads';
$activePage = 'leads';

$uid = $_SESSION['user_id'];

$statuses  = ['Untouched','New','Contacted','Follow-up','Interested','Converted','Closed','Rejected'];
$fStatus   = $_GET['status']   ?? '';
$fPriority = $_GET['priority'] ?? '';
$fSearch   = trim($_GET['q']   ?? '');

$where  = ['l.assigned_to = ?'];
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
        <a href="<?= APP_URL ?>/employee/leads.php" class="btn btn-outline-secondary btn-sm">Reset</a>
      </div>
      <div class="col-auto ms-auto">
        <a href="<?= APP_URL ?>/employee/lead-form.php" class="btn btn-sm btn-primary">
          <i class="bi bi-plus-lg me-1"></i>Add Lead
        </a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <i class="bi bi-funnel me-2 text-primary"></i>My Leads
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
            <td class="d-flex gap-1">
              <a href="<?= APP_URL ?>/admin/lead-detail.php?id=<?= $lead['id'] ?>"
                 class="btn btn-xs btn-outline-primary" title="View & Follow-up">
                <i class="bi bi-eye"></i>
              </a>
              <a href="<?= APP_URL ?>/employee/lead-form.php?id=<?= $lead['id'] ?>"
                 class="btn btn-xs btn-outline-secondary" title="Edit">
                <i class="bi bi-pencil"></i>
              </a>
              <button type="button" class="btn btn-xs btn-outline-danger" title="Delete"
                      data-bs-toggle="modal" data-bs-target="#deleteModal"
                      data-id="<?= $lead['id'] ?>" data-name="<?= e($lead['name']) ?>">
                <i class="bi bi-trash"></i>
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$leads): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">
            <i class="bi bi-inbox display-6 d-block mb-2 opacity-50"></i>No leads assigned.
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

<!-- Delete confirmation modal -->
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

<script>
// Delete modal: fill in the lead name + build the delete link from whichever
// row's delete button was clicked
document.getElementById('deleteModal').addEventListener('show.bs.modal', function (ev) {
  const btn  = ev.relatedTarget;
  const id   = btn.dataset.id;
  const name = btn.dataset.name;
  document.getElementById('deleteName').textContent = name;
  document.getElementById('deleteConfirmBtn').href =
    '<?= APP_URL ?>/api/leads.php?action=delete&id=' + id;
});

// Status dropdown: update status inline without leaving the page
document.querySelectorAll('.status-select').forEach(sel => {
  sel.addEventListener('change', async function () {
    const fd = new FormData();
    fd.append('action', 'update-status');
    fd.append('id', this.dataset.id);
    fd.append('status', this.value);
    try {
      const res = await fetch('<?= APP_URL ?>/api/leads.php', { method: 'POST', body: fd });
      const d = await res.json();
      showToast(d.success ? 'success' : 'danger', d.message);
    } catch (e) {
      showToast('danger', 'Could not update status.');
    }
  });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>