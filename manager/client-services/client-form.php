<?php
// manager/client-services/client-form.php — Manager: Add / Edit Client
require_once __DIR__ . '/../../includes/auth.php';
requireRole(['admin','manager']);

$id     = (int)($_GET['id'] ?? 0);
$client = $id ? DB::fetchOne("SELECT * FROM clients WHERE id=?", [$id]) : null;
$isEdit = (bool)$client;

$pageTitle  = $isEdit ? 'Edit Client' : 'Add Client';
$activePage = 'clients';

$managers = DB::fetchAll("SELECT id,name FROM users WHERE role='employee' AND status='active' ORDER BY name");

$statusOptions = ['Active','On Hold','Inactive','Completed'];
$billingOptions = ['One-time','Monthly','Quarterly','Yearly'];
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

$errors = [];
$data   = $client ?? [];
$selectedServices = $isEdit && $client['services']
    ? array_filter(array_map('trim', explode(',', $client['services'])))
    : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'CSRF token error.';
    } else {
        $postedServices = array_filter($_POST['services'] ?? [], fn($s) => in_array($s, $serviceOptions, true));
        $selectedServices = $postedServices;

        $data = [
            'company'         => trim($_POST['company'] ?? ''),
            'first_name'      => trim($_POST['first_name'] ?? ''),
            'last_name'       => trim($_POST['last_name'] ?? ''),
            'phone'           => trim($_POST['phone'] ?? ''),
            'email'           => trim($_POST['email'] ?? ''),
            'location'        => trim($_POST['location'] ?? ''),
            'status'          => $_POST['status'] ?? 'Active',
            'account_manager' => $_POST['account_manager'] ?: null,
            'contract_value'  => trim($_POST['contract_value'] ?? ''),
            'billing_cycle'   => $_POST['billing_cycle'] ?? '',
            'start_date'      => $_POST['start_date'] ?? '',
            'notes'           => trim($_POST['notes'] ?? ''),
        ];

        if (!$data['company'])    $errors[] = 'Company name is required.';
        if (!$data['first_name']) $errors[] = 'First name is required.';
        if (!$data['last_name'])  $errors[] = 'Last name is required.';
        if (!$data['phone'])      $errors[] = 'Phone number is required.';
        if ($data['email'] && !filter_var($data['email'], FILTER_VALIDATE_EMAIL))
            $errors[] = 'Invalid email address.';
        if ($data['contract_value'] !== '' && !is_numeric($data['contract_value']))
            $errors[] = 'Contract value must be a number.';

        if (!$errors) {
            $fullName = trim($data['first_name'] . ' ' . $data['last_name']);

            $dbData = [
                'company'         => $data['company'],
                'first_name'      => $data['first_name'],
                'last_name'       => $data['last_name'],
                'name'            => $fullName,
                'phone'           => $data['phone'],
                'email'           => $data['email'],
                'location'        => $data['location'],
                'services'        => implode(',', $selectedServices),
                'status'          => $data['status'],
                'account_manager' => $data['account_manager'],
                'contract_value'  => $data['contract_value'] !== '' ? $data['contract_value'] : null,
                'billing_cycle'   => $data['billing_cycle'] ?: null,
                'start_date'      => $data['start_date'] ?: null,
                'notes'           => $data['notes'],
            ];

            if ($isEdit) {
                DB::query(
                    "UPDATE clients SET company=?,first_name=?,last_name=?,name=?,phone=?,email=?,location=?,
                     services=?,status=?,account_manager=?,contract_value=?,billing_cycle=?,start_date=?,notes=?
                     WHERE id=?",
                    [...array_values($dbData), $id]
                );
                logActivity($_SESSION['user_id'], 'Edit Client', "Client #$id '{$dbData['company']}' updated");
                if ($dbData['account_manager']) {
                    addNotification($dbData['account_manager'], 'Client Updated',
                        "Client '{$dbData['company']}' has been updated.", 'info',
                        APP_URL.'/admin/client-services/client-detail.php?id='.$id);
                }
                setFlash('success', 'Client updated successfully.');
            } else {
                DB::query(
                    "INSERT INTO clients (company,first_name,last_name,name,phone,email,location,
                     services,status,account_manager,contract_value,billing_cycle,start_date,notes,created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                    [...array_values($dbData), $_SESSION['user_id']]
                );
                $newId = DB::lastInsertId();
                logActivity($_SESSION['user_id'], 'Create Client', "Client #$newId '{$dbData['company']}' created");
                if ($dbData['account_manager']) {
                    addNotification($dbData['account_manager'], 'New Client Assigned',
                        "Client '{$dbData['company']}' has been assigned to you.", 'success',
                        APP_URL.'/admin/client-services/client-detail.php?id='.$newId);
                }
                setFlash('success', 'Client added successfully.');
            }
            redirect(APP_URL . '/manager/client-services/clients.php');
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row justify-content-center">
  <div class="col-12 col-xl-9">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-<?= $isEdit?'pencil':'plus-circle' ?> me-2 text-primary"></i><?= $pageTitle ?></span>
        <a href="<?= APP_URL ?>/manager/client-services/clients.php" class="btn btn-sm btn-outline-secondary">
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
            <div class="col-12"><h6 class="text-muted fw-600 border-bottom pb-1">Company & Contact</h6></div>

            <div class="col-md-6">
              <label class="form-label">Company Name <span class="text-danger">*</span></label>
              <input type="text" name="company" class="form-control" value="<?= e($data['company']??'') ?>" required>
            </div>
            <div class="col-md-3">
              <label class="form-label">Contact First Name <span class="text-danger">*</span></label>
              <input type="text" name="first_name" class="form-control" value="<?= e($data['first_name']??'') ?>" required>
            </div>
            <div class="col-md-3">
              <label class="form-label">Contact Last Name <span class="text-danger">*</span></label>
              <input type="text" name="last_name" class="form-control" value="<?= e($data['last_name']??'') ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Phone <span class="text-danger">*</span></label>
              <input type="tel" name="phone" class="form-control" value="<?= e($data['phone']??'') ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Email</label>
              <input type="email" name="email" class="form-control" value="<?= e($data['email']??'') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Location</label>
              <input type="text" name="location" class="form-control" placeholder="City, State / Country"
                     value="<?= e($data['location']??'') ?>">
            </div>

            <div class="col-12 mt-2"><h6 class="text-muted fw-600 border-bottom pb-1">Services Subscribed</h6></div>
            <div class="col-12">
              <div class="row g-2">
                <?php foreach ($serviceOptions as $svc): ?>
                <div class="col-md-6 col-lg-4">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="services[]" value="<?= e($svc) ?>"
                           id="svc-<?= md5($svc) ?>" <?= in_array($svc, $selectedServices, true)?'checked':'' ?>>
                    <label class="form-check-label small" for="svc-<?= md5($svc) ?>"><?= e($svc) ?></label>
                  </div>
                </div>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="col-12 mt-2"><h6 class="text-muted fw-600 border-bottom pb-1">Account & Billing</h6></div>

            <div class="col-md-4">
              <label class="form-label">Status</label>
              <select name="status" class="form-select">
                <?php foreach ($statusOptions as $s): ?>
                <option value="<?= e($s) ?>" <?= ($data['status']??'Active')===$s?'selected':'' ?>><?= e($s) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Account Manager</label>
              <select name="account_manager" class="form-select">
                <option value="">— Unassigned —</option>
                <?php foreach ($managers as $m): ?>
                <option value="<?= $m['id'] ?>" <?= ($data['account_manager']??'')==$m['id']?'selected':'' ?>>
                  <?= e($m['name']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Client Since</label>
              <input type="date" name="start_date" class="form-control" value="<?= e($data['start_date']??'') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Contract Value (₹)</label>
              <input type="number" step="0.01" min="0" name="contract_value" class="form-control"
                     placeholder="e.g. 25000" value="<?= e($data['contract_value']??'') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Billing Cycle</label>
              <select name="billing_cycle" class="form-select">
                <option value="">— Select —</option>
                <?php foreach ($billingOptions as $b): ?>
                <option value="<?= e($b) ?>" <?= ($data['billing_cycle']??'')===$b?'selected':'' ?>><?= e($b) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12">
              <label class="form-label">Notes</label>
              <textarea name="notes" class="form-control" rows="3"
                        placeholder="Anything worth knowing about this client..."><?= e($data['notes']??'') ?></textarea>
            </div>

            <div class="col-12 d-flex gap-2 justify-content-end">
              <a href="<?= APP_URL ?>/manager/client-services/clients.php" class="btn btn-outline-secondary">Cancel</a>
              <button type="submit" class="btn btn-primary px-4">
                <i class="bi bi-check-lg me-1"></i><?= $isEdit?'Update Client':'Add Client' ?>
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>