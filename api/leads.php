<?php
// api/leads.php — AJAX API for lead operations (status, assign, delete)
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
header('Content-Type: application/json');

$action = $_REQUEST['action'] ?? '';
$uid    = $_SESSION['user_id'];
$role   = $_SESSION['user_role'];

$validStatuses = ['Untouched','New','Contacted','Follow-up','Interested','Converted','Closed','Rejected'];

/* ── Update status ─────────────────────────────────────────── */
if ($action === 'update-status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';

    if (!$id || !in_array($status, $validStatuses, true)) {
        jsonOut(['success'=>false, 'message'=>'Invalid data.']);
    }
    // Employees can only update their own assigned leads
    if ($role === 'employee') {
        $lead = DB::fetchOne("SELECT id FROM leads WHERE id=? AND assigned_to=?", [$id, $uid]);
        if (!$lead) jsonOut(['success'=>false, 'message'=>'Access denied.']);
    }
    $old = DB::fetchOne("SELECT status, name, assigned_to FROM leads WHERE id=?", [$id]);
    DB::query("UPDATE leads SET status=? WHERE id=?", [$status, $id]);
    logActivity($uid, 'Status Update', "Lead #$id '{$old['name']}' → $status");

    // Notify assigned employee if admin changed status
    if ($old['assigned_to'] && $old['assigned_to'] != $uid && in_array($role,['admin','manager'])) {
        addNotification($old['assigned_to'], 'Lead Status Changed',
            "'{$old['name']}' status changed to $status.", 'info',
            APP_URL.'/admin/lead-detail.php?id='.$id);
    }
    jsonOut(['success'=>true, 'message'=>"Status updated to $status"]);
}

if ($action === 'update-status' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        
} 

/* ── Assign employee ───────────────────────────────────────── */
if ($action === 'assign' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!in_array($role, ['admin','manager'])) jsonOut(['success'=>false,'message'=>'Permission denied.']);
    $id  = (int)($_POST['id'] ?? 0);
    $emp = !empty($_POST['employee_id']) ? (int)$_POST['employee_id'] : null;

    if (!$id) jsonOut(['success'=>false,'message'=>'Invalid lead ID.']);

    $empRow = null;
    if ($emp) {
        $empRow = DB::fetchOne(
            "SELECT id, name FROM users WHERE id=? AND role='employee' AND status='active'", [$emp]
        );
        if (!$empRow) jsonOut(['success'=>false,'message'=>'Employee not found.']);
    }
    $lead = DB::fetchOne("SELECT name, lead_type FROM leads WHERE id=?", [$id]);
    DB::query("UPDATE leads SET assigned_to=? WHERE id=?", [$emp, $id]);
    logActivity($uid, 'Lead Assigned', "Lead #$id '{$lead['name']}' assigned to #$emp");

    if ($emp && $empRow) {
        // Route the notification to whichever employee list the lead actually belongs to
        $empLeadsPage = ($lead['lead_type'] === 'Outbound Leads') ? 'outbound-leads.php' : 'inbound-leads.php';
        addNotification($emp, 'New Lead Assigned',
            "Lead '{$lead['name']}' has been assigned to you.", 'success',
            APP_URL.'/employee/'.$empLeadsPage);
    }
    $msg = $emp ? "Assigned to {$empRow['name']}" : 'Lead unassigned';
    jsonOut(['success'=>true,'message'=>$msg]);
}

/* ── Delete lead (GET with redirect fallback) ──────────────── */
if ($action === 'delete') {
    if (!in_array($role, ['admin','manager'])) jsonOut(['success'=>false,'message'=>'Permission denied.']);
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonOut(['success'=>false,'message'=>'Invalid ID.']);

    $lead = DB::fetchOne("SELECT name FROM leads WHERE id=?", [$id]);
    if (!$lead) jsonOut(['success'=>false,'message'=>'Lead not found.']);

    DB::query("DELETE FROM leads WHERE id=?", [$id]);
    logActivity($uid, 'Delete Lead', "Lead #$id '{$lead['name']}' deleted");

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_SERVER['HTTP_REFERER'])) {
        setFlash('success', "Lead '{$lead['name']}' deleted.");
        header('Location: ' . APP_URL . '/admin/leads.php');
        exit;
    }
    jsonOut(['success'=>true,'message'=>'Lead deleted.']);
}

jsonOut(['success'=>false,'message'=>'Unknown action.'], 400);