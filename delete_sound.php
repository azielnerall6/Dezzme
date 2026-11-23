<?php
session_start();
include 'config.php';

header('Content-Type: application/json');

$current_user = $_SESSION['user_id'] ?? 0;

if(!$current_user){
    echo json_encode(["status"=>"error","message"=>"Not logged in"]);
    exit();
}

if(isset($_POST['sound_id'])){
    $id = intval($_POST['sound_id']);

    // Check ownership
    $stmt = $conn->prepare("SELECT file_name FROM sounds WHERE id=? AND user_id=?");
    $stmt->bind_param("ii", $id, $current_user);
    $stmt->execute();
    $res = $stmt->get_result();

    if($res->num_rows > 0){
        $sound = $res->fetch_assoc();
        $file = "uploads/sounds/".$sound['file_name'];

        // Delete file
        if(file_exists($file)){
            unlink($file);
        }

        // Delete from DB
        $stmt = $conn->prepare("DELETE FROM sounds WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();

        echo json_encode(["status"=>"success"]);
    } else {
        echo json_encode(["status"=>"error","message"=>"Not owner or not found"]);
    }
} else {
    echo json_encode(["status"=>"error","message"=>"No ID"]);
}
?>