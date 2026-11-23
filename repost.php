<?php
session_start();
include 'config.php';

$current_user = $_SESSION['user_id'] ?? 0;
if(!$current_user){
    header("Location: login.php");
    exit();
}

/* ── CCM DATA (ads + suggested users for modal) ── */
if (isset($_GET['type'])) {
    $type = $_GET['type'];
    header('Content-Type: application/json');
    if ($type === 'ads') {
        $ads = [];
        $res = $conn->query("SELECT * FROM sponsorships WHERE status='approved' ORDER BY RAND() LIMIT 10");
        while ($row = $res->fetch_assoc()) { $ads[] = $row; }
        echo json_encode($ads);
    } elseif ($type === 'sugg') {
        $sugg = [];
        $res = $conn->query("
            SELECT u.id, u.username, u.profile_pic,
            (SELECT COUNT(*) FROM followers f WHERE f.follower_id=$current_user AND f.following_id=u.id) as is_following
            FROM users u WHERE u.id != $current_user ORDER BY RAND() LIMIT 20
        ");
        while ($row = $res->fetch_assoc()) { $sugg[] = $row; }
        echo json_encode($sugg);
    } else { echo json_encode([]); }
    exit;
}

/* ── TOGGLE FOLLOW ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_follow') {
    header('Content-Type: application/json');
    if (!$current_user) { echo json_encode(['status'=>'error']); exit; }
    $target_id = intval($_POST['user_id']);
    $stmt = $conn->prepare("SELECT id FROM followers WHERE follower_id=? AND following_id=?");
    $stmt->bind_param("ii", $current_user, $target_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $stmt = $conn->prepare("DELETE FROM followers WHERE follower_id=? AND following_id=?");
        $stmt->bind_param("ii", $current_user, $target_id);
        $stmt->execute();
        echo json_encode(['status'=>'ok','following'=>false]);
    } else {
        $stmt = $conn->prepare("INSERT INTO followers (follower_id, following_id) VALUES (?,?)");
        $stmt->bind_param("ii", $current_user, $target_id);
        $stmt->execute();
        echo json_encode(['status'=>'ok','following'=>true]);
    }
    exit;
}

$profile_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : $current_user;
$user = $conn->query("SELECT username, profile_pic FROM users WHERE id=$profile_id")->fetch_assoc();

// ── Follow state for this profile ──
$is_own_profile = ($current_user == $profile_id);
$is_following_profile = false;
if (!$is_own_profile) {
    $fchk = $conn->query("SELECT id FROM followers WHERE follower_id=$current_user AND following_id=$profile_id");
    $is_following_profile = $fchk->num_rows > 0;
}

$search = $_GET['search'] ?? '';

$query = "
SELECT m.*, u.username AS creator_name, u.profile_pic AS creator_pic,
(SELECT COUNT(*) FROM likes l WHERE l.meme_id = m.id) AS like_count,
(SELECT COUNT(*) FROM reposts r2 WHERE r2.meme_id = m.id) AS repost_count,
(SELECT COUNT(*) FROM favorites f WHERE f.meme_id = m.id) AS favorite_count
FROM reposts r
JOIN memes m ON r.meme_id = m.id
JOIN users u ON m.uploaded_by = u.id
WHERE r.user_id = $profile_id
AND (
    m.is_adult = 0
    OR $current_user = $profile_id
    OR EXISTS (
        SELECT 1 FROM group_posts gp
        JOIN group_members gm ON gm.group_id = gp.group_id
        WHERE gp.meme_id = m.id AND gm.user_id = $current_user
    )
)
";

if($search){
    $search_safe = $conn->real_escape_string($search);
    $query .= " AND m.caption LIKE '%$search_safe%'";
}
$query .= " ORDER BY r.id DESC";
$reposts = $conn->query($query);

$reposts_count = $conn->query("SELECT COUNT(*) as cnt FROM reposts WHERE user_id=$profile_id")->fetch_assoc()['cnt'];

/* ── Pre-fetch all repost rows into an array (so we can query it multiple times) ── */
$_repost_rows = [];
$_meme_ids = [];
while($rr = $reposts->fetch_assoc()){ $_repost_rows[] = $rr; $_meme_ids[] = $rr['id']; }

/* ── Nickname map ── */
$_nick_map = [];
if(!empty($_repost_rows)){
    $uid_list = implode(',', array_unique(array_map(function($r){ return (int)$r['uploaded_by']; }, $_repost_rows)));
    if($uid_list){
        $nres = $conn->query("SELECT id, nickname FROM users WHERE id IN ($uid_list)");
        while($nr = $nres->fetch_assoc()) $_nick_map[(int)$nr['id']] = $nr['nickname'] ?? '';
    }
}

/* ── Multi-media map (meme_media table) ── */
$_media_map = [];
if(!empty($_meme_ids)){
    $ids_str = implode(',', array_map('intval', $_meme_ids));
    $mm_res = $conn->query("SELECT meme_id, file_name, type, position FROM meme_media WHERE meme_id IN ($ids_str) ORDER BY meme_id, position ASC");
    if($mm_res){
        while($mm = $mm_res->fetch_assoc()){
            $mid = (int)$mm['meme_id'];
            if(!isset($_media_map[$mid])) $_media_map[$mid] = [];
            $_media_map[$mid][] = ['file'=>'uploads/'.$mm['file_name'], 'type'=>$mm['type']];
        }
    }
}

/* ── Social proof map (likes/reposts from people you follow) ── */
$_sp_map = [];
if($current_user && !empty($_meme_ids)){
    $ids_str = implode(',', array_map('intval', $_meme_ids));
    $sp_res = $conn->query("
        SELECT l.meme_id, u.id, u.username, u.profile_pic, 'like' AS action_type
        FROM likes l JOIN users u ON u.id=l.user_id
        JOIN followers f ON f.following_id=u.id
        WHERE l.meme_id IN ($ids_str) AND f.follower_id=$current_user AND u.id!=$current_user
        UNION ALL
        SELECT r2.meme_id, u.id, u.username, u.profile_pic, 'repost' AS action_type
        FROM reposts r2 JOIN users u ON u.id=r2.user_id
        JOIN followers f ON f.following_id=u.id
        WHERE r2.meme_id IN ($ids_str) AND f.follower_id=$current_user AND u.id!=$current_user
    ");
    if($sp_res){
        while($sp = $sp_res->fetch_assoc()){
            $mid = $sp['meme_id'];
            if(!isset($_sp_map[$mid])) $_sp_map[$mid] = [];
            if(count($_sp_map[$mid]) < 4) $_sp_map[$mid][] = $sp;
        }
    }
}

if(isset($_POST['action'])){
    $meme_id = intval($_POST['meme_id']);
    $action = $_POST['action'];

    if($action == 'like'){
        $check = $conn->query("SELECT * FROM likes WHERE user_id=$current_user AND meme_id=$meme_id");
        if($check->num_rows){
            $conn->query("DELETE FROM likes WHERE user_id=$current_user AND meme_id=$meme_id");
            $liked = false;
        } else {
            $conn->query("INSERT INTO likes (user_id,meme_id) VALUES ($current_user,$meme_id)");
            $liked = true;
        }
        $count = $conn->query("SELECT COUNT(*) as cnt FROM likes WHERE meme_id=$meme_id")->fetch_assoc()['cnt'];
        echo json_encode(['liked'=>$liked,'count'=>$count]);
        exit();
    }

    if($action == 'repost'){
        $check = $conn->query("SELECT * FROM reposts WHERE user_id=$current_user AND meme_id=$meme_id");
        if($check->num_rows){
            $conn->query("DELETE FROM reposts WHERE user_id=$current_user AND meme_id=$meme_id");
            $reposted = false;
        } else {
            $conn->query("INSERT INTO reposts (user_id,meme_id) VALUES ($current_user,$meme_id)");
            $reposted = true;
        }
        $count = $conn->query("SELECT COUNT(*) as cnt FROM reposts WHERE meme_id=$meme_id")->fetch_assoc()['cnt'];
        echo json_encode(['reposted'=>$reposted,'count'=>$count]);
        exit();
    }

    if($action == 'favorite'){
        $check = $conn->query("SELECT * FROM favorites WHERE user_id=$current_user AND meme_id=$meme_id");
        if($check->num_rows){
            $conn->query("DELETE FROM favorites WHERE user_id=$current_user AND meme_id=$meme_id");
            $favorited = false;
        } else {
            $conn->query("INSERT INTO favorites (user_id,meme_id) VALUES ($current_user,$meme_id)");
            $favorited = true;
        }
        $count = $conn->query("SELECT COUNT(*) as cnt FROM favorites WHERE meme_id=$meme_id")->fetch_assoc()['cnt'];
        echo json_encode(['favorited'=>$favorited,'count'=>$count]);
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($user['username']); ?>'s Reposts | Dezzme</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<style>
* { margin:0; padding:0; box-sizing:border-box; font-family:'Poppins',sans-serif; }

:root {
    --red:       #FF2D2D;
    --red-dim:   rgba(255,45,45,0.18);
    --red-glow:  rgba(255,45,45,0.35);
    --ink:       #06071A;
    --ink-2:     #0C0E28;
    --surface:   rgba(255,255,255,0.04);
    --surface-2: rgba(255,255,255,0.07);
    --border:    rgba(255,255,255,0.07);
    --border-2:  rgba(255,255,255,0.13);
    --text:      #F0F2FF;
    --text-2:    rgba(220,224,255,0.65);
    --text-3:    rgba(180,188,255,0.4);
    --radius-md: 16px;
    --radius-lg: 22px;
    --radius-xl: 30px;
}

html { scroll-behavior: smooth; }

body {
    background: linear-gradient(-45deg,#010218,#070924,#070924);
    background-attachment: fixed;
    color: var(--text);
    min-height: 100vh;
    overflow-x: hidden;
}

canvas {
    position: fixed;
    top: 0; left: 0;
    width: 100%; height: 100%;
    z-index: -1;
    pointer-events: none;
}

.back-btn {
    position: fixed;
    top: 20px; left: 20px;
    z-index: 200;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 0; border-radius: 0;
    background: none; border: none;
    backdrop-filter: none; -webkit-backdrop-filter: none;
    color: var(--text);
    font-size: 13px; font-weight: 600;
    cursor: pointer; text-decoration: none;
    box-shadow: none;
    transition: opacity 0.2s ease, transform 0.2s ease;
}
.back-btn i { font-size: 16px; }

.page-header {
    text-align: center;
    padding: 100px 20px 30px;
}

.header-avatar {
    width: 110px; height: 110px;
    border-radius: 50%;
    border: 3px solid var(--red);
    object-fit: cover;
    box-shadow: 0 0 30px var(--red-glow), 0 10px 40px rgba(0,0,0,0.5);
    margin-bottom: 16px;
    transition: transform 0.4s ease, box-shadow 0.4s ease;
    cursor: pointer;
}
.header-avatar:hover { transform: scale(1.07); box-shadow: 0 0 45px var(--red-glow); }

.page-header h1 {
    font-size: clamp(1.4rem, 4vw, 2rem);
    font-weight: 700; color: #fff;
    display: flex; align-items: center; justify-content: center;
    gap: 10px; margin-bottom: 6px;
}
.page-header h1 i { color: var(--red); font-size: 1.2em; }
.upload-count { font-size: 13px; color: var(--text-3); font-weight: 400; letter-spacing: 0.5px; }
.header-divider {
    width: 180px; height: 2px;
    margin: 14px auto 0;
    background: linear-gradient(90deg, transparent, var(--red), transparent);
    border-radius: 2px;
}

.search-wrap { display: flex; justify-content: center; padding: 24px 20px 10px; }
.search-inner { position: relative; width: 100%; max-width: 560px; }
.search-inner input {
    width: 100%;
    padding: 13px 50px 13px 20px;
    border-radius: var(--radius-xl);
    border: 1px solid var(--border-2);
    background: var(--surface-2);
    backdrop-filter: blur(14px);
    color: var(--text); font-size: 14px;
    font-family: 'Poppins', sans-serif;
    outline: none; transition: all 0.3s ease;
}
.search-inner input::placeholder { color: var(--text-3); }
.search-inner input:focus {
    border-color: var(--red-glow);
    background: rgba(255,45,45,0.06);
    box-shadow: 0 0 0 3px var(--red-dim);
}
.search-inner button {
    position: absolute; right: 14px; top: 50%;
    transform: translateY(-50%);
    background: none; border: none;
    color: var(--text-3); font-size: 18px;
    cursor: pointer; transition: color 0.2s;
}
.search-inner button:hover { color: var(--red); }

@media (max-width:768px){ .search-inner input{ padding:10px 46px 10px 16px; font-size:13px; border-radius:18px; } .search-inner button{ font-size:16px; right:12px; } }
@media (max-width:480px){ .search-inner input{ padding:8px 42px 8px 14px; font-size:12px; border-radius:16px; } .search-inner button{ font-size:15px; right:10px; } }
@media (max-width:360px){ .search-inner input{ padding:7px 38px 7px 12px; font-size:11px; border-radius:14px; } .search-inner button{ font-size:14px; right:9px; } }

.grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 20px;
    padding: 20px 30px 60px;
    max-width: 1300px;
    margin: 0 auto;
}

.card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    overflow: hidden;
    position: relative;
    transition: transform 0.35s cubic-bezier(0.34,1.56,0.64,1), box-shadow 0.35s ease, border-color 0.3s;
}
.card:hover { transform: translateY(-6px) scale(1.01); }

.media-box { position: relative; width: 100%; overflow: hidden; }
.media-box img,
.media-box video {
    width: 100%; height: 220px;
    object-fit: cover; display: block;
    transition: transform 0.4s ease; cursor: pointer;
}
.card:hover .media-box img,
.card:hover .media-box video { transform: scale(1.03); }

.top-bar-overlay {
    position: absolute; top: 12px; left: 12px; z-index: 5;
    display: flex; align-items: center; gap: 8px;
    padding: 0; border-radius: 0;
    background: none; backdrop-filter: none; -webkit-backdrop-filter: none;
}
.top-bar-overlay img {
    width: 28px; height: 28px;
    border-radius: 50%; object-fit: cover; cursor: pointer;
    border: 2px solid var(--red);
    box-shadow: 0 0 10px rgba(255,45,45,0.25);
}
.top-bar-overlay .uname {
    color: white; font-size: 13px; font-weight: 600;
    cursor: pointer; text-shadow: 0 2px 8px rgba(0,0,0,0.55);
}

.new-badge {
    position: absolute; top: 190px; left: 10px;
    padding: 3px 9px; border-radius: 8px;
    font-size: 9px; font-weight: 700; letter-spacing: 1px;
    color: #fff;
    background: rgba(255,45,45,0.18);
    border: 1px solid rgba(255,45,45,0.35);
    backdrop-filter: blur(10px); z-index: 5;
}

.caption {
    padding: 10px 14px 8px; font-size: 12px;
    color: var(--text-2); line-height: 1.4;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}

.actions {
    display: flex; align-items: center;
    gap: 4px; padding: 0 10px 12px; flex-wrap: wrap;
}
.actions button {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 6px 11px; border-radius: 20px;
    background: rgba(120,90,255,0.14);
    border: 1px solid rgba(170,150,255,0.10);
    color: var(--text-2); font-size: 12px;
    font-family: 'Poppins', sans-serif;
    cursor: pointer; transition: all 0.25s ease;
    white-space: nowrap;
    backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
}
.actions button:hover { background: rgba(120,90,255,0.18); border-color: transparent; color: #fff; transform: translateY(-1px); }
.actions button:hover i { color: var(--red); }
.actions button.active { background: rgba(120,90,255,0.22); border-color: transparent; color: #d9d2ff; }
.actions button.active i { color: var(--red); }
.actions button i { font-size: 14px; }

.empty-state { grid-column: 1/-1; text-align: center; padding: 60px 20px; color: var(--text-3); }
.empty-state i { font-size: 3rem; display: block; margin-bottom: 12px; color: var(--red-dim); }


@media (max-width: 768px) {
    .back-btn { top:14px; left:14px; font-size:0; gap:0; }
    .back-btn i { font-size:20px; }
    .page-header { padding:80px 16px 20px; }
    .header-avatar { width:86px; height:86px; }
    .page-header h1 { font-size:1.2rem; }
    .search-wrap { padding:16px 16px 6px; }
    .grid { grid-template-columns:repeat(auto-fill,minmax(155px,1fr)); gap:12px; padding:14px 14px 80px; }
    .media-box img, .media-box video { height:155px; }
    .actions { gap:3px; padding:0 8px 10px; }
    .actions button { padding:5px 9px; font-size:11px; }
    .feed-wrapper { transform:translateY(-65px); }
    .top-bar-overlay { top:12px; left:12px; gap:8px; }
    .top-bar-overlay img { width:30px; height:30px; }
    .top-bar-overlay .uname { font-size:14px; }
}
@media (max-width: 480px) {
    .back-btn { top:12px; left:12px; }
    .page-header { padding:70px 12px 16px; }
    .header-avatar { width:74px; height:74px; }
    .page-header h1 { font-size:1.05rem; gap:7px; }
    .grid { grid-template-columns:repeat(2,1fr); gap:10px; padding:12px 12px 80px; }
    .media-box img, .media-box video { height:135px; }
    .caption { font-size:11px; padding:8px 10px 6px; }
    .actions button { padding:4px 8px; font-size:10.5px; }
    .actions button i { font-size:13px; }
    .feed-wrapper { transform:translateY(-50px); }
    .top-bar-overlay { top:10px; left:10px; gap:7px; }
    .top-bar-overlay img { width:27px; height:27px; border-width:2px; }
    .top-bar-overlay .uname { font-size:12px; }
}
@media (max-width: 360px) {
    .grid { gap:8px; padding:10px 10px 80px; }
    .media-box img, .media-box video { height:118px; }
    .page-header h1 { font-size:0.95rem; }
    .feed-wrapper { transform:translateY(-55px); }
    .top-bar-overlay { top:9px; left:9px; gap:6px; }
    .top-bar-overlay img { width:24px; height:24px; }
    .top-bar-overlay .uname { font-size:11px; }
}

.top-bar-overlay {
    max-width: calc(100% - 24px);
}
.top-bar-overlay .uname {
    overflow: hidden;
    text-overflow: ellipsis;
    flex-shrink: 1;
    min-width: 0;
}
.grid-track-btn {
    margin-left: auto;
    padding: 3px 10px;
    border-radius: 14px;
    border: none;
    font-size: 10px;
    font-weight: 600;
    background: rgba(255, 43, 43, 0.55);
    color: white;
    cursor: pointer;
    transition: 0.25s;
    font-family: 'Poppins', sans-serif;
    flex-shrink: 0;
    -webkit-tap-highlight-color: transparent;
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
}
.grid-track-btn.following {
    background: rgba(0,0,0,0.35);
    border: 1.5px solid rgba(255,43,43,0.6);
    color: #ff5252;
}
.grid-track-btn:hover { background:#ff2b2b; color:white; transform:scale(1.05); }
.grid-track-btn:active { transform:scale(0.95); }
</style>
</head>
<body>

<a href="profile.php?user_id=<?php echo $profile_id; ?>" class="back-btn">
    <i class="ri-arrow-left-s-line"></i> Profile
</a>

<div class="feed-wrapper">
<div class="page-header">
    <img src="<?php echo htmlspecialchars($user['profile_pic'] ?: 'uploads/default.jpg'); ?>"
         class="header-avatar"
         onclick="window.location.href='profile.php?user_id=<?php echo $profile_id; ?>'">
    <h1>
        <i class="ri-repeat-line"></i>
        <?php echo ($current_user == $profile_id) ? "Your" : htmlspecialchars($user['username'])."'s"; ?> Reposts
    </h1>
    <div class="upload-count"><?php echo $reposts_count; ?> repost<?php echo $reposts_count != 1 ? 's' : ''; ?></div>
</div>

<form class="search-wrap" method="GET">
    <div class="search-inner">
        <input type="text" name="search" placeholder="Search reposts..." value="<?php echo htmlspecialchars($search); ?>">
        <input type="hidden" name="user_id" value="<?php echo $profile_id; ?>">
        <button type="submit"><i class="ri-search-line"></i></button>
    </div>
</form>

<div class="grid">
<?php if(empty($_repost_rows)): ?>
    <div class="empty-state">
        <i class="ri-repeat-line"></i>
        No reposts found<?php echo $search ? " for \"".htmlspecialchars($search)."\"" : ""; ?>
    </div>
<?php else: ?>
<script>var peuraFeed = [];</script>
<?php foreach($_repost_rows as $r):
    $created  = strtotime($r['created_at'] ?? 'now');
    $diff     = time() - $created;
    $is_new   = $diff <= 86400;
    $modal_file = ($r['type'] === 'text' || empty($r['file_name']) || $r['file_name'] === '0') ? 'uploads/Text.jpg' : 'uploads/'.$r['file_name'];
    $sc = addslashes(htmlspecialchars($r['caption']));
    $su = addslashes(htmlspecialchars($r['creator_name']));
    $sp = addslashes($r['creator_pic'] ?: 'uploads/default.jpg');
    $sf = addslashes($r['file_name']);
    $sm = addslashes($modal_file);
    $st = addslashes($r['created_at'] ?? '');
    $stype = addslashes($r['type']);
    $s_nick = addslashes($_nick_map[(int)$r['uploaded_by']] ?? '');

    $liked     = $conn->query("SELECT id FROM likes WHERE user_id=$current_user AND meme_id={$r['id']}")->num_rows > 0;
    $favorited = $conn->query("SELECT id FROM favorites WHERE user_id=$current_user AND meme_id={$r['id']}")->num_rows > 0;
    $reposted  = $conn->query("SELECT id FROM reposts WHERE user_id=$current_user AND meme_id={$r['id']}")->num_rows > 0;

    $card_creator_id = (int)$r['uploaded_by'];
    $is_card_own = ($current_user == $card_creator_id);
    $is_card_following = false;
    if (!$is_card_own) {
        $cfchk = $conn->query("SELECT id FROM followers WHERE follower_id=$current_user AND following_id=$card_creator_id");
        $is_card_following = $cfchk->num_rows > 0;
    }

    /* ── media items: primary file first, then any extras from meme_media ── */
    $media_items = [['file'=>$sm, 'type'=>$stype]];
    if(!empty($_media_map[$r['id']])) {
        $media_items = array_merge($media_items, $_media_map[$r['id']]);
    }

    /* ── social proof for this meme ── */
    $sp_json = json_encode(array_map(function($sp){
        return [
            'id'          => (int)$sp['id'],
            'username'    => $sp['username'],
            'profile_pic' => $sp['profile_pic'],
            'action_type' => $sp['action_type'],
        ];
    }, $_sp_map[$r['id']] ?? []));

    $modal_args = "'{$sm}','{$stype}','{$sp}','{$card_creator_id}','{$su}','{$sc}'";
?>
<script>
peuraFeed.push({
    id:          <?= (int)$r['id'] ?>,
    file:        '<?= $sm ?>',
    media:       <?= json_encode($media_items) ?>,
    type:        '<?= $stype ?>',
    pic:         '<?= $sp ?>',
    userId:      <?= $card_creator_id ?>,
    username:    '<?= $su ?>',
    nickname:    '<?= $s_nick ?>',
    caption:     '<?= $sc ?>',
    time:        '<?= $st ?>',
    likeCount:   <?= (int)$r['like_count'] ?>,
    repostCount: <?= (int)$r['repost_count'] ?>,
    favCount:    <?= (int)$r['favorite_count'] ?>,
    liked:       <?= $liked    ? 'true' : 'false' ?>,
    reposted:    <?= $reposted ? 'true' : 'false' ?>,
    faved:       <?= $favorited ? 'true' : 'false' ?>,
    isOwn:       <?= $is_card_own ? 'true' : 'false' ?>,
    isFollowing: <?= $is_card_following ? 'true' : 'false' ?>,
    socialProof: <?= $sp_json ?>
});
</script>
<div class="card">
    <div class="media-box">
        <div class="top-bar-overlay">
    <img src="<?= $r['creator_pic'] ?: 'uploads/default.jpg' ?>"
         onclick="window.location.href='profile.php?user_id=<?= $r['uploaded_by'] ?>'">
    <span class="uname"
          onclick="window.location.href='profile.php?user_id=<?= $r['uploaded_by'] ?>'">
        <?= ($current_user == $r['uploaded_by']) ? 'You' : htmlspecialchars($r['creator_name']) ?>
    </span>
    <?php if(!$is_card_own): ?>
<button class="grid-track-btn <?= $is_card_following ? 'following' : '' ?>"
        data-user="<?= $card_creator_id ?>"
        onclick="event.stopPropagation();gridToggleTrack(this)">
    <?= $is_card_following ? 'Untrack' : 'Track' ?>
</button>
<?php endif; ?>
</div>

        <?php if($r['type'] == 'image'): ?>
            <img src="uploads/<?= $sf ?>" onclick="openDiscoverModal(<?= $modal_args ?>)">
        <?php elseif($r['type'] == 'video'): ?>
            <video src="uploads/<?= $sf ?>" autoplay muted loop onclick="openDiscoverModal(<?= $modal_args ?>)"></video>
        <?php else: ?>
            <img src="uploads/Text.jpg" onclick="openDiscoverModal(<?= $modal_args ?>)">
        <?php endif; ?>

        <?php if($is_new): ?>
            <div class="new-badge">NEW</div>
        <?php endif; ?>
    </div>

    <div class="caption"><?= htmlspecialchars($r['caption']) ?></div>

    <div class="actions">
        <button class="like-btn <?= $liked ? 'active' : '' ?>" data-id="<?= $r['id'] ?>">
            <i class="<?= $liked ? 'ri-heart-fill' : 'ri-heart-3-line' ?>"></i> <span class="count"><?= $r['like_count'] ?></span>
        </button>
        <button class="fav-btn <?= $favorited ? 'active' : '' ?>" data-id="<?= $r['id'] ?>">
            <i class="<?= $favorited ? 'ri-bookmark-fill' : 'ri-bookmark-line' ?>"></i> <span class="count"><?= $r['favorite_count'] ?></span>
        </button>
        <button class="repost-btn <?= $reposted ? 'active' : '' ?>" data-id="<?= $r['id'] ?>">
            <i class="<?= $reposted ? 'ri-repeat-fill' : 'ri-repeat-line' ?>"></i> <span class="count"><?= $r['repost_count'] ?></span>
        </button>
        <button onclick="window.location.href='remix_upload.php?meme_id=<?= $r['id'] ?>'">
            <i class="ri-share-forward-line"></i>
        </button>
        <button onclick="window.location.href='download.php?file=<?= urlencode($r['file_name']) ?>'">
            <i class="ri-download-line"></i>
        </button>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>
</div>

<!-- ══════════════════════════════════════════════════════════
     DISCOVER-STYLE CARD MODAL — exact ccm system from search.php
     ══════════════════════════════════════════════════════════ -->
<div id="discoverModal" onclick="if(event.target===this) closeDiscoverModal()">
    <div id="ccm-stage">
        <div id="ccm-inner"></div>
    </div>

    <div id="ccm-arrows">
        <button type="button" onclick="ccmLoadIndex(_ccmIndex-1)" title="Previous">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.85)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"/></svg>
        </button>
        <button type="button" onclick="ccmLoadIndex(_ccmIndex+1)" title="Next">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.85)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
    </div>
</div>

<style>
#discoverModal{
    display:none;
    position:fixed;inset:0;
    z-index:9999999;
    background:#000;
    align-items:center;
    justify-content:center;
}
#discoverModal.ccm-open{ display:flex; }

#ccm-stage{
    position:relative;
    width:100%;
    height:100%;
    max-width:480px;
    background:#000;
    overflow:hidden;
    touch-action:pan-y;
}

@media(min-width:791px){
    #ccm-stage{
        height:92vh;
        max-height:940px;
        border-radius:22px;
        box-shadow:0 25px 90px rgba(0,0,0,.65), 0 0 0 1px rgba(255,255,255,.06);
    }
}

#ccm-inner{ position:relative; width:100%; height:100%; }

.ccm-slide{
    position:absolute; inset:0;
    background:#000;
    opacity:1; transform:translateY(0);
    transition: transform .28s cubic-bezier(.22,1,.36,1), opacity .28s ease;
}

.ccm-media-box{ width:100%; height:100%; position:relative; background:#000; overflow:hidden; }

.ccm-media-box .ccm-media{
    width:100%; height:100%;
    object-fit:contain;
    background:transparent;
    display:block;
    position:relative;
    z-index:1;
}

.ccm-media-gallery{
    display:flex; flex-direction:row;
    width:100%; height:100%;
    transition:transform .28s cubic-bezier(.22,1,.36,1);
}
.ccm-media-item{
    position:relative;
    flex:0 0 100%; width:100%; height:100%;
    background:#000;
}

.ccm-media-arrow{
    position:absolute; top:34%; transform:translateY(-50%);
    width:36px; height:36px; border-radius:50%;
    background:rgba(0,0,0,.35);
    display:flex; align-items:center; justify-content:center;
    color:#fff; cursor:pointer; z-index:6;
    opacity:0; transition:opacity .2s ease;
    -webkit-tap-highlight-color:transparent;
}
.ccm-media-box:hover .ccm-media-arrow{ opacity:1; }
.ccm-media-arrow-left{ left:56px; }
.ccm-media-arrow-right{ right:64px; }
.ccm-media-arrow i{ font-size:20px; }
@media (hover:none){ .ccm-media-arrow{ display:none !important; } }

.ccm-media-dashes{
    display:flex;
    justify-content:center;
    align-items:center;
    gap:6px;
    margin-top:8px;
    width:100%;
    transform:translateX(39px);
}
.ccm-dash{
    flex:0 0 auto;
    width:6px;
    height:6px;
    border-radius:50%;
    background:rgba(255,255,255,.32);
    transition:background .2s ease, transform .2s ease;
}
.ccm-dash.active{
    background:#ff3b30;
    transform:scale(1.35);
}

@media (max-width:790px){
    .ccm-media-dashes{ gap:5px; transform:translateX(35px); }
    .ccm-dash{ width:5.5px; height:5.5px; }
    .ccm-dash.active{ transform:scale(1.3); }
}
@media (max-width:490px){ .ccm-media-dashes{ transform:translateX(31px); } }
@media (max-width:390px){ .ccm-media-dashes{ transform:translateX(28px); } }
@media (max-width:330px){ .ccm-media-dashes{ transform:translateX(25px); } }

.ccm-skeleton{
    position:absolute; inset:0; z-index:15;
    background:#0c0c10;
    opacity:1;
    transition:opacity .32s ease;
    pointer-events:none;
    overflow:hidden;
}
.ccm-skeleton.ccm-skeleton-hide{ opacity:0; }

.ccm-skel-block{
    position:absolute;
    background:linear-gradient(120deg,#17171b 25%,#232329 37%,#17171b 63%);
    background-size:400% 100%;
    animation:ccmSkelPulse 1.3s ease-in-out infinite;
    border-radius:8px;
}
@keyframes ccmSkelPulse{
    0%{ background-position:100% 50%; }
    100%{ background-position:0% 50%; }
}

.ccm-skel-media{ inset:0; border-radius:0; }
.ccm-skel-avatar{ left:16px; bottom:26px; width:48px; height:48px; border-radius:50%; }
.ccm-skel-line{ left:74px; height:11px; border-radius:5px; }
.ccm-skel-line1{ bottom:53px; width:130px; }
.ccm-skel-line2{ bottom:33px; width:190px; }
.ccm-skel-rail{ right:16px; width:27px; height:27px; border-radius:50%; }
.ccm-skel-rail1{ bottom:26px; }
.ccm-skel-rail2{ bottom:78px; }
.ccm-skel-rail3{ bottom:130px; }
.ccm-skel-rail4{ bottom:182px; }

.ccm-skel-shimmer{
    position:absolute; inset:0; z-index:1;
    background:linear-gradient(100deg,
        rgba(255,255,255,0) 30%,
        rgba(255,255,255,.05) 45%,
        rgba(255,255,255,.12) 50%,
        rgba(255,255,255,.05) 55%,
        rgba(255,255,255,0) 70%);
    background-size:250% 100%;
    animation:ccmShimmerSweep 1.3s ease-in-out infinite;
}
@keyframes ccmShimmerSweep{
    0%{ background-position:150% 0; }
    100%{ background-position:-150% 0; }
}

.ccm-tap-flash{
    position:absolute; top:50%; left:50%;
    transform:translate(-50%,-50%) scale(.6);
    z-index:3; pointer-events:none;
    width:64px; height:64px; border-radius:50%;
    background:rgba(0,0,0,.45);
    display:flex; align-items:center; justify-content:center;
    opacity:0; transition:opacity .2s ease, transform .2s ease;
}
.ccm-tap-flash.show{ opacity:1; transform:translate(-50%,-50%) scale(1); }
.ccm-tap-flash i{ font-size:32px; color:#fff; }

.ccm-paused-controls{
    position:absolute; inset:0; z-index:4;
    display:flex; align-items:center; justify-content:center; gap:22px;
    pointer-events:none;
}
.ccm-pause-icon{
    width:60px; height:60px; border-radius:50%;
    background:rgba(0,0,0,.45);
    display:flex; align-items:center; justify-content:center;
}
.ccm-pause-icon i{ font-size:28px; color:#fff; }
.ccm-mute-btn{
    width:60px; height:60px; border-radius:50%;
    background:rgba(0,0,0,.45); border:none; cursor:pointer;
    display:flex; align-items:center; justify-content:center;
    pointer-events:all;
    -webkit-tap-highlight-color:transparent;
    transition:transform .15s ease, background .15s ease;
}
.ccm-mute-btn:active{ transform:scale(.9); background:rgba(0,0,0,.6); }
.ccm-mute-btn i{ font-size:26px; color:#fff; }

.ccm-textonly-bg{
    position:absolute; inset:0; z-index:0;
    background-image:url('uploads/Text.jpg');
    background-size:cover; background-position:center;
}
.ccm-textonly-overlay{
    position:absolute; inset:0; z-index:0;
    background:rgba(0,0,0,.45);
}

.ccm-center-caption-wrap{
    position:absolute;
    top:122px; left:16px; right:70px; bottom:225px;
    z-index:1;
    display:flex;
    align-items:center;
    justify-content:center;
    text-align:center;
}
.ccm-center-caption-wrap.ccm-caption-overflow{ align-items:flex-start; }
.ccm-center-caption-text{
    width:100%;
    max-width:420px;
    margin:0 auto;
    transform:translateX(14px);
    color:#fff; font-size:16px; font-weight:500; line-height:1.5;
    text-shadow:0 1px 5px rgba(0,0,0,.4);
    max-height:100%; overflow-y:auto;
    word-break:break-word;
    scrollbar-width:none; -ms-overflow-style:none;
}
.ccm-center-caption-text::-webkit-scrollbar{ display:none; }

.ccm-top-bar{
    position:absolute; top:0; left:0; right:0; z-index:8;
    padding:14px 12px 34px 4px;
    background:linear-gradient(to bottom, rgba(0,0,0,.68) 0%, rgba(0,0,0,.3) 60%, transparent 100%);
    pointer-events:none;
}
.ccm-top-bar-row{ display:flex; justify-content:space-between; align-items:center; pointer-events:all; }
.ccm-top-bar > *{ pointer-events:all; }
.ccm-back-btn{ padding-left:2px !important; }

.ccm-icon-btn{
    background:none; border:none; box-shadow:none;
    color:#fff; cursor:pointer; padding:6px;
    display:flex; align-items:center; justify-content:center;
    -webkit-tap-highlight-color:transparent;
    transition:transform .15s ease, opacity .15s ease;
}
.ccm-icon-btn:active{ transform:scale(.88); opacity:.7; }
.ccm-icon-btn i{ font-size:30px; filter:drop-shadow(0 2px 6px rgba(0,0,0,.7)); }
.ccm-search-btn i{ font-size:24px; }

.ccm-back-group{ display:flex; align-items:center; gap:2px; }
.ccm-brand-label{
    color:#fff; font-weight:700; font-size:16px;
    text-shadow:0 2px 6px rgba(0,0,0,.6);
    font-family:Poppins,sans-serif;
}
.ccm-brand-dot{ color:rgba(255,255,255,.6); font-size:14px; font-weight:700; margin:0 5px; }
.ccm-brand-date{
    color:rgba(255,255,255,.75); font-size:12.5px; font-weight:500;
    text-shadow:0 1px 4px rgba(0,0,0,.6);
    font-family:Poppins,sans-serif; white-space:nowrap;
}

.ccm-ad-badge{
    font-size:9px; font-weight:800; letter-spacing:1.2px; color:#fff;
    border:1px solid rgba(255,255,255,.4); border-radius:4px; padding:3px 7px;
    text-transform:uppercase; background:rgba(0,0,0,.3);
}

.ccm-top-right-tags{
    position:absolute; top:80px; right:14px; z-index:8;
    display:flex; flex-direction:column; align-items:flex-end; gap:6px;
    pointer-events:none;
}
.ccm-media-counter{
    background:rgba(22,22,24,.82); color:#fff;
    font-size:10px; font-weight:800; letter-spacing:.3px;
    padding:5px 9px; border-radius:6px;
}
.ccm-type-tag{
    display:flex; align-items:center; gap:4px;
    background:rgba(22,22,24,.82); border:none;
    border-radius:6px; padding:5px 9px 5px 7px;
}
.ccm-type-tag i{ font-size:12.5px; color:#fff; }
.ccm-type-tag span{
    font-size:10px; font-weight:700; color:#fff;
    text-transform:uppercase; letter-spacing:.4px;
    white-space:nowrap;
}

.ccm-rail{
    position:absolute; right:16px; bottom:26px; z-index:9;
    display:flex; flex-direction:column; align-items:center; gap:28px;
}
.ccm-rail-btn{
    display:flex; flex-direction:column; align-items:center; gap:5px;
    background:none; border:none; cursor:pointer;
    -webkit-tap-highlight-color:transparent;
}
.ccm-rail-btn i{
    font-size:27px; color:#fff;
    -webkit-text-stroke:0.6px currentColor;
    filter:drop-shadow(0 2px 5px rgba(0,0,0,.55));
    transition:transform .18s ease, color .18s ease;
}
.ccm-rail-btn.active i{ color:#ff2d55; }
.ccm-rail-btn:active i{ transform:scale(1.3); }
.ccm-rail-count{
    font-size:13px; font-weight:800;
    -webkit-text-stroke:0.2px currentColor;
    color:#fff;
    text-shadow:0 1px 4px rgba(0,0,0,.75), 0 0 8px rgba(255,255,255,.25);
}

.ccm-rail-msg{ text-decoration:none; }
.ccm-rail-msg-pic{
    width:30px; height:30px; object-fit:cover;
    border-radius:10px; border:2px solid #fff;
    box-shadow:0 3px 8px rgba(0,0,0,.55);
    display:block; transition:transform .15s ease;
}
.ccm-rail-msg:active .ccm-rail-msg-pic{ transform:scale(1.12); }

.ccm-bottom{
    position:absolute; left:0; right:0; bottom:0; z-index:7;
    padding:16px 78px 22px 16px;
    background:linear-gradient(to top, rgba(0,0,0,.88) 0%, rgba(0,0,0,.55) 55%, transparent 100%);
}
.ccm-bottom.ccm-no-rail{ padding-right:16px; }

.ccm-user-row{ display:flex; align-items:center; gap:10px; margin-bottom:5px; }
.ccm-user-pic{
    width:48px; height:48px; border-radius:50%; object-fit:cover;
    border:2.5px solid #ff2b2b; cursor:pointer; flex-shrink:0;
}
.ccm-user-info{ display:flex; flex-direction:column; justify-content:center; gap:0px; min-width:0; flex:1; }
.ccm-user-line1{ display:flex; align-items:center; gap:8px; min-width:0; line-height:1.1; margin-bottom:4px; }
.ccm-username{
    color:#fff; font-weight:700; font-size:14px; cursor:pointer;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    text-shadow:0 1px 3px rgba(0,0,0,.5); line-height:1.1;
}
.ccm-nickname{ color:rgba(255,255,255,.5); font-size:11.5px; font-weight:500; margin:0; line-height:1.1; }

.ccm-social-proof-strip{
    position:absolute; left:16px; bottom:175px; z-index:6;
    display:flex; flex-direction:row-reverse; gap:8px;
    pointer-events:none;
}
.ccm-sp-avatar-wrap{ position:relative; pointer-events:all; display:inline-block; text-decoration:none; }
.ccm-sp-avatar-wrap:nth-child(1){ animation:ccmSpFloat 3s ease-in-out infinite; }
.ccm-sp-avatar-wrap:nth-child(2){ animation:ccmSpFloat 3s ease-in-out infinite .4s; }
.ccm-sp-avatar-wrap:nth-child(3){ animation:ccmSpFloat 3s ease-in-out infinite .8s; }
.ccm-sp-avatar-wrap:nth-child(4){ animation:ccmSpFloat 3s ease-in-out infinite 1.2s; }
@keyframes ccmSpFloat{
    0%,100%{ transform:translateY(0); }
    50%{ transform:translateY(-6px); }
}
.ccm-sp-avatar{
    width:44px; height:44px; border-radius:50%; object-fit:cover;
    border:2.5px solid #ff2b2b; display:block;
    box-shadow:0 4px 12px rgba(0,0,0,.7);
}
.ccm-sp-badge{
    position:absolute; bottom:-2px; right:-2px;
    width:14px; height:14px; border-radius:50%;
    display:flex; align-items:center; justify-content:center;
    z-index:2;
}
.ccm-sp-badge i{ font-size:7px; color:#fff; line-height:1; }

.ccm-track-btn{
    padding:0; margin:0; border-radius:0;
    border:none; background:transparent;
    color:#ff2b2b; font-size:13px; font-weight:700;
    cursor:pointer; flex-shrink:0; font-family:Poppins,sans-serif;
    text-decoration:underline; text-decoration-color:#ff2b2b;
    text-underline-offset:2px; text-decoration-thickness:1.5px;
    transition:opacity .2s, transform .15s;
    -webkit-tap-highlight-color:transparent;
}
.ccm-track-btn.following{
    background:transparent; border:none; color:#ff2b2b;
    text-decoration:underline; text-decoration-color:#ff2b2b;
}
.ccm-track-btn:active{ transform:scale(.94); opacity:.7; }

.ccm-user-dot{ color:#fff; font-size:15px; flex-shrink:0; }

.ccm-caption-wrap{ max-width:100%; }
.ccm-caption-text{
    color:#fff; font-size:13px; line-height:1.5;
    word-break:break-word; text-shadow:0 1px 2px rgba(0,0,0,.35);
    display:inline;
}
.ccm-more-toggle{
    color:#ff2b2b; font-size:inherit; font-weight:600;
    line-height:inherit; cursor:pointer; display:inline;
    -webkit-tap-highlight-color:transparent;
}
.ccm-ellipsis{ color:#fff; font-weight:500; }

.ccm-meta-row{
    display:flex; align-items:center; gap:6px; margin-top:8px;
    color:rgba(255,255,255,.55); font-size:11px;
}
.ccm-meta-row i{ font-size:11px; }
.ccm-new-tag{ color:#ff2b2b; font-weight:700; }

#ccm-nav-hint{
    position:absolute; bottom:8px; left:50%; transform:translateX(-50%);
    color:rgba(255,255,255,.28); font-size:10px; z-index:20;
    pointer-events:none; white-space:nowrap;
}

#ccm-arrows{
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    gap:14px; margin-left:16px; flex-shrink:0;
}
#ccm-arrows button{
    background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.14);
    border-radius:50%; width:44px; height:44px;
    display:flex; align-items:center; justify-content:center;
    cursor:pointer; transition:background .2s ease, transform .15s ease;
    -webkit-tap-highlight-color:transparent;
}
#ccm-arrows button:hover{ background:rgba(255,255,255,.18); }
#ccm-arrows button:active{ transform:scale(.9); }
@media(max-width:790px){ #ccm-arrows{ display:none; } }

.ccm-panel-slide{
    width:100%; height:100%; overflow-y:auto;
    background:#0b0b14;
    scrollbar-width:none; -ms-overflow-style:none;
}
.ccm-panel-slide::-webkit-scrollbar{ display:none; }
.ccm-panel-topbar{
    position:sticky; top:0; z-index:5;
    display:flex; align-items:center; justify-content:space-between;
    padding:12px 14px;
    background:rgba(11,11,20,.92);
    backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    border-bottom:1px solid rgba(255,255,255,.06);
}
.ccm-panel-inner{ padding:16px 18px 30px; display:flex; flex-direction:column; gap:12px; }

.ccm-ad-inline-tag{
    display:flex; align-items:center; gap:4px;
    font-size:10px; font-weight:800; letter-spacing:.5px; color:#fff;
    border:none; border-radius:6px; padding:5px 9px 5px 7px;
    text-transform:uppercase; background:rgba(22,22,24,.82); flex-shrink:0;
}
.ccm-ad-inline-tag i{ font-size:12px; }

.ccm-visit-brand-btn{
    display:flex; align-items:center; justify-content:center; gap:6px;
    width:100%; margin:8px 0 0; padding:6px 0;
    border-radius:9px; color:#fff; font-weight:700; font-size:13px;
    text-decoration:none; font-family:Poppins,sans-serif;
    transition:background .2s;
}
.ccm-visit-brand-red{ background:rgba(224,68,74,.9); border:1px solid rgba(224,68,74,1); }
.ccm-visit-brand-red:hover{ background:rgba(224,68,74,1); }
.ccm-visit-brand-blue{ background:rgba(56,108,235,.9); border:1px solid rgba(56,108,235,1); }
.ccm-visit-brand-blue:hover{ background:rgba(56,108,235,1); }
.ccm-visit-brand-orange{ background:rgba(211,127,53,.9); border:1px solid rgba(211,127,53,1); }
.ccm-visit-brand-orange:hover{ background:rgba(211,127,53,1); }

.ccm-sugg-header{ padding:2px 0 14px; }
.ccm-sugg-title{ font-size:15px; font-weight:800; color:#fff; font-family:Poppins,sans-serif; }
.ccm-sugg-subtitle{ font-size:11.5px; color:rgba(255,255,255,.5); margin-top:2px; }
.ccm-sugg-grid{ display:grid; grid-template-columns:repeat(3,1fr); gap:10px; }
.ccm-sugg-card{
    background:linear-gradient(160deg, rgba(255,255,255,.07), rgba(255,255,255,.02));
    border:1px solid rgba(255,255,255,.09);
    border-radius:14px;
    padding:14px 6px 12px;
    display:flex; flex-direction:column; align-items:center; gap:7px;
    text-align:center;
    transition:transform .15s ease, border-color .15s ease;
}
.ccm-sugg-card:active{ transform:scale(.97); }
.ccm-sugg-card-link{
    display:flex; flex-direction:column; align-items:center; gap:7px;
    text-decoration:none; color:inherit; width:100%;
}
.ccm-sugg-card img{
    width:52px; height:52px; border-radius:50%; object-fit:cover;
    border:2px solid #ff2b2b; display:block;
    box-shadow:0 4px 14px rgba(0,0,0,.35);
}
.ccm-sugg-card-name{
    font-size:11px; font-weight:700; color:#fff;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:100%;
}
.ccm-sugg-card-track{
    width:100%; margin-top:1px;
    font-size:10px; font-weight:700; padding:6px 0;
    border-radius:8px; border:1.5px solid #ff2b2b;
    background:transparent; color:#ff2b2b;
    cursor:pointer; transition:all .2s;
    font-family:Poppins,sans-serif;
    -webkit-tap-highlight-color:transparent;
}
.ccm-sugg-card-track:hover{ background:rgba(255,43,43,.12); }
.ccm-sugg-card-track.tracking{ background:#ff2b2b; color:#fff; }

@media (max-width:790px){
    .ccm-icon-btn i{ font-size:28px; }
    .ccm-search-btn i{ font-size:22px; }
    .ccm-back-btn i{ font-size:34px; }
    .ccm-brand-label{ font-size:15px; }
    .ccm-brand-date{ font-size:11.5px; }
    .ccm-top-right-tags{ top:73px; right:13px; gap:5px; }
    .ccm-type-tag{ padding:5px 8px 5px 6px; }
    .ccm-type-tag i{ font-size:12px; }
    .ccm-type-tag span{ font-size:9.5px; }
    .ccm-media-counter{ font-size:9.5px; padding:4.5px 8px; }
    .ccm-rail{ right:14px; bottom:24px; gap:25px; }
    .ccm-rail-btn i{ font-size:25px; }
    .ccm-rail-msg-pic{ width:28px; height:28px; border-radius:9px; }
    .ccm-rail-count{ font-size:12px; }
    .ccm-bottom{ right:0; padding:16px 70px 20px 14px; }
    .ccm-bottom.ccm-no-rail{ padding-right:14px; }
    .ccm-social-proof-strip{ left:14px; bottom:162px; }
    .ccm-sp-avatar{ width:40px; height:40px; }
    .ccm-user-pic{ width:44px; height:44px; }
    .ccm-nickname{ font-size:11px; }
    .ccm-username{ font-size:13.5px; }
    .ccm-track-btn{ padding:0; font-size:12px; }
    .ccm-caption-text{ font-size:12px; }
    .ccm-panel-topbar{ padding:11px 13px; }
    .ccm-center-caption-wrap{ top:112px; left:14px; right:62px; bottom:210px; }
    .ccm-center-caption-text{ font-size:15px; max-width:380px; transform:translateX(12px); }
    .ccm-pause-icon,.ccm-mute-btn{ width:56px; height:56px; }
    .ccm-pause-icon i{ font-size:26px; }
    .ccm-mute-btn i{ font-size:24px; }
    .ccm-skel-avatar{ width:44px; height:44px; }
    .ccm-skel-line{ left:68px; }
    .ccm-skel-line1{ bottom:50px; width:120px; }
    .ccm-skel-line2{ bottom:31px; width:175px; }
    .ccm-media-arrow-left{ left:48px; }
    .ccm-media-arrow-right{ right:56px; }
    .ccm-media-arrow{ width:32px; height:32px; }
    .ccm-media-arrow i{ font-size:18px; }
}
@media (max-width:490px){
    .ccm-icon-btn i{ font-size:26px; }
    .ccm-search-btn i{ font-size:20px; }
    .ccm-back-btn i{ font-size:32px; }
    .ccm-brand-label{ font-size:14px; }
    .ccm-brand-date{ font-size:11px; }
    .ccm-top-right-tags{ top:66px; right:12px; gap:5px; }
    .ccm-type-tag{ padding:4.5px 7px 4.5px 5px; }
    .ccm-type-tag i{ font-size:11px; }
    .ccm-type-tag span{ font-size:9px; }
    .ccm-media-counter{ font-size:9px; padding:4px 7px; }
    .ccm-rail{ right:13px; bottom:22px; gap:22px; }
    .ccm-rail-btn i{ font-size:23px; }
    .ccm-rail-msg-pic{ width:26px; height:26px; border-radius:8px; border-width:1.5px; }
    .ccm-rail-count{ font-size:11.5px; }
    .ccm-bottom{ right:0; padding:13px 62px 18px 12px; }
    .ccm-bottom.ccm-no-rail{ padding-right:12px; }
    .ccm-social-proof-strip{ left:12px; bottom:140px; }
    .ccm-sp-avatar{ width:37px; height:37px; border-width:2px; }
    .ccm-user-pic{ width:41px; height:41px; border-width:2px; }
    .ccm-nickname{ font-size:10.5px; }
    .ccm-username{ font-size:13px; }
    .ccm-track-btn{ padding:0; font-size:11.5px; border-radius:0; }
    .ccm-caption-text{ font-size:11.5px; }
    .ccm-meta-row{ font-size:10px; margin-top:6px; }
    .ccm-top-bar{ padding:11px 10px 28px 3px; }
    #ccm-nav-hint{ display:none; }
    .ccm-panel-topbar{ padding:10px 11px; }
    .ccm-sugg-grid{ gap:9px; }
    .ccm-sugg-card{ padding:12px 5px 10px; border-radius:13px; gap:6px; }
    .ccm-sugg-card img{ width:46px; height:46px; }
    .ccm-sugg-card-name{ font-size:10.5px; }
    .ccm-sugg-card-track{ font-size:9.5px; padding:5.5px 0; }
    .ccm-center-caption-wrap{ top:104px; left:12px; right:54px; bottom:185px; }
    .ccm-center-caption-text{ font-size:14px; max-width:340px; transform:translateX(10px); }
    .ccm-pause-icon,.ccm-mute-btn{ width:52px; height:52px; }
    .ccm-pause-icon i{ font-size:24px; }
    .ccm-mute-btn i{ font-size:22px; }
    .ccm-skel-avatar{ width:41px; height:41px; left:12px; }
    .ccm-skel-line{ left:63px; }
    .ccm-skel-line1{ bottom:47px; width:110px; }
    .ccm-skel-line2{ bottom:29px; width:160px; }
    .ccm-skel-rail{ width:23px; height:23px; right:13px; }
    .ccm-media-arrow-left{ left:42px; }
    .ccm-media-arrow-right{ right:50px; }
    .ccm-media-arrow{ width:30px; height:30px; }
    .ccm-media-arrow i{ font-size:16px; }
}
@media (max-width:390px){
    .ccm-icon-btn i{ font-size:24px; }
    .ccm-search-btn i{ font-size:19px; }
    .ccm-back-btn i{ font-size:30px; }
    .ccm-brand-label{ font-size:13px; }
    .ccm-brand-date{ font-size:10.5px; }
    .ccm-top-right-tags{ top:60px; right:10px; gap:4px; }
    .ccm-type-tag{ padding:4px 6.5px 4px 5px; }
    .ccm-type-tag i{ font-size:10px; }
    .ccm-type-tag span{ font-size:8.5px; }
    .ccm-media-counter{ font-size:8.5px; padding:3.5px 6px; }
    .ccm-rail{ right:12px; bottom:20px; gap:20px; }
    .ccm-rail-btn i{ font-size:21px; }
    .ccm-rail-msg-pic{ width:24px; height:24px; border-radius:7px; border-width:1.5px; }
    .ccm-rail-count{ font-size:11px; }
    .ccm-bottom{ right:0; padding:11px 56px 16px 10px; }
    .ccm-bottom.ccm-no-rail{ padding-right:10px; }
    .ccm-social-proof-strip{ left:10px; bottom:125px; }
    .ccm-sp-avatar{ width:35px; height:35px; border-width:2px; }
    .ccm-user-pic{ width:39px; height:39px; border-width:2px; }
    .ccm-nickname{ font-size:10px; }
    .ccm-username{ font-size:12.5px; }
    .ccm-track-btn{ padding:0; font-size:11px; }
    .ccm-caption-text{ font-size:11px; line-height:1.45; }
    .ccm-user-row{ gap:8px; margin-bottom:4px; }
    .ccm-panel-topbar{ padding:9px 10px; }
    .ccm-sugg-grid{ gap:8px; }
    .ccm-sugg-card{ padding:11px 4px 9px; border-radius:12px; gap:5px; }
    .ccm-sugg-card img{ width:42px; height:42px; border-width:2px; }
    .ccm-sugg-card-name{ font-size:10px; }
    .ccm-sugg-card-track{ font-size:9px; padding:5px 0; }
    .ccm-sugg-title{ font-size:14px; }
    .ccm-sugg-subtitle{ font-size:11px; }
    .ccm-center-caption-wrap{ top:96px; left:10px; right:48px; bottom:170px; }
    .ccm-center-caption-text{ font-size:13px; max-width:300px; transform:translateX(8px); }
    .ccm-pause-icon,.ccm-mute-btn{ width:48px; height:48px; }
    .ccm-pause-icon i{ font-size:22px; }
    .ccm-mute-btn i{ font-size:20px; }
    .ccm-skel-avatar{ width:39px; height:39px; left:10px; }
    .ccm-skel-line{ left:59px; }
    .ccm-skel-line1{ bottom:44px; width:100px; }
    .ccm-skel-line2{ bottom:27px; width:145px; }
    .ccm-skel-rail{ width:21px; height:21px; right:12px; }
    .ccm-media-arrow-left{ left:38px; }
    .ccm-media-arrow-right{ right:46px; }
    .ccm-media-arrow{ width:28px; height:28px; }
    .ccm-media-arrow i{ font-size:15px; }
}
@media (max-width:330px){
    .ccm-icon-btn i{ font-size:22px; }
    .ccm-search-btn i{ font-size:18px; }
    .ccm-back-btn i{ font-size:28px; }
    .ccm-brand-label{ font-size:12px; }
    .ccm-brand-date{ font-size:10px; }
    .ccm-top-right-tags{ top:56px; right:9px; gap:4px; }
    .ccm-type-tag{ padding:3.5px 6px 3.5px 4.5px; }
    .ccm-type-tag i{ font-size:9px; }
    .ccm-type-tag span{ font-size:8px; }
    .ccm-media-counter{ font-size:8px; padding:3px 5.5px; }
    .ccm-rail{ right:11px; bottom:18px; gap:17px; }
    .ccm-rail-btn i{ font-size:19px; }
    .ccm-rail-msg-pic{ width:22px; height:22px; border-radius:6px; border-width:1.5px; }
    .ccm-rail-count{ font-size:10.5px; }
    .ccm-bottom{ right:0; padding:10px 50px 14px 9px; }
    .ccm-bottom.ccm-no-rail{ padding-right:9px; }
    .ccm-social-proof-strip{ left:9px; bottom:110px; }
    .ccm-sp-avatar{ width:32px; height:32px; border-width:1.5px; }
    .ccm-user-pic{ width:35px; height:35px; border-width:1.5px; }
    .ccm-nickname{ font-size:9.5px; }
    .ccm-username{ font-size:11.5px; }
    .ccm-track-btn{ padding:0; font-size:10.5px; border-radius:0; }
    .ccm-caption-text{ font-size:10.5px; line-height:1.4; }
    .ccm-top-bar{ padding:9px 8px 24px 3px; }
    .ccm-panel-topbar{ padding:9px 9px; }
    .ccm-sugg-grid{ gap:7px; }
    .ccm-sugg-card{ padding:10px 3px 8px; border-radius:11px; gap:5px; }
    .ccm-sugg-card img{ width:38px; height:38px; border-width:1.5px; }
    .ccm-sugg-card-name{ font-size:9.5px; }
    .ccm-sugg-card-track{ font-size:8.5px; padding:4.5px 0; }
    .ccm-center-caption-wrap{ top:90px; left:9px; right:44px; bottom:150px; }
    .ccm-center-caption-text{ font-size:12px; max-width:260px; transform:translateX(6px); }
    .ccm-pause-icon,.ccm-mute-btn{ width:44px; height:44px; gap:14px; }
    .ccm-pause-icon i{ font-size:20px; }
    .ccm-mute-btn i{ font-size:18px; }
    .ccm-skel-avatar{ width:35px; height:35px; left:9px; }
    .ccm-skel-line{ left:53px; }
    .ccm-skel-line1{ bottom:41px; width:90px; }
    .ccm-skel-line2{ bottom:25px; width:130px; }
    .ccm-skel-rail{ width:19px; height:19px; right:11px; }
    .ccm-media-arrow-left{ left:34px; }
    .ccm-media-arrow-right{ right:42px; }
    .ccm-media-arrow{ width:26px; height:26px; }
    .ccm-media-arrow i{ font-size:14px; }
}
</style>

<script>
var _ccmIndex      = 0;
var _ccmMemeId     = null;
var _ccmPostUserId = null;
var _ccmMediaIndex = 0;
var ccmFeed        = [];
var _ccmCaptionFullHtml  = '';
var _ccmCaptionTruncHtml = '';
var _ccmCaptionExpanded  = false;
var _ccmBuilt      = false;
const currentUserId = <?php echo (int)$current_user; ?>;

/* ── DATA LOADING — reposts.php's own ads/sugg endpoints ── */
function _loadCcmData(){
    Promise.all([
        fetch('reposts.php?type=ads').then(function(r){ return r.json(); }).catch(function(){ return []; }),
        fetch('reposts.php?type=sugg').then(function(r){ return r.json(); }).catch(function(){ return []; })
    ]).then(function(results){
        window.cmAds       = results[0] || [];
        window.cmSuggUsers = results[1] || [];
        _buildCcmFeed();
        _ccmBuilt = true;
    });
}

function _buildCcmFeed(){
    ccmFeed = [];
    var adIdx = 0, suggIdx = 0, adSlot = 0;
    var ads  = window.cmAds       || [];
    var sugg = window.cmSuggUsers || [];
    if(typeof peuraFeed === 'undefined') return;
    peuraFeed.forEach(function(post, i){
        ccmFeed.push({ type:'post', data:post });
        if((i+1) % 4 === 0 && ads.length > 0){
            ccmFeed.push({ type:'ad', data:ads[adIdx % ads.length], adSlot:adSlot });
            adIdx++; adSlot++;
        }
        if((i+1) % 7 === 0 && sugg.length > 0){
            var slice = [];
            for(var j=0; j<6; j++) slice.push(sugg[(suggIdx+j) % sugg.length]);
            ccmFeed.push({ type:'sugg', data:slice });
            suggIdx += 6;
        }
    });
}

function _formatDate(timeStr){
    if(!timeStr) return '';
    var d = new Date(timeStr.replace(' ','T'));
    if(isNaN(d)) return timeStr;
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return d.getDate()+' '+months[d.getMonth()]+' '+d.getFullYear()+
           ' · '+String(d.getHours()).padStart(2,'0')+':'+String(d.getMinutes()).padStart(2,'0');
}

function _ccmTruncateName(name){
    if(!name) return '';
    return name.length > 15 ? name.slice(0, 15) + '....' : name;
}

function _ccmTypeTagInfo(rawType){
    var type = (rawType || '').toLowerCase();
    var map = {
        image:    { icon:'ri-file-copy-line', label:'Photo'   },
        video:    { icon:'ri-vidicon-line',   label:'Video'   },
        gif:      { icon:'ri-file-gif-line',  label:'GIF'     },
        sticker:  { icon:'ri-shapes-line',    label:'Sticker' },
        stickers: { icon:'ri-shapes-line',    label:'Sticker' },
        text:     { icon:'ri-file-text-line', label:'Text'    }
    };
    return map[type] || { icon:'ri-file-copy-line', label:'Photo' };
}

function linkifyCaption(caption){
    return caption.replace(/@([a-zA-Z0-9_]+)/g, function(match, username){
        return '<a href="profile.php?username='+encodeURIComponent(username)+
               '" onclick="event.stopPropagation()" style="color:#ff2b2b;font-weight:600;text-decoration:none;"'+
               ' onmouseover="this.style.textDecoration=\'underline\'"'+
               ' onmouseout="this.style.textDecoration=\'none\'">'+match+'</a>';
    });
}

function ccmToggleCaption(){
    var textEl = document.getElementById('ccm-caption-text');
    if(!textEl) return;
    _ccmCaptionExpanded = !_ccmCaptionExpanded;
    if(_ccmCaptionExpanded){
        textEl.innerHTML = _ccmCaptionFullHtml+
            ' <span class="ccm-more-toggle" onclick="event.stopPropagation();ccmToggleCaption()"> see less</span>';
    } else {
        textEl.innerHTML = _ccmCaptionTruncHtml;
    }
}

function _ccmSkeletonHtml(){
    return '<div class="ccm-skeleton" id="ccm-skeleton">'+
        '<div class="ccm-skel-block ccm-skel-media"></div>'+
        '<div class="ccm-skel-block ccm-skel-avatar"></div>'+
        '<div class="ccm-skel-block ccm-skel-line ccm-skel-line1"></div>'+
        '<div class="ccm-skel-block ccm-skel-line ccm-skel-line2"></div>'+
        '<div class="ccm-skel-block ccm-skel-rail ccm-skel-rail1"></div>'+
        '<div class="ccm-skel-block ccm-skel-rail ccm-skel-rail2"></div>'+
        '<div class="ccm-skel-block ccm-skel-rail ccm-skel-rail3"></div>'+
        '<div class="ccm-skel-block ccm-skel-rail ccm-skel-rail4"></div>'+
        '<div class="ccm-skel-shimmer"></div>'+
    '</div>';
}

function ccmHideSkeleton(root){
    var sk = root ? root.querySelector('#ccm-skeleton') : document.getElementById('ccm-skeleton');
    if(!sk) return;
    sk.classList.add('ccm-skeleton-hide');
    setTimeout(function(){ if(sk && sk.parentNode) sk.remove(); }, 340);
}

function _ccmCheckCaptionOverflow(slide){
    if(!slide) return;
    var wrap = slide.querySelector('.ccm-center-caption-wrap');
    var text = slide.querySelector('.ccm-center-caption-text');
    if(!wrap || !text) return;
    var isOverflowing = text.scrollHeight > wrap.clientHeight;
    wrap.classList.toggle('ccm-caption-overflow', isOverflowing);
}

function ccmToggleVideoPlay(mediaBox){
    var video = mediaBox.querySelector('video.ccm-media');
    if(!video) return;
    if(video.paused){
        video.play();
        ccmHidePausedControls(mediaBox);
        ccmFlashIcon(mediaBox, 'ri-play-fill');
    } else {
        video.pause();
        ccmShowPausedControls(mediaBox, video);
    }
}

function ccmMediaBoxTap(mediaBox){
    var activeItem = mediaBox.querySelector('.ccm-media-item[data-idx="'+_ccmMediaIndex+'"]');
    if(!activeItem) return;
    var video = activeItem.querySelector('video.ccm-media');
    if(!video) return;
    if(video.paused){
        video.play();
        ccmHidePausedControls(activeItem);
        ccmFlashIcon(activeItem, 'ri-play-fill');
    } else {
        video.pause();
        ccmShowPausedControls(activeItem, video);
    }
}

function ccmFlashIcon(mediaBox, iconClass){
    var existing = mediaBox.querySelector('.ccm-tap-flash');
    if(existing) existing.remove();
    var icon = document.createElement('div');
    icon.className = 'ccm-tap-flash';
    icon.innerHTML = '<i class="'+iconClass+'"></i>';
    mediaBox.appendChild(icon);
    requestAnimationFrame(function(){ icon.classList.add('show'); });
    setTimeout(function(){
        icon.classList.remove('show');
        setTimeout(function(){ icon.remove(); }, 220);
    }, 450);
}

function ccmShowPausedControls(mediaBox, video){
    ccmHidePausedControls(mediaBox);
    var wrap = document.createElement('div');
    wrap.className = 'ccm-paused-controls';
    wrap.innerHTML =
        '<div class="ccm-pause-icon"><i class="ri-pause-fill"></i></div>'+
        '<button type="button" class="ccm-mute-btn" onclick="event.stopPropagation();ccmToggleMute(this)">'+
            '<i class="'+(video.muted ? 'ri-volume-mute-fill' : 'ri-volume-up-fill')+'"></i>'+
        '</button>';
    mediaBox.appendChild(wrap);
}

function ccmHidePausedControls(mediaBox){
    var existing = mediaBox.querySelector('.ccm-paused-controls');
    if(existing) existing.remove();
}

function ccmToggleMute(btn){
    var container = btn.closest('.ccm-media-box') || btn.closest('.ccm-media-item');
    var video = container ? container.querySelector('video.ccm-media') : null;
    if(!video) return;
    video.muted = !video.muted;
    var icon = btn.querySelector('i');
    icon.className = video.muted ? 'ri-volume-mute-fill' : 'ri-volume-up-fill';
}

function ccmFixObjectFit(el){
    var box = el.closest('.ccm-media-item') || el.closest('.ccm-media-box');
    if(!box) return;
    var mW = el.tagName === 'VIDEO' ? el.videoWidth  : el.naturalWidth;
    var mH = el.tagName === 'VIDEO' ? el.videoHeight : el.naturalHeight;
    if(!mW || !mH) return;
    var cW = box.clientWidth, cH = box.clientHeight;
    if(!cW || !cH) return;
    var mediaAspect = mW / mH;
    var boxAspect   = cW / cH;
    el.style.objectFit = (mediaAspect < boxAspect) ? 'cover' : 'contain';
}

/* ── FOLLOW/TRACK — shared between grid and modal ── */
function syncTrackState(userId, following) {
    document.querySelectorAll('.grid-track-btn[data-user="' + userId + '"]').forEach(function(gb) {
        if (following) { gb.textContent = 'Untrack'; gb.classList.add('following'); }
        else           { gb.textContent = 'Track';   gb.classList.remove('following'); }
    });
    document.querySelectorAll('.ccm-track-btn[data-user="' + userId + '"]').forEach(function(mb) {
        if (following) { mb.textContent = 'Untrack'; mb.classList.add('following'); }
        else           { mb.textContent = 'Track';   mb.classList.remove('following'); }
    });
    (peuraFeed || []).forEach(function(p) {
        if (String(p.userId) === String(userId)) p.isFollowing = following;
    });
}

function gridToggleTrack(btn) {
    var userId = btn.dataset.user;
    var orig   = btn.textContent;
    btn.disabled = true;
    fetch('reposts.php', {
        method:  'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body:    'action=toggle_follow&user_id=' + userId
    })
    .then(function(r){ return r.json(); })
    .then(function(data) {
        btn.disabled = false;
        if (data.status === 'ok') { syncTrackState(userId, data.following); }
        else                      { btn.textContent = orig; }
    })
    .catch(function(){ btn.disabled = false; btn.textContent = orig; });
}

function ccmToggleTrack(btn){
    var userId = btn.dataset.user;
    var orig = btn.textContent;
    fetch('reposts.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=toggle_follow&user_id='+userId
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        if(data.status === 'ok'){ syncTrackState(userId, data.following); }
        else { btn.textContent = orig; }
    });
}

function toggleSuggTrack(btn){
    var userId = btn.dataset.user;
    fetch('reposts.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=toggle_follow&user_id='+userId
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        if(data.status === 'ok'){
            if(data.following){ btn.textContent='Untrack'; btn.classList.add('tracking'); }
            else               { btn.textContent='Track';  btn.classList.remove('tracking'); }
        }
    });
}

/* ── MULTI-MEDIA GALLERY ── */
function ccmUpdateMediaGallery(slide){
    var gallery = slide.querySelector('#ccm-media-gallery');
    if(!gallery) return;
    gallery.style.transform = 'translateX(-'+(_ccmMediaIndex*100)+'%)';
    gallery.querySelectorAll('.ccm-media-item').forEach(function(itemEl){
        var idx = parseInt(itemEl.dataset.idx, 10);
        var vid = itemEl.querySelector('video.ccm-media');
        if(vid){
            if(idx === _ccmMediaIndex) vid.play().catch(function(){});
            else vid.pause();
        }
        if(idx !== _ccmMediaIndex) ccmHidePausedControls(itemEl);
    });
    slide.querySelectorAll('.ccm-dash').forEach(function(d, i){
        d.classList.toggle('active', i === _ccmMediaIndex);
    });
    var counterEl = slide.querySelector('#ccm-media-counter');
    if(counterEl){
        var total = gallery.querySelectorAll('.ccm-media-item').length;
        counterEl.textContent = (_ccmMediaIndex+1)+'/'+total;
    }
    var activeItem = gallery.querySelector('.ccm-media-item[data-idx="'+_ccmMediaIndex+'"]');
    var typeTagEl  = slide.querySelector('#ccm-type-tag');
    if(activeItem && typeTagEl){
        var info = _ccmTypeTagInfo(activeItem.dataset.type);
        typeTagEl.innerHTML = '<i class="'+info.icon+'"></i><span>'+info.label+'</span>';
    }
}

function ccmMediaNext(){
    var slide = document.getElementById('ccm-slide');
    if(!slide) return;
    var mediaBox = slide.querySelector('#ccm-media-box');
    if(!mediaBox) return;
    var count = parseInt(mediaBox.dataset.count || '1', 10);
    if(_ccmMediaIndex < count - 1){ _ccmMediaIndex++; ccmUpdateMediaGallery(slide); }
}

function ccmMediaPrev(){
    var slide = document.getElementById('ccm-slide');
    if(!slide) return;
    if(_ccmMediaIndex > 0){ _ccmMediaIndex--; ccmUpdateMediaGallery(slide); }
}

function _ccmInitMediaSwipe(slide){
    var gallery  = slide.querySelector('#ccm-media-gallery');
    var mediaBox = slide.querySelector('#ccm-media-box');
    if(!gallery || !mediaBox) return;
    var mediaCount = parseInt(mediaBox.dataset.count || '1', 10);
    if(mediaCount <= 1) return;

    var startX = 0, startY = 0, dragging = false, horizontal = false;

    mediaBox.addEventListener('touchstart', function(e){
        startX = e.touches[0].clientX;
        startY = e.touches[0].clientY;
        dragging = true; horizontal = false;
        gallery.style.transition = 'none';
    }, {passive:true});

    mediaBox.addEventListener('touchmove', function(e){
        if(!dragging) return;
        var dx = e.touches[0].clientX - startX;
        var dy = e.touches[0].clientY - startY;
        if(!horizontal && Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 8) horizontal = true;
        if(horizontal){
            e.stopPropagation();
            gallery.style.transform = 'translateX(calc(-'+(_ccmMediaIndex*100)+'% + '+dx+'px))';
        }
    }, {passive:true});

    mediaBox.addEventListener('touchend', function(e){
        if(!dragging) return;
        dragging = false;
        gallery.style.transition = 'transform .28s cubic-bezier(.22,1,.36,1)';
        if(horizontal){
            e.stopPropagation();
            var dx = e.changedTouches[0].clientX - startX;
            var count = parseInt(mediaBox.dataset.count || '1', 10);
            if(Math.abs(dx) > 50){
                if(dx < 0 && _ccmMediaIndex < count - 1) _ccmMediaIndex++;
                else if(dx > 0 && _ccmMediaIndex > 0) _ccmMediaIndex--;
            }
        }
        ccmUpdateMediaGallery(slide);
    }, {passive:true});
}

/* ── RENDER — POST SLOT ── */
function _ccmRenderPost(item){
    var p = item.data;
    _ccmMemeId     = p.id;
    _ccmPostUserId = p.userId;
    _ccmMediaIndex = 0;

    var isOwnPost = String(p.userId) === String(currentUserId);
    var media = (Array.isArray(p.media) && p.media.length) ? p.media.slice() : [{file:p.file, type:p.type}];
    var mediaCount = media.length;

    var mediaSet   = ['image','video','gif','sticker','stickers'];
    var firstType  = (media[0].type || '').toLowerCase();
    var isTextOnly = mediaCount === 1 && mediaSet.indexOf(firstType) === -1;

    var feedTrackBtn = document.querySelector('.grid-track-btn[data-user="'+p.userId+'"]');
    var isFollowing  = feedTrackBtn ? feedTrackBtn.classList.contains('following') : !!p.isFollowing;

    var captionHtml = linkifyCaption(p.caption || '');
    var mediaBoxInner;

    if(isTextOnly){
        mediaBoxInner = '<div class="ccm-textonly-bg"></div>'+
                    '<div class="ccm-textonly-overlay"></div>'+
                    '<div class="ccm-center-caption-wrap">'+
                        '<div class="ccm-center-caption-text">'+captionHtml+'</div>'+
                    '</div>';
    } else {
        mediaBoxInner = '<div class="ccm-media-gallery" id="ccm-media-gallery">'+
            media.map(function(m, i){
                var t = (m.type||'').toLowerCase();
                var loadHook = i === 0 ? "ccmHideSkeleton(this.closest('.ccm-slide'))" : "";
                if(t === 'video'){
                    return '<div class="ccm-media-item" data-idx="'+i+'" data-type="video">'+
                        '<video src="'+m.file+'" '+(i===0?'autoplay':'')+' loop playsinline '+
                        'onloadedmetadata="ccmFixObjectFit(this);'+loadHook+'" class="ccm-media"></video>'+
                    '</div>';
                }
                return '<div class="ccm-media-item" data-idx="'+i+'" data-type="'+t+'">'+
                    '<img src="'+m.file+'" onerror="this.src=\'uploads/mee.jpeg\'" '+
                    'onload="ccmFixObjectFit(this);'+loadHook+'" class="ccm-media">'+
                '</div>';
            }).join('')+
        '</div>'+
        (mediaCount > 1
            ? '<div class="ccm-media-arrow ccm-media-arrow-left" onclick="event.stopPropagation();ccmMediaPrev()"><i class="ri-arrow-left-s-line"></i></div>'+
              '<div class="ccm-media-arrow ccm-media-arrow-right" onclick="event.stopPropagation();ccmMediaNext()"><i class="ri-arrow-right-s-line"></i></div>'
            : '');
    }

    var typeInfo    = _ccmTypeTagInfo(isTextOnly ? 'text' : firstType);
    var typeTagHtml = '<div class="ccm-type-tag" id="ccm-type-tag"><i class="'+typeInfo.icon+'"></i><span>'+typeInfo.label+'</span></div>';
    var counterHtml = mediaCount > 1
        ? '<div class="ccm-media-counter" id="ccm-media-counter">1/'+mediaCount+'</div>'
        : '';
    var dashesHtml = (mediaCount > 1)
        ? '<div class="ccm-media-dashes" id="ccm-media-dashes">'+
            media.map(function(_, i){ return '<span class="ccm-dash'+(i===0?' active':'')+'"></span>'; }).join('')+
          '</div>'
        : '';

    var likeCount   = p.likeCount   !== undefined ? p.likeCount   : 0;
    var repostCount = p.repostCount !== undefined ? p.repostCount : 0;
    var favCount    = p.favCount    !== undefined ? p.favCount    : 0;
    var liked       = p.liked    || false;
    var reposted    = p.reposted || false;
    var faved       = p.faved    || false;

    var trackBtnHtml = !isOwnPost
        ? '<span class="ccm-user-dot">·</span>'+
          '<button class="ccm-track-btn '+(isFollowing?'following':'')+'" data-user="'+p.userId+'" '+
              'onclick="event.stopPropagation();ccmToggleTrack(this)">'+(isFollowing?'Untrack':'Track')+'</button>'
        : '';

    var isNewPost = false;
    if(p.time){
        var _pd = new Date((p.time||'').replace(' ','T'));
        if(!isNaN(_pd)){ isNewPost = (Date.now() - _pd.getTime()) <= (172800*1000); }
    }
    var displayName = isOwnPost ? 'You' : _ccmTruncateName(p.username);
    var nicknameHtml = (!isOwnPost && p.nickname) ? '<div class="ccm-nickname">@'+p.nickname+'</div>' : '';

    var socialProofHtml = '';
    if(Array.isArray(p.socialProof) && p.socialProof.length){
        socialProofHtml = '<div class="ccm-social-proof-strip">'+
            p.socialProof.slice(0,4).map(function(sp){
                var spPic   = sp.profile_pic || 'uploads/mee.jpeg';
                var isLike  = sp.action_type === 'like';
                var icon    = isLike ? 'ri-heart-3-fill' : 'ri-repeat-line';
                var color   = isLike ? '#f24455' : '#7a4aff';
                return '<a href="profile.php?user_id='+sp.id+'" onclick="event.stopPropagation()" class="ccm-sp-avatar-wrap">'+
                    '<img src="'+spPic+'" onerror="this.src=\'uploads/mee.jpeg\'" class="ccm-sp-avatar">'+
                    '<span class="ccm-sp-badge" style="background:'+color+';"><i class="'+icon+'"></i></span>'+
                '</a>';
            }).join('')+
        '</div>';
    }

    var bottomCaptionHtml = '';
    if(!isTextOnly){
        var words   = (p.caption || '').trim().split(/\s+/).filter(Boolean);
        var isLong  = words.length > 10;
        _ccmCaptionFullHtml = captionHtml;
        if(isLong){
            var truncPlain = words.slice(0, 10).join(' ');
            _ccmCaptionTruncHtml = linkifyCaption(truncPlain)+
                '<span class="ccm-more-toggle" onclick="event.stopPropagation();ccmToggleCaption()">'+
                    '<span class="ccm-ellipsis">...</span>more'+
                '</span>';
        } else {
            _ccmCaptionTruncHtml = captionHtml;
        }
        _ccmCaptionExpanded = false;
        bottomCaptionHtml = '<div class="ccm-caption-wrap">'+
              '<span class="ccm-caption-text" id="ccm-caption-text">'+_ccmCaptionTruncHtml+'</span>'+
          '</div>';
    }

    return (
        _ccmSkeletonHtml()+
        '<div class="ccm-media-box" id="ccm-media-box" data-count="'+mediaCount+'" '+
            (mediaCount > 1
                ? 'onclick="event.stopPropagation();ccmMediaBoxTap(this)"'
                : 'onclick="event.stopPropagation();ccmToggleVideoPlay(this)"')+
        '>'+ mediaBoxInner +'</div>'+

        '<div class="ccm-top-bar">'+
            '<div class="ccm-top-bar-row">'+
                '<div class="ccm-back-group">'+
                    '<button class="ccm-icon-btn ccm-back-btn" onclick="closeDiscoverModal()"><i class="ri-arrow-left-s-line"></i></button>'+
                    '<span class="ccm-brand-label">Zeems</span>'+
                    (p.time ? '<span class="ccm-brand-dot">·</span><span class="ccm-brand-date">'+_formatDate(p.time)+(isNewPost?' · <span class="ccm-new-tag">New</span>':'')+'</span>' : '')+
                '</div>'+
                '<button class="ccm-icon-btn ccm-search-btn" onclick="location.href=\'search.php?focus=1\'"><i class="ri-search-line"></i></button>'+
            '</div>'+
        '</div>'+

        '<div class="ccm-top-right-tags">'+ counterHtml + typeTagHtml +'</div>'+
        socialProofHtml+

        '<div class="ccm-rail">'+
            '<button id="ccm-like-btn" class="ccm-rail-btn '+(liked?'active':'')+'" onclick="ccmAction(\'like\')">'+
                '<i class="ri-heart-3-fill"></i><span id="ccm-like-count" class="ccm-rail-count">'+likeCount+'</span>'+
            '</button>'+
            '<button id="ccm-repost-btn" class="ccm-rail-btn '+(reposted?'active':'')+'" onclick="ccmAction(\'repost\')">'+
                '<i class="ri-repeat-line"></i><span id="ccm-repost-count" class="ccm-rail-count">'+repostCount+'</span>'+
            '</button>'+
            '<button id="ccm-fav-btn" class="ccm-rail-btn '+(faved?'active':'')+'" onclick="ccmAction(\'fav\')">'+
                '<i class="ri-bookmark-fill"></i><span id="ccm-fav-count" class="ccm-rail-count">'+favCount+'</span>'+
            '</button>'+
            (!isOwnPost
                ? '<a class="ccm-rail-btn ccm-rail-msg" href="messages.php?user_id='+p.userId+'" onclick="event.stopPropagation()">'+
                      '<img src="'+(p.pic||'uploads/mee.jpeg')+'" onerror="this.src=\'uploads/mee.jpeg\'" class="ccm-rail-msg-pic">'+
                  '</a>'
                : '')+
        '</div>'+

        '<div class="ccm-bottom">'+
            '<div class="ccm-user-row">'+
                '<img src="'+(p.pic||'uploads/mee.jpeg')+'" onerror="this.src=\'uploads/mee.jpeg\'" class="ccm-user-pic" onclick="location.href=\'profile.php?user_id='+p.userId+'\'">'+
                '<div class="ccm-user-info">'+
                    '<div class="ccm-user-line1">'+
                        '<span class="ccm-username" onclick="location.href=\'profile.php?user_id='+p.userId+'\'">'+displayName+'</span>'+
                        trackBtnHtml+
                    '</div>'+
                    nicknameHtml+
                '</div>'+
            '</div>'+
            bottomCaptionHtml+
            dashesHtml+
        '</div>'
    );
}

/* ── RENDER — AD SLOT ── */
function _ccmRenderAd(item){
    var ad  = item.data;
    var ext = (ad.meme_image||'').split('.').pop().toLowerCase();
    var isVideo = ['mp4','webm','mov'].indexOf(ext) !== -1;
    _ccmMemeId = null;

    var mediaHtml = isVideo
        ? '<video src="uploads/'+(ad.meme_image||'')+'" autoplay loop playsinline onloadedmetadata="ccmFixObjectFit(this);ccmHideSkeleton(this.closest(\'.ccm-slide\'))" class="ccm-media"></video>'
        : '<img src="uploads/'+(ad.meme_image||'')+'" onerror="this.src=\'uploads/mee.jpeg\'" onload="ccmFixObjectFit(this);ccmHideSkeleton(this.closest(\'.ccm-slide\'))" class="ccm-media">';

    var vbColors = ['ccm-visit-brand-red', 'ccm-visit-brand-blue', 'ccm-visit-brand-orange'];
    var vbClass  = vbColors[(item.adSlot||0) % vbColors.length];

    return (
        _ccmSkeletonHtml()+
        '<div class="ccm-media-box" onclick="event.stopPropagation();ccmToggleVideoPlay(this)">'+ mediaHtml +'</div>'+
        '<div class="ccm-top-bar">'+
            '<div class="ccm-top-bar-row">'+
                '<div class="ccm-back-group">'+
                    '<button class="ccm-icon-btn ccm-back-btn" onclick="closeDiscoverModal()"><i class="ri-arrow-left-s-line"></i></button>'+
                    '<span class="ccm-brand-label">Zeems</span>'+
                '</div>'+
                '<span class="ccm-ad-inline-tag"><i class="ri-advertisement-line"></i><span>Advertisement</span></span>'+
            '</div>'+
        '</div>'+
        '<div class="ccm-bottom ccm-no-rail">'+
            '<div class="ccm-user-row">'+
                '<img src="uploads/'+(ad.image||'')+'" onerror="this.src=\'uploads/mee.jpeg\'" class="ccm-user-pic">'+
                '<span class="ccm-username">'+(ad.title||'')+'</span>'+
            '</div>'+
            '<div class="ccm-caption-wrap">'+
                '<span class="ccm-caption-text">'+(ad.caption||'')+'</span>'+
            '</div>'+
            '<a href="'+(ad.link||'#')+'" target="_blank" class="ccm-visit-brand-btn '+vbClass+'" onclick="event.stopPropagation()">'+
                'Visit Brand <i class="ri-arrow-right-up-line"></i>'+
            '</a>'+
        '</div>'
    );
}

/* ── RENDER — SUGGESTED USERS SLOT ── */
function _ccmRenderSugg(item){
    var users = item.data.slice(0, 6);
    _ccmMemeId = null;
    var cardsHtml = users.map(function(u){
        var following = parseInt(u.is_following) > 0;
        return '<div class="ccm-sugg-card">'+
            '<a class="ccm-sugg-card-link" href="profile.php?user_id='+u.id+'" onclick="event.stopPropagation()">'+
                '<img src="'+(u.profile_pic||'uploads/mee.jpeg')+'" onerror="this.src=\'uploads/mee.jpeg\'">'+
                '<span class="ccm-sugg-card-name">'+u.username+'</span>'+
            '</a>'+
            '<button class="ccm-sugg-card-track '+(following?'tracking':'')+'" data-user="'+u.id+'" '+
                    'onclick="event.stopPropagation();toggleSuggTrack(this)">'+(following?'Untrack':'Track')+'</button>'+
        '</div>';
    }).join('');

    return (
        '<div class="ccm-panel-slide">'+
            '<div class="ccm-panel-topbar">'+
                '<button class="ccm-icon-btn ccm-back-btn" onclick="closeDiscoverModal()"><i class="ri-arrow-left-s-line"></i></button>'+
                '<span class="ccm-ad-badge" style="border-color:#ff2d55;color:#ff2d55;">SUGGESTED</span>'+
            '</div>'+
            '<div class="ccm-panel-inner">'+
                '<div class="ccm-sugg-header">'+
                    '<div class="ccm-sugg-title">Suggested for you</div>'+
                    '<div class="ccm-sugg-subtitle">Accounts you might like</div>'+
                '</div>'+
                '<div class="ccm-sugg-grid">'+cardsHtml+'</div>'+
            '</div>'+
        '</div>'
    );
}

function _ccmRenderSlot(index){
    if(index < 0 || index >= ccmFeed.length) return;
    var item  = ccmFeed[index];
    var inner = document.getElementById('ccm-inner');
    inner.querySelectorAll('video').forEach(function(v){ v.pause(); v.src=''; });

    var html = '';
    if     (item.type === 'post') html = _ccmRenderPost(item);
    else if(item.type === 'ad')   html = _ccmRenderAd(item);
    else if(item.type === 'sugg') html = _ccmRenderSugg(item);

    inner.innerHTML = '<div class="ccm-slide" id="ccm-slide">'+html+'</div>';
    var slide = document.getElementById('ccm-slide');

    if(item.type === 'post'){ _ccmInitMediaSwipe(slide); }

    if(item.type === 'post' || item.type === 'ad'){
        var mediaImg = slide.querySelector('img.ccm-media');
        var textBg    = slide.querySelector('.ccm-textonly-bg');
        if(textBg){
            var probe = new Image();
            probe.onload  = function(){ ccmHideSkeleton(slide); };
            probe.onerror = function(){ ccmHideSkeleton(slide); };
            probe.src = 'uploads/Text.jpg';
            requestAnimationFrame(function(){ _ccmCheckCaptionOverflow(slide); });
        } else if(mediaImg && mediaImg.complete){
            ccmHideSkeleton(slide);
        }
        setTimeout(function(){ ccmHideSkeleton(slide); }, 2500);
    }
}

function openDiscoverModal(file, type, pic, userId, username, caption){
    if(!_ccmBuilt){ _buildCcmFeed(); }
    var idx = ccmFeed.findIndex(function(s){
        return s.type === 'post' && s.data.file === file && String(s.data.userId) === String(userId);
    });
    _ccmIndex = idx >= 0 ? idx : 0;
    _ccmRenderSlot(_ccmIndex);
    document.getElementById('discoverModal').classList.add('ccm-open');
    document.body.style.overflow = 'hidden';
}

function ccmLoadIndex(index){
    if(index < 0 || index >= ccmFeed.length) return;
    var slide = document.getElementById('ccm-slide');
    var dir   = index > _ccmIndex ? 1 : -1;
    if(!slide){ _ccmIndex = index; _ccmRenderSlot(_ccmIndex); return; }

    slide.style.transition = 'transform 0.24s ease, opacity 0.24s ease';
    slide.style.transform  = 'translateY('+(dir * -100)+'%)';
    slide.style.opacity    = '0';
    setTimeout(function(){
        _ccmIndex = index;
        _ccmRenderSlot(_ccmIndex);
        var newSlide = document.getElementById('ccm-slide');
        newSlide.style.transition = 'none';
        newSlide.style.transform  = 'translateY('+(dir * 100)+'%)';
        newSlide.style.opacity    = '0';
        requestAnimationFrame(function(){
            newSlide.style.transition = 'transform 0.24s ease, opacity 0.24s ease';
            newSlide.style.transform  = 'translateY(0)';
            newSlide.style.opacity    = '1';
        });
    }, 240);
}

function closeDiscoverModal(){
    document.getElementById('ccm-inner').querySelectorAll('video').forEach(function(v){ v.pause(); v.src=''; });
    document.getElementById('discoverModal').classList.remove('ccm-open');
    document.body.style.overflow = '';
    document.body.style.touchAction = '';
    _ccmMemeId     = null;
    _ccmPostUserId = null;
    _ccmMediaIndex = 0;
}

/* ── ACTIONS — likes/reposts/favorites via reposts.php ── */
function ccmAction(type){
    if(!_ccmMemeId) return;
    var actionMap  = { like:'like', repost:'repost', fav:'favorite' };
    var btnMap     = { like:'ccm-like-btn', repost:'ccm-repost-btn', fav:'ccm-fav-btn' };
    var cntMap     = { like:'ccm-like-count', repost:'ccm-repost-count', fav:'ccm-fav-count' };
    var cntField   = { like:'likeCount', repost:'repostCount', fav:'favCount' };
    var stateField = { like:'liked', repost:'reposted', fav:'faved' };
    var feedSel    = { like:'.like-btn', repost:'.repost-btn', fav:'.fav-btn' };
    var respKey    = { like:'liked', repost:'reposted', fav:'favorited' };
    var iconMap    = { like:['ri-heart-fill','ri-heart-3-line'], repost:['ri-repeat-fill','ri-repeat-line'], fav:['ri-bookmark-fill','ri-bookmark-line'] };

    var memeId = _ccmMemeId;

    fetch('reposts.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action='+actionMap[type]+'&meme_id='+memeId
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        if(data.count === undefined) return;
        var isActive = data[respKey[type]];

        var cmBtn  = document.getElementById(btnMap[type]);
        var cmSpan = document.getElementById(cntMap[type]);
        if(cmBtn)  cmBtn.classList.toggle('active', isActive);
        if(cmSpan) cmSpan.textContent = data.count;

        var feedBtn = document.querySelector(feedSel[type]+'[data-id="'+memeId+'"]');
        if(feedBtn){
            feedBtn.classList.toggle('active', isActive);
            var icon = feedBtn.querySelector('i');
            if(icon) icon.className = isActive ? iconMap[type][0] : iconMap[type][1];
            var cnt = feedBtn.querySelector('.count');
            if(cnt) cnt.textContent = data.count;
        }

        var feedItem = peuraFeed.find(function(s){ return String(s.id) === String(memeId); });
        if(feedItem){
            feedItem[cntField[type]]   = data.count;
            feedItem[stateField[type]] = isActive;
        }

        if(type === 'repost' && !isActive && <?php echo (int)$current_user; ?> == <?php echo (int)$profile_id; ?>){
            var card = feedBtn ? feedBtn.closest('.card') : null;
            if(card) card.remove();
            closeDiscoverModal();
        }
    })
    .catch(function(err){ console.error('ccmAction failed:', err); });
}

/* ── Init on page load ── */
window.addEventListener('load', function(){ _loadCcmData(); });

/* ── Scroll / swipe / keyboard navigation ── */
document.getElementById('discoverModal').addEventListener('wheel', function(e){
    e.preventDefault();
    var mediaBox = e.target.closest && e.target.closest('#ccm-media-box');
    var mediaCount = mediaBox ? parseInt(mediaBox.dataset.count || '1', 10) : 1;
    if(mediaBox && mediaCount > 1 && Math.abs(e.deltaX) > Math.abs(e.deltaY)){
        if(e.deltaX > 0) ccmMediaNext(); else ccmMediaPrev();
        return;
    }
    if(e.deltaY > 0) ccmLoadIndex(_ccmIndex+1);
    else             ccmLoadIndex(_ccmIndex-1);
}, { passive:false });

var ccmTouchStartY = 0;
document.getElementById('discoverModal').addEventListener('touchstart', function(e){
    ccmTouchStartY = e.touches[0].clientY;
}, { passive:true });

document.getElementById('discoverModal').addEventListener('touchend', function(e){
    var diff = ccmTouchStartY - e.changedTouches[0].clientY;
    if(Math.abs(diff) > 40){
        if(diff > 0) ccmLoadIndex(_ccmIndex+1);
        else         ccmLoadIndex(_ccmIndex-1);
    }
}, { passive:true });

document.addEventListener('keydown', function(e){
    if(document.getElementById('discoverModal').classList.contains('ccm-open')){
        if(e.key === 'Escape')    closeDiscoverModal();
        if(e.key === 'ArrowDown') ccmLoadIndex(_ccmIndex+1);
        if(e.key === 'ArrowUp')   ccmLoadIndex(_ccmIndex-1);
        if(e.key === 'ArrowRight') ccmMediaNext();
        if(e.key === 'ArrowLeft')  ccmMediaPrev();
    }
});

window.addEventListener('resize', function(){
    var slide = document.getElementById('ccm-slide');
    if(slide && ccmFeed[_ccmIndex] && ccmFeed[_ccmIndex].type === 'post'){
        _ccmCheckCaptionOverflow(slide);
    }
});

/* ── GRID ACTION BUTTONS (like/repost/favorite) — kept in sync with modal ── */
var REPOST_TOGGLE_MAP = {
    'like':     { cntField:'likeCount',   modalBtn:'ccm-like-btn',   modalCount:'ccm-like-count'   },
    'repost':   { cntField:'repostCount', modalBtn:'ccm-repost-btn', modalCount:'ccm-repost-count' },
    'favorite': { cntField:'favCount',    modalBtn:'ccm-fav-btn',    modalCount:'ccm-fav-count'    }
};
var REPOST_ICON_MAP = {
    like:     ['ri-heart-fill','ri-heart-3-line'],
    repost:   ['ri-repeat-fill','ri-repeat-line'],
    favorite: ['ri-bookmark-fill','ri-bookmark-line']
};

function handleCardAction(action, btn){
    var map = REPOST_TOGGLE_MAP[action];
    var memeId = btn.dataset.id;

    fetch('', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action='+action+'&meme_id='+memeId
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        var activeKey = action==='like' ? 'liked' : (action==='repost' ? 'reposted' : 'favorited');
        var isActive = data[activeKey];

        btn.classList.toggle('active', isActive);
        var icon = btn.querySelector('i');
        if(icon) icon.className = isActive ? REPOST_ICON_MAP[action][0] : REPOST_ICON_MAP[action][1];
        var cnt = btn.querySelector('.count');
        if(cnt) cnt.textContent = data.count;

        if(typeof peuraFeed !== 'undefined'){
            var item = peuraFeed.find(function(p){ return String(p.id)===String(memeId); });
            if(item){
                item[map.cntField] = data.count;
                item[action==='like'?'liked':(action==='repost'?'reposted':'faved')] = isActive;
            }
        }

        if(typeof _ccmMemeId !== 'undefined' && String(_ccmMemeId) === String(memeId)){
            var mb = document.getElementById(map.modalBtn);
            var mc = document.getElementById(map.modalCount);
            if(mb) mb.classList.toggle('active', isActive);
            if(mc) mc.textContent = data.count;
        }

        if(action === 'repost' && !isActive && <?php echo (int)$current_user; ?> == <?php echo (int)$profile_id; ?>){
            btn.closest('.card').remove();
        }
    });
}

document.querySelectorAll('.like-btn').forEach(function(btn){
    btn.addEventListener('click', function(){ handleCardAction('like', this); });
});
document.querySelectorAll('.fav-btn').forEach(function(btn){
    btn.addEventListener('click', function(){ handleCardAction('favorite', this); });
});
document.querySelectorAll('.repost-btn').forEach(function(btn){
    btn.addEventListener('click', function(){ handleCardAction('repost', this); });
});
</script>
</body>
</html>