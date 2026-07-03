<?php
// api/save-subscription.php — Save / remove Web Push subscription for logged-in user
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

header('Content-Type: application/json');

$uid  = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);

if (!is_array($data) || empty($data['endpoint'])) {
    jsonOut(['status'=>'error','message'=>'Invalid subscription data.']);
}

$endpoint   = $data['endpoint'];
$p256dh     = $data['keys']['p256dh']  ?? '';
$authKey    = $data['keys']['auth']    ?? '';
$expiration = isset($data['expirationTime']) && $data['expirationTime']
              ? (int)floor($data['expirationTime'] / 1000)  // ms → seconds
              : null;
$ua         = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

// ── SUBSCRIBE ─────────────────────────────────────────────────
if (isset($_GET['subscribe'])) {
    // Upsert: update if the same user+endpoint already exists
    $existing = DB::fetchOne(
        "SELECT id FROM push_subscribers WHERE user_id=? AND endpoint=?",
        [$uid, $endpoint]
    );
    if ($existing) {
        DB::query(
            "UPDATE push_subscribers
             SET p256dh=?, auth_key=?, expiration_time=?, user_agent=?
             WHERE id=?",
            [$p256dh, $authKey, $expiration, $ua, $existing['id']]
        );
    } else {
        DB::query(
            "INSERT INTO push_subscribers (user_id, endpoint, expiration_time, p256dh, auth_key, user_agent)
             VALUES (?,?,?,?,?,?)",
            [$uid, $endpoint, $expiration, $p256dh, $authKey, $ua]
        );
    }
    logActivity($uid, 'Push Subscribed', 'Browser subscribed to web push notifications');
    jsonOut(['status'=>'ok','message'=>'Subscribed to push notifications.']);
}

// ── UNSUBSCRIBE ───────────────────────────────────────────────
if (isset($_GET['unsubscribe'])) {
    DB::query(
        "DELETE FROM push_subscribers WHERE user_id=? AND endpoint=?",
        [$uid, $endpoint]
    );
    logActivity($uid, 'Push Unsubscribed', 'Browser unsubscribed from web push notifications');
    jsonOut(['status'=>'ok','message'=>'Unsubscribed from push notifications.']);
}

jsonOut(['status'=>'error','message'=>'No action specified.']);