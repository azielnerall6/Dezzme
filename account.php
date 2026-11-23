<?php
session_start();
include 'config.php';
/* verified flower badge (dz_badge_sprite() + dz_badge('f')) — same file the mutuals / crossroads / tracks pages use */
require_once __DIR__ . '/verified_badge.php';

$current_user = (int)($_SESSION['user_id'] ?? 0);
if(!$current_user){ header("Location: login.php"); exit(); }

$profile_id = (isset($_GET['user_id']) && (int)$_GET['user_id'] > 0)
    ? (int)$_GET['user_id']
    : $current_user;

$is_own_profile = ($current_user === $profile_id);

// ── User info ──────────────────────────────────────────────
$user_result = $conn->query("SELECT username, profile_pic, created_at, dz_id, is_verified FROM users WHERE id=$profile_id LIMIT 1");
if(!$user_result || $user_result->num_rows === 0){ header("Location: 404.php"); exit(); }
$user = $user_result->fetch_assoc();

// ── Verification date (uses whichever date column exists; the page still works if none does) ──
$verified_date = null;
if(!empty($user['is_verified'])){
    foreach(['verified_at','verified_on','verification_date','verified_date'] as $vc){
        $chk = $conn->query("SHOW COLUMNS FROM `users` LIKE '$vc'");
        if($chk && $chk->num_rows > 0){
            $vr = $conn->query("SELECT `$vc` AS vd FROM users WHERE id=$profile_id LIMIT 1");
            if($vr && ($vrow = $vr->fetch_assoc()) && !empty($vrow['vd']) && strtotime((string)$vrow['vd'])){
                $verified_date = date('F j, Y', strtotime((string)$vrow['vd']));
            }
            break;
        }
    }
}

// ── Permanent Profile ID (stored in DB, never changes) ─────
$dz_profile_id = $user['dz_id'] ?? 'DZ-000000';
$dz_digits     = substr($dz_profile_id, 3); // everything after "DZ-"

// ── Basic counts ───────────────────────────────────────────
$memes_count     = (int)($conn->query("SELECT COUNT(*) AS cnt FROM memes    WHERE uploaded_by=$profile_id")->fetch_assoc()['cnt'] ?? 0);
$followers_count = (int)($conn->query("SELECT COUNT(*) AS cnt FROM followers WHERE following_id=$profile_id")->fetch_assoc()['cnt'] ?? 0);
$following_count = (int)($conn->query("SELECT COUNT(*) AS cnt FROM followers WHERE follower_id=$profile_id")->fetch_assoc()['cnt'] ?? 0);

// ── Username history ───────────────────────────────────────
$username_change_count = 0;
$tbl_check = $conn->query("SHOW TABLES LIKE 'username_history'");
if($tbl_check && $tbl_check->num_rows > 0){
    $username_change_count = (int)($conn->query("SELECT COUNT(*) AS cnt FROM username_history WHERE user_id=$profile_id")->fetch_assoc()['cnt'] ?? 0);
}

// ── Relationship data (other profiles only) ────────────────
$shared_followers_count = 0;
$shared_followers_list  = [];
$mutuals_count          = 0;
$mutuals_list           = [];

if(!$is_own_profile){

    // ── Shared Mutual Trackers ─────────────────────────────
    $sf_result = $conn->query("
        SELECT u.id, u.username, u.nickname, u.profile_pic, u.is_verified
        FROM   followers f1
        JOIN   followers f2 ON f2.follower_id  = f1.follower_id
        JOIN   users     u  ON u.id            = f1.follower_id
        WHERE  f1.following_id = $current_user
          AND  f2.following_id = $profile_id
          AND  f1.follower_id != $current_user
          AND  f1.follower_id != $profile_id
        ORDER BY u.username ASC
    ");
    if($sf_result){
        while($row = $sf_result->fetch_assoc()) $shared_followers_list[] = $row;
        $shared_followers_count = count($shared_followers_list);
    }

    // ── Mutuals ────────────────────────────────────────────
    $mut_result = $conn->query("
        SELECT u.id, u.username, u.nickname, u.profile_pic, u.is_verified
        FROM   followers f1
        JOIN   followers f2
            ON  f2.follower_id  = f1.following_id
           AND  f2.following_id = $profile_id
        JOIN   users u ON u.id = f1.following_id
        WHERE  f1.follower_id   = $current_user
          AND  f1.following_id != $current_user
          AND  f1.following_id != $profile_id
        ORDER BY u.username ASC
    ");
    if($mut_result){
        while($row = $mut_result->fetch_assoc()) $mutuals_list[] = $row;
        $mutuals_count = count($mutuals_list);
    }
}

// ── Location ───────────────────────────────────────────────
$user_location = null;
$about_result  = $conn->query("SELECT current_location, hometown FROM user_about WHERE user_id=$profile_id LIMIT 1");
if($about_result && $about_result->num_rows > 0){
    $about = $about_result->fetch_assoc();
    $cur   = trim((string)($about['current_location'] ?? ''));
    $home  = trim((string)($about['hometown'] ?? ''));
    $user_location = $cur !== '' ? $cur : ($home !== '' ? $home : null);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($user['username']); ?> | About This Account</title>
<!-- theme: applied before first paint so there is no dark flash in light mode (same key as every other Dezzme page) -->
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
    --fg:       255,255,255;   /* R,G,B of the text-on-surface tint used for lines + soft fills (dark theme = white) */
    --ui-text:  #ffffff;
    --red:      #FF2D2D;
    --ring:     #FF2D2D;       /* modal avatar border colour AND verified badge colour */
    --bg:       linear-gradient(-45deg,#010218,#070924,#070924);
    --surface:  rgba(var(--fg),0.05);
    --line:     rgba(var(--fg),0.09);
    --line2:    rgba(var(--fg),0.14);
    --text:     #ffffff;
    --muted:    rgba(var(--fg),0.45);
    --sub:      rgba(var(--fg),0.62);
    --card-bg:  rgba(12,12,28,0.98);   /* modal cards */
    --pop-bg:   rgba(8,8,22,.97);      /* "Profile ID copied" toast */
    --scrim:    rgba(0,0,0,0.7);
    --ph:       #1a1a1a;               /* avatar placeholder */
    --ok:       #4ade80;
    --scroll-thumb: rgba(var(--fg),.14);

    --max-w:    580px;
    --topbar-h: 54px;
    --pad:      clamp(9px, 3vw, 16px);
    --ctl-h:    clamp(30px, 8vw, 36px);
    --r:        5px;
}
@media (max-width:490px){ :root{ --topbar-h:48px; } }
@media (max-width:380px){ :root{ --topbar-h:44px; } }

html{ scroll-behavior:smooth; }

/* RESPONSIVE view: no scrollbars anywhere — scrolling still works. */
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

/* ── centered lane + grey side lines ── */
.ac-lane{
    width:100%; max-width:var(--max-w);
    min-height:100vh;
    display:flex; flex-direction:column;
    padding-bottom:72px;
}
@media (min-width:801px){
    .ac-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); }
}
@media (max-width:800px){ .ac-lane{ max-width:100%; } }

/* ══════════════════════════════════════
   TOPBAR — X on the left, "About This Account" on the right (no icon)
══════════════════════════════════════ */
.ac-topbar{
    position:sticky; top:0; z-index:500;
    height:var(--topbar-h);
    display:flex; align-items:center; gap:2px;
    padding:0 10px;
    background:var(--bg);
    background-attachment:fixed;
}
/* grey line under the topbar — its own layer so it shows on EVERY screen size
   (responsive included) and stays pinned while scrolling */
.ac-topbar::after{
    content:''; position:absolute; left:0; right:0; bottom:0;
    height:1px; background:rgba(var(--fg),0.14);
    pointer-events:none; z-index:2;
}
.ac-x{
    width:34px; height:34px;
    background:none; border:none; color:var(--ui-text);
    display:flex; align-items:center; justify-content:center;
    opacity:.9; transition:opacity .2s, color .2s;
    padding:0; flex-shrink:0;
}
.ac-x:hover{ opacity:1; color:var(--red); }
.ac-x svg{ width:22px; height:22px; display:block; }
.ac-title{
    flex:1; min-width:0;
    font-size:.92rem; font-weight:700; color:var(--ui-text); letter-spacing:-.1px;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    text-align:right; margin:0 6px 0 10px;
}
@media (max-width:490px){
    .ac-x{ width:30px; height:30px; }
    .ac-title{ font-size:.84rem; }
}
@media (max-width:380px){ .ac-title{ font-size:.8rem; } }

/* ── Profile header ── */
.profile-header{
    display:flex; flex-direction:column; align-items:center;
    padding:clamp(14px, 4vw, 22px) var(--pad) clamp(16px, 4.5vw, 24px);
    text-align:center;
}
.avatar{
    width:clamp(64px, 19vw, 90px); height:clamp(64px, 19vw, 90px);
    border-radius:50%; object-fit:cover; display:block;
    border:2.6px solid var(--red);
    box-shadow:0 4px 18px rgba(255,45,45,0.25);
    transition:transform .3s, box-shadow .3s;
    margin-bottom:12px;
}
.avatar:hover{ transform:scale(1.05); box-shadow:0 0 22px rgba(255,45,45,0.5); }
.profile-name{
    font-size:clamp(.88rem, 3.4vw, 1.05rem); font-weight:700;
    display:flex; align-items:center; gap:.3em; justify-content:center;
    margin-bottom:3px; max-width:100%;
}
.profile-name .pn-nm{ min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
/* verified badge next to the big name: 1em = follows the name size at every breakpoint, same colour as the avatar border */
.profile-name .vbadge{
    width:1em; height:1em; flex-shrink:0;
    vertical-align:baseline;
    color:var(--ring) !important;
    fill:currentColor;
}
.profile-label{
    font-size:clamp(.58rem, 1.9vw, .68rem); color:var(--muted); font-weight:500;
    text-transform:uppercase; letter-spacing:.08em;
}
.transparency-note{
    font-size:clamp(.6rem, 1.9vw, .68rem); color:var(--muted);
    line-height:1.65; margin-top:12px; max-width:340px; text-align:center;
}
.transparency-note a{ color:var(--red); text-decoration:none; font-weight:600; }

/* ── Stats bar ── */
.stats-bar{
    display:grid; grid-template-columns:repeat(3, 1fr);
    border-top:1px solid var(--line); border-bottom:1px solid var(--line);
}
.stat-item{ display:flex; flex-direction:column; align-items:center; padding:clamp(11px, 3.4vw, 16px) 6px; text-align:center; }
.stat-item + .stat-item{ border-left:1px solid var(--line); }
.stat-num{ font-size:clamp(.88rem, 3.2vw, 1.2rem); font-weight:700; color:var(--text); line-height:1; }
.stat-lbl{ font-size:clamp(.48rem, 1.7vw, .6rem); color:var(--muted); margin-top:4px; letter-spacing:.03em; text-transform:uppercase; }

/* ── Section label ── */
.section-label{
    padding:clamp(14px, 4vw, 20px) var(--pad) 6px;
    font-size:.6rem; font-weight:700; text-transform:uppercase; letter-spacing:1.5px; color:var(--muted);
}

/* ── Info list ── */
.info-list{ display:flex; flex-direction:column; }
.divider{ width:100%; border:none; border-top:1px solid var(--line); margin:0; }
.info-row{
    display:flex; align-items:center; gap:clamp(10px, 3vw, 14px);
    padding:clamp(11px, 3.2vw, 16px) var(--pad);
    transition:background .18s;
}
.info-row.clickable{ cursor:pointer; }
.info-row.clickable:hover{ background:rgba(var(--fg),0.04); }
.info-icon{
    width:clamp(27px, 7.6vw, 36px); height:clamp(27px, 7.6vw, 36px);
    border-radius:6px; background:var(--surface);
    display:flex; align-items:center; justify-content:center;
    flex-shrink:0; color:var(--red); font-size:clamp(.78rem, 2.6vw, 1rem);
    transition:background .2s;
}
.info-row.clickable:hover .info-icon{ background:rgba(255,45,45,0.12); }
.info-content{ flex:1; min-width:0; }
.info-label{
    font-size:clamp(.54rem, 1.8vw, .66rem); color:var(--muted); font-weight:400;
    line-height:1.2; text-transform:uppercase; letter-spacing:.05em;
}
.info-value{
    font-size:clamp(.72rem, 2.4vw, .86rem); font-weight:500; color:var(--text);
    line-height:1.4; margin-top:2px; word-break:break-word;
}
.chevron{ color:var(--muted); font-size:1rem; flex-shrink:0; }

/* ── Profile ID ── */
.profile-id-value{
    font-size:clamp(.7rem, 2.3vw, .86rem); font-weight:400; color:var(--text);
    line-height:1.3; margin-top:3px; letter-spacing:.06em;
    font-variant-numeric:tabular-nums;
}
.profile-id-value .pid-prefix{ color:var(--ui-text); }
.profile-id-value .pid-digits{ color:var(--red); }

.copy-btn{
    display:flex; align-items:center; justify-content:center; gap:5px;
    height:clamp(25px, 7.2vw, 30px); padding:0 12px; flex-shrink:0;
    background:rgba(var(--fg),0.1);
    backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    border:none; border-radius:4px;
    color:var(--ui-text); font-size:.7rem; font-weight:600; white-space:nowrap;
    transition:background .2s, color .2s, transform .15s;
}
.copy-btn:hover{ background:rgba(var(--fg),0.2); }
.copy-btn:active{ transform:scale(.97); }
.copy-btn.copied{ background:rgba(74,222,128,0.14); color:var(--ok); }
.copy-btn i{ font-size:.82rem; }
@media (max-width:490px){ .copy-btn{ font-size:.62rem; padding:0 9px; gap:4px; } }
@media (max-width:380px){ .copy-btn{ font-size:.58rem; padding:0 7px; } }

/* Copy toast */
#copy-toast{
    position:fixed; bottom:88px; left:50%;
    transform:translateX(-50%) translateY(12px);
    background:var(--pop-bg); border:1px solid rgba(74,222,128,.3);
    color:var(--ok); font-size:.74rem; font-weight:500;
    padding:9px 18px; border-radius:var(--r);
    opacity:0; pointer-events:none;
    transition:opacity .22s ease, transform .22s ease;
    z-index:999999; white-space:nowrap;
    box-shadow:0 8px 24px rgba(0,0,0,.6);
}
#copy-toast.show{ opacity:1; transform:translateX(-50%) translateY(0); }

/* ── Notes ── */
.mutual-notes{ padding:clamp(11px, 3.4vw, 16px) var(--pad); display:flex; flex-direction:column; gap:8px; }
.mutual-note{ font-size:clamp(.6rem, 1.9vw, .7rem); color:var(--muted); line-height:1.65; }
.mutual-note strong{ color:rgba(var(--fg),0.7); font-weight:600; }

/* ══════════════════════════════════════
   MODALS — centered form (desktop) / bottom sheet (responsive)
══════════════════════════════════════ */
.u-modal-overlay, .modal-overlay{
    position:fixed; inset:0;
    background:var(--scrim);
    backdrop-filter:blur(4px); -webkit-backdrop-filter:blur(4px);
    display:none; align-items:flex-end; justify-content:center;
}
.u-modal-overlay{ z-index:999999; }
.modal-overlay{ z-index:99999; }
.u-modal-overlay.open{ display:flex; animation:acFade .18s ease both; }
.modal-overlay.show{ display:flex; animation:acFade .18s ease both; }
@keyframes acFade{ from{ opacity:0; } to{ opacity:1; } }
@keyframes acSlideUp{ from{ transform:translateY(60px); opacity:0; } to{ transform:none; opacity:1; } }

.u-modal-card, .modal{
    position:relative; width:100%; max-width:480px;
    background:var(--card-bg);
    border:1px solid var(--line); border-bottom:none;
    border-radius:10px 10px 0 0;
    animation:acSlideUp .28s cubic-bezier(.32,.72,0,1);
}
.u-modal-card{ max-height:78vh; display:flex; flex-direction:column; overflow:hidden; }
.u-modal-card::before, .modal::before{
    content:''; display:block; flex-shrink:0;
    width:36px; height:4px; background:rgba(var(--fg),.18);
    border-radius:4px; margin:10px auto 2px;
}
.modal{ padding:0 18px calc(env(safe-area-inset-bottom, 0px) + 18px); }

.u-modal-header{
    display:flex; align-items:center; justify-content:space-between;
    padding:10px var(--pad) 10px; border-bottom:1px solid var(--line); flex-shrink:0;
}
.u-modal-title{ font-size:.88rem; font-weight:700; color:var(--ui-text); display:flex; align-items:center; gap:8px; }
.u-modal-badge{
    font-size:.64rem; font-weight:700; line-height:1;
    background:rgba(255,45,45,.14); color:var(--red);
    border-radius:4px; padding:3px 7px;
}
.u-modal-close, .modal-close{
    width:28px; height:28px; flex-shrink:0;
    background:rgba(var(--fg),.08); border:none; border-radius:4px;
    color:var(--sub); font-size:1.05rem;
    display:flex; align-items:center; justify-content:center;
    transition:background .2s, color .2s;
}
.u-modal-close:hover, .modal-close:hover{ background:rgba(var(--fg),.18); color:var(--ui-text); }
.modal-close{ position:absolute; top:12px; right:12px; }

.u-modal-search-wrap{ padding:10px var(--pad) 8px; flex-shrink:0; position:relative; }
.u-modal-search-icon{
    position:absolute; left:calc(var(--pad) + 11px); top:50%; transform:translateY(-50%);
    color:var(--muted); font-size:.95rem; pointer-events:none;
}
.u-modal-search{
    width:100%; height:var(--ctl-h);
    background:var(--surface); border:1px solid var(--line2); border-radius:var(--r);
    padding:0 12px 0 34px; font-size:.76rem; color:var(--ui-text); outline:none;
    transition:border-color .2s, background .2s, box-shadow .2s;
    -webkit-appearance:none; appearance:none;
}
.u-modal-search::placeholder{ color:var(--muted); }
.u-modal-search:focus{
    border-color:rgba(255,45,45,.7); background:rgba(var(--fg),.07);
    box-shadow:0 0 0 3px rgba(255,45,45,.12);
}

.u-modal-list{
    overflow-y:auto; flex:1; padding:2px 0 24px;
    scrollbar-width:thin; scrollbar-color:var(--scroll-thumb) transparent;
}
.u-modal-list::-webkit-scrollbar{ width:4px; }
.u-modal-list::-webkit-scrollbar-thumb{ background:var(--scroll-thumb); border-radius:99px; }

.u-modal-row{
    display:flex; align-items:center; gap:clamp(10px, 3vw, 13px);
    padding:11px var(--pad); cursor:pointer; transition:background .15s;
}
.u-modal-row:hover{ background:rgba(var(--fg),.05); }
.u-modal-row:active{ background:rgba(255,45,45,.07); }
.u-modal-row.hidden{ display:none; }

/* modal avatar border colour = --ring (same variable as the verified badge) */
.u-modal-pic{
    width:clamp(36px, 10vw, 46px); height:clamp(36px, 10vw, 46px);
    border-radius:50%; object-fit:cover; border:2px solid var(--ring);
    flex-shrink:0; background:var(--ph);
}
@media (max-width:490px){ .u-modal-pic{ border-width:1.5px; } }
.u-modal-info{ flex:1; min-width:0; display:flex; flex-direction:column; gap:2px; }
/* name + badge on one line; a long name is cut with "…" but the badge never is */
.u-modal-uname{
    font-size:clamp(.74rem, 2.4vw, .84rem); font-weight:600; color:var(--ui-text);
    display:flex; align-items:center; gap:.3em; min-width:0;
}
.u-modal-uname .un-nm{
    min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
/* verified badge: filled, same size as the name text (1em follows every responsive
   font-size below), colour = same variable as the avatar border, dark AND light mode */
.u-modal-uname .vbadge{
    width:1em; height:1em;
    vertical-align:baseline;
    flex-shrink:0;
    color:var(--ring) !important;
    fill:currentColor;
}
.u-modal-nick{ font-size:clamp(.6rem, 1.9vw, .7rem); color:var(--muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.u-modal-arrow{ color:rgba(var(--fg),.2); font-size:1.05rem; flex-shrink:0; transition:transform .2s, color .2s; }
.u-modal-row:hover .u-modal-arrow{ transform:translateX(3px); color:var(--red); }

.u-modal-empty{
    padding:40px 20px; text-align:center; color:var(--muted); font-size:.78rem;
    display:none; flex-direction:column; align-items:center; gap:8px;
}
.u-modal-empty i{ font-size:1.8rem; color:var(--red); opacity:.8; }
.u-modal-empty.show{ display:flex; }

/* username-history modal content */
.modal h3{ font-size:.9rem; font-weight:700; margin:6px 34px 10px 0; display:flex; align-items:center; gap:8px; color:var(--ui-text); }
.modal h3 i{ color:var(--red); }
.modal p{ font-size:.78rem; color:var(--muted); line-height:1.65; }
.modal p strong{ color:var(--text); font-weight:600; }

@media (min-width:600px){
    .u-modal-overlay, .modal-overlay{ align-items:center; padding:20px; }
    .u-modal-card, .modal{ border-radius:8px; border-bottom:1px solid var(--line); max-width:400px; }
    .u-modal-card::before, .modal::before{ display:none; }
    .modal{ padding:20px; }
    .modal h3{ margin-top:0; }
}
@media (max-width:490px){
    .u-modal-card{ max-height:80vh; }
    .u-modal-title{ font-size:.82rem; }
    .u-modal-search{ font-size:.7rem; padding-left:32px; }
    .modal{ padding:0 14px calc(env(safe-area-inset-bottom, 0px) + 14px); }
    .modal h3{ font-size:.84rem; }
    .modal p{ font-size:.72rem; }
}
@media (max-width:380px){
    .u-modal-title{ font-size:.78rem; }
    .modal{ padding:0 12px calc(env(safe-area-inset-bottom, 0px) + 12px); }
}

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
.sk{ position:relative; overflow:hidden; background:rgba(var(--fg),.06); border-radius:6px; }
.sk::after{
    content:''; position:absolute; inset:0;
    background:linear-gradient(90deg, transparent, rgba(var(--fg),.09), transparent);
    transform:translateX(-100%); animation:skShimmer 1.5s infinite;
}
@keyframes skShimmer{ 100%{ transform:translateX(100%); } }
.sk-topbar{ height:var(--topbar-h); display:flex; align-items:center; justify-content:space-between; padding:0 16px 0 14px; border-bottom:1px solid rgba(var(--fg),0.14); }
.sk-back{ width:20px; height:20px; }
.sk-title{ width:130px; height:14px; }
.sk-avatar-block{ display:flex; flex-direction:column; align-items:center; gap:10px; padding:20px 0; }
.sk-avatar-circle{ width:clamp(64px,19vw,90px); height:clamp(64px,19vw,90px); border-radius:50%; }
.sk-name{ width:130px; height:14px; }
.sk-sub{ width:90px; height:10px; }
.sk-row-item{ display:flex; align-items:center; gap:14px; padding:clamp(11px,3.2vw,16px) var(--pad); border-top:1px solid var(--line); }
.sk-icon{ width:clamp(27px,7.6vw,36px); height:clamp(27px,7.6vw,36px); border-radius:6px; flex-shrink:0; }
.sk-text{ display:flex; flex-direction:column; gap:6px; }
.sk-lbl{ width:80px; height:9px; }
.sk-val{ width:140px; height:12px; }

/* ═══════════════════════════════════════════════════════════════
   RESPONSIVE ONLY — everything 10% bigger (desktop untouched)
   Kept near the end so it wins over the rules above.
   The verified badge is 1em, so it grows with the name text here too.
═══════════════════════════════════════════════════════════════ */
@media (max-width:800px){
    :root{
        --topbar-h:59px;
        --pad:clamp(10px, 3.3vw, 17.6px);
        --ctl-h:clamp(33px, 8.8vw, 39.6px);
        --r:5.5px;
    }

    /* topbar */
    .ac-topbar{ padding:0 11px; gap:2px; }
    .ac-x{ width:37px; height:37px; }
    .ac-x svg{ width:24px; height:24px; }
    .ac-title{ font-size:1.01rem; margin:0 7px 0 11px; }

    /* profile header */
    .profile-header{ padding:clamp(15px, 4.4vw, 24px) var(--pad) clamp(18px, 5vw, 26px); }
    .avatar{ width:clamp(70px, 20.9vw, 99px); height:clamp(70px, 20.9vw, 99px); border-width:2.9px; margin-bottom:13px; }
    .profile-name{ font-size:clamp(.97rem, 3.74vw, 1.155rem); gap:.3em; margin-bottom:3.3px; }
    .profile-name .vbadge{ width:1em; height:1em; }
    .profile-label{ font-size:clamp(.64rem, 2.09vw, .75rem); }
    .transparency-note{ font-size:clamp(.66rem, 2.09vw, .75rem); margin-top:13px; max-width:374px; }

    /* stats */
    .stat-item{ padding:clamp(12px, 3.74vw, 17.6px) 6.6px; }
    .stat-num{ font-size:clamp(.97rem, 3.52vw, 1.32rem); }
    .stat-lbl{ font-size:clamp(.53rem, 1.87vw, .66rem); margin-top:4.4px; }

    /* section label */
    .section-label{ padding:clamp(15px, 4.4vw, 22px) var(--pad) 6.6px; font-size:.66rem; letter-spacing:1.65px; }

    /* info rows */
    .info-row{ gap:clamp(11px, 3.3vw, 15px); padding:clamp(12px, 3.52vw, 17.6px) var(--pad); }
    .info-icon{ width:clamp(30px, 8.36vw, 39.6px); height:clamp(30px, 8.36vw, 39.6px); border-radius:6.6px; font-size:clamp(.86rem, 2.86vw, 1.1rem); }
    .info-label{ font-size:clamp(.59rem, 1.98vw, .73rem); }
    .info-value{ font-size:clamp(.79rem, 2.64vw, .95rem); margin-top:2.2px; }
    .chevron{ font-size:1.1rem; }

    /* profile id + copy */
    .profile-id-value{ font-size:clamp(.77rem, 2.53vw, .95rem); margin-top:3.3px; }
    .copy-btn{ height:clamp(27.5px, 7.92vw, 33px); padding:0 13px; gap:5.5px; font-size:.77rem; border-radius:4.4px; }
    .copy-btn i{ font-size:.9rem; }
    #copy-toast{ bottom:97px; font-size:.81rem; padding:10px 20px; }

    /* notes */
    .mutual-notes{ padding:clamp(12px, 3.74vw, 17.6px) var(--pad); gap:8.8px; }
    .mutual-note{ font-size:clamp(.66rem, 2.09vw, .77rem); }

    /* modals */
    .u-modal-card::before, .modal::before{ width:40px; height:4.4px; margin:11px auto 2px; }
    .modal{ padding:0 20px calc(env(safe-area-inset-bottom, 0px) + 20px); }
    .u-modal-header{ padding:11px var(--pad); }
    .u-modal-title{ font-size:.97rem; gap:8.8px; }
    .u-modal-badge{ font-size:.7rem; padding:3.3px 7.7px; }
    .u-modal-close, .modal-close{ width:31px; height:31px; font-size:1.15rem; }
    .modal-close{ top:13px; right:13px; }
    .u-modal-search-wrap{ padding:11px var(--pad) 9px; }
    .u-modal-search-icon{ left:calc(var(--pad) + 12px); font-size:1.05rem; }
    .u-modal-search{ font-size:.84rem; padding:0 13px 0 37px; }
    .u-modal-list{ padding:2px 0 26px; }
    .u-modal-row{ gap:clamp(11px, 3.3vw, 14px); padding:12px var(--pad); }
    .u-modal-pic{ width:clamp(40px, 11vw, 51px); height:clamp(40px, 11vw, 51px); }
    .u-modal-info{ gap:2.2px; }
    .u-modal-uname{ font-size:clamp(.81rem, 2.64vw, .92rem); gap:.3em; }
    .u-modal-uname .vbadge{ width:1em; height:1em; }
    .u-modal-nick{ font-size:clamp(.66rem, 2.09vw, .77rem); }
    .u-modal-arrow{ font-size:1.15rem; }
    .u-modal-empty{ padding:44px 22px; font-size:.86rem; gap:8.8px; }
    .u-modal-empty i{ font-size:2rem; }
    .modal h3{ font-size:.99rem; margin:6.6px 37px 11px 0; gap:8.8px; }
    .modal p{ font-size:.86rem; }

    /* skeleton */
    .sk-topbar{ padding:0 17.6px 0 15px; }
    .sk-back{ width:22px; height:22px; }
    .sk-title{ width:143px; height:15.4px; }
    .sk-avatar-block{ gap:11px; padding:22px 0; }
    .sk-avatar-circle{ width:clamp(70px, 20.9vw, 99px); height:clamp(70px, 20.9vw, 99px); }
    .sk-name{ width:143px; height:15.4px; }
    .sk-sub{ width:99px; height:11px; }
    .sk-row-item{ gap:15px; padding:clamp(12px, 3.52vw, 17.6px) var(--pad); }
    .sk-icon{ width:clamp(30px, 8.36vw, 39.6px); height:clamp(30px, 8.36vw, 39.6px); border-radius:6.6px; }
    .sk-text{ gap:6.6px; }
    .sk-lbl{ width:88px; height:10px; }
    .sk-val{ width:154px; height:13px; }
}

/* tablet-ish range where modals are centered cards, not bottom sheets */
@media (min-width:600px) and (max-width:800px){
    .u-modal-card, .modal{ max-width:440px; }
    .modal{ padding:22px; }
    .modal h3{ margin-top:0; }
}

@media (max-width:490px){
    :root{ --topbar-h:53px; }
    .ac-x{ width:33px; height:33px; }
    .ac-title{ font-size:.92rem; }
    .copy-btn{ font-size:.68rem; padding:0 10px; gap:4.4px; }
    .u-modal-pic{ border-width:1.65px; }
    .u-modal-uname{ gap:.28em; }
    .u-modal-uname .vbadge{ width:1em; height:1em; }
    .u-modal-title{ font-size:.9rem; }
    .u-modal-search{ font-size:.77rem; padding-left:35px; }
    .modal{ padding:0 15px calc(env(safe-area-inset-bottom, 0px) + 15px); }
    .modal h3{ font-size:.92rem; }
    .modal p{ font-size:.79rem; }
}

@media (max-width:380px){
    :root{ --topbar-h:48px; }
    .ac-title{ font-size:.88rem; }
    .copy-btn{ font-size:.64rem; padding:0 8px; }
    .u-modal-uname{ gap:.26em; }
    .u-modal-uname .vbadge{ width:1em; height:1em; }
    .u-modal-title{ font-size:.86rem; }
    .modal{ padding:0 13px calc(env(safe-area-inset-bottom, 0px) + 13px); }
}


/* ═══════════════════════════════════════════════════════════════
   BOTTOM SHEETS — same design as the profile bottom sheets:
   lane-coloured sheet, thin handle, one rounded inner card with a tiny
   uppercase title, compact rows with hairline dividers, and a separate
   Close card at the bottom. Kept last so it wins over the rules above.
═══════════════════════════════════════════════════════════════ */
.u-modal-overlay{
    --sh-s:1.1;
    --sh-lane:#070924; --sh-card:#090b29;
    background:rgba(0,0,0,.6); backdrop-filter:blur(2px); -webkit-backdrop-filter:blur(2px);
    padding:0; align-items:flex-end; justify-content:center;
}
:root[data-theme="light"] .u-modal-overlay{ --sh-lane:#ffffff; --sh-card:#eef1f7; background:rgba(6,7,26,.45); }
.u-modal-overlay .u-modal-card{
    width:100%; max-width:var(--max-w); max-height:80vh; max-height:80dvh;
    display:flex; flex-direction:column; gap:calc(8px * var(--sh-s));
    background:var(--sh-lane); border:0; border-radius:0;
    padding:calc(5px * var(--sh-s)) calc(10px * var(--sh-s)) calc(env(safe-area-inset-bottom,0px) + 10px * var(--sh-s));
    overflow:hidden; animation:acSheetUp .3s cubic-bezier(.32,.72,0,1) both;
}
@keyframes acSheetUp{ from{ transform:translateY(100%); } to{ transform:none; } }
.u-modal-overlay .u-modal-card::before{ display:none; }
@media (min-width:801px){
    .u-modal-overlay .u-modal-card{ border-left:1px solid var(--line); border-right:1px solid var(--line); border-top:1px solid var(--line); }
}
.u-sh-handle{ width:calc(30px * var(--sh-s)); height:calc(3px * var(--sh-s)); border-radius:2px; background:rgba(var(--fg),.22); margin:calc(3px * var(--sh-s)) auto 0; flex-shrink:0; }
.u-sh-inner{ background:var(--sh-card); border-radius:calc(6px * var(--sh-s)); display:flex; flex-direction:column; min-height:0; flex:0 1 auto; overflow:hidden; }
.u-sh-title{
    display:flex; align-items:center; gap:calc(6px * var(--sh-s));
    font-size:calc(8.5px * var(--sh-s)); font-weight:400; color:rgba(var(--fg),.35);
    letter-spacing:1px; text-transform:uppercase; padding:calc(7px * var(--sh-s)) calc(12px * var(--sh-s)) calc(3px * var(--sh-s));
}
.u-sh-title .u-modal-badge{ background:none; color:rgba(var(--fg),.5); padding:0; font-size:inherit; font-weight:600; border-radius:0; }
.u-sh-close{
    display:flex; align-items:center; justify-content:center; width:100%; flex-shrink:0;
    padding:calc(10px * var(--sh-s)); background:var(--sh-card); border:0; border-radius:calc(6px * var(--sh-s));
    color:rgba(var(--fg),.85); font-size:calc(12px * var(--sh-s)); font-weight:600; font-family:'Poppins',sans-serif;
    transition:background .2s, color .2s;
}
.u-sh-close:hover{ color:var(--red); }
.u-modal-overlay .u-modal-search-wrap{ padding:calc(6px * var(--sh-s)) calc(12px * var(--sh-s)) calc(6px * var(--sh-s)); }
.u-modal-overlay .u-modal-search-icon{ left:calc(12px + 11px * var(--sh-s)); font-size:calc(13px * var(--sh-s)); }
.u-modal-overlay .u-modal-search{ height:calc(30px * var(--sh-s)); font-size:calc(11px * var(--sh-s)); padding:0 calc(10px * var(--sh-s)) 0 calc(32px * var(--sh-s)); background:rgba(var(--fg),.06); border-color:transparent; border-radius:calc(5px * var(--sh-s)); }
.u-modal-overlay .u-modal-list{ flex:1; min-height:0; padding:0; scrollbar-width:thin; scrollbar-color:rgba(var(--fg),.15) transparent; }
.u-modal-overlay .u-modal-row{ gap:calc(9px * var(--sh-s)); padding:calc(6px * var(--sh-s)) calc(12px * var(--sh-s)); border-top:1px solid rgba(var(--fg),.08); }
.u-modal-overlay .u-modal-pic{ width:calc(34px * var(--sh-s)); height:calc(34px * var(--sh-s)); border-width:1.5px; }
.u-modal-overlay .u-modal-uname{ font-size:calc(11.5px * var(--sh-s)); font-weight:600; }
.u-modal-overlay .u-modal-nick{ font-size:calc(9px * var(--sh-s)); color:rgba(var(--fg),.4); margin-top:1px; }
.u-modal-overlay .u-modal-arrow{ font-size:calc(15px * var(--sh-s)); }
.u-modal-overlay .u-modal-empty{ padding:calc(28px * var(--sh-s)) 20px; font-size:calc(11px * var(--sh-s)); }
.uh-body{ padding:calc(8px * var(--sh-s)) calc(12px * var(--sh-s)) calc(14px * var(--sh-s)); }
.uh-body p{ font-size:calc(11.5px * var(--sh-s)); color:var(--muted); line-height:1.65; }
.uh-body p strong{ color:var(--text); font-weight:600; }

/* ═══════════ LIGHT MODE — <html data-theme="light"> (dark mode is never touched) ═══════════
   Same palette as the Community, Games, Algorithm and Upload pages. The red accent stays the same in both themes. */
:root[data-theme="light"]{
    color-scheme:light;
    --fg:6,7,26;
    --ui-text:#0b0d24;
    --text:#0b0d24;
    --bg:linear-gradient(-45deg,#ffffff,#f4f5fb,#f4f5fb);
    --surface:rgba(6,7,26,.05);
    --line:rgba(6,7,26,.1);
    --line2:rgba(6,7,26,.2);
    --muted:rgba(6,7,26,.52);
    --sub:rgba(6,7,26,.66);
    --card-bg:#ffffff;
    --pop-bg:#ffffff;
    --scrim:rgba(6,7,26,.45);
    --ph:#eceef5;
    --ok:#15a34a;
    --scroll-thumb:rgba(42,95,216,.4);
}
[data-theme="light"] #copy-toast{ border-color:rgba(21,163,74,.35); box-shadow:0 8px 24px rgba(0,0,0,.18); }
[data-theme="light"] .copy-btn.copied{ background:rgba(21,163,74,.12); }
/* badge keeps the avatar-border colour in light mode too (the name text turns dark, the badge does not) */
[data-theme="light"] .u-modal-uname .vbadge,
[data-theme="light"] .profile-name .vbadge{ color:var(--ring) !important; }
</style>
</head>
<body>

<?php echo dz_badge_sprite(); ?>

<!-- ══ SKELETON ══ -->
<div id="page-skeleton">
    <div class="sk-lane">
        <div class="sk-topbar">
            <div class="sk sk-back"></div>
            <div class="sk sk-title"></div>
        </div>
        <div class="sk-avatar-block">
            <div class="sk sk-avatar-circle"></div>
            <div class="sk sk-name"></div>
            <div class="sk sk-sub"></div>
        </div>
        <?php for($i=0;$i<5;$i++): ?>
        <div class="sk-row-item">
            <div class="sk sk-icon"></div>
            <div class="sk-text"><div class="sk sk-lbl"></div><div class="sk sk-val"></div></div>
        </div>
        <?php endfor; ?>
    </div>
</div>

<div class="ac-lane">

    <!-- ══ TOPBAR ══ -->
    <div class="ac-topbar">
        <button type="button" class="ac-x" onclick="acClose()" aria-label="Close">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
        <span class="ac-title">About This Account</span>
    </div>

    <!-- ══ PROFILE HEADER ══ -->
    <div class="profile-header">
        <img class="avatar" src="<?php echo htmlspecialchars($user['profile_pic'] ?: 'uploads/me.png'); ?>" onerror="this.src='uploads/me.png'" alt="Profile picture">
        <div class="profile-name">
            <span class="pn-nm"><?php echo $is_own_profile ? 'Your Account' : htmlspecialchars($user['username']); ?></span><?php echo !empty($user['is_verified']) ? dz_badge('f') : ''; ?>
        </div>
        <div class="profile-label">About This Account</div>
        <p class="transparency-note">
            Dezzme shows this page to help the community understand who they're interacting with. Account age, username history, and location give you the context to decide whether an account is genuine before you connect, follow, or share with them.
            <a href="help.php#transparency">Learn More</a>
        </p>
    </div>

    <!-- ══ STATS BAR ══ -->
    <div class="stats-bar">
        <div class="stat-item">
            <div class="stat-num"><?php echo number_format($followers_count); ?></div>
            <div class="stat-lbl">Trackers</div>
        </div>
        <div class="stat-item">
            <div class="stat-num"><?php echo number_format($following_count); ?></div>
            <div class="stat-lbl">Tracking</div>
        </div>
        <div class="stat-item">
            <div class="stat-num"><?php echo number_format($memes_count); ?></div>
            <div class="stat-lbl">Posts</div>
        </div>
    </div>

    <!-- ══ INFO LIST ══ -->
    <div class="section-label">Account Info</div>
    <div class="info-list">

        <hr class="divider">

        <!-- Date Joined -->
        <div class="info-row">
            <div class="info-icon"><i class="ri-calendar-check-line"></i></div>
            <div class="info-content">
                <div class="info-label">Date Joined</div>
                <div class="info-value"><?php echo date('F j, Y', strtotime($user['created_at'])); ?></div>
            </div>
        </div>

        <hr class="divider">

        <!-- Profile ID -->
        <div class="info-row">
            <div class="info-icon"><i class="ri-fingerprint-line"></i></div>
            <div class="info-content">
                <div class="info-label">Profile ID</div>
                <div class="profile-id-value" id="dz-pid"><span class="pid-prefix">DZ-</span><span class="pid-digits"><?php echo htmlspecialchars($dz_digits); ?></span></div>
            </div>
            <button type="button" class="copy-btn" id="copy-pid-btn" onclick="copyProfileId()" aria-label="Copy Profile ID">
                <i class="ri-file-copy-line"></i> Copy
            </button>
        </div>

        <hr class="divider">

        <!-- Location -->
        <div class="info-row">
            <div class="info-icon"><i class="ri-map-pin-2-line"></i></div>
            <div class="info-content">
                <div class="info-label">Account Based In</div>
                <div class="info-value" <?php echo !$user_location ? 'style="color:var(--muted)"' : ''; ?>>
                    <?php echo $user_location ? htmlspecialchars($user_location) : 'Not set'; ?>
                </div>
            </div>
        </div>

        <?php if(!empty($user['is_verified'])): ?>
        <hr class="divider">

        <!-- Verified -->
        <div class="info-row">
            <div class="info-icon"><i class="ri-verified-badge-line"></i></div>
            <div class="info-content">
                <div class="info-label">Verified</div>
                <div class="info-value"><?php echo $verified_date ? htmlspecialchars($verified_date) : '<span style="color:var(--muted)">Verified account</span>'; ?></div>
            </div>
        </div>
        <?php endif; ?>

        <hr class="divider">

        <!-- Former usernames -->
        <div class="info-row clickable" onclick="openUsernameModal()">
            <div class="info-icon"><i class="ri-at-line"></i></div>
            <div class="info-content">
                <div class="info-label">Former Usernames</div>
                <div class="info-value">
                    <?php
                    $times = $username_change_count;
                    echo $times == 0
                        ? '<span style="color:var(--muted)">No changes</span>'
                        : $times . ' ' . ($times == 1 ? 'change' : 'changes');
                    ?>
                </div>
            </div>
            <i class="ri-arrow-right-s-line chevron"></i>
        </div>

        <hr class="divider">

        <!-- Posts -->
        <div class="info-row">
            <div class="info-icon"><i class="ri-image-2-line"></i></div>
            <div class="info-content">
                <div class="info-label">Memes Posted</div>
                <div class="info-value"><?php echo number_format($memes_count); ?></div>
            </div>
        </div>

        <?php if(!$is_own_profile): ?>

        <hr class="divider">

        <!-- Shared Mutual Trackers -->
        <div class="info-row <?php echo $shared_followers_count > 0 ? 'clickable' : ''; ?>"
             <?php echo $shared_followers_count > 0 ? 'onclick="openUserModal(\'shared\')"' : ''; ?>>
            <div class="info-icon"><i class="ri-group-line"></i></div>
            <div class="info-content">
                <div class="info-label">Shared Mutual Trackers</div>
                <div class="info-value"><?php echo number_format($shared_followers_count); ?> in common</div>
            </div>
            <?php if($shared_followers_count > 0): ?>
            <i class="ri-arrow-right-s-line chevron"></i>
            <?php endif; ?>
        </div>

        <hr class="divider">

        <!-- Mutuals -->
        <div class="info-row <?php echo $mutuals_count > 0 ? 'clickable' : ''; ?>"
             <?php echo $mutuals_count > 0 ? 'onclick="openUserModal(\'mutuals\')"' : ''; ?>>
            <div class="info-icon"><i class="ri-exchange-line"></i></div>
            <div class="info-content">
                <div class="info-label">Mutuals</div>
                <div class="info-value"><?php echo number_format($mutuals_count); ?> mutual<?php echo $mutuals_count == 1 ? '' : 's'; ?></div>
            </div>
            <?php if($mutuals_count > 0): ?>
            <i class="ri-arrow-right-s-line chevron"></i>
            <?php endif; ?>
        </div>

        <?php endif; ?>

        <hr class="divider">

    </div>

    <!-- ══ DEFINITIONS ══ -->
    <?php if(!$is_own_profile): ?>
    <div class="mutual-notes">
        <?php if(!empty($user['is_verified'])): ?>
        <div class="mutual-note">
            <strong>Verified</strong> means Dezzme has confirmed that this account belongs to who or what it claims to be. A verified account shows a badge beside its username, and the date above is when that verification was granted.
        </div>
        <?php endif; ?>
        <div class="mutual-note">
            <strong>Shared Mutual Trackers</strong> are users who track both you and <?php echo htmlspecialchars($user['username']); ?>.
        </div>
        <div class="mutual-note">
            <strong>Mutuals</strong> are people you track who also track <?php echo htmlspecialchars($user['username']); ?> — showing your shared connections with this account.
        </div>
        <div class="mutual-note">
            <strong>Profile IDs</strong> are permanent account identifiers used for authenticity, moderation, reporting, and account recognition across Dezzme. Even if a username changes multiple times, the Profile ID remains the same forever — making it easier to identify accounts and adding another layer of transparency and trust within the Dezzme community.
        </div>
    </div>
    <?php else: ?>
    <div class="mutual-notes">
        <?php if(!empty($user['is_verified'])): ?>
        <div class="mutual-note">
            <strong>Verified</strong> means Dezzme has confirmed that this account belongs to who or what it claims to be. A verified account shows a badge beside its username, and the date above is when that verification was granted.
        </div>
        <?php endif; ?>
        <div class="mutual-note">
            <strong>Profile IDs</strong> are permanent account identifiers used for authenticity, moderation, reporting, and account recognition across Dezzme. Even if a username changes multiple times, the Profile ID remains the same forever — making it easier to identify accounts and adding another layer of transparency and trust within the Dezzme community.
        </div>
    </div>
    <?php endif; ?>

</div><!-- /.ac-lane -->

<!-- ══ COPY TOAST ══ -->
<div id="copy-toast"><i class="ri-check-line" style="margin-right:5px;"></i>Profile ID copied.</div>

<!-- ══ SHARED TRACKERS MODAL ══ -->
<div class="u-modal-overlay" id="sharedModal" onclick="closeOnBackdrop(event,'sharedModal')">
    <div class="u-modal-card">
        <div class="u-sh-handle"></div>
        <div class="u-sh-inner">
        <div class="u-sh-title">Shared Trackers <span class="u-modal-badge"><?php echo $shared_followers_count; ?></span></div>
        <div class="u-modal-search-wrap">
            <i class="ri-search-line u-modal-search-icon"></i>
            <input type="text" class="u-modal-search" placeholder="Search users…"
                   oninput="filterModal(this,'sharedModal')" autocomplete="off">
        </div>
        <div class="u-modal-list">
            <?php foreach($shared_followers_list as $u):
                $pic   = htmlspecialchars($u['profile_pic'] ?: 'uploads/me.png');
                $uname = htmlspecialchars($u['username']);
                $nick  = !empty($u['nickname']) ? htmlspecialchars($u['nickname']) : '';
                $uid   = (int)$u['id'];
                $vbdg  = !empty($u['is_verified']) ? dz_badge('f') : '';
            ?>
            <div class="u-modal-row" data-name="<?php echo strtolower($uname.' '.$nick); ?>"
                 onclick="location.href='profile.php?user_id=<?php echo $uid; ?>'">
                <img src="<?php echo $pic; ?>" onerror="this.src='uploads/me.png'" class="u-modal-pic" loading="lazy" alt="">
                <div class="u-modal-info">
                    <div class="u-modal-uname"><span class="un-nm"><?php echo $uname; ?></span><?php echo $vbdg; ?></div>
                    <?php if($nick): ?><div class="u-modal-nick">@<?php echo $nick; ?></div><?php endif; ?>
                </div>
                <i class="ri-arrow-right-s-line u-modal-arrow"></i>
            </div>
            <?php endforeach; ?>
            <?php if(empty($shared_followers_list)): ?>
            <div class="u-modal-empty show"><i class="ri-group-line"></i><span>No shared trackers</span></div>
            <?php else: ?>
            <div class="u-modal-empty" id="sharedModal-empty"><i class="ri-search-line"></i><span>No users found</span></div>
            <?php endif; ?>
        </div>
        </div>
        <button type="button" class="u-sh-close" onclick="closeUserModal('sharedModal')">Close</button>
    </div>
</div>

<!-- ══ MUTUALS MODAL ══ -->
<div class="u-modal-overlay" id="mutualsModal" onclick="closeOnBackdrop(event,'mutualsModal')">
    <div class="u-modal-card">
        <div class="u-sh-handle"></div>
        <div class="u-sh-inner">
        <div class="u-sh-title">Mutuals <span class="u-modal-badge"><?php echo $mutuals_count; ?></span></div>
        <div class="u-modal-search-wrap">
            <i class="ri-search-line u-modal-search-icon"></i>
            <input type="text" class="u-modal-search" placeholder="Search users…"
                   oninput="filterModal(this,'mutualsModal')" autocomplete="off">
        </div>
        <div class="u-modal-list">
            <?php foreach($mutuals_list as $u):
                $pic   = htmlspecialchars($u['profile_pic'] ?: 'uploads/me.png');
                $uname = htmlspecialchars($u['username']);
                $nick  = !empty($u['nickname']) ? htmlspecialchars($u['nickname']) : '';
                $uid   = (int)$u['id'];
                $vbdg  = !empty($u['is_verified']) ? dz_badge('f') : '';
            ?>
            <div class="u-modal-row" data-name="<?php echo strtolower($uname.' '.$nick); ?>"
                 onclick="location.href='profile.php?user_id=<?php echo $uid; ?>'">
                <img src="<?php echo $pic; ?>" onerror="this.src='uploads/me.png'" class="u-modal-pic" loading="lazy" alt="">
                <div class="u-modal-info">
                    <div class="u-modal-uname"><span class="un-nm"><?php echo $uname; ?></span><?php echo $vbdg; ?></div>
                    <?php if($nick): ?><div class="u-modal-nick">@<?php echo $nick; ?></div><?php endif; ?>
                </div>
                <i class="ri-arrow-right-s-line u-modal-arrow"></i>
            </div>
            <?php endforeach; ?>
            <?php if(empty($mutuals_list)): ?>
            <div class="u-modal-empty show"><i class="ri-exchange-line"></i><span>No mutuals yet</span></div>
            <?php else: ?>
            <div class="u-modal-empty" id="mutualsModal-empty"><i class="ri-search-line"></i><span>No users found</span></div>
            <?php endif; ?>
        </div>
        </div>
        <button type="button" class="u-sh-close" onclick="closeUserModal('mutualsModal')">Close</button>
    </div>
</div>

<!-- ══ USERNAME MODAL ══ -->
<div class="u-modal-overlay" id="usernameModal" onclick="closeOnBackdrop(event,'usernameModal')">
    <div class="u-modal-card">
        <div class="u-sh-handle"></div>
        <div class="u-sh-inner">
            <div class="u-sh-title">Username History</div>
            <div class="uh-body">
                <p>
                    <?php
                    $uname = htmlspecialchars($user['username']);
                    $count = $username_change_count;
                    if($count == 0)      echo "<strong>$uname</strong> has never changed their username.";
                    elseif($count == 1)  echo "<strong>$uname</strong> has changed their username <strong>1 time</strong>.";
                    else                 echo "<strong>$uname</strong> has changed their username <strong>{$count} times</strong>.";
                    ?>
                </p>
            </div>
        </div>
        <button type="button" class="u-sh-close" onclick="closeUsernameModal()">Close</button>
    </div>
</div>

<script>
// ── keep the theme in sync if it is switched in another tab ──
window.addEventListener('storage', function(e){
    if(e.key !== 'dz_theme') return;
    if(e.newValue === 'light') document.documentElement.setAttribute('data-theme','light');
    else document.documentElement.removeAttribute('data-theme');
});

// ── X: go back, or to the profile if there is no history ──
function acClose(){
    if (document.referrer && document.referrer.indexOf(window.location.host) !== -1) history.back();
    else location.href = 'profile.php?user_id=<?php echo (int)$profile_id; ?>';
}

// ── Skeleton ──────────────────────────────────────────────
(function(){
    var sk = document.getElementById('page-skeleton');
    if(!sk) return;
    var done = false;
    function hide(){
        if(done) return; done = true;
        sk.classList.add('hide');
        setTimeout(function(){ if(sk.parentNode) sk.remove(); }, 380);
    }
    window.addEventListener('load', function(){ setTimeout(hide, 280); });
    setTimeout(hide, 1500);   // safety net
})();

// ── Copy Profile ID ───────────────────────────────────────
var _copyToastTimer = null;
function copyProfileId(){
    var pid   = 'DZ-' + document.querySelector('#dz-pid .pid-digits').textContent.trim();
    var btn   = document.getElementById('copy-pid-btn');
    var toast = document.getElementById('copy-toast');

    if(navigator.clipboard && navigator.clipboard.writeText){
        navigator.clipboard.writeText(pid).then(function(){ showCopied(btn, toast); });
    } else {
        var ta = document.createElement('textarea');
        ta.value = pid;
        ta.style.cssText = 'position:fixed;top:-9999px;left:-9999px;opacity:0;';
        document.body.appendChild(ta);
        ta.focus(); ta.select();
        try { document.execCommand('copy'); } catch(e){}
        document.body.removeChild(ta);
        showCopied(btn, toast);
    }
}
function showCopied(btn, toast){
    btn.classList.add('copied');
    btn.innerHTML = '<i class="ri-check-line"></i> Copied';
    toast.classList.add('show');
    clearTimeout(_copyToastTimer);
    _copyToastTimer = setTimeout(function(){
        btn.classList.remove('copied');
        btn.innerHTML = '<i class="ri-file-copy-line"></i> Copy';
        toast.classList.remove('show');
    }, 2200);
}

// ── User modals ───────────────────────────────────────────
function openUserModal(type){
    var id = type === 'shared' ? 'sharedModal' : 'mutualsModal';
    document.getElementById(id).classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeUserModal(id){
    var el = document.getElementById(id);
    el.classList.remove('open');
    document.body.style.overflow = '';
    var inp = el.querySelector('.u-modal-search');
    if(inp){ inp.value = ''; filterModal(inp, id); }
}
function closeOnBackdrop(e, id){
    if(e.target === document.getElementById(id)) closeUserModal(id);
}

// ── Search filter ─────────────────────────────────────────
function filterModal(input, modalId){
    var q     = input.value.trim().toLowerCase();
    var modal = document.getElementById(modalId);
    var rows  = modal.querySelectorAll('.u-modal-row');
    var vis   = 0;
    rows.forEach(function(r){
        var match = r.dataset.name.indexOf(q) > -1;
        r.classList.toggle('hidden', !match);
        if(match) vis++;
    });
    var empty = document.getElementById(modalId + '-empty');
    if(empty) empty.classList.toggle('show', q.length > 0 && vis === 0 && rows.length > 0);
}

// ── Username modal ────────────────────────────────────────
function openUsernameModal(){
    document.getElementById('usernameModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeUsernameModal(){
    document.getElementById('usernameModal').classList.remove('open');
    document.body.style.overflow = '';
}

document.addEventListener('keydown', function(e){
    if(e.key === 'Escape'){
        closeUsernameModal();
        closeUserModal('sharedModal');
        closeUserModal('mutualsModal');
    }
});
</script>
</body>
</html>