<?php
session_start();
include 'config.php';
/* verified flower badge (dz_badge_sprite() + dz_badge('f')) — same file the other pages use */
require_once __DIR__ . '/verified_badge.php';

$current_user = (int)($_SESSION['user_id'] ?? 0);
if(!$current_user){ header("Location: login.php"); exit(); }

$profile_id = (isset($_GET['user_id']) && (int)$_GET['user_id'] > 0)
    ? (int)$_GET['user_id']
    : $current_user;
$is_own_profile = ($profile_id === $current_user);

// ── User info ──────────────────────────────────────────────
$stmt = $conn->prepare("SELECT username, profile_pic, is_verified FROM users WHERE id=? LIMIT 1");
$stmt->bind_param("i", $profile_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
if(!$user){ header("Location: 404.php"); exit(); }
$username_display = $is_own_profile ? "Your" : htmlspecialchars($user['username'])."'s";

// ── Add link ───────────────────────────────────────────────
if(isset($_POST['add_link']) && $is_own_profile){
    $title = trim($_POST['title'] ?? '');
    $url   = trim($_POST['url'] ?? '');
    $icon  = $_POST['icon'] ?? 'ri-link-line';
    $allowed_icons = ['ri-link-line','ri-instagram-line','ri-twitter-line','ri-github-line','ri-linkedin-line','ri-facebook-line','ri-youtube-line','ri-tiktok-line','ri-mail-line','ri-global-line'];
    if(!in_array($icon, $allowed_icons, true)) $icon = 'ri-link-line';
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    if($title && $url && in_array($scheme, ['http','https'], true)){
        $stmt = $conn->prepare("INSERT INTO links (user_id, title, url, icon) VALUES (?,?,?,?)");
        $stmt->bind_param("isss", $current_user, $title, $url, $icon);
        $stmt->execute();
        header("Location: links.php?user_id=".$current_user);
        exit();
    }
}

// ── Delete link ────────────────────────────────────────────
if(isset($_GET['delete']) && is_numeric($_GET['delete']) && $is_own_profile){
    $link_id = (int)$_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM links WHERE id=? AND user_id=?");
    $stmt->bind_param("ii", $link_id, $current_user);
    $stmt->execute();
    header("Location: links.php?user_id=".$current_user);
    exit();
}

// ── Links ──────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT * FROM links WHERE user_id=? ORDER BY created_at DESC");
$stmt->bind_param("i", $profile_id);
$stmt->execute();
$links_result = $stmt->get_result();
$links_arr = [];
while($l = $links_result->fetch_assoc()) $links_arr[] = $l;
$link_count = count($links_arr);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($user['username']); ?> | Links</title>
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
    --solid:    #070924;
    --red:      #FF2D2D;
    --ring:     #FF2D2D;       /* avatar border colour AND verified badge colour */
    --red-text: #FF2D2D;       /* red used for link names / urls (a little darker in light mode) */
    --bg:       linear-gradient(-45deg,#010218,#070924,#070924);
    --surface:  rgba(var(--fg),0.05);
    --line:     rgba(var(--fg),0.09);
    --line2:    rgba(var(--fg),0.14);
    --text:     #ffffff;
    --muted:    rgba(var(--fg),0.45);
    --sub:      rgba(var(--fg),0.62);

    --max-w:    580px;
    --topbar-h: 54px;
    --pad:      clamp(9px, 3vw, 16px);
    --ctl-h:    clamp(30px, 8vw, 36px);
    --r:        5px;

    /* same size / corners as the action buttons on the profile page */
    --act-h:    clamp(25px, 7.2vw, 30px);
    --act-r:    4px;
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
   TOPBAR — X on the left, "Links" on the right.
   The thin grey line lives ON the topbar, so it never disappears.
══════════════════════════════════════ */
.ac-topbar{
    position:sticky; top:0; z-index:500;
    height:var(--topbar-h);
    display:flex; align-items:center; gap:2px;
    padding:0 10px;
    background:var(--bg);
    background-attachment:fixed;
    border-bottom:1px solid var(--line);
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

/* ── Profile header: text left, picture on the right ── */
.profile-header{
    display:flex; flex-direction:row; align-items:flex-end; justify-content:flex-start;
    gap:clamp(12px, 4vw, 18px);
    padding:clamp(14px, 4vw, 22px) var(--pad) 0;
}
.profile-text{ flex:1; min-width:0; display:flex; flex-direction:column; align-items:flex-start; text-align:left; justify-content:flex-end; }
/* name + badge on one line; a long name is cut with "…" but the badge never is */
.profile-name{
    font-size:clamp(.95rem, 3.8vw, 1.2rem); font-weight:700;
    max-width:100%; margin-bottom:2px;
    display:flex; align-items:center; gap:.3em; min-width:0;
}
.profile-name .pn-nm{ min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
/* verified badge: filled, 1em = follows the name size at every breakpoint,
   colour = same variable as the avatar border, dark AND light mode */
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
.avatar{
    width:clamp(64px, 19vw, 90px); height:clamp(64px, 19vw, 90px);
    border-radius:50%; object-fit:cover; display:block; flex-shrink:0;
    border:2.6px solid var(--ring);
    order:-1;
    box-shadow:0 4px 18px rgba(255,45,45,0.25);
    transition:transform .3s, box-shadow .3s;
}
.avatar:hover{ transform:scale(1.05); box-shadow:0 0 22px rgba(255,45,45,0.5); }

/* ── Links note ── */
.links-note{
    padding:clamp(8px, 2.4vw, 10px) var(--pad) clamp(12px, 3.4vw, 16px);
    font-size:clamp(.6rem, 1.9vw, .7rem); color:var(--muted);
    line-height:1.65;
}

.links-note a.learn-more,
.links-note a.learn-more:visited{
    color:var(--red-text); font-weight:600; text-decoration:none; white-space:nowrap;
    transition:color .18s;
}
.links-note a.learn-more:hover{ color:#ff5a5a; }

/* ── Section label ── */
.section-label{
    padding:clamp(14px, 4vw, 20px) var(--pad) 6px;
    font-size:.6rem; font-weight:700; text-transform:uppercase; letter-spacing:1.5px; color:var(--muted);
}

/* ── Link rows ── */
.info-list{ display:flex; flex-direction:column; flex:1; }
.divider{ width:100%; border:none; border-top:1px solid var(--line); margin:0; }

.link-item{
    display:flex; align-items:center;
    opacity:0; animation:rowIn .4s ease forwards;
}
@keyframes rowIn{
    from{ opacity:0; transform:translateY(8px); }
    to{ opacity:1; transform:translateY(0); }
}
.info-row{
    flex:1; min-width:0;
    display:flex; align-items:center; gap:clamp(10px, 3vw, 14px);
    padding:clamp(11px, 3.2vw, 16px) var(--pad);
    transition:background .18s;
    text-decoration:none; color:var(--text);
}
.link-item:hover{ background:rgba(var(--fg),0.04); }
.link-item:hover .info-icon{ background:rgba(255,45,45,0.12); }

.info-icon{
    width:clamp(27px, 7.6vw, 36px); height:clamp(27px, 7.6vw, 36px);
    border-radius:6px; background:var(--surface);
    display:flex; align-items:center; justify-content:center;
    flex-shrink:0; color:var(--red); font-size:clamp(.78rem, 2.6vw, 1rem);
    transition:background .2s;
}
.info-content{ flex:1; min-width:0; }
.info-label{
    font-size:clamp(.54rem, 1.8vw, .66rem); font-weight:400;
    line-height:1.2; text-transform:uppercase; letter-spacing:.05em;
    color:var(--red-text);
}
.info-value{
    font-size:clamp(.72rem, 2.4vw, .86rem); font-weight:500;
    line-height:1.4; margin-top:2px;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    color:var(--red-text);
}
.chevron{ color:var(--muted); font-size:1rem; flex-shrink:0; }
.link-item .chevron{ margin-right:var(--pad); }

.del-form{ flex-shrink:0; margin-right:calc(var(--pad) - 4px); }
.delete-btn{
    background:none; border:none;
    color:rgba(255,45,45,0.45); font-size:1rem;
    padding:7px; flex-shrink:0;
    display:flex; align-items:center;
    transition:color .2s, background .2s;
    border-radius:var(--r);
}
.delete-btn:hover{ color:var(--red); background:rgba(255,45,45,0.1); }

/* ══════════════════════════════════════
   EMPTY STATE — same premium design as the Untracked page
══════════════════════════════════════ */
.empty-state{
    flex:1; min-height:44vh;
    padding:clamp(28px, 8vw, 48px) clamp(20px, 7vw, 36px) clamp(36px, 10vw, 60px);
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    text-align:center;
    animation:rowIn .4s ease both;
}
.es-title{ font-size:clamp(.95rem, 3.4vw, 1.1rem); font-weight:700; color:var(--ui-text); letter-spacing:-.2px; }
.es-text{
    margin-top:6px; max-width:340px;
    font-size:clamp(.66rem, 2.1vw, .76rem); color:var(--muted); line-height:1.7;
}
.es-tips{
    width:100%; max-width:360px;
    margin-top:clamp(16px, 5vw, 24px);
    display:flex; flex-direction:column; gap:7px;
}
.es-tip{
    display:flex; align-items:center; gap:10px;
    padding:10px 12px; border-radius:var(--act-r);
    background:rgba(var(--fg),0.05);
    text-align:left;
}
.es-tip i{ font-size:1rem; color:var(--red); flex-shrink:0; }
.es-tip span{ font-size:clamp(.62rem, 2vw, .7rem); color:var(--sub); line-height:1.5; }
.es-btn{
    margin-top:clamp(16px, 5vw, 22px);
    display:flex; align-items:center; justify-content:center;
    height:var(--act-h); min-width:150px; padding:0 22px;
    border:none; border-radius:var(--act-r);
    background:rgba(var(--fg),0.1);
    backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    color:var(--ui-text); font-size:.72rem; font-weight:600; text-decoration:none;
    transition:background .2s ease, transform .15s ease;
}
.es-btn:hover{ background:rgba(var(--fg),0.2); }
.es-btn:active{ transform:scale(0.98); }
.es-btn.red{ background:var(--red); color:#000; font-weight:700; }
.es-btn.red:hover{ background:#ff1a1a; }
@media (max-width:490px){ .es-btn{ font-size:.62rem; } }

/* ── Add form ── */
.add-section{ padding:0 var(--pad) 6px; }
.add-section-label{
    font-size:.6rem; color:var(--muted); font-weight:700;
    letter-spacing:1.5px; text-transform:uppercase;
    padding:clamp(14px, 4vw, 20px) 0 10px;
}
.add-form{ display:flex; flex-direction:column; gap:10px; }
.add-form input,
.add-form select{
    width:100%; height:var(--ctl-h); padding:0 12px;
    border-radius:var(--r);
    border:1px solid var(--line2);
    background:var(--surface);
    color:var(--text); font-size:.76rem; outline:none;
    transition:border-color .2s, background .2s, box-shadow .2s;
    -webkit-appearance:none; appearance:none;
}
.add-form input::placeholder{ color:var(--muted); }
.add-form input:focus,
.add-form select:focus{
    border-color:rgba(255,45,45,.7); background:rgba(var(--fg),.07);
    box-shadow:0 0 0 3px rgba(255,45,45,.12);
}
.add-form select option{ background:var(--solid); color:var(--ui-text); }
.add-form button{
    width:100%; height:var(--ctl-h);
    border-radius:var(--r); background:var(--red);
    border:none; color:#000;
    font-size:.78rem; font-weight:700;
    display:flex; align-items:center; justify-content:center; gap:6px;
    transition:background .2s, transform .15s;
}
.add-form button:hover{ background:#ff4747; }
.add-form button:active{ transform:scale(.98); }
.add-form button i{ font-size:.95rem; }
@media (max-width:490px){
    .add-form{ gap:8px; }
    .add-form input, .add-form select{ font-size:.7rem; padding:0 10px; }
    .add-form button{ font-size:.72rem; }
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
.sk-topbar{ height:var(--topbar-h); display:flex; align-items:center; justify-content:space-between; padding:0 16px 0 14px; border-bottom:1px solid var(--line); }
.sk-back{ width:20px; height:20px; }
.sk-title{ width:70px; height:14px; }
.sk-head{ display:flex; align-items:center; justify-content:flex-start; gap:16px; padding:clamp(14px,4vw,22px) var(--pad) 0; align-items:flex-end; }
.sk-head-text{ display:flex; flex-direction:column; gap:9px; }
.sk-name{ width:130px; height:15px; }
.sk-sub{ width:90px; height:10px; }
.sk-avatar-circle{ order:-1; width:clamp(64px,19vw,90px); height:clamp(64px,19vw,90px); border-radius:50%; flex-shrink:0; }
.sk-row-item{ display:flex; align-items:center; gap:14px; padding:clamp(11px,3.2vw,16px) var(--pad); border-bottom:1px solid var(--line); }
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
        --act-h:clamp(27.5px, 7.92vw, 33px);
        --act-r:4.4px;
    }

    /* topbar */
    .ac-topbar{ padding:0 11px; }
    .ac-x{ width:37px; height:37px; }
    .ac-x svg{ width:24px; height:24px; }
    .ac-title{ font-size:1.01rem; margin:0 7px 0 11px; }

    /* profile header */
    .profile-header{ gap:clamp(13px, 4.4vw, 20px); padding:clamp(15px, 4.4vw, 24px) var(--pad) 0; }
    .profile-name{ font-size:clamp(1.045rem, 4.18vw, 1.32rem); margin-bottom:2.2px; gap:.3em; }
    .profile-name .vbadge{ width:1em; height:1em; }
    .profile-label{ font-size:clamp(.64rem, 2.09vw, .75rem); }
    .avatar{ width:clamp(70px, 20.9vw, 99px); height:clamp(70px, 20.9vw, 99px); border-width:2.9px; }

    /* links note */
    .links-note{ padding:clamp(9px, 2.64vw, 11px) var(--pad) clamp(13px, 3.74vw, 17.6px); font-size:clamp(.66rem, 2.09vw, .77rem); }

    /* section label */
    .section-label{ padding:clamp(15px, 4.4vw, 22px) var(--pad) 6.6px; font-size:.66rem; letter-spacing:1.65px; }

    /* link rows */
    .info-row{ gap:clamp(11px, 3.3vw, 15px); padding:clamp(12px, 3.52vw, 17.6px) var(--pad); }
    .info-icon{ width:clamp(30px, 8.36vw, 39.6px); height:clamp(30px, 8.36vw, 39.6px); border-radius:6.6px; font-size:clamp(.86rem, 2.86vw, 1.1rem); }
    .info-label{ font-size:clamp(.59rem, 1.98vw, .73rem); }
    .info-value{ font-size:clamp(.79rem, 2.64vw, .95rem); margin-top:2.2px; }
    .chevron{ font-size:1.1rem; }
    .delete-btn{ font-size:1.1rem; padding:7.7px; }

    /* empty state */
    .empty-state{ padding:clamp(31px, 8.8vw, 53px) clamp(22px, 7.7vw, 40px) clamp(40px, 11vw, 66px); }
    .es-title{ font-size:clamp(1.045rem, 3.74vw, 1.21rem); }
    .es-text{ margin-top:6.6px; max-width:374px; font-size:clamp(.73rem, 2.31vw, .84rem); }
    .es-tips{ max-width:396px; margin-top:clamp(18px, 5.5vw, 26px); gap:7.7px; }
    .es-tip{ gap:11px; padding:11px 13px; }
    .es-tip i{ font-size:1.1rem; }
    .es-tip span{ font-size:clamp(.68rem, 2.2vw, .77rem); }
    .es-btn{ margin-top:clamp(18px, 5.5vw, 24px); min-width:165px; padding:0 24px; font-size:.79rem; }

    /* add form */
    .add-section{ padding:0 var(--pad) 6.6px; }
    .add-section-label{ font-size:.66rem; letter-spacing:1.65px; padding:clamp(15px, 4.4vw, 22px) 0 11px; }
    .add-form{ gap:11px; }
    .add-form input, .add-form select{ padding:0 13px; font-size:.84rem; }
    .add-form button{ font-size:.86rem; gap:6.6px; }
    .add-form button i{ font-size:1.05rem; }

    /* skeleton */
    .sk-topbar{ padding:0 17.6px 0 15px; }
    .sk-back{ width:22px; height:22px; }
    .sk-title{ width:77px; height:15.4px; }
    .sk-head{ gap:17.6px; padding:clamp(15px, 4.4vw, 24px) var(--pad) 0; }
    .sk-head-text{ gap:10px; }
    .sk-name{ width:143px; height:16.5px; }
    .sk-sub{ width:99px; height:11px; }
    .sk-avatar-circle{ width:clamp(70px, 20.9vw, 99px); height:clamp(70px, 20.9vw, 99px); }
    .sk-row-item{ gap:15px; padding:clamp(12px, 3.52vw, 17.6px) var(--pad); }
    .sk-icon{ width:clamp(30px, 8.36vw, 39.6px); height:clamp(30px, 8.36vw, 39.6px); border-radius:6.6px; }
    .sk-text{ gap:6.6px; }
    .sk-lbl{ width:88px; height:10px; }
    .sk-val{ width:154px; height:13px; }
}

@media (max-width:490px){
    :root{ --topbar-h:53px; }
    .ac-x{ width:33px; height:33px; }
    .ac-title{ font-size:.92rem; }
    .profile-name .vbadge{ width:1em; height:1em; }
    .es-btn{ font-size:.68rem; }
    .add-form{ gap:8.8px; }
    .add-form input, .add-form select{ font-size:.77rem; padding:0 11px; }
    .add-form button{ font-size:.79rem; }
}

@media (max-width:380px){
    :root{ --topbar-h:48px; }
    .ac-title{ font-size:.88rem; }
    .profile-name .vbadge{ width:1em; height:1em; }
}

/* ═══════════ LIGHT MODE — <html data-theme="light"> (dark mode is never touched) ═══════════
   Same palette as the other Dezzme pages. The red accent stays the same in both themes. */
:root[data-theme="light"]{
    color-scheme:light;
    --fg:6,7,26;
    --ui-text:#0b0d24;
    --text:#0b0d24;
    --solid:#ffffff;
    --bg:linear-gradient(-45deg,#ffffff,#f4f5fb,#f4f5fb);
    --surface:rgba(6,7,26,.05);
    --line:rgba(6,7,26,.1);
    --line2:rgba(6,7,26,.2);
    --muted:rgba(6,7,26,.52);
    --sub:rgba(6,7,26,.66);
    --red-text:#d92b2b;
}
/* badge keeps the avatar-border colour in light mode too (the name text turns dark, the badge does not) */
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
        <div class="sk-head">
            <div class="sk-head-text">
                <div class="sk sk-name"></div>
                <div class="sk sk-sub"></div>
            </div>
            <div class="sk sk-avatar-circle"></div>
        </div>
        <?php for($i=0;$i<4;$i++): ?>
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
        <span class="ac-title">Links</span>
    </div>

    <!-- ══ PROFILE HEADER ══ -->
    <div class="profile-header">
        <div class="profile-text">
            <div class="profile-name"><span class="pn-nm"><?php echo htmlspecialchars($user['username']); ?></span><?php echo !empty($user['is_verified']) ? dz_badge('f') : ''; ?></div>
            <div class="profile-label">External Links</div>
        </div>
        <img class="avatar"
             src="<?php echo htmlspecialchars($user['profile_pic'] ?: 'uploads/default.jpg'); ?>"
             onerror="this.src='uploads/default.jpg'"
             alt="Profile picture">
    </div>

    <!-- ══ ABOUT LINKS ══ -->
    <p class="links-note">
        <?php echo $is_own_profile
            ? "Links are how you share your world beyond Dezzme. Add your socials, portfolio, online store or website so Trackers can find you elsewhere, and so you can turn the attention you get here into followers, customers or opportunities on other platforms. Your links appear right under your bio, and you can remove any of them at any time. Only add links you trust and want people to visit, and avoid anything misleading or unsafe."
            : "Links are how " . htmlspecialchars($user['username']) . " shares their world beyond Dezzme, like socials, a portfolio, an online store or a website. They are added by " . htmlspecialchars($user['username']) . " and open outside Dezzme in a new tab, so Dezzme doesn't control what is on the other side. Only visit links you trust, and never share your password or personal details on a site you're unsure about."; ?>
        <a class="learn-more" href="help.php">Learn more</a>
    </p>

    <!-- ══ ADD LINK ══ -->
    <?php if($is_own_profile): ?>
    <div class="add-section" id="addSection">
        <div class="add-section-label">Add a Link</div>
        <form class="add-form" method="POST">
            <input type="text" name="title" id="linkTitleInput" placeholder="Label — e.g. Instagram, Portfolio" maxlength="60" required>
            <input type="url"  name="url"   placeholder="https://example.com" required>
            <select name="icon">
                <option value="ri-link-line">Generic Link</option>
                <option value="ri-instagram-line">Instagram</option>
                <option value="ri-twitter-line">Twitter / X</option>
                <option value="ri-github-line">GitHub</option>
                <option value="ri-linkedin-line">LinkedIn</option>
                <option value="ri-facebook-line">Facebook</option>
                <option value="ri-youtube-line">YouTube</option>
                <option value="ri-tiktok-line">TikTok</option>
                <option value="ri-mail-line">Email</option>
                <option value="ri-global-line">Website</option>
            </select>
            <button type="submit" name="add_link">
                <i class="ri-add-line"></i> Add Link
            </button>
        </form>
    </div>
    <?php endif; ?>

    <!-- ══ LINKS ══ -->
    <div class="section-label">
        <?php echo $username_display; ?> Links<?php echo $link_count ? ' — ' . $link_count . ' added' : ''; ?>
    </div>

    <div class="info-list">
        <hr class="divider">

        <?php if($link_count > 0): ?>
            <?php foreach($links_arr as $idx => $link): ?>
            <div class="link-item" style="animation-delay:<?php echo $idx * 0.05; ?>s">
                <a class="info-row" href="<?php echo htmlspecialchars($link['url']); ?>" target="_blank" rel="noopener noreferrer">
                    <div class="info-icon">
                        <i class="<?php echo htmlspecialchars($link['icon']); ?>"></i>
                    </div>
                    <div class="info-content">
                        <div class="info-label"><?php echo htmlspecialchars($link['title']); ?></div>
                        <div class="info-value"><?php echo htmlspecialchars($link['url']); ?></div>
                    </div>
                    <?php if(!$is_own_profile): ?>
                    <i class="ri-arrow-right-s-line chevron"></i>
                    <?php endif; ?>
                </a>
                <?php if($is_own_profile): ?>
                <form class="del-form" method="GET" onsubmit="return confirm('Delete this link?');">
                    <input type="hidden" name="user_id" value="<?php echo (int)$profile_id; ?>">
                    <input type="hidden" name="delete" value="<?php echo (int)$link['id']; ?>">
                    <button type="submit" class="delete-btn" title="Delete link" aria-label="Delete link">
                        <i class="ri-delete-bin-line"></i>
                    </button>
                </form>
                <?php endif; ?>
            </div>
            <hr class="divider">
            <?php endforeach; ?>

        <?php elseif($is_own_profile): ?>
            <!-- ══ EMPTY — own profile ══ -->
            <div class="empty-state">
                <div class="es-title">You haven't added any links</div>
                <p class="es-text">Point your Trackers to your socials, portfolio or website. Your links show up right under your bio on your profile.</p>
                <div class="es-tips">
                    <div class="es-tip"><i class="ri-share-forward-line"></i><span>Add Instagram, YouTube, GitHub, your email and more.</span></div>
                    <div class="es-tip"><i class="ri-eye-line"></i><span>Your links appear on your profile for anyone who can view it.</span></div>
                    <div class="es-tip"><i class="ri-shield-check-line"></i><span>Only add links you trust and want people to visit.</span></div>
                </div>
                <button type="button" class="es-btn red" onclick="acFocusAdd()">Add your first link</button>
            </div>

        <?php else: ?>
            <!-- ══ EMPTY — someone else's profile ══ -->
            <div class="empty-state">
                <div class="es-title">No links yet</div>
                <p class="es-text"><?php echo htmlspecialchars($user['username']); ?> hasn't added any links. If they do, their socials, portfolio or website will show up here.</p>
                <div class="es-tips">
                    <div class="es-tip"><i class="ri-link-m"></i><span>Links open outside Dezzme in a new tab.</span></div>
                    <div class="es-tip"><i class="ri-shield-check-line"></i><span>Only visit links you trust.</span></div>
                </div>
                <a class="es-btn" href="profile.php?user_id=<?php echo (int)$profile_id; ?>">Back to profile</a>
            </div>
        <?php endif; ?>
    </div>

</div><!-- /.ac-lane -->

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

// ── Empty-state button: jump to the add form and focus it ──
function acFocusAdd(){
    var s = document.getElementById('addSection');
    var i = document.getElementById('linkTitleInput');
    if (s) s.scrollIntoView({ behavior:'smooth', block:'center' });
    if (i) setTimeout(function(){ i.focus({ preventScroll:true }); }, 350);
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
</script>
</body>
</html>