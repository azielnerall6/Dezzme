<?php
session_start();
include 'config.php';
require_once __DIR__ . '/earth_countries.php';

$current_user = (int)($_SESSION['user_id'] ?? 0);
if(!$current_user){ header("Location: login.php"); exit(); }

/* ══════════════════════════════════════════════════════════════════
   EARTH DATA  (earth.php?data=1)  — COUNTRY LEVEL ONLY
   • activity: how many posts / people posted in the last EARTH_WINDOW_MIN minutes, per country.
     A country is only shown when at least EARTH_MIN_PEOPLE different people posted, so nobody can be picked out.
   • pods: public pods grouped by the country of the person who created them (top EARTH_PODS_PER_COUNTRY per country).
   Private accounts are never counted. The country comes from the "Current location" text in About me.
   Nothing returned here is a city, a coordinate of a person, or a user id.
   ══════════════════════════════════════════════════════════════════ */
const EARTH_WINDOW_MIN        = 30;   // "right now" = the last 30 minutes
const EARTH_MIN_PEOPLE        = 3;    // lower this (e.g. 1) while your site is small; keep it at 3+ once you have many users
const EARTH_PODS_PER_COUNTRY  = 6;
const EARTH_CACHE_SECONDS     = 20;

/* [user_id => ISO country] for a list of users (private accounts left out) */
function earth_user_countries($conn, array $uids){
    $uids = array_values(array_unique(array_filter(array_map('intval', $uids))));
    $out = [];
    if(!$uids) return $out;
    $tbl = '';
    try{
        $q = $conn->query("SELECT c.TABLE_NAME FROM information_schema.COLUMNS c
                           JOIN information_schema.COLUMNS u ON u.TABLE_SCHEMA = c.TABLE_SCHEMA AND u.TABLE_NAME = c.TABLE_NAME AND u.COLUMN_NAME = 'user_id'
                           WHERE c.TABLE_SCHEMA = DATABASE() AND c.COLUMN_NAME = 'current_location' LIMIT 1");
        if($q instanceof mysqli_result && ($r = $q->fetch_assoc())) $tbl = (string)$r['TABLE_NAME'];
    }catch(Throwable $e){}
    if($tbl === '' || !preg_match('/^[A-Za-z0-9_]+$/', $tbl)) return $out;
    $in = implode(',', $uids);
    $private = [];
    try{
        $q = $conn->query("SELECT user_id FROM user_settings WHERE user_id IN ($in) AND is_fully_private = 1");
        if($q instanceof mysqli_result) while($r = $q->fetch_assoc()) $private[(int)$r['user_id']] = true;
    }catch(Throwable $e){}
    try{
        $q = $conn->query("SELECT user_id, current_location FROM `$tbl` WHERE user_id IN ($in)");
        if($q instanceof mysqli_result) while($r = $q->fetch_assoc()){
            $uid = (int)$r['user_id'];
            if(isset($private[$uid])) continue;
            $cc = earth_country_of($r['current_location'] ?? '');
            if($cc) $out[$uid] = $cc;
        }
    }catch(Throwable $e){}
    return $out;
}

function earth_build($conn){
    $T = earth_country_table();
    $res = ['ts' => time(), 'window' => EARTH_WINDOW_MIN, 'activity' => [], 'pods' => []];

    /* live activity */
    $posters = [];
    try{
        $q = $conn->query("SELECT uploaded_by, COUNT(*) c FROM memes
                           WHERE created_at >= (NOW() - INTERVAL " . (int)EARTH_WINDOW_MIN . " MINUTE) AND COALESCE(is_ad,0) = 0
                           GROUP BY uploaded_by LIMIT 5000");
        if($q instanceof mysqli_result) while($r = $q->fetch_assoc()) $posters[(int)$r['uploaded_by']] = (int)$r['c'];
    }catch(Throwable $e){}
    if($posters){
        $map = earth_user_countries($conn, array_keys($posters));
        $agg = [];
        foreach($posters as $uid => $c){
            if(!isset($map[$uid])) continue;
            $cc = $map[$uid];
            if(!isset($agg[$cc])) $agg[$cc] = ['people' => 0, 'posts' => 0];
            $agg[$cc]['people']++; $agg[$cc]['posts'] += $c;
        }
        foreach($agg as $cc => $a){
            if($a['people'] < EARTH_MIN_PEOPLE || !isset($T[$cc])) continue;
            $res['activity'][] = ['c' => $cc, 'n' => $T[$cc]['n'], 'lat' => $T[$cc]['lat'], 'lon' => $T[$cc]['lon'], 'posts' => $a['posts'], 'people' => $a['people']];
        }
    }

    /* pods by country */
    $pods = [];
    try{
        $q = $conn->query("SELECT g.id, g.title, g.group_pic, g.created_by,
                                  (SELECT COUNT(*) FROM group_members gm WHERE gm.group_id = g.id) mc
                           FROM `groups` g
                           WHERE COALESCE(g.privacy,'public') = 'public'
                           ORDER BY mc DESC, g.id DESC LIMIT 3000");
        if($q instanceof mysqli_result) while($r = $q->fetch_assoc()) $pods[] = $r;
    }catch(Throwable $e){}
    if($pods){
        $map = earth_user_countries($conn, array_column($pods, 'created_by'));
        $by = [];
        foreach($pods as $p){
            $uid = (int)$p['created_by'];
            if(!isset($map[$uid])) continue;
            $cc = $map[$uid];
            if(!isset($by[$cc])) $by[$cc] = ['count' => 0, 'top' => []];
            $by[$cc]['count']++;
            if(count($by[$cc]['top']) < EARTH_PODS_PER_COUNTRY){
                $pic = (string)($p['group_pic'] ?? '');
                $by[$cc]['top'][] = ['id' => (int)$p['id'], 't' => (string)$p['title'], 'm' => (int)$p['mc'],
                                     'p' => $pic === '' ? 'uploads/default_group.png' : (strpos($pic, 'uploads/') === 0 ? $pic : 'uploads/' . $pic)];
            }
        }
        foreach($by as $cc => $b){
            if(!isset($T[$cc])) continue;
            $res['pods'][] = ['c' => $cc, 'n' => $T[$cc]['n'], 'lat' => $T[$cc]['lat'], 'lon' => $T[$cc]['lon'], 'count' => $b['count'], 'top' => $b['top']];
        }
    }
    return $res;
}

if(isset($_GET['data'])){
    if(session_status() === PHP_SESSION_ACTIVE) session_write_close();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $cf = sys_get_temp_dir() . '/dz_earth_' . md5(__DIR__) . '.json';
    $json = null;
    if(is_file($cf) && (time() - (int)@filemtime($cf)) < EARTH_CACHE_SECONDS) $json = @file_get_contents($cf);
    if(!$json){
        $json = json_encode(earth_build($conn), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        @file_put_contents($cf, $json, LOCK_EX);
    }
    echo $json; exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>DezzMap | Dezzme</title>
<!-- theme: applied before first paint so there is no dark flash in light mode (same key as every other Dezzme page) -->
<script>try{if(localStorage.getItem('dz_theme')==='light')document.documentElement.setAttribute('data-theme','light');}catch(e){}</script>

<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Bebas+Neue&display=swap" rel="stylesheet">
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
    --fg:       255,255,255;
    --ui-text:  #ffffff;
    --red:      #FF2D2D;
    --bg:       linear-gradient(-45deg,#010218,#070924,#070924);
    --surface:  rgba(var(--fg),0.05);
    --line:     rgba(var(--fg),0.09);
    --line2:    rgba(var(--fg),0.14);
    --text:     #ffffff;
    --muted:    rgba(var(--fg),0.45);
    --sub:      rgba(var(--fg),0.62);
    --sheet-bg: #0d1030;
    --max-w:    580px;
    --topbar-h: 54px;
    --pad:      clamp(9px, 3vw, 16px);
    --r:        5px;
}
@media (max-width:490px){ :root{ --topbar-h:48px; } }
@media (max-width:380px){ :root{ --topbar-h:44px; } }
@media (max-width:800px){ :root{ --topbar-h:59px; } }
@media (max-width:490px){ :root{ --topbar-h:53px; } }
@media (max-width:380px){ :root{ --topbar-h:48px; } }

html{ scroll-behavior:smooth; }
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

/* ── centered lane + grey side lines (same as About This Account) ── */
.ac-lane{
    width:100%; max-width:var(--max-w);
    height:100vh; height:100dvh;
    display:flex; flex-direction:column;
}
@media (min-width:801px){
    .ac-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); }
}
@media (max-width:800px){ .ac-lane{ max-width:100%; } }

/* ── Topbar: X left, title right ── */
.ac-topbar{
    position:relative; z-index:500; flex-shrink:0;
    height:var(--topbar-h);
    display:flex; align-items:center; gap:2px;
    padding:0 10px;
    background:var(--bg);
    background-attachment:fixed;
}
.ac-topbar::after{ display:none; }   /* no grey line under the bar */
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
@media (max-width:800px){
    .ac-topbar{ padding:0 11px; }
    .ac-x{ width:37px; height:37px; }
    .ac-x svg{ width:24px; height:24px; }
    .ac-title{ font-size:1.01rem; margin:0 7px 0 11px; }
}
@media (max-width:490px){ .ac-x{ width:33px; height:33px; } .ac-title{ font-size:.92rem; } }

/* "DEZZMAP" word: exactly the same look as the "GAMES" word on the Games page (Bebas Neue, wide letter-spacing, same sizes) */
.ac-title, :root[data-theme="light"] .ac-title{
    font-family:'Bebas Neue',sans-serif !important; font-weight:400 !important; color:var(--red) !important;
    font-size:clamp(24px,6.5vw,32px) !important; letter-spacing:clamp(5px,1.8vw,8px) !important; line-height:1 !important;
    padding-left:0 !important; text-transform:none; text-align:right; margin:0 calc(8px - clamp(5px,1.8vw,8px)) 0 10px !important;   /* right side of the bar; the pull-back cancels the letter-spacing after the last letter */
}
@media (max-width:768px){ .ac-title, :root[data-theme="light"] .ac-title{ font-size:clamp(24px,6.8vw,31px) !important; } }

/* ══════════════ GLOBE STAGE ══════════════ */
.globe-stage{
    position:relative; flex:1; min-height:0;
    overflow:hidden;
    touch-action:none;
    user-select:none; -webkit-user-select:none;
}
#globe-canvas{
    position:absolute; inset:0; width:100%; height:100%;
    display:block; cursor:grab; outline:none;
}
#globe-canvas.dragging{ cursor:grabbing; }

/* loader */
.globe-loader{
    position:absolute; inset:0; z-index:5;
    display:flex; flex-direction:column; align-items:center; justify-content:center; gap:14px;
    background:var(--bg); background-attachment:fixed;
    transition:opacity .5s ease; pointer-events:none;
}
.globe-loader.hide{ opacity:0; }
.loader-ball{
    width:clamp(96px, 30vw, 150px); height:clamp(96px, 30vw, 150px); border-radius:50%;
    background:rgba(var(--fg),.06); position:relative; overflow:hidden;
}
.loader-ball::after{
    content:''; position:absolute; inset:0;
    background:linear-gradient(90deg, transparent, rgba(var(--fg),.1), transparent);
    transform:translateX(-100%); animation:skShimmer 1.4s infinite;
}
@keyframes skShimmer{ 100%{ transform:translateX(100%); } }
.loader-text{ font-size:.7rem; color:var(--muted); letter-spacing:.04em; }

/* hint */
.globe-hint{
    position:absolute; left:0; right:0; top:14px; z-index:3;
    display:flex; justify-content:center; pointer-events:none;
    transition:opacity .6s ease;
}
.globe-hint.hide{ opacity:0; }
.globe-hint span{
    display:flex; align-items:center; gap:7px;
    font-size:.7rem; font-weight:500; color:var(--sub);
    background:rgba(var(--fg),.07);
    backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    border-radius:var(--r); padding:7px 13px;
}
.globe-hint i{ color:var(--red); font-size:.9rem; }
@media (max-width:800px){ .globe-hint span{ font-size:.77rem; padding:8px 14px; } }

/* controls */
.globe-ctl{
    position:absolute; right:var(--pad); bottom:calc(var(--pad) + env(safe-area-inset-bottom, 0px)); z-index:4;
    display:flex; flex-direction:column; gap:8px;
}
.ctl-btn{
    width:38px; height:38px;
    display:flex; align-items:center; justify-content:center;
    background:rgba(var(--fg),.1);
    backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    border:none; border-radius:var(--r);
    color:var(--ui-text); font-size:1.1rem;
    transition:background .2s, color .2s, transform .15s;
}
.ctl-btn:hover{ background:rgba(var(--fg),.2); }
.ctl-btn:active{ transform:scale(.94); }
.ctl-btn.on{ background:rgba(255,45,45,.16); color:var(--red); }
@media (max-width:800px){ .ctl-btn{ width:42px; height:42px; font-size:1.2rem; } }

/* live chip */
.globe-live{
    position:absolute; left:var(--pad); bottom:calc(var(--pad) + env(safe-area-inset-bottom, 0px)); z-index:4;
    display:flex; align-items:center; gap:8px; max-width:calc(100% - 120px);
    font-size:.72rem; font-weight:500; color:var(--sub);
    background:rgba(var(--fg),.08);
    backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    border-radius:var(--r); padding:8px 12px;
    transition:opacity .4s ease;
}
.globe-live .dot{ width:8px; height:8px; border-radius:50%; background:var(--red); flex-shrink:0; animation:livePulse 1.6s ease-out infinite; }
.globe-live.quiet .dot{ background:var(--muted); animation:none; }
.globe-live.off{ opacity:0; pointer-events:none; }
@keyframes livePulse{ 0%{ box-shadow:0 0 0 0 rgba(255,45,45,.6); } 100%{ box-shadow:0 0 0 9px rgba(255,45,45,0); } }
@media (max-width:800px){ .globe-live{ font-size:.77rem; } }

/* country sheet */
.earth-sheet{
    position:absolute; left:0; right:0; bottom:0; z-index:6;
    background:var(--sheet-bg); border-top:1px solid var(--line2);
    border-radius:14px 14px 0 0;
    padding:14px var(--pad) calc(16px + env(safe-area-inset-bottom, 0px));
    max-height:62%; overflow-y:auto;
    transform:translateY(105%); transition:transform .28s cubic-bezier(.22,1,.36,1);
    box-shadow:0 -10px 30px rgba(0,0,0,.35);
}
.earth-sheet.open{ transform:translateY(0); }
.es-head{ display:flex; align-items:center; gap:10px; margin-bottom:4px; }
.es-title{ flex:1; min-width:0; font-size:1rem; font-weight:700; color:var(--ui-text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.es-x{ width:32px; height:32px; border:none; background:rgba(var(--fg),.08); color:var(--ui-text); border-radius:50%; font-size:1.1rem; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.es-live{ display:flex; align-items:center; gap:8px; font-size:.78rem; color:var(--sub); margin:2px 0 10px; }
.es-live .dot{ width:8px; height:8px; border-radius:50%; background:var(--red); flex-shrink:0; }
.es-sec{ font-size:.7rem; font-weight:600; letter-spacing:.05em; text-transform:uppercase; color:var(--muted); margin:10px 0 6px; }
.es-pod{ display:flex; align-items:center; gap:11px; padding:9px 0; text-decoration:none; color:var(--ui-text); border-top:1px solid var(--line); }
.es-pod:first-of-type{ border-top:none; }
.es-pod img{ width:42px; height:42px; border-radius:8px; object-fit:cover; background:rgba(var(--fg),.08); flex-shrink:0; }
.es-pod .t{ font-size:.88rem; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.es-pod .m{ font-size:.72rem; color:var(--muted); margin-top:1px; }
.es-pod .go{ margin-left:auto; color:var(--muted); font-size:1.1rem; flex-shrink:0; }
.es-empty{ font-size:.8rem; color:var(--muted); padding:6px 0; }
.es-more{ display:block; text-align:center; margin-top:10px; padding:10px; border-radius:var(--r); background:rgba(var(--fg),.08); color:var(--ui-text); font-size:.82rem; font-weight:600; text-decoration:none; }

/* ═══════════ LIGHT MODE ═══════════ */
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
    --sheet-bg:#ffffff;
}

/* ══════════════ REAL MAP (zoom in from the globe) ══════════════ */
:root{ --pill-bg:rgba(8,10,28,.84); --pill-fg:#ffffff; }
:root[data-theme="light"]{ --pill-bg:rgba(255,255,255,.94); --pill-fg:#0b0d24; }
#globe-canvas{ transition:opacity .4s ease; }
.map-stage{
    position:absolute; inset:0; z-index:2;
    opacity:0; visibility:hidden; pointer-events:none;
    transition:opacity .4s ease, visibility 0s linear .4s;
    background:var(--sheet-bg);
}
.map-stage.show{ opacity:1; visibility:visible; pointer-events:auto; transition:opacity .4s ease; }
#map{ position:absolute; inset:0; }
.maplibregl-ctrl-attrib{ font-size:10px !important; }
.maplibregl-ctrl-bottom-left{ z-index:1; }

.map-top{
    position:absolute; left:0; right:0; top:calc(12px + env(safe-area-inset-top, 0px)); z-index:5;
    display:none; align-items:center; justify-content:space-between; gap:10px; padding:0 var(--pad);
    pointer-events:none;
}
.globe-stage.map-on .map-top{ display:flex; }
.map-top > *{ pointer-events:auto; }
.mt-circle{
    width:42px; height:42px; border-radius:50%; border:none; flex-shrink:0;
    background:var(--pill-bg); color:var(--pill-fg); font-size:1.3rem;
    display:flex; align-items:center; justify-content:center;
    backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    box-shadow:0 2px 10px rgba(0,0,0,.25);
}
.mt-pill{
    height:42px; padding:0 18px; border-radius:999px; border:none;
    background:var(--pill-bg); color:var(--pill-fg); font-size:.86rem; font-weight:600; white-space:nowrap;
    backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    box-shadow:0 2px 10px rgba(0,0,0,.25);
}
.mt-circle:active, .mt-pill:active{ transform:scale(.96); }

.globe-stage.map-on .globe-only{ display:none; }
.map-only{ display:none; }
.globe-stage.map-on .map-only{ display:flex; }
.globe-stage.map-on .globe-hint, .globe-stage.map-on .tour-chip{ top:calc(66px + env(safe-area-inset-top, 0px)); }
#mt-back{ visibility:hidden; }                 /* the map IS the home view now, so there is nothing to go back to — the spacer keeps the pill centred */
.globe-stage.map-first .map-stage{ background:radial-gradient(1.5px 1.5px at 12% 18%, rgba(255,255,255,.8) 50%, transparent 51%), radial-gradient(1px 1px at 78% 12%, rgba(255,255,255,.7) 50%, transparent 51%), radial-gradient(1.5px 1.5px at 64% 82%, rgba(255,255,255,.75) 50%, transparent 51%), radial-gradient(1px 1px at 22% 74%, rgba(255,255,255,.65) 50%, transparent 51%), radial-gradient(1px 1px at 90% 58%, rgba(255,255,255,.6) 50%, transparent 51%), radial-gradient(1.5px 1.5px at 40% 8%, rgba(255,255,255,.7) 50%, transparent 51%), radial-gradient(1px 1px at 8% 48%, rgba(255,255,255,.6) 50%, transparent 51%), var(--bg); }
:root[data-theme="light"] .globe-stage.map-first .map-stage{ background:var(--bg); }

/* tour chip + toast */
.tour-chip{
    position:absolute; left:50%; top:14px; z-index:3; transform:translate(-50%,-8px);
    max-width:calc(100% - 32px); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    font-size:.72rem; font-weight:500; color:var(--sub);
    background:rgba(var(--fg),.08); backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    border-radius:var(--r); padding:7px 13px; opacity:0; pointer-events:none;
    transition:opacity .4s ease, transform .4s ease;
}
.tour-chip.on{ opacity:1; transform:translate(-50%,0); }
.tour-chip i{ color:var(--red); margin-right:6px; }
.earth-toast{
    position:absolute; left:50%; bottom:calc(76px + env(safe-area-inset-bottom, 0px)); z-index:8;
    transform:translate(-50%,10px); max-width:calc(100% - 32px);
    background:var(--pill-bg); color:var(--pill-fg); font-size:.78rem; font-weight:500;
    border-radius:var(--r); padding:9px 14px; opacity:0; pointer-events:none;
    box-shadow:0 4px 16px rgba(0,0,0,.3); transition:opacity .25s ease, transform .25s ease; text-align:center;
}
.earth-toast.on{ opacity:1; transform:translate(-50%,0); }

/* map pins (country level only) */
.mk-pod{
    width:34px; height:34px; border-radius:50%; padding:0; border:3px solid #fff; background:var(--red); color:#fff;
    font-size:.72rem; font-weight:700; line-height:1; display:flex; align-items:center; justify-content:center;
    box-shadow:0 2px 8px rgba(0,0,0,.4); cursor:pointer;
}
.mk-live{ position:relative; width:14px; height:14px; cursor:pointer; }
.mk-live b{ position:absolute; inset:0; border-radius:50%; background:var(--red); }
.mk-live i{
    position:absolute; left:50%; top:50%; width:14px; height:14px; margin:-7px 0 0 -7px; border-radius:50%;
    border:2px solid var(--red); animation:mkPulse 2.6s ease-out infinite;
}
.mk-live i:nth-child(2){ animation-delay:.87s; }
.mk-live i:nth-child(3){ animation-delay:1.73s; }
.perf .mk-live i:nth-child(n+2){ display:none; }
@keyframes mkPulse{ 0%{ transform:scale(1); opacity:.85; } 100%{ transform:scale(6); opacity:0; } }
.mk-me{ width:16px; height:16px; border-radius:50%; background:#2b8fe0; border:3px solid #fff; box-shadow:0 0 0 6px rgba(43,143,224,.25); }
@media (prefers-reduced-motion:reduce){ .mk-live i{ animation:none; opacity:.4; } }

/* sheet: drag handle + filter chips */
.es-grab{ display:flex; justify-content:center; padding:2px 0 10px; margin-top:-6px; touch-action:none; cursor:grab; }
.es-grab span{ width:38px; height:4px; border-radius:4px; background:rgba(var(--fg),.28); }
.earth-sheet.tall{ max-height:90%; }
.earth-sheet.drag{ transition:none; }
.es-chips{ display:flex; gap:8px; margin:6px 0 4px; overflow-x:auto; scrollbar-width:none; }
.es-chips::-webkit-scrollbar{ display:none; }
.es-chips button{
    border:none; border-radius:var(--r); padding:8px 14px; font-size:.78rem; font-weight:600; flex-shrink:0;
    background:rgba(var(--fg),.08); color:var(--ui-text);
}
.es-chips button.on{ background:rgba(255,45,45,.16); color:var(--red); }
#es-body[data-f="live"] .es-pods{ display:none; }
#es-body[data-f="pods"] .es-live{ display:none; }
.es-country{ font-size:.92rem; font-weight:700; color:var(--ui-text); margin:14px 0 2px; }
.es-country:first-child{ margin-top:6px; }
.es-pod .ring{ width:46px; height:46px; border-radius:50%; padding:2px; background:linear-gradient(45deg,#FF2D2D,#ff8a3d); flex-shrink:0; }
.es-pod .ring img{ width:100%; height:100%; border-radius:50%; border:2px solid var(--sheet-bg); object-fit:cover; background:rgba(var(--fg),.08); }
.es-near{ display:block; width:100%; border:none; text-align:center; margin-top:10px; padding:11px; border-radius:var(--r); background:rgba(255,45,45,.14); color:var(--red); font-size:.84rem; font-weight:600; }
.es-attr{ font-size:.64rem; color:var(--muted); margin-top:14px; text-align:center; }
#btn-perf.on{ background:rgba(255,45,45,.16); color:var(--red); }

/* place shown from a profile / pod location (earth.php?loc=…) */
.mk-place{ width:38px; height:38px; margin-top:-19px; border-radius:50% 50% 50% 0; transform:rotate(-45deg); background:var(--red); border:3px solid #fff; box-shadow:0 3px 10px rgba(0,0,0,.45); display:flex; align-items:center; justify-content:center; }
.mk-place::after{ content:''; width:12px; height:12px; border-radius:50%; background:#fff; }
.earth-sheet.place-mode .es-chips{ display:none; }
.es-place-name{ font-size:.86rem; color:var(--ui-text); line-height:1.45; padding:2px 0 6px; }
/* solid (not see-through) controls on top of the map */
.globe-stage.map-on{ --ctl-solid:#171a3a; --ctl-fg:#ffffff; }
:root[data-theme="light"] .globe-stage.map-on{ --ctl-solid:#ffffff; --ctl-fg:#0b0d24; }
.globe-stage.map-on .ctl-btn{ background:var(--ctl-solid); color:var(--ctl-fg); backdrop-filter:none; -webkit-backdrop-filter:none; box-shadow:0 2px 8px rgba(0,0,0,.3); }
.globe-stage.map-on .ctl-btn.on{ color:var(--red); }
.globe-stage.map-on .globe-live, .globe-stage.map-on .globe-hint span, .globe-stage.map-on .tour-chip{ background:var(--ctl-solid); color:var(--ctl-fg); backdrop-filter:none; -webkit-backdrop-filter:none; box-shadow:0 2px 8px rgba(0,0,0,.3); }
.mt-circle, .mt-pill{ background:var(--ctl-solid,#171a3a) !important; color:var(--ctl-fg,#fff) !important; backdrop-filter:none !important; -webkit-backdrop-filter:none !important; }
.globe-stage.map-first .map-stage{ background:#04051a !important; }
:root[data-theme="light"] .globe-stage.map-first .map-stage{ background:#e9edf6 !important; }
.map-stage{ background:#04051a; }
:root[data-theme="light"] .map-stage{ background:#e9edf6; }

/* ══════════════ BOTTOM SHEET — the same design as the profile page's own sheets ══════════════ */
:root{ --lane:#070924; --surface-3:#090b29; --bs-s:1; }
:root[data-theme="light"]{ --lane:#ffffff; --surface-3:#f1f3fa; }
@media (max-width:768px){ :root{ --bs-s:1.1; } }
.earth-sheet{
    background:var(--lane); border:none; border-radius:0; overflow:hidden;
    padding:calc(5px * var(--bs-s)) calc(10px * var(--bs-s)) calc(env(safe-area-inset-bottom, 0px) + calc(10px * var(--bs-s)));
    display:flex; flex-direction:column; gap:calc(8px * var(--bs-s));
    max-height:62%; box-shadow:0 -12px 34px rgba(0,0,0,.5);
}
@media (min-width:791px){ .earth-sheet{ border-radius:calc(8px * var(--bs-s)) calc(8px * var(--bs-s)) 0 0; } }
.earth-sheet.tall{ max-height:90%; }
.es-grab{ display:flex; justify-content:center; padding:7px 0 8px; margin:-3px 0 -2px; flex-shrink:0; touch-action:none; cursor:grab; }
.es-kind, .es-title{ touch-action:none; cursor:grab; }
.earth-sheet.sized .es-card{ flex:1 1 auto; }
.earth-sheet{ transition:transform .28s cubic-bezier(.22,1,.36,1), height .26s cubic-bezier(.22,1,.36,1); }
.earth-sheet.drag{ transition:none; }
.es-grab span{ width:calc(30px * var(--bs-s)); height:calc(3px * var(--bs-s)); border-radius:2px; background:rgba(var(--fg),.22); display:block; }
.es-card{ background:var(--surface-3); border-radius:calc(6px * var(--bs-s)); overflow:hidden; flex-shrink:1; min-height:0; display:flex; flex-direction:column; }
.es-scroll{ overflow-y:auto; min-height:0; scrollbar-width:thin; scrollbar-color:rgba(var(--fg),.15) transparent; }
.es-scroll::-webkit-scrollbar{ width:calc(4px * var(--bs-s)); }
.es-scroll::-webkit-scrollbar-thumb{ background:rgba(var(--fg),.15); border-radius:999px; }
.es-kind{ padding:calc(7px * var(--bs-s)) calc(12px * var(--bs-s)) calc(3px * var(--bs-s)); font-size:calc(8.5px * var(--bs-s)); color:rgba(var(--fg),.35); letter-spacing:1px; text-transform:uppercase; }
.es-title{
    padding:calc(2px * var(--bs-s)) calc(14px * var(--bs-s)) calc(10px * var(--bs-s)); text-align:center; white-space:normal; overflow:visible;
    font-size:clamp(14px,3.8vw,16px); font-weight:700; line-height:1.4; color:#FF2D2D; word-break:break-word; letter-spacing:.1px;
}
.es-chips{ padding:0 calc(12px * var(--bs-s)) calc(8px * var(--bs-s)); margin:0; }
.es-chips button{ border-radius:5px; background:rgba(var(--fg),.07); }
.es-chips button.on{ background:rgba(255,45,45,.16); color:var(--red); }
#es-body .es-sec, #es-body .es-country{
    margin:0; padding:calc(12px * var(--bs-s)) calc(14px * var(--bs-s)) calc(4px * var(--bs-s)); border-top:1px solid rgba(var(--fg),.08);
    font-size:calc(10px * var(--bs-s)); font-weight:500; color:rgba(var(--fg),.35); letter-spacing:1px; text-transform:uppercase;
}
#es-body > .es-sec:first-child, #es-body > .es-pods > .es-sec:first-child{ border-top:none; }
#es-body .es-country{ color:rgba(var(--fg),.6); }
#es-body .es-live{ margin:0; padding:calc(10px * var(--bs-s)) calc(14px * var(--bs-s)); border-top:1px solid rgba(var(--fg),.08); font-size:calc(11.5px * var(--bs-s)); color:var(--sub); }
#es-body .es-live .dot{ box-shadow:0 0 0 3px rgba(255,45,45,.18); }
#es-body .es-pod{ gap:calc(10px * var(--bs-s)); padding:calc(10px * var(--bs-s)) calc(14px * var(--bs-s)); border-top:1px solid rgba(var(--fg),.08); transition:background .2s; }
#es-body .es-pod:hover{ background:rgba(var(--fg),.06); }
#es-body .es-pod:first-of-type{ border-top:1px solid rgba(var(--fg),.08); }
#es-body .es-pod .ring{ width:calc(36px * var(--bs-s)); height:calc(36px * var(--bs-s)); padding:1.5px; }
#es-body .es-pod .t{ font-size:calc(13px * var(--bs-s)); }
#es-body .es-pod .m{ font-size:calc(10px * var(--bs-s)); color:rgba(var(--fg),.4); }
#es-body .es-empty{ padding:calc(12px * var(--bs-s)) calc(16px * var(--bs-s)); text-align:center; font-size:calc(11.5px * var(--bs-s)); line-height:1.5; border-top:1px solid rgba(var(--fg),.08); }
#es-body .es-more, #es-body .es-near{
    display:block; width:100%; margin:0; padding:calc(12px * var(--bs-s)); border:none; border-top:1px solid rgba(var(--fg),.08); border-radius:0; background:none;
    text-align:center; color:#FF2D2D; font-size:calc(12px * var(--bs-s)); font-weight:600; text-decoration:none;
}
#es-body .es-more:hover, #es-body .es-near:hover{ background:rgba(var(--fg),.06); }
#es-body .es-place-name{ padding:calc(6px * var(--bs-s)) calc(14px * var(--bs-s)) calc(12px * var(--bs-s)); text-align:center; font-size:calc(12px * var(--bs-s)); color:rgba(var(--fg),.62); line-height:1.5; }
#es-body .es-attr{ margin:0; padding:calc(8px * var(--bs-s)) calc(14px * var(--bs-s)) calc(10px * var(--bs-s)); border-top:1px solid rgba(var(--fg),.08); }
.es-close{
    display:flex; align-items:center; justify-content:center; width:100%; flex-shrink:0; padding:calc(10px * var(--bs-s));
    background:var(--surface-3); border:none; border-radius:calc(6px * var(--bs-s)); color:rgba(var(--fg),.85);
    font-size:calc(12px * var(--bs-s)); font-weight:600; font-family:inherit; transition:background .2s, color .2s;
}
.es-close:hover{ background:rgba(var(--fg),.08); color:#FF2D2D; }
</style>
</head>
<body>

<div class="ac-lane">

    <!-- ══ TOPBAR ══ -->
    <div class="ac-topbar">
        <button type="button" class="ac-x" onclick="earthClose()" aria-label="Close">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
        <span class="ac-title">DezzMap</span>
    </div>

    <!-- ══ GLOBE ══ -->
    <div class="globe-stage" id="globe-stage">
        <canvas id="globe-canvas" aria-label="Interactive cartoon 3D globe. Drag to rotate, scroll or pinch to zoom."></canvas>

        <div class="map-stage" id="map-stage"><div id="map"></div></div>

        <div class="globe-loader" id="globe-loader">
            <div class="loader-ball"></div>
            <div class="loader-text">Loading Earth…</div>
        </div>

        <div class="globe-hint" id="globe-hint">
            <span><i class="ri-drag-move-2-line"></i> Drag to rotate · Pinch in to open the real map</span>
        </div>
        <div class="tour-chip" id="tour-chip"></div>
        <div class="earth-toast" id="earth-toast" role="status"></div>

        <div class="map-top">
            <button type="button" class="mt-circle" id="mt-back" aria-label="Back to the globe"><i class="ri-arrow-left-s-line"></i></button>
            <button type="button" class="mt-pill" id="mt-area">Search This Area</button>
            <button type="button" class="mt-circle" id="mt-locate" aria-label="Go to my location"><i class="ri-navigation-line"></i></button>
        </div>

        <div class="globe-ctl">
            <button type="button" class="ctl-btn" id="btn-zin" aria-label="Zoom in" title="Zoom in"><i class="ri-add-line"></i></button>
            <button type="button" class="ctl-btn" id="btn-zout" aria-label="Zoom out" title="Zoom out"><i class="ri-subtract-line"></i></button>
            <button type="button" class="ctl-btn map-only" id="btn-sat" aria-label="Satellite view" title="Satellite"><i class="ri-stack-line"></i></button>
            <button type="button" class="ctl-btn on" id="btn-live" aria-label="Show live activity" title="Live activity"><i class="ri-pulse-line"></i></button>
            <button type="button" class="ctl-btn on" id="btn-pods" aria-label="Show pods" title="Pods by country"><i class="ri-map-pin-2-line"></i></button>
            <button type="button" class="ctl-btn on" id="btn-spin" aria-label="Toggle auto-rotate and tour" title="Auto-rotate and idle tour"><i class="ri-refresh-line"></i></button>
            <button type="button" class="ctl-btn" id="btn-perf" aria-label="Performance mode" title="Performance mode (lighter on phones)"><i class="ri-speed-line"></i></button>
            <button type="button" class="ctl-btn" id="btn-reset" aria-label="Reset view" title="Reset view"><i class="ri-focus-3-line"></i></button>
        </div>

        <div class="globe-live quiet off" id="globe-live"><span class="dot"></span><span id="globe-live-t">Live</span></div>

        <div class="earth-sheet" id="earth-sheet" role="dialog" aria-label="Details">
            <div class="es-grab" id="es-grab"><span></span></div>
            <div class="es-card">
                <div class="es-scroll">
                    <div class="es-kind" id="es-kind">Country</div>
                    <div class="es-title" id="es-title"></div>
                    <div class="es-chips" id="es-chips"><button type="button" data-f="all" class="on">All</button><button type="button" data-f="live">Live</button><button type="button" data-f="pods">Pods</button></div>
                    <div id="es-body" data-f="all"></div>
                </div>
            </div>
            <button type="button" class="es-close" id="es-x">Close</button>
        </div>
    </div>

</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js"></script>
<script>
// ── theme sync across tabs ──
window.addEventListener('storage', function(e){
    if(e.key !== 'dz_theme') return;
    if(e.newValue === 'light') document.documentElement.setAttribute('data-theme','light');
    else document.documentElement.removeAttribute('data-theme');
    if(window.applyTheme) window.applyTheme();
});

// ── X: go back, or home if there is no history ──
function earthClose(){
    if (document.referrer && document.referrer.indexOf(window.location.host) !== -1) history.back();
    else location.href = 'index.php';
}

(function(){
    var stage  = document.getElementById('globe-stage');
    var canvas = document.getElementById('globe-canvas');
    var loader = document.getElementById('globe-loader');
    var hint   = document.getElementById('globe-hint');
    var btnSpin  = document.getElementById('btn-spin');
    var btnReset = document.getElementById('btn-reset');

    if(typeof THREE === 'undefined'){
        loader.querySelector('.loader-text').textContent = 'Could not load the 3D engine. Check your connection and reload.';
        return;
    }

    // ── performance mode: auto on weak / low-power devices, or forced with the speed button (remembered) ──
    var PERF = (function(){
        var v = null; try{ v = localStorage.getItem('dz_earth_perf'); }catch(e){}
        if(v === '1') return true; if(v === '0') return false;
        var n = navigator, c = n.connection || {};
        return !!((n.hardwareConcurrency && n.hardwareConcurrency <= 4) || (n.deviceMemory && n.deviceMemory <= 2) || c.saveData ||
                  (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches));
    })();

    // ── scene ──
    var scene    = new THREE.Scene();
    var camera   = new THREE.PerspectiveCamera(45, 1, 0.1, 100);
    var START_Z  = 3.6;
    camera.position.set(0, 0.3, START_Z);

    // colours are written to the screen exactly as painted (no tone / encoding changes)
    var renderer = new THREE.WebGLRenderer({ canvas:canvas, antialias:!PERF, alpha:true });
    renderer.setPixelRatio(PERF ? 1 : Math.min(window.devicePixelRatio || 1, 2));

    // ═══════════════════════════════════════════════
    //  FLAT CARTOON MAP — pill-shaped land + clouds,
    //  painted once for the day side and once for the night side
    // ═══════════════════════════════════════════════
    var TW = PERF ? 1024 : 2048, TH = TW / 2, PX = TW / 360;     // px per degree (same both ways)

    // one rounded pill, sized in degrees on the sphere (stretched toward the poles so it still looks round)
    function pill(ctx, lat, lon, wDeg, hDeg, color){
        var cx = (lon + 180) * PX, cy = (90 - lat) * PX;
        var w = wDeg * PX, h = hDeg * PX, r = Math.min(w, h) / 2;
        var sx = 1 / Math.max(Math.cos(lat * Math.PI / 180), 0.3);
        ctx.save();
        ctx.translate(cx, cy);
        ctx.scale(sx, 1);
        ctx.fillStyle = color;
        ctx.beginPath();
        ctx.arc( w / 2 - r, 0, r, -Math.PI / 2,  Math.PI / 2);
        ctx.arc(-w / 2 + r, 0, r,  Math.PI / 2,  Math.PI * 1.5);
        ctx.closePath();
        ctx.fill();
        ctx.restore();
    }

    // land: simplified real outlines [lon, lat, lon, lat, …] — softened with a rounded stroke so they read as flat cartoon shapes
    var LAND = [
        // North America
        [-168,66,-162,70,-156,71.3,-141,69.6,-128,70,-115,68.5,-95,71,-85,69.5,-82,67,-90,64,-93,61,-94,58.5,-88,56.5,-82,55,-79,52,-79,57,-77,62,-70,61,-64,60,-61,56,-57,53,-60,50,-66,50,-64,47,-61,46,-66,44,-70,43,-70,41.5,-74,40.5,-76,37,-76,35,-81,31.5,-80,27,-80.5,25.2,-82,26.5,-83,29.5,-86,30.3,-89,30.2,-90,29,-94,29.5,-97,27.5,-97.5,24,-97.5,21,-95,18.5,-91,18.8,-90.5,21,-87,21.5,-87.5,18,-88.5,16,-84,15.8,-83.5,11,-81.5,9,-79,9.3,-77.5,8.5,-80,7.2,-83,8.2,-85.5,10,-87.5,13,-92,14.5,-95,16,-100,17,-105.5,20,-105.5,22.5,-109,25.5,-112.5,29,-114.5,31.7,-113,29.5,-111,26,-109.5,23.2,-110.5,23.5,-112.5,25,-114.5,27.5,-115.5,29.5,-117,32.5,-120.5,34.5,-122,37,-124,40,-124,46,-124.5,48.5,-128,51,-131,54.5,-135,58,-140,60,-146,60.8,-152,59.5,-158,57,-164,54.7,-160,58.5,-162,60.5,-165,62.5,-166,65],
        // Greenland, Baffin, Victoria, Newfoundland, Cuba, Hispaniola
        [-73,78,-60,82,-35,83.5,-20,81.5,-18,76,-22,70,-32,68,-41,64,-44,60,-50,62,-53,67,-55,71,-62,76],
        [-80,73,-70,72.5,-62,67,-65,63,-72,64,-78,65,-90,70],
        [-118,73,-105,73,-101,69,-112,68.5,-118,70],
        [-59,48,-53,47,-54,51],
        [-85,22,-80,23,-74,20.2,-77.5,19.8,-82,22.2],
        [-74,19.8,-69,19.5,-68.5,18.3,-72,18],
        // South America
        [-77.5,8.5,-76,9,-72,12,-69,11,-64,10.5,-61,10,-58,6.5,-52,5,-50,1.5,-48,-1,-44,-2.5,-39,-3.5,-35,-5.5,-35,-9,-37.5,-12.5,-39,-17.5,-40.5,-22,-43,-23,-48,-25.5,-48.5,-28,-52,-32,-54,-34.8,-57,-35,-58,-38,-62,-39,-65,-41,-64,-42.5,-67,-46,-66,-48,-69,-51,-68.5,-53,-71,-54.5,-74,-52,-75.5,-48,-74,-43,-73.5,-37,-71.5,-32,-71.5,-27,-70.5,-18,-76,-14,-79,-8,-81,-5,-80,-1.5,-80,1.5,-78.5,2.5,-77.5,6.5],
        // Africa + Madagascar
        [-17,21,-16,16,-17.5,14.7,-16.5,12.5,-13,9,-10,6,-7.5,4.4,-4,5.2,1,6,4.5,6.3,6,4.3,9,4,9.5,1,9.5,-2,12,-5,13.5,-11,12,-17,14.5,-22.5,15.5,-27.5,18,-32,19,-34.5,23,-34,27,-33.5,31,-30,32.5,-26,35,-22,35.5,-18,40.5,-15,40.5,-11,39,-6,41,-2,44,1.5,48,5,51,11.8,47,11,43.5,12,41,15,37.5,18,35,24,33,28,32.5,30,30,31,25,32,20,30.5,19,30,15,32.5,10.5,34,11,37,8,37,3,36.8,-2,35.5,-6,35.8,-9.5,32,-10,29,-13,27.5,-16,24],
        [49.5,-12,50.5,-15.5,47,-25,44,-24.5,43.5,-21,44.5,-16.5],
        // Europe + Asia
        [-9.3,43,-8.8,41,-9.5,38.7,-8.8,37,-6,36.2,-2,36.8,0,38.5,3,42,3.5,43.3,7.5,43.7,10,44,12.5,41.8,16,38,18.5,40.2,14,42.5,12.4,44.5,13.7,45.6,15.5,44,19,41.5,20,39.5,23,36.5,24,38.5,23,40.5,26,40.8,26.5,38.5,28,36.8,30,36.2,36,36.5,35.5,33.5,34.8,31.5,34.3,28,35.5,28,38,24,39.5,21,42,16,43.5,12.7,45,12.8,50,14.5,52,16,55,17.5,58.5,20.5,59.5,22.5,56.5,24.5,55,25.5,54,24.2,51.5,24.5,51,26,50,26.5,48,29.5,50,30,52,27.8,56,27,57.5,25.8,61.5,25.2,66.5,25.3,68.5,23.5,70,21,72.8,20.8,73,16,74.5,12.5,76,9,77.5,8,80,10,80,15.5,82,17,84.5,19.5,87,21.5,89,21.8,91.5,22.5,92,21,94,16.5,97.5,16.5,98,13,98.5,9,100,6.5,101,3.5,103.5,1.3,104.2,2.5,103,5.5,101.5,6.5,100.5,8,99.8,10.5,100,13.5,102,12.5,103,10.5,105,8.7,106.5,9.5,107,10.5,109,12,109.3,14,108,16,106.5,18.5,106,20,108,21.5,110,20.5,111,21.5,113,22,114.5,22.5,117,23.5,119,25.5,120.5,27.5,122,30,121.5,31.5,120.5,33,119,35,120.5,36.5,122.5,37.2,119.5,37.5,118,38.5,117.8,39,121,40.5,122,40,124.5,39.8,125.5,38,126.5,37,126.5,34.5,128,34.8,129.4,35.3,129.2,37,128,39.5,129.7,41,131,42.5,133,43,135,43.8,138,46,140.5,50,140.5,53,137,54,135,54.6,138,56,142,59,150,59.3,155,59.5,158,61.5,163,62.5,170,60.5,178,62.5,185,65,185,68.5,176,69.5,170,70,160,69.5,150,71,142,72.5,131,71,127,73.5,113,73.5,108,76.5,104,77.7,99,76.5,95,76,88,75.5,81,73.5,80,72,73,72.5,70,69,67,69,66,67.5,60,69.5,56,68,53,68.5,44,68.5,40,67,33,66.5,30,67,30,63,30.5,61,30,60,28,59.5,24,59.2,23.5,57.5,21,56.5,21,55,19.5,54.4,14.2,54,11,54.2,10.5,54.2,9.9,54.8,9.8,57,8.5,57.1,8.2,55.5,8.7,54,7,53.5,4.7,52.8,3.5,51.5,1.7,51,1.5,50.1,-1.5,49.7,-1.8,48.7,-4.7,48.5,-2.5,47.3,-1.2,46,-1.5,44,-1.8,43.4,-4,43.4,-8,43.7],
        // Scandinavia, UK, Ireland, Iceland
        [5,59,5,62,10,64,14,68,18,70,25,71,30,70,30,67,30,62,28,60.5,24,60,22,60.5,21.5,63,25,65,22,65.5,17.5,62.5,18.5,60,16.5,57,14.5,55.5,12.8,56,11,59,8,58],
        [-5.5,50,1.5,51,1.7,52.8,-0.5,54,-2,56,-2,57.6,-4,57.7,-6,58.5,-5,55.5,-3,54.5,-3,53.4,-4.5,52.5,-5.2,51.8,-3.5,51.3],
        [-10,52,-6,52,-6,54.2,-8,55.3,-10,54],
        [-24,65.5,-22,66.4,-15,66.5,-13.5,65,-18,63.4,-22.5,63.8],
        // Japan, Taiwan, Philippines, Sri Lanka
        [130.5,31.5,132,33,135,33.5,136.5,34,138.5,34.7,140,35.2,141,38,141.8,40,141.5,41.5,140,40,139.8,38,137,37,136,35.8,133,35.5,131,34.5,130.5,33.8],
        [140,42,141,45.5,145.3,44,143,42],
        [121,25,122,25,120.8,22,120,23],
        [120,18.5,122,18.4,122,14,124,13,121,13.5,120,16],
        [122,7,126,9,126,6.5,125,5.8,123,7.5],
        [80,9.5,81.8,7.5,80.5,6,79.8,8],
        // Indonesia + New Guinea
        [95.5,5.5,98,4,104,-1,106,-3,105.8,-5.8,102,-4,98.5,0],
        [109,1.5,111.5,2,115,5,117,7,119,5,118,1,116.5,-3.5,114,-4,110.5,-3,109,-1],
        [105.2,-6.8,108,-6.3,114.5,-7.5,114.5,-8.7,108,-8,105.5,-7],
        [119,-3,119.5,0.5,124.5,1,121.5,-1,123,-4,122,-5,120.5,-5.5],
        [131,-1,135,-3.3,138,-1.8,141,-2.6,145,-4.8,148,-6,147.5,-8,150.5,-10.5,147,-10,143.5,-8,141,-9.2,138,-8,137,-5,133,-4],
        // Australia, Tasmania, New Zealand
        [114,-22,114,-26,115,-34,118,-35,123,-34,126,-32.5,131,-31.5,134,-32.8,135.5,-34.8,138,-35.5,139,-37.5,141,-38,145,-38.5,147,-38,150,-37,151,-33.5,153,-30,153.5,-27,152.5,-24,150,-22,148.5,-20,146,-18.7,145.3,-15,143.5,-14,142.5,-10.8,141.5,-13.5,141.5,-17,139.5,-17.5,137,-15.5,135.5,-14.8,136.5,-12.2,132,-11.5,130,-13,129.5,-15,127,-14,125,-14.5,122.5,-17,121,-19.5,119,-20,116,-20.7],
        [145,-41,148,-41,147.5,-43.2,145.5,-43],
        [172.7,-34.5,175,-36.5,178,-37.7,175.5,-41.5,174.5,-39.5,173.8,-36.5],
        [172.5,-40.5,174,-41.5,171,-44.5,169,-46.6,166.5,-46,168,-44,171.5,-41.5],
    ];
    // Antarctica (ice, so it gets its own colour)
    var ICE = [
        [-190,-95,-190,-84,-160,-78,-145,-76,-120,-74,-100,-73,-80,-73.5,-70,-71,-65,-67,-62,-64.5,-58,-63.5,-60,-67,-62,-72,-58,-75,-45,-78,-30,-78,-15,-72,0,-70,20,-70,40,-68,60,-67,75,-69,90,-66,110,-66,130,-66,150,-69,165,-71,175,-77,190,-78,190,-95]
    ];
    // inland seas, painted back over the land in ocean colour
    var INLAND = [
        [28,42,28,44,30,46,33,46,35,45,38,47,41.5,42,36,41.7,31,41],           // Black Sea
        [47,45,51,47,53,45,51,41,54,37,50,37,49,40,47,43]                      // Caspian Sea
    ];
    function poly(ctx, flat, color){
        var P = [], n = flat.length / 2, i;
        for(i = 0; i < n; i++) P.push([(flat[i*2] + 180) * PX, (90 - flat[i*2+1]) * PX]);
        function mid(a, b){ return [(a[0] + b[0]) / 2, (a[1] + b[1]) / 2]; }
        ctx.beginPath();
        var s0 = mid(P[n-1], P[0]);
        ctx.moveTo(s0[0], s0[1]);
        for(i = 0; i < n; i++){                       // every corner becomes a smooth curve
            var m = mid(P[i], P[(i+1) % n]);
            ctx.quadraticCurveTo(P[i][0], P[i][1], m[0], m[1]);
        }
        ctx.closePath();
        ctx.fillStyle = color; ctx.strokeStyle = color;
        ctx.lineJoin = 'round'; ctx.lineCap = 'round'; ctx.lineWidth = 7 * TW / 2048;
        ctx.fill(); ctx.stroke();
    }
    // clouds: little stacks of pills  [lat, lon, scale] — mostly over the oceans so the continents stay readable
    var CLOUDS = [
        [32,-42,1], [-22,-18,.9], [12,-150,1], [38,-150,.8], [-28,-118,.9], [-14,82,.9], [-38,62,.8],
        [30,162,.9], [-52,-100,.8], [0,-28,.6], [22,-125,.7], [-45,150,.7],
        [50,-35,.8], [18,-60,.7], [-8,-95,.8], [-40,-30,.8], [-55,20,.9], [-48,80,.8], [-5,115,.6],
        [8,60,.8], [-18,170,.7], [48,-165,.8], [-35,-150,.7], [62,-20,.7], [24,-170,.6], [-60,-60,.7],
        [10,-120,.6], [40,-60,.7], [0,160,.7], [-26,40,.6], [56,160,.7], [-64,130,.7],
        [5,20,.6], [-12,-52,.6], [45,100,.6], [40,-100,.6], [-24,-80,.6], [34,-20,.6]
    ];

    function paintWorld(pal){
        var c = document.createElement('canvas'); c.width = TW; c.height = TH;
        var ctx = c.getContext('2d');
        ctx.fillStyle = pal.ocean; ctx.fillRect(0, 0, TW, TH);
        LAND.forEach(function(p){ poly(ctx, p, pal.land); });
        ICE.forEach(function(p){ poly(ctx, p, pal.ice); });
        INLAND.forEach(function(p){ poly(ctx, p, pal.ocean); });
        CLOUDS.forEach(function(k){
            var s = k[2];
            pill(ctx, k[0],           k[1],          26 * s, 7 * s, pal.cloud);
            pill(ctx, k[0] - 7 * s,   k[1] + 5 * s,  20 * s, 7 * s, pal.cloud);
            pill(ctx, k[0] - 14 * s,  k[1] - 2 * s,  14 * s, 6 * s, pal.cloud);
        });
        var t = new THREE.CanvasTexture(c);
        t.anisotropy = renderer.capabilities.getMaxAnisotropy();
        return t;
    }
    var dayTex   = paintWorld({ ocean:'#2b8fe0', land:'#4ccf5a', ice:'#ffffff', cloud:'#ffffff' });
    var nightTex = paintWorld({ ocean:'#1b6bd6', land:'#2f9a8a', ice:'#c9cff7', cloud:'#aab0ef' });

    // ── globe: day on the left of the screen, night on the right, one crisp line between ──
    // the light is fixed to the camera, so the split always stays down the middle while the world turns under it
    var earthMat = new THREE.ShaderMaterial({
        uniforms:{ dayTex:{ value:dayTex }, nightTex:{ value:nightTex } },
        vertexShader:
            'varying vec2 vUv; varying vec3 vN;' +
            'void main(){ vUv = uv; vN = normalize(normalMatrix * normal);' +
            'gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0); }',
        fragmentShader:
            'uniform sampler2D dayTex; uniform sampler2D nightTex;' +
            'varying vec2 vUv; varying vec3 vN;' +
            'void main(){' +
            '  vec4 d = texture2D(dayTex, vUv); vec4 n = texture2D(nightTex, vUv);' +
            '  float k = smoothstep(-0.006, 0.006, vN.x);' +
            '  gl_FragColor = vec4(mix(d.rgb, n.rgb, k), 1.0); }'
    });
    var RADIUS = 1;
    var earth = new THREE.Mesh(new THREE.SphereGeometry(RADIUS, PERF ? 48 : 96, PERF ? 48 : 96), earthMat);
    scene.add(earth);

    // ── thick navy ring around the globe ──
    var ringNavy = new THREE.Mesh(new THREE.SphereGeometry(RADIUS * 1.18, PERF ? 32 : 64, PERF ? 32 : 64),
        new THREE.MeshBasicMaterial({ color:0x0a1038, side:THREE.BackSide }));
    scene.add(ringNavy);

    // ── stars (dark theme only) ──
    var starGeo = new THREE.BufferGeometry();
    var starPos = new Float32Array((PERF ? 400 : 1600) * 3);
    for(var i = 0; i < (PERF ? 400 : 1600); i++){
        var r = 25 + Math.random() * 15, th = Math.random() * Math.PI * 2, ph = Math.acos(2 * Math.random() - 1);
        starPos[i*3]   = r * Math.sin(ph) * Math.cos(th);
        starPos[i*3+1] = r * Math.sin(ph) * Math.sin(th);
        starPos[i*3+2] = r * Math.cos(ph);
    }
    starGeo.setAttribute('position', new THREE.BufferAttribute(starPos, 3));
    var starMat = new THREE.PointsMaterial({ color:0xffffff, size:0.12, sizeAttenuation:true, transparent:true, opacity:0.85, depthWrite:false });
    var stars   = new THREE.Points(starGeo, starMat);
    scene.add(stars);

    // ── optional pins: lat/lon → point on the globe ──
    // Fill this list (e.g. from PHP with json_encode) to drop red pins on the globe.
    var PINS = [ /* { lat:6.5244, lon:3.3792 } */ ];
    function latLonToVec(lat, lon, rad){
        var phi = (90 - lat) * Math.PI / 180, theta = (lon + 180) * Math.PI / 180;
        return new THREE.Vector3(-rad * Math.sin(phi) * Math.cos(theta), rad * Math.cos(phi), rad * Math.sin(phi) * Math.sin(theta));
    }
    PINS.forEach(function(p){
        var m = new THREE.Mesh(new THREE.SphereGeometry(0.025, 16, 16), new THREE.MeshBasicMaterial({ color:0xFF2D2D }));
        m.position.copy(latLonToVec(p.lat, p.lon, RADIUS * 1.005));
        earth.add(m);
    });

    // ── controls: drag / touch to rotate, wheel / pinch to zoom ──
    var controls = new THREE.OrbitControls(camera, canvas);
    controls.enablePan       = false;
    controls.enableDamping   = true;
    controls.dampingFactor   = 0.07;
    controls.rotateSpeed     = 0.55;
    controls.zoomSpeed       = 0.7;
    controls.minDistance     = 1.4;          // zooming in past ENTER_D hands over to the real map
    controls.maxDistance     = 7;
    controls.autoRotate      = true;
    controls.autoRotateSpeed = 0.55;

    // The handoff is matched to the size of the globe on screen: at ENTER_D the 3D globe and the map-globe (zoom ENTER_Z) are the same size,
    // so zooming in keeps showing a round Earth — now with real map detail — and only flattens out near street level.
    var ENTER_D = 2.2,  EXIT_D = 3.1;        // globe camera distance: enter the map / return to the globe
    var ENTER_Z = 2.3,  EXIT_Z = 1.7;        // map zoom: start level / below this we go back to the globe
    var mode = 'globe', switching = false, globeOn = true, flyTok = 0;
    var spinWanted = true, resumeTimer = null;

    // ── element refs ──
    var liveChip = document.getElementById('globe-live'), liveTxt = document.getElementById('globe-live-t');
    var sheet = document.getElementById('earth-sheet'), esKind = document.getElementById('es-kind'), esTitle = document.getElementById('es-title'), esBody = document.getElementById('es-body');
    var mapStage = document.getElementById('map-stage'), tourChip = document.getElementById('tour-chip'), toastEl = document.getElementById('earth-toast');
    var btnLive = document.getElementById('btn-live'), btnPods = document.getElementById('btn-pods'), btnPerf = document.getElementById('btn-perf');
    var REDUCED = !!(window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches);

    function isLight(){ return document.documentElement.getAttribute('data-theme') === 'light'; }
    function esc(t){ return String(t == null ? '' : t).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
    var toastT = null;
    function toast(msg){ toastEl.textContent = msg; toastEl.classList.add('on'); clearTimeout(toastT); toastT = setTimeout(function(){ toastEl.classList.remove('on'); }, 3200); }

    // camera → the lat / lon it is looking straight down on (inverse of latLonToVec)
    function camLatLon(){
        var v = camera.position.clone().normalize();
        var lat = Math.asin(Math.max(-1, Math.min(1, v.y))) * 180 / Math.PI;
        var lon = Math.atan2(v.z, -v.x) * 180 / Math.PI - 180;
        while(lon < -180) lon += 360; while(lon > 180) lon -= 360;
        return { lat:lat, lon:lon };
    }
    // smooth fly of the globe camera (great-circle slerp, cancelled by anything that bumps flyTok)
    function flyGlobe(lat, lon, dist, dur, done){
        var tok = ++flyTok, a = camera.position.clone(), d0 = a.length(); a.normalize();
        var b = latLonToVec(Math.max(-80, Math.min(80, lat)), lon, 1).normalize();
        var qa = new THREE.Quaternion().setFromUnitVectors(a, b), id = new THREE.Quaternion(), t0 = performance.now();
        (function step(now){
            if(tok !== flyTok) return;
            var k = Math.min((now - t0) / dur, 1), e = 1 - Math.pow(1 - k, 3);
            camera.position.copy(a).applyQuaternion(id.clone().slerp(qa, e)).multiplyScalar(d0 + (dist - d0) * e);
            if(k < 1) requestAnimationFrame(step); else if(done) done();
        })(t0);
    }

    controls.addEventListener('start', function(){
        flyTok++; tourStop();
        controls.autoRotate = false;
        clearTimeout(resumeTimer);
        canvas.classList.add('dragging');
        hint.classList.add('hide');
    });
    controls.addEventListener('end', function(){
        canvas.classList.remove('dragging');
        if(spinWanted) resumeTimer = setTimeout(function(){ if(mode === 'globe') controls.autoRotate = true; }, 2500);
        tourArm();
    });
    // zooming in far enough → real map
    controls.addEventListener('change', function(){
        if(mode === 'globe' && !switching && camera.position.length() <= ENTER_D) enterMap(camLatLon());
    });

    btnSpin.addEventListener('click', function(){
        spinWanted = !spinWanted;
        controls.autoRotate = spinWanted && mode === 'globe';
        clearTimeout(resumeTimer);
        btnSpin.classList.toggle('on', spinWanted);
        if(spinWanted) tourArm(); else tourStop();
    });
    btnReset.addEventListener('click', function(){
        tourStop();
        if(mode === 'map'){ if(mapPrimary){ if(sheet.classList.contains('open')) closeSheet(); map.flyTo({ center:[HOME.lon, HOME.lat], zoom:mapHomeZoom(), duration:900 }); lastTouch = performance.now(); } else exitMap(); return; }
        flyGlobe(camLatLon().lat, camLatLon().lon, START_Z, 700);
        tourArm();
    });
    document.getElementById('btn-zin').addEventListener('click', function(){
        tourStop();
        if(mode === 'map'){ map.zoomIn(); return; }
        var c = camLatLon(); flyGlobe(c.lat, c.lon, Math.max(controls.minDistance, camera.position.length() * 0.72), 450);
    });
    document.getElementById('btn-zout').addEventListener('click', function(){
        tourStop();
        if(mode === 'map'){ map.zoomOut(); return; }
        var c = camLatLon(); flyGlobe(c.lat, c.lon, Math.min(controls.maxDistance, camera.position.length() * 1.38), 450);
    });
    // performance mode: remembered, applied on reload (the globe is built once, so it needs a fresh start)
    btnPerf.classList.toggle('on', PERF);
    btnPerf.addEventListener('click', function(){
        try{ localStorage.setItem('dz_earth_perf', PERF ? '0' : '1'); }catch(e){}
        location.reload();
    });

    // ═══════════════════════════════════════════════
    //  LIVE ACTIVITY (soft pulses) + PODS BY COUNTRY (pins)
    //  Country level only — data comes from earth.php?data=1
    // ═══════════════════════════════════════════════
    var layerLive = new THREE.Group(), layerPods = new THREE.Group();
    earth.add(layerLive); earth.add(layerPods);
    // arriving from a profile / pod location (earth.php?loc=…) shows only that place — pulses and pod pins appear when you open DezzMap itself
    var FROM_LOC = false; try{ FROM_LOC = !!(new URLSearchParams(location.search).get('loc') || '').trim(); }catch(e){}
    var showLive = !FROM_LOC, showPodPins = !FROM_LOC;
    btnLive.classList.toggle('on', showLive); btnPods.classList.toggle('on', showPodPins);
    var pulses = [], lastJson = '', DATA = { activity:[], pods:[], window:30 };
    var Z = new THREE.Vector3(0, 0, 1);
    var RINGS = PERF ? 1 : 3;

    function surfaceQuat(v){ return new THREE.Quaternion().setFromUnitVectors(Z, v.clone().normalize()); }
    function clearGroup(g){
        for(var i = g.children.length - 1; i >= 0; i--){
            var o = g.children[i]; g.remove(o);
            if(o.geometry && o.geometry !== ringGeo && o.geometry !== dotGeo) o.geometry.dispose();
            if(o.material){ if(o.material.map) o.material.map.dispose(); o.material.dispose(); }
        }
    }

    var ringGeo = new THREE.RingGeometry(0.92, 1, PERF ? 32 : 56);
    var dotGeo  = new THREE.CircleGeometry(1, PERF ? 14 : 20);
    function buildLive(list){
        clearGroup(layerLive); pulses = [];
        list.forEach(function(a){
            var n = latLonToVec(a.lat, a.lon, 1), q = surfaceQuat(n);
            var size = 0.06 + 0.02 * Math.min(Math.log(a.posts + 1) / Math.LN2, 5);     // bigger pulse = more posts (soft, capped)
            var dot = new THREE.Mesh(dotGeo, new THREE.MeshBasicMaterial({ color:0xFF2D2D, transparent:true, opacity:.95, depthWrite:false }));
            dot.scale.setScalar(0.014); dot.position.copy(n.clone().multiplyScalar(1.006)); dot.quaternion.copy(q);
            dot.userData = { cc:a.c }; layerLive.add(dot);
            for(var i = 0; i < RINGS; i++){
                var m = new THREE.Mesh(ringGeo, new THREE.MeshBasicMaterial({ color:0xFF2D2D, transparent:true, opacity:0, depthWrite:false, side:THREE.DoubleSide }));
                m.position.copy(n.clone().multiplyScalar(1.005)); m.quaternion.copy(q); layerLive.add(m);
                pulses.push({ m:m, size:size, phase:i / RINGS, speed:0.00022 + Math.random() * 0.00004 });
            }
        });
    }
    function tickPulses(now){
        if(!showLive) return;
        for(var i = 0; i < pulses.length; i++){
            var p = pulses[i], t = (now * p.speed + p.phase) % 1;
            p.m.scale.setScalar(Math.max(0.0001, p.size * (0.12 + t)));
            p.m.material.opacity = (1 - t) * (1 - t) * 0.75;
        }
    }

    function pinTexture(count){
        var c = document.createElement('canvas'); c.width = c.height = 128;
        var x = c.getContext('2d'), label = count > 99 ? '99+' : String(count);
        x.fillStyle = '#ffffff'; x.beginPath(); x.arc(64, 64, 60, 0, 7); x.fill();
        x.fillStyle = '#FF2D2D'; x.beginPath(); x.arc(64, 64, 50, 0, 7); x.fill();
        x.fillStyle = '#ffffff'; x.font = '700 ' + (label.length > 2 ? 44 : 56) + 'px Poppins, sans-serif';
        x.textAlign = 'center'; x.textBaseline = 'middle'; x.fillText(label, 64, 68);
        var t = new THREE.CanvasTexture(c); t.anisotropy = 4; return t;
    }
    function buildPods(list){
        clearGroup(layerPods);
        list.forEach(function(p){
            var sp = new THREE.Sprite(new THREE.SpriteMaterial({ map:pinTexture(p.count), transparent:true, depthWrite:false }));
            sp.scale.setScalar(0.12);
            sp.position.copy(latLonToVec(p.lat, p.lon, 1.045));
            sp.userData = { cc:p.c };
            layerPods.add(sp);
        });
    }

    function renderLiveChip(){
        var posts = 0, ctr = DATA.activity.length;
        DATA.activity.forEach(function(a){ posts += a.posts; });
        liveChip.classList.toggle('off', !showLive);
        liveChip.classList.toggle('quiet', !ctr);
        liveTxt.textContent = ctr
            ? 'Live · ' + posts + ' new post' + (posts === 1 ? '' : 's') + ' from ' + ctr + ' countr' + (ctr === 1 ? 'y' : 'ies') + ' · last ' + DATA.window + ' min'
            : 'Quiet right now · pulses show where people are posting';
    }

    function applyData(d){
        DATA = d;
        buildLive(d.activity || []); buildPods(d.pods || []);
        layerLive.visible = showLive; layerPods.visible = showPodPins;
        renderLiveChip();
        if(map) buildMapMarkers();
        if(sheet.classList.contains('open') && sheet.dataset.cc) openCountry(sheet.dataset.cc, true);
    }
    function poll(){
        if(document.hidden) return;
        fetch('earth.php?data=1', { credentials:'same-origin', cache:'no-store' })
            .then(function(r){ return r.ok ? r.text() : Promise.reject(); })
            .then(function(t){ if(t !== lastJson){ lastJson = t; applyData(JSON.parse(t)); } })
            .catch(function(){});
    }
    poll(); setInterval(poll, 25000);
    document.addEventListener('visibilitychange', function(){ if(!document.hidden) poll(); });

    // layer toggles (apply to the globe AND the map pins)
    function applyLayerVis(){
        layerLive.visible = showLive; layerPods.visible = showPodPins;
        mkLive.forEach(function(m){ m.getElement().style.display = showLive ? '' : 'none'; });
        mkPods.forEach(function(m){ m.getElement().style.display = showPodPins ? '' : 'none'; });
    }
    btnLive.addEventListener('click', function(){ showLive = !showLive; btnLive.classList.toggle('on', showLive); applyLayerVis(); renderLiveChip(); });
    btnPods.addEventListener('click', function(){ showPodPins = !showPodPins; btnPods.classList.toggle('on', showPodPins); applyLayerVis(); if(!showPodPins) closeSheet(); });

    // ═══════════ country sheet (works on the globe and on the map) ═══════════
    function findBy(list, cc){ for(var i = 0; i < list.length; i++) if(list[i].c === cc) return list[i]; return null; }
    function countryHtml(cc){
        var a = findBy(DATA.activity, cc), p = findBy(DATA.pods, cc);
        if(!a && !p) return null;
        var name = (a || p).n, h = '';
        if(a) h += '<div class="es-live"><span class="dot"></span>' + a.posts + ' new post' + (a.posts === 1 ? '' : 's') + ' from ' + a.people + ' people in the last ' + DATA.window + ' min</div>';
        h += '<div class="es-pods">';
        if(p){
            h += '<div class="es-sec">' + p.count + ' pod' + (p.count === 1 ? '' : 's') + ' from ' + esc(name) + '</div>';
            p.top.forEach(function(g){
                h += '<a class="es-pod" href="group.php?id=' + g.id + '"><span class="ring"><img src="' + esc(g.p) + '" alt="" loading="lazy" onerror="this.onerror=null;this.src=\'uploads/default_group.png\'"></span>' +
                     '<div style="min-width:0"><div class="t">' + esc(g.t) + '</div><div class="m">' + g.m + ' member' + (g.m === 1 ? '' : 's') + '</div></div><i class="ri-arrow-right-s-line go"></i></a>';
            });
            if(p.count > p.top.length) h += '<a class="es-more" href="community.php">See all pods</a>';
        } else {
            h += '<div class="es-empty">No pods from ' + esc(name) + ' yet.</div><a class="es-more" href="community.php">Start one in Pods</a>';
        }
        h += '</div>';
        return { name:name, html:h, lat:(a || p).lat, lon:(a || p).lon };
    }
    var ATTR = '<div class="es-attr">Map data © OpenStreetMap contributors · CARTO · Esri</div>';
    function showSheet(title, html, cc, kind){
        if(!sheet.classList.contains('open')) sheetReset();
        sheet.classList.remove('place-mode'); esKind.textContent = kind || 'Country'; esTitle.textContent = title; esBody.innerHTML = html + (mode === 'map' ? ATTR : '');
        sheet.dataset.cc = cc || ''; sheet.classList.add('open'); hint.classList.add('hide');
        controls.autoRotate = false; clearTimeout(resumeTimer); tourStop();
    }
    function openCountry(cc, keepView){
        var c = countryHtml(cc); if(!c) return;
        showSheet(c.name, c.html, cc);
        if(keepView) return;
        if(mode === 'map'){
            map.easeTo({ center:[c.lon, c.lat], zoom:Math.max(map.getZoom(), 4.2), padding:{ bottom:Math.round(stage.clientHeight * 0.42) }, duration:700 });
        } else {
            flyGlobe(c.lat - 14, c.lon, camera.position.length(), 650);     // aim a little south of the country so it sits above the sheet
        }
    }
    function closeSheet(){
        sheet.classList.remove('open', 'tall'); sheet.dataset.cc = '';
        setTimeout(function(){ if(!sheet.classList.contains('open')) sheetReset(); }, 340);
        if(mode === 'map' && map) map.easeTo({ padding:{ bottom:0 }, duration:300 });
        if(spinWanted && mode === 'globe'){ clearTimeout(resumeTimer); resumeTimer = setTimeout(function(){ controls.autoRotate = true; }, 1500); }
        tourArm();
    }
    document.getElementById('es-x').addEventListener('click', closeSheet);

    // filter chips
    var chips = document.getElementById('es-chips');
    chips.addEventListener('click', function(e){
        var b = e.target.closest('button'); if(!b) return;
        esBody.dataset.f = b.dataset.f;
        [].forEach.call(chips.children, function(x){ x.classList.toggle('on', x === b); });
    });
    // drag the sheet up / down (handle, label or name): it follows your finger and snaps to small · half · tall; pull it right down (or flick) to close; a tap on the handle toggles half / tall
    function sheetReset(){ sheet.style.height = ''; sheet.style.maxHeight = ''; sheet.classList.remove('sized', 'tall', 'drag'); }
    function sheetSnaps(){ var H = stage.clientHeight; return [Math.max(130, Math.round(H * 0.2)), Math.round(H * 0.5), Math.round(H * 0.92)]; }
    function applySnap(h){
        sheet.classList.add('sized'); sheet.style.maxHeight = 'none'; sheet.style.height = h + 'px';
        if(mode === 'map' && map) map.easeTo({ padding:{ bottom:Math.min(h, Math.round(stage.clientHeight * 0.6)) }, duration:300 });
    }
    (function(){
        var y0 = null, h0 = 0, dy = 0, moved = false, lastY = 0, lastT = 0, vel = 0;
        function down(e){
            y0 = e.clientY; h0 = sheet.offsetHeight; dy = 0; moved = false; lastY = y0; lastT = performance.now(); vel = 0;
            sheet.classList.add('drag', 'sized'); sheet.style.maxHeight = 'none'; sheet.style.height = h0 + 'px';
            try{ e.currentTarget.setPointerCapture(e.pointerId); }catch(_){}
        }
        function move(e){
            if(y0 === null) return;
            dy = e.clientY - y0; if(Math.abs(dy) > 5) moved = true;
            sheet.style.height = Math.max(40, Math.min(sheetSnaps()[2], h0 - dy)) + 'px';
            var now = performance.now(); vel = (e.clientY - lastY) / Math.max(1, now - lastT); lastY = e.clientY; lastT = now;
        }
        function up(){
            if(y0 === null) return;
            y0 = null; sheet.classList.remove('drag');
            var h = sheet.offsetHeight, S = sheetSnaps();
            if(!moved){ applySnap(h > S[1] + 20 ? S[1] : S[2]); return; }          // tap
            var proj = h - vel * 180;                                                  // a flick carries it a bit further
            if(proj < S[0] * 0.62){ closeSheet(); return; }
            var best = S[0]; S.forEach(function(v){ if(Math.abs(v - proj) < Math.abs(best - proj)) best = v; });
            applySnap(best);
        }
        [document.getElementById('es-grab'), esKind, esTitle].forEach(function(z){
            z.addEventListener('pointerdown', down); z.addEventListener('pointermove', move);
            z.addEventListener('pointerup', up); z.addEventListener('pointercancel', up);
        });
    })();

    // tap (not drag) a pin / dot on the globe to open its country
    var ray = new THREE.Raycaster(), ndc = new THREE.Vector2(), dn = null;
    canvas.addEventListener('pointerdown', function(e){ dn = { x:e.clientX, y:e.clientY, t:performance.now() }; });
    canvas.addEventListener('pointerup', function(e){
        if(!dn) return;
        var moved = Math.hypot(e.clientX - dn.x, e.clientY - dn.y), dt = performance.now() - dn.t; dn = null;
        if(moved > 8 || dt > 450) return;
        var r = canvas.getBoundingClientRect();
        ndc.set(((e.clientX - r.left) / r.width) * 2 - 1, -((e.clientY - r.top) / r.height) * 2 + 1);
        ray.setFromCamera(ndc, camera);
        var targets = [];
        if(showPodPins) targets = targets.concat(layerPods.children);
        if(showLive) layerLive.children.forEach(function(o){ if(o.userData && o.userData.cc) targets.push(o); });
        var hits = ray.intersectObjects(targets, false), blocker = ray.intersectObject(earth, false);
        for(var i = 0; i < hits.length; i++){
            if(blocker.length && blocker[0].distance < hits[i].distance - 0.02) continue;     // on the far side of the globe
            openCountry(hits[i].object.userData.cc); return;
        }
        if(sheet.classList.contains('open')) closeSheet();
    });

    // ═══════════════════════════════════════════════
    //  IDLE TOUR — when nobody touches the globe it flies to where people are posting (then to the biggest pod countries)
    // ═══════════════════════════════════════════════
    var tourT = null, touring = false, tourIdx = 0, TOUR_IDLE = 12000, TOUR_HOLD = 4800;
    function tourSpots(){
        var seen = {}, out = [];
        DATA.activity.slice().sort(function(a, b){ return b.posts - a.posts; }).forEach(function(a){ if(!seen[a.c]){ seen[a.c] = 1; out.push({ c:a.c, n:a.n, lat:a.lat, lon:a.lon, txt:a.posts + ' new post' + (a.posts === 1 ? '' : 's') + ' right now' }); } });
        DATA.pods.slice().sort(function(a, b){ return b.count - a.count; }).forEach(function(p){ if(!seen[p.c]){ seen[p.c] = 1; out.push({ c:p.c, n:p.n, lat:p.lat, lon:p.lon, txt:p.count + ' pod' + (p.count === 1 ? '' : 's') }); } });
        return out.slice(0, 8);
    }
    function tourStop(){ touring = false; clearTimeout(tourT); tourChip.classList.remove('on'); }
    function tourArm(){
        clearTimeout(tourT);
        if(FROM_LOC || !spinWanted || REDUCED || (mode !== 'globe' && !(mapPrimary && map && map.getZoom() < 4.5))) return;
        tourT = setTimeout(tourStart, TOUR_IDLE);
    }
    function tourStart(){
        if((mode !== 'globe' && !mapPrimary) || document.hidden || sheet.classList.contains('open') || !tourSpots().length){ tourArm(); return; }
        touring = true; controls.autoRotate = false; clearTimeout(resumeTimer); hint.classList.add('hide');
        tourStep();
    }
    function tourStep(){
        if(!touring || (mode !== 'globe' && !mapPrimary)) return;
        var s = tourSpots(); if(!s.length){ tourStop(); tourArm(); return; }
        var spot = s[tourIdx++ % s.length];
        tourChip.innerHTML = '<i class="ri-compass-3-line"></i>' + esc(spot.n) + ' · ' + esc(spot.txt);
        tourChip.classList.add('on');
        if(mapPrimary && map) map.flyTo({ center:[spot.lon, spot.lat], zoom:Math.max(mapHomeZoom() + 1.2, 2.8), duration:2200, essential:true });
        else flyGlobe(spot.lat - 12, spot.lon, 2.5, 1700);
        tourT = setTimeout(tourStep, TOUR_HOLD);
    }
    stage.addEventListener('pointerdown', function(){ flyTok++; tourStop(); });
    stage.addEventListener('wheel', function(){ tourStop(); }, { passive:true });

    // ═══════════════════════════════════════════════
    //  REAL MAP — MapLibre, loaded only the first time you zoom in
    //  Tiles: CARTO (dark / light follows the theme), satellite = Esri imagery.
    // ═══════════════════════════════════════════════
    var map = null, mapPrimary = false, lastTouch = 0, mapLoading = false, mapWaiters = [], mapFailAt = 0, satOn = true, mkLive = [], mkPods = [], meMarker = null;
    var MAPLIB_JS  = 'https://cdn.jsdelivr.net/npm/maplibre-gl@5.6.0/dist/maplibre-gl.js';
    var MAPLIB_CSS = 'https://cdn.jsdelivr.net/npm/maplibre-gl@5.6.0/dist/maplibre-gl.css';
    // street map = CARTO Voyager (full colour). Satellite = Esri imagery (true Earth colours) with CARTO place names on top.
    // Both sit on a solid ocean-blue background layer and fade tiles in instantly, so the globe is never see-through while tiles load.
    var STYLE_STREET = 'https://basemaps.cartocdn.com/gl/voyager-gl-style/style.json';
    var STYLE_SAT = { version:8,
        sources:{
            sat:{ type:'raster', tileSize:256, maxzoom:18, attribution:'Imagery © Esri, Maxar, Earthstar Geographics · Labels © OpenStreetMap contributors © CARTO',
                  tiles:['https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}'] },
            lbl:{ type:'raster', tileSize:256, maxzoom:18, tiles:['https://basemaps.cartocdn.com/rastertiles/voyager_only_labels/{z}/{x}/{y}.png'] } },
        layers:[ { id:'ocean', type:'background', paint:{ 'background-color':'#1d5fa8' } },
                 { id:'sat', type:'raster', source:'sat', paint:{ 'raster-fade-duration':0 } },
                 { id:'lbl', type:'raster', source:'lbl', paint:{ 'raster-fade-duration':0 } } ] };
    function mapStyle(){ return satOn ? STYLE_SAT : STYLE_STREET; }

    function loadMapLib(cb){
        if(window.maplibregl) return cb(true);
        mapWaiters.push(cb);
        if(mapLoading) return;
        mapLoading = true;
        var l = document.createElement('link'); l.rel = 'stylesheet'; l.href = MAPLIB_CSS; document.head.appendChild(l);
        var s = document.createElement('script'); s.src = MAPLIB_JS;
        function fin(ok){ mapLoading = false; var w = mapWaiters; mapWaiters = []; w.forEach(function(f){ f(ok); }); }
        s.onload = function(){ fin(!!window.maplibregl); };
        s.onerror = function(){ fin(false); };
        document.head.appendChild(s);
    }
    function ensureMap(ll, z){
        if(map) return;
        map = new maplibregl.Map({ container:'map', style:mapStyle(), center:[ll.lon, ll.lat], zoom:(z == null ? ENTER_Z : z), minZoom:(z == null ? 1.2 : Math.max(0.6, z - 0.4)), maxZoom:17,
                                   attributionControl:false, dragRotate:false, pitchWithRotate:false, fadeDuration:0 });
        map.addControl(new maplibregl.AttributionControl({ compact:true }), 'bottom-left');
        map.touchZoomRotate.disableRotation();
        // zoomed out the map is a real globe (it flattens by itself as you get close to street level); a new style (theme / satellite) resets it, so it is applied on every style load
        map.on('style.load', function(){
            try{ map.setProjection({ type:'globe' }); }catch(e){}
            try{ map.setSky({ 'sky-color':'#2a6fc4', 'horizon-color':'#8ec5ff', 'fog-color':'#8ec5ff', 'sky-horizon-blend':0.6, 'horizon-fog-blend':0.6, 'fog-ground-blend':0.4, 'atmosphere-blend':['interpolate', ['linear'], ['zoom'], 0, 1, 5, 1, 7, 0] }); }catch(e){}   // soft blue glow around the Earth (ignored by older engines)
        });
        map.on('zoom', function(){ if(mode === 'map' && !switching && !mapPrimary && map.getZoom() < EXIT_Z) exitMap(); });
        map.on('movestart', function(e){ if(e.originalEvent){ tourStop(); lastTouch = performance.now(); hint.classList.add('hide'); } });
        map.on('moveend', function(e){ if(e.originalEvent){ lastTouch = performance.now(); tourArm(); } });
        map.on('error', function(e){ if(e && e.error && /Failed to fetch|NetworkError|AJAX/i.test(String(e.error.message || e.error))) toast('Some map tiles could not load.'); });
    }
    function buildMapMarkers(){
        if(!map || !window.maplibregl) return;
        mkLive.concat(mkPods).forEach(function(m){ m.remove(); }); mkLive = []; mkPods = [];
        (DATA.activity || []).forEach(function(a){
            var el = document.createElement('div'); el.className = 'mk-live'; el.innerHTML = '<i></i><i></i><i></i><b></b>';
            el.setAttribute('aria-label', a.n + ' live activity');
            el.addEventListener('click', function(e){ e.stopPropagation(); openCountry(a.c); });
            mkLive.push(new maplibregl.Marker({ element:el }).setLngLat([a.lon, a.lat]).addTo(map));
        });
        (DATA.pods || []).forEach(function(p){
            var el = document.createElement('button'); el.type = 'button'; el.className = 'mk-pod'; el.textContent = p.count > 99 ? '99+' : p.count;
            el.setAttribute('aria-label', p.count + ' pods in ' + p.n);
            el.addEventListener('click', function(e){ e.stopPropagation(); openCountry(p.c); });
            mkPods.push(new maplibregl.Marker({ element:el }).setLngLat([p.lon, p.lat]).addTo(map));
        });
        applyLayerVis();
    }
    function enterMap(ll){
        if(mode !== 'globe' || switching) return;
        if(mapFailAt && performance.now() - mapFailAt < 15000){ flyGlobe(ll.lat, ll.lon, 2.2, 450); return; }
        switching = true; tourStop(); controls.autoRotate = false; clearTimeout(resumeTimer);
        loadMapLib(function(ok){
            if(!ok){ mapFailAt = performance.now(); switching = false; toast('The map could not load. Check your connection.'); flyGlobe(ll.lat, ll.lon, 2.2, 450); return; }
            try{ ensureMap(ll); }catch(err){ mapFailAt = performance.now(); switching = false; toast('Your browser could not start the map.'); flyGlobe(ll.lat, ll.lon, 2.2, 450); return; }
            if(sheet.classList.contains('open')) closeSheet();
            flyTok++; mode = 'map'; stage.classList.add('map-on'); mapStage.classList.add('show');
            map.resize(); map.jumpTo({ center:[ll.lon, ll.lat], zoom:ENTER_Z, padding:{ top:0, bottom:0, left:0, right:0 } });
            buildMapMarkers();
            canvas.style.opacity = '0';
            hint.classList.add('hide'); liveChip.classList.add('off');
            setTimeout(function(){ globeOn = false; switching = false; }, 450);
        });
    }
    function exitMap(){
        if(mode !== 'map' || switching) return;
        switching = true;
        var c = map.getCenter();
        if(sheet.classList.contains('open')) closeSheet();
        camera.position.copy(latLonToVec(c.lat, c.lng, 1).normalize().multiplyScalar(EXIT_D));
        controls.update();
        globeOn = true; mode = 'globe';
        canvas.style.opacity = '1'; mapStage.classList.remove('show'); stage.classList.remove('map-on');
        renderLiveChip();
        setTimeout(function(){ switching = false; }, 450);
        if(spinWanted){ clearTimeout(resumeTimer); resumeTimer = setTimeout(function(){ controls.autoRotate = true; }, 2500); }
        tourArm();
    }
    document.getElementById('mt-back').addEventListener('click', exitMap);
    document.getElementById('btn-sat').classList.toggle('on', satOn);
    document.getElementById('btn-sat').addEventListener('click', function(){
        satOn = !satOn; this.classList.toggle('on', satOn);
        if(map) map.setStyle(mapStyle(), { diff:false });
    });
    document.getElementById('mt-locate').addEventListener('click', function(){
        if(!navigator.geolocation){ toast('Location is not available on this device.'); return; }
        navigator.geolocation.getCurrentPosition(function(pos){
            var ll = [pos.coords.longitude, pos.coords.latitude];        // used only to move the map on this device; never sent to the server
            if(meMarker) meMarker.remove();
            var el = document.createElement('div'); el.className = 'mk-me';
            meMarker = new maplibregl.Marker({ element:el }).setLngLat(ll).addTo(map);
            map.flyTo({ center:ll, zoom:11, duration:1400 });
        }, function(){ toast('Allow location to jump to where you are.'); }, { enableHighAccuracy:false, timeout:8000, maximumAge:60000 });
    });

    // "Search this area": pins whose country centre is inside the view; if none, offer the nearest one
    function hav(lat1, lon1, lat2, lon2){
        var R = Math.PI / 180, dLat = (lat2 - lat1) * R, dLon = (lon2 - lon1) * R;
        var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) + Math.cos(lat1 * R) * Math.cos(lat2 * R) * Math.sin(dLon / 2) * Math.sin(dLon / 2);
        return 6371 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }
    document.getElementById('mt-area').addEventListener('click', function(){
        if(!map) return;
        var b = map.getBounds(), seen = {}, list = [];
        DATA.pods.concat(DATA.activity).forEach(function(x){
            if(seen[x.c]) return;
            if(b.contains([x.lon, x.lat])){ seen[x.c] = 1; list.push(x.c); }
        });
        if(list.length){
            var h = '', n = 0;
            list.slice(0, 6).forEach(function(cc){ var c = countryHtml(cc); if(c){ n++; h += '<div class="es-country">' + esc(c.name) + '</div>' + c.html; } });
            showSheet('In this area', h, '', 'DezzMap');
            return;
        }
        var ctr = map.getCenter(), best = null, bd = 1e12;
        DATA.pods.concat(DATA.activity).forEach(function(x){ var d = hav(ctr.lat, ctr.lng, x.lat, x.lon); if(d < bd){ bd = d; best = x; } });
        var h2 = '<div class="es-empty">No pods or live posts are pinned in this view. Pins sit at the middle of each country, so zoom out to find them.</div>';
        if(best) h2 += '<button type="button" class="es-near" id="es-near">Go to ' + esc(best.n) + ' · ' + Math.round(bd).toLocaleString() + ' km away</button>';
        showSheet('In this area', h2, '', 'DezzMap');
        var nb = document.getElementById('es-near');
        if(nb) nb.addEventListener('click', function(){ openCountry(best.c); });
    });



    // ═══════════ a place sent from a profile / pod location: earth.php?loc=Lagos, Nigeria ═══════════
    var placeMarker = null;
    function placeFromQuery(){
        var q = ''; try{ q = (new URLSearchParams(location.search).get('loc') || '').replace(/\s+/g, ' ').trim().slice(0, 120); }catch(e){}
        if(!q) return;
        showSheet(q, '<div class="es-empty">Finding this place…</div>', '', 'Location'); sheet.classList.add('place-mode');
        fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(q), { headers:{ 'Accept':'application/json' } })
            .then(function(r){ return r.ok ? r.json() : Promise.reject(); })
            .then(function(a){
                if(!a || !a[0]){ esBody.innerHTML = '<div class="es-empty">Could not find “' + esc(q) + '” on the map.</div>'; return; }
                var lat = parseFloat(a[0].lat), lon = parseFloat(a[0].lon), bb = a[0].boundingbox;
                esBody.innerHTML = '<div class="es-place-name">' + esc(a[0].display_name || q) + '</div>' + (mode === 'map' ? ATTR : '');
                if(mapPrimary && map){
                    if(placeMarker) placeMarker.remove();
                    var el = document.createElement('div'); el.className = 'mk-place';
                    placeMarker = new maplibregl.Marker({ element:el, anchor:'bottom' }).setLngLat([lon, lat]).addTo(map);
                    var pad = { top:90, bottom:Math.round(stage.clientHeight * 0.42), left:40, right:40 };
                    if(bb && bb.length === 4) map.fitBounds([[parseFloat(bb[2]), parseFloat(bb[0])], [parseFloat(bb[3]), parseFloat(bb[1])]], { padding:pad, maxZoom:13, duration:2600, essential:true });
                    else map.flyTo({ center:[lon, lat], zoom:10, duration:2600, essential:true });
                } else {
                    flyGlobe(lat - 14, lon, 2.4, 1400);
                }
            })
            .catch(function(){ esBody.innerHTML = '<div class="es-empty">Could not look this place up right now. Check your connection.</div>'; });
    }

    // ═══════════ MAP FIRST — the dark map-globe is the home view; the cartoon 3D globe is only a fallback when the map cannot load ═══════════
    var HOME = { lat:18, lon:12 };
    function mapHomeZoom(){                                   // zoom at which the whole round Earth fits the screen
        var m = Math.min(stage.clientWidth || 400, stage.clientHeight || 700), R = m * 0.44;
        return Math.max(0.8, Math.min(2.2, Math.log(R * 2 * Math.PI / 512) / Math.LN2));
    }
    function bootMap(){
        loadMapLib(function(ok){
            if(!ok){ hideLoader(); placeFromQuery(); return; }   // keep the cartoon globe
            try{ ensureMap(HOME, mapHomeZoom()); }catch(err){ hideLoader(); placeFromQuery(); return; }
            mapPrimary = true; mode = 'map'; globeOn = false;
            stage.classList.add('map-on', 'map-first'); mapStage.classList.add('show'); canvas.style.opacity = '0';
            map.resize(); buildMapMarkers();
            map.once('load', hideLoader); setTimeout(hideLoader, 4500);
            tourArm(); lastTouch = performance.now();
            placeFromQuery();
        });
    }
    // slow idle spin of the map-globe (only while zoomed out, nothing open, nobody touching it)
    var spinPrev = 0;
    function spinTick(now){
        requestAnimationFrame(spinTick);
        var dt = Math.min(now - spinPrev, 60); spinPrev = now;
        if(!mapPrimary || !map || document.hidden || !spinWanted || touring || REDUCED) return;
        if(sheet.classList.contains('open') || now - lastTouch < 2500 || map.getZoom() > 3.2) return;
        var c = map.getCenter(), lng = c.lng + dt * 0.006;           // ≈ 0.36° per second
        map.jumpTo({ center:[lng > 180 ? lng - 360 : lng, c.lat] });
    }
    requestAnimationFrame(spinTick);

    // ── theme: stars, glow ring, map style ──
    var lastLight = null;
    window.applyTheme = function(){
        var light = isLight();
        stars.visible = !light;
        ringNavy.material.color.set(light ? 0xbfd8ff : 0x0a1038);
        lastLight = light;
    };
    applyTheme();
    new MutationObserver(function(){ applyTheme(); }).observe(document.documentElement, { attributes:true, attributeFilter:['data-theme'] });

    // ── resize with the stage ──
    function resize(){
        var w = stage.clientWidth, h = stage.clientHeight;
        if(!w || !h) return;
        renderer.setSize(w, h, false);
        camera.aspect = w / h;
        camera.fov = w < h ? 54 : 45;   // keep the whole globe + rings in view on narrow / tall screens
        camera.updateProjectionMatrix();
        if(map) map.resize();
    }
    resize();
    if(window.ResizeObserver) new ResizeObserver(resize).observe(stage);
    else window.addEventListener('resize', resize);

    // ── render loop (pauses when the tab is hidden; the globe stops drawing while the map is up) ──
    var running = true, lastFrame = 0;
    document.addEventListener('visibilitychange', function(){
        var was = running;
        running = !document.hidden;
        if(running && !was) loop();
    });
    function loop(){
        if(!running) return;
        requestAnimationFrame(loop);
        var now = performance.now();
        if(PERF && now - lastFrame < 32) return;          // ~30 fps in performance mode
        lastFrame = now;
        if(!globeOn) return;
        controls.rotateSpeed = Math.min(0.55, 0.16 + (camera.position.length() - controls.minDistance) * 0.2);   // finer drag when zoomed in
        controls.update();
        stars.rotation.y += 0.00015;
        tickPulses(now);
        renderer.render(scene, camera);
    }

    // everything is drawn locally (no image downloads), so the globe is ready straight away
    if(PERF) document.documentElement.classList.add('perf');
    var loaderGone = false;
    function hideLoader(){
        if(loaderGone) return; loaderGone = true;
        loader.classList.add('hide');
        setTimeout(function(){ loader.style.display = 'none'; }, 600);
    }
    loop(); bootMap();
    setTimeout(hideLoader, 6000);
})();
</script>
</body>
</html>