<?php
session_start();
include 'config.php';
header('Content-Type: application/json');

$current_user = $_SESSION['user_id'] ?? 0;
$group_id = (int)($_GET['group_id'] ?? 0);

if (!$group_id) {
    echo json_encode(['error' => 'Invalid group']);
    exit();
}

// ── Group + creator info ──────────────────────────────────
$stmt = $conn->prepare("
    SELECT g.*, u.username AS creator_name
    FROM groups g
    JOIN users u ON g.created_by = u.id
    WHERE g.id = ?
");
$stmt->bind_param("i", $group_id);
$stmt->execute();
$group = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$group) {
    echo json_encode(['error' => 'Group not found']);
    exit();
}

// ── Member count ──────────────────────────────────────────
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM group_members WHERE group_id = ?");
$stmt->bind_param("i", $group_id);
$stmt->execute();
$member_count = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

// ── Total posts ───────────────────────────────────────────
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM group_posts WHERE group_id = ?");
$stmt->bind_param("i", $group_id);
$stmt->execute();
$total_posts = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

// ── Posts today ───────────────────────────────────────────
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM group_posts WHERE group_id = ? AND DATE(created_at) = CURDATE()");
$stmt->bind_param("i", $group_id);
$stmt->execute();
$posts_today = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

// ── New members in the last 7 days ────────────────────────
$stmt = $conn->prepare("
    SELECT COUNT(*) AS c FROM group_members
    WHERE group_id = ? AND joined_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
");
$stmt->bind_param("i", $group_id);
$stmt->execute();
$new_week = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

// ── Visitors this week ────────────────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS group_views (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        group_id INT UNSIGNED NOT NULL,
        viewer_id INT UNSIGNED NOT NULL,
        viewed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_group_viewer (group_id, viewer_id)
    ) ENGINE=InnoDB
");
$stmt = $conn->prepare("
    SELECT COUNT(DISTINCT viewer_id) AS c FROM group_views
    WHERE group_id = ? AND viewed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
");
$stmt->bind_param("i", $group_id);
$stmt->execute();
$visitors_week = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

// ── Is current user already a member? ─────────────────────
$is_member = false;
if ($current_user) {
    $stmt = $conn->prepare("SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $group_id, $current_user);
    $stmt->execute();
    $is_member = $stmt->get_result()->num_rows > 0;
    $stmt->close();
}

// ── Image paths ────────────────────────────────────────────
$pic = $group['group_pic'] ?? '';
$img_src = empty($pic) ? 'uploads/default_group.png' : (strpos($pic, 'uploads/') === 0 ? $pic : 'uploads/' . $pic);

$banner = $group['banner_pic'] ?? '';
$banner_src = !empty($banner) ? (strpos($banner, 'uploads/') === 0 ? $banner : 'uploads/' . $banner) : $img_src;

echo json_encode([
    'id'            => (int)$group['id'],
    'title'         => $group['title'],
    'description'   => $group['description'] ?? '',
    'img'           => $img_src,
    'banner'        => $banner_src,
    'member_count'  => $member_count,
    'total_posts'   => $total_posts,
    'posts_today'   => $posts_today,
    'new_week'      => $new_week,
    'visitors_week' => $visitors_week,
    'created_at'    => $group['created_at'],
    'is_adult'      => !empty($group['is_adult']),
    'is_member'     => $is_member,
    'is_private'    => (($group['privacy'] ?? 'public') === 'private'),
]);

