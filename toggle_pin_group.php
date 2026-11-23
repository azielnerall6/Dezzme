<?php
session_start();
include 'config.php';

header('Content-Type: application/json');

$user_id = $_SESSION['user_id'] ?? 0;
if (!$user_id) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$group_id = intval($_POST['group_id'] ?? 0);
if (!$group_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid group']);
    exit();
}

// Make sure user is actually a member of this group
$check = $conn->prepare("SELECT id FROM group_members WHERE user_id = ? AND group_id = ?");
$check->bind_param("ii", $user_id, $group_id);
$check->execute();
if ($check->get_result()->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Not a member']);
    exit();
}

if (isset($_POST['pin'])) {
    $stmt = $conn->prepare("INSERT IGNORE INTO group_pins (user_id, group_id) VALUES (?, ?)");
    $stmt->bind_param("ii", $user_id, $group_id);
    $stmt->execute();
    echo json_encode(['success' => true, 'action' => 'pinned']);
} elseif (isset($_POST['unpin'])) {
    $stmt = $conn->prepare("DELETE FROM group_pins WHERE user_id = ? AND group_id = ?");
    $stmt->bind_param("ii", $user_id, $group_id);
    $stmt->execute();
    echo json_encode(['success' => true, 'action' => 'unpinned']);
} else {
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
}