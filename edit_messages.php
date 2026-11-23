<?php
session_start();
include 'config.php';

if(!isset($_SESSION['user_id'])){
    exit("Unauthorized");
}

$user_id = $_SESSION['user_id'];

$id = $_POST['id'] ?? null;
$message = trim($_POST['message'] ?? "");

// Basic validation
if(!$id || $message === ""){
    exit("Invalid request");
}

// Only allow editing your own message
$stmt = $conn->prepare("UPDATE messages SET message=? WHERE id=? AND sender_id=?");
$stmt->bind_param("sii", $message, $id, $user_id);
$stmt->execute();

echo "success";
?>