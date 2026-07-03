<?php
// api/chart-data.php — AJAX endpoint for dashboard chart data
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
header('Content-Type: application/json');

$uid    = $_SESSION['user_id'];
$role   = $_SESSION['user_role'];
$period = $_GET['period'] ?? 'month';
$from   = $_GET['from']   ?? '';
$to     = $_GET['to']     ?? '';

$empCond = ($role === 'employee') ? " AND assigned_to = $uid" : '';

// Optional lead_type filter (Inbound Leads / Outbound Leads) used by the Reports tabs
$leadType = $_GET['lead_type'] ?? '';
$typeCond = in_array($leadType, ['Inbound Leads','Outbound Leads'], true) ? ' AND lead_type=?' : '';

function withType(array $params, string $typeCond, string $leadType): array {
    return $typeCond ? array_merge($params, [$leadType]) : $params;
}

// ── Determine date range & grouping ──────────────────────────
switch ($period) {
    case 'today':
        $dateFrom = date('Y-m-d');
        $dateTo   = date('Y-m-d');
        $groupBy  = 'hour';
        break;
    case 'week':
        $dateFrom = date('Y-m-d', strtotime('monday this week'));
        $dateTo   = date('Y-m-d');
        $groupBy  = 'day';
        break;
    case 'month':
        $dateFrom = date('Y-m-01');
        $dateTo   = date('Y-m-d');
        $groupBy  = 'day';
        break;
    case '3months':
        $dateFrom = date('Y-m-d', strtotime('-3 months'));
        $dateTo   = date('Y-m-d');
        $groupBy  = 'week';
        break;
    case '6months':
        $dateFrom = date('Y-m-d', strtotime('-6 months'));
        $dateTo   = date('Y-m-d');
        $groupBy  = 'month';
        break;
    case 'custom':
        $dateFrom = $from ?: date('Y-m-01');
        $dateTo   = $to   ?: date('Y-m-d');
        $days     = max(1, (strtotime($dateTo) - strtotime($dateFrom)) / 86400);
        if ($days <= 1)       $groupBy = 'hour';
        elseif ($days <= 31)  $groupBy = 'day';
        elseif ($days <= 90)  $groupBy = 'week';
        else                  $groupBy = 'month';
        break;
    default:
        $dateFrom = date('Y-m-d', strtotime('-6 months'));
        $dateTo   = date('Y-m-d');
        $groupBy  = 'month';
}

if ($dateFrom > $dateTo) $dateFrom = $dateTo;

// ── Build chart labels & data ─────────────────────────────────
$labels = [];
$counts = [];

if ($groupBy === 'hour') {
    $maxHour = ($dateFrom === date('Y-m-d')) ? (int)date('H') : 23;
    for ($h = 0; $h <= $maxHour; $h++) {
        $labels[] = sprintf('%02d:00', $h);
        $counts[] = (int)DB::fetchOne(
            "SELECT COUNT(*) c FROM leads WHERE DATE(created_at)=? AND HOUR(created_at)=? $empCond$typeCond",
            withType([$dateFrom, $h], $typeCond, $leadType))['c'];
    }
} elseif ($groupBy === 'day') {
    $cur = strtotime($dateFrom);
    $end = strtotime($dateTo);
    while ($cur <= $end) {
        $d        = date('Y-m-d', $cur);
        $labels[] = date('d M', $cur);
        $counts[] = (int)DB::fetchOne(
            "SELECT COUNT(*) c FROM leads WHERE DATE(created_at)=? $empCond$typeCond",
            withType([$d], $typeCond, $leadType))['c'];
        $cur = strtotime('+1 day', $cur);
    }
} elseif ($groupBy === 'week') {
    $cur  = strtotime('monday this week', strtotime($dateFrom));
    $end  = strtotime($dateTo);
    $seen = [];
    while ($cur <= $end) {
        $ws  = date('Y-m-d', $cur);
        $we  = date('Y-m-d', strtotime('+6 days', $cur));
        if (!isset($seen[$ws])) {
            $seen[$ws] = true;
            $labels[]  = 'Wk ' . date('d M', $cur);
            $counts[]  = (int)DB::fetchOne(
                "SELECT COUNT(*) c FROM leads WHERE DATE(created_at) BETWEEN ? AND ? $empCond$typeCond",
                withType([$ws, min($we, $dateTo)], $typeCond, $leadType))['c'];
        }
        $cur = strtotime('+7 days', $cur);
    }
} else {
    $cur = strtotime(date('Y-m-01', strtotime($dateFrom)));
    $end = strtotime(date('Y-m-01', strtotime($dateTo)));
    while ($cur <= $end) {
        $m        = date('Y-m', $cur);
        $labels[] = date('M Y', $cur);
        $counts[] = (int)DB::fetchOne(
            "SELECT COUNT(*) c FROM leads WHERE DATE_FORMAT(created_at,'%Y-%m')=? $empCond$typeCond",
            withType([$m], $typeCond, $leadType))['c'];
        $cur = strtotime('+1 month', $cur);
    }
}

// ── Status distribution (same period) ────────────────────────
$statusRows = DB::fetchAll(
    "SELECT status, COUNT(*) cnt FROM leads
     WHERE DATE(created_at) BETWEEN ? AND ? $empCond$typeCond
     GROUP BY status ORDER BY cnt DESC",
    withType([$dateFrom, $dateTo], $typeCond, $leadType));

// ── Period summary stats ──────────────────────────────────────
$pTotal = (int)DB::fetchOne(
    "SELECT COUNT(*) c FROM leads WHERE DATE(created_at) BETWEEN ? AND ? $empCond$typeCond",
    withType([$dateFrom, $dateTo], $typeCond, $leadType))['c'];
$pNew   = (int)DB::fetchOne(
    "SELECT COUNT(*) c FROM leads WHERE DATE(created_at) BETWEEN ? AND ? AND status='New' $empCond$typeCond",
    withType([$dateFrom, $dateTo], $typeCond, $leadType))['c'];
$pConv  = (int)DB::fetchOne(
    "SELECT COUNT(*) c FROM leads WHERE DATE(created_at) BETWEEN ? AND ? AND status='Converted' $empCond$typeCond",
    withType([$dateFrom, $dateTo], $typeCond, $leadType))['c'];
$pUntouched = (int)DB::fetchOne(
    "SELECT COUNT(*) c FROM leads WHERE DATE(created_at) BETWEEN ? AND ? AND status='Untouched' $empCond$typeCond",
    withType([$dateFrom, $dateTo], $typeCond, $leadType))['c'];

// ── Lifetime totals (not period-bound) — drives the "All-time" KPI cards,
//    refreshed whenever the Total / Inbound / Outbound tab changes ───────
$allTotal     = (int)DB::fetchOne(
    "SELECT COUNT(*) c FROM leads WHERE 1=1 $empCond$typeCond",
    withType([], $typeCond, $leadType))['c'];
$allConverted = (int)DB::fetchOne(
    "SELECT COUNT(*) c FROM leads WHERE status='Converted' $empCond$typeCond",
    withType([], $typeCond, $leadType))['c'];
$allRejected  = (int)DB::fetchOne(
    "SELECT COUNT(*) c FROM leads WHERE status='Rejected' $empCond$typeCond",
    withType([], $typeCond, $leadType))['c'];
$allUntouched = (int)DB::fetchOne(
    "SELECT COUNT(*) c FROM leads WHERE status='Untouched' $empCond$typeCond",
    withType([], $typeCond, $leadType))['c'];
$allRate      = $allTotal > 0 ? round(($allConverted / $allTotal) * 100, 1) : 0;

jsonOut([
    'labels'           => $labels,
    'counts'           => $counts,
    'statusData'       => $statusRows,
    'period_total'     => $pTotal,
    'period_new'       => $pNew,
    'period_conv'      => $pConv,
    'period_untouched' => $pUntouched,
    'conv_rate'        => $pTotal > 0 ? round(($pConv / $pTotal) * 100, 1) : 0,
    'date_from'        => $dateFrom,
    'date_to'          => $dateTo,
    'group_by'         => $groupBy,
    'all_total'        => $allTotal,
    'all_converted'    => $allConverted,
    'all_rejected'     => $allRejected,
    'all_untouched'    => $allUntouched,
    'all_rate'         => $allRate,
]);