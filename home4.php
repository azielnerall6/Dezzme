<?php
session_start();
include 'config.php';

$user_id = $_SESSION['user_id'] ?? 0;
$current_user = $_SESSION['user_id'] ?? null;

/* ================================================================
   HELPER: fetch this user's top hashtag affinities.
================================================================ */
function getUserTopTags($conn, $user_id){
    $top_tags = [];
    $tag_weights = [];
    if(!$user_id) return [$top_tags, $tag_weights];

    $stmt = $conn->prepare("
        SELECT
            LOWER(TRIM(LEADING '#' FROM
                REGEXP_SUBSTR(m.caption, '#[a-zA-Z0-9_]+')
            )) AS tag,
            SUM(
                CASE ui.interaction_type
                    WHEN 'repost'   THEN 6.0
                    WHEN 'favorite' THEN 4.0
                    WHEN 'share'    THEN 3.0
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
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while($r = $res->fetch_assoc()){
        if(!empty($r['tag'])){
            $top_tags[]                = $r['tag'];
            $tag_weights[$r['tag']]    = round($r['affinity_score'], 4);
        }
    }
    return [$top_tags, $tag_weights];
}

/* ================================================================
   HELPER: fetch Crossroads-style scored suggested users for $user_id.
================================================================ */
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
            CASE WHEN f.follower_id IS NOT NULL THEN 1 ELSE 0 END AS is_following,
            COALESCE(sig.sig_follow, 0)     AS sig_follow,
            COALESCE(sig.sig_group, 0)      AS sig_group,
            COALESCE(sig.sig_like, 0)       AS sig_like,
            COALESCE(sig.sig_repost, 0)     AS sig_repost,
            COALESCE(sig.sig_fav, 0)        AS sig_fav,
            COALESCE(sig.sig_pod, 0)        AS sig_pod,
            COALESCE(sig.sig_collection, 0) AS sig_collection,
            COALESCE(sig.sig_view, 0)       AS sig_view,
            (COALESCE(sig.sig_follow,0) + COALESCE(sig.sig_group,0) + COALESCE(sig.sig_like,0)
             + COALESCE(sig.sig_repost,0) + COALESCE(sig.sig_fav,0) + COALESCE(sig.sig_pod,0)
             + COALESCE(sig.sig_collection,0) + COALESCE(sig.sig_view,0)) AS overlap_score

        FROM users u

        LEFT JOIN followers f
            ON f.follower_id = ? AND f.following_id = u.id

        INNER JOIN (
            SELECT
                candidate_id,
                MAX(is_follow)     AS sig_follow,
                MAX(is_group)      AS sig_group,
                MAX(is_like)       AS sig_like,
                MAX(is_repost)     AS sig_repost,
                MAX(is_fav)        AS sig_fav,
                MAX(is_pod)        AS sig_pod,
                MAX(is_collection) AS sig_collection,
                MAX(is_view)       AS sig_view
            FROM (
                SELECT f2b.follower_id AS candidate_id, 1 AS is_follow, 0 AS is_group, 0 AS is_like, 0 AS is_repost, 0 AS is_fav, 0 AS is_pod, 0 AS is_collection, 0 AS is_view
                FROM followers f1
                JOIN followers f2b ON f1.following_id = f2b.following_id
                WHERE f1.follower_id = ?

                UNION ALL

                SELECT gm2.user_id, 0, 1, 0, 0, 0, 0, 0, 0
                FROM group_members gm1
                JOIN group_members gm2 ON gm1.group_id = gm2.group_id
                WHERE gm1.user_id = ?

                UNION ALL

                SELECT l2.user_id, 0, 0, 1, 0, 0, 0, 0, 0
                FROM likes l1
                JOIN likes l2 ON l1.meme_id = l2.meme_id
                WHERE l1.user_id = ?

                UNION ALL

                SELECT r2.user_id, 0, 0, 0, 1, 0, 0, 0, 0
                FROM reposts r1
                JOIN reposts r2 ON r1.meme_id = r2.meme_id
                WHERE r1.user_id = ?

                UNION ALL

                SELECT fv2.user_id, 0, 0, 0, 0, 1, 0, 0, 0
                FROM favorites fv1
                JOIN favorites fv2 ON fv1.meme_id = fv2.meme_id
                WHERE fv1.user_id = ?

                UNION ALL

                SELECT pr2.user_id, 0, 0, 0, 0, 0, 1, 0, 0
                FROM pod_discussion_replies pr1
                JOIN pod_discussion_replies pr2 ON pr1.discussion_id = pr2.discussion_id
                WHERE pr1.user_id = ?

                UNION ALL

                SELECT c2.user_id, 0, 0, 0, 0, 0, 0, 1, 0
                FROM collection_items ci1
                JOIN collections c1 ON ci1.collection_id = c1.id AND c1.user_id = ?
                JOIN collection_items ci2 ON ci1.meme_id = ci2.meme_id
                JOIN collections c2 ON ci2.collection_id = c2.id

                UNION ALL

                SELECT pv2.viewer_id, 0, 0, 0, 0, 0, 0, 0, 1
                FROM profile_views pv1
                JOIN profile_views pv2 ON pv1.profile_id = pv2.profile_id
                WHERE pv1.viewer_id = ?
            ) signals
            GROUP BY candidate_id
        ) sig ON sig.candidate_id = u.id

        WHERE u.id != ?
          AND u.id NOT IN ($blocked_ph)
          AND u.id NOT IN ($exclude_ph)
          AND u.id NOT IN (SELECT following_id FROM followers WHERE follower_id = ?)
        HAVING overlap_score >= 2
        ORDER BY overlap_score DESC
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

/* ================================================================
   NAV PIC — fetched once here (with username) and reused later.
================================================================ */
$nav_pic  = 'uploads/mee.jpeg';
$nav_user = null;
if ($user_id) {
    $stmt = $conn->prepare("SELECT profile_pic, username FROM users WHERE id=? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $nav_user = $stmt->get_result()->fetch_assoc();
    if (!empty($nav_user['profile_pic'])) {
        $nav_pic = $nav_user['profile_pic'];
    }
}

/* ── CCM DATA (suggested users + following list for modal) ──
   NOTE: the standalone sponsorships/ad system (ads_*, sponsorships,
   ad_clicks, ad_dismissals, ad_favorites, ad_impressions, ad_likes,
   ad_recent_shows, ad_reports, ad_stats_daily) has been removed
   entirely from this file. The `type=ads` branch that used to live
   here is gone. Posts flagged is_ad=1 (memes.is_ad / meme_ads) are
   a completely separate feature and are untouched. */
if (isset($_GET['type'])) {
    $type = $_GET['type'];
    header('Content-Type: application/json');

    if ($type === 'sugg') {
        /* Batch-enrich suggested users with is_fully_private + has_new_post
           in 2 queries total instead of N+1 per user. */
        $sugg = $user_id ? getSuggestedUsers($conn, $user_id, 20) : [];

        if (!empty($sugg)) {
            $sugg_ids = array_map(function($u){ return (int)$u['id']; }, $sugg);
            $ids_str  = implode(',', $sugg_ids);

            $priv_map = [];
            $pr = $conn->query("SELECT user_id, is_fully_private FROM user_settings WHERE user_id IN ($ids_str)");
            if($pr) while($p = $pr->fetch_assoc()) $priv_map[(int)$p['user_id']] = (int)$p['is_fully_private'];

            $recent_map = [];
            $rc = $conn->query("SELECT DISTINCT uploaded_by FROM memes WHERE uploaded_by IN ($ids_str) AND created_at >= NOW() - INTERVAL 24 HOUR");
            if($rc) while($r = $rc->fetch_assoc()) $recent_map[(int)$r['uploaded_by']] = true;

            foreach($sugg as &$u){
                $uid = (int)$u['id'];
                $u['is_fully_private'] = $priv_map[$uid] ?? 0;
                $u['has_new_post']     = isset($recent_map[$uid]) ? 1 : 0;
            }
            unset($u);
        }

        echo json_encode($sugg);

    } elseif ($type === 'following') {
        $following_list = [];
        if ($user_id) {
            $stmt = $conn->prepare("
                SELECT u.id, u.username, u.profile_pic
                FROM followers f
                JOIN users u ON u.id = f.following_id
                WHERE f.follower_id = ?
                ORDER BY u.username ASC
            ");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) { $following_list[] = $row; }
        }
        echo json_encode($following_list);

    } else {
        echo json_encode([]);
    }
    exit;
}

/* ================= TOGGLE FOLLOW HANDLER ================= */
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_follow'){
    header('Content-Type: application/json');
    if(!$user_id){ echo json_encode(['status'=>'error','message'=>'not logged in']); exit; }

    $target_id = intval($_POST['user_id']);

    $stmt = $conn->prepare("SELECT id FROM followers WHERE follower_id=? AND following_id=?");
    $stmt->bind_param("ii", $user_id, $target_id);
    $stmt->execute();
    $res = $stmt->get_result();

    if($res->num_rows > 0){
        $stmt = $conn->prepare("DELETE FROM followers WHERE follower_id=? AND following_id=?");
        $stmt->bind_param("ii", $user_id, $target_id);
        $stmt->execute();
        echo json_encode(['status'=>'ok','following'=>false]);
    } else {
        $stmt = $conn->prepare("INSERT INTO followers (follower_id, following_id) VALUES (?,?)");
        $stmt->bind_param("ii", $user_id, $target_id);
        $stmt->execute();
        echo json_encode(['status'=>'ok','following'=>true]);
    }
    exit;
}

/* ================= AJAX ACTION HANDLER (LIKE/REPOST/FAV/SHARE/CONNECTED ZEEM) =================
   NOTE: the ad-system actions that used to live here — toggle_ad_like,
   toggle_ad_favorite, log_ad_impression, log_ad_shown, ad_not_interested —
   have all been removed along with the sponsorships/ad_* tables they
   depended on. */
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])){
    header('Content-Type: application/json');
    $meme_id = intval($_POST['meme_id'] ?? 0);
    $action = $_POST['action'];

    function countRows($conn, $table, $meme_id){
        $stmt = $conn->prepare("SELECT COUNT(*) as c FROM `$table` WHERE meme_id=?");
        $stmt->bind_param("i", $meme_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc()['c'];
    }

    function recordInteraction($conn, $user_id, $meme_id, $type) {
        if(!$user_id) return;
        $stmt = $conn->prepare("INSERT INTO user_interactions (user_id, meme_id, interaction_type) VALUES (?, ?, ?)");
        $stmt->bind_param("iis", $user_id, $meme_id, $type);
        $stmt->execute();
    }

    if($action === "toggle_like"){
        $stmt = $conn->prepare("SELECT id FROM likes WHERE user_id=? AND meme_id=?");
        $stmt->bind_param("ii", $user_id, $meme_id);
        $stmt->execute();
        $check = $stmt->get_result();

        if($check->num_rows){
            $stmt = $conn->prepare("DELETE FROM likes WHERE user_id=? AND meme_id=?");
            $stmt->bind_param("ii", $user_id, $meme_id);
            $stmt->execute();

            $stmt = $conn->prepare("DELETE FROM user_interactions WHERE user_id=? AND meme_id=? AND interaction_type='like'");
            $stmt->bind_param("ii", $user_id, $meme_id);
            $stmt->execute();
        } else {
            $stmt = $conn->prepare("INSERT INTO likes (user_id,meme_id) VALUES (?,?)");
            $stmt->bind_param("ii", $user_id, $meme_id);
            $stmt->execute();
            recordInteraction($conn, $user_id, $meme_id, 'like');
        }
        echo json_encode(["count" => countRows($conn,"likes",$meme_id)]);
        exit;
    }

    if($action === "toggle_repost"){
        $stmt = $conn->prepare("SELECT id FROM reposts WHERE user_id=? AND meme_id=?");
        $stmt->bind_param("ii", $user_id, $meme_id);
        $stmt->execute();
        $check = $stmt->get_result();

        if($check->num_rows){
            $stmt = $conn->prepare("DELETE FROM reposts WHERE user_id=? AND meme_id=?");
            $stmt->bind_param("ii", $user_id, $meme_id);
            $stmt->execute();

            $stmt = $conn->prepare("DELETE FROM user_interactions WHERE user_id=? AND meme_id=? AND interaction_type='repost'");
            $stmt->bind_param("ii", $user_id, $meme_id);
            $stmt->execute();
        } else {
            $stmt = $conn->prepare("INSERT INTO reposts (user_id,meme_id) VALUES (?,?)");
            $stmt->bind_param("ii", $user_id, $meme_id);
            $stmt->execute();
            recordInteraction($conn, $user_id, $meme_id, 'repost');
        }
        echo json_encode(["count" => countRows($conn,"reposts",$meme_id)]);
        exit;
    }

    if($action === "toggle_favorite"){
        $stmt = $conn->prepare("SELECT id FROM favorites WHERE user_id=? AND meme_id=?");
        $stmt->bind_param("ii", $user_id, $meme_id);
        $stmt->execute();
        $check = $stmt->get_result();

        if($check->num_rows){
            $stmt = $conn->prepare("DELETE FROM favorites WHERE user_id=? AND meme_id=?");
            $stmt->bind_param("ii", $user_id, $meme_id);
            $stmt->execute();

            $stmt = $conn->prepare("DELETE FROM user_interactions WHERE user_id=? AND meme_id=? AND interaction_type='favorite'");
            $stmt->bind_param("ii", $user_id, $meme_id);
            $stmt->execute();
        } else {
            $stmt = $conn->prepare("INSERT INTO favorites (user_id,meme_id) VALUES (?,?)");
            $stmt->bind_param("ii", $user_id, $meme_id);
            $stmt->execute();
            recordInteraction($conn, $user_id, $meme_id, 'favorite');
        }
        echo json_encode(["count" => countRows($conn,"favorites",$meme_id)]);
        exit;
    }

    /* ── SHARE (share to tracked/followed users via message) ── */
    if($action === "share_post"){
        if(!$user_id){
            echo json_encode(["status" => "error", "message" => "not logged in"]);
            exit;
        }

        $receiver_ids_raw = $_POST['receiver_ids'] ?? '';
        $receiver_ids = array_values(array_unique(array_filter(
            array_map('intval', explode(',', $receiver_ids_raw)),
            function($v) use ($user_id){ return $v > 0 && $v !== $user_id; }
        )));

        if(!$meme_id || empty($receiver_ids)){
            echo json_encode(["status" => "error", "message" => "invalid request"]);
            exit;
        }

        $stmt = $conn->prepare("SELECT id FROM memes WHERE id=? LIMIT 1");
        $stmt->bind_param("i", $meme_id);
        $stmt->execute();
        $meme_check = $stmt->get_result();
        if(!$meme_check || $meme_check->num_rows === 0){
            echo json_encode(["status" => "error", "message" => "meme not found"]);
            exit;
        }

        $sent_to    = [];
        $stmt_share = $conn->prepare("INSERT INTO shares (user_id, meme_id, share_type) VALUES (?, ?, 'message')");
        $stmt_msg   = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message, seen) VALUES (?, ?, ?, 0)");

        foreach($receiver_ids as $rid){
            $stmt_share->bind_param("ii", $user_id, $meme_id);
            $stmt_share->execute();

            $msg_text = "[meme:$meme_id]";
            $stmt_msg->bind_param("iis", $user_id, $rid, $msg_text);
            $stmt_msg->execute();

            recordInteraction($conn, $user_id, $meme_id, 'share');
            $sent_to[] = $rid;
        }

        echo json_encode([
            "status"  => "ok",
            "count"   => countRows($conn, "shares", $meme_id),
            "sent_to" => $sent_to
        ]);
        exit;
    }

    if($action === 'block_user' && isset($_POST['target_id'])){
        $target = intval($_POST['target_id']);
        if($user_id && $target !== $user_id){
            $stmt = $conn->prepare("INSERT IGNORE INTO blocks (blocker_id, blocked_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $user_id, $target);
            $stmt->execute();

            $stmt2 = $conn->prepare("DELETE FROM followers WHERE follower_id=? AND following_id=?");
            $stmt2->bind_param("ii", $user_id, $target);
            $stmt2->execute();

            echo json_encode(['status' => 'ok']);
        } else {
            echo json_encode(['status' => 'error']);
        }
        exit;
    }

    /* ── CONNECTED ZEEM — fetch a linked post's full payload for the
       reels modal's horizontal "Connected Zeem" layer. Mirrors the
       same shape used to build peuraFeed / ccmFeed post entries. ── */
    if($action === "get_connected_meme"){
        $target_id = (int)($_POST['meme_id'] ?? 0);
        if(!$target_id){ echo json_encode(['ok'=>false]); exit; }

        $stmt = $conn->prepare("
            SELECT memes.*, users.username, users.nickname, users.profile_pic,
                ma.cta_text AS ad_cta_text, ma.cta_url AS ad_cta_url, ma.cta_color AS ad_cta_color
            FROM memes
            JOIN users ON memes.uploaded_by = users.id
            LEFT JOIN meme_ads ma ON ma.meme_id = memes.id
            WHERE memes.id = ? LIMIT 1
        ");
        $stmt->bind_param("i", $target_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if(!$row){ echo json_encode(['ok'=>false]); exit; }

        $poster_id = (int)$row['uploaded_by'];

        // blocked either direction
        if($user_id){
            $blk = $conn->query("SELECT id FROM blocks WHERE (blocker_id=$user_id AND blocked_id=$poster_id) OR (blocker_id=$poster_id AND blocked_id=$user_id)");
            if($blk && $blk->num_rows > 0){ echo json_encode(['ok'=>false]); exit; }
        }

        // private profile gate
        if($poster_id != $user_id){
            $ps = $conn->query("SELECT is_fully_private FROM user_settings WHERE user_id=$poster_id");
            $is_private = $ps && ($r = $ps->fetch_assoc()) && !empty($r['is_fully_private']);
            if($is_private){
                $approved = false;
                if($user_id){
                    $ap = $conn->query("SELECT id FROM track_requests WHERE requester_id=$user_id AND target_id=$poster_id AND status='approved'");
                    $approved = $ap && $ap->num_rows > 0;
                }
                if(!$approved){ echo json_encode(['ok'=>false]); exit; }
            }
        }

        // adult-content gate (mirrors main feed WHERE)
        if(!empty($row['is_adult'])){
            $ok_adult = $user_id && $conn->query("
                SELECT 1 FROM group_posts gp JOIN group_members gm ON gm.group_id=gp.group_id
                WHERE gp.meme_id=$target_id AND gm.user_id=$user_id
            ")->num_rows > 0;
            if(!$ok_adult){ echo json_encode(['ok'=>false]); exit; }
        }

        // media items
        $media_items = [[ 'file' => 'uploads/'.$row['file_name'], 'type' => $row['type'] ]];
        $mm = $conn->query("SELECT file_name, type FROM meme_media WHERE meme_id=$target_id ORDER BY position ASC");
        if($mm) while($m = $mm->fetch_assoc()) $media_items[] = ['file'=>'uploads/'.$m['file_name'], 'type'=>$m['type']];

        // group info
        $group_info = null;
        $gp = $conn->query("SELECT gp.group_id, g.title AS group_title FROM group_posts gp JOIN groups g ON g.id=gp.group_id WHERE gp.meme_id=$target_id LIMIT 1");
        if($gp) $group_info = $gp->fetch_assoc();

        // audio info
        $audio_info = null;
        $au = $conn->query("SELECT a.id AS audio_id, a.title, a.artist, a.file_name, ma2.is_original, ma2.music_volume, ma2.video_audio_volume FROM meme_audio ma2 JOIN audios a ON a.id=ma2.audio_id WHERE ma2.meme_id=$target_id LIMIT 1");
        if($au) $audio_info = $au->fetch_assoc();

        // counts
        $like_count   = (int)countRows($conn,"likes",$target_id);
        $repost_count = (int)countRows($conn,"reposts",$target_id);
        $fav_count    = (int)countRows($conn,"favorites",$target_id);
        $share_count  = (int)countRows($conn,"shares",$target_id);

        // viewer interaction state
        $user_liked = $user_reposted = $user_faved = false;
        if($user_id){
            $user_liked    = $conn->query("SELECT id FROM likes WHERE meme_id=$target_id AND user_id=$user_id")->num_rows > 0;
            $user_reposted = $conn->query("SELECT id FROM reposts WHERE meme_id=$target_id AND user_id=$user_id")->num_rows > 0;
            $user_faved    = $conn->query("SELECT id FROM favorites WHERE meme_id=$target_id AND user_id=$user_id")->num_rows > 0;
        }

        // social proof (mutuals-first, capped 4)
        $social_proof = [];
        if($user_id){
            $sp = $conn->query("
                SELECT u.id, u.username, u.profile_pic, 'like' AS action_type
                FROM likes l JOIN users u ON u.id=l.user_id
                JOIN followers f ON f.following_id=u.id
                WHERE l.meme_id=$target_id AND f.follower_id=$user_id AND u.id!=$user_id
                UNION ALL
                SELECT u.id, u.username, u.profile_pic, 'repost' AS action_type
                FROM reposts r JOIN users u ON u.id=r.user_id
                JOIN followers f ON f.following_id=u.id
                WHERE r.meme_id=$target_id AND f.follower_id=$user_id AND u.id!=$user_id
            ");
            $raw = [];
            if($sp) while($s = $sp->fetch_assoc()) $raw[] = $s;
            usort($raw, function($a,$b){ return ($a['action_type']==='repost'?0:1) <=> ($b['action_type']==='repost'?0:1); });
            $social_proof = array_slice($raw, 0, 4);
        }

        echo json_encode(['ok'=>true, 'post'=>[
            'id'          => $target_id,
            'file'        => 'uploads/'.$row['file_name'],
            'type'        => $row['type'],
            'media'       => $media_items,
            'pic'         => $row['profile_pic'],
            'userId'      => (string)$poster_id,
            'username'    => $row['username'],
            'nickname'    => $row['nickname'] ?? '',
            'caption'     => $row['caption'],
            'time'        => $row['created_at'],
            'likeCount'   => $like_count,
            'repostCount' => $repost_count,
            'favCount'    => $fav_count,
            'shareCount'  => $share_count,
            'liked'       => $user_liked,
            'reposted'    => $user_reposted,
            'faved'       => $user_faved,
            'isPartnership' => !empty($row['is_partnership']),
            'partnerBrand'  => $row['partner_brand'] ?? '',
            'isAiGenerated' => !empty($row['is_ai_generated']),
            'isAd'          => !empty($row['is_ad']),
            'adCtaText'     => $row['ad_cta_text'] ?? '',
            'adCtaUrl'      => $row['ad_cta_url'] ?? '',
            'adCtaColor'    => $row['ad_cta_color'] ?? '#FF4C4C',
            'groupId'     => $group_info ? (int)$group_info['group_id'] : null,
            'groupTitle'  => $group_info ? $group_info['group_title'] : null,
            'audioId'     => $audio_info ? (int)$audio_info['audio_id'] : null,
            'audioTitle'  => $audio_info ? $audio_info['title'] : null,
            'audioArtist' => ($audio_info && !empty($audio_info['artist'])) ? $audio_info['artist'] : null,
            'audioFile'   => $audio_info ? 'uploads/'.$audio_info['file_name'] : null,
            'audioIsOriginal'  => (bool)($audio_info && !empty($audio_info['is_original'])),
            'musicVolume'      => $audio_info ? (int)$audio_info['music_volume'] : 80,
            'videoAudioVolume' => $audio_info ? (int)$audio_info['video_audio_volume'] : 40,
            'socialProof' => array_map(function($sp){
                return ['id'=>(int)$sp['id'],'username'=>$sp['username'],'profile_pic'=>$sp['profile_pic'],'action_type'=>$sp['action_type']];
            }, $social_proof),
            'connectedMemeId' => !empty($row['connected_meme_id']) ? (int)$row['connected_meme_id'] : null,
            'connectedLabel'  => $row['connected_label'] ?? null,
        ]]);
        exit;
    }
}

/* ================= NOT INTERESTED (memes) ================= */
if (isset($_POST['action']) && $_POST['action'] === 'not_interested') {
    header('Content-Type: application/json');
    $meme_id = (int)($_POST['meme_id'] ?? 0);
    if ($meme_id && $current_user) {
        $stmt = $conn->prepare("INSERT IGNORE INTO user_interactions (user_id, meme_id, interaction_type) VALUES (?, ?, 'view')");
        $stmt->bind_param("ii", $current_user, $meme_id);
        $stmt->execute();

        $stmt = $conn->prepare("UPDATE memes SET trend_velocity = trend_velocity * 0.5 WHERE id = ?");
        $stmt->bind_param("i", $meme_id);
        $stmt->execute();
    }
    echo json_encode(['status' => 'ok']);
    exit;
}

/* ================= REPORT MEME ================= */
if (isset($_POST['action']) && $_POST['action'] === 'report_meme') {
    header('Content-Type: application/json');
    $meme_id = (int)($_POST['meme_id'] ?? 0);
    $reason  = trim($_POST['reason'] ?? 'No reason given');
    if ($meme_id && $current_user) {
        $stmt = $conn->prepare("SELECT uploaded_by FROM memes WHERE id=?");
        $stmt->bind_param("i", $meme_id);
        $stmt->execute();
        $owner = $stmt->get_result()->fetch_assoc()['uploaded_by'] ?? 0;

        if ($owner) {
            $stmt = $conn->prepare("INSERT INTO reports (reporter_id, reported_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $current_user, $owner);
            $stmt->execute();
        }
    }
    echo json_encode(['status' => 'ok']);
    exit;
}

/* ================= PRE-QUERY DATA (BLOCKS, MUTES & AI PERSONALIZATION) ================= */
$blocked_users = [];
$muted_users   = [];
$muted_group_meme_ids = [];
$user_top_tags = [];
$user_tag_weights = [];

if($user_id){

    /* ── BLOCKED USERS ── */
    $stmt = $conn->prepare("SELECT blocked_id FROM blocks WHERE blocker_id=?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while($r = $res->fetch_assoc()){ $blocked_users[] = (int)$r['blocked_id']; }

    /* ── MUTED USERS ── */
    $stmt = $conn->prepare("SELECT muted_id FROM muted_users WHERE muter_id=?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if($res) while($r = $res->fetch_assoc()){ $muted_users[] = (int)$r['muted_id']; }

    /* ── MUTED GROUPS — collect meme IDs posted in groups the user has muted ── */
    $stmt = $conn->prepare("
        SELECT DISTINCT gp.meme_id
        FROM muted_groups mg
        JOIN group_posts gp ON gp.group_id = mg.group_id
        WHERE mg.user_id = ?
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $muted_group_meme_ids[] = (int)$r['meme_id'];
        }
    }

    /* ===============================================================
       ✨ DEZZME AI PERSONALIZATION ENGINE
       Reuses the shared getUserTopTags() helper defined at the top
       of this file.
    =============================================================== */
    list($user_top_tags, $user_tag_weights) = getUserTopTags($conn, $user_id);
}

/* ================= FILTER LOGIC =================
   RAND() removed from every branch. Replaced with a per-request
   jitter seeded once via mt_rand() so "random-looking" filters
   (following/discover) and the trending formula's tiebreaker still
   shuffle differently each page load, without forcing MySQL to
   materialize + sort the whole candidate set the way RAND() does.
================================================================= */
$filter = $_GET['filter'] ?? 'trending';
$tag    = $_GET['tag']    ?? 'all';

$feed_seed = mt_rand(1, 104729); // any large prime works; reused below in FINAL ORDERING too

switch($filter){
    case 'most_liked':    $order = "like_count DESC"; break;
    case 'most_reposted': $order = "repost_count DESC"; break;
    case 'most_favorited':$order = "fav_count DESC"; break;
    case 'most_shared':   $order = "share_count DESC"; break;
    case 'most_viewed':   $order = "memes.views DESC"; break;
    case 'newest':        $order = "memes.created_at DESC"; break;
    case 'following':     $order = "((memes.id * $feed_seed) % 104729)"; break;
    case 'discover':      $order = "((memes.id * $feed_seed) % 104729)"; break;
    default:
        $order = "(((like_count * 4.0) + (repost_count * 6.0) + (fav_count * 3.0) + (share_count * 5.0) + (LOG10(memes.views + 1) * 2.0)) / POW(TIMESTAMPDIFF(HOUR, memes.created_at, NOW()) + 2, 1.5)) + ((((memes.id * $feed_seed) % 104729) / 104729.0) * 0.5) DESC";
        break;
}

/* ================= QUERY BUILDER =================
   The 9 correlated subqueries are LEFT JOIN aggregates/lookups,
   computed once per query instead of once per row.
================================================================= */
$sql = "SELECT memes.*, users.username, users.nickname, users.profile_pic,

    COALESCE(like_agg.cnt, 0)    AS like_count,
    COALESCE(repost_agg.cnt, 0)  AS repost_count,
    COALESCE(fav_agg.cnt, 0)     AS fav_count,
    COALESCE(share_agg.cnt, 0)   AS share_count,

    CASE WHEN user_like.id     IS NOT NULL THEN 1 ELSE 0 END AS user_liked,
    CASE WHEN user_repost.id   IS NOT NULL THEN 1 ELSE 0 END AS user_reposted,
    CASE WHEN user_fav.id      IS NOT NULL THEN 1 ELSE 0 END AS user_fav,
    CASE WHEN user_share.id    IS NOT NULL THEN 1 ELSE 0 END AS user_shared,

    CASE WHEN follow_check.id  IS NOT NULL THEN 1 ELSE 0 END AS is_following,

    ma.cta_text  AS ad_cta_text,
    ma.cta_url   AS ad_cta_url,
    ma.cta_color AS ad_cta_color

    FROM memes
    JOIN users ON memes.uploaded_by = users.id

    LEFT JOIN (SELECT meme_id, COUNT(*) AS cnt FROM likes     GROUP BY meme_id) like_agg    ON like_agg.meme_id    = memes.id
    LEFT JOIN (SELECT meme_id, COUNT(*) AS cnt FROM reposts   GROUP BY meme_id) repost_agg  ON repost_agg.meme_id  = memes.id
    LEFT JOIN (SELECT meme_id, COUNT(*) AS cnt FROM favorites GROUP BY meme_id) fav_agg     ON fav_agg.meme_id     = memes.id
    LEFT JOIN (SELECT meme_id, COUNT(*) AS cnt FROM shares    GROUP BY meme_id) share_agg   ON share_agg.meme_id   = memes.id

    LEFT JOIN likes     user_like   ON user_like.meme_id   = memes.id AND user_like.user_id   = ?
    LEFT JOIN reposts   user_repost ON user_repost.meme_id = memes.id AND user_repost.user_id = ?
    LEFT JOIN favorites user_fav    ON user_fav.meme_id    = memes.id AND user_fav.user_id    = ?
    LEFT JOIN shares    user_share  ON user_share.meme_id  = memes.id AND user_share.user_id  = ?
    LEFT JOIN followers follow_check ON follow_check.follower_id = ? AND follow_check.following_id = memes.uploaded_by
    LEFT JOIN meme_ads   ma ON ma.meme_id = memes.id";

$params = [$user_id, $user_id, $user_id, $user_id, $user_id];
$types  = "iiiii";

$where  = " WHERE 1=1 ";

if($tag !== 'all'){
    $where   .= " AND memes.caption LIKE ? ";
    $params[] = "%#$tag%";
    $types   .= "s";
}

if(!empty($blocked_users)){
    $in      = implode(',', array_fill(0, count($blocked_users), '?'));
    $where  .= " AND memes.uploaded_by NOT IN ($in) ";
    $params  = array_merge($params, $blocked_users);
    $types  .= str_repeat('i', count($blocked_users));
}

if(!empty($muted_users)){
    $in      = implode(',', array_fill(0, count($muted_users), '?'));
    $where  .= " AND memes.uploaded_by NOT IN ($in) ";
    $params  = array_merge($params, $muted_users);
    $types  .= str_repeat('i', count($muted_users));
}

if (!empty($muted_group_meme_ids)) {
    $in      = implode(',', array_fill(0, count($muted_group_meme_ids), '?'));
    $where  .= " AND memes.id NOT IN ($in) ";
    $params  = array_merge($params, $muted_group_meme_ids);
    $types  .= str_repeat('i', count($muted_group_meme_ids));
}

$where   .= " AND (
    memes.is_adult = 0
    OR EXISTS (
        SELECT 1 FROM group_posts gp
        JOIN group_members gm ON gm.group_id = gp.group_id
        WHERE gp.meme_id = memes.id
        AND gm.user_id = ?
    )
) ";
$params[] = $user_id;
$types   .= "i";

$where   .= " AND (memes.is_ad = 0 OR ma.status = 'approved') ";

if($filter === 'following' && $user_id){
    $where   .= " AND memes.uploaded_by IN (SELECT following_id FROM followers WHERE follower_id=?) ";
    $params[] = $user_id;
    $types   .= "i";
}

$final_order = " ORDER BY $order";

if($filter === 'trending' && !empty($user_top_tags)){
    $boost_cases = [];
    foreach($user_top_tags as $t){
        $weight        = $user_tag_weights[$t] ?? 1.0;
        $multiplier    = min(2.5, round(1.0 + ($weight / 20.0), 3));
        $safe_tag      = $conn->real_escape_string($t);
        $boost_cases[] = "WHEN memes.caption LIKE '%#$safe_tag%' THEN $multiplier";
    }

    $case_sql = "CASE " . implode(' ', $boost_cases) . " ELSE 1.0 END";

    $final_order = " ORDER BY (
        (((like_count * 4.0) + (repost_count * 6.0) + (fav_count * 3.0) + (share_count * 5.0) + (LOG10(memes.views + 1) * 2.0))
        / POW(TIMESTAMPDIFF(HOUR, memes.created_at, NOW()) + 2, 1.5))
        + ((((memes.id * $feed_seed) % 104729) / 104729.0) * 0.5)
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

if($user_id && !empty($meme_ids)){
    $id_list = implode(',', array_map('intval', $meme_ids));
    $stmt = $conn->prepare("
        SELECT l.meme_id, u.id, u.username, u.profile_pic, 'like' AS action_type
        FROM likes l
        JOIN users u ON u.id = l.user_id
        JOIN followers f ON f.following_id = u.id
        WHERE l.meme_id IN ($id_list) AND f.follower_id = ? AND u.id != ?

        UNION ALL

        SELECT r.meme_id, u.id, u.username, u.profile_pic, 'repost' AS action_type
        FROM reposts r
        JOIN users u ON u.id = r.user_id
        JOIN followers f ON f.following_id = u.id
        WHERE r.meme_id IN ($id_list) AND f.follower_id = ? AND u.id != ?
    ");
    $stmt->bind_param("iiii", $user_id, $user_id, $user_id, $user_id);
    $stmt->execute();
    $sp_result = $stmt->get_result();

    // collect ALL rows per meme first — no 4-cap here yet
    $_social_proof_raw = [];
    while($sp = $sp_result->fetch_assoc()){
        $mid = $sp['meme_id'];
        if(!isset($_social_proof_raw[$mid])) $_social_proof_raw[$mid] = [];
        $_social_proof_raw[$mid][] = $sp;
    }

    // sort reposts to the front, THEN take the top 4 —
    // reposts fill the strip first, likes only pad leftover slots
    foreach($_social_proof_raw as $mid => $rows){
        usort($rows, function($a, $b){
            $aRank = $a['action_type'] === 'repost' ? 0 : 1;
            $bRank = $b['action_type'] === 'repost' ? 0 : 1;
            return $aRank <=> $bRank;
        });
        $social_proof_map[$mid] = array_slice($rows, 0, 4);
    }
}

// Rename so feed HTML still uses $memes
$memes = $all_rows;

/* ================= FOLLOW LIST ================= */
$following = null;

if ($user_id) {
    $stmt = $conn->prepare("
        SELECT u.id, u.username, u.profile_pic 
        FROM followers f 
        JOIN users u ON f.following_id = u.id 
        WHERE f.follower_id = ?
        ORDER BY f.id DESC
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $following = $stmt->get_result();
}

if (!empty($_POST['mark_viewed']) && $user_id) {
    $mid = intval($_POST['mark_viewed']);
    $stmt = $conn->prepare("
        INSERT IGNORE INTO user_interactions 
        (user_id, meme_id, interaction_type) 
        VALUES (?, ?, 'view')
    ");
    $stmt->bind_param("ii", $user_id, $mid);
    $stmt->execute();
    echo "ok";
    exit();
}
?>
<?php
// ── Total unread messages across all linked accounts — FIX #5 ──
$total_unread = 0;
if (!empty($_SESSION['accounts'])) {
    foreach ($_SESSION['accounts'] as $acc_id) {
        $acc_id = (int)$acc_id;
        $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM messages WHERE receiver_id = ? AND seen = 0");
        $stmt->bind_param("i", $acc_id);
        $stmt->execute();
        $unread = $stmt->get_result()->fetch_assoc()['cnt'] ?? 0;
        $total_unread += $unread;
    }
}

$all_tags_raw = ['All','Funny','Me','Text','Memes','Gaming','Love','School','Anime','Music','Movies','Sports','Football','Dank','DarkHumor','Wholesome','Cursed','Cringe','Sus','Bruh','LOL','ROFL','Template','Classic','OldButGold','Reaction','Mood','Same','Unexpected','PlotTwist','NoContext','ExplainThis','JustWhy','ImDone','RelatableAF','TooReal','BigMood','Pain','Dead','ICant','SendHelp','ThisAintIt','ItBeLikeThat','WhyTho','LowQuality','HighQuality','DeepFried','Brainrot','NPC','SideEye','Mid','W','L','Rare','Legendary','Throwback'];
$sorted_tags  = ['All'];
$lower_top    = array_map('strtolower', $user_top_tags);
foreach($all_tags_raw as $t){ if($t==='All') continue; if(in_array(strtolower($t),$lower_top)) $sorted_tags[]=$t; }
foreach($all_tags_raw as $t){ if($t==='All') continue; if(!in_array(strtolower($t),$lower_top)) $sorted_tags[]=$t; }
?>
<!DOCTYPE html>
<html>
<head>

<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/Peura/service-worker.js')
            .then(reg => console.log('Peura SW registered'))
            .catch(err => console.log('SW error:', err));
    });
}
</script>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta charset="UTF-8">
<title>Dezzme | Homepage</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<style>

*{margin:0;padding:0;box-sizing:border-box;font-family:Poppins;}
button,a{cursor:pointer;outline:none;border:none;background:none;}

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


/* GLASS EFFECT */
.glass {
    background: transparent;
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    border: transparent;
    border-radius: 30px;
    transition: .35s;
    box-shadow:0 4px 25px rgba(0,0,0,0.4);
}

/* NAVBAR (Default Desktop View) */
nav {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 40px;
    position: sticky;
    top: 0;
    z-index: 100;
    transition: all 0.4s ease;
}

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

/* Desktop: show icon, hide profile pic in nav */
.nav-profile-pic {
    display: none;
}

/* 📱 PHONE VIEW (Instagram-Style Tab Bar) */
@media (max-width: 768px) {

    .glass {
        background: rgba(3, 4, 21, 0.97);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        border: transparent;
        border-radius: 11px;
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
        gap: 7px;
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

    /* Shine removed */
    .nav-links a::before {
        display: none;
    }

    /* Bolder icons */
    .nav-links a i {
        font-size: 24px;
        color: #fff;
        font-weight: 900;
        -webkit-text-stroke: 0.5px #fff;
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

    /* Essential: Push content up so bar doesn't cover it */
    body {
        padding-bottom: 70px;
    }

    /* ── Profile nav: hide icon, show picture ── */
    .nav-profile-icon {
        display: none !important;
    }

    .nav-profile-pic {
        display: block !important;
        width: 24px;
        height: 24px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid #ffffff;
    }

    /* Essential: Push content up so bar doesn't cover it */
    body {
        padding-bottom: 70px;
    }
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

/* Optional: shine sweep for extra polish */
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

/* Keep your existing animations... */
@keyframes floatUpDown {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-5px); }
}

.follow-wrap {
    position: relative;
    margin-top: clamp(25px, 6vw, 80px);
}
.follow-bar {
    display: flex;
    overflow-x: auto;
    padding: clamp(10px,2vw,15px) clamp(36px,6vw,52px);
    gap: clamp(15px,3vw,25px);
    flex-wrap: nowrap;
    scrollbar-width: none;
    -ms-overflow-style: none;
    -webkit-overflow-scrolling: touch;
    position: relative;
    top: 10px;
    scroll-behavior: smooth;
}
.follow-bar::-webkit-scrollbar { display: none; }

/* arrows */
.fb-arrow {
    position: absolute;
    top: 50%; transform: translateY(-50%);
    z-index: 10;
    width: 32px; height: 32px;
    border-radius: 50%;
    background: rgba(0,0,0,0.7);
    border: 1.5px solid rgba(255,255,255,0.25);
    color: white;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer;
    opacity: 0; pointer-events: none;
    transition: opacity 0.25s ease, background 0.2s ease;
    font-size: 20px; line-height: 1;
}
.fb-arrow-left  { left: 4px; }
.fb-arrow-right { right: 4px; }
.fb-arrow:hover { background: rgba(255,45,45,0.85); }

/* show on desktop hover OR touch-triggered class */
.follow-wrap:hover .fb-arrow,
.follow-wrap.show-arrows .fb-arrow {
    opacity: 1; pointer-events: auto;
}
.fb-arrow.hidden { opacity: 0 !important; pointer-events: none !important; }

/* badge */
.fb-badge {
    position: absolute; top: 0; right: 0;
    width: 18px; height: 18px;
    background: #000;
    border-radius: 50%;
    border: 2px solid #06071A;
    display: flex; align-items: center; justify-content: center;
    z-index: 2; font-size: 10px; color: #FF2D2D;
}

/* rest stays same */
.follow-user { text-align: center; flex-shrink: 0; }
.follow-user img {
    width: clamp(65px,11vw,70px); height: clamp(65px,11vw,70px);
    border-radius: 50%; object-fit: cover;
    border: 2px solid red;
    transition: transform 0.4s ease, filter 0.4s ease;
    filter: drop-shadow(0 4px 12px rgba(0,0,0,0.4));
    display: block; margin: 0 auto;
}
.follow-user img:hover { transform: scale(1.15) rotate(-2deg); cursor: pointer; }
.follow-user:hover { transform: translateY(-4px); }
.follow-user p { font-size: clamp(11px,2.5vw,14px); color: white; margin-top: 5px; }

/* ─── FEED GRID ──────────────────────────────────────────── */
.feed {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(280px, 100%), 1fr));
    gap: clamp(14px, 3vw, 28px);
    padding: clamp(16px, 4vw, 40px);
    max-width: 1200px;
    margin: auto;
}

@media (min-width: 1024px) {
    .feed {
        grid-template-columns: repeat(3, 1fr);
    }
}

/* ─── MEDIA CARD ─────────────────────────────────────────── */
.card {
    position: relative;
    border-radius: 25px;
    cursor: pointer;
    transition: .35s;
}

.card:hover {
    transform: translateY(-3px) scale(1.03);
}

.card img,
.card video {
    width: 100%;
    height: clamp(200px, 40vw, 320px);
    object-fit: cover;
    border-radius: 20px;
    transition: transform 0.4s ease, filter 0.4s ease;
    display: block;
}

/* ─── TOP BUTTONS ────────────────────────────────────────── */
.top-buttons {
    position: absolute;
    top: 12px;
    left: 12px;
    right: 12px;
    display: flex;
    justify-content: space-between;
    gap: 20px;
    z-index: 5;
    flex-wrap: wrap;
}

.top-buttons button {
    padding: clamp(4px, 1vw, 6px) clamp(8px, 2vw, 12px);
    font-size: clamp(10px, 2.5vw, 12px);
    border-radius: 20px;
    background: rgba(62, 46, 237, 0.15);
    color: white;
    transition: .35s;
    min-height: 32px;
    -webkit-tap-highlight-color: transparent;
}

.top-buttons button:hover {
    box-shadow: 0 0 25px white;
}

/* ─── USER BAR ───────────────────────────────────────────── */
/* ─── USER BAR ───────────────────────────────────────────── */
.user-bar {
    position: absolute;
    bottom: 10px;
    left: 50%;
    transform: translateX(-50%);
    width: calc(100% - clamp(16px, 4vw, 60px));
    display: flex;
    align-items: center;
    gap: clamp(5px, 1.5vw, 10px);
    padding: clamp(4px, 1vw, 8px) clamp(8px, 2vw, 12px);
    border-radius: clamp(10px, 2vw, 15px);
    background: rgba(14, 10, 239, 0.15);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    z-index: 3;
    overflow: hidden;
    min-width: 0;
}

.user-bar img {
    width: clamp(22px, 5vw, 38px);
    height: clamp(22px, 5vw, 38px);
    min-width: clamp(22px, 5vw, 38px);
    min-height: clamp(22px, 5vw, 38px);
    aspect-ratio: 1 / 1;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid red;
    display: block;
    flex-shrink: 0;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.user-bar img:hover {
    transform: scale(1.08);
    box-shadow: 0 0 10px rgba(255,0,0,0.6);
}

.user-bar span {
    display: flex;
    align-items: center;
    gap: clamp(4px, 1vw, 8px);
    color: white;
    font-weight: 500;
    font-size: clamp(0.6rem, 2vw, 0.85rem);
    flex-wrap: nowrap;
    min-width: 0;
    flex: 1;
    overflow: hidden;
}

.user-bar a {
    color: white;
    font-weight: 600;
    text-decoration: none;
    font-size: clamp(0.6rem, 2vw, 0.85rem);
    white-space: nowrap;
    flex-shrink: 0;
}

.user-bar a:hover {
    text-decoration: underline;
}

.user-bar .caption {
    color: white;
    font-weight: 400;
    font-size: clamp(0.6rem, 2vw, 0.85rem);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    min-width: 0;
    flex: 1;
}


/* ─── FLOATING LOGO ──────────────────────────────────────── */
.bottom-logo {
    position: fixed;
    bottom: 20px;
    right: 20px;
    width: clamp(40px, 8vw, 50px);
    height: clamp(40px, 8vw, 50px);
    border-radius: 12px;
    cursor: pointer;
    transition: .3s;
    animation: floatUpDown 2s ease-in-out infinite;
    z-index: 99;
}

.bottom-logo:hover {
    box-shadow: 0 0 15px white;
    transform: scale(1.1);
}

/* ─── PARTICLES ──────────────────────────────────────────── */
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
    0%   { transform: translate(0, 0) scale(1); opacity: 0; }
    10%  { opacity: 1; }
    50%  { transform: translate(var(--x-move), var(--y-move)) scale(1.2); }
    100% { transform: translate(calc(var(--x-move) * -1), calc(var(--y-move) * -1)) scale(0.8); opacity: 0; }
}

/* ─── TRACK / FOLLOW BUTTON ──────────────────────────────── */
.track-btn {
    margin-left: 8px;
    margin-top: 2px;
    padding: 4px 10px;
    border-radius: 20px;
    border: none;
    font-size: clamp(11px, 2.5vw, 12px);
    font-weight: 600;
    background: rgba(255, 43, 43, 0.51);
    color: white;
    cursor: pointer;
    transition: 0.3s;
    z-index: 999;
    min-height: 32px;
    -webkit-tap-highlight-color: transparent;
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

/* ─── ACTION BUTTONS ─────────────────────────────────────── */
.action-btn i {
    color: white;
    transition: 0.25s ease;
}

.action-btn.active i {
    color: #ff2a2a;
}

.action-btn:hover i {
    transform: scale(1.1);
}

.action-btn {
    transition: 0.2s ease;
    min-width: 44px;
    min-height: 44px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    -webkit-tap-highlight-color: transparent;
}

.action-btn.active {
    transform: scale(1.05);
}

/* ─── AD ROW ─────────────────────────────────────────────── */
.ad-row {
    grid-column: 1 / -1;
    display: flex;
    gap: clamp(15px, 3vw, 50px);
    overflow-x: auto;
    padding: clamp(10px, 2vw, 15px) clamp(5px, 1vw, 10px);
    margin: 10px 0 30px;
    scroll-snap-type: x mandatory;
    scrollbar-width: none;
    -ms-overflow-style: none;
    -webkit-overflow-scrolling: touch;
    white-space: nowrap;
}

.ad-row::-webkit-scrollbar {
    display: none;
}

/* ─── MINI AD CARD ───────────────────────────────────────── */
.ad-card-mini {
    min-width: clamp(220px, 70vw, 280px);
    max-width: clamp(220px, 70vw, 280px);
    background: rgba(255,255,255,0.05) !important;
    border-radius: 18px;
    padding: 12px;
    flex-shrink: 0;
    scroll-snap-align: start;
    transition: all 0.3s ease;
    cursor: pointer;
    position: relative;
    overflow: hidden;
    box-shadow: none !important;
    -webkit-tap-highlight-color: transparent;
}

.ad-card-mini:hover,
.ad-card-mini:active,
.ad-card-mini:focus {
    background: rgba(255,255,255,0.05) !important;
    box-shadow: none !important;
    transform: none !important;
    transition: none !important;
}

.ad-card-mini::before,
.ad-card-mini::after {
    display: none !important;
    content: none !important;
    animation: none !important;
}

/* ─── AD CARD HEADER ─────────────────────────────────────── */
.card-header {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
}

.brand-logo {
    width: 30px;
    height: 30px;
    border: 2px solid red;
    border-radius: 50%;
    flex-shrink: 0;
}

.brand-name {
    font-size: clamp(0.75rem, 2.5vw, 0.85rem);
    font-weight: 600;
    color: white;
}

.tier-tag {
    font-size: clamp(0.65rem, 2vw, 0.72rem);
    color: rgba(255,255,255,0.6);
}

/* ─── AD MEDIA BOX ───────────────────────────────────────── */
.media-box {
    width: 100%;
    height: clamp(130px, 25vw, 180px);
    border-radius: 12px;
    overflow: hidden;
    margin-bottom: 8px;
    position: relative;
    border: 2px solid rgba(255, 0, 0, 0.7);
    transition: 0.3s ease;
}

.ad-card-mini:hover .media-box {
    border-color: #ff2a2a;
    box-shadow: 0 0 12px rgba(255, 0, 0, 0.5);
}

.media-box img,
.media-box video {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

/* ─── AD CAPTION ─────────────────────────────────────────── */
.caption {
    font-size: clamp(0.7rem, 2vw, 0.75rem);
    margin-bottom: 8px;
    white-space: normal;
    color: rgba(255,255,255,0.85);
}

/* ─── CTA BUTTON ─────────────────────────────────────────── */
.cta-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    padding: 10px 0;
    background: rgba(255,255,255,0.08) !important;
    border-radius: 12px;
    text-decoration: none;
    color: white !important;
    font-weight: 600;
    font-size: clamp(0.72rem, 2vw, 0.75rem);
    margin-top: auto;
    transition: none !important;
    border: 1px solid rgba(255,255,255,0.1);
    outline: none !important;
    -webkit-tap-highlight-color: transparent;
    text-align: center;
}

.ad-card-mini .cta-btn:hover,
.ad-card-mini .cta-btn:active,
.ad-card-mini .cta-btn:focus {
    background: rgba(255,255,255,0.08) !important;
    box-shadow: none !important;
    transform: none !important;
    transition: none !important;
    outline: none !important;
}

/* ─── SUGGESTED ROW ──────────────────────────────────────── */
.suggested-row {
    margin: clamp(10px, 3vw, 20px) 0;
    width: 100%;
    overflow: hidden; /* Prevents the whole page from stretching */
}

.suggested-title {
    color: white;
    font-size: clamp(0.8rem, 2.5vw, 0.9rem);
    margin: 10px 15px;
    opacity: 0.7;
    font-weight: 500;
}

.suggested-scroll {
    display: flex;
    /* CRITICAL: Prevents vertical stacking on mobile */
    flex-wrap: nowrap; 
    overflow-x: auto;
    gap: 15px;
    padding: 10px 15px;
    
    /* Smooth scrolling feel */
    scrollbar-width: none;
    -ms-overflow-style: none;
    scroll-snap-type: x mandatory; /* Makes it feel like a professional app */
    -webkit-overflow-scrolling: touch;
}

.suggested-scroll::-webkit-scrollbar {
    display: none; /* Hides scrollbar on Chrome/Safari */
}

.suggested-user {
    /* Set a consistent width for each user item */
    width: clamp(65px, 18vw, 85px); 
    text-align: center;
    text-decoration: none;
    color: white;
    font-size: clamp(0.65rem, 2vw, 0.75rem);
    flex-shrink: 0; /* Prevents the items from being crushed */
    scroll-snap-align: start; /* Locks to each user as you scroll */
}

.suggested-user img {
    width: clamp(55px, 12vw, 65px);
    height: clamp(55px, 12vw, 65px);
    aspect-ratio: 1/1;
    border-radius: 50%;
    border: 2px solid red;
    object-fit: cover;
    transition: 0.3s;
    display: block;
    margin: 0 auto 8px auto;
}

.suggested-user:hover img {
    transform: scale(1.08);
    box-shadow: 0 0 15px rgba(255, 0, 0, 0.4);
}

.suggested-user span {
    display: block;
    white-space: nowrap; /* Prevents username from breaking into two lines */
    overflow: hidden;
    text-overflow: ellipsis;
    opacity: 0.9;
}

/* 📱 PHONE-SPECIFIC TWEAKS */
@media (max-width: 768px) {
    .suggested-scroll {
        /* Adds a little extra space at the end so the last user isn't cut off */
        padding-right: 30px; 
        gap: 12px;
    }
    
    .suggested-user {
        /* Slightly smaller targets for phone fingers */
        width: 70px;
    }
}

/* ─── TAG SCROLLBAR ──────────────────────────────────────── */
.tag-scrollbar {
    display: flex;
    gap: 10px;
    padding: 10px;
    overflow-x: auto;
    white-space: nowrap;
    scrollbar-width: none;
    -webkit-overflow-scrolling: touch;
}

.tag-scrollbar::-webkit-scrollbar {
    display: none;
}

/* ─── TAG PILLS ──────────────────────────────────────────── */
.tag-pill {
    flex-shrink: 0;
    padding: clamp(8px, 2vw, 10px) clamp(12px, 3vw, 16px);
    border-radius: 999px;
    background: transparent;
    border: 1px solid rgba(255,255,255,0.18);
    color: #ffffff;
    font-size: clamp(0.78rem, 2.5vw, 0.85rem);
    font-weight: 500;
    letter-spacing: 0.3px;
    text-decoration: none;
    margin-top: 10px;
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    box-shadow: 0 4px 18px rgba(0,0,0,0.12);
    transition: all 0.25s ease;
    position: relative;
    overflow: hidden;
    -webkit-tap-highlight-color: transparent;
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
    0%   { opacity: 0.35; transform: scale(1); }
    50%  { opacity: 0.85; transform: scale(1.05); }
    100% { opacity: 0.35; transform: scale(1); }
}


/* ─── NEW TAG STICKER ────────────────────────────────────── */
.peura-new-tag {
    position: absolute;
    bottom: 112px;
    left: 12px;
    background: rgba(255,255,255,0.1);
    backdrop-filter: blur(5px);
    -webkit-backdrop-filter: blur(5px);
    border: 1px solid rgba(255,255,255,0.2);
    border-radius: 4px;
    color: #ffffff;
    font-size: 10px;
    font-weight: 800;
    padding: 2px 8px;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    z-index: 20;
    pointer-events: none;
    box-shadow: inset 0 0 5px rgba(255,255,255,0.05);
    text-shadow: 0 0 4px rgba(255,255,255,0.5);
}

.card.is-new {
    border: 1px solid rgba(0,242,255,0.2) !important;
}

.card.is-fading {
    border: 1px solid rgba(255,255,255,0.1) !important;
}

/* ════════════════════════════════════════════════════════════
   RESPONSIVE BREAKPOINTS
   ════════════════════════════════════════════════════════════ */

/* ─── TABLET (≤ 768px) ───────────────────────────────────── */
@media (max-width: 768px) {

    nav {
        padding: 12px 16px;
        gap: 8px;
    }

    .title {
        font-size: 20px;
        letter-spacing: 3px;
    }


    .follow-bar {
    padding: 10px 16px;
    margin-top: 55px; /* 👈 pushed lower */
}

    .feed {
        grid-template-columns: repeat(2, 1fr);
        gap: 14px;
        padding: 16px;
    }

    .card img,
    .card video {
        height: clamp(160px, 35vw, 240px);
    }

    .user-bar {
        left: 50%;
        width: calc(100% - 24px);
    }

    .modal-media img,
    .modal-media video {
        max-width: 100%;
        width: 100%;
    }

    .messages {
        bottom: 15px;
        left: 15px;
        padding: 9px 14px;
        font-size: 0.78rem;
    }

    .bottom-logo {
        bottom: 15px;
        right: 15px;
        width: 42px;
        height: 42px;
    }
    
}

/* ─── MOBILE (≤ 480px) ───────────────────────────────────── */
@media (max-width: 480px) {

    nav {
        padding: 10px 12px;
    }

    .title {
        font-size: 16px;
        letter-spacing: 2px;
    }

    .logo-container img {
        width: 38px;
    }


    .feed {
        grid-template-columns: 1fr;
        gap: 12px;
        padding: 12px;
    }

    .card img,
    .card video {
        height: clamp(220px, 60vw, 300px);
    }

    .follow-bar {
    padding: 10px 12px;
    margin-top: 65px; /* 👈 even lower on phone */
    gap: 14px;
}

    .follow-user img {
    width: clamp(60px, 15vw, 82px);
    height: clamp(60px, 15vw, 82px);
}

    .follow-user p {
        font-size: 11px;
    }

    .ad-card-mini {
        min-width: clamp(200px, 78vw, 240px);
        max-width: clamp(200px, 78vw, 240px);
    }

    .modal-info {
        padding: 8px 12px;
        border-radius: 14px;
    }

    .close-modal {
        top: 8px;
        right: 8px;
        padding: 8px 11px;
    }

    .tag-pill {
        padding: 7px 12px;
        font-size: 0.78rem;
    }

    .messages {
        font-size: 0.72rem;
        padding: 8px 12px;
        bottom: 12px;
        left: 12px;
    }

    .bottom-logo {
        width: 38px;
        height: 38px;
        bottom: 12px;
        right: 12px;
    }
}

/* ─── VERY SMALL (≤ 360px) ───────────────────────────────── */
@media (max-width: 360px) {

    .title {
        font-size: 14px;
        letter-spacing: 1.5px;
    }


    .feed {
        padding: 10px;
        gap: 10px;
    }
}

@media (max-width: 1024px) {
    .nav-icon[href="sponsorships.php"] {
        display: none !important;
    }
}

/* ─── TABLET (≤ 768px) ───────────────────────── */
@media (max-width: 768px) {

    .tag-pill {
        padding: 7px 12px;     /* was 6px 10px */
        font-size: 0.78rem;    /* slightly bigger */
        margin-top: 6px;
    }
}

/* ─── MOBILE (≤ 480px) ───────────────────────── */
@media (max-width: 480px) {

    .tag-pill {
        padding: 6px 11px;     /* was 5px 9px */
        font-size: 0.74rem;    /* slightly bigger */
        margin-top: 5px;
        letter-spacing: 0.2px;
    }
}

/* ─── VERY SMALL (≤ 360px) ───────────────────── */
@media (max-width: 360px) {

    .tag-pill {
        padding: 5px 10px;     /* was 4px 8px */
        font-size: 0.7rem;     /* slightly bigger */
    }
}

.social-proof-strip {
    position: absolute;
    bottom: 71px;
    left: 10px;
    display: flex;
    flex-direction: row;
    z-index: 3;
    pointer-events: none;
    gap:7px;
}

.sp-avatar-wrap {
    position: relative;
    pointer-events: all;
    display: inline-block;
    text-decoration: none;
    margin-right: 6px;
}

.sp-avatar-wrap:nth-child(1) { animation: spFloat 3s ease-in-out infinite; }
.sp-avatar-wrap:nth-child(2) { animation: spFloat 3s ease-in-out infinite 0.4s; }
.sp-avatar-wrap:nth-child(3) { animation: spFloat 3s ease-in-out infinite 0.8s; }
.sp-avatar-wrap:nth-child(4) { animation: spFloat 3s ease-in-out infinite 1.2s; }

@keyframes spFloat {
    0%   { transform: translateY(0px); }
    50%  { transform: translateY(-6px); }
    100% { transform: translateY(0px); }
}

.social-proof-strip .sp-avatar {
    width: 38px !important;
    height: 38px !important;
    min-width: 38px !important;
    max-width: 38px !important;
    min-height: 38px !important;
    max-height: 38px !important;
    border-radius: 50% !important;
    object-fit: cover !important;
    border: 2px solid red !important;
    display: block !important;
    box-shadow: 0 4px 12px rgba(0,0,0,0.7);
    flex-shrink: 0 !important;
}

.sp-action-badge {
    position: absolute;
    bottom: -2px;
    right: -2px;
    width: 15px;
    height: 15px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    border: transparent 1.5px solid;
    z-index: 21;
}

.sp-action-badge i {
    font-size: 7px;
    color: white;
    line-height: 1;
}

/* ── TABLET (max 768px) ── */
@media (max-width: 768px) {
    .social-proof-strip {
        bottom: 56px;
        left: 8px;
    }

    .sp-avatar-wrap {
        margin-right: 5px;
    }

    .social-proof-strip .sp-avatar {
        width: 36px !important;
        height: 36px !important;
        min-width: 36px !important;
        max-width: 36px !important;
        min-height: 36px !important;
        max-height: 36px !important;
        border: 2px solid red !important;
    }

    .sp-action-badge {
        width: 14px;
        height: 14px;
        bottom: -2px;
        right: -2px;
        border: transparent 1.5px solid;
    }

    .sp-action-badge i {
        font-size: 6.5px;
    }
}

/* ── PHONE (max 480px) ── */
@media (max-width: 480px) {
    .social-proof-strip {
        bottom: 55px;
        left: 7px;
    }

    .sp-avatar-wrap {
        margin-right: 5px;
    }

    .social-proof-strip .sp-avatar {
        width: 35px !important;
        height: 35px !important;
        min-width: 35px !important;
        max-width: 35px !important;
        min-height: 35px !important;
        max-height: 35px !important;
        border: 2px solid red !important;
        box-shadow: 0 3px 8px rgba(0,0,0,0.7);
    }

    .sp-action-badge {
        width: 13px;
        height: 13px;
        bottom: -2px;
        right: -2px;
        border: transparent 1.5px solid;
    }

    .sp-action-badge i {
        font-size: 6px;
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
    font-size:0.72rem;
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

.feed {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(280px, 100%), 1fr));
    gap: clamp(14px, 3vw, 28px);
    padding: clamp(16px, 4vw, 40px);
    max-width: 1200px;
    margin: auto;
    align-items: start;
}

@media (min-width: 1024px) {
    .feed {
        grid-template-columns: repeat(3, 1fr);
    }
}

.card-unit {
    display: flex;
    flex-direction: column;
}

.eng-row {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 4px;
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
}

.eng-avatars {
    display: flex;
    flex-direction: row;
}

.eng-avatar {
    width: 20px !important;
    height: 20px !important;
    min-width: 20px !important;
    max-width: 20px !important;
    min-height: 20px !important;
    max-height: 20px !important;
    border-radius: 50% !important;
    object-fit: cover !important;
    border: 1.5px solid rgba(255, 255, 255, 0.41) !important;
    margin-right: -5px;
    display: block !important;
    box-shadow: none !important;
}

.eng-avatars .eng-avatar:last-child {
    margin-right: 4px;
}

.eng-text {
    font-size: 0.7rem;
    color: rgba(255,255,255,0.75);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    flex: 1;
}

.eng-text strong {
    color: white;
    font-weight: 600;
}

@media (max-width: 768px) {
    .action-btn {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 4px 8px;
    }

    .action-btn i {
        font-size: 14px;
    }

    .action-btn .count {
        font-size: 11px;
        font-weight: 600;
    }

    .btn-group {
        display: flex;
        flex-direction: row;
        align-items: center;
        gap: 19px;
    }
}

@media (max-width: 480px) {
    .action-btn i {
        font-size: 14px;
    }

    .action-btn .count {
        font-size: 12px;
    }

    .btn-group {
        gap: 17px;
    }
}

@media (max-width: 360px) {
    .action-btn i {
        font-size: 14px;
    }

    .action-btn .count {
        font-size: 11px;
    }

    .btn-group {
        gap: 15px;
    }
}

@media (max-width: 768px) {
    a.nav-icon[href="upload.php"] {
        display: none !important;
    }
}
</style>
</head>
<body>

<script>
(function(){
    const wrap = document.getElementById('followWrap');
    const bar  = document.getElementById('followBar');
    const btnL = document.getElementById('arrowLeft');
    const btnR = document.getElementById('arrowRight');

    function updateArrows(){
        btnL.classList.toggle('hidden', bar.scrollLeft <= 4);
        btnR.classList.toggle('hidden', bar.scrollLeft >= bar.scrollWidth - bar.clientWidth - 4);
    }
    bar.addEventListener('scroll', updateArrows);
    window.addEventListener('resize', updateArrows);
    updateArrows();

    btnL.addEventListener('click', () => bar.scrollLeft -= 160);
    btnR.addEventListener('click', () => bar.scrollLeft += 160);

    let t;
    function showArrows(){
        wrap.classList.add('show-arrows');
        clearTimeout(t);
        t = setTimeout(() => wrap.classList.remove('show-arrows'), 2800);
    }
    bar.querySelectorAll('img').forEach(img => {
        img.addEventListener('touchstart', showArrows, { passive: true });
        img.addEventListener('click', showArrows);
    });
    document.addEventListener('touchstart', e => {
        if(!wrap.contains(e.target)){
            clearTimeout(t);
            wrap.classList.remove('show-arrows');
        }
    }, { passive: true });
})();
</script>

<!-- NAV -->
<nav class="glass">
    <div class="logo-container">
        <img src="Dezzme.jpg" onclick="location.href='welcome.php'">
        <div class="title">Dezzme<span style="font-size:10px; color: #f41010"></span></div>
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

<!-- ══════════════════════════════════════
     HOME FIXED TOPBAR (mobile only)
══════════════════════════════════════ -->
<div class="home-fixed-topbar" id="homeFixedTopbar">

    <button type="button" class="hft-btn hft-menu" onclick="hftOpenSideMenu()" aria-label="Menu">
        <i class="ri-apps-2-line"></i>
    </button>

    <div class="hft-title">Dezzme</div>

    <div class="hft-right">
        <button type="button" class="hft-btn hft-sort" onclick="hftToggleSort(event)" aria-label="Sort feed">
            <i class="ri-menu-3-line"></i>
        </button>

        <div class="hft-sort-dropdown" id="hftSortDropdown">
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

<style>
/* ══════════════════════════════════════
   HOME FIXED TOPBAR — mobile only
   Same bg + height feel as profile's topbar,
   but its own layout: menu / title / sort.
══════════════════════════════════════ */
.home-fixed-topbar{
    display:none;
    position:fixed;
    top:0;left:0;right:0;
    z-index:100000;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    padding:14px 16px;
    background: linear-gradient(-45deg,#070924,#070924);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
}

.hft-btn{
    background:none;
    border:none;
    color:#fff;
    font-size:1.5rem;
    display:flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
    padding:2px;
    opacity:.9;
    transition:opacity .2s, color .2s;
    -webkit-tap-highlight-color:transparent;
    flex-shrink:0;
}
.hft-btn:hover,
.hft-btn:active{ opacity:1; color:#FF2D2D; }

.hft-title{
    flex:1;
    text-align:center;
    font-size:1.4rem;      /* was 1rem */
    font-weight:700;
    color:#fff;
    letter-spacing:3px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

.hft-right{
    display:flex;
    align-items:center;
    gap:14px;
    position:relative;
}

/* ── SORT DROPDOWN ── */
.hft-sort-dropdown{
    display:none;
    position:absolute;
    top:38px;
    right:0;
    background: rgba(255,255,255,0.08);
    backdrop-filter: blur(15px);
    -webkit-backdrop-filter: blur(15px);
    border-radius:12px;
    min-width:190px;
    flex-direction:column;
    z-index:100001;
    box-shadow:0 8px 25px rgba(0,0,0,0.35);
    border:1px solid rgba(255,255,255,0.1);
    overflow:hidden;
}
.hft-sort-dropdown.show{
    display:flex;
    animation:hftFadeDown .25s ease forwards;
}
@keyframes hftFadeDown{
    0%{ opacity:0; transform:translateY(-6px); }
    100%{ opacity:1; transform:translateY(0); }
}
.hft-sort-dropdown a{
    display:flex;
    align-items:center;
    gap:10px;
    padding:12px 16px;
    color:#fff;
    text-decoration:none;
    font-size:13px;
    font-weight:500;
    border-bottom:1px solid rgba(255,255,255,0.06);
    transition:background .2s, color .2s;
}
.hft-sort-dropdown a:last-child{ border-bottom:none; }
.hft-sort-dropdown a i{ color:#ff2a2a; width:16px; text-align:center; }
.hft-sort-dropdown a:hover{ background:rgba(255,255,255,0.08); color:#ff2626; }

/* ── ACTIVATE ON MOBILE, COVER OLD HEADER ROW ── */
@media (max-width:768px){
    .home-fixed-topbar{ display:flex; }
    .kebab-container{ display:none !important; }

    /* push the follow-bar down so it clears the fixed topbar */
    .follow-bar{ margin-top: 60px !important; }
}

@media (max-width:490px){
    .home-fixed-topbar{ padding:12px 14px; }
    .hft-title{ font-size:1.2rem; letter-spacing:2px; }
    .hft-btn{ font-size:1.35rem; }
    .follow-bar{ margin-top: 50px !important; }
}

@media (max-width:380px){
    .home-fixed-topbar{ padding:10px 12px; }
    .hft-title{ font-size:1.05rem; letter-spacing:1.5px; }
    .hft-btn{ font-size:1.25rem; }
    .follow-bar{ margin-top: 50px !important; }
}

@media (max-width:330px){
    .home-fixed-topbar{ padding:9px 10px; }
    .hft-title{ font-size:0.95rem; letter-spacing:1px; }
    .hft-btn{ font-size:1.15rem; }
    .follow-bar{ margin-top: 50px !important; }
}
</style>

<script>
// ── Opens the existing Dezzme side drawer (#dezzmeSideMenu / #dezzmeSideMenuOverlay) ──
function hftOpenSideMenu(){
    var overlay = document.getElementById('dezzmeSideMenuOverlay');
    var panel   = document.getElementById('dezzmeSideMenu');
    if(!overlay || !panel) return;
    overlay.classList.add('show');
    panel.classList.add('show');
    document.body.style.overflow = 'hidden';
    // closeDezzmeSideMenu() (already defined globally in home.php) closes it
}

// ── Sort/filter dropdown toggle ──
function hftToggleSort(e){
    e.stopPropagation();
    var dd = document.getElementById('hftSortDropdown');
    if(dd) dd.classList.toggle('show');
}

document.addEventListener('click', function(){
    var dd = document.getElementById('hftSortDropdown');
    if(dd) dd.classList.remove('show');
});
</script>

<?php
// ── Cross-account notification check ──
$has_cross_notif = false;
if(!empty($_SESSION['accounts'])) {
    // FIX #5: prepared once, re-bound/executed per linked account
    $notif_stmt = $conn->prepare("SELECT 1 FROM messages WHERE receiver_id = ? AND seen = 0 LIMIT 1");
    foreach($_SESSION['accounts'] as $acc_id) {
        if($acc_id == $current_user) continue;
        $acc_id = (int)$acc_id;
        $notif_stmt->bind_param('i', $acc_id);
        $notif_stmt->execute();
        $notif_check = $notif_stmt->get_result();
        if($notif_check && $notif_check->num_rows > 0) {
            $has_cross_notif = true;
            break;
        }
    }
    $notif_stmt->close();
}

// ── Account limit check ──
// FIX #5: prepared statement instead of interpolating $current_user
$link_stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM linked_accounts WHERE primary_user_id = ?");
$nav_current_user = (int)$current_user;
$link_stmt->bind_param('i', $nav_current_user);
$link_stmt->execute();
$linked_count = $link_stmt->get_result()->fetch_assoc()['cnt'] ?? 0;
$link_stmt->close();

$at_limit = ($linked_count >= 4);
?>

<style>
.home-nav-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px clamp(20px, 4vw, 40px);
    position: relative;
}

/* ── LEFT: profile pic + arrow ── */
.home-nav-profile {
    display: flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
    position: relative;
    user-select: none;
    -webkit-tap-highlight-color: transparent;
    flex-shrink: 0;
}

.home-nav-avatar {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #FF2D2D;
    display: block;
    flex-shrink: 0;
    transition: box-shadow 0.2s;
}

.home-nav-profile:hover .home-nav-avatar {
    box-shadow: 0 0 14px rgba(255,45,45,0.5);
}

.home-nav-arrow {
    font-size: 18px;
    color: rgba(255,255,255,0.45);
    transition: transform 0.25s ease;
    line-height: 1;
}

.home-nav-profile.open .home-nav-arrow {
    transform: rotate(180deg);
}

.home-nav-notif-dot {
    position: absolute;
    top: 0;
    left: 26px;
    width: 9px;
    height: 9px;
    background: #FF2D2D;
    border-radius: 50%;
    border: 1.5px solid #06071A;
    pointer-events: none;
}

/* ── MIDDLE: nav links ── */
.home-nav-links {
    display: flex;
    align-items: center;
    gap: clamp(54px, 7vw, 68px);
    flex: 1;
    justify-content: center;
    flex-wrap: nowrap;
}

.home-nav-link {
    font-size: clamp(12px, 1.2vw, 14px);
    font-weight: 500;
    color: rgba(255,255,255,0.65);
    text-decoration: none;
    font-family: Poppins, sans-serif;
    letter-spacing: 0.2px;
    position: relative;
    transition: color 0.2s ease;
    white-space: nowrap;
    -webkit-tap-highlight-color: transparent;
}

.home-nav-link::after {
    content: '';
    position: absolute;
    bottom: -2px;
    left: 0;
    width: 0;
    height: 1.5px;
    background: rgba(255,255,255,0.6);
    border-radius: 999px;
    transition: width 0.25s ease;
}

.home-nav-link:hover {
    color: #ffffff;
}

.home-nav-link:hover::after {
    width: 100%;
}

/* ── RIGHT: Chatbot link ── */
.home-nav-chatbot {
    font-size: clamp(12px, 1.2vw, 14px);
    font-weight: 700;
    color: #FF4C4C;
    text-decoration: none;
    font-family: Poppins, sans-serif;
    letter-spacing: 0.2px;
    position: relative;
    transition: color 0.2s;
    background: none;
    border: none;
    cursor: pointer;
    padding: 0;
    flex-shrink: 0;
    -webkit-tap-highlight-color: transparent;
    white-space: nowrap;
}

.home-nav-chatbot::after {
    content: '';
    position: absolute;
    bottom: -2px;
    left: 0;
    width: 0;
    height: 1.5px;
    background: #FF4C4C;
    border-radius: 999px;
    transition: width 0.25s ease;
}

.home-nav-chatbot:hover {
    color: #ff2020;
}

.home-nav-chatbot:hover::after {
    width: 100%;
}

/* ── DROPDOWN ── */
.home-nav-dropdown {
    display: none;
    position: absolute;
    top: calc(100% + 8px);
    left: 0;
    background: rgba(12,12,28,0.96);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: 16px;
    min-width: 230px;
    box-shadow: 0 12px 40px rgba(0,0,0,0.6);
    z-index: 999999;
    overflow: hidden;
}

.home-nav-dropdown.open {
    display: block;
    animation: hnDropIn 0.2s ease forwards;
}

@keyframes hnDropIn {
    from { opacity:0; transform: translateY(-6px) scale(0.98); }
    to   { opacity:1; transform: translateY(0) scale(1); }
}

.hn-drop-label {
    padding: 8px 14px 6px;
    font-size: 10px;
    color: rgba(255,255,255,0.35);
    letter-spacing: 1px;
    text-transform: uppercase;
    font-family: Poppins, sans-serif;
}

.hn-drop-row {
    display: flex;
    align-items: center;
    position: relative;
}

.hn-drop-link {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 14px;
    text-decoration: none;
    color: white;
    transition: background 0.2s;
    flex: 1;
    min-width: 0;
}

.hn-drop-link:hover {
    background: rgba(255,255,255,0.07);
}

.hn-drop-link.disabled {
    opacity: 0.5;
    cursor: default;
    pointer-events: none;
}

.hn-drop-avatar {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    object-fit: cover;
    border: 1.5px solid #FF2D2D;
    display: block;
    flex-shrink: 0;
}

.hn-drop-name {
    font-size: 13px;
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.hn-drop-sub {
    font-size: 10px;
    margin-top: 1px;
}

.hn-unread-dot {
    position: absolute;
    top: -2px;
    right: -2px;
    width: 9px;
    height: 9px;
    background: #FF2D2D;
    border-radius: 50%;
    border: 1.5px solid rgba(20,20,30,0.95);
}

.hn-unlink-btn {
    padding: 0 12px 0 4px;
    color: rgba(255,255,255,0.3);
    font-size: 16px;
    text-decoration: none;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    opacity: 0;
    transition: opacity 0.2s, color 0.2s;
}

.hn-drop-row:hover .hn-unlink-btn {
    opacity: 1;
}

.hn-unlink-btn:hover {
    color: #FF2D2D !important;
}

.hn-divider {
    height: 1px;
    background: rgba(255,255,255,0.07);
    margin: 4px 0;
}

.hn-add-btn {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 14px;
    text-decoration: none;
    color: rgba(255,255,255,0.75);
    transition: background 0.2s;
    font-family: Poppins, sans-serif;
    background: none;
    border: none;
    width: 100%;
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
}

.hn-add-btn:hover {
    background: rgba(255,255,255,0.07);
}

.hn-add-icon {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    border: 1.5px dashed rgba(255,255,255,0.3);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.hn-add-icon i {
    font-size: 18px;
    color: rgba(255,255,255,0.5);
}

/* ── HIDE ENTIRE BAR ON MOBILE ── */
@media (max-width: 768px) {
    .home-nav-bar {
        display: none !important;
    }
}
</style>

<?php
/* ── FIX #4: batch-fetch linked account info + unread counts once,
   instead of running 2 unprepared queries per linked account inside
   the foreach loop below. For up to 5 linked accounts that used to be
   up to 10 queries per page load — now it's 2 total, regardless of
   how many accounts are linked. ── */
$_linked_acc_map    = [];
$_linked_unread_map = [];

if (!empty($_SESSION['accounts'])) {
    $_acc_ids = array_map('intval', $_SESSION['accounts']);
    $_acc_ids = array_filter($_acc_ids); // drop any falsy/0 ids
    if (!empty($_acc_ids)) {
        $_ids_str = implode(',', $_acc_ids);

        $_ua = $conn->query("SELECT id, username, profile_pic FROM users WHERE id IN ($_ids_str)");
        if ($_ua) while ($_r = $_ua->fetch_assoc()) { $_linked_acc_map[(int)$_r['id']] = $_r; }

        $_uc = $conn->query("SELECT receiver_id, COUNT(*) as cnt FROM messages WHERE receiver_id IN ($_ids_str) AND seen=0 GROUP BY receiver_id");
        if ($_uc) while ($_r = $_uc->fetch_assoc()) { $_linked_unread_map[(int)$_r['receiver_id']] = (int)$_r['cnt']; }
    }
}
?>

<div class="home-nav-bar">

    <!-- LEFT — Profile pic + dropdown arrow -->
    <div class="home-nav-profile" id="homeNavProfile" onclick="toggleHomeNav(event)">

        <div style="display:flex;align-items:center;gap:8px;">
    <a href="profile.php" style="position:relative;display:inline-block;text-decoration:none;">
        <img src="<?= htmlspecialchars($nav_pic) ?>"
             onerror="this.src='uploads/me.jpeg'"
             class="home-nav-avatar"
             alt="<?= htmlspecialchars($nav_user['username']) ?>">
        <?php if($has_cross_notif): ?>
        <span class="home-nav-notif-dot"></span>
        <?php endif; ?>
    </a>
</div>
<span style="font-size:13px;font-weight:600;color:rgba(255,255,255,0.75);">You</span>
        <i class="ri-arrow-down-s-line home-nav-arrow"></i>
        
       <!-- DROPDOWN -->
<div class="home-nav-dropdown" id="homeNavDropdown" onclick="event.stopPropagation()">
    <div class="hn-drop-label">Accounts</div>

    <?php if(!empty($_SESSION['accounts'])): ?>
    <?php foreach($_SESSION['accounts'] as $acc_id):
        $acc_id = (int)$acc_id;
        $acc = $_linked_acc_map[$acc_id] ?? null;
        if(!$acc) continue;
        $is_active  = ($acc_id == $current_user);
        $acc_unread = $_linked_unread_map[$acc_id] ?? 0;
    ?>
    <div class="hn-drop-row"
         onmouseover="var u=this.querySelector('.hn-unlink-btn');if(u)u.style.opacity='1';"
         onmouseout="var u=this.querySelector('.hn-unlink-btn');if(u)u.style.opacity='0';">

        <a href="<?= $is_active ? '#' : 'switch_account.php?user_id='.$acc_id ?>"
           onclick="<?= $is_active ? 'return false;' : '' ?>"
           class="hn-drop-link <?= $is_active ? 'disabled' : '' ?>">

            <div style="position:relative;flex-shrink:0;">
                <img src="<?= !empty($acc['profile_pic']) ? htmlspecialchars($acc['profile_pic']) : 'uploads/me.jpeg' ?>"
                     onerror="this.src='uploads/me.jpeg'"
                     class="hn-drop-avatar">
                <?php if(!$is_active && $acc_unread > 0): ?>
                <span class="hn-unread-dot"></span>
                <?php endif; ?>
            </div>

            <div style="flex:1;min-width:0;">
                <div class="hn-drop-name"><?= htmlspecialchars($acc['username']) ?></div>
                <?php if($is_active): ?>
                    <div class="hn-drop-sub" style="color:#2b67f5;">Active</div>
                <?php elseif($acc_unread > 0): ?>
                    <div class="hn-drop-sub" style="color:#FF2D2D;"><?= $acc_unread ?> unread</div>
                <?php endif; ?>
            </div>

            <?php if($is_active): ?>
            <i class="ri-checkbox-circle-fill" style="color:#2b67f5;font-size:16px;flex-shrink:0;"></i>
            <?php endif; ?>
        </a>

        <?php if(!$is_active): ?>
        <a href="unlink_account.php?user_id=<?= $acc_id ?>"
           onclick="return confirm('Remove <?= htmlspecialchars($acc['username']) ?> from your accounts?');"
           class="hn-unlink-btn"
           title="Remove account">
            <i class="ri-close-line"></i>
        </a>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <div class="hn-divider"></div>

    <?php if($at_limit): ?>
    <div class="hn-add-btn" style="opacity:0.4;cursor:not-allowed;" title="Maximum 5 accounts reached">
        <div class="hn-add-icon" style="border-color:rgba(255,255,255,0.15);">
            <i class="ri-add-line" style="color:rgba(255,255,255,0.2);"></i>
        </div>
        <div>
            <div style="font-size:13px;font-weight:500;">Add Account</div>
            <div style="font-size:10px;color:rgba(255,255,255,0.3);">Limit reached (5 max)</div>
        </div>
    </div>
    <?php else: ?>
    <a href="add_account.php" class="hn-add-btn">
        <div class="hn-add-icon">
            <i class="ri-add-line"></i>
        </div>
        <div>
            <div style="font-size:13px;font-weight:500;">Add Account</div>
            <div style="font-size:10px;color:rgba(255,255,255,0.35);"><?= 4 - $linked_count ?> slot<?= (4 - $linked_count) === 1 ? '' : 's' ?> remaining</div>
        </div>
    </a>
    <?php endif; ?>

    <!-- New Logout Section -->
    <div class="hn-divider"></div>
    
    <a href="logout.php" class="hn-add-btn" onclick="return confirm('Are you sure you want to logout?');" style="color:#ff4d4d;">
        <div class="hn-add-icon" style="border-color:rgba(255,77,77,0.4);">
            <i class="ri-logout-box-r-line" style="color:#ff4d4d;"></i>
        </div>
        <div>
            <div style="font-size:13px;font-weight:500;">Logout</div>
            <div style="font-size:10px;color:rgba(255,77,77,0.6);">Sign out of all accounts</div>
        </div>
    </a>
        </div>
    </div>

    <!-- MIDDLE — Nav links -->
    <nav class="home-nav-links">
        <a href="discover.php"     class="home-nav-link">Discover</a>
        <a href="Sponsorships.php" class="home-nav-link">Ads & Promotions</a>
        <a href="audio.php"    class="home-nav-link">Sounds</a>
        <a href="notifications.php"   class="home-nav-link">Notifications</a>
        <a href="untracked.php"   class="home-nav-link">Untracked</a>
        <a href="memepedia.php" class="home-nav-link">Memepedia</a>
    </nav>

    <!-- RIGHT — Chatbot link -->
    <a href="chatbot.php" class="home-nav-chatbot">
        <i class="ri-robot-line" style="margin-right:5px;font-size:13px;vertical-align:-1px;"></i>Chatbot
    </a>
 
</div>

<script>
function toggleHomeNav(e) {
    e.stopPropagation();
    var profile  = document.getElementById('homeNavProfile');
    var dropdown = document.getElementById('homeNavDropdown');
    var isOpen   = dropdown.classList.contains('open');
    dropdown.classList.toggle('open', !isOpen);
    profile.classList.toggle('open', !isOpen);
}

document.addEventListener('click', function() {
    var d = document.getElementById('homeNavDropdown');
    var p = document.getElementById('homeNavProfile');
    if(d) d.classList.remove('open');
    if(p) p.classList.remove('open');
});
</script>



<div class="header-controls-wrapper">
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

    <div class="follow-wrap" id="followWrap">
    <button class="fb-arrow fb-arrow-left hidden" id="arrowLeft" aria-label="Scroll left">&#8249;</button>
    <div class="follow-bar" id="followBar">
        <?php
        $defaultPic = 'uploads/mee.jpeg';

        /* ── FIX #3: batch "has new post" lookup for the whole follow
           bar in one query instead of one query per followed user.
           $following is a buffered mysqli_result (get_result() from a
           prepared statement), so data_seek() is safe here. ── */
        $_follow_bar_new_map = [];
        if ($following && $following->num_rows > 0) {
            $following->data_seek(0);
            $follow_ids = [];
            while ($f = $following->fetch_assoc()) { $follow_ids[] = (int)$f['id']; }
            $following->data_seek(0);

            if (!empty($follow_ids)) {
                $ids_str = implode(',', $follow_ids);
                $rc = $conn->query("SELECT DISTINCT uploaded_by FROM memes WHERE uploaded_by IN ($ids_str) AND created_at >= NOW() - INTERVAL 24 HOUR");
                if ($rc) while ($r = $rc->fetch_assoc()) { $_follow_bar_new_map[(int)$r['uploaded_by']] = true; }
            }
        }

        if($following && $following->num_rows > 0){
            while($f = $following->fetch_assoc()){
                $pic = $f['profile_pic'] ?? '';
                if(empty($pic)){ $pic = $defaultPic; }
                $uid = (int)$f['id'];
                $has_new_post = !empty($_follow_bar_new_map[$uid]);
                $badge = $has_new_post ? '<span class="fb-badge"><i class="ri-image-fill"></i></span>' : '';
                echo '
                <div class="follow-user">
                    <a href="profile.php?user_id='.$f['id'].'" style="position:relative;display:inline-block;">
                        <img src="'.htmlspecialchars($pic).'" onerror="this.src=\''.$defaultPic.'\'">
                        '.$badge.'
                        <p><svg xmlns="http://www.w3.org/2000/svg" width="8" height="8" viewBox="0 0 24 24"><circle cx="12" cy="12" r="12" fill="#FF0000"/><path d="M9 12l2 2 4-4" stroke="white" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg> '.$f['username'].'</p>
                    </a>
                </div>';
            }
        }
        ?>
    </div>
    <button class="fb-arrow fb-arrow-right" id="arrowRight" aria-label="Scroll right">&#8250;</button>
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
    </div>
</div>

<style>
/* ── HEADER CONTROLS WRAPPER ── */
.header-controls-wrapper {
    position: relative;
    margin-top: -55px;
    padding: 0 15px;
}
 
@media (max-width: 768px) {
    .header-controls-wrapper {
        margin-top: -1px;
        padding: 0 10px;
    }
}
 
@media (max-width: 480px) {
    .header-controls-wrapper {
        margin-top: -1px;
        padding: 0 8px;
    }
}
 
@media (max-width: 360px) {
    .header-controls-wrapper {
        margin-top: -1px;
        padding: 0 6px;
    }
}

@media (min-width: 769px) {
    .kebab-btn {
        margin-right: 10px;
    }
}

/* ─── TITLE STYLING ──────────────────────────────────────── */
.kebab-container .title {
    font-size: 24px;
    letter-spacing: 4px;
    font-weight: 700;
    color: #fff;
    padding-left: 20px;
    display: none; /* hidden on desktop */
}

/* ─── TITLE ROW (title + icon, same line) ──────────────────
   Wraps the title text + the new menu icon so they sit
   beside each other, while pushing the kebab-btn far right
   (this replaces margin-right:auto that used to live on .title) */
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
        width: 100%;
        padding: 0 15px;
    }

    .kebab-container .title {
        display: block;
    }
}

/* 🌟 KEBAB POSITIONING (Pinned within the wrapper) */
.kebab-container {
    position: absolute;
    top: -45px;
    right: 17px;
}

/* 🌟 UPDATED FLUID BUTTON */
.kebab-btn {
    top:10px;
    background:none;
    border: none;
    color: #fff;
    font-size: clamp(18px, 5vw, 22px);
    padding: clamp(6px, 2vw, 8px) clamp(10px, 3vw, 14px);
    border-radius: 18px;
    cursor: pointer;
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    transition: all 0.3s ease;
}

.kebab-btn:hover {
    color: #ff2a2a;
    transform: scale(1.1);
}

/* 🌟 UPDATED DROPDOWN */
.kebab-dropdown {
    display: none;
    position: absolute;
    top: 45px;
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

/* 📱 TABLET + MOBILE */
@media (max-width: 768px){
    .kebab-btn{
        margin-left: auto !important;
        margin-right: -19px !important;
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

<style>
/* =========================
   RESPONSIVE "POSTED BY"
   ========================= */

/* Tablet */
@media (max-width: 768px){
    .original-user{
        top:49px !important;
        left:10px !important;
        font-size:0.72rem !important;
        padding:2px 6px;
    }

    .original-user strong a{
        font-size:0.72rem;
    }
}

/* Phone */
@media (max-width: 480px){
    .original-user{
        top:49px !important;
        left:8px !important;
        font-size:0.68rem !important;
        padding:2px 5px;
    }

    .original-user strong a{
        font-size:0.68rem;
    }
}

/* Very small phones */
@media (max-width: 360px){
    .original-user{
        top:49px !important;
        left:6px !important;
        font-size:0.65rem !important;
    }

    .original-user strong a{
        font-size:0.65rem;
    }
}

/* =========================
   TRACK BUTTON RESPONSIVE
   ========================= */

/* Tablet */
@media (max-width: 768px){
    .track-btn{
        padding:6px 10px;
        font-size:0.75rem;
        border-radius:15px;
    }
}

/* Phone */
@media (max-width: 480px){
    .track-btn{
        padding:5px 9px;
        font-size:0.7rem;
        border-radius:15px;
    }
}

/* Very small phones */
@media (max-width: 360px){
    .track-btn{
        padding:4px 8px;
        font-size:0.64rem;
        border-radius:15px;
    }
}


/* ── KEBAB BUTTON — transparent, no button look ── */
.index-kebab-btn {
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    padding: 6px !important;
    min-width: unset !important;
    min-height: unset !important;
}

.index-kebab-btn:hover{background:rgba(255,255,255,0.08)!important;}

.index-kebab-btn i {
    font-size: 1.3rem !important;
    color: white !important;
}

.index-kebab-btn:hover i {
    color: #f24455 !important;
}

/* ── TABLET (max 768px) ── */
@media (max-width: 768px) {
    .index-kebab-btn i {
        font-size: 1.3rem !important;
    }
}


.eng-pill {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 7px 16px;
    border-radius: 999px;
    border: 1px solid rgba(255,255,255,0.15);
    background: transparent;
    color: rgba(255,255,255,0.6);
    font-size: 0.78rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    font-family: Poppins, sans-serif;
}

.eng-pill i {
    font-size: 0.85rem;
}

.active-pill {
    background: #f24455;
    border-color: #f24455;
    color: white;
}

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

/* ══════════════════════════════════════
   INDEX FEED SKELETON
══════════════════════════════════════ */
.feed-skeleton {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(280px, 100%), 1fr));
    gap: clamp(14px, 3vw, 28px);
    padding: clamp(16px, 4vw, 40px);
    max-width: 1200px;
    margin: auto;
}

@media (min-width: 1024px) {
    .feed-skeleton { grid-template-columns: repeat(3, 1fr); }
}

/* ── CARD SHELL ── */
.sk-card {
    position: relative;
    border-radius: 25px;
    overflow: hidden;
    background: rgba(255,255,255,0.04);
    /* same height rhythm as real card */
    height: clamp(200px, 40vw, 320px);
}

/* ── SHIMMER SWEEP ── */
.sk-card::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(
        105deg,
        transparent      0%,
        transparent      33%,
        rgba(255,255,255,0.055) 43%,
        rgba(255,255,255,0.11)  50%,
        rgba(255,255,255,0.055) 57%,
        transparent      67%,
        transparent      100%
    );
    background-size: 260% 100%;
    animation: feedShimmer 1.8s ease-in-out infinite;
}

@keyframes feedShimmer {
    0%   { background-position:  220% 0; }
    100% { background-position: -220% 0; }
}

/* stagger so cards feel alive individually */
.sk-card:nth-child(1)  { animation-delay: 0s;    }
.sk-card:nth-child(2)  { animation-delay: 0.14s; }
.sk-card:nth-child(3)  { animation-delay: 0.28s; }
.sk-card:nth-child(4)  { animation-delay: 0.08s; }
.sk-card:nth-child(5)  { animation-delay: 0.22s; }
.sk-card:nth-child(6)  { animation-delay: 0.36s; }

/* ── FAKE TOP BUTTONS ROW ── */
.sk-top-row {
    position: absolute;
    top: 12px;
    left: 12px;
    right: 12px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    z-index: 2;
}

.sk-btn-group {
    display: flex;
    gap: 7px;
}

.sk-pill {
    height: 30px;
    border-radius: 20px;
    background: rgba(255,255,255,0.09);
}

.sk-pill.wide  { width: 54px; }
.sk-pill.short { width: 32px; }

/* ── FAKE "POSTED BY" ROW ── */
.sk-posted {
    position: absolute;
    top: 52px;
    left: 12px;
    height: 14px;
    width: 130px;
    border-radius: 6px;
    background: rgba(255,255,255,0.08);
    z-index: 2;
}

/* ── FAKE SOCIAL PROOF AVATARS ── */
.sk-avatars {
    position: absolute;
    bottom: 56px;
    left: 12px;
    display: flex;
    gap: 0;
    z-index: 2;
}

.sk-avatar {
    width: 26px;
    height: 26px;
    border-radius: 50%;
    background: rgba(255,255,255,0.1);
    border: 2px solid rgba(255,255,255,0.05);
    margin-right: -6px;
}

/* ── FAKE USER BAR (bottom) ── */
.sk-user-bar {
    position: absolute;
    bottom: 10px;
    left: 50%;
    transform: translateX(-50%);
    width: calc(100% - clamp(16px, 4vw, 60px));
    height: clamp(36px, 7vw, 48px);
    border-radius: clamp(10px, 2vw, 15px);
    background: rgba(255,255,255,0.07);
    z-index: 2;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 0 10px;
    overflow: hidden;
}

/* circle avatar inside user bar */
.sk-user-bar::before {
    content: '';
    flex-shrink: 0;
    width: clamp(22px, 5vw, 34px);
    height: clamp(22px, 5vw, 34px);
    border-radius: 50%;
    background: rgba(255,255,255,0.12);
}

/* caption line inside user bar */
.sk-user-bar::after {
    content: '';
    flex: 1;
    height: 10px;
    border-radius: 6px;
    background: rgba(255,255,255,0.1);
    max-width: 75%;
}

/* ── ENGAGEMENT ROW below card ── */
.sk-eng-row {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 4px 0;
}

.sk-eng-avatars {
    display: flex;
}

.sk-eng-av {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: rgba(255,255,255,0.1);
    border: 1.5px solid rgba(255,255,255,0.05);
    margin-right: -5px;
}

.sk-eng-av:last-child { margin-right: 4px; }

.sk-eng-text {
    height: 10px;
    border-radius: 6px;
    background: rgba(255,255,255,0.08);
    flex: 1;
    max-width: 55%;
}

/* ── RESPONSIVE ── */
@media (max-width: 768px) {
    .feed-skeleton {
        grid-template-columns: repeat(2, 1fr);
        gap: 14px;
        padding: 16px;
    }
    .sk-card { height: clamp(160px, 35vw, 240px); }
}

@media (max-width: 480px) {
    .feed-skeleton {
        grid-template-columns: 1fr;
        gap: 12px;
        padding: 12px;
    }
    .sk-card { height: clamp(220px, 60vw, 300px); }
}


/* ── GROUP TAG ON FEED CARDS ── */
.post-group-tag {
    position: absolute;
    top: 92px; /* sits just below the Posted By row */
    left: 12px;
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 0.65rem;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.88);
    z-index: 5;
    pointer-events: all;
    max-width: calc(100% - 16px);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.post-group-tag i {
    font-size: 0.7rem;
    color: rgba(255,255,255,0.4);
    flex-shrink: 0;
}

.post-group-link {
    color: #FF4C4C;
    text-decoration: none;
    font-weight: 600;
    font-size: 0.65rem;
    transition: color 0.2s, text-decoration 0.2s;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.post-group-link:hover {
    color: #ff2020;
    text-decoration: underline;
}

/* Responsive */
@media (max-width: 768px) {
    .post-group-tag { top: 90px; font-size: 0.6rem; }
    .post-group-link { font-size: 0.6rem; }
}

@media (max-width: 480px) {
    .post-group-tag { top: 90px; font-size: 0.58rem; }
    .post-group-link { font-size: 0.58rem; }
}

.feed-multi-badge {
    position: absolute;
    top: 50px;
    right: 12px;
    z-index: 6;
    display: flex;
    align-items: center;
    gap: 4px;
    background: rgba(0,0,0,0.55);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    color: #fff;
    font-size: 10.5px;
    font-weight: 700;
    padding: 4px 8px;
    border-radius: 20px;
    pointer-events: none;
}
.feed-multi-badge i { font-size: 11px; }
@media (max-width: 480px) {
    .feed-multi-badge { top: 46px; right: 10px; font-size: 10px; padding: 3px 7px; }
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

        // Close when clicking anywhere else
        document.addEventListener('click', () => {
            menu.classList.remove('show');
        });
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
                $href=$t==='All'?'index.php?tag=all':'index.php?tag='.urlencode($t);
                $cls='tag-pill'.($isActive?' active':'').($isAff&&!$isActive?' user-affinity':'');
                echo '<a href="'.$href.'" class="'.$cls.'">'.($t==='All'?'All':'#'.$t).'</a>';
            }
            ?>
        </div>
    </div>
    <button class="tag-scroll-btn-desktop" id="tagRight"><i class="ri-arrow-right-s-line"></i></button>
</div>

<!-- INDEX FEED SKELETON -->
<div class="feed-skeleton" id="feedSkeleton">

    <!-- repeat this block 6 times for a full first-screen fill -->

    <div style="display:flex;flex-direction:column;">
        <div class="sk-card">
            <div class="sk-top-row">
                <div class="sk-btn-group">
                    <div class="sk-pill wide"></div>
                    <div class="sk-pill wide"></div>
                    <div class="sk-pill wide"></div>
                </div>
                <div class="sk-btn-group">
                    <div class="sk-pill short"></div>
                </div>
            </div>
            <div class="sk-posted"></div>
            <div class="sk-avatars">
                <div class="sk-avatar"></div>
                <div class="sk-avatar"></div>
                <div class="sk-avatar"></div>
            </div>
            <div class="sk-user-bar"></div>
        </div>
        <div class="sk-eng-row">
            <div class="sk-eng-avatars">
                <div class="sk-eng-av"></div>
                <div class="sk-eng-av"></div>
                <div class="sk-eng-av"></div>
            </div>
            <div class="sk-eng-text"></div>
        </div>
    </div>

    <div style="display:flex;flex-direction:column;">
        <div class="sk-card">
            <div class="sk-top-row">
                <div class="sk-btn-group">
                    <div class="sk-pill wide"></div>
                    <div class="sk-pill wide"></div>
                    <div class="sk-pill wide"></div>
                </div>
                <div class="sk-btn-group">
                    <div class="sk-pill short"></div>
                </div>
            </div>
            <div class="sk-posted"></div>
            <div class="sk-avatars">
                <div class="sk-avatar"></div>
                <div class="sk-avatar"></div>
            </div>
            <div class="sk-user-bar"></div>
        </div>
        <div class="sk-eng-row">
            <div class="sk-eng-avatars">
                <div class="sk-eng-av"></div>
                <div class="sk-eng-av"></div>
            </div>
            <div class="sk-eng-text"></div>
        </div>
    </div>

    <div style="display:flex;flex-direction:column;">
        <div class="sk-card">
            <div class="sk-top-row">
                <div class="sk-btn-group">
                    <div class="sk-pill wide"></div>
                    <div class="sk-pill wide"></div>
                    <div class="sk-pill wide"></div>
                </div>
                <div class="sk-btn-group">
                    <div class="sk-pill short"></div>
                </div>
            </div>
            <div class="sk-posted"></div>
            <div class="sk-user-bar"></div>
        </div>
        <div class="sk-eng-row">
            <div class="sk-eng-avatars">
                <div class="sk-eng-av"></div>
                <div class="sk-eng-av"></div>
                <div class="sk-eng-av"></div>
            </div>
            <div class="sk-eng-text"></div>
        </div>
    </div>

    <div style="display:flex;flex-direction:column;">
        <div class="sk-card">
            <div class="sk-top-row">
                <div class="sk-btn-group">
                    <div class="sk-pill wide"></div>
                    <div class="sk-pill wide"></div>
                    <div class="sk-pill wide"></div>
                </div>
                <div class="sk-btn-group">
                    <div class="sk-pill short"></div>
                </div>
            </div>
            <div class="sk-posted"></div>
            <div class="sk-avatars">
                <div class="sk-avatar"></div>
                <div class="sk-avatar"></div>
            </div>
            <div class="sk-user-bar"></div>
        </div>
        <div class="sk-eng-row">
            <div class="sk-eng-avatars">
                <div class="sk-eng-av"></div>
            </div>
            <div class="sk-eng-text"></div>
        </div>
    </div>

    <div style="display:flex;flex-direction:column;">
        <div class="sk-card">
            <div class="sk-top-row">
                <div class="sk-btn-group">
                    <div class="sk-pill wide"></div>
                    <div class="sk-pill wide"></div>
                    <div class="sk-pill wide"></div>
                </div>
                <div class="sk-btn-group">
                    <div class="sk-pill short"></div>
                </div>
            </div>
            <div class="sk-posted"></div>
            <div class="sk-avatars">
                <div class="sk-avatar"></div>
                <div class="sk-avatar"></div>
                <div class="sk-avatar"></div>
            </div>
            <div class="sk-user-bar"></div>
        </div>
        <div class="sk-eng-row">
            <div class="sk-eng-avatars">
                <div class="sk-eng-av"></div>
                <div class="sk-eng-av"></div>
            </div>
            <div class="sk-eng-text"></div>
        </div>
    </div>

    <div style="display:flex;flex-direction:column;">
        <div class="sk-card">
            <div class="sk-top-row">
                <div class="sk-btn-group">
                    <div class="sk-pill wide"></div>
                    <div class="sk-pill wide"></div>
                    <div class="sk-pill wide"></div>
                </div>
                <div class="sk-btn-group">
                    <div class="sk-pill short"></div>
                </div>
            </div>
            <div class="sk-posted"></div>
            <div class="sk-user-bar"></div>
        </div>
        <div class="sk-eng-row">
            <div class="sk-eng-avatars">
                <div class="sk-eng-av"></div>
                <div class="sk-eng-av"></div>
                <div class="sk-eng-av"></div>
            </div>
            <div class="sk-eng-text"></div>
        </div>
    </div>

</div>

<?php
// ── Build private profile + approved maps ONCE before loop ──
$_private_profile_map = [];
$_approved_map        = [];

if($user_id) {
    $pp = $conn->query("SELECT user_id FROM user_settings WHERE is_fully_private=1");
    if($pp) while($r=$pp->fetch_assoc())
        $_private_profile_map[(int)$r['user_id']] = true;

    $ap = $conn->query("SELECT target_id FROM track_requests WHERE requester_id=$user_id AND status='approved'");
    if($ap) while($r=$ap->fetch_assoc())
        $_approved_map[(int)$r['target_id']] = true;
}
?>

<?php
// ── Pre-fetch group membership map for all meme IDs ──
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

// ── Pre-fetch all media items for every Zeem (multi-media posts) ──
$_media_map = [];
if(!empty($memes)) {
    $all_meme_ids = array_column($memes, 'id');
    if(!empty($all_meme_ids)) {
        $ids_str = implode(',', array_map('intval', $all_meme_ids));
        $mm_res = $conn->query("
            SELECT meme_id, file_name, type, position
            FROM meme_media
            WHERE meme_id IN ($ids_str)
            ORDER BY meme_id, position ASC
        ");
        if($mm_res) {
            while($mm = $mm_res->fetch_assoc()) {
                $mid = (int)$mm['meme_id'];
                if(!isset($_media_map[$mid])) $_media_map[$mid] = [];
                $_media_map[$mid][] = [
                    'file' => $mm['file_name'],
                    'type' => $mm['type'],
                ];
            }
        }
    }
}

// ── Pre-fetch attached-audio info for every Zeem ──
$_audio_map = [];
if(!empty($memes)) {
    $all_meme_ids = array_column($memes, 'id');
    if(!empty($all_meme_ids)) {
        $ids_str = implode(',', array_map('intval', $all_meme_ids));

        $au_res = $conn->query("
            SELECT ma.meme_id, a.id AS audio_id, a.title, a.artist, a.file_name,
                   ma.is_original,
                   ma.music_volume, ma.video_audio_volume
            FROM meme_audio ma
            JOIN audios a ON a.id = ma.audio_id
            WHERE ma.meme_id IN ($ids_str)
        ");
        if($au_res) while($au = $au_res->fetch_assoc())
            $_audio_map[(int)$au['meme_id']] = $au;

        /* ── FIX #3: Backfill native audio identity for videos with
           nothing attached — batched. Previously this ran a SELECT
           plus up to two INSERTs per video, synchronously, before any
           HTML was sent. Now it's one batched SELECT to find which
           videos already have a backfilled audio row, and INSERTs only
           run for the genuinely-missing ones. ── */
        $_needs_backfill = [];
        foreach($memes as $mrow){
            $mid = (int)$mrow['id'];
            if(($mrow['type'] ?? '') !== 'video') continue;
            if(isset($_audio_map[$mid])) continue;
            $_needs_backfill[$mid] = $mrow;
        }

        if(!empty($_needs_backfill)){
            $_backfill_ids_str = implode(',', array_keys($_needs_backfill));
            $_existing_native  = [];
            $er = $conn->query("SELECT id, source_meme_id FROM audios WHERE source_meme_id IN ($_backfill_ids_str)");
            if($er) while($r = $er->fetch_assoc()) $_existing_native[(int)$r['source_meme_id']] = (int)$r['id'];

            foreach($_needs_backfill as $mid => $mrow){
                if(isset($_existing_native[$mid])){
                    $aid = $_existing_native[$mid];
                } else {
                    $uploader_id = (int)$mrow['uploaded_by'];
                    $file_name   = $conn->real_escape_string($mrow['file_name']);
                    $conn->query("
                        INSERT INTO audios (uploader_id, title, file_name, is_original, source_meme_id, created_at)
                        VALUES ($uploader_id, 'Original audio', '$file_name', 1, $mid, NOW())
                    ");
                    $aid = $conn->insert_id;
                    if($aid){
                        $conn->query("
                            INSERT INTO meme_audio (meme_id, audio_id, is_original, music_volume, video_audio_volume)
                            VALUES ($mid, $aid, 1, 100, 100)
                        ");
                    }
                }

                if($aid){
                    $_audio_map[$mid] = [
                        'meme_id'            => $mid,
                        'audio_id'           => $aid,
                        'title'              => 'Original audio',
                        'artist'             => null,
                        'file_name'          => $mrow['file_name'],
                        'is_original'        => 1,
                        'music_volume'       => 100,
                        'video_audio_volume' => 100,
                    ];
                }
            }
        }
    }
}

/* ═══════════════════════════════════════════════════════════
   FIX #5: batch-fetch engagement avatars (top 3 likers per
   meme) for the WHOLE page in one query, instead of the old
   $eng_stmt->execute() running once per card inside the loop.
   ═══════════════════════════════════════════════════════════ */
$_eng_map = [];
if(!empty($memes)) {
    $all_meme_ids = array_column($memes, 'id');
    if(!empty($all_meme_ids)) {
        $ids_str = implode(',', array_map('intval', $all_meme_ids));
        $eng_res = $conn->query("
            SELECT l.meme_id, u.id, u.username, u.profile_pic
            FROM likes l
            JOIN users u ON u.id = l.user_id
            WHERE l.meme_id IN ($ids_str)
            ORDER BY l.meme_id, l.id DESC
        ");
        if($eng_res) {
            while($er = $eng_res->fetch_assoc()) {
                $mid = (int)$er['meme_id'];
                if(!isset($_eng_map[$mid])) $_eng_map[$mid] = [];
                // only keep the first 3 per meme, same LIMIT 3 the old query had
                if(count($_eng_map[$mid]) < 3) $_eng_map[$mid][] = $er;
            }
        }
    }
}
?>

<!-- FEED -->
<div class="feed" id="peuraFeedGrid">
<?php
$counter = 0;

/* ── FIX #4: collect per-card data into PHP arrays instead of
   echoing one <script> tag per card. json_encode'd once, in a
   single script tag, after the loop ends. ── */
$_peura_feed_data = [];
$_kebab_data      = [];

// Small helper: mysqli::bind_param() needs real variables (by reference),
// so a dynamic-length param list (e.g. the NOT IN (...) exclude list) can't
// just be splatted from an array of values. This builds the ref array.
function bindParamsByRef($stmt, $types, $params) {
    $refs = [];
    foreach ($params as $key => $value) {
        $refs[$key] = &$params[$key];
    }
    array_unshift($refs, $types);
    return call_user_func_array([$stmt, 'bind_param'], $refs);
}

/* ── FIX #5: collect every @mention across every caption in the feed
   up front, resolve them all in ONE query, then have linkifyCaption()
   just read from that map. Previously this ran a separate prepared
   statement per @mention, per caption, on every single page load —
   a feed of 20 posts with several mentions each meant dozens of
   individual DB round trips mid-render. ── */
$_mentioned_id_map = [];
if (!empty($memes)) {
    $all_usernames = [];
    foreach ($memes as $mrow) {
        if (preg_match_all('/@([a-zA-Z0-9_]+)/', $mrow['caption'] ?? '', $m)) {
            $all_usernames = array_merge($all_usernames, $m[1]);
        }
    }
    $all_usernames = array_unique($all_usernames);
    if (!empty($all_usernames)) {
        $ph = implode(',', array_fill(0, count($all_usernames), '?'));
        $stmt = $conn->prepare("SELECT id, username FROM users WHERE username IN ($ph)");
        $types = str_repeat('s', count($all_usernames));
        $stmt->bind_param($types, ...$all_usernames);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $_mentioned_id_map[strtolower($r['username'])] = (int)$r['id'];
        }
        $stmt->close();
    }
}

function linkifyCaption($caption, $mention_map) {
    return preg_replace_callback('/@([a-zA-Z0-9_]+)/', function($matches) use ($mention_map) {
        $username = $matches[1];
        $uid = $mention_map[strtolower($username)] ?? null;
        if ($uid) {
            return '<a href="profile.php?user_id='.$uid.'"
                       onclick="event.stopPropagation()"
                       style="color:#f24455;font-weight:600;text-decoration:none;"
                       onmouseover="this.style.textDecoration=\'underline\'"
                       onmouseout="this.style.textDecoration=\'none\'">@'.$username.'</a>';
        }
        return '@'.$username;
    }, $caption);
}

foreach($memes as $row) {

    $uploader_id = (int)$row['uploaded_by'];
    if($uploader_id != (int)$user_id && !empty($_private_profile_map[$uploader_id]) && empty($_approved_map[$uploader_id])) continue;

    $counter++;
    $meme_id = (int)$row['id'];
    $media_items = [[ 'file' => $row['file_name'], 'type' => $row['type'] ]];
    if(!empty($_media_map[$meme_id])) {
        $media_items = array_merge($media_items, $_media_map[$meme_id]);
    }
    $media_count = count($media_items);

    $like_count    = (int)$row['like_count'];
    $repost_count  = (int)$row['repost_count'];
    $fav_count     = (int)$row['fav_count'];
    $share_count   = (int)$row['share_count'];

    $user_liked    = (int)$row['user_liked']    > 0;
    $user_reposted = (int)$row['user_reposted'] > 0;
    $user_fav      = (int)$row['user_fav']      > 0;

    $is_following  = (int)$row['is_following']  > 0;

    $group_info = $_group_map[$meme_id] ?? null;
    $audio_info = $_audio_map[$meme_id] ?? null;
    $social_proof = $social_proof_map[(int)$meme_id] ?? [];

    $created      = strtotime($row['created_at']);
    $now          = time();
    $diff         = $now - $created;
    $is_new_badge = ($diff <= 172800);

    // data-feed-index lets the client-side suggested-users script (below
    // the loop) know exactly which position each card sits at, replacing
    // the old $counter % 12 == 0 PHP check.
    echo '<div class="card-unit" data-feed-index="'.$counter.'">';

    echo '<div class="card glass" onclick="openCardModal(
        \'uploads/'.$row['file_name'].'\',
        \''.$row['type'].'\',
        \''.addslashes($row['profile_pic']).'\',
        \''.$row['uploaded_by'].'\',
        \''.addslashes($row['username']).'\',
        \''.addslashes($row['caption']).'\'
    )">';

    /* ── FIX #4: push this card's data into the PHP arrays instead of
       echoing a per-card <script> tag. ── */
    $_peura_feed_data[] = [
        'id'          => (int)$meme_id,
        'file'        => 'uploads/'.$row['file_name'],
        'type'        => $row['type'],
        'media'       => array_map(function($m){
                             return ['file' => 'uploads/'.$m['file'], 'type' => $m['type']];
                         }, $media_items),
        'pic'         => $row['profile_pic'],
        'userId'      => (string)(int)$row['uploaded_by'],
        'username'    => $row['username'],
        'nickname'    => $row['nickname'] ?? '',
        'caption'     => $row['caption'],
        'time'        => $row['created_at'],
        'likeCount'   => (int)$like_count,
        'repostCount' => (int)$repost_count,
        'favCount'    => (int)$fav_count,
        'shareCount'  => (int)$share_count,
        'liked'       => (bool)$user_liked,
        'reposted'    => (bool)$user_reposted,
        'faved'       => (bool)$user_fav,
        'isPartnership' => !empty($row['is_partnership']),
        'partnerBrand'  => $row['partner_brand'] ?? '',
        'isAiGenerated' => !empty($row['is_ai_generated']),
        'isAd'          => !empty($row['is_ad']),
        'adCtaText'     => $row['ad_cta_text'] ?? '',
        'adCtaUrl'      => $row['ad_cta_url'] ?? '',
        'adCtaColor'    => $row['ad_cta_color'] ?? '#FF4C4C',
        'groupId'     => $group_info ? (int)$group_info['group_id'] : null,
        'groupTitle'  => $group_info ? $group_info['group_title'] : null,
        'connectedMemeId' => !empty($row['connected_meme_id']) ? (int)$row['connected_meme_id'] : null,
        'connectedLabel'  => $row['connected_label'] ?? null,
        'audioId'     => $audio_info ? (int)$audio_info['audio_id'] : null,
        'audioTitle'  => $audio_info ? $audio_info['title'] : null,
        'audioArtist' => ($audio_info && !empty($audio_info['artist'])) ? $audio_info['artist'] : null,
        'audioFile'   => $audio_info ? 'uploads/'.$audio_info['file_name'] : null,
        'audioIsOriginal'  => (bool)($audio_info && !empty($audio_info['is_original'])),
        'musicVolume'      => $audio_info ? (int)$audio_info['music_volume'] : 80,
        'videoAudioVolume' => $audio_info ? (int)$audio_info['video_audio_volume'] : 40,
        'socialProof' => array_map(function($sp){
            return [
                'id'          => (int)$sp['id'],
                'username'    => $sp['username'],
                'profile_pic' => $sp['profile_pic'],
                'action_type' => $sp['action_type'],
            ];
        }, $social_proof),
    ];

    $_kebab_data[$meme_id] = [
    'id'             => (int)$meme_id,
    'fileName'       => $row['file_name'],
    'type'           => $row['type'],
    'uploadedBy'     => (int)$row['uploaded_by'],
    'remixOf'        => $row['remix_of'] ? (int)$row['remix_of'] : null,
    'caption'        => $row['caption'],
    'isAd'           => !empty($row['is_ad']),
    'posterPic'      => !empty($row['profile_pic']) ? $row['profile_pic'] : 'uploads/me.jpeg',
    'posterUsername' => $row['username'],
];

    echo '
    <div class="top-buttons" onclick="event.stopPropagation()">
        <div class="btn-group">
            <button class="action-btn like-btn '.($user_liked ? 'active' : '').'" data-id="'.$meme_id.'" onclick="event.stopPropagation();handleFeedAction(\'like\',this)">
                <i class="ri-heart-3-fill"></i>
                <span class="count like-count" style="margin-left:5px;">'.$like_count.'</span>
            </button>
            <button class="action-btn repost-btn '.($user_reposted ? 'active' : '').'" data-id="'.$meme_id.'" onclick="event.stopPropagation();handleFeedAction(\'repost\',this)">
                <i class="ri-repeat-line"></i>
                <span class="count repost-count" style="margin-left:5px;">'.$repost_count.'</span>
            </button>
            <button class="action-btn fav-btn '.($user_fav ? 'active' : '').'" data-id="'.$meme_id.'" onclick="event.stopPropagation();handleFeedAction(\'fav\',this)">
                <i class="ri-bookmark-fill"></i>
                <span class="count fav-count" style="margin-left:5px;">'.$fav_count.'</span>
            </button>
            <button class="action-btn share-btn" data-id="'.$meme_id.'" onclick="event.stopPropagation();openShareModal('.$meme_id.')">
                <i class="ri-share-forward-fill"></i>
                <span class="count share-count" style="margin-left:5px;">'.$share_count.'</span>
            </button>
        </div>
        <div class="btn-group">
            <button class="action-btn index-kebab-btn" data-id="'.$meme_id.'" onclick="event.stopPropagation();openIndexSheet('.$meme_id.')">
                <i class="ri-menu-line"></i>
            </button>
        </div>
    </div>';

    echo '<div class="original-user" style="position:absolute;top:50px;left:12px;font-size:0.8rem;" onclick="event.stopPropagation()">';
    echo '<span style="color:#f24455;">Posted By</span> ';
    echo '<strong><a href="profile.php?user_id='.$uploader_id.'" onclick="event.stopPropagation()" style="color:white;">'.
        ($uploader_id == $current_user ? 'You' : htmlspecialchars($row['username'])).
    '</a></strong>';

    if($uploader_id != $current_user) {
        echo '<button
            class="track-btn '.($is_following ? 'following' : '').'"
            data-user="'.$uploader_id.'"
            data-private="'.(!empty($_private_profile_map[$uploader_id]) ? '1' : '0').'"
            onclick="event.stopPropagation();handleTrackBtn(this)"
        >'.($is_following ? 'Untrack' : 'Track').'</button>';
    }

    echo '</div>';

    if($group_info) {
        $g_id    = (int)$group_info['group_id'];
        $g_title = htmlspecialchars($group_info['group_title']);
        echo '<div class="post-group-tag" onclick="event.stopPropagation()">
            <i class="ri-team-line"></i>
            From&nbsp;<a href="group.php?id='.$g_id.'"
                onclick="event.stopPropagation()"
                class="post-group-link">'.$g_title.'</a>
        </div>';
    }

    /* ── FIX #1 + #2: lazy-load images, and don't autoplay every video
       on page load — only the ones actually on screen. The IntersectionObserver
       that flips preload/plays these lives in the script block right after
       the feed's closing </div>. ── */
    if($row['type'] == "image"){
        echo '<img src="uploads/'.$row['file_name'].'" loading="lazy">';
    } elseif($row['type'] == "video"){
        echo '<video src="uploads/'.$row['file_name'].'" muted loop playsinline preload="none" class="feed-video"></video>';
    } else {
        echo '<img src="uploads/Text.jpg" loading="lazy">';
    }

    if($is_new_badge){ echo '<div class="peura-new-tag">NEW</div>'; }

    if($media_count > 1){
        echo '<div class="feed-multi-badge" onclick="event.stopPropagation()">
            <i class="ri-stack-line"></i> 1/'.$media_count.'
        </div>';
    }

    if (!empty($social_proof)) {
        echo '<div class="social-proof-strip">';
        foreach ($social_proof as $sp) {
            $sp_pic       = !empty($sp['profile_pic']) ? $sp['profile_pic'] : 'uploads/me.jpeg';
            $action_icon  = $sp['action_type'] === 'like' ? 'ri-heart-3-fill' : 'ri-repeat-line';
            $action_color = $sp['action_type'] === 'like' ? '#f24455' : '#7a4aff';
            echo '<a href="profile.php?user_id='.$sp['id'].'" onclick="event.stopPropagation()" class="sp-avatar-wrap">
                <img src="'.$sp_pic.'" onerror="this.src=\'uploads/me.jpeg\'" class="sp-avatar">
                <span class="sp-action-badge" style="background:'.$action_color.';"><i class="'.$action_icon.'"></i></span>
            </a>';
        }
        echo '</div>';
    }

    echo '
    <div class="user-bar glass" onclick="event.stopPropagation()">
        <a href="profile.php?user_id='.$uploader_id.'" onclick="event.stopPropagation()" style="flex-shrink:0;">
            <img src="'.(!empty($row['profile_pic']) ? $row['profile_pic'] : 'uploads/me.jpeg').'"
                 onerror="this.src=\'uploads/me.jpeg\'"
                 style="width:clamp(24px,5vw,38px);height:clamp(24px,5vw,38px);min-width:clamp(24px,5vw,38px);border-radius:50%;object-fit:cover;border:2px solid red;display:block;flex-shrink:0;">
        </a>
        <div style="display:flex;flex-direction:column;justify-content:center;min-width:0;flex:1;">
            <div style="color:rgba(255,255,255,0.9);font-size:clamp(0.65rem,2.2vw,0.82rem);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0;width:100%;font-weight:500;">
                '.linkifyCaption(htmlspecialchars($row["caption"]), $_mentioned_id_map).'
            </div>
        </div>
    </div>';

    echo '</div>'; // END CARD

    /* ── FIX #5: engagement avatars read from the batched $_eng_map
       instead of executing $eng_stmt once per card. ── */
    if($like_count > 0 || $repost_count > 0){
        $eng_users = $_eng_map[$meme_id] ?? [];

        echo '<div class="eng-row" onclick="openEngModal('.$meme_id.', \'likes\')">';
        if(!empty($eng_users)){
            echo '<div class="eng-avatars">';
            foreach($eng_users as $eu){
                $ep = !empty($eu['profile_pic']) ? $eu['profile_pic'] : 'uploads/me.jpeg';
                echo '<img src="'.$ep.'" onerror="this.src=\'uploads/me.jpeg\'" class="eng-avatar">';
            }
            echo '</div>';
        }
        if(!empty($eng_users)){
            $text = 'Liked by <strong>'.$eng_users[0]['username'].'</strong>';
            if($like_count > 1) $text .= ' and <strong>'.($like_count-1).' others</strong>';
            if($repost_count > 0) $text .= ' · <strong>'.$repost_count.'</strong> reposts';
        } else {
            $text = '<strong>'.$repost_count.'</strong> reposts';
        }
        echo '<span class="eng-text">'.$text.'</span>';
        echo '</div>';
    }

    echo '</div>'; // END CARD-UNIT

    /* ─────────────────────────────────────────────────────────
       AD ROW removed entirely — no more per-9-post sponsorship
       query/render block here.

       The old inline "if($counter % 12 == 0)" suggested-users
       block was already removed previously; suggested-user rows
       are inserted client-side by the script below, reusing the
       single type=sugg fetch.
       ───────────────────────────────────────────────────────── */
}
?>
<script>
    const peuraFeed = <?php echo json_encode($_peura_feed_data); ?>;
    window.kebabData = <?php echo json_encode($_kebab_data, JSON_FORCE_OBJECT); ?>;
</script>
</div>

<script>
/* ── FIX #1: lazily play/pause feed videos based on visibility instead
   of every <video> autoplaying the instant the HTML parses. Videos are
   rendered with preload="none" and no autoplay attribute; this observer
   is the only thing that starts them, and only once they're actually
   on screen. ── */
(function(){
    var observer = new IntersectionObserver(function(entries){
        entries.forEach(function(entry){
            var vid = entry.target;
            if(entry.isIntersecting){
                if(vid.preload === 'none') vid.preload = 'auto';
                vid.play().catch(function(){});
            } else {
                vid.pause();
            }
        });
    }, { threshold: 0.5 });

    document.querySelectorAll('.feed-video').forEach(function(v){ observer.observe(v); });
})();
</script>

<script>
/* ══════════════════════════════════════════════════════════
   CLIENT-SIDE SUGGESTED USERS ROWS (fixes #2 and #5)
   Replaces the old per-request PHP getSuggestedUsers() call
   that ran every 12 posts inside the feed loop, each with its
   own N+1 is_fully_private / has_new_post queries. Now there's
   exactly one network round trip (home.php?type=sugg), shared
   with _loadCcmData() below via window._suggPromise, and the
   rows are stitched into the DOM after every 12th card.
   ══════════════════════════════════════════════════════════ */
(function(){
    var DEFAULT_PIC = 'uploads/mee.jpeg';
    var shownUserIds = new Set();

    function escapeHtml(str){
        var div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }

    function suggestedRowHtml(users){
        var html = '<div class="ad-row" style="display:flex;flex-direction:column;align-items:flex-start;gap:8px;width:100%;overflow:hidden;margin:15px 0;">'+
            '<div style="color:#f24455;font-size:0.85rem;margin-left:15px;margin-bottom:5px;font-weight:600;letter-spacing:1px;">Suggested Users</div>'+
            '<div class="suggested-scroll-container" style="display:flex;flex-wrap:nowrap;gap:22px;overflow-x:auto;width:100%;padding:10px 15px 15px 15px;scrollbar-width:none;-ms-overflow-style:none;-webkit-overflow-scrolling:touch;">';

        users.forEach(function(u){
            var pic          = u.profile_pic || DEFAULT_PIC;
            var uid          = parseInt(u.id, 10);
            var isFollowing  = parseInt(u.is_following) > 0;
            var followText   = isFollowing ? 'Untrack' : 'Track';
            var followClass  = isFollowing ? 'following' : '';
            var isPrivate    = !!parseInt(u.is_fully_private || 0);
            var showBtn      = !isPrivate || isFollowing;
            var reason       = u.reason || '';
            var reasonHtml   = reason
                ? '<span style="font-size:0.6rem;color:rgba(255,255,255,0.35);display:block;text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px;">'+escapeHtml(reason)+'</span>'
                : '';
            var newBadge = parseInt(u.has_new_post || 0) > 0
                ? '<span style="position:absolute;top:0;right:8px;width:18px;height:18px;background:#000;border-radius:50%;border:2px solid #06071A;display:flex;align-items:center;justify-content:center;z-index:2;"><i class="ri-image-fill" style="font-size:10px;color:#FF2D2D;line-height:1;"></i></span>'
                : '';

            html += '<div style="display:flex;flex-direction:column;align-items:center;flex-shrink:0;width:82px;color:white;">'+
                '<a href="profile.php?user_id='+uid+'" style="text-decoration:none;color:white;width:100%;position:relative;display:block;">'+
                    '<img src="'+pic+'" onerror="this.src=\''+DEFAULT_PIC+'\';" style="width:65px;height:65px;border-radius:50%;border:2px solid red;object-fit:cover;transition:0.3s;display:block;margin:0 auto;box-shadow:0 4px 10px rgba(0,0,0,0.3);" onmouseover="this.style.transform=\'scale(1.1)\'" onmouseout="this.style.transform=\'scale(1)\'">'+
                    newBadge+
                    '<span style="font-size:0.7rem;margin-top:8px;font-weight:500;display:block;text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'+escapeHtml(u.username)+'</span>'+
                    reasonHtml+
                '</a>'+
                (showBtn
                    ? '<button class="track-btn '+followClass+'" data-user="'+uid+'" style="margin-top:10px;font-size:0.62rem;padding:5px 0;width:100%;border-radius:12px;z-index:0;" onclick="event.stopPropagation();handleTrackBtn(this)">'+followText+'</button>'
                    : '')+
            '</div>';
        });

        html += '</div></div>';
        return html;
    }

    function insertSuggestedRows(allUsers){
        if(!allUsers || !allUsers.length) return;
        var feed = document.getElementById('peuraFeedGrid');
        if(!feed) return;

        var cards = feed.querySelectorAll('.card-unit[data-feed-index]');
        var pool = allUsers.slice();
        var poolIdx = 0;

        cards.forEach(function(card){
            var idx = parseInt(card.dataset.feedIndex, 10);
            if(idx % 12 !== 0) return; // same cadence as the old $counter % 12 check

            var picked = [];
            var attempts = 0;
            while(picked.length < 8 && attempts < pool.length){
                var u = pool[poolIdx % pool.length];
                poolIdx++; attempts++;
                if(!shownUserIds.has(u.id)){
                    picked.push(u);
                    shownUserIds.add(u.id); // dedupe across insertions, same as old $shown_users
                }
            }
            if(!picked.length) return;

            var wrap = document.createElement('div');
            wrap.innerHTML = suggestedRowHtml(picked);
            card.insertAdjacentElement('afterend', wrap.firstElementChild);
        });
    }

    /* single shared fetch for suggested users — _loadCcmData() below
       awaits this same promise instead of firing its own fetch */
    window._suggPromise = fetch('home.php?type=sugg')
        .then(function(r){ return r.json(); })
        .then(function(users){
            window.cmSuggUsers = users || []; // reused by the reels-modal script later in the page
            insertSuggestedRows(window.cmSuggUsers);
            return window.cmSuggUsers;
        })
        .catch(function(){ return []; });
})();
</script>

<!-- ═══════════════════════════════════════════════════════
     INDEX BOTTOM SHEET (post-aware: normal post vs post-ad)
     ═══════════════════════════════════════════════════════ -->
<div id="indexSheetOverlay" class="idx-sheet-overlay" onclick="closeIndexSheet()"></div>
<div id="indexSheetPanel" class="idx-sheet-panel">
    <div class="idx-sheet-handle"></div>

    <div class="idx-sheet-track" id="indexSheetTrack">

        <!-- SCREEN A: option list (differs for normal post vs post-ad) -->
        <div class="idx-sheet-screen">
            <div class="idx-sheet-list" id="indexSheetList"></div>
        </div>

        <!-- SCREEN B: "why am I seeing this" detail -->
        <div class="idx-sheet-screen">
            <div class="idx-detail-header">
                <button class="idx-detail-back" onclick="idxSheetBackToList()"><i class="ri-arrow-left-s-line"></i></button>
                <div class="idx-detail-title" id="idxDetailTitle">Why you're seeing this</div>
                <span class="idx-detail-spacer"></span>
            </div>
            <div class="idx-detail-body" id="idxDetailBody"></div>
        </div>

    </div>

    <button class="idx-sheet-cancel" id="indexSheetCancelBtn" onclick="closeIndexSheet()">Cancel</button>
</div>

<style>
.idx-sheet-overlay{
    position:fixed;inset:0;background:rgba(0,0,0,0.6);
    backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);
    z-index:99999998;opacity:0;pointer-events:none;
    transition:opacity 0.25s ease;
}
.idx-sheet-overlay.show{opacity:1;pointer-events:all;}

.idx-sheet-panel{
    position:fixed;left:0;right:0;bottom:0;
    background:rgba(12,12,28,0.98);
    backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);
    border-top:1px solid rgba(255,255,255,0.08);
    border-radius:20px 20px 0 0;
    z-index:99999999;
    max-height:80vh;
    display:flex;flex-direction:column;
    padding:8px 0 max(10px, env(safe-area-inset-bottom));
    transform:translateY(100%);
    transition:transform 0.3s cubic-bezier(0.32,0.72,0,1);
    box-shadow:0 -10px 40px rgba(0,0,0,0.5);
    overflow:hidden;
}
.idx-sheet-panel.show{transform:translateY(0);}

.idx-sheet-handle{
    width:34px;height:3.5px;border-radius:999px;
    background:rgba(255,255,255,0.2);
    margin:0 auto 4px;flex-shrink:0;
}

/* ── SLIDING TRACK: list ↔ detail ── */
.idx-sheet-track{
    display:flex;width:200%;
    flex:1;min-height:0;
    transition:transform .3s cubic-bezier(.32,.72,0,1);
}
.idx-sheet-track.show-detail{ transform:translateX(-50%); }

.idx-sheet-screen{
    width:50%;flex-shrink:0;
    display:flex;flex-direction:column;min-height:0;
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

/* item with a small subtitle line (e.g. "Not Interested") */
.idx-sheet-item-title{font-size:13px;font-weight:500;color:#fff;line-height:1.3;}
.idx-sheet-item-sub{font-size:10.5px;color:rgba(255,255,255,0.4);margin-top:2px;line-height:1.3;}
.idx-sheet-item.danger .idx-sheet-item-title{color:#ff4c4c;}

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

/* ── DETAIL SCREEN ── */
.idx-detail-header{
    display:flex;align-items:center;gap:8px;
    padding:4px 14px 10px;
    border-bottom:1px solid rgba(255,255,255,0.06);
    flex-shrink:0;
}
.idx-detail-back{
    width:28px;height:28px;border-radius:50%;
    background:rgba(255,255,255,0.06);border:none;color:#fff;
    display:flex;align-items:center;justify-content:center;
    font-size:19px;cursor:pointer;-webkit-tap-highlight-color:transparent;
    transition:background .18s,color .18s;flex-shrink:0;
}
.idx-detail-back:hover{background:rgba(255,45,45,0.18);color:#ff6060;}
.idx-detail-title{
    flex:1;font-size:13.5px;font-weight:700;color:#fff;
    text-align:center;padding-right:28px;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
.idx-detail-spacer{width:28px;flex-shrink:0;}

.idx-detail-body{
    flex:1;overflow-y:auto;-webkit-overflow-scrolling:touch;
    padding:14px 18px 18px;
}
.idx-detail-body::-webkit-scrollbar{width:4px;}
.idx-detail-body::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.15);border-radius:999px;}

.idx-detail-general{
    font-size:12px;line-height:1.65;color:rgba(255,255,255,0.62);
    margin-bottom:16px;
}

.idx-detail-divider{
    display:flex;align-items:center;gap:10px;margin:16px 0 14px;
}
.idx-detail-divider::before,.idx-detail-divider::after{
    content:'';flex:1;height:1px;background:rgba(255,255,255,0.08);
}
.idx-detail-divider span{
    font-size:9.5px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;
    color:rgba(255,255,255,0.3);white-space:nowrap;
}

.idx-detail-poster-row{
    display:flex;align-items:center;gap:10px;margin-bottom:12px;
}
.idx-detail-poster-pic{
    width:38px;height:38px;border-radius:50%;object-fit:cover;
    border:2px solid #f24455;flex-shrink:0;
}
.idx-detail-poster-name{font-size:13px;font-weight:700;color:#fff;}
.idx-detail-poster-sub{font-size:10.5px;color:rgba(255,255,255,0.4);margin-top:1px;}

.idx-detail-unique{
    font-size:12px;line-height:1.7;color:rgba(255,255,255,0.72);
    margin-bottom:18px;
}

.idx-detail-hide-btn{
    width:100%;padding:11px;border-radius:12px;
    border:1px solid rgba(255,76,76,0.35);
    background:rgba(255,76,76,0.08);color:#ff6060;
    font-size:12.5px;font-weight:700;font-family:Poppins,sans-serif;
    cursor:pointer;-webkit-tap-highlight-color:transparent;
    display:flex;align-items:center;justify-content:center;gap:7px;
    transition:background .18s;
}
.idx-detail-hide-btn:hover{background:rgba(255,76,76,0.16);}
.idx-detail-hide-btn i{font-size:15px;}

/* ═══ RESPONSIVE (mirrors existing breakpoints) ═══ */
@media (min-width:791px){
    .idx-sheet-panel{
        left:50%;right:auto;bottom:auto;top:50%;
        width:100%;max-width:340px;
        border-radius:18px;
        transform:translate(-50%,-40%) scale(0.95);
        opacity:0;pointer-events:none;
        max-height:76vh;
    }
    .idx-sheet-panel.show{
        transform:translate(-50%,-50%) scale(1);
        opacity:1;pointer-events:all;
    }
    .idx-sheet-handle{display:none;}
}
@media (max-width:790px){
    .idx-sheet-panel{ border-radius:18px 18px 0 0; max-height:78vh; padding:7px 0 max(9px, env(safe-area-inset-bottom)); }
    .idx-sheet-handle{ width:32px;height:3.5px;margin:0 auto 4px; }
    .idx-sheet-item{ padding:8px 16px; font-size:12.5px; gap:10px; }
    .idx-sheet-item i{ font-size:15px; width:17px; }
    .idx-sheet-cancel{ margin:5px 11px 0; padding:9px; font-size:12px; border-radius:11px; }
    .idx-detail-body{ padding:12px 16px 16px; }
    .idx-detail-general,.idx-detail-unique{ font-size:11.5px; }
}
@media (max-width:490px){
    .idx-sheet-panel{ border-radius:16px 16px 0 0; max-height:76vh; padding:6px 0 max(8px, env(safe-area-inset-bottom)); backdrop-filter:blur(16px); -webkit-backdrop-filter:blur(16px); }
    .idx-sheet-handle{ width:30px;height:3px;margin:0 auto 3px; }
    .idx-sheet-item{ padding:7px 14px; font-size:12px; gap:9px; }
    .idx-sheet-item i{ font-size:14px; width:16px; }
    .idx-sheet-item-sub{ font-size:10px; }
    .idx-sheet-cancel{ margin:5px 10px 0; padding:8px; font-size:11.5px; border-radius:10px; }
    .idx-detail-header{ padding:4px 12px 9px; }
    .idx-detail-title{ font-size:13px; }
    .idx-detail-body{ padding:11px 14px 14px; }
    .idx-detail-general,.idx-detail-unique{ font-size:11px; }
    .idx-detail-poster-pic{ width:34px;height:34px; }
    .idx-detail-hide-btn{ padding:10px;font-size:12px; }
}
@media (max-width:390px){
    .idx-sheet-panel{ border-radius:14px 14px 0 0; max-height:74vh; padding:6px 0 max(7px, env(safe-area-inset-bottom)); backdrop-filter:blur(14px); -webkit-backdrop-filter:blur(14px); box-shadow:0 -8px 30px rgba(0,0,0,0.45); }
    .idx-sheet-handle{ width:28px;height:3px;margin:0 auto 3px; }
    .idx-sheet-item{ padding:6.5px 12px; font-size:11.5px; gap:8px; }
    .idx-sheet-item i{ font-size:13.5px; width:15px; }
    .idx-sheet-cancel{ margin:4px 9px 0; padding:7px; font-size:11px; border-radius:9px; }
    .idx-detail-body{ padding:10px 12px 12px; }
}
@media (max-width:330px){
    .idx-sheet-panel{ border-radius:12px 12px 0 0; max-height:72vh; padding:5px 0 max(6px, env(safe-area-inset-bottom)); backdrop-filter:blur(12px); -webkit-backdrop-filter:blur(12px); box-shadow:0 -6px 24px rgba(0,0,0,0.4); }
    .idx-sheet-handle{ width:24px;height:2.5px;margin:0 auto 3px; }
    .idx-sheet-list::-webkit-scrollbar{width:3px;}
    .idx-sheet-item{ padding:5.5px 10px; font-size:10.5px; gap:7px; }
    .idx-sheet-item i{ font-size:12.5px; width:13px; }
    .idx-sheet-cancel{ margin:4px 8px 0; padding:6px; font-size:10px; border-radius:8px; }
    .idx-detail-body{ padding:9px 10px 10px; }
}
</style>

<script>
/* ═══════════════════════════════════════════════════════
   DISPATCHER — routes to the normal-post list or the
   post-ad list based on d.isAd. This is the ONLY thing
   openIndexSheet() calls, so nothing else needs to change.
   ═══════════════════════════════════════════════════════ */
function _idxSheetHTML(d, isOwner){
    return d.isAd ? _idxSheetAdHTML(d, isOwner) : _idxSheetPostHTML(d, isOwner);
}

/* ── NORMAL POST LIST (unchanged, + new "Why am I seeing this post") ── */
function _idxSheetPostHTML(d, isOwner){
    var items = [];
    var safeCaption = (d.caption || '').replace(/\\/g,'\\\\').replace(/'/g,"\\'");

    items.push('<a class="idx-sheet-item" href="remix_upload.php?meme_id='+d.id+'"><i class="ri-scissors-cut-line"></i> Remix</a>');
    items.push('<div class="idx-sheet-item" onclick="copyCaption(\''+safeCaption+'\');closeIndexSheet();"><i class="ri-file-copy-line"></i> Copy Caption</div>');

    if(d.type !== 'text' && d.fileName && d.fileName !== '0'){
        items.push('<a class="idx-sheet-item" href="uploads/'+d.fileName+'" download><i class="ri-download-2-line"></i> Download</a>');
    }

    items.push('<div class="idx-sheet-item" onclick="showMemeQR('+d.id+');closeIndexSheet();"><i class="ri-qr-code-line"></i> QR Code</div>');
    items.push('<a class="idx-sheet-item" href="account.php?user_id='+d.uploadedBy+'"><i class="ri-user-search-line"></i> About Account</a>');
    items.push('<div class="idx-sheet-item" onclick="shareMeme('+d.id+');closeIndexSheet();"><i class="ri-share-line"></i> Share Post</div>');
    items.push('<div class="idx-sheet-item" onclick="closeIndexSheet();openSaveModal('+d.id+');"><i class="ri-bookmark-line"></i> Add to Collection</div>');

    if(d.remixOf){
        items.push('<a class="idx-sheet-item" href="index.php?meme='+d.remixOf+'"><i class="ri-arrow-go-back-line"></i> See Original</a>');
    }

    items.push('<div class="idx-sheet-item" onclick="idxShowPostReason()"><i class="ri-information-line"></i> Why am I seeing this post</div>');

    items.push('<div class="idx-sheet-item" onclick="notInterestedFromSheet('+d.id+')"><i class="ri-eye-off-line"></i> Not Interested</div>');
    items.push('<div class="idx-sheet-item" onclick="openReportModal({contentId:'+d.id+',contentType:\'meme\'});closeIndexSheet();"><i class="ri-flag-line"></i> Report</div>');

    if(!isOwner){
        items.push('<div class="idx-sheet-item danger" onclick="closeIndexSheet();blockUser('+d.uploadedBy+')"><i class="ri-forbid-line"></i> Block User</div>');
    }

    return items.join('');
}

/* ── POST-AD LIST — a meme flagged is_ad=1, NOT a standalone
   sponsorships-table ad. Mirrors Instagram's ad menu.
   UPDATED: every item now carries a title+subtitle pair (the
   same two-line pattern "Not Interested" used to be the only
   one with), instead of a single plain label. ── */
function _idxSheetAdHTML(d, isOwner){
    var items = [];
    var uname = d.posterUsername || 'this account';

    function row(cls, icon, onclick, href, title, sub){
        var tag = href ? 'a' : 'div';
        var hrefAttr = href ? ' href="'+href+'"' : '';
        var onclickAttr = onclick ? ' onclick="'+onclick+'"' : '';
        return '<'+tag+' class="idx-sheet-item'+(cls?' '+cls:'')+'"'+hrefAttr+onclickAttr+'>'+
            '<i class="'+icon+'"></i>'+
            '<div><div class="idx-sheet-item-title">'+title+'</div>'+
                 '<div class="idx-sheet-item-sub">'+sub+'</div></div>'+
        '</'+tag+'>';
    }


    items.push(row(
        '', 'ri-checkbox-circle-line',
        'idxAdInterested('+d.id+')', null,
        'Interested',
        'We\'ll show you more Ads like this'
    ));

    items.push(row(
        '', 'ri-close-circle-line',
        'idxAdNotInterested('+d.id+')', null,
        'Not Interested',
        'We\'ll show you fewer Ads like this'
    ));

    items.push(row(
        '', 'ri-bookmark-line',
        'closeIndexSheet();openSaveModal('+d.id+');', null,
        'Add to Collection',
        'Save this ad to one of your collections'
    ));
    
    items.push(row(
        '', 'ri-information-line',
        null, 'account.php?user_id='+d.uploadedBy,
        'About this Ad',
        'See who\'s behind this ad and why it was made'
    ));
    
    items.push(row(
        '', 'ri-question-line',
        'idxShowAdReason('+JSON.stringify(d).replace(/"/g,'&quot;')+')', null,
        'Why am I seeing this Ad',
        'Learn how ads are chosen for your feed'
    ));

    items.push(row(
        '', 'ri-flag-line',
        'openReportModal({contentId:'+d.id+',contentType:\'meme\'});closeIndexSheet();', null,
        'Report Ad',
        'Flag this ad for review — your report stays anonymous'
    ));

    if(!isOwner){
        items.push(row(
            'danger', 'ri-forbid-line',
            'closeIndexSheet();blockUser('+d.uploadedBy+')', null,
            'Block Ad Owner',
            'You won\'t see posts or ads from '+uname+' again'
        ));
    }

    return items.join('');
}

/* ═══════════════════════════════════════════════════════
   DETAIL SCREEN — shared slide-in "why am I seeing this"
   ═══════════════════════════════════════════════════════ */
function idxSheetShowDetail(title, bodyHtml){
    document.getElementById('idxDetailTitle').textContent = title;
    document.getElementById('idxDetailBody').innerHTML = bodyHtml;
    document.getElementById('indexSheetTrack').classList.add('show-detail');
}
function idxSheetBackToList(){
    document.getElementById('indexSheetTrack').classList.remove('show-detail');
}

/* short, generic — normal posts */
function idxShowPostReason(){
    var body =
        '<div class="idx-detail-general">'+
            'Dezzme finds and suggests posts based on your recent activity — the tags you interact with, '+
            'the creators you track, and what\'s trending among people with similar interests. This keeps '+
            'your feed relevant as your interests change.'+
        '</div>';
    idxSheetShowDetail("Why you're seeing this post", body);
}

/* long, split into a general "how ads work" part + a unique
   per-post part with the poster's pic and a hide-ads action */
function idxShowAdReason(d){
    var pic   = d.posterPic || 'uploads/me.jpeg';
    var uname = d.posterUsername || 'this account';
    var safeUname = uname.replace(/'/g,"\\'");

    var general =
        '<div class="idx-detail-general">'+
            'Ads are shown in your Dezzme feed based on a mix of signals — your recent activity, the tags '+
            'and creators you engage with, choices the advertiser makes about who they want to reach, and '+
            'what\'s trending across the app right now. Ads help keep Dezzme free to use for everyone.'+
        '</div>';

    var divider = '<div class="idx-detail-divider"><span>About this ad</span></div>';

    var posterRow =
        '<div class="idx-detail-poster-row">'+
            '<img src="'+pic+'" onerror="this.src=\'uploads/me.jpeg\'" class="idx-detail-poster-pic">'+
            '<div>'+
                '<div class="idx-detail-poster-name">'+uname+'</div>'+
                '<div class="idx-detail-poster-sub">Sponsored content</div>'+
            '</div>'+
        '</div>';

    var unique =
        '<div class="idx-detail-unique">'+
            uname+' chose to reach people with interests similar to yours. Dezzme also factored in your '+
            'recent activity — including posts, tags, and creators you\'ve engaged with — to decide this '+
            'ad was worth showing you right now. As your activity on Dezzme changes over time, the ads you '+
            'see may change too.'+
        '</div>';

    var hideBtn =
        '<button class="idx-detail-hide-btn" onclick="idxHideAllAdsFromUser('+d.uploadedBy+',\''+safeUname+'\')">'+
            '<i class="ri-eye-off-line"></i> Hide all Ads from '+uname+
        '</button>';

    idxSheetShowDetail("Why you're seeing this Ad", general + divider + posterRow + unique + hideBtn);
}

/* ═══════════════════════════════════════════════════════
   AD-SPECIFIC ACTIONS
   ═══════════════════════════════════════════════════════ */
function idxAdInterested(memeId){
    closeIndexSheet();
    showToast("Thanks! We'll show you more Ads like this");
}

function idxAdNotInterested(memeId){
    notInterestedFromSheet(memeId); // reuses existing action=not_interested + closes sheet
    showToast("Got it — you'll see fewer Ads like this");
}

/* NOTE: this calls a new backend action, "hide_ads_from_user", which
   doesn't exist yet. Add a handler in home.php's POST block, e.g.:
     INSERT IGNORE INTO hidden_ad_advertisers (user_id, hidden_user_id) VALUES (?, ?)
   and filter the ads feed with:
     AND (memes.is_ad = 0 OR memes.uploaded_by NOT IN (
         SELECT hidden_user_id FROM hidden_ad_advertisers WHERE user_id = ?
     )) */
function idxHideAllAdsFromUser(userId, username){
    if(!confirm('Hide all ads from '+(username||'this account')+'?')) return;
    fetch('home.php', {
        method:  'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body:    'action=hide_ads_from_user&target_id='+userId
    })
    .then(function(r){ return r.json(); })
    .then(function(){
        showToast("You won't see ads from "+(username||'this account')+' anymore');
        closeIndexSheet();
    })
    .catch(function(){ showToast('Something went wrong'); });
}

/* ═══════════════════════════════════════════════════════
   OPEN / CLOSE — resets to list view every time it opens
   ═══════════════════════════════════════════════════════ */
function openIndexSheet(memeId){
    var d = window.kebabData && window.kebabData[memeId];
    if(!d) return;
    var isOwner = String(d.uploadedBy) === String(currentUserId);

    document.getElementById('indexSheetTrack').classList.remove('show-detail');
    document.getElementById('indexSheetList').innerHTML = _idxSheetHTML(d, isOwner);
    document.getElementById('indexSheetOverlay').classList.add('show');
    document.getElementById('indexSheetPanel').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeIndexSheet(){
    document.getElementById('indexSheetOverlay').classList.remove('show');
    document.getElementById('indexSheetPanel').classList.remove('show');
    document.body.style.overflow = '';
    setTimeout(function(){
        document.getElementById('indexSheetTrack').classList.remove('show-detail');
    }, 300);
}

function notInterestedFromSheet(memeId){
    var btn = document.querySelector('.index-kebab-btn[data-id="'+memeId+'"]');
    if(btn) notInterested(memeId, btn);
    closeIndexSheet();
}

document.addEventListener('keydown', function(e){
    if(e.key === 'Escape'){
        var panel = document.getElementById('indexSheetPanel');
        if(!panel || !panel.classList.contains('show')) return;
        var track = document.getElementById('indexSheetTrack');
        if(track && track.classList.contains('show-detail')){ idxSheetBackToList(); }
        else { closeIndexSheet(); }
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
            <div class="dzrpt-cat-sub">Memes, posts or comments that break the rules</div>
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
    .dzrpt-success-icon  { width: 70px; height: 70px; font-size: 28px; margin-bottom: 18px; }
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
            // opened directly against a piece of content (e.g. kebab sheet "Report")
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
        // if the user picked "Reporting Content" from the generic category
        // screen (no contentId/contentType supplied up front), default to meme
        // so report_content.php always receives a valid content_type
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

<!-- ═══════════════════════════════════════════════════════
     MEME CARD CAPTION MODAL — REELS/TIKTOK/IG STYLE
     Full-bleed media, right action rail, bottom info panel
     UPDATED v5:
       - NEW: Track/Untrack button restyled — red underline,
         no border/background, font size bumped up to sit
         almost as large as the username next to it.
       - NEW: Premium skeleton loader — every slide (post, ad)
         shows a shimmering placeholder (media rect, avatar
         circle, text lines, rail icon dots) the instant it
         renders, and fades out the moment the real media is
         actually ready (onload / onloadedmetadata), with a
         short fail-safe timeout so it never gets stuck.
       - (carried over from v4) media-type tag below the search
         icon, persistent pause+mute controls while a video is
         paused, brief flash-icon on resume
       - (carried over from v3) full, uncropped media with
         blurred/zoomed filler removed in favor of plain black
         background, plain back/search icons, centered caption
         for text-only posts, left-side social proof strip,
         restructured user row with nickname under username
     ═══════════════════════════════════════════════════════ -->
<!-- MEME CARD CAPTION MODAL -->
<div id="cardCaptionModal" onclick="if(event.target===this) closeCardCaptionModal()">
    <div id="ccm-stage">
        <button type="button" id="ccmFixedBackBtn" class="ccm-icon-btn ccm-back-btn"
                onclick="event.stopPropagation();ccmHandleBackTap()">
            <i class="ri-arrow-left-s-line"></i>
        </button>
        <!-- #ccm-vtrack (vertical feed) and #ccm-hstack (Connected Zeem
             horizontal layers) are both built/appended into this by JS -->
        <div id="ccm-inner"></div>
    </div>

    <!-- desktop-only up/down navigation (no touchscreen needed) -->
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
    will-change:transform; /* promotes stage to its own layer */
}

@media(min-width:791px){
    #ccm-stage{
        height:92vh;
        max-height:940px;
        border-radius:22px;
        box-shadow:0 25px 90px rgba(0,0,0,.65), 0 0 0 1px rgba(255,255,255,.06);
        /* clip-path takes over the rounded clip instead of relying
           solely on border-radius + overflow, which is what leaks
           video content past the mask in Chrome */
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
    padding:0 12px;   /* was 0 16px — smaller = closer to the edge */
    box-sizing:border-box;
}
.ccm-dash{
    flex:1 1 auto; max-width:400px; min-width:6px; height:3px; border-radius:2px;
    background:rgba(255,255,255,.32);
    transition:background .2s ease, transform .2s ease, max-width .2s ease;
}
.ccm-dash.active{
    background:#ff3b30;
}


#ccmFixedBackBtn{
    position:absolute;
    top:14px;
    left:4px;
    z-index:50; /* above skeleton(15), rail(9), top-bar(8), bottom(7) */
}
@media (max-width:490px){
    #ccmFixedBackBtn{ top:11px; left:3px; }
}
/* ══════════════════════════════════════════════════════════
   VIDEO PROGRESS BAR
   ══════════════════════════════════════════════════════════ */
.ccm-video-progress{
    position:absolute; bottom:0; left:0; right:0;
    height:26px;
    z-index:11;                 /* escapes the gallery's transform stacking context */
    display:none;                /* toggled by JS based on active media type */
    align-items:flex-end;
    padding:0 4px 6px;
    box-sizing:border-box;
    touch-action:none;
    cursor:pointer;
}
.ccm-video-progress-track{
    position:relative; width:100%; height:4px;
    border-radius:3px;
    background:rgba(255,255,255,.35);
    transition:height .15s ease;
}
.ccm-video-progress.scrubbing .ccm-video-progress-track{ height:6px; }
.ccm-video-progress-fill{
    position:absolute; left:0; top:0; bottom:0;
    width:0%; border-radius:3px;
    background:linear-gradient(90deg,#ff2b2b,#ff6b5f);
    box-shadow:0 0 6px rgba(255,43,43,.7);
}
.ccm-video-progress-thumb{
    position:absolute; top:50%;
    width:13px; height:13px; border-radius:50%;
    background:#fff; border:2px solid #ff2b2b;
    box-shadow:0 1px 5px rgba(0,0,0,.5);
    transform:translate(-50%,-50%) scale(0);
    transition:transform .15s ease;
    pointer-events:none;
}
.ccm-video-progress.scrubbing .ccm-video-progress-thumb{ transform:translate(-50%,-50%) scale(1); }

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
#ccm-repost-btn.arc-swap-active i {
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
/* base / desktop rule */
.ccm-social-proof-strip.ccm-sp-raised{ bottom:195px; } 
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

/* ── AI GENERATED TAG ──────────────────────────────────────
   Sits in the group-tag's slot by default. When a group tag
   is also present, .ccm-ai-tag-under-group pushes it down
   below the group tag instead of overlapping it. ── */
.ccm-ai-tag{
    position:absolute; top:80px; left:16px; z-index:8;
    display:flex; align-items:center; gap:5px;
    background:rgba(59,130,246,.18);
    border:1px solid rgba(59,130,246,.35);
    border-radius:6px; padding:5px 9px 5px 7px;
    font-size:11px; font-weight:600; color:#fff;
    backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px);
    white-space:nowrap;
}
.ccm-ai-tag i{ font-size:12.5px; color:#7fb2ff; }
.ccm-ai-tag.ccm-ai-tag-under-group{ top:112px; }

/* ── AD RAIL LABEL — replaces the rail-msg profile-pic button
   on ad posts, same footprint as .ccm-rail-msg-pic. ── */
.ccm-rail-ad-label{
    width:30px; height:30px;
    border-radius:10px;
    background:rgba(0,0,0,.45);
    border:2px solid rgba(255,255,255,.5);
    display:flex; align-items:center; justify-content:center;
    color:#fff; font-size:10px; font-weight:800; letter-spacing:.4px;
    box-shadow:0 3px 8px rgba(0,0,0,.55);
    flex-shrink:0;
}

/* ── CTA PILL — occupies the social-proof strip's exact slot
   on ad posts (they never appear together). Cycles small
   ↔ expanded via JS toggling .ccm-cta-pill-expanded. ── */
/* ── CTA PILL — occupies the social-proof strip's exact slot
   on ad posts (they never appear together). Cycles small
   ↔ expanded via JS toggling .ccm-cta-pill-expanded. Expanded
   state anchors both left+right so it stretches to just short
   of the action rail, text starting flush at the left edge. ── */
.ccm-cta-pill{
    position:absolute; left:16px; bottom:175px; z-index:6;
    display:flex; align-items:center; justify-content:flex-start;
    padding:9px 14px;
    border-radius:8px;
    background:rgba(30,30,34,.55);
    border:1px solid rgba(255,255,255,.28);
    color:#fff;
    font-weight:700;
    font-size:12px;
    text-decoration:none;
    box-shadow:0 4px 14px rgba(0,0,0,.3);
    white-space:nowrap;
    overflow:hidden;
    width:auto;
    right:auto;
    transition:right .4s cubic-bezier(.22,1,.36,1), padding .4s cubic-bezier(.22,1,.36,1),
               background .4s ease, border-color .4s ease, box-shadow .4s ease;
}
.ccm-cta-pill.ccm-sp-raised{ bottom:195px; }
.ccm-cta-pill-text{
    position:relative; z-index:1;
    overflow:hidden; text-overflow:ellipsis;
}
.ccm-cta-pill-chevron{
    display:inline-block;
    font-size:0;
    opacity:0;
    max-width:0;
    overflow:hidden;
    margin-left:0;
    flex-shrink:0;
    transition:opacity .3s ease, max-width .3s ease, margin-left .3s ease, font-size .3s ease;
}
.ccm-cta-pill.ccm-cta-pill-expanded{
    right:74px;
    padding:13px 18px;
    background:var(--cta-color, #FF4C4C);
    border-color:transparent;
    box-shadow:0 6px 22px rgba(0,0,0,.45);
}
.ccm-cta-pill.ccm-cta-pill-expanded .ccm-cta-pill-chevron{
    opacity:1;
    max-width:20px;
    margin-left:auto;
    padding-left:8px;
    font-size:16px;
}

/* ══════════════════════════════════════════════════════════
   CONNECTED ZEEM — HORIZONTAL LAYER STACK
   #ccm-hstack sits as a full-bleed overlay INSIDE #ccm-inner
   (which is position:relative), stacked above #ccm-vtrack.
   Each .ccm-hlayer is itself a full-bleed absolute box so the
   connected-Zeem slide genuinely overlays and slides in on top
   of the current post, instead of pushing layout around in
   normal document flow.
   ══════════════════════════════════════════════════════════ */
#ccm-hstack{
    position:absolute;
    inset:0;
    z-index:12;
    overflow:hidden;
}

.ccm-hlayer{
    position:absolute;
    inset:0;
    width:100%;
    height:100%;
    background:#000;
    will-change:transform;
}

/* ══════════════════════════════════════════════════════════
   CONNECTED ZEEM PILL — occupies the same bottom-left slot as
   .ccm-social-proof-strip / .ccm-cta-pill (mutually exclusive
   with both — a post is never more than one of: has social
   proof shown, is an ad, or links to a connected Zeem).
   Tapping it slides the whole slide horizontally to the
   connected post (separate from vertical feed swipe and the
   horizontal in-post media gallery swipe).

   Rectangular (not pill-shaped), thin, dark grey — single
   solid dark grey tone for background and border, no
   translucent light glass look, no circular icon backdrop.
   ══════════════════════════════════════════════════════════ */
.ccm-connected-tag{
    position:absolute; left:16px; bottom:175px; z-index:6;
    display:flex; align-items:center; gap:8px;
    padding:4px 13px 4px 10px;
    border-radius:8px;
    background:rgba(20,20,23,.96);
    border:1px solid rgba(20,20,23,.96);
    box-shadow:0 6px 18px rgba(0,0,0,.4);
    cursor:pointer;
    -webkit-tap-highlight-color:transparent;
    transition:background .18s ease, transform .15s ease, box-shadow .18s ease, opacity .22s ease;
    max-width:calc(100% - 32px);
    text-decoration:none;
}
.ccm-connected-tag:hover{
    background:rgba(30,30,34,.98);
    border-color:rgba(30,30,34,.98);
    box-shadow:0 8px 22px rgba(0,0,0,.5);
}
.ccm-connected-tag:active{
    transform:scale(.96);
}
.ccm-connected-tag.ccm-sp-raised{ bottom:195px; }
.ccm-connected-tag.ccm-sp-hidden{
    opacity:0;
    transform:translateX(10px);
    pointer-events:none;
}

/* Sits below the CTA pill when a post is both an ad and
   connected — dropped further down so it never touches the
   CTA pill above it. */
.ccm-connected-tag.ccm-connected-below-cta{ bottom:130px; }
.ccm-connected-tag.ccm-connected-below-cta.ccm-sp-raised{ bottom:150px; }

.ccm-connected-icon{
    display:flex; align-items:center; justify-content:center;
    flex-shrink:0;
}
.ccm-connected-icon i{
    font-size:15px; color:#fff;
}

.ccm-connected-text{
    display:flex; align-items:baseline; gap:5px;
    min-width:0;
    overflow:hidden;
    white-space:nowrap;
}
.ccm-connected-text-main{
    font-size:12.5px; font-weight:700; color:#fff;
    letter-spacing:.1px;
    flex-shrink:0;
}
.ccm-connected-text-dot{
    color:rgba(255,255,255,.4);
    font-size:11px;
    flex-shrink:0;
}
.ccm-connected-text-label{
    font-size:12px; font-weight:500; color:rgba(255,255,255,.72);
    overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
    min-width:0;
}

/* ══════════════════════════════════════
   RESPONSIVE — 790 / 490 / 390 / 330
   (mirrors .ccm-social-proof-strip / .ccm-cta-pill exactly)
   ══════════════════════════════════════ */
@media (max-width:790px){
    .ccm-connected-tag{ left:14px; bottom:162px; padding:3.5px 11px 3.5px 9px; gap:7px; border-radius:7px; }
    .ccm-connected-tag.ccm-sp-raised{ bottom:180px; }
    .ccm-connected-tag.ccm-connected-below-cta{ bottom:117px; }
    .ccm-connected-tag.ccm-connected-below-cta.ccm-sp-raised{ bottom:135px; }
    .ccm-connected-icon i{ font-size:12px; }
    .ccm-connected-text-main{ font-size:11.5px; }
    .ccm-connected-text-dot{ font-size:10px; }
    .ccm-connected-text-label{ font-size:11px; }
}

@media (max-width:490px){
    .ccm-connected-tag{ left:12px; bottom:140px; padding:3px 10px 3px 8px; gap:6px; border-radius:7px; }
    .ccm-connected-tag.ccm-sp-raised{ bottom:156px; }
    .ccm-connected-tag.ccm-connected-below-cta{ bottom:95px; }
    .ccm-connected-tag.ccm-connected-below-cta.ccm-sp-raised{ bottom:111px; }
    .ccm-connected-icon i{ font-size:11px; }
    .ccm-connected-text-main{ font-size:11px; }
    .ccm-connected-text-dot{ font-size:9.5px; }
    .ccm-connected-text-label{ font-size:10.5px; }
}

@media (max-width:390px){
    .ccm-connected-tag{ left:10px; bottom:125px; padding:3px 9px 3px 7px; gap:5.5px; border-radius:6px; }
    .ccm-connected-tag.ccm-sp-raised{ bottom:139px; }
    .ccm-connected-tag.ccm-connected-below-cta{ bottom:80px; }
    .ccm-connected-tag.ccm-connected-below-cta.ccm-sp-raised{ bottom:94px; }
    .ccm-connected-icon i{ font-size:10px; }
    .ccm-connected-text-main{ font-size:10.5px; }
    .ccm-connected-text-dot{ font-size:9px; }
    .ccm-connected-text-label{ font-size:10px; }
}

@media (max-width:330px){
    .ccm-connected-tag{ left:9px; bottom:110px; padding:2.5px 8px 2.5px 6px; gap:5px; border-radius:6px; }
    .ccm-connected-tag.ccm-sp-raised{ bottom:122px; }
    .ccm-connected-tag.ccm-connected-below-cta{ bottom:65px; }
    .ccm-connected-tag.ccm-connected-below-cta.ccm-sp-raised{ bottom:77px; }
    .ccm-connected-icon i{ font-size:9px; }
    .ccm-connected-text-main{ font-size:10px; }
    .ccm-connected-text-dot{ font-size:8.5px; }
    .ccm-connected-text-label{ font-size:9.5px; }
}
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
    .ccm-ai-tag{ top:73px; left:14px; font-size:10.5px; padding:4px 7px 4px 5px; }
    .ccm-ai-tag i{ font-size:12px; }
    .ccm-ai-tag.ccm-ai-tag-under-group{ top:103px; }
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
    .ccm-rail-ad-label{ width:28px; height:28px; border-radius:9px; font-size:9px; }
    .ccm-rail-count{ font-size:12px; }
    .ccm-bottom{ right:0; padding:16px 70px 20px 14px; }
    .ccm-bottom.ccm-no-rail{ padding-right:14px; }
    .ccm-social-proof-strip{ left:14px; bottom:162px; }
    .ccm-social-proof-strip.ccm-sp-raised{ bottom:180px; }
    .ccm-cta-pill{ left:14px; bottom:162px; font-size:11.5px; padding:8px 13px; }
    .ccm-cta-pill.ccm-sp-raised{ bottom:180px; }
    .ccm-cta-pill.ccm-cta-pill-expanded{ right:66px; padding:11px 16px; }
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
    .ccm-ai-tag{ top:66px; left:12px; font-size:10px; padding:3.5px 6.5px 3.5px 5px; max-width:calc(100% - 76px); }
    .ccm-ai-tag i{ font-size:11px; }
    .ccm-ai-tag.ccm-ai-tag-under-group{ top:94px; }
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
    .ccm-rail-ad-label{ width:26px; height:26px; border-radius:8px; border-width:1.5px; font-size:8.5px; }
    .ccm-rail-count{ font-size:11.5px; }
    .ccm-bottom{ right:0; padding:13px 62px 18px 12px; }
    .ccm-bottom.ccm-no-rail{ padding-right:12px; }
    .ccm-social-proof-strip{ left:12px; bottom:140px; }
    .ccm-social-proof-strip.ccm-sp-raised{ bottom:156px; }
    .ccm-cta-pill{ left:12px; bottom:140px; font-size:11px; padding:7px 12px; }
    .ccm-cta-pill.ccm-sp-raised{ bottom:156px; }
    .ccm-cta-pill.ccm-cta-pill-expanded{ right:58px; padding:10px 14px; }
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
    .ccm-ai-tag{ top:60px; left:10px; font-size:9.5px; padding:3px 6px 3px 4.5px; max-width:calc(100% - 66px); }
    .ccm-ai-tag i{ font-size:10.5px; }
    .ccm-ai-tag.ccm-ai-tag-under-group{ top:86px; }
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
    .ccm-rail-ad-label{ width:24px; height:24px; border-radius:7px; border-width:1.5px; font-size:8px; }
    .ccm-rail-count{ font-size:11px; }
    .ccm-bottom{ right:0; padding:11px 56px 16px 10px; }
    .ccm-bottom.ccm-no-rail{ padding-right:10px; }
    .ccm-social-proof-strip{ left:10px; bottom:125px; }
    .ccm-cta-pill{ left:10px; bottom:125px; font-size:10.5px; padding:7px 11px; }
    .ccm-cta-pill.ccm-sp-raised{ bottom:139px; }
    .ccm-cta-pill.ccm-cta-pill-expanded{ right:52px; padding:9px 13px; }
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
    .ccm-ai-tag{ top:56px; left:9px; font-size:9px; padding:3px 5.5px 3px 4px; max-width:calc(100% - 60px); }
    .ccm-ai-tag i{ font-size:10px; }
    .ccm-ai-tag.ccm-ai-tag-under-group{ top:80px; }
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
    .ccm-rail-ad-label{ width:22px; height:22px; border-radius:6px; border-width:1.5px; font-size:7.5px; }
    .ccm-rail-count{ font-size:10.5px; }
    .ccm-bottom{ right:0; padding:10px 50px 14px 9px; }
    .ccm-bottom.ccm-no-rail{ padding-right:9px; }
    .ccm-social-proof-strip{ left:9px; bottom:110px; }
    .ccm-cta-pill{ left:9px; bottom:110px; font-size:10px; padding:6px 10px; }
    .ccm-cta-pill.ccm-sp-raised{ bottom:122px; }
    .ccm-cta-pill.ccm-cta-pill-expanded{ right:46px; padding:8px 12px; }
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

/* ── FIX: Visit Brand button — sits just above the video
   progress bar (26px tall) without climbing into the caption. ── */
/* ── FIX: Visit Brand button — sits just above the video
   progress bar (26px tall) without climbing into the caption. ── */
.ccm-visit-brand-btn{ bottom:24px; }

@media (max-width:790px){
    .ccm-bottom-ad{ padding-bottom:78px; }
    #ccm-ad-rail{ bottom:72px; }
    .ccm-visit-brand-btn{ left:14px; right:14px; bottom:21px; padding:10px 0; font-size:13px; border-radius:14px; }
}
@media (max-width:490px){
    .ccm-bottom-ad{ padding-bottom:68px; }
    #ccm-ad-rail{ bottom:64px; }
    .ccm-visit-brand-btn{ left:12px; right:12px; bottom:18px; padding:9px 0; font-size:12.5px; border-radius:13px; }
}
@media (max-width:390px){
    .ccm-bottom-ad{ padding-bottom:60px; }
    #ccm-ad-rail{ bottom:58px; }
    .ccm-visit-brand-btn{ left:10px; right:10px; bottom:15px; padding:8px 0; font-size:12px; border-radius:12px; }
}
@media (max-width:330px){
    .ccm-bottom-ad{ padding-bottom:54px; }
    #ccm-ad-rail{ bottom:52px; }
    .ccm-visit-brand-btn{ left:9px; right:9px; bottom:12px; padding:7px 0; font-size:11.5px; border-radius:11px; }
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
   CARD CAPTION MODAL — ported onto the same gapless vertical
   track engine as the GF/meme modal. #ccm-inner hosts a
   #ccm-vtrack containing up to 3 .ccm-vslide wrappers (prev/
   current/next), each fully rendered before you ever swipe.
   Dragging moves the whole track by the raw touch delta so the
   neighbor is visibly there under your finger — no gap, no
   rebuild-on-release. On a committed swipe the already-mounted
   neighbor is promoted to "current" (its media element reused
   as-is, already decoded); only the vacant side gets a fresh
   silent preview slide built in the background. Chrome dims/
   drifts during drag and snaps to full brightness on landing.
   Ads, suggested-user carousels and the group tag are unchanged
   feature-for-feature — only the navigation/audio-continuity
   engine underneath is new.

   NEW IN THIS VERSION:
   - "Saved · Collections" toast on favoriting a POST (mirrors
     profile.php's ccmAction fav-branch behavior exactly).
   - Double-tap-to-like now works on ADS too, not just posts —
     ccmDoubleTapLike() checks the current feed item's type and
     routes to ccmAdLikeAction() for ads, ccmAction('like') for
     posts. The heart-burst animation was already type-agnostic.
   ════════════════════════════════════════════════════════════ */
var _ccmIndex             = 0;
var _ccmMemeId            = null;
var _ccmMediaIndex        = 0;
var ccmFeed                = [];
var _ccmCaptionFullHtml   = '';
var _ccmCaptionTruncHtml  = '';
var _ccmCaptionExpanded   = false;
var _ccmInfoSwitchTimer   = null;
var _ccmCtaPillTimer      = null;
var _ccmVSwipeLocked      = false;

var _ccmPausedMemeIds    = {};
var _ccmMediaIndexByPost = {};
var _ccmTrackPositions   = {};

/* ══ CONNECTED ZEEM — horizontal layer stack state ══ */
var _ccmHStack             = [];
var _ccmConnectedCache     = {};
var _ccmConnectedFetchLock = false;

const currentUserId = <?php echo $current_user; ?>;

function _ccmComputeDashGap(count){
    return Math.max(3, Math.min(6, Math.round(60 / count)));
}

/* ── SAVED TOAST ── */
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
        '.ccm-saved-toast-right i{ font-size:17px; }';
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
    if(typeof openSaveModal === 'function'){
        openSaveModal(memeId);
    }
}

/* ── DATA LOADING / FEED BUILD ── */
function _loadCcmData(){
    window._suggPromise.then(function(sugg){
        window.cmSuggUsers = sugg || [];
        _buildCcmFeed();
    });
}

function _buildCcmFeed(){
    ccmFeed = [];
    var suggIdx = 0;
    var sugg = window.cmSuggUsers || [];
    peuraFeed.forEach(function(post, i){
        ccmFeed.push({ type:'post', data:post });
        if((i+1) % 7 === 0 && sugg.length > 0){
            var maxSlice = Math.min(16, sugg.length);
            var slice = [];
            for(var j=0; j<maxSlice; j++) slice.push(sugg[(suggIdx+j) % sugg.length]);
            ccmFeed.push({ type:'sugg', data:slice });
            suggIdx = (suggIdx + maxSlice) % sugg.length;
        }
    });
}

/* ── HELPERS ── */
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
function _ccmWordCount(str){
    if(!str) return 0;
    var trimmed = str.trim();
    if(trimmed === '') return 0;
    return trimmed.split(/\s+/).length;
}
function _ccmEscapeHtml(str){
    if(!str) return '';
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}
function _ccmTypeTagInfo(rawType){
    var type = (rawType || '').toLowerCase();
    var map = {
        image:    { icon:'ri-file-copy-line', label:'Photo'   },
        video:    { icon:'ri-vidicon-line',   label:'Video'   },
        gif:      { icon:'ri-file-gif-line',  label:'GIF'     },
        sticker:  { icon:'ri-shapes-line',    label:'Sticker' },
        stickers: { icon:'ri-shapes-line',    label:'Sticker' },
        text:     { icon:'ri-file-text-line', label:'Text'    }
    };
    return map[type] || { icon:'ri-file-copy-line', label:'Photo' };
}

function linkifyCaption(caption){
    if(!caption) return '';
    return caption
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
    var media = (Array.isArray(p.media) && p.media.length) ? p.media.slice() : [{file:p.file, type:p.type}];
    var mediaSet  = ['image','video','gif','sticker','stickers'];
    var firstType = (media[0].type || '').toLowerCase();
    var isTextOnly = media.length === 1 && mediaSet.indexOf(firstType) === -1;

    var captionHtml = linkifyCaption(p.caption || '');
    var full = captionHtml, trunc = captionHtml;

    if(isTextOnly){
        var cWords = (p.caption || '').trim().split(/\s+/).filter(Boolean);
        if(cWords.length > 40){
            var cTrunc = cWords.slice(0, 40).join(' ');
            trunc = linkifyCaption(cTrunc)+
                '<span class="ccm-more-toggle" onclick="event.stopPropagation();ccmToggleCenterCaption()">'+
                    '<span class="ccm-ellipsis">...</span>more'+
                '</span>';
        }
    } else {
        var words = (p.caption || '').trim().split(/\s+/).filter(Boolean);
        if(words.length > 10){
            var truncPlain = words.slice(0, 10).join(' ');
            trunc = linkifyCaption(truncPlain)+
                '<span class="ccm-more-toggle" onclick="event.stopPropagation();ccmToggleCaption()">'+
                    '<span class="ccm-ellipsis">...</span>more'+
                '</span>';
        }
    }
    return { full:full, trunc:trunc, isTextOnly:isTextOnly };
}

/* ── CAPTION EXPAND / COLLAPSE ── */
function ccmUpdateSocialProofVisibility(){
    var slideEl = document.getElementById('ccm-slide');
    var sp = slideEl ? slideEl.querySelector('#ccm-social-proof') : null;
    if(!sp) return;
    sp.classList.toggle('ccm-sp-hidden', _ccmCaptionExpanded);
}
function _ccmIsInsideExpandedCaption(target){
    if(!_ccmCaptionExpanded || !target || !target.closest) return false;
    return !!(target.closest('.ccm-caption-wrap') || target.closest('.ccm-center-caption-wrap'));
}
function _ccmCheckCaptionOverflow(slide){
    if(!slide) return;
    var wrap = slide.querySelector('.ccm-center-caption-wrap');
    var text = slide.querySelector('.ccm-center-caption-text');
    if(!wrap || !text) return;
    var isOverflowing = text.scrollHeight > wrap.clientHeight;
    wrap.classList.toggle('ccm-caption-overflow', isOverflowing);
}
function ccmToggleCaption(){
    var slideEl = document.getElementById('ccm-slide');
    if(!slideEl) return;
    var textEl = slideEl.querySelector('#ccm-caption-text');
    var wrapEl = slideEl.querySelector('#ccm-caption-wrap');
    if(!textEl) return;
    _ccmCaptionExpanded = !_ccmCaptionExpanded;
    if(_ccmCaptionExpanded){
        textEl.innerHTML = _ccmCaptionFullHtml+
            ' <span class="ccm-more-toggle" onclick="event.stopPropagation();ccmToggleCaption()"> see less</span>';
        if(wrapEl) wrapEl.classList.add('ccm-expanded');
    } else {
        textEl.innerHTML = _ccmCaptionTruncHtml;
        if(wrapEl){ wrapEl.classList.remove('ccm-expanded'); wrapEl.scrollTop = 0; }
    }
    ccmUpdateSocialProofVisibility();
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
    var slide = wrapEl ? wrapEl.closest('.ccm-slide') : null;
    if(slide) _ccmCheckCaptionOverflow(slide);
}

/* ── SKELETON ── */
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
function ccmHideSkeleton(root){
    var sk = root ? root.querySelector('#ccm-skeleton') : document.getElementById('ccm-skeleton');
    if(!sk) return;
    sk.classList.add('ccm-skeleton-hide');
    setTimeout(function(){ if(sk && sk.parentNode) sk.remove(); }, 340);
}

/* ── SWITCHERS ── */
function _ccmInitAudioMarquee(slide){
    if(!slide) return;
    var track = slide.querySelector('#ccm-audio-track');
    var text  = slide.querySelector('#ccm-audio-track-text');
    if(!track || !text) return;
    requestAnimationFrame(function(){
        var overflow = text.scrollWidth - track.clientWidth;
        if(overflow > 4){
            text.classList.add('ccm-marquee');
            text.style.setProperty('--marquee-dist', (-overflow - 6)+'px');
            var dur = Math.max(4.5, Math.min(14, overflow / 22));
            text.style.setProperty('--marquee-dur', dur+'s');
        }
    });
}
function _ccmInitInfoSwitcher(slide){
    if(_ccmInfoSwitchTimer){ clearInterval(_ccmInfoSwitchTimer); _ccmInfoSwitchTimer = null; }
    if(!slide) return;
    var switcher = slide.querySelector('#ccm-info-switcher');
    if(!switcher) return;
    var panes = switcher.querySelectorAll('.ccm-switch-pane');
    if(panes.length < 2) return;
    var activeIdx = 0;
    panes.forEach(function(p, i){ p.classList.toggle('active', i === 0); });
    _ccmInfoSwitchTimer = setInterval(function(){
        panes[activeIdx].classList.remove('active');
        activeIdx = (activeIdx + 1) % panes.length;
        panes[activeIdx].classList.add('active');
    }, 5000);
}

/* ── CTA PILL CYCLE (ad posts) ──────────────────────────────
   Toggles the small CTA pill between its default small state
   and an expanded state (showing the chevron) every 3s, on
   loop, for as long as this slide is active. ── */
function _ccmInitCtaPillCycle(slide){
    if(_ccmCtaPillTimer){ clearInterval(_ccmCtaPillTimer); _ccmCtaPillTimer = null; }
    if(!slide) return;
    var pill = slide.querySelector('#ccm-cta-pill');
    if(!pill) return;
    pill.classList.remove('ccm-cta-pill-expanded');
    var expanded = false;
    _ccmCtaPillTimer = setInterval(function(){
        expanded = !expanded;
        pill.classList.toggle('ccm-cta-pill-expanded', expanded);
    }, 3000);
}

/* ── ATTACHED SOUNDTRACK ── */
function _ccmEnsureSoundtrackEl(){
    var stage = document.getElementById('ccm-stage');
    if(!stage) return null;
    var el = document.getElementById('ccmSoundtrackPlayer');
    if(!el){
        el = document.createElement('audio');
        el.id = 'ccmSoundtrackPlayer';
        el.loop = true;
        el.style.display = 'none';
        stage.appendChild(el);
    }
    return el;
}

function _ccmSyncSoundtrack(item){
    var audioEl = _ccmEnsureSoundtrackEl();
    if(!audioEl) return;

    var prevMemeId = audioEl.dataset.memeId || null;
    var p = (item && item.type === 'post') ? item.data : null;

    if(!p || !p.audioFile){
        if(prevMemeId) _ccmTrackPositions[prevMemeId] = audioEl.currentTime || 0;
        audioEl.pause();
        audioEl.removeAttribute('src');
        delete audioEl.dataset.memeId;
        audioEl.load();
        return;
    }

    var isSamePost = prevMemeId !== null && String(prevMemeId) === String(p.id);

    if(!isSamePost){
        if(prevMemeId) _ccmTrackPositions[prevMemeId] = audioEl.currentTime || 0;
        audioEl.src = p.audioFile;
        audioEl.dataset.memeId = p.id;
        audioEl.currentTime = _ccmTrackPositions[p.id] || 0;
        audioEl.muted = false;
    }
    audioEl.volume = (p.musicVolume !== undefined && p.musicVolume !== null ? p.musicVolume : 80) / 100;

    var curSlide = document.getElementById('ccm-slide');
    var mb = curSlide ? curSlide.querySelector('#ccm-media-box') : null;

    if(_ccmPausedMemeIds[p.id]){
        audioEl.pause();
        if(mb){
            var activeItemForPause = mb.querySelector('.ccm-media-item[data-idx="'+_ccmMediaIndex+'"]') || mb;
            ccmShowPausedControls(activeItemForPause, audioEl);
        }
    } else {
        audioEl.play().catch(function(){});
    }

    var videoAudioVol = (p.videoAudioVolume !== undefined && p.videoAudioVolume !== null ? p.videoAudioVolume : 40) / 100;
    document.querySelectorAll('.ccm-vslide[data-pos="current"] video.ccm-media').forEach(function(v){
        v.volume = videoAudioVol;
    });
}

function _ccmPauseSoundtrack(){ var el = document.getElementById('ccmSoundtrackPlayer'); if(el) el.pause(); }
function _ccmResumeSoundtrack(){ var el = document.getElementById('ccmSoundtrackPlayer'); if(el && el.src) el.play().catch(function(){}); }
function _ccmStopSoundtrack(){
    var el = document.getElementById('ccmSoundtrackPlayer');
    if(el){
        var leavingMemeId = el.dataset.memeId || null;
        if(leavingMemeId) _ccmTrackPositions[leavingMemeId] = el.currentTime || 0;
        el.pause();
        el.removeAttribute('src');
        delete el.dataset.memeId;
        el.load();
    }
}

/* ══════════════════════════════════════════════════════════
   VIDEO PROGRESS — reads/writes the single bar that sits as a
   sibling of .ccm-bottom (scoped to .ccm-slide, not the
   transformed .ccm-media-gallery), and skips updating while
   the user is actively dragging the thumb.
══════════════════════════════════════════════════════════ */
function ccmUpdateProgress(video){
    if(!video.duration || isNaN(video.duration)) return;
    var slide = video.closest('.ccm-slide');
    var bar = slide ? slide.querySelector('.ccm-video-progress') : null;
    if(!bar || bar.classList.contains('scrubbing')) return;
    var pct = video.currentTime / video.duration * 100;
    var fill  = bar.querySelector('.ccm-video-progress-fill');
    var thumb = bar.querySelector('.ccm-video-progress-thumb');
    if(fill)  fill.style.width = pct+'%';
    if(thumb) thumb.style.left = pct+'%';
}

/* ══════════════════════════════════════════════════════════
   DRAG-TO-SEEK on the progress bar. Pauses/scrubs both the
   active gallery video AND the attached soundtrack (if playing
   for the current post) in lockstep, so audio stays synced to
   whatever frame you drag to.
══════════════════════════════════════════════════════════ */
function ccmInitProgressScrub(bar){
    if(!bar || bar._ccmScrubBound) return;
    bar._ccmScrubBound = true;

    var track = bar.querySelector('.ccm-video-progress-track');
    var fill  = bar.querySelector('.ccm-video-progress-fill');
    var thumb = bar.querySelector('.ccm-video-progress-thumb');

    function getMedia(){
        var slide = bar.closest('.ccm-slide');
        var video = slide ? (
            slide.querySelector('.ccm-media-item[data-idx="'+_ccmMediaIndex+'"] video.ccm-media')
            || slide.querySelector('video.ccm-media')
        ) : null;
        var trackEl = document.getElementById('ccmSoundtrackPlayer');
        var hasTrack = trackEl && trackEl.src && trackEl.dataset.memeId && String(trackEl.dataset.memeId) === String(_ccmMemeId);
        return { video: video, audio: hasTrack ? trackEl : null };
    }
    function pctFromX(x){
        var r = track.getBoundingClientRect();
        return Math.max(0, Math.min(1, (x - r.left) / r.width));
    }
    function paint(pct){
        fill.style.width = (pct*100)+'%';
        thumb.style.left = (pct*100)+'%';
    }
    function seekTo(pct){
        var m = getMedia();
        if(m.video && !isNaN(m.video.duration)) m.video.currentTime = pct * m.video.duration;
        if(m.audio && !isNaN(m.audio.duration))  m.audio.currentTime = pct * m.audio.duration;
    }

    var dragging = false;

    function start(x){
        dragging = true;
        bar.classList.add('scrubbing');
        var m = getMedia();
        if(m.video) m.video.pause();
        if(m.audio) m.audio.pause();
        paint(pctFromX(x));
    }
    function move(x){
        if(!dragging) return;
        var pct = pctFromX(x);
        paint(pct);
        seekTo(pct);
    }
    function end(x){
        if(!dragging) return;
        dragging = false;
        seekTo(pctFromX(x));
        bar.classList.remove('scrubbing');
        var wasManuallyPaused = _ccmMemeId ? !!_ccmPausedMemeIds[_ccmMemeId] : false;
        if(!wasManuallyPaused){
            var m = getMedia();
            if(m.video) m.video.play().catch(function(){});
            if(m.audio) m.audio.play().catch(function(){});
        }
    }

    bar.addEventListener('touchstart', function(e){ e.stopPropagation(); start(e.touches[0].clientX); }, {passive:true});
    bar.addEventListener('touchmove',  function(e){ e.stopPropagation(); move(e.touches[0].clientX);  }, {passive:true});
    bar.addEventListener('touchend',   function(e){ e.stopPropagation(); end(e.changedTouches[0].clientX); }, {passive:true});

    bar.addEventListener('mousedown', function(e){
        e.stopPropagation();
        start(e.clientX);
        function onMove(ev){ move(ev.clientX); }
        function onUp(ev){
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
            end(ev.clientX);
        }
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
    });

    bar.addEventListener('click', function(e){ e.stopPropagation(); });
}

/* ── TAP HANDLING ── */
function ccmHandleMediaTap(e, mediaBox, isGallery){
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
            if(isGallery) ccmMediaBoxTap(mediaBox);
            else ccmToggleVideoPlay(mediaBox);
        }, 260);
    }
}

function ccmDoubleTapLike(mediaBox, e){
    var rect = mediaBox.getBoundingClientRect();
    var cx = (e && typeof e.clientX === 'number' && e.clientX !== 0) ? e.clientX : (rect.left + rect.width/2);
    var cy = (e && typeof e.clientY === 'number' && e.clientY !== 0) ? e.clientY : (rect.top + rect.height/2);
    ccmShowHeartBurst(mediaBox, cx - rect.left, cy - rect.top);

    var item = ccmFeed[_ccmIndex];
    if(!item) return;

    var slideEl = document.getElementById('ccm-slide');

    if(item.type === 'post'){
        var likeBtn = slideEl ? slideEl.querySelector('#ccm-like-btn') : null;
        if(likeBtn && !likeBtn.classList.contains('active')){
            ccmAction('like');
        }
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

/* ── TAP TO PAUSE/RESUME + MUTE + 10s SEEK ── */
function ccmToggleVideoPlay(mediaBox){
    var video = mediaBox.querySelector('video.ccm-media');
    if(!video){
        var audioEl = document.getElementById('ccmSoundtrackPlayer');
        if(audioEl && audioEl.src){
            if(audioEl.paused){
                audioEl.play().catch(function(){});
                if(_ccmMemeId) delete _ccmPausedMemeIds[_ccmMemeId];
                ccmHidePausedControls(mediaBox);
                ccmFlashIcon(mediaBox,'ri-play-fill');
            } else {
                audioEl.pause();
                if(_ccmMemeId) _ccmPausedMemeIds[_ccmMemeId] = true;
                ccmShowPausedControls(mediaBox, audioEl);
            }
        }
        return;
    }
    if(video.paused){
        video.play();
        _ccmResumeSoundtrack();
        if(_ccmMemeId) delete _ccmPausedMemeIds[_ccmMemeId];
        ccmHidePausedControls(mediaBox);
        ccmFlashIcon(mediaBox, 'ri-play-fill');
    } else {
        video.pause();
        _ccmPauseSoundtrack();
        if(_ccmMemeId) _ccmPausedMemeIds[_ccmMemeId] = true;
        ccmShowPausedControls(mediaBox, video);
    }
}

function ccmMediaBoxTap(mediaBox){
    var activeItem = mediaBox.querySelector('.ccm-media-item[data-idx="'+_ccmMediaIndex+'"]');
    if(!activeItem) return;
    var video = activeItem.querySelector('video.ccm-media');

    if(video){
        if(video.paused){
            video.play();
            _ccmResumeSoundtrack();
            if(_ccmMemeId) delete _ccmPausedMemeIds[_ccmMemeId];
            ccmHidePausedControls(activeItem);
            ccmFlashIcon(activeItem, 'ri-play-fill');
        } else {
            video.pause();
            _ccmPauseSoundtrack();
            if(_ccmMemeId) _ccmPausedMemeIds[_ccmMemeId] = true;
            ccmShowPausedControls(activeItem, video);
        }
        return;
    }

    var audioEl = document.getElementById('ccmSoundtrackPlayer');
    if(audioEl && audioEl.src){
        if(audioEl.paused){
            audioEl.play().catch(function(){});
            if(_ccmMemeId) delete _ccmPausedMemeIds[_ccmMemeId];
            ccmHidePausedControls(activeItem);
            ccmFlashIcon(activeItem, 'ri-play-fill');
        } else {
            audioEl.pause();
            if(_ccmMemeId) _ccmPausedMemeIds[_ccmMemeId] = true;
            ccmShowPausedControls(activeItem, audioEl);
        }
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

    var trackEl  = document.getElementById('ccmSoundtrackPlayer');
    var hasTrack = trackEl && trackEl.src;
    var soundSrc = hasTrack ? trackEl : video;
    var isMuted  = soundSrc ? !!soundSrc.muted : false;

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
    var container = btn.closest('.ccm-media-box') || btn.closest('.ccm-media-item');
    var video    = container ? container.querySelector('video.ccm-media') : null;
    var trackEl  = document.getElementById('ccmSoundtrackPlayer');
    var hasTrack = trackEl && trackEl.src;
    var media    = hasTrack ? trackEl : video;
    if(!media) return;
    media.muted = !media.muted;
    var icon = btn.querySelector('i');
    icon.className = media.muted ? 'ri-volume-mute-fill' : 'ri-volume-up-fill';
}
function ccmSeekVideo(btn, delta){
    var container = btn.closest('.ccm-media-box') || btn.closest('.ccm-media-item');
    var video    = container ? container.querySelector('video.ccm-media') : null;
    var trackEl  = document.getElementById('ccmSoundtrackPlayer');
    var hasTrack = trackEl && trackEl.src;
    var media    = hasTrack ? trackEl : video;
    if(!media || isNaN(media.duration)) return;
    var newTime = media.currentTime + delta;
    if(newTime < 0) newTime = 0;
    if(media.duration && newTime > media.duration) newTime = media.duration;
    media.currentTime = newTime;
    if(media === video) ccmUpdateProgress(video);
}

/* ══════════════════ SMART OBJECT-FIT ══════════════════ */
function ccmFixObjectFit(el){
    var box = el.closest('.ccm-media-item') || el.closest('.ccm-media-box');
    if(!box) return;
    var mW = el.tagName === 'VIDEO' ? el.videoWidth  : el.naturalWidth;
    var mH = el.tagName === 'VIDEO' ? el.videoHeight : el.naturalHeight;
    if(!mW || !mH) return;
    var cW = box.clientWidth, cH = box.clientHeight;
    if(!cW || !cH) return;

    var mediaAspect = mW / mH;
    var boxAspect   = cW / cH;

    el.style.transform = ''; // clear any leftover transform from before

    if(mediaAspect < boxAspect){
        el.style.objectFit = 'cover';
        return;
    }

    var scaledHeight = cW / mediaAspect;
    var fillRatio    = scaledHeight / cH;

    var FILL_THRESHOLD = 0.84;

    if(fillRatio >= FILL_THRESHOLD){
        el.style.objectFit = 'fill';
    } else {
        el.style.objectFit = 'contain';
    }
}

/* ── TRACK BUTTON ── */
function ccmToggleTrack(btn){
    var userId = btn.dataset.user;
    fetch('home.php', {
        method:  'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body:    'action=toggle_follow&user_id='+userId
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        if(data.status === 'ok'){
            document.querySelectorAll('.ccm-track-btn[data-user="'+userId+'"]').forEach(function(fb){
                if(data.following){ fb.textContent='Untrack'; fb.classList.add('following'); }
                else               { fb.textContent='Track';  fb.classList.remove('following'); }
            });
            document.querySelectorAll('.track-btn[data-user="'+userId+'"]').forEach(function(fb){
                if(data.following){ fb.textContent='Untrack'; fb.classList.add('following'); }
                else               { fb.textContent='Track';  fb.classList.remove('following'); }
            });
        }
    });
}

/* ══════════════════════════════════════════════════════════
   MULTI-MEDIA GALLERY
══════════════════════════════════════════════════════════ */
function _ccmGetSavedMediaIndex(postId){
    if(postId === null || postId === undefined) return 0;
    var v = _ccmMediaIndexByPost[postId];
    return (typeof v === 'number' && v >= 0) ? v : 0;
}
function _ccmSaveMediaIndex(postId, idx){
    if(postId === null || postId === undefined) return;
    _ccmMediaIndexByPost[postId] = idx;
}

function ccmUpdateMediaGallery(slide, suppressAutoplay){
    var gallery = slide.querySelector('#ccm-media-gallery');
    if(!gallery) return;
    gallery.style.transform = 'translateX(-'+(_ccmMediaIndex*100)+'%)';

    gallery.querySelectorAll('.ccm-media-item').forEach(function(itemEl){
        var idx = parseInt(itemEl.dataset.idx, 10);
        var vid = itemEl.querySelector('video.ccm-media');
        if(vid){
            if(idx === _ccmMediaIndex){
                if(vid.preload !== 'auto'){ vid.preload = 'auto'; }
                if(suppressAutoplay){ vid.pause(); ccmShowPausedControls(itemEl, vid); }
                else { vid.play().catch(function(){}); }
            } else if(Math.abs(idx - _ccmMediaIndex) === 1){
                if(vid.preload !== 'auto'){ vid.preload = 'auto'; }
                vid.pause();
            } else {
                vid.pause();
            }
        }
        if(idx !== _ccmMediaIndex) ccmHidePausedControls(itemEl);
    });

    slide.querySelectorAll('.ccm-dash').forEach(function(d, i){
        d.classList.toggle('active', i === _ccmMediaIndex);
    });

    var counterEl = slide.querySelector('#ccm-media-counter');
    if(counterEl){
        var total = gallery.querySelectorAll('.ccm-media-item').length;
        counterEl.textContent = (_ccmMediaIndex+1)+'/'+total;
    }

    var activeItem = gallery.querySelector('.ccm-media-item[data-idx="'+_ccmMediaIndex+'"]');
    var typeTagEl  = slide.querySelector('#ccm-type-tag');
    if(activeItem && typeTagEl){
        var info = _ccmTypeTagInfo(activeItem.dataset.type);
        typeTagEl.innerHTML = '<i class="'+info.icon+'"></i><span>'+info.label+'</span>';
    }

    var progressBar = slide.querySelector('.ccm-video-progress');
    if(progressBar){
        var activeVid = activeItem ? activeItem.querySelector('video.ccm-media') : null;
        progressBar.style.display = activeVid ? 'flex' : 'none';
        ccmInitProgressScrub(progressBar);
    }
}

function ccmMediaNext(){
    var slide = document.getElementById('ccm-slide');
    if(!slide) return;
    var mediaBox = slide.querySelector('#ccm-media-box');
    if(!mediaBox) return;
    var count = parseInt(mediaBox.dataset.count || '1', 10);
    if(_ccmMediaIndex < count - 1){
        _ccmMediaIndex++;
        _ccmSaveMediaIndex(_ccmMemeId, _ccmMediaIndex);
        ccmUpdateMediaGallery(slide);
    }
}
function ccmMediaPrev(){
    var slide = document.getElementById('ccm-slide');
    if(!slide) return;
    if(_ccmMediaIndex > 0){
        _ccmMediaIndex--;
        _ccmSaveMediaIndex(_ccmMemeId, _ccmMediaIndex);
        ccmUpdateMediaGallery(slide);
    }
}

function _ccmInitMediaSwipe(slide){
    var gallery  = slide.querySelector('#ccm-media-gallery');
    var mediaBox = slide.querySelector('#ccm-media-box');
    if(!gallery || !mediaBox) return;
    if(mediaBox._ccmSwipeBound) return;
    var mediaCount = parseInt(mediaBox.dataset.count || '1', 10);
    if(mediaCount <= 1) return;
    mediaBox._ccmSwipeBound = true;

    var startX=0, startY=0, dragging=false, horizontal=false;

    mediaBox.addEventListener('touchstart', function(e){
        startX = e.touches[0].clientX; startY = e.touches[0].clientY;
        dragging = true; horizontal = false;
        gallery.style.transition = 'none';
    }, {passive:true});

    mediaBox.addEventListener('touchmove', function(e){
        if(!dragging) return;
        var dx = e.touches[0].clientX - startX;
        var dy = e.touches[0].clientY - startY;
        if(!horizontal && Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 8) horizontal = true;
        if(horizontal){
            e.stopPropagation();
            gallery.style.transform = 'translateX(calc(-'+(_ccmMediaIndex*100)+'% + '+dx+'px))';
        }
    }, {passive:true});

    mediaBox.addEventListener('touchend', function(e){
        if(!dragging) return;
        dragging = false;
        gallery.style.transition = 'transform .28s cubic-bezier(.22,1,.36,1)';
        if(horizontal){
            e.stopPropagation();
            var dx = e.changedTouches[0].clientX - startX;
            var count = parseInt(mediaBox.dataset.count || '1', 10);
            if(Math.abs(dx) > 50){
                if(dx < 0 && _ccmMediaIndex < count - 1) _ccmMediaIndex++;
                else if(dx > 0 && _ccmMediaIndex > 0) _ccmMediaIndex--;
            }
            _ccmSaveMediaIndex(_ccmMemeId, _ccmMediaIndex);
        }
        ccmUpdateMediaGallery(slide);
    }, {passive:true});
}

/* ── DESKTOP WHEEL + KEYBOARD NAV ── */
document.getElementById('cardCaptionModal').addEventListener('wheel', function(e){
    if(_ccmIsInsideExpandedCaption(e.target)) return;
    e.preventDefault();

    /* Connected-Zeem layer open — wheel navigation is locked
       while a linked post is showing (only the back button
       navigates out of it). */
    if(_ccmHStack.length > 0) return;

    var item = ccmFeed[_ccmIndex];

    if(item && item.type === 'sugg' && Math.abs(e.deltaX) > Math.abs(e.deltaY)){
        var slideElS = document.getElementById('ccm-slide');
        var suggRoot = slideElS ? slideElS.querySelector('.ccm-sugg-slide') : null;
        if(e.deltaX > 0) ccmSuggNext(suggRoot); else ccmSuggPrev(suggRoot);
        return;
    }

    var mediaBox = e.target.closest && e.target.closest('#ccm-media-box');
    var mediaCount = mediaBox ? parseInt(mediaBox.dataset.count || '1', 10) : 1;
    if(mediaBox && mediaCount > 1 && Math.abs(e.deltaX) > Math.abs(e.deltaY)){
        if(e.deltaX > 0) ccmMediaNext(); else ccmMediaPrev();
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

        if(e.key === 'Escape'){ ccmHandleBackTap(); return; }
        if(_ccmHStack.length > 0) return; // locked while a Connected Zeem layer is open
        if(e.key === 'ArrowDown'){ ccmLoadIndex(_ccmIndex+1); return; }
        if(e.key === 'ArrowUp'){ ccmLoadIndex(_ccmIndex-1); return; }

        if(e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;

        var item = ccmFeed[_ccmIndex];
        if(!item) return;

        if(item.type === 'sugg'){
            e.preventDefault();
            var slideElS = document.getElementById('ccm-slide');
            var suggRoot = slideElS ? slideElS.querySelector('.ccm-sugg-slide') : null;
            if(e.key === 'ArrowLeft') ccmSuggPrev(suggRoot); else ccmSuggNext(suggRoot);
            return;
        }

        if(item.type === 'post'){
            var slideEl2 = document.getElementById('ccm-slide');
            var mediaBox = slideEl2 ? slideEl2.querySelector('#ccm-media-box') : null;
            var mediaCount = mediaBox ? parseInt(mediaBox.dataset.count || '1', 10) : 1;
            if(mediaCount > 1){
                e.preventDefault();
                if(e.key === 'ArrowLeft') ccmMediaPrev(); else ccmMediaNext();
            }
        }
    });
}

window.addEventListener('resize', function(){
    var slide = document.getElementById('ccm-slide');
    if(slide){
        _ccmCheckCaptionOverflow(slide);
        _ccmInitAudioMarquee(slide);
    }
});

/* ══════════════════════════════════════════════════════════
   RENDER — POST SLOT
══════════════════════════════════════════════════════════ */
function _ccmRenderPost(p, isPreview){
    var isOwnPost = String(p.userId) === String(currentUserId);

    var media = (Array.isArray(p.media) && p.media.length) ? p.media.slice() : [{file:p.file, type:p.type}];
    var mediaCount = media.length;

    var mediaSet   = ['image','video','gif','sticker','stickers'];
    var firstType  = (media[0].type || '').toLowerCase();
    var isTextOnly = mediaCount === 1 && mediaSet.indexOf(firstType) === -1;

    var hasSeparateTrack = !!(p.audioFile) && !p.audioIsOriginal;
    var isMemePaused = !!_ccmPausedMemeIds[p.id];

    var initIdx;
    if(isPreview){
        initIdx = 0;
    } else {
        initIdx = isTextOnly ? 0 : _ccmGetSavedMediaIndex(p.id);
        initIdx = Math.max(0, Math.min(initIdx, mediaCount - 1));
        _ccmMediaIndex = initIdx;
    }

    var feedTrackBtn = document.querySelector('.track-btn[data-user="'+p.userId+'"]');
    var isFollowing  = feedTrackBtn ? feedTrackBtn.classList.contains('following') : false;

    var capState = _ccmComputeCaptionHtml(p);
    var localCaptionTruncHtml = capState.trunc;
    var mediaBoxInner;

    if(isTextOnly){
        mediaBoxInner = '<div class="ccm-textonly-bg"></div>'+
                    '<div class="ccm-textonly-overlay"></div>'+
                    '<div class="ccm-center-caption-wrap" id="ccm-center-caption-wrap">'+
                        '<div class="ccm-center-caption-text" id="ccm-center-caption-text">'+localCaptionTruncHtml+'</div>'+
                    '</div>';
    } else {
        mediaBoxInner = '<div class="ccm-media-gallery" id="ccm-media-gallery">'+
            media.map(function(m, i){
                var t = (m.type||'').toLowerCase();
                var isActive = (i === initIdx);
                var loadHook = isActive ? "ccmHideSkeleton(this.closest('.ccm-slide'))" : "";
                if(t === 'video'){
                    var mutedAttr = hasSeparateTrack ? 'muted ' : '';
                    var autoplayAttr = (isActive && !isPreview && !isMemePaused) ? 'autoplay' : '';
                    var isAdjacent = !isPreview && mediaCount > 1 && Math.abs(i - initIdx) === 1;
                    var preloadAttr = (isActive && !isPreview) ? 'preload="auto"' :
                                       (isAdjacent ? 'preload="auto"' : 'preload="metadata"');
                    return '<div class="ccm-media-item" data-idx="'+i+'" data-type="video">'+
                        '<video src="'+m.file+'" '+mutedAttr+autoplayAttr+' loop playsinline '+preloadAttr+' '+
                        'onloadedmetadata="ccmFixObjectFit(this);" '+
                        'onloadeddata="'+loadHook+'" '+
                        'ontimeupdate="ccmUpdateProgress(this)" class="ccm-media"></video>'+
                    '</div>';
                }
                return '<div class="ccm-media-item" data-idx="'+i+'" data-type="'+t+'">'+
                    '<img src="'+m.file+'" onerror="this.src=\'uploads/me.jpeg\'" '+
                    'onload="ccmFixObjectFit(this);'+loadHook+'" class="ccm-media">'+
                '</div>';
            }).join('')+
        '</div>'+
        (mediaCount > 1
            ? '<div class="ccm-media-arrow ccm-media-arrow-left" onclick="event.stopPropagation();ccmMediaPrev()"><i class="ri-arrow-left-s-line"></i></div>'+
              '<div class="ccm-media-arrow ccm-media-arrow-right" onclick="event.stopPropagation();ccmMediaNext()"><i class="ri-arrow-right-s-line"></i></div>'
            : '');
    }

    var typeInfo = _ccmTypeTagInfo(isTextOnly ? 'text' : ((media[initIdx] && media[initIdx].type) || firstType));
    var typeTagHtml = '<div class="ccm-type-tag" id="ccm-type-tag"><i class="'+typeInfo.icon+'"></i><span>'+typeInfo.label+'</span></div>';
    var counterHtml = mediaCount > 1
        ? '<div class="ccm-media-counter" id="ccm-media-counter">'+(initIdx+1)+'/'+mediaCount+'</div>'
        : '';

    var dashGap = mediaCount > 1 ? _ccmComputeDashGap(mediaCount) : 6;
    var dashesHtml = (mediaCount > 1)
        ? '<div class="ccm-media-dashes" id="ccm-media-dashes" style="gap:'+dashGap+'px;">'+
            media.map(function(_, i){ return '<span class="ccm-dash'+(i===initIdx?' active':'')+'"></span>'; }).join('')+
          '</div>'
        : '';

        var groupTagHtml = '';
    if(p.groupId){
        groupTagHtml = '<a href="group.php?id='+p.groupId+'" onclick="event.stopPropagation()" class="ccm-group-tag">'+
            '<i class="ri-team-line"></i>From&nbsp;<span class="ccm-group-tag-link">'+(p.groupTitle||'Group')+'</span>'+
        '</a>';
    }

    /* AI badge — sits below the group tag if one exists, otherwise
       takes the group tag's exact position */
    var aiTagHtml = '';
    if(p.isAiGenerated){
        var aiTagClass = 'ccm-ai-tag' + (p.groupId ? ' ccm-ai-tag-under-group' : '');
        aiTagHtml = '<div class="'+aiTagClass+'"><i class="ri-sparkling-2-fill"></i>AI Generated</div>';
    }

    var likeCount   = p.likeCount   !== undefined ? p.likeCount   : 0;
    var repostCount = p.repostCount !== undefined ? p.repostCount : 0;
    var favCount    = p.favCount    !== undefined ? p.favCount    : 0;
    var shareCount  = p.shareCount  !== undefined ? p.shareCount  : 0;
    var liked    = p.liked    || false;
    var reposted = p.reposted || false;
    var faved    = p.faved    || false;

    var trackBtnHtml = !isOwnPost
        ? '<span class="ccm-user-dot">·</span>'+
          '<button class="ccm-track-btn '+(isFollowing?'following':'')+'" data-user="'+p.userId+'" '+
              'onclick="event.stopPropagation();ccmToggleTrack(this)">'+(isFollowing?'Untrack':'Track')+'</button>'
        : '';

    var isNewPost = false;
    if(p.time){
        var _pd = new Date((p.time||'').replace(' ','T'));
        if(!isNaN(_pd)) isNewPost = (Date.now() - _pd.getTime()) <= (172800*1000);
    }
    var displayName = isOwnPost ? 'You' : _ccmTruncateName(p.username);
    var nicknameHtml = (!isOwnPost && p.nickname) ? '<div class="ccm-nickname">@'+p.nickname+'</div>' : '';

    var audioTagHtml = '';
    if(p.audioId !== undefined && p.audioId !== null){
        var audioLabel;
        if(p.audioIsOriginal){
            audioLabel = (isOwnPost ? p.username : displayName) + ' · Original audio';
        } else {
            audioLabel = (p.audioTitle || 'Audio') + (p.audioArtist ? ' — '+p.audioArtist : '');
        }
        audioTagHtml = '<div class="ccm-audio-tag" onclick="event.stopPropagation();ccmOpenSoundsPage('+p.userId+','+p.audioId+')" style="cursor:pointer;">'+
            '<span class="ccm-audio-icon" aria-hidden="true">'+
                '<span class="ccm-eq-bar"></span><span class="ccm-eq-bar"></span><span class="ccm-eq-bar"></span>'+
            '</span>'+
            '<div class="ccm-audio-track" id="ccm-audio-track">'+
                '<span class="ccm-audio-track-text" id="ccm-audio-track-text">'+_ccmEscapeHtml(audioLabel)+'</span>'+
            '</div>'+
        '</div>';
    }
    var switcherHtml = '';
    if(nicknameHtml && audioTagHtml){
        switcherHtml = '<div class="ccm-info-switcher" id="ccm-info-switcher">'+
            '<div class="ccm-switch-pane ccm-switch-nickname-pane active">'+nicknameHtml+'</div>'+
            '<div class="ccm-switch-pane ccm-switch-audio-pane">'+audioTagHtml+'</div>'+
        '</div>';
    } else if(nicknameHtml){ switcherHtml = nicknameHtml; }
    else if(audioTagHtml){ switcherHtml = audioTagHtml; }

    /* shared "should this bottom-left slot sit higher" flag —
       raised because of the paid-partnership badge sitting just
       below it. Social-proof strip / CTA pill / connected pill
       share this one flag. */
    var _ccmNeedsRaise = p.isPartnership;

    /* SOCIAL PROOF — never renders for ad posts OR connected-Zeem
       posts. The CTA pill / connected pill take this exact slot
       instead. A post can be an ad AND connected at the same time;
       social proof is suppressed by either condition alone. */
    var socialProofHtml = '';
    if(!p.isAd && !p.connectedMemeId && Array.isArray(p.socialProof) && p.socialProof.length){
        var spClass = 'ccm-social-proof-strip' + (_ccmNeedsRaise ? ' ccm-sp-raised' : '');
        socialProofHtml = '<div class="'+spClass+'" id="ccm-social-proof">'+
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

    /* CTA PILL — occupies the social-proof strip's exact spot,
       only for ad posts with a URL to send people to. Small by
       default; ccmInitCtaPillCycle() expands/contracts it every
       few seconds via the .ccm-cta-pill-expanded class. */
    var ctaPillHtml = '';
    if(p.isAd && p.adCtaUrl){
        var ctaClass = 'ccm-cta-pill' + (_ccmNeedsRaise ? ' ccm-sp-raised' : '');
        var ctaColor = p.adCtaColor || '#FF4C4C';
        var ctaLabel = p.adCtaText || 'Learn more';
        ctaPillHtml = '<a href="'+p.adCtaUrl+'" target="_blank" id="ccm-cta-pill" class="'+ctaClass+'" '+
        'style="--cta-color:'+ctaColor+';" onclick="event.stopPropagation()">'+
        '<span class="ccm-cta-pill-text">'+_ccmEscapeHtml(ctaLabel)+'</span>'+
        '<i class="ri-arrow-right-s-line ccm-cta-pill-chevron"></i>'+
        '</a>';
    }

    /* CONNECTED ZEEM PILL — occupies the same slot as
       social-proof/CTA (mutually exclusive with social proof —
       see the guard on socialProofHtml above). If this post is
       ALSO an ad (ctaPillHtml is non-empty), the connected pill
       drops to a lower position via .ccm-connected-below-cta so
       it sits BELOW the CTA pill without touching it or the
       user-row/caption block underneath. Tapping it never
       navigates the page — it slides a new horizontal layer over
       the modal via ccmOpenConnectedZeem(). */
    var connectedTagHtml = '';
    if(p.connectedMemeId){
        var stackedClass = ctaPillHtml ? ' ccm-connected-below-cta' : '';
        var raisedClass  = _ccmNeedsRaise ? ' ccm-sp-raised' : '';
        var connLabel    = p.connectedLabel ? _ccmEscapeHtml(p.connectedLabel) : 'Connected Zeem';
        connectedTagHtml = '<a href="#" id="ccm-connected-tag" class="ccm-connected-tag'+raisedClass+stackedClass+'" '+
            'onclick="event.stopPropagation();event.preventDefault();ccmOpenConnectedZeem('+p.connectedMemeId+')">'+
            '<span class="ccm-connected-icon"><i class="ri-play-fill"></i></span>'+
            '<span class="ccm-connected-text">'+
                '<span class="ccm-connected-text-main">View</span>'+
                '<span class="ccm-connected-text-dot">·</span>'+
                '<span class="ccm-connected-text-label">'+connLabel+'</span>'+
            '</span>'+
        '</a>';
    }

    var bottomCaptionHtml = '';
    if(!isTextOnly){
        bottomCaptionHtml = '<div class="ccm-caption-wrap" id="ccm-caption-wrap">'+
              '<span class="ccm-caption-text" id="ccm-caption-text">'+localCaptionTruncHtml+'</span>'+
          '</div>';
    }

    var partnershipHtml = '';
    if(p.isPartnership){
        var brandText = p.partnerBrand ? ' with <strong>'+_ccmEscapeHtml(p.partnerBrand)+'</strong>' : '';
        partnershipHtml = '<div class="ccm-partnership-tag"><i class="ri-handshake-line"></i>Paid partnership'+brandText+'</div>';
    }

    var railClass = 'ccm-rail' + (mediaCount > 1 ? ' ccm-rail-raised' : '');

    var skeletonHtml = isTextOnly ? '' : _ccmSkeletonHtml();

    var initialActiveType = isTextOnly ? '' : ((media[initIdx] && media[initIdx].type) || firstType).toLowerCase();
    var progressBarHtml = !isTextOnly ? (
        '<div class="ccm-video-progress" style="display:'+(initialActiveType === 'video' ? 'flex' : 'none')+';">'+
            '<div class="ccm-video-progress-track">'+
                '<div class="ccm-video-progress-fill"></div>'+
                '<div class="ccm-video-progress-thumb"></div>'+
            '</div>'+
        '</div>'
    ) : '';

    /* RAIL — the profile-pic message shortcut is swapped out for a
       plain "AD" tag when this post is an ad, instead of never
       rendering (which would leave an empty gap in the rail). */
    var railMsgOrAdHtml = '';
    if(p.isAd){
        railMsgOrAdHtml = '<span class="ccm-rail-ad-label">AD</span>';
    } else if(!isOwnPost){
        railMsgOrAdHtml = '<button type="button" class="ccm-rail-btn ccm-rail-msg" onclick="event.stopPropagation();ccmOpenSoundsPage('+p.userId+(p.audioId !== undefined && p.audioId !== null ? ','+p.audioId : '')+')">'+
            '<img src="'+(p.pic||'uploads/me.jpeg')+'" onerror="this.src=\'uploads/me.jpeg\'" class="ccm-rail-msg-pic">'+
        '</button>';
    }

    return (
        skeletonHtml+

        '<div class="ccm-media-box" id="ccm-media-box" data-count="'+mediaCount+'" '+
            'onclick="event.stopPropagation();ccmHandleMediaTap(event,this,'+(mediaCount > 1)+')"'+
        '>'+ mediaBoxInner +'</div>'+

        '<div class="ccm-top-bar">'+
            '<div class="ccm-top-bar-row">'+
                '<div class="ccm-back-group">'+
                    '<span class="ccm-icon-btn ccm-back-btn" style="visibility:hidden;pointer-events:none;"><i class="ri-arrow-left-s-line"></i></span>'+
                    (p.isAd
                        ? '<span class="ccm-brand-label">Advertisement</span>'
                        : '<span class="ccm-brand-label">Zeems</span>'+(p.time ? '<span class="ccm-brand-dot">·</span><span class="ccm-brand-date">'+_formatDate(p.time)+(isNewPost?' · <span class="ccm-new-tag">New</span>':'')+'</span>' : '')
                    )+
                '</div>'+
                '<button class="ccm-icon-btn ccm-search-btn" onclick="location.href=\'search.php?focus=1\'"><i class="ri-search-line"></i></button>'+
            '</div>'+
        '</div>'+

        groupTagHtml+
        aiTagHtml+
        '<div class="ccm-top-right-tags">'+ counterHtml + typeTagHtml +'</div>'+
        socialProofHtml+
        ctaPillHtml+
        connectedTagHtml+

        '<div class="'+railClass+'">'+
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
            railMsgOrAdHtml+
        '</div>'+

        '<div class="ccm-bottom">'+
            '<div class="ccm-user-row">'+
                '<img src="'+(p.pic||'uploads/me.jpeg')+'" onerror="this.src=\'uploads/me.jpeg\'" class="ccm-user-pic" onclick="location.href=\'profile.php?user_id='+p.userId+'\'">'+
                '<div class="ccm-user-info">'+
                    '<div class="ccm-user-line1">'+
                        '<span class="ccm-username" onclick="location.href=\'profile.php?user_id='+p.userId+'\'">'+displayName+'</span>'+
                        trackBtnHtml+
                    '</div>'+
                    switcherHtml+
                '</div>'+
            '</div>'+
            bottomCaptionHtml+
            partnershipHtml+
            dashesHtml+
        '</div>'+

        progressBarHtml
    );
}

function ccmOpenSoundsPage(userId, audioId){
    if(!userId) return;
    var url = 'audio.php?user_id=' + userId;
    if(audioId){ url += '&audio_id=' + audioId; }
    window.location.href = url;
}

/* ── SUGGESTED USERS SLOT ── */
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
                        'onclick="event.stopPropagation();toggleSuggTrack(this)">'+(following?'Untrack':'Track')+'</button>'+
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
                '<button class="ccm-icon-btn ccm-back-btn" onclick="ccmHandleBackTap()"></button>'+
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

function toggleSuggTrack(btn){
    var userId = btn.dataset.user;
    fetch('home.php', {
        method:  'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body:    'action=toggle_follow&user_id='+userId
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        if(data.status === 'ok'){
            if(data.following){ btn.textContent='Untrack'; btn.classList.add('tracking'); }
            else               { btn.textContent='Track';  btn.classList.remove('tracking'); }
        }
    });
}

/* ══════════════════════════════════════════════════════════
   GAPLESS VERTICAL TRACK ENGINE
══════════════════════════════════════════════════════════ */
function _ccmSlideItem(idx){
    if(idx < 0 || idx >= ccmFeed.length) return null;
    return ccmFeed[idx];
}

function _ccmRenderItemHtml(item, isPreview){
    if(!item) return '';
    if(item.type === 'post') return _ccmRenderPost(item.data, isPreview);
    if(item.type === 'sugg') return _ccmRenderSugg(item, isPreview);
    return '';
}

/* Binds drag-to-seek on any .ccm-video-progress bar the instant
   the slide is built, whether it's the current slide or a
   preloaded prev/next preview — so a promoted neighbor slide is
   already scrub-ready with no re-binding needed mid-swipe. */
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
        wrap.querySelectorAll('.ccm-video-progress').forEach(ccmInitProgressScrub);
    }
    return wrap;
}

/* Activates whichever slide currently owns #ccm-slide — this
   function doesn't care whether that slide lives inside the
   vertical track or inside a Connected-Zeem horizontal layer,
   it only ever looks the slide up by id. */
function _ccmActivateCurrentSlide(currentItem){
    ccmHideSavedToast();

    if(currentItem && currentItem.type === 'post'){
        var capState = _ccmComputeCaptionHtml(currentItem.data);
        _ccmCaptionFullHtml  = capState.full;
        _ccmCaptionTruncHtml = capState.trunc;
    }
    _ccmCaptionExpanded = false;

    var newMemeId = (currentItem && currentItem.type === 'post') ? currentItem.data.id : null;
    var shouldStayPaused = newMemeId !== null && !!_ccmPausedMemeIds[newMemeId];

    var curSlide = document.getElementById('ccm-slide');

    if(curSlide){
        if(currentItem.type === 'post'){
            _ccmInitMediaSwipe(curSlide);
            _ccmInitAudioMarquee(curSlide);
            _ccmInitInfoSwitcher(curSlide);
            ccmUpdateMediaGallery(curSlide, shouldStayPaused);
            _ccmInitCtaPillCycle(curSlide);
        } else {
            if(_ccmInfoSwitchTimer){ clearInterval(_ccmInfoSwitchTimer); _ccmInfoSwitchTimer = null; }
            if(_ccmCtaPillTimer){ clearInterval(_ccmCtaPillTimer); _ccmCtaPillTimer = null; }
        }

        if(currentItem.type === 'sugg'){
            _ccmInitSuggSwipe(curSlide.querySelector('.ccm-sugg-slide'));
        }

        var mediaImg  = curSlide.querySelector('img.ccm-media');
        var textBg    = curSlide.querySelector('.ccm-textonly-bg');
        var activeVid = curSlide.querySelector('.ccm-media-item[data-idx="'+_ccmMediaIndex+'"] video.ccm-media')
                      || curSlide.querySelector('video.ccm-media');

        if(currentItem.type === 'sugg'){
            ccmHideSkeleton(curSlide);
        } else if(textBg){
            ccmHideSkeleton(curSlide);
            requestAnimationFrame(function(){ _ccmCheckCaptionOverflow(curSlide); });
        } else if(mediaImg && mediaImg.complete){
            ccmHideSkeleton(curSlide);
        } else if(activeVid && activeVid.readyState >= 2){
            ccmHideSkeleton(curSlide);
        }
        setTimeout(function(){ ccmHideSkeleton(curSlide); }, 2500);
    } else {
        if(_ccmInfoSwitchTimer){ clearInterval(_ccmInfoSwitchTimer); _ccmInfoSwitchTimer = null; }
        if(_ccmCtaPillTimer){ clearInterval(_ccmCtaPillTimer); _ccmCtaPillTimer = null; }
    }

    _ccmSyncSoundtrack(currentItem);

    _ccmMemeId = newMemeId;
}

function _ccmMountTrack(centerIndex){
    _ccmVSwipeLocked = false;

    // Any Connected-Zeem layers from a previous session should never
    // survive a full remount.
    var existingHstack = document.getElementById('ccm-hstack');
    if(existingHstack){
        existingHstack.querySelectorAll('video').forEach(function(v){ v.pause(); v.src=''; });
        existingHstack.innerHTML = '';
        existingHstack.style.pointerEvents = 'none';
    }
    _ccmHStack = [];

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
    _ccmEnsureHStackEl();

    _ccmIndex = centerIndex;
    _ccmActivateCurrentSlide(_ccmSlideItem(centerIndex));
}

var CCM_OVERLAY_SELECTOR = '.ccm-top-bar, .ccm-group-tag, .ccm-ai-tag, .ccm-top-right-tags, .ccm-social-proof-strip, .ccm-cta-pill, .ccm-connected-tag, .ccm-rail, .ccm-bottom, .ccm-sugg-header, .ccm-sugg-dots, .ccm-sugg-arrow';

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
        // Vertical feed navigation is fully locked while a
        // Connected-Zeem layer is open — the only way out is the
        // back button (ccmHandleBackTap).
        if(_ccmHStack.length > 0) return;
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
    if(_ccmHStack.length > 0) return; // locked while a Connected-Zeem layer is open

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
        if(_ccmInfoSwitchTimer){ clearInterval(_ccmInfoSwitchTimer); _ccmInfoSwitchTimer = null; }
        if(_ccmCtaPillTimer){ clearInterval(_ccmCtaPillTimer); _ccmCtaPillTimer = null; }
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
    var promotedId   = (promotedItem && promotedItem.type === 'post') ? promotedItem.data.id : null;

    _ccmIndex      = newIndex;
    _ccmMediaIndex = _ccmGetSavedMediaIndex(promotedId);
    _ccmActivateCurrentSlide(promotedItem);

    _ccmVSwipeLocked = false;
}

function ccmLoadIndex(index){
    if(_ccmVSwipeLocked) return;
    if(_ccmHStack.length > 0) return; // locked while a Connected-Zeem layer is open
    if(index < 0 || index >= ccmFeed.length || index === _ccmIndex) return;
    if(Math.abs(index - _ccmIndex) === 1){
        _ccmCommitTrackMove(index > _ccmIndex ? 1 : -1);
    } else {
        _ccmMountTrack(index);
    }
}

/* ══════════════════════════════════════════════════════════
   CONNECTED ZEEM — horizontal linked-post navigation
   ══════════════════════════════════════════════════════════
   Separate from BOTH existing swipe systems:
     - vertical (_ccmVDragBound / _ccmCommitTrackMove) moves
       between posts in the current feed (ccmFeed)
     - horizontal-in-gallery (_ccmInitMediaSwipe) moves between
       media items WITHIN one post
   This is a third system: horizontal, but slides the ENTIRE
   slide (header, media, rail, caption) to a totally different
   post that may not be anywhere in ccmFeed. It lives in its own
   stack (_ccmHStack) of .ccm-hlayer elements inside #ccm-hstack
   (a sibling of #ccm-vtrack inside #ccm-inner). Only one layer
   is ever the "active" one — whichever is on top of the stack —
   and it's the one that gets id="ccm-slide" so every existing
   helper (ccmAction, ccmToggleTrack, _ccmActivateCurrentSlide,
   the media-gallery/scrub/skeleton code, etc.) keeps working
   completely unmodified, since none of it cares WHERE the slide
   physically lives, only that it can find it by id.
   ══════════════════════════════════════════════════════════ */

function _ccmEnsureHStackEl(){
    var inner = document.getElementById('ccm-inner');
    if(!inner) return null;
    var el = document.getElementById('ccm-hstack');
    if(!el){
        el = document.createElement('div');
        el.id = 'ccm-hstack';
        el.style.position = 'absolute';
        el.style.inset = '0';
        el.style.zIndex = '12';
        el.style.overflow = 'hidden';
        el.style.pointerEvents = 'none';
        inner.appendChild(el);
    }
    return el;
}

function ccmOpenConnectedZeem(memeId){
    if(!memeId) return;

    // Already showing this exact post on top — nothing to do.
    if(_ccmHStack.length){
        var topItem = _ccmHStack[_ccmHStack.length - 1].item;
        if(topItem && String(topItem.data.id) === String(memeId)) return;
    }

    var cached = _ccmConnectedCache[memeId];
    if(cached){
        _ccmPushConnectedLayer(cached);
        return;
    }

    if(_ccmConnectedFetchLock) return;
    _ccmConnectedFetchLock = true;

    fetch('home.php', {
        method:  'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body:    'action=get_connected_meme&meme_id='+encodeURIComponent(memeId)
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        _ccmConnectedFetchLock = false;
        if(!data || !data.ok || !data.post){
            showToast('Could not load this Zeem');
            return;
        }
        _ccmConnectedCache[memeId] = data.post;
        _ccmPushConnectedLayer(data.post);
    })
    .catch(function(){
        _ccmConnectedFetchLock = false;
        showToast('Network error — try again');
    });
}

function _ccmPushConnectedLayer(postData){
    var hstack = _ccmEnsureHStackEl();
    if(!hstack) return;

    // Detach id from whatever is currently the active slide (either
    // the vtrack's current post, or the previous top hlayer) so only
    // the new incoming layer ever owns #ccm-slide.
    var curSlide = document.getElementById('ccm-slide');
    if(curSlide) curSlide.removeAttribute('id');

    // Stop timers tied to the outgoing slide — they'll be restarted
    // fresh against the new slide inside _ccmActivateCurrentSlide.
    if(_ccmInfoSwitchTimer){ clearInterval(_ccmInfoSwitchTimer); _ccmInfoSwitchTimer = null; }
    if(_ccmCtaPillTimer){ clearInterval(_ccmCtaPillTimer); _ccmCtaPillTimer = null; }

    var item = { type: 'post', data: postData };

    var layer = document.createElement('div');
    layer.className = 'ccm-hlayer';
    layer.style.position = 'absolute';
    layer.style.inset = '0';
    layer.style.width = '100%';
    layer.style.height = '100%';
    layer.style.background = '#000';
    layer.style.willChange = 'transform';

    var slideEl = document.createElement('div');
    slideEl.className = 'ccm-slide';
    slideEl.id = 'ccm-slide';
    slideEl.innerHTML = _ccmRenderPost(postData, false);
    layer.appendChild(slideEl);

    layer.style.transform  = 'translateX(100%)';
    layer.style.transition = 'none';
    hstack.appendChild(layer);
    hstack.style.pointerEvents = 'all';

    void layer.offsetHeight; // force reflow so the slide-in actually animates
    layer.style.transition = 'transform .32s cubic-bezier(.22,.36,.36,1)';
    layer.style.transform  = 'translateX(0)';

    layer.querySelectorAll('.ccm-video-progress').forEach(ccmInitProgressScrub);

    _ccmHStack.push({ layer: layer, item: item });
    _ccmMediaIndex = 0;

    _ccmActivateCurrentSlide(item);
}

function ccmCloseConnectedZeem(){
    if(!_ccmHStack.length) return;

    var top   = _ccmHStack.pop();
    var layer = top.layer;

    if(_ccmInfoSwitchTimer){ clearInterval(_ccmInfoSwitchTimer); _ccmInfoSwitchTimer = null; }
    if(_ccmCtaPillTimer){ clearInterval(_ccmCtaPillTimer); _ccmCtaPillTimer = null; }

    var outgoingSlide = layer.querySelector('.ccm-slide');
    if(outgoingSlide){
        outgoingSlide.removeAttribute('id');
        outgoingSlide.querySelectorAll('video').forEach(function(v){ v.pause(); });
    }

    layer.style.transition = 'transform .32s cubic-bezier(.22,1,.36,1)';
    layer.style.transform  = 'translateX(100%)';

    var removed = false;
    function finish(){
        if(removed) return;
        removed = true;
        layer.removeEventListener('transitionend', onEnd);
        if(layer.parentNode) layer.parentNode.removeChild(layer);

        var nextTop = _ccmHStack.length ? _ccmHStack[_ccmHStack.length - 1] : null;
        var nextItem;

        if(nextTop){
            var nextSlide = nextTop.layer.querySelector('.ccm-slide');
            if(nextSlide) nextSlide.id = 'ccm-slide';
            nextItem = nextTop.item;
        } else {
            var hstack = document.getElementById('ccm-hstack');
            if(hstack) hstack.style.pointerEvents = 'none';
            var curVSlide = document.querySelector('.ccm-vslide[data-pos="current"] .ccm-slide');
            if(curVSlide) curVSlide.id = 'ccm-slide';
            nextItem = _ccmSlideItem(_ccmIndex);
        }

        _ccmMediaIndex = _ccmGetSavedMediaIndex(nextItem && nextItem.type === 'post' ? nextItem.data.id : null);
        _ccmActivateCurrentSlide(nextItem);
    }
    var onEnd = function(e){ if(e.target === layer && e.propertyName === 'transform') finish(); };
    layer.addEventListener('transitionend', onEnd);
    setTimeout(finish, 360);
}

/* Back button dispatcher — wired to #ccmFixedBackBtn's onclick.
   If a Connected-Zeem layer is open, one tap slides back exactly
   one hop (never closes the modal); only once the stack is empty
   does back close the modal. */
function ccmHandleBackTap(){
    if(_ccmHStack.length > 0){
        ccmCloseConnectedZeem();
    } else {
        closeCardCaptionModal();
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

function openCardModal(file, type, pic, userId, username, caption){
    if(ccmFeed.length === 0) _buildCcmFeed();
    var idx = ccmFeed.findIndex(function(s){
        return s.type === 'post' && s.data.file === file && String(s.data.userId) === String(userId);
    });
    var targetIdx = idx >= 0 ? idx : 0;

    _ccmVSwipeLocked = false;

    // Always start a fresh open with no Connected-Zeem layers
    // carried over from a previous session.
    var existingHstack = document.getElementById('ccm-hstack');
    if(existingHstack){
        existingHstack.querySelectorAll('video').forEach(function(v){ v.pause(); v.src=''; });
        existingHstack.innerHTML = '';
        existingHstack.style.pointerEvents = 'none';
    }
    _ccmHStack = [];

    _ccmMountTrack(targetIdx);
    _ccmInitVerticalDrag();

    document.getElementById('cardCaptionModal').classList.add('ccm-open');
    ccmLockBodyScroll();
}

function closeCardCaptionModal(){
    var hstack = document.getElementById('ccm-hstack');
    if(hstack){
        hstack.querySelectorAll('video').forEach(function(v){ v.pause(); v.src=''; });
        hstack.innerHTML = '';
        hstack.style.pointerEvents = 'none';
    }
    _ccmHStack = [];

    document.getElementById('ccm-inner').querySelectorAll('video').forEach(function(v){ v.pause(); v.src=''; });
    _ccmStopSoundtrack();
    ccmHideSavedToast();
    if(_ccmInfoSwitchTimer){ clearInterval(_ccmInfoSwitchTimer); _ccmInfoSwitchTimer = null; }
    if(_ccmCtaPillTimer){ clearInterval(_ccmCtaPillTimer); _ccmCtaPillTimer = null; }
    document.getElementById('cardCaptionModal').classList.remove('ccm-open');
    ccmUnlockBodyScroll();
    _ccmMemeId     = null;
    _ccmMediaIndex = 0;
    _ccmVSwipeLocked = false;
}

/* ── ACTION HANDLER (like / repost / fav) ── */
function ccmAction(type){
    if(!_ccmMemeId) return;
    var memeId = _ccmMemeId;

    var actionMap  = { like:'toggle_like', repost:'toggle_repost', fav:'toggle_favorite' };
    var btnMap     = { like:'ccm-like-btn', repost:'ccm-repost-btn', fav:'ccm-fav-btn' };
    var cntMap     = { like:'ccm-like-count', repost:'ccm-repost-count', fav:'ccm-fav-count' };
    var cntField   = { like:'likeCount', repost:'repostCount', fav:'favCount' };
    var stateField = { like:'liked', repost:'reposted', fav:'faved' };
    var feedSel    = { like:'.like-btn', repost:'.repost-btn', fav:'.fav-btn' };
    var feedCntCls = { like:'.like-count', repost:'.repost-count', fav:'.fav-count' };

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

    if(type === 'repost' && cmBtn){
        var repostIcon = cmBtn.querySelector('i');
        if(repostIcon){
            cmBtn.classList.remove('arc-swap-active');
            void repostIcon.offsetWidth; // reflow so rapid re-taps restart it
            cmBtn.classList.add('arc-swap-active');
            repostIcon.addEventListener('animationend', function handler(){
                cmBtn.classList.remove('arc-swap-active');
                repostIcon.removeEventListener('animationend', handler);
            });
        }
    } else if(cmBtn){
        cmBtn.style.transform = 'scale(1.25)';
        setTimeout(function(){ if(cmBtn) cmBtn.style.transform = ''; }, 140);
    }

    if(type === 'fav'){
        if(optimisticNext){
            ccmShowSavedToast(memeId);
        } else {
            ccmHideSavedToast();
        }
    }

    fetch('home.php', {
        method:  'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body:    'action='+actionMap[type]+'&meme_id='+memeId
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        if(data.count === undefined){
            if(cmBtn)  cmBtn.classList.toggle('active', wasActive);
            if(cmSpan) cmSpan.textContent = prevCountText;
            if(type === 'fav' && optimisticNext) ccmHideSavedToast();
            return;
        }

        var nowActive = cmBtn ? cmBtn.classList.contains('active') : optimisticNext;
        if(cmSpan) cmSpan.textContent = data.count;

        if(type === 'fav' && nowActive !== optimisticNext){
            if(nowActive) ccmShowSavedToast(memeId); else ccmHideSavedToast();
        }

        var feedBtn = document.querySelector(feedSel[type]+'[data-id="'+memeId+'"]');
        if(feedBtn){
            feedBtn.classList.toggle('active', nowActive);
            var feedCnt = feedBtn.querySelector(feedCntCls[type]);
            if(feedCnt) feedCnt.textContent = data.count;
        }

        var feedItem = ccmFeed.find(function(s){
            return s.type === 'post' && String(s.data.id) === String(memeId);
        });
        if(feedItem){
            feedItem.data[cntField[type]]   = data.count;
            feedItem.data[stateField[type]] = nowActive;
        }
    })
    .catch(function(err){
        console.error('ccmAction failed:', err);
        if(cmBtn)  cmBtn.classList.toggle('active', wasActive);
        if(cmSpan) cmSpan.textContent = prevCountText;
        if(type === 'fav' && optimisticNext) ccmHideSavedToast();
    });
}

/* ── SHARE MODAL ── */
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
    }).catch(function(){});
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

            var feedShareBtn = document.querySelector('.share-btn[data-id="'+_shareMemeId+'"]');
            if(feedShareBtn){
                var fc = feedShareBtn.querySelector('.share-count');
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

window.addEventListener('load', function(){ _loadCcmData(); });

var ccmTouchStartY    = 0;
var ccmTouchInCaption = false;
document.getElementById('cardCaptionModal').addEventListener('touchstart', function(e){
    ccmTouchInCaption = _ccmIsInsideExpandedCaption(e.target);
    if(ccmTouchInCaption) return;
    ccmTouchStartY = e.touches[0].clientY;
}, { passive:true });

document.getElementById('cardCaptionModal').addEventListener('touchend', function(e){
    if(ccmTouchInCaption){ ccmTouchInCaption = false; return; }
    if(_ccmHStack.length > 0) return; // locked while a Connected-Zeem layer is open
    var diff = ccmTouchStartY - e.changedTouches[0].clientY;
    if(Math.abs(diff) > 40){
        if(diff > 0) ccmLoadIndex(_ccmIndex+1);
        else         ccmLoadIndex(_ccmIndex-1);
    }
}, { passive:true });


/* ── SHARE MODAL ── */
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
    }).catch(function(){});
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

            var feedShareBtn = document.querySelector('.share-btn[data-id="'+_shareMemeId+'"]');
            if(feedShareBtn){
                var fc = feedShareBtn.querySelector('.share-count');
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

window.addEventListener('load', function(){ _loadCcmData(); });

document.getElementById('cardCaptionModal').addEventListener('wheel', function(e){
    if(_ccmIsInsideExpandedCaption(e.target)) return;

    e.preventDefault();
    var mediaBox = e.target.closest && e.target.closest('#ccm-media-box');
    var mediaCount = mediaBox ? parseInt(mediaBox.dataset.count || '1', 10) : 1;
    if(mediaBox && mediaCount > 1 && Math.abs(e.deltaX) > Math.abs(e.deltaY)){
        if(e.deltaX > 0) ccmMediaNext(); else ccmMediaPrev();
        return;
    }
    if(e.deltaY > 0) ccmLoadIndex(_ccmIndex+1);
    else             ccmLoadIndex(_ccmIndex-1);
}, { passive:false });

var ccmTouchStartY    = 0;
var ccmTouchInCaption = false;
document.getElementById('cardCaptionModal').addEventListener('touchstart', function(e){
    ccmTouchInCaption = _ccmIsInsideExpandedCaption(e.target);
    if(ccmTouchInCaption) return;
    ccmTouchStartY = e.touches[0].clientY;
}, { passive:true });

document.getElementById('cardCaptionModal').addEventListener('touchend', function(e){
    if(ccmTouchInCaption){ ccmTouchInCaption = false; return; }
    var diff = ccmTouchStartY - e.changedTouches[0].clientY;
    if(Math.abs(diff) > 40){
        if(diff > 0) ccmLoadIndex(_ccmIndex+1);
        else         ccmLoadIndex(_ccmIndex-1);
    }
}, { passive:true });

document.addEventListener('keydown', function(e){
    if(document.getElementById('cardCaptionModal').classList.contains('ccm-open')){
        if(e.key === 'Escape')    closeCardCaptionModal();
        if(e.key === 'ArrowDown') ccmLoadIndex(_ccmIndex+1);
        if(e.key === 'ArrowUp')   ccmLoadIndex(_ccmIndex-1);
        if(e.key === 'ArrowRight') ccmMediaNext();
        if(e.key === 'ArrowLeft')  ccmMediaPrev();
    }
});

window.addEventListener('resize', function(){
    var slide = document.getElementById('ccm-slide');
    if(slide && ccmFeed[_ccmIndex] && ccmFeed[_ccmIndex].type === 'post'){
        _ccmCheckCaptionOverflow(slide);
        _ccmInitAudioMarquee(slide);
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

<div id="eng-modal" style="
    display:none;
    position:fixed;
    top:0;left:0;
    width:100%;height:100%;
    background:rgba(0,0,0,0.85);
    z-index:10000;
    align-items:center;
    justify-content:center;
" onclick="closeEngModal()">
    <div style="
        background:transparent;
        backdrop-filter:blur(20px);
        border-radius:16px;
        width:90%;
        max-width:420px;
        max-height:80vh;
        overflow:hidden;
        border:1px solid rgba(255,255,255,0.1);
        display:flex;
        flex-direction:column;
    " onclick="event.stopPropagation()">

        <!-- HEADER -->
        <div style="
            display:flex;
            align-items:center;
            justify-content:space-between;
            padding:14px 16px;
            border-bottom:1px solid rgba(255,255,255,0.1);
            flex-shrink:0;
        ">
            <span style="color:white;font-weight:600;font-size:0.95rem;">Engagement</span>
            <button onclick="closeEngModal()" style="background:transparent;border:none;color:white;font-size:1.2rem;cursor:pointer;">✕</button>
        </div>

        <!-- SEARCH BAR -->
        <div style="padding:12px 16px;border-bottom:1px solid rgba(255,255,255,0.08);flex-shrink:0;">
            <input id="eng-search" type="text" placeholder="Search users..." oninput="filterEngList()"
            style="
                width:100%;
                background:rgba(255,255,255,0.07);
                border:1px solid rgba(255,255,255,0.12);
                border-radius:10px;
                padding:9px 14px;
                color:white;
                font-size:0.82rem;
                outline:none;
                font-family:Poppins,sans-serif;
            ">
        </div>

        <!-- PILL TABS -->
        <div style="
            display:flex;
            gap:10px;
            padding:12px 16px;
            border-bottom:1px solid rgba(255,255,255,0.08);
            flex-shrink:0;
        ">
            <button id="pill-likes" class="eng-pill active-pill" onclick="switchEngTab('likes')">
                <i class="ri-heart-3-fill"></i> Liked
            </button>
            <button id="pill-reposts" class="eng-pill" onclick="switchEngTab('reposts')">
                <i class="ri-repeat-line"></i> Reposted
            </button>
        </div>

        <!-- USER LIST -->
        <div id="eng-modal-list" style="overflow-y:auto;flex:1;"></div>

    </div>
</div>

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
function handleAdLike(btn){
    var adId = btn.dataset.adId;
    var willBeActive = !btn.classList.contains('active');

    fetch('home.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=toggle_ad_like&sponsorship_id=' + adId
    })
    .then(function(res){ return res.json(); })
    .then(function(data){
        if(data.count === undefined) return;

        btn.classList.toggle('active', willBeActive);
        var countEl = btn.querySelector('.ad-like-count');
        if(countEl) countEl.textContent = data.count;

        // keep the reels-modal ad slide in sync if it's open on this same ad
        var modalBtn = document.getElementById('ccm-ad-like-btn');
        if(modalBtn && window.cmAds){
            var adInList = window.cmAds.find(function(a){ return String(a.id) === String(adId); });
            if(adInList){
                var modalCount = document.getElementById('ccm-ad-like-count');
                modalBtn.classList.toggle('active', willBeActive);
                if(modalCount) modalCount.textContent = data.count;
            }
        }
    })
    .catch(function(err){ console.error('handleAdLike failed:', err); });
}

(function () {
    var sk   = document.getElementById('feedSkeleton');
    var feed = document.querySelector('.feed');

    if (sk && feed) {
        feed.style.visibility = 'hidden';
        feed.style.opacity    = '0';

        window.addEventListener('load', function () {
            setTimeout(function () {
                sk.style.transition = 'opacity 0.4s ease';
                sk.style.opacity    = '0';
                setTimeout(function () {
                    sk.style.display   = 'none';
                    feed.style.visibility = 'visible';
                    feed.style.transition = 'opacity 0.4s ease';
                    feed.style.opacity    = '1';
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

// ── SCREEN TIME BREAK OVERLAY ──────────────────────────────
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

// ── COPY CAPTION ─────────────────────────────────────────────
function copyCaption(caption) {
    navigator.clipboard.writeText(caption).then(() => {
        showToast('Caption copied!');
    }).catch(() => {
        // fallback
        const el = document.createElement('textarea');
        el.value = caption;
        document.body.appendChild(el);
        el.select();
        document.execCommand('copy');
        document.body.removeChild(el);
        showToast('Caption copied!');
    });
}

// ── NOT INTERESTED ────────────────────────────────────────────
/* ── FIX 8: notInterested posts to home.php ── */
function notInterested(memeId, el){
    var card = el.closest('.card-unit') || el.closest('.card');
    fetch('home.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=not_interested&meme_id=' + memeId
    }).then(function(){
        if(card){
            card.style.transition = 'opacity 0.4s, transform 0.4s';
            card.style.opacity    = '0';
            card.style.transform  = 'scale(0.96)';
            setTimeout(function(){ card.remove(); }, 400);
        }
    });
}



/* ── spin keyframe (keep it available) ── */
if(!document.getElementById('feedSpinKf')){
    var s=document.createElement('style');
    s.id='feedSpinKf';
    s.textContent='@keyframes spin{to{transform:rotate(360deg);}}';
    document.head.appendChild(s);
}

// ── TOAST HELPER ─────────────────────────────────────────────
function showToast(msg) {
    const existing = document.getElementById('peura-toast');
    if (existing) existing.remove();

    const t = document.createElement('div');
    t.id = 'peura-toast';
    t.textContent = msg;
    Object.assign(t.style, {
        position:    'fixed',
        bottom:      '90px',
        left:        '50%',
        transform:   'translateX(-50%)',
        background:  'rgba(20,20,25,.95)',
        border:      '1px solid rgba(255,45,45,.3)',
        color:       '#fff',
        padding:     '8px 20px',
        borderRadius:'50px',
        fontSize:    '12px',
        zIndex:      '9999999',
        pointerEvents:'none',
        boxShadow:   '0 8px 24px rgba(0,0,0,.6)',
        transition:  'opacity .3s',
        whiteSpace:  'nowrap'
    });
    document.body.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 300); }, 2200);
}



let currentEngMemeId = null;
let currentEngTab = 'likes';
let currentEngUsers = [];

function openEngModal(memeId, type) {
    event.stopPropagation();
    currentEngMemeId = memeId;
    currentEngTab = 'likes';

    document.getElementById('eng-modal').style.display = 'flex';
    document.getElementById('eng-search').value = '';

    // reset pills
    document.getElementById('pill-likes').classList.add('active-pill');
    document.getElementById('pill-reposts').classList.remove('active-pill');

    loadEngTab('likes');
}

function switchEngTab(type) {
    currentEngTab = type;

    document.getElementById('pill-likes').classList.toggle('active-pill', type === 'likes');
    document.getElementById('pill-reposts').classList.toggle('active-pill', type === 'reposts');
    document.getElementById('eng-search').value = '';

    loadEngTab(type);
}

function loadEngTab(type) {
    const list = document.getElementById('eng-modal-list');
    list.innerHTML = '<div style="color:white;padding:20px;text-align:center;">Loading...</div>';

    fetch('get_engagement.php?meme_id=' + currentEngMemeId + '&type=' + type)
    .then(res => res.json())
    .then(users => {
        currentEngUsers = users;
        renderEngList(users);
    });
}

function renderEngList(users) {
    const list = document.getElementById('eng-modal-list');
    list.innerHTML = '';

    if(users.length === 0){
        list.innerHTML = '<div style="color:rgba(255,255,255,0.5);padding:20px;text-align:center;">No one yet</div>';
        return;
    }

    users.forEach(u => {
        const pic = u.profile_pic || 'uploads/me.jpeg';
        list.innerHTML += `
        <div style="
            display:flex;
            align-items:center;
            gap:12px;
            padding:10px 16px;
            border-bottom:1px solid rgba(255,255,255,0.06);
        ">
            <img src="${pic}" onerror="this.src='uploads/me.jpeg'" style="
                width:40px;height:40px;
                border-radius:50%;
                object-fit:cover;
                border:2px solid red;
                flex-shrink:0;">
            <span style="
                color:white;
                font-weight:500;
                font-size:0.83rem;
                flex:1;
                overflow:hidden;
                text-overflow:ellipsis;
                white-space:nowrap;
            ">${u.username}</span>
            <a href="profile.php?user_id=${u.id}" style="
                padding:6px 14px;
                border-radius:20px;
                background:transparent;
                border:1px solid rgba(255,43,43,0.6);
                color:#f24455;
                font-size:0.72rem;
                font-weight:600;
                text-decoration:none;
                white-space:nowrap;
                flex-shrink:0;
                font-family:Poppins,sans-serif;
            ">View Profile</a>
        </div>`;
    });
}

function filterEngList() {
    const q = document.getElementById('eng-search').value.toLowerCase();
    const filtered = currentEngUsers.filter(u => u.username.toLowerCase().includes(q));
    renderEngList(filtered);
}

function closeEngModal(){
    document.getElementById('eng-modal').style.display = 'none';
}



/* ── FIX 4: Block user posts to home.php ── */
function blockUser(userId){
    if(!confirm('Block this user? You won\'t see their content anymore.')) return;
    fetch('home.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=block_user&target_id=' + userId
    })
    .then(function(res){ return res.json(); })
    .then(function(data){
        if(data.status === 'ok'){
            document.querySelectorAll('.card-unit').forEach(function(card){
                var trackBtn = card.querySelector('.track-btn[data-user="'+userId+'"]');
                if(trackBtn){
                    card.style.transition = 'opacity 0.4s';
                    card.style.opacity    = '0';
                    setTimeout(function(){ card.remove(); }, 400);
                }
            });
        }
    });
}

function toggleIndexKebab(e, memeId) {
    e.stopPropagation();
    // close all others first
    document.querySelectorAll('.index-kebab-dropdown').forEach(d => {
        if(d.id !== 'index-kebab-' + memeId) d.style.display = 'none';
    });
    const menu = document.getElementById('index-kebab-' + memeId);
    menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
}

// close when clicking anywhere else
document.addEventListener('click', function(){
    document.querySelectorAll('.index-kebab-dropdown').forEach(d => {
        d.style.display = 'none';
    });
});

function showMemeQR(memeId) {
    const url = window.location.origin + '/Peura/index.php?meme=' + memeId;
    const qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(url);
    const win = window.open('', '_blank', 'width=260,height=280');
    win.document.write('<body style="margin:0;background:#000;display:flex;align-items:center;justify-content:center;height:100vh;"><img src="'+qrUrl+'" style="border-radius:12px;"></body>');
}

function shareMeme(memeId) {
    const url = window.location.origin + '/Peura/index.php?meme=' + memeId;
    if(navigator.share){
        navigator.share({ title: 'Check this on PEURA', url: url });
    } else {
        navigator.clipboard.writeText(url);
        alert('Link copied to clipboard!');
    }
}


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


// MODAL FUNCTIONALITY
function openMedia(src,type,profile,user_id,username,caption){
    let modal=document.getElementById("modal");
    let modalMedia=document.getElementById("modalMedia");
    let modalInfo=document.getElementById("modalInfo");
    let commentSection=document.getElementById("commentSection");

    modalMedia.innerHTML="";
    modalInfo.innerHTML='<img src="'+profile+'"><a href="profile.php?user_id='+user_id+'">'+username+'</a> <span class="modal-caption">'+caption+'</span>';

    if(type === "image"){  
    modalMedia.innerHTML = '<img src="' + src + '">';
} 
else if(type === "text"){
    modalMedia.innerHTML = '<img src="uploads/Text.jpg">';
} 
else {
    modalMedia.innerHTML = '<video src="' + src + '" autoplay muted loop></video>';
}

    modal.style.display="flex";

    // Add event listener for comment form
    const commentForm = document.getElementById("commentForm");
    commentForm.onsubmit = function(e){
        e.preventDefault();
        const commentText = document.getElementById("commentInput").value;
        if(commentText.trim() !== ""){
            const formData = new FormData();
            formData.append("meme_id", src.split('/').pop());
            fetch("index.php", {
                method:"POST",
                body:formData
            }).then(()=> {
        
            });
        }
    }
}

function closeModal(e){
    if(e) e.stopPropagation();
    document.getElementById("modal").style.display="none";
}

/* ── FIX 1: sendAction posts to home.php ── */
function sendAction(action, memeId, callback){
    fetch("home.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: "action=" + action + "&meme_id=" + memeId
    })
    .then(function(res){ return res.json(); })
    .then(function(data){ callback(data); })
    .catch(function(err){ console.error(err); });
}

/* ── handleFeedAction — this is what the PHP-rendered cards already call
   via onclick="handleFeedAction('like', this)" / ('repost', this) /
   ('fav', this). That inline handler previously pointed at nothing, and
   the real work happened in three separate, disconnected .like-btn /
   .repost-btn / .fav-btn addEventListener blocks that only touched the
   clicked button's own DOM. Since ccmFeed's post entries hold a direct
   reference to the matching peuraFeed object, updating peuraFeed here
   is what keeps the reels-style modal (openCardModal / ccmAction) in
   sync — without it, likes/reposts/saves done from the grid only
   showed up in the modal after a full page refresh. ── */
function handleFeedAction(type, btn){
    var map = {
        like:   { action:'toggle_like',     countClass:'.like-count',   arrField:'liked',    cntField:'likeCount',   modalBtn:'ccm-like-btn',   modalCount:'ccm-like-count'   },
        repost: { action:'toggle_repost',   countClass:'.repost-count', arrField:'reposted', cntField:'repostCount', modalBtn:'ccm-repost-btn', modalCount:'ccm-repost-count' },
        fav:    { action:'toggle_favorite', countClass:'.fav-count',    arrField:'faved',    cntField:'favCount',    modalBtn:'ccm-fav-btn',    modalCount:'ccm-fav-count'    }
    };
    var m = map[type];
    if (!m) return;

    var memeId = btn.dataset.id;
    // home.php's toggle_* endpoints only return {count}, no boolean state,
    // so we flip locally the same way the old code implicitly did.
    var willBeActive = !btn.classList.contains('active');

    sendAction(m.action, memeId, function(data){
        btn.classList.toggle('active', willBeActive);
        var countEl = btn.querySelector(m.countClass);
        if (countEl && data.count !== undefined) countEl.textContent = data.count;

        // keep peuraFeed (and therefore ccmFeed, via shared object reference) in sync
        var item = (typeof peuraFeed !== 'undefined')
            ? peuraFeed.find(function(p){ return String(p.id) === String(memeId); })
            : null;
        if (item && data.count !== undefined) {
            item[m.cntField] = data.count;
            item[m.arrField] = willBeActive;
        }

        // if the reels modal is open on this exact post right now, update its rail live
        if (typeof _ccmMemeId !== 'undefined' && String(_ccmMemeId) === String(memeId)) {
            var modalBtn   = document.getElementById(m.modalBtn);
            var modalCount = document.getElementById(m.modalCount);
            if (modalBtn) modalBtn.classList.toggle('active', willBeActive);
            if (modalCount && data.count !== undefined) modalCount.textContent = data.count;
        }
    });
}
</script>
</body>
</html>