<?php
session_start();
include 'config.php';

header('Content-Type: application/json');

// ── Auth check ──
$current_user = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if (!$current_user) {
    echo json_encode(['status' => 'error', 'message' => 'Not logged in']);
    exit;
}

// ── Remove music action ──
if (isset($_POST['music_action']) && $_POST['music_action'] === 'remove') {
    $conn->query("UPDATE user_settings SET profile_music=NULL, profile_music_name=NULL WHERE user_id=$current_user");
    echo json_encode(['status' => 'ok', 'removed' => true]);
    exit;
}

// ── Upload action ──
if (!isset($_FILES['profile_music_file'])) {
    echo json_encode(['status' => 'error', 'message' => 'No file received']);
    exit;
}

$file = $_FILES['profile_music_file'];

// PHP upload error codes
if ($file['error'] !== UPLOAD_ERR_OK) {
    $codes = [
        1 => 'File exceeds server upload limit',
        2 => 'File exceeds form upload limit',
        3 => 'File only partially uploaded',
        4 => 'No file was uploaded',
        6 => 'Missing temporary folder',
        7 => 'Failed to write file to disk',
        8 => 'Upload stopped by extension',
    ];
    echo json_encode(['status' => 'error', 'message' => $codes[$file['error']] ?? 'Upload error #' . $file['error']]);
    exit;
}

// Extension check
$ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowed = ['mp3', 'aac', 'ogg', 'wav', 'm4a', 'flac'];
if (!in_array($ext, $allowed)) {
    echo json_encode(['status' => 'error', 'message' => 'File type not allowed. Use mp3, aac, ogg, wav, m4a or flac.']);
    exit;
}

// Size check (15 MB)
if ($file['size'] > 15 * 1024 * 1024) {
    echo json_encode(['status' => 'error', 'message' => 'File too large. Max 15 MB.']);
    exit;
}

// MIME sniff (basic)
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);
$allowed_mimes = ['audio/mpeg','audio/mp3','audio/ogg','audio/wav','audio/x-wav',
                  'audio/aac','audio/x-m4a','audio/mp4','audio/flac','audio/x-flac',
                  'application/octet-stream']; // some browsers report this for audio
if (!in_array($mime, $allowed_mimes)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid file content (MIME: ' . $mime . ')']);
    exit;
}

// Folder check
$upload_dir = __DIR__ . '/uploads/';
if (!is_dir($upload_dir)) {
    echo json_encode(['status' => 'error', 'message' => 'Uploads folder does not exist']);
    exit;
}
if (!is_writable($upload_dir)) {
    echo json_encode(['status' => 'error', 'message' => 'Uploads folder is not writable']);
    exit;
}

// Delete old music file for this user (cleanup)
$old = $conn->query("SELECT profile_music FROM user_settings WHERE user_id=$current_user")->fetch_assoc();
if ($old && !empty($old['profile_music'])) {
    $old_path = $upload_dir . basename($old['profile_music']);
    if (file_exists($old_path) && strpos(basename($old_path), 'music_') === 0) {
        @unlink($old_path);
    }
}

// Save new file
$fname  = 'music_' . $current_user . '_' . time() . '.' . $ext;
$target = $upload_dir . $fname;

if (!move_uploaded_file($file['tmp_name'], $target)) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to save file. Check folder permissions.']);
    exit;
}

$orig_name = $conn->real_escape_string(pathinfo($file['name'], PATHINFO_FILENAME));
$fname_esc = $conn->real_escape_string($fname);

// Upsert into user_settings
$conn->query("
    INSERT INTO user_settings (user_id, profile_music, profile_music_name)
    VALUES ($current_user, '$fname_esc', '$orig_name')
    ON DUPLICATE KEY UPDATE
        profile_music      = '$fname_esc',
        profile_music_name = '$orig_name'
");

if ($conn->errno) {
    echo json_encode(['status' => 'error', 'message' => 'DB error: ' . $conn->error]);
    exit;
}

echo json_encode([
    'status' => 'ok',
    'file'   => $fname,
    'name'   => $orig_name,
]);
exit;
?>