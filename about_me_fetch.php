<?php
session_start();
include 'config.php';

header('Content-Type: application/json');

$current_user = $_SESSION['user_id'] ?? 0;
$profile_id   = intval($_GET['user_id'] ?? 0);

if (!$profile_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid user']);
    exit;
}

$is_owner     = ($current_user == $profile_id);
$is_following = false;

if (!$is_owner && $current_user) {
    $stmt = $conn->prepare("SELECT 1 FROM followers WHERE follower_id = ? AND following_id = ?");
    $stmt->bind_param("ii", $current_user, $profile_id);
    $stmt->execute();
    $is_following = $stmt->get_result()->num_rows > 0;
    $stmt->close();
}

if (!$is_owner && !$is_following) {
    echo json_encode(['success' => false, 'message' => 'Follow this user to view their About Me']);
    exit;
}

// ── Core about data ────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT full_name, nickname, occupation, education, current_location, hometown,
           relationship_status, date_of_birth, website, interests, description,
           contact_email, contact_phone, political_views, religious_views, favorite_quotes
    FROM user_about
    WHERE user_id = ?
");
$stmt->bind_param("i", $profile_id);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$data) {
    $data = array_fill_keys([
        'full_name', 'nickname', 'occupation', 'education', 'current_location',
        'hometown', 'relationship_status', 'date_of_birth', 'website', 'interests',
        'description', 'contact_email', 'contact_phone', 'political_views',
        'religious_views', 'favorite_quotes'
    ], null);
}

// ── Family members ─────────────────────────────────────────────────────────
$family = [];
$stmt = $conn->prepare("
    SELECT id, member_name, relationship, profile_pic
    FROM user_family_members
    WHERE user_id = ?
    ORDER BY created_at ASC
");
$stmt->bind_param("i", $profile_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $family[] = $row;
$stmt->close();

// ── Life events ────────────────────────────────────────────────────────────
$events = [];
$stmt = $conn->prepare("
    SELECT id, event_type, event_title, event_date, description
    FROM user_life_events
    WHERE user_id = ?
    ORDER BY event_date DESC, created_at DESC
");
$stmt->bind_param("i", $profile_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $events[] = $row;
$stmt->close();

echo json_encode([
    'success'  => true,
    'is_owner' => $is_owner,
    'about'    => $data,
    'family'   => $family,
    'events'   => $events,
]);