<?php
// ============================================================
// header.php — Sidebar + Topbar layout template
// Variables expected: $pageTitle (string), $activePage (string)
// ============================================================
require_once __DIR__ . '/auth.php';
requireLogin();

$user = currentUser();

// A logged-in session whose user record can no longer be found (deleted/deactivated
// account, stale session after a DB change, etc.) must not be allowed to render —
// every other line below assumes $user is a real array, so bounce back to login.
if (!$user) {
    redirect(APP_URL . '/auth/logout.php');
    exit;
}

$notifCount = unreadNotifCount();
$flash      = getFlash();
$role       = $_SESSION['user_role'];
// Folder prefix for role-specific pages
$roleFolder = $role === 'employee' ? 'employee' : ($role === 'manager' ? 'manager' : 'admin');

// Safe fallbacks — second layer of defense in case $user is ever a partial
// array (missing a field) rather than fully null, which the guard above
// doesn't catch since it only checks for a completely empty $user.
$userName  = $user['name']  ?? 'User';
$userEmail = $user['email'] ?? '';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle ?? 'Dashboard') ?> — <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css">
</head>
<body data-role="<?= e($role) ?>">

<div class="lms-wrapper">

<!-- ===== SIDEBAR ===== -->
<nav id="sidebar" class="lms-sidebar">

  <!-- Brand -->
  <div class="sidebar-brand">
    <div class="brand-icon"><i class="bi bi-bullseye"></i></div>
    <span class="brand-name"><?= APP_NAME ?></span>
    <button class="btn-close-sidebar d-xl-none" id="sidebarClose" aria-label="Close sidebar">
      <i class="bi bi-x-lg"></i>
    </button>
  </div>

  <!-- Logged-in user chip -->
  <div class="sidebar-user">
    <div class="user-avatar">
      <?php if (!empty($user['profile_image'])): ?>
        <img src="<?= UPLOAD_URL . e($user['profile_image']) ?>" alt="Avatar">
      <?php else: ?>
        <span><?= strtoupper(substr($userName, 0, 1)) ?></span>
      <?php endif; ?>
    </div>
    <div class="overflow-hidden">
      <div class="user-name text-truncate"><?= e($userName) ?></div>
      <span class="role-badge role-<?= e($role) ?>"><?= ucfirst($role) ?></span>
    </div>
  </div>

  <!-- Navigation -->
  <ul class="sidebar-nav">
    <li class="nav-section">MAIN</li>

    <li class="nav-item">
      <a href="<?= APP_URL ?>/dashboard.php"
         class="nav-link <?= ($activePage??'')==='dashboard' ? 'active' : '' ?>">
        <i class="bi bi-speedometer2"></i><span>Dashboard</span>
      </a>
    </li>

    <li class="nav-item">
      <a href="<?= APP_URL ?>/<?= $roleFolder ?>/leads.php"
         class="nav-link <?= ($activePage??'')==='leads' ? 'active' : '' ?>">
        <i class="bi bi-funnel"></i><span>All Leads</span>
      </a>
    </li>

    <li class="nav-item">
      <a href="<?= APP_URL ?>/<?= $roleFolder ?>/inbound-leads.php"
         class="nav-link <?= ($activePage??'')==='inbound-leads' ? 'active' : '' ?>">
        <i class="bi bi-box-arrow-in-down"></i><span>Inbound Leads</span>
      </a>
    </li>

    <li class="nav-item">
      <a href="<?= APP_URL ?>/<?= $roleFolder ?>/outbound-leads.php"
         class="nav-link <?= ($activePage??'')==='outbound-leads' ? 'active' : '' ?>">
        <i class="bi bi-box-arrow-up-right"></i><span>Outbound Leads</span>
      </a>
    </li>

      <li class="nav-item">
      <a href="<?= APP_URL ?>/<?= $role==='employee' ? 'employee' : 'admin' ?>/client-services/clients.php"
         class="nav-link <?= ($activePage??'')==='clients' ? 'active' : '' ?>">
        <i class="bi bi-briefcase"></i><span>Client Services</span>
      </a>
    </li>


    <?php if (in_array($role,['admin','manager'])): ?>
    <li class="nav-item">
      <a href="<?= APP_URL ?>/<?= $role === 'manager' ? 'manager' : 'admin' ?>/kanban.php"
         class="nav-link <?= ($activePage??'')==='kanban' ? 'active' : '' ?>">
        <i class="bi bi-kanban"></i><span>Kanban Board</span>
      </a>
    </li>
    <?php endif; ?>

    <li class="nav-item">
      <a href="<?= APP_URL ?>/<?= $roleFolder ?>/followups.php"
         class="nav-link <?= ($activePage??'')==='followups' ? 'active' : '' ?>">
        <i class="bi bi-calendar-check"></i><span>Follow-ups</span>
        <?php
          $uid2 = $_SESSION['user_id'];
          $cond2 = $role==='employee' ? " AND employee_id=$uid2" : '';
          $due = DB::fetchOne("SELECT COUNT(*) c FROM followups WHERE next_followup_date <= CURDATE() $cond2")['c'];
          if ($due > 0):
        ?>
        <span class="ms-auto badge bg-danger" style="font-size:.6rem;"><?= $due ?></span>
        <?php endif; ?>
      </a>
    </li>

    <!-- <?php if ($role === 'employee'): ?>
    <li class="nav-item">
      <a href="<?= APP_URL ?>/employee/employee-report.php"
         class="nav-link <?= ($activePage??'')==='employee-report' ? 'active' : '' ?>">
        <i class="bi bi-person-lines-fill"></i>
        <span>My Report</span>
      </a>
    </li>
    <?php endif; ?> -->
     

    <!------>

    <?php if (in_array($role,['employee','manager'])): ?>
    <li class="nav-item">
      <a href="<?= APP_URL ?>/<?= $role === 'manager' ? 'manager' : 'employee' ?>/<?= $role === 'manager' ? 'manager' : 'employee' ?>-report.php"
         class="nav-link <?= ($activePage??'')===`{$role}-report` ? 'active' : '' ?>">
        <i class="bi bi-person-lines-fill"></i><span>My Report</span>
      </a>
    </li>
    <?php endif; ?>

    <!------->
    

    <?php if (in_array($role,['admin','manager'])): ?>
    <li class="nav-section">MANAGEMENT</li>

    <li class="nav-item">
      <a href="<?= APP_URL ?>/admin/users.php"
         class="nav-link <?= ($activePage??'')==='users' ? 'active' : '' ?>">
        <i class="bi bi-people"></i>
        <span><?= $role === 'manager' ? 'Employees' : 'User Management' ?></span>
      </a>
    </li>

     <?php if ($role === 'admin'): ?>
    <li class="nav-item">
      <a href="<?= APP_URL ?>/admin/manager-report.php"
         class="nav-link <?= ($activePage??'')==='manager-report' ? 'active' : '' ?>">
        <i class="bi bi-person-lines-fill"></i><span>Manager Report</span>
      </a>
    </li>
    <?php endif; ?>

    <li class="nav-item">
      <a href="<?= APP_URL ?>/<?= $role === 'manager' ? 'manager' : 'admin' ?>/reports.php"
         class="nav-link <?= ($activePage??'')==='reports' ? 'active' : '' ?>">
        <i class="bi bi-bar-chart-line"></i><span>Reports</span>
      </a>
    </li>

    <li class="nav-item">
      <a href="<?= APP_URL ?>/admin/employee-report.php"
         class="nav-link <?= ($activePage??'')==='employee-report' ? 'active' : '' ?>">
        <i class="bi bi-person-lines-fill"></i><span>Employee Report</span>
      </a>
    </li>

   

    <?php if ($role === 'admin'): ?>
    <li class="nav-item">
      <a href="<?= APP_URL ?>/admin/activity-log.php"
         class="nav-link <?= ($activePage??'')==='activity' ? 'active' : '' ?>">
        <i class="bi bi-activity"></i><span>Activity Log</span>
      </a>
    </li>
    <?php endif; ?>
    <?php endif; ?>

    <li class="nav-section">ACCOUNT</li>

    <li class="nav-item">
      <a href="<?= APP_URL ?>/auth/profile.php"
         class="nav-link <?= ($activePage??'')==='profile' ? 'active' : '' ?>">
        <i class="bi bi-person-circle"></i><span>My Profile</span>
      </a>
    </li>

    <?php if ($role === 'admin'): ?>
    <li class="nav-item">
      <a href="<?= APP_URL ?>/admin/settings.php"
         class="nav-link <?= ($activePage??'')==='settings' ? 'active' : '' ?>">
        <i class="bi bi-gear"></i><span>Settings</span>
      </a>
    </li>
    <?php endif; ?>

    <li class="nav-item mt-2">
      <a href="<?= APP_URL ?>/auth/logout.php" class="nav-link" style="color:#f87171;">
        <i class="bi bi-box-arrow-right"></i><span>Logout</span>
      </a>
    </li>
  </ul>

  <!-- Footer: theme toggle + version -->
  <div class="sidebar-footer">
    <button class="btn-theme-toggle" id="themeToggle" title="Toggle dark/light mode">
      <i class="bi bi-moon-fill" id="themeIcon"></i>
    </button>
    <small class="text-muted" style="color:#fff !important;">v<?= APP_VERSION ?></small>
  </div>

</nav>
<!-- ===== END SIDEBAR ===== -->

<!-- ===== MAIN AREA ===== -->
<div class="lms-content">

  <!-- Topbar -->
  <header class="lms-topbar">
    <button class="btn-hamburger" id="sidebarToggle" aria-label="Toggle sidebar">
      <i class="bi bi-list"></i>
    </button>

    <div class="topbar-title d-none d-sm-block">
      <?= e($pageTitle ?? 'Dashboard') ?>
    </div>

    <!-- Global Search -->
    <div class="topbar-search d-none d-md-flex position-relative ms-3">
      <div class="input-group input-group-sm">
        <span class="input-group-text border-end-0 bg-transparent border-secondary-subtle">
          <i class="bi bi-search text-muted small"></i>
        </span>
        <input type="text" id="globalSearch" class="form-control border-start-0 ps-0"
               placeholder="Search leads…  (Ctrl+K)" autocomplete="off"
               style="min-width:220px; background:transparent;">
      </div>
      <div id="searchResults" class="search-dropdown shadow" style="display:none;"></div>
    </div>

    <div class="ms-auto d-flex align-items-center gap-1">
      <!-- Notifications -->
        <button class="btn-icon js-push-btn">
          <i class="bi bi-bell"></i>
        </button>

      <div class="dropdown notif-wrapper">
        <button class="btn-icon position-relative" data-bs-toggle="dropdown"
                data-bs-auto-close="outside"
                id="notifBtn" aria-label="Notifications">
          <i class="bi bi-bell"></i>
          <?php if ($notifCount > 0): ?>
          <span class="notif-badge" id="notifBadge"><?= min($notifCount, 99) ?></span>
          <?php endif; ?>
        </button>
        <div class="dropdown-menu dropdown-menu-end notif-dropdown p-0">
          <div class="notif-header px-3 py-2 border-bottom d-flex justify-content-between align-items-center">
            <span class="fw-600 small"><i class="bi bi-bell me-2 text-primary"></i>Notifications</span>
            <a href="#" class="small text-primary text-decoration-none mark-all-read">
              Mark all read
            </a>
          </div>
          <div id="notifList" class="notif-list-scroll">
            <div class="notif-loading py-4 text-center text-muted small">
              <i class="bi bi-hourglass-split me-1"></i>Loading…
            </div>
          </div>
          <div class="border-top text-center py-2">
            <a href="<?= APP_URL ?>/auth/profile.php" class="small text-primary text-decoration-none">
              <i class="bi bi-list-ul me-1"></i>View all activity
            </a>
          </div>
        </div>
      </div>

      <!-- User dropdown -->
      <div class="dropdown">
        <button class="btn-user-menu" data-bs-toggle="dropdown" aria-label="User menu">
          <div class="mini-avatar"><?= strtoupper(substr($userName, 0, 1)) ?></div>
          <span class="d-none d-lg-inline"><?= e(explode(' ', $userName)[0]) ?></span>
          <i class="bi bi-chevron-down small opacity-50"></i>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow border-0" style="min-width:200px;">
          <li class="px-3 py-2 border-bottom">
            <div class="small fw-700"><?= e($userName) ?></div>
            <div class="small text-muted"><?= e($userEmail) ?></div>
          </li>
          <li>
            <a class="dropdown-item small py-2" href="<?= APP_URL ?>/auth/profile.php">
              <i class="bi bi-person me-2 text-primary"></i>My Profile
            </a>
          </li>
          <li>
            <a class="dropdown-item small py-2" href="<?= APP_URL ?>/auth/change-password.php">
              <i class="bi bi-key me-2 text-primary"></i>Change Password
            </a>
          </li>
          <?php if ($role === 'admin'): ?>
          <li>
            <a class="dropdown-item small py-2" href="<?= APP_URL ?>/admin/settings.php">
              <i class="bi bi-gear me-2 text-primary"></i>Settings
            </a>
          </li>
          <?php endif; ?>
          <li><hr class="dropdown-divider my-1"></li>
          <li>
            <a class="dropdown-item small py-2 text-danger" href="<?= APP_URL ?>/auth/logout.php">
              <i class="bi bi-box-arrow-right me-2"></i>Logout
            </a>
          </li>
        </ul>
      </div>
    </div>
  </header>
  <!-- End Topbar -->

  <!-- Flash message -->
  <main class="lms-main">
    <?php $flash = getFlash(); if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show d-flex align-items-center"
         role="alert" style="border-radius:10px;">
      <i class="bi bi-<?= $flash['type']==='success'?'check-circle-fill':'exclamation-circle-fill' ?> me-2 flex-shrink-0"></i>
      <?= e($flash['msg']) ?>
      <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>