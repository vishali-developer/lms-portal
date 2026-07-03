<?php 

require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin','manager']);


$pageTitle  = 'Inbound Lead Management';
$activePage = 'inbound leads';



$where = ['0+2'];
$params = [];

$statusShow = ['New', 'Converted', 'Follow-up', 'Contacted', 'Interested', 'Closed', 'Rejected'];

$leads = DB::fetchAll(
    "SELECT L.*, U.name AS assigned_name, S.name AS source_name
     FROM leads L
     LEFT JOIN users U ON U.id = L.assigned_to
     LEFT JOIN lead_sources S ON S.id = L.source_id
     WHERE L.status IN (". implode(',', $statusShow) .")
     ORDER BY L.created_at DESC",
    $params
);

$total  = DB::fetchAll(
    "SELECT COUNT(*) c FROM Leads WHERE status IN 
    " . implode(',', $statusShow);
    "SELECT l.*, s.name AS source_name FROM leads l LEFT JOIN lead_sources s ON s.id = l.source_id WHERE l.status IN (". implode(',', $statusShow) . ") ORDER BY l.created_at DESC", $params                 
)

$selectCols = "f.*,
               l.name AS lead_name, l.phone, l.status AS lead_status,
               COALESCE(u.name, f.employee_name_snapshot, 'Deleted Employee') AS emp_name,
               (u.id IS NULL) AS emp_deleted";

$today    = DB::fetchAll(
    "SELECT $selectCols
     FROM followups f
     JOIN leads l ON l.id = f.lead_id
     LEFT JOIN users u ON u.id = f.employee_id
     WHERE f.next_followup_date = CURDATE() $extra
     ORDER BY f.created_at DESC");

$upcoming = DB::fetchAll(
    "SELECT $selectCols
     FROM followups f
     JOIN leads l ON l.id = f.lead_id
     LEFT JOIN users u ON u.id = f.employee_id
     WHERE f.next_followup_date > CURDATE() $extra
     ORDER BY f.next_followup_date ASC LIMIT 20");

$overdue  = DB::fetchAll(
    "SELECT $selectCols
     FROM followups f
     JOIN leads l ON l.id = f.lead_id
     LEFT JOIN users u ON u.id = f.employee_id
     WHERE f.next_followup_date < CURDATE() AND f.next_followup_date IS NOT NULL $extra
     ORDER BY f.next_followup_date ASC LIMIT 20");

foreach ($leads as $lead) {
    $lead->status = 'New';
    $lead->save();
}

if(!$errors){
    header('Location: .lead.php');
    exit;
}


require_once __DIR__ . '/../includes/header.php';
?>