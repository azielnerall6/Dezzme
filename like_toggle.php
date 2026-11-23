<?php
session_start();
include 'db.php'; // your DB connection

$current_user = $_SESSION['user_id'] ?? 0; // logged-in user

if(!$current_user || !isset($_POST['meme_id'])) {
    echo json_encode(['status'=>'error']);
    exit;
}

$meme_id = intval($_POST['meme_id']);

// Check if user already liked
$stmt = $conn->prepare("SELECT id FROM likes WHERE user_id=? AND meme_id=?");
$stmt->bind_param("ii", $current_user, $meme_id);
$stmt->execute();
$result = $stmt->get_result();

if($result->num_rows > 0){
    // Already liked → unlike
    $stmt = $conn->prepare("DELETE FROM likes WHERE user_id=? AND meme_id=?");
    $stmt->bind_param("ii", $current_user, $meme_id);
    $stmt->execute();
    $liked = false;
} else {
    // Not liked → like
    $stmt = $conn->prepare("INSERT INTO likes (user_id,meme_id) VALUES (?,?)");
    $stmt->bind_param("ii", $current_user, $meme_id);
    $stmt->execute();
    $liked = true;
}

// Return updated count
$stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM likes WHERE meme_id=?");
$stmt->bind_param("i", $meme_id);
$stmt->execute();
$count = $stmt->get_result()->fetch_assoc()['cnt'];

echo json_encode([
    'liked' => $liked,
    'count' => $count
]);