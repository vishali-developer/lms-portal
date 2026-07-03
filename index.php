<?php
// index.php — Root entry point: redirect to dashboard or login
require_once __DIR__ . '/includes/auth.php';
startSession();
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . APP_URL . '/dashboard.php');
} else {
    header('Location: ' . APP_URL . '/login.php');
}
exit;
