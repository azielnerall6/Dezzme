<?php
session_start();
include 'config.php';

$current_user = $_SESSION['user_id'] ?? 0;
if(!$current_user){
    header("Location: login.php");
    exit();
}

$profile_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : $current_user;
$user = $conn->query("SELECT username, profile_pic FROM users WHERE id=$profile_id")->fetch_assoc();

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
";

if($search){
    $search_safe = $conn->real_escape_string($search);
    $query .= " AND m.caption LIKE '%$search_safe%'";
}
$query .= " ORDER BY r.id DESC";
$reposts = $conn->query($query);

$reposts_count = $conn->query("SELECT COUNT(*) as cnt FROM reposts WHERE user_id=$profile_id")->fetch_assoc()['cnt'];

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
<title><?php echo htmlspecialchars($user['username']); ?>'s Reposts | Peura</title>
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
    background-color: var(--ink);
    background-image:
        radial-gradient(ellipse 80% 60% at 10% 0%,  rgba(0,60,255,0.18)  0%, transparent 60%),
        radial-gradient(ellipse 40% 40% at 50% 50%,  rgba(0,30,120,0.12)  0%, transparent 70%),
        radial-gradient(ellipse 40% 40% at 50% 50%,  rgba(0,30,120,0.12)  0%, transparent 70%);
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
    top: 20px;
    left: 20px;
    z-index: 200;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 0;
    border-radius: 0;
    background: none;
    border: none;
    backdrop-filter: none;
    -webkit-backdrop-filter: none;
    color: var(--text);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    box-shadow: none;
    transition: opacity 0.2s ease, transform 0.2s ease;
}
.back-btn i { font-size: 16px; }

.page-header {
    text-align: center;
    padding: 100px 20px 30px;
}

.header-avatar {
    width: 110px;
    height: 110px;
    border-radius: 50%;
    border: 3px solid var(--red);
    object-fit: cover;
    box-shadow: 0 0 30px var(--red-glow), 0 10px 40px rgba(0,0,0,0.5);
    margin-bottom: 16px;
    transition: transform 0.4s ease, box-shadow 0.4s ease;
    cursor: pointer;
}
.header-avatar:hover {
    transform: scale(1.07);
    box-shadow: 0 0 45px var(--red-glow);
}

.page-header h1 {
    font-size: clamp(1.4rem, 4vw, 2rem);
    font-weight: 700;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    margin-bottom: 6px;
}
.page-header h1 i { color: var(--red); font-size: 1.2em; }

.upload-count {
    font-size: 13px;
    color: var(--text-3);
    font-weight: 400;
    letter-spacing: 0.5px;
}

.header-divider {
    width: 180px;
    height: 2px;
    margin: 14px auto 0;
    background: linear-gradient(90deg, transparent, var(--red), transparent);
    border-radius: 2px;
}

.search-wrap {
    display: flex;
    justify-content: center;
    padding: 24px 20px 10px;
}
.search-inner {
    position: relative;
    width: 100%;
    max-width: 560px;
}
.search-inner input {
    width: 100%;
    padding: 13px 50px 13px 20px;
    border-radius: var(--radius-xl);
    border: 1px solid var(--border-2);
    background: var(--surface-2);
    backdrop-filter: blur(14px);
    color: var(--text);
    font-size: 14px;
    font-family: 'Poppins', sans-serif;
    outline: none;
    transition: all 0.3s ease;
}
.search-inner input::placeholder { color: var(--text-3); }
.search-inner input:focus {
    border-color: var(--red-glow);
    background: rgba(255,45,45,0.06);
    box-shadow: 0 0 0 3px var(--red-dim);
}
.search-inner button {
    position: absolute;
    right: 14px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: var(--text-3);
    font-size: 18px;
    cursor: pointer;
    transition: color 0.2s;
}
.search-inner button:hover { color: var(--red); }

@media (max-width:768px){
    .search-inner input { padding:10px 46px 10px 16px; font-size:13px; border-radius:18px; }
    .search-inner button { font-size:16px; right:12px; }
}
@media (max-width:480px){
    .search-inner input { padding:8px 42px 8px 14px; font-size:12px; border-radius:16px; }
    .search-inner button { font-size:15px; right:10px; }
}
@media (max-width:360px){
    .search-inner input { padding:7px 38px 7px 12px; font-size:11px; border-radius:14px; }
    .search-inner button { font-size:14px; right:9px; }
}

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
.card:hover {
    transform: translateY(-6px) scale(1.01);
}

.media-box {
    position: relative;
    width: 100%;
    overflow: hidden;
}
.media-box img,
.media-box video {
    width: 100%;
    height: 220px;
    object-fit: cover;
    display: block;
    transition: transform 0.4s ease;
    cursor: pointer;
}
.card:hover .media-box img,
.card:hover .media-box video {
    transform: scale(1.03);
}

.top-bar-overlay {
    position: absolute;
    top: 12px;
    left: 12px;
    z-index: 5;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 0;
    border-radius: 0;
    background: none;
    backdrop-filter: none;
    -webkit-backdrop-filter: none;
}
.top-bar-overlay img {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    object-fit: cover;
    cursor: pointer;
    border: 2px solid var(--red);
    box-shadow: 0 0 10px rgba(255,45,45,0.25);
}
.top-bar-overlay .uname {
    color: white;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    text-shadow: 0 2px 8px rgba(0,0,0,0.55);
}

@media (max-width:768px){
    .top-bar-overlay { top:12px; left:12px; gap:8px; }
    .top-bar-overlay img { width:30px; height:30px; }
    .top-bar-overlay .uname { font-size:14px; }
}
@media (max-width:480px){
    .top-bar-overlay { top:10px; left:10px; gap:7px; }
    .top-bar-overlay img { width:27px; height:27px; border-width:2px; }
    .top-bar-overlay .uname { font-size:12px; }
}
@media (max-width:360px){
    .top-bar-overlay { top:9px; left:9px; gap:6px; }
    .top-bar-overlay img { width:24px; height:24px; }
    .top-bar-overlay .uname { font-size:11px; }
}

.new-badge {
    position: absolute;
    top: 10px; left: 10px;
    padding: 3px 9px;
    border-radius: 8px;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 1px;
    color: #fff;
    background: rgba(255,45,45,0.18);
    border: 1px solid rgba(255,45,45,0.35);
    backdrop-filter: blur(10px);
    z-index: 5;
}

.caption {
    padding: 10px 14px 8px;
    font-size: 12px;
    color: var(--text-2);
    line-height: 1.4;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.actions {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 0 10px 12px;
    flex-wrap: wrap;
}
.actions button {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 11px;
    border-radius: 20px;
    background: rgba(120, 90, 255, 0.14);
    border: 1px solid rgba(170, 150, 255, 0.10);
    color: var(--text-2);
    font-size: 12px;
    font-family: 'Poppins', sans-serif;
    cursor: pointer;
    transition: all 0.25s ease;
    white-space: nowrap;
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
}
.actions button:hover {
    background: rgba(120, 90, 255, 0.18);
    border-color: transparent;
    color: #fff;
    transform: translateY(-1px);
}
.actions button:hover i { color: var(--red); }
.actions button.active {
    background: rgba(120, 90, 255, 0.22);
    border-color: transparent;
    color: #d9d2ff;
}
.actions button.active i { color: var(--red); }
.actions button i { font-size: 14px; }

.empty-state {
    grid-column: 1 / -1;
    text-align: center;
    padding: 60px 20px;
    color: var(--text-3);
}
.empty-state i {
    font-size: 3rem;
    display: block;
    margin-bottom: 12px;
    color: var(--red-dim);
}

#memeModal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 9999;
    background: rgba(0,0,0,0.92);
    backdrop-filter: blur(12px);
    align-items: center;
    justify-content: center;
    padding: 20px;
    flex-direction: column;
}
#memeModal.open { display: flex; }
#modal-card {
    background: rgba(21,20,20,0.78);
    border: 1px solid var(--border-2);
    border-radius: var(--radius-lg);
    width: 100%;
    max-width: 440px;
    overflow: hidden;
}
#modal-card .modal-top {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
}
#modal-card .modal-top img {
    width: 36px; height: 36px;
    border-radius: 50%;
    border: 2px solid var(--red);
    object-fit: cover;
    cursor: pointer;
}
#modal-card .modal-top span {
    font-size: 13px;
    font-weight: 700;
    color: var(--red);
    cursor: pointer;
}
#modal-media { width: 100%; max-height: 58vh; overflow: hidden; }
#modal-media img,
#modal-media video {
    width: 100%;
    max-height: 58vh;
    object-fit: contain;
    display: block;
}
#modal-caption {
    padding: 12px 16px;
    font-size: 12.5px;
    color: var(--text-2);
    line-height: 1.5;
    border-top: 1px solid var(--border);
    word-break: break-word;
    white-space: pre-wrap;
    max-height: 90px;
    overflow-y: auto;
}
#modal-close {
    width: 100%;
    padding: 11px;
    background: rgba(21,20,20,0.78);
    border: none;
    border-top: 1px solid var(--border);
    color: var(--text-2);
    font-size: 13px;
    font-family: 'Poppins', sans-serif;
    cursor: pointer;
    transition: background 0.2s, color 0.2s;
}
#modal-close:hover { color: #fff; }
.modal-scroll-hint {
    margin-top: 10px;
    font-size: 11px;
    color: var(--text-3);
    text-align: center;
}

/* ── RESPONSIVE ── */
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
    #memeModal { padding:10px; align-items:flex-end; }
    #modal-card { border-radius:20px 20px 12px 12px; max-height:92vh; overflow-y:auto; }
    #modal-media { max-height:44vh; }
    #modal-media img, #modal-media video { max-height:44vh; }
    #modal-caption { font-size:13px; line-height:1.4; }
    .page-header { margin-bottom:5px; }
    .search-wrap { padding-bottom:5px; }
    .feed-wrapper { transform:translateY(-65px); }
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
    #modal-caption { font-size:12px; line-height:1.35; }
    .feed-wrapper { transform:translateY(-50px); }
}

@media (max-width: 360px) {
    .grid { gap:8px; padding:10px 10px 80px; }
    .media-box img, .media-box video { height:118px; }
    .page-header h1 { font-size:0.95rem; }
    #modal-caption { font-size:11px; line-height:1.3; }
    .feed-wrapper { transform:translateY(-55px); }
}

.top-bar-overlay{
    position:absolute;
    top:12px;
    left:12px;
    z-index:5;

    display:flex;
    align-items:center;
    gap:8px;

    padding:0;
    border-radius:0;

    background:none;

    backdrop-filter:none;
    -webkit-backdrop-filter:none;
}

.top-bar-overlay img{
    width:28px;
    height:28px;

    border-radius:50%;
    object-fit:cover;
    cursor:pointer;

    border:2px solid var(--red);

    box-shadow:0 0 10px rgba(255,45,45,0.25);
}

.top-bar-overlay .uname{
    color:white;
    font-size:13px;
    font-weight:600;
    cursor:pointer;

    text-shadow:0 2px 8px rgba(0,0,0,0.55);
}

/* ── TABLET ── */
@media (max-width:768px){

    .top-bar-overlay{
        top:12px;
        left:12px;
        gap:8px;
    }

    .top-bar-overlay img{
        width:30px;
        height:30px;
    }

    .top-bar-overlay .uname{
        font-size:14px;
    }

}

/* ── MOBILE ── */
@media (max-width:480px){

    .top-bar-overlay{
        top:10px;
        left:10px;
        gap:7px;
    }

    .top-bar-overlay img{
        width:27px;
        height:27px;
        border-width:2px;
    }

    .top-bar-overlay .uname{
        font-size:12px;
    }

}

/* ── SMALL PHONES ── */
@media (max-width:360px){

    .top-bar-overlay{
        top:9px;
        left:9px;
        gap:6px;
    }

    .top-bar-overlay img{
        width:24px;
        height:24px;
    }

    .top-bar-overlay .uname{
        font-size:11px;
    }
}
#modal-like-btn.active i,
#modal-fav-btn.active i,
#modal-repost-btn.active i {
    color: var(--red);
}
</style>
</head>
<body>

<canvas id="particles"></canvas>

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
<?php if($reposts->num_rows === 0): ?>
    <div class="empty-state">
        <i class="ri-repeat-line"></i>
        No reposts found<?php echo $search ? " for \"".htmlspecialchars($search)."\"" : ""; ?>
    </div>
<?php else: ?>
<script>var rFeed = [];</script>
<?php while($r = $reposts->fetch_assoc()):
    $created  = strtotime($r['created_at'] ?? 'now');
    $diff     = time() - $created;
    $is_new   = $diff <= 86400;
    $modal_file = ($r['type'] === 'text' || empty($r['file_name']) || $r['file_name'] === '0') ? 'uploads/Text.jpg' : 'uploads/'.$r['file_name'];
    $sc = addslashes(htmlspecialchars($r['caption']));
    $su = addslashes(htmlspecialchars($r['creator_name']));
    $sp = addslashes($r['creator_pic'] ?: 'uploads/default.jpg');
    $sf = addslashes($r['file_name']);
    $sm = addslashes($modal_file);
    $liked     = $conn->query("SELECT id FROM likes WHERE user_id=$current_user AND meme_id={$r['id']}")->num_rows > 0;
    $favorited = $conn->query("SELECT id FROM favorites WHERE user_id=$current_user AND meme_id={$r['id']}")->num_rows > 0;
    $reposted  = $conn->query("SELECT id FROM reposts WHERE user_id=$current_user AND meme_id={$r['id']}")->num_rows > 0;
?>
<script>
rFeed.push({ id:<?= (int)$r['id'] ?>, file:'<?= $sm ?>', type:'<?= addslashes($r['type']) ?>', pic:'<?= $sp ?>', userId:<?= (int)$r['uploaded_by'] ?>, username:'<?= $su ?>', caption:'<?= $sc ?>' });
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
        </div>

        <?php if($r['type'] == 'image'): ?>
            <img src="uploads/<?= $sf ?>" onclick="openModal(<?= $r['id'] ?>)">
        <?php elseif($r['type'] == 'video'): ?>
            <video src="uploads/<?= $sf ?>" autoplay muted loop onclick="openModal(<?= $r['id'] ?>)"></video>
        <?php else: ?>
            <img src="uploads/Text.jpg" onclick="openModal(<?= $r['id'] ?>)">
        <?php endif; ?>

        <?php if($is_new): ?>
            <div class="new-badge">NEW</div>
        <?php endif; ?>

    </div>

    <div class="caption"><?= htmlspecialchars($r['caption']) ?></div>

    <div class="actions">
        <button class="like-btn <?= $liked ? 'active' : '' ?>" data-id="<?= $r['id'] ?>">
    <i class="<?= $liked ? 'ri-heart-fill' : 'ri-heart-3-line' ?>"></i> <?= $r['like_count'] ?>
</button>
<button class="fav-btn <?= $favorited ? 'active' : '' ?>" data-id="<?= $r['id'] ?>">
    <i class="<?= $favorited ? 'ri-bookmark-fill' : 'ri-bookmark-line' ?>"></i> <?= $r['favorite_count'] ?>
</button>
<button class="repost-btn <?= $reposted ? 'active' : '' ?>" data-id="<?= $r['id'] ?>">
    <i class="<?= $reposted ? 'ri-repeat-fill' : 'ri-repeat-line' ?>"></i> <?= $r['repost_count'] ?>
</button>
        <button onclick="window.location.href='remix_upload.php?meme_id=<?= $r['id'] ?>'">
            <i class="ri-share-forward-line"></i>
        </button>
        <button onclick="window.location.href='download.php?file=<?= urlencode($r['file_name']) ?>'">
            <i class="ri-download-line"></i>
        </button>
    </div>
</div>
<?php endwhile; ?>
<?php endif; ?>
</div>
</div>

<!-- MODAL -->
<div id="memeModal" onclick="if(event.target===this)closeModal()">
    <div id="modal-card">
        <div class="modal-top">
            <img id="modal-pic" src="" onclick="goProfile()">
            <span id="modal-user" onclick="goProfile()"></span>
        </div>
        <div id="modal-media"></div>
        <div id="modal-caption"></div>

        <!-- ACTION BUTTONS INSIDE MODAL -->
        <div id="modal-actions" style="
            display:flex;
            align-items:center;
            gap:6px;
            padding:10px 14px;
            border-top:1px solid rgba(255,255,255,0.07);
            flex-wrap:wrap;
        ">
            <button id="modal-like-btn" onclick="modalAction('like')" style="
                display:inline-flex; align-items:center; gap:5px;
                padding:7px 13px; border-radius:20px;
                background:rgba(120,90,255,0.14);
                border:1px solid rgba(170,150,255,0.10);
                color:rgba(220,224,255,0.65);
                font-size:13px; font-family:'Poppins',sans-serif;
                cursor:pointer; transition:all 0.25s ease;
                backdrop-filter:blur(12px);
            ">
                <i class="ri-heart-3-line" style="font-size:15px;"></i>
                <span id="modal-like-count">0</span>
            </button>

            <button id="modal-fav-btn" onclick="modalAction('favorite')" style="
                display:inline-flex; align-items:center; gap:5px;
                padding:7px 13px; border-radius:20px;
                background:rgba(120,90,255,0.14);
                border:1px solid rgba(170,150,255,0.10);
                color:rgba(220,224,255,0.65);
                font-size:13px; font-family:'Poppins',sans-serif;
                cursor:pointer; transition:all 0.25s ease;
                backdrop-filter:blur(12px);
            ">
                <i class="ri-bookmark-line" style="font-size:15px;"></i>
                <span id="modal-fav-count">0</span>
            </button>

            <button id="modal-repost-btn" onclick="modalAction('repost')" style="
                display:inline-flex; align-items:center; gap:5px;
                padding:7px 13px; border-radius:20px;
                background:rgba(120,90,255,0.14);
                border:1px solid rgba(170,150,255,0.10);
                color:rgba(220,224,255,0.65);
                font-size:13px; font-family:'Poppins',sans-serif;
                cursor:pointer; transition:all 0.25s ease;
                backdrop-filter:blur(12px);
            ">
                <i class="ri-repeat-line" style="font-size:15px;"></i>
                <span id="modal-repost-count">0</span>
            </button>

            <button id="modal-remix-btn" onclick="goRemix()" style="
                display:inline-flex; align-items:center; gap:5px;
                padding:7px 13px; border-radius:20px;
                background:rgba(120,90,255,0.14);
                border:1px solid rgba(170,150,255,0.10);
                color:rgba(220,224,255,0.65);
                font-size:13px; font-family:'Poppins',sans-serif;
                cursor:pointer; transition:all 0.25s ease;
                backdrop-filter:blur(12px);
            ">
                <i class="ri-share-forward-line" style="font-size:15px;"></i>
            </button>

            <button id="modal-download-btn" onclick="goDownload()" style="
                display:inline-flex; align-items:center; gap:5px;
                padding:7px 13px; border-radius:20px;
                background:rgba(120,90,255,0.14);
                border:1px solid rgba(170,150,255,0.10);
                color:rgba(220,224,255,0.65);
                font-size:13px; font-family:'Poppins',sans-serif;
                cursor:pointer; transition:all 0.25s ease;
                backdrop-filter:blur(12px);
            ">
                <i class="ri-download-line" style="font-size:15px;"></i>
            </button>
        </div>

        <button id="modal-close" onclick="closeModal()">Close</button>
    </div>
    <div class="modal-scroll-hint">scroll for next ↕</div>
</div>

<script>
/* ── PARTICLES ── */
const canvas = document.getElementById('particles');
const ctx    = canvas.getContext('2d');
canvas.width  = window.innerWidth;
canvas.height = window.innerHeight;
const pts = [];
for(let i=0;i<90;i++) pts.push({
    x: Math.random()*canvas.width,
    y: Math.random()*canvas.height,
    size: Math.random()*1.8+0.6,
    sx: (Math.random()-0.5)*0.4,
    sy: (Math.random()-0.5)*0.4
});
(function anim(){
    ctx.clearRect(0,0,canvas.width,canvas.height);
    pts.forEach(p=>{
        p.x+=p.sx; p.y+=p.sy;
        if(p.x<0||p.x>canvas.width)  p.sx*=-1;
        if(p.y<0||p.y>canvas.height) p.sy*=-1;
        ctx.beginPath();
        ctx.arc(p.x,p.y,p.size,0,Math.PI*2);
        ctx.fillStyle='rgba(200,210,255,0.55)';
        ctx.fill();
    });
    requestAnimationFrame(anim);
})();
window.addEventListener('resize',()=>{ canvas.width=window.innerWidth; canvas.height=window.innerHeight; });

/* ── ACTIONS ── */
document.querySelectorAll('.like-btn').forEach(btn=>{
    btn.addEventListener('click',()=>{
        const id = btn.dataset.id;
        fetch('',{ method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=like&meme_id='+id })
        .then(r=>r.json()).then(data=>{
            btn.classList.toggle('active', data.liked);
            btn.querySelector('i').className = data.liked ? 'ri-heart-fill' : 'ri-heart-3-line';
            btn.childNodes.forEach(n=>{ if(n.nodeType===3 && n.textContent.trim()) n.textContent=' '+data.count; });
        });
    });
});

document.querySelectorAll('.fav-btn').forEach(btn=>{
    btn.addEventListener('click',()=>{
        const id = btn.dataset.id;
        fetch('',{ method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=favorite&meme_id='+id })
        .then(r=>r.json()).then(data=>{
            btn.classList.toggle('active', data.favorited);
            btn.querySelector('i').className = data.favorited ? 'ri-bookmark-fill' : 'ri-bookmark-line';
            btn.childNodes.forEach(n=>{ if(n.nodeType===3 && n.textContent.trim()) n.textContent=' '+data.count; });
        });
    });
});

document.querySelectorAll('.repost-btn').forEach(btn=>{
    btn.addEventListener('click',()=>{
        const id = btn.dataset.id;
        fetch('',{ method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=repost&meme_id='+id })
        .then(r=>r.json()).then(data=>{
            btn.classList.toggle('active', data.reposted);
            btn.querySelector('i').className = data.reposted ? 'ri-repeat-fill' : 'ri-repeat-line';
            btn.childNodes.forEach(n=>{ if(n.nodeType===3 && n.textContent.trim()) n.textContent=' '+data.count; });
            if(!data.reposted && <?php echo $current_user; ?> == <?php echo $profile_id; ?>){
                btn.closest('.card').remove();
            }
        });
    });
});

/* ── MODAL ── */
let mIdx = 0, mProfileId = null, mCurrentId = null, mCurrentFile = null;

function openModal(id){
    mIdx = rFeed.findIndex(m=>m.id===id);
    if(mIdx<0) mIdx=0;
    renderModal(rFeed[mIdx]);
    document.getElementById('memeModal').classList.add('open');
    document.body.style.overflow='hidden';
}

function renderModal(d){
    mProfileId   = d.userId;
    mCurrentId   = d.id;
    mCurrentFile = d.file;

    document.getElementById('modal-pic').src             = d.pic;
    document.getElementById('modal-user').textContent    = d.username;
    document.getElementById('modal-caption').textContent = d.caption.replace(/\\n/g,'\n');

    const mb = document.getElementById('modal-media');
    const isText = !d.file || d.file.endsWith('Text.jpg') || d.type==='text';
    if(isText){ mb.innerHTML=''; mb.style.display='none'; }
    else if(d.type==='video'){
        mb.innerHTML=`<video src="${d.file}" autoplay muted loop playsinline style="width:100%;max-height:58vh;object-fit:contain;display:block;"></video>`;
        mb.style.display='block';
    } else {
        mb.innerHTML=`<img src="${d.file}" style="width:100%;max-height:58vh;object-fit:contain;display:block;">`;
        mb.style.display='block';
    }

    // sync buttons from card state
    const cardLike   = document.querySelector(`.like-btn[data-id="${d.id}"]`);
    const cardFav    = document.querySelector(`.fav-btn[data-id="${d.id}"]`);
    const cardRepost = document.querySelector(`.repost-btn[data-id="${d.id}"]`);

    const mLike   = document.getElementById('modal-like-btn');
    const mFav    = document.getElementById('modal-fav-btn');
    const mRepost = document.getElementById('modal-repost-btn');

    const likedActive   = cardLike   && cardLike.classList.contains('active');
    const favActive     = cardFav    && cardFav.classList.contains('active');
    const repostActive  = cardRepost && cardRepost.classList.contains('active');

    mLike.classList.toggle('active', likedActive);
    mLike.querySelector('i').className = likedActive ? 'ri-heart-fill' : 'ri-heart-3-line';
    document.getElementById('modal-like-count').textContent = cardLike ? cardLike.textContent.trim().replace(/\D/g,'') : '0';

    mFav.classList.toggle('active', favActive);
    mFav.querySelector('i').className = favActive ? 'ri-bookmark-fill' : 'ri-bookmark-line';
    document.getElementById('modal-fav-count').textContent = cardFav ? cardFav.textContent.trim().replace(/\D/g,'') : '0';

    mRepost.classList.toggle('active', repostActive);
    mRepost.querySelector('i').className = repostActive ? 'ri-repeat-fill' : 'ri-repeat-line';
    document.getElementById('modal-repost-count').textContent = cardRepost ? cardRepost.textContent.trim().replace(/\D/g,'') : '0';
}

function modalAction(action){
    if(!mCurrentId) return;
    fetch('', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:`action=${action}&meme_id=${mCurrentId}`
    })
    .then(r=>r.json())
    .then(data=>{
        if(action === 'like'){
            const btn = document.getElementById('modal-like-btn');
            btn.classList.toggle('active', data.liked);
            btn.querySelector('i').className = data.liked ? 'ri-heart-fill' : 'ri-heart-3-line';
            document.getElementById('modal-like-count').textContent = data.count;
            const cb = document.querySelector(`.like-btn[data-id="${mCurrentId}"]`);
            if(cb){
                cb.classList.toggle('active', data.liked);
                cb.querySelector('i').className = data.liked ? 'ri-heart-fill' : 'ri-heart-3-line';
                cb.childNodes.forEach(n=>{ if(n.nodeType===3&&n.textContent.trim()) n.textContent=' '+data.count; });
            }
        }
        if(action === 'favorite'){
            const btn = document.getElementById('modal-fav-btn');
            btn.classList.toggle('active', data.favorited);
            btn.querySelector('i').className = data.favorited ? 'ri-bookmark-fill' : 'ri-bookmark-line';
            document.getElementById('modal-fav-count').textContent = data.count;
            const cb = document.querySelector(`.fav-btn[data-id="${mCurrentId}"]`);
            if(cb){
                cb.classList.toggle('active', data.favorited);
                cb.querySelector('i').className = data.favorited ? 'ri-bookmark-fill' : 'ri-bookmark-line';
                cb.childNodes.forEach(n=>{ if(n.nodeType===3&&n.textContent.trim()) n.textContent=' '+data.count; });
            }
        }
        if(action === 'repost'){
            const btn = document.getElementById('modal-repost-btn');
            btn.classList.toggle('active', data.reposted);
            btn.querySelector('i').className = data.reposted ? 'ri-repeat-fill' : 'ri-repeat-line';
            document.getElementById('modal-repost-count').textContent = data.count;
            const cb = document.querySelector(`.repost-btn[data-id="${mCurrentId}"]`);
            if(cb){
                cb.classList.toggle('active', data.reposted);
                cb.querySelector('i').className = data.reposted ? 'ri-repeat-fill' : 'ri-repeat-line';
                cb.childNodes.forEach(n=>{ if(n.nodeType===3&&n.textContent.trim()) n.textContent=' '+data.count; });
            }
            if(!data.reposted && <?php echo $current_user; ?> == <?php echo $profile_id; ?>){
                const card = document.querySelector(`.repost-btn[data-id="${mCurrentId}"]`)?.closest('.card');
                if(card) card.remove();
                closeModal();
            }
        }
    });
}

function goRemix(){ if(mCurrentId) window.location.href='remix_upload.php?meme_id='+mCurrentId; }
function goDownload(){ if(mCurrentFile) window.location.href='download.php?file='+encodeURIComponent(mCurrentFile.replace('uploads/','')); }

function closeModal(){
    const v=document.querySelector('#modal-media video');
    if(v){v.pause();v.src='';}
    document.getElementById('memeModal').classList.remove('open');
    document.getElementById('modal-media').innerHTML='';
    document.body.style.overflow='';
}

function goProfile(){ if(mProfileId) window.location.href='profile.php?user_id='+mProfileId; }

function shiftModal(dir){
    const n=mIdx+dir;
    if(n<0||n>=rFeed.length) return;
    const card=document.getElementById('modal-card');
    card.style.transition='transform 0.22s ease,opacity 0.22s ease';
    card.style.transform=`translateY(${dir*-35}px)`;
    card.style.opacity='0';
    setTimeout(()=>{
        mIdx=n;
        const v=card.querySelector('video');
        if(v){v.pause();v.src='';}
        renderModal(rFeed[mIdx]);
        card.style.transition='none';
        card.style.transform=`translateY(${dir*35}px)`;
        card.style.opacity='0';
        requestAnimationFrame(()=>{
            card.style.transition='transform 0.22s ease,opacity 0.22s ease';
            card.style.transform='translateY(0)';
            card.style.opacity='1';
        });
    },220);
}

document.getElementById('memeModal').addEventListener('wheel',e=>{ e.preventDefault(); shiftModal(e.deltaY>0?1:-1); },{passive:false});
let ty=0;
document.getElementById('memeModal').addEventListener('touchstart',e=>{ ty=e.touches[0].clientY; },{passive:true});
document.getElementById('memeModal').addEventListener('touchend',e=>{
    const d=ty-e.changedTouches[0].clientY;
    if(Math.abs(d)>40) shiftModal(d>0?1:-1);
},{passive:true});
document.addEventListener('keydown',e=>{
    if(document.getElementById('memeModal').classList.contains('open')){
        if(e.key==='Escape') closeModal();
        if(e.key==='ArrowDown') shiftModal(1);
        if(e.key==='ArrowUp')   shiftModal(-1);
    }
});
</script>
</body>
</html>