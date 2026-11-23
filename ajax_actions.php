<?php
session_start();
include 'config.php';

$current_user = $_SESSION['user_id'] ?? 0;
if(!$current_user) exit(json_encode(['error'=>'Not logged in']));

$meme_id = intval($_POST['meme_id']);
$response = [];

// LIKE toggle
if(isset($_POST['ajax_like'])){
    $exists = $conn->query("SELECT id FROM likes WHERE user_id=$current_user AND meme_id=$meme_id")->num_rows;
    if($exists){
        $conn->query("DELETE FROM likes WHERE user_id=$current_user AND meme_id=$meme_id");
        $response['liked'] = false;
    } else {
        $conn->query("INSERT INTO likes (user_id,meme_id) VALUES ($current_user,$meme_id)");
        $response['liked'] = true;
    }
    $response['count'] = $conn->query("SELECT COUNT(*) as cnt FROM likes WHERE meme_id=$meme_id")->fetch_assoc()['cnt'];
}

// REPOST toggle
if(isset($_POST['ajax_repost'])){
    $exists = $conn->query("SELECT id FROM reposts WHERE user_id=$current_user AND meme_id=$meme_id")->num_rows;
    if($exists){
        $conn->query("DELETE FROM reposts WHERE user_id=$current_user AND meme_id=$meme_id");
        $response['reposted'] = false;
    } else {
        $conn->query("INSERT INTO reposts (user_id,meme_id) VALUES ($current_user,$meme_id)");
        $response['reposted'] = true;
    }
    $response['count'] = $conn->query("SELECT COUNT(*) as cnt FROM reposts WHERE meme_id=$meme_id")->fetch_assoc()['cnt'];
}

// FAVORITE toggle
if(isset($_POST['ajax_favorite'])){
    $exists = $conn->query("SELECT id FROM favorites WHERE user_id=$current_user AND meme_id=$meme_id")->num_rows;
    if($exists){
        $conn->query("DELETE FROM favorites WHERE user_id=$current_user AND meme_id=$meme_id");
        $response['favorited'] = false;
    } else {
        $conn->query("INSERT INTO favorites (user_id,meme_id) VALUES ($current_user,$meme_id)");
        $response['favorited'] = true;
    }
    $response['count'] = $conn->query("SELECT COUNT(*) as cnt FROM favorites WHERE meme_id=$meme_id")->fetch_assoc()['cnt'];
}

echo json_encode($response);