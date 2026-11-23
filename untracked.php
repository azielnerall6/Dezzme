<?php
session_start();
include 'config.php';
/* verified flower badge (dz_badge_sprite() + dz_badge('f')) — same file the other pages use */
require_once __DIR__ . '/verified_badge.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
if(!$user_id){
    header("Location: login.php");
    exit();
}

$conn->query("
    CREATE TABLE IF NOT EXISTS unfollow_log (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        unfollowed_by INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        unfollowed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX(user_id),
        INDEX(unfollowed_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── Remembers when you last opened this page.
//    profile.php shows a red dot beside "Untracked" when someone untracked / blocked you
//    AFTER this time. Opening this page clears the dot. ──
$conn->query("
    CREATE TABLE IF NOT EXISTS untracked_seen (
        user_id INT UNSIGNED NOT NULL PRIMARY KEY,
        seen_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── Blocked me: one row per user, most recent block only ───
$stmt = $conn->prepare("
    SELECT u.id, u.username, u.profile_pic, u.is_verified, MAX(b.created_at) AS blocked_at
    FROM blocks b
    JOIN users u ON u.id = b.blocker_id
    WHERE b.blocked_id = ?
    GROUP BY u.id, u.username, u.profile_pic, u.is_verified
    ORDER BY blocked_at DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$blocked_me = $stmt->get_result();

// ── Untracked me: one row per user, most recent untrack only ──
$stmt = $conn->prepare("
    SELECT u.id, u.username, u.profile_pic, u.is_verified, MAX(ul.unfollowed_at) AS unfollowed_at
    FROM unfollow_log ul
    JOIN users u ON u.id = ul.unfollowed_by
    WHERE ul.user_id = ?
      AND NOT EXISTS (
          SELECT 1 FROM followers f
          WHERE f.follower_id = ul.unfollowed_by
            AND f.following_id = ?
      )
    GROUP BY u.id, u.username, u.profile_pic, u.is_verified
    ORDER BY unfollowed_at DESC
");
$stmt->bind_param("ii", $user_id, $user_id);
$stmt->execute();
$unfollowed_me = $stmt->get_result();

$uf_count = $unfollowed_me ? $unfollowed_me->num_rows : 0;
$bl_count = $blocked_me ? $blocked_me->num_rows : 0;

// ── Mark as seen (clears the red dot on the profile page) ──
$seen_stmt = $conn->prepare("
    INSERT INTO untracked_seen (user_id, seen_at) VALUES (?, NOW())
    ON DUPLICATE KEY UPDATE seen_at = NOW()
");
if ($seen_stmt) {
    $seen_stmt->bind_param("i", $user_id);
    $seen_stmt->execute();
    $seen_stmt->close();
}

function timeAgo($datetime){
    $now  = new DateTime();
    $ago  = new DateTime($datetime);
    $diff = $now->diff($ago);
    if($diff->y > 0) return $diff->y.' year'.($diff->y>1?'s':'').' ago';
    if($diff->m > 0) return $diff->m.' month'.($diff->m>1?'s':'').' ago';
    if($diff->d > 0) return $diff->d.' day'.($diff->d>1?'s':'').' ago';
    if($diff->h > 0) return $diff->h.' hour'.($diff->h>1?'s':'').' ago';
    if($diff->i > 0) return $diff->i.' min ago';
    return 'Just now';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<!-- PWA -->
<link rel="manifest" href="/Peura/manifest.json">
<meta name="theme-color" content="#FF2D2D">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Peura">
<link rel="apple-touch-icon" href="/Peura/Peura.jpg">
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/Peura/service-worker.js')
            .then(reg => console.log('Peura SW registered'))
            .catch(err => console.log('SW error:', err));
    });
}
</script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Untracked | Dezzme</title>

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
    --red:      #FF2D2D;
    --bg:       linear-gradient(-45deg,#070924);
    --surface:  rgba(255,255,255,0.05);
    --line:     rgba(255,255,255,0.09);
    --line2:    rgba(255,255,255,0.14);
    --text:     #ffffff;
    --muted:    rgba(255,255,255,0.45);
    --sub:      rgba(255,255,255,0.62);

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
   TOPBAR — X on the left, "Untracked" on the right (no icon)
══════════════════════════════════════ */
.ac-topbar{
    position:sticky; top:0; z-index:500;
    height:var(--topbar-h);
    display:flex; align-items:center; gap:2px;
    padding:0 10px;
    background:var(--bg);
    background-attachment:fixed;
    border-bottom:1px solid var(--line);          /* thin grey line under the topbar — all screen sizes */
}
.ac-x{
    width:34px; height:34px;
    background:none; border:none; color:#fff;
    display:flex; align-items:center; justify-content:center;
    opacity:.9; transition:opacity .2s, color .2s;
    padding:0; flex-shrink:0;
}
.ac-x:hover{ opacity:1; color:var(--red); }
.ac-x svg{ width:22px; height:22px; display:block; }
.ac-title{
    flex:1; min-width:0;
    font-size:.92rem; font-weight:700; color:#fff; letter-spacing:-.1px;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    text-align:right; margin:0 6px 0 10px;
}
@media (max-width:490px){
    .ac-x{ width:30px; height:30px; }
    .ac-title{ font-size:.84rem; }
}
@media (max-width:380px){ .ac-title{ font-size:.8rem; } }

/* ── Tabs ── */
.tabs-wrap{
    display:flex;
    border-top:none; border-bottom:1px solid var(--line);
    position:sticky; top:var(--topbar-h); z-index:400;
    background:var(--bg); background-attachment:fixed;
}
.tab-btn{
    flex:1; padding:clamp(9px, 2.8vw, 13px) 6px;
    background:none; border:none;
    border-bottom:2px solid transparent;
    color:var(--muted);
    display:flex; flex-direction:column; align-items:center; gap:3px;
    margin-bottom:-1px;
    transition:color .2s, border-color .2s;
}
.tab-count{ font-size:clamp(.8rem, 3vw, 1.05rem); font-weight:700; color:var(--text); line-height:1; }
.tab-label{
    font-size:clamp(.5rem, 1.8vw, .62rem); font-weight:600;
    text-transform:uppercase; letter-spacing:.05em;
    display:flex; align-items:center; gap:5px;
}
.tab-btn.active{ color:var(--text); border-bottom-color:#fff; }

/* ── Search ── */
.search-wrap{
    padding:clamp(9px, 2.6vw, 12px) var(--pad);
    border-bottom:1px solid var(--line);
}
.search-inner{ position:relative; width:100%; }
.search-icon{
    position:absolute; left:12px; top:50%; transform:translateY(-50%);
    color:var(--muted); font-size:.95rem; pointer-events:none;
}
.search-input{
    width:100%; height:var(--ctl-h);
    background:var(--surface); border:1px solid var(--line2); border-radius:var(--r);
    padding:0 12px 0 34px; font-size:.76rem; color:#fff; outline:none;
    transition:border-color .2s, background .2s, box-shadow .2s;
    -webkit-appearance:none; appearance:none;
}
.search-input::placeholder{ color:var(--muted); }
.search-input:focus{
    border-color:rgba(255,45,45,.7); background:rgba(255,255,255,.07);
    box-shadow:0 0 0 3px rgba(255,45,45,.12);
}
@media (max-width:490px){
    .search-input{ font-size:.7rem; padding-left:32px; }
}

/* ── Context note ── */
.context-note{
    font-size:clamp(.6rem, 1.9vw, .7rem); color:var(--muted); line-height:1.65;
    padding:clamp(11px, 3.4vw, 16px) var(--pad);
    border-bottom:1px solid var(--line);
    display:flex; align-items:flex-start; gap:8px;
}
.context-note i{ color:var(--red); font-size:.9rem; flex-shrink:0; margin-top:1px; }

/* ── User list ── */
.user-list{ display:none; flex-direction:column; }
.user-list.active{ display:flex; flex:1; }

.user-row{
    display:flex; align-items:center; gap:clamp(10px, 3vw, 14px);
    padding:clamp(11px, 3.2vw, 14px) var(--pad);
    border-bottom:1px solid var(--line);
    transition:background .18s;
    color:var(--text);
}
.user-row:hover{ background:rgba(255,255,255,0.04); }
.user-row.hidden{ display:none !important; }

.user-avatar{
    width:clamp(36px, 10vw, 46px); height:clamp(36px, 10vw, 46px);
    border-radius:50%; object-fit:cover; flex-shrink:0;
    border:2px solid var(--red); background:#1a1a1a;
    transition:transform .2s;
}
@media (max-width:490px){ .user-avatar{ border-width:1.5px; } }
.user-row:hover .user-avatar{ transform:scale(1.04); }

.user-info{ flex:1; min-width:0; }
/* name + verified badge on one line; a long name is cut with "…" but the badge never is */
.uname{
    font-size:clamp(.74rem, 2.4vw, .86rem); font-weight:600; color:var(--text);
    display:flex; align-items:center; gap:.3em; min-width:0;
}
.uname .un-nm{ min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
/* verified badge: filled, 1em = follows the name size, RED (same colour as the avatar border) */
.uname .vbadge{
    width:1em; height:1em; flex-shrink:0;
    vertical-align:baseline;
    color:var(--red) !important;
    fill:currentColor;
}
.umeta{
    font-size:clamp(.6rem, 1.9vw, .7rem); color:var(--muted); margin-top:2px;
    display:flex; align-items:center; gap:5px;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.umeta i{ font-size:.78rem; }
.uexact{ font-size:clamp(.54rem, 1.7vw, .62rem); color:rgba(255,255,255,0.28); margin-top:2px; }

/* ── Right side: grey button (red words) + grey "View Profile" button (white words).
      Same look and size as the action buttons on the profile page. ── */
.row-actions{
    flex-shrink:0;
    display:flex; flex-direction:column; gap:6px;
    width:clamp(88px, 25vw, 110px);
}
.status-badge, .view-btn{
    display:flex; align-items:center; justify-content:center;
    width:100%; height:var(--act-h); line-height:1;
    padding:3px 8px; border-radius:var(--act-r);
    font-size:0.72rem; font-weight:600;
    background:rgba(255,255,255,0.1);
    backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    border:none; white-space:nowrap; overflow:hidden;
    text-decoration:none;
    transition:background .2s ease, transform .15s ease;
}
.status-badge{ color:var(--red); font-weight:700; cursor:default; }
.view-btn{ color:#fff; }
.view-btn:hover{ background:rgba(255,255,255,0.2); }
.view-btn:active{ transform:scale(0.98); }
@media (max-width:490px){
    .status-badge, .view-btn{ font-size:0.62rem; padding:3px 6px; }
}
@media (max-width:360px){
    .status-badge, .view-btn{ font-size:0.58rem; }
}

/* ── Empty state ── */
.empty-state{
    flex:1; min-height:52vh;
    padding:clamp(28px, 8vw, 48px) clamp(20px, 7vw, 36px) clamp(36px, 10vw, 60px);
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    text-align:center;
}
.es-title{ font-size:clamp(.95rem, 3.4vw, 1.1rem); font-weight:700; color:#fff; letter-spacing:-.2px; }
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
    background:rgba(255,255,255,0.05);
    text-align:left;
}
.es-tip i{ font-size:1rem; color:var(--red); flex-shrink:0; }
.es-tip span{ font-size:clamp(.62rem, 2vw, .7rem); color:var(--sub); line-height:1.5; }
.es-btn{
    margin-top:clamp(16px, 5vw, 22px);
    display:flex; align-items:center; justify-content:center;
    height:var(--act-h); min-width:150px; padding:0 22px;
    border-radius:var(--act-r);
    background:rgba(255,255,255,0.1); color:#fff;
    font-size:.72rem; font-weight:600; text-decoration:none;
    transition:background .2s ease, transform .15s ease;
}
.es-btn:hover{ background:rgba(255,255,255,0.2); }
.es-btn:active{ transform:scale(0.98); }
@media (max-width:490px){ .es-btn{ font-size:.62rem; } }

.no-results{
    display:none; flex-direction:column; align-items:center; gap:8px;
    padding:36px 0 24px; text-align:center;
    color:var(--muted); font-size:.78rem;
}
.no-results i{ font-size:1.8rem; opacity:.35; }
.no-results.show{ display:flex; }

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
.sk{ position:relative; overflow:hidden; background:rgba(255,255,255,.06); border-radius:6px; }
.sk::after{
    content:''; position:absolute; inset:0;
    background:linear-gradient(90deg, transparent, rgba(255,255,255,.09), transparent);
    transform:translateX(-100%); animation:skShimmer 1.5s infinite;
}
@keyframes skShimmer{ 100%{ transform:translateX(100%); } }
.sk-topbar{ height:var(--topbar-h); display:flex; align-items:center; justify-content:space-between; padding:0 16px 0 14px; border-bottom:1px solid var(--line); }
.sk-back{ width:20px; height:20px; }
.sk-title{ width:90px; height:14px; }
.sk-tabs{ display:flex; border-bottom:1px solid var(--line); }
.sk-tab{ flex:1; height:50px; border-radius:0; }
.sk-tab + .sk-tab{ margin-left:1px; }
.sk-search{ height:var(--ctl-h); margin:clamp(9px,2.6vw,12px) var(--pad); border-radius:var(--r); }
.sk-row{ display:flex; align-items:center; gap:14px; padding:clamp(11px,3.2vw,14px) var(--pad); border-top:1px solid var(--line); }
.sk-avatar{ width:clamp(36px,10vw,46px); height:clamp(36px,10vw,46px); border-radius:50%; flex-shrink:0; }
.sk-info{ flex:1; display:flex; flex-direction:column; gap:7px; }
.sk-name{ width:110px; height:12px; }
.sk-sub{ width:70px; height:9px; }
.sk-btn{ width:clamp(88px, 25vw, 110px); height:calc(var(--act-h) * 2 + 6px); border-radius:var(--act-r); flex-shrink:0; }

/* ═══════════════════════════════════════════════════════════════
   RESPONSIVE ONLY — everything 10% bigger (desktop untouched)
   Kept at the very end so it wins over the rules above.
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

    /* tabs */
    .tab-btn{ padding:clamp(10px, 3.08vw, 14px) 6.6px; gap:3.3px; }
    .tab-count{ font-size:clamp(.88rem, 3.3vw, 1.155rem); }
    .tab-label{ font-size:clamp(.55rem, 1.98vw, .68rem); gap:5.5px; }

    /* search */
    .search-wrap{ padding:clamp(10px, 2.86vw, 13px) var(--pad); }
    .search-icon{ left:13px; font-size:1.045rem; }
    .search-input{ padding:0 13px 0 37px; font-size:.84rem; }

    /* context note */
    .context-note{ font-size:clamp(.66rem, 2.09vw, .77rem); padding:clamp(12px, 3.74vw, 17.6px) var(--pad); gap:8.8px; }
    .context-note i{ font-size:.99rem; }

    /* user rows */
    .user-row{ gap:clamp(11px, 3.3vw, 15px); padding:clamp(12px, 3.52vw, 15px) var(--pad); }
    .user-avatar{ width:clamp(40px, 11vw, 51px); height:clamp(40px, 11vw, 51px); border-width:2.2px; }
    .uname{ font-size:clamp(.81rem, 2.64vw, .95rem); }
    .uname .vbadge{ width:1em; height:1em; }
    .umeta{ font-size:clamp(.66rem, 2.09vw, .77rem); margin-top:2.2px; gap:5.5px; }
    .umeta i{ font-size:.86rem; }
    .uexact{ font-size:clamp(.59rem, 1.87vw, .68rem); margin-top:2.2px; }

    /* right-side buttons */
    .row-actions{ gap:6.6px; width:clamp(97px, 27.5vw, 121px); }
    .status-badge, .view-btn{ padding:3.3px 8.8px; font-size:.79rem; }

    /* empty state */
    .empty-state{ padding:clamp(31px, 8.8vw, 53px) clamp(22px, 7.7vw, 40px) clamp(40px, 11vw, 66px); }
    .es-title{ font-size:clamp(1.045rem, 3.74vw, 1.21rem); }
    .es-text{ margin-top:6.6px; max-width:374px; font-size:clamp(.73rem, 2.31vw, .84rem); }
    .es-tips{ max-width:396px; margin-top:clamp(18px, 5.5vw, 26px); gap:7.7px; }
    .es-tip{ gap:11px; padding:11px 13px; }
    .es-tip i{ font-size:1.1rem; }
    .es-tip span{ font-size:clamp(.68rem, 2.2vw, .77rem); }
    .es-btn{ margin-top:clamp(18px, 5.5vw, 24px); min-width:165px; padding:0 24px; font-size:.79rem; }
    .no-results{ gap:8.8px; padding:40px 0 26px; font-size:.86rem; }
    .no-results i{ font-size:1.98rem; }

    /* skeleton */
    .sk-topbar{ padding:0 17.6px 0 15px; }
    .sk-back{ width:22px; height:22px; }
    .sk-title{ width:99px; height:15.4px; }
    .sk-tab{ height:55px; }
    .sk-search{ margin:clamp(10px, 2.86vw, 13px) var(--pad); }
    .sk-row{ gap:15px; padding:clamp(12px, 3.52vw, 15px) var(--pad); }
    .sk-avatar{ width:clamp(40px, 11vw, 51px); height:clamp(40px, 11vw, 51px); }
    .sk-info{ gap:7.7px; }
    .sk-name{ width:121px; height:13px; }
    .sk-sub{ width:77px; height:10px; }
    .sk-btn{ width:clamp(97px, 27.5vw, 121px); }
}

@media (max-width:490px){
    :root{ --topbar-h:53px; }
    .ac-x{ width:33px; height:33px; }
    .ac-title{ font-size:.92rem; }
    .search-input{ font-size:.77rem; padding-left:35px; }
    .user-avatar{ border-width:1.65px; }
    .status-badge, .view-btn{ font-size:.68rem; padding:3.3px 6.6px; }
    .es-btn{ font-size:.68rem; }
}

@media (max-width:380px){
    :root{ --topbar-h:48px; }
    .ac-title{ font-size:.88rem; }
}

@media (max-width:360px){
    .status-badge, .view-btn{ font-size:.64rem; }
}
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
        <div class="sk-tabs">
            <div class="sk sk-tab"></div>
            <div class="sk sk-tab"></div>
        </div>
        <div class="sk sk-search"></div>
        <?php for($i=0;$i<6;$i++): ?>
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

<div class="ac-lane">

    <!-- ══ TOPBAR ══ -->
    <div class="ac-topbar">
        <button type="button" class="ac-x" onclick="acClose()" aria-label="Close">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
        <span class="ac-title">Untracked</span>
    </div>

    <!-- ══ TABS ══ -->
    <div class="tabs-wrap">
        <button type="button" class="tab-btn active" data-tab="tab-unfollowed">
            <span class="tab-count"><?php echo number_format($uf_count); ?></span>
            <span class="tab-label">Untracked Me</span>
        </button>
        <button type="button" class="tab-btn" data-tab="tab-blocked">
            <span class="tab-count"><?php echo number_format($bl_count); ?></span>
            <span class="tab-label">Blocked Me</span>
        </button>
    </div>

    <!-- ══ SEARCH ══ -->
    <?php if($uf_count + $bl_count > 0): ?>
    <div class="search-wrap">
        <div class="search-inner">
            <i class="ri-search-line search-icon"></i>
            <input type="text" class="search-input" id="globalSearch"
                   placeholder="Search users…" oninput="filterUsers(this)" autocomplete="off">
        </div>
    </div>
    <?php endif; ?>

    <!-- ══ UNTRACKED LIST ══ -->
    <div class="user-list active" id="tab-unfollowed">
        <?php if($uf_count > 0): ?>
            <p class="context-note"><i class="ri-information-line"></i> This list shows users who untracked you, with their most recent untrack only. New events are logged automatically going forward — activity from before this feature was added won't appear here.</p>
            <?php
            $unfollowed_me->data_seek(0);
            while($u = $unfollowed_me->fetch_assoc()):
                $pic   = !empty($u['profile_pic']) ? $u['profile_pic'] : 'uploads/me.jpeg';
                $ago   = timeAgo($u['unfollowed_at']);
                $exact = date('M j, Y · g:i A', strtotime($u['unfollowed_at']));
                $dname = strtolower($u['username']);
            ?>
            <div class="user-row" data-name="<?php echo htmlspecialchars($dname); ?>" onclick="location.href='profile.php?user_id=<?php echo (int)$u['id']; ?>'" style="cursor:pointer;">
                <img class="user-avatar" src="<?php echo htmlspecialchars($pic); ?>" onerror="this.src='uploads/me.jpeg'" alt="<?php echo htmlspecialchars($u['username']); ?>">
                <div class="user-info">
                    <div class="uname"><span class="un-nm"><?php echo htmlspecialchars($u['username']); ?></span><?php echo !empty($u['is_verified']) ? dz_badge('f') : ''; ?></div>
                    <div class="umeta"><i class="ri-time-line"></i> <?php echo $ago; ?></div>
                    <div class="uexact"><?php echo $exact; ?></div>
                </div>
                <div class="row-actions">
                    <div class="status-badge unfollowed">Untracked</div>
                    <a class="view-btn" href="profile.php?user_id=<?php echo (int)$u['id']; ?>" onclick="event.stopPropagation();">View Profile</a>
                </div>
            </div>
            <?php endwhile; ?>
            <div class="no-results">
                <i class="ri-search-line"></i>
                <span>No users found</span>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <div class="es-title">Nobody has untracked you</div>
                <p class="es-text">Your Trackers are sticking around. If someone ever untracks you, they'll show up here with the exact time it happened.</p>
                <div class="es-tips">
                    <div class="es-tip"><i class="ri-history-line"></i><span>Only each person's most recent untrack is listed.</span></div>
                    <div class="es-tip"><i class="ri-notification-3-line"></i><span>New events are logged automatically and a red dot lets you know.</span></div>
                </div>
                <a class="es-btn" href="profile.php?user_id=<?php echo $user_id; ?>">Back to profile</a>
            </div>
        <?php endif; ?>
    </div>

    <!-- ══ BLOCKED LIST ══ -->
    <div class="user-list" id="tab-blocked">
        <?php if($bl_count > 0): ?>
            <p class="context-note"><i class="ri-information-line"></i> These users have blocked you on Dezzme. You can't see their content or interact with their profile while they have you blocked.</p>
            <?php
            $blocked_me->data_seek(0);
            while($u = $blocked_me->fetch_assoc()):
                $pic   = !empty($u['profile_pic']) ? $u['profile_pic'] : 'uploads/me.jpeg';
                $ago   = timeAgo($u['blocked_at']);
                $exact = date('M j, Y · g:i A', strtotime($u['blocked_at']));
                $dname = strtolower($u['username']);
            ?>
            <div class="user-row" data-name="<?php echo htmlspecialchars($dname); ?>" onclick="location.href='profile.php?user_id=<?php echo (int)$u['id']; ?>'" style="cursor:pointer;">
                <img class="user-avatar" src="<?php echo htmlspecialchars($pic); ?>" onerror="this.src='uploads/me.jpeg'" alt="<?php echo htmlspecialchars($u['username']); ?>">
                <div class="user-info">
                    <div class="uname"><span class="un-nm"><?php echo htmlspecialchars($u['username']); ?></span><?php echo !empty($u['is_verified']) ? dz_badge('f') : ''; ?></div>
                    <div class="umeta"><i class="ri-time-line"></i> <?php echo $ago; ?></div>
                    <div class="uexact"><?php echo $exact; ?></div>
                </div>
                <div class="row-actions">
                    <div class="status-badge blocked">Blocked</div>
                    <a class="view-btn" href="profile.php?user_id=<?php echo (int)$u['id']; ?>" onclick="event.stopPropagation();">View Profile</a>
                </div>
            </div>
            <?php endwhile; ?>
            <div class="no-results">
                <i class="ri-search-line"></i>
                <span>No users found</span>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <div class="es-title">Nobody has blocked you</div>
                <p class="es-text">All clear. If someone ever blocks you on Dezzme, you'll see them here, which explains why their profile and posts disappear.</p>
                <div class="es-tips">
                    <div class="es-tip"><i class="ri-eye-off-line"></i><span>Blocked profiles can't be viewed or interacted with.</span></div>
                    <div class="es-tip"><i class="ri-notification-3-line"></i><span>A red dot on your profile tells you when something new shows up.</span></div>
                </div>
                <a class="es-btn" href="profile.php?user_id=<?php echo $user_id; ?>">Back to profile</a>
            </div>
        <?php endif; ?>
    </div>

</div><!-- /.ac-lane -->

<script>
// ── X: go back, or to the profile if there is no history ──
function acClose(){
    if (document.referrer && document.referrer.indexOf(window.location.host) !== -1) history.back();
    else location.href = 'profile.php?user_id=<?php echo $user_id; ?>';
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

// ── Tabs ──────────────────────────────────────────────────
document.querySelectorAll('.tab-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
        document.querySelectorAll('.tab-btn').forEach(function(b){ b.classList.remove('active'); });
        document.querySelectorAll('.user-list').forEach(function(l){ l.classList.remove('active'); });
        btn.classList.add('active');
        var target = document.getElementById(btn.dataset.tab);
        if(target) target.classList.add('active');
        var inp = document.getElementById('globalSearch');
        if(inp) filterUsers(inp);
    });
});

// ── Search ────────────────────────────────────────────────
function filterUsers(input){
    var q = input.value.trim().toLowerCase();
    var activeList = document.querySelector('.user-list.active');
    if(!activeList) return;

    var rows = activeList.querySelectorAll('.user-row');
    var vis  = 0;
    rows.forEach(function(r){
        var match = r.dataset.name.indexOf(q) > -1;
        r.classList.toggle('hidden', !match);
        if(match) vis++;
    });

    var noRes = activeList.querySelector('.no-results');
    if(noRes) noRes.classList.toggle('show', q.length > 0 && vis === 0 && rows.length > 0);
}
</script>
</body>
</html>