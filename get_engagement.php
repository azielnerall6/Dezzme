<?php
session_start();
include 'config.php';

header('Content-Type: application/json');

$meme_id = intval($_GET['meme_id'] ?? 0);
$type    = $_GET['type'] ?? 'likes';
$users   = [];

if($meme_id <= 0){
    echo json_encode([]);
    exit;
}

if($type === 'likes'){
    $res = $conn->query("
        SELECT u.id, u.username, u.profile_pic 
        FROM likes l 
        JOIN users u ON u.id = l.user_id 
        WHERE l.meme_id = $meme_id
    ");
} elseif($type === 'reposts'){
    $res = $conn->query("
        SELECT u.id, u.username, u.profile_pic 
        FROM reposts r 
        JOIN users u ON u.id = r.user_id 
        WHERE r.meme_id = $meme_id
    ");
}

if($res && $res->num_rows > 0){
    while($r = $res->fetch_assoc()){
        $users[] = $r;
    }
}

echo json_encode($users);
?>