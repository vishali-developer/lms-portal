<?php
// api/notifications.php — AJAX: list, mark-read, count
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
header('Content-Type: application/json');

$uid    = $_SESSION['user_id'];
$action = $_REQUEST['action'] ?? 'list';

switch ($action) {
    case 'list':
        $notifs = DB::fetchAll(
            "SELECT id,title,message,type,is_read,link,created_at
             FROM notifications WHERE user_id=?
             ORDER BY created_at DESC LIMIT 25",
            [$uid]
        );
        echo json_encode($notifs);
        break;

    case 'mark-read':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonOut(['error'=>'POST required'],405);
        $nid = (int)($_POST['id'] ?? 0);
        if ($nid) {
            DB::query("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?", [$nid,$uid]);
        }
        jsonOut(['success'=>true]);
        break;

    case 'mark-all-read':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonOut(['error'=>'POST required'],405);
        DB::query("UPDATE notifications SET is_read=1 WHERE user_id=?", [$uid]);
        jsonOut(['success'=>true]);
        break;

    case 'count':
        $row = DB::fetchOne(
            "SELECT COUNT(*) c FROM notifications WHERE user_id=? AND is_read=0", [$uid]
        );
        jsonOut(['count' => (int)$row['c']]);
        break;

    default:
        jsonOut(['error'=>'Unknown action'], 400);
}
exit;
