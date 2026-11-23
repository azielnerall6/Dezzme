<?php
session_start();
include 'config.php';

$current_user = (int)($_SESSION['user_id'] ?? 0);
if (!$current_user) { header("Location: login.php"); exit(); }

$user = $conn->query("SELECT * FROM users WHERE id=$current_user")->fetch_assoc();
$profile_pic = !empty($user['profile_pic']) ? $user['profile_pic'] : 'uploads/me.jpeg';
$username = $user['username'] ?? 'You';

/* ══════════════════════════════════════════════════════════════════
   FOLLOW (watchlist) — stored in meme_follows.
   The notifications page reads this table to tell followers when an entry
   is edited, commented on, or changes status. Nothing about it is shown on this page.
   ══════════════════════════════════════════════════════════════════ */
try {
    if (empty($_SESSION['meme_follows_ok'])) {
        $conn->query("CREATE TABLE IF NOT EXISTS meme_follows (
            id INT AUTO_INCREMENT PRIMARY KEY,
            article_id INT NOT NULL,
            user_id INT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_meme_follow (article_id, user_id),
            KEY idx_meme_follow_user (user_id)
        )");
        $_SESSION['meme_follows_ok'] = 1;
    }
} catch (Throwable $e) { error_log('meme_follows setup: ' . $e->getMessage()); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'follow') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $in  = json_decode(file_get_contents('php://input'), true) ?: [];
        $aid = (int)($in['article_id'] ?? 0);
        $on  = !empty($in['follow']);
        $chk = $conn->prepare("SELECT id FROM meme_articles WHERE id = ?");
        $chk->bind_param('i', $aid); $chk->execute();
        if ($aid <= 0 || !$chk->get_result()->fetch_assoc()) { echo json_encode(['success' => false, 'error' => 'Entry not found']); exit; }
        if ($on) {
            $st = $conn->prepare("INSERT IGNORE INTO meme_follows (article_id, user_id) VALUES (?, ?)");
        } else {
            $st = $conn->prepare("DELETE FROM meme_follows WHERE article_id = ? AND user_id = ?");
        }
        $st->bind_param('ii', $aid, $current_user); $st->execute();
        echo json_encode(['success' => true, 'following' => $on]);
    } catch (Throwable $e) {
        error_log('meme follow: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Could not save']);
    }
    exit;
}

$followed_ids = [];
try {
    $fr = $conn->query("SELECT article_id FROM meme_follows WHERE user_id = " . (int)$current_user);
    if ($fr) while ($row = $fr->fetch_assoc()) $followed_ids[] = (int)$row['article_id'];
} catch (Throwable $e) {}

/* ══════════════════════════════════════════════════════════════════
   THEME: RED by default.  BLUE (same as the Community / Pods page)
   when the page is opened from the group page.

   How it is detected:
   1. ?from=group in the URL   (explicit — best, add it to the Memepedia link in group.php)
   2. otherwise, the browser's Referer is group.php on this same site
   Any other value of ?from=  (e.g. ?from=nav) forces red.
   Once blue is detected, the JS below adds ?from=group to the address bar
   so a refresh keeps the blue theme.
   ══════════════════════════════════════════════════════════════════ */
$from_group = false;
if (isset($_GET['from'])) {
    $from_group = (strtolower((string)$_GET['from']) === 'group');
} else {
    $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
    if ($ref !== '') {
        $ref_host = strtolower((string)parse_url($ref, PHP_URL_HOST));
        $ref_path = (string)parse_url($ref, PHP_URL_PATH);
        $my_host  = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
        if ($ref_path !== '' && strtolower(basename($ref_path)) === 'group.php' && ($ref_host === '' || $ref_host === $my_host)) {
            $from_group = true;
        }
    }
}

$categories_list = [
    'Image Memes','Video Memes','GIF Memes','Reaction Memes',
    'Audio Memes','Anime Memes','Gaming Memes','Internet Slang',
    'Classic Internet','Brainrot','Viral Challenges','Sports Memes'
];

// ── Full-Article segments ───────────────────────────────────────────
// Keys here MUST match $SEGMENT_KEYS in api_articles.php exactly.
$segment_defs = [
    'origin'                => ['label' => 'Origin',                    'icon' => 'ri-seedling-line'],
    'creator_source'        => ['label' => 'Creator / Source',          'icon' => 'ri-user-star-line'],
    'how_popular'           => ['label' => 'How It Became Popular',     'icon' => 'ri-rocket-2-line'],
    'variations'            => ['label' => 'Major Variations',          'icon' => 'ri-shuffle-line'],
    'platforms'             => ['label' => 'Platforms Where It Spread', 'icon' => 'ri-global-line'],
    'cultural_significance' => ['label' => 'Cultural Significance',     'icon' => 'ri-earth-line'],
    'notable_uses'          => ['label' => 'Notable Uses',              'icon' => 'ri-award-line'],
    'sources_references'    => ['label' => 'Sources & References',     'icon' => 'ri-links-line'],
    'evolution'             => ['label' => 'Evolution Over Time',       'icon' => 'ri-line-chart-line'],
    'current_status'        => ['label' => 'Current Status',            'icon' => 'ri-pulse-line'],
    'related_memes'         => ['label' => 'Related Memes',             'icon' => 'ri-git-merge-line'],
];

// After these 0-indexed segment positions (i.e. after every 3 segments),
// an optional extra image slot is offered. index => slot number.
$extra_image_after = [2 => 1, 5 => 2, 8 => 3];

/* Optional deep-link support: memepedia.php?open=123 opens that entry on load */
$open_id = (int)($_GET['open'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Memepedia | Dezzme</title>
<script>try{if(localStorage.getItem('dz_theme')==='light')document.documentElement.setAttribute('data-theme','light');}catch(e){}</script>

<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">

<style>
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; font-family:'Poppins', sans-serif; }
button, a { cursor:pointer; outline:none; -webkit-tap-highlight-color:transparent; }
a { color:inherit; text-decoration:none; }
img, video { max-width:100%; display:block; }

[class^="ri-"], [class*=" ri-"],
[class^="ri-"]::before, [class*=" ri-"]::before,
[class^="ri-"]::after,  [class*=" ri-"]::after {
    font-family:'remixicon' !important;
    font-style:normal; font-weight:normal; line-height:1;
    speak:none; text-transform:none;
    -webkit-font-smoothing:antialiased;
}

/* ══════════ THEME (the ONLY block that differs between red and blue) ══════════ */
/*THEME-START*/
<?php if ($from_group): ?>
/* BLUE — same as the Community / Pods page (opened from the group page) */
:root{
    --accent:        #2a5fd8;
    --accent-hover:  #234fb5;
    --accent-soft:   rgba(42,95,216,.18);
    --accent-border: rgba(42,95,216,.42);
    --accent-fill:   rgba(42,95,216,.24);
    --accent-glow:   rgba(42,95,216,.3);
    --accent-text:   #7ea6ff;
    --accent-tag:    #7ea6ff;
    --accent-focus:  rgba(42,95,216,.85);
    --accent-ring:   rgba(42,95,216,.18);
    --accent-sel:    rgba(42,95,216,.4);
    --accent-rgb:    42,95,216;
    --scroll-thumb:  rgba(42,95,216,.55);
    --gold:          #7ea6ff;
    --gold-dim:      rgba(126,166,255,0.1);
    --gold-border:   rgba(126,166,255,0.35);
}
:root[data-theme="light"]{
    --accent-text:   #1a56db;
    --scroll-thumb:  rgba(42,95,216,.4);
    --gold:          #1a56db;
    --gold-dim:      rgba(26,86,219,0.08);
    --gold-border:   rgba(26,86,219,0.35);
}
<?php else: ?>
/* RED — default Memepedia theme */
:root{
    --accent:        #FF2D2D;
    --accent-hover:  #ff1a1a;
    --accent-soft:   rgba(255,45,45,.14);
    --accent-border: rgba(255,45,45,.4);
    --accent-fill:   rgba(255,45,45,.2);
    --accent-glow:   rgba(255,45,45,.28);
    --accent-text:   #ff8a8a;
    --accent-tag:    #ff8a8a;
    --accent-focus:  rgba(255,45,45,.7);
    --accent-ring:   rgba(255,45,45,.12);
    --accent-sel:    rgba(255,45,45,.35);
    --accent-rgb:    255,45,45;
    --scroll-thumb:  rgba(255,255,255,.32);
    --gold:          #ff8a8a;
    --gold-dim:      rgba(255,138,138,0.1);
    --gold-border:   rgba(255,138,138,0.35);
}
:root[data-theme="light"]{
    --accent-text:   #d01f1f;
    --scroll-thumb:  rgba(6,7,26,.3);
    --gold:          #d01f1f;
    --gold-dim:      rgba(208,31,31,0.07);
    --gold-border:   rgba(208,31,31,0.35);
}
<?php endif; ?>
/*THEME-END*/

:root{
    --fg:            255,255,255;   /* R,G,B of the tint used for lines, borders and soft fills (dark theme = white) */
    --ui-text:       #ffffff;
    --danger:        #ff6b6b;
    --danger-soft:   rgba(255,107,107,.14);
    --on-accent:     #000;
    --solid:         #070924;
    --surface:       rgba(var(--fg),0.05);
    --surface-2:     rgba(var(--fg),0.08);
    --border:        rgba(var(--fg),0.14);
    --line:          rgba(var(--fg),0.09);
    --text:          #ffffff;
    --muted:         rgba(var(--fg),0.45);
    --muted-2:       rgba(var(--fg),0.3);
    --sub:           rgba(var(--fg),0.62);
    --bg: linear-gradient(-45deg,#010218,#070924,#070924);
    --ph:            #15171f;
    --ph2:           #171a24;
    --ph-grad:       linear-gradient(135deg,#262b3d,#171a24);
    --pop-bg:        rgba(12,12,28,.98);

    --max-w:    580px;
    --topbar-h: 52px;
    --pad:      clamp(9px, 3vw, 16px);
    --ctl-h:    clamp(30px, 8vw, 36px);
    --r:        5px;
    --act-h:    clamp(25px, 7.2vw, 30px);
    --act-r:    4px;
    --chip-h:   clamp(22px, 6vw, 26px);
}

html{ scroll-behavior:smooth; -webkit-text-size-adjust:100%; }

@media (max-width:768px){
    html, body, *{ scrollbar-width:none !important; -ms-overflow-style:none !important; }
    *::-webkit-scrollbar, html::-webkit-scrollbar, body::-webkit-scrollbar{ display:none !important; width:0 !important; height:0 !important; background:transparent !important; }
}
/* page scrollbar: normal browser one (like the pod Discussions page). Hidden on responsive by the rule above. */
::selection{ background:var(--accent-sel); color:var(--ui-text); }

body{
    background:var(--bg);
    background-attachment:fixed;
    color:var(--text);
    min-height:100vh;
    overflow-x:hidden;
    -webkit-font-smoothing:antialiased;
}

/* ══ SHELL: main lane on the left, info column on the right (same idea as the pod Discussions page) ══ */
.mp-shell{ width:100%; display:flex; align-items:flex-start; justify-content:center; }
.mp-side{ display:none; }
@media (min-width:1100px){
    .mp-shell{ justify-content:flex-start; max-width:1240px; margin:0 auto; padding-left:22px; }
    .mp-side{
        display:block; flex:1 1 0; min-width:0; position:sticky; top:0; height:100vh;
        overflow-y:auto; overflow-x:hidden; padding:0 22px 30px 20px;
        scrollbar-width:thin; scrollbar-color:var(--scroll-thumb) transparent;
    }
    .mp-side::-webkit-scrollbar{ width:5px; }
    .mp-side::-webkit-scrollbar-thumb{ background:var(--scroll-thumb); border-radius:99px; }
}

/* ── centered lane + grey side lines ── */
.ac-lane{
    width:100%; max-width:var(--max-w);
    min-height:100vh; align-self:stretch;
    display:flex; flex-direction:column;
    padding-bottom:48px;
}
@media (min-width:801px){ .ac-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); } }
@media (max-width:800px){ .ac-lane{ max-width:100%; } }
@media (min-width:1100px){ .ac-lane{ flex:0 0 var(--max-w); width:var(--max-w); } }

/* ══ TOPBAR — title left, icons right, grey line pinned under it ══ */
.ac-topbar{
    position:sticky; top:0; z-index:200;
    height:var(--topbar-h); flex-shrink:0;
    display:flex; align-items:center; gap:2px;
    padding:0 10px;
    background:var(--bg); background-attachment:fixed;
}
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
.ac-x:hover{ opacity:1; color:var(--accent); }
.ac-x svg{ width:22px; height:22px; display:block; }
.ac-x i{ font-size:28px; }
.ac-title{
    flex:1; min-width:0;
    font-size:.92rem; font-weight:700; color:var(--ui-text); letter-spacing:-.1px;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    text-align:right; margin:0 6px 0 10px;
}
/* "Memepedia" word — sizes for every width are set in the TITLE block at the very bottom of the CSS */
.ac-title.left{ text-align:left; margin:0 6px 0 8px; font-size:1.4rem; letter-spacing:3px; }

.tb-icons{ display:flex; align-items:center; gap:0; flex-shrink:0; }
.tb-icon-btn{
    width:40px; height:40px; background:none; border:none; border-radius:50%;
    color:var(--ui-text); font-size:1.5rem;
    display:flex; align-items:center; justify-content:center;
    transition:color .2s, transform .15s; padding:0;
}
.tb-icon-btn:hover{ color:var(--accent); }
.tb-icon-btn:active{ transform:scale(.9); }
.tb-add-btn{ font-size:1.8rem; }
.sort-btn.active{ color:var(--accent); }
.sort-btn i{ font-size:.92em; }

/* search icon = the exact same SVG that nav.php uses (ring + solid handle) */
.tb-search-svg{ width:.95em; height:.95em; display:block; color:inherit; flex-shrink:0; }
.tb-search-svg .nav-search-ring{ stroke-width:2.5; }

/* ══ FILTER CHIPS (one solid colour bar) ══ */
.toolbar{
    position:sticky; top:var(--topbar-h); z-index:190;
    display:flex; align-items:center; gap:8px;
    padding:10px var(--pad);
    background:var(--solid);
    border-bottom:1px solid var(--line);
}
.filter-chips{ display:flex; align-items:center; gap:6px; overflow-x:auto; flex:1; scrollbar-width:none; -ms-overflow-style:none; }
.filter-chips::-webkit-scrollbar{ display:none; }
@media (max-width:768px){
    /* chips scroll all the way to the screen edges instead of being cut off at the padding line */
    .toolbar{ gap:0; }
    .filter-chips{ margin:0 calc(var(--pad) * -1); padding:0 var(--pad); }
}
.chip{
    display:flex; align-items:center; gap:5px; flex-shrink:0;
    height:var(--chip-h); padding:0 12px; border-radius:var(--act-r);
    border:none; background:rgba(var(--fg),0.1);
    backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    color:rgba(var(--fg),.75); font-size:.7rem; font-weight:600;
    transition:background .2s, color .2s, transform .15s; white-space:nowrap;
}
.chip i{ font-size:.9em; }
.chip:hover{ background:rgba(var(--fg),0.2); color:var(--ui-text); }
.chip:active{ transform:scale(.96); }
.chip.active{ background:var(--accent); color:var(--on-accent); font-weight:700; }
@media (max-width:490px){ .chip{ font-size:.64rem; padding:0 10px; } }

/* sort menu: dropdown on desktop, bottom sheet on small screens */
.toolbar-sort{ position:relative; flex-shrink:0; }
.sort-menu{
    position:absolute; right:0; top:calc(100% + 18px); z-index:20000;
    background:var(--pop-bg); border:1px solid var(--line); border-radius:10px;
    min-width:190px; overflow:hidden;
    display:flex; flex-direction:column;
    opacity:0; visibility:hidden; transform:translateY(-6px);
    transition:opacity .22s ease, transform .22s ease, visibility .22s;
    pointer-events:none; box-shadow:0 14px 30px rgba(0,0,0,.5);
}
.sort-menu.open{ opacity:1; visibility:visible; transform:translateY(0); pointer-events:auto; }
.sort-opt{
    display:flex; align-items:center; gap:10px; padding:13px 16px;
    font-size:.76rem; font-weight:500; color:rgba(var(--fg),.8); transition:background .2s; cursor:pointer;
}
.sort-opt + .sort-opt{ border-top:1px solid var(--line); }
.sort-opt:hover{ background:rgba(var(--fg),.05); }
.sort-opt.active{ color:var(--ui-text); font-weight:700; }
.sort-opt.active i{ color:var(--accent); }
.sort-opt i{ font-size:.95rem; color:rgba(var(--fg),.5); width:16px; text-align:center; }

.toolbar.sort-open{ z-index:20001; }   /* menu sits inside the toolbar, so the toolbar must rise with it */
.sort-backdrop{
    position:fixed; inset:0; z-index:20000;
    background:rgba(0,0,0,0); opacity:0; visibility:hidden; pointer-events:none;
    transition:opacity .25s ease, background .25s ease, visibility .25s ease;
}
.sort-backdrop.open{ opacity:1; visibility:visible; pointer-events:auto; background:rgba(0,0,0,.6); }

@media (max-width:790px){
    .sort-menu{
        position:fixed; left:0; right:0; top:auto; bottom:0; min-width:0;
        border-radius:16px 16px 0 0; border-bottom:none;
        padding:6px 0 calc(8px + env(safe-area-inset-bottom, 0px));
        transform:translateY(100%); opacity:1;
    }
    .sort-menu::before{ content:''; display:block; width:36px; height:4px; border-radius:4px; background:rgba(var(--fg),.18); margin:8px auto 6px; }
    .sort-menu.open{ transform:translateY(0); }
    .sort-opt{ padding:0 20px; min-height:48px; font-size:.82rem; }
}
@media (max-width:490px){ .sort-opt{ min-height:44px; font-size:.78rem; padding:0 16px; } }

/* results bar: count on the left, Filters + view toggle on the right */
.results-bar{
    display:flex; align-items:center; justify-content:space-between; gap:10px;
    padding:12px var(--pad) 6px;
    font-size:.7rem; color:var(--muted);
}
.results-bar strong{ color:var(--ui-text); font-weight:700; }

/* ══ LIST ══ */
.entries-list{ display:flex; flex-direction:column; }
.entry-row{
    display:flex; align-items:flex-start; gap:clamp(11px, 3vw, 14px);
    padding:clamp(12px, 3.4vw, 15px) var(--pad); border-bottom:1px solid var(--line);
    transition:background .18s; position:relative; cursor:pointer;
    opacity:0; animation:rowIn .4s ease forwards;
}
@keyframes rowIn{ from{ opacity:0; transform:translateY(8px);} to{ opacity:1; transform:translateY(0);} }
.entry-row:hover{ background:rgba(var(--fg),.04); }

.entry-thumb{
    width:clamp(74px, 22vw, 96px); height:clamp(74px, 22vw, 96px);
    border-radius:var(--r); overflow:hidden; flex-shrink:0;
    background:var(--ph); position:relative;
}
.entry-thumb img, .entry-thumb video{ width:100%; height:100%; object-fit:cover; display:block; }
.entry-thumb .thumb-no-img{
    width:100%; height:100%; display:flex; align-items:center; justify-content:center;
    background:var(--ph-grad); font-size:1.8rem;
}
.thumb-media-tag{
    position:absolute; bottom:4px; right:4px;
    background:rgba(4,6,18,.75); color:rgba(255,255,255,.9);
    font-size:.5rem; font-weight:700; letter-spacing:.3px;
    padding:2px 6px; border-radius:3px; text-transform:uppercase;
}

.entry-info{ flex:1; min-width:0; padding-top:1px; padding-right:30px; }
.entry-status-row{ display:flex; align-items:center; gap:7px; margin-bottom:5px; flex-wrap:wrap; }
.status-pill{
    font-size:.58rem; font-weight:700; padding:2px 8px; border-radius:var(--act-r);
    display:inline-flex; align-items:center; gap:4px;
    background:var(--accent-soft); border:1px solid var(--accent-border); color:var(--accent-text);
}
.entry-cat-tag{ font-size:.58rem; color:var(--muted-2); font-weight:600; text-transform:uppercase; letter-spacing:.5px; }
.entry-title{
    font-size:clamp(.78rem, 2.5vw, .9rem); font-weight:700; color:var(--ui-text);
    line-height:1.35; margin-bottom:3px;
    display:-webkit-box; -webkit-line-clamp:1; -webkit-box-orient:vertical; overflow:hidden;
}
.entry-summary{
    font-size:clamp(.64rem, 2vw, .72rem); color:var(--muted); line-height:1.55; margin-bottom:6px;
    display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;
}
.entry-meta{ font-size:clamp(.58rem, 1.9vw, .66rem); color:var(--muted-2); display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.entry-meta span{ display:flex; align-items:center; gap:3px; }
.entry-meta .mine-tag{ color:var(--accent-text); font-weight:700; }
.entry-meta .fol-tag{ color:var(--accent-text); }
.entry-meta .ver-tag{ color:#4dde8a; }
[data-theme="light"] .entry-meta .ver-tag{ color:#14803c; }


.row-actions{ position:absolute; top:12px; right:var(--pad); display:flex; align-items:center; gap:6px; z-index:3; }
.row-icon-btn{
    width:var(--act-h); height:var(--act-h); border-radius:var(--act-r); border:none;
    background:rgba(var(--fg),.1); backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    color:rgba(var(--fg),.75);
    display:flex; align-items:center; justify-content:center; font-size:.85rem; transition:.2s; flex-shrink:0;
}
.row-icon-btn:hover{ background:rgba(var(--fg),.2); color:var(--ui-text); }
.row-icon-btn.del:hover{ background:var(--danger-soft); color:var(--danger); }

/* ══ EMPTY STATE ══ */
.empty-state{
    flex:1; min-height:48vh; display:none;
    padding:clamp(28px, 8vw, 48px) clamp(20px, 7vw, 36px) clamp(36px, 10vw, 60px);
    flex-direction:column; align-items:center; justify-content:center; text-align:center;
}
.es-title{ font-size:clamp(.95rem, 3.4vw, 1.1rem); font-weight:700; color:var(--ui-text); letter-spacing:-.2px; }
.es-text{ margin-top:6px; max-width:340px; font-size:clamp(.66rem, 2.1vw, .76rem); color:var(--muted); line-height:1.7; }
.es-tips{ width:100%; max-width:360px; margin-top:clamp(16px, 5vw, 24px); display:flex; flex-direction:column; gap:7px; }
.es-tip{ display:flex; align-items:center; gap:10px; padding:10px 12px; border-radius:var(--act-r); background:rgba(var(--fg),0.05); text-align:left; }
.es-tip i{ font-size:1rem; color:var(--accent); flex-shrink:0; }
.es-tip span{ font-size:clamp(.62rem, 2vw, .7rem); color:var(--sub); line-height:1.5; }
.es-btn{
    margin-top:clamp(16px, 5vw, 22px);
    display:flex; align-items:center; justify-content:center; gap:6px;
    height:var(--act-h); min-width:150px; padding:0 22px;
    border:none; border-radius:var(--act-r);
    background:var(--accent); color:var(--on-accent); font-size:.72rem; font-weight:700;
    transition:background .2s ease, transform .15s ease;
}
.es-btn:hover{ background:var(--accent-hover); }
.es-btn:active{ transform:scale(0.98); }
@media (max-width:490px){ .es-btn{ font-size:.62rem; } }

/* ═════════ RIGHT COLUMN CARDS (same design as the pod Discussions page) ═════════ */
.gs-cards{ padding:18px 0 2px; }
.gs-card{ margin-bottom:12px; }
.gs-bar{
    display:flex; align-items:center; gap:7px; width:100%; height:30px; padding:0 12px; border:none; border-radius:var(--act-r);
    background:rgba(var(--fg),.1); backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    color:var(--ui-text); font-size:.72rem; font-weight:600; line-height:1; text-align:left; transition:background .2s ease, transform .15s ease;
}
.gs-bar:hover{ background:rgba(var(--fg),.2); }
.gs-bar:active{ transform:scale(.99); }
.gs-bar-ic{ font-size:14px; color:var(--accent-text); flex-shrink:0; }
.gs-bar-t{ flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.gs-bar-chev{ font-size:18px; color:rgba(var(--fg),.75); flex-shrink:0; transition:transform .25s ease; }
.gs-card.collapsed .gs-bar-chev{ transform:rotate(-90deg); }
.gs-body{ display:grid; grid-template-rows:1fr; transition:grid-template-rows .3s ease; }
.gs-inner{ min-height:0; overflow:hidden; }
.gs-pad{ padding-top:8px; }
.gs-card.collapsed .gs-body{ grid-template-rows:0fr; }
.gs-row{ display:flex; align-items:center; justify-content:space-between; gap:12px; padding:9px 2px; border-bottom:1px solid rgba(var(--fg),.06); }
.gs-row:last-child{ border-bottom:none; }
.gs-row-l{ font-size:.66rem; color:rgba(var(--fg),.5); font-weight:500; white-space:nowrap; }
.gs-row-v{ font-size:.74rem; font-weight:600; color:var(--ui-text); text-align:right; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.gs-p{ font-size:.72rem; line-height:1.65; color:rgba(var(--fg),.7); overflow-wrap:anywhere; padding:2px 2px 4px; }
.gs-p b{ color:var(--ui-text); font-weight:600; }
.gs-callout{
    display:flex; gap:9px; margin:8px 0 4px; padding:10px 11px; border-radius:var(--act-r);
    background:var(--accent-soft); border:1px solid var(--accent-border);
    font-size:.67rem; line-height:1.6; color:rgba(var(--fg),.8);
}
.gs-callout i{ color:var(--accent-text); font-size:1rem; flex-shrink:0; margin-top:1px; }
.gs-callout b{ color:var(--ui-text); }
.gs-step{ display:flex; gap:11px; padding:10px 2px; border-bottom:1px solid rgba(var(--fg),.06); }
.gs-step:last-child{ border-bottom:none; }
.gs-sn{ width:22px; height:22px; border-radius:50%; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:.62rem; font-weight:700; color:var(--on-accent); background:var(--accent); }
.gs-st{ min-width:0; }
.gs-st b{ display:block; font-size:.72rem; margin-bottom:2px; }
.gs-st span{ display:block; font-size:.65rem; line-height:1.55; color:rgba(var(--fg),.58); overflow-wrap:anywhere; }
.gs-topic{
    display:flex; align-items:flex-start; gap:10px; width:100%; padding:9px 2px; border:none; border-bottom:1px solid rgba(var(--fg),.06);
    background:none; color:inherit; font:inherit; text-align:left;
}
.gs-topic:last-child{ border-bottom:none; }
.gs-topic:hover .gs-tn b{ text-decoration:underline; }
.gs-ti{ width:28px; height:28px; border-radius:var(--act-r); display:flex; align-items:center; justify-content:center; font-size:.95rem; flex-shrink:0; background:var(--accent-soft); color:var(--accent-text); }
.gs-tx{ flex:1; min-width:0; }
.gs-tn{ display:flex; align-items:center; gap:7px; flex-wrap:wrap; }
.gs-tn b{ font-size:.72rem; }
.gs-tn small{ font-size:.56rem; font-weight:600; color:var(--muted); padding:1px 7px; border-radius:4px; background:rgba(var(--fg),.07); }
.gs-td{ display:block; font-size:.64rem; line-height:1.5; color:rgba(var(--fg),.55); margin-top:2px; overflow-wrap:anywhere; }
.gs-rule{ display:flex; gap:9px; padding:6px 2px; font-size:.68rem; line-height:1.55; color:rgba(var(--fg),.74); overflow-wrap:anywhere; }
.gs-rule i{ color:var(--accent-text); font-size:.95rem; flex-shrink:0; margin-top:1px; }
.gs-th{
    display:flex; align-items:center; gap:10px; width:100%; padding:9px 2px; border:none; border-bottom:1px solid rgba(var(--fg),.06);
    background:none; color:inherit; font:inherit; text-align:left;
}
.gs-th:last-child{ border-bottom:none; }
.gs-th:hover{ opacity:.78; }
.gs-th-img{ width:38px; height:38px; border-radius:var(--act-r); overflow:hidden; flex-shrink:0; background:var(--ph); display:flex; align-items:center; justify-content:center; font-size:1.1rem; }
.gs-th-img img{ width:100%; height:100%; object-fit:cover; }
.gs-th-tx{ flex:1; min-width:0; }
.gs-th b{ display:block; font-size:.72rem; font-weight:600; line-height:1.4; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.gs-th small{ display:block; font-size:.62rem; color:var(--muted); margin-top:1px; }
.gs-note{ font-size:.68rem; color:rgba(var(--fg),.5); line-height:1.55; padding:4px 2px; }

/* ══ MODAL SHELL (full screen, same lane) ══ */
.modal-overlay{
    position:fixed; inset:0; z-index:1000; display:none;
    justify-content:center; align-items:flex-start; background:var(--bg); background-attachment:fixed;
    overflow-y:auto; overflow-x:hidden; -webkit-overflow-scrolling:touch;
}
.modal-overlay.active{ display:flex; }
@media (min-width:801px) and (hover:hover) and (pointer:fine){
    .modal-overlay{ scrollbar-width:thin; scrollbar-color:var(--scroll-thumb) transparent; }
    .modal-overlay::-webkit-scrollbar{ display:block; width:5px; }
    .modal-overlay::-webkit-scrollbar-track{ background:transparent; }
    .modal-overlay::-webkit-scrollbar-thumb{ background:var(--scroll-thumb); border-radius:99px; }
}
.modal-lane{ width:100%; max-width:var(--max-w); min-height:100%; display:flex; flex-direction:column; }
@media (min-width:801px){ .modal-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); } }
@media (max-width:800px){ .modal-lane{ max-width:100%; } }
.modal-topbar{ z-index:5; }
/* article + upload/edit modals sit above nav.php (sidebar is 9999, its hold-menu 10000) */
#modal-entry, #modal-create{ z-index:20000; }
.modal-body{ flex:1; padding:6px var(--pad) 36px; width:100%; }

/* ══ SEARCH MODAL ══ */
.search-input-wrap{
    flex:1; min-width:0; display:flex; align-items:center; gap:8px; margin:0 4px 0 2px;
    height:var(--ctl-h); background:var(--surface); border:1px solid var(--border);
    border-radius:var(--r); padding:0 12px; transition:border-color .2s, background .2s, box-shadow .2s;
}
.search-input-wrap:focus-within{ border-color:var(--accent-focus); background:rgba(var(--fg),.07); box-shadow:0 0 0 3px var(--accent-ring); }
.search-input-wrap i{ color:var(--muted); font-size:.95rem; flex-shrink:0; }
.search-input-wrap svg.si-search{ width:.95rem; height:.95rem; flex-shrink:0; color:var(--muted); display:block; }
.search-input-wrap input{ background:transparent; border:none; outline:none; flex:1; min-width:0; color:var(--text); font-size:.76rem; }
.search-input-wrap input::placeholder{ color:var(--muted); }
.search-clear-x{ background:none; border:none; color:var(--muted); font-size:1.05rem; display:none; flex-shrink:0; }
.search-clear-x:hover{ color:var(--accent); }
.search-clear-x.show{ display:flex; }
#searchResultsWrap{ margin:0 calc(var(--pad) * -1); }
.search-recents-label{ font-size:.6rem; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; color:var(--muted); padding:16px var(--pad) 8px; }
.search-empty-msg{ text-align:center; padding:60px 20px; color:var(--muted); font-size:.78rem; }
.search-empty-msg i{ font-size:2.1rem; color:var(--accent); display:block; margin-bottom:10px; opacity:.7; }

/* ══ CREATE / EDIT MODAL ══ */
.field-block{ margin-top:clamp(14px, 4vw, 20px); }
.field-block:first-child{ margin-top:6px; }
.field-label{ font-size:.6rem; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; color:var(--muted); display:flex; align-items:center; gap:6px; margin-bottom:9px; }
.field-label i{ color:var(--accent); font-size:.85rem; }
.field-hint{ font-size:.64rem; color:var(--muted); margin:-3px 0 10px; line-height:1.55; }

.media-uploader{
    border:1.5px dashed var(--accent-border); border-radius:var(--r); padding:24px 16px;
    text-align:center; transition:background .2s, border-color .2s; position:relative; background:var(--accent-soft);
}
.media-uploader:hover, .media-uploader.dragover{ border-color:var(--accent); background:var(--accent-fill); }
.media-uploader input{ position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; height:100%; }
.mu-icon{ font-size:1.8rem; margin-bottom:6px; color:var(--accent); }
.mu-title{ font-size:.76rem; font-weight:700; color:var(--text); margin-bottom:3px; }
.mu-sub{ font-size:.64rem; color:var(--muted); }
.mu-preview{ margin-top:14px; border-radius:var(--r); overflow:hidden; display:none; position:relative; }
.mu-preview img, .mu-preview video{ width:100%; border-radius:var(--r); }
.mu-remove{
    position:absolute; top:8px; right:8px; z-index:2; width:28px; height:28px; border-radius:var(--act-r);
    background:rgba(0,0,0,.7); border:none; color:#fff; font-size:.9rem;
    display:flex; align-items:center; justify-content:center; transition:background .2s, color .2s;
}
.mu-remove:hover{ background:var(--accent); color:var(--on-accent); }
.small-uploader{ padding:16px 12px; }
.small-uploader .mu-icon{ font-size:1.4rem; margin-bottom:4px; }

.gc-input, .gc-textarea, .gc-select{
    width:100%; padding:0 12px; height:var(--ctl-h); border-radius:var(--r);
    border:1px solid var(--border); background:var(--surface);
    color:var(--ui-text); font-size:.76rem; outline:none; transition:border-color .2s, background .2s, box-shadow .2s;
    -webkit-appearance:none; appearance:none;
}
.gc-textarea{ height:auto; padding:10px 12px; resize:vertical; min-height:90px; line-height:1.6; }
.gc-input:focus, .gc-textarea:focus, .gc-select:focus{ border-color:var(--accent-focus); background:rgba(var(--fg),.07); box-shadow:0 0 0 3px var(--accent-ring); }
.gc-input::placeholder, .gc-textarea::placeholder{ color:var(--muted); }
.gc-select{
    cursor:pointer; padding-right:36px; color-scheme:dark;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='rgba(255,255,255,0.5)' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
    background-repeat:no-repeat; background-position:right 12px center;
}
.gc-select option{ background:var(--solid); color:var(--ui-text); }
.field-row{ display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.field-row .field-block{ margin-top:clamp(14px, 4vw, 20px); }

.segment-editor{ display:flex; flex-direction:column; gap:14px; }
.segment-editor .field-block{ margin-top:0; }

.status-chips{ display:flex; flex-wrap:wrap; gap:6px; }
.status-chip{
    display:flex; align-items:center; gap:5px; height:var(--act-h); padding:0 13px; border-radius:var(--act-r);
    border:none; background:rgba(var(--fg),0.1); color:var(--ui-text);
    font-size:.7rem; font-weight:600; transition:background .2s, transform .15s;
}
.status-chip:hover{ background:rgba(var(--fg),0.2); }
.status-chip:active{ transform:scale(.96); }
.status-chip.selected{ background:var(--accent); color:var(--on-accent); font-weight:700; }

.dup-warning{ background:var(--gold-dim); border:1px solid var(--gold-border); border-radius:var(--act-r); padding:12px 14px; margin-top:14px; display:none; }
.dup-warning.show{ display:block; }
.dup-title{ font-size:.74rem; font-weight:700; color:var(--gold); margin-bottom:5px; display:flex; align-items:center; gap:6px; }
.dup-text{ font-size:.68rem; color:var(--sub); line-height:1.55; }
.dup-link{ display:inline-flex; align-items:center; gap:5px; margin-top:8px; font-size:.68rem; font-weight:700; color:var(--accent-text); text-decoration:underline; cursor:pointer; }

.gc-btn{
    height:var(--ctl-h); padding:0 16px; border-radius:var(--r); border:none;
    background:var(--accent); color:var(--on-accent); font-size:.78rem; font-weight:700;
    transition:background .2s, transform .15s; width:100%;
    display:flex; align-items:center; justify-content:center; gap:7px;
    margin-top:clamp(18px, 5vw, 24px);
}
.gc-btn:hover{ background:var(--accent-hover); }
.gc-btn:active{ transform:scale(.98); }
.gc-btn:disabled{ cursor:default; opacity:.7; }

/* ══ ENTRY DETAIL MODAL ══ */
.detail-stickybar{
    position:sticky; top:0; width:100%; height:var(--topbar-h);
    margin-bottom:calc(var(--topbar-h) * -1); z-index:60;
    pointer-events:none;
}
.detail-stickybar::before{
    content:''; position:absolute; inset:0;
    background:var(--bg); background-attachment:fixed;
    opacity:0; transition:opacity .28s ease; pointer-events:none;
}
.detail-stickybar::after{
    content:''; position:absolute; left:0; right:0; bottom:0; height:1px;
    background:rgba(var(--fg),.14); opacity:0; transition:opacity .28s ease; pointer-events:none;
}
.detail-stickybar.is-scrolled::before, .detail-stickybar.is-scrolled::after{ opacity:1; }
.detail-icon-btn{
    position:absolute; top:50%; transform:translateY(-50%);
    width:42px; height:42px; background:none; border:none; color:#fff;
    display:flex; align-items:center; justify-content:center; font-size:1.6rem;
    filter:drop-shadow(0 1px 2px rgba(0,0,0,.9)) drop-shadow(0 0 7px rgba(0,0,0,.6));
    transition:color .2s, transform .15s ease; z-index:2; pointer-events:auto;
}
.detail-icon-btn:hover{ color:var(--accent); }
.detail-icon-btn:active{ transform:translateY(-50%) scale(.9); }
.detail-icon-btn svg{ width:22px; height:22px; display:block; }
.detail-back-btn{ left:6px; }
.detail-right-icons{ position:absolute; top:0; right:6px; bottom:0; display:flex; align-items:center; pointer-events:auto; }
.detail-edit-icon-btn{ position:static; transform:none; }
.detail-edit-icon-btn:active{ transform:scale(.9); }
/* once the bar has its solid background, icons use the normal UI colour (matters in light mode) */
.detail-stickybar.is-scrolled .detail-icon-btn{ color:var(--ui-text); filter:none; }
.detail-stickybar.is-scrolled .detail-icon-btn:hover{ color:var(--accent); }

.stickybar-title{
    position:absolute; top:0; left:54px; right:142px; height:100%;
    display:flex; align-items:center;
    font-weight:700; font-size:.86rem; color:var(--ui-text);
    opacity:0; transform:translateY(4px); transition:opacity .28s ease, transform .28s ease;
    pointer-events:none; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; z-index:1;
}
.stickybar-title.show-title{ opacity:1; transform:translateY(0); }

.detail-hero{ width:100%; height:clamp(190px, 46vw, 260px); position:relative; flex-shrink:0; background:var(--ph); overflow:hidden; }
.detail-hero img, .detail-hero video{ width:100%; height:100%; object-fit:cover; display:block; }
.detail-hero .no-img{
    width:100%; height:100%; display:flex; align-items:center; justify-content:center;
    background:var(--ph-grad); color:var(--accent); font-size:2.6rem;
}
.detail-hero-fade{ position:absolute; left:0; right:0; bottom:0; height:70px; background:linear-gradient(to bottom, rgba(6,8,20,0), rgba(6,8,20,.55)); pointer-events:none; }
.hero-status-tag{
    position:absolute; bottom:10px; right:10px; z-index:2;
    display:flex; align-items:center; gap:4px;
    font-size:.6rem; font-weight:700; letter-spacing:.3px; padding:3px 9px; border-radius:var(--act-r);
    background:rgba(0,0,0,.6); border:1px solid var(--accent-border); color:var(--accent-text);
}
/* the status tag sits on a dark pill over the photo, so it needs the light accent colour in both themes */
.hero-status-tag{ color:var(--accent-tag, var(--accent-text)); }

.detail-modal-body{ padding-top:18px; flex:none; }

.detail-cat-eyebrow{ font-size:.6rem; font-weight:700; letter-spacing:1.6px; text-transform:uppercase; color:var(--accent-text); margin-bottom:8px; display:flex; align-items:center; gap:6px; }
.detail-title{ font-size:clamp(1.05rem, 4.2vw, 1.3rem); font-weight:700; color:var(--ui-text); line-height:1.3; margin-bottom:12px; word-break:break-word; letter-spacing:-.2px; }

.detail-author-row{ display:flex; align-items:center; gap:8px; margin-bottom:16px; }
.detail-author-row img{ width:26px; height:26px; border-radius:50%; object-fit:cover; border:2px solid var(--accent); }
.detail-author-row span{ font-size:.72rem; color:var(--muted); }
.detail-author-row .dot{ width:3px; height:3px; border-radius:50%; background:var(--muted-2); }

/* Follow button (sits at the right end of the author row) */
.follow-btn{
    margin-left:auto; flex-shrink:0; display:flex; align-items:center; gap:5px;
    height:var(--act-h); padding:0 13px; border:none; border-radius:var(--act-r);
    background:rgba(var(--fg),.1); color:var(--ui-text); font-size:.68rem; font-weight:700;
    transition:background .2s, color .2s, transform .15s;
}
.detail-author-row .follow-btn span{ font-size:inherit; color:inherit; }
.follow-btn i{ font-size:.95rem; }
.follow-btn:hover{ background:rgba(var(--fg),.2); }
.follow-btn:active{ transform:scale(.96); }
.follow-btn.on{ background:var(--accent); color:var(--on-accent); }
.follow-btn:disabled{ opacity:.6; }

.detail-meta-list{ display:flex; flex-direction:column; gap:9px; margin-bottom:16px; padding:12px 14px; background:var(--surface); border:1px solid var(--line); border-radius:var(--r); }
.meta-row{ display:flex; align-items:center; gap:9px; font-size:.74rem; color:var(--sub); }
.meta-row i{ font-size:.9rem; color:var(--accent); width:16px; text-align:center; flex-shrink:0; }
.meta-row .meta-label{ min-width:0; overflow-wrap:anywhere; }

.detail-info-grid{ display:grid; grid-template-columns:repeat(2,1fr); gap:1px; background:var(--line); border:1px solid var(--line); border-radius:var(--r); overflow:hidden; margin-bottom:18px; }
.detail-info-cell{ background:var(--solid); padding:11px 13px; }
.detail-info-label{ font-size:.54rem; font-weight:700; letter-spacing:1px; text-transform:uppercase; color:var(--muted); margin-bottom:4px; }
.detail-info-val{ font-size:.76rem; font-weight:600; color:var(--ui-text); word-break:break-word; }

/* "Translated to …" bar shown above the article tabs */
.tr-bar{
    display:none; align-items:center; gap:9px; margin:0 0 14px; padding:9px 12px;
    border-radius:var(--r); background:var(--accent-soft); border:1px solid var(--accent-border);
    font-size:.7rem; color:var(--ui-text);
}
.tr-bar.show{ display:flex; }
.tr-bar i{ color:var(--accent-text); font-size:1rem; flex-shrink:0; }
.tr-bar span{ flex:1; min-width:0; line-height:1.4; }
.tr-bar button{ background:none; border:none; padding:0; color:var(--accent-text); font-size:.7rem; font-weight:700; text-decoration:underline; flex-shrink:0; }
.tr-bar button:hover{ color:var(--ui-text); }

.detail-tabs{
    display:flex; align-items:stretch; border-top:1px solid var(--line); border-bottom:1px solid var(--line);
    margin:0 calc(var(--pad) * -1) 20px; overflow-x:auto; scrollbar-width:none;
}
.detail-tabs::-webkit-scrollbar{ display:none; }
.detail-tab{
    flex:1 0 auto; padding:clamp(10px, 2.8vw, 13px) 14px; font-size:.7rem; font-weight:600; color:var(--muted);
    transition:color .2s, border-color .2s; border-bottom:2px solid transparent; white-space:nowrap; margin-bottom:-1px;
    background:none; border-left:none; border-right:none; border-top:none;
    display:flex; align-items:center; justify-content:center; gap:5px;
}
.detail-tab:hover{ color:var(--ui-text); }
.detail-tab.active{ color:var(--ui-text); border-bottom-color:var(--ui-text); }
.detail-tab-panel{ display:none; }
.detail-tab-panel.active{ display:block; }

.detail-section{ margin-bottom:24px; }
.detail-section-label{ font-size:.6rem; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; color:var(--muted); margin-bottom:10px; display:flex; align-items:center; gap:8px; }
.detail-section-label::after{ content:''; flex:1; height:1px; background:var(--line); }
.detail-summary{ font-size:.82rem; color:var(--sub); line-height:1.8; font-weight:300; }
.detail-tags{ display:flex; flex-wrap:wrap; gap:6px; margin-top:8px; }
.detail-tag{ font-size:.64rem; font-weight:500; padding:4px 11px; border-radius:var(--act-r); background:rgba(var(--fg),.1); color:var(--ui-text); }

.detail-segment{ margin-bottom:22px; }
.detail-segment-label{ font-size:.6rem; font-weight:700; letter-spacing:1.3px; text-transform:uppercase; color:var(--accent-text); margin-bottom:8px; display:flex; align-items:center; gap:6px; }
.detail-segment-text{ font-size:.78rem; color:var(--sub); line-height:1.85; font-weight:300; white-space:pre-wrap; word-break:break-word; }
.detail-segment-image{ margin:22px 0; border-radius:var(--r); overflow:hidden; border:1px solid var(--line); background:var(--surface); }
.detail-segment-image img{ width:100%; display:block; }
.detail-empty-article{ font-size:.76rem; color:var(--muted); font-style:italic; line-height:1.7; }

.edit-history-list{ display:flex; flex-direction:column; }
.eh-item{ display:flex; align-items:flex-start; gap:12px; padding:12px 0; border-bottom:1px solid var(--line); }
.eh-item:last-child{ border-bottom:none; }
.eh-avatar{ width:30px; height:30px; border-radius:50%; object-fit:cover; flex-shrink:0; border:1.5px solid var(--accent); }
.eh-info{ flex:1; min-width:0; }
.eh-user{ font-size:.72rem; font-weight:600; color:var(--ui-text); }
.eh-summary{ font-size:.66rem; color:var(--muted); margin-top:2px; }
.eh-time{ font-size:.58rem; color:var(--muted-2); margin-top:3px; }
.empty-tab-msg{ font-size:.74rem; color:var(--muted); text-align:center; padding:20px 0; }

.comment-list{ display:flex; flex-direction:column; gap:14px; margin-bottom:16px; }
.comment-item{ display:flex; gap:10px; }
.comment-avatar{ width:32px; height:32px; border-radius:50%; overflow:hidden; flex-shrink:0; border:1.5px solid var(--accent); background:var(--ph2); display:flex; align-items:center; justify-content:center; color:var(--accent); font-size:.9rem; }
.comment-avatar img{ width:100%; height:100%; object-fit:cover; display:block; }
.comment-body{ flex:1; min-width:0; }
.comment-bubble{ background:var(--surface); border:1px solid var(--line); border-radius:var(--r); padding:9px 13px; }
.comment-username{ font-size:.72rem; font-weight:700; color:var(--ui-text); margin-bottom:1px; }
.comment-time-inline{ font-size:.58rem; color:var(--muted); margin-bottom:5px; }
.comment-text{ font-size:.74rem; color:var(--sub); line-height:1.55; word-break:break-word; }
.comment-form{ display:flex; gap:8px; align-items:flex-end; }
.comment-form img{ width:32px; height:32px; border-radius:50%; object-fit:cover; flex-shrink:0; border:1.5px solid var(--accent); }
.comment-form textarea{
    flex:1; min-width:0; min-height:var(--ctl-h); max-height:100px; padding:8px 12px; border-radius:var(--r);
    border:1px solid var(--border); background:var(--surface);
    color:var(--ui-text); font-size:.76rem; outline:none; resize:none; line-height:1.4; transition:.2s;
}
.comment-form textarea:focus{ border-color:var(--accent-focus); background:rgba(var(--fg),.07); box-shadow:0 0 0 3px var(--accent-ring); }
.comment-form textarea::placeholder{ color:var(--muted); }
.comment-form button{
    width:var(--ctl-h); height:var(--ctl-h); border-radius:var(--r); border:none; flex-shrink:0;
    background:var(--accent); color:var(--on-accent); font-size:1rem;
    display:flex; align-items:center; justify-content:center; transition:background .2s;
}
.comment-form button:hover{ background:var(--accent-hover); }

.related-list{ display:flex; flex-direction:column; margin:0 calc(var(--pad) * -1); }
.related-row{ display:flex; align-items:center; gap:12px; padding:11px var(--pad); border-bottom:1px solid var(--line); cursor:pointer; transition:background .18s; }
.related-row:hover{ background:rgba(var(--fg),.04); }
.related-row-thumb{ width:50px; height:50px; border-radius:var(--act-r); overflow:hidden; flex-shrink:0; background:var(--surface); }
.related-row-thumb img{ width:100%; height:100%; object-fit:cover; }
.related-row-emoji{ width:100%; height:100%; display:flex; align-items:center; justify-content:center; font-size:1.2rem; }
.related-row-info{ flex:1; min-width:0; }
.related-row-title{ font-size:.76rem; font-weight:600; color:var(--ui-text); line-height:1.3; }
.related-row-meta{ font-size:.6rem; color:var(--muted); margin-top:2px; }
.related-row-arrow{ font-size:1rem; color:var(--muted); flex-shrink:0; }

.host-section{ margin-top:4px; padding-top:18px; border-top:1px solid var(--line); }
.host-section-title{ font-size:.6rem; font-weight:700; text-transform:uppercase; letter-spacing:1.5px; color:var(--muted); margin-bottom:12px; }
.host-row{ display:flex; align-items:center; gap:12px; padding:12px 13px; background:var(--surface); border:1px solid var(--line); border-radius:var(--r); cursor:pointer; transition:background .2s; }
.host-row:hover{ background:rgba(var(--fg),.08); }
.host-row-avatar{ width:44px; height:44px; border-radius:50%; object-fit:cover; border:2px solid var(--accent); flex-shrink:0; }
.host-row-info{ flex:1; min-width:0; }
.host-row-name{ font-size:.8rem; font-weight:700; color:var(--ui-text); }
.host-row-role{ font-size:.58rem; color:var(--muted); text-transform:uppercase; letter-spacing:.8px; margin-top:2px; }
.host-row-link{ width:var(--act-h); height:var(--act-h); border-radius:var(--act-r); background:rgba(var(--fg),.1); display:flex; align-items:center; justify-content:center; color:var(--ui-text); font-size:1.1rem; flex-shrink:0; transition:background .2s; }
.host-row-link:hover{ background:rgba(var(--fg),.2); }

.detail-footer{ margin-top:32px; padding-top:22px; border-top:1px solid var(--line); }
.detail-footer-brand{ display:flex; align-items:center; gap:7px; font-weight:700; font-size:.9rem; color:var(--ui-text); margin-bottom:8px; }
.detail-footer-brand i{ color:var(--accent); }
.detail-footer-desc{ font-size:.68rem; color:var(--muted); line-height:1.7; margin-bottom:18px; }
.detail-footer-cols{ display:flex; gap:26px; flex-wrap:wrap; margin-bottom:18px; }
.detail-footer-col-title{ font-size:.56rem; font-weight:700; letter-spacing:1.2px; text-transform:uppercase; color:var(--muted-2); margin-bottom:9px; }
.detail-footer-link{ display:block; font-size:.68rem; color:var(--muted); padding:3px 0; cursor:pointer; transition:color .2s; }
.detail-footer-link:hover{ color:var(--accent); }
.detail-footer-bottom{ display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; padding-top:16px; border-top:1px solid var(--line); font-size:.6rem; color:var(--muted-2); }
.detail-footer-social{ display:flex; gap:7px; }
.detail-footer-social a{ width:var(--act-h); height:var(--act-h); border-radius:var(--act-r); background:rgba(var(--fg),.1); display:flex; align-items:center; justify-content:center; color:var(--ui-text); transition:background .2s, color .2s; }
.detail-footer-social a:hover{ background:rgba(var(--fg),.2); color:var(--accent); }

/* toast */
.toast-container{ position:fixed; bottom:24px; left:50%; transform:translateX(-50%); z-index:30000; display:flex; flex-direction:column; gap:8px; align-items:center; pointer-events:none; }
.toast{
    display:flex; align-items:center; gap:9px; padding:9px 18px; border-radius:var(--r);
    background:var(--pop-bg); border:1px solid var(--accent-border);
    font-size:.74rem; font-weight:500; color:var(--text); box-shadow:0 8px 24px rgba(0,0,0,.6);
    animation:toastIn .3s ease forwards; white-space:nowrap; max-width:calc(100vw - 32px);
}
.toast i{ color:var(--accent); }
.toast.success i{ color:var(--accent-text); }
@keyframes toastIn{ from{ opacity:0; transform:translateY(12px);} to{ opacity:1; transform:translateY(0);} }

.entry-skeleton{ display:none; flex-direction:column; width:100%; }
.entry-skeleton.show{ display:flex; }
.es-hero{ width:100%; height:clamp(190px, 46vw, 260px); flex-shrink:0; }
.es-body{ width:100%; padding:18px var(--pad) 24px; }
.es-line{ border-radius:6px; }
.entry-content{ display:flex; flex-direction:column; width:100%; }
.entry-content.hidden{ display:none; }

/* ══ PAGE SKELETON ══ */
.sk-shimmer{ position:relative; overflow:hidden; background:rgba(var(--fg),.06); border-radius:6px; }
.sk-shimmer::after{
    content:''; position:absolute; inset:0;
    background:linear-gradient(90deg, transparent, rgba(var(--fg),.09), transparent);
    transform:translateX(-100%); animation:sk-shine 1.5s infinite;
}
@keyframes sk-shine{ 100%{ transform:translateX(100%); } }
.sk-row{ display:flex; align-items:flex-start; gap:12px; padding:14px var(--pad); border-bottom:1px solid var(--line); }
.sk-row-thumb{ width:clamp(74px, 22vw, 96px); height:clamp(74px, 22vw, 96px); border-radius:var(--r); flex-shrink:0; }
.sk-row-lines{ flex:1; display:flex; flex-direction:column; gap:7px; padding-top:2px; }
.sk-row{ opacity:1; }

/* create / edit modal skeleton (shown once per page load, the first time the modal opens) */
.cm-skel{ display:none; }
.cm-skel.show{ display:block; }
.cm-real.hide{ display:none; }
.cm-skel .sk-shimmer{ margin-bottom:12px; }

/* ══ RESPONSIVE ══ */
@media (max-width:490px){
    .field-row{ gap:9px; }
    .gc-input, .gc-textarea, .gc-select{ font-size:.7rem; padding:0 10px; }
    .gc-textarea{ padding:9px 10px; }
    .media-uploader{ padding:18px 12px; }
    .mu-title{ font-size:.7rem; } .mu-sub{ font-size:.6rem; }
    .status-chip{ font-size:.62rem; padding:0 10px; }
    .gc-btn{ font-size:.72rem; }
    .detail-icon-btn{ width:38px; height:38px; }
    .stickybar-title{ font-size:.78rem; left:48px; right:130px; }
    .detail-info-cell{ padding:10px 11px; }
    .detail-footer-cols{ gap:18px; }
    .row-actions{ top:10px; }
}
@media (max-width:380px){
    .detail-tab{ font-size:.64rem; padding:10px 11px; }
    .entry-info{ padding-right:26px; }
    .toast{ font-size:.7rem; padding:8px 14px; }
}

/* ═══════════════════════════════════════════════════════════════════
   MOBILE 1.1× SCALE — responsive view only (790px and below).
   (Topbar title + right-side icons are handled in the TOPBAR block at the very bottom.)
   ═══════════════════════════════════════════════════════════════════ */
@media (max-width:790px){
    :root{
        --pad:    clamp(10px, 3.3vw, 17.6px);
        --ctl-h:  clamp(33px, 8.8vw, 39.6px);
        --r:      5.5px;
        --act-h:  clamp(27.5px, 7.9vw, 33px);
        --act-r:  4.4px;
        --chip-h: clamp(24.2px, 6.6vw, 28.6px);
    }

    .ac-topbar{ padding:0 11px; }
    .ac-x{ width:37px; height:37px; }
    .ac-x svg{ width:24px; height:24px; }
    .ac-x i{ font-size:31px; }
    .ac-title:not(.left){ font-size:1.01rem; }

    .toolbar{ padding:11px var(--pad); }
    .filter-chips{ gap:6.6px; }
    .chip{ font-size:.77rem; padding:0 13px; gap:5.5px; }
    .results-bar{ font-size:.77rem; padding:13px var(--pad) 6px; }

    .sort-opt{ font-size:.9rem; min-height:53px; padding:0 22px; gap:11px; }
    .sort-opt i{ font-size:1.05rem; width:18px; }

    .entry-row{ gap:clamp(12px, 3.3vw, 15.4px); padding:clamp(13px, 3.75vw, 16.5px) var(--pad); }
    .entry-thumb, .sk-row-thumb{ width:clamp(81px, 24.2vw, 106px); height:clamp(81px, 24.2vw, 106px); }
    .entry-thumb .thumb-no-img{ font-size:2rem; }
    .thumb-media-tag{ font-size:.55rem; padding:2px 6.6px; }
    .entry-info{ padding-right:33px; }
    .entry-status-row{ gap:7.7px; margin-bottom:5.5px; }
    .status-pill{ font-size:.64rem; padding:2px 8.8px; }
    .entry-cat-tag{ font-size:.64rem; }
    .entry-title{ font-size:clamp(.86rem, 2.75vw, .99rem); }
    .entry-summary{ font-size:clamp(.7rem, 2.2vw, .79rem); }
    .entry-meta{ font-size:clamp(.64rem, 2.1vw, .73rem); gap:11px; }
    .row-actions{ top:12px; }
    .row-icon-btn{ font-size:.94rem; }

    .es-title{ font-size:clamp(1.05rem, 3.75vw, 1.21rem); }
    .es-text{ font-size:clamp(.73rem, 2.3vw, .84rem); max-width:374px; }
    .es-tips{ max-width:396px; gap:8px; }
    .es-tip{ padding:11px 13px; }
    .es-tip i{ font-size:1.1rem; }
    .es-tip span{ font-size:clamp(.68rem, 2.2vw, .77rem); }
    .es-btn{ font-size:.8rem; min-width:165px; padding:0 24px; }

    .search-input-wrap{ padding:0 13px; gap:9px; }
    .search-input-wrap i{ font-size:1.05rem; }
    .search-input-wrap svg.si-search{ width:1.05rem; height:1.05rem; }
    .search-input-wrap input{ font-size:.84rem; }
    .search-clear-x{ font-size:1.15rem; }
    .search-recents-label{ font-size:.66rem; }
    .search-empty-msg{ font-size:.86rem; }
    .search-empty-msg i{ font-size:2.3rem; }

    .field-label{ font-size:.66rem; }
    .field-label i{ font-size:.94rem; }
    .field-hint{ font-size:.7rem; }
    .media-uploader{ padding:26px 17px; }
    .mu-icon{ font-size:2rem; }
    .mu-title{ font-size:.84rem; }
    .mu-sub{ font-size:.7rem; }
    .mu-remove{ width:31px; height:31px; font-size:1rem; }
    .small-uploader{ padding:18px 13px; }
    .small-uploader .mu-icon{ font-size:1.54rem; }
    .gc-input, .gc-textarea, .gc-select{ font-size:.84rem; padding:0 13px; }
    .gc-textarea{ padding:11px 13px; }
    .status-chip{ font-size:.77rem; padding:0 14px; }
    .dup-title{ font-size:.81rem; }
    .dup-text, .dup-link{ font-size:.75rem; }
    .gc-btn{ font-size:.86rem; }

    .detail-icon-btn{ width:46px; height:46px; font-size:1.76rem; }
    .detail-icon-btn svg{ width:24px; height:24px; }
    .stickybar-title{ font-size:.95rem; left:56px; right:150px; }
    .detail-hero, .es-hero{ height:clamp(209px, 50.6vw, 286px); }
    .hero-status-tag{ font-size:.66rem; padding:3px 10px; }
    .detail-cat-eyebrow{ font-size:.66rem; }
    .detail-title{ font-size:clamp(1.16rem, 4.6vw, 1.43rem); }
    .detail-author-row img{ width:29px; height:29px; }
    .detail-author-row span{ font-size:.79rem; }
    .follow-btn{ font-size:.74rem; padding:0 14px; }
    .detail-meta-list{ padding:13px 15px; gap:10px; }
    .meta-row{ font-size:.81rem; }
    .meta-row i{ font-size:1rem; width:18px; }
    .detail-info-cell{ padding:12px 14px; }
    .detail-info-label{ font-size:.6rem; }
    .detail-info-val{ font-size:.84rem; }
    .tr-bar{ font-size:.77rem; }
    .tr-bar button{ font-size:.77rem; }
    .detail-tab{ font-size:.77rem; padding:clamp(11px, 3.1vw, 14px) 15px; }
    .detail-section-label{ font-size:.66rem; }
    .detail-summary{ font-size:.9rem; }
    .detail-tag{ font-size:.7rem; padding:4px 12px; }
    .detail-segment-label{ font-size:.66rem; }
    .detail-segment-text{ font-size:.86rem; }
    .detail-empty-article{ font-size:.84rem; }
    .eh-avatar{ width:33px; height:33px; }
    .eh-user{ font-size:.79rem; }
    .eh-summary{ font-size:.73rem; }
    .eh-time{ font-size:.64rem; }
    .empty-tab-msg{ font-size:.81rem; }
    .comment-avatar, .comment-form img{ width:35px; height:35px; }
    .comment-username{ font-size:.79rem; }
    .comment-time-inline{ font-size:.64rem; }
    .comment-text{ font-size:.81rem; }
    .comment-form textarea{ font-size:.84rem; }
    .comment-form button{ font-size:1.1rem; }
    .related-row-thumb{ width:55px; height:55px; }
    .related-row-title{ font-size:.84rem; }
    .related-row-meta{ font-size:.66rem; }
    .host-row-avatar{ width:48px; height:48px; }
    .host-row-name{ font-size:.88rem; }
    .host-row-role{ font-size:.64rem; }
    .host-row-link{ font-size:1.2rem; }
    .host-section-title{ font-size:.66rem; }
    .detail-footer-brand{ font-size:.99rem; }
    .detail-footer-desc{ font-size:.75rem; }
    .detail-footer-col-title{ font-size:.62rem; }
    .detail-footer-link{ font-size:.75rem; }
    .detail-footer-bottom{ font-size:.66rem; }

    .toast{ font-size:.81rem; padding:10px 20px; }
}
@media (max-width:490px){
    .ac-x{ width:33px; height:33px; }
    .ac-title:not(.left){ font-size:.92rem; }
    .chip{ font-size:.7rem; padding:0 11px; }
    .sort-opt{ min-height:48px; font-size:.86rem; padding:0 18px; }
    .gc-input, .gc-textarea, .gc-select{ font-size:.77rem; padding:0 11px; }
    .gc-textarea{ padding:10px 11px; }
    .mu-title{ font-size:.77rem; }
    .mu-sub{ font-size:.66rem; }
    .status-chip{ font-size:.68rem; padding:0 11px; }
    .gc-btn{ font-size:.79rem; }
    .es-btn{ font-size:.68rem; }
    .detail-icon-btn{ width:42px; height:42px; }
    .stickybar-title{ font-size:.86rem; left:52px; right:138px; }
    .row-actions{ top:11px; }
}
@media (max-width:380px){
    .ac-title:not(.left){ font-size:.88rem; }
    .detail-tab{ font-size:.7rem; padding:11px 12px; }
    .entry-info{ padding-right:29px; }
    .toast{ font-size:.77rem; padding:9px 15px; }
}

/* ═══════════════════════════════════════════════════════════════════
   TOPBAR — "Memepedia" title + right-side icons (kept near the end so nothing overrides it).
   NO clamp() here: exact sizes at each width.

   Title:                      size     letter-spacing
     over 790px               1.4rem    3px
     790px and below          1.3rem    3px
     490px and below          1.2rem    2px
     390px and below          1.05rem   1.5px
     330px and below          0.95rem   1px
   ═══════════════════════════════════════════════════════════════════ */
.ac-title.left{ font-size:1.4rem; letter-spacing:3px; }

@media (max-width:790px){
    :root{ --topbar-h:48px; }
    .ac-title.left{ font-size:1.3rem; letter-spacing:3px; }
    .tb-icon-btn{ width:44px; height:44px; font-size:1.95rem; }
    .tb-add-btn{ font-size:2.3rem; }
}
@media (max-width:490px){
    :root{ --topbar-h:46px; }
    .ac-title.left{ font-size:1.2rem; letter-spacing:2px; }
    .tb-icon-btn{ width:42px; height:42px; font-size:1.85rem; }
    .tb-add-btn{ font-size:2.2rem; }
}
@media (max-width:390px){
    :root{ --topbar-h:44px; }
    .ac-title.left{ font-size:1.05rem; letter-spacing:1.5px; }
    .tb-icon-btn{ width:38px; height:38px; font-size:1.7rem; }
    .tb-add-btn{ font-size:2.05rem; }
}
@media (max-width:330px){
    :root{ --topbar-h:42px; }
    .ac-title.left{ font-size:.95rem; letter-spacing:1px; }
    .tb-icon-btn{ width:34px; height:34px; font-size:1.55rem; }
    .tb-add-btn{ font-size:1.85rem; }
}

/* ═══════════ LIGHT MODE — <html data-theme="light"> (dark mode is never touched) ═══════════
   Same system as the Community page. The theme-specific light values
   (accent text colour, scrollbar, warning colours) live in the THEME block at the top. */
:root[data-theme="light"]{
    color-scheme:light;
    --fg:6,7,26;
    --ui-text:#0b0d24;
    --text:#0b0d24;
    --solid:#ffffff;
    --surface:rgba(6,7,26,.05);
    --surface-2:rgba(6,7,26,.08);
    --border:rgba(6,7,26,.2);
    --line:rgba(6,7,26,.1);
    --muted:rgba(6,7,26,.52);
    --muted-2:rgba(6,7,26,.38);
    --sub:rgba(6,7,26,.66);
    --bg:linear-gradient(-45deg,#ffffff,#f4f5fb,#f4f5fb);
    --danger:#d92b2b;
    --danger-soft:rgba(217,43,43,.12);
    --ph:#eceef5; --ph2:#ffffff;
    --ph-grad:linear-gradient(135deg,#f1f3fa,#e3e6f1);
    --pop-bg:#ffffff;
}
/* dropdown arrow for the category select (dark stroke on light) */
:root[data-theme="light"] .gc-select{
    color-scheme:light;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='rgba(6,7,26,0.55)' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
}
[data-theme="light"] .toast,
[data-theme="light"] .sort-menu{ box-shadow:0 8px 24px rgba(0,0,0,.18); }
[data-theme="light"] .sort-backdrop.open{ background:rgba(6,7,26,.35); }
/* skeleton shimmer sweep must be visible on white */
[data-theme="light"] .sk-shimmer::after{ background:linear-gradient(90deg, transparent, rgba(255,255,255,.7), transparent); }

/* ══════════ FULL-SCREEN SCREENS — Report · Entry menu · Translate · QR code · Filters · Share ══════════
   Uses the page theme, so it is red or blue (from group page) and dark or light automatically.
   Full-screen centered lane · grey side lines · horizontal-line rows · slim scrollbar on desktop only. */
.mpr-screen{
    --rp-pad:clamp(9.9px,3.3vw,17.6px); --rp-act-h:clamp(27.5px,7.92vw,33px);
    --rp-r:5.5px; --rp-fs:.88rem; --rp-tb-h:59.4px;
    display:none; position:fixed; inset:0; z-index:40000;
    background:var(--bg); background-attachment:fixed;
    justify-content:center; overflow:hidden;
    font-size:var(--rp-fs); color:var(--ui-text);
    opacity:0; transform:translateY(19.8px);
    transition:opacity .25s ease, transform .28s cubic-bezier(.22,1,.36,1);
}
.mpr-screen.mpr-open{ opacity:1; transform:translateY(0); }
@media (max-width:490px){ .mpr-screen{ --rp-fs:.81rem; --rp-tb-h:52.8px; } }
@media (max-width:390px){ .mpr-screen{ --rp-fs:.77rem; --rp-tb-h:48.4px; } }
.mpr-lane{ width:100%; max-width:580px; height:100%; display:flex; flex-direction:column; min-height:0; }
@media (min-width:801px){ .mpr-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); } }
@media (max-width:800px){ .mpr-lane{ max-width:100%; } }

.mpr-topbar{ flex-shrink:0; display:flex; align-items:center; gap:8.8px; min-height:var(--rp-tb-h); padding:env(safe-area-inset-top) 11px 0 8.8px; }
.mpr-title{ flex:1; min-width:0; font-size:1.01rem; font-weight:700; color:var(--ui-text); line-height:1.2; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.mpr-x{ width:37.4px; height:37.4px; background:none; border:none; color:var(--ui-text); display:flex; align-items:center; justify-content:center; opacity:.9; padding:0; transition:.2s; flex-shrink:0; }
.mpr-x:hover{ color:var(--accent); opacity:1; }
.mpr-x svg{ width:24.2px; height:24.2px; display:block; }
@media (max-width:490px){ .mpr-title{ font-size:.92rem; } .mpr-x{ width:33px; height:33px; } }
@media (max-width:390px){ .mpr-title{ font-size:.88rem; } }

.mpr-body{ flex:1; min-height:0; overflow-y:auto; overflow-x:hidden; overscroll-behavior:contain; -webkit-overflow-scrolling:touch; padding:0 0 19.8px; }
/* scrollbar: gone on phones / tablets / narrow windows, slim accent scrollbar on desktop only */
.mpr-screen, .mpr-screen *{ scrollbar-width:none; -ms-overflow-style:none; }
.mpr-screen ::-webkit-scrollbar{ display:none; width:0; height:0; background:transparent; }
@media (min-width:801px){
    .mpr-body{ scrollbar-width:thin; scrollbar-color:var(--accent) transparent; }
    .mpr-body::-webkit-scrollbar{ display:block; width:6px; }
    .mpr-body::-webkit-scrollbar-track{ background:transparent; }
    .mpr-body::-webkit-scrollbar-thumb{ background:var(--accent); border-radius:99px; }
}

.mpr-target{ display:flex; align-items:center; gap:9px; padding:11px var(--rp-pad); border-top:1px solid var(--line); border-bottom:1px solid var(--line); background:var(--surface); }
.mpr-target i{ font-size:17px; color:var(--accent); flex-shrink:0; }
.mpr-target span{ min-width:0; font-size:.8rem; font-weight:600; color:var(--ui-text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.mpr-section-label{ display:flex; align-items:center; gap:6.6px; padding:13.2px var(--rp-pad) 11px; font-size:.7rem; font-weight:600; letter-spacing:.6px; text-transform:uppercase; color:var(--sub); border-bottom:1px solid var(--line); }

.mpr-row{ display:flex; align-items:center; gap:13.2px; padding:14.3px var(--rp-pad); border-bottom:1px solid var(--line); cursor:pointer; transition:background .2s; -webkit-tap-highlight-color:transparent; }
.mpr-row:hover{ background:var(--accent-soft); }
.mpr-row.selected{ background:var(--accent-soft); }
.mpr-row-icon{ font-size:19.8px; color:var(--accent); flex-shrink:0; width:26.4px; text-align:center; }
.mpr-row-text{ flex:1; min-width:0; font-size:.88rem; font-weight:500; color:var(--ui-text); opacity:.88; }
.mpr-row-text small{ display:block; font-size:.7rem; font-weight:400; color:var(--muted); margin-top:2px; }
.mpr-row.selected .mpr-row-text{ font-weight:600; opacity:1; }
.mpr-row.danger .mpr-row-icon, .mpr-row.danger .mpr-row-text{ color:var(--danger); opacity:1; }
.mpr-chev{ font-size:18px; color:var(--muted); flex-shrink:0; }
.mpr-val{ font-size:.72rem; font-weight:600; color:var(--accent-text); flex-shrink:0; max-width:42%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.mpr-sub{ font-size:.7rem; font-weight:500; color:var(--muted); flex-shrink:0; max-width:42%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.mpr-check{ width:22px; height:22px; border-radius:5.5px; border:1.5px solid rgba(var(--fg),.3); display:flex; align-items:center; justify-content:center; font-size:13.2px; font-weight:800; color:transparent; flex-shrink:0; transition:.2s; }
.mpr-row.selected .mpr-check{ background:var(--accent); border-color:var(--accent); color:var(--on-accent); }
@media (max-width:490px){
    .mpr-row{ gap:11px; padding:13.2px var(--rp-pad); }
    .mpr-row-icon{ font-size:17.6px; width:24.2px; }
    .mpr-row-text{ font-size:.79rem; }
    .mpr-row-text small{ font-size:.64rem; }
    .mpr-check{ width:19.8px; height:19.8px; font-size:12.1px; }
}

/* language search */
.mpl-search{ padding:11px var(--rp-pad); border-bottom:1px solid var(--line); }
.mpl-search-in{ position:relative; }
.mpl-search-in i{ position:absolute; left:11px; top:50%; transform:translateY(-50%); font-size:15px; color:var(--muted); pointer-events:none; }
.mpl-search-in input{
    width:100%; height:38px; padding:0 12px 0 34px; border-radius:var(--rp-r);
    border:1px solid var(--border); background:var(--surface); color:var(--ui-text);
    font-size:.84rem; outline:none; -webkit-appearance:none; appearance:none; transition:.2s;
}
.mpl-search-in input::placeholder{ color:var(--muted); }
.mpl-search-in input:focus{ border-color:var(--accent-focus); box-shadow:0 0 0 3px var(--accent-ring); }
.mpl-empty{ padding:40px 20px; text-align:center; font-size:.78rem; color:var(--muted); }

/* QR code */
.mpq-box{ display:flex; flex-direction:column; align-items:center; padding:24px var(--rp-pad) 8px; }
.mpq-box canvas{ width:min(260px,70vw); height:min(260px,70vw); background:#fff; border-radius:6px; }
.mpq-name{ margin-top:14px; font-size:.9rem; font-weight:700; text-align:center; max-width:100%; word-break:break-word; }
.mpq-url{ margin-top:4px; font-size:.64rem; color:var(--muted); text-align:center; word-break:break-all; max-width:100%; }
.mpq-err{ font-size:.76rem; color:var(--muted); padding:30px 0; text-align:center; }
.mpq-btns{ display:flex; gap:8.8px; width:100%; margin-top:18px; }
.mpq-btns .mpr-btn{ order:0; flex:1; }
.mpq-hint{ margin-top:14px; font-size:.7rem; line-height:1.6; color:var(--muted); text-align:center; max-width:340px; }

.mpr-other-wrap{ padding:13.2px var(--rp-pad) 6.6px; display:none; }
.mpr-other-textarea{
    width:100%; min-height:105.6px; padding:9.9px 12.1px; border-radius:var(--rp-r);
    border:1px solid var(--border); background:var(--surface); color:var(--ui-text);
    font-size:.9rem; line-height:1.6; resize:none; transition:.2s; -webkit-appearance:none; appearance:none; overflow-y:auto; outline:none;
}
.mpr-other-textarea::placeholder{ color:var(--muted); }
.mpr-other-textarea:focus{ border-color:var(--accent-focus); box-shadow:0 0 0 3px var(--accent-ring); }
.mpr-char{ text-align:right; font-size:.68rem; color:var(--muted); margin-top:5.5px; }
@media (max-width:490px){ .mpr-other-textarea{ font-size:.84rem; } }

.mpr-footer{ flex-shrink:0; display:flex; gap:8.8px; padding:8.8px var(--rp-pad) calc(11px + env(safe-area-inset-bottom)); border-top:1px solid var(--line); }
.mpr-btn{
    display:flex; align-items:center; justify-content:center; gap:6.6px; min-width:0;
    height:var(--rp-act-h); padding:0 15.4px; border:none; border-radius:var(--rp-r);
    font-size:.87rem; font-weight:700; line-height:1; white-space:nowrap;
    transition:background .2s ease, transform .15s ease, box-shadow .2s ease;
}
.mpr-btn-submit{ flex:1.4; order:2; background:var(--accent); color:var(--on-accent); }
.mpr-btn-submit:hover, .mpr-btn-done:hover{ background:var(--accent-hover); box-shadow:0 4px 16px var(--accent-glow); }
.mpr-btn-submit:active, .mpr-btn-done:active{ transform:scale(.98); }
.mpr-btn-submit:disabled{ opacity:.5; cursor:not-allowed; box-shadow:none; pointer-events:none; }
.mpr-btn-cancel{ flex:1; order:1; background:rgba(var(--fg),.1); color:var(--ui-text); backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px); }
.mpr-btn-cancel:hover{ background:rgba(var(--fg),.2); }
.mpr-btn-done{ background:var(--accent); color:var(--on-accent); padding:0 44px; }
@media (max-width:490px){ .mpr-btn{ font-size:.75rem; padding:0 11px; } .mpr-btn-done{ padding:0 35.2px; } }

.mpr-success{ flex:1; min-height:0; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:33px 26.4px 66px; text-align:center; }
.mpr-success-icon{ width:70.4px; height:70.4px; border-radius:50%; background:rgba(77,222,138,.12); border:1px solid rgba(77,222,138,.4); display:flex; align-items:center; justify-content:center; margin:0 auto 17.6px; font-size:30.8px; color:#4dde8a; }
[data-theme="light"] .mpr-success-icon{ background:rgba(30,160,90,.1); border-color:rgba(30,160,90,.4); color:#1ea05a; }
.mpr-success-title{ font-size:1.04rem; font-weight:700; color:var(--ui-text); margin-bottom:8.8px; }
.mpr-success-desc{ font-size:.81rem; color:var(--sub); line-height:1.7; max-width:319px; margin:0 auto 24.2px; }
@media (max-width:490px){ .mpr-success-icon{ width:61.6px; height:61.6px; font-size:27.5px; } .mpr-success-title{ font-size:.95rem; } .mpr-success-desc{ font-size:.75rem; } }

.mpr-toast{
    position:fixed; bottom:calc(33px + env(safe-area-inset-bottom)); left:50%; transform:translateX(-50%) translateY(11px);
    background:var(--pop-bg); border:1px solid var(--accent-border); color:var(--text);
    padding:8.8px 17.6px; border-radius:5.5px; font-size:.79rem; font-weight:500; z-index:50000; pointer-events:none;
    box-shadow:0 8px 28px rgba(0,0,0,.45); white-space:nowrap; max-width:92vw; overflow:hidden; text-overflow:ellipsis; opacity:0;
    transition:opacity .22s ease, transform .22s ease;
}
.mpr-toast.show{ opacity:1; transform:translateX(-50%) translateY(0); }
@keyframes mprShake{ 0%,100%{transform:translateX(0);} 20%{transform:translateX(-6px);} 40%{transform:translateX(6px);} 60%{transform:translateX(-4px);} 80%{transform:translateX(4px);} }
@media (prefers-reduced-motion:reduce){ .mpr-screen{ transition-duration:.01ms !important; } }

/* ═══════════════════════════════════════════════════════════════════
   FEATURES ADDED EARLIER
   Filters button + view toggle · Timeline · Gallery · Edit diffs + upvotes · Filters screen · Share screen
   ═══════════════════════════════════════════════════════════════════ */
.rb-tools{ display:flex; align-items:center; gap:6px; flex-shrink:0; }
.rb-btn{
    position:relative; display:flex; align-items:center; gap:5px; flex-shrink:0;
    height:var(--chip-h); padding:0 11px; border:none; border-radius:var(--act-r);
    background:rgba(var(--fg),.1); backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    color:rgba(var(--fg),.8); font-size:.68rem; font-weight:600; white-space:nowrap;
    transition:background .2s, color .2s, transform .15s;
}
.rb-btn i{ font-size:.95rem; }
.rb-btn:hover{ background:rgba(var(--fg),.2); color:var(--ui-text); }
.rb-btn:active{ transform:scale(.96); }
.rb-btn.has-badge{ background:var(--accent-soft); color:var(--accent-text); }
.rb-badge{
    display:none; min-width:16px; height:16px; padding:0 4px; border-radius:8px;
    background:var(--accent); color:var(--on-accent); font-size:.58rem; font-weight:700;
    align-items:center; justify-content:center;
}
.rb-btn.has-badge .rb-badge{ display:inline-flex; }
.view-toggle{ display:flex; border-radius:var(--act-r); overflow:hidden; background:rgba(var(--fg),.1); flex-shrink:0; }
.view-toggle button{
    height:var(--chip-h); width:calc(var(--chip-h) * 1.3); border:none; background:none;
    color:rgba(var(--fg),.7); font-size:1rem; display:flex; align-items:center; justify-content:center; transition:background .2s, color .2s;
}
.view-toggle button:hover{ color:var(--ui-text); }
.view-toggle button.active{ background:var(--accent); color:var(--on-accent); }
@media (max-width:790px){ .rb-btn{ font-size:.74rem; padding:0 12px; } }
@media (max-width:380px){ .rb-lbl{ display:none; } .rb-btn{ padding:0 9px; } }

.adv-summary{ display:none; flex-wrap:wrap; align-items:center; gap:6px; padding:2px var(--pad) 8px; }
.adv-summary.show{ display:flex; }
.adv-chip{
    display:inline-flex; align-items:center; gap:3px; height:var(--chip-h); padding:0 3px 0 10px; border-radius:var(--act-r);
    background:var(--accent-soft); border:1px solid var(--accent-border); color:var(--accent-text);
    font-size:.64rem; font-weight:600; white-space:nowrap;
}
.adv-chip button{ background:none; border:none; color:inherit; display:flex; align-items:center; font-size:.95rem; padding:0 5px; height:100%; }
.adv-clear{ background:none; border:none; color:var(--muted); font-size:.64rem; font-weight:700; text-decoration:underline; padding:0 4px; }
.adv-clear:hover{ color:var(--ui-text); }
@media (max-width:790px){ .adv-chip, .adv-clear{ font-size:.7rem; } }

/* ── Timeline ── */
.tl{ padding:8px 0 14px; }
.tl-group{ position:relative; margin-left:calc(var(--pad) + 6px); border-left:2px solid var(--accent-border); }
.tl-group.unk{ border-left-style:dashed; border-left-color:var(--line); }
.tl-year{ position:relative; display:flex; align-items:baseline; gap:10px; padding:16px var(--pad) 6px calc(var(--pad) - 2px); }
.tl-year::before{
    content:''; position:absolute; left:-8px; top:50%; width:14px; height:14px; margin-top:-2px; border-radius:50%;
    background:var(--accent); box-shadow:0 0 0 4px var(--accent-soft); transform:translateY(-50%);
}
.tl-group.unk .tl-year::before{ background:var(--muted-2); box-shadow:0 0 0 4px rgba(var(--fg),.06); }
.tl-year b{ font-size:1.25rem; font-weight:700; color:var(--ui-text); letter-spacing:-.3px; padding-left:8px; }
.tl-group.unk .tl-year b{ font-size:.95rem; color:var(--sub); }
.tl-year small{ font-size:.62rem; font-weight:600; color:var(--muted); padding:1px 8px; border-radius:4px; background:rgba(var(--fg),.07); }
.tl-items .entry-row:last-child{ border-bottom:none; }
.entries-list.is-timeline{ overflow:hidden; }
@media (max-width:790px){ .tl-year b{ font-size:1.38rem; } .tl-year small{ font-size:.68rem; } }

/* ── Gallery carousel (entry detail) ── */
.gal{ position:relative; }
.gal-track{
    display:flex; gap:10px; overflow-x:auto; scroll-snap-type:x mandatory; -webkit-overflow-scrolling:touch;
    scrollbar-width:none; -ms-overflow-style:none;
    margin:0 calc(var(--pad) * -1); padding:0 var(--pad); scroll-padding:0 var(--pad);
}
.gal-track::-webkit-scrollbar{ display:none; }
.gal-item{
    flex:0 0 82%; scroll-snap-align:start; position:relative; overflow:hidden;
    border-radius:var(--r); border:1px solid var(--line); background:var(--surface);
}
.gal.single .gal-item{ flex-basis:100%; }
.gal-media{ aspect-ratio:4/3; background:var(--ph); display:flex; align-items:center; justify-content:center; }
.gal-media img, .gal-media video{ width:100%; height:100%; object-fit:contain; display:block; }
.gal-tag{
    position:absolute; top:8px; left:8px; z-index:2; padding:2px 7px; border-radius:3px;
    background:rgba(4,6,18,.75); color:#fff; font-size:.52rem; font-weight:700; letter-spacing:.4px;
}
.gal-cap{ padding:9px 12px; font-size:.7rem; line-height:1.55; color:var(--sub); border-top:1px solid var(--line); }
.gal-nav{
    display:none; position:absolute; top:calc(50% - 22px); z-index:3; width:34px; height:34px; border-radius:50%;
    border:none; background:rgba(4,6,18,.7); color:#fff; font-size:1.25rem; align-items:center; justify-content:center;
    transition:background .2s, color .2s;
}
.gal-nav:hover{ background:var(--accent); color:var(--on-accent); }
.gal-nav.prev{ left:6px; } .gal-nav.next{ right:6px; }
@media (hover:hover) and (pointer:fine){ .gal:not(.single) .gal-nav{ display:flex; } }
.gal-dots{ display:flex; justify-content:center; gap:6px; margin-top:11px; }
.gal-dot{ width:6px; height:6px; border-radius:50%; background:rgba(var(--fg),.25); transition:width .2s, background .2s; cursor:pointer; }
.gal-dot.on{ width:18px; border-radius:4px; background:var(--accent); }
@media (max-width:790px){ .gal-cap{ font-size:.77rem; } }

/* ── Gallery editor (create / edit modal) ── */
.gal-ed{ display:grid; grid-template-columns:repeat(2,1fr); gap:10px; margin-top:12px; }
.gal-ed:empty{ display:none; }
.gal-ed-cell{ position:relative; border:1px solid var(--line); border-radius:var(--r); overflow:hidden; background:var(--surface); }
.gal-ed-media{ aspect-ratio:4/3; background:var(--ph); display:flex; align-items:center; justify-content:center; color:var(--muted); font-size:1.4rem; }
.gal-ed-media img, .gal-ed-media video{ width:100%; height:100%; object-fit:cover; }
.gal-ed-cap{ padding:7px; }
.gal-ed-cap .gc-input{ height:30px; font-size:.68rem; padding:0 9px; }
.gal-ed-tag{ position:absolute; top:7px; left:7px; z-index:2; padding:2px 7px; border-radius:3px; background:rgba(4,6,18,.75); color:#fff; font-size:.5rem; font-weight:700; letter-spacing:.4px; }
.gal-ed-count{ font-size:.62rem; color:var(--muted); margin-top:8px; }
@media (max-width:790px){ .gal-ed-cap .gc-input{ height:33px; font-size:.77rem; } }

/* ── Edit history: diffs + upvotes ── */
.eh-vote{
    display:flex; align-items:center; gap:5px; flex-shrink:0; height:var(--act-h); padding:0 10px;
    border:none; border-radius:var(--act-r); background:rgba(var(--fg),.1); color:rgba(var(--fg),.75);
    font-size:.68rem; font-weight:700; transition:background .2s, color .2s, transform .15s;
}
.eh-vote i{ font-size:.9rem; }
.eh-vote:hover{ background:rgba(var(--fg),.2); color:var(--ui-text); }
.eh-vote:active{ transform:scale(.95); }
.eh-vote.on{ background:var(--accent); color:var(--on-accent); }
.eh-vote:disabled{ opacity:.45; cursor:default; transform:none; }
.eh-toggle{ background:none; border:none; padding:0; margin-top:6px; color:var(--accent-text); font-size:.64rem; font-weight:700; text-decoration:underline; }
.eh-changes{ display:none; margin-top:4px; }
.eh-item.open .eh-changes{ display:block; }
.df{ margin-top:8px; border:1px solid var(--line); border-radius:var(--act-r); overflow:hidden; background:var(--surface); }
.df-h{ padding:5px 10px; font-size:.56rem; font-weight:700; letter-spacing:1px; text-transform:uppercase; color:var(--muted); border-bottom:1px solid var(--line); }
.df-b{ padding:9px 10px; font-size:.72rem; line-height:1.75; color:var(--sub); white-space:pre-wrap; word-break:break-word; }
.df-b ins{ text-decoration:none; background:rgba(46,204,113,.2); color:#4dde8a; border-radius:2px; padding:0 1px; }
.df-b del{ background:rgba(255,80,80,.17); color:#ff6b6b; text-decoration:line-through; border-radius:2px; padding:0 1px; }
.df-gap{ color:var(--muted-2); }
.df-note{ display:flex; align-items:center; gap:7px; }
.df-note i{ color:var(--accent); font-size:.95rem; }
[data-theme="light"] .df-b ins{ background:rgba(30,160,90,.16); color:#14803c; }
[data-theme="light"] .df-b del{ background:rgba(217,43,43,.12); color:#c0392b; }
@media (max-width:790px){ .eh-vote{ font-size:.74rem; } .df-b{ font-size:.79rem; } .df-h{ font-size:.6rem; } .eh-toggle{ font-size:.7rem; } }

/* ── Filters screen ── */
.mpf-block{ padding:14px var(--rp-pad) 6px; }
.mpf-row{ display:grid; grid-template-columns:1fr 1fr; gap:10px; }
.mpf-sub{ font-size:.62rem; color:var(--muted); margin-bottom:6px; font-weight:600; }
.mpf-note{ padding:2px var(--rp-pad) 12px; font-size:.66rem; color:var(--muted); line-height:1.6; }

/* ── Share screen ── */
.mps-box{ padding:18px var(--rp-pad) 6px; }
.mps-box canvas{ display:block; width:100%; height:auto; aspect-ratio:1200/630; border-radius:8px; background:#0b0d24; border:1px solid var(--line); }
.mps-btns{ display:flex; flex-wrap:wrap; gap:8.8px; margin-top:14px; }
.mps-btns .mpr-btn{ order:0; flex:1 1 130px; }
.mps-url{ margin-top:12px; font-size:.64rem; color:var(--muted); text-align:center; word-break:break-all; }
.mps-hint{ margin:14px auto 4px; font-size:.7rem; line-height:1.6; color:var(--muted); text-align:center; max-width:340px; }

/* ═══════════════════════════════════════════════════════════════════
   NEW FEATURES
   Sources & citations (+ editor)
   ═══════════════════════════════════════════════════════════════════ */

/* ── Sources & citations (entry detail) ── */
.src-list{ display:flex; flex-direction:column; gap:10px; }
.src-item{ border:1px solid var(--line); border-radius:var(--r); background:var(--surface); padding:11px 12px; }
.src-item.ver{ border-color:rgba(77,222,138,.4); }
[data-theme="light"] .src-item.ver{ border-color:rgba(30,160,90,.4); }
.src-top{ display:flex; align-items:flex-start; gap:10px; }
.src-ic{ width:32px; height:32px; border-radius:var(--act-r); display:flex; align-items:center; justify-content:center; flex-shrink:0; background:var(--accent-soft); color:var(--accent-text); font-size:1rem; }
.src-tx{ flex:1; min-width:0; }
.src-title{ display:block; font-size:.76rem; font-weight:600; color:var(--ui-text); line-height:1.4; overflow-wrap:anywhere; }
a.src-title:hover{ text-decoration:underline; color:var(--accent-text); }
.src-meta{ font-size:.62rem; color:var(--muted); margin-top:2px; line-height:1.5; overflow-wrap:anywhere; }
.src-foot{ display:flex; align-items:center; justify-content:space-between; gap:8px; flex-wrap:wrap; margin-top:10px; padding-top:9px; border-top:1px solid var(--line); }
.src-badge{
    display:inline-flex; align-items:center; gap:5px; font-size:.62rem; font-weight:700; padding:3px 9px; border-radius:var(--act-r);
    background:rgba(var(--fg),.08); color:var(--muted);
}
.src-badge.ver{ background:rgba(77,222,138,.16); color:#4dde8a; }
[data-theme="light"] .src-badge.ver{ background:rgba(30,160,90,.14); color:#14803c; }
.src-verify{
    display:flex; align-items:center; gap:5px; height:var(--act-h); padding:0 12px; border:none; border-radius:var(--act-r);
    background:rgba(var(--fg),.1); color:var(--ui-text); font-size:.66rem; font-weight:700; transition:background .2s, color .2s, transform .15s;
}
.src-verify:hover{ background:rgba(var(--fg),.2); }
.src-verify:active{ transform:scale(.96); }
.src-verify.on{ background:var(--accent); color:var(--on-accent); }
.src-verify:disabled{ opacity:.45; cursor:default; transform:none; }
@media (max-width:790px){ .src-title{ font-size:.84rem; } .src-meta{ font-size:.68rem; } .src-badge{ font-size:.68rem; } .src-verify{ font-size:.72rem; } }

/* ── Sources editor (create / edit modal) ── */
.se-list{ display:flex; flex-direction:column; gap:10px; }
.se-list:empty{ display:none; }
.se-row{ border:1px solid var(--line); border-radius:var(--r); background:var(--surface); padding:9px; display:flex; flex-direction:column; gap:8px; }
.se-top{ display:flex; align-items:center; gap:8px; }
.se-top .gc-select{ flex:1; min-width:0; }
.se-x{ width:var(--ctl-h); height:var(--ctl-h); border:none; border-radius:var(--r); background:rgba(var(--fg),.1); color:var(--ui-text); font-size:1rem; display:flex; align-items:center; justify-content:center; flex-shrink:0; transition:background .2s, color .2s; }
.se-x:hover{ background:var(--danger-soft); color:var(--danger); }
.se-add{
    margin-top:10px; width:100%; height:var(--ctl-h); border:1.5px dashed var(--accent-border); border-radius:var(--r);
    background:var(--accent-soft); color:var(--accent-text); font-size:.72rem; font-weight:700;
    display:flex; align-items:center; justify-content:center; gap:6px; transition:background .2s, border-color .2s;
}
.se-add:hover{ background:var(--accent-fill); border-color:var(--accent); }
.se-add:disabled{ opacity:.5; cursor:default; }
@media (max-width:790px){ .se-add{ font-size:.8rem; } }

</style>
</head>
<body>

<?php include 'nav.php'; ?>

<div class="mp-shell">

<div class="ac-lane">

    <!-- ══ TOPBAR (no X — just the title + icons) ══ -->
    <div class="ac-topbar">
        <div class="ac-title left">Memepedia</div>
        <div class="tb-icons">
            <button type="button" class="tb-icon-btn tb-add-btn" onclick="openCreate()" aria-label="Upload new entry" title="New Entry"><i class="ri-add-line"></i></button>
            <!-- Search: same SVG as the one in nav.php -->
            <button type="button" class="tb-icon-btn" onclick="openSearch()" aria-label="Search entries" title="Search">
                <svg class="tb-search-svg" viewBox="0 0 24 24" aria-hidden="true">
                    <circle class="nav-search-ring" cx="10" cy="10" r="6.5" fill="none" stroke="currentColor" stroke-width="2.5"/>
                    <path class="nav-search-handle" fill="currentColor" d="M15.37 14.23L21.41 18.59A2 2 0 0 1 18.59 21.41L14.23 15.37Z"/>
                </svg>
            </button>
            <button type="button" class="tb-icon-btn sort-btn" id="sortBtn" onclick="toggleSortMenu()" aria-label="Sort" title="Sort"><i class="ri-menu-line"></i></button>
        </div>
    </div>

    <!-- ══ FILTER CHIPS + SORT MENU ══ -->
    <div class="toolbar">
        <div class="filter-chips" id="filterChips">
            <button type="button" class="chip active" data-filter="all" onclick="setFilter(this,'all')">All</button>
            <button type="button" class="chip" data-filter="mine" onclick="setFilter(this,'mine')">Mine</button>
            <button type="button" class="chip" data-filter="following" onclick="setFilter(this,'following')"><i class="ri-bookmark-fill"></i> Following</button>
            <button type="button" class="chip" data-filter="trending" onclick="setFilter(this,'trending')">Trending</button>
            <button type="button" class="chip" data-filter="rising" onclick="setFilter(this,'rising')">Rising</button>
            <button type="button" class="chip" data-filter="classic" onclick="setFilter(this,'classic')">Classic</button>
            <button type="button" class="chip" data-filter="legendary" onclick="setFilter(this,'legendary')">Legendary</button>
            <button type="button" class="chip" data-filter="dead" onclick="setFilter(this,'dead')">Dead</button>
            <button type="button" class="chip" data-filter="revived" onclick="setFilter(this,'revived')">Revived</button>
        </div>
        <div class="toolbar-sort">
            <div class="sort-menu" id="sortMenu">
                <div class="sort-opt active" data-sort="newest" onclick="pickSort(this,'newest')"><i class="ri-time-line"></i> Newest First</div>
                <div class="sort-opt" data-sort="oldest" onclick="pickSort(this,'oldest')"><i class="ri-history-line"></i> Oldest First</div>
                <div class="sort-opt" data-sort="views" onclick="pickSort(this,'views')"><i class="ri-eye-line"></i> Most Viewed</div>
                <div class="sort-opt" data-sort="discussed" onclick="pickSort(this,'discussed')"><i class="ri-chat-3-line"></i> Most Discussed</div>
                <div class="sort-opt" data-sort="edits" onclick="pickSort(this,'edits')"><i class="ri-edit-line"></i> Most Edited</div>
                <div class="sort-opt" data-sort="az" onclick="pickSort(this,'az')"><i class="ri-sort-asc"></i> A &rarr; Z</div>
            </div>
        </div>
    </div>
    <div class="sort-backdrop" id="sortBackdrop" onclick="closeSortMenu()"></div>

    <div class="results-bar">
        <span><strong id="visibleCount">–</strong> <span id="visibleWord">entries found</span></span>
        <div class="rb-tools">
            <button type="button" class="rb-btn" id="filterBtn" onclick="openFilters()" aria-label="Advanced filters">
                <i class="ri-equalizer-line"></i><span class="rb-lbl">Filters</span><span class="rb-badge" id="filterBadge">0</span>
            </button>
            <div class="view-toggle" role="group" aria-label="View mode">
                <button type="button" id="viewList" class="active" onclick="setView('list')" aria-label="List view" title="List"><i class="ri-list-unordered"></i></button>
                <button type="button" id="viewTl" onclick="setView('timeline')" aria-label="Timeline view" title="Timeline"><i class="ri-timeline-view"></i></button>
            </div>
        </div>
    </div>
    <div class="adv-summary" id="advSummary"></div>

    <!-- ══ LIST / TIMELINE ══ -->
    <div class="entries-list" id="entriesList"></div>

    <div class="empty-state" id="emptyState">
        <div class="es-title" id="emptyTitle">No entries yet</div>
        <p class="es-text" id="emptyDesc">Memepedia is blank — be the first to document a meme, a piece of internet slang, or a viral moment.</p>
        <div class="es-tips" id="emptyTips">
            <div class="es-tip"><i class="ri-book-open-line"></i><span>Write what you know. Others can add the rest later.</span></div>
            <div class="es-tip"><i class="ri-image-2-line"></i><span>Every entry needs a cover image and a short summary.</span></div>
        </div>
        <button type="button" class="es-btn" id="emptyBtn" onclick="openCreate()"><i class="ri-edit-line"></i> Write the First Entry</button>
    </div>

</div><!-- /.ac-lane -->

<!-- ═════════ RIGHT COLUMN (normal / laptop view) — what Memepedia is about ═════════ -->
<aside class="mp-side" aria-label="About Memepedia">
  <div class="gs-cards">

    <div class="gs-card" data-k="about">
      <button type="button" class="gs-bar" onclick="gsToggle(this)" aria-expanded="true"><i class="ri-book-open-line gs-bar-ic"></i><span class="gs-bar-t">About Memepedia</span><i class="ri-arrow-down-s-line gs-bar-chev"></i></button>
      <div class="gs-body"><div class="gs-inner"><div class="gs-pad">
        <p class="gs-p"><b>Memepedia</b> is the community-written encyclopedia of internet meme culture. Every meme, piece of slang and viral moment gets its own entry, built edit by edit by the people who were there.</p>
        <div class="gs-callout"><i class="ri-information-line"></i><span>Anyone can start an entry or expand one. <b>Only the author can delete their own entry</b>, but everyone can improve it.</span></div>
      </div></div></div>
    </div>

    <div class="gs-card" data-k="stats">
      <button type="button" class="gs-bar" onclick="gsToggle(this)" aria-expanded="true"><i class="ri-bar-chart-2-line gs-bar-ic"></i><span class="gs-bar-t">Memepedia at a glance</span><i class="ri-arrow-down-s-line gs-bar-chev"></i></button>
      <div class="gs-body"><div class="gs-inner"><div class="gs-pad">
        <div class="gs-row"><span class="gs-row-l">Entries</span><span class="gs-row-v" id="gsEntries">0</span></div>
        <div class="gs-row"><span class="gs-row-l">Total edits</span><span class="gs-row-v" id="gsEdits">0</span></div>
        <div class="gs-row"><span class="gs-row-l">Contributors</span><span class="gs-row-v" id="gsPeople">0</span></div>
        <div class="gs-row"><span class="gs-row-l">Your entries</span><span class="gs-row-v" id="gsMine">0</span></div>
        <div class="gs-row"><span class="gs-row-l">Following</span><span class="gs-row-v" id="gsFollow">0</span></div>
      </div></div></div>
    </div>

    <div class="gs-card" data-k="status">
      <button type="button" class="gs-bar" onclick="gsToggle(this)" aria-expanded="true"><i class="ri-pulse-line gs-bar-ic"></i><span class="gs-bar-t">Meme statuses</span><i class="ri-arrow-down-s-line gs-bar-chev"></i></button>
      <div class="gs-body"><div class="gs-inner"><div class="gs-pad">
        <button type="button" class="gs-topic" onclick="mpFilter('trending')"><span class="gs-ti"><i class="ri-fire-fill"></i></span><span class="gs-tx"><span class="gs-tn"><b>Trending</b><small id="gsC_trending">0</small></span><span class="gs-td">Everywhere right now.</span></span></button>
        <button type="button" class="gs-topic" onclick="mpFilter('rising')"><span class="gs-ti"><i class="ri-arrow-up-line"></i></span><span class="gs-tx"><span class="gs-tn"><b>Rising</b><small id="gsC_rising">0</small></span><span class="gs-td">Gaining traction fast.</span></span></button>
        <button type="button" class="gs-topic" onclick="mpFilter('classic')"><span class="gs-ti"><i class="ri-building-line"></i></span><span class="gs-tx"><span class="gs-tn"><b>Classic</b><small id="gsC_classic">0</small></span><span class="gs-td">Old, but everyone knows it.</span></span></button>
        <button type="button" class="gs-topic" onclick="mpFilter('legendary')"><span class="gs-ti"><i class="ri-vip-crown-line"></i></span><span class="gs-tx"><span class="gs-tn"><b>Legendary</b><small id="gsC_legendary">0</small></span><span class="gs-td">All-time internet royalty.</span></span></button>
        <button type="button" class="gs-topic" onclick="mpFilter('dead')"><span class="gs-ti"><i class="ri-skull-line"></i></span><span class="gs-tx"><span class="gs-tn"><b>Dead</b><small id="gsC_dead">0</small></span><span class="gs-td">Past its time. Rest in memes.</span></span></button>
        <button type="button" class="gs-topic" onclick="mpFilter('revived')"><span class="gs-ti"><i class="ri-refresh-line"></i></span><span class="gs-tx"><span class="gs-tn"><b>Revived</b><small id="gsC_revived">0</small></span><span class="gs-td">Back from the dead.</span></span></button>
      </div></div></div>
    </div>

    <div class="gs-card" data-k="how">
      <button type="button" class="gs-bar" onclick="gsToggle(this)" aria-expanded="true"><i class="ri-quill-pen-line gs-bar-ic"></i><span class="gs-bar-t">How to write an entry</span><i class="ri-arrow-down-s-line gs-bar-chev"></i></button>
      <div class="gs-body"><div class="gs-inner"><div class="gs-pad">
        <div class="gs-step"><span class="gs-sn">1</span><span class="gs-st"><b>Check it doesn't exist</b><span>Search first. If the meme is already here, edit that entry instead.</span></span></div>
        <div class="gs-step"><span class="gs-sn">2</span><span class="gs-st"><b>Add a cover and summary</b><span>A clear image or clip plus one to three sentences on what it is.</span></span></div>
        <div class="gs-step"><span class="gs-sn">3</span><span class="gs-st"><b>Fill in what you know</b><span>Origin, spread, variations and more. Leave the rest for others.</span></span></div>
        <div class="gs-step"><span class="gs-sn">4</span><span class="gs-st"><b>Cite your sources</b><span>Link the first post or a news article. Other members can verify them.</span></span></div>
      </div></div></div>
    </div>

    <div class="gs-card" data-k="rules">
      <button type="button" class="gs-bar" onclick="gsToggle(this)" aria-expanded="true"><i class="ri-shield-check-line gs-bar-ic"></i><span class="gs-bar-t">Community guidelines</span><i class="ri-arrow-down-s-line gs-bar-chev"></i></button>
      <div class="gs-body"><div class="gs-inner"><div class="gs-pad">
        <div class="gs-rule"><i class="ri-check-line"></i><span>Be accurate. Say what you know and cite sources when you can.</span></div>
        <div class="gs-rule"><i class="ri-check-line"></i><span>Stay neutral. Describe the meme, don't sell or attack it.</span></div>
        <div class="gs-rule"><i class="ri-check-line"></i><span>No personal info, harassment or hateful content.</span></div>
        <div class="gs-rule"><i class="ri-check-line"></i><span>Respect other writers. Improve entries, don't wipe them.</span></div>
      </div></div></div>
    </div>

    <div class="gs-card" data-k="recent">
      <button type="button" class="gs-bar" onclick="gsToggle(this)" aria-expanded="true"><i class="ri-time-line gs-bar-ic"></i><span class="gs-bar-t">Recently added</span><i class="ri-arrow-down-s-line gs-bar-chev"></i></button>
      <div class="gs-body"><div class="gs-inner"><div class="gs-pad" id="gsRecentList"><div class="gs-note">Nothing here yet.</div></div></div></div>
    </div>

  </div>
</aside>

</div><!-- /.mp-shell -->

<!-- ══ SEARCH MODAL ══ -->
<div class="modal-overlay" id="modal-search">
  <div class="modal-lane">
    <div class="ac-topbar modal-topbar">
        <button type="button" class="ac-x" onclick="closeModal('modal-search')" aria-label="Close search"><i class="ri-arrow-left-s-line"></i></button>
        <div class="search-input-wrap">
            <svg class="si-search" viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="10" cy="10" r="6.5" fill="none" stroke="currentColor" stroke-width="2.5"/>
                <path fill="currentColor" d="M15.37 14.23L21.41 18.59A2 2 0 0 1 18.59 21.41L14.23 15.37Z"/>
            </svg>
            <input type="text" id="searchInput" placeholder="Search entries, trends…" autocomplete="off" spellcheck="false" oninput="runSearch(this.value)">
            <button type="button" class="search-clear-x" id="searchClearX" onclick="clearSearch()" aria-label="Clear"><i class="ri-close-line"></i></button>
        </div>
    </div>
    <div class="modal-body">
        <div id="searchResultsWrap"></div>
    </div>
  </div>
</div>

<!-- ══ CREATE / EDIT MODAL ══ -->
<div class="modal-overlay" id="modal-create">
  <div class="modal-lane">
    <div class="ac-topbar modal-topbar">
        <button type="button" class="ac-x" onclick="closeCreate()" aria-label="Close">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
        <div class="ac-title" id="cmTitle">New Meme Entry</div>
    </div>
    <div class="modal-body">
        <div class="cm-skel" id="cmSkel" aria-hidden="true">
            <div class="sk-shimmer" style="width:35%;height:10px;"></div><div class="sk-shimmer" style="height:32px;margin-bottom:22px;"></div>
            <div class="sk-shimmer" style="width:30%;height:10px;"></div><div class="sk-shimmer" style="height:96px;margin-bottom:22px;"></div>
            <div class="sk-shimmer" style="width:25%;height:10px;"></div><div class="sk-shimmer" style="height:70px;margin-bottom:22px;"></div>
            <div class="sk-shimmer" style="width:40%;height:10px;"></div><div class="sk-shimmer" style="height:60px;"></div><div class="sk-shimmer" style="height:60px;"></div><div class="sk-shimmer" style="height:60px;margin-bottom:22px;"></div>
            <div class="sk-shimmer" style="width:30%;height:10px;"></div><div class="sk-shimmer" style="height:32px;"></div>
        </div>
        <div class="cm-real" id="cmReal">
        <div class="dup-warning" id="dupWarning">
            <div class="dup-title"><i class="ri-alert-line"></i> Entry Already Exists</div>
            <div class="dup-text">An entry for "<span id="dupName"></span>" already exists.</div>
            <span class="dup-link" onclick="openDuplicate()"><i class="ri-external-link-line"></i> View and edit that entry instead</span>
        </div>

        <div class="field-block">
            <div class="field-label"><i class="ri-text"></i> Meme Name / Title *</div>
            <input type="text" class="gc-input" id="cm_title" placeholder="e.g. Distracted Boyfriend, Doge, NPC…" oninput="checkDuplicate(this.value)" autocomplete="off" spellcheck="false">
        </div>

        <div class="field-block">
            <div class="field-label"><i class="ri-image-2-line"></i> Cover Image *</div>
            <div class="media-uploader" id="mediaUploader"
                 ondragover="event.preventDefault();this.classList.add('dragover')"
                 ondragleave="this.classList.remove('dragover')"
                 ondrop="handleDrop(event)">
                <input type="file" id="cm_media" accept="image/*,video/*,image/gif" onchange="previewMedia(this)">
                <div class="mu-icon"><i class="ri-upload-cloud-line"></i></div>
                <div class="mu-title">Drop or click to upload</div>
                <div class="mu-sub">PNG · JPG · GIF · MP4 · WebM · up to 50MB · required</div>
                <div class="mu-preview" id="mediaPreview">
                    <button type="button" class="mu-remove" onclick="removeMedia(event)"><i class="ri-close-line"></i></button>
                </div>
            </div>
        </div>

        <div class="field-block">
            <div class="field-label"><i class="ri-file-text-line"></i> Summary *</div>
            <textarea class="gc-textarea" id="cm_summary" rows="3" placeholder="One to three sentences. What is this meme?" style="min-height:70px;"></textarea>
        </div>

        <div class="field-block">
            <div class="field-label"><i class="ri-article-line"></i> Full Article — By Segment</div>
            <div class="field-hint">Optional. Fill in whatever you know — leave the rest blank and other contributors can add to it later.</div>
            <div class="segment-editor">
                <?php $i = 0; foreach ($segment_defs as $key => $def): ?>
                <div class="field-block">
                    <div class="field-label"><i class="<?= $def['icon'] ?>"></i> <?= htmlspecialchars($def['label']) ?></div>
                    <textarea class="gc-textarea" id="seg_<?= $key ?>" rows="3" placeholder="Write about <?= htmlspecialchars(strtolower($def['label'])) ?>…" style="min-height:64px;"></textarea>
                </div>
                <?php if (isset($extra_image_after[$i])): $slot = $extra_image_after[$i]; ?>
                <div class="field-block">
                    <div class="field-label"><i class="ri-image-add-line"></i> Additional Image <?= $slot ?> <span style="color:var(--muted);font-weight:400;text-transform:none;letter-spacing:0;">(optional — appears here)</span></div>
                    <div class="media-uploader small-uploader" id="extraUploader<?= $slot ?>"
                         ondragover="event.preventDefault();this.classList.add('dragover')"
                         ondragleave="this.classList.remove('dragover')"
                         ondrop="handleExtraDrop(event,<?= $slot ?>)">
                        <input type="file" id="extra_media_<?= $slot ?>" accept="image/*" onchange="previewExtraMedia(this,<?= $slot ?>)">
                        <div class="mu-icon"><i class="ri-image-line"></i></div>
                        <div class="mu-title">Add an image</div>
                        <div class="mu-sub">Optional</div>
                        <div class="mu-preview" id="extraPreview<?= $slot ?>">
                            <button type="button" class="mu-remove" onclick="removeExtraMedia(event,<?= $slot ?>)"><i class="ri-close-line"></i></button>
                        </div>
                    </div>
                </div>
                <?php endif; $i++; endforeach; ?>
            </div>
        </div>

        <!-- Example gallery: swipeable carousel on the entry -->
        <div class="field-block">
            <div class="field-label"><i class="ri-gallery-line"></i> Example Gallery</div>
            <div class="field-hint">Optional. Add example posts, GIF variations and video clips (up to 10). Readers swipe through them on the entry.</div>
            <div class="media-uploader small-uploader" id="galUploader"
                 ondragover="event.preventDefault();this.classList.add('dragover')"
                 ondragleave="this.classList.remove('dragover')"
                 ondrop="handleGalleryDrop(event)">
                <input type="file" id="gal_files" accept="image/*,video/*" multiple onchange="addGalleryFiles(this.files); this.value='';">
                <div class="mu-icon"><i class="ri-image-add-line"></i></div>
                <div class="mu-title">Add images, GIFs or clips</div>
                <div class="mu-sub">Select several at once · up to 50MB each</div>
            </div>
            <div class="gal-ed" id="galEditor"></div>
            <div class="gal-ed-count" id="galEdCount"></div>
        </div>

        <!-- Sources & citations (other members can verify each one) -->
        <div class="field-block">
            <div class="field-label"><i class="ri-links-line"></i> Sources &amp; Citations</div>
            <div class="field-hint">Optional. Link the first post, a news article or a Know Your Meme page. Other members can verify each source.</div>
            <div class="se-list" id="srcEditor"></div>
            <button type="button" class="se-add" id="srcAddBtn" onclick="addSourceRow()"><i class="ri-add-line"></i> Add a source</button>
        </div>

        <div class="field-row">
            <div class="field-block">
                <div class="field-label"><i class="ri-calendar-line"></i> Origin Year</div>
                <input type="text" class="gc-input" id="cm_year" placeholder="e.g. 2016" maxlength="4" inputmode="numeric">
            </div>
            <div class="field-block">
                <div class="field-label"><i class="ri-global-line"></i> Origin Platform</div>
                <input type="text" class="gc-input" id="cm_platform" placeholder="e.g. Reddit, Twitter…">
            </div>
        </div>

        <div class="field-block">
            <div class="field-label"><i class="ri-hashtag"></i> Category</div>
            <select class="gc-select" id="cm_category">
                <option value="">— Select a category —</option>
                <?php foreach ($categories_list as $cat): ?>
                <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field-block">
            <div class="field-label"><i class="ri-pulse-line"></i> Status</div>
            <div class="status-chips" id="statusChips">
                <button type="button" class="status-chip" data-val="trending" onclick="selectStatus(this)"><i class="ri-fire-fill"></i> Trending</button>
                <button type="button" class="status-chip selected" data-val="rising" onclick="selectStatus(this)"><i class="ri-arrow-up-line"></i> Rising</button>
                <button type="button" class="status-chip" data-val="classic" onclick="selectStatus(this)"><i class="ri-building-line"></i> Classic</button>
                <button type="button" class="status-chip" data-val="legendary" onclick="selectStatus(this)"><i class="ri-vip-crown-line"></i> Legendary</button>
                <button type="button" class="status-chip" data-val="dead" onclick="selectStatus(this)"><i class="ri-skull-line"></i> Dead</button>
                <button type="button" class="status-chip" data-val="revived" onclick="selectStatus(this)"><i class="ri-refresh-line"></i> Revived</button>
            </div>
        </div>

        <div class="field-block">
            <div class="field-label"><i class="ri-price-tag-3-line"></i> Tags (comma separated)</div>
            <input type="text" class="gc-input" id="cm_tags" placeholder="e.g. dog, shiba inu, crypto, 2013">
        </div>

        <input type="hidden" id="cm_edit_id" value="">
        <button type="button" class="gc-btn" id="cmSubmit" onclick="submitArticle()"><i class="ri-check-line"></i> Publish Entry</button>
        </div><!-- /#cmReal -->
    </div>
  </div>
</div>

<!-- ══ ENTRY DETAIL MODAL ══ -->
<div class="modal-overlay" id="modal-entry">
  <div class="modal-lane">

    <div class="entry-skeleton" id="entrySkeleton">
        <div class="es-hero sk-shimmer" style="border-radius:0;"></div>
        <div class="es-body">
            <div class="es-line sk-shimmer" style="width:35%;height:10px;margin-bottom:10px;"></div>
            <div class="es-line sk-shimmer" style="width:75%;height:20px;margin-bottom:14px;"></div>
            <div class="es-line sk-shimmer" style="width:50%;height:13px;margin-bottom:18px;"></div>
            <div class="es-line sk-shimmer" style="width:100%;height:76px;margin-bottom:14px;"></div>
            <div class="es-line sk-shimmer" style="width:100%;height:130px;"></div>
        </div>
    </div>

    <div class="entry-content" id="entryContent">
        <div class="detail-stickybar" id="entryStickybar">
            <button type="button" class="detail-icon-btn detail-back-btn" onclick="closeEntry()" aria-label="Close">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
            </button>
            <span class="stickybar-title" id="stickybarTitle"></span>
            <div class="detail-right-icons">
                <button type="button" class="detail-icon-btn detail-edit-icon-btn" id="entryShareBtn" onclick="openShare()" aria-label="Share entry" title="Share">
                    <i class="ri-share-forward-line"></i>
                </button>
                <button type="button" class="detail-icon-btn detail-edit-icon-btn" id="entryEditBtn" onclick="editCurrentEntry()" aria-label="Edit entry" title="Edit">
                    <i class="ri-edit-2-line"></i>
                </button>
                <button type="button" class="detail-icon-btn detail-edit-icon-btn" id="entryMenuBtn" onclick="openEntryMenu()" aria-label="Menu" title="Menu">
                    <i class="ri-menu-line"></i>
                </button>
            </div>
        </div>

        <div class="detail-hero" id="entryHeroMedia">
            <div class="no-img" id="entryNoMedia"><i class="ri-image-line"></i></div>
            <div class="detail-hero-fade"></div>
            <span class="hero-status-tag" id="entryHeroStatus"></span>
        </div>

        <div class="modal-body detail-modal-body">
            <div class="detail-cat-eyebrow"><i class="ri-hashtag"></i> <span id="entryCategoryText" data-tr></span></div>
            <h1 class="detail-title" id="entryTitle" data-tr></h1>

            <div class="detail-author-row">
                <img id="entryAuthorAvatar" src="" alt="Author" onerror="this.src='uploads/me.jpeg'">
                <span id="entryAuthorName"></span>
                <span class="dot"></span>
                <span id="entryCreatedShort"></span>
                <button type="button" class="follow-btn" id="entryFollowBtn" onclick="toggleFollow()" aria-pressed="false">
                    <i class="ri-bookmark-line"></i><span>Follow</span>
                </button>
            </div>

            <div class="detail-meta-list">
                <div class="meta-row"><i class="ri-quill-pen-line"></i> <span class="meta-label" id="entryCreatedRow"></span></div>
                <div class="meta-row"><i class="ri-refresh-line"></i> <span class="meta-label" id="entryUpdatedRow"></span></div>
                <div class="meta-row"><i class="ri-shield-check-line"></i> <span class="meta-label" id="entryVerRow"></span></div>
            </div>

            <div class="detail-info-grid" id="entryInfoGrid"></div>

            <div class="tr-bar" id="entryTrBar">
                <i class="ri-translate-2"></i>
                <span id="entryTrTxt"></span>
                <button type="button" onclick="trShowOriginal()">Show original</button>
            </div>

            <div class="detail-tabs" role="tablist">
                <button type="button" class="detail-tab active" onclick="switchEntryTab(this,'article')"><i class="ri-file-text-line"></i> Article</button>
                <button type="button" class="detail-tab" onclick="switchEntryTab(this,'history')"><i class="ri-history-line"></i> History (<span id="entryEditCount">0</span>)</button>
                <button type="button" class="detail-tab" onclick="switchEntryTab(this,'discussion')"><i class="ri-chat-3-line"></i> Discussion</button>
                <button type="button" class="detail-tab" onclick="switchEntryTab(this,'related')"><i class="ri-links-line"></i> Related</button>
            </div>

            <div class="detail-tab-panel active" id="etab-article">
                <div class="detail-section">
                    <div class="detail-section-label">Summary</div>
                    <p class="detail-summary" id="entrySummary" data-tr></p>
                </div>
                <div class="detail-section" id="entryGallerySec" style="display:none;">
                    <div class="detail-section-label">Examples &amp; Variations · <span id="galCount"></span></div>
                    <div id="entryGallery"></div>
                </div>
                <div class="detail-section">
                    <div class="detail-section-label">Full Article</div>
                    <div id="entryArticleSegments"></div>
                </div>
                <div class="detail-section" id="entrySourcesSec">
                    <div class="detail-section-label">Sources &amp; Citations · <span id="srcCount"></span></div>
                    <div class="src-list" id="entrySources"></div>
                </div>
                <div class="detail-section">
                    <div class="detail-section-label">Tags</div>
                    <div class="detail-tags" id="entryTags"></div>
                </div>
            </div>

            <div class="detail-tab-panel" id="etab-history">
                <div class="detail-section">
                    <div class="detail-section-label">Edit History</div>
                    <div class="edit-history-list" id="editHistoryList">
                        <div class="empty-tab-msg">No edits recorded yet.</div>
                    </div>
                </div>
            </div>

            <div class="detail-tab-panel" id="etab-discussion">
                <div class="detail-section">
                    <div class="detail-section-label">Discussion</div>
                    <div class="comment-list" id="discussionList" style="margin-bottom:16px;"></div>
                    <div class="comment-form">
                        <img id="discInputAvatar" src="<?= htmlspecialchars($profile_pic) ?>" alt="You" onerror="this.src='uploads/me.jpeg'">
                        <textarea id="discInput" placeholder="Add to the discussion…" rows="1"></textarea>
                        <button type="button" onclick="postComment()" aria-label="Post comment"><i class="ri-send-plane-fill"></i></button>
                    </div>
                </div>
            </div>

            <div class="detail-tab-panel" id="etab-related">
                <div class="detail-section">
                    <div class="detail-section-label">Related Entries</div>
                    <div class="related-list" id="relatedList">
                        <div class="empty-tab-msg">No related entries yet.</div>
                    </div>
                </div>
            </div>

            <div class="host-section">
                <div class="host-section-title">Written By</div>
                <div class="host-row" onclick="var l=document.getElementById('entryHostProfileLink'); if(l) location.href=l.href;">
                    <img class="host-row-avatar" id="entryHostAvatar" src="" alt="" onerror="this.src='uploads/me.jpeg'">
                    <div class="host-row-info">
                        <div class="host-row-name" id="entryHostName"></div>
                        <div class="host-row-role">Contributor</div>
                    </div>
                    <a class="host-row-link" id="entryHostProfileLink" href="profile.php" onclick="event.stopPropagation()" aria-label="View profile">
                        <i class="ri-arrow-right-s-line"></i>
                    </a>
                </div>
            </div>

            <footer class="detail-footer">
                <div class="detail-footer-brand"><i class="ri-book-open-line"></i> Memepedia</div>
                <p class="detail-footer-desc">Memepedia is the free, community-written encyclopedia of internet meme culture — built entry by entry, edit by edit, by people who were there. Every meme, every piece of slang, every viral moment, documented before the internet forgets it.</p>
                <div class="detail-footer-cols">
                    <div>
                        <div class="detail-footer-col-title">Explore</div>
                        <span class="detail-footer-link" onclick="closeEntry()">All Entries</span>
                        <span class="detail-footer-link" onclick="closeEntry();openCreate()">New Entry</span>
                        <span class="detail-footer-link" onclick="closeEntry();openSearch()">Search</span>
                    </div>
                    <div>
                        <div class="detail-footer-col-title">Community</div>
                        <span class="detail-footer-link">Guidelines</span>
                        <span class="detail-footer-link">Edit Etiquette</span>
                        <span class="detail-footer-link" onclick="openArticleReport()">Report an Entry</span>
                    </div>
                    <div>
                        <div class="detail-footer-col-title">About</div>
                        <span class="detail-footer-link">How It Works</span>
                        <span class="detail-footer-link">Privacy</span>
                        <span class="detail-footer-link">Terms</span>
                    </div>
                </div>
                <div class="detail-footer-bottom">
                    <span>© <span id="footerYear"></span> Memepedia. Written by everyone, owned by no one.</span>
                    <div class="detail-footer-social">
                        <a href="#" title="Twitter"><i class="ri-twitter-x-line"></i></a>
                        <a href="#" title="Discord"><i class="ri-discord-line"></i></a>
                        <a href="#" title="Instagram"><i class="ri-instagram-line"></i></a>
                    </div>
                </div>
            </footer>
        </div>
    </div>

  </div>
</div>

<div class="toast-container" id="toastContainer" aria-live="polite"></div>

<!-- ══ ENTRY MENU (Follow · Share · Translate · QR code · Report) ══ -->
<div class="mpr-screen" id="mpMenu" role="dialog" aria-modal="true" aria-label="Entry menu">
  <div class="mpr-lane">
    <div class="mpr-topbar">
        <button type="button" class="mpr-x" onclick="mpClose('mpMenu')" aria-label="Close">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
        <div class="mpr-title">Menu</div>
    </div>
    <div class="mpr-body">
        <div class="mpr-target"><i class="ri-book-open-line"></i><span id="mpMenuTarget"></span></div>
        <div class="mpr-row" onclick="mpMenuGo('follow')">
            <i class="ri-bookmark-line mpr-row-icon" id="mpMenuFollowIc" aria-hidden="true"></i>
            <div class="mpr-row-text"><span id="mpMenuFollowTx">Follow this entry</span><small>Get notified about edits, comments and status changes</small></div>
        </div>
        <div class="mpr-row" onclick="mpMenuGo('share')">
            <i class="ri-share-forward-line mpr-row-icon" aria-hidden="true"></i>
            <div class="mpr-row-text">Share this entry<small>Send it with a preview card</small></div>
            <i class="ri-arrow-right-s-line mpr-chev"></i>
        </div>
        <div class="mpr-row" onclick="mpMenuGo('translate')">
            <i class="ri-translate-2 mpr-row-icon" aria-hidden="true"></i>
            <div class="mpr-row-text">Translate this article<small>Read it in your own language</small></div>
            <span class="mpr-val" id="mpMenuTrVal"></span>
            <i class="ri-arrow-right-s-line mpr-chev"></i>
        </div>
        <div class="mpr-row" onclick="mpMenuGo('qr')">
            <i class="ri-qr-code-line mpr-row-icon" aria-hidden="true"></i>
            <div class="mpr-row-text">QR code<small>Scan or print it to open this entry</small></div>
            <i class="ri-arrow-right-s-line mpr-chev"></i>
        </div>
        <div class="mpr-row danger" id="mpMenuReportRow" onclick="mpMenuGo('report')">
            <i class="ri-flag-line mpr-row-icon" aria-hidden="true"></i>
            <div class="mpr-row-text">Report this entry<small>Tell us if something is wrong with it</small></div>
            <i class="ri-arrow-right-s-line mpr-chev"></i>
        </div>
    </div>
  </div>
</div>

<!-- ══ TRANSLATE: pick your language ══ -->
<div class="mpr-screen notranslate" id="mpLang" role="dialog" aria-modal="true" aria-label="Translate this article" translate="no">
  <div class="mpr-lane">
    <div class="mpr-topbar">
        <button type="button" class="mpr-x" onclick="mpClose('mpLang')" aria-label="Close">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
        <div class="mpr-title">Translate this article</div>
    </div>
    <div class="mpl-search"><div class="mpl-search-in"><i class="ri-search-line"></i><input type="text" id="mpLangQ" placeholder="Search your language…" autocomplete="off" oninput="trLangBuild(this.value)"></div></div>
    <div class="mpr-body" id="mpLangBody">
        <div class="mpr-section-label">Translate into</div>
        <div id="mpLangList"></div>
    </div>
  </div>
</div>

<!-- ══ QR CODE ══ -->
<div class="mpr-screen" id="mpQr" role="dialog" aria-modal="true" aria-label="QR code">
  <div class="mpr-lane">
    <div class="mpr-topbar">
        <button type="button" class="mpr-x" onclick="mpClose('mpQr')" aria-label="Close">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
        <div class="mpr-title">QR code</div>
    </div>
    <div class="mpr-body">
        <div class="mpq-box">
            <canvas id="mpQrCv" width="300" height="300"></canvas>
            <div class="mpq-name" id="mpQrName"></div>
            <div class="mpq-url notranslate" id="mpQrUrl"></div>
            <div class="mpq-err" id="mpQrErr" style="display:none">This link is too long for a QR code.</div>
            <div class="mpq-btns">
                <button type="button" class="mpr-btn mpr-btn-submit" onclick="mpQrDownload()"><i class="ri-download-2-line"></i> Download image</button>
                <button type="button" class="mpr-btn mpr-btn-cancel" onclick="mpCopyLink()"><i class="ri-links-line"></i> Copy link</button>
            </div>
            <div class="mpq-hint">Print it, add it to a poster or share the image. Anyone who scans it opens this entry.</div>
        </div>
    </div>
  </div>
</div>

<!-- ══ SHARE (preview card + native share sheet + social links) ══ -->
<div class="mpr-screen" id="mpShare" role="dialog" aria-modal="true" aria-label="Share this entry">
  <div class="mpr-lane">
    <div class="mpr-topbar">
        <button type="button" class="mpr-x" onclick="mpClose('mpShare')" aria-label="Close">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
        <div class="mpr-title">Share this entry</div>
    </div>
    <div class="mpr-body">
        <div class="mps-box">
            <canvas id="mpShCv" width="1200" height="630"></canvas>
            <div class="mps-btns">
                <button type="button" class="mpr-btn mpr-btn-submit" id="mpShNative" onclick="mpShareNative()"><i class="ri-share-forward-line"></i> Share</button>
                <button type="button" class="mpr-btn mpr-btn-cancel" onclick="mpShDownload()"><i class="ri-download-2-line"></i> Save card</button>
                <button type="button" class="mpr-btn mpr-btn-cancel" onclick="mpCopyLink()"><i class="ri-links-line"></i> Copy link</button>
            </div>
            <div class="mps-url notranslate" id="mpShUrl"></div>
        </div>
        <div class="mpr-section-label" style="margin-top:14px;">Share to</div>
        <div class="mpr-row" onclick="mpShareTo('x')"><i class="ri-twitter-x-line mpr-row-icon" aria-hidden="true"></i><div class="mpr-row-text">X (Twitter)</div><i class="ri-arrow-right-s-line mpr-chev"></i></div>
        <div class="mpr-row" onclick="mpShareTo('whatsapp')"><i class="ri-whatsapp-line mpr-row-icon" aria-hidden="true"></i><div class="mpr-row-text">WhatsApp</div><i class="ri-arrow-right-s-line mpr-chev"></i></div>
        <div class="mpr-row" onclick="mpShareTo('facebook')"><i class="ri-facebook-circle-line mpr-row-icon" aria-hidden="true"></i><div class="mpr-row-text">Facebook</div><i class="ri-arrow-right-s-line mpr-chev"></i></div>
        <div class="mpr-row" onclick="mpShareTo('telegram')"><i class="ri-telegram-line mpr-row-icon" aria-hidden="true"></i><div class="mpr-row-text">Telegram</div><i class="ri-arrow-right-s-line mpr-chev"></i></div>
        <div class="mpr-row" onclick="mpShareTo('email')"><i class="ri-mail-line mpr-row-icon" aria-hidden="true"></i><div class="mpr-row-text">Email</div><i class="ri-arrow-right-s-line mpr-chev"></i></div>
        <div class="mps-hint">Tip: on your phone, Share opens the native share sheet with the preview card attached. Use Save card to post the image yourself.</div>
    </div>
  </div>
</div>

<!-- ══ ADVANCED FILTERS ══ -->
<div class="mpr-screen" id="mpFilters" role="dialog" aria-modal="true" aria-label="Filters">
  <div class="mpr-lane">
    <div class="mpr-topbar">
        <button type="button" class="mpr-x" onclick="mpClose('mpFilters')" aria-label="Close">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
        <div class="mpr-title">Filters</div>
    </div>
    <div class="mpr-body">
        <div class="mpr-section-label"><i class="ri-hashtag"></i> Category</div>
        <div class="mpf-block">
            <select class="gc-select" id="mpfCat" onchange="updateFilterCount()">
                <option value="">All categories</option>
                <?php foreach ($categories_list as $cat): ?>
                <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="mpr-section-label" style="margin-top:8px;"><i class="ri-calendar-line"></i> Origin year range</div>
        <div class="mpf-block">
            <div class="mpf-row">
                <div><div class="mpf-sub">From</div><input type="text" class="gc-input" id="mpfFrom" placeholder="e.g. 2010" maxlength="4" inputmode="numeric" oninput="this.value=this.value.replace(/\D/g,'');updateFilterCount()"></div>
                <div><div class="mpf-sub">To</div><input type="text" class="gc-input" id="mpfTo" placeholder="e.g. 2020" maxlength="4" inputmode="numeric" oninput="this.value=this.value.replace(/\D/g,'');updateFilterCount()"></div>
            </div>
        </div>
        <div class="mpf-note">Entries with no origin year are hidden while a year range is set.</div>

        <div class="mpr-section-label"><i class="ri-global-line"></i> Platform</div>
        <div class="mpf-block">
            <input type="text" class="gc-input" id="mpfPlat" list="mpfPlatList" placeholder="e.g. Reddit, TikTok, 4chan…" autocomplete="off" oninput="updateFilterCount()">
            <datalist id="mpfPlatList"></datalist>
        </div>

        <div class="mpr-section-label" style="margin-top:14px;"><i class="ri-film-line"></i> Cover media type</div>
        <div class="mpf-block">
            <div class="status-chips" id="mpfKinds">
                <button type="button" class="status-chip selected" data-kind="" onclick="filtersKind(this)">All</button>
                <button type="button" class="status-chip" data-kind="image" onclick="filtersKind(this)"><i class="ri-image-line"></i> Image</button>
                <button type="button" class="status-chip" data-kind="gif" onclick="filtersKind(this)"><i class="ri-gif-line"></i> GIF</button>
                <button type="button" class="status-chip" data-kind="video" onclick="filtersKind(this)"><i class="ri-video-line"></i> Video</button>
            </div>
        </div>
        <div class="mpf-note" style="margin-top:6px;">Tip: use the sort menu (top right) for Most Viewed and Most Discussed.</div>
    </div>
    <div class="mpr-footer">
        <button type="button" class="mpr-btn mpr-btn-cancel" onclick="filtersReset()">Reset</button>
        <button type="button" class="mpr-btn mpr-btn-submit" id="mpfApply" onclick="filtersApply()">Show results</button>
    </div>
  </div>
</div>

<!-- ══ REPORT AN ENTRY (screen 1: reason · screen 2: success) ══ -->
<div class="mpr-screen" id="mprReason" role="dialog" aria-modal="true" aria-label="Report this entry">
  <div class="mpr-lane">
    <div class="mpr-topbar">
        <button type="button" class="mpr-x" onclick="closeArticleReport()" aria-label="Close">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
        <div class="mpr-title">Why are you reporting this entry?</div>
    </div>
    <div class="mpr-body" id="mprScroll">
        <div class="mpr-target"><i class="ri-book-open-line"></i><span id="mprTargetTitle"></span></div>
        <div class="mpr-section-label">Select a reason</div>
        <div id="mprReasonList"></div>
        <div class="mpr-other-wrap" id="mprOtherWrap">
            <textarea class="mpr-other-textarea" id="mprOtherInput" placeholder="Please describe the issue in detail…" maxlength="500" oninput="mprCharCount(this)"></textarea>
            <div class="mpr-char" id="mprCharLabel">0 / 500</div>
        </div>
    </div>
    <div class="mpr-footer">
        <button type="button" class="mpr-btn mpr-btn-cancel" onclick="closeArticleReport()">Cancel</button>
        <button type="button" class="mpr-btn mpr-btn-submit" id="mprSubmitBtn" onclick="submitArticleReport()">Submit Report</button>
    </div>
  </div>
</div>

<div class="mpr-screen" id="mprDone" role="dialog" aria-modal="true" aria-label="Report submitted">
  <div class="mpr-lane">
    <div class="mpr-topbar"><div class="mpr-title">Report Submitted</div></div>
    <div class="mpr-success">
        <div class="mpr-success-icon"><i class="ri-shield-check-line"></i></div>
        <div class="mpr-success-title">Report Submitted</div>
        <div class="mpr-success-desc">Thank you. Our team will review this entry and decide whether it stays up or is taken down. The author will not know who reported it.</div>
        <button type="button" class="mpr-btn mpr-btn-done" onclick="closeArticleReport()">Done</button>
    </div>
  </div>
</div>
<div class="mpr-toast" id="mprToast"></div>

<script>
const CURRENT_USER = {
    id: <?= (int)$current_user ?>,
    username: <?= json_encode($username) ?>,
    avatar: <?= json_encode($profile_pic) ?>
};
const OPEN_ON_LOAD = <?= (int)$open_id ?>;
const FOLLOWED_IDS = <?= json_encode($followed_ids) ?>;   // entries you follow (from the server)
const FROM_GROUP   = <?= $from_group ? 'true' : 'false' ?>;

/* Opened from the group page → blue theme. Remember it in the address bar (?from=group)
   so a refresh keeps the blue theme instead of falling back to red. */
(function(){
    try {
        const url = new URL(window.location.href);
        if (FROM_GROUP && url.searchParams.get('from') !== 'group') {
            url.searchParams.set('from', 'group');
            window.history.replaceState({}, '', url);
        }
    } catch(e){}
})();

// Ordered segment definitions, mirrors $segment_defs / SEGMENT_KEYS on the server.
const SEGMENT_DEFS  = <?= json_encode($segment_defs) ?>;
const SEGMENT_ORDER = Object.keys(SEGMENT_DEFS);

document.getElementById('footerYear').textContent = new Date().getFullYear();
document.getElementById('entriesList').innerHTML = skRowsHTML(6);

let articles      = [];
let activeFilter  = 'all';
let activeSort    = 'newest';
let openEntryId   = null;
let dupArticleId  = null;
let pendingReturnEntryId = null; // set when editing from within the detail modal, so cancel returns to it
let loadedDetails = new Set();
let listLoaded    = false;   // false → the entries area (and search results) show skeleton rows

/* view mode: list · timeline */
let viewMode = 'list';
try { if (localStorage.getItem('mp_view') === 'timeline') viewMode = 'timeline'; } catch(e){}
let adv      = { category:'', yFrom:'', yTo:'', platform:'', kind:'' };
let advDraft = { category:'', yFrom:'', yTo:'', platform:'', kind:'' };
let galleryItems = [];            // [{url, data, type, caption}]
let sourceItems  = [];            // [{id, url, title, type}]
const GAL_MAX = 10, SRC_MAX = 8;
const histState = {};             // "articleId:editIndex" -> expanded?

const statusMap = {
    trending:  { label:'Trending',  icon:'ri-fire-fill'      },
    rising:    { label:'Rising',    icon:'ri-arrow-up-line'  },
    classic:   { label:'Classic',   icon:'ri-building-line'  },
    dead:      { label:'Dead Meme', icon:'ri-skull-line'     },
    revived:   { label:'Revived',   icon:'ri-refresh-line'   },
    legendary: { label:'Legendary', icon:'ri-vip-crown-line' },
};

const SRC_TYPES = {
    first_post: { label:'First post',     icon:'ri-seedling-line'     },
    news:       { label:'News article',   icon:'ri-newspaper-line'    },
    kym:        { label:'Know Your Meme', icon:'ri-book-open-line'    },
    video:      { label:'Video',          icon:'ri-play-circle-line'  },
    archive:    { label:'Archive',        icon:'ri-archive-line'      },
    other:      { label:'Other',          icon:'ri-link'              }
};

/* ══════════════════════════════════════════════════════
   API
   ══════════════════════════════════════════════════════ */
async function apiFetch(action, method='GET', body=null){
    try{
        const opts = { method, headers:{'Content-Type':'application/json'} };
        if (body) opts.body = JSON.stringify(body);
        const res = await fetch(`api_articles.php?action=${action}`, opts);
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return await res.json();
    }catch(err){
        console.error(`API error [${action}]:`, err);
        toast('Server error — please try again','error','ri-error-warning-line');
        return null;
    }
}

function skRowsHTML(n){
    let h = '';
    for (let i = 0; i < n; i++) h += '<div class="sk-row"><div class="sk-row-thumb sk-shimmer"></div><div class="sk-row-lines"><div class="sk-shimmer" style="width:60px;height:9px;"></div><div class="sk-shimmer" style="width:65%;height:13px;"></div><div class="sk-shimmer" style="width:85%;height:9px;"></div><div class="sk-shimmer" style="width:40%;height:9px;"></div></div></div>';
    return h;
}
async function loadArticles(){
    const data = await apiFetch('list');
    if (data) articles = data;
    listLoaded = true;
    renderList();
    if (OPEN_ON_LOAD) openEntry(OPEN_ON_LOAD);
}
async function loadArticlesQuiet(){
    const data = await apiFetch('list');
    if (data) { articles = data; renderList(); }
}

function toast(msg,type='info',icon='ri-information-line'){
    const c = document.getElementById('toastContainer');
    const t = document.createElement('div');
    t.className = 'toast '+type;
    const i = document.createElement('i'); i.className = icon;
    const s = document.createElement('span'); s.textContent = msg;   // never inject HTML into a toast
    t.appendChild(i); t.appendChild(s);
    c.appendChild(t);
    setTimeout(()=>t.remove(), 3200);
}

/* ══════════════════════════════════════════════════════
   SMALL HELPERS (media kind · years · gallery · follow · sources)
   ══════════════════════════════════════════════════════ */
function trimTxt(s){ return String(s == null ? '' : s).trim(); }

function kindOfUrl(url, type){
    if (type === 'video') return 'video';
    if (type === 'gif') return 'gif';
    const raw = String(url || '');
    if (/^data:video/i.test(raw)) return 'video';
    if (/^data:image\/gif/i.test(raw)) return 'gif';
    const u = raw.split('?')[0].toLowerCase();
    if (/\.(mp4|webm|mov|m4v|ogv)$/.test(u)) return 'video';
    if (/\.gif$/.test(u)) return 'gif';
    return 'image';
}
function mediaKindOf(a){ return kindOfUrl(a.media_url, a.media_type); }
function viewsOf(a){ return Number(a.views ?? a.view_count ?? 0) || 0; }
function commentsOf(a){
    if (a.comment_count != null) return Number(a.comment_count) || 0;
    return Array.isArray(a.comments) ? a.comments.length : 0;
}
function yearOf(a){
    const y = parseInt(String(a.origin_year || '').trim(), 10);
    return (y >= 1900 && y <= 2100) ? y : null;
}
function normalizeGallery(g){
    if (!g) return [];
    if (typeof g === 'string') { try { g = JSON.parse(g); } catch(e){ return []; } }
    if (!Array.isArray(g)) g = Object.values(g || {});
    return g.map(it => {
        if (typeof it === 'string') it = { url: it };
        if (!it || !it.url) return null;
        return { url: it.url, type: it.type || it.media_type || kindOfUrl(it.url), caption: it.caption || '' };
    }).filter(Boolean);
}

/* follow */
const followSet = new Set((FOLLOWED_IDS || []).map(Number));
function isFollowing(a){ return !!a && followSet.has(Number(a.id)); }

/* sources */
function safeUrl(u){
    u = trimTxt(u);
    if (!u) return '';
    if (!/^https?:\/\//i.test(u)) u = 'https://' + u;
    try { const x = new URL(u); return (x.protocol === 'http:' || x.protocol === 'https:') && x.hostname.indexOf('.') > 0 ? x.href : ''; } catch(e){ return ''; }
}
function domainOf(href){ try { return new URL(href).hostname.replace(/^www\./, ''); } catch(e){ return ''; } }
function normalizeSources(s){
    if (!s) return [];
    if (typeof s === 'string') { try { s = JSON.parse(s); } catch(e){ return []; } }
    if (!Array.isArray(s)) s = Object.values(s || {});
    return s.map(it => {
        if (typeof it === 'string') it = { url: it };
        if (!it || !it.url) return null;
        return {
            id: it.id ?? null,
            url: it.url,
            title: it.title || '',
            type: SRC_TYPES[it.type] ? it.type : 'other',
            added_by_id: it.added_by_id ?? it.user_id ?? null,
            added_by_name: it.added_by_name || it.username || '',
            verified_count: Number(it.verified_count ?? (Array.isArray(it.verified_by) ? it.verified_by.length : 0)) || 0,
            verified_by_me: !!it.verified_by_me,
            verified: it.verified
        };
    }).filter(Boolean);
}
function isVerifiedSrc(s){ return s.verified != null ? !!s.verified : s.verified_count >= 1; }
function verifiedCountOf(a){
    if (a.verified_sources != null) return Number(a.verified_sources) || 0;
    return normalizeSources(a.sources).filter(isVerifiedSrc).length;
}


/* ══════════════════════════════════════════════════════
   FILTER / SORT / RENDER LIST
   ══════════════════════════════════════════════════════ */
function baseFilter(list){
    if (activeFilter === 'mine')      return list.filter(a => a.creator_id == CURRENT_USER.id);
    if (activeFilter === 'following') return list.filter(isFollowing);
    if (activeFilter !== 'all')       return list.filter(a => a.status === activeFilter);
    return list;
}
function matchesAdv(a, f){
    if (f.category && (a.category || '') !== f.category) return false;
    if (f.yFrom || f.yTo) {
        const y = yearOf(a);
        if (y === null) return false;
        if (f.yFrom && y < +f.yFrom) return false;
        if (f.yTo && y > +f.yTo) return false;
    }
    if (f.platform && !(a.origin_platform || '').toLowerCase().includes(f.platform.toLowerCase())) return false;
    if (f.kind && mediaKindOf(a) !== f.kind) return false;
    return true;
}
function advCount(f){
    return (f.category ? 1 : 0) + ((f.yFrom || f.yTo) ? 1 : 0) + (f.platform ? 1 : 0) + (f.kind ? 1 : 0);
}
function getFiltered(sourceList){
    let list = baseFilter([...(sourceList || articles)]).filter(a => matchesAdv(a, adv));
    const byNewest = (a,b) => new Date(b.created_at) - new Date(a.created_at);
    switch (activeSort) {
        case 'newest':    list.sort(byNewest); break;
        case 'oldest':    list.sort((a,b)=> new Date(a.created_at) - new Date(b.created_at)); break;
        case 'edits':     list.sort((a,b)=> (b.edit_count||0) - (a.edit_count||0)); break;
        case 'views':     list.sort((a,b)=> (viewsOf(b) - viewsOf(a)) || byNewest(a,b)); break;
        case 'discussed': list.sort((a,b)=> (commentsOf(b) - commentsOf(a)) || byNewest(a,b)); break;
        case 'az':        list.sort((a,b)=> a.title.localeCompare(b.title)); break;
    }
    return list;
}


function renderList(){
    const list = getFiltered();
    const wrap = document.getElementById('entriesList');
    const empty = document.getElementById('emptyState');
    document.getElementById('visibleCount').textContent = list.length;
    renderAdvSummary();

    if (list.length === 0) {
        wrap.innerHTML = '';
        wrap.classList.remove('is-timeline');
        let title, desc, showBtn = true, showTips = true;
        if (!articles.length) {
            title = 'No entries yet';
            desc = 'Memepedia is blank — be the first to document a meme, a piece of internet slang, or a viral moment.';
        } else if (activeFilter === 'following' && !advCount(adv)) {
            title = 'Nothing followed yet';
            desc = 'Tap Follow on an entry to get notified when it is edited, commented on, or changes status.';
            showBtn = false; showTips = false;
        } else {
            title = 'No matching entries';
            desc = advCount(adv) ? 'Try changing or clearing your filters.' : 'Try a different filter or sort order.';
        }
        document.getElementById('emptyTitle').textContent = title;
        document.getElementById('emptyDesc').textContent = desc;
        document.getElementById('emptyBtn').style.display = showBtn ? '' : 'none';
        document.getElementById('emptyTips').style.display = (showTips && !articles.length) ? '' : 'none';
        empty.style.display = 'flex';
    } else {
        empty.style.display = 'none';
        if (viewMode === 'timeline') {
            wrap.classList.add('is-timeline');
            wrap.innerHTML = buildTimeline(list);
        } else {
            wrap.classList.remove('is-timeline');
            wrap.innerHTML = list.map(a => buildRow(a)).join('');
        }
    }
    renderSide();
}

function buildRow(a){
    const st = statusMap[a.status] || statusMap.rising;
    const mediaHtml = a.media_url
        ? (a.media_type === 'video'
            ? `<video src="${escHtml(a.media_url)}" muted playsinline autoplay loop></video>`
            : `<img src="${escHtml(a.media_url)}" alt="${escHtml(a.title)}" loading="lazy">`)
        : `<div class="thumb-no-img">${getEmoji(a.title)}</div>`;
    const mediaTag = a.media_type ? `<div class="thumb-media-tag">${escHtml(mediaKindOf(a).toUpperCase())}</div>` : '';
    const dateStr = formatDate(a.created_at).date;
    const mine = a.creator_id == CURRENT_USER.id;
    const views = viewsOf(a), cmts = commentsOf(a), vsrc = verifiedCountOf(a);
    return `
    <div class="entry-row" data-id="${a.id}" onclick="openEntry(${a.id})">
        <div class="entry-thumb">${mediaHtml}${mediaTag}</div>
        <div class="entry-info">
            <div class="entry-status-row">
                <span class="status-pill"><i class="${st.icon}"></i> ${st.label}</span>
                <span class="entry-cat-tag">${escHtml(a.category || 'Uncategorized')}</span>
            </div>
            <div class="entry-title">${escHtml(a.title)}</div>
            <div class="entry-summary">${escHtml(a.summary || '')}</div>
            <div class="entry-meta">
                <span><i class="ri-edit-line"></i> ${a.edit_count||0}</span>
                ${views ? `<span><i class="ri-eye-line"></i> ${fmtNum(views)}</span>` : ''}
                ${cmts ? `<span><i class="ri-chat-3-line"></i> ${fmtNum(cmts)}</span>` : ''}
                ${vsrc ? `<span class="ver-tag" title="Verified sources"><i class="ri-shield-check-line"></i> ${vsrc}</span>` : ''}
                <span><i class="ri-time-line"></i> ${dateStr}</span>
                ${isFollowing(a) ? '<span class="fol-tag" title="Following"><i class="ri-bookmark-fill"></i></span>' : ''}
                ${mine ? '<span class="mine-tag"><i class="ri-user-fill"></i> Mine</span>' : ''}
            </div>
        </div>
        ${mine ? `<div class="row-actions" onclick="event.stopPropagation()">
            <button type="button" class="row-icon-btn del" title="Delete entry" onclick="deleteEntry(${a.id}, this)"><i class="ri-delete-bin-line"></i></button>
        </div>` : ''}
    </div>`;
}

/* ── Timeline view: grouped by origin_year ── */
function buildTimeline(list){
    const groups = new Map(), unknown = [];
    list.forEach(a => {
        const y = yearOf(a);
        if (y === null) unknown.push(a);
        else { if (!groups.has(y)) groups.set(y, []); groups.get(y).push(a); }
    });
    const years = [...groups.keys()].sort((a,b)=> activeSort === 'oldest' ? a - b : b - a);
    let h = '<div class="tl">';
    years.forEach(y => { h += tlGroup(String(y), groups.get(y), false); });
    if (unknown.length) h += tlGroup('Year unknown', unknown, true);
    return h + '</div>';
}
function tlGroup(label, items, unk){
    return `<div class="tl-group${unk ? ' unk' : ''}">
        <div class="tl-year"><b>${escHtml(label)}</b><small>${items.length} ${items.length === 1 ? 'meme' : 'memes'}</small></div>
        <div class="tl-items">${items.map(a => buildRow(a)).join('')}</div>
    </div>`;
}

function setView(v){
    viewMode = (v === 'timeline') ? 'timeline' : 'list';
    try { localStorage.setItem('mp_view', viewMode); } catch(e){}
    updateViewUI();
    renderList();
    window.scrollTo({ top:0, behavior:'smooth' });
}
function updateViewUI(){
    document.getElementById('viewList').classList.toggle('active', viewMode === 'list');
    document.getElementById('viewTl').classList.toggle('active', viewMode === 'timeline');
}

function setFilter(btn, val){
    activeFilter = val;
    document.querySelectorAll('#filterChips .chip').forEach(c => c.classList.remove('active'));
    btn.classList.add('active');
    try { btn.scrollIntoView({ inline:'nearest', block:'nearest' }); } catch(e){}
    renderList();
}
/* used by the right-hand "Meme statuses" card */
function mpFilter(val){
    const btn = document.querySelector('#filterChips .chip[data-filter="'+val+'"]');
    if (btn) setFilter(btn, val);
    window.scrollTo({ top:0, behavior:'smooth' });
}

function toggleSortMenu(){
    const menu = document.getElementById('sortMenu');
    const backdrop = document.getElementById('sortBackdrop');
    const willOpen = !menu.classList.contains('open');
    menu.classList.toggle('open', willOpen);
    const tb = document.querySelector('.toolbar'); if (tb) tb.classList.toggle('sort-open', willOpen);
    if (backdrop) backdrop.classList.toggle('open', willOpen);
    document.getElementById('sortBtn').classList.toggle('active', willOpen);
}
function closeSortMenu(){
    const menu = document.getElementById('sortMenu');
    const backdrop = document.getElementById('sortBackdrop');
    menu.classList.remove('open');
    const tb = document.querySelector('.toolbar'); if (tb) tb.classList.remove('sort-open');
    if (backdrop) backdrop.classList.remove('open');
    document.getElementById('sortBtn').classList.toggle('active', activeSort !== 'newest');
}
function pickSort(el, val){
    activeSort = val;
    document.querySelectorAll('.sort-opt').forEach(o => o.classList.remove('active'));
    el.classList.add('active');
    closeSortMenu();
    renderList();
}
document.addEventListener('click', (e) => {
    const wrap = document.querySelector('.toolbar-sort');
    const btn = document.getElementById('sortBtn');
    const backdrop = document.getElementById('sortBackdrop');
    if (wrap && !wrap.contains(e.target) && !(btn && btn.contains(e.target)) && e.target !== backdrop) closeSortMenu();
});

async function deleteEntry(id, btn){
    if (!confirm('Delete this entry permanently? This cannot be undone.')) return;
    const result = await apiFetch('delete', 'POST', { id });
    if (result?.success) {
        articles = articles.filter(a => a.id != id);
        renderList();
        toast('Entry deleted', 'success', 'ri-check-line');
    }
}

/* ══════════════════════════════════════════════════════
   ADVANCED FILTERS (category · year range · platform · media type)
   ══════════════════════════════════════════════════════ */
const KIND_LABEL = { image:'Image', gif:'GIF', video:'Video' };

function renderAdvSummary(){
    const el = document.getElementById('advSummary');
    const n = advCount(adv);
    const btn = document.getElementById('filterBtn'), badge = document.getElementById('filterBadge');
    if (badge) badge.textContent = n;
    if (btn) btn.classList.toggle('has-badge', n > 0);
    if (!el) return;
    if (!n) { el.classList.remove('show'); el.innerHTML = ''; return; }
    const chips = [];
    const chip = (label, part) => `<span class="adv-chip">${escHtml(label)}<button type="button" onclick="removeAdv('${part}')" aria-label="Remove filter"><i class="ri-close-line"></i></button></span>`;
    if (adv.category) chips.push(chip(adv.category, 'category'));
    if (adv.yFrom || adv.yTo) {
        const lbl = (adv.yFrom && adv.yTo) ? (adv.yFrom + '–' + adv.yTo) : (adv.yFrom ? 'From ' + adv.yFrom : 'Until ' + adv.yTo);
        chips.push(chip(lbl, 'years'));
    }
    if (adv.platform) chips.push(chip(adv.platform, 'platform'));
    if (adv.kind) chips.push(chip(KIND_LABEL[adv.kind] || adv.kind, 'kind'));
    el.innerHTML = chips.join('') + '<button type="button" class="adv-clear" onclick="clearAdv()">Clear all</button>';
    el.classList.add('show');
}
function removeAdv(part){
    if (part === 'category') adv.category = '';
    else if (part === 'years') { adv.yFrom = ''; adv.yTo = ''; }
    else if (part === 'platform') adv.platform = '';
    else if (part === 'kind') adv.kind = '';
    renderList();
}
function clearAdv(){ adv = { category:'', yFrom:'', yTo:'', platform:'', kind:'' }; renderList(); }

function filtersRead(){
    const kindBtn = document.querySelector('#mpfKinds .status-chip.selected');
    advDraft = {
        category: mprEl('mpfCat').value || '',
        yFrom:    String(mprEl('mpfFrom').value).replace(/\D/g,'').slice(0,4),
        yTo:      String(mprEl('mpfTo').value).replace(/\D/g,'').slice(0,4),
        platform: mprEl('mpfPlat').value.trim(),
        kind:     kindBtn ? (kindBtn.dataset.kind || '') : ''
    };
    return advDraft;
}
function updateFilterCount(){
    const f = filtersRead();
    const n = baseFilter([...articles]).filter(a => matchesAdv(a, f)).length;
    mprEl('mpfApply').textContent = 'Show ' + n + ' ' + (n === 1 ? 'entry' : 'entries');
}
function filtersKind(btn){
    document.querySelectorAll('#mpfKinds .status-chip').forEach(c => c.classList.remove('selected'));
    btn.classList.add('selected');
    updateFilterCount();
}
function filtersSetUI(f){
    mprEl('mpfCat').value = f.category || '';
    mprEl('mpfFrom').value = f.yFrom || '';
    mprEl('mpfTo').value = f.yTo || '';
    mprEl('mpfPlat').value = f.platform || '';
    document.querySelectorAll('#mpfKinds .status-chip').forEach(c => c.classList.toggle('selected', (c.dataset.kind || '') === (f.kind || '')));
}
function openFilters(){
    const plats = new Map();
    articles.forEach(a => { const p = (a.origin_platform || '').trim(); if (p && !plats.has(p.toLowerCase())) plats.set(p.toLowerCase(), p); });
    mprEl('mpfPlatList').innerHTML = [...plats.values()].sort((a,b)=> a.localeCompare(b)).map(p => `<option value="${escHtml(p)}"></option>`).join('');
    filtersSetUI(adv);
    updateFilterCount();
    mprEl('mpFilters').querySelector('.mpr-body').scrollTop = 0;
    mprOpenScreen('mpFilters');
}
function filtersReset(){
    filtersSetUI({ category:'', yFrom:'', yTo:'', platform:'', kind:'' });
    updateFilterCount();
}
function filtersApply(){
    const f = filtersRead();
    if (f.yFrom && f.yTo && +f.yFrom > +f.yTo) { const t = f.yFrom; f.yFrom = f.yTo; f.yTo = t; }
    adv = { ...f };
    mpClose('mpFilters');
    renderList();
    window.scrollTo({ top:0, behavior:'smooth' });
}

/* ══════════════════════════════════════════════════════
   RIGHT COLUMN (stats / recently added) + collapsible cards
   ══════════════════════════════════════════════════════ */
function renderSide(){
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
    set('gsEntries', fmtNum(articles.length));
    set('gsEdits',   fmtNum(articles.reduce((s,a)=> s + (Number(a.edit_count)||0), 0)));
    set('gsPeople',  fmtNum(new Set(articles.map(a => a.creator_id).filter(Boolean)).size));
    set('gsMine',    fmtNum(articles.filter(a => a.creator_id == CURRENT_USER.id).length));
    set('gsFollow',  fmtNum(articles.filter(isFollowing).length));
    Object.keys(statusMap).forEach(k => set('gsC_'+k, articles.filter(a => a.status === k).length));

    const recent = [...articles].sort((a,b)=> new Date(b.created_at) - new Date(a.created_at)).slice(0,5);
    const el = document.getElementById('gsRecentList');
    if (!el) return;
    el.innerHTML = recent.length ? recent.map(a => `
        <button type="button" class="gs-th" onclick="openEntry(${a.id})">
            <span class="gs-th-img">${a.media_url && a.media_type !== 'video' ? `<img src="${escHtml(a.media_url)}" alt="" loading="lazy">` : getEmoji(a.title)}</span>
            <span class="gs-th-tx"><b>${escHtml(a.title)}</b><small>${escHtml(timeSince(a.created_at))} · ${(statusMap[a.status]||statusMap.rising).label}</small></span>
        </button>`).join('') : '<div class="gs-note">Nothing here yet.</div>';
}
window.gsToggle = function(btn){
    const card = btn && btn.closest ? btn.closest('.gs-card') : null; if (!card) return;
    const c = card.classList.toggle('collapsed');
    btn.setAttribute('aria-expanded', c ? 'false' : 'true');
    try { localStorage.setItem('mp_gs_' + (card.getAttribute('data-k')||''), c ? '1' : '0'); } catch(e){}
};
(function(){
    document.querySelectorAll('.gs-card').forEach(card => {
        try {
            if (localStorage.getItem('mp_gs_' + (card.getAttribute('data-k')||'')) === '1') {
                card.classList.add('collapsed');
                const b = card.querySelector('.gs-bar'); if (b) b.setAttribute('aria-expanded','false');
            }
        } catch(e){}
    });
})();

/* ══════════════════════════════════════════════════════
   FOLLOW / WATCHLIST
   Followers are notified (on the main notifications page) when a followed entry is edited, commented on, or changes status.
   ══════════════════════════════════════════════════════ */
function updateFollowUI(a){
    const on = isFollowing(a);
    const btn = document.getElementById('entryFollowBtn');
    if (btn) {
        btn.classList.toggle('on', on);
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        btn.innerHTML = `<i class="${on ? 'ri-bookmark-fill' : 'ri-bookmark-line'}"></i><span>${on ? 'Following' : 'Follow'}</span>`;
    }
    const ic = document.getElementById('mpMenuFollowIc'), tx = document.getElementById('mpMenuFollowTx');
    if (ic) ic.className = (on ? 'ri-bookmark-fill' : 'ri-bookmark-line') + ' mpr-row-icon';
    if (tx) tx.textContent = on ? 'Unfollow this entry' : 'Follow this entry';
}

async function toggleFollow(id){
    id = id || openEntryId;
    const a = articles.find(x => x.id == id);
    if (!a) return;
    const prev = isFollowing(a), next = !prev;
    if (next) followSet.add(Number(a.id)); else followSet.delete(Number(a.id));
    updateFollowUI(a); renderSide();
    const btn = document.getElementById('entryFollowBtn');
    if (btn) btn.disabled = true;
    let ok = false;
    try {
        const res = await fetch(location.pathname + '?action=follow', {
            method:'POST', headers:{'Content-Type':'application/json'}, credentials:'same-origin',
            body: JSON.stringify({ article_id: a.id, follow: next })
        });
        const d = await res.json();
        ok = !!(d && d.success);
        if (ok && typeof d.following === 'boolean') { if (d.following) followSet.add(Number(a.id)); else followSet.delete(Number(a.id)); }
    } catch (e) { ok = false; }
    if (btn) btn.disabled = false;
    if (ok) {
        toast(isFollowing(a) ? "Following — you'll be notified of edits, comments and status changes" : 'Unfollowed', 'success', isFollowing(a) ? 'ri-bookmark-fill' : 'ri-bookmark-line');
    } else {
        if (prev) followSet.add(Number(a.id)); else followSet.delete(Number(a.id));   // roll back
        toast("Couldn't update follow — please try again", 'error', 'ri-error-warning-line');
    }
    if (openEntryId == a.id) updateFollowUI(a);
    renderSide();
    if (activeFilter === 'following') renderList();
}

/* ══════════════════════════════════════════════════════
   SEARCH MODAL
   ══════════════════════════════════════════════════════ */
function openSearch(){
    openModal('modal-search');
    document.getElementById('searchInput').value = '';
    document.getElementById('searchClearX').classList.remove('show');
    renderSearchResults('');
    setTimeout(()=> document.getElementById('searchInput').focus(), 260);
}
function clearSearch(){
    document.getElementById('searchInput').value = '';
    document.getElementById('searchClearX').classList.remove('show');
    renderSearchResults('');
    document.getElementById('searchInput').focus();
}
function runSearch(q){
    document.getElementById('searchClearX').classList.toggle('show', q.trim().length > 0);
    renderSearchResults(q);
}
function renderSearchResults(q){
    const wrap = document.getElementById('searchResultsWrap');
    const query = q.trim().toLowerCase();
    if (!listLoaded) { wrap.innerHTML = skRowsHTML(5); return; }

    if (!query) {
        if (!articles.length) {
            wrap.innerHTML = `<div class="search-empty-msg"><i class="ri-book-open-line"></i>Start typing to search Memepedia</div>`;
            return;
        }
        const recent = [...articles].sort((a,b)=> new Date(b.created_at)-new Date(a.created_at)).slice(0,8);
        wrap.innerHTML = `<div class="search-recents-label">Recently Added</div>` + recent.map(a => buildRow(a)).join('');
        return;
    }

    const results = articles.filter(a =>
        a.title.toLowerCase().includes(query) ||
        (a.summary||'').toLowerCase().includes(query) ||
        (a.category||'').toLowerCase().includes(query) ||
        normalizeTags(a.tags).some(t => t.toLowerCase().includes(query))
    );

    if (!results.length) {
        wrap.innerHTML = `<div class="search-empty-msg"><i class="ri-search-eye-line"></i>No entries found for "${escHtml(q)}"</div>`;
        return;
    }
    wrap.innerHTML = `<div class="search-recents-label">${results.length} result${results.length===1?'':'s'}</div>` + results.map(a => buildRow(a)).join('');
}
function openEntryFromSearch(id){
    closeModal('modal-search');
    openEntry(id);
}
document.addEventListener('click', function(e){
    const row = e.target.closest('.entry-row');
    if (!row) return;
    if (row.closest('#modal-search')) {
        e.stopPropagation();
        openEntryFromSearch(parseInt(row.dataset.id));
    }
}, true);

/* ══════════════════════════════════════════════════════
   MODAL HELPERS
   ══════════════════════════════════════════════════════ */
function openModal(id){
    const m = document.getElementById(id);
    if (!m) return;
    m.classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeModal(id){
    const m = document.getElementById(id);
    if (!m) return;
    m.classList.remove('active');
    if (!document.querySelector('.modal-overlay.active')) document.body.style.overflow = '';
}

/* ══════════════════════════════════════════════════════
   CREATE / EDIT
   ══════════════════════════════════════════════════════ */
let selectedStatus = 'rising', mediaData = null, mediaType = null, editingMediaUrl = null;
let extraMediaData     = { 1:null, 2:null, 3:null }; // freshly-picked extra images (base64)
let extraMediaExisting = { 1:null, 2:null, 3:null }; // existing extra image urls when editing

const REMOVE_BTN = '<button type="button" class="mu-remove" onclick="removeMedia(event)"><i class="ri-close-line"></i></button>';
const removeExtraBtn = n => `<button type="button" class="mu-remove" onclick="removeExtraMedia(event,${n})"><i class="ri-close-line"></i></button>`;

let cmSkelShown = false;
function cmSkelOnce(){
    const sk = document.getElementById('cmSkel'), real = document.getElementById('cmReal');
    if (cmSkelShown) { sk.classList.remove('show'); real.classList.remove('hide'); return; }
    cmSkelShown = true;
    sk.classList.add('show'); real.classList.add('hide');
    setTimeout(() => { sk.classList.remove('show'); real.classList.remove('hide'); const t = document.getElementById('cm_title'); if (t) t.focus(); }, 550);
}
function openCreate(){
    document.getElementById('cmTitle').textContent = 'New Meme Entry';
    document.getElementById('cmSubmit').innerHTML = '<i class="ri-check-line"></i> Publish Entry';
    document.getElementById('cm_edit_id').value = '';
    pendingReturnEntryId = null;
    clearCreateForm();
    openModal('modal-create');
    cmSkelOnce();
    document.getElementById('modal-create').scrollTop = 0;
    setTimeout(()=> document.getElementById('cm_title').focus(), 260);
}
function closeCreate(){
    closeModal('modal-create');
    clearCreateForm();
    if (pendingReturnEntryId !== null) {
        const id = pendingReturnEntryId;
        pendingReturnEntryId = null;
        openEntry(id);
    }
}
function clearCreateForm(){
    ['cm_title','cm_summary','cm_year','cm_platform','cm_tags'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('cm_category').value = '';
    document.getElementById('dupWarning').classList.remove('show');
    mediaData = null; mediaType = null; editingMediaUrl = null;
    document.getElementById('mediaPreview').style.display = 'none';
    document.getElementById('mediaPreview').innerHTML = REMOVE_BTN;
    document.getElementById('cm_media').value = '';
    SEGMENT_ORDER.forEach(key => { const el = document.getElementById('seg_'+key); if (el) el.value = ''; });
    for (let n=1; n<=3; n++){
        extraMediaData[n] = null; extraMediaExisting[n] = null;
        const p = document.getElementById('extraPreview'+n);
        if (p) { p.style.display='none'; p.innerHTML = removeExtraBtn(n); }
        const inp = document.getElementById('extra_media_'+n);
        if (inp) inp.value = '';
    }
    galleryItems = [];
    renderGalleryEditor();
    sourceItems = [];
    renderSourceEditor();
    document.querySelectorAll('#statusChips .status-chip').forEach(c => c.classList.toggle('selected', c.dataset.val === 'rising'));
    selectedStatus = 'rising';
}
function selectStatus(btn){
    document.querySelectorAll('#statusChips .status-chip').forEach(c => c.classList.remove('selected'));
    btn.classList.add('selected');
    selectedStatus = btn.dataset.val;
}
function checkDuplicate(val){
    const v = val.trim().toLowerCase();
    const dup = articles.find(a => a.title.toLowerCase() === v);
    const warn = document.getElementById('dupWarning');
    if (dup && document.getElementById('cm_edit_id').value != dup.id) {
        document.getElementById('dupName').textContent = dup.title;
        dupArticleId = dup.id;
        warn.classList.add('show');
    } else {
        warn.classList.remove('show');
        dupArticleId = null;
    }
}
function openDuplicate(){
    const id = dupArticleId;
    closeCreate();
    if (id !== null) openEntry(id);
}
function previewMedia(input){
    const file = input.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function(e){
        mediaData = e.target.result;
        mediaType = file.type.startsWith('video') ? 'video' : 'image';
        const preview = document.getElementById('mediaPreview');
        preview.innerHTML = REMOVE_BTN + (mediaType === 'video'
            ? `<video src="${mediaData}" controls></video>`
            : `<img src="${mediaData}" alt="Preview">`);
        preview.style.display = 'block';
    };
    reader.readAsDataURL(file);
}
function handleDrop(e){
    e.preventDefault();
    document.getElementById('mediaUploader').classList.remove('dragover');
    const file = e.dataTransfer.files[0];
    if (file) previewMedia({ files:[file] });
}
function removeMedia(e){
    e.stopPropagation(); e.preventDefault();
    mediaData = null; mediaType = null; editingMediaUrl = null;
    document.getElementById('mediaPreview').style.display = 'none';
    document.getElementById('mediaPreview').innerHTML = REMOVE_BTN;
    document.getElementById('cm_media').value = '';
}

function previewExtraMedia(input, n){
    const file = input.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function(e){
        extraMediaData[n] = e.target.result;
        const p = document.getElementById('extraPreview'+n);
        p.innerHTML = removeExtraBtn(n) + `<img src="${e.target.result}" alt="Preview">`;
        p.style.display = 'block';
    };
    reader.readAsDataURL(file);
}
function handleExtraDrop(e, n){
    e.preventDefault();
    const el = document.getElementById('extraUploader'+n);
    if (el) el.classList.remove('dragover');
    const file = e.dataTransfer.files[0];
    if (file) previewExtraMedia({ files:[file] }, n);
}
function removeExtraMedia(e, n){
    e.stopPropagation(); e.preventDefault();
    extraMediaData[n] = null; extraMediaExisting[n] = null;
    const p = document.getElementById('extraPreview'+n);
    p.style.display = 'none';
    p.innerHTML = removeExtraBtn(n);
    const input = document.getElementById('extra_media_'+n);
    if (input) input.value = '';
}

/* ── Example gallery editor ── */
function addGalleryFiles(files){
    const arr = Array.from(files || []);
    for (const f of arr) {
        if (galleryItems.length >= GAL_MAX) { toast('Up to ' + GAL_MAX + ' gallery items', 'error', 'ri-error-warning-line'); break; }
        if (!/^(image|video)\//.test(f.type)) continue;
        if (f.size > 50 * 1024 * 1024) { toast(f.name + ' is over 50MB', 'error', 'ri-error-warning-line'); continue; }
        const item = { url:null, data:null, type: f.type.startsWith('video') ? 'video' : (f.type === 'image/gif' ? 'gif' : 'image'), caption:'' };
        galleryItems.push(item);
        const r = new FileReader();
        r.onload = e => { item.data = e.target.result; renderGalleryEditor(); };
        r.readAsDataURL(f);
    }
    renderGalleryEditor();
}
function handleGalleryDrop(e){
    e.preventDefault();
    const el = document.getElementById('galUploader'); if (el) el.classList.remove('dragover');
    addGalleryFiles(e.dataTransfer.files);
}
function removeGalleryItem(i, e){
    if (e) { e.stopPropagation(); e.preventDefault(); }
    galleryItems.splice(i, 1);
    renderGalleryEditor();
}
function setGalleryCaption(i, v){ if (galleryItems[i]) galleryItems[i].caption = v; }
function renderGalleryEditor(){
    const box = document.getElementById('galEditor'), cnt = document.getElementById('galEdCount');
    if (!box) return;
    box.innerHTML = galleryItems.map((g, i) => {
        const src = g.data || g.url;
        const kind = kindOfUrl(src, g.type);
        const media = !src ? '<i class="ri-loader-4-line"></i>'
            : (kind === 'video' ? `<video src="${escHtml(src)}" muted playsinline preload="metadata"></video>` : `<img src="${escHtml(src)}" alt="">`);
        return `<div class="gal-ed-cell">
            <span class="gal-ed-tag">${kind.toUpperCase()}</span>
            <button type="button" class="mu-remove" onclick="removeGalleryItem(${i}, event)" aria-label="Remove"><i class="ri-close-line"></i></button>
            <div class="gal-ed-media">${media}</div>
            <div class="gal-ed-cap"><input type="text" class="gc-input" maxlength="120" placeholder="Caption (optional)" value="${escHtml(g.caption || '')}" oninput="setGalleryCaption(${i}, this.value)"></div>
        </div>`;
    }).join('');
    if (cnt) cnt.textContent = galleryItems.length ? (galleryItems.length + ' / ' + GAL_MAX + ' items') : '';
}

/* ── Sources & citations editor ── */
function addSourceRow(){
    if (sourceItems.length >= SRC_MAX) { toast('Up to ' + SRC_MAX + ' sources', 'error', 'ri-error-warning-line'); return; }
    sourceItems.push({ id:null, url:'', title:'', type:'first_post' });
    renderSourceEditor();
    const inputs = document.querySelectorAll('#srcEditor .se-url');
    if (inputs.length) inputs[inputs.length - 1].focus();
}
function removeSourceRow(i){ sourceItems.splice(i, 1); renderSourceEditor(); }
function setSrc(i, key, v){ if (sourceItems[i]) sourceItems[i][key] = v; }
function renderSourceEditor(){
    const box = document.getElementById('srcEditor'), btn = document.getElementById('srcAddBtn');
    if (!box) return;
    box.innerHTML = sourceItems.map((s, i) => `
        <div class="se-row">
            <div class="se-top">
                <select class="gc-select" onchange="setSrc(${i}, 'type', this.value)" aria-label="Source type">
                    ${Object.keys(SRC_TYPES).map(k => `<option value="${k}"${s.type === k ? ' selected' : ''}>${escHtml(SRC_TYPES[k].label)}</option>`).join('')}
                </select>
                <button type="button" class="se-x" onclick="removeSourceRow(${i})" aria-label="Remove source"><i class="ri-close-line"></i></button>
            </div>
            <input type="text" class="gc-input se-url" placeholder="https://…" value="${escHtml(s.url || '')}" oninput="setSrc(${i}, 'url', this.value)" autocomplete="off" spellcheck="false" inputmode="url">
            <input type="text" class="gc-input" placeholder="Title (optional)" maxlength="120" value="${escHtml(s.title || '')}" oninput="setSrc(${i}, 'title', this.value)" autocomplete="off">
        </div>`).join('');
    if (btn) btn.disabled = sourceItems.length >= SRC_MAX;
}

function openEditModal(a){
    document.getElementById('cmTitle').textContent = 'Edit Meme Entry';
    document.getElementById('cmSubmit').innerHTML = '<i class="ri-check-line"></i> Save Changes';
    document.getElementById('dupWarning').classList.remove('show');
    document.getElementById('cm_edit_id').value = a.id;

    document.getElementById('cm_title').value = a.title || '';
    document.getElementById('cm_summary').value = a.summary || '';
    document.getElementById('cm_year').value = a.origin_year || '';
    document.getElementById('cm_platform').value = a.origin_platform || '';
    document.getElementById('cm_category').value = a.category || '';
    document.getElementById('cm_tags').value = (normalizeTags(a.tags) || []).join(', ');

    const st = a.status || 'rising';
    document.querySelectorAll('#statusChips .status-chip').forEach(c => c.classList.toggle('selected', c.dataset.val === st));
    selectedStatus = st;

    // Cover — show existing, only replace if the user picks a new file
    mediaData = null; mediaType = null; editingMediaUrl = a.media_url || null;
    const preview = document.getElementById('mediaPreview');
    if (editingMediaUrl) {
        preview.innerHTML = REMOVE_BTN + (a.media_type === 'video'
            ? `<video src="${escHtml(editingMediaUrl)}" controls></video>`
            : `<img src="${escHtml(editingMediaUrl)}" alt="Current cover">`);
        preview.style.display = 'block';
    } else {
        preview.style.display = 'none';
        preview.innerHTML = REMOVE_BTN;
    }

    const segments = a.segments || {};
    SEGMENT_ORDER.forEach(key => {
        const el = document.getElementById('seg_'+key);
        if (el) el.value = segments[key] || '';
    });

    const extraImages = a.extra_images || {};
    for (let n=1; n<=3; n++){
        extraMediaData[n] = null;
        extraMediaExisting[n] = extraImages[n] ?? extraImages[String(n)] ?? null;
        const p = document.getElementById('extraPreview'+n);
        if (extraMediaExisting[n]) {
            p.innerHTML = removeExtraBtn(n) + `<img src="${escHtml(extraMediaExisting[n])}" alt="Extra image ${n}">`;
            p.style.display = 'block';
        } else {
            p.innerHTML = removeExtraBtn(n);
            p.style.display = 'none';
        }
    }

    galleryItems = normalizeGallery(a.gallery).map(g => ({ url:g.url, data:null, type:g.type, caption:g.caption || '' }));
    renderGalleryEditor();
    sourceItems = normalizeSources(a.sources).map(s => ({ id:s.id, url:s.url, title:s.title, type:s.type }));
    renderSourceEditor();

    openModal('modal-create');
    cmSkelOnce();
    document.getElementById('modal-create').scrollTop = 0;
    setTimeout(()=> document.getElementById('cm_title').focus(), 260);
}

/* ── What changed in an edit (stored with the edit so the History tab can show a diff) ── */
function buildChanges(old, now){
    const ch = [];
    const txt = (key, label, o, n) => { o = trimTxt(o); n = trimTxt(n); if (o !== n) ch.push({ key, label, kind:'text', old:o, new:n }); };
    txt('title', 'Title', old.title, now.title);
    txt('summary', 'Summary', old.summary, now.summary);
    SEGMENT_ORDER.forEach(k => txt('seg_' + k, SEGMENT_DEFS[k].label, (old.segments || {})[k], now.segments[k]));
    txt('category', 'Category', old.category, now.category);
    txt('origin_year', 'Origin Year', old.origin_year, now.origin_year);
    txt('origin_platform', 'Platform', old.origin_platform, now.origin_platform);
    txt('status', 'Status', (statusMap[old.status] || {}).label || old.status, (statusMap[now.status] || {}).label || now.status);
    txt('tags', 'Tags', normalizeTags(old.tags).join(', '), now.tags.join(', '));

    if (mediaData) ch.push({ key:'cover', label:'Cover', kind:'note', note:'Cover media replaced' });
    const oldExtra = old.extra_images || {};
    for (let n = 1; n <= 3; n++) {
        const oldU = oldExtra[n] ?? oldExtra[String(n)] ?? null;
        if (extraMediaData[n]) ch.push({ key:'extra'+n, label:'Image '+n, kind:'note', note:'Additional image ' + n + (oldU ? ' replaced' : ' added') });
        else if (oldU && !extraMediaExisting[n]) ch.push({ key:'extra'+n, label:'Image '+n, kind:'note', note:'Additional image ' + n + ' removed' });
    }
    const keptUrls = galleryItems.filter(g => g.url && !g.data).map(g => g.url);
    const added = galleryItems.filter(g => g.data).length;
    const removed = normalizeGallery(old.gallery).filter(o => !keptUrls.includes(o.url)).length;
    if (added || removed) {
        const parts = [];
        if (added) parts.push(added + ' added');
        if (removed) parts.push(removed + ' removed');
        ch.push({ key:'gallery', label:'Gallery', kind:'note', note:'Gallery: ' + parts.join(', ') });
    }
    // sources
    const oldUrls = normalizeSources(old.sources).map(s => safeUrl(s.url));
    const newUrls = now.sources.map(s => s.url);
    const sAdd = newUrls.filter(u => !oldUrls.includes(u)).length;
    const sRem = oldUrls.filter(u => !newUrls.includes(u)).length;
    if (sAdd || sRem) {
        const parts = [];
        if (sAdd) parts.push(sAdd + ' added');
        if (sRem) parts.push(sRem + ' removed');
        ch.push({ key:'sources', label:'Sources', kind:'note', note:'Sources: ' + parts.join(', ') });
    }
    return ch;
}

async function submitArticle(){
    const title = document.getElementById('cm_title').value.trim();
    const summary = document.getElementById('cm_summary').value.trim();
    const editId = parseInt(document.getElementById('cm_edit_id').value) || null;

    if (!title) { toast('Please enter a title','error','ri-error-warning-line'); document.getElementById('cm_title').focus(); return; }
    if (!summary) { toast('Please add a summary','error','ri-error-warning-line'); document.getElementById('cm_summary').focus(); return; }
    if (!editId && !mediaData) { toast('Please upload a cover image','error','ri-error-warning-line'); return; }
    if (!editId) {
        const dup = articles.find(a => a.title.toLowerCase() === title.toLowerCase());
        if (dup) { toast(`"${dup.title}" already exists!`,'error','ri-error-warning-line'); return; }
    }

    // sources: drop empty rows, block bad links
    const sources = [];
    for (const s of sourceItems) {
        if (!trimTxt(s.url)) continue;
        const href = safeUrl(s.url);
        if (!href) { toast('One of your source links doesn\'t look right', 'error', 'ri-error-warning-line'); return; }
        if (!sources.some(x => x.url === href)) sources.push({ id: s.id || null, url: href, title: trimTxt(s.title), type: SRC_TYPES[s.type] ? s.type : 'other' });
    }

    const tags = document.getElementById('cm_tags').value.split(',').map(t => t.trim()).filter(Boolean);

    const segments = {};
    SEGMENT_ORDER.forEach(key => {
        const el = document.getElementById('seg_'+key);
        segments[key] = el ? el.value.trim() : '';
    });
    const extra_images = {
        1: extraMediaData[1] || extraMediaExisting[1] || null,
        2: extraMediaData[2] || extraMediaExisting[2] || null,
        3: extraMediaData[3] || extraMediaExisting[3] || null,
    };
    const category = document.getElementById('cm_category').value;
    const origin_year = document.getElementById('cm_year').value.trim();
    const origin_platform = document.getElementById('cm_platform').value.trim();

    // gallery: kept items send their url, new items send base64 data
    const gallery = galleryItems.filter(g => g.url || g.data).map(g => ({
        url: g.data ? null : g.url,
        media_data: g.data || null,
        media_type: g.type,
        caption: (g.caption || '').trim()
    }));

    // edit diff + auto summary
    let edit_changes, edit_summary;
    if (editId) {
        const old = articles.find(a => a.id == editId) || {};
        edit_changes = buildChanges(old, { title, summary, segments, category, origin_year, origin_platform, status: selectedStatus, tags, sources });
        const labels = [...new Set(edit_changes.map(c => c.label))];
        edit_summary = labels.length
            ? 'Edited ' + labels.slice(0, 4).join(', ') + (labels.length > 4 ? ' +' + (labels.length - 4) + ' more' : '')
            : 'Saved without changes';
    }

    const payload = {
        id: editId, title, summary, segments, extra_images, gallery, sources,
        media_data: mediaData, media_type: mediaType,
        status: selectedStatus,
        category, origin_year, origin_platform,
        tags,
        creator_id: CURRENT_USER.id, creator_name: CURRENT_USER.username, creator_avatar: CURRENT_USER.avatar,
        editor_id: CURRENT_USER.id, editor_name: CURRENT_USER.username, editor_avatar: CURRENT_USER.avatar,
        edit_summary: editId ? edit_summary : undefined,
        edit_changes: editId ? edit_changes : undefined
    };

    const btn = document.getElementById('cmSubmit');
    btn.disabled = true;
    const result = await apiFetch(editId ? 'update' : 'save', 'POST', payload);
    btn.disabled = false;
    if (result?.id || result?.success) {
        const newId = result.id || editId;
        await loadArticlesQuiet();
        loadedDetails.delete(newId);
        pendingReturnEntryId = null; // we handle the reopen ourselves below
        closeCreate();
        toast(`"${title}" ${editId ? 'updated' : 'published'}!`, 'success', 'ri-check-line');
        setTimeout(()=> openEntry(newId), 300);
    } else if (result?.error) {
        toast(result.error, 'error', 'ri-error-warning-line');
    }
}

/* ══════════════════════════════════════════════════════
   ENTRY DETAIL MODAL
   ══════════════════════════════════════════════════════ */
async function openEntry(id){
    let a = articles.find(x => x.id == id);
    if (!a) return;
    openEntryId = id;
    apiFetch('increment_views', 'POST', { id });

    const skeleton = document.getElementById('entrySkeleton');
    const content  = document.getElementById('entryContent');
    const isFirstLoad = !loadedDetails.has(id);

    openModal('modal-entry');
    document.getElementById('modal-entry').scrollTop = 0;

    if (isFirstLoad) {
        skeleton.classList.add('show');
        content.classList.add('hidden');

        const full = await apiFetch(`get&id=${id}`);
        if (full) {
            const idx = articles.findIndex(x => x.id == id);
            if (idx !== -1) articles[idx] = full;
            a = full;
            loadedDetails.add(id);
        }
    } else {
        skeleton.classList.remove('show');
        content.classList.remove('hidden');
    }

    trReset();   // a freshly opened entry always starts in its original language

    document.querySelectorAll('.detail-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.detail-tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.detail-tab')[0].classList.add('active');
    document.getElementById('etab-article').classList.add('active');

    const hero = document.getElementById('entryHeroMedia');
    const noMedia = document.getElementById('entryNoMedia');
    const oldMedia = hero.querySelector('img,video');
    if (oldMedia) oldMedia.remove();
    if (a.media_url) {
        noMedia.style.display = 'none';
        const el = document.createElement(a.media_type === 'video' ? 'video' : 'img');
        el.src = a.media_url;
        if (a.media_type === 'video') { el.autoplay = true; el.loop = true; el.muted = true; el.playsInline = true; }
        else el.alt = a.title;
        hero.insertBefore(el, hero.firstChild);
    } else {
        noMedia.style.display = 'flex';
        noMedia.textContent = getEmoji(a.title);
    }

    const st = statusMap[a.status] || statusMap.rising;
    document.getElementById('entryHeroStatus').innerHTML = `<i class="${st.icon}"></i> ${st.label}`;

    document.getElementById('entryCategoryText').textContent = a.category || 'Uncategorized';
    document.getElementById('entryTitle').textContent = a.title;
    document.getElementById('stickybarTitle').textContent = a.title;
    document.getElementById('entryAuthorAvatar').src = a.creator_avatar || CURRENT_USER.avatar;
    document.getElementById('entryAuthorName').textContent = '@' + (a.creator_name || CURRENT_USER.username);
    document.getElementById('entryCreatedShort').textContent = timeSince(a.created_at);
    updateFollowUI(a);

    const created = formatDate(a.created_at);
    const updated = formatDate(a.updated_at || a.created_at);
    document.getElementById('entryCreatedRow').textContent = 'Created ' + created.date + ' at ' + created.time;
    document.getElementById('entryUpdatedRow').textContent = 'Last updated ' + updated.date + ' at ' + updated.time;

    const infoData = [
        { label:'Type', val: a.media_type ? mediaKindOf(a).toUpperCase()+' Meme' : 'Meme' },
        { label:'Origin Year', val: a.origin_year || '—' },
        { label:'Platform', val: a.origin_platform || '—' },
        { label:'Edits', val: a.edit_count || 0 },
    ];
    document.getElementById('entryInfoGrid').innerHTML = infoData.map(d =>
        `<div class="detail-info-cell"><div class="detail-info-label">${d.label}</div><div class="detail-info-val">${escHtml(String(d.val))}</div></div>`
    ).join('');

    document.getElementById('entrySummary').textContent = a.summary || '';
    renderGallery(a);
    renderArticleSegments(a);
    renderSources(a);

    const tags = normalizeTags(a.tags);
    document.getElementById('entryTags').innerHTML = tags.length
        ? tags.map(t => `<span class="detail-tag" data-tr>${escHtml(t)}</span>`).join('')
        : '<span class="empty-tab-msg" style="padding:0;">No tags yet</span>';

    document.getElementById('entryEditCount').textContent = (a.edits || []).length;
    renderEditHistory(a);
    renderDiscussion(a);
    renderRelated(a);

    document.getElementById('entryHostAvatar').src = a.creator_avatar || CURRENT_USER.avatar;
    document.getElementById('entryHostName').textContent = '@' + (a.creator_name || CURRENT_USER.username);
    document.getElementById('entryHostProfileLink').href = a.creator_id ? ('profile.php?user_id=' + encodeURIComponent(a.creator_id)) : 'profile.php';

    skeleton.classList.remove('show');
    content.classList.remove('hidden');

    document.getElementById('entryStickybar').classList.remove('is-scrolled');
    initEntryScroll();


}

function renderArticleSegments(a){
    const container = document.getElementById('entryArticleSegments');
    const segments = a.segments || {};
    const extraImages = a.extra_images || {};
    const getExtra = n => extraImages[n] ?? extraImages[String(n)] ?? null;
    let html = '';
    let hasContent = false;
    SEGMENT_ORDER.forEach((key, idx) => {
        const text = (segments[key] || '').trim();
        if (text) {
            hasContent = true;
            const def = SEGMENT_DEFS[key];
            html += `<div class="detail-segment">
                <div class="detail-segment-label"><i class="${def.icon}"></i> <span data-tr>${escHtml(def.label)}</span></div>
                <p class="detail-segment-text" data-tr>${escHtml(text)}</p>
            </div>`;
        }
        if (idx===2 || idx===5 || idx===8) {
            const slot = idx===2 ? 1 : (idx===5 ? 2 : 3);
            const img = getExtra(slot);
            if (img) {
                hasContent = true;
                html += `<div class="detail-segment-image"><img src="${escHtml(img)}" alt="Additional image ${slot}" loading="lazy"></div>`;
            }
        }
    });
    container.innerHTML = hasContent
        ? html
        : '<p class="detail-empty-article">No full article written yet. Be the first to expand this entry — add an Origin, Cultural Significance, or any section you know.</p>';
}

/* ── Sources & citations (with verification) ── */
function renderSources(a){
    const box = document.getElementById('entrySources');
    const items = normalizeSources(a.sources);
    const ver = items.filter(isVerifiedSrc).length;
    document.getElementById('srcCount').textContent = items.length ? (ver + ' of ' + items.length + ' verified') : 'none yet';
    document.getElementById('entryVerRow').textContent = !items.length ? 'No sources cited yet'
        : (ver ? ver + (ver === 1 ? ' verified source' : ' verified sources') : 'Sources not verified yet');
    if (!items.length) {
        box.innerHTML = '<div class="empty-tab-msg" style="padding:2px 0 0;text-align:left;">No sources yet. Tap the edit icon to add the first post, a news article or a Know Your Meme page.</div>';
        return;
    }
    box.innerHTML = items.map(srcCard).join('');
}
function srcCard(s){
    const t = SRC_TYPES[s.type] || SRC_TYPES.other;
    const href = safeUrl(s.url), dom = domainOf(href);
    const own = s.added_by_id != null && s.added_by_id == CURRENT_USER.id;
    const ver = isVerifiedSrc(s);
    const label = s.title || dom || s.url;
    const titleEl = href
        ? `<a class="src-title" href="${escHtml(href)}" target="_blank" rel="noopener noreferrer nofollow">${escHtml(label)} <i class="ri-external-link-line" style="font-size:.85em;"></i></a>`
        : `<span class="src-title">${escHtml(label)}</span>`;
    const meta = [dom, t.label, s.added_by_name ? 'added by @' + s.added_by_name : ''].filter(Boolean).join(' · ');
    let btn = '';
    if (s.id != null) {
        if (own) btn = '<button type="button" class="src-verify" disabled>Added by you</button>';
        else btn = `<button type="button" class="src-verify${s.verified_by_me ? ' on' : ''}" data-id="${escHtml(String(s.id))}" onclick="verifySource(this.dataset.id)"><i class="${s.verified_by_me ? 'ri-check-double-line' : 'ri-shield-check-line'}"></i> ${s.verified_by_me ? 'You verified' : 'Verify'}</button>`;
    }
    return `<div class="src-item${ver ? ' ver' : ''}">
        <div class="src-top">
            <div class="src-ic"><i class="${t.icon}"></i></div>
            <div class="src-tx">${titleEl}<div class="src-meta">${escHtml(meta)}</div></div>
        </div>
        <div class="src-foot">
            ${ver ? `<span class="src-badge ver"><i class="ri-shield-check-fill"></i> Verified${s.verified_count ? ' · ' + s.verified_count : ''}</span>` : '<span class="src-badge"><i class="ri-shield-line"></i> Unverified</span>'}
            ${btn}
        </div>
    </div>`;
}
async function verifySource(srcId){
    const a = articles.find(x => x.id == openEntryId);
    if (!a) return;
    let list = Array.isArray(a.sources) ? a.sources : (typeof a.sources === 'string' ? null : null);
    // work on the live objects when sources is an array; otherwise normalise and write back
    if (!list) { list = normalizeSources(a.sources); a.sources = list; }
    else { a.sources = list = normalizeSources(list).map((n, i) => Object.assign(list[i] && typeof list[i] === 'object' ? list[i] : {}, n)); }
    const s = list.find(x => String(x.id) === String(srcId));
    if (!s) return;
    if (s.added_by_id != null && s.added_by_id == CURRENT_USER.id) { toast("You can't verify your own source", 'error', 'ri-error-warning-line'); return; }
    const prevMe = !!s.verified_by_me, prevN = Number(s.verified_count || 0), prevVer = s.verified;
    s.verified_by_me = !prevMe;
    s.verified_count = Math.max(0, prevN + (s.verified_by_me ? 1 : -1));
    s.verified = undefined;
    renderSources(a);                                           // optimistic update
    const res = await apiFetch('verify_source', 'POST', { article_id: a.id, source_id: s.id });
    if (res && !res.error && res.success !== false) {
        if (typeof res.verified_count === 'number') s.verified_count = res.verified_count;
        if (typeof res.verified_by_me === 'boolean') s.verified_by_me = res.verified_by_me;
        if (typeof res.verified === 'boolean') s.verified = res.verified;
        toast(s.verified_by_me ? 'Thanks — source verified' : 'Verification removed', 'success', 'ri-shield-check-line');
    } else {
        s.verified_by_me = prevMe; s.verified_count = prevN; s.verified = prevVer;   // roll back
    }
    if (openEntryId == a.id) renderSources(a);
}

/* ── Gallery carousel ── */
function renderGallery(a){
    const sec = document.getElementById('entryGallerySec'), box = document.getElementById('entryGallery');
    const items = normalizeGallery(a.gallery);
    if (!items.length) { sec.style.display = 'none'; box.innerHTML = ''; return; }
    sec.style.display = '';
    document.getElementById('galCount').textContent = items.length + (items.length === 1 ? ' item' : ' items');
    const slides = items.map(g => {
        const k = kindOfUrl(g.url, g.type);
        const media = k === 'video'
            ? `<video src="${escHtml(g.url)}" controls muted loop playsinline preload="metadata"></video>`
            : `<img src="${escHtml(g.url)}" alt="" loading="lazy">`;
        return `<div class="gal-item"><span class="gal-tag">${k.toUpperCase()}</span><div class="gal-media">${media}</div>${g.caption ? `<div class="gal-cap" data-tr>${escHtml(g.caption)}</div>` : ''}</div>`;
    }).join('');
    const dots = items.length > 1 ? '<div class="gal-dots" id="galDots">' + items.map((_, i) => `<span class="gal-dot${i === 0 ? ' on' : ''}" onclick="galGo(${i})"></span>`).join('') + '</div>' : '';
    box.innerHTML = `<div class="gal${items.length === 1 ? ' single' : ''}">
        <button type="button" class="gal-nav prev" onclick="galStep(-1)" aria-label="Previous"><i class="ri-arrow-left-s-line"></i></button>
        <div class="gal-track" id="galTrack" onscroll="galOnScroll()">${slides}</div>
        <button type="button" class="gal-nav next" onclick="galStep(1)" aria-label="Next"><i class="ri-arrow-right-s-line"></i></button>
    </div>${dots}`;
}
function galIndex(){
    const t = document.getElementById('galTrack'); if (!t) return 0;
    const it = t.querySelector('.gal-item'); if (!it) return 0;
    const step = it.offsetWidth + 10;
    return Math.max(0, Math.min(t.children.length - 1, Math.round(t.scrollLeft / step)));
}
function galOnScroll(){
    const t = document.getElementById('galTrack'); if (!t) return;
    const i = galIndex();
    document.querySelectorAll('#galDots .gal-dot').forEach((d, n) => d.classList.toggle('on', n === i));
    Array.from(t.children).forEach((c, n) => { if (n !== i) { const v = c.querySelector('video'); if (v && !v.paused) v.pause(); } });
}
function galGo(i){
    const t = document.getElementById('galTrack'); if (!t) return;
    const it = t.querySelector('.gal-item'); if (!it) return;
    i = Math.max(0, Math.min(t.children.length - 1, i));
    t.scrollTo({ left: i * (it.offsetWidth + 10), behavior:'smooth' });
}
function galStep(d){ galGo(galIndex() + d); }

function closeEntry(){
    closeModal('modal-entry');
    openEntryId = null;
    trReset();
    renderList();
    if (new URLSearchParams(window.location.search).get('open')) {
        const url = new URL(window.location.href);
        url.searchParams.delete('open');
        window.history.replaceState({}, '', url);
    }
}

let _entryScrollBound = false;
function initEntryScroll(){
    const modal = document.getElementById('modal-entry');
    const hero = modal.querySelector('.detail-hero');
    const titleEl = document.getElementById('entryTitle');
    const bar = document.getElementById('entryStickybar');
    const titleSpan = document.getElementById('stickybarTitle');
    if (!hero || !bar || !titleEl || !titleSpan) return;

    function onScroll(){
        const heroThreshold = Math.max(hero.offsetHeight - 50, 0);
        bar.classList.toggle('is-scrolled', modal.scrollTop > heroThreshold);
        const barHeight = bar.offsetHeight || 50;
        titleSpan.classList.toggle('show-title', titleEl.getBoundingClientRect().bottom <= barHeight);
    }
    if (!_entryScrollBound){ modal.addEventListener('scroll', onScroll, { passive:true }); _entryScrollBound = true; }
    onScroll();
}

function switchEntryTab(btn, name){
    document.querySelectorAll('.detail-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.detail-tab-panel').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('etab-' + name).classList.add('active');
}

/* ══════════════════════════════════════════════════════
   EDIT HISTORY — word-level diffs (green = added, red = removed) + upvotes
   ══════════════════════════════════════════════════════ */
function normalizeChanges(c){
    if (!c) return [];
    if (typeof c === 'string') { try { c = JSON.parse(c); } catch(e){ return []; } }
    return Array.isArray(c) ? c.filter(x => x && (x.kind === 'note' || x.label)) : [];
}
function diffOps(a, b){
    const A = a.split(/(\s+)/).filter(Boolean), B = b.split(/(\s+)/).filter(Boolean);
    const n = A.length, m = B.length;
    if (n * m > 2500000) return [[-1, a], [1, b]];            // too big for the fine diff: show whole replace
    let s = 0; while (s < n && s < m && A[s] === B[s]) s++;
    let e = 0; while (e < n - s && e < m - s && A[n-1-e] === B[m-1-e]) e++;
    const a2 = A.slice(s, n - e), b2 = B.slice(s, m - e);
    const N = a2.length, M = b2.length, W = M + 1;
    const dp = new Uint16Array((N + 1) * W);
    for (let i = N - 1; i >= 0; i--)
        for (let j = M - 1; j >= 0; j--)
            dp[i*W + j] = a2[i] === b2[j] ? dp[(i+1)*W + j + 1] + 1 : Math.max(dp[(i+1)*W + j], dp[i*W + j + 1]);
    const ops = [];
    const push = (t, x) => { if (ops.length && ops[ops.length-1][0] === t) ops[ops.length-1][1] += x; else ops.push([t, x]); };
    if (s) push(0, A.slice(0, s).join(''));
    let i = 0, j = 0;
    while (i < N && j < M) {
        if (a2[i] === b2[j]) { push(0, a2[i]); i++; j++; }
        else if (dp[(i+1)*W + j] >= dp[i*W + j + 1]) { push(-1, a2[i]); i++; }
        else { push(1, b2[j]); j++; }
    }
    while (i < N) push(-1, a2[i++]);
    while (j < M) push(1, b2[j++]);
    if (e) push(0, A.slice(n - e).join(''));
    return ops;
}
function collapseEq(t, pos){
    const k = 70;
    if (t.length <= 180) return escHtml(t);
    const gap = '<span class="df-gap">…</span>';
    if (pos === 'first') return gap + ' ' + escHtml(t.slice(-k));
    if (pos === 'last')  return escHtml(t.slice(0, k)) + ' ' + gap;
    if (pos === 'only')  return escHtml(t.slice(0, k)) + ' ' + gap;
    return escHtml(t.slice(0, k)) + ' ' + gap + ' ' + escHtml(t.slice(-k));
}
function renderDiff(o, n){
    const ops = diffOps(String(o || ''), String(n || ''));
    return ops.map((op, idx) => {
        const pos = ops.length === 1 ? 'only' : (idx === 0 ? 'first' : (idx === ops.length - 1 ? 'last' : 'mid'));
        if (op[0] === 0)  return collapseEq(op[1], pos);
        if (op[0] === -1) return '<del>' + escHtml(op[1]) + '</del>';
        return '<ins>' + escHtml(op[1]) + '</ins>';
    }).join('');
}
function diffBlock(c){
    if (c.kind === 'note') return `<div class="df"><div class="df-b df-note"><i class="ri-image-edit-line"></i> ${escHtml(c.note || c.label || '')}</div></div>`;
    return `<div class="df"><div class="df-h">${escHtml(c.label)}</div><div class="df-b">${renderDiff(c.old || '', c.new || '')}</div></div>`;
}

function renderEditHistory(a){
    const edits = a.edits || [];
    const el = document.getElementById('editHistoryList');
    if (!edits.length) { el.innerHTML = '<div class="empty-tab-msg">No edits recorded yet.</div>'; return; }
    const rows = edits.map((e, i) => ({ e, i })).reverse();
    el.innerHTML = rows.map((r, pos) => {
        const e = r.e, idx = r.i;
        const changes = normalizeChanges(e.changes);
        const key = a.id + ':' + idx;
        const open = key in histState ? histState[key] : (pos === 0);
        const votes = Number(e.votes || 0), voted = !!e.voted;
        const own = e.editor_id && e.editor_id == CURRENT_USER.id;
        return `
        <div class="eh-item${open && changes.length ? ' open' : ''}">
            <img class="eh-avatar" src="${escHtml(e.editor_avatar || CURRENT_USER.avatar)}" alt="" onerror="this.src='uploads/me.jpeg'">
            <div class="eh-info">
                <div class="eh-user">@${escHtml(e.editor_name || 'Unknown')}</div>
                <div class="eh-summary">${escHtml(e.summary || 'Updated the entry')}</div>
                <div class="eh-time">${formatDate(e.edited_at).date} · ${formatDate(e.edited_at).time}</div>
                ${changes.length ? `<button type="button" class="eh-toggle" onclick="toggleDiff(${idx})">${open ? 'Hide changes' : 'Show changes (' + changes.length + ')'}</button>
                <div class="eh-changes">${changes.map(diffBlock).join('')}</div>` : ''}
            </div>
            <button type="button" class="eh-vote${voted ? ' on' : ''}" onclick="voteEdit(${idx})" ${own ? 'disabled title="You can\'t upvote your own edit"' : 'title="Good edit"'} aria-label="Upvote this edit">
                <i class="${voted ? 'ri-thumb-up-fill' : 'ri-thumb-up-line'}"></i><span>${votes}</span>
            </button>
        </div>`;
    }).join('');
}
function toggleDiff(idx){
    const a = articles.find(x => x.id == openEntryId); if (!a) return;
    const key = a.id + ':' + idx;
    const isLatest = idx === (a.edits || []).length - 1;
    const cur = key in histState ? histState[key] : isLatest;
    histState[key] = !cur;
    renderEditHistory(a);
}
async function voteEdit(idx){
    const a = articles.find(x => x.id == openEntryId);
    if (!a || !a.edits || !a.edits[idx]) return;
    const e = a.edits[idx];
    if (e.editor_id && e.editor_id == CURRENT_USER.id) { toast("You can't upvote your own edit", 'error', 'ri-error-warning-line'); return; }
    const prevV = !!e.voted, prevN = Number(e.votes || 0);
    e.voted = !prevV;
    e.votes = Math.max(0, prevN + (e.voted ? 1 : -1));
    renderEditHistory(a);                                       // optimistic update
    const res = await apiFetch('vote_edit', 'POST', { article_id: a.id, edit_id: e.id ?? null, edit_index: idx });
    if (res && !res.error && res.success !== false) {
        if (typeof res.votes === 'number') e.votes = res.votes;
        if (typeof res.voted === 'boolean') e.voted = res.voted;
    } else {
        e.voted = prevV; e.votes = prevN;                       // roll back
    }
    if (openEntryId == a.id) renderEditHistory(a);
}

function renderDiscussion(a){
    const comments = a.comments || [];
    const el = document.getElementById('discussionList');
    if (!comments.length) { el.innerHTML = '<div class="empty-tab-msg">No comments yet — start the discussion.</div>'; return; }
    el.innerHTML = comments.map(c => `
        <div class="comment-item">
            <div class="comment-avatar">
                <img src="${escHtml(c.avatar || CURRENT_USER.avatar)}" alt="" onerror="this.src='uploads/me.jpeg'">
            </div>
            <div class="comment-body">
                <div class="comment-bubble">
                    <div class="comment-username">@${escHtml(c.username)}</div>
                    <div class="comment-time-inline">${timeSince(c.posted_at)}</div>
                    <div class="comment-text">${escHtml(c.text)}</div>
                </div>
            </div>
        </div>`).join('');
}
async function postComment(){
    if (openEntryId === null) return;
    const input = document.getElementById('discInput');
    const text = input.value.trim();
    if (!text) { toast('Write something first','error','ri-error-warning-line'); return; }
    const result = await apiFetch('comment', 'POST', {
        article_id: openEntryId, text, username: CURRENT_USER.username, avatar: CURRENT_USER.avatar
    });
    if (result?.success) {
        input.value = '';
        const full = await apiFetch(`get&id=${openEntryId}`);
        if (full) { const idx = articles.findIndex(x => x.id == openEntryId); if (idx !== -1) articles[idx] = full; renderDiscussion(full); }
        toast('Comment posted','success','ri-check-line');
    }
}

function renderRelated(a){
    const others = articles.filter(x => x.id != a.id && (x.category === a.category || x.status === a.status)).slice(0,6);
    const el = document.getElementById('relatedList');
    if (!others.length) { el.innerHTML = '<div class="empty-tab-msg">No related entries yet.</div>'; return; }
    el.innerHTML = others.map(r => {
        const st = statusMap[r.status] || statusMap.rising;
        return `
        <div class="related-row" onclick="openEntry(${r.id})">
            <div class="related-row-thumb">${r.media_url ? `<img src="${escHtml(r.media_url)}" alt="${escHtml(r.title)}" loading="lazy">` : `<div class="related-row-emoji">${getEmoji(r.title)}</div>`}</div>
            <div class="related-row-info">
                <div class="related-row-title">${escHtml(r.title)}</div>
                <div class="related-row-meta">${st.label}</div>
            </div>
            <i class="ri-arrow-right-s-line related-row-arrow"></i>
        </div>`;
    }).join('');
}

function editCurrentEntry(){
    if (openEntryId === null) return;
    const a = articles.find(x => x.id == openEntryId);
    if (!a) return;
    pendingReturnEntryId = a.id;
    closeModal('modal-entry');
    openEditModal(a);
}

/* ══════════════════════════════════════════════════════
   FULL-SCREEN SCREEN HELPERS (menu · translate · QR · share · filters · report)
   ══════════════════════════════════════════════════════ */
const MP_SCREENS = ['mprDone','mprReason','mpShare','mpQr','mpLang','mpFilters','mpMenu'];   // topmost first

function mprEl(id){ return document.getElementById(id); }
function mpTopOpen(){ for (const id of MP_SCREENS){ if (mprEl(id).classList.contains('mpr-open')) return id; } return null; }
function mprIsOpen(){ return mpTopOpen() !== null; }
function mprOpenScreen(id){
    const el = mprEl(id);
    el.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    requestAnimationFrame(() => requestAnimationFrame(() => el.classList.add('mpr-open')));
}
function mprCloseScreen(id){
    const el = mprEl(id);
    el.classList.remove('mpr-open');
    setTimeout(() => { if (!el.classList.contains('mpr-open')) el.style.display = 'none'; }, 270);
}
/* close one of the screens and give the page its scrolling back if nothing else is open */
function mpClose(id){
    mprCloseScreen(id);
    setTimeout(() => {
        if (!document.querySelector('.modal-overlay.active') && !mprIsOpen()) document.body.style.overflow = '';
    }, 280);
}
function mprToast(msg){
    const t = mprEl('mprToast');
    t.textContent = msg; t.classList.add('show');
    clearTimeout(t._tid);
    t._tid = setTimeout(() => t.classList.remove('show'), 2600);
}

/* ══════════════════════════════════════════════════════
   ENTRY MENU  →  Follow · Share · Translate · QR code · Report
   ══════════════════════════════════════════════════════ */
function openEntryMenu(){
    const a = articles.find(x => x.id == openEntryId);
    if (!a) return;
    mprEl('mpMenuTarget').textContent = a.title;
    mprEl('mpMenuTrVal').textContent = trState.code ? trNativeName(trState.code) : '';
    updateFollowUI(a);
    const own = a.creator_id && a.creator_id == CURRENT_USER.id;
    mprEl('mpMenuReportRow').style.display = own ? 'none' : '';
    mprOpenScreen('mpMenu');
}
function mpMenuGo(what){
    mpClose('mpMenu');
    setTimeout(() => {
        if (what === 'follow') toggleFollow();
        else if (what === 'translate') openLangPicker();
        else if (what === 'qr')   openQr();
        else if (what === 'share') openShare();
        else if (what === 'report') openArticleReport();
    }, 130);
}

/* ══════════════════════════════════════════════════════
   SMART SHARE — native share sheet on mobile + a generated preview card
   (cover image · title · summary) drawn on a canvas, plus quick social links.
   Uses the existing ?open=ID deep link.
   ══════════════════════════════════════════════════════ */
let shareToken = 0;
function shareSlug(a){ return (a.title || 'entry').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'') || 'entry'; }
function shareText(a){ const s = (a.summary || '').trim(); return s.length > 140 ? s.slice(0, 137) + '…' : s; }

function openShare(){
    const a = articles.find(x => x.id == openEntryId);
    if (!a) return;
    mprEl('mpShNative').style.display = (navigator.share ? '' : 'none');
    mprEl('mpShUrl').textContent = entryUrl();
    mprEl('mpShare').querySelector('.mpr-body').scrollTop = 0;
    mprOpenScreen('mpShare');
    drawShareCard(a);
}
function loadImg(url){
    return new Promise(res => {
        if (!url) return res(null);
        const im = new Image();
        im.crossOrigin = 'anonymous';
        im.onload = () => res(im);
        im.onerror = () => res(null);
        im.src = url;
    });
}
function rrect(ctx, x, y, w, h, r){
    ctx.beginPath();
    ctx.moveTo(x + r, y); ctx.lineTo(x + w - r, y); ctx.quadraticCurveTo(x + w, y, x + w, y + r);
    ctx.lineTo(x + w, y + h - r); ctx.quadraticCurveTo(x + w, y + h, x + w - r, y + h);
    ctx.lineTo(x + r, y + h); ctx.quadraticCurveTo(x, y + h, x, y + h - r);
    ctx.lineTo(x, y + r); ctx.quadraticCurveTo(x, y, x + r, y); ctx.closePath();
}
function wrapLines(ctx, text, maxW, maxLines){
    const words = String(text || '').replace(/\s+/g, ' ').trim().split(' ');
    const lines = []; let cur = '';
    for (let w of words) {
        const test = cur ? cur + ' ' + w : w;
        if (ctx.measureText(test).width <= maxW) { cur = test; continue; }
        if (cur) { lines.push(cur); cur = ''; }
        while (w && ctx.measureText(w).width > maxW) {
            let k = w.length;
            while (k > 1 && ctx.measureText(w.slice(0, k)).width > maxW) k--;
            lines.push(w.slice(0, k)); w = w.slice(k);
        }
        cur = w;
    }
    if (cur) lines.push(cur);
    if (lines.length > maxLines) {
        lines.length = maxLines;
        let l = lines[maxLines - 1];
        while (l.length > 1 && ctx.measureText(l + '…').width > maxW) l = l.slice(0, -1);
        lines[maxLines - 1] = l.replace(/\s+$/, '') + '…';
    }
    return lines;
}
async function drawShareCard(a){
    const token = ++shareToken;
    const cv = mprEl('mpShCv'), ctx = cv.getContext('2d'), W = 1200, H = 630;
    const accent = (getComputedStyle(document.documentElement).getPropertyValue('--accent') || '#FF2D2D').trim() || '#FF2D2D';
    try { await Promise.all([document.fonts.load('700 56px Poppins'), document.fonts.load('400 28px Poppins')]); } catch(e){}
    const img = mediaKindOf(a) === 'video' ? null : await loadImg(a.media_url);
    if (token !== shareToken) return;

    // background
    const bg = ctx.createLinearGradient(0, 0, W, H);
    bg.addColorStop(0, '#010218'); bg.addColorStop(1, '#0b0e30');
    ctx.fillStyle = bg; ctx.fillRect(0, 0, W, H);

    // cover panel (left)
    const PW = 500;
    ctx.save(); ctx.beginPath(); ctx.rect(0, 0, PW, H); ctx.clip();
    if (img) {
        const s = Math.max(PW / img.width, H / img.height);
        const dw = img.width * s, dh = img.height * s;
        ctx.drawImage(img, (PW - dw) / 2, (H - dh) / 2, dw, dh);
    } else {
        ctx.fillStyle = '#171a24'; ctx.fillRect(0, 0, PW, H);
        ctx.font = '170px sans-serif'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
        ctx.fillStyle = '#fff'; ctx.fillText(getEmoji(a.title), PW / 2, H / 2);
    }
    ctx.restore();
    const fade = ctx.createLinearGradient(PW - 120, 0, PW, 0);
    fade.addColorStop(0, 'rgba(1,2,24,0)'); fade.addColorStop(1, 'rgba(1,2,24,.9)');
    ctx.fillStyle = fade; ctx.fillRect(PW - 120, 0, 120, H);
    ctx.fillStyle = accent; ctx.fillRect(PW, 0, 6, H);

    // text column
    const X = 556 + 44, TW = W - X - 56;
    ctx.textAlign = 'left'; ctx.textBaseline = 'top';
    const st = statusMap[a.status] || statusMap.rising;
    ctx.font = '700 22px Poppins, sans-serif';
    const pillW = ctx.measureText(st.label).width + 36;
    ctx.fillStyle = accent; rrect(ctx, X, 62, pillW, 40, 8); ctx.fill();
    ctx.fillStyle = '#000'; ctx.fillText(st.label, X + 18, 70);
    if (a.category) {
        ctx.font = '600 20px Poppins, sans-serif'; ctx.fillStyle = 'rgba(255,255,255,.55)';
        const cat = String(a.category).toUpperCase();
        ctx.fillText(cat, X + pillW + 18, 72);
    }

    ctx.fillStyle = '#fff'; ctx.font = '700 58px Poppins, sans-serif';
    const tLines = wrapLines(ctx, a.title, TW, 3);
    let y = 134;
    tLines.forEach(l => { ctx.fillText(l, X, y); y += 70; });

    ctx.font = '400 27px Poppins, sans-serif'; ctx.fillStyle = 'rgba(255,255,255,.72)';
    const sLines = wrapLines(ctx, a.summary || '', TW, tLines.length >= 3 ? 3 : 4);
    y += 14;
    sLines.forEach(l => { ctx.fillText(l, X, y); y += 40; });

    // meta + brand
    const meta = [a.origin_year, a.origin_platform].filter(Boolean).join('  ·  ');
    if (meta) { ctx.font = '600 22px Poppins, sans-serif'; ctx.fillStyle = 'rgba(255,255,255,.5)'; ctx.fillText(meta, X, H - 118); }
    ctx.fillStyle = accent; ctx.fillRect(X, H - 74, 6, 36);
    ctx.font = '700 32px Poppins, sans-serif'; ctx.fillStyle = '#fff'; ctx.fillText('Memepedia', X + 20, H - 74);
    ctx.font = '400 20px Poppins, sans-serif'; ctx.fillStyle = 'rgba(255,255,255,.45)';
    ctx.textAlign = 'right'; ctx.fillText(location.host, W - 56, H - 68);
    ctx.textAlign = 'left';
}
async function mpShareNative(){
    const a = articles.find(x => x.id == openEntryId);
    if (!a || !navigator.share) { mpCopyLink(); return; }
    const url = entryUrl(), title = a.title + ' | Memepedia', text = shareText(a);
    try {
        const cv = mprEl('mpShCv');
        const blob = await new Promise(r => cv.toBlob(r, 'image/png'));
        if (blob && navigator.canShare) {
            const file = new File([blob], 'memepedia-' + shareSlug(a) + '.png', { type:'image/png' });
            if (navigator.canShare({ files:[file] })) {
                await navigator.share({ files:[file], title, text: (text ? text + ' ' : '') + url });
                return;
            }
        }
        await navigator.share({ title, text, url });
    } catch (err) {
        if (err && err.name === 'AbortError') return;
        mpCopyLink();
    }
}
function mpShDownload(){
    const a = articles.find(x => x.id == openEntryId);
    const cv = mprEl('mpShCv');
    if (!a || !cv) return;
    const name = 'memepedia-' + shareSlug(a) + '.png';
    const go = url => { const l = document.createElement('a'); l.href = url; l.download = name; document.body.appendChild(l); l.click(); document.body.removeChild(l); };
    if (cv.toBlob) cv.toBlob(b => { if (!b) { mprToast('Could not save the card'); return; } const url = URL.createObjectURL(b); go(url); setTimeout(() => URL.revokeObjectURL(url), 4000); }, 'image/png');
    else go(cv.toDataURL('image/png'));
}
function mpShareTo(net){
    const a = articles.find(x => x.id == openEntryId);
    if (!a) return;
    const url = entryUrl(), title = a.title + ' | Memepedia', text = shareText(a);
    const u = encodeURIComponent(url);
    const links = {
        x:        'https://twitter.com/intent/tweet?text=' + encodeURIComponent(title) + '&url=' + u,
        whatsapp: 'https://wa.me/?text=' + encodeURIComponent(title + ' ' + url),
        facebook: 'https://www.facebook.com/sharer/sharer.php?u=' + u,
        telegram: 'https://t.me/share/url?url=' + u + '&text=' + encodeURIComponent(title),
        email:    'mailto:?subject=' + encodeURIComponent(title) + '&body=' + encodeURIComponent((text ? text + '\n\n' : '') + url)
    };
    if (!links[net]) return;
    if (net === 'email') location.href = links[net];
    else window.open(links[net], '_blank', 'noopener');
}

/* ══════════════════════════════════════════════════════
   TRANSLATE — translates ONLY the article (title, summary, sections, tags),
   right where you are, no page reload. Pick "Show original" to go back.
   ══════════════════════════════════════════════════════ */
const TR_LANGS = [['en','English','English'],['fr','French','Français'],['es','Spanish','Español'],['pt','Portuguese','Português'],['de','German','Deutsch'],['it','Italian','Italiano'],['nl','Dutch','Nederlands'],['ar','Arabic','العربية'],['zh-CN','Chinese (Simplified)','简体中文'],['zh-TW','Chinese (Traditional)','繁體中文'],['hi','Hindi','हिन्दी'],['bn','Bengali','বাংলা'],['ur','Urdu','اردو'],['ru','Russian','Русский'],['uk','Ukrainian','Українська'],['ja','Japanese','日本語'],['ko','Korean','한국어'],['tr','Turkish','Türkçe'],['vi','Vietnamese','Tiếng Việt'],['th','Thai','ไทย'],['id','Indonesian','Bahasa Indonesia'],['ms','Malay','Bahasa Melayu'],['tl','Filipino','Filipino'],['sw','Swahili','Kiswahili'],['yo','Yoruba','Yorùbá'],['ig','Igbo','Igbo'],['ha','Hausa','Hausa'],['zu','Zulu','isiZulu'],['xh','Xhosa','isiXhosa'],['af','Afrikaans','Afrikaans'],['am','Amharic','አማርኛ'],['so','Somali','Soomaali'],['rw','Kinyarwanda','Kinyarwanda'],['mg','Malagasy','Malagasy'],['pl','Polish','Polski'],['cs','Czech','Čeština'],['sk','Slovak','Slovenčina'],['hu','Hungarian','Magyar'],['ro','Romanian','Română'],['bg','Bulgarian','Български'],['el','Greek','Ελληνικά'],['sr','Serbian','Српски'],['hr','Croatian','Hrvatski'],['sl','Slovenian','Slovenščina'],['sv','Swedish','Svenska'],['no','Norwegian','Norsk'],['da','Danish','Dansk'],['fi','Finnish','Suomi'],['is','Icelandic','Íslenska'],['et','Estonian','Eesti'],['lv','Latvian','Latviešu'],['lt','Lithuanian','Lietuvių'],['he','Hebrew','עברית'],['fa','Persian','فارسی'],['ta','Tamil','தமிழ்'],['te','Telugu','తెలుగు'],['ml','Malayalam','മലയാളം'],['kn','Kannada','ಕನ್ನಡ'],['mr','Marathi','मराठी'],['gu','Gujarati','ગુજરાતી'],['pa','Punjabi','ਪੰਜਾਬੀ'],['ne','Nepali','नेपाली'],['si','Sinhala','සිංහල'],['my','Burmese','မြန်မာ'],['km','Khmer','ខ្មែរ'],['lo','Lao','ລາວ'],['ka','Georgian','ქართული'],['hy','Armenian','Հայերեն'],['az','Azerbaijani','Azərbaycan'],['kk','Kazakh','Қазақша'],['uz','Uzbek','Oʻzbek'],['mn','Mongolian','Монгол'],['ht','Haitian Creole','Kreyòl ayisyen'],['ca','Catalan','Català'],['gl','Galician','Galego'],['eu','Basque','Euskara'],['cy','Welsh','Cymraeg'],['ga','Irish','Gaeilge'],['sq','Albanian','Shqip'],['mk','Macedonian','Македонски'],['bs','Bosnian','Bosanski'],['be','Belarusian','Беларуская'],['mt','Maltese','Malti'],['la','Latin','Latina']];
let trState = { code:'' };
let trToken = 0;
const trCache = new Map();

function trNativeName(code){ const L = TR_LANGS.find(x => x[0] === code); return L ? L[2] : code; }
function trEls(){ return Array.from(document.querySelectorAll('#modal-entry [data-tr]')); }

/* forget any translation (used whenever another entry is shown or the entry is closed) */
function trReset(){
    trToken++;
    trState.code = '';
    trEls().forEach(el => { delete el.dataset.orig; el.removeAttribute('dir'); });
    const bar = mprEl('entryTrBar'); if (bar) bar.classList.remove('show');
}
function trShowOriginal(){
    trToken++;
    trEls().forEach(el => {
        if (el.dataset.orig !== undefined) el.textContent = el.dataset.orig;
        delete el.dataset.orig; el.removeAttribute('dir');
    });
    const st = document.getElementById('stickybarTitle'), ti = document.getElementById('entryTitle');
    if (st && ti) st.textContent = ti.textContent;
    trState.code = '';
    mprEl('entryTrBar').classList.remove('show');
}

function openLangPicker(){
    const q = mprEl('mpLangQ'); if (q) q.value = '';
    trLangBuild('');
    mprEl('mpLangBody').scrollTop = 0;
    mprOpenScreen('mpLang');
}
function trLangBuild(v){
    v = (v || '').trim().toLowerCase();
    const box = mprEl('mpLangList'); box.innerHTML = '';
    if (trState.code && !v) {
        const o = document.createElement('div');
        o.className = 'mpr-row';
        o.innerHTML = '<i class="ri-arrow-go-back-line mpr-row-icon" aria-hidden="true"></i><span class="mpr-row-text">Show original</span>';
        o.addEventListener('click', () => { mpClose('mpLang'); trShowOriginal(); });
        box.appendChild(o);
    }
    let n = 0;
    TR_LANGS.forEach(L => {
        if (v && (L[1] + ' ' + L[2]).toLowerCase().indexOf(v) === -1) return;
        n++;
        const row = document.createElement('div');
        row.className = 'mpr-row' + (trState.code === L[0] ? ' selected' : '');
        row.innerHTML = '<i class="ri-translate-2 mpr-row-icon" aria-hidden="true"></i><span class="mpr-row-text"></span><span class="mpr-sub"></span><div class="mpr-check">' + (trState.code === L[0] ? '✓' : '') + '</div>';
        row.querySelector('.mpr-row-text').textContent = L[2];
        row.querySelector('.mpr-sub').textContent = L[1];
        row.addEventListener('click', () => { mpClose('mpLang'); trApply(L[0]); });
        box.appendChild(row);
    });
    if (!n) box.innerHTML = '<div class="mpl-empty">No language found</div>';
}

/* split long text into pieces the translator accepts, keeping sentence and line breaks */
function trChunks(text, max){
    if (text.length <= max) return [text];
    const parts = text.match(/[^.!?。！？\n]+[.!?。！？]*\s*|\n+/g) || [text];
    const out = []; let cur = '';
    parts.forEach(p => {
        if (cur && (cur + p).length > max) { out.push(cur); cur = ''; }
        while (p.length > max) { out.push(p.slice(0, max)); p = p.slice(max); }
        cur += p;
    });
    if (cur) out.push(cur);
    return out;
}
async function trFetch(text, code){
    const base = 'https://translate.googleapis.com/translate_a/single?client=gtx&sl=auto&dt=t&tl=' + encodeURIComponent(code);
    let res;
    try {
        res = await fetch(base, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'}, body:'q=' + encodeURIComponent(text) });
        if (!res.ok) throw new Error('post');
    } catch (e) {
        res = await fetch(base + '&q=' + encodeURIComponent(text));
        if (!res.ok) throw new Error('translate');
    }
    const d = await res.json();
    return (d && d[0] ? d[0] : []).map(s => (s && s[0]) || '').join('');
}
async function trText(text, code){
    const key = code + '\u0001' + text;
    if (trCache.has(key)) return trCache.get(key);
    const pieces = trChunks(text, 1400);
    const out = [];
    for (const p of pieces) out.push(p.trim() ? await trFetch(p, code) : p);
    const res = out.join('');
    trCache.set(key, res);
    return res;
}

async function trApply(code){
    if (!code) { trShowOriginal(); return; }
    const els = trEls();
    els.forEach(el => { if (el.dataset.orig === undefined) el.dataset.orig = el.textContent; });
    const todo = els.filter(el => (el.dataset.orig || '').trim());
    if (!todo.length) { toast('Nothing to translate', 'error', 'ri-error-warning-line'); return; }

    const token = ++trToken;
    toast('Translating…', 'info', 'ri-translate-2');
    const results = new Array(todo.length);
    let next = 0, failed = false;
    async function worker(){
        while (!failed) {
            const i = next++; if (i >= todo.length) return;
            try { results[i] = await trText(todo[i].dataset.orig, code); }
            catch (e) { failed = true; return; }
        }
    }
    await Promise.all([worker(), worker(), worker(), worker()]);
    if (token !== trToken) return;          // another entry/language was chosen meanwhile

    if (failed) {
        trShowOriginal();
        toast('Could not translate right now — check your connection', 'error', 'ri-error-warning-line');
        return;
    }
    todo.forEach((el, i) => { el.textContent = results[i]; el.setAttribute('dir', 'auto'); });
    const st = document.getElementById('stickybarTitle'), ti = document.getElementById('entryTitle');
    if (st && ti) st.textContent = ti.textContent;
    trState.code = code;
    mprEl('entryTrTxt').textContent = 'Translated to ' + trNativeName(code);
    mprEl('entryTrBar').classList.add('show');
    toast('Translated to ' + trNativeName(code), 'success', 'ri-check-line');
}

/* ══════════════════════════════════════════════════════
   QR CODE for the open entry
   ══════════════════════════════════════════════════════ */
function entryUrl(){ return location.origin + location.pathname + '?open=' + encodeURIComponent(openEntryId); }
function openQr(){
    const a = articles.find(x => x.id == openEntryId);
    if (!a) return;
    const u = entryUrl(), cv = mprEl('mpQrCv');
    mprEl('mpQrName').textContent = a.title;
    mprEl('mpQrUrl').textContent = u;
    const ok = SpQR.draw(cv, u, 1000);
    mprEl('mpQrErr').style.display = ok ? 'none' : '';
    cv.style.display = ok ? '' : 'none';
    mprEl('mpQr').querySelector('.mpr-body').scrollTop = 0;
    mprOpenScreen('mpQr');
}
function mpQrDownload(){
    const cv = mprEl('mpQrCv');
    if (!cv || cv.style.display === 'none') return;
    const go = url => { const a = document.createElement('a'); a.href = url; a.download = 'memepedia-qr-code.png'; document.body.appendChild(a); a.click(); document.body.removeChild(a); };
    if (cv.toBlob) cv.toBlob(b => { if (!b) { mprToast('Could not save the image'); return; } const url = URL.createObjectURL(b); go(url); setTimeout(() => URL.revokeObjectURL(url), 4000); }, 'image/png');
    else go(cv.toDataURL('image/png'));
}
function mpCopyLink(){
    const u = entryUrl();
    const ok = () => mprToast('Link copied');
    const fb = () => {
        const t = document.createElement('textarea'); t.value = u; t.style.cssText = 'position:fixed;opacity:0'; document.body.appendChild(t); t.select();
        try { document.execCommand('copy'); ok(); } catch (e) { mprToast('Could not copy'); }
        document.body.removeChild(t);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(u).then(ok).catch(fb); else fb();
}

/* tiny QR encoder: byte mode, error correction M, versions 1-10 */
var SpQR = (function(){
    var ECC = [null,[10,1,16,0,0],[16,1,28,0,0],[26,1,44,0,0],[18,2,32,0,0],[24,2,43,0,0],[16,4,27,0,0],[18,4,31,0,0],[22,2,38,2,39],[22,3,36,2,37],[26,4,43,1,44]];
    var ALIGN = [null,[],[6,18],[6,22],[6,26],[6,30],[6,34],[6,22,38],[6,24,42],[6,26,46],[6,28,50]];
    var EXP = [], LOG = [], i, x = 1;
    for (i = 0; i < 255; i++){ EXP[i] = x; LOG[x] = i; x <<= 1; if (x & 256) x ^= 0x11d; }
    for (i = 255; i < 512; i++) EXP[i] = EXP[i - 255];
    function mul(a, b){ return (a === 0 || b === 0) ? 0 : EXP[LOG[a] + LOG[b]]; }
    function rsRem(data, n){
        var g = [1], j, k;
        for (k = 0; k < n; k++){
            var ng = []; for (j = 0; j <= g.length; j++) ng.push(0);
            for (j = 0; j < g.length; j++){ ng[j] ^= g[j]; ng[j + 1] ^= mul(g[j], EXP[k]); }
            g = ng;
        }
        var res = []; for (k = 0; k < n; k++) res.push(0);
        data.forEach(function(b){
            var f = b ^ res.shift(); res.push(0);
            for (var q = 0; q < n; q++) res[q] ^= mul(g[q + 1], f);
        });
        return res;
    }
    function utf8(s){
        var o = [], c, k;
        s = unescape(encodeURIComponent(s));
        for (k = 0; k < s.length; k++){ c = s.charCodeAt(k); o.push(c); }
        return o;
    }
    function make(text){
        var bytes = utf8(text), v, info, dataCw;
        for (v = 1; v <= 10; v++){
            info = ECC[v]; dataCw = info[1] * info[2] + info[3] * info[4];
            if (dataCw * 8 >= 4 + (v < 10 ? 8 : 16) + bytes.length * 8) break;
        }
        if (v > 10) return null;
        var bits = [];
        function put(val, len){ for (var b = len - 1; b >= 0; b--) bits.push((val >>> b) & 1); }
        put(4, 4); put(bytes.length, v < 10 ? 8 : 16);
        bytes.forEach(function(b){ put(b, 8); });
        var cap = dataCw * 8;
        put(0, Math.min(4, cap - bits.length));
        while (bits.length % 8) bits.push(0);
        for (var pad = 0xEC; bits.length < cap; pad ^= 0xEC ^ 0x11) put(pad, 8);
        var cw = []; for (i = 0; i < bits.length; i += 8){ var by = 0; for (var q = 0; q < 8; q++) by = (by << 1) | bits[i + q]; cw.push(by); }
        /* blocks */
        var ecLen = info[0], blocks = [], pos = 0, n, b2;
        for (n = 0; n < info[1]; n++){ blocks.push(cw.slice(pos, pos + info[2])); pos += info[2]; }
        for (n = 0; n < info[3]; n++){ blocks.push(cw.slice(pos, pos + info[4])); pos += info[4]; }
        var ecs = blocks.map(function(b){ return rsRem(b, ecLen); });
        var out = [], maxLen = Math.max(info[2], info[4]);
        for (n = 0; n < maxLen; n++) blocks.forEach(function(b){ if (n < b.length) out.push(b[n]); });
        for (n = 0; n < ecLen; n++) ecs.forEach(function(e){ out.push(e[n]); });
        /* matrix */
        var size = 17 + 4 * v, m = [], fn = [], r, c;
        for (r = 0; r < size; r++){ m.push(new Array(size).fill(0)); fn.push(new Array(size).fill(false)); }
        function setF(xx, yy, d){ m[yy][xx] = d ? 1 : 0; fn[yy][xx] = true; }
        function finder(cx, cy){
            for (var dy = -4; dy <= 4; dy++) for (var dx = -4; dx <= 4; dx++){
                var d = Math.max(Math.abs(dx), Math.abs(dy)), xx = cx + dx, yy = cy + dy;
                if (xx >= 0 && xx < size && yy >= 0 && yy < size) setF(xx, yy, d !== 2 && d !== 4);
            }
        }
        for (i = 0; i < size; i++){ setF(6, i, i % 2 === 0); setF(i, 6, i % 2 === 0); }
        finder(3, 3); finder(size - 4, 3); finder(3, size - 4);
        var al = ALIGN[v];
        for (r = 0; r < al.length; r++) for (c = 0; c < al.length; c++){
            if ((r === 0 && c === 0) || (r === 0 && c === al.length - 1) || (r === al.length - 1 && c === 0)) continue;
            for (var dy = -2; dy <= 2; dy++) for (var dx = -2; dx <= 2; dx++) setF(al[c] + dx, al[r] + dy, Math.max(Math.abs(dx), Math.abs(dy)) !== 1);
        }
        function formatBits(mask){
            var d = (0 << 3) | mask, rem = d;
            for (var k = 0; k < 10; k++) rem = (rem << 1) ^ ((rem >>> 9) * 0x537);
            var bb = ((d << 10) | rem) ^ 0x5412;
            function bt(k){ return ((bb >>> k) & 1) !== 0; }
            for (var k = 0; k <= 5; k++) setF(8, k, bt(k));
            setF(8, 7, bt(6)); setF(8, 8, bt(7)); setF(7, 8, bt(8));
            for (k = 9; k < 15; k++) setF(14 - k, 8, bt(k));
            for (k = 0; k < 8; k++) setF(size - 1 - k, 8, bt(k));
            for (k = 8; k < 15; k++) setF(8, size - 15 + k, bt(k));
            setF(8, size - 8, true);
        }
        formatBits(0);
        if (v >= 7){
            var rv = v;
            for (i = 0; i < 12; i++) rv = (rv << 1) ^ ((rv >>> 11) * 0x1F25);
            var vb = (v << 12) | rv;
            for (i = 0; i < 18; i++){ var bt2 = ((vb >>> i) & 1) !== 0, a = size - 11 + i % 3, bb2 = Math.floor(i / 3); setF(a, bb2, bt2); setF(bb2, a, bt2); }
        }
        /* data */
        var idx = 0, total = out.length * 8, right, vert, j, up, yy2, xx2;
        for (right = size - 1; right >= 1; right -= 2){
            if (right === 6) right = 5;
            for (vert = 0; vert < size; vert++) for (j = 0; j < 2; j++){
                xx2 = right - j; up = ((right + 1) & 2) === 0; yy2 = up ? size - 1 - vert : vert;
                if (!fn[yy2][xx2] && idx < total){ m[yy2][xx2] = (out[idx >>> 3] >>> (7 - (idx & 7))) & 1; idx++; }
            }
        }
        function maskFn(k, xx, yy){
            switch (k){
                case 0: return (xx + yy) % 2 === 0; case 1: return yy % 2 === 0; case 2: return xx % 3 === 0; case 3: return (xx + yy) % 3 === 0;
                case 4: return (Math.floor(xx / 3) + Math.floor(yy / 2)) % 2 === 0; case 5: return xx * yy % 2 + xx * yy % 3 === 0;
                case 6: return (xx * yy % 2 + xx * yy % 3) % 2 === 0; default: return ((xx + yy) % 2 + xx * yy % 3) % 2 === 0;
            }
        }
        function applyMask(k){ for (var yy = 0; yy < size; yy++) for (var xx = 0; xx < size; xx++) if (!fn[yy][xx] && maskFn(k, xx, yy)) m[yy][xx] ^= 1; }
        function penalty(){
            var p = 0, rr, cc, run, dark = 0, line;
            for (rr = 0; rr < size; rr++) for (cc = 0; cc < size; cc++) if (m[rr][cc]) dark++;
            for (var dir = 0; dir < 2; dir++){
                for (rr = 0; rr < size; rr++){
                    line = []; for (cc = 0; cc < size; cc++) line.push(dir ? m[cc][rr] : m[rr][cc]);
                    run = 1;
                    for (cc = 1; cc <= size; cc++){
                        if (cc < size && line[cc] === line[cc - 1]) run++; else { if (run >= 5) p += 3 + (run - 5); run = 1; }
                    }
                    var s = line.join('');
                    var pats = ['000010111010', '010111010000'], pi, from;
                    for (pi = 0; pi < 2; pi++){ from = s.indexOf(pats[pi]); while (from !== -1){ p += 40; from = s.indexOf(pats[pi], from + 1); } }
                }
            }
            for (rr = 0; rr < size - 1; rr++) for (cc = 0; cc < size - 1; cc++){ var cv = m[rr][cc]; if (cv === m[rr][cc + 1] && cv === m[rr + 1][cc] && cv === m[rr + 1][cc + 1]) p += 3; }
            p += (Math.ceil(Math.abs(dark * 20 - size * size * 10) / (size * size)) - 1) * 10;
            return p;
        }
        var best = 0, bestP = 1e9, k;
        for (k = 0; k < 8; k++){
            applyMask(k); formatBits(k);
            var pp = penalty(); if (pp < bestP){ bestP = pp; best = k; }
            applyMask(k);
        }
        applyMask(best); formatBits(best);
        return m;
    }
    function draw(canvas, text, px){
        var m = make(text); if (!m) return false;
        var n = m.length, q = 4, cell = Math.max(2, Math.floor(px / (n + q * 2))), W = cell * (n + q * 2);
        canvas.width = W; canvas.height = W;
        var ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, W, W); ctx.fillStyle = '#000';
        for (var r = 0; r < n; r++) for (var c = 0; c < n; c++) if (m[r][c]) ctx.fillRect((c + q) * cell, (r + q) * cell, cell, cell);
        return true;
    }
    return { make: make, draw: draw };
})();

/* ══════════════════════════════════════════════════════
   REPORT AN ENTRY  →  report_article.php  →  Reports Center
   (keep this list identical to $RA_REASONS in report_article.php)
   ══════════════════════════════════════════════════════ */
const MPR_REASONS = [
    { icon:'ri-error-warning-line',   text:'Inaccurate or Wrong Information' },
    { icon:'ri-newspaper-line',       text:'Misinformation or Fake Info' },
    { icon:'ri-ghost-line',           text:'Made-up or Fake Meme' },
    { icon:'ri-spam-2-line',          text:'Vandalism or Spam' },
    { icon:'ri-prohibited-line',      text:'Hate Speech or Discrimination' },
    { icon:'ri-emotion-unhappy-line', text:'Harassment or Personal Attacks' },
    { icon:'ri-eye-off-line',         text:'Personal Information (Doxxing)' },
    { icon:'ri-eye-2-line',           text:'Graphic or Sexual Content' },
    { icon:'ri-copyright-line',       text:'Copyright Infringement' },
    { icon:'ri-megaphone-line',       text:'Promotion or Advertising' },
    { icon:'ri-file-copy-line',       text:'Duplicate Entry' },
    { icon:'ri-alert-line',           text:'Illegal or Dangerous Content' },
    { icon:'ri-shield-user-line',     text:'Child Safety Concern' },
    { icon:'ri-question-line',        text:'Other' }
];
let mprState = { id:null, reason:null };

function mprCharCount(el){
    mprEl('mprCharLabel').textContent = el.value.length + ' / 500';
    el.style.borderColor = '';
}
function mprSelectReason(reason){
    mprState.reason = reason;
    document.querySelectorAll('#mprReasonList .mpr-row').forEach(row => {
        const sel = row.dataset.reason === reason;
        row.classList.toggle('selected', sel);
        row.querySelector('.mpr-check').textContent = sel ? '✓' : '';
    });
    const ow = mprEl('mprOtherWrap');
    ow.style.display = reason === 'Other' ? 'block' : 'none';
    if (reason === 'Other') {
        const sc = mprEl('mprScroll');
        setTimeout(() => { if (sc) sc.scrollTo({ top: sc.scrollHeight, behavior:'smooth' }); }, 60);
    } else {
        mprEl('mprOtherInput').value = '';
        mprEl('mprCharLabel').textContent = '0 / 500';
    }
}
function openArticleReport(id){
    id = id || openEntryId;
    const a = articles.find(x => x.id == id);
    if (!a) return;
    if (a.creator_id && a.creator_id == CURRENT_USER.id) { toast("You can't report your own entry", 'error', 'ri-error-warning-line'); return; }
    mprState = { id:a.id, reason:null };
    mprEl('mprTargetTitle').textContent = a.title;
    const list = mprEl('mprReasonList');
    list.innerHTML = '';
    MPR_REASONS.forEach(r => {
        const row = document.createElement('div');
        row.className = 'mpr-row';
        row.dataset.reason = r.text;
        row.innerHTML = '<i class="' + r.icon + ' mpr-row-icon" aria-hidden="true"></i><span class="mpr-row-text">' + escHtml(r.text) + '</span><div class="mpr-check"></div>';
        row.addEventListener('click', () => mprSelectReason(r.text));
        list.appendChild(row);
    });
    mprEl('mprOtherWrap').style.display = 'none';
    mprEl('mprOtherInput').value = '';
    mprEl('mprCharLabel').textContent = '0 / 500';
    mprEl('mprScroll').scrollTop = 0;
    mprOpenScreen('mprReason');
}
function closeArticleReport(){
    mprCloseScreen('mprReason');
    mprCloseScreen('mprDone');
    setTimeout(() => {
        if (!document.querySelector('.modal-overlay.active') && !mprIsOpen()) document.body.style.overflow = '';
        mprState = { id:null, reason:null };
    }, 280);
}
async function submitArticleReport(){
    if (!mprState.reason) {
        const list = mprEl('mprReasonList');
        list.style.animation = 'none'; void list.offsetWidth;
        list.style.animation = 'mprShake .35s ease';
        setTimeout(() => { list.style.animation = ''; }, 400);
        mprToast('Please select a reason first');
        return;
    }
    const ta = mprEl('mprOtherInput');
    const desc = ta.value.trim();
    if (mprState.reason === 'Other' && !desc) {
        ta.style.borderColor = 'var(--accent)';
        ta.focus();
        mprToast('Please describe the issue');
        return;
    }
    const btn = mprEl('mprSubmitBtn');
    btn.disabled = true; btn.textContent = 'Submitting…';
    const fd = new FormData();
    fd.append('article_id', mprState.id);
    fd.append('reason', mprState.reason);
    if (desc) fd.append('description', desc);
    try {
        const res = await fetch('report_article.php', { method:'POST', body:fd, credentials:'same-origin' });
        const d = await res.json();
        btn.disabled = false; btn.textContent = 'Submit Report';
        if (d.success || d.msg === 'already_reported') {
            mprCloseScreen('mprReason');
            setTimeout(() => mprOpenScreen('mprDone'), 80);
        } else {
            mprToast(d.msg || 'Something went wrong. Try again.');
        }
    } catch (err) {
        btn.disabled = false; btn.textContent = 'Submit Report';
        mprToast('Network error — check your connection');
    }
}

/* ══════════════════════════════════════════════════════
   GLOBAL KEYS
   ══════════════════════════════════════════════════════ */
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        const top = mpTopOpen();
        if (top) {
            if (top === 'mprReason' || top === 'mprDone') closeArticleReport(); else mpClose(top);
            return;
        }
        closeSortMenu();
        document.querySelectorAll('.modal-overlay.active').forEach(m => m.classList.remove('active'));
        document.body.style.overflow = '';
        if (openEntryId !== null) trReset();
        openEntryId = null;
    }
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') { e.preventDefault(); openSearch(); }
});

/* ══════════════════════════════════════════════════════
   UTILS
   ══════════════════════════════════════════════════════ */
function escHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
function normalizeTags(tags){ if (!tags) return []; if (Array.isArray(tags)) return tags; try { return JSON.parse(tags); } catch { return String(tags).split(',').map(t=>t.trim()).filter(Boolean); } }
function formatDate(iso){
    if (!iso) return { date:'—', time:'—' };
    const d = new Date(iso);
    const m = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return { date: d.getDate()+' '+m[d.getMonth()]+' '+d.getFullYear(), time: d.getHours().toString().padStart(2,'0')+':'+d.getMinutes().toString().padStart(2,'0') };
}
function timeSince(iso){
    if (!iso) return '';
    const s = Math.floor((Date.now() - new Date(iso)) / 1000);
    if (s < 60) return 'just now';
    if (s < 3600) return Math.floor(s/60) + 'm ago';
    if (s < 86400) return Math.floor(s/3600) + 'h ago';
    if (s < 604800) return Math.floor(s/86400) + 'd ago';
    return formatDate(iso).date;
}
function fmtNum(n){ n = Number(n) || 0; if (n >= 1e6) return (n/1e6).toFixed(1).replace('.0','')+'M'; if (n >= 1000) return (n/1000).toFixed(1).replace('.0','')+'K'; return String(n); }
const EMOJIS = ['😂','🐕','🎵','😤','🧠','🤖','😡','💪','🏆','🎮','🌐','📱','🎬','🔥','👑','✨','🤌','😈','🫠','💀'];
function getEmoji(title){ let h=0; for (let i=0;i<title.length;i++) h = title.charCodeAt(i) + ((h<<5)-h); return EMOJIS[Math.abs(h) % EMOJIS.length]; }

/* ══════════════════════════════════════════════════════
   INIT
   ══════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', async () => {
    updateViewUI();
    renderAdvSummary();
    await loadArticles();
});
</script>
</body>
</html>