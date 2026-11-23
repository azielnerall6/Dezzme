<?php
session_start();
include 'config.php';
$current_user = $_SESSION['user_id'] ?? 0;

// ── NAV PIC ──
$nav_pic = 'uploads/mee.jpeg';
if ($current_user) {
    $nav_row = $conn->query("SELECT profile_pic FROM users WHERE id=$current_user LIMIT 1")->fetch_assoc();
    if (!empty($nav_row['profile_pic'])) {
        $nav_pic = $nav_row['profile_pic'];
    }
}


/* ── SEARCH AUTOCOMPLETE AJAX ── */
if (isset($_GET['type']) && $_GET['type'] === 'autocomplete') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    $results = [];

    if (strlen($q) >= 1) {
        $like = "%$q%";

  /* ── PROFILE ID SEARCH ── */
$isDZSearch = false;
$dzLike     = '';

if (preg_match('/^dz-?([a-zA-Z0-9]*)/i', $q, $m)) {
    $isDZSearch = true;
    $digits     = strtoupper($m[1]);
    // Even if digits is empty (user just typed "DZ" or "DZ-"), do a broad search
    $dzLike = 'DZ-' . $digits . '%';
} elseif (strlen($q) >= 3) {
    // Raw partial digits e.g. "10E34A"
    $isDZSearch = true;
    $dzLike     = 'DZ-%' . strtoupper($q) . '%';
}

if ($isDZSearch) {
    $stmt0 = $conn->prepare("
        SELECT
            u.id,
            u.username,
            u.nickname,
            u.profile_pic,
            u.dz_id,
            u.bio,
            (SELECT COUNT(*) FROM followers f
             WHERE f.follower_id = ? AND f.following_id = u.id) AS is_following,
            COALESCE((SELECT us.is_fully_private FROM user_settings us
                      WHERE us.user_id = u.id LIMIT 1), 0) AS is_private,
            (SELECT COUNT(*) FROM blocks b
             WHERE b.blocker_id = ? AND b.blocked_id = u.id) AS is_blocked,
            (SELECT COUNT(*) FROM track_requests tr
             WHERE tr.requester_id = ? AND tr.target_id = u.id
             AND tr.status = 'approved') AS is_track_approved
        FROM users u
        WHERE u.dz_id LIKE ?
          AND u.id != ?
        LIMIT 4
    ");
    $stmt0->bind_param("iiisi",
        $current_user, $current_user, $current_user,
        $dzLike, $current_user
    );
    $stmt0->execute();
    $res0 = $stmt0->get_result();
    while ($row = $res0->fetch_assoc()) {
        $results[] = [
            'type'              => 'profile_id',
            'id'                => (int)$row['id'],
            'username'          => $row['username'],
            'nickname'          => $row['nickname'] ?? '',
            'profile_pic'       => $row['profile_pic'] ?? '',
            'dz_id'             => $row['dz_id'] ?? '',
            'bio'               => $row['bio'] ?? '',
            'is_following'      => (int)$row['is_following'],
            'is_private'        => (int)$row['is_private'],
            'is_blocked'        => (int)$row['is_blocked'],
            'is_track_approved' => (int)$row['is_track_approved'],
        ];
    }
    $stmt0->close();
}

        /* ── USERS ── */
        $stmt = $conn->prepare("
            SELECT
                u.id,
                u.username,
                u.nickname,
                u.profile_pic,
                u.dz_id,
                u.bio,
                (SELECT COUNT(*) FROM followers f
                 WHERE f.follower_id = ? AND f.following_id = u.id) AS is_following,
                COALESCE((SELECT us.is_fully_private FROM user_settings us
                          WHERE us.user_id = u.id LIMIT 1), 0) AS is_private,
                (SELECT COUNT(*) FROM blocks b
                 WHERE b.blocker_id = ? AND b.blocked_id = u.id) AS is_blocked,
                (SELECT COUNT(*) FROM track_requests tr
                 WHERE tr.requester_id = ? AND tr.target_id = u.id
                 AND tr.status = 'approved') AS is_track_approved
            FROM users u
            WHERE (
                u.username LIKE ?
                OR (u.nickname IS NOT NULL AND u.nickname != '' AND u.nickname LIKE ?)
            )
            AND u.id != ?
            LIMIT 6
        ");
        $stmt->bind_param("iiissi",
            $current_user, $current_user, $current_user,
            $like, $like,
            $current_user
        );
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $results[] = [
                'type'              => 'user',
                'id'                => (int)$row['id'],
                'username'          => $row['username'],
                'nickname'          => $row['nickname'] ?? '',
                'profile_pic'       => $row['profile_pic'] ?? '',
                'dz_id'             => $row['dz_id'] ?? '',
                'bio'               => $row['bio'] ?? '',
                'is_following'      => (int)$row['is_following'],
                'is_private'        => (int)$row['is_private'],
                'is_blocked'        => (int)$row['is_blocked'],
                'is_track_approved' => (int)$row['is_track_approved'],
            ];
        }
        $stmt->close();

        /* ── COMMUNITIES ── */
$stmt2 = $conn->prepare("
    SELECT
        g.id,
        g.title,
        g.group_pic,
        g.is_adult,
        ou.username AS owner_username,
        (SELECT COUNT(*) FROM group_members gm 
         WHERE gm.group_id = g.id) AS member_count,
        (SELECT COUNT(*) FROM group_members gm2 
         WHERE gm2.group_id = g.id AND gm2.user_id = ?) AS is_joined,
        EXISTS(
            SELECT 1 FROM muted_groups mg 
            WHERE mg.user_id = ? AND mg.group_id = g.id
        ) AS is_muted,
        EXISTS(
            SELECT 1 FROM group_notif_off gno 
            WHERE gno.user_id = ? AND gno.group_id = g.id
        ) AS notif_off
    FROM groups g
    LEFT JOIN users ou ON ou.id = g.created_by
    WHERE g.title LIKE ?
    LIMIT 3
");
$stmt2->bind_param("iiis", $current_user, $current_user, $current_user, $like);
$stmt2->execute();
$res2 = $stmt2->get_result();

while ($row = $res2->fetch_assoc()) {
    $results[] = [
        'type'          => 'group',
        'id'            => (int)$row['id'],
        'title'         => $row['title'],
        'group_pic'     => $row['group_pic'] ?? '',
        'owner_username'=> $row['owner_username'] ?? '',    // ← group owner
        'member_count'  => (int)$row['member_count'],
        'is_joined'     => (int)$row['is_joined'],
        'is_adult'      => (int)$row['is_adult'],           // ← NSFW flag
        'is_muted'      => (int)$row['is_muted'],           // ← Muted flag
        'notif_off'     => (int)$row['notif_off'],          // ← Notifications off
    ];
}
$stmt2->close();

        /* ── MEMES / POSTS ── */
        $stmt3 = $conn->prepare("
            SELECT m.id, m.caption, m.file_name, m.type AS meme_type,
                   u.username, u.profile_pic
            FROM memes m
            JOIN users u ON u.id = m.uploaded_by
            WHERE m.caption LIKE ?
            ORDER BY m.created_at DESC
            LIMIT 5
        ");
        $stmt3->bind_param("s", $like);
        $stmt3->execute();
        $res3 = $stmt3->get_result();
        while ($row = $res3->fetch_assoc()) {
            $results[] = [
                'type'        => 'meme',
                'id'          => (int)$row['id'],
                'caption'     => $row['caption'],
                'file_name'   => $row['file_name'],
                'meme_type'   => $row['meme_type'],
                'username'    => $row['username'],
                'profile_pic' => $row['profile_pic'] ?? '',
            ];
        }
        $stmt3->close();

        /* ── COLLECTIONS (public only) ── */
        $stmt4 = $conn->prepare("
            SELECT
                c.id,
                c.name,
                c.user_id,
                u.username AS owner_username,
                (SELECT COUNT(*) FROM collection_items ci WHERE ci.collection_id = c.id) AS item_count
            FROM collections c
            JOIN users u ON u.id = c.user_id
            WHERE c.is_public = 1
              AND c.name LIKE ?
            ORDER BY c.created_at DESC
            LIMIT 4
        ");
        $stmt4->bind_param("s", $like);
        $stmt4->execute();
        $res4 = $stmt4->get_result();
        while ($row = $res4->fetch_assoc()) {
            $results[] = [
                'type'           => 'collection',
                'id'             => (int)$row['id'],
                'name'           => $row['name'],
                'owner_id'       => (int)$row['user_id'],
                'owner_username' => $row['owner_username'],
                'item_count'     => (int)$row['item_count'],
            ];
        }
        $stmt4->close();

        /* ── SOUNDS ── */
        $stmt5 = $conn->prepare("
            SELECT
                s.id,
                s.title,
                s.user_id,
                u.username
            FROM sounds s
            JOIN users u ON u.id = s.user_id
            WHERE s.title LIKE ?
            ORDER BY s.created_at DESC
            LIMIT 4
        ");
        $stmt5->bind_param("s", $like);
        $stmt5->execute();
        $res5 = $stmt5->get_result();
        while ($row = $res5->fetch_assoc()) {
            $results[] = [
                'type'     => 'sound',
                'id'       => (int)$row['id'],
                'title'    => $row['title'],
                'user_id'  => (int)$row['user_id'],
                'username' => $row['username'],
            ];
        }
        $stmt5->close();

        /* ── SOUND FOLDERS / SOUND COLLECTIONS (public only) ── */
        $stmt6 = $conn->prepare("
            SELECT
                sf.id,
                sf.name,
                sf.user_id,
                u.username AS owner_username,
                (SELECT COUNT(*) FROM sound_folder_items sfi WHERE sfi.folder_id = sf.id) AS item_count
            FROM sound_folders sf
            JOIN users u ON u.id = sf.user_id
            WHERE sf.is_public = 1
              AND sf.name LIKE ?
            ORDER BY sf.created_at DESC
            LIMIT 3
        ");
        $stmt6->bind_param("s", $like);
        $stmt6->execute();
        $res6 = $stmt6->get_result();
        while ($row = $res6->fetch_assoc()) {
            $results[] = [
                'type'           => 'sound_collection',
                'id'             => (int)$row['id'],
                'name'           => $row['name'],
                'owner_id'       => (int)$row['user_id'],
                'owner_username' => $row['owner_username'],
                'item_count'     => (int)$row['item_count'],
            ];
        }
        $stmt6->close();

        /* ── MEMEPEDIA ENTRIES ── */
        $stmt7 = $conn->prepare("
            SELECT
                ma.id,
                ma.title,
                ma.status,
                ma.category,
                ma.summary,
                ma.media_url,
                ma.created_at,
                au.username AS author_username,
                au.profile_pic AS author_pic
            FROM meme_articles ma
            LEFT JOIN users au ON au.id = ma.created_by
            WHERE ma.title LIKE ?
            ORDER BY ma.views DESC
            LIMIT 4
        ");
        $stmt7->bind_param("s", $like);
        $stmt7->execute();
        $res7 = $stmt7->get_result();
        while ($row = $res7->fetch_assoc()) {
            $results[] = [
                'type'            => 'memepedia',
                'id'              => (int)$row['id'],
                'title'           => $row['title'],
                'status'          => $row['status'],
                'category'        => $row['category'],
                'summary'         => $row['summary'] ?? '',
                'media_url'       => $row['media_url'] ?? '',
                'created_at'      => $row['created_at'] ?? '',
                'author_username' => $row['author_username'] ?? '',
                'author_pic'      => $row['author_pic'] ?? '',
            ];
        }
        $stmt7->close();
    }

    echo json_encode($results);
    exit;
}

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

/* ── TOGGLE FOLLOW (standalone, before main POST block) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_follow' && !isset($_POST['meme_id'])) {
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
$users  = [];

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
        $uid = (int)$row['id'];
        $row['is_blocked']        = false;
        $row['is_following']      = false;
        $row['is_private']        = false;
        $row['is_track_approved'] = false;
        $row['track_req_status']  = null;

        if($current_user){
            $b_check = $conn->query("SELECT 1 FROM blocks WHERE blocker_id=$current_user AND blocked_id=$uid");
            $row['is_blocked'] = $b_check->num_rows > 0;

            $f_check = $conn->query("SELECT 1 FROM followers WHERE follower_id=$current_user AND following_id=$uid");
            $row['is_following'] = $f_check->num_rows > 0;

            $p_check = $conn->query("SELECT is_fully_private FROM user_settings WHERE user_id=$uid LIMIT 1");
            if($p_check && $p_row = $p_check->fetch_assoc())
                $row['is_private'] = !empty($p_row['is_fully_private']);

            $tr_check = $conn->query("SELECT status FROM track_requests WHERE requester_id=$current_user AND target_id=$uid LIMIT 1");
            if($tr_check && $tr_row = $tr_check->fetch_assoc()){
                $row['track_req_status']  = $tr_row['status'];
                $row['is_track_approved'] = ($tr_row['status'] === 'approved');
            }
        }
        $row['display_name'] = (!empty($row['nickname'])) ? $row['nickname'] : $row['username'];
        $users[] = $row;
    }
}

/* ================= DATA PREP (BLOCKS & MUTES) ================= */
$blocked_users        = [];
$muted_users          = [];
$muted_group_meme_ids = [];

if($current_user){

    /* ── BLOCKED USERS ── */
    $res = $conn->query("SELECT blocked_id FROM blocks WHERE blocker_id=$current_user");
    while($r = $res->fetch_assoc()){ $blocked_users[] = (int)$r['blocked_id']; }

    /* ── MUTED USERS ── */
    // Ensure the muted_users table exists (safe no-op if already created)
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
    $res = $conn->query("SELECT muted_id FROM muted_users WHERE muter_id=$current_user");
    if($res) while($r = $res->fetch_assoc()){ $muted_users[] = (int)$r['muted_id']; }

    /* ── MUTED GROUPS — collect meme IDs posted in groups the user has muted ── */
    $res = $conn->query("
        SELECT DISTINCT gp.meme_id
        FROM muted_groups mg
        JOIN group_posts gp ON gp.group_id = mg.group_id
        WHERE mg.user_id = $current_user
    ");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $muted_group_meme_ids[] = (int)$r['meme_id'];
        }
    }
}

/* ================= AI PERSONALIZATION ENGINE ================= */
$user_top_tags    = [];
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
$tag    = $_GET['tag']    ?? 'all';

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

/* ================= QUERY BUILDER ================= */
$sql = "SELECT m.*, u.username, u.profile_pic,
       (SELECT COUNT(*) FROM likes     WHERE meme_id = m.id) AS like_count,
       (SELECT COUNT(*) FROM reposts   WHERE meme_id = m.id) AS repost_count,
       (SELECT COUNT(*) FROM favorites WHERE meme_id = m.id) AS fav_count,
       (SELECT COUNT(*) FROM likes     WHERE meme_id = m.id AND user_id = $current_user) AS user_liked,
       (SELECT COUNT(*) FROM reposts   WHERE meme_id = m.id AND user_id = $current_user) AS user_reposted,
       (SELECT COUNT(*) FROM favorites WHERE meme_id = m.id AND user_id = $current_user) AS user_fav,
       (SELECT COUNT(*) FROM followers WHERE follower_id = $current_user AND following_id = m.uploaded_by) AS is_following
       FROM memes m JOIN users u ON m.uploaded_by = u.id";

$where  = " WHERE 1=1 ";
$params = [];
$types  = "";

if($tag !== 'all'){
    $where   .= " AND m.caption LIKE ? ";
    $params[] = "%#$tag%";
    $types   .= "s";
}

if(!empty($search_query)){
    $where   .= " AND m.caption LIKE ? ";
    $params[] = "%$search_query%";
    $types   .= "s";
}

/* ── BLOCKED USERS — hide their posts entirely ── */
if(!empty($blocked_users)){
    $in      = implode(',', array_fill(0, count($blocked_users), '?'));
    $where  .= " AND m.uploaded_by NOT IN ($in) ";
    $params  = array_merge($params, $blocked_users);
    $types  .= str_repeat('i', count($blocked_users));
}

/* ── MUTED USERS — hide their posts from the feed ── */
if(!empty($muted_users)){
    $in      = implode(',', array_fill(0, count($muted_users), '?'));
    $where  .= " AND m.uploaded_by NOT IN ($in) ";
    $params  = array_merge($params, $muted_users);
    $types  .= str_repeat('i', count($muted_users));
}

/* ── MUTED GROUPS — hide memes posted in muted groups ── */
if(!empty($muted_group_meme_ids)){
    $in      = implode(',', array_fill(0, count($muted_group_meme_ids), '?'));
    $where  .= " AND m.id NOT IN ($in) ";
    $params  = array_merge($params, $muted_group_meme_ids);
    $types  .= str_repeat('i', count($muted_group_meme_ids));
}

/* ── 18+ FILTER ── */
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
    $where   .= " AND m.uploaded_by IN (SELECT following_id FROM followers WHERE follower_id=?) ";
    $params[] = $current_user;
    $types   .= "i";
}

/* ================= FINAL ORDER WITH AI PERSONALIZATION BOOST ================= */
$final_order = " ORDER BY $order_logic";

if($filter === 'trending' && !empty($user_top_tags)){
    $boost_cases = [];
    foreach($user_top_tags as $t){
        $weight        = $user_tag_weights[$t] ?? 1.0;
        $multiplier    = min(2.5, round(1.0 + ($weight / 20.0), 3));
        $safe_tag      = $conn->real_escape_string($t);
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
    $id_list   = implode(',', $meme_ids);
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
    $action  = $_POST['action'];
    $meme_id = isset($_POST['meme_id']) ? (int)$_POST['meme_id'] : 0;

    /* ── toggle_follow ── */
    if($action === 'toggle_follow' && isset($_POST['user_id'])){
        $target = (int)$_POST['user_id'];
        $check  = $conn->query("SELECT 1 FROM followers WHERE follower_id=$current_user AND following_id=$target");
        if($check->num_rows){
            $conn->query("DELETE FROM followers WHERE follower_id=$current_user AND following_id=$target");
            $conn->query("INSERT INTO unfollow_log (unfollowed_by, user_id) VALUES ($current_user, $target)");
            echo json_encode(['status'=>'ok','following'=>false]);
        } else {
            $conn->query("INSERT INTO followers (follower_id, following_id) VALUES ($current_user, $target)");
            $conn->query("DELETE FROM unfollow_log WHERE unfollowed_by=$current_user AND user_id=$target");
            echo json_encode(['status'=>'ok','following'=>true]);
        }
        exit;
    }

    /* ── toggle_group ── */
    if($action === 'toggle_group' && isset($_POST['group_id'])){
        $gid   = (int)$_POST['group_id'];
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

    /* ── request_track (private accounts) ── */
    if($action === 'request_track' && isset($_POST['user_id'])){
        $target = (int)$_POST['user_id'];
        $check  = $conn->query("SELECT id FROM track_requests
                                WHERE requester_id=$current_user AND target_id=$target LIMIT 1");
        if($check && $check->num_rows === 0){
            $conn->query("INSERT INTO track_requests (requester_id, target_id)
                          VALUES ($current_user, $target)");
        }
        echo json_encode(['status'=>'ok']);
        exit;
    }

    /* ── toggle_like ── */
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

    /* ── toggle_repost ── */
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

    /* ── toggle_favorite ── */
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
    background: linear-gradient(-45deg,#010218,#070924,#070924);
    background-attachment: fixed;
    color: var(--text);
    font-family: var(--font-body);
    min-height: 100vh;
    overflow-x: hidden;
}

.glass {
    background: transparent;
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    border: transparent;
    border-radius: 20px;
    transition: .35s;
}

nav{display:flex;justify-content:space-between;align-items:center;padding:20px 40px;position:sticky;top:0;z-index:100;background:transparent;border-radius:30px;backdrop-filter:blur(12px);box-shadow:0 4px 25px rgba(0,0,0,0.4);}

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
    box-shadow: transparent;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.nav-links a:hover {
    transform: translateY(-3px) scale(1.05);
    background: rgba(255,255,255,0.15);
    box-shadow: 0 10px 25px rgba(255,255,255,0.2), 0 0 15px rgba(255,255,255,0.1);
}

.nav-links a:active {
    transform: scale(0.95);
    box-shadow: inset 0 4px 10px rgba(0,0,0,0.4);
}

.nav-links a i { transition: transform 0.3s ease; }
.nav-links a:hover i { transform: scale(1.2) rotate(5deg); }

.nav-links a::before {
    content: "";
    position: absolute;
    top: 0; left: -80%;
    width: 50%; height: 100%;
    background: linear-gradient(120deg, transparent, rgba(255,255,255,0.4), transparent);
    transform: skewX(-20deg);
    transition: 0.6s;
}
.nav-links a:hover::before { left: 120%; }

@keyframes floatUpDown {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-5px); }
}

canvas { position:fixed; top:0; left:0; z-index:-1; }

.follow-bar { display:flex; overflow-x:auto; padding:15px 20px; gap:22px; margin-bottom:20px; }

.follow-user {
    text-align: center;
    flex: 0 0 auto;
    transition: transform 0.3s ease;
    margin-bottom: 3px;
}

.follow-user img {
    width: 70px; height: 70px;
    border-radius: 50%; object-fit: cover;
    border: 2px solid rgba(238,10,10,0.99);
    background: rgba(255,255,255,0.05);
    backdrop-filter: blur(6px);
    transition: all 0.3s ease;
}

.follow-user img:hover { transform: scale(1.15) rotate(-2deg); cursor: pointer; }
.follow-user:hover { transform: translateY(-4px); }
.follow-user p { font-size:14px; color:white; margin-top:5px; }

.follow-more {
    width: 60px; height: 60px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 30px; cursor: pointer; color: white;
    background: rgba(103,101,101,0.19);
    backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
    border: rgba(103,101,101,0.69);
    transition: all 0.3s ease;
    position: relative; overflow: hidden;
}
.follow-more:hover { transform: scale(1.15) rotate(-2deg); cursor: pointer; }
.follow-more:active { transform: scale(0.95); box-shadow: inset 0 4px 10px rgba(0,0,0,0.4); }
.follow-more:hover::before { left: 120%; }

.all-users { display:none; overflow-x:auto; white-space:nowrap; padding:20px; }

.all-user {
    display: inline-block; text-align: center;
    margin-right: 22px; transition: 0.3s ease;
}
.all-user img {
    width: 70px; height: 70px; border-radius: 50%;
    border: 3px solid #FF4C4C; object-fit: cover; transition: 0.3s ease;
}
.all-user p {
    font-size: 13.5px; color: rgba(255,255,255,0.9);
    margin-top: 6px; font-weight: 500; letter-spacing: 0.4px;
    max-width: 75px; white-space: nowrap; overflow: hidden;
    text-overflow: ellipsis; transition: 0.3s ease;
}
.all-user:hover img { transform: scale(1.08); box-shadow: 0 0 18px rgba(255,76,76,0.6); }
.all-user p { font-size:13px; }

.container { max-width:1200px; margin:40px auto; padding:0 15px; }

/* ── DISCOVER GRID — DESKTOP ── */
.discover-grid {
    display: grid;
    grid-template-columns: 2fr 2fr 1fr 1fr 1fr;
    grid-auto-rows: 220px;
    gap: 12px;
    margin-top: 30px;
    padding: 0 12px;
}
.trending { grid-column: span 2; grid-row: span 2; }

.meme-tile {
    position: relative; overflow: hidden;
    border-radius: 20px; background: rgba(255,255,255,0.05); cursor: pointer;
}
.meme-tile img, .meme-tile video { width:100%; height:100%; object-fit:cover; }

.meme-btn {
    position: absolute; display: flex;
    align-items: center; justify-content: center;
    gap: 6.2px; padding: 6px 6px;
    min-width: 42px; height: 32px;
    border-radius: 12px; background: transparent;
    color: white; font-size: 14px; cursor: pointer;
    transition: 0.2s ease; z-index: 5;
}
.meme-btn i { font-size:13px; line-height:1; color:white; transition:0.25s ease; }
.meme-btn .count { font-size:12px; font-weight:600; color:#ffffff; }
.meme-btn.active { background: rgba(64,61,238,0.18); }
.meme-btn.active i { color: #ff2b55; }
.meme-btn:hover { transform: scale(1.05); }
.meme-btn.left { left: 8px; }
.meme-btn.right { right: 8px; }
.meme-btn[style*="left:0px"], .meme-btn[style*="left:0"] { left: 6px !important; }
.meme-btn[style*="left:53px"] { left: 58px !important; }
.meme-btn[style*="left:106px"] { left: 110px !important; }

.meme-overlay {
    position: absolute; bottom: 0; width: 100%; padding: 10px;
    background: rgba(0,0,0,0.45); backdrop-filter: blur(10px);
    opacity: 0; transition: 0.3s;
}
.meme-tile:hover .meme-overlay { opacity: 1; }
.meme-user { display:flex; align-items:center; gap:8px; margin-top:5px; }
.meme-user img { width:28px; height:28px; border-radius:50%; }
.meme-user a { color:white; font-size:13px; text-decoration:none; }
.caption { font-size:12px; color:#eee; }

.bottom-logo {
    position: fixed; bottom:25px; right:25px; width:65px;
    border-radius: 18px; cursor: pointer; z-index: 200;
    transition: 0.4s; filter: drop-shadow(0 10px 25px rgba(0,0,0,0.5));
    animation: logoFloat 5s ease-in-out infinite;
}
.bottom-logo:hover { transform: scale(1.15) rotate(-3deg); box-shadow: 0 0 30px rgba(255,255,255,0.6); }
@keyframes logoFloat { 0%{transform:translateY(0);} 50%{transform:translateY(-12px);} 100%{transform:translateY(0);} }

footer { background:rgba(255,255,255,0.1); padding:20px; text-align:center; margin-top:30px; }

#particles { position:fixed; width:100%; height:100%; overflow:hidden; top:0; left:0; z-index:-1; }

.particle {
    position: absolute; background: white; border-radius: 50%;
    pointer-events: none; box-shadow: 0 0 10px rgba(255,255,255,0.6);
    animation: floatParticle linear infinite;
}
@keyframes floatParticle {
    0% { transform: translate(0,0) scale(1); opacity: 0; }
    10% { opacity: 1; }
    50% { transform: translate(var(--x-move), var(--y-move)) scale(1.2); }
    100% { transform: translate(calc(var(--x-move)*-1), calc(var(--y-move)*-1)) scale(0.8); opacity: 0; }
}

.follow-btn {
    background: rgba(255,255,255,0.15); border: none; color: white;
    padding: 6px 14px; border-radius: 25px; cursor: pointer;
    transition: all 0.3s ease; backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px); font-weight: 600;
    position: relative; overflow: hidden; min-width: 80px;
}
.follow-btn:hover { background:#FF4C4C; transform:translateY(-2px) scale(1.05); box-shadow:0 10px 25px rgba(255,76,76,0.3),0 0 15px rgba(255,255,255,0.1); }
.follow-btn:active { transform:scale(0.95); box-shadow:inset 0 4px 10px rgba(0,0,0,0.4); }
.follow-btn.following { color:#FF4C4C; border:2px solid #FF4C4C; }
.follow-btn.following:hover { background:#FF4C4C; color:white; }
.follow-btn::before { content:""; position:absolute; top:0; left:-80%; width:50%; height:100%; background:linear-gradient(120deg,transparent,rgba(255,255,255,0.4),transparent); transform:skewX(-20deg); transition:0.6s; }
.follow-btn:hover::before { left:120%; }

.group-btn {
    background: rgba(255,255,255,0.15); border: none; color: white;
    padding: 6px 14px; border-radius: 25px; cursor: pointer;
    transition: all 0.3s ease; backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px); font-weight: 600;
    position: relative; overflow: hidden; min-width: 80px;
}
.group-btn:hover { background:#0673f0ff; transform:translateY(-2px) scale(1.05); box-shadow:0 10px 25px rgba(6,115,240,0.3),0 0 15px rgba(255,255,255,0.1); }
.group-btn:active { transform:scale(0.95); box-shadow:inset 0 4px 10px rgba(0,0,0,0.4); }
.group-btn.joined { color:#0673f0ff; border:2px solid #0673f0ff; }
.group-btn.joined:hover { background:#0673f0ff; color:white; }
.group-btn::before { content:""; position:absolute; top:0; left:-80%; width:50%; height:100%; background:linear-gradient(120deg,transparent,rgba(255,255,255,0.4),transparent); transform:skewX(-20deg); transition:0.6s; }
.group-btn:hover::before { left:120%; }

.track-btn {
    margin-left: 50px; margin-top: 2px;
    padding: 4px 10px; width: 65px; border-radius: 30px;
    border: none; font-size: 11px; font-weight: 500;
    background: rgba(255,43,43,0.51); color: white;
    cursor: pointer; transition: 0.3s;
}
.track-btn.following { background:transparent; border:2px solid rgba(255,43,43,0.51); color:#ff2b2b; }
.track-btn:hover { background:#ff2b2b; color:white; transform:scale(1.05); }

.tag-scrollbar { display:flex; gap:10px; padding:10px; overflow-x:auto; white-space:nowrap; scrollbar-width:none; }
.tag-scrollbar::-webkit-scrollbar { display:none; }

.tag-pill {
    flex-shrink: 0; padding: 10px 16px; border-radius: 70px;
    background: transparent; border: 1px solid rgba(255,255,255,0.18);
    color: #ffffff; font-size: 0.85rem; font-weight: 500;
    letter-spacing: 0.3px; text-decoration: none;
    backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
    box-shadow: 0 4px 18px rgba(0,0,0,0.12);
    transition: all 0.25s ease; position: relative; overflow: hidden;
}
.tag-pill::before { content:""; position:absolute; top:0; left:-120%; width:120%; height:100%; background:linear-gradient(120deg,transparent,rgba(255,255,255,0.25),transparent); transition:0.6s ease; }
.tag-pill:hover { transform:translateY(-2px) scale(1.07); background:rgba(255,255,255,0.18); border:1px solid rgba(242,68,85,0.7); color:#ffffff; }
.tag-pill:hover::before { left:120%; }
.tag-pill.active { background:linear-gradient(135deg,#ff2a2a,#ff4d5a); border:1px solid rgba(255,255,255,0.25); color:white; border-radius:12px; box-shadow:0 4px 15px rgba(255,42,42,0.35),0 0 20px rgba(255,42,42,0.25) inset; z-index:1; }
.tag-pill.active::after { content:""; position:absolute; inset:0; border-radius:12px; box-shadow:0 0 20px rgba(255,42,42,0.6); animation:redPulse 2s ease-in-out infinite; z-index:-1; }
@keyframes redPulse { 0%{opacity:0.35;transform:scale(1);} 50%{opacity:0.85;transform:scale(1.05);} 100%{opacity:0.35;transform:scale(1);} }

.peura-new-tag {
    position: absolute; bottom: 72px; left: 12px;
    background: transparent; color: #ffffff;
    border: transparent 1px solid; border-radius: 10px;
    color: #ffffff; font-size: 0.65rem; font-weight: 600;
    padding: 4px 10px; text-transform: uppercase;
    letter-spacing: 0.5px; pointer-events: none;
    display: inline-flex; align-items: center; justify-content: center;
}

@media (max-width: 1024px) {
    .nav-icon[href="sponsorships.php"] { display: none !important; }
}

.search-box {
    position: relative; width: 110%; max-width: 1000px;
    margin: 40px auto; display: flex; align-items: center;
    border-radius: 20px; overflow: hidden;
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.1);
    backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
    transition: border-color 0.3s, box-shadow 0.3s;
}
.search-box:focus-within { border-color:rgba(255,255,255,0.22); box-shadow:0 0 0 4px rgba(255,45,45,0.08),0 16px 40px rgba(0,0,0,0.4); }
.search-box-icon { padding:0 6px 0 22px; color:rgba(255,255,255,0.35); font-size:18px; display:flex; align-items:center; flex-shrink:0; pointer-events:none; transition:color 0.3s; }
.search-box:focus-within .search-box-icon { color:rgba(255,255,255,0.6); }
.search-box input { flex:1; padding:17px 16px; font-size:0.95em; font-family:inherit; font-weight:400; letter-spacing:0.2px; color:white; background:transparent; border:none; outline:none; }
.search-box input::placeholder { color:rgba(255,255,255,0.6); font-weight:300; }
.search-box button { margin:7px; width:70px; height:40px; border:none; border-radius:9px; cursor:pointer; color:white; background:#FF2D2D; display:flex; align-items:center; justify-content:center; flex-shrink:0; transition:all 0.22s ease; -webkit-tap-highlight-color:transparent; position:relative; overflow:hidden; }
.search-box button i { font-size:17px; display:block; }
.search-box button::before { content:""; position:absolute; top:0; left:-80%; width:50%; height:100%; background:linear-gradient(120deg,transparent,rgba(255,255,255,0.25),transparent); transform:skewX(-20deg); transition:0.5s; }
.search-box button:hover { background:#ff1a1a; box-shadow:0 6px 20px rgba(255,45,45,0.45); transform:scale(1.04); }
.search-box button:hover::before { left:120%; }
.search-box button:active { transform:scale(0.96); }

.search-clear-btn {
    margin: 7px; width: 70px; height: 40px;
    border-radius: 9px; cursor: pointer; color: white;
    background: #FF2D2D; display: flex; align-items: center;
    justify-content: center; flex-shrink: 0;
    text-decoration: none; font-size: 13px; font-weight: 600;
    font-family: 'Poppins', sans-serif;
    transition: all 0.22s ease; -webkit-tap-highlight-color: transparent;
    position: relative; overflow: hidden;
}
.search-clear-btn::before { content:""; position:absolute; top:0; left:-80%; width:50%; height:100%; background:linear-gradient(120deg,transparent,rgba(255,255,255,0.25),transparent); transform:skewX(-20deg); transition:0.5s; }
.search-clear-btn:hover { background:#ff1a1a; box-shadow:0 6px 20px rgba(255,45,45,0.45); transform:scale(1.04); }
.search-clear-btn:hover::before { left:120%; }
.search-clear-btn:active { transform:scale(0.96); }

/* ── DISCOVER SKELETON ── */
.discover-skeleton {
    display: grid;
    grid-template-columns: 2fr 2fr 1fr 1fr 1fr;
    grid-auto-rows: 220px;
    gap: 12px; margin-top: 30px; padding: 0 12px;
}
.sk-tile { border-radius:20px; background:rgba(255,255,255,0.06); position:relative; overflow:hidden; }
.sk-tile.sk-trending { grid-column:span 2; grid-row:span 2; }
.sk-tile::after { content:''; position:absolute; inset:0; background:linear-gradient(105deg,transparent 0%,transparent 35%,rgba(255,255,255,0.07) 45%,rgba(255,255,255,0.13) 50%,rgba(255,255,255,0.07) 55%,transparent 65%,transparent 100%); background-size:250% 100%; animation:skDiscover 1.7s ease-in-out infinite; }
@keyframes skDiscover { 0%{background-position:200% 0;} 100%{background-position:-200% 0;} }
.sk-tile:nth-child(1){animation-delay:0s;} .sk-tile:nth-child(2){animation-delay:0.12s;} .sk-tile:nth-child(3){animation-delay:0.22s;} .sk-tile:nth-child(4){animation-delay:0.32s;} .sk-tile:nth-child(5){animation-delay:0.42s;} .sk-tile:nth-child(6){animation-delay:0.52s;} .sk-tile:nth-child(7){animation-delay:0.62s;}
.sk-tile::before { content:''; position:absolute; bottom:10px; left:10px; right:10px; height:34px; border-radius:12px; background:rgba(255,255,255,0.07); z-index:2; }
.sk-btn-row { position:absolute; top:10px; left:10px; display:flex; gap:6px; z-index:2; }
.sk-btn { width:42px; height:28px; border-radius:10px; background:rgba(255,255,255,0.09); }
.sk-badge { position:absolute; bottom:52px; left:10px; width:36px; height:14px; border-radius:5px; background:rgba(255,255,255,0.08); z-index:2; }

/* ── USER BAR inside discover grid ── */
.meme-tile .user-bar {
    position: absolute; bottom:8px; left:8px; right:8px;
    width: auto; transform: none;
    display: flex; align-items: center; gap: 6px;
    padding: 5px 8px; border-radius: 10px;
    background: rgba(14,10,239,0.15);
    backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
    z-index: 3; overflow: hidden; min-width: 0;
}
.meme-tile .user-bar img { width:24px; height:24px; min-width:24px; min-height:24px; border-radius:50%; object-fit:cover; border:1.5px solid red; display:block; flex-shrink:0; }
.meme-tile .user-bar > div { display:flex; flex-direction:column; justify-content:center; min-width:0; flex:1; }
.meme-tile .user-bar > div > div { color:rgba(255,255,255,0.9); font-size:clamp(0.55rem,1.8vw,0.72rem); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; min-width:0; width:100%; font-weight:500; }

/* ── TAG BAR ── */
.tag-bar-wrap{display:flex;align-items:center;gap:8px;padding:0 clamp(16px,4vw,40px);width:100%;max-width:1280px;}
.tag-scroll-btn-desktop{width:32px;height:32px;border-radius:50%;border:1px solid rgba(255,255,255,0.12);background:rgba(255,255,255,0.05);backdrop-filter:blur(10px);color:white;font-size:14px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:0.2s;-webkit-tap-highlight-color:transparent;}
.tag-scroll-btn-desktop:hover{background:rgba(255,45,45,0.7);border-color:transparent;box-shadow:0 0 14px rgba(255,45,45,0.35);}
.tag-scroll-btn-desktop:active{transform:scale(0.9);}
.tag-scrollbar-wrapper{flex:1;overflow:hidden;}
.tag-scrollbar{display:flex;gap:10px;padding:10px;overflow-x:auto;white-space:nowrap;scrollbar-width:none;-webkit-overflow-scrolling:touch;}
.tag-scrollbar::-webkit-scrollbar{display:none;}
.tag-scrollbar > a{display:inline-block;}
.tag-pill{flex-shrink:0;padding:clamp(8px,2vw,10px) clamp(12px,3vw,16px);border-radius:999px;background:transparent;border:1px solid rgba(255,255,255,0.18);color:#fff;font-size:clamp(0.78rem,2.5vw,0.85rem);font-weight:500;letter-spacing:0.3px;text-decoration:none;backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);box-shadow:0 4px 18px rgba(0,0,0,0.12);transition:all 0.25s ease;position:relative;overflow:hidden;-webkit-tap-highlight-color:transparent;}
.tag-pill.user-affinity:not(.active){border-color:rgba(255,45,45,0.28);color:rgba(255,180,180,0.85);}

/* ── SEARCH RESULT ROWS ── */
.user-result-row, .group-result-row {
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px; padding: 10px 14px; margin: 0 10px 12px;
    border-radius: 18px; box-sizing: border-box; width: calc(100% - 20px);
}
.user-result-info .username, .group-result-row span > div > span { font-size:clamp(12px,2.8vw,14px) !important; line-height:1.2; }
.user-result-info .nickname { font-size:clamp(10px,2.3vw,12px) !important; opacity:0.85; }
.user-result-row .follow-btn, .group-result-row .group-btn { padding:5px 10px !important; font-size:clamp(10px,2.4vw,12px) !important; border-radius:12px; min-height:30px; min-width:58px; line-height:1; }
.user-result-row img, .group-result-row img { width:46px; height:46px; border-radius:50%; flex-shrink:0; object-fit:cover; }
.user-result-info { flex:1; cursor:pointer; min-width:0; }
.user-result-info .username { font-weight:700; font-size:clamp(13px,3.5vw,15px); color:#fff; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.user-result-info .nickname { font-size:clamp(10px,2.5vw,11px); color:#aaa; margin-top:2px; }
.group-result-row img { width:clamp(60px,15vw,90px); height:clamp(55px,14vw,80px); border-radius:12px; border:2px solid blue; cursor:pointer; flex-shrink:0; object-fit:cover; }
.user-result-row img { width:clamp(48px,12vw,65px); height:clamp(48px,12vw,65px); border-radius:70px; border:2px solid red; cursor:pointer; flex-shrink:0; object-fit:cover; }

/* USERS + COMMUNITIES HEADINGS */
.search-section-title, h3 { padding-left:14px; margin-bottom:12px; box-sizing:border-box; }

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

.nav-profile-pic {
    display: none;
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

/* ══════════════════════════════════════════
   RESPONSIVE — 1024px
══════════════════════════════════════════ */
@media (max-width: 1024px) {
    .nav-icon[href="sponsorships.php"] { display:none !important; }
}

/* ══════════════════════════════════════════
   RESPONSIVE — 768px (MOBILE)
══════════════════════════════════════════ */
@media (max-width: 768px) {

    body { padding-bottom:70px; padding-left:0; padding-right:0; }

    .glass { background:rgba(3,4,21,0.97); backdrop-filter:blur(12px); -webkit-backdrop-filter:blur(12px); border:transparent; border-radius:10px; transition:.35s; }

    nav { position:fixed; top:auto; bottom:0; left:0; right:0; height:60px; padding:0; background:rgba(3,4,21,0.97); border-radius:10px; backdrop-filter:blur(20px); -webkit-backdrop-filter:blur(20px); border-top:1px solid rgba(255,255,255,0.1); display:flex; justify-content:center; z-index:1000; }
    .logo-container { display:none; }
    .nav-links { width:100%; height:100%; display:flex; justify-content:space-evenly; align-items:center; gap:0; }

.nav-links a { background:transparent !important; border:none !important; box-shadow:none !important; backdrop-filter:none !important; flex:0 0 auto; width:50px; height:100%; display:flex; justify-content:center; align-items:center; position:relative; transition:opacity 0.2s ease; -webkit-tap-highlight-color:transparent; }

.nav-links a::before { display:none; }

.nav-links a i { font-size:24px; color:#fff; font-weight:900; -webkit-text-stroke:0.5px #fff; }

.nav-links a span { display:none; }

.nav-links a.notif::after { content:''; position:absolute; top:18px; right:calc(50% - 15px); width:6px; height:6px; background:#ff0000; border-radius:50%; border:2px solid #000; }

.nav-links a.active i { color:#fff; }

.nav-links a:active { transform:scale(0.9); opacity:0.6; }

a.nav-icon[href="upload.php"] { display:none !important; }

/* hide icon, show pic on mobile */
.nav-profile-icon { display:none !important; }

.nav-profile-pic { 
    display: block !important; 
    width: 24px; 
    height: 24px; 
    min-width: 24px;
    min-height: 24px;
    border-radius: 50%; 
    object-fit: cover; 
    border: 2px solid #ffffff;
    overflow: hidden;
    flex-shrink: 0;
}

    .container { padding-left:0 !important; padding-right:0 !important; margin-left:0; margin-right:0; width:100%; }

.follow-bar {
    margin-top: 10px;
    margin-bottom: clamp(12px, 3vw, 30px);
    padding: clamp(8px, 2vw, 15px) clamp(10px, 3vw, 20px);
    gap: clamp(14px, 3.5vw, 22px);
    display: flex;
    align-items: center;
    overflow-x: auto;
    scroll-snap-type: x mandatory;
    scrollbar-width: none;
    -ms-overflow-style: none;
}

.follow-bar::-webkit-scrollbar {
    display: none;                  /* Chrome/Safari */
}
    .follow-user { flex-shrink:0; }
    .follow-user img { width:clamp(60px,15vw,82px); height:clamp(60px,15vw,82px); object-fit:cover; border-radius:50%; }
    .follow-user p { font-size:clamp(10px,2.5vw,14px); white-space:nowrap; }
    .follow-more { width:clamp(44px,11vw,60px); height:clamp(44px,11vw,60px); font-size:clamp(20px,5vw,30px); flex-shrink:0; }

    /* Search box */
    .search-box { max-width:100%; margin:14px 13px; margin-bottom:-25px; width:calc(100% - 32px); border-radius:13px; transform:translateY(-27px); }
    .search-box input { font-size:clamp(13px,3.5vw,15px); padding:7px 12px; }
    .search-box button { width:28px; height:28px; border-radius:5px; font-size:15px; }
    .search-clear-btn { width:52px; height:28px; border-radius:5px; font-size:11px; margin:5px; }

    /* Tag pills */
    .tag-scroll-btn-desktop { display:none !important; }
    .tag-pill { padding:7px 12px; font-size:0.78rem; }

    /* Track btn */
    .track-btn { margin-left:clamp(4px,1.5vw,8px) !important; padding:clamp(2px,0.8vw,4px) clamp(6px,1.5vw,10px) !important; width:auto !important; font-size:clamp(9px,2.2vw,11px) !important; }

    /* Meme buttons */
    .meme-btn i { font-size:16px; }
    .meme-btn .count { font-size:13px; font-weight:700; }
    .meme-btn.left { padding:5px 8px; border-radius:8px; top:8px !important; }
    .meme-btn[style*="left:0px"], .meme-btn[style*="left:0"] { left:8px !important; }
    .meme-btn[style*="left:53px"] { left:61px !important; }
    .meme-btn[style*="left:106px"] { left:114px !important; }

    /* Peura tag */
    .peura-new-tag { padding:3px 8px; font-size:0.65rem; border-radius:10px; }

    /* Search results */
    .user-result-row, .group-result-row { padding:9px 12px; margin:0 12px 10px; gap:10px; }
    .user-result-info .username, .group-result-row span > div > span { font-size:12px !important; }
    .user-result-info .nickname { font-size:10px !important; }
    .user-result-row .follow-btn, .group-result-row .group-btn { padding:4px 9px !important; font-size:11px !important; min-height:28px; min-width:54px; }
    .user-result-row img, .group-result-row img { width:42px; height:42px; }

    /* Headings */
    .search-section-title, h3 { padding-left:12px; margin-bottom:10px; }
    .container h3 { font-size:clamp(14px,3.5vw,18px); margin:clamp(12px,3vw,20px) 0 clamp(6px,2vw,10px); }

    /* All users */
    .all-user img { width:clamp(52px,13vw,70px); height:clamp(52px,13vw,70px); }
    .all-user p { font-size:clamp(11px,2.5vw,13.5px); max-width:clamp(55px,14vw,75px); }


    /* ── DISCOVER GRID — ALL MOBILE ── */
   .discover-grid {
    display: grid;
    width: 100%;
    margin: 0;
    padding: 0 6px;
    gap: 6px;
    grid-template-columns: repeat(2, 1fr);
    grid-auto-rows: clamp(180px, 55vw, 240px);
}

.trending {
    grid-column: span 2;
    grid-row: span 2;
}

    /* Skeleton */
    .discover-skeleton { grid-template-columns:1fr; grid-auto-rows:clamp(180px,55vw,240px); gap:6px; padding:0 6px; }
    .sk-tile.sk-trending { grid-column:span 1; grid-row:span 1; }

    /* User bar */
    .meme-tile .user-bar { bottom:6px; left:6px; right:6px; padding:4px 7px; border-radius:8px; gap:5px; }
    .meme-tile .user-bar img { width:20px; height:20px; min-width:20px; min-height:20px; }
    .meme-tile .user-bar > div > div { font-size:clamp(0.5rem,1.6vw,0.65rem); }

    /* Break overlay mobile */
    @media (max-height: 680px) {
        .break-angel { font-size:45px; margin-bottom:10px; }
        .break-title { font-size:18px; }
        .break-subtitle { margin-bottom:10px; font-size:12px; }
        .break-code-label { margin-bottom:8px; }
        .break-input { padding:9px 16px; margin-bottom:8px; }
        .break-return-btn { padding:10px 0; margin-bottom:10px; }
    }
}

/* ══════════════════════════════════════════
   RESPONSIVE — 480px
══════════════════════════════════════════ */
@media (max-width: 480px) {
    .meme-btn i { font-size:11.5px; }
    .meme-btn .count { font-size:11px; }
    .meme-btn.left { padding:5px 8px; border-radius:8px; top:8px !important; }
    .meme-btn[style*="left:0px"], .meme-btn[style*="left:0"] { left:8px !important; }
    .meme-btn[style*="left:53px"] { left:61px !important; }
    .meme-btn[style*="left:106px"] { left:114px !important; }

    .discover-grid { padding:0 6px; gap:6px; grid-auto-rows:clamp(180px,55vw,240px); }

    .meme-tile .user-bar { bottom:5px; left:5px; right:5px; padding:3px 6px; border-radius:7px; gap:4px; }
    .meme-tile .user-bar img { width:18px; height:18px; min-width:18px; min-height:18px; border-width:1px; }
    .meme-tile .user-bar > div > div { font-size:0.5rem; }

    .tag-pill { padding:6px 11px; font-size:0.76rem; }

    .user-result-row, .group-result-row { padding:8px 10px; margin:0 10px 9px; gap:8px; border-radius:14px; }
    .user-result-info .username, .group-result-row span > div > span { font-size:11px !important; }
    .user-result-info .nickname { font-size:9px !important; }
    .user-result-row .follow-btn, .group-result-row .group-btn { padding:4px 8px !important; font-size:10px !important; min-height:26px; min-width:50px; border-radius:10px; }
    .user-result-row img, .group-result-row img { width:38px; height:38px; }

    .search-section-title, h3 { padding-left:10px; margin-bottom:9px; font-size:15px; }
    .peura-new-tag { padding:2px 7px; font-size:0.6rem; border-radius:10px; }
}

/* ══════════════════════════════════════════
   RESPONSIVE — 380px
══════════════════════════════════════════ */
@media (max-width: 380px) {
    .discover-grid { padding:0 6px; gap:6px; grid-template-columns:1fr; grid-auto-rows:clamp(180px,55vw,240px); }
    .trending { grid-column:span 1; grid-row:span 1; }
    .discover-skeleton { grid-template-columns:1fr; grid-auto-rows:clamp(180px,55vw,240px); }
    .sk-tile.sk-trending { grid-column:span 1; grid-row:span 1; }
    .search-box input { font-size:0.78rem; }
    .tag-pill { padding:5px 10px; font-size:0.76rem; }
}

/* ══════════════════════════════════════════
   RESPONSIVE — 360px
══════════════════════════════════════════ */
@media (max-width: 360px) {
    .meme-btn i { font-size:12px; }
    .meme-btn .count { font-size:11px; }
    .meme-btn.left { top:6px !important; }
    .meme-btn[style*="left:0px"], .meme-btn[style*="left:0"] { left:6px !important; }
    .meme-btn[style*="left:53px"] { left:58px !important; }
    .meme-btn[style*="left:106px"] { left:110px !important; }

    .discover-grid { grid-template-columns:1fr; grid-auto-rows:clamp(180px,55vw,240px); padding:0 6px; gap:6px; }
    .trending { grid-column:span 1; grid-row:span 1; }
    .discover-skeleton { grid-template-columns:1fr; grid-auto-rows:clamp(180px,55vw,240px); gap:6px; padding:0 6px; }
    .sk-tile.sk-trending { grid-column:span 1; grid-row:span 1; }

    .meme-tile .user-bar { bottom:5px; left:4px; right:4px; padding:3px 5px; }
    .meme-tile .user-bar img { width:16px; height:16px; min-width:16px; }

    .user-result-row, .group-result-row { padding:8px 10px; margin:0 10px 9px; gap:8px; border-radius:14px; }
    .user-result-info .username, .group-result-row span > div > span { font-size:11px !important; }
    .user-result-info .nickname { font-size:9px !important; }
    .user-result-row .follow-btn, .group-result-row .group-btn { padding:4px 8px !important; font-size:10px !important; min-height:26px; min-width:50px; border-radius:10px; }
    .user-result-row img, .group-result-row img { width:38px; height:38px; }

    .search-section-title, h3 { padding-left:8px; font-size:14px; }
    .peura-new-tag { padding:2px 6px; font-size:0.58rem; border-radius:12px; }
}
</style>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>

<nav>
    <div class="logo-container">
        <img src="Dezzme.jpg" onclick="location.href='index.php'">
        <div class="title">Dezzme</div>
    </div>
    <div class="nav-links">
        <a class="glass nav-icon" href="home.php"><i class="ri-home-5-line" style="font-size:15px; color:white;"></i></a>
        <a class="glass nav-icon" href="text_feed.php"><i class="ri-send-plane-line" style="font-size:15px; color:white;"></i></a>
        <a class="glass nav-icon" href="search.php"><i class="ri-search-line" style="font-size:15px; color:white;"></i></a>
        <a class="glass nav-icon" href="upload.php"><i class="ri-add-circle-line" style="font-size:29px; color:white;"></i></a>
        <a class="glass nav-icon" href="community.php"><i class="ri-team-line" style="font-size:15px; color:white;"></i></a>
        <a class="glass nav-icon nav-profile-link" href="profile.php">
            <i class="ri-user-3-line nav-profile-icon" style="font-size:15px; color:white;"></i>
            <img class="nav-profile-pic"
                 src="<?php echo htmlspecialchars($nav_pic); ?>"
                 onerror="this.src='uploads/mee.jpeg'"
                 alt="Profile">
        </a>
    </div>
</nav>

<div class="container">

<div class="header-controls-wrapper" style="position: relative; margin-top: 50px; padding: 0 15px;">

    <div class="kebab-container">

        <div class="title-row">
            <div class="title">Dezzme</div>
            <button class="dezzme-menu-btn" id="dezzmeMenuBtn" aria-label="Open menu">
                <i class="ri-apps-2-line"></i>
            </button>
        </div>

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

<!-- DEZZME SIDE MENU (half-screen drawer, mobile only) -->
<div id="dezzmeSideMenuOverlay" class="dezzme-menu-overlay" onclick="closeDezzmeSideMenu()"></div>
<div id="dezzmeSideMenu" class="dezzme-menu-panel">
    <div class="dezzme-menu-header">
        <div class="dezzme-menu-title">Dezzme</div>
        <button class="dezzme-menu-close" onclick="closeDezzmeSideMenu()" aria-label="Close menu"><i class="ri-close-line"></i></button>
    </div>
    <div class="dezzme-menu-list">
        <a href="notifications.php" class="dezzme-menu-item"><i class="ri-notification-3-line"></i><span>Notifications</span></a>
        <a href="messages.php" class="dezzme-menu-item"><i class="ri-wechat-line"></i><span>Your messages</span></a>
        <a href="sounds.php" class="dezzme-menu-item"><i class="ri-music-2-line"></i><span>Sounds</span></a>
        <a href="discover.php" class="dezzme-menu-item"><i class="ri-compass-3-line"></i><span>Discover</span></a>
        <a href="chatbot.php" class="dezzme-menu-item"><i class="ri-robot-line"></i><span>Your AI</span></a>
        <a href="memepedia.php" class="dezzme-menu-item"><i class="ri-book-open-line"></i><span>Memepedia</span></a>
        <a href="upload.php" class="dezzme-menu-item"><i class="ri-add-circle-line"></i><span>Create/Upload</span></a>
        <a href="history.php" class="dezzme-menu-item"><i class="ri-history-line"></i><span>History</span></a>
        <a href="sponsorships.php" class="dezzme-menu-item"><i class="ri-megaphone-line"></i><span>Ads</span></a>
        <a href="Collections.php" class="dezzme-menu-item"><i class="ri-folder-2-line"></i><span>Collections</span></a>
    </div>
</div>

<style>

 .kebab-container .title {
    font-size: 24px;
    letter-spacing: 4px;
    font-weight: 700;
    color: #fff;
    margin-left: -15px;
    padding-left: 0;        /* changed from 20px to 0 */
    display: none;
}

/* ── TITLE ROW (title + new icon, same line) ──
   margin-right:auto moved here from .title so it
   still pushes the kebab-btn to the far right */
.title-row {
    display: none;
}

@media (max-width: 768px) {
    .title-row {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-right: auto;
    }
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
        position: relative;
        top: -35px;
        right: auto;
        z-index: 999;
        margin-bottom: -30px;
    }
}

/* ════════════════════════════════════════════════════════════
   NEW: DEZZME MENU ICON — plain, no box/background, sits
   right beside the "Dezzme" word
   ════════════════════════════════════════════════════════════ */
.dezzme-menu-btn {
    display: none;
}

@media (max-width: 768px) {
    .dezzme-menu-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: none;
        border: none;
        padding: 0;
        margin: 0;
        color: #fff;
        cursor: pointer;
        -webkit-tap-highlight-color: transparent;
        transition: color 0.25s ease, transform 0.25s ease;
    }
    .dezzme-menu-btn i {
        font-size: 18px;
    }
    .dezzme-menu-btn:hover,
    .dezzme-menu-btn:active {
        color: #ff2a2a;
        transform: scale(1.1);
    }
}

@media (max-width: 480px) {
    .dezzme-menu-btn i { font-size: 19px; }
}

@media (max-width: 360px) {
    .dezzme-menu-btn i { font-size: 17px; }
}

/* ════════════════════════════════════════════════════════════
   NEW: HALF-SCREEN SIDE MENU DRAWER
   ════════════════════════════════════════════════════════════ */
/* ===== BASE STYLES (Desktop) ===== */

.dezzme-menu-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.65);
    backdrop-filter: blur(2px);
    -webkit-backdrop-filter: blur(2px);
    z-index: 999998;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.3s ease;
}

.dezzme-menu-overlay.show {
    opacity: 1;
    pointer-events: all;
}

.dezzme-menu-panel {
    position: fixed;
    top: 0;
    left: 0;
    height: 100%;
    width: 78%;
    max-width: 320px;
    background: rgba(6,7,26,0.98);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    z-index: 999999;
    transform: translateX(-100%);
    transition: transform 0.32s cubic-bezier(0.32,0.72,0,1);
    display: flex;
    flex-direction: column;
    box-shadow: 8px 0 30px rgba(0,0,0,0.5);
    border-right: 1px solid rgba(255,255,255,0.07);
}

.dezzme-menu-panel.show {
    transform: translateX(0);
}

.dezzme-menu-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 18px 18px 14px;
    border-bottom: 1px solid rgba(255,255,255,0.07);
    flex-shrink: 0;
}

.dezzme-menu-title {
    font-size: 18px;
    font-weight: 700;
    letter-spacing: 2px;
    color: #fff;
}

.dezzme-menu-close {
    background: rgba(255,255,255,0.06);
    border: none;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    color: rgba(255,255,255,0.6);
    font-size: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
    transition: background 0.2s ease, color 0.2s ease;
}

.dezzme-menu-close:hover {
    background: rgba(255,45,45,0.18);
    color: #ff4c4c;
}

.dezzme-menu-list {
    display: flex;
    flex-direction: column;
    padding: 8px 0;
    overflow-y: auto;
}

.dezzme-menu-item {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 14px 20px;
    color: #fff;
    text-decoration: none;
    font-size: 15px;
    font-weight: 500;
    transition: background 0.2s ease;
    -webkit-tap-highlight-color: transparent;
}

.dezzme-menu-item i {
    font-size: 19px;
    color: #ff2a2a;
    width: 20px;
    text-align: center;
    flex-shrink: 0;
}

.dezzme-menu-item:hover,
.dezzme-menu-item:active {
    background: rgba(255,255,255,0.06);
}


/* ===== RESPONSIVE BREAKPOINTS ===== */

/* 760px and above - Medium Desktop */
@media (max-width: 760px) {
    .dezzme-menu-panel {
        width: 80%;
        max-width: 300px;
    }

    .dezzme-menu-title {
        font-size: 17px;
        letter-spacing: 1.5px;
    }

    .dezzme-menu-header {
        padding: 16px 16px 12px;
    }

    .dezzme-menu-item {
        font-size: 14px;
        padding: 12px 18px;
        gap: 14px;
    }

    .dezzme-menu-item i {
        font-size: 18px;
        width: 18px;
    }

    .dezzme-menu-close {
        width: 28px;
        height: 28px;
        font-size: 15px;
    }
}

/* 480px - Tablet & Large Mobile */
@media (max-width: 480px) {
    .dezzme-menu-overlay {
        background: rgba(0,0,0,0.55);
        backdrop-filter: blur(1.5px);
        -webkit-backdrop-filter: blur(1.5px);
    }

    .dezzme-menu-panel {
        width: 85%;
        max-width: 280px;
        backdrop-filter: blur(15px);
        -webkit-backdrop-filter: blur(15px);
        box-shadow: 6px 0 20px rgba(0,0,0,0.4);
    }

    .dezzme-menu-header {
        padding: 14px 14px 10px;
    }

    .dezzme-menu-title {
        font-size: 15px;
        font-weight: 700;
        letter-spacing: 1px;
    }

    .dezzme-menu-item {
        font-size: 13px;
        padding: 11px 16px;
        gap: 12px;
    }

    .dezzme-menu-item i {
        font-size: 17px;
        width: 17px;
    }

    .dezzme-menu-close {
        width: 26px;
        height: 26px;
        font-size: 14px;
    }

    .dezzme-menu-list {
        padding: 6px 0;
    }
}

/* 380px - Small Mobile */
@media (max-width: 380px) {
    .dezzme-menu-overlay {
        background: rgba(0,0,0,0.5);
    }

    .dezzme-menu-panel {
        width: 88%;
        max-width: 260px;
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        box-shadow: 4px 0 15px rgba(0,0,0,0.35);
    }

    .dezzme-menu-header {
        padding: 12px 12px 9px;
        gap: 10px;
    }

    .dezzme-menu-title {
        font-size: 14px;
        letter-spacing: 0.8px;
    }

    .dezzme-menu-item {
        font-size: 12px;
        padding: 9px 14px;
        gap: 10px;
    }

    .dezzme-menu-item i {
        font-size: 16px;
        width: 16px;
    }

    .dezzme-menu-close {
        width: 24px;
        height: 24px;
        font-size: 13px;
    }

    .dezzme-menu-list {
        padding: 4px 0;
    }
}

/* 320px - Extra Small Mobile */
@media (max-width: 320px) {
    .dezzme-menu-overlay {
        background: rgba(0,0,0,0.45);
    }

    .dezzme-menu-panel {
        width: 90%;
        max-width: 240px;
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        box-shadow: 2px 0 10px rgba(0,0,0,0.3);
        transition: transform 0.3s cubic-bezier(0.32,0.72,0,1);
    }

    .dezzme-menu-header {
        padding: 11px 11px 8px;
        border-bottom: 0.5px solid rgba(255,255,255,0.05);
    }

    .dezzme-menu-title {
        font-size: 13px;
        font-weight: 700;
        letter-spacing: 0.5px;
    }

    .dezzme-menu-item {
        font-size: 11px;
        padding: 8px 12px;
        gap: 8px;
    }

    .dezzme-menu-item i {
        font-size: 15px;
        width: 15px;
    }

    .dezzme-menu-close {
        width: 22px;
        height: 22px;
        font-size: 12px;
        background: rgba(255,255,255,0.04);
    }

    .dezzme-menu-list {
        padding: 3px 0;
    }

    .dezzme-menu-close:hover {
        background: rgba(255,45,45,0.15);
    }
}


/* ===== TOUCH DEVICE OPTIMIZATIONS ===== */

@media (hover: none) and (pointer: coarse) {
    .dezzme-menu-item {
        padding: 12px 16px;
    }

    .dezzme-menu-close {
        width: 32px;
        height: 32px;
    }
}

/* ===== LANDSCAPE MODE ===== */

@media (max-height: 600px) and (orientation: landscape) {
    .dezzme-menu-panel {
        max-width: 280px;
    }

    .dezzme-menu-header {
        padding: 10px 12px 8px;
    }

    .dezzme-menu-item {
        padding: 8px 14px;
        font-size: 12px;
    }

    .dezzme-menu-list {
        overflow-y: auto;
        max-height: 100vh;
    }
}

/* ===== ACCESSIBILITY & REDUCED MOTION ===== */

@media (prefers-reduced-motion: reduce) {
    .dezzme-menu-overlay,
    .dezzme-menu-panel,
    .dezzme-menu-item,
    .dezzme-menu-close {
        transition: none;
    }
}

/* ===== HIGH DPI SCREENS ===== */

@media (-webkit-min-device-pixel-ratio: 2), (min-resolution: 192dpi) {
    .dezzme-menu-panel {
        border-right: 0.5px solid rgba(255,255,255,0.07);
    }

    .dezzme-menu-header {
        border-bottom: 0.5px solid rgba(255,255,255,0.07);
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var menuBtn = document.getElementById('dezzmeMenuBtn');
    var overlay = document.getElementById('dezzmeSideMenuOverlay');
    var panel   = document.getElementById('dezzmeSideMenu');

    if (!menuBtn || !overlay || !panel) return;

    function openDezzmeSideMenu() {
        overlay.classList.add('show');
        panel.classList.add('show');
        document.body.style.overflow = 'hidden';
    }

    window.closeDezzmeSideMenu = function () {
        overlay.classList.remove('show');
        panel.classList.remove('show');
        document.body.style.overflow = '';
    };

    menuBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        openDezzmeSideMenu();
    });
});
</script>

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
<!-- SEARCH TRIGGER — matches old search-box style exactly -->
<div class="search-box" id="searchTrigger" onclick="openSearchModal()" style="cursor:pointer; user-select:none;">

    <span class="search-box-icon">
        <i class="ri-search-line"></i>
    </span>

    <input
        type="text"
        placeholder="Search..."
        value="<?php echo htmlspecialchars($search_query); ?>"
        readonly
        style="cursor:pointer; pointer-events:none;"
    >

    <?php if($search_query): ?>
        <a href="search.php"
           onclick="event.stopPropagation()"
           class="search-clear-btn">
            Clear
        </a>
    <?php else: ?>
        <button type="button" style="pointer-events:none;">
            <i class="ri-arrow-right-line"></i>
        </button>
    <?php endif; ?>

</div>

<!-- ══════════════════════════════════════
     SEARCH MODAL HTML — Updated
══════════════════════════════════════ -->
<div id="searchModal">

    <!-- TOP BAR -->
    <div class="sm-topbar">
        <button class="sm-back-btn" onclick="closeSearchModal()">
            <i class="ri-arrow-left-s-line"></i>
        </button>

        <div id="modalSearchWrap">
            <span class="sm-search-icon">
                <i class="ri-search-line"></i>
            </span>
            <input
                type="text"
                id="modalSearchInput"
                placeholder="Search users, posts, sounds, DZ-ID..."
                autocomplete="off"
            >
            <button id="modalClearBtn" onclick="clearModalSearch()">
                <i class="ri-close-line"></i>
            </button>
        </div>

        <button id="smSearchBtn" onclick="smDoSearch()" style="display:none">Search</button>
    </div>

    <!-- CONTENT -->
    <div id="searchModalContent">

        <!-- RECENTS -->
        <div id="searchRecents">
            <div class="sm-recents-header" id="smRecentsHeader">
                <span class="sm-recents-title">Recent</span>
                <div class="sm-recents-actions" id="smRecentsActions">
                    <button id="smClearAllBtn" onclick="clearAllRecents()" style="display:none">Clear all</button>
                    <button id="smCollapseBtn" onclick="smCollapseRecents()" style="display:none">
                        <i class="ri-arrow-up-s-line"></i> Collapse
                    </button>
                </div>
            </div>
            <div id="recentsList"></div>
            <div id="smSeeMoreRow" style="display:none">
                <button id="smSeeMoreBtn" onclick="smExpandRecents()">
                    <i class="ri-arrow-down-s-line"></i> See more
                </button>
                <button id="smCollapseBtn2" onclick="smCollapseRecents()" style="display:none">
                    <i class="ri-arrow-up-s-line"></i> Collapse
                </button>
            </div>

            <!-- SUGGESTIONS -->
            <div id="smSuggestionsWrap">
                <div class="sm-recents-header">
                    <span class="sm-recents-title" id="smSuggestTitle">Suggested</span>
                </div>
                <div id="smSuggestionsList"></div>
            </div>
        </div>

        <!-- LIVE RESULTS -->
        <div id="searchLiveResults"></div>

    </div>
</div>

<style>
/* ═══════════════════════════════════════════
   SEARCH MODAL — BASE
═══════════════════════════════════════════ */

#searchModal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 2147483647;
    background: #040617;
    flex-direction: column;
    overflow: hidden;
    touch-action: none;
}

#searchModal.sm-open {
    animation: smSlideIn 0.22s cubic-bezier(0.4, 0, 0.2, 1) forwards;
}
@keyframes smSlideIn {
    from { opacity: 0; transform: translateY(12px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* ── TOP BAR ── */
.sm-topbar {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 18px 20px;
    padding-top: calc(env(safe-area-inset-top) + 18px);
    border-bottom: 1px solid rgba(255,255,255,0.07);
    flex-shrink: 0;
    background: #040617;
}

.sm-back-btn {
    background: transparent;
    border: none;
    border-radius: 0;
    width: auto;
    height: auto;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 28px;
    cursor: pointer;
    flex-shrink: 0;
    padding: 0 2px;
    margin-left: -9px;
    transition: opacity 0.2s;
    -webkit-tap-highlight-color: transparent;
}
.sm-back-btn:hover  { opacity: 0.7; }
.sm-back-btn:active { opacity: 0.45; }

#modalSearchWrap {
    flex: 1;
    display: flex;
    align-items: center;
    background: rgba(255,255,255,0.07);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 14px;
    overflow: hidden;
    height: 52px;
    transition: border-color 0.2s;
}
#modalSearchWrap.focused { border-color: rgba(255,45,45,0.45); }

@media (max-width: 780px) {
    #modalSearchWrap { height: 38px; border-radius: 13px; }
}
@media (max-width: 490px) {
    #modalSearchWrap { height: 36px; border-radius: 10px; }
}
@media (max-width: 380px) {
    #modalSearchWrap { height: 33px; border-radius: 8px; }
}
@media (max-width: 330px) {
    #modalSearchWrap { height: 30px; border-radius: 8px; }
}

.sm-search-icon {
    padding: 0 8px 0 16px;
    color: rgba(255,255,255,0.4);
    font-size: 16px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
}

#modalSearchInput {
    flex: 1;
    padding: 13px 10px;
    background: transparent;
    border: none;
    outline: none;
    color: white;
    font-size: 15px;
    font-family: 'Poppins', sans-serif;
}
#modalSearchInput::placeholder { color: rgba(255,255,255,0.35); }

#modalClearBtn {
    display: none;
    background: transparent;
    border: none;
    color: rgba(255,255,255,0.4);
    padding: 0 14px;
    font-size: 18px;
    cursor: pointer;
    flex-shrink: 0;
    -webkit-tap-highlight-color: transparent;
    line-height: 1;
    transition: color 0.2s;
}
#modalClearBtn:hover { color: rgba(255,255,255,0.75); }

#smSearchBtn {
    display: none;
    flex-shrink: 0;
    background: transparent;
    border: none;
    color: #FF2D2D;
    font-size: 15px;
    font-weight: 600;
    font-family: 'Poppins', sans-serif;
    cursor: pointer;
    padding: 4px 2px;
    white-space: nowrap;
    -webkit-tap-highlight-color: transparent;
    transition: opacity 0.2s;
}
#smSearchBtn:hover  { opacity: 0.75; }
#smSearchBtn:active { opacity: 0.5; }

/* ── CONTENT SCROLL AREA ── */
#searchModalContent {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
    -webkit-overflow-scrolling: touch;
    overscroll-behavior: contain;
    touch-action: pan-y;
}

/* ── RECENTS SECTION ── */
#searchRecents { padding: 18px 22px 0; }

.sm-recents-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 14px;
}

.sm-recents-title {
    font-size: 15px;
    font-weight: 600;
    color: white;
    font-family: 'Poppins', sans-serif;
}

#smClearAllBtn {
    display: none;
    background: transparent;
    border: none;
    color: #FF2D2D;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    font-family: 'Poppins', sans-serif;
    -webkit-tap-highlight-color: transparent;
    transition: opacity 0.2s;
}
#smClearAllBtn:hover { opacity: 0.75; }

.sm-no-recents {
    color: rgba(255,255,255,0.28);
    font-size: 13px;
    padding: 8px 0 20px;
    font-family: 'Poppins', sans-serif;
}

/* ── LIVE RESULTS ── */
#searchLiveResults { display: none; padding: 6px 0; }

/* ── RECENT ROWS ── */
.sm-recent-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid rgba(255,255,255,0.05);
    cursor: pointer;
    transition: opacity 0.15s;
    -webkit-tap-highlight-color: transparent;
}
.sm-recent-row:last-child { border-bottom: none; }
.sm-recent-row:active { opacity: 0.55; }

.sm-r-avatar {
    width: 44px; height: 44px;
    border-radius: 50%; object-fit: cover;
    border: 2px solid #FF2D2D; flex-shrink: 0;
}
.sm-r-avatar.grp  { border-radius: 10px; border-color: #0673f0; }
.sm-r-avatar.meme-av { border-radius: 8px; border-color: rgba(255,255,255,0.2); }
.sm-r-avatar.pid-av { border-color: #FF2D2D; }

.sm-r-query-icon {
    width: 44px; height: 44px;
    border-radius: 50%;
    background: rgba(255,255,255,0.06);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.sm-r-query-icon i { font-size: 18px; color: rgba(255,255,255,0.4); }

.sm-r-icon-box {
    width: 44px; height: 44px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    font-size: 20px;
}
.sm-r-icon-box.col  { background: rgba(255,201,71,0.12); border: 1.5px solid rgba(255,201,71,0.25); }
.sm-r-icon-box.col i  { color: #ffc947; font-size: 18px; }
.sm-r-icon-box.sound { background: rgba(100,200,255,0.1); border: 1.5px solid rgba(100,200,255,0.22); }
.sm-r-icon-box.sound i { color: #64c8ff; font-size: 18px; }
.sm-r-icon-box.scol  { background: rgba(150,120,255,0.1); border: 1.5px solid rgba(150,120,255,0.25); }
.sm-r-icon-box.scol i { color: #b09aff; font-size: 18px; }
.sm-r-icon-box.wiki  { background: rgba(80,200,140,0.1); border: 1.5px solid rgba(80,200,140,0.22); }
.sm-r-icon-box.wiki i { color: #50c88c; font-size: 18px; }
.sm-r-icon-box.pid  { background: rgba(255,45,45,0.1); border: 1.5px solid rgba(255,45,45,0.25); }
.sm-r-icon-box.pid i { color: #FF2D2D; font-size: 18px; }

.sm-r-video-wrap {
    width: 44px; height: 44px;
    border-radius: 8px;
    border: 2px solid rgba(255,255,255,0.2);
    background: #111;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; position: relative; overflow: hidden;
}
.sm-r-video-wrap video { width: 100%; height: 100%; object-fit: cover; border-radius: 6px; }
.sm-r-video-wrap .sm-play-overlay { position: absolute; color: rgba(255,255,255,0.75); font-size: 14px; pointer-events: none; }

.sm-r-info { flex: 1; min-width: 0; }
.sm-r-name {
    font-size: 13px; font-weight: 600; color: white;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    display: flex; align-items: center; gap: 5px;
    font-family: 'Poppins', sans-serif;
}
.sm-r-sub {
    font-size: 11px; color: rgba(255,255,255,0.4); margin-top: 2px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    font-family: 'Poppins', sans-serif;
    display: flex; align-items: center; gap: 5px;
}

/* ── NSFW BADGE ── */
.sm-nsfw-badge {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    background: rgba(255,45,45,0.15);
    border: 1px solid rgba(255,45,45,0.45);
    border-radius: 6px;
    padding: 1px 5px;
    font-size: 9px;
    font-weight: 800;
    color: #FF2D2D;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    flex-shrink: 0;
    font-family: 'Poppins', sans-serif;
    line-height: 1.4;
}

/* ── MUTED / NOTIF OFF INDICATORS ── */
.sm-muted-icon {
    display: inline-flex;
    align-items: center;
    color: rgba(255,200,50,0.7);
    font-size: 11px;
    flex-shrink: 0;
}
.sm-notif-off-icon {
    display: inline-flex;
    align-items: center;
    color: rgba(255,255,255,0.35);
    font-size: 11px;
    flex-shrink: 0;
}

.sm-pid-badge {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    font-size: 10px;
    font-weight: 600;
    font-family: 'Poppins', sans-serif;
    color: #FF2D2D;
    letter-spacing: 0.04em;
}
.sm-pid-badge i { font-size: 10px; }

.sm-rm-btn {
    background: transparent; border: none;
    color: rgba(255,255,255,0.28); font-size: 19px;
    cursor: pointer; padding: 4px 6px; flex-shrink: 0;
    -webkit-tap-highlight-color: transparent;
    line-height: 1; display: flex; align-items: center; justify-content: center;
    transition: color 0.2s;
}
.sm-rm-btn:hover { color: rgba(255,255,255,0.65); }

/* ── LIVE RESULT ROWS ── */
.sm-res-row {
    display: flex; align-items: center; gap: 12px;
    padding: 11px 22px; cursor: pointer;
    transition: background 0.15s;
    -webkit-tap-highlight-color: transparent;
}
.sm-res-row:hover, .sm-res-row:active { background: rgba(255,255,255,0.04); }

.sm-res-avatar {
    width: 46px; height: 46px;
    border-radius: 50%; object-fit: cover;
    border: 2px solid #FF2D2D; flex-shrink: 0;
}
.sm-res-avatar.grp  { border-radius: 10px; border-color: #0673f0; }
.sm-res-avatar.meme { border-radius: 8px; border-color: rgba(255,255,255,0.15); }
.sm-res-avatar.pid  { border-color: #FF2D2D; }

.sm-res-icon-box {
    width: 46px; height: 46px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.sm-res-icon-box.col  { background: rgba(255,201,71,0.12); border: 1.5px solid rgba(255,201,71,0.28); }
.sm-res-icon-box.col i  { color: #ffc947; font-size: 20px; }
.sm-res-icon-box.sound { background: rgba(100,200,255,0.1); border: 1.5px solid rgba(100,200,255,0.25); }
.sm-res-icon-box.sound i { color: #64c8ff; font-size: 20px; }
.sm-res-icon-box.scol  { background: rgba(150,120,255,0.1); border: 1.5px solid rgba(150,120,255,0.28); }
.sm-res-icon-box.scol i { color: #b09aff; font-size: 20px; }
.sm-res-icon-box.wiki  { background: rgba(80,200,140,0.1); border: 1.5px solid rgba(80,200,140,0.25); }
.sm-res-icon-box.wiki i { color: #50c88c; font-size: 20px; }
.sm-res-icon-box.pid  { background: rgba(255,45,45,0.1); border: 1.5px solid rgba(255,45,45,0.28); }
.sm-res-icon-box.pid i { color: #FF2D2D; font-size: 20px; }

.sm-res-video-wrap {
    width: 46px; height: 46px;
    border-radius: 8px; border: 2px solid rgba(255,255,255,0.15);
    background: #111; display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; position: relative; overflow: hidden;
}
.sm-res-video-wrap video { width: 100%; height: 100%; object-fit: cover; border-radius: 6px; }
.sm-res-video-wrap .sm-play-overlay { position: absolute; color: rgba(255,255,255,0.75); font-size: 16px; pointer-events: none; }

.sm-res-info { flex: 1; min-width: 0; }
.sm-res-name {
    font-size: 13px; font-weight: 600; color: white;
    display: flex; align-items: center; gap: 5px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    font-family: 'Poppins', sans-serif;
}
.sm-res-name.meme-caption { color: rgba(255,255,255,0.85); }
.sm-res-sub {
    font-size: 11px; color: rgba(255,255,255,0.4); margin-top: 2px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    font-family: 'Poppins', sans-serif;
    display: flex; align-items: center; gap: 5px;
}

.sm-pid-pill {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    background: rgba(255,45,45,0.1);
    border: 1px solid rgba(255,45,45,0.3);
    border-radius: 20px;
    padding: 1px 7px 1px 5px;
    font-size: 10px;
    font-weight: 600;
    color: #FF2D2D;
    letter-spacing: 0.05em;
    font-family: 'Poppins', sans-serif;
    white-space: nowrap;
}
.sm-pid-pill i { font-size: 9px; }

.sm-res-icon { color: rgba(255,255,255,0.18); font-size: 15px; flex-shrink: 0; }
.sm-res-icon.red    { color: #FF2D2D; }
.sm-res-icon.gold   { color: #ffc947; }
.sm-res-icon.blue   { color: #64c8ff; }
.sm-res-icon.purple { color: #b09aff; }
.sm-res-icon.green  { color: #50c88c; }
.sm-res-icon.violet { color: #FF2D2D; }

.sm-sec {
    font-size: 10px; font-weight: 700; letter-spacing: 1.1px;
    color: rgba(255,255,255,0.32); text-transform: uppercase;
    padding: 14px 22px 2px; font-family: 'Poppins', sans-serif;
}
.sm-sec.pid-sec { color: rgba(255,45,45,0.6); }

.sm-full-search-row { border-top: 1px solid rgba(255,255,255,0.06); margin-top: 6px; }

.sm-full-search-icon {
    width: 46px; height: 46px; border-radius: 50%;
    background: rgba(255,45,45,0.1); border: 2px solid rgba(255,45,45,0.3);
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.sm-full-search-icon i { color: #FF2D2D; font-size: 17px; }
.sm-full-search-label { color: #FF2D2D; }

.sm-hl { color: #FF2D2D; font-weight: 700; }
.sm-hl-pid { color: #FF2D2D; font-weight: 700; }

.sm-empty {
    display: flex; flex-direction: column; align-items: center;
    justify-content: center; padding: 60px 20px; gap: 12px;
    color: rgba(255,255,255,0.3); font-family: 'Poppins', sans-serif;
}
.sm-empty i { font-size: 46px; }
.sm-empty p { font-size: 14px; font-weight: 500; }
.sm-empty small { font-size: 12px; }

.sm-loader { padding: 28px; text-align: center; color: rgba(255,255,255,0.3); }
.sm-loader i { font-size: 22px; }
.sm-spin { animation: smSpin 0.7s linear infinite; display: inline-block; }
@keyframes smSpin { to { transform: rotate(360deg); } }

/* ── SEE MORE / COLLAPSE ROW ── */
#smSeeMoreRow {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 4px 0 8px;
}
#smSeeMoreBtn,
#smCollapseBtn,
#smCollapseBtn2 {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    font-size: 11px;
    color: #f00;
    background: none;
    border: none;
    cursor: pointer;
    padding: 4px 0;
    opacity: 0.85;
    font-family: 'Poppins', sans-serif;
    -webkit-tap-highlight-color: transparent;
}
#smSeeMoreBtn:hover,
#smCollapseBtn:hover,
#smCollapseBtn2:hover { opacity: 1; }
#smSeeMoreBtn i,
#smCollapseBtn i,
#smCollapseBtn2 i { font-size: 12px; }

.sm-recents-actions {
    display: flex;
    align-items: center;
    gap: 10px;
}

/* ── SUGGESTIONS SECTION ── */
#smSuggestionsWrap { padding: 4px 0 8px; }

.sm-tag-row {
    display: flex; align-items: center; gap: 11px;
    width: 100%; padding: 8px 0;
    background: none; border: none; cursor: pointer;
    text-align: left; transition: background 0.13s;
    box-sizing: border-box;
    -webkit-tap-highlight-color: transparent;
}
.sm-tag-row:hover { background: rgba(255,255,255,0.05); }

.sm-tag-icon-wrap {
    width: 36px; height: 36px; min-width: 36px;
    border-radius: 50%; display: flex; align-items: center;
    justify-content: center; font-size: 15px;
    background: rgba(255,255,255,0.07);
    color: rgba(255,255,255,0.45); flex-shrink: 0;
}

.sm-tag-text { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex: 1; }
.sm-tag-name {
    font-size: 13px; font-weight: 500;
    color: rgba(255,255,255,0.88);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    line-height: 1.3;
}
.sm-tag-sub {
    font-size: 11px; color: rgba(255,255,255,0.35);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    line-height: 1.3;
}

.sm-tag-arr { font-size: 15px; color: rgba(255,255,255,0.18); flex-shrink: 0; margin-left: auto; }

.sm-tag-trending .sm-tag-icon-wrap { background: rgba(255,80,40,0.12); color: #ff7a5a; }
.sm-tag-trending .sm-tag-name      { color: #ff9472; }
.sm-tag-trending .sm-tag-arr       { color: rgba(255,100,60,0.3); }

.sm-tag-personal .sm-tag-icon-wrap { background: rgba(80,160,255,0.12); color: #7ec8ff; }
.sm-tag-personal .sm-tag-name      { color: #90cfff; }
.sm-tag-personal .sm-tag-arr       { color: rgba(80,160,255,0.3); }

.sm-tag-related .sm-tag-icon-wrap  { background: rgba(120,200,140,0.12); color: #7ecf97; }
.sm-tag-related .sm-tag-name       { color: #8fd9a8; }
.sm-tag-related .sm-tag-arr        { color: rgba(100,200,130,0.3); }

/* ══ INLINE ACTION BUTTONS ══ */
.sm-track-btn {
    flex-shrink: 0;
    padding: 5px 11px;
    border-radius: 20px;
    border: 1.5px solid rgba(255,45,45,0.55);
    background: transparent;
    color: rgba(255,255,255,0.85);
    font-size: 11px;
    font-weight: 600;
    font-family: 'Poppins', sans-serif;
    cursor: pointer;
    transition: background 0.2s, color 0.2s, border-color 0.2s;
    white-space: nowrap;
    -webkit-tap-highlight-color: transparent;
    line-height: 1;
}
.sm-track-btn:hover,
.sm-track-btn:active { background: rgba(255,45,45,0.18); border-color: #FF2D2D; color: #fff; }
.sm-track-btn.tracking { border-color: rgba(255,45,45,0.3); color: #FF2D2D; }
.sm-track-btn.tracking:hover { background: rgba(255,45,45,0.12); }

.sm-private-label {
    flex-shrink: 0;
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 10px;
    font-weight: 600;
    font-family: 'Poppins', sans-serif;
    color: rgba(255,255,255,0.35);
    white-space: nowrap;
    cursor: default;
    padding: 5px 8px;
    border-radius: 20px;
    border: 1.5px solid rgba(255,255,255,0.1);
    background: rgba(255,255,255,0.04);
    transition: background 0.2s, color 0.2s, border-color 0.2s;
    -webkit-tap-highlight-color: transparent;
    line-height: 1;
}
.sm-private-label.clickable { cursor: pointer; }
.sm-private-label.clickable:hover {
    background: rgba(255,255,255,0.08);
    border-color: rgba(255,255,255,0.2);
    color: rgba(255,255,255,0.6);
}
.sm-private-label i { font-size: 10px; }

.sm-blocked-label {
    flex-shrink: 0;
    padding: 5px 10px;
    border-radius: 20px;
    border: 1.5px solid rgba(255,45,45,0.25);
    background: rgba(255,45,45,0.08);
    color: rgba(255,45,45,0.6);
    font-size: 10px;
    font-weight: 600;
    font-family: 'Poppins', sans-serif;
    white-space: nowrap;
    cursor: default;
    line-height: 1;
}

.sm-group-btn {
    flex-shrink: 0;
    padding: 5px 11px;
    border-radius: 20px;
    border: 1.5px solid rgba(6,115,240,0.55);
    background: transparent;
    color: rgba(255,255,255,0.85);
    font-size: 11px;
    font-weight: 600;
    font-family: 'Poppins', sans-serif;
    cursor: pointer;
    transition: background 0.2s, color 0.2s, border-color 0.2s;
    white-space: nowrap;
    -webkit-tap-highlight-color: transparent;
    line-height: 1;
}
.sm-group-btn:hover,
.sm-group-btn:active { background: rgba(6,115,240,0.18); border-color: #0673f0; color: #fff; }
.sm-group-btn.joined { border-color: rgba(6,115,240,0.3); color: #0673f0; }
.sm-group-btn.joined:hover { background: rgba(6,115,240,0.12); }

.sm-view-btn {
    flex-shrink: 0;
    padding: 5px 11px;
    border-radius: 20px;
    border: 1.5px solid rgba(255,201,71,0.4);
    background: transparent;
    color: #ffc947;
    font-size: 11px;
    font-weight: 600;
    font-family: 'Poppins', sans-serif;
    cursor: pointer;
    white-space: nowrap;
    -webkit-tap-highlight-color: transparent;
    line-height: 1;
    transition: background 0.2s;
}
.sm-view-btn:hover { background: rgba(255,201,71,0.12); }
.sm-view-btn.blue-v   { border-color: rgba(100,200,255,0.4); color: #64c8ff; }
.sm-view-btn.blue-v:hover { background: rgba(100,200,255,0.1); }
.sm-view-btn.purple-v { border-color: rgba(150,120,255,0.4); color: #b09aff; }
.sm-view-btn.purple-v:hover { background: rgba(150,120,255,0.1); }
.sm-view-btn.green-v  { border-color: rgba(80,200,140,0.4); color: #50c88c; }
.sm-view-btn.green-v:hover { background: rgba(80,200,140,0.1); }

/* ── SCROLLBARS ── */
#searchModalContent {
    scrollbar-width: thin;
    scrollbar-color: rgba(80,95,140,0.35) transparent;
}
#searchModalContent::-webkit-scrollbar { width: 4px; }
#searchModalContent::-webkit-scrollbar-track { background: transparent; }
#searchModalContent::-webkit-scrollbar-thumb { background: rgba(80,95,140,0.35); border-radius: 999px; }
#searchModalContent::-webkit-scrollbar-thumb:hover { background: rgba(160,180,220,0.7); }

/* ════════════════════ RESPONSIVE ════════════════════ */
@media (max-width: 780px) {
    .sm-topbar { padding: 14px 16px; padding-top: calc(env(safe-area-inset-top) + 14px); gap: 10px; }
    .sm-back-btn { font-size: 26px; }
    #modalSearchInput { font-size: 14px; padding: 12px 8px; }
    #searchRecents { padding: 16px 16px 0; }
    .sm-recents-title { font-size: 14px; }
    #smClearAllBtn { font-size: 12px; }
    #smSearchBtn { font-size: 13px; }
    .sm-sec { padding: 12px 16px 2px; font-size: 10px; }
    .sm-res-row { padding: 10px 16px; gap: 11px; }
    .sm-res-avatar, .sm-res-video-wrap, .sm-res-icon-box { width: 43px; height: 43px; }
    .sm-r-avatar, .sm-r-video-wrap, .sm-r-query-icon, .sm-r-icon-box { width: 42px; height: 42px; }
    .sm-res-name, .sm-r-name { font-size: 13px; }
    .sm-res-sub, .sm-r-sub { font-size: 11px; }
    .sm-full-search-icon { width: 43px; height: 43px; }
    .sm-tag-row { padding: 7px 0; gap: 10px; }
    .sm-tag-icon-wrap { width: 33px; height: 33px; min-width: 33px; font-size: 14px; }
    .sm-tag-name { font-size: 12.5px; }
    .sm-tag-sub { font-size: 10.5px; }
    .sm-tag-arr { font-size: 14px; }
    .sm-nsfw-badge { font-size: 8px; padding: 1px 4px; }
    .sm-muted-icon, .sm-notif-off-icon { font-size: 10px; }
}

@media (max-width: 490px) {
    .sm-topbar { padding: 12px 13px; padding-top: calc(env(safe-area-inset-top) + 12px); gap: 9px; }
    .sm-back-btn { font-size: 24px; }
    .sm-search-icon { padding: 0 6px 0 12px; font-size: 15px; }
    #modalSearchInput { font-size: 13px; padding: 11px 8px; }
    #modalClearBtn { padding: 0 11px; font-size: 17px; }
    #smSearchBtn { font-size: 12px; }
    #searchRecents { padding: 14px 13px 0; }
    .sm-recents-title { font-size: 13px; }
    #smClearAllBtn { font-size: 11px; }
    .sm-recents-header { margin-bottom: 11px; }
    .sm-sec { padding: 11px 13px 2px; font-size: 9px; letter-spacing: 1px; }
    .sm-res-row { padding: 10px 13px; gap: 10px; }
    .sm-res-avatar, .sm-res-video-wrap, .sm-res-icon-box { width: 40px; height: 40px; }
    .sm-r-avatar, .sm-r-video-wrap, .sm-r-query-icon, .sm-r-icon-box { width: 40px; height: 40px; }
    .sm-res-name, .sm-r-name { font-size: 12px; gap: 4px; }
    .sm-res-sub, .sm-r-sub { font-size: 10px; }
    .sm-res-icon { font-size: 13px; }
    .sm-full-search-icon { width: 40px; height: 40px; }
    .sm-full-search-icon i { font-size: 15px; }
    .sm-empty { padding: 45px 16px; gap: 10px; }
    .sm-empty i { font-size: 38px; }
    .sm-empty p { font-size: 13px; }
    .sm-empty small { font-size: 11px; }
    .sm-tag-row { padding: 7px 0; gap: 9px; }
    .sm-tag-icon-wrap { width: 30px; height: 30px; min-width: 30px; font-size: 13px; }
    .sm-tag-name { font-size: 12px; }
    .sm-tag-sub { font-size: 10px; }
    .sm-tag-arr { font-size: 13px; }
    .sm-track-btn, .sm-group-btn, .sm-view-btn { padding: 4px 9px; font-size: 10px; }
    .sm-private-label { padding: 4px 7px; font-size: 9px; }
    .sm-blocked-label { padding: 4px 8px; font-size: 9px; }
    .sm-nsfw-badge { font-size: 8px; padding: 1px 4px; border-radius: 5px; }
    .sm-muted-icon, .sm-notif-off-icon { font-size: 10px; }
}

@media (max-width: 380px) {
    .sm-topbar { padding: 10px 11px; padding-top: calc(env(safe-area-inset-top) + 10px); gap: 8px; }
    .sm-back-btn { font-size: 22px; }
    .sm-search-icon { padding: 0 5px 0 10px; font-size: 14px; }
    #modalSearchInput { font-size: 12px; padding: 10px 6px; }
    #smSearchBtn { font-size: 10.9px; }
    #searchRecents { padding: 12px 11px 0; }
    .sm-recents-title { font-size: 12px; }
    #smClearAllBtn { font-size: 11px; }
    .sm-sec { padding: 10px 11px 2px; }
    .sm-res-row { padding: 9px 11px; gap: 9px; }
    .sm-res-avatar, .sm-res-video-wrap, .sm-res-icon-box { width: 37px; height: 37px; }
    .sm-r-avatar, .sm-r-video-wrap, .sm-r-query-icon, .sm-r-icon-box { width: 37px; height: 37px; }
    .sm-res-name, .sm-r-name { font-size: 11px; }
    .sm-res-sub, .sm-r-sub { font-size: 10px; }
    .sm-res-icon { font-size: 12px; }
    .sm-full-search-icon { width: 37px; height: 37px; }
    .sm-full-search-icon i { font-size: 14px; }
    .sm-rm-btn { font-size: 17px; padding: 3px 5px; }
    .sm-tag-row { padding: 6px 0; gap: 8px; }
    .sm-tag-icon-wrap { width: 28px; height: 28px; min-width: 28px; font-size: 12px; }
    .sm-tag-name { font-size: 11.5px; }
    .sm-tag-sub { font-size: 9.5px; }
    .sm-tag-arr { font-size: 12px; }
    .sm-track-btn, .sm-group-btn, .sm-view-btn { padding: 4px 8px; font-size: 9px; }
    .sm-private-label { padding: 4px 6px; font-size: 8.5px; }
    .sm-blocked-label { padding: 4px 7px; font-size: 8.5px; }
    .sm-nsfw-badge { font-size: 7.5px; padding: 1px 3px; }
    .sm-muted-icon, .sm-notif-off-icon { font-size: 9px; }
}

@media (max-width: 320px) {
    .sm-topbar { padding: 9px 9px; padding-top: calc(env(safe-area-inset-top) + 9px); gap: 7px; }
    .sm-back-btn { font-size: 20px; }
    .sm-search-icon { padding: 0 4px 0 9px; font-size: 13px; }
    #modalSearchInput { font-size: 11px; padding: 9px 5px; }
    #smSearchBtn { font-size: 10px; }
    #searchRecents { padding: 10px 9px 0; }
    .sm-sec { padding: 9px 9px 2px; font-size: 8px; }
    .sm-res-row { padding: 8px 9px; gap: 8px; }
    .sm-res-avatar, .sm-res-video-wrap, .sm-res-icon-box { width: 34px; height: 34px; }
    .sm-r-avatar, .sm-r-video-wrap, .sm-r-query-icon, .sm-r-icon-box { width: 34px; height: 34px; }
    .sm-res-name, .sm-r-name { font-size: 11px; }
    .sm-res-sub, .sm-r-sub { font-size: 9px; }
    .sm-full-search-icon { width: 34px; height: 34px; }
    .sm-full-search-icon i { font-size: 13px; }
    .sm-empty i { font-size: 32px; }
    .sm-empty p { font-size: 12px; }
    .sm-empty small { font-size: 10px; }
    .sm-nsfw-badge { font-size: 7px; padding: 1px 3px; }
}

@media (max-width: 768px) {
    #searchModalContent {
        scrollbar-width: none;
        -ms-overflow-style: none;
    }
    #searchModalContent::-webkit-scrollbar { display: none; }
}
</style>

<script>
/* ══════════════════════════════════════════════
   DEZZME SEARCH MODAL ENGINE
   Updated: NSFW badge, mute/notif indicators,
   blue group dot removed + field name consistency
   + wired to open frModal (full results) instead
     of redirecting to search.php?q=...
══════════════════════════════════════════════ */
var SM_ENDPOINT   = 'search_recents.php';
var smTimer       = null;
var smLastQ       = '';
var smOpen        = false;
var SM_PAGE_SIZE  = 5;
var _smAllRecents = [];
var _smExpanded   = false;

/* ── helpers ── */
function smEsc(s) {
    var d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
}
function smHL(str, q) {
    if (!q) return smEsc(str);
    return smEsc(str).replace(
        new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi'),
        '<span class="sm-hl">$1</span>'
    );
}
function smHLpid(str, q) {
    if (!q) return smEsc(str);
    var clean = q.replace(/^dz-?/i, '');
    if (!clean) return smEsc(str);
    return smEsc(str).replace(
        new RegExp('(' + clean.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi'),
        '<span class="sm-hl-pid">$1</span>'
    );
}

/* ── Build NSFW badge HTML ── */
function smNSFWbadge(isAdult) {
    if (!isAdult) return '';
    return '<span class="sm-nsfw-badge">NSFW</span>';
}

/* ── Build mute / notif-off indicator HTML for sub-line ── */
function smGroupStatusIcons(isMuted, isNotifOff) {
    var html = '';
    if (isMuted)    html += '<span class="sm-muted-icon" title="Muted"><i class="ri-volume-mute-line"></i></span>';
    if (isNotifOff) html += '<span class="sm-notif-off-icon" title="Notifications off"><i class="ri-notification-off-line"></i></span>';
    return html;
}

/* ── Server-side recent helpers ── */
function smGetRecents(cb) {
    fetch(SM_ENDPOINT + '?action=get')
        .then(function(r) { return r.json(); })
        .then(function(d) { cb(Array.isArray(d) ? d : []); })
        .catch(function()  { cb([]); });
}
function smGetSuggestions(cb) {
    fetch(SM_ENDPOINT + '?action=suggestions')
        .then(function(r) { return r.json(); })
        .then(function(d) { cb(Array.isArray(d) ? d : []); })
        .catch(function()  { cb([]); });
}
function smAddRecent(item) {
    fetch(SM_ENDPOINT + '?action=add', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(item)
    }).catch(function() {});
}
function smRemoveRecent(type, id) {
    fetch(SM_ENDPOINT + '?action=remove', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ type: type, id: id })
    })
    .then(function() { smRenderRecents(); })
    .catch(function() { smRenderRecents(); });
}
function clearAllRecents() {
    fetch(SM_ENDPOINT + '?action=clear', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: '{}'
    })
    .then(function() { _smExpanded = false; smRenderRecents(); })
    .catch(function() { _smExpanded = false; smRenderRecents(); });
}

/* ════════════ PTR LOCK ════════════ */
var _smPtrLocked = false;
function _smLockPTR() {
    if (_smPtrLocked) return;
    _smPtrLocked = true;
    document.addEventListener('touchstart', _smPtrBlocker, { passive: false, capture: true });
    document.addEventListener('touchmove',  _smPtrBlocker, { passive: false, capture: true });
}
function _smUnlockPTR() {
    if (!_smPtrLocked) return;
    _smPtrLocked = false;
    document.removeEventListener('touchstart', _smPtrBlocker, { capture: true });
    document.removeEventListener('touchmove',  _smPtrBlocker, { capture: true });
}
function _smPtrBlocker(e) {
    var content = document.getElementById('searchModalContent');
    if (content && content.contains(e.target)) return;
    if (smOpen) { e.stopImmediatePropagation(); }
}

/* ── Open / Close ── */
function openSearchModal() {
    if (smOpen) return;
    smOpen = true;
    var m = document.getElementById('searchModal');
    m.style.display = 'flex';
    m.classList.add('sm-open');
    document.body.style.overflow = 'hidden';
    _smExpanded = false;
    _smLockPTR();
    smRenderRecents();
    setTimeout(function() {
        var inp = document.getElementById('modalSearchInput');
        if (inp) inp.focus();
    }, 150);
}
function closeSearchModal() {
    if (!smOpen) return;
    smOpen = false;
    var m = document.getElementById('searchModal');
    m.style.display = 'none';
    m.classList.remove('sm-open');
    document.body.style.overflow = '';
    var inp = document.getElementById('modalSearchInput');
    if (inp) inp.value = '';
    document.getElementById('modalClearBtn').style.display     = 'none';
    document.getElementById('smSearchBtn').style.display       = 'none';
    document.getElementById('searchLiveResults').style.display = 'none';
    document.getElementById('searchRecents').style.display     = 'block';
    smLastQ     = '';
    _smExpanded = false;
    _smUnlockPTR();
    clearTimeout(smTimer);
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && smOpen) closeSearchModal();
});

/* block overscroll PTR inside modal */
(function() {
    var content = document.getElementById('searchModalContent');
    var _startY = 0;
    if (!content) return;
    content.addEventListener('touchstart', function(e) {
        _startY = e.touches[0].clientY;
    }, { passive: true });
    content.addEventListener('touchmove', function(e) {
        if (!smOpen) return;
        var dy    = e.touches[0].clientY - _startY;
        var atTop = content.scrollTop <= 0;
        if (atTop && dy > 0) { e.stopPropagation(); }
    }, { passive: true, capture: false });
})();

/* ── Recent item cache ── */
var _smRecentCache = {};

/* ── Expand / Collapse recents ── */
function smExpandRecents()  { _smExpanded = true;  smDrawRecents(_smAllRecents); }
function smCollapseRecents(){ _smExpanded = false; smDrawRecents(_smAllRecents); }

/* ══════════════════════════════════════════════
   DRAW RECENTS — Groups now show NSFW, Muted & Notif Off
══════════════════════════════════════════════ */
function smDrawRecents(arr) {
    var list       = document.getElementById('recentsList');
    var clearBtn   = document.getElementById('smClearAllBtn');
    var seeMoreRow = document.getElementById('smSeeMoreRow');
    var seeMoreBtn = document.getElementById('smSeeMoreBtn');
    var colBtn     = document.getElementById('smCollapseBtn');
    var colBtn2    = document.getElementById('smCollapseBtn2');
    if (!list) return;

    _smRecentCache = {};

    var total   = arr.length;
    var hasMore = total > SM_PAGE_SIZE;
    var visible = _smExpanded ? arr : arr.slice(0, SM_PAGE_SIZE);

    clearBtn.style.display = total ? 'inline-block' : 'none';

    if (hasMore) {
        seeMoreRow.style.display = 'flex';
        if (_smExpanded) {
            seeMoreBtn.style.display = 'none';
            colBtn2.style.display    = 'inline-flex';
            colBtn.style.display     = 'inline-flex';
        } else {
            seeMoreBtn.style.display = 'inline-flex';
            colBtn2.style.display    = 'none';
            colBtn.style.display     = 'none';
        }
    } else {
        seeMoreRow.style.display = 'none';
        colBtn.style.display     = 'none';
    }

    document.getElementById('smSuggestionsWrap').style.display = 'block';

    if (!total) {
        list.innerHTML = '<div class="sm-no-recents">No recent searches yet</div>';
        smLoadSuggestions(true);
        return;
    }

    smLoadSuggestions(false);

    list.innerHTML = visible.map(function(r, idx) {
        var defPic = 'uploads/mee.jpeg';
        var name, subParts = [], avatarHtml, nameSuffix = '';

        _smRecentCache[idx] = r;

        if (r.type === 'profile_id') {
            var pic  = r.profile_pic || defPic;
            name = r.username || '';
            subParts.push('<span class="sm-pid-badge"><i class="ri-fingerprint-line"></i>' + smEsc(r.dz_id || '') + '</span>');
            avatarHtml = '<img class="sm-r-avatar pid-av" src="' + smEsc(pic) + '" onerror="this.src=\'' + defPic + '\'">';

        } else if (r.type === 'user') {
            var pic  = r.profile_pic || defPic;
            name = r.username || '';
            subParts.push(r.nickname ? '@' + smEsc(r.nickname) : 'User');
            avatarHtml = '<img class="sm-r-avatar" src="' + smEsc(pic) + '" onerror="this.src=\'' + defPic + '\'">';

        } else if (r.type === 'group') {
            var gpic = r.group_pic
                ? (r.group_pic.indexOf('uploads/') === 0 ? r.group_pic : 'uploads/' + r.group_pic)
                : 'uploads/default_group.png';
            name = r.title || '';

            if (r.is_adult) nameSuffix = smNSFWbadge(true);

            subParts.push(smEsc(String(r.member_count || 0)) + ' members');
            var statusHtml = smGroupStatusIcons(r.is_muted, r.is_notif_off || r.notif_off);
            if (statusHtml) subParts.push(statusHtml);

            avatarHtml = '<img class="sm-r-avatar grp" src="' + smEsc(gpic) + '" onerror="this.src=\'uploads/default_group.png\'">';

        } else if (r.type === 'meme') {
            name  = (r.caption || 'Post').length > 40 ? (r.caption || 'Post').substring(0, 40) + '…' : (r.caption || 'Post');
            subParts.push('Post · by ' + smEsc(r.username || 'unknown'));
            if (r.meme_type === 'video') {
                avatarHtml = '<div class="sm-r-video-wrap"><video src="uploads/' + smEsc(r.file_name) + '" muted playsinline preload="metadata"></video><i class="ri-play-fill sm-play-overlay"></i></div>';
            } else {
                var mPic = r.file_name ? 'uploads/' + r.file_name : 'uploads/Text.jpg';
                avatarHtml = '<img class="sm-r-avatar meme-av" src="' + smEsc(mPic) + '" onerror="this.src=\'uploads/Text.jpg\'">';
            }

        } else if (r.type === 'collection') {
            name = r.name || 'Collection';
            subParts.push('Collection · by ' + smEsc(r.owner_username || 'unknown'));
            avatarHtml = '<div class="sm-r-icon-box col"><i class="ri-folder-3-line"></i></div>';

        } else if (r.type === 'sound') {
            name = r.title || 'Sound';
            subParts.push('Sound · by ' + smEsc(r.username || 'unknown'));
            avatarHtml = '<div class="sm-r-icon-box sound"><i class="ri-music-2-line"></i></div>';

        } else if (r.type === 'sound_collection') {
            name = r.name || 'Sound Folder';
            subParts.push('Sound Folder · by ' + smEsc(r.owner_username || 'unknown'));
            avatarHtml = '<div class="sm-r-icon-box scol"><i class="ri-folder-music-line"></i></div>';

        } else if (r.type === 'memepedia') {
            name = r.title || 'Meme Entry';
            subParts.push('Memepedia · ' + smEsc(r.status || ''));
            avatarHtml = '<div class="sm-r-icon-box wiki"><i class="ri-book-open-line"></i></div>';

        } else {
            name = r.query || '';
            subParts.push('Search');
            avatarHtml = '<div class="sm-r-query-icon"><i class="ri-search-line"></i></div>';
        }

        return '<div class="sm-recent-row" data-idx="' + idx + '" onclick="smGoToIdx(' + idx + ')">'
            + avatarHtml
            + '<div class="sm-r-info">'
                + '<div class="sm-r-name">' + smEsc(name) + (nameSuffix ? ' ' + nameSuffix : '') + '</div>'
                + '<div class="sm-r-sub">' + subParts.join(' · ') + '</div>'
            + '</div>'
            + '<button class="sm-rm-btn" onclick="event.stopPropagation();smRemoveRecent(\'' + r.type + '\',\'' + String(r.id) + '\')"><i class="ri-close-line"></i></button>'
        + '</div>';
    }).join('');
}

/* ── Load and render suggestions ── */
function smLoadSuggestions(isNewUser) {
    var title = document.getElementById('smSuggestTitle');
    var list  = document.getElementById('smSuggestionsList');
    if (!list) return;

    title.textContent = isNewUser ? 'Trending on Dezzme' : 'Suggested for you';
    list.innerHTML    = '<div class="sm-loader"><i class="ri-loader-4-line sm-spin"></i></div>';

    smGetSuggestions(function(tags) {
        if (!tags || !tags.length) {
            list.innerHTML = '';
            document.getElementById('smSuggestionsWrap').style.display = 'none';
            return;
        }

        var srcMeta = {
            personal: { cls: 'sm-tag-personal', icon: 'ri-user-heart-line',  sub: 'Based on your searches'    },
            trending: { cls: 'sm-tag-trending',  icon: 'ri-fire-line',        sub: 'Trending now'              },
            geo:      { cls: 'sm-tag-trending',  icon: 'ri-map-pin-2-line',   sub: 'Popular in your region'   },
            related:  { cls: 'sm-tag-related',   icon: 'ri-link-m',           sub: 'Related to your interests' },
            global:   { cls: '',                 icon: 'ri-global-line',      sub: 'Popular worldwide'         },
            popular:  { cls: '',                 icon: 'ri-hashtag',          sub: 'Popular on Dezzme'         },
        };
        var fallbackMeta = { cls: '', icon: 'ri-hashtag', sub: 'Popular on Dezzme' };

        var html = '';
        tags.forEach(function(t) {
            var src  = t.source || 'popular';
            var meta = srcMeta[src] || fallbackMeta;
            var cls  = 'sm-tag-row' + (meta.cls ? ' ' + meta.cls : '');
            html += '<button class="' + cls + '" onclick="smSearchTag(\'' + smEsc(t.tag) + '\')">'
                +     '<span class="sm-tag-icon-wrap"><i class="' + meta.icon + '"></i></span>'
                +     '<span class="sm-tag-text">'
                +         '<span class="sm-tag-name">' + smEsc(t.tag) + '</span>'
                +         '<span class="sm-tag-sub">'  + meta.sub     + '</span>'
                +     '</span>'
                +     '<i class="ri-arrow-right-s-line sm-tag-arr"></i>'
                + '</button>';
        });

        list.innerHTML = html;
        document.getElementById('smSuggestionsWrap').style.display = 'block';
    });
}

function smSearchTag(tag) {
    smAddRecent({ type: 'query', id: btoa(encodeURIComponent(tag)).slice(0, 12), query: tag });
    closeSearchModal();
    openFullResultsModal(tag);
}

/* ── Main render ── */
function smRenderRecents() {
    var list = document.getElementById('recentsList');
    var btn  = document.getElementById('smClearAllBtn');
    if (!list) return;

    list.innerHTML    = '<div class="sm-loader"><i class="ri-loader-4-line sm-spin"></i></div>';
    btn.style.display = 'none';

    smGetRecents(function(arr) {
        _smAllRecents = arr;
        smDrawRecents(arr);
    });
}

/* ── Safe navigation using cache index ── */
function smGoToIdx(idx) {
    var r = _smRecentCache[idx];
    if (!r) return;
    smAddRecent(r);
    smNavigate(r.type, r.id, r);
}

/* ── Central navigation handler ── */
function smNavigate(type, id, item) {
    item = item || {};
    if      (type === 'profile_id')       location.href = 'profile.php?user_id=' + id;
    else if (type === 'user')             location.href = 'profile.php?user_id=' + id;
    else if (type === 'group')            location.href = 'group.php?id=' + id;
    else if (type === 'meme')             location.href = 'search.php?q=' + encodeURIComponent(item.caption || '');
    else if (type === 'collection')       location.href = 'profile.php?user_id=' + (item.owner_id || id) + '#memes';
    else if (type === 'sound')            location.href = 'audio.php?user_id=' + (item.user_id || id);
    else if (type === 'sound_collection') location.href = 'audio.php?user_id=' + (item.owner_id || id);
    else if (type === 'memepedia')        location.href = 'memepedia.php?open=' + id;
    else                                  location.href = 'search.php?q=' + encodeURIComponent(item.query || id);
}

function smGoTo(type, id, enc) {
    try {
        var item = JSON.parse(decodeURIComponent(enc));
        smAddRecent(item);
        smNavigate(type, id, item);
    } catch(e) {
        smNavigate(type, id, {});
    }
}

/* ══ TRACK TOGGLE ══ */
function smToggleTrack(btn, userId) {
    btn.disabled = true;
    fetch('search.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=toggle_follow&user_id=' + encodeURIComponent(userId)
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.status === 'ok') {
            if (d.following) { btn.textContent = 'Untrack'; btn.classList.add('tracking'); }
            else             { btn.textContent = 'Track';   btn.classList.remove('tracking'); }
        }
        btn.disabled = false;
    })
    .catch(function() { btn.disabled = false; });
}

/* ══ REQUEST TRACK ══ */
function smRequestTrack(btn, userId) {
    btn.disabled = true;
    fetch('search.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=request_track&user_id=' + encodeURIComponent(userId)
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.status === 'ok') {
            btn.innerHTML = '<i class="ri-time-line"></i> Requested';
            btn.classList.remove('clickable');
            btn.style.cursor = 'default';
            btn.onclick = null;
        }
        btn.disabled = false;
    })
    .catch(function() { btn.disabled = false; });
}

/* ══ GROUP JOIN/LEAVE ══ */
function smToggleGroup(btn, groupId) {
    btn.disabled = true;
    fetch('search.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=toggle_group&group_id=' + encodeURIComponent(groupId)
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.status === 'ok') {
            if (d.joined) { btn.textContent = 'Leave'; btn.classList.add('joined'); }
            else          { btn.textContent = 'Join';  btn.classList.remove('joined'); }
        }
        btn.disabled = false;
    })
    .catch(function() { btn.disabled = false; });
}

/* ── "Search" button handler ── */
function smDoSearch() {
    var q = document.getElementById('modalSearchInput').value.trim();
    if (q) {
        smAddRecent({ type: 'query', id: btoa(encodeURIComponent(q)).slice(0, 12), query: q });
        closeSearchModal();
        openFullResultsModal(q);
    }
}

/* ── Input handler ── */
document.getElementById('modalSearchInput').addEventListener('input', function() {
    var q = this.value.trim();
    var searchBtn = document.getElementById('smSearchBtn');

    document.getElementById('modalClearBtn').style.display = q ? 'block' : 'none';
    searchBtn.style.display = q ? 'inline-block' : 'none';

    if (!q) {
        clearTimeout(smTimer);
        document.getElementById('searchLiveResults').style.display = 'none';
        document.getElementById('searchRecents').style.display     = 'block';
        smLastQ = '';
        return;
    }

    document.getElementById('searchRecents').style.display     = 'none';
    document.getElementById('searchLiveResults').style.display = 'block';
    document.getElementById('searchLiveResults').innerHTML     =
        '<div class="sm-loader"><i class="ri-loader-4-line sm-spin"></i></div>';

    clearTimeout(smTimer);
    smLastQ = q;
    smTimer = setTimeout(function() {
        if (q !== smLastQ) return;
        fetch('search.php?type=autocomplete&q=' + encodeURIComponent(q))
            .then(function(r) { return r.json(); })
            .then(function(d) { smRenderResults(d, q); })
            .catch(function()  { smRenderResults([], q); });
    }, 260);
});

document.getElementById('modalSearchInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        var q = this.value.trim();
        if (q) {
            smAddRecent({ type: 'query', id: btoa(encodeURIComponent(q)).slice(0, 12), query: q });
            closeSearchModal();
            openFullResultsModal(q);
        }
    }
});

function clearModalSearch() {
    document.getElementById('modalSearchInput').value          = '';
    document.getElementById('modalClearBtn').style.display     = 'none';
    document.getElementById('smSearchBtn').style.display       = 'none';
    document.getElementById('searchLiveResults').style.display = 'none';
    document.getElementById('searchRecents').style.display     = 'block';
    document.getElementById('modalSearchInput').focus();
    smLastQ = '';
    clearTimeout(smTimer);
}

/* ══════════════════════════════════════════════
   RENDER LIVE RESULTS
   Groups: NSFW badge in name, muted/notif icons
   in sub, NO blue dot
══════════════════════════════════════════════ */
function smRenderResults(data, q) {
    var box    = document.getElementById('searchLiveResults');
    var defPic = 'uploads/mee.jpeg';

    if (!data || !data.length) {
        var isDZQuery = /^dz-/i.test(q.trim());
        box.innerHTML = '<div class="sm-empty">'
            + '<i class="' + (isDZQuery ? 'ri-fingerprint-line' : 'ri-search-2-line') + '"></i>'
            + (isDZQuery
                ? '<p>No account found for <strong style="color:#FF2D2D">' + smEsc(q.toUpperCase()) + '</strong></p>'
                  + '<small>Type the full or partial ID — e.g. <span style="color:#FF2D2D;letter-spacing:0.05em">DZ-10E34A</span></small>'
                : '<p>No results for "' + smEsc(q) + '"</p>'
                  + '<small>Try a different word, hashtag, or Profile ID (DZ-…)</small>'
              )
            + '</div>';
        return;
    }

    var pidRes = data.filter(function(d) { return d.type === 'profile_id'; });
    var users  = data.filter(function(d) { return d.type === 'user'; });
    var groups = data.filter(function(d) { return d.type === 'group'; });
    var memes  = data.filter(function(d) { return d.type === 'meme'; });
    var cols   = data.filter(function(d) { return d.type === 'collection'; });
    var sounds = data.filter(function(d) { return d.type === 'sound'; });
    var scols  = data.filter(function(d) { return d.type === 'sound_collection'; });
    var wikis  = data.filter(function(d) { return d.type === 'memepedia'; });
    var html   = '';

    /* ── PROFILE ID ── */
    if (pidRes.length) {
        html += '<div class="sm-sec pid-sec"><i class="ri-fingerprint-line" style="margin-right:4px;"></i>Profile ID</div>';
        html += pidRes.map(function(u) {
            var pic = u.profile_pic || defPic;
            var enc = encodeURIComponent(JSON.stringify(u));
            var actionHtml = '';
            if (parseInt(u.is_blocked)) {
                actionHtml = '<span class="sm-blocked-label">Blocked</span>';
            } else if (parseInt(u.is_private) && !parseInt(u.is_following) && !parseInt(u.is_track_approved)) {
                actionHtml = '<span class="sm-private-label clickable" onclick="event.stopPropagation();smRequestTrack(this,' + u.id + ')">'
                    + '<i class="ri-lock-line"></i>Private</span>';
            } else {
                var isTracking = parseInt(u.is_following) ? true : false;
                actionHtml = '<button class="sm-track-btn ' + (isTracking ? 'tracking' : '') + '" '
                    + 'onclick="event.stopPropagation();smToggleTrack(this,' + u.id + ')">'
                    + (isTracking ? 'Untrack' : 'Track') + '</button>';
            }
            return '<div class="sm-res-row" onclick="smSelectResult(\'profile_id\',' + u.id + ',\'' + enc + '\')">'
                + '<img class="sm-res-avatar pid" src="' + smEsc(pic) + '" onerror="this.src=\'' + defPic + '\'">'
                + '<div class="sm-res-info">'
                    + '<div class="sm-res-name">' + smHL(u.username, q) + '</div>'
                    + '<div class="sm-res-sub"><span class="sm-pid-pill"><i class="ri-fingerprint-line"></i>' + smHLpid(u.dz_id || '', q) + '</span></div>'
                + '</div>'
                + actionHtml
            + '</div>';
        }).join('');
    }

    /* ── USERS ── */
    if (users.length) {
        html += '<div class="sm-sec">Users</div>';
        html += users.map(function(u) {
            var pic = u.profile_pic || defPic;
            var enc = encodeURIComponent(JSON.stringify(u));
            var actionHtml = '';
            if (parseInt(u.is_blocked)) {
                actionHtml = '<span class="sm-blocked-label">Blocked</span>';
            } else if (parseInt(u.is_private) && !parseInt(u.is_following) && !parseInt(u.is_track_approved)) {
                actionHtml = '<span class="sm-private-label clickable" onclick="event.stopPropagation();smRequestTrack(this,' + u.id + ')">'
                    + '<i class="ri-lock-line"></i>Private · Request Track</span>';
            } else {
                var isTracking = parseInt(u.is_following) ? true : false;
                actionHtml = '<button class="sm-track-btn ' + (isTracking ? 'tracking' : '') + '" '
                    + 'onclick="event.stopPropagation();smToggleTrack(this,' + u.id + ')">'
                    + (isTracking ? 'Untrack' : 'Track') + '</button>';
            }
            return '<div class="sm-res-row" onclick="smSelectResult(\'user\',' + u.id + ',\'' + enc + '\')">'
                + '<img class="sm-res-avatar" src="' + smEsc(pic) + '" onerror="this.src=\'' + defPic + '\'">'
                + '<div class="sm-res-info">'
                    + '<div class="sm-res-name">' + smHL(u.username, q) + '</div>'
                    + '<div class="sm-res-sub">' + (u.nickname ? smHL('@' + u.nickname, q) : 'User') + '</div>'
                + '</div>'
                + actionHtml
            + '</div>';
        }).join('');
    }

    /* ── GROUPS — no blue dot, NSFW badge, mute/notif icons ── */
    /* ── GROUPS ── */
    var groups = data.filter(function(d) { return d.type === 'group'; });
    if (groups.length) {
        var html = '<div class="sm-sec">Communities</div>';
        html += groups.map(function(g) {
            var pic = g.group_pic ? (g.group_pic.indexOf('uploads/') === 0 ? g.group_pic : 'uploads/' + g.group_pic) : 'uploads/default_group.png';
            var enc = encodeURIComponent(JSON.stringify(g));
            var isJoined = !!parseInt(g.is_joined);

            var nsfwHtml = smNSFWbadge(g.is_adult);
            var subContent = smEsc(String(g.member_count || 0)) + ' members';
            var statusHtml = smGroupStatusIcons(g.is_muted, g.is_notif_off || g.notif_off);

            return '<div class="sm-res-row" onclick="smSelectResult(\'group\',' + g.id + ',\'' + enc + '\')">'
                + '<img class="sm-res-avatar grp" src="' + smEsc(pic) + '" onerror="this.src=\'uploads/default_group.png\'">'
                + '<div class="sm-res-info">'
                    + '<div class="sm-res-name">' + smHL(g.title, q) + (nsfwHtml ? ' ' + nsfwHtml : '') + '</div>'
                    + '<div class="sm-res-sub">' + subContent + (statusHtml ? ' ' + statusHtml : '') + '</div>'
                + '</div>'
                + '<button class="sm-group-btn ' + (isJoined ? 'joined' : '') + '" onclick="event.stopPropagation();smToggleGroup(this,' + g.id + ')">'
                    + (isJoined ? 'Leave' : 'Join')
                + '</button>'
            + '</div>';
        }).join('');
        box.innerHTML += html;  // append to previous sections
    }

    /* ── POSTS / MEMES ── */
    if (memes.length) {
        html += '<div class="sm-sec">Posts</div>';
        html += memes.map(function(m) {
            var capShort = (m.caption || '').length > 55 ? m.caption.substring(0, 55) + '…' : (m.caption || '');
            var enc      = encodeURIComponent(JSON.stringify(m));
            var thumbHtml;
            if (m.meme_type === 'video') {
                thumbHtml = '<div class="sm-res-video-wrap">'
                    + '<video src="uploads/' + smEsc(m.file_name) + '" muted playsinline preload="metadata" onerror="this.style.display=\'none\'"></video>'
                    + '<i class="ri-play-fill sm-play-overlay"></i></div>';
            } else {
                var thumb = m.meme_type === 'image' ? 'uploads/' + m.file_name : 'uploads/Text.jpg';
                thumbHtml = '<img class="sm-res-avatar meme" src="' + smEsc(thumb) + '" onerror="this.src=\'uploads/mee.jpeg\'">';
            }
            return '<div class="sm-res-row" onclick="smSelectResult(\'meme\',' + m.id + ',\'' + enc + '\')">'
                + thumbHtml
                + '<div class="sm-res-info">'
                    + '<div class="sm-res-name meme-caption">' + smHL(capShort, q) + '</div>'
                    + '<div class="sm-res-sub">by ' + smEsc(m.username) + '</div>'
                + '</div>'
                + '<i class="ri-image-line sm-res-icon"></i>'
            + '</div>';
        }).join('');
    }

    /* ── COLLECTIONS ── */
    if (cols.length) {
        html += '<div class="sm-sec">Collections</div>';
        html += cols.map(function(c) {
            var enc     = encodeURIComponent(JSON.stringify(c));
            var itemCnt = (c.item_count || 0) + ' item' + ((c.item_count || 0) === 1 ? '' : 's');
            return '<div class="sm-res-row" onclick="smSelectResult(\'collection\',' + c.id + ',\'' + enc + '\')">'
                + '<div class="sm-res-icon-box col"><i class="ri-folder-3-line"></i></div>'
                + '<div class="sm-res-info">'
                    + '<div class="sm-res-name">' + smHL(c.name, q) + '</div>'
                    + '<div class="sm-res-sub">by ' + smEsc(c.owner_username || '') + ' · ' + smEsc(itemCnt) + '</div>'
                + '</div>'
                + '<i class="ri-arrow-right-s-line sm-res-icon gold"></i>'
            + '</div>';
        }).join('');
    }

    /* ── SOUNDS ── */
    if (sounds.length) {
        html += '<div class="sm-sec">Sounds</div>';
        html += sounds.map(function(s) {
            var enc = encodeURIComponent(JSON.stringify(s));
            return '<div class="sm-res-row" onclick="smSelectResult(\'sound\',' + s.id + ',\'' + enc + '\')">'
                + '<div class="sm-res-icon-box sound"><i class="ri-music-2-line"></i></div>'
                + '<div class="sm-res-info">'
                    + '<div class="sm-res-name">' + smHL(s.title, q) + '</div>'
                    + '<div class="sm-res-sub">by ' + smEsc(s.username || '') + '</div>'
                + '</div>'
                + '<i class="ri-arrow-right-s-line sm-res-icon blue"></i>'
            + '</div>';
        }).join('');
    }

    /* ── SOUND FOLDERS ── */
    if (scols.length) {
        html += '<div class="sm-sec">Sound Folders</div>';
        html += scols.map(function(sf) {
            var enc   = encodeURIComponent(JSON.stringify(sf));
            var itCnt = (sf.item_count || 0) + ' track' + ((sf.item_count || 0) === 1 ? '' : 's');
            return '<div class="sm-res-row" onclick="smSelectResult(\'sound_collection\',' + sf.id + ',\'' + enc + '\')">'
                + '<div class="sm-res-icon-box scol"><i class="ri-folder-music-line"></i></div>'
                + '<div class="sm-res-info">'
                    + '<div class="sm-res-name">' + smHL(sf.name, q) + '</div>'
                    + '<div class="sm-res-sub">by ' + smEsc(sf.owner_username || '') + ' · ' + smEsc(itCnt) + '</div>'
                + '</div>'
                + '<i class="ri-arrow-right-s-line sm-res-icon purple"></i>'
            + '</div>';
        }).join('');
    }

    /* ── MEMEPEDIA ── */
    if (wikis.length) {
        html += '<div class="sm-sec">Memepedia</div>';
        html += wikis.map(function(w) {
            var enc         = encodeURIComponent(JSON.stringify(w));
            var statusLabel = w.status ? w.status.charAt(0).toUpperCase() + w.status.slice(1) : '';
            return '<div class="sm-res-row" onclick="smSelectResult(\'memepedia\',' + w.id + ',\'' + enc + '\')">'
                + '<div class="sm-res-icon-box wiki"><i class="ri-book-open-line"></i></div>'
                + '<div class="sm-res-info">'
                    + '<div class="sm-res-name">' + smHL(w.title, q) + '</div>'
                    + '<div class="sm-res-sub">Memepedia' + (statusLabel ? ' · ' + smEsc(statusLabel) : '') + (w.category ? ' · ' + smEsc(w.category) : '') + '</div>'
                + '</div>'
                + '<i class="ri-arrow-right-s-line sm-res-icon green"></i>'
            + '</div>';
        }).join('');
    }

    /* ── FULL SEARCH ROW ── */
    html += '<div class="sm-res-row sm-full-search-row" onclick="smFullSearch(\'' + encodeURIComponent(q) + '\')">'
        + '<div class="sm-full-search-icon"><i class="ri-search-line"></i></div>'
        + '<div class="sm-res-info">'
            + '<div class="sm-res-name sm-full-search-label">Search for "' + smEsc(q) + '"</div>'
            + '<div class="sm-res-sub">See all matching posts, users &amp; communities</div>'
        + '</div>'
        + '<i class="ri-arrow-right-line sm-res-icon red"></i>'
    + '</div>';

    box.innerHTML = html;
}

function smFullSearch(encQ) {
    var q = decodeURIComponent(encQ);
    smAddRecent({ type: 'query', id: btoa(encodeURIComponent(q)).slice(0, 12), query: q });
    closeSearchModal();
    openFullResultsModal(q);
}

/* ── smSelectResult ── */
function smSelectResult(type, id, enc) {
    var item = {};
    try { item = JSON.parse(decodeURIComponent(enc)); } catch(e) {}
    smAddRecent(item);
    smNavigate(type, id, item);
}

/* ── Focus border ── */
document.getElementById('modalSearchInput').addEventListener('focus', function() {
    document.getElementById('modalSearchWrap').classList.add('focused');
});
document.getElementById('modalSearchInput').addEventListener('blur', function() {
    document.getElementById('modalSearchWrap').classList.remove('focused');
});

/* ── Auto-open if ?focus=1 ── */
(function() {
    if (new URLSearchParams(window.location.search).get('focus') === '1') {
        setTimeout(openSearchModal, 120);
    }
})();
</script>

<?php
    // ── USER TOP POSTS (for FR modal user cards) ──
    if (isset($_GET['type']) && $_GET['type'] === 'user_posts') {
        header('Content-Type: application/json');
        $uid   = (int)($_GET['user_id'] ?? 0);
        $limit = min(8, max(1, (int)($_GET['limit'] ?? 4)));
        $out = [];
        if ($uid) {
            $stmt = $conn->prepare("
                SELECT id, file_name, type, caption
                FROM memes
                WHERE uploaded_by = ? AND (is_adult = 0 OR ? = ?)
                ORDER BY created_at DESC
                LIMIT $limit
            ");
            $stmt->bind_param("iii", $uid, $current_user, $uid);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) $out[] = $row;
            $stmt->close();
        }
        echo json_encode($out);
        exit;
    }

    // ── GROUP TOP POSTS (for FR modal group cards) ──
    if (isset($_GET['type']) && $_GET['type'] === 'group_posts') {
        header('Content-Type: application/json');
        $gid   = (int)($_GET['group_id'] ?? 0);
        $limit = min(8, max(1, (int)($_GET['limit'] ?? 4)));
        $out = [];
        if ($gid) {
            $stmt = $conn->prepare("
                SELECT m.id, m.file_name, m.type, m.caption
                FROM group_posts gp
                JOIN memes m ON m.id = gp.meme_id
                WHERE gp.group_id = ?
                ORDER BY gp.created_at DESC
                LIMIT $limit
            ");
            $stmt->bind_param("i", $gid);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) $out[] = $row;
            $stmt->close();
        }
        echo json_encode($out);
        exit;
    }
?>

<div id="frModal">
    <div class="fr-topbar">
        <button class="fr-back" onclick="closeFullResultsModal()"><i class="ri-arrow-left-s-line"></i></button>
        <div class="fr-title" id="frTitle">Search</div>
        <button class="fr-more" onclick="frRedoSearch()"><i class="ri-search-line"></i></button>
    </div>

    <div class="fr-tabs-wrap">
        <div class="fr-tabs" id="frTabs"></div>
    </div>

    <div class="fr-content" id="frContent">
        <div class="fr-loading"><i class="ri-loader-4-line fr-spin"></i></div>
    </div>
</div>


<!-- ═══════════════════ STYLE ═══════════════════ -->
<style>
#frModal{
    display:none; position:fixed; inset:0; z-index:2147483000;
    background:#040617; flex-direction:column; overflow:hidden;
}
#frModal.fr-open{ display:flex; animation:frFadeIn .22s ease forwards; }
@keyframes frFadeIn{ from{opacity:0; transform:translateY(10px);} to{opacity:1; transform:translateY(0);} }

.fr-topbar{
    display:flex; align-items:center; gap:10px;
    padding:16px 18px; padding-top:calc(env(safe-area-inset-top) + 16px);
    border-bottom:1px solid rgba(255,255,255,0.07); flex-shrink:0;
    background:#040617;
}
.fr-back,.fr-more{
    background:transparent; border:none; color:#fff; cursor:pointer;
    width:34px; height:34px; display:flex; align-items:center; justify-content:center;
    border-radius:50%; flex-shrink:0; font-size:22px; -webkit-tap-highlight-color:transparent;
    transition:background .2s;
}
.fr-back:hover,.fr-more:hover{ background:rgba(255,255,255,0.08); }
.fr-title{
    flex:1; font-family:'Poppins',sans-serif; font-weight:600; font-size:17px;
    color:#fff; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}

/* ── TABS ── */
.fr-tabs-wrap{ border-bottom:1px solid rgba(255,255,255,0.07); flex-shrink:0; background:#040617; }
.fr-tabs{
    display:flex; gap:8px; padding:10px 16px; overflow-x:auto; white-space:nowrap;
    scrollbar-width:none; -webkit-overflow-scrolling:touch;
}
.fr-tabs::-webkit-scrollbar{ display:none; }
.fr-tab{
    flex-shrink:0; padding:8px 16px; border-radius:999px; cursor:pointer;
    font-family:'Poppins',sans-serif; font-size:13px; font-weight:600;
    color:rgba(255,255,255,0.5); background:transparent; border:1px solid transparent;
    transition:all .2s ease; -webkit-tap-highlight-color:transparent;
}
.fr-tab:hover{ color:rgba(255,255,255,0.85); }
.fr-tab.active{ background:#fff; color:#040617; }

/* ── CONTENT ── */
.fr-content{ flex:1; overflow-y:auto; -webkit-overflow-scrolling:touch; scrollbar-width:thin; }
.fr-content::-webkit-scrollbar{ width:4px; }
.fr-content::-webkit-scrollbar-thumb{ background:rgba(80,95,140,0.35); border-radius:999px; }

.fr-loading, .fr-empty{
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    padding:70px 20px; gap:12px; color:rgba(255,255,255,0.3); font-family:'Poppins',sans-serif;
}
.fr-empty i{ font-size:44px; }
.fr-empty p{ font-size:14px; font-weight:500; }
.fr-spin{ font-size:26px; animation:frSpin .7s linear infinite; display:inline-block; }
@keyframes frSpin{ to{ transform:rotate(360deg); } }

.fr-sec-title{
    font-size:11px; font-weight:700; letter-spacing:1px; text-transform:uppercase;
    color:rgba(255,255,255,0.35); padding:18px 20px 10px; font-family:'Poppins',sans-serif;
}

/* ══════════════════════════════════════════════════
   RICH USER CARD
══════════════════════════════════════════════════ */
.fru-card{
    margin:14px 16px; border-radius:22px; overflow:hidden;
    background:rgba(255,255,255,0.045); border:1px solid rgba(255,255,255,0.08);
}
.fru-header{
    display:flex; align-items:center; gap:13px; padding:16px;
}
.fru-avatar-btn{ flex-shrink:0; cursor:pointer; -webkit-tap-highlight-color:transparent; }
.fru-avatar{
    width:58px; height:58px; border-radius:50%; object-fit:cover;
    border:2.5px solid #FF2D2D; display:block; transition:transform .18s;
}
.fru-avatar-btn:hover .fru-avatar{ transform:scale(1.05); }
.fru-headtext{ flex:1; min-width:0; cursor:pointer; -webkit-tap-highlight-color:transparent; }
.fru-username{
    font-family:'Poppins',sans-serif; font-weight:700; font-size:15.5px; color:#fff;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    display:flex; align-items:center; gap:6px;
}
.fru-nickname{
    font-size:12.5px; color:rgba(255,255,255,0.42); margin-top:2px;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    font-family:'Poppins',sans-serif;
}
.fru-track-btn{
    flex-shrink:0; margin-left:auto; padding:8px 18px; border-radius:22px; border:none; cursor:pointer;
    font-size:12.5px; font-weight:700; font-family:'Poppins',sans-serif; color:#fff;
    background:#FF2D2D; transition:.2s; min-width:78px; text-align:center;
    box-shadow:0 4px 14px rgba(255,45,45,0.28);
}
.fru-track-btn:hover{ background:#ff1a1a; transform:translateY(-1px); }
.fru-track-btn.following{ background:transparent; border:1.5px solid rgba(255,45,45,0.55); color:#ff6b6b; box-shadow:none; }
.fru-track-btn.following:hover{ background:rgba(255,45,45,0.1); }
.fru-track-btn.blocked{ background:transparent; border:1.5px solid rgba(255,255,255,0.15); color:rgba(255,255,255,0.35); cursor:default; box-shadow:none; }
.fru-track-btn.requested{ background:transparent; border:1.5px solid rgba(255,255,255,0.2); color:rgba(255,255,255,0.5); }
.fru-track-btn:disabled{ opacity:.6; pointer-events:none; }

.fru-posts-grid{
    display:grid; grid-template-columns:repeat(4,1fr); gap:3px;
    padding:0 3px 3px;
}
.fru-post-tile{
    position:relative; aspect-ratio:1/1; overflow:hidden; cursor:pointer; background:rgba(255,255,255,0.03);
}
.fru-post-tile img, .fru-post-tile video{ width:100%; height:100%; object-fit:cover; display:block; }
.fru-post-tile .fru-play-icon{
    position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
    color:rgba(255,255,255,0.85); font-size:20px; pointer-events:none;
}
.fru-post-skel{ width:100%; height:100%; background:linear-gradient(90deg,rgba(255,255,255,0.03) 25%,rgba(255,255,255,0.07) 50%,rgba(255,255,255,0.03) 75%); background-size:400% 100%; animation:fruShimmer 1.4s infinite; }
@keyframes fruShimmer{ 0%{background-position:200% 0;} 100%{background-position:-200% 0;} }
.fru-post-empty{ display:flex; align-items:center; justify-content:center; height:100%; color:rgba(255,255,255,0.14); font-size:16px; }
.fru-seemore-tile{
    display:flex; flex-direction:column; align-items:center; justify-content:center; gap:4px;
    background:rgba(255,45,45,0.08); color:#ff6b6b; font-size:10.5px; font-weight:700;
    font-family:'Poppins',sans-serif; text-align:center; gap:5px;
}
.fru-seemore-tile i{ font-size:17px; }

.fru-bio{
    padding:12px 16px 16px; font-size:12.5px; line-height:1.55; color:rgba(230,232,255,0.75);
    font-family:'Poppins',sans-serif; border-top:1px solid rgba(255,255,255,0.05); margin-top:2px;
}

/* ══════════════════════════════════════════════════
   RICH GROUP CARD
══════════════════════════════════════════════════ */
.frg-card{
    margin:14px 16px; border-radius:22px; overflow:hidden;
    background:rgba(255,255,255,0.045); border:1px solid rgba(6,115,240,0.12);
}
.frg-header{ display:flex; align-items:flex-start; gap:13px; padding:16px; }
.frg-pic-btn{ flex-shrink:0; cursor:pointer; -webkit-tap-highlight-color:transparent; }
.frg-pic{
    width:58px; height:58px; border-radius:16px; object-fit:cover;
    border:2px solid #0673f0; display:block; transition:transform .18s;
}
.frg-pic-btn:hover .frg-pic{ transform:scale(1.05); }
.frg-headtext{ flex:1; min-width:0; cursor:pointer; -webkit-tap-highlight-color:transparent; padding-top:1px; }
.frg-title{
    font-family:'Poppins',sans-serif; font-weight:700; font-size:15.5px; color:#fff;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    display:flex; align-items:center; gap:6px;
}
.frg-meta{
    display:flex; align-items:center; gap:6px; margin-top:4px; font-size:12px;
    color:rgba(255,255,255,0.42); font-family:'Poppins',sans-serif;
    white-space:nowrap; overflow:hidden;
}
.frg-meta .frg-owner{ color:rgba(255,255,255,0.6); font-weight:600; overflow:hidden; text-overflow:ellipsis; }
.frg-meta .frg-dot{ flex-shrink:0; opacity:.5; }
.frg-meta .frg-members{ flex-shrink:0; }
.frg-nsfw{
    display:inline-flex; align-items:center; gap:2px; background:rgba(255,45,45,0.15);
    border:1px solid rgba(255,45,45,0.45); border-radius:6px; padding:1px 5px;
    font-size:9px; font-weight:800; color:#FF2D2D; letter-spacing:.5px; text-transform:uppercase;
}
.frg-join-btn{
    flex-shrink:0; margin-left:auto; padding:8px 18px; border-radius:22px; border:none; cursor:pointer;
    font-size:12.5px; font-weight:700; font-family:'Poppins',sans-serif; color:#fff;
    background:#0673f0; transition:.2s; min-width:78px; text-align:center;
    box-shadow:0 4px 14px rgba(6,115,240,0.3);
}
.frg-join-btn:hover{ background:#0560cc; transform:translateY(-1px); }
.frg-join-btn.joined{ background:transparent; border:1.5px solid rgba(6,115,240,0.55); color:#4aa3ff; box-shadow:none; }
.frg-join-btn.joined:hover{ background:rgba(6,115,240,0.1); }

.frg-posts-grid{ display:grid; grid-template-columns:repeat(4,1fr); gap:3px; padding:0 3px 3px; }
.frg-post-tile{ position:relative; aspect-ratio:1/1; overflow:hidden; cursor:pointer; background:rgba(255,255,255,0.03); }
.frg-post-tile img, .frg-post-tile video{ width:100%; height:100%; object-fit:cover; display:block; }
.frg-post-tile .frg-play-icon{ position:absolute; inset:0; display:flex; align-items:center; justify-content:center; color:rgba(255,255,255,0.85); font-size:20px; pointer-events:none; }
.frg-post-skel{ width:100%; height:100%; background:linear-gradient(90deg,rgba(255,255,255,0.03) 25%,rgba(255,255,255,0.07) 50%,rgba(255,255,255,0.03) 75%); background-size:400% 100%; animation:fruShimmer 1.4s infinite; }
.frg-post-empty{ display:flex; align-items:center; justify-content:center; height:100%; color:rgba(255,255,255,0.14); font-size:16px; }
.frg-seemore-tile{
    display:flex; flex-direction:column; align-items:center; justify-content:center; gap:5px;
    background:rgba(6,115,240,0.1); color:#4aa3ff; font-size:10.5px; font-weight:700;
    font-family:'Poppins',sans-serif; text-align:center;
}
.frg-seemore-tile i{ font-size:17px; }

/* ══════════════════════════════════════════════════
   RICH MEMEPEDIA CARD
══════════════════════════════════════════════════ */
.frw-card{
    display:flex; gap:13px; margin:12px 16px; padding:14px; border-radius:20px; cursor:pointer;
    background:rgba(255,255,255,0.04); border:1px solid rgba(80,200,140,0.14); transition:background .18s;
}
.frw-card:hover{ background:rgba(255,255,255,0.07); }
.frw-media{
    flex-shrink:0; width:78px; height:78px; border-radius:14px; overflow:hidden;
    background:rgba(80,200,140,0.08); display:flex; align-items:center; justify-content:center;
}
.frw-media img{ width:100%; height:100%; object-fit:cover; display:block; }
.frw-media i{ font-size:26px; color:#50c88c; }
.frw-body{ flex:1; min-width:0; display:flex; flex-direction:column; gap:5px; }
.frw-title{
    font-family:'Poppins',sans-serif; font-weight:700; font-size:14.5px; color:#fff;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.frw-summary{
    font-size:12px; line-height:1.5; color:rgba(255,255,255,0.55);
    font-family:'Poppins',sans-serif;
    display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;
}
.frw-meta{
    display:flex; align-items:center; gap:6px; margin-top:auto; font-size:11px;
    color:rgba(255,255,255,0.38); font-family:'Poppins',sans-serif;
}
.frw-author-pic{ width:18px; height:18px; border-radius:50%; object-fit:cover; border:1px solid rgba(80,200,140,0.5); flex-shrink:0; }
.frw-meta .frw-dot{ opacity:.5; }
.frw-meta span{ white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:110px; }

/* ── FEATURED WRAP (For You top match uses the rich cards directly) ── */
.fr-featured-wrap .fru-card, .fr-featured-wrap .frg-card{ margin-top:14px; }

/* ── POST GRID (general "Posts" tile grid used in For You for loose meme matches) ── */
.fr-grid{ display:grid; grid-template-columns:repeat(3,1fr); gap:3px; padding:0 3px; }
.fr-grid-tile{ position:relative; aspect-ratio:1/1; overflow:hidden; cursor:pointer; background:#111; }
.fr-grid-tile img, .fr-grid-tile video{ width:100%; height:100%; object-fit:cover; display:block; }
.fr-grid-tile .fr-play-icon{ position:absolute; inset:0; display:flex; align-items:center; justify-content:center; color:rgba(255,255,255,0.85); font-size:26px; pointer-events:none; }

/* ── HORIZONTAL RELATED ROWS (collections / sounds / sound folders) ── */
.fr-hscroll{ display:flex; gap:12px; overflow-x:auto; padding:2px 20px 18px; scrollbar-width:none; -webkit-overflow-scrolling:touch; }
.fr-hscroll::-webkit-scrollbar{ display:none; }
.fr-hcard{ flex:0 0 auto; width:130px; border-radius:14px; overflow:hidden; cursor:pointer; background:rgba(255,255,255,0.045); border:1px solid rgba(255,255,255,0.07); }
.fr-hcard .fr-hcard-thumb{ width:100%; height:90px; object-fit:cover; display:block; background:#111; }
.fr-hcard .fr-hcard-icon{ width:100%; height:90px; display:flex; align-items:center; justify-content:center; font-size:26px; }
.fr-hcard-body{ padding:9px 10px; }
.fr-hcard-name{ font-size:12px; font-weight:600; color:#fff; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; font-family:'Poppins',sans-serif; }
.fr-hcard-sub{ font-size:10.5px; color:rgba(255,255,255,0.4); margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

/* ── LIST ROWS (fallback simple rows — kept for consistency where needed) ── */
.fr-row{ display:flex; align-items:center; gap:12px; padding:11px 18px; cursor:pointer; transition:background .15s; }
.fr-row:hover{ background:rgba(255,255,255,0.04); }
.fr-row-icon{ width:48px; height:48px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0; }
.fr-row-info{ flex:1; min-width:0; }
.fr-row-name{ font-size:14px; font-weight:600; color:#fff; display:flex; align-items:center; gap:6px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; font-family:'Poppins',sans-serif; }
.fr-row-sub{ font-size:11.5px; color:rgba(255,255,255,0.42); margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

/* ══════════════════════════════════════════════════
   RESPONSIVE — 790 / 490 / 390 / 330px
   (top-level premium tightening at every step)
══════════════════════════════════════════════════ */
@media (max-width:790px){
    .fr-topbar{ padding:13px 15px; padding-top:calc(env(safe-area-inset-top) + 13px); gap:9px; }
    .fr-back,.fr-more{ width:31px; height:31px; font-size:20px; }
    .fr-title{ font-size:15px; }
    .fr-tab{ padding:7px 14px; font-size:12.5px; }
    .fr-sec-title{ padding:16px 16px 8px; font-size:10.5px; }

    .fru-card, .frg-card{ margin:12px 13px; border-radius:19px; }
    .fru-header, .frg-header{ padding:14px; gap:11px; }
    .fru-avatar, .frg-pic{ width:52px; height:52px; }
    .fru-username, .frg-title{ font-size:14.5px; }
    .fru-nickname, .frg-meta{ font-size:11.5px; }
    .fru-track-btn, .frg-join-btn{ padding:7px 15px; font-size:12px; min-width:72px; }
    .fru-bio{ padding:11px 14px 14px; font-size:12px; }

    .frw-card{ margin:11px 13px; padding:12px; gap:11px; border-radius:17px; }
    .frw-media{ width:70px; height:70px; border-radius:12px; }
    .frw-title{ font-size:13.5px; }
    .frw-summary{ font-size:11.5px; }
    .frw-meta{ font-size:10.5px; }

    .fr-hcard{ width:118px; }
    .fr-hcard .fr-hcard-thumb, .fr-hcard .fr-hcard-icon{ height:82px; }
}

@media (max-width:490px){
    .fr-topbar{ padding:11px 13px; padding-top:calc(env(safe-area-inset-top) + 11px); gap:8px; }
    .fr-back,.fr-more{ width:29px; height:29px; font-size:19px; }
    .fr-title{ font-size:14px; }
    .fr-tabs{ padding:9px 13px; gap:6px; }
    .fr-tab{ padding:6px 12px; font-size:11.5px; }
    .fr-sec-title{ padding:14px 13px 7px; font-size:10px; }

    .fru-card, .frg-card{ margin:10px 10px; border-radius:17px; }
    .fru-header, .frg-header{ padding:12px; gap:10px; }
    .fru-avatar, .frg-pic{ width:46px; height:46px; border-width:2px; }
    .frg-pic{ border-radius:13px; }
    .fru-username, .frg-title{ font-size:13.5px; gap:5px; }
    .fru-nickname{ font-size:11px; }
    .frg-meta{ font-size:10.5px; gap:5px; }
    .fru-track-btn, .frg-join-btn{ padding:6px 13px; font-size:11px; min-width:64px; border-radius:18px; }
    .fru-bio{ padding:10px 12px 12px; font-size:11px; }
    .fru-post-tile, .frg-post-tile{ }
    .fru-seemore-tile i, .frg-seemore-tile i{ font-size:15px; }
    .fru-seemore-tile, .frg-seemore-tile{ font-size:9.5px; }

    .frw-card{ margin:9px 10px; padding:10px; gap:10px; border-radius:15px; }
    .frw-media{ width:60px; height:60px; border-radius:11px; }
    .frw-media i{ font-size:22px; }
    .frw-title{ font-size:12.5px; }
    .frw-summary{ font-size:11px; }
    .frw-meta{ font-size:10px; }
    .frw-author-pic{ width:16px; height:16px; }

    .fr-hcard{ width:104px; }
    .fr-hcard .fr-hcard-thumb, .fr-hcard .fr-hcard-icon{ height:72px; }
    .fr-hcard-name{ font-size:11px; }
    .fr-hcard-sub{ font-size:9.5px; }
}

@media (max-width:390px){
    .fr-topbar{ padding:10px 11px; padding-top:calc(env(safe-area-inset-top) + 10px); gap:7px; }
    .fr-back,.fr-more{ width:27px; height:27px; font-size:18px; }
    .fr-title{ font-size:13px; }
    .fr-tabs{ padding:8px 11px; gap:5px; }
    .fr-tab{ padding:6px 10px; font-size:11px; }
    .fr-sec-title{ padding:13px 11px 6px; font-size:9.5px; letter-spacing:.8px; }

    .fru-card, .frg-card{ margin:9px 8px; border-radius:16px; }
    .fru-header, .frg-header{ padding:11px; gap:9px; }
    .fru-avatar, .frg-pic{ width:42px; height:42px; }
    .fru-username, .frg-title{ font-size:12.5px; }
    .fru-nickname{ font-size:10.5px; }
    .frg-meta{ font-size:10px; }
    .fru-track-btn, .frg-join-btn{ padding:6px 11px; font-size:10.5px; min-width:58px; border-radius:16px; box-shadow:none; }
    .fru-bio{ padding:9px 11px 11px; font-size:10.5px; line-height:1.5; }
    .fru-post-skel, .frg-post-skel{ }

    .frw-card{ margin:8px 8px; padding:9px; gap:9px; border-radius:14px; }
    .frw-media{ width:54px; height:54px; border-radius:10px; }
    .frw-media i{ font-size:20px; }
    .frw-title{ font-size:12px; }
    .frw-summary{ font-size:10.5px; -webkit-line-clamp:2; }
    .frw-meta{ font-size:9.5px; }
    .frw-meta span{ max-width:80px; }

    .fr-hcard{ width:94px; }
    .fr-hcard .fr-hcard-thumb, .fr-hcard .fr-hcard-icon{ height:64px; }
    .fr-hcard-body{ padding:7px 8px; }
    .fr-hcard-name{ font-size:10.5px; }
    .fr-hcard-sub{ font-size:9px; }
}

@media (max-width:330px){
    .fr-topbar{ padding:9px 9px; padding-top:calc(env(safe-area-inset-top) + 9px); gap:6px; }
    .fr-back,.fr-more{ width:25px; height:25px; font-size:16px; }
    .fr-title{ font-size:12px; }
    .fr-tabs{ padding:7px 9px; gap:4px; }
    .fr-tab{ padding:5px 9px; font-size:10px; }
    .fr-sec-title{ padding:11px 9px 5px; font-size:9px; letter-spacing:.6px; }

    .fru-card, .frg-card{ margin:7px 7px; border-radius:14px; }
    .fru-header, .frg-header{ padding:9px; gap:8px; }
    .fru-avatar, .frg-pic{ width:38px; height:38px; border-width:1.5px; }
    .fru-username, .frg-title{ font-size:11.5px; gap:4px; }
    .fru-nickname{ font-size:9.5px; }
    .frg-meta{ font-size:9px; gap:4px; }
    .fru-track-btn, .frg-join-btn{ padding:5px 10px; font-size:9.5px; min-width:52px; border-radius:14px; }
    .fru-bio{ padding:8px 9px 9px; font-size:9.5px; }
    .fru-seemore-tile, .frg-seemore-tile{ font-size:8.5px; gap:3px; }
    .fru-seemore-tile i, .frg-seemore-tile i{ font-size:13px; }

    .frw-card{ margin:6px 7px; padding:8px; gap:8px; border-radius:12px; }
    .frw-media{ width:46px; height:46px; border-radius:9px; }
    .frw-media i{ font-size:17px; }
    .frw-title{ font-size:11px; }
    .frw-summary{ font-size:9.5px; -webkit-line-clamp:2; }
    .frw-meta{ font-size:9px; gap:4px; }
    .frw-author-pic{ width:14px; height:14px; }
    .frw-meta span{ max-width:64px; }

    .fr-hcard{ width:84px; }
    .fr-hcard .fr-hcard-thumb, .fr-hcard .fr-hcard-icon{ height:58px; }
    .fr-hcard-body{ padding:6px 7px; }
    .fr-hcard-name{ font-size:10px; }
    .fr-hcard-sub{ font-size:8.5px; }
}
</style>


<!-- ═══════════════════ SCRIPT ═══════════════════ -->
<script>
/* ══════════════════════════════════════════════
   DEZZME — FULL SEARCH RESULTS MODAL ENGINE (v2)
══════════════════════════════════════════════ */
var FR = {
    query: '',
    data: { users: [], groups: [], memes: [], collections: [], sounds: [], sound_collections: [], memepedia: [], profile_id: [] },
    activeTab: 'foryou'
};

var FR_TAB_META = {
    foryou:            { label: 'For You' },
    profile_id:        { label: 'Profile ID' },
    users:             { label: 'Users' },
    groups:            { label: 'Groups' },
    memepedia:         { label: 'Memepedia' },
    collections:       { label: 'Collections' },
    sounds:            { label: 'Sounds' },
    sound_collections: { label: 'Sound Folders' }
};

function frEsc(s){
    var d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
}
function frUid(){ return 'x' + Math.random().toString(36).slice(2, 10); }

/* ── OPEN / CLOSE ── */
function openFullResultsModal(query){
    FR.query = query;
    FR.activeTab = 'foryou';
    document.getElementById('frTitle').textContent = query;
    document.getElementById('frModal').classList.add('fr-open');
    document.body.style.overflow = 'hidden';
    document.getElementById('frContent').innerHTML = '<div class="fr-loading"><i class="ri-loader-4-line fr-spin"></i></div>';
    document.getElementById('frTabs').innerHTML = '';

    fetch('search.php?type=autocomplete&q=' + encodeURIComponent(query))
        .then(function(r){ return r.json(); })
        .then(function(list){ frOnData(Array.isArray(list) ? list : []); })
        .catch(function(){ frOnData([]); });
}

function closeFullResultsModal(){
    document.getElementById('frModal').classList.remove('fr-open');
    document.body.style.overflow = '';
}

function frRedoSearch(){
    closeFullResultsModal();
    setTimeout(function(){
        openSearchModal();
        var inp = document.getElementById('modalSearchInput');
        if (inp) { inp.value = FR.query; inp.dispatchEvent(new Event('input')); }
    }, 150);
}

/* ── SPLIT RESULTS INTO BUCKETS ── */
function frOnData(list){
    FR.data = { users: [], groups: [], memes: [], collections: [], sounds: [], sound_collections: [], memepedia: [], profile_id: [] };
    list.forEach(function(item){
        if      (item.type === 'user')             FR.data.users.push(item);
        else if (item.type === 'profile_id')       FR.data.profile_id.push(item);
        else if (item.type === 'group')            FR.data.groups.push(item);
        else if (item.type === 'meme')             FR.data.memes.push(item);
        else if (item.type === 'collection')       FR.data.collections.push(item);
        else if (item.type === 'sound')            FR.data.sounds.push(item);
        else if (item.type === 'sound_collection') FR.data.sound_collections.push(item);
        else if (item.type === 'memepedia')        FR.data.memepedia.push(item);
    });
    frBuildTabs();
    frRenderTab('foryou');
}

/* ── BUILD TAB BAR (only show tabs that have results) ── */
function frBuildTabs(){
    var wrap = document.getElementById('frTabs');
    var order = ['foryou','profile_id','users','groups','memepedia','collections','sounds','sound_collections'];
    var html = '';
    order.forEach(function(key){
        if (key !== 'foryou' && (!FR.data[key] || !FR.data[key].length)) return;
        var active = key === FR.activeTab ? ' active' : '';
        html += '<div class="fr-tab' + active + '" data-tab="' + key + '" onclick="frRenderTab(\'' + key + '\')">' + FR_TAB_META[key].label + '</div>';
    });
    wrap.innerHTML = html;
}

function frSetActiveTabUI(tab){
    document.querySelectorAll('.fr-tab').forEach(function(t){
        t.classList.toggle('active', t.dataset.tab === tab);
    });
}

/* ── RENDER A TAB ── */
function frRenderTab(tab){
    FR.activeTab = tab;
    frSetActiveTabUI(tab);
    var box = document.getElementById('frContent');

    if (tab === 'foryou')            { box.innerHTML = frRenderForYou();               frMountForYou();        return; }
    if (tab === 'profile_id')        { box.innerHTML = frRenderProfileIdList();        return; }
    if (tab === 'users')             { box.innerHTML = frRenderUserListRich(FR.data.users); frMountUsers(FR.data.users); return; }
    if (tab === 'groups')            { box.innerHTML = frRenderGroupListRich(FR.data.groups); frMountGroups(FR.data.groups); return; }
    if (tab === 'memepedia')         { box.innerHTML = frRenderMemepediaListRich();     return; }
    if (tab === 'collections')       { box.innerHTML = frRenderCollectionList();       return; }
    if (tab === 'sounds')            { box.innerHTML = frRenderSoundList();            return; }
    if (tab === 'sound_collections') { box.innerHTML = frRenderSoundFolderList();      return; }
}

function frEmptyMsg(label){
    return '<div class="fr-empty"><i class="ri-inbox-line"></i><p>No ' + label + ' found</p></div>';
}

/* ══════════════════════════════════════════════
   RICH USER CARD BUILDER
══════════════════════════════════════════════ */
function frUserTrackBtnHtml(u, uid){
    if (parseInt(u.is_blocked)) {
        return '<span class="fru-track-btn blocked">Blocked</span>';
    }
    if (parseInt(u.is_private) && !parseInt(u.is_following) && parseInt(u.is_track_approved) !== 1) {
        var reqState = u.track_request_status === 'pending';
        return '<button class="fru-track-btn ' + (reqState ? 'requested' : '') + '" data-uid="' + u.id + '" onclick="event.stopPropagation();frTrackClick(this,' + u.id + ',true)">'
            + (reqState ? 'Requested' : 'Request') + '</button>';
    }
    var isTracking = !!parseInt(u.is_following);
    return '<button class="fru-track-btn ' + (isTracking ? 'following' : '') + '" data-uid="' + u.id + '" onclick="event.stopPropagation();frTrackClick(this,' + u.id + ',false)">'
        + (isTracking ? 'Untrack' : 'Track') + '</button>';
}

function frBuildUserRichCard(u){
    var gridId = frUid();
    var pic = u.profile_pic || 'uploads/mee.jpeg';
    var nick = u.nickname ? '@' + frEsc(u.nickname) : (u.dz_id ? frEsc(u.dz_id) : 'Dezzmer');

    var html = '<div class="fru-card" data-uid="' + u.id + '">'
        + '<div class="fru-header">'
            + '<div class="fru-avatar-btn" onclick="location.href=\'profile.php?user_id=' + u.id + '\'">'
                + '<img class="fru-avatar" src="' + frEsc(pic) + '" onerror="this.src=\'uploads/mee.jpeg\'">'
            + '</div>'
            + '<div class="fru-headtext" onclick="location.href=\'profile.php?user_id=' + u.id + '\'">'
                + '<div class="fru-username">' + frEsc(u.username) + '</div>'
                + '<div class="fru-nickname">' + nick + '</div>'
            + '</div>'
            + frUserTrackBtnHtml(u, u.id)
        + '</div>'
        + '<div class="fru-posts-grid" id="' + gridId + '">'
            + Array(3).fill('<div class="fru-post-tile"><div class="fru-post-skel"></div></div>').join('')
            + '<div class="fru-post-tile fru-seemore-tile" onclick="location.href=\'profile.php?user_id=' + u.id + '\'"><i class="ri-arrow-right-s-line"></i>See more</div>'
        + '</div>'
        + '<div class="fru-bio" id="bio-' + gridId + '" style="display:none;"></div>'
    + '</div>';

    return { html: html, gridId: gridId, bioId: 'bio-' + gridId, bio: u.bio || '' };
}

function frRenderUserListRich(arr){
    if (!arr.length) return frEmptyMsg('users');
    var out = '';
    var refs = [];
    arr.forEach(function(u){
        var built = frBuildUserRichCard(u);
        out += built.html;
        refs.push({ id: u.id, gridId: built.gridId, bioId: built.bioId, bio: built.bio });
    });
    window._frPendingUserMounts = refs;
    return out;
}

function frMountUsers(arr){
    (window._frPendingUserMounts || []).forEach(function(ref){
        frLoadUserPosts(ref.id, ref.gridId);
        frShowBio(ref.bioId, ref.bio);
    });
    window._frPendingUserMounts = null;
}

function frShowBio(bioId, bio){
    var el = document.getElementById(bioId);
    if (!el) return;
    var text = (bio || '').trim();
    if (!text) { el.style.display = 'none'; return; }
    el.textContent = text;
    el.style.display = 'block';
}

function frLoadUserPosts(userId, gridId){
    fetch('search.php?type=user_posts&user_id=' + userId + '&limit=3')
        .then(function(r){ return r.json(); })
        .then(function(posts){ frFillPostGrid(gridId, Array.isArray(posts) ? posts : [], 'fru'); })
        .catch(function(){ frFillPostGrid(gridId, [], 'fru'); });
}

function frFillPostGrid(gridId, posts, prefix){
    var grid = document.getElementById(gridId);
    if (!grid) return;
    var seeMore = grid.querySelector('.' + prefix + '-seemore-tile');
    var tiles = Array.prototype.slice.call(grid.querySelectorAll('.' + prefix + '-post-tile'));

    for (var i = 0; i < tiles.length; i++) {
        var p = posts[i];
        if (!p) {
            tiles[i].innerHTML = '<div class="' + prefix + '-post-empty"><i class="ri-image-line"></i></div>';
            continue;
        }
        var isVideo = p.type === 'video';
        var thumb = (p.type === 'text' || !p.file_name || p.file_name === '0') ? 'uploads/Text.jpg' : 'uploads/' + p.file_name;
        var enc = encodeURIComponent(p.caption || '');
        if (isVideo) {
            tiles[i].innerHTML = '<video src="' + frEsc(thumb) + '" muted playsinline preload="metadata"></video><i class="ri-play-fill ' + prefix + '-play-icon"></i>';
        } else {
            tiles[i].innerHTML = '<img src="' + frEsc(thumb) + '" onerror="this.src=\'uploads/Text.jpg\'">';
        }
        tiles[i].onclick = (function(query){ return function(){ location.href = 'search.php?q=' + query; }; })(enc);
    }
    if (seeMore) grid.appendChild(seeMore); // keep see-more pinned last
}

/* ── TRACK BUTTON — self-contained AJAX, syncs every instance on screen ── */
function frTrackClick(btn, userId, isPrivateRequest){
    if (btn.disabled) return;
    btn.disabled = true;
    var wasFollowing = btn.classList.contains('following');
    var wasRequested = btn.classList.contains('requested');

    if (isPrivateRequest) {
        if (wasRequested) { btn.disabled = false; return; } // already requested, no toggle
        btn.textContent = '…';
        fetch('profile.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'send_track_request=1&user_id=' + userId
        }).then(function(){
            document.querySelectorAll('.fru-track-btn[data-uid="' + userId + '"]').forEach(function(b){
                b.classList.add('requested');
                b.textContent = 'Requested';
                b.disabled = false;
            });
        }).catch(function(){
            btn.disabled = false;
            btn.textContent = 'Request';
        });
        return;
    }

    btn.textContent = '…';
    fetch('profile.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=toggle_follow&user_id=' + userId
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        var nowFollowing = data && data.status === 'ok' ? !!data.following : !wasFollowing;
        document.querySelectorAll('.fru-track-btn[data-uid="' + userId + '"]').forEach(function(b){
            b.classList.toggle('following', nowFollowing);
            b.textContent = nowFollowing ? 'Untrack' : 'Track';
            b.disabled = false;
        });
    })
    .catch(function(){
        btn.disabled = false;
        btn.textContent = wasFollowing ? 'Untrack' : 'Track';
    });
}

/* ══════════════════════════════════════════════
   RICH GROUP CARD BUILDER
══════════════════════════════════════════════ */
function frBuildGroupRichCard(g){
    var gridId = frUid();
    var gpic = g.group_pic ? (g.group_pic.indexOf('uploads/') === 0 ? g.group_pic : 'uploads/' + g.group_pic) : 'uploads/default_group.png';
    var owner = g.owner_username || g.creator_name || 'Unknown';
    var members = parseInt(g.member_count || 0);
    var isJoined = !!parseInt(g.is_joined);

    var html = '<div class="frg-card" data-gid="' + g.id + '">'
        + '<div class="frg-header">'
            + '<div class="frg-pic-btn" onclick="location.href=\'group.php?id=' + g.id + '\'">'
                + '<img class="frg-pic" src="' + frEsc(gpic) + '" onerror="this.src=\'uploads/default_group.png\'">'
            + '</div>'
            + '<div class="frg-headtext" onclick="location.href=\'group.php?id=' + g.id + '\'">'
                + '<div class="frg-title">' + frEsc(g.title) + (parseInt(g.is_adult) ? ' <span class="frg-nsfw">18+</span>' : '') + '</div>'
                + '<div class="frg-meta"><span class="frg-owner">' + frEsc(owner) + '</span><span class="frg-dot">•</span><span class="frg-members">' + members.toLocaleString() + ' members</span></div>'
            + '</div>'
            + '<button class="frg-join-btn ' + (isJoined ? 'joined' : '') + '" data-gid="' + g.id + '" onclick="event.stopPropagation();frGroupClick(this,' + g.id + ')">' + (isJoined ? 'Leave' : 'Join') + '</button>'
        + '</div>'
        + '<div class="frg-posts-grid" id="' + gridId + '">'
            + Array(3).fill('<div class="frg-post-tile"><div class="frg-post-skel"></div></div>').join('')
            + '<div class="frg-post-tile frg-seemore-tile" onclick="location.href=\'group.php?id=' + g.id + '\'"><i class="ri-arrow-right-s-line"></i>See more</div>'
        + '</div>'
    + '</div>';

    return { html: html, gridId: gridId };
}

function frRenderGroupListRich(arr){
    if (!arr.length) return frEmptyMsg('groups');
    var out = '';
    var refs = [];
    arr.forEach(function(g){
        var built = frBuildGroupRichCard(g);
        out += built.html;
        refs.push({ id: g.id, gridId: built.gridId });
    });
    window._frPendingGroupMounts = refs;
    return out;
}

function frMountGroups(arr){
    (window._frPendingGroupMounts || []).forEach(function(ref){
        frLoadGroupPosts(ref.id, ref.gridId);
    });
    window._frPendingGroupMounts = null;
}

function frLoadGroupPosts(groupId, gridId){
    fetch('search.php?type=group_posts&group_id=' + groupId + '&limit=3')
        .then(function(r){ return r.json(); })
        .then(function(posts){ frFillPostGrid(gridId, Array.isArray(posts) ? posts : [], 'frg'); })
        .catch(function(){ frFillPostGrid(gridId, [], 'frg'); });
}

/* ── JOIN/LEAVE — optimistic UI, syncs every instance on screen ── */
function frGroupClick(btn, groupId){
    if (btn.disabled) return;
    btn.disabled = true;
    var wasJoined = btn.classList.contains('joined');
    var willJoin = !wasJoined;

    // optimistic flip across every matching button (featured + list share the same group)
    document.querySelectorAll('.frg-join-btn[data-gid="' + groupId + '"]').forEach(function(b){
        b.classList.toggle('joined', willJoin);
        b.textContent = willJoin ? 'Leave' : 'Join';
    });

    var body = (willJoin ? 'join_group=1' : 'leave_group=1');
    fetch('group.php?id=' + groupId, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body
    })
    .catch(function(){
        // revert on network failure
        document.querySelectorAll('.frg-join-btn[data-gid="' + groupId + '"]').forEach(function(b){
            b.classList.toggle('joined', wasJoined);
            b.textContent = wasJoined ? 'Leave' : 'Join';
        });
    })
    .finally(function(){
        document.querySelectorAll('.frg-join-btn[data-gid="' + groupId + '"]').forEach(function(b){ b.disabled = false; });
    });
}

/* ══════════════════════════════════════════════
   RICH MEMEPEDIA CARD BUILDER
══════════════════════════════════════════════ */
function frFmtDate(t){
    if (!t) return '';
    var d = new Date(t.replace(' ', 'T'));
    if (isNaN(d)) return '';
    var mo = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return mo[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
}

function frBuildMemepediaRichCard(w){
    var media = w.media_url ? (w.media_url.indexOf('uploads/') === 0 ? w.media_url : 'uploads/' + w.media_url) : '';
    var summary = (w.summary || '').trim();
    var author = w.creator_name || w.author_username || 'Unknown';
    var authorPic = w.creator_avatar || w.author_pic || '';
    var dateStr = frFmtDate(w.created_at || w.updated_at || '');

    var mediaHtml = media
        ? '<img src="' + frEsc(media) + '" onerror="this.parentNode.innerHTML=\'<i class=&quot;ri-book-open-line&quot;></i>\'">'
        : '<i class="ri-book-open-line"></i>';

    return '<div class="frw-card" onclick="location.href=\'memepedia.php?open=' + w.id + '\'">'
        + '<div class="frw-media">' + mediaHtml + '</div>'
        + '<div class="frw-body">'
            + '<div class="frw-title">' + frEsc(w.title) + '</div>'
            + (summary ? '<div class="frw-summary">' + frEsc(summary) + '</div>' : '<div class="frw-summary" style="color:rgba(255,255,255,0.25);font-style:italic;">No summary yet</div>')
            + '<div class="frw-meta">'
                + (authorPic ? '<img class="frw-author-pic" src="' + frEsc(authorPic) + '" onerror="this.style.display=\'none\'">' : '<i class="ri-user-3-line" style="font-size:12px;"></i>')
                + '<span>' + frEsc(author) + '</span>'
                + (dateStr ? '<span class="frw-dot">•</span><span>' + frEsc(dateStr) + '</span>' : '')
            + '</div>'
        + '</div>'
    + '</div>';
}

function frRenderMemepediaListRich(){
    var arr = FR.data.memepedia;
    if (!arr.length) return frEmptyMsg('memepedia entries');
    return arr.map(frBuildMemepediaRichCard).join('');
}

/* ══════════════════════════════════════════════
   "FOR YOU" — rich featured card + grid + related rows
══════════════════════════════════════════════ */
function frRenderForYou(){
    var d = FR.data;
    var topUser  = d.profile_id[0] || d.users[0] || null;
    var topGroup = (!topUser) ? (d.groups[0] || null) : null;
    var top = topUser || topGroup;
    var html = '';

    if (!top && !d.memes.length && !d.collections.length && !d.sounds.length && !d.sound_collections.length && !d.memepedia.length){
        return '<div class="fr-empty"><i class="ri-search-2-line"></i><p>No results for "' + frEsc(FR.query) + '"</p></div>';
    }

    window._frForYouUserMount  = null;
    window._frForYouGroupMount = null;

    if (topUser) {
        var built = frBuildUserRichCard(topUser);
        html += '<div class="fr-featured-wrap">' + built.html + '</div>';
        window._frForYouUserMount = { id: topUser.id, gridId: built.gridId, bioId: built.bioId, bio: built.bio };
    } else if (topGroup) {
        var builtG = frBuildGroupRichCard(topGroup);
        html += '<div class="fr-featured-wrap">' + builtG.html + '</div>';
        window._frForYouGroupMount = { id: topGroup.id, gridId: builtG.gridId };
    }

    if (d.memes.length){
        html += '<div class="fr-sec-title">Posts</div>';
        html += '<div class="fr-grid">' + d.memes.map(frGridTile).join('') + '</div>';
    }

    if (d.memepedia.length){
        html += '<div class="fr-sec-title">Memepedia</div>';
        html += d.memepedia.slice(0, 3).map(frBuildMemepediaRichCard).join('');
    }

    if (d.collections.length){
        html += '<div class="fr-sec-title">Collections</div><div class="fr-hscroll">' + d.collections.map(frCollectionCard).join('') + '</div>';
    }
    if (d.sounds.length){
        html += '<div class="fr-sec-title">Sounds</div><div class="fr-hscroll">' + d.sounds.map(frSoundCard).join('') + '</div>';
    }
    if (d.sound_collections.length){
        html += '<div class="fr-sec-title">Sound Folders</div><div class="fr-hscroll">' + d.sound_collections.map(frSoundFolderCard).join('') + '</div>';
    }

    var extraGroups = topGroup ? d.groups.slice(1) : d.groups;
    if (extraGroups.length){
        html += '<div class="fr-sec-title">More Communities</div><div class="fr-hscroll">' + extraGroups.map(frGroupCard).join('') + '</div>';
    }
    var extraUsers = topUser ? d.users.slice(1) : d.users;
    if (extraUsers.length){
        html += '<div class="fr-sec-title">More Users</div><div class="fr-hscroll">' + extraUsers.map(frUserCard).join('') + '</div>';
    }

    html += '<div style="height:24px;"></div>';
    return html;
}

function frMountForYou(){
    if (window._frForYouUserMount) {
        frLoadUserPosts(window._frForYouUserMount.id, window._frForYouUserMount.gridId);
        frShowBio(window._frForYouUserMount.bioId, window._frForYouUserMount.bio);
    }
    if (window._frForYouGroupMount) {
        frLoadGroupPosts(window._frForYouGroupMount.id, window._frForYouGroupMount.gridId);
    }
}

function frGridTile(m){
    var thumb = m.meme_type === 'image' ? 'uploads/' + m.file_name : (m.meme_type === 'video' ? 'uploads/' + m.file_name : 'uploads/Text.jpg');
    var enc = encodeURIComponent(m.caption || '');
    if (m.meme_type === 'video'){
        return '<div class="fr-grid-tile" onclick="location.href=\'search.php?q=' + enc + '\'">'
            + '<video src="' + frEsc(thumb) + '" muted playsinline preload="metadata"></video>'
            + '<i class="ri-play-fill fr-play-icon"></i></div>';
    }
    return '<div class="fr-grid-tile" onclick="location.href=\'search.php?q=' + enc + '\'">'
        + '<img src="' + frEsc(thumb) + '" onerror="this.src=\'uploads/Text.jpg\'"></div>';
}

function frCollectionCard(c){
    return '<div class="fr-hcard" onclick="location.href=\'profile.php?user_id=' + c.owner_id + '#memes\'">'
        + '<div class="fr-hcard-icon" style="background:rgba(255,201,71,0.12);color:#ffc947;"><i class="ri-folder-3-line"></i></div>'
        + '<div class="fr-hcard-body"><div class="fr-hcard-name">' + frEsc(c.name) + '</div>'
        + '<div class="fr-hcard-sub">' + (c.item_count||0) + ' items</div></div></div>';
}
function frSoundCard(s){
    return '<div class="fr-hcard" onclick="location.href=\'audio.php?user_id=' + s.user_id + '\'">'
        + '<div class="fr-hcard-icon" style="background:rgba(100,200,255,0.1);color:#64c8ff;"><i class="ri-music-2-line"></i></div>'
        + '<div class="fr-hcard-body"><div class="fr-hcard-name">' + frEsc(s.title) + '</div>'
        + '<div class="fr-hcard-sub">by ' + frEsc(s.username||'') + '</div></div></div>';
}
function frSoundFolderCard(sf){
    return '<div class="fr-hcard" onclick="location.href=\'audio.php?user_id=' + sf.owner_id + '\'">'
        + '<div class="fr-hcard-icon" style="background:rgba(150,120,255,0.1);color:#b09aff;"><i class="ri-folder-music-line"></i></div>'
        + '<div class="fr-hcard-body"><div class="fr-hcard-name">' + frEsc(sf.name) + '</div>'
        + '<div class="fr-hcard-sub">' + (sf.item_count||0) + ' tracks</div></div></div>';
}
function frGroupCard(g){
    var gpic = g.group_pic ? (g.group_pic.indexOf('uploads/') === 0 ? g.group_pic : 'uploads/' + g.group_pic) : 'uploads/default_group.png';
    return '<div class="fr-hcard" onclick="location.href=\'group.php?id=' + g.id + '\'">'
        + '<img class="fr-hcard-thumb" src="' + frEsc(gpic) + '" onerror="this.src=\'uploads/default_group.png\'">'
        + '<div class="fr-hcard-body"><div class="fr-hcard-name">' + frEsc(g.title) + '</div>'
        + '<div class="fr-hcard-sub">' + (g.member_count||0) + ' members</div></div></div>';
}
function frUserCard(u){
    var pic = u.profile_pic || 'uploads/mee.jpeg';
    return '<div class="fr-hcard" onclick="location.href=\'profile.php?user_id=' + u.id + '\'">'
        + '<img class="fr-hcard-thumb" src="' + frEsc(pic) + '" onerror="this.src=\'uploads/mee.jpeg\'" style="border-radius:0;">'
        + '<div class="fr-hcard-body"><div class="fr-hcard-name">' + frEsc(u.username) + '</div>'
        + '<div class="fr-hcard-sub">' + (u.nickname ? '@'+frEsc(u.nickname) : 'User') + '</div></div></div>';
}

/* ══════════════════════════════════════════════
   REMAINING SIMPLE LIST TABS (unchanged style)
══════════════════════════════════════════════ */
function frRenderProfileIdList(){
    var arr = FR.data.profile_id;
    if (!arr.length) return frEmptyMsg('profiles');
    var out = '';
    var refs = [];
    arr.forEach(function(u){
        var built = frBuildUserRichCard(u);
        out += built.html;
        refs.push({ id: u.id, gridId: built.gridId, bioId: built.bioId, bio: built.bio });
    });
    setTimeout(function(){
        refs.forEach(function(ref){
            frLoadUserPosts(ref.id, ref.gridId);
            frShowBio(ref.bioId, ref.bio);
        });
    }, 0);
    return out;
}

function frRenderCollectionList(){
    var arr = FR.data.collections;
    if (!arr.length) return frEmptyMsg('collections');
    return arr.map(function(c){
        return '<div class="fr-row" onclick="location.href=\'profile.php?user_id=' + c.owner_id + '#memes\'">'
            + '<div class="fr-row-icon" style="background:rgba(255,201,71,0.12);color:#ffc947;"><i class="ri-folder-3-line"></i></div>'
            + '<div class="fr-row-info"><div class="fr-row-name">' + frEsc(c.name) + '</div>'
            + '<div class="fr-row-sub">by ' + frEsc(c.owner_username||'') + ' · ' + (c.item_count||0) + ' items</div></div>'
            + '<i class="ri-arrow-right-s-line" style="color:rgba(255,255,255,0.2);"></i></div>';
    }).join('');
}

function frRenderSoundList(){
    var arr = FR.data.sounds;
    if (!arr.length) return frEmptyMsg('sounds');
    return arr.map(function(s){
        return '<div class="fr-row" onclick="location.href=\'audio.php?user_id=' + s.user_id + '\'">'
            + '<div class="fr-row-icon" style="background:rgba(100,200,255,0.1);color:#64c8ff;"><i class="ri-music-2-line"></i></div>'
            + '<div class="fr-row-info"><div class="fr-row-name">' + frEsc(s.title) + '</div>'
            + '<div class="fr-row-sub">by ' + frEsc(s.username||'') + '</div></div>'
            + '<i class="ri-arrow-right-s-line" style="color:rgba(255,255,255,0.2);"></i></div>';
    }).join('');
}

function frRenderSoundFolderList(){
    var arr = FR.data.sound_collections;
    if (!arr.length) return frEmptyMsg('sound folders');
    return arr.map(function(sf){
        return '<div class="fr-row" onclick="location.href=\'audio.php?user_id=' + sf.owner_id + '\'">'
            + '<div class="fr-row-icon" style="background:rgba(150,120,255,0.1);color:#b09aff;"><i class="ri-folder-music-line"></i></div>'
            + '<div class="fr-row-info"><div class="fr-row-name">' + frEsc(sf.name) + '</div>'
            + '<div class="fr-row-sub">by ' + frEsc(sf.owner_username||'') + ' · ' + (sf.item_count||0) + ' tracks</div></div>'
            + '<i class="ri-arrow-right-s-line" style="color:rgba(255,255,255,0.2);"></i></div>';
    }).join('');
}

/* ── ESC key ── */
document.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && document.getElementById('frModal').classList.contains('fr-open')){
        closeFullResultsModal();
    }
});
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
            $pic        = !empty($u['profile_pic']) ? $u['profile_pic'] : $defaultPic;
            $uid        = (int)$u['id'];

            $is_blocked        = !empty($u['is_blocked']);
            $is_following      = !empty($u['is_following']);
            $is_private        = !empty($u['is_private']);
            $is_track_approved = !empty($u['is_track_approved']);
            $track_req_status  = $u['track_req_status'] ?? null;

            /* Decide button state — mirrors the search modal logic exactly */
            $show_private_label   = false;
            $show_requested_label = false;
            $show_track_btn       = false;

            if($current_user && !$is_blocked){
                if($is_private && !$is_following && !$is_track_approved){
                    if($track_req_status === 'pending'){
                        $show_requested_label = true;
                    } else {
                        $show_private_label = true;
                    }
                } else {
                    $show_track_btn = true;
                }
            }
        ?>
        <div class="user-result-row">

            <img src="<?php echo htmlspecialchars($pic); ?>"
                 onerror="this.src='<?php echo $defaultPic; ?>';"
                 onclick="location.href='profile.php?user_id=<?php echo $uid; ?>'">

            <div class="user-result-info"
                 onclick="location.href='profile.php?user_id=<?php echo $uid; ?>'">
                <div class="username">
                    <svg xmlns="http://www.w3.org/2000/svg" width="9" height="9" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="12" fill="#FF0000"/>
                        <path d="M9 12l2 2 4-4" stroke="white" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <?php echo htmlspecialchars($u['username']); ?>
                    <?php if($is_blocked): ?>
                        <span style="color:red;font-weight:bold;"> Blocked</span>
                    <?php endif; ?>
                </div>
                <?php if(!empty($u['nickname'])): ?>
                    <div class="nickname">@<?php echo htmlspecialchars($u['nickname']); ?></div>
                <?php endif; ?>
            </div>

            <?php if($is_blocked): ?>
                <!-- blocked: no button -->

            <?php elseif($show_requested_label): ?>
                <span class="sm-private-label"
                      style="flex-shrink:0;display:inline-flex;align-items:center;gap:4px;font-size:clamp(9px,2.2vw,11px);padding:5px 8px;border-radius:20px;border:1.5px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.04);color:rgba(255,255,255,0.35);white-space:nowrap;">
                    <i class="ri-time-line"></i> Requested
                </span>

            <?php elseif($show_private_label): ?>
                <span class="sm-private-label clickable"
                      data-user="<?php echo $uid; ?>"
                      onclick="srRequestTrack(this, <?php echo $uid; ?>)"
                      style="flex-shrink:0;cursor:pointer;display:inline-flex;align-items:center;gap:4px;font-size:clamp(9px,2.2vw,11px);padding:5px 8px;border-radius:20px;border:1.5px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.04);color:rgba(255,255,255,0.35);white-space:nowrap;transition:background 0.2s,color 0.2s,border-color 0.2s;">
                    <i class="ri-lock-line"></i> Private · Request Track
                </span>

            <?php elseif($show_track_btn): ?>
                <button class="follow-btn <?php echo $is_following ? 'following' : ''; ?>"
                        data-user="<?php echo $uid; ?>"
                        style="flex-shrink:0;font-size:clamp(0.72rem,2.5vw,1rem);padding:clamp(5px,1.5vw,6px) clamp(10px,3vw,14px);min-width:0;">
                    <?php echo $is_following ? 'Untrack' : 'Track'; ?>
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

<script>
/* ── Static search results: Request Track handler ── */
function srRequestTrack(el, userId) {
    el.style.pointerEvents = 'none';
    fetch('search.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=request_track&user_id=' + encodeURIComponent(userId)
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if(d.status === 'ok'){
            el.innerHTML = '<i class="ri-time-line"></i> Requested';
            el.classList.remove('clickable');
            el.style.cursor   = 'default';
            el.style.color    = 'rgba(255,255,255,0.35)';
            el.style.borderColor = 'rgba(255,255,255,0.1)';
        } else {
            el.style.pointerEvents = '';
        }
    })
    .catch(function(){ el.style.pointerEvents = ''; });
}
</script>

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

<div class="discover-grid" id="discoverGrid">
<?php
$index = 0;
foreach($memes as $row){

    $uploader_id = (int)$row['uploaded_by'];
    if($uploader_id != (int)$current_user && !empty($_private_profile_map[$uploader_id]) && empty($_approved_map[$uploader_id])) continue;

    $class   = $search_query ? "meme-tile" : ($index < 2 ? "meme-tile trending" : "meme-tile");
    $meme_id = $row['id'];

    $created      = strtotime($row['created_at']);
    $diff         = time() - $created;
    $is_new_badge = ($diff <= 172800);

    $liked_res   = $conn->query("SELECT id FROM likes WHERE meme_id=$meme_id AND user_id=$current_user");
    $user_liked  = ($liked_res->num_rows > 0);
    $total_likes = $conn->query("SELECT COUNT(*) as total FROM likes WHERE meme_id=$meme_id")->fetch_assoc()['total'];

    $is_fav      = $conn->query("SELECT id FROM favorites WHERE meme_id=$meme_id AND user_id=$current_user")->num_rows > 0;
    $is_repost   = $conn->query("SELECT id FROM reposts WHERE meme_id=$meme_id AND user_id=$current_user")->num_rows > 0;
    $fav_count   = $conn->query("SELECT COUNT(*) as c FROM favorites WHERE meme_id=$meme_id")->fetch_assoc()['c'];
    $repost_count= $conn->query("SELECT COUNT(*) as c FROM reposts WHERE meme_id=$meme_id")->fetch_assoc()['c'];

    $is_following = false;
    if($current_user){
        $chk_follow = $conn->query("SELECT id FROM followers WHERE follower_id=$current_user AND following_id=".$row['uploaded_by']);
        $is_following = $chk_follow->num_rows > 0;
    }

    $group_info = $_group_map[$meme_id] ?? null;

    /* safe JS args — NO newlines inside the string */
    $safe_file     = addslashes($row['file_name']);
    $safe_type     = addslashes($row['type']);
    $safe_pic      = addslashes(!empty($row['profile_pic']) ? $row['profile_pic'] : 'uploads/mee.jpeg');
    $safe_username = addslashes($row['username']);
    $safe_caption  = addslashes(str_replace(["\r","\n"], ' ', $row['caption']));

    $modal_args = "'uploads/{$safe_file}','{$safe_type}','{$safe_pic}','{$row['uploaded_by']}','{$safe_username}','{$safe_caption}'";

    echo '<div class="'.$class.'" data-id="'.$meme_id.'" onclick="openDiscoverModal('.$modal_args.')">';

    /* ── TOP BUTTONS ── */
    echo '<div class="meme-btn left like-btn '.($user_liked?'active':'').'" data-id="'.$meme_id.'" onclick="event.stopPropagation()" style="left:0px;">
        <i class="ri-heart-3-fill"></i><span class="count">'.$total_likes.'</span>
    </div>';
    echo '<div class="meme-btn left repost-btn '.($is_repost?'active':'').'" data-id="'.$meme_id.'" onclick="event.stopPropagation()" style="left:53px;">
        <i class="ri-repeat-line"></i><span class="count">'.$repost_count.'</span>
    </div>';
    echo '<div class="meme-btn left fav-btn '.($is_fav?'active':'').'" data-id="'.$meme_id.'" onclick="event.stopPropagation()" style="left:106px;">
        <i class="ri-bookmark-fill"></i><span class="count">'.$fav_count.'</span>
    </div>';

    /* ── POSTED BY OVERLAY ── */
    echo '<div class="posted-by-overlay" onclick="event.stopPropagation()" style="position:absolute;top:48px;left:12px;font-size:clamp(0.6rem,2vw,0.75rem);font-weight:500;z-index:5;display:flex;flex-direction:column;align-items:flex-start;gap:4px;max-width:calc(100% - 16px);">
        <div style="display:flex;align-items:center;gap:3px;flex-wrap:nowrap;">
            <span style="color:#f24455;font-weight:600;white-space:nowrap;">Posted By</span>
            <strong><a href="profile.php?user_id='.$row['uploaded_by'].'" style="color:white;text-decoration:underline;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:80px;">'.
            ($row['uploaded_by']==$current_user ? 'You' : (mb_strlen($row['username'])>10 ? mb_substr($row['username'],0,10).'...' : $row['username']))
            .'</a></strong>
        </div>';

    if($row['uploaded_by'] != $current_user){
        echo '<button class="track-btn '.($is_following?'following':'').'" data-user="'.$row['uploaded_by'].'" onclick="event.stopPropagation()" style="padding:clamp(2px,0.8vw,4px) clamp(6px,1.5vw,10px);width:auto;font-size:clamp(9px,2.2vw,11px);margin-left:0;">'.($is_following?'Untrack':'Track').'</button>';
    }

    if($group_info){
        $g_id    = (int)$group_info['group_id'];
        $g_title = htmlspecialchars($group_info['group_title']);
        echo '<div style="display:flex;align-items:center;gap:4px;font-size:clamp(0.58rem,1.8vw,0.65rem);font-weight:500;color:rgba(255,255,255,0.88);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:calc(100% - 8px);" onclick="event.stopPropagation()">
            <i class="ri-team-line" style="font-size:0.7rem;color:rgba(255,255,255,0.4);flex-shrink:0;"></i>
            From&nbsp;<a href="group.php?id='.$g_id.'" onclick="event.stopPropagation()" style="color:#FF4C4C;text-decoration:none;font-weight:600;font-size:clamp(0.58rem,1.8vw,0.65rem);">'.$g_title.'</a>
        </div>';
    }
    echo '</div>'; /* end posted-by-overlay */

    /* ── MEDIA ── */
    if($row['type']==='image'){
        echo '<img src="uploads/'.$row['file_name'].'" loading="lazy" style="width:100%;height:100%;object-fit:cover;cursor:pointer;" onclick="openDiscoverModal('.$modal_args.')">';
    } elseif($row['type']==='video'){
        echo '<video src="uploads/'.$row['file_name'].'" muted autoplay loop playsinline style="width:100%;height:100%;object-fit:cover;cursor:pointer;" onclick="openDiscoverModal('.$modal_args.')"></video>';
    } else {
        echo '<img src="uploads/Text.jpg" loading="lazy" style="width:100%;height:100%;object-fit:cover;cursor:pointer;" onclick="openDiscoverModal('.$modal_args.')">';
    }

    /* ── NEW BADGE ── */
    if($is_new_badge){
        echo '<div class="peura-new-tag" style="bottom:55px;">NEW</div>';
    }

    /* ── USER BAR ── */
    echo '<div class="user-bar glass" onclick="event.stopPropagation()">
        <a href="profile.php?user_id='.$row['uploaded_by'].'" onclick="event.stopPropagation()" style="flex-shrink:0;">
            <img src="'.(!empty($row['profile_pic']) ? $row['profile_pic'] : 'uploads/mee.jpeg').'"
                 onerror="this.src=\'uploads/mee.jpeg\'"
                 style="width:clamp(21px,4vw,30px);height:clamp(21px,4vw,30px);min-width:clamp(21px,4vw,30px);border-radius:50%;object-fit:cover;border:2px solid red;display:block;flex-shrink:0;">
        </a>
        <div style="display:flex;flex-direction:column;justify-content:center;min-width:0;flex:1;">
            <div style="color:rgba(255,255,255,0.9);font-size:clamp(0.65rem,2.2vw,0.82rem);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0;width:100%;font-weight:500;">
                '.linkifyDiscoverCaption(htmlspecialchars($row['caption']), $conn).'
            </div>
        </div>
    </div>';

    /* ── FEED DATA FOR MODAL ── */
    echo '<script>
    if(typeof peuraFeed==="undefined") var peuraFeed=[];
    peuraFeed.push({
        id:'.(int)$meme_id.',
        file:"uploads/'.addslashes($row['file_name']).'",
        type:"'.addslashes($row['type']).'",
        pic:"'.addslashes(!empty($row['profile_pic']) ? $row['profile_pic'] : 'uploads/mee.jpeg').'",
        userId:"'.(int)$row['uploaded_by'].'",
        username:"'.addslashes($row['username']).'",
        caption:"'.addslashes(str_replace(["\r","\n"],' ',$row['caption'])).'",
        time:"'.addslashes($row['created_at']).'",
        likeCount:'.(int)$total_likes.',
        repostCount:'.(int)$repost_count.',
        favCount:'.(int)$fav_count.',
        liked:'.($user_liked?'true':'false').',
        reposted:'.($is_repost?'true':'false').',
        faved:'.($is_fav?'true':'false').'
    });
    </scr'.'ipt>';

    echo '</div>';
    $index++;
}
?>
</div>

<!-- ══════════════════════════════════
     DISCOVER CARD MODAL — updated with Track button
══════════════════════════════════════ -->
<div id="discoverModal"
     onclick="if(event.target===this)closeDiscoverModal()"
     style="display:none;position:fixed;inset:0;z-index:9999999;
            background:rgba(0,0,0,0.92);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);
            align-items:center;justify-content:center;padding:16px;flex-direction:row;gap:12px;">

    <div id="dm-card" style="background:rgba(18,17,17,0.97);border:1px solid rgba(255,255,255,0.07);
         border-radius:20px;width:100%;max-width:430px;overflow:hidden;
         display:flex;flex-direction:column;max-height:90vh;position:relative;">
        <div id="dm-inner" style="display:flex;flex-direction:column;overflow:hidden;flex:1;"></div>
    </div>

    <!-- Desktop arrows -->
    <div id="dm-arrows" style="display:flex;flex-direction:column;align-items:center;gap:10px;flex-shrink:0;">
        <button onclick="dmLoadIndex(dmCurrentIndex-1)"
                style="background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.12);
                       border-radius:50%;width:40px;height:40px;display:flex;align-items:center;
                       justify-content:center;cursor:pointer;transition:background 0.2s;"
                onmouseover="this.style.background='rgba(255,255,255,0.15)'"
                onmouseout="this.style.background='rgba(255,255,255,0.07)'">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.7)" stroke-width="2.5"><polyline points="18 15 12 9 6 15"/></svg>
        </button>
        <button onclick="dmLoadIndex(dmCurrentIndex+1)"
                style="background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.12);
                       border-radius:50%;width:40px;height:40px;display:flex;align-items:center;
                       justify-content:center;cursor:pointer;transition:background 0.2s;"
                onmouseover="this.style.background='rgba(255,255,255,0.15)'"
                onmouseout="this.style.background='rgba(255,255,255,0.07)'">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.7)" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
    </div>

    <div id="dm-hint" style="position:absolute;bottom:10px;left:50%;transform:translateX(-50%);
         color:rgba(255,255,255,0.15);font-size:10px;white-space:nowrap;pointer-events:none;">
         swipe or use arrows ↕
    </div>
</div>

<style>
/* ── SCROLLBAR ── */
.dm-caption-box::-webkit-scrollbar{width:3px;}
.dm-caption-box::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.12);border-radius:999px;}

/* ── ACTION BAR ── */
.dm-action-bar{display:flex;align-items:center;gap:6px;padding:10px 14px;border-top:1px solid rgba(255,255,255,0.06);flex-shrink:0;}
.dm-action-btn{display:flex;align-items:center;justify-content:center;gap:5px;background:transparent;border:1px solid rgba(255,255,255,0.1);border-radius:10px;padding:7px 10px;color:white;cursor:pointer;font-size:12px;font-weight:600;font-family:Poppins,sans-serif;transition:background 0.2s,border-color 0.2s;flex:1;-webkit-tap-highlight-color:transparent;}
.dm-action-btn:hover{background:rgba(255,255,255,0.07);}
.dm-close-btn{display:flex;align-items:center;justify-content:center;background:transparent;border:1px solid rgba(255,255,255,0.1);border-radius:10px;padding:7px 12px;color:rgba(255,255,255,0.4);cursor:pointer;font-size:12px;font-family:Poppins,sans-serif;-webkit-tap-highlight-color:transparent;}

/* ── TRACK BUTTON (mirrors feed card .track-btn) ── */
.dm-track-btn {
    padding: 4px 12px;
    border-radius: 20px;
    border: none;
    font-size: 11px;
    font-weight: 600;
    background: rgba(255, 43, 43, 0.51);
    color: white;
    cursor: pointer;
    transition: 0.3s;
    font-family: Poppins, sans-serif;
    flex-shrink: 0;
    min-height: 28px;
    line-height: 1;
    -webkit-tap-highlight-color: transparent;
}
.dm-track-btn.following {
    background: transparent;
    border: 2px solid rgba(255, 43, 43, 0.51);
    color: #ff2b2b;
}
.dm-track-btn:hover {
    background: #ff2b2b;
    color: white;
    transform: scale(1.05);
}

/* ── AD STYLES ── */
.dm-ad-wrap{padding:14px;display:flex;flex-direction:column;gap:10px;overflow-y:auto;flex:1;}
.dm-ad-header{display:flex;align-items:center;gap:8px;}
.dm-ad-logo{width:28px;height:28px;border-radius:50%;border:1.5px solid #FF2D2D;object-fit:cover;flex-shrink:0;}
.dm-ad-brand{font-size:13px;font-weight:600;color:white;}
.dm-ad-type{font-size:10px;color:rgba(255,255,255,0.4);}
.dm-ad-media{width:100%;border-radius:12px;overflow:hidden;border:1.5px solid rgba(255,45,45,0.45);}
.dm-ad-media img,.dm-ad-media video{width:100%;display:block;object-fit:cover;max-height:200px;}
.dm-ad-caption{font-size:11px;color:rgba(255,255,255,0.7);line-height:1.45;}
.dm-ad-cta{display:flex;align-items:center;justify-content:center;gap:6px;padding:10px;background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.1);border-radius:12px;color:white;font-weight:600;font-size:12px;text-decoration:none;transition:background 0.2s;}
.dm-ad-cta:hover{background:rgba(255,255,255,0.12);}

/* ── SUGGESTED USERS ── */
.dm-sugg-label{font-size:10px;font-weight:700;letter-spacing:1.2px;color:#f24455;text-transform:uppercase;}
.dm-sugg-scroll{display:flex;flex-wrap:nowrap;overflow-x:auto;gap:14px;padding:4px 0 8px;scrollbar-width:none;-webkit-overflow-scrolling:touch;}
.dm-sugg-scroll::-webkit-scrollbar{display:none;}
.dm-sugg-user{flex:0 0 auto;width:68px;display:flex;flex-direction:column;align-items:center;gap:5px;text-decoration:none;color:white;}
.dm-sugg-user img{width:52px;height:52px;border-radius:50%;border:2px solid #FF2D2D;object-fit:cover;display:block;transition:transform 0.2s;}
.dm-sugg-user:hover img{transform:scale(1.08);}
.dm-sugg-name{font-size:10px;font-weight:500;text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;width:100%;}
.dm-sugg-track{font-size:9px;font-weight:600;padding:3px 0;width:100%;border-radius:8px;border:1px solid rgba(255,45,45,0.5);background:transparent;color:rgba(255,255,255,0.7);cursor:pointer;transition:all 0.2s;font-family:Poppins,sans-serif;text-align:center;}
.dm-sugg-track:hover,.dm-sugg-track.tracking{background:rgba(255,45,45,0.15);border-color:#FF2D2D;color:#FF2D2D;}

/* ── RESPONSIVE ── */
@media(max-width:480px){
    #dm-arrows,#dm-hint{display:none!important;}
    #discoverModal{padding:8px!important;align-items:center!important;}
    #dm-card{border-radius:18px!important;max-height:88vh!important;}
    .dm-caption-box{font-size:12px!important;max-height:58px!important;}
    .dm-action-btn{padding:7px 6px!important;font-size:11px!important;}
    .dm-track-btn{font-size:10px;padding:4px 9px;}
}
@media(max-width:360px){
    .dm-action-btn{padding:6px 4px!important;font-size:10px!important;}
    .dm-track-btn{font-size:9px;padding:3px 8px;}
}
</style>

<script>
var dmCurrentIndex  = 0;
var _dmMemeId       = null;
var _dmPostUserId   = null; /* tracks uploader of the currently shown post */
var dmFeed          = [];
var _dmBuilt        = false;
const currentUserId = <?php echo (int)$current_user; ?>;

/* ────────────────────────────────────────────────────────────
   DATA LOADING
──────────────────────────────────────────────────────────── */
function _loadDmData(){
    Promise.all([
        fetch('search.php?type=ads').then(function(r){return r.json();}).catch(function(){return[];}),
        fetch('search.php?type=sugg').then(function(r){return r.json();}).catch(function(){return[];})
    ]).then(function(res){
        window.cmAds       = res[0]||[];
        window.cmSuggUsers = res[1]||[];
        _buildDmFeed();
        _dmBuilt = true;
    });
}

function _buildDmFeed(){
    dmFeed = [];
    var ai=0, si=0;
    var ads  = window.cmAds||[];
    var sugg = window.cmSuggUsers||[];
    if(typeof peuraFeed==='undefined') return;
    peuraFeed.forEach(function(post,i){
        dmFeed.push({type:'post',data:post});
        if((i+1)%4===0 && ads.length){
            dmFeed.push({type:'ad',data:ads[ai%ads.length]});
            ai++;
        }
        if((i+1)%7===0 && sugg.length){
            var sl=[];
            for(var j=0;j<6;j++) sl.push(sugg[(si+j)%sugg.length]);
            dmFeed.push({type:'sugg',data:sl});
            si+=6;
        }
    });
}

/* ────────────────────────────────────────────────────────────
   HELPERS
──────────────────────────────────────────────────────────── */
function _dmFmt(t){
    if(!t) return '';
    var d=new Date(t.replace(' ','T'));
    if(isNaN(d)) return t;
    var mo=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return d.getDate()+' '+mo[d.getMonth()]+' '+d.getFullYear()+
           ' · '+String(d.getHours()).padStart(2,'0')+':'+String(d.getMinutes()).padStart(2,'0');
}

function _dmLink(cap){
    return cap.replace(/@([a-zA-Z0-9_]+)/g,function(m,u){
        return '<a href="profile.php?username='+encodeURIComponent(u)+
               '" onclick="event.stopPropagation()" style="color:#f24455;font-weight:600;text-decoration:none;">'+m+'</a>';
    });
}

/* ────────────────────────────────────────────────────────────
   TRACK BUTTON — toggle follow inside the modal
   Mirrors feed card track-btn behavior + keeps feed in sync
──────────────────────────────────────────────────────────── */
function dmToggleTrack(btn){
    var userId = btn.dataset.user;
    fetch('search.php',{
        method:  'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body:    'action=toggle_follow&user_id='+userId
    })
    .then(function(r){return r.json();})
    .then(function(d){
        if(d.status==='ok'){
            if(d.following){
                btn.textContent='Untrack';
                btn.classList.add('following');
            } else {
                btn.textContent='Track';
                btn.classList.remove('following');
            }
            /* keep any matching feed card track-btns in sync */
            document.querySelectorAll('.track-btn[data-user="'+userId+'"]').forEach(function(fb){
                if(d.following){
                    fb.textContent='Untrack';
                    fb.classList.add('following');
                } else {
                    fb.textContent='Track';
                    fb.classList.remove('following');
                }
            });
        }
    });
}

/* ────────────────────────────────────────────────────────────
   RENDER — POST SLOT
   ★ Changed: username row now includes Track/Untrack button
──────────────────────────────────────────────────────────── */
function _dmRenderPost(item){
    var p = item.data;
    _dmMemeId     = p.id;
    _dmPostUserId = p.userId;

    var isOwnPost = String(p.userId) === String(currentUserId);

    /* read current follow state from matching feed card track-btn */
    var feedTrackBtn = document.querySelector('.track-btn[data-user="'+p.userId+'"]');
    var isFollowing  = feedTrackBtn ? feedTrackBtn.classList.contains('following') : false;

    var media = '';
    if(p.type==='image'){
        media='<img src="'+p.file+'" onerror="this.src=\'uploads/mee.jpeg\'" style="width:100%;max-height:52vh;object-fit:contain;display:block;">';
    } else if(p.type==='video'){
        media='<video src="'+p.file+'" autoplay muted loop playsinline style="width:100%;max-height:52vh;object-fit:contain;display:block;"></video>';
    }

    var lc = p.likeCount||0, rc = p.repostCount||0, fc = p.favCount||0;
    var liked = p.liked||false, reposted = p.reposted||false, faved = p.faved||false;

    /* Track button — hidden for own posts */
    var trackBtnHtml = !isOwnPost
        ? '<button class="dm-track-btn '+(isFollowing?'following':'')+'" '+
              'data-user="'+p.userId+'" '+
              'onclick="event.stopPropagation();dmToggleTrack(this)">'+
              (isFollowing?'Untrack':'Track')+
          '</button>'
        : '';

    return (
        /* ── HEADER ROW ── */
        '<div style="display:flex;align-items:center;gap:10px;padding:12px 14px;flex-shrink:0;">'

            /* Avatar */
            +'<img src="'+(p.pic||'uploads/mee.jpeg')+'" onerror="this.src=\'uploads/mee.jpeg\'"'
                +' onclick="location.href=\'profile.php?user_id='+p.userId+'\'"'
                +' style="width:38px;height:38px;border-radius:50%;border:2px solid #FF4C4C;cursor:pointer;object-fit:cover;flex-shrink:0;">'

            /* Name + track btn + timestamp — grows to fill space */
            +'<div style="flex:1;min-width:0;">'

                /* Username row: name · track button — flex, no wrap */
                +'<div style="display:flex;align-items:center;gap:7px;flex-wrap:nowrap;">'
                    +'<div onclick="location.href=\'profile.php?user_id='+p.userId+'\'"'
                         +' style="color:#FF4C4C;font-weight:700;font-size:14px;cursor:pointer;'
                                 +'display:flex;align-items:center;gap:5px;white-space:nowrap;'
                                 +'overflow:hidden;text-overflow:ellipsis;flex-shrink:1;min-width:0;">'
                        +'<svg width="10" height="10" viewBox="0 0 24 24">'
                            +'<circle cx="12" cy="12" r="12" fill="#f00"/>'
                            +'<path d="M9 12l2 2 4-4" stroke="#fff" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>'
                        +'</svg>'
                        +'<span>'+(isOwnPost?'You':p.username)+'</span>'
                    +'</div>'
                    + trackBtnHtml
                +'</div>'

                /* Timestamp */
                +'<div style="font-size:10px;color:rgba(255,255,255,0.32);margin-top:2px;display:flex;align-items:center;gap:4px;">'
                    +'<i class="ri-time-line"></i><span>'+_dmFmt(p.time)+'</span>'
                +'</div>'
            +'</div>'

            /* Close X */
            +'<button onclick="closeDiscoverModal()" style="background:rgba(255,255,255,0.06);border:none;border-radius:50%;'
                +'width:30px;height:30px;display:flex;align-items:center;justify-content:center;'
                +'cursor:pointer;color:rgba(255,255,255,0.45);font-size:15px;flex-shrink:0;-webkit-tap-highlight-color:transparent;">✕</button>'
        +'</div>'

        /* ── MEDIA ── */
        +(media?'<div style="width:100%;background:#000;flex-shrink:0;">'+media+'</div>':'')

        /* ── CAPTION ── */
        +'<div class="dm-caption-box" style="padding:9px 14px;color:rgba(255,255,255,0.78);font-size:13px;'
            +'line-height:1.5;border-top:1px solid rgba(255,255,255,0.06);word-break:break-word;'
            +'overflow-y:auto;max-height:75px;flex-shrink:0;">'+_dmLink(p.caption||'')+'</div>'

        /* ── ACTION BAR ── */
        +'<div class="dm-action-bar">'
            +'<button id="dm-like-btn" class="dm-action-btn"'
                +' style="color:'+(liked?'#ff4c4c':'white')+';border-color:'+(liked?'rgba(255,76,76,0.4)':'rgba(255,255,255,0.1')+');"'
                +' onclick="dmAction(\'like\')"><i class="ri-heart-fill" style="font-size:13px;"></i><span id="dm-like-count">'+lc+'</span></button>'
            +'<button id="dm-repost-btn" class="dm-action-btn"'
                +' style="color:'+(reposted?'#ff4c4c':'white')+';border-color:'+(reposted?'rgba(255,76,76,0.4)':'rgba(255,255,255,0.1')+');"'
                +' onclick="dmAction(\'repost\')"><i class="ri-repeat-line" style="font-size:13px;"></i><span id="dm-repost-count">'+rc+'</span></button>'
            +'<button id="dm-fav-btn" class="dm-action-btn"'
                +' style="color:'+(faved?'#ff4c4c':'white')+';border-color:'+(faved?'rgba(255,76,76,0.4)':'rgba(255,255,255,0.1')+');"'
                +' onclick="dmAction(\'fav\')"><i class="ri-bookmark-fill" style="font-size:13px;"></i><span id="dm-fav-count">'+fc+'</span></button>'
            +'<button class="dm-close-btn" onclick="closeDiscoverModal()">Close</button>'
        +'</div>'
    );
}

/* ────────────────────────────────────────────────────────────
   RENDER — AD SLOT (unchanged)
──────────────────────────────────────────────────────────── */
function _dmRenderAd(item){
    var ad  = item.data;
    var isVid = ['mp4','webm','mov'].indexOf((ad.meme_image||'').split('.').pop().toLowerCase()) !== -1;
    _dmMemeId = null;
    return (
        '<div style="display:flex;align-items:center;justify-content:space-between;padding:12px 14px 0;flex-shrink:0;">'
            +'<span style="font-size:9px;font-weight:800;letter-spacing:1px;color:rgba(255,255,255,0.35);'
                +'text-transform:uppercase;border:1px solid rgba(255,255,255,0.12);border-radius:4px;padding:2px 6px;">AD</span>'
            +'<button onclick="closeDiscoverModal()" style="background:rgba(255,255,255,0.06);border:none;border-radius:50%;'
                +'width:28px;height:28px;display:flex;align-items:center;justify-content:center;cursor:pointer;'
                +'color:rgba(255,255,255,0.45);font-size:14px;">✕</button>'
        +'</div>'
        +'<div class="dm-ad-wrap">'
            +'<div class="dm-ad-header">'
                +'<img src="uploads/'+(ad.image||'')+'" onerror="this.src=\'uploads/mee.jpeg\'" class="dm-ad-logo">'
                +'<div style="min-width:0;"><div class="dm-ad-brand">'+(ad.title||'')+'</div>'
                +'<div class="dm-ad-type">'+(ad.type||'')+'</div></div>'
            +'</div>'
            +'<div class="dm-ad-media">'
                +(isVid?'<video src="uploads/'+ad.meme_image+'" autoplay muted loop playsinline></video>'
                       :'<img src="uploads/'+(ad.meme_image||'')+'" onerror="this.src=\'uploads/mee.jpeg\'">')
            +'</div>'
            +'<div class="dm-ad-caption">'+(ad.caption||'')+'</div>'
            +'<a href="'+(ad.link||'#')+'" target="_blank" class="dm-ad-cta" onclick="event.stopPropagation()">Visit Brand <i class="ri-arrow-right-up-line"></i></a>'
        +'</div>'
        +'<div class="dm-action-bar">'
            +'<button class="dm-action-btn" onclick="dmLoadIndex(dmCurrentIndex-1)"><i class="ri-arrow-up-line"></i> Prev</button>'
            +'<button class="dm-action-btn" onclick="dmLoadIndex(dmCurrentIndex+1)">Next <i class="ri-arrow-down-line"></i></button>'
            +'<button class="dm-close-btn" onclick="closeDiscoverModal()">Close</button>'
        +'</div>'
    );
}

/* ────────────────────────────────────────────────────────────
   RENDER — SUGGESTED USERS SLOT (unchanged)
──────────────────────────────────────────────────────────── */
function _dmRenderSugg(item){
    var users = item.data;
    _dmMemeId = null;
    var uHtml = users.map(function(u){
        var fol = parseInt(u.is_following) > 0;
        return '<div class="dm-sugg-user">'
            +'<a href="profile.php?user_id='+u.id+'" onclick="event.stopPropagation()" style="display:contents;color:inherit;text-decoration:none;">'
                +'<img src="'+(u.profile_pic||'uploads/mee.jpeg')+'" onerror="this.src=\'uploads/mee.jpeg\'">'
                +'<span class="dm-sugg-name">'+u.username+'</span>'
            +'</a>'
            +'<button class="dm-sugg-track '+(fol?'tracking':'')+'" data-user="'+u.id+'" '
                +'onclick="event.stopPropagation();dmToggleSuggTrack(this)">'+(fol?'Untrack':'Track')+'</button>'
        +'</div>';
    }).join('');
    return (
        '<div style="display:flex;align-items:center;justify-content:space-between;padding:12px 14px 0;flex-shrink:0;">'
            +'<span class="dm-sugg-label">Suggested Peumers</span>'
            +'<button onclick="closeDiscoverModal()" style="background:rgba(255,255,255,0.06);border:none;border-radius:50%;'
                +'width:28px;height:28px;display:flex;align-items:center;justify-content:center;cursor:pointer;'
                +'color:rgba(255,255,255,0.45);font-size:14px;">✕</button>'
        +'</div>'
        +'<div style="padding:10px 14px;overflow-y:auto;flex:1;"><div class="dm-sugg-scroll">'+uHtml+'</div></div>'
        +'<div class="dm-action-bar">'
            +'<button class="dm-action-btn" onclick="dmLoadIndex(dmCurrentIndex-1)"><i class="ri-arrow-up-line"></i> Prev</button>'
            +'<button class="dm-action-btn" onclick="dmLoadIndex(dmCurrentIndex+1)">Next <i class="ri-arrow-down-line"></i></button>'
            +'<button class="dm-close-btn" onclick="closeDiscoverModal()">Close</button>'
        +'</div>'
    );
}

/* ────────────────────────────────────────────────────────────
   SLOT RENDERER DISPATCHER
──────────────────────────────────────────────────────────── */
function _dmRenderSlot(idx){
    if(idx<0||idx>=dmFeed.length) return;
    var item  = dmFeed[idx];
    var inner = document.getElementById('dm-inner');
    inner.querySelectorAll('video').forEach(function(v){v.pause();v.src='';});
    if     (item.type==='post') inner.innerHTML=_dmRenderPost(item);
    else if(item.type==='ad')   inner.innerHTML=_dmRenderAd(item);
    else if(item.type==='sugg') inner.innerHTML=_dmRenderSugg(item);
}

/* ────────────────────────────────────────────────────────────
   PUBLIC API
──────────────────────────────────────────────────────────── */
function openDiscoverModal(file,type,pic,userId,username,caption){
    if(!_dmBuilt){ _buildDmFeed(); }
    var idx = dmFeed.findIndex(function(s){
        return s.type==='post' && s.data.file===file && String(s.data.userId)===String(userId);
    });
    dmCurrentIndex = idx>=0 ? idx : 0;
    _dmRenderSlot(dmCurrentIndex);
    var modal = document.getElementById('discoverModal');
    modal.style.display='flex';
    document.body.style.overflow='hidden';
    document.body.style.touchAction='none';
}

function dmLoadIndex(idx){
    if(idx<0||idx>=dmFeed.length) return;
    var card = document.getElementById('dm-card');
    var dir  = idx>dmCurrentIndex ? 1 : -1;
    card.style.transition='transform 0.2s ease,opacity 0.2s ease';
    card.style.transform='translateY('+(dir*-28)+'px)';
    card.style.opacity='0';
    setTimeout(function(){
        dmCurrentIndex=idx;
        _dmRenderSlot(dmCurrentIndex);
        card.style.transition='none';
        card.style.transform='translateY('+(dir*28)+'px)';
        card.style.opacity='0';
        requestAnimationFrame(function(){
            card.style.transition='transform 0.2s ease,opacity 0.2s ease';
            card.style.transform='translateY(0)';
            card.style.opacity='1';
        });
    },200);
}

function closeDiscoverModal(){
    document.getElementById('dm-inner').querySelectorAll('video').forEach(function(v){v.pause();v.src='';});
    document.getElementById('discoverModal').style.display='none';
    document.body.style.overflow='';
    document.body.style.touchAction='';
    _dmMemeId     = null;
    _dmPostUserId = null;
}

function dmAction(type){
    if(!_dmMemeId) return;
    var selMap={like:'.like-btn',repost:'.repost-btn',fav:'.fav-btn'};
    var btnMap={like:'dm-like-btn',repost:'dm-repost-btn',fav:'dm-fav-btn'};
    var cntMap={like:'dm-like-count',repost:'dm-repost-count',fav:'dm-fav-count'};
    var cField={like:'likeCount',repost:'repostCount',fav:'favCount'};
    var sField={like:'liked',repost:'reposted',fav:'faved'};
    var feedBtn=document.querySelector(selMap[type]+'[data-id="'+_dmMemeId+'"]');
    if(!feedBtn) return;
    feedBtn.click();
    setTimeout(function(){
        var cntEl  = feedBtn.querySelector('.count,span');
        var dmBtn  = document.getElementById(btnMap[type]);
        var dmSpan = document.getElementById(cntMap[type]);
        var active = feedBtn.classList.contains('active');
        if(cntEl&&dmSpan) dmSpan.textContent=cntEl.textContent;
        if(dmBtn){ dmBtn.style.color=active?'#ff4c4c':'white'; dmBtn.style.borderColor=active?'rgba(255,76,76,0.4)':'rgba(255,255,255,0.1)'; }
        var fi=dmFeed.find(function(s){return s.type==='post'&&String(s.data.id)===String(_dmMemeId);});
        if(fi&&cntEl){ fi.data[cField[type]]=parseInt(cntEl.textContent)||0; fi.data[sField[type]]=active; }
    },350);
}

/* Suggested users track button (inside sugg slots) */
function dmToggleSuggTrack(btn){
    fetch('search.php',{
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=toggle_follow&user_id='+btn.dataset.user
    })
    .then(function(r){return r.json();})
    .then(function(d){
        if(d.status==='ok'){
            if(d.following){btn.textContent='Untrack';btn.classList.add('tracking');}
            else            {btn.textContent='Track';btn.classList.remove('tracking');}
        }
    });
}

/* ── Init on page load ── */
window.addEventListener('load',function(){ _loadDmData(); });

/* ── Scroll wheel (desktop) ── */
document.getElementById('discoverModal').addEventListener('wheel',function(e){
    e.preventDefault(); e.stopPropagation();
    if(e.deltaY>0) dmLoadIndex(dmCurrentIndex+1);
    else           dmLoadIndex(dmCurrentIndex-1);
},{passive:false});

/* ── Swipe (mobile) ── */
var _dmTY=0;
document.getElementById('discoverModal').addEventListener('touchstart',function(e){_dmTY=e.touches[0].clientY;},{passive:true});
document.getElementById('discoverModal').addEventListener('touchmove',function(e){e.stopPropagation();e.preventDefault();},{passive:false});
document.getElementById('discoverModal').addEventListener('touchend',function(e){
    var d=_dmTY-e.changedTouches[0].clientY;
    if(Math.abs(d)>40){ if(d>0) dmLoadIndex(dmCurrentIndex+1); else dmLoadIndex(dmCurrentIndex-1); }
},{passive:true});

/* ── Keyboard ── */
document.addEventListener('keydown',function(e){
    if(document.getElementById('discoverModal').style.display==='flex'){
        if(e.key==='Escape')    closeDiscoverModal();
        if(e.key==='ArrowDown') dmLoadIndex(dmCurrentIndex+1);
        if(e.key==='ArrowUp')   dmLoadIndex(dmCurrentIndex-1);
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