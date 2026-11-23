<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$uid = (int)$_SESSION['user_id'];

/* ── safe helpers ── */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function dq($conn, $sql){ try { $r = $conn->query($sql); return ($r instanceof mysqli_result) ? $r : null; } catch (Throwable $e) { return null; } }
function drows($conn, $sql){ $r = dq($conn, $sql); $o = []; if ($r) while ($x = $r->fetch_assoc()) $o[] = $x; return $o; }
function dcol($conn, $sql){ $r = dq($conn, $sql); if (!$r) return ''; $x = $r->fetch_row(); return trim((string)($x[0] ?? '')); }

/* CSRF token — same session structure profile.php uses, so Track requests are accepted there */
$c = $_SESSION['dz_csrf'] ?? null;
if (!is_array($c) || empty($c['t'])) { $c = ['t' => bin2hex(random_bytes(32)), 'p' => '', 'at' => time()]; }
elseif (time() - (int)$c['at'] > 7200) { $c = ['t' => bin2hex(random_bytes(32)), 'p' => $c['t'], 'at' => time()]; }
$_SESSION['dz_csrf'] = $c;
$csrf = $c['t'];

/* ── which profile type? ?type=Pets  or  ?user_id=ID (default: you) ── */
$type = trim((string)($_GET['type'] ?? ''));
$src = 'type'; $owner_name = ''; $unavailable = false;
if ($type === '') {
    $vid = (int)($_GET['user_id'] ?? 0);
    if ($vid <= 0) $vid = $uid;
    $src = ($vid === $uid) ? 'self' : 'other';
    if ($vid !== $uid) {
        $owner_name = dcol($conn, "SELECT username FROM users WHERE id = $vid LIMIT 1");
        $bl = drows($conn, "SELECT 1 FROM blocks WHERE (blocker_id = $uid AND blocked_id = $vid) OR (blocker_id = $vid AND blocked_id = $uid) LIMIT 1");
        if ($bl || $owner_name === '') $unavailable = true;
    }
    if (!$unavailable) $type = dcol($conn, "SELECT profile_type FROM user_settings WHERE user_id = $vid LIMIT 1");
}

/* ── people with the same type that you don't track (optional name search) ── */
const PAGE = 10;
function find_people($conn, $uid, $type, $offset, $limit, $q = ''){
    $lt = mb_strtolower($type);
    $base = " FROM user_settings us JOIN users u ON u.id = us.user_id
        WHERE LOWER(TRIM(us.profile_type)) = ? AND u.id <> ?
          AND NOT EXISTS (SELECT 1 FROM followers f WHERE f.follower_id = ? AND f.following_id = u.id)
          AND NOT EXISTS (SELECT 1 FROM blocks b WHERE (b.blocker_id = ? AND b.blocked_id = u.id) OR (b.blocker_id = u.id AND b.blocked_id = ?))";
    $types = "siiii"; $params = [$lt, $uid, $uid, $uid, $uid];
    if ($q !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $base .= " AND (u.username LIKE ? OR u.nickname LIKE ?)";
        $types .= "ss"; $params[] = $like; $params[] = $like;
    }
    $total = 0; $rows = [];
    try {
        $st = $conn->prepare("SELECT COUNT(*)" . $base);
        $st->bind_param($types, ...$params);
        $st->execute(); $total = (int)$st->get_result()->fetch_row()[0]; $st->close();

        $st = $conn->prepare("SELECT u.id, u.username, u.nickname, u.profile_pic, us.profile_type AS ptype,
            (SELECT COUNT(*) FROM followers f2 WHERE f2.following_id = u.id) AS trk" . $base . " ORDER BY trk DESC, u.id DESC LIMIT ? OFFSET ?");
        $p2 = $params; $p2[] = $limit; $p2[] = $offset;
        $st->bind_param($types . "ii", ...$p2);
        $st->execute(); $r = $st->get_result();
        while ($x = $r->fetch_assoc()) $rows[] = $x;
        $st->close();
    } catch (Throwable $e) {}
    return [$total, $rows];
}

function ptile($p){
    $t = strtolower((string)$p['type']); $f = (string)$p['file_name'];
    if ($t === 'text' || $f === '' || stripos($f, 'text.jpg') !== false)
        return '<span class="tile txt"><span>' . h(mb_substr((string)$p['caption'], 0, 70)) . '</span></span>';
    if ($t === 'video')
        return '<span class="tile"><video src="uploads/' . h($f) . '#t=0.1" muted playsinline preload="metadata"></video><i class="ri-play-fill tv"></i></span>';
    return '<span class="tile"><img src="uploads/' . h($f) . '" alt="" loading="lazy" onerror="this.src=\'uploads/Text.jpg\'"></span>';
}

function render_cards($conn, $uid, $rows){
    if (!$rows) return '';
    $ids = implode(',', array_map(function($r){ return (int)$r['id']; }, $rows));
    $private = [];
    foreach (drows($conn, "SELECT user_id FROM user_privacy WHERE user_id IN ($ids) AND privacy_level IN ('private','full')") as $x) $private[(int)$x['user_id']] = true;
    foreach (drows($conn, "SELECT user_id FROM user_settings WHERE user_id IN ($ids) AND is_fully_private = 1") as $x) $private[(int)$x['user_id']] = true;
    $pending = [];
    foreach (drows($conn, "SELECT target_id FROM track_requests WHERE requester_id = $uid AND target_id IN ($ids) AND status = 'pending'") as $x) $pending[(int)$x['target_id']] = true;

    $out = '';
    foreach ($rows as $u) {
        $id = (int)$u['id'];
        $name = trim((string)$u['nickname']) !== '' ? $u['nickname'] : $u['username'];
        $pic = !empty($u['profile_pic']) ? $u['profile_pic'] : 'uploads/me.png';
        $priv = !empty($private[$id]);
        $sub = '@' . $u['username'] . ' · ' . trim((string)$u['ptype']);

        if (!empty($pending[$id])) $btn = '<button type="button" class="tbtn pend" data-id="' . $id . '" data-priv="1" onclick="dzCancel(this)">Requested</button>';
        else $btn = '<button type="button" class="tbtn" data-id="' . $id . '" data-priv="' . ($priv ? 1 : 0) . '" onclick="dzTrack(this)">' . ($priv ? 'Request' : 'Track') . '</button>';

        if ($priv) {
            $strip = '<div class="lock"><i class="ri-lock-2-line"></i><span>This profile is private. Request to track to see their posts.</span></div>';
        } else {
            $posts = drows($conn, "SELECT file_name, type, caption FROM memes WHERE uploaded_by = $id ORDER BY created_at DESC LIMIT 3");
            if (!$posts) {
                $strip = '<div class="lock"><i class="ri-image-2-line"></i><span>No posts yet.</span></div>';
            } else {
                $strip = '<div class="strip">';
                for ($i = 0; $i < 3; $i++) $strip .= isset($posts[$i]) ? ptile($posts[$i]) : '<span class="tile empty"></span>';
                $strip .= '</div>';
            }
        }
        $out .= '<article class="card" id="c' . $id . '">'
              . '<div class="chead"><a class="who" href="profile.php?user_id=' . $id . '"><img class="av" src="' . h($pic) . '" onerror="this.src=\'uploads/me.png\'" alt="">'
              . '<span class="wn"><b>' . h($name) . '</b><em>' . h($sub) . '</em></span></a>' . $btn . '</div>'
              . '<a class="sw" href="profile.php?user_id=' . $id . '">' . $strip . '</a></article>';
    }
    return $out;
}

/* ── AJAX: search / next batch ── */
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    $off = max(0, (int)($_GET['offset'] ?? 0));
    $q = trim((string)($_GET['q'] ?? ''));
    list($tot, $rows) = ($type !== '' && !$unavailable) ? find_people($conn, $uid, $type, $off, PAGE, $q) : [0, []];
    echo json_encode(['html' => render_cards($conn, $uid, $rows), 'next' => $off + count($rows), 'more' => ($off + count($rows)) < $tot]);
    exit;
}

$total = 0; $rows = [];
if ($type !== '' && !$unavailable) list($total, $rows) = find_people($conn, $uid, $type, 0, PAGE);
$cards_html = render_cards($conn, $uid, $rows);
$more = count($rows) < $total;
$qs = ($type !== '' && $src === 'type') ? 'type=' . rawurlencode($type) : ($src === 'other' ? 'user_id=' . (int)($_GET['user_id'] ?? 0) : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= h($csrf) ?>">
<title><?= $type !== '' ? h($type) . ' · ' : '' ?>Explore People — Dezzme</title>
<script>try{if(localStorage.getItem('dz_theme')==='light')document.documentElement.setAttribute('data-theme','light');}catch(e){}</script>
<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; font-family:'Poppins', sans-serif; }
button, a { outline:none; -webkit-tap-highlight-color:transparent; cursor:pointer; }
a { text-decoration:none; color:inherit; }
[class^="ri-"], [class*=" ri-"], [class^="ri-"]::before, [class*=" ri-"]::before {
    font-family:'remixicon' !important; font-style:normal; font-weight:normal; line-height:1; speak:none; text-transform:none; -webkit-font-smoothing:antialiased;
}
:root{
    --red:#FF2D2D; --red-h:#ff1a1a;
    --fg:255,255,255;           /* R,G,B of the tint used for lines + soft fills (dark theme = white) */
    --ui-text:#ffffff;
    --text:#ffffff;
    --solid:#0d1030;
    --ph:#0d1030;               /* image / avatar placeholder */
    --bg:linear-gradient(-45deg,#010218,#070924,#070924);
    --surface:rgba(var(--fg),0.05); --line:rgba(var(--fg),0.09); --line2:rgba(var(--fg),0.14);
    --muted:rgba(var(--fg),0.45); --sub:rgba(var(--fg),0.62);
    --max-w:580px; --topbar-h:54px; --pad:clamp(9px,3vw,16px);
    --ctl-h:clamp(30px,8vw,36px); --r:5px;
    --act-h:clamp(25px,7.2vw,30px); --act-r:4px;
}
@media (max-width:490px){ :root{ --topbar-h:52.8px; } }
@media (max-width:380px){ :root{ --topbar-h:48.4px; } }
html{ scroll-behavior:smooth; }
@media (max-width:768px){
    html, body, *{ scrollbar-width:none !important; -ms-overflow-style:none !important; }
    *::-webkit-scrollbar{ display:none !important; width:0 !important; height:0 !important; }
}
body{ background:var(--bg); background-attachment:fixed; color:var(--text); min-height:100vh; display:flex; justify-content:center; overflow-x:hidden; -webkit-font-smoothing:antialiased; }
.ac-lane{ width:100%; max-width:var(--max-w); min-height:100vh; display:flex; flex-direction:column; padding-bottom:72px; }
@media (min-width:801px){ .ac-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); } }
@media (max-width:800px){ .ac-lane{ max-width:100%; } }

/* topbar — X left, title right, thin grey line under it on ALL screen sizes */
.ac-topbar{ position:sticky; top:0; z-index:500; height:var(--topbar-h); display:flex; align-items:center; gap:2px; padding:0 10px; background:var(--bg); background-attachment:fixed; border-bottom:1px solid var(--line); }
.ac-x{ width:34px; height:34px; background:none; border:none; color:var(--ui-text); display:flex; align-items:center; justify-content:center; opacity:.9; transition:opacity .2s,color .2s; padding:0; flex-shrink:0; }
.ac-x:hover{ opacity:1; color:var(--red); }
.ac-x svg{ width:22px; height:22px; display:block; }
.ac-title{ flex:1; min-width:0; font-size:.92rem; font-weight:700; letter-spacing:-.1px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; text-align:right; margin:0 6px 0 10px; color:var(--ui-text); }
@media (max-width:490px){ .ac-x{ width:33px; height:33px; } .ac-title{ font-size:.924rem; } }
@media (max-width:380px){ .ac-title{ font-size:.88rem; } }

/* search */
.search-wrap{ padding:clamp(9px,2.6vw,12px) var(--pad); border-bottom:1px solid var(--line); }
.search-inner{ position:relative; width:100%; }
.search-icon{ position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--muted); font-size:.95rem; pointer-events:none; }
.search-input{ width:100%; height:var(--ctl-h); background:var(--surface); border:1px solid var(--line2); border-radius:var(--r); padding:0 34px 0 34px; font-size:.76rem; color:var(--ui-text); outline:none; transition:border-color .2s,background .2s,box-shadow .2s; -webkit-appearance:none; appearance:none; }
.search-input::placeholder{ color:var(--muted); }
.search-input:focus{ border-color:rgba(255,45,45,.7); background:rgba(var(--fg),.07); box-shadow:0 0 0 3px rgba(255,45,45,.12); }
.search-x{ position:absolute; right:10px; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--muted); font-size:.8rem; display:none; }
@media (max-width:490px){ .search-input{ font-size:.77rem; padding-left:35px; } }

/* cards (explore rows) */
.card{ padding:clamp(12px,3.4vw,16px) 0 clamp(14px,4vw,18px); border-bottom:1px solid var(--line); transition:background .18s; }
.chead{ display:flex; align-items:center; gap:12px; padding:0 var(--pad); }
.who{ flex:1; min-width:0; display:flex; align-items:center; gap:clamp(10px,3vw,14px); }
.av{ width:clamp(44px,12.5vw,56px); height:clamp(44px,12.5vw,56px); border-radius:50%; object-fit:cover; flex-shrink:0; border:2px solid var(--red); background:var(--ph); transition:transform .2s; }
.card:hover .av{ transform:scale(1.04); }
.wn{ min-width:0; display:flex; flex-direction:column; gap:2px; }
.wn b{ font-size:clamp(.78rem,2.6vw,.9rem); font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; color:var(--ui-text); }
.wn em{ font-style:normal; font-size:clamp(.6rem,1.9vw,.7rem); color:var(--muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
/* Track / Request — solid red, black bold text (same in both themes) */
.tbtn{ flex-shrink:0; height:var(--act-h); min-width:clamp(80px,23vw,100px); padding:0 12px; border:none; border-radius:var(--act-r); background:var(--red); color:#000; font-size:.72rem; font-weight:700; line-height:1; transition:background .2s,transform .15s; white-space:nowrap; }
.tbtn:hover{ background:var(--red-h); }
.tbtn:active{ transform:scale(.98); }
.tbtn.pend, .tbtn.on{ background:rgba(var(--fg),.1); color:rgba(var(--fg),.6); font-weight:600; }
.tbtn.pend:hover, .tbtn.on:hover{ background:rgba(var(--fg),.2); }
.tbtn:disabled{ opacity:.6; cursor:default; }
@media (max-width:490px){ .tbtn{ font-size:.68rem; padding:0 9px; } }

.sw{ display:block; margin:12px var(--pad) 0; }
.strip{ display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:3px; border-radius:6px; overflow:hidden; }
.tile{ position:relative; display:block; aspect-ratio:4/5; background:var(--ph); overflow:hidden; }
.tile img, .tile video{ position:absolute; inset:0; width:100%; height:100%; object-fit:cover; display:block; pointer-events:none; transition:transform .3s ease; }
.sw:hover .tile img, .sw:hover .tile video{ transform:scale(1.04); }
/* text tiles + video badge sit on top of images / dark overlays, so their text stays white in both themes */
.tile.txt{ background:var(--ph) url('uploads/Text.jpg') center/cover no-repeat; }
.tile.txt::before{ content:''; position:absolute; inset:0; background:rgba(0,0,0,.5); }
.tile.txt span{ position:absolute; inset:0; padding:8px; display:flex; align-items:center; justify-content:center; text-align:center; font-size:clamp(.5rem,1.7vw,.6rem); line-height:1.45; font-weight:500; overflow:hidden; word-break:break-word; color:#fff; }
.tile.empty{ background:rgba(var(--fg),.04); }
.tv{ position:absolute; top:6px; right:6px; z-index:2; width:20px; height:20px; border-radius:6px; background:rgba(0,0,0,.55); display:flex; align-items:center; justify-content:center; font-size:12px; color:#fff; }
.lock{ display:flex; align-items:center; justify-content:center; gap:9px; padding:clamp(18px,6vw,28px) 16px; border-radius:6px; background:var(--surface); border:1px solid var(--line); color:var(--muted); font-size:clamp(.6rem,2vw,.7rem); line-height:1.6; text-align:center; }
.lock i{ font-size:1.2rem; color:var(--red); flex-shrink:0; }

/* empty states — same design as the Untracked page */
.empty-state{ flex:1; min-height:52vh; padding:clamp(28px,8vw,48px) clamp(20px,7vw,36px) clamp(36px,10vw,60px); display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; }
.es-title{ font-size:clamp(.95rem,3.4vw,1.1rem); font-weight:700; letter-spacing:-.2px; color:var(--ui-text); }
.es-text{ margin-top:6px; max-width:340px; font-size:clamp(.66rem,2.1vw,.76rem); color:var(--muted); line-height:1.7; }
.es-text strong{ color:var(--sub); font-weight:600; }
.es-tips{ width:100%; max-width:360px; margin-top:clamp(16px,5vw,24px); display:flex; flex-direction:column; gap:7px; }
.es-tip{ display:flex; align-items:center; gap:10px; padding:10px 12px; border-radius:var(--act-r); background:rgba(var(--fg),0.05); text-align:left; }
.es-tip i{ font-size:1rem; color:var(--red); flex-shrink:0; }
.es-tip span{ font-size:clamp(.62rem,2vw,.7rem); color:var(--sub); line-height:1.5; }
.es-btn{ margin-top:clamp(16px,5vw,22px); display:flex; align-items:center; justify-content:center; height:var(--act-h); min-width:150px; padding:0 22px; border-radius:var(--act-r); background:rgba(var(--fg),0.1); color:var(--ui-text); font-size:.72rem; font-weight:600; transition:background .2s,transform .15s; }
.es-btn:hover{ background:rgba(var(--fg),0.2); }
.es-btn:active{ transform:scale(.98); }
.es-btn.red{ background:var(--red); color:#000; font-weight:700; }
.es-btn.red:hover{ background:var(--red-h); }
@media (max-width:490px){ .es-btn{ font-size:.68rem; } }
.no-results{ display:none; flex-direction:column; align-items:center; gap:8px; padding:36px 0 24px; text-align:center; color:var(--muted); font-size:.78rem; }
.no-results i{ font-size:1.8rem; opacity:.35; }
.no-results.show{ display:flex; }

#sentinel{ height:1px; }
.skc{ padding:clamp(12px,3.4vw,16px) var(--pad) clamp(14px,4vw,18px); border-bottom:1px solid var(--line); }
.sk{ position:relative; overflow:hidden; background:rgba(var(--fg),.06); border-radius:6px; }
.sk::after{ content:''; position:absolute; inset:0; background:linear-gradient(90deg,transparent,rgba(var(--fg),.09),transparent); transform:translateX(-100%); animation:skS 1.5s infinite; }
@keyframes skS{ 100%{ transform:translateX(100%); } }
.skr{ display:flex; align-items:center; gap:12px; }

#page-skeleton{ position:fixed; inset:0; z-index:9999; background:var(--bg); background-attachment:fixed; display:flex; justify-content:center; opacity:1; transition:opacity .35s ease; pointer-events:none; }
#page-skeleton.hide{ opacity:0; }
.sk-lane{ width:100%; max-width:var(--max-w); overflow:hidden; }
@media (min-width:801px){ .sk-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); } }
@media (max-width:800px){ .sk-lane{ max-width:100%; } }

.toast{ position:fixed; bottom:34px; left:50%; transform:translateX(-50%) translateY(10px); background:var(--solid); border:1px solid var(--line2); color:var(--text); padding:8px 18px; border-radius:5px; font-size:.68rem; font-weight:500; z-index:99999; pointer-events:none; box-shadow:0 8px 28px rgba(0,0,0,.65); white-space:nowrap; opacity:0; transition:opacity .22s,transform .22s; }
.toast.show{ opacity:1; transform:translateX(-50%) translateY(0); }

/* responsive: user rows (+10%) */
@media (max-width:768px){
    .card{ padding:11px 0 13px; }
    .chead{ gap:11px; }
    .who{ gap:11px; }
    .av{ width:clamp(42px,11.7vw,51px); height:clamp(42px,11.7vw,51px); border-width:1.65px; }
    .wn b{ font-size:clamp(.77rem,2.5vw,.88rem); }
    .wn em{ font-size:clamp(.6rem,1.9vw,.69rem); }
    .tbtn{ height:calc((var(--act-h) - 3px) * 1.1); min-width:clamp(77px,22vw,95px); font-size:.68rem; padding:0 9px; }
    .sw{ margin-top:10px; }
    .strip{ gap:2.2px; border-radius:5.5px; }
    .tile{ aspect-ratio:1/1; }
    .lock{ padding:15px 13px; font-size:.64rem; }
    .lock i{ font-size:1.1rem; }
}
@media (max-width:380px){
    .av{ width:40px; height:40px; }
    .wn b{ font-size:.75rem; }
    .tbtn{ min-width:73px; font-size:.64rem; }
}

/* ═══════════ LIGHT MODE — <html data-theme="light"> (dark mode is never touched) ═══════════ */
:root[data-theme="light"]{
    color-scheme:light;
    --fg:6,7,26;
    --ui-text:#0b0d24;
    --text:#0b0d24;
    --solid:#ffffff;
    --surface:rgba(6,7,26,.05);
    --line:rgba(6,7,26,.1);
    --line2:rgba(6,7,26,.2);
    --muted:rgba(6,7,26,.52);
    --sub:rgba(6,7,26,.66);
    --bg:linear-gradient(-45deg,#ffffff,#f4f5fb,#f4f5fb);
    --ph:#eceef5;
}
[data-theme="light"] .toast{ box-shadow:0 8px 24px rgba(0,0,0,.18); }
</style>
</head>
<body>

<div id="page-skeleton" aria-hidden="true">
    <div class="sk-lane">
        <div style="height:var(--topbar-h);display:flex;align-items:center;justify-content:space-between;padding:0 16px 0 14px;border-bottom:1px solid var(--line)"><div class="sk" style="width:20px;height:20px"></div><div class="sk" style="width:110px;height:14px"></div></div>
        <div class="sk" style="height:var(--ctl-h);margin:clamp(9px,2.6vw,12px) var(--pad)"></div>
        <?php for ($i = 0; $i < 2; $i++): ?>
        <div class="skc" style="border-top:1px solid var(--line)"><div class="skr"><div class="sk" style="width:52px;height:52px;border-radius:50%;flex-shrink:0"></div><div style="flex:1;display:flex;flex-direction:column;gap:7px"><div class="sk" style="width:50%;height:12px"></div><div class="sk" style="width:35%;height:9px"></div></div><div class="sk" style="width:86px;height:28px"></div></div><div class="sk" style="height:clamp(110px,34vw,170px);margin-top:12px"></div></div>
        <?php endfor; ?>
    </div>
</div>

<div class="ac-lane">

    <div class="ac-topbar">
        <button type="button" class="ac-x" onclick="acClose()" aria-label="Close">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
        <span class="ac-title"><?= ($type !== '' && !$unavailable) ? h($type) : 'Explore People' ?></span>
    </div>

<?php if ($unavailable): ?>
    <div class="empty-state">
        <div class="es-title">Not available</div>
        <p class="es-text">You can't view this profile type right now.</p>
        <div class="es-tips">
            <div class="es-tip"><i class="ri-eye-off-line"></i><span>Profiles that have blocked you, or that you blocked, can't be explored.</span></div>
        </div>
        <a class="es-btn" href="home.php">Back to Home</a>
    </div>

<?php elseif ($type === ''): ?>
    <div class="empty-state">
        <div class="es-title"><?= $src === 'self' ? 'You have no profile type' : 'No profile type' ?></div>
        <p class="es-text"><?= $src === 'self' ? 'Choose a profile type like Photographer, Student or Meme Creator and you will see people with the same type here.' : '@' . h($owner_name) . ' has not chosen a profile type yet.' ?></p>
        <div class="es-tips">
            <div class="es-tip"><i class="ri-price-tag-3-line"></i><span>A profile type is a short label on your profile that tells people what you do.</span></div>
            <div class="es-tip"><i class="ri-group-line"></i><span>People with the same type that you don't track show up here.</span></div>
        </div>
        <?php if ($src === 'self'): ?><a class="es-btn red" href="profile.php?edit=profiletype">Choose a profile type</a>
        <?php else: ?><a class="es-btn" href="profile.php?user_id=<?= (int)($_GET['user_id'] ?? 0) ?>">Back to profile</a><?php endif; ?>
    </div>

<?php else: ?>
    <?php if ($total > 0): ?>
    <div class="search-wrap">
        <div class="search-inner">
            <i class="ri-search-line search-icon"></i>
            <input type="text" class="search-input" id="q" placeholder="Search users…" autocomplete="off">
            <button type="button" class="search-x" id="qx" aria-label="Clear search">✕</button>
        </div>
    </div>
    <?php endif; ?>

    <div id="list">
    <?php if ($cards_html === ''): ?>
        <div class="empty-state">
            <div class="es-title">You're tracking everyone</div>
            <p class="es-text">There is nobody else with the <strong><?= h($type) ?></strong> profile type that you don't already track. Check back later as more people join.</p>
            <div class="es-tips">
                <div class="es-tip"><i class="ri-user-follow-line"></i><span>You already track every person with this profile type.</span></div>
                <div class="es-tip"><i class="ri-notification-3-line"></i><span>New people with this type will show up here automatically.</span></div>
            </div>
            <a class="es-btn" href="search.php?focus=1">Search people</a>
        </div>
    <?php else: echo $cards_html; endif; ?>
    </div>
    <div class="no-results" id="noRes"><i class="ri-search-line"></i><span>No users found</span></div>
    <div id="sentinel"></div>
<?php endif; ?>

</div>
<div class="toast" id="toast" role="status"></div>

<script>
var CSRF = document.querySelector('meta[name="csrf-token"]').content;
var NEXT = <?= (int)count($rows) ?>, MORE = <?= $more ? 'true' : 'false' ?>, LOADING = false, Q = '', SEQ = 0;
var QS = <?= json_encode($qs) ?>;

function $(id){ return document.getElementById(id); }
function acClose(){
    if (document.referrer && document.referrer.indexOf(window.location.host) !== -1) history.back();
    else location.href = 'profile.php';
}
function toast(m){ var t = $('toast'); t.textContent = m; t.classList.add('show'); clearTimeout(t._t); t._t = setTimeout(function(){ t.classList.remove('show'); }, 2400); }

/* ── track / request / cancel / untrack (posts to profile.php, same fields it already reads) ── */
function refreshToken(){
    return fetch('profile.php?csrf_refresh=1', { credentials:'same-origin', cache:'no-store' })
        .then(function(r){ return r.json(); }).then(function(d){ if (d && d.token) CSRF = d.token; return CSRF; });
}
function post(id, action){
    function send(){
        return fetch('profile.php?user_id=' + id, {
            method:'POST', credentials:'same-origin',
            headers:{ 'Content-Type':'application/x-www-form-urlencoded', 'X-CSRF-Token':CSRF },
            body: action + '=1&target_id=' + id + '&csrf_token=' + encodeURIComponent(CSRF)
        });
    }
    return send().then(function(r){ if (r.status !== 403) return r; return refreshToken().then(send); })
        .then(function(r){ if (!r.ok) throw new Error('failed'); return r; });
}
function busy(btn, on){
    if (on){ btn._l = btn.textContent; btn.style.minWidth = btn.offsetWidth + 'px'; btn.textContent = '…'; btn.disabled = true; }
    else { btn.disabled = false; btn.textContent = btn._l; }
}
function dzTrack(btn){
    if (btn.disabled) return;
    var id = btn.getAttribute('data-id'), priv = btn.getAttribute('data-priv') === '1';
    busy(btn, true);
    post(id, priv ? 'send_track_request' : 'follow').then(function(){
        btn.disabled = false;
        if (priv){ btn.className = 'tbtn pend'; btn.textContent = 'Requested'; btn.setAttribute('onclick', 'dzCancel(this)'); toast('Request sent'); }
        else { btn.className = 'tbtn on'; btn.textContent = 'Untrack'; btn.setAttribute('onclick', 'dzUntrack(this)'); toast('Now tracking'); }
    }).catch(function(){ busy(btn, false); toast('Something went wrong'); });
}
function dzCancel(btn){
    if (btn.disabled) return;
    busy(btn, true);
    post(btn.getAttribute('data-id'), 'cancel_track_request').then(function(){
        btn.disabled = false; btn.className = 'tbtn'; btn.textContent = 'Request'; btn.setAttribute('onclick', 'dzTrack(this)'); toast('Request cancelled');
    }).catch(function(){ busy(btn, false); toast('Something went wrong'); });
}
function dzUntrack(btn){
    if (btn.disabled) return;
    busy(btn, true);
    post(btn.getAttribute('data-id'), 'unfollow').then(function(){
        btn.disabled = false; btn.className = 'tbtn'; btn.textContent = 'Track'; btn.setAttribute('onclick', 'dzTrack(this)'); toast('Untracked');
    }).catch(function(){ busy(btn, false); toast('Something went wrong'); });
}

/* ── loading: search + infinite scroll, 10 at a time ── */
function skeleton(n){
    var h = '';
    for (var i = 0; i < n; i++) h += '<div class="skc js-sk"><div class="skr"><div class="sk" style="width:52px;height:52px;border-radius:50%;flex-shrink:0"></div><div style="flex:1;display:flex;flex-direction:column;gap:7px"><div class="sk" style="width:50%;height:12px"></div><div class="sk" style="width:35%;height:9px"></div></div><div class="sk" style="width:86px;height:28px"></div></div><div class="sk" style="height:clamp(110px,34vw,170px);margin-top:12px"></div></div>';
    $('list').insertAdjacentHTML('beforeend', h);
}
function clearSk(){ document.querySelectorAll('.js-sk').forEach(function(s){ s.remove(); }); }
function url(off){ return 'profile_type.php?ajax=1&offset=' + off + (Q ? '&q=' + encodeURIComponent(Q) : '') + (QS ? '&' + QS : ''); }

function loadMore(){
    if (LOADING || !MORE) return;
    LOADING = true; var s = SEQ; skeleton(2);
    fetch(url(NEXT), { credentials:'same-origin' }).then(function(r){ return r.json(); }).then(function(d){
        if (s !== SEQ) return;
        clearSk(); $('list').insertAdjacentHTML('beforeend', d.html);
        NEXT = d.next; MORE = d.more; LOADING = false;
    }).catch(function(){ clearSk(); LOADING = false; });
}
function runSearch(){
    var s = ++SEQ; LOADING = true; MORE = false; NEXT = 0;
    $('noRes').classList.remove('show');
    $('list').innerHTML = ''; skeleton(3);
    fetch(url(0), { credentials:'same-origin' }).then(function(r){ return r.json(); }).then(function(d){
        if (s !== SEQ) return;
        $('list').innerHTML = d.html;
        NEXT = d.next; MORE = d.more; LOADING = false;
        $('noRes').classList.toggle('show', d.html === '');
    }).catch(function(){ if (s === SEQ){ $('list').innerHTML = ''; LOADING = false; } });
}
(function(){
    var q = $('q'), x = $('qx'), t;
    if (q){
        q.addEventListener('input', function(){
            x.style.display = q.value ? 'block' : 'none';
            clearTimeout(t); t = setTimeout(function(){ Q = q.value.trim(); runSearch(); }, 350);
        });
        x.addEventListener('click', function(){ q.value = ''; x.style.display = 'none'; Q = ''; runSearch(); q.focus(); });
    }
    var s = $('sentinel');
    if (s && 'IntersectionObserver' in window)
        new IntersectionObserver(function(e){ if (e[0].isIntersecting) loadMore(); }, { rootMargin:'600px 0px' }).observe(s);
})();

/* ── page-load skeleton ── */
(function(){
    var sk = $('page-skeleton'); if (!sk) return;
    var done = false;
    function hide(){ if (done) return; done = true; sk.classList.add('hide'); setTimeout(function(){ if (sk.parentNode) sk.remove(); }, 380); }
    window.addEventListener('load', function(){ setTimeout(hide, 280); });
    setTimeout(hide, 1500);
})();
</script>
</body>
</html>