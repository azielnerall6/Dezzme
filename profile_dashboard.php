<?php
session_start();
include 'config.php';
/* verified flower badge (dz_badge_sprite() + dz_badge('f')) — same file the other pages use */
require_once __DIR__ . '/verified_badge.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$uid = (int)$_SESSION['user_id'];

/* ── safe query helpers: a missing table/column never crashes the page ── */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function dq($conn, $sql){ try { $r = $conn->query($sql); return ($r instanceof mysqli_result) ? $r : null; } catch (Throwable $e) { return null; } }
function drows($conn, $sql){ $r = dq($conn, $sql); $o = []; if ($r) while ($x = $r->fetch_assoc()) $o[] = $x; return $o; }
function dnum($conn, $sql){ $r = dq($conn, $sql); if (!$r) return 0; $x = $r->fetch_row(); return (int)($x[0] ?? 0); }
function dfmt($n){
    $n = (float)$n;
    if ($n >= 1000000) return round($n / 1000000, 1) . 'M';
    if ($n >= 1000)    return round($n / 1000, 1) . 'K';
    return (string)(floor($n) == $n ? (int)$n : round($n, 1));
}
function ddelta($cur, $prev){
    if ($prev <= 0) return $cur > 0 ? ['New', 'up'] : ['—', 'flat'];
    $p = round(($cur - $prev) / $prev * 100);
    if ($p == 0) return ['0%', 'flat'];
    return [($p > 0 ? '+' : '') . $p . '%', $p > 0 ? 'up' : 'down'];
}
function dunion($uid, $sel, $cond){
    $p = [];
    foreach (['likes', 'reposts', 'favorites'] as $t)
        $p[] = "SELECT $sel FROM `$t` x JOIN memes m ON m.id = x.meme_id WHERE m.uploaded_by = $uid $cond";
    return implode(' UNION ALL ', $p);
}
function dseries($conn, $sql, $R){
    $m = [];
    foreach (drows($conn, $sql) as $r) $m[$r['d']] = (int)$r['c'];
    $v = []; $l = [];
    for ($i = $R - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $v[] = $m[$d] ?? 0;
        $l[] = date('M j', strtotime($d));
    }
    return [$l, $v];
}
function dago($t){
    $ts = strtotime((string)$t); if (!$ts) return '';
    $d = time() - $ts;
    if ($d < 60) return 'just now';
    if ($d < 3600) return floor($d / 60) . 'm ago';
    if ($d < 86400) return floor($d / 3600) . 'h ago';
    if ($d < 2592000) return floor($d / 86400) . 'd ago';
    return date('M j, Y', $ts);
}
function dthumb($p){
    $t = strtolower((string)$p['type']); $f = (string)$p['file_name'];
    if ($t === 'text' || $f === '' || stripos($f, 'text.jpg') !== false)
        return '<span class="th txt"><span>' . h(mb_substr((string)($p['caption'] ?? ''), 0, 40)) . '</span></span>';
    if ($t === 'video')
        return '<span class="th"><video src="uploads/' . h($f) . '#t=0.1" muted playsinline preload="metadata"></video></span>';
    return '<span class="th"><img src="uploads/' . h($f) . '" alt="" loading="lazy" onerror="this.src=\'uploads/Text.jpg\'"></span>';
}
/* milestone ladder → [level, previous, next] */
function dladder($v, $lad){
    $lvl = 0; $prev = 0; $next = end($lad);
    foreach ($lad as $i => $x) { if ($v >= $x) { $lvl = $i + 1; $prev = $x; } else { $next = $x; break; } }
    if ($v >= end($lad)) $next = end($lad);
    return [$lvl, $prev, $next];
}

/* ── range (7 / 30 / 90 days) ── */
$R = (int)($_GET['range'] ?? 30);
if (!in_array($R, [7, 30, 90], true)) $R = 30;
$cur = "DATE_SUB(NOW(), INTERVAL $R DAY)";
$prv = "DATE_SUB(NOW(), INTERVAL " . ($R * 2) . " DAY)";

/* ── user ── */
$user = drows($conn, "SELECT u.username, u.nickname, u.profile_pic, u.is_verified, us.Bio AS bio FROM users u LEFT JOIN user_settings us ON us.user_id = u.id WHERE u.id = $uid LIMIT 1");
if (!$user) $user = drows($conn, "SELECT username, nickname, profile_pic, is_verified FROM users WHERE id = $uid LIMIT 1");
if (!$user) $user = drows($conn, "SELECT username, nickname, profile_pic FROM users WHERE id = $uid LIMIT 1");
$user = $user[0] ?? ['username' => 'user', 'nickname' => '', 'profile_pic' => '', 'bio' => '', 'is_verified' => 0];
$display_name = !empty($user['nickname']) ? $user['nickname'] : $user['username'];
$avatar = !empty($user['profile_pic']) ? $user['profile_pic'] : 'uploads/me.png';
$is_verified = !empty($user['is_verified']);

/* ── reactions received ── */
function dint($conn, $uid, $tbl, $from, $to = null){
    $w = "m.uploaded_by = $uid AND x.created_at >= $from" . ($to ? " AND x.created_at < $to" : '');
    return dnum($conn, "SELECT COUNT(*) FROM `$tbl` x JOIN memes m ON m.id = x.meme_id WHERE $w");
}
$likes = dint($conn, $uid, 'likes', $cur);     $likes_p = dint($conn, $uid, 'likes', $prv, $cur);
$reps  = dint($conn, $uid, 'reposts', $cur);   $reps_p  = dint($conn, $uid, 'reposts', $prv, $cur);
$favs  = dint($conn, $uid, 'favorites', $cur); $favs_p  = dint($conn, $uid, 'favorites', $prv, $cur);
$inter = $likes + $reps + $favs; $inter_p = $likes_p + $reps_p + $favs_p;
$all_likes = dnum($conn, "SELECT COUNT(*) FROM likes x JOIN memes m ON m.id = x.meme_id WHERE m.uploaded_by = $uid");

/* ── visits ── */
$visits   = dnum($conn, "SELECT COUNT(*) FROM profile_views WHERE profile_id = $uid AND viewed_at >= $cur");
$visits_p = dnum($conn, "SELECT COUNT(*) FROM profile_views WHERE profile_id = $uid AND viewed_at >= $prv AND viewed_at < $cur");
$uniq     = dnum($conn, "SELECT COUNT(DISTINCT viewer_id) FROM profile_views WHERE profile_id = $uid AND viewed_at >= $cur");
$uniq_p   = dnum($conn, "SELECT COUNT(DISTINCT viewer_id) FROM profile_views WHERE profile_id = $uid AND viewed_at >= $prv AND viewed_at < $cur");

/* ── trackers ── */
$total_trackers = dnum($conn, "SELECT COUNT(*) FROM followers WHERE following_id = $uid");
$total_tracking = dnum($conn, "SELECT COUNT(*) FROM followers WHERE follower_id = $uid");
$rt = dq($conn, "SELECT COUNT(*) FROM followers WHERE following_id = $uid AND created_at >= $cur");
$new_tr = $rt ? (int)$rt->fetch_row()[0] : null;
$rt = dq($conn, "SELECT COUNT(*) FROM followers WHERE following_id = $uid AND created_at >= $prv AND created_at < $cur");
$new_tr_p = $rt ? (int)$rt->fetch_row()[0] : 0;

/* ── posts ── */
$posts = drows($conn, "SELECT m.id, m.file_name, m.type, m.caption, m.created_at, m.is_ad,
    (SELECT COUNT(*) FROM likes WHERE meme_id = m.id) AS lk,
    (SELECT COUNT(*) FROM reposts WHERE meme_id = m.id) AS rp,
    (SELECT COUNT(*) FROM favorites WHERE meme_id = m.id) AS fv
    FROM memes m WHERE m.uploaded_by = $uid ORDER BY m.created_at DESC LIMIT 200");
$total_posts = dnum($conn, "SELECT COUNT(*) FROM memes WHERE uploaded_by = $uid");
$posts_per   = dnum($conn, "SELECT COUNT(*) FROM memes WHERE uploaded_by = $uid AND created_at >= $cur");
$posts_per_p = dnum($conn, "SELECT COUNT(*) FROM memes WHERE uploaded_by = $uid AND created_at >= $prv AND created_at < $cur");
$types = []; $tags = []; $jsposts = []; $no_tag = 0;
foreach ($posts as $i => $p) {
    $posts[$i]['score'] = (int)$p['lk'] + (int)$p['rp'] * 2 + (int)$p['fv'] * 2;
    $posts[$i]['eng']   = (int)$p['lk'] + (int)$p['rp'] + (int)$p['fv'];
    $t = strtolower($p['type']) ?: 'image';
    if (!isset($types[$t])) $types[$t] = ['n' => 0, 'eng' => 0];
    $types[$t]['n']++; $types[$t]['eng'] += $posts[$i]['eng'];
    if (preg_match_all('/#(\w+)/u', (string)$p['caption'], $mm)) {
        foreach (array_unique(array_map('mb_strtolower', $mm[1])) as $tg) {
            if (!isset($tags[$tg])) $tags[$tg] = ['n' => 0, 'eng' => 0];
            $tags[$tg]['n']++; $tags[$tg]['eng'] += $posts[$i]['eng'];
        }
    } else $no_tag++;
    $f = (string)$p['file_name'];
    $k = ($t === 'text' || $f === '' || stripos($f, 'text.jpg') !== false) ? 't' : ($t === 'video' ? 'v' : 'i');
    $jsposts[] = ['id' => (int)$p['id'], 'k' => $k, 'src' => 'uploads/' . $f, 'cap' => mb_substr((string)$p['caption'], 0, 140),
        'lk' => (int)$p['lk'], 'rp' => (int)$p['rp'], 'fv' => (int)$p['fv'], 'sc' => $posts[$i]['score'],
        'ts' => (int)strtotime($p['created_at']), 'ago' => dago($p['created_at']), 'ad' => !empty($p['is_ad']) ? 1 : 0];
}
$top = $posts;
usort($top, function($a, $b){ return $b['score'] <=> $a['score']; });
$top = array_values(array_filter(array_slice($top, 0, 5), function($p){ return $p['score'] > 0; }));
$per_post = $total_posts > 0 ? round($inter / $total_posts, 1) : 0;
$eng_rate = $total_trackers > 0 ? round($per_post / $total_trackers * 100, 1) : 0;
$tmeta = ['image' => ['ri-image-line', 'Images'], 'video' => ['ri-video-line', 'Videos'], 'text' => ['ri-file-text-line', 'Text posts'], 'gif' => ['ri-file-gif-line', 'GIFs']];
$best_type = ''; $best_avg = 0;
foreach ($types as $t => $d) {
    $avg = $d['n'] ? $d['eng'] / $d['n'] : 0;
    $types[$t]['avg'] = round($avg, 1);
    if ($avg > $best_avg) { $best_avg = $avg; $best_type = $t; }
}
$max_avg = 1; foreach ($types as $d) $max_avg = max($max_avg, $d['avg']);
foreach ($tags as $k => $d) $tags[$k]['avg'] = round($d['eng'] / $d['n'], 1);
uasort($tags, function($a, $b){ return $b['avg'] <=> $a['avg'] ?: $b['n'] <=> $a['n']; });
$top_tags = array_slice($tags, 0, 8, true);

/* ── chart series ── */
list($lab, $s_int) = dseries($conn, "SELECT d, COUNT(*) c FROM (" . dunion($uid, 'DATE(x.created_at) d', "AND x.created_at >= $cur") . ") a GROUP BY d", $R);
list(, $s_vis) = dseries($conn, "SELECT DATE(viewed_at) d, COUNT(*) c FROM profile_views WHERE profile_id = $uid AND viewed_at >= $cur GROUP BY d", $R);
list(, $s_trk) = dseries($conn, "SELECT DATE(created_at) d, COUNT(*) c FROM followers WHERE following_id = $uid AND created_at >= $cur GROUP BY d", $R);
$chart = ['int' => ['l' => $lab, 'v' => $s_int], 'vis' => ['l' => $lab, 'v' => $s_vis]];
if ($new_tr !== null) $chart['trk'] = ['l' => $lab, 'v' => $s_trk];

/* ── best time ── */
$hours = array_fill(0, 24, 0); $days = array_fill(1, 7, 0);
foreach (drows($conn, "SELECT HOUR(t) h, DAYOFWEEK(t) w, COUNT(*) c FROM (" . dunion($uid, 'x.created_at t', '') . ") a GROUP BY h, w") as $r) {
    $hours[(int)$r['h']] += (int)$r['c']; $days[(int)$r['w']] += (int)$r['c'];
}
$max_h = max(1, max($hours)); $max_d = max(1, max($days));
$peak_h = array_search(max($hours), $hours); $peak_d = array_search(max($days), $days);
$has_time = max($hours) > 0;
$dn = [1 => 'Sun', 2 => 'Mon', 3 => 'Tue', 4 => 'Wed', 5 => 'Thu', 6 => 'Fri', 7 => 'Sat'];
$dfull = [1 => 'Sundays', 2 => 'Mondays', 3 => 'Tuesdays', 4 => 'Wednesdays', 5 => 'Thursdays', 6 => 'Fridays', 7 => 'Saturdays'];

/* ── posting calendar (13 weeks) + streak ── */
$w = (int)date('w');
$cal = [];
foreach (drows($conn, "SELECT DATE(created_at) d, COUNT(*) c FROM memes WHERE uploaded_by = $uid AND created_at >= DATE_SUB(CURDATE(), INTERVAL " . (84 + $w) . " DAY) GROUP BY d") as $r) $cal[$r['d']] = (int)$r['c'];
$cal_start = strtotime(date('Y-m-d') . ' -' . (84 + $w) . ' days');
$today = date('Y-m-d');
$streak = 0;
for ($i = 0; $i < 91; $i++) {
    $d = date('Y-m-d', strtotime("-$i days"));
    if (!empty($cal[$d])) $streak++; elseif ($i === 0) continue; else break;
}
$active_days = count(array_filter($cal, function($c){ return $c > 0; }));
$posts_84 = array_sum($cal);

/* ── audience ── */
$fans = drows($conn, "SELECT u.id, u.username, u.profile_pic, u.is_verified, COUNT(*) c FROM (" . dunion($uid, 'x.user_id uid', '') . ") a
    JOIN users u ON u.id = a.uid WHERE a.uid <> $uid GROUP BY u.id, u.username, u.profile_pic, u.is_verified ORDER BY c DESC LIMIT 8");
$new_list = null;
$rr = dq($conn, "SELECT u.id, u.username, u.profile_pic, u.is_verified, f.created_at AS at FROM followers f JOIN users u ON u.id = f.follower_id WHERE f.following_id = $uid ORDER BY f.id DESC LIMIT 8");
if ($rr) { $new_list = []; while ($x = $rr->fetch_assoc()) $new_list[] = $x; }
if ($new_list === null) $new_list = drows($conn, "SELECT u.id, u.username, u.profile_pic, u.is_verified, NULL AS at FROM followers f JOIN users u ON u.id = f.follower_id WHERE f.following_id = $uid ORDER BY f.id DESC LIMIT 8");
$visitors = drows($conn, "SELECT u.id, u.username, u.profile_pic, u.is_verified, MAX(v.viewed_at) AS last_at
    FROM profile_views v JOIN users u ON u.id = v.viewer_id
    WHERE v.profile_id = $uid AND v.viewed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY u.id, u.username, u.profile_pic, u.is_verified ORDER BY last_at DESC LIMIT 8");
$requests = drows($conn, "SELECT u.id, u.username, u.profile_pic, u.is_verified, r.created_at AS at FROM track_requests r JOIN users u ON u.id = r.requester_id
    WHERE r.target_id = $uid AND r.status = 'pending' ORDER BY r.created_at DESC LIMIT 4");
$req_pending  = dnum($conn, "SELECT COUNT(*) FROM track_requests WHERE target_id = $uid AND status = 'pending'");
$tags_pending = dnum($conn, "SELECT COUNT(*) FROM meme_tags WHERE tagged_user_id = $uid AND status = 'pending'");
$unread       = dnum($conn, "SELECT COUNT(*) FROM messages WHERE receiver_id = $uid AND seen = 0");
$ads = drows($conn, "SELECT a.status, a.duration_days, a.price, m.id, m.caption FROM meme_ads a JOIN memes m ON m.id = a.meme_id WHERE m.uploaded_by = $uid ORDER BY a.meme_id DESC LIMIT 5");

/* ── checklist + milestones ── */
$n_links = dnum($conn, "SELECT COUNT(*) FROM links WHERE user_id = $uid");
$n_hl    = dnum($conn, "SELECT COUNT(*) FROM highlights WHERE user_id = $uid");
$n_pods  = dnum($conn, "SELECT COUNT(*) FROM group_members WHERE user_id = $uid");
$has_pic = !empty($user['profile_pic']) && stripos($user['profile_pic'], 'me.png') === false && stripos($user['profile_pic'], 'mee.jpeg') === false;
function dcol($conn, $sql){ $r = dq($conn, $sql); if (!$r) return ''; $x = $r->fetch_row(); return trim((string)($x[0] ?? '')); }
$f_nick  = dcol($conn, "SELECT nickname FROM users WHERE id = $uid LIMIT 1");
$f_bio   = dcol($conn, "SELECT bio FROM users WHERE id = $uid LIMIT 1") ?: dcol($conn, "SELECT Bio FROM user_settings WHERE user_id = $uid LIMIT 1") ?: trim((string)($user['bio'] ?? ''));
$f_pron  = dcol($conn, "SELECT pronouns FROM users WHERE id = $uid LIMIT 1") ?: dcol($conn, "SELECT pronouns FROM user_settings WHERE user_id = $uid LIMIT 1");
$f_ptype = dcol($conn, "SELECT profile_type FROM user_settings WHERE user_id = $uid LIMIT 1");
$f_utype = dcol($conn, "SELECT user_type FROM users WHERE id = $uid LIMIT 1") ?: dcol($conn, "SELECT user_type FROM user_settings WHERE user_id = $uid LIMIT 1");
$f_vibe  = dcol($conn, "SELECT activity_vibe FROM user_settings WHERE user_id = $uid LIMIT 1");
$check = [
    ['Add a profile picture',      $has_pic,                                  'profile.php?edit=picture',     'ri-image-circle-line'],
    ['Set a nickname',             $f_nick !== '' && strcasecmp($f_nick, (string)$user['username']) !== 0, 'profile.php?edit=nickname', 'ri-text'],
    ['Write a bio',                $f_bio !== '',                             'profile.php?edit=bio',         'ri-edit-2-line'],
    ['Add your pronouns',          $f_pron !== '',                            'profile.php?edit=pronouns',    'ri-hearts-line'],
    ['Choose a profile type',      $f_ptype !== '',                           'profile.php?edit=profiletype', 'ri-price-tag-3-line'],
    ['Pick a user type',           $f_utype !== '',                           'profile.php?edit=usertype',    'ri-user-settings-line'],
    ['Set your activity vibe',     $f_vibe !== '',                            'profile.php?edit=vibe',        'ri-time-line'],
    ['Add a link',                 $n_links > 0,                              'setting.php',                  'ri-link-m'],
    ['Publish your first post',    $total_posts > 0,                          'upload.php',                   'ri-upload-2-line'],
    ['Reach 10 posts',             $total_posts >= 10,                        'upload.php',                   'ri-image-add-line'],
    ['Create a highlight',         $n_hl > 0,                                 'upload.php?mode=highlights',   'ri-star-smile-line'],
    ['Join a pod',                 $n_pods > 0,                               'community.php',                'ri-team-line'],
];
$check_done = count(array_filter($check, function($c){ return $c[1]; }));
$check_pct = round($check_done / count($check) * 100);
$mile = [
    ['ri-image-2-line',    'Posts',      $total_posts,    [1, 10, 25, 50, 100, 250, 500]],
    ['ri-heart-3-line',    'Likes',      $all_likes,      [10, 50, 100, 500, 1000, 5000, 10000]],
    ['ri-user-follow-line','Trackers',   $total_trackers, [10, 50, 100, 500, 1000, 5000, 10000]],
];

/* ── insights / next steps ── */
$ins = [];
if ($has_time) $ins[] = ['ri-time-line', 'Best time to post', 'Your posts get the most reactions around <strong>' . date('g A', mktime((int)$peak_h, 0, 0)) . '</strong>, especially on <strong>' . $dfull[$peak_d] . '</strong>.'];
if ($best_type && count($types) > 1) $ins[] = ['ri-trophy-line', 'Your strongest format', ucfirst($tmeta[$best_type][1] ?? $best_type) . ' earn the most: <strong>' . $types[$best_type]['avg'] . '</strong> reactions per post on average.'];
if ($inter_p > 0 && $inter > $inter_p) $ins[] = ['ri-rocket-2-line', 'You are growing', 'Reactions are up <strong>' . round(($inter - $inter_p) / $inter_p * 100) . '%</strong> compared to the previous ' . $R . ' days.'];
elseif ($inter_p > 0 && $inter < $inter_p) $ins[] = ['ri-line-chart-line', 'Reactions dipped', 'You got fewer reactions than the previous ' . $R . ' days. Posting consistently and using hashtags helps.'];
if ($visits > 0 && $new_tr !== null) $ins[] = ['ri-user-follow-line', 'Visit to tracker', 'Out of <strong>' . dfmt($uniq) . '</strong> people who visited, <strong>' . dfmt($new_tr) . '</strong> started tracking you.'];
if ($total_posts > 0 && $posts_per === 0) $ins[] = ['ri-calendar-event-line', 'Time to post', "You haven't posted in the last $R days. Fresh posts keep you in people's feeds."];
if ($no_tag > 0 && $total_posts > 0) $ins[] = ['ri-hashtag', 'Add hashtags', '<strong>' . $no_tag . '</strong> of your posts have no hashtag, so fewer people can find them.'];
foreach ($check as $c) if (!$c[1]) { $ins[] = [$c[3], 'Next step', '<a href="' . h($c[2]) . '"><strong>' . h($c[0]) . '</strong></a> to complete your profile.']; break; }
if (!$ins) $ins[] = ['ri-sparkling-2-line', 'Getting started', 'Post a few memes with hashtags. Insights appear here as people react to your content.'];

/* ── tiles ── */
$tiles = [
    ['ri-eye-line',         dfmt($visits),         'Profile visits',   ddelta($visits, $visits_p)],
    ['ri-group-line',       dfmt($uniq),           'Unique visitors',  ddelta($uniq, $uniq_p)],
    ['ri-heart-3-line',     dfmt($likes),          'Likes received',   ddelta($likes, $likes_p)],
    ['ri-repeat-2-line',    dfmt($reps),           'Reposts received', ddelta($reps, $reps_p)],
    ['ri-bookmark-line',    dfmt($favs),           'Saves received',   ddelta($favs, $favs_p)],
    ['ri-user-add-line',    dfmt($new_tr ?? $total_trackers), $new_tr !== null ? 'New trackers' : 'Trackers', $new_tr !== null ? ddelta($new_tr, $new_tr_p) : ['Total', 'flat']],
    ['ri-image-add-line',   dfmt($posts_per),      'Posts published',  ddelta($posts_per, $posts_per_p)],
    ['ri-star-line',        dfmt($per_post),       'Avg per post',     ['All posts', 'flat']],
    ['ri-pulse-line',       $eng_rate . '%',       'Engagement rate',  ['Per tracker', 'flat']],
    ['ri-user-star-line',   dfmt($total_tracking), 'Tracking',         ['You track', 'flat']],
];
$d_int = ddelta($inter, $inter_p);
$d_vis = ddelta($visits, $visits_p);
$d_trk = $new_tr !== null ? ddelta($new_tr, $new_tr_p) : ['Total', 'flat'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — Dezzme</title>
<script>try{if(localStorage.getItem('dz_theme')==='light')document.documentElement.setAttribute('data-theme','light');}catch(e){}</script>
<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; font-family:'Poppins', sans-serif; }
button, a, input { outline:none; -webkit-tap-highlight-color:transparent; }
button, a { cursor:pointer; }
a { text-decoration:none; color:inherit; }
[class^="ri-"], [class*=" ri-"], [class^="ri-"]::before, [class*=" ri-"]::before {
    font-family:'remixicon' !important; font-style:normal; font-weight:normal; line-height:1; speak:none; text-transform:none; -webkit-font-smoothing:antialiased;
}
:root{
    --red:#FF2D2D; --red-1:#ff6b6b; --red-2:#e02424; --red-3:#b91c1c; --red-4:#ff9a9a;
    --bg:linear-gradient(-45deg,#010218,#070924,#070924);
    --surface:rgba(var(--fg),0.05); --line:rgba(var(--fg),0.09); --line2:rgba(var(--fg),0.14);
    --muted:rgba(var(--fg),0.45); --sub:rgba(var(--fg),0.62);
    --max-w:580px; --topbar-h:54px; --pad:clamp(9px,3vw,16px); --r:6px; --btn-h:clamp(25px,7.2vw,30px);
}
@media (max-width:490px){ :root{ --topbar-h:48px; } }
@media (max-width:380px){ :root{ --topbar-h:44px; } }
html{ scroll-behavior:smooth; }
@media (max-width:768px){
    html, body, *{ scrollbar-width:none !important; -ms-overflow-style:none !important; }
    *::-webkit-scrollbar{ display:none !important; width:0 !important; height:0 !important; }
}
body{ background:var(--bg); background-attachment:fixed; color:var(--ui-text,#fff); min-height:100vh; display:flex; justify-content:center; overflow-x:hidden; -webkit-font-smoothing:antialiased; }
.an-lane{ width:100%; max-width:var(--max-w); min-height:100vh; display:flex; flex-direction:column; padding-bottom:60px; }
@media (min-width:801px){ .an-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); } }
@media (max-width:800px){ .an-lane{ max-width:100%; } }

.an-topbar{ position:sticky; top:0; z-index:500; height:var(--topbar-h); display:flex; align-items:center; gap:2px; padding:0 10px; background:var(--solid,#070924); }
.an-x{ width:34px; height:34px; background:none; border:none; color:var(--ui-text,#fff); display:flex; align-items:center; justify-content:center; opacity:.9; transition:opacity .2s,color .2s; padding:0; flex-shrink:0; }
.an-x:hover{ opacity:1; color:var(--red); }
.an-x svg{ width:22px; height:22px; display:block; }
.an-title{ flex:1; min-width:0; font-size:.92rem; font-weight:700; letter-spacing:-.1px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; text-align:right; margin:0 6px 0 10px; }
@media (max-width:490px){ .an-x{ width:30px; height:30px; } .an-title{ font-size:.84rem; } }

.rv{ opacity:0; }
body.ready .rv{ animation:anIn .55s cubic-bezier(.22,1,.36,1) both; animation-delay:calc(var(--i,0) * 55ms); }
@keyframes anIn{ from{ opacity:0; transform:translateY(12px); } to{ opacity:1; transform:none; } }
@media (prefers-reduced-motion:reduce){ body.ready .rv{ animation:none; opacity:1; } }

/* hero */
.hero{ padding:clamp(14px,4vw,20px) var(--pad) clamp(10px,3vw,14px); border-top:1px solid var(--line); display:flex; align-items:center; gap:clamp(11px,3.4vw,16px); }
.hero-avatar{ width:clamp(48px,14vw,64px); height:clamp(48px,14vw,64px); border-radius:50%; object-fit:cover; flex-shrink:0; border:2.4px solid var(--red); box-shadow:0 4px 18px rgba(255,45,45,.25); }
.hero-info{ flex:1; min-width:0; }
/* name + verified badge on one line; a long name is cut with "…" but the badge never is */
.hero-name{ font-size:clamp(.86rem,3.2vw,1.04rem); font-weight:700; display:flex; align-items:center; gap:.3em; min-width:0; }
.hero-name .hn-nm{ min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
/* verified badge: filled, 1em = follows the name size, RED (same colour as the avatar border) in dark AND light mode */
.hero-name .vbadge{ width:1em; height:1em; flex-shrink:0; vertical-align:baseline; color:var(--red) !important; fill:currentColor; }
.hero-sub{ font-size:clamp(.58rem,2vw,.7rem); color:var(--muted); margin-top:2px; }
.hero-badges{ display:flex; gap:6px; flex-wrap:wrap; margin-top:8px; }
.hero-badge{ display:inline-flex; align-items:center; gap:5px; height:clamp(20px,5.6vw,23px); padding:0 9px; border-radius:4px; font-size:clamp(.52rem,1.8vw,.62rem); font-weight:700; white-space:nowrap; background:rgba(255,45,45,.12); color:var(--red); }
.range{ display:flex; gap:8px; padding:0 var(--pad) 14px; }
.pill{ display:inline-flex; align-items:center; justify-content:center; gap:5px; height:var(--btn-h); padding:0 16px; border:none; border-radius:4px; background:rgba(var(--fg),.075); color:var(--ui-text,#fff); font-size:.72rem; font-weight:600; transition:background .2s,transform .15s; white-space:nowrap; flex-shrink:0; }
.pill:hover{ background:rgba(var(--fg),.15); }
.pill:active{ transform:scale(.98); }
.pill.on{ color:var(--red); font-weight:800; }
.pill i{ font-size:13px; }
@media (max-width:490px){ .pill{ font-size:.64rem; padding:0 13px; } }

/* main tabs (sticky under topbar) */
.mtabs{ position:sticky; top:calc(var(--topbar-h) - 1px); z-index:400; display:flex; background:var(--solid,#070924); border-top:1px solid var(--line); border-bottom:1px solid var(--line); }
.mtab{ flex:1; position:relative; height:42px; background:none; border:none; color:rgba(var(--fg),.5); font-size:.74rem; font-weight:600; transition:color .2s; display:flex; align-items:center; justify-content:center; gap:6px; }
.mtab i{ font-size:15px; }
.mtab:hover{ color:rgba(var(--fg),.85); }
.mtab.on{ color:var(--ui-text,#fff); }
.mtab.on::after{ content:''; position:absolute; left:18%; right:18%; bottom:0; height:2px; border-radius:2px 2px 0 0; background:var(--ui-text,#fff); }
.mtab .dot{ position:absolute; top:9px; right:calc(50% - 30px); width:7px; height:7px; border-radius:50%; background:var(--red); }
@media (max-width:490px){ .mtab{ font-size:.64rem; gap:4px; height:38px; } .mtab i{ font-size:14px; } }
@media (max-width:360px){ .mtab span{ display:none; } .mtab .dot{ right:calc(50% - 16px); } }
.panel{ display:none; }
.panel.on{ display:block; }

/* sections */
.an-sec{ margin-top:clamp(20px,5.4vw,28px); }
.sec-head{ display:flex; align-items:center; gap:12px; padding:0 var(--pad) 12px; }
.sec-icon{ width:clamp(28px,7.6vw,36px); height:clamp(28px,7.6vw,36px); border-radius:6px; background:var(--surface); display:flex; align-items:center; justify-content:center; font-size:clamp(.82rem,2.6vw,1rem); color:var(--red); flex-shrink:0; }
.sec-title{ font-size:clamp(.82rem,2.8vw,.96rem); font-weight:700; letter-spacing:-.1px; }
.sec-sub{ font-size:clamp(.56rem,1.9vw,.68rem); color:var(--muted); margin-top:2px; }
.sec-head .sec-end{ margin-left:auto; font-size:.66rem; font-weight:700; color:var(--red); white-space:nowrap; }
.sec-pad{ padding:0 var(--pad); }
.an-card{ background:var(--surface); border:1px solid var(--line); border-radius:var(--r); padding:clamp(14px,4vw,20px) clamp(11px,3.4vw,16px); }
.chart-title{ font-size:.58rem; font-weight:700; color:var(--muted); letter-spacing:.08em; text-transform:uppercase; margin-bottom:14px; }
.gap{ height:clamp(18px,5vw,26px); }

/* kpi cards */
.kpis{ display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:8px; padding:16px var(--pad) 0; }
.kpi{ text-align:left; background:var(--surface); border:1px solid var(--line); border-radius:var(--r); padding:clamp(9px,2.6vw,13px); color:var(--ui-text,#fff); transition:border-color .2s,background .2s; min-width:0; overflow:hidden; }
.kpi:hover{ background:rgba(var(--fg),.08); }
.kpi.on{ border-color:rgba(255,45,45,.6); background:rgba(255,45,45,.07); }
.kpi-l{ font-size:clamp(.52rem,1.8vw,.62rem); color:var(--muted); font-weight:600; letter-spacing:.03em; white-space:nowrap; }
.kpi-v{ font-size:clamp(1rem,4.6vw,1.45rem); font-weight:700; line-height:1; margin-top:6px; }
.kpi-s{ height:26px; margin-top:8px; }
.kpi-s svg{ width:100%; height:100%; display:block; }
.delta{ display:inline-flex; align-items:center; height:17px; padding:0 6px; margin-top:7px; border-radius:4px; font-size:.52rem; font-weight:700; background:rgba(var(--fg),.07); color:var(--muted); white-space:nowrap; }
.delta.up{ background:rgba(255,45,45,.14); color:var(--red-1); }
.delta.down{ color:var(--red-4); }

/* quick action grid */
.qgrid{ display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:8px; padding:0 var(--pad); }
.qa{ position:relative; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:7px; padding:14px 6px; border-radius:var(--r); background:var(--surface); border:1px solid var(--line); text-align:center; transition:background .2s,transform .15s,border-color .2s; }
.qa:hover{ background:rgba(var(--fg),.09); border-color:rgba(255,45,45,.35); }
.qa:active{ transform:scale(.97); }
.qa i{ font-size:1.25rem; color:var(--red); }
.qa span{ font-size:clamp(.58rem,2vw,.7rem); font-weight:600; }
.qa .badge{ position:absolute; top:6px; right:6px; }
.badge{ min-width:18px; height:18px; padding:0 5px; border-radius:99px; background:var(--red); color:#000; font-size:.58rem; font-weight:700; display:inline-flex; align-items:center; justify-content:center; flex-shrink:0; }

/* list rows */
.list{ border-top:1px solid var(--line); }
.row{ display:flex; align-items:center; gap:clamp(10px,3vw,14px); padding:clamp(10px,3vw,14px) var(--pad); border-bottom:1px solid rgba(var(--fg),.06); transition:background .2s; }
.row:last-child{ border-bottom:none; }
a.row:hover{ background:rgba(var(--fg),.03); }
.row-ic{ width:clamp(27px,7.4vw,34px); height:clamp(27px,7.4vw,34px); border-radius:6px; background:var(--surface); display:flex; align-items:center; justify-content:center; flex-shrink:0; color:var(--red); font-size:clamp(.78rem,2.5vw,.96rem); }
.row-body{ flex:1; min-width:0; }
.row-t{ font-size:clamp(.7rem,2.4vw,.82rem); font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.row-t.un{ display:flex; align-items:center; gap:.3em; min-width:0; }
.row-t.un .un-nm{ min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
/* verified badge beside user names in lists: 1em, RED in dark and light mode */
.row-t.un .vbadge{ width:1em; height:1em; flex-shrink:0; vertical-align:baseline; color:var(--red) !important; fill:currentColor; }
.row-s{ font-size:clamp(.58rem,2vw,.7rem); color:var(--muted); margin-top:2px; line-height:1.55; }
.row-s strong, .ins-text strong{ color:var(--red); font-weight:700; }
.row-end{ font-size:1rem; color:rgba(var(--fg),.35); flex-shrink:0; }
.avatar{ width:34px; height:34px; border-radius:50%; object-fit:cover; border:1.5px solid var(--red); flex-shrink:0; }
.th{ position:relative; display:block; width:44px; aspect-ratio:4/5; border-radius:5px; overflow:hidden; background:var(--ph,#0d1030); flex-shrink:0; }
.th img, .th video{ position:absolute; inset:0; width:100%; height:100%; object-fit:cover; display:block; pointer-events:none; }
.th.txt{ background:var(--ph,#0d1030) url('uploads/Text.jpg') center/cover no-repeat; }
.th.txt::before{ content:''; position:absolute; inset:0; background:rgba(0,0,0,.5); }
.th.txt span{ position:absolute; inset:0; padding:4px; display:flex; align-items:center; justify-content:center; text-align:center; font-size:.38rem; line-height:1.3; overflow:hidden; word-break:break-word; }
.rank{ width:22px; font-size:.78rem; font-weight:700; color:var(--red); text-align:center; flex-shrink:0; }
.stats{ display:flex; flex-wrap:wrap; gap:4px 12px; margin-top:5px; font-size:clamp(.58rem,2vw,.7rem); color:var(--sub); font-weight:600; }
.stats span{ display:inline-flex; align-items:center; gap:4px; }
.stats i{ color:var(--red); font-size:.82rem; }
.ebar{ height:3px; border-radius:2px; background:rgba(var(--fg),.07); overflow:hidden; margin-top:7px; }
.ebar i{ display:block; height:100%; border-radius:2px; background:linear-gradient(90deg,var(--red-3),var(--red)); }
.tag{ display:inline-block; height:17px; line-height:17px; padding:0 6px; border-radius:4px; background:rgba(255,45,45,.12); color:var(--red-1); font-size:.5rem; font-weight:700; letter-spacing:.04em; text-transform:uppercase; flex-shrink:0; }
.tag.ok{ background:rgba(var(--fg),.1); color:var(--ui-text,#fff); }
.empty{ padding:24px var(--pad); text-align:center; color:var(--muted); font-size:.72rem; line-height:1.7; }
.empty i{ display:block; font-size:1.7rem; color:var(--red); margin-bottom:6px; opacity:.7; }
.mini-btn{ display:inline-flex; align-items:center; justify-content:center; height:24px; padding:0 10px; border-radius:4px; background:rgba(var(--fg),.1); color:var(--ui-text,#fff); font-size:.6rem; font-weight:700; flex-shrink:0; transition:background .2s; }
.mini-btn:hover{ background:rgba(var(--fg),.2); }

/* tiles */
.stat-grid{ display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); border-top:1px solid var(--line); }
.stat-cell{ padding:clamp(12px,3.6vw,18px) var(--pad); border-bottom:1px solid var(--line); transition:background .2s; }
.stat-cell:nth-child(odd){ border-right:1px solid var(--line); }
.stat-cell:hover{ background:rgba(var(--fg),.03); }
.stat-ico{ width:clamp(24px,6.4vw,30px); height:clamp(24px,6.4vw,30px); border-radius:6px; background:var(--surface); display:flex; align-items:center; justify-content:center; font-size:clamp(.74rem,2.3vw,.9rem); margin-bottom:10px; color:var(--red); }
.stat-val{ font-size:clamp(1.05rem,4.4vw,1.4rem); font-weight:700; line-height:1; }
.stat-label{ font-size:clamp(.58rem,2vw,.7rem); color:var(--sub); margin-top:5px; }

/* chart */
.chart-total{ display:flex; align-items:baseline; gap:8px; margin-bottom:14px; }
.chart-total b{ font-size:1.3rem; color:var(--red); line-height:1; }
.chart-total span{ font-size:.62rem; color:var(--muted); }
.bar-chart{ display:grid; grid-auto-flow:column; grid-auto-columns:minmax(0,1fr); gap:clamp(1px,.5vw,4px); height:clamp(80px,24vw,112px); align-items:end; position:relative; }
.bar-chart.ghost::before{ content:'No data yet for this range'; position:absolute; inset:0; display:flex; align-items:center; justify-content:center; font-size:.66rem; color:var(--muted); z-index:2; }
.bar{ width:100%; border-radius:3px 3px 0 0; min-height:3px; position:relative; background:linear-gradient(180deg,var(--red),var(--red-3)); transition:height .7s cubic-bezier(.22,1,.36,1); }
.bar.zero{ background:rgba(var(--fg),.07); }
.bar::after, .heat-cell::after{ content:attr(data-tip); position:absolute; bottom:calc(100% + 5px); left:50%; transform:translateX(-50%); padding:2px 6px; border-radius:4px; background:rgba(8,8,22,.97); border:1px solid var(--line2); font-size:.58rem; font-weight:600; white-space:nowrap; opacity:0; pointer-events:none; transition:opacity .15s; z-index:5; }
.bar:hover::after, .bar.on::after, .heat-cell:hover::after, .heat-cell.on::after{ opacity:1; }
.bar-labels{ display:flex; justify-content:space-between; margin-top:7px; font-size:.5rem; color:var(--muted); }

/* heat / calendar / weekdays */
.heat{ display:grid; grid-template-columns:auto repeat(12,minmax(0,1fr)); gap:clamp(2px,.9vw,4px); align-items:center; }
.heat-lbl{ font-size:.5rem; font-weight:700; color:var(--muted); padding-right:4px; }
.heat-cell{ aspect-ratio:1/1; border-radius:3px; position:relative; transition:transform .15s; }
.heat-cell:hover{ transform:scale(1.18); z-index:2; }
.heat-cell.peak{ box-shadow:0 0 0 1.5px var(--ui-text,#fff) inset; }
.heat-cols{ display:grid; grid-template-columns:auto repeat(12,minmax(0,1fr)); gap:clamp(2px,.9vw,4px); margin-top:7px; }
.heat-cols span{ font-size:.5rem; color:var(--muted); text-align:center; }
.heat-cols span:first-child{ visibility:hidden; padding-right:4px; }
.wk{ display:grid; grid-template-columns:repeat(7,minmax(0,1fr)); gap:6px; align-items:end; height:64px; }
.wk div{ border-radius:3px 3px 0 0; min-height:3px; background:rgba(255,45,45,.35); }
.wk div.peak{ background:var(--red); }
.wk-l{ display:grid; grid-template-columns:repeat(7,minmax(0,1fr)); gap:6px; margin-top:6px; }
.wk-l span{ font-size:.5rem; color:var(--muted); text-align:center; }
.cal{ display:grid; grid-template-rows:repeat(7,1fr); grid-auto-flow:column; grid-auto-columns:minmax(0,1fr); gap:clamp(2px,.8vw,4px); }
.cal i{ display:block; aspect-ratio:1/1; border-radius:3px; background:rgba(var(--fg),.06); position:relative; }
.cal i.fut{ visibility:hidden; }
.cal i.l1{ background:rgba(255,45,45,.35); } .cal i.l2{ background:rgba(255,45,45,.65); } .cal i.l3{ background:var(--red); }
.cal i::after{ content:attr(data-tip); position:absolute; bottom:calc(100% + 5px); left:50%; transform:translateX(-50%); padding:2px 6px; border-radius:4px; background:rgba(8,8,22,.97); border:1px solid var(--line2); font-size:.58rem; font-weight:600; white-space:nowrap; opacity:0; pointer-events:none; z-index:5; }
.cal i:hover::after, .cal i.on::after{ opacity:1; }
.cal-stats{ display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:8px; margin-bottom:14px; }
.cal-stats div{ text-align:center; padding:9px 4px; border-radius:var(--r); background:rgba(var(--fg),.05); }
.cal-stats b{ display:block; font-size:1.05rem; color:var(--red); line-height:1; }
.cal-stats span{ font-size:.54rem; color:var(--muted); }

/* progress / checklist / milestones */
.progress-list{ display:flex; flex-direction:column; gap:15px; }
.progress-head{ display:flex; justify-content:space-between; gap:8px; font-size:clamp(.62rem,2.1vw,.74rem); margin-bottom:7px; }
.progress-head span:first-child{ color:rgba(var(--fg),.85); font-weight:600; display:flex; align-items:center; gap:6px; }
.progress-head span:first-child i{ color:var(--red); }
.progress-head span:last-child{ color:var(--muted); }
.progress-track{ height:6px; border-radius:3px; background:rgba(var(--fg),.07); overflow:hidden; }
.progress-fill{ height:100%; border-radius:3px; width:0; background:linear-gradient(90deg,var(--red-3),var(--red)); transition:width 1s cubic-bezier(.22,1,.36,1); }
.ck{ font-size:1.05rem; flex-shrink:0; color:rgba(var(--fg),.3); }
.row.done .ck{ color:var(--red); }
.row.done .row-t{ color:var(--muted); text-decoration:line-through; }
.lvl{ display:inline-block; padding:1px 7px; border-radius:4px; background:rgba(255,45,45,.14); color:var(--red-1); font-size:.52rem; font-weight:700; margin-left:6px; }
.chips{ display:flex; flex-wrap:wrap; gap:6px; }
.chip{ display:flex; align-items:center; gap:7px; height:clamp(26px,7.2vw,31px); padding:0 11px; border-radius:4px; background:rgba(var(--fg),.1); font-size:clamp(.62rem,2.1vw,.74rem); font-weight:600; }
.chip .hash{ color:var(--red); }
.chip b{ font-size:.54rem; font-weight:700; color:var(--red); background:rgba(255,45,45,.14); padding:2px 6px; border-radius:3px; }

/* content manager toolbar */
.toolbar{ display:flex; gap:8px; overflow-x:auto; padding:0 var(--pad) 10px; }
.toolbar::after{ content:''; flex:0 0 var(--pad); }
.search{ position:relative; margin:0 var(--pad) 10px; }
.search input{ width:100%; height:34px; padding:0 34px 0 12px; border-radius:6px; background:rgba(var(--fg),.06); border:1px solid rgba(var(--fg),.14); color:var(--ui-text,#fff); font-size:.72rem; transition:border-color .2s,background .2s; }
.search input:focus{ background:rgba(var(--fg),.09); border-color:rgba(var(--fg),.32); }
.search input::placeholder{ color:rgba(var(--fg),.28); }
.search button{ position:absolute; right:10px; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--muted); font-size:.8rem; display:none; }
.more-btn{ display:flex; align-items:center; justify-content:center; width:calc(100% - 2 * var(--pad)); margin:12px var(--pad) 0; height:var(--btn-h); border:none; border-radius:4px; background:rgba(var(--fg),.1); color:var(--ui-text,#fff); font-size:.72rem; font-weight:600; }
.more-btn:hover{ background:rgba(var(--fg),.2); }

/* skeleton */
#page-skeleton{ position:fixed; inset:0; z-index:9999; background:var(--bg); background-attachment:fixed; display:flex; justify-content:center; opacity:1; transition:opacity .45s ease,visibility .45s ease; pointer-events:none; }
#page-skeleton.hide{ opacity:0; visibility:hidden; }
.sk-lane{ width:100%; max-width:var(--max-w); display:flex; flex-direction:column; overflow:hidden; }
@media (min-width:801px){ .sk-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); } }
@media (max-width:800px){ .sk-lane{ max-width:100%; } }
.sk{ position:relative; overflow:hidden; background:rgba(var(--fg),.06); border-radius:6px; }
.sk::after{ content:''; position:absolute; inset:0; background:linear-gradient(90deg,transparent,rgba(var(--fg),.09),transparent); transform:translateX(-100%); animation:skS 1.5s infinite; }
@keyframes skS{ 100%{ transform:translateX(100%); } }

/* ═══════════════════════════════════════════════════════════════
   RESPONSIVE ONLY — everything 10% bigger (desktop untouched)
   Kept at the very end so it wins over the rules above.
═══════════════════════════════════════════════════════════════ */
@media (max-width:800px){
    :root{
        --topbar-h:59px;
        --pad:clamp(10px, 3.3vw, 17.6px);
        --r:6.6px;
        --btn-h:clamp(27.5px, 7.92vw, 33px);
    }

    /* topbar */
    .an-topbar{ padding:0 11px; }
    .an-x{ width:37px; height:37px; }
    .an-x svg{ width:24px; height:24px; }
    .an-title{ font-size:1.01rem; margin:0 7px 0 11px; }

    /* hero */
    .hero{ padding:clamp(15px, 4.4vw, 22px) var(--pad) clamp(11px, 3.3vw, 15px); gap:clamp(12px, 3.74vw, 17.6px); }
    .hero-avatar{ width:clamp(53px, 15.4vw, 70px); height:clamp(53px, 15.4vw, 70px); border-width:2.6px; }
    .hero-name{ font-size:clamp(.95rem, 3.52vw, 1.14rem); }
    .hero-name .vbadge{ width:1em; height:1em; }
    .hero-sub{ font-size:clamp(.64rem, 2.2vw, .77rem); margin-top:2.2px; }
    .hero-badges{ gap:6.6px; margin-top:8.8px; }
    .hero-badge{ gap:5.5px; height:clamp(22px, 6.16vw, 25px); padding:0 10px; font-size:clamp(.57rem, 1.98vw, .68rem); }
    .range{ gap:8.8px; padding:0 var(--pad) 15px; }
    .pill{ gap:5.5px; padding:0 17.6px; font-size:.79rem; }
    .pill i{ font-size:14px; }

    /* main tabs */
    .mtab{ height:46px; font-size:.81rem; gap:6.6px; }
    .mtab i{ font-size:16.5px; }
    .mtab.on::after{ height:2.2px; }
    .mtab .dot{ top:10px; right:calc(50% - 33px); width:7.7px; height:7.7px; }

    /* sections */
    .an-sec{ margin-top:clamp(22px, 5.94vw, 31px); }
    .sec-head{ gap:13px; padding:0 var(--pad) 13px; }
    .sec-icon{ width:clamp(31px, 8.36vw, 40px); height:clamp(31px, 8.36vw, 40px); border-radius:6.6px; font-size:clamp(.9rem, 2.86vw, 1.1rem); }
    .sec-title{ font-size:clamp(.9rem, 3.08vw, 1.056rem); }
    .sec-sub{ font-size:clamp(.62rem, 2.09vw, .75rem); margin-top:2.2px; }
    .sec-head .sec-end{ font-size:.73rem; }
    .an-card{ padding:clamp(15px, 4.4vw, 22px) clamp(12px, 3.74vw, 17.6px); }
    .chart-title{ font-size:.64rem; margin-bottom:15px; }
    .gap{ height:clamp(20px, 5.5vw, 29px); }

    /* kpi cards */
    .kpis{ gap:8.8px; padding:17.6px var(--pad) 0; }
    .kpi{ padding:clamp(10px, 2.86vw, 14px); }
    .kpi-l{ font-size:clamp(.57rem, 1.98vw, .68rem); }
    .kpi-v{ font-size:clamp(1.1rem, 5.06vw, 1.595rem); margin-top:6.6px; }
    .kpi-s{ height:29px; margin-top:8.8px; }
    .delta{ height:18.7px; padding:0 6.6px; margin-top:7.7px; font-size:.57rem; }

    /* quick actions */
    .qgrid{ gap:8.8px; }
    .qa{ gap:7.7px; padding:15px 6.6px; }
    .qa i{ font-size:1.375rem; }
    .qa span{ font-size:clamp(.64rem, 2.2vw, .77rem); }
    .qa .badge{ top:6.6px; right:6.6px; }
    .badge{ min-width:20px; height:20px; padding:0 5.5px; font-size:.64rem; }

    /* list rows */
    .row{ gap:clamp(11px, 3.3vw, 15px); padding:clamp(11px, 3.3vw, 15px) var(--pad); }
    .row-ic{ width:clamp(30px, 8.14vw, 37px); height:clamp(30px, 8.14vw, 37px); border-radius:6.6px; font-size:clamp(.86rem, 2.75vw, 1.056rem); }
    .row-t{ font-size:clamp(.77rem, 2.64vw, .9rem); }
    .row-s{ font-size:clamp(.64rem, 2.2vw, .77rem); margin-top:2.2px; }
    .row-end{ font-size:1.1rem; }
    .avatar{ width:37px; height:37px; border-width:1.65px; }
    .th{ width:48px; border-radius:5.5px; }
    .th.txt span{ padding:4.4px; font-size:.42rem; }
    .rank{ width:24px; font-size:.86rem; }
    .stats{ gap:4.4px 13px; margin-top:5.5px; font-size:clamp(.64rem, 2.2vw, .77rem); }
    .stats span{ gap:4.4px; }
    .stats i{ font-size:.9rem; }
    .ebar{ height:3.3px; margin-top:7.7px; }
    .tag{ height:18.7px; line-height:18.7px; padding:0 6.6px; font-size:.55rem; }
    .empty{ padding:26px var(--pad); font-size:.79rem; }
    .empty i{ font-size:1.87rem; margin-bottom:6.6px; }
    .mini-btn{ height:26.4px; padding:0 11px; font-size:.66rem; }

    /* tiles */
    .stat-cell{ padding:clamp(13px, 3.96vw, 20px) var(--pad); }
    .stat-ico{ width:clamp(26px, 7.04vw, 33px); height:clamp(26px, 7.04vw, 33px); border-radius:6.6px; font-size:clamp(.81rem, 2.53vw, .99rem); margin-bottom:11px; }
    .stat-val{ font-size:clamp(1.155rem, 4.84vw, 1.54rem); }
    .stat-label{ font-size:clamp(.64rem, 2.2vw, .77rem); margin-top:5.5px; }

    /* chart */
    .chart-total{ gap:8.8px; margin-bottom:15px; }
    .chart-total b{ font-size:1.43rem; }
    .chart-total span{ font-size:.68rem; }
    .bar-chart{ height:clamp(88px, 26.4vw, 123px); }
    .bar-chart.ghost::before{ font-size:.73rem; }
    .bar{ min-height:3.3px; }
    .bar::after, .heat-cell::after, .cal i::after{ padding:2.2px 6.6px; font-size:.64rem; }
    .bar-labels{ margin-top:7.7px; font-size:.55rem; }

    /* heat / calendar / weekdays */
    .heat-lbl{ font-size:.55rem; }
    .heat-cols span{ font-size:.55rem; }
    .wk{ gap:6.6px; height:70px; }
    .wk-l{ gap:6.6px; margin-top:6.6px; }
    .wk-l span{ font-size:.55rem; }
    .cal-stats{ gap:8.8px; margin-bottom:15px; }
    .cal-stats div{ padding:10px 4.4px; }
    .cal-stats b{ font-size:1.155rem; }
    .cal-stats span{ font-size:.6rem; }

    /* progress / checklist */
    .progress-list{ gap:16.5px; }
    .progress-head{ font-size:clamp(.68rem, 2.31vw, .81rem); margin-bottom:7.7px; }
    .progress-track{ height:6.6px; }
    .ck{ font-size:1.155rem; }
    .lvl{ padding:1px 7.7px; font-size:.57rem; margin-left:6.6px; }
    .chips{ gap:6.6px; }
    .chip{ gap:7.7px; height:clamp(29px, 7.92vw, 34px); padding:0 12px; font-size:clamp(.68rem, 2.31vw, .81rem); }
    .chip b{ font-size:.6rem; padding:2.2px 6.6px; }

    /* toolbar */
    .toolbar{ gap:8.8px; padding:0 var(--pad) 11px; }
    .search{ margin:0 var(--pad) 11px; }
    .search input{ height:37px; padding:0 37px 0 13px; font-size:.79rem; border-radius:6.6px; }
    .search button{ right:11px; font-size:.88rem; }
    .more-btn{ margin:13px var(--pad) 0; font-size:.79rem; }
}

@media (max-width:490px){
    :root{ --topbar-h:53px; }
    .an-x{ width:33px; height:33px; }
    .an-title{ font-size:.92rem; }
    .pill{ font-size:.7rem; padding:0 14px; }
    .mtab{ font-size:.7rem; gap:4.4px; height:42px; }
    .mtab i{ font-size:15.4px; }
}

@media (max-width:380px){
    :root{ --topbar-h:48px; }
}

@media (max-width:360px){
    .mtab .dot{ right:calc(50% - 18px); }
}

/* ═══════════ LIGHT MODE — <html data-theme="light"> (dark mode is never touched) ═══════════ */
:root[data-theme="light"]{
    color-scheme:light;
    --fg:6,7,26;
    --ui-text:#0b0d24;
    --solid:#ffffff;
    --ph:#eceef5;
    --surface:rgba(6,7,26,.05);
    --line:rgba(6,7,26,.1);
    --line2:rgba(6,7,26,.2);
    --muted:rgba(6,7,26,.52);
    --sub:rgba(6,7,26,.66);
    --bg:linear-gradient(-45deg,#ffffff,#f4f5fb,#f4f5fb);
}
[data-theme="light"] body{ color:#0b0d24; }
/* verified badge stays red in light mode too (the name text turns dark, the badge does not) */
[data-theme="light"] .hero-name .vbadge{ color:var(--red) !important; }
</style>
<noscript><style>.rv{opacity:1 !important} #page-skeleton{display:none} .progress-fill{width:var(--w) !important} .panel{display:block !important}</style></noscript>
</head>
<body>

<?php echo dz_badge_sprite(); ?>

<div id="page-skeleton" aria-hidden="true">
    <div class="sk-lane">
        <div style="height:var(--topbar-h);display:flex;align-items:center;justify-content:space-between;padding:0 16px 0 14px"><div class="sk" style="width:20px;height:20px"></div><div class="sk" style="width:110px;height:13px"></div></div>
        <div style="display:flex;align-items:center;gap:14px;padding:18px var(--pad);border-top:1px solid var(--line)">
            <div class="sk" style="width:60px;height:60px;border-radius:50%;flex-shrink:0"></div>
            <div style="flex:1;display:flex;flex-direction:column;gap:8px"><div class="sk" style="width:55%;height:13px"></div><div class="sk" style="width:75%;height:9px"></div></div>
        </div>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;padding:6px var(--pad)"><div class="sk" style="height:96px"></div><div class="sk" style="height:96px"></div><div class="sk" style="height:96px"></div></div>
        <div style="padding:14px var(--pad)"><div class="sk" style="height:150px"></div></div>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;padding:0 var(--pad)"><div class="sk" style="height:70px"></div><div class="sk" style="height:70px"></div><div class="sk" style="height:70px"></div></div>
    </div>
</div>

<div class="an-lane">

    <header class="an-topbar">
        <button type="button" class="an-x" onclick="dbClose()" aria-label="Close">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
        <span class="an-title">Your Dashboard</span>
    </header>

    <!-- HERO -->
    <section class="hero rv" style="--i:0;">
        <img class="hero-avatar" src="<?= h($avatar) ?>" onerror="this.src='uploads/me.png'" alt="">
        <div class="hero-info">
            <div class="hero-name"><span class="hn-nm"><?= h($display_name) ?></span><?= $is_verified ? dz_badge('f') : '' ?></div>
            <div class="hero-sub">@<?= h($user['username']) ?> · <?= dfmt($total_trackers) ?> trackers · <?= dfmt($total_posts) ?> posts</div>
            <div class="hero-badges">
                <?php if ($streak > 0): ?><span class="hero-badge"><i class="ri-fire-line"></i> <?= $streak ?> day streak</span><?php endif; ?>
                <span class="hero-badge"><i class="ri-user-settings-line"></i> Profile <?= $check_pct ?>%</span>
            </div>
        </div>
    </section>

    <div class="range rv" style="--i:1;">
        <?php foreach ([7, 30, 90] as $r): ?><a class="pill<?= $r === $R ? ' on' : '' ?>" href="?range=<?= $r ?>"><?= $r ?> days</a><?php endforeach; ?>
    </div>

    <nav class="mtabs rv" style="--i:2;" id="mtabs">
        <button type="button" class="mtab on" data-p="overview"><i class="ri-dashboard-line"></i><span>Overview</span></button>
        <button type="button" class="mtab" data-p="content"><i class="ri-grid-fill"></i><span>Content</span></button>
        <button type="button" class="mtab" data-p="audience"><i class="ri-group-line"></i><span>Audience</span><?php if ($req_pending > 0): ?><i class="dot"></i><?php endif; ?></button>
        <button type="button" class="mtab" data-p="growth"><i class="ri-rocket-2-line"></i><span>Growth</span></button>
    </nav>

    <!-- ═════════ OVERVIEW ═════════ -->
    <div class="panel on" id="p-overview">

        <div class="kpis rv" style="--i:3;" id="kpis">
            <button type="button" class="kpi on" data-k="int"><div class="kpi-l">Reactions</div><div class="kpi-v"><?= dfmt($inter) ?></div><div class="delta <?= $d_int[1] ?>"><?= h($d_int[0]) ?></div><div class="kpi-s" data-s="int"></div></button>
            <button type="button" class="kpi" data-k="vis"><div class="kpi-l">Profile visits</div><div class="kpi-v"><?= dfmt($visits) ?></div><div class="delta <?= $d_vis[1] ?>"><?= h($d_vis[0]) ?></div><div class="kpi-s" data-s="vis"></div></button>
            <button type="button" class="kpi" data-k="trk"><div class="kpi-l"><?= $new_tr !== null ? 'New trackers' : 'Trackers' ?></div><div class="kpi-v"><?= dfmt($new_tr ?? $total_trackers) ?></div><div class="delta <?= $d_trk[1] ?>"><?= h($d_trk[0]) ?></div><div class="kpi-s" data-s="trk"></div></button>
        </div>

        <section class="an-sec rv" style="--i:4;">
            <div class="sec-pad"><div class="an-card">
                <div class="chart-total"><b id="chTotal">0</b><span id="chLabel">reactions · last <?= $R ?> days</span></div>
                <div class="bar-chart" id="barChart"></div>
                <div class="bar-labels"><span id="lbA"></span><span id="lbB"></span><span id="lbC"></span></div>
            </div></div>
        </section>

        <section class="an-sec rv" style="--i:5;">
            <div class="sec-head"><div class="sec-icon"><i class="ri-flashlight-line"></i></div><div><div class="sec-title">Quick Actions</div><div class="sec-sub">Jump straight to what you need</div></div></div>
            <div class="qgrid">
                <a class="qa" href="upload.php"><i class="ri-add-circle-line"></i><span>Create post</span></a>
                <a class="qa" href="upload.php?mode=edit"><i class="ri-edit-2-line"></i><span>Edit posts</span><?php if ($tags_pending > 0): ?><em class="badge"><?= $tags_pending ?></em><?php endif; ?></a>
                <a class="qa" href="upload.php?mode=highlights"><i class="ri-star-smile-line"></i><span>Highlights</span></a>
                <a class="qa" href="setting.php?open=trackreqs"><i class="ri-user-received-line"></i><span>Requests</span><?php if ($req_pending > 0): ?><em class="badge"><?= $req_pending ?></em><?php endif; ?></a>
                <a class="qa" href="messages.php"><i class="ri-chat-1-line"></i><span>Messages</span><?php if ($unread > 0): ?><em class="badge"><?= $unread ?></em><?php endif; ?></a>
                <a class="qa" href="analytics.php"><i class="ri-bar-chart-2-line"></i><span>Analytics</span></a>
            </div>
        </section>

        <section class="an-sec rv" style="--i:6;">
            <div class="sec-head"><div class="sec-icon"><i class="ri-dashboard-line"></i></div><div><div class="sec-title">Overview</div><div class="sec-sub">Last <?= $R ?> days vs the <?= $R ?> before</div></div></div>
            <div class="stat-grid">
                <?php foreach ($tiles as $t): ?>
                <div class="stat-cell">
                    <div class="stat-ico"><i class="<?= $t[0] ?>"></i></div>
                    <div class="stat-val"><?= h($t[1]) ?></div>
                    <div class="stat-label"><?= h($t[2]) ?></div>
                    <div class="delta <?= $t[3][1] ?>"><?= h($t[3][0]) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="an-sec rv" style="--i:7;">
            <div class="sec-head"><div class="sec-icon"><i class="ri-trophy-line"></i></div><div><div class="sec-title">Top Posts</div><div class="sec-sub">Likes + reposts + saves, all time</div></div><a class="sec-end" href="#" onclick="goPanel('content');return false;">See all</a></div>
            <div class="list">
            <?php if (!$top): ?>
                <div class="empty"><i class="ri-image-2-line"></i>No reactions yet. Your best posts will rank here.</div>
            <?php else: foreach ($top as $i => $p): ?>
                <a class="row" href="upload.php?edit=<?= (int)$p['id'] ?>">
                    <span class="rank">#<?= $i + 1 ?></span><?= dthumb($p) ?>
                    <div class="row-body">
                        <div class="row-t"><?= h(trim((string)$p['caption']) !== '' ? mb_substr($p['caption'], 0, 46) : '(no caption)') ?></div>
                        <div class="stats"><span><i class="ri-heart-3-fill"></i><?= dfmt($p['lk']) ?></span><span><i class="ri-repeat-line"></i><?= dfmt($p['rp']) ?></span><span><i class="ri-bookmark-fill"></i><?= dfmt($p['fv']) ?></span></div>
                    </div>
                    <i class="ri-arrow-right-s-line row-end"></i>
                </a>
            <?php endforeach; endif; ?>
            </div>
        </section>

        <section class="an-sec rv" style="--i:8;">
            <div class="sec-head"><div class="sec-icon"><i class="ri-calendar-schedule-line"></i></div><div><div class="sec-title">Best Time to Post</div><div class="sec-sub">When people react to your content</div></div></div>
            <div class="sec-pad"><div class="an-card">
            <?php if (!$has_time): ?>
                <div class="empty" style="padding:6px 0"><i class="ri-hourglass-line"></i>Not enough reactions yet to find your best time.</div>
            <?php else: ?>
                <div class="chart-title">Hour of day</div>
                <div class="heat">
                    <?php for ($row = 0; $row < 2; $row++): ?>
                    <span class="heat-lbl"><?= $row === 0 ? 'AM' : 'PM' ?></span>
                    <?php for ($col = 0; $col < 12; $col++): $hr = $row * 12 + $col; $c = $hours[$hr]; $a = $c > 0 ? max(0.16, ($c / $max_h) * 0.92) : 0.06; ?>
                    <div class="heat-cell<?= ($c > 0 && $c === $max_h) ? ' peak' : '' ?>" data-tip="<?= date('g A', mktime($hr, 0, 0)) ?> · <?= $c ?>" style="background:rgba(255,45,45,<?= round($a, 2) ?>)"></div>
                    <?php endfor; endfor; ?>
                </div>
                <div class="heat-cols"><span>AM</span><?php foreach ([12,1,2,3,4,5,6,7,8,9,10,11] as $x): ?><span><?= $x ?></span><?php endforeach; ?></div>
                <div class="chart-title" style="margin:20px 0 14px">Day of week</div>
                <div class="wk"><?php for ($d = 1; $d <= 7; $d++): ?><div class="<?= $d === $peak_d ? 'peak' : '' ?>" style="height:<?= max(5, round($days[$d] / $max_d * 100)) ?>%"></div><?php endfor; ?></div>
                <div class="wk-l"><?php for ($d = 1; $d <= 7; $d++): ?><span><?= $dn[$d] ?></span><?php endfor; ?></div>
            <?php endif; ?>
            </div></div>
        </section>
    </div>

    <!-- ═════════ CONTENT ═════════ -->
    <div class="panel" id="p-content">
        <section class="an-sec" style="margin-top:16px">
            <div class="sec-pad"><div class="cal-stats" style="margin-bottom:0">
                <div><b><?= dfmt($total_posts) ?></b><span>Posts</span></div>
                <div><b><?= dfmt(array_sum(array_column($posts, 'eng'))) ?></b><span>Total reactions</span></div>
                <div><b><?= dfmt($per_post) ?></b><span>Avg per post</span></div>
            </div></div>
        </section>
        <section class="an-sec">
            <div class="sec-head"><div class="sec-icon"><i class="ri-grid-fill"></i></div><div><div class="sec-title">All Posts</div><div class="sec-sub">Sort, filter and jump into any post to edit it</div></div></div>
            <div class="search"><input type="text" id="pq" placeholder="Search captions or #hashtags…" autocomplete="off"><button type="button" id="pqx">✕</button></div>
            <div class="toolbar" id="sortBar">
                <button type="button" class="pill on" data-s="new">Newest</button>
                <button type="button" class="pill" data-s="lk"><i class="ri-heart-3-line"></i>Likes</button>
                <button type="button" class="pill" data-s="rp"><i class="ri-repeat-line"></i>Reposts</button>
                <button type="button" class="pill" data-s="fv"><i class="ri-bookmark-line"></i>Saves</button>
            </div>
            <div class="toolbar" id="typeBar">
                <button type="button" class="pill on" data-t="a">All</button>
                <button type="button" class="pill" data-t="i"><i class="ri-image-line"></i>Photos</button>
                <button type="button" class="pill" data-t="v"><i class="ri-video-line"></i>Videos</button>
                <button type="button" class="pill" data-t="t"><i class="ri-file-text-line"></i>Text</button>
            </div>
            <div class="list" id="postList"></div>
            <button type="button" class="more-btn" id="postMore" style="display:none">See more</button>
        </section>
        <?php if ($types): ?>
        <section class="an-sec">
            <div class="sec-head"><div class="sec-icon"><i class="ri-pie-chart-line"></i></div><div><div class="sec-title">What Works Best</div><div class="sec-sub">Average reactions per post by format</div></div></div>
            <div class="sec-pad"><div class="an-card"><div class="progress-list">
                <?php foreach ($types as $t => $d): $pct = round($d['avg'] / $max_avg * 100); $m = $tmeta[$t] ?? ['ri-image-line', ucfirst($t)]; ?>
                <div>
                    <div class="progress-head"><span><i class="<?= $m[0] ?>"></i> <?= h($m[1]) ?></span><span><?= $d['avg'] ?> avg · <?= $d['n'] ?> post<?= $d['n'] === 1 ? '' : 's' ?></span></div>
                    <div class="progress-track"><div class="progress-fill" data-w="<?= $pct ?>" style="--w:<?= $pct ?>%"></div></div>
                </div>
                <?php endforeach; ?>
            </div></div></div>
        </section>
        <?php endif; ?>
        <?php if ($top_tags): ?>
        <section class="an-sec">
            <div class="sec-head"><div class="sec-icon"><i class="ri-hashtag"></i></div><div><div class="sec-title">Hashtag Performance</div><div class="sec-sub">Average reactions per post that uses the tag</div></div></div>
            <div class="sec-pad"><div class="chips">
                <?php foreach ($top_tags as $tg => $d): ?><a class="chip" href="search.php?focus=1&mq=<?= rawurlencode('#' . $tg) ?>"><span class="hash">#</span><?= h($tg) ?><b><?= $d['avg'] ?> avg · <?= $d['n'] ?>×</b></a><?php endforeach; ?>
            </div></div>
        </section>
        <?php endif; ?>
    </div>

    <!-- ═════════ AUDIENCE ═════════ -->
    <div class="panel" id="p-audience">
        <section class="an-sec" style="margin-top:16px">
            <div class="sec-pad"><div class="cal-stats" style="margin-bottom:0">
                <div><b><?= dfmt($total_trackers) ?></b><span>Trackers</span></div>
                <div><b><?= dfmt($total_tracking) ?></b><span>Tracking</span></div>
                <div><b><?= dfmt($uniq) ?></b><span>Visitors (<?= $R ?>d)</span></div>
            </div></div>
        </section>

        <?php if ($requests || $req_pending > 0): ?>
        <section class="an-sec">
            <div class="sec-head"><div class="sec-icon"><i class="ri-user-received-line"></i></div><div><div class="sec-title">Track Requests</div><div class="sec-sub"><?= $req_pending ?> waiting for your answer</div></div><a class="sec-end" href="setting.php?open=trackreqs">Review</a></div>
            <div class="list">
                <?php foreach ($requests as $v): ?>
                <a class="row" href="profile.php?user_id=<?= (int)$v['id'] ?>"><img class="avatar" src="<?= h(!empty($v['profile_pic']) ? $v['profile_pic'] : 'uploads/me.png') ?>" onerror="this.src='uploads/me.png'" alt=""><div class="row-body"><div class="row-t un"><span class="un-nm"><?= h($v['username']) ?></span><?= !empty($v['is_verified']) ? dz_badge('f') : '' ?></div><div class="row-s">Requested <?= h(dago($v['at'])) ?></div></div><i class="ri-arrow-right-s-line row-end"></i></a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <section class="an-sec">
            <div class="sec-head"><div class="sec-icon"><i class="ri-user-add-line"></i></div><div><div class="sec-title">New Trackers</div><div class="sec-sub">The latest people to start tracking you</div></div></div>
            <div class="list">
            <?php if (!$new_list): ?><div class="empty"><i class="ri-user-add-line"></i>No trackers yet. Post consistently and share your profile link.</div>
            <?php else: foreach ($new_list as $v): ?>
                <a class="row" href="profile.php?user_id=<?= (int)$v['id'] ?>"><img class="avatar" src="<?= h(!empty($v['profile_pic']) ? $v['profile_pic'] : 'uploads/me.png') ?>" onerror="this.src='uploads/me.png'" alt=""><div class="row-body"><div class="row-t un"><span class="un-nm"><?= h($v['username']) ?></span><?= !empty($v['is_verified']) ? dz_badge('f') : '' ?></div><div class="row-s"><?= !empty($v['at']) ? 'Started tracking you ' . h(dago($v['at'])) : 'Tracks you' ?></div></div><i class="ri-arrow-right-s-line row-end"></i></a>
            <?php endforeach; endif; ?>
            </div>
        </section>

        <section class="an-sec">
            <div class="sec-head"><div class="sec-icon"><i class="ri-heart-3-line"></i></div><div><div class="sec-title">Top Fans</div><div class="sec-sub">People who react to your posts the most</div></div></div>
            <div class="list">
            <?php if (!$fans): ?><div class="empty"><i class="ri-heart-3-line"></i>Your biggest supporters will show up here.</div>
            <?php else: foreach ($fans as $i => $v): ?>
                <a class="row" href="profile.php?user_id=<?= (int)$v['id'] ?>"><span class="rank">#<?= $i + 1 ?></span><img class="avatar" src="<?= h(!empty($v['profile_pic']) ? $v['profile_pic'] : 'uploads/me.png') ?>" onerror="this.src='uploads/me.png'" alt=""><div class="row-body"><div class="row-t un"><span class="un-nm"><?= h($v['username']) ?></span><?= !empty($v['is_verified']) ? dz_badge('f') : '' ?></div><div class="row-s"><strong><?= (int)$v['c'] ?></strong> reaction<?= (int)$v['c'] === 1 ? '' : 's' ?> on your posts</div></div><i class="ri-arrow-right-s-line row-end"></i></a>
            <?php endforeach; endif; ?>
            </div>
        </section>

        <section class="an-sec">
            <div class="sec-head"><div class="sec-icon"><i class="ri-eye-line"></i></div><div><div class="sec-title">Recent Visitors</div><div class="sec-sub">Who viewed your profile this week</div></div></div>
            <div class="list">
            <?php if (!$visitors): ?><div class="empty"><i class="ri-user-search-line"></i>No profile visits this week yet.</div>
            <?php else: foreach ($visitors as $v): ?>
                <a class="row" href="profile.php?user_id=<?= (int)$v['id'] ?>"><img class="avatar" src="<?= h(!empty($v['profile_pic']) ? $v['profile_pic'] : 'uploads/me.png') ?>" onerror="this.src='uploads/me.png'" alt=""><div class="row-body"><div class="row-t un"><span class="un-nm"><?= h($v['username']) ?></span><?= !empty($v['is_verified']) ? dz_badge('f') : '' ?></div><div class="row-s">Visited <?= h(dago($v['last_at'])) ?></div></div><i class="ri-arrow-right-s-line row-end"></i></a>
            <?php endforeach; endif; ?>
            </div>
        </section>
    </div>

    <!-- ═════════ GROWTH ═════════ -->
    <div class="panel" id="p-growth">
        <section class="an-sec" style="margin-top:16px">
            <div class="sec-head"><div class="sec-icon"><i class="ri-calendar-check-line"></i></div><div><div class="sec-title">Posting Consistency</div><div class="sec-sub">Your last 13 weeks, one square per day</div></div></div>
            <div class="sec-pad"><div class="an-card">
                <div class="cal-stats">
                    <div><b><?= $streak ?></b><span>Day streak</span></div>
                    <div><b><?= $active_days ?></b><span>Active days</span></div>
                    <div><b><?= round($posts_84 / 12, 1) ?></b><span>Posts / week</span></div>
                </div>
                <div class="cal">
                    <?php for ($i = 0; $i < 91; $i++): $d = date('Y-m-d', strtotime("+$i days", $cal_start)); $fut = $d > $today; $c = $cal[$d] ?? 0;
                        $l = $c >= 3 ? 'l3' : ($c == 2 ? 'l2' : ($c == 1 ? 'l1' : '')); ?>
                    <i class="<?= $fut ? 'fut' : $l ?>" data-tip="<?= date('M j', strtotime($d)) ?> · <?= $c ?> post<?= $c === 1 ? '' : 's' ?>"></i>
                    <?php endfor; ?>
                </div>
            </div></div>
        </section>

        <section class="an-sec">
            <div class="sec-head"><div class="sec-icon"><i class="ri-checkbox-circle-line"></i></div><div><div class="sec-title">Complete Your Profile</div><div class="sec-sub"><?= $check_done ?> of <?= count($check) ?> done</div></div></div>
            <div class="sec-pad" style="margin-bottom:10px"><div class="progress-track"><div class="progress-fill" data-w="<?= $check_pct ?>" style="--w:<?= $check_pct ?>%"></div></div></div>
            <div class="list">
                <?php foreach ($check as $c): ?>
                <a class="row<?= $c[1] ? ' done' : '' ?>" href="<?= $c[1] ? '#' : h($c[2]) ?>"<?= $c[1] ? ' onclick="return false"' : '' ?>>
                    <i class="<?= $c[1] ? 'ri-checkbox-circle-fill' : 'ri-checkbox-blank-circle-line' ?> ck"></i>
                    <div class="row-body"><div class="row-t"><?= h($c[0]) ?></div></div>
                    <?php if (!$c[1]): ?><i class="ri-arrow-right-s-line row-end"></i><?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="an-sec">
            <div class="sec-head"><div class="sec-icon"><i class="ri-medal-line"></i></div><div><div class="sec-title">Milestones</div><div class="sec-sub">Your next goals on Dezzme</div></div></div>
            <div class="sec-pad"><div class="an-card"><div class="progress-list">
                <?php foreach ($mile as $m): list($lvl, $prev, $next) = dladder($m[2], $m[3]); $done = $m[2] >= end($m[3]);
                    $pct = $done ? 100 : round(($m[2] - $prev) / max(1, $next - $prev) * 100); ?>
                <div>
                    <div class="progress-head"><span><i class="<?= $m[0] ?>"></i> <?= $m[1] ?><em class="lvl" style="font-style:normal">Level <?= $lvl ?></em></span><span><?= dfmt($m[2]) ?> / <?= dfmt($next) ?></span></div>
                    <div class="progress-track"><div class="progress-fill" data-w="<?= $pct ?>" style="--w:<?= $pct ?>%"></div></div>
                </div>
                <?php endforeach; ?>
            </div></div></div>
        </section>

        <section class="an-sec">
            <div class="sec-head"><div class="sec-icon"><i class="ri-lightbulb-line"></i></div><div><div class="sec-title">Insights &amp; Next Steps</div><div class="sec-sub">What your data is telling you</div></div></div>
            <div class="list">
                <?php foreach ($ins as $n): ?>
                <div class="row" style="align-items:flex-start"><div class="row-ic"><i class="<?= $n[0] ?>"></i></div><div class="row-body"><div class="row-t" style="white-space:normal"><?= h($n[1]) ?></div><div class="row-s ins-text"><?= $n[2] ?></div></div></div>
                <?php endforeach; ?>
            </div>
        </section>

        <?php if ($ads): ?>
        <section class="an-sec">
            <div class="sec-head"><div class="sec-icon"><i class="ri-rocket-2-line"></i></div><div><div class="sec-title">Your Ads</div><div class="sec-sub">Status of the posts you boosted</div></div></div>
            <div class="list">
                <?php foreach ($ads as $a): $st = strtolower((string)$a['status']); ?>
                <a class="row" href="upload.php?edit=<?= (int)$a['id'] ?>"><div class="row-ic"><i class="ri-megaphone-line"></i></div><div class="row-body"><div class="row-t"><?= h(trim((string)$a['caption']) !== '' ? mb_substr($a['caption'], 0, 40) : '(no caption)') ?></div><div class="row-s"><?= (int)$a['duration_days'] ?> day<?= (int)$a['duration_days'] === 1 ? '' : 's' ?> · $<?= number_format((float)$a['price'], 2) ?></div></div><span class="tag<?= in_array($st, ['approved', 'active'], true) ? ' ok' : '' ?>"><?= h($st ?: 'pending') ?></span></a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>
    </div>

</div>

<script>
var CHART = <?= json_encode($chart, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
var POSTS = <?= json_encode($jsposts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR) ?>;
var RANGE = <?= (int)$R ?>;
var CH_TXT = { int:'reactions', vis:'profile visits', trk:'new trackers' };
function $(id){ return document.getElementById(id); }
function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function dbClose(){
    if (document.referrer && document.referrer.indexOf(window.location.host) !== -1) history.back();
    else location.href = 'profile.php';
}

/* ── main tabs ── */
function goPanel(p){
    document.querySelectorAll('.mtab').forEach(function(t){ t.classList.toggle('on', t.getAttribute('data-p') === p); });
    document.querySelectorAll('.panel').forEach(function(x){ x.classList.toggle('on', x.id === 'p-' + p); });
    if (history.replaceState) history.replaceState(null, '', location.pathname + location.search + '#' + p);
    var tabs = $('mtabs'), top = tabs.getBoundingClientRect().top + window.pageYOffset - (parseInt(getComputedStyle(document.documentElement).getPropertyValue('--topbar-h')) || 54);
    if (window.pageYOffset > top) window.scrollTo({ top: Math.max(0, top + 1), behavior: 'smooth' });
}
$('mtabs').addEventListener('click', function(e){ var b = e.target.closest('.mtab'); if (b) goPanel(b.getAttribute('data-p')); });

/* ── sparkline + main chart ── */
function spark(v){
    var n = v.length, max = Math.max.apply(null, v.concat([1])), pts = [];
    if (n < 2) return '';
    v.forEach(function(x, i){ pts.push((i / (n - 1) * 100).toFixed(1) + ',' + (25 - x / max * 22).toFixed(1)); });
    var line = pts.join(' ');
    return '<svg viewBox="0 0 100 28" preserveAspectRatio="none"><polygon points="0,28 ' + line + ' 100,28" fill="rgba(255,45,45,.16)"/><polyline points="' + line + '" fill="none" stroke="#FF2D2D" stroke-width="1.6" vector-effect="non-scaling-stroke" stroke-linejoin="round"/></svg>';
}
document.querySelectorAll('.kpi-s').forEach(function(el){ var d = CHART[el.getAttribute('data-s')]; el.innerHTML = d ? spark(d.v) : ''; });
function drawChart(k){
    var d = CHART[k]; if (!d) return;
    var box = $('barChart'), max = Math.max.apply(null, d.v.concat([1])), sum = 0, html = '';
    d.v.forEach(function(v, i){ sum += v; html += '<div class="bar' + (v === 0 ? ' zero' : '') + '" data-tip="' + d.l[i] + ' · ' + v + '" data-h="' + Math.round(v / max * 100) + '" style="height:0"></div>'; });
    box.innerHTML = html; box.classList.toggle('ghost', sum === 0);
    $('chTotal').textContent = sum.toLocaleString();
    $('chLabel').textContent = CH_TXT[k] + ' · last ' + RANGE + ' days';
    var n = d.l.length;
    $('lbA').textContent = d.l[0] || ''; $('lbB').textContent = d.l[Math.floor(n / 2)] || ''; $('lbC').textContent = d.l[n - 1] || '';
    box.querySelectorAll('.bar').forEach(function(b, i){ setTimeout(function(){ b.style.height = b.getAttribute('data-h') + '%'; }, 60 + i * 8); });
}
$('kpis').addEventListener('click', function(e){
    var b = e.target.closest('.kpi'); if (!b || !CHART[b.getAttribute('data-k')]) return;
    this.querySelectorAll('.kpi').forEach(function(t){ t.classList.remove('on'); });
    b.classList.add('on'); drawChart(b.getAttribute('data-k'));
});

/* ── content manager ── */
var ST = { s:'new', t:'a', q:'', shown:10 }, STEP = 10;
function thumb(p){
    if (p.k === 't') return '<span class="th txt"><span>' + esc(p.cap.slice(0, 40)) + '</span></span>';
    if (p.k === 'v') return '<span class="th"><video src="' + esc(p.src) + '#t=0.1" muted playsinline preload="metadata"></video></span>';
    return '<span class="th"><img src="' + esc(p.src) + '" alt="" loading="lazy" onerror="this.src=\'uploads/Text.jpg\'"></span>';
}
function renderPosts(){
    var list = POSTS.filter(function(p){
        if (ST.t !== 'a' && p.k !== ST.t) return false;
        return !ST.q || p.cap.toLowerCase().indexOf(ST.q) > -1;
    });
    list.sort(function(a, b){
        if (ST.s === 'top') return b.sc - a.sc || b.ts - a.ts;
        if (ST.s === 'lk') return b.lk - a.lk || b.ts - a.ts;
        if (ST.s === 'rp') return b.rp - a.rp || b.ts - a.ts;
        if (ST.s === 'fv') return b.fv - a.fv || b.ts - a.ts;
        return b.ts - a.ts;
    });
    var max = 1; POSTS.forEach(function(p){ max = Math.max(max, p.lk + p.rp + p.fv); });
    var box = $('postList');
    if (!list.length) {
        box.innerHTML = '<div class="empty"><i class="ri-image-2-line"></i>' + (POSTS.length ? 'No posts match this filter.' : 'You haven\'t posted yet.<br>Create your first post to see how it performs.') + '</div>';
        $('postMore').style.display = 'none'; return;
    }
    box.innerHTML = list.slice(0, ST.shown).map(function(p){
        var e = p.lk + p.rp + p.fv;
        return '<a class="row" href="upload.php?edit=' + p.id + '">' + thumb(p) +
            '<div class="row-body"><div class="row-t">' + esc(p.cap.trim() ? p.cap.slice(0, 46) : '(no caption)') + '</div>' +
            '<div class="row-s">' + esc(p.ago) + (p.ad ? ' · Ad' : '') + '</div>' +
            '<div class="stats"><span><i class="ri-heart-3-fill"></i>' + p.lk + '</span><span><i class="ri-repeat-line"></i>' + p.rp + '</span><span><i class="ri-bookmark-fill"></i>' + p.fv + '</span></div>' +
            '<div class="ebar"><i style="width:' + Math.max(e ? 4 : 0, Math.round(e / max * 100)) + '%"></i></div></div>' +
            '<span class="mini-btn">Edit</span></a>';
    }).join('');
    var more = list.length - ST.shown;
    $('postMore').style.display = more > 0 ? 'flex' : 'none';
    $('postMore').textContent = 'See more (' + more + ' left)';
}
function bindBar(id, key, attr){
    $(id).addEventListener('click', function(e){
        var b = e.target.closest('.pill'); if (!b) return;
        this.querySelectorAll('.pill').forEach(function(x){ x.classList.remove('on'); });
        b.classList.add('on'); ST[key] = b.getAttribute(attr); ST.shown = STEP; renderPosts();
    });
}
bindBar('sortBar', 's', 'data-s'); bindBar('typeBar', 't', 'data-t');
$('pq').addEventListener('input', function(){ ST.q = this.value.trim().toLowerCase(); ST.shown = STEP; $('pqx').style.display = ST.q ? 'block' : 'none'; renderPosts(); });
$('pqx').addEventListener('click', function(){ $('pq').value = ''; ST.q = ''; this.style.display = 'none'; renderPosts(); });
$('postMore').addEventListener('click', function(){ ST.shown += STEP; renderPosts(); });
renderPosts();

function animateFills(){
    drawChart('int');
    document.querySelectorAll('.progress-fill').forEach(function(f, i){
        var w = f.getAttribute('data-w') + '%'; f.style.width = '0';
        setTimeout(function(){ f.style.width = w; }, 260 + i * 60);
    });
}
(function(){
    var h = (location.hash || '').replace('#', '');
    if (['overview', 'content', 'audience', 'growth'].indexOf(h) > -1 && h !== 'overview') goPanel(h);
    var sk = $('page-skeleton'), t0 = Date.now(), done = false;
    function reveal(){
        if (done) return; done = true;
        setTimeout(function(){
            document.body.classList.add('ready');
            if (sk){ sk.classList.add('hide'); setTimeout(function(){ if (sk.parentNode) sk.remove(); }, 520); }
            animateFills();
        }, Math.max(0, 450 - (Date.now() - t0)));
    }
    if (document.readyState === 'complete') reveal(); else window.addEventListener('load', reveal);
    setTimeout(reveal, 1800);
})();
document.addEventListener('click', function(e){
    var t = e.target.closest ? e.target.closest('.bar, .heat-cell, .cal i') : null;
    document.querySelectorAll('.bar.on, .heat-cell.on, .cal i.on').forEach(function(el){ if (el !== t) el.classList.remove('on'); });
    if (t) t.classList.toggle('on');
});
</script>
</body>
</html>