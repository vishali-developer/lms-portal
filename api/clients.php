<?php
// api/clients.php — AJAX API for client operations (status, assign, delete, notes)
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
header('Content-Type: application/json');

$action = $_REQUEST['action'] ?? '';
$uid    = $_SESSION['user_id'];
$role   = $_SESSION['user_role'];

$validStatuses = ['Active','On Hold','Inactive','Completed'];

/* ── Update status ─────────────────────────────────────────── */
if ($action === 'update-status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';

    if (!$id || !in_array($status, $validStatuses, true)) {
        jsonOut(['success'=>false, 'message'=>'Invalid data.']);
    }
    // Employees can only update their own assigned clients
    if ($role === 'employee') {
        $client = DB::fetchOne("SELECT id FROM clients WHERE id=? AND account_manager=?", [$id, $uid]);
        if (!$client) jsonOut(['success'=>false, 'message'=>'Access denied.']);
    }
    $old = DB::fetchOne("SELECT status, company, account_manager FROM clients WHERE id=?", [$id]);
    if (!$old) jsonOut(['success'=>false, 'message'=>'Client not found.']);

    DB::query("UPDATE clients SET status=? WHERE id=?", [$status, $id]);
    logActivity($uid, 'Client Status Update', "Client #$id '{$old['company']}' → $status");

    if ($old['account_manager'] && $old['account_manager'] != $uid && in_array($role,['admin','manager'])) {
        addNotification($old['account_manager'], 'Client Status Changed',
            "'{$old['company']}' status changed to $status.", 'info',
            APP_URL.'/admin/client-services/client-detail.php?id='.$id);
    }
    jsonOut(['success'=>true, 'message'=>"Status updated to $status"]);
}

/* ── Assign account manager ────────────────────────────────── */
if ($action === 'assign' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!in_array($role, ['admin','manager'])) jsonOut(['success'=>false,'message'=>'Permission denied.']);
    $id  = (int)($_POST['id'] ?? 0);
    $emp = !empty($_POST['employee_id']) ? (int)$_POST['employee_id'] : null;

    if (!$id) jsonOut(['success'=>false,'message'=>'Invalid client ID.']);

    $empRow = null;
    if ($emp) {
        $empRow = DB::fetchOne(
            "SELECT id, name FROM users WHERE id=? AND role='employee' AND status='active'", [$emp]
        );
        if (!$empRow) jsonOut(['success'=>false,'message'=>'Employee not found.']);
    }
    $client = DB::fetchOne("SELECT company FROM clients WHERE id=?", [$id]);
    if (!$client) jsonOut(['success'=>false,'message'=>'Client not found.']);

    DB::query("UPDATE clients SET account_manager=? WHERE id=?", [$emp, $id]);
    logActivity($uid, 'Client Assigned', "Client #$id '{$client['company']}' assigned to #$emp");

    if ($emp && $empRow) {
        addNotification($emp, 'New Client Assigned',
            "Client '{$client['company']}' has been assigned to you.", 'success',
            APP_URL.'/admin/client-services/client-detail.php?id='.$id);
    }
    $msg = $emp ? "Assigned to {$empRow['name']}" : 'Client unassigned';
    jsonOut(['success'=>true,'message'=>$msg]);
}

/* ── Add note / check-in ───────────────────────────────────── */
if ($action === 'add-note' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $clientId = (int)($_POST['client_id'] ?? 0);
    $note     = trim($_POST['note'] ?? '');
    $nextDate = trim($_POST['next_checkin_date'] ?? '') ?: null;

    if (!$clientId || !$note) jsonOut(['success'=>false,'message'=>'Note cannot be empty.']);

    // Employees can only add notes to their own assigned clients
    if ($role === 'employee') {
        $client = DB::fetchOne("SELECT id FROM clients WHERE id=? AND account_manager=?", [$clientId, $uid]);
        if (!$client) jsonOut(['success'=>false, 'message'=>'Access denied.']);
    } else {
        $client = DB::fetchOne("SELECT id FROM clients WHERE id=?", [$clientId]);
        if (!$client) jsonOut(['success'=>false, 'message'=>'Client not found.']);
    }

    DB::query(
        "INSERT INTO client_notes (client_id, employee_id, note, next_checkin_date) VALUES (?,?,?,?)",
        [$clientId, $uid, $note, $nextDate]
    );
    logActivity($uid, 'Client Note Added', "Note added to client #$clientId");
    jsonOut(['success'=>true, 'message'=>'Note saved.']);
}

/* ── Delete client (GET with redirect fallback) ────────────── */
if ($action === 'delete') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonOut(['success'=>false,'message'=>'Invalid ID.']);

    $client = DB::fetchOne("SELECT company, account_manager FROM clients WHERE id=?", [$id]);
    if (!$client) jsonOut(['success'=>false,'message'=>'Client not found.']);

    // Employees can only delete their own assigned clients
    if ($role === 'employee' && (int)$client['account_manager'] !== $uid) {
        jsonOut(['success'=>false,'message'=>'Permission denied.']);
    }
    if (!in_array($role, ['admin','manager','employee'])) {
        jsonOut(['success'=>false,'message'=>'Permission denied.']);
    }

    DB::query("DELETE FROM clients WHERE id=?", [$id]);
    logActivity($uid, 'Delete Client', "Client #$id '{$client['company']}' deleted");

    $listPage = $role === 'employee'
        ? '/employee/client-services/clients.php'
        : '/admin/client-services/clients.php';

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_SERVER['HTTP_REFERER'])) {
        setFlash('success', "Client '{$client['company']}' deleted.");
        header('Location: ' . APP_URL . $listPage);
        exit;
    }
    jsonOut(['success'=>true,'message'=>'Client deleted.']);
}

jsonOut(['success'=>false,'message'=>'Unknown action.'], 400);