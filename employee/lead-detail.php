<?php
// admin/lead-detail.php — Lead Detail (follow-up edit/delete enabled)
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$uid  = $_SESSION['user_id'];
$role = $_SESSION['user_role'];

// Employees live under /employee/, everyone else under /admin/ — used for
// breadcrumb + redirect targets so employees never get bounced to admin-only pages.
$basePath = $role === 'employee' ? '/employee' : '/admin';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { redirect(APP_URL . $basePath . '/leads.php'); }

$lead = DB::fetchOne(
    "SELECT l.*, u.name AS assigned_name, u.email AS assigned_email,
            s.name AS source_name, c.name AS creator_name
     FROM leads l
     LEFT JOIN users u  ON u.id  = l.assigned_to
     LEFT JOIN lead_sources s ON s.id = l.source_id
     LEFT JOIN users c  ON c.id  = l.created_by
     WHERE l.id = ?", [$id]);

if (!$lead) { setFlash('danger','Lead not found.'); redirect(APP_URL.$basePath.'/leads.php'); }

// For employees, only show their own leads
if ($role === 'employee' && $lead['assigned_to'] != $uid) {
    setFlash('danger','Access denied.'); redirect(APP_URL.'/employee/leads.php');
}

// LEFT JOIN users — follow-ups stay visible even if the adder's account was deleted.
// COALESCE falls back to the snapshot name, then a generic label.
$followups = DB::fetchAll(
    "SELECT f.*,
            COALESCE(u.name, f.employee_name_snapshot, 'Deleted Employee') AS emp_name,
            (u.id IS NULL) AS emp_deleted
     FROM followups f
     LEFT JOIN users u ON u.id = f.employee_id
     WHERE f.lead_id = ?
     ORDER BY f.created_at DESC", [$id]);

$activities = DB::fetchAll(
    "SELECT al.*, u.name AS user_name
     FROM activity_logs al
     LEFT JOIN users u ON u.id = al.user_id
     WHERE al.description LIKE ?
     ORDER BY al.created_at DESC LIMIT 20",
    ["%#$id%"]);

$employees = DB::fetchAll("SELECT id,name FROM users WHERE role='employee' AND status='active'");
$statuses  = ['Untouched','New','Contacted','Follow-up','Interested','Converted','Closed','Rejected'];

if ($lead['lead_type'] === 'Outbound Leads') {
    $listPage = 'outbound-leads.php';
} elseif ($lead['lead_type'] === 'Inbound Leads') {
    $listPage = 'inbound-leads.php';
} else {
    $listPage = 'leads.php';
}

$pageTitle  = 'Lead: ' . $lead['name'];
$activePage = 'leads';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.emp-deleted-badge {
  font-size:.63rem; font-weight:700; color:#ef4444;
  background:#fee2e2; padding:.05rem .35rem; border-radius:10px; margin-left:.3rem;
}
.fu-actions { display:flex; gap:.3rem; flex-shrink:0; }
.fu-actions .btn-xs { padding:.15rem .4rem; font-size:.7rem; border-radius:5px; }
.timeline-item .timeline-body { position: relative; }
#editFuNote { resize: vertical; min-height: 90px; }
</style>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb small">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?><?= $basePath ?>/<?= $listPage ?>">Leads</a></li>
    <li class="breadcrumb-item active"><?= e($lead['name']) ?></li>
  </ol>
</nav>

<div class="row g-3">
  <!-- Left: Lead Info -->
  <div class="col-12 col-xl-4">
    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-person-circle me-2 text-primary"></i>Lead Info</span>
        <?php if (in_array($role,['admin','manager'])): ?>
        <a href="<?= APP_URL ?>/admin/lead-form.php?id=<?= $id ?>" class="btn btn-xs btn-outline-secondary">
          <i class="bi bi-pencil"></i>
        </a>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <div class="d-flex align-items-center gap-3 mb-3">
          <div class="stat-icon blue" style="width:56px;height:56px;font-size:1.6rem;border-radius:16px;">
            <?= strtoupper(substr($lead['name'],0,1)) ?>
          </div>
          <div>
            <h5 class="mb-0 fw-700"><?= e($lead['name']) ?></h5>
            <span class="badge-status status-<?= str_replace(' ','-',e($lead['status'])) ?>">
              <?= e($lead['status']) ?>
            </span>
            <span class="ms-1 fw-600 priority-<?= e($lead['priority']) ?>"><?= e($lead['priority']) ?></span>
          </div>
        </div>

        <table class="table table-sm table-borderless mb-0" style="font-size:.85rem">
          <tr>
            <td class="text-muted" width="35%"><i class="bi bi-person me-1"></i>First Name</td>
            <td><?= e($lead['first_name'] ?? '') ?: '—' ?></td>
          </tr>
          <tr>
            <td class="text-muted"><i class="bi bi-person me-1"></i>Last Name</td>
            <td><?= e($lead['last_name'] ?? '') ?: '—' ?></td>
          </tr>
          <tr>
            <td class="text-muted"><i class="bi bi-telephone me-1"></i>Phone</td>
            <td><a href="tel:<?= e($lead['phone']) ?>"><?= e($lead['phone']) ?></a></td>
          </tr>
          <tr>
            <td class="text-muted"><i class="bi bi-envelope me-1"></i>Email</td>
            <td><?= $lead['email'] ? '<a href="mailto:'.e($lead['email']).'">'.e($lead['email']).'</a>' : '—' ?></td>
          </tr>
          <tr>
            <td class="text-muted"><i class="bi bi-building me-1"></i>Company</td>
            <td><?= e($lead['company'] ?: '—') ?></td>
          </tr>
          <tr>
            <td class="text-muted"><i class="bi bi-geo-alt me-1"></i>Location</td>
            <td><?= e($lead['location'] ?? '') ?: '—' ?></td>
          </tr>
          <tr>
            <td class="text-muted"><i class="bi bi-tools me-1"></i>Service</td>
            <td><?= e($lead['service'] ?: '—') ?></td>
          </tr>
          <tr>
            <td class="text-muted"><i class="bi bi-signpost-split me-1"></i>Lead Type</td>
            <td><?= e($lead['lead_type'] ?? '') ?: '—' ?></td>
          </tr>
          <tr>
            <td class="text-muted"><i class="bi bi-broadcast me-1"></i>Source</td>
            <td><?= e($lead['source_name'] ?: '—') ?></td>
          </tr>
          <tr>
            <td class="text-muted"><i class="bi bi-person me-1"></i>Assigned</td>
            <td><?= e($lead['assigned_name'] ?: 'Unassigned') ?></td>
          </tr>
          <tr>
            <td class="text-muted"><i class="bi bi-calendar me-1"></i>Created</td>
            <td><?= date('d M Y, h:i A', strtotime($lead['created_at'])) ?></td>
          </tr>
          <tr>
            <td class="text-muted"><i class="bi bi-person-plus me-1"></i>Created by</td>
            <td><?= e($lead['creator_name'] ?: '—') ?></td>
          </tr>
        </table>

        <?php if ($lead['message']): ?>
        <hr>
        <div class="text-muted small fw-600 mb-1">Message / Notes</div>
        <p class="small mb-0"><?= nl2br(e($lead['message'])) ?></p>
        <?php endif; ?>
      </div>
    </div>

    <!-- Quick status update -->
    <div class="card">
      <div class="card-header">Quick Update</div>
      <div class="card-body">
        <label class="form-label">Status</label>
        <select class="form-select status-select mb-3" data-id="<?= $id ?>">
          <?php foreach ($statuses as $s): ?>
          <option value="<?= $s ?>" <?= $lead['status']===$s?'selected':'' ?>><?= $s ?></option>
          <?php endforeach; ?>
        </select>

        <?php if (in_array($role,['admin','manager'])): ?>
        <label class="form-label">Assign To</label>
        <select class="form-select assign-select" data-id="<?= $id ?>">
          <option value="">Unassigned</option>
          <?php foreach ($employees as $emp): ?>
          <option value="<?= $emp['id'] ?>" <?= $lead['assigned_to']==$emp['id']?'selected':'' ?>>
            <?= e($emp['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Right: Follow-ups & Activity -->
  <div class="col-12 col-xl-8">
    <!-- Add Follow-up -->
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-plus-circle me-2 text-primary"></i>Add Follow-up</div>
      <div class="card-body">
        <form id="followupForm">
          <input type="hidden" name="lead_id" value="<?= $id ?>">
          <div class="row g-2">
            <div class="col-12">
              <textarea name="note" class="form-control" rows="3"
                        placeholder="Write follow-up notes..." required></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label">Next Follow-up Date</label>
              <input type="date" name="next_followup_date" class="form-control"
                     min="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-6 d-flex align-items-end">
              <button type="submit" class="btn btn-primary w-100">
                <i class="bi bi-check-circle me-1"></i>Save Follow-up
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>

    <!-- Follow-up timeline -->
    <div class="card mb-3">
      <div class="card-header">
        <i class="bi bi-clock-history me-2 text-primary"></i>
        Follow-up History (<span id="fuCount"><?= count($followups) ?></span>)
      </div>
      <div class="card-body">
        <div class="timeline" id="followupTimeline">
          <?php foreach ($followups as $fu):
            $canEdit = ($role !== 'employee') || ((int)$fu['employee_id'] === $uid);
          ?>
          <div class="timeline-item" data-fu-id="<?= $fu['id'] ?>">
            <div class="timeline-dot"></div>
            <div class="timeline-body">
              <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                <span class="fw-600 small">
                  <?= e($fu['emp_name']) ?>
                  <?php if ($fu['emp_deleted']): ?>
                  <span class="emp-deleted-badge">Deleted</span>
                  <?php endif; ?>
                </span>
                <div class="d-flex align-items-center gap-2 flex-shrink-0">
                  <span class="timeline-date"><?= date('d M Y, h:i A', strtotime($fu['created_at'])) ?></span>
                  <?php if ($canEdit): ?>
                  <div class="fu-actions">
                    <button class="btn btn-xs btn-outline-secondary edit-fu-btn" title="Edit"
                            data-id="<?= $fu['id'] ?>"
                            data-note="<?= e($fu['note']) ?>"
                            data-date="<?= e($fu['next_followup_date'] ?? '') ?>">
                      <i class="bi bi-pencil"></i>
                    </button>
                    <button class="btn btn-xs btn-outline-danger delete-fu-btn" title="Delete"
                            data-id="<?= $fu['id'] ?>">
                      <i class="bi bi-trash"></i>
                    </button>
                  </div>
                  <?php endif; ?>
                </div>
              </div>
              <p class="mb-1 small fu-note-text"><?= nl2br(e($fu['note'])) ?></p>
              <span class="badge bg-warning text-dark fu-date-badge"
                    style="font-size:.72rem; <?= $fu['next_followup_date'] ? '' : 'display:none;' ?>">
                <i class="bi bi-calendar me-1"></i>
                Next: <span class="fu-date-text"><?= $fu['next_followup_date'] ? date('d M Y', strtotime($fu['next_followup_date'])) : '' ?></span>
              </span>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <p class="text-muted small mb-0 text-center py-2" id="noFollowupsMsg"
           style="<?= $followups ? 'display:none;' : '' ?>">
          No follow-ups yet.
        </p>
      </div>
    </div>
  </div>
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
        Delete this follow-up entry?
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
let editFuModal, deleteFuModal, pendingDeleteId = null;

document.addEventListener('DOMContentLoaded', () => {
  editFuModal   = new bootstrap.Modal(document.getElementById('editFuModal'));
  deleteFuModal = new bootstrap.Modal(document.getElementById('deleteFuModal'));
  wireFuButtons();
});

function wireFuButtons() {
  document.querySelectorAll('.edit-fu-btn').forEach(btn => {
    btn.onclick = () => {
      document.getElementById('editFuId').value   = btn.dataset.id;
      document.getElementById('editFuNote').value = btn.dataset.note;
      document.getElementById('editFuDate').value = btn.dataset.date || '';
      editFuModal.show();
    };
  });
  document.querySelectorAll('.delete-fu-btn').forEach(btn => {
    btn.onclick = () => {
      pendingDeleteId = btn.dataset.id;
      deleteFuModal.show();
    };
  });
}

// ── Save edit (updates the DOM in place, no reload) ─────────────
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
      // Update the specific timeline item in place
      const item = document.querySelector(`.timeline-item[data-fu-id="${id}"]`);
      if (item) {
        item.querySelector('.fu-note-text').innerHTML = note.replace(/\n/g,'<br>');
        const badge = item.querySelector('.fu-date-badge');
        const dateText = item.querySelector('.fu-date-text');
        if (d.date_fmt) {
          dateText.textContent = d.date_fmt;
          badge.style.display = '';
        } else {
          badge.style.display = 'none';
        }
      }
      showToast('success', d.message);
      editFuModal.hide();
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

// ── Confirm delete (removes the DOM node in place, no reload) ───
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
      const item = document.querySelector(`.timeline-item[data-fu-id="${pendingDeleteId}"]`);
      if (item) item.remove();

      // Update count + show "no follow-ups" message if list is now empty
      const countEl = document.getElementById('fuCount');
      const newCount = Math.max(0, parseInt(countEl.textContent) - 1);
      countEl.textContent = newCount;
      if (newCount === 0) {
        document.getElementById('noFollowupsMsg').style.display = 'block';
      }
      showToast('success', d.message);
      deleteFuModal.hide();
    } else {
      showToast('danger', d.message);
    }
  } catch(e) {
    showToast('danger','Network error.');
  } finally {
    this.disabled = false;
    this.innerHTML = '<i class="bi bi-trash me-1"></i>Delete';
    pendingDeleteId = null;
  }
});

// ── Add follow-up (existing behaviour, now also appends to timeline live) ──
document.getElementById('followupForm').addEventListener('submit', async function(e) {
  e.preventDefault();
  const btn = this.querySelector('button[type="submit"]');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving…';

  const fd = new FormData(this);
  fd.append('action','add');

  try {
    const res = await fetch(APP + '/api/followups.php', { method:'POST', body:fd });
    const d   = await res.json();
    if (d.success) {
      showToast('success', d.message);
      setTimeout(() => location.reload(), 700); // reload to re-render full timeline + status
    } else {
      showToast('danger', d.message);
    }
  } catch(e) {
    showToast('danger','Network error.');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Save Follow-up';
  }
});

// ── Status select AJAX ──────────────────────────────────────────
document.querySelectorAll('.status-select').forEach(sel => {
  sel.addEventListener('change', async function() {
    const fd = new FormData();
    fd.append('action','update-status');
    fd.append('id', this.dataset.id);
    fd.append('status', this.value);
    const res = await fetch(APP + '/api/leads.php', {method:'POST',body:fd});
    const d = await res.json();
    showToast(d.success?'success':'danger', d.message);
  });
});

// ── Assign select AJAX ──────────────────────────────────────────
document.querySelectorAll('.assign-select').forEach(sel => {
  sel.addEventListener('change', async function() {
    const fd = new FormData();
    fd.append('action','assign');
    fd.append('id', this.dataset.id);
    fd.append('employee_id', this.value);
    const res = await fetch(APP + '/api/leads.php', {method:'POST',body:fd});
    const d = await res.json();
    showToast(d.success?'success':'danger', d.message);
  });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>