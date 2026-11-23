<?php
session_start();
include 'config.php';
header('Content-Type: application/json');

$current_user = $_SESSION['user_id'] ?? 0;
if (!$current_user) {
    echo json_encode(['success' => false, 'error' => 'not_logged_in']);
    exit();
}

$group_id = (int)($_POST['group_id'] ?? 0);
if (!$group_id) {
    echo json_encode(['success' => false, 'error' => 'invalid_group']);
    exit();
}

$group = $conn->query("SELECT created_by FROM groups WHERE id=$group_id")->fetch_assoc();
if (!$group) {
    echo json_encode(['success' => false, 'error' => 'not_found']);
    exit();
}

// Blocked by owner?
$owner_blocked = $conn->query("
    SELECT 1 FROM blocks WHERE blocker_id=" . (int)$group['created_by'] . " AND blocked_id=$current_user
")->num_rows > 0;

// Group-scoped ban?
$group_banned = false;
$gb = $conn->query("SHOW TABLES LIKE 'group_bans'");
if ($gb && $gb->num_rows > 0) {
    $group_banned = $conn->query("SELECT 1 FROM group_bans WHERE group_id=$group_id AND banned_id=$current_user")->num_rows > 0;
}

if ($owner_blocked || $group_banned) {
    echo json_encode(['success' => false, 'error' => 'blocked']);
    exit();
}

$is_member = $conn->query("SELECT 1 FROM group_members WHERE group_id=$group_id AND user_id=$current_user")->num_rows > 0;
if (!$is_member) {
    $conn->query("INSERT INTO group_members (group_id, user_id) VALUES ($group_id, $current_user)");
}

$member_count = (int)$conn->query("SELECT COUNT(*) AS c FROM group_members WHERE group_id=$group_id")->fetch_assoc()['c'];

echo json_encode([
    'success'      => true,
    'member_count' => $member_count,
]);