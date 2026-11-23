<?php
session_start();
include 'config.php';

/* ================================================================
   SHARED: Crossroads-style scored suggested users (same as home.php)
================================================================ */
if(!function_exists('getSuggestedUsers')){
function getSuggestedUsers($conn, $user_id, $limit = 20, $exclude_ids = []){
    $sug_user_id = (int)$user_id;
    if(!$sug_user_id) return [];

    $blocked_ids = [0];
    $bres = $conn->query("SELECT blocked_id FROM blocks WHERE blocker_id = $sug_user_id UNION SELECT blocker_id FROM blocks WHERE blocked_id = $sug_user_id");
    if($bres) while($br = $bres->fetch_assoc()) $blocked_ids[] = (int)$br['blocked_id'];

    $exclude_ids = !empty($exclude_ids) ? array_values(array_map('intval', $exclude_ids)) : [0];

    $blocked_ph = implode(',', array_fill(0, count($blocked_ids), '?'));
    $exclude_ph = implode(',', array_fill(0, count($exclude_ids), '?'));

    $sql = "
        SELECT
            u.id, u.username, u.profile_pic, u.last_active,
            (SELECT COUNT(*) FROM followers f2 WHERE f2.follower_id=? AND f2.following_id=u.id) AS is_following,

            (CASE WHEN EXISTS (
                SELECT 1 FROM followers f1
                JOIN followers f2b ON f1.following_id = f2b.following_id
                   AND f1.follower_id = ? AND f2b.follower_id = u.id
            ) THEN 1 ELSE 0 END) AS sig_follow,

            (CASE WHEN EXISTS (
                SELECT 1 FROM group_members gm1
                JOIN group_members gm2 ON gm1.group_id = gm2.group_id
                   AND gm1.user_id = ? AND gm2.user_id = u.id
            ) THEN 1 ELSE 0 END) AS sig_group,

            (CASE WHEN EXISTS (
                SELECT 1 FROM likes l1
                JOIN likes l2 ON l1.meme_id = l2.meme_id
                   AND l1.user_id = ? AND l2.user_id = u.id
            ) THEN 1 ELSE 0 END) AS sig_like,

            (CASE WHEN EXISTS (
                SELECT 1 FROM reposts r1
                JOIN reposts r2 ON r1.meme_id = r2.meme_id
                   AND r1.user_id = ? AND r2.user_id = u.id
            ) THEN 1 ELSE 0 END) AS sig_repost,

            (CASE WHEN EXISTS (
                SELECT 1 FROM favorites fv1
                JOIN favorites fv2 ON fv1.meme_id = fv2.meme_id
                   AND fv1.user_id = ? AND fv2.user_id = u.id
            ) THEN 1 ELSE 0 END) AS sig_fav,

            (CASE WHEN EXISTS (
                SELECT 1 FROM pod_discussion_replies pr1
                JOIN pod_discussion_replies pr2 ON pr1.discussion_id = pr2.discussion_id
                   AND pr1.user_id = ? AND pr2.user_id = u.id
            ) THEN 1 ELSE 0 END) AS sig_pod,

            (CASE WHEN EXISTS (
                SELECT 1 FROM collection_items ci1
                JOIN collections c1 ON ci1.collection_id = c1.id AND c1.user_id = ?
                JOIN collection_items ci2 ON ci1.meme_id = ci2.meme_id
                JOIN collections c2 ON ci2.collection_id = c2.id AND c2.user_id = u.id
            ) THEN 1 ELSE 0 END) AS sig_collection,

            (CASE WHEN EXISTS (
                SELECT 1 FROM profile_views pv1
                JOIN profile_views pv2 ON pv1.profile_id = pv2.profile_id
                   AND pv1.viewer_id = ? AND pv2.viewer_id = u.id
            ) THEN 1 ELSE 0 END) AS sig_view

        FROM users u
        WHERE u.id != ?
          AND u.id NOT IN ($blocked_ph)
          AND u.id NOT IN ($exclude_ph)
          AND u.id NOT IN (SELECT following_id FROM followers WHERE follower_id = ?)
        HAVING (sig_follow + sig_group + sig_like + sig_repost + sig_fav + sig_pod + sig_collection + sig_view) >= 2
        ORDER BY (sig_follow + sig_group + sig_like + sig_repost + sig_fav + sig_pod + sig_collection + sig_view) DESC
        LIMIT $limit
    ";

    $types  = str_repeat('i', 9) . 'i' . str_repeat('i', count($blocked_ids)) . str_repeat('i', count($exclude_ids)) . 'i';
    $params = array_merge(
        array_fill(0, 9, $sug_user_id),
        [$sug_user_id],
        $blocked_ids,
        $exclude_ids,
        [$sug_user_id]
    );

    $stmt = $conn->prepare($sql);
    $refs = [];
    foreach($params as $k => $v){ $refs[$k] = &$params[$k]; }
    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while($r = $res->fetch_assoc()){
        $overlaps = [];
        if(!empty($r['sig_follow']))     $overlaps[] = 'same creators';
        if(!empty($r['sig_group']))      $overlaps[] = 'same communities';
        if(!empty($r['sig_like']))       $overlaps[] = 'same content';
        if(!empty($r['sig_repost']))     $overlaps[] = 'same reposts';
        if(!empty($r['sig_fav']))        $overlaps[] = 'same saves';
        if(!empty($r['sig_pod']))        $overlaps[] = 'same discussions';
        if(!empty($r['sig_collection'])) $overlaps[] = 'similar collections';
        if(!empty($r['sig_view']))       $overlaps[] = 'same profiles viewed';
        $r['reason'] = implode(', ', array_slice($overlaps, 0, 2));
        $rows[] = $r;
    }

    // ── Fallback: not enough overlap-based suggestions, top up with random ──
    if(count($rows) < $limit){
        $have_ids = array_map(function($r){ return (int)$r['id']; }, $rows);
        $skip_ids = array_merge($blocked_ids, $exclude_ids, $have_ids, [0]);
        $skip_ph  = implode(',', array_fill(0, count($skip_ids), '?'));
        $need     = $limit - count($rows);

        $sql2 = "
            SELECT u.id, u.username, u.profile_pic, u.last_active,
            (SELECT COUNT(*) FROM followers f WHERE f.follower_id=? AND f.following_id=u.id) as is_following
            FROM users u
            WHERE u.id != ?
              AND u.id NOT IN ($skip_ph)
              AND u.id NOT IN (SELECT following_id FROM followers WHERE follower_id = ?)
            ORDER BY RAND() LIMIT $need
        ";
        $types2  = 'ii' . str_repeat('i', count($skip_ids)) . 'i';
        $params2 = array_merge([$sug_user_id, $sug_user_id], $skip_ids, [$sug_user_id]);

        $stmt2 = $conn->prepare($sql2);
        $refs2 = [];
        foreach($params2 as $k => $v){ $refs2[$k] = &$params2[$k]; }
        array_unshift($refs2, $types2);
        call_user_func_array([$stmt2, 'bind_param'], $refs2);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        while($r = $res2->fetch_assoc()){
            $r['sig_follow'] = $r['sig_group'] = $r['sig_like'] = $r['sig_repost'] = 0;
            $r['sig_fav'] = $r['sig_pod'] = $r['sig_collection'] = $r['sig_view'] = 0;
            $r['reason'] = '';
            $rows[] = $r;
        }
    }

    return $rows;
}
}

$current_user = $_SESSION['user_id'] ?? 0;
if(!$current_user){ 
    if(isset($_POST['action']) || isset($_GET['ajax_search'])){
        header('Content-Type: application/json');
        echo json_encode(['status'=>'error','message'=>'not logged in']);
        exit;
    }
    header("Location: login.php"); 
    exit(); 
}

// ── nav profile pic ──
$nav_pic = 'uploads/mee.jpeg';
$nav_row = $conn->query("SELECT profile_pic FROM users WHERE id=$current_user LIMIT 1")->fetch_assoc();
if (!empty($nav_row['profile_pic'])) $nav_pic = $nav_row['profile_pic'];

$user = $conn->query("SELECT * FROM users WHERE id=$current_user")->fetch_assoc();

$following_ids = [];
$res = $conn->query("SELECT following_id FROM followers WHERE follower_id=$current_user");
while($row = $res->fetch_assoc()) { $following_ids[] = (int)$row['following_id']; }

/* ── BLOCKED USERS ── */
$blocked_users = [];
$res = $conn->query("SELECT blocked_id FROM blocks WHERE blocker_id=$current_user");
while($row = $res->fetch_assoc()){ $blocked_users[] = (int)$row['blocked_id']; }
$blocked_ids_str = !empty($blocked_users) ? implode(',', $blocked_users) : '0';

/* ── MUTED USERS ── */
$conn->query("
    CREATE TABLE IF NOT EXISTS `muted_users` (
        `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
        `muter_id` int(10) UNSIGNED NOT NULL,
        `muted_id` int(10) UNSIGNED NOT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `unique_mute` (`muter_id`,`muted_id`),
        KEY `fk_muted_id` (`muted_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");
$muted_users = [];
$res = $conn->query("SELECT muted_id FROM muted_users WHERE muter_id=$current_user");
if($res) while($row = $res->fetch_assoc()){ $muted_users[] = (int)$row['muted_id']; }
$muted_ids_str = !empty($muted_users) ? implode(',', $muted_users) : '0';

/* ── AI PERSONALIZATION ── */
$user_top_tags    = [];
$user_tag_weights = [];
$stmt = $conn->prepare("
    SELECT
        LOWER(TRIM(LEADING '#' FROM REGEXP_SUBSTR(m.caption, '#[a-zA-Z0-9_]+'))) AS tag,
        SUM(CASE ui.interaction_type WHEN 'repost' THEN 6.0 WHEN 'favorite' THEN 4.0 WHEN 'like' THEN 2.0 WHEN 'view' THEN 0.5 ELSE 1.0 END * EXP(-0.03 * TIMESTAMPDIFF(HOUR, ui.created_at, NOW()))) AS affinity_score,
        COUNT(*) AS interaction_count
    FROM user_interactions ui
    JOIN memes m ON ui.meme_id = m.id
    WHERE ui.user_id = ? AND m.caption REGEXP '#[a-zA-Z0-9_]+' AND ui.created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)
    GROUP BY tag HAVING affinity_score > 0.5 ORDER BY affinity_score DESC LIMIT 10
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

/* ── TAG SORTING ── */
$all_tags_raw = ['All','Funny','Me','Text','Memes','Gaming','Love','School','Anime','Music','Movies','Sports','Football','Dank','DarkHumor','Wholesome','Cursed','Cringe','Sus','Bruh','LOL','ROFL','Template','Classic','OldButGold','Reaction','Mood','Same','Unexpected','PlotTwist','NoContext','ExplainThis','JustWhy','ImDone','RelatableAF','TooReal','BigMood','Pain','Dead','ICant','SendHelp','ThisAintIt','ItBeLikeThat','WhyTho','LowQuality','HighQuality','DeepFried','Brainrot','NPC','SideEye','Mid','W','L','Rare','Legendary','Throwback'];
$sorted_tags  = ['All'];
$lower_top    = array_map('strtolower', $user_top_tags);
foreach($all_tags_raw as $t){ if($t==='All') continue; if(in_array(strtolower($t), $lower_top)) $sorted_tags[] = $t; }
foreach($all_tags_raw as $t){ if($t==='All') continue; if(!in_array(strtolower($t), $lower_top)) $sorted_tags[] = $t; }

/* ── HELPERS ── */
function recordInteraction($conn, $user_id, $meme_id, $type) {
    $stmt = $conn->prepare("INSERT INTO user_interactions (user_id, meme_id, interaction_type) VALUES (?, ?, ?)");
    $stmt->bind_param("iis", $user_id, $meme_id, $type);
    $stmt->execute();
}

/* ── TEXT POST ── */
if (isset($_POST['post_text'])) {
    $text = trim($_POST['text'] ?? '');
    if ($text === '') exit('error: empty post');
    if (!preg_match('/#[a-zA-Z0-9_]+$/', $text)) exit('error: hashtag required');
    $stmt = $conn->prepare("INSERT INTO memes (uploaded_by, caption, type, file_name) VALUES (?, ?, 'text', '')");
    $stmt->bind_param("is", $current_user, $text);
    $stmt->execute();
    exit('success');
}

/* ── LIKE / REPOST / FAVORITE ── */
if(isset($_POST['ajax_like']) || isset($_POST['ajax_repost']) || isset($_POST['ajax_favorite'])){
    header('Content-Type: application/json');
    $id = (int)$_POST['meme_id'];
    if(isset($_POST['ajax_like'])){
        $check = $conn->query("SELECT id FROM likes WHERE user_id=$current_user AND meme_id=$id");
        if($check->num_rows){ $conn->query("DELETE FROM likes WHERE user_id=$current_user AND meme_id=$id"); $conn->query("DELETE FROM user_interactions WHERE user_id=$current_user AND meme_id=$id AND interaction_type='like'"); $state = false; }
        else { $conn->query("INSERT INTO likes (user_id,meme_id) VALUES ($current_user,$id)"); recordInteraction($conn, $current_user, $id, 'like'); $state = true; }
        $count = $conn->query("SELECT COUNT(*) as c FROM likes WHERE meme_id=$id")->fetch_assoc()['c'];
        echo json_encode(['liked'=>$state,'count'=>$count]);
    } elseif(isset($_POST['ajax_repost'])){
        $check = $conn->query("SELECT id FROM reposts WHERE user_id=$current_user AND meme_id=$id");
        if($check->num_rows){ $conn->query("DELETE FROM reposts WHERE user_id=$current_user AND meme_id=$id"); $conn->query("DELETE FROM user_interactions WHERE user_id=$current_user AND meme_id=$id AND interaction_type='repost'"); $state = false; }
        else { $conn->query("INSERT INTO reposts (user_id,meme_id) VALUES ($current_user,$id)"); recordInteraction($conn, $current_user, $id, 'repost'); $state = true; }
        $count = $conn->query("SELECT COUNT(*) as c FROM reposts WHERE meme_id=$id")->fetch_assoc()['c'];
        echo json_encode(['reposted'=>$state,'count'=>$count]);
    } elseif(isset($_POST['ajax_favorite'])){
        $check = $conn->query("SELECT id FROM favorites WHERE user_id=$current_user AND meme_id=$id");
        if($check->num_rows){ $conn->query("DELETE FROM favorites WHERE user_id=$current_user AND meme_id=$id"); $conn->query("DELETE FROM user_interactions WHERE user_id=$current_user AND meme_id=$id AND interaction_type='favorite'"); $state = false; }
        else { $conn->query("INSERT INTO favorites (user_id,meme_id) VALUES ($current_user,$id)"); recordInteraction($conn, $current_user, $id, 'favorite'); $state = true; }
        $count = $conn->query("SELECT COUNT(*) as c FROM favorites WHERE meme_id=$id")->fetch_assoc()['c'];
        echo json_encode(['favorited'=>$state,'count'=>$count]);
    }
    exit();
}

/* ── BLOCK USER ── */
if (isset($_POST['action']) && $_POST['action'] === 'block_user') {
    header('Content-Type: application/json');
    $target_id = (int)($_POST['target_id'] ?? 0);
    if (!$target_id || $target_id === $current_user) { echo json_encode(['status' => 'error']); exit; }
    $conn->query("INSERT IGNORE INTO blocks (blocker_id, blocked_id) VALUES ($current_user, $target_id)");
    $conn->query("DELETE FROM followers WHERE (follower_id=$current_user AND following_id=$target_id) OR (follower_id=$target_id AND following_id=$current_user)");
    echo json_encode(['status' => 'ok']); exit;
}

/* ── NOT INTERESTED ── */
if (isset($_POST['action']) && $_POST['action'] === 'not_interested') {
    header('Content-Type: application/json');
    $meme_id = (int)($_POST['meme_id'] ?? 0);
    if ($meme_id && $current_user) {
        $conn->query("INSERT IGNORE INTO user_interactions (user_id, meme_id, interaction_type) VALUES ($current_user, $meme_id, 'view')");
        $conn->query("UPDATE memes SET trend_velocity = trend_velocity * 0.5 WHERE id = $meme_id");
    }
    echo json_encode(['status' => 'ok']); exit;
}

/* ── REPORT MEME ── */
if (isset($_POST['action']) && $_POST['action'] === 'report_meme') {
    header('Content-Type: application/json');
    $meme_id = (int)($_POST['meme_id'] ?? 0);
    $reason  = trim($_POST['reason'] ?? 'No reason given');
    if ($meme_id && $current_user) {
        $safe_reason = $conn->real_escape_string($reason);
        $owner = $conn->query("SELECT uploaded_by FROM memes WHERE id=$meme_id")->fetch_assoc()['uploaded_by'] ?? 0;
        if ($owner) $conn->query("INSERT INTO reports (reporter_id, reported_id) VALUES ($current_user, $owner)");
    }
    echo json_encode(['status' => 'ok']); exit;
}

/* ── FILTER / TAG / SEARCH ── */
$filter       = $_GET['filter'] ?? 'trending';
$tag          = $_GET['tag']    ?? 'all';
$search_param = trim($_GET['q'] ?? $_GET['ajax_search'] ?? '');

if (isset($_GET['ajax_search']) && !empty($search_param)) {
    header('Content-Type: application/json');
    $stmt = $conn->prepare("SELECT id, username, profile_pic FROM users WHERE username LIKE ? LIMIT 5");
    $searchTerm = "%$search_param%";
    $stmt->bind_param("s", $searchTerm);
    $stmt->execute();
    $res         = $stmt->get_result();
    $found_users = [];
    while($u = $res->fetch_assoc()) { $found_users[] = $u; }
    echo json_encode($found_users); exit;
}

/* ── ORDER LOGIC ── */
switch($filter){
    case 'most_liked':    $order_logic = "likes_count DESC"; break;
    case 'most_reposted': $order_logic = "reposts_count DESC"; break;
    case 'most_favorited':$order_logic = "favorites_count DESC"; break;
    case 'most_viewed':   $order_logic = "m.views DESC"; break;
    case 'newest':        $order_logic = "m.created_at DESC"; break;
    case 'following':     $order_logic = "RAND()"; break;
    case 'discover':      $order_logic = "RAND()"; break;
    default:
        $order_logic = "(((likes_count * 4) + (reposts_count * 7) + (favorites_count * 4) + (LOG10(m.views + 1) * 2)) / POW(TIMESTAMPDIFF(HOUR, m.created_at, NOW()) + 2, 1.5)) + (RAND() * 0.2) DESC";
        break;
}

/* ── WHERE CLAUSES ── */
if (!empty($search_param)) {
    $where_clauses = [
        "m.uploaded_by NOT IN ($blocked_ids_str)",
        "m.uploaded_by NOT IN ($muted_ids_str)",
        "(m.is_ad = 0 OR ma.status = 'approved')",   /* ← NEW */
    ];
} else {
    $where_clauses = [
        "m.type='text'",
        "m.uploaded_by NOT IN ($blocked_ids_str)",
        "m.uploaded_by NOT IN ($muted_ids_str)",
        "(m.is_ad = 0 OR ma.status = 'approved')",   /* ← NEW */
    ];
}

$params = [];
$types  = "";

if(!empty($search_param)){
    $where_clauses[] = "(m.caption LIKE ? OR u.username LIKE ?)";
    $params[] = "%$search_param%";
    $params[] = "%$search_param%";
    $types   .= "ss";
}
if($tag !== 'all'){
    $where_clauses[] = "m.caption LIKE ?";
    $params[] = "%#$tag%";
    $types   .= "s";
}
if($filter === 'following'){
    $where_clauses[] = "m.uploaded_by IN (SELECT following_id FROM followers WHERE follower_id=?)";
    $params[] = $current_user;
    $types   .= "i";
}

/* ── AI BOOST ORDER ── */
$final_order = " ORDER BY $order_logic";
if($filter === 'trending' && !empty($user_top_tags)){
    $boost_cases = [];
    foreach($user_top_tags as $t){
        $weight        = $user_tag_weights[$t] ?? 1.0;
        $multiplier    = min(2.5, round(1.0 + ($weight / 20.0), 3));
        $safe_tag      = $conn->real_escape_string($t);
        $boost_cases[] = "WHEN m.caption LIKE '%#$safe_tag%' THEN $multiplier";
    }
    $case_sql    = "CASE " . implode(' ', $boost_cases) . " ELSE 1.0 END";
    $final_order = " ORDER BY ((((likes_count * 4) + (reposts_count * 7) + (favorites_count * 4) + (LOG10(m.views + 1) * 2)) / POW(TIMESTAMPDIFF(HOUR, m.created_at, NOW()) + 2, 1.5)) + (RAND() * 0.2)) * ($case_sql) DESC";
}

/* ── MAIN QUERY ── */
$where_sql   = "WHERE " . implode(' AND ', $where_clauses);
$posts_query = "SELECT m.*, u.username, u.nickname, u.profile_pic,
    (SELECT COUNT(*) FROM likes     WHERE meme_id=m.id) AS likes_count,
    (SELECT COUNT(*) FROM reposts   WHERE meme_id=m.id) AS reposts_count,
    (SELECT COUNT(*) FROM favorites WHERE meme_id=m.id) AS favorites_count,
    (SELECT COUNT(*) FROM likes     WHERE meme_id=m.id AND user_id=$current_user) AS liked,
    (SELECT COUNT(*) FROM reposts   WHERE meme_id=m.id AND user_id=$current_user) AS reposted,
    (SELECT COUNT(*) FROM favorites WHERE meme_id=m.id AND user_id=$current_user) AS favorited,
    (SELECT COUNT(*) FROM followers WHERE follower_id=$current_user AND following_id=m.uploaded_by) AS is_following
    FROM memes m
    JOIN users u ON u.id = m.uploaded_by
    LEFT JOIN meme_ads ma ON ma.meme_id = m.id
    $where_sql $final_order";   /* ← added LEFT JOIN meme_ads line above */

$stmt = $conn->prepare($posts_query);
if(!empty($params)){ $stmt->bind_param($types, ...$params); }
$stmt->execute();
$posts_result = $stmt->get_result();

/* ── SOCIAL PROOF ── */
$all_posts = [];
$post_ids  = [];
while($row = $posts_result->fetch_assoc()){ $all_posts[] = $row; $post_ids[] = $row['id']; }

$social_proof_map = [];
if($current_user && !empty($post_ids)){
    $id_list   = implode(',', $post_ids);
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
$posts = $all_posts;

/* ── DELETE POST ── */
if(isset($_POST['ajax_delete_post'])){
    $id    = (int)$_POST['meme_id'];
    $check = $conn->query("SELECT uploaded_by FROM memes WHERE id=$id")->fetch_assoc();
    if($check && $check['uploaded_by'] == $current_user){
        $conn->query("DELETE FROM memes WHERE id=$id");
        echo json_encode(['deleted'=>true]);
    } else {
        echo json_encode(['deleted'=>false]);
    }
    exit();
}

$your_posts = $conn->query("SELECT * FROM memes WHERE uploaded_by=$current_user AND type='text' ORDER BY created_at DESC LIMIT 10");
$active_tag = $tag;

/* ── TOGGLE FOLLOW ── */
if (isset($_POST['toggle_follow'])) {
    header('Content-Type: application/json');
    $target_id = (int)$_POST['user_id'];
    if ($target_id === $current_user) { echo json_encode(['status' => 'error', 'message' => 'Self-follow']); exit; }
    $check = $conn->query("SELECT id FROM followers WHERE follower_id=$current_user AND following_id=$target_id");
    if ($check->num_rows > 0) {
        $conn->query("DELETE FROM followers WHERE follower_id=$current_user AND following_id=$target_id");
        $conn->query("INSERT INTO unfollow_log (unfollowed_by, user_id) VALUES ($current_user, $target_id)");
        $following = false;
    } else {
        $conn->query("INSERT INTO followers (follower_id, following_id) VALUES ($current_user, $target_id)");
        $conn->query("DELETE FROM unfollow_log WHERE unfollowed_by=$current_user AND user_id=$target_id");
        $following = true;
    }
    echo json_encode(['status' => 'success', 'following' => $following]); exit;
}
?>
<?php
$total_unread = 0;
if (!empty($_SESSION['accounts'])) {
    foreach ($_SESSION['accounts'] as $acc_id) {
        $acc_id = (int)$acc_id;
        $unread = $conn->query("SELECT COUNT(*) as cnt FROM messages WHERE receiver_id = $acc_id AND seen = 0")->fetch_assoc()['cnt'] ?? 0;
        $total_unread += $unread;
    }
}
?>

<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dezzme Text Feed</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&family=Syne:wght@700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
html,body{width:100%;max-width:100%;overflow-x:hidden;}

:root{
    --red:#FF2D2D;
    --red-dim:rgba(255,45,45,0.18);
    --red-glow:rgba(255,45,45,0.35);
    --blue:#0057FF;
    --blue-dim:rgba(0,87,255,0.14);
    --ink:#06071A;
    --ink-2:#0C0E28;
    --ink-3:#111438;
    --surface:rgba(255,255,255,0.04);
    --surface-2:rgba(255,255,255,0.07);
    --border:rgba(255,255,255,0.07);
    --border-2:rgba(255,255,255,0.13);
    --text:#F0F2FF;
    --text-2:rgba(220,224,255,0.65);
    --text-3:rgba(180,188,255,0.4);
    --radius-sm:10px;
    --radius-md:16px;
    --radius-lg:22px;
    --radius-xl:30px;
    --font-head:  'Poppins', sans-serif;
--font-body:  'Poppins', sans-serif;
    --sidebar-w:80px;
    --card-glow:0 0 0 1px rgba(255,255,255,0.06),0 8px 32px rgba(0,0,0,0.45);
    --card-glow-hover:0 0 0 1px rgba(255,45,45,0.25),0 16px 48px rgba(0,0,0,0.6),0 0 60px rgba(255,45,45,0.08);
}

html{scroll-behavior:smooth;}

body{
    margin:0;
    font-family: var(--font-body);
    color:var(--text);
    display:flex;
    background: linear-gradient(-45deg,#010218,#070924,#070924);
    background-attachment: fixed;
    overflow-x:hidden;
    min-height:100vh;
}

/* ── PULL-TO-REFRESH ── */
#ptr-indicator{
    position:fixed;
    top:0;left:50%;
    transform:translateX(-50%) translateY(-80px);
    z-index:99999;
    display:flex;
    align-items:center;
    gap:10px;
    background:rgba(10,10,30,0.92);
    border:1px solid rgba(255,45,45,0.3);
    border-radius:999px;
    padding:8px 20px 8px 14px;
    font-size:13px;
    font-weight:600;
    color:white;
    backdrop-filter:blur(20px);
    box-shadow:0 8px 32px rgba(0,0,0,0.5);
    transition:transform 0.3s cubic-bezier(0.34,1.56,0.64,1),opacity 0.3s;
    pointer-events:none;
    white-space:nowrap;
}
#ptr-indicator.visible{transform:translateX(-50%) translateY(16px);}
#ptr-spinner{
    width:20px;height:20px;
    border:2px solid rgba(255,45,45,0.3);
    border-top-color:#FF2D2D;
    border-radius:50%;
    animation:spin 0.7s linear infinite;
    flex-shrink:0;
}
@keyframes spin{to{transform:rotate(360deg);}}

/* ── SKELETON LOADER ── */
.skeleton-feed{display:flex;flex-direction:column;gap:15px;padding:0;}
.skeleton-card{
    width:720px;max-width:100%;
    padding:15px;
    border-radius:20px;
    background:rgba(255,255,255,0.04);
    border:1px solid rgba(255,255,255,0.06);
    display:flex;flex-direction:column;gap:12px;
    overflow:hidden;
}
.skel{
    background:linear-gradient(90deg,rgba(255,255,255,0.04) 25%,rgba(255,255,255,0.09) 50%,rgba(255,255,255,0.04) 75%);
    background-size:200% 100%;
    animation:shimmer 1.6s infinite;
    border-radius:8px;
}
@keyframes shimmer{0%{background-position:200% 0;}100%{background-position:-200% 0;}}
.skel-avatar{width:38px;height:38px;border-radius:50%;}
.skel-line{height:12px;border-radius:6px;}
.skel-text-block{height:60px;border-radius:12px;}
.skel-row{display:flex;align-items:center;gap:10px;}

#bgCanvas{position:fixed;top:0;left:0;width:100%;height:100%;z-index:-2;}
#particles{position:fixed;width:100%;height:100%;overflow:hidden;top:0;left:0;z-index:-1;}
.particle{position:absolute;background:white;border-radius:50%;pointer-events:none;box-shadow:0 0 10px rgba(255,255,255,0.6);animation:floatParticle linear infinite;}
@keyframes floatParticle{0%{transform:translate(0,0) scale(1);opacity:0;}10%{opacity:1;}50%{transform:translate(var(--x-move),var(--y-move)) scale(1.2);}100%{transform:translate(calc(var(--x-move)*-1),calc(var(--y-move)*-1)) scale(0.8);opacity:0;}}

.glass{
    background:transparent;
    backdrop-filter:blur(18px);
    border:transparent;
    border-radius:30px;
    transition:all 0.3s ease;
    box-shadow:0 4px 30px rgba(0,0,0,0.5);
}

/* ═══════════════════════════════════════
   LEFT SIDEBAR — desktop
   ═══════════════════════════════════════ */
.sidebar {
    width: 100px;
    height: 100vh;
    position: fixed;
    left: 0; top: 0;
    display: flex;
    background: transparent;
    flex-direction: column;
    align-items: center;
    padding: 20px 0;
    gap: 30px;
    z-index: 100;
}

.sidebar-logo {
    width: 50px; height: 50px;
    border-radius: 25%;
    cursor: pointer;
    overflow: hidden;
    animation: bounceLogo 2s infinite;
    flex-shrink: 0;
}

.sidebar-logo img {
    width: 100%; height: 100%;
    object-fit: cover;
}

.sidebar-logo:hover {
    box-shadow: 0 0 15px #fff;
    transform: scale(1.15);
}

@keyframes bounceLogo {
    0%, 100% { transform: translateY(0); }
    50%       { transform: translateY(-6px); }
}

.sidebar button {
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
    cursor:pointer;
}

.sidebar button:hover {
    transform: translateY(-3px) scale(1.05);
    background: rgba(255,255,255,0.15);
    box-shadow: 0 10px 25px rgba(255,255,255,0.2), 0 0 15px rgba(255,255,255,0.1);
}

.sidebar button:active {
    transform: scale(0.95);
    box-shadow: inset 0 4px 10px rgba(0,0,0,0.4);
}

.sidebar button i { transition: transform 0.3s ease; }
.sidebar button:hover i { transform: scale(1.2) rotate(5deg); }

.sidebar button::before {
    content: "";
    position: absolute;
    top: 0; left: -80%;
    width: 50%; height: 100%;
    background: linear-gradient(120deg, transparent, rgba(255,255,255,0.4), transparent);
    transform: skewX(-20deg);
    transition: 0.6s;
}
.sidebar button:hover::before { left: 120%; }

#msgBtn {
    margin-top: auto;
    animation: bounceLogo 2s infinite;
}

/* ══════════════════════════════════════
   MAIN
══════════════════════════════════════ */
.main{
    margin-left:90px;
    margin-right:340px;
    flex:1;
    padding:20px;
    display:flex;
    flex-direction:column;
    gap:15px;
    width:auto;
    max-width:100%;
    min-width:0;
}

/* ══════════════════════════════════════
   SEARCH BOX
══════════════════════════════════════ */
.search-box{
    display:flex;
    align-items:center;
    gap:8px;
    position:sticky;
    top:0;
    width:100%;
    max-width:755px;
    box-sizing:border-box;
    height:56px;
    padding:6px 6px 6px 18px;
    margin-bottom:4px;
    background:rgba(255,255,255,0.04);
    backdrop-filter:blur(24px);
    -webkit-backdrop-filter:blur(24px);
    border:1px solid rgba(255,255,255,0.08);
    border-radius:18px;
    z-index:999;
    transition:border-color 0.3s,box-shadow 0.3s;
}
.search-box:focus-within{
    border-color:rgba(255,45,45,0.35);
    box-shadow:0 0 0 4px rgba(255,45,45,0.06),0 16px 40px rgba(0,0,0,0.35);
}
.search-box-icon{color:rgba(255,255,255,0.25);font-size:17px;flex-shrink:0;pointer-events:none;transition:color 0.3s;}
.search-box:focus-within .search-box-icon{color:rgba(255,45,45,0.7);}
.search-box input{flex:1;border:none;background:transparent;color:white;outline:none;font-family:var(--font-body);font-size:14px;font-weight:300;}
.search-box input::placeholder{color:rgba(255,255,255,0.2);}
.search-box button{border:none;background:#FF2D2D;width:44px;height:44px;border-radius:12px;cursor:pointer;color:white;font-size:16px;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:all 0.2s ease;position:relative;overflow:hidden;}
.search-box button:hover{background:#ff1a1a;box-shadow:0 4px 16px rgba(255,45,45,0.45);transform:scale(1.04);}
.search-box button:active{transform:scale(0.95);}

.search-results{
    position:absolute;top:110%;left:0;width:100%;
    background:rgba(8,8,22,0.95);
    backdrop-filter:blur(28px);
    border:1px solid rgba(255,255,255,0.08);
    border-radius:16px;
    max-height:320px;overflow-y:auto;
    z-index:9999;
    box-shadow:0 20px 60px rgba(0,0,0,0.8);
}
.search-result-item{display:flex;align-items:center;gap:12px;padding:11px 14px;transition:0.2s;cursor:pointer;}
.search-result-item:hover{background:rgba(255,45,45,0.1);border-radius:10px;margin:0 5px;}
.search-result-item img{width:40px;height:40px;border-radius:12px;object-fit:cover;border:1.5px solid rgba(255,45,45,0.5);flex-shrink:0;}
.search-result-item span{color:white;font-size:13px;}

/* ══════════════════════════════════════
   TAG BAR
══════════════════════════════════════ */
.tag-bar-wrap{
    display:flex;
    align-items:center;
    gap:8px;
    width:100%;
    max-width:755px;
}
.tag-scroll-btn{
    width:32px;height:32px;border-radius:50%;
    border:1px solid rgba(255,255,255,0.12);
    background:rgba(255,255,255,0.05);
    backdrop-filter:blur(10px);
    color:white;font-size:14px;
    cursor:pointer;display:flex;align-items:center;justify-content:center;
    flex-shrink:0;transition:0.2s;
    -webkit-tap-highlight-color:transparent;
}
.tag-scroll-btn:hover{background:rgba(255,45,45,0.7);border-color:transparent;box-shadow:0 0 14px rgba(255,45,45,0.35);}
.tag-scroll-btn:active{transform:scale(0.9);}
.tag-scroll-btn.mobile-hidden{display:none;}

.tag-scrollbar-wrapper{flex:1;overflow:hidden;}
.tag-scrollbar{
    display:flex;gap:8px;
    overflow-x:auto;overflow-y:hidden;
    padding:8px 2px;
    scroll-behavior:smooth;
    -webkit-overflow-scrolling:touch;
    white-space:nowrap;
    border-radius:14px;
    scrollbar-width:none;
}
.tag-scrollbar::-webkit-scrollbar{display:none;}
.tag-scrollbar>a{display:inline-block;}

.tag-pill{
    position:relative;overflow:hidden;
    padding:7px 14px;border-radius:999px;
    text-decoration:none;color:rgba(255,255,255,0.7);
    font-size:12px;font-weight:500;
    background:rgba(255,255,255,0.05);
    border:1px solid rgba(255,255,255,0.1);
    white-space:nowrap;flex-shrink:0;
    transition:0.2s ease;
    -webkit-tap-highlight-color:transparent;
}
.tag-pill:hover{transform:translateY(-1px);border-color:rgba(255,45,45,0.5);color:white;box-shadow:0 0 14px rgba(255,45,45,0.2);}
.tag-pill.active{
    background:rgba(255,45,45,0.15);
    border:1px solid rgba(255,45,45,0.5);
    color:#FF5566;
    font-weight:600;
    box-shadow:0 0 20px rgba(255,45,45,0.18),inset 0 0 12px rgba(255,45,45,0.08);
}
.tag-pill.user-affinity{border-color:rgba(255,45,45,0.28);color:rgba(255,180,180,0.85);}

/* ══════════════════════════════════════
   FEED & POST CARD
══════════════════════════════════════ */
.feed{display:flex;align-items:flex-start;flex-direction:column;gap:14px;padding-bottom:4px;}

.post-card{
    width:720px;max-width:100%;
    padding:16px;border-radius:20px;
    display:flex;flex-direction:column;gap:11px;
    background:rgba(255,255,255,0.034);
    border:transparent;
    cursor:pointer;
    transition:transform 0.25s ease,box-shadow 0.25s ease,border-color 0.25s ease;
    box-shadow:var(--card-glow);
    animation:cardIn 0.4s ease both;
}
@keyframes cardIn{from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:translateY(0);}}
.post-card:hover{
    transform: translateY(-3px) scale(1.03);
}

.post-header{display:flex;align-items:center;justify-content:space-between;}
.post-header img{
    width:40px;height:40px;border-radius:50%;
    border:2px solid rgb(241, 28, 28);
    object-fit:cover;transition:all 0.3s;cursor:pointer;flex-shrink:0;
}
.post-header img:hover{transform:scale(1.12);box-shadow:0 6px 18px rgb(244, 46, 46);border-color:#FF4C4C;}

.post-content{font-size:clamp(0.75rem,2.5vw,0.92rem);line-height:1.6;color:rgba(255,255,255,0.82);word-break:break-word;}

.post-actions{display:flex;gap:14px;align-items:center;}
.post-actions button{
    border:none;background:transparent;color:rgb(255, 255, 255);
    cursor:pointer;font-size:13px;font-family:var(--font-body);
    -webkit-tap-highlight-color:transparent;
    min-width:44px;min-height:44px;
    display:flex;align-items:center;justify-content:center;gap:5px;
    border-radius:10px;padding:0 10px;
    transition:all 0.2s;
}
.post-actions button:hover{color:white;}
.post-actions button i{font-size:15px;}

/* ══════════════════════════════════════
   FOLLOW BTN
══════════════════════════════════════ */
.follow-btn{
    background:transparent;
    border:1.5px solid rgba(255,43,43,0.45);
    color:rgba(255,255,255,0.75);
    padding:6px 16px;border-radius:999px;
    cursor:pointer;transition:all 0.25s;
    backdrop-filter:blur(8px);
    font-weight:600;font-size:12px;
    position:relative;overflow:hidden;
    min-width:80px;text-align:center;
    -webkit-tap-highlight-color:transparent;
    white-space:nowrap;flex-shrink:0;
}
.follow-btn:hover{background:rgba(255,45,45,0.85);border-color:transparent;color:white;transform:translateY(-1px);box-shadow:0 6px 18px rgba(255,45,45,0.3);}
.follow-btn:active{transform:scale(0.95);}
.follow-btn.following{color:#FF4C4C;border-color:rgba(255,76,76,0.4);}
.follow-btn.following:hover{background:#FF4C4C;color:white;}

/* ══════════════════════════════════════
   SOCIAL PROOF
══════════════════════════════════════ */
.social-proof-strip{display:flex;flex-direction:row;gap:5px;align-items:center;padding:2px 0;}
.sp-avatar-wrap{position:relative;display:inline-block;text-decoration:none;margin-right:4px;}
.sp-avatar-wrap:nth-child(1){animation:spFloat 3s ease-in-out infinite;}
.sp-avatar-wrap:nth-child(2){animation:spFloat 3s ease-in-out infinite 0.4s;}
.sp-avatar-wrap:nth-child(3){animation:spFloat 3s ease-in-out infinite 0.8s;}
.sp-avatar-wrap:nth-child(4){animation:spFloat 3s ease-in-out infinite 1.2s;}
@keyframes spFloat{0%,100%{transform:translateY(0);}50%{transform:translateY(-4px);}}
.social-proof-strip .sp-avatar{width:35px!important;height:35px!important;min-width:35px!important;max-width:35px!important;border-radius:50%!important;object-fit:cover!important;border:2px solid rgba(255,45,45,0.7)!important;display:block!important;box-shadow:0 3px 10px rgba(0,0,0,0.5);}
.sp-action-badge{position:absolute;bottom:-2px;right:-2px;width:14px;height:14px;border-radius:50%;display:flex;align-items:center;justify-content:center;border:transparent;z-index:2;}
.sp-action-badge i{font-size:7px;color:white;line-height:1;}

/* ══════════════════════════════════════
   ENGAGEMENT ROW
══════════════════════════════════════ */
.eng-row{display:flex;align-items:center;gap:8px;padding:4px 6px;cursor:pointer;-webkit-tap-highlight-color:transparent;border-radius:10px;transition:background 0.2s;}
.eng-row:hover{background:rgba(255,255,255,0.04);}
.eng-avatars{display:flex;flex-direction:row;}
.eng-avatar{width:18px!important;height:18px!important;min-width:18px!important;border-radius:50%!important;object-fit:cover!important;border:1.5px solid rgba(255,255,255,0.4)!important;margin-right:-4px;display:block!important;}
.eng-avatars .eng-avatar:last-child{margin-right:4px;}
.eng-text{font-size:0.69rem;color:rgba(255,255,255,0.55);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1;}
.eng-text strong{color:rgba(255,255,255,0.85);font-weight:600;}

/* ══════════════════════════════════════
   TOP BAR (panel + kebab row)
══════════════════════════════════════ */
.top-controls-row{
    display:flex;
    align-items:center;
    justify-content:flex-end;
    gap:8px;
    position:fixed;
    top:16px;right:16px;
    z-index:9999;
}

/* PANEL TOGGLE BTN */
.panel-toggle-btn{
    display:flex;align-items:center;gap:8px;
    height:42px;padding:0 16px;
    border-radius:14px;
    border:1px solid rgba(255,255,255,0.1);
    background:rgba(10,10,28,0.7);
    backdrop-filter:blur(20px);
    -webkit-backdrop-filter:blur(20px);
    color:white;font-size:13px;font-weight:600;
    font-family:var(--font-body);
    cursor:pointer;transition:all 0.25s;
    box-shadow:0 4px 20px rgba(0,0,0,0.4);
    white-space:nowrap;
    -webkit-tap-highlight-color:transparent;
}
.panel-toggle-btn:hover{background:rgba(255,45,45,0.15);border-color:rgba(255,45,45,0.4);box-shadow:0 6px 24px rgba(255,45,45,0.15);}
.panel-toggle-btn i{font-size:16px;transition:transform 0.3s;}
.panel-toggle-btn.active i{transform:rotate(90deg);}

/* KEBAB BTN (beside panel btn) */
.global-kebab-wrap{position:relative;}
.global-kebab-btn{
    display:flex;align-items:center;justify-content:center;
    width:42px;height:42px;
    border-radius:14px;
    background:none;
    border: none;
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    color:white;font-size:18px;
    cursor:pointer;transition:all 0.25s;
    -webkit-tap-highlight-color:transparent;
}

.global-kebab-btn:hover {
    color: #ff2a2a;
    transform: scale(1.1);
}

.global-kebab-dropdown {
    display: none;
    position: absolute;
    top: 45px;
    /* Aligns to the right edge so it doesn't overflow the screen */
    right: 30px;
    background: rgba(255, 255, 255, 0.08);
    backdrop-filter: blur(15px);
    -webkit-backdrop-filter: blur(15px);
    border-radius: 12px;
    min-width: clamp(160px, 40vw, 200px);
    flex-direction: column;
    z-index: 999;
    box-shadow: 0 8px 25px rgba(0,0,0,0.35);
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.global-kebab-dropdown a {
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

.global-kebab-dropdown a:last-child { border-bottom: none; }

.global-kebab-dropdown a i {
    color: #ff2a2a;
    transition: 0.3s;
    width: 18px;
    text-align: center;
}

.global-kebab-dropdown a:hover {
    background: rgba(255,255,255,0.07);
    transform: translateX(4px);
    color: #ff2626;
}

.global-kebab-dropdown a:hover i {
    transform: scale(1.2) rotate(8deg);
}

.global-kebab-dropdown a.active {
    background: rgba(255,42,42,0.2);
    box-shadow: inset 0 0 10px rgba(255,42,42,0.4);
}

.global-kebab-dropdown.show {
    display: flex;
    animation: fadeDown 0.25s ease forwards;
}

@keyframes fadeDown {
    0%   { opacity: 0; transform: translateY(-6px); }
    100% { opacity: 1; transform: translateY(0); }
}

/* ══════════════════════════════════════
   RIGHT PANEL
══════════════════════════════════════ */
.right-panel{
    position:fixed;right:0;top:0;
    height:100vh;width:330px;
    background:rgba(8,8,22,0.85);
    backdrop-filter:blur(32px) saturate(180%);
    -webkit-backdrop-filter:blur(32px) saturate(180%);
    border-left:1px solid rgba(255,255,255,0.07);
    z-index:1000;
    transition:transform 0.38s cubic-bezier(0.4,0,0.2,1);
    box-shadow:-12px 0 40px rgba(0,0,0,0.4);
    padding:24px 18px;
    overflow-y:auto;
    overflow-x:hidden;
}
.right-panel.collapsed{transform:translateX(100%);}
.right-panel::-webkit-scrollbar{width:4px;}
.right-panel::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.1);border-radius:4px;}

.close-btn{
    background:none;border:none;
    color:rgba(255,255,255,0.4);font-size:22px;
    cursor:pointer;position:absolute;
    top:16px;left:16px;width:32px;height:32px;
    display:flex;align-items:center;justify-content:center;
    border-radius:8px;transition:all 0.2s;
    -webkit-tap-highlight-color:transparent;
}
.close-btn:hover{color:#ff2a2a;background:rgba(255,45,45,0.1);}

/* ══════════════════════════════════════
   PROFILE CARD (right panel)
══════════════════════════════════════ */
.profile-card-inner{
    display:flex;gap:12px;align-items:center;
    padding:14px;border-radius:16px;
    background:rgba(255,255,255,0.04);
    border:1px solid rgba(255,255,255,0.07);
    cursor:pointer;transition:all 0.25s;
    margin-top:40px;
}
.profile-card-inner:hover{background:rgba(255,45,45,0.07);border-color:rgba(255,45,45,0.25);}
.profile-card-inner img{width:48px;height:48px;border-radius:50%;border:2px solid rgba(255,45,45,0.6);object-fit:cover;flex-shrink:0;}

/* ══════════════════════════════════════
   POST BOX
══════════════════════════════════════ */
.post-box{
    padding:14px;border-radius:16px;margin-top:16px;
    display:flex;flex-direction:column;gap:10px;
    background:rgba(255,255,255,0.03);
    border:1px solid rgba(255,255,255,0.07);
}
.post-box textarea{
    width:100%;box-sizing:border-box;
    border:1px solid rgba(255,255,255,0.08);
    border-radius:12px;
    padding:10px 12px;
    background:rgba(255,255,255,0.05);
    color:white;resize:none;
    font-family:var(--font-body);font-size:13px;
    transition:border-color 0.2s;
    outline:none;
}
.post-box textarea:focus{border-color:rgba(255,45,45,0.4);}
.post-box textarea::placeholder{color:rgba(255,255,255,0.2);}
.post-box button{
    align-self:flex-start;
    padding:8px 20px;border:none;border-radius:10px;
    background:rgba(255,45,45,0.85);
    color:white;font-weight:600;font-size:13px;
    cursor:pointer;transition:all 0.2s;font-family:var(--font-body);
}
.post-box button:hover{background:#ff1a1a;box-shadow:0 4px 16px rgba(255,45,45,0.35);}
.post-box button:active{transform:scale(0.96);}

/* ══════════════════════════════════════
   YOUR POSTS
══════════════════════════════════════ */
.your-posts-vertical{
    display:flex;flex-direction:column;gap:10px;
    max-height:280px;overflow-y:auto;padding:4px 2px;
}
.your-posts-vertical::-webkit-scrollbar{width:4px;}
.your-posts-vertical::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.12);border-radius:4px;}
.your-post-card{
    padding:11px 13px;border-radius:14px;
    background:rgba(255,255,255,0.04);
    border:1px solid rgba(255,255,255,0.07);
    transition:0.25s;word-break:break-word;
}
.your-post-card:hover{border-color:rgba(255,45,45,0.25);transform:translateX(2px);}

/* ══════════════════════════════════════
   INDEX KEBAB (per card)
══════════════════════════════════════ */
.index-kebab-wrap{position:relative;}
.index-kebab-btn{background:transparent!important;border:none!important;box-shadow:none!important;padding:6px!important;min-width:unset!important;cursor:pointer;display:flex;align-items:center;justify-content:center;border-radius:8px;transition:background 0.2s;}
.index-kebab-btn:hover{background:rgba(255,255,255,0.08)!important;}
.index-kebab-btn i{font-size:1.2rem!important;color:rgba(255,255,255,0.5)!important;transition:color 0.2s;}
.index-kebab-btn:hover i{color: #f24455!important;}

/* ══════════════════════════════════════
   AD ROW
══════════════════════════════════════ */
.ad-row-wrap{width:100%;overflow:hidden;margin:6px 0 14px;}
.ad-row-scroll{display:flex;flex-wrap:nowrap;overflow-x:auto;gap:12px;padding:6px 28px;scrollbar-width:none;-ms-overflow-style:none;-webkit-overflow-scrolling:touch;scroll-snap-type:x mandatory;-webkit-mask-image:linear-gradient(to right,transparent 0%,black 10%,black 90%,transparent 100%);mask-image:linear-gradient(to right,transparent 0%,black 10%,black 90%,transparent 100%);}
.ad-row-scroll::-webkit-scrollbar{display:none;}
.ad-card{flex:0 0 auto;width:clamp(210px,70vw,260px);background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.07);border-radius:16px;padding:12px;scroll-snap-align:center;position:relative;overflow:hidden;transition:border-color 0.2s;}
.ad-card:hover{border-color:rgba(255,45,45,0.2);}
.ad-badge{position:absolute;top:10px;right:10px;background:rgba(0,0,0,0.6);backdrop-filter:blur(5px);color:rgba(255,255,255,0.4);font-size:8px;font-weight:800;padding:2px 6px;border-radius:4px;letter-spacing:1px;border:1px solid rgba(255,255,255,0.1);pointer-events:none;text-transform:uppercase;}
.ad-card-header{display:flex;align-items:center;gap:8px;margin-bottom:10px;}
.ad-brand-logo{width:26px;height:26px;border-radius:50%;border:1.5px solid rgba(255,45,45,0.5);object-fit:cover;flex-shrink:0;}
.ad-brand-name{font-size:12px;font-weight:600;color:white;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.ad-brand-type{font-size:10px;color:rgba(255,255,255,0.35);}
.ad-media{width:100%;height:clamp(110px,20vw,150px);border-radius:10px;overflow:hidden;border:1px solid rgba(255,45,45,0.3);margin-bottom:10px;}
.ad-media img,.ad-media video{width:100%;height:100%;object-fit:cover;display:block;}
.ad-caption{font-size:11px;color:rgba(255,255,255,0.65);margin-bottom:10px;line-height:1.45;}
.ad-cta{display:flex;align-items:center;justify-content:center;gap:6px;width:100%;padding:8px 0;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);border-radius:10px;color:white;font-weight:600;font-size:11px;text-decoration:none;transition:background 0.2s;}
.ad-cta:hover{background:rgba(255,255,255,0.1);}

/* ══════════════════════════════════════
   SUGGESTED USERS
══════════════════════════════════════ */
.sugg-wrap{width:100%;overflow:hidden;margin:6px 0 14px;}
.sugg-label{font-size:10px;font-weight:700;letter-spacing:1.2px;color:#f24455;text-transform:uppercase;margin:0 0 10px 4px;}
.sugg-scroll{display:flex;flex-wrap:nowrap;overflow-x:auto;gap:12px;padding:4px 28px 8px;scrollbar-width:none;-ms-overflow-style:none;-webkit-overflow-scrolling:touch;scroll-snap-type:x mandatory;-webkit-mask-image:linear-gradient(to right,transparent 0%,black 10%,black 90%,transparent 100%);mask-image:linear-gradient(to right,transparent 0%,black 10%,black 90%,transparent 100%);}
.sugg-scroll::-webkit-scrollbar{display:none;}
.sugg-user{flex:0 0 auto;width:72px;display:flex;flex-direction:column;align-items:center;gap:5px;scroll-snap-align:center;text-decoration:none;color:white;}
.sugg-img-wrap{position:relative;width:54px;height:54px;flex-shrink:0;}
.sugg-img-wrap img{width:54px;height:54px;border-radius:50%;border:2px solid rgba(255,45,45,0.6);object-fit:cover;display:block;transition:transform 0.2s;}
.sugg-user:hover .sugg-img-wrap img{transform:scale(1.06);}
.sugg-new-dot{position:absolute;top:0;right:0;width:16px;height:16px;background:#000;border-radius:50%;border:2px solid var(--ink);display:flex;align-items:center;justify-content:center;}
.sugg-new-dot i{font-size:8px;color:#FF2D2D;}
.sugg-name{font-size:10px;font-weight:500;text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;width:100%;color:rgba(255,255,255,0.8);}
.sugg-track-btn{font-size:10px;font-weight:600;padding:3px 0;width:100%;border-radius:8px;border:1px solid rgba(255,45,45,0.4);background:transparent;color:rgba(255,255,255,0.6);cursor:pointer;transition:all 0.2s;font-family:var(--font-body);text-align:center;}
.sugg-track-btn:hover,.sugg-track-btn.tracking{background:rgba(255,45,45,0.12);border-color:#FF2D2D;color:#FF2D2D;}

/* ══════════════════════════════════════
   PEURA NEW TAG
══════════════════════════════════════ */
.peura-new-tag-mini{background:rgba(255,45,45,0.15);border:1px solid rgba(255,45,45,0.3);color:#FF5566;font-size:8px;font-weight:800;padding:1px 5px;border-radius:3px;text-transform:uppercase;letter-spacing:0.8px;vertical-align:middle;}

/* ── SCREEN TIME BREAK OVERLAY ── */
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
/* ══════════════════════════════════════
   ENGAGEMENT MODAL
══════════════════════════════════════ */
#eng-modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.85);z-index:10000;align-items:center;justify-content:center;}
.eng-pill{display:flex;align-items:center;gap:6px;padding:7px 16px;border-radius:999px;border:1px solid rgba(255,255,255,0.12);background:transparent;color:rgba(255,255,255,0.55);font-size:0.75rem;font-weight:600;cursor:pointer;transition:all 0.2s;font-family:var(--font-body);}
.eng-pill i{font-size:0.82rem;}
.active-pill{background:rgba(242,68,85,0.2);border-color:#f24455;color:#f24455;}

/* ══════════════════════════════════════
   TOAST
══════════════════════════════════════ */
#peura-toast{position:fixed;bottom:90px;left:50%;transform:translateX(-50%);background:rgba(14,14,30,0.97);border:1px solid rgba(255,45,45,0.3);color:#fff;padding:8px 20px;border-radius:50px;font-size:12px;z-index:9999999;pointer-events:none;box-shadow:0 8px 24px rgba(0,0,0,0.6);transition:opacity 0.3s;white-space:nowrap;}

.nav-profile-pic {
    display: none;
}
/* ══════════════════════════════════════
   MOBILE ≤ 768px
══════════════════════════════════════ */
@media (max-width: 768px) {
    body { flex-direction: column; padding-bottom: 0; }

    /* ── SIDEBAR hidden on mobile ── */
    .sidebar {
        display: none !important;
    }

    .sidebar-logo { display: none !important; }

    .sidebar button { display: none !important; }

    /* hide nav profile elements too */
    .nav-profile-icon { display: none !important; }
    .nav-profile-pic { display: none !important; }
    .nav-profile-btn { display: none !important; }

    #msgBtn{margin-top:0;animation:none;}

    .main{margin-left:0;margin-right:0;padding:12px 12px 80px;gap:12px;width:100%;max-width:100%;}

    .search-box{width:100%;max-width:100%;height:auto;min-height:50px;padding:6px 6px 6px 14px;margin-bottom:6px;border-radius:14px;gap:6px;position:sticky;margin-top:50px;z-index:200;}
    .search-box input{font-size:14px;}
    .search-box button{width:38px;height:38px;border-radius:10px;font-size:15px;}

    .post-card{width:100%;max-width:100%;padding:13px;border-radius:16px;}
    .post-header{gap:8px;width:100%;min-width:0;}
    .post-header>div:first-child{display:flex;align-items:center;gap:8px;min-width:0;}
    .post-actions{flex-wrap:wrap;gap:8px;}
    .post-content{word-break:break-word;}

    .tag-pill{font-size:11px;padding:6px 12px;}
    .tag-scroll-btn.mobile-hidden{display:none!important;}

    .top-controls-row{top:10px;right:10px;gap:6px;}
    .panel-toggle-btn{height:38px;padding:0 12px;font-size:12px;border-radius:12px;}
    .panel-toggle-btn span.btn-label{display:none;}
    .global-kebab-btn{width:38px;height:38px;border-radius:12px;font-size:16px;}
    .global-kebab-dropdown{min-width:170px;right:0;}
    .global-kebab-dropdown a{padding:10px 14px;font-size:12px;}

    .right-panel{position:fixed;bottom:60px;top:auto;width:calc(100% - 24px);left:50%;transform:translateX(-50%);height:clamp(320px,65vh,520px);border-left:none;border-top:1px solid rgba(255,255,255,0.08);border-radius:22px 22px 0 0;box-shadow:0 -12px 40px rgba(0,0,0,0.5);padding:20px 16px;overflow-y:auto;z-index:500;backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px);}
    .right-panel::before{content:"";display:block;width:36px;height:4px;background:rgba(255,255,255,0.18);border-radius:999px;margin:0 auto 14px;}
    .right-panel.collapsed{transform:translateX(-50%) translateY(110%);}

    .follow-btn{min-width:70px;padding:5px 12px;font-size:11px;left:0;}

    #cm-arrows,#cm-hint{display:none!important;}
    #captionModal{padding:10px!important;align-items:center!important;}
    #cm-card{border-radius:20px!important;max-height:88vh!important;}

    .skeleton-card{width:100%;}

    button[onclick="location.href='upload.php'"]{display:none!important;}
}

@media(max-width:380px){
    .sidebar button{font-size:15px;}
    .post-card{padding:10px;border-radius:12px;}
    .tag-pill{font-size:11px;padding:5px 10px;}
    .right-panel{height:clamp(300px,60vh,460px);border-radius:18px 18px 0 0;}
}

@media(min-width:769px){
    .tag-scroll-btn.mobile-hidden{display:flex!important;}
}

@media (max-width: 768px) {
    #msgBtn {
        display: none !important;
    }
}
</style>
</head>
<body>

<!-- PULL-TO-REFRESH INDICATOR -->


<!-- ══ TOP CONTROLS (Panel toggle + Kebab) ══ -->
<div class="top-controls-row">
    <!-- KEBAB FILTER -->
    <div class="global-kebab-wrap">
        <button class="global-kebab-btn" id="globalKebabBtn" title="Filter feed">
            <i class="ri-menu-3-line"></i>
        </button>
        <div class="global-kebab-dropdown" id="globalKebabMenu">
            <a href="?filter=trending"><i class="ri-fire-line"></i> Trending</a>
            <a href="?filter=most_liked"><i class="ri-heart-3-line"></i> Most Liked</a>
            <a href="?filter=most_reposted"><i class="ri-repeat-line"></i> Most Reposted</a>
            <a href="?filter=most_favorited"><i class="ri-bookmark-line"></i> Most Favorited</a>
            <a href="?filter=most_viewed"><i class="ri-eye-line"></i> Most Viewed</a>
            <a href="?filter=newest"><i class="ri-time-line"></i> Newest</a>
            <a href="?filter=following"><i class="ri-user-follow-line"></i> Tracking</a>
        </div>
    </div>

    <!-- PANEL TOGGLE -->
    <button class="panel-toggle-btn" id="menuToggle">
        <i class="ri-layout-right-2-line"></i>
        <span class="btn-label">Panel</span>
    </button>
</div>

<!-- LEFT SIDEBAR -->
<div class="sidebar glass">
    <div class="sidebar-logo" onclick="location.href='profile.php'">
        <img src="Dezzme.jpg">
    </div>
    <button onclick="location.href='home.php'" title="Home"><i class="ri-home-5-line"></i></button>
    <button onclick="location.href='text_feed.php'" title="Feed"><i class="ri-send-plane-line"></i></button>
    <button onclick="location.href='search.php'" title="Search"><i class="ri-search-line"></i></button>
    <button onclick="location.href='upload.php'" title="Upload"><i class="ri-add-circle-line" style="font-size:30px; color:white;"></i></button>
    <button onclick="location.href='community.php'" title="Community"><i class="ri-team-line"></i></button>
    <button onclick="location.href='profile.php'" title="Profile" class="nav-profile-btn">
        <i class="ri-user-3-line nav-profile-icon"></i>
        <img class="nav-profile-pic"
             src="<?php echo htmlspecialchars($nav_pic); ?>"
             onerror="this.src='uploads/mee.jpeg'"
             alt="Profile">
    </button>
</div>
<?php include 'nav.php'; ?>

<!-- MAIN -->
<div class="main">

    <!-- SEARCH BOX -->
    <div class="search-box" style="position:relative;">
        <i class="ri-search-line search-box-icon"></i>
        <input id="searchInput" type="text" placeholder="Search users or posts..." value="<?php echo htmlspecialchars($search_param);?>">
        <button id="searchBtn" type="button"><i class="ri-arrow-right-line"></i></button>
        <div id="searchResults" class="search-results" style="display:none;"></div>
    </div>

    <!-- TAG BAR -->
    <div class="tag-bar-wrap">
        <button class="tag-scroll-btn mobile-hidden" id="tagLeft" aria-label="Scroll tags left">
            <i class="ri-arrow-left-s-line"></i>
        </button>
        <div class="tag-scrollbar-wrapper">
            <div class="tag-scrollbar" id="tagScrollbar">
                <?php foreach($sorted_tags as $t):
                    $isActive = ($active_tag === $t || ($t==='All' && $active_tag==='all'));
                    $lowerT = strtolower($t);
                    $isAffinity = in_array($lowerT, array_map('strtolower', $user_top_tags));
                    $href = $t==='All' ? 'text_feed.php?tag=all' : 'text_feed.php?tag='.urlencode($t);
                    $classes = 'tag-pill'.($isActive?' active':'').($isAffinity&&!$isActive?' user-affinity':'');
                ?>
                <a href="<?php echo $href;?>" class="<?php echo $classes;?>"><?php echo $t==='All'?'All':'#'.$t;?></a>
                <?php endforeach;?>
            </div>
        </div>
        <button class="tag-scroll-btn mobile-hidden" id="tagRight" aria-label="Scroll tags right">
            <i class="ri-arrow-right-s-line"></i>
        </button>
    </div>

    <!-- SKELETON LOADER (shown while JS hydrates) -->
    <div class="skeleton-feed" id="skeletonFeed">
        <?php for($s=0;$s<4;$s++): ?>
        <div class="skeleton-card">
            <div class="skel-row">
                <div class="skel skel-avatar"></div>
                <div style="flex:1;display:flex;flex-direction:column;gap:6px;">
                    <div class="skel skel-line" style="width:40%;"></div>
                    <div class="skel skel-line" style="width:25%;"></div>
                </div>
            </div>
            <div class="skel skel-text-block"></div>
            <div class="skel-row">
                <div class="skel skel-line" style="width:20%;height:10px;"></div>
                <div class="skel skel-line" style="width:20%;height:10px;"></div>
                <div class="skel skel-line" style="width:20%;height:10px;"></div>
            </div>
        </div>
        <?php endfor;?>
    </div>

<?php
// ── Build private profile + approved maps ONCE before loop ──
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
?>

<!-- FEED -->
<div class="feed" id="feed" style="display:none;">

<?php
if(!function_exists('linkifyCaption')){
    function linkifyCaption($caption, $conn) {
        $escaped = htmlspecialchars($caption);
        return preg_replace_callback('/@([a-zA-Z0-9_]+)/', function($matches) use ($conn) {
            $username = $matches[1];
            $safe = $conn->real_escape_string($username);
            $res = $conn->query("SELECT id FROM users WHERE username='$safe' LIMIT 1");
            if($res && $res->num_rows > 0){
                $uid = $res->fetch_assoc()['id'];
                return '<a href="profile.php?username='.$username.'" onclick="event.stopPropagation()" style="color:#f24455;font-weight:600;text-decoration:none;" onmouseover="this.style.textDecoration=\'underline\'" onmouseout="this.style.textDecoration=\'none\'">@'.$username.'</a>';
            }
            return '@'.$username;
        }, $escaped);
    }
}
$counter = 0;
$shown_users = [];
?>

<?php foreach($posts as $p): ?>
<?php
// ── PRIVATE ACCOUNT GATE ──
$_p_uploader = (int)$p['uploaded_by'];
if($_p_uploader != (int)$current_user && !empty($_private_profile_map[$_p_uploader]) && empty($_approved_map[$_p_uploader])) continue;
$counter++;

// ── REAL SHARE COUNT — use it if the main query already selected it
//    (e.g. as shares_count), otherwise fall back to a direct count
//    against the shares table so the modal and post-actions button
//    always show the true number. ──
if(isset($p['shares_count'])){
    $share_count_p = (int)$p['shares_count'];
} else {
    $post_id_for_share = (int)$p['id'];
    $share_count_p = (int)($conn->query("SELECT COUNT(*) as c FROM shares WHERE meme_id=$post_id_for_share")->fetch_assoc()['c'] ?? 0);
}
?>

<script>
if(typeof peuraFeed === 'undefined') var peuraFeed = [];
if(typeof window.kebabData === 'undefined') window.kebabData = {};
peuraFeed.push({
    id: <?php echo (int)$p['id'];?>,
    file: '<?php echo addslashes(!empty($p['file_name'])?'uploads/'.$p['file_name']:'');?>',
    type: '<?php echo addslashes($p['type']);?>',
    pic: '<?php echo addslashes(!empty($p['profile_pic'])?$p['profile_pic']:'uploads/me.jpeg');?>',
    userId: '<?php echo (int)$p['uploaded_by'];?>',
    username: '<?php echo addslashes($p['uploaded_by']==$current_user?'You':$p['username']);?>',
    nickname: <?php echo json_encode($p['nickname'] ?? ''); ?>,
    caption: '<?php echo addslashes(htmlspecialchars($p['caption']));?>',
    time: '<?php echo date('M d, Y H:i',strtotime($p['created_at']));?>',
    shareCount: <?php echo (int)$share_count_p; ?>,
    isPartnership: <?php echo !empty($p['is_partnership']) ? 'true' : 'false'; ?>,
    partnerBrand: <?php echo json_encode($p['partner_brand'] ?? ''); ?>
});
window.kebabData[<?php echo (int)$p['id'];?>] = {
    id:         <?php echo (int)$p['id'];?>,
    uploadedBy: <?php echo (int)$p['uploaded_by'];?>,
    caption:    '<?php echo addslashes(htmlspecialchars($p['caption']));?>'
};
</script>

<?php
$created = strtotime($p['created_at']);
$diff = time()-$created;
$is_new_badge = ($diff <= 172800);

/* ── SOCIAL PROOF: reposts prioritized over likes.
   Pull ALL matching rows (no SQL LIMIT), sort reposts first,
   then cap at 4 in PHP — so reposts always claim the strip's
   slots before likes get to pad any leftover space. ── */
$social_proof = [];
if($current_user){
    $post_id = $p['id'];
    $sp_result = $conn->query("SELECT u.id,u.username,u.profile_pic,'like' AS action_type FROM likes l JOIN users u ON u.id=l.user_id JOIN followers f ON f.following_id=u.id WHERE l.meme_id=$post_id AND f.follower_id=$current_user AND u.id!=$current_user UNION SELECT u.id,u.username,u.profile_pic,'repost' AS action_type FROM reposts r JOIN users u ON u.id=r.user_id JOIN followers f ON f.following_id=u.id WHERE r.meme_id=$post_id AND f.follower_id=$current_user AND u.id!=$current_user");

    $_sp_raw = [];
    while($sp = $sp_result->fetch_assoc()) $_sp_raw[] = $sp;

    usort($_sp_raw, function($a, $b){
        $aRank = $a['action_type'] === 'repost' ? 0 : 1;
        $bRank = $b['action_type'] === 'repost' ? 0 : 1;
        return $aRank <=> $bRank;
    });

    $social_proof = array_slice($_sp_raw, 0, 4);
}

$post_id = $p['id'];
$like_count_p = $p['likes_count'];
$repost_count_p = $p['reposts_count'];
$eng_users_p = [];
if($like_count_p > 0 || $repost_count_p > 0){
    $eng_q = $conn->query("SELECT u.id,u.username,u.profile_pic FROM likes l JOIN users u ON u.id=l.user_id WHERE l.meme_id=$post_id LIMIT 3");
    while($r=$eng_q->fetch_assoc()) $eng_users_p[]=$r;
}
?>
<script>
(function(){
    var last = peuraFeed[peuraFeed.length - 1];
    last.socialProof = <?php echo json_encode(array_map(function($sp){
        return [
            'id'          => (int)$sp['id'],
            'username'    => $sp['username'],
            'profile_pic' => $sp['profile_pic'],
            'action_type' => $sp['action_type'],
        ];
    }, $social_proof)); ?>;
})();
</script>

<div class="post-card glass"
     data-id="<?php echo $p['id'];?>"
     data-uploader="<?php echo $p['uploaded_by'];?>"
     onclick="openCaptionModal(<?php echo $p['id'];?>,'<?php echo addslashes(htmlspecialchars($p['caption']));?>','<?php echo addslashes($p['uploaded_by']==$current_user?'You':$p['username']);?>','<?php echo addslashes(!empty($p['profile_pic'])?$p['profile_pic']:'uploads/me.jpeg');?>','<?php echo date('M d, Y H:i',strtotime($p['created_at']));?>',<?php echo (int)$p['uploaded_by'];?>)">

    <div class="post-header">
        <div style="display:flex;align-items:center;gap:10px;min-width:0;cursor:pointer;" onclick="event.stopPropagation();location.href='profile.php?user_id=<?php echo $p['uploaded_by'];?>'">
            <img src="<?php echo !empty($p['profile_pic'])?$p['profile_pic']:'uploads/me.jpeg';?>" onerror="this.src='uploads/me.jpeg';">
            <div style="min-width:0;">
                <b style="display:flex;align-items:center;gap:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:13px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" style="flex-shrink:0;"><circle cx="12" cy="12" r="12" fill="#FF0000"/><path d="M9 12l2 2 4-4" stroke="white" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <?php echo $p['uploaded_by']==$current_user?'You':$p['username'];?>
                </b>
                <div style="display:flex;align-items:center;gap:5px;margin-top:2px;">
                    <span style="font-size:10px;color:rgba(255,255,255,0.35);"><?php echo date("M d, Y H:i",strtotime($p['created_at']));?></span>
                    <?php if($is_new_badge):?><span class="peura-new-tag-mini">NEW</span><?php endif;?>
                </div>
            </div>
        </div>

        <div style="display:flex;align-items:center;gap:6px;flex-shrink:0;" onclick="event.stopPropagation()">
            <?php if($p['uploaded_by']!=$current_user): $is_following=in_array($p['uploaded_by'],$following_ids);?>
            <button class="follow-btn <?php echo $is_following?'following':'not-following';?>" data-user="<?php echo $p['uploaded_by'];?>">
                <?php echo $is_following?'UnTrack':'Track';?>
            </button>
            <?php endif;?>
            <button class="action-btn index-kebab-btn" data-id="<?php echo $p['id'];?>" onclick="event.stopPropagation();openIndexSheet(<?php echo $p['id'];?>)">
                <i class="ri-menu-line"></i>
            </button>
        </div>
    </div>

    <?php
    $caption=$p['caption'];
    $limit=40;
    $trimmed=mb_strlen($caption)>$limit?mb_substr($caption,0,$limit).'..':$caption;
    ?>
    <div class="post-content"><?php echo nl2br(linkifyCaption($trimmed,$conn));?></div>

    <?php if(!empty($social_proof)):?>
    <div class="social-proof-strip">
        <?php foreach($social_proof as $sp):
            $sp_pic=!empty($sp['profile_pic'])?$sp['profile_pic']:'uploads/me.jpeg';
            $action_icon=$sp['action_type']==='like'?'ri-heart-3-fill':'ri-repeat-line';
            $action_color=$sp['action_type']==='like'?'#f24455':'#7a4aff';
        ?>
        <a href="profile.php?user_id=<?php echo $sp['id'];?>" class="sp-avatar-wrap" onclick="event.stopPropagation()" title="<?php echo $sp['username'].' '.($sp['action_type']==='like'?'liked':'reposted').' this';?>">
            <img src="<?php echo $sp_pic;?>" onerror="this.src='uploads/me.jpeg'" class="sp-avatar">
            <span class="sp-action-badge" style="background:<?php echo $action_color;?>;"><i class="<?php echo $action_icon;?>"></i></span>
        </a>
        <?php endforeach;?>
    </div>
    <?php endif;?>

    <div class="post-actions" onclick="event.stopPropagation()">
        <button class="likeBtn" data-id="<?php echo $p['id'];?>">
            <i class="ri-heart-fill" style="color:<?php echo $p['liked']?'#ff4c4c':'rgb(255, 255, 255)';?>"></i>
            <span><?php echo $p['likes_count'];?></span>
        </button>
        <button class="repostBtn" data-id="<?php echo $p['id'];?>">
            <i class="ri-repeat-line" style="color:<?php echo $p['reposted']?'#ff4c4c':'rgb(255, 255, 255)';?>"></i>
            <span><?php echo $p['reposts_count'];?></span>
        </button>
        <button class="favBtn" data-id="<?php echo $p['id'];?>">
            <i class="ri-bookmark-fill" style="color:<?php echo $p['favorited']?'#ff0000':'rgb(255, 255, 255)';?>"></i>
            <span><?php echo $p['favorites_count'];?></span>
        </button>
        <button class="shareBtn" data-id="<?php echo $p['id'];?>" onclick="event.stopPropagation();openShareModal(<?php echo (int)$p['id'];?>)">
            <i class="ri-share-forward-fill" style="color:rgb(255, 255, 255)"></i>
            <span><?php echo (int)$share_count_p;?></span>
        </button>
    </div>
</div>

<?php if($like_count_p>0||$repost_count_p>0):?>
<div class="eng-row" onclick="openEngModal(<?php echo $post_id;?>,'likes')">
    <?php if(!empty($eng_users_p)):?>
    <div class="eng-avatars">
        <?php foreach($eng_users_p as $eu): $ep=!empty($eu['profile_pic'])?$eu['profile_pic']:'uploads/me.jpeg';?>
        <img src="<?php echo $ep;?>" onerror="this.src='uploads/me.jpeg'" class="eng-avatar">
        <?php endforeach;?>
    </div>
    <?php endif;?>
    <span class="eng-text">
        <?php if(!empty($eng_users_p)):?>
            Liked by <strong><?php echo $eng_users_p[0]['username'];?></strong>
            <?php if($like_count_p>1):?> and <strong><?php echo ($like_count_p-1);?> others</strong><?php endif;?>
            <?php if($repost_count_p>0):?> · <strong><?php echo $repost_count_p;?></strong> reposts<?php endif;?>
        <?php else:?><strong><?php echo $repost_count_p;?></strong> reposts<?php endif;?>
    </span>
</div>
<?php endif;?>

    <?php if($counter%5==0):
        $ads=$conn->query("SELECT * FROM sponsorships WHERE status='approved' ORDER BY RAND() LIMIT 6");
        if($ads&&$ads->num_rows>0):?>
    <div class="ad-row-wrap">
        <div class="ad-row-scroll">
            <?php while($ad=$ads->fetch_assoc()): $ext=strtolower(pathinfo($ad['meme_image'],PATHINFO_EXTENSION));?>
            <div class="ad-card">
                <span class="ad-badge">AD</span>
                <div class="ad-card-header">
                    <img src="uploads/<?php echo htmlspecialchars($ad['image']);?>" onerror="this.src='uploads/me.jpeg'" class="ad-brand-logo">
                    <div style="min-width:0;"><div class="ad-brand-name"><?php echo htmlspecialchars($ad['title']);?></div><div class="ad-brand-type"><?php echo htmlspecialchars($ad['type']);?></div></div>
                </div>
                <div class="ad-media">
                    <?php if(in_array($ext,['mp4','webm','mov'])):?><video src="uploads/<?php echo htmlspecialchars($ad['meme_image']);?>" autoplay muted loop playsinline></video><?php else:?><img src="uploads/<?php echo htmlspecialchars($ad['meme_image']);?>" onerror="this.src='uploads/me.jpeg'"><?php endif;?>
                </div>
                <div class="ad-caption"><?php echo htmlspecialchars($ad['caption']);?></div>
                <a href="<?php echo htmlspecialchars($ad['link']);?>" target="_blank" class="ad-cta" onclick="event.stopPropagation()">Visit Brand <i class="ri-arrow-right-up-line"></i></a>
            </div>
            <?php endwhile;?>
        </div>
    </div>
    <?php endif;endif;?>

    <?php if($counter%2==0):
        $sugg_rows = getSuggestedUsers($conn, $current_user, 10, $shown_users);
        if(!empty($sugg_rows)):
    ?>
    <div class="sugg-wrap">
        <div class="sugg-label">Suggested Dezzmers</div>
        <div class="sugg-scroll">
            <?php foreach($sugg_rows as $su):
                $shown_users[]=(int)$su['id'];
                $su_pic      = !empty($su['profile_pic'])?$su['profile_pic']:'uploads/me.jpeg';
                $su_tracking = (int)$su['is_following'];
                $su_uid      = (int)$su['id'];
                $su_reason   = $su['reason'] ?? '';

                $su_recent  = $conn->query("SELECT id FROM memes WHERE uploaded_by=$su_uid AND created_at>=NOW()-INTERVAL 24 HOUR LIMIT 1");
                $su_has_new = ($su_recent && $su_recent->num_rows > 0);

                // Check if private profile
                $su_priv_chk = $conn->query("SELECT is_fully_private FROM user_settings WHERE user_id=$su_uid LIMIT 1");
                $su_priv_row = $su_priv_chk ? $su_priv_chk->fetch_assoc() : null;
                $su_is_private = !empty($su_priv_row['is_fully_private']);

                // Show button: public = always | private + following = Untrack | private + not following = hide
                $su_show_btn = !$su_is_private || $su_tracking;
            ?>
            <div class="sugg-user">
                <a href="profile.php?user_id=<?php echo $su_uid;?>" onclick="event.stopPropagation()" style="display:contents;color:inherit;text-decoration:none;">
                    <div class="sugg-img-wrap">
                        <img src="<?php echo htmlspecialchars($su_pic);?>" onerror="this.src='uploads/me.jpeg'">
                        <?php if($su_has_new):?><span class="sugg-new-dot"><i class="ri-image-fill"></i></span><?php endif;?>
                    </div>
                    <span class="sugg-name"><?php echo htmlspecialchars($su['username']);?></span>
                    <?php if($su_reason):?><span style="font-size:9px;color:rgba(255,255,255,0.35);display:block;text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($su_reason);?></span><?php endif;?>
                </a>
                <?php if($su_show_btn): ?>
                <button class="sugg-track-btn <?php echo $su_tracking?'tracking':'';?>" data-user="<?php echo $su_uid;?>" onclick="event.stopPropagation();toggleSuggTrack(this)">
                    <?php echo $su_tracking?'Untrack':'Track';?>
                </button>
                <?php endif;?>
            </div>
            <?php endforeach;?>
        </div>
    </div>
    <?php endif;endif;?>

    <?php endforeach;?>
    </div><!-- /feed -->
</div><!-- /main -->


<!-- ═══════════════════════════════════════════════════════
     INDEX BOTTOM SHEET (shared pattern with home.php)
     ═══════════════════════════════════════════════════════ -->
<div id="indexSheetOverlay" class="idx-sheet-overlay" onclick="closeIndexSheet()"></div>
<div id="indexSheetPanel" class="idx-sheet-panel">
    <div class="idx-sheet-handle"></div>
    <div class="idx-sheet-list" id="indexSheetList"></div>
    <button class="idx-sheet-cancel" onclick="closeIndexSheet()">Cancel</button>
</div>

<style>
.idx-sheet-overlay{
    position:fixed;inset:0;background:rgba(0,0,0,0.6);
    backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);
    z-index:2147483647; /* max valid 32-bit z-index */
    opacity:0;pointer-events:none;
    transition:opacity 0.25s ease;
}
.idx-sheet-overlay.show{opacity:1;pointer-events:all;}

.idx-sheet-panel{
    position:fixed;left:0;right:0;bottom:0;
    background:rgba(12,12,28,0.98);
    backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);
    border-top:1px solid rgba(255,255,255,0.08);
    border-radius:20px 20px 0 0;
    z-index:2147483647; /* max valid 32-bit z-index */
    max-height:80vh;
    display:flex;flex-direction:column;
    padding:8px 0 max(10px, env(safe-area-inset-bottom));
    transform:translateY(100%);
    transition:transform 0.3s cubic-bezier(0.32,0.72,0,1);
    box-shadow:0 -10px 40px rgba(0,0,0,0.5);
}
.idx-sheet-panel.show{transform:translateY(0);}

.idx-sheet-handle{
    width:34px;height:3.5px;border-radius:999px;
    background:rgba(255,255,255,0.2);
    margin:0 auto 4px;flex-shrink:0;
}

.idx-sheet-list{overflow-y:auto;flex:1;-webkit-overflow-scrolling:touch;}
.idx-sheet-list::-webkit-scrollbar{width:4px;}
.idx-sheet-list::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.15);border-radius:999px;}

.idx-sheet-item{
    display:flex;align-items:center;gap:11px;
    padding:9px 18px;
    color:#fff;text-decoration:none;
    font-size:13px;font-weight:500;
    font-family:Poppins,sans-serif;
    cursor:pointer;
    -webkit-tap-highlight-color:transparent;
    transition:background 0.15s ease;
    border-bottom:1px solid rgba(255,255,255,0.06);
}
.idx-sheet-item:last-child{border-bottom:none;}
.idx-sheet-item:active{background:rgba(255,255,255,0.06);}
.idx-sheet-item i{font-size:15.5px;color:#f24455;width:18px;text-align:center;flex-shrink:0;}
.idx-sheet-item.danger{color:#ff4c4c;}
.idx-sheet-item.danger i{color:#ff4c4c;}

.idx-sheet-cancel{
    margin:6px 12px 0;
    padding:10px;
    background:rgba(255,255,255,0.06);
    border:none;border-radius:12px;
    color:rgba(255,255,255,0.7);
    font-weight:600;font-size:12.5px;
    font-family:Poppins,sans-serif;
    cursor:pointer;flex-shrink:0;
    -webkit-tap-highlight-color:transparent;
}
.idx-sheet-cancel:active{background:rgba(255,255,255,0.1);}

@media (min-width:791px){
    .idx-sheet-panel{
        left:50%;right:auto;bottom:auto;top:50%;
        width:100%;max-width:320px;
        border-radius:18px;
        transform:translate(-50%,-40%) scale(0.95);
        opacity:0;pointer-events:none;max-height:none;
    }
    .idx-sheet-panel.show{transform:translate(-50%,-50%) scale(1);opacity:1;pointer-events:all;}
    .idx-sheet-handle{display:none;}
}

@media (max-width:790px){
    .idx-sheet-panel{border-radius:18px 18px 0 0;max-height:78vh;padding:7px 0 max(9px, env(safe-area-inset-bottom));}
    .idx-sheet-handle{width:32px;height:3.5px;margin:0 auto 4px;}
    .idx-sheet-item{padding:8px 16px;font-size:12.5px;gap:10px;}
    .idx-sheet-item i{font-size:15px;width:17px;}
    .idx-sheet-cancel{margin:5px 11px 0;padding:9px;font-size:12px;border-radius:11px;}
}

@media (max-width:490px){
    .idx-sheet-panel{border-radius:16px 16px 0 0;max-height:76vh;padding:6px 0 max(8px, env(safe-area-inset-bottom));backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);}
    .idx-sheet-handle{width:30px;height:3px;margin:0 auto 3px;}
    .idx-sheet-item{padding:7px 14px;font-size:12px;gap:9px;}
    .idx-sheet-item i{font-size:14px;width:16px;}
    .idx-sheet-cancel{margin:5px 10px 0;padding:8px;font-size:11.5px;border-radius:10px;}
}

@media (max-width:390px){
    .idx-sheet-panel{border-radius:14px 14px 0 0;max-height:74vh;padding:6px 0 max(7px, env(safe-area-inset-bottom));backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);box-shadow:0 -8px 30px rgba(0,0,0,0.45);}
    .idx-sheet-handle{width:28px;height:3px;margin:0 auto 3px;}
    .idx-sheet-item{padding:6.5px 12px;font-size:11.5px;gap:8px;}
    .idx-sheet-item i{font-size:13.5px;width:15px;}
    .idx-sheet-cancel{margin:4px 9px 0;padding:7px;font-size:11px;border-radius:9px;}
}

@media (max-width:330px){
    .idx-sheet-panel{border-radius:12px 12px 0 0;max-height:72vh;padding:5px 0 max(6px, env(safe-area-inset-bottom));backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);box-shadow:0 -6px 24px rgba(0,0,0,0.4);}
    .idx-sheet-handle{width:24px;height:2.5px;margin:0 auto 3px;}
    .idx-sheet-list::-webkit-scrollbar{width:3px;}
    .idx-sheet-item{padding:5.5px 10px;font-size:10.5px;gap:7px;}
    .idx-sheet-item i{font-size:12.5px;width:13px;}
    .idx-sheet-cancel{margin:4px 8px 0;padding:6px;font-size:10px;border-radius:8px;}
}
</style>

<script>
function _idxSheetHTML(d, isOwner){
    var items = [];
    var safeCaption = (d.caption || '').replace(/\\/g,'\\\\').replace(/'/g,"\\'");

    items.push('<a class="idx-sheet-item" href="remix_upload.php?meme_id='+d.id+'"><i class="ri-scissors-cut-line"></i> Remix</a>');
    items.push('<div class="idx-sheet-item" onclick="copyCaption(\''+safeCaption+'\');closeIndexSheet();"><i class="ri-file-copy-line"></i> Copy Caption</div>');
    items.push('<div class="idx-sheet-item" onclick="showMemeQR('+d.id+');closeIndexSheet();"><i class="ri-qr-code-line"></i> QR Code</div>');
    items.push('<a class="idx-sheet-item" href="account.php?user_id='+d.uploadedBy+'"><i class="ri-user-search-line"></i> About Account</a>');
    items.push('<div class="idx-sheet-item" onclick="shareMeme('+d.id+');closeIndexSheet();"><i class="ri-share-line"></i> Share Post</div>');
    items.push('<div class="idx-sheet-item" onclick="closeIndexSheet();openSaveModal('+d.id+');"><i class="ri-bookmark-line"></i> Add to Collection</div>');
    items.push('<div class="idx-sheet-item" onclick="notInterestedFromSheet('+d.id+')"><i class="ri-eye-off-line"></i> Not Interested</div>');
    items.push('<div class="idx-sheet-item" onclick="openReportModal({contentId:'+d.id+',contentType:\'meme\'});closeIndexSheet();"><i class="ri-flag-line"></i> Report</div>');

    if(!isOwner){
        items.push('<div class="idx-sheet-item danger" onclick="closeIndexSheet();blockUser('+d.uploadedBy+')"><i class="ri-forbid-line"></i> Block User</div>');
    }

    return items.join('');
}

function openIndexSheet(memeId){
    var d = window.kebabData && window.kebabData[memeId];
    if(!d) return;
    var isOwner = String(d.uploadedBy) === String(currentUserId);

    document.getElementById('indexSheetList').innerHTML = _idxSheetHTML(d, isOwner);
    document.getElementById('indexSheetOverlay').classList.add('show');
    document.getElementById('indexSheetPanel').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeIndexSheet(){
    document.getElementById('indexSheetOverlay').classList.remove('show');
    document.getElementById('indexSheetPanel').classList.remove('show');
    document.body.style.overflow = '';
}

function notInterestedFromSheet(memeId){
    var btn = document.querySelector('.index-kebab-btn[data-id="'+memeId+'"]');
    if(btn) notInterested(memeId, btn);
    closeIndexSheet();
}

document.addEventListener('keydown', function(e){
    if(e.key === 'Escape'){
        var panel = document.getElementById('indexSheetPanel');
        if(panel && panel.classList.contains('show')) closeIndexSheet();
    }
});
</script>


<!-- ══ SCREEN 0: Category picker ══ -->
<div id="dzReportOverlay">

    <div class="dzrpt-topbar">
        <div class="dzrpt-topbar-center">
            <div class="dzrpt-topbar-title">Report</div>
            <div class="dzrpt-topbar-sub">Reports are private and anonymous</div>
        </div>
        <button class="dzrpt-close-btn" onclick="closeReportModal()" aria-label="Close">
            <i class="ri-close-line"></i>
        </button>
    </div>

    <div class="dzrpt-section-label">Select what you want to report</div>

    <div class="dzrpt-cat-row" onclick="dzRptSelectCat('user')">
        <div class="dzrpt-cat-icon"><i class="ri-user-forbid-line"></i></div>
        <div class="dzrpt-cat-info">
            <div class="dzrpt-cat-label">Reporting a User</div>
            <div class="dzrpt-cat-sub">Fake accounts, spam, harassment &amp; more</div>
        </div>
        <i class="ri-arrow-right-s-line dzrpt-cat-chevron"></i>
    </div>

    <div class="dzrpt-cat-row" onclick="dzRptSelectCat('content')">
        <div class="dzrpt-cat-icon"><i class="ri-image-2-line"></i></div>
        <div class="dzrpt-cat-info">
            <div class="dzrpt-cat-label">Reporting Content</div>
            <div class="dzrpt-cat-sub">Posts that break the rules</div>
        </div>
        <i class="ri-arrow-right-s-line dzrpt-cat-chevron"></i>
    </div>

</div>


<!-- ══ SCREEN 1: Reason picker ══ -->
<div id="dzRptStep1Screen">

    <div class="dzrpt-topbar">
        <button class="dzrpt-back-btn" onclick="dzRptBack()" aria-label="Back">
            <i class="ri-arrow-left-s-line"></i>
        </button>
        <div class="dzrpt-topbar-center">
            <div class="dzrpt-topbar-title" id="dzRptTitle">Select a reason</div>
            <div class="dzrpt-topbar-sub" id="dzRptSubtitle">Your report is anonymous</div>
        </div>
        <div class="dzrpt-topbar-spacer"></div>
    </div>

    <div class="dzrpt-userid-wrap" id="dzRptUserIdWrap" style="display:none;">
        <div class="dzrpt-userid-label">
            <i class="ri-fingerprint-line"></i>
            User ID
            <span style="font-weight:400;text-transform:none;letter-spacing:0;color:rgba(255,255,255,.22);font-size:9px;">
                &nbsp;— optional
            </span>
        </div>
        <input class="dzrpt-userid-input" type="text" id="dzRptUserIdInput"
               placeholder="e.g. DZ-10E34AFAD or user #123">
        <div class="dzrpt-userid-hint">Find this on the user's profile page</div>
    </div>

    <div class="dzrpt-section-label" id="dzRptReasonLabel">Select a reason</div>
    <div id="dzRptReasonList"></div>

    <div class="dzrpt-other-wrap" id="dzRptOtherWrap">
        <textarea class="dzrpt-other-textarea" id="dzRptOtherInput"
                  placeholder="Please describe the issue in detail…"
                  maxlength="500"
                  oninput="dzRptCharCount(this)"
                  onfocus="this.style.borderColor='rgba(255,45,45,0.45)'"
                  onblur="this.style.borderColor='rgba(255,255,255,0.09)'"></textarea>
        <div class="dzrpt-char-count" id="dzRptCharLabel">0 / 500</div>
    </div>

    <div class="dzrpt-footer">
        <button class="dzrpt-submit-btn" id="dzRptSubmitBtn" onclick="dzRptSubmit()">
            Submit Report
        </button>
        <button class="dzrpt-cancel-btn" onclick="closeReportModal()">Cancel</button>
    </div>

</div>


<!-- ══ SCREEN 2: Success ══ -->
<div id="dzRptStep2Screen">

    <div class="dzrpt-topbar">
        <div class="dzrpt-topbar-spacer"></div>
        <div class="dzrpt-topbar-center">
            <div class="dzrpt-topbar-title">Report Submitted</div>
        </div>
        <div class="dzrpt-topbar-spacer"></div>
    </div>

    <div class="dzrpt-success-body">
        <div class="dzrpt-success-icon"><i class="ri-shield-check-line"></i></div>
        <div class="dzrpt-success-title">Report Submitted</div>
        <div class="dzrpt-success-desc">
            Thank you. Your report has been submitted for review.
            The reported account will not know who reported them.
        </div>
        <button class="dzrpt-done-btn" onclick="closeReportModal()">Done</button>
    </div>

</div>


<!-- Toast -->
<div id="dzRptToastEl" class="dzrpt-toast"></div>


<style>
#dzReportOverlay {
    display: none;
    position: fixed; inset: 0;
    z-index: 999999999999;
    font-family: 'Poppins', sans-serif;
    background: linear-gradient(-45deg, #010218, #070924, #070924);
    overflow-y: scroll;
    overscroll-behavior: contain;
    opacity: 0;
    transform: translateY(18px);
    transition: opacity .25s ease, transform .28s cubic-bezier(.22,1,.36,1);
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
    scrollbar-color: rgba(255,255,255,.12) transparent;
}
#dzReportOverlay.dzrpt-open {
    opacity: 1;
    transform: translateY(0);
}

#dzRptStep1Screen {
    display: none;
    position: fixed; inset: 0;
    z-index: 99999999;
    font-family: 'Poppins', sans-serif;
    background: linear-gradient(-45deg, #010218, #070924, #070924);
    overflow-y: scroll;
    overscroll-behavior: contain;
    opacity: 0;
    transform: translateX(28px);
    transition: opacity .22s ease, transform .26s cubic-bezier(.22,1,.36,1);
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
    scrollbar-color: rgba(255,255,255,.12) transparent;
}
#dzRptStep1Screen.dzrpt-open {
    opacity: 1;
    transform: translateX(0);
}

#dzRptStep2Screen {
    display: none;
    position: fixed; inset: 0;
    z-index: 99999999;
    font-family: 'Poppins', sans-serif;
    background: linear-gradient(-45deg, #010218, #070924, #070924);
    overflow-y: scroll;
    overscroll-behavior: contain;
    opacity: 0;
    transform: translateX(28px);
    transition: opacity .22s ease, transform .26s cubic-bezier(.22,1,.36,1);
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
    scrollbar-color: rgba(255,255,255,.12) transparent;
}
#dzRptStep2Screen.dzrpt-open {
    opacity: 1;
    transform: translateX(0);
}

#dzReportOverlay::-webkit-scrollbar,
#dzRptStep1Screen::-webkit-scrollbar,
#dzRptStep2Screen::-webkit-scrollbar {
    width: 4px;
}
#dzReportOverlay::-webkit-scrollbar-track,
#dzRptStep1Screen::-webkit-scrollbar-track,
#dzRptStep2Screen::-webkit-scrollbar-track {
    background: transparent;
}
#dzReportOverlay::-webkit-scrollbar-thumb,
#dzRptStep1Screen::-webkit-scrollbar-thumb,
#dzRptStep2Screen::-webkit-scrollbar-thumb {
    background: rgba(255,255,255,.14);
    border-radius: 999px;
}
#dzReportOverlay::-webkit-scrollbar-thumb:hover,
#dzRptStep1Screen::-webkit-scrollbar-thumb:hover,
#dzRptStep2Screen::-webkit-scrollbar-thumb:hover {
    background: rgba(255,255,255,.22);
}

.dzrpt-topbar {
    display: flex; align-items: center; justify-content: space-between;
    padding: 15px 22px;
    border-bottom: 1px solid rgba(255,255,255,.06);
    position: sticky; top: 0;
    background: linear-gradient(-45deg, #010218, #070924);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    z-index: 3;
}
.dzrpt-topbar-title {
    font-size: 15px; font-weight: 700; color: #fff; letter-spacing: -.2px;
}
.dzrpt-topbar-sub {
    font-size: 10.5px; color: rgba(255,255,255,.32); margin-top: 1px;
}
.dzrpt-topbar-center { display: flex; flex-direction: column; }

.dzrpt-back-btn {
    display: flex; align-items: center;
    background: none; border: none;
    color: rgba(255,255,255,.5);
    font-size: 13px; font-family: 'Poppins', sans-serif;
    cursor: pointer; padding: 4px 2px;
    transition: color .18s;
    line-height: 1;
}
.dzrpt-back-btn i {
    font-size: 30px;
}
.dzrpt-back-btn:hover { color: #fff; }

.dzrpt-close-btn {
    width: 30px; height: 30px; border-radius: 50%;
    background: rgba(255,255,255,.06); border: none;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; color: rgba(255,255,255,.45); font-size: 17px;
    transition: background .18s, color .18s;
}
.dzrpt-close-btn:hover { background: rgba(255,45,45,.18); color: #ff6060; }

.dzrpt-topbar-spacer {
    width: 30px; height: 30px; flex-shrink: 0;
}

.dzrpt-section-label {
    font-size: 9.5px; font-weight: 700; letter-spacing: 1.5px;
    text-transform: uppercase; color: rgba(255,255,255,.24);
    padding: 20px 22px 8px;
}

.dzrpt-cat-row {
    display: flex; align-items: center; gap: 15px;
    padding: 14px 22px;
    border-bottom: 1px solid rgba(255,255,255,.048);
    cursor: pointer; transition: background .18s;
    -webkit-tap-highlight-color: transparent;
}
.dzrpt-cat-row:hover  { background: rgba(255,255,255,.035); }
.dzrpt-cat-row:active { background: rgba(255,255,255,.065); }
.dzrpt-cat-icon {
    width: 42px; height: 42px; flex-shrink: 0; border-radius: 13px;
    background: rgba(255,45,45,.13);
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; color: #FF2D2D;
}
.dzrpt-cat-info { flex: 1; min-width: 0; }
.dzrpt-cat-label { font-size: 13.5px; font-weight: 600; color: #fff; }
.dzrpt-cat-sub { font-size: 11px; color: rgba(255,255,255,.35); margin-top: 3px; line-height: 1.4; }
.dzrpt-cat-chevron { font-size: 18px; color: rgba(255,255,255,.18); transition: transform .18s, color .18s; }
.dzrpt-cat-row:hover .dzrpt-cat-chevron { transform: translateX(3px); color: rgba(255,255,255,.35); }

.dzrpt-userid-wrap {
    padding: 18px 22px 6px;
}
.dzrpt-userid-label {
    font-size: 9.5px; font-weight: 700; letter-spacing: 1.3px;
    text-transform: uppercase; color: rgba(255,255,255,.28);
    margin-bottom: 9px; display: flex; align-items: center; gap: 6px;
}
.dzrpt-userid-label i { font-size: 13px; color: #FF2D2D; }
.dzrpt-userid-input {
    width: 100%; padding: 13px 15px;
    border-radius: 13px;
    background: rgba(255,255,255,.045);
    border: 1px solid rgba(255,255,255,.09);
    color: #fff; font-family: 'Poppins', sans-serif;
    font-size: 13.5px; font-weight: 500; outline: none;
    transition: border-color .2s, background .2s, box-shadow .2s;
    box-sizing: border-box;
}
.dzrpt-userid-input::placeholder { color: rgba(255,255,255,.22); }
.dzrpt-userid-input:focus {
    border-color: rgba(255,45,45,.4);
    background: rgba(255,45,45,.04);
    box-shadow: 0 0 0 3px rgba(255,45,45,.09);
}
.dzrpt-userid-hint {
    font-size: 10.5px; color: rgba(255,255,255,.25);
    margin-top: 7px; line-height: 1.5;
}

.dzrpt-reason-row {
    display: flex; align-items: center; gap: 14px;
    padding: 14px 22px;
    border-bottom: 1px solid rgba(255,255,255,.045);
    cursor: pointer; transition: background .18s;
    -webkit-tap-highlight-color: transparent;
}
.dzrpt-reason-row:hover  { background: rgba(255,255,255,.035); }
.dzrpt-reason-row:active { background: rgba(255,255,255,.065); }
.dzrpt-reason-row.selected { background: rgba(255,45,45,.09); border-left: 3px solid #FF2D2D; }
.dzrpt-reason-icon { font-size: 19px; color: #FF2D2D; flex-shrink: 0; width: 24px; text-align: center; }
.dzrpt-reason-text { flex: 1; font-size: 13.5px; font-weight: 500; color: rgba(255,255,255,.85); }
.dzrpt-reason-row.selected .dzrpt-reason-text { color: #fff; font-weight: 600; }
.dzrpt-check {
    width: 23px; height: 23px; border-radius: 50%;
    border: 1.5px solid rgba(255,255,255,.2);
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; color: transparent; flex-shrink: 0; transition: all .2s;
}
.dzrpt-reason-row.selected .dzrpt-check { background: #FF2D2D; border-color: transparent; color: #fff; }

.dzrpt-other-wrap { padding: 10px 22px 14px; display: none; }
.dzrpt-other-textarea {
    width: 100%; min-height: 100px;
    background: rgba(255,255,255,.045);
    border: 1px solid rgba(255,255,255,.09);
    border-radius: 13px; padding: 13px 15px;
    color: #fff; font-size: 13px;
    font-family: 'Poppins', sans-serif;
    resize: vertical; outline: none; box-sizing: border-box;
    transition: border-color .2s, background .2s;
}
.dzrpt-other-textarea::placeholder { color: rgba(255,255,255,.22); }
.dzrpt-other-textarea:focus {
    border-color: rgba(255,45,45,.4);
    background: rgba(255,45,45,.04);
}
.dzrpt-char-count { text-align: right; font-size: 10px; color: rgba(255,255,255,.25); margin-top: 5px; }

.dzrpt-footer { padding: 16px 22px 40px; }
.dzrpt-submit-btn {
    width: 100%; padding: 14px; border-radius: 13px;
    background: #FF2D2D; border: none; color: #fff;
    font-size: 13.5px; font-weight: 700; font-family: 'Poppins', sans-serif;
    cursor: pointer; letter-spacing: .3px;
    transition: background .18s, transform .15s, box-shadow .2s;
}
.dzrpt-submit-btn:hover { background: #e01f1f; box-shadow: 0 4px 20px rgba(255,45,45,.35); }
.dzrpt-submit-btn:active { transform: scale(.98); }
.dzrpt-submit-btn:disabled { opacity: .55; pointer-events: none; }
.dzrpt-cancel-btn {
    width: 100%; padding: 12px; margin-top: 10px; border-radius: 13px;
    background: rgba(255,255,255,.05);
    border: 1px solid rgba(255,255,255,.09);
    color: rgba(255,255,255,.42); font-size: 13px; font-weight: 600;
    font-family: 'Poppins', sans-serif; cursor: pointer;
    transition: background .18s;
}
.dzrpt-cancel-btn:hover { background: rgba(255,255,255,.09); }

.dzrpt-success-body {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    min-height: calc(100vh - 70px);
    padding: 40px 28px 60px; text-align: center;
}
.dzrpt-success-icon {
    width: 86px; height: 86px; border-radius: 50%;
    background: rgba(77,222,138,.1);
    border: 2px solid rgba(77,222,138,.3);
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 24px; font-size: 38px; color: #4dde8a;
}
.dzrpt-success-title { font-size: 19px; font-weight: 700; color: #fff; margin-bottom: 12px; }
.dzrpt-success-desc {
    font-size: 13.5px; color: rgba(255,255,255,.42);
    line-height: 1.75; max-width: 300px; margin: 0 auto 36px;
}
.dzrpt-done-btn {
    padding: 14px 48px; border-radius: 50px;
    background: #FF2D2D; border: none; color: #fff;
    font-size: 14px; font-weight: 700; font-family: 'Poppins', sans-serif;
    cursor: pointer; transition: background .18s;
}
.dzrpt-done-btn:hover { background: #e01f1f; }

.dzrpt-toast {
    position: fixed; bottom: 30px; left: 50%;
    transform: translateX(-50%) translateY(10px);
    background: rgba(8,8,22,.97);
    border: 1px solid rgba(255,45,45,.25);
    color: #fff; padding: 9px 22px; border-radius: 50px;
    font-size: 12px; font-family: 'Poppins', sans-serif; font-weight: 500;
    z-index: 999999999; pointer-events: none;
    box-shadow: 0 8px 28px rgba(0,0,0,.65);
    white-space: nowrap; opacity: 0;
    transition: opacity .22s ease, transform .22s ease;
}
.dzrpt-toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }

@media (max-width: 780px) {
    .dzrpt-topbar        { padding: 13px 18px; }
    .dzrpt-topbar-title  { font-size: 14.5px; }
    .dzrpt-section-label { padding: 18px 18px 7px; }
    .dzrpt-cat-row       { padding: 13px 18px; gap: 14px; }
    .dzrpt-reason-row    { padding: 13px 18px; gap: 13px; }
    .dzrpt-userid-wrap   { padding: 16px 18px 5px; }
    .dzrpt-other-wrap    { padding: 9px 18px 12px; }
    .dzrpt-footer        { padding: 14px 18px 36px; }
    .dzrpt-back-btn i    { font-size: 28px; }
}
@media (max-width: 490px) {
    .dzrpt-topbar        { padding: 11px 16px; }
    .dzrpt-topbar-title  { font-size: 13.5px; }
    .dzrpt-topbar-sub    { font-size: 10px; }
    .dzrpt-back-btn i    { font-size: 26px; }
    .dzrpt-close-btn     { width: 27px; height: 27px; font-size: 15px; }
    .dzrpt-section-label { font-size: 9px; letter-spacing: 1.3px; padding: 16px 16px 6px; }
    .dzrpt-cat-row       { padding: 12px 16px; gap: 13px; }
    .dzrpt-cat-icon      { width: 38px; height: 38px; font-size: 18px; border-radius: 11px; }
    .dzrpt-cat-label     { font-size: 13px; }
    .dzrpt-cat-sub       { font-size: 10.5px; }
    .dzrpt-cat-chevron   { font-size: 16px; }
    .dzrpt-reason-row    { padding: 12px 16px; gap: 12px; }
    .dzrpt-reason-icon   { font-size: 17px; width: 22px; }
    .dzrpt-reason-text   { font-size: 13px; }
    .dzrpt-check         { width: 21px; height: 21px; font-size: 11px; }
    .dzrpt-userid-wrap   { padding: 14px 16px 4px; }
    .dzrpt-userid-label  { font-size: 9px; }
    .dzrpt-userid-input  { font-size: 13px; padding: 12px 14px; border-radius: 12px; }
    .dzrpt-userid-hint   { font-size: 10px; }
    .dzrpt-other-wrap    { padding: 8px 16px 10px; }
    .dzrpt-other-textarea{ font-size: 12.5px; padding: 12px 14px; border-radius: 12px; min-height: 90px; }
    .dzrpt-footer        { padding: 13px 16px 32px; }
    .dzrpt-submit-btn    { padding: 13px; font-size: 13px; border-radius: 12px; }
    .dzrpt-cancel-btn    { padding: 11px; font-size: 12.5px; border-radius: 12px; }
    .dzrpt-success-icon  { width: 76px; height: 76px; font-size: 32px; }
    .dzrpt-success-title { font-size: 17px; }
    .dzrpt-success-desc  { font-size: 13px; }
    .dzrpt-done-btn      { padding: 13px 40px; font-size: 13.5px; }
}
@media (max-width: 380px) {
    .dzrpt-topbar        { padding: 10px 14px; }
    .dzrpt-topbar-title  { font-size: 13px; }
    .dzrpt-back-btn i    { font-size: 24px; }
    .dzrpt-close-btn     { width: 25px; height: 25px; font-size: 14px; }
    .dzrpt-section-label { font-size: 8.5px; padding: 14px 14px 5px; }
    .dzrpt-cat-row       { padding: 11px 14px; gap: 12px; }
    .dzrpt-cat-icon      { width: 36px; height: 36px; font-size: 17px; border-radius: 10px; }
    .dzrpt-cat-label     { font-size: 12.5px; }
    .dzrpt-cat-sub       { font-size: 10px; }
    .dzrpt-reason-row    { padding: 11px 14px; gap: 11px; }
    .dzrpt-reason-icon   { font-size: 16px; width: 20px; }
    .dzrpt-reason-text   { font-size: 12.5px; }
    .dzrpt-check         { width: 20px; height: 20px; }
    .dzrpt-userid-wrap   { padding: 13px 14px 4px; }
    .dzrpt-userid-input  { font-size: 12.5px; padding: 11px 13px; border-radius: 11px; }
    .dzrpt-other-wrap    { padding: 7px 14px 10px; }
    .dzrpt-other-textarea{ font-size: 12px; padding: 11px 13px; border-radius: 11px; }
    .dzrpt-footer        { padding: 12px 14px 28px; }
    .dzrpt-submit-btn    { padding: 12px; font-size: 12.5px; border-radius: 11px; }
    .dzrpt-cancel-btn    { padding: 10px; font-size: 12px; border-radius: 11px; }
    .dzrpt-success-icon  { width: 70px; height: 70px; font-size: 28px; }
    .dzrpt-success-title { font-size: 16px; }
    .dzrpt-success-desc  { font-size: 12.5px; }
    .dzrpt-done-btn      { padding: 12px 36px; font-size: 13px; }
}
@media (max-width: 330px) {
    .dzrpt-topbar        { padding: 9px 12px; }
    .dzrpt-topbar-title  { font-size: 12.5px; }
    .dzrpt-back-btn i    { font-size: 22px; }
    .dzrpt-close-btn     { width: 23px; height: 23px; font-size: 13px; }
    .dzrpt-section-label { font-size: 8px; padding: 12px 12px 4px; }
    .dzrpt-cat-row       { padding: 10px 12px; gap: 10px; }
    .dzrpt-cat-icon      { width: 33px; height: 33px; font-size: 15px; border-radius: 9px; }
    .dzrpt-cat-label     { font-size: 12px; }
    .dzrpt-cat-sub       { font-size: 9.5px; }
    .dzrpt-reason-row    { padding: 10px 12px; gap: 10px; }
    .dzrpt-reason-icon   { font-size: 15px; width: 18px; }
    .dzrpt-reason-text   { font-size: 12px; }
    .dzrpt-check         { width: 19px; height: 19px; font-size: 10px; }
    .dzrpt-userid-wrap   { padding: 11px 12px 3px; }
    .dzrpt-userid-input  { font-size: 12px; padding: 10px 12px; border-radius: 10px; }
    .dzrpt-userid-hint   { font-size: 9.5px; }
    .dzrpt-other-textarea{ font-size: 11.5px; padding: 10px 12px; border-radius: 10px; }
    .dzrpt-footer        { padding: 10px 12px 24px; }
    .dzrpt-submit-btn    { padding: 11px; font-size: 12px; border-radius: 10px; }
    .dzrpt-cancel-btn    { padding: 9px; font-size: 11.5px; border-radius: 10px; }
    .dzrpt-success-icon  { width: 64px; height: 64px; font-size: 26px; }
    .dzrpt-success-title { font-size: 15px; }
    .dzrpt-success-desc  { font-size: 12px; }
}

@keyframes dzRptShakeAnim {
    0%,100%{transform:translateX(0);}
    20%{transform:translateX(-6px);}
    40%{transform:translateX(6px);}
    60%{transform:translateX(-4px);}
    80%{transform:translateX(4px);}
}
</style>

<script>
(function () {
    var _rState = {
        step: 0, category: null,
        targetId: null, contentType: null,
        ownerId: null, reason: null
    };

    var _userReasons = [
        { icon:'ri-ghost-line',           text:'Fake Account' },
        { icon:'ri-spy-line',             text:'Impersonation' },
        { icon:'ri-spam-2-line',          text:'Spam Activity' },
        { icon:'ri-emotion-unhappy-line', text:'Harassment or Bullying' },
        { icon:'ri-prohibited-line',      text:'Hate Speech' },
        { icon:'ri-suitcase-line',        text:'Scam or Fraud' },
        { icon:'ri-skull-line',           text:'Dangerous Behavior' },
        { icon:'ri-user-unfollow-line',   text:'Underage User' },
        { icon:'ri-eye-off-line',         text:'Privacy Violations' },
        { icon:'ri-scales-3-line',        text:'Illegal Activity' },
        { icon:'ri-alert-line',           text:'Posting Harmful Content' },
        { icon:'ri-robot-line',           text:'Suspicious or Bot Activity' },
        { icon:'ri-repeat-line',          text:'Repeated Rule Violations' },
        { icon:'ri-team-line',            text:'Coordinated Abuse' },
        { icon:'ri-question-line',        text:'Other' }
    ];
    var _contentReasons = [
        { icon:'ri-knife-blood-line',     text:'Graphic Violence or Gore' },
        { icon:'ri-eye-2-line',           text:'Nudity or Sexual Content' },
        { icon:'ri-newspaper-line',       text:'Misinformation or Fake News' },
        { icon:'ri-emotion-unhappy-line', text:'Harassment or Cyberbullying' },
        { icon:'ri-prohibited-line',      text:'Hate Speech or Discrimination' },
        { icon:'ri-spam-2-line',          text:'Spam or Clickbait' },
        { icon:'ri-copyright-line',       text:'Copyright Infringement' },
        { icon:'ri-alert-line',           text:'Illegal or Dangerous Content' },
        { icon:'ri-heart-pulse-line',     text:'Self-Harm or Suicide Content' },
        { icon:'ri-shield-user-line',     text:'Child Safety Concern' },
        { icon:'ri-suitcase-line',        text:'Scam or Deceptive Content' },
        { icon:'ri-emotion-sad-line',     text:'Disturbing or Upsetting Content' },
        { icon:'ri-question-line',        text:'Other' }
    ];

    function _openScreen(id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.style.display = 'block';
        document.body.style.overflow = 'hidden';
        requestAnimationFrame(function () {
            requestAnimationFrame(function () { el.classList.add('dzrpt-open'); });
        });
    }
    function _closeScreen(id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.classList.remove('dzrpt-open');
        setTimeout(function () { el.style.display = 'none'; }, 270);
    }
    function _closeAll() {
        ['dzReportOverlay','dzRptStep1Screen','dzRptStep2Screen'].forEach(_closeScreen);
        document.body.style.overflow = '';
        setTimeout(function () {
            _rState = { step:0, category:null, targetId:null, contentType:null, ownerId:null, reason:null };
            var ta = document.getElementById('dzRptOtherInput');
            if (ta) ta.value = '';
            var cl = document.getElementById('dzRptCharLabel');
            if (cl) cl.textContent = '0 / 500';
            var uid = document.getElementById('dzRptUserIdInput');
            if (uid) uid.value = '';
        }, 280);
    }

    window.openReportModal = function (opts) {
        opts = opts || {};
        _rState.targetId    = opts.userId || opts.contentId || null;
        _rState.contentType = opts.contentType || null;
        _rState.ownerId     = opts.ownerId || null;

        if (opts.userId && !opts.skipCat) {
            _rState.category = 'user';
            _rState.targetId = opts.userId;
            _buildReasonScreen('user');
            _openScreen('dzRptStep1Screen');
        } else if (opts.contentId) {
            _rState.category    = 'content';
            _rState.targetId    = opts.contentId;
            _rState.contentType = opts.contentType || 'meme';
            _buildReasonScreen('content');
            _openScreen('dzRptStep1Screen');
        } else {
            _openScreen('dzReportOverlay');
        }
    };

    window.reportUser = function (profileId) {
        openReportModal({ userId: profileId });
    };

    window.closeReportModal = function () { _closeAll(); };

    window.dzRptSelectCat = function (cat) {
        _rState.category = cat;
        if (cat === 'content' && !_rState.contentType) {
            _rState.contentType = 'meme';
        }
        _buildReasonScreen(cat);
        _closeScreen('dzReportOverlay');
        setTimeout(function () { _openScreen('dzRptStep1Screen'); }, 80);
    };

    window.dzRptBack = function () {
        _rState.reason = null;
        _closeScreen('dzRptStep1Screen');
        setTimeout(function () { _openScreen('dzReportOverlay'); }, 80);
    };

    function _buildReasonScreen(cat) {
        var reasons = cat === 'user' ? _userReasons : _contentReasons;
        var titles = {
            user:    'Why are you reporting this user?',
            content: 'Why are you reporting this content?'
        };
        document.getElementById('dzRptTitle').textContent    = titles[cat] || 'Select a reason';
        document.getElementById('dzRptSubtitle').textContent = 'Your report is anonymous';

        var uidWrap = document.getElementById('dzRptUserIdWrap');
        if (uidWrap) uidWrap.style.display = cat === 'user' ? 'block' : 'none';

        _rState.reason = null;
        document.getElementById('dzRptOtherWrap').style.display = 'none';

        var list = document.getElementById('dzRptReasonList');
        list.innerHTML = '';
        reasons.forEach(function (r) {
            var row = document.createElement('div');
            row.className = 'dzrpt-reason-row';
            row.dataset.reason = r.text;
            row.innerHTML =
                '<i class="' + r.icon + ' dzrpt-reason-icon" aria-hidden="true"></i>' +
                '<span class="dzrpt-reason-text">' + _esc(r.text) + '</span>' +
                '<div class="dzrpt-check"></div>';
            row.addEventListener('click', function () { _selectReason(r.text); });
            list.appendChild(row);
        });

        var screen = document.getElementById('dzRptStep1Screen');
        if (screen) screen.scrollTop = 0;
    }

    function _selectReason(reason) {
        _rState.reason = reason;
        document.querySelectorAll('.dzrpt-reason-row').forEach(function (row) {
            var sel = row.dataset.reason === reason;
            row.classList.toggle('selected', sel);
            row.querySelector('.dzrpt-check').textContent = sel ? '✓' : '';
        });
        var ow = document.getElementById('dzRptOtherWrap');
        ow.style.display = reason === 'Other' ? 'block' : 'none';
        if (reason !== 'Other') {
            var ta = document.getElementById('dzRptOtherInput');
            if (ta) ta.value = '';
            var cl = document.getElementById('dzRptCharLabel');
            if (cl) cl.textContent = '0 / 500';
        }
    }

    window.dzRptCharCount = function (el) {
        var cl = document.getElementById('dzRptCharLabel');
        if (cl) cl.textContent = el.value.length + ' / 500';
    };

    window.dzRptSubmit = function () {
        if (!_rState.reason) {
            var list = document.getElementById('dzRptReasonList');
            list.style.animation = 'none'; void list.offsetWidth;
            list.style.animation = 'dzRptShakeAnim 0.35s ease';
            setTimeout(function () { list.style.animation = ''; }, 400);
            _showToast('Please select a reason first');
            return;
        }
        if (_rState.reason === 'Other') {
            var ta = document.getElementById('dzRptOtherInput');
            if (!ta.value.trim()) {
                ta.style.borderColor = 'rgba(255,45,45,0.7)';
                _showToast('Please describe the issue');
                return;
            }
        }

        var btn = document.getElementById('dzRptSubmitBtn');
        btn.disabled = true; btn.textContent = 'Submitting…';

        var cat = _rState.category;
        var fd  = new FormData();
        var endpoint;

        if (cat === 'user') {
            endpoint = 'report_user.php';
            fd.append('type', 'user');
            fd.append('reported_id', _rState.targetId);
            var uid = (document.getElementById('dzRptUserIdInput').value || '').trim();
            if (uid) fd.append('user_id_hint', uid);
        } else {
            endpoint = 'report_content.php';
            fd.append('reported_id', _rState.targetId);
            fd.append('content_type', _rState.contentType || 'meme');
        }
        fd.append('reason', _rState.reason);
        var desc = (document.getElementById('dzRptOtherInput').value || '').trim();
        if (desc) fd.append('description', desc);

        fetch(endpoint, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                btn.disabled = false; btn.textContent = 'Submit Report';
                if (d.success || d.msg === 'already_reported') {
                    _closeScreen('dzRptStep1Screen');
                    setTimeout(function () { _openScreen('dzRptStep2Screen'); }, 80);
                } else {
                    _showToast(d.msg || 'Something went wrong. Try again.');
                }
            })
            .catch(function () {
                btn.disabled = false; btn.textContent = 'Submit Report';
                _showToast('Network error — check your connection');
            });
    };

    function _showToast(msg) {
        var t = document.getElementById('dzRptToastEl');
        if (!t) return;
        t.textContent = msg; t.classList.add('show');
        clearTimeout(t._tid);
        t._tid = setTimeout(function () { t.classList.remove('show'); }, 2600);
    }

    function _esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var s2 = document.getElementById('dzRptStep2Screen');
        var s1 = document.getElementById('dzRptStep1Screen');
        var s0 = document.getElementById('dzReportOverlay');
        if (s2 && s2.classList.contains('dzrpt-open')) { _closeAll(); }
        else if (s1 && s1.classList.contains('dzrpt-open')) { window.dzRptBack(); }
        else if (s0 && s0.classList.contains('dzrpt-open')) { _closeAll(); }
    });

})();
</script>



<!-- CARD CAPTION MODAL — same Reels/TikTok-style player as home.php,
     text-only build (no image/video branches needed since every post
     in text_feed.php is type='text') -->
<div id="cardCaptionModal" onclick="if(event.target===this) closeCaptionModal()">
    <div id="ccm-stage">
        <button type="button" id="ccmFixedBackBtn" class="ccm-icon-btn ccm-back-btn"
                onclick="event.stopPropagation();closeCaptionModal()">
            <i class="ri-arrow-left-s-line"></i>
        </button>
        <div id="ccm-inner"></div>
        <div id="ccm-nav-hint">swipe up / down ↕</div>
    </div>

    <!-- desktop-only up/down navigation -->
    <div id="ccm-arrows">
        <button type="button" onclick="ccmLoadIndex(_ccmIndex-1)" title="Previous">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.85)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"/></svg>
        </button>
        <button type="button" onclick="ccmLoadIndex(_ccmIndex+1)" title="Next">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.85)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
    </div>
</div>

<style>
/* ══════════════════════════════════════════════════════════
   CARD CAPTION MODAL — REELS/TIKTOK STYLE FULL-SCREEN PLAYER
   (text_feed.php build — upgraded onto the same gapless 3-slide
   track engine as home.php, with ad + suggested-user interfaces
   ported 1:1 from home.php. Post rendering stays text-only since
   text_feed.php posts are always type='text'.)
   ══════════════════════════════════════════════════════════ */
#cardCaptionModal{
    display:none;
    position:fixed;inset:0;
    z-index:9999999;
    background:#000;
    align-items:center;
    justify-content:center;
}
#cardCaptionModal.ccm-open{ display:flex; }

#ccm-stage{
    position:relative;
    width:100%;
    height:100%;
    max-width:480px;
    background:#000;
    overflow:hidden;
    touch-action:pan-y;
    will-change:transform;
}

@media(min-width:791px){
    #ccm-stage{
        height:92vh;
        max-height:940px;
        border-radius:22px;
        box-shadow:0 25px 90px rgba(0,0,0,.65), 0 0 0 1px rgba(255,255,255,.06);
        clip-path: inset(0 round 22px);
        -webkit-clip-path: inset(0 round 22px);
    }
}

#ccm-inner{ position:relative; width:100%; height:100%; overflow:hidden; }

.ccm-vtrack{ position:absolute; inset:0; will-change:transform; }
.ccm-vslide{
    position:absolute; left:0; right:0; top:0;
    height:100%;
    background:#000;
}
.ccm-vslide[data-pos="prev"]{ transform:translateY(calc(-100% - 2px)); }
.ccm-vslide[data-pos="current"]{ transform:translateY(0); }
.ccm-vslide[data-pos="next"]{ transform:translateY(calc(100% + 2px)); }

.ccm-slide{
    position:absolute; inset:0;
    background:#000;
}

.ccm-media-box{ width:100%; height:100%; position:relative; background:#000; overflow:hidden; }

.ccm-media-box .ccm-media{
    width:100%; height:100%;
    object-fit:contain;
    background:transparent;
    display:block;
    position:relative;
    z-index:1;
}

/* ══════════════════════════════════════════════════════════
   MULTI-MEDIA GALLERY (horizontal swipe within one Zeem)
   ══════════════════════════════════════════════════════════ */
.ccm-media-gallery{
    display:flex; flex-direction:row;
    width:100%; height:100%;
    transition:transform .28s cubic-bezier(.22,1,.36,1);
}
.ccm-media-item{
    position:relative;
    flex:0 0 100%; width:100%; height:100%;
    background:#000;
}

.ccm-media-arrow{
    position:absolute; top:34%; transform:translateY(-50%);
    width:36px; height:36px; border-radius:50%;
    background:rgba(0,0,0,.35);
    display:flex; align-items:center; justify-content:center;
    color:#fff; cursor:pointer; z-index:6;
    opacity:0; transition:opacity .2s ease;
    -webkit-tap-highlight-color:transparent;
}
.ccm-media-box:hover .ccm-media-arrow{ opacity:1; }
.ccm-media-arrow-left{ left:56px; }
.ccm-media-arrow-right{ right:64px; }
.ccm-media-arrow i{ font-size:20px; }
@media (hover:none){ .ccm-media-arrow{ display:none !important; } }

/* ── Dashes flex to fill the full modal width — fewer
   items grow up to a max cap (fat, easy to see), more items shrink
   toward a small floor, but the row still spans edge-to-edge inside
   a fixed 16px inset so it never touches the screen edges. Broken
   out of .ccm-bottom's asymmetric padding via matching negative
   margins so it's centered against the true full width, not the
   lopsided content box. ── */
.ccm-media-dashes{
    display:flex; justify-content:center; align-items:center; gap:6px;
    margin-top:18px;
    margin-left:-16px; margin-right:-78px;
    width:calc(100% + 94px);
    padding:0 16px;
    box-sizing:border-box;
}
.ccm-dash{
    flex:1 1 auto; max-width:46px; min-width:6px; height:3px; border-radius:2px;
    background:rgba(255,255,255,.32);
    transition:background .2s ease, transform .2s ease;
}
.ccm-dash.active{
    background:#ff3b30;
}

#ccmFixedBackBtn{
    position:absolute;
    top:14px;
    left:4px;
    z-index:50;
}
@media (max-width:490px){
    #ccmFixedBackBtn{ top:11px; left:3px; }
}

/* ══════════════════════════════════════════════════════════
   VIDEO PROGRESS BAR
   ══════════════════════════════════════════════════════════ */
.ccm-video-progress{
    position:absolute; bottom:0; left:0; right:0; height:3px;
    background:rgba(255,255,255,.22);
    z-index:10; overflow:hidden;
    pointer-events:none;
}
.ccm-video-progress-fill{
    height:100%; width:0%;
    background:linear-gradient(90deg,#ff2b2b,#ff6b5f);
    transition:width .12s linear;
}

/* ══════════════════════════════════════════════════════════
   DOUBLE-TAP LIKE HEART BURST
   ══════════════════════════════════════════════════════════ */
.ccm-double-tap-heart{
    position:absolute; z-index:14; pointer-events:none;
    transform:translate(-50%,-50%) scale(0) rotate(0deg);
    opacity:0;
}
.ccm-double-tap-heart i{
    font-size:100px; color:#ff2d4f;
    filter:drop-shadow(0 6px 22px rgba(255,45,79,.65));
    -webkit-text-stroke:1.5px rgba(255,255,255,.35);
}
.ccm-double-tap-heart.show{
    animation: ccmHeartBurst .95s cubic-bezier(.17,.89,.32,1.28) forwards;
}
@keyframes ccmHeartBurst{
    0%{   transform:translate(-50%,-50%) scale(0)    rotate(-28deg); opacity:0; }
    14%{  transform:translate(-50%,-50%) scale(1.35) rotate(10deg);  opacity:1; }
    28%{  transform:translate(-50%,-50%) scale(.88)  rotate(-6deg);  opacity:1; }
    42%{  transform:translate(-50%,-50%) scale(1.14) rotate(4deg);   opacity:1; }
    58%{  transform:translate(-50%,-50%) scale(.97)  rotate(-1deg);  opacity:1; }
    75%{  transform:translate(-50%,-50%) scale(1.05) rotate(0deg);   opacity:1; }
    100%{ transform:translate(-50%,-50%) scale(1.15) rotate(0deg) translateY(-46px); opacity:0; }
}

.ccm-heart-ring{
    position:absolute; z-index:13; pointer-events:none;
    width:110px; height:110px;
    margin-left:-55px; margin-top:-55px;
    border-radius:50%;
    border:3px solid rgba(255,45,79,.65);
    transform:scale(.25);
    opacity:0;
}
.ccm-heart-ring.show{
    animation: ccmRingPulse .75s cubic-bezier(.15,.6,.35,1) forwards;
}
@keyframes ccmRingPulse{
    0%{   transform:scale(.25); opacity:.9; border-width:4px; }
    100%{ transform:scale(2.4); opacity:0;  border-width:1px; }
}

.ccm-heart-particle{
    position:absolute; z-index:12; pointer-events:none;
    left:0; top:0;
    transform:translate(-50%,-50%) scale(0);
    opacity:0;
    color:#ff2d4f;
}
.ccm-heart-particle i{ filter:drop-shadow(0 2px 8px rgba(255,45,79,.55)); }
.ccm-heart-particle.show{
    animation: ccmParticleFly .8s cubic-bezier(.2,.7,.3,1) forwards;
}
@keyframes ccmParticleFly{
    0%{   transform:translate(-50%,-50%) scale(0)    rotate(0deg);        opacity:0; }
    12%{  opacity:1; }
    100%{ transform:translate(calc(-50% + var(--tx)), calc(-50% + var(--ty))) scale(1) rotate(var(--tr)); opacity:0; }
}

/* ══════════════════════════════════════════════════════════
   PREMIUM SKELETON LOADER
   ══════════════════════════════════════════════════════════ */
.ccm-skeleton{
    position:absolute; inset:0; z-index:15;
    background:#0c0c10;
    opacity:1;
    transition:opacity .32s ease;
    pointer-events:none;
    overflow:hidden;
}
.ccm-skeleton.ccm-skeleton-hide{ opacity:0; }

.ccm-skel-block{
    position:absolute;
    background:linear-gradient(120deg,#17171b 25%,#232329 37%,#17171b 63%);
    background-size:400% 100%;
    animation:ccmSkelPulse 1.3s ease-in-out infinite;
    border-radius:8px;
}
@keyframes ccmSkelPulse{
    0%{ background-position:100% 50%; }
    100%{ background-position:0% 50%; }
}

.ccm-skel-media{ inset:0; border-radius:0; }
.ccm-skel-avatar{ left:16px; bottom:26px; width:48px; height:48px; border-radius:50%; }
.ccm-skel-line{ left:74px; height:11px; border-radius:5px; }
.ccm-skel-line1{ bottom:53px; width:130px; }
.ccm-skel-line2{ bottom:33px; width:190px; }
.ccm-skel-rail{ right:16px; width:27px; height:27px; border-radius:50%; }
.ccm-skel-rail1{ bottom:26px; }
.ccm-skel-rail2{ bottom:78px; }
.ccm-skel-rail3{ bottom:130px; }
.ccm-skel-rail4{ bottom:182px; }
.ccm-skel-rail5{ bottom:234px; }

.ccm-skel-shimmer{
    position:absolute; inset:0; z-index:1;
    background:linear-gradient(100deg,
        rgba(255,255,255,0) 30%,
        rgba(255,255,255,.05) 45%,
        rgba(255,255,255,.12) 50%,
        rgba(255,255,255,.05) 55%,
        rgba(255,255,255,0) 70%);
    background-size:250% 100%;
    animation:ccmShimmerSweep 1.3s ease-in-out infinite;
}
@keyframes ccmShimmerSweep{
    0%{ background-position:150% 0; }
    100%{ background-position:-150% 0; }
}

/* ── TAP-TO-PAUSE FEEDBACK (RESUME ONLY) ── */
.ccm-tap-flash{
    position:absolute; top:50%; left:50%;
    transform:translate(-50%,-50%) scale(.6);
    z-index:3; pointer-events:none;
    width:64px; height:64px; border-radius:50%;
    background:rgba(0,0,0,.45);
    display:flex; align-items:center; justify-content:center;
    opacity:0; transition:opacity .2s ease, transform .2s ease;
}
.ccm-tap-flash.show{ opacity:1; transform:translate(-50%,-50%) scale(1); }
.ccm-tap-flash i{ font-size:32px; color:#fff; }

/* ── PERSISTENT PAUSE + MUTE + SEEK CONTROLS ── */
.ccm-paused-controls{
    position:absolute; inset:0; z-index:4;
    display:flex; align-items:center; justify-content:center;
    pointer-events:none;
}
.ccm-paused-stack{ display:flex; flex-direction:column; align-items:center; gap:14px; }
.ccm-paused-row{ display:flex; align-items:center; gap:22px; }
.ccm-pause-icon{
    width:60px; height:60px; border-radius:50%;
    background:rgba(0,0,0,.45);
    display:flex; align-items:center; justify-content:center;
    flex-shrink:0;
}
.ccm-pause-icon i{ font-size:28px; color:#fff; }
.ccm-mute-btn{
    width:46px; height:46px; border-radius:50%;
    background:rgba(0,0,0,.45); border:none; cursor:pointer;
    display:flex; align-items:center; justify-content:center;
    pointer-events:all;
    -webkit-tap-highlight-color:transparent;
    transition:transform .15s ease, background .15s ease;
}
.ccm-mute-btn:active{ transform:scale(.9); background:rgba(0,0,0,.6); }
.ccm-mute-btn i{ font-size:20px; color:#fff; }

.ccm-seek-btn{
    width:38px; height:38px; border-radius:50%;
    background:rgba(0,0,0,.4); border:none; cursor:pointer;
    display:flex; align-items:center; justify-content:center;
    pointer-events:all;
    -webkit-tap-highlight-color:transparent;
    transition:transform .15s ease, background .15s ease;
    flex-shrink:0;
}
.ccm-seek-btn:active{ transform:scale(.88); background:rgba(0,0,0,.58); }
.ccm-seek-btn i{ font-size:17px; color:#fff; }

/* ── CAPTION-ONLY POSTS ── */
.ccm-textonly-bg{
    position:absolute; inset:0; z-index:0;
    background-image:url('uploads/Text.jpg');
    background-size:cover; background-position:center;
}
.ccm-textonly-overlay{ position:absolute; inset:0; z-index:0; background:rgba(0,0,0,.45); }

.ccm-center-caption-wrap{
    position:absolute;
    top:122px; left:16px; right:70px; bottom:225px;
    z-index:1;
    display:flex;
    align-items:center;
    justify-content:center;
    text-align:center;
}
.ccm-center-caption-wrap.ccm-caption-overflow{ align-items:flex-start; }
.ccm-center-caption-text{
    width:100%;
    max-width:420px;
    margin:0 auto;
    transform:translateX(14px);
    color:#fff; font-size:16px; font-weight:500; line-height:1.5;
    text-shadow:0 1px 5px rgba(0,0,0,.4);
    max-height:100%; overflow-y:auto;
    word-break:break-word;
    scrollbar-width:none; -ms-overflow-style:none;
    touch-action:pan-y;
    -webkit-overflow-scrolling:touch;
}
.ccm-center-caption-text::-webkit-scrollbar{ display:none; }

/* ── TOP BAR ── */
.ccm-top-bar{
    position:absolute; top:0; left:0; right:0; z-index:8;
    padding:14px 12px 34px 4px;
    background:linear-gradient(to bottom, rgba(0,0,0,.68) 0%, rgba(0,0,0,.3) 60%, transparent 100%);
    pointer-events:none;
}
.ccm-top-bar-row{ display:flex; justify-content:space-between; align-items:center; pointer-events:all; }
.ccm-top-bar > *{ pointer-events:all; }
.ccm-back-btn{ padding-left:2px !important; }

.ccm-icon-btn{
    background:none; border:none; box-shadow:none;
    color:#fff; cursor:pointer; padding:6px;
    display:flex; align-items:center; justify-content:center;
    -webkit-tap-highlight-color:transparent;
    transition:transform .15s ease, opacity .15s ease;
}
.ccm-icon-btn:active{ transform:scale(.88); opacity:.7; }
.ccm-icon-btn i{ font-size:30px; filter:drop-shadow(0 2px 6px rgba(0,0,0,.7)); }
.ccm-search-btn i{ font-size:24px; }

.ccm-back-group{ display:flex; align-items:center; gap:2px; }
.ccm-brand-label{
    color:#fff; font-weight:700; font-size:16px;
    text-shadow:0 2px 6px rgba(0,0,0,.6);
    font-family:Poppins,sans-serif;
}
.ccm-brand-dot{ color:rgba(255,255,255,.6); font-size:14px; font-weight:700; margin:0 5px; }
.ccm-brand-date{
    color:rgba(255,255,255,.75); font-size:12.5px; font-weight:500;
    text-shadow:0 1px 4px rgba(0,0,0,.6);
    font-family:Poppins,sans-serif; white-space:nowrap;
}

.ccm-ad-badge{
    font-size:9px; font-weight:800; letter-spacing:1.2px; color:#fff;
    border:1px solid rgba(255,255,255,.4); border-radius:4px; padding:3px 7px;
    text-transform:uppercase; background:rgba(0,0,0,.3);
}

.ccm-group-tag{
    position:absolute; top:80px; left:16px; z-index:8;
    display:flex; align-items:center; gap:5px;
    background:rgba(22,22,24,.72);
    border-radius:6px; padding:5px 9px 5px 7px;
    font-size:11px; font-weight:500; color:rgba(255,255,255,.85);
    text-decoration:none;
    max-width:calc(100% - 90px);
    white-space:nowrap; overflow:hidden;
}
.ccm-group-tag i{ font-size:12.5px; color:rgba(255,255,255,.5); flex-shrink:0; }
.ccm-group-tag-link{
    color:#ff4c4c; font-weight:700; text-decoration:none;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.ccm-group-tag:hover .ccm-group-tag-link{ text-decoration:underline; }

.ccm-top-right-tags{
    position:absolute; top:80px; right:14px; z-index:8;
    display:flex; flex-direction:column; align-items:flex-end; gap:6px;
    pointer-events:none;
}

.ccm-media-counter{
    background:rgba(22,22,24,.82); color:#fff;
    font-size:10px; font-weight:800; letter-spacing:.3px;
    padding:5px 9px; border-radius:6px;
}

.ccm-type-tag{
    display:flex; align-items:center; gap:4px;
    background:rgba(22,22,24,.82); border:none;
    border-radius:6px; padding:5px 9px 5px 7px;
}
.ccm-type-tag i{ font-size:12.5px; color:#fff; }
.ccm-type-tag span{
    font-size:10px; font-weight:700; color:#fff;
    text-transform:uppercase; letter-spacing:.4px;
    white-space:nowrap;
}

/* ── RIGHT ACTION RAIL (like/repost/fav/SHARE/menu) ── */
.ccm-rail{
    position:absolute; right:16px; bottom:26px; z-index:9;
    display:flex; flex-direction:column; align-items:center; gap:20px;
}
.ccm-rail.ccm-rail-raised{ bottom:42px; }

.ccm-rail-btn{
    display:flex; flex-direction:column; align-items:center; gap:5px;
    background:none; border:none; cursor:pointer;
    -webkit-tap-highlight-color:transparent;
}
.ccm-rail-btn i{
    font-size:23px; color:#fff;
    -webkit-text-stroke:0.6px currentColor;
    filter:drop-shadow(0 2px 5px rgba(0,0,0,.55));
    transition:transform .18s ease, color .18s ease;
}
.ccm-rail-btn.active i{ color:#ff2d55; }
.ccm-rail-btn:active i{ transform:scale(1.3); }
.ccm-rail-count{
    font-size:12px; font-weight:800;
    -webkit-text-stroke:0.2px currentColor;
    color:#fff;
    text-shadow:0 1px 4px rgba(0,0,0,.75), 0 0 8px rgba(255,255,255,.25);
}

@keyframes ccm-repost-arc-swap {
    0%   { transform: rotate(0deg) scale(1); }
    35%  { transform: rotate(140deg) scale(1.15); }
    50%  { transform: rotate(180deg) scale(1.2); }
    65%  { transform: rotate(220deg) scale(1.15); }
    100% { transform: rotate(360deg) scale(1); }
}
.ccm-rail-btn.arc-swap-active i {
    animation: ccm-repost-arc-swap 0.6s cubic-bezier(.33,1,.68,1);
    transition: none !important;
}

#ccm-share-btn i{ font-size:26px; }

.ccm-rail-msg{ text-decoration:none; }
.ccm-rail-msg-pic{
    width:30px; height:30px; object-fit:cover;
    border-radius:10px; border:2px solid #fff;
    box-shadow:0 3px 8px rgba(0,0,0,.55);
    display:block; transition:transform .15s ease;
}
.ccm-rail-msg:active .ccm-rail-msg-pic{ transform:scale(1.12); }

/* ── BOTTOM INFO PANEL ── */
.ccm-bottom{
    position:absolute; left:0; right:0; bottom:0; z-index:7;
    padding:16px 78px 22px 16px;
    background:linear-gradient(to top, rgba(0,0,0,.88) 0%, rgba(0,0,0,.55) 55%, transparent 100%);
}
.ccm-bottom.ccm-no-rail{ padding-right:16px; }

.ccm-user-row{ display:flex; align-items:center; gap:10px; margin-bottom:5px; }
.ccm-user-pic{
    width:48px; height:48px; border-radius:50%; object-fit:cover;
    border:2.5px solid #ff2b2b; cursor:pointer; flex-shrink:0;
}
.ccm-user-info{
    display:flex; flex-direction:column; justify-content:center;
    gap:0px; min-width:0; flex:1;
}
.ccm-user-line1{
    display:flex; align-items:center; gap:8px; min-width:0; line-height:1.1;
    margin-bottom:4px;
}
.ccm-username{
    color:#fff; font-weight:700; font-size:14px; cursor:pointer;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    text-shadow:0 1px 3px rgba(0,0,0,.5); line-height:1.1;
}
.ccm-nickname{
    color:rgba(255,255,255,.5); font-size:11.5px; font-weight:500;
    margin:0; line-height:1.1;
}

.ccm-info-switcher{ position:relative; min-height:14px; }
.ccm-switch-pane{
    position:absolute; left:0; top:0; right:0;
    opacity:0; pointer-events:none;
    transition:opacity .35s ease;
}
.ccm-switch-pane.active{ position:relative; opacity:1; pointer-events:all; }

.ccm-audio-tag{ display:flex; align-items:center; gap:7px; max-width:100%; }
.ccm-audio-icon{ display:inline-flex; align-items:flex-end; gap:2px; height:11px; flex-shrink:0; }
.ccm-eq-bar{
    width:2.5px; border-radius:2px;
    background:rgba(255,255,255,.75);
    display:inline-block; transform-origin:bottom;
    animation:ccmEqBounce 0.9s ease-in-out infinite;
}
.ccm-eq-bar:nth-child(1){ height:11px; animation-delay:-0.6s; }
.ccm-eq-bar:nth-child(2){ height:11px; animation-delay:-0.2s; }
.ccm-eq-bar:nth-child(3){ height:11px; animation-delay:-0.9s; }
@keyframes ccmEqBounce{
    0%   { transform:scaleY(0.35); }
    25%  { transform:scaleY(1); }
    50%  { transform:scaleY(0.5); }
    75%  { transform:scaleY(0.85); }
    100% { transform:scaleY(0.35); }
}

.ccm-audio-track{
    position:relative; overflow:hidden;
    max-width:230px; flex:0 1 auto; display:flex; align-items:center;
}
.ccm-audio-track-text{
    display:inline-block; white-space:nowrap;
    font-size:12.5px; font-weight:500; line-height:1;
    color:rgba(255,255,255,.7);
}
.ccm-audio-track-text.ccm-marquee{
    animation: ccmAudioMarquee var(--marquee-dur,6s) ease-in-out infinite alternate;
}
@keyframes ccmAudioMarquee{
    0%   { transform:translateX(0); }
    12%  { transform:translateX(0); }
    88%  { transform:translateX(var(--marquee-dist,0px)); }
    100% { transform:translateX(var(--marquee-dist,0px)); }
}

.ccm-social-proof-strip{
    position:absolute; left:16px; bottom:175px; z-index:6;
    display:flex; flex-direction:row-reverse; gap:8px;
    pointer-events:none;
    transition:opacity .22s ease, transform .22s ease;
    opacity:1;
}
.ccm-social-proof-strip.ccm-sp-hidden{ opacity:0; transform:translateY(10px); }
.ccm-sp-avatar-wrap{ position:relative; pointer-events:all; display:inline-block; text-decoration:none; }
.ccm-sp-avatar-wrap:nth-child(1){ animation:ccmSpFloat 3s ease-in-out infinite; }
.ccm-sp-avatar-wrap:nth-child(2){ animation:ccmSpFloat 3s ease-in-out infinite .4s; }
.ccm-sp-avatar-wrap:nth-child(3){ animation:ccmSpFloat 3s ease-in-out infinite .8s; }
.ccm-sp-avatar-wrap:nth-child(4){ animation:ccmSpFloat 3s ease-in-out infinite 1.2s; }
@keyframes ccmSpFloat{
    0%,100%{ transform:translateY(0); }
    50%{ transform:translateY(-6px); }
}
.ccm-sp-avatar{
    width:44px; height:44px; border-radius:50%; object-fit:cover;
    border:2.5px solid #ff2b2b; display:block;
    box-shadow:0 4px 12px rgba(0,0,0,.7);
}
.ccm-sp-badge{
    position:absolute; bottom:-2px; right:-2px;
    width:16px; height:16px; border-radius:50%;
    display:flex; align-items:center; justify-content:center;
    z-index:2;
}
.ccm-sp-badge i{ font-size:8px; color:#fff; line-height:1; }

.ccm-track-btn{
    padding:0; margin:0; border-radius:0;
    border:none; background:transparent;
    color:#ff2b2b; font-size:13px; font-weight:700;
    cursor:pointer; flex-shrink:0; font-family:Poppins,sans-serif;
    text-decoration:underline; text-decoration-color:#ff2b2b;
    text-underline-offset:2px; text-decoration-thickness:2.5px;
    transition:opacity .2s, transform .15s;
    -webkit-tap-highlight-color:transparent;
    position:relative; top:-2px;
}
.ccm-track-btn.following{
    background:transparent; border:none; color:#ff2b2b;
    text-decoration:underline; text-decoration-color:#ff2b2b;
}
.ccm-track-btn:active{ transform:scale(.94); opacity:.7; }

.ccm-user-dot{ color:#fff; font-size:15px; flex-shrink:0; }

.ccm-partnership-tag{
    display:inline-flex;align-items:center;gap:6px;
    margin-top:8px;
    padding:5px 10px;
    border-radius:8px;
    background:rgba(0,0,0,0.55);
    color:#ff4c4c;
    font-size:11px;
    font-weight:600;
    max-width:100%;
    backdrop-filter:blur(6px);
    -webkit-backdrop-filter:blur(6px);
}
.ccm-partnership-tag i{ font-size:13px; flex-shrink:0; }
.ccm-partnership-tag strong{ color:#fff; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

.ccm-caption-wrap{ max-width:100%; }
.ccm-caption-wrap.ccm-expanded{
    max-height:34vh; overflow-y:auto;
    -webkit-overflow-scrolling:touch;
    touch-action:pan-y;
    scrollbar-width:none; -ms-overflow-style:none;
    padding-right:2px;
}
.ccm-caption-wrap.ccm-expanded::-webkit-scrollbar{ display:none; }

.ccm-caption-text{
    color:#fff; font-size:13px; line-height:1.5;
    word-break:break-word; text-shadow:0 1px 2px rgba(0,0,0,.35);
    display:inline;
}
.ccm-more-toggle{
    color:#ff2b2b; font-size:inherit; font-weight:600; line-height:inherit;
    cursor:pointer; display:inline;
    -webkit-tap-highlight-color:transparent;
}
.ccm-ellipsis{ color:#fff; font-weight:500; }

.ccm-meta-row{
    display:flex; align-items:center; gap:6px; margin-top:8px;
    color:rgba(255,255,255,.55); font-size:11px;
}
.ccm-meta-row i{ font-size:11px; }
.ccm-new-tag{ color:#ff2b2b; font-weight:700; }

#ccm-nav-hint{
    position:absolute; bottom:8px; left:50%; transform:translateX(-50%);
    color:rgba(255,255,255,.28); font-size:10px; z-index:20;
    pointer-events:none; white-space:nowrap;
}

#ccm-arrows{
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    gap:14px; margin-left:16px; flex-shrink:0;
}
#ccm-arrows button{
    background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.14);
    border-radius:50%; width:44px; height:44px;
    display:flex; align-items:center; justify-content:center;
    cursor:pointer; transition:background .2s ease, transform .15s ease;
    -webkit-tap-highlight-color:transparent;
}
#ccm-arrows button:hover{ background:rgba(255,255,255,.18); }
#ccm-arrows button:active{ transform:scale(.9); }
@media(max-width:790px){ #ccm-arrows{ display:none; } }

.ccm-ad-inline-tag{
    display:flex; align-items:center; gap:4px;
    font-size:10px; font-weight:800; letter-spacing:.5px; color:#fff;
    border:none; border-radius:6px; padding:5px 9px 5px 7px;
    text-transform:uppercase; background:rgba(22,22,24,.82); flex-shrink:0;
}
.ccm-ad-inline-tag i{ font-size:12px; }

.ccm-visit-brand-btn{
    display:flex; align-items:center; justify-content:center; gap:6px;
    position:absolute; left:16px; right:16px; bottom:16px; z-index:9;
    width:auto; margin:0; padding:11px 0;
    border-radius:16px; color:#fff; font-weight:700; font-size:14px;
    text-decoration:none; font-family:Poppins,sans-serif;
    transition:background .2s;
}
.ccm-vb-switcher{
    position:relative; display:inline-flex; align-items:center; justify-content:center;
    min-width:118px; min-height:18px;
}
.ccm-vb-pane{
    position:absolute; left:0; right:0; top:50%; transform:translateY(-50%);
    display:flex; align-items:center; justify-content:center; gap:6px;
    opacity:0; pointer-events:none; white-space:nowrap;
    transition:opacity .35s ease;
}
.ccm-vb-pane.active{ position:relative; transform:none; top:0; opacity:1; pointer-events:all; }
.ccm-visit-brand-red{ background:rgba(224,68,74,.9); border:1px solid rgba(224,68,74,1); }
.ccm-visit-brand-red:hover{ background:rgba(224,68,74,1); }
.ccm-visit-brand-blue{ background:rgba(56,108,235,.9); border:1px solid rgba(56,108,235,1); }
.ccm-visit-brand-blue:hover{ background:rgba(56,108,235,1); }
.ccm-visit-brand-orange{ background:rgba(211,127,53,.9); border:1px solid rgba(211,127,53,1); }
.ccm-visit-brand-orange:hover{ background:rgba(211,127,53,1); }

/* ══════════════════════════════════════════════════════════
   SUGGESTED USERS — full-bleed carousel, blurred true 4-way split
   photo backdrop (2 top / 2 bottom) that tracks the CURRENT page,
   paged 2x2 card grid, red dot pager that scales to actual user
   count (no padded/duplicated users), header restored, desktop
   hover arrows + keyboard left/right support.
   ══════════════════════════════════════════════════════════ */
.ccm-sugg-slide{
    position:relative; width:100%; height:100%;
    overflow:hidden; background:#000;
}

/* True 4-way split — 2 tiles stacked on top, 2 tiles stacked on
   bottom, each tile a distinct profile photo from the CURRENT page.
   Grid (not two flat halves) keeps left/right separation too. */
.ccm-sugg-bg{
    position:absolute; inset:0;
    display:grid; grid-template-columns:1fr 1fr; grid-template-rows:1fr 1fr;
    z-index:0;
}
.ccm-sugg-bg-tile{
    background-size:cover; background-position:center;
    background-color:#141418;
    filter:blur(26px) brightness(.42) saturate(1.15);
    transform:scale(1.2);
    transition:background-image .4s ease;
}
.ccm-sugg-overlay{
    position:absolute; inset:0; z-index:1;
    background:rgba(0,0,0,.3);
}

/* ── Header — restored, pinned over the backdrop like the other
   slide top bars so it reads consistently across the modal ── */
.ccm-sugg-header{
    position:absolute; top:0; left:0; right:0; z-index:5;
    padding:12px 14px 30px 6px;
    background:linear-gradient(to bottom, rgba(0,0,0,.7) 0%, rgba(0,0,0,.3) 65%, transparent 100%);
    display:flex; align-items:center; gap:8px;
}
.ccm-sugg-header-text{ flex:1; min-width:0; padding-left:2px; }
.ccm-sugg-title{ font-size:15px; font-weight:800; color:#fff; font-family:Poppins,sans-serif; }
.ccm-sugg-subtitle{ font-size:11.5px; color:rgba(255,255,255,.55); margin-top:2px; }
.ccm-sugg-header-spacer{ width:30px; flex-shrink:0; }

.ccm-sugg-pages{
    position:relative; z-index:2;
    width:100%; height:100%;
    display:flex;
    transition:transform .32s cubic-bezier(.22,1,.36,1);
}
.ccm-sugg-page{
    flex:0 0 100%; width:100%; height:100%;
    display:flex; align-items:center; justify-content:center;
    box-sizing:border-box;
    padding:78px 14px 58px;
}
.ccm-sugg-grid2{
    width:100%; height:100%;
    display:grid; grid-template-columns:1fr 1fr; grid-template-rows:1fr 1fr;
    gap:10px;
}

.ccm-sugg-card{
    position:relative;
    border-radius:20px; overflow:hidden;
    background:rgba(255,255,255,.08);
    border:1px solid rgba(255,255,255,.14);
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    gap:8px; padding:12px 8px;
    backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    transition:opacity .3s ease, transform .3s ease;
}
.ccm-sugg-card.dismissed{ opacity:0; transform:scale(.9); pointer-events:none; }

.ccm-sugg-close{
    position:absolute; top:8px; right:8px; z-index:3;
    width:24px; height:24px; border-radius:50%;
    background:rgba(0,0,0,.45); border:none; color:#fff;
    display:flex; align-items:center; justify-content:center;
    cursor:pointer; font-size:14px; padding:0;
    -webkit-tap-highlight-color:transparent;
}

.ccm-sugg-card-link{
    display:flex; flex-direction:column; align-items:center; gap:8px;
    text-decoration:none; color:inherit; width:100%;
}
.ccm-sugg-card img{
    width:60px; height:60px; border-radius:50%; object-fit:cover;
    border:2.5px solid #ff2b2b; display:block;
    box-shadow:0 4px 14px rgba(0,0,0,.4);
}
.ccm-sugg-card-name{
    font-size:13px; font-weight:700; color:#fff;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:100%;
}
.ccm-sugg-card-meta{
    font-size:10.5px; font-weight:500; color:rgba(255,255,255,.6);
}

/* ── Track/Untrack — restored to the original red-outline pill
   style used everywhere else, instead of the flat white button ── */
.ccm-sugg-card-track{
    width:86%; margin-top:1px;
    font-size:11px; font-weight:700; padding:7px 0;
    border-radius:9px; border:1.5px solid #ff2b2b;
    background:transparent; color:#ff2b2b;
    cursor:pointer; transition:all .2s;
    font-family:Poppins,sans-serif;
    -webkit-tap-highlight-color:transparent;
}
.ccm-sugg-card-track:hover{ background:rgba(255,43,43,.12); }
.ccm-sugg-card-track.tracking{
    background:#ff2b2b; color:#fff; border-color:#ff2b2b;
}

.ccm-sugg-dots{
    position:absolute; left:0; right:0; bottom:16px; z-index:3;
    display:flex; align-items:center; justify-content:center; gap:8px;
}
.ccm-sugg-dot{
    width:7px; height:7px; border-radius:50%;
    background:rgba(255,255,255,.35);
    cursor:pointer; transition:all .2s ease;
    -webkit-tap-highlight-color:transparent;
}
.ccm-sugg-dot.active{ background:#ff2b2b; width:18px; border-radius:4px; }

/* ── Desktop-only hover arrows — mirrors the multimedia gallery
   arrows exactly (hidden on touch devices via hover:none) ── */
.ccm-sugg-arrow{
    position:absolute; top:45%; transform:translateY(-50%);
    width:40px; height:40px; border-radius:50%;
    background:rgba(0,0,0,.4);
    display:flex; align-items:center; justify-content:center;
    color:#fff; cursor:pointer; z-index:4;
    opacity:0; transition:opacity .2s ease;
    -webkit-tap-highlight-color:transparent;
}
.ccm-sugg-slide:hover .ccm-sugg-arrow{ opacity:1; }
.ccm-sugg-arrow.ccm-sugg-arrow-disabled{ opacity:0 !important; pointer-events:none; }
.ccm-sugg-arrow-left{ left:16px; }
.ccm-sugg-arrow-right{ right:16px; }
.ccm-sugg-arrow i{ font-size:22px; }

.ccm-saved-toast{
    position:fixed;
    left:6px;right:6px;
    bottom:calc(30px + env(safe-area-inset-bottom)); /* sits above bottom nav bar */
    background:rgba(12,12,28,0.98);
    backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);
    border:1px solid rgba(255,255,255,0.08);
    border-radius:8px;
    padding:13px 16px;
    display:flex;align-items:center;justify-content:space-between;
    gap:12px;
    z-index:999999998;
    font-family:Poppins,sans-serif;
    transform:translateY(120%);
    opacity:0;
    transition:transform .28s cubic-bezier(.32,.72,0,1), opacity .28s ease;
    box-shadow:0 8px 24px rgba(0,0,0,0.35);
}
.ccm-saved-toast.show{
    transform:translateY(0);
    opacity:1;
}
.ccm-saved-toast-left{
    display:flex;align-items:center;gap:10px;
    color:#fff;font-size:14.5px;font-weight:600;
}
.ccm-saved-toast-check{
    width:20px;height:20px;border-radius:50%;
    background:#f24455;color:#fff;
    display:flex;align-items:center;justify-content:center;
    font-size:12px;flex-shrink:0;
}
.ccm-saved-toast-right{
    display:flex;align-items:center;gap:2px;
    color:#f24455;font-size:14.5px;font-weight:600;
    cursor:pointer;-webkit-tap-highlight-color:transparent;
    flex-shrink:0;
    transition:opacity .15s ease;
}
.ccm-saved-toast-right:active{ opacity:0.7; }
.ccm-saved-toast-right i{ font-size:17px; }

/* 790px — TABLET */
@media (max-width:790px){
    .ccm-saved-toast{
        left:5px;right:5px;
        bottom:calc(26px + env(safe-area-inset-bottom));
        border-radius:7px;
        padding:12px 15px;
    }
    .ccm-saved-toast-left{ font-size:14px;gap:9px; }
    .ccm-saved-toast-check{ width:19px;height:19px;font-size:11.5px; }
    .ccm-saved-toast-right{ font-size:14px; }
    .ccm-saved-toast-right i{ font-size:16px; }
}

/* 490px — LARGE MOBILE */
@media (max-width:490px){
    .ccm-saved-toast{
        left:4px;right:4px;
        bottom:calc(20px + env(safe-area-inset-bottom));
        border-radius:6px;
        padding:11px 14px;
        backdrop-filter:blur(16px);
        -webkit-backdrop-filter:blur(16px);
    }
    .ccm-saved-toast-left{ font-size:13.5px;gap:8px; }
    .ccm-saved-toast-check{ width:18px;height:18px;font-size:11px; }
    .ccm-saved-toast-right{ font-size:13.5px;gap:1px; }
    .ccm-saved-toast-right i{ font-size:15.5px; }
}

/* 390px — STANDARD MOBILE */
@media (max-width:390px){
    .ccm-saved-toast{
        left:3px;right:3px;
        bottom:calc(14px + env(safe-area-inset-bottom));
        border-radius:5px;
        padding:10px 13px;
        backdrop-filter:blur(14px);
        -webkit-backdrop-filter:blur(14px);
        box-shadow:0 6px 20px rgba(0,0,0,0.3);
    }
    .ccm-saved-toast-left{ font-size:12.5px;gap:7px; }
    .ccm-saved-toast-check{ width:17px;height:17px;font-size:10px; }
    .ccm-saved-toast-right{ font-size:12.5px; }
    .ccm-saved-toast-right i{ font-size:14.5px; }
}

@media (hover:none){ .ccm-sugg-arrow{ display:none !important; } }

/* ══════════════════════════════════════
   RESPONSIVE BREAKPOINTS — 790 / 490 / 390 / 330
   ══════════════════════════════════════ */

@media (max-width:790px){
    .ccm-icon-btn i{ font-size:28px; }
    .ccm-search-btn i{ font-size:22px; }
    .ccm-back-btn i{ font-size:34px; }
    .ccm-brand-label{ font-size:15px; }
    .ccm-brand-date{ font-size:11.5px; }
    .ccm-group-tag{ top:73px; left:14px; font-size:10.5px; padding:4px 7px 4px 5px; }
    .ccm-group-tag i{ font-size:12px; }
    .ccm-top-right-tags{ top:73px; right:13px; gap:5px; }
    .ccm-type-tag{ padding:5px 8px 5px 6px; }
    .ccm-type-tag i{ font-size:12px; }
    .ccm-type-tag span{ font-size:9.5px; }
    .ccm-media-counter{ font-size:9.5px; padding:4.5px 8px; }
    .ccm-rail{ right:14px; bottom:24px; gap:23px; }
    .ccm-rail.ccm-rail-raised{ bottom:36px; }
    .ccm-rail-btn i{ font-size:25px; }
    #ccm-share-btn i{ font-size:24px; }
    .ccm-rail-msg-pic{ width:28px; height:28px; border-radius:9px; }
    .ccm-rail-count{ font-size:12px; }
    .ccm-bottom{ right:0; padding:16px 70px 20px 14px; }
    .ccm-bottom.ccm-no-rail{ padding-right:14px; }
    .ccm-social-proof-strip{ left:14px; bottom:162px; }
    .ccm-sp-avatar{ width:40px; height:40px; }
    .ccm-user-pic{ width:44px; height:44px; }
    .ccm-nickname{ font-size:11px; }
    .ccm-username{ font-size:13.5px; }
    .ccm-track-btn{ padding:0; font-size:12px; text-decoration-thickness:2.5px; top:-2px; }
    .ccm-caption-text{ font-size:12px; }
    .ccm-partnership-tag{ font-size:10.5px; padding:4.5px 9px; margin-top:7px; gap:5px; }
    .ccm-partnership-tag i{ font-size:12.5px; }
    .ccm-audio-icon{ height:10px; }
    .ccm-eq-bar:nth-child(1){ height:4.5px; }
    .ccm-eq-bar:nth-child(2){ height:10px; }
    .ccm-eq-bar:nth-child(3){ height:6.5px; }
    .ccm-audio-track{ max-width:210px; }
    .ccm-audio-track-text{ font-size:10.8px; }
    .ccm-center-caption-wrap{ top:112px; left:14px; right:62px; bottom:210px; }
    .ccm-center-caption-text{ font-size:15px; max-width:380px; transform:translateX(12px); }
    .ccm-paused-stack{ gap:12px; }
    .ccm-paused-row{ gap:18px; }
    .ccm-pause-icon{ width:54px; height:54px; }
    .ccm-pause-icon i{ font-size:26px; }
    .ccm-mute-btn{ width:48px; height:48px; }
    .ccm-mute-btn i{ font-size:20px; }
    .ccm-seek-btn{ width:36px; height:36px; }
    .ccm-seek-btn i{ font-size:16px; }
    .ccm-skel-avatar{ width:44px; height:44px; }
    .ccm-skel-line{ left:68px; }
    .ccm-skel-line1{ bottom:50px; width:120px; }
    .ccm-skel-line2{ bottom:31px; width:175px; }
    .ccm-media-arrow-left{ left:48px; }
    .ccm-media-arrow-right{ right:56px; }
    .ccm-media-arrow{ width:32px; height:32px; }
    .ccm-media-arrow i{ font-size:18px; }
    .ccm-media-dashes{ gap:5px; margin-left:-14px; margin-right:-70px; width:calc(100% + 84px); }
    .ccm-double-tap-heart i{ font-size:80px; }
    .ccm-sugg-header{ padding:11px 12px 26px 5px; }
    .ccm-sugg-title{ font-size:14px; }
    .ccm-sugg-subtitle{ font-size:11px; }
    .ccm-sugg-page{ padding:70px 12px 52px; }
    .ccm-sugg-grid2{ gap:9px; }
    .ccm-sugg-card{ padding:11px 7px; border-radius:18px; gap:7px; }
    .ccm-sugg-card img{ width:54px; height:54px; }
    .ccm-sugg-card-name{ font-size:12px; }
    .ccm-sugg-card-meta{ font-size:10px; }
    .ccm-sugg-card-track{ font-size:10.5px; padding:6.5px 0; }
    .ccm-sugg-close{ width:22px; height:22px; font-size:13px; }
    .ccm-sugg-dots{ bottom:14px; }
    .ccm-sugg-arrow{ width:36px; height:36px; }
    .ccm-sugg-arrow i{ font-size:20px; }
}

@media (max-width:490px){
    .ccm-icon-btn i{ font-size:26px; }
    .ccm-search-btn i{ font-size:20px; }
    .ccm-back-btn i{ font-size:32px; }
    .ccm-brand-label{ font-size:14px; }
    .ccm-brand-date{ font-size:11px; }
    .ccm-group-tag{ top:66px; left:12px; font-size:10px; padding:3.5px 6.5px 3.5px 5px; max-width:calc(100% - 76px); }
    .ccm-group-tag i{ font-size:11px; }
    .ccm-top-right-tags{ top:66px; right:12px; gap:5px; }
    .ccm-type-tag{ padding:4.5px 7px 4.5px 5px; }
    .ccm-type-tag i{ font-size:11px; }
    .ccm-type-tag span{ font-size:9px; }
    .ccm-media-counter{ font-size:9px; padding:4px 7px; }
    .ccm-rail{ right:13px; bottom:22px; gap:20px; }
    .ccm-rail.ccm-rail-raised{ bottom:34px; }
    .ccm-rail-btn i{ font-size:23px; }
    #ccm-share-btn i{ font-size:22px; }
    .ccm-rail-msg-pic{ width:26px; height:26px; border-radius:8px; border-width:1.5px; }
    .ccm-rail-count{ font-size:11.5px; }
    .ccm-bottom{ right:0; padding:13px 62px 18px 12px; }
    .ccm-bottom.ccm-no-rail{ padding-right:12px; }
    .ccm-social-proof-strip{ left:12px; bottom:140px; }
    .ccm-sp-avatar{ width:37px; height:37px; border-width:2px; }
    .ccm-user-pic{ width:41px; height:41px; border-width:2px; }
    .ccm-nickname{ font-size:10.5px; }
    .ccm-username{ font-size:13px; }
    .ccm-track-btn{ padding:0; font-size:11.5px; border-radius:0; text-decoration-thickness:2.25px; top:-1.5px; }
    .ccm-caption-text{ font-size:11.5px; }
    .ccm-partnership-tag{ font-size:10px; padding:4px 8px; margin-top:6px; gap:5px; }
    .ccm-partnership-tag i{ font-size:12px; }
    .ccm-audio-icon{ height:9px; gap:1.5px; }
    .ccm-eq-bar:nth-child(1){ height:4px; }
    .ccm-eq-bar:nth-child(2){ height:9px; }
    .ccm-eq-bar:nth-child(3){ height:6px; }
    .ccm-audio-track{ max-width:180px; }
    .ccm-audio-track-text{ font-size:10.6px; }
    .ccm-meta-row{ font-size:10px; margin-top:6px; }
    .ccm-top-bar{ padding:11px 10px 28px 3px; }
    #ccm-nav-hint{ display:none; }
    .ccm-center-caption-wrap{ top:104px; left:12px; right:54px; bottom:185px; }
    .ccm-center-caption-text{ font-size:14px; max-width:340px; transform:translateX(10px); }
    .ccm-paused-stack{ gap:11px; }
    .ccm-paused-row{ gap:16px; }
    .ccm-pause-icon{ width:50px; height:50px; }
    .ccm-pause-icon i{ font-size:24px; }
    .ccm-mute-btn{ width:44px; height:44px; }
    .ccm-mute-btn i{ font-size:19px; }
    .ccm-seek-btn{ width:33px; height:33px; }
    .ccm-seek-btn i{ font-size:15px; }
    .ccm-skel-avatar{ width:41px; height:41px; left:12px; }
    .ccm-skel-line{ left:63px; }
    .ccm-skel-line1{ bottom:47px; width:110px; }
    .ccm-skel-line2{ bottom:29px; width:160px; }
    .ccm-skel-rail{ width:23px; height:23px; right:13px; }
    .ccm-media-arrow-left{ left:42px; }
    .ccm-media-arrow-right{ right:50px; }
    .ccm-media-arrow{ width:30px; height:30px; }
    .ccm-media-arrow i{ font-size:16px; }
    .ccm-media-dashes{ gap:5px; margin-left:-12px; margin-right:-62px; width:calc(100% + 74px); }
    .ccm-double-tap-heart i{ font-size:70px; }
    .ccm-sugg-header{ padding:10px 10px 22px 4px; }
    .ccm-sugg-title{ font-size:13.5px; }
    .ccm-sugg-subtitle{ font-size:10.5px; }
    .ccm-sugg-page{ padding:60px 10px 46px; }
    .ccm-sugg-grid2{ gap:8px; }
    .ccm-sugg-card{ padding:10px 6px; border-radius:16px; gap:6px; }
    .ccm-sugg-card img{ width:48px; height:48px; }
    .ccm-sugg-card-name{ font-size:11.5px; }
    .ccm-sugg-card-meta{ font-size:9.5px; }
    .ccm-sugg-card-track{ font-size:10px; padding:6px 0; }
    .ccm-sugg-close{ width:20px; height:20px; font-size:12px; top:6px; right:6px; }
    .ccm-sugg-dots{ bottom:12px; }
    .ccm-sugg-arrow{ width:32px; height:32px; }
    .ccm-sugg-arrow i{ font-size:18px; }
}

@media (max-width:390px){
    .ccm-icon-btn i{ font-size:24px; }
    .ccm-search-btn i{ font-size:19px; }
    .ccm-back-btn i{ font-size:30px; }
    .ccm-brand-label{ font-size:13px; }
    .ccm-brand-date{ font-size:10.5px; }
    .ccm-group-tag{ top:60px; left:10px; font-size:9.5px; padding:3px 6px 3px 4.5px; max-width:calc(100% - 66px); }
    .ccm-group-tag i{ font-size:10.5px; }
    .ccm-top-right-tags{ top:60px; right:10px; gap:4px; }
    .ccm-type-tag{ padding:4px 6.5px 4px 5px; }
    .ccm-type-tag i{ font-size:10px; }
    .ccm-type-tag span{ font-size:8.5px; }
    .ccm-media-counter{ font-size:8.5px; padding:3.5px 6px; }
    .ccm-rail{ right:12px; bottom:20px; gap:18px; }
    .ccm-rail.ccm-rail-raised{ bottom:31px; }
    .ccm-rail-btn i{ font-size:21px; }
    #ccm-share-btn i{ font-size:20px; }
    .ccm-rail-msg-pic{ width:24px; height:24px; border-radius:7px; border-width:1.5px; }
    .ccm-rail-count{ font-size:11px; }
    .ccm-bottom{ right:0; padding:11px 56px 16px 10px; }
    .ccm-bottom.ccm-no-rail{ padding-right:10px; }
    .ccm-social-proof-strip{ left:10px; bottom:125px; }
    .ccm-sp-avatar{ width:35px; height:35px; border-width:2px; }
    .ccm-user-pic{ width:39px; height:39px; border-width:2px; }
    .ccm-nickname{ font-size:10px; }
    .ccm-username{ font-size:12.5px; }
    .ccm-track-btn{ padding:0; font-size:11px; text-decoration-thickness:2px; top:-1.5px; }
    .ccm-caption-text{ font-size:11px; line-height:1.45; }
    .ccm-partnership-tag{ font-size:9.5px; padding:3.5px 7px; margin-top:5px; gap:4px; }
    .ccm-partnership-tag i{ font-size:11px; }
    .ccm-audio-icon{ height:8.5px; gap:1.5px; }
    .ccm-eq-bar:nth-child(1){ height:3.5px; }
    .ccm-eq-bar:nth-child(2){ height:8.5px; }
    .ccm-eq-bar:nth-child(3){ height:5.5px; }
    .ccm-audio-track{ max-width:150px; }
    .ccm-audio-track-text{ font-size:9.6px; }
    .ccm-user-row{ gap:8px; margin-bottom:4px; }
    .ccm-center-caption-wrap{ top:96px; left:10px; right:48px; bottom:170px; }
    .ccm-center-caption-text{ font-size:13px; max-width:300px; transform:translateX(8px); }
    .ccm-paused-stack{ gap:10px; }
    .ccm-paused-row{ gap:14px; }
    .ccm-pause-icon{ width:46px; height:46px; }
    .ccm-pause-icon i{ font-size:22px; }
    .ccm-mute-btn{ width:40px; height:40px; }
    .ccm-mute-btn i{ font-size:18px; }
    .ccm-seek-btn{ width:30px; height:30px; }
    .ccm-seek-btn i{ font-size:14px; }
    .ccm-skel-avatar{ width:39px; height:39px; left:10px; }
    .ccm-skel-line{ left:59px; }
    .ccm-skel-line1{ bottom:44px; width:100px; }
    .ccm-skel-line2{ bottom:27px; width:145px; }
    .ccm-skel-rail{ width:21px; height:21px; right:12px; }
    .ccm-media-arrow-left{ left:38px; }
    .ccm-media-arrow-right{ right:46px; }
    .ccm-media-arrow{ width:28px; height:28px; }
    .ccm-media-arrow i{ font-size:15px; }
    .ccm-media-dashes{ margin-left:-10px; margin-right:-56px; width:calc(100% + 66px); }
    .ccm-double-tap-heart i{ font-size:62px; }
    .ccm-sugg-header{ padding:9px 9px 18px 4px; }
    .ccm-sugg-title{ font-size:13px; }
    .ccm-sugg-subtitle{ font-size:10px; }
    .ccm-sugg-page{ padding:52px 9px 42px; }
    .ccm-sugg-grid2{ gap:7px; }
    .ccm-sugg-card{ padding:9px 5px; border-radius:15px; gap:5px; }
    .ccm-sugg-card img{ width:44px; height:44px; border-width:2px; }
    .ccm-sugg-card-name{ font-size:11px; }
    .ccm-sugg-card-meta{ font-size:9px; }
    .ccm-sugg-card-track{ font-size:9.5px; padding:5.5px 0; }
    .ccm-sugg-close{ width:19px; height:19px; font-size:11px; top:6px; right:6px; }
    .ccm-sugg-dots{ bottom:11px; gap:6px; }
    .ccm-sugg-arrow{ width:30px; height:30px; }
    .ccm-sugg-arrow i{ font-size:16px; }
}

@media (max-width:330px){
    .ccm-icon-btn i{ font-size:22px; }
    .ccm-search-btn i{ font-size:18px; }
    .ccm-back-btn i{ font-size:28px; }
    .ccm-brand-label{ font-size:12px; }
    .ccm-brand-date{ font-size:10px; }
    .ccm-group-tag{ top:56px; left:9px; font-size:9px; padding:3px 5.5px 3px 4px; max-width:calc(100% - 60px); }
    .ccm-group-tag i{ font-size:10px; }
    .ccm-top-right-tags{ top:56px; right:9px; gap:4px; }
    .ccm-type-tag{ padding:3.5px 6px 3.5px 4.5px; }
    .ccm-type-tag i{ font-size:9px; }
    .ccm-type-tag span{ font-size:8px; }
    .ccm-media-counter{ font-size:8px; padding:3px 5.5px; }
    .ccm-rail{ right:11px; bottom:18px; gap:15px; }
    .ccm-rail.ccm-rail-raised{ bottom:28px; }
    .ccm-rail-btn i{ font-size:19px; }
    #ccm-share-btn i{ font-size:18px; }
    .ccm-rail-msg-pic{ width:22px; height:22px; border-radius:6px; border-width:1.5px; }
    .ccm-rail-count{ font-size:10.5px; }
    .ccm-bottom{ right:0; padding:10px 50px 14px 9px; }
    .ccm-bottom.ccm-no-rail{ padding-right:9px; }
    .ccm-social-proof-strip{ left:9px; bottom:110px; }
    .ccm-sp-avatar{ width:32px; height:32px; border-width:1.5px; }
    .ccm-user-pic{ width:35px; height:35px; border-width:1.5px; }
    .ccm-nickname{ font-size:9.5px; }
    .ccm-username{ font-size:11.5px; }
    .ccm-track-btn{ padding:0; font-size:10.5px; border-radius:0; text-decoration-thickness:2px; top:-1px; }
    .ccm-caption-text{ font-size:10.5px; line-height:1.4; }
    .ccm-partnership-tag{ font-size:9px; padding:3px 6px; margin-top:4px; gap:4px; border-radius:7px; }
    .ccm-partnership-tag i{ font-size:10px; }
    .ccm-audio-icon{ height:8px; gap:1.5px; }
    .ccm-eq-bar:nth-child(1){ height:3px; }
    .ccm-eq-bar:nth-child(2){ height:8px; }
    .ccm-eq-bar:nth-child(3){ height:5px; }
    .ccm-audio-track{ max-width:130px; }
    .ccm-audio-track-text{ font-size:9px; }
    .ccm-top-bar{ padding:9px 8px 24px 3px; }
    .ccm-center-caption-wrap{ top:90px; left:9px; right:44px; bottom:150px; }
    .ccm-center-caption-text{ font-size:12px; max-width:260px; transform:translateX(6px); }
    .ccm-paused-stack{ gap:9px; }
    .ccm-paused-row{ gap:12px; }
    .ccm-pause-icon{ width:42px; height:42px; }
    .ccm-pause-icon i{ font-size:20px; }
    .ccm-mute-btn{ width:37px; height:37px; }
    .ccm-mute-btn i{ font-size:17px; }
    .ccm-seek-btn{ width:27px; height:27px; }
    .ccm-seek-btn i{ font-size:13px; }
    .ccm-skel-avatar{ width:35px; height:35px; left:9px; }
    .ccm-skel-line{ left:53px; }
    .ccm-skel-line1{ bottom:41px; width:90px; }
    .ccm-skel-line2{ bottom:25px; width:130px; }
    .ccm-skel-rail{ width:19px; height:19px; right:11px; }
    .ccm-media-arrow-left{ left:34px; }
    .ccm-media-arrow-right{ right:42px; }
    .ccm-media-arrow{ width:26px; height:26px; }
    .ccm-media-arrow i{ font-size:14px; }
    .ccm-media-dashes{ margin-left:-9px; margin-right:-50px; width:calc(100% + 59px); }
    .ccm-double-tap-heart i{ font-size:54px; }
    .ccm-sugg-header{ padding:8px 8px 16px 4px; }
    .ccm-sugg-title{ font-size:12.5px; }
    .ccm-sugg-subtitle{ font-size:9.5px; }
    .ccm-sugg-page{ padding:46px 8px 38px; }
    .ccm-sugg-grid2{ gap:6px; }
    .ccm-sugg-card{ padding:8px 4px; border-radius:13px; gap:5px; }
    .ccm-sugg-card img{ width:40px; height:40px; border-width:1.5px; }
    .ccm-sugg-card-name{ font-size:10px; }
    .ccm-sugg-card-meta{ font-size:8.5px; }
    .ccm-sugg-card-track{ font-size:9px; padding:5.5px 0; }
    .ccm-sugg-close{ width:18px; height:18px; font-size:10px; top:5px; right:5px; }
    .ccm-sugg-dots{ bottom:9px; gap:5px; }
    .ccm-sugg-arrow{ width:28px; height:28px; }
    .ccm-sugg-arrow i{ font-size:15px; }
}

/* ── AD FROM TAG (mini card + modal) ── */
.ad-from-tag{
    display:inline-flex;align-items:center;gap:6px;
    background:rgba(40,40,46,0.85);
    border-radius:20px;
    padding:5px 10px 5px 5px;
    margin-bottom:8px;
    text-decoration:none;
    max-width:100%;
    -webkit-tap-highlight-color:transparent;
}
.ad-from-avatar{
    width:22px;height:22px;border-radius:50%;object-fit:cover;
    border:2px solid #ff2b2b;flex-shrink:0;display:block;
}
.ad-from-label{ color:rgba(255,255,255,0.6); font-size:11px; font-weight:500; white-space:nowrap; }
.ad-from-name{ color:#fff; font-size:12px; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

.brand-logo{
    width:36px;
    height:36px;
    border-radius:10px;
    border:2px solid red;
    object-fit:cover;
    flex-shrink:0;
    display:block;
}

.ad-actions-row{
    display:flex;
    align-items:center;
    gap:14px;
    margin-top:10px;
    margin-bottom:10px;
    padding-top:8px;
    border-top:1px solid rgba(255,255,255,0.08);
}
.ad-action-btn{
    display:flex;
    align-items:center;
    gap:5px;
    background:none;
    border:none;
    cursor:pointer;
    -webkit-tap-highlight-color:transparent;
    padding:0;
}
.ad-action-btn i{
    font-size:16px;
    color:rgba(255,255,255,0.6);
    transition:color 0.2s ease, transform 0.2s ease;
}
.ad-action-btn.active i{ color:#ff2d55; }
.ad-action-btn:active i{ transform:scale(1.2); }
.ad-action-count{
    font-size:12px;
    font-weight:600;
    color:rgba(255,255,255,0.6);
}
.ad-view-badge{ margin-left:auto; }
.ad-view-badge i{ color:rgba(255,255,255,0.4); }

.ccm-ad-logo{
    width:48px; height:48px;
    border-radius:12px;
    object-fit:cover;
    border:2.5px solid #ff2b2b;
    cursor:pointer;
    flex-shrink:0;
    display:block;
}

.ccm-ad-from-tag{
    display:inline-flex;align-items:center;gap:6px;
    background:rgba(40,40,46,0.85);
    border-radius:8px;
    padding:3px 10px 3px 4px;
    margin-bottom:0;
    text-decoration:none;
    max-width:100%;
    -webkit-tap-highlight-color:transparent;
}
.ccm-ad-from-avatar{
    width:26px;height:26px;border-radius:50%;object-fit:cover;
    border:2px solid #ff2b2b;flex-shrink:0;display:block;
}
.ccm-ad-from-label{ color:rgba(255,255,255,0.6); font-size:11px; font-weight:500; white-space:nowrap; }
.ccm-ad-from-name{ color:#fff; font-size:12px; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

@media (max-width:790px){
    .brand-logo{ width:33px; height:33px; border-radius:9px; }
    .ad-from-avatar{ width:20px; height:20px; }
    .ad-from-label{ font-size:10.5px; }
    .ad-from-name{ font-size:11.5px; }
    .ad-action-btn i{ font-size:15px; }
    .ad-action-count{ font-size:11.5px; }
    .ccm-ad-logo{ width:44px; height:44px; border-radius:11px; }
    .ccm-ad-from-tag{ padding:3px 9px 3px 4px; }
    .ccm-ad-from-avatar{ width:24px; height:24px; }
    .ccm-ad-from-label{ font-size:10.5px; }
    .ccm-ad-from-name{ font-size:11.5px; }
}

@media (max-width:490px){
    .brand-logo{ width:30px; height:30px; border-radius:8px; }
    .ad-from-avatar{ width:18px; height:18px; }
    .ad-from-label{ font-size:10px; }
    .ad-from-name{ font-size:11px; }
    .ad-actions-row{ gap:12px; margin-top:8px; margin-bottom:8px; padding-top:7px; }
    .ad-action-btn i{ font-size:14px; }
    .ad-action-count{ font-size:11px; }
    .ccm-ad-logo{ width:41px; height:41px; border-radius:10px; border-width:2px; }
    .ccm-ad-from-tag{ padding:2.5px 8px 2.5px 3.5px; }
    .ccm-ad-from-avatar{ width:22px; height:22px; }
    .ccm-ad-from-label{ font-size:10px; }
    .ccm-ad-from-name{ font-size:11px; }
}

.ccm-bottom-ad{ padding-bottom:90px; }

#ccm-ad-rail{ bottom:80px; }
.ccm-rail-ad-tag{
    color:rgba(255,255,255,.4); font-size:9.5px; font-weight:800;
    letter-spacing:.6px; margin-top:-6px; text-align:center;
}

.ccm-ad-from-switcher{ position:relative; min-height:30px; margin-bottom:10px; }
.ccm-ad-from-tag.ccm-switch-pane{
    position:absolute; left:0; top:0;
    opacity:0; pointer-events:none;
    transition:opacity .35s ease;
}
.ccm-ad-from-tag.ccm-switch-pane.active{ position:relative; opacity:1; pointer-events:all; }

@media (max-width:790px){
    .ccm-bottom-ad{ padding-bottom:78px; }
    #ccm-ad-rail{ bottom:72px; }
    .ccm-visit-brand-btn{ left:14px; right:14px; bottom:14px; padding:10px 0; font-size:13px; border-radius:14px; }
}
@media (max-width:490px){
    .ccm-bottom-ad{ padding-bottom:68px; }
    #ccm-ad-rail{ bottom:64px; }
    .ccm-visit-brand-btn{ left:12px; right:12px; bottom:12px; padding:9px 0; font-size:12.5px; border-radius:13px; }
}
@media (max-width:390px){
    .ccm-bottom-ad{ padding-bottom:60px; }
    #ccm-ad-rail{ bottom:58px; }
    .ccm-visit-brand-btn{ left:10px; right:10px; bottom:10px; padding:8px 0; font-size:12px; border-radius:12px; }
}
@media (max-width:330px){
    .ccm-bottom-ad{ padding-bottom:54px; }
    #ccm-ad-rail{ bottom:52px; }
    .ccm-visit-brand-btn{ left:9px; right:9px; bottom:9px; padding:7px 0; font-size:11.5px; border-radius:11px; }
}


/* ══════════════════════════════════════
   SHARE MODAL — bottom sheet (mobile) / centered (desktop)
   ══════════════════════════════════════ */
.share-modal-overlay{
    position:fixed;inset:0;background:rgba(0,0,0,.6);
    backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);
    z-index:99999997;opacity:0;pointer-events:none;
    transition:opacity .25s ease;
}
.share-modal-overlay.show{opacity:1;pointer-events:all;}

.share-modal-panel{
    position:fixed;left:0;right:0;bottom:0;
    background:rgba(12,12,28,.98);
    backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);
    border-top:1px solid rgba(255,255,255,.08);
    border-radius:20px 20px 0 0;
    z-index:99999998;
    max-height:82vh;
    display:flex;flex-direction:column;
    padding:8px 0 max(10px, env(safe-area-inset-bottom));
    transform:translateY(100%);
    transition:transform .3s cubic-bezier(.32,.72,0,1);
    box-shadow:0 -10px 40px rgba(0,0,0,.5);
    font-family:Poppins,sans-serif;
}
.share-modal-panel.show{transform:translateY(0);}

.share-modal-handle{
    width:34px;height:3.5px;border-radius:999px;
    background:rgba(255,255,255,.2);
    margin:0 auto 6px;flex-shrink:0;
}

.share-modal-header{
    display:flex;align-items:center;justify-content:space-between;
    padding:6px 18px 12px;flex-shrink:0;
}
.share-modal-title{ font-size:15px;font-weight:700;color:#fff; }
.share-modal-close{
    width:28px;height:28px;border-radius:50%;
    background:rgba(255,255,255,.07);border:none;color:rgba(255,255,255,.6);
    display:flex;align-items:center;justify-content:center;cursor:pointer;
    -webkit-tap-highlight-color:transparent;
}
.share-modal-close:hover{background:rgba(255,45,45,.18);color:#ff4c4c;}

.share-apps-row{
    display:flex;gap:14px;overflow-x:auto;
    padding:4px 18px 16px;flex-shrink:0;
    scrollbar-width:none;-ms-overflow-style:none;
}
.share-apps-row::-webkit-scrollbar{display:none;}

.share-app-btn{
    display:flex;flex-direction:column;align-items:center;gap:6px;
    flex:0 0 auto;width:60px;
    background:none;border:none;cursor:pointer;
    -webkit-tap-highlight-color:transparent;
    transition:transform .15s ease;
}
.share-app-btn:active{transform:scale(.92);}

.share-app-icon{
    width:50px;height:50px;border-radius:50%;
    display:flex;align-items:center;justify-content:center;
    font-size:22px;color:#fff;
    box-shadow:0 4px 14px rgba(0,0,0,.35);
    transition:box-shadow .2s ease;
}
.share-app-btn:hover .share-app-icon{ box-shadow:0 6px 18px rgba(0,0,0,.5); }

.share-app-icon.copy       { background:linear-gradient(135deg,#4b4b56,#2a2a33); }
.share-app-icon.whatsapp   { background:linear-gradient(135deg,#25d366,#1fa855); }
.share-app-icon.telegram   { background:linear-gradient(135deg,#2ea6de,#229ed9); }
.share-app-icon.twitter    { background:linear-gradient(135deg,#111,#000); }
.share-app-icon.facebook   { background:linear-gradient(135deg,#1877f2,#0f5ec4); }
.share-app-icon.email      { background:linear-gradient(135deg,#ff9a56,#ff6a3d); }
.share-app-icon.more       { background:linear-gradient(135deg,#ff2b2b,#c0141e); }

.share-app-label{
    font-size:10.5px;font-weight:600;color:rgba(255,255,255,.65);
    white-space:nowrap;
}

.share-apps-divider{
    display:flex;align-items:center;gap:10px;
    padding:0 18px 10px;flex-shrink:0;
}
.share-apps-divider::before,
.share-apps-divider::after{ content:'';flex:1;height:1px;background:rgba(255,255,255,.08); }
.share-apps-divider span{
    font-size:10.5px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;
    color:rgba(255,255,255,.35);white-space:nowrap;
}

.share-modal-search{
    display:flex;align-items:center;gap:8px;
    margin:0 18px 12px;padding:9px 14px;
    background:rgba(255,255,255,.06);
    border:1px solid rgba(255,255,255,.1);
    border-radius:12px;flex-shrink:0;
}
.share-modal-search i{color:rgba(255,255,255,.4);font-size:15px;}
.share-modal-search input{
    flex:1;background:none;border:none;outline:none;
    color:#fff;font-size:13px;font-family:Poppins,sans-serif;
}
.share-modal-search input::placeholder{color:rgba(255,255,255,.3);}

.share-modal-list{
    overflow-y:auto;flex:1;padding:0 8px;
    -webkit-overflow-scrolling:touch;
}
.share-modal-list::-webkit-scrollbar{width:4px;}
.share-modal-list::-webkit-scrollbar-thumb{background:rgba(255,255,255,.15);border-radius:999px;}

.share-modal-empty{
    text-align:center;padding:40px 20px;color:rgba(255,255,255,.35);font-size:13px;
}

.share-user-row{
    display:flex;align-items:center;gap:12px;
    padding:10px 12px;border-radius:12px;
    cursor:pointer;transition:background .15s;
    -webkit-tap-highlight-color:transparent;
}
.share-user-row:hover{background:rgba(255,255,255,.05);}
.share-user-row img{
    width:42px;height:42px;border-radius:50%;object-fit:cover;
    border:2px solid #ff2b2b;flex-shrink:0;
}
.share-user-name{
    flex:1;color:#fff;font-size:13.5px;font-weight:500;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
.share-user-check{
    width:22px;height:22px;border-radius:50%;
    border:2px solid rgba(255,255,255,.25);
    display:flex;align-items:center;justify-content:center;
    font-size:12px;color:transparent;flex-shrink:0;
    transition:all .15s;
}
.share-user-row.selected .share-user-check{
    background:#ff2b2b;border-color:#ff2b2b;color:#fff;
}

.share-modal-footer{
    display:flex;align-items:center;gap:12px;
    padding:12px 18px 6px;flex-shrink:0;
    border-top:1px solid rgba(255,255,255,.06);
    margin-top:6px;
}
.share-modal-selcount{ font-size:12px;color:rgba(255,255,255,.5);flex:1; }
.share-send-btn{
    padding:10px 22px;border-radius:12px;border:none;
    background:#ff2b2b;color:#fff;font-weight:700;font-size:13px;
    cursor:pointer;font-family:Poppins,sans-serif;
    transition:opacity .2s;
}
.share-send-btn:disabled{ opacity:.4;cursor:not-allowed; }
.share-send-btn:not(:disabled):hover{ background:#e01f1f; }

@media (min-width:791px){
    .share-modal-panel{
        left:50%;right:auto;bottom:auto;top:50%;
        width:100%;max-width:400px;
        border-radius:20px;
        transform:translate(-50%,-40%) scale(.95);
        opacity:0;pointer-events:none;
        max-height:560px;
    }
    .share-modal-panel.show{
        transform:translate(-50%,-50%) scale(1);
        opacity:1;pointer-events:all;
    }
    .share-modal-handle{display:none;}
}

@media (max-width:790px){
    .share-modal-panel{ max-height:80vh; }
    .share-modal-header{ padding:6px 16px 10px; }
    .share-modal-title{ font-size:14.5px; }
    .share-modal-close{ width:27px;height:27px; }
    .share-apps-row{ gap:12px; padding:4px 16px 14px; }
    .share-app-btn{ width:56px; }
    .share-app-icon{ width:46px;height:46px;font-size:20px; }
    .share-app-label{ font-size:10px; }
    .share-apps-divider{ padding:0 16px 9px; }
    .share-apps-divider span{ font-size:10px; }
    .share-modal-search{ margin:0 16px 11px; padding:8px 13px; }
    .share-modal-search input{ font-size:12.5px; }
    .share-user-row{ padding:9px 11px; gap:11px; }
    .share-user-row img{ width:40px;height:40px; }
    .share-user-name{ font-size:13px; }
    .share-modal-footer{ padding:11px 16px 6px; }
    .share-send-btn{ padding:9px 20px; font-size:12.5px; }
}

@media (max-width:490px){
    .share-modal-panel{ max-height:78vh; }
    .share-modal-header{ padding:6px 14px 9px; }
    .share-modal-title{ font-size:14px; }
    .share-modal-close{ width:26px;height:26px;font-size:14px; }
    .share-apps-row{ gap:10px; padding:4px 14px 12px; }
    .share-app-btn{ width:52px; }
    .share-app-icon{ width:42px;height:42px;font-size:18px; }
    .share-app-label{ font-size:9.5px; }
    .share-apps-divider{ padding:0 14px 8px; }
    .share-apps-divider span{ font-size:9.5px; }
    .share-modal-search{ margin:0 14px 10px; padding:8px 12px; }
    .share-modal-search i{ font-size:14px; }
    .share-modal-search input{ font-size:12px; }
    .share-user-row{ padding:8px 10px; gap:10px; }
    .share-user-row img{ width:38px;height:38px; }
    .share-user-name{ font-size:12.5px; }
    .share-user-check{ width:20px;height:20px;font-size:11px; }
    .share-modal-footer{ padding:10px 14px 6px; gap:10px; }
    .share-modal-selcount{ font-size:11px; }
    .share-send-btn{ padding:8px 18px; font-size:12px; }
}

@media (max-width:390px){
    .share-modal-panel{ max-height:76vh; border-radius:18px 18px 0 0; }
    .share-modal-handle{ width:30px;height:3px; }
    .share-modal-header{ padding:5px 12px 8px; }
    .share-modal-title{ font-size:13.5px; }
    .share-modal-close{ width:25px;height:25px;font-size:13px; }
    .share-apps-row{ gap:9px; padding:4px 12px 11px; }
    .share-app-btn{ width:48px; }
    .share-app-icon{ width:40px;height:40px;font-size:17px; }
    .share-app-label{ font-size:9px; }
    .share-apps-divider{ padding:0 12px 7px; gap:8px; }
    .share-apps-divider span{ font-size:9px; }
    .share-modal-search{ margin:0 12px 9px; padding:7px 11px; border-radius:10px; }
    .share-modal-search i{ font-size:13px; }
    .share-modal-search input{ font-size:11.5px; }
    .share-modal-list{ padding:0 6px; }
    .share-user-row{ padding:7px 9px; gap:9px; border-radius:10px; }
    .share-user-row img{ width:35px;height:35px; }
    .share-user-name{ font-size:12px; }
    .share-user-check{ width:19px;height:19px;font-size:10px; }
    .share-modal-footer{ padding:9px 12px 6px; gap:9px; }
    .share-modal-selcount{ font-size:10.5px; }
    .share-send-btn{ padding:8px 16px; font-size:11.5px; border-radius:10px; }
}

@media (max-width:330px){
    .share-modal-panel{ max-height:74vh; border-radius:16px 16px 0 0; }
    .share-modal-handle{ width:26px;height:3px;margin:0 auto 5px; }
    .share-modal-header{ padding:5px 10px 7px; }
    .share-modal-title{ font-size:13px; }
    .share-modal-close{ width:23px;height:23px;font-size:12px; }
    .share-apps-row{ gap:7px; padding:3px 10px 9px; }
    .share-app-btn{ width:44px; }
    .share-app-icon{ width:36px;height:36px;font-size:15px; }
    .share-app-label{ font-size:8.5px; }
    .share-apps-divider{ padding:0 10px 6px; gap:7px; }
    .share-apps-divider span{ font-size:8.5px; }
    .share-modal-search{ margin:0 10px 8px; padding:6px 10px; border-radius:9px; }
    .share-modal-search i{ font-size:12px; }
    .share-modal-search input{ font-size:11px; }
    .share-modal-list{ padding:0 5px; }
    .share-user-row{ padding:6px 8px; gap:8px; border-radius:9px; }
    .share-user-row img{ width:32px;height:32px;border-width:1.5px; }
    .share-user-name{ font-size:11px; }
    .share-user-check{ width:18px;height:18px;font-size:9px;border-width:1.5px; }
    .share-modal-footer{ padding:8px 10px 5px; gap:8px; }
    .share-modal-selcount{ font-size:10px; }
    .share-send-btn{ padding:7px 14px; font-size:11px; border-radius:9px; }
}
</style>

<!-- SHARE MODAL MARKUP -->
<div id="shareModalOverlay" class="share-modal-overlay" onclick="closeShareModal()"></div>
<div id="shareModalPanel" class="share-modal-panel">
    <div class="share-modal-handle"></div>

    <div class="share-modal-header">
        <span class="share-modal-title">Share to</span>
        <button class="share-modal-close" onclick="closeShareModal()"><i class="ri-close-line"></i></button>
    </div>

    <div class="share-apps-row" id="shareAppsRow">
        <button type="button" class="share-app-btn" onclick="shareViaCopyLink()">
            <span class="share-app-icon copy"><i class="ri-link"></i></span>
            <span class="share-app-label">Copy Link</span>
        </button>
        <button type="button" class="share-app-btn" onclick="shareViaWhatsApp()">
            <span class="share-app-icon whatsapp"><i class="ri-whatsapp-fill"></i></span>
            <span class="share-app-label">WhatsApp</span>
        </button>
        <button type="button" class="share-app-btn" onclick="shareViaTelegram()">
            <span class="share-app-icon telegram"><i class="ri-telegram-fill"></i></span>
            <span class="share-app-label">Telegram</span>
        </button>
        <button type="button" class="share-app-btn" onclick="shareViaTwitter()">
            <span class="share-app-icon twitter"><i class="ri-twitter-x-fill"></i></span>
            <span class="share-app-label">X</span>
        </button>
        <button type="button" class="share-app-btn" onclick="shareViaFacebook()">
            <span class="share-app-icon facebook"><i class="ri-facebook-fill"></i></span>
            <span class="share-app-label">Facebook</span>
        </button>
        <button type="button" class="share-app-btn" onclick="shareViaEmail()">
            <span class="share-app-icon email"><i class="ri-mail-fill"></i></span>
            <span class="share-app-label">Email</span>
        </button>
        <button type="button" class="share-app-btn" id="shareNativeBtn" onclick="shareViaNative()" style="display:none;">
            <span class="share-app-icon more"><i class="ri-more-fill"></i></span>
            <span class="share-app-label">More</span>
        </button>
    </div>

    <div class="share-apps-divider"><span>Or share with people you track</span></div>

    <div class="share-modal-search">
        <i class="ri-search-line"></i>
        <input type="text" id="shareSearchInput" placeholder="Search people you track…" oninput="filterShareList()">
    </div>

    <div class="share-modal-list" id="shareModalList">
        <div class="share-modal-empty">Loading…</div>
    </div>

    <div class="share-modal-footer">
        <span class="share-modal-selcount" id="shareSelCount">0 selected</span>
        <button class="share-send-btn" id="shareSendBtn" onclick="sendShare()" disabled>Send</button>
    </div>
</div>

<script>
/* ════════════════════════════════════════════════════════════
   CARD CAPTION MODAL — text_feed.php build, upgraded onto the
   same gapless vertical-track engine as home.php.

   THIS VERSION ADDS:
   - "Saved · Collections" toast on favoriting a POST (ccmAction fav)
     and on favoriting an AD (ccmAdFavAction), matching the meme
     modal / discover modal behavior. Self-injects its own <style>
     block since this page didn't already have .ccm-saved-toast CSS
     — same CSS as the other modals, so nothing to change on your end.
   - Double-tap-to-like now works on AD slides too. ccmDoubleTapLike
     checks for #ccm-like-btn (post) first, then falls back to
     #ccm-ad-like-btn + _ccmCurrentAdId (ad) and calls
     ccmAdLikeAction(_ccmCurrentAdId). Heart burst animation is
     unchanged/shared — it was already generic.
   ════════════════════════════════════════════════════════════ */
var currentUserId = <?php echo (int)$current_user; ?>;

var ccmFeed               = [];
var _ccmIndex              = 0;
var _ccmMemeId             = null;   // current post id, else null
var _ccmCurrentAdId        = null;   // current ad (sponsorship) id, else null
var _ccmCaptionFullHtml    = '';
var _ccmCaptionTruncHtml   = '';
var _ccmCaptionExpanded    = false;
var _ccmVSwipeLocked       = false;
var _ccmAdFromSwitchTimer  = null;
var _ccmVbSwitchTimer      = null;
var _ccmPausedAdIds        = {};   // ad-id -> true, persists ad video pause state across swipes
var _ccmLoggedImpressions  = new Set();
var _ccmShownForCapping    = new Set();
var _ccmActiveViewTimer    = null;
var CCM_VIEW_DWELL_MS      = 1000;

/* ────────────────────────────────────────────────────────────
   SAVED TOAST — self-injecting styles + show/hide/collections-jump.
   Same class names/markup as the profile & discover modals, so if
   .ccm-saved-toast CSS is already loaded elsewhere on this page
   this injector just no-ops (checks its own <style> id first).
──────────────────────────────────────────────────────────── */
(function _ccmInjectSavedToastStyles(){
    if(document.getElementById('ccm-saved-toast-style')) return;
    var style = document.createElement('style');
    style.id = 'ccm-saved-toast-style';
    style.textContent =
        '.ccm-saved-toast{'+
            'position:fixed;left:12px;right:12px;'+
            'bottom:calc(64px + env(safe-area-inset-bottom));'+
            'background:rgba(40,40,46,0.96);'+
            'backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);'+
            'border-radius:14px;padding:13px 16px;'+
            'display:flex;align-items:center;justify-content:space-between;gap:12px;'+
            'z-index:999999998;font-family:Poppins,sans-serif;'+
            'transform:translateY(120%);opacity:0;'+
            'transition:transform .28s cubic-bezier(.32,.72,0,1), opacity .28s ease;'+
            'box-shadow:0 8px 24px rgba(0,0,0,0.35);'+
        '}'+
        '.ccm-saved-toast.show{ transform:translateY(0); opacity:1; }'+
        '.ccm-saved-toast-left{ display:flex;align-items:center;gap:10px;color:#fff;font-size:14.5px;font-weight:600; }'+
        '.ccm-saved-toast-check{ width:20px;height:20px;border-radius:50%;background:#fff;color:#0c0c1c;'+
            'display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0; }'+
        '.ccm-saved-toast-right{ display:flex;align-items:center;gap:2px;color:#f24455;font-size:14.5px;font-weight:600;'+
            'cursor:pointer;-webkit-tap-highlight-color:transparent;flex-shrink:0; }'+
        '.ccm-saved-toast-right i{ font-size:17px; }'+
        '@media (max-width:790px){'+
            '.ccm-saved-toast{ left:5px;right:5px;bottom:calc(26px + env(safe-area-inset-bottom));border-radius:7px;padding:12px 15px; }'+
            '.ccm-saved-toast-left{ font-size:14px;gap:9px; }'+
            '.ccm-saved-toast-check{ width:19px;height:19px;font-size:11.5px; }'+
            '.ccm-saved-toast-right{ font-size:14px; } .ccm-saved-toast-right i{ font-size:16px; }'+
        '}'+
        '@media (max-width:490px){'+
            '.ccm-saved-toast{ left:4px;right:4px;bottom:calc(20px + env(safe-area-inset-bottom));border-radius:6px;padding:11px 14px; }'+
            '.ccm-saved-toast-left{ font-size:13.5px;gap:8px; }'+
            '.ccm-saved-toast-check{ width:18px;height:18px;font-size:11px; }'+
            '.ccm-saved-toast-right{ font-size:13.5px;gap:1px; } .ccm-saved-toast-right i{ font-size:15.5px; }'+
        '}'+
        '@media (max-width:390px){'+
            '.ccm-saved-toast{ left:3px;right:3px;bottom:calc(14px + env(safe-area-inset-bottom));border-radius:5px;padding:10px 13px; }'+
            '.ccm-saved-toast-left{ font-size:12.5px;gap:7px; }'+
            '.ccm-saved-toast-check{ width:17px;height:17px;font-size:10px; }'+
            '.ccm-saved-toast-right{ font-size:12.5px; } .ccm-saved-toast-right i{ font-size:14.5px; }'+
        '}';
    document.head.appendChild(style);
})();

var _ccmSavedToastTimer = null;

function ccmShowSavedToast(memeId){
    var existing = document.getElementById('ccmSavedToast');
    if(existing) existing.remove();
    clearTimeout(_ccmSavedToastTimer);

    var toast = document.createElement('div');
    toast.id = 'ccmSavedToast';
    toast.className = 'ccm-saved-toast';
    toast.innerHTML =
        '<div class="ccm-saved-toast-left">'+
            '<span class="ccm-saved-toast-check"><i class="ri-check-line"></i></span>'+
            'Saved'+
        '</div>'+
        '<div class="ccm-saved-toast-right" onclick="ccmToastOpenCollections('+memeId+')">'+
            'Collections<i class="ri-arrow-right-s-line"></i>'+
        '</div>';

    document.body.appendChild(toast);
    requestAnimationFrame(function(){ toast.classList.add('show'); });

    _ccmSavedToastTimer = setTimeout(function(){
        toast.classList.remove('show');
        setTimeout(function(){ toast.remove(); }, 300);
    }, 3500);
}

function ccmHideSavedToast(){
    var toast = document.getElementById('ccmSavedToast');
    if(!toast) return;
    clearTimeout(_ccmSavedToastTimer);
    toast.classList.remove('show');
    setTimeout(function(){ toast.remove(); }, 300);
}

function ccmToastOpenCollections(memeId){
    ccmHideSavedToast();
    if(typeof openSaveModal === 'function') openSaveModal(memeId);
}

/* ────────────────────────────────────────────────────────────
   FEED BUILD
──────────────────────────────────────────────────────────── */
function _ccmBuildFeed(){
    ccmFeed = [];
    var adIdx = 0, suggIdx = 0, adSlot = 0;
    var ads  = window.cmAds       || [];
    var sugg = window.cmSuggUsers || [];

    (window.peuraFeed || []).forEach(function(post, i){
        var likeEl   = document.querySelector('.likeBtn[data-id="'+post.id+'"]');
        var repostEl = document.querySelector('.repostBtn[data-id="'+post.id+'"]');
        var favEl    = document.querySelector('.favBtn[data-id="'+post.id+'"]');
        var shareEl  = document.querySelector('.shareBtn[data-id="'+post.id+'"]');

        if(likeEl){
            post.likeCount = parseInt(likeEl.querySelector('span').textContent) || 0;
            var liColor    = likeEl.querySelector('i').style.color;
            post.liked     = liColor === 'rgb(255, 76, 76)' || liColor === '#ff4c4c';
        }
        if(repostEl){
            post.repostCount = parseInt(repostEl.querySelector('span').textContent) || 0;
            var reColor      = repostEl.querySelector('i').style.color;
            post.reposted    = reColor === 'rgb(247, 43, 43)' || reColor === 'rgb(255, 76, 76)' || reColor === '#ff4c4c';
        }
        if(favEl){
            post.favCount = parseInt(favEl.querySelector('span').textContent) || 0;
            var faColor   = favEl.querySelector('i').style.color;
            post.faved    = faColor === 'rgb(255, 0, 0)' || faColor === '#ff0000'
                          || faColor === 'rgb(246, 31, 31)' || faColor === '#f61f1f';
        }
        if(shareEl){
            var shareSpan = shareEl.querySelector('span');
            post.shareCount = shareSpan ? (parseInt(shareSpan.textContent) || 0) : 0;
        } else if(post.shareCount === undefined){
            post.shareCount = 0;
        }

        ccmFeed.push({ type:'post', data:post });

        if((i+1) % 4 === 0 && ads.length > 0){
            ccmFeed.push({ type:'ad', data:ads[adIdx % ads.length], adSlot:adSlot });
            adIdx++; adSlot++;
        }
        if((i+1) % 7 === 0 && sugg.length > 0){
            var maxSlice = Math.min(16, sugg.length);
            var slice = [];
            for(var j=0; j<maxSlice; j++) slice.push(sugg[(suggIdx+j) % sugg.length]);
            ccmFeed.push({ type:'sugg', data:slice });
            suggIdx = (suggIdx + maxSlice) % sugg.length;
        }
    });
}

/* ────────────────────────────────────────────────────────────
   HELPERS
──────────────────────────────────────────────────────────── */
function _formatDate(timeStr){
    if(!timeStr) return '';
    var d = new Date(timeStr.replace(' ','T'));
    if(isNaN(d)) return timeStr;
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return d.getDate()+' '+months[d.getMonth()]+' '+d.getFullYear()+
           ' · '+String(d.getHours()).padStart(2,'0')+':'+String(d.getMinutes()).padStart(2,'0');
}
function _ccmTruncateName(name){
    if(!name) return '';
    return name.length > 15 ? name.slice(0, 15) + '....' : name;
}
function _ccmEscapeHtml(str){
    if(!str) return '';
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function linkifyCaption_ccm(caption){
    return (caption||'')
        .replace(/#([a-zA-Z0-9_]+)/g, function(match, tag){
            var mq = encodeURIComponent('#'+tag);
            return '<a href="search.php?focus=1&mq='+mq+
                   '" onclick="event.stopPropagation()" style="color:#6366f1;font-weight:600;text-decoration:none;"'+
                   ' onmouseover="this.style.textDecoration=\'underline\'"'+
                   ' onmouseout="this.style.textDecoration=\'none\'">'+match+'</a>';
        })
        .replace(/@([a-zA-Z0-9_]+)/g, function(match, username){
            return '<a href="profile.php?username='+encodeURIComponent(username)+
                   '" onclick="event.stopPropagation()" style="color:#ff2b2b;font-weight:600;text-decoration:none;"'+
                   ' onmouseover="this.style.textDecoration=\'underline\'"'+
                   ' onmouseout="this.style.textDecoration=\'none\'">'+match+'</a>';
        });
}

function _ccmComputeCaptionHtml(p){
    var captionHtml = linkifyCaption_ccm(p.caption || '');
    var full = captionHtml, trunc = captionHtml;
    var cWords = (p.caption || '').trim().split(/\s+/).filter(Boolean);
    if(cWords.length > 40){
        var cTrunc = cWords.slice(0, 40).join(' ');
        trunc = linkifyCaption_ccm(cTrunc)+
            '<span class="ccm-more-toggle" onclick="event.stopPropagation();ccmToggleCenterCaption()">'+
                '<span class="ccm-ellipsis">...</span>more'+
            '</span>';
    }
    return { full:full, trunc:trunc };
}

/* ────────────────────────────────────────────────────────────
   CAPTION EXPAND / COLLAPSE
──────────────────────────────────────────────────────────── */
function ccmUpdateSocialProofVisibility(){
    var slideEl = document.getElementById('ccm-slide');
    var sp = slideEl ? slideEl.querySelector('#ccm-social-proof') : null;
    if(!sp) return;
    sp.classList.toggle('ccm-sp-hidden', _ccmCaptionExpanded);
}
function _ccmIsInsideExpandedCaption(target){
    if(!_ccmCaptionExpanded || !target || !target.closest) return false;
    return !!target.closest('.ccm-center-caption-wrap');
}
function _ccmCheckCaptionOverflow(slide){
    if(!slide) return;
    var wrap = slide.querySelector('.ccm-center-caption-wrap');
    var text = slide.querySelector('.ccm-center-caption-text');
    if(!wrap || !text) return;
    var isOverflowing = text.scrollHeight > wrap.clientHeight;
    wrap.classList.toggle('ccm-caption-overflow', isOverflowing);
}
function ccmToggleCenterCaption(){
    var slideEl = document.getElementById('ccm-slide');
    if(!slideEl) return;
    var textEl = slideEl.querySelector('#ccm-center-caption-text');
    var wrapEl = slideEl.querySelector('#ccm-center-caption-wrap');
    if(!textEl) return;
    _ccmCaptionExpanded = !_ccmCaptionExpanded;
    if(_ccmCaptionExpanded){
        textEl.innerHTML = _ccmCaptionFullHtml+
            ' <span class="ccm-more-toggle" onclick="event.stopPropagation();ccmToggleCenterCaption()"> see less</span>';
    } else {
        textEl.innerHTML = _ccmCaptionTruncHtml;
        textEl.scrollTop = 0;
    }
    ccmUpdateSocialProofVisibility();
    _ccmCheckCaptionOverflow(slideEl);
}

/* ────────────────────────────────────────────────────────────
   SKELETON
──────────────────────────────────────────────────────────── */
function _ccmSkeletonHtml(){
    return '<div class="ccm-skeleton" id="ccm-skeleton">'+
        '<div class="ccm-skel-block ccm-skel-media"></div>'+
        '<div class="ccm-skel-block ccm-skel-avatar"></div>'+
        '<div class="ccm-skel-block ccm-skel-line ccm-skel-line1"></div>'+
        '<div class="ccm-skel-block ccm-skel-line ccm-skel-line2"></div>'+
        '<div class="ccm-skel-block ccm-skel-rail ccm-skel-rail1"></div>'+
        '<div class="ccm-skel-block ccm-skel-rail ccm-skel-rail2"></div>'+
        '<div class="ccm-skel-block ccm-skel-rail ccm-skel-rail3"></div>'+
        '<div class="ccm-skel-block ccm-skel-rail ccm-skel-rail4"></div>'+
        '<div class="ccm-skel-block ccm-skel-rail ccm-skel-rail5"></div>'+
        '<div class="ccm-skel-shimmer"></div>'+
    '</div>';
}
function ccmHideSkeleton(root, instant){
    var sk = root ? root.querySelector('#ccm-skeleton') : document.getElementById('ccm-skeleton');
    if(!sk) return;
    if(instant){
        sk.style.transition = 'none';
        sk.classList.add('ccm-skeleton-hide');
        requestAnimationFrame(function(){
            if(sk && sk.parentNode) sk.remove();
        });
        return;
    }
    sk.classList.add('ccm-skeleton-hide');
    setTimeout(function(){ if(sk && sk.parentNode) sk.remove(); }, 340);
}

/* ────────────────────────────────────────────────────────────
   AD-FROM / VISIT-BRAND TEXT SWITCHERS
──────────────────────────────────────────────────────────── */
function _ccmInitAdFromSwitcher(slide){
    if(_ccmAdFromSwitchTimer){ clearInterval(_ccmAdFromSwitchTimer); _ccmAdFromSwitchTimer = null; }
    if(!slide) return;
    var switcher = slide.querySelector('#ccm-ad-from-switcher');
    if(!switcher) return;
    var panes = switcher.querySelectorAll('.ccm-switch-pane');
    if(panes.length < 2) return;
    var activeIdx = 0;
    panes.forEach(function(p, i){ p.classList.toggle('active', i === 0); });
    _ccmAdFromSwitchTimer = setInterval(function(){
        panes[activeIdx].classList.remove('active');
        activeIdx = (activeIdx + 1) % panes.length;
        panes[activeIdx].classList.add('active');
    }, 3000);
}
function _ccmInitVbSwitcher(slide){
    if(_ccmVbSwitchTimer){ clearInterval(_ccmVbSwitchTimer); _ccmVbSwitchTimer = null; }
    if(!slide) return;
    var switcher = slide.querySelector('#ccm-vb-switcher');
    if(!switcher) return;
    var panes = switcher.querySelectorAll('.ccm-vb-pane');
    if(panes.length < 2) return;
    var activeIdx = 0;
    panes.forEach(function(p, i){ p.classList.toggle('active', i === 0); });
    _ccmVbSwitchTimer = setInterval(function(){
        panes[activeIdx].classList.remove('active');
        activeIdx = (activeIdx + 1) % panes.length;
        panes[activeIdx].classList.add('active');
    }, 3000);
}

/* ────────────────────────────────────────────────────────────
   VIDEO PROGRESS (ad videos)
──────────────────────────────────────────────────────────── */
function ccmUpdateProgress(video){
    if(!video.duration || isNaN(video.duration)) return;
    var container = video.closest('.ccm-media-box');
    if(!container) return;
    var fill = container.querySelector('.ccm-video-progress-fill');
    if(!fill) return;
    fill.style.width = (video.currentTime/video.duration*100)+'%';
}

/* ────────────────────────────────────────────────────────────
   TAP HANDLING
──────────────────────────────────────────────────────────── */
function ccmHandleMediaTap(e, mediaBox){
    var now = Date.now();
    var lastTap = mediaBox._ccmLastTap || 0;
    var dt = now - lastTap;

    if(dt > 0 && dt < 320){
        mediaBox._ccmLastTap = 0;
        if(mediaBox._ccmTapTimer){ clearTimeout(mediaBox._ccmTapTimer); mediaBox._ccmTapTimer = null; }
        ccmDoubleTapLike(mediaBox, e);
    } else {
        mediaBox._ccmLastTap = now;
        mediaBox._ccmTapTimer = setTimeout(function(){
            ccmToggleVideoPlay(mediaBox);
        }, 260);
    }
}

/* UPDATED: now handles both post slides (#ccm-like-btn -> ccmAction)
   and ad slides (#ccm-ad-like-btn -> ccmAdLikeAction). Only ever
   likes, never unlikes, on double-tap. */
function ccmDoubleTapLike(mediaBox, e){
    var rect = mediaBox.getBoundingClientRect();
    var cx = (e && typeof e.clientX === 'number' && e.clientX !== 0) ? e.clientX : (rect.left + rect.width/2);
    var cy = (e && typeof e.clientY === 'number' && e.clientY !== 0) ? e.clientY : (rect.top + rect.height/2);
    ccmShowHeartBurst(mediaBox, cx - rect.left, cy - rect.top);

    var slideEl = document.getElementById('ccm-slide');
    if(!slideEl) return;

    var postLikeBtn = slideEl.querySelector('#ccm-like-btn');
    if(postLikeBtn){
        if(!postLikeBtn.classList.contains('active')) ccmAction('like');
        return;
    }

    var adLikeBtn = slideEl.querySelector('#ccm-ad-like-btn');
    if(adLikeBtn && _ccmCurrentAdId){
        if(!adLikeBtn.classList.contains('active')) ccmAdLikeAction(_ccmCurrentAdId);
    }
}

function ccmShowHeartBurst(mediaBox, x, y){
    mediaBox.querySelectorAll('.ccm-double-tap-heart, .ccm-heart-ring, .ccm-heart-particle')
        .forEach(function(el){ el.remove(); });

    var ring = document.createElement('div');
    ring.className = 'ccm-heart-ring';
    ring.style.left = x+'px'; ring.style.top = y+'px';
    mediaBox.appendChild(ring);
    requestAnimationFrame(function(){ ring.classList.add('show'); });
    setTimeout(function(){ ring.remove(); }, 800);

    var heart = document.createElement('div');
    heart.className = 'ccm-double-tap-heart';
    heart.style.left = x+'px'; heart.style.top = y+'px';
    heart.innerHTML = '<i class="ri-heart-3-fill"></i>';
    mediaBox.appendChild(heart);
    requestAnimationFrame(function(){ heart.classList.add('show'); });
    setTimeout(function(){ heart.remove(); }, 950);

    var particleCount = 8;
    for(var i = 0; i < particleCount; i++){
        (function(i){
            var angle    = (360 / particleCount) * i + (Math.random()*20 - 10);
            var rad      = angle * Math.PI / 180;
            var distance = 70 + Math.random()*50;
            var tx       = Math.cos(rad) * distance;
            var ty       = Math.sin(rad) * distance;
            var rot      = (Math.random()*80 - 40);
            var size     = 14 + Math.random()*12;

            var p = document.createElement('div');
            p.className = 'ccm-heart-particle';
            p.style.left = x+'px'; p.style.top = y+'px';
            p.style.setProperty('--tx', tx+'px');
            p.style.setProperty('--ty', ty+'px');
            p.style.setProperty('--tr', rot+'deg');
            p.innerHTML = '<i class="ri-heart-3-fill" style="font-size:'+size+'px;"></i>';
            mediaBox.appendChild(p);

            setTimeout(function(){ requestAnimationFrame(function(){ p.classList.add('show'); }); }, 40 + i*15);
            setTimeout(function(){ p.remove(); }, 950 + i*15);
        })(i);
    }
}

/* ────────────────────────────────────────────────────────────
   TAP TO PAUSE/RESUME + MUTE + 10s SEEK (ad videos)
──────────────────────────────────────────────────────────── */
function ccmToggleVideoPlay(mediaBox){
    var video = mediaBox.querySelector('video.ccm-media');
    if(!video) return;

    if(video.paused){
        video.play();
        if(_ccmCurrentAdId) delete _ccmPausedAdIds[_ccmCurrentAdId];
        ccmHidePausedControls(mediaBox);
        ccmFlashIcon(mediaBox, 'ri-play-fill');
    } else {
        video.pause();
        if(_ccmCurrentAdId) _ccmPausedAdIds[_ccmCurrentAdId] = true;
        ccmShowPausedControls(mediaBox, video);
    }
}

function ccmFlashIcon(mediaBox, iconClass){
    var existing = mediaBox.querySelector('.ccm-tap-flash');
    if(existing) existing.remove();
    var icon = document.createElement('div');
    icon.className = 'ccm-tap-flash';
    icon.innerHTML = '<i class="'+iconClass+'"></i>';
    mediaBox.appendChild(icon);
    requestAnimationFrame(function(){ icon.classList.add('show'); });
    setTimeout(function(){
        icon.classList.remove('show');
        setTimeout(function(){ icon.remove(); }, 220);
    }, 450);
}

function ccmShowPausedControls(mediaBox, video){
    ccmHidePausedControls(mediaBox);
    var isMuted = video ? !!video.muted : false;
    var wrap = document.createElement('div');
    wrap.className = 'ccm-paused-controls';
    wrap.innerHTML =
        '<div class="ccm-paused-stack">'+
            '<button type="button" class="ccm-mute-btn" onclick="event.stopPropagation();ccmToggleMute(this)">'+
                '<i class="'+(isMuted ? 'ri-volume-mute-fill' : 'ri-volume-up-fill')+'"></i>'+
            '</button>'+
            '<div class="ccm-paused-row">'+
                '<button type="button" class="ccm-seek-btn" onclick="event.stopPropagation();ccmSeekVideo(this,-10)" title="Back 10s">'+
                    '<i class="ri-replay-10-line"></i>'+
                '</button>'+
                '<div class="ccm-pause-icon"><i class="ri-pause-fill"></i></div>'+
                '<button type="button" class="ccm-seek-btn" onclick="event.stopPropagation();ccmSeekVideo(this,10)" title="Forward 10s">'+
                    '<i class="ri-forward-10-line"></i>'+
                '</button>'+
            '</div>'+
        '</div>';
    mediaBox.appendChild(wrap);
}
function ccmHidePausedControls(mediaBox){
    var existing = mediaBox.querySelector('.ccm-paused-controls');
    if(existing) existing.remove();
}
function ccmToggleMute(btn){
    var container = btn.closest('.ccm-media-box');
    var video = container ? container.querySelector('video.ccm-media') : null;
    if(!video) return;
    video.muted = !video.muted;
    var icon = btn.querySelector('i');
    icon.className = video.muted ? 'ri-volume-mute-fill' : 'ri-volume-up-fill';
}
function ccmSeekVideo(btn, delta){
    var container = btn.closest('.ccm-media-box');
    var video = container ? container.querySelector('video.ccm-media') : null;
    if(!video || isNaN(video.duration)) return;
    var newTime = video.currentTime + delta;
    if(newTime < 0) newTime = 0;
    if(video.duration && newTime > video.duration) newTime = video.duration;
    video.currentTime = newTime;
    ccmUpdateProgress(video);
}

/* ────────────────────────────────────────────────────────────
   SMART OBJECT-FIT (ad media)
──────────────────────────────────────────────────────────── */
function ccmFixObjectFit(el){
    var box = el.closest('.ccm-media-box');
    if(!box) return;
    var mW = el.tagName === 'VIDEO' ? el.videoWidth  : el.naturalWidth;
    var mH = el.tagName === 'VIDEO' ? el.videoHeight : el.naturalHeight;
    if(!mW || !mH) return;
    var cW = box.clientWidth, cH = box.clientHeight;
    if(!cW || !cH) return;
    var mediaAspect = mW / mH;
    var boxAspect   = cW / cH;
    el.style.objectFit = (mediaAspect < boxAspect) ? 'cover' : 'contain';
}

/* ────────────────────────────────────────────────────────────
   TRACK BUTTON
──────────────────────────────────────────────────────────── */
function ccmToggleTrack(btn){
    var userId = btn.dataset.user;
    fetch('text_feed.php', {
        method:  'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body:    'toggle_follow=1&user_id='+userId
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if(d.status === 'success'){
            document.querySelectorAll('.ccm-track-btn[data-user="'+userId+'"]').forEach(function(fb){
                if(d.following){ fb.textContent='Untrack'; fb.classList.add('following'); }
                else            { fb.textContent='Track';  fb.classList.remove('following'); }
            });
            document.querySelectorAll('.follow-btn[data-user="'+userId+'"]').forEach(function(fb){
                if(d.following){ fb.textContent='UnTrack'; fb.classList.add('following'); }
                else            { fb.textContent='Track';  fb.classList.remove('following'); }
            });
        }
    });
}

/* ────────────────────────────────────────────────────────────
   RENDER — TEXT POST SLOT
──────────────────────────────────────────────────────────── */
function _ccmRenderPost(p, isPreview){
    var isOwnPost = String(p.userId) === String(currentUserId);
    var feedTrackBtn = document.querySelector('.follow-btn[data-user="'+p.userId+'"]');
    var isFollowing  = feedTrackBtn ? feedTrackBtn.classList.contains('following') : false;

    var capState = _ccmComputeCaptionHtml(p);
    var localCaptionTruncHtml = capState.trunc;

    var mediaHtml = '<div class="ccm-textonly-bg"></div>'+
                     '<div class="ccm-textonly-overlay"></div>'+
                     '<div class="ccm-center-caption-wrap" id="ccm-center-caption-wrap">'+
                         '<div class="ccm-center-caption-text" id="ccm-center-caption-text">'+localCaptionTruncHtml+'</div>'+
                     '</div>';

    var typeTagHtml = '<div class="ccm-type-tag"><i class="ri-file-text-line"></i><span>Text</span></div>';

    var likeCount   = p.likeCount   !== undefined ? p.likeCount   : 0;
    var repostCount = p.repostCount !== undefined ? p.repostCount : 0;
    var favCount    = p.favCount    !== undefined ? p.favCount    : 0;
    var shareCount  = p.shareCount  !== undefined ? p.shareCount  : 0;
    var liked       = p.liked    || false;
    var reposted    = p.reposted || false;
    var faved       = p.faved    || false;

    var trackBtnHtml = !isOwnPost
        ? '<span class="ccm-user-dot">·</span>'+
          '<button class="ccm-track-btn '+(isFollowing?'following':'')+'" data-user="'+p.userId+'" '+
              'onclick="event.stopPropagation();ccmToggleTrack(this)">'+(isFollowing?'Untrack':'Track')+'</button>'
        : '';

    var isNewPost = false;
    if(p.time){
        var _pd = new Date((p.time||'').replace(' ','T'));
        if(!isNaN(_pd)){ isNewPost = (Date.now() - _pd.getTime()) <= (172800*1000); }
    }
    var displayName = isOwnPost ? 'You' : _ccmTruncateName(p.username);
    var nicknameHtml = (!isOwnPost && p.nickname) ? '<div class="ccm-nickname">@'+p.nickname+'</div>' : '';

    var socialProofHtml = '';
    if(Array.isArray(p.socialProof) && p.socialProof.length){
        socialProofHtml = '<div class="ccm-social-proof-strip" id="ccm-social-proof">'+
            p.socialProof.slice(0,4).map(function(sp){
                var spPic  = sp.profile_pic || 'uploads/me.jpeg';
                var isLike = sp.action_type === 'like';
                var icon   = isLike ? 'ri-heart-3-fill' : 'ri-repeat-line';
                var color  = isLike ? '#f24455' : '#7a4aff';
                return '<a href="profile.php?user_id='+sp.id+'" onclick="event.stopPropagation()" class="ccm-sp-avatar-wrap">'+
                    '<img src="'+spPic+'" onerror="this.src=\'uploads/me.jpeg\'" class="ccm-sp-avatar">'+
                    '<span class="ccm-sp-badge" style="background:'+color+';"><i class="'+icon+'"></i></span>'+
                '</a>';
            }).join('')+
        '</div>';
    }

    /* Paid Partnership badge — same pattern as home.php's _ccmRenderPost.
       Sits under the user row / caption, never mixed into caption text. */
    var partnershipHtml = '';
    if(p.isPartnership){
        var brandText = p.partnerBrand ? ' with <strong>'+_ccmEscapeHtml(p.partnerBrand)+'</strong>' : '';
        partnershipHtml = '<div class="ccm-partnership-tag"><i class="ri-handshake-line"></i>Paid partnership'+brandText+'</div>';
    }

    return (
        '<div class="ccm-media-box" id="ccm-media-box" onclick="event.stopPropagation();ccmHandleMediaTap(event,this)">'+ mediaHtml +'</div>'+

        '<div class="ccm-top-bar">'+
            '<div class="ccm-top-bar-row">'+
                '<div class="ccm-back-group">'+
                    '<span class="ccm-icon-btn ccm-back-btn" style="visibility:hidden;pointer-events:none;"><i class="ri-arrow-left-s-line"></i></span>'+
                    '<span class="ccm-brand-label">Zeems</span>'+
                    (p.time ? '<span class="ccm-brand-dot">·</span><span class="ccm-brand-date">'+_formatDate(p.time)+(isNewPost?' · <span class="ccm-new-tag">New</span>':'')+'</span>' : '')+
                '</div>'+
                '<button class="ccm-icon-btn ccm-search-btn" onclick="location.href=\'search.php?focus=1\'"><i class="ri-search-line"></i></button>'+
            '</div>'+
        '</div>'+

        '<div class="ccm-top-right-tags">'+ typeTagHtml +'</div>'+
        socialProofHtml+

        '<div class="ccm-rail">'+
            '<button id="ccm-like-btn" class="ccm-rail-btn '+(liked?'active':'')+'" onclick="event.stopPropagation();ccmAction(\'like\')">'+
                '<i class="ri-heart-3-fill"></i><span id="ccm-like-count" class="ccm-rail-count">'+likeCount+'</span>'+
            '</button>'+
            '<button id="ccm-repost-btn" class="ccm-rail-btn '+(reposted?'active':'')+'" onclick="event.stopPropagation();ccmAction(\'repost\')">'+
                '<i class="ri-repeat-line"></i><span id="ccm-repost-count" class="ccm-rail-count">'+repostCount+'</span>'+
            '</button>'+
            '<button id="ccm-fav-btn" class="ccm-rail-btn '+(faved?'active':'')+'" onclick="event.stopPropagation();ccmAction(\'fav\')">'+
                '<i class="ri-bookmark-fill"></i><span id="ccm-fav-count" class="ccm-rail-count">'+favCount+'</span>'+
            '</button>'+
            '<button id="ccm-share-btn" class="ccm-rail-btn" onclick="event.stopPropagation();openShareModal('+p.id+')">'+
                '<i class="ri-share-forward-fill"></i><span id="ccm-share-count" class="ccm-rail-count">'+shareCount+'</span>'+
            '</button>'+
            '<button class="ccm-rail-btn" onclick="event.stopPropagation();openIndexSheet('+p.id+')">'+
                '<i class="ri-menu-line"></i>'+
            '</button>'+
            (!isOwnPost
                ? '<a class="ccm-rail-btn ccm-rail-msg" href="messages.php?user_id='+p.userId+'" onclick="event.stopPropagation()">'+
                      '<img src="'+(p.pic||'uploads/me.jpeg')+'" onerror="this.src=\'uploads/me.jpeg\'" class="ccm-rail-msg-pic">'+
                  '</a>'
                : '')+
        '</div>'+

        '<div class="ccm-bottom">'+
            '<div class="ccm-user-row">'+
                '<img src="'+(p.pic||'uploads/me.jpeg')+'" onerror="this.src=\'uploads/me.jpeg\'" class="ccm-user-pic" onclick="location.href=\'profile.php?user_id='+p.userId+'\'">'+
                '<div class="ccm-user-info">'+
                    '<div class="ccm-user-line1">'+
                        '<span class="ccm-username" onclick="location.href=\'profile.php?user_id='+p.userId+'\'">'+displayName+'</span>'+
                        trackBtnHtml+
                    '</div>'+
                    nicknameHtml+
                '</div>'+
            '</div>'+
            partnershipHtml+
        '</div>'
    );
}


/* ────────────────────────────────────────────────────────────
   RENDER — AD SLOT
──────────────────────────────────────────────────────────── */
function _ccmRenderAd(item, isPreview){
    var ad  = item.data;
    var ext = (ad.meme_image||ad.ext||'').split('.').pop().toLowerCase();
    var isVideo = ['mp4','webm','mov'].indexOf(ext) !== -1;

    var mutedAttr    = isPreview ? 'muted' : '';
    var autoplayAttr = isPreview ? '' : 'autoplay';

    var mediaHtml = isVideo
        ? '<video src="uploads/'+(ad.meme_image||'')+'" '+autoplayAttr+' '+mutedAttr+' loop playsinline '+
          'onloadedmetadata="ccmFixObjectFit(this);ccmHideSkeleton(this.closest(\'.ccm-slide\'))" '+
          'ontimeupdate="ccmUpdateProgress(this)" class="ccm-media"></video>'+
          '<div class="ccm-video-progress"><div class="ccm-video-progress-fill"></div></div>'
        : '<img src="uploads/'+(ad.meme_image||'')+'" onerror="this.src=\'uploads/me.jpeg\'" onload="ccmFixObjectFit(this);ccmHideSkeleton(this.closest(\'.ccm-slide\'))" class="ccm-media">';

    var vbColors = ['ccm-visit-brand-red', 'ccm-visit-brand-blue', 'ccm-visit-brand-orange'];
    var vbClass  = vbColors[(item.adSlot||0) % vbColors.length];

    var adId       = ad.id;
    var likeCount  = ad.like_count !== undefined ? ad.like_count : 0;
    var favCount   = ad.fav_count  !== undefined ? ad.fav_count  : 0;
    var userLiked  = parseInt(ad.user_liked) > 0;
    var userFaved  = parseInt(ad.user_faved) > 0;
    var profileUrl = ad.user_id ? 'profile.php?user_id='+ad.user_id : '#';
    var posterPic  = ad.poster_pic || 'uploads/me.jpeg';
    var posterName = ad.poster_username || '';

    var isOwnAd = (ad.user_id !== undefined && ad.user_id !== null) && String(ad.user_id) === String(currentUserId);
    var isAdFollowing = parseInt(ad.is_following) > 0;
    var trackBtnHtml  = (!isOwnAd && ad.user_id)
        ? '<button class="ccm-track-btn '+(isAdFollowing?'following':'')+'" data-user="'+ad.user_id+'" '+
              'onclick="event.stopPropagation();ccmToggleTrack(this)">'+(isAdFollowing?'Untrack':'Track')+'</button>'
        : '';

    return (
        _ccmSkeletonHtml()+

        '<div class="ccm-media-box" id="ccm-media-box" onclick="event.stopPropagation();ccmHandleMediaTap(event,this)">'+ mediaHtml +'</div>'+

        '<div class="ccm-top-bar">'+
            '<div class="ccm-top-bar-row">'+
                '<div class="ccm-back-group">'+
                    '<span class="ccm-icon-btn ccm-back-btn" style="visibility:hidden;pointer-events:none;"><i class="ri-arrow-left-s-line"></i></span>'+
                '</div>'+
                '<div style="display:flex;align-items:center;gap:8px;">'+
                    '<span class="ccm-ad-inline-tag"><i class="ri-advertisement-line"></i><span>Advertisement</span></span>'+
                    '<button class="ccm-icon-btn ccm-search-btn" onclick="location.href=\'search.php?focus=1\'"><i class="ri-search-line"></i></button>'+
                '</div>'+
            '</div>'+
        '</div>'+

        '<div class="ccm-rail" id="ccm-ad-rail">'+
            '<button id="ccm-ad-like-btn" class="ccm-rail-btn '+(userLiked?'active':'')+'" '+
                'onclick="event.stopPropagation();ccmAdLikeAction('+adId+')">'+
                '<i class="ri-heart-3-fill"></i><span id="ccm-ad-like-count" class="ccm-rail-count">'+likeCount+'</span>'+
            '</button>'+
            '<button id="ccm-ad-fav-btn" class="ccm-rail-btn '+(userFaved?'active':'')+'" '+
                'onclick="event.stopPropagation();ccmAdFavAction('+adId+')">'+
                '<i class="ri-bookmark-fill"></i><span id="ccm-ad-fav-count" class="ccm-rail-count">'+favCount+'</span>'+
            '</button>'+
            '<button id="ccm-ad-share-btn" class="ccm-rail-btn" onclick="event.stopPropagation();openShareModal('+adId+')">'+
                '<i class="ri-share-forward-fill"></i>'+
            '</button>'+
            '<button id="ccm-ad-more-btn" class="ccm-rail-btn" onclick="event.stopPropagation();ccmOpenAdMenu('+adId+')">'+
                '<i class="ri-menu-line"></i>'+
            '</button>'+
            '<span class="ccm-rail-ad-tag">AD</span>'+
        '</div>'+

        '<div class="ccm-bottom ccm-bottom-ad">'+
            '<div class="ccm-ad-from-switcher" id="ccm-ad-from-switcher">'+
                '<a href="'+profileUrl+'" onclick="event.stopPropagation()" class="ccm-ad-from-tag ccm-switch-pane active">'+
                    '<img src="'+posterPic+'" onerror="this.src=\'uploads/me.jpeg\'" class="ccm-ad-from-avatar">'+
                    '<span class="ccm-ad-from-label">From</span>'+
                    '<span class="ccm-ad-from-name">'+posterName+'</span>'+
                '</a>'+
                '<a href="'+profileUrl+'" onclick="event.stopPropagation()" class="ccm-ad-from-tag ccm-switch-pane">'+
                    '<img src="'+posterPic+'" onerror="this.src=\'uploads/me.jpeg\'" class="ccm-ad-from-avatar">'+
                    '<span class="ccm-ad-from-name">'+posterName+'\'s Ad</span>'+
                '</a>'+
            '</div>'+
            '<div class="ccm-user-row">'+
                '<a href="'+profileUrl+'" onclick="event.stopPropagation()" style="flex-shrink:0;">'+
                    '<img src="uploads/'+(ad.image||'')+'" onerror="this.src=\'uploads/mee.jpeg\'" class="ccm-ad-logo">'+
                '</a>'+
                '<div style="display:flex;flex-direction:column;min-width:0;flex:1;">'+
                    '<div style="display:flex;align-items:center;gap:8px;min-width:0;">'+
                        '<span class="ccm-username">'+(ad.title||'')+'</span>'+
                        (trackBtnHtml ? '<span class="ccm-user-dot">·</span>'+trackBtnHtml : '')+
                    '</div>'+
                    '<span style="font-size:10px;font-weight:500;color:rgba(255,255,255,0.45);line-height:1;margin-top:2px;">Sponsored</span>'+
                '</div>'+
            '</div>'+
            '<div class="ccm-caption-wrap">'+
                '<span class="ccm-caption-text">'+(ad.caption||'')+'</span>'+
            '</div>'+
            '<a href="'+(ad.link||'#')+'" target="_blank" class="ccm-visit-brand-btn '+vbClass+'" onclick="event.stopPropagation()">'+
                '<span class="ccm-vb-switcher" id="ccm-vb-switcher">'+
                    '<span class="ccm-vb-pane active">Visit Brand <i class="ri-arrow-right-up-line"></i></span>'+
                    '<span class="ccm-vb-pane">Learn More <i class="ri-arrow-right-up-line"></i></span>'+
                '</span>'+
            '</a>'+
        '</div>'
    );
}

/* ────────────────────────────────────────────────────────────
   AD LIKE / FAVORITE / MENU
   UPDATED (fav): now shows/hides the same "Saved · Collections"
   toast as posts, on both add and remove.
──────────────────────────────────────────────────────────── */
function ccmAdLikeAction(sponsorshipId){
    var slideEl = document.getElementById('ccm-slide');
    var btn     = slideEl ? slideEl.querySelector('#ccm-ad-like-btn') : null;
    var countEl = slideEl ? slideEl.querySelector('#ccm-ad-like-count') : null;

    fetch('text_feed.php', {
        method:  'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body:    'toggle_ad_like=1&sponsorship_id='+sponsorshipId
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        if(data.count === undefined) return;
        var nowActive = btn ? !btn.classList.contains('active') : true;
        if(btn) btn.classList.toggle('active', nowActive);
        if(countEl) countEl.textContent = data.count;
    })
    .catch(function(err){ console.error('ccmAdLikeAction failed:', err); });
}

function ccmAdFavAction(sponsorshipId){
    var slideEl = document.getElementById('ccm-slide');
    var btn     = slideEl ? slideEl.querySelector('#ccm-ad-fav-btn') : null;
    var countEl = slideEl ? slideEl.querySelector('#ccm-ad-fav-count') : null;

    fetch('text_feed.php', {
        method:  'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body:    'toggle_ad_favorite=1&sponsorship_id='+sponsorshipId
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        if(data.count === undefined) return;
        var nowActive = btn ? !btn.classList.contains('active') : true;
        if(btn) btn.classList.toggle('active', nowActive);
        if(countEl) countEl.textContent = data.count;

        // NEW: same toast behavior as posts
        if(nowActive) ccmShowSavedToast(sponsorshipId);
        else ccmHideSavedToast();
    })
    .catch(function(err){ console.error('ccmAdFavAction failed:', err); });
}

function ccmOpenAdMenu(sponsorshipId){
    if(!confirm('Hide this ad and see fewer like it?')) return;
    ccmAdNotInterested(sponsorshipId);
}

function ccmAdNotInterested(sponsorshipId){
    fetch('text_feed.php', {
        method:  'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body:    'ad_not_interested=1&sponsorship_id='+sponsorshipId
    })
    .then(function(r){ return r.json(); })
    .then(function(){
        var item = ccmFeed[_ccmIndex];
        if(item && item.type === 'ad' && String(item.data.id) === String(sponsorshipId)){
            ccmLoadIndex(_ccmIndex + 1);
        }
    })
    .catch(function(err){ console.error('ccmAdNotInterested failed:', err); });
}

function _ccmClearPendingImpression(){
    if(_ccmActiveViewTimer){ clearTimeout(_ccmActiveViewTimer); _ccmActiveViewTimer = null; }
}
function _ccmLogAdImpression(adId){
    if(_ccmLoggedImpressions.has(String(adId))) return;
    _ccmLoggedImpressions.add(String(adId));
    fetch('text_feed.php', {
        method:  'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body:    'log_ad_impression=1&sponsorship_id='+adId
    }).catch(function(){});
}
function _ccmLogAdShown(adId){
    if(_ccmShownForCapping.has(String(adId))) return;
    _ccmShownForCapping.add(String(adId));
    fetch('text_feed.php', {
        method:  'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body:    'log_ad_shown=1&sponsorship_id='+adId
    }).catch(function(){});
}

/* ────────────────────────────────────────────────────────────
   RENDER — SUGGESTED USERS SLOT
──────────────────────────────────────────────────────────── */
function _ccmSuggRoot(ctx){
    return ctx ? ctx.closest('.ccm-sugg-slide') : document.querySelector('#ccm-slide .ccm-sugg-slide');
}

function _ccmRenderSugg(item, isPreview){
    var users = (item.data || []).filter(Boolean);
    if(users.length === 0) return '';

    var pageCount = Math.max(1, Math.ceil(users.length / 4));

    function pageUsers(pg){ return users.slice(pg*4, pg*4+4); }
    function bgTilesHtml(pg){
        var pu = pageUsers(pg);
        var tiles = [];
        for(var t=0; t<4; t++){
            var u = pu.length ? pu[t % pu.length] : null;
            var pic = u ? (u.profile_pic || 'uploads/me.jpeg') : 'uploads/me.jpeg';
            tiles.push('<div class="ccm-sugg-bg-tile" data-tile="'+t+'" style="background-image:url(\''+pic+'\')"></div>');
        }
        return tiles.join('');
    }

    var pagesHtml = '';
    for(var pg = 0; pg < pageCount; pg++){
        var slice = pageUsers(pg);
        var cardsHtml = slice.map(function(u){
            var following = parseInt(u.is_following) > 0;
            var metaHtml = '';
            if(u.mutual_count){ metaHtml = '<span class="ccm-sugg-card-meta">'+u.mutual_count+' mutual</span>'; }
            else if(u.reason){ metaHtml = '<span class="ccm-sugg-card-meta">'+_ccmEscapeHtml(u.reason)+'</span>'; }
            return '<div class="ccm-sugg-card" data-user="'+u.id+'">'+
                '<button type="button" class="ccm-sugg-close" onclick="event.stopPropagation();ccmSuggDismiss(this)"><i class="ri-close-line"></i></button>'+
                '<a class="ccm-sugg-card-link" href="profile.php?user_id='+u.id+'" onclick="event.stopPropagation()">'+
                    '<img src="'+(u.profile_pic||'uploads/me.jpeg')+'" onerror="this.src=\'uploads/me.jpeg\'">'+
                    '<span class="ccm-sugg-card-name">'+u.username+'</span>'+
                    metaHtml+
                '</a>'+
                '<button class="ccm-sugg-card-track '+(following?'tracking':'')+'" data-user="'+u.id+'" '+
                        'onclick="event.stopPropagation();ccmToggleSuggTrack(this)">'+(following?'Untrack':'Track')+'</button>'+
            '</div>';
        }).join('');
        pagesHtml += '<div class="ccm-sugg-page" data-page="'+pg+'"><div class="ccm-sugg-grid2">'+cardsHtml+'</div></div>';
    }

    var dotsHtml = '';
    if(pageCount > 1){
        for(var d = 0; d < pageCount; d++){
            dotsHtml += '<span class="ccm-sugg-dot'+(d===0?' active':'')+'" onclick="event.stopPropagation();ccmSuggGoToPage(this,'+d+')"></span>';
        }
    }
    var arrowsHtml = pageCount > 1
        ? '<div class="ccm-sugg-arrow ccm-sugg-arrow-left ccm-sugg-arrow-disabled" onclick="event.stopPropagation();ccmSuggPrev(this)"><i class="ri-arrow-left-s-line"></i></div>'+
          '<div class="ccm-sugg-arrow ccm-sugg-arrow-right" onclick="event.stopPropagation();ccmSuggNext(this)"><i class="ri-arrow-right-s-line"></i></div>'
        : '';

    return (
        '<div class="ccm-sugg-slide">'+
            '<div class="ccm-sugg-bg">'+bgTilesHtml(0)+'</div>'+
            '<div class="ccm-sugg-overlay"></div>'+
            '<div class="ccm-sugg-header">'+
                '<button class="ccm-icon-btn ccm-back-btn" onclick="closeCaptionModal()"></button>'+
                '<div class="ccm-sugg-header-text">'+
                    '<div class="ccm-sugg-title">Suggested for you</div>'+
                    '<div class="ccm-sugg-subtitle">Accounts you might like</div>'+
                '</div>'+
                '<span class="ccm-sugg-header-spacer"></span>'+
            '</div>'+
            '<div class="ccm-sugg-pages" data-page-count="'+pageCount+'" data-current="0">'+pagesHtml+'</div>'+
            arrowsHtml+
            (pageCount > 1 ? '<div class="ccm-sugg-dots">'+dotsHtml+'</div>' : '')+
        '</div>'
    );
}

function ccmSuggGoToPage(ctx, idx){
    var root = _ccmSuggRoot(ctx);
    if(!root) return;
    var pagesEl = root.querySelector('.ccm-sugg-pages');
    var dotsEl  = root.querySelector('.ccm-sugg-dots');
    if(!pagesEl) return;
    var pageCount = parseInt(pagesEl.dataset.pageCount || '1', 10);
    if(idx < 0) idx = 0;
    if(idx > pageCount - 1) idx = pageCount - 1;

    pagesEl.style.transition = 'transform .32s cubic-bezier(.22,1,.36,1)';
    pagesEl.style.transform  = 'translateX(-'+(idx*100)+'%)';
    pagesEl.dataset.current  = idx;

    if(dotsEl){
        dotsEl.querySelectorAll('.ccm-sugg-dot').forEach(function(dot, i){
            dot.classList.toggle('active', i === idx);
        });
    }

    var bgEl   = root.querySelector('.ccm-sugg-bg');
    var tiles  = bgEl ? bgEl.querySelectorAll('.ccm-sugg-bg-tile') : [];
    var pageEl = pagesEl.querySelector('.ccm-sugg-page[data-page="'+idx+'"]');
    var cards  = pageEl ? pageEl.querySelectorAll('.ccm-sugg-card') : [];
    tiles.forEach(function(tile, t){
        var card = cards.length ? cards[t % cards.length] : null;
        var img  = card ? card.querySelector('img') : null;
        if(img) tile.style.backgroundImage = "url('"+img.getAttribute('src')+"')";
    });

    var leftArrow  = root.querySelector('.ccm-sugg-arrow-left');
    var rightArrow = root.querySelector('.ccm-sugg-arrow-right');
    if(leftArrow)  leftArrow.classList.toggle('ccm-sugg-arrow-disabled', idx === 0);
    if(rightArrow) rightArrow.classList.toggle('ccm-sugg-arrow-disabled', idx === pageCount - 1);
}

function ccmSuggNext(ctx){
    var root = _ccmSuggRoot(ctx);
    var pagesEl = root ? root.querySelector('.ccm-sugg-pages') : null;
    if(!pagesEl) return;
    ccmSuggGoToPage(ctx, parseInt(pagesEl.dataset.current||'0',10)+1);
}
function ccmSuggPrev(ctx){
    var root = _ccmSuggRoot(ctx);
    var pagesEl = root ? root.querySelector('.ccm-sugg-pages') : null;
    if(!pagesEl) return;
    ccmSuggGoToPage(ctx, parseInt(pagesEl.dataset.current||'0',10)-1);
}

function _ccmInitSuggSwipe(root){
    if(!root) return;
    var pagesEl = root.querySelector('.ccm-sugg-pages');
    if(!pagesEl || pagesEl._ccmSwipeBound) return;
    var pageCount = parseInt(pagesEl.dataset.pageCount || '1', 10);
    if(pageCount <= 1) return;
    pagesEl._ccmSwipeBound = true;

    var startX=0, startY=0, dragging=false, horizontal=false;

    pagesEl.addEventListener('touchstart', function(e){
        startX = e.touches[0].clientX; startY = e.touches[0].clientY;
        dragging = true; horizontal = false;
        pagesEl.style.transition = 'none';
    }, {passive:true});

    pagesEl.addEventListener('touchmove', function(e){
        if(!dragging) return;
        var current = parseInt(pagesEl.dataset.current || '0', 10);
        var dx = e.touches[0].clientX - startX;
        var dy = e.touches[0].clientY - startY;
        if(!horizontal && Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 8) horizontal = true;
        if(horizontal){
            e.stopPropagation();
            pagesEl.style.transform = 'translateX(calc(-'+(current*100)+'% + '+dx+'px))';
        }
    }, {passive:true});

    pagesEl.addEventListener('touchend', function(e){
        if(!dragging) return;
        dragging = false;
        var current = parseInt(pagesEl.dataset.current || '0', 10);
        if(horizontal){
            e.stopPropagation();
            var dx = e.changedTouches[0].clientX - startX;
            if(Math.abs(dx) > 50){
                if(dx < 0 && current < pageCount - 1) current++;
                else if(dx > 0 && current > 0) current--;
            }
        }
        ccmSuggGoToPage(pagesEl, current);
    }, {passive:true});
}

function ccmSuggDismiss(btn){
    var card = btn.closest('.ccm-sugg-card');
    if(card) card.classList.add('dismissed');
}

function ccmToggleSuggTrack(btn){
    var userId = btn.dataset.user;
    fetch('text_feed.php', {
        method:  'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body:    'toggle_follow=1&user_id='+userId
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        if(data.status === 'success'){
            if(data.following){ btn.textContent='Untrack'; btn.classList.add('tracking'); }
            else               { btn.textContent='Track';  btn.classList.remove('tracking'); }
        }
    });
}

/* ════════════════════════════════════════════════════════════
   GAPLESS VERTICAL TRACK ENGINE
   ════════════════════════════════════════════════════════════ */
function _ccmSlideItem(idx){
    if(idx < 0 || idx >= ccmFeed.length) return null;
    return ccmFeed[idx];
}

function _ccmRenderItemHtml(item, isPreview){
    if(!item) return '';
    if(item.type === 'post') return _ccmRenderPost(item.data, isPreview);
    if(item.type === 'ad')   return _ccmRenderAd(item, isPreview);
    if(item.type === 'sugg') return _ccmRenderSugg(item, isPreview);
    return '';
}

function _ccmBuildSlideWrap(item, pos){
    var wrap = document.createElement('div');
    wrap.className = 'ccm-vslide';
    wrap.dataset.pos = pos;
    if(item){
        var slideEl = document.createElement('div');
        slideEl.className = 'ccm-slide';
        if(pos === 'current') slideEl.id = 'ccm-slide';
        slideEl.innerHTML = _ccmRenderItemHtml(item, pos !== 'current');
        wrap.appendChild(slideEl);
    }
    return wrap;
}

function _ccmActivateCurrentSlide(currentItem){
    _ccmClearPendingImpression();
    ccmHideSavedToast(); // switching slides — dismiss any lingering toast from the previous one

    if(currentItem && currentItem.type === 'post'){
        var capState = _ccmComputeCaptionHtml(currentItem.data);
        _ccmCaptionFullHtml  = capState.full;
        _ccmCaptionTruncHtml = capState.trunc;
    }
    _ccmCaptionExpanded = false;

    _ccmMemeId      = (currentItem && currentItem.type === 'post') ? currentItem.data.id : null;
    _ccmCurrentAdId = (currentItem && currentItem.type === 'ad')   ? currentItem.data.id  : null;

    var curSlide = document.getElementById('ccm-slide');

    if(curSlide){
        if(currentItem.type === 'ad'){
            _ccmInitAdFromSwitcher(curSlide);
            _ccmInitVbSwitcher(curSlide);
            _ccmLogAdShown(currentItem.data.id);
            _ccmActiveViewTimer = setTimeout(function(){
                _ccmLogAdImpression(currentItem.data.id);
                _ccmActiveViewTimer = null;
            }, CCM_VIEW_DWELL_MS);

            var adVideo = curSlide.querySelector('video.ccm-media');
            if(adVideo && _ccmPausedAdIds[currentItem.data.id]){
                adVideo.pause();
                var adBox = curSlide.querySelector('#ccm-media-box');
                if(adBox) ccmShowPausedControls(adBox, adVideo);
            }
        } else {
            if(_ccmAdFromSwitchTimer){ clearInterval(_ccmAdFromSwitchTimer); _ccmAdFromSwitchTimer = null; }
            if(_ccmVbSwitchTimer){ clearInterval(_ccmVbSwitchTimer); _ccmVbSwitchTimer = null; }
        }

        if(currentItem.type === 'sugg'){
            _ccmInitSuggSwipe(curSlide.querySelector('.ccm-sugg-slide'));
        }

        var textBg = curSlide.querySelector('.ccm-textonly-bg');
        if(textBg){
            ccmHideSkeleton(curSlide, true);
            requestAnimationFrame(function(){ _ccmCheckCaptionOverflow(curSlide); });
        } else if(currentItem.type === 'sugg'){
            ccmHideSkeleton(curSlide, true);
        } else {
            var mediaImg  = curSlide.querySelector('img.ccm-media');
            var activeVid = curSlide.querySelector('video.ccm-media');
            if(mediaImg && mediaImg.complete){
                ccmHideSkeleton(curSlide);
            } else if(activeVid && activeVid.readyState >= 2){
                ccmHideSkeleton(curSlide);
            }
            setTimeout(function(){ ccmHideSkeleton(curSlide); }, 2500);
        }
    } else {
        if(_ccmAdFromSwitchTimer){ clearInterval(_ccmAdFromSwitchTimer); _ccmAdFromSwitchTimer = null; }
        if(_ccmVbSwitchTimer){ clearInterval(_ccmVbSwitchTimer); _ccmVbSwitchTimer = null; }
    }
}

function _ccmMountTrack(centerIndex){
    _ccmVSwipeLocked = false;

    var inner = document.getElementById('ccm-inner');
    inner.querySelectorAll('video').forEach(function(v){ v.pause(); v.src=''; v.load(); });

    var track = document.createElement('div');
    track.className = 'ccm-vtrack';
    track.id = 'ccm-vtrack';
    track.appendChild(_ccmBuildSlideWrap(_ccmSlideItem(centerIndex - 1), 'prev'));
    track.appendChild(_ccmBuildSlideWrap(_ccmSlideItem(centerIndex),     'current'));
    track.appendChild(_ccmBuildSlideWrap(_ccmSlideItem(centerIndex + 1), 'next'));

    inner.innerHTML = '';
    inner.appendChild(track);

    _ccmIndex = centerIndex;
    _ccmActivateCurrentSlide(_ccmSlideItem(centerIndex));
}

var CCM_OVERLAY_SELECTOR = '.ccm-top-bar, .ccm-top-right-tags, .ccm-social-proof-strip, .ccm-rail, .ccm-bottom, .ccm-sugg-header, .ccm-sugg-dots, .ccm-sugg-arrow';

function _ccmOverlayEls(slideEl){
    return slideEl ? slideEl.querySelectorAll(CCM_OVERLAY_SELECTOR) : [];
}

function _ccmSetOverlayProgress(slideEl, progress, dir){
    if(!slideEl) return;
    var opacity   = 1 - (progress * 0.7);
    var translate = (dir || 0) * progress * 16;
    _ccmOverlayEls(slideEl).forEach(function(el){
        el.style.opacity   = opacity;
        el.style.transform = 'translateY('+translate+'px)';
    });
}

var _ccmVDragBound = false;
function _ccmInitVerticalDrag(){
    if(_ccmVDragBound) return;
    _ccmVDragBound = true;
    var stage = document.getElementById('ccm-stage');
    if(!stage) return;

    var startX=0, startY=0, dragging=false, committedVertical=false, decided=false, stageH=0;

    stage.addEventListener('touchstart', function(e){
        if(e.touches.length !== 1) return;
        if(_ccmVSwipeLocked) return;
        if(_ccmIsInsideExpandedCaption(e.target)) return;
        startX = e.touches[0].clientX;
        startY = e.touches[0].clientY;
        dragging = true; committedVertical = false; decided = false;
        stageH = stage.clientHeight;
        var track = document.getElementById('ccm-vtrack');
        if(track){
            track.style.transition = 'none';
            track.querySelectorAll('.ccm-vslide .ccm-slide').forEach(function(slideEl){
                _ccmOverlayEls(slideEl).forEach(function(el){ el.style.transition = 'none'; });
            });
        }
    }, {passive:true});

    stage.addEventListener('touchmove', function(e){
        if(!dragging) return;
        var dx = e.touches[0].clientX - startX;
        var dy = e.touches[0].clientY - startY;

        if(!decided){
            if(Math.abs(dy) > Math.abs(dx) && Math.abs(dy) > 10){
                committedVertical = true; decided = true;
            } else if(Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 10){
                dragging = false; decided = true; return;
            }
        }
        if(committedVertical){
            var track = document.getElementById('ccm-vtrack');
            if(track){
                var atTop    = _ccmIndex <= 0;
                var atBottom = _ccmIndex >= ccmFeed.length - 1;
                var clamped = dy;
                if(atTop && clamped > 0)    clamped = clamped * 0.35;
                if(atBottom && clamped < 0) clamped = clamped * 0.35;
                clamped = Math.max(-stageH, Math.min(stageH, clamped));
                track.style.transform = 'translateY('+clamped+'px)';

                var progress = Math.min(1, Math.abs(clamped) / (stageH * 0.35));
                var dirOut   = clamped < 0 ? -1 : (clamped > 0 ? 1 : 0);
                var curSlideEl = track.querySelector('.ccm-vslide[data-pos="current"] .ccm-slide');
                _ccmSetOverlayProgress(curSlideEl, progress, dirOut);

                var incomingPos = clamped < 0 ? 'next' : (clamped > 0 ? 'prev' : null);
                ['next','prev'].forEach(function(pos){
                    var nSlideEl = track.querySelector('.ccm-vslide[data-pos="'+pos+'"] .ccm-slide');
                    if(!nSlideEl) return;
                    if(pos === incomingPos){
                        _ccmSetOverlayProgress(nSlideEl, 1 - progress, -dirOut);
                    } else {
                        _ccmSetOverlayProgress(nSlideEl, 1, dirOut);
                    }
                });
            }
        }
    }, {passive:true});

    stage.addEventListener('touchend', function(e){
        if(!dragging) return;
        dragging = false;
        var track = document.getElementById('ccm-vtrack');
        if(!track || !committedVertical){ return; }

        var dy = e.changedTouches[0].clientY - startY;
        track.style.transition = 'transform .28s cubic-bezier(.22,1,.36,1)';

        var threshold = stageH * 0.18;
        if(dy < -threshold && _ccmIndex < ccmFeed.length - 1){
            _ccmCommitTrackMove(1);
        } else if(dy > threshold && _ccmIndex > 0){
            _ccmCommitTrackMove(-1);
        } else {
            track.style.transform = 'translateY(0)';

            var cancelTransition = 'opacity .28s cubic-bezier(.22,1,.36,1), transform .28s cubic-bezier(.22,1,.36,1)';
            var curSlideEl = track.querySelector('.ccm-vslide[data-pos="current"] .ccm-slide');
            _ccmOverlayEls(curSlideEl).forEach(function(el){ el.style.transition = cancelTransition; });
            _ccmSetOverlayProgress(curSlideEl, 0, 0);

            ['next','prev'].forEach(function(pos){
                var nSlideEl = track.querySelector('.ccm-vslide[data-pos="'+pos+'"] .ccm-slide');
                if(!nSlideEl) return;
                _ccmOverlayEls(nSlideEl).forEach(function(el){ el.style.transition = cancelTransition; });
                _ccmSetOverlayProgress(nSlideEl, 1, 0);
            });
        }
    }, {passive:true});
}

function _ccmCommitTrackMove(dir){
    if(_ccmVSwipeLocked) return;
    var track = document.getElementById('ccm-vtrack');
    if(!track) return;

    _ccmVSwipeLocked = true;

    var stageH = document.getElementById('ccm-stage').clientHeight;
    track.style.transition = 'transform .28s cubic-bezier(.22,1,.36,1)';
    track.style.transform  = 'translateY('+(dir > 0 ? -stageH : stageH)+'px)';

    var keepPos     = dir > 0 ? 'next' : 'prev';
    var curSlideEl  = track.querySelector('.ccm-vslide[data-pos="current"] .ccm-slide');
    var nextSlideEl = track.querySelector('.ccm-vslide[data-pos="'+keepPos+'"] .ccm-slide');
    var commitTransition = 'opacity .28s cubic-bezier(.22,1,.36,1), transform .28s cubic-bezier(.22,1,.36,1)';
    _ccmOverlayEls(curSlideEl).forEach(function(el){ el.style.transition = commitTransition; });
    _ccmOverlayEls(nextSlideEl).forEach(function(el){ el.style.transition = commitTransition; });
    _ccmSetOverlayProgress(curSlideEl, 1, -dir);
    _ccmSetOverlayProgress(nextSlideEl, 0, dir);

    var newIndex = _ccmIndex + dir;
    var settled = false;
    var finish = function(){
        if(settled) return;
        settled = true;
        track.removeEventListener('transitionend', onEnd);
        _ccmFinishTrackMove(dir, newIndex);
    };
    var onEnd = function(e){
        if(e.target === track && e.propertyName === 'transform') finish();
    };
    track.addEventListener('transitionend', onEnd);
    setTimeout(finish, 320);
}

function _ccmFinishTrackMove(dir, newIndex){
    var track = document.getElementById('ccm-vtrack');
    if(!track){
        _ccmVSwipeLocked = false;
        return;
    }

    var keepPos = dir > 0 ? 'next' : 'prev';
    var dropPos = dir > 0 ? 'prev' : 'next';
    var keepNode = track.querySelector('.ccm-vslide[data-pos="'+keepPos+'"]');
    var dropNode = track.querySelector('.ccm-vslide[data-pos="'+dropPos+'"]');
    var oldCurrent = track.querySelector('.ccm-vslide[data-pos="current"]');

    if(dropNode){
        dropNode.querySelectorAll('video').forEach(function(v){ v.pause(); v.src=''; v.load(); });
        dropNode.remove();
    }
    if(oldCurrent){
        oldCurrent.dataset.pos = dropPos;
        oldCurrent.querySelectorAll('video').forEach(function(v){ v.pause(); });
        var oldSlideEl = oldCurrent.querySelector('.ccm-slide');
        if(oldSlideEl) oldSlideEl.removeAttribute('id');
        if(_ccmAdFromSwitchTimer){ clearInterval(_ccmAdFromSwitchTimer); _ccmAdFromSwitchTimer = null; }
        if(_ccmVbSwitchTimer){ clearInterval(_ccmVbSwitchTimer); _ccmVbSwitchTimer = null; }
    }
    if(keepNode){
        keepNode.dataset.pos = 'current';
        var innerSlide = keepNode.querySelector('.ccm-slide');
        if(innerSlide) innerSlide.id = 'ccm-slide';
    }

    track.style.transition = 'none';
    track.style.transform  = 'translateY(0)';
    void track.offsetHeight;

    var missingIndex = keepPos === 'next' ? newIndex + 1 : newIndex - 1;
    track.appendChild(_ccmBuildSlideWrap(_ccmSlideItem(missingIndex), keepPos));

    var promotedItem = _ccmSlideItem(newIndex);
    _ccmIndex = newIndex;
    _ccmActivateCurrentSlide(promotedItem);

    _ccmVSwipeLocked = false;
}

/* ── PUBLIC API ── */
function ccmLoadIndex(index){
    if(_ccmVSwipeLocked) return;
    if(index < 0 || index >= ccmFeed.length || index === _ccmIndex) return;
    if(Math.abs(index - _ccmIndex) === 1){
        _ccmCommitTrackMove(index > _ccmIndex ? 1 : -1);
    } else {
        _ccmMountTrack(index);
    }
}

var _ccmScrollLockY = 0;
function ccmLockBodyScroll(){
    _ccmScrollLockY = window.scrollY || window.pageYOffset || 0;
    document.body.style.position = 'fixed';
    document.body.style.top      = '-' + _ccmScrollLockY + 'px';
    document.body.style.left     = '0';
    document.body.style.right    = '0';
    document.body.style.width    = '100%';
    document.body.style.overflow = 'hidden';
}
function ccmUnlockBodyScroll(){
    document.body.style.position = '';
    document.body.style.top      = '';
    document.body.style.left     = '';
    document.body.style.right    = '';
    document.body.style.width    = '';
    document.body.style.overflow = '';
    window.scrollTo(0, _ccmScrollLockY);
}

function openCaptionModal(id){
    if(ccmFeed.length === 0) _ccmBuildFeed();
    var idx = ccmFeed.findIndex(function(s){
        return s.type === 'post' && String(s.data.id) === String(id);
    });
    var targetIdx = idx >= 0 ? idx : 0;

    _ccmVSwipeLocked = false;
    _ccmMountTrack(targetIdx);
    _ccmInitVerticalDrag();

    document.getElementById('cardCaptionModal').classList.add('ccm-open');
    ccmLockBodyScroll();
}

function closeCaptionModal(){
    document.getElementById('ccm-inner').querySelectorAll('video').forEach(function(v){ v.pause(); v.src=''; });
    _ccmClearPendingImpression();
    if(_ccmAdFromSwitchTimer){ clearInterval(_ccmAdFromSwitchTimer); _ccmAdFromSwitchTimer = null; }
    if(_ccmVbSwitchTimer){ clearInterval(_ccmVbSwitchTimer); _ccmVbSwitchTimer = null; }
    ccmHideSavedToast();
    document.getElementById('cardCaptionModal').classList.remove('ccm-open');
    ccmUnlockBodyScroll();
    _ccmMemeId       = null;
    _ccmCurrentAdId  = null;
    _ccmVSwipeLocked = false;
}

/* ────────────────────────────────────────────────────────────
   ACTION HANDLER (like / repost / fav)
   UPDATED (fav): shows/hides the "Saved · Collections" toast,
   with rollback on failure and reconciliation against the real
   server-confirmed state.
──────────────────────────────────────────────────────────── */
function ccmAction(type){
    if(!_ccmMemeId) return;
    var memeId = _ccmMemeId;

    var actionParam = { like:'ajax_like', repost:'ajax_repost', fav:'ajax_favorite' };
    var stateKey     = { like:'liked',    repost:'reposted',    fav:'favorited'      };
    var btnMap       = { like:'ccm-like-btn', repost:'ccm-repost-btn', fav:'ccm-fav-btn' };
    var cntMap       = { like:'ccm-like-count', repost:'ccm-repost-count', fav:'ccm-fav-count' };
    var cntField     = { like:'likeCount', repost:'repostCount', fav:'favCount' };
    var flagField    = { like:'liked', repost:'reposted', fav:'faved' };
    var feedSel      = { like:'.likeBtn', repost:'.repostBtn', fav:'.favBtn' };

    var slideEl = document.getElementById('ccm-slide');
    var cmBtn  = slideEl ? slideEl.querySelector('#'+btnMap[type]) : null;
    var cmSpan = slideEl ? slideEl.querySelector('#'+cntMap[type]) : null;

    var wasActive       = cmBtn ? cmBtn.classList.contains('active') : false;
    var optimisticNext  = !wasActive;
    var prevCountText   = cmSpan ? cmSpan.textContent : '0';
    var prevCountNum    = parseInt(prevCountText, 10) || 0;
    var optimisticCount = optimisticNext ? prevCountNum + 1 : Math.max(0, prevCountNum - 1);

    if(cmBtn)  cmBtn.classList.toggle('active', optimisticNext);
    if(cmSpan) cmSpan.textContent = optimisticCount;
    if(cmBtn){
        var actionIcon = cmBtn.querySelector('i');
        if(actionIcon){
            cmBtn.classList.remove('arc-swap-active');
            void actionIcon.offsetWidth; // force reflow so rapid re-taps restart the animation
            cmBtn.classList.add('arc-swap-active');
            actionIcon.addEventListener('animationend', function handler(){
                cmBtn.classList.remove('arc-swap-active');
                actionIcon.removeEventListener('animationend', handler);
            });
        }
    }

    // NEW: optimistic toast on saving to favorites
    if(type === 'fav'){
        if(optimisticNext) ccmShowSavedToast(memeId);
        else ccmHideSavedToast();
    }

    fetch('text_feed.php', {
        method:  'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body:    actionParam[type]+'=1&meme_id='+memeId
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        var count = data.count;
        if(count === undefined){
            if(cmBtn)  cmBtn.classList.toggle('active', wasActive);
            if(cmSpan) cmSpan.textContent = prevCountText;
            if(type === 'fav' && optimisticNext) ccmHideSavedToast(); // rollback
            return;
        }
        var isNowActive = !!data[stateKey[type]];
        if(cmBtn)  cmBtn.classList.toggle('active', isNowActive);
        if(cmSpan) cmSpan.textContent = count;

        var feedBtn = document.querySelector(feedSel[type]+'[data-id="'+memeId+'"]');
        if(feedBtn){
            var color = isNowActive
                ? (type==='fav' ? '#f61f1f' : '#ff4c4c')
                : 'rgb(255, 255, 255)';
            var feedIcon = feedBtn.querySelector('i');
            if(feedIcon) feedIcon.style.color = color;
            var feedSpan = feedBtn.querySelector('span');
            if(feedSpan) feedSpan.textContent = count;
        }

        var feedItem = ccmFeed.find(function(s){
            return s.type === 'post' && String(s.data.id) === String(memeId);
        });
        if(feedItem){
            feedItem.data[cntField[type]]  = count;
            feedItem.data[flagField[type]] = isNowActive;
        }

        // reconcile toast with real server state if it disagreed with our guess
        if(type === 'fav' && isNowActive !== optimisticNext){
            if(isNowActive) ccmShowSavedToast(memeId);
            else ccmHideSavedToast();
        }
    })
    .catch(function(err){
        console.error('ccmAction failed:', err);
        if(cmBtn)  cmBtn.classList.toggle('active', wasActive);
        if(cmSpan) cmSpan.textContent = prevCountText;
        if(type === 'fav' && optimisticNext) ccmHideSavedToast();
    });
}

/* ────────────────────────────────────────────────────────────
   DESKTOP WHEEL + KEYBOARD NAV — vertical paging delegates to
   ccmLoadIndex; horizontal wheel/arrow keys page the suggested-
   user carousel (text posts have no horizontal content).
──────────────────────────────────────────────────────────── */
document.getElementById('cardCaptionModal').addEventListener('wheel', function(e){
    if(_ccmIsInsideExpandedCaption(e.target)) return;
    e.preventDefault();

    var item = ccmFeed[_ccmIndex];
    if(item && item.type === 'sugg' && Math.abs(e.deltaX) > Math.abs(e.deltaY)){
        var slideElS = document.getElementById('ccm-slide');
        var suggRoot = slideElS ? slideElS.querySelector('.ccm-sugg-slide') : null;
        if(e.deltaX > 0) ccmSuggNext(suggRoot); else ccmSuggPrev(suggRoot);
        return;
    }

    if(e.deltaY > 0) ccmLoadIndex(_ccmIndex+1);
    else             ccmLoadIndex(_ccmIndex-1);
}, { passive:false });

if(!window._ccmKeydownBound){
    window._ccmKeydownBound = true;

    document.addEventListener('keydown', function(e){
        var modal = document.getElementById('cardCaptionModal');
        if(!modal || !modal.classList.contains('ccm-open')) return;

        if(e.key === 'Escape'){ closeCaptionModal(); return; }
        if(e.key === 'ArrowDown'){ ccmLoadIndex(_ccmIndex+1); return; }
        if(e.key === 'ArrowUp'){ ccmLoadIndex(_ccmIndex-1); return; }

        if(e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;

        var item = ccmFeed[_ccmIndex];
        if(!item || item.type !== 'sugg') return;

        e.preventDefault();
        var slideElS = document.getElementById('ccm-slide');
        var suggRoot = slideElS ? slideElS.querySelector('.ccm-sugg-slide') : null;
        if(e.key === 'ArrowLeft') ccmSuggPrev(suggRoot); else ccmSuggNext(suggRoot);
    });
}

window.addEventListener('resize', function(){
    var slide = document.getElementById('ccm-slide');
    if(slide) _ccmCheckCaptionOverflow(slide);
});

/* ────────────────────────────────────────────────────────────
   SHARE MODAL — external apps + follow-list picker → shares table + chat message
   (same backend endpoints as home.php: home.php?type=following for the
   people list, home.php action=share_post to actually send the shares)
──────────────────────────────────────────────────────────── */
var _shareMemeId   = null;
var _shareAllUsers = [];
var _shareSelected = new Set();

function _shareGetLink(){
    return window.location.origin + '/home.php?meme_id=' + _shareMemeId;
}

function _shareGetCaption(){
    if(!_shareMemeId) return '';
    var item = ccmFeed.find(function(s){
        return s.type === 'post' && String(s.data.id) === String(_shareMemeId);
    });
    return item && item.data.caption ? item.data.caption : 'Check this out on Zeems';
}

function openShareModal(memeId){
    _shareMemeId   = memeId;
    _shareSelected = new Set();
    document.getElementById('shareSearchInput').value = '';
    document.getElementById('shareModalList').innerHTML = '<div class="share-modal-empty">Loading…</div>';
    updateShareFooter();

    var nativeBtn = document.getElementById('shareNativeBtn');
    if(nativeBtn) nativeBtn.style.display = (navigator.share ? 'flex' : 'none');

    document.getElementById('shareModalOverlay').classList.add('show');
    document.getElementById('shareModalPanel').classList.add('show');
    document.body.style.overflow = 'hidden';

    fetch('home.php?type=following')
        .then(function(r){ return r.json(); })
        .then(function(users){
            _shareAllUsers = users || [];
            renderShareList(_shareAllUsers);
        })
        .catch(function(){
            document.getElementById('shareModalList').innerHTML =
                '<div class="share-modal-empty">Couldn\'t load your tracked people</div>';
        });
}

function closeShareModal(){
    document.getElementById('shareModalOverlay').classList.remove('show');
    document.getElementById('shareModalPanel').classList.remove('show');
    document.body.style.overflow = '';
    _shareMemeId = null;
}

/* ── EXTERNAL APP SHARE ACTIONS ── */
function shareViaCopyLink(){
    var link = _shareGetLink();
    navigator.clipboard.writeText(link).then(function(){
        showToast('🔗 Link copied');
    }).catch(function(){
        var ta = document.createElement('textarea');
        ta.value = link;
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); showToast('🔗 Link copied'); } catch(e){ showToast('Could not copy link'); }
        ta.remove();
    });
}

function shareViaWhatsApp(){
    var text = encodeURIComponent(_shareGetCaption() + ' — ' + _shareGetLink());
    window.open('https://wa.me/?text=' + text, '_blank');
}

function shareViaTelegram(){
    var url  = encodeURIComponent(_shareGetLink());
    var text = encodeURIComponent(_shareGetCaption());
    window.open('https://t.me/share/url?url=' + url + '&text=' + text, '_blank');
}

function shareViaTwitter(){
    var text = encodeURIComponent(_shareGetCaption());
    var url  = encodeURIComponent(_shareGetLink());
    window.open('https://twitter.com/intent/tweet?text=' + text + '&url=' + url, '_blank');
}

function shareViaFacebook(){
    var url = encodeURIComponent(_shareGetLink());
    window.open('https://www.facebook.com/sharer/sharer.php?u=' + url, '_blank');
}

function shareViaEmail(){
    var subject = encodeURIComponent('Check this out on Zeems');
    var body    = encodeURIComponent(_shareGetCaption() + '\n\n' + _shareGetLink());
    window.location.href = 'mailto:?subject=' + subject + '&body=' + body;
}

function shareViaNative(){
    if(!navigator.share) return;
    navigator.share({
        title: 'Zeems',
        text:  _shareGetCaption(),
        url:   _shareGetLink()
    }).catch(function(){ /* user cancelled — no-op */ });
}

function renderShareList(users){
    var list = document.getElementById('shareModalList');
    if(!users.length){
        list.innerHTML = '<div class="share-modal-empty">You aren\'t tracking anyone yet</div>';
        return;
    }
    list.innerHTML = users.map(function(u){
        var pic = u.profile_pic || 'uploads/me.jpeg';
        var sel = _shareSelected.has(String(u.id));
        return '<div class="share-user-row'+(sel?' selected':'')+'" data-uid="'+u.id+'" '+
            'onclick="toggleShareUser('+u.id+', this)">'+
            '<img src="'+pic+'" onerror="this.src=\'uploads/me.jpeg\'">'+
            '<span class="share-user-name">'+u.username+'</span>'+
            '<span class="share-user-check"><i class="ri-check-line"></i></span>'+
        '</div>';
    }).join('');
}

function toggleShareUser(userId, el){
    var key = String(userId);
    if(_shareSelected.has(key)){ _shareSelected.delete(key); el.classList.remove('selected'); }
    else { _shareSelected.add(key); el.classList.add('selected'); }
    updateShareFooter();
}

function updateShareFooter(){
    var n = _shareSelected.size;
    document.getElementById('shareSelCount').textContent = n + ' selected';
    document.getElementById('shareSendBtn').disabled = n === 0;
}

function filterShareList(){
    var q = document.getElementById('shareSearchInput').value.toLowerCase();
    var filtered = _shareAllUsers.filter(function(u){
        return u.username.toLowerCase().includes(q);
    });
    renderShareList(filtered);
}

function sendShare(){
    if(!_shareMemeId || _shareSelected.size === 0) return;
    var btn = document.getElementById('shareSendBtn');
    btn.disabled = true;
    btn.textContent = 'Sending…';

    var ids = Array.from(_shareSelected).join(',');

    fetch('home.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=share_post&meme_id='+_shareMemeId+'&receiver_ids='+encodeURIComponent(ids)
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        btn.disabled = false;
        btn.textContent = 'Send';
        if(data.status === 'ok'){
            var countEl = document.getElementById('ccm-share-count');
            if(countEl) countEl.textContent = data.count;

            var feedShareBtn = document.querySelector('.shareBtn[data-id="'+_shareMemeId+'"]');
            if(feedShareBtn){
                var fc = feedShareBtn.querySelector('span');
                if(fc) fc.textContent = data.count;
            }

            var feedItem = ccmFeed.find(function(s){
                return s.type === 'post' && String(s.data.id) === String(_shareMemeId);
            });
            if(feedItem) feedItem.data.shareCount = data.count;

            showToast('Shared with '+data.sent_to.length+' '+(data.sent_to.length===1?'person':'people'));
            closeShareModal();
        } else {
            showToast(data.message || 'Share failed');
        }
    })
    .catch(function(){
        btn.disabled = false;
        btn.textContent = 'Send';
        showToast('Network error');
    });
}

/* ── Small toast helper (falls back to this if the page doesn't
   already define its own showToast) ── */
if(typeof window.showToast !== 'function'){
    window.showToast = function(msg){
        var existing = document.getElementById('peura-toast');
        if(existing) existing.remove();

        var t = document.createElement('div');
        t.id = 'peura-toast';
        t.textContent = msg;
        Object.assign(t.style, {
            position:     'fixed',
            bottom:       '90px',
            left:         '50%',
            transform:    'translateX(-50%)',
            background:   'rgba(20,20,25,.95)',
            border:       '1px solid rgba(255,45,45,.3)',
            color:        '#fff',
            padding:      '8px 20px',
            borderRadius: '50px',
            fontSize:     '12px',
            zIndex:       '9999999',
            pointerEvents:'none',
            boxShadow:    '0 8px 24px rgba(0,0,0,.6)',
            transition:   'opacity .3s',
            whiteSpace:   'nowrap'
        });
        document.body.appendChild(t);
        setTimeout(function(){
            t.style.opacity = '0';
            setTimeout(function(){ t.remove(); }, 300);
        }, 2200);
    };
}

/* ── Init once the page's inline data scripts have all run ── */
document.addEventListener('DOMContentLoaded', function(){ _ccmBuildFeed(); });

/* ── Scroll / swipe / keyboard navigation ── */
document.getElementById('cardCaptionModal').addEventListener('wheel', function(e){
    if(_ccmIsInsideExpandedCaption(e.target)) return; // let the caption scroll natively, don't page

    e.preventDefault();
    if(e.deltaY > 0) ccmLoadIndex(_ccmIndex+1);
    else             ccmLoadIndex(_ccmIndex-1);
}, { passive:false });

var _ccmTouchStartY = 0;
var ccmTouchInCaption = false;

document.getElementById('cardCaptionModal').addEventListener('touchstart', function(e){
    ccmTouchInCaption = _ccmIsInsideExpandedCaption(e.target);
    _ccmTouchStartY = e.touches[0].clientY;
}, { passive:true });

/* Non-passive touchmove is what actually stops the page behind the modal
   from scrolling while a finger drags inside it. Elements meant to keep
   their own internal scroll (suggested-users panel, expanded caption)
   are allowlisted through so they still scroll normally. */
document.getElementById('cardCaptionModal').addEventListener('touchmove', function(e){
    if(e.target.closest('.ccm-panel-slide, .ccm-center-caption-text, .ccm-caption-wrap')) return;
    e.preventDefault();
}, { passive:false });

document.getElementById('cardCaptionModal').addEventListener('touchend', function(e){
    if(ccmTouchInCaption){ ccmTouchInCaption = false; return; } // was scrolling the caption, not paging
    var diff = _ccmTouchStartY - e.changedTouches[0].clientY;
    if(Math.abs(diff) > 40){
        if(diff > 0) ccmLoadIndex(_ccmIndex+1);
        else         ccmLoadIndex(_ccmIndex-1);
    }
}, { passive:true });

document.addEventListener('keydown', function(e){
    if(document.getElementById('cardCaptionModal').classList.contains('ccm-open')){
        if(e.key === 'Escape')    closeCaptionModal();
        if(e.key === 'ArrowDown') ccmLoadIndex(_ccmIndex+1);
        if(e.key === 'ArrowUp')   ccmLoadIndex(_ccmIndex-1);
    }
});

window.addEventListener('resize', function(){
    var slide = document.getElementById('ccm-slide');
    if(slide && ccmFeed[_ccmIndex] && ccmFeed[_ccmIndex].type === 'post'){
        _ccmCheckCaptionOverflow(slide);
    }
});
</script>

<div id="svshOverlay" class="svsh-overlay" onclick="svshClose()"></div>
<div id="svshPanel" class="svsh-panel">
    <div class="svsh-screens" id="svshScreens">

        <!-- SCREEN 1: main sheet -->
        <div class="svsh-screen">
            <div class="svsh-handle"></div>

            <div class="svsh-section-label">Add to a collection</div>

            <div class="svsh-row svsh-create-row" onclick="svshOpenCreate()">
                <i class="ri-folder-add-line svsh-row-icon"></i>
                <div class="svsh-row-text">
                    <div class="svsh-row-title">Create a new collection</div>
                    <div class="svsh-row-sub">Save posts to a new collection</div>
                </div>
                <i class="ri-arrow-right-s-line svsh-chevron"></i>
            </div>

            <div class="svsh-collections-list" id="svshCollectionsList"></div>
        </div>

        <!-- SCREEN 2: create collection -->
        <div class="svsh-screen">
            <div class="svsh-create-topbar">
                <button class="svsh-back-btn" onclick="svshBackToMain()"><i class="ri-arrow-left-s-line"></i></button>
                <div class="svsh-create-title">Create a collection</div>
                <button class="svsh-done-btn" id="svshDoneBtn" onclick="svshCreateCollection()">Done</button>
            </div>

            <div class="svsh-create-body">
                <input type="text" id="svshNameInput" class="svsh-name-input"
                       placeholder="Collection name" maxlength="60" oninput="svshValidateName()">
                <div class="svsh-required-hint" id="svshRequiredHint">Required</div>

                <div class="svsh-toggle-row">
                    <div class="svsh-toggle-text">
                        <div class="svsh-toggle-title">Set the collection to public</div>
                        <div class="svsh-toggle-sub">Anyone can view this collection</div>
                    </div>
                    <label class="svsh-switch">
                        <input type="checkbox" id="svshPublicToggle">
                        <span class="svsh-switch-slider"></span>
                    </label>
                </div>
            </div>
        </div>

    </div>
</div>
<style>
.svsh-overlay{
    position:fixed;inset:0;background:rgba(0,0,0,0.6);
    backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);
    z-index:999999996;opacity:0;pointer-events:none;
    transition:opacity 0.25s ease;
}
.svsh-overlay.show{opacity:1;pointer-events:all;}

.svsh-panel{
    position:fixed;left:0;right:0;bottom:0;
    background:rgba(12,12,28,0.98);
    backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);
    border-top:1px solid rgba(255,255,255,0.08);
    border-radius:20px 20px 0 0;
    z-index:999999997;
    overflow:hidden;
    max-height:82vh;
    display:flex;flex-direction:column;
    transform:translateY(100%);
    transition:transform 0.3s cubic-bezier(0.32,0.72,0,1);
    box-shadow:0 -10px 40px rgba(0,0,0,0.5);
    font-family:Poppins,sans-serif;
}
.svsh-panel.show{transform:translateY(0);}

.svsh-screens{
    display:flex;width:200%;
    transition:transform .3s cubic-bezier(.32,.72,0,1);
}
.svsh-panel.svsh-create-mode .svsh-screens{ transform:translateX(-50%); }

.svsh-screen{
    width:50%;
    flex-shrink:0;
    padding:8px 0 max(10px, env(safe-area-inset-bottom));
    max-height:82vh;
    overflow-y:auto;
    box-sizing:border-box;
    -webkit-overflow-scrolling:touch;
}
.svsh-screen::-webkit-scrollbar{width:4px;}
.svsh-screen::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.15);border-radius:999px;}

.svsh-handle{
    width:34px;height:3.5px;border-radius:999px;
    background:rgba(255,255,255,0.2);
    margin:0 auto 4px;flex-shrink:0;
}

.svsh-section-label{
    font-size:13px;font-weight:700;color:#fff;
    padding:12px 18px 6px;
}

/* ── ROW ITEMS — matched to idx-sheet-item ── */
.svsh-row{
    display:flex;align-items:center;gap:11px;
    padding:9px 18px;
    color:#fff;text-decoration:none;
    font-size:13px;font-weight:500;
    font-family:Poppins,sans-serif;
    cursor:pointer;
    -webkit-tap-highlight-color:transparent;
    transition:background 0.15s ease;
    border-bottom:1px solid rgba(255,255,255,0.06);
}
.svsh-row:active{background:rgba(255,255,255,0.06);}
.svsh-row-icon{
    font-size:15.5px;color:#f24455;width:18px;text-align:center;flex-shrink:0;
}

.svsh-row-text{ flex:1; min-width:0; }
.svsh-row-title{
    font-size:13px;font-weight:600;color:#fff;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
.svsh-row-sub{
    font-size:11px;color:rgba(255,255,255,0.45);
    margin-top:2px;display:flex;align-items:center;gap:4px;
}

.svsh-chevron{ font-size:18px; color:rgba(255,255,255,0.25); flex-shrink:0; }

.svsh-collections-list{ margin-top:0; }
.svsh-col-thumb{
    width:34px;height:34px;flex-shrink:0;border-radius:9px;
    object-fit:cover;background:rgba(255,255,255,0.08);
}
.svsh-col-thumb-empty{
    display:flex;align-items:center;justify-content:center;
    color:#f24455;font-size:16px;
}

.svsh-add-btn{
    width:26px;height:26px;flex-shrink:0;border-radius:50%;
    border:1.5px solid rgba(255,255,255,0.22);
    display:flex;align-items:center;justify-content:center;
    color:#fff;font-size:15px;
    transition:all .15s ease;
    -webkit-tap-highlight-color:transparent;
}
.svsh-add-btn.svsh-added{
    background:#f24455;border-color:#f24455;
}

.svsh-loading{
    text-align:center;padding:24px;color:rgba(255,255,255,0.35);font-size:13px;
}

/* ── CREATE SCREEN ── */
.svsh-create-topbar{
    display:flex;align-items:center;justify-content:space-between;
    padding:6px 18px 14px;
    border-bottom:1px solid rgba(255,255,255,0.06);
}
.svsh-back-btn{
    width:30px;height:30px;border-radius:50%;
    background:rgba(255,255,255,0.06);border:none;color:#fff;
    display:flex;align-items:center;justify-content:center;
    font-size:19px;cursor:pointer;-webkit-tap-highlight-color:transparent;
    transition:background .18s;
}
.svsh-back-btn:hover{background:rgba(255,45,45,0.18);color:#ff6060;}
.svsh-create-title{ font-size:13.5px;font-weight:700;color:#fff; }
.svsh-done-btn{
    background:none;border:none;color:#f24455;
    font-size:13px;font-weight:700;cursor:pointer;padding:6px 2px;
    font-family:Poppins,sans-serif;-webkit-tap-highlight-color:transparent;
}
.svsh-done-btn:disabled{ opacity:.4; }

.svsh-create-body{ padding:16px 18px 10px; }
.svsh-name-input{
    width:100%;padding:13px 15px;
    background:rgba(255,255,255,0.045);
    border:1px solid rgba(255,255,255,0.09);
    border-radius:13px;color:#fff;font-size:13.5px;
    font-family:Poppins,sans-serif;outline:none;box-sizing:border-box;
    transition:border-color .2s, background .2s;
}
.svsh-name-input::placeholder{ color:rgba(255,255,255,0.22); }
.svsh-name-input:focus{
    border-color:rgba(255,45,45,0.4);
    background:rgba(255,45,45,0.04);
}

.svsh-required-hint{
    font-size:10.5px;color:rgba(255,255,255,0.25);
    margin:7px 2px 20px;
}
.svsh-required-hint.svsh-error{ color:#ff6060; }

.svsh-toggle-row{
    display:flex;align-items:center;justify-content:space-between;
    gap:14px;padding:6px 2px;
}
.svsh-toggle-title{ font-size:13px;font-weight:600;color:#fff; }
.svsh-toggle-sub{ font-size:10.5px;color:rgba(255,255,255,0.35);margin-top:2px; }

.svsh-switch{ position:relative;width:44px;height:26px;flex-shrink:0; }
.svsh-switch input{ opacity:0;width:0;height:0; }
.svsh-switch-slider{
    position:absolute;inset:0;background:rgba(255,255,255,0.15);
    border-radius:999px;cursor:pointer;transition:.2s;
}
.svsh-switch-slider::before{
    content:'';position:absolute;width:20px;height:20px;left:3px;top:3px;
    background:#fff;border-radius:50%;transition:.2s;
}
.svsh-switch input:checked + .svsh-switch-slider{ background:#f24455; }
.svsh-switch input:checked + .svsh-switch-slider::before{ transform:translateX(18px); }

/* Desktop: centered modal, same as idx-sheet */
@media (min-width:791px){
    .svsh-panel{
        left:50%;right:auto;bottom:auto;top:50%;
        width:100%;max-width:340px;
        border-radius:18px;
        transform:translate(-50%,-40%) scale(0.95);
        opacity:0;
        pointer-events:none;
        max-height:70vh;
    }
    .svsh-panel.show{
        transform:translate(-50%,-50%) scale(1);
        opacity:1;
        pointer-events:all;
    }
    .svsh-handle{display:none;}
}

/* 790px — TABLET */
@media (max-width:790px){
    .svsh-panel{
        border-radius:18px 18px 0 0;
        max-height:78vh;
    }
    .svsh-screen{ padding:7px 0 max(9px, env(safe-area-inset-bottom)); }
    .svsh-handle{ width:32px;height:3.5px;margin:0 auto 4px; }
    .svsh-section-label{ padding:11px 16px 5px; font-size:12.5px; }
    .svsh-row{ padding:8px 16px; font-size:12.5px; gap:10px; }
    .svsh-row-icon{ font-size:15px; width:17px; }
    .svsh-col-thumb{ width:32px;height:32px;border-radius:8px; }
    .svsh-add-btn{ width:25px;height:25px;font-size:14px; }
    .svsh-create-topbar{ padding:5px 16px 12px; }
    .svsh-create-body{ padding:14px 16px 9px; }
}

/* 490px — LARGE MOBILE */
@media (max-width:490px){
    .svsh-panel{
        border-radius:16px 16px 0 0;
        max-height:76vh;
        backdrop-filter:blur(16px);
        -webkit-backdrop-filter:blur(16px);
    }
    .svsh-screen{ padding:6px 0 max(8px, env(safe-area-inset-bottom)); }
    .svsh-handle{ width:30px;height:3px;margin:0 auto 3px; }
    .svsh-section-label{ padding:10px 14px 5px; font-size:12px; }
    .svsh-row{ padding:7px 14px; font-size:12px; gap:9px; }
    .svsh-row-icon{ font-size:14px; width:16px; }
    .svsh-col-thumb{ width:30px;height:30px;border-radius:8px; }
    .svsh-add-btn{ width:24px;height:24px;font-size:13px; }
    .svsh-create-topbar{ padding:5px 14px 11px; }
    .svsh-back-btn{ width:27px;height:27px;font-size:17px; }
    .svsh-create-title{ font-size:13px; }
    .svsh-create-body{ padding:13px 14px 8px; }
    .svsh-name-input{ font-size:13px;padding:12px 14px;border-radius:12px; }
}

/* 390px — STANDARD MOBILE */
@media (max-width:390px){
    .svsh-panel{
        border-radius:14px 14px 0 0;
        max-height:74vh;
        backdrop-filter:blur(14px);
        -webkit-backdrop-filter:blur(14px);
        box-shadow:0 -8px 30px rgba(0,0,0,0.45);
    }
    .svsh-screen{ padding:6px 0 max(7px, env(safe-area-inset-bottom)); }
    .svsh-handle{ width:28px;height:3px;margin:0 auto 3px; }
    .svsh-section-label{ padding:9px 12px 4px; font-size:11.5px; }
    .svsh-row{ padding:6.5px 12px; font-size:11.5px; gap:8px; }
    .svsh-row-icon{ font-size:13.5px; width:15px; }
    .svsh-col-thumb{ width:28px;height:28px;border-radius:7px; }
    .svsh-add-btn{ width:22px;height:22px;font-size:12px; }
    .svsh-create-topbar{ padding:4px 12px 10px; }
    .svsh-back-btn{ width:25px;height:25px;font-size:16px; }
    .svsh-create-title{ font-size:12.5px; }
    .svsh-create-body{ padding:12px 12px 7px; }
    .svsh-name-input{ font-size:12.5px;padding:11px 13px;border-radius:11px; }
}

/* 330px — SMALL / COMPACT MOBILE */
@media (max-width:330px){
    .svsh-panel{
        border-radius:12px 12px 0 0;
        max-height:72vh;
        backdrop-filter:blur(12px);
        -webkit-backdrop-filter:blur(12px);
        box-shadow:0 -6px 24px rgba(0,0,0,0.4);
    }
    .svsh-screen{ padding:5px 0 max(6px, env(safe-area-inset-bottom)); }
    .svsh-handle{ width:24px;height:2.5px;margin:0 auto 3px; }
    .svsh-screen::-webkit-scrollbar{width:3px;}
    .svsh-section-label{ padding:8px 10px 4px; font-size:11px; }
    .svsh-row{ padding:5.5px 10px; font-size:10.5px; gap:7px; }
    .svsh-row-icon{ font-size:12.5px; width:13px; }
    .svsh-col-thumb{ width:26px;height:26px;border-radius:6px; }
    .svsh-add-btn{ width:20px;height:20px;font-size:11px; }
    .svsh-create-topbar{ padding:4px 10px 8px; }
    .svsh-back-btn{ width:23px;height:23px;font-size:14px; }
    .svsh-create-title{ font-size:11.5px; }
    .svsh-create-body{ padding:10px 10px 6px; }
    .svsh-name-input{ font-size:11.5px;padding:10px 12px;border-radius:10px; }
}
</style>

<script>
/* Point this at wherever collections_api.php lives relative to the
   current page — same file works on home.php, profile.php, etc. */
const SVSH_ENDPOINT = 'collections_api.php';

let svshMemeId    = null;
let svshCollections = [];
let svshOnChange  = null; // optional callback(state) so the calling page can sync its own button/counts

function svshFetch(body){
    return fetch(SVSH_ENDPOINT, {
        method:  'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body:    body
    }).then(function(r){ return r.json(); });
}

function openSaveModal(memeId, onChange){
    svshMemeId   = memeId;
    svshOnChange = onChange || null;

    document.getElementById('svshPanel').classList.remove('svsh-create-mode');
    document.getElementById('svshCollectionsList').innerHTML = '<div class="svsh-loading">Loading…</div>';

    document.getElementById('svshOverlay').classList.add('show');
    document.getElementById('svshPanel').classList.add('show');
    document.body.style.overflow = 'hidden';

    svshFetch('col_action=get_my_collections&meme_id='+memeId).then(function(d){
        if(d.status !== 'ok') return;
        svshCollections = d.collections || [];
        svshRenderCollections();
    }).catch(function(){
        document.getElementById('svshCollectionsList').innerHTML =
            '<div class="svsh-loading">Couldn\'t load collections</div>';
    });
}

function svshClose(){
    document.getElementById('svshOverlay').classList.remove('show');
    document.getElementById('svshPanel').classList.remove('show');
    document.body.style.overflow = '';
    setTimeout(function(){
        svshMemeId = null;
        document.getElementById('svshPanel').classList.remove('svsh-create-mode');
        document.getElementById('svshNameInput').value = '';
        document.getElementById('svshPublicToggle').checked = false;
    }, 280);
}

function svshRenderCollections(){
    var list = document.getElementById('svshCollectionsList');
    if(!svshCollections.length){ list.innerHTML = ''; return; }

    list.innerHTML = svshCollections.map(function(c){
        var thumb = c.thumb
            ? '<img src="'+c.thumb+'" class="svsh-col-thumb" onerror="this.src=\'uploads/Text.jpg\'">'
            : '<div class="svsh-col-thumb svsh-col-thumb-empty"><i class="ri-folder-3-line"></i></div>';
        var inCol = !!c.in_collection;
        return '<div class="svsh-row svsh-col-row" onclick="svshToggleCollection('+c.id+')">'+
            thumb+
            '<div class="svsh-row-text">'+
                '<div class="svsh-row-title">'+svshEsc(c.name)+'</div>'+
                '<div class="svsh-row-sub"><i class="'+(c.is_public==1?'ri-earth-line':'ri-lock-line')+'"></i> '+
                    (c.is_public==1?'Public':'Only me')+'</div>'+
            '</div>'+
            '<div class="svsh-add-btn '+(inCol?'svsh-added':'')+'" id="svshAddBtn-'+c.id+'">'+
                '<i class="'+(inCol?'ri-check-line':'ri-add-line')+'"></i>'+
            '</div>'+
        '</div>';
    }).join('');
}

function svshToggleCollection(colId){
    if(!svshMemeId) return;
    var col = svshCollections.find(function(c){ return c.id == colId; });
    if(!col) return;

    var wasIn = !!col.in_collection;
    col.in_collection = !wasIn;
    svshUpdateAddBtn(colId, col.in_collection);

    var action = wasIn ? 'remove_from_collection' : 'add_to_collection';
    svshFetch('col_action='+action+'&col_id='+colId+'&meme_id='+svshMemeId).then(function(d){
        if(d.status !== 'ok' && d.status !== 'already'){
            col.in_collection = wasIn;
            svshUpdateAddBtn(colId, wasIn);
            return;
        }
        if(d.item_count !== undefined) col.item_count = d.item_count;
        svshToast(col.in_collection ? 'Added to '+col.name : 'Removed from '+col.name);
        if(svshOnChange) svshOnChange({ type:'collection', colId:colId, inCollection:col.in_collection });
    }).catch(function(){ col.in_collection = wasIn; svshUpdateAddBtn(colId, wasIn); });
}

function svshUpdateAddBtn(colId, inCol){
    var btn = document.getElementById('svshAddBtn-'+colId);
    if(!btn) return;
    btn.classList.toggle('svsh-added', inCol);
    btn.innerHTML = '<i class="'+(inCol?'ri-check-line':'ri-add-line')+'"></i>';
}

function svshOpenCreate(){
    document.getElementById('svshPanel').classList.add('svsh-create-mode');
    document.getElementById('svshNameInput').value = '';
    document.getElementById('svshPublicToggle').checked = false;
    document.getElementById('svshRequiredHint').classList.remove('svsh-error');
    setTimeout(function(){ document.getElementById('svshNameInput').focus(); }, 260);
}
function svshBackToMain(){
    document.getElementById('svshPanel').classList.remove('svsh-create-mode');
}
function svshValidateName(){
    document.getElementById('svshRequiredHint').classList.remove('svsh-error');
}

function svshCreateCollection(){
    var name = document.getElementById('svshNameInput').value.trim();
    if(!name){ document.getElementById('svshRequiredHint').classList.add('svsh-error'); return; }

    var isPublic = document.getElementById('svshPublicToggle').checked ? 1 : 0;
    var btn = document.getElementById('svshDoneBtn');
    btn.disabled = true;

    svshFetch('col_action=create_collection&name='+encodeURIComponent(name)+'&is_public='+isPublic+'&meme_id='+(svshMemeId||0))
    .then(function(d){
        btn.disabled = false;
        if(d.status !== 'ok'){ svshToast('Could not create collection'); return; }
        svshCollections.unshift({
            id: d.id, name: d.name, is_public: d.is_public,
            item_count: svshMemeId ? 1 : 0, thumb: null, in_collection: !!svshMemeId
        });
        svshRenderCollections();
        svshBackToMain();
        svshToast('Collection "'+d.name+'" created ✓');
        if(svshOnChange) svshOnChange({ type:'created', colId:d.id });
    }).catch(function(){ btn.disabled = false; svshToast('Network error'); });
}

function svshEsc(s){
    if(!s) return '';
    var div = document.createElement('div');
    div.textContent = s;
    return div.innerHTML;
}

function svshToast(msg){
    if(typeof showToast === 'function'){ showToast(msg); return; } // reuse page's toast if present
    var t = document.createElement('div');
    t.textContent = msg;
    t.style.cssText = 'position:fixed;bottom:90px;left:50%;transform:translateX(-50%);'+
        'background:rgba(20,20,25,.95);color:#fff;padding:8px 20px;border-radius:50px;'+
        'font-size:12px;z-index:999999999;';
    document.body.appendChild(t);
    setTimeout(function(){ t.remove(); }, 2200);
}
</script> 


<!-- ENGAGEMENT MODAL -->
<div id="eng-modal" onclick="closeEngModal()" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.85);z-index:10000;align-items:center;justify-content:center;">
    <div style="background:transparent;backdrop-filter:blur(24px);border-radius:18px;width:90%;max-width:420px;max-height:80vh;overflow:hidden;border:1px solid rgba(255,255,255,0.1);display:flex;flex-direction:column;box-shadow:0 24px 60px rgba(0,0,0,0.8);" onclick="event.stopPropagation()">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid rgba(255,255,255,0.08);flex-shrink:0;">
            <span style="color:white;font-weight:700;font-size:0.9rem;">Engagement</span>
            <button onclick="closeEngModal()" style="background:transparent;border:none;color:rgba(255,255,255,0.4);font-size:1.1rem;cursor:pointer;width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;transition:background 0.2s;" onmouseover="this.style.background='rgba(255,45,45,0.15)'" onmouseout="this.style.background='transparent'">✕</button>
        </div>
        <div style="padding:10px 14px;border-bottom:1px solid rgba(255,255,255,0.06);flex-shrink:0;">
            <input id="eng-search" type="text" placeholder="Search users..." oninput="filterEngList()" style="width:100%;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);border-radius:10px;padding:8px 13px;color:white;font-size:0.8rem;outline:none;font-family:var(--font-body);">
        </div>
        <div style="display:flex;gap:8px;padding:10px 14px;border-bottom:1px solid rgba(255,255,255,0.06);flex-shrink:0;">
            <button id="pill-likes" class="eng-pill active-pill" onclick="switchEngTab('likes')"><i class="ri-heart-3-fill"></i> Liked</button>
            <button id="pill-reposts" class="eng-pill" onclick="switchEngTab('reposts')"><i class="ri-repeat-line"></i> Reposted</button>
        </div>
        <div id="eng-modal-list" style="overflow-y:auto;flex:1;"></div>
    </div>
</div>

<!-- SCREEN TIME BREAK OVERLAY -->
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

<!-- FLOATING BUTTONS — paste just before </body> -->
<a href="messages.php" id="floatMsgBtn" aria-label="Messages">
    <i class="ri-wechat-line"></i>
</a>

<style>
/* ── FLOATING BTN — mobile only ── */
#floatMsgBtn {
    display: none;
}

@media (max-width: 760px) {
    #floatMsgBtn {
        display: flex;
        align-items: center;
        justify-content: center;
        position: fixed;
        right: 18px;
        width: 52px;
        height: 52px;
        border-radius: 50%;
        background: #f70d0d;
        color: #fff;
        font-size: 24px;
        text-decoration: none;
        z-index: 99999;
        border: 1.5px solid rgba(255, 255, 255, 0.96);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        -webkit-tap-highlight-color: transparent;
    }

    #floatMsgBtn { bottom: 75px; }

    #floatMsgBtn:hover {
        transform: scale(1.1);
        box-shadow: 0 6px 24px rgba(150, 30, 20, 0.65);
    }
    #floatMsgBtn:active {
        transform: scale(0.93);
    }
}

@media (max-width: 480px) {
    #floatMsgBtn {
        width: 46px;
        height: 46px;
        font-size: 21px;
        right: 14px;
    }
    #floatMsgBtn { bottom: 68px; }
}

@media (max-width: 360px) {
    #floatMsgBtn {
        width: 46px;
        height: 46px;
        font-size: 21px;
        right: 14px;
    }
    #floatMsgBtn { bottom: 68px; }
}

@media (max-width: 320px) {
    #floatMsgBtn {
        width: 44px;
        height: 44px;
        font-size: 20px;
        right: 12px;
    }
    #floatMsgBtn { bottom: 66px; }
}
</style>

<!-- RIGHT PANEL -->
<div class="right-panel glass collapsed" id="sidePanel">
    <button id="closePanel" class="close-btn">✕</button>
    <div class="panel-content">
        <div class="profile-card-inner" onclick="location.href='profile.php'">
            <img src="<?php echo !empty($user['profile_pic'])?$user['profile_pic']:'uploads/me.jpeg';?>" onerror="this.src='uploads/me.jpeg';">
            <div>
                <b style="font-size:14px;display:flex;align-items:center;gap:4px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24"><circle cx="12" cy="12" r="12" fill="#FF0000"/><path d="M9 12l2 2 4-4" stroke="white" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <?php echo $user['username'];?>
                </b>
                <span style="font-size:11px;color:rgba(255,255,255,0.4);">@<?php echo $user['username'];?>_<span style="color:#ff0000;">MX</span></span>
            </div>
        </div>

        <div class="post-box">
            <textarea id="postText" rows="3" placeholder="Share something..."></textarea>
            <div id="hashtagTip" style="color:rgba(100,160,255,0.8);font-size:11px;">Tip: Add a #hashtag at the end or it won't publish</div>
            <button id="publishBtn" type="button">Publish</button>
        </div>

        <h4 style="font-size:11px;font-weight:700;letter-spacing:1px;color:rgba(255,255,255,0.35);text-transform:uppercase;margin:18px 0 10px 2px;">Your Posts</h4>
        <div class="your-posts-vertical">
        <?php
        $your_posts=$conn->query("SELECT * FROM memes WHERE uploaded_by=$current_user AND type='text' ORDER BY created_at DESC");
        while($yp=$your_posts->fetch_assoc()):
        ?>
            <div class="your-post-card" data-id="<?php echo $yp['id'];?>">
                <span style="font-size:10px;color:rgba(255,255,255,0.3);"><?php echo date("M d, Y H:i",strtotime($yp['created_at']));?></span>
                <div style="margin-top:5px;font-size:13px;"><?php echo nl2br(htmlspecialchars($yp['caption']));?></div>
                <button class="delete-post-btn" style="margin-top:8px;background:rgba(255,45,45,0.15);border:1px solid rgba(255,45,45,0.3);padding:4px 12px;border-radius:8px;color:#f24455;cursor:pointer;font-size:11px;font-weight:600;font-family:var(--font-body);transition:all 0.2s;" onmouseover="this.style.background='rgba(255,45,45,0.25)'" onmouseout="this.style.background='rgba(255,45,45,0.15)'">Delete</button>
            </div>
        <?php endwhile;?>
        </div>
    </div>
</div>

<?php
$js_ads = [];
$conn->query("
    CREATE TABLE IF NOT EXISTS `ad_dismissals` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT UNSIGNED NOT NULL,
        `sponsorship_id` INT UNSIGNED NOT NULL,
        UNIQUE KEY `uniq_dismiss` (`user_id`,`sponsorship_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");
$ads_stmt = $conn->prepare("
    SELECT s.*,
        u.id AS poster_id, u.username AS poster_username, u.profile_pic AS poster_pic,
        (SELECT COUNT(*) FROM ad_likes WHERE ad_likes.sponsorship_id = s.id) AS like_count,
        (SELECT COUNT(*) FROM ad_likes WHERE ad_likes.sponsorship_id = s.id AND ad_likes.user_id = ?) AS user_liked,
        (SELECT COUNT(*) FROM ad_favorites WHERE ad_favorites.sponsorship_id = s.id) AS fav_count,
        (SELECT COUNT(*) FROM ad_favorites WHERE ad_favorites.sponsorship_id = s.id AND ad_favorites.user_id = ?) AS user_faved,
        (SELECT COUNT(*) FROM followers WHERE follower_id = ? AND following_id = u.id) AS is_following
    FROM sponsorships s
    JOIN users u ON u.id = s.user_id
    WHERE s.status = 'approved'
      AND s.id NOT IN (SELECT sponsorship_id FROM ad_dismissals WHERE user_id = ?)
    ORDER BY RAND() LIMIT 10
");
$ads_stmt->bind_param("iiii", $current_user, $current_user, $current_user, $current_user);
$ads_stmt->execute();
$ads_res2 = $ads_stmt->get_result();
while($ad = $ads_res2->fetch_assoc()){
    $ext = strtolower(pathinfo($ad['meme_image'], PATHINFO_EXTENSION));
    $js_ads[] = [
        'id'              => (int)$ad['id'],
        'image'           => $ad['image'],
        'title'           => htmlspecialchars($ad['title']),
        'type'            => htmlspecialchars($ad['type']),
        'meme_image'      => $ad['meme_image'],
        'caption'         => htmlspecialchars($ad['caption']),
        'link'            => htmlspecialchars($ad['link']),
        'ext'             => $ext,
        'user_id'         => (int)$ad['poster_id'],
        'poster_username' => htmlspecialchars($ad['poster_username']),
        'poster_pic'      => !empty($ad['poster_pic']) ? $ad['poster_pic'] : 'uploads/me.jpeg',
        'like_count'      => (int)$ad['like_count'],
        'user_liked'      => (int)$ad['user_liked'],
        'fav_count'       => (int)$ad['fav_count'],
        'user_faved'      => (int)$ad['user_faved'],
        'is_following'    => (int)$ad['is_following'],  
    ];
}

$js_sugg = $current_user ? getSuggestedUsers($conn, $current_user, 20) : [];
?>

<script>
window.cmAds = <?php echo json_encode($js_ads);?>;
window.cmSuggUsers = <?php echo json_encode($js_sugg);?>;

/* ─────────────────────────────────────
   SKELETON → FEED REVEAL
───────────────────────────────────── */
window.addEventListener('load', function(){
    setTimeout(function(){
        var sk = document.getElementById('skeletonFeed');
        var fd = document.getElementById('feed');
        if(sk) { sk.style.transition='opacity 0.4s'; sk.style.opacity='0'; setTimeout(function(){ sk.style.display='none'; },400); }
        if(fd) { fd.style.display='flex'; fd.style.opacity='0'; fd.style.transition='opacity 0.5s'; setTimeout(function(){ fd.style.opacity='1'; },50); }
    }, 600);
});


/* ─────────────────────────────────────
   TAG SCROLL ARROWS
───────────────────────────────────── */
(function(){
    var bar=document.getElementById('tagScrollbar');
    var l=document.getElementById('tagLeft');
    var r=document.getElementById('tagRight');
    if(l&&r&&bar){
        l.addEventListener('click',function(){ bar.scrollBy({left:-200,behavior:'smooth'}); });
        r.addEventListener('click',function(){ bar.scrollBy({left:200,behavior:'smooth'}); });
    }
})();

/* ─────────────────────────────────────
   PANEL TOGGLE
───────────────────────────────────── */
(function(){
    var panel=document.getElementById('sidePanel');
    var menuBtn=document.getElementById('menuToggle');
    var closeBtn=document.getElementById('closePanel');
    menuBtn.addEventListener('click',function(e){ e.stopPropagation(); panel.classList.toggle('collapsed'); menuBtn.classList.toggle('active'); });
    closeBtn.addEventListener('click',function(){ panel.classList.add('collapsed'); menuBtn.classList.remove('active'); });
    document.addEventListener('click',function(e){ if(!panel.contains(e.target)&&!menuBtn.contains(e.target)){ panel.classList.add('collapsed'); menuBtn.classList.remove('active'); } });
})();

/* ─────────────────────────────────────
   GLOBAL KEBAB
───────────────────────────────────── */
(function(){
    var btn=document.getElementById('globalKebabBtn');
    var menu=document.getElementById('globalKebabMenu');
    btn.addEventListener('click',function(e){ e.stopPropagation(); menu.classList.toggle('show'); });
    document.addEventListener('click',function(){ menu.classList.remove('show'); });
})();

/* ─────────────────────────────────────
   FOLLOW BTNS
───────────────────────────────────── */
document.querySelectorAll('.follow-btn').forEach(function(btn){
    btn.addEventListener('click',function(e){
        e.stopPropagation();
        var userId=this.dataset.user; var button=this; button.disabled=true;
        fetch('text_feed.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'toggle_follow=1&user_id='+userId})
        .then(function(r){return r.json();})
        .then(function(data){
            if(data.status==='success'){
                if(data.following){ button.textContent='UnTrack'; button.classList.add('following'); }
                else{ button.textContent='Track'; button.classList.remove('following'); }
            }
            button.disabled=false;
        }).catch(function(){ button.disabled=false; });
    });
});

/* ─────────────────────────────────────
   SUGGESTED TRACK
───────────────────────────────────── */
function toggleSuggTrack(btn){
    var userId=btn.dataset.user; btn.disabled=true;
    fetch('text_feed.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'toggle_follow=1&user_id='+userId})
    .then(function(r){return r.json();})
    .then(function(data){
        if(data.status==='success'){
            if(data.following){ btn.textContent='Untrack'; btn.classList.add('tracking'); }
            else{ btn.textContent='Track'; btn.classList.remove('tracking'); }
        }
        btn.disabled=false;
    }).catch(function(){ btn.disabled=false; });
}

/* ─────────────────────────────────────
   LIKE / REPOST / FAV
───────────────────────────────────── */
document.querySelectorAll('.likeBtn').forEach(function(btn){
    btn.addEventListener('click',function(){
        var id=btn.dataset.id;
        fetch('text_feed.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'ajax_like=1&meme_id='+id})
        .then(function(r){return r.json();}).then(function(data){
            btn.querySelector('i').style.color=data.liked?'#ff4c4c':'rgb(255, 255, 255)';
            btn.querySelector('span').textContent=data.count;

            /* keep ccmFeed + the caption modal (if open on this post) in sync,
               otherwise the modal shows stale data until a full page refresh */
            var feedItem = ccmFeed.find(function(s){ return s.type==='post' && String(s.data.id)===String(id); });
            if(feedItem){
                feedItem.data.likeCount = data.count;
                feedItem.data.liked     = data.liked;
            }
            if(String(_ccmMemeId) === String(id)){
                var mBtn = document.getElementById('ccm-like-btn');
                var mCnt = document.getElementById('ccm-like-count');
                if(mBtn) mBtn.classList.toggle('active', !!data.liked);
                if(mCnt) mCnt.textContent = data.count;
            }
        });
    });
});
document.querySelectorAll('.repostBtn').forEach(function(btn){
    btn.addEventListener('click',function(){
        var id=btn.dataset.id;
        fetch('text_feed.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'ajax_repost=1&meme_id='+id})
        .then(function(r){return r.json();}).then(function(data){
            btn.querySelector('i').style.color=data.reposted?'rgb(247,43,43)':'rgb(255, 255, 255)';
            btn.querySelector('span').textContent=data.count;

            var feedItem = ccmFeed.find(function(s){ return s.type==='post' && String(s.data.id)===String(id); });
            if(feedItem){
                feedItem.data.repostCount = data.count;
                feedItem.data.reposted    = data.reposted;
            }
            if(String(_ccmMemeId) === String(id)){
                var mBtn = document.getElementById('ccm-repost-btn');
                var mCnt = document.getElementById('ccm-repost-count');
                if(mBtn) mBtn.classList.toggle('active', !!data.reposted);
                if(mCnt) mCnt.textContent = data.count;
            }
        });
    });
});
document.querySelectorAll('.favBtn').forEach(function(btn){
    btn.addEventListener('click',function(){
        var id=btn.dataset.id;
        fetch('text_feed.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'ajax_favorite=1&meme_id='+id})
        .then(function(r){return r.json();}).then(function(data){
            btn.querySelector('i').style.color=data.favorited?'#f61f1f':'rgb(255, 255, 255)';
            btn.querySelector('span').textContent=data.count;

            var feedItem = ccmFeed.find(function(s){ return s.type==='post' && String(s.data.id)===String(id); });
            if(feedItem){
                feedItem.data.favCount = data.count;
                feedItem.data.faved    = data.favorited;
            }
            if(String(_ccmMemeId) === String(id)){
                var mBtn = document.getElementById('ccm-fav-btn');
                var mCnt = document.getElementById('ccm-fav-count');
                if(mBtn) mBtn.classList.toggle('active', !!data.favorited);
                if(mCnt) mCnt.textContent = data.count;
            }
        });
    });
});

/* ─────────────────────────────────────
   DELETE
───────────────────────────────────── */
document.querySelectorAll('.delete-post-btn').forEach(function(btn){
    btn.addEventListener('click',function(){
        if(!confirm('Delete this post?')) return;
        var card=btn.closest('.your-post-card'); var id=card.dataset.id;
        fetch('text_feed.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'ajax_delete_post=1&meme_id='+id})
        .then(function(r){return r.json();}).then(function(data){
            if(data.deleted){ card.remove(); var mc=document.querySelector(".post-card[data-id='"+id+"']"); if(mc) mc.remove(); }
        });
    });
});

/* ─────────────────────────────────────
   PUBLISH
───────────────────────────────────── */
(function(){
    var btn=document.getElementById('publishBtn');
    var text=document.getElementById('postText');
    var tip=document.getElementById('hashtagTip');
    btn.addEventListener('click',async function(){
        var value=text.value.trim();
        if(!/#[a-zA-Z0-9_]+$/.test(value)){ tip.textContent='Add a #hashtag at the END before publishing'; tip.style.color='#ff4c4c'; return; }
        btn.disabled=true;
        var res=await fetch(window.location.href,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'post_text=1&text='+encodeURIComponent(value)});
        var data=await res.text();
        if(data.includes('success')){ text.value=''; tip.textContent='Posted successfully ✓'; tip.style.color='#4caf50'; }
        else{ tip.textContent=data; tip.style.color='#ff4c4c'; }
        btn.disabled=false;
    });
})();

/* ─────────────────────────────────────
   SEARCH
───────────────────────────────────── */
document.getElementById('searchBtn').addEventListener('click',function(){
    var val=document.getElementById('searchInput').value.trim();
    window.location.href=val?'text_feed.php?q='+encodeURIComponent(val):'text_feed.php';
});
document.getElementById('searchInput').addEventListener('keydown',function(e){ if(e.key==='Enter'){ e.preventDefault(); document.getElementById('searchBtn').click(); } });
(function(){
    var si=document.getElementById('searchInput');
    var sr=document.getElementById('searchResults');
    si.addEventListener('input',function(){
        var val=si.value.trim();
        if(val.length>=2){
            fetch('text_feed.php?ajax_search='+encodeURIComponent(val)).then(function(r){return r.json();}).then(function(data){
                sr.innerHTML='';
                if(data.length>0){
                    data.forEach(function(u){
                        var div=document.createElement('div'); div.className='search-result-item';
                        div.innerHTML='<img src="'+(u.profile_pic||'uploads/me.jpeg')+'"><span><svg xmlns="http://www.w3.org/2000/svg" width="9" height="9" viewBox="0 0 24 24"><circle cx="12" cy="12" r="12" fill="#FF0000"/><path d="M9 12l2 2 4-4" stroke="white" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg> '+u.username+'</span>';
                        div.onclick=function(){ window.location='profile.php?user_id='+u.id; };
                        sr.appendChild(div);
                    });
                    sr.style.display='block';
                } else {
                    sr.innerHTML='<div style="color:rgba(255,255,255,0.35);padding:14px 16px;font-size:12px;">No users found</div>';
                    sr.style.display='block';
                }
            }).catch(function(){ sr.style.display='none'; });
        } else { sr.style.display='none'; }
    });
    document.addEventListener('click',function(e){ if(!e.target.closest('.search-box')) sr.style.display='none'; });
})();


/* ─────────────────────────────────────
   ACTIONS
───────────────────────────────────── */
function copyCaption(caption){ navigator.clipboard.writeText(caption).then(function(){ showToast('Caption copied!'); }).catch(function(){ var el=document.createElement('textarea'); el.value=caption; document.body.appendChild(el); el.select(); document.execCommand('copy'); document.body.removeChild(el); showToast('Caption copied!'); }); }
function notInterested(memeId,el){ var card=el.closest('.post-card')||el.closest('.card-unit')||el.closest('.card'); fetch(window.location.pathname,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=not_interested&meme_id='+memeId}).then(function(){ if(card){ card.style.transition='opacity 0.4s,transform 0.4s'; card.style.opacity='0'; card.style.transform='scale(0.96)'; setTimeout(function(){ card.remove(); },400); } }); }
function showMemeQR(memeId){ var url=window.location.origin+'/Peura/index.php?meme='+memeId; var qrUrl='https://api.qrserver.com/v1/create-qr-code/?size=200x200&data='+encodeURIComponent(url); var win=window.open('','_blank','width=260,height=280'); win.document.write('<body style="margin:0;background:#000;display:flex;align-items:center;justify-content:center;height:100vh;"><img src="'+qrUrl+'" style="border-radius:12px;"></body>'); }
function shareMeme(memeId){ var url=window.location.origin+'/Peura/index.php?meme='+memeId; if(navigator.share){ navigator.share({title:'Check this on PEURA',url:url}); } else { navigator.clipboard.writeText(url); showToast('Link copied!'); } }
function blockUser(userId){ if(!confirm('Block this user?')) return; fetch('text_feed.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=block_user&target_id='+userId}).then(function(r){return r.json();}).then(function(data){ if(data.status==='ok'){ document.querySelectorAll('.post-card[data-uploader="'+userId+'"]').forEach(function(card){ card.style.transition='opacity 0.4s,transform 0.4s'; card.style.opacity='0'; card.style.transform='scale(0.95)'; setTimeout(function(){ card.remove(); },400); }); } }); }
function showToast(msg){ var ex=document.getElementById('peura-toast'); if(ex) ex.remove(); var t=document.createElement('div'); t.id='peura-toast'; t.textContent=msg; document.body.appendChild(t); setTimeout(function(){ t.style.opacity='0'; setTimeout(function(){ t.remove(); },300); },2200); }

/* ─────────────────────────────────────
   ENGAGEMENT MODAL
───────────────────────────────────── */
var currentEngMemeId=null,currentEngTab='likes',currentEngUsers=[];
function openEngModal(memeId){ event.stopPropagation(); currentEngMemeId=memeId; currentEngTab='likes'; document.getElementById('eng-modal').style.display='flex'; document.getElementById('eng-search').value=''; document.getElementById('pill-likes').classList.add('active-pill'); document.getElementById('pill-reposts').classList.remove('active-pill'); loadEngTab('likes'); }
function switchEngTab(type){ currentEngTab=type; document.getElementById('pill-likes').classList.toggle('active-pill',type==='likes'); document.getElementById('pill-reposts').classList.toggle('active-pill',type==='reposts'); document.getElementById('eng-search').value=''; loadEngTab(type); }
function loadEngTab(type){ var list=document.getElementById('eng-modal-list'); list.innerHTML='<div style="color:rgba(255,255,255,0.4);padding:20px;text-align:center;font-size:13px;">Loading...</div>'; fetch('get_engagement.php?meme_id='+currentEngMemeId+'&type='+type).then(function(r){return r.json();}).then(function(users){ currentEngUsers=users; renderEngList(users); }); }
function renderEngList(users){ var list=document.getElementById('eng-modal-list'); list.innerHTML=''; if(users.length===0){ list.innerHTML='<div style="color:rgba(255,255,255,0.3);padding:20px;text-align:center;font-size:13px;">No one yet</div>'; return; } users.forEach(function(u){ var pic=u.profile_pic||'uploads/me.jpeg'; list.innerHTML+='<div style="display:flex;align-items:center;gap:12px;padding:10px 16px;border-bottom:1px solid rgba(255,255,255,0.05);"><img src="'+pic+'" onerror="this.src=\'uploads/me.jpeg\'" style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid rgb(252, 9, 9);flex-shrink:0;"><span style="color:white;font-weight:500;font-size:0.82rem;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">'+u.username+'</span><a href="profile.php?user_id='+u.id+'" style="padding:5px 13px;border-radius:20px;background:transparent;border:1px solid rgba(255,43,43,0.5);color:#f24455;font-size:0.7rem;font-weight:600;text-decoration:none;white-space:nowrap;flex-shrink:0;font-family:var(--font-body);">View</a></div>'; }); }
function filterEngList(){ var q=document.getElementById('eng-search').value.toLowerCase(); renderEngList(currentEngUsers.filter(function(u){ return u.username.toLowerCase().includes(q); })); }
function closeEngModal(){ document.getElementById('eng-modal').style.display='none'; }


/* ─────────────────────────────────────
   SCREEN TIME BREAK
───────────────────────────────────── */
(function(){
    const LIMIT_MS  = 60 * 60 * 1000; // 1 min for testing (change to 1 * 60 * 1000 for 1 hour) to (60 * 60 * 1000 for 1 hour)
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
</script>
</body>
</html>