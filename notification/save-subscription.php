<?php
session_start();
require "includes/database.php";
header("Content-Type: application/json");

$data = json_decode(file_get_contents('php://input'), true);

// Check login
if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'status' => 'error',
        'message' => 'User not logged in'
    ]);
    exit;
}

$userId = (int)$_SESSION['user_id'];

if (is_array($data) && isset($data['endpoint'])) {

    $endpoint        = $data['endpoint'];
    $expirationTime  = !empty($data['expirationTime']) ? (int)floor($data['expirationTime'] / 1000) : 0;
    $p256dh          = $data['keys']['p256dh'] ?? '';
    $authKey         = $data['keys']['auth'] ?? '';

    $stmt = $con->prepare("SELECT id FROM push_subscribers WHERE endpoint = ?");
    $stmt->bind_param('s', $endpoint);
    $stmt->execute();
    $selectId = $stmt->get_result();
    $stmt->close();

    if (isset($_GET['subscribe'])) {

        if ($selectId->num_rows == 0) {

            // Insert New Subscriber
            $stmt = $con->prepare("INSERT INTO push_subscribers
                (user_id, endpoint, expirationTime, p256dh, authKey)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param('isiss', $userId, $endpoint, $expirationTime, $p256dh, $authKey);
            $query = $stmt->execute();
            $error = $stmt->error;
            $stmt->close();

        } else {

            // Update Existing Subscriber
            // NOTE: $userId is guaranteed to be a real logged-in user id here
            // because we already exit()'d above if no session user_id was set.
            $stmt = $con->prepare("UPDATE push_subscribers
                SET
                    user_id = ?,
                    expirationTime = ?,
                    p256dh = ?,
                    authKey = ?
                WHERE endpoint = ?
            ");
            $stmt->bind_param('iisss', $userId, $expirationTime, $p256dh, $authKey, $endpoint);
            $query = $stmt->execute();
            $error = $stmt->error;
            $stmt->close();
        }

        if ($query) {
            echo json_encode([
                'status' => 'ok',
                'message' => 'Subscribed',
                'user_id' => $userId
            ]);
        } else {
            echo json_encode([
                'status' => 'error',
                'message' => $error
            ]);
        }

    } elseif (isset($_GET['unsubscribe'])) {

        $stmt = $con->prepare("DELETE FROM push_subscribers WHERE endpoint = ?");
        $stmt->bind_param('s', $endpoint);
        $stmt->execute();
        $stmt->close();

        echo json_encode([
            'status' => 'ok',
            'message' => 'Unsubscribed'
        ]);
    }

} else {

    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid Data'
    ]);
}