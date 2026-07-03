<?php
// auth/change-password.php — Redirects to profile password section
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
redirect(APP_URL . '/auth/profile.php#change-password');
