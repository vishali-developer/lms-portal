<?php
// admin/client-services/client-detail.php — Client Detail
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { redirect(APP_URL . '/admin/client-services/clients.php'); }

$client = DB::fetchOne(
    "SELECT c.*, COALESCE(u.name,'Unassigned') AS manager_name, cr.name AS creator_name
     FROM clients c
     LEFT JOIN users u  ON u.id = c.account_manager
     LEFT JOIN users cr ON cr.id = c.created_by
     WHERE c.id = ?", [$id]);

if (!$client) { setFlash('danger','Client not found.'); redirect(APP_URL.'/admin/client-services/clients.php'); }

// Employees may only view their own assigned clients
if ($_SESSION['user_role']==='employee' && $client['account_manager']!=$_SESSION['user_id']) {
    setFlash('danger','Access denied.'); redirect(APP_URL.'/employee/client-services/clients.php');
}

$notes = DB::fetchAll(
    "SELECT n.*, COALESCE(u.name,'Deleted Employee') AS emp_name
     FROM client_notes n
     LEFT JOIN users u ON u.id = n.employee_id
     WHERE n.client_id = ?
     ORDER BY n.created_at DESC", [$id]);

$managers = DB::fetchAll("SELECT id,name FROM users WHERE role='employee' AND status='active' ORDER BY name");
$statusOptions = ['Active','On Hold','Inactive','Completed'];
$svcList = $client['services'] ? array_filter(array_map('trim', explode(',', $client['services']))) : [];

$pageTitle  = 'Client: ' . $client['company'];
$activePage = 'clients';
require_once __DIR__ . '/../../includes/header.php';

$isAdmin = in_array($_SESSION['user_role'], ['admin','manager'], true);
$formBase = $isAdmin ? '/admin/client-services' : '/employee/client-services';
?>

<style>
.cs-status-badge {
  font-size:.68rem; font-weight:700; padding:.18rem .6rem; border-radius:20px; white-space:nowrap;
}
.cs-status-Active    { background:#d1fae5; color:#059669; }
.cs-status-On-Hold   { background:#fef3c7; color:#d97706; }
.cs-status-Inactive  { background:#f1f5f9; color:#64748b; }
.cs-status-Completed { background:#dbeafe; color:#2563eb; }
.cs-chip {
  font-size:.7rem; background:var(--bg); border:1px solid var(--border); color:var(--text-muted);
  padding:.15rem .55rem; border-radius:6px; display:inline-block; margin:0 .25rem .25rem 0;
}
</style>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb small">
    <li class="breadcrumb-item"><a href="<?= APP_URL.$formBase ?>/clients.php">Clients</a></li>
    <li class="breadcrumb-item active"><?= e($client['company']) ?></li>
  </ol>
</nav>

<div class="row g-3">
  <!-- Left: Client Info -->
  <div class="col-12 col-xl-4">
    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-briefcase me-2 text-primary"></i>Client Info</span>
        <a href="<?= APP_URL.$formBase ?>/client-form.php?id=<?= $id ?>" class="btn btn-xs btn-outline-secondary">
          <i class="bi bi-pencil"></i>
        </a>
      </div>
      <div class="card-body">
        <div class="d-flex align-items-center gap-3 mb-3">
          <div class="stat-icon blue" style="width:56px;height:56px;font-size:1.6rem;border-radius:16px;">
            <?= strtoupper(substr($client['company'],0,1)) ?>
          </div>
          <div>
            <h5 class="mb-0 fw-700"><?= e($client['company']) ?></h5>
            <span class="cs-status-badge cs-status-<?= str_replace(' ','-',e($client['status'])) ?>">
              <?= e($client['status']) ?>
            </span>
          </div>
        </div>

        <table class="table table-sm table-borderless mb-0" style="font-size:.85rem">
          <tr>
            <td class="text-muted" width="35%"><i class="bi bi-person me-1"></i>Contact</td>
            <td><?= e($client['name']) ?></td>
          </tr>
          <tr>
            <td class="text-muted"><i class="bi bi-telephone me-1"></i>Phone</td>
            <td><a href="tel:<?= e($client['phone']) ?>"><?= e($client['phone']) ?></a></td>
          </tr>
          <tr>
            <td class="text-muted"><i class="bi bi-envelope me-1"></i>Email</td>
            <td><?= $client['email'] ? '<a href="mailto:'.e($client['email']).'">'.e($client['email']).'</a>' : '—' ?></td>
          </tr>
          <tr>
            <td class="text-muted"><i class="bi bi-geo-alt me-1"></i>Location</td>
            <td><?= e($client['location'] ?: '—') ?></td>
          </tr>
          <tr>
            <td class="text-muted"><i class="bi bi-person-badge me-1"></i>Account Manager</td>
            <td><?= e($client['manager_name']) ?></td>
          </tr>
          <tr>
            <td class="text-muted"><i class="bi bi-cash-stack me-1"></i>Contract Value</td>
            <td>
              <?php if ($client['contract_value']): ?>
              ₹<?= number_format($client['contract_value']) ?><?= $client['billing_cycle'] ? ' / '.e($client['billing_cycle']) : '' ?>
              <?php else: ?>—<?php endif; ?>
            </td>
          </tr>
          <tr>
            <td class="text-muted"><i class="bi bi-calendar3 me-1"></i>Client Since</td>
            <td><?= $client['start_date'] ? date('d M Y', strtotime($client['start_date'])) : '—' ?></td>
          </tr>
          <tr>
            <td class="text-muted"><i class="bi bi-person-plus me-1"></i>Added by</td>
            <td><?= e($client['creator_name'] ?: '—') ?></td>
          </tr>
        </table>

        <?php if ($svcList): ?>
        <hr>
        <div class="text-muted small fw-600 mb-1">Services Subscribed</div>
        <?php foreach ($svcList as $svc): ?>
        <span class="cs-chip"><?= e($svc) ?></span>
        <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($client['notes']): ?>
        <hr>
        <div class="text-muted small fw-600 mb-1">Notes</div>
        <p class="small mb-0"><?= nl2br(e($client['notes'])) ?></p>
        <?php endif; ?>
      </div>
    </div>

    <!-- Quick update -->
    <div class="card">
      <div class="card-header">Quick Update</div>
      <div class="card-body">
        <label class="form-label">Status</label>
        <select class="form-select status-select mb-3" data-id="<?= $id ?>">
          <?php foreach ($statusOptions as $s): ?>
          <option value="<?= e($s) ?>" <?= $client['status']===$s?'selected':'' ?>><?= e($s) ?></option>
          <?php endforeach; ?>
        </select>

        <?php if ($isAdmin): ?>
        <label class="form-label">Account Manager</label>
        <select class="form-select assign-select" data-id="<?= $id ?>">
          <option value="">Unassigned</option>
          <?php foreach ($managers as $m): ?>
          <option value="<?= $m['id'] ?>" <?= $client['account_manager']==$m['id']?'selected':'' ?>>
            <?= e($m['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Right: Notes / Check-ins -->
  <div class="col-12 col-xl-8">
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-plus-circle me-2 text-primary"></i>Add Note / Check-in</div>
      <div class="card-body">
        <form id="clientNoteForm">
          <input type="hidden" name="client_id" value="<?= $id ?>">
          <div class="row g-2">
            <div class="col-12">
              <textarea name="note" class="form-control" rows="3"
                        placeholder="Write a check-in note, renewal update, or anything worth logging..." required></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label">Next Check-in Date</label>
              <input type="date" name="next_checkin_date" class="form-control" min="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-6 d-flex align-items-end">
              <button type="submit" class="btn btn-primary w-100">
                <i class="bi bi-check-circle me-1"></i>Save Note
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <i class="bi bi-clock-history me-2 text-primary"></i>
        Note History (<?= count($notes) ?>)
      </div>
      <div class="card-body">
        <?php if ($notes): ?>
        <div class="timeline">
          <?php foreach ($notes as $n): ?>
          <div class="timeline-item">
            <div class="timeline-dot"></div>
            <div class="timeline-body">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="fw-600 small"><?= e($n['emp_name']) ?></span>
                <span class="timeline-date"><?= date('d M Y, h:i A', strtotime($n['created_at'])) ?></span>
              </div>
              <p class="mb-1 small"><?= nl2br(e($n['note'])) ?></p>
              <?php if ($n['next_checkin_date']): ?>
              <span class="badge bg-warning text-dark" style="font-size:.72rem;">
                <i class="bi bi-calendar me-1"></i>
                Next: <?= date('d M Y', strtotime($n['next_checkin_date'])) ?>
              </span>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p class="text-muted small mb-0 text-center py-2">No notes yet.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
// Status select AJAX
document.querySelectorAll('.status-select').forEach(sel => {
  sel.addEventListener('change', async function() {
    const fd = new FormData();
    fd.append('action','update-status');
    fd.append('id', this.dataset.id);
    fd.append('status', this.value);
    const res = await fetch('<?= APP_URL ?>/api/clients.php', {method:'POST',body:fd});
    const d = await res.json();
    showToast(d.success?'success':'danger', d.message);
  });
});

// Account manager select AJAX (admin/manager only — element doesn't exist for employees)
document.querySelectorAll('.assign-select').forEach(sel => {
  sel.addEventListener('change', async function() {
    const fd = new FormData();
    fd.append('action','assign');
    fd.append('id', this.dataset.id);
    fd.append('employee_id', this.value);
    const res = await fetch('<?= APP_URL ?>/api/clients.php', {method:'POST',body:fd});
    const d = await res.json();
    showToast(d.success?'success':'danger', d.message);
  });
});

// Add note form
document.getElementById('clientNoteForm').addEventListener('submit', async function(e) {
  e.preventDefault();
  const btn = this.querySelector('button[type="submit"]');
  const original = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving…';

  const fd = new FormData(this);
  fd.append('action','add-note');

  try {
    const res = await fetch('<?= APP_URL ?>/api/clients.php', {method:'POST', body:fd});
    const d = await res.json();
    if (d.success) {
      showToast('success', d.message);
      setTimeout(() => location.reload(), 600);
    } else {
      showToast('danger', d.message);
    }
  } catch (e) {
    showToast('danger', 'Network error.');
  } finally {
    btn.disabled = false;
    btn.innerHTML = original;
  }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>