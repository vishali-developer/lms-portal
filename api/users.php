<?php
// api/users.php — User management API (delete)
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin','manager']);

header('Content-Type: application/json');

$action   = $_REQUEST['action'] ?? '';
$selfId   = $_SESSION['user_id'];
$selfRole = $_SESSION['user_role'];

/* ── Delete user ───────────────────────────────────────────── */
if ($action === 'delete') {
    $id = (int)($_GET['id'] ?? 0);

    if (!$id) jsonOut(['success'=>false,'message'=>'Invalid user ID.']);
    if ($id === $selfId) jsonOut(['success'=>false,'message'=>'You cannot delete your own account.']);

    $target = DB::fetchOne("SELECT id, name, role FROM users WHERE id=?", [$id]);
    if (!$target) jsonOut(['success'=>false,'message'=>'User not found.']);

    // Permission check
    // Manager can only delete employees
    if ($selfRole === 'manager' && $target['role'] !== 'employee') {
        jsonOut(['success'=>false,'message'=>'Managers can only delete employee accounts.']);
    }
    // Prevent deleting the last admin (safety net)
    if ($target['role'] === 'admin') {
        $adminCount = (int)DB::fetchOne("SELECT COUNT(*) c FROM users WHERE role='admin'")['c'];
        if ($adminCount <= 1) {
            jsonOut(['success'=>false,'message'=>'Cannot delete the last admin account.']);
        }
    }

    // Delete user — FK ON DELETE SET NULL means their leads/clients become unassigned automatically
    DB::query("DELETE FROM users WHERE id=?", [$id]);
    logActivity($selfId, 'Delete User', "User #{$id} '{$target['name']}' ({$target['role']}) deleted by {$selfRole}");

    // Redirect for GET-triggered deletes (from the confirmation link)
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        setFlash('success', "User '{$target['name']}' deleted. Their leads and clients are now unassigned.");
        header('Location: ' . APP_URL . '/admin/users.php');
        exit;
    }

    jsonOut(['success'=>true,'message'=>'User deleted.']);
}

jsonOut(['success'=>false,'message'=>'Unknown action.'], 400);