<?php
// ============================================================
// config.php — Database & App Configuration
// Edit this file with your credentials before going live.
// ============================================================

define('DB_HOST',    'localhost');
define('DB_USER',    'root');         // ← change for production
define('DB_PASS',    '');             // ← change for production
define('DB_NAME',    'lms_db');
define('DB_PORT',    3306);
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME',    'LeadPro LMS');
define('APP_URL',     'http://localhost/lms');  // ← no trailing slash
define('APP_VERSION', '1.0.0');

define('UPLOAD_PATH', __DIR__ . '/../uploads/');
define('UPLOAD_URL',  APP_URL . '/uploads/');

// Session lifetime in seconds (1 hour)
define('SESSION_LIFETIME', 3600);

// Timezone
date_default_timezone_set('Asia/Kolkata');


error_reporting(E_ALL);
ini_set('display_errors', 1);
