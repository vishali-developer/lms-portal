<?php
// api/export.php — CSV / Excel Export
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$type = $_GET['type'] ?? 'leads';
$uid  = $_SESSION['user_id'];
$role = $_SESSION['user_role'];

if ($type === 'leads') {
    // Build the same filter as leads.php
    $where  = ['1=1'];
    $params = [];

    if (!empty($_GET['status']))   { $where[] = 'l.status=?';            $params[] = $_GET['status']; }
    if (!empty($_GET['employee'])) { $where[] = 'l.assigned_to=?';       $params[] = (int)$_GET['employee']; }
    if (!empty($_GET['source']))   { $where[] = 'l.source_id=?';         $params[] = (int)$_GET['source']; }
    if (!empty($_GET['priority'])) { $where[] = 'l.priority=?';          $params[] = $_GET['priority']; }
    if (!empty($_GET['from']))     { $where[] = 'DATE(l.created_at)>=?'; $params[] = $_GET['from']; }
    if (!empty($_GET['to']))       { $where[] = 'DATE(l.created_at)<=?'; $params[] = $_GET['to']; }
    if (!empty($_GET['q'])) {
        $s = '%'.$_GET['q'].'%';
        $where[] = '(l.name LIKE ? OR l.phone LIKE ? OR l.email LIKE ?)';
        $params  = array_merge($params, [$s,$s,$s]);
    }

    // Inbound / Outbound list pages pass this to scope the export to their own lead type
    $typeSlug = '';
    if (!empty($_GET['lead_type']) && in_array($_GET['lead_type'], ['Inbound Leads','Outbound Leads'], true)) {
        $where[]   = 'l.lead_type=?';
        $params[]  = $_GET['lead_type'];
        $typeSlug  = $_GET['lead_type'] === 'Outbound Leads' ? 'outbound_' : 'inbound_';
    }

    // Employees only see own leads
    if ($role === 'employee') {
        $where[] = 'l.assigned_to=?';
        $params[] = $uid;
    }

    $sql   = "SELECT l.id, l.first_name, l.last_name, l.name, l.phone, l.email, l.company,
                     l.location, l.service, l.lead_type, l.message,
                     s.name AS source, l.status, l.priority,
                     u.name AS assigned_to, l.created_at
              FROM leads l
              LEFT JOIN lead_sources s ON s.id=l.source_id
              LEFT JOIN users u ON u.id=l.assigned_to
              WHERE " . implode(' AND ', $where) . "
              ORDER BY l.created_at DESC";
    $rows  = DB::fetchAll($sql, $params);

    $headers = ['ID','First Name','Last Name','Full Name','Phone','Email','Company','Location',
                'Service','Lead Type','Message','Source','Status','Priority','Assigned To','Created At'];
    $filename = $typeSlug . 'leads_export_' . date('Y-m-d') . '.csv';

} elseif ($type === 'report') {
    if (!in_array($role, ['admin','manager'])) {
        die('Access denied.');
    }
    $from = $_GET['from'] ?? date('Y-m-01');
    $to   = $_GET['to']   ?? date('Y-m-d');

    $rows = DB::fetchAll(
        "SELECT u.name AS employee,
                COUNT(l.id) AS total_leads,
                SUM(l.status='Converted') AS converted,
                SUM(l.status='Rejected') AS rejected,
                SUM(l.status='New') AS new_leads,
                SUM(l.status='Untouched') AS untouched,
                SUM(l.priority='High') AS high_priority
         FROM users u
         LEFT JOIN leads l ON l.assigned_to=u.id AND DATE(l.created_at) BETWEEN ? AND ?
         WHERE u.role='employee'
         GROUP BY u.id ORDER BY total_leads DESC",
        [$from, $to]);

    $headers  = ['Employee','Total Leads','Converted','Rejected','New','Untouched','High Priority'];
    $filename = 'report_' . $from . '_to_' . $to . '.csv';

} else {
    die('Invalid export type.');
}

// Output CSV
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

$out = fopen('php://output', 'w');
// BOM for Excel UTF-8 compatibility
fputs($out, "\xEF\xBB\xBF");
fputcsv($out, $headers);

foreach ($rows as $row) {
    fputcsv($out, array_values($row));
}

fclose($out);
logActivity($uid, 'Export CSV', "Exported $type as CSV");
exit;