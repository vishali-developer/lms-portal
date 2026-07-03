<?php
// api/push-send.php
// ─────────────────────────────────────────────────────────────
// Core web-push helper used by addNotification() in auth.php.
//
// Logic
//   1. If target user has ≥1 active browser subscription → send now.
//   2. If user has NO active subscription (offline / not subscribed yet)
//      → queue the push in `pending_push` table.
//   3. On login (flushed from login.php or dashboard.php) → call
//      flushPendingPush($userId) to deliver any queued messages.
//
// Requirements (install once):
//   cd E:\xampp\htdocs\lms-1
//   composer require minishlink/web-push
// ─────────────────────────────────────────────────────────────

require_once __DIR__ . '/../includes/db.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

// ════════════════════════════════════════════════════════════
// sendWebPush()
// Send to a specific user (userId > 0) or everyone (userId = 0).
// If the user has no active subscription, the push is queued.
// ════════════════════════════════════════════════════════════
function sendWebPush(
    int    $userId,
    string $title,
    string $body,
    string $icon = '',
    string $url  = ''
): array {

    // ── Load VAPID keys from settings table ───────────────────
    $vapidRows = DB::fetchAll(
        "SELECT setting_key, setting_val FROM settings
         WHERE setting_key IN ('vapid_public_key','vapid_private_key','vapid_subject')"
    );
    $vapid = [];
    foreach ($vapidRows as $r) $vapid[$r['setting_key']] = $r['setting_val'];

    if (empty($vapid['vapid_public_key']) || empty($vapid['vapid_private_key'])) {
        error_log('[WebPush] VAPID keys not configured.');
        return ['sent' => 0, 'failed' => 0, 'queued' => 0, 'errors' => ['VAPID keys not configured']];
    }

    $defaultIcon = defined('APP_URL') ? APP_URL . '/assets/images/icon-192.png' : '';
    $clickUrl    = $url ?: (defined('APP_URL') ? APP_URL . '/dashboard.php' : '/');
    $icon        = $icon ?: $defaultIcon;
    $now         = time();

    // ── Fetch active subscriptions for this user ──────────────
    if ($userId > 0) {
        $subs = DB::fetchAll(
            "SELECT ps.* FROM push_subscribers ps
             WHERE ps.user_id = ?
               AND (ps.expiration_time IS NULL
                    OR ps.expiration_time = 0
                    OR ps.expiration_time > ?)
             ORDER BY ps.created_at DESC",
            [$userId, $now]
        );
    } else {
        // Broadcast
        $subs = DB::fetchAll(
            "SELECT ps.* FROM push_subscribers ps
             WHERE ps.expiration_time IS NULL
                OR ps.expiration_time = 0
                OR ps.expiration_time > ?",
            [$now]
        );
    }

    // ── No subscription found → queue for later delivery ─────
    if (empty($subs)) {
        if ($userId > 0) {
            // Only queue for specific users (not broadcast)
            DB::query(
                "INSERT INTO pending_push (user_id, title, body, url)
                 VALUES (?, ?, ?, ?)",
                [$userId, $title, $body, $clickUrl]
            );
            return ['sent' => 0, 'failed' => 0, 'queued' => 1, 'errors' => []];
        }
        return ['sent' => 0, 'failed' => 0, 'queued' => 0, 'errors' => []];
    }

    // ── Check PhpWebPush composer package ────────────────────
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload)) {
        // Queue instead of failing silently
        if ($userId > 0) {
            DB::query(
                "INSERT INTO pending_push (user_id, title, body, url) VALUES (?,?,?,?)",
                [$userId, $title, $body, $clickUrl]
            );
        }
        error_log('[WebPush] vendor/autoload.php missing. Run: composer require minishlink/web-push');
        return ['sent' => 0, 'failed' => 0, 'queued' => 1, 'errors' => ['PhpWebPush not installed']];
    }
    require_once $autoload;

    // ── Build WebPush instance ────────────────────────────────
    $auth = [
        'VAPID' => [
            'subject'    => $vapid['vapid_subject'] ?? 'mailto:admin@lms.local',
            'publicKey'  => $vapid['vapid_public_key'],
            'privateKey' => $vapid['vapid_private_key'],
        ],
    ];
    $webPush = new WebPush($auth);

    $payload = json_encode([
        'title'     => $title,
        'body'      => $body,
        'icon'      => $icon,
        'badge'     => defined('APP_URL') ? APP_URL . '/assets/images/badge-72.png' : $icon,
        'extraData' => $clickUrl,
    ]);

    // Queue notifications for all this user's subscriptions
    foreach ($subs as $sub) {
        $subscription = Subscription::create([
            'endpoint' => $sub['endpoint'],
            'keys'     => [
                'p256dh' => $sub['p256dh'],
                'auth'   => $sub['auth_key'],
            ],
        ]);
        $webPush->queueNotification($subscription, $payload);
    }

    // ── Flush & collect results ───────────────────────────────
    $sent = 0; $failed = 0; $errors = [];

    foreach ($webPush->flush() as $report) {
        if ($report->isSuccess()) {
            $sent++;
        } else {
            $failed++;
            $reason = $report->getReason();
            $errors[] = $reason;

            // Remove expired / gone subscriptions (HTTP 410)
            if (str_contains($reason, '410') || str_contains($reason, 'expired')) {
                $ep = $report->getRequest()->getUri()->__toString();
                DB::query("DELETE FROM push_subscribers WHERE endpoint = ?", [$ep]);
            }
        }
    }

    // If every delivery failed, queue so the user gets it next login
    if ($sent === 0 && $userId > 0) {
        DB::query(
            "INSERT INTO pending_push (user_id, title, body, url) VALUES (?,?,?,?)",
            [$userId, $title, $body, $clickUrl]
        );
        $queued = 1;
    } else {
        $queued = 0;
    }

    return ['sent' => $sent, 'failed' => $failed, 'queued' => $queued, 'errors' => $errors];
}

// ════════════════════════════════════════════════════════════
// flushPendingPush()
// Call this right after a user logs in.
// Delivers any queued pushes accumulated while they were offline.
// ════════════════════════════════════════════════════════════
function flushPendingPush(int $userId): void
{
    if ($userId <= 0) return;

    // Check table exists first to avoid errors on fresh installs
    try {
        $pending = DB::fetchAll(
            "SELECT * FROM pending_push WHERE user_id = ? ORDER BY created_at ASC",
            [$userId]
        );
    } catch (Throwable $e) {
        // Table doesn't exist yet — silently skip
        return;
    }

    if (empty($pending)) return;

    // Delete them first so we don't retry if push itself fails
    DB::query("DELETE FROM pending_push WHERE user_id = ?", [$userId]);

    // Deduplicate: if the same title+body was queued multiple times, send once
    $seen = [];
    foreach ($pending as $p) {
        $key = md5($p['title'] . '|' . $p['body'] . '|' . $p['url']);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;

        sendWebPush($userId, $p['title'], $p['body'], '', $p['url']);
    }
}

// ════════════════════════════════════════════════════════════
// Admin broadcast endpoint (POST from Settings page)
// ════════════════════════════════════════════════════════════
if (php_sapi_name() !== 'cli' && !empty($_POST['broadcast'])) {
    require_once __DIR__ . '/../includes/auth.php';
    requireRole(['admin']);
    header('Content-Type: application/json');

    $title = trim($_POST['title'] ?? 'LeadPro LMS');
    $body  = trim($_POST['body']  ?? '');
    $url   = trim($_POST['url']   ?? '');

    if (!$body) {
        jsonOut(['success' => false, 'message' => 'Message body is required.']);
    }

    $result = sendWebPush(0, $title, $body, '', $url);
    logActivity($_SESSION['user_id'], 'Push Broadcast',
        "Broadcast push: \"$title\" — {$result['sent']} sent, {$result['failed']} failed");

    jsonOut([
        'success' => true,
        'message' => "{$result['sent']} push notification(s) sent.",
        'result'  => $result,
    ]);
}