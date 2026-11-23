<?php
session_start();
include 'db.php'; // your database connection

$user_id = $_SESSION['user_id'] ?? 0;
$meme_id = intval($_POST['meme_id'] ?? 0);

$response = ['success' => false, 'liked' => false, 'total_likes' => 0];

if($user_id && $meme_id) {
    // Check if user already liked
    $stmt = $conn->prepare("SELECT id FROM likes WHERE user_id=? AND meme_id=?");
    $stmt->bind_param("ii",$user_id,$meme_id);
    $stmt->execute();
    $res = $stmt->get_result();

    if($res->num_rows > 0){
        // Already liked → remove
        $stmt = $conn->prepare("DELETE FROM likes WHERE user_id=? AND meme_id=?");
        $stmt->bind_param("ii",$user_id,$meme_id);
        $stmt->execute();
        $response['liked'] = false;
    } else {
        // Not liked → insert
        $stmt = $conn->prepare("INSERT INTO likes(user_id,meme_id) VALUES(?,?)");
        $stmt->bind_param("ii",$user_id,$meme_id);
        $stmt->execute();
        $response['liked'] = true;
    }

    // Get updated like count
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM likes WHERE meme_id=?");
    $stmt->bind_param("i",$meme_id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $response['total_likes'] = $res['cnt'] ?? 0;
    $response['success'] = true;
}

header('Content-Type: application/json');
echo json_encode($response);