<?php
session_start();
include 'config.php';

if(!isset($_SESSION['user_id'])) exit;

$current_user = $_SESSION['user_id'];
$chat_user = intval($_GET['user'] ?? 0);

$messages = [];
if($chat_user){
    $stmt = $conn->prepare("
        SELECT * FROM messages 
        WHERE (sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?) 
        ORDER BY created_at ASC
    ");
    $stmt->bind_param("iiii", $current_user, $chat_user, $chat_user, $current_user);
    $stmt->execute();
    $res = $stmt->get_result();
    while($row = $res->fetch_assoc()){
        $messages[] = $row;
    }
}

header('Content-Type: application/json');
echo json_encode($messages);
exit;
?>