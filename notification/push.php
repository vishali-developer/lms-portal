<?php
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;
// use Minishlink\WebPush\VAPID;

$userId  = $_POST['user_id'] ?? '';
$title   = $_POST['title'] ?? '';
$body    = $_POST['message'] ?? '';
$type    = $_POST['type'] ?? '';
$link    = $_POST['link'] ?? '';

require "includes/database.php";
require 'web-push/vendor/autoload.php';

// var_dump(VAPID::createVapidKeys());
// die;

$publicKey = "";
$privateKey = "";

// $message = json_encode([
//     'title' => 'LeadPro - LMS Message!',
//     'body' => 555,
//     'icon' => 'https://localhost/notification/images/icon.png',
//     'badge' => 'https://localhost/notification/images/badge.png',
//     'extraData' => 'https://thintake.in?ref=push-message'
// ]);

$message = json_encode([
    'title' => $title,
    'body'  => $body,
    'icon'  => 'http://localhost/lms/notification/images/icon.png',
    'badge' => 'http://localhost/lms/notification/images/badge.png',
    'extraData' => $link
]);


$time = time();
$query = $con->query("SELECT * FROM `push_subscribers` WHERE `expirationTime` = 0 OR `expirationTime` > '{$time}'");
if($query->num_rows > 0){
    $auth = [
        'VAPID' => [
            'subject' => 'http://localhost/lms', // can be a mailto: or your website address
            'publicKey' => $publicKey, // (recommended) uncompressed public key P-256 encoded in Base64-URL
            'privateKey' => $privateKey, // (recommended) in fact the secret multiplier of the private key encoded in Base64-URL
        ],
    ];
    $webPush = new WebPush($auth);

    while ($subscriber = $query->fetch_assoc()) {
        $subscription = Subscription::create([
                "endpoint" => $subscriber['endpoint'],
                "keys" => [
                    'p256dh' => $subscriber['p256dh'],
                    'auth' => $subscriber['authKey']
                ]
            ]);
        $webPush->queueNotification($subscription, $message);
    }

    foreach ($webPush->flush() as $report) {
        $endpoint = $report->getRequest()->getUri()->__toString();
    
        if ($report->isSuccess()) {
            echo "Message sent successfully for {$endpoint}.<br>";
        } else {
            echo "Message failed to sent for {$endpoint}: {$report->getReason()}.<br>";
        }
    }
}
else{
    echo "No Subscribers";
}

?>
