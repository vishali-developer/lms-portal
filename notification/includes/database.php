<?php

$con = mysqli_connect('localhost', 'root', '', 'notification');
$con->set_charset('utf8mb4');

if(mysqli_connect_errno()){
    echo "MySql Connection Error<br>";
    die;
}