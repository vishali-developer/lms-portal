<?php
// admin/settings.php — System Settings
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin']);

$pageTitle  = 'Settings';
$activePage = 'settings';

// Save settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['settings'])) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) { setFlash('danger','CSRF error'); redirect(APP_URL.'/admin/settings.php'); }
    foreach ($_POST['settings'] as $key => $val) {
        DB::query("INSERT INTO settings (setting_key,setting_val) VALUES (?,?)
                   ON DUPLICATE KEY UPDATE setting_val=?",
                  [$key, $val, $val]);
    }
    logActivity($_SESSION['user_id'],'Settings Updated','System settings saved');
    setFlash('success','Settings saved successfully.');
    redirect(APP_URL.'/admin/settings.php');
}

// Load settings
$settingsRows = DB::fetchAll("SELECT setting_key, setting_val FROM settings");
$settings = [];
foreach ($settingsRows as $row) { $settings[$row['setting_key']] = $row['setting_val']; }

// Lead Sources management
if (isset($_GET['add_source'])) {
    if (!verifyCsrf($_GET['csrf'] ?? '')) { setFlash('danger','CSRF error'); redirect(APP_URL.'/admin/settings.php'); }
    $srcName = trim(urldecode($_GET['add_source']));
    if ($srcName) {
        DB::query("INSERT IGNORE INTO lead_sources (name) VALUES (?)", [$srcName]);
        setFlash('success','Source added.');
    }
    redirect(APP_URL.'/admin/settings.php#sources');
}
if (isset($_GET['del_source'])) {
    if (!verifyCsrf($_GET['csrf'] ?? '')) { setFlash('danger','CSRF error'); redirect(APP_URL.'/admin/settings.php'); }
    DB::query("DELETE FROM lead_sources WHERE id=?", [(int)$_GET['del_source']]);
    setFlash('success','Source removed.');
    redirect(APP_URL.'/admin/settings.php#sources');
}

$sources = DB::fetchAll("SELECT * FROM lead_sources ORDER BY name");
$csrf = csrfToken();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row g-3">
  <div class="col-12 col-xl-8">
    <!-- Company Settings -->
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-building me-2 text-primary"></i>Company Settings</div>
      <div class="card-body">
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Company Name</label>
              <input type="text" name="settings[company_name]" class="form-control"
                     value="<?= e($settings['company_name']??'') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Company Email</label>
              <input type="email" name="settings[company_email]" class="form-control"
                     value="<?= e($settings['company_email']??'') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Phone</label>
              <input type="text" name="settings[company_phone]" class="form-control"
                     value="<?= e($settings['company_phone']??'') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Address</label>
              <input type="text" name="settings[company_address]" class="form-control"
                     value="<?= e($settings['company_address']??'') ?>">
            </div>
            <div class="col-12 text-end">
              <button type="submit" class="btn btn-primary">
                <i class="bi bi-save me-1"></i>Save Settings
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>

    <!-- SMTP Settings -->
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-envelope me-2 text-primary"></i>SMTP / Email Settings</div>
      <div class="card-body">
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">SMTP Host</label>
              <input type="text" name="settings[smtp_host]" class="form-control"
                     placeholder="smtp.gmail.com" value="<?= e($settings['smtp_host']??'') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Port</label>
              <input type="number" name="settings[smtp_port]" class="form-control"
                     placeholder="587" value="<?= e($settings['smtp_port']??587) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">From Name</label>
              <input type="text" name="settings[smtp_from]" class="form-control"
                     value="<?= e($settings['smtp_from']??'') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">SMTP Username</label>
              <input type="text" name="settings[smtp_user]" class="form-control"
                     value="<?= e($settings['smtp_user']??'') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">SMTP Password</label>
              <input type="password" name="settings[smtp_pass]" class="form-control"
                     placeholder="Leave blank to keep current">
            </div>
            <div class="col-12 text-end">
              <button type="submit" class="btn btn-primary">
                <i class="bi bi-save me-1"></i>Save SMTP
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-xl-4">
    <!-- Lead Sources -->
    <div class="card" id="sources">
      <div class="card-header"><i class="bi bi-broadcast me-2 text-primary"></i>Lead Sources</div>
      <div class="card-body">
        <form method="GET" class="input-group mb-3">
          <input type="text" name="add_source" class="form-control form-control-sm"
                 placeholder="New source name" required>
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <button type="submit" class="btn btn-primary btn-sm">Add</button>
        </form>
        <ul class="list-group list-group-flush">
          <?php foreach ($sources as $src): ?>
          <li class="list-group-item d-flex justify-content-between align-items-center py-2">
            <span class="small"><?= e($src['name']) ?></span>
            <a href="?del_source=<?= $src['id'] ?>&csrf=<?= $csrf ?>"
               class="btn btn-xs btn-outline-danger"
               onclick="return confirm('Remove this source?')">
              <i class="bi bi-x"></i>
            </a>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div>
</div>

<style>.btn-xs{padding:.2rem .45rem;font-size:.75rem;border-radius:6px}</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
