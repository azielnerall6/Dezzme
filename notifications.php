<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include_once 'config.php';

$current_user_id = $_SESSION['user_id'] ?? null;
$page_user_id    = $_GET['user_id'] ?? $current_user_id;

if (!$current_user_id || $page_user_id != $current_user_id) {
    header("Location: login.php");
    exit();
}

// ── Ensure notification_reads table exists (tracks "read up to" timestamp) ──
$conn->query("
    CREATE TABLE IF NOT EXISTS notification_reads (
        user_id INT PRIMARY KEY,
        last_read_at DATETIME NOT NULL
    )
");

// ── Handle AJAX: mark all as read ────────────────────
if (isset($_POST['mark_all_read'])) {
    header('Content-Type: application/json');
    $uid_int   = (int)$current_user_id;

    // Record "everything up to now" as read for virtual notification types
    $conn->query("
        INSERT INTO notification_reads (user_id, last_read_at)
        VALUES ($uid_int, NOW())
        ON DUPLICATE KEY UPDATE last_read_at = NOW()
    ");

    // Still mark real notifications-table rows (group_invite) as read
    $conn->query("UPDATE notifications SET is_read=1 WHERE user_id=$uid_int AND DATE(created_at)=CURDATE()");

    echo json_encode(['ok' => true]);
    exit();
}

// ── Handle AJAX: mark specific notification as read ──
if (isset($_POST['mark_notif_read'])) {
    header('Content-Type: application/json');
    $uid_int = (int)$current_user_id;
    $nid = (int)($_POST['notif_id'] ?? 0);
    if ($nid > 0) {
        $conn->query("UPDATE notifications SET is_read=1 WHERE id=$nid AND user_id=$uid_int");
    }
    echo json_encode(['ok' => true]);
    exit();
}

// ── Helper: human time ────────────────────────────────────
function timeAgoNotif($datetime) {
    if (empty($datetime)) return 'recently';
    try {
        $now  = new DateTime();
        $past = new DateTime($datetime);
        $diff = $now->diff($past);
        if ($diff->days > 0) return $diff->days . 'd ago';
        if ($diff->h > 0)    return $diff->h . 'h ago';
        if ($diff->i > 0)    return $diff->i . 'm ago';
        return 'just now';
    } catch (Exception $e) { return 'recently'; }
}

// ── Helper: group same-type notifications by day ─────────
function groupNotifications(array $items): array {
    $grouped   = [];
    $seen_keys = [];
    foreach ($items as $n) {
        $type      = $n['type'] ?? '';
        $groupable = ['like','repost','favorite','download','view','follow'];
        if (in_array($type, $groupable)) {
            $key = $type . '_' . date('Y-m-d', strtotime($n['created_at'] ?? 'now'));
            if (!isset($seen_keys[$key])) {
                $seen_keys[$key]         = count($grouped);
                $n['_group_count']       = 1;
                $n['_group_actors']      = [['username' => $n['username'], 'profile_pic' => $n['profile_pic'], 'actor_id' => $n['actor_id']]];
                $grouped[]               = $n;
            } else {
                $idx = $seen_keys[$key];
                $grouped[$idx]['_group_count']++;
                $grouped[$idx]['_group_actors'][] = ['username' => $n['username'], 'profile_pic' => $n['profile_pic'], 'actor_id' => $n['actor_id']];
            }
        } else {
            $n['_group_count']  = 1;
            $n['_group_actors'] = [];
            $grouped[]          = $n;
        }
    }
    return $grouped;
}

$uid = (int)$current_user_id;

// ── Fetch this user's "read up to" timestamp for virtual notification types ──
$last_read_row = $conn->query("SELECT last_read_at FROM notification_reads WHERE user_id=$uid")->fetch_assoc();
$last_read_at  = $last_read_row['last_read_at'] ?? '1970-01-01 00:00:00';

// ── Auto-fix: backfill ref_id on group_invite rows ────────
$conn->query("
    UPDATE notifications n
    JOIN groups g ON TRIM(g.title) = TRIM(SUBSTRING(n.message, LOCATE('Pod: ', n.message) + 5))
    SET n.ref_id = g.id
    WHERE n.type = 'group_invite' AND n.ref_id IS NULL AND n.message LIKE '%Pod: %'
");

// ── Main UNION query ──────────────────────────────────────
$sql = "
(SELECT
    'like'                                                          AS type,
    CONVERT(l.created_at, CHAR)                                    AS created_at,
    u.id                                                           AS actor_id,
    CONVERT(u.username    USING utf8mb4)                           AS username,
    CONVERT(u.profile_pic USING utf8mb4)                           AS profile_pic,
    CONVERT(CONCAT(u.username, ' liked your post') USING utf8mb4)  AS message,
    0                                                              AS ref_id,
    0                                                              AS is_read,
    0                                                              AS notif_id
 FROM likes l
 JOIN users u ON l.user_id = u.id
 WHERE l.meme_id IN (SELECT id FROM memes WHERE uploaded_by = ?)
   AND l.user_id != ?
   AND DATE(l.created_at) = CURDATE())

UNION ALL

(SELECT 'follow', CONVERT(f.created_at, CHAR),
    u.id, CONVERT(u.username USING utf8mb4), CONVERT(u.profile_pic USING utf8mb4),
    CONVERT(CONCAT(u.username, ' started Tracking you') USING utf8mb4), 0, 0, 0
 FROM followers f JOIN users u ON f.follower_id = u.id
 WHERE f.following_id = ? AND f.follower_id != ? AND DATE(f.created_at) = CURDATE())

UNION ALL

(SELECT 'view', CONVERT(pv.viewed_at, CHAR),
    u.id, CONVERT(u.username USING utf8mb4), CONVERT(u.profile_pic USING utf8mb4),
    CONVERT(CONCAT(u.username, ' viewed your profile') USING utf8mb4), 0, 0, 0
 FROM profile_views pv JOIN users u ON pv.viewer_id = u.id
 WHERE pv.profile_id = ? AND pv.viewer_id != ? AND DATE(pv.viewed_at) = CURDATE())

UNION ALL

(SELECT 'group_join', CONVERT(gm.joined_at, CHAR),
    u.id, CONVERT(u.username USING utf8mb4), CONVERT(u.profile_pic USING utf8mb4),
    CONVERT(CONCAT(u.username, ' joined your Pod') USING utf8mb4), 0, 0, 0
 FROM group_members gm JOIN users u ON gm.user_id = u.id
 WHERE gm.group_id IN (SELECT id FROM groups WHERE created_by = ?)
   AND gm.user_id != ? AND DATE(gm.joined_at) = CURDATE())

UNION ALL

(SELECT 'following_post', CONVERT(m.created_at, CHAR),
    u.id, CONVERT(u.username USING utf8mb4), CONVERT(u.profile_pic USING utf8mb4),
    CONVERT(CONCAT(u.username, ' posted a new meme') USING utf8mb4), 0, 0, 0
 FROM memes m JOIN users u ON m.uploaded_by = u.id
 WHERE m.uploaded_by IN (SELECT following_id FROM followers WHERE follower_id = ?)
   AND DATE(m.created_at) = CURDATE())

UNION ALL

(SELECT 'group_post', CONVERT(gp.created_at, CHAR),
    u.id, CONVERT(u.username USING utf8mb4), CONVERT(u.profile_pic USING utf8mb4),
    CONVERT(CONCAT(u.username, ' posted in a Pod you are in') USING utf8mb4), 0, 0, 0
 FROM group_posts gp JOIN users u ON gp.posted_by = u.id
 WHERE gp.group_id IN (
       SELECT group_id FROM group_members WHERE user_id = ?
       UNION SELECT id FROM groups WHERE created_by = ?
 ) AND gp.posted_by != ? AND DATE(gp.created_at) = CURDATE())

UNION ALL

(SELECT 'remix', CONVERT(r.created_at, CHAR),
    u.id, CONVERT(u.username USING utf8mb4), CONVERT(u.profile_pic USING utf8mb4),
    CONVERT(CONCAT(u.username, ' remixed your meme') USING utf8mb4), 0, 0, 0
 FROM remix r JOIN users u ON r.user_id = u.id
 WHERE r.root_id IN (SELECT id FROM memes WHERE uploaded_by = ?)
   AND r.user_id != ? AND DATE(r.created_at) = CURDATE())

UNION ALL

(SELECT 'message', CONVERT(m.created_at, CHAR),
    u.id, CONVERT(u.username USING utf8mb4), CONVERT(u.profile_pic USING utf8mb4),
    CONVERT(CONCAT(u.username, ' sent you a message') USING utf8mb4), 0, 0, 0
 FROM messages m JOIN users u ON m.sender_id = u.id
 WHERE m.receiver_id = ? AND m.sender_id != ? AND DATE(m.created_at) = CURDATE())

UNION ALL

(SELECT 'repost', CONVERT(r.created_at, CHAR),
    u.id, CONVERT(u.username USING utf8mb4), CONVERT(u.profile_pic USING utf8mb4),
    CONVERT(CONCAT(u.username, ' reposted your meme') USING utf8mb4), 0, 0, 0
 FROM reposts r JOIN users u ON r.user_id = u.id
 WHERE r.meme_id IN (SELECT id FROM memes WHERE uploaded_by = ?)
   AND r.user_id != ? AND DATE(r.created_at) = CURDATE())

UNION ALL

(SELECT 'favorite', CONVERT(f.created_at, CHAR),
    u.id, CONVERT(u.username USING utf8mb4), CONVERT(u.profile_pic USING utf8mb4),
    CONVERT(CONCAT(u.username, ' saved your meme') USING utf8mb4), 0, 0, 0
 FROM favorites f JOIN users u ON f.user_id = u.id
 WHERE f.meme_id IN (SELECT id FROM memes WHERE uploaded_by = ?)
   AND f.user_id != ? AND DATE(f.created_at) = CURDATE())

UNION ALL

(SELECT 'download', CONVERT(d.downloaded_at, CHAR),
    u.id, CONVERT(u.username USING utf8mb4), CONVERT(u.profile_pic USING utf8mb4),
    CONVERT(CONCAT(u.username, ' downloaded your meme') USING utf8mb4), 0, 0, 0
 FROM downloads d JOIN users u ON d.user_id = u.id
 WHERE d.meme_id IN (SELECT id FROM memes WHERE uploaded_by = ?)
   AND d.user_id != ? AND DATE(d.downloaded_at) = CURDATE())

UNION ALL

(SELECT 'vibe', CONVERT(vv.created_at, CHAR),
    u.id, CONVERT(u.username USING utf8mb4), CONVERT(u.profile_pic USING utf8mb4),
    CONVERT(CONCAT(u.username, ' voted your vibe: ', vv.vibe) USING utf8mb4), 0, 0, 0
 FROM vibe_votes vv JOIN users u ON vv.voter_id = u.id
 WHERE vv.profile_id = ? AND vv.voter_id != ? AND DATE(vv.created_at) = CURDATE())

UNION ALL

(SELECT 'admin_appoint', CONVERT(ga.appointed_at, CHAR),
    u.id, CONVERT(u.username USING utf8mb4), CONVERT(u.profile_pic USING utf8mb4),
    CONVERT(CONCAT(u.username, ' appointed you as Pod Admin') USING utf8mb4), 0, 0, 0
 FROM group_admins ga JOIN groups g ON g.id = ga.group_id JOIN users u ON u.id = g.created_by
 WHERE ga.user_id = ? AND g.created_by != ? AND DATE(ga.appointed_at) = CURDATE())

UNION ALL

(SELECT 'collection', CONVERT(ci.added_at, CHAR),
    u.id, CONVERT(u.username USING utf8mb4), CONVERT(u.profile_pic USING utf8mb4),
    CONVERT(CONCAT(u.username, ' added your meme to a collection') USING utf8mb4), 0, 0, 0
 FROM collection_items ci JOIN collections c ON c.id = ci.collection_id JOIN users u ON u.id = c.user_id
 WHERE ci.meme_id IN (SELECT id FROM memes WHERE uploaded_by = ?)
   AND c.user_id != ? AND DATE(ci.added_at) = CURDATE())

UNION ALL

(SELECT 'subscription', CONVERT(s.created_at, CHAR),
    u.id, CONVERT(u.username USING utf8mb4), CONVERT(u.profile_pic USING utf8mb4),
    CONVERT(CONCAT(u.username, ' requested a subscription') USING utf8mb4), 0, 0, 0
 FROM subscriptions s JOIN users u ON u.id = s.subscriber_id
 WHERE s.owner_id = ? AND s.subscriber_id != ? AND DATE(s.created_at) = CURDATE())

UNION ALL

(SELECT 'track_request', CONVERT(tr.created_at, CHAR),
    u.id, CONVERT(u.username USING utf8mb4), CONVERT(u.profile_pic USING utf8mb4),
    CONVERT(CONCAT(u.username, ' sent you a Track Request') USING utf8mb4), 0, 0, 0
 FROM track_requests tr JOIN users u ON u.id = tr.requester_id
 WHERE tr.target_id = ? AND tr.requester_id != ? AND DATE(tr.created_at) = CURDATE())

UNION ALL

(SELECT 'track_approved', CONVERT(tr.created_at, CHAR),
    u.id, CONVERT(u.username USING utf8mb4), CONVERT(u.profile_pic USING utf8mb4),
    CONVERT(CONCAT(u.username, ' approved your Track Request') USING utf8mb4), 0, 0, 0
 FROM track_requests tr JOIN users u ON u.id = tr.target_id
 WHERE tr.requester_id = ? AND tr.status = 'approved' AND tr.target_id != ? AND DATE(tr.created_at) = CURDATE())

UNION ALL

(SELECT 'sponsorship', CONVERT(sp.created_at, CHAR),
    u.id, CONVERT(u.username USING utf8mb4), CONVERT(u.profile_pic USING utf8mb4),
    CONVERT(CONCAT('Your sponsorship was ', sp.status) USING utf8mb4), 0, 0, 0
 FROM sponsorships sp JOIN users u ON u.id = sp.user_id
 WHERE sp.user_id = ? AND sp.status != 'pending' AND DATE(sp.created_at) = CURDATE())

UNION ALL

(SELECT 'pod_like', CONVERT(pl.created_at, CHAR),
    u.id, CONVERT(u.username USING utf8mb4), CONVERT(u.profile_pic USING utf8mb4),
    CONVERT(CONCAT(u.username, ' liked your Pod Discussion') USING utf8mb4), 0, 0, 0
 FROM pod_likes pl JOIN users u ON u.id = pl.user_id
 WHERE pl.discussion_id IN (SELECT id FROM pod_discussions WHERE user_id = ?)
   AND pl.user_id != ? AND pl.type = 'like' AND DATE(pl.created_at) = CURDATE())

UNION ALL

(SELECT 'pod_reply', CONVERT(r.created_at, CHAR),
    u.id, CONVERT(u.username USING utf8mb4), CONVERT(u.profile_pic USING utf8mb4),
    CONVERT(CONCAT(u.username, ' replied to your Pod Discussion') USING utf8mb4), 0, 0, 0
 FROM pod_discussion_replies r JOIN users u ON u.id = r.user_id
 WHERE r.discussion_id IN (SELECT id FROM pod_discussions WHERE user_id = ?)
   AND r.user_id != ? AND DATE(r.created_at) = CURDATE())

UNION ALL

(SELECT 'group_invite', CONVERT(n.created_at, CHAR),
    u.id, CONVERT(u.username USING utf8mb4), CONVERT(u.profile_pic USING utf8mb4),
    CONVERT(n.message USING utf8mb4),
    COALESCE(n.ref_id,
        (SELECT g.id FROM groups g WHERE TRIM(SUBSTRING(n.message, LOCATE('Pod: ', n.message) + 5)) = TRIM(g.title) LIMIT 1), 0),
    n.is_read, n.id
 FROM notifications n JOIN users u ON u.id = n.actor_id
 WHERE n.user_id = ? AND n.type = 'group_invite' AND DATE(n.created_at) = CURDATE())

UNION ALL

(SELECT 'pod_nested_reply', CONVERT(r.created_at, CHAR),
    u.id, CONVERT(u.username USING utf8mb4), CONVERT(u.profile_pic USING utf8mb4),
    CONVERT(CONCAT(u.username, ' replied to your comment in a Pod') USING utf8mb4), 0, 0, 0
 FROM pod_discussion_replies r JOIN users u ON u.id = r.user_id
 WHERE r.parent_id IN (SELECT id FROM pod_discussion_replies WHERE user_id = ?)
   AND r.user_id != ? AND DATE(r.created_at) = CURDATE())

UNION ALL

(SELECT 'pod_discussion', CONVERT(d.created_at, CHAR),
    u.id, CONVERT(u.username USING utf8mb4), CONVERT(u.profile_pic USING utf8mb4),
    CONVERT(CONCAT(u.username, ' started a Discussion in your Pod') USING utf8mb4), 0, 0, 0
 FROM pod_discussions d JOIN users u ON u.id = d.user_id
 WHERE d.group_id IN (
       SELECT group_id FROM group_members WHERE user_id = ?
       UNION SELECT id FROM groups WHERE created_by = ?
 ) AND d.user_id != ? AND DATE(d.created_at) = CURDATE())

ORDER BY created_at DESC
LIMIT 150
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    $notifications = [];
    $notif_count   = 0;
    $unread_count  = 0;
} else {
    $actual_count = substr_count($sql, '?');
    $params       = array_fill(0, $actual_count, $uid);
    $types        = str_repeat('i', $actual_count);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result        = $stmt->get_result();
    $raw_notifs    = $result->fetch_all(MYSQLI_ASSOC);
    $notifications = $raw_notifs;

    // ── Override is_read for virtual notification types based on last_read_at ──
    foreach ($notifications as &$n) {
        if (($n['type'] ?? '') !== 'group_invite') {
            $n['is_read'] = (strtotime($n['created_at']) <= strtotime($last_read_at)) ? 1 : 0;
        }
    }
    unset($n);

    $notif_count   = count($notifications);
    $unread_count  = 0;
    foreach ($notifications as $n) {
        if (!($n['is_read'] ?? false)) $unread_count++;
    }
}

// ── Category grouping ─────────────────────────────────────
$categories = [
    'all'      => [],
    'social'   => [],
    'pods'     => [],
    'content'  => [],
    'messages' => [],
];

$social_types  = ['follow','view','vibe','track_request','track_approved','admin_appoint','group_invite'];
$pod_types     = ['group_join','group_post','pod_like','pod_reply','pod_nested_reply','pod_discussion'];
$content_types = ['like','repost','favorite','download','remix','collection','following_post','subscription','sponsorship'];
$msg_types     = ['message'];

foreach ($notifications as $n) {
    $categories['all'][] = $n;
    if (in_array($n['type'], $social_types))  $categories['social'][]   = $n;
    if (in_array($n['type'], $pod_types))     $categories['pods'][]     = $n;
    if (in_array($n['type'], $content_types)) $categories['content'][]  = $n;
    if (in_array($n['type'], $msg_types))     $categories['messages'][] = $n;
}

foreach ($categories as $k => $v) {
    $categories[$k] = groupNotifications($v);
}

// ── Icon map ──────────────────────────────────────────────
$icon_map = [
    'like'             => ['ri-heart-fill',          '#7a0000'],
    'follow'           => ['ri-user-follow-fill',    '#8b0000'],
    'view'             => ['ri-eye-fill',             '#9b1010'],
    'group_join'       => ['ri-group-fill',           '#a81818'],
    'following_post'   => ['ri-image-fill',           '#b52020'],
    'group_post'       => ['ri-layout-grid-fill',     '#c02828'],
    'remix'            => ['ri-repeat-fill',          '#cc3030'],
    'message'          => ['ri-message-3-fill',       '#d43838'],
    'repost'           => ['ri-repeat-2-fill',        '#dd4040'],
    'favorite'         => ['ri-bookmark-fill',        '#e44848'],
    'download'         => ['ri-download-fill',        '#e85555'],
    'vibe'             => ['ri-fire-fill',            '#ec6060'],
    'admin_appoint'    => ['ri-shield-star-fill',     '#ef6e6e'],
    'collection'       => ['ri-folder-fill',          '#f27878'],
    'subscription'     => ['ri-vip-crown-fill',       '#f48484'],
    'track_request'    => ['ri-radar-fill',           '#f69090'],
    'track_approved'   => ['ri-checkbox-circle-fill', '#f79d9d'],
    'sponsorship'      => ['ri-megaphone-fill',       '#f9a8a8'],
    'pod_like'         => ['ri-heart-3-fill',         '#fab2b2'],
    'pod_reply'        => ['ri-chat-3-fill',          '#fbbcbc'],
    'pod_nested_reply' => ['ri-reply-fill',           '#fcc6c6'],
    'pod_discussion'   => ['ri-discuss-fill',         '#fdd0d0'],
    'group_invite'     => ['ri-user-add-fill',        '#4dabff'],
];

$type_labels = [
    'like'             => 'Likes',
    'repost'           => 'Reposts',
    'favorite'         => 'Saves',
    'download'         => 'Downloads',
    'remix'            => 'Remixes',
    'collection'       => 'Collections',
    'follow'           => 'New Trackers',
    'view'             => 'Profile Views',
    'vibe'             => 'Vibe Votes',
    'linked_account'   => 'Linked Accounts',
    'track_request'    => 'Track Requests',
    'track_approved'   => 'Track Approved',
    'admin_appoint'    => 'Pod Admin',
    'message'          => 'Messages',
    'group_join'       => 'Pod Joins',
    'group_post'       => 'Pod Posts',
    'pod_like'         => 'Pod Likes',
    'pod_reply'        => 'Pod Replies',
    'pod_nested_reply' => 'Pod Replies',
    'pod_discussion'   => 'Pod Discussions',
    'following_post'   => 'New Posts',
    'subscription'     => 'Subscriptions',
    'sponsorship'      => 'Sponsorships',
    'group_invite'     => 'Pod Invites',
];

$tab_labels = [
    'all'      => ['All',      $notif_count],
    'social'   => ['Social',   count($categories['social'])],
    'pods'     => ['Pods',     count($categories['pods'])],
    'content'  => ['Content',  count($categories['content'])],
    'messages' => ['Messages', count($categories['messages'])],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta charset="UTF-8">
<title>Your Notifications</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --red:     #FF4C4C;
    --surface: rgba(255,255,255,0.05);
    --border:  rgba(255,255,255,0.10);
    --text:    #ffffff;
    --muted:   rgba(255,255,255,0.45);
    --divider: rgba(255,255,255,0.08);
}

body {
    font-family: 'Poppins', sans-serif;
    background: linear-gradient(-45deg,#010218,#070924,#070924);
    background-attachment: fixed;
    color: var(--text);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 0 0 60px;
    overflow-x: hidden;
}

/* ── Skeleton ── */
#page-skeleton {
    position: fixed;
    inset: 0;
    background: linear-gradient(-45deg,#010218,#070924,#070924);
    z-index: 9999;
    display: flex;
    flex-direction: column;
    align-items: center;
    opacity: 1;
    transition: opacity 0.35s ease;
    pointer-events: none;
    padding: 0 16px 60px;
}
#page-skeleton.hide { opacity: 0; }

@keyframes shimmer {
    0%   { background-position: -500px 0; }
    100% { background-position:  500px 0; }
}
.sk {
    background: linear-gradient(90deg,
        rgba(255,255,255,0.04) 25%,
        rgba(255,255,255,0.09) 50%,
        rgba(255,255,255,0.04) 75%);
    background-size: 500px 100%;
    animation: shimmer 1.5s infinite linear;
    border-radius: 8px;
}

.sk-topbar {
    width: 100%;
    max-width: 480px;
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 20px 0 16px;
}
.sk-back   { width: 22px; height: 22px; border-radius: 4px; flex-shrink: 0; }
.sk-title  { width: 180px; height: 18px; border-radius: 6px; }

.sk-tabs {
    width: 100%;
    max-width: 480px;
    display: flex;
    gap: 0;
    border-bottom: 1px solid var(--divider);
    margin-bottom: 0;
}
.sk-tab { flex: 1; height: 40px; border-radius: 0; }
.sk-tab + .sk-tab { margin-left: 2px; }

.sk-list {
    width: 100%;
    max-width: 480px;
    display: flex;
    flex-direction: column;
    margin-top: 0;
}
.sk-row {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 0;
    border-bottom: 1px solid var(--divider);
}
.sk-avatar { width: 38px; height: 38px; border-radius: 50%; flex-shrink: 0; }
.sk-info   { flex: 1; display: flex; flex-direction: column; gap: 6px; }
.sk-msg    { width: 150px; height: 11px; border-radius: 4px; }
.sk-time   { width: 50px;  height: 8px;  border-radius: 4px; }

/* ── Top bar ── */
.topbar {
    width: 100%;
    max-width: 480px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    padding: 20px 16px 14px;
    position: sticky;
    top: 0;
    z-index: 100;
    background: linear-gradient(-45deg,#010218,#070924,#070924);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
}

.topbar-left {
    display: flex;
    align-items: center;
    gap: 14px;
}

.back-btn {
    background: none;
    border: none;
    color: var(--text);
    font-size: 1.4rem;
    cursor: pointer;
    opacity: 0.75;
    padding: 0;
    display: flex;
    align-items: center;
    flex-shrink: 0;
    transition: opacity 0.2s, color 0.2s;
    text-decoration: none;
}

.back-btn:hover { opacity: 1; color: var(--red); }

.topbar-title {
    font-size: 1rem;
    font-weight: 600;
    color: var(--text);
}

.mark-all-btn {
    padding: 6px 13px;
    border-radius: 14px;
    background: rgba(255,76,76,0.15);
    border: 1px solid rgba(255,76,76,0.3);
    color: var(--red);
    font-family: 'Poppins', sans-serif;
    font-size: 0.72rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    white-space: nowrap;
    flex-shrink: 0;
}
.mark-all-btn:hover {
    background: rgba(255,76,76,0.25);
    border-color: rgba(255,76,76,0.5);
}

/* ── Tabs ── */
.tabs-wrap {
    width: 100%;
    max-width: 480px;
    display: flex;
    border-bottom: 1px solid var(--divider);
    position: sticky;
    top: 62px;
    z-index: 99;
    background: linear-gradient(-45deg,#010218,#070924,#070924);
    gap: 0;
    overflow-x: auto;
    scrollbar-width: none;
}
.tabs-wrap::-webkit-scrollbar { display: none; }

.tab-btn {
    flex: 1;
    min-width: 70px;
    padding: 13px 6px;
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    color: var(--muted);
    font-family: 'Poppins', sans-serif;
    font-size: 0.78rem;
    font-weight: 500;
    cursor: pointer;
    text-align: center;
    transition: color 0.2s, border-color 0.2s;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
    margin-bottom: -1px;
}
.tab-btn .tab-count {
    font-size: 0.92rem;
    font-weight: 700;
    color: var(--text);
    line-height: 1;
}
.tab-btn .tab-label {
    font-size: 0.68rem;
    font-weight: 500;
    letter-spacing: 0.02em;
}
.tab-btn.active {
    color: var(--text);
    border-bottom-color: #fff;
}
.tab-btn.active .tab-count { color: var(--text); }

/* ── Notification list ── */
.notif-panels {
    width: 100%;
    max-width: 480px;
    display: flex;
    flex-direction: column;
}

.notif-panel { display: none; }
.notif-panel.active { display: flex; flex-direction: column; }

.notif-section-label {
    padding: 10px 16px 6px;
    font-size: 0.72rem;
    font-weight: 800;
    color: rgba(255,255,255,0.22);
    text-transform: uppercase;
    letter-spacing: 1.2px;
    font-family: 'Poppins', sans-serif;
    background: rgba(255,255,255,0.018);
    border-bottom: 1px solid rgba(255,255,255,0.04);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.notif-section-count {
    background: rgba(255,255,255,0.07);
    border-radius: 20px;
    padding: 1px 6px;
    font-size: 0.65rem;
    font-weight: 800;
    color: rgba(255,255,255,0.3);
}

.notif-item {
    display: flex;
    align-items: center;
    gap: 11px;
    padding: 12px 16px;
    border-bottom: 1px solid rgba(255,255,255,0.04);
    text-decoration: none;
    transition: background 0.15s;
    cursor: pointer;
    position: relative;
    color: var(--text);
}

.notif-item:last-child { border-bottom: none; }
.notif-item:hover { background: rgba(255,255,255,0.04); }

.notif-item.unread::before {
    content: '';
    position: absolute;
    left: 5px;
    top: 50%;
    transform: translateY(-50%);
    width: 4px;
    height: 4px;
    border-radius: 50%;
    background: var(--red);
}

.notif-avatar-wrap {
    position: relative;
    flex-shrink: 0;
}

.notif-avatar {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid var(--red);
    display: block;
}

.notif-avatar-stack {
    position: relative;
    width: 38px;
    height: 38px;
    flex-shrink: 0;
}

.notif-avatar-stack .sa-img {
    position: absolute;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    object-fit: cover;
    border: 1.5px solid #080810;
}

.notif-avatar-stack .sa-img:nth-child(1) { top: 0; left: 0; z-index: 3; }
.notif-avatar-stack .sa-img:nth-child(2) { bottom: 0; right: 0; z-index: 2; }

.notif-avatar-stack .sa-more {
    position: absolute;
    bottom: 0;
    right: 0;
    z-index: 4;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: rgba(255,76,76,0.8);
    border: 1.5px solid #080810;
    font-size: 7px;
    font-weight: 800;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Poppins', sans-serif;
}

.notif-type-icon {
    position: absolute;
    bottom: -2px;
    right: -2px;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: #080810;
    border: 1.5px solid #080810;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 9px;
}

.notif-text {
    flex: 1;
    min-width: 0;
}

.notif-msg {
    font-size: 12px;
    color: rgba(255,255,255,0.82);
    line-height: 1.4;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    font-family: 'Poppins', sans-serif;
}

.notif-msg strong { color: #fff; font-weight: 700; }

.notif-time {
    font-size: 9px;
    color: rgba(255,255,255,0.28);
    margin-top: 2px;
    display: flex;
    align-items: center;
    gap: 3px;
    font-family: 'Poppins', sans-serif;
}

.notif-time i { font-size: 8px; }

/* ── Empty state ── */
.empty-state {
    padding: 60px 20px 40px;
    text-align: center;
    color: var(--muted);
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
}

.empty-state i { font-size: 3rem; opacity: 0.3; }
.empty-state span { font-size: 0.82rem; }

/* ── Actors modal ── */
.notif-actors-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.6);
    z-index: 99999999;
    align-items: center;
    justify-content: center;
}
.notif-actors-overlay.open { display: flex; }

.notif-actors-modal {
    background: rgba(14,14,28,0.98);
    border-radius: 18px;
    width: clamp(280px, 90vw, 380px);
    max-height: 70vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 24px 64px rgba(0,0,0,0.9), 0 0 0 1px rgba(255,255,255,0.06);
    animation: slideUp 0.22s cubic-bezier(0.22,1,0.36,1);
}

@keyframes slideUp {
    from { transform: translateY(60px); opacity: 0; }
    to   { transform: translateY(0);    opacity: 1; }
}

.notif-actors-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 16px;
    border-bottom: 1px solid rgba(255,255,255,0.07);
    font-family: 'Poppins', sans-serif;
    font-size: 13px;
    font-weight: 800;
    color: #fff;
}

.notif-actors-close {
    background: none;
    border: none;
    cursor: pointer;
    color: rgba(255,255,255,0.5);
    font-size: 16px;
    padding: 2px 6px;
    border-radius: 8px;
    transition: 0.2s;
}

.notif-actors-close:hover { color: #fff; background: rgba(255,255,255,0.08); }

.notif-actors-list {
    overflow-y: auto;
    flex: 1;
    scrollbar-width: thin;
    scrollbar-color: rgba(255,255,255,0.07) transparent;
}

.notif-actor-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 16px;
    text-decoration: none;
    border-bottom: 1px solid rgba(255,255,255,0.04);
    transition: background 0.15s;
    cursor: pointer;
}

.notif-actor-row:last-child { border-bottom: none; }
.notif-actor-row:hover { background: rgba(255,255,255,0.05); }

.notif-actor-row img {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    object-fit: cover;
    border: 1.5px solid var(--red);
    flex-shrink: 0;
}

.notif-actor-row span {
    font-size: 13px;
    font-weight: 600;
    color: #fff;
    font-family: 'Poppins', sans-serif;
}

/* ══════════════════════════════
   RESPONSIVE
══════════════════════════════ */
@media (max-width: 768px) {
    .topbar { padding: 18px 14px 12px; gap: 10px; }
    .topbar-title { font-size: 0.95rem; }
    .back-btn { font-size: 1.3rem; }
    .mark-all-btn { font-size: 0.68rem; padding: 5px 11px; }
    .tab-btn { padding: 11px 4px; }
    .tab-btn .tab-count { font-size: 0.85rem; }
    .tab-btn .tab-label { font-size: 0.64rem; }
    .notif-item { padding: 11px 14px; gap: 10px; }
    .notif-avatar { width: 35px; height: 35px; }
    .notif-avatar-stack { width: 35px; height: 35px; }
    .notif-avatar-stack .sa-img { width: 22px; height: 22px; }
    .notif-msg { font-size: 11px; }
    .notif-time { font-size: 8.5px; }
    .notif-section-label { font-size: 0.68rem; padding: 8px 14px 5px; }
    .empty-state { padding: 50px 16px 30px; }
    .empty-state i { font-size: 2.5rem; }
    .empty-state span { font-size: 0.78rem; }
    #page-skeleton { padding: 0 14px 60px; }
    .sk-topbar { padding: 18px 0 12px; }
    .sk-list { padding: 0 14px; }
}

@media (max-width: 480px) {
    .topbar { padding: 16px 12px 10px; gap: 9px; }
    .topbar-left { gap: 11px; }
    .topbar-title { font-size: 0.88rem; }
    .back-btn { font-size: 1.2rem; }
    .mark-all-btn { font-size: 0.64rem; padding: 4px 9px; border-radius: 12px; }
    .tab-btn { padding: 10px 3px; }
    .tab-btn .tab-count { font-size: 0.8rem; }
    .tab-btn .tab-label { font-size: 0.6rem; }
    .notif-item { padding: 10px 12px; gap: 9px; }
    .notif-avatar { width: 34px; height: 34px; border-width: 1.5px; }
    .notif-msg { font-size: 10.5px; }
    .notif-time { font-size: 8px; margin-top: 1px; }
    .notif-section-label { font-size: 0.64rem; padding: 7px 12px 4px; }
    .empty-state { padding: 40px 14px 24px; }
    .empty-state i { font-size: 2.2rem; margin-bottom: 6px; }
    .empty-state span { font-size: 0.76rem; }
    .sk-topbar { padding: 14px 0 10px; gap: 12px; }
    .sk-title { width: 140px; }
    #page-skeleton { padding: 0 12px 60px; }
    .tabs-wrap { top: 54px; }
    .sk-tabs { top: 54px; }
}

@media (max-width: 360px) {
    .topbar { padding: 14px 10px 9px; gap: 8px; }
    .topbar-title { font-size: 0.82rem; }
    .back-btn { font-size: 1.1rem; }
    .mark-all-btn { font-size: 0.62rem; padding: 3px 7px; }
    .tab-btn { padding: 9px 2px; }
    .tab-btn .tab-count { font-size: 0.75rem; }
    .tab-btn .tab-label { font-size: 0.56rem; }
    .notif-item { padding: 9px 11px; gap: 8px; }
    .notif-avatar { width: 32px; height: 32px; }
    .notif-avatar-stack { width: 31px; height: 31px; }
    .notif-avatar-stack .sa-img { width: 20px; height: 20px; }
    .notif-msg { font-size: 10px; }
    .notif-time { font-size: 7.5px; }
    .notif-section-label { font-size: 0.6rem; padding: 6px 11px 4px; }
    .empty-state { padding: 35px 12px 20px; }
    .empty-state i { font-size: 2rem; margin-bottom: 6px; }
    .empty-state span { font-size: 0.72rem; }
    #page-skeleton { padding: 0 10px 60px; }
}

@media (max-width: 320px) {
    .topbar { padding: 12px 8px 8px; gap: 6px; }
    .topbar-title { font-size: 0.76rem; }
    .back-btn { font-size: 1rem; }
    .mark-all-btn { font-size: 0.58rem; padding: 3px 6px; }
    .tab-btn { padding: 8px 1.5px; min-width: 60px; }
    .tab-btn .tab-count { font-size: 0.7rem; }
    .tab-btn .tab-label { font-size: 0.52rem; }
    .notif-item { padding: 8px 10px; gap: 7px; }
    .notif-avatar { width: 30px; height: 30px; }
    .notif-avatar-stack { width: 29px; height: 29px; }
    .notif-avatar-stack .sa-img { width: 19px; height: 19px; }
    .notif-msg { font-size: 9.5px; }
    .notif-time { font-size: 7px; }
    .notif-section-label { font-size: 0.56rem; padding: 5px 10px 3px; }
    .empty-state { padding: 30px 10px 16px; }
    .empty-state i { font-size: 1.8rem; margin-bottom: 5px; }
    .empty-state span { font-size: 0.68rem; }
    #page-skeleton { padding: 0 8px 60px; }
}
</style>
</head>
<body>

<!-- ══ SKELETON ══ -->
<div id="page-skeleton">
    <div class="sk-topbar">
        <div class="sk sk-back"></div>
        <div class="sk sk-title"></div>
    </div>
    <div class="sk-tabs">
        <div class="sk sk-tab"></div>
        <div class="sk sk-tab"></div>
        <div class="sk sk-tab"></div>
        <div class="sk sk-tab"></div>
        <div class="sk sk-tab"></div>
    </div>
    <div class="sk-list">
        <?php for($i=0;$i<6;$i++): ?>
        <div class="sk-row">
            <div class="sk sk-avatar"></div>
            <div class="sk-info">
                <div class="sk sk-msg"></div>
                <div class="sk sk-time"></div>
            </div>
        </div>
        <?php endfor; ?>
    </div>
</div>

<!-- ══ TOPBAR ══ -->
<div class="topbar">
    <div class="topbar-left">
        <button class="back-btn" onclick="history.back()" aria-label="Go back">
            <i class="ri-arrow-left-s-line"></i>
        </button>
        <span class="topbar-title">Your Notifications</span>
    </div>
    <?php if($unread_count > 0): ?>
    <button class="mark-all-btn" onclick="markAllRead()">Mark All Read</button>
    <?php endif; ?>
</div>

<!-- ══ TABS ══ -->
<div class="tabs-wrap">
    <?php foreach ($tab_labels as $key => [$label, $count]): ?>
    <button class="tab-btn <?= $key === 'all' ? 'active' : '' ?>"
            onclick="switchTab('<?= $key ?>', this)">
        <span class="tab-count"><?= $count ?></span>
        <span class="tab-label"><?= $label ?></span>
    </button>
    <?php endforeach; ?>
</div>

<!-- ══ NOTIFICATIONS ══ -->
<div class="notif-panels">
    <?php foreach ($categories as $cat_key => $items): ?>
    <div class="notif-panel <?= $cat_key === 'all' ? 'active' : '' ?>" id="panel-<?= $cat_key ?>">

        <?php if (empty($items)): ?>
        <div class="empty-state">
            <i class="ri-notification-off-line"></i>
            <span>Nothing here today.<br>Check back later!</span>
        </div>
        <?php else:
            $sections = [];
            foreach ($items as $n) {
                $sec = $type_labels[$n['type'] ?? ''] ?? 'Other';
                if (!isset($sections[$sec])) $sections[$sec] = [];
                $sections[$sec][] = $n;
            }
            foreach ($sections as $section_name => $section_items):
                $sec_count = count($section_items);
        ?>

        <div class="notif-section-label">
            <?= htmlspecialchars($section_name) ?>
            <?php if ($sec_count > 1): ?>
            <span class="notif-section-count"><?= $sec_count ?></span>
            <?php endif; ?>
        </div>

        <?php foreach ($section_items as $note):
            $ntype    = $note['type']       ?? '';
            $nactor   = (int)($note['actor_id']  ?? 0);
            $nref     = (int)($note['ref_id']    ?? 0);
            $npic     = !empty($note['profile_pic']) ? htmlspecialchars($note['profile_pic']) : 'uploads/me.jpeg';
            $nuname   = !empty($note['username'])   ? htmlspecialchars($note['username'])    : 'Someone';
            $nmsg     = !empty($note['message'])    ? htmlspecialchars($note['message'])     : 'New activity';
            $ntime    = timeAgoNotif($note['created_at'] ?? '');
            $icon     = $icon_map[$ntype] ?? ['ri-bell-fill', '#aaa'];
            $nread    = (bool)($note['is_read'] ?? false);
            $gc       = (int)($note['_group_count'] ?? 1);
            $gactors  = $note['_group_actors'] ?? [];

            $is_group_invite    = ($ntype === 'group_invite');
            $is_grouped         = ($gc > 1);
            $groupable_types    = ['like','repost','favorite','download','view','follow'];
            $is_groupable_multi = $is_grouped && in_array($ntype, $groupable_types, true);

            $msg_display = str_replace($nuname, '<strong>' . $nuname . '</strong>', $nmsg);

            $action_word = match($ntype) {
                'like'     => 'liked your post',
                'repost'   => 'reposted your meme',
                'favorite' => 'saved your meme',
                'download' => 'downloaded your meme',
                'view'     => 'viewed your profile',
                'follow'   => 'started Tracking you',
                default    => 'interacted'
            };

            if ($is_grouped && $gc === 2 && count($gactors) >= 2) {
                $n2          = htmlspecialchars($gactors[1]['username'] ?? '');
                $msg_display = '<strong>' . $nuname . '</strong> and <strong>' . $n2 . '</strong> ' . $action_word;
            } elseif ($is_grouped && $gc > 2) {
                $others      = $gc - 1;
                $msg_display = '<strong>' . $nuname . '</strong> and <strong>' . $others . ' others</strong> ' . $action_word;
            }

            if ($is_group_invite && preg_match('/Pod:\s*(.+)$/iu', $note['message'] ?? '', $gm)) {
                $safe_group_name = htmlspecialchars(trim($gm[1]));
                $group_href      = $nref > 0 ? 'group.php?id=' . $nref : 'group.php?id=0';
                $msg_display     = preg_replace(
                    '/Pod:\s*.+$/iu',
                    'Pod: <a class="notif-group-link" href="' . $group_href . '" onclick="event.stopPropagation();" style="color:#1a56db;text-decoration:none;font-weight:700;">' . $safe_group_name . '</a>',
                    $msg_display
                );
            }

            if ($is_group_invite) {
                $outer_tag  = 'div';
                $outer_attr = 'onclick="location.href=\'profile.php?user_id=' . $nactor . '\'"';
            } elseif ($is_groupable_multi) {
                $actors_json  = htmlspecialchars(json_encode($gactors), ENT_QUOTES, 'UTF-8');
                $action_label = htmlspecialchars($action_word, ENT_QUOTES);
                $outer_tag    = 'div';
                $outer_attr   = 'onclick="openActorsModal(' . $actors_json . ', \'' . $action_label . '\')"';
            } else {
                $outer_tag  = 'a';
                $outer_attr = 'href="profile.php?user_id=' . $nactor . '" onclick="markAsRead(' . ($note['notif_id'] ?? 0) . ')"';
            }

            $unread_class = (!$nread && $ntype === 'group_invite') ? ' unread' : '';
        ?>

        <<?= $outer_tag ?> class="notif-item<?= $unread_class ?>" <?= $outer_attr ?>>

            <?php if ($is_grouped && count($gactors) >= 2): ?>
            <div class="notif-avatar-stack">
                <?php foreach (array_slice($gactors, 0, 2) as $i => $ga):
                    $ga_pic = !empty($ga['profile_pic']) ? htmlspecialchars($ga['profile_pic']) : 'uploads/me.jpeg';
                ?>
                <img class="sa-img" src="<?= $ga_pic ?>" onerror="this.src='uploads/me.jpeg'" alt="">
                <?php endforeach; ?>
                <?php if ($gc > 2): ?>
                <span class="sa-more">+<?= $gc - 2 ?></span>
                <?php endif; ?>
                <span class="notif-type-icon" style="color:<?= $icon[1] ?>;">
                    <i class="<?= $icon[0] ?>"></i>
                </span>
            </div>
            <?php else: ?>
            <div class="notif-avatar-wrap">
                <img class="notif-avatar" src="<?= $npic ?>" onerror="this.src='uploads/me.jpeg'" alt="<?= $nuname ?>">
                <span class="notif-type-icon" style="color:<?= $icon[1] ?>;">
                    <i class="<?= $icon[0] ?>"></i>
                </span>
            </div>
            <?php endif; ?>

            <div class="notif-text">
                <div class="notif-msg"><?= $msg_display ?></div>
                <div class="notif-time"><i class="ri-time-line"></i><?= $ntime ?></div>
            </div>

        </<?= $outer_tag ?>>

        <?php endforeach; ?>
        <?php endforeach; endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<!-- ══ ACTORS MODAL ══ -->
<div class="notif-actors-overlay" id="actorsOverlay" onclick="closeActorsModal(event)">
    <div class="notif-actors-modal" onclick="event.stopPropagation()">
        <div class="notif-actors-header">
            <span id="actorsTitle">Activity</span>
            <button class="notif-actors-close" onclick="closeActorsModal()"><i class="ri-close-line"></i></button>
        </div>
        <div class="notif-actors-list" id="actorsList"></div>
    </div>
</div>

<script>
window.addEventListener('load', function(){
    const sk = document.getElementById('page-skeleton');
    if(!sk) return;
    setTimeout(function(){
        sk.classList.add('hide');
        setTimeout(function(){ sk.remove(); }, 380);
    }, 280);
});

function switchTab(key, btn) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.notif-panel').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    const panel = document.getElementById('panel-' + key);
    if (panel) panel.classList.add('active');
}

function markAsRead(notifId) {
    if (notifId > 0) {
        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'mark_notif_read=1&notif_id=' + notifId
        }).catch(() => {});
    }
}

function markAllRead() {
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'mark_all_read=1'
    }).then(r => r.json()).then(data => {
        if (data.ok) {
            location.reload();
        }
    }).catch(() => {});
}

function openActorsModal(actors, actionLabel) {
    const overlay = document.getElementById('actorsOverlay');
    const list    = document.getElementById('actorsList');
    const title   = document.getElementById('actorsTitle');
    if (!overlay || !list) return;

    const count = Array.isArray(actors) ? actors.length : 0;
    title.textContent = count + (count === 1 ? ' person ' : ' people ') + actionLabel;

    list.innerHTML = '';
    (actors || []).forEach(a => {
        const row  = document.createElement('a');
        row.className = 'notif-actor-row';
        row.href = 'profile.php?user_id=' + encodeURIComponent(a.actor_id);
        const img  = document.createElement('img');
        img.src  = a.profile_pic || 'uploads/me.jpeg';
        img.onerror = function () { this.src = 'uploads/me.jpeg'; };
        const span = document.createElement('span');
        span.textContent = a.username || 'Someone';
        row.appendChild(img);
        row.appendChild(span);
        list.appendChild(row);
    });

    overlay.classList.add('open');
}

function closeActorsModal(e) {
    if (e && e.target !== document.getElementById('actorsOverlay')) return;
    document.getElementById('actorsOverlay').classList.remove('open');
}
</script>

</body>
</html>