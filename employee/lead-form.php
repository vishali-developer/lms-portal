<?php
// employee/lead-form.php — Employee: Add / Edit Own Lead
// Employees can create leads and edit leads already assigned to them.
// There is no Assign To field here — every lead an employee creates is
// automatically assigned to themselves, and they can never reassign it.
require_once __DIR__ . '/../includes/auth.php';
requireRole(['employee']);

$uid = $_SESSION['user_id'];

$id   = (int)($_GET['id'] ?? 0);
$lead = $id ? DB::fetchOne("SELECT * FROM leads WHERE id=?", [$id]) : null;

// An employee may only edit a lead that is actually assigned to them
if ($lead && (int)$lead['assigned_to'] !== $uid) {
    setFlash('danger', 'You can only edit leads assigned to you.');
    redirect(APP_URL . '/employee/leads.php');
}

$isEdit = (bool)$lead;

$pageTitle  = $isEdit ? 'Edit Lead' : 'Add Lead';
$activePage = 'leads';

$sources    = DB::fetchAll("SELECT id,name FROM lead_sources WHERE status='active' ORDER BY name");
$statuses   = ['Untouched','New','Contacted','Follow-up','Interested','Converted','Closed','Rejected'];
$priorities = ['Low','Medium','High'];

$serviceOptions = [
    'Search Engine Optimization',
    'Online Reputation Management',
    'Social Media Monitoring & Listening',
    'Ecommerce SEO',
    'Social Media Management',
    'Digital Brand Launch',
    'Review Management',
    'Local SEO',
    'Campaign Management',
    'Website Designing',
    'Crisis Management',
];

$leadTypes = ['Inbound Leads', 'Outbound Leads'];

$errors = [];
$data   = $lead ?? [];

// Pre-select Lead Type when coming from the Inbound/Outbound "Add Lead" buttons
if (!$isEdit && empty($data['lead_type']) && in_array($_GET['lead_type'] ?? '', $leadTypes, true)) {
    $data['lead_type'] = $_GET['lead_type'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'CSRF token error.';
    } else {
        $data = [
            'first_name'   => trim($_POST['first_name'] ?? ''),
            'last_name'    => trim($_POST['last_name'] ?? ''),
            'phone'        => trim($_POST['phone'] ?? ''),
            'email'        => trim($_POST['email'] ?? ''),
            'company'      => trim($_POST['company'] ?? ''),
            'location'     => trim($_POST['location'] ?? ''),
            'service'      => trim($_POST['service'] ?? ''),
            'lead_type'    => trim($_POST['lead_type'] ?? ''),
            'message'      => trim($_POST['message'] ?? ''),
            'source_id'    => $_POST['source_id'] ?? '',       // numeric id, '' or "other"
            'source_other' => trim($_POST['source_other'] ?? ''),
            'status'       => $_POST['status'] ?? 'New',
            'priority'     => $_POST['priority'] ?? 'Medium',
        ];

        if (!$data['first_name']) $errors[] = 'First name is required.';
        if (!$data['last_name'])  $errors[] = 'Last name is required.';
        if (!$data['phone'])      $errors[] = 'Phone number is required.';
        if ($data['email'] && !filter_var($data['email'], FILTER_VALIDATE_EMAIL))
            $errors[] = 'Invalid email address.';
        if ($data['source_id'] === 'other' && !$data['source_other'])
            $errors[] = 'Please specify the lead source.';

        if (!$errors) {
            // Resolve "Other" lead source into a real lead_sources row
            $sourceId = $data['source_id'] ?: null;
            if ($sourceId === 'other') {
                $existingSrc = DB::fetchOne("SELECT id FROM lead_sources WHERE name=?", [$data['source_other']]);
                if ($existingSrc) {
                    $sourceId = $existingSrc['id'];
                } else {
                    DB::query("INSERT INTO lead_sources (name,status) VALUES (?, 'active')", [$data['source_other']]);
                    $sourceId = DB::lastInsertId();
                }
            }

            $fullName = trim($data['first_name'] . ' ' . $data['last_name']);

            $dbData = [
                'first_name'  => $data['first_name'],
                'last_name'   => $data['last_name'],
                'name'        => $fullName, // kept in sync for legacy lookups (lists, notifications, search)
                'phone'       => $data['phone'],
                'email'       => $data['email'],
                'company'     => $data['company'],
                'location'    => $data['location'],
                'service'     => $data['service'],
                'lead_type'   => $data['lead_type'],
                'message'     => $data['message'],
                'source_id'   => $sourceId,
                'status'      => $data['status'],
                'priority'    => $data['priority'],
                'assigned_to' => $uid, // always self — never selectable by the employee
            ];

            if ($isEdit) {
                DB::query(
                    "UPDATE leads SET first_name=?,last_name=?,name=?,phone=?,email=?,company=?,location=?,
                     service=?,lead_type=?,message=?,source_id=?,status=?,priority=?,assigned_to=? WHERE id=?",
                    [...array_values($dbData), $id]
                );
                logActivity($uid, 'Edit Lead', "Lead #$id updated");
                setFlash('success', 'Lead updated successfully.');
            } else {
                DB::query(
                    "INSERT INTO leads (first_name,last_name,name,phone,email,company,location,
                     service,lead_type,message,source_id,status,priority,assigned_to,created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                    [...array_values($dbData), $uid]
                );
                $newId = DB::lastInsertId();
                logActivity($uid, 'Create Lead', "Lead #$newId created");
                setFlash('success', 'Lead added successfully.');
            }

            if ($dbData['lead_type'] === 'Outbound Leads') {
                $listPage = 'outbound-leads.php';
            } elseif ($dbData['lead_type'] === 'Inbound Leads') {
                $listPage = 'inbound-leads.php';
            } else {
                $listPage = 'leads.php';
            }
            redirect(APP_URL . '/employee/' . $listPage);
        }
    }
}

if (($data['lead_type'] ?? '') === 'Outbound Leads') {
    $backListPage = 'outbound-leads.php';
} elseif (($data['lead_type'] ?? '') === 'Inbound Leads') {
    $backListPage = 'inbound-leads.php';
} else {
    $backListPage = 'leads.php';
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center">
  <div class="col-12 col-xl-9">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-<?= $isEdit?'pencil':'plus-circle' ?> me-2 text-primary"></i><?= $pageTitle ?></span>
        <a href="<?= APP_URL ?>/employee/<?= $backListPage ?>" class="btn btn-sm btn-outline-secondary">
          <i class="bi bi-arrow-left me-1"></i>Back
        </a>
      </div>
      <div class="card-body">

        <?php foreach ($errors as $err): ?>
        <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle me-2"></i><?= e($err) ?></div>
        <?php endforeach; ?>

        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

          <div class="row g-3">
            <!-- Contact Info -->
            <div class="col-12"><h6 class="text-muted fw-600 border-bottom pb-1">Contact Information</h6></div>

            <div class="col-md-6">
              <label class="form-label">First Name <span class="text-danger">*</span></label>
              <input type="text" name="first_name" class="form-control"
                     value="<?= e($data['first_name']??'') ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Last Name <span class="text-danger">*</span></label>
              <input type="text" name="last_name" class="form-control"
                     value="<?= e($data['last_name']??'') ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Phone <span class="text-danger">*</span></label>
              <input type="tel" name="phone" class="form-control"
                     value="<?= e($data['phone']??'') ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Email</label>
              <input type="email" name="email" class="form-control"
                     value="<?= e($data['email']??'') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Company</label>
              <input type="text" name="company" class="form-control"
                     value="<?= e($data['company']??'') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Location</label>
              <input type="text" name="location" class="form-control"
                     placeholder="City, State / Country"
                     value="<?= e($data['location']??'') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Services</label>
              <select name="service" class="form-select">
                <option value="">— Select Service —</option>
                <?php foreach ($serviceOptions as $svc): ?>
                <option value="<?= e($svc) ?>" <?= ($data['service']??'')===$svc?'selected':'' ?>>
                  <?= e($svc) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Lead Type</label>
              <select name="lead_type" class="form-select">
                <option value="">— Select Lead Type —</option>
                <?php foreach ($leadTypes as $lt): ?>
                <option value="<?= e($lt) ?>" <?= ($data['lead_type']??'')===$lt?'selected':'' ?>>
                  <?= e($lt) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Lead Source</label>
              <select name="source_id" id="sourceSelect" class="form-select">
                <option value="">— Select Source —</option>
                <?php foreach ($sources as $src): ?>
                <option value="<?= $src['id'] ?>"
                  <?= ($data['source_id']??'')==$src['id']?'selected':'' ?>>
                  <?= e($src['name']) ?>
                </option>
                <?php endforeach; ?>
                <option value="other" <?= (($data['source_id']??'')==='other')?'selected':'' ?>>Other</option>
              </select>
            </div>
            <div class="col-md-6 <?= (($data['source_id']??'')==='other') ? '' : 'd-none' ?>" id="sourceOtherWrap">
              <label class="form-label">Specify Lead Source</label>
              <input type="text" name="source_other" id="sourceOtherInput" class="form-control"
                     placeholder="Enter lead source name"
                     value="<?= e($data['source_other']??'') ?>">
            </div>

            <!-- Lead details -->
            <div class="col-12 mt-2"><h6 class="text-muted fw-600 border-bottom pb-1">Lead Details</h6></div>

            <div class="col-md-6">
              <label class="form-label">Status</label>
              <select name="status" class="form-select">
                <?php foreach ($statuses as $s): ?>
                <option value="<?= $s ?>" <?= ($data['status']??'New')===$s?'selected':'' ?>><?= $s ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Priority</label>
              <select name="priority" class="form-select">
                <?php foreach ($priorities as $p): ?>
                <option value="<?= $p ?>" <?= ($data['priority']??'Medium')===$p?'selected':'' ?>><?= $p ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- No Assign To field: every lead an employee creates is auto-assigned to them -->
            <div class="col-12">
              <div class="small text-muted bg-body-secondary rounded p-2">
                <i class="bi bi-info-circle me-1"></i>
                This lead will be assigned to <strong>you</strong> automatically.
              </div>
            </div>

            <div class="col-12">
              <label class="form-label">Message / Notes</label>
              <textarea name="message" class="form-control" rows="4"
                        placeholder="Customer's requirement or notes..."
                        ><?= e($data['message']??'') ?></textarea>
            </div>

            <div class="col-12 d-flex gap-2 justify-content-end">
              <a href="<?= APP_URL ?>/employee/<?= $backListPage ?>" class="btn btn-outline-secondary">Cancel</a>
              <button type="submit" class="btn btn-primary px-4">
                <i class="bi bi-check-lg me-1"></i><?= $isEdit?'Update Lead':'Add Lead' ?>
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const sourceSelect = document.getElementById('sourceSelect');
  const otherWrap    = document.getElementById('sourceOtherWrap');
  const otherInput   = document.getElementById('sourceOtherInput');

  function toggleSourceOther() {
    const isOther = sourceSelect.value === 'other';
    otherWrap.classList.toggle('d-none', !isOther);
    otherInput.required = isOther;
  }

  sourceSelect.addEventListener('change', toggleSourceOther);
  toggleSourceOther();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>