<?php
session_start();
include 'config.php';

header('Content-Type: application/json');

$current_user = (int)($_SESSION['user_id'] ?? 0);
if(!$current_user){ echo json_encode(['success'=>false]); exit; }

$target_id = (int)($_POST['user_id'] ?? 0);
if(!$target_id){ echo json_encode(['success'=>false]); exit; }

$exists = $conn->query("SELECT id FROM followers WHERE follower_id=$current_user AND following_id=$target_id")->num_rows > 0;

if($exists){
    $conn->query("DELETE FROM followers WHERE follower_id=$current_user AND following_id=$target_id");
    $conn->query("DELETE FROM track_requests WHERE requester_id=$current_user AND target_id=$target_id");
    $conn->query("INSERT INTO unfollow_log (unfollowed_by, user_id) VALUES ($current_user, $target_id)");
    echo json_encode(['success'=>true, 'following'=>false]);
} else {
    $conn->query("INSERT IGNORE INTO followers (follower_id, following_id) VALUES ($current_user, $target_id)");
    $conn->query("DELETE FROM unfollow_log WHERE unfollowed_by=$current_user AND user_id=$target_id");
    echo json_encode(['success'=>true, 'following'=>true]);
}