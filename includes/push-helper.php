<?php

function sendPushNotification($userId, $title, $body, $url = '')
{
    $payload = [
        'user_id' => $userId,
        'title'   => $title,
        'body'    => $body,
        'url'     => $url
    ];

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => APP_URL . "/notification/push.php",
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 2,
    ]);

    curl_exec($ch);

    curl_close($ch);
}   