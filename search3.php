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

/* ── SCHEMA SAFETY: meme_articles author/date columns ── */
$col_check = $conn->query("SHOW COLUMNS FROM meme_articles LIKE 'created_by'");
if ($col_check && $col_check->num_rows === 0) {
    $conn->query("ALTER TABLE meme_articles ADD COLUMN created_by INT UNSIGNED DEFAULT NULL");
}
$col_check = $conn->query("SHOW COLUMNS FROM meme_articles LIKE 'created_at'");
if ($col_check && $col_check->num_rows === 0) {
    $conn->query("ALTER TABLE meme_articles ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
}
$col_check = $conn->query("SHOW COLUMNS FROM meme_articles LIKE 'summary'");
if ($col_check && $col_check->num_rows === 0) {
    $conn->query("ALTER TABLE meme_articles ADD COLUMN summary TEXT DEFAULT NULL");
}

/* ═══════════════════════════════════════════════════════
   HELPER: Batch-fetch up to 3 most recent posts per user
   (excludes 18+ posts and posts by blocked users)
═══════════════════════════════════════════════════════ */
function dz_batch_user_previews($conn, $user_ids, $current_user) {
    $previews = [];
    if (empty($user_ids)) return $previews;
    $ids = implode(',', array_map('intval', $user_ids));

    $blocked_sql = '';
    if ($current_user) {
        $b = [];
        $br = $conn->query("SELECT blocked_id FROM blocks WHERE blocker_id=$current_user");
        while ($r = $br->fetch_assoc()) $b[] = (int)$r['blocked_id'];
        if (!empty($b)) $blocked_sql = " AND m.uploaded_by NOT IN (" . implode(',', $b) . ") ";
    }

    $res = $conn->query("
        SELECT m.id, m.uploaded_by, m.file_name, m.type, m.caption, m.created_at
        FROM memes m
        WHERE m.uploaded_by IN ($ids)
          AND m.is_adult = 0
          $blocked_sql
        ORDER BY m.uploaded_by, m.created_at DESC
    ");
    while ($row = $res->fetch_assoc()) {
        $uid = (int)$row['uploaded_by'];
        if (!isset($previews[$uid])) $previews[$uid] = [];
        if (count($previews[$uid]) < 3) {
            $previews[$uid][] = [
                'id'        => (int)$row['id'],
                'file_name' => $row['file_name'],
                'type'      => $row['type'],
                'caption'   => $row['caption'],
            ];
        }
    }
    return $previews;
}

/* ═══════════════════════════════════════════════════════
   HELPER: Batch-fetch up to 3 most recent posts per group
═══════════════════════════════════════════════════════ */
function dz_batch_group_previews($conn, $group_ids) {
    $previews = [];
    if (empty($group_ids)) return $previews;
    $ids = implode(',', array_map('intval', $group_ids));

    $res = $conn->query("
        SELECT gp.group_id, m.id, m.file_name, m.type, m.caption, gp.created_at
        FROM group_posts gp
        JOIN memes m ON m.id = gp.meme_id
        WHERE gp.group_id IN ($ids)
        ORDER BY gp.group_id, gp.created_at DESC
    ");
    while ($row = $res->fetch_assoc()) {
        $gid = (int)$row['group_id'];
        if (!isset($previews[$gid])) $previews[$gid] = [];
        if (count($previews[$gid]) < 3) {
            $previews[$gid][] = [
                'id'        => (int)$row['id'],
                'file_name' => $row['file_name'],
                'type'      => $row['type'],
                'caption'   => $row['caption'],
            ];
        }
    }
    return $previews;
}

/* ═══════════════════════════════════════════════════════
   HELPER: Fetch bios for a batch of user_ids, respecting
   private-profile + approved-tracker rules (same logic
   as profile.php's $bio_visible check).
═══════════════════════════════════════════════════════ */
function dz_batch_user_bios($conn, $user_ids, $current_user) {
    $bios = [];
    if (empty($user_ids)) return $bios;
    $ids_str = implode(',', array_map('intval', $user_ids));

    $bio_map = [];
    $bio_res = $conn->query("
        SELECT user_id, bio, COALESCE(is_fully_private,0) AS is_private
        FROM user_settings
        WHERE user_id IN ($ids_str)
    ");
    while ($br = $bio_res->fetch_assoc()) {
        $bio_map[(int)$br['user_id']] = [
            'bio'        => $br['bio'] ?? '',
            'is_private' => (int)$br['is_private'],
        ];
    }

    $approved_ids = [];
    if ($current_user) {
        $ar = $conn->query("
            SELECT target_id FROM track_requests
            WHERE requester_id=$current_user
              AND target_id IN ($ids_str)
              AND status='approved'
        ");
        while ($arow = $ar->fetch_assoc()) $approved_ids[] = (int)$arow['target_id'];
    }

    foreach ($user_ids as $uid) {
        $uid = (int)$uid;
        $bio_text = '';
        if (isset($bio_map[$uid])) {
            $is_private_u = $bio_map[$uid]['is_private'];
            $visible = (!$is_private_u)
                || ($current_user == $uid)
                || in_array($uid, $approved_ids);
            if ($visible) $bio_text = $bio_map[$uid]['bio'];
        }
        $bios[$uid] = $bio_text;
    }
    return $bios;
}

/* ═══════════════════════════════════════════════════════
   HELPER: fetch this user's top hashtag affinities.
   (Mirrors home.php's getUserTopTags — used both for the
   type=ads tag-boost weighting AND could be reused for any
   future personalization on this page.)
═══════════════════════════════════════════════════════ */
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

/* ═══════════════════════════════════════════════════════
   HELPER: fetch Crossroads-style scored suggested users for
   $user_id. Mirrors home.php's getSuggestedUsers() so the
   discover-modal suggested carousel on THIS page produces
   the same overlap-based ranking + `reason` string instead
   of a flat ORDER BY RAND().
═══════════════════════════════════════════════════════ */
function getSuggestedUsers($conn, $user_id, $limit = 20, $exclude_ids = []){
    $sug_user_id = (int)$user_id;
    if(!$sug_user_id) return [];

    // blocked (both directions)
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
            $dzLike = 'DZ-' . $digits . '%';
        } elseif (strlen($q) >= 3) {
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
                'is_following'      => (int)$row['is_following'],
                'is_private'        => (int)$row['is_private'],
                'is_blocked'        => (int)$row['is_blocked'],
                'is_track_approved' => (int)$row['is_track_approved'],
            ];
        }
        $stmt->close();

        /* ── PODS ── */
        $stmt2 = $conn->prepare("
            SELECT
                g.id,
                g.title,
                g.group_pic,
                g.is_adult,
                g.created_by,
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
                'type'           => 'group',
                'id'             => (int)$row['id'],
                'title'          => $row['title'],
                'group_pic'      => $row['group_pic'] ?? '',
                'member_count'   => (int)$row['member_count'],
                'is_joined'      => (int)$row['is_joined'],
                'is_adult'       => (int)$row['is_adult'],
                'is_muted'       => (int)$row['is_muted'],
                'notif_off'      => (int)$row['notif_off'],
                'owner_id'       => (int)($row['created_by'] ?? 0),
                'owner_username' => $row['owner_username'] ?? '',
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
                ma.media_url,
                ma.media_type,
                ma.summary,
                ma.created_at,
                ma.created_by,
                au.username    AS author_username,
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
                'media_url'       => $row['media_url'] ?? '',
                'media_type'      => $row['media_type'] ?? '',
                'summary'         => $row['summary'] ?? '',
                'created_at'      => $row['created_at'] ?? '',
                'author_id'       => (int)($row['created_by'] ?? 0),
                'author_username' => $row['author_username'] ?? '',
                'author_pic'      => $row['author_pic'] ?? '',
            ];
        }
        $stmt7->close();

        /* ═══════════════════════════════════════════════
           BATCH ATTACH: preview posts + bios (users),
           preview posts (groups) — one/two extra queries
           total instead of N+1 per result.
        ═══════════════════════════════════════════════ */
        $preview_user_ids  = [];
        $preview_group_ids = [];
        foreach ($results as $r) {
            if ($r['type'] === 'user' || $r['type'] === 'profile_id') {
                $preview_user_ids[] = $r['id'];
            } elseif ($r['type'] === 'group') {
                $preview_group_ids[] = $r['id'];
            }
        }
        $preview_user_ids  = array_values(array_unique($preview_user_ids));
        $preview_group_ids = array_values(array_unique($preview_group_ids));

        $user_previews_map  = dz_batch_user_previews($conn, $preview_user_ids, $current_user);
        $group_previews_map = dz_batch_group_previews($conn, $preview_group_ids);
        $user_bios_map      = dz_batch_user_bios($conn, $preview_user_ids, $current_user);

        foreach ($results as &$r) {
            if ($r['type'] === 'user' || $r['type'] === 'profile_id') {
                $r['preview_posts'] = $user_previews_map[$r['id']] ?? [];
                $r['bio']           = $user_bios_map[$r['id']] ?? '';
            } elseif ($r['type'] === 'group') {
                $r['preview_posts'] = $group_previews_map[$r['id']] ?? [];
            }
        }
        unset($r);
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

        /* ── SAFETY NET: auto-create ad_dismissals / ad_recent_shows if
           missing, so a forgotten migration can't take down the whole
           ads feed. Mirrors home.php exactly. ── */
        $conn->query("
            CREATE TABLE IF NOT EXISTS `ad_dismissals` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED NOT NULL,
                `sponsorship_id` INT UNSIGNED NOT NULL,
                UNIQUE KEY `uniq_dismiss` (`user_id`,`sponsorship_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        $conn->query("
            CREATE TABLE IF NOT EXISTS `ad_recent_shows` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED NOT NULL,
                `sponsorship_id` INT UNSIGNED NOT NULL,
                `shown_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uniq_recent_show` (`user_id`,`sponsorship_id`)
            )
        ");

        /* ── Tag-affinity boost, same as home.php ── */
        list($user_top_tags_ads, $user_tag_weights_ads) = getUserTopTags($conn, $current_user);

        $boost_sql = "1.0";
        if (!empty($user_top_tags_ads)) {
            $cases = [];
            foreach ($user_top_tags_ads as $t) {
                $weight     = $user_tag_weights_ads[$t] ?? 1.0;
                $multiplier = min(2.5, round(1.0 + ($weight / 20.0), 3));
                $safe_tag   = $conn->real_escape_string($t);
                $cases[]    = "WHEN s.caption LIKE '%#$safe_tag%' THEN $multiplier";
            }
            $boost_sql = "CASE " . implode(' ', $cases) . " ELSE 1.0 END";
        }

        $FREQ_CAP_HOURS = 6;

        /* ── Main ads query: poster JOIN, like/fav/view counts + user
           state, frequency cap + dismissal exclusion, tag-boosted order ── */
        $stmt = $conn->prepare("
            SELECT s.*,
                u.username AS poster_username,
                u.profile_pic AS poster_pic,
                (SELECT COUNT(*) FROM ad_likes WHERE ad_likes.sponsorship_id = s.id) AS like_count,
                (SELECT COUNT(*) FROM ad_likes WHERE ad_likes.sponsorship_id = s.id AND ad_likes.user_id = ?) AS user_liked,
                (SELECT COUNT(*) FROM ad_favorites WHERE ad_favorites.sponsorship_id = s.id) AS fav_count,
                (SELECT COUNT(*) FROM ad_favorites WHERE ad_favorites.sponsorship_id = s.id AND ad_favorites.user_id = ?) AS user_faved,
                (SELECT COUNT(*) FROM ad_impressions WHERE ad_impressions.sponsorship_id = s.id) AS view_count
            FROM sponsorships s
            JOIN users u ON u.id = s.user_id
            WHERE s.status = 'approved'
              AND s.id NOT IN (
                  SELECT sponsorship_id FROM ad_dismissals WHERE user_id = ?
              )
              AND s.id NOT IN (
                  SELECT sponsorship_id FROM ad_recent_shows
                  WHERE user_id = ? AND shown_at >= NOW() - INTERVAL $FREQ_CAP_HOURS HOUR
              )
            ORDER BY ($boost_sql) * RAND() DESC
            LIMIT 10
        ");
        if($stmt){
            $stmt->bind_param("iiii", $current_user, $current_user, $current_user, $current_user);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) { $ads[] = $row; }

            /* ── DRY POOL FALLBACK: if capping + dismissals left too few
               ads, recycle already-seen ones (ignoring the freq cap) so
               we never show an empty ad row. ── */
            if(count($ads) < 3){
                $fallback_ids = array_map(function($a){ return (int)$a['id']; }, $ads);
                $exclude_sql  = "";
                if(!empty($fallback_ids)){
                    $exclude_sql = "AND s.id NOT IN (" . implode(',', $fallback_ids) . ")";
                }

                $stmt2 = $conn->prepare("
                    SELECT s.*,
                        u.username AS poster_username,
                        u.profile_pic AS poster_pic,
                        (SELECT COUNT(*) FROM ad_likes WHERE ad_likes.sponsorship_id = s.id) AS like_count,
                        (SELECT COUNT(*) FROM ad_likes WHERE ad_likes.sponsorship_id = s.id AND ad_likes.user_id = ?) AS user_liked,
                        (SELECT COUNT(*) FROM ad_favorites WHERE ad_favorites.sponsorship_id = s.id) AS fav_count,
                        (SELECT COUNT(*) FROM ad_favorites WHERE ad_favorites.sponsorship_id = s.id AND ad_favorites.user_id = ?) AS user_faved,
                        (SELECT COUNT(*) FROM ad_impressions WHERE ad_impressions.sponsorship_id = s.id) AS view_count
                    FROM sponsorships s
                    JOIN users u ON u.id = s.user_id
                    WHERE s.status = 'approved'
                      AND s.id NOT IN (
                          SELECT sponsorship_id FROM ad_dismissals WHERE user_id = ?
                      )
                      $exclude_sql
                    ORDER BY RAND()
                    LIMIT " . (10 - count($ads)) . "
                ");
                if($stmt2){
                    $stmt2->bind_param("iii", $current_user, $current_user, $current_user);
                    $stmt2->execute();
                    $res2 = $stmt2->get_result();
                    while ($row = $res2->fetch_assoc()) { $ads[] = $row; }
                }
            }
        } else {
            error_log("Ads query prepare() failed (search.php): " . $conn->error);
        }
        echo json_encode($ads);

    } elseif ($type === 'sugg') {
        /* ── Crossroads-style scored suggestions, same helper home.php
           uses, so this stays consistent with discover.php / home.php. ── */
        $sugg = $current_user ? getSuggestedUsers($conn, $current_user, 20) : [];
        echo json_encode($sugg);

    } elseif ($type === 'following') {
        /* ── People the current user tracks — used to populate the Share modal's recipient list ── */
        $following_list = [];
        if ($current_user) {
            $res = $conn->query("
                SELECT u.id, u.username, u.profile_pic
                FROM followers f
                JOIN users u ON f.following_id = u.id
                WHERE f.follower_id = $current_user
                ORDER BY u.username ASC
            ");
            while ($row = $res->fetch_assoc()) { $following_list[] = $row; }
        }
        echo json_encode($following_list);

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

/* ================= SEARCH LOGIC (PODS, USERS, COLLECTIONS, SOUNDS, SOUND FOLDERS, MEMEPEDIA) ================= */
$search_query      = trim($_GET['q'] ?? '');
$groups            = [];
$users             = [];
$collections       = [];
$sounds            = [];
$sound_folders     = [];
$memepedia_entries = [];
$user_previews_static  = [];   // [user_id => [post, post, post]]
$group_previews_static = [];   // [group_id => [post, post, post]]

if(!empty($search_query)){
    $like = "%$search_query%";

    /* ── PODS ── */
    $stmt3 = $conn->prepare("
        SELECT
            g.id,
            g.title,
            g.description,
            g.group_pic,
            g.is_adult,
            g.created_by,
            ou.username AS owner_username,
            (SELECT COUNT(*) FROM group_members gm WHERE gm.group_id = g.id) AS member_count,
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
    ");
    $stmt3->bind_param("iis", $current_user, $current_user, $like);
    $stmt3->execute();
    $groups = $stmt3->get_result();

    /* ── USERS ── */
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

    $static_user_ids = [];
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
        $static_user_ids[] = $uid;
        $users[] = $row;
    }

    /* Batch previews + bios for the static user list */
    $user_previews_static = dz_batch_user_previews($conn, $static_user_ids, $current_user);
    $static_user_bios     = dz_batch_user_bios($conn, $static_user_ids, $current_user);
    foreach ($users as &$u) {
        $u['bio'] = $static_user_bios[(int)$u['id']] ?? '';
    }
    unset($u);

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
        LIMIT 20
    ");
    $stmt4->bind_param("s", $like);
    $stmt4->execute();
    $res4 = $stmt4->get_result();
    while($row = $res4->fetch_assoc()){
        $collections[] = [
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
        LIMIT 20
    ");
    $stmt5->bind_param("s", $like);
    $stmt5->execute();
    $res5 = $stmt5->get_result();
    while($row = $res5->fetch_assoc()){
        $sounds[] = [
            'id'       => (int)$row['id'],
            'title'    => $row['title'],
            'user_id'  => (int)$row['user_id'],
            'username' => $row['username'],
        ];
    }
    $stmt5->close();

    /* ── SOUND FOLDERS (public only) ── */
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
        LIMIT 20
    ");
    $stmt6->bind_param("s", $like);
    $stmt6->execute();
    $res6 = $stmt6->get_result();
    while($row = $res6->fetch_assoc()){
        $sound_folders[] = [
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
            ma.media_url,
            ma.media_type,
            ma.summary,
            ma.created_at,
            ma.created_by,
            au.username    AS author_username,
            au.profile_pic AS author_pic
        FROM meme_articles ma
        LEFT JOIN users au ON au.id = ma.created_by
        WHERE ma.title LIKE ?
        ORDER BY ma.views DESC
        LIMIT 20
    ");
    $stmt7->bind_param("s", $like);
    $stmt7->execute();
    $res7 = $stmt7->get_result();
    while($row = $res7->fetch_assoc()){
        $memepedia_entries[] = [
            'id'              => (int)$row['id'],
            'title'           => $row['title'],
            'status'          => $row['status'],
            'category'        => $row['category'],
            'media_url'       => $row['media_url'] ?? '',
            'media_type'      => $row['media_type'] ?? '',
            'summary'         => $row['summary'] ?? '',
            'created_at'      => $row['created_at'] ?? '',
            'author_id'       => (int)($row['created_by'] ?? 0),
            'author_username' => $row['author_username'] ?? '',
            'author_pic'      => $row['author_pic'] ?? '',
        ];
    }
    $stmt7->close();

    /* Batch previews for the static group list (need ids first — clone-scan the result) */
    $static_group_ids = [];
    $groups_rows_cache = [];
    while ($g = $groups->fetch_assoc()) {
        $static_group_ids[] = (int)$g['id'];
        $groups_rows_cache[] = $g;
    }
    $group_previews_static = dz_batch_group_previews($conn, $static_group_ids);

    /* Rebuild $groups as a simple array so downstream template code
       (which does `while($g = $groups->fetch_assoc())`) still works —
       we replace the mysqli_result with an equivalent iterator-safe
       structure further down via $groups_rows_cache if needed. */
    $groups_array = $groups_rows_cache;
    // Re-run the query fresh so $groups remains a live mysqli_result
    // for any code expecting ->num_rows / ->fetch_assoc() unchanged.
    $stmt3b = $conn->prepare("
        SELECT
            g.id,
            g.title,
            g.description,
            g.group_pic,
            g.is_adult,
            g.created_by,
            ou.username AS owner_username,
            (SELECT COUNT(*) FROM group_members gm WHERE gm.group_id = g.id) AS member_count,
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
    ");
    $stmt3b->bind_param("iis", $current_user, $current_user, $like);
    $stmt3b->execute();
    $groups = $stmt3b->get_result();
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
    list($user_top_tags, $user_tag_weights) = getUserTopTags($conn, $current_user);
}

/* ================= FILTER & ORDERING SYSTEM ================= */
// ⛔ DO NOT TOUCH THIS BLOCK — leave exactly as-is
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
// ⛔ END DO-NOT-TOUCH


/* ================= QUERY BUILDER ================= */
// ✅ THIS IS THE BLOCK TO REPLACE — everything from here down to
// (but not including) $stmt = $conn->prepare(...)
$sql = "SELECT m.*, u.username, u.nickname, u.profile_pic,

    COALESCE(like_agg.cnt, 0)    AS like_count,
    COALESCE(repost_agg.cnt, 0)  AS repost_count,
    COALESCE(fav_agg.cnt, 0)     AS fav_count,
    COALESCE(share_agg.cnt, 0)   AS share_count,

    CASE WHEN user_like.id     IS NOT NULL THEN 1 ELSE 0 END AS user_liked,
    CASE WHEN user_repost.id   IS NOT NULL THEN 1 ELSE 0 END AS user_reposted,
    CASE WHEN user_fav.id      IS NOT NULL THEN 1 ELSE 0 END AS user_fav,
    CASE WHEN follow_check.id  IS NOT NULL THEN 1 ELSE 0 END AS is_following,

    ma.cta_text  AS ad_cta_text,
    ma.cta_url   AS ad_cta_url,
    ma.cta_color AS ad_cta_color

    FROM memes m
    JOIN users u ON m.uploaded_by = u.id

    LEFT JOIN (SELECT meme_id, COUNT(*) AS cnt FROM likes     GROUP BY meme_id) like_agg    ON like_agg.meme_id    = m.id
    LEFT JOIN (SELECT meme_id, COUNT(*) AS cnt FROM reposts   GROUP BY meme_id) repost_agg  ON repost_agg.meme_id  = m.id
    LEFT JOIN (SELECT meme_id, COUNT(*) AS cnt FROM favorites GROUP BY meme_id) fav_agg     ON fav_agg.meme_id     = m.id
    LEFT JOIN (SELECT meme_id, COUNT(*) AS cnt FROM shares    GROUP BY meme_id) share_agg   ON share_agg.meme_id   = m.id

    LEFT JOIN likes     user_like    ON user_like.meme_id    = m.id AND user_like.user_id    = ?
    LEFT JOIN reposts   user_repost  ON user_repost.meme_id  = m.id AND user_repost.user_id  = ?
    LEFT JOIN favorites user_fav     ON user_fav.meme_id     = m.id AND user_fav.user_id     = ?
    LEFT JOIN followers follow_check ON follow_check.follower_id = ? AND follow_check.following_id = m.uploaded_by
    LEFT JOIN meme_ads  ma            ON ma.meme_id = m.id";        /* ← NEW */

$params = [$current_user, $current_user, $current_user, $current_user];
$types  = "iiii";

$where  = " WHERE 1=1 ";

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

if(!empty($blocked_users)){
    $in      = implode(',', array_fill(0, count($blocked_users), '?'));
    $where  .= " AND m.uploaded_by NOT IN ($in) ";
    $params  = array_merge($params, $blocked_users);
    $types  .= str_repeat('i', count($blocked_users));
}

if(!empty($muted_users)){
    $in      = implode(',', array_fill(0, count($muted_users), '?'));
    $where  .= " AND m.uploaded_by NOT IN ($in) ";
    $params  = array_merge($params, $muted_users);
    $types  .= str_repeat('i', count($muted_users));
}

if(!empty($muted_group_meme_ids)){
    $in      = implode(',', array_fill(0, count($muted_group_meme_ids), '?'));
    $where  .= " AND m.id NOT IN ($in) ";
    $params  = array_merge($params, $muted_group_meme_ids);
    $types  .= str_repeat('i', count($muted_group_meme_ids));
}

/* ── 18+ FILTER ── */
$where   .= " AND (
    m.is_adult = 0
    OR EXISTS (
        SELECT 1 FROM group_posts gp
        JOIN group_members gm ON gm.group_id = gp.group_id
        WHERE gp.meme_id = m.id
        AND gm.user_id = ?
    )
) ";
$params[] = $current_user;
$types   .= "i";

/* ── AD APPROVAL — hide unapproved ad-tagged memes ── */    /* ← NEW */
$where .= " AND (m.is_ad = 0 OR ma.status = 'approved') ";    /* ← NEW */

if($filter === 'following' && $current_user){
    $where   .= " AND m.uploaded_by IN (SELECT following_id FROM followers WHERE follower_id=?) ";
    $params[] = $current_user;
    $types   .= "i";
}

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

$memes = $all_rows;

/* ── Pre-fetch all media items for every Zeem (multi-media posts) ── */
$_media_map = [];
if(!empty($meme_ids)) {
    $ids_str = implode(',', array_map('intval', $meme_ids));
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

    /* ── AD LIKE (sponsorships) ── */
    if($action === "toggle_ad_like"){
        if(!$current_user){
            echo json_encode(["status" => "error", "message" => "not logged in"]);
            exit;
        }

        $sponsorship_id = intval($_POST['sponsorship_id'] ?? 0);
        if(!$sponsorship_id){
            echo json_encode(["status" => "error", "message" => "invalid ad"]);
            exit;
        }

        $stmt = $conn->prepare("SELECT id FROM ad_likes WHERE user_id=? AND sponsorship_id=?");
        $stmt->bind_param("ii", $current_user, $sponsorship_id);
        $stmt->execute();
        $check = $stmt->get_result();

        if($check->num_rows){
            $stmt = $conn->prepare("DELETE FROM ad_likes WHERE user_id=? AND sponsorship_id=?");
            $stmt->bind_param("ii", $current_user, $sponsorship_id);
            $stmt->execute();
        } else {
            $stmt = $conn->prepare("INSERT INTO ad_likes (user_id,sponsorship_id) VALUES (?,?)");
            $stmt->bind_param("ii", $current_user, $sponsorship_id);
            $stmt->execute();
        }

        $stmt = $conn->prepare("SELECT COUNT(*) as c FROM ad_likes WHERE sponsorship_id=?");
        $stmt->bind_param("i", $sponsorship_id);
        $stmt->execute();
        $count = $stmt->get_result()->fetch_assoc()['c'];

        echo json_encode(["status" => "ok", "count" => $count]);
        exit;
    }

    /* ── AD FAVORITE (sponsorships) ── */
    if($action === "toggle_ad_favorite"){
        if(!$current_user){
            echo json_encode(["status" => "error", "message" => "not logged in"]);
            exit;
        }

        $sponsorship_id = intval($_POST['sponsorship_id'] ?? 0);
        if(!$sponsorship_id){
            echo json_encode(["status" => "error", "message" => "invalid ad"]);
            exit;
        }

        $stmt = $conn->prepare("SELECT id FROM ad_favorites WHERE user_id=? AND sponsorship_id=?");
        $stmt->bind_param("ii", $current_user, $sponsorship_id);
        $stmt->execute();
        $check = $stmt->get_result();

        if($check->num_rows){
            $stmt = $conn->prepare("DELETE FROM ad_favorites WHERE user_id=? AND sponsorship_id=?");
            $stmt->bind_param("ii", $current_user, $sponsorship_id);
            $stmt->execute();
        } else {
            $stmt = $conn->prepare("INSERT INTO ad_favorites (user_id,sponsorship_id) VALUES (?,?)");
            $stmt->bind_param("ii", $current_user, $sponsorship_id);
            $stmt->execute();
        }

        $stmt = $conn->prepare("SELECT COUNT(*) as c FROM ad_favorites WHERE sponsorship_id=?");
        $stmt->bind_param("i", $sponsorship_id);
        $stmt->execute();
        $count = $stmt->get_result()->fetch_assoc()['c'];

        echo json_encode(["status" => "ok", "count" => $count]);
        exit;
    }

    /* ── LOG AD IMPRESSION ── */
    if($action === "log_ad_impression"){
        $sponsorship_id = intval($_POST['sponsorship_id'] ?? 0);
        if($sponsorship_id){
            $stmt = $conn->prepare("INSERT INTO ad_impressions (sponsorship_id, user_id) VALUES (?, ?)");
            if($current_user){
                $stmt->bind_param("ii", $sponsorship_id, $current_user);
            } else {
                $null = null;
                $stmt->bind_param("ii", $sponsorship_id, $null);
            }
            $stmt->execute();
        }
        echo json_encode(["status" => "ok"]);
        exit;
    }

    /* ── LOG AD SHOWN — for frequency-capping, separate from billing impression ── */
    if($action === "log_ad_shown"){
        $sponsorship_id = intval($_POST['sponsorship_id'] ?? 0);
        if($sponsorship_id && $current_user){
            $conn->query("
                CREATE TABLE IF NOT EXISTS `ad_recent_shows` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `user_id` INT UNSIGNED NOT NULL,
                    `sponsorship_id` INT UNSIGNED NOT NULL,
                    `shown_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY `uniq_recent_show` (`user_id`,`sponsorship_id`)
                )
            ");
            $stmt = $conn->prepare("
                INSERT INTO ad_recent_shows (user_id, sponsorship_id, shown_at)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE shown_at = NOW()
            ");
            $stmt->bind_param("ii", $current_user, $sponsorship_id);
            $stmt->execute();
        }
        echo json_encode(["status" => "ok"]);
        exit;
    }

    /* ── AD "NOT INTERESTED" ──
       Requires ad_dismissals table (auto-created in the type=ads
       GET handler above). The type=ads fetch already excludes
       dismissed ads via NOT IN (SELECT sponsorship_id FROM
       ad_dismissals ...). ── */
    if($action === "ad_not_interested"){
        $sponsorship_id = intval($_POST['sponsorship_id'] ?? 0);
        if($sponsorship_id && $current_user){
            $stmt = $conn->prepare("INSERT IGNORE INTO ad_dismissals (user_id, sponsorship_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $current_user, $sponsorship_id);
            $stmt->execute();
        }
        echo json_encode(['status' => 'ok']);
        exit;
    }

    /* ── share_post ── */
    if($action === 'share_post' && $meme_id){
        if(!$current_user){
            echo json_encode(['status'=>'error','message'=>'Not logged in']);
            exit;
        }

        $receiver_ids_raw = $_POST['receiver_ids'] ?? '';
        $receiver_ids = array_filter(array_map('intval', explode(',', $receiver_ids_raw)));

        if(empty($receiver_ids)){
            echo json_encode(['status'=>'error','message'=>'No recipients selected']);
            exit;
        }

        $sent_to = [];
        foreach($receiver_ids as $rid){
            if($rid === $current_user) continue;

            $stmt = $conn->prepare("INSERT INTO shares (meme_id, user_id, receiver_id) VALUES (?, ?, ?)");
            $stmt->bind_param("iii", $meme_id, $current_user, $rid);
            $stmt->execute();

            $stmt2 = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, meme_id, seen) VALUES (?, ?, ?, 0)");
            $stmt2->bind_param("iii", $current_user, $rid, $meme_id);
            $stmt2->execute();

            $sent_to[] = $rid;
        }

        $count = $conn->query("SELECT COUNT(*) as c FROM shares WHERE meme_id=$meme_id")->fetch_assoc()['c'];
        echo json_encode(['status'=>'ok', 'count'=>$count, 'sent_to'=>$sent_to]);
        exit;
    }

            /* ── block_user ── */
        if($action === 'block_user' && isset($_POST['target_id'])){
            $target = (int)$_POST['target_id'];
            if($current_user && $target && $target !== $current_user){
                $stmt = $conn->prepare("INSERT IGNORE INTO blocks (blocker_id, blocked_id) VALUES (?, ?)");
                $stmt->bind_param("ii", $current_user, $target);
                $stmt->execute();

                $stmt2 = $conn->prepare("DELETE FROM followers WHERE (follower_id=? AND following_id=?) OR (follower_id=? AND following_id=?)");
                $stmt2->bind_param("iiii", $current_user, $target, $target, $current_user);
                $stmt2->execute();

                echo json_encode(['status' => 'ok']);
            } else {
                echo json_encode(['status' => 'error']);
            }
            exit;
        }

        /* ── not_interested ── */
        if($action === 'not_interested' && $meme_id){
            if($current_user){
                $conn->query("INSERT IGNORE INTO user_interactions (user_id, meme_id, interaction_type) VALUES ($current_user, $meme_id, 'view')");
                $conn->query("UPDATE memes SET trend_velocity = trend_velocity * 0.5 WHERE id = $meme_id");
            }
            echo json_encode(['status' => 'ok']);
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
    grid-template-columns: 1fr 1fr 1fr 1fr 1fr;
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
   RESPONSIVE — 790px (MOBILE)
══════════════════════════════════════════ */
@media (max-width: 790px) {

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

.discover-multi-badge {
    position: absolute;
    top: 48px;
    right: 8px;
    z-index: 6;
    display: flex;
    align-items: center;
    gap: 4px;
    background: rgba(0,0,0,0.55);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    color: #fff;
    font-size: clamp(9px, 1.8vw, 10.5px);
    font-weight: 700;
    padding: 3px 7px;
    border-radius: 20px;
    pointer-events: none;
}
.discover-multi-badge i { font-size: clamp(9.5px, 1.9vw, 11px); }
</style>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>

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
    font-size:1.4rem;
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
    // closeDezzmeSideMenu() (already defined globally) closes it
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

let _appTopZIndex = 2147483000; // higher than any other static z-index on the page
function bringModalToFront(el){
    if(!el) return;
    el.style.zIndex = ++_appTopZIndex;
}
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

<!-- ══════════════════════════════════════════════════════════════
     DEZZME — UNIFIED SEARCH MODAL
     One modal (#searchModal) — two internal views:
       #smViewSearch   → typing / recents / live autocomplete
       #smViewResults  → full tabbed results (Posts/Dezzmers/Pods/etc)

     No page navigation happens anymore. A full search fetches
     THIS SAME PAGE (search.php?q=...) via fetch(), pulls the
     freshly server-rendered #smResultsInner markup out of the
     response, and swaps it straight into the already-open modal.
     PHP on the backend is untouched — it still just renders
     #smResultsInner whenever $_GET['q'] is set, exactly like before.
════════════════════════════════════════════════════════════════ -->
<div id="searchModal">

    <!-- ══════════════ VIEW 1 — SEARCH / TYPING ══════════════ -->
    <div id="smViewSearch" class="sm-view sm-view-active">

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
    <!-- ══════════════ /VIEW 1 ══════════════ -->


    <!-- ══════════════ VIEW 2 — FULL RESULTS ══════════════ -->
    <div id="smViewResults" class="sm-view">
        <div id="smResultsLoading" class="srm-loading">
            <i class="ri-loader-4-line sm-spin"></i>
        </div>

        <!--
            Everything inside #smResultsInner is exactly the PHP block
            that used to live inside the old standalone #searchResultsModal.
            It is rendered server-side on every load of search.php where
            $_GET['q'] is set — whether that's a direct/shared link (full
            page load) or a fetch() call we make in the background from
            inside the already-open modal (see smRunFullSearch below).
        -->
        <div id="smResultsInner">
        <?php
        $has_results = !empty($users)
            || !empty($memes)
            || ($groups && $groups->num_rows > 0)
            || !empty($collections)
            || !empty($sounds)
            || !empty($sound_folders)
            || !empty($memepedia_entries);

        /* Truncate a name/title to $limit chars, add "…" if cut */
        function srm_truncate_name($name, $limit = 15) {
            $name = (string)$name;
            if (mb_strlen($name) > $limit) {
                return mb_substr($name, 0, $limit) . '…';
            }
            return $name;
        }

        /* Truncate body text by word count; returns HTML with a colored
           "...see more" span appended if the text was cut. */
        function srm_truncate_words($text, $wordLimit, $colorClass) {
            $text = trim((string)$text);
            if ($text === '') return '';
            $words = preg_split('/\s+/u', $text);
            if (count($words) <= $wordLimit) {
                return htmlspecialchars($text);
            }
            $short = implode(' ', array_slice($words, 0, $wordLimit));
            return htmlspecialchars($short) . '<span class="srm-see-more ' . $colorClass . '">...see more</span>';
        }

        /* Build a 3-post tile grid + a 4th "Check Out" mini-card tile.
           $type is 'user' or 'group' — controls the color/shape theme.
           $avatarSrc is the profile/group pic to show inside the tile. */
        function srm_build_preview_grid($posts, $see_more_href, $type, $name, $avatarSrc, $defaultAvatar) {
            $posts = $posts ?? [];
            $html  = '<div class="srm-card-grid">';
            for ($i = 0; $i < 3; $i++) {
                $p = $posts[$i] ?? null;
                if ($p) {
                    $file = htmlspecialchars($p['file_name'] ?? '');
                    $ptype = $p['type'] ?? 'image';
                    if ($ptype === 'video') {
                        $html .= '<div class="srm-card-tile" onclick="event.stopPropagation();smNavigateInApp(\''.$see_more_href.'\')">'
                            . '<video src="uploads/'.$file.'" muted playsinline preload="metadata"></video>'
                            . '<i class="ri-play-fill srm-card-tile-play"></i>'
                            . '</div>';
                    } else {
                        $thumb = ($ptype === 'image' && $file) ? 'uploads/'.$file : 'uploads/Text.jpg';
                        $html .= '<div class="srm-card-tile" onclick="event.stopPropagation();smNavigateInApp(\''.$see_more_href.'\')">'
                            . '<img src="'.htmlspecialchars($thumb).'" onerror="this.src=\'uploads/Text.jpg\'">'
                            . '</div>';
                    }
                } else {
                    $html .= '<div class="srm-card-tile srm-card-tile-empty"><i class="ri-image-line"></i></div>';
                }
            }

            /* 4th tile: "Check Out" mini card — grey bg, avatar, name, See more button */
            $isGroup      = ($type === 'group');
            $themeClass   = $isGroup ? 'srm-checkout-blue' : 'srm-checkout-red';
            $avatarClass  = $isGroup ? 'srm-checkout-avatar-grp' : 'srm-checkout-avatar-user';
            $shortName    = htmlspecialchars(srm_truncate_name($name, 15));
            $avatarSrcSafe = htmlspecialchars($avatarSrc);
            $defaultSafe   = htmlspecialchars($defaultAvatar);

            $html .= '<div class="srm-card-tile srm-checkout-tile '.$themeClass.'" onclick="event.stopPropagation();smNavigateInApp(\''.$see_more_href.'\')">'
                . '<img class="srm-checkout-avatar '.$avatarClass.'" src="'.$avatarSrcSafe.'" onerror="this.src=\''.$defaultSafe.'\';">'
                . '<span class="srm-checkout-label">Check Out</span>'
                . '<span class="srm-checkout-name">'.$shortName.'</span>'
                . '<span class="srm-checkout-btn">See more</span>'
                . '</div>';

            $html .= '</div>';
            return $html;
        }
        ?>

        <!-- RESULTS TOP BAR -->
        <div class="srm-topbar">
            <div class="srm-title-wrap">
                <div class="srm-title-row">
                    <?php if($search_query): ?>
                    <div class="srm-search-pill">
                        <button type="button"
                                id="srmPillText"
                                class="srm-search-pill-text"
                                onclick='smReopenSearchInput(<?php echo json_encode($search_query); ?>)'>
                            <?php echo htmlspecialchars($search_query); ?>
                        </button>
                        <span class="srm-pill-divider"></span>
                        <a href="search.php" id="srmClearBtn" class="srm-clear-btn" onclick="return smClearFullSearch(event)">Clear</a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- STICKY UNDERLINE TABS -->
        <div class="srm-tabs-wrap">
            <div class="srm-tabs" id="srmTabs">
                <button class="srm-tab active" data-filter="all">For You</button>
                <button class="srm-tab" data-filter="posts">Posts</button>
                <button class="srm-tab" data-filter="dezzmers">Dezzmers</button>
                <button class="srm-tab" data-filter="pods">Pods</button>
                <button class="srm-tab" data-filter="memepedia">Memepedia</button>
                <button class="srm-tab" data-filter="collections">Collections</button>
                <button class="srm-tab" data-filter="sounds">Sounds</button>
                <button class="srm-tab" data-filter="soundfolders">Sound Folders</button>
            </div>
        </div>

        <!-- RESULTS CONTENT -->
        <div class="srm-content">
        <?php if($search_query): ?>

            <?php if(!$has_results): ?>
            <div class="srm-empty">
                <i class="ri-search-2-line"></i>
                <p>No results for "<?php echo htmlspecialchars($search_query); ?>"</p>
                <small>Try a different word, hashtag, or Profile ID (DZ-…)</small>
            </div>
            <?php endif; ?>

            <?php ob_start(); ?>
            <!-- ═══════ POSTS ═══════ -->
           <?php if(!empty($memes)): ?>
<?php $srm_shuffled_memes = $memes; /* no shuffle — must match peuraFeed order */ ?>
<script>if(typeof srmSearchFeed === "undefined") var srmSearchFeed = []; srmSearchFeed.length = 0;</script>

<?php
/* ═══════════════════════════════════════════════════════
   Search-results-scoped media/group/audio/social-proof maps.
   $_media_map / $_group_map / $_audio_map / $social_proof_map
   built earlier in the file only cover the main grid's
   $meme_ids — a search result can include posts that never
   appeared in the grid, so those maps would miss them entirely
   (no audio, no group tag, no social proof). Build parallel
   maps scoped to $srm_shuffled_memes's own IDs here instead.
═══════════════════════════════════════════════════════ */
$srm_ids = array_map(function($m){ return (int)$m['id']; }, $srm_shuffled_memes);
$_srm_media_map = [];
$_srm_group_map = [];
$_srm_audio_map = [];
$_srm_social_proof_map = [];

if(!empty($srm_ids)){
    $srm_ids_str = implode(',', $srm_ids);

    $mm_res = $conn->query("SELECT meme_id, file_name, type, position FROM meme_media WHERE meme_id IN ($srm_ids_str) ORDER BY meme_id, position ASC");
    if($mm_res) while($mm = $mm_res->fetch_assoc()){
        $mid = (int)$mm['meme_id'];
        if(!isset($_srm_media_map[$mid])) $_srm_media_map[$mid] = [];
        $_srm_media_map[$mid][] = ['file'=>$mm['file_name'], 'type'=>$mm['type']];
    }

    $gp_res = $conn->query("SELECT gp.meme_id, g.id AS group_id, g.title AS group_title FROM group_posts gp JOIN groups g ON g.id=gp.group_id WHERE gp.meme_id IN ($srm_ids_str)");
    if($gp_res) while($gp = $gp_res->fetch_assoc()) $_srm_group_map[(int)$gp['meme_id']] = $gp;

    $au_res = $conn->query("SELECT ma.meme_id, a.id AS audio_id, a.title, a.artist, a.file_name, ma.is_original, ma.music_volume, ma.video_audio_volume FROM meme_audio ma JOIN audios a ON a.id=ma.audio_id WHERE ma.meme_id IN ($srm_ids_str)");
    if($au_res) while($au = $au_res->fetch_assoc()) $_srm_audio_map[(int)$au['meme_id']] = $au;

    /* backfill native audio for videos with nothing attached (mirrors the grid's own backfill) */
    foreach($srm_shuffled_memes as $mrow){
        $mid = (int)$mrow['id'];
        if(($mrow['type'] ?? '') !== 'video') continue;
        if(isset($_srm_audio_map[$mid])) continue;

        $existing = $conn->query("SELECT id FROM audios WHERE source_meme_id=$mid LIMIT 1")->fetch_assoc();
        if($existing){
            $aid = (int)$existing['id'];
        } else {
            $uploader_id = (int)($mrow['uploaded_by'] ?? 0);
            $file_name   = $conn->real_escape_string($mrow['file_name']);
            $conn->query("INSERT INTO audios (uploader_id, title, file_name, is_original, source_meme_id, created_at) VALUES ($uploader_id, 'Original audio', '$file_name', 1, $mid, NOW())");
            $aid = $conn->insert_id;
            if($aid){
                $conn->query("INSERT INTO meme_audio (meme_id, audio_id, is_original, music_volume, video_audio_volume) VALUES ($mid, $aid, 1, 100, 100)");
            }
        }
        if($aid){
            $_srm_audio_map[$mid] = [
                'meme_id'=>$mid,'audio_id'=>$aid,'title'=>'Original audio','artist'=>null,
                'file_name'=>$mrow['file_name'],'is_original'=>1,'music_volume'=>100,'video_audio_volume'=>100
            ];
        }
    }

    if($current_user){
        $sp_result = $conn->query("
            SELECT l.meme_id, u.id, u.username, u.profile_pic, 'like' AS action_type
            FROM likes l JOIN users u ON u.id=l.user_id JOIN followers f ON f.following_id=u.id
            WHERE l.meme_id IN ($srm_ids_str) AND f.follower_id=$current_user AND u.id != $current_user
            UNION ALL
            SELECT r.meme_id, u.id, u.username, u.profile_pic, 'repost' AS action_type
            FROM reposts r JOIN users u ON u.id=r.user_id JOIN followers f ON f.following_id=u.id
            WHERE r.meme_id IN ($srm_ids_str) AND f.follower_id=$current_user AND u.id != $current_user
        ");
        $_srm_sp_raw = [];
        if($sp_result) while($sp = $sp_result->fetch_assoc()){
            $mid = $sp['meme_id'];
            if(!isset($_srm_sp_raw[$mid])) $_srm_sp_raw[$mid] = [];
            $_srm_sp_raw[$mid][] = $sp;
        }
        foreach($_srm_sp_raw as $mid => $rows){
            usort($rows, function($a,$b){ return ($a['action_type']==='repost'?0:1) <=> ($b['action_type']==='repost'?0:1); });
            $_srm_social_proof_map[$mid] = array_slice($rows, 0, 4);
        }
    }
}
?>

<div class="srm-section" data-section="posts">
    <div class="srm-sec-title">Posts</div>
    <div class="srm-posts-grid">
    <?php $srm_feed_idx = 0; /* tracks position in srmSearchFeed, separate from foreach index since we `continue` on blocked/private posts */ ?>
    <?php foreach($srm_shuffled_memes as $m): ?>
    <?php
        $m_uploader_id = (int)$m['uploaded_by'];
        if($m_uploader_id != (int)$current_user && !empty($_private_profile_map[$m_uploader_id]) && empty($_approved_map[$m_uploader_id])) continue;

        $m_safe_file     = addslashes($m['file_name']);
        $m_safe_type     = addslashes($m['type']);
        $m_safe_pic      = addslashes(!empty($m['profile_pic']) ? $m['profile_pic'] : 'uploads/mee.jpeg');
        $m_safe_username = addslashes($m['username']);
        $m_safe_caption  = addslashes(str_replace(["\r","\n"], ' ', $m['caption']));
        $m_modal_args    = "'uploads/{$m_safe_file}','{$m_safe_type}','{$m_safe_pic}','{$m['uploaded_by']}','{$m_safe_username}','{$m_safe_caption}'";
        $m_post_pic      = !empty($m['profile_pic']) ? $m['profile_pic'] : 'uploads/mee.jpeg';

        $m_raw_date = $m['created_at'] ?? $m['uploaded_at'] ?? $m['upload_date'] ?? $m['date_posted'] ?? $m['timestamp'] ?? null;
        $m_date_str = $m_raw_date ? date('j M', strtotime($m_raw_date)) : '';

        $m_is_text_post = ($m['type'] !== 'video' && $m['type'] !== 'image');

        /* ── FULL FEED DATA — same shape as peuraFeed so the discover
           modal renders nickname, date, counts, toggle state, gallery,
           audio, ads, and social proof identically to the grid modal ── */
        $m_id = (int)$m['id'];
        $m_media_items = [[ 'file' => $m['file_name'], 'type' => $m['type'] ]];
        if(!empty($_srm_media_map[$m_id])){
            $m_media_items = array_merge($m_media_items, $_srm_media_map[$m_id]);
        }
        $m_group_info  = $_srm_group_map[$m_id] ?? null;
        $m_audio_info  = $_srm_audio_map[$m_id] ?? null;
        $m_sp_for_meme = $_srm_social_proof_map[$m_id] ?? [];
        $m_sp_json = json_encode(array_map(function($sp){
            return [
                'id'          => (int)$sp['id'],
                'username'    => $sp['username'],
                'profile_pic' => $sp['profile_pic'],
                'action_type' => $sp['action_type'],
            ];
        }, $m_sp_for_meme));

        echo '<script>
        srmSearchFeed.push({
            id:'.$m_id.',
            file:"uploads/'.$m_safe_file.'",
            type:"'.$m_safe_type.'",
            media:'.json_encode(array_map(function($mm){
                         return ['file' => 'uploads/'.$mm['file'], 'type' => $mm['type']];
                     }, $m_media_items)).',
            pic:"'.$m_safe_pic.'",
            userId:"'.(int)$m['uploaded_by'].'",
            username:"'.$m_safe_username.'",
            nickname:"'.addslashes($m['nickname'] ?? '').'",
            caption:"'.$m_safe_caption.'",
            time:"'.addslashes($m['created_at'] ?? '').'",
            likeCount:'.(int)($m['like_count'] ?? 0).',
            repostCount:'.(int)($m['repost_count'] ?? 0).',
            favCount:'.(int)($m['fav_count'] ?? 0).',
            shareCount:'.(int)($m['share_count'] ?? 0).',
            liked:'.(!empty($m['user_liked']) ? 'true' : 'false').',
            reposted:'.(!empty($m['user_reposted']) ? 'true' : 'false').',
            faved:'.(!empty($m['user_fav']) ? 'true' : 'false').',
            isPartnership: '.(!empty($m['is_partnership']) ? 'true' : 'false').',
            partnerBrand:  '.(!empty($m['partner_brand']) ? '"'.addslashes($m['partner_brand']).'"' : 'null').',
            isAiGenerated: '.(!empty($m['is_ai_generated']) ? 'true' : 'false').',
            isAd:          '.(!empty($m['is_ad']) ? 'true' : 'false').',
            adCtaText:     '.(!empty($m['ad_cta_text']) ? '"'.addslashes($m['ad_cta_text']).'"' : '""').',
            adCtaUrl:      '.(!empty($m['ad_cta_url']) ? '"'.addslashes($m['ad_cta_url']).'"' : '""').',
            adCtaColor:    '.(!empty($m['ad_cta_color']) ? '"'.addslashes($m['ad_cta_color']).'"' : '"#FF4C4C"').',
            groupId:     '.($m_group_info ? (int)$m_group_info['group_id'] : 'null').',
            groupTitle:  '.($m_group_info ? '"'.addslashes($m_group_info['group_title']).'"' : 'null').',
            audioId:     '.($m_audio_info ? (int)$m_audio_info['audio_id'] : 'null').',
            audioTitle:  '.($m_audio_info ? '"'.addslashes($m_audio_info['title']).'"' : 'null').',
            audioArtist: '.($m_audio_info && !empty($m_audio_info['artist']) ? '"'.addslashes($m_audio_info['artist']).'"' : 'null').',
            audioFile:   '.($m_audio_info ? '"uploads/'.addslashes($m_audio_info['file_name']).'"' : 'null').',
            audioIsOriginal: '.($m_audio_info && !empty($m_audio_info['is_original']) ? 'true' : 'false').',
            musicVolume: '.($m_audio_info ? (int)$m_audio_info['music_volume'] : 80).',
            videoAudioVolume: '.($m_audio_info ? (int)$m_audio_info['video_audio_volume'] : 40).',
            socialProof: '.$m_sp_json.'
        });
        </scr'.'ipt>';

        $m_srm_index = $srm_feed_idx; // capture before increment, used in onclick below
        $srm_feed_idx++;
    ?>
    <div class="srm-post-card" onclick="openDiscoverModalFromSearch(<?php echo $m_srm_index; ?>)">
                    <div class="srm-post-media">
                        <?php if($m['type'] === 'video'): ?>
                            <video src="uploads/<?php echo htmlspecialchars($m['file_name']); ?>" muted autoplay loop playsinline preload="metadata"></video>
                            <i class="ri-play-fill srm-post-play"></i>
                        <?php elseif($m['type'] === 'image'): ?>
                            <img src="uploads/<?php echo htmlspecialchars($m['file_name']); ?>" onerror="this.src='uploads/mee.jpeg'">
                        <?php else: ?>
                            <img src="uploads/Text.jpg" onerror="this.src='uploads/mee.jpeg'">
                            <div class="srm-post-text-overlay"><?php echo htmlspecialchars($m['caption']); ?></div>
                        <?php endif; ?>
                        <?php if($m_date_str): ?>
                            <span class="srm-post-date"><?php echo htmlspecialchars($m_date_str); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if(!$m_is_text_post): ?>
                        <div class="srm-post-caption"><?php echo htmlspecialchars($m['caption']); ?></div>
                    <?php endif; ?>
                    <div class="srm-post-meta">
                        <img class="srm-post-avatar" src="<?php echo htmlspecialchars($m_post_pic); ?>" onerror="this.src='uploads/mee.jpeg'">
                        <span class="srm-post-username"><?php echo htmlspecialchars($m['username']); ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php $srm_block_html['posts'] = ob_get_clean(); ?>

            <?php ob_start(); ?>
            <!-- ═══════ DEZZMERS (CARD) ═══════ -->
            <?php if(!empty($users)): ?>
            <div class="srm-section" data-section="dezzmers">
                <div class="srm-sec-title">Dezzmers</div>
                <?php foreach($users as $u): ?>
                <?php
                    $defaultPic = 'uploads/mee.jpeg';
                    $pic        = !empty($u['profile_pic']) ? $u['profile_pic'] : $defaultPic;
                    $uid        = (int)$u['id'];
                    $profileHref = 'profile.php?user_id='.$uid;

                    $is_blocked        = !empty($u['is_blocked']);
                    $is_following      = !empty($u['is_following']);
                    $is_private        = !empty($u['is_private']);
                    $is_track_approved = !empty($u['is_track_approved']);
                    $track_req_status  = $u['track_req_status'] ?? null;
                    $bio               = $u['bio'] ?? '';

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

                    $previews = $user_previews_static[$uid] ?? [];
                ?>
                <div class="srm-card srm-card-user">
                    <div class="srm-card-header" onclick="smNavigateInApp('<?php echo $profileHref; ?>')">
                        <img class="srm-card-avatar srm-avatar-red" src="<?php echo htmlspecialchars($pic); ?>"
                             onerror="this.src='<?php echo $defaultPic; ?>';">
                        <div class="srm-card-headinfo">
                            <div class="srm-card-name">
                                <?php echo htmlspecialchars($u['username']); ?>
                                <?php if($is_blocked): ?><span class="srm-blocked-txt">Blocked</span><?php endif; ?>
                            </div>
                            <div class="srm-card-sub">
                                <?php if(!empty($u['nickname'])): ?>
                                    @<?php echo htmlspecialchars($u['nickname']); ?>
                                <?php else: ?>
                                    <span class="srm-card-sub-muted">Dezzmer</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php if($is_blocked): ?>
                        <div class="srm-card-blocked-row"><span class="srm-blocked-label">Blocked</span></div>

                    <?php elseif($show_requested_label): ?>
                        <div class="srm-card-blocked-row">
                            <span class="sm-private-label"><i class="ri-time-line"></i> Requested</span>
                        </div>

                    <?php elseif($show_private_label): ?>
                        <button class="srm-card-track-btn srm-card-private-btn srm-btn-red"
                                data-user="<?php echo $uid; ?>"
                                onclick="event.stopPropagation();srRequestTrack(this, <?php echo $uid; ?>)">
                            <i class="ri-lock-line"></i> Request Track
                        </button>

                    <?php elseif($show_track_btn): ?>
                        <button class="follow-btn srm-card-track-btn srm-btn-red <?php echo $is_following ? 'following tracking' : ''; ?>"
                                data-user="<?php echo $uid; ?>"
                                onclick="event.stopPropagation()">
                            <?php echo $is_following ? 'Untrack' : 'Track'; ?>
                        </button>
                    <?php endif; ?>

                    <?php if(!$is_blocked): ?>
                        <?php echo srm_build_preview_grid($previews, $profileHref, 'user', $u['username'], $pic, $defaultPic); ?>
                    <?php endif; ?>

                    <?php if(!empty($bio)): ?>
                        <div class="srm-card-bio"><?php echo srm_truncate_words($bio, 25, 'srm-see-more-red'); ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php $srm_block_html['dezzmers'] = ob_get_clean(); ?>

            <?php ob_start(); ?>
            <!-- ═══════ PODS (CARD) ═══════ -->
            <?php if($groups && $groups->num_rows > 0): ?>
            <div class="srm-section" data-section="pods">
                <div class="srm-sec-title">Pods</div>
                <?php while($g = $groups->fetch_assoc()):
                    $gid = (int)$g['id'];
                    $is_joined = false;
                    if($current_user){
                        $chk = $conn->query("SELECT 1 FROM group_members WHERE group_id=$gid AND user_id=$current_user");
                        $is_joined = $chk->num_rows > 0;
                    }
                    $group_pic = $g['group_pic'] ?? '';
                    if(empty($group_pic)) $img_src = 'uploads/default_group.png';
                    elseif(strpos($group_pic, 'uploads/') === 0) $img_src = $group_pic;
                    else $img_src = 'uploads/' . $group_pic;

                    $g_is_adult  = !empty($g['is_adult']);
                    $g_is_muted  = !empty($g['is_muted']);
                    $g_notif_off = !empty($g['notif_off']);
                    $owner_name  = $g['owner_username'] ?? 'Unknown';
                    $groupHref   = 'group.php?id='.$gid;
                    $previews    = $group_previews_static[$gid] ?? [];
                    $g_desc      = $g['description'] ?? '';
                ?>
                <div class="srm-card srm-card-group">
                    <div class="srm-card-header srm-card-header-split">
                        <div class="srm-card-header-left" onclick="smNavigateInApp('<?php echo $groupHref; ?>')">
                            <img class="srm-card-avatar srm-card-avatar-grp srm-avatar-blue" src="<?php echo htmlspecialchars($img_src); ?>"
                                 onerror="this.src='uploads/default_group.png';">
                            <div class="srm-card-headinfo">
                                <div class="srm-card-name">
                                    <?php echo htmlspecialchars($g['title']); ?>
                                    <?php if($g_is_adult): ?><span class="sm-nsfw-badge">NSFW</span><?php endif; ?>
                                </div>
                                <div class="srm-card-sub">
                                    <span class="srm-card-owner"><?php echo htmlspecialchars($owner_name); ?></span>
                                    <span class="srm-card-dot">•</span>
                                    <span><?php echo (int)$g['member_count']; ?> members</span>
                                    <?php if($g_is_muted): ?><span class="sm-muted-icon" title="Muted"><i class="ri-volume-mute-line"></i></span><?php endif; ?>
                                    <?php if($g_notif_off): ?><span class="sm-notif-off-icon" title="Notifications off"><i class="ri-notification-off-line"></i></span><?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php if($current_user): ?>
                            <button class="group-btn srm-card-group-btn srm-btn-blue <?php echo $is_joined ? 'joined' : ''; ?>"
                                    data-group="<?php echo $gid; ?>"
                                    onclick="event.stopPropagation()">
                                <?php echo $is_joined ? 'Leave' : 'Join'; ?>
                            </button>
                        <?php endif; ?>
                    </div>
                    <?php echo srm_build_preview_grid($previews, $groupHref, 'group', $g['title'], $img_src, 'uploads/default_group.png'); ?>

                    <?php if(!empty($g_desc)): ?>
                        <div class="srm-card-desc"><?php echo srm_truncate_words($g_desc, 30, 'srm-see-more-blue'); ?></div>
                    <?php endif; ?>
                </div>
                <?php endwhile; ?>
            </div>
            <?php endif; ?>
            <?php $srm_block_html['pods'] = ob_get_clean(); ?>

            <?php
                $srm_block_order = ['posts', 'dezzmers', 'pods'];
                foreach($srm_block_order as $srm_block_key){
                    echo $srm_block_html[$srm_block_key];
                }
            ?>

            <!-- ═══════ COLLECTIONS ═══════ -->
            <?php if(!empty($collections)): ?>
            <div class="srm-section" data-section="collections">
                <div class="srm-sec-title">Collections</div>
                <?php foreach($collections as $c): ?>
                <div class="srm-row" onclick="smNavigateInApp('profile.php?user_id=<?php echo (int)$c['owner_id']; ?>#memes')">
                    <div class="sm-res-icon-box col srm-icon-box">
                        <i class="ri-folder-3-line"></i>
                    </div>
                    <div class="srm-info">
                        <div class="srm-name"><?php echo htmlspecialchars($c['name']); ?></div>
                        <div class="srm-sub">by <?php echo htmlspecialchars($c['owner_username']); ?> · <?php echo (int)$c['item_count']; ?> items</div>
                    </div>
                    <i class="ri-arrow-right-s-line srm-arrow" style="color:#ffc947;"></i>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- ═══════ SOUNDS ═══════ -->
            <?php if(!empty($sounds)): ?>
            <div class="srm-section" data-section="sounds">
                <div class="srm-sec-title">Sounds</div>
                <?php foreach($sounds as $s): ?>
                <div class="srm-row" onclick="smNavigateInApp('audio.php?user_id=<?php echo (int)$s['user_id']; ?>')">
                    <div class="sm-res-icon-box sound srm-icon-box">
                        <i class="ri-music-2-line"></i>
                    </div>
                    <div class="srm-info">
                        <div class="srm-name"><?php echo htmlspecialchars($s['title']); ?></div>
                        <div class="srm-sub">by <?php echo htmlspecialchars($s['username']); ?></div>
                    </div>
                    <i class="ri-arrow-right-s-line srm-arrow" style="color:#64c8ff;"></i>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- ═══════ SOUND FOLDERS ═══════ -->
            <?php if(!empty($sound_folders)): ?>
            <div class="srm-section" data-section="soundfolders">
                <div class="srm-sec-title">Sound Folders</div>
                <?php foreach($sound_folders as $sf): ?>
                <div class="srm-row" onclick="smNavigateInApp('audio.php?user_id=<?php echo (int)$sf['owner_id']; ?>')">
                    <div class="sm-res-icon-box scol srm-icon-box">
                        <i class="ri-folder-music-line"></i>
                    </div>
                    <div class="srm-info">
                        <div class="srm-name"><?php echo htmlspecialchars($sf['name']); ?></div>
                        <div class="srm-sub">by <?php echo htmlspecialchars($sf['owner_username']); ?> · <?php echo (int)$sf['item_count']; ?> tracks</div>
                    </div>
                    <i class="ri-arrow-right-s-line srm-arrow" style="color:#b09aff;"></i>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- ═══════ MEMEPEDIA (CARD) ═══════ -->
            <?php if(!empty($memepedia_entries)): ?>
            <div class="srm-section" data-section="memepedia">
                <div class="srm-sec-title">Memepedia</div>
                <?php foreach($memepedia_entries as $w): ?>
                <?php
                    $articleHref = 'memepedia.php?open='.(int)$w['id'];
                    $statusLabel = !empty($w['status']) ? ucfirst($w['status']) : '';
                    $dateStr     = !empty($w['created_at']) ? date('j M Y', strtotime($w['created_at'])) : '';
                    $authorPic   = !empty($w['author_pic']) ? $w['author_pic'] : 'uploads/mee.jpeg';
                    $authorName  = !empty($w['author_username']) ? '@'.$w['author_username'] : 'Unknown';
                    $summary     = $w['summary'] ?? '';
                    $isVideoWiki = !empty($w['media_url']) && (($w['media_type'] ?? '') === 'video');
                ?>
                <div class="srm-card srm-card-wiki" onclick="smNavigateInApp('<?php echo $articleHref; ?>')">
                    <?php if($isVideoWiki): ?>
                        <video class="srm-wiki-hero" src="<?php echo htmlspecialchars($w['media_url']); ?>"
                               muted playsinline preload="metadata"></video>
                    <?php elseif(!empty($w['media_url'])): ?>
                        <img class="srm-wiki-hero" src="<?php echo htmlspecialchars($w['media_url']); ?>"
                             onerror="smImgFallback(this,'srm-wiki-hero srm-wiki-hero-fallback','ri-book-open-line')">
                    <?php else: ?>
                        <div class="srm-wiki-hero srm-wiki-hero-fallback">
                            <i class="ri-book-open-line"></i>
                        </div>
                    <?php endif; ?>
                    <div class="srm-wiki-body">
                        <div class="srm-wiki-title">
                            <?php echo htmlspecialchars($w['title']); ?>
                            <?php if($statusLabel): ?><span class="srm-wiki-status"><?php echo htmlspecialchars($statusLabel); ?></span><?php endif; ?>
                        </div>
                        <?php if(!empty($summary)): ?>
                            <div class="srm-wiki-summary"><?php echo htmlspecialchars($summary); ?></div>
                        <?php endif; ?>
                        <div class="srm-wiki-meta">
                            <img src="<?php echo htmlspecialchars($authorPic); ?>" onerror="this.src='uploads/mee.jpeg'">
                            <span>by <?php echo htmlspecialchars($authorName); ?><?php echo $dateStr ? ' · '.htmlspecialchars($dateStr) : ''; ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

        <?php endif; ?>

        <div class="srm-empty-tab" id="srmEmptyTab" style="display:none;">
            <i id="srmEmptyTabIcon" class="ri-search-2-line"></i>
            <p id="srmEmptyTabTitle"></p>
            <small id="srmEmptyTabText"></small>
        </div>
        </div>
        <!-- /srm-content -->

        </div>
        <!-- /smResultsInner -->
    </div>
    <!-- ══════════════ /VIEW 2 ══════════════ -->

</div>
<!-- ══════════════ /searchModal ══════════════ -->


<style>
/* ═══════════════════════════════════════════
   OUTER MODAL SHELL — one fixed layer, two views
═══════════════════════════════════════════ */

#searchModal {
    display: none;
    position: fixed;
    inset: 0;
     z-index: 999998; 
    background: transparent;
    flex-direction: column;
    overflow: hidden;
    touch-action: none;
}

#searchModal.sm-open {
    display: flex;
    animation: smSlideIn 0.22s cubic-bezier(0.4, 0, 0.2, 1) forwards;
}
@keyframes smSlideIn {
    from { opacity: 0; transform: translateY(12px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* Each view fills the modal; only one is visible at a time */
.sm-view {
    display: none;
    flex: 1;
    min-height: 0;
    flex-direction: column;
    overflow: hidden;
}
.sm-view.sm-view-active {
    display: flex;
    animation: smViewIn 0.16s ease;
}
@keyframes smViewIn {
    from { opacity: 0; }
    to   { opacity: 1; }
}

#smViewSearch  { background: linear-gradient(-45deg,#070924,#070924); }
#smViewResults { background: linear-gradient(-45deg,#070924,#070924); position: relative; }

/* Loading veil shown over the results view while a search fetch is in flight */
.srm-loading {
    display: none;
    position: absolute;
    inset: 0;
    z-index: 3;
    align-items: center;
    justify-content: center;
    background: rgba(7,9,36,0.86);
    backdrop-filter: blur(1px);
}
.srm-loading.srm-loading-active { display: flex; }
.srm-loading i { font-size: 30px; color: rgba(255,255,255,0.55); }

/* ── TOP BAR (search/typing view) ── */
.sm-topbar {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 18px 20px;
    padding-top: calc(env(safe-area-inset-top) + 18px);
    border-bottom: 1px solid rgba(255,255,255,0.07);
    flex-shrink: 0;
    background: linear-gradient(-45deg,#070924,#070924);
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
    font-size: 34px;
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
    .sm-back-btn { font-size: 30px; }
}
@media (max-width: 490px) {
    #modalSearchWrap { height: 36px; border-radius: 10px; }
    .sm-back-btn { font-size: 28px; }
}
@media (max-width: 380px) {
    #modalSearchWrap { height: 33px; border-radius: 8px; }
    .sm-back-btn { font-size: 26px; }
}
@media (max-width: 330px) {
    #modalSearchWrap { height: 30px; border-radius: 8px; }
    .sm-back-btn { font-size: 24px; }
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

/* ════════════════════ RESPONSIVE (search view) ════════════════════ */
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

/* ═══════════════════════════════════════════
   RESULTS VIEW — same design as before,
   just re-scoped from #searchResultsModal → #smViewResults
═══════════════════════════════════════════ */

.srm-topbar {
    display: flex;
    align-items: center;
    padding: 18px 20px;
    padding-top: calc(env(safe-area-inset-top) + 18px);
    flex-shrink: 0;
    background: transparent;
}
.srm-title-wrap { flex: 1; min-width: 0; }
.srm-title-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    min-width: 0;
}
.srm-search-pill {
    display: flex;
    align-items: center;
    background: rgba(255,255,255,0.09);
    padding: 4px 8px 4px 16px;
    border-radius: 10px;
    flex: 1;
    min-width: 0;
    border: 1.5px solid #D93C3C;
    -webkit-tap-highlight-color: transparent;
    transition: background 0.15s, border-color 0.15s;
}
.srm-search-pill:hover,
.srm-search-pill:active {
    background: rgba(255,255,255,0.14);
    border-color: #E85252;
}

.srm-search-pill-text {
    flex: 1;
    min-width: 0;
    background: transparent;
    border: none;
    color: #ffffff;
    font-size: 14px;
    font-weight: 600;
    font-family: 'Poppins', sans-serif;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    text-align: left;
    padding: 0;
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
}

.srm-pill-divider {
    flex-shrink: 0;
    width: 1px;
    height: 22px;
    margin: 0 10px;
    background: rgba(255,255,255,0.18);
}

.srm-clear-btn {
    flex-shrink: 0;
    background: transparent;
    border: none;
    color: #FF2D2D;
    text-decoration: none;
    font-size: 14px;
    font-weight: 800;
    font-family: 'Poppins', sans-serif;
    padding: 6px 8px;
    transition: opacity 0.2s;
    -webkit-tap-highlight-color: transparent;
}
.srm-clear-btn:hover { opacity: 0.65; }

.srm-tabs-wrap {
    flex-shrink: 0;
    position: sticky;
    top: 0;
    z-index: 5;
    background: transparent;
    border-bottom: 1px solid rgba(255,255,255,0.15);
}
.srm-tabs {
    display: flex;
    gap: 28px;
    overflow-x: auto;
    padding: 14px 18px 0;
    scrollbar-width: none;
    -ms-overflow-style: none;
    -webkit-overflow-scrolling: touch;
}
.srm-tabs::-webkit-scrollbar { display: none; }
.srm-tab {
    flex-shrink: 0;
    background: transparent;
    border: none;
    color: #ffffff;
    font-size: 14px;
    font-weight: 700;
    font-family: 'Poppins', sans-serif;
    cursor: pointer;
    white-space: nowrap;
    padding: 0 0 12px;
    position: relative;
    -webkit-tap-highlight-color: transparent;
    transition: color 0.18s;
}
.srm-tab:hover { color: #ffffff; opacity: 0.85; }
.srm-tab.active { color: #FF4C4C; }
.srm-tab.active::after {
    content: '';
    position: absolute;
    left: 0;
    right: 0;
    bottom: -1px;
    height: 3px;
    border-radius: 2px 2px 0 0;
    background: #FF2D2D;
}

.srm-content {
    flex: 1;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
    padding: 3px 0 36px;
    scrollbar-width: thin;
    scrollbar-color: rgba(80,95,140,0.35) transparent;
}
.srm-content::-webkit-scrollbar { width: 4px; }
.srm-content::-webkit-scrollbar-thumb { background: rgba(80,95,140,0.35); border-radius: 999px; }

.srm-section {
    margin: 2px 0 16px;
    padding-bottom: 6px;
    border-bottom: 1px solid rgba(255,255,255,0.045);
}
.srm-section:last-child { border-bottom: none; }
.srm-section.srm-hidden { display: none !important; }

.srm-sec-title {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 1.2px;
    color: rgba(255,255,255,0.4);
    text-transform: uppercase;
    padding: 11px 18px 9px;
    font-family: 'Poppins', sans-serif;
}
.srm-sec-title i { font-size: 13px; color: #FF4C4C; }
.srm-content.srm-hide-titles .srm-sec-title { display: none; }

.srm-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 18px;
    cursor: pointer;
    transition: background 0.15s;
    -webkit-tap-highlight-color: transparent;
}
.srm-row:hover, .srm-row:active { background: rgba(255,255,255,0.045); }

.srm-avatar {
    width: 44px; height: 44px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid #FF2D2D;
    box-shadow: 0 0 0 1px rgba(255,45,45,0.25), 0 2px 8px rgba(255,45,45,0.2);
    flex-shrink: 0;
}
.srm-avatar-meme {
    border-radius: 7px;
    border-color: rgba(255,255,255,0.35);
    box-shadow: 0 2px 8px rgba(0,0,0,0.3);
}
.srm-avatar-video {
    border-radius: 7px;
    border: 3px solid rgba(255,255,255,0.35);
    box-shadow: 0 2px 8px rgba(0,0,0,0.3);
    background: #111;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    overflow: hidden;
}
.srm-avatar-video video { width: 100%; height: 100%; object-fit: cover; }
.srm-play-overlay { position: absolute; color: rgba(255,255,255,0.85); font-size: 15px; pointer-events: none; text-shadow: 0 1px 4px rgba(0,0,0,0.6); }

.srm-icon-box {
    width: 44px !important; height: 44px !important;
    border-radius: 7px !important;
    flex-shrink: 0;
    box-shadow: 0 2px 8px rgba(0,0,0,0.25);
}
.srm-icon-box i { font-size: 18px !important; }

.srm-info { flex: 1; min-width: 0; }
.srm-name {
    font-size: 13.5px;
    font-weight: 650;
    color: white;
    display: flex;
    align-items: center;
    gap: 4px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    font-family: 'Poppins', sans-serif;
}
.srm-name-caption { font-weight: 500; color: rgba(255,255,255,0.85); }
.srm-sub {
    font-size: 11.5px;
    color: rgba(255,255,255,0.42);
    margin-top: 2px;
    display: flex;
    align-items: center;
    gap: 4px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    font-family: 'Poppins', sans-serif;
}
.srm-blocked-txt { color: #ff3b3b; font-weight: 700; font-size: 11px; margin-left: 3px; }
.srm-arrow { font-size: 16px; flex-shrink: 0; }

.srm-content {
    flex: 1;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
    touch-action: pan-y;   /* ← add this */
    padding: 3px 0 36px;
    scrollbar-width: thin;
    scrollbar-color: rgba(80,95,140,0.35) transparent;
}

#smResultsInner {
    display: contents;
}

.srm-posts-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 8px;
    padding: 4px 16px 8px;
}
@media (min-width: 700px) {
    .srm-posts-grid {
        grid-template-columns: repeat(4, 1fr);
        gap: 12px;
        padding: 6px 20px 10px;
    }
}
.srm-post-card {
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
    min-width: 0;
}
.srm-post-media {
    position: relative;
    width: 100%;
    padding-top: 133.33%;
    border-radius: 0;
    overflow: hidden;
    background: rgba(255,255,255,0.04);
}
.srm-post-media img,
.srm-post-media video {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.srm-post-play {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    color: rgba(255,255,255,0.9);
    font-size: 26px;
    text-shadow: 0 2px 8px rgba(0,0,0,0.6);
    pointer-events: none;
}
.srm-post-date {
    position: absolute;
    top: 8px;
    left: 8px;
    background: rgba(0,0,0,0.55);
    backdrop-filter: blur(2px);
    color: #ffffff;
    font-size: 10.5px;
    font-weight: 700;
    font-family: 'Poppins', sans-serif;
    padding: 3px 8px;
    border-radius: 6px;
    letter-spacing: 0.2px;
    pointer-events: none;
}
.srm-post-text-overlay {
    position: absolute;
    top: 50%;
    left: 0;
    right: 0;
    transform: translateY(-50%);
    padding: 0 16px;
    color: #ffffff;
    font-size: 11.5px;
    font-weight: 700;
    font-family: 'Poppins', sans-serif;
    text-align: center;
    line-height: 1.5;
    text-shadow: 0 1px 6px rgba(0,0,0,0.7);
    display: -webkit-box;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 5;
    overflow: hidden;
    text-overflow: ellipsis;
}
.srm-post-caption {
    margin-top: 6px;
    font-size: 12px;
    font-weight: 500;
    color: rgba(255,255,255,0.88);
    font-family: 'Poppins', sans-serif;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.srm-post-meta {
    display: flex;
    align-items: center;
    gap: 5px;
    margin-top: 5px;
    min-width: 0;
}
.srm-post-avatar {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    object-fit: cover;
    border: 2.5px solid #FF0000;
    flex-shrink: 0;
}
.srm-post-username {
    font-size: 13px;
    font-weight: 700;
    color: rgba(255,255,255,0.75);
    font-family: 'Poppins', sans-serif;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    min-width: 0;
}

.srm-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 76px 20px;
    gap: 13px;
    color: rgba(255,255,255,0.32);
    font-family: 'Poppins', sans-serif;
    text-align: center;
}
.srm-empty i {
    font-size: 46px;
    color: #FF4C4C;
    opacity: 0.7;
}
.srm-empty p { font-size: 14px; font-weight: 600; color: rgba(255,255,255,0.55); }
.srm-empty small { font-size: 12px; }

.srm-empty-tab {
    display: none;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 76px 26px;
    gap: 13px;
    color: rgba(255,255,255,0.32);
    font-family: 'Poppins', sans-serif;
    text-align: center;
}
.srm-empty-tab i {
    font-size: 42px;
    color: #FF4C4C;
    opacity: 0.7;
}
.srm-empty-tab p { font-size: 14px; font-weight: 700; color: rgba(255,255,255,0.65); margin: 0; }
.srm-empty-tab small { font-size: 12.5px; line-height: 1.6; max-width: 280px; color: rgba(255,255,255,0.4); }

.srm-card {
    margin: 9px 16px 13px;
    border-radius: 10px;
    background: rgba(255,255,255,0.035);
    border: 1px solid rgba(255,255,255,0.08);
    overflow: hidden;
    transition: border-color 0.15s, background 0.15s, transform 0.15s;
}
.srm-card:hover { border-color: rgba(255,45,45,0.22); background: rgba(255,255,255,0.05); transform: translateY(-1px); }

.srm-card-header {
    display: flex;
    align-items: center;
    gap: 13px;
    padding: 15px 16px;
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
}
.srm-card-header-split { justify-content: space-between; padding-right: 14px; }
.srm-card-header-left { display: flex; align-items: center; gap: 13px; cursor: pointer; flex: 1; min-width: 0; }

.srm-card-avatar {
    width: 50px; height: 50px;
    border-radius: 50%;
    object-fit: cover;
    flex-shrink: 0;
}
.srm-avatar-red  { border: 2.5px solid #FF2D2D; }
.srm-avatar-blue { border: 2.5px solid #0b3aa8; }
.srm-card-avatar-grp { border-radius: 8px; }

.srm-card-headinfo { flex: 1; min-width: 0; }
.srm-card-name {
    font-size: 15px; font-weight: 700; color: white;
    display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
    font-family: 'Poppins', sans-serif;
}
.srm-card-sub {
    font-size: 12.5px; color: rgba(255,255,255,0.45);
    margin-top: 4px; display: flex; align-items: center; gap: 5px; flex-wrap: wrap;
    font-family: 'Poppins', sans-serif;
}
.srm-card-sub-muted { color: rgba(255,255,255,0.32); }
.srm-card-owner { color: rgba(255,255,255,0.6); font-weight: 600; }
.srm-card-dot { color: rgba(255,255,255,0.25); font-size: 10px; }

.srm-card-track-btn {
    display: block;
    width: calc(100% - 32px);
    margin: 0 16px 14px;
    padding: 11px 0;
    border-radius: 8px;
    color: white;
    font-size: 14px; font-weight: 700;
    font-family: 'Poppins', sans-serif;
    cursor: pointer;
    transition: background 0.2s, border-color 0.2s, color 0.2s;
    -webkit-tap-highlight-color: transparent;
    text-align: center;
    border: none;
}
.srm-btn-red {
    border: 1.5px solid rgba(255,45,45,0.55);
    background: rgba(255,45,45,0.08);
}
.srm-btn-red:hover { background: rgba(255,45,45,0.2); border-color: #FF2D2D; }
.srm-btn-red.tracking,
.srm-btn-red.following.tracking { background: transparent; border-color: rgba(255,45,45,0.3); color: #FF6A6A; }

.srm-btn-blue {
    border: 1.5px solid rgba(11,58,168,0.6);
    background: rgba(11,58,168,0.12);
}
.srm-btn-blue:hover { background: rgba(11,58,168,0.25); border-color: #0b3aa8; }
.srm-btn-blue.joined { background: transparent; border-color: rgba(11,58,168,0.4); color: #6a9bff; }

.srm-card-private-btn { display: flex; align-items: center; justify-content: center; gap: 7px; }
.srm-card-blocked-row { padding: 0 16px 14px; }
.srm-blocked-label {
    display: inline-block; font-size: 12px; font-weight: 700;
    color: rgba(255,45,45,0.65); background: rgba(255,45,45,0.08);
    border: 1px solid rgba(255,45,45,0.25); border-radius: 14px; padding: 5px 13px;
}

.srm-card-group-btn {
    flex-shrink: 0;
    padding: 9px 20px;
    border-radius: 8px;
    color: white;
    font-size: 13.5px; font-weight: 700;
    font-family: 'Poppins', sans-serif;
    cursor: pointer;
    transition: background 0.2s, border-color 0.2s;
    -webkit-tap-highlight-color: transparent;
}

.srm-card-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 6px;
    padding: 0 16px 16px;
}
.srm-card-tile {
    position: relative;
    aspect-ratio: 1;
    border-radius: 7px;
    overflow: hidden;
    background: rgba(255,255,255,0.04);
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
}
.srm-card-tile img, .srm-card-tile video {
    width: 100%; height: 100%; object-fit: cover; display: block;
}
.srm-card-tile-play {
    position: absolute; top: 50%; left: 50%;
    transform: translate(-50%, -50%);
    color: rgba(255,255,255,0.9); font-size: 16px;
    text-shadow: 0 1px 4px rgba(0,0,0,0.6); pointer-events: none;
}
.srm-card-tile-empty {
    display: flex; align-items: center; justify-content: center;
    color: rgba(255,255,255,0.12); font-size: 17px;
}

.srm-checkout-tile {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 2px;
    background: rgba(255,255,255,0.06);
    border: none;
    padding: 4px 3px;
}
.srm-checkout-tile img.srm-checkout-avatar {
    width: 104px;
    height: 104px;
    object-fit: cover;
    flex-shrink: 0;
    display: block;
    margin-bottom: 2px;
}
.srm-checkout-avatar-user { border-radius: 50%; border: 1.6px solid #FF2D2D; }
.srm-checkout-avatar-grp  { border-radius: 14%;  border: 1.6px solid #0b3aa8; }

.srm-checkout-label {
    font-size: 7px;
    font-weight: 700;
    color: rgba(255,255,255,0.6);
    font-family: 'Poppins', sans-serif;
    text-align: center;
    line-height: 1.1;
}
.srm-checkout-name {
    font-size: 12px;
    font-weight: 800;
    color: white;
    font-family: 'Poppins', sans-serif;
    text-align: center;
    line-height: 1.2;
    word-break: break-word;
    max-width: 100%;
    margin-bottom: 3px;
}
.srm-checkout-btn {
    display: block;
    width: 88%;
    text-align: center;
    padding: 3px 2px;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 700;
    font-family: 'Poppins', sans-serif;
    color: #fff;
}
.srm-checkout-red .srm-checkout-btn {
    background: linear-gradient(135deg, #FF2D2D, #ff4d4d);
}
.srm-checkout-blue .srm-checkout-btn {
    background: linear-gradient(135deg, #0b3aa8, #1a56db);
}
.srm-checkout-red:hover  { background: rgba(255,255,255,0.1); }
.srm-checkout-blue:hover { background: rgba(255,255,255,0.1); }

.srm-card-bio,
.srm-card-desc {
    padding: 0 16px 16px;
    font-size: 13px; color: rgba(255,255,255,0.55);
    line-height: 1.6; font-family: 'Poppins', sans-serif;
    word-break: break-word;
}
.srm-see-more {
    font-weight: 700;
    cursor: default;
    margin-left: 2px;
    white-space: nowrap;
}
.srm-see-more-red  { color: #FF4C4C; }
.srm-see-more-blue { color: #6a9bff; }

.srm-card-wiki { cursor: pointer; }
.srm-wiki-hero {
    width: 100%; height: 150px; object-fit: cover; display: block;
    background: rgba(255,255,255,0.04);
}
.srm-wiki-hero-fallback {
    display: flex; align-items: center; justify-content: center;
    color: rgba(80,200,140,0.35); font-size: 34px;
}
.srm-wiki-body { padding: 13px 16px 16px; }
.srm-wiki-title {
    font-size: 15px; font-weight: 700; color: white;
    display: flex; align-items: center; gap: 7px; flex-wrap: wrap;
    font-family: 'Poppins', sans-serif;
}
.srm-wiki-status {
    font-size: 10.5px; font-weight: 700; color: #50c88c;
    background: rgba(80,200,140,0.1); border: 1px solid rgba(80,200,140,0.3);
    padding: 2px 9px; border-radius: 14px;
}
.srm-wiki-summary {
    font-size: 13px; color: rgba(255,255,255,0.5);
    margin-top: 7px; line-height: 1.6;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
    font-family: 'Poppins', sans-serif;
}
.srm-wiki-meta {
    display: flex; align-items: center; gap: 8px;
    margin-top: 11px; font-size: 12px; color: rgba(255,255,255,0.4);
    font-family: 'Poppins', sans-serif;
}
.srm-wiki-meta img {
    width: 22px; height: 22px; border-radius: 50%; object-fit: cover;
    border: 1px solid rgba(80,200,140,0.4);
}

@media (max-width: 790px) {
    .srm-topbar { padding: 14px 15px; padding-top: calc(env(safe-area-inset-top) + 14px); }
    .srm-search-pill { padding: 3.5px 7px 3.5px 14px; border-radius: 9px; border-width: 1.5px; }
    .srm-search-pill-text { font-size: 13px; }
    .srm-pill-divider { height: 20px; margin: 0 9px; }
    .srm-clear-btn { font-size: 13px; padding: 5px 7px; }
    .srm-tabs { padding: 12px 15px 0; gap: 23px; }
    .srm-tab { font-size: 13px; padding: 0 0 10px; }
    .srm-sec-title { padding: 9px 15px 7px; font-size: 10px; }
    .srm-sec-title i { font-size: 12px; }
    .srm-row { padding: 9px 15px; gap: 11px; }
    .srm-avatar, .srm-icon-box { width: 39px !important; height: 39px !important; }
    .srm-posts-grid { gap: 11px; padding: 4px 13px 7px; }
    .srm-post-media { border-radius: 0; }
    .srm-post-date { font-size: 10px; padding: 3px 7px; }
    .srm-post-caption { font-size: 12px; margin-top: 7px; }
    .srm-post-avatar { width: 22px; height: 22px; border-width: 2.3px; }
    .srm-post-username { font-size: 12.3px; }
    .srm-avatar { border-width: 2.7px; }
    .srm-avatar-video { border-width: 2.7px; }
    .srm-name { font-size: 13px; }
    .srm-sub { font-size: 11px; }
    .srm-arrow { font-size: 15px; }
    .srm-card { margin: 8px 13px 11px; border-radius: 9px; }
    .srm-card-header { padding: 13px 14px; gap: 11px; }
    .srm-card-avatar { width: 45px; height: 45px; }
    .srm-card-name { font-size: 14px; }
    .srm-card-sub { font-size: 11.5px; }
    .srm-card-track-btn { width: calc(100% - 28px); margin: 0 14px 12px; padding: 10px 0; font-size: 13px; border-radius: 7px; }
    .srm-card-group-btn { padding: 8px 17px; font-size: 12.5px; border-radius: 7px; }
    .srm-card-grid { padding: 0 14px 14px; gap: 5px; }
    .srm-card-tile { border-radius: 6px; }
    .srm-checkout-tile img.srm-checkout-avatar { width: 44px; height: 44px; }
    .srm-checkout-label { font-size: 6.5px; }
    .srm-checkout-name { font-size: 11px; }
    .srm-checkout-btn { font-size: 9.5px; }
    .srm-card-bio, .srm-card-desc { padding: 0 14px 14px; font-size: 12px; }
    .srm-wiki-hero { height: 132px; }
    .srm-wiki-body { padding: 12px 14px 14px; }
    .srm-wiki-title { font-size: 14px; }
    .srm-wiki-summary { font-size: 12px; }
    .srm-wiki-meta { font-size: 11px; }
    .srm-wiki-meta img { width: 20px; height: 20px; }
}

@media (max-width: 490px) {
    .srm-topbar { padding: 12px 13px; padding-top: calc(env(safe-area-inset-top) + 12px); }
    .srm-search-pill { padding: 3px 6px 3px 12px; border-radius: 8px; border-width: 1.3px; }
    .srm-search-pill-text { font-size: 12.5px; }
    .srm-pill-divider { height: 18px; margin: 0 8px; }
    .srm-clear-btn { font-size: 12.5px; padding: 4.5px 6px; }
    .srm-tabs { padding: 10px 13px 0; gap: 19px; }
    .srm-tab { font-size: 12px; padding: 0 0 9px; }
    .srm-sec-title { padding: 8px 13px 6px; font-size: 9.5px; letter-spacing: 1px; }
    .srm-sec-title i { font-size: 11px; }
    .srm-row { padding: 8px 13px; gap: 10px; }
    .srm-avatar, .srm-icon-box { width: 36px !important; height: 36px !important; }
    .srm-posts-grid { gap: 9px; padding: 4px 11px 6px; }
    .srm-post-media { border-radius: 0; }
    .srm-post-date { font-size: 9.5px; padding: 2.5px 6.5px; }
    .srm-post-caption { font-size: 11.3px; margin-top: 6px; }
    .srm-post-meta { margin-top: 5px; gap: 5px; }
    .srm-post-avatar { width: 20px; height: 20px; border-width: 2px; }
    .srm-post-username { font-size: 11.5px; }
    .srm-avatar { border-width: 2.5px; }
    .srm-avatar-video { border-width: 2.5px; }
    .srm-icon-box i { font-size: 16px !important; }
    .srm-name { font-size: 12.5px; gap: 4px; }
    .srm-sub { font-size: 10.5px; }
    .srm-arrow { font-size: 14px; }
    .srm-empty { padding: 54px 17px; gap: 11px; }
    .srm-empty i { font-size: 40px; }
    .srm-empty p { font-size: 13px; }
    .srm-empty small { font-size: 11px; }
    .srm-card { margin: 7px 11px 10px; border-radius: 8px; }
    .srm-card-header { padding: 11px 12px; gap: 10px; }
    .srm-card-avatar { width: 40px; height: 40px; border-width: 2.2px; }
    .srm-card-name { font-size: 13px; }
    .srm-card-sub { font-size: 10.5px; }
    .srm-card-track-btn { width: calc(100% - 24px); margin: 0 12px 10px; padding: 9px 0; font-size: 12px; border-radius: 6px; }
    .srm-card-group-btn { padding: 7px 15px; font-size: 11.5px; border-radius: 6px; }
    .srm-card-grid { padding: 0 12px 12px; gap: 4px; }
    .srm-card-tile { border-radius: 5px; }
    .srm-checkout-tile img.srm-checkout-avatar { width: 36px; height: 36px; margin-bottom: 1px; }
    .srm-checkout-label { display: none; }
    .srm-checkout-name { font-size: 10.5px; }
    .srm-checkout-btn { font-size: 9px; padding: 2px; }
    .srm-card-bio, .srm-card-desc { padding: 0 12px 12px; font-size: 11.3px; }
    .srm-wiki-hero { height: 112px; }
    .srm-wiki-body { padding: 11px 12px 12px; }
    .srm-wiki-title { font-size: 13px; }
    .srm-wiki-status { font-size: 9px; padding: 2px 8px; }
    .srm-wiki-summary { font-size: 11.3px; margin-top: 6px; }
    .srm-wiki-meta { font-size: 10.5px; margin-top: 9px; gap: 7px; }
    .srm-wiki-meta img { width: 18px; height: 18px; }
}

@media (max-width: 390px) {
    .srm-topbar { padding: 10px 11px; padding-top: calc(env(safe-area-inset-top) + 10px); }
    .srm-search-pill { padding: 2.5px 6px 2.5px 11px; border-radius: 8px; border-width: 1.2px; }
    .srm-search-pill-text { font-size: 12px; }
    .srm-pill-divider { height: 16px; margin: 0 7px; }
    .srm-clear-btn { font-size: 12px; padding: 4px 6px; }
    .srm-tabs { padding: 8px 11px 0; gap: 16px; }
    .srm-tab { font-size: 11px; padding: 0 0 8px; }
    .srm-sec-title { padding: 7px 11px 5px; font-size: 9px; }
    .srm-sec-title i { font-size: 10.5px; }
    .srm-row { padding: 7px 11px; gap: 9px; }
    .srm-avatar, .srm-icon-box { width: 32px !important; height: 32px !important; }
    .srm-posts-grid { gap: 8px; padding: 3px 10px 6px; }
    .srm-post-media { border-radius: 0; }
    .srm-post-date { font-size: 9px; padding: 2.5px 6px; }
    .srm-post-play { font-size: 24px; }
    .srm-post-caption { font-size: 10.7px; margin-top: 6px; }
    .srm-post-meta { margin-top: 5px; gap: 5px; }
    .srm-post-avatar { width: 18px; height: 18px; border-width: 2px; }
    .srm-post-username { font-size: 10.8px; }
    .srm-avatar { border-width: 2.2px; }
    .srm-avatar-video { border-width: 2.2px; }
    .srm-icon-box i { font-size: 14.5px !important; }
    .srm-name { font-size: 11.5px; }
    .srm-sub { font-size: 10px; }
    .srm-arrow { font-size: 13px; }
    .srm-blocked-txt { font-size: 10px; }
    .sm-nsfw-badge { font-size: 8px !important; padding: 1px 4.5px !important; }
    .sm-muted-icon, .sm-notif-off-icon { font-size: 10px !important; }
    .srm-card { margin: 6px 10px 9px; border-radius: 8px; }
    .srm-card-header { padding: 10px 11px; gap: 9px; }
    .srm-card-avatar { width: 36px; height: 36px; border-width: 2px; }
    .srm-card-name { font-size: 12px; }
    .srm-card-sub { font-size: 10px; }
    .srm-card-track-btn { width: calc(100% - 22px); margin: 0 11px 9px; padding: 8px 0; font-size: 11.5px; border-radius: 6px; }
    .srm-card-group-btn { padding: 6.5px 13px; font-size: 11px; border-radius: 6px; }
    .srm-card-grid { padding: 0 11px 11px; gap: 4px; }
    .srm-card-tile { border-radius: 5px; }
    .srm-checkout-tile img.srm-checkout-avatar { width: 30px; height: 30px; margin-bottom: 1px; }
    .srm-checkout-label { display: none; }
    .srm-checkout-name { font-size: 10px; }
    .srm-checkout-btn { font-size: 8.5px; padding: 2px; }
    .srm-card-bio, .srm-card-desc { padding: 0 11px 11px; font-size: 10.7px; }
    .srm-wiki-hero { height: 98px; }
    .srm-wiki-body { padding: 10px 11px 11px; }
    .srm-wiki-title { font-size: 12px; }
    .srm-wiki-status { font-size: 8.5px; padding: 1.5px 7px; }
    .srm-wiki-summary { font-size: 10.7px; }
    .srm-wiki-meta { font-size: 10px; gap: 6px; margin-top: 8px; }
    .srm-wiki-meta img { width: 16px; height: 16px; }
}

@media (max-width: 330px) {
    .srm-topbar { padding: 9px 9px; padding-top: calc(env(safe-area-inset-top) + 9px); }
    .srm-search-pill { padding: 2px 5px 2px 10px; border-radius: 7px; border-width: 1.2px; }
    .srm-search-pill-text { font-size: 11px; }
    .srm-pill-divider { height: 14px; margin: 0 6px; }
    .srm-clear-btn { font-size: 11px; padding: 3.5px 5px; }
    .srm-tabs { padding: 7px 8px 0; gap: 14px; }
    .srm-tab { font-size: 10px; padding: 0 0 7px; }
    .srm-sec-title { padding: 6px 9px 4px; font-size: 8.5px; }
    .srm-sec-title i { font-size: 9.5px; }
    .srm-row { padding: 6px 9px; gap: 8px; }
    .srm-avatar, .srm-icon-box { width: 29px !important; height: 29px !important; }
    .srm-posts-grid { gap: 7px; padding: 3px 9px 5px; }
    .srm-post-media { border-radius: 0; }
    .srm-post-date { font-size: 8.5px; padding: 2px 5.5px; }
    .srm-post-play { font-size: 22px; }
    .srm-post-caption { font-size: 10px; margin-top: 5px; }
    .srm-post-meta { margin-top: 4px; gap: 4px; }
    .srm-post-avatar { width: 16px; height: 16px; border-width: 1.8px; }
    .srm-post-username { font-size: 10px; }
    .srm-avatar { border-width: 2px; }
    .srm-avatar-video { border-width: 2px; }
    .srm-icon-box i { font-size: 13px !important; }
    .srm-name { font-size: 10.5px; }
    .srm-sub { font-size: 9px; }
    .srm-arrow { font-size: 12px; }
    .srm-empty i { font-size: 33px; }
    .srm-empty p { font-size: 11.5px; }
    .srm-empty small { font-size: 10px; }
    .srm-card { margin: 6px 9px 8px; border-radius: 7px; }
    .srm-card-header { padding: 9px 9px; gap: 8px; }
    .srm-card-avatar { width: 32px; height: 32px; border-width: 1.8px; }
    .srm-card-name { font-size: 11px; }
    .srm-card-sub { font-size: 9.3px; }
    .srm-card-track-btn { width: calc(100% - 18px); margin: 0 9px 8px; padding: 7px 0; font-size: 10.7px; border-radius: 6px; }
    .srm-card-group-btn { padding: 6px 11px; font-size: 10.3px; border-radius: 6px; }
    .srm-card-grid { padding: 0 9px 9px; gap: 3px; }
    .srm-card-tile { border-radius: 5px; }
    .srm-checkout-tile img.srm-checkout-avatar { width: 24px; height: 24px; margin-bottom: 1px; }
    .srm-checkout-label { display: none; }
    .srm-checkout-name { font-size: 9px; }
    .srm-checkout-btn { font-size: 8px; padding: 1.5px; }
    .srm-card-bio, .srm-card-desc { padding: 0 9px 9px; font-size: 10px; }
    .srm-wiki-hero { height: 84px; }
    .srm-wiki-body { padding: 9px 9px 9px; }
    .srm-wiki-title { font-size: 11.3px; }
    .srm-wiki-summary { font-size: 10px; }
    .srm-wiki-meta { font-size: 9.3px; gap: 5px; margin-top: 7px; }
    .srm-wiki-meta img { width: 15px; height: 15px; }
}

@media (max-width: 790px) {
    .srm-content { scrollbar-width: none; -ms-overflow-style: none; }
    .srm-content::-webkit-scrollbar { display: none; }
}

/* ═══════════════════════════════════════════
   CARD STYLES — Users / Pods / Memepedia
   (live-search dropdown result cards, kept from original)
═══════════════════════════════════════════ */
.sm-card {
    margin: 8px 14px 12px;
    border-radius: 16px;
    background: rgba(255,255,255,0.035);
    border: 1px solid rgba(255,255,255,0.08);
    overflow: hidden;
    transition: border-color 0.15s, background 0.15s;
}
.sm-card:hover { border-color: rgba(255,45,45,0.22); background: rgba(255,255,255,0.05); }
.sm-card-header {
    display: flex; align-items: center; gap: 12px;
    padding: 13px 14px; cursor: pointer;
    -webkit-tap-highlight-color: transparent;
}
.sm-card-header-split { justify-content: space-between; padding-right: 12px; }
.sm-card-header-left { display: flex; align-items: center; gap: 12px; cursor: pointer; flex: 1; min-width: 0; }
.sm-card-avatar { width: 46px; height: 46px; border-radius: 50%; object-fit: cover; border: 2px solid #FF2D2D; flex-shrink: 0; }
.sm-card-avatar-grp { border-radius: 12px; border-color: #0673f0; }
.sm-card-headinfo { flex: 1; min-width: 0; }
.sm-card-name { font-size: 14px; font-weight: 700; color: white; display: flex; align-items: center; gap: 5px; flex-wrap: wrap; font-family: 'Poppins', sans-serif; }
.sm-card-sub { font-size: 11.5px; color: rgba(255,255,255,0.45); margin-top: 3px; display: flex; align-items: center; gap: 4px; flex-wrap: wrap; font-family: 'Poppins', sans-serif; }
.sm-card-sub-muted { color: rgba(255,255,255,0.32); }
.sm-card-owner { color: rgba(255,255,255,0.6); font-weight: 600; }
.sm-card-dot { color: rgba(255,255,255,0.25); font-size: 10px; }
.sm-card-track-btn {
    display: block; width: calc(100% - 28px); margin: 0 14px 12px; padding: 10px 0;
    border-radius: 12px; border: 1.5px solid rgba(255,45,45,0.55); background: rgba(255,45,45,0.08);
    color: white; font-size: 13px; font-weight: 700; font-family: 'Poppins', sans-serif;
    cursor: pointer; transition: background 0.2s, border-color 0.2s, color 0.2s;
    -webkit-tap-highlight-color: transparent;
}
.sm-card-track-btn:hover { background: rgba(255,45,45,0.2); border-color: #FF2D2D; }
.sm-card-track-btn.tracking { background: transparent; border-color: rgba(255,45,45,0.3); color: #FF6A6A; }
.sm-card-track-btn.tracking:hover { background: rgba(255,45,45,0.1); }
.sm-card-private-btn { display: flex; align-items: center; justify-content: center; gap: 6px; }
.sm-card-blocked-row { padding: 0 14px 12px; }
.sm-blocked-label {
    display: inline-block; font-size: 11px; font-weight: 700;
    color: rgba(255,45,45,0.65); background: rgba(255,45,45,0.08);
    border: 1px solid rgba(255,45,45,0.25); border-radius: 20px; padding: 4px 12px;
}
.sm-card-group-btn {
    flex-shrink: 0; padding: 8px 18px; border-radius: 12px;
    border: 1.5px solid rgba(6,115,240,0.55); background: rgba(6,115,240,0.1);
    color: white; font-size: 12.5px; font-weight: 700; font-family: 'Poppins', sans-serif;
    cursor: pointer; transition: background 0.2s, border-color 0.2s;
    -webkit-tap-highlight-color: transparent;
}
.sm-card-group-btn:hover { background: rgba(6,115,240,0.22); border-color: #0673f0; }
.sm-card-group-btn.joined { background: transparent; border-color: rgba(6,115,240,0.3); color: #6ba3ff; }
.sm-card-group-btn.joined:hover { background: rgba(6,115,240,0.1); }
.sm-card-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 5px; padding: 0 14px 14px; }
.sm-card-tile { position: relative; aspect-ratio: 1; border-radius: 10px; overflow: hidden; background: rgba(255,255,255,0.04); cursor: pointer; -webkit-tap-highlight-color: transparent; }
.sm-card-tile img, .sm-card-tile video { width: 100%; height: 100%; object-fit: cover; display: block; }
.sm-card-tile-play { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: rgba(255,255,255,0.9); font-size: 15px; text-shadow: 0 1px 4px rgba(0,0,0,0.6); pointer-events: none; }
.sm-card-tile-empty { display: flex; align-items: center; justify-content: center; color: rgba(255,255,255,0.12); font-size: 16px; }
.sm-card-tile-more {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: 2px; background: rgba(255,45,45,0.1); border: 1px dashed rgba(255,45,45,0.3);
    color: #FF6A6A; font-size: 9px; font-weight: 700; text-align: center;
    font-family: 'Poppins', sans-serif;
}
.sm-card-tile-more i { font-size: 15px; }
.sm-card-tile-more span { line-height: 1.1; }
.sm-card-bio { padding: 0 14px 14px; font-size: 12px; color: rgba(255,255,255,0.55); line-height: 1.55; font-family: 'Poppins', sans-serif; word-break: break-word; }
.sm-card-wiki { cursor: pointer; }
.sm-wiki-hero { width: 100%; height: 130px; object-fit: cover; display: block; background: rgba(255,255,255,0.04); }
.sm-wiki-hero-fallback { display: flex; align-items: center; justify-content: center; color: rgba(80,200,140,0.35); font-size: 30px; }
.sm-wiki-body { padding: 12px 14px 14px; }
.sm-wiki-title { font-size: 14px; font-weight: 700; color: white; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; font-family: 'Poppins', sans-serif; }
.sm-wiki-status { font-size: 9.5px; font-weight: 700; color: #50c88c; background: rgba(80,200,140,0.1); border: 1px solid rgba(80,200,140,0.3); padding: 2px 8px; border-radius: 20px; }
.sm-wiki-summary { font-size: 12px; color: rgba(255,255,255,0.5); margin-top: 6px; line-height: 1.55; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; font-family: 'Poppins', sans-serif; }
.sm-wiki-meta { display: flex; align-items: center; gap: 7px; margin-top: 10px; font-size: 11px; color: rgba(255,255,255,0.4); font-family: 'Poppins', sans-serif; }
.sm-wiki-meta img { width: 20px; height: 20px; border-radius: 50%; object-fit: cover; border: 1px solid rgba(80,200,140,0.4); }
</style>


<script>
/* ══════════════════════════════════════════════════════════════
   DEZZME SEARCH — UNIFIED MODAL ENGINE
   One #searchModal. Two views inside it: #smViewSearch (typing)
   and #smViewResults (full results). Switching views is just a
   class toggle — the modal itself never closes and the page never
   navigates. Full searches fetch this same page in the background
   and splice the freshly-rendered #smResultsInner markup in.
══════════════════════════════════════════════════════════════ */
var SM_ENDPOINT   = 'search_recents.php';
var smTimer       = null;
var smLastQ       = '';
var smOpen        = false;
var SM_PAGE_SIZE  = 5;
var _smAllRecents = [];
var _smExpanded   = false;
var _smCurrentQuery = <?php echo json_encode($search_query ?? ''); ?>;
var _smFetchToken  = 0; // guards against out-of-order fetch responses

/* ── helpers ── */
function smEsc(s) {
    var d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
}


function smHL(str, q) {
    str = String(str == null ? '' : str);
    if (!q) return smEsc(str);
    var idx = str.toLowerCase().indexOf(String(q).toLowerCase());
    if (idx === -1) return smEsc(str);
    var before = str.slice(0, idx);
    var match  = str.slice(idx, idx + q.length);
    var after  = str.slice(idx + q.length);
    return smEsc(before) + '<span class="sm-hl">' + smEsc(match) + '</span>' + smEsc(after);
}

function smHLpid(str, q) {
    str = String(str == null ? '' : str);
    if (!q) return smEsc(str);
    var clean = String(q).replace(/^dz-?/i, '');
    if (!clean) return smEsc(str);
    var idx = str.toLowerCase().indexOf(clean.toLowerCase());
    if (idx === -1) return smEsc(str);
    var before = str.slice(0, idx);
    var match  = str.slice(idx, idx + clean.length);
    var after  = str.slice(idx + clean.length);
    return smEsc(before) + '<span class="sm-hl-pid">' + smEsc(match) + '</span>' + smEsc(after);
}

function smNSFWbadge(isAdult) {
    if (!isAdult) return '';
    return '<span class="sm-nsfw-badge">NSFW</span>';
}

function smGroupStatusIcons(isMuted, isNotifOff) {
    var html = '';
    if (isMuted)    html += '<span class="sm-muted-icon" title="Muted"><i class="ri-volume-mute-line"></i></span>';
    if (isNotifOff) html += '<span class="sm-notif-off-icon" title="Notifications off"><i class="ri-notification-off-line"></i></span>';
    return html;
}

function smImgFallback(el, boxClass, iconClass) {
    var box = document.createElement('div');
    box.className = boxClass;
    box.innerHTML = '<i class="' + iconClass + '"></i>';
    if (el && el.parentNode) el.parentNode.replaceChild(box, el);
}

function smVidFallback(el, boxClass, iconClass) {
    var box = document.createElement('div');
    box.className = boxClass;
    box.innerHTML = '<i class="' + iconClass + '"></i>';
    var wrap = el.closest('.sm-res-video-wrap, .sm-r-video-wrap') || el.parentNode;
    if (wrap && wrap.parentNode) wrap.parentNode.replaceChild(box, wrap);
}

/* Central in-app navigation used by result rows/cards that used to
   do location.href — kept as real navigation since these go to
   profile/group/audio/memepedia pages, not back into search. */
function smNavigateInApp(href) {
    location.href = href;
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
    var discoverEl = document.getElementById('discoverModal');
    if (discoverEl && discoverEl.classList.contains('ccm-open')) return; // let CCM own its touches
    var content = document.querySelector('.sm-view-active #searchModalContent, .sm-view-active .srm-content');
    if (content && content.contains(e.target)) return;
    if (smOpen) { e.stopImmediatePropagation(); }
}

/* ══════════ VIEW SWITCHING ══════════ */
function smSwitchView(view) {
    var vs = document.getElementById('smViewSearch');
    var vr = document.getElementById('smViewResults');
    if (!vs || !vr) return;
    if (view === 'results') {
        vs.classList.remove('sm-view-active');
        vr.classList.add('sm-view-active');
    } else {
        vr.classList.remove('sm-view-active');
        vs.classList.add('sm-view-active');
    }
}

/* ── Open / Close (controls the ONE outer modal) ── */
function openSearchModal() {
    if (smOpen) return;
    smOpen = true;
    var m = document.getElementById('searchModal');
    m.style.display = 'flex';
    m.classList.add('sm-open');
    bringModalToFront(m);   // ← add this
    document.body.style.overflow = 'hidden';
    _smExpanded = false;
    _smLockPTR();
    smRenderRecents();
    setTimeout(function() {
        var vs = document.getElementById('smViewSearch');
        if (vs && vs.classList.contains('sm-view-active')) {
            var inp = document.getElementById('modalSearchInput');
            if (inp) inp.focus();
        }
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

/* Back-compat aliases — old markup on this page (post-card onclicks
   etc.) still calls these two names before navigating elsewhere. */
function openSearchResultsModal() { openSearchModal(); smSwitchView('results'); }
function closeSearchResultsModal() { closeSearchModal(); }

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && smOpen) closeSearchModal();
});

/* block overscroll PTR inside modal (both views) */
(function() {
    document.addEventListener('touchstart', function(e) {
        var scroller = e.target.closest('#searchModalContent, .srm-content');
        if (scroller) scroller.dataset.smStartY = e.touches[0].clientY;
    }, { passive: true });
    document.addEventListener('touchmove', function(e) {
        if (!smOpen) return;
        var scroller = e.target.closest('#searchModalContent, .srm-content');
        if (!scroller) return;
        var startY = parseFloat(scroller.dataset.smStartY || '0');
        var dy     = e.touches[0].clientY - startY;
        var atTop  = scroller.scrollTop <= 0;
        if (atTop && dy > 0) { e.stopPropagation(); }
    }, { passive: true, capture: false });
})();

/* ── Recent item cache ── */
var _smRecentCache = {};

/* ── Expand / Collapse recents ── */
function smExpandRecents()  { _smExpanded = true;  smDrawRecents(_smAllRecents); }
function smCollapseRecents(){ _smExpanded = false; smDrawRecents(_smAllRecents); }

/* ══════════════════════════════════════════════
   DRAW RECENTS
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
            if (r.media_url && r.media_type === 'video') {
                avatarHtml = '<div class="sm-r-video-wrap" style="border-color:#50c88c;">'
                    + '<video src="' + smEsc(r.media_url) + '" muted playsinline preload="metadata" onerror="smVidFallback(this,\'sm-r-icon-box wiki\',\'ri-book-open-line\')"></video>'
                    + '<i class="ri-play-fill sm-play-overlay"></i></div>';
            } else if (r.media_url) {
                avatarHtml = '<img class="sm-r-avatar meme-av" style="border-color:#50c88c;" src="'
                    + smEsc(r.media_url)
                    + '" onerror="smImgFallback(this,\'sm-r-icon-box wiki\',\'ri-book-open-line\')">';
            } else {
                avatarHtml = '<div class="sm-r-icon-box wiki"><i class="ri-book-open-line"></i></div>';
            }

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
    smRunFullSearch(tag);
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

/* ── Central navigation handler (autocomplete / recents rows) ── */
function smNavigate(type, id, item) {
    item = item || {};
    if      (type === 'profile_id')       location.href = 'profile.php?user_id=' + id;
    else if (type === 'user')             location.href = 'profile.php?user_id=' + id;
    else if (type === 'group')            location.href = 'group.php?id=' + id;
    else if (type === 'meme')             smRunFullSearch(item.caption || '');
    else if (type === 'collection')       location.href = 'profile.php?user_id=' + (item.owner_id || id) + '#memes';
    else if (type === 'sound')            location.href = 'audio.php?user_id=' + (item.user_id || id);
    else if (type === 'sound_collection') location.href = 'audio.php?user_id=' + (item.owner_id || id);
    else if (type === 'memepedia')        location.href = 'memepedia.php?open=' + id;
    else                                  smRunFullSearch(item.query || id);
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

/* ══ TRACK TOGGLE (live-search dropdown) ══ */
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
    if (q) smRunFullSearch(q);
}

/* ── Input handler (live autocomplete, unchanged) ── */
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
        if (q) smRunFullSearch(q);
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
   RENDER LIVE (AUTOCOMPLETE) RESULTS — unchanged
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

    if (groups.length) {
        html += '<div class="sm-sec">Pods</div>';
        html += groups.map(function(g) {
            var pic = g.group_pic ? (g.group_pic.indexOf('uploads/') === 0 ? g.group_pic : 'uploads/' + g.group_pic) : 'uploads/default_group.png';
            var enc = encodeURIComponent(JSON.stringify(g));
            var isJoined = !!parseInt(g.is_joined);

            var nsfwHtml   = smNSFWbadge(g.is_adult);
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
    }

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

    if (wikis.length) {
        html += '<div class="sm-sec">Memepedia</div>';
        html += wikis.map(function(w) {
            var enc         = encodeURIComponent(JSON.stringify(w));
            var statusLabel = w.status ? w.status.charAt(0).toUpperCase() + w.status.slice(1) : '';
            var thumbHtml;
            if (w.media_url && w.media_type === 'video') {
                thumbHtml = '<div class="sm-res-video-wrap" style="border-color:#50c88c;">'
                    + '<video src="' + smEsc(w.media_url) + '" muted playsinline preload="metadata" onerror="smVidFallback(this,\'sm-res-icon-box wiki\',\'ri-book-open-line\')"></video>'
                    + '<i class="ri-play-fill sm-play-overlay"></i></div>';
            } else if (w.media_url) {
                thumbHtml = '<img class="sm-res-avatar meme" style="border-color:#50c88c;" src="'
                    + smEsc(w.media_url)
                    + '" onerror="smImgFallback(this,\'sm-res-icon-box wiki\',\'ri-book-open-line\')">';
            } else {
                thumbHtml = '<div class="sm-res-icon-box wiki"><i class="ri-book-open-line"></i></div>';
            }
            return '<div class="sm-res-row" onclick="smSelectResult(\'memepedia\',' + w.id + ',\'' + enc + '\')">'
                + thumbHtml
                + '<div class="sm-res-info">'
                    + '<div class="sm-res-name">' + smHL(w.title, q) + '</div>'
                    + '<div class="sm-res-sub">Memepedia' + (statusLabel ? ' · ' + smEsc(statusLabel) : '') + (w.category ? ' · ' + smEsc(w.category) : '') + '</div>'
                + '</div>'
                + '<i class="ri-arrow-right-s-line sm-res-icon green"></i>'
            + '</div>';
        }).join('');
    }

    html += '<div class="sm-res-row sm-full-search-row" onclick="smFullSearch(\'' + encodeURIComponent(q) + '\')">'
        + '<div class="sm-full-search-icon"><i class="ri-search-line"></i></div>'
        + '<div class="sm-res-info">'
            + '<div class="sm-res-name sm-full-search-label">Search for "' + smEsc(q) + '"</div>'
            + '<div class="sm-res-sub">See all matching posts, users &amp; pods</div>'
        + '</div>'
        + '<i class="ri-arrow-right-line sm-res-icon red"></i>'
    + '</div>';

    box.innerHTML = html;
}

function smFullSearch(encQ) {
    smRunFullSearch(decodeURIComponent(encQ));
}

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


/* ══════════════════════════════════════════════════════════════
   FULL SEARCH — no navigation, no second modal.
   Fetches THIS SAME PAGE with ?q=..., pulls the freshly rendered
   #smResultsInner out of the response, and swaps it into the
   already-open modal's results view. This is what replaces every
   old `location.href = 'search.php?q=...'` call.
══════════════════════════════════════════════════════════════ */
function smRunFullSearch(q) {
    q = (q || '').trim();
    if (!q) return;

    smAddRecent({ type: 'query', id: btoa(encodeURIComponent(q)).slice(0, 12), query: q });
    _smCurrentQuery = q;

    openSearchModal();
    smSwitchView('results');
    smSetResultsPill(q);
    smShowResultsLoading(true);

    var newUrl = 'search.php?q=' + encodeURIComponent(q);
    if (window.location.pathname.replace(/^.*\//, 'search.php') + window.location.search !== newUrl) {
        history.pushState({ smQuery: q }, '', newUrl);
    }

    var token = ++_smFetchToken;
    fetch(newUrl)
        .then(function(r) { return r.text(); })
        .then(function(html) {
            if (token !== _smFetchToken) return; // a newer search started meanwhile
            smInjectResultsHTML(html, q);
        })
        .catch(function() {
            if (token !== _smFetchToken) return;
            smShowResultsLoading(false);
        });
}

function smSetResultsPill(q) {
    var wrap = document.querySelector('#smViewResults .srm-title-row');
    if (!wrap) return;
    var pillText = document.getElementById('srmPillText');
    if (pillText) {
        pillText.textContent = q;
        pillText.setAttribute('onclick', "smReopenSearchInput(" + JSON.stringify(q) + ")");
        return;
    }
    // No pill exists yet (e.g. this was the very first search on a
    // fresh page) — build one to match the server-rendered markup.
    wrap.innerHTML = '<div class="srm-search-pill">'
        + '<button type="button" id="srmPillText" class="srm-search-pill-text" onclick="smReopenSearchInput(' + JSON.stringify(q).replace(/"/g,'&quot;') + ')">' + smEsc(q) + '</button>'
        + '<span class="srm-pill-divider"></span>'
        + '<a href="search.php" id="srmClearBtn" class="srm-clear-btn" onclick="return smClearFullSearch(event)">Clear</a>'
        + '</div>';
}

function smShowResultsLoading(on) {
    var loader = document.getElementById('smResultsLoading');
    if (!loader) return;
    loader.classList.toggle('srm-loading-active', !!on);
}

/* Parses the fetched page's HTML and splices its #smResultsInner
   into the current DOM. Because #smViewResults, its tabs, and the
   follow/group buttons are all wired via event delegation (below),
   nothing needs to be rebound after this swap. */
function smInjectResultsHTML(html, q) {
    var current = document.getElementById('smResultsInner');
    if (!current) { smShowResultsLoading(false); return; }

    // NEW — clear the old search feed before the new HTML (and its
    // srmSearchFeed.push(...) script tags) come in, so stale entries
    // from the previous query don't linger or double up.
    window.srmSearchFeed = [];

    var parser = new DOMParser();
    var doc    = parser.parseFromString(html, 'text/html');
    var fresh  = doc.getElementById('smResultsInner');

    if (fresh) {
        current.innerHTML = fresh.innerHTML;
    } else {
        current.innerHTML = '<div class="srm-empty"><i class="ri-error-warning-line"></i>'
            + '<p>Something went wrong</p><small>Try searching again.</small></div>';
    }

    // NEW — innerHTML never executes <script> tags it inserts, so the
    // srmSearchFeed.push(...) blocks rendered by search.php's PHP loop
    // are inert at this point. Manually re-create and run each one.
    current.querySelectorAll('script').forEach(function(oldScript){
        var newScript = document.createElement('script');
        if (oldScript.src) {
            newScript.src = oldScript.src;
        } else {
            newScript.textContent = oldScript.textContent;
        }
        oldScript.parentNode.replaceChild(newScript, oldScript);
    });

    // Reset to the "For You" tab on every fresh search
    var content = document.querySelector('#smViewResults .srm-content');
    if (content) { content.classList.remove('srm-hide-titles'); content.scrollTop = 0; }
    var emptyTab = document.getElementById('srmEmptyTab');
    if (emptyTab) emptyTab.style.display = 'none';

    smShowResultsLoading(false);
}

/* ── Reopen the typing view, keeping the query editable ── */
function smReopenSearchInput(query) {
    smSwitchView('search');
    var input = document.getElementById('modalSearchInput');
    if (!input) return;
    input.value = query || '';
    document.getElementById('searchRecents').style.display = 'block';
    document.getElementById('searchLiveResults').style.display = 'none';
    document.getElementById('modalClearBtn').style.display = query ? 'block' : 'none';
    document.getElementById('smSearchBtn').style.display   = query ? 'inline-block' : 'none';
    setTimeout(function() { input.focus(); input.setSelectionRange(input.value.length, input.value.length); }, 80);
}

/* ── Clear results & go back to a blank search ── */
function smClearFullSearch(e) {
    if (e) e.preventDefault();
    _smCurrentQuery = '';
    history.pushState({}, '', 'search.php');
    smReopenSearchInput('');
    return false;
}

/* ── Browser back/forward support ── */
window.addEventListener('popstate', function(e) {
    var params = new URLSearchParams(window.location.search);
    var q = params.get('q');
    if (q) {
        if (!smOpen) openSearchModal();
        smSwitchView('results');
        smSetResultsPill(q);
        smShowResultsLoading(true);
        var token = ++_smFetchToken;
        fetch('search.php?q=' + encodeURIComponent(q))
            .then(function(r) { return r.text(); })
            .then(function(html) { if (token === _smFetchToken) smInjectResultsHTML(html, q); })
            .catch(function() { if (token === _smFetchToken) smShowResultsLoading(false); });
    } else if (smOpen) {
        smSwitchView('search');
    }
});


/* ══════════════════════════════════════════════════════════════
   RESULTS VIEW — delegated event handling.
   Bound ONCE on #smViewResults, which never gets replaced (only
   its #smResultsInner child does), so tabs / follow / group /
   private-request buttons keep working after every AJAX refresh
   without needing to be rebound.
══════════════════════════════════════════════════════════════ */
(function() {
    var root = document.getElementById('smViewResults');
    if (!root) return;

    var tabEmptyCopy = {
        dezzmers: {
            icon: 'ri-user-3-line',
            title: 'No Dezzmers match this search',
            text: "We couldn't find any Dezzmers for this. Try a nickname, username, or Profile ID instead."
        },
        pods: {
            icon: 'ri-team-line',
            title: 'No Pods here yet',
            text: "This search didn't turn up any Pods. Once one matches, it'll show up right here."
        },
        posts: {
            icon: 'ri-image-2-line',
            title: 'No Posts found',
            text: "Nothing matched this search in Posts. Try a shorter word or a different tag."
        },
        memepedia: {
            icon: 'ri-book-open-line',
            title: 'No Memepedia entries found',
            text: "This search didn't match any Memepedia articles. Maybe you're the first to write one."
        },
        collections: {
            icon: 'ri-folder-3-line',
            title: 'No Collections found',
            text: "We couldn't find a Collection for this search. Check another tab, your search might live there instead."
        },
        sounds: {
            icon: 'ri-music-2-line',
            title: 'No Sounds found',
            text: "This search didn't match any Sounds. Try a track name or the creator's username."
        },
        soundfolders: {
            icon: 'ri-folder-music-line',
            title: 'No Sound Folders found',
            text: "We couldn't find a Sound Folder for this search. It might be sitting under Sounds instead."
        }
    };

    function updateEmptyTabState(filter) {
        var emptyTab = document.getElementById('srmEmptyTab');
        if (!emptyTab) return;

        if (filter === 'all') {
            emptyTab.style.display = 'none';
            return;
        }

        var hasMatch = root.querySelector('.srm-section[data-section="' + filter + '"]') !== null;

        if (hasMatch) {
            emptyTab.style.display = 'none';
            return;
        }

        var copy = tabEmptyCopy[filter] || {
            icon: 'ri-search-2-line',
            title: 'Nothing here yet',
            text: 'Try another tab, or search for something else.'
        };
        document.getElementById('srmEmptyTabIcon').className = copy.icon;
        document.getElementById('srmEmptyTabTitle').textContent = copy.title;
        document.getElementById('srmEmptyTabText').textContent = copy.text;
        emptyTab.style.display = 'flex';
    }

    root.addEventListener('click', function(e) {
        /* ── tabs ── */
        var tab = e.target.closest('.srm-tab');
        if (tab) {
            root.querySelectorAll('.srm-tab').forEach(function(t) { t.classList.remove('active'); });
            tab.classList.add('active');

            var filter = tab.dataset.filter;
            root.querySelectorAll('.srm-section').forEach(function(sec) {
                if (filter === 'all' || sec.dataset.section === filter) {
                    sec.classList.remove('srm-hidden');
                } else {
                    sec.classList.add('srm-hidden');
                }
            });

            var content = root.querySelector('.srm-content');
            if (content) {
                content.classList.toggle('srm-hide-titles', filter !== 'all');
                content.scrollTop = 0;
            }

            updateEmptyTabState(filter);
            return;
        }

        /* ── follow / track toggle ── */
        var followBtn = e.target.closest('.follow-btn');
        if (followBtn) {
            e.stopPropagation();
            var userId = followBtn.dataset.user;
            followBtn.disabled = true;
            fetch('search.php', {
                method: 'POST',
                headers: {'Content-Type':'application/x-www-form-urlencoded'},
                body: 'action=toggle_follow&user_id=' + userId
            }).then(function(res){ return res.json(); }).then(function(data){
                followBtn.disabled = false;
                if(data.status === 'ok'){
                    if(data.following){
                        followBtn.classList.add('following','tracking');
                        followBtn.textContent = 'Untrack';
                    } else {
                        followBtn.classList.remove('following','tracking');
                        followBtn.textContent = 'Track';
                    }
                }
            }).catch(function(){ followBtn.disabled = false; });
            return;
        }

        /* ── group join/leave toggle ── */
        var groupBtn = e.target.closest('.group-btn');
        if (groupBtn) {
            e.stopPropagation();
            var groupId = groupBtn.dataset.group;
            groupBtn.disabled = true;
            fetch('search.php', {
                method: 'POST',
                headers: {'Content-Type':'application/x-www-form-urlencoded'},
                body: 'action=toggle_group&group_id=' + groupId
            }).then(function(res){ return res.json(); }).then(function(data){
                groupBtn.disabled = false;
                if(data.status === 'ok'){
                    if(data.joined){
                        groupBtn.classList.add('joined');
                        groupBtn.textContent = 'Leave';
                    } else {
                        groupBtn.classList.remove('joined');
                        groupBtn.textContent = 'Join';
                    }
                }
            }).catch(function(){ groupBtn.disabled = false; });
            return;
        }
    });
})();

/* ── srRequestTrack stays a plain global fn: it's wired via an
   inline onclick attribute in the PHP markup itself, so it keeps
   working automatically after every innerHTML swap — no delegation
   needed for it. ── */
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


/* ══════════════════════════════════════════════
   INITIAL LOAD
   - If the page loaded with ?q=... (shared link, or a non-JS
     fallback navigation), the PHP above already rendered the
     results inside #smResultsInner — just open the modal
     straight into the results view, no fetch needed.
   - ?focus=1 (+optional ?mq=) still opens straight into the
     typing view and pre-fills/searches, same as before.
══════════════════════════════════════════════ */
<?php if($search_query): ?>
window.addEventListener('DOMContentLoaded', function() {
    openSearchModal();
    smSwitchView('results');
});
<?php endif; ?>

(function() {
    var params = new URLSearchParams(window.location.search);
    if (params.get('focus') !== '1') return;

    var presetQ = params.get('mq') || '';

    setTimeout(function() {
        openSearchModal();
        smSwitchView('search');

        if (presetQ) {
            setTimeout(function() {
                var input = document.getElementById('modalSearchInput');
                if (!input) return;

                input.value = presetQ;
                document.getElementById('modalClearBtn').style.display     = 'block';
                document.getElementById('smSearchBtn').style.display       = 'inline-block';
                document.getElementById('searchRecents').style.display     = 'none';
                document.getElementById('searchLiveResults').style.display = 'block';
                document.getElementById('searchLiveResults').innerHTML =
                    '<div class="sm-loader"><i class="ri-loader-4-line sm-spin"></i></div>';

                smLastQ = presetQ;
                fetch('search.php?type=autocomplete&q=' + encodeURIComponent(presetQ))
                    .then(function(r) { return r.json(); })
                    .then(function(d) { if (presetQ === smLastQ) smRenderResults(d, presetQ); })
                    .catch(function()  { smRenderResults([], presetQ); });
            }, 180);
        }
    }, 120);
})();
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

/* ── Pre-fetch attached-audio info for every Zeem (same behavior as home.php) ──
   Memes with a real row in meme_audio (an actually attached track — can sit
   on a photo, a multi-media post, or a video) show up here as-is, including
   the real is_original flag from the meme_audio relationship.

   Videos with NOTHING attached get a backfilled "native" audios row so the
   pill and modal always have a real audio_id to open — is_original=1 marks
   these as sound-that-came-with-the-video, not a separately chosen track.

   REQUIRES (run once, same migration as home.php):
     ALTER TABLE audios ADD COLUMN source_meme_id INT UNSIGNED NULL,
                         ADD UNIQUE KEY uniq_source_meme (source_meme_id);
*/
$_audio_map = [];
if(!empty($meme_ids)) {
    $ids_str = implode(',', array_map('intval', $meme_ids));

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

    // ── Backfill native audio identity for videos with nothing attached ──
    foreach($memes as $mrow){
        $mid = (int)$mrow['id'];
        if(($mrow['type'] ?? '') !== 'video') continue;
        if(isset($_audio_map[$mid])) continue;

        $existing = $conn->query("SELECT id FROM audios WHERE source_meme_id=$mid LIMIT 1")->fetch_assoc();

        if($existing){
            $aid = (int)$existing['id'];
        } else {
            $uploader_id = (int)($mrow['uploaded_by'] ?? 0);
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
    $share_count = $conn->query("SELECT COUNT(*) as c FROM shares WHERE meme_id=$meme_id")->fetch_assoc()['c'];

    $group_info = $_group_map[$meme_id] ?? null;
    $audio_info = $_audio_map[$meme_id] ?? null;

    /* ── NICKNAME — fetched here since the main discover query doesn't select it ── */
    if(!isset($row['nickname'])){
        $nick_res = $conn->query("SELECT nickname FROM users WHERE id=$uploader_id LIMIT 1");
        $nick_row = $nick_res ? $nick_res->fetch_assoc() : null;
        $row['nickname'] = $nick_row['nickname'] ?? '';
    }

    /* safe JS args — NO newlines inside the string */
    $safe_file     = addslashes($row['file_name']);
    $safe_type     = addslashes($row['type']);
    $safe_pic      = addslashes(!empty($row['profile_pic']) ? $row['profile_pic'] : 'uploads/mee.jpeg');
    $safe_username = addslashes($row['username']);
    $safe_caption  = addslashes(str_replace(["\r","\n"], ' ', $row['caption']));
    $safe_nickname = addslashes($row['nickname'] ?? '');

    /* ── gather all media items for this Zeem (gallery support) ── */
    $media_items = [[ 'file' => $row['file_name'], 'type' => $row['type'] ]];
    if(!empty($_media_map[$meme_id])) {
        $media_items = array_merge($media_items, $_media_map[$meme_id]);
    }
    $media_count = count($media_items); // total media items for this post

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
    echo '<div class="meme-btn left share-btn" data-id="'.$meme_id.'" onclick="event.stopPropagation();openShareModal('.$meme_id.')" style="left:159px;">
        <i class="ri-share-forward-fill"></i><span class="count share-count">'.$share_count.'</span>
    </div>';
    echo '<div class="meme-btn right index-kebab-btn" data-id="'.$meme_id.'" onclick="event.stopPropagation();openIndexSheet('.$meme_id.')">
<i class="ri-menu-line"></i>
</div>';

    /* ── GROUP TAG (kept, Posted By + Track removed) ── */
    if($group_info){
        $g_id    = (int)$group_info['group_id'];
        $g_title = htmlspecialchars($group_info['group_title']);
        echo '<div class="discover-group-tag" onclick="event.stopPropagation()" style="position:absolute;top:48px;left:12px;display:flex;align-items:center;gap:4px;font-size:clamp(0.58rem,1.8vw,0.65rem);font-weight:500;color:rgba(255,255,255,0.88);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:calc(100% - 16px);z-index:5;">
            <i class="ri-team-line" style="font-size:0.7rem;color:rgba(255,255,255,0.4);flex-shrink:0;"></i>
            From&nbsp;<a href="group.php?id='.$g_id.'" onclick="event.stopPropagation()" style="color:#FF4C4C;text-decoration:none;font-weight:600;font-size:clamp(0.58rem,1.8vw,0.65rem);">'.$g_title.'</a>
        </div>';
    }

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

    /* ── multi-media indicator badge — shows "1/N" when a post has more than 1 file ── */
    if($media_count > 1){
        echo '<div class="discover-multi-badge" onclick="event.stopPropagation()">
            <i class="ri-stack-line"></i> 1/'.$media_count.'
        </div>';
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

    /* ── SOCIAL PROOF — pull from the batch map built earlier in this file, defaulting to empty ── */
    $sp_for_meme = $social_proof_map[$meme_id] ?? [];
    $sp_json = json_encode(array_map(function($sp){
        return [
            'id'          => (int)$sp['id'],
            'username'    => $sp['username'],
            'profile_pic' => $sp['profile_pic'],
            'action_type' => $sp['action_type'],
        ];
    }, $sp_for_meme));

    /* ── FEED DATA FOR MODAL ── */
    echo '<script>
    if(typeof peuraFeed==="undefined") var peuraFeed=[];
    if(typeof window.kebabData==="undefined") window.kebabData={};
    peuraFeed.push({
        id:'.(int)$meme_id.',
        file:"uploads/'.addslashes($row['file_name']).'",
        type:"'.addslashes($row['type']).'",
        media:'.json_encode(array_map(function($m){
                     return ['file' => 'uploads/'.$m['file'], 'type' => $m['type']];
                 }, $media_items)).',
        pic:"'.addslashes(!empty($row['profile_pic']) ? $row['profile_pic'] : 'uploads/mee.jpeg').'",
        userId:"'.(int)$row['uploaded_by'].'",
        username:"'.addslashes($row['username']).'",
        nickname:"'.$safe_nickname.'",
        caption:"'.addslashes(str_replace(["\r","\n"],' ',$row['caption'])).'",
        time:"'.addslashes($row['created_at']).'",
        likeCount:'.(int)$total_likes.',
        repostCount:'.(int)$repost_count.',
        favCount:'.(int)$fav_count.',
        shareCount:'.(int)$share_count.',
        liked:'.($user_liked?'true':'false').',
        reposted:'.($is_repost?'true':'false').',
        faved:'.($is_fav?'true':'false').',
        isPartnership: '.(!empty($row['is_partnership']) ? 'true' : 'false').',
        partnerBrand:  '.(!empty($row['partner_brand']) ? '"'.addslashes($row['partner_brand']).'"' : 'null').',
        isAiGenerated: '.(!empty($row['is_ai_generated']) ? 'true' : 'false').',
        isAd:          '.(!empty($row['is_ad']) ? 'true' : 'false').',
        adCtaText:     '.(!empty($row['ad_cta_text']) ? '"'.addslashes($row['ad_cta_text']).'"' : '""').',
        adCtaUrl:      '.(!empty($row['ad_cta_url']) ? '"'.addslashes($row['ad_cta_url']).'"' : '""').',
        adCtaColor:    '.(!empty($row['ad_cta_color']) ? '"'.addslashes($row['ad_cta_color']).'"' : '"#FF4C4C"').',
        groupId:     '.($group_info ? (int)$group_info['group_id'] : 'null').',
        groupTitle:  '.($group_info ? '"'.addslashes($group_info['group_title']).'"' : 'null').',
        audioId:     '.($audio_info ? (int)$audio_info['audio_id'] : 'null').',
        audioTitle:  '.($audio_info ? '"'.addslashes($audio_info['title']).'"' : 'null').',
        audioArtist: '.($audio_info && !empty($audio_info['artist']) ? '"'.addslashes($audio_info['artist']).'"' : 'null').',
        audioFile:   '.($audio_info ? '"uploads/'.addslashes($audio_info['file_name']).'"' : 'null').',
        audioIsOriginal: '.($audio_info && !empty($audio_info['is_original']) ? 'true' : 'false').',
        musicVolume: '.($audio_info ? (int)$audio_info['music_volume'] : 80).',
        videoAudioVolume: '.($audio_info ? (int)$audio_info['video_audio_volume'] : 40).',
        socialProof: '.$sp_json.'
    });
    window.kebabData['.(int)$meme_id.'] = {
        id:             '.(int)$meme_id.',
        fileName:       "'.addslashes($row['file_name']).'",
        type:           "'.addslashes($row['type']).'",
        uploadedBy:     '.(int)$row['uploaded_by'].',
        remixOf:        '.(!empty($row['remix_of']) ? (int)$row['remix_of'] : 'null').',
        caption:        "'.addslashes(str_replace(["\r","\n"],' ',$row['caption'])).'",
        isAd:           '.(!empty($row['is_ad']) ? 'true' : 'false').',
        posterPic:      "'.addslashes(!empty($row['profile_pic']) ? $row['profile_pic'] : 'uploads/me.jpeg').'",
        posterUsername: "'.addslashes($row['username']).'"
    };
    </scr'.'ipt>';

    echo '</div>';
    $index++;
}
?>
</div>

<!-- ═══════════════════════════════════════════════════════
     INDEX BOTTOM SHEET (shared pattern with home.php / text_feed.php)
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

/* item with a small subtitle line (used by ad items) */
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

/* ═══ RESPONSIVE ═══ */
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

/* ── KEBAB BUTTON — bigger tap target + icon since it's icon-only ── */
.meme-btn {
    position: absolute; display: flex;
    align-items: center; justify-content: center;
    gap: 6.2px; padding: 6px 6px;
    min-width: 42px; height: 32px;
    border-radius: 12px; background: transparent;
    color: white; font-size: 14px; cursor: pointer;
    transition: 0.2s ease; z-index: 5;
    top: 8px;
}
.meme-btn.index-kebab-btn i {
    font-size: 16px;
}
.meme-btn.index-kebab-btn:hover {
    background: rgba(255,255,255,0.18);
    transform: scale(1.08);
}

@media (max-width: 768px) {
    .meme-btn.index-kebab-btn {
        min-width: 36px;
        width: 36px;
        height: 36px;
    }
    .meme-btn.index-kebab-btn i {
        font-size: 17px;
    }
    .meme-btn.left, .meme-btn.right { padding:5px 8px; border-radius:8px; top:8px !important; }
}

@media (max-width: 480px) {
    .meme-btn.index-kebab-btn {
        min-width: 34px;
        width: 34px;
        height: 34px;
    }
    .meme-btn.index-kebab-btn i {
        font-size: 16px;
    }
    .meme-btn.left, .meme-btn.right { padding:5px 8px; border-radius:8px; top:8px !important; }
}

@media (max-width: 380px) {
    .meme-btn.index-kebab-btn {
        min-width: 32px;
        width: 32px;
        height: 32px;
    }
    .meme-btn.index-kebab-btn i {
        font-size: 15px;
    }
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
   sponsorships-table ad. Mirrors Instagram's ad menu. Every
   item carries a title+subtitle pair. ── */
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
            'This app finds and suggests posts based on your recent activity — the tags you interact with, '+
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
            'Ads are shown based on a mix of signals — your recent activity, the tags and creators you '+
            'engage with, choices the advertiser makes about who they want to reach, and what\'s trending '+
            'across the app right now. Ads help keep this app free to use for everyone.'+
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
            uname+' chose to reach people with interests similar to yours. Your recent activity — including '+
            'posts, tags, and creators you\'ve engaged with — was also factored in to decide this ad was '+
            'worth showing you right now. As your activity changes over time, the ads you see may change too.'+
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
   doesn't exist yet. Add a handler in search.php's POST block, e.g.:
     INSERT IGNORE INTO hidden_ad_advertisers (user_id, hidden_user_id) VALUES (?, ?)
   and filter the search/discover results with:
     AND (memes.is_ad = 0 OR memes.uploaded_by NOT IN (
         SELECT hidden_user_id FROM hidden_ad_advertisers WHERE user_id = ?
     )) */
function idxHideAllAdsFromUser(userId, username){
    if(!confirm('Hide all ads from '+(username||'this account')+'?')) return;
    fetch('search.php', {
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
    bringModalToFront(document.getElementById('indexSheetOverlay'));
    bringModalToFront(document.getElementById('indexSheetPanel'));
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

/* ── BLOCK USER (unchanged, still search.php) ── */
function blockUser(userId){
    if(!confirm('Block this user? You won\'t see their content anymore.')) return;
    fetch('search.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=block_user&target_id=' + userId
    })
    .then(function(res){ return res.json(); })
    .then(function(data){
        if(data.status === 'ok'){
            document.querySelectorAll('.meme-tile[data-uploader="'+userId+'"]').forEach(function(card){
                card.style.transition = 'opacity 0.4s, transform 0.4s';
                card.style.opacity    = '0';
                card.style.transform  = 'scale(0.95)';
                setTimeout(function(){ card.remove(); }, 400);
            });
            closeDiscoverModal();
            showToast('User blocked');
        }
    });
}

/* ── COPY CAPTION (unchanged) ── */
function copyCaption(caption) {
    navigator.clipboard.writeText(caption).then(() => {
        showToast('Caption copied!');
    }).catch(() => {
        const el = document.createElement('textarea');
        el.value = caption;
        document.body.appendChild(el);
        el.select();
        document.execCommand('copy');
        document.body.removeChild(el);
        showToast('Caption copied!');
    });
}

/* ── NOT INTERESTED (unchanged, still search.php) ── */
function notInterested(memeId, el){
    var card = el
        ? (el.closest('.meme-tile') || el.closest('.card-unit') || el.closest('.card'))
        : document.querySelector('.meme-tile[data-id="'+memeId+'"]');

    fetch('search.php', {
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
        closeDiscoverModal();
        showToast('Got it — you\'ll see less like this');
    });
}

/* ── QR CODE (unchanged) ── */
function showMemeQR(memeId) {
    const url = window.location.origin + '/Peura/index.php?meme=' + memeId;
    const qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(url);
    const win = window.open('', '_blank', 'width=260,height=280');
    win.document.write('<body style="margin:0;background:#000;display:flex;align-items:center;justify-content:center;height:100vh;"><img src="'+qrUrl+'" style="border-radius:12px;"></body>');
}

/* ── SHARE POST (unchanged) ── */
function shareMeme(memeId) {
    const url = window.location.origin + '/Peura/index.php?meme=' + memeId;
    if(navigator.share){
        navigator.share({ title: 'Check this on PEURA', url: url });
    } else {
        navigator.clipboard.writeText(url);
        showToast('Link copied to clipboard!');
    }
}

/* ── TOAST HELPER (unchanged) ── */
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

<!-- ═══════════════════════════════════════════════════════
     DISCOVER CARD MODAL — rebuilt to be atomically identical
     to the meme card caption modal (Reels/TikTok style),
     INCLUDING full multi-media gallery support (1–10 media
     items per Zeem via peuraFeed[i].media), exactly matching
     home.php's implementation. Wired to search.php's own
     endpoints (ads/sugg/actions), keeps the openDiscoverModal(...)
     / closeDiscoverModal() function signatures so every existing
     onclick in search.php keeps working without any other changes.
     ═══════════════════════════════════════════════════════ -->
<!-- DISCOVER CARD MODAL -->
<div id="discoverModal" onclick="if(event.target===this) closeDiscoverModal()">
    <div id="ccm-stage">
        <button type="button" id="ccmFixedBackBtnDiscover" class="ccm-icon-btn ccm-back-btn"
                onclick="event.stopPropagation();closeDiscoverModal()">
            <i class="ri-arrow-left-s-line"></i>
        </button>
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
   DISCOVER CARD MODAL — REELS/TIKTOK STYLE FULL-SCREEN PLAYER
   Ported onto the SAME gapless vertical-scroll engine as the
   meme/CCM caption modal: #ccm-inner hosts a #ccm-vtrack holding
   up to 3 .ccm-vslide wrappers (prev/current/next), each already
   fully rendered before the person ever swipes. Dragging moves the
   whole track by the raw touch delta so the neighbor post/ad/
   suggested-widget is visibly there under your finger the instant
   you start dragging. On a committed swipe, the already-mounted
   neighbor is promoted to "current" (its media reused as-is,
   already decoded) — only the vacant side gets a fresh, silent,
   paused slide built in the background. Chrome (top bar, tags,
   rail, bottom panel, suggested-widget header) dims and drifts
   during the drag and snaps to full brightness once a slide
   settles as current — identical to the meme/CCM modal.
   Ad impression/"shown" logging and the suggested-carousel's
   active background/pager state are now gated to ACTIVATION only,
   not render, so a pre-built preview neighbor never counts as
   "shown" before the person actually swipes to it.
   ══════════════════════════════════════════════════════════ */

#discoverModal{
    display:none;
    position:fixed;inset:0;
    z-index: 999997;
    background:#000;
    align-items:center;
    justify-content:center;
}
#discoverModal.ccm-open{ display:flex; }

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
    /* no per-slide transition — sliding is driven by .ccm-vtrack's
       own transform transition; chrome dim/drift is driven
       per-element via inline styles set from JS. */
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

.ccm-media-dashes{
    display:flex; justify-content:center; align-items:center; gap:6px;
    margin-top:18px;
    margin-left:-16px; margin-right:-78px;
    width:calc(100% + 94px);
    padding:0 12px;
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

#ccmFixedBackBtn, #ccmFixedBackBtnMeme, #ccmFixedBackBtnDiscover{
    position:absolute;
    top:14px;
    left:4px;
    z-index:50;
}
@media (max-width:490px){
    #ccmFixedBackBtn, #ccmFixedBackBtnMeme, #ccmFixedBackBtnDiscover{ top:11px; left:3px; }
}
/* ══════════════════════════════════════════════════════════
   VIDEO PROGRESS BAR — matches home.php's ccm modal exactly
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
   count, desktop hover arrows + keyboard left/right support.
   ══════════════════════════════════════════════════════════ */
.ccm-sugg-slide{
    position:relative; width:100%; height:100%;
    overflow:hidden; background:#000;
}

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
   DISCOVER MODAL JS — ported onto the gapless track engine.
   Data source (ccmFeed built by _buildCcmFeed from peuraFeed +
   ads + suggested-user widgets), endpoints (search.php's
   toggle_like/toggle_repost/toggle_favorite/toggle_ad_like/
   toggle_ad_favorite/toggle_follow/log_ad_shown/
   log_ad_impression/ad_not_interested, home.php's toggle_follow),
   and public function names (openDiscoverModal, closeDiscoverModal,
   ccmLoadIndex, ccmAdNotInterested, feedAdNotInterested,
   handleAdLike/handleAdFav) are all unchanged so every existing
   onclick elsewhere on the page keeps working.

   THIS VERSION ADDS:
   - "Saved · Collections" toast on favoriting a POST (ccmAction fav)
     and on favoriting an AD (ccmAdFavAction), matching the meme
     modal's behavior. Self-injects its own <style> block since this
     page didn't already have .ccm-saved-toast CSS.
   - Double-tap-to-like on AD slides. ccmDoubleTapLike now detects
     whether the current slide is a post (#ccm-like-btn) or an ad
     (#ccm-ad-like-btn) and fires the correct like action
     (ccmAction('like') vs ccmAdLikeAction(_ccmCurrentAdId)). Heart
     burst animation is unchanged/shared — it was already generic.
   ════════════════════════════════════════════════════════════ */

var _ccmIndex            = 0;
var _ccmMemeId           = null;
var _ccmPostUserId       = null;
var _ccmMediaIndex       = 0;
var ccmFeed               = [];
var _ccmActiveFeed        = ccmFeed;   // NEW — the feed currently being navigated (grid feed by default, swapped to srmSearchFeed when opened from search results)
var _ccmCaptionFullHtml   = '';
var _ccmCaptionTruncHtml  = '';
var _ccmCaptionExpanded   = false;
var _ccmInfoSwitchTimer   = null;
var _ccmAdFromSwitchTimer = null;
var _ccmVbSwitchTimer     = null;
var _ccmCtaPillTimer      = null;   // NEW — cycles the CTA pill small ↔ expanded on ad posts
var _ccmActiveSuggData    = null;
var _ccmBuilt             = false;
var _ccmVSwipeLocked      = false;

/* NEW: tracks which ad is currently active on screen, so double-tap
   and other ad-scoped actions know which sponsorship_id to hit.
   Mirrors how _ccmMemeId tracks the active post. Always null while
   a post or suggested-users slide is current. */
var _ccmCurrentAdId = null;

/* Per-post pause state, so returning to a post you manually paused
   before scrolling away keeps it paused. Ads/suggested slides don't
   use this (their _ccmMemeId is always null). */
var _ccmPausedMemeIds = {};

/* Per-post horizontal media (gallery) index, keyed by post id. */
var _ccmMediaIndexByPost = {};

/* Per-post attached-soundtrack playback position, keyed by post id
   — not the audio file URL — so two posts sharing a track file stay
   independent, and returning to a post resumes it where it left off. */
var _ccmTrackPositions = {};

const currentUserId = <?php echo (int)$current_user; ?>;

function _ccmComputeDashGap(count){
    return Math.max(3, Math.min(6, Math.round(60 / count)));
}

/* ────────────────────────────────────────────────────────────
   SAVED TOAST — self-injecting styles + show/hide/collections-jump.
   Matches the meme modal's toast exactly (same class names), so if
   .ccm-saved-toast CSS already exists elsewhere on this page this
   injector just no-ops (it checks for its own <style> id first).
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
    bringModalToFront(toast);
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
   DATA LOADING
──────────────────────────────────────────────────────────── */
function _loadCcmData(){
    Promise.all([
        fetch('home.php?type=ads').then(function(r){ return r.json(); }).catch(function(){ return []; }),
        fetch('home.php?type=sugg').then(function(r){ return r.json(); }).catch(function(){ return []; })
    ]).then(function(results){
        window.cmAds       = results[0] || [];
        window.cmSuggUsers = results[1] || [];
        _buildCcmFeed();
        _ccmBuilt = true;
    });
}

function _buildCcmFeed(){
    ccmFeed = [];
    var adIdx = 0, suggIdx = 0, adSlot = 0;
    var ads  = window.cmAds       || [];
    var sugg = window.cmSuggUsers || [];
    peuraFeed.forEach(function(post, i){
        ccmFeed.push({ type:'post', data:post });
        if((i+1) % 4 === 0 && ads.length > 0){
            ccmFeed.push({ type:'ad', data:ads[adIdx % ads.length], adSlot:adSlot });
            adIdx++;
            adSlot++;
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
    return (caption||'')
        .replace(/#([a-zA-Z0-9_]+)/g, function(match, tag){
            var encTag = encodeURIComponent('#'+tag).replace(/'/g, "\\'");
            return '<a href="javascript:void(0)"'+
                   ' onclick="event.stopPropagation();linkifyOpenTag(\''+encTag+'\')"'+
                   ' style="color:#6366f1;font-weight:600;text-decoration:none;"'+
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

function linkifyOpenTag(encTag){
    var presetQ = decodeURIComponent(encTag);

    // The discover modal (post card view) was brought to the top of the
    // z-index stack when it opened, sitting above #searchModal. Simply
    // switching #searchModal's internal view isn't enough — it's still
    // hidden underneath. Close the discover modal and bring the search
    // modal back to front so the switched view is actually visible.
    if(typeof closeDiscoverModal === 'function'){
        closeDiscoverModal();
    }

    if(!smOpen){ openSearchModal(); }
    smSwitchView('search');
    bringModalToFront(document.getElementById('searchModal'));

    var input = document.getElementById('modalSearchInput');
    if (!input) return;

    input.value = presetQ;
    document.getElementById('modalClearBtn').style.display     = 'block';
    document.getElementById('smSearchBtn').style.display       = 'inline-block';
    document.getElementById('searchRecents').style.display     = 'none';
    document.getElementById('searchLiveResults').style.display = 'block';
    document.getElementById('searchLiveResults').innerHTML =
        '<div class="sm-loader"><i class="ri-loader-4-line sm-spin"></i></div>';

    smLastQ = presetQ;
    fetch('search.php?type=autocomplete&q=' + encodeURIComponent(presetQ))
        .then(function(r) { return r.json(); })
        .then(function(d) { if (presetQ === smLastQ) smRenderResults(d, presetQ); })
        .catch(function()  { smRenderResults([], presetQ); });
}

function _ccmComputeCaptionHtml(p){
    var media = (Array.isArray(p.media) && p.media.length) ? p.media.slice() : [{file:p.file, type:p.type}];
    var mediaSet = ['image','video','gif','sticker','stickers'];
    var firstType = (media[0].type || '').toLowerCase();
    var isTextOnly = media.length === 1 && mediaSet.indexOf(firstType) === -1;

    var captionHtml = linkifyCaption(p.caption || '');
    var full  = captionHtml;
    var trunc = captionHtml;

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
    return { full: full, trunc: trunc, isTextOnly: isTextOnly };
}

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
        if(wrapEl){
            wrapEl.classList.remove('ccm-expanded');
            wrapEl.scrollTop = 0;
        }
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

/* ────────────────────────────────────────────────────────────
   PREMIUM SKELETON LOADER
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

function ccmHideSkeleton(root){
    var sk = root ? root.querySelector('#ccm-skeleton') : document.getElementById('ccm-skeleton');
    if(!sk) return;
    sk.classList.add('ccm-skeleton-hide');
    setTimeout(function(){ if(sk && sk.parentNode) sk.remove(); }, 340);
}

function _ccmCheckCaptionOverflow(slide){
    if(!slide) return;
    var wrap = slide.querySelector('.ccm-center-caption-wrap');
    var text = slide.querySelector('.ccm-center-caption-text');
    if(!wrap || !text) return;
    var isOverflowing = text.scrollHeight > wrap.clientHeight;
    wrap.classList.toggle('ccm-caption-overflow', isOverflowing);
}

/* ────────────────────────────────────────────────────────────
   AUDIO TITLE MARQUEE
──────────────────────────────────────────────────────────── */
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

/* ────────────────────────────────────────────────────────────
   NICKNAME / AUDIO-TITLE SWITCHER
──────────────────────────────────────────────────────────── */
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

/* ────────────────────────────────────────────────────────────
   AD "FROM X" / "X'S AD" SWITCHER — flips every 3s
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

/* ────────────────────────────────────────────────────────────
   "VISIT BRAND" / "LEARN MORE" SWITCHER — flips every 3s
──────────────────────────────────────────────────────────── */
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
   CTA PILL CYCLE (ad posts) — toggles the small CTA pill between
   its default small state and an expanded state (revealing the
   chevron) every 3s, on loop, for as long as this slide is active.
   Mirrors home.php's _ccmInitCtaPillCycle exactly.
──────────────────────────────────────────────────────────── */
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

/* ────────────────────────────────────────────────────────────
   ATTACHED SOUNDTRACK PLAYBACK (per-post-id position memory)
──────────────────────────────────────────────────────────── */
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

    if(!item || item.type !== 'post' || !item.data.audioFile){
        if(prevMemeId) _ccmTrackPositions[prevMemeId] = audioEl.currentTime || 0;
        audioEl.pause();
        audioEl.removeAttribute('src');
        delete audioEl.dataset.memeId;
        audioEl.load();
        return;
    }

    var p = item.data;
    var isSamePost = prevMemeId !== null && String(prevMemeId) === String(p.id);

    if(!isSamePost){
        if(prevMemeId) _ccmTrackPositions[prevMemeId] = audioEl.currentTime || 0;
        audioEl.src = p.audioFile;
        audioEl.dataset.memeId = p.id;
        var savedTime = _ccmTrackPositions[p.id] || 0;
        audioEl.currentTime = savedTime;
        audioEl.muted = false;
    }
    audioEl.volume = (p.musicVolume !== undefined && p.musicVolume !== null ? p.musicVolume : 80) / 100;

    if(_ccmPausedMemeIds[p.id]){
        audioEl.pause();
        var curSlide = document.getElementById('ccm-slide');
        var mb = curSlide ? curSlide.querySelector('#ccm-media-box') : null;
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

function _ccmPauseSoundtrack(){
    var el = document.getElementById('ccmSoundtrackPlayer');
    if(el) el.pause();
}

function _ccmResumeSoundtrack(){
    var el = document.getElementById('ccmSoundtrackPlayer');
    if(el && el.src) el.play().catch(function(){});
}

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

/* ════════════════════════════════════════════════════════════
   VIDEO PROGRESS — UPDATED: reads/writes the track/fill/thumb
   structure (matches home.php exactly) and skips updating while
   the user is actively dragging the thumb.
════════════════════════════════════════════════════════════ */
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

/* ════════════════════════════════════════════════════════════
   NEW: DRAG-TO-SEEK on the progress bar — matches home.php.
   Uses EVENT DELEGATION bound once on document, so it works
   regardless of when a bar enters the DOM (initial slide mount,
   gallery swipe, ad activation, promoted neighbor slide) without
   needing a bind call wired into every render path. Pauses/scrubs
   both the active gallery video AND the attached soundtrack (if
   playing for the current post) in lockstep, so audio stays synced
   to whatever frame you drag to.
════════════════════════════════════════════════════════════ */
(function _ccmInitProgressScrubDelegated(){
    if(window._ccmProgressScrubBound) return;
    window._ccmProgressScrubBound = true;

    var dragging  = false;
    var activeBar = null;

    function getMedia(bar){
        var slide = bar.closest('.ccm-slide');
        var video = slide ? (
            slide.querySelector('.ccm-media-item[data-idx="'+_ccmMediaIndex+'"] video.ccm-media')
            || slide.querySelector('video.ccm-media')
        ) : null;
        var trackEl = document.getElementById('ccmSoundtrackPlayer');
        var hasTrack = trackEl && trackEl.src && trackEl.dataset.memeId && String(trackEl.dataset.memeId) === String(_ccmMemeId);
        return { video: video, audio: hasTrack ? trackEl : null };
    }
    function pctFromX(bar, x){
        var track = bar.querySelector('.ccm-video-progress-track');
        if(!track) return 0;
        var r = track.getBoundingClientRect();
        return Math.max(0, Math.min(1, (x - r.left) / r.width));
    }
    function paint(bar, pct){
        var fill  = bar.querySelector('.ccm-video-progress-fill');
        var thumb = bar.querySelector('.ccm-video-progress-thumb');
        if(fill)  fill.style.width = (pct*100)+'%';
        if(thumb) thumb.style.left = (pct*100)+'%';
    }
    function seekTo(bar, pct){
        var m = getMedia(bar);
        if(m.video && !isNaN(m.video.duration)) m.video.currentTime = pct * m.video.duration;
        if(m.audio && !isNaN(m.audio.duration))  m.audio.currentTime = pct * m.audio.duration;
    }
    function startDrag(bar, x){
        dragging  = true;
        activeBar = bar;
        bar.classList.add('scrubbing');
        var m = getMedia(bar);
        if(m.video) m.video.pause();
        if(m.audio) m.audio.pause();
        paint(bar, pctFromX(bar, x));
    }
    function moveDrag(x){
        if(!dragging || !activeBar) return;
        var pct = pctFromX(activeBar, x);
        paint(activeBar, pct);
        seekTo(activeBar, pct);
    }
    function endDrag(x){
        if(!dragging || !activeBar) return;
        var bar = activeBar;
        dragging  = false;
        activeBar = null;
        seekTo(bar, pctFromX(bar, x));
        bar.classList.remove('scrubbing');
        var wasManuallyPaused = _ccmMemeId ? !!_ccmPausedMemeIds[_ccmMemeId] : false;
        if(!wasManuallyPaused){
            var m = getMedia(bar);
            if(m.video) m.video.play().catch(function(){});
            if(m.audio) m.audio.play().catch(function(){});
        }
    }

    document.addEventListener('touchstart', function(e){
        var bar = e.target.closest && e.target.closest('.ccm-video-progress');
        if(!bar) return;
        e.stopPropagation();
        startDrag(bar, e.touches[0].clientX);
    }, { passive:true, capture:true });

    document.addEventListener('touchmove', function(e){
        if(!dragging) return;
        e.stopPropagation();
        moveDrag(e.touches[0].clientX);
    }, { passive:true, capture:true });

    document.addEventListener('touchend', function(e){
        if(!dragging) return;
        e.stopPropagation();
        endDrag(e.changedTouches[0].clientX);
    }, { passive:true, capture:true });

    document.addEventListener('mousedown', function(e){
        var bar = e.target.closest && e.target.closest('.ccm-video-progress');
        if(!bar) return;
        e.stopPropagation();
        startDrag(bar, e.clientX);
        function onMove(ev){ moveDrag(ev.clientX); }
        function onUp(ev){
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
            endDrag(ev.clientX);
        }
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
    }, true);

    document.addEventListener('click', function(e){
        var bar = e.target.closest && e.target.closest('.ccm-video-progress');
        if(bar) e.stopPropagation();
    }, true);
})();

/* No-op kept for compatibility in case anything external still calls
   ccmInitProgressScrub(bar) directly — binding is now handled globally
   via the delegated listeners above, so per-element binding calls are
   unnecessary but harmless. */
function ccmInitProgressScrub(bar){ /* handled by delegation */ }

/* ────────────────────────────────────────────────────────────
   TAP HANDLING
──────────────────────────────────────────────────────────── */
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

/* UPDATED: now handles both post slides (#ccm-like-btn -> ccmAction)
   and ad slides (#ccm-ad-like-btn -> ccmAdLikeAction). Only fires the
   like — never unlikes on double-tap, matching Instagram/TikTok
   behavior and matching what posts already did. */
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
    ring.style.left = x+'px';
    ring.style.top  = y+'px';
    mediaBox.appendChild(ring);
    requestAnimationFrame(function(){ ring.classList.add('show'); });
    setTimeout(function(){ ring.remove(); }, 800);

    var heart = document.createElement('div');
    heart.className = 'ccm-double-tap-heart';
    heart.style.left = x+'px';
    heart.style.top  = y+'px';
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
            p.style.left = x+'px';
            p.style.top  = y+'px';
            p.style.setProperty('--tx', tx+'px');
            p.style.setProperty('--ty', ty+'px');
            p.style.setProperty('--tr', rot+'deg');
            p.innerHTML = '<i class="ri-heart-3-fill" style="font-size:'+size+'px;"></i>';
            mediaBox.appendChild(p);

            setTimeout(function(){
                requestAnimationFrame(function(){ p.classList.add('show'); });
            }, 40 + i*15);

            setTimeout(function(){ p.remove(); }, 950 + i*15);
        })(i);
    }
}

/* ────────────────────────────────────────────────────────────
   TAP TO PAUSE / RESUME + MUTE + 10s SEEK
──────────────────────────────────────────────────────────── */
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

/* ────────────────────────────────────────────────────────────
   SMART OBJECT-FIT
──────────────────────────────────────────────────────────── */
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

    // Taller/equal than the box → always cover. This scales by width to
    // fill it exactly and only trims overflow off the top/bottom — sides
    // are never touched in this case, so it's safe.
    if(mediaAspect < boxAspect){
        el.style.objectFit = 'cover';
        return;
    }

    // Wider than the box → 'contain' already fills the width exactly
    // (that's never the issue here), it only leaves letterbox bars top/
    // bottom. If the media's height comes close enough to filling the box
    // once scaled to full width, STRETCH it (never crop) to close that
    // gap. If it's far off, leave 'contain' so nothing gets cut off.
    var scaledHeight = cW / mediaAspect;   // height once scaled to fill box width
    var fillRatio    = scaledHeight / cH;  // how close that is to full box height

    // Tweak this threshold: raise it to be stricter (fewer items get
    // stretched/filled), lower it to be more lenient (more items fill
    // edge-to-edge instead of showing letterbox bars).
    var FILL_THRESHOLD = 0.84;

    // 'fill' stretches height only (width is already exactly cW under
    // 'contain', so it stays untouched) — this closes the gap without
    // ever cropping the sides, unlike 'cover' which would crop them.
    el.style.objectFit = (fillRatio >= FILL_THRESHOLD) ? 'fill' : 'contain';
}

/* ────────────────────────────────────────────────────────────
   TRACK BUTTON
──────────────────────────────────────────────────────────── */
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

/* ════════════════════════════════════════════════════════════
   MULTI-MEDIA GALLERY — UPDATED: also shows/hides the (single,
   sibling-of-.ccm-bottom) progress bar based on whether the newly
   active gallery item is a video. Binding itself is handled
   globally by the delegated scrub listeners, so no per-element
   bind call is needed here.
════════════════════════════════════════════════════════════ */
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
                if(suppressAutoplay){
                    vid.pause();
                    ccmShowPausedControls(itemEl, vid);
                } else {
                    vid.play().catch(function(){});
                }
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
    }
}

function _ccmGetSavedMediaIndex(postId){
    if(postId === null || postId === undefined) return 0;
    var v = _ccmMediaIndexByPost[postId];
    return (typeof v === 'number' && v >= 0) ? v : 0;
}
function _ccmSaveMediaIndex(postId, idx){
    if(postId === null || postId === undefined) return;
    _ccmMediaIndexByPost[postId] = idx;
}

/* ────────────────────────────────────────────────────────────
   MEDIA NAV STEP LOCK
──────────────────────────────────────────────────────────── */
var _ccmMediaNavLock = false;

function _ccmMediaNavStep(delta){
    if(_ccmMediaNavLock) return;
    _ccmMediaNavLock = true;
    setTimeout(function(){ _ccmMediaNavLock = false; }, 200);

    var slide = document.getElementById('ccm-slide');
    if(!slide) return;
    var mediaBox = slide.querySelector('#ccm-media-box');
    if(!mediaBox) return;
    var count = parseInt(mediaBox.dataset.count || '1', 10);

    var next = _ccmMediaIndex + delta;
    if(next < 0 || next > count - 1) return;

    _ccmMediaIndex = next;
    _ccmSaveMediaIndex(_ccmMemeId, _ccmMediaIndex);
    ccmUpdateMediaGallery(slide);
}

function ccmMediaNext(){ _ccmMediaNavStep(1); }
function ccmMediaPrev(){ _ccmMediaNavStep(-1); }

function _ccmInitMediaSwipe(slide){
    var gallery  = slide.querySelector('#ccm-media-gallery');
    var mediaBox = slide.querySelector('#ccm-media-box');
    if(!gallery || !mediaBox) return;
    if(mediaBox._ccmSwipeBound) return;
    var mediaCount = parseInt(mediaBox.dataset.count || '1', 10);
    if(mediaCount <= 1) return;
    mediaBox._ccmSwipeBound = true;

    var startX = 0, startY = 0, dragging = false, horizontal = false;

    mediaBox.addEventListener('touchstart', function(e){
        startX = e.touches[0].clientX;
        startY = e.touches[0].clientY;
        dragging = true;
        horizontal = false;
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

/* ════════════════════════════════════════════════════════════
   RENDER — POST SLOT. UPDATED: the per-video inline progress bar
   was removed from inside the gallery items; one progress bar is
   now built once and appended as a sibling of .ccm-bottom (outside
   the transformed .ccm-media-gallery), escaping that stacking
   context entirely, matching home.php exactly.
════════════════════════════════════════════════════════════ */
function _ccmRenderPost(item, isPreview){
    var p = item.data;
    var isOwnPost = String(p.userId) === String(currentUserId);

    var media = (Array.isArray(p.media) && p.media.length) ? p.media.slice() : [{file:p.file, type:p.type}];
    var mediaCount = media.length;

    var mediaSet   = ['image','video','gif','sticker','stickers'];
    var firstType  = (media[0].type || '').toLowerCase();
    var isTextOnly = mediaCount === 1 && mediaSet.indexOf(firstType) === -1;

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
                    var autoplayAttr = (isActive && !isPreview && !isMemePaused) ? 'autoplay' : '';
                    var isAdjacent = !isPreview && mediaCount > 1 && Math.abs(i - initIdx) === 1;
                    var preloadAttr = (isActive && !isPreview) ? 'preload="auto"' :
                                       (isAdjacent ? 'preload="auto"' : 'preload="metadata"');
                    return '<div class="ccm-media-item" data-idx="'+i+'" data-type="video">'+
                        '<video src="'+m.file+'" '+autoplayAttr+' loop playsinline '+preloadAttr+' '+
                        'onloadedmetadata="ccmFixObjectFit(this);" '+
                        'onloadeddata="'+loadHook+'" '+
                        'ontimeupdate="ccmUpdateProgress(this)" class="ccm-media"></video>'+
                    '</div>';
                }
                return '<div class="ccm-media-item" data-idx="'+i+'" data-type="'+t+'">'+
                    '<img src="'+m.file+'" onerror="this.src=\'uploads/mee.jpeg\'" '+
                    'onload="ccmFixObjectFit(this);'+loadHook+'" class="ccm-media">'+
                '</div>';
            }).join('')+
        '</div>'+
        (mediaCount > 1
            ? '<div class="ccm-media-arrow ccm-media-arrow-left" onclick="event.stopPropagation();ccmMediaPrev()"><i class="ri-arrow-left-s-line"></i></div>'+
              '<div class="ccm-media-arrow ccm-media-arrow-right" onclick="event.stopPropagation();ccmMediaNext()"><i class="ri-arrow-right-s-line"></i></div>'
            : '');
    }

    var typeInfo    = _ccmTypeTagInfo(isTextOnly ? 'text' : ((media[initIdx] && media[initIdx].type) || firstType));
    var typeTagHtml = '<div class="ccm-type-tag" id="ccm-type-tag"><i class="'+typeInfo.icon+'"></i><span>'+typeInfo.label+'</span></div>';
    var counterHtml = mediaCount > 1
        ? '<div class="ccm-media-counter" id="ccm-media-counter">'+(initIdx+1)+'/'+mediaCount+'</div>'
        : '';

    var dashGap    = mediaCount > 1 ? _ccmComputeDashGap(mediaCount) : 6;
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
       takes the group tag's exact position. Matches home.php exactly. */
    var aiTagHtml = '';
    if(p.isAiGenerated){
        var aiTagClass = 'ccm-ai-tag' + (p.groupId ? ' ccm-ai-tag-under-group' : '');
        aiTagHtml = '<div class="'+aiTagClass+'"><i class="ri-sparkling-2-fill"></i>AI Generated</div>';
    }

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
        if(!isNaN(_pd)){
            isNewPost = (Date.now() - _pd.getTime()) <= (172800*1000);
        }
    }
    var displayName = isOwnPost ? 'You' : _ccmTruncateName(p.username);

    var nicknameHtml = (!isOwnPost && p.nickname)
        ? '<div class="ccm-nickname">@'+p.nickname+'</div>'
        : '';

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
    } else if(nicknameHtml){
        switcherHtml = nicknameHtml;
    } else if(audioTagHtml){
        switcherHtml = audioTagHtml;
    }

    /* SOCIAL PROOF — never renders for ad posts. The CTA pill
       takes this exact slot instead (see ctaPillHtml below). */
    var socialProofHtml = '';
    if(!p.isAd && Array.isArray(p.socialProof) && p.socialProof.length){
        var spClass = 'ccm-social-proof-strip' + (p.isPartnership ? ' ccm-sp-raised' : '');
        socialProofHtml = '<div class="'+spClass+'" id="ccm-social-proof">'+
            p.socialProof.slice(0,4).map(function(sp){
                var spPic   = sp.profile_pic || 'uploads/mee.jpeg';
                var isLike  = sp.action_type === 'like';
                var icon    = isLike ? 'ri-heart-3-fill' : 'ri-repeat-line';
                var color   = isLike ? '#f24455' : '#7a4aff';
                return '<a href="profile.php?user_id='+sp.id+'" onclick="event.stopPropagation()" class="ccm-sp-avatar-wrap">'+
                    '<img src="'+spPic+'" onerror="this.src=\'uploads/mee.jpeg\'" class="ccm-sp-avatar">'+
                    '<span class="ccm-sp-badge" style="background:'+color+';"><i class="'+icon+'"></i></span>'+
                '</a>';
            }).join('')+
        '</div>';
    }

    /* CTA PILL — occupies the social-proof strip's exact spot,
       only for ad posts with a URL to send people to. Small by
       default; _ccmInitCtaPillCycle() expands/contracts it every
       few seconds via the .ccm-cta-pill-expanded class. */
    var ctaPillHtml = '';
    if(p.isAd && p.adCtaUrl){
        var ctaClass = 'ccm-cta-pill' + (p.isPartnership ? ' ccm-sp-raised' : '');
        var ctaColor = p.adCtaColor || '#FF4C4C';
        var ctaLabel = p.adCtaText || 'Learn more';
        ctaPillHtml = '<a href="'+p.adCtaUrl+'" target="_blank" id="ccm-cta-pill" class="'+ctaClass+'" '+
        'style="--cta-color:'+ctaColor+';" onclick="event.stopPropagation()">'+
        '<span class="ccm-cta-pill-text">'+_ccmEscapeHtml(ctaLabel)+'</span>'+
        '<i class="ri-arrow-right-s-line ccm-cta-pill-chevron"></i>'+
        '</a>';
    }

    var bottomCaptionHtml = '';
    if(!isTextOnly){
        bottomCaptionHtml = '<div class="ccm-caption-wrap" id="ccm-caption-wrap">'+
              '<span class="ccm-caption-text" id="ccm-caption-text">'+localCaptionTruncHtml+'</span>'+
          '</div>';
    }

    /* Paid Partnership badge — sits under the caption, never mixed into
       the caption text itself. */
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
        railMsgOrAdHtml = '<button type="button" class="ccm-rail-btn ccm-rail-msg" onclick="event.stopPropagation();'+(p.audioId !== undefined && p.audioId !== null ? 'ccmOpenSoundsPage('+p.userId+','+p.audioId+')' : '')+'">'+
              '<img src="'+(p.pic||'uploads/mee.jpeg')+'" onerror="this.src=\'uploads/mee.jpeg\'" class="ccm-rail-msg-pic">'+
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
                '<img src="'+(p.pic||'uploads/mee.jpeg')+'" onerror="this.src=\'uploads/mee.jpeg\'" class="ccm-user-pic" onclick="location.href=\'profile.php?user_id='+p.userId+'\'">'+
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

/* ────────────────────────────────────────────────────────────
   RENDER — AD SLOT. UPDATED: same treatment — inline bar removed
   from the video tag, single sibling bar appended after
   .ccm-bottom-ad.
──────────────────────────────────────────────────────────── */
var _ccmLoggedImpressions = new Set();
var _ccmActiveViewTimer   = null;
var CCM_VIEW_DWELL_MS     = 1000;

function _ccmClearPendingImpression(){
    if(_ccmActiveViewTimer){
        clearTimeout(_ccmActiveViewTimer);
        _ccmActiveViewTimer = null;
    }
}

function _ccmLogAdImpression(adId){
    if(_ccmLoggedImpressions.has(String(adId))) return;
    _ccmLoggedImpressions.add(String(adId));
    fetch('search.php', {
        method:  'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body:    'action=log_ad_impression&sponsorship_id='+adId
    }).catch(function(){});
}

var _ccmShownForCapping = new Set();
function _ccmLogAdShown(adId){
    if(_ccmShownForCapping.has(String(adId))) return;
    _ccmShownForCapping.add(String(adId));
    fetch('search.php', {
        method:  'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body:    'action=log_ad_shown&sponsorship_id='+adId
    }).catch(function(){});
}

function _ccmRenderAd(item, isPreview){
    var ad  = item.data;
    var ext = (ad.meme_image||'').split('.').pop().toLowerCase();
    var isVideo = ['mp4','webm','mov'].indexOf(ext) !== -1;

    var loadHook = "ccmHideSkeleton(this.closest('.ccm-slide'))";
    var autoplayAttr = isPreview ? '' : 'autoplay';

    var mediaHtml = isVideo
        ? '<video src="uploads/'+(ad.meme_image||'')+'" '+autoplayAttr+' loop playsinline '+
          'onloadedmetadata="ccmFixObjectFit(this);'+loadHook+'" '+
          'ontimeupdate="ccmUpdateProgress(this)" class="ccm-media"></video>'
        : '<img src="uploads/'+(ad.meme_image||'')+'" onerror="this.src=\'uploads/mee.jpeg\'" onload="ccmFixObjectFit(this);'+loadHook+'" class="ccm-media">';

    var vbColors = ['ccm-visit-brand-red', 'ccm-visit-brand-blue', 'ccm-visit-brand-orange'];
    var vbClass  = vbColors[(item.adSlot||0) % vbColors.length];

    var adId        = ad.id;
    var likeCount   = ad.like_count  !== undefined ? ad.like_count  : 0;
    var favCount    = ad.fav_count   !== undefined ? ad.fav_count   : 0;
    var userLiked   = parseInt(ad.user_liked) > 0;
    var userFaved   = parseInt(ad.user_faved) > 0;
    var profileUrl  = ad.user_id ? 'profile.php?user_id='+ad.user_id : '#';
    var posterPic   = ad.poster_pic || 'uploads/mee.jpeg';
    var posterName  = ad.poster_username || '';

    var isOwnAd        = (ad.user_id !== undefined && ad.user_id !== null) && String(ad.user_id) === String(currentUserId);
    var adTrackRef     = ad.user_id ? document.querySelector('.track-btn[data-user="'+ad.user_id+'"]') : null;
    var isAdFollowing  = adTrackRef ? adTrackRef.classList.contains('following') : false;
    var trackBtnHtml   = (!isOwnAd && ad.user_id)
        ? '<button class="ccm-track-btn '+(isAdFollowing?'following':'')+'" data-user="'+ad.user_id+'" '+
              'onclick="event.stopPropagation();ccmToggleTrack(this)">'+(isAdFollowing?'Untrack':'Track')+'</button>'
        : '';

    var progressBarHtml = isVideo
        ? '<div class="ccm-video-progress" style="display:flex;">'+
              '<div class="ccm-video-progress-track">'+
                  '<div class="ccm-video-progress-fill"></div>'+
                  '<div class="ccm-video-progress-thumb"></div>'+
              '</div>'+
          '</div>'
        : '';

    return (
        _ccmSkeletonHtml()+

        '<div class="ccm-media-box" onclick="event.stopPropagation();ccmHandleMediaTap(event,this,false)">'+ mediaHtml +'</div>'+

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
                '<i class="ri-heart-3-fill"></i>'+
                '<span id="ccm-ad-like-count" class="ccm-rail-count">'+likeCount+'</span>'+
            '</button>'+
            '<button id="ccm-ad-fav-btn" class="ccm-rail-btn '+(userFaved?'active':'')+'" '+
                'onclick="event.stopPropagation();ccmAdFavAction('+adId+')">'+
                '<i class="ri-bookmark-fill"></i>'+
                '<span id="ccm-ad-fav-count" class="ccm-rail-count">'+favCount+'</span>'+
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
                    '<img src="'+posterPic+'" onerror="this.src=\'uploads/mee.jpeg\'" class="ccm-ad-from-avatar">'+
                    '<span class="ccm-ad-from-label">From</span>'+
                    '<span class="ccm-ad-from-name">'+posterName+'</span>'+
                '</a>'+
                '<a href="'+profileUrl+'" onclick="event.stopPropagation()" class="ccm-ad-from-tag ccm-switch-pane">'+
                    '<img src="'+posterPic+'" onerror="this.src=\'uploads/mee.jpeg\'" class="ccm-ad-from-avatar">'+
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
        '</div>'+

        progressBarHtml
    );
}
/* ────────────────────────────────────────────────────────────
   AD LIKE / FAVORITE ACTIONS
   UPDATED (fav): now shows the same "Saved · Collections" toast
   posts show, on both add and remove — same show/hide pattern as
   ccmAction's fav branch below.
──────────────────────────────────────────────────────────── */
function ccmAdLikeAction(sponsorshipId){
    var slideEl = document.getElementById('ccm-slide');
    var btn = slideEl ? slideEl.querySelector('#ccm-ad-like-btn') : null;
    var countEl = slideEl ? slideEl.querySelector('#ccm-ad-like-count') : null;

    fetch('search.php', {
        method:  'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body:    'action=toggle_ad_like&sponsorship_id='+sponsorshipId
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        if(data.count === undefined) return;
        var nowActive = btn ? !btn.classList.contains('active') : true;
        if(btn) btn.classList.toggle('active', nowActive);
        if(countEl) countEl.textContent = data.count;

        var feedBtn = document.querySelector('.ad-like-btn[data-ad-id="'+sponsorshipId+'"]');
        if(feedBtn){
            feedBtn.classList.toggle('active', nowActive);
            var feedCnt = feedBtn.querySelector('.ad-like-count');
            if(feedCnt) feedCnt.textContent = data.count;
        }
    })
    .catch(function(err){ console.error('ccmAdLikeAction failed:', err); });
}

function ccmAdFavAction(sponsorshipId){
    var slideEl = document.getElementById('ccm-slide');
    var btn = slideEl ? slideEl.querySelector('#ccm-ad-fav-btn') : null;
    var countEl = slideEl ? slideEl.querySelector('#ccm-ad-fav-count') : null;

    var wasActive = btn ? btn.classList.contains('active') : false;
    var willBeActive = !wasActive;

    fetch('search.php', {
        method:  'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body:    'action=toggle_ad_favorite&sponsorship_id='+sponsorshipId
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        if(data.count === undefined) return;
        var nowActive = btn ? !btn.classList.contains('active') : true;
        if(btn) btn.classList.toggle('active', nowActive);
        if(countEl) countEl.textContent = data.count;

        var feedBtn = document.querySelector('.ad-fav-btn[data-ad-id="'+sponsorshipId+'"]');
        if(feedBtn){
            feedBtn.classList.toggle('active', nowActive);
            var feedCnt = feedBtn.querySelector('.ad-fav-count');
            if(feedCnt) feedCnt.textContent = data.count;
        }

        // NEW: same toast behavior as posts — saving shows it, unsaving hides it
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
    fetch('search.php', {
        method:  'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body:    'action=ad_not_interested&sponsorship_id='+sponsorshipId
    })
    .then(function(r){ return r.json(); })
    .then(function(){
        showToast('Got it — you\'ll see less of this');
        var item = ccmFeed[_ccmIndex];
        if(item && item.type === 'ad' && String(item.data.id) === String(sponsorshipId)){
            ccmLoadIndex(_ccmIndex + 1);
        }
        document.querySelectorAll('.ad-card-mini[data-ad-id="'+sponsorshipId+'"]').forEach(function(card){
            card.style.transition = 'opacity 0.4s, transform 0.4s';
            card.style.opacity    = '0';
            card.style.transform  = 'scale(0.96)';
            setTimeout(function(){ card.remove(); }, 400);
        });
    })
    .catch(function(err){ console.error('ccmAdNotInterested failed:', err); });
}

function feedAdNotInterested(sponsorshipId, cardEl){
    fetch('search.php', {
        method:  'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body:    'action=ad_not_interested&sponsorship_id='+sponsorshipId
    }).then(function(){
        if(cardEl){
            cardEl.style.transition = 'opacity 0.4s, transform 0.4s';
            cardEl.style.opacity    = '0';
            cardEl.style.transform  = 'scale(0.96)';
            setTimeout(function(){ cardEl.remove(); }, 400);
        }
        showToast('Got it — you\'ll see less of this');
    }).catch(function(){});
}

/* ────────────────────────────────────────────────────────────
   FEED (INLINE MINI CARD) — LIKE / FAV HANDLERS
──────────────────────────────────────────────────────────── */
function handleAdLike(btn){
    var adId = btn.dataset.adId;
    var willBeActive = !btn.classList.contains('active');

    fetch('search.php', {
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

        var slideEl = document.getElementById('ccm-slide');
        var modalBtn = slideEl ? slideEl.querySelector('#ccm-ad-like-btn') : null;
        if(modalBtn && window.cmAds){
            var adInList = window.cmAds.find(function(a){ return String(a.id) === String(adId); });
            if(adInList){
                var modalCount = slideEl.querySelector('#ccm-ad-like-count');
                modalBtn.classList.toggle('active', willBeActive);
                if(modalCount) modalCount.textContent = data.count;
            }
        }
    })
    .catch(function(err){ console.error('handleAdLike failed:', err); });
}

function handleAdFav(btn){
    var adId = btn.dataset.adId;
    var willBeActive = !btn.classList.contains('active');

    fetch('search.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=toggle_ad_favorite&sponsorship_id=' + adId
    })
    .then(function(res){ return res.json(); })
    .then(function(data){
        if(data.count === undefined) return;
        btn.classList.toggle('active', willBeActive);
        var countEl = btn.querySelector('.ad-fav-count');
        if(countEl) countEl.textContent = data.count;

        var slideEl = document.getElementById('ccm-slide');
        var modalBtn = slideEl ? slideEl.querySelector('#ccm-ad-fav-btn') : null;
        if(modalBtn && window.cmAds){
            var adInList = window.cmAds.find(function(a){ return String(a.id) === String(adId); });
            if(adInList){
                var modalCount = slideEl.querySelector('#ccm-ad-fav-count');
                modalBtn.classList.toggle('active', willBeActive);
                if(modalCount) modalCount.textContent = data.count;
            }
        }

        // NEW: mirror the toast here too, since this handler is used
        // by the inline mini-card fav button on the feed (outside the
        // modal), not just inside it.
        if(willBeActive) ccmShowSavedToast(adId);
        else ccmHideSavedToast();
    })
    .catch(function(err){ console.error('handleAdFav failed:', err); });
}

/* ────────────────────────────────────────────────────────────
   FEED — LOG IMPRESSIONS WHEN A MINI AD CARD SCROLLS INTO VIEW
──────────────────────────────────────────────────────────── */
(function(){
    if(!('IntersectionObserver' in window)) return;
    var logged      = new Set();
    var pendingTimers = new Map();

    var observer = new IntersectionObserver(function(entries){
        entries.forEach(function(entry){
            var adId = entry.target.dataset.adId;
            if(!adId || logged.has(adId)) return;

            if(entry.isIntersecting){
                if(pendingTimers.has(adId)) clearTimeout(pendingTimers.get(adId));
                var t = setTimeout(function(){
                    if(logged.has(adId)) return;
                    logged.add(adId);
                    fetch('search.php', {
                        method: 'POST',
                        headers: {'Content-Type':'application/x-www-form-urlencoded'},
                        body: 'action=log_ad_impression&sponsorship_id=' + adId
                    }).catch(function(){});
                    observer.unobserve(entry.target);
                    pendingTimers.delete(adId);
                }, CCM_VIEW_DWELL_MS);
                pendingTimers.set(adId, t);
            } else {
                if(pendingTimers.has(adId)){
                    clearTimeout(pendingTimers.get(adId));
                    pendingTimers.delete(adId);
                }
            }
        });
    }, { threshold: 0.5 });

    document.addEventListener('DOMContentLoaded', function(){
        document.querySelectorAll('.ad-card-mini[data-ad-id]').forEach(function(card){
            observer.observe(card);
        });
    });
})();

/* ────────────────────────────────────────────────────────────
   RENDER — SUGGESTED USERS SLOT
──────────────────────────────────────────────────────────── */
function _ccmRenderSugg(item, isPreview){
    var users = (item.data || []).filter(Boolean);
    if(users.length === 0) return '';

    var pageCount = Math.max(1, Math.ceil(users.length / 4));
    function pageUsers(pg){ return users.slice(pg*4, pg*4+4); }

    var pagesHtml = '';
    for(var pg = 0; pg < pageCount; pg++){
        var slice = pageUsers(pg);
        var cardsHtml = slice.map(function(u){
            var following = parseInt(u.is_following) > 0;
            var metaHtml = '';
            if(u.mutual_count){
                metaHtml = '<span class="ccm-sugg-card-meta">'+u.mutual_count+' mutual</span>';
            } else if(u.reason){
                metaHtml = '<span class="ccm-sugg-card-meta">'+_ccmEscapeHtml(u.reason)+'</span>';
            }
            return '<div class="ccm-sugg-card" data-user="'+u.id+'">'+
                '<button type="button" class="ccm-sugg-close" onclick="event.stopPropagation();ccmSuggDismiss(this)"><i class="ri-close-line"></i></button>'+
                '<a class="ccm-sugg-card-link" href="profile.php?user_id='+u.id+'" onclick="event.stopPropagation()">'+
                    '<img src="'+(u.profile_pic||'uploads/mee.jpeg')+'" onerror="this.src=\'uploads/mee.jpeg\'">'+
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
            dotsHtml += '<span class="ccm-sugg-dot'+(d===0?' active':'')+'" onclick="event.stopPropagation();ccmSuggGoToPage('+d+')"></span>';
        }
    }

    var arrowsHtml = pageCount > 1
        ? '<div class="ccm-sugg-arrow ccm-sugg-arrow-left ccm-sugg-arrow-disabled" id="ccm-sugg-arrow-left" onclick="event.stopPropagation();ccmSuggPrev()"><i class="ri-arrow-left-s-line"></i></div>'+
          '<div class="ccm-sugg-arrow ccm-sugg-arrow-right" id="ccm-sugg-arrow-right" onclick="event.stopPropagation();ccmSuggNext()"><i class="ri-arrow-right-s-line"></i></div>'
        : '';

    function bgTiles(pg){
        var pu = pageUsers(pg);
        var tiles = [];
        for(var t=0; t<4; t++){
            var u = pu.length ? pu[t % pu.length] : null;
            var pic = u ? (u.profile_pic || 'uploads/mee.jpeg') : 'uploads/mee.jpeg';
            tiles.push('<div class="ccm-sugg-bg-tile" data-tile="'+t+'" style="background-image:url(\''+pic+'\')"></div>');
        }
        return tiles.join('');
    }

    return (
        '<div class="ccm-sugg-slide">'+
            '<div class="ccm-sugg-bg" id="ccm-sugg-bg">'+bgTiles(0)+'</div>'+
            '<div class="ccm-sugg-overlay"></div>'+
            '<div class="ccm-sugg-header">'+
                '<button class="ccm-icon-btn ccm-back-btn" onclick="closeDiscoverModal()"></button>'+
                '<div class="ccm-sugg-header-text">'+
                    '<div class="ccm-sugg-title">Suggested for you</div>'+
                    '<div class="ccm-sugg-subtitle">Accounts you might like</div>'+
                '</div>'+
                '<span class="ccm-sugg-header-spacer"></span>'+
            '</div>'+
            '<div class="ccm-sugg-pages" id="ccm-sugg-pages" data-page-count="'+pageCount+'" data-current="0">'+pagesHtml+'</div>'+
            arrowsHtml+
            (pageCount > 1 ? '<div class="ccm-sugg-dots" id="ccm-sugg-dots">'+dotsHtml+'</div>' : '')+
        '</div>'
    );
}

function _ccmSuggPageUsers(pg){
    var arr = _ccmActiveSuggData || [];
    return arr.slice(pg*4, pg*4+4);
}

function _ccmSuggUpdateBg(pg){
    var slideEl = document.getElementById('ccm-slide');
    var bgEl = slideEl ? slideEl.querySelector('#ccm-sugg-bg') : null;
    if(!bgEl) return;
    var pu = _ccmSuggPageUsers(pg);
    var tiles = bgEl.querySelectorAll('.ccm-sugg-bg-tile');
    tiles.forEach(function(tile, t){
        var u = pu.length ? pu[t % pu.length] : null;
        var pic = u ? (u.profile_pic || 'uploads/mee.jpeg') : 'uploads/mee.jpeg';
        tile.style.backgroundImage = "url('"+pic+"')";
    });
}

function ccmSuggGoToPage(idx){
    var slideEl = document.getElementById('ccm-slide');
    var pagesEl = slideEl ? slideEl.querySelector('#ccm-sugg-pages') : null;
    var dotsEl  = slideEl ? slideEl.querySelector('#ccm-sugg-dots') : null;
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

    _ccmSuggUpdateBg(idx);

    var leftArrow  = slideEl ? slideEl.querySelector('#ccm-sugg-arrow-left') : null;
    var rightArrow = slideEl ? slideEl.querySelector('#ccm-sugg-arrow-right') : null;
    if(leftArrow)  leftArrow.classList.toggle('ccm-sugg-arrow-disabled', idx === 0);
    if(rightArrow) rightArrow.classList.toggle('ccm-sugg-arrow-disabled', idx === pageCount - 1);
}

function ccmSuggNext(){
    var slideEl = document.getElementById('ccm-slide');
    var pagesEl = slideEl ? slideEl.querySelector('#ccm-sugg-pages') : null;
    if(!pagesEl) return;
    var current = parseInt(pagesEl.dataset.current || '0', 10);
    ccmSuggGoToPage(current + 1);
}

function ccmSuggPrev(){
    var slideEl = document.getElementById('ccm-slide');
    var pagesEl = slideEl ? slideEl.querySelector('#ccm-sugg-pages') : null;
    if(!pagesEl) return;
    var current = parseInt(pagesEl.dataset.current || '0', 10);
    ccmSuggGoToPage(current - 1);
}

function _ccmInitSuggSwipe(pagesEl){
    if(!pagesEl) return;
    if(pagesEl._ccmSuggSwipeBound) return;
    var pageCount = parseInt(pagesEl.dataset.pageCount || '1', 10);
    if(pageCount <= 1) return;
    pagesEl._ccmSuggSwipeBound = true;

    var startX = 0, startY = 0, dragging = false, horizontal = false;

    pagesEl.addEventListener('touchstart', function(e){
        startX = e.touches[0].clientX;
        startY = e.touches[0].clientY;
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
        ccmSuggGoToPage(current);
    }, {passive:true});
}

function ccmSuggDismiss(btn){
    var card = btn.closest('.ccm-sugg-card');
    if(card) card.classList.add('dismissed');
}

function toggleSuggTrack(btn){
    var userId = btn.dataset.user;
    fetch('search.php', {
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

/* ════════════════════════════════════════════════════════════
   GAPLESS VERTICAL TRACK ENGINE
   ════════════════════════════════════════════════════════════ */
function _ccmSlideItem(idx){
    if(idx < 0 || idx >= _ccmActiveFeed.length) return null;
    return _ccmActiveFeed[idx];
}

function _ccmBuildSlideWrap(item, pos){
    var wrap = document.createElement('div');
    wrap.className = 'ccm-vslide';
    wrap.dataset.pos = pos;
    if(item){
        var slideEl = document.createElement('div');
        slideEl.className = 'ccm-slide';
        if(pos === 'current') slideEl.id = 'ccm-slide';
        var isPreview = pos !== 'current';
        var html = '';
        if(item.type === 'post') html = _ccmRenderPost(item, isPreview);
        else if(item.type === 'ad') html = _ccmRenderAd(item, isPreview);
        else if(item.type === 'sugg') html = _ccmRenderSugg(item, isPreview);
        slideEl.innerHTML = html;
        wrap.appendChild(slideEl);
    }
    return wrap;
}

/* UPDATED: ad branch now sets _ccmCurrentAdId so double-tap knows
   which sponsorship_id to like. Post/sugg branches clear it. */
function _ccmActivateCurrentSlide(currentItem){
    if(_ccmInfoSwitchTimer){ clearInterval(_ccmInfoSwitchTimer); _ccmInfoSwitchTimer = null; }
    if(_ccmAdFromSwitchTimer){ clearInterval(_ccmAdFromSwitchTimer); _ccmAdFromSwitchTimer = null; }
    if(_ccmVbSwitchTimer){ clearInterval(_ccmVbSwitchTimer); _ccmVbSwitchTimer = null; }
    if(_ccmCtaPillTimer){ clearInterval(_ccmCtaPillTimer); _ccmCtaPillTimer = null; }   // NEW
    _ccmClearPendingImpression();
    ccmHideSavedToast();

    var curSlide = document.getElementById('ccm-slide');

    if(!currentItem){
        _ccmSyncSoundtrack(null);
        _ccmMemeId = null;
        _ccmPostUserId = null;
        _ccmActiveSuggData = null;
        _ccmCurrentAdId = null;
        return;
    }

    if(currentItem.type === 'post'){
        var p = currentItem.data;
        var capState = _ccmComputeCaptionHtml(p);
        _ccmCaptionFullHtml  = capState.full;
        _ccmCaptionTruncHtml = capState.trunc;
        _ccmCaptionExpanded  = false;

        var shouldStayPaused = !!_ccmPausedMemeIds[p.id];

        if(curSlide){
            _ccmInitMediaSwipe(curSlide);
            _ccmInitAudioMarquee(curSlide);
            _ccmInitInfoSwitcher(curSlide);
            _ccmInitCtaPillCycle(curSlide);   // NEW — starts/stops itself; safe no-op if p.isAd is false
            ccmUpdateMediaGallery(curSlide, shouldStayPaused);

            var mediaImg = curSlide.querySelector('img.ccm-media');
            var textBg   = curSlide.querySelector('.ccm-textonly-bg');
            var activeVid = curSlide.querySelector('.ccm-media-item[data-idx="'+_ccmMediaIndex+'"] video.ccm-media')
                          || curSlide.querySelector('video.ccm-media');

            if(textBg){
                ccmHideSkeleton(curSlide);
                requestAnimationFrame(function(){ _ccmCheckCaptionOverflow(curSlide); });
            } else if(mediaImg && mediaImg.complete){
                ccmHideSkeleton(curSlide);
            } else if(activeVid && activeVid.readyState >= 2){
                ccmHideSkeleton(curSlide);
            }
            setTimeout(function(){ ccmHideSkeleton(curSlide); }, 2500);
        }

        _ccmSyncSoundtrack(currentItem);

        _ccmMemeId = p.id;
        _ccmPostUserId = p.userId;
        _ccmActiveSuggData = null;
        _ccmCurrentAdId = null;

    } else if(currentItem.type === 'ad'){
        var ad = currentItem.data;

        _ccmSyncSoundtrack(currentItem); // stops any post track that was playing

        if(curSlide){
            _ccmInitAdFromSwitcher(curSlide);
            _ccmInitVbSwitcher(curSlide);

            var adImg = curSlide.querySelector('img.ccm-media');
            var adVid = curSlide.querySelector('video.ccm-media');
            if(adImg && adImg.complete) ccmHideSkeleton(curSlide);
            else if(adVid && adVid.readyState >= 2) ccmHideSkeleton(curSlide);
            setTimeout(function(){ ccmHideSkeleton(curSlide); }, 2500);

            if(adVid) adVid.play().catch(function(){});
        }

        _ccmLogAdShown(ad.id);
        _ccmActiveViewTimer = setTimeout(function(){
            _ccmLogAdImpression(ad.id);
            _ccmActiveViewTimer = null;
        }, CCM_VIEW_DWELL_MS);

        _ccmMemeId = null;
        _ccmPostUserId = null;
        _ccmActiveSuggData = null;
        _ccmCurrentAdId = ad.id;

    } else if(currentItem.type === 'sugg'){
        _ccmSyncSoundtrack(currentItem); // stops any post track that was playing

        _ccmActiveSuggData = currentItem.data;

        if(curSlide){
            var pagesEl = curSlide.querySelector('#ccm-sugg-pages');
            _ccmInitSuggSwipe(pagesEl);
        }

        _ccmMemeId = null;
        _ccmPostUserId = null;
        _ccmCurrentAdId = null;
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

var CCM_OVERLAY_SELECTOR = '.ccm-top-bar, .ccm-group-tag, .ccm-top-right-tags, .ccm-social-proof-strip, .ccm-rail, .ccm-bottom, .ccm-sugg-header';

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

function _ccmResetAllOverlayStyles(track){
    if(!track) return;
    track.querySelectorAll('.ccm-vslide .ccm-slide').forEach(function(slideEl){
        _ccmOverlayEls(slideEl).forEach(function(el){
            el.style.transition = '';
            el.style.opacity    = '';
            el.style.transform  = '';
        });
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
        dragging = true;
        committedVertical = false;
        decided = false;
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
                var atBottom = _ccmIndex >= _ccmActiveFeed.length - 1;
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
        if(dy < -threshold && _ccmIndex < _ccmActiveFeed.length - 1){
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
    _ccmMediaIndex = (promotedItem && promotedItem.type === 'post')
        ? _ccmGetSavedMediaIndex(promotedItem.data.id)
        : 0;
    _ccmActivateCurrentSlide(promotedItem);

    _ccmVSwipeLocked = false;
}

/* ────────────────────────────────────────────────────────────
   PUBLIC API
──────────────────────────────────────────────────────────── */
function openDiscoverModal(file, type, pic, userId, username, caption, source){
    if(!_ccmBuilt){ _buildCcmFeed(); }

    // NEW — grid-triggered opens always reset to the grid feed, so a
    // stale _ccmActiveFeed left over from a search-modal open doesn't
    // leak into normal grid navigation.
    source = source || 'grid';
    if(source === 'grid'){
        _ccmActiveFeed = ccmFeed;
    }

    var idx = _ccmActiveFeed.findIndex(function(s){
        return s.type === 'post' && s.data.file === file && String(s.data.userId) === String(userId);
    });
    _ccmIndex = idx >= 0 ? idx : 0;

    _ccmVSwipeLocked = false;

    _ccmMountTrack(_ccmIndex);
    _ccmInitVerticalDrag();

    document.getElementById('discoverModal').classList.add('ccm-open');
    bringModalToFront(document.getElementById('discoverModal'));
    document.body.style.overflow = 'hidden';
}

/* NEW — entrypoint used by .srm-post-card in the search modal.
   Unlike openDiscoverModal, this doesn't need to findIndex by
   file/userId — the caller already knows the exact position in
   srmSearchFeed, since that array is built in the same render
   order as the cards on screen. */
function openDiscoverModalFromSearch(index){
    if(!Array.isArray(window.srmSearchFeed) || !srmSearchFeed.length) return;

    _ccmActiveFeed = srmSearchFeed.map(function(p){
        return { type: 'post', data: p };
    });

    _ccmIndex = (index >= 0 && index < _ccmActiveFeed.length) ? index : 0;

    _ccmVSwipeLocked = false;

    _ccmMountTrack(_ccmIndex);
    _ccmInitVerticalDrag();

    document.getElementById('discoverModal').classList.add('ccm-open');
    bringModalToFront(document.getElementById('discoverModal'));
    document.body.style.overflow = 'hidden';
}

function ccmLoadIndex(index){
    if(_ccmVSwipeLocked) return;
    if(index < 0 || index >= _ccmActiveFeed.length || index === _ccmIndex) return;
    if(Math.abs(index - _ccmIndex) === 1){
        _ccmCommitTrackMove(index > _ccmIndex ? 1 : -1);
    } else {
        _ccmMountTrack(index);
    }
}

function closeDiscoverModal(){
    document.getElementById('ccm-inner').querySelectorAll('video').forEach(function(v){ v.pause(); v.src=''; });
    _ccmStopSoundtrack();
    if(_ccmInfoSwitchTimer){ clearInterval(_ccmInfoSwitchTimer); _ccmInfoSwitchTimer = null; }
    if(_ccmAdFromSwitchTimer){ clearInterval(_ccmAdFromSwitchTimer); _ccmAdFromSwitchTimer = null; }
    if(_ccmVbSwitchTimer){ clearInterval(_ccmVbSwitchTimer); _ccmVbSwitchTimer = null; }
    _ccmClearPendingImpression();
    ccmHideSavedToast();
    document.getElementById('discoverModal').classList.remove('ccm-open');
    document.body.style.overflow = '';
    document.body.style.touchAction = '';
    _ccmMemeId     = null;
    _ccmPostUserId = null;
    _ccmMediaIndex = 0;
    _ccmActiveSuggData = null;
    _ccmCurrentAdId = null;
    _ccmVSwipeLocked = false;
}

/* ────────────────────────────────────────────────────────────
   ACTION HANDLER — likes/reposts/favorites via search.php
   UPDATED (fav): shows/hides the "Saved · Collections" toast,
   same as the meme modal.
──────────────────────────────────────────────────────────── */
var _ccmActionLocks = {};

function ccmAction(type){
    if(!_ccmMemeId) return;
    var memeId  = _ccmMemeId;
    var lockKey = memeId + ':' + type;

    if(_ccmActionLocks[lockKey]) return;
    _ccmActionLocks[lockKey] = true;

    var actionMap   = { like:'toggle_like', repost:'toggle_repost', fav:'toggle_favorite' };
    var btnMap      = { like:'ccm-like-btn', repost:'ccm-repost-btn', fav:'ccm-fav-btn' };
    var cntMap      = { like:'ccm-like-count', repost:'ccm-repost-count', fav:'ccm-fav-count' };
    var cntField    = { like:'likeCount', repost:'repostCount', fav:'favCount' };
    var stateField  = { like:'liked', repost:'reposted', fav:'faved' };
    var feedSel     = { like:'.like-btn', repost:'.repost-btn', fav:'.fav-btn' };
    var feedCntCls  = { like:'.count', repost:'.count', fav:'.count' };

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

    // NEW: optimistic toast on saving to favorites (matches meme modal)
    if(type === 'fav'){
        if(optimisticNext) ccmShowSavedToast(memeId);
        else ccmHideSavedToast();
    }

    fetch('search.php', {
        method:  'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body:    'action='+actionMap[type]+'&meme_id='+memeId
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        delete _ccmActionLocks[lockKey];

        if(data.count === undefined){
            if(cmBtn)  cmBtn.classList.toggle('active', wasActive);
            if(cmSpan) cmSpan.textContent = prevCountText;
            if(type === 'fav' && optimisticNext) ccmHideSavedToast(); // rollback
            return;
        }

        var nowActive = optimisticNext; // search.php's toggle_* confirms the flip; count is authoritative
        if(cmBtn)  cmBtn.classList.toggle('active', nowActive);
        if(cmSpan) cmSpan.textContent = data.count;

        var feedBtn = document.querySelector(feedSel[type]+'[data-id="'+memeId+'"]');
        if(feedBtn){
            feedBtn.classList.toggle('active', nowActive);
            var feedCnt = feedBtn.querySelector(feedCntCls[type]);
            if(feedCnt) feedCnt.textContent = data.count;

            if(type === 'like'){
                var feedIcon = feedBtn.querySelector('i');
                if(feedIcon) feedIcon.style.color = nowActive ? '#ff2b55' : '';
            }
        }

        var feedItem = ccmFeed.find(function(s){
            return s.type === 'post' && String(s.data.id) === String(memeId);
        });
        if(feedItem){
            feedItem.data[cntField[type]]   = data.count;
            feedItem.data[stateField[type]] = nowActive;
        }

        // reconcile toast with real server state if it disagreed with our guess
        if(type === 'fav' && nowActive !== optimisticNext){
            if(nowActive) ccmShowSavedToast(memeId);
            else ccmHideSavedToast();
        }
    })
    .catch(function(err){
        delete _ccmActionLocks[lockKey];
        console.error('ccmAction failed:', err);
        if(cmBtn)  cmBtn.classList.toggle('active', wasActive);
        if(cmSpan) cmSpan.textContent = prevCountText;
        if(type === 'fav' && optimisticNext) ccmHideSavedToast();
    });
}


/* ────────────────────────────────────────────────────────────
   KEYBOARD NAVIGATION (desktop) — left/right arrow keys
   • on a suggested-users slide: page the 2x2 carousel
   • on a post slide with more than one media item: move through
     the multimedia gallery
   Scoped to the CURRENT slide only, and guarded so this listener
   can never be bound more than once.
──────────────────────────────────────────────────────────── */
if(!window._ccmKeydownBound){
    window._ccmKeydownBound = true;

    document.addEventListener('keydown', function(e){
        if(e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;

        var modal = document.getElementById('discoverModal');
        if(!modal || !modal.classList.contains('ccm-open')) return;

        var item = ccmFeed[_ccmIndex];
        if(!item) return;

        if(item.type === 'sugg'){
            e.preventDefault();
            if(e.key === 'ArrowLeft') ccmSuggPrev();
            else ccmSuggNext();
            return;
        }

        if(item.type === 'post'){
            var slideEl = document.getElementById('ccm-slide');
            var mediaBox = slideEl ? slideEl.querySelector('#ccm-media-box') : null;
            var mediaCount = mediaBox ? parseInt(mediaBox.dataset.count || '1', 10) : 1;
            if(mediaCount > 1){
                e.preventDefault();
                if(e.key === 'ArrowLeft') ccmMediaPrev();
                else ccmMediaNext();
            }
        }
    });
}

/* ────────────────────────────────────────────────────────────
   SHARE MODAL — external apps + track-list picker → shares
   table + chat message, driven by search.php's action handler
──────────────────────────────────────────────────────────── */
var _shareMemeId   = null;
var _shareAllUsers = [];
var _shareSelected = new Set();

function _shareGetLink(){
    return window.location.origin + '/search.php?meme_id=' + _shareMemeId;
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
    bringModalToFront(document.getElementById('shareModalOverlay'));
    bringModalToFront(document.getElementById('shareModalPanel'));
    document.body.style.overflow = 'hidden';

    fetch('search.php?type=following')
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
        var pic = u.profile_pic || 'uploads/mee.jpeg';
        var sel = _shareSelected.has(String(u.id));
        return '<div class="share-user-row'+(sel?' selected':'')+'" data-uid="'+u.id+'" '+
            'onclick="toggleShareUser('+u.id+', this)">'+
            '<img src="'+pic+'" onerror="this.src=\'uploads/mee.jpeg\'">'+
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

    fetch('search.php', {
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

/* Fallback toast in case search.php doesn't already define one
   elsewhere on the page (home.php defines its own showToast —
   if search.php already has one, this simply won't be redeclared
   since only one definition will exist in the final page). */
if(typeof showToast !== 'function'){
    function showToast(msg){
        var existing = document.getElementById('peura-toast');
        if(existing) existing.remove();
        var t = document.createElement('div');
        t.id = 'peura-toast';
        t.textContent = msg;
        Object.assign(t.style, {
            position:'fixed', bottom:'90px', left:'50%', transform:'translateX(-50%)',
            background:'rgba(20,20,25,.95)', border:'1px solid rgba(255,45,45,.3)',
            color:'#fff', padding:'8px 20px', borderRadius:'50px', fontSize:'12px',
            zIndex:'9999999', pointerEvents:'none', boxShadow:'0 8px 24px rgba(0,0,0,.6)',
            transition:'opacity .3s', whiteSpace:'nowrap'
        });
        document.body.appendChild(t);
        setTimeout(function(){ t.style.opacity='0'; setTimeout(function(){ t.remove(); }, 300); }, 2200);
    }
}

/* ── Init on page load ── */
window.addEventListener('load', function(){ _loadCcmData(); });

/* ── Scroll / swipe / keyboard navigation ── */
document.getElementById('discoverModal').addEventListener('wheel', function(e){
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
document.getElementById('discoverModal').addEventListener('touchstart', function(e){
    ccmTouchInCaption = _ccmIsInsideExpandedCaption(e.target);
    if(ccmTouchInCaption) return;
    ccmTouchStartY = e.touches[0].clientY;
}, { passive:true });

document.getElementById('discoverModal').addEventListener('touchend', function(e){
    if(ccmTouchInCaption){ ccmTouchInCaption = false; return; }
    var diff = ccmTouchStartY - e.changedTouches[0].clientY;
    if(Math.abs(diff) > 40){
        if(diff > 0) ccmLoadIndex(_ccmIndex+1);
        else         ccmLoadIndex(_ccmIndex-1);
    }
}, { passive:true });

document.addEventListener('keydown', function(e){
    if(document.getElementById('discoverModal').classList.contains('ccm-open')){
        if(e.key === 'Escape')    closeDiscoverModal();
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
    bringModalToFront(document.getElementById('svshOverlay'));
    bringModalToFront(document.getElementById('svshPanel'));
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

/* FAVORITE / REPOST / LIKE BUTTONS
   ── FIX: grid card clicks previously only flipped the clicked tile's own
   DOM. peuraFeed (which ccmFeed / the discover modal reads from) was
   never updated, and the modal's rail was never synced if it happened to
   be open on the same post — so likes/reposts/saves done from the grid
   only appeared in the modal after a full page refresh. This mirrors the
   same fix applied to home.php / group.php: after the request succeeds,
   also patch the matching peuraFeed entry and, if the discover modal is
   currently open on this exact post, its rail button + count live. ── */
function sendAction(action, memeId, cb){
    fetch("search.php", {
        method: "POST",
        headers: {"Content-Type":"application/x-www-form-urlencoded"},
        body: `action=${action}&meme_id=${memeId}`
    })
    .then(res => res.json())
    .then(cb);
}

var SEARCH_TOGGLE_MAP = {
    'toggle_like':     { countClass: '.count', arrField: 'liked',    cntField: 'likeCount',   modalBtn: 'ccm-like-btn',   modalCount: 'ccm-like-count'   },
    'toggle_repost':   { countClass: '.count', arrField: 'reposted', cntField: 'repostCount', modalBtn: 'ccm-repost-btn', modalCount: 'ccm-repost-count' },
    'toggle_favorite': { countClass: '.count', arrField: 'faved',    cntField: 'favCount',    modalBtn: 'ccm-fav-btn',    modalCount: 'ccm-fav-count'    }
};

function handleGridAction(action, btn){
    var map = SEARCH_TOGGLE_MAP[action];
    if(!map) return;

    var memeId = btn.dataset.id;

    sendAction(action, memeId, function(data){
        btn.classList.toggle('active');
        var isActive = btn.classList.contains('active');

        var countEl = btn.querySelector(map.countClass);
        if(data.count !== undefined && countEl) countEl.textContent = data.count;

        // like buttons also recolor their icon
        var icon = btn.querySelector('i');
        if(icon && action === 'toggle_like'){
            icon.style.color = isActive ? '#ff2b55' : '';
        }

        // keep peuraFeed (ccmFeed's data source) in sync
        if(typeof peuraFeed !== 'undefined'){
            var item = peuraFeed.find(function(p){ return String(p.id) === String(memeId); });
            if(item && data.count !== undefined){
                item[map.cntField] = data.count;
                item[map.arrField] = isActive;
            }
        }

        // if the discover modal is open on this exact post, update its rail live
        if(typeof _ccmMemeId !== 'undefined' && String(_ccmMemeId) === String(memeId)){
            var modalBtn   = document.getElementById(map.modalBtn);
            var modalCount = document.getElementById(map.modalCount);
            if(modalBtn) modalBtn.classList.toggle('active', isActive);
            if(modalCount && data.count !== undefined) modalCount.textContent = data.count;
        }
    });
}

/* ================= REPOST ================= */
document.querySelectorAll('.repost-btn').forEach(btn=>{
    btn.addEventListener('click', function(e){
        e.stopPropagation();
        handleGridAction('toggle_repost', this);
    });
});

/* ================= FAVORITE ================= */
document.querySelectorAll('.fav-btn').forEach(btn=>{
    btn.addEventListener('click', function(e){
        e.stopPropagation();
        handleGridAction('toggle_favorite', this);
    });
});

/* ================= LIKE ================= */
document.querySelectorAll('.like-btn').forEach(btn=>{
    btn.addEventListener('click', function(e){
        e.stopPropagation();
        handleGridAction('toggle_like', this);
    });
});
</script>
</body>
</html>