<?php
session_start();
include 'config.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
if(!$user_id){
    header("Location: login.php");
    exit();
}

/* ─────────────────────────────────────────────────────────
   Small helpers
───────────────────────────────────────────────────────── */
function ap_rows($conn, $sql, $types, $params){
    $rows = [];
    try{
        $st = @$conn->prepare($sql);
        if(!$st) return $rows;
        $st->bind_param($types, ...$params);
        $st->execute();
        $res = $st->get_result();
        if($res){ while($r = $res->fetch_assoc()) $rows[] = $r; }
        $st->close();
    }catch(Throwable $e){ /* table missing or query failed: just show nothing for this source */ }
    return $rows;
}

function ap_clean($text, $fallback, $max = 48){
    $t = trim(preg_replace('/\s+/', ' ', strip_tags((string)$text)));
    if($t === '') return $fallback;
    return mb_strlen($t) > $max ? mb_substr($t, 0, $max - 1).'…' : $t;
}

function ap_status($raw){
    $s = strtolower(trim((string)$raw));
    return in_array($s, ['approved','pending','rejected'], true) ? $s : 'pending';
}

function ap_days($n){
    $n = (int)$n;
    return $n > 0 ? $n.' day'.($n > 1 ? 's' : '') : '';
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

function ap_money($n){ return '$'.number_format((float)$n, 2); }

/* ─────────────────────────────────────────────────────────
   Collect every ad payment this user has made
   1) Post ads (meme_ads)   2) Sponsorships   3) Page post ads
───────────────────────────────────────────────────────── */
$ads = [];
$now_ts = time();

/* 1 ── Post ads */
$rows = ap_rows($conn, "
    SELECT a.id, a.cta_text, a.duration_days, a.price, a.status, a.submitted_at,
           a.approved_at, a.reviewed_at, a.expires_at,
           m.caption, m.file_name, m.type
    FROM meme_ads a
    JOIN memes m ON m.id = a.meme_id
    WHERE m.uploaded_by = ?
", "i", [$user_id]);

foreach($rows as $r){
    $status = ap_status($r['status']);
    $start  = $r['approved_at'] ?: ($r['reviewed_at'] ?: null);
    $end    = null;
    if($r['expires_at']) $end = strtotime($r['expires_at']);
    elseif($status === 'approved' && $start && (int)$r['duration_days'] > 0) $end = strtotime($start.' +'.(int)$r['duration_days'].' days');

    $type  = $r['type'] ?? 'image';
    $thumb = ($type === 'image' && $r['file_name'] !== '' && $r['file_name'] !== '0') ? 'uploads/'.$r['file_name'] : '';
    $icon  = $type === 'video' ? 'ri-video-line' : ($type === 'text' ? 'ri-file-text-line' : 'ri-image-line');

    $ads[] = [
        'title'  => ap_clean($r['caption'], 'Post ad'),
        'kind'   => 'Post ad',
        'detail' => ($r['cta_text'] ? 'Button: '.ap_clean($r['cta_text'], '', 24) : ''),
        'days'   => ap_days($r['duration_days']),
        'price'  => (float)$r['price'],
        'status' => $status,
        'date'   => $r['submitted_at'],
        'end'    => $end,
        'thumb'  => $thumb,
        'icon'   => $icon,
    ];
}

/* 2 ── Sponsorships */
$rows = ap_rows($conn, "
    SELECT id, title, image, meme_image, type, price, status, starts_at, ends_at, created_at
    FROM sponsorships
    WHERE user_id = ?
", "i", [$user_id]);

foreach($rows as $r){
    $status = ap_status($r['status']);
    $img    = $r['image'] ?: $r['meme_image'];
    $thumb  = ($img && preg_match('/\.(jpe?g|png|gif|webp)$/i', $img)) ? 'uploads/'.$img : '';
    $ads[] = [
        'title'  => ap_clean($r['title'], 'Sponsorship'),
        'kind'   => 'Sponsorship',
        'detail' => $r['type'] ? $r['type'].' plan' : '',
        'days'   => '',
        'price'  => (float)$r['price'],
        'status' => $status,
        'date'   => $r['created_at'],
        'end'    => $r['ends_at'] ? strtotime($r['ends_at']) : null,
        'thumb'  => $thumb,
        'icon'   => 'ri-megaphone-line',
    ];
}

/* 3 ── Page post ads */
$rows = ap_rows($conn, "
    SELECT p.id, p.caption, p.file_name, p.type, p.ad_days, p.ad_price, p.ad_status,
           p.created_at, pp.name AS page_name
    FROM profile_page_posts p
    LEFT JOIN profile_pages pp ON pp.id = p.page_id
    WHERE p.posted_by = ? AND p.is_ad = 1
", "i", [$user_id]);

foreach($rows as $r){
    $status = ap_status($r['ad_status']);
    $end    = ($status === 'approved' && (int)$r['ad_days'] > 0) ? strtotime($r['created_at'].' +'.(int)$r['ad_days'].' days') : null;
    $type   = $r['type'] ?? 'image';
    $thumb  = ($type === 'image' && $r['file_name']) ? $r['file_name'] : '';
    $ads[] = [
        'title'  => ap_clean($r['caption'], 'Page post ad'),
        'kind'   => 'Page ad',
        'detail' => $r['page_name'] ? ap_clean($r['page_name'], '', 24) : '',
        'days'   => ap_days($r['ad_days']),
        'price'  => (float)$r['ad_price'],
        'status' => $status,
        'date'   => $r['created_at'],
        'end'    => $end,
        'thumb'  => $thumb,
        'icon'   => $type === 'video' ? 'ri-video-line' : 'ri-pages-line',
    ];
}

/* Newest first */
usort($ads, function($a, $b){ return strtotime($b['date']) <=> strtotime($a['date']); });

/* Totals + groups */
$groups = ['all' => [], 'approved' => [], 'pending' => [], 'rejected' => []];
$total_spent   = 0.0;
$pending_total = 0.0;
foreach($ads as $ad){
    $groups['all'][] = $ad;
    $groups[$ad['status']][] = $ad;
    if($ad['status'] === 'approved') $total_spent   += $ad['price'];
    if($ad['status'] === 'pending')  $pending_total += $ad['price'];
}
$all_count      = count($groups['all']);
$approved_count = count($groups['approved']);
$pending_count  = count($groups['pending']);
$rejected_count = count($groups['rejected']);

/* ─────────────────────────────────────────────────────────
   Row renderer
───────────────────────────────────────────────────────── */
function ap_render_row($ad, $now_ts){
    $label = 'Pending'; $cls = 'pending';
    if($ad['status'] === 'approved'){
        if($ad['end'] && $ad['end'] < $now_ts){ $label = 'Ended';  $cls = 'ended'; }
        elseif($ad['end'])                     { $label = 'Active'; $cls = 'approved'; }
        else                                   { $label = 'Approved'; $cls = 'approved'; }
    } elseif($ad['status'] === 'rejected'){
        $label = 'Rejected'; $cls = 'rejected';
    }

    $ts    = strtotime($ad['date']);
    $ago   = timeAgo($ad['date']);
    $exact = date('M j, Y · g:i A', $ts);

    $kind_parts = array_filter([$ad['kind'], $ad['days'], $ad['detail']]);
    $kind_line  = implode(' · ', $kind_parts);

    $counted = $ad['status'] === 'approved' ? '' : ' not-counted';

    echo '<div class="user-row ad-row'.$counted.'">';
    echo   '<div class="ad-thumb"><i class="'.htmlspecialchars($ad['icon']).'"></i>';
    if($ad['thumb']){
        echo '<img src="'.htmlspecialchars($ad['thumb']).'" alt="" loading="lazy" onerror="this.remove()">';
    }
    echo   '</div>';
    echo   '<div class="user-info">';
    echo     '<div class="uname">'.htmlspecialchars($ad['title']).'</div>';
    echo     '<div class="umeta"><i class="ri-price-tag-3-line"></i> '.htmlspecialchars($kind_line).'</div>';
    echo     '<div class="umeta"><i class="ri-time-line"></i> '.$ago.'</div>';
    echo     '<div class="uexact">'.$exact.'</div>';
    echo   '</div>';
    echo   '<div class="row-actions">';
    echo     '<div class="status-badge '.$cls.'">'.$label.'</div>';
    echo     '<div class="price-badge">'.ap_money($ad['price']).'</div>';
    echo   '</div>';
    echo '</div>';
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
<title>Your Ad Payments | Dezzme</title>

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
    --green:    #4ade80;
    --amber:    #fbbf24;
    --bg:       linear-gradient(-45deg,#010218,#070924,#070924);
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
   TOPBAR — X on the left, "Ad Payments" on the right (no icon)
══════════════════════════════════════ */
.ac-topbar{
    position:sticky; top:0; z-index:500;
    height:var(--topbar-h);
    display:flex; align-items:center; gap:2px;
    padding:0 10px;
    background:var(--bg);
    background-attachment:fixed;
}
.ac-topbar::after{
    content:''; position:absolute; left:0; right:0; bottom:0;
    height:1px; background:rgba(255,255,255,0.14);
    pointer-events:none; z-index:2;
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

/* ── Intro header ── */
.profile-header{
    display:flex; flex-direction:column; align-items:center;
    padding:clamp(14px, 4vw, 22px) var(--pad) clamp(16px, 4.5vw, 24px);
    text-align:center;
}
.hdr-icon{
    width:clamp(64px, 19vw, 90px); height:clamp(64px, 19vw, 90px);
    border-radius:50%; display:flex; align-items:center; justify-content:center;
    border:2.6px solid var(--red);
    box-shadow:0 4px 18px rgba(255,45,45,0.25);
    background:rgba(255,45,45,0.08);
    color:var(--red); font-size:clamp(1.7rem, 7vw, 2.4rem);
    margin-bottom:12px;
    transition:transform .3s, box-shadow .3s;
}
.hdr-icon:hover{ transform:scale(1.05); box-shadow:0 0 22px rgba(255,45,45,0.5); }
.profile-name{
    font-size:clamp(.88rem, 3.4vw, 1.05rem); font-weight:700;
    margin-bottom:3px; max-width:100%;
}
.profile-label{
    font-size:clamp(.58rem, 1.9vw, .68rem); color:var(--muted); font-weight:500;
    text-transform:uppercase; letter-spacing:.08em;
}
.transparency-note{
    font-size:clamp(.6rem, 1.9vw, .68rem); color:var(--muted);
    line-height:1.65; margin-top:12px; max-width:380px; text-align:center;
}
.transparency-note a{ color:var(--red); text-decoration:none; font-weight:600; }

/* ── Stats bar ── */
.stats-bar{
    display:grid; grid-template-columns:repeat(3, 1fr);
    border-top:1px solid var(--line); border-bottom:1px solid var(--line);
}
.stat-item{ display:flex; flex-direction:column; align-items:center; padding:clamp(11px, 3.4vw, 16px) 6px; text-align:center; min-width:0; }
.stat-item + .stat-item{ border-left:1px solid var(--line); }
.stat-num{ font-size:clamp(.88rem, 3.2vw, 1.2rem); font-weight:700; color:var(--text); line-height:1; max-width:100%; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.stat-num.red{ color:var(--red); }
.stat-lbl{ font-size:clamp(.48rem, 1.7vw, .6rem); color:var(--muted); margin-top:4px; letter-spacing:.03em; text-transform:uppercase; }

/* ── Section label + how-it-works notes ── */
.section-label{
    padding:clamp(14px, 4vw, 20px) var(--pad) 6px;
    font-size:.6rem; font-weight:700; text-transform:uppercase; letter-spacing:1.5px; color:var(--muted);
}
.mutual-notes{ padding:clamp(6px, 2vw, 10px) var(--pad) clamp(14px, 4vw, 20px); display:flex; flex-direction:column; gap:8px; }
.mutual-note{ font-size:clamp(.6rem, 1.9vw, .7rem); color:var(--muted); line-height:1.65; }
.mutual-note strong{ color:rgba(255,255,255,0.7); font-weight:600; }
.mutual-note .dot{ display:inline-block; width:6px; height:6px; border-radius:50%; margin-right:6px; vertical-align:middle; position:relative; top:-1px; }
.dot.g{ background:var(--green); } .dot.a{ background:var(--amber); } .dot.r{ background:var(--red); }

/* ── Tabs ── */
.tabs-wrap{
    display:flex;
    border-top:1px solid var(--line); border-bottom:1px solid var(--line);
    position:sticky; top:var(--topbar-h); z-index:400;
    background:var(--bg); background-attachment:fixed;
}
.tab-btn{
    flex:1; padding:clamp(9px, 2.8vw, 13px) 4px;
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

/* ── Context note ── */
.context-note{
    font-size:clamp(.6rem, 1.9vw, .7rem); color:var(--muted); line-height:1.65;
    padding:clamp(11px, 3.4vw, 16px) var(--pad);
    border-bottom:1px solid var(--line);
    display:flex; align-items:flex-start; gap:8px;
}
.context-note i{ color:var(--red); font-size:.9rem; flex-shrink:0; margin-top:1px; }

/* ── Ad list ── */
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

.ad-thumb{
    position:relative; overflow:hidden; flex-shrink:0;
    width:clamp(36px, 10vw, 46px); height:clamp(36px, 10vw, 46px);
    border-radius:8px; border:2px solid var(--red); background:#1a1a1a;
    display:flex; align-items:center; justify-content:center;
    color:var(--red); font-size:1.05rem;
    transition:transform .2s;
}
.ad-thumb img{ position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }
.user-row:hover .ad-thumb{ transform:scale(1.04); }
.ad-row.not-counted .ad-thumb{ border-color:var(--line2); color:var(--muted); }
@media (max-width:490px){ .ad-thumb{ border-width:1.5px; } }

.user-info{ flex:1; min-width:0; }
.uname{
    font-size:clamp(.74rem, 2.4vw, .86rem); font-weight:600; color:var(--text);
    display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.umeta{
    font-size:clamp(.6rem, 1.9vw, .7rem); color:var(--muted); margin-top:2px;
    display:flex; align-items:center; gap:5px;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.umeta i{ font-size:.78rem; flex-shrink:0; }
.uexact{ font-size:clamp(.54rem, 1.7vw, .62rem); color:rgba(255,255,255,0.28); margin-top:2px; }

/* ── Right side: status button (coloured words) + price button (white words).
      Same look and size as the action buttons on the profile page. ── */
.row-actions{
    flex-shrink:0;
    display:flex; flex-direction:column; gap:6px;
    width:clamp(88px, 25vw, 110px);
}
.status-badge, .price-badge{
    display:flex; align-items:center; justify-content:center;
    width:100%; height:var(--act-h); line-height:1;
    padding:3px 8px; border-radius:var(--act-r);
    font-size:0.72rem; font-weight:600;
    background:rgba(255,255,255,0.1);
    backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    border:none; white-space:nowrap; overflow:hidden;
    cursor:default;
}
.status-badge{ font-weight:700; }
.status-badge.approved{ color:var(--green); }
.status-badge.pending{ color:var(--amber); }
.status-badge.rejected{ color:var(--red); }
.status-badge.ended{ color:var(--sub); }
.price-badge{ color:#fff; font-variant-numeric:tabular-nums; }
.ad-row.not-counted .price-badge{ color:var(--muted); text-decoration:line-through; text-decoration-color:rgba(255,255,255,.25); }
@media (max-width:490px){
    .status-badge, .price-badge{ font-size:0.62rem; padding:3px 6px; }
}
@media (max-width:360px){
    .status-badge, .price-badge{ font-size:0.58rem; }
}

/* ── Empty state ── */
.empty-state{
    flex:1; min-height:46vh;
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
.sk-topbar{ height:var(--topbar-h); display:flex; align-items:center; justify-content:space-between; padding:0 16px 0 14px; border-bottom:1px solid rgba(255,255,255,0.14); }
.sk-back{ width:20px; height:20px; }
.sk-title{ width:100px; height:14px; }
.sk-head{ display:flex; flex-direction:column; align-items:center; gap:10px; padding:20px 0; }
.sk-circle{ width:clamp(64px,19vw,90px); height:clamp(64px,19vw,90px); border-radius:50%; }
.sk-name{ width:130px; height:14px; }
.sk-sub{ width:90px; height:10px; }
.sk-stats{ display:flex; border-top:1px solid var(--line); border-bottom:1px solid var(--line); }
.sk-stat{ flex:1; height:56px; border-radius:0; }
.sk-stat + .sk-stat{ margin-left:1px; }
.sk-row{ display:flex; align-items:center; gap:14px; padding:clamp(11px,3.2vw,14px) var(--pad); border-top:1px solid var(--line); }
.sk-avatar{ width:clamp(36px,10vw,46px); height:clamp(36px,10vw,46px); border-radius:8px; flex-shrink:0; }
.sk-info{ flex:1; display:flex; flex-direction:column; gap:7px; }
.sk-line1{ width:110px; height:12px; }
.sk-line2{ width:70px; height:9px; }
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

    /* intro */
    .profile-header{ padding:clamp(15px, 4.4vw, 24px) var(--pad) clamp(18px, 5vw, 26px); }
    .hdr-icon{ width:clamp(70px, 20.9vw, 99px); height:clamp(70px, 20.9vw, 99px); border-width:2.9px; margin-bottom:13px; }
    .profile-name{ font-size:clamp(.97rem, 3.74vw, 1.155rem); margin-bottom:3.3px; }
    .profile-label{ font-size:clamp(.64rem, 2.09vw, .75rem); }
    .transparency-note{ font-size:clamp(.6996rem, 2.2154vw, .795rem); margin-top:13px; max-width:443px; }

    /* stats */
    .stat-item{ padding:clamp(12px, 3.74vw, 17.6px) 6.6px; }
    .stat-num{ font-size:clamp(.97rem, 3.52vw, 1.32rem); }
    .stat-lbl{ font-size:clamp(.53rem, 1.87vw, .66rem); margin-top:4.4px; }

    /* notes */
    .section-label{ padding:clamp(15px, 4.4vw, 22px) var(--pad) 6.6px; font-size:.6996rem; letter-spacing:1.65px; }
    .mutual-notes{ padding:clamp(7px, 2.2vw, 11px) var(--pad) clamp(15px, 4.4vw, 22px); gap:8.8px; }
    .mutual-note{ font-size:clamp(.6996rem, 2.2154vw, .8162rem); }

    /* tabs */
    .tab-btn{ padding:clamp(10px, 3.08vw, 14px) 4.4px; gap:3.3px; }
    .tab-count{ font-size:clamp(.88rem, 3.3vw, 1.155rem); }
    .tab-label{ font-size:clamp(.55rem, 1.98vw, .68rem); gap:5.5px; }

    /* context note */
    .context-note{ font-size:clamp(.66rem, 2.09vw, .77rem); padding:clamp(12px, 3.74vw, 17.6px) var(--pad); gap:8.8px; }
    .context-note i{ font-size:.99rem; }

    /* ad rows */
    .user-row{ gap:clamp(11px, 3.3vw, 15px); padding:clamp(12px, 3.52vw, 15px) var(--pad); }
    .ad-thumb{ width:clamp(40px, 11vw, 51px); height:clamp(40px, 11vw, 51px); border-width:2.2px; border-radius:8.8px; font-size:1.15rem; }
    .uname{ font-size:clamp(.81rem, 2.64vw, .95rem); }
    .umeta{ font-size:clamp(.66rem, 2.09vw, .77rem); margin-top:2.2px; gap:5.5px; }
    .umeta i{ font-size:.86rem; }
    .uexact{ font-size:clamp(.59rem, 1.87vw, .68rem); margin-top:2.2px; }

    /* right-side buttons */
    .row-actions{ gap:6.6px; width:clamp(97px, 27.5vw, 121px); }
    .status-badge, .price-badge{ padding:3.3px 8.8px; font-size:.79rem; }

    /* empty state */
    .empty-state{ padding:clamp(31px, 8.8vw, 53px) clamp(22px, 7.7vw, 40px) clamp(40px, 11vw, 66px); }
    .es-title{ font-size:clamp(1.045rem, 3.74vw, 1.21rem); }
    .es-text{ margin-top:6.6px; max-width:374px; font-size:clamp(.73rem, 2.31vw, .84rem); }
    .es-tips{ max-width:396px; margin-top:clamp(18px, 5.5vw, 26px); gap:7.7px; }
    .es-tip{ gap:11px; padding:11px 13px; }
    .es-tip i{ font-size:1.1rem; }
    .es-tip span{ font-size:clamp(.68rem, 2.2vw, .77rem); }
    .es-btn{ margin-top:clamp(18px, 5.5vw, 24px); min-width:165px; padding:0 24px; font-size:.79rem; }

    /* skeleton */
    .sk-topbar{ padding:0 17.6px 0 15px; }
    .sk-back{ width:22px; height:22px; }
    .sk-title{ width:110px; height:15.4px; }
    .sk-head{ gap:11px; padding:22px 0; }
    .sk-circle{ width:clamp(70px, 20.9vw, 99px); height:clamp(70px, 20.9vw, 99px); }
    .sk-name{ width:143px; height:15.4px; }
    .sk-sub{ width:99px; height:11px; }
    .sk-stat{ height:62px; }
    .sk-row{ gap:15px; padding:clamp(12px, 3.52vw, 15px) var(--pad); }
    .sk-avatar{ width:clamp(40px, 11vw, 51px); height:clamp(40px, 11vw, 51px); }
    .sk-info{ gap:7.7px; }
    .sk-line1{ width:121px; height:13px; }
    .sk-line2{ width:77px; height:10px; }
    .sk-btn{ width:clamp(97px, 27.5vw, 121px); }
}

@media (max-width:490px){
    :root{ --topbar-h:53px; }
    .ac-x{ width:33px; height:33px; }
    .ac-title{ font-size:.92rem; }
    .ad-thumb{ border-width:1.65px; }
    .status-badge, .price-badge{ font-size:.68rem; padding:3.3px 6.6px; }
    .es-btn{ font-size:.68rem; }
}

@media (max-width:380px){
    :root{ --topbar-h:48px; }
    .ac-title{ font-size:.88rem; }
}

@media (max-width:360px){
    .status-badge, .price-badge{ font-size:.64rem; }
}
</style>
</head>
<body>

<!-- ══ SKELETON ══ -->
<div id="page-skeleton">
    <div class="sk-lane">
        <div class="sk-topbar">
            <div class="sk sk-back"></div>
            <div class="sk sk-title"></div>
        </div>
        <div class="sk-head">
            <div class="sk sk-circle"></div>
            <div class="sk sk-name"></div>
            <div class="sk sk-sub"></div>
        </div>
        <div class="sk-stats">
            <div class="sk sk-stat"></div>
            <div class="sk sk-stat"></div>
            <div class="sk sk-stat"></div>
        </div>
        <?php for($i=0;$i<5;$i++): ?>
        <div class="sk-row">
            <div class="sk sk-avatar"></div>
            <div class="sk-info">
                <div class="sk sk-line1"></div>
                <div class="sk sk-line2"></div>
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
        <span class="ac-title">Ad Payments</span>
    </div>

    <!-- ══ INTRO ══ -->
    <div class="profile-header">
        <div class="hdr-icon"><i class="ri-wallet-3-line"></i></div>
        <div class="profile-name">Your Ad Payments</div>
        <div class="profile-label">Everything you've spent on ads</div>
        <p class="transparency-note">
            This page is your receipt book for Dezzme ads. Every time you pay to promote a post, a page post, or a sponsorship, it's recorded here with the date, the amount, and where it stands, so you always know exactly where your money went.
        </p>
    </div>

    <!-- ══ HOW IT WORKS ══ -->
    <div class="section-label">How it works</div>
    <div class="mutual-notes">
        <div class="mutual-note">
            <span class="dot g"></span><strong>Approved</strong> ads passed review and are running or have finished running. Only approved ads count toward your total spent.
        </div>
        <div class="mutual-note">
            <span class="dot a"></span><strong>Pending</strong> ads are waiting for our team to review them. They're not counted in your total yet.
        </div>
        <div class="mutual-note">
            <span class="dot r"></span><strong>Rejected</strong> ads didn't meet the ad guidelines. They're listed so you have a record, but they are not counted in your total.
        </div>
        <div class="mutual-note">
            <strong>Active</strong> means an approved ad is still running. <strong>Ended</strong> means its time is up. Newest payments are always at the top.
        </div>
    </div>

    <!-- ══ STATS ══ -->
    <div class="stats-bar">
        <div class="stat-item">
            <div class="stat-num red"><?php echo ap_money($total_spent); ?></div>
            <div class="stat-lbl">Total spent</div>
        </div>
        <div class="stat-item">
            <div class="stat-num"><?php echo number_format($approved_count); ?></div>
            <div class="stat-lbl">Approved ads</div>
        </div>
        <div class="stat-item">
            <div class="stat-num"><?php echo ap_money($pending_total); ?></div>
            <div class="stat-lbl">Awaiting review</div>
        </div>
    </div>

    <!-- ══ TABS ══ -->
    <div class="tabs-wrap">
        <button type="button" class="tab-btn active" data-tab="tab-all">
            <span class="tab-count"><?php echo number_format($all_count); ?></span>
            <span class="tab-label">All</span>
        </button>
        <button type="button" class="tab-btn" data-tab="tab-approved">
            <span class="tab-count"><?php echo number_format($approved_count); ?></span>
            <span class="tab-label">Approved</span>
        </button>
        <button type="button" class="tab-btn" data-tab="tab-pending">
            <span class="tab-count"><?php echo number_format($pending_count); ?></span>
            <span class="tab-label">Pending</span>
        </button>
        <button type="button" class="tab-btn" data-tab="tab-rejected">
            <span class="tab-count"><?php echo number_format($rejected_count); ?></span>
            <span class="tab-label">Rejected</span>
        </button>
    </div>

    <?php
    /* Per-tab copy for the empty states */
    $empty_copy = [
        'approved' => ['No approved ads yet',  "Once one of your ads passes review, it shows up here and counts toward your total spent."],
        'pending'  => ['Nothing waiting for review', "Ads you've just paid for will appear here while our team reviews them."],
        'rejected' => ['No rejected ads', "Nice. None of your ads have been turned down."],
    ];
    ?>

    <!-- ══ ALL ══ -->
    <div class="user-list active" id="tab-all">
        <?php if($all_count > 0): ?>
            <p class="context-note"><i class="ri-information-line"></i> Showing every ad payment on your account, newest first. Rejected and pending ads are dimmed because they're not counted in your total spent.</p>
            <?php foreach($groups['all'] as $ad) ap_render_row($ad, $now_ts); ?>
        <?php else: ?>
            <div class="empty-state">
                <div class="es-title">No ad payments yet</div>
                <p class="es-text">You haven't paid for any ads so far. When you promote a post or sponsor something on Dezzme, the payment will show up here with the exact time it happened.</p>
                <div class="es-tips">
                    <div class="es-tip"><i class="ri-history-line"></i><span>Every payment is listed with its date, amount, and status.</span></div>
                    <div class="es-tip"><i class="ri-shield-check-line"></i><span>You're only counted as having spent money on ads that are approved.</span></div>
                </div>
                <a class="es-btn" href="profile.php?user_id=<?php echo $user_id; ?>">Back to profile</a>
            </div>
        <?php endif; ?>
    </div>

    <!-- ══ APPROVED / PENDING / REJECTED ══ -->
    <?php foreach(['approved','pending','rejected'] as $g): ?>
    <div class="user-list" id="tab-<?php echo $g; ?>">
        <?php if(count($groups[$g]) > 0): ?>
            <?php foreach($groups[$g] as $ad) ap_render_row($ad, $now_ts); ?>
        <?php else: ?>
            <div class="empty-state">
                <div class="es-title"><?php echo $empty_copy[$g][0]; ?></div>
                <p class="es-text"><?php echo $empty_copy[$g][1]; ?></p>
                <?php if($all_count === 0): ?>
                <div class="es-tips">
                    <div class="es-tip"><i class="ri-history-line"></i><span>Every payment is listed with its date, amount, and status.</span></div>
                </div>
                <?php endif; ?>
                <a class="es-btn" href="profile.php?user_id=<?php echo $user_id; ?>">Back to profile</a>
            </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

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
    });
});
</script>
</body>
</html>