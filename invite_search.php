<?php
session_start();
include 'config.php';

header('Content-Type: application/json');

$current_user = (int)($_SESSION['user_id'] ?? 0);
if (!$current_user) { echo json_encode([]); exit(); }

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 1) { echo json_encode([]); exit(); }

$safe = '%' . $conn->real_escape_string($q) . '%';

$res = $conn->query("
    SELECT u.id, u.username, u.profile_pic
    FROM users u
    WHERE u.username LIKE '$safe'
      AND u.id != $current_user
    LIMIT 20
");

if (!$res) {
    echo json_encode(['error' => $conn->error]);
    exit();
}

$users = [];
while ($row = $res->fetch_assoc()) {
    $users[] = [
        'id'          => (int)$row['id'],
        'username'    => $row['username'],
        'profile_pic' => !empty($row['profile_pic']) ? $row['profile_pic'] : 'uploads/me.jpeg',
        'nickname'    => '',
    ];
}

echo json_encode($users);