<?php
// api/followups.php — AJAX: add / edit / delete follow-up notes
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
header('Content-Type: application/json');

$uid    = $_SESSION['user_id'];
$role   = $_SESSION['user_role'];
$action = $_REQUEST['action'] ?? 'add'; // add | edit | delete

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['success'=>false,'message'=>'POST required.'], 405);
}

// ════════════════════════════════════════════════════════════
// ADD a new follow-up
// ════════════════════════════════════════════════════════════
if ($action === 'add') {

    $leadId   = (int)trim($_POST['lead_id']      ?? '');
    $note     = trim($_POST['note']               ?? '');
    $nextDate = trim($_POST['next_followup_date'] ?? '');

    if (!$leadId || !$note) {
        jsonOut(['success'=>false,'message'=>'Lead ID and note are required.']);
    }
    if (strlen($note) > 2000) {
        jsonOut(['success'=>false,'message'=>'Note is too long (max 2000 characters).']);
    }

    // Access check
    if ($role === 'employee') {
        $lead = DB::fetchOne("SELECT id,name,assigned_to FROM leads WHERE id=? AND assigned_to=?", [$leadId,$uid]);
    } else {
        $lead = DB::fetchOne("SELECT id,name,assigned_to FROM leads WHERE id=?", [$leadId]);
    }
    if (!$lead) jsonOut(['success'=>false,'message'=>'Lead not found or access denied.']);

    // Validate date
    $validDate = null;
    if ($nextDate) {
        $d = DateTime::createFromFormat('Y-m-d', $nextDate);
        if ($d && $d->format('Y-m-d') === $nextDate) {
            $validDate = $nextDate;
        } else {
            jsonOut(['success'=>false,'message'=>'Invalid next follow-up date.']);
        }
    }

    // Snapshot the adder's name so history survives if their account is later deleted
    $me = DB::fetchOne("SELECT name FROM users WHERE id=?", [$uid]);

    DB::query(
        "INSERT INTO followups (lead_id, employee_id, employee_name_snapshot, note, next_followup_date)
         VALUES (?,?,?,?,?)",
        [$leadId, $uid, $me['name'] ?? 'Unknown', $note, $validDate]
    );
    $newId = DB::lastInsertId();

    // Auto-advance status: New or Contacted → Follow-up
    $current = DB::fetchOne("SELECT status FROM leads WHERE id=?", [$leadId]);
    if (in_array($current['status'], ['New','Contacted'])) {
        DB::query("UPDATE leads SET status='Follow-up' WHERE id=?", [$leadId]);
    }

    logActivity($uid, 'Add Follow-up', "Follow-up added for Lead #$leadId");

    notifyAdmins(
        'New Follow-up Added',
        "Follow-up added for lead '{$lead['name']}'.",
        'info',
        APP_URL . '/admin/lead-detail.php?id=' . $leadId,
        $uid
    );

    if ($role === 'employee' && $lead['assigned_to'] !== $uid) {
        addNotification($lead['assigned_to'], 'Follow-up Added',
            "Follow-up added for '{$lead['name']}'.", 'info',
            APP_URL.'/admin/lead-detail.php?id='.$leadId);
    }

    jsonOut([
        'success'  => true,
        'message'  => 'Follow-up saved successfully.',
        'id'       => $newId,
        'date_fmt' => $validDate ? date('d M Y', strtotime($validDate)) : null,
    ]);
}

// ════════════════════════════════════════════════════════════
// EDIT an existing follow-up
// ════════════════════════════════════════════════════════════
if ($action === 'edit') {

    $id       = (int)($_POST['id'] ?? 0);
    $note     = trim($_POST['note'] ?? '');
    $nextDate = trim($_POST['next_followup_date'] ?? '');

    if (!$id || !$note) {
        jsonOut(['success'=>false,'message'=>'Follow-up ID and note are required.']);
    }
    if (strlen($note) > 2000) {
        jsonOut(['success'=>false,'message'=>'Note is too long (max 2000 characters).']);
    }

    $fu = DB::fetchOne(
        "SELECT f.*, l.name AS lead_name FROM followups f
         JOIN leads l ON l.id = f.lead_id WHERE f.id=?", [$id]
    );
    if (!$fu) jsonOut(['success'=>false,'message'=>'Follow-up not found.']);

    // Permission: admin/manager can edit any; employee can only edit their own
    if ($role === 'employee' && (int)$fu['employee_id'] !== $uid) {
        jsonOut(['success'=>false,'message'=>'You can only edit your own follow-ups.']);
    }

    $validDate = null;
    if ($nextDate) {
        $d = DateTime::createFromFormat('Y-m-d', $nextDate);
        if ($d && $d->format('Y-m-d') === $nextDate) {
            $validDate = $nextDate;
        } else {
            jsonOut(['success'=>false,'message'=>'Invalid next follow-up date.']);
        }
    }

    DB::query(
        "UPDATE followups SET note=?, next_followup_date=? WHERE id=?",
        [$note, $validDate, $id]
    );

    logActivity($uid, 'Edit Follow-up', "Follow-up #$id edited for Lead #{$fu['lead_id']}");

    jsonOut([
        'success'  => true,
        'message'  => 'Follow-up updated successfully.',
        'note'     => $note,
        'date_fmt' => $validDate ? date('d M Y', strtotime($validDate)) : null,
        'date_raw' => $validDate,
    ]);
}

// ════════════════════════════════════════════════════════════
// DELETE a follow-up
// ════════════════════════════════════════════════════════════
if ($action === 'delete') {

    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    if (!$id) jsonOut(['success'=>false,'message'=>'Invalid follow-up ID.']);

    $fu = DB::fetchOne(
        "SELECT f.*, l.name AS lead_name FROM followups f
         JOIN leads l ON l.id = f.lead_id WHERE f.id=?", [$id]
    );
    if (!$fu) jsonOut(['success'=>false,'message'=>'Follow-up not found.']);

    // Permission: admin/manager can delete any; employee can only delete their own
    if ($role === 'employee' && (int)$fu['employee_id'] !== $uid) {
        jsonOut(['success'=>false,'message'=>'You can only delete your own follow-ups.']);
    }

    DB::query("DELETE FROM followups WHERE id=?", [$id]);

    logActivity($uid, 'Delete Follow-up',
        "Follow-up #$id deleted (lead: '{$fu['lead_name']}')");

    jsonOut(['success'=>true,'message'=>'Follow-up deleted.']);
}

jsonOut(['success'=>false,'message'=>'Unknown action.'], 400);