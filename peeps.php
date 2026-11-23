<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$current_user = (int)$_SESSION['user_id'];

$views_stmt = $conn->prepare("
    SELECT pv.viewer_id, pv.viewed_at, u.username, u.nickname, u.profile_pic
    FROM profile_views pv
    JOIN users u ON pv.viewer_id = u.id
    WHERE pv.profile_id = ?
    ORDER BY pv.viewed_at DESC
");
$views_stmt->bind_param("i", $current_user);
$views_stmt->execute();
$views = $views_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$views_stmt->close();

$total_views = count($views);

// ── stats ─────────────────────────────────────────────────────
$unique   = [];
$week_cut = strtotime('-7 days');
$week_cnt = 0;
foreach ($views as $v) {
    $unique[$v['viewer_id']] = true;
    if (strtotime($v['viewed_at']) >= $week_cut) $week_cnt++;
}
$unique_count = count($unique);

// ── group by date label ───────────────────────────────────────
function viewLabel($ts) {
    if ($ts >= strtotime('today'))      return 'Today';
    if ($ts >= strtotime('yesterday'))  return 'Yesterday';
    if ($ts >= strtotime('-7 days'))    return 'This Week';
    if ($ts >= strtotime('-30 days'))   return 'This Month';
    return date('F Y', $ts);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Profile Views — Dezzme</title>

<!-- THEME: same switch as community.php — runs first so there is no dark → light flash -->
<script>
try{
    if (localStorage.getItem('dz_theme') === 'light') {
        document.documentElement.setAttribute('data-theme', 'light');
    }
}catch(e){}
</script>

<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">

<style>
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; font-family:'Poppins', sans-serif; }
button, a { cursor:pointer; outline:none; -webkit-tap-highlight-color:transparent; }
a { text-decoration:none; color:inherit; }

[class^="ri-"], [class*=" ri-"],
[class^="ri-"]::before, [class*=" ri-"]::before,
[class^="ri-"]::after,  [class*=" ri-"]::after {
    font-family:'remixicon' !important;
    font-style:normal; font-weight:normal; line-height:1;
    speak:none; text-transform:none;
    -webkit-font-smoothing:antialiased;
}

:root{
    --red:    #FF2D2D;
    --red-1:  #ff6b6b;

    --fg:      255,255,255;   /* R,G,B of the text-on-surface tint used for lines + soft fills (dark theme = white) */
    --ui-text: #ffffff;

    --bg:      linear-gradient(-45deg,#010218,#070924,#070924);
    --surface: rgba(var(--fg),0.05);
    --line:    rgba(var(--fg),0.09);
    --line2:   rgba(var(--fg),0.14);
    --text:    #ffffff;
    --muted:   rgba(var(--fg),0.45);
    --muted-2: rgba(var(--fg),0.34);
    --sub:     rgba(var(--fg),0.62);

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
.pv-lane{
    width:100%; max-width:var(--max-w);
    min-height:100vh;
    display:flex; flex-direction:column;
    padding-bottom:50px;
}
@media (min-width:801px){
    .pv-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); }
}
@media (max-width:800px){ .pv-lane{ max-width:100%; } }

/* ══════════════════════════════════════
   TOPBAR — X on the left, title on the right
══════════════════════════════════════ */
.pv-topbar{
    position:sticky; top:0; z-index:500;
    height:var(--topbar-h);
    display:flex; align-items:center; gap:2px;
    padding:0 10px;
    background:var(--bg);
    background-attachment:fixed;
}
@media (max-width:768px){ .pv-topbar{ border-bottom:1px solid var(--line); } }
.pv-x{
    width:34px; height:34px;
    background:none; border:none; color:var(--ui-text);
    display:flex; align-items:center; justify-content:center;
    opacity:.9; transition:opacity .2s, color .2s;
    padding:0; flex-shrink:0;
}
.pv-x:hover{ opacity:1; color:var(--red); }
.pv-x svg{ width:22px; height:22px; display:block; }
.pv-title{
    flex:1; min-width:0;
    font-size:.92rem; font-weight:700; color:var(--ui-text); letter-spacing:-.1px;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    text-align:right; margin:0 6px 0 10px;
}
@media (max-width:490px){ .pv-x{ width:30px; height:30px; } .pv-title{ font-size:.84rem; } }
@media (max-width:380px){ .pv-title{ font-size:.8rem; } }

/* ── page-load reveal ── */
.rv{ opacity:0; }
body.ready .rv{ animation:pvIn .55s cubic-bezier(.22,1,.36,1) both; animation-delay:calc(var(--i, 0) * 60ms); }
@keyframes pvIn{ from{ opacity:0; transform:translateY(12px); } to{ opacity:1; transform:none; } }
@media (prefers-reduced-motion:reduce){ body.ready .rv{ animation:none; opacity:1; } }

/* ══════════════════════════════════════
   HERO
══════════════════════════════════════ */
.hero{
    padding:clamp(14px, 4.5vw, 24px) var(--pad) clamp(14px, 4vw, 20px);
    text-align:center; border-top:1px solid var(--line);
}
.hero-badge{
    display:inline-flex; align-items:center; gap:6px;
    height:clamp(22px, 6vw, 26px); padding:0 11px; border-radius:4px;
    background:rgba(255,45,45,.12);
    font-size:clamp(.56rem, 1.9vw, .64rem); font-weight:700; color:var(--red);
    letter-spacing:.06em; text-transform:uppercase;
    margin-bottom:clamp(10px, 3vw, 16px);
}
.hero h1{ font-size:clamp(1.15rem, 5.4vw, 1.65rem); font-weight:700; line-height:1.15; letter-spacing:-.3px; margin-bottom:8px; color:var(--ui-text); }
.hero-sub{ font-size:clamp(.68rem, 2.3vw, .82rem); color:var(--muted); line-height:1.6; }

/* ── stats bar ── */
.stats-bar{
    display:grid; grid-template-columns:repeat(3, 1fr);
    border-top:1px solid var(--line); border-bottom:1px solid var(--line);
}
.stat-item{ display:flex; flex-direction:column; align-items:center; padding:clamp(11px, 3.4vw, 16px) 6px; text-align:center; }
.stat-item + .stat-item{ border-left:1px solid var(--line); }
.stat-num{ font-size:clamp(.92rem, 3.4vw, 1.25rem); font-weight:700; line-height:1; color:var(--red); }
.stat-lbl{ font-size:clamp(.48rem, 1.7vw, .6rem); color:var(--muted); margin-top:5px; letter-spacing:.04em; text-transform:uppercase; }

/* ── search ── */
.pv-search{ position:relative; display:flex; align-items:center; padding:12px var(--pad) 6px; }
.pv-search i{ position:absolute; left:calc(var(--pad) + 11px); color:var(--muted); font-size:1rem; pointer-events:none; }
.pv-search input{
    width:100%; height:var(--ctl-h);
    background:var(--surface); border:1px solid var(--line2); border-radius:var(--r);
    padding:0 12px 0 34px; font-size:.76rem; color:var(--text); outline:none;
    transition:border-color .2s, background .2s, box-shadow .2s;
    -webkit-appearance:none; appearance:none;
}
.pv-search input::placeholder{ color:var(--muted); }
.pv-search input:focus{ border-color:rgba(255,45,45,.7); background:rgba(var(--fg),.07); box-shadow:0 0 0 3px rgba(255,45,45,.12); }
@media (max-width:490px){ .pv-search input{ font-size:.7rem; padding-left:32px; } .pv-search i{ left:calc(var(--pad) + 10px); font-size:.92rem; } }

/* ── list ── */
.vw-list{ display:flex; flex-direction:column; }
.vw-head{
    display:flex; align-items:center; justify-content:space-between;
    padding:clamp(14px, 4vw, 20px) var(--pad) 9px;
}
.vw-pill{
    display:inline-flex; align-items:center; height:22px; padding:0 10px; border-radius:4px;
    background:rgba(255,45,45,.12); color:var(--red);
    font-size:.58rem; font-weight:700; letter-spacing:.07em; text-transform:uppercase;
}
.vw-cnt{ font-size:.64rem; color:var(--muted); }
.vw-row{
    display:flex; align-items:center; gap:clamp(10px, 3vw, 14px);
    padding:clamp(10px, 3vw, 13px) var(--pad);
    border-top:1px solid rgba(var(--fg),.06);
    transition:background .15s;
}
.vw-row:hover{ background:rgba(var(--fg),.03); }
.vw-row:active{ background:rgba(var(--fg),.05); }
.vw-pic{
    width:clamp(38px, 10.5vw, 46px); height:clamp(38px, 10.5vw, 46px);
    border-radius:50%; object-fit:cover; flex-shrink:0; border:2px solid var(--red);
}
@media (max-width:490px){ .vw-pic{ border-width:1.5px; } }
.vw-info{ flex:1; min-width:0; }
.vw-name{ display:block; font-size:clamp(.74rem, 2.5vw, .86rem); font-weight:600; color:var(--ui-text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.vw-nick{ display:block; font-size:clamp(.6rem, 2vw, .7rem); color:var(--muted); margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.vw-time{ display:flex; align-items:center; gap:5px; font-size:clamp(.56rem, 1.9vw, .66rem); color:var(--muted-2); margin-top:3px; }
.vw-time i{ font-size:.8rem; color:var(--red-1); }
.vw-chev{ color:var(--muted); font-size:1.05rem; flex-shrink:0; transition:transform .2s, color .2s; }
.vw-row:hover .vw-chev{ color:var(--red); transform:translateX(3px); }

.vw-hide{ display:none !important; }
.vw-in{ animation:pvIn .4s cubic-bezier(.22,1,.36,1) both; }
.vw-sentinel{ height:1px; }

/* ── empty / no results ── */
.empty-state{
    padding:56px 20px 36px; text-align:center; color:var(--muted);
    display:flex; flex-direction:column; align-items:center; gap:8px;
}
.empty-state i{ font-size:2.3rem; color:var(--red); opacity:.85; }
.empty-state p{ font-size:.8rem; line-height:1.6; max-width:260px; }

/* ══════════════════════════════════════
   PREMIUM PAGE-LOAD SKELETON
══════════════════════════════════════ */
#page-skeleton{
    position:fixed; inset:0; z-index:9999;
    background:var(--bg); background-attachment:fixed;
    display:flex; justify-content:center;
    opacity:1; transition:opacity .45s ease, visibility .45s ease; pointer-events:none;
}
#page-skeleton.hide{ opacity:0; visibility:hidden; }
.sk-lane{ width:100%; max-width:var(--max-w); display:flex; flex-direction:column; overflow:hidden; }
@media (min-width:801px){ .sk-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); } }
@media (max-width:800px){ .sk-lane{ max-width:100%; } }
@media (max-width:768px){ .sk-topbar{ border-bottom:1px solid var(--line); } }
.sk{ position:relative; overflow:hidden; background:rgba(var(--fg),.06); border-radius:6px; }
.sk::after{
    content:''; position:absolute; inset:0;
    background:linear-gradient(90deg, transparent, rgba(var(--fg),.09), transparent);
    transform:translateX(-100%); animation:skShimmer 1.5s infinite;
}
@keyframes skShimmer{ 100%{ transform:translateX(100%); } }
.sk-topbar{ height:var(--topbar-h); display:flex; align-items:center; justify-content:space-between; padding:0 16px 0 14px; flex-shrink:0; }
.sk-x{ width:20px; height:20px; } .sk-ttl{ width:100px; height:13px; }
.sk-hero{ display:flex; flex-direction:column; align-items:center; gap:11px; padding:clamp(16px,4.5vw,26px) var(--pad) 18px; border-top:1px solid var(--line); }
.sk-badge{ width:110px; height:22px; border-radius:4px; }
.sk-h1{ width:min(250px, 70%); height:clamp(20px, 5.4vw, 26px); }
.sk-p{ width:min(260px, 70%); height:10px; }
.sk-stats{ display:grid; grid-template-columns:repeat(3,1fr); border-top:1px solid var(--line); border-bottom:1px solid var(--line); }
.sk-stat{ height:clamp(52px,14vw,64px); border-radius:0; }
.sk-search{ margin:12px var(--pad) 6px; height:var(--ctl-h); border-radius:var(--r); }
.sk-pill{ width:70px; height:22px; border-radius:4px; margin:16px var(--pad) 9px; }
.sk-row{ display:flex; align-items:center; gap:14px; padding:12px var(--pad); border-top:1px solid rgba(var(--fg),.06); }
.sk-av{ width:clamp(38px,10.5vw,46px); height:clamp(38px,10.5vw,46px); border-radius:50%; flex-shrink:0; }
.sk-l{ flex:1; display:flex; flex-direction:column; gap:7px; } .sk-l1{ width:40%; height:11px; } .sk-l2{ width:60%; height:9px; }

/* ═══════════════════════════════════════════════════════════════
   RESPONSIVE ONLY — everything 10% bigger (desktop untouched)
   Kept near the end so it wins over the rules above.
═══════════════════════════════════════════════════════════════ */
@media (max-width:800px){
    :root{
        --topbar-h:59px;
        --pad:clamp(10px, 3.3vw, 17.6px);
        --ctl-h:clamp(33px, 8.8vw, 39.6px);
        --r:5.5px;
    }

    /* topbar */
    .pv-topbar{ padding:0 11px; }
    .pv-x{ width:37px; height:37px; }
    .pv-x svg{ width:24px; height:24px; }
    .pv-title{ font-size:1.01rem; margin:0 7px 0 11px; }

    /* hero */
    .hero{ padding:clamp(15px, 4.95vw, 26px) var(--pad) clamp(15px, 4.4vw, 22px); }
    .hero-badge{ height:clamp(24px, 6.6vw, 29px); gap:6.6px; padding:0 12px; font-size:clamp(.62rem, 2.09vw, .7rem); margin-bottom:clamp(11px, 3.3vw, 17.6px); }
    .hero h1{ font-size:clamp(1.265rem, 5.94vw, 1.815rem); margin-bottom:8.8px; }
    .hero-sub{ font-size:clamp(.75rem, 2.53vw, .9rem); }

    /* stats bar */
    .stat-item{ padding:clamp(12px, 3.74vw, 17.6px) 6.6px; }
    .stat-num{ font-size:clamp(1.01rem, 3.74vw, 1.375rem); }
    .stat-lbl{ font-size:clamp(.53rem, 1.87vw, .66rem); margin-top:5.5px; }

    /* search */
    .pv-search{ padding:13px var(--pad) 6.6px; }
    .pv-search i{ left:calc(var(--pad) + 12px); font-size:1.1rem; }
    .pv-search input{ padding:0 13px 0 37px; font-size:.84rem; }

    /* list */
    .vw-head{ padding:clamp(15px, 4.4vw, 22px) var(--pad) 10px; }
    .vw-pill{ height:24.2px; padding:0 11px; font-size:.64rem; }
    .vw-cnt{ font-size:.7rem; }
    .vw-row{ gap:clamp(11px, 3.3vw, 15px); padding:clamp(11px, 3.3vw, 14px) var(--pad); }
    .vw-pic{ width:clamp(42px, 11.55vw, 51px); height:clamp(42px, 11.55vw, 51px); border-width:2.2px; }
    .vw-name{ font-size:clamp(.81rem, 2.75vw, .95rem); }
    .vw-nick{ font-size:clamp(.66rem, 2.2vw, .77rem); margin-top:2.2px; }
    .vw-time{ gap:5.5px; font-size:clamp(.62rem, 2.09vw, .73rem); margin-top:3.3px; }
    .vw-time i{ font-size:.88rem; }
    .vw-chev{ font-size:1.155rem; }

    /* empty */
    .empty-state{ padding:62px 22px 40px; gap:8.8px; }
    .empty-state i{ font-size:2.53rem; }
    .empty-state p{ font-size:.88rem; max-width:286px; }

    /* skeleton */
    .sk-topbar{ padding:0 17.6px 0 15px; }
    .sk-x{ width:22px; height:22px; } .sk-ttl{ width:110px; height:14.3px; }
    .sk-hero{ gap:12px; padding:clamp(18px, 4.95vw, 29px) var(--pad) 20px; }
    .sk-badge{ width:121px; height:24px; }
    .sk-h1{ width:min(275px, 70%); height:clamp(22px, 5.94vw, 29px); }
    .sk-p{ width:min(286px, 70%); height:11px; }
    .sk-stat{ height:clamp(57px, 15.4vw, 70px); }
    .sk-search{ margin:13px var(--pad) 6.6px; }
    .sk-pill{ width:77px; height:24px; margin:17.6px var(--pad) 10px; }
    .sk-row{ gap:15px; padding:13px var(--pad); }
    .sk-av{ width:clamp(42px, 11.55vw, 51px); height:clamp(42px, 11.55vw, 51px); }
    .sk-l{ gap:7.7px; } .sk-l1{ height:12px; } .sk-l2{ height:10px; }
}

@media (max-width:490px){
    :root{ --topbar-h:53px; }
    .pv-x{ width:33px; height:33px; }
    .pv-title{ font-size:.92rem; }
    .pv-search input{ font-size:.77rem; padding-left:35px; }
    .pv-search i{ left:calc(var(--pad) + 11px); font-size:1.01rem; }
    .vw-pic{ border-width:1.65px; }
}

@media (max-width:380px){
    :root{ --topbar-h:48px; }
    .pv-title{ font-size:.88rem; }
}

/* ═══════════ LIGHT MODE — <html data-theme="light"> (dark mode is never touched) ═══════════
   Same switch + same palette as community.php and the History page. */
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
    --muted-2:rgba(6,7,26,.42);
    --sub:rgba(6,7,26,.66);
    --red:#e62020;
    --red-1:#e62020;
}
[data-theme="light"] .hero-badge,
[data-theme="light"] .vw-pill{ background:rgba(230,32,32,.1); }
[data-theme="light"] .pv-search input:focus{ border-color:rgba(230,32,32,.7); box-shadow:0 0 0 3px rgba(230,32,32,.12); }
</style>
<noscript><style>.rv{opacity:1 !important} #page-skeleton{display:none} .vw-hide{display:flex !important}</style></noscript>
</head>
<body>

<!-- ══ PREMIUM PAGE-LOAD SKELETON ══ -->
<div id="page-skeleton" aria-hidden="true">
    <div class="sk-lane">
        <div class="sk-topbar"><div class="sk sk-x"></div><div class="sk sk-ttl"></div></div>
        <div class="sk-hero"><div class="sk sk-badge"></div><div class="sk sk-h1"></div><div class="sk sk-p"></div></div>
        <div class="sk-stats"><div class="sk sk-stat"></div><div class="sk sk-stat"></div><div class="sk sk-stat"></div></div>
        <div class="sk sk-search"></div>
        <div class="sk sk-pill"></div>
        <?php for ($i = 0; $i < 6; $i++): ?>
        <div class="sk-row"><div class="sk sk-av"></div><div class="sk-l"><div class="sk sk-l1"></div><div class="sk sk-l2"></div></div></div>
        <?php endfor; ?>
    </div>
</div>

<div class="pv-lane">

    <!-- ══ TOPBAR ══ -->
    <header class="pv-topbar">
        <button type="button" class="pv-x" onclick="pvClose()" aria-label="Close">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
        <span class="pv-title">Profile Views</span>
    </header>

    <!-- ══ HERO ══ -->
    <section class="hero rv" style="--i:0;">
        <div class="hero-badge"><i class="ri-eye-line"></i> <?php echo number_format($total_views); ?> Total <?php echo $total_views === 1 ? 'View' : 'Views'; ?></div>
        <h1>Who's checked you out</h1>
        <p class="hero-sub">Everyone who viewed your profile, newest first.</p>
    </section>

    <?php if ($total_views > 0): ?>
    <!-- ══ STATS ══ -->
    <div class="stats-bar rv" style="--i:1;">
        <div class="stat-item"><div class="stat-num"><?php echo number_format($total_views); ?></div><div class="stat-lbl">Total views</div></div>
        <div class="stat-item"><div class="stat-num"><?php echo number_format($unique_count); ?></div><div class="stat-lbl">Visitors</div></div>
        <div class="stat-item"><div class="stat-num"><?php echo number_format($week_cnt); ?></div><div class="stat-lbl">This week</div></div>
    </div>

    <!-- ══ SEARCH ══ -->
    <div class="pv-search rv" style="--i:2;">
        <i class="ri-search-line"></i>
        <input type="text" id="vwSearch" placeholder="Search visitors..." autocomplete="off">
    </div>

    <!-- ══ LIST ══ -->
    <main class="vw-list rv" id="vwList" style="--i:3;">
        <?php
        $last_label = null;
        $label_counts = [];
        foreach ($views as $v) { $l = viewLabel(strtotime($v['viewed_at'])); $label_counts[$l] = ($label_counts[$l] ?? 0) + 1; }
        foreach ($views as $v):
            $label = viewLabel(strtotime($v['viewed_at']));
            if ($label !== $last_label):
                $last_label = $label;
        ?>
        <div class="vw-head vw-item" data-kind="head">
            <span class="vw-pill"><?php echo htmlspecialchars($label); ?></span>
            <span class="vw-cnt"><?php echo $label_counts[$label]; ?> view<?php echo $label_counts[$label] === 1 ? '' : 's'; ?></span>
        </div>
        <?php endif; ?>
        <a class="vw-row vw-item" data-kind="row" data-name="<?php echo htmlspecialchars(strtolower($v['username'] . ' ' . ($v['nickname'] ?? ''))); ?>"
           href="profile.php?user_id=<?php echo (int)$v['viewer_id']; ?>">
            <img class="vw-pic" src="<?php echo htmlspecialchars(!empty($v['profile_pic']) ? $v['profile_pic'] : 'uploads/me.png'); ?>" onerror="this.src='uploads/me.png'" alt="">
            <div class="vw-info">
                <span class="vw-name"><?php echo htmlspecialchars($v['username']); ?></span>
                <?php if (!empty($v['nickname'])): ?><span class="vw-nick">@<?php echo htmlspecialchars($v['nickname']); ?></span><?php endif; ?>
                <span class="vw-time"><i class="ri-time-line"></i><?php echo date('M j, Y · g:i A', strtotime($v['viewed_at'])); ?></span>
            </div>
            <i class="ri-arrow-right-s-line vw-chev"></i>
        </a>
        <?php endforeach; ?>

        <div class="empty-state vw-hide" id="vwNone">
            <i class="ri-search-line"></i><p>No visitors found</p>
        </div>
        <div class="vw-sentinel" id="vwSentinel"></div>
    </main>

    <?php else: ?>
    <div class="empty-state rv" style="--i:1;">
        <i class="ri-ghost-line"></i>
        <p>No one has viewed your profile yet. Keep posting!</p>
    </div>
    <?php endif; ?>

</div><!-- /.pv-lane -->

<script>
// ── X: go back, or to your profile if there is no history ──
function pvClose(){
    if (document.referrer && document.referrer.indexOf(window.location.host) !== -1) history.back();
    else location.href = 'profile.php';
}

// ── PAGE-LOAD SKELETON → staggered reveal ──
(function(){
    var sk = document.getElementById('page-skeleton');
    var shownAt = Date.now(), done = false, MIN = 450;
    function reveal(){
        if (done) return; done = true;
        var wait = Math.max(0, MIN - (Date.now() - shownAt));
        setTimeout(function(){
            document.body.classList.add('ready');
            if (sk){
                sk.classList.add('hide');
                setTimeout(function(){ if (sk.parentNode) sk.remove(); }, 520);
            }
        }, wait);
    }
    if (document.readyState === 'complete') reveal();
    else window.addEventListener('load', reveal);
    setTimeout(reveal, 1800);   // safety net
})();

// ── LIST: infinite reveal (30 at a time) + search ──
(function(){
    var list = document.getElementById('vwList');
    if (!list) return;
    var items    = Array.prototype.slice.call(list.querySelectorAll('.vw-item'));
    var input    = document.getElementById('vwSearch');
    var none     = document.getElementById('vwNone');
    var sentinel = document.getElementById('vwSentinel');
    var BATCH = 30, shown = BATCH, query = '';
    var totalRows = items.filter(function(i){ return i.dataset.kind === 'row'; }).length;

    function render(animateFrom){
        var rowIdx = 0, head = null, headShown = false, visibleRows = 0;
        items.forEach(function(el){
            if (el.dataset.kind === 'head'){
                head = el; headShown = false;
                el.classList.add('vw-hide');
                return;
            }
            var match = !query || el.dataset.name.indexOf(query) > -1;
            var inBatch = query ? true : rowIdx < shown;
            var vis = match && inBatch;
            var wasHidden = el.classList.contains('vw-hide') || !el.hasAttribute('data-seen');
            el.classList.toggle('vw-hide', !vis);
            if (vis){
                visibleRows++;
                if (head && !headShown){ head.classList.remove('vw-hide'); headShown = true; }
                if (wasHidden && animateFrom !== undefined && rowIdx >= animateFrom){ el.classList.add('vw-in'); }
                el.setAttribute('data-seen', '1');
            }
            rowIdx++;
        });
        if (none) none.classList.toggle('vw-hide', !(query && visibleRows === 0));
    }

    function more(){
        if (query || shown >= totalRows) return;
        var from = shown;
        shown += BATCH;
        render(from);
    }

    if ('IntersectionObserver' in window && sentinel){
        new IntersectionObserver(function(en){ if (en[0].isIntersecting) more(); }, { rootMargin:'600px 0px' }).observe(sentinel);
    } else { shown = totalRows; }

    if (input) input.addEventListener('input', function(){
        query = this.value.trim().toLowerCase();
        render();
    });

    render();
})();
</script>
</body>
</html>