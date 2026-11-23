<?php
session_start();
include 'config.php';
$current_user = $_SESSION['user_id'] ?? 0;

/* ── CCM DATA (ads + suggested users for discover modal) ── */
if (isset($_GET['type'])) {
    $type = $_GET['type'];
    header('Content-Type: application/json');

    if ($type === 'ads') {
        $ads = [];
        $res = $conn->query("SELECT * FROM sponsorships WHERE status='approved' ORDER BY RAND() LIMIT 10");
        while ($row = $res->fetch_assoc()) { $ads[] = $row; }
        echo json_encode($ads);

    } elseif ($type === 'sugg') {
        $sugg = [];
        if ($current_user) {
            $res = $conn->query("
                SELECT u.id, u.username, u.profile_pic,
                (SELECT COUNT(*) FROM followers f WHERE f.follower_id=$current_user AND f.following_id=u.id) as is_following
                FROM users u
                WHERE u.id != $current_user
                ORDER BY RAND()
                LIMIT 20
            ");
            while ($row = $res->fetch_assoc()) { $sugg[] = $row; }
        }
        echo json_encode($sugg);

    } else {
        echo json_encode([]);
    }
    exit;
}

/* ── TOGGLE FOLLOW ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_follow') {
    header('Content-Type: application/json');
    if (!$current_user) { echo json_encode(['status'=>'error']); exit; }
    $target_id = intval($_POST['user_id']);
    $stmt = $conn->prepare("SELECT id FROM followers WHERE follower_id=? AND following_id=?");
    $stmt->bind_param("ii", $current_user, $target_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $stmt = $conn->prepare("DELETE FROM followers WHERE follower_id=? AND following_id=?");
        $stmt->bind_param("ii", $current_user, $target_id);
        $stmt->execute();
        echo json_encode(['status'=>'ok','following'=>false]);
    } else {
        $stmt = $conn->prepare("INSERT INTO followers (follower_id, following_id) VALUES (?,?)");
        $stmt->bind_param("ii", $current_user, $target_id);
        $stmt->execute();
        echo json_encode(['status'=>'ok','following'=>true]);
    }
    exit;
}

/* ================= SEARCH LOGIC (GROUPS & USERS) ================= */
$search_query = trim($_GET['q'] ?? '');
$groups = [];
$users = [];

if(!empty($search_query)){
    $like = "%$search_query%";

    $stmt3 = $conn->prepare("
        SELECT g.id, g.title, g.group_pic,
        (SELECT COUNT(*) FROM group_members gm WHERE gm.group_id = g.id) AS member_count
        FROM groups g WHERE g.title LIKE ?
    ");
    $stmt3->bind_param("s", $like);
    $stmt3->execute();
    $groups = $stmt3->get_result();

    $stmt = $conn->prepare("
        SELECT id, username, nickname, profile_pic 
        FROM users 
        WHERE (
            username LIKE ? 
            OR (nickname IS NOT NULL AND nickname != '' AND nickname LIKE ?)
        ) 
        AND id != ?
    ");
    $stmt->bind_param("ssi", $like, $like, $current_user);
    $stmt->execute();
    $res = $stmt->get_result();

    while($row = $res->fetch_assoc()){
        $row['is_blocked']  = false;
        $row['is_following'] = false;
        if($current_user){
            $b_check = $conn->query("SELECT 1 FROM blocks WHERE blocker_id=$current_user AND blocked_id=".$row['id']);
            $row['is_blocked'] = $b_check->num_rows > 0;

            $f_check = $conn->query("SELECT 1 FROM followers WHERE follower_id=$current_user AND following_id=".$row['id']);
            $row['is_following'] = $f_check->num_rows > 0;
        }
        $row['display_name'] = (!empty($row['nickname'])) ? $row['nickname'] : $row['username'];
        $users[] = $row;
    }
}

/* ================= DATA PREP (BLOCKS) ================= */
$blocked_users = [];

if($current_user){
    $res = $conn->query("SELECT blocked_id FROM blocks WHERE blocker_id=$current_user");
    while($r = $res->fetch_assoc()){ $blocked_users[] = (int)$r['blocked_id']; }
}

/* ================= AI PERSONALIZATION ENGINE ================= */
$user_top_tags = [];
$user_tag_weights = [];

if($current_user){
    $stmt = $conn->prepare("
        SELECT
            LOWER(TRIM(LEADING '#' FROM
                REGEXP_SUBSTR(m.caption, '#[a-zA-Z0-9_]+')
            )) AS tag,

            SUM(
                CASE ui.interaction_type
                    WHEN 'repost'   THEN 6.0
                    WHEN 'favorite' THEN 4.0
                    WHEN 'like'     THEN 2.0
                    WHEN 'view'     THEN 0.5
                    ELSE 1.0
                END
                *
                EXP(-0.03 * TIMESTAMPDIFF(HOUR, ui.created_at, NOW()))
            ) AS affinity_score,

            COUNT(*) AS interaction_count

        FROM user_interactions ui
        JOIN memes m ON ui.meme_id = m.id
        WHERE ui.user_id = ?
          AND m.caption REGEXP '#[a-zA-Z0-9_]+'
          AND ui.created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)

        GROUP BY tag
        HAVING affinity_score > 0.5
        ORDER BY affinity_score DESC
        LIMIT 10
    ");
    $stmt->bind_param("i", $current_user);
    $stmt->execute();
    $res = $stmt->get_result();
    while($r = $res->fetch_assoc()){
        if(!empty($r['tag'])){
            $user_top_tags[]             = $r['tag'];
            $user_tag_weights[$r['tag']] = round($r['affinity_score'], 4);
        }
    }
}

/* ================= FILTER & ORDERING SYSTEM ================= */
$filter = $_GET['filter'] ?? 'trending';
$tag = $_GET['tag'] ?? 'all';

switch($filter){
    case 'most_liked':    $order_logic = "like_count DESC"; break;
    case 'most_reposted': $order_logic = "repost_count DESC"; break;
    case 'most_favorited':$order_logic = "fav_count DESC"; break;
    case 'most_viewed':   $order_logic = "m.views DESC"; break;
    case 'newest':        $order_logic = "m.created_at DESC"; break;
    case 'following':     $order_logic = "RAND()"; break;
    case 'discover':      $order_logic = "RAND()"; break;
    default:
        $order_logic = "(((like_count * 4.0) + (repost_count * 6.0) + (fav_count * 3.0) + (LOG10(m.views + 1) * 2.0)) / POW(TIMESTAMPDIFF(HOUR, m.created_at, NOW()) + 2, 1.5)) + (RAND() * 0.5) DESC";
        break;
}

/* ================= QUERY BUILDER (N+1 FIX) ================= */
$sql = "SELECT m.*, u.username, u.profile_pic,
       (SELECT COUNT(*) FROM likes WHERE meme_id = m.id) AS like_count,
       (SELECT COUNT(*) FROM reposts WHERE meme_id = m.id) AS repost_count,
       (SELECT COUNT(*) FROM favorites WHERE meme_id = m.id) AS fav_count,
       (SELECT COUNT(*) FROM likes WHERE meme_id = m.id AND user_id = $current_user) AS user_liked,
       (SELECT COUNT(*) FROM reposts WHERE meme_id = m.id AND user_id = $current_user) AS user_reposted,
       (SELECT COUNT(*) FROM favorites WHERE meme_id = m.id AND user_id = $current_user) AS user_fav,
       (SELECT COUNT(*) FROM followers WHERE follower_id = $current_user AND following_id = m.uploaded_by) AS is_following
       FROM memes m JOIN users u ON m.uploaded_by = u.id";

$where = " WHERE 1=1 ";
$params = [];
$types = "";

if($tag !== 'all'){
    $where .= " AND m.caption LIKE ? ";
    $params[] = "%#$tag%"; $types .= "s";
}
if(!empty($search_query)){
    $where .= " AND m.caption LIKE ? ";
    $params[] = "%$search_query%"; $types .= "s";
}
if(!empty($blocked_users)){
    $in = implode(',', array_fill(0, count($blocked_users), '?'));
    $where .= " AND m.uploaded_by NOT IN ($in) ";
    $params = array_merge($params, $blocked_users); $types .= str_repeat('i', count($blocked_users));
}

/* ── 18+ FILTER: hide adult memes unless user is a member of that group ── */
$where .= " AND (
    m.is_adult = 0
    OR EXISTS (
        SELECT 1 FROM group_posts gp
        JOIN group_members gm ON gm.group_id = gp.group_id
        WHERE gp.meme_id = m.id
        AND gm.user_id = $current_user
    )
) ";

if($filter === 'following' && $current_user){
    $where .= " AND m.uploaded_by IN (SELECT following_id FROM followers WHERE follower_id=?) ";
    $params[] = $current_user; $types .= "i";
}

/* ================= FINAL ORDER WITH AI PERSONALIZATION BOOST ================= */
$final_order = " ORDER BY $order_logic";

if($filter === 'trending' && !empty($user_top_tags)){
    $boost_cases = [];
    foreach($user_top_tags as $t){
        $weight     = $user_tag_weights[$t] ?? 1.0;
        $multiplier = min(2.5, round(1.0 + ($weight / 20.0), 3));
        $safe_tag   = $conn->real_escape_string($t);
        $boost_cases[] = "WHEN m.caption LIKE '%#$safe_tag%' THEN $multiplier";
    }
    $case_sql = "CASE " . implode(' ', $boost_cases) . " ELSE 1.0 END";

    $final_order = " ORDER BY (
        (((like_count * 4.0) + (repost_count * 6.0) + (fav_count * 3.0) + (LOG10(m.views + 1) * 2.0))
        / POW(TIMESTAMPDIFF(HOUR, m.created_at, NOW()) + 2, 1.5))
        + (RAND() * 0.5)
    ) * ($case_sql) DESC";
}

$stmt = $conn->prepare($sql . $where . $final_order);
if(!empty($params)){ $stmt->bind_param($types, ...$params); }
$stmt->execute();
$memes_result = $stmt->get_result();

/* ================= PRE-FETCH ALL ROWS & SOCIAL PROOF ================= */
$all_rows = [];
$meme_ids = [];

while($row = $memes_result->fetch_assoc()){
    $all_rows[] = $row;
    $meme_ids[] = $row['id'];
}

$social_proof_map = [];

if($current_user && !empty($meme_ids)){
    $id_list = implode(',', $meme_ids);
    $sp_result = $conn->query("
        SELECT l.meme_id, u.id, u.username, u.profile_pic, 'like' AS action_type
        FROM likes l
        JOIN users u ON u.id = l.user_id
        JOIN followers f ON f.following_id = u.id
        WHERE l.meme_id IN ($id_list) AND f.follower_id = $current_user AND u.id != $current_user

        UNION ALL

        SELECT r.meme_id, u.id, u.username, u.profile_pic, 'repost' AS action_type
        FROM reposts r
        JOIN users u ON u.id = r.user_id
        JOIN followers f ON f.following_id = u.id
        WHERE r.meme_id IN ($id_list) AND f.follower_id = $current_user AND u.id != $current_user
    ");

    while($sp = $sp_result->fetch_assoc()){
        $mid = $sp['meme_id'];
        if(!isset($social_proof_map[$mid])) $social_proof_map[$mid] = [];
        if(count($social_proof_map[$mid]) < 4) $social_proof_map[$mid][] = $sp;
    }
}

$memes = $all_rows;

/* ================= AJAX ACTIONS ================= */
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])){
    header('Content-Type: application/json');
    $action = $_POST['action'];
    $meme_id = isset($_POST['meme_id']) ? (int)$_POST['meme_id'] : 0;

    if($action === 'toggle_follow' && isset($_POST['user_id'])){
        $target = (int)$_POST['user_id'];
        $check = $conn->query("SELECT 1 FROM followers WHERE follower_id=$current_user AND following_id=$target");
        if($check->num_rows){
            $conn->query("DELETE FROM followers WHERE follower_id=$current_user AND following_id=$target");

            // Log the unfollow so the unfollowed person can see it on untracked.php
            $conn->query("INSERT INTO unfollow_log (unfollowed_by, user_id) VALUES ($current_user, $target)");

            echo json_encode(['status'=>'ok','following'=>false]);
        } else {
            $conn->query("INSERT INTO followers (follower_id, following_id) VALUES ($current_user, $target)");

            // Remove from unfollow log since they followed back
            $conn->query("DELETE FROM unfollow_log WHERE unfollowed_by=$current_user AND user_id=$target");

            echo json_encode(['status'=>'ok','following'=>true]);
        }
        exit;
    }

    if($action === 'toggle_group' && isset($_POST['group_id'])){
        $gid = (int)$_POST['group_id'];
        $check = $conn->query("SELECT 1 FROM group_members WHERE group_id=$gid AND user_id=$current_user");
        if($check->num_rows){
            $conn->query("DELETE FROM group_members WHERE group_id=$gid AND user_id=$current_user");
            echo json_encode(['status'=>'ok','joined'=>false]);
        } else {
            $conn->query("INSERT INTO group_members (group_id, user_id) VALUES ($gid, $current_user)");
            echo json_encode(['status'=>'ok','joined'=>true]);
        }
        exit;
    }

if($action === 'toggle_like' && $meme_id){
    $check = $conn->query("SELECT id FROM likes WHERE meme_id=$meme_id AND user_id=$current_user");
    if($check->num_rows){
        $conn->query("DELETE FROM likes WHERE meme_id=$meme_id AND user_id=$current_user");
    } else {
        $conn->query("INSERT INTO likes (meme_id, user_id) VALUES ($meme_id, $current_user)");
    }
    $count = $conn->query("SELECT COUNT(*) as c FROM likes WHERE meme_id=$meme_id")->fetch_assoc()['c'];
    echo json_encode(['status'=>'ok', 'count'=>$count]);
    exit;
}

    if($action === 'toggle_repost' && $meme_id){
        $check = $conn->query("SELECT id FROM reposts WHERE meme_id=$meme_id AND user_id=$current_user");
        if($check->num_rows){
            $conn->query("DELETE FROM reposts WHERE meme_id=$meme_id AND user_id=$current_user");
        } else {
            $conn->query("INSERT INTO reposts (meme_id, user_id) VALUES ($meme_id, $current_user)");
        }
        $count = $conn->query("SELECT COUNT(*) as c FROM reposts WHERE meme_id=$meme_id")->fetch_assoc()['c'];
        echo json_encode(['status'=>'ok', 'count'=>$count]);
        exit;
    }

    if($action === 'toggle_favorite' && $meme_id){
        $check = $conn->query("SELECT id FROM favorites WHERE meme_id=$meme_id AND user_id=$current_user");
        if($check->num_rows){
            $conn->query("DELETE FROM favorites WHERE meme_id=$meme_id AND user_id=$current_user");
        } else {
            $conn->query("INSERT INTO favorites (meme_id, user_id) VALUES ($meme_id, $current_user)");
        }
        $count = $conn->query("SELECT COUNT(*) as c FROM favorites WHERE meme_id=$meme_id")->fetch_assoc()['c'];
        echo json_encode(['status'=>'ok', 'count'=>$count]);
        exit;
    }
}



/* ================= SIDEBAR DATA ================= */
$following = null;

if ($current_user) {
    $stmt = $conn->prepare("
        SELECT u.id, u.username, u.profile_pic
        FROM followers f
        JOIN users u ON f.following_id = u.id
        WHERE f.follower_id = ?
        ORDER BY RAND()
    ");
    $stmt->bind_param("i", $current_user);
    $stmt->execute();
    $following = $stmt->get_result();
}

$all_users = $conn->query("SELECT id, username, profile_pic FROM users WHERE id != $current_user ORDER BY RAND()");
?>
<?php
// ── Total unread messages across all linked accounts ──
$total_unread = 0;
if (!empty($_SESSION['accounts'])) {
    foreach ($_SESSION['accounts'] as $acc_id) {
        $acc_id = (int)$acc_id;
        $unread = $conn->query("
            SELECT COUNT(*) as cnt 
            FROM messages 
            WHERE receiver_id = $acc_id AND seen = 0
        ")->fetch_assoc()['cnt'] ?? 0;
        $total_unread += $unread;
    }
}

$all_tags_raw=['All','Funny','Me','Text','Memes','Gaming','Love','School','Anime','Music','Movies','Sports','Football','Dank','DarkHumor','Wholesome','Cursed','Cringe','Sus','Bruh','LOL','ROFL','Template','Classic','OldButGold','Reaction','Mood','Same','Unexpected','PlotTwist','NoContext','ExplainThis','JustWhy','ImDone','RelatableAF','TooReal','BigMood','Pain','Dead','ICant','SendHelp','ThisAintIt','ItBeLikeThat','WhyTho','LowQuality','HighQuality','DeepFried','Brainrot','NPC','SideEye','Mid','W','L','Rare','Legendary','Throwback'];
$sorted_tags=['All'];
$lower_top=array_map('strtolower',$user_top_tags);
foreach($all_tags_raw as $t){ if($t==='All') continue; if(in_array(strtolower($t),$lower_top)) $sorted_tags[]=$t; }
foreach($all_tags_raw as $t){ if($t==='All') continue; if(!in_array(strtolower($t),$lower_top)) $sorted_tags[]=$t; }
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta charset="UTF-8">
<title>Dezzme | Discover</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<style>
* {
    box-sizing: border-box;
}


*{margin:0;padding:0;box-sizing:border-box;font-family:'Poppins',sans-serif;}
:root {
    --red:        #FF2D2D;
    --red-dim:    rgba(255,45,45,0.18);
    --red-glow:   rgba(255,45,45,0.35);
    --blue:       #0057FF;
    --blue-mid:   #1A3BFF;
    --blue-dim:   rgba(0,87,255,0.14);
    --blue-glow:  rgba(26,59,255,0.3);
    --ink:        #06071A;
    --ink-2:      #0C0E28;
    --ink-3:      #111438;
    --surface:    rgba(255,255,255,0.04);
    --surface-2:  rgba(255,255,255,0.07);
    --border:     rgba(255,255,255,0.07);
    --border-2:   rgba(255,255,255,0.13);
    --text:       #F0F2FF;
    --text-2:     rgba(220,224,255,0.65);
    --text-3:     rgba(180,188,255,0.4);
    --radius-sm:  10px;
    --radius-md:  16px;
    --radius-lg:  22px;
    --radius-xl:  30px;
    --font-head:  'Syne', sans-serif;
    --font-body:  'DM Sans', sans-serif;
    --sidebar-w:  68px;
}

html { scroll-behavior: smooth; }

body {
    background-color: var(--ink);
    background-image:
        radial-gradient(ellipse 80% 60% at 10% 0%, rgba(0,60,255,0.18) 0%, transparent 60%),
        radial-gradient(ellipse 40% 40% at 50% 50%, rgba(0,30,120,0.12) 0%, transparent 70%);
    color: var(--text);
    font-family: var(--font-body);
    min-height: 100vh;
    overflow-x: hidden;
}

/* GLASS EFFECT */
.glass {
    background: transparent;
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    border: transparent;
    border-radius: 20px;
    transition: .35s;
}

/* NAVBAR (Default Desktop View) */
nav{display:flex;justify-content:space-between;align-items:center;padding:20px 40px;position:sticky;top:0;z-index:100;background: transparent;border-radius:30px;backdrop-filter:blur(12px);box-shadow:0 4px 25px rgba(0,0,0,0.4);}

.logo-container {
    display: flex;
    align-items: center;
    gap: 15px;
    cursor: pointer;
    animation: floatUpDown 2s ease-in-out infinite;
}

.logo-container img {
    width: clamp(40px, 6vw, 55px);
    border-radius: 16px;
    transition: .35s;
}

.title {
    font-size: clamp(24px, 4vw, 35px);
    letter-spacing: 6px;
    font-weight: 600;
}

.nav-links {
    display: flex;
    align-items: center;
    gap: clamp(40px, 2vw, 32px);
}

.nav-links a {
    /* Changed fixed width/height to min-content for flexibility */
    min-width: 40px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 10px 18px;
    border-radius: 18px;
    text-decoration: none;
    font-weight: 600;
    font-size: 1rem;
    color: white;
    background: transparent;
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    border: transparent;
    box-shadow:transparent;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

/* Hover effect */
.nav-links a:hover {
    transform: translateY(-3px) scale(1.05);
    background: rgba(255,255,255,0.15);
    box-shadow:
        0 10px 25px rgba(255,255,255,0.2),
        0 0 15px rgba(255,255,255,0.1);
}

/* Click effect */
.nav-links a:active {
    transform: scale(0.95);
    box-shadow: inset 0 4px 10px rgba(0,0,0,0.4);
}

/* Icon animation */
.nav-links a i {
    transition: transform 0.3s ease;
}

.nav-links a:hover i {
    transform: scale(1.2) rotate(5deg);
}

/* shine sweep */
.nav-links a::before {
    content: "";
    position: absolute;
    top: 0;
    left: -80%;
    width: 50%;
    height: 100%;
    background: linear-gradient(120deg, transparent, rgba(255,255,255,0.4), transparent);
    transform: skewX(-20deg);
    transition: 0.6s;
}

.nav-links a:hover::before {
    left: 120%;
}

/* 📱 PHONE VIEW (Instagram-Style Tab Bar) */
@media (max-width: 768px) {

    .glass {
        background: rgba(3, 4, 21, 0.97);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        border: transparent;
        border-radius: 10px;
        transition: .35s;
    }

    nav {
        position: fixed;
        top: auto;
        bottom: 0;
        left: 0;
        right: 0;
        height: 60px;
        padding: 0;
        background: rgba(3, 4, 21, 0.97);
        border-radius: 10px;
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border-top: 1px solid rgba(255, 255, 255, 0.1);
        display: flex;
        justify-content: center;
        z-index: 1000;
    }

    .logo-container {
        display: none;
    }

    .nav-links {
        width: 100%;
        height: 100%;
        display: flex;
        justify-content: space-evenly;
        align-items: center;
        gap: 0;
    }

    .nav-links a {
        background: transparent !important;
        border: none !important;
        box-shadow: none !important;
        backdrop-filter: none !important;
        flex: 1;
        height: 100%;
        display: flex;
        justify-content: center;
        align-items: center;
        position: relative;
        transition: opacity 0.2s ease;
        -webkit-tap-highlight-color: transparent;
    }

    .nav-links a i {
        font-size: 24px;
        color: #fff;
        font-weight: normal;
    }

    .nav-links a span {
        display: none;
    }

    .nav-links a.notif::after {
        content: '';
        position: absolute;
        top: 18px;
        right: calc(50% - 15px);
        width: 6px;
        height: 6px;
        background: #ff0000;
        border-radius: 50%;
        border: 2px solid #000;
    }

    .nav-links a.active i {
        color: #fff;
    }

    .nav-links a:active {
        transform: scale(0.9);
        opacity: 0.6;
    }

    body {
        padding-bottom: 70px;
    }

    /* ── FOLLOW BAR (MOBILE / TAB FIX) ── */
.follow-bar {
    margin-top: clamp(20px, 6vw, 60px);   /* pushes it LOWER properly */
    margin-bottom: clamp(15px, 3vw, 30px);

    padding: clamp(10px, 2.5vw, 16px) clamp(12px, 3vw, 20px);
    gap: clamp(14px, 3vw, 22px);

    display: flex;
    align-items: center;
    overflow-x: auto; /* prevents squeezing on small screens */
    scroll-snap-type: x mandatory;
}

/* each user item stays stable */
.follow-user {
    flex-shrink: 0;
}

/* profile image scaling */
.follow-user img {
    width: clamp(50px, 12vw, 65px);
    height: clamp(50px, 12vw, 65px);
    object-fit: cover;
    border-radius: 50%;
}

/* username text */
.follow-user p {
    font-size: clamp(12px, 2.8vw, 14px);
    white-space: nowrap;
}

/* more button */
.follow-more {
    width: clamp(46px, 11vw, 60px);
    height: clamp(46px, 11vw, 60px);
    font-size: clamp(22px, 5vw, 30px);
    flex-shrink: 0;
}

    /* ── CONTAINER ── */
    .container {
        margin: clamp(16px, 4vw, 40px) auto;
        padding: 0 clamp(10px, 3vw, 15px);
    }


    /* ── DISCOVER GRID ── */
    @media (max-width: 768px) {

    .discover-grid {
        width: 100%;
        margin: 0;
        padding: 0 12px; 
        gap: 8px;
        grid-template-columns: repeat(2, 1fr);
    }
}

    .trending {
        grid-column: span 2;
        grid-row: span 1;
    }

    /* ── TRACK BTN ── */
    .track-btn {
        margin-left: clamp(6px, 2vw, 50px);
        padding: clamp(3px, 1vw, 4px) clamp(8px, 2vw, 10px);
        width: auto;
        font-size: clamp(10px, 2.5vw, 11px);
    }

    /* ── TAG PILLS ── */
     .tag-pill {
        padding: 4px 8px;        /* tighter pill */
        font-size: 0.65rem;     /* smaller text */
        border-radius: 999px;
        letter-spacing: 0.1px;
        line-height: 1;
    }

    /* ── ALL USERS ── */
    .all-user img {
        width: clamp(52px, 13vw, 70px);
        height: clamp(52px, 13vw, 70px);
    }

    .all-user p {
        font-size: clamp(11px, 2.5vw, 13.5px);
        max-width: clamp(55px, 14vw, 75px);
    }

    /* ── NEW TAG ── */
    .peura-new-tag {
        bottom: clamp(52px, 13vw, 72px);
        font-size: clamp(8px, 2vw, 10px);
        padding: 2px clamp(5px, 1.5vw, 8px);
    }

    /* ── BOTTOM LOGO ── */
    .bottom-logo {
        bottom: clamp(70px, 15vw, 90px); /* sits above the tab bar */
        right: clamp(12px, 3vw, 25px);
        width: clamp(48px, 10vw, 65px);
    }

    /* ── MEME OVERLAY ── */
    .meme-overlay {
        padding: clamp(6px, 2vw, 10px);
    }

    .meme-user img {
        width: clamp(22px, 5vw, 28px);
        height: clamp(22px, 5vw, 28px);
    }

    .meme-user a {
        font-size: clamp(11px, 2.5vw, 13px);
    }

    .caption {
        font-size: clamp(10px, 2.2vw, 12px);
    }

    /* ── MEME BUTTONS ── */
    .meme-btn {
        padding: clamp(4px, 1vw, 6px);
        min-width: clamp(34px, 8vw, 42px);
        height: clamp(26px, 6vw, 32px);
        font-size: clamp(11px, 2.5vw, 14px);
        border-radius: clamp(8px, 2vw, 12px);
    }

    .meme-btn i {
        font-size: clamp(11px, 2.5vw, 13px);
    }

    .meme-btn .count {
        font-size: clamp(10px, 2.2vw, 12px);
    }
}

/* ── VERY SMALL (≤ 380px) ── */
@media (max-width: 380px) {
    .discover-grid {
        padding: 0 6px;
        gap: 6px;
    }

    .trending {
        grid-column: span 1;
    }

    .search-box input {
        font-size: 0.78rem;
    }

    .tag-pill {
        padding: 3px 7px;
        font-size: 0.6rem;
    }
}

@media (max-width: 768px) {

    /* REMOVE SIDE SPACE */
    body {
        padding-left: 0;
        padding-right: 0;
    }

    .container {
        padding-left: 0 !important;
        padding-right: 0 !important;
        margin-left: 0;
        margin-right: 0;
        width: 100%;
    }
}

/* =========================
   SEARCH RESULT ROWS
========================= */

.user-result-row,
.group-result-row {
    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 12px;

    padding: 10px 14px;
    margin: 0 10px 12px;

    border-radius: 18px;

    box-sizing: border-box;
    width: calc(100% - 20px);
}


/* =========================
   USERNAME + GROUP TITLE
========================= */

.user-result-info .username,
.group-result-row span > div > span {
    font-size: clamp(12px, 2.8vw, 14px) !important;
    line-height: 1.2;
}

.user-result-info .nickname {
    font-size: clamp(10px, 2.3vw, 12px) !important;
    opacity: 0.85;
}


/* =========================
   TRACK + JOIN BUTTONS
========================= */

.user-result-row .follow-btn,
.group-result-row .group-btn {
    padding: 5px 10px !important;
    font-size: clamp(10px, 2.4vw, 12px) !important;

    border-radius: 12px;

    min-height: 30px;
    min-width: 58px;

    line-height: 1;
}


/* =========================
   PROFILE + GROUP IMAGES
========================= */

.user-result-row img,
.group-result-row img {
    width: 46px;
    height: 46px;
    border-radius: 50%;
    flex-shrink: 0;
    object-fit: cover;
}


/* =========================
   TABLET
========================= */
@media (max-width: 768px) {

    .user-result-row,
    .group-result-row {
        padding: 9px 12px;
        margin: 0 12px 10px;
        gap: 10px;
    }

    .user-result-info .username,
    .group-result-row span > div > span {
        font-size: 12px !important;
    }

    .user-result-info .nickname {
        font-size: 10px !important;
    }

    .user-result-row .follow-btn,
    .group-result-row .group-btn {
        padding: 4px 9px !important;
        font-size: 11px !important;

        min-height: 28px;
        min-width: 54px;
    }

    .user-result-row img,
    .group-result-row img {
        width: 42px;
        height: 42px;
    }
}


/* =========================
   PHONE
========================= */
@media (max-width: 480px) {

    .user-result-row,
    .group-result-row {
        padding: 8px 10px;
        margin: 0 10px 9px;

        gap: 8px;

        border-radius: 14px;
    }

    .user-result-info .username,
    .group-result-row span > div > span {
        font-size: 11px !important;
    }

    .user-result-info .nickname {
        font-size: 9px !important;
    }

    .user-result-row .follow-btn,
    .group-result-row .group-btn {
        padding: 4px 8px !important;
        font-size: 10px !important;

        min-height: 26px;
        min-width: 50px;

        border-radius: 10px;
    }

    .user-result-row img,
    .group-result-row img {
        width: 38px;
        height: 38px;
    }
}


/* =========================
   VERY SMALL PHONES
========================= */
@media (max-width: 360px) {

    .user-result-row,
    .group-result-row {
        padding: 8px 10px;
        margin: 0 10px 9px;

        gap: 8px;

        border-radius: 14px;
    }

    .user-result-info .username,
    .group-result-row span > div > span {
        font-size: 11px !important;
    }

    .user-result-info .nickname {
        font-size: 9px !important;
    }

    .user-result-row .follow-btn,
    .group-result-row .group-btn {
        padding: 4px 8px !important;
        font-size: 10px !important;

        min-height: 26px;
        min-width: 50px;

        border-radius: 10px;
    }

    .user-result-row img,
    .group-result-row img {
        width: 38px;
        height: 38px;
    }
}

/* USERS + COMMUNITIES HEADINGS */
.search-section-title,
h3 {
    padding-left: 14px;
    margin-bottom: 12px;
    box-sizing: border-box;
}


/* 📱 TABLET */
@media (max-width: 768px) {

    .search-section-title,
    h3 {
        padding-left: 12px;
        margin-bottom: 10px;
    }
}


/* 📱 PHONE */
@media (max-width: 480px) {

    .search-section-title,
    h3 {
        padding-left: 10px;
        margin-bottom: 9px;

        font-size: 15px;
    }
}


/* 📱 VERY SMALL */
@media (max-width: 360px) {

    .search-section-title,
    h3 {
        padding-left: 8px;
        font-size: 14px;
    }
}

/* ═══════════════════════════════════════════
   EVERYTHING BELOW = UNCHANGED FROM ORIGINAL
   ═══════════════════════════════════════════ */

@keyframes floatUpDown {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-5px); }
}

/* PARTICLES */
canvas{position:fixed;top:0;left:0;z-index:-1;}

/* FOLLOW BAR */
.follow-bar{display:flex;overflow-x:auto;padding:15px 20px;gap:22px;margin-bottom:20px;}

.follow-user {
    text-align: center;
    flex: 0 0 auto;
    transition: transform 0.3s ease;
    margin-bottom:3px;
}

.follow-user img {
    width: 70px;
    height: 70px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid rgba(238, 10, 10, 0.99);
    background: rgba(255,255,255,0.05);
    backdrop-filter: blur(6px);
    transition: all 0.3s ease;
}

.follow-user img:hover {
    transform: scale(1.15) rotate(-2deg);
    cursor: pointer;
}

.follow-user:hover {
    transform: translateY(-4px);
}


.follow-user p{font-size:14px;color:white;margin-top:5px;}

.follow-more {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 30px;
    cursor: pointer;
    color: white;
    background:rgba(103, 101, 101, 0.19);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    border:rgba(103, 101, 101, 0.69);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.follow-more:hover {
    transform: scale(1.15) rotate(-2deg);
    cursor: pointer;
}

.follow-more:active {
    transform: scale(0.95);
    box-shadow: inset 0 4px 10px rgba(0,0,0,0.4);
}

.follow-more:hover::before {
    left: 120%;
}

.all-users{display:none;overflow-x:auto;white-space:nowrap;padding:20px;}

.all-user {
    display: inline-block;
    text-align: center;
    margin-right: 22px;
    transition: 0.3s ease;
}

.all-user img {
    width: 70px;
    height: 70px;
    border-radius: 50%;
    border: 3px solid #FF4C4C;
    object-fit: cover;
    transition: 0.3s ease;
}

.all-user p {
    font-size: 13.5px;
    color: rgba(255,255,255,0.9);
    margin-top: 6px;
    font-weight: 500;
    letter-spacing: 0.4px;
    max-width: 75px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    transition: 0.3s ease;
}

.all-user:hover img {
    transform: scale(1.08);
    box-shadow: 0 0 18px rgba(255,76,76,0.6);
}

.all-user p{font-size:13px;}

.container{max-width:1200px;margin:40px auto;padding:0 15px;}


.discover-grid{display:grid;grid-template-columns:2fr 2fr 1fr 1fr 1fr;grid-auto-rows:220px;gap:12px;margin-top:30px;  padding: 0 12px;}
.trending{grid-column:span 2;grid-row:span 2;}

.meme-tile{position:relative;overflow:hidden;border-radius:20px;background:rgba(255,255,255,0.05);cursor:pointer;}
.meme-tile img,.meme-tile video{width:100%;height:100%;object-fit:cover;}

.meme-btn{
    position:absolute;
    display:flex;
    background:transparent;
    align-items:center;
    justify-content:center;
    gap:6.2px;
    padding:6px 6px;
    min-width:42px;
    height:32px;
    border-radius:12px;
    background:transparent;
    color:white;
    font-size:14px;
    cursor:pointer;
    transition:0.2s ease;
    z-index:5;
}

.meme-btn i{
    font-size:13px;
    line-height:1;
    color:white;
    transition:0.25s ease;
}

.meme-btn .count{
    font-size:12px;
    font-weight:600;
    color:#ffffff;
}

.meme-btn.active{
    background:rgba(64,61,238,0.18);
}

.meme-btn.active i{
    color:#ff2b55;
}

.meme-btn:hover{
    transform:scale(1.05);
}

.meme-btn.left{left:8px;}
.meme-btn.right{right:8px;}

.meme-btn[style*="left:0px"],
    .meme-btn[style*="left:0"] {
        left: 6px !important;
    }
    .meme-btn[style*="left:53px"] {
        left: 58px !important;
    }
    .meme-btn[style*="left:106px"] {
        left: 110px !important;
    }

.meme-overlay{position:absolute;bottom:0;width:100%;padding:10px;background:rgba(0,0,0,0.45);backdrop-filter:blur(10px);opacity:0;transition:0.3s;}
.meme-tile:hover .meme-overlay{opacity:1;}
.meme-user{display:flex;align-items:center;gap:8px;margin-top:5px;}
.meme-user img{width:28px;height:28px;border-radius:50%;border:0 0 12px rgb(239, 27, 27);}
.meme-user a{color:white;font-size:13px;text-decoration:none;}
.caption{font-size:12px;color:#eee;}

.bottom-logo{position:fixed;bottom:25px;right:25px;width:65px;border-radius:18px;cursor:pointer;z-index:200;transition:0.4s;filter:drop-shadow(0 10px 25px rgba(0,0,0,0.5));animation:logoFloat 5s ease-in-out infinite;}
.bottom-logo:hover{transform:scale(1.15) rotate(-3deg);box-shadow:0 0 30px rgba(255,255,255,0.6);}
@keyframes logoFloat{0%{transform:translateY(0);}50%{transform:translateY(-12px);}100%{transform:translateY(0);}}

footer{background:rgba(255,255,255,0.1);padding:20px;text-align:center;margin-top:30px;}

#particles {
    position: fixed;
    width: 100%;
    height: 100%;
    overflow: hidden;
    top: 0;
    left: 0;
    z-index: -1;
}

.particle {
    position: absolute;
    background: white;
    border-radius: 50%;
    pointer-events: none;
    box-shadow: 0 0 10px rgba(255,255,255,0.6);
    animation: floatParticle linear infinite;
}

@keyframes floatParticle {
    0% { transform: translate(0, 0) scale(1); opacity: 0; }
    10% { opacity: 1; }
    50% { transform: translate(var(--x-move), var(--y-move)) scale(1.2); }
    100% { transform: translate(calc(var(--x-move) * -1), calc(var(--y-move) * -1)) scale(0.8); opacity: 0; }
}

.follow-btn {
    background: rgba(255, 255, 255, 0.15);
    border: none;
    color: white;
    padding: 6px 14px;
    border-radius: 25px;
    cursor: pointer;
    transition: all 0.3s ease;
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    font-weight: 600;
    position: relative;
    overflow: hidden;
    min-width: 80px;
}
.follow-btn:hover {
    background: #FF4C4C;
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 10px 25px rgba(255,76,76,0.3), 0 0 15px rgba(255,255,255,0.1);
}
.follow-btn:active {
    transform: scale(0.95);
    box-shadow: inset 0 4px 10px rgba(0,0,0,0.4);
}
.follow-btn.following {
    color: #FF4C4C;
    border: 2px solid #FF4C4C;
}
.follow-btn.following:hover {
    background: #FF4C4C;
    color: white;
}
.follow-btn::before {
    content: "";
    position: absolute;
    top: 0;
    left: -80%;
    width: 50%;
    height: 100%;
    background: linear-gradient(120deg, transparent, rgba(255,255,255,0.4), transparent);
    transform: skewX(-20deg);
    transition: 0.6s;
}
.follow-btn:hover::before {
    left: 120%;
}

.group-btn {
    background: rgba(255, 255, 255, 0.15);
    border: none;
    color: white;
    padding: 6px 14px;
    border-radius: 25px;
    cursor: pointer;
    transition: all 0.3s ease;
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    font-weight: 600;
    position: relative;
    overflow: hidden;
    min-width: 80px;
}
.group-btn:hover {
    background: #0673f0ff;
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 10px 25px rgba(6,115,240,0.3), 0 0 15px rgba(255,255,255,0.1);
}
.group-btn:active {
    transform: scale(0.95);
    box-shadow: inset 0 4px 10px rgba(0,0,0,0.4);
}
.group-btn.joined {
    color: #0673f0ff;
    border: 2px solid #0673f0ff;
}
.group-btn.joined:hover {
    background: #0673f0ff;
    color: white;
}
.group-btn::before {
    content: "";
    position: absolute;
    top: 0;
    left: -80%;
    width: 50%;
    height: 100%;
    background: linear-gradient(120deg, transparent, rgba(255,255,255,0.4), transparent);
    transform: skewX(-20deg);
    transition: 0.6s;
}
.group-btn:hover::before {
    left: 120%;
}

.track-btn {
    margin-left: 50px;
    margin-top: 2px;
    padding: 4px 10px;
    width: 65px;
    border-radius: 30px;
    border: none;
    font-size: 11px;
    font-weight: 500;
    background: rgba(255, 43, 43, 0.51);
    color: white;
    cursor: pointer;
    transition: 0.3s;
}
.track-btn.following {
    background: transparent;
    border: 2px solid rgba(255, 43, 43, 0.51);
    color: #ff2b2b;
}
.track-btn:hover {
    background: #ff2b2b;
    color: white;
    transform: scale(1.05);
}

.tag-scrollbar {
    display: flex;
    gap: 10px;
    padding: 10px;
    overflow-x: auto;
    white-space: nowrap;
    scrollbar-width: none;
}

.tag-scrollbar::-webkit-scrollbar {
    display: none;
}

.tag-pill {
    flex-shrink: 0;
    padding: 10px 16px;
    border-radius: 999px;
    background: transparent;
    border: 1px solid rgba(255,255,255,0.18);
    color: #ffffff;
    border-radius: 70px;
    font-size: 0.85rem;
    font-weight: 500;
    letter-spacing: 0.3px;
    text-decoration: none;
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    box-shadow: 0 4px 18px rgba(0,0,0,0.12);
    transition: all 0.25s ease;
    position: relative;
    overflow: hidden;
}

.tag-pill::before {
    content: "";
    position: absolute;
    top: 0;
    left: -120%;
    width: 120%;
    height: 100%;
    background: linear-gradient(120deg, transparent, rgba(255,255,255,0.25), transparent);
    transition: 0.6s ease;
}

.tag-pill:hover {
    transform: translateY(-2px) scale(1.07);
    background: rgba(255,255,255,0.18);
    border: 1px solid rgba(242,68,85,0.7);
    color: #ffffff;
}

.tag-pill:hover::before {
    left: 120%;
}

.tag-pill.active {
    background: linear-gradient(135deg, #ff2a2a, #ff4d5a);
    border: 1px solid rgba(255,255,255,0.25);
    color: white;
    border-radius: 12px;
    box-shadow:
        0 4px 15px rgba(255,42,42,0.35),
        0 0 20px rgba(255,42,42,0.25) inset;
    z-index: 1;
}

.tag-pill.active::after {
    content: "";
    position: absolute;
    inset: 0;
    border-radius: 12px;
    box-shadow: 0 0 20px rgba(255,42,42,0.6);
    animation: redPulse 2s ease-in-out infinite;
    z-index: -1;
}

@keyframes redPulse {
    0% { opacity: 0.35; transform: scale(1); }
    50% { opacity: 0.85; transform: scale(1.05); }
    100% { opacity: 0.35; transform: scale(1); }
}

.peura-new-tag {
    position: absolute;
    bottom: 72px;
    left: 12px;
    background: rgba(255, 255, 255, 0.1);
    backdrop-filter: blur(5px);
    -webkit-backdrop-filter: blur(5px);
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 4px;
    color: #ffffff;
    font-size: 10px;
    font-weight: 800;
    padding: 2px 8px;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    pointer-events: none;
    box-shadow: inset 0 0 5px rgba(255, 255, 255, 0.05);
    text-shadow: 0 0 4px rgba(255, 255, 255, 0.5);
}

.card.is-new {
    border: 1px solid rgba(0, 242, 255, 0.2) !important;
}

.card.is-fading {
    border: 1px solid rgba(255, 255, 255, 0.1) !important;
}

@media (max-width: 1024px) {
    .nav-icon[href="sponsorships.php"] {
        display: none !important;
    }
}

/* =========================
   PEURA NEW TAG RESPONSIVE
   ========================= */

.peura-new-tag{
    background:transparent;
    color:
    #ffffff;
    border:transparent 1px solid;
    padding:4px 10px;
    font-size:0.65rem;
    font-weight:600;
    border-radius:10px;
    letter-spacing:0.5px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
}

/* Tablet */
@media (max-width:768px){
    .peura-new-tag{
        padding:3px 8px;
        font-size:0.65rem;
        border-radius:10px;
    }
}

/* Phone */
@media (max-width:480px){
    .peura-new-tag{
        padding:2px 7px;
        font-size:0.6rem;
        border-radius:10px;
    }
}

/* Very small phones */
@media (max-width:360px){
    .peura-new-tag{
        padding:2px 6px;
        font-size:0.58rem;
        border-radius:12px;
    }
}
 
 .search-box {
    position: relative;
    width: 110%;
    max-width: 1000px;
    margin: 40px auto;
    display: flex;
    align-items: center;
    border-radius: 20px;
    overflow: hidden;
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.1);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    transition: border-color 0.3s, box-shadow 0.3s;
}

.search-box:focus-within {
    border-color: rgba(255,255,255,0.22);
    box-shadow: 0 0 0 4px rgba(255,45,45,0.08), 0 16px 40px rgba(0,0,0,0.4);
}

.search-box-icon {
    padding: 0 6px 0 22px;
    color: rgba(255,255,255,0.35);
    font-size: 18px;
    display: flex;
    align-items: center;
    flex-shrink: 0;
    pointer-events: none;
    transition: color 0.3s;
}

.search-box:focus-within .search-box-icon {
    color: rgba(255,255,255,0.6);
}

.search-box input {
    flex: 1;
    padding: 17px 16px;
    font-size: 0.95em;
    font-family: inherit;
    font-weight: 400;
    letter-spacing: 0.2px;
    color: white;
    background: transparent;
    border: none;
    outline: none;
}

.search-box input::placeholder {
    color: rgba(255,255,255,0.6);
    font-weight: 300;
}

.search-box button {
    margin: 7px;
    width: 70px;
    height: 40px;
    border: none;
    border-radius: 9px;
    cursor: pointer;
    color: white;
    background: #FF2D2D;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    transition: all 0.22s ease;
    -webkit-tap-highlight-color: transparent;
    position: relative;
    overflow: hidden;
}

.search-box button i {
    font-size: 17px;
    display: block;
}

.search-box button::before {
    content: "";
    position: absolute;
    top: 0; left: -80%;
    width: 50%; height: 100%;
    background: linear-gradient(120deg, transparent, rgba(255,255,255,0.25), transparent);
    transform: skewX(-20deg);
    transition: 0.5s;
}

.search-box button:hover {
    background: #ff1a1a;
    box-shadow: 0 6px 20px rgba(255,45,45,0.45);
    transform: scale(1.04);
}
.search-box button:hover::before { left: 120%; }
.search-box button:active { transform: scale(0.96); }

@media (max-width: 768px) {
    .search-box {
        max-width: 100%;
        margin: 14px 13px;
        width: calc(100% - 32px);
        border-radius: 13px;
    }

    .search-box input {
    font-size: clamp(13px, 3.5vw, 15px);
    padding: 7px 12px;
}

.search-box button {
    width: 28px;
    height: 28px;
    border-radius: 5px;
    font-size: 15px;
}
}

@media (max-width: 768px) {
    .meme-btn i {
        font-size: 16px;
    }
    .meme-btn .count {
        font-size: 13px;
        font-weight: 700;
    }
    .meme-btn.left {
        padding: 5px 8px;
        border-radius: 8px;
        top: 8px !important;
    }

    .meme-btn[style*="left:0px"],
    .meme-btn[style*="left:0"] {
        left: 8px !important;
    }
    .meme-btn[style*="left:53px"] {
        left: 61px !important;
    }
    .meme-btn[style*="left:106px"] {
        left: 114px !important;
    }
}
@media (max-width: 480px) {
    .meme-btn i {
        font-size: 11.5px;
    }
    .meme-btn .count {
        font-size: 11px;
    }
    .meme-btn.left {
        padding: 5px 8px;
        border-radius: 8px;
        top: 8px !important;
    }
    .meme-btn[style*="left:0px"],
    .meme-btn[style*="left:0"] {
        left: 8px !important;
    }
    .meme-btn[style*="left:53px"] {
        left: 61px !important;
    }
    .meme-btn[style*="left:106px"] {
        left: 114px !important;
    }
}
@media (max-width: 360px) {
    .meme-btn i {
        font-size: 12px;
    }
    .meme-btn .count {
        font-size: 11px;
    }
    .meme-btn.left {
        top: 6px !important;
    }
    .meme-btn[style*="left:0px"],
    .meme-btn[style*="left:0"] {
        left: 6px !important;
    }
    .meme-btn[style*="left:53px"] {
        left: 58px !important;
    }
    .meme-btn[style*="left:106px"] {
        left: 110px !important;
    }
}

@media (max-width: 768px) {
    a.nav-icon[href="upload.php"] {
        display: none !important;
    }
}

/* PTR SPINNER */
#ptr-indicator{position:fixed;top:0;left:50%;transform:translateX(-50%) translateY(-80px);z-index:99999;display:flex;align-items:center;gap:10px;background:rgba(10,10,30,0.92);border:1px solid rgba(255,45,45,0.3);border-radius:999px;padding:8px 20px 8px 14px;font-size:13px;font-weight:600;color:white;backdrop-filter:blur(20px);box-shadow:0 8px 32px rgba(0,0,0,0.5);transition:transform 0.3s cubic-bezier(0.34,1.56,0.64,1),opacity 0.3s;pointer-events:none;white-space:nowrap;}
#ptr-indicator.visible{transform:translateX(-50%) translateY(16px);}
#ptr-spinner-ring{width:20px;height:20px;border:2px solid rgba(255,45,45,0.3);border-top-color:#FF2D2D;border-radius:50%;animation:spin 0.7s linear infinite;flex-shrink:0;}
@keyframes spin{to{transform:rotate(360deg);}}
</style>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>

<div class="particles-container" id="particles"></div>

<!-- PTR -->
<div id="ptr-indicator">
  <div id="ptr-spinner-ring"></div>
  <span id="ptr-label">Pull to refresh</span>
</div>

<nav>
<div class="logo-container">
<img src="PEURA.jpg" onclick="location.href='index.php'">
 <div class="title">Dezzme<strong style="font-size:9px; color: #f41010" ;>RD</strong></div>
</div>
<div class="nav-links">
        <a class="glass nav-icon" href="home.php"><i class="ri-home-5-line" style="font-size:15px; color:white;"></i></a>
        <a class="glass nav-icon" href="text_feed.php"><i class="ri-send-plane-line" style="font-size:15px; color:white;"></i></a>
        <a class="glass nav-icon" href="search.php"><i class="ri-search-line" style="font-size:15px; color:white;"></i></a>
        <a class="glass nav-icon" href="upload.php"><i class="ri-add-circle-line" style="font-size:29px; color:white;"></i></a>
       <a class="glass nav-icon" href="profile.php" style="position:relative;">
    <i class="ri-user-3-line" style="font-size:15px; color:white;"></i>
    <?php if (!empty($total_unread) && $total_unread > 0): ?>
    <span style="
        position: absolute;
        top: 10px; right: 8px;
        min-width: 16px;
        height: 16px;
        background: #FF2D2D;
        border-radius: 50px;
        border: 1.5px solid #06071A;
        font-size: 9px;
        font-weight: 700;
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0 3px;
        line-height: 1;
        pointer-events: none;
    "><?php echo $total_unread > 99 ? '99+' : $total_unread; ?></span>
    <?php endif; ?>
</a>
       <a class="glass nav-icon" href="community.php"><i class="ri-team-line" style="font-size:15px; color:white;"></i></a>
        <a class="glass nav-icon" href="sponsorships.php"><i class="ri-megaphone-line"  style="font-size:15px; color:white;"></i></a>
    </div>
</nav>

<div class="container">

<div class="header-controls-wrapper" style="position: relative; margin-top: 50px; padding: 0 15px;">

    <div class="kebab-container">

        <div class="title">Dezzme<span style="font-size:10px; color: #f41010" ;>RD</span></div>

        <button class="kebab-btn"><i class="ri-menu-3-line"></i></button>

        <div class="kebab-dropdown">
            <a href="?filter=trending"><i class="ri-fire-line"></i> Trending</a>
            <a href="?filter=most_liked"><i class="ri-heart-3-line"></i> Most Liked</a>
            <a href="?filter=most_reposted"><i class="ri-repeat-line"></i> Most Reposted</a>
            <a href="?filter=most_favorited"><i class="ri-bookmark-line"></i> Most Favorited</a>
            <a href="?filter=most_viewed"><i class="ri-eye-line"></i> Most Viewed</a>
            <a href="?filter=newest"><i class="ri-time-line"></i> Newest</a>
            <a href="?filter=following"><i class="ri-user-follow-line"></i> Tracking</a>
            <a href="?filter=followers"><i class="ri-user-star-line"></i> Trackers</a>
        </div>
    </div>

</div>

<!-- FOLLOW BAR -->
<div class="follow-bar">
<div class="follow-more" onclick="toggleUsers()" style="cursor:pointer;">+</div>

<?php if($following && $following->num_rows > 0): ?>
<?php while($f = $following->fetch_assoc()): ?>
<?php
$defaultPic = 'uploads/mee.jpeg';
$pic = !empty($f['profile_pic']) ? $f['profile_pic'] : $defaultPic;

$uid = (int)$f['id'];

// New post in last 24 hours
$recent = $conn->query("SELECT id FROM memes WHERE uploaded_by=$uid AND created_at >= NOW() - INTERVAL 24 HOUR LIMIT 1");
$has_new_post = ($recent->num_rows > 0);
?>
<div class="follow-user">
    <a href="profile.php?user_id=<?php echo $f['id']; ?>" style="position:relative; display:inline-block;">
        <img src="<?php echo htmlspecialchars($pic); ?>"
             onerror="this.src='<?php echo $defaultPic; ?>';">

        <?php if($has_new_post): ?>
        <!-- New post icon — top right -->
        <span style="
            position:absolute;
            top:0; right:0;
            width:18px; height:18px;
            background:#000000;
            border-radius:50%;
            border:2px solid #06071A;
            display:flex;
            align-items:center;
            justify-content:center;
            z-index:2;
        ">
            <i class="ri-image-fill" style="font-size:10px; color:#FF2D2D; line-height:1;"></i>
        </span>
        <?php endif; ?>

        <p>
            <svg xmlns="http://www.w3.org/2000/svg" width="8" height="8" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="12" fill="#FF0000"/>
                <path d="M9 12l2 2 4-4" stroke="white" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <?php echo $f['username']; ?>
        </p>
    </a>
</div>
<?php endwhile; ?>
<?php endif; ?>

</div>
<!-- ALL USERS -->
<div id="allUsers" class="all-users" style="
    display: none;
    flex-direction: row;
    align-items: flex-start;
    gap: clamp(20px, 5vw, 35px);
    overflow-x: auto;
    white-space: nowrap;
    padding: 0 12px;
    padding: clamp(10px, 3vw, 15px);
    width: 100%;
    scrollbar-width: none;
    -ms-overflow-style: none;
    -webkit-overflow-scrolling: touch;
">

<?php
$all_users->data_seek(0);
while($u = $all_users->fetch_assoc()):
    if($u['id'] == $current_user) continue;
    $defaultPic = 'uploads/mee.jpeg';
    $upic = !empty($u['profile_pic']) ? $u['profile_pic'] : $defaultPic;
    $uid = (int)$u['id'];

    $chk = $conn->query("SELECT 1 FROM followers WHERE follower_id=$current_user AND following_id=$uid");
    $is_following = ($chk->num_rows > 0);
    $followText  = $is_following ? 'Untrack' : 'Track';
    $followClass = $is_following ? 'following' : '';

    // Check if private profile
    $priv_chk    = $conn->query("SELECT is_fully_private FROM user_settings WHERE user_id=$uid LIMIT 1");
    $priv_row    = $priv_chk ? $priv_chk->fetch_assoc() : null;
    $su_is_private = !empty($priv_row['is_fully_private']);

    // Show button: public = always | private + following = Untrack | private + not following = hide
    $show_btn = !$su_is_private || $is_following;

    // New post in last 24 hours
    $recent = $conn->query("SELECT id FROM memes WHERE uploaded_by=$uid AND created_at >= NOW() - INTERVAL 24 HOUR LIMIT 1");
    $has_new_post = ($recent->num_rows > 0);
?>
    <div style="
        display: inline-flex;
        flex-direction: column;
        align-items: center;
        flex: 0 0 auto;
        width: clamp(70px, 18vw, 85px);
        color: white;
    ">
        <a href="profile.php?user_id=<?php echo $uid; ?>" style="text-decoration:none;color:white;display:flex;flex-direction:column;align-items:center;position:relative;">

            <img src="<?php echo htmlspecialchars($upic); ?>"
                 onerror="this.src='<?php echo $defaultPic; ?>';"
                 style="
                    width: clamp(52px, 13vw, 65px);
                    height: clamp(52px, 13vw, 65px);
                    border-radius: 50%;
                    border:2px solid red;
                    object-fit: cover;
                    transition: 0.3s;
                 "
                 onmouseover="this.style.transform='scale(1.1)'"
                 onmouseout="this.style.transform='scale(1)'">

            <?php if($has_new_post): ?>
            <span style="
                position:absolute;
                top:0; right:0;
                width:18px; height:18px;
                background:#000000;
                border-radius:50%;
                border:2px solid #06071A;
                display:flex;
                align-items:center;
                justify-content:center;
                z-index:2;
            ">
                <i class="ri-image-fill" style="font-size:10px; color:#FF2D2D; line-height:1;"></i>
            </span>
            <?php endif; ?>

            <span style="font-size:clamp(0.6rem, 2vw, 0.7rem);margin-top:8px;opacity:0.9;display:flex;align-items:center;gap:4px;text-align:center;white-space:normal;word-break:break-word;">
                <svg xmlns="http://www.w3.org/2000/svg" width="9" height="9" viewBox="0 0 24 24">
                    <circle cx="12" cy="12" r="12" fill="#FF0000"/>
                    <path d="M9 12l2 2 4-4" stroke="white" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <?php echo htmlspecialchars($u['username']); ?>
            </span>
        </a>

        <?php if($show_btn): ?>
        <button class="track-btn <?php echo $followClass; ?>"
                data-user="<?php echo $uid; ?>"
                style="margin-top:8px;margin-left:0;font-size:clamp(0.6rem, 2vw, 0.65rem);padding:4px 10px;min-width:0;width:100%;">
            <?php echo $followText; ?>
        </button>
        <?php endif; ?>

    </div>
<?php endwhile; ?>
</div>

<style>

 /* ─── TITLE STYLING ──────────────────────────────────────── */
.kebab-container .title {
    /* Branding specific styles */
    font-size: 24px;
    letter-spacing: 4px;
    font-weight: 700;
    color: #fff;
    
    /* Layout logic: Push buttons to the far right */
    margin-right: auto; 
    padding-left: 20px;
    
    /* Default: Hidden on Desktop */
    display: none;
}

/* 📱 PHONE VIEW (Show Title & Layout) */
@media (max-width: 768px) {
    .kebab-container {
        display: flex;
        align-items: center;
        width: 100%; /* Ensure container spans the full width */
        padding: 0 15px;
    }

    .kebab-container .title {
        /* Show only on mobile */
        display: block;
    }
}

/* Ensure container allows side-by-side alignment */
.kebab-container {
    display: flex;
    align-items: center;
}

/* ── KEBAB CONTAINER ── */
.kebab-container {
    position: absolute;
    top:-49px;
    right:-10px;
    margin-top: 10px;
}

.kebab-btn {
    background: transparent;
    border: transparent;
    color: #fff;
    font-size: 22px;
    padding: 8px 14px;
    border-radius: 18px;
    cursor: pointer;
    backdrop-filter: blur(12px);
    transition: all 0.3s ease;
    /* big touch target on mobile */
    min-width: 44px;
    min-height: 44px;
    display: flex;
    align-items: center;
    justify-content: center;
    -webkit-tap-highlight-color: transparent;
    font-size: clamp(18px, 5vw, 22px);
}

.kebab-btn:hover { color: #ff2a2a; }

/* 📱 TABLET + MOBILE */
@media (max-width: 768px){
    .kebab-container{
    position: relative;
    top: 0;
    right: auto;
    margin-top: 0;
    display: flex;
    align-items: center;
    width: 100%;
}

    .kebab-btn{
        margin-left: auto !important;
        margin-right: -19px !important; /* pushes closer to right edge */
        flex-shrink: 0;
    }
}

/* 📱 SMALL PHONES */
@media (max-width: 480px){

    .kebab-btn{
        margin-right: -19px !important;
    }
}

/* 📱 VERY SMALL */
@media (max-width: 360px){

    .kebab-btn{
        margin-right: -19px !important;
    }
}

.kebab-dropdown {
    display: none;
    position: absolute;
    top: 40px;
    right: 30px;           /* anchors to RIGHT so it never bleeds off screen */
    left: auto;
    background:rgba(255, 255, 255, 0.08);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border-radius: 16px;
    min-width: 190px;
    flex-direction: column;
    z-index: 999;
    box-shadow: 0 8px 32px rgba(0,0,0,0.5);
    border: 1px solid rgba(255,255,255,0.08);
    overflow: hidden;
}

.kebab-dropdown a {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 13px 18px;
    color: #fff;
    text-decoration: none;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.25s ease;
    border-bottom: 1px solid rgba(255,255,255,0.06);
    -webkit-tap-highlight-color: transparent;
}

.kebab-dropdown a:last-child { border-bottom: none; }

.kebab-dropdown a i {
    color: #ff2a2a;
    transition: 0.3s;
    width: 18px;
    text-align: center;
}

.kebab-dropdown a:hover {
    background: rgba(255,255,255,0.07);
    transform: translateX(4px);
    color: #ff2626;
}

.kebab-dropdown a:hover i {
    transform: scale(1.2) rotate(8deg);
}

.kebab-dropdown a.active {
    background: rgba(255,42,42,0.2);
    box-shadow: inset 0 0 10px rgba(255,42,42,0.4);
}

.kebab-dropdown.show {
    display: flex;
    animation: fadeDown 0.25s ease forwards;
}

@keyframes fadeDown {
    0%   { opacity: 0; transform: translateY(-6px); }
    100% { opacity: 1; transform: translateY(0); }
}

/* 📱 TABLET */
@media (max-width: 768px){

    .kebab-dropdown{
        top: 42px;
        min-width: clamp(145px, 38vw, 180px);
        border-radius: 10px;
    }

    .kebab-dropdown a{
        gap: 10px;
        padding: 11px 15px;
        font-size: 13px;
    }

    .kebab-dropdown a i{
        width: 16px;
        font-size: 15px;
    }
}

/* 📱 MOBILE */
@media (max-width: 480px){

    .kebab-dropdown{
        top: 40px;
        min-width: clamp(130px, 36vw, 165px);
        border-radius: 9px;
    }

    .kebab-dropdown a{
        gap: 9px;
        padding: 10px 13px;
        font-size: 12px;
    }

    .kebab-dropdown a i{
        width: 15px;
        font-size: 14px;
    }
}

/* 📱 SMALL DEVICES */
@media (max-width: 360px){

    .kebab-dropdown{
        top: 38px;
        min-width: clamp(120px, 34vw, 150px);
        border-radius: 8px;
    }

    .kebab-dropdown a{
        gap: 8px;
        padding: 9px 11px;
        font-size: 11px;
    }

    .kebab-dropdown a i{
        width: 14px;
        font-size: 13px;
    }
}

/* ── MOBILE KEBAB FIX ── */
@media (max-width: 768px) {
    .kebab-container {
        position: fixed;
        top: calc(env(safe-area-inset-top) + 11px); /* pushes it higher safely */
        right: 12px;
        z-index: 9999;
    }
}

/* ── USER SEARCH RESULTS ── */
.user-result-row {
    display: flex;
    align-items: center;
    gap: clamp(8px, 3vw, 10px);
    margin: 8px 0;
    flex-wrap: nowrap;
}

.user-result-row img {
    width: clamp(48px, 12vw, 65px);
    height: clamp(48px, 12vw, 65px);
    border-radius: 70px;
    border: 2px solid red;
    cursor: pointer;
    flex-shrink: 0;
    object-fit: cover;
}

.user-result-info {
    flex: 1;
    cursor: pointer;
    min-width: 0;
}

.user-result-info .username {
    font-weight: 700;
    font-size: clamp(13px, 3.5vw, 15px);
    color: #fff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.user-result-info .nickname {
    font-size: clamp(10px, 2.5vw, 11px);
    color: #aaa;
    margin-top: 2px;
}

/* ── GROUP RESULT ROW ── */
.group-result-row {
    display: flex;
    align-items: center;
    gap: clamp(8px, 3vw, 10px);
    margin: 8px 0;
    flex-wrap: nowrap;
}

.group-result-row img {
    width: clamp(60px, 15vw, 90px);
    height: clamp(55px, 14vw, 80px);
    border-radius: 12px;
    border: 2px solid blue;
    cursor: pointer;
    flex-shrink: 0;
    object-fit: cover;
}

/* ── DISCOVER GRID RESPONSIVE ── */
@media (max-width: 768px) {
    .discover-grid {
        grid-template-columns: 1fr 1fr;
        grid-auto-rows: clamp(130px, 35vw, 200px);
        gap: clamp(6px, 2vw, 10px);
    }

    .trending {
        grid-column: span 2;
        grid-row: span 1;
    }

    .track-btn {
        margin-left: clamp(4px, 1.5vw, 8px) !important;
        padding: clamp(2px, 0.8vw, 4px) clamp(6px, 1.5vw, 10px) !important;
        width: auto !important;
        font-size: clamp(9px, 2.2vw, 11px) !important;
    }
}

@media (max-width: 380px) {
    .discover-grid {
        grid-template-columns: 1fr;
        grid-auto-rows: clamp(200px, 60vw, 260px);
    }

    .trending {
        grid-column: span 1;
    }
}

/* ── FOLLOW BAR MOBILE ── */
@media (max-width: 768px) {
    .follow-bar {
        padding: clamp(8px, 2vw, 15px) clamp(10px, 3vw, 20px);
        gap: clamp(14px, 3.5vw, 22px);
        margin-bottom: clamp(12px, 3vw, 30px);
    }

    .follow-user img {
    width: clamp(60px, 15vw, 82px);
    height: clamp(60px, 15vw, 82px);
}

    .follow-user p {
        font-size: clamp(10px, 2.5vw, 14px);
    }

    .follow-more {
        width: clamp(44px, 11vw, 60px);
        height: clamp(44px, 11vw, 60px);
        font-size: clamp(20px, 5vw, 30px);
    }
}

@media (max-width: 768px) {
    .follow-bar {
        margin-top: 70px; /* creates breathing room under header */
    }
}

/* ── TAG PILLS MOBILE ── */
@media (max-width: 768px) {
    .tag-pill {
        padding: clamp(7px, 2vw, 10px) clamp(11px, 3vw, 16px);
        font-size: clamp(0.74rem, 2.5vw, 0.85rem);
    }
}


/* ── SECTION HEADERS ── */
@media (max-width: 768px) {
    .container h3 {
        font-size: clamp(14px, 3.5vw, 18px);
        margin: clamp(12px, 3vw, 20px) 0 clamp(6px, 2vw, 10px);
    }
}

/* ── SCREEN TIME BREAK OVERLAY ── */
#breakOverlay {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 999999999;
    background: linear-gradient(135deg, #6c63ff 0%, #4e46cc 50%, #3a34b0 100%);
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 20px 30px;
    overflow: hidden;
    animation: breakFadeIn 0.5s ease forwards;
}

@keyframes breakFadeIn {
    from { opacity: 0; transform: scale(0.97); }
    to   { opacity: 1; transform: scale(1); }
}

#breakOverlay.active {
    display: flex;
}

.break-title {
    font-size: clamp(20px, 4.5vw, 34px);
    font-weight: 700;
    color: white;
    line-height: 1.2;
    margin-bottom: 6px;
    font-family: 'Poppins', sans-serif;
}

.break-subtitle {
    font-size: clamp(12px, 2.5vw, 15px);
    color: rgba(255,255,255,0.75);
    margin-bottom: 16px;
    font-family: 'Poppins', sans-serif;
}

.break-angel {
    font-size: clamp(55px, 12vw, 90px);
    margin-bottom: 16px;
    animation: breakFloat 3s ease-in-out infinite;
    filter: drop-shadow(0 8px 20px rgba(0,0,0,0.2));
    line-height: 1;
}

@keyframes breakFloat {
    0%, 100% { transform: translateY(0px); }
    50%       { transform: translateY(-10px); }
}

.break-code-label {
    font-size: clamp(12px, 2.5vw, 14px);
    color: rgba(255,255,255,0.6);
    margin-bottom: 10px;
    font-family: 'Poppins', sans-serif;
}

.break-code-label strong {
    color: white;
    font-size: clamp(15px, 3.5vw, 19px);
    display: block;
    margin-top: 3px;
    letter-spacing: 6px;
}

.break-input {
    width: clamp(150px, 45vw, 210px);
    padding: 11px 20px;
    border-radius: 50px;
    border: 2px solid rgba(255,255,255,0.3);
    background: rgba(255,255,255,0.12);
    color: white;
    font-size: 20px;
    letter-spacing: 8px;
    text-align: center;
    font-family: 'Poppins', sans-serif;
    outline: none;
    margin-bottom: 10px;
    transition: border 0.25s;
}

.break-input::placeholder {
    color: rgba(255,255,255,0.35);
    letter-spacing: 4px;
    font-size: 13px;
}

.break-input:focus {
    border-color: rgba(255,255,255,0.7);
}

.break-input.shake {
    animation: breakShake 0.35s ease;
    border-color: #ff4444;
}

@keyframes breakShake {
    0%,100% { transform: translateX(0); }
    20%      { transform: translateX(-8px); }
    40%      { transform: translateX(8px); }
    60%      { transform: translateX(-5px); }
    80%      { transform: translateX(5px); }
}

.break-return-btn {
    width: clamp(150px, 45vw, 210px);
    padding: 13px 0;
    border-radius: 50px;
    background: rgba(255,255,255,0.18);
    border: 2px solid rgba(255,255,255,0.3);
    color: white;
    font-size: clamp(13px, 3vw, 15px);
    font-weight: 600;
    cursor: pointer;
    font-family: 'Poppins', sans-serif;
    transition: background 0.25s, transform 0.2s;
    margin-bottom: 14px;
}

.break-return-btn:hover {
    background: rgba(255,255,255,0.28);
    transform: scale(1.03);
}

.break-return-btn:active {
    transform: scale(0.97);
}

.break-motivate {
    font-size: clamp(11px, 2.5vw, 13px);
    color: rgba(255,255,255,0.55);
    font-family: 'Poppins', sans-serif;
    font-style: italic;
    max-width: 260px;
    line-height: 1.4;
}

/* Mobile tight fix */
@media (max-height: 680px) {
    .break-angel { font-size: 45px; margin-bottom: 10px; }
    .break-title { font-size: 18px; }
    .break-subtitle { margin-bottom: 10px; font-size: 12px; }
    .break-code-label { margin-bottom: 8px; }
    .break-input { padding: 9px 16px; margin-bottom: 8px; }
    .break-return-btn { padding: 10px 0; margin-bottom: 10px; }
}

/* TAG BAR */
.tag-bar-wrap{display:flex;align-items:center;gap:8px;padding:0 clamp(16px,4vw,40px);width:100%;max-width:1280px;}
.tag-scroll-btn-desktop{width:32px;height:32px;border-radius:50%;border:1px solid rgba(255,255,255,0.12);background:rgba(255,255,255,0.05);backdrop-filter:blur(10px);color:white;font-size:14px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:0.2s;-webkit-tap-highlight-color:transparent;}
.tag-scroll-btn-desktop:hover{background:rgba(255,45,45,0.7);border-color:transparent;box-shadow:0 0 14px rgba(255,45,45,0.35);}
.tag-scroll-btn-desktop:active{transform:scale(0.9);}
@media(max-width:768px){.tag-scroll-btn-desktop{display:none!important;}}
.tag-scrollbar-wrapper{flex:1;overflow:hidden;}
.tag-scrollbar{display:flex;gap:10px;padding:10px;overflow-x:auto;white-space:nowrap;scrollbar-width:none;-webkit-overflow-scrolling:touch;}
.tag-scrollbar::-webkit-scrollbar{display:none;}
.tag-scrollbar>a{display:inline-block;}
.tag-pill{flex-shrink:0;padding:clamp(8px,2vw,10px) clamp(12px,3vw,16px);border-radius:999px;background:transparent;border:1px solid rgba(255,255,255,0.18);color:#fff;font-size:clamp(0.78rem,2.5vw,0.85rem);font-weight:500;letter-spacing:0.3px;text-decoration:none;backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);box-shadow:0 4px 18px rgba(0,0,0,0.12);transition:all 0.25s ease;position:relative;overflow:hidden;-webkit-tap-highlight-color:transparent;}
.tag-pill::before{content:"";position:absolute;top:0;left:-120%;width:120%;height:100%;background:linear-gradient(120deg,transparent,rgba(255,255,255,0.25),transparent);transition:0.6s ease;}
.tag-pill:hover{transform:translateY(-2px) scale(1.07);background:rgba(255,255,255,0.18);border:1px solid rgba(242,68,85,0.7);}
.tag-pill:hover::before{left:120%;}
.tag-pill.active{background:linear-gradient(135deg,#ff2a2a,#ff4d5a);border:1px solid rgba(255,255,255,0.25);color:white;border-radius:12px;box-shadow:0 4px 15px rgba(255,42,42,0.35),0 0 20px rgba(255,42,42,0.25) inset;}
.tag-pill.active::after{content:"";position:absolute;inset:0;border-radius:12px;box-shadow:0 0 20px rgba(255,42,42,0.6);animation:redPulse 2s ease-in-out infinite;z-index:-1;}
.tag-pill.user-affinity:not(.active){border-color:rgba(255,45,45,0.28);color:rgba(255,180,180,0.85);}
@keyframes redPulse{0%{opacity:0.35;transform:scale(1);}50%{opacity:0.85;transform:scale(1.05);}100%{opacity:0.35;transform:scale(1);}}
@media(max-width:768px){.tag-pill{padding:7px 12px;font-size:0.78rem;}}
@media(max-width:480px){.tag-pill{padding:6px 11px;font-size:0.74rem;}}

/* ── DISCOVER SKELETON ── */
.discover-skeleton {
    display: grid;
    grid-template-columns: 2fr 2fr 1fr 1fr 1fr;
    grid-auto-rows: 220px;
    gap: 12px;
    margin-top: 30px;
    padding: 0 12px;
}

.sk-tile {
    border-radius: 20px;
    background: rgba(255,255,255,0.06);
    position: relative;
    overflow: hidden;
}

/* mirror the trending span */
.sk-tile.sk-trending {
    grid-column: span 2;
    grid-row: span 2;
}

/* shimmer sweep */
.sk-tile::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(
        105deg,
        transparent     0%,
        transparent     35%,
        rgba(255,255,255,0.07) 45%,
        rgba(255,255,255,0.13) 50%,
        rgba(255,255,255,0.07) 55%,
        transparent     65%,
        transparent     100%
    );
    background-size: 250% 100%;
    animation: skDiscover 1.7s ease-in-out infinite;
}

@keyframes skDiscover {
    0%   { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}

/* stagger each tile's shimmer so they don't all pulse in sync */
.sk-tile:nth-child(1)  { animation-delay: 0s;    }
.sk-tile:nth-child(2)  { animation-delay: 0.12s; }
.sk-tile:nth-child(3)  { animation-delay: 0.22s; }
.sk-tile:nth-child(4)  { animation-delay: 0.32s; }
.sk-tile:nth-child(5)  { animation-delay: 0.42s; }
.sk-tile:nth-child(6)  { animation-delay: 0.52s; }
.sk-tile:nth-child(7)  { animation-delay: 0.62s; }

/* fake user-bar at bottom of each tile */
.sk-tile::before {
    content: '';
    position: absolute;
    bottom: 10px;
    left: 10px;
    right: 10px;
    height: 34px;
    border-radius: 12px;
    background: rgba(255,255,255,0.07);
    z-index: 2;
}

/* fake action buttons top-left (like / repost / fav) */
.sk-btn-row {
    position: absolute;
    top: 10px;
    left: 10px;
    display: flex;
    gap: 6px;
    z-index: 2;
}

.sk-btn {
    width: 42px;
    height: 28px;
    border-radius: 10px;
    background: rgba(255,255,255,0.09);
}

/* fake NEW badge area */
.sk-badge {
    position: absolute;
    bottom: 52px;
    left: 10px;
    width: 36px;
    height: 14px;
    border-radius: 5px;
    background: rgba(255,255,255,0.08);
    z-index: 2;
}

/* ── RESPONSIVE ── */
@media (max-width: 768px) {
    .discover-skeleton {
        grid-template-columns: 1fr 1fr;
        grid-auto-rows: clamp(130px, 35vw, 200px);
        gap: 8px;
        padding: 0 8px;
    }

    .sk-tile.sk-trending {
        grid-column: span 2;
        grid-row: span 1;
    }
}

@media (max-width: 380px) {
    .discover-skeleton {
        grid-template-columns: 1fr;
        grid-auto-rows: clamp(200px, 60vw, 260px);
    }

    .sk-tile.sk-trending {
        grid-column: span 1;
    }
}

/* ── USER BAR inside discover grid ── */
.meme-tile .user-bar {
    position: absolute;
    bottom: 8px;
    left: 8px;
    right: 8px;
    width: auto;
    transform: none; /* override the translateX(-50%) from index */

    display: flex;
    align-items: center;
    gap: 6px;

    padding: 5px 8px;
    border-radius: 10px;

    background: rgba(14, 10, 239, 0.15);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);

    z-index: 3;
    overflow: hidden;
    min-width: 0;
}

.meme-tile .user-bar img {
    width: 24px;
    height: 24px;
    min-width: 24px;
    min-height: 24px;
    border-radius: 50%;
    object-fit: cover;
    border: 1.5px solid red;
    display: block;
    flex-shrink: 0;
}

.meme-tile .user-bar > div {
    display: flex;
    flex-direction: column;
    justify-content: center;
    min-width: 0;
    flex: 1;
}

.meme-tile .user-bar > div > div {
    color: rgba(255,255,255,0.9);
    font-size: clamp(0.55rem, 1.8vw, 0.72rem);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    min-width: 0;
    width: 100%;
    font-weight: 500;
}

/* ── TABLET (≤ 768px) ── */
@media (max-width: 768px) {

    .discover-grid {
        display: grid;
        width: 100%;
        margin: 0;
        padding: 0 12px;
        gap: 8px;
        grid-template-columns: repeat(2, 1fr);
        grid-auto-rows: clamp(130px, 35vw, 200px);
    }

    .trending {
        grid-column: span 2;
        grid-row: span 1;
    }

    .meme-tile .user-bar {
        bottom: 6px;
        left: 6px;
        right: 6px;
        padding: 4px 7px;
        border-radius: 8px;
        gap: 5px;
    }

    .meme-tile .user-bar img {
        width: 20px;
        height: 20px;
        min-width: 20px;
        min-height: 20px;
    }

    .meme-tile .user-bar > div > div {
        font-size: clamp(0.5rem, 1.6vw, 0.65rem);
    }
}

/* ── MOBILE (≤ 480px) ── */
@media (max-width: 480px) {

    .discover-grid {
        padding: 0 8px;
        gap: 6px;
        grid-auto-rows: clamp(120px, 32vw, 170px);
    }

    .meme-tile .user-bar {
        bottom: 5px;
        left: 5px;
        right: 5px;
        padding: 3px 6px;
        border-radius: 7px;
        gap: 4px;
    }

    .meme-tile .user-bar img {
        width: 18px;
        height: 18px;
        min-width: 18px;
        min-height: 18px;
        border-width: 1px;
    }

    .meme-tile .user-bar > div > div {
        font-size: 0.5rem;
    }
}

/* ── VERY SMALL (≤ 360px) ── */
@media (max-width: 360px) {

    .discover-grid {
        grid-template-columns: 1fr;
        grid-auto-rows: clamp(180px, 55vw, 240px);
        padding: 0 6px;
        gap: 6px;
    }

    .trending {
        grid-column: span 1;
        grid-row: span 1;
    }

    .meme-tile .user-bar {
        bottom: 5px;
        left: 4px;
        right: 4px;
        padding: 3px 5px;
    }

    .meme-tile .user-bar img {
        width: 16px;
        height: 16px;
        min-width: 16px;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const btn = document.querySelector('.kebab-btn');
    const menu = document.querySelector('.kebab-dropdown');

    if(btn && menu){
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            menu.classList.toggle('show');
        });

        document.addEventListener('click', () => {
            menu.classList.remove('show');
        });
    }
});
</script>

</div>

<!-- SEARCH -->
<form class="search-box" method="GET">

    <span class="search-box-icon">
        <i class="ri-search-line"></i>
    </span>

    <input 
        type="text" 
        id="mainSearchInput"
        name="q" 
        placeholder="Search..." 
        value="<?php echo $search_query; ?>"
    >

    <button type="submit">
        <i class="ri-arrow-right-line"></i>
    </button>

</form>

<script>
const params = new URLSearchParams(window.location.search);

if (params.get("focus") === "1") {
    const input = document.getElementById("mainSearchInput");

    if (input) {
        input.focus();
    }
}
</script>

<!-- TAG BAR -->
<div class="tag-bar-wrap" style="margin-top:12px;">
    <button class="tag-scroll-btn-desktop" id="tagLeft"><i class="ri-arrow-left-s-line"></i></button>
    <div class="tag-scrollbar-wrapper">
        <div class="tag-scrollbar" id="tagScrollbar">
            <?php
            $active_tag=$tag;
            foreach($sorted_tags as $t){
                $isActive=($active_tag===$t||($t==='All'&&$active_tag==='all'));
                $isAff=in_array(strtolower($t),array_map('strtolower',$user_top_tags));
                $href=$t==='All'?'search.php?tag=all':'search.php?tag='.urlencode($t);
                $cls='tag-pill'.($isActive?' active':'').($isAff&&!$isActive?' user-affinity':'');
                echo '<a href="'.$href.'" class="'.$cls.'">'.($t==='All'?'All':'#'.$t).'</a>';
            }
            ?>
        </div>
    </div>
    <button class="tag-scroll-btn-desktop" id="tagRight"><i class="ri-arrow-right-s-line"></i></button>
</div>



<!-- USERS & GROUPS RESULTS -->
<?php if($search_query): ?>

    <?php if(!empty($users)): ?>
    <div style="margin: clamp(12px,3vw,20px) 0;">
        <h3>Users</h3>
        <?php foreach($users as $u): ?>
        <?php
            $defaultPic = 'uploads/mee.jpeg';
            $pic = !empty($u['profile_pic']) ? $u['profile_pic'] : $defaultPic;
        ?>
        <div class="user-result-row">
            <img src="<?php echo htmlspecialchars($pic); ?>"
                 onerror="this.src='<?php echo $defaultPic; ?>';"
                 onclick="location.href='profile.php?user_id=<?php echo $u['id']; ?>'">

            <div class="user-result-info"
                 onclick="location.href='profile.php?user_id=<?php echo $u['id']; ?>'">
                <div class="username">
                    <svg xmlns="http://www.w3.org/2000/svg" width="9" height="9" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="12" fill="#FF0000"/>
                        <path d="M9 12l2 2 4-4" stroke="white" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <?php echo htmlspecialchars($u['username']); ?>
                    <?php if($u['is_blocked']): ?>
                        <span style="color:red;font-weight:bold;"> Blocked</span>
                    <?php endif; ?>
                </div>
                <?php if(!empty($u['nickname'])): ?>
                    <div class="nickname">@<?php echo htmlspecialchars($u['nickname']); ?></div>
                <?php endif; ?>
            </div>

            <?php if(!$u['is_blocked'] && $current_user): ?>
                <button class="follow-btn <?php echo $u['is_following'] ? 'following' : ''; ?>"
                        data-user="<?php echo $u['id']; ?>"
                        style="flex-shrink:0;font-size:clamp(0.72rem,2.5vw,1rem);padding:clamp(5px,1.5vw,6px) clamp(10px,3vw,14px);min-width:0;">
                    <?php echo $u['is_following'] ? 'Untrack' : 'Track'; ?>
                </button>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if($groups && $groups->num_rows > 0): ?>
    <div style="margin: clamp(12px,3vw,20px) 0;">
        <h3>Communities</h3>
        <?php while($g = $groups->fetch_assoc()):
            $is_joined = false;
            if($current_user){
                $chk = $conn->query("SELECT 1 FROM group_members WHERE group_id=".$g['id']." AND user_id=$current_user");
                $is_joined = $chk->num_rows > 0;
            }
            $group_pic = $g['group_pic'] ?? '';
            if(empty($group_pic)) $img_src = 'uploads/default_group.png';
            elseif(strpos($group_pic, 'uploads/') === 0) $img_src = $group_pic;
            else $img_src = 'uploads/' . $group_pic;
        ?>
        <div class="group-result-row">
            <img src="<?php echo $img_src; ?>"
                 onclick="location.href='group.php?id=<?php echo $g['id']; ?>'"
                 onerror="this.src='uploads/default_group.png';">

            <span style="flex:1;cursor:pointer;min-width:0;"
                  onclick="location.href='group.php?id=<?php echo $g['id']; ?>'">
                <div style="display:flex;flex-direction:column;min-width:0;">
                    <span style="font-size:clamp(13px,3.5vw,15px);font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24">
                            <circle cx="12" cy="12" r="12" fill="#0673f0ff"/>
                            <path d="M9 12l2 2 4-4" stroke="white" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <?php echo htmlspecialchars($g['title']); ?>
                    </span>
                    <small style="color:#31a1f7;font-size:clamp(10px,2.5vw,12px);">
                        <?php echo $g['member_count']; ?> members
                    </small>
                </div>
            </span>

            <?php if($current_user): ?>
                <button class="group-btn <?php echo $is_joined ? 'joined' : ''; ?>"
                        data-group="<?php echo $g['id']; ?>"
                        style="flex-shrink:0;font-size:clamp(0.72rem,2.5vw,1rem);padding:clamp(5px,1.5vw,6px) clamp(10px,3vw,14px);min-width:0;">
                    <?php echo $is_joined ? 'Leave' : 'Join'; ?>
                </button>
            <?php endif; ?>
        </div>
        <?php endwhile; ?>
    </div>
    <?php endif; ?>

<?php endif; ?>

<!-- DISCOVER SKELETON — hidden once grid loads -->
<div class="discover-skeleton" id="discoverSkeleton">

    <!-- tile 1: trending (spans 2 cols × 2 rows) -->
    <div class="sk-tile sk-trending">
        <div class="sk-btn-row">
            <div class="sk-btn"></div>
            <div class="sk-btn"></div>
            <div class="sk-btn"></div>
        </div>
        <div class="sk-badge"></div>
    </div>

    <!-- tile 2: trending (spans 2 cols × 2 rows) -->
    <div class="sk-tile sk-trending">
        <div class="sk-btn-row">
            <div class="sk-btn"></div>
            <div class="sk-btn"></div>
            <div class="sk-btn"></div>
        </div>
        <div class="sk-badge"></div>
    </div>

    <!-- tiles 3–7: normal single cells -->
    <div class="sk-tile">
        <div class="sk-btn-row"><div class="sk-btn"></div><div class="sk-btn"></div><div class="sk-btn"></div></div>
        <div class="sk-badge"></div>
    </div>
    <div class="sk-tile">
        <div class="sk-btn-row"><div class="sk-btn"></div><div class="sk-btn"></div><div class="sk-btn"></div></div>
        <div class="sk-badge"></div>
    </div>
    <div class="sk-tile">
        <div class="sk-btn-row"><div class="sk-btn"></div><div class="sk-btn"></div><div class="sk-btn"></div></div>
        <div class="sk-badge"></div>
    </div>
    <div class="sk-tile">
        <div class="sk-btn-row"><div class="sk-btn"></div><div class="sk-btn"></div><div class="sk-btn"></div></div>
        <div class="sk-badge"></div>
    </div>
    <div class="sk-tile">
        <div class="sk-btn-row"><div class="sk-btn"></div><div class="sk-btn"></div><div class="sk-btn"></div></div>
        <div class="sk-badge"></div>
    </div>

</div>

<?php
function linkifyDiscoverCaption($caption, $conn) {
    return preg_replace_callback('/@([a-zA-Z0-9_]+)/', function($matches) use ($conn) {
        $username = $matches[1];
        $safe = $conn->real_escape_string($username);
        $res = $conn->query("SELECT id FROM users WHERE username='$safe' LIMIT 1");
        if ($res && $res->num_rows > 0) {
            $uid = $res->fetch_assoc()['id'];
            return '<a href="profile.php?user_id=' . $uid . '"
                onclick="event.stopPropagation()"
                style="color:#f24455;font-weight:600;text-decoration:none;"
                onmouseover="this.style.textDecoration=\'underline\'"
                onmouseout="this.style.textDecoration=\'none\'">@' . $username . '</a>';
        }
        return '<span style="color:#f24455;font-weight:600;">@' . $username . '</span>';
    }, $caption);
}
?>

<?php
// ── Build private profile + approved maps ONCE before loop ──
if(!isset($_private_profile_map)){
    $_private_profile_map = [];
    $_approved_map        = [];

    if($current_user){
        $pp = $conn->query("SELECT user_id FROM user_settings WHERE is_fully_private=1");
        if($pp) while($r=$pp->fetch_assoc())
            $_private_profile_map[(int)$r['user_id']] = true;

        $ap = $conn->query("SELECT target_id FROM track_requests WHERE requester_id=$current_user AND status='approved'");
        if($ap) while($r=$ap->fetch_assoc())
            $_approved_map[(int)$r['target_id']] = true;
    }
}
?>

<?php
// ── Build private profile + approved maps ONCE before loop ──
$_private_profile_map = [];
$_approved_map        = [];

if($current_user) {
    $pp = $conn->query("SELECT user_id FROM user_settings WHERE is_fully_private=1");
    if($pp) while($r=$pp->fetch_assoc())
        $_private_profile_map[(int)$r['user_id']] = true;

    $ap = $conn->query("SELECT target_id FROM track_requests WHERE requester_id=$current_user AND status='approved'");
    if($ap) while($r=$ap->fetch_assoc())
        $_approved_map[(int)$r['target_id']] = true;
}

// ── Pre-fetch group membership map for all meme IDs (same as home.php) ──
$_group_map = [];
if(!empty($memes)) {
    $all_meme_ids = array_column($memes, 'id');
    if(!empty($all_meme_ids)) {
        $ids_str = implode(',', array_map('intval', $all_meme_ids));
        $gp_res = $conn->query("
            SELECT gp.meme_id, g.id AS group_id, g.title AS group_title
            FROM group_posts gp
            JOIN groups g ON g.id = gp.group_id
            WHERE gp.meme_id IN ($ids_str)
        ");
        if($gp_res) while($gp = $gp_res->fetch_assoc())
            $_group_map[(int)$gp['meme_id']] = $gp;
    }
}
?>

<div class="discover-grid">
<?php
$index = 0;
foreach($memes as $row){

    // ── PRIVATE ACCOUNT GATE ──
    $uploader_id = (int)$row['uploaded_by'];
    if($uploader_id != (int)$current_user && !empty($_private_profile_map[$uploader_id]) && empty($_approved_map[$uploader_id])) continue;

    $class = $search_query ? "meme-tile" : ($index < 2 ? "meme-tile trending" : "meme-tile");
    $meme_id = $row['id'];

    $created = strtotime($row['created_at']);
    $now = time();
    $diff = $now - $created;
    $is_new_badge = ($diff <= 172800);

    $liked_res = $conn->query("SELECT id FROM likes WHERE meme_id=$meme_id AND user_id=$current_user");
    $user_liked = ($liked_res->num_rows > 0);
    $total_likes = $conn->query("SELECT COUNT(*) as total FROM likes WHERE meme_id=$meme_id")->fetch_assoc()['total'];

    $is_fav = $conn->query("SELECT id FROM favorites WHERE meme_id=$meme_id AND user_id=$current_user")->num_rows > 0;
    $is_repost = $conn->query("SELECT id FROM reposts WHERE meme_id=$meme_id AND user_id=$current_user")->num_rows > 0;
    $fav_count = $conn->query("SELECT COUNT(*) as c FROM favorites WHERE meme_id=$meme_id")->fetch_assoc()['c'];
    $repost_count = $conn->query("SELECT COUNT(*) as c FROM reposts WHERE meme_id=$meme_id")->fetch_assoc()['c'];

    $is_following = false;
    if($current_user){
        $chk_follow = $conn->query("SELECT id FROM followers WHERE follower_id=$current_user AND following_id=".$row['uploaded_by']);
        $is_following = $chk_follow->num_rows > 0;
    }

    // ── Group tag for this meme (same as home.php) ──
    $group_info = $_group_map[$meme_id] ?? null;

    $modal_args = '\'uploads/'.$row['file_name'].'\',
    \''.$row['type'].'\',
    \''.addslashes(!empty($row['profile_pic']) ? $row['profile_pic'] : 'uploads/mee.jpeg').'\',
    \''.$row['uploaded_by'].'\',
    \''.addslashes($row['username']).'\',
    \''.addslashes($row['caption']).'\'';

    echo '<div class="'.$class.'" data-id="'.$meme_id.'" onclick="openDiscoverModal('.$modal_args.')">';

    /* TOP BUTTONS */
    echo '<div class="meme-btn left like-btn '.($user_liked ? 'active' : '').'"
        data-id="'.$meme_id.'"
        onclick="event.stopPropagation()"
        style="left:0px;'.($user_liked ? 'color:#ff2b55;' : '').'">
        <i class="ri-heart-3-fill"></i>
        <span class="count">'.$total_likes.'</span>
    </div>';

    echo '<div class="meme-btn left repost-btn '.($is_repost ? 'active' : '').'" data-id="'.$meme_id.'" onclick="event.stopPropagation()" style="left:53px;">
        <i class="ri-repeat-line"></i>
        <span class="count">'.$repost_count.'</span>
    </div>';

    echo '<div class="meme-btn left fav-btn '.($is_fav ? 'active' : '').'"
        data-id="'.$meme_id.'"
        onclick="event.stopPropagation()"
        style="left:106px;'.($is_fav ? 'color:red;' : '').'">
        <i class="ri-bookmark-fill"></i>
        <span class="count">'.$fav_count.'</span>
    </div>';

    /* POSTED BY + TRACK BTN */
    echo '<div class="posted-by-overlay" style="
        position:absolute;
        top:48px;
        left:12px;
        font-size:clamp(0.6rem, 2vw, 0.75rem);
        font-weight:500;
        z-index:5;
        display:flex;
        flex-direction:column;
        align-items:flex-start;
        gap:4px;
        max-width:calc(100% - 16px);
    " onclick="event.stopPropagation()">

        <div style="display:flex;align-items:center;gap:3px;flex-wrap:nowrap;">
            <span style="color:#f24455;font-weight:600;white-space:nowrap;">Posted By</span>
            <strong>
            <a href="profile.php?user_id='.$row['uploaded_by'].'"
               style="color:white;text-decoration:underline;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:80px;">'.
                ($row['uploaded_by'] == $current_user
                    ? 'You'
                    : (mb_strlen($row['username']) > 10
                        ? mb_substr($row['username'], 0, 10).'...'
                        : $row['username']))
            .'</a>
            </strong>
        </div>';

    if($row['uploaded_by'] != $current_user){
        echo '<button class="track-btn '.($is_following ? 'following' : '').'"
            data-user="'.$row['uploaded_by'].'"
            onclick="event.stopPropagation()"
            style="padding:clamp(2px,0.8vw,4px) clamp(6px,1.5vw,10px);width:auto;font-size:clamp(9px,2.2vw,11px);flex-shrink:0;margin-left:0;">
            '.($is_following ? 'Untrack' : 'Track').'
        </button>';
    }

    // ── GROUP TAG — sits below the Track button, same as home.php ──
    if($group_info) {
        $g_id    = (int)$group_info['group_id'];
        $g_title = htmlspecialchars($group_info['group_title']);
        echo '<div style="display:flex;align-items:center;gap:4px;font-size:clamp(0.58rem,1.8vw,0.65rem);font-weight:500;color:rgba(255,255,255,0.88);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:calc(100% - 8px);" onclick="event.stopPropagation()">
            <i class="ri-team-line" style="font-size:0.7rem;color:rgba(255,255,255,0.4);flex-shrink:0;"></i>
            From&nbsp;<a href="group.php?id='.$g_id.'"
                onclick="event.stopPropagation()"
                style="color:#FF4C4C;text-decoration:none;font-weight:600;font-size:clamp(0.58rem,1.8vw,0.65rem);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"
                onmouseover="this.style.textDecoration=\'underline\'"
                onmouseout="this.style.textDecoration=\'none\'">'.$g_title.'</a>
        </div>';
    }

    echo '</div>'; // END posted-by-overlay

    /* MEDIA */
    if($row['type']=="image"){
        echo '<img src="uploads/'.$row['file_name'].'" style="cursor:pointer;" onclick="openDiscoverModal('.$modal_args.')">';
    } elseif($row['type']=="video"){
        echo '<video src="uploads/'.$row['file_name'].'" muted autoplay loop style="cursor:pointer;" onclick="openDiscoverModal('.$modal_args.')"></video>';
    } else {
        echo '<img src="uploads/Text.jpg" style="cursor:pointer;" onclick="openDiscoverModal('.$modal_args.')">';
    }

    /* NEW BADGE */
    if($is_new_badge){
        echo '<div class="peura-new-tag" style="bottom:55px;">NEW</div>';
    }

    /* USER BAR GLASS */
    echo '
    <div class="user-bar glass" onclick="event.stopPropagation()">
        <a href="profile.php?user_id='.$row['uploaded_by'].'" onclick="event.stopPropagation()" style="flex-shrink:0;">
            <img src="'.(!empty($row['profile_pic']) ? $row['profile_pic'] : 'uploads/mee.jpeg').'"
                 onerror="this.src=\'uploads/mee.jpeg\'"
                 style="width:clamp(21px,4vw,30px);height:clamp(21px,4vw,30px);min-width:clamp(21px,4vw,30px);border-radius:50%;object-fit:cover;border:2px solid red;display:block;flex-shrink:0;">
        </a>
        <div style="display:flex;flex-direction:column;justify-content:center;min-width:0;flex:1;">
            <div style="color:rgba(255,255,255,0.9);font-size:clamp(0.65rem,2.2vw,0.82rem);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0;width:100%;font-weight:500;">
                '.linkifyDiscoverCaption(htmlspecialchars($row["caption"]), $conn).'
            </div>
        </div>
    </div>';

    /* PEURAFEED PUSH */
    echo '<script>
    if(typeof peuraFeed === "undefined") var peuraFeed = [];
    peuraFeed.push({
        id:          '.(int)$meme_id.',
        file:        "uploads/'.addslashes($row["file_name"]).'",
        type:        "'.addslashes($row["type"]).'",
        pic:         "'.addslashes(!empty($row["profile_pic"]) ? $row["profile_pic"] : "uploads/mee.jpeg").'",
        userId:      "'.(int)$row["uploaded_by"].'",
        username:    "'.addslashes($row["username"]).'",
        caption:     "'.addslashes($row["caption"]).'",
        time:        "'.addslashes($row["created_at"]).'",
        likeCount:   '.(int)$total_likes.',
        repostCount: '.(int)$repost_count.',
        favCount:    '.(int)$fav_count.',
        liked:       '.($user_liked  ? 'true' : 'false').',
        reposted:    '.($is_repost   ? 'true' : 'false').',
        faved:       '.($is_fav      ? 'true' : 'false').'
    });
    </script>';

    echo '</div>';
    $index++;
}
?>
</div>


<!-- DISCOVER CARD MODAL -->
<div id="discoverModal" onclick="if(event.target===this) closeDiscoverModal()" style="
    display:none;
    position:fixed;
    inset:0;
    z-index:9999999999999;
    background:rgba(0,0,0,0.92);
    backdrop-filter:blur(6px);
    -webkit-backdrop-filter:blur(6px);
    align-items:center;
    justify-content:center;
    padding:16px;
    flex-direction:row;
    gap:12px;">

    <div id="dm-card" style="background:rgba(18,17,17,0.95);border:1px solid rgba(255,255,255,0.07);border-radius:20px;width:100%;max-width:420px;overflow:hidden;display:flex;flex-direction:column;max-height:90vh;">
        <div id="dm-inner"></div>
    </div>

    <div id="dm-arrows" style="display:flex;flex-direction:column;align-items:center;gap:10px;flex-shrink:0;">
        <button onclick="dmLoadIndex(dmCurrentIndex-1)" onmouseover="this.style.background='rgba(255,255,255,0.15)'" onmouseout="this.style.background='rgba(255,255,255,0.07)'" style="background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.12);border-radius:50%;width:40px;height:40px;display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;transition:background 0.2s;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.7)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"/></svg>
        </button>
        <button onclick="dmLoadIndex(dmCurrentIndex+1)" onmouseover="this.style.background='rgba(255,255,255,0.15)'" onmouseout="this.style.background='rgba(255,255,255,0.07)'" style="background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.12);border-radius:50%;width:40px;height:40px;display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;transition:background 0.2s;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.7)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
    </div>

    <div id="dm-hint" style="position:absolute;bottom:10px;left:50%;transform:translateX(-50%);color:rgba(255,255,255,0.18);font-size:10px;white-space:nowrap;pointer-events:none;">swipe or use arrows ↕</div>
</div>

<style>
#dm-inner { display:flex; flex-direction:column; overflow:hidden; flex:1; }
.dm-caption-box::-webkit-scrollbar { width:4px; }
.dm-caption-box::-webkit-scrollbar-thumb { background:rgba(255,255,255,0.15); border-radius:999px; }
.dm-action-bar { display:flex; align-items:center; gap:6px; padding:10px 14px; border-top:1px solid rgba(255,255,255,0.06); flex-shrink:0; }
.dm-action-btn { display:flex; align-items:center; justify-content:center; gap:5px; background:transparent; border:1px solid rgba(255,255,255,0.1); border-radius:10px; padding:7px 12px; color:white; cursor:pointer; font-size:12px; font-weight:600; font-family:Poppins,sans-serif; transition:background 0.2s; flex:1; }
.dm-action-btn:hover { background:rgba(255,255,255,0.07); }
.dm-close-btn { display:flex; align-items:center; justify-content:center; background:transparent; border:1px solid rgba(255,255,255,0.1); border-radius:10px; padding:7px 12px; color:rgba(255,255,255,0.45); cursor:pointer; font-size:12px; font-family:Poppins,sans-serif; }
.dm-ad-wrap { padding:14px; display:flex; flex-direction:column; gap:10px; overflow-y:auto; flex:1; }
.dm-ad-header { display:flex; align-items:center; gap:8px; }
.dm-ad-logo { width:28px; height:28px; border-radius:50%; border:1.5px solid #FF2D2D; object-fit:cover; flex-shrink:0; }
.dm-ad-brand { font-size:13px; font-weight:600; color:white; }
.dm-ad-type { font-size:10px; color:rgba(255,255,255,0.4); }
.dm-ad-media { width:100%; border-radius:12px; overflow:hidden; border:1.5px solid rgba(255,45,45,0.45); }
.dm-ad-media img, .dm-ad-media video { width:100%; display:block; object-fit:cover; max-height:200px; }
.dm-ad-caption { font-size:11px; color:rgba(255,255,255,0.7); line-height:1.45; }
.dm-ad-cta { display:flex; align-items:center; justify-content:center; gap:6px; padding:10px; background:rgba(255,255,255,0.07); border:1px solid rgba(255,255,255,0.1); border-radius:12px; color:white; font-weight:600; font-size:12px; text-decoration:none; transition:background 0.2s; }
.dm-ad-cta:hover { background:rgba(255,255,255,0.12); }
.dm-sugg-label { font-size:10px; font-weight:700; letter-spacing:1.2px; color:#f24455; text-transform:uppercase; }
.dm-sugg-scroll { display:flex; flex-wrap:nowrap; overflow-x:auto; gap:14px; padding:4px 0 8px; scrollbar-width:none; -ms-overflow-style:none; -webkit-overflow-scrolling:touch; }
.dm-sugg-scroll::-webkit-scrollbar { display:none; }
.dm-sugg-user { flex:0 0 auto; width:68px; display:flex; flex-direction:column; align-items:center; gap:5px; text-decoration:none; color:white; }
.dm-sugg-user img { width:52px; height:52px; border-radius:50%; border:2px solid #FF2D2D; object-fit:cover; display:block; transition:transform 0.2s; }
.dm-sugg-user:hover img { transform:scale(1.08); }
.dm-sugg-name { font-size:10px; font-weight:500; text-align:center; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; width:100%; }
.dm-sugg-track { font-size:9px; font-weight:600; padding:3px 0; width:100%; border-radius:8px; border:1px solid rgba(255,45,45,0.5); background:transparent; color:rgba(255,255,255,0.7); cursor:pointer; transition:all 0.2s; font-family:Poppins,sans-serif; text-align:center; }
.dm-sugg-track:hover, .dm-sugg-track.tracking { background:rgba(255,45,45,0.15); border-color:#FF2D2D; color:#FF2D2D; }
@media(max-width:480px) {
    #dm-arrows, #dm-hint { display:none !important; }
    #discoverModal { padding:10px !important; align-items:center !important; }
    #dm-card { border-radius:20px 20px 14px 14px !important; max-height:88vh !important; }
    .dm-caption-box { font-size:12px !important; max-height:60px !important; }
    .dm-action-btn { padding:7px 8px !important; font-size:11px !important; }
}
@media(max-width:360px) {
    .dm-caption-box { font-size:11px !important; max-height:55px !important; }
    .dm-action-btn { padding:6px !important; font-size:10px !important; }
}
</style>

<script>
var dmCurrentIndex = 0;
var _dmMemeId      = null;
var dmFeed         = [];
const currentUserId = <?php echo $current_user; ?>;

/* ── Fetch ads + suggested users then build feed ── */
function _loadDmData() {
    Promise.all([
        fetch('search.php?type=ads').then(function(r){ return r.json(); }).catch(function(){ return []; }),
        fetch('search.php?type=sugg').then(function(r){ return r.json(); }).catch(function(){ return []; })
    ]).then(function(results) {
        window.cmAds       = results[0] || [];
        window.cmSuggUsers = results[1] || [];
        _buildDmFeed();
    });
}

function _buildDmFeed() {
    dmFeed = [];
    var adIdx = 0, suggIdx = 0;
    var ads  = window.cmAds       || [];
    var sugg = window.cmSuggUsers || [];
    peuraFeed.forEach(function(post, i) {
        dmFeed.push({ type: 'post', data: post });
        if ((i + 1) % 4 === 0 && ads.length > 0) {
            dmFeed.push({ type: 'ad', data: ads[adIdx % ads.length] });
            adIdx++;
        }
        if ((i + 1) % 7 === 0 && sugg.length > 0) {
            var slice = [];
            for (var j = 0; j < 6; j++) slice.push(sugg[(suggIdx + j) % sugg.length]);
            dmFeed.push({ type: 'sugg', data: slice });
            suggIdx += 6;
        }
    });
}

function _dmFormatDate(timeStr) {
    if (!timeStr) return '';
    var d = new Date(timeStr.replace(' ', 'T'));
    if (isNaN(d)) return timeStr;
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    var day   = d.getDate();
    var month = months[d.getMonth()];
    var year  = d.getFullYear();
    var hrs   = String(d.getHours()).padStart(2, '0');
    var mins  = String(d.getMinutes()).padStart(2, '0');
    return day + ' ' + month + ' ' + year + ' · ' + hrs + ':' + mins;
}

function _dmLinkify(caption) {
    return caption.replace(/@([a-zA-Z0-9_]+)/g, function(match, username) {
        return '<a href="profile.php?username=' + encodeURIComponent(username) +
               '" onclick="event.stopPropagation()" style="color:#f24455;font-weight:600;text-decoration:none;"' +
               ' onmouseover="this.style.textDecoration=\'underline\'"' +
               ' onmouseout="this.style.textDecoration=\'none\'">' + match + '</a>';
    });
}

function _dmRenderPost(item) {
    var p = item.data;
    _dmMemeId = p.id;

    var mediaHtml = '';
    if (p.type === 'image') {
        mediaHtml = '<img src="' + p.file + '" onerror="this.src=\'uploads/mee.jpeg\'" style="width:100%;max-height:52vh;object-fit:contain;display:block;">';
    } else if (p.type === 'video') {
        mediaHtml = '<video src="' + p.file + '" autoplay muted loop playsinline style="width:100%;max-height:52vh;object-fit:contain;display:block;"></video>';
    }

    var likeCount   = p.likeCount   !== undefined ? p.likeCount   : 0;
    var repostCount = p.repostCount !== undefined ? p.repostCount : 0;
    var favCount    = p.favCount    !== undefined ? p.favCount    : 0;
    var liked    = p.liked    || false;
    var reposted = p.reposted || false;
    var faved    = p.faved    || false;

    var dateStr = _dmFormatDate(p.time || '');

   return '' +
    '<div style="display:flex;align-items:center;gap:10px;padding:14px 16px;flex-shrink:0;">' +

        '<img src="' + (p.pic || 'uploads/mee.jpeg') + '" onerror="this.src=\'uploads/mee.jpeg\'"' +
             ' onclick="location.href=\'profile.php?user_id=' + p.userId + '\'"' +
             ' style="width:40px;height:40px;border-radius:50%;border:2px solid #FF4C4C;cursor:pointer;object-fit:cover;flex-shrink:0;">' +

        '<div style="flex:1;min-width:0;">' +

            '<div onclick="location.href=\'profile.php?user_id=' + p.userId + '\'" style="color:#FF4C4C;font-weight:700;font-size:14px;cursor:pointer;display:flex;align-items:center;gap:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' +

                '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" style="flex-shrink:0;">' +
                    '<circle cx="12" cy="12" r="12" fill="#FF0000"/>' +
                    '<path d="M9 12l2 2 4-4" stroke="white" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>' +
                '</svg>' +

                '<span>' +
                    (p.userId == currentUserId ? 'You' : p.username) +
                '</span>' +

            '</div>' +
                '<div style="font-size:11px;color:rgba(255,255,255,0.35);margin-top:3px;display:flex;align-items:center;gap:4px;">' +
                    '<i class="ri-time-line" style="font-size:10px;"></i>' +
                    '<span>' + dateStr + '</span>' +
                '</div>' +
            '</div>' +
            '<button onclick="closeDiscoverModal()" style="background:rgba(255,255,255,0.06);border:none;border-radius:50%;width:30px;height:30px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:rgba(255,255,255,0.5);font-size:16px;flex-shrink:0;">✕</button>' +
        '</div>' +
        (mediaHtml ? '<div style="width:100%;background:#000;flex-shrink:0;">' + mediaHtml + '</div>' : '') +
        '<div class="dm-caption-box" style="padding:10px 16px;color:rgba(255,255,255,0.8);font-size:13px;line-height:1.55;border-top:1px solid rgba(255,255,255,0.06);word-break:break-word;white-space:pre-wrap;overflow-y:auto;max-height:80px;flex-shrink:0;">' +
            _dmLinkify(p.caption || '') +
        '</div>' +
        '<div class="dm-action-bar">' +
            '<button id="dm-like-btn" class="dm-action-btn" style="color:' + (liked ? '#ff4c4c' : 'white') + ';border-color:' + (liked ? 'rgba(255,76,76,0.4)' : 'rgba(255,255,255,0.1)') + ';" onclick="dmAction(\'like\')">' +
                '<i class="ri-heart-fill" style="font-size:14px;"></i><span id="dm-like-count">' + likeCount + '</span>' +
            '</button>' +
            '<button id="dm-repost-btn" class="dm-action-btn" style="color:' + (reposted ? '#ff4c4c' : 'white') + ';border-color:' + (reposted ? 'rgba(255,76,76,0.4)' : 'rgba(255,255,255,0.1)') + ';" onclick="dmAction(\'repost\')">' +
                '<i class="ri-repeat-line" style="font-size:14px;"></i><span id="dm-repost-count">' + repostCount + '</span>' +
            '</button>' +
            '<button id="dm-fav-btn" class="dm-action-btn" style="color:' + (faved ? '#ff4c4c' : 'white') + ';border-color:' + (faved ? 'rgba(255,76,76,0.4)' : 'rgba(255,255,255,0.1)') + ';" onclick="dmAction(\'fav\')">' +
                '<i class="ri-bookmark-fill" style="font-size:14px;"></i><span id="dm-fav-count">' + favCount + '</span>' +
            '</button>' +
            '<button class="dm-close-btn" onclick="closeDiscoverModal()">Close</button>' +
        '</div>';
}

function _dmRenderAd(item) {
    var ad  = item.data;
    var ext = (ad.meme_image || '').split('.').pop().toLowerCase();
    var isVideo = ['mp4','webm','mov'].indexOf(ext) !== -1;
    _dmMemeId = null;
    return '' +
        '<div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px 0;flex-shrink:0;">' +
            '<span style="font-size:9px;font-weight:800;letter-spacing:1px;color:rgba(255,255,255,0.35);text-transform:uppercase;border:1px solid rgba(255,255,255,0.12);border-radius:4px;padding:2px 6px;">AD</span>' +
            '<button onclick="closeDiscoverModal()" style="background:rgba(255,255,255,0.06);border:none;border-radius:50%;width:30px;height:30px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:rgba(255,255,255,0.5);font-size:16px;">✕</button>' +
        '</div>' +
        '<div class="dm-ad-wrap">' +
            '<div class="dm-ad-header">' +
                '<img src="uploads/' + (ad.image || '') + '" onerror="this.src=\'uploads/mee.jpeg\'" class="dm-ad-logo">' +
                '<div style="min-width:0;"><div class="dm-ad-brand">' + (ad.title || '') + '</div><div class="dm-ad-type">' + (ad.type || '') + '</div></div>' +
            '</div>' +
            '<div class="dm-ad-media">' +
                (isVideo ? '<video src="uploads/' + ad.meme_image + '" autoplay muted loop playsinline></video>'
                         : '<img src="uploads/' + (ad.meme_image || '') + '" onerror="this.src=\'uploads/mee.jpeg\'">') +
            '</div>' +
            '<div class="dm-ad-caption">' + (ad.caption || '') + '</div>' +
            '<a href="' + (ad.link || '#') + '" target="_blank" class="dm-ad-cta" onclick="event.stopPropagation()">Visit Brand <i class="ri-arrow-right-up-line"></i></a>' +
        '</div>' +
        '<div class="dm-action-bar">' +
            '<button class="dm-action-btn" onclick="dmLoadIndex(dmCurrentIndex-1)"><i class="ri-arrow-up-line"></i> Prev</button>' +
            '<button class="dm-action-btn" onclick="dmLoadIndex(dmCurrentIndex+1)">Next <i class="ri-arrow-down-line"></i></button>' +
            '<button class="dm-close-btn" onclick="closeDiscoverModal()">Close</button>' +
        '</div>';
}

function _dmRenderSugg(item) {
    var users = item.data;
    _dmMemeId = null;
    var usersHtml = users.map(function(u) {
        var following = parseInt(u.is_following) > 0;
        return '<div class="dm-sugg-user">' +
            '<a href="profile.php?user_id=' + u.id + '" onclick="event.stopPropagation()" style="display:contents;color:inherit;text-decoration:none;">' +
                '<img src="' + (u.profile_pic || 'uploads/mee.jpeg') + '" onerror="this.src=\'uploads/mee.jpeg\'">' +
                '<span class="dm-sugg-name">' + u.username + '</span>' +
            '</a>' +
            '<button class="dm-sugg-track ' + (following ? 'tracking' : '') + '" data-user="' + u.id + '" onclick="event.stopPropagation();dmToggleSuggTrack(this)">' +
                (following ? 'Untrack' : 'Track') +
            '</button>' +
        '</div>';
    }).join('');
    return '' +
        '<div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px 0;flex-shrink:0;">' +
            '<span class="dm-sugg-label">Suggested Peumers</span>' +
            '<button onclick="closeDiscoverModal()" style="background:rgba(255,255,255,0.06);border:none;border-radius:50%;width:30px;height:30px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:rgba(255,255,255,0.5);font-size:16px;">✕</button>' +
        '</div>' +
        '<div style="padding:10px 14px;overflow-y:auto;flex:1;"><div class="dm-sugg-scroll">' + usersHtml + '</div></div>' +
        '<div class="dm-action-bar">' +
            '<button class="dm-action-btn" onclick="dmLoadIndex(dmCurrentIndex-1)"><i class="ri-arrow-up-line"></i> Prev</button>' +
            '<button class="dm-action-btn" onclick="dmLoadIndex(dmCurrentIndex+1)">Next <i class="ri-arrow-down-line"></i></button>' +
            '<button class="dm-close-btn" onclick="closeDiscoverModal()">Close</button>' +
        '</div>';
}

function _dmRenderSlot(index) {
    if (index < 0 || index >= dmFeed.length) return;
    var item  = dmFeed[index];
    var inner = document.getElementById('dm-inner');
    inner.querySelectorAll('video').forEach(function(v){ v.pause(); v.src=''; });
    if      (item.type === 'post') inner.innerHTML = _dmRenderPost(item);
    else if (item.type === 'ad')   inner.innerHTML = _dmRenderAd(item);
    else if (item.type === 'sugg') inner.innerHTML = _dmRenderSugg(item);
}

function openDiscoverModal(file, type, pic, userId, username, caption) {
    if (dmFeed.length === 0) _buildDmFeed();
    var idx = dmFeed.findIndex(function(s) {
        return s.type === 'post' && s.data.file === file && String(s.data.userId) === String(userId);
    });
    dmCurrentIndex = idx >= 0 ? idx : 0;
    _dmRenderSlot(dmCurrentIndex);
    var modal = document.getElementById('discoverModal');
    modal.style.display = 'flex';
    modal.style.overscrollBehavior = 'contain';
    document.body.style.overflow  = 'hidden';
    document.body.style.touchAction = 'none';
}

function dmLoadIndex(index) {
    if (index < 0 || index >= dmFeed.length) return;
    var card = document.getElementById('dm-card');
    var dir  = index > dmCurrentIndex ? 1 : -1;
    card.style.transition = 'transform 0.22s ease, opacity 0.22s ease';
    card.style.transform  = 'translateY(' + (dir * -30) + 'px)';
    card.style.opacity    = '0';
    setTimeout(function() {
        dmCurrentIndex = index;
        _dmRenderSlot(dmCurrentIndex);
        card.style.transition = 'none';
        card.style.transform  = 'translateY(' + (dir * 30) + 'px)';
        card.style.opacity    = '0';
        requestAnimationFrame(function() {
            card.style.transition = 'transform 0.22s ease, opacity 0.22s ease';
            card.style.transform  = 'translateY(0)';
            card.style.opacity    = '1';
        });
    }, 220);
}

function closeDiscoverModal() {
    document.getElementById('dm-inner').querySelectorAll('video').forEach(function(v){ v.pause(); v.src=''; });
    var modal = document.getElementById('discoverModal');
    modal.style.display = 'none';
    document.body.style.overflow   = '';
    document.body.style.touchAction = '';
    _dmMemeId = null;
}

function dmAction(type) {
    if (!_dmMemeId) return;
    var selMap  = { like: '.like-btn',    repost: '.repost-btn', fav: '.fav-btn' };
    var btnMap  = { like: 'dm-like-btn',  repost: 'dm-repost-btn', fav: 'dm-fav-btn' };
    var cntMap  = { like: 'dm-like-count',repost: 'dm-repost-count', fav: 'dm-fav-count' };
    var cntField   = { like: 'likeCount', repost: 'repostCount', fav: 'favCount' };
    var stateField = { like: 'liked',     repost: 'reposted',    fav: 'faved' };

    var feedBtn = document.querySelector(selMap[type] + '[data-id="' + _dmMemeId + '"]');
    if (!feedBtn) return;
    feedBtn.click();

    setTimeout(function() {
        var cntEl  = feedBtn.querySelector('.count, span');
        var dmBtn  = document.getElementById(btnMap[type]);
        var dmSpan = document.getElementById(cntMap[type]);
        var active = feedBtn.classList.contains('active');

        if (cntEl && dmSpan) dmSpan.textContent = cntEl.textContent;
        if (dmBtn) {
            dmBtn.style.color       = active ? '#ff4c4c' : 'white';
            dmBtn.style.borderColor = active ? 'rgba(255,76,76,0.4)' : 'rgba(255,255,255,0.1)';
        }

        var feedItem = dmFeed.find(function(s) {
            return s.type === 'post' && String(s.data.id) === String(_dmMemeId);
        });
        if (feedItem && cntEl) {
            feedItem.data[cntField[type]]   = parseInt(cntEl.textContent) || 0;
            feedItem.data[stateField[type]] = active;
        }
    }, 350);
}

function dmToggleSuggTrack(btn) {
    var userId = btn.dataset.user;
    fetch('search.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=toggle_follow&user_id=' + userId
    })
    .then(function(r){ return r.json(); })
    .then(function(data) {
        if (data.status === 'ok') {
            if (data.following) { btn.textContent = 'Untrack'; btn.classList.add('tracking'); }
            else                { btn.textContent = 'Track';   btn.classList.remove('tracking'); }
        }
    });
}

/* ── Load ads + sugg on page load ── */
window.addEventListener('load', function(){ _loadDmData(); });

/* wheel — blocks page scroll on desktop */
document.getElementById('discoverModal').addEventListener('wheel', function(e) {
    e.preventDefault();
    e.stopPropagation();
    if (e.deltaY > 0) dmLoadIndex(dmCurrentIndex + 1);
    else              dmLoadIndex(dmCurrentIndex - 1);
}, { passive: false });

/* touchmove — blocks page scroll on mobile */
var dmTouchStartY = 0;
document.getElementById('discoverModal').addEventListener('touchstart', function(e) {
    dmTouchStartY = e.touches[0].clientY;
}, { passive: true });

document.getElementById('discoverModal').addEventListener('touchmove', function(e) {
    e.stopPropagation();
    e.preventDefault();
}, { passive: false });

document.getElementById('discoverModal').addEventListener('touchend', function(e) {
    var diff = dmTouchStartY - e.changedTouches[0].clientY;
    if (Math.abs(diff) > 40) {
        if (diff > 0) dmLoadIndex(dmCurrentIndex + 1);
        else          dmLoadIndex(dmCurrentIndex - 1);
    }
}, { passive: true });

document.addEventListener('keydown', function(e) {
    if (document.getElementById('discoverModal').style.display === 'flex') {
        if (e.key === 'Escape')    closeDiscoverModal();
        if (e.key === 'ArrowDown') dmLoadIndex(dmCurrentIndex + 1);
        if (e.key === 'ArrowUp')   dmLoadIndex(dmCurrentIndex - 1);
    }
});
</script>

<!-- ── SCREEN TIME BREAK OVERLAY ── -->
<div id="breakOverlay">
    <div class="break-title">Time to Stretch<br>and Take a Break</div>
    <div class="break-subtitle">You've been scrolling for a while 🕐</div>
    <div class="break-angel">🪽</div>
    <div class="break-code-label">
        Type the code below to return
        <strong>1 2 1 2</strong>
    </div>
    <input class="break-input" id="breakCodeInput" type="tel" maxlength="4" placeholder="- - - -" oninput="checkBreakCode()">
    <button class="break-return-btn" onclick="tryBreakReturn()">Return to Feed</button>
    <div class="break-motivate">Stay positive and keep moving forward — you got this 💪</div>
</div>


<script>
(function () {
    var skeleton = document.getElementById('discoverSkeleton');
    var grid     = document.querySelector('.discover-grid');

    if (skeleton && grid) {
        // hide real grid until ready
        grid.style.visibility = 'hidden';
        grid.style.opacity    = '0';

        window.addEventListener('load', function () {
            setTimeout(function () {
                // fade skeleton out
                skeleton.style.transition = 'opacity 0.4s ease';
                skeleton.style.opacity    = '0';
                setTimeout(function () {
                    skeleton.style.display = 'none';
                    // reveal real grid
                    grid.style.visibility  = 'visible';
                    grid.style.transition  = 'opacity 0.4s ease';
                    grid.style.opacity     = '1';
                }, 400);
            }, 500);
        });
    }
})();

/* TAG ARROWS */
(function(){var b=document.getElementById('tagScrollbar');var l=document.getElementById('tagLeft');var r=document.getElementById('tagRight');if(l&&r&&b){l.addEventListener('click',function(){b.scrollBy({left:-200,behavior:'smooth'});});r.addEventListener('click',function(){b.scrollBy({left:200,behavior:'smooth'});});}})();

/* PTR */
(function(){
    var ind=document.getElementById('ptr-indicator');
    var lbl=document.getElementById('ptr-label');
    var startY=0,pulling=false,triggered=false,THRESHOLD=90,MAX_PULL=130;
    function isAtTop(){return window.scrollY<=0;}
    document.addEventListener('touchstart',function(e){if(!isAtTop()) return;startY=e.touches[0].clientY;pulling=true;triggered=false;},{passive:true});
    document.addEventListener('touchmove',function(e){if(!pulling||!isAtTop()) return;var d=Math.min(e.touches[0].clientY-startY,MAX_PULL);if(d<=0) return;var t=-80+(d/MAX_PULL)*150;ind.style.transition='none';ind.style.transform='translateX(-50%) translateY('+(t+16)+'px)';ind.style.opacity=d/THRESHOLD;lbl.textContent=d>=THRESHOLD?'Release to refresh':'Pull to refresh';if(d>=THRESHOLD&&!triggered){triggered=true;ind.classList.add('visible');if(navigator.vibrate) navigator.vibrate(10);}},{passive:true});
    document.addEventListener('touchend',function(){if(!pulling) return;pulling=false;if(triggered){lbl.textContent='Refreshing...';ind.classList.add('visible');setTimeout(function(){window.location.reload();},800);}else{ind.style.transition='transform 0.3s,opacity 0.3s';ind.style.opacity='0';ind.style.transform='translateX(-50%) translateY(-80px)';setTimeout(function(){ind.style.transition='';},300);}},{passive:true});
})();

(function(){
    const LIMIT_MS  = 60 * 60 * 1000; // 1 min for testing (change to 60 * 60 * 1000 for 1 hour)
    const STORE_KEY = 'peura_session_start';
    const CODE      = '1212';

    if(!sessionStorage.getItem(STORE_KEY)){
        sessionStorage.setItem(STORE_KEY, Date.now());
    }

    function showBreak(){
        document.getElementById('breakOverlay').classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function hideBreak(){
        document.getElementById('breakOverlay').classList.remove('active');
        document.body.style.overflow = '';
        sessionStorage.setItem(STORE_KEY, Date.now()); // reset timer
    }

    function checkTime(){
        const start = parseInt(sessionStorage.getItem(STORE_KEY));
        if(Date.now() - start >= LIMIT_MS){
            showBreak();
        }
    }

    setInterval(checkTime, 30000);
    window.addEventListener('load', checkTime);

    window.checkBreakCode = function(){
        const val = document.getElementById('breakCodeInput').value;
        const input = document.getElementById('breakCodeInput');
        if(val.length === 4){
            if(val === CODE){
                input.style.borderColor = '#44ff88';
                setTimeout(hideBreak, 300);
                input.value = '';
            } else {
                input.classList.remove('shake');
                void input.offsetWidth;
                input.classList.add('shake');
                input.value = '';
                setTimeout(()=> input.classList.remove('shake'), 400);
            }
        }
    };

    window.tryBreakReturn = function(){
        const val = document.getElementById('breakCodeInput').value;
        const input = document.getElementById('breakCodeInput');
        if(val === CODE){
            input.style.borderColor = '#44ff88';
            setTimeout(hideBreak, 300);
            input.value = '';
        } else {
            input.classList.remove('shake');
            void input.offsetWidth;
            input.classList.add('shake');
            input.value = '';
            setTimeout(()=> input.classList.remove('shake'), 400);
        }
    };
})();


document.querySelectorAll('.track-btn').forEach(btn=>{
    btn.addEventListener('click', function(e){
        e.stopPropagation();
        const userId = this.dataset.user;
        const button = this;

        fetch('', {
            method:'POST',
            headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:'action=toggle_follow&user_id='+userId
        }).then(res=>res.json()).then(data=>{
            if(data.status==='ok'){
                if(data.following){
                    button.classList.add('following');
                    button.textContent='Untrack';
                } else {
                    button.classList.remove('following');
                    button.textContent='Track';
                }
            }
        });
    });
});


document.querySelectorAll('.group-btn').forEach(btn=>{
    btn.addEventListener('click', function(e){
        e.stopPropagation();
        const groupId = this.dataset.group;
        const button = this;

        fetch('', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: 'action=toggle_group&group_id='+groupId
        }).then(res=>res.json()).then(data=>{
            if(data.status === 'ok'){
                if(data.joined){
                    button.classList.add('joined');
                    button.textContent = 'Leave';
                } else {
                    button.classList.remove('joined');
                    button.textContent = 'Join';
                }
            }
        });
    });
});


document.querySelectorAll('.follow-btn').forEach(btn=>{
    btn.addEventListener('click', function(e){
        e.stopPropagation();
        const userId = this.dataset.user;
        const button = this;

        fetch('', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: 'action=toggle_follow&user_id='+userId
        }).then(res=>res.json()).then(data=>{
            if(data.status === 'ok'){
                if(data.following){
                    button.classList.add('following');
                    button.textContent = 'Untrack';
                } else {
                    button.classList.remove('following');
                    button.textContent = 'Track';
                }
            }
        });
    });
});

/* SHOW ALL USERS TOGGLE */
function toggleUsers() {
    let users = document.getElementById("allUsers");
    
    // Check current display state
    if (users.style.display === "none" || users.style.display === "") {
        users.style.display = "flex"; // Shows the row perfectly aligned
    } else {
        users.style.display = "none"; // Hides it again
    }
}

/* FAVORITE / REPOST BUTTONS */
function sendAction(action, memeId, cb){
    fetch("search.php", {
        method: "POST",
        headers: {"Content-Type":"application/x-www-form-urlencoded"},
        body: `action=${action}&meme_id=${memeId}`
    })
    .then(res => res.json())
    .then(cb);
}

/* ================= REPOST ================= */
document.querySelectorAll('.repost-btn').forEach(btn=>{
    btn.addEventListener('click', function(e){
        e.stopPropagation();

        const id = this.dataset.id;
        const countEl = this.querySelector('.count');

        sendAction("toggle_repost", id, (data)=>{
            this.classList.toggle("active");
            if(data.count !== undefined){
                countEl.textContent = data.count;
            }
        });
    });
});

/* ================= FAVORITE ================= */
document.querySelectorAll('.fav-btn').forEach(btn=>{
    btn.addEventListener('click', function(e){
        e.stopPropagation();

        const id = this.dataset.id;
        const countEl = this.querySelector('.count');

        sendAction("toggle_favorite", id, (data)=>{
            this.classList.toggle("active");
            if(data.count !== undefined){
                countEl.textContent = data.count;
            }
        });
    });
});

/* ================= LIKE ================= */
document.querySelectorAll('.like-btn').forEach(btn=>{
    btn.addEventListener('click', function(e){
        e.stopPropagation();

        const id = this.dataset.id;
        const countEl = this.querySelector('.count');

        sendAction("toggle_like", id, (data)=>{
            this.classList.toggle("active");
            if(data.count !== undefined){
                countEl.textContent = data.count;
            }
            // update color based on active state
            const icon = this.querySelector('i');
            if(icon){
                icon.style.color = this.classList.contains('active') ? '#ff2b55' : '';
            }
        });
    });
});

// ✨ GENERATE RANDOM FREE-FLOWING PARTICLES
const container = document.getElementById('particles');

for (let i = 0; i < 70; i++) {
    const p = document.createElement('div');
    p.className = 'particle';

    // Bigger sizes
    const size = Math.random() * 2 + 3; // 3px → 9px
    p.style.width = size + 'px';
    p.style.height = size + 'px';

    // Random starting position
    p.style.left = Math.random() * 100 + 'vw';
    p.style.top = Math.random() * 100 + 'vh';

    // Random movement values (X + Y drift)
    p.style.setProperty('--x-move', (Math.random() * 200 - 100) + 'px');
    p.style.setProperty('--y-move', (Math.random() * -300 - 100) + 'px');

    // Timing
    p.style.animationDuration = (Math.random() * 15 + 10) + 's';

    // Slight glow variation
    p.style.opacity = Math.random() * 0.5 + 0.2;

    container.appendChild(p);
}

function downloadMedia(url){
    const a=document.createElement('a'); a.href=url; a.download=''; a.click();
}

</script>

</body>
</html>