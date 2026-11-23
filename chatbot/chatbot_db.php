<?php
/**
 * chatbot_db.php
 * Live database endpoint for DezzBot
 * Called by ask.py / chatbot.php to fetch real-time stats and user info
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

include 'config.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ══════════════════════════════════════════
// PLATFORM STATS  (live, updates with DB)
// ══════════════════════════════════════════
if ($action === 'stats') {
    $user_count   = $conn->query("SELECT COUNT(*) as c FROM users")->fetch_assoc()['c'];
    $meme_count   = $conn->query("SELECT COUNT(*) as c FROM memes")->fetch_assoc()['c'];
    $group_count  = $conn->query("SELECT COUNT(*) as c FROM `groups`")->fetch_assoc()['c'];
    $pod_count    = $group_count; // groups = pods on Dezzme
    $follow_count = $conn->query("SELECT COUNT(*) as c FROM followers")->fetch_assoc()['c'];
    $like_count   = $conn->query("SELECT COUNT(*) as c FROM likes")->fetch_assoc()['c'];
    $repost_count = $conn->query("SELECT COUNT(*) as c FROM reposts")->fetch_assoc()['c'];
    $fav_count    = $conn->query("SELECT COUNT(*) as c FROM favorites")->fetch_assoc()['c'];
    $sound_count  = $conn->query("SELECT COUNT(*) as c FROM sounds")->fetch_assoc()['c'];
    $msg_count    = $conn->query("SELECT COUNT(*) as c FROM messages WHERE message != '[deleted]'")->fetch_assoc()['c'];
    $sponsor_count= $conn->query("SELECT COUNT(*) as c FROM sponsorships WHERE status='approved'")->fetch_assoc()['c'];
    $col_count    = $conn->query("SELECT COUNT(*) as c FROM collections")->fetch_assoc()['c'];
    $remix_count  = $conn->query("SELECT COUNT(*) as c FROM remix")->fetch_assoc()['c'];
    $vibe_count   = $conn->query("SELECT COUNT(*) as c FROM vibe_votes")->fetch_assoc()['c'];
    $text_memes   = $conn->query("SELECT COUNT(*) as c FROM memes WHERE type='text'")->fetch_assoc()['c'];
    $img_memes    = $conn->query("SELECT COUNT(*) as c FROM memes WHERE type='image'")->fetch_assoc()['c'];
    $vid_memes    = $conn->query("SELECT COUNT(*) as c FROM memes WHERE type='video'")->fetch_assoc()['c'];

    // Most liked meme
    $top_meme = $conn->query("
        SELECT m.caption, m.type, u.username,
               COUNT(l.id) as lc
        FROM memes m
        JOIN likes l ON l.meme_id = m.id
        JOIN users u ON u.id = m.uploaded_by
        GROUP BY m.id
        ORDER BY lc DESC
        LIMIT 1
    ")->fetch_assoc();

    // Most tracked user
    $top_user = $conn->query("
        SELECT u.username, COUNT(f.id) as fc
        FROM followers f
        JOIN users u ON u.id = f.following_id
        GROUP BY f.following_id
        ORDER BY fc DESC
        LIMIT 1
    ")->fetch_assoc();

    // Most active group
    $top_group = $conn->query("
        SELECT g.title, COUNT(gm.id) as mc
        FROM groups g
        JOIN group_members gm ON gm.group_id = g.id
        GROUP BY g.id
        ORDER BY mc DESC
        LIMIT 1
    ")->fetch_assoc();

    // Newest user
    $newest_user = $conn->query("SELECT username, created_at FROM users ORDER BY created_at DESC LIMIT 1")->fetch_assoc();

    echo json_encode([
        'status'       => 'ok',
        'user_count'   => (int)$user_count,
        'meme_count'   => (int)$meme_count,
        'group_count'  => (int)$group_count,
        'pod_count'    => (int)$pod_count,
        'follow_count' => (int)$follow_count,
        'like_count'   => (int)$like_count,
        'repost_count' => (int)$repost_count,
        'fav_count'    => (int)$fav_count,
        'sound_count'  => (int)$sound_count,
        'msg_count'    => (int)$msg_count,
        'sponsor_count'=> (int)$sponsor_count,
        'col_count'    => (int)$col_count,
        'remix_count'  => (int)$remix_count,
        'vibe_count'   => (int)$vibe_count,
        'text_memes'   => (int)$text_memes,
        'img_memes'    => (int)$img_memes,
        'vid_memes'    => (int)$vid_memes,
        'top_meme'     => $top_meme,
        'top_user'     => $top_user,
        'top_group'    => $top_group,
        'newest_user'  => $newest_user,
    ]);
    exit;
}

// ══════════════════════════════════════════
// USER LOOKUP  ?action=user&q=username
// ══════════════════════════════════════════
if ($action === 'user') {
    $q    = trim($_GET['q'] ?? '');
    $safe = $conn->real_escape_string($q);

    if (empty($safe)) {
        echo json_encode(['status' => 'error', 'message' => 'No username provided']);
        exit;
    }

    $user = $conn->query("
        SELECT u.id, u.username, u.profile_pic, u.created_at, u.user_rank, u.nickname, u.last_active,
               s.Bio, s.pronouns, s.activity_vibe, s.user_type, s.is_fully_private
        FROM users u
        LEFT JOIN user_settings s ON s.user_id = u.id
        WHERE u.username LIKE '%$safe%'
        LIMIT 1
    ")->fetch_assoc();

    if (!$user) {
        echo json_encode(['status' => 'not_found']);
        exit;
    }

    $uid = (int)$user['id'];

    $meme_c    = $conn->query("SELECT COUNT(*) as c FROM memes WHERE uploaded_by=$uid")->fetch_assoc()['c'];
    $repost_c  = $conn->query("SELECT COUNT(*) as c FROM reposts WHERE user_id=$uid")->fetch_assoc()['c'];
    $tracker_c = $conn->query("SELECT COUNT(*) as c FROM followers WHERE following_id=$uid")->fetch_assoc()['c'];
    $tracking_c= $conn->query("SELECT COUNT(*) as c FROM followers WHERE follower_id=$uid")->fetch_assoc()['c'];
    $like_c    = $conn->query("SELECT COUNT(*) as c FROM likes WHERE user_id=$uid")->fetch_assoc()['c'];
    $fav_c     = $conn->query("SELECT COUNT(*) as c FROM favorites WHERE user_id=$uid")->fetch_assoc()['c'];
    $vibe      = $conn->query("SELECT vibe, COUNT(*) as vc FROM vibe_votes WHERE profile_id=$uid GROUP BY vibe ORDER BY vc DESC LIMIT 1")->fetch_assoc();
    $pods      = $conn->query("SELECT COUNT(*) as c FROM group_members WHERE user_id=$uid")->fetch_assoc()['c'];

    echo json_encode([
        'status'     => 'ok',
        'id'         => $uid,
        'username'   => $user['username'],
        'nickname'   => $user['nickname'],
        'bio'        => $user['Bio'],
        'pronouns'   => $user['pronouns'],
        'user_type'  => $user['user_type'],
        'rank'       => $user['user_rank'],
        'activity'   => $user['activity_vibe'],
        'private'    => (bool)$user['is_fully_private'],
        'joined'     => $user['created_at'],
        'last_active'=> $user['last_active'],
        'memes'      => (int)$meme_c,
        'reposts'    => (int)$repost_c,
        'trackers'   => (int)$tracker_c,
        'tracking'   => (int)$tracking_c,
        'likes_given'=> (int)$like_c,
        'favs'       => (int)$fav_c,
        'vibe'       => $vibe ? $vibe['vibe'] : null,
        'pods'       => (int)$pods,
        'profile_url'=> 'profile.php?user_id=' . $uid,
    ]);
    exit;
}

// ══════════════════════════════════════════
// GROUPS / PODS LIST
// ══════════════════════════════════════════
if ($action === 'groups') {
    $groups = [];
    $res = $conn->query("
        SELECT g.id, g.title, g.description, g.is_adult, g.created_at,
               u.username as creator,
               COUNT(gm.id) as member_count
        FROM groups g
        JOIN users u ON u.id = g.created_by
        LEFT JOIN group_members gm ON gm.group_id = g.id
        GROUP BY g.id
        ORDER BY member_count DESC
    ");
    while ($row = $res->fetch_assoc()) {
        $groups[] = $row;
    }
    echo json_encode(['status' => 'ok', 'groups' => $groups, 'total' => count($groups)]);
    exit;
}

// ══════════════════════════════════════════
// TOP MEMES (most liked)
// ══════════════════════════════════════════
if ($action === 'top_memes') {
    $memes = [];
    $res = $conn->query("
        SELECT m.id, m.caption, m.type, u.username,
               COUNT(l.id) as like_count
        FROM memes m
        JOIN users u ON u.id = m.uploaded_by
        LEFT JOIN likes l ON l.meme_id = m.id
        GROUP BY m.id
        ORDER BY like_count DESC
        LIMIT 5
    ");
    while ($row = $res->fetch_assoc()) {
        $memes[] = $row;
    }
    echo json_encode(['status' => 'ok', 'memes' => $memes]);
    exit;
}

// ══════════════════════════════════════════
// TRENDING TAGS from captions
// ══════════════════════════════════════════
if ($action === 'tags') {
    $tags = [];
    $res = $conn->query("SELECT caption FROM memes WHERE caption REGEXP '#[a-zA-Z0-9_]+'");
    $tag_counts = [];
    while ($row = $res->fetch_assoc()) {
        preg_match_all('/#([a-zA-Z0-9_]+)/', strtolower($row['caption']), $matches);
        foreach ($matches[1] as $tag) {
            $tag_counts[$tag] = ($tag_counts[$tag] ?? 0) + 1;
        }
    }
    arsort($tag_counts);
    $top = array_slice($tag_counts, 0, 10, true);
    foreach ($top as $t => $c) {
        $tags[] = ['tag' => '#' . $t, 'count' => $c];
    }
    echo json_encode(['status' => 'ok', 'tags' => $tags]);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Unknown action']);