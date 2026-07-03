<?php
// employee/followups.php — Follow-ups list (employee can edit/delete own follow-ups)
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$pageTitle  = 'Follow-ups';
$activePage = 'followups';
$uid  = $_SESSION['user_id'];
$role = $_SESSION['user_role'];

// Build filter
$extra = $role === 'employee' ? " AND f.employee_id = $uid" : '';

// LEFT JOIN users so a follow-up never disappears if the adder's account was deleted
$selectCols = "f.*,
               l.name AS lead_name, l.phone, l.status AS lead_status,
               COALESCE(u.name, f.employee_name_snapshot, 'Deleted Employee') AS emp_name,
               (u.id IS NULL) AS emp_deleted";

$today    = DB::fetchAll(
    "SELECT $selectCols
     FROM followups f
     JOIN leads l ON l.id = f.lead_id
     LEFT JOIN users u ON u.id = f.employee_id
     WHERE f.next_followup_date = CURDATE() $extra
     ORDER BY f.created_at DESC");

$upcoming = DB::fetchAll(
    "SELECT $selectCols
     FROM followups f
     JOIN leads l ON l.id = f.lead_id
     LEFT JOIN users u ON u.id = f.employee_id
     WHERE f.next_followup_date > CURDATE() $extra
     ORDER BY f.next_followup_date ASC LIMIT 20");

$overdue  = DB::fetchAll(
    "SELECT $selectCols
     FROM followups f
     JOIN leads l ON l.id = f.lead_id
     LEFT JOIN users u ON u.id = f.employee_id
     WHERE f.next_followup_date < CURDATE() AND f.next_followup_date IS NOT NULL $extra
     ORDER BY f.next_followup_date ASC LIMIT 20");

require_once __DIR__ . '/../includes/header.php';


function renderFollowupTable(array $rows, string $emptyMsg, int $uid, string $role): void { ?>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr><th>#</th><th>Lead</th><th>Phone</th><th>Note</th>
            <th>Next Date</th><th>Status</th><th>By</th><th>Action</th></tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $i => $f):
          $canEdit = ($role !== 'employee') || ((int)$f['employee_id'] === $uid);
        ?>
        <tr>
          <td><?= $i+1 ?></td>
          <td class="fw-600"><?= e($f['lead_name']) ?></td>
          <td><?= e($f['phone']) ?></td>
          <td class="text-truncate-2" style="max-width:200px"><?= e($f['note']) ?></td>
          <td><?= $f['next_followup_date'] ? date('d M Y', strtotime($f['next_followup_date'])) : '—' ?></td>
          <td><span class="badge-status status-<?= str_replace(' ','-',e($f['lead_status'])) ?>"><?= e($f['lead_status']) ?></span></td>
          <td>
            <?= e($f['emp_name']) ?>
            <?php if ($f['emp_deleted']): ?>
            <span class="emp-deleted-badge">Deleted</span>
            <?php endif; ?>
          </td>
          <td>
            <div class="d-flex gap-1">
              <a href="<?= APP_URL ?>/admin/lead-detail.php?id=<?= $f['lead_id'] ?>"
                 class="btn btn-xs btn-outline-primary" title="View Lead"><i class="bi bi-eye"></i></a>
              <?php if ($canEdit): ?>
              <button class="btn btn-xs btn-outline-secondary edit-fu-btn" title="Edit"
                      data-id="<?= $f['id'] ?>"
                      data-note="<?= e($f['note']) ?>"
                      data-date="<?= e($f['next_followup_date'] ?? '') ?>">
                <i class="bi bi-pencil"></i>
              </button>
              <button class="btn btn-xs btn-outline-danger delete-fu-btn" title="Delete"
                      data-id="<?= $f['id'] ?>" data-lead="<?= e($f['lead_name']) ?>">
                <i class="bi bi-trash"></i>
              </button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
        <tr><td colspan="8" class="text-center text-muted py-3"><?= $emptyMsg ?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
<?php } ?>

<style>
.emp-deleted-badge {
  font-size:.63rem; font-weight:700; color:#ef4444;
  background:#fee2e2; padding:.05rem .35rem; border-radius:10px; margin-left:.3rem;
}
.btn-xs{padding:.2rem .45rem;font-size:.75rem;border-radius:6px}
#editFuNote { resize: vertical; min-height: 90px; }
</style>

<div class="row g-3 mb-3">
  <div class="col-4">
    <div class="stat-card">
      <div class="stat-icon red"><i class="bi bi-exclamation-circle-fill"></i></div>
      <div><div class="stat-number"><?= count($overdue) ?></div><div class="stat-label">Overdue</div></div>
    </div>
  </div>
  <div class="col-4">
    <div class="stat-card">
      <div class="stat-icon amber"><i class="bi bi-calendar-day"></i></div>
      <div><div class="stat-number"><?= count($today) ?></div><div class="stat-label">Today</div></div>
    </div>
  </div>
  <div class="col-4">
    <div class="stat-card">
      <div class="stat-icon blue"><i class="bi bi-calendar-week"></i></div>
      <div><div class="stat-number"><?= count($upcoming) ?></div><div class="stat-label">Upcoming</div></div>
    </div>
  </div>
</div>

<!-- Overdue -->
<?php if ($overdue): ?>
<div class="card mb-3 border-danger">
  <div class="card-header text-danger">
    <i class="bi bi-exclamation-circle me-2"></i>Overdue Follow-ups
  </div>
  <?php renderFollowupTable($overdue, 'None', $uid, $role); ?>
</div>
<?php endif; ?>

<!-- Today -->
<div class="card mb-3">
  <div class="card-header"><i class="bi bi-calendar-day me-2 text-warning"></i>Today's Follow-ups</div>
  <?php renderFollowupTable($today, 'No follow-ups scheduled for today.', $uid, $role); ?>
</div>

<!-- Upcoming -->
<div class="card">
  <div class="card-header"><i class="bi bi-calendar-week me-2 text-primary"></i>Upcoming Follow-ups</div>
  <?php renderFollowupTable($upcoming, 'No upcoming follow-ups.', $uid, $role); ?>
</div>

<!-- ── Edit Follow-up Modal ─────────────────────────────────── -->
<div class="modal fade" id="editFuModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-700"><i class="bi bi-pencil text-primary me-2"></i>Edit Follow-up</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="editFuId">
        <div class="mb-3">
          <label class="form-label">Note</label>
          <textarea id="editFuNote" class="form-control" rows="4" maxlength="2000"></textarea>
        </div>
        <div class="mb-2">
          <label class="form-label">Next Follow-up Date</label>
          <input type="date" id="editFuDate" class="form-control">
        </div>
      </div>
      <div class="modal-footer border-0 pt-0 d-flex gap-2 justify-content-end">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" id="saveFuEditBtn">
          <i class="bi bi-check-lg me-1"></i>Save Changes
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ── Delete Confirm Modal ─────────────────────────────────── -->
<div class="modal fade" id="deleteFuModal" tabindex="-1">
  <div class="modal-dialog modal-sm modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-700"><i class="bi bi-trash text-danger me-2"></i>Delete Follow-up</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        Delete this follow-up for <strong id="deleteFuLead"></strong>?
        <div class="text-muted small mt-1">This cannot be undone.</div>
      </div>
      <div class="modal-footer border-0 pt-0 d-flex gap-2 justify-content-end">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger btn-sm" id="confirmDeleteFuBtn">
          <i class="bi bi-trash me-1"></i>Delete
        </button>
      </div>
    </div>
  </div>
</div>

<script>
const APP = '<?= APP_URL ?>';
let editModal, deleteModal, pendingDeleteId = null;

document.addEventListener('DOMContentLoaded', () => {
  editModal   = new bootstrap.Modal(document.getElementById('editFuModal'));
  deleteModal = new bootstrap.Modal(document.getElementById('deleteFuModal'));
});

document.querySelectorAll('.edit-fu-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById('editFuId').value   = btn.dataset.id;
    document.getElementById('editFuNote').value = btn.dataset.note;
    document.getElementById('editFuDate').value = btn.dataset.date || '';
    editModal.show();
  });
});

document.getElementById('saveFuEditBtn').addEventListener('click', async function() {
  const id   = document.getElementById('editFuId').value;
  const note = document.getElementById('editFuNote').value.trim();
  const date = document.getElementById('editFuDate').value;

  if (!note) { showToast('warning','Note cannot be empty.'); return; }

  this.disabled = true;
  this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving…';

  const fd = new FormData();
  fd.append('action','edit');
  fd.append('id', id);
  fd.append('note', note);
  fd.append('next_followup_date', date);

  try {
    const res = await fetch(APP + '/api/followups.php', { method:'POST', body:fd });
    const d   = await res.json();
    if (d.success) {
      showToast('success', d.message);
      editModal.hide();
      setTimeout(() => location.reload(), 600);
    } else {
      showToast('danger', d.message);
    }
  } catch(e) {
    showToast('danger','Network error.');
  } finally {
    this.disabled = false;
    this.innerHTML = '<i class="bi bi-check-lg me-1"></i>Save Changes';
  }
});

document.querySelectorAll('.delete-fu-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    pendingDeleteId = btn.dataset.id;
    document.getElementById('deleteFuLead').textContent = btn.dataset.lead;
    deleteModal.show();
  });
});

document.getElementById('confirmDeleteFuBtn').addEventListener('click', async function() {
  if (!pendingDeleteId) return;
  this.disabled = true;
  this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Deleting…';

  const fd = new FormData();
  fd.append('action','delete');
  fd.append('id', pendingDeleteId);

  try {
    const res = await fetch(APP + '/api/followups.php', { method:'POST', body:fd });
    const d   = await res.json();
    if (d.success) {
      showToast('success', d.message);
      deleteModal.hide();
      setTimeout(() => location.reload(), 600);
    } else {
      showToast('danger', d.message);
    }
  } catch(e) {
    showToast('danger','Network error.');
  } finally {
    this.disabled = false;
    this.innerHTML = '<i class="bi bi-trash me-1"></i>Delete';
  }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>