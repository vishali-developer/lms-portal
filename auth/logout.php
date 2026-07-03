<?php
// auth/logout.php
require_once __DIR__ . '/../includes/auth.php';
startSession();
logoutUser();
header('Location: ' . APP_URL . '/login.php');
exit;
