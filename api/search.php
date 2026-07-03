<?php
// api/search.php — Live global lead search (JSON)
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
header('Content-Type: application/json');

$q    = trim($_GET['q'] ?? '');
$uid  = $_SESSION['user_id'];
$role = $_SESSION['user_role'];

if (strlen($q) < 2) { echo '[]'; exit; }

$s      = '%' . $q . '%';
$params = [$s, $s, $s, $s];
$cond   = '';

if ($role === 'employee') {
    $cond     = ' AND l.assigned_to = ?';
    $params[] = $uid;
}

$leads = DB::fetchAll(
    "SELECT l.id, l.name, l.phone, l.email, l.company, l.status, l.priority
     FROM leads l
     WHERE (l.name LIKE ? OR l.phone LIKE ? OR l.email LIKE ? OR l.company LIKE ?)
     $cond
     ORDER BY l.created_at DESC
     LIMIT 10",
    $params
);

$base = ($role === 'employee') ? 'employee' : 'admin';

$out = array_map(fn($l) => [
    'id'       => $l['id'],
    'name'     => $l['name'],
    'phone'    => $l['phone'],
    'email'    => $l['email'],
    'company'  => $l['company'],
    'status'   => $l['status'],
    'priority' => $l['priority'],
    'url'      => APP_URL . "/{$base}/lead-detail.php?id={$l['id']}",
], $leads);

echo json_encode($out);
exit;
