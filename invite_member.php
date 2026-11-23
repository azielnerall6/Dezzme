<?php
session_start();
include 'config.php';

header('Content-Type: application/json'); // Important for JS to parse JSON

$current_user = $_SESSION['user_id'] ?? 0;
$group_id = $_POST['group_id'] ?? 0;
$invite_username = $_POST['username'] ?? '';

// Check required fields
if (!$current_user  !$group_id  !$invite_username) {
    echo json_encode(['error'=>'Missing fields']);
    exit;
}

// Find the user to invite
$user_res = $conn->query("SELECT * FROM users WHERE username='". $conn->real_escape_string($invite_username) ."' LIMIT 1");
if ($user_res->num_rows == 0) {
    echo json_encode(['error'=>'User not found']);
    exit;
}
$user = $user_res->fetch_assoc();
$invite_user_id = $user['id'];

// Check if already a member
$check = $conn->query("SELECT * FROM group_members WHERE group_id=$group_id AND user_id=$invite_user_id");
if ($check->num_rows > 0) {
    echo json_encode(['error'=>'User already in group']);
    exit;
}

// Add user to group
$conn->query("INSERT INTO group_members (group_id,user_id) VALUES ($group_id,$invite_user_id)");

// ✅ Return JSON with user info for JS to dynamically add
echo json_encode([
    'success' => 'User added to group',
    'user_id' => $invite_user_id,
    'username' => $user['username'],
    'profile_pic' => $user['profile_pic'] ?: 'uploads/default.jpg'
]);
exit;
?>