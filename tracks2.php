<?php
session_start();
include 'config.php';

// ── Auth & IDs ─────────────────────────────────────────────
$current_user = (int)($_SESSION['user_id'] ?? 0);
if(!$current_user){ header("Location: login.php"); exit(); }

$profile_id = (isset($_GET['user_id']) && (int)$_GET['user_id'] > 0)
    ? (int)$_GET['user_id']
    : $current_user;

$is_own_profile = ($current_user === $profile_id);

// ── User info ──────────────────────────────────────────────
$user = $conn->query("SELECT id, username, profile_pic, nickname FROM users WHERE id=$profile_id LIMIT 1")->fetch_assoc();
if(!$user){ header("Location: 404.php"); exit(); }

// ── Handle POST actions FIRST (before any output) ──────────
if($_SERVER['REQUEST_METHOD'] === 'POST' && $current_user){
    header('Content-Type: application/json');
    $action  = $_POST['action'] ?? '';
    $user_id = (int)($_POST['user_id'] ?? 0);

    if($user_id === $current_user){
        echo json_encode(['status'=>'error','msg'=>'Not allowed']);
        exit();
    }

    $is_priv_target = false;
    if($user_id){
        $priv = $conn->query("SELECT is_fully_private FROM user_settings WHERE user_id=$user_id LIMIT 1")->fetch_assoc();
        $is_priv_target = !empty($priv['is_fully_private']);
    }

    if($action === 'toggle_follow' && $user_id){
        $exists = $conn->query("SELECT id FROM followers WHERE follower_id=$current_user AND following_id=$user_id")->num_rows;
        if($exists){
            $conn->query("DELETE FROM followers WHERE follower_id=$current_user AND following_id=$user_id");
        } else {
            // Private profiles can never be tracked directly — a request is required
            if($is_priv_target){
                echo json_encode(['status'=>'error','msg'=>'private','is_private'=>true]);
                exit();
            }
            $conn->query("INSERT INTO followers(follower_id,following_id) VALUES($current_user,$user_id)");
        }
        echo json_encode(['status'=>'ok','is_private'=>$is_priv_target]);

    } elseif($action === 'send_track_request' && $user_id){
        // Remove any old denied request first so INSERT IGNORE works cleanly
        $conn->query("DELETE FROM track_requests WHERE requester_id=$current_user AND target_id=$user_id AND status='denied'");
        $conn->query("INSERT IGNORE INTO track_requests(requester_id,target_id,status) VALUES($current_user,$user_id,'pending')");
        echo json_encode(['status'=>'ok','is_private'=>$is_priv_target]);

    } elseif($action === 'cancel_track_request' && $user_id){
        $conn->query("DELETE FROM track_requests WHERE requester_id=$current_user AND target_id=$user_id");
        echo json_encode(['status'=>'ok','is_private'=>$is_priv_target]);

    } else {
        echo json_encode(['status'=>'error','msg'=>'Unknown action']);
    }
    exit();
}

// ── Counts ────────────────────────────────────────────────
$followers_count = (int)($conn->query("SELECT COUNT(*) AS c FROM followers WHERE following_id=$profile_id")->fetch_assoc()['c'] ?? 0);
$following_count = (int)($conn->query("SELECT COUNT(*) AS c FROM followers WHERE follower_id=$profile_id")->fetch_assoc()['c'] ?? 0);

// ── Followers list ─────────────────────────────────────────
$followers = $conn->query("
    SELECT u.id, u.username, u.profile_pic, u.nickname
    FROM followers f
    JOIN users u ON f.follower_id = u.id
    WHERE f.following_id = $profile_id
    ORDER BY u.username ASC
");

// ── Following list ─────────────────────────────────────────
$following = $conn->query("
    SELECT u.id, u.username, u.profile_pic, u.nickname
    FROM followers f
    JOIN users u ON f.following_id = u.id
    WHERE f.follower_id = $profile_id
    ORDER BY u.username ASC
");

// ── Mutuals (only when viewing someone else's profile) ──────
$mutuals_count = 0;
$mutuals       = null;
if(!$is_own_profile){
    $mutuals = $conn->query("
        SELECT u.id, u.username, u.profile_pic, u.nickname
        FROM   followers f1
        JOIN   followers f2 ON f2.follower_id = f1.following_id
        JOIN   users     u  ON u.id           = f1.following_id
        WHERE  f1.follower_id  = $current_user
          AND  f2.following_id = $profile_id
          AND  f1.following_id != $current_user
          AND  f1.following_id != $profile_id
        ORDER BY u.username ASC
    ");
    $mutuals_count = $mutuals ? (int)$mutuals->num_rows : 0;
}

// ── Circle (own profile: people who follow each other) ──────
$circle_count = 0;
$circle       = null;
if($is_own_profile){
    $circle = $conn->query("
        SELECT u.id, u.username, u.profile_pic, u.nickname
        FROM   followers f1
        JOIN   followers f2
            ON  f2.follower_id  = f1.following_id
           AND  f2.following_id = $current_user
        JOIN   users u ON u.id = f1.following_id
        WHERE  f1.follower_id   = $current_user
          AND  f1.following_id != $current_user
        ORDER BY u.username ASC
    ");
    $circle_count = $circle ? (int)$circle->num_rows : 0;
}

// ── Track request states for every user the current viewer might see ──
$track_request_states = [];
$tr_res = $conn->query("
    SELECT target_id, status
    FROM track_requests
    WHERE requester_id = $current_user
");
if($tr_res){
    while($tr_row = $tr_res->fetch_assoc()){
        $track_request_states[(int)$tr_row['target_id']] = $tr_row['status'];
    }
}

// ── Which tab is open (validated so a stale ?type= never leaves nothing active) ──
$default_tab = $_GET['type'] ?? 'followers';
$valid_tabs  = ['followers', 'following'];
if(!$is_own_profile && $mutuals_count > 0) $valid_tabs[] = 'mutuals';
if($is_own_profile  && $circle_count  > 0) $valid_tabs[] = 'circle';
$active_tab = in_array($default_tab, $valid_tabs, true) ? $default_tab : 'followers';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($user['username']); ?> · Tracks</title>

<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">

<style>
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; font-family:'Poppins', sans-serif; }
button, a { cursor:pointer; outline:none; -webkit-tap-highlight-color:transparent; }

[class^="ri-"], [class*=" ri-"],
[class^="ri-"]::before, [class*=" ri-"]::before,
[class^="ri-"]::after,  [class*=" ri-"]::after {
    font-family:'remixicon' !important;
    font-style:normal; font-weight:normal; line-height:1;
    speak:none; text-transform:none;
    -webkit-font-smoothing:antialiased;
}

:root{
    --red:             #FF2D2D;
    --track-red:       #FF2D2D;
    --track-red-hover: #ff1a1a;

    /* page background — identical to the post modal */
    --bg:        linear-gradient(-45deg,#010218,#070924,#070924);

    --surface:   rgba(255,255,255,0.05);
    --line:      rgba(255,255,255,0.09);
    --line2:     rgba(255,255,255,0.14);
    --text:      #ffffff;
    --muted:     rgba(255,255,255,0.45);
    --sub:       rgba(255,255,255,0.62);

    --max-w:     580px;
    --topbar-h:  54px;
    --pad:       clamp(9px, 3vw, 16px);

    /* same action button metrics as the profile page */
    --rp-action-h:      clamp(25px, 7.2vw, 30px);
    --rp-action-radius: 4px;
    --rp-pill-radius:   5px;
}
@media (max-width:490px){ :root{ --topbar-h:48px; } }
@media (max-width:380px){ :root{ --topbar-h:44px; } }

html{ scroll-behavior:smooth; }

/* RESPONSIVE view: no scrollbars anywhere — scrolling still works.
   Normal view keeps its scrollbars. */
@media (max-width:768px){
    html, body, *{ scrollbar-width:none !important; -ms-overflow-style:none !important; }
    *::-webkit-scrollbar, html::-webkit-scrollbar, body::-webkit-scrollbar{ display:none !important; width:0 !important; height:0 !important; background:transparent !important; }
}

body{
    background:var(--bg);
    background-attachment:fixed;
    color:var(--text);
    min-height:100vh;
    display:flex; justify-content:center;
    overflow-x:hidden;
    -webkit-font-smoothing:antialiased;
}

/* ── centered lane with the two grey side lines (like the post modal) ── */
.tk-lane{
    width:100%; max-width:var(--max-w);
    min-height:100vh;
    display:flex; flex-direction:column;
}
@media (min-width:801px){
    .tk-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); }
}
@media (max-width:800px){ .tk-lane{ max-width:100%; } }

/* ══════════════════════════════════════
   TOPBAR — no visible gradient: it uses the same fixed page background,
   so it reads as part of the page while staying pinned.
══════════════════════════════════════ */
.tk-topbar{
    position:sticky; top:0; z-index:500;
    height:var(--topbar-h);
    display:flex; align-items:center; gap:2px;
    padding:0 16px 0 6px;
    background:var(--bg);
    background-attachment:fixed;
}
.tk-back{
    background:none; border:none; color:#fff;
    font-size:1.9rem;                       /* same as the back button on the profile page */
    display:flex; align-items:center; justify-content:center;
    opacity:.9; transition:opacity .2s, color .2s;
    text-decoration:none; padding:2px; flex-shrink:0;
}
.tk-back:hover{ opacity:1; color:var(--red); }
.tk-title{
    font-size:.92rem; font-weight:700; color:#fff; letter-spacing:-.1px;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    min-width:0; flex:1;
}
@media (max-width:490px){
    .tk-topbar{ padding:0 12px 0 4px; }
    .tk-back{ font-size:1.75rem; }
    .tk-title{ font-size:.84rem; }
}
@media (max-width:380px){
    .tk-topbar{ padding:0 10px 0 3px; }
    .tk-back{ font-size:1.6rem; }
    .tk-title{ font-size:.8rem; }
}

/* ══════════════════════════════════════
   TABS — docked under the topbar, sliding white underline
══════════════════════════════════════ */
.tk-tabs{
    position:sticky; top:calc(var(--topbar-h) - 1px); z-index:400;
    display:flex; align-items:stretch;
    background:var(--bg);
    background-attachment:fixed;
    border-top:1px solid var(--line);
    border-bottom:1px solid var(--line);
    overflow-x:auto; scrollbar-width:none; -ms-overflow-style:none;
    -webkit-overflow-scrolling:touch;
}
.tk-tabs::-webkit-scrollbar{ display:none; }
.tk-tabs-track{ position:relative; display:flex; align-items:stretch; flex:1 0 auto; }
.tk-tab-ind{
    position:absolute; bottom:0; left:0; height:2px; width:0;
    background:#fff; border-radius:2px 2px 0 0;
    transition:left .28s cubic-bezier(.4,0,.2,1), width .28s cubic-bezier(.4,0,.2,1), opacity .2s ease;
    opacity:0; pointer-events:none;
}
.tk-tab-ind.ready{ opacity:1; }
.tk-tab{
    flex:1 0 auto; min-width:72px;
    display:flex; flex-direction:column; align-items:center; justify-content:center; gap:2px;
    padding:10px 14px; background:none; border:none;
    color:rgba(255,255,255,0.5); transition:color .2s ease;
}
.tk-tab:hover{ color:rgba(255,255,255,0.85); }
.tk-tab.active{ color:#fff; }
.tk-tab-count{ font-size:.9rem; font-weight:700; color:#fff; line-height:1.1; }
.tk-tab-label{ font-size:.66rem; font-weight:500; letter-spacing:.02em; }
@media (max-width:490px){
    .tk-tab{ padding:9px 12px; min-width:60px; }
    .tk-tab-count{ font-size:.82rem; }
    .tk-tab-label{ font-size:.6rem; }
}
@media (max-width:380px){
    .tk-tab{ padding:8px 10px; min-width:54px; }
    .tk-tab-count{ font-size:.76rem; }
    .tk-tab-label{ font-size:.56rem; }
}

/* ── Search ── */
.tk-search{
    position:relative; display:flex; align-items:center;
    padding:12px var(--pad) 4px;
}
.tk-search i{
    position:absolute; left:calc(var(--pad) + 11px);
    color:var(--muted); font-size:1rem; pointer-events:none;
}
.tk-search input{
    width:100%; height:clamp(30px, 8vw, 36px);
    background:var(--surface); border:1px solid var(--line2);
    border-radius:var(--rp-pill-radius);
    padding:0 12px 0 34px;
    font-size:.76rem; color:var(--text); outline:none;
    transition:border-color .2s, background .2s, box-shadow .2s;
    -webkit-appearance:none; appearance:none;
}
.tk-search input::placeholder{ color:var(--muted); }
.tk-search input:focus{
    border-color:rgba(255,45,45,.7); background:rgba(255,255,255,0.07);
    box-shadow:0 0 0 3px rgba(255,45,45,.12);
}
@media (max-width:490px){
    .tk-search input{ font-size:.7rem; padding-left:32px; }
    .tk-search i{ left:calc(var(--pad) + 10px); font-size:.92rem; }
}

/* ── Lists ── */
.user-list{ display:none; flex-direction:column; width:100%; padding:0 0 40px; }
.user-list.active{ display:flex; animation:tkFade .3s ease both; }
@keyframes tkFade{ from{ opacity:0; transform:translateY(6px); } to{ opacity:1; transform:none; } }

.context-note{
    font-size:.7rem; color:var(--muted); line-height:1.55;
    padding:12px var(--pad) 10px;
    border-bottom:1px solid var(--line);
}
@media (max-width:490px){ .context-note{ font-size:.64rem; } }

/* ── User row ── */
.user-row{
    display:flex; align-items:center; gap:clamp(10px, 3vw, 14px);
    padding:12px var(--pad);
    border-bottom:1px solid rgba(255,255,255,0.06);
    cursor:pointer; transition:background .15s;
    color:var(--text); text-decoration:none;
}
.user-row:last-of-type{ border-bottom:none; }
.user-row:hover{ background:rgba(255,255,255,0.03); }

.user-avatar{
    width:clamp(38px, 10.5vw, 46px); height:clamp(38px, 10.5vw, 46px);
    border-radius:50%; object-fit:cover; flex-shrink:0;
    border:2px solid var(--red);
}
@media (max-width:490px){ .user-avatar{ border-width:1.5px; } }

.user-info{ flex:1; min-width:0; }
.user-info .uname{
    display:flex; align-items:center; gap:5px; flex-wrap:nowrap;
    font-size:.84rem; font-weight:600; color:var(--text);
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.user-info .uname i{ font-size:.78rem; color:rgba(255,255,255,0.45); flex-shrink:0; }
.user-info .unick{
    font-size:.7rem; color:var(--muted); margin-top:2px;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
@media (max-width:490px){
    .user-info .uname{ font-size:.78rem; gap:4px; }
    .user-info .unick{ font-size:.64rem; }
}
@media (max-width:380px){
    .user-info .uname{ font-size:.74rem; }
    .user-info .unick{ font-size:.6rem; }
}

.new-post-badge{
    display:inline-block; color:var(--red);
    font-size:.62rem; font-weight:700; letter-spacing:.3px;
    margin-left:4px; vertical-align:middle; line-height:1;
    position:relative; top:-1px;
}
.new-post-badge::before{ content:'• '; font-size:.7rem; }

/* ══════════════════════════════════════
   TRACK / UNTRACK / REQUESTED — same look as the profile page buttons:
   rectangular, 4px corners, Track = solid red + black bold text,
   Untrack = frosted grey + white, Requested = dim grey.
   A little smaller so they sit nicely in a row.
══════════════════════════════════════ */
.tk-btn{
    display:flex; align-items:center; justify-content:center; gap:6px;
    flex-shrink:0; min-width:96px;
    height:var(--rp-action-h); padding:0 12px;
    border:none; border-radius:var(--rp-action-radius);
    font-size:.68rem; font-weight:600; line-height:1; color:#fff;
    white-space:nowrap; overflow:hidden;
    transition:background .2s ease, transform .15s ease;
}
.tk-btn:active{ transform:scale(0.96); }
.tk-btn:disabled{ cursor:default; }

.tk-btn.track{ background:var(--track-red); color:#000; font-weight:700; }
.tk-btn.track:hover{ background:var(--track-red-hover); }

.tk-btn.untrack{
    background:rgba(255,255,255,0.1);
    backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    color:#fff; font-weight:600;
}
.tk-btn.untrack:hover{ background:rgba(255,255,255,0.2); }

.tk-btn.pending{
    background:rgba(255,255,255,0.1);
    color:rgba(255,255,255,0.45); font-weight:600;
}
.tk-btn.pending:hover{ background:rgba(255,255,255,0.16); }

@media (max-width:490px){
    .tk-btn{ min-width:82px; font-size:.6rem; padding:0 9px; }
}
@media (max-width:380px){
    .tk-btn{ min-width:74px; font-size:.56rem; padding:0 7px; }
}

.spinner-icon{
    display:inline-flex; align-items:center; justify-content:center;
    gap:3px; height:16px; vertical-align:middle;
}
.spinner-icon span{
    display:block; width:3px; background:currentColor; border-radius:2px;
    animation:btn-bar 1s ease-in-out infinite;
}
.spinner-icon span:nth-child(1){ animation-delay:0s; }
.spinner-icon span:nth-child(2){ animation-delay:.15s; }
.spinner-icon span:nth-child(3){ animation-delay:.3s; }
.spinner-icon span:nth-child(4){ animation-delay:.45s; }
@keyframes btn-bar{
    0%,100%{ height:4px; opacity:.4; }
    50%    { height:10px; opacity:1; }
}

/* ── Empty state ── */
.empty-state{
    padding:48px 20px 32px; text-align:center; color:var(--muted);
    display:flex; flex-direction:column; align-items:center; gap:10px;
}
.empty-state i{ font-size:2.1rem; color:var(--red); opacity:.85; }
.empty-state span{ font-size:.8rem; }
@media (max-width:490px){ .empty-state span{ font-size:.74rem; } }

/* ══════════════════════════════════════
   SKELETON — same lane, same lines
══════════════════════════════════════ */
#page-skeleton{
    position:fixed; inset:0; z-index:9999;
    background:var(--bg); background-attachment:fixed;
    display:flex; justify-content:center;
    opacity:1; transition:opacity .35s ease; pointer-events:none;
}
#page-skeleton.hide{ opacity:0; }
.sk-lane{ width:100%; max-width:var(--max-w); display:flex; flex-direction:column; }
@media (min-width:801px){ .sk-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); } }
@media (max-width:800px){ .sk-lane{ max-width:100%; } }

.sk{
    position:relative; overflow:hidden;
    background:rgba(255,255,255,0.06); border-radius:6px;
}
.sk::after{
    content:''; position:absolute; inset:0;
    background:linear-gradient(90deg, transparent, rgba(255,255,255,0.09), transparent);
    transform:translateX(-100%); animation:skShimmer 1.5s infinite;
}
@keyframes skShimmer{ 100%{ transform:translateX(100%); } }

.sk-topbar{ height:var(--topbar-h); display:flex; align-items:center; gap:12px; padding:0 16px 0 10px; }
.sk-back{ width:22px; height:22px; }
.sk-title{ width:120px; height:14px; }
.sk-tabs{ display:flex; border-top:1px solid var(--line); border-bottom:1px solid var(--line); }
.sk-tab{ flex:1; height:46px; border-radius:0; }
.sk-tab + .sk-tab{ margin-left:2px; }
.sk-row{ display:flex; align-items:center; gap:14px; padding:12px var(--pad); border-bottom:1px solid rgba(255,255,255,0.06); }
.sk-avatar{ width:clamp(38px,10.5vw,46px); height:clamp(38px,10.5vw,46px); border-radius:50%; flex-shrink:0; }
.sk-info{ flex:1; display:flex; flex-direction:column; gap:7px; }
.sk-name{ width:100px; height:12px; }
.sk-sub{ width:70px; height:9px; }
.sk-btn{ width:86px; height:var(--rp-action-h); border-radius:var(--rp-action-radius); flex-shrink:0; }
</style>
</head>
<body>

<!-- ══ SKELETON ══ -->
<div id="page-skeleton">
    <div class="sk-lane">
        <div class="sk-topbar">
            <div class="sk sk-back"></div>
        </div>
        <div class="sk-tabs">
            <div class="sk sk-tab"></div>
            <div class="sk sk-tab"></div>
            <?php if(!$is_own_profile && $mutuals_count > 0): ?><div class="sk sk-tab"></div><?php endif; ?>
            <?php if($is_own_profile && $circle_count > 0): ?><div class="sk sk-tab"></div><?php endif; ?>
        </div>
        <?php for($i=0;$i<8;$i++): ?>
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

<div class="tk-lane">

    <!-- ══ TOPBAR ══ -->
    <div class="tk-topbar">
        <button type="button" class="tk-back" onclick="history.back()" aria-label="Go back">
            <i class="ri-arrow-left-s-line"></i>
        </button>
    </div>

    <!-- ══ TABS ══ -->
    <div class="tk-tabs" id="tkTabs">
        <div class="tk-tabs-track" id="tkTabsTrack">
            <button type="button" class="tk-tab tab-btn<?php echo $active_tab === 'followers' ? ' active' : ''; ?>" data-tab="tab-followers">
                <span class="tk-tab-count"><?php echo number_format($followers_count); ?></span>
                <span class="tk-tab-label">Trackers</span>
            </button>
            <button type="button" class="tk-tab tab-btn<?php echo $active_tab === 'following' ? ' active' : ''; ?>" data-tab="tab-following">
                <span class="tk-tab-count"><?php echo number_format($following_count); ?></span>
                <span class="tk-tab-label">Tracking</span>
            </button>
            <?php if(!$is_own_profile && $mutuals_count > 0): ?>
            <button type="button" class="tk-tab tab-btn<?php echo $active_tab === 'mutuals' ? ' active' : ''; ?>" data-tab="tab-mutuals">
                <span class="tk-tab-count"><?php echo number_format($mutuals_count); ?></span>
                <span class="tk-tab-label">Mutual</span>
            </button>
            <?php endif; ?>
            <?php if($is_own_profile && $circle_count > 0): ?>
            <button type="button" class="tk-tab tab-btn<?php echo $active_tab === 'circle' ? ' active' : ''; ?>" data-tab="tab-circle">
                <span class="tk-tab-count"><?php echo number_format($circle_count); ?></span>
                <span class="tk-tab-label">Circle</span>
            </button>
            <?php endif; ?>
            <span class="tk-tab-ind" id="tkTabInd"></span>
        </div>
    </div>

    <!-- ══ SEARCH ══ -->
    <div class="tk-search">
        <i class="ri-search-line"></i>
        <input type="text" id="user-search" placeholder="Search users..." oninput="filterUsers()" autocomplete="off">
    </div>

<?php
// ── Reusable user row renderer ─────────────────────────────
function render_user_rows($result, $current_user, $conn, $track_request_states = []){
    if(!$result || $result->num_rows === 0) return false;
    while($u = $result->fetch_assoc()):
        $uid     = (int)$u['id'];
        $is_self = ($uid == $current_user);

        // Is this user's profile private?
        $priv_row   = $conn->query("SELECT is_fully_private FROM user_settings WHERE user_id=$uid LIMIT 1")->fetch_assoc();
        $is_private = !empty($priv_row['is_fully_private']);

        // Does the current viewer already track this user?
        $i_track_them = (int)$conn->query("
            SELECT COUNT(*) AS c FROM followers
            WHERE follower_id=$current_user AND following_id=$uid
        ")->fetch_assoc()['c'] > 0;

        // Pending track-request status (if any)
        $req_status = $track_request_states[$uid] ?? null;

        // Button state — same logic as the profile page
        if($is_self){
            $btn_class = ''; $btn_text = ''; $btn_mode = '';
        } elseif($i_track_them){
            $btn_class = 'untrack'; $btn_text = 'Untrack'; $btn_mode = 'untrack';
        } elseif($is_private && $req_status === 'pending'){
            $btn_class = 'pending'; $btn_text = 'Requested'; $btn_mode = 'cancel_request';
        } elseif($is_private){
            $btn_class = 'track'; $btn_text = 'Request to Track'; $btn_mode = 'send_request';
        } else {
            $btn_class = 'track'; $btn_text = 'Track'; $btn_mode = 'follow';
        }

        // New post in the last 24 hours?
        $has_new_post = false;
        $new_post_chk = $conn->query("
            SELECT id FROM memes
            WHERE uploaded_by = $uid
            AND created_at >= NOW() - INTERVAL 24 HOUR
            LIMIT 1
        ");
        if($new_post_chk && $new_post_chk->num_rows > 0) $has_new_post = true;

        $display_username = $is_self ? 'You' : htmlspecialchars($u['username']);
?>
    <div class="user-row" onclick="location.href='profile.php?user_id=<?php echo $uid; ?>'">
        <img class="user-avatar"
             src="<?php echo htmlspecialchars($u['profile_pic'] ?: 'uploads/me.png'); ?>"
             onerror="this.src='uploads/me.png'" alt="">
        <div class="user-info">
            <div class="uname">
                <?php echo $display_username; ?>
            </div>
            <?php if(!empty($u['nickname'])): ?>
            <div class="unick">
                @<?php echo htmlspecialchars($u['nickname']); ?>
                <?php if($has_new_post): ?><span class="new-post-badge">New Post</span><?php endif; ?>
            </div>
            <?php elseif($has_new_post): ?>
            <div class="unick"><span class="new-post-badge">New Post</span></div>
            <?php endif; ?>
        </div>
        <?php if(!$is_self): ?>
        <button type="button" class="tk-btn <?php echo $btn_class; ?>"
                data-mode="<?php echo $btn_mode; ?>"
                onclick="event.stopPropagation(); handleTrackBtn(this, <?php echo $uid; ?>)">
            <?php echo $btn_text; ?>
        </button>
        <?php endif; ?>
    </div>
<?php
    endwhile;
    return true;
}
?>

    <!-- ══ TRACKERS LIST ══ -->
    <div class="user-list<?php echo $active_tab === 'followers' ? ' active' : ''; ?>" id="tab-followers">
        <?php if($followers && $followers->num_rows > 0): ?>
            <p class="context-note">
                These are Dezzers who track <?php echo $is_own_profile ? 'you' : htmlspecialchars($user['username']); ?> — they see everything <?php echo $is_own_profile ? 'you post' : 'posted on this account'; ?>.
            </p>
            <?php render_user_rows($followers, $current_user, $conn, $track_request_states); ?>
            <div class="empty-state search-no-results" style="display:none;">
                <i class="ri-search-line"></i><span>No users found</span>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="ri-user-follow-line"></i><span>No trackers yet</span>
            </div>
        <?php endif; ?>
    </div>

    <!-- ══ TRACKING LIST ══ -->
    <div class="user-list<?php echo $active_tab === 'following' ? ' active' : ''; ?>" id="tab-following">
        <?php if($following && $following->num_rows > 0): ?>
            <p class="context-note">
                <?php echo $is_own_profile ? 'You track' : htmlspecialchars($user['username']).' tracks'; ?> these accounts — their posts appear in <?php echo $is_own_profile ? 'your' : 'their'; ?> feed.
            </p>
            <?php render_user_rows($following, $current_user, $conn, $track_request_states); ?>
            <div class="empty-state search-no-results" style="display:none;">
                <i class="ri-search-line"></i><span>No users found</span>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="ri-user-search-line"></i><span>Not tracking anyone yet</span>
            </div>
        <?php endif; ?>
    </div>

    <!-- ══ MUTUALS LIST (other profiles only) ══ -->
    <?php if(!$is_own_profile): ?>
    <div class="user-list<?php echo $active_tab === 'mutuals' ? ' active' : ''; ?>" id="tab-mutuals">
        <?php if($mutuals && $mutuals->num_rows > 0): ?>
            <p class="context-note">
                These are people you both track — your shared connections with <?php echo htmlspecialchars($user['username']); ?>.
            </p>
            <?php render_user_rows($mutuals, $current_user, $conn, $track_request_states); ?>
            <div class="empty-state search-no-results" style="display:none;">
                <i class="ri-search-line"></i><span>No users found</span>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="ri-exchange-line"></i><span>No mutual connections yet</span>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ══ CIRCLE LIST (own profile only) ══ -->
    <?php if($is_own_profile): ?>
    <div class="user-list<?php echo $active_tab === 'circle' ? ' active' : ''; ?>" id="tab-circle">
        <?php if($circle && $circle->num_rows > 0): ?>
            <p class="context-note">
                These are people you track who track you back — your mutual connections.
            </p>
            <?php render_user_rows($circle, $current_user, $conn, $track_request_states); ?>
            <div class="empty-state search-no-results" style="display:none;">
                <i class="ri-search-line"></i><span>No users found</span>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="ri-team-line"></i><span>Your circle is empty — track someone who tracks you back</span>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div><!-- /.tk-lane -->

<script>
// ── Shuffle helpers ────────────────────────────────────────
function shuffleArray(arr){
    for (var i = arr.length - 1; i > 0; i--) {
        var j = Math.floor(Math.random() * (i + 1));
        var t = arr[i]; arr[i] = arr[j]; arr[j] = t;
    }
    return arr;
}
function shuffleUserRows(list){
    if (!list) return;
    var rows = Array.prototype.slice.call(list.querySelectorAll('.user-row'));
    if (rows.length <= 1) return;
    shuffleArray(rows);
    var noRes = list.querySelector('.search-no-results');
    rows.forEach(function(row){
        if (noRes) list.insertBefore(row, noRes);
        else list.appendChild(row);
    });
}

// ── Sliding tab underline ──────────────────────────────────
function moveTabIndicator(btn){
    var ind = document.getElementById('tkTabInd');
    if (!ind || !btn) return;
    ind.style.left  = btn.offsetLeft + 'px';
    ind.style.width = btn.offsetWidth + 'px';
    ind.classList.add('ready');
}
function syncIndicator(){
    moveTabIndicator(document.querySelector('.tab-btn.active'));
}
window.addEventListener('resize', syncIndicator);

// ── Page skeleton ──────────────────────────────────────────
window.addEventListener('load', function(){
    document.querySelectorAll('.user-list').forEach(shuffleUserRows);
    syncIndicator();

    var sk = document.getElementById('page-skeleton');
    if (!sk) return;
    setTimeout(function(){
        sk.classList.add('hide');
        setTimeout(function(){ sk.remove(); }, 380);
    }, 280);
});
document.addEventListener('DOMContentLoaded', syncIndicator);

// ── Tab switching ──────────────────────────────────────────
document.querySelectorAll('.tab-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
        document.querySelectorAll('.tab-btn').forEach(function(b){ b.classList.remove('active'); });
        document.querySelectorAll('.user-list').forEach(function(l){ l.classList.remove('active'); });
        btn.classList.add('active');
        moveTabIndicator(btn);
        var target = document.getElementById(btn.dataset.tab);
        if (target){
            target.classList.add('active');
            shuffleUserRows(target);
        }
        var s = document.getElementById('user-search');
        if (s){ s.value = ''; filterUsers(); }
    });
});

// ── Search / filter ────────────────────────────────────────
function filterUsers(){
    var query      = document.getElementById('user-search').value.trim().toLowerCase();
    var activeList = document.querySelector('.user-list.active');
    if (!activeList) return;
    var rows = activeList.querySelectorAll('.user-row');
    var visible = 0;
    rows.forEach(function(row){
        var un = row.querySelector('.uname'), nk = row.querySelector('.unick');
        var uname = (un ? un.textContent : '').toLowerCase();
        var unick = (nk ? nk.textContent : '').toLowerCase();
        var match = uname.indexOf(query) > -1 || unick.indexOf(query) > -1;
        row.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    var noRes = activeList.querySelector('.search-no-results');
    if (noRes) noRes.style.display = (query && visible === 0 && rows.length > 0) ? 'flex' : 'none';
}

// ── Track button — identical states to the profile page ────
var BTN_STATES = {
    follow:         { cls: 'track',   txt: 'Track' },
    untrack:        { cls: 'untrack', txt: 'Untrack' },
    send_request:   { cls: 'track',   txt: 'Request to Track' },
    cancel_request: { cls: 'pending', txt: 'Requested' }
};
function paintBtn(btn, mode){
    var s = BTN_STATES[mode];
    if (!s) return;
    btn.className = 'tk-btn ' + s.cls;
    btn.textContent = s.txt;
    btn.dataset.mode = mode;
    btn.style.minWidth = '';
    btn.disabled = false;
}

function handleTrackBtn(btn, userId){
    if (btn.disabled) return;
    var mode = btn.dataset.mode;
    var body;
    if (mode === 'follow' || mode === 'untrack') body = 'action=toggle_follow&user_id=' + userId;
    else if (mode === 'send_request')            body = 'action=send_track_request&user_id=' + userId;
    else if (mode === 'cancel_request')          body = 'action=cancel_track_request&user_id=' + userId;
    else return;

    // spinner keeps the button the same size while loading
    btn.style.minWidth = btn.offsetWidth + 'px';
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-icon"><span></span><span></span><span></span><span></span></span>';

    fetch('tracks.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        credentials: 'same-origin',
        body: body
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        if (data.status === 'ok'){
            if (mode === 'follow')              paintBtn(btn, 'untrack');
            else if (mode === 'untrack')        paintBtn(btn, data.is_private ? 'send_request' : 'follow');
            else if (mode === 'send_request')   paintBtn(btn, 'cancel_request');
            else if (mode === 'cancel_request') paintBtn(btn, 'send_request');
        } else if (data.is_private && mode === 'follow'){
            // profile turned private — a request is required now
            paintBtn(btn, 'send_request');
        } else {
            paintBtn(btn, mode);
        }
    })
    .catch(function(){ paintBtn(btn, mode); });
}
</script>
</body>
</html>