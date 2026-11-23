<?php
session_start();
include 'config.php';
/* verified flower badge (dz_badge_sprite() + dz_badge('f')) — same file the other pages use */
require_once __DIR__ . '/verified_badge.php';

$current_user = (int)($_SESSION['user_id'] ?? 0);
if(!$current_user){ header("Location: login.php"); exit(); }

$target_id = (isset($_GET['user_id']) && (int)$_GET['user_id'] > 0) ? (int)$_GET['user_id'] : 0;
if(!$target_id || $target_id === $current_user){ header("Location: profile.php"); exit(); }

// ── Current user info ──
$me_res = $conn->query("SELECT username, profile_pic FROM users WHERE id=$current_user LIMIT 1");
$me = $me_res->fetch_assoc();
$me_pic = htmlspecialchars($me['profile_pic'] ?: 'uploads/me.png');
$me_username = $me['username'];

// ── Target user info ──
$target_res = $conn->query("SELECT username, nickname, profile_pic, is_verified FROM users WHERE id=$target_id LIMIT 1");
if(!$target_res || $target_res->num_rows === 0){ header("Location: 404.php"); exit(); }
$target = $target_res->fetch_assoc();
$target_username     = htmlspecialchars($target['username']);
$target_username_raw = $target['username'];
$target_pic = htmlspecialchars($target['profile_pic'] ?: 'uploads/me.png');
$target_verified = !empty($target['is_verified']);

// ── Is the target profile private? ──
$priv_row   = $conn->query("SELECT is_fully_private FROM user_settings WHERE user_id=$target_id LIMIT 1")->fetch_assoc();
$is_private = !empty($priv_row['is_fully_private']);

// ── Handle Track / Request / Untrack POST ──
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    if(isset($_POST['track'])){
        if($is_private){
            // private profiles can never be tracked directly — a request is required
            $conn->query("DELETE FROM track_requests WHERE requester_id=$current_user AND target_id=$target_id AND status='denied'");
            $conn->query("INSERT IGNORE INTO track_requests(requester_id,target_id,status) VALUES($current_user,$target_id,'pending')");
        } else {
            $conn->query("INSERT IGNORE INTO followers (follower_id, following_id) VALUES ($current_user, $target_id)");
        }
        header("Location: profile_share.php?user_id=$target_id"); exit();
    }
    if(isset($_POST['cancel_request'])){
        $conn->query("DELETE FROM track_requests WHERE requester_id=$current_user AND target_id=$target_id");
        header("Location: profile_share.php?user_id=$target_id"); exit();
    }
    if(isset($_POST['untrack'])){
        $conn->query("DELETE FROM followers WHERE follower_id=$current_user AND following_id=$target_id");
        $conn->query("DELETE FROM track_requests WHERE requester_id=$current_user AND target_id=$target_id");
        header("Location: profile_share.php?user_id=$target_id"); exit();
    }
}

// ── Is current user tracking target? ──
$is_tracking = false;
$tracking_since = null;
$tr = $conn->query("SELECT created_at FROM followers WHERE follower_id=$current_user AND following_id=$target_id LIMIT 1");
if($tr && $tr->num_rows > 0){
    $is_tracking = true;
    $tracking_since = $tr->fetch_assoc()['created_at'];
}

// ── Pending request status ──
$req_status = null;
$rq = $conn->query("SELECT status FROM track_requests WHERE requester_id=$current_user AND target_id=$target_id LIMIT 1");
if($rq && $rq->num_rows > 0) $req_status = $rq->fetch_assoc()['status'];

// ── Does target track current user? ──
$target_tracks_me = $conn->query("SELECT id FROM followers WHERE follower_id=$target_id AND following_id=$current_user LIMIT 1")->num_rows > 0;

// ── Shared Likes ──
$shared_likes = $conn->query("
    SELECT COUNT(*) as cnt FROM likes l1
    JOIN likes l2 ON l1.meme_id = l2.meme_id
    WHERE l1.user_id = $current_user AND l2.user_id = $target_id
")->fetch_assoc()['cnt'] ?? 0;

// ── Shared Reposts ──
$shared_reposts = $conn->query("
    SELECT COUNT(*) as cnt FROM reposts r1
    JOIN reposts r2 ON r1.meme_id = r2.meme_id
    WHERE r1.user_id = $current_user AND r2.user_id = $target_id
")->fetch_assoc()['cnt'] ?? 0;

// ── Tagged by You ──
$safe_target_username = $conn->real_escape_string($target_username_raw);
$tagged_by_you = $conn->query("
    SELECT COUNT(*) as cnt FROM memes
    WHERE uploaded_by = $current_user
    AND caption LIKE '%@$safe_target_username%'
")->fetch_assoc()['cnt'] ?? 0;

// ── Tagged by Them ──
$safe_me_username = $conn->real_escape_string($me_username);
$tagged_by_them = $conn->query("
    SELECT COUNT(*) as cnt FROM memes
    WHERE uploaded_by = $target_id
    AND caption LIKE '%@$safe_me_username%'
")->fetch_assoc()['cnt'] ?? 0;

function formatTrackDate($datetime){
    if(!$datetime) return '';
    return date('F Y', strtotime($datetime));
}

// ── Button state (same logic as the profile page) ──
if($is_tracking){
    $btn_class = 'untracked'; $btn_text = 'Untrack';    $btn_name = 'untrack';
} elseif($is_private && $req_status === 'pending'){
    $btn_class = 'track pending'; $btn_text = 'Requested'; $btn_name = 'cancel_request';
} elseif($is_private){
    $btn_class = 'track'; $btn_text = 'Request to Track'; $btn_name = 'track';
} else {
    $btn_class = 'track'; $btn_text = 'Track'; $btn_name = 'track';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Shared Activity · <?php echo $target_username; ?></title>
<script>try{if(localStorage.getItem('dz_theme')==='light')document.documentElement.setAttribute('data-theme','light');}catch(e){}</script>

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
    --track-red-hover: #ff1a1a;

    --fg:        255,255,255;   /* R,G,B of the tint used for lines + soft fills (dark theme = white) */
    --ui-text:   #ffffff;
    --ph:        #0d1030;       /* avatar placeholder */

    /* page background — identical to the profile page */
    --bg:        linear-gradient(-45deg,#010218,#070924,#070924);

    --surface:   rgba(var(--fg),0.05);
    --line:      rgba(var(--fg),0.09);
    --line2:     rgba(var(--fg),0.14);
    --text:      #ffffff;
    --muted:     rgba(var(--fg),0.45);
    --sub:       rgba(var(--fg),0.62);

    --max-w:     580px;
    --topbar-h:  54px;
    --pad:       clamp(9px, 3vw, 16px);

    /* same action button metrics as the profile page */
    --rp-action-h:      clamp(25px, 7.2vw, 30px);
    --rp-action-radius: 5px;
}
@media (max-width:490px){ :root{ --topbar-h:48px; } }
@media (max-width:380px){ :root{ --topbar-h:44px; } }

html{ scroll-behavior:smooth; }

/* RESPONSIVE view: no scrollbars — scrolling still works */
@media (max-width:768px){
    html, body, *{ scrollbar-width:none !important; -ms-overflow-style:none !important; }
    *::-webkit-scrollbar{ display:none !important; width:0 !important; height:0 !important; }
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

/* ── centered lane with the two grey side lines (same as tracks page) ── */
.tk-lane{
    width:100%; max-width:var(--max-w);
    min-height:100vh;
    display:flex; flex-direction:column;
}
@media (min-width:801px){
    .tk-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); }
}
@media (max-width:800px){ .tk-lane{ max-width:100%; } }

/* ══ TOPBAR — X button + title on the right ══ */
.tk-topbar{
    position:sticky; top:0; z-index:500;
    height:var(--topbar-h);
    display:flex; align-items:center; gap:2px;
    padding:0 10px;
    background:var(--bg);
    background-attachment:fixed;
}
/* grey line under the topbar — drawn as its own layer so it shows on EVERY screen size
   (responsive included) and stays pinned while scrolling */
.tk-topbar::after{
    content:''; position:absolute; left:0; right:0; bottom:0;
    height:1px; background:rgba(var(--fg),0.14);
    pointer-events:none; z-index:2;
}
.tk-back{
    width:34px; height:34px;
    background:none; border:none; color:var(--ui-text);
    display:flex; align-items:center; justify-content:center;
    opacity:.9; transition:opacity .2s, color .2s;
    padding:0; flex-shrink:0;
}
.tk-back:hover{ opacity:1; color:var(--red); }
.tk-back svg{ width:22px; height:22px; display:block; }
.tk-title{
    font-size:.92rem; font-weight:700; color:var(--ui-text); letter-spacing:-.1px;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    min-width:0; flex:1;
    text-align:right; margin:0 6px 0 10px;
}
@media (max-width:490px){
    .tk-back{ width:30px; height:30px; }
    .tk-title{ font-size:.84rem; }
}
@media (max-width:380px){ .tk-title{ font-size:.8rem; } }

/* ══ HERO ══ */
.sh-hero{
    display:flex; flex-direction:column; align-items:center; text-align:center;
    padding:14px var(--pad) 0;
}
.dual-avatar-wrap{
    position:relative;
    width:clamp(122px, 34vw, 170px); height:clamp(72px, 20vw, 100px);
    margin:clamp(6px,2vw,14px) auto 10px;
}
.dual-avatar{
    width:clamp(68px, 19vw, 96px); height:clamp(68px, 19vw, 96px);
    border-radius:50%; object-fit:cover; position:absolute; top:0;
    border:2.5px solid var(--red);
    background:var(--ph);
    box-shadow:0 6px 20px rgba(0,0,0,0.45);
}
.dual-avatar.mine  { left:0; z-index:1; }
.dual-avatar.theirs{ right:0; z-index:2; }   /* theirs in front */

.share-title{ font-size:clamp(.92rem, 3.6vw, 1.15rem); font-weight:700; margin-bottom:4px; letter-spacing:-.2px; color:var(--ui-text); }
.share-title strong{ color:var(--red); }
/* verified badge — white, follows the title size (1em) */
.share-title .vbadge{
    width:1em; height:1em; flex-shrink:0;
    display:inline-block; vertical-align:-0.14em;
    margin-left:.3em;
    color:var(--ui-text) !important;
    fill:currentColor;
}
.share-sub{
    font-size:clamp(.64rem, 2.1vw, .75rem); color:var(--muted); line-height:1.6;
    max-width:320px; margin-bottom:14px;
}
.share-sub strong{ color:rgba(var(--fg),0.7); font-weight:600; }

/* ══ ACTION BUTTONS — identical to the profile page ══ */
.rp-actions{
    display:flex; flex-direction:row; flex-wrap:nowrap; align-items:stretch; gap:8px;
    width:100%; padding:4px 0 16px;
}
.rp-actions form{ margin:0; flex:1 1 0; width:0; min-width:0; display:flex; }
.rp-rect-btn{
    display:flex; align-items:center; justify-content:center; gap:6px;
    flex:1 1 0; min-width:0; width:100%; padding:4px 4px; border-radius:var(--rp-action-radius);
    height:var(--rp-action-h); line-height:1;
    font-size:0.72rem; font-weight:700; color:var(--ui-text); text-decoration:none;
    border:none;
    background:rgba(var(--fg),0.07);
    transition:background .2s ease, transform .15s ease;
    white-space:nowrap; overflow:hidden;
}
.rp-actions > a.rp-rect-btn{ width:0; }
.rp-rect-btn:hover{ background:rgba(var(--fg),0.14); }
.rp-rect-btn:active{ transform:scale(0.98); }
.rp-rect-btn:disabled{ cursor:default; }

/* TRACK / REQUEST TO TRACK — solid red, black bold text (same in both themes) */
.rp-rect-btn.track{ background:var(--red); color:#000; font-weight:800; }
.rp-rect-btn.track:hover{ background:var(--track-red-hover); }
.rp-rect-btn.track.pending{ background:rgba(var(--fg),0.1); color:rgba(var(--fg),0.45); font-weight:700; }
.rp-rect-btn.track.pending:hover{ background:rgba(var(--fg),0.16); }

/* UNTRACK / MESSAGE / VIEW PROFILE — frosted grey */
.rp-rect-btn.untracked,
.rp-rect-btn.message{
    background:rgba(var(--fg),0.1);
    backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    border:none; color:var(--ui-text);
}
.rp-rect-btn.untracked:hover,
.rp-rect-btn.message:hover{ background:rgba(var(--fg),0.2); }

@media (max-width:490px){
    .rp-actions{ gap:5px; }
    .rp-rect-btn{ font-size:0.62rem; padding:3px 4px; gap:4px; }
}
@media (max-width:360px){ .rp-rect-btn{ font-size:0.58rem; } }

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

/* ══ ROWS ══ */
.sh-list{ display:flex; flex-direction:column; width:100%; animation:tkFade .3s ease both; }
@keyframes tkFade{ from{ opacity:0; transform:translateY(6px); } to{ opacity:1; transform:none; } }

.section-label{
    padding:18px var(--pad) 8px;
    font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:1.4px;
    color:var(--muted);
    border-top:1px solid var(--line);
}
.sh-row{
    display:flex; align-items:center; gap:clamp(10px, 3vw, 14px);
    padding:13px var(--pad);
    border-bottom:1px solid rgba(var(--fg),0.06);
    transition:background .15s;
}
.sh-row:last-child{ border-bottom:none; }
.sh-row:hover{ background:rgba(var(--fg),0.03); }
.sh-icon{
    width:clamp(30px, 8.5vw, 36px); height:clamp(30px, 8.5vw, 36px);
    border-radius:10px; background:var(--surface);
    display:flex; align-items:center; justify-content:center;
    flex-shrink:0; color:var(--red); font-size:clamp(.82rem, 2.6vw, 1rem);
}
.sh-content{ flex:1; min-width:0; }
.sh-title{ font-size:clamp(.72rem, 2.3vw, .84rem); font-weight:600; color:var(--text); }
.sh-value{ font-size:clamp(.7rem, 2.3vw, .82rem); font-weight:500; color:var(--text); line-height:1.45; word-break:break-word; }
.sh-value strong{ font-weight:700; }
.sh-value.dim{ color:var(--muted); font-weight:400; }
.sh-sub{ font-size:clamp(.6rem, 1.9vw, .68rem); color:var(--muted); margin-top:2px; line-height:1.4; }
.sh-count{
    font-size:clamp(.88rem, 2.8vw, 1.05rem); font-weight:700; color:var(--red);
    flex-shrink:0; min-width:30px; text-align:right;
}

/* ══ TAG CARDS ══ */
.tags-row{ display:flex; gap:clamp(7px, 2vw, 10px); padding:4px var(--pad) 6px; }
.tag-card{
    flex:1; min-width:0;
    background:var(--surface); border:1px solid var(--line);
    border-radius:12px; padding:clamp(11px, 3vw, 16px) clamp(8px, 2.5vw, 14px);
    display:flex; flex-direction:column; align-items:center; text-align:center; gap:4px;
    transition:background .2s, border-color .2s;
}
.tag-card:hover{ background:rgba(var(--fg),0.07); border-color:var(--line2); }
.tag-icon{
    width:clamp(26px, 7vw, 34px); height:clamp(26px, 7vw, 34px); border-radius:9px;
    background:rgba(255,45,45,0.12); color:var(--red);
    display:flex; align-items:center; justify-content:center; font-size:clamp(.78rem, 2.4vw, .95rem);
}
.tag-count{ font-size:clamp(1rem, 4vw, 1.3rem); font-weight:700; color:var(--red); line-height:1.1; margin-top:2px; }
.tag-label{ font-size:clamp(.58rem, 2vw, .7rem); font-weight:600; color:var(--text); }
.tag-sub{ font-size:clamp(.5rem, 1.7vw, .6rem); color:var(--muted); line-height:1.4; }

.sh-note{
    font-size:clamp(.58rem, 1.9vw, .68rem); color:var(--muted); line-height:1.7;
    padding:16px var(--pad) 40px;
}
.sh-note strong{ color:rgba(var(--fg),0.6); font-weight:500; }

/* ══ SKELETON — same lane, same lines ══ */
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
.sk{ position:relative; overflow:hidden; background:rgba(var(--fg),0.06); border-radius:6px; }
.sk::after{
    content:''; position:absolute; inset:0;
    background:linear-gradient(90deg, transparent, rgba(var(--fg),0.09), transparent);
    transform:translateX(-100%); animation:skShimmer 1.5s infinite;
}
@keyframes skShimmer{ 100%{ transform:translateX(100%); } }
.sk-topbar{ height:var(--topbar-h); display:flex; align-items:center; gap:12px; padding:0 16px 0 10px; border-bottom:1px solid rgba(var(--fg),0.14); }
.sk-back{ width:22px; height:22px; }
.sk-hero{ display:flex; flex-direction:column; align-items:center; gap:10px; padding:20px var(--pad) 16px; }
.sk-av{ width:clamp(110px,30vw,150px); height:clamp(68px,19vw,96px); border-radius:48px; }
.sk-name{ width:150px; height:14px; }
.sk-sub{ width:210px; height:10px; }
.sk-btns{ display:flex; gap:8px; width:100%; margin-top:6px; }
.sk-btn{ flex:1; height:var(--rp-action-h); border-radius:var(--rp-action-radius); }
.sk-row{ display:flex; align-items:center; gap:14px; padding:13px var(--pad); border-bottom:1px solid rgba(var(--fg),0.06); }
.sk-ic{ width:clamp(30px,8.5vw,36px); height:clamp(30px,8.5vw,36px); border-radius:10px; flex-shrink:0; }
.sk-info{ flex:1; display:flex; flex-direction:column; gap:7px; }
.sk-l1{ width:70%; height:11px; }
.sk-l2{ width:45%; height:9px; }

/* ═══════════════════════════════════════════════════════════════
   RESPONSIVE ONLY — everything 10% bigger (desktop untouched)
   Kept before the light-mode block so theme variables still win.
═══════════════════════════════════════════════════════════════ */
@media (max-width:800px){
    :root{
        --topbar-h:59px;
        --pad:clamp(10px, 3.3vw, 17.6px);
        --rp-action-h:clamp(27.5px, 7.92vw, 33px);
        --rp-action-radius:5.5px;
    }

    /* topbar */
    .tk-topbar{ padding:0 11px; }
    .tk-back{ width:37px; height:37px; }
    .tk-back svg{ width:24px; height:24px; }
    .tk-title{ font-size:1.01rem; margin:0 7px 0 11px; }

    /* hero */
    .sh-hero{ padding:15px var(--pad) 0; }
    .dual-avatar-wrap{ width:clamp(134px, 37.4vw, 187px); height:clamp(79px, 22vw, 110px); margin:clamp(6.6px, 2.2vw, 15.4px) auto 11px; }
    .dual-avatar{ width:clamp(75px, 20.9vw, 106px); height:clamp(75px, 20.9vw, 106px); border-width:2.75px; }
    .share-title{ font-size:clamp(1.01rem, 3.96vw, 1.265rem); margin-bottom:4.4px; }
    .share-title .vbadge{ width:1em; height:1em; }
    .share-sub{ font-size:clamp(.7rem, 2.31vw, .825rem); max-width:352px; margin-bottom:15.4px; }

    /* action buttons */
    .rp-actions{ gap:8.8px; padding:4.4px 0 17.6px; }
    .rp-rect-btn{ gap:6.6px; padding:4.4px; font-size:.79rem; }

    /* rows */
    .section-label{ padding:20px var(--pad) 8.8px; font-size:.68rem; letter-spacing:1.54px; }
    .sh-row{ gap:clamp(11px, 3.3vw, 15px); padding:14px var(--pad); }
    .sh-icon{ width:clamp(33px, 9.35vw, 40px); height:clamp(33px, 9.35vw, 40px); border-radius:11px; font-size:clamp(.9rem, 2.86vw, 1.1rem); }
    .sh-title{ font-size:clamp(.79rem, 2.53vw, .92rem); }
    .sh-value{ font-size:clamp(.77rem, 2.53vw, .9rem); }
    .sh-sub{ font-size:clamp(.66rem, 2.09vw, .75rem); margin-top:2.2px; }
    .sh-count{ font-size:clamp(.97rem, 3.08vw, 1.155rem); min-width:33px; }

    /* tag cards */
    .tags-row{ gap:clamp(8px, 2.2vw, 11px); padding:4.4px var(--pad) 6.6px; }
    .tag-card{ border-radius:13.2px; padding:clamp(12px, 3.3vw, 17.6px) clamp(9px, 2.75vw, 15.4px); gap:4.4px; }
    .tag-icon{ width:clamp(29px, 7.7vw, 37px); height:clamp(29px, 7.7vw, 37px); border-radius:9.9px; font-size:clamp(.86rem, 2.64vw, 1.045rem); }
    .tag-count{ font-size:clamp(1.1rem, 4.4vw, 1.43rem); margin-top:2.2px; }
    .tag-label{ font-size:clamp(.64rem, 2.2vw, .77rem); }
    .tag-sub{ font-size:clamp(.55rem, 1.87vw, .66rem); }

    .sh-note{ font-size:clamp(.64rem, 2.09vw, .75rem); padding:17.6px var(--pad) 44px; }

    /* skeleton */
    .sk-topbar{ gap:13px; padding:0 17.6px 0 11px; }
    .sk-back{ width:24px; height:24px; }
    .sk-hero{ gap:11px; padding:22px var(--pad) 17.6px; }
    .sk-av{ width:clamp(121px, 33vw, 165px); height:clamp(75px, 20.9vw, 106px); border-radius:53px; }
    .sk-name{ width:165px; height:15.4px; }
    .sk-sub{ width:231px; height:11px; }
    .sk-btns{ gap:8.8px; margin-top:6.6px; }
    .sk-row{ gap:15px; padding:14px var(--pad); }
    .sk-ic{ width:clamp(33px, 9.35vw, 40px); height:clamp(33px, 9.35vw, 40px); border-radius:11px; }
    .sk-info{ gap:7.7px; }
    .sk-l1{ height:12px; }
    .sk-l2{ height:10px; }
}

@media (max-width:490px){
    :root{ --topbar-h:53px; }
    .tk-back{ width:33px; height:33px; }
    .tk-title{ font-size:.92rem; }
    .rp-actions{ gap:5.5px; }
    .rp-rect-btn{ font-size:.68rem; padding:3.3px 4.4px; gap:4.4px; }
}

@media (max-width:380px){
    :root{ --topbar-h:48px; }
    .tk-title{ font-size:.88rem; }
}

@media (max-width:360px){
    .rp-rect-btn{ font-size:.64rem; }
}

/* ═══════════ LIGHT MODE — <html data-theme="light"> (dark mode is never touched) ═══════════ */
:root[data-theme="light"]{
    color-scheme:light;
    --fg:6,7,26;
    --ui-text:#0b0d24;
    --text:#0b0d24;
    --surface:rgba(6,7,26,.05);
    --line:rgba(6,7,26,.1);
    --line2:rgba(6,7,26,.18);
    --muted:rgba(6,7,26,.52);
    --sub:rgba(6,7,26,.66);
    --bg:linear-gradient(-45deg,#ffffff,#f4f5fb,#f4f5fb);
    --ph:#eceef5;
}
[data-theme="light"] .dual-avatar{ box-shadow:0 6px 20px rgba(0,0,0,.18); }
/* badge: white in dark mode; in light mode it follows the text colour so it stays visible on the white page.
   To force pure white in light mode too, change to: color:#fff !important; */
[data-theme="light"] .share-title .vbadge{ color:var(--ui-text) !important; }
</style>
</head>
<body>

<?php echo dz_badge_sprite(); ?>

<!-- ══ SKELETON ══ -->
<div id="page-skeleton">
    <div class="sk-lane">
        <div class="sk-topbar"><div class="sk sk-back"></div></div>
        <div class="sk-hero">
            <div class="sk sk-av"></div>
            <div class="sk sk-name"></div>
            <div class="sk sk-sub"></div>
            <div class="sk-btns"><div class="sk sk-btn"></div><div class="sk sk-btn"></div><div class="sk sk-btn"></div></div>
        </div>
        <?php for($i=0;$i<5;$i++): ?>
        <div class="sk-row">
            <div class="sk sk-ic"></div>
            <div class="sk-info"><div class="sk sk-l1"></div><div class="sk sk-l2"></div></div>
        </div>
        <?php endfor; ?>
    </div>
</div>

<div class="tk-lane">

    <!-- ══ TOPBAR ══ -->
    <div class="tk-topbar">
        <button type="button" class="tk-back" onclick="shClose()" aria-label="Close">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
        <span class="tk-title">Shared Activity</span>
    </div>

    <!-- ══ HERO ══ -->
    <div class="sh-hero">
        <div class="dual-avatar-wrap">
            <img class="dual-avatar theirs" src="<?php echo $target_pic; ?>" onerror="this.src='uploads/me.png'" alt="<?php echo $target_username; ?>">
            <img class="dual-avatar mine" src="<?php echo $me_pic; ?>" onerror="this.src='uploads/me.png'" alt="You">
        </div>

        <div class="share-title">You and <strong><?php echo $target_username; ?></strong><?php echo $target_verified ? dz_badge('f') : ''; ?></div>
        <p class="share-sub">View your activity with <strong><?php echo $target_username; ?></strong> and manage interactions.</p>

        <!-- ══ ACTION BUTTONS ══ -->
        <div class="rp-actions">
            <form method="POST" id="shTrackForm">
                <button type="button" class="rp-rect-btn <?php echo $btn_class; ?>"
                        data-name="<?php echo $btn_name; ?>" onclick="shSubmit(this)"><?php echo $btn_text; ?></button>
            </form>
            <a href="messages.php?user=<?php echo $target_id; ?>" class="rp-rect-btn message">Message</a>
            <a href="profile.php?user_id=<?php echo $target_id; ?>" class="rp-rect-btn message">View profile</a>
        </div>
    </div>

    <!-- ══ CONNECTION ══ -->
    <div class="sh-list">
        <div class="sh-row">
            <div class="sh-icon"><i class="ri-information-line"></i></div>
            <div class="sh-content">
                <div class="sh-value<?php echo !$target_tracks_me ? ' dim' : ''; ?>">
                    <?php echo $target_tracks_me
                        ? "<strong>$target_username</strong> tracks you"
                        : "<strong>$target_username</strong> doesn't track you"; ?>
                </div>
            </div>
        </div>
        <div class="sh-row">
            <div class="sh-icon"><i class="ri-calendar-check-line"></i></div>
            <div class="sh-content">
                <div class="sh-value<?php echo !$is_tracking ? ' dim' : ''; ?>">
                    <?php
                    if($is_tracking && $tracking_since){
                        echo "You started tracking <strong>$target_username</strong> in " . formatTrackDate($tracking_since);
                    } else {
                        echo "You aren't tracking <strong>$target_username</strong>";
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ SHARED ACTIVITY ══ -->
    <div class="section-label">Shared Activity</div>
    <div class="sh-list">
        <div class="sh-row">
            <div class="sh-icon"><i class="ri-heart-line"></i></div>
            <div class="sh-content">
                <div class="sh-title">Likes</div>
                <div class="sh-sub">Memes you and <?php echo $target_username; ?> both liked.</div>
            </div>
            <div class="sh-count"><?php echo number_format($shared_likes); ?></div>
        </div>
        <div class="sh-row">
            <div class="sh-icon"><i class="ri-repeat-line"></i></div>
            <div class="sh-content">
                <div class="sh-title">Reposts</div>
                <div class="sh-sub">Memes you and <?php echo $target_username; ?> both reposted.</div>
            </div>
            <div class="sh-count"><?php echo number_format($shared_reposts); ?></div>
        </div>
    </div>

    <!-- ══ TAGS ══ -->
    <div class="section-label">Tags</div>
    <div class="tags-row">
        <div class="tag-card">
            <div class="tag-icon"><i class="ri-price-tag-3-line"></i></div>
            <div class="tag-count"><?php echo number_format($tagged_by_you); ?></div>
            <div class="tag-label">Tagged by You</div>
            <div class="tag-sub">Times you tagged <?php echo $target_username; ?></div>
        </div>
        <div class="tag-card">
            <div class="tag-icon"><i class="ri-price-tag-3-line"></i></div>
            <div class="tag-count"><?php echo number_format($tagged_by_them); ?></div>
            <div class="tag-label">Tagged by Them</div>
            <div class="tag-sub"><?php echo $target_username; ?> tagged you</div>
        </div>
    </div>

    <p class="sh-note">
        <strong>Shared Activity</strong> shows overlap between your interactions and <?php echo $target_username; ?>'s on Dezzme — including likes, reposts, and tagged posts. This is only visible to you.
    </p>

</div><!-- /.tk-lane -->

<script>
// ── Close (X) — goes back, or to the profile if there is no history ──
function shClose(){
    if (document.referrer && document.referrer.indexOf(window.location.host) !== -1) history.back();
    else location.href = 'profile.php?user_id=<?php echo (int)$target_id; ?>';
}

// ── Track / Untrack / Request — spinner (same size), then submit ──
function shSubmit(btn){
    if (btn.disabled) return;
    var name = btn.dataset.name;
    var form = document.getElementById('shTrackForm');
    if (!form || !name) return;

    btn.style.minWidth = btn.offsetWidth + 'px';
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-icon"><span></span><span></span><span></span><span></span></span>';

    setTimeout(function(){
        var h = document.createElement('input');
        h.type = 'hidden'; h.name = name; h.value = '1';
        form.appendChild(h);
        form.submit();
    }, 120);
}

// ── Page skeleton ──
window.addEventListener('load', function(){
    var sk = document.getElementById('page-skeleton');
    if (!sk) return;
    setTimeout(function(){
        sk.classList.add('hide');
        setTimeout(function(){ sk.remove(); }, 380);
    }, 280);
});
</script>
</body>
</html>