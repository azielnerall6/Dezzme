<?php
session_start();
include 'config.php';

$current_user = $_SESSION['user_id'] ?? 0;

if (!$current_user) { header("Location: login.php"); exit(); }

// Update last active
$conn->query("UPDATE users SET last_active = NOW() WHERE id = $current_user");

// ── TOGGLE FOLLOW (AJAX) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_follow') {
    header('Content-Type: application/json');
    if (!$current_user) { echo json_encode(['status' => 'error', 'message' => 'not logged in']); exit; }
    $target_id = intval($_POST['user_id']);
    $stmt = $conn->prepare("SELECT id FROM followers WHERE follower_id=? AND following_id=?");
    $stmt->bind_param("ii", $current_user, $target_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $d = $conn->prepare("DELETE FROM followers WHERE follower_id=? AND following_id=?");
        $d->bind_param("ii", $current_user, $target_id);
        $d->execute();
        echo json_encode(['status' => 'ok', 'following' => false]);
    } else {
        $ins = $conn->prepare("INSERT INTO followers (follower_id, following_id) VALUES (?,?)");
        $ins->bind_param("ii", $current_user, $target_id);
        $ins->execute();
        echo json_encode(['status' => 'ok', 'following' => true]);
    }
    exit;
}

// ── CROSSROADS QUERY ──
$users = [];

$blocked_ids = [0];
$bres = $conn->query("SELECT blocked_id FROM blocks WHERE blocker_id = $current_user UNION SELECT blocker_id FROM blocks WHERE blocked_id = $current_user");
while ($br = $bres->fetch_assoc()) $blocked_ids[] = (int)$br['blocked_id'];
$blocked_in = implode(',', $blocked_ids);

$cr_query = $conn->query("
    SELECT
        u.id,
        u.username,
        u.profile_pic,
        u.last_active,
        s.Bio,
        s.pronouns,
        s.user_type,
        s.is_fully_private,
        (SELECT COUNT(*) FROM memes m WHERE m.uploaded_by = u.id) AS meme_count,
        (SELECT COUNT(*) FROM followers f2 WHERE f2.follower_id = $current_user AND f2.following_id = u.id) AS is_following,

        (CASE WHEN EXISTS (
            SELECT 1 FROM followers f1
            JOIN followers f2b ON f1.following_id = f2b.following_id
               AND f1.follower_id = $current_user
               AND f2b.follower_id = u.id
        ) THEN 1 ELSE 0 END) AS sig_follow,

        (CASE WHEN EXISTS (
            SELECT 1 FROM group_members gm1
            JOIN group_members gm2 ON gm1.group_id = gm2.group_id
               AND gm1.user_id = $current_user
               AND gm2.user_id = u.id
        ) THEN 1 ELSE 0 END) AS sig_group,

        (CASE WHEN EXISTS (
            SELECT 1 FROM likes l1
            JOIN likes l2 ON l1.meme_id = l2.meme_id
               AND l1.user_id = $current_user
               AND l2.user_id = u.id
        ) THEN 1 ELSE 0 END) AS sig_like,

        (CASE WHEN EXISTS (
            SELECT 1 FROM reposts r1
            JOIN reposts r2 ON r1.meme_id = r2.meme_id
               AND r1.user_id = $current_user
               AND r2.user_id = u.id
        ) THEN 1 ELSE 0 END) AS sig_repost,

        (CASE WHEN EXISTS (
            SELECT 1 FROM favorites fv1
            JOIN favorites fv2 ON fv1.meme_id = fv2.meme_id
               AND fv1.user_id = $current_user
               AND fv2.user_id = u.id
        ) THEN 1 ELSE 0 END) AS sig_fav,

        (CASE WHEN EXISTS (
            SELECT 1 FROM pod_discussion_replies pr1
            JOIN pod_discussion_replies pr2 ON pr1.discussion_id = pr2.discussion_id
               AND pr1.user_id = $current_user
               AND pr2.user_id = u.id
        ) THEN 1 ELSE 0 END) AS sig_pod,

        (CASE WHEN EXISTS (
            SELECT 1 FROM collection_items ci1
            JOIN collections c1 ON ci1.collection_id = c1.id AND c1.user_id = $current_user
            JOIN collection_items ci2 ON ci1.meme_id = ci2.meme_id
            JOIN collections c2 ON ci2.collection_id = c2.id AND c2.user_id = u.id
        ) THEN 1 ELSE 0 END) AS sig_collection,

        (CASE WHEN EXISTS (
            SELECT 1 FROM profile_views pv1
            JOIN profile_views pv2 ON pv1.profile_id = pv2.profile_id
               AND pv1.viewer_id = $current_user
               AND pv2.viewer_id = u.id
        ) THEN 1 ELSE 0 END) AS sig_view

    FROM users u
    LEFT JOIN user_settings s ON s.user_id = u.id
    WHERE u.id != $current_user
      AND u.id NOT IN ($blocked_in)
      AND u.id NOT IN (
          SELECT following_id FROM followers WHERE follower_id = $current_user
      )
    HAVING (sig_follow + sig_group + sig_like + sig_repost + sig_fav + sig_pod + sig_collection + sig_view) >= 2
    ORDER BY (sig_follow + sig_group + sig_like + sig_repost + sig_fav + sig_pod + sig_collection + sig_view) DESC
    LIMIT 60
");

if ($cr_query) {
    while ($row = $cr_query->fetch_assoc()) {
        $users[] = $row;
    }
}

$total_count = count($users);

function shortNum($n) {
    if ($n >= 1000000) return round($n / 1000000, 1) . 'M';
    if ($n >= 1000)    return round($n / 1000, 1) . 'K';
    return $n;
}

function timeSince($datetime) {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff/60) . 'm ago';
    if ($diff < 86400)  return floor($diff/3600) . 'h ago';
    if ($diff < 604800) return floor($diff/86400) . 'd ago';
    return date('M j', strtotime($datetime));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Crossroads | Dezzme</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

* { font-family: 'Poppins', sans-serif; }

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
.sk-title  { width: 140px; height: 18px; border-radius: 6px; }

.sk-search {
    width: 100%;
    max-width: 480px;
    height: 38px;
    border-radius: 19px;
    margin: 4px 0 14px;
}

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
    padding: 16px 0;
    border-bottom: 1px solid var(--divider);
}
.sk-avatar { width: 46px; height: 46px; border-radius: 50%; flex-shrink: 0; }
.sk-info   { flex: 1; display: flex; flex-direction: column; gap: 7px; }
.sk-name   { width: 120px; height: 13px; border-radius: 5px; }
.sk-sub    { width: 90px;  height: 10px; border-radius: 5px; }
.sk-btn    { width: 80px;  height: 30px; border-radius: 20px; flex-shrink: 0; }

/* ── Top bar ── */
.topbar {
    width: 100%;
    max-width: 480px;
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 20px 16px 14px;
    position: sticky;
    top: 0;
    z-index: 100;
    background: linear-gradient(-45deg,#010218,#070924,#070924);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
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

.topbar-title-wrap {
    flex: 1;
    min-width: 0;
}

.topbar-title {
    font-size: 1rem;
    font-weight: 600;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: 8px;
}

.topbar-title i { color: var(--red); font-size: 1.1rem; }

.topbar-subtitle {
    font-size: 0.7rem;
    color: var(--muted);
    margin-top: 2px;
}

.home-btn {
    color: var(--muted);
    font-size: 1.2rem;
    text-decoration: none;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    transition: color 0.2s;
}
.home-btn:hover { color: var(--text); }

/* ── Search bar ── */
.search-wrap {
    width: 100%;
    max-width: 480px;
    padding: 4px 16px 14px;
    position: relative;
    display: flex;
    align-items: center;
}
.search-icon {
    position: absolute;
    left: 32px;
    color: var(--muted);
    font-size: 1rem;
    pointer-events: none;
}
.search-input {
    width: 100%;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 15px;
    padding: 10px 16px 10px 38px;
    font-family: 'Poppins', sans-serif;
    font-size: 0.82rem;
    color: var(--text);
    outline: none;
    transition: border-color 0.2s, background 0.2s;
}
.search-input::placeholder { color: var(--muted); }
.search-input:focus {
    border-color: var(--red);
    background: rgba(255,255,255,0.07);
}

/* ── Context note ── */
.context-note {
    width: 100%;
    max-width: 480px;
    font-size: 0.72rem;
    color: var(--muted);
    line-height: 1.55;
    padding: 0 16px 14px;
    display: flex;
    align-items: flex-start;
    gap: 8px;
}
.context-note i { color: var(--red); font-size: 0.9rem; flex-shrink: 0; margin-top: 1px; }

/* ── User list ── */
.user-list {
    width: 100%;
    max-width: 480px;
    display: flex;
    flex-direction: column;
    padding: 0 16px;
}

/* ── User row ── */
.user-row {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 0;
    border-bottom: 1px solid var(--divider);
    cursor: pointer;
    transition: background 0.15s;
    text-decoration: none;
    color: var(--text);
    -webkit-tap-highlight-color: transparent;
}
.user-row:last-child { border-bottom: none; }
.user-row:hover { background: rgba(255,255,255,0.03); margin: 0 -16px; padding-left: 16px; padding-right: 16px; }

.avatar-wrap {
    position: relative;
    flex-shrink: 0;
}

.user-avatar {
    width: 46px;
    height: 46px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid rgb(248, 24, 24);
    flex-shrink: 0;
    transition: border-color 0.2s, transform 0.2s;
    display: block;
}
.user-row:hover .user-avatar { border-color: var(--red); transform: scale(1.04); }

.status-dot {
    position: absolute;
    bottom: 1px;
    right: 1px;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    border: 2px solid #070924;
}
.status-dot.online { background: #22c55e; box-shadow: 0 0 6px rgba(34,197,94,0.7); }
.status-dot.recent { background: #f59e0b; }

.user-info {
    flex: 1;
    min-width: 0;
}
.user-info .uname {
    font-size: 0.88rem;
    font-weight: 600;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: 5px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.user-info .uname svg { flex-shrink: 0; }
.user-info .umeta {
    font-size: 0.72rem;
    color: var(--muted);
    margin-top: 2px;
    display: flex;
    align-items: center;
    gap: 5px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.user-info .umeta i { font-size: 0.78rem; color: var(--red); }
.user-info .ureasons {
    font-size: 0.66rem;
    color: rgba(255,255,255,0.3);
    margin-top: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* ── Track button ── */
.track-btn {
    padding: 7px 18px;
    border-radius: 20px;
    font-family: 'Poppins', sans-serif;
    font-size: 0.72rem;
    font-weight: 600;
    cursor: pointer;
    border: none;
    transition: all 0.2s;
    flex-shrink: 0;
    white-space: nowrap;
    display: flex;
    align-items: center;
    gap: 5px;
}
.track-btn.follow {
    background: var(--red);
    color: #fff;
}
.track-btn.follow:hover { background: #ff2e2e; }
.track-btn.following {
    background: rgba(255,255,255,0.08);
    color: var(--muted);
    border: 1px solid rgba(255,255,255,0.12);
}
.track-btn.following:hover {
    background: rgba(255,76,76,0.1);
    border-color: rgba(255,76,76,0.3);
    color: var(--red);
}

/* ── Empty state ── */
.empty-state {
    padding: 56px 0 32px;
    text-align: center;
    color: var(--muted);
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
}
.empty-state i { font-size: 2.1rem; opacity: 0.4; }
.empty-state span { font-size: 0.82rem; line-height: 1.6; max-width: 280px; }

.search-no-results { display: none; }

/* ── Toast ── */
.dz-toast {
    position: fixed;
    bottom: 30px;
    left: 50%;
    transform: translateX(-50%) translateY(8px);
    background: rgba(10,10,22,0.97);
    border: 1px solid rgba(255,76,76,0.3);
    color: #fff;
    padding: 9px 24px;
    border-radius: 50px;
    font-size: 12px;
    font-family: 'Poppins', sans-serif;
    z-index: 9999;
    pointer-events: none;
    box-shadow: 0 8px 24px rgba(0,0,0,0.6);
    white-space: nowrap;
    opacity: 0;
    transition: opacity 0.22s ease, transform 0.22s ease;
}
.dz-toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }

/* ══════════════════════════════
   RESPONSIVE — 780 / 490 / 380 / 330
══════════════════════════════ */
@media (max-width: 780px) {
    .topbar { padding: 18px 14px 12px; }
    .topbar-title { font-size: 0.95rem; }
    .topbar-subtitle { font-size: 0.66rem; }
    .back-btn { font-size: 1.3rem; }
    .home-btn { font-size: 1.1rem; }
    .search-wrap { padding: 4px 14px 12px; }
    .search-input { font-size: 0.78rem; padding: 9px 14px 9px 36px; }
    .search-icon { left: 30px; font-size: 0.95rem; }
    .context-note { font-size: 0.68rem; padding: 0 14px 12px; }
    .user-list { padding: 0 14px; }
    .user-row { padding: 13px 0; gap: 12px; }
    .user-avatar { width: 42px; height: 42px; }
    .user-info .uname { font-size: 0.84rem; }
    .user-info .umeta { font-size: 0.68rem; }
    .track-btn { padding: 6px 14px; font-size: 0.68rem; }
    .sk-list { padding: 0 14px; }
    #page-skeleton { padding: 0 14px 60px; }
}

@media (max-width: 490px) {
    .topbar { padding: 16px 12px 10px; gap: 11px; }
    .topbar-title { font-size: 0.88rem; }
    .topbar-subtitle { font-size: 0.62rem; }
    .back-btn { font-size: 1.2rem; }
    .home-btn { font-size: 1.05rem; }
    .search-wrap { padding: 4px 12px 10px; }
    .search-input { font-size: 0.74rem; padding: 8px 12px 8px 34px; }
    .search-icon { left: 28px; font-size: 0.9rem; }
    .context-note { font-size: 0.64rem; padding: 0 12px 10px; }
    .user-list { padding: 0 12px; }
    .user-row { padding: 12px 0; gap: 11px; }
    .user-avatar { width: 40px; height: 40px; border-width: 1.5px; }
    .user-info .uname { font-size: 0.8rem; gap: 4px; }
    .user-info .umeta { font-size: 0.65rem; }
    .user-info .ureasons { font-size: 0.6rem; }
    .track-btn { padding: 6px 12px; font-size: 0.65rem; }
    .sk-topbar { padding: 16px 0 12px; }
    .sk-list { padding: 0 12px; }
    #page-skeleton { padding: 0 12px 60px; }
}

@media (max-width: 380px) {
    .topbar { padding: 14px 10px 9px; gap: 9px; }
    .topbar-title { font-size: 0.82rem; }
    .topbar-subtitle { font-size: 0.58rem; }
    .back-btn { font-size: 1.1rem; }
    .home-btn { font-size: 1rem; }
    .search-wrap { padding: 4px 10px 9px; }
    .search-input { font-size: 0.7rem; padding: 7px 10px 7px 32px; }
    .search-icon { left: 26px; font-size: 0.85rem; }
    .context-note { font-size: 0.6rem; padding: 0 10px 9px; }
    .user-list { padding: 0 10px; }
    .user-row { padding: 11px 0; gap: 10px; }
    .user-avatar { width: 36px; height: 36px; }
    .user-info .uname { font-size: 0.75rem; }
    .user-info .umeta { font-size: 0.62rem; }
    .track-btn { padding: 5px 10px; font-size: 0.62rem; border-radius: 16px; }
    #page-skeleton { padding: 0 10px 60px; }
}

@media (max-width: 330px) {
    .topbar { padding: 12px 8px 8px; gap: 8px; }
    .topbar-title { font-size: 0.76rem; }
    .topbar-subtitle { font-size: 0.54rem; }
    .back-btn { font-size: 1rem; }
    .home-btn { font-size: 0.95rem; }
    .search-wrap { padding: 4px 8px 8px; }
    .search-input { font-size: 0.68rem; padding: 6px 9px 6px 30px; }
    .search-icon { left: 24px; font-size: 0.8rem; }
    .context-note { font-size: 0.57rem; padding: 0 8px 8px; }
    .user-list { padding: 0 8px; }
    .user-row { padding: 10px 0; gap: 9px; }
    .user-avatar { width: 34px; height: 34px; }
    .user-info .uname { font-size: 0.72rem; }
    .user-info .umeta { font-size: 0.58rem; }
    .track-btn { padding: 4px 9px; font-size: 0.58rem; }
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
    <div class="sk sk-search"></div>
    <div class="sk-list">
        <?php for($i=0;$i<7;$i++): ?>
        <div class="sk-row">
            <div class="sk sk-avatar"></div>
            <div class="sk-info">
                <div class="sk sk-name"></div>
                <div class="sk sk-sub"></div>
            </div>
            <div class="sk sk-btn"></div>
        </div>
        <?php endfor; ?>
    </div>
</div>

<!-- ══ TOPBAR ══ -->
<div class="topbar">
    <button class="back-btn" onclick="history.back()" aria-label="Go back">
        <i class="ri-arrow-left-s-line"></i>
    </button>
    <div class="topbar-title-wrap">
        <div class="topbar-title"><i class="ri-crosshair-2-line"></i> Crossroads</div>
        <div class="topbar-subtitle">People you keep crossing paths with</div>
    </div>
</div>

<?php if (!empty($users)): ?>

<!-- ══ SEARCH ══ -->
<div class="search-wrap">
    <i class="ri-search-line search-icon"></i>
    <input type="text" id="user-search" class="search-input" placeholder="Search by username..." oninput="filterUsers()" autocomplete="off">
</div>

<p class="context-note"><i class="ri-information-line"></i> <?php echo $total_count; ?> people you overlap with but haven't connected to yet — based on shared creators, communities, likes, and more.</p>

<!-- ══ USER LIST ══ -->
<div class="user-list" id="user-list">
<?php foreach($users as $u):
    $uid    = (int)$u['id'];
    $uname  = htmlspecialchars($u['username']);
    $pic    = !empty($u['profile_pic']) ? htmlspecialchars($u['profile_pic']) : 'uploads/me.jpeg';
    $follow = (int)$u['is_following'] > 0;
    $memcnt = shortNum($u['meme_count'] ?? 0);
    $last   = $u['last_active'] ?? '';

    $diff      = time() - strtotime($last);
    $is_online = $diff <= 300;
    $is_recent = !$is_online && $diff <= 3600;

    $cr_score = (int)$u['sig_follow'] + (int)$u['sig_group'] + (int)$u['sig_like']
              + (int)$u['sig_repost'] + (int)$u['sig_fav']   + (int)$u['sig_pod']
              + (int)$u['sig_collection'] + (int)$u['sig_view'];

    $cr_overlaps = [];
    if ($u['sig_follow'])     $cr_overlaps[] = 'same creators';
    if ($u['sig_group'])      $cr_overlaps[] = 'same communities';
    if ($u['sig_like'])       $cr_overlaps[] = 'same content';
    if ($u['sig_repost'])     $cr_overlaps[] = 'same reposts';
    if ($u['sig_fav'])        $cr_overlaps[] = 'same saves';
    if ($u['sig_pod'])        $cr_overlaps[] = 'same discussions';
    if ($u['sig_collection']) $cr_overlaps[] = 'similar collections';
    if ($u['sig_view'])       $cr_overlaps[] = 'same profiles viewed';
    $reasons_str = implode(', ', array_slice($cr_overlaps, 0, 3));
    if(count($cr_overlaps) > 3) $reasons_str .= ' +' . (count($cr_overlaps)-3) . ' more';
?>
    <div class="user-row" data-name="<?php echo strtolower($uname); ?>" onclick="location.href='profile.php?user_id=<?php echo $uid; ?>'">
        <div class="avatar-wrap">
            <img class="user-avatar" src="<?php echo $pic; ?>" onerror="this.src='uploads/me.jpeg'" alt="<?php echo $uname; ?>">
            <?php if($is_online): ?><div class="status-dot online"></div>
            <?php elseif($is_recent): ?><div class="status-dot recent"></div>
            <?php endif; ?>
        </div>
        <div class="user-info">
            <div class="uname">
                <svg xmlns="http://www.w3.org/2000/svg" width="9" height="9" viewBox="0 0 24 24"><circle cx="12" cy="12" r="12" fill="#FF4C4C"/><path d="M9 12l2 2 4-4" stroke="white" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <?php echo $uname; ?>
            </div>
            <div class="umeta"><i class="ri-arrow-left-right-line"></i> <?php echo $cr_score; ?> paths crossed · <?php echo $memcnt; ?> posts</div>
            <?php if($reasons_str): ?>
            <div class="ureasons"><?php echo htmlspecialchars($reasons_str); ?></div>
            <?php endif; ?>
        </div>
        <button class="track-btn <?php echo $follow ? 'following' : 'follow'; ?>"
            onclick="event.stopPropagation(); handleTrack(this, <?php echo $uid; ?>)">
            <i class="<?php echo $follow ? 'ri-user-follow-line' : 'ri-user-add-line'; ?>"></i>
            <?php echo $follow ? 'Tracking' : 'Track'; ?>
        </button>
    </div>
<?php endforeach; ?>
    <div class="empty-state search-no-results">
        <i class="ri-search-line"></i>
        <span>No users found</span>
    </div>
</div>

<?php else: ?>

<!-- ══ EMPTY STATE ══ -->
<div class="user-list">
    <div class="empty-state">
        <i class="ri-crosshair-2-line"></i>
        <span>No crossroads yet. You're already tracking everyone who shares your spaces, or you haven't overlapped enough with anyone yet.</span>
    </div>
</div>

<?php endif; ?>

<!-- ══ TOAST ══ -->
<div class="dz-toast" id="dzToast"></div>

<script>
// ── Remove skeleton ──────────────────────────────────────
window.addEventListener('load', function(){
    const sk = document.getElementById('page-skeleton');
    if(!sk) return;
    setTimeout(function(){
        sk.classList.add('hide');
        setTimeout(function(){ sk.remove(); }, 380);
    }, 280);
});

// ── Search / filter users ────────────────────────────────
function filterUsers(){
    const searchEl = document.getElementById('user-search');
    if(!searchEl) return;
    const query = searchEl.value.trim().toLowerCase();
    const list = document.getElementById('user-list');
    if(!list) return;

    const rows = list.querySelectorAll('.user-row');
    let visibleCount = 0;

    rows.forEach(function(row){
        const name = row.dataset.name || '';
        const match = name.includes(query);
        row.style.display = match ? '' : 'none';
        if(match) visibleCount++;
    });

    const noResults = list.querySelector('.search-no-results');
    if(noResults){
        noResults.style.display = (query.length > 0 && visibleCount === 0 && rows.length > 0) ? 'flex' : 'none';
    }
}

// ── Track / Untrack AJAX ─────────────────────────────────
function handleTrack(btn, uid){
    const isTracking = btn.classList.contains('following');
    btn.disabled = true;
    btn.style.opacity = '0.5';

    fetch('discover.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=toggle_follow&user_id=' + uid
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.style.opacity = '1';
        if(data.status !== 'ok'){ showToast('Please log in to track'); return; }
        const nowFollowing = data.following;
        if(nowFollowing){
            btn.classList.remove('follow');
            btn.classList.add('following');
            btn.innerHTML = '<i class="ri-user-follow-line"></i> Tracking';
            showToast('Now tracking');
        } else {
            btn.classList.remove('following');
            btn.classList.add('follow');
            btn.innerHTML = '<i class="ri-user-add-line"></i> Track';
            showToast('Untracked');
        }
    })
    .catch(function(){
        btn.disabled = false;
        btn.style.opacity = '1';
        showToast('Something went wrong');
    });
}

// ── Toast ─────────────────────────────────────────────────
function showToast(msg){
    const t = document.getElementById('dzToast');
    if(!t) return;
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(function(){ t.classList.remove('show'); }, 2200);
}

// ── ESC clears search ────────────────────────────────────
const si = document.getElementById('user-search');
if(si) si.addEventListener('keydown', function(e){
    if(e.key === 'Escape') { this.value = ''; filterUsers(); }
});
</script>
</body>
</html>