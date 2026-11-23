<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$uid = (int)$_SESSION['user_id'];

// ── USER INFO ─────────────────────────────────────────────────
$u = $conn->prepare("SELECT u.*, us.Bio, us.activity_vibe, us.user_type FROM users u LEFT JOIN user_settings us ON us.user_id = u.id WHERE u.id = ?");
$u->bind_param("i", $uid);
$u->execute();
$user = $u->get_result()->fetch_assoc();
$u->close();

$joined_ts   = strtotime($user['created_at']);
$days_on_app = max(1, (int)floor((time() - $joined_ts) / 86400));

// ── ACTIVITY COUNTS ───────────────────────────────────────────
function single($conn, $sql) {
    $r = $conn->query($sql);
    return $r ? (int)$r->fetch_row()[0] : 0;
}

$total_memes    = single($conn, "SELECT COUNT(*) FROM memes WHERE uploaded_by=$uid");
$total_likes    = single($conn, "SELECT COUNT(*) FROM likes WHERE user_id=$uid");
$total_reposts  = single($conn, "SELECT COUNT(*) FROM reposts WHERE user_id=$uid");
$total_favs     = single($conn, "SELECT COUNT(*) FROM favorites WHERE user_id=$uid");
$total_messages = single($conn, "SELECT COUNT(*) FROM messages WHERE sender_id=$uid");
$total_trackers = single($conn, "SELECT COUNT(*) FROM followers WHERE following_id=$uid");
$total_tracking = single($conn, "SELECT COUNT(*) FROM followers WHERE follower_id=$uid");
$profile_views  = single($conn, "SELECT COUNT(*) FROM profile_views WHERE viewer_id=$uid");
$received_views = single($conn, "SELECT COUNT(*) FROM profile_views WHERE profile_id=$uid");
$total_remixes  = single($conn, "SELECT COUNT(*) FROM remix WHERE user_id=$uid");
$pods_joined    = single($conn, "SELECT COUNT(*) FROM group_members WHERE user_id=$uid");
$sounds_saved   = single($conn, "SELECT COUNT(*) FROM saved_sounds WHERE user_id=$uid");

// ── RECEIVED on own memes ─────────────────────────────────────
$received_likes   = single($conn, "SELECT COUNT(*) FROM likes l JOIN memes m ON m.id=l.meme_id WHERE m.uploaded_by=$uid");
$received_reposts = single($conn, "SELECT COUNT(*) FROM reposts r JOIN memes m ON m.id=r.meme_id WHERE m.uploaded_by=$uid");

// ── ESTIMATED TIME SPENT ──────────────────────────────────────
$secs = [
    'likes'    => $total_likes    * 8,
    'reposts'  => $total_reposts  * 12,
    'favs'     => $total_favs     * 10,
    'messages' => $total_messages * 30,
    'memes'    => $total_memes    * 120,
    'views'    => $profile_views  * 20,
    'remixes'  => $total_remixes  * 45,
];
$time_seconds = array_sum($secs);

$time_hours   = floor($time_seconds / 3600);
$time_minutes = floor(($time_seconds % 3600) / 60);
$avg_per_day  = $days_on_app > 0 ? round($time_seconds / $days_on_app / 60) : 0;

function fmt_time($s) {
    $h = floor($s / 3600);
    $m = round(($s % 3600) / 60);
    if ($h > 0) return $h . 'h ' . $m . 'm';
    return round($s / 60) . 'm';
}

// ── DAILY ACTIVITY (last 14 days) ─────────────────────────────
$daily_raw = $conn->query("
    SELECT DATE(created_at) AS day, COUNT(*) AS cnt
    FROM (
        SELECT created_at FROM likes WHERE user_id=$uid
        UNION ALL
        SELECT created_at FROM reposts WHERE user_id=$uid
        UNION ALL
        SELECT created_at FROM favorites WHERE user_id=$uid
        UNION ALL
        SELECT created_at FROM messages WHERE sender_id=$uid
        UNION ALL
        SELECT created_at FROM memes WHERE uploaded_by=$uid
    ) AS all_actions
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
    GROUP BY day
    ORDER BY day ASC
");
$daily_data = [];
if ($daily_raw) {
    while ($row = $daily_raw->fetch_assoc()) {
        $daily_data[$row['day']] = (int)$row['cnt'];
    }
}
$chart_labels = [];
$chart_values = [];
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $chart_labels[] = date('M j', strtotime($d));
    $chart_values[] = $daily_data[$d] ?? 0;
}
$max_val = max(1, max($chart_values));

// ── HOURLY HEATMAP (all time) ─────────────────────────────────
$hourly_raw = $conn->query("
    SELECT HOUR(created_at) AS hr, COUNT(*) AS cnt
    FROM (
        SELECT created_at FROM likes WHERE user_id=$uid
        UNION ALL
        SELECT created_at FROM messages WHERE sender_id=$uid
        UNION ALL
        SELECT created_at FROM memes WHERE uploaded_by=$uid
    ) AS all_a
    GROUP BY hr
");
$hourly = array_fill(0, 24, 0);
if ($hourly_raw) {
    while ($row = $hourly_raw->fetch_assoc()) {
        $hourly[(int)$row['hr']] = (int)$row['cnt'];
    }
}
$max_hourly = max(1, max($hourly));

// ── MOST ACTIVE DAY ───────────────────────────────────────────
$most_active_day = '';
if (!empty($daily_data)) {
    arsort($daily_data);
    $most_active_day = date('l, M j', strtotime(array_key_first($daily_data)));
}

// ── CONTENT BREAKDOWN ─────────────────────────────────────────
$img_count  = single($conn, "SELECT COUNT(*) FROM memes WHERE uploaded_by=$uid AND type='image'");
$vid_count  = single($conn, "SELECT COUNT(*) FROM memes WHERE uploaded_by=$uid AND type='video'");
$txt_count  = single($conn, "SELECT COUNT(*) FROM memes WHERE uploaded_by=$uid AND type='text'");

// ── ENGAGEMENT RATE ───────────────────────────────────────────
$engagement = $total_memes > 0 ? round(($received_likes + $received_reposts) / $total_memes, 1) : 0;

// ── STREAK (consecutive active days) ─────────────────────────
$streak_raw = $conn->query("
    SELECT DISTINCT DATE(created_at) AS day
    FROM (
        SELECT created_at FROM likes WHERE user_id=$uid
        UNION ALL SELECT created_at FROM memes WHERE uploaded_by=$uid
        UNION ALL SELECT created_at FROM messages WHERE sender_id=$uid
    ) AS acts
    ORDER BY day DESC
");
$streak = 0;
$prev   = null;
if ($streak_raw) {
    while ($row = $streak_raw->fetch_assoc()) {
        $d = $row['day'];
        if ($prev === null) {
            $streak = 1;
        } elseif (strtotime($prev) - strtotime($d) === 86400) {
            $streak++;
        } else {
            break;
        }
        $prev = $d;
    }
}

// ── TOP HASHTAGS ──────────────────────────────────────────────
$tag_raw = $conn->query("SELECT caption FROM memes WHERE uploaded_by=$uid AND caption IS NOT NULL");
$tag_counts = [];
if ($tag_raw) {
    while ($row = $tag_raw->fetch_assoc()) {
        preg_match_all('/#(\w+)/', $row['caption'], $matches);
        foreach ($matches[1] as $tag) {
            $t = strtolower($tag);
            $tag_counts[$t] = ($tag_counts[$t] ?? 0) + 1;
        }
    }
}
arsort($tag_counts);
$top_tags = array_slice($tag_counts, 0, 6, true);

// ── RANK BADGE (all shades of red) ────────────────────────────
$score = $total_memes * 5 + $received_likes * 3 + $received_reposts * 4 + $total_trackers * 2;
if ($score >= 500)      { $rank = 'Legend'; $rank_color = '#ffa3a3'; }
elseif ($score >= 200)  { $rank = 'Icon';   $rank_color = '#ff7b7b'; }
elseif ($score >= 100)  { $rank = 'Rising'; $rank_color = '#FF4C4C'; }
elseif ($score >= 40)   { $rank = 'Active'; $rank_color = '#e03a3a'; }
else                    { $rank = 'New';    $rank_color = '#b85454'; }

$display_name = !empty($user['nickname']) ? $user['nickname'] : $user['username'];
$avatar       = !empty($user['profile_pic']) ? $user['profile_pic'] : 'uploads/me.png';

// ── Time-spent rows ───────────────────────────────────────────
$time_items = [
    ['ri-heart-line',        'Liking memes',       $secs['likes'],    '#FF2D2D'],
    ['ri-repeat-2-line',     'Reposting',          $secs['reposts'],  '#ff6b6b'],
    ['ri-bookmark-line',     'Saving favourites',  $secs['favs'],     '#e02424'],
    ['ri-chat-3-line',       'Messaging',          $secs['messages'], '#ff9a9a'],
    ['ri-upload-cloud-line', 'Uploading memes',    $secs['memes'],    '#FF4C4C'],
    ['ri-user-search-line',  'Browsing profiles',  $secs['views'],    '#c43030'],
];

// ── Stat tiles (icon colours are all red variations) ──────────
$stats = [
    ['ri-image-2-line',      '#ff6b6b', $total_memes,      'Memes uploaded', 'All time'],
    ['ri-heart-line',        '#FF2D2D', $total_likes,      'Memes liked',    'All time'],
    ['ri-heart-fill',        '#FF2D2D', $received_likes,   'Likes received', 'On your memes'],
    ['ri-repeat-2-line',     '#e02424', $total_reposts,    'Memes reposted', 'All time'],
    ['ri-share-forward-line','#ff6b6b', $received_reposts, 'Times reposted', 'Your content'],
    ['ri-bookmark-line',     '#ff9a9a', $total_favs,       'Favourited',     'All time'],
    ['ri-chat-3-line',       '#FF4C4C', $total_messages,   'Messages sent',  'All time'],
    ['ri-user-follow-line',  '#FF2D2D', $total_trackers,   'Trackers',       'Tracking you'],
    ['ri-user-star-line',    '#ff6b6b', $total_tracking,   'Tracking',       'You track'],
    ['ri-eye-line',          '#ff9a9a', $received_views,   'Profile views',  'People viewed you'],
    ['ri-user-search-line',  '#e02424', $profile_views,    'Profiles visited','You visited'],
    ['ri-repeat-line',       '#FF4C4C', $total_remixes,    'Remixes made',   'All time'],
    ['ri-group-line',        '#ff6b6b', $pods_joined,      'Pods joined',    'Communities'],
    ['ri-music-2-line',      '#FF2D2D', $sounds_saved,     'Sounds saved',   'In playlist'],
    ['ri-star-line',         '#ff9a9a', $engagement,       'Avg engagement', 'Per meme posted'],
];

// ── Insights ──────────────────────────────────────────────────
$peak_hour  = array_search(max($hourly), $hourly);
$peak_label = date('g A', mktime((int)$peak_hour, 0, 0));
$insights = [];
if ($most_active_day) {
    $insights[] = ['ri-calendar-line', 'Most Active Day', htmlspecialchars($most_active_day) . ' was your busiest day on Dezzme recently.'];
}
$insights[] = ['ri-flashlight-line', 'Activity Score', 'Your current score is <strong>' . number_format($score) . '</strong> — that earns you the <strong>' . $rank . '</strong> rank.'];
if ($streak > 1) {
    $insights[] = ['ri-fire-line', $streak . '-Day Streak', "You've been active $streak days in a row. Keep the momentum going."];
}
$insights[] = ['ri-time-line', 'Peak Hour', "You're most active around <strong>$peak_label</strong> based on all your activity so far."];
if ($received_likes > 0 && $total_memes > 0) {
    $insights[] = ['ri-heart-line', 'Engagement Rate', 'Your memes average <strong>' . $engagement . '</strong> engagements each. ' . ($engagement >= 3 ? "That's strong." : 'Keep posting consistently to grow it.')];
}
$insights[] = ['ri-cloud-line', 'Zero Storage Used', "All $total_memes of your uploads live in the cloud. Your phone has lost nothing."];
$insights[] = ['ri-global-line', 'Social Reach', "Your content has been reposted <strong>$received_reposts</strong> times and reached $total_trackers trackers."];

$img_pct = $total_memes > 0 ? round($img_count / $total_memes * 100) : 0;
$vid_pct = $total_memes > 0 ? round($vid_count / $total_memes * 100) : 0;
$txt_pct = $total_memes > 0 ? round($txt_count / $total_memes * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Analytics — Dezzme</title>

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
    /* the whole page uses red + its variations only */
    --red:    #FF2D2D;
    --red-1:  #ff6b6b;
    --red-2:  #e02424;
    --red-3:  #b91c1c;
    --red-4:  #ff9a9a;

    --fg:      255,255,255;   /* R,G,B of the text-on-surface tint used for lines + soft fills (dark theme = white) */
    --ui-text: #ffffff;

    --bg:      linear-gradient(-45deg,#010218,#070924,#070924);
    --surface: rgba(var(--fg),0.05);
    --line:    rgba(var(--fg),0.09);
    --line2:   rgba(var(--fg),0.14);
    --text:    #ffffff;
    --muted:   rgba(var(--fg),0.45);
    --muted-2: rgba(var(--fg),0.28);
    --sub:     rgba(var(--fg),0.62);

    --max-w:    580px;
    --topbar-h: 54px;
    --pad:      clamp(9px, 3vw, 16px);
    --r:        6px;
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
.an-lane{
    width:100%; max-width:var(--max-w);
    min-height:100vh;
    display:flex; flex-direction:column;
    padding-bottom:60px;
}
@media (min-width:801px){
    .an-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); }
}
@media (max-width:800px){ .an-lane{ max-width:100%; } }

/* ══════════════════════════════════════
   TOPBAR — X on the left, title on the right
══════════════════════════════════════ */
.an-topbar{
    position:sticky; top:0; z-index:500;
    height:var(--topbar-h);
    display:flex; align-items:center; gap:2px;
    padding:0 10px;
    background:var(--bg);
    background-attachment:fixed;
}
@media (max-width:768px){ .an-topbar{ border-bottom:1px solid var(--line); } }
.an-x{
    width:34px; height:34px;
    background:none; border:none; color:var(--ui-text);
    display:flex; align-items:center; justify-content:center;
    opacity:.9; transition:opacity .2s, color .2s;
    padding:0; flex-shrink:0;
}
.an-x:hover{ opacity:1; color:var(--red); }
.an-x svg{ width:22px; height:22px; display:block; }
.an-title{
    flex:1; min-width:0;
    font-size:.92rem; font-weight:700; color:var(--ui-text); letter-spacing:-.1px;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    text-align:right; margin:0 6px 0 10px;
}
@media (max-width:490px){ .an-x{ width:30px; height:30px; } .an-title{ font-size:.84rem; } }
@media (max-width:380px){ .an-title{ font-size:.8rem; } }

/* ── page-load reveal ── */
.rv{ opacity:0; }
body.ready .rv{ animation:anIn .55s cubic-bezier(.22,1,.36,1) both; animation-delay:calc(var(--i, 0) * 55ms); }
@keyframes anIn{ from{ opacity:0; transform:translateY(12px); } to{ opacity:1; transform:none; } }
@media (prefers-reduced-motion:reduce){ body.ready .rv{ animation:none; opacity:1; } }

/* ══════════════════════════════════════
   HERO
══════════════════════════════════════ */
.hero{
    padding:clamp(14px, 4vw, 20px) var(--pad);
    border-top:1px solid var(--line);
    display:flex; align-items:center; gap:clamp(11px, 3.4vw, 16px);
}
.hero-avatar{
    width:clamp(48px, 14vw, 64px); height:clamp(48px, 14vw, 64px);
    border-radius:50%; object-fit:cover; flex-shrink:0;
    border:2.4px solid var(--red);
    box-shadow:0 4px 18px rgba(255,45,45,.25);
}
.hero-info{ flex:1; min-width:0; }
.hero-name{ font-size:clamp(.86rem, 3.2vw, 1.04rem); font-weight:700; color:var(--ui-text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.hero-sub{ font-size:clamp(.58rem, 2vw, .7rem); color:var(--muted); margin-top:2px; }
.hero-badges{ display:flex; gap:6px; flex-wrap:wrap; margin-top:9px; }
.hero-badge{
    display:inline-flex; align-items:center; gap:5px;
    height:clamp(20px, 5.6vw, 23px); padding:0 9px; border-radius:4px;
    font-size:clamp(.52rem, 1.8vw, .62rem); font-weight:700; white-space:nowrap;
    background:rgba(255,45,45,.12); color:var(--red);
}
.hero-badge i{ font-size:.8rem; }
.hero-time{ text-align:right; flex-shrink:0; }
.hero-time-val{ font-size:clamp(1.05rem, 4.6vw, 1.5rem); font-weight:700; color:var(--red); line-height:1; }
.hero-time-label{ font-size:clamp(.5rem, 1.7vw, .62rem); color:var(--muted); margin-top:5px; }
.hero-time-sub{ font-size:clamp(.48rem, 1.6vw, .58rem); color:var(--muted-2); margin-top:2px; }
@media (max-width:380px){
    .hero{ flex-wrap:wrap; }
    .hero-time{ width:100%; text-align:left; display:flex; align-items:baseline; gap:8px; }
    .hero-time-label, .hero-time-sub{ margin-top:0; }
}

/* ══════════════════════════════════════
   SECTIONS
══════════════════════════════════════ */
.an-sec{ margin-top:clamp(20px, 5.4vw, 30px); }
.sec-head{ display:flex; align-items:center; gap:12px; padding:0 var(--pad) 12px; }
.sec-icon{
    width:clamp(28px, 7.6vw, 36px); height:clamp(28px, 7.6vw, 36px);
    border-radius:6px; background:var(--surface);
    display:flex; align-items:center; justify-content:center;
    font-size:clamp(.82rem, 2.6vw, 1rem); color:var(--red); flex-shrink:0;
}
.sec-title{ font-size:clamp(.82rem, 2.8vw, .96rem); font-weight:700; letter-spacing:-.1px; color:var(--ui-text); }
.sec-sub{ font-size:clamp(.56rem, 1.9vw, .68rem); color:var(--muted); margin-top:2px; }
.sec-pad{ padding:0 var(--pad); }

/* ── Time spent rows ── */
.time-list{ border-top:1px solid var(--line); }
.time-row{
    display:flex; align-items:center; gap:clamp(10px, 3vw, 14px);
    padding:clamp(11px, 3.2vw, 15px) var(--pad);
    border-bottom:1px solid rgba(var(--fg),.06);
}
.time-row:last-child{ border-bottom:none; }
.time-icon{
    width:clamp(27px, 7.4vw, 34px); height:clamp(27px, 7.4vw, 34px);
    border-radius:6px; background:var(--surface);
    display:flex; align-items:center; justify-content:center;
    flex-shrink:0; font-size:clamp(.78rem, 2.5vw, .96rem);
}
.time-body{ flex:1; min-width:0; }
.time-top{ display:flex; align-items:baseline; justify-content:space-between; gap:8px; }
.time-label{ font-size:clamp(.68rem, 2.3vw, .8rem); font-weight:600; color:var(--ui-text); }
.time-val{ font-size:clamp(.7rem, 2.4vw, .84rem); font-weight:700; color:var(--red); }
.bar-track{ height:4px; border-radius:2px; background:rgba(var(--fg),.07); overflow:hidden; margin-top:7px; }
.bar-fill{ height:100%; border-radius:2px; width:0; transition:width 1s cubic-bezier(.22,1,.36,1); }

/* ── cards ── */
.an-card{
    background:var(--surface); border:1px solid var(--line);
    border-radius:var(--r); padding:clamp(14px, 4vw, 20px) clamp(11px, 3.4vw, 16px);
}
.chart-title{
    font-size:.58rem; font-weight:700; color:var(--muted);
    letter-spacing:.08em; text-transform:uppercase; margin-bottom:16px;
}

/* ── Bar chart ── */
.bar-chart{ display:grid; grid-template-columns:repeat(14, minmax(0,1fr)); gap:clamp(2px, .9vw, 5px); height:clamp(72px, 22vw, 100px); align-items:end; }
.bar{
    width:100%; border-radius:3px 3px 0 0; min-height:3px; position:relative;
    background:linear-gradient(180deg, var(--red), var(--red-3));
    transition:height .7s cubic-bezier(.22,1,.36,1);
}
.bar.zero{ background:rgba(var(--fg),.07); }
/* tooltips stay dark with white text in both themes */
.bar::after{
    content:attr(data-val); position:absolute; bottom:calc(100% + 5px); left:50%;
    transform:translateX(-50%); padding:2px 6px; border-radius:4px;
    background:rgba(8,8,22,.97); border:1px solid rgba(255,255,255,.14);
    font-size:.58rem; font-weight:600; color:#fff; white-space:nowrap;
    opacity:0; pointer-events:none; transition:opacity .15s; z-index:3;
}
.bar:hover::after, .bar.on::after{ opacity:1; }
.bar-labels{ display:grid; grid-template-columns:repeat(14, minmax(0,1fr)); gap:clamp(2px, .9vw, 5px); margin-top:7px; }
.bar-labels span{ font-size:.5rem; color:var(--muted); text-align:center; white-space:nowrap; overflow:visible; }
@media (max-width:560px){ .bar-labels span:nth-child(even){ visibility:hidden; } }

/* ── Heatmap (AM row + PM row) ── */
.heat{ display:grid; grid-template-columns:auto repeat(12, minmax(0,1fr)); gap:clamp(2px, .9vw, 4px); align-items:center; margin-top:2px; }
.heat-row-lbl{ font-size:.5rem; font-weight:700; color:var(--muted); padding-right:4px; letter-spacing:.04em; }
.heat-cell{ aspect-ratio:1/1; border-radius:3px; position:relative; transition:transform .15s; }
.heat-cell:hover{ transform:scale(1.18); z-index:2; }
.heat-cell.peak{ box-shadow:0 0 0 1.5px var(--ui-text) inset; }
.heat-cell::after{
    content:attr(data-tip); position:absolute; bottom:calc(100% + 5px); left:50%;
    transform:translateX(-50%); padding:3px 7px; border-radius:4px;
    background:rgba(8,8,22,.97); border:1px solid rgba(255,255,255,.14);
    font-size:.58rem; font-weight:600; color:#fff; white-space:nowrap;
    opacity:0; pointer-events:none; transition:opacity .15s; z-index:5;
}
.heat-cell:hover::after, .heat-cell.on::after{ opacity:1; }
.heat-cols{ display:grid; grid-template-columns:auto repeat(12, minmax(0,1fr)); gap:clamp(2px, .9vw, 4px); margin-top:7px; }
.heat-cols span{ font-size:.5rem; color:var(--muted); text-align:center; }
.heat-cols span:first-child{ visibility:hidden; padding-right:4px; }
.heat-legend{ display:flex; align-items:center; justify-content:flex-end; gap:6px; margin-top:12px; font-size:.5rem; color:var(--muted); }
.heat-legend i{ display:block; width:12px; height:12px; border-radius:3px; }

/* ── Stat tiles (bordered grid, flush with the lane) ── */
.stat-grid{ display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); border-top:1px solid var(--line); }
.stat-cell{
    padding:clamp(12px, 3.6vw, 18px) var(--pad);
    border-bottom:1px solid var(--line);
    transition:background .2s;
}
.stat-cell:nth-child(odd){ border-right:1px solid var(--line); }
.stat-cell:last-child:nth-child(odd){ grid-column:1 / -1; border-right:none; }
.stat-cell:hover{ background:rgba(var(--fg),.03); }
.stat-ico{
    width:clamp(24px, 6.4vw, 30px); height:clamp(24px, 6.4vw, 30px);
    border-radius:6px; background:var(--surface);
    display:flex; align-items:center; justify-content:center;
    font-size:clamp(.74rem, 2.3vw, .9rem); margin-bottom:10px;
}
.stat-val{ font-size:clamp(1.05rem, 4.4vw, 1.4rem); font-weight:700; line-height:1; color:var(--ui-text); }
.stat-label{ font-size:clamp(.58rem, 2vw, .7rem); color:var(--sub); margin-top:5px; }
.stat-delta{
    display:inline-flex; align-items:center; height:17px; padding:0 6px; margin-top:7px;
    border-radius:4px; background:rgba(255,45,45,.1); color:var(--red-1);
    font-size:.5rem; font-weight:600; letter-spacing:.02em;
}

/* ── Content breakdown ── */
.progress-list{ display:flex; flex-direction:column; gap:15px; }
.progress-head{ display:flex; justify-content:space-between; font-size:clamp(.62rem, 2.1vw, .74rem); margin-bottom:7px; }
.progress-head span:first-child{ color:rgba(var(--fg),.8); font-weight:600; display:flex; align-items:center; gap:6px; }
.progress-head span:first-child i{ color:var(--red); }
.progress-head span:last-child{ color:var(--muted); }
.progress-track{ height:6px; border-radius:3px; background:rgba(var(--fg),.07); overflow:hidden; }
.progress-fill{ height:100%; border-radius:3px; width:0; transition:width 1s cubic-bezier(.22,1,.36,1); }

/* ── Hashtags ── */
.tag-cloud{ display:flex; flex-wrap:wrap; gap:6px; }
.tag-pill{
    display:flex; align-items:center; gap:7px;
    height:clamp(26px, 7.2vw, 31px); padding:0 11px; border-radius:4px;
    background:rgba(var(--fg),.1); color:var(--ui-text);
    font-size:clamp(.62rem, 2.1vw, .74rem); font-weight:600;
    transition:background .2s;
}
.tag-pill:hover{ background:rgba(var(--fg),.18); }
.tag-pill .hash{ color:var(--red); }
.tag-pill .tag-count{
    font-size:.54rem; font-weight:700; color:var(--red);
    background:rgba(255,45,45,.14); padding:2px 6px; border-radius:3px;
}

/* ── Insights ── */
.ins-list{ border-top:1px solid var(--line); }
.ins-row{
    display:flex; align-items:flex-start; gap:clamp(10px, 3vw, 14px);
    padding:clamp(11px, 3.2vw, 15px) var(--pad);
    border-bottom:1px solid rgba(var(--fg),.06);
}
.ins-row:last-child{ border-bottom:none; }
.ins-icon{
    width:clamp(27px, 7.4vw, 34px); height:clamp(27px, 7.4vw, 34px);
    border-radius:6px; background:var(--surface);
    display:flex; align-items:center; justify-content:center;
    flex-shrink:0; color:var(--red); font-size:clamp(.78rem, 2.5vw, .96rem);
}
.ins-title{ font-size:clamp(.72rem, 2.5vw, .84rem); font-weight:600; margin-bottom:3px; color:var(--ui-text); }
.ins-text{ font-size:clamp(.62rem, 2.1vw, .74rem); color:var(--muted); line-height:1.6; }
.ins-text strong{ color:var(--red); font-weight:700; }

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
.sk-x{ width:20px; height:20px; }
.sk-ttl{ width:110px; height:13px; }
.sk-hero{ display:flex; align-items:center; gap:14px; padding:clamp(14px,4vw,20px) var(--pad); border-top:1px solid var(--line); }
.sk-av{ width:clamp(48px,14vw,64px); height:clamp(48px,14vw,64px); border-radius:50%; flex-shrink:0; }
.sk-hl{ flex:1; display:flex; flex-direction:column; gap:8px; }
.sk-h1{ width:55%; height:13px; } .sk-h2{ width:75%; height:9px; } .sk-h3{ width:40%; height:20px; border-radius:4px; }
.sk-big{ width:70px; height:26px; }
.sk-sec{ padding:clamp(18px,5vw,28px) var(--pad) 0; }
.sk-sec-h{ display:flex; align-items:center; gap:12px; margin-bottom:14px; }
.sk-ico{ width:clamp(28px,7.6vw,36px); height:clamp(28px,7.6vw,36px); border-radius:6px; flex-shrink:0; }
.sk-t{ display:flex; flex-direction:column; gap:6px; } .sk-t1{ width:130px; height:12px; } .sk-t2{ width:170px; height:9px; }
.sk-row{ display:flex; align-items:center; gap:12px; padding:13px 0; border-top:1px solid rgba(var(--fg),.06); }
.sk-row .sk-ico{ width:clamp(27px,7.4vw,34px); height:clamp(27px,7.4vw,34px); }
.sk-l{ flex:1; display:flex; flex-direction:column; gap:7px; } .sk-l1{ width:40%; height:11px; } .sk-l2{ width:100%; height:5px; }
.sk-chart{ height:clamp(110px,32vw,150px); border-radius:6px; }

/* ═══════════════════════════════════════════════════════════════
   RESPONSIVE ONLY — everything 10% bigger (desktop untouched)
   Kept near the end so it wins over the rules above.
═══════════════════════════════════════════════════════════════ */
@media (max-width:800px){
    :root{
        --topbar-h:59px;
        --pad:clamp(10px, 3.3vw, 17.6px);
        --r:6.6px;
    }

    /* topbar */
    .an-topbar{ padding:0 11px; }
    .an-x{ width:37px; height:37px; }
    .an-x svg{ width:24px; height:24px; }
    .an-title{ font-size:1.01rem; margin:0 7px 0 11px; }

    /* hero */
    .hero{ padding:clamp(15px, 4.4vw, 22px) var(--pad); gap:clamp(12px, 3.74vw, 17.6px); }
    .hero-avatar{ width:clamp(53px, 15.4vw, 70px); height:clamp(53px, 15.4vw, 70px); border-width:2.6px; }
    .hero-name{ font-size:clamp(.95rem, 3.52vw, 1.14rem); }
    .hero-sub{ font-size:clamp(.64rem, 2.2vw, .77rem); margin-top:2.2px; }
    .hero-badges{ gap:6.6px; margin-top:10px; }
    .hero-badge{ gap:5.5px; height:clamp(22px, 6.16vw, 25px); padding:0 10px; font-size:clamp(.57rem, 1.98vw, .68rem); }
    .hero-badge i{ font-size:.88rem; }
    .hero-time-val{ font-size:clamp(1.155rem, 5.06vw, 1.65rem); }
    .hero-time-label{ font-size:clamp(.55rem, 1.87vw, .68rem); margin-top:5.5px; }
    .hero-time-sub{ font-size:clamp(.53rem, 1.76vw, .64rem); margin-top:2.2px; }

    /* sections */
    .an-sec{ margin-top:clamp(22px, 5.94vw, 33px); }
    .sec-head{ gap:13px; padding:0 var(--pad) 13px; }
    .sec-icon{ width:clamp(31px, 8.36vw, 40px); height:clamp(31px, 8.36vw, 40px); border-radius:6.6px; font-size:clamp(.9rem, 2.86vw, 1.1rem); }
    .sec-title{ font-size:clamp(.9rem, 3.08vw, 1.056rem); }
    .sec-sub{ font-size:clamp(.62rem, 2.09vw, .75rem); margin-top:2.2px; }

    /* time spent */
    .time-row{ gap:clamp(11px, 3.3vw, 15px); padding:clamp(12px, 3.52vw, 16.5px) var(--pad); }
    .time-icon{ width:clamp(30px, 8.14vw, 37px); height:clamp(30px, 8.14vw, 37px); border-radius:6.6px; font-size:clamp(.86rem, 2.75vw, 1.056rem); }
    .time-top{ gap:8.8px; }
    .time-label{ font-size:clamp(.75rem, 2.53vw, .88rem); }
    .time-val{ font-size:clamp(.77rem, 2.64vw, .92rem); }
    .bar-track{ height:4.4px; margin-top:7.7px; }

    /* cards + charts */
    .an-card{ padding:clamp(15px, 4.4vw, 22px) clamp(12px, 3.74vw, 17.6px); }
    .chart-title{ font-size:.64rem; margin-bottom:17.6px; }
    .bar-chart{ height:clamp(79px, 24.2vw, 110px); }
    .bar{ min-height:3.3px; }
    .bar::after{ padding:2.2px 6.6px; font-size:.64rem; }
    .bar-labels{ margin-top:7.7px; }
    .bar-labels span{ font-size:.55rem; }

    /* heatmap */
    .heat-row-lbl{ font-size:.55rem; }
    .heat-cell::after{ padding:3.3px 7.7px; font-size:.64rem; }
    .heat-cols{ margin-top:7.7px; }
    .heat-cols span{ font-size:.55rem; }
    .heat-legend{ gap:6.6px; margin-top:13px; font-size:.55rem; }
    .heat-legend i{ width:13px; height:13px; }

    /* stat tiles */
    .stat-cell{ padding:clamp(13px, 3.96vw, 20px) var(--pad); }
    .stat-ico{ width:clamp(26px, 7.04vw, 33px); height:clamp(26px, 7.04vw, 33px); border-radius:6.6px; font-size:clamp(.81rem, 2.53vw, .99rem); margin-bottom:11px; }
    .stat-val{ font-size:clamp(1.155rem, 4.84vw, 1.54rem); }
    .stat-label{ font-size:clamp(.64rem, 2.2vw, .77rem); margin-top:5.5px; }
    .stat-delta{ height:18.7px; padding:0 6.6px; margin-top:7.7px; font-size:.55rem; }

    /* breakdown */
    .progress-list{ gap:16.5px; }
    .progress-head{ font-size:clamp(.68rem, 2.31vw, .81rem); margin-bottom:7.7px; }
    .progress-head span:first-child{ gap:6.6px; }
    .progress-track{ height:6.6px; }

    /* hashtags */
    .tag-cloud{ gap:6.6px; }
    .tag-pill{ gap:7.7px; height:clamp(29px, 7.92vw, 34px); padding:0 12px; font-size:clamp(.68rem, 2.31vw, .81rem); }
    .tag-pill .tag-count{ font-size:.6rem; padding:2.2px 6.6px; }

    /* insights */
    .ins-row{ gap:clamp(11px, 3.3vw, 15px); padding:clamp(12px, 3.52vw, 16.5px) var(--pad); }
    .ins-icon{ width:clamp(30px, 8.14vw, 37px); height:clamp(30px, 8.14vw, 37px); border-radius:6.6px; font-size:clamp(.86rem, 2.75vw, 1.056rem); }
    .ins-title{ font-size:clamp(.79rem, 2.75vw, .92rem); margin-bottom:3.3px; }
    .ins-text{ font-size:clamp(.68rem, 2.31vw, .81rem); }

    /* skeleton */
    .sk-topbar{ padding:0 17.6px 0 15px; }
    .sk-x{ width:22px; height:22px; }
    .sk-ttl{ width:121px; height:14.3px; }
    .sk-hero{ gap:15.4px; padding:clamp(15px, 4.4vw, 22px) var(--pad); }
    .sk-av{ width:clamp(53px, 15.4vw, 70px); height:clamp(53px, 15.4vw, 70px); }
    .sk-hl{ gap:8.8px; }
    .sk-h1{ height:14.3px; } .sk-h2{ height:10px; } .sk-h3{ height:22px; }
    .sk-big{ width:77px; height:28.6px; }
    .sk-sec{ padding:clamp(20px, 5.5vw, 31px) var(--pad) 0; }
    .sk-sec-h{ gap:13px; margin-bottom:15px; }
    .sk-ico{ width:clamp(31px, 8.36vw, 40px); height:clamp(31px, 8.36vw, 40px); border-radius:6.6px; }
    .sk-t{ gap:6.6px; } .sk-t1{ width:143px; height:13px; } .sk-t2{ width:187px; height:10px; }
    .sk-row{ gap:13px; padding:14px 0; }
    .sk-row .sk-ico{ width:clamp(30px, 8.14vw, 37px); height:clamp(30px, 8.14vw, 37px); }
    .sk-l{ gap:7.7px; } .sk-l1{ height:12px; } .sk-l2{ height:5.5px; }
    .sk-chart{ height:clamp(121px, 35.2vw, 165px); }
}

@media (max-width:490px){
    :root{ --topbar-h:53px; }
    .an-x{ width:33px; height:33px; }
    .an-title{ font-size:.92rem; }
}

@media (max-width:380px){
    :root{ --topbar-h:48px; }
    .an-title{ font-size:.88rem; }
    .hero-time{ gap:8.8px; }
    .hero-time-label, .hero-time-sub{ margin-top:0; }
}

/* ═══════════ LIGHT MODE — <html data-theme="light"> (dark mode is never touched) ═══════════
   Same switch + same palette as community.php and the other pages. */
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
    --muted-2:rgba(6,7,26,.4);
    --sub:rgba(6,7,26,.66);
    --red:#e62020;
    --red-1:#e62020;
}
/* the rank badge + icons get their colours inline from PHP (some are pale pinks that vanish on white),
   so in light mode they use the deeper red instead */
[data-theme="light"] .hero-badge{ color:#c81e1e !important; background:rgba(230,32,32,.1) !important; }
[data-theme="light"] .time-icon i,
[data-theme="light"] .stat-ico i{ color:var(--red) !important; }
[data-theme="light"] .stat-delta,
[data-theme="light"] .tag-pill .tag-count{ background:rgba(230,32,32,.1); }
</style>
<noscript><style>.rv{opacity:1 !important} #page-skeleton{display:none} .bar-fill,.progress-fill{width:var(--w) !important}</style></noscript>
</head>
<body>

<!-- ══ PREMIUM PAGE-LOAD SKELETON ══ -->
<div id="page-skeleton" aria-hidden="true">
    <div class="sk-lane">
        <div class="sk-topbar"><div class="sk sk-x"></div><div class="sk sk-ttl"></div></div>
        <div class="sk-hero">
            <div class="sk sk-av"></div>
            <div class="sk-hl"><div class="sk sk-h1"></div><div class="sk sk-h2"></div><div class="sk sk-h3"></div></div>
            <div class="sk sk-big"></div>
        </div>
        <div class="sk-sec">
            <div class="sk-sec-h"><div class="sk sk-ico"></div><div class="sk-t"><div class="sk sk-t1"></div><div class="sk sk-t2"></div></div></div>
            <?php for ($i = 0; $i < 4; $i++): ?>
            <div class="sk-row"><div class="sk sk-ico"></div><div class="sk-l"><div class="sk sk-l1"></div><div class="sk sk-l2"></div></div></div>
            <?php endfor; ?>
        </div>
        <div class="sk-sec">
            <div class="sk-sec-h"><div class="sk sk-ico"></div><div class="sk-t"><div class="sk sk-t1"></div><div class="sk sk-t2"></div></div></div>
            <div class="sk sk-chart"></div>
        </div>
    </div>
</div>

<div class="an-lane">

    <!-- ══ TOPBAR ══ -->
    <header class="an-topbar">
        <button type="button" class="an-x" onclick="anClose()" aria-label="Close">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
        <span class="an-title">Your Analytics</span>
    </header>

    <!-- ══ HERO ══ -->
    <section class="hero rv" style="--i:0;">
        <img class="hero-avatar" src="<?php echo htmlspecialchars($avatar); ?>" onerror="this.src='uploads/me.png'" alt="">
        <div class="hero-info">
            <div class="hero-name"><?php echo htmlspecialchars($display_name); ?></div>
            <div class="hero-sub">@<?php echo htmlspecialchars($user['username']); ?> · Member for <?php echo $days_on_app; ?> days</div>
            <div class="hero-badges">
                <span class="hero-badge" style="color:<?php echo $rank_color; ?>; background:<?php echo $rank_color; ?>22;">
                    <i class="ri-award-line"></i> <?php echo $rank; ?>
                </span>
                <span class="hero-badge"><i class="ri-calendar-line"></i> <?php echo $days_on_app; ?> days</span>
                <?php if ($streak > 1): ?>
                <span class="hero-badge"><i class="ri-fire-line"></i> <?php echo $streak; ?> day streak</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="hero-time">
            <div class="hero-time-val"><?php echo $time_hours > 0 ? $time_hours . 'h ' . $time_minutes . 'm' : $time_minutes . 'm'; ?></div>
            <div class="hero-time-label">Est. time on Dezzme</div>
            <div class="hero-time-sub">~<?php echo $avg_per_day; ?> min/day avg</div>
        </div>
    </section>

    <!-- ═══ TIME SPENT ═══ -->
    <section class="an-sec rv" style="--i:1;">
        <div class="sec-head">
            <div class="sec-icon"><i class="ri-time-line"></i></div>
            <div>
                <div class="sec-title">Time Spent</div>
                <div class="sec-sub">Estimated from your activity across Dezzme</div>
            </div>
        </div>
        <div class="time-list">
            <?php foreach ($time_items as $t):
                $pct = $time_seconds > 0 ? round($t[2] / $time_seconds * 100) : 0; ?>
            <div class="time-row">
                <div class="time-icon"><i class="<?php echo $t[0]; ?>" style="color:<?php echo $t[3]; ?>"></i></div>
                <div class="time-body">
                    <div class="time-top">
                        <span class="time-label"><?php echo $t[1]; ?></span>
                        <span class="time-val"><?php echo fmt_time($t[2]); ?></span>
                    </div>
                    <div class="bar-track"><div class="bar-fill" data-w="<?php echo $pct; ?>" style="--w:<?php echo $pct; ?>%; background:<?php echo $t[3]; ?>;"></div></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- ═══ 14-DAY ACTIVITY ═══ -->
    <section class="an-sec rv" style="--i:2;">
        <div class="sec-head">
            <div class="sec-icon"><i class="ri-bar-chart-2-line"></i></div>
            <div>
                <div class="sec-title">Activity — Last 14 Days</div>
                <div class="sec-sub">Likes + reposts + favourites + messages + uploads</div>
            </div>
        </div>
        <div class="sec-pad">
            <div class="an-card">
                <div class="chart-title">Daily interactions</div>
                <div class="bar-chart" id="barChart">
                    <?php foreach ($chart_values as $val): ?>
                    <div class="bar <?php echo $val === 0 ? 'zero' : ''; ?>"
                         data-val="<?php echo $val; ?>"
                         data-h="<?php echo round(($val / $max_val) * 100); ?>"
                         style="height:<?php echo round(($val / $max_val) * 100); ?>%"></div>
                    <?php endforeach; ?>
                </div>
                <div class="bar-labels">
                    <?php foreach ($chart_labels as $l): ?><span><?php echo $l; ?></span><?php endforeach; ?>
                </div>
            </div>
        </div>
    </section>

    <!-- ═══ HOURLY HEATMAP ═══ -->
    <section class="an-sec rv" style="--i:3;">
        <div class="sec-head">
            <div class="sec-icon"><i class="ri-grid-line"></i></div>
            <div>
                <div class="sec-title">Active Hours</div>
                <div class="sec-sub">When you're most active on Dezzme (all time)</div>
            </div>
        </div>
        <div class="sec-pad">
            <div class="an-card">
                <div class="chart-title">Hourly activity</div>
                <div class="heat">
                    <?php for ($row = 0; $row < 2; $row++): ?>
                    <span class="heat-row-lbl"><?php echo $row === 0 ? 'AM' : 'PM'; ?></span>
                    <?php for ($col = 0; $col < 12; $col++):
                        $hr  = $row * 12 + $col;
                        $cnt = $hourly[$hr];
                        $alpha = $cnt > 0 ? max(0.16, ($cnt / $max_hourly) * 0.92) : 0.06;
                        $label = date('g A', mktime($hr, 0, 0));
                        $is_peak = ($cnt > 0 && $cnt === $max_hourly);
                    ?>
                    <div class="heat-cell<?php echo $is_peak ? ' peak' : ''; ?>"
                         data-tip="<?php echo $label; ?> · <?php echo $cnt; ?>"
                         style="background:rgba(255,45,45,<?php echo round($alpha, 2); ?>)"></div>
                    <?php endfor; endfor; ?>
                </div>
                <div class="heat-cols">
                    <span>AM</span>
                    <?php foreach ([12,1,2,3,4,5,6,7,8,9,10,11] as $h): ?><span><?php echo $h; ?></span><?php endforeach; ?>
                </div>
                <div class="heat-legend">
                    Less
                    <i style="background:rgba(255,45,45,.08)"></i>
                    <i style="background:rgba(255,45,45,.3)"></i>
                    <i style="background:rgba(255,45,45,.6)"></i>
                    <i style="background:rgba(255,45,45,.92)"></i>
                    More
                </div>
            </div>
        </div>
    </section>

    <!-- ═══ OVERVIEW STATS ═══ -->
    <section class="an-sec rv" style="--i:4;">
        <div class="sec-head">
            <div class="sec-icon"><i class="ri-dashboard-line"></i></div>
            <div>
                <div class="sec-title">Your Numbers</div>
                <div class="sec-sub">Everything you've done on Dezzme</div>
            </div>
        </div>
        <div class="stat-grid">
            <?php foreach ($stats as $s): ?>
            <div class="stat-cell">
                <div class="stat-ico"><i class="<?php echo $s[0]; ?>" style="color:<?php echo $s[1]; ?>"></i></div>
                <div class="stat-val"><?php echo number_format($s[2], is_float($s[2]) ? 1 : 0); ?></div>
                <div class="stat-label"><?php echo $s[3]; ?></div>
                <div class="stat-delta"><?php echo $s[4]; ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- ═══ CONTENT BREAKDOWN ═══ -->
    <?php if ($total_memes > 0): ?>
    <section class="an-sec rv" style="--i:5;">
        <div class="sec-head">
            <div class="sec-icon"><i class="ri-pie-chart-line"></i></div>
            <div>
                <div class="sec-title">Content Breakdown</div>
                <div class="sec-sub">What you post most</div>
            </div>
        </div>
        <div class="sec-pad">
            <div class="an-card">
                <div class="progress-list">
                    <div>
                        <div class="progress-head"><span><i class="ri-image-line"></i> Images</span><span><?php echo $img_count; ?> · <?php echo $img_pct; ?>%</span></div>
                        <div class="progress-track"><div class="progress-fill" data-w="<?php echo $img_pct; ?>" style="--w:<?php echo $img_pct; ?>%; background:linear-gradient(90deg,var(--red-3),var(--red));"></div></div>
                    </div>
                    <div>
                        <div class="progress-head"><span><i class="ri-video-line"></i> Videos</span><span><?php echo $vid_count; ?> · <?php echo $vid_pct; ?>%</span></div>
                        <div class="progress-track"><div class="progress-fill" data-w="<?php echo $vid_pct; ?>" style="--w:<?php echo $vid_pct; ?>%; background:linear-gradient(90deg,var(--red-2),var(--red-1));"></div></div>
                    </div>
                    <div>
                        <div class="progress-head"><span><i class="ri-file-text-line"></i> Text posts</span><span><?php echo $txt_count; ?> · <?php echo $txt_pct; ?>%</span></div>
                        <div class="progress-track"><div class="progress-fill" data-w="<?php echo $txt_pct; ?>" style="--w:<?php echo $txt_pct; ?>%; background:linear-gradient(90deg,var(--red-1),var(--red-4));"></div></div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- ═══ TOP HASHTAGS ═══ -->
    <?php if (!empty($top_tags)): ?>
    <section class="an-sec rv" style="--i:6;">
        <div class="sec-head">
            <div class="sec-icon"><i class="ri-hashtag"></i></div>
            <div>
                <div class="sec-title">Your Top Hashtags</div>
                <div class="sec-sub">Tags you use most in your memes</div>
            </div>
        </div>
        <div class="sec-pad">
            <div class="tag-cloud">
                <?php foreach ($top_tags as $tag => $count): ?>
                <div class="tag-pill"><span class="hash">#</span><?php echo htmlspecialchars($tag); ?><span class="tag-count"><?php echo $count; ?></span></div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- ═══ INSIGHTS ═══ -->
    <section class="an-sec rv" style="--i:7;">
        <div class="sec-head">
            <div class="sec-icon"><i class="ri-lightbulb-line"></i></div>
            <div>
                <div class="sec-title">Insights</div>
                <div class="sec-sub">What your data is telling you</div>
            </div>
        </div>
        <div class="ins-list">
            <?php foreach ($insights as $in): ?>
            <div class="ins-row">
                <div class="ins-icon"><i class="<?php echo $in[0]; ?>"></i></div>
                <div>
                    <div class="ins-title"><?php echo $in[1]; ?></div>
                    <div class="ins-text"><?php echo $in[2]; ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

</div><!-- /.an-lane -->

<script>
// ── X: go back, or to Settings if there is no history ──
function anClose(){
    if (document.referrer && document.referrer.indexOf(window.location.host) !== -1) history.back();
    else location.href = 'setting.php';
}

// ── bars / progress fill in AFTER the content has revealed ──
function animateFills(){
    document.querySelectorAll('.bar').forEach(function(bar, i){
        var h = bar.getAttribute('data-h') + '%';
        bar.style.height = '0';
        setTimeout(function(){ bar.style.height = h; }, 120 + i * 35);
    });
    document.querySelectorAll('.bar-fill, .progress-fill').forEach(function(f, i){
        var w = f.getAttribute('data-w') + '%';
        f.style.width = '0';
        setTimeout(function(){ f.style.width = w; }, 260 + i * 60);
    });
}

// ── PAGE-LOAD SKELETON → staggered content reveal ──
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
            animateFills();
        }, wait);
    }
    if (document.readyState === 'complete') reveal();
    else window.addEventListener('load', reveal);
    setTimeout(reveal, 1800);   // safety net
})();

// ── tap a bar / heat cell to see its value on touch screens ──
document.addEventListener('click', function(e){
    var t = e.target.closest ? e.target.closest('.bar, .heat-cell') : null;
    document.querySelectorAll('.bar.on, .heat-cell.on').forEach(function(el){ if (el !== t) el.classList.remove('on'); });
    if (t) t.classList.toggle('on');
});
</script>
</body>
</html>