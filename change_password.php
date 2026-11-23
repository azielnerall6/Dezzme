<?php
session_start();
include 'config.php';
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'] ?? 0;
if (!$user_id) {
    exit(json_encode(['success' => false, 'msg' => 'Not logged in']));
}

$current_password = $_POST['current_password'] ?? '';
$new_password     = $_POST['new_password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';

// ── Basic presence checks ─────────────────────────────────
if ($current_password === '' || $new_password === '' || $confirm_password === '') {
    exit(json_encode(['success' => false, 'msg' => 'All fields are required']));
}

// ── New password match check ──────────────────────────────
if ($new_password !== $confirm_password) {
    exit(json_encode(['success' => false, 'msg' => 'New passwords do not match']));
}

// ── Minimum strength check ────────────────────────────────
if (strlen($new_password) < 8) {
    exit(json_encode(['success' => false, 'msg' => 'New password must be at least 8 characters']));
}

// ── Fetch current hash from DB ────────────────────────────
$stmt = $conn->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    exit(json_encode(['success' => false, 'msg' => 'User not found']));
}

// ── Verify current password ───────────────────────────────
if (!password_verify($current_password, $row['password'])) {
    exit(json_encode(['success' => false, 'msg' => 'Current password is incorrect']));
}

// ── Prevent re-using the same password ────────────────────
if (password_verify($new_password, $row['password'])) {
    exit(json_encode(['success' => false, 'msg' => 'New password must be different from current password']));
}

// ── Hash and update ────────────────────────────────────────
$new_hash = password_hash($new_password, PASSWORD_DEFAULT);

$stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
$stmt->bind_param("si", $new_hash, $user_id);
$res = $stmt->execute();
$stmt->close();

if (!$res) {
    exit(json_encode(['success' => false, 'msg' => 'Failed to update password']));
}

// ── Rotate session ID so any fixated session tokens are invalidated ──
session_regenerate_id(true);

echo json_encode(['success' => true, 'msg' => 'Password updated successfully']);