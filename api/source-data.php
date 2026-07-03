<?php
// api/source-data.php — Lead source breakdown for a period
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
header('Content-Type: application/json');

$uid    = $_SESSION['user_id'];
$role   = $_SESSION['user_role'];
$period = $_GET['period'] ?? 'month';
$from   = $_GET['from']   ?? '';
$to     = $_GET['to']     ?? '';

$empCond = ($role === 'employee') ? " AND l.assigned_to = $uid" : '';

// Optional lead_type filter (Inbound Leads / Outbound Leads) used by the Reports tabs
$leadType = $_GET['lead_type'] ?? '';
$typeCond = in_array($leadType, ['Inbound Leads','Outbound Leads'], true) ? ' AND l.lead_type=?' : '';

// Resolve dates (same logic as chart-data.php)
switch ($period) {
    case 'today':    $dateFrom = date('Y-m-d'); $dateTo = date('Y-m-d'); break;
    case 'week':     $dateFrom = date('Y-m-d', strtotime('monday this week')); $dateTo = date('Y-m-d'); break;
    case 'month':    $dateFrom = date('Y-m-01'); $dateTo = date('Y-m-d'); break;
    case '3months':  $dateFrom = date('Y-m-d', strtotime('-3 months')); $dateTo = date('Y-m-d'); break;
    case '6months':  $dateFrom = date('Y-m-d', strtotime('-6 months')); $dateTo = date('Y-m-d'); break;
    case 'custom':   $dateFrom = $from ?: date('Y-m-01'); $dateTo = $to ?: date('Y-m-d'); break;
    default:         $dateFrom = date('Y-m-01'); $dateTo = date('Y-m-d');
}
if ($dateFrom > $dateTo) $dateFrom = $dateTo;

$rows = DB::fetchAll(
    "SELECT COALESCE(s.name,'Unknown') AS sname, COUNT(*) cnt
     FROM leads l
     LEFT JOIN lead_sources s ON s.id = l.source_id
     WHERE DATE(l.created_at) BETWEEN ? AND ? $empCond$typeCond
     GROUP BY l.source_id
     ORDER BY cnt DESC
     LIMIT 10",
    $typeCond ? [$dateFrom, $dateTo, $leadType] : [$dateFrom, $dateTo]
);

// Also fetch employee performance for this period.
// The lead_type filter lives in the JOIN's ON clause (not WHERE) so employees
// with zero matching leads for that type still appear with a 0 row via the LEFT JOIN.
$empPerf = DB::fetchAll(
    "SELECT u.name,
            COUNT(l.id)               AS total,
            SUM(l.status='Converted') AS converted,
            SUM(l.status='Rejected')  AS rejected,
            SUM(l.status='Untouched') AS untouched
     FROM users u
     LEFT JOIN leads l ON l.assigned_to = u.id
       AND DATE(l.created_at) BETWEEN ? AND ?$typeCond
     WHERE u.role = 'employee'
     GROUP BY u.id
     ORDER BY total DESC",
    $typeCond ? [$dateFrom, $dateTo, $leadType] : [$dateFrom, $dateTo]
);

jsonOut([
    'rows'      => $rows,
    'empPerf'   => $empPerf,
    'date_from' => $dateFrom,
    'date_to'   => $dateTo,
]);