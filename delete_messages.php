<?php
session_start();
include 'config.php';

if(!isset($_SESSION['user_id'])){
    exit("Unauthorized");
}

$user_id = $_SESSION['user_id'];

$id = $_POST['id'] ?? null;
$mode = $_POST['mode'] ?? "me"; // 'all' or 'me'

if(!$id){
    exit("Invalid request");
}

/*
IMPORTANT:
Your current DB does NOT support "delete for me only"
(because there is no hidden flag)

So BOTH options will delete fully for now.
Later I can upgrade your DB to support real "delete for me"
*/

if($mode === "all"){
    // delete for everyone (only sender allowed)
    $stmt = $conn->prepare("DELETE FROM messages WHERE id=? AND sender_id=?");
    $stmt->bind_param("ii", $id, $user_id);
    $stmt->execute();
}else{
    // delete for me (same behavior for now)
    $stmt = $conn->prepare("DELETE FROM messages WHERE id=? AND sender_id=?");
    $stmt->bind_param("ii", $id, $user_id);
    $stmt->execute();
}

echo "success";
?>