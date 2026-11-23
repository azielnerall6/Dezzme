<?php
session_start();
include 'config.php';
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'] ?? 0;

if(!$user_id){
    echo json_encode(['success'=>false,'msg'=>'Not logged in']);
    exit;
}

$meme_id = intval($_POST['meme_id'] ?? 0);
$action = $_POST['action'] ?? '';

if(!$meme_id || !in_array($action, ['favorite','repost','like'])){
    echo json_encode(['success'=>false,'msg'=>'Invalid request']);
    exit;
}

/* =========================
   FAVORITE
========================= */
if($action === 'favorite'){

    $stmt = $conn->prepare("SELECT id FROM favorites WHERE user_id=? AND meme_id=?");
    $stmt->bind_param("ii",$user_id,$meme_id);
    $stmt->execute();
    $res = $stmt->get_result();

    if($res->num_rows > 0){
        $stmt = $conn->prepare("DELETE FROM favorites WHERE user_id=? AND meme_id=?");
        $stmt->bind_param("ii",$user_id,$meme_id);
        $stmt->execute();

        echo json_encode(['success'=>true,'status'=>'unfavorited']);
    } else {
        $stmt = $conn->prepare("INSERT INTO favorites(user_id,meme_id) VALUES(?,?)");
        $stmt->bind_param("ii",$user_id,$meme_id);
        $stmt->execute();

        echo json_encode(['success'=>true,'status'=>'favorited']);
    }
}

/* =========================
   REPOST
========================= */
elseif($action === 'repost'){

    $stmt = $conn->prepare("SELECT id FROM reposts WHERE user_id=? AND meme_id=?");
    $stmt->bind_param("ii",$user_id,$meme_id);
    $stmt->execute();
    $res = $stmt->get_result();

    if($res->num_rows > 0){
        $stmt = $conn->prepare("DELETE FROM reposts WHERE user_id=? AND meme_id=?");
        $stmt->bind_param("ii",$user_id,$meme_id);
        $stmt->execute();

        echo json_encode(['success'=>true,'status'=>'unreposted']);
    } else {
        $stmt = $conn->prepare("INSERT INTO reposts(user_id,meme_id) VALUES(?,?)");
        $stmt->bind_param("ii",$user_id,$meme_id);
        $stmt->execute();

        echo json_encode(['success'=>true,'status'=>'reposted']);
    }
}

/* =========================
   LIKE (THIS WAS MISSING)
========================= */
elseif($action === 'like'){

    $stmt = $conn->prepare("SELECT id FROM likes WHERE user_id=? AND meme_id=?");
    $stmt->bind_param("ii",$user_id,$meme_id);
    $stmt->execute();
    $res = $stmt->get_result();

    if($res->num_rows > 0){
        // UNLIKE
        $stmt = $conn->prepare("DELETE FROM likes WHERE user_id=? AND meme_id=?");
        $stmt->bind_param("ii",$user_id,$meme_id);
        $stmt->execute();

        echo json_encode(['success'=>true,'status'=>'unliked']);
    } else {
        // LIKE
        $stmt = $conn->prepare("INSERT INTO likes(user_id,meme_id) VALUES(?,?)");
        $stmt->bind_param("ii",$user_id,$meme_id);
        $stmt->execute();

        echo json_encode(['success'=>true,'status'=>'liked']);
    }
}
?>