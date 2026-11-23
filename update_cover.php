<?php
session_start();
require 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'msg' => 'Not logged in']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

if (!isset($_FILES['cover_photo']) || $_FILES['cover_photo']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'msg' => 'No file received']);
    exit;
}

$file    = $_FILES['cover_photo'];
$ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowed = ['jpg', 'jpeg', 'png', 'webp'];

if (!in_array($ext, $allowed)) {
    echo json_encode(['success' => false, 'msg' => 'File type not allowed']);
    exit;
}

$uploadDir = 'uploads/covers/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

// ✅ KEY FIX: Delete old cover file to prevent stale files piling up
$old = $conn->query("SELECT cover_photo FROM users WHERE id = $user_id")->fetch_assoc();
if (!empty($old['cover_photo']) && file_exists($old['cover_photo'])) {
    unlink($old['cover_photo']);
}

// Always use same predictable filename per user — fixes refresh issue
$filename = 'cover_' . $user_id . '.' . $ext;
$path     = $uploadDir . $filename;

if (move_uploaded_file($file['tmp_name'], $path)) {
    // ✅ KEY FIX: Use prepared statement, save clean path
    $stmt = $conn->prepare("UPDATE users SET cover_photo = ? WHERE id = ?");
    $stmt->bind_param("si", $path, $user_id);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true, 'path' => $path]);
} else {
    echo json_encode(['success' => false, 'msg' => 'File move failed']);
}