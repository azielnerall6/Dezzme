<?php
session_start();
include 'config.php';
require_once 'mute_restrict_api.php';

// FIX: opening a Collection folder crashed with "something went wrong"
// because get_col_item_states() calls get_poster_meta()/get_meme_counts()/
// get_meme_media_items()/get_meme_audio_info()/get_meme_group_info(), and
// those functions' `global $COUNT_CACHE` (etc.) pointed at variables that
// hadn't been assigned yet — the code that actually sets
// $COUNT_CACHE = [] / $PRIMED_MEME_IDS = [...] doesn't run until much later
// in the file, but the col_action AJAX handlers exit() long before reaching
// it. With the global still null, array_key_exists($id, $COUNT_CACHE) threw
// a fatal PHP 8 TypeError, which broke the JSON response entirely.
// Declaring these as empty arrays up front means every AJAX-only code path
// (including col_action) has valid caches to work with, and each helper's
// self-healing lazy-fill logic just queries fresh since nothing is primed.
$NICK_CACHE = [];
$FOLLOW_CACHE = [];
$SOCIAL_CACHE = [];
$MEDIA_CACHE = [];
$GROUP_CACHE = [];
$AUDIO_CACHE = [];
$COUNT_CACHE = [];
$INTERACTION_CACHE = [];
$REPOST_TS_CACHE = [];
$FAV_TS_CACHE = [];
$LIKE_TS_CACHE = [];
$PRIMED_MEME_IDS = [];
$PRIMED_UPLOADER_IDS = [];
if(!empty($_SESSION['user_id'])){
    $uid = (int)$_SESSION['user_id'];
    $conn->query("UPDATE users SET last_active = NOW() WHERE id = $uid");
}

if(isset($_GET['username']) && !empty($_GET['username'])){
    $uname = $conn->real_escape_string($_GET['username']);
    $res = $conn->query("SELECT id FROM users WHERE username='$uname' LIMIT 1");
    if($res && $res->num_rows > 0){
        $uid = $res->fetch_assoc()['id'];
        header("Location: profile.php?user_id=$uid");
        exit();
    } else {
        header("Location: index.php");
        exit();
    }
}
$current_user = $_SESSION['user_id'] ?? 0;
// Fetch logged-in user's own profile pic for nav
$nav_profile_pic = 'uploads/mee.jpeg';
if ($current_user) {
    $nav_res = $conn->query("SELECT profile_pic FROM users WHERE id = $current_user LIMIT 1");
    if ($nav_res && $nav_row = $nav_res->fetch_assoc()) {
        $nav_profile_pic = !empty($nav_row['profile_pic']) ? $nav_row['profile_pic'] : 'uploads/mee.jpeg';
    }
}


/* ── CCM DATA (ads + suggested users + following list for profile modal) ── */
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

    } elseif ($type === 'following') {
        /* Powers the Share Modal's "share with people you track" list.
           Without this branch, profile.php?type=following fell into the
           else{} below and always returned [], which is why the share
           modal on profile.php said "you aren't tracking anyone" even
           when you were. */
        $following_list = [];
        if ($current_user) {
            $stmt = $conn->prepare("
                SELECT u.id, u.username, u.profile_pic
                FROM followers f
                JOIN users u ON u.id = f.following_id
                WHERE f.follower_id = ?
                ORDER BY u.username ASC
            ");
            $stmt->bind_param("i", $current_user);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) { $following_list[] = $row; }
            $stmt->close();
        }
        echo json_encode($following_list);

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
        // Unfollow
        $stmt = $conn->prepare("DELETE FROM followers WHERE follower_id=? AND following_id=?");
        $stmt->bind_param("ii", $current_user, $target_id);
        $stmt->execute();

        // Delete track_request so private wall shows again correctly
        $stmt2 = $conn->prepare("DELETE FROM track_requests WHERE requester_id=? AND target_id=?");
        $stmt2->bind_param("ii", $current_user, $target_id);
        $stmt2->execute();

        echo json_encode(['status'=>'ok','following'=>false]);
    } else {
        // Follow
        $stmt = $conn->prepare("INSERT INTO followers (follower_id, following_id) VALUES (?,?)");
        $stmt->bind_param("ii", $current_user, $target_id);
        $stmt->execute();
        echo json_encode(['status'=>'ok','following'=>true]);
    }
    exit;
}

/* ── rest of your profile.php continues below ── */

// ─── ACCOUNT SWITCHER INIT ───────────────────────────────
if ($current_user) {
    $_SESSION['accounts'] = [$current_user];
    $linked = $conn->query("SELECT linked_user_id FROM linked_accounts WHERE primary_user_id = $current_user");
    while ($row = $linked->fetch_assoc()) {
        $_SESSION['accounts'][] = (int)$row['linked_user_id'];
    }
    $_SESSION['accounts'] = array_unique($_SESSION['accounts']);
}

// 2. Identify whose profile we are viewing
$profile_id = isset($_GET['user_id']) && !empty($_GET['user_id']) ? intval($_GET['user_id']) : $current_user;

// Kick to login if no ID exists
if (!$profile_id && !$current_user) {
    header("Location: login.php");
    exit();
}

// Only log views if someone other than yourself is viewing
if ($current_user != $profile_id) {

    // Check if viewed within last 30 minutes
    $stmt = $conn->prepare("
        SELECT id FROM profile_views 
        WHERE viewer_id = ? 
        AND profile_id = ? 
        AND viewed_at > (NOW() - INTERVAL 60 MINUTE)
        LIMIT 1
    ");
    $stmt->bind_param("ii", $current_user, $profile_id);
    $stmt->execute();
    $result = $stmt->get_result();

    // If no recent view → insert new one
    if ($result->num_rows === 0) {
        $stmt = $conn->prepare("
            INSERT INTO profile_views (viewer_id, profile_id, viewed_at)
            VALUES (?, ?, NOW())
        ");
        $stmt->bind_param("ii", $current_user, $profile_id);
        $stmt->execute();
    }
}

$is_blocked_by_owner = false;
if ($current_user && $current_user != $profile_id) {
    $block_check = $conn->prepare("SELECT id FROM blocks WHERE blocker_id = ? AND blocked_id = ?");
    $block_check->bind_param("ii", $profile_id, $current_user);
    $block_check->execute();
    $block_check->store_result();
    $is_blocked_by_owner = $block_check->num_rows > 0;
    $block_check->close();
}

$is_muted_by_me   = false;
$is_restricted_by_me = false;
 
if ($current_user && $current_user != $profile_id) {
    $s_mu = $conn->prepare("SELECT id FROM muted_users WHERE muter_id=? AND muted_id=?");
    $s_mu->bind_param("ii", $current_user, $profile_id);
    $s_mu->execute();
    $is_muted_by_me = $s_mu->get_result()->num_rows > 0;
 
    $s_re = $conn->prepare("SELECT id FROM restricted_users WHERE restrictor_id=? AND restricted_id=?");
    $s_re->bind_param("ii", $current_user, $profile_id);
    $s_re->execute();
    $is_restricted_by_me = $s_re->get_result()->num_rows > 0;
}

// 3. Handle Block / Unblock actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- Block User ---
  if (isset($_POST['block'])) {
    $stmt = $conn->prepare("INSERT INTO blocks (blocker_id, blocked_id) VALUES (?, ?)");
    $stmt->bind_param("ii", $current_user, $profile_id);
    $stmt->execute();

    // Remove from BOTH tables to be safe
    $stmt2 = $conn->prepare("DELETE FROM followers WHERE follower_id = ? AND following_id = ?");
    $stmt2->bind_param("ii", $current_user, $profile_id);
    $stmt2->execute();

    $stmt3 = $conn->prepare("DELETE FROM follows WHERE follower_id = ? AND following_id = ?");
    $stmt3->bind_param("ii", $current_user, $profile_id);
    $stmt3->execute();

    // Also clear any track requests
    $stmt4 = $conn->prepare("DELETE FROM track_requests WHERE requester_id = ? AND target_id = ?");
    $stmt4->bind_param("ii", $current_user, $profile_id);
    $stmt4->execute();

    header("Location: profile.php?user_id=" . $profile_id);
    exit();
}

    // --- Unblock User ---
    if (isset($_POST['unblock'])) {
        $stmt = $conn->prepare("DELETE FROM blocks WHERE blocker_id = ? AND blocked_id = ?");
        $stmt->bind_param("ii", $current_user, $profile_id);
        $stmt->execute();
        header("Location: profile.php?user_id=" . $profile_id);
        exit();
    }

}


// Check for new/unseen profile views
$new_views = $conn->query("
    SELECT COUNT(*) as cnt 
    FROM profile_views 
    WHERE profile_id = $current_user AND seen = 0
")->fetch_assoc()['cnt'];


// 4. Fetch memes while excluding users you have blocked
$sql = "SELECT * FROM memes 
        WHERE uploaded_by = ? 
        AND uploaded_by NOT IN (SELECT blocked_id FROM blocks WHERE blocker_id = ?)
        ORDER BY created_at DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $profile_id, $current_user);
$stmt->execute();
$memes = $stmt->get_result();

// 5. Check if current user has blocked this profile
$stmt_block = $conn->prepare("SELECT * FROM blocks WHERE blocker_id = ? AND blocked_id = ?");
$stmt_block->bind_param("ii", $current_user, $profile_id);
$stmt_block->execute();
$result_block = $stmt_block->get_result();
$is_blocked = $result_block->num_rows > 0;

// 3. Fetch the Profile Header Info
$stmt = $conn->prepare("
    SELECT 
        u.username, 
        u.nickname,
        u.profile_pic, 
        s.bio, 
        s.pronouns, 
        s.activity_vibe, 
        s.user_type
    FROM users u
    LEFT JOIN user_settings s ON s.user_id = u.id
    WHERE u.id = ?
");
$stmt->bind_param("i", $profile_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$display_name = $user['nickname'] ?: $user['username'];
$activity_vibe = $user['activity_vibe'] ?? '';

// 4. Followers/Following Counts
$follow_check = false;
$followers_count = 0;
$following_count = 0;

if ($current_user) {
    // Check if logged-in user follows this profile
    $stmt = $conn->prepare("SELECT id FROM followers WHERE follower_id=? AND following_id=?");
    $stmt->bind_param("ii", $current_user, $profile_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) $follow_check = true;
    $stmt->close();

    // Counts
    $res = $conn->query("SELECT COUNT(*) as cnt FROM followers WHERE following_id=$profile_id");
    $followers_count = $res->fetch_assoc()['cnt'] ?? 0;

    $res = $conn->query("SELECT COUNT(*) as cnt FROM followers WHERE follower_id=$profile_id");
    $following_count = $res->fetch_assoc()['cnt'] ?? 0;
}

// Can current user access this profile's features?
$is_owner = ($current_user == $profile_id);
$can_view = $is_owner || $follow_check;

// Assuming $profile_id is the profile being viewed
$settings = $conn->query("SELECT * FROM user_settings WHERE user_id = $profile_id")->fetch_assoc();
$other_accounts = $settings['other_accounts'] ?? '';

// ── Default values to prevent undefined variable warnings ──
$profile_is_private   = !empty($settings['is_fully_private']);
$track_request_status = null;

// 5. THE QUERIES (Fixed to use $profile_id)

// MEMES (Things this user uploaded)
$memes = $conn->query("
    SELECT m.*, u.username, u.profile_pic AS uploader_pic,
        ma.cta_text  AS ad_cta_text,
        ma.cta_url   AS ad_cta_url,
        ma.cta_color AS ad_cta_color
    FROM memes m
    JOIN users u ON m.uploaded_by = u.id
    LEFT JOIN meme_ads ma ON ma.meme_id = m.id
    WHERE m.uploaded_by = $profile_id
    AND (
        m.is_adult = 0
        OR $current_user = $profile_id
        OR EXISTS (
            SELECT 1 FROM group_posts gp
            JOIN group_members gm ON gm.group_id = gp.group_id
            WHERE gp.meme_id = m.id AND gm.user_id = $current_user
        )
    )
    AND (m.is_ad = 0 OR ma.status = 'approved' OR $current_user = $profile_id)
    ORDER BY m.created_at DESC
");

// REPOSTS (Things this user shared)
// NOTE: added m.connected_meme_id, m.connected_label — this query hand-picks
// columns (unlike the others below, which use m.* and get these for free),
// so Zeem Connect data was silently missing from the Reposts tab.
$reposts = $conn->query("
    SELECT m.id, m.uploaded_by, m.file_name, m.caption, m.type, 
           m.created_at, m.is_partnership, m.partner_brand,
           m.is_ad, m.is_ai_generated,
           m.connected_meme_id, m.connected_label,
           ma.cta_text  AS ad_cta_text,
           ma.cta_url   AS ad_cta_url,
           ma.cta_color AS ad_cta_color,
           u.username as creator, u.profile_pic as creator_pic
    FROM reposts r
    JOIN memes m ON r.meme_id = m.id
    JOIN users u ON m.uploaded_by = u.id
    LEFT JOIN meme_ads ma ON ma.meme_id = m.id
    WHERE r.user_id = $profile_id
    AND (
        m.is_adult = 0
        OR $current_user = $profile_id
        OR EXISTS (
            SELECT 1 FROM group_posts gp
            JOIN group_members gm ON gm.group_id = gp.group_id
            WHERE gp.meme_id = m.id AND gm.user_id = $current_user
        )
    )
    AND (m.is_ad = 0 OR ma.status = 'approved')
    ORDER BY r.id DESC
");

// Get tagged posts (memes where @username appears in caption)
$profile_username = $user['username'];

$tagged_posts = $conn->query("
    SELECT m.*, u.username, u.profile_pic as uploader_pic,
        ma.cta_text  AS ad_cta_text,
        ma.cta_url   AS ad_cta_url,
        ma.cta_color AS ad_cta_color
    FROM memes m
    JOIN users u ON m.uploaded_by = u.id 
    LEFT JOIN meme_ads ma ON ma.meme_id = m.id
    WHERE m.caption LIKE '%@$profile_username%'
    AND m.uploaded_by != $profile_id
    AND (
        m.is_adult = 0
        OR $current_user = $profile_id
        OR EXISTS (
            SELECT 1 FROM group_posts gp
            JOIN group_members gm ON gm.group_id = gp.group_id
            WHERE gp.meme_id = m.id AND gm.user_id = $current_user
        )
    )
    AND (m.is_ad = 0 OR ma.status = 'approved')
    ORDER BY m.created_at DESC
");


$favorites = $conn->query("
    SELECT m.*, u.username, u.profile_pic as uploader_pic,
        ma.cta_text  AS ad_cta_text,
        ma.cta_url   AS ad_cta_url,
        ma.cta_color AS ad_cta_color
    FROM favorites f
    JOIN memes m ON f.meme_id = m.id 
    JOIN users u ON m.uploaded_by = u.id 
    LEFT JOIN meme_ads ma ON ma.meme_id = m.id
    WHERE f.user_id = $profile_id
    AND (
        m.is_adult = 0
        OR $current_user = $profile_id
        OR EXISTS (
            SELECT 1 FROM group_posts gp
            JOIN group_members gm ON gm.group_id = gp.group_id
            WHERE gp.meme_id = m.id AND gm.user_id = $current_user
        )
    )
    AND (m.is_ad = 0 OR ma.status = 'approved')
    ORDER BY f.created_at DESC
");

$liked_posts = $conn->query("
    SELECT m.*, u.username, u.profile_pic as uploader_pic,
        ma.cta_text  AS ad_cta_text,
        ma.cta_url   AS ad_cta_url,
        ma.cta_color AS ad_cta_color
    FROM likes l
    JOIN memes m ON l.meme_id = m.id 
    JOIN users u ON m.uploaded_by = u.id 
    LEFT JOIN meme_ads ma ON ma.meme_id = m.id
    WHERE l.user_id = $profile_id
    AND (
        m.is_adult = 0
        OR $current_user = $profile_id
        OR EXISTS (
            SELECT 1 FROM group_posts gp
            JOIN group_members gm ON gm.group_id = gp.group_id
            WHERE gp.meme_id = m.id AND gm.user_id = $current_user
        )
    )
    AND (m.is_ad = 0 OR ma.status = 'approved')
    ORDER BY l.created_at DESC
");

// MESSAGES (Only if looking at own profile)
$messages = [];
if($current_user == $profile_id){
    $messages = $conn->query("
    SELECT 
        u.id,
        u.username,
        u.profile_pic,

        m.message AS last_message,
        m.file_attachment AS last_attachment,
        m.created_at AS last_time,
        m.sender_id,
        m.seen,

        (
            SELECT COUNT(*) 
            FROM messages 
            WHERE sender_id = u.id 
            AND receiver_id = $current_user 
            AND seen = 0
        ) AS unread_count

    FROM (
        SELECT 
            CASE 
                WHEN sender_id = $current_user 
                THEN receiver_id 
                ELSE sender_id 
            END AS other_user_id,

            MAX(id) AS last_msg_id

        FROM messages
        WHERE sender_id = $current_user 
           OR receiver_id = $current_user

        GROUP BY other_user_id

    ) AS convos

    JOIN messages m 
        ON m.id = convos.last_msg_id

    JOIN users u 
        ON u.id = convos.other_user_id

    ORDER BY m.created_at DESC
");
}

// MY UPLOADS (Only if looking at own profile)
$my_uploads = [];
if($current_user == $profile_id){
    $my_uploads = $conn->query("
        SELECT m.*, u.username, u.profile_pic as uploader_pic,
            ma.cta_text  AS ad_cta_text,
            ma.cta_url   AS ad_cta_url,
            ma.cta_color AS ad_cta_color
        FROM memes m
        JOIN users u ON m.uploaded_by=u.id
        LEFT JOIN meme_ads ma ON ma.meme_id = m.id
        WHERE m.uploaded_by=$current_user
        ORDER BY m.created_at DESC
    ");
}

// 6. Action Handlers (Profile Update / Follow / Unfollow)
$profile_msg = "";
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Update Profile Pic
    if(isset($_POST['update_profile']) && $current_user == $profile_id && isset($_FILES['profile_pic'])){
        $file = $_FILES['profile_pic'];
        $filename = time().'_'.basename($file['name']);
        $target = "uploads/".$filename;
        if(move_uploaded_file($file['tmp_name'], $target)){
            $stmt2 = $conn->prepare("UPDATE users SET profile_pic=? WHERE id=?");
            $stmt2->bind_param("si", $target, $current_user);
            $stmt2->execute();
            $user['profile_pic'] = $target;
            $profile_msg = "Profile picture updated!";
        }
    }
    
    // Follow/Unfollow
if (isset($_POST['follow'])) {
    $conn->query("INSERT IGNORE INTO followers (follower_id,following_id) VALUES ($current_user,$profile_id)");

    // Remove from unfollow log since they followed back
    $conn->query("DELETE FROM unfollow_log WHERE unfollowed_by=$current_user AND user_id=$profile_id");

    header("Location: profile.php?user_id=$profile_id"); exit();
}

if (isset($_POST['unfollow'])) {
    // Remove from followers
    $conn->query("DELETE FROM followers WHERE follower_id=$current_user AND following_id=$profile_id");

    // Log the unfollow so the unfollowed person can see it on untracked.php
    $conn->query("INSERT INTO unfollow_log (unfollowed_by, user_id) VALUES ($current_user, $profile_id)");

    // Delete track_request row so private profile wall shows again correctly
    $conn->query("DELETE FROM track_requests WHERE requester_id=$current_user AND target_id=$profile_id");

    header("Location: profile.php?user_id=$profile_id"); exit();
}


    // Delete Meme
if(isset($_POST['delete_meme'])){
    $mid = intval($_POST['meme_id']);
    // Only delete if current user uploaded it
    $conn->query("DELETE FROM memes WHERE id=$mid AND uploaded_by=$current_user");
    
    // Redirect to same page (so it updates immediately and avoids double submit issues)
    header("Location: profile.php?user_id=$profile_id#meme-grid"); 
    exit();
}
}

// 7. ESSENTIAL FUNCTIONS (Restored to fix your error)
function get_likes($conn, $meme_id) {
    $res = $conn->query("SELECT COUNT(*) as cnt FROM likes WHERE meme_id=$meme_id");
    $row = $res->fetch_assoc();
    return $row['cnt'] ?? 0;
}

function user_liked($conn, $meme_id, $user_id) {
    if(!$user_id) return false;
    $res = $conn->query("SELECT id FROM likes WHERE meme_id=$meme_id AND user_id=$user_id");
    return ($res && $res->num_rows > 0);
}



// --- Like Toggle (AJAX) ---
if (isset($_POST['action']) && $_POST['action'] === 'like' && $current_user) {
    header('Content-Type: application/json');
    $meme_id = (int)$_POST['meme_id'];
    $check = $conn->query("SELECT id FROM likes WHERE meme_id=$meme_id AND user_id=$current_user");
    if ($check->num_rows > 0) {
        $conn->query("DELETE FROM likes WHERE meme_id=$meme_id AND user_id=$current_user");
        $liked = false;
    } else {
        $conn->query("INSERT INTO likes (meme_id, user_id) VALUES ($meme_id, $current_user)");
        $liked = true;
    }
    $count = $conn->query("SELECT COUNT(*) as c FROM likes WHERE meme_id=$meme_id")->fetch_assoc()['c'];
    echo json_encode(['ok' => true, 'liked' => $liked, 'count' => (int)$count]);
    exit();
}

// --- Repost Toggle (AJAX) ---
if (isset($_POST['action']) && $_POST['action'] === 'repost' && $current_user) {
    header('Content-Type: application/json');
    $meme_id = (int)$_POST['meme_id'];
    $check = $conn->query("SELECT id FROM reposts WHERE meme_id=$meme_id AND user_id=$current_user");
    if ($check->num_rows > 0) {
        $conn->query("DELETE FROM reposts WHERE meme_id=$meme_id AND user_id=$current_user");
        $reposted = false;
    } else {
        $conn->query("INSERT INTO reposts (meme_id, user_id) VALUES ($meme_id, $current_user)");
        $reposted = true;
    }
    $count = $conn->query("SELECT COUNT(*) as c FROM reposts WHERE meme_id=$meme_id")->fetch_assoc()['c'];
    echo json_encode(['ok' => true, 'reposted' => $reposted, 'count' => (int)$count]);
    exit();
}

// --- Favorite Toggle (AJAX) ---
if (isset($_POST['action']) && $_POST['action'] === 'fav' && $current_user) {
    header('Content-Type: application/json');
    $meme_id = (int)$_POST['meme_id'];
    $check = $conn->query("SELECT id FROM favorites WHERE meme_id=$meme_id AND user_id=$current_user");
    if ($check->num_rows > 0) {
        $conn->query("DELETE FROM favorites WHERE meme_id=$meme_id AND user_id=$current_user");
        $faved = false;
    } else {
        $conn->query("INSERT INTO favorites (meme_id, user_id, created_at) VALUES ($meme_id, $current_user, NOW())");
        $faved = true;
    }
    $count = $conn->query("SELECT COUNT(*) as c FROM favorites WHERE meme_id=$meme_id")->fetch_assoc()['c'];
    echo json_encode(['ok' => true, 'faved' => $faved, 'count' => (int)$count]);
    exit();
}

// --- Share Post (AJAX) ---
// Powers the Share Modal's "Send" button. Inserts one row per selected
// recipient into `shares` (so counts everywhere — feed cards, reel
// modal, immersive post viewer — actually populate) and drops a
// "[meme:ID]" message into that recipient's chat with the sharer,
// mirroring exactly what home.php's share_post handler does.
if (isset($_POST['action']) && $_POST['action'] === 'share_post' && $current_user) {
    header('Content-Type: application/json');

    $meme_id = (int)($_POST['meme_id'] ?? 0);
    $receiver_ids_raw = $_POST['receiver_ids'] ?? '';
    $receiver_ids = array_values(array_unique(array_filter(
        array_map('intval', explode(',', $receiver_ids_raw)),
        function($v) use ($current_user){ return $v > 0 && $v !== $current_user; }
    )));

    if (!$meme_id || empty($receiver_ids)) {
        echo json_encode(["status" => "error", "message" => "invalid request"]);
        exit;
    }

    $meme_check = $conn->query("SELECT id FROM memes WHERE id=$meme_id LIMIT 1");
    if (!$meme_check || $meme_check->num_rows === 0) {
        echo json_encode(["status" => "error", "message" => "meme not found"]);
        exit;
    }

    $sent_to    = [];
    $stmt_share = $conn->prepare("INSERT INTO shares (user_id, meme_id, share_type) VALUES (?, ?, 'message')");
    $stmt_msg   = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message, seen) VALUES (?, ?, ?, 0)");

    foreach ($receiver_ids as $rid) {
        $stmt_share->bind_param("ii", $current_user, $meme_id);
        $stmt_share->execute();

        $msg_text = "[meme:$meme_id]";
        $stmt_msg->bind_param("iis", $current_user, $rid, $msg_text);
        $stmt_msg->execute();

        $sent_to[] = $rid;
    }
    $stmt_share->close();
    $stmt_msg->close();

    $count = $conn->query("SELECT COUNT(*) as c FROM shares WHERE meme_id=$meme_id")->fetch_assoc()['c'];

    echo json_encode([
        "status"  => "ok",
        "count"   => (int)$count,
        "sent_to" => $sent_to
    ]);
    exit;
}

// --- Full Likers / Reposters List (AJAX) ---
// Powers the Immersive Post Viewer's "Liked by ..." bottom sheet.
// NOTE: this is intentionally separate from get_poster_meta()'s
// `socialProof` — that one is a mutuals-only, likes-first, max-4-total
// list built for the small floating avatar strip on the media, not a
// full list. This endpoint returns EVERY liker and EVERY reposter for
// the meme so the sheet's Likes/Reposts tabs are actually complete.
if (isset($_POST['action']) && $_POST['action'] === 'get_meme_social') {
    header('Content-Type: application/json');
    $meme_id = (int)($_POST['meme_id'] ?? 0);
    if (!$meme_id) {
        echo json_encode(['ok' => false]);
        exit();
    }

    $likes = [];
    $lres = $conn->query("
        SELECT u.id, u.username, u.profile_pic
        FROM likes l
        JOIN users u ON u.id = l.user_id
        WHERE l.meme_id = $meme_id
        ORDER BY l.created_at DESC
    ");
    if ($lres) {
        while ($row = $lres->fetch_assoc()) { $likes[] = $row; }
    }

    $reposts = [];
    $rres = $conn->query("
        SELECT u.id, u.username, u.profile_pic
        FROM reposts r
        JOIN users u ON u.id = r.user_id
        WHERE r.meme_id = $meme_id
        ORDER BY r.id DESC
    ");
    if ($rres) {
        while ($row = $rres->fetch_assoc()) { $reposts[] = $row; }
    }

    echo json_encode(['ok' => true, 'likes' => $likes, 'reposts' => $reposts]);
    exit();
}

// --- Get Connected Zeem (AJAX) ---
// Powers the "▶ Watch · Connected Zeem" pill in the reel modal.
if (isset($_POST['action']) && $_POST['action'] === 'get_connected_meme') {
    header('Content-Type: application/json');
    $meme_id = (int)($_POST['meme_id'] ?? 0);
    if (!$meme_id) { echo json_encode(['ok'=>false]); exit; }

    $res = $conn->query("
        SELECT m.*, u.username, u.profile_pic AS uploader_pic,
            ma.cta_text  AS ad_cta_text,
            ma.cta_url   AS ad_cta_url,
            ma.cta_color AS ad_cta_color
        FROM memes m
        JOIN users u ON m.uploaded_by = u.id
        LEFT JOIN meme_ads ma ON ma.meme_id = m.id
        WHERE m.id = $meme_id
        AND (
            m.is_adult = 0
            OR $current_user = m.uploaded_by
            OR EXISTS (
                SELECT 1 FROM group_posts gp
                JOIN group_members gm ON gm.group_id = gp.group_id
                WHERE gp.meme_id = m.id AND gm.user_id = $current_user
            )
        )
        AND (m.is_ad = 0 OR ma.status = 'approved' OR $current_user = m.uploaded_by)
    ");
    $row = $res ? $res->fetch_assoc() : null;
    if (!$row) { echo json_encode(['ok'=>false]); exit; }

    // Blocked either direction? Hide it.
    if ($current_user) {
        $blk = $conn->query("
            SELECT id FROM blocks
            WHERE (blocker_id=$current_user AND blocked_id={$row['uploaded_by']})
               OR (blocker_id={$row['uploaded_by']} AND blocked_id=$current_user)
        ");
        if ($blk && $blk->num_rows > 0) { echo json_encode(['ok'=>false]); exit; }
    }

    $mid = (int)$row['id'];
    $uploaded_by = (int)$row['uploaded_by'];
    $_meta = get_poster_meta($conn, $mid, $uploaded_by, $current_user);

    $_norm_type = ($row['type'] === 'text' || empty($row['file_name']) || $row['file_name'] === '0') ? 'text' : $row['type'];
    $_main_file = ($_norm_type === 'text') ? 'uploads/Text.jpg' : 'uploads/' . $row['file_name'];
    $_media = get_meme_media_items($conn, $mid, $_main_file, $_norm_type);

    $_group = get_meme_group_info($conn, $mid);
    $_audio = get_meme_audio_info($conn, $mid);
    $_counts = get_meme_counts($mid);
    $_inter = get_meme_interactions($mid, $current_user);

    echo json_encode(['ok'=>true, 'post'=>[
        'id'               => $mid,
        'file'             => $_main_file,
        'type'             => $row['type'],
        'pic'              => $row['uploader_pic'] ?: 'uploads/default.jpg',
        'userId'           => $uploaded_by,
        'username'         => $row['username'],
        'caption'          => $row['caption'],
        'time'             => $row['created_at'],
        'isYou'            => ($current_user == $uploaded_by),
        'likeCount'        => (int)$_counts['like'],
        'repostCount'      => (int)$_counts['repost'],
        'favCount'         => (int)$_counts['fav'],
        'shareCount'       => (int)$_counts['share'],
        'liked'            => $_inter['liked'],
        'reposted'         => $_inter['reposted'],
        'faved'            => $_inter['faved'],
        'nickname'         => $_meta['nickname'],
        'isFollowing'      => $_meta['is_following'],
        'socialProof'      => $_meta['social_proof'],
        'media'            => $_media,
        'context'          => '',
        'groupId'          => $_group ? (int)$_group['group_id'] : null,
        'groupTitle'       => $_group ? $_group['group_name'] : null,
        'audioId'          => $_audio ? (int)$_audio['audio_id'] : null,
        'audioIsOriginal'  => $_audio ? (bool)($_audio['is_original'] ?? false) : false,
        'audioFile'        => $_audio ? 'uploads/'.$_audio['file_name'] : null,
        'audioTitle'       => $_audio ? $_audio['title'] : null,
        'audioArtist'      => ($_audio && !empty($_audio['artist'])) ? $_audio['artist'] : null,
        'musicVolume'      => ($_audio && $_audio['music_volume'] !== '' && $_audio['music_volume'] !== null) ? (int)$_audio['music_volume'] : 80,
        'videoAudioVolume' => ($_audio && $_audio['video_audio_volume'] !== '' && $_audio['video_audio_volume'] !== null) ? (int)$_audio['video_audio_volume'] : 40,
        'isPartnership'    => !empty($row['is_partnership']),
        'partnerBrand'     => $row['partner_brand'] ?? null,
        'isAd'             => !empty($row['is_ad']),
        'adCtaText'        => $row['ad_cta_text']  ?? null,
        'adCtaUrl'         => $row['ad_cta_url']   ?? null,
        'adCtaColor'       => $row['ad_cta_color'] ?? null,
        'isAiGenerated'    => !empty($row['is_ai_generated']),
        'connectedMemeId'  => !empty($row['connected_meme_id']) ? (int)$row['connected_meme_id'] : null,
        'connectedLabel'   => $row['connected_label'] ?? null,
    ]]);
    exit;
}

// --- Profile Pic Update via Plus-Modal (AJAX, no separate upload file needed) ---
if (isset($_POST['action']) && $_POST['action'] === 'update_profile_pic' && $current_user) {
    header('Content-Type: application/json');

    if (empty($_FILES['profile_pic_file']) || $_FILES['profile_pic_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'msg' => 'Upload error']);
        exit;
    }

    $file     = $_FILES['profile_pic_file'];
    $maxBytes = 5 * 1024 * 1024; // 5 MB

    if ($file['size'] > $maxBytes) {
        echo json_encode(['success' => false, 'msg' => 'File too large (max 5 MB)']);
        exit;
    }

    $finfo    = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    if (!isset($allowedMimes[$mimeType])) {
        echo json_encode(['success' => false, 'msg' => 'Only JPG, PNG, GIF and WebP are allowed']);
        exit;
    }
    $ext = $allowedMimes[$mimeType];

    $uploadDir = 'uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $pic_filename = 'profile_' . $current_user . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $pic_destPath = $uploadDir . $pic_filename;

    if (!move_uploaded_file($file['tmp_name'], $pic_destPath)) {
        echo json_encode(['success' => false, 'msg' => 'Could not save file — check server permissions']);
        exit;
    }

    // Delete old profile pic (cleanup), skip defaults
    $old_pic_q = $conn->prepare("SELECT profile_pic FROM users WHERE id = ? LIMIT 1");
    $old_pic_q->bind_param("i", $current_user);
    $old_pic_q->execute();
    $old_pic_row = $old_pic_q->get_result()->fetch_assoc();
    $old_pic_q->close();

    if (!empty($old_pic_row['profile_pic'])) {
        $oldPicPath = ltrim($old_pic_row['profile_pic'], '/');
        if (strpos($oldPicPath, 'uploads/') === 0
            && strpos($oldPicPath, 'mee.jpeg') === false
            && strpos($oldPicPath, 'default') === false
            && file_exists($oldPicPath)
        ) {
            @unlink($oldPicPath);
        }
    }

    $upd_pic = $conn->prepare("UPDATE users SET profile_pic = ? WHERE id = ?");
    $upd_pic->bind_param("si", $pic_destPath, $current_user);
    $upd_pic->execute();
    $upd_pic->close();

    echo json_encode(['success' => true, 'url' => $pic_destPath]);
    exit;
}

// Count user memes
$post_count = $memes->num_rows ?? 0;
?>
<?php
/* ───────────────── PROFILE STATUS ───────────────── */

$profilePic = $user['profile_pic'] ?? '';

if (empty($profilePic)) {
    $profilePic = 'uploads/mee.jpeg';
}

$pid = (int)$profile_id;

/* LIVE */
$live_chk = $conn->query("
    SELECT id
    FROM memes
    WHERE uploaded_by = $pid
    AND created_at >= NOW() - INTERVAL 2 MINUTE
    LIMIT 1
");

$is_live = ($live_chk && $live_chk->num_rows > 0);

/* ACTIVITY */
$is_online = false;
$is_recently_active = false;

$activity = $conn->query("
    SELECT last_active
    FROM users
    WHERE id = $pid
    LIMIT 1
");

if($activity && $activity->num_rows > 0){

    $activity_data = $activity->fetch_assoc();

    $last_active = strtotime($activity_data['last_active']);

    if($last_active){

        $diff = time() - $last_active;

        /* ONLINE */
        if($diff <= 300){

            $is_online = true;

        }

        /* RECENTLY ACTIVE */
        elseif($diff <= 3600){

            $is_recently_active = true;

        }

    }
}

// ── COLLECTIONS AJAX — place at TOP of profile.php before any HTML output ──
if(isset($_POST['col_action']) && $current_user) {
    header('Content-Type: application/json');
    $col_action = trim($_POST['col_action'] ?? '');

    if($col_action === 'create_collection') {
        $name = trim($conn->real_escape_string($_POST['name'] ?? ''));
        $is_public = (int)($_POST['is_public'] ?? 0); // 0=private, 1=public
        if(empty($name)) { echo json_encode(['status'=>'error','message'=>'Name required']); exit; }
        $conn->query("INSERT INTO collections (user_id, name, is_public, created_at) VALUES ($current_user, '$name', $is_public, NOW())");
        $new_id = $conn->insert_id;
        $rows = [];
        $res = $conn->query("SELECT c.id, c.name, c.is_public, c.created_at, (SELECT COUNT(*) FROM collection_items ci WHERE ci.collection_id=c.id) as item_count FROM collections c WHERE c.user_id=$current_user ORDER BY c.created_at DESC");
        while($r=$res->fetch_assoc()) $rows[]=$r;
        echo json_encode(['status'=>'ok','id'=>$new_id,'name'=>$name,'is_public'=>$is_public,'collections'=>$rows]);
        exit;
    }

    if($col_action === 'delete_collection') {
        $col_id = (int)($_POST['col_id'] ?? 0);
        if(!$col_id){ echo json_encode(['status'=>'error']); exit; }
        $conn->query("DELETE FROM collections WHERE id=$col_id AND user_id=$current_user");
        $conn->query("DELETE FROM collection_items WHERE collection_id=$col_id");
        $conn->query("DELETE FROM pinned_collections WHERE collection_id=$col_id AND user_id=$current_user");
        echo json_encode(['status'=>'ok']);
        exit;
    }

    if($col_action === 'set_visibility') {
        $col_id    = (int)($_POST['col_id'] ?? 0);
        $is_public = (int)($_POST['is_public'] ?? 0);
        if(!$col_id){ echo json_encode(['status'=>'error']); exit; }
        $conn->query("UPDATE collections SET is_public=$is_public WHERE id=$col_id AND user_id=$current_user");
        echo json_encode(['status'=>'ok','is_public'=>$is_public]);
        exit;
    }

    if($col_action === 'add_to_collection') {
        $col_id  = (int)($_POST['col_id']  ?? 0);
        $meme_id = (int)($_POST['meme_id'] ?? 0);
        if(!$col_id || !$meme_id){ echo json_encode(['status'=>'error']); exit; }
        $own = $conn->query("SELECT id FROM collections WHERE id=$col_id AND user_id=$current_user");
        if(!$own->num_rows){ echo json_encode(['status'=>'error','message'=>'Not your collection']); exit; }
        $check = $conn->query("SELECT id FROM collection_items WHERE collection_id=$col_id AND meme_id=$meme_id");
        if($check->num_rows){ echo json_encode(['status'=>'already']); exit; }
        $conn->query("INSERT INTO collection_items (collection_id, meme_id, added_at) VALUES ($col_id, $meme_id, NOW())");
        $count = $conn->query("SELECT COUNT(*) as c FROM collection_items WHERE collection_id=$col_id")->fetch_assoc()['c'];
        echo json_encode(['status'=>'ok','item_count'=>(int)$count]);
        exit;
    }

    if($col_action === 'remove_from_collection') {
        $col_id  = (int)($_POST['col_id']  ?? 0);
        $meme_id = (int)($_POST['meme_id'] ?? 0);
        if(!$col_id || !$meme_id){ echo json_encode(['status'=>'error']); exit; }
        $conn->query("DELETE FROM collection_items WHERE collection_id=$col_id AND meme_id=$meme_id");
        $count = $conn->query("SELECT COUNT(*) as c FROM collection_items WHERE collection_id=$col_id")->fetch_assoc()['c'];
        echo json_encode(['status'=>'ok','item_count'=>(int)$count]);
        exit;
    }

    if($col_action === 'get_collections') {
        $rows = [];
        $res = $conn->query("SELECT c.id, c.name, c.is_public, c.created_at, (SELECT COUNT(*) FROM collection_items ci WHERE ci.collection_id=c.id) as item_count FROM collections c WHERE c.user_id=$current_user ORDER BY c.created_at DESC");
        while($r=$res->fetch_assoc()) $rows[]=$r;
        echo json_encode(['status'=>'ok','collections'=>$rows]);
        exit;
    }

    if($col_action === 'get_public_collections') {
        // For viewing another user's public collections
        $owner_id = (int)($_POST['owner_id'] ?? 0);
        if(!$owner_id){ echo json_encode(['status'=>'error','collections'=>[]]); exit; }
        $rows = [];
        $res = $conn->query("SELECT c.id, c.name, c.is_public, c.created_at, (SELECT COUNT(*) FROM collection_items ci WHERE ci.collection_id=c.id) as item_count FROM collections c WHERE c.user_id=$owner_id AND c.is_public=1 ORDER BY c.created_at DESC");
        while($r=$res->fetch_assoc()) $rows[]=$r;
        echo json_encode(['status'=>'ok','collections'=>$rows]);
        exit;
    }
if($col_action === 'get_collection_items') {
        $col_id = (int)($_POST['col_id'] ?? 0);
        if(!$col_id){ echo json_encode(['status'=>'error','items'=>[]]); exit; }
        // Owner OR public collection
        $owner_check = $conn->query("SELECT id, user_id, is_public FROM collections WHERE id=$col_id");
        if(!$owner_check->num_rows){ echo json_encode(['status'=>'error','items'=>[]]); exit; }
        $col_row = $owner_check->fetch_assoc();
        if($col_row['user_id'] != $current_user && !$col_row['is_public']){
            echo json_encode(['status'=>'error','items'=>[]]); exit;
        }
        $rows = [];
        $res = $conn->query("
            SELECT m.id, m.caption, m.type, m.file_name, m.uploaded_by, m.created_at,
                   u.username, u.profile_pic AS uploader_pic
            FROM collection_items ci
            JOIN memes m ON m.id = ci.meme_id
            JOIN users u ON u.id = m.uploaded_by
            WHERE ci.collection_id = $col_id
            ORDER BY ci.added_at DESC
        ");
        while($r=$res->fetch_assoc()) $rows[]=$r;
        echo json_encode(['status'=>'ok','items'=>$rows]);
        exit;
    }

    if($col_action === 'rename_collection') {
        $col_id = (int)($_POST['col_id'] ?? 0);
        $name   = trim($conn->real_escape_string($_POST['name'] ?? ''));
        if(!$col_id || empty($name)){ echo json_encode(['status'=>'error']); exit; }
        $conn->query("UPDATE collections SET name='$name' WHERE id=$col_id AND user_id=$current_user");
        echo json_encode(['status'=>'ok']);
        exit;
    }

    if($col_action === 'get_previews') {
        $col_id = (int)($_POST['col_id'] ?? 0);
        if(!$col_id){ echo json_encode(['status'=>'ok','items'=>[]]); exit; }
        // Allow owner or public
        $col_check = $conn->query("SELECT user_id, is_public FROM collections WHERE id=$col_id");
        if($col_check->num_rows){
            $cc = $col_check->fetch_assoc();
            if($cc['user_id'] != $current_user && !$cc['is_public']){
                echo json_encode(['status'=>'ok','items'=>[]]); exit;
            }
        }
        $rows = [];
        $res = $conn->query("SELECT m.file_name, m.type FROM collection_items ci JOIN memes m ON m.id=ci.meme_id WHERE ci.collection_id=$col_id ORDER BY ci.added_at DESC LIMIT 4");
        while($r=$res->fetch_assoc()) $rows[]=$r;
        echo json_encode(['status'=>'ok','items'=>$rows]);
        exit;
    }

if($col_action === 'get_col_item_states') {
    $col_id = (int)($_POST['col_id'] ?? 0);
    if(!$col_id){ echo json_encode(['status'=>'error','states'=>[]]); exit; }
    // Allow owner or public
    $col_check = $conn->query("SELECT user_id, is_public FROM collections WHERE id=$col_id");
    if($col_check->num_rows){
        $cc = $col_check->fetch_assoc();
        if($cc['user_id'] != $current_user && !$cc['is_public']){
            echo json_encode(['status'=>'error','states'=>[]]); exit;
        }
    } else {
        echo json_encode(['status'=>'error','states'=>[]]); exit;
    }
    $states = [];
    // FIX: also select file_name/type so we can build the full multi-media array
    // FIX: also select is_ad/is_ai_generated + join meme_ads for CTA fields,
    // so ad posts and AI-generated posts opened from inside a Collection
    // show the same CTA pill / AD rail badge / AI tag they do everywhere else.
    // FIX: also select connected_meme_id/connected_label so Zeem Connect's
    // "▶ View · Connected Zeem" overlay button shows up on posts opened
    // from inside a Collection too, same as everywhere else.
    $res = $conn->query("
    SELECT ci.meme_id, m.uploaded_by, m.file_name, m.type, m.is_partnership, m.partner_brand,
           m.is_ad, m.is_ai_generated,
           m.connected_meme_id, m.connected_label,
           ma.cta_text  AS ad_cta_text,
           ma.cta_url   AS ad_cta_url,
           ma.cta_color AS ad_cta_color
    FROM collection_items ci
    JOIN memes m ON m.id = ci.meme_id
    LEFT JOIN meme_ads ma ON ma.meme_id = m.id
    WHERE ci.collection_id=$col_id
");

    while($r = $res->fetch_assoc()) {
        $mid = (int)$r['meme_id'];
        $uploaded_by = (int)$r['uploaded_by'];
        $lc  = $conn->query("SELECT COUNT(*) as c FROM likes     WHERE meme_id=$mid")->fetch_assoc()['c'];
        $rc  = $conn->query("SELECT COUNT(*) as c FROM reposts   WHERE meme_id=$mid")->fetch_assoc()['c'];
        $fc  = $conn->query("SELECT COUNT(*) as c FROM favorites WHERE meme_id=$mid")->fetch_assoc()['c'];
        $li  = $current_user ? $conn->query("SELECT id FROM likes     WHERE meme_id=$mid AND user_id=$current_user")->num_rows > 0 : false;
        $ri  = $current_user ? $conn->query("SELECT id FROM reposts   WHERE meme_id=$mid AND user_id=$current_user")->num_rows > 0 : false;
        $fi  = $current_user ? $conn->query("SELECT id FROM favorites WHERE meme_id=$mid AND user_id=$current_user")->num_rows > 0 : false;

        $_meta = get_poster_meta($conn, $mid, $uploaded_by, $current_user);

        $_norm_type   = ($r['type'] === 'text' || empty($r['file_name']) || $r['file_name'] === '0') ? 'text' : $r['type'];
        $_main_file   = ($_norm_type === 'text') ? 'uploads/Text.jpg' : 'uploads/' . $r['file_name'];
        $_media_items = get_meme_media_items($conn, $mid, $_main_file, $_norm_type);

        $_audio = get_meme_audio_info($conn, $mid);
        $_group = get_meme_group_info($conn, $mid);

        $_counts = get_meme_counts($mid);

        $states[] = [
            'id'               => $mid,
            'likeCount'        => (int)$lc,
            'repostCount'      => (int)$rc,
            'favCount'         => (int)$fc,
            'shareCount'       => (int)$_counts['share'],
            'liked'            => $li,
            'reposted'         => $ri,
            'faved'            => $fi,
            'nickname'         => $_meta['nickname'],
            'isFollowing'      => $_meta['is_following'],
            'socialProof'      => $_meta['social_proof'],
            'media'            => $_media_items,
            'audioId'          => $_audio ? (int)$_audio['audio_id'] : null,
            'audioIsOriginal'  => $_audio ? (bool)($_audio['is_original'] ?? false) : false,
            'audioFile'        => $_audio ? 'uploads/'.$_audio['file_name'] : null,
            'audioTitle'       => $_audio ? $_audio['title'] : null,
            'audioArtist'      => ($_audio && !empty($_audio['artist'])) ? $_audio['artist'] : null,
            'musicVolume'      => ($_audio && $_audio['music_volume'] !== '' && $_audio['music_volume'] !== null) ? (int)$_audio['music_volume'] : 80,
            'videoAudioVolume' => ($_audio && $_audio['video_audio_volume'] !== '' && $_audio['video_audio_volume'] !== null) ? (int)$_audio['video_audio_volume'] : 40,
            'groupId'          => $_group ? (int)$_group['group_id'] : null,
            'groupTitle'       => $_group ? $_group['group_name'] : null,
            // FIX: Paid Partnership badge fields — were missing entirely,
            // so partnership posts opened from inside a Collection never
            // showed the badge in the reel/IPV modals.
            'isPartnership'    => !empty($r['is_partnership']),
            'partnerBrand'     => $r['partner_brand'] ?? null,
            // FIX: post-as-ad + AI-generated fields — were missing entirely,
            // so ad posts and AI-generated posts opened from inside a
            // Collection never showed the CTA pill / AD rail badge / AI tag.
            'isAd'             => !empty($r['is_ad']),
            'adCtaText'        => $r['ad_cta_text']  ?? null,
            'adCtaUrl'         => $r['ad_cta_url']   ?? null,
            'adCtaColor'       => $r['ad_cta_color'] ?? null,
            'isAiGenerated'    => !empty($r['is_ai_generated']),
            // FIX: Zeem Connect fields — were missing entirely, so a
            // connected post opened from inside a Collection never showed
            // the "▶ View · Connected Zeem" overlay button.
            'connectedMemeId'  => !empty($r['connected_meme_id']) ? (int)$r['connected_meme_id'] : null,
            'connectedLabel'   => $r['connected_label'] ?? null,
        ];
    }
    echo json_encode(['status'=>'ok','states'=>$states]);
    exit;
}
        
    /* ════════════════════════════════════════════════════════
       PIN / UNPIN POSTS  (was missing — caused "Could not update pin")
       ════════════════════════════════════════════════════════ */
    if($col_action === 'pin_post') {
        $meme_id = (int)($_POST['meme_id'] ?? 0);
        if(!$meme_id){ echo json_encode(['status'=>'error','message'=>'Missing meme_id']); exit; }

        // Only allow pinning your own profile's posts
        if($current_user != $profile_id){
            echo json_encode(['status'=>'error','message'=>'Not your profile']); exit;
        }

        // Max 3 pins — enforce server-side too
        $cnt = $conn->query("SELECT COUNT(*) as c FROM pinned_posts WHERE user_id=$current_user")->fetch_assoc()['c'];
        if((int)$cnt >= 3){
            echo json_encode(['status'=>'error','message'=>'Max 3 pins allowed. Unpin one first.']); exit;
        }

        // Make sure the meme actually exists / belongs to this user's feed
        $meme_chk = $conn->query("SELECT id FROM memes WHERE id=$meme_id");
        if(!$meme_chk->num_rows){
            echo json_encode(['status'=>'error','message'=>'Meme not found']); exit;
        }

        $stmt = $conn->prepare("INSERT INTO pinned_posts (user_id, meme_id, pinned_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE pinned_at=NOW()");
        $stmt->bind_param("ii", $current_user, $meme_id);
        if(!$stmt->execute()){
            echo json_encode(['status'=>'error','message'=>'DB error: '.$conn->error]); exit;
        }
        $stmt->close();
        echo json_encode(['status'=>'ok','pinned'=>true]);
        exit;
    }

    if($col_action === 'unpin_post') {
        $meme_id = (int)($_POST['meme_id'] ?? 0);
        if(!$meme_id){ echo json_encode(['status'=>'error','message'=>'Missing meme_id']); exit; }
        if($current_user != $profile_id){
            echo json_encode(['status'=>'error','message'=>'Not your profile']); exit;
        }
        $stmt = $conn->prepare("DELETE FROM pinned_posts WHERE user_id=? AND meme_id=?");
        $stmt->bind_param("ii", $current_user, $meme_id);
        if(!$stmt->execute()){
            echo json_encode(['status'=>'error','message'=>'DB error: '.$conn->error]); exit;
        }
        $stmt->close();
        echo json_encode(['status'=>'ok','pinned'=>false]);
        exit;
    }

    /* ════════════════════════════════════════════════════════
       PIN / UNPIN COLLECTIONS  (was missing — caused "Could not update")
       ════════════════════════════════════════════════════════ */
    if($col_action === 'pin_collection') {
        $col_id = (int)($_POST['col_id'] ?? 0);
        if(!$col_id){ echo json_encode(['status'=>'error','message'=>'Missing col_id']); exit; }

        // Must own the collection
        $own = $conn->query("SELECT id FROM collections WHERE id=$col_id AND user_id=$current_user");
        if(!$own->num_rows){
            echo json_encode(['status'=>'error','message'=>'Not your collection']); exit;
        }

        $cnt = $conn->query("SELECT COUNT(*) as c FROM pinned_collections WHERE user_id=$current_user")->fetch_assoc()['c'];
        if((int)$cnt >= 3){
            echo json_encode(['status'=>'error','message'=>'Max 3 pinned collections. Unpin one first.']); exit;
        }

        $stmt = $conn->prepare("INSERT INTO pinned_collections (user_id, collection_id, pinned_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE pinned_at=NOW()");
        $stmt->bind_param("ii", $current_user, $col_id);
        if(!$stmt->execute()){
            echo json_encode(['status'=>'error','message'=>'DB error: '.$conn->error]); exit;
        }
        $stmt->close();
        echo json_encode(['status'=>'ok','pinned'=>true]);
        exit;
    }

    if($col_action === 'unpin_collection') {
        $col_id = (int)($_POST['col_id'] ?? 0);
        if(!$col_id){ echo json_encode(['status'=>'error','message'=>'Missing col_id']); exit; }
        $stmt = $conn->prepare("DELETE FROM pinned_collections WHERE user_id=? AND collection_id=?");
        $stmt->bind_param("ii", $current_user, $col_id);
        if(!$stmt->execute()){
            echo json_encode(['status'=>'error','message'=>'DB error: '.$conn->error]); exit;
        }
        $stmt->close();
        echo json_encode(['status'=>'ok','pinned'=>false]);
        exit;
    }

    echo json_encode(['status'=>'error','message'=>'Unknown action']);
    exit;
}

// Load owner's collections (all for owner, public only for others)
$user_collections = [];
if($current_user == $profile_id) {
    // Owner sees all
    $col_res = $conn->query("
        SELECT 
            c.id, 
            c.name, 
            COALESCE(c.is_public,0) as is_public, 
            UNIX_TIMESTAMP(c.created_at) as created_at,
            (SELECT COUNT(*) FROM collection_items ci WHERE ci.collection_id=c.id) as item_count,
            (SELECT UNIX_TIMESTAMP(MAX(ci.added_at)) FROM collection_items ci WHERE ci.collection_id=c.id) as latest_addition
        FROM collections c 
        WHERE c.user_id=$current_user 
        ORDER BY c.created_at DESC
    ");
    while($r=$col_res->fetch_assoc()) $user_collections[]=$r;
} else {
    // Viewer sees only public
    $col_res = $conn->query("
        SELECT 
            c.id, 
            c.name, 
            COALESCE(c.is_public,0) as is_public, 
            UNIX_TIMESTAMP(c.created_at) as created_at,
            (SELECT COUNT(*) FROM collection_items ci WHERE ci.collection_id=c.id) as item_count,
            (SELECT UNIX_TIMESTAMP(MAX(ci.added_at)) FROM collection_items ci WHERE ci.collection_id=c.id) as latest_addition
        FROM collections c 
        WHERE c.user_id=$profile_id AND c.is_public=1 
        ORDER BY c.created_at DESC
    ");
    while($r=$col_res->fetch_assoc()) $user_collections[]=$r;
}

// Separate from $user_collections (which is scoped to whichever
// profile is being viewed). This one is always the LOGGED-IN
// user's own collections, used only by the "Add to Collection"
// modal — since you always save into YOUR OWN collections,
// regardless of whose profile/post you're adding from.
$my_collections = [];
if($current_user) {
    if($current_user == $profile_id) {
        // Already fetched above for the owner case — reuse it instead of querying twice.
        $my_collections = $user_collections;
    } else {
        $col_res = $conn->query("
            SELECT 
                c.id, 
                c.name, 
                COALESCE(c.is_public,0) as is_public, 
                UNIX_TIMESTAMP(c.created_at) as created_at,
                (SELECT COUNT(*) FROM collection_items ci WHERE ci.collection_id=c.id) as item_count,
                (SELECT UNIX_TIMESTAMP(MAX(ci.added_at)) FROM collection_items ci WHERE ci.collection_id=c.id) as latest_addition
            FROM collections c 
            WHERE c.user_id=$current_user 
            ORDER BY c.created_at DESC
        ");
        while($r=$col_res->fetch_assoc()) $my_collections[]=$r;
    }
}

// ── ALL POST HANDLERS — must be before any HTML ──
if($_SERVER['REQUEST_METHOD'] === 'POST' && $current_user && $current_user != $profile_id) {

    if(isset($_POST['follow'])) {
        $s = $conn->prepare("INSERT IGNORE INTO followers (follower_id,following_id) VALUES (?,?)");
        $s->bind_param("ii", $current_user, $profile_id);
        $s->execute(); $s->close();
        header("Location: profile.php?user_id=$profile_id"); exit;
    }

    if(isset($_POST['unfollow'])) {
        // Remove from followers
        $s = $conn->prepare("DELETE FROM followers WHERE follower_id=? AND following_id=?");
        $s->bind_param("ii", $current_user, $profile_id);
        $s->execute(); $s->close();

        // Clear any approved track request so private profile
        // shows "Request Track" instead of lingering "UnTrack"
        $s2 = $conn->prepare("DELETE FROM track_requests WHERE requester_id=? AND target_id=?");
        $s2->bind_param("ii", $current_user, $profile_id);
        $s2->execute(); $s2->close();

        header("Location: profile.php?user_id=$profile_id"); exit;
    }

    if(isset($_POST['send_track_request'])) {
        $s = $conn->prepare("INSERT INTO track_requests (requester_id,target_id,status,created_at) VALUES (?,?,'pending',NOW()) ON DUPLICATE KEY UPDATE status='pending',created_at=NOW()");
        $s->bind_param("ii", $current_user, $profile_id);
        $s->execute(); $s->close();
        header("Location: profile.php?user_id=$profile_id"); exit;
    }

    if(isset($_POST['cancel_track_request'])) {
        $s = $conn->prepare("DELETE FROM track_requests WHERE requester_id=? AND target_id=? AND status='pending'");
        $s->bind_param("ii", $current_user, $profile_id);
        $s->execute(); $s->close();
        header("Location: profile.php?user_id=$profile_id"); exit;
    }

}
// ... rest of your profile.php continues below
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

// ── Check for any unread notification-worthy activity (bell badge) ──
$notif_has_unread = false;
if ($current_user && $current_user == $profile_id) {
    $notif_uid = (int)$current_user;

    $last_read_row = $conn->query("SELECT last_read_at FROM notification_reads WHERE user_id=$notif_uid")->fetch_assoc();
    $last_read_at  = $last_read_row['last_read_at'] ?? '1970-01-01 00:00:00';
    $last_read_esc = $conn->real_escape_string($last_read_at);

    $notif_check = $conn->query("
        SELECT
        (
            (SELECT COUNT(*) FROM likes l WHERE l.meme_id IN (SELECT id FROM memes WHERE uploaded_by=$notif_uid) AND l.user_id != $notif_uid AND l.created_at > '$last_read_esc')
            +
            (SELECT COUNT(*) FROM followers f WHERE f.following_id=$notif_uid AND f.follower_id != $notif_uid AND f.created_at > '$last_read_esc')
            +
            (SELECT COUNT(*) FROM reposts r WHERE r.meme_id IN (SELECT id FROM memes WHERE uploaded_by=$notif_uid) AND r.user_id != $notif_uid AND r.created_at > '$last_read_esc')
            +
            (SELECT COUNT(*) FROM favorites fv WHERE fv.meme_id IN (SELECT id FROM memes WHERE uploaded_by=$notif_uid) AND fv.user_id != $notif_uid AND fv.created_at > '$last_read_esc')
            +
            (SELECT COUNT(*) FROM profile_views pv WHERE pv.profile_id=$notif_uid AND pv.viewer_id != $notif_uid AND pv.viewed_at > '$last_read_esc')
            +
            (SELECT COUNT(*) FROM notifications n WHERE n.user_id=$notif_uid AND n.is_read=0)
        ) AS cnt
    ")->fetch_assoc();

    $notif_has_unread = ((int)($notif_check['cnt'] ?? 0)) > 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $user['username']; ?> | Dezzme</title>

<!-- Open the connections early, before the browser even reaches the
     <link> tags below. Each of these is a separate DNS lookup + TLS
     handshake that would otherwise happen serially, one at a time,
     as the parser hits each stylesheet. -->
<link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<style>

/* ============================================================
   TABLE OF CONTENTS
   ─────────────────────────────────────────────────────────
   1.  RESET & CSS VARIABLES
   2.  BASE / BODY
   3.  NAV — DESKTOP
   4.  NAV LINKS — DESKTOP
   5.  CONTAINER
   6.  PROFILE HEADER
   7.  TABS — DESKTOP
   8.  MEME GRID
   9.  MEME CARD
   10. MEDIA BOX
   11. TOP BAR (card overlay)
   12. ICON BAR (card overlay)
   13. CAPTION
   14. NEW BADGE
   15. ACTION BUTTONS (Follow / Message / Block / Group / Track)
   16. KEBAB DROPDOWN
   17. POST COUNT BUTTON
   18. MESSAGES SECTION
   19. PROFILE NAME & LINK
   20. TOP ACTIONS
   21. OTHER ACCOUNTS INLINE
   22. MISC UTILITIES (hide-mobile, bottom-logo, PTR spinner, animations)
   23. PROFILE LAYOUT SYSTEM (info-block, stats-row, pills-row, action-row)
   ─────────────────────────────────────────────────────────
   RESPONSIVE SECTIONS
   ─────────────────────────────────────────────────────────
   R1.  TABLET  — max-width: 1024px  (button transitions only)
   R2.  TABLET  — max-width: 768px   (nav bottom bar, layout, grid, cards)
   R3.  PHONE   — max-width: 480px   (tighter spacing, icon-only group btn)
   R4.  SMALL   — max-width: 360px   (very small phones)
   ============================================================ */


/* ============================================================
   1. RESET & CSS VARIABLES
   ============================================================ */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Poppins', sans-serif;
}

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


/* ============================================================
   2. BASE / BODY
   ============================================================ */
html {
    scroll-behavior: smooth;
}

body {
    background: linear-gradient(-45deg,#070924,#070924);
    background-attachment: fixed;
    color: var(--text);
    font-family: var(--font-body);
    min-height: 100vh;
    overflow-x: hidden;
}

/* Particles canvas */
canvas {
    position: fixed;
    top: 0;
    left: 0;
    z-index: -1;
    width: 100%;
    height: 100%;
}


/* ============================================================
   3. NAV — DESKTOP
   ============================================================ */
nav {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 40px;
    position: sticky;
    top: 0;
    z-index: 100;
    background: transparent;
    border-radius: 30px;
    backdrop-filter: blur(12px);
    box-shadow: 0 4px 25px rgba(0,0,0,0.4);
}

nav .logo-container {
    display: flex;
    align-items: center;
    gap: 15px;
}

nav .logo-container img {
    width: 60px;
    height: 60px;
    border-radius: 20px;
    object-fit: cover;
    transition: 0.4s;
    cursor: pointer;
    filter: drop-shadow(0 10px 30px rgba(0,0,0,0.5));
}

nav .logo-container img:hover {
    transform: scale(1.15) rotate(-2deg);
    box-shadow: 0 0 40px rgba(255,255,255,0.5);
}

nav .logo-container .title {
    font-size: 32px;
    font-weight: 600;
    color: white;
    letter-spacing: 5px;
    text-shadow: 0 5px 15px rgba(0,0,0,0.3);
}


/* ============================================================
   4. NAV LINKS — DESKTOP
   ============================================================ */
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
    box-shadow:
        0 10px 25px rgba(255,255,255,0.2),
        0 0 15px rgba(255,255,255,0.1);
}

.nav-links a:active {
    transform: scale(0.95);
    box-shadow: inset 0 4px 10px rgba(0,0,0,0.4);
}

/* Icon inside nav link */
.nav-links a i {
    transition: transform 0.3s ease;
}

.nav-links a:hover i {
    transform: scale(1.2) rotate(5deg);
}

/* Shine sweep effect on hover */
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


/* ============================================================
   5. CONTAINER
   ============================================================ */
.container {
    max-width: 1000px;
    margin: auto;
    padding: 30px;
}


/* ============================================================
   6. PROFILE HEADER
   ============================================================ */
.profile-header {
    display: flex;
    align-items: center;
    margin-bottom: 20px;
    gap: 20px;
}

.profile-header img {
    width: 100px;
    height: 100px;
    cursor: pointer;
    border-radius: 20px;
    border:transparent;
    object-fit: cover;
    box-shadow:transparent;
    transition: all 0.4s ease;
    position: relative;
    filter: drop-shadow(0 10px 25px rgba(0,0,0,0.5));
}

.profile-header img:hover {
    transform: scale(1.08) rotate(4deg);
}

.profile-header h2 {
    font-size: 28px;
    color: rgba(255,255,255,0.95);
    font-weight: 600;
    letter-spacing: 0.6px;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: 0.3s ease;
    margin-bottom: 5px;
}

.profile-header:hover h2 {
    color: #ffffff;
    text-shadow: 0 0 10px rgba(255,255,255,0.25);
}

.profile-header p {
    line-height: 1.4;
}

/* Profile name label above header */
.profile-name {
    font-size: 12px;
    font-weight: 700;
    margin-top: 8px;
    margin-left: 15px;
    color: #c7baba;
}

/* Clickable profile name link */
.profile-link {
    text-decoration: none;
    color: inherit;
    display: inline-block;
}

.profile-link:hover .profile-name {
    color: #f23939;
    text-shadow: 0 0 10px rgba(255,0,0,0.4);
}

.profile-link:hover .profile-username {
    color: #fff;
}


/* ============================================================
   7. TABS — DESKTOP
   ============================================================ */
.tabs {
    display: flex;
    gap: 15px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.tabs button {
    background: rgba(255,255,255,0);
    border: none;
    color: white;
    padding: 9px 22px;
    border-radius: 25px;
    cursor: pointer;
    transition: all 0.3s ease;
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    font-weight: 600;
    position: relative;
    overflow: hidden;
}

.tabs button:hover {
    background: transparent;
    color: #ef1c1c;
    transform: translateY(-2px) scale(1.05);
}

.tabs button:hover i {
    color: #ee1717;
    transform: scale(1.2) rotate(5deg);
}

.tabs button:active {
    transform: scale(0.95);
    box-shadow: inset 0 4px 10px rgba(0,0,0,0.4);
}

/* Active tab state */
.tabs button.active {
    background: transparent;
    color: #e51f1f !important;
}

.tabs button.active i {
    color: #e51f1f !important;
    transform: none;
}

.tabs button i {
    transition: transform 0.3s ease, color 0.3s ease;
}


/* ============================================================
   8. MEME GRID
   ============================================================ */
.grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 5.5px;
}


/* ============================================================
   9. MEME CARD
   ============================================================ */
.meme-card {
    background: rgba(255,255,255,0.05);
    backdrop-filter: blur(10px);
    border-radius: 15px;
    overflow: hidden;
    position: relative;
    transition:
        transform 0.5s cubic-bezier(0.68,-0.55,0.27,1.55),
        opacity 0.5s cubic-bezier(0.68,-0.55,0.27,1.55),
        box-shadow 0.3s;
    opacity: 1;
    transform: translateY(0) scale(1);
    cursor: pointer;
}


/* Media inside card */
.meme-card img,
.meme-card video {
    width: 100%;
    height: 180px;
    object-fit: cover;
    border-bottom: 1px solid rgba(255,255,255,0.1);
    position: relative;
    z-index: 1;
}

.meme-card video {
    height: 220px;
}


/* ============================================================
   10. MEDIA BOX
   ============================================================ */
.media-box {
    position: relative;
    width: 100%;
}

.media-box img,
.media-box video {
    width: 100%;
    height: 180px;
    object-fit: cover;
    display: block;
}


/* ============================================================
   11. TOP BAR (card overlay — avatar + username)
   ============================================================ */
.top-bar {
    position: absolute;
    top: 10px;
    left: 10px;
    display: flex;
    align-items: center;
    gap: 8px;
    z-index: 10;
}

.top-bar img {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: 2px solid #FF4C4C;
    cursor: pointer;
}

.top-bar .username {
    color: white;
    font-weight: bold;
}


/* ============================================================
   12. ICON BAR (card overlay — action buttons top-right)
   ============================================================ */
.icon-bar {
    position: absolute;
    top: 10px;
    right: 10px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    z-index: 10;
}

.icon-bar button,
.icon-bar a {
    width: 36px;
    height: 36px;
    background: rgba(25,11,228,0.15);
    border: none;
    border-radius: 12px;
    color: white;
    font-size: 18px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: 0.3s;
}

.icon-bar button:hover,
.icon-bar a:hover {
    background: #FF4C4C;
    transform: scale(1.1);
    box-shadow: 0 0 12px rgba(255,255,255,0.8);
}


/* ============================================================
   13. CAPTION
   ============================================================ */
.caption {
    background: transparent;
    padding: 10px;
    font-size: 12.5px;
    line-height: 1.4;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 300px;
}


/* ============================================================
   14. NEW BADGE
   ============================================================ */
.new-badge {
    position: absolute;
    bottom: 10px;
    left: 10px;
    padding: 4px 9px;
    border-radius: 8px;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.8px;
    color: rgba(255,255,255,0.92);
    background: rgba(255,76,94,0.14);
    border: 1px solid rgba(255,76,94,0.28);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    z-index: 10;
    box-shadow: 0 4px 12px rgba(0,0,0,0.18);
    transition: opacity 0.6s ease, transform 0.6s ease, background 0.6s ease;
}

/* Glow layer on badge */
.new-badge::after {
    content: "";
    position: absolute;
    inset: 0;
    border-radius: inherit;
    box-shadow: 0 0 10px rgba(255,76,94,0.25);
    opacity: 0.6;
    pointer-events: none;
}

/* Faded state (applied via JS after time) */
.new-badge.fade {
    opacity: 0.35;
    transform: scale(0.95);
}

@keyframes newPulse {
    0%   { opacity: 0.3; transform: scale(1); }
    50%  { opacity: 0.9; transform: scale(1.08); }
    100% { opacity: 0.3; transform: scale(1); }
}


/* ============================================================
   15. ACTION BUTTONS
   ============================================================ */

/* ── Base action button ── */
.action-btn {
    background: rgba(255,255,255,0.1);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255,255,255,0.3);
    color: white;
    padding: 8px 20px;
    border-radius: 25px;
    cursor: pointer;
    transition: 0.3s;
    font-size: 14px;
}

.action-btn:hover {
    background: #FF4C4C;
}

/* ── Follow button ── */
.action-btn.follow-btn {
    border: 2px solid rgba(255,45,45,0.5);
    color: #FF2D2D;
    font-weight: 500;
    font-size: 14px;
    padding: 8px 22px;
    border-radius: 25px;
    background: rgba(255,255,255,0.1);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
}

.action-btn.follow-btn:hover {
    color: #FF2D2D;
    background: transparent;
    transform: translateY(-2px) scale(1.05);
    border-color: #FF2D2D;
}

/* ── Message button ── */
.message-btn {
    background: rgba(255,255,255,0.1);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    border: 1.5px solid rgba(255,255,255,0.3);
    color: white;
    font-weight: 500;
    font-size: 14px;
    padding: 8px 22px;
    border-radius: 25px;
    cursor: pointer;
    transition: all 0.35s ease;
    position: relative;
}

.message-btn:hover {
    background: rgba(255,255,255,0.25);
    transform: translateY(-2px) scale(1.05);
    border-color: rgba(255,255,255,0.5);
}

/* ── Block / Unblock button ── */
.block-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    width: 105px;
    height: 40px;
    background: rgba(255,255,255,0.08);
    font-weight: 500;
    border-radius: 15px;
    border: transparent;
    text-decoration: none;
    font-size: 16px;
    cursor: pointer;
    position: relative;
    overflow: hidden;
    box-shadow: transparent;
    transition: all 0.35s ease;
}

.block-btn i {
    font-size: 20px;
    transition: transform 0.3s ease;
}

.block-btn:hover {
    background: rgba(0,0,0,0.11);
    color: #ff1111;
    transform: scale(1.08) translateY(-2px);
}

.block-btn:active {
    transform: scale(0.95);
    box-shadow: inset 0 4px 12px rgba(0,0,0,0.4);
}

.block-btn.block   { color: white; }
.block-btn.unblock { color: red; }

/* ── Group / Pods button ── */
.group-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    width: 90px;
    height: 40px;
    color: rgba(255,255,255,0.55);
    font-weight: 500;
    border-radius: 15px;
    text-decoration: none;
    font-size: 16px;
    cursor: pointer;
    position: relative;
    overflow: hidden;
    transition: all 0.35s ease;
    background: none;
    border: none;
    outline: none;
    box-shadow: none;
    appearance: none;
    -webkit-appearance: none;
}

.group-btn i {
    font-size: 20px;
    transition: transform 0.3s ease;
}

.group-btn:hover {
    color: #ff1111;
    transform: translateY(4px) scale(1.08) translateY(-2px);
}

.group-btn:active {
    transform: translateY(4px) scale(0.95);
    box-shadow: inset 0 4px 12px rgba(0,0,0,0.4);
}

.group-btn .track-count {
    color: #fff;
}
/* ── Track / Untrack button ── */
.track-btn {
    display: inline-block;
    padding: 4px 14px;
    border-radius: 20px;
    color: rgba(255,255,255,0.55);
    text-decoration: none;
    font-weight: 500;
    margin-right: 8px;
    transition: all 0.2s ease;
    background: none;
    border: none;
    outline: none;
    box-shadow: none;
    appearance: none;
    -webkit-appearance: none;
}

.track-btn .track-count {
    color: #fff;
}

.track-btn:hover {
    color: #ff1111;
    transform: translateY(4px) scale(1.08) translateY(-2px);
}

/* Blurred follower count (shown to non-followers) */
.blur-count {
    filter: blur(6px);
    user-select: none;
    transition: 0.3s;
}

.blur-count:hover {
    filter: blur(4px);
}


/* ============================================================
   16. KEBAB DROPDOWN
   ============================================================ */
.kebab-wrapper {
    position: relative;
    margin-top: 5px;
    display: inline-block;
    width: 23px;
    cursor: pointer;
}

.kebab-icon {
    font-size: 28px;
    width: 10px;
    color: #fff;
    padding: 6px;
    border-radius: 30px;
    background: transparent;
    backdrop-filter: blur(12px);
    border: transparent;
    transition: all 0.3s ease;
}

.kebab-icon:hover {
    color: #ff1111;
}

.kebab-dropdown {
    display: none;
    position: absolute;
    top: 30px;
    left: 25px;
    background: rgba(255,255,255,0.08);
    backdrop-filter: blur(15px);
    border-radius: 15px;
    min-width: 175px;
    overflow: hidden;
    box-shadow: 0 10px 25px rgba(0,0,0,0.35);
    flex-direction: column;
    z-index: 999;
    transition: all 0.25s ease;
}

.kebab-dropdown a {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 15px;
    color: white;
    text-decoration: none;
    font-size: 14px;
    transition: all 0.25s ease;
}

.kebab-dropdown a i {
    font-size: 18px;
    color: #f21d1d;
}

.kebab-dropdown a:hover {
    background: rgba(255,255,255,0.15);
    color: #ee2626;
}

.kebab-dropdown.show {
    display: flex;
    animation: fadeDown 0.25s ease forwards;
}

@keyframes fadeDown {
    0%   { opacity: 0; transform: translateY(-5px); }
    100% { opacity: 1; transform: translateY(0); }
}


/* ============================================================
   17. POST COUNT BUTTON
   ============================================================ */
.post-count-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    width: 50px;
    height: 45px;
    border-radius: 12px;
    color: white;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    position: relative;
    font-size: 14px;
    appearance: none;
    -webkit-appearance: none;
    background: none;
    border: none;
    outline: none;
    box-shadow: none;
    padding: 0;
}

.post-count-btn i {
    font-size: 18px;
    color: #ff4c4c;
}

.post-count-btn:hover {
    color: #fa3737;
    transform: scale(1.08) translateY(-2px);
}


/* ============================================================
   18. MESSAGES SECTION
   ============================================================ */
#messages {
    display: none;
    flex-direction: column;
    gap: 12px;
    padding: 10px 0;
}

.message-user {
    display: flex;
    align-items: center;
    gap: 12px;
    background: rgba(255,255,255,0.08);
    padding: 10px 15px;
    border-radius: 20px;
    transition: transform 0.3s, box-shadow 0.3s, background 0.3s;
    cursor: pointer;
}

.message-user:hover {
    transform: translateY(-2px) scale(1.02);
    background: rgba(255,255,255,0.18);
    box-shadow: 0 0 12px rgba(255,255,255,0.15);
}

.message-user img {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    object-fit: cover;
    cursor: pointer;
    border: 3px solid rgba(223,20,20,0.96);
    transition: transform 0.3s, box-shadow 0.3s, border-color 0.3s;
}

.message-user img:hover {
    box-shadow: 0 0 12px rgb(243,14,14), 0 0 20px rgba(255,255,255,0.2);
    transform: scale(1.1);
    border-color: rgba(255,36,36,0.92);
}

.message-user a {
    color: #ffffff;
    font-weight: 600;
    text-decoration: none;
    font-size: 16px;
    cursor: pointer;
    transition: color 0.3s, text-shadow 0.3s;
}

.message-user a:hover {
    color: white;
    text-shadow: 0 0 6px rgb(255,255,255);
}


/* ============================================================
   19. TOP ACTIONS (absolute positioned row — kebab / notifications)
   ============================================================ */
.top-actions {
    position: absolute;
    top: 105px;
    right: 25px;
    display: flex;
    align-items: center;
    gap: 15px;
    z-index: 999;
}


/* ============================================================
   20. OTHER ACCOUNTS INLINE
   ============================================================ */
.other-accounts-inline {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    font-size: 14px;
    color: #fff;
}

.other-accounts-inline .label {
    font-weight: 600;
    margin-right: 4px;
}

.other-accounts-inline .other-account-link {
    color: #adacb3;
    text-decoration: none;
    transition: color 0.2s ease;
}

.other-accounts-inline .other-account-link:hover {
    color: #ff0000;
    cursor: pointer;
}

/* Other account links (standalone version) */
.other-account-link {
    color: #c0bfc6;
    text-decoration: underline;
    font-size: 14px;
    transition: color 0.2s, text-decoration 0.2s;
}

.other-account-link:hover {
    color: #ea1e1e;
    text-decoration: none;
    cursor: pointer;
}


/* ============================================================
   21. MISC UTILITIES
   ============================================================ */

/* Hide on mobile (shown on desktop) */
.hide-mobile {
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

/* Floating bottom logo */
.bottom-logo {
    position: fixed;
    bottom: 25px;
    right: 25px;
    width: 60px;
    border-radius: 20px;
    filter: drop-shadow(0 10px 30px rgba(0,0,0,0.5));
    transition: 0.4s;
    animation: floatLogo 5s ease-in-out infinite;
}

@keyframes floatLogo {
    0%   { transform: translateY(0); }
    50%  { transform: translateY(-15px); }
    100% { transform: translateY(0); }
}

/* Profile upload file input */
#profile-upload input[type="file"] {
    margin-bottom: 10px;
    border-radius: 12px;
    padding: 5px;
    border: none;
    background: rgba(255,255,255,0.1);
    color: white;
}

#profile-msg {
    color: #FF4C4C;
    margin-top: 5px;
}


/* ============================================================
   22. PROFILE LAYOUT SYSTEM
   (info block, stats row, pills row, action row)
   ============================================================ */
.profile-info-block {
    display: flex;
    flex-direction: column;
    gap: 6px;
    flex: 1;
    min-width: 0;
}

.profile-stats-row {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 4px;
}

.profile-pills-row {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
    margin-top: 4px;
}

.profile-action-row {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 8px;
}

.profile-stat-divider {
    color: rgba(255,255,255,0.15);
    font-size: 13px;
    line-height: 1;
    flex-shrink: 0;
}



/* Desktop: show icon, hide profile pic in nav */
.nav-profile-pic {
    display: none;
}

/* ╔══════════════════════════════════════════════════════════╗
   ║               RESPONSIVE STYLES BELOW                   ║
   ╚══════════════════════════════════════════════════════════╝ */


/* ============================================================
   R1. TABLET — max-width: 1024px
   (button base transition tweak only)
   ============================================================ */
@media (max-width: 1024px) {

    .follow-btn,
    .message-btn,
    .action-btn,
    .block-btn,
    .view-btn,
    .post-count-btn {
        transition: all 0.25s ease;
    }
}


/* ============================================================
   R2. TABLET — max-width: 768px
   Nav becomes fixed bottom bar. Layout, grid, and cards shrink.
   ============================================================ */
@media (max-width: 788px) {

    /* ── Nav: fixed bottom tab bar ── */
    nav {
        position: fixed;
        top: auto;
        bottom: 0;
        left: 0;
        right: 0;
        height: 60px;
        padding: 0;
        background: rgba(3,4,21,0.97);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border-top: 1px solid rgba(255,255,255,0.1);
        display: flex;
        border-radius: 0;
        justify-content: center;
        z-index: 1000;
    }

    /* Hide nav entirely on other user's profile (responsive only) */
    nav.other-user-nav {
        display: none !important;
    }

    /* ── Nav links: fill bar evenly ── */
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

    /* Remove shine sweep */
    .nav-links a::before {
        display: none;
    }

    /* Bolder icons */
    .nav-links a i {
        font-size: 24px;
        color: #fff;
        font-weight: 800;
        -webkit-text-stroke: 0.5px #fff;
    }

    .nav-links a span {
        display: none;
    }

    .nav-links a:active {
        transform: scale(0.9);
        opacity: 0.6;
    }

    /* Hide upload icon from bottom tab bar */
    a.nav-icon[href="upload.php"] {
        display: none !important;
    }

    /* ── Logo: hide in bottom bar ── */
    .logo-container {
        display: none !important;
    }

    /* ── Hide mobile utility class ── */
    .hide-mobile {
        display: none !important;
    }

    /* ── Bottom floating logo: hide on mobile ── */
    .bottom-logo {
        display: none !important;
    }

    /* ── Container: add bottom padding for fixed nav ── */
    .container {
        max-width: 100%;
        padding: 20px 16px 80px 16px;
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


    /* ── Profile header ── */
    .profile-header {
        flex-wrap: wrap;
        gap: 14px;
        margin-bottom: 14px;
        align-items: flex-start;
    }

    .profile-header img {
        width: 80px;
        height: 80px;
        border-radius: 16px;
        flex-shrink: 0;
    }

    .profile-header h2 {
        font-size: 18px;
        line-height: 1.3;
        letter-spacing: 0.3px;
    }

    .profile-header p {
        font-size: 13px;
    }

    /* ── Profile layout system ── */
    .profile-stats-row  { gap: 6px; margin-top: 3px; }
    .profile-pills-row  { gap: 5px; margin-top: 3px; }
    .profile-action-row { gap: 6px; margin-top: 6px; }

    .other-accounts-inline {
        font-size: 12.5px;
        gap: 6px;
        margin-top: 4px;
    }

    .other-accounts-inline .label,
    .other-accounts-inline .other-account-link {
        font-size: 12.5px;
    }

    /* ── Action buttons: keep them from breaking ── */
    .action-btn.follow-btn,
    .message-btn,
    .action-btn,
    .block-btn,
    .view-btn,
    .post-count-btn {
        flex-shrink: 0;
    }

    .action-btn.follow-btn,
    .message-btn,
    .action-btn {
        padding: 4px 12px;
        font-size: 11px;
        border-radius: 18px;
        white-space: nowrap;
    }

    .block-btn {
        width: auto;
        height: 34px;
        padding: 6px 14px;
        font-size: 13px;
        border-radius: 14px;
        white-space: nowrap;
    }

    .view-btn {
        width: 40px;
        height: 40px;
        font-size: 18px;
        flex-shrink: 0;
    }

    .post-count-btn {
        width: 42px;
        height: 42px;
        font-size: 13px;
        border-radius: 10px;
        flex-shrink: 0;
    }

    .post-count-btn i { font-size: 16px; }

    .group-btn {
        width: 80px;
        height: 36px;
        font-size: 14px;
        border-radius: 12px;
    }

    /* ── Top actions row ── */
    .top-actions { top: 88px; right: 16px; gap: 10px; }

    /* ── Meme grid (sharp corners, tight spacing) ── */
    .grid {
        grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
        gap: 4px;
    }

    .meme-card,
    .media-box {
        border-radius: 0 !important;
    }

    .meme-card img,
    .media-box img  { height: 150px; border-radius: 0 !important; }

    .meme-card video,
    .media-box video { height: 180px; border-radius: 0 !important; }

    /* ── Card internals ── */
    .caption { font-size: 11.5px; padding: 8px; border-radius: 0 !important; }

    .top-bar img      { width: 28px; height: 28px; border-radius: 50% !important; }
    .top-bar .username { font-size: 12px; }

    .icon-bar button,
    .icon-bar a       { width: 32px; height: 32px; font-size: 16px; border-radius: 10px; }

    .new-badge        { font-size: 8px; padding: 3px 7px; border-radius: 7px; }

    /* ── Profile name ── */
    .profile-name { font-size: 13px; margin-left: 12px; margin-top: 6px; }

    /* ── Messages ── */
    #messages         { gap: 10px; }
    .message-user     { padding: 9px 12px; border-radius: 16px; gap: 10px; }
    .message-user img { width: 44px; height: 44px; }
    .message-user a   { font-size: 14px; }

    /* ── Kebab ── */
    .kebab-icon       { font-size: 24px; }
    .kebab-dropdown   { min-width: 155px; border-radius: 13px; }
    .kebab-dropdown a { font-size: 13px; padding: 9px 13px; }
    .kebab-dropdown a i { font-size: 16px; }

    /* ── Track button ── */
    .track-btn {
        font-size: 12px;
        padding: 5px 13px;
        border-radius: 16px;
        margin-right: 6px;
        font-weight: 500;
    }

/* ── Tabs: sticky scrollable icon-only row ── */
.tabs-indicator {
    position: absolute;
    bottom: 9.5px;
    left: 0;
    height: 2px;
    width: 0;
    background: #ffffff;
    border-radius: 999px;
    transition:
        left  0.3s cubic-bezier(0.4,0,0.2,1),
        width 0.3s cubic-bezier(0.4,0,0.2,1);
    pointer-events: none;
    z-index: 1;
}

.tabs {
    position: sticky;
    top: 57px; /* ← flush against topbar bottom edge, no gap */
    left: 0;
    right: 0;
   width: 98vw;
    margin-left: calc(-49vw + 50%); /* ← breaks out of any container padding */
    background: linear-gradient(-45deg,#070924,#070924);
    z-index: 998;
    display: flex;
    flex-wrap: nowrap;
    overflow-x: auto;
    gap: 0;
    padding: 8px 0 12px; /* ← taller: added top padding, slightly more bottom */
    scrollbar-width: none;
    -webkit-overflow-scrolling: touch;
    justify-content: space-evenly;
    border-bottom: 1px solid rgba(255,255,255,0.07);
    transform: translateZ(0);
    -webkit-transform: translateZ(0);
    will-change: transform;
    backface-visibility: hidden;
    -webkit-backface-visibility: hidden;
}

.tabs::-webkit-scrollbar { display: none; }

/* Sliding underline via ::after */
.tabs::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    height: 2px;
    width: 0;
    background: #ff2a2a;
    border-radius: 999px;
    transition:
        left  0.3s cubic-bezier(0.4,0,0.2,1),
        width 0.3s cubic-bezier(0.4,0,0.2,1);
    pointer-events: none;
}

.tabs button {
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    backdrop-filter: none !important;
    overflow: visible !important;
    padding: 6px 0;
    min-width: 0;
    height: auto;
    width: auto;
    flex: 1;
    border-radius: 0;
    white-space: nowrap;
    font-size: 0 !important;
    color: rgba(255,255,255,0.55);
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    transition: color 0.22s ease, transform 0.15s ease;
    -webkit-tap-highlight-color: transparent;
    cursor: pointer;
}

/* Kill inherited shine sweep on tab buttons */
.tabs button::before {
    display: none !important;
    content: none !important;
    animation: none !important;
}

.tabs button::after { display: none !important; }

.tabs button i {
    font-size: clamp(13px, 4vw, 16px);
    display: block;
    pointer-events: none;
    color: rgba(255,255,255,0.55) !important;
}

.tabs button.active       { color: #ffffff !important; background: transparent !important; }
.tabs button.active i     { color: #ffffff !important; }
.tabs button:hover        { color: rgba(255,255,255,0.85) !important; background: transparent !important; }
.tabs button:hover i      { color: rgba(255,255,255,0.85) !important; transform: none !important; }
.tabs button:active       { color: #fff !important; transform: scale(0.88); opacity: 0.75; }

.tabs .post-count-btn {
    flex: 1;
    height: clamp(32px, 8vw, 40px);
    flex-shrink: 0;
    font-size: clamp(11px, 2.8vw, 13px) !important;
}

.tabs .kebab-wrapper {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
}
.tabs .kebab-icon { font-size: clamp(17px, 4vw, 20px); color: rgba(255,255,255,0.72); }
}

/* Keep tabs flush with topbar at every breakpoint (no gap) */
@media (max-width: 490px) {
    .tabs { top: 47px; }
}
@media (max-width: 380px) {
    .tabs { top: 44px; }
}
@media (max-width: 330px) {
    .tabs { top: 41px; }
}


/* ============================================================
   R3. PHONE — max-width: 480px
   ============================================================ */
@media (max-width: 480px) {

    /* ── Nav ── */
    nav {
        padding: 0 12px;
        min-height: 52px;
    }

    .nav-links a {
        font-size: 14px;
        height: 42px;
        padding: 0 10px;
    }

    /* ── Logo + hide-mobile ── */
    .logo-container  { display: none !important; }
    .hide-mobile     { display: none !important; }
    .bottom-logo     { display: none !important; }

    /* ── Container ── */
    .container { padding: 20px 16px 80px 16px; }

    /* ── Profile header ── */
    .profile-header { gap: 12px; margin-bottom: 12px; }
    .profile-header img { width: 70px; height: 70px; border-radius: 14px; }
    .profile-header h2  { font-size: 16px; }
    .profile-header p   { font-size: 12px; line-height: 1.4; }

    /* ── Profile layout ── */
    .profile-stats-row  { gap: 4px; }
    .profile-pills-row  { gap: 4px; }
    .profile-action-row { gap: 5px; margin-top: 5px; }

    .other-accounts-inline,
    .other-accounts-inline .label,
    .other-accounts-inline .other-account-link { font-size: 11.5px; gap: 5px; line-height: 1.4; }

    /* ── Action buttons ── */
    .follow-btn,
    .message-btn,
    .action-btn {
        padding: 5.5px 12px;
        font-size: 12px;
        border-radius: 16px;
        white-space: nowrap;
        flex-shrink: 0;
    }

    .block-btn {
        height: 32px;
        padding: 5px 12px;
        font-size: 13px;
        border-radius: 12px;
        white-space: nowrap;
        flex-shrink: 0;
    }

    .view-btn      { width: 36px; height: 36px; font-size: 16px; flex-shrink: 0; }
    .post-count-btn { width: 38px; height: 38px; font-size: 12px; flex-shrink: 0; }
    .post-count-btn i { font-size: 15px; }

    /* ── Group button: icon only (no text) ── */
    .group-btn {
        width: 42px;
        height: 36px;
        padding: 0;
        font-size: 11.5px;
        border-radius: 12px;
        gap: 0;
    }

    .group-btn i { font-size: 0; } /* hides text label */

    /* ── Disable hover transform on touch ── */
    .message-btn:hover,
    .group-btn:hover,
    .block-btn:hover,
    .view-btn:hover { transform: none; }

    /* ── Top actions ── */
    .top-actions { top: 80px; right: 12px; gap: 8px; }

    /* ── Grid (sharp corners, tight spacing) ── */
    .grid { grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 3px; }

    .meme-card,
    .media-box { border-radius: 0 !important; }

    .meme-card img, .media-box img   { height: 130px; border-radius: 0 !important; }
    .meme-card video, .media-box video { height: 160px; border-radius: 0 !important; }

    /* ── Card internals ── */
    .caption        { font-size: 11px; padding: 7px; border-radius: 0 !important; }
    .top-bar        { gap: 5px; }
    .top-bar img    { width: 24px; height: 24px; border-radius: 50% !important; }
    .top-bar .username { font-size: 11px; }
    .icon-bar       { gap: 5px; }
    .icon-bar button,
    .icon-bar a     { width: 28px; height: 28px; font-size: 14px; border-radius: 8px; }
    .new-badge      { font-size: 7.5px; padding: 2px 6px; }

    /* ── Post count ── */
    .post-count-btn { width: 40px; height: 40px; font-size: 12px; }
    .post-count-btn i { font-size: 15px; }

    /* ── Profile name ── */
    .profile-name { font-size: 12px; margin-left: 10px; margin-top: 5px; }

    /* ── Messages ── */
    .message-user     { padding: 8px 10px; border-radius: 14px; gap: 9px; }
    .message-user img { width: 40px; height: 40px; }
    .message-user a   { font-size: 13px; }

    /* ── Kebab ── */
    .kebab-icon       { font-size: 22px; }
    .kebab-dropdown   { min-width: 145px; top: 26px; left: 20px; border-radius: 12px; }
    .kebab-dropdown a { font-size: 12.5px; padding: 8px 12px; gap: 8px; }
    .kebab-dropdown a i { font-size: 15px; }

    /* ── Track button ── */
    .track-btn { font-size: 11px; padding: 5px 11px; margin-right: 5px; }

    /* ── Other account links ── */
    .other-account-link { font-size: 12.5px; }

    /* ── Tabs ── */
.tabs { gap: 0; padding: 8px 0 12px; }
.tabs button     { flex: 1; padding: 5px 0; }
.tabs button i   { font-size: clamp(15px, 4vw, 21px); }
.tabs .post-count-btn { flex: 1; height: clamp(30px, 7.5vw, 38px); }
.tabs .kebab-icon { font-size: clamp(17px, 4vw, 20px); }
}


/* ============================================================
   R4. SMALL PHONE — max-width: 360px
   ============================================================ */
@media (max-width: 390px) {

    /* ── Nav ── */
    nav { padding: 0 10px; min-height: 48px; }

    .nav-links a { font-size: 13.5px; height: 40px; padding: 0 8px; }

    /* ── Logo + hide-mobile ── */
    .logo-container { display: none !important; }
    .hide-mobile    { display: none !important; }
    .bottom-logo    { display: none !important; }

    /* ── Container ── */
    .container { padding: 14px 10px 80px 10px; }

    /* ── Profile header ── */
    .profile-header { gap: 10px; }
    .profile-header img { width: 62px; height: 62px; border-radius: 12px; }
    .profile-header h2  { font-size: 14.5px; }
    .profile-header p   { font-size: 12px; }

    /* ── Profile layout ── */
    .profile-stats-row  { gap: 3px; }
    .profile-action-row { flex-wrap: wrap; gap: 4px; }

    .other-accounts-inline { font-size: 11px; gap: 4px; }

    /* ── Action buttons ── */
    .action-btn.follow-btn,
    .message-btn,
    .action-btn {
        padding: 4.5px 12px;
        font-size: 13px;
        white-space: nowrap;
    }

    .block-btn  { height: 30px; font-size: 11px; }
    .view-btn   { width: 34px; height: 34px; }
    .post-count-btn { width: 36px; height: 36px; }
    .post-count-btn i { font-size: 14px; }

    /* ── Group button: icon only ── */
    .group-btn   { width: 38px; height: 34px; font-size: 11px; border-radius: 10px; }
    .group-btn i { font-size: 0; }

    /* ── Top actions ── */
    .top-actions { top: 76px; right: 10px; gap: 7px; }

    /* ── Grid (sharp corners, tight spacing) ── */
    .grid { grid-template-columns: repeat(2, 1fr); gap: 3px; }

    .meme-card,
    .media-box { border-radius: 0 !important; }

    .meme-card img, .media-box img     { height: 115px; border-radius: 0 !important; }
    .meme-card video, .media-box video { height: 140px; border-radius: 0 !important; }

    /* ── Card internals ── */
    .caption        { font-size: 10.5px; padding: 6px; border-radius: 0 !important; }
    .top-bar img    { width: 22px; height: 22px; border-radius: 50% !important; }
    .top-bar .username { font-size: 10.5px; }
    .icon-bar button,
    .icon-bar a     { width: 26px; height: 26px; font-size: 13px; border-radius: 7px; }
    .new-badge      { font-size: 7px; padding: 2px 5px; bottom: 6px; left: 6px; }

    /* ── Profile name ── */
    .profile-name { font-size: 11.5px; margin-left: 8px; }

    /* ── Messages ── */
    .message-user     { padding: 7px 9px; border-radius: 12px; gap: 8px; }
    .message-user img { width: 36px; height: 36px; }
    .message-user a   { font-size: 12.5px; }

    /* ── Kebab ── */
    .kebab-dropdown   { min-width: 135px; top: 24px; left: 16px; }
    .kebab-dropdown a { font-size: 12px; padding: 7px 10px; }
    .kebab-dropdown a i { font-size: 14px; }

    /* ── Track button ── */
    .track-btn { font-size: 10.5px; padding: 4px 10px; }

    /* ── Other account links ── */
    .other-account-link { font-size: 11.5px; }
}

@media (max-width: 330px) {

    /* ── Nav ── */
    nav { padding: 0 8px; min-height: 44px; }

    .nav-links a { font-size: 12px; height: 36px; padding: 0 6px; }

    /* ── Logo + hide-mobile ── */
    .logo-container { display: none !important; }
    .hide-mobile    { display: none !important; }
    .bottom-logo    { display: none !important; }

    /* ── Container ── */
    .container { padding: 12px 8px 80px 8px; }

    /* ── Profile header ── */
    .profile-header { gap: 8px; }
    .profile-header img { width: 54px; height: 54px; border-radius: 11px; }
    .profile-header h2  { font-size: 13px; }
    .profile-header p   { font-size: 11px; }

    /* ── Profile layout ── */
    .profile-stats-row  { gap: 2px; }
    .profile-action-row { flex-wrap: wrap; gap: 3px; }

    .other-accounts-inline { font-size: 10px; gap: 3px; }

    /* ── Action buttons ── */
    .action-btn.follow-btn,
    .message-btn,
    .action-btn {
        padding: 3px 10px;
        font-size: 11px;
        white-space: nowrap;
    }

    .block-btn  { height: 27px; font-size: 10px; }
    .view-btn   { width: 30px; height: 30px; }
    .post-count-btn { width: 32px; height: 32px; }
    .post-count-btn i { font-size: 13px; }

    /* ── Group button: icon only ── */
    .group-btn   { width: 34px; height: 30px; font-size: 10px; border-radius: 9px; }
    .group-btn i { font-size: 0; }

    /* ── Top actions ── */
    .top-actions { top: 70px; right: 8px; gap: 6px; }

    /* ── Grid (sharp corners, tight spacing) ── */
    .grid { grid-template-columns: repeat(2, 1fr); gap: 2px; }

    .meme-card,
    .media-box { border-radius: 0 !important; }

    .meme-card img, .media-box img     { height: 100px; border-radius: 0 !important; }
    .meme-card video, .media-box video { height: 125px; border-radius: 0 !important; }

    /* ── Card internals ── */
    .caption        { font-size: 9.5px; padding: 5px; border-radius: 0 !important; }
    .top-bar img    { width: 20px; height: 20px; border-radius: 50% !important; }
    .top-bar .username { font-size: 9.5px; }
    .icon-bar button,
    .icon-bar a     { width: 24px; height: 24px; font-size: 12px; border-radius: 6px; }
    .new-badge      { font-size: 6.5px; padding: 2px 4px; bottom: 5px; left: 5px; }

    /* ── Profile name ── */
    .profile-name { font-size: 10.5px; margin-left: 6px; }

    /* ── Messages ── */
    .message-user     { padding: 6px 8px; border-radius: 11px; gap: 7px; }
    .message-user img { width: 32px; height: 32px; }
    .message-user a   { font-size: 11.5px; }

    /* ── Kebab ── */
    .kebab-dropdown   { min-width: 125px; top: 22px; left: 14px; }
    .kebab-dropdown a { font-size: 11px; padding: 6px 9px; }
    .kebab-dropdown a i { font-size: 13px; }

    /* ── Track button ── */
    .track-btn { font-size: 9.5px; padding: 3px 9px; }

    /* ── Other account links ── */
    .other-account-link { font-size: 10.5px; }
}

/* ── Force User Info block to always sit BELOW the profile pic ── */
@media(max-width:510px){
    .profile-header{
        flex-wrap: wrap !important;
    }
    .profile-userinfo{
        flex-basis: 100% !important;
        width: 100% !important;
        order: 3 !important;
        margin-top: 10px !important;
    }
}

@media(max-width:490px){
    .profile-userinfo{ margin-top: 1px !important; }
}

@media(max-width:380px){
    .profile-userinfo{ margin-top: 1px !important; }
}

@media(max-width:330px){
    .profile-userinfo{ margin-top: 1px !important; }
}

</style>
</head>
<body>

<nav>
    <div class="logo-container">
        <img src="Dezzme.jpg" alt="Logo Top" onclick="window.location.href='index.php'">
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
     src="<?php echo htmlspecialchars($nav_profile_pic); ?>"
     onerror="this.src='uploads/mee.jpeg'"
     alt="Profile">
        </a>
        <a class="glass nav-icon hide-mobile" href="logout.php"><i class="ri-login-box-line"></i></a>
    </div>
</nav>

<?php
// ── Quick follow/track status just for the sticky topbar ──
$topbar_is_following = false;
$topbar_is_private   = !empty($settings['is_fully_private']);
$topbar_req_status   = null;

if ($current_user && $current_user != $profile_id) {
    $tbf = $conn->prepare("SELECT id FROM followers WHERE follower_id=? AND following_id=?");
    $tbf->bind_param("ii", $current_user, $profile_id);
    $tbf->execute();
    $topbar_is_following = $tbf->get_result()->num_rows > 0;
    $tbf->close();

    if ($topbar_is_private) {
        $tbr = $conn->prepare("SELECT status FROM track_requests WHERE requester_id=? AND target_id=?");
        $tbr->bind_param("ii", $current_user, $profile_id);
        $tbr->execute();
        $tbr_row = $tbr->get_result()->fetch_assoc();
        $topbar_req_status = $tbr_row ? $tbr_row['status'] : null;
        $tbr->close();
    }
}

$topbar_show_track = $current_user
    && $current_user != $profile_id
    && !$is_blocked_by_owner
    && !$topbar_is_following;

// If there's no Track button to show (already tracking, is owner, blocked, etc.)
// mark the topbar so the sticky-swap CSS never hides search/menu for nothing.
$topbar_no_track_class = (!$topbar_show_track && $current_user != $profile_id) ? ' dz-no-track' : '';

// ── Username / Nickname split for the topbar title block ──
$topbar_username = $user['username'] ?? '';
$topbar_nickname = $user['nickname'] ?? '';
$topbar_has_nickname = (!empty($topbar_nickname) && $topbar_nickname !== $topbar_username);
$topbar_is_owner = ($current_user == $profile_id);
?>

<!-- ══════════════════════════════════════
     MOBILE-ONLY FIXED PROFILE TOPBAR
     Only visible at ≤770/490/380/330px.
     Fixed position — stays pinned, never scrolls away.
     Track button only appears once .tabs docks under this bar,
     and only if a Track button actually exists (not already tracking).
══════════════════════════════════════ -->
<div class="dz-profile-topbar<?php echo $topbar_no_track_class; ?>">
    <div class="dz-pt-side dz-pt-left">
        <?php if($current_user == $profile_id): ?>
            <a href="memepedia.php" class="dz-pt-btn dz-pt-memepedia" title="Memepedia" aria-label="Memepedia">
                <i class="ri-book-open-line"></i>
            </a>
        <?php else: ?>
            <button type="button" class="dz-pt-btn dz-pt-back" aria-label="Go back"
                onclick="if(document.referrer && document.referrer.indexOf(window.location.host) !== -1){history.back();}else{location.href='profile.php?user_id=<?php echo (int)$profile_id; ?>';}">
                <i class="ri-arrow-left-s-line"></i>
            </button>
        <?php endif; ?>
    </div>

    <div class="dz-pt-title-wrap"
         id="dzPtTitleWrap"
         <?php echo $topbar_is_owner ? 'onclick="toggleUserKebab(event)" role="button" aria-label="Account menu"' : ''; ?>>
        <div class="dz-pt-username">
            <span class="dz-pt-username-text"><?php echo htmlspecialchars($topbar_username); ?></span>
            <?php if($topbar_is_owner): ?>
                <i class="ri-arrow-down-s-line dz-pt-chevron" id="dzPtChevron"></i>
            <?php endif; ?>
        </div>
        <?php if($topbar_has_nickname): ?>
        <div class="dz-pt-nickname">@<?php echo htmlspecialchars($topbar_nickname); ?></div>
        <?php endif; ?>
    </div>

    <div class="dz-pt-side dz-pt-right">
<?php if($current_user == $profile_id): ?>
    <a href="notifications.php" class="dz-pt-btn dz-pt-bell" title="Notifications" aria-label="Notifications" style="position:relative;">
        <i class="ri-notification-3-line"></i>
        <?php if($notif_has_unread): ?>
        <span style="
            position:absolute;
            top:2px; right:2px;
            width:8px; height:8px;
            background:#FF2D2D;
            border-radius:50%;
            border:1.5px solid #06071A;
        "></span>
        <?php endif; ?>
    </a>
    <button type="button" class="dz-pt-btn dz-pt-kebab" aria-label="Menu" onclick="openKebabModal()">
        <i class="ri-menu-line"></i>
    </button>
<?php else: ?>
    <!-- Default actions: visible until the tabs bar docks under this topbar
         AND a Track button exists to replace them. If already tracking,
         these simply stay visible at all scroll positions. -->
    <div class="dz-pt-default-actions">
        <a href="search.php?focus=1" class="dz-pt-btn dz-pt-search" aria-label="Search">
            <i class="ri-search-line"></i>
        </a>
        <button type="button" class="dz-pt-btn dz-pt-kebab" aria-label="Menu" onclick="openProfileKebabModal()">
            <i class="ri-menu-line"></i>
        </button>
    </div>

    <?php if($topbar_show_track): ?>
    <!-- Track button: hidden until the tabs bar docks, then replaces the actions above -->
    <div class="dz-pt-track-wrap">
        <?php if($topbar_is_private && $topbar_req_status === 'pending'): ?>
            <button type="button" class="dz-pt-track-btn dz-pt-track-pending" id="dzTopbarTrackBtn" disabled>
                Requested
            </button>
        <?php else: ?>
            <button type="button"
                    class="dz-pt-track-btn"
                    id="dzTopbarTrackBtn"
                    data-private="<?= $topbar_is_private ? '1' : '0' ?>"
                    data-target="<?= (int)$profile_id ?>"
                    onclick="dzTopbarTrack(this)">
                Track
            </button>
        <?php endif; ?>
    </div>
    <?php endif; ?>
<?php endif; ?>
    </div>
</div>

<style>
/* ============================================================
   MOBILE-ONLY FIXED PROFILE TOPBAR
   Hidden on desktop/laptop. Only shows ≤770px (and scales down
   further at 490/380/330). position:fixed = never scrolls away.
   ============================================================ */
.dz-profile-topbar {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 99999;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 14px 16px;
    background: linear-gradient(-45deg,#070924,#070924);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
}

.dz-pt-side {
    display: flex;
    align-items: center;
    gap: 10px;
    flex: 0 0 auto;
    min-width: 34px;
}
.dz-pt-side.dz-pt-right { justify-content: flex-end; }

/* ── Title wrap: username (bigger) on top, nickname (smaller) below ── */
.dz-pt-title-wrap {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 0 8px;
    cursor: default;
    -webkit-tap-highlight-color: transparent;
    user-select: none;
}
.dz-pt-title-wrap[role="button"] {
    cursor: pointer;
}

.dz-pt-username {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 2px;
    max-width: 100%;
}
.dz-pt-username-text {
    font-size: 0.86rem;
    font-weight: 700;
    color: #fff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 100%;
}
.dz-pt-chevron {
    font-size: 15px;
    color: rgba(255,255,255,0.45);
    flex-shrink: 0;
    transition: transform 0.25s ease, color 0.2s ease;
}
.dz-pt-title-wrap[role="button"]:active .dz-pt-chevron {
    color: rgba(255,255,255,0.75);
}

.dz-pt-nickname {
    font-size: 0.68rem;
    font-weight: 500;
    color: rgba(255,255,255,0.5);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 100%;
    margin-top: 1px;
    line-height: 1.2;
}

.dz-pt-btn {
    background: none;
    border: none;
    color: #fff;
    font-size: 1.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    padding: 2px;
    opacity: 0.9;
    transition: opacity 0.2s, color 0.2s;
    text-decoration: none;
    -webkit-tap-highlight-color: transparent;
}
.dz-pt-btn:hover, .dz-pt-btn:active { opacity: 1; color: #FF2D2D; }
.dz-pt-btn.dz-pt-search { font-size: 1.2rem; }
.dz-pt-btn.dz-pt-memepedia { font-size: 1.3rem; }
.dz-pt-btn.dz-pt-back { font-size: 1.9rem; } /* ← bigger back arrow */

/* Show it only on the four requested breakpoints, hidden on desktop */
@media (max-width: 770px) {
    .dz-profile-topbar { display: flex; }

    /* Hide old duplicate mobile controls now replaced by the topbar */
    .profile-link          { display: none !important; }
    .profile-back-btn      { display: none !important; }
    .profile-search-btn    { display: none !important; }
    .profile-top-btns      { display: none !important; }
    .top-right-tools       { display: none !important; }
    .profile-kebab-btn     { display: none !important; }
}
@media (max-width: 770px) { .container { padding-top: 66px !important; } }
@media (max-width: 490px) {
    .dz-profile-topbar { padding: 12px 12px; }
    .dz-pt-username-text { font-size: 0.78rem; }
    .dz-pt-nickname { font-size: 0.62rem; }
    .dz-pt-chevron { font-size: 13px; }
    .dz-pt-btn { font-size: 1.35rem; }
    .dz-pt-btn.dz-pt-back { font-size: 1.75rem; }
    .container { padding-top: 58px !important; }
}
@media (max-width: 380px) {
    .dz-profile-topbar { padding: 10px 10px; }
    .dz-pt-username-text { font-size: 0.72rem; }
    .dz-pt-nickname { font-size: 0.58rem; }
    .dz-pt-chevron { font-size: 12px; }
    .dz-pt-btn { font-size: 1.25rem; }
    .dz-pt-btn.dz-pt-back { font-size: 1.6rem; }
    .container { padding-top: 54px !important; }
}
@media (max-width: 330px) {
    .dz-profile-topbar { padding: 9px 9px; }
    .dz-pt-username-text { font-size: 0.66rem; }
    .dz-pt-nickname { font-size: 0.55rem; }
    .dz-pt-chevron { font-size: 11px; }
    .dz-pt-btn { font-size: 1.15rem; }
    .dz-pt-btn.dz-pt-back { font-size: 1.45rem; }
    .container { padding-top: 50px !important; }
}

/* ── Topbar Track/Search toggle ── */
.dz-pt-default-actions{
    display: flex;
    align-items: center;
    gap: 10px;
}
.dz-pt-track-wrap{
    display: none;
    align-items: center;
}

/* Only swap search/menu → Track when the tabs are stuck AND
   a Track button actually exists (i.e. topbar isn't marked dz-no-track).
   If already tracking, .dz-no-track blocks this rule entirely, so
   search + menu stay visible at every scroll position. */
.dz-profile-topbar.dz-tabs-stuck:not(.dz-no-track) .dz-pt-default-actions{
    display: none;
}
.dz-profile-topbar.dz-tabs-stuck:not(.dz-no-track) .dz-pt-track-wrap{
    display: flex;
}

.dz-pt-track-btn{
    background: rgba(255,45,45,0.1);
    border: 1.5px solid rgba(255,45,45,0.55);
    color: #FF2D2D;
    font-size: 12px;
    font-weight: 700;
    font-family: 'Poppins', sans-serif;
    padding: 5px 14px;
    border-radius: 999px;
    cursor: pointer;
    white-space: nowrap;
    transition: background .2s, transform .15s;
    -webkit-tap-highlight-color: transparent;
}
.dz-pt-track-btn:active{ transform: scale(0.94); }
.dz-pt-track-btn.dz-pt-track-pending{
    background: rgba(255,255,255,0.06);
    border-color: rgba(255,255,255,0.15);
    color: rgba(255,255,255,0.45);
    cursor: default;
}
@media (max-width:490px){ .dz-pt-track-btn{ font-size:11px; padding:4px 11px; } }
@media (max-width:380px){ .dz-pt-track-btn{ font-size:10.5px; padding:4px 10px; } }
@media (max-width:330px){ .dz-pt-track-btn{ font-size:10px; padding:3px 9px; } }
</style>

<script>
// ── Detect when .tabs docks under the topbar (via sentinel above .tabs) ──
(function(){
    function initTabsStickyWatcher(){
        var sentinel = document.getElementById('dzTabsSentinel');
        var topbar   = document.querySelector('.dz-profile-topbar');
        if(!sentinel || !topbar || !('IntersectionObserver' in window)) return;

        var io = new IntersectionObserver(function(entries){
            entries.forEach(function(entry){
                // Sentinel scrolled out of view above the fold = tabs is now stuck
                topbar.classList.toggle('dz-tabs-stuck', !entry.isIntersecting);
            });
        }, { threshold: 0, rootMargin: '0px' });

        io.observe(sentinel);
    }

    if(document.readyState === 'loading'){
        document.addEventListener('DOMContentLoaded', initTabsStickyWatcher);
    } else {
        initTabsStickyWatcher();
    }
})();

// ── Sync the topbar chevron rotation with the #userKebab open/close state ──
// This does NOT redefine toggleUserKebab/closeUserKebab (those live in the
// main profile header script) — it just watches the kebab's "open" class
// and mirrors it onto the topbar's own chevron, so both chevrons rotate
// together no matter which trigger (header username or topbar) was tapped.
(function(){
    function initChevronSync(){
        var kebab = document.getElementById('userKebab');
        var chevron = document.getElementById('dzPtChevron');
        if(!kebab || !chevron) return;

        function sync(){
            var isOpen = kebab.classList.contains('open');
            chevron.style.transform = isOpen ? 'rotate(180deg)' : 'rotate(0deg)';
        }

        sync();

        var mo = new MutationObserver(sync);
        mo.observe(kebab, { attributes: true, attributeFilter: ['class'] });
    }

    if(document.readyState === 'loading'){
        document.addEventListener('DOMContentLoaded', initChevronSync);
    } else {
        initChevronSync();
    }
})();

function dzTopbarTrack(btn){
    var targetId  = btn.dataset.target;
    var isPrivate = btn.dataset.private === '1';
    btn.disabled = true;
    btn.textContent = isPrivate ? 'Requesting…' : 'Tracking…';

    if(isPrivate){
        // Private profile — send a track request
        fetch(window.location.pathname + window.location.search, {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: 'send_track_request=1'
        }).then(function(r){
            if(!r.ok) throw new Error('Request failed');
            // Reload so the rest of the page (pending state, bottom button) syncs
            window.location.reload();
        }).catch(function(err){
            console.error('Track request failed:', err);
            btn.disabled = false;
            btn.textContent = 'Track';
        });
    } else {
        // Public profile — follow immediately
        fetch(window.location.pathname + window.location.search, {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: 'action=toggle_follow&user_id=' + targetId
        })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if(d.status === 'ok' && d.following){
                // Reload so the bottom Track/Untrack button, follower count,
                // and everything else on the page reflects the new state.
                window.location.reload();
            } else {
                console.error('Follow did not succeed:', d);
                btn.disabled = false;
                btn.textContent = 'Track';
            }
        })
        .catch(function(err){
            console.error('Track request error:', err);
            btn.disabled = false;
            btn.textContent = 'Track';
        });
    }
}
</script>

<div class="container">

<div class="top-right-tools">
    <?php include 'profile_notifications.php'; ?>

    <?php if($current_user == $profile_id): ?>
<!-- Kebab Full-Screen Modal Trigger (Top Right) -->
<div class="kebab-container">
    <button class="kebab-btn" onclick="openKebabModal()"><i class="ri-menu-line"></i></button>
</div>

<style>
.top-right-tools{
    position: absolute;
    top: 105px;
    right: 55px;

    display: flex;
    align-items: center;
    gap: 12px; /* THIS is what makes them sit side by side */
}

/* 🌟 Kebab Button */
.kebab-btn {
    background: none;
    border: none;
    outline: none;
    box-shadow: none;
    -webkit-appearance: none;
    appearance: none;
    color: #fff;
    font-size: 21px;
    padding: 6px 12px;
    border-radius: 18px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.kebab-btn:hover {
    color: #ff2626;
}

/* Hide on mobile — replaced by the fixed profile topbar */
@media (max-width: 770px) {
    .top-right-tools { display: none !important; }
}


/* ══════════════════════════════════════
   KEBAB FULL-SCREEN MODAL
   Same background as the rest of profile.php
══════════════════════════════════════ */
.kbm-overlay {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 999999999999;
    font-family: 'Poppins', sans-serif;
    background: linear-gradient(-45deg,#010218,#070924,#070924);
    background-attachment: fixed;
    overflow-y: scroll;
    -webkit-overflow-scrolling: touch;
    opacity: 0;
    transform: translateY(18px);
    transition: opacity .25s ease, transform .28s cubic-bezier(.22,1,.36,1);
}

/* Custom scrollbar for laptop / desktop view — subtle grey, shows on hover */
.kbm-overlay::-webkit-scrollbar {
    width: 5px;
}
.kbm-overlay::-webkit-scrollbar-track {
    background: transparent;
}
.kbm-overlay::-webkit-scrollbar-thumb {
    background: rgba(255,255,255,0.08);
    border-radius: 10px;
}
.kbm-overlay:hover::-webkit-scrollbar-thumb {
    background: rgba(255,255,255,0.35);
}
.kbm-overlay::-webkit-scrollbar-thumb:hover {
    background: rgba(255,255,255,0.5);
}
.kbm-overlay {
    scrollbar-width: thin;
    scrollbar-color: rgba(255,255,255,0.08) transparent;
}
.kbm-overlay:hover {
    scrollbar-color: rgba(255,255,255,0.35) transparent;
}
.kbm-overlay.kbm-open {
    display: block;
    opacity: 1;
    transform: translateY(0);
}

.kbm-topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 18px;
    border-bottom: 1px solid rgba(255,255,255,0.07);
    position: sticky;
    top: 0;
    background: linear-gradient(-45deg,#010218,#070924,#070924);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    z-index: 3;
}

.kbm-back-btn {
    background: none;
    border: none;
    color: #fff;
    font-size: 30px;
    cursor: pointer;
    display: flex;
    align-items: center;
    line-height: 1;
    padding: 2px;
}

.kbm-topbar-title {
    font-size: 16px;
    font-weight: 700;
    color: #fff;
    letter-spacing: -0.2px;
}

.kbm-topbar-spacer {
    width: 30px;
    flex-shrink: 0;
}

.kbm-body {
    padding: 6px 0 50px;
    display: flex;
    flex-direction: column;
}

.kbm-row {
    padding: 15px 22px;
    color: white;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 14px;
    border-bottom: 1px solid rgba(255,255,255,0.045);
    transition: 0.2s;
}
.kbm-row:hover {
    background: rgba(255,255,255,0.06);
    color: #ff2626;
}
.kbm-row:hover i {
    transform: scale(1.2) rotate(10deg);
}
.kbm-row i {
    font-size: 19px;
    color: #FF2D2D;
    flex-shrink: 0;
    width: 22px;
    text-align: center;
    transition: transform 0.2s;
}

/* Responsive */
@media (max-width: 768px) {
    .kbm-topbar { padding: 13px 16px; }
    .kbm-topbar-title { font-size: 14.5px; }
    .kbm-row { padding: 13px 18px; font-size: 13.5px; gap: 11px; }
    .kbm-row i { font-size: 17px; }
}

@media (max-width: 480px) {
    .kbm-topbar { padding: 11px 14px; }
    .kbm-back-btn { font-size: 26px; }
    .kbm-topbar-title { font-size: 13.5px; }
    .kbm-row { padding: 12px 16px; font-size: 12.5px; gap: 10px; }
    .kbm-row i { font-size: 16px; }
}

@media (max-width: 380px) {
    .kbm-topbar { padding: 10px 12px; }
    .kbm-back-btn { font-size: 23px; }
    .kbm-topbar-title { font-size: 12.5px; }
    .kbm-row { padding: 10px 13px; font-size: 11.5px; gap: 8px; }
    .kbm-row i { font-size: 14px; width: 18px; }
}

@media (max-width: 360px) {
    .kbm-row { font-size: 11px; padding: 9px 12px; }
    .kbm-row i { font-size: 13.5px; width: 17px; }
}

@media (max-width: 330px) {
    .kbm-topbar { padding: 9px 10px; }
    .kbm-back-btn { font-size: 21px; }
    .kbm-topbar-title { font-size: 11.5px; }
    .kbm-row { font-size: 10.5px; padding: 8px 10px; gap: 7px; }
    .kbm-row i { font-size: 12.5px; width: 16px; }
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
</style>
<?php endif; ?>
</div><!-- closes .top-right-tools — now always closes, owner or not -->

<?php if($current_user == $profile_id): ?>
<!-- ══════════════════════════════════════
     FULL-SCREEN KEBAB MENU MODAL
     MOVED OUTSIDE .top-right-tools so mobile's
     "display:none !important" on .top-right-tools
     no longer hides this modal / breaks the topbar button.
══════════════════════════════════════ -->
<div id="kebabFullModal" class="kbm-overlay">
    <div class="kbm-topbar">
        <button class="kbm-back-btn" onclick="closeKebabModal()">
            <i class="ri-arrow-left-s-line"></i>
        </button>
        <div class="kbm-topbar-title">Menu</div>
        <div class="kbm-topbar-spacer"></div>
    </div>

    <div class="kbm-body">

        <!-- ══════════ SKELETON LOADER ══════════ -->
        <div id="kbmSkeleton" class="kbm-skeleton" aria-hidden="true">
            <?php for ($i = 0; $i < 11; $i++): ?>
            <div class="kbm-skel-row">
                <div class="kbm-skel-icon"></div>
                <div class="kbm-skel-text" style="width: <?php echo rand(55, 85); ?>%;"></div>
            </div>
            <?php endfor; ?>
        </div>

        <!-- ══════════ REAL MENU CONTENT ══════════ -->
        <div id="kbmContent" class="kbm-content">

            <a href="setting.php" class="kbm-row">
                <i class="ri-settings-3-line"></i> Your Settings
            </a>
            <a href="search.php?focus=1" class="kbm-row">
                <i class="ri-search-line"></i> Search
            </a>

            <a href="audio.php?user_id=<?php echo $profile_id; ?>" class="kbm-row">
                <i class="ri-play-list-line"></i> Your Sounds
            </a>
            <a href="profile_longform.php?user_id=<?php echo $profile_id; ?>" class="kbm-row">
                <i class="ri-video-line"></i> Your Videos
            </a>
            <a href="links.php?user_id=<?php echo $profile_id; ?>" class="kbm-row">
                <i class="ri-link-m"></i> Your Links
            </a>
            <a href="account.php?user_id=<?php echo $profile_id; ?>" class="kbm-row">
                <i class="ri-user-line"></i> About Account
            </a>

            <a href="#" onclick="closeKebabModal(); dzOpenAboutMe(<?php echo $profile_id; ?>); return false;" class="kbm-row">
                <i class="ri-user-heart-line"></i> More About You
            </a>

            <!-- UPLOADS - triggers grid dropdown -->
            <a href="#" onclick="
                closeKebabModal();
                toggleSection('uploads');
                return false;" class="kbm-row">
                <i class="ri-upload-2-line"></i> Your Uploads
            </a>
            <a href="#" onclick="closeKebabModal(); toggleSection('likes'); return false;" class="kbm-row">
                <i class="ri-heart-line"></i> Your Like History
            </a>
            <a href="#" onclick="shareProfile(<?php echo $profile_id; ?>)" class="kbm-row">
                <i class="ri-share-line"></i> Share Profile
            </a>
            <a href="#" onclick="copyProfileLink(<?php echo $profile_id; ?>); return false;" class="kbm-row">
                <i class="ri-file-copy-line"></i> Copy Profile Link
            </a>
            <a href="privacy_moderation.php" class="kbm-row">
                <i class="ri-shield-user-line"></i> Privacy & Moderation
            </a>

        </div>
    </div>
</div>

<style>
/* ══════════ SKELETON LOADER STYLES ══════════ */
.kbm-content {
    opacity: 0;
    transform: translateY(4px);
    pointer-events: none;
    transition: opacity 0.35s ease, transform 0.35s ease;
}
.kbm-content.kbm-content-ready {
    opacity: 1;
    transform: translateY(0);
    pointer-events: auto;
}

.kbm-skeleton {
    display: flex;
    flex-direction: column;
    transition: opacity 0.25s ease;
}
.kbm-skeleton.kbm-skel-hide {
    opacity: 0;
    pointer-events: none;
    position: absolute;
    inset: 0;
}

.kbm-skel-row {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 16px;
    box-sizing: border-box;
}

.kbm-skel-icon {
    width: 20px;
    height: 20px;
    flex-shrink: 0;
    border-radius: 6px;
    background: linear-gradient(
        90deg,
        rgba(255,255,255,0.06) 25%,
        rgba(255,255,255,0.14) 37%,
        rgba(255,255,255,0.06) 63%
    );
    background-size: 400% 100%;
    animation: kbmShimmer 1.4s ease-in-out infinite;
}

.kbm-skel-text {
    height: 14px;
    border-radius: 4px;
    background: linear-gradient(
        90deg,
        rgba(255,255,255,0.06) 25%,
        rgba(255,255,255,0.14) 37%,
        rgba(255,255,255,0.06) 63%
    );
    background-size: 400% 100%;
    animation: kbmShimmer 1.4s ease-in-out infinite;
}

@keyframes kbmShimmer {
    0%   { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
}

/* Stagger each row very slightly for a premium cascading feel */
.kbm-skel-row:nth-child(1)  .kbm-skel-icon, .kbm-skel-row:nth-child(1)  .kbm-skel-text { animation-delay: 0s; }
.kbm-skel-row:nth-child(2)  .kbm-skel-icon, .kbm-skel-row:nth-child(2)  .kbm-skel-text { animation-delay: 0.05s; }
.kbm-skel-row:nth-child(3)  .kbm-skel-icon, .kbm-skel-row:nth-child(3)  .kbm-skel-text { animation-delay: 0.1s; }
.kbm-skel-row:nth-child(4)  .kbm-skel-icon, .kbm-skel-row:nth-child(4)  .kbm-skel-text { animation-delay: 0.15s; }
.kbm-skel-row:nth-child(5)  .kbm-skel-icon, .kbm-skel-row:nth-child(5)  .kbm-skel-text { animation-delay: 0.2s; }
.kbm-skel-row:nth-child(6)  .kbm-skel-icon, .kbm-skel-row:nth-child(6)  .kbm-skel-text { animation-delay: 0.25s; }
.kbm-skel-row:nth-child(7)  .kbm-skel-icon, .kbm-skel-row:nth-child(7)  .kbm-skel-text { animation-delay: 0.3s; }
.kbm-skel-row:nth-child(8)  .kbm-skel-icon, .kbm-skel-row:nth-child(8)  .kbm-skel-text { animation-delay: 0.35s; }
.kbm-skel-row:nth-child(9)  .kbm-skel-icon, .kbm-skel-row:nth-child(9)  .kbm-skel-text { animation-delay: 0.4s; }
.kbm-skel-row:nth-child(10) .kbm-skel-icon, .kbm-skel-row:nth-child(10) .kbm-skel-text { animation-delay: 0.45s; }
.kbm-skel-row:nth-child(11) .kbm-skel-icon, .kbm-skel-row:nth-child(11) .kbm-skel-text { animation-delay: 0.5s; }
</style>

<script>
var kbmSkeletonShown = false; // tracks whether skeleton already played this page load

function openKebabModal() {
    var m = document.getElementById('kebabFullModal');
    if (!m) return;

    var skeleton = document.getElementById('kbmSkeleton');
    var content  = document.getElementById('kbmContent');

    m.style.display = 'block';
    document.body.style.overflow = 'hidden';
    requestAnimationFrame(function () {
        requestAnimationFrame(function () { m.classList.add('kbm-open'); });
    });

    if (kbmSkeletonShown) {
        // Already loaded once this page — skip straight to content, no shimmer
        if (skeleton) skeleton.classList.add('kbm-skel-hide');
        if (content)  content.classList.add('kbm-content-ready');
        return;
    }

    // First open this page load — show the skeleton
    if (skeleton) skeleton.classList.remove('kbm-skel-hide');
    if (content)  content.classList.remove('kbm-content-ready');

    // Reveal real content after a short "premium" load beat.
    // Swap this timeout for a real data-ready callback if the menu
    // ever depends on an async fetch (e.g. permissions/notification counts).
    var minDelay = 450;
    setTimeout(function () {
        if (skeleton) skeleton.classList.add('kbm-skel-hide');
        if (content)  content.classList.add('kbm-content-ready');
        kbmSkeletonShown = true;
    }, minDelay);
}

function closeKebabModal() {
    var m = document.getElementById('kebabFullModal');
    if (!m) return;
    m.classList.remove('kbm-open');
    setTimeout(function () {
        m.style.display = 'none';
        document.body.style.overflow = '';
    }, 270);
}

function copyProfileLink(profileId) {
    var link = window.location.origin + '/profile.php?user_id=' + profileId;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(link).then(function () {
            if (typeof showToast === 'function') {
                showToast('Profile link copied!');
            } else {
                alert('Profile link copied!');
            }
        }).catch(function () {
            alert('Could not copy link. Long-press the URL bar to copy manually.');
        });
    } else {
        var tempInput = document.createElement('input');
        tempInput.value = link;
        document.body.appendChild(tempInput);
        tempInput.select();
        document.execCommand('copy');
        document.body.removeChild(tempInput);
        alert('Profile link copied!');
    }
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeKebabModal();
});
</script>
<?php endif; ?>

<a href="profile.php?user_id=<?php echo $profile_id; ?>" class="profile-link">
    <div class="profile-name">
        <span style="margin-right:1px;">@</span><?php echo htmlspecialchars($display_name); ?>
    </div>
</a>

<div class="profile-header" style="display:flex; align-items:flex-start; gap:12px;">
<div style="display:flex; flex-direction:column; gap:4px; left: 20px;">

<?php
/*
=============================================================
  PROFILE PIC  +  FULL-SCREEN PLUS MODAL  —  drop into profile.php
  v3 — gradient bg, bare red text buttons, clean icons, tight responsive
=============================================================
*/

$profilePic = $user['profile_pic'] ?? '';
if (empty($profilePic)) $profilePic = 'uploads/mee.jpeg';

$pid = (int)($profile_id ?? $current_user);
$is_own_profile = ($pid === (int)$current_user);

$_pic_modal_allowed = true;
if ($pid != $current_user && !empty($settings['is_fully_private'])) {
    $pic_chk = $conn->prepare(
        "SELECT id FROM track_requests WHERE requester_id=? AND target_id=? AND status='approved'"
    );
    $pic_chk->bind_param("ii", $current_user, $pid);
    $pic_chk->execute();
    $_pic_modal_allowed = $pic_chk->get_result()->num_rows > 0;
    $pic_chk->close();
}

$current_bio      = htmlspecialchars((string)($settings['Bio'] ?? ''));
$current_username = htmlspecialchars((string)($user['username'] ?? ''));
$current_nickname = htmlspecialchars((string)($user['nickname'] ?? ''));
$current_pronouns = htmlspecialchars((string)($user['pronouns'] ?? $settings['pronouns'] ?? ''));
?>

<style>
/* ═══════════════════════════════════════════
   TRIGGER BUTTON
═══════════════════════════════════════════ */
.profile-main-pic.no-modal { cursor: default; }

.plus-trigger-btn {
    position: absolute;
    bottom: 2px; right: -1px;
    width: 26px; height: 26px;
    border-radius: 50%;
    background: #FF2D2D;
    border: 3.5px solid #070924;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; z-index: 10; padding: 0;
    transition: transform .18s ease, box-shadow .18s ease;
    -webkit-tap-highlight-color: transparent;
}
.plus-trigger-btn:hover { transform: scale(1.12); box-shadow: 0 4px 14px rgba(255,45,45,.7); }
.plus-trigger-btn svg { width: 12px; height: 12px; flex-shrink: 0; }
@media (max-width:480px){ .plus-trigger-btn{width:24px;height:24px;border: 3.5px solid #070924;} .plus-trigger-btn svg { width: 9px; height: 9px; }  .plus-trigger-btn svg path { stroke-width: 3.2; }}
@media (max-width:330px){ .plus-trigger-btn{width:21px;height:21px; border: 3px solid #070924;} .plus-trigger-btn svg { width: 7.5px; height: 7.5px; }}

/* ═══════════════════════════════════════════
   FULL-SCREEN OVERLAY BASE
═════════════════════════════════════════ */
.pm-overlay {
    display: none;
    position: fixed; inset: 0;
    z-index: 100000;
    font-family: 'Poppins', sans-serif;
    background: linear-gradient(-45deg, #010218, #070924, #070924);
    overflow-y: auto;
    overscroll-behavior: contain;
    opacity: 0;
    transform: translateY(18px);
    transition: opacity .25s ease, transform .28s cubic-bezier(.22,1,.36,1);
    -webkit-overflow-scrolling: touch;
}
.pm-overlay.pm-open {
    display: block;
    opacity: 1;
    transform: translateY(0);
}
.pm-overlay.pm-nested {
    z-index: 100001;
    transform: translateX(28px);
    transition: opacity .22s ease, transform .26s cubic-bezier(.22,1,.36,1);
}
.pm-overlay.pm-nested.pm-open { transform: translateX(0); }

/* ── Top bar ── */
.pm-topbar {
    display: flex; align-items: center; justify-content: space-between;
    padding: 15px 22px;
    border-bottom: 1px solid rgba(255,255,255,.06);
    position: sticky; top: 0;
    background: linear-gradient(-45deg, #010218, #070924, #070924);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    z-index: 500;
}
.pm-topbar-title {
    font-size: 15px; font-weight: 700; color: #fff; letter-spacing: -.2px;
}
.pm-back-btn {
    display: flex; align-items: center; gap: 4px;
    background: none; border: none;
    color: rgba(255,255,255,.5);
    font-size: 13px; font-family: 'Poppins', sans-serif;
    cursor: pointer; padding: 6px 0;
    transition: color .18s;
}
.pm-back-btn i { font-size: 19px; }
.pm-back-btn:hover { color: #fff; }

.pm-close-btn {
    width: 30px; height: 30px; border-radius: 50%;
    background: rgba(255,255,255,.06); border: none;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; color: rgba(255,255,255,.45); font-size: 17px;
    transition: background .18s, color .18s;
}
.pm-close-btn:hover { background: rgba(255,255,255,.12); color: #fff; }

/* ═══════════════════════════════════════════
   PROFILE PIC SECTION
═══════════════════════════════════════════ */
.pm-body { padding: 0 0 48px; }

.pm-pic-section {
    display: flex; flex-direction: column; align-items: center;
    padding: 30px 20px 22px;
    border-bottom: 1px solid rgba(255,255,255,.055);
}
.pm-pic-wrap {
    position: relative; width: 96px; height: 96px; margin-bottom: 14px;
}
.pm-pic-wrap img {
    width: 100%; height: 100%; border-radius: 50%; object-fit: cover;
    border: 2.5px solid rgba(255,45,45,.3);
    transition: border-color .2s;
}
.pm-pic-wrap:hover img { border-color: rgba(255,45,45,.55); }
.pm-pic-overlay {
    position: absolute; inset: 0; border-radius: 50%;
    background: rgba(0,0,0,.36);
    display: flex; align-items: center; justify-content: center;
    opacity: 0; transition: opacity .2s; pointer-events: none;
}
.pm-pic-wrap:hover .pm-pic-overlay { opacity: 1; }
.pm-pic-overlay i { font-size: 22px; color: #fff; }

/* ── Bare red word button ── */
.pm-word-btn {
    background: none; border: none; padding: 0;
    color: #FF2D2D;
    font-size: 13px; font-weight: 700; font-family: 'Poppins', sans-serif;
    cursor: pointer; letter-spacing: .15px;
    transition: color .18s, opacity .18s;
}
.pm-word-btn:hover { color: #ff5050; }
.pm-word-btn:active { opacity: .7; }

#pmPicFileInput { display: none; }

/* Preview */
.pm-preview-area {
    display: none; flex-direction: column; align-items: center;
    gap: 12px; margin-top: 18px; padding: 18px;
    background: rgba(255,255,255,.03);
    border: 1px solid rgba(255,255,255,.07);
    border-radius: 16px; width: 100%; max-width: 300px;
}
.pm-preview-area.visible { display: flex; }
.pm-preview-area img {
    width: 80px; height: 80px; border-radius: 50%; object-fit: cover;
    border: 2.5px solid rgba(255,45,45,.4);
}
.pm-preview-label {
    font-size: 10px; color: rgba(255,255,255,.35);
    text-transform: uppercase; letter-spacing: 1.1px; font-weight: 600;
}
.pm-preview-actions { display: flex; gap: 24px; }
.pm-preview-save-word {
    background: none; border: none; padding: 0;
    color: #FF2D2D; font-size: 13px; font-weight: 700;
    font-family: 'Poppins', sans-serif; cursor: pointer;
    transition: color .18s;
}
.pm-preview-save-word:hover { color: #ff5050; }
.pm-preview-cancel-word {
    background: none; border: none; padding: 0;
    color: rgba(255,255,255,.38); font-size: 13px; font-weight: 600;
    font-family: 'Poppins', sans-serif; cursor: pointer;
    transition: color .18s;
}
.pm-preview-cancel-word:hover { color: rgba(255,255,255,.65); }

/* ═══════════════════════════════════════════
   SECTION LABEL
═══════════════════════════════════════════ */
.pm-section-label {
    font-size: 9.5px; font-weight: 700; letter-spacing: 1.5px;
    text-transform: uppercase; color: rgba(255,255,255,.24);
    padding: 18px 22px 7px;
}

/* ═══════════════════════════════════════════
   EDIT ROWS
═══════════════════════════════════════════ */
.pm-edit-row {
    display: flex; align-items: center; gap: 14px;
    padding: 13px 22px;
    border-bottom: 1px solid rgba(255,255,255,.045);
    cursor: pointer; transition: background .18s;
    -webkit-tap-highlight-color: transparent;
}
.pm-edit-row:hover { background: rgba(255,255,255,.03); }
.pm-edit-row:active { background: rgba(255,255,255,.055); }

.pm-edit-icon {
    width: 36px; height: 36px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: 17px; color: #FF4040;
}

.pm-edit-meta { flex: 1; min-width: 0; }
.pm-edit-label { font-size: 13px; font-weight: 600; color: rgba(255,255,255,.88); }
.pm-edit-current {
    font-size: 11px; color: rgba(255,255,255,.3);
    margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}

.pm-edit-chevron { font-size: 17px; color: rgba(255,255,255,.18); transition: transform .18s, color .18s; }
.pm-edit-row:hover .pm-edit-chevron { transform: translateX(3px); color: rgba(255,255,255,.38); }

/* ═══════════════════════════════════════════
   ACTION ROWS
═══════════════════════════════════════════ */
.pm-action-row {
    display: flex; align-items: center; gap: 14px;
    padding: 13px 22px;
    border-bottom: 1px solid rgba(255,255,255,.045);
    text-decoration: none; cursor: pointer; transition: background .18s;
    -webkit-tap-highlight-color: transparent;
}
.pm-action-row:hover { background: rgba(255,255,255,.03); }
.pm-action-row:active { background: rgba(255,255,255,.055); }

.pm-action-icon {
    width: 36px; height: 36px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: 17px; color: #FF4040;
}

.pm-action-meta { flex: 1; min-width: 0; }
.pm-action-label { font-size: 13px; font-weight: 600; color: rgba(255,255,255,.88); }
.pm-action-sub   { font-size: 11px; color: rgba(255,255,255,.3); margin-top: 2px; }

.pm-action-chevron { font-size: 17px; color: rgba(255,255,255,.18); transition: transform .18s, color .18s; }
.pm-action-row:hover .pm-action-chevron { transform: translateX(3px); color: rgba(255,255,255,.38); }

#pmAiDisplayName { font-size: 13px; font-weight: 600; color: rgba(255,255,255,.88); }

/* ═══════════════════════════════════════════
   NESTED MODAL BODY
═══════════════════════════════════════════ */
.pm-nested-body {
    padding: 26px 22px 52px;
    display: flex; flex-direction: column; gap: 18px;
}

.pm-field-group { display: flex; flex-direction: column; gap: 8px; }

.pm-field-label {
    font-size: 10px; font-weight: 700; letter-spacing: 1px;
    text-transform: uppercase; color: rgba(255,255,255,.3);
}

.pm-field-input {
    width: 100%; padding: 13px 15px;
    border-radius: 13px;
    background: rgba(255,255,255,.045);
    border: 1px solid rgba(255,255,255,.09);
    color: #fff; font-family: 'Poppins', sans-serif;
    font-size: 14px; font-weight: 500; outline: none;
    transition: border-color .2s, box-shadow .2s, background .2s;
    box-sizing: border-box;
}
.pm-field-input:focus {
    border-color: rgba(255,45,45,.4);
    background: rgba(255,45,45,.04);
    box-shadow: 0 0 0 3px rgba(255,45,45,.09);
}
textarea.pm-field-input {
    min-height: 108px; resize: vertical; line-height: 1.55; font-size: 13px;
}

/* Availability */
.pm-avail-row { display: flex; align-items: center; gap: 7px; min-height: 17px; }
.pm-avail-indicator { font-size: 13px; }
.pm-avail-text { font-size: 12px; color: rgba(255,255,255,.36); }
.pm-avail-text.taken { color: #fb923c; }
.pm-avail-text.ok    { color: #6ee7b7; }

.pm-suggestions { display: flex; flex-wrap: wrap; gap: 6px; }
.pm-suggestion-chip {
    padding: 4px 11px; border-radius: 99px;
    background: rgba(255,45,45,.07);
    border: 1px solid rgba(255,45,45,.18);
    color: #ff7070; font-size: 11px; font-weight: 600;
    font-family: 'Poppins', sans-serif; cursor: pointer;
    transition: background .18s;
}
.pm-suggestion-chip:hover { background: rgba(255,45,45,.15); }

/* Info note */
.pm-info-note {
    background: rgba(255,255,255,.025);
    border: 1px solid rgba(255,255,255,.06);
    border-radius: 13px;
    padding: 13px 15px;
    font-size: 12px; color: rgba(255,255,255,.38); line-height: 1.65;
}
.pm-info-note strong { color: rgba(255,255,255,.6); font-weight: 600; }

.pm-char-count { font-size: 10px; color: rgba(255,255,255,.25); text-align: right; }

/* Save button — full-width red */
.pm-save-btn {
    width: 100%; padding: 13px; border-radius: 13px;
    background: #FF2D2D; border: none; color: #fff;
    font-size: 13px; font-weight: 700; font-family: 'Poppins', sans-serif;
    cursor: pointer; letter-spacing: .3px;
    transition: background .18s, box-shadow .2s, transform .15s;
}
.pm-save-btn:hover { background: #e62020; box-shadow: 0 4px 20px rgba(255,45,45,.35); }
.pm-save-btn:active { transform: scale(.98); }
.pm-save-btn:disabled { opacity: .5; pointer-events: none; }

/* ═══════════════════════════════════════════
   TOAST
═══════════════════════════════════════════ */
.pm-toast {
    position: fixed; bottom: 30px; left: 50%;
    transform: translateX(-50%) translateY(10px);
    background: rgba(8,8,22,.97);
    border: 1px solid rgba(255,45,45,.25);
    color: #fff; padding: 8px 20px; border-radius: 50px;
    font-size: 12px; font-family: 'Poppins', sans-serif; font-weight: 500;
    z-index: 999999; pointer-events: none;
    box-shadow: 0 8px 28px rgba(0,0,0,.65);
    white-space: nowrap; opacity: 0;
    transition: opacity .22s ease, transform .22s ease;
}
.pm-toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }

/* ═══════════════════════════════════════════
   RESPONSIVE — 790 / 490 / 380 / 330
═══════════════════════════════════════════ */

/* 790px — wider phones / small tablets */
@media (max-width: 790px) {
    .pm-topbar         { padding: 13px 18px; }
    .pm-topbar-title   { font-size: 14px; }
    .pm-nested-body    { padding: 22px 18px 44px; }
    .pm-edit-row,
    .pm-action-row     { padding: 12px 18px; gap: 13px; }
    .pm-pic-section    { padding: 26px 18px 20px; }
    .pm-pic-wrap       { width: 88px; height: 88px; margin-bottom: 12px; }
    .pm-section-label  { padding: 16px 18px 6px; }
}

/* 490px — typical phones */
@media (max-width: 490px) {
    .pm-topbar         { padding: 11px 16px; }
    .pm-topbar-title   { font-size: 13.5px; }
    .pm-back-btn       { font-size: 12px; }
    .pm-back-btn i     { font-size: 17px; }
    .pm-close-btn      { width: 27px; height: 27px; font-size: 15px; }

    .pm-pic-section    { padding: 22px 16px 18px; }
    .pm-pic-wrap       { width: 82px; height: 82px; margin-bottom: 11px; }
    .pm-word-btn       { font-size: 12.5px; }
    .pm-preview-area   { padding: 15px; gap: 10px; max-width: 280px; }
    .pm-preview-area img{ width: 72px; height: 72px; }
    .pm-preview-save-word,
    .pm-preview-cancel-word { font-size: 12px; }

    .pm-section-label  { font-size: 9px; letter-spacing: 1.3px; padding: 14px 16px 6px; }

    .pm-edit-row,
    .pm-action-row     { padding: 11px 16px; gap: 12px; }
    .pm-edit-icon,
    .pm-action-icon    { width: 32px; height: 32px; font-size: 15px; }
    .pm-edit-label,
    .pm-action-label   { font-size: 12.5px; }
    .pm-edit-current,
    .pm-action-sub     { font-size: 10.5px; }
    .pm-edit-chevron,
    .pm-action-chevron { font-size: 15px; }

    .pm-nested-body    { padding: 20px 16px 40px; gap: 15px; }
    .pm-field-label    { font-size: 9.5px; }
    .pm-field-input    { font-size: 13.5px; padding: 12px 14px; border-radius: 12px; }
    textarea.pm-field-input { font-size: 12.5px; min-height: 96px; }
    .pm-info-note      { font-size: 11.5px; padding: 12px 14px; border-radius: 12px; }
    .pm-save-btn       { padding: 12px; font-size: 12.5px; border-radius: 12px; }
    .pm-avail-text     { font-size: 11px; }
    .pm-suggestion-chip{ font-size: 10.5px; }
}

/* 380px — small phones */
@media (max-width: 380px) {
    .pm-topbar         { padding: 10px 14px; }
    .pm-topbar-title   { font-size: 13px; }
    .pm-back-btn       { font-size: 11.5px; }
    .pm-back-btn i     { font-size: 16px; }
    .pm-close-btn      { width: 25px; height: 25px; font-size: 14px; }

    .pm-pic-section    { padding: 18px 14px 16px; }
    .pm-pic-wrap       { width: 76px; height: 76px; margin-bottom: 10px; }
    .pm-word-btn       { font-size: 12px; }
    .pm-preview-area   { padding: 13px; gap: 9px; max-width: 264px; }
    .pm-preview-area img{ width: 64px; height: 64px; }

    .pm-section-label  { font-size: 8.5px; padding: 13px 14px 5px; }

    .pm-edit-row,
    .pm-action-row     { padding: 10px 14px; gap: 11px; }
    .pm-edit-icon,
    .pm-action-icon    { width: 30px; height: 30px; font-size: 14px; }
    .pm-edit-label,
    .pm-action-label   { font-size: 12px; }
    .pm-edit-current,
    .pm-action-sub     { font-size: 10px; }
    .pm-edit-chevron,
    .pm-action-chevron { font-size: 14px; }

    .pm-nested-body    { padding: 18px 14px 36px; gap: 14px; }
    .pm-field-label    { font-size: 9px; }
    .pm-field-input    { font-size: 13px; padding: 11px 13px; border-radius: 11px; }
    textarea.pm-field-input { min-height: 88px; }
    .pm-info-note      { font-size: 11px; padding: 11px 13px; }
    .pm-save-btn       { padding: 11px; font-size: 12px; border-radius: 11px; }
    .pm-avail-text     { font-size: 10.5px; }
    .pm-suggestion-chip{ font-size: 10px; padding: 3px 9px; }
}

/* 330px — very small phones */
@media (max-width: 330px) {
    .pm-topbar         { padding: 9px 12px; }
    .pm-topbar-title   { font-size: 12.5px; }
    .pm-back-btn       { font-size: 11px; }
    .pm-back-btn i     { font-size: 15px; }
    .pm-close-btn      { width: 23px; height: 23px; font-size: 13px; }

    .pm-pic-section    { padding: 16px 12px 14px; }
    .pm-pic-wrap       { width: 70px; height: 70px; margin-bottom: 9px; }
    .pm-word-btn       { font-size: 11.5px; }
    .pm-preview-area   { padding: 11px; gap: 8px; max-width: 240px; border-radius: 13px; }
    .pm-preview-area img{ width: 58px; height: 58px; }
    .pm-preview-save-word,
    .pm-preview-cancel-word { font-size: 11.5px; }

    .pm-section-label  { font-size: 8px; padding: 11px 12px 4px; }

    .pm-edit-row,
    .pm-action-row     { padding: 9px 12px; gap: 10px; }
    .pm-edit-icon,
    .pm-action-icon    { width: 27px; height: 27px; font-size: 13px; }
    .pm-edit-label,
    .pm-action-label   { font-size: 11.5px; }
    .pm-edit-current,
    .pm-action-sub     { font-size: 9.5px; }
    .pm-edit-chevron,
    .pm-action-chevron { font-size: 13px; }

    .pm-nested-body    { padding: 15px 12px 32px; gap: 12px; }
    .pm-field-label    { font-size: 8.5px; }
    .pm-field-input    { font-size: 12.5px; padding: 10px 12px; border-radius: 10px; }
    textarea.pm-field-input { min-height: 80px; }
    .pm-info-note      { font-size: 10.5px; padding: 10px 12px; border-radius: 10px; }
    .pm-save-btn       { padding: 10px; font-size: 11.5px; border-radius: 10px; }
    .pm-avail-indicator{ font-size: 12px; }
    .pm-avail-text     { font-size: 10px; }
    .pm-suggestion-chip{ font-size: 9.5px; padding: 3px 8px; }
}
</style>


<!-- ═══════════════════════════════════════
     PROFILE PIC WRAP
═══════════════════════════════════════ -->
<div class="profile-pic-wrap">
    <img src="<?= htmlspecialchars($profilePic) ?>"
         alt="Profile Pic"
         id="profilePic"
         class="profile-main-pic<?= !$_pic_modal_allowed ? ' no-modal' : '' ?>"
         onerror="this.src='uploads/mee.jpeg';">

    <?php if ($is_own_profile): ?>
    <button id="plusTriggerBtn" class="plus-trigger-btn"
            onclick="event.stopPropagation(); pmOpenMain()"
            aria-label="Quick actions">
        <svg viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M6 1V11M1 6H11" stroke="white" stroke-width="2.2" stroke-linecap="round"/>
        </svg>
    </button>
    <?php endif; ?>
</div>


<?php if ($is_own_profile): ?>

<!-- ═══════════════════════════════════════
     MAIN FULL-SCREEN MODAL
═══════════════════════════════════════ -->
<div id="pmMain" class="pm-overlay" role="dialog" aria-modal="true">

    <div class="pm-topbar">
        <div class="pm-topbar-title">Profile</div>
        <button class="pm-close-btn" onclick="pmClose('pmMain')" aria-label="Close">
            <i class="ri-close-line"></i>
        </button>
    </div>

    <div class="pm-body">

        <!-- Profile pic -->
        <div class="pm-pic-section">
            <div class="pm-pic-wrap">
                <img src="<?= htmlspecialchars($profilePic) ?>"
                     alt="Profile"
                     id="pmCurrentPicImg"
                     onerror="this.src='uploads/mee.jpeg';">
                <div class="pm-pic-overlay"><i class="ri-camera-line"></i></div>
            </div>

            <button class="pm-word-btn" onclick="document.getElementById('pmPicFileInput').click()">
                Change Profile Picture
            </button>

            <input type="file" id="pmPicFileInput" accept="image/*" onchange="pmHandlePicSelect(this)">

            <div id="pmPreviewArea" class="pm-preview-area">
                <span class="pm-preview-label">Preview</span>
                <img id="pmPreviewImg" src="" alt="Preview">
                <div class="pm-preview-actions">
                    <button class="pm-preview-save-word" onclick="pmSavePic(this)">Save Photo</button>
                    <button class="pm-preview-cancel-word" onclick="pmCancelPic()">Cancel</button>
                </div>
            </div>
        </div>

        <!-- Edit section -->
        <div class="pm-section-label">Edit Profile</div>

        <div class="pm-edit-row" onclick="pmOpenNested('pmEditUsername')">
            <div class="pm-edit-icon"><i class="ri-user-line"></i></div>
            <div class="pm-edit-meta">
                <div class="pm-edit-label">Edit Username</div>
                <div class="pm-edit-current" id="pmDispUsername">@<?= $current_username ?></div>
            </div>
            <i class="ri-arrow-right-s-line pm-edit-chevron"></i>
        </div>

        <div class="pm-edit-row" onclick="pmOpenNested('pmEditNickname')">
            <div class="pm-edit-icon"><i class="ri-text"></i></div>
            <div class="pm-edit-meta">
                <div class="pm-edit-label">Edit Nickname</div>
                <div class="pm-edit-current" id="pmDispNickname"><?= $current_nickname ?: 'Not set' ?></div>
            </div>
            <i class="ri-arrow-right-s-line pm-edit-chevron"></i>
        </div>

        <div class="pm-edit-row" onclick="pmOpenNested('pmEditBio')">
            <div class="pm-edit-icon"><i class="ri-edit-2-line"></i></div>
            <div class="pm-edit-meta">
                <div class="pm-edit-label">Edit Bio</div>
                <div class="pm-edit-current" id="pmDispBio"><?= $current_bio ?: 'Not set' ?></div>
            </div>
            <i class="ri-arrow-right-s-line pm-edit-chevron"></i>
        </div>

        <div class="pm-edit-row" onclick="pmOpenNested('pmEditPronouns')">
            <div class="pm-edit-icon"><i class="ri-hearts-line"></i></div>
            <div class="pm-edit-meta">
                <div class="pm-edit-label">Edit Pronouns</div>
                <div class="pm-edit-current" id="pmDispPronouns"><?= $current_pronouns ?: 'Not set' ?></div>
            </div>
            <i class="ri-arrow-right-s-line pm-edit-chevron"></i>
        </div>

        <!-- Quick actions -->
        <div class="pm-section-label">Quick Actions</div>

        <a href="uploads.php" onclick="pmClose('pmMain')" class="pm-action-row">
            <div class="pm-action-icon"><i class="ri-upload-cloud-line"></i></div>
            <div class="pm-action-meta">
                <div class="pm-action-label">Create / Upload</div>
                <div class="pm-action-sub">Post a new meme or video</div>
            </div>
            <i class="ri-arrow-right-s-line pm-action-chevron"></i>
        </a>

        <a href="money.php" onclick="pmClose('pmMain')" class="pm-action-row">
            <div class="pm-action-icon"><i class="ri-megaphone-line"></i></div>
            <div class="pm-action-meta">
                <div class="pm-action-label">Elevate Brand</div>
                <div class="pm-action-sub">Promote your brand or content</div>
            </div>
            <i class="ri-arrow-right-s-line pm-action-chevron"></i>
        </a>

        <a href="chatbot.php" onclick="pmClose('pmMain')" class="pm-action-row">
            <div class="pm-action-icon"><i class="ri-robot-line"></i></div>
            <div class="pm-action-meta">
                <div id="pmAiDisplayName" class="pm-action-label">Your AI</div>
                <div class="pm-action-sub">Open AI chatbot</div>
            </div>
            <i class="ri-arrow-right-s-line pm-action-chevron"></i>
        </a>

    </div>
</div>


<!-- ═══════════════════════════════════════
     NESTED: EDIT USERNAME
═══════════════════════════════════════ -->
<div id="pmEditUsername" class="pm-overlay pm-nested">
    <div class="pm-topbar">
        <button class="pm-back-btn" onclick="pmCloseNested('pmEditUsername')">
            <i class="ri-arrow-left-s-line"></i> Back
        </button>
        <div class="pm-topbar-title">Edit Username</div>
        <div style="width:52px;"></div>
    </div>
    <div class="pm-nested-body">
        <div class="pm-field-group">
            <label class="pm-field-label">Username</label>
            <input type="text" id="pmUsernameInput" class="pm-field-input"
                   value="<?= $current_username ?>" maxlength="30" autocomplete="off"
                   oninput="pmCheckAvail('username', this.value)">
            <div class="pm-avail-row">
                <span id="pmUsernameAvailIcon" class="pm-avail-indicator"></span>
                <span id="pmUsernameAvailText" class="pm-avail-text"></span>
            </div>
            <div id="pmUsernameSuggestions" class="pm-suggestions"></div>
        </div>
        <div class="pm-info-note">
            <strong>Dezzme allows you to change your username</strong> — because your identity is completely yours. Pick something that feels authentic to who you are right now. Your followers stay connected and your past posts remain linked to your account.
        </div>
        <button class="pm-save-btn"
                onclick="pmSaveField('username', document.getElementById('pmUsernameInput').value, this, 'pmEditUsername')">
            Save Username
        </button>
    </div>
</div>


<!-- ═══════════════════════════════════════
     NESTED: EDIT NICKNAME
═══════════════════════════════════════ -->
<div id="pmEditNickname" class="pm-overlay pm-nested">
    <div class="pm-topbar">
        <button class="pm-back-btn" onclick="pmCloseNested('pmEditNickname')">
            <i class="ri-arrow-left-s-line"></i> Back
        </button>
        <div class="pm-topbar-title">Edit Nickname</div>
        <div style="width:52px;"></div>
    </div>
    <div class="pm-nested-body">
        <div class="pm-field-group">
            <label class="pm-field-label">Nickname</label>
            <input type="text" id="pmNicknameInput" class="pm-field-input"
                   value="<?= $current_nickname ?>" maxlength="40" autocomplete="off"
                   oninput="pmCheckAvail('nickname', this.value)">
            <div class="pm-avail-row">
                <span id="pmNicknameAvailIcon" class="pm-avail-indicator"></span>
                <span id="pmNicknameAvailText" class="pm-avail-text"></span>
            </div>
            <div id="pmNicknameSuggestions" class="pm-suggestions"></div>
        </div>
        <div class="pm-info-note">
            <strong>Dezzme lets you set a display nickname</strong> — the name shown on your profile and across your posts. It can be your real name, a title, or anything that represents how you want to be known. Unlike your username, it does not need to be unique.
        </div>
        <button class="pm-save-btn"
                onclick="pmSaveField('nickname', document.getElementById('pmNicknameInput').value, this, 'pmEditNickname')">
            Save Nickname
        </button>
    </div>
</div>


<!-- ═══════════════════════════════════════
     NESTED: EDIT BIO
═══════════════════════════════════════ -->
<div id="pmEditBio" class="pm-overlay pm-nested">
    <div class="pm-topbar">
        <button class="pm-back-btn" onclick="pmCloseNested('pmEditBio')">
            <i class="ri-arrow-left-s-line"></i> Back
        </button>
        <div class="pm-topbar-title">Edit Bio</div>
        <div style="width:52px;"></div>
    </div>
    <div class="pm-nested-body">
        <div class="pm-field-group">
            <label class="pm-field-label">Bio <span style="font-weight:400;text-transform:none;letter-spacing:0;color:rgba(255,255,255,.2);">· max 600 chars</span></label>
            <textarea id="pmBioInput" class="pm-field-input" maxlength="600"
                      oninput="document.getElementById('pmBioCount').textContent = this.value.length + '/600'"><?= $current_bio ?></textarea>
            <div class="pm-char-count" id="pmBioCount"><?= strlen(strip_tags($current_bio)) ?>/600</div>
        </div>
        <div class="pm-info-note">
            <strong>Dezzme gives you space to express yourself</strong> — your bio is the first thing people read on your profile. Be real, be brief, be you. Update it whenever your vibe changes.
        </div>
        <button class="pm-save-btn"
                onclick="pmSaveField('bio', document.getElementById('pmBioInput').value, this, 'pmEditBio')">
            Save Bio
        </button>
    </div>
</div>


<!-- ═══════════════════════════════════════
     NESTED: EDIT PRONOUNS
═══════════════════════════════════════ -->
<div id="pmEditPronouns" class="pm-overlay pm-nested">
    <div class="pm-topbar">
        <button class="pm-back-btn" onclick="pmCloseNested('pmEditPronouns')">
            <i class="ri-arrow-left-s-line"></i> Back
        </button>
        <div class="pm-topbar-title">Edit Pronouns</div>
        <div style="width:52px;"></div>
    </div>
    <div class="pm-nested-body">
        <div class="pm-field-group">
            <label class="pm-field-label">Pronouns</label>
            <input type="text" id="pmPronounsInput" class="pm-field-input"
                   value="<?= $current_pronouns ?>" maxlength="40"
                   placeholder="e.g. he/him, she/her, they/them"
                   autocomplete="off">
        </div>
        <div class="pm-info-note">
            <strong>Dezzme respects your identity</strong> — your pronouns will appear on your profile alongside your name so others know how to refer to you. This is always optional and entirely your choice.
        </div>
        <button class="pm-save-btn"
                onclick="pmSaveField('pronouns', document.getElementById('pmPronounsInput').value, this, 'pmEditPronouns')">
            Save Pronouns
        </button>
    </div>
</div>


<div id="pmToast" class="pm-toast"></div>


<!-- ═══════════════════════════════════════
     JAVASCRIPT
═══════════════════════════════════════ -->
<script>
(function () {

    /* AI name */
    var AI_KEY = 'dezzme_ai_name_<?= (int)$current_user ?>';
    (function () {
        var name = localStorage.getItem(AI_KEY) || 'Your AI';
        var el = document.getElementById('pmAiDisplayName');
        if (el) el.textContent = name;
    })();

    /* ── Open / Close ── */
    window.pmOpenMain = function () {
        var el = document.getElementById('pmMain');
        if (!el) return;
        el.style.display = 'block';
        document.body.style.overflow = 'hidden';
        requestAnimationFrame(function () {
            requestAnimationFrame(function () { el.classList.add('pm-open'); });
        });
    };

    window.pmClose = function (id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.classList.remove('pm-open');
        setTimeout(function () {
            el.style.display = 'none';
            document.body.style.overflow = '';
        }, 270);
    };

    window.pmOpenNested = function (id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.style.display = 'block';
        requestAnimationFrame(function () {
            requestAnimationFrame(function () { el.classList.add('pm-open'); });
        });
    };

    window.pmCloseNested = function (id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.classList.remove('pm-open');
        setTimeout(function () { el.style.display = 'none'; }, 260);
    };

    /* ESC */
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var nested = document.querySelectorAll('.pm-overlay.pm-nested.pm-open');
        if (nested.length) {
            window.pmCloseNested(nested[nested.length - 1].id);
        } else {
            window.pmClose('pmMain');
        }
    });

    /* Swipe-down-to-close on main */
    var mainEl = document.getElementById('pmMain'), swipeY = 0;
    if (mainEl) {
        mainEl.addEventListener('touchstart', function (e) { swipeY = e.touches[0].clientY; }, { passive: true });
        mainEl.addEventListener('touchend',   function (e) {
            if (e.changedTouches[0].clientY - swipeY > 80) window.pmClose('pmMain');
        }, { passive: true });
    }

    /* ── Profile pic ── */
    var _picFile = null;

    window.pmHandlePicSelect = function (input) {
        var f = input.files[0];
        if (!f) return;
        if (!f.type.startsWith('image/')) { pmShowToast('Please pick an image file'); return; }
        _picFile = f;
        var r = new FileReader();
        r.onload = function (e) {
            document.getElementById('pmPreviewImg').src = e.target.result;
            document.getElementById('pmPreviewArea').classList.add('visible');
        };
        r.readAsDataURL(f);
    };

    window.pmCancelPic = function () {
        _picFile = null;
        document.getElementById('pmPreviewArea').classList.remove('visible');
        document.getElementById('pmPreviewImg').src = '';
        document.getElementById('pmPicFileInput').value = '';
    };

    window.pmSavePic = function (btn) {
        if (!_picFile) return;
        var orig = btn.textContent;
        btn.textContent = 'Saving…'; btn.disabled = true;
        var fd = new FormData();
        fd.append('action', 'update_profile_pic');
        fd.append('profile_pic_file', _picFile);
        fetch(window.location.pathname, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            btn.disabled = false;
            if (d.success && d.url) {
                var m = document.getElementById('pmCurrentPicImg');
                var t = document.getElementById('profilePic');
                if (m) m.src = d.url;
                if (t) t.src = d.url;
                pmCancelPic();
                btn.textContent = orig;
                pmShowToast('Profile pic updated ✓');
            } else {
                btn.textContent = orig;
                pmShowToast(d.msg || 'Could not save — try again');
            }
        })
        .catch(function () {
            btn.disabled = false; btn.textContent = orig;
            pmShowToast('Network error — check connection');
        });
    };

    /* ── Availability check ── */
    var _avT = {};
    window.pmCheckAvail = function (field, val) {
        clearTimeout(_avT[field]);
        var f = field.charAt(0).toUpperCase() + field.slice(1);
        var iconEl = document.getElementById('pm' + f + 'AvailIcon');
        var textEl = document.getElementById('pm' + f + 'AvailText');
        var sugEl  = document.getElementById('pm' + f + 'Suggestions');
        if (!iconEl) return;
        if (val.length < 2) {
            iconEl.textContent = ''; textEl.textContent = '';
            if (sugEl) sugEl.innerHTML = ''; return;
        }
        iconEl.textContent = '⏳'; textEl.textContent = ''; textEl.className = 'pm-avail-text';
        _avT[field] = setTimeout(function () {
            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'check_availability=1&field=' + encodeURIComponent(field) + '&value=' + encodeURIComponent(val)
            })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (sugEl) sugEl.innerHTML = '';
                if (d.status === 'available') {
                    iconEl.textContent = '✅'; textEl.textContent = 'Available'; textEl.className = 'pm-avail-text ok';
                } else if (d.status === 'taken') {
                    iconEl.textContent = '❌'; textEl.textContent = 'Already taken — try one of these:'; textEl.className = 'pm-avail-text taken';
                    (d.suggestions || []).forEach(function (s) {
                        var c = document.createElement('button');
                        c.className = 'pm-suggestion-chip'; c.textContent = s;
                        c.onclick = function () {
                            var inp = document.getElementById('pm' + f + 'Input');
                            if (inp) { inp.value = s; window.pmCheckAvail(field, s); }
                        };
                        if (sugEl) sugEl.appendChild(c);
                    });
                } else if (d.status === 'short') {
                    iconEl.textContent = ''; textEl.textContent = 'Too short'; textEl.className = 'pm-avail-text taken';
                }
            });
        }, 480);
    };

    /* ── Save field ── */
    window.pmSaveField = function (field, value, btn, modalId) {
        var orig = btn.textContent;
        btn.textContent = 'Saving…'; btn.disabled = true;
        fetch('ajax_update_user.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'field=' + encodeURIComponent(field) + '&value=' + encodeURIComponent(value)
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            btn.disabled = false;
            if (d.success) {
                btn.textContent = 'Saved ✓';
                var map = { username:'pmDispUsername', nickname:'pmDispNickname', bio:'pmDispBio', pronouns:'pmDispPronouns' };
                var el = document.getElementById(map[field]);
                if (el) el.textContent = field === 'username' ? '@' + value : (value || 'Not set');
                pmShowToast(field.charAt(0).toUpperCase() + field.slice(1) + ' updated ✓');
                setTimeout(function () { btn.textContent = orig; window.pmCloseNested(modalId); }, 900);
            } else {
                btn.textContent = orig;
                pmShowToast('Error: ' + (d.msg || 'Could not save'));
            }
        })
        .catch(function () {
            btn.disabled = false; btn.textContent = orig;
            pmShowToast('Network error — try again');
        });
    };

    /* ── Toast ── */
    window.pmShowToast = function (msg) {
        var t = document.getElementById('pmToast');
        if (!t) return;
        t.textContent = msg; t.classList.add('show');
        clearTimeout(t._tid);
        t._tid = setTimeout(function () { t.classList.remove('show'); }, 2600);
    };

})();
</script>

<?php endif; /* is_own_profile */ ?>

<!-- Modal -->
<div id="picModal" class="modal">
  <span class="close">&times;</span>
  <img class="modal-content" id="modalImg">

  <div class="modal-actions">

    <a href="#" onclick="vibeCheck(<?php echo $profile_id; ?>); return false;" class="modal-action-btn">
      <div class="modal-action-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M12 3l1.8 4.2L18 9l-4.2 1.8L12 15l-1.8-4.2L6 9l4.2-1.8L12 3z"/>
          <path d="M5 16l.8 2.2L8 19l-2.2.8L5 22l-.8-2.2L2 19l2.2-.8L5 16z"/>
          <path d="M19 14l1 2.5L22.5 17 20 18l-1 2.5L18 18l-2.5-1 2.5-.5L19 14z"/>
        </svg>
      </div>
      <span>Vibe Check</span>
    </a>

    <a href="#" onclick="shareProfile(<?php echo $profile_id; ?>); return false;" class="modal-action-btn">
      <div class="modal-action-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/>
          <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/>
          <line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>
        </svg>
      </div>
      <span>Share</span>
    </a>

    <a href="#" onclick="copyProfileLink(<?php echo $profile_id; ?>); return false;" class="modal-action-btn">
      <div class="modal-action-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
          <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
        </svg>
      </div>
      <span>Copy Link</span>
    </a>

    <a href="#" onclick="showQR(<?php echo $profile_id; ?>); return false;" class="modal-action-btn">
      <div class="modal-action-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
          <rect x="3" y="14" width="7" height="7"/>
          <rect x="5" y="5" width="3" height="3" fill="currentColor" stroke="none"/>
          <rect x="16" y="5" width="3" height="3" fill="currentColor" stroke="none"/>
          <rect x="5" y="16" width="3" height="3" fill="currentColor" stroke="none"/>
          <line x1="14" y1="14" x2="17" y2="14"/><line x1="17" y1="14" x2="17" y2="17"/>
          <line x1="17" y1="17" x2="21" y2="17"/><line x1="21" y1="17" x2="21" y2="21"/>
          <line x1="14" y1="21" x2="17" y2="21"/><line x1="14" y1="17" x2="14" y2="21"/>
        </svg>
      </div>
      <span>QR Code</span>
    </a>
</div>

<?php
$blocked_check = false;

if (isset($viewer_id, $profile_id)) {

    $stmt = $conn->prepare("
        SELECT id 
        FROM blocks
        WHERE blocker_id = ?
        AND blocked_id = ?
        LIMIT 1
    ");

    $stmt->bind_param("ii", $profile_id, $viewer_id);
    $stmt->execute();
    $stmt->store_result();

    $blocked_check = $stmt->num_rows > 0;

    $stmt->close();
}
?>

<div class="other-accounts-inline">
    <?php if(!empty($other_accounts)): ?>
        <span class="label">
    <span class="label-full">Other Accounts:</span>
    <span class="label-short">Other Accounts: </span>

        <?php
        $accounts = explode(',', $other_accounts);

        foreach($accounts as $acc){
            $acc = trim($acc);
            if($acc){

                $clean = ltrim($acc, '@');

                // 🔥 NOW SEARCH BOTH username AND nickname
                $res = $conn->query("
                    SELECT id, username, nickname 
                    FROM users 
                    WHERE username = '".$conn->real_escape_string($clean)."'
                    OR nickname = '".$conn->real_escape_string($clean)."'
                    LIMIT 1
                ");

                if($row = $res->fetch_assoc()){
                    $user_id = $row['id'];


                    // Decide behavior first (unchanged logic)
if($blocked_check){
    $link = '#';
    $onclick = "alert('You are blocked, you cannot view profiles.'); return false;";
} elseif(!$can_view){
    $link = '#';
    $onclick = "alert('Track this Peumer to view their accounts.'); return false;";
} else {
    $link = "profile.php?user_id=".$user_id;
    $onclick = "";
}

// Always display exactly what the user typed — never override with nickname
echo '<a href="'.$link.'" class="other-account-link"'
 .($onclick ? ' onclick="'.$onclick.'"' : '')
 .'>'
 .'<span class="account-handle">'.htmlspecialchars($acc).'</span>'
 .'</a> ';
                }
            }
        }
        ?>
    <?php endif; ?>
    </div>
    
<!-- Vibe Check Popup -->
<div id="vibePopup">
  <div class="vibe-sheet">

    <div class="vibe-handle"></div>

    <p class="vibe-title">✨ Vibe Check</p>
    <p id="vibeSubText" class="vibe-sub"></p>

    <div id="vibeVoteSection">
      <p class="vibe-section-label">What vibe does this profile give?</p>
      <div class="vibe-grid">
        <button onclick="submitVibe('Main character 🔥')"              class="vibe-opt">Main character 🔥</button>
        <button onclick="submitVibe('Mysterious 🌙')"                  class="vibe-opt">Mysterious 🌙</button>
        <button onclick="submitVibe('Looks like a problem 😭')"        class="vibe-opt">Looks like a problem 😭</button>
        <button onclick="submitVibe('Chaos energy ⚡')"                class="vibe-opt">Chaos energy ⚡</button>
        <button onclick="submitVibe('Chill but dangerous 😈')"         class="vibe-opt">Chill but dangerous 😈</button>
        <button onclick="submitVibe('Unbothered elite 😌')"            class="vibe-opt">Unbothered 😌</button>
        <button onclick="submitVibe('Knows they\'re attractive 😭')"   class="vibe-opt">Knows they're attractive 😭</button>
        <button onclick="submitVibe('Underrated legend 👑')"           class="vibe-opt">Underrated legend 👑</button>
        <button onclick="submitVibe('Hot mess 🔥')"                    class="vibe-opt">Hot ass mess 🔥</button>
        <button onclick="submitVibe('Unreachable energy 📶')"          class="vibe-opt">Unreachable energy 📶</button>
        <button onclick="submitVibe('Soft life era 🌸')"               class="vibe-opt">Soft life era 🌸</button>
        <button onclick="submitVibe('Don\'t text them first 🚫📱')"    class="vibe-opt">Don't text them first 🚫📱</button>
        <button onclick="submitVibe('Quiet luxury 🖤')"                class="vibe-opt">Quiet luxury 🖤</button>
        <button onclick="submitVibe('Probably toxic (don\'t trust) 🚩')" class="vibe-opt">Probably toxic (don't trust) 🚩</button>
        <button onclick="submitVibe('Gym bro / gym menace 🏋️')"       class="vibe-opt">Gym bro / gym menace 🏋️</button>
        <button onclick="submitVibe('Will knock out your teeth 😭')"   class="vibe-opt">Will knock out your teeth 😭</button>
        <button onclick="submitVibe('Bookworm energy 📚')"             class="vibe-opt">Bookworm energy 📚</button>
        <button onclick="submitVibe('Thinks they\'re all that 😏')"    class="vibe-opt">Thinks they're all that 😏</button>
        <button onclick="submitVibe('Would argue and win 💀')"         class="vibe-opt">Would argue and win 💀</button>
        <button onclick="submitVibe('Always in drama somehow 🎭')"     class="vibe-opt">Always in drama somehow 🎭</button>
        <button onclick="submitVibe('Acts innocent but isn\'t 😭')"    class="vibe-opt">Acts innocent but isn't 😭</button>
        <button onclick="submitVibe('Walking ego boost 📈')"           class="vibe-opt">Walking ego boost 📈</button>
      </div>
    </div>

    <div id="vibeTopResult" class="vibe-result">
      <p class="vibe-result-label">
        <?php echo ($profile_id == ($_SESSION['user_id'] ?? 0))
            ? "Your Vibe Is"
            : htmlspecialchars($user['username']) . "'s Vibe Is Giving"; ?>
      </p>
      <p id="vibeTopText"   class="vibe-result-text"></p>
      <p id="vibeTotalText" class="vibe-result-total"></p>
    </div>

    <button onclick="closeVibePopup()" class="vibe-close-btn">Close</button>

  </div>
</div>
</div>
<style>
/* ════════════════════════════════════════════════════════════
   PROFILE PAGE — FULL CSS
   1. Profile Pic & Status
   2. Profile Buttons (Back / Search / Add)
   3. Modal (Profile Pic Popup)
   4. Vibe Check Popup
   5. Profile Info (Labels / Bio / Accounts)
   6. Misc Utilities
════════════════════════════════════════════════════════════ */


/* ══════════════════════════════════════
   1. PROFILE PIC & STATUS
══════════════════════════════════════ */

#profilePic {
    width: 120px;
    height: 120px;
    border-radius: 20%;
    object-fit: cover;
    border: 3px solid red;
    cursor: pointer;
    position: relative;
    z-index: 1;
}

@media (max-width: 768px) {
    #profilePic {
        width: 105px !important;
        height: 105px !important;
        border-radius: 50%;
        border: 3px solid red;
    }
}

@media (max-width: 480px) {
    #profilePic {
        width: 80px !important;
        height: 80px !important;
        border-radius: 50%;
        border: 3px solid red;
    }
}

@media (max-width: 390px) {
    #profilePic {
        width: 75px !important;
        height: 75px !important;
    }
}

@media (max-width: 330px) {
    #profilePic {
        width: 70px !important;
        height: 70px !important;
    }
}
.profile-pic-wrap {
    position: relative;
    display: inline-block;
    line-height: 0;
    z-index: 1;
}

.profile-main-pic {
    border: 3px solid red;
    cursor: pointer;
}


/* ══════════════════════════════════════
   3. MODAL (Profile Pic Popup)
══════════════════════════════════════ */

.modal {
    display: none;
    position: fixed;
    z-index:999999;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.92);
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 32px;
}

.modal[style*="flex"] {
    display: flex !important;
    align-items: center;
    justify-content: center;
}

.modal-content {
    display: block !important;
    border-radius: 16px;
    border: 3px solid red;
    object-fit: cover;
    transition: transform 0.3s ease;
    width: min(60vw, 60vh) !important;
    height: min(60vw, 60vh) !important;
    max-width: 700px !important;
    max-height: 700px !important;
    margin: 0 !important;
    padding: 0 !important;
}

@media (max-width: 768px) {
    .modal-content {
        width: min(60vw, 60vh) !important;
        height: min(60vw, 60vh) !important;
        max-width: 300px !important;
        max-height: 300px !important;
        border-radius: 50% !important;
    }
}

@media (max-width: 480px) {
    .modal-content {
         width: 60vw !important;
        height: 60vw !important;
        border-radius: 50% !important;
    }
}

@media (max-width: 360px) {
    .modal-content {
        width: 60vw !important;
        height: 60vw !important;
        border-radius: 50% !important;
    }
}

.modal-actions {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 20px;
}

@media (max-width: 480px) { .modal-actions { gap: 14px; } }
@media (max-width: 360px) { .modal-actions { gap: 10px; } }

.modal-action-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    cursor: pointer;
}

.modal-action-icon {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: rgba(30,30,30,0.95);
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.2s, transform 0.2s;
}

.modal-action-icon svg {
    width: 22px;
    height: 22px;
    stroke: red;
}

.modal-action-btn span {
    font-size: 11px;
    color: rgba(255,255,255,0.85);
    font-weight: 500;
    white-space: nowrap;
    letter-spacing: 0.2px;
}

.modal-action-btn:hover .modal-action-icon {
    background: rgba(50,50,50,0.95);
    transform: translateY(-2px);
}

@media (max-width: 480px) {
    .modal-action-icon     { width: 48px; height: 48px; }
    .modal-action-icon svg { width: 20px; height: 20px; }
    .modal-action-btn span { font-size: 10px; }
}

@media (max-width: 360px) {
    .modal-action-icon     { width: 42px; height: 42px; }
    .modal-action-icon svg { width: 18px; height: 18px; }
}

/* ── CLOSE BUTTON ── */
.close {
    position: fixed;
    top: 20px;
    right: 25px;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: transparent;
    border: transparent;
    color: #fff;
    font-size: 38px;
    font-weight: bold;
    cursor: pointer;
    transition: all 0.25s ease;
    z-index: 1001;
    display: flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
}

.close:hover  { background: rgba(255,0,0,0.25); border-color: rgba(255,0,0,0.4); color: red; transform: scale(1.08); }
.close:active { transform: scale(0.93); }

@media (max-width: 768px) { .close { display: none; } }
@media (max-width: 480px) { .close { display: none; } }

/* ── PROFILE LINK ── */
/* ── PROFILE LINK — hidden on mobile (replaced by fixed topbar) ── */
@media (max-width: 768px) {
    .profile-link {
        display: none !important;
    }
}

/* ══════════════════════════════════════
   4. VIBE CHECK POPUP
══════════════════════════════════════ */

#vibePopup {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 2147483647;
    background: rgba(0,0,0,0.82);
    align-items: flex-end;
    justify-content: center;
    isolation: isolate;
}

#vibePopup.open {
    display: flex;
}

.vibe-sheet {
    position: relative;
    z-index: 2147483647;
    background: #111;
    border-radius: 24px 24px 0 0;
    padding: 28px 20px 40px;
    width: 100%;
    max-width: 480px;
    max-height: 85vh;
    overflow-y: auto;
    border-top: 1px solid rgba(255,255,255,0.08);
    scrollbar-width: thin;
    scrollbar-color: rgba(255,255,255,0.15) transparent;
}

.vibe-sheet::-webkit-scrollbar       { width: 4px; }
.vibe-sheet::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.15); border-radius: 999px; }

.vibe-handle {
    width: 36px;
    height: 4px;
    background: rgba(255,255,255,0.2);
    border-radius: 4px;
    margin: 0 auto 20px;
}

.vibe-title {
    text-align: center;
    color: white;
    font-size: 15px;
    font-weight: 600;
    margin: 0 0 4px;
    font-family: 'Poppins', sans-serif;
}

.vibe-sub {
    text-align: center;
    color: rgba(255,255,255,0.45);
    font-size: 12px;
    margin: 0 0 24px;
    font-family: 'Poppins', sans-serif;
}

.vibe-section-label {
    color: rgba(255,255,255,0.5);
    font-size: 11px;
    letter-spacing: 1px;
    text-transform: uppercase;
    margin: 0 0 12px;
    font-family: 'Poppins', sans-serif;
}

.vibe-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin-bottom: 20px;
}

.vibe-opt {
    padding: 11px 8px;
    border-radius: 10px;
    border: 1px solid rgba(255,255,255,0.1);
    background: rgba(255,255,255,0.05);
    color: rgba(255,255,255,0.75);
    font-size: 12px;
    font-family: 'Poppins', sans-serif;
    cursor: pointer;
    transition: all 0.2s ease;
    text-align: center;
    width: 100%;
    line-height: 1.4;
}

.vibe-opt:hover {
    background: rgba(255,0,0,0.1);
    border-color: rgba(255,0,0,0.3);
    color: white;
    transform: translateY(-1px);
}

.vibe-opt.selected {
    background: rgba(255,0,0,0.18);
    border-color: red;
    color: white;
    font-weight: 600;
}

.vibe-result {
    display: none;
    text-align: center;
    margin-bottom: 20px;
    padding: 16px;
    background: rgba(255,255,255,0.05);
    border-radius: 14px;
    border: 1px solid rgba(255,255,255,0.08);
}

.vibe-result-label {
    color: rgba(255,255,255,0.45);
    font-size: 11px;
    letter-spacing: 1px;
    text-transform: uppercase;
    margin: 0 0 8px;
    font-family: 'Poppins', sans-serif;
}

.vibe-result-text {
    color: white;
    font-size: 20px;
    font-weight: 700;
    margin: 0;
    font-family: 'Poppins', sans-serif;
}

.vibe-result-total {
    color: rgba(255,255,255,0.35);
    font-size: 11px;
    margin: 8px 0 0;
    font-family: 'Poppins', sans-serif;
}

.vibe-close-btn {
    width: 100%;
    padding: 13px;
    background: rgba(255,255,255,0.07);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 12px;
    color: rgba(255,255,255,0.7);
    font-size: 14px;
    font-family: 'Poppins', sans-serif;
    cursor: pointer;
    transition: background 0.2s ease;
}

.vibe-close-btn:hover { background: rgba(255,255,255,0.12); color: white; }

/* ── VIBE RESPONSIVE ── */
@media (max-width: 768px) {
    .vibe-sheet        { max-width: 100%; border-radius: 20px 20px 0 0; padding: 22px 16px 36px; max-height: 88vh; }
    .vibe-title        { font-size: 14px; }
    .vibe-sub          { font-size: 11.5px; margin-bottom: 20px; }
    .vibe-grid         { gap: 8px; margin-bottom: 16px; }
    .vibe-opt          { padding: 10px 6px; font-size: 11.5px; border-radius: 9px; }
    .vibe-result       { padding: 13px; border-radius: 12px; }
    .vibe-result-text  { font-size: 18px; }
    .vibe-result-label { font-size: 10px; }
    .vibe-result-total { font-size: 10px; }
    .vibe-close-btn    { padding: 11px; font-size: 13px; border-radius: 10px; }
}

@media (max-width: 480px) {
    .vibe-sheet         { padding: 18px 14px 32px; max-height: 90vh; border-radius: 18px 18px 0 0; }
    .vibe-handle        { width: 30px; height: 3px; margin-bottom: 16px; }
    .vibe-title         { font-size: 13.5px; }
    .vibe-sub           { font-size: 11px; margin-bottom: 16px; }
    .vibe-section-label { font-size: 10px; letter-spacing: 0.8px; }
    .vibe-grid          { gap: 7px; }
    .vibe-opt           { padding: 9px 5px; font-size: 11px; border-radius: 8px; }
    .vibe-result-text   { font-size: 16px; }
    .vibe-close-btn     { padding: 10px; font-size: 12.5px; }
}

@media (max-width: 360px) {
    .vibe-sheet     { padding: 16px 12px 28px; }
    .vibe-grid      { gap: 6px; }
    .vibe-opt       { padding: 8px 4px; font-size: 10.5px; border-radius: 7px; }
    .vibe-title     { font-size: 13px; }
    .vibe-sub       { font-size: 10.5px; }
    .vibe-close-btn { padding: 9px; font-size: 12px; }
}


/* ══════════════════════════════════════
   5. PROFILE INFO (Labels / Bio / Accounts)
══════════════════════════════════════ */

/* ── LABELS ── */
.label-full  { display: inline; }
.label-short { display: none;   }

@media (max-width: 480px) {
    .label-full  { display: none; }
    .label-short { display: inline; font-weight: 600; color: #f24455; }
    .pronouns-pill,
    .type-pill   { color: #f24455; }
}

/* ── PRONOUN LABEL ── */
.pronoun-label { display: inline; }

@media (max-width: 768px) { .pronoun-label { display: none; } }

/* ── BIO ── */
.bio-text {
    margin: 10px 0 0 0;
    color: #fff;
    max-width: 600px;
    font-size: 12.5px;
    line-height: 1.4;
    opacity: 0.8;
}

@media (max-width: 768px) {
    .bio-text { font-size: 10px; transform: scale(0.81); transform-origin: left top; opacity: 0.9; }
}

/* ── OTHER ACCOUNT HANDLES ── */
.account-handle { font-size: 12.5px; color: #adacb3; transition: all 0.2s ease; }

@media (max-width: 768px) { .account-handle { font-size: 11px; } }

@media (max-width: 480px) {
    .account-handle { transform: translateY(-2px); margin-top: -2px; line-height: 1.2; font-size: 11px; color: #c9c9c9; }
}

@media (max-width: 360px) {
    .account-handle { transform: translateY(-2px); margin-top: -2px; line-height: 1.2; font-size: 11px; color: #bdbdbd; }
}

/* ── RESPONSIVE FLEX ── */
.responsive-flex {
    display: flex;
    align-items: center;
    gap: 9px;
    flex-wrap: wrap;
}

@media (max-width: 768px) {
    .responsive-flex { flex-direction: row; gap: 6px; justify-content: flex-start; align-items: center; }
}


/* ══════════════════════════════════════
   6. MISC UTILITIES — TEXT SHRINK
══════════════════════════════════════ */

@media (max-width: 768px) {
    .pronoun-label, .label-full, .label-short, .type-short { font-size: 11px !important; line-height: 1.25; transform: scale(1);    transform-origin: left top; }
    div[style*="border-radius:20px"]                       { font-size: 11.5px !important; line-height: 1.25; transform: scale(1);    transform-origin: left top; }
}

@media (max-width: 480px) {
    .pronoun-label, .label-full, .label-short, .type-short { font-size: 10px !important; line-height: 1.2;  transform: scale(0.98) translateX(-5px); transform-origin: left top; margin-bottom: 4px; }
    div[style*="border-radius:20px"]                       { font-size: 10.1px !important; line-height: 1.2;  transform: scale(0.98) translateX(-5px); transform-origin: left top; margin-bottom: 4px; }
}

@media (max-width: 400px) {
    .pronoun-label, .label-full, .label-short, .type-short { font-size: 10px   !important; line-height: 1.15; transform: scale(0.95) translateX(-2px); transform-origin: left top; margin-bottom: 4px; }
    div[style*="border-radius:20px"]                       { font-size: 10px !important; line-height: 1.15; transform: scale(0.95) translateX(-2px); transform-origin: left top; margin-bottom: 4px; }
}

@media (max-width: 360px) {
    .pronoun-label, .label-full, .label-short, .type-short { font-size: 10px   !important; line-height: 1.15; transform: scale(0.95) translateX(-2px); transform-origin: left top; margin-bottom: 4px; }
    div[style*="border-radius:20px"]                       { font-size: 10px !important; line-height: 1.15; transform: scale(0.95) translateX(-2px); transform-origin: left top; margin-bottom: 4px; }
}

/* ── Shift Trackers / Tracking / Pods row left on mobile ── */
@media (max-width: 770px) {
    .track-pods-row {
        margin-left: -8px;
    }
}
@media (max-width: 490px) {
    .track-pods-row {
        margin-left: -8px;
    }
}
@media (max-width: 390px) {
    .track-pods-row {
        margin-left: -6px;
    }
}
@media (max-width: 330px) {
    .track-pods-row {
        margin-left: -6px;
    }
}
</style>

<script>
const modal = document.getElementById("picModal");
const img = document.getElementById("profilePic");
const modalImg = document.getElementById("modalImg");
const closeBtn = document.getElementsByClassName("close")[0];

img.onclick = function() {
    if(this.classList.contains('no-modal')) return;
    modal.style.cssText = "display:flex; flex-direction:column; align-items:center; justify-content:center; gap:32px;";
    modalImg.src = this.src;
};

closeBtn.onclick = function() {
  modal.style.cssText = "display:none;";
}

modal.onclick = function(e) {
  if(e.target === modal) {
    modal.style.cssText = "display:none;";
  }
}

// build profile URL
function getProfileURL(profileId) {
  return window.location.origin + '/profile.php?id=' + profileId;
}

// Share
function shareProfile(profileId) {
  const url = getProfileURL(profileId);
  if (navigator.share) {
    navigator.share({ title: 'Profile', url: url });
  } else {
    copyToClipboard(url, 'Link copied — share it anywhere!');
  }
}

// Copy link
function copyProfileLink(profileId) {
  const url = getProfileURL(profileId);
  copyToClipboard(url, 'Profile link copied!');
}

// QR Code
function showQR(profileId) {
  const url = getProfileURL(profileId);
  alert('QR for: ' + url);
}

// copy helper
function copyToClipboard(text, message) {
  navigator.clipboard.writeText(text).then(() => {
    alert(message);
  }).catch(() => {
    const el = document.createElement('textarea');
    el.value = text;
    document.body.appendChild(el);
    el.select();
    document.execCommand('copy');
    document.body.removeChild(el);
    alert(message);
  });
}

// Vibe Check
let currentVibeProfileId = null;
<?php $is_own_profile = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $profile_id) ? 'true' : 'false'; ?>
const isOwnProfile = <?php echo $is_own_profile; ?>;

function vibeCheck(profileId) {
  currentVibeProfileId = profileId;

  document.querySelectorAll('.vibe-opt').forEach(b => b.classList.remove('selected'));
  document.getElementById('vibeTopResult').style.display = 'none';
  document.getElementById('vibeSubText').textContent = 'Loading...';
  document.getElementById('vibeVoteSection').style.display = isOwnProfile ? 'none' : 'block';

  const popup = document.getElementById('vibePopup');
  popup.style.cssText = 'display:flex; position:fixed; inset:0; z-index:3000; background:rgba(0,0,0,0.8); align-items:flex-end; justify-content:center;';

  fetch('vibe_vote.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: 'action=fetch&profile_id=' + profileId
  })
  .then(r => r.json())
  .then(data => {
    if (!data.success) {
      document.getElementById('vibeSubText').textContent = isOwnProfile ? 'No votes yet' : 'Be the first to vibe check!';
      return;
    }

    const total = data.total || 0;
    document.getElementById('vibeSubText').textContent =
      total > 0
        ? total + (total === 1 ? ' person' : ' people') + ' voted'
        : isOwnProfile ? 'No votes yet' : 'Be the first to vibe check!';

    if (data.my_vote) {
      document.querySelectorAll('.vibe-opt').forEach(b => {
        if (b.textContent.trim() === data.my_vote) b.classList.add('selected');
      });
    }

    if (data.top_vibe) {
      document.getElementById('vibeTopText').textContent = data.top_vibe;
      document.getElementById('vibeTotalText').textContent = total + (total === 1 ? ' vote' : ' votes');
      document.getElementById('vibeTopResult').style.display = 'block';
    }
  })
  .catch(() => {
    document.getElementById('vibeSubText').textContent = 'Could not load vibes';
  });
}

function submitVibe(vibe) {
  document.querySelectorAll('.vibe-opt').forEach(b => {
    b.classList.toggle('selected', b.textContent.trim() === vibe);
  });

  fetch('vibe_vote.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: 'action=vote&profile_id=' + currentVibeProfileId + '&vibe=' + encodeURIComponent(vibe)
  })
  .then(r => r.json())
  .then(data => {
    if (!data.success) return;
    const total = data.total || 0;
    document.getElementById('vibeSubText').textContent = total + (total === 1 ? ' person' : ' people') + ' voted';

    if (data.top_vibe) {
      document.getElementById('vibeTopText').textContent = data.top_vibe;
      document.getElementById('vibeTotalText').textContent = total + (total === 1 ? ' vote' : ' votes');
      document.getElementById('vibeTopResult').style.display = 'block';
    }
  });
}

function closeVibePopup() {
  document.getElementById('vibePopup').style.display = 'none';
}

document.getElementById('vibePopup').addEventListener('click', function(e) {
  if (e.target === this) closeVibePopup();
});
</script>

</div>

<!-- User Info -->
<div class="profile-userinfo" style="display:flex; flex-direction:column; gap:3px;">

<!-- Username + Followers / Following -->
<div style="display:flex; align-items:center; gap: 9px; flex-wrap:wrap;">
<?php
// ── Cross-account notification check ──
$has_cross_notif = false;
foreach ($_SESSION['accounts'] as $acc_id) {
    if ($acc_id == $current_user) continue;
    $acc_id = (int)$acc_id;
    $notif_check = $conn->query("
        SELECT 1 FROM messages 
        WHERE receiver_id = $acc_id AND seen = 0
        LIMIT 1
    ");
    if ($notif_check && $notif_check->num_rows > 0) {
        $has_cross_notif = true;
        break;
    }
}

// ── Account limit check ──
$linked_count = $conn->query("SELECT COUNT(*) as cnt FROM linked_accounts WHERE primary_user_id = $current_user")->fetch_assoc()['cnt'] ?? 0;
$at_limit = ($linked_count >= 4); // 4 linked + current = 5 total
?>

<h2 id="profileMainUsername" style="margin:0; cursor:pointer; user-select:none; position:relative; display:inline-flex; align-items:center; gap:3px;" onclick="toggleUserKebab(event)">
    <span class="status-icon"></span>
    <?php echo $user['username']; ?>
    <?php if($current_user == $profile_id): ?>
        <i class="ri-arrow-down-s-line" id="userKebabChevron" style="font-size:16px; color:rgba(255,255,255,0.4); vertical-align:middle; transition:transform 0.25s ease, font-size 0.2s ease; margin-left:-1px;"></i>
        <?php if($has_cross_notif): ?>
            <span style="
                position:absolute;
                top:0; right:-8px;
                width:8px; height:8px;
                background:#FF2D2D;
                border-radius:50%;
                border:1.5px solid #06071A;
                display:inline-block;
            "></span>
        <?php endif; ?>
    <?php endif; ?>
</h2>

<style>
/* ── Hide the main profile username (h2) on mobile — ALWAYS, regardless
     of who's viewing. It's replaced by the fixed topbar on mobile. ── */
@media (max-width: 770px) {
    #profileMainUsername {
        display: none !important;
    }
}
</style>

<?php if($current_user == $profile_id): ?>

<style>
/* ── Chevron responsive sizing (smaller than normal on smaller screens, still visible) ── */
@media (max-width:780px) {
    #userKebabChevron { font-size:14px !important; }
}
@media (max-width:490px) {
    #userKebabChevron { font-size:13px !important; }
}
@media (max-width:380px) {
    #userKebabChevron { font-size:12px !important; }
}
@media (max-width:330px) {
    #userKebabChevron { font-size:11px !important; }
}

/* ── Overlay ── */
#userKebabOverlay {
    display:none;
    position:fixed;
    inset:0;
    background:rgba(0,0,0,0.6);
    backdrop-filter:blur(2px);
    -webkit-backdrop-filter:blur(2px);
    z-index:999998;
}
#userKebabOverlay.open { display:block; animation:ukbFadeIn 0.2s ease; }

@keyframes ukbFadeIn { from{opacity:0;} to{opacity:1;} }
@keyframes ukbPopIn  { from{opacity:0; transform:translate(-50%,-46%) scale(0.96);} to{opacity:1; transform:translate(-50%,-50%) scale(1);} }
@keyframes ukbSlideUp{ from{transform:translateY(100%);} to{transform:translateY(0);} }

/* ── Modal shell (desktop = centered form modal) ── */
#userKebab {
    display:none;
    position:fixed;
    z-index:999999;
    top:50%;
    left:50%;
    transform:translate(-50%,-50%);
    width:360px;
    max-width:90vw;
}
#userKebab.open { display:block; }
#userKebab.open .ukb-sheet { animation:ukbPopIn 0.22s cubic-bezier(0.34,1.56,0.64,1); }

/* ── The single shared sheet surface — holds handle + card + logout ── */
#userKebab .ukb-sheet {
    background:rgba(12,12,28,0.97);
    backdrop-filter:blur(20px);
    -webkit-backdrop-filter:blur(20px);
    border:1px solid rgba(255,255,255,0.06);
    border-radius:22px;
    padding:14px 14px 16px;
    box-shadow:0 16px 40px rgba(0,0,0,0.6);
    max-height:80vh;
    display:flex;
    flex-direction:column;
    gap:12px;
}

/* Handle — sits ABOVE the card, on the sheet's own background */
#userKebab .ukb-handle {
    display:none;
    justify-content:center;
    padding:2px 0 2px;
    flex-shrink:0;
}
#userKebab .ukb-handle span {
    width:38px; height:4px;
    border-radius:2px;
    background:rgba(255,255,255,0.22);
    display:block;
}

/* ── Accounts card — its own bordered box inside the sheet ── */
#userKebab .ukb-card {
    background:rgba(255,255,255,0.02);
    border:1px solid rgba(255,255,255,0.12);
    border-radius:18px;
    overflow:hidden;
    flex-shrink:1;
    min-height:0;
    display:flex;
    flex-direction:column;
}

#userKebab .ukb-scroll { overflow-y:auto; }

#userKebab .ukb-header {
    padding:12px 16px 6px;
    font-size:10px;
    color:rgba(255,255,255,0.35);
    letter-spacing:1px;
    text-transform:uppercase;
    flex-shrink:0;
}

#userKebab .ukb-row-wrap { display:flex; align-items:center; position:relative; }

#userKebab .ukb-row {
    display:flex;
    align-items:center;
    gap:10px;
    padding:10px 14px;
    text-decoration:none;
    color:white;
    transition:background 0.2s;
    flex:1;
    min-width:0;
}

#userKebab .ukb-avatar-wrap { position:relative; flex-shrink:0; }
#userKebab .ukb-avatar {
    width:34px; height:34px;
    border-radius:50%;
    border:1.5px solid #FF2D2D;
    object-fit:cover;
    display:block;
}
#userKebab .ukb-dot {
    position:absolute;
    top:-2px; right:-2px;
    width:9px; height:9px;
    background:#FF2D2D;
    border-radius:50%;
    border:1.5px solid rgba(20,20,30,0.95);
}
#userKebab .ukb-info { flex:1; min-width:0; }
#userKebab .ukb-name {
    font-size:13px; font-weight:600;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
#userKebab .ukb-sub { font-size:10px; }

#userKebab .unlink-btn {
    opacity:0;
    transition:opacity 0.2s;
    padding:0 14px 0 4px;
    color:rgba(255,255,255,0.3);
    font-size:16px;
    text-decoration:none;
    flex-shrink:0;
    display:flex;
    align-items:center;
}

#userKebab .ukb-divider { border-top:1px solid rgba(255,255,255,0.08); margin:4px 0; flex-shrink:0; }

#userKebab .ukb-add-row {
    display:flex;
    align-items:center;
    gap:10px;
    padding:10px 14px;
    text-decoration:none;
    color:rgba(255,255,255,0.75);
    transition:background 0.2s;
}
#userKebab .ukb-add-icon {
    width:32px; height:32px;
    border-radius:50%;
    display:flex;
    align-items:center;
    justify-content:center;
    flex-shrink:0;
}

/* ── Logout pill — separate bordered button below the card, on the SAME sheet background ── */
#userKebab .ukb-logout-btn {
    display:flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    width:100%;
    padding:14px;
    text-align:center;
    background:transparent;
    border:1px solid rgba(255,255,255,0.15);
    border-radius:999px;
    color:#fff;
    font-size:14px;
    font-weight:600;
    cursor:pointer;
    transition:background 0.2s, border-color 0.2s, color 0.2s;
    font-family:inherit;
    flex-shrink:0;
}
#userKebab .ukb-logout-btn:hover {
    background:rgba(255,45,45,0.08);
    border-color:#FF2D2D;
    color:#FF2D2D;
}

/* ── Bottom sheet mode ≤790px — FULL BLEED, flush to edges and bottom ── */
@media (max-width:790px) {
    #userKebab {
        top:auto;
        bottom:0;
        left:0;
        right:0;
        transform:none;
        width:100%;
        max-width:100%;
        padding:0;
        box-sizing:border-box;
    }
    #userKebab.open .ukb-sheet { animation:ukbSlideUp 0.28s cubic-bezier(0.32,0.72,0,1); }
    #userKebab .ukb-sheet {
        border-radius:22px 22px 0 0;
        max-height:78vh;
        padding:8px 14px calc(env(safe-area-inset-bottom,0px) + 14px);
    }
    #userKebab .ukb-handle {
        display:flex;
    }
    /* no hover on touch — always show unlink */
    #userKebab .unlink-btn { opacity:1 !important; }
}

@media (max-width:490px) {
    #userKebab .ukb-sheet { padding:7px 10px calc(env(safe-area-inset-bottom,0px) + 12px); gap:10px; border-radius:20px 20px 0 0; }
    #userKebab .ukb-card { border-radius:16px; }
    #userKebab .ukb-header { font-size:9px; padding:8px 14px 5px; }
    #userKebab .ukb-row { padding:9px 14px; }
    #userKebab .ukb-avatar { width:32px; height:32px; }
    #userKebab .ukb-name { font-size:12.5px; }
    #userKebab .ukb-sub { font-size:9.5px; }
    #userKebab .ukb-add-icon { width:30px; height:30px; }
    #userKebab .ukb-logout-btn { padding:13px; font-size:13.5px; }
}

@media (max-width:380px) {
    #userKebab .ukb-sheet { padding:6px 8px calc(env(safe-area-inset-bottom,0px) + 10px); gap:9px; border-radius:18px 18px 0 0; }
    #userKebab .ukb-card { border-radius:14px; }
    #userKebab .ukb-row { padding:8px 10px; gap:8px; }
    #userKebab .ukb-avatar { width:30px; height:30px; }
    #userKebab .ukb-name { font-size:12px; }
    #userKebab .ukb-sub { font-size:9px; }
    #userKebab .ukb-add-icon { width:28px; height:28px; }
    #userKebab .ukb-logout-btn { padding:12px; font-size:13px; }
}

@media (max-width:330px) {
    #userKebab .ukb-sheet { padding:5px 7px calc(env(safe-area-inset-bottom,0px) + 8px); gap:8px; border-radius:16px 16px 0 0; }
    #userKebab .ukb-row { padding:7px 8px; gap:7px; }
    #userKebab .ukb-avatar { width:28px; height:28px; }
    #userKebab .ukb-name { font-size:11.5px; }
    #userKebab .ukb-sub { font-size:8.5px; }
    #userKebab .ukb-add-icon { width:26px; height:26px; }
    #userKebab .ukb-logout-btn { padding:11px; font-size:12.5px; }
}
</style>

<div id="userKebabOverlay" onclick="closeUserKebab()"></div>

<div id="userKebab">
    <div class="ukb-sheet">

        <!-- Handle — above the card, on the shared sheet background -->
        <div class="ukb-handle"><span></span></div>

        <!-- Accounts card — its own bordered box -->
        <div class="ukb-card">
            <div class="ukb-scroll">
                <div class="ukb-header">Accounts</div>

                <?php foreach($_SESSION['accounts'] as $acc_id):
                    $acc = $conn->query("SELECT id, username, profile_pic FROM users WHERE id=".(int)$acc_id)->fetch_assoc();
                    if (!$acc) continue;
                    $is_active = ($acc_id == $current_user);

                    // Unread check for this account
                    $acc_unread = $conn->query("SELECT COUNT(*) as cnt FROM messages WHERE receiver_id=".(int)$acc_id." AND seen=0")->fetch_assoc()['cnt'] ?? 0;
                ?>
                <div class="ukb-row-wrap"
                     onmouseover="this.querySelector('.unlink-btn') && (this.querySelector('.unlink-btn').style.opacity='1')"
                     onmouseout="this.querySelector('.unlink-btn') && (this.querySelector('.unlink-btn').style.opacity='0')">

                    <a href="<?php echo $is_active ? '#' : 'switch_account.php?user_id='.$acc_id; ?>"
                       onclick="<?php echo $is_active ? 'return false;' : ''; ?>"
                       class="ukb-row"
                       style="<?php echo $is_active ? 'opacity:0.6; cursor:default;' : ''; ?>"
                       onmouseover="if(!<?php echo $is_active ? 'true' : 'false'; ?>)this.style.background='rgba(255,255,255,0.06)'"
                       onmouseout="this.style.background='transparent'">

                        <div class="ukb-avatar-wrap">
                            <img src="<?php echo !empty($acc['profile_pic']) ? $acc['profile_pic'] : 'uploads/me.jpeg'; ?>"
                                 onerror="this.src='uploads/me.jpeg'"
                                 class="ukb-avatar">
                            <?php if (!$is_active && $acc_unread > 0): ?>
                            <span class="ukb-dot"></span>
                            <?php endif; ?>
                        </div>

                        <div class="ukb-info">
                            <div class="ukb-name"><?php echo htmlspecialchars($acc['username']); ?></div>
                            <?php if ($is_active): ?>
                                <div class="ukb-sub" style="color:#2b67f5;">Active</div>
                            <?php elseif ($acc_unread > 0): ?>
                                <div class="ukb-sub" style="color:#FF2D2D;"><?php echo $acc_unread; ?> unread</div>
                            <?php endif; ?>
                        </div>

                        <?php if ($is_active): ?>
                        <i class="ri-checkbox-circle-fill" style="color:#2b67f5;font-size:16px;flex-shrink:0;"></i>
                        <?php endif; ?>
                    </a>

                    <?php if (!$is_active): ?>
                    <a href="unlink_account.php?user_id=<?php echo (int)$acc_id; ?>"
                       onclick="return confirm('Remove <?php echo htmlspecialchars($acc['username']); ?> from your accounts?');"
                       class="unlink-btn"
                       title="Remove account"
                       onmouseover="this.style.color='#FF2D2D'"
                       onmouseout="this.style.color='rgba(255,255,255,0.3)'">
                        <i class="ri-close-line"></i>
                    </a>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>

                <div class="ukb-divider"></div>

                <?php if ($at_limit): ?>
                <div class="ukb-add-row" style="color:rgba(255,255,255,0.25); cursor:not-allowed;" title="Maximum 5 accounts reached">
                    <div class="ukb-add-icon" style="border:1.5px dashed rgba(255,255,255,0.15);">
                        <i class="ri-add-line" style="font-size:18px;color:rgba(255,255,255,0.2);"></i>
                    </div>
                    <div style="flex:1;">
                        <span style="font-size:13px;font-weight:500;display:block;">Add Account</span>
                        <span style="font-size:10px;color:rgba(255,255,255,0.25);">Limit reached (5 max)</span>
                    </div>
                </div>
                <?php else: ?>
                <a href="add_account.php" class="ukb-add-row"
                   onmouseover="this.style.background='rgba(255,255,255,0.06)'"
                   onmouseout="this.style.background='transparent'">
                    <div class="ukb-add-icon" style="border:1.5px dashed rgba(255,255,255,0.3);">
                        <i class="ri-add-line" style="font-size:18px;color:rgba(255,255,255,0.5);"></i>
                    </div>
                    <div style="flex:1;">
                        <span style="font-size:13px;font-weight:500;display:block;">Add Account</span>
                        <span style="font-size:10px;color:rgba(255,255,255,0.35);"><?php echo 4 - $linked_count; ?> slot<?php echo (4 - $linked_count) === 1 ? '' : 's'; ?> remaining</span>
                    </div>
                </a>
                <?php endif; ?>
            </div><!-- end .ukb-scroll -->
        </div><!-- end .ukb-card -->

        <!-- Logout — separate pill below the card, same sheet background -->
        <button type="button" class="ukb-logout-btn" onclick="window.location.href='logout.php'">
            <i class="ri-logout-box-r-line"></i> Logout
        </button>

    </div><!-- end .ukb-sheet -->
</div><!-- end #userKebab -->
<?php endif; ?>

<script>
function toggleUserKebab(e) {
    e.stopPropagation();
    const overlay = document.getElementById('userKebabOverlay');
    const k  = document.getElementById('userKebab');
    const ch = document.getElementById('userKebabChevron');
    if (!k) return;

    const isOpen = k.classList.contains('open');
    if (isOpen) {
        closeUserKebab();
    } else {
        overlay.classList.add('open');
        k.classList.add('open');
        if (ch) ch.style.transform = 'rotate(180deg)';
        document.body.style.overflow = 'hidden';
    }
}

function closeUserKebab() {
    const overlay = document.getElementById('userKebabOverlay');
    const k  = document.getElementById('userKebab');
    const ch = document.getElementById('userKebabChevron');
    if (!k) return;

    overlay.classList.remove('open');
    k.classList.remove('open');
    if (ch) ch.style.transform = 'rotate(0deg)';
    document.body.style.overflow = '';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeUserKebab();
});
</script>


<div class="track-pods-row" style="display:flex; gap: 1px; font-size:14px; color:#fff;">

<div class="responsive-flex">
    <?php if (!$is_blocked): ?>
<?php
function shortNum($n) {
    if ($n >= 1000000000) return round($n / 1000000000, 1) . 'B';
    if ($n >= 1000000)    return round($n / 1000000, 1) . 'M';
    if ($n >= 1000)       return round($n / 1000, 1) . 'K';
    return $n;
}
?>
<!-- Trackers -->
<a href="<?php echo ($blocked_check || !$can_view) ? '#' : 'tracks.php?user_id='.$profile_id.'&type=followers'; ?>" 
   class="track-btn"
   <?php 
       if ($blocked_check) {
           echo 'onclick="alert(\'You are blocked, you cannot view this.\'); return false;"';
       } elseif (!$can_view) {
           echo 'onclick="alert(\'Track this Peumer to view their Trackers.\'); return false;"';
       }
   ?>>
    Trackers: 
    <span class="track-count <?php echo !$can_view ? 'blur-count' : ''; ?>">
        <?php echo shortNum($followers_count); ?>
    </span>
</a>

<span style="color:rgba(255,255,255,0.2); font-size:14px;">|</span>

<!-- Tracking -->
<a href="<?php echo ($blocked_check || !$can_view) ? '#' : 'tracks.php?user_id='.$profile_id.'&type=following'; ?>" 
   class="track-btn"
   <?php 
       if ($blocked_check) {
           echo 'onclick="alert(\'You are blocked, you cannot view this.\'); return false;"';
       } elseif (!$can_view) {
           echo 'onclick="alert(\'Track this Peumer to view their Trackings.\'); return false;"';
       }
   ?>>
    Tracking: 
    <span class="track-count <?php echo !$can_view ? 'blur-count' : ''; ?>">
        <?php echo shortNum($following_count); ?>
    </span>
</a>
</div>

<span style="color:rgba(255,255,255,0.2); font-size:14px; line-height:1; align-self:center;">|</span>

<!-- Pods -->
<a href="<?php echo (!$blocked_check && $can_view) ? 'group_list.php?user_id='.$profile_id : '#'; ?>" 
   class="group-btn"
   <?php if($blocked_check) echo 'onclick="alert(\'You are blocked, you cannot view this.\'); return false;"';
         elseif(!$can_view) echo 'onclick="alert(\'Track this Peumer to view their pods\'); return false;"'; ?>>
    <i class="ri-group-2-line"></i> Pods
</a>
    </div>
</div>

    <!-- Pronouns, Activity Vibe, and User Type -->
<?php
$has_pronouns = !empty($user['pronouns']);
$has_vibe     = !empty($activity_vibe);
$has_type     = !empty($user['user_type']);
$show_pills_row = $has_pronouns || $has_vibe || $has_type;
?>

<?php if ($show_pills_row): ?>
<!-- Pronouns, Activity Vibe, and User Type -->
<div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-top:5px;">

    <!-- Pronouns -->
    <?php if($has_pronouns): ?>
    <div style="
        display:inline-flex;
        align-items:center;
        white-space:nowrap;
        background: rgba(255,255,255,0.1);
        padding:4px 12px;
        border-radius:20px;
        font-size:14px;
        color:#c0bfc6;
        max-width:190px;
        overflow:hidden;
        text-overflow:ellipsis;
        gap:6px;
    ">
        <span class="pronoun-label">Pronouns:</span>  
<?php echo htmlspecialchars($user['pronouns']); ?>
    </div>
    <?php endif; ?>

    <!-- Activity Vibe -->
    <?php if($has_vibe): ?>
        <div style="background: rgba(255,255,255,0.1); padding:4px 12px; border-radius:20px; font-size:14px; color:#fff; max-width: 300px;">
            <span class="label-full"style="color:#f24455; font-weight:600;">Active Hours:</span>
<span class="label-short"></span>
            <?php echo htmlspecialchars($activity_vibe); ?>
        </div>
    <?php endif; ?>

    <!-- User Type -->
    <?php if($has_type): ?>
        <div style="background: rgba(255,255,255,0.1); padding:4px 12px; border-radius:20px; font-size:14px; color:#fff; max-width: 160px;">
           <span class="label-full"style="color:#f24455; font-weight:600;"> Type:</span>
<span class="label-short type-short"></span>
            <?php echo htmlspecialchars($user['user_type']); ?>
        </div>
    <?php endif; ?>

</div>
<?php endif; ?>



<!-- Bio -->
<?php
function linkifyBio($text) {
    $text = preg_replace(
        '/(https?:\/\/[^\s<]+)/i',
        '<a href="$1" target="_blank" style="color:#4A90D9;text-decoration:none;font-weight:500;" onmouseover="this.style.textDecoration=\'underline\'" onmouseout="this.style.textDecoration=\'none\'">$1</a>',
        $text
    );
    $text = preg_replace(
        '/([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})/i',
        '<a href="mailto:$1" style="color:#4A90D9;text-decoration:none;font-weight:500;" onmouseover="this.style.textDecoration=\'underline\'" onmouseout="this.style.textDecoration=\'none\'">$1</a>',
        $text
    );
    return $text;
}

// ── Decide if bio should be visible ──
$bio_visible = false;

if ($current_user == $profile_id) {
    // Owner always sees their own bio
    $bio_visible = true;

} elseif (!empty($settings['is_fully_private'])) {
    // Private profile — only approved trackers see the bio
    if ($current_user) {
        $bio_chk = $conn->prepare("SELECT id FROM track_requests WHERE requester_id=? AND target_id=? AND status='approved'");
        $bio_chk->bind_param("ii", $current_user, $profile_id);
        $bio_chk->execute();
        $bio_visible = $bio_chk->get_result()->num_rows > 0;
        $bio_chk->close();

        // Also approved if already a follower (covers edge cases)
        if (!$bio_visible && $follow_check) {
            $bio_visible = true;
        }
    }

} else {
    // Public profile — everyone sees it
    $bio_visible = true;
}

// ── Single source of truth for "should we render anything at all" ──
$show_bio = $bio_visible && !empty($user['bio']);
?>

<?php if ($show_bio): ?>
    <p class="bio-text" style="margin-top:5px;">
        <?php
        $bio   = linkifyBio(htmlspecialchars($user['bio']));
        $limit = 105;
        if (mb_strlen(strip_tags($bio)) > $limit):
            $short = linkifyBio(htmlspecialchars(mb_substr($user['bio'], 0, $limit)));
            $rest  = linkifyBio(htmlspecialchars(mb_substr($user['bio'], $limit)));
        ?>
            <span id="bio-short"><?= nl2br($short) ?><span id="bio-dots">...</span></span>
            <span id="bio-rest" style="display:none;"><?= nl2br($rest) ?></span>
            <span id="bio-more-btn"
                  onclick="
                      document.getElementById('bio-rest').style.display='inline';
                      document.getElementById('bio-dots').style.display='none';
                      document.getElementById('bio-less-btn').style.display='inline';
                      this.style.display='none';
                  "
                  style="color:#FF2D2D;cursor:pointer;font-weight:600;margin-left:3px;"
                  class="bio-toggle-btn">more</span>
            <span id="bio-less-btn"
                  onclick="
                      document.getElementById('bio-rest').style.display='none';
                      document.getElementById('bio-dots').style.display='inline';
                      document.getElementById('bio-more-btn').style.display='inline';
                      this.style.display='none';
                  "
                  style="display:none;color:#FF2D2D;cursor:pointer;font-weight:600;margin-left:3px;"
                  class="bio-toggle-btn">close</span>
        <?php else: ?>
            <?= nl2br($bio) ?>
        <?php endif; ?>
    </p>
<?php endif; ?>
<?php endif; ?>

<!-- ══════════════════════════════════════
     PROFILE LINKS (separate from bio — sits directly below it)
     Rules:
       - 1 link  → clicking goes straight to that URL
       - 2 links → each row clicks straight to its own URL
       - 3+ links → clicking opens the full links modal
══════════════════════════════════════ -->
<?php
// ── Fetch this profile's links (same visibility rule as the bio) ──
$profile_links = [];
if ($show_bio || $current_user == $profile_id) {
    $pl_stmt = $conn->prepare("SELECT id, title, url, icon FROM links WHERE user_id=? ORDER BY created_at DESC");
    $pl_stmt->bind_param("i", $profile_id);
    $pl_stmt->execute();
    $pl_res = $pl_stmt->get_result();
    while ($l = $pl_res->fetch_assoc()) $profile_links[] = $l;
    $pl_stmt->close();
}
$profile_link_count = count($profile_links);
?>

<?php if ($profile_link_count > 0): ?>

    <?php if ($profile_link_count == 1): ?>
    <div class="plp-wrap">
        <a class="plp-row" href="<?= htmlspecialchars($profile_links[0]['url']) ?>" target="_blank" rel="noopener">
            <i class="ri-link-m"></i>
            <span class="plp-text"><?= htmlspecialchars($profile_links[0]['url']) ?></span>
        </a>
    </div>

    <?php elseif ($profile_link_count == 2): ?>
    <div class="plp-wrap">
        <a class="plp-row" href="<?= htmlspecialchars($profile_links[0]['url']) ?>" target="_blank" rel="noopener">
            <i class="ri-link-m"></i>
            <span class="plp-text"><?= htmlspecialchars($profile_links[0]['url']) ?></span>
        </a>
        <a class="plp-row" href="<?= htmlspecialchars($profile_links[1]['url']) ?>" target="_blank" rel="noopener">
            <i class="ri-link-m"></i>
            <span class="plp-text"><?= htmlspecialchars($profile_links[1]['url']) ?></span>
        </a>
    </div>

    <?php else: ?>
    <div class="plp-wrap" onclick="openProfileLinksModal()">
        <div class="plp-row">
            <i class="ri-link-m"></i>
            <span class="plp-text"><?= htmlspecialchars($profile_links[0]['url']) ?></span>
        </div>
        <div class="plp-row">
            <i class="ri-link-m"></i>
            <span class="plp-text"><?= htmlspecialchars($profile_links[1]['url']) ?></span>
            <span class="plp-more">+<?= $profile_link_count - 2 ?> more</span>
        </div>
    </div>
    <?php endif; ?>

<?php endif; ?>

<!-- ══════════════════════════════════════
     PROFILE LINKS MODAL — bottom sheet (mobile) / form modal (desktop)
     Only ever opened when there are 3+ links
══════════════════════════════════════ -->
<?php if ($profile_link_count > 2): ?>
<div id="profileLinksOverlay" onclick="if(event.target===this)closeProfileLinksModal()">
    <div class="pl-sheet">
        <div class="pl-handle"></div>
        <div class="pl-title"><?= htmlspecialchars($user['username']) ?>'s Links</div>
        <?php foreach ($profile_links as $link): ?>
        <a class="pl-item" href="<?= htmlspecialchars($link['url']) ?>" target="_blank" rel="noopener">
            <div class="pl-icon"><i class="ri-link-m"></i></div>
            <div class="pl-info">
                <div class="pl-item-title"><?= htmlspecialchars($link['title']) ?></div>
                <div class="pl-item-url"><?= htmlspecialchars($link['url']) ?></div>
            </div>
            <i class="ri-arrow-right-s-line pl-chevron"></i>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>


<style>
.bio-toggle-btn {
    font-size: 12px;
}
@media (max-width: 790px) {
    .bio-toggle-btn { font-size: 13px; }
}
@media (max-width: 490px) {
    .bio-toggle-btn { font-size: 11.9px; }
}



/* ---------- ACTION BUTTONS SPACING (bio/links → Track/Message/Block) ---------- */
.profile-action-buttons{
    margin-top:14px;
}
@media (max-width:790px){
    .profile-action-buttons{
        margin-top:12px !important;
    }
}
@media (max-width:490px){
    .profile-action-buttons{
        margin-top:10px !important;
    }
}
@media (max-width:390px){
    .profile-action-buttons{
        margin-top:9px !important;
    }
}
@media (max-width:330px){
    .profile-action-buttons{
        margin-top:8px !important;
    }
}

/* ── Tablet (≤790px) ── */
@media (max-width: 790px) {
  .profile-action-buttons .action-btn{
    font-size: 0.72rem !important;
  }
}

/* ── Mobile (≤490px) ── */
@media (max-width: 490px) {
  .profile-action-buttons .action-btn{
    font-size: 0.65rem !important;
  }
}

/* ── Mobile (≤390px) ── */
@media (max-width: 390px) {
  .profile-action-buttons .action-btn {
    font-size: 0.63rem !important;
  }
}


/* ══════════════════════════════════════
   PROFILE LINKS PREVIEW (below bio)
   — lighter purple accent, big icon,
   text sized normally on desktop but
   smaller on responsive, "+N more" plain text
══════════════════════════════════════ */
.plp-wrap{
    margin-top:4px;
    display:flex;
    flex-direction:column;
    gap:6px;
    cursor:pointer;
    max-width:100%;
}
.plp-row{
    display:flex;
    align-items:center;
    gap:7px;
    min-width:0;
    text-decoration:none;
    cursor:pointer;
}
.plp-row i{
    font-size:18px;
    color:#9B7BD4;
    flex-shrink:0;
}
.plp-text{
    font-size:13px;
    font-weight:500;
    color:#9B7BD4;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
    max-width:230px;
    transition:color 0.18s;
}
.plp-more{
    font-size:13px;
    font-weight:600;
    color:#B79BE0;
    flex-shrink:0;
    white-space:nowrap;
}
.plp-wrap:hover .plp-text{ color:#B79BE0; text-decoration:underline; }

@media (max-width:790px){
    .plp-wrap{ gap:6px; margin-top:-5px; }
    .plp-text{ font-size:11.8px; max-width:210px; }
    .plp-more{ font-size:11.8px; }
    .plp-row i{ font-size:17px; }
    .plp-row{ gap:6px; }
}
@media (max-width:490px){
    .plp-wrap{ gap:5px; margin-top:-3px; }
    .plp-text{ font-size:10.9px; max-width:185px; }
    .plp-more{ font-size:10.9px; }
    .plp-row i{ font-size:16px; }
    .plp-row{ gap:6px; }
}
@media (max-width:390px){
    .plp-wrap{ gap:5px; margin-top:-3px; }
    .plp-text{ font-size:10.4px; max-width:160px; }
    .plp-more{ font-size:10.4px; }
    .plp-row i{ font-size:15px; }
    .plp-row{ gap:5px; }
}
@media (max-width:330px){
    .plp-wrap{ gap:4px; margin-top:2px; }
    .plp-text{ font-size:9.5px; max-width:135px; }
    .plp-more{ font-size:9,5px; }
    .plp-row i{ font-size:14px; }
    .plp-row{ gap:5px; }
}


/* ══════════════════════════════════════
   PROFILE LINKS MODAL
══════════════════════════════════════ */
#profileLinksOverlay{
    display:none;
    position:fixed; inset:0;
    z-index:999999999;
    background:rgba(0,0,0,0.7);
    backdrop-filter:blur(4px);
    -webkit-backdrop-filter:blur(4px);
    align-items:flex-end;
    justify-content:center;
}
#profileLinksOverlay.open{ display:flex; }

.pl-sheet{
    background:rgba(12,12,28,0.98);
    border:1px solid rgba(255,255,255,0.08);
    border-radius:22px 22px 0 0;
    width:100%;
    max-width:480px;
    max-height:75vh;
    overflow-y:auto;
    padding:8px 0 24px;
    animation:plSlideUp 0.3s cubic-bezier(0.32,0.72,0,1);
    scrollbar-width:thin;
    scrollbar-color:rgba(255,255,255,0.15) transparent;
}
.pl-sheet::-webkit-scrollbar{ width:4px; }
.pl-sheet::-webkit-scrollbar-thumb{ background:rgba(255,255,255,0.15); border-radius:999px; }

@keyframes plSlideUp{ from{transform:translateY(60px);opacity:0;} to{transform:translateY(0);opacity:1;} }

.pl-handle{
    width:36px; height:4px; border-radius:4px;
    background:rgba(255,255,255,0.18);
    margin:8px auto 14px;
}

.pl-title{
    font-size:14px; font-weight:700; color:#fff;
    text-align:center; margin-bottom:12px;
    padding:0 20px;
}

.pl-item{
    display:flex; align-items:center; gap:12px;
    padding:13px 20px;
    text-decoration:none;
    color:#fff;
    transition:background 0.18s;
    border-bottom:1px solid rgba(255,255,255,0.05);
}
.pl-item:last-child{ border-bottom:none; }
.pl-item:hover{ background:rgba(255,255,255,0.04); }

.pl-icon{
    width:36px; height:36px; border-radius:10px; flex-shrink:0;
    background:rgba(155,123,212,0.16);
    display:flex; align-items:center; justify-content:center;
    color:#9B7BD4; font-size:16px;
}

.pl-info{ flex:1; min-width:0; }
.pl-item-title{ font-size:13.5px; font-weight:600; color:#fff; margin-bottom:2px; }
.pl-item-url{
    font-size:11.5px; color:#9B7BD4;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.pl-chevron{ font-size:16px; color:rgba(255,255,255,0.25); flex-shrink:0; }

/* Desktop: centered form-style modal instead of bottom sheet */
@media (min-width:600px){
    #profileLinksOverlay{ align-items:center; }
    .pl-sheet{
        border-radius:20px;
        max-width:420px;
        border:1px solid rgba(255,255,255,0.08);
    }
    .pl-handle{ display:none; }
    .pl-title{ margin-top:6px; }
}

@media (max-width:790px){
    .pl-sheet{ max-height:78vh; padding:7px 0 20px; }
    .pl-title{ font-size:13.5px; }
    .pl-item{ padding:12px 18px; gap:11px; }
    .pl-icon{ width:34px; height:34px; font-size:15px; border-radius:9.5px; }
    .pl-item-title{ font-size:13px; }
    .pl-item-url{ font-size:11px; }
}

@media (max-width:490px){
    .pl-sheet{ max-height:80vh; padding:6px 0 18px; border-radius:18px 18px 0 0; }
    .pl-handle{ width:32px; height:3.5px; margin:7px auto 12px; }
    .pl-title{ font-size:13px; margin-bottom:10px; }
    .pl-item{ padding:11px 16px; gap:10px; }
    .pl-icon{ width:32px; height:32px; font-size:14px; border-radius:9px; }
    .pl-item-title{ font-size:12.5px; }
    .pl-item-url{ font-size:11px; }
    .pl-chevron{ font-size:15px; }
}

@media (max-width:390px){
    .pl-title{ font-size:12.5px; }
    .pl-item{ padding:10px 14px; gap:9px; }
    .pl-icon{ width:30px; height:30px; font-size:13.5px; border-radius:8px; }
    .pl-item-title{ font-size:12px; }
    .pl-item-url{ font-size:10.5px; }
}

@media (max-width:330px){
    .pl-title{ font-size:12px; }
    .pl-item{ padding:9px 12px; gap:8px; }
    .pl-icon{ width:28px; height:28px; font-size:13px; border-radius:7px; }
    .pl-item-title{ font-size:11.5px; }
    .pl-item-url{ font-size:10px; }
    .pl-chevron{ font-size:14px; }
}
</style>

<script>
function openProfileLinksModal(){
    var m = document.getElementById('profileLinksOverlay');
    if(!m) return;
    m.classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeProfileLinksModal(){
    var m = document.getElementById('profileLinksOverlay');
    if(!m) return;
    m.classList.remove('open');
    document.body.style.overflow = '';
}
document.addEventListener('keydown', function(e){
    if(e.key === 'Escape') closeProfileLinksModal();
});
</script>

    <!-- Follow / Unfollow, Message, Block / Unblock Button   -->
   <?php if($current_user != $profile_id): ?>
<div class="profile-action-buttons" style="display:flex; gap:10px; margin-top:10px; align-items:center;">

<style>
/* ── UNTRACK SEAMLESS BUTTON ── */
.untrack-split{
    display:inline-flex;
    align-items:center;
    gap:2px;
    position:relative;
    background:rgba(255,255,255,0.06);
    backdrop-filter:blur(10px);
    border:2px solid rgba(255,45,45,0.45);
    border-radius:25px;
    padding:9px 14px 9px 16px;
    cursor:pointer;
    transition:all 0.25s ease;
}
.untrack-split:hover{background:rgba(255,45,45,0.08);border-color:#FF2D2D;}
.untrack-main{
    color:#FF2D2D;
    font-weight:500;
    font-size:14px;
    font-family:'Poppins',sans-serif;
    line-height:1;
    white-space:nowrap;
    cursor:pointer;
}
.untrack-chevron{
    color:#FF2D2D;
    font-size:17px;
    line-height:1;
    display:flex;align-items:center;justify-content:center;
    transition:transform 0.25s ease;
    cursor:pointer;
}
.untrack-chevron.open{transform:rotate(180deg);}


/* ── MUTE/RESTRICT BOTTOM SHEET ── */
#mrSheet{
    display:none;
    position:fixed;
    inset:0;
    z-index:2147483647;
    background:rgba(0,0,0,0.7);
    backdrop-filter:blur(2px);
    align-items:flex-end;
    justify-content:center;
    padding:0;
}
#mrSheet.open{display:flex;}
.mr-inner{
    background:rgb(0, 2, 15);
    border:transparent;
    border-bottom:none;
    border-radius:28px 28px 0 0;
    width:100%;
    max-width:460px;
    padding-bottom:max(env(safe-area-inset-bottom, 0px), 14px);
    box-shadow:0 -20px 60px rgba(0,0,0,0.5);
    animation:mrSlideUp 0.32s cubic-bezier(0.32,0.94,0.6,1);
}
@keyframes mrSlideUp{from{transform:translateY(70px);opacity:0;}to{transform:translateY(0);opacity:1;}}
.mr-handle{width:38px;height:4px;border-radius:4px;background:rgba(255,255,255,0.16);margin:14px auto 2px;}

/* ── USER IDENTITY ROW (pic + username) ── */
.mr-user-row{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:9px;
    padding:8px 20px 16px;
}

/* ── PROFILE PIC — forced circle, no bleed from global styles ── */
#mrSheet .mr-user-pic,
#mrSheet img.mr-user-pic {
    width:34px !important;
    height:34px !important;
    min-width:34px !important;
    min-height:34px !important;
    max-width:34px !important;
    max-height:34px !important;
    border-radius:50% !important;
    object-fit:cover !important;
    border:1.5px solid #FF2D2D !important;
    flex-shrink:0 !important;
    display:block !important;
    box-shadow:none !important;
    filter:none !important;
    transform:none !important;
    margin:0 !important;
    padding:0 !important;
    cursor:default !important;
    background:transparent !important;
}

.mr-username-text{
    font-size:13px;
    font-weight:700;
    color:#FF2D2D;
    letter-spacing:0.3px;
    text-transform:uppercase;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
    max-width:200px;
}

.mr-divider{
    border:none;
    height:1px;
    margin:0 20px;
    background:linear-gradient(90deg, transparent, rgba(255,255,255,0.08), transparent);
}
.mr-row{
    display:flex;align-items:center;gap:16px;
    padding:16px 22px;
    cursor:pointer;
    transition:background 0.18s ease;
    -webkit-tap-highlight-color:transparent;
}
.mr-row + .mr-row{
    border-top:1px solid rgba(255,255,255,0.045);
}
.mr-row:hover{background:rgba(255,255,255,0.04);}
.mr-row:active{background:rgba(255,255,255,0.07);}

.mr-icon{
    width:22px;
    flex-shrink:0;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:19px;
    color:#FF2D2D;
}

.mr-label{font-size:14.5px;font-weight:600;color:#fff;letter-spacing:0.1px;}
.mr-sub{font-size:11.5px;color:rgba(255,255,255,0.4);margin-top:3px;line-height:1.35;}
.mr-label.red{color:#FF2D2D;}
.mr-state-badge{
    margin-left:auto;
    font-size:10px;font-weight:700;
    padding:3px 10px;border-radius:99px;
    flex-shrink:0;
    letter-spacing:0.3px;
}
.mr-state-badge.on-mute{background:rgba(255,45,45,0.1);color:#FF2D2D;}
.mr-state-badge.on-restrict{background:rgba(255,45,45,0.1);color:#FF2D2D;}
.mr-cancel{
    text-align:center;padding:16px 20px 6px;
    font-size:13.5px;font-weight:600;
    color:rgba(255,255,255,0.4);
    cursor:pointer;
    transition:color 0.18s ease;
    -webkit-tap-highlight-color:transparent;
}
.mr-cancel:hover{color:rgba(255,255,255,0.75);}

/* Desktop modal style */
@media(min-width:600px){
    #mrSheet{align-items:center;padding:20px;}
    .mr-inner{border-radius:22px;max-width:380px;border-bottom:1px solid rgba(255,255,255,0.07);}
}

/* Toast */
.mr-toast{
    position:fixed;bottom:90px;left:50%;transform:translateX(-50%) translateY(10px);
    background:rgba(8,8,22,.97);border:1px solid rgba(255,45,45,.25);color:#fff;
    padding:9px 24px;border-radius:50px;font-size:12px;
    font-family:'Poppins',sans-serif;z-index:9999999;pointer-events:none;
    box-shadow:0 8px 24px rgba(0,0,0,.6);white-space:nowrap;
    opacity:0;transition:opacity .22s ease,transform .22s ease;
}
.mr-toast.show{opacity:1;transform:translateX(-50%) translateY(0);}

/* ── 770px ── */
@media(max-width:770px){
    .untrack-split{padding:5px 9px 5px 9px;gap:2px;}
    .untrack-main{font-size:11.5px;}
    .untrack-chevron{font-size:15px;}

    #mrSheet .mr-user-pic,
    #mrSheet img.mr-user-pic{width:32px !important;height:32px !important;min-width:32px !important;min-height:32px !important;max-width:32px !important;max-height:32px !important;}
    .mr-username-text{font-size:12px;max-width:170px;}
    .mr-row{padding:14px 20px;gap:14px;}
    .mr-icon{font-size:18px;width:21px;}
    .mr-label{font-size:13px;font-weight:500;}
    .mr-sub{font-size:10.5px;}
    .mr-state-badge{font-size:9.5px;padding:2px 9px;}
    .mr-cancel{font-size:12.5px;padding:14px 18px 5px;}
}

/* ── 490px ── */
@media(max-width:490px){
    .untrack-split{padding:5px 8px 5px 10px;}
    .untrack-main{font-size:10.5px;}
    .untrack-chevron{font-size:14px;}

    #mrSheet .mr-user-pic,
    #mrSheet img.mr-user-pic{width:30px !important;height:30px !important;min-width:30px !important;min-height:30px !important;max-width:30px !important;max-height:30px !important;}
    .mr-username-text{font-size:11px;max-width:150px;letter-spacing:0.1px;}
    .mr-row{padding:12px 16px;gap:12px;}
    .mr-icon{font-size:16px;width:19px;}
    .mr-label{font-size:12px;font-weight:500;letter-spacing:0;}
    .mr-sub{font-size:10px;margin-top:2px;color:rgba(255,255,255,0.35);}
    .mr-state-badge{font-size:8.5px;padding:2px 8px;}
    .mr-cancel{font-size:11.5px;padding:12px 16px 5px;font-weight:500;}
    .mr-handle{width:34px;height:3.5px;margin:12px auto 2px;}
}

/* ── 390px ── */
@media(max-width:380px){
    .untrack-split{padding: 5px 8px 5px 9px;gap:2px;}
    .untrack-main{font-size:10.4px;}
    .untrack-chevron{font-size:12.3px;}

    #mrSheet .mr-user-pic,
    #mrSheet img.mr-user-pic{width:28px !important;height:28px !important;min-width:28px !important;min-height:28px !important;max-width:28px !important;max-height:28px !important;border-width:1.5px !important;}
    .mr-username-text{font-size:10px;max-width:130px;letter-spacing:0.1px;}
    .mr-user-row{gap:7px;padding:6px 16px 13px;}
    .mr-row{padding:10px 14px;gap:10px;}
    .mr-icon{font-size:15px;width:18px;}
    .mr-label{font-size:11.5px;font-weight:400;letter-spacing:0;}
    .mr-sub{font-size:9.5px;margin-top:2px;color:rgba(255,255,255,0.3);}
    .mr-state-badge{font-size:8px;padding:2px 7px;}
    .mr-cancel{font-size:11px;padding:11px 14px 4px;font-weight:400;}
    .mr-handle{width:30px;height:3px;margin:11px auto 2px;}
}

/* ── 330px ── */
@media(max-width:330px){
    .untrack-split{padding:4.9px 8px 4.9px 10px;gap:1px;}
    .untrack-main{font-size:10px;}
    .untrack-chevron{font-size:12px;}

    #mrSheet .mr-user-pic,
    #mrSheet img.mr-user-pic{width:26px !important;height:26px !important;min-width:26px !important;min-height:26px !important;max-width:26px !important;max-height:26px !important;border-width:1.5px !important;}
    .mr-username-text{font-size:9.5px;max-width:110px;letter-spacing:0.05px;}
    .mr-user-row{gap:6px;padding:5px 13px 11px;}
    .mr-row{padding:9px 13px;gap:9px;}
    .mr-icon{font-size:14px;width:16px;}
    .mr-label{font-size:11px;font-weight:400;letter-spacing:0;}
    .mr-sub{font-size:9px;margin-top:1px;line-height:1.3;color:rgba(255,255,255,0.28);}
    .mr-state-badge{font-size:7.5px;padding:2px 6px;}
    .mr-cancel{font-size:10.5px;padding:10px 13px 4px;font-weight:400;}
    .mr-handle{width:28px;height:3px;margin:10px auto 1px;}
}

.spinner-icon {
    display: inline-flex; align-items: center; justify-content: center;
    gap: 3px; height: 16px; vertical-align: middle;
}
.spinner-icon span {
    display: block; width: 3px; background: #d53333; border-radius: 2px;
    animation: btn-bar 1s ease-in-out infinite;
}
.spinner-icon span:nth-child(1) { animation-delay: 0s; }
.spinner-icon span:nth-child(2) { animation-delay: 0.15s; }
.spinner-icon span:nth-child(3) { animation-delay: 0.3s; }
.spinner-icon span:nth-child(4) { animation-delay: 0.45s; }
@keyframes btn-bar {
    0%,100% { height: 4px; opacity: 0.4; }
    50%      { height: 10px; opacity: 1; }
}
</style>

<?php
$profile_is_private   = !empty($settings['is_fully_private']);
$track_request_status = null;

if($current_user && $current_user != $profile_id) {
    $tr = $conn->prepare("SELECT status FROM track_requests WHERE requester_id=? AND target_id=?");
    $tr->bind_param("ii", $current_user, $profile_id);
    $tr->execute();
    $tr_row               = $tr->get_result()->fetch_assoc();
    $tr->close();
    $track_request_status = $tr_row ? $tr_row['status'] : null;
}
?>

<form method="POST" style="margin:0;" id="trackForm">
<?php if(!$current_user || $current_user == $profile_id): ?>
    <?php /* Own profile or not logged in — no button */ ?>

<?php elseif($follow_check): ?>
    <!-- ── UNTRACK BUTTON (always opens modal) ── -->
    <div class="untrack-split" onclick="openMrSheet(event)">
        <span class="untrack-main" id="untrackMainBtn">Untrack</span>
        <span class="untrack-chevron" id="untrackChevron" aria-label="More options">
            <i class="ri-arrow-down-s-line"></i>
        </span>
    </div>

<?php elseif($profile_is_private): ?>

    <?php if($track_request_status === 'pending'): ?>
        <button class="action-btn follow-btn" name="cancel_track_request"
                onclick="showSpinner(this); return false;"
                style="background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.15);color:rgba(255,255,255,0.45);display:flex;align-items:center;gap:6px;">
            <i class="ri-time-line" style="font-size:12px;"></i> Requested
        </button>

    <?php else: ?>
        <button class="action-btn follow-btn" name="send_track_request" onclick="showSpinner(this); return false;">
            <i class="ri-user-add-line" style="font-size:12px;margin-right:4px;"></i>Request Track
        </button>

    <?php endif; ?>

<?php else: ?>

    <button class="action-btn follow-btn" name="follow" onclick="showSpinner(this); return false;">Track</button>
<?php endif; ?>
</form>


<!-- ═══════════════════════════════════════════════════════════
     MUTE / RESTRICT BOTTOM SHEET
     ═══════════════════════════════════════════════════════════ -->
<div id="mrSheet" onclick="if(event.target===this)closeMrSheet()">
    <div class="mr-inner">
        <div class="mr-handle"></div>

        <!-- Profile pic + red username -->
        <div class="mr-user-row">
            <img class="mr-user-pic"
                 src="<?= htmlspecialchars(!empty($user['profile_pic']) ? $user['profile_pic'] : 'uploads/mee.jpeg') ?>"
                 onerror="this.src='uploads/mee.jpeg'"
                 alt="<?= htmlspecialchars($user['username'] ?? '') ?>">
            <span class="mr-username-text"><?= htmlspecialchars($user['username'] ?? '') ?></span>
        </div>

        <!-- Mute Row -->
        <div class="mr-row" id="mrMuteRow" onclick="mrToggleMute()">
            <div class="mr-icon"><i class="ri-volume-mute-line"></i></div>
            <div style="flex:1;min-width:0;">
                <div class="mr-label" id="mrMuteLabel"><?= !empty($is_muted_by_me) ? 'Unmute Account' : 'Mute Account' ?></div>
                <div class="mr-sub" id="mrMuteSub">
                    <?= !empty($is_muted_by_me)
                        ? 'Their posts will reappear in your feed'
                        : "Hide their posts from your feed. They won't know." ?>
                </div>
            </div>
            <?php if (!empty($is_muted_by_me)): ?>
            <span class="mr-state-badge on-mute" id="mrMuteBadge">ON</span>
            <?php else: ?>
            <span class="mr-state-badge" id="mrMuteBadge" style="display:none;"></span>
            <?php endif; ?>
        </div>

        <!-- Restrict Row -->
        <div class="mr-row" id="mrRestrictRow" onclick="mrToggleRestrict()">
            <div class="mr-icon"><i class="ri-shield-line"></i></div>
            <div style="flex:1;min-width:0;">
                <div class="mr-label" id="mrRestrictLabel"><?= !empty($is_restricted_by_me) ? 'Remove Restriction' : 'Restrict Account' ?></div>
                <div class="mr-sub" id="mrRestrictSub">
                    <?= !empty($is_restricted_by_me)
                        ? 'Their messages will return to your main inbox'
                        : "Move their messages to requests. They won't know." ?>
                </div>
            </div>
            <?php if (!empty($is_restricted_by_me)): ?>
            <span class="mr-state-badge on-restrict" id="mrRestrictBadge">ON</span>
            <?php else: ?>
            <span class="mr-state-badge" id="mrRestrictBadge" style="display:none;"></span>
            <?php endif; ?>
        </div>

        <!-- UnTrack Row -->
        <div class="mr-row" onclick="mrDoUntrack()">
            <div class="mr-icon"><i class="ri-user-unfollow-line"></i></div>
            <div style="flex:1;min-width:0;">
                <div class="mr-label red">Untrack</div>
                <div class="mr-sub">Stop Tracking their posts and updates</div>
            </div>
        </div>

        <div class="mr-cancel" onclick="closeMrSheet()">Cancel</div>
    </div>
</div>

<div id="mrToast" class="mr-toast"></div>

<script>
function showSpinner(btn) {
    var actionName = btn.name;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-icon"><span></span><span></span><span></span><span></span></span>';

    setTimeout(function() {
        var form = document.getElementById('trackForm');
        var hidden = document.createElement('input');
        hidden.type  = 'hidden';
        hidden.name  = actionName;
        hidden.value = '1';
        form.appendChild(hidden);
        form.submit();
    }, 120);
}
</script>

<script>
(function(){
    var _profileId  = <?= (int)$profile_id ?>;
    var _isMuted    = <?= !empty($is_muted_by_me)      ? 'true' : 'false' ?>;
    var _isRestrict = <?= !empty($is_restricted_by_me) ? 'true' : 'false' ?>;

    window.openMrSheet = function(e) {
        if (e) e.stopPropagation();
        document.getElementById('mrSheet').classList.add('open');
        document.body.style.overflow = 'hidden';
    };

    window.closeMrSheet = function() {
        document.getElementById('mrSheet').classList.remove('open');
        document.body.style.overflow = '';
    };

    /* Untrack via main button — shows spinner then submits form */
    window.showSpinnerUntrack = function() {
        var btn = document.getElementById('untrackMainBtn');
        if (!btn) return;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-icon"><span></span><span></span><span></span><span></span></span>';
        setTimeout(function() {
            var form = document.getElementById('trackForm');
            var h = document.createElement('input');
            h.type = 'hidden'; h.name = 'unfollow'; h.value = '1';
            form.appendChild(h);
            form.submit();
        }, 120);
    };

    /* Untrack via bottom sheet */
    window.mrDoUntrack = function() {
        closeMrSheet();
        setTimeout(showSpinnerUntrack, 180);
    };

    /* ── Mute Toggle ── */
    window.mrToggleMute = function() {
        var action = _isMuted ? 'unmute' : 'mute';
        _mrFetch(action, function(d) {
            if (d.status !== 'ok') { mrShowToast('Something went wrong'); return; }
            _isMuted = !_isMuted;
            _updateMuteUI();
            _updateStatusIcons();
            mrShowToast(_isMuted ? 'Account muted' : 'Account unmuted');
        });
    };

    function _updateMuteUI() {
        var lbl  = document.getElementById('mrMuteLabel');
        var sub  = document.getElementById('mrMuteSub');
        var bdg  = document.getElementById('mrMuteBadge');
        if (lbl) lbl.textContent = _isMuted ? 'Unmute Account' : 'Mute Account';
        if (sub) sub.textContent = _isMuted
            ? 'Their posts will reappear in your feed'
            : "Hide their posts from your feed. They won't know.";
        if (bdg) {
            bdg.textContent = _isMuted ? 'ON' : '';
            bdg.className = 'mr-state-badge' + (_isMuted ? ' on-mute' : '');
            bdg.style.display = _isMuted ? '' : 'none';
        }
    }

    /* ── Restrict Toggle ── */
    window.mrToggleRestrict = function() {
        var action = _isRestrict ? 'unrestrict' : 'restrict';
        _mrFetch(action, function(d) {
            if (d.status !== 'ok') { mrShowToast('Something went wrong'); return; }
            _isRestrict = !_isRestrict;
            _updateRestrictUI();
            _updateStatusIcons();
            mrShowToast(_isRestrict ? 'Account restricted' : 'Restriction removed');
        });
    };

    function _updateRestrictUI() {
        var lbl  = document.getElementById('mrRestrictLabel');
        var sub  = document.getElementById('mrRestrictSub');
        var bdg  = document.getElementById('mrRestrictBadge');
        if (lbl) lbl.textContent = _isRestrict ? 'Remove Restriction' : 'Restrict Account';
        if (sub) sub.textContent = _isRestrict
            ? 'Their messages will return to your main inbox'
            : "Move their messages to requests. They won't know.";
        if (bdg) {
            bdg.textContent = _isRestrict ? 'ON' : '';
            bdg.className = 'mr-state-badge' + (_isRestrict ? ' on-restrict' : '');
            bdg.style.display = _isRestrict ? '' : 'none';
        }
    }

    /* ── Update status icons beside Untrack button (removed) ── */
    function _updateStatusIcons() { /* no-op: indicators removed */ }

    /* ── AJAX helper ── */
    function _mrFetch(action, cb) {
        fetch('profile.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'mr_action=' + action + '&target_id=' + _profileId
        }).then(function(r) { return r.json(); }).then(cb)
          .catch(function() { mrShowToast('Network error'); });
    }

    /* ── Toast ── */
    window.mrShowToast = function(msg) {
        var t = document.getElementById('mrToast');
        if (!t) return;
        t.textContent = msg;
        t.classList.add('show');
        clearTimeout(t._tid);
        t._tid = setTimeout(function() { t.classList.remove('show'); }, 2400);
    };

    /* Close sheet on ESC */
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeMrSheet();
    });

    /* Swipe-down to close */
    var _sy = 0;
    var sh = document.getElementById('mrSheet');
    if (sh) {
        sh.addEventListener('touchstart', function(e) { _sy = e.touches[0].clientY; }, { passive: true });
        sh.addEventListener('touchend', function(e) {
            if (e.changedTouches[0].clientY - _sy > 70) closeMrSheet();
        }, { passive: true });
    }
})();
</script>

<?php if (!$is_blocked): ?>
<!-- Message Button -->
<a href="<?php echo (!$blocked_check && $can_view) ? 'messages.php?user='.$profile_id : '#'; ?>" style="text-decoration:none;">
    <button class="action-btn message-btn"
        <?php 
            if($blocked_check) {
                echo 'onclick="alert(\'You are blocked, you cannot message this Peumer.\'); return false;"';
            } elseif(!$can_view) {
                echo 'onclick="alert(\'Track this Peumer to message them.\'); return false;"';
            }
        ?>>
        Message
    </button>
</a>
   <?php endif; ?>

    <!-- Block / Unblock Button -->
    <form method="POST" style="margin:0;">
    <?php if($is_blocked): ?>
        <button type="submit" name="unblock" class="block-btn unblock">
            <i class="ri-user-unfollow-line"></i> Unblock
        </button>
    <?php else: ?>
        <button type="submit" name="block" class="block-btn block">
            <i class="ri-user-add-line"></i> Block
        </button>
    <?php endif; ?>
</form>


<?php if($current_user != $profile_id): ?>
<!-- Kebab button -->
<button class="profile-kebab-btn" onclick="openProfileKebabModal()"><i class="ri-menu-line"></i></button>

<!-- ══════════════════════════════════════
     FULL-SCREEN PROFILE KEBAB MODAL
══════════════════════════════════════ -->
<div id="profileKebabFullModal" class="pkbm-overlay">

    <div class="pkbm-topbar">
        <button class="pkbm-back-btn" onclick="closeProfileKebabModal()">
            <i class="ri-arrow-left-s-line"></i>
        </button>
        <div class="pkbm-topbar-title"><?php echo htmlspecialchars($user['username']); ?></div>
        <div class="pkbm-topbar-spacer"></div>
    </div>

    <div class="pkbm-body">

        <!-- ══════════ SKELETON LOADER ══════════ -->
        <div id="pkbmSkeleton" class="pkbm-skeleton" aria-hidden="true">
            <?php for ($i = 0; $i < 11; $i++): ?>
            <div class="pkbm-skel-row">
                <div class="pkbm-skel-icon"></div>
                <div class="pkbm-skel-text" style="width: <?php echo rand(55, 85); ?>%;"></div>
            </div>
            <?php endfor; ?>
        </div>

        <!-- ══════════ REAL MENU CONTENT ══════════ -->
        <div id="pkbmContent" class="pkbm-content">

        <?php if (!$is_blocked): ?>
        <a href="<?php echo (!$blocked_check && $can_view) ? 'audio.php?user_id='.$profile_id : '#'; ?>"
   <?php 
   if($blocked_check){
       echo 'onclick="alert(\'You are blocked, you cannot view this.\'); return false;"';
   } elseif(!$can_view){
       echo 'onclick="alert(\'Track this Peumer to view their playlist.\'); return false;"';
   }
   ?> class="pkbm-row">
   <i class="ri-play-list-line"></i> User Sounds
</a>

<a href="<?php echo (!$blocked_check && $can_view) ? 'profile_longform.php?user_id='.$profile_id : '#'; ?>"
   <?php 
   if($blocked_check){
       echo 'onclick="alert(\'You are blocked, you cannot view this.\'); return false;"';
   } elseif(!$can_view){
       echo 'onclick="alert(\'Track this Peumer to view their videos.\'); return false;"';
   }
   ?> class="pkbm-row">
   <i class="ri-video-line"></i> User Videos
</a>

<a href="<?php echo (!$blocked_check && $can_view) ? 'account.php?user_id='.$profile_id : '#'; ?>"
   <?php 
   if($blocked_check){
       echo 'onclick="alert(\'You are blocked, you cannot view this account.\'); return false;"';
   } elseif(!$can_view){
       echo 'onclick="alert(\'Track this Peumer to view their account.\'); return false;"';
   }
   ?> class="pkbm-row">
   <i class="ri-user-line"></i> About Account
</a>


<a href="<?php echo (!$blocked_check && $can_view) ? 'links.php?user_id='.$profile_id : '#'; ?>"
   <?php 
       if($blocked_check){
           echo 'onclick="alert(\'You are blocked, you cannot view this user\'s links.\'); return false;"';
       } elseif(!$can_view){
           echo 'onclick="alert(\'Track this Peumer to view their links.\'); return false;"';
       }
   ?> class="pkbm-row">
   <i class="ri-link-m"></i> Links
</a>

<a href="search.php?focus=1" class="pkbm-row">
    <i class="ri-search-line"></i> Search
</a>

<!-- LEAVE THESE UNTOUCHED -->
<a href="#" onclick="closeProfileKebabModal(); shareProfile(<?php echo $profile_id; ?>)" class="pkbm-row">
    <i class="ri-share-line"></i> Share Profile
</a>

<a href="profile_share.php?user_id=<?php echo $profile_id; ?>" class="pkbm-row">
    <i class="ri-links-line"></i> View Shared Activities
</a>

<a href="#" onclick="closeProfileKebabModal(); copyProfileLinkPK(<?php echo $profile_id; ?>); return false;" class="pkbm-row">
    <i class="ri-file-copy-line"></i> Copy <span style="color:#FF2D2D;font-weight:600;"><?php echo htmlspecialchars($user['username']); ?>'s</span> Profile Link
</a>
        <?php endif; ?>

       <!-- Block / Unblock -->
<form method="POST" style="margin:0;">
    <?php if($is_blocked): ?>
        <button type="submit" name="unblock" class="pkbm-row pkbm-form-row pkbm-row-danger">
            <i class="ri-user-unfollow-line"></i> Unblock
        </button>
    <?php else: ?>
        <button type="submit" name="block" class="pkbm-row pkbm-form-row">
            <i class="ri-user-add-line"></i> Block
        </button>
    <?php endif; ?>
</form>

        <a href="#" onclick="closeProfileKebabModal(); openReportModal({userId: <?php echo (int)$profile_id; ?>}); return false;" class="pkbm-row pkbm-row-danger">
            <i class="ri-flag-line"></i> Report
        </a>

        <a href="Help.php" class="pkbm-row">
            <i class="ri-question-line"></i> Help and Feedback
        </a>

        </div>
    </div>
</div>

<style>
/* ══════════════════════════════════════
   FULL-SCREEN PROFILE KEBAB MODAL
   (matches kbm-overlay exactly, separate
   namespace so both can co-exist safely)
══════════════════════════════════════ */
.pkbm-overlay {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 999999999999;
    font-family: 'Poppins', sans-serif;
    background: linear-gradient(-45deg,#010218,#070924,#070924);
    background-attachment: fixed;
    overflow-y: scroll;
    -webkit-overflow-scrolling: touch;
    opacity: 0;
    transform: translateY(18px);
    transition: opacity .25s ease, transform .28s cubic-bezier(.22,1,.36,1);
}

.pkbm-overlay::-webkit-scrollbar { width: 5px; }
.pkbm-overlay::-webkit-scrollbar-track { background: transparent; }
.pkbm-overlay::-webkit-scrollbar-thumb {
    background: rgba(255,255,255,0.08);
    border-radius: 10px;
}
.pkbm-overlay:hover::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.35); }
.pkbm-overlay::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.5); }
.pkbm-overlay {
    scrollbar-width: thin;
    scrollbar-color: rgba(255,255,255,0.08) transparent;
}
.pkbm-overlay:hover { scrollbar-color: rgba(255,255,255,0.35) transparent; }

.pkbm-overlay.pkbm-open {
    display: block;
    opacity: 1;
    transform: translateY(0);
}

.pkbm-topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 18px;
    border-bottom: 1px solid rgba(255,255,255,0.07);
    position: sticky;
    top: 0;
    background: linear-gradient(-45deg,#010218,#070924,#070924);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    z-index: 3;
}

.pkbm-back-btn {
    background: none;
    border: none;
    color: #fff;
    font-size: 30px;
    cursor: pointer;
    display: flex;
    align-items: center;
    line-height: 1;
    padding: 2px;
}

.pkbm-topbar-title {
    font-size: 16px;
    font-weight: 700;
    color: #fff;
    letter-spacing: -0.2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 70vw;
}

.pkbm-topbar-spacer {
    width: 30px;
    flex-shrink: 0;
}

.pkbm-body {
    padding: 6px 0 50px;
    display: flex;
    flex-direction: column;
    position: relative;
}

.pkbm-row {
    padding: 15px 22px;
    color: white;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 14px;
    border-bottom: 1px solid rgba(255,255,255,0.045);
    transition: 0.2s;
    background: none;
    border: none;
    border-bottom: 1px solid rgba(255,255,255,0.045);
    width: 100%;
    text-align: left;
    font-family: 'Poppins', sans-serif;
    cursor: pointer;
}
.pkbm-row:hover {
    background: rgba(255,255,255,0.06);
    color: #ff2626;
}
.pkbm-row:hover i {
    transform: scale(1.2) rotate(10deg);
}
.pkbm-row i {
    font-size: 19px;
    color: #FF2D2D;
    flex-shrink: 0;
    width: 22px;
    text-align: center;
    transition: transform 0.2s;
}

.pkbm-row-danger { color: #FF2D2D; }
.pkbm-row-danger:hover { color: #ff5555; }

.pkbm-form-row { margin: 0; }

/* ══════════ SKELETON LOADER STYLES ══════════ */
.pkbm-content {
    opacity: 0;
    transform: translateY(4px);
    pointer-events: none;
    transition: opacity 0.35s ease, transform 0.35s ease;
}
.pkbm-content.pkbm-content-ready {
    opacity: 1;
    transform: translateY(0);
    pointer-events: auto;
}

.pkbm-skeleton {
    display: flex;
    flex-direction: column;
    transition: opacity 0.25s ease;
}
.pkbm-skeleton.pkbm-skel-hide {
    opacity: 0;
    pointer-events: none;
    position: absolute;
    inset: 0;
}

.pkbm-skel-row {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 15px 22px;
    box-sizing: border-box;
}

.pkbm-skel-icon {
    width: 19px;
    height: 19px;
    flex-shrink: 0;
    border-radius: 6px;
    background: linear-gradient(
        90deg,
        rgba(255,255,255,0.06) 25%,
        rgba(255,255,255,0.14) 37%,
        rgba(255,255,255,0.06) 63%
    );
    background-size: 400% 100%;
    animation: pkbmShimmer 1.4s ease-in-out infinite;
}

.pkbm-skel-text {
    height: 14px;
    border-radius: 4px;
    background: linear-gradient(
        90deg,
        rgba(255,255,255,0.06) 25%,
        rgba(255,255,255,0.14) 37%,
        rgba(255,255,255,0.06) 63%
    );
    background-size: 400% 100%;
    animation: pkbmShimmer 1.4s ease-in-out infinite;
}

@keyframes pkbmShimmer {
    0%   { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
}

.pkbm-skel-row:nth-child(1)  .pkbm-skel-icon, .pkbm-skel-row:nth-child(1)  .pkbm-skel-text { animation-delay: 0s; }
.pkbm-skel-row:nth-child(2)  .pkbm-skel-icon, .pkbm-skel-row:nth-child(2)  .pkbm-skel-text { animation-delay: 0.05s; }
.pkbm-skel-row:nth-child(3)  .pkbm-skel-icon, .pkbm-skel-row:nth-child(3)  .pkbm-skel-text { animation-delay: 0.1s; }
.pkbm-skel-row:nth-child(4)  .pkbm-skel-icon, .pkbm-skel-row:nth-child(4)  .pkbm-skel-text { animation-delay: 0.15s; }
.pkbm-skel-row:nth-child(5)  .pkbm-skel-icon, .pkbm-skel-row:nth-child(5)  .pkbm-skel-text { animation-delay: 0.2s; }
.pkbm-skel-row:nth-child(6)  .pkbm-skel-icon, .pkbm-skel-row:nth-child(6)  .pkbm-skel-text { animation-delay: 0.25s; }
.pkbm-skel-row:nth-child(7)  .pkbm-skel-icon, .pkbm-skel-row:nth-child(7)  .pkbm-skel-text { animation-delay: 0.3s; }
.pkbm-skel-row:nth-child(8)  .pkbm-skel-icon, .pkbm-skel-row:nth-child(8)  .pkbm-skel-text { animation-delay: 0.35s; }
.pkbm-skel-row:nth-child(9)  .pkbm-skel-icon, .pkbm-skel-row:nth-child(9)  .pkbm-skel-text { animation-delay: 0.4s; }
.pkbm-skel-row:nth-child(10) .pkbm-skel-icon, .pkbm-skel-row:nth-child(10) .pkbm-skel-text { animation-delay: 0.45s; }
.pkbm-skel-row:nth-child(11) .pkbm-skel-icon, .pkbm-skel-row:nth-child(11) .pkbm-skel-text { animation-delay: 0.5s; }

/* Responsive */
@media (max-width: 768px) {
    .pkbm-topbar { padding: 13px 16px; }
    .pkbm-topbar-title { font-size: 14.5px; }
    .pkbm-row { padding: 13px 18px; font-size: 13.5px; gap: 11px; }
    .pkbm-row i { font-size: 17px; }
    .pkbm-skel-row { padding: 13px 18px; gap: 11px; }
}

@media (max-width: 480px) {
    .pkbm-topbar { padding: 11px 14px; }
    .pkbm-back-btn { font-size: 26px; }
    .pkbm-topbar-title { font-size: 13.5px; }
    .pkbm-row { padding: 12px 16px; font-size: 12.5px; gap: 10px; }
    .pkbm-row i { font-size: 16px; }
    .pkbm-skel-row { padding: 12px 16px; gap: 10px; }
}

@media (max-width: 380px) {
    .pkbm-topbar { padding: 10px 12px; }
    .pkbm-back-btn { font-size: 23px; }
    .pkbm-topbar-title { font-size: 12.5px; }
    .pkbm-row { padding: 10px 13px; font-size: 11.5px; gap: 8px; }
    .pkbm-row i { font-size: 14px; width: 18px; }
    .pkbm-skel-row { padding: 10px 13px; gap: 8px; }
}

@media (max-width: 360px) {
    .pkbm-row { font-size: 11px; padding: 9px 12px; }
    .pkbm-row i { font-size: 13.5px; width: 17px; }
    .pkbm-skel-row { padding: 9px 12px; }
}

@media (max-width: 330px) {
    .pkbm-topbar { padding: 9px 10px; }
    .pkbm-back-btn { font-size: 21px; }
    .pkbm-topbar-title { font-size: 11.5px; }
    .pkbm-row { font-size: 10.5px; padding: 8px 10px; gap: 7px; }
    .pkbm-row i { font-size: 12.5px; width: 16px; }
    .pkbm-skel-row { padding: 8px 10px; gap: 7px; }
}
</style>

<script>
var pkbmSkeletonShown = false; // tracks whether skeleton already played this page load

function openProfileKebabModal() {
    var m = document.getElementById('profileKebabFullModal');
    if (!m) return;

    var skeleton = document.getElementById('pkbmSkeleton');
    var content  = document.getElementById('pkbmContent');

    m.style.display = 'block';
    document.body.style.overflow = 'hidden';
    requestAnimationFrame(function () {
        requestAnimationFrame(function () { m.classList.add('pkbm-open'); });
    });

    if (pkbmSkeletonShown) {
        // Already loaded once this page — skip straight to content, no shimmer
        if (skeleton) skeleton.classList.add('pkbm-skel-hide');
        if (content)  content.classList.add('pkbm-content-ready');
        return;
    }

    // First open this page load — show the skeleton
    if (skeleton) skeleton.classList.remove('pkbm-skel-hide');
    if (content)  content.classList.remove('pkbm-content-ready');

    var minDelay = 450;
    setTimeout(function () {
        if (skeleton) skeleton.classList.add('pkbm-skel-hide');
        if (content)  content.classList.add('pkbm-content-ready');
        pkbmSkeletonShown = true;
    }, minDelay);
}

function closeProfileKebabModal() {
    var m = document.getElementById('profileKebabFullModal');
    if (!m) return;
    m.classList.remove('pkbm-open');
    setTimeout(function () {
        m.style.display = 'none';
        document.body.style.overflow = '';
    }, 270);
}

function copyProfileLinkPK(profileId) {
    var link = window.location.origin + '/profile.php?user_id=' + profileId;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(link).then(function () {
            if (typeof showToast === 'function') {
                showToast('Profile link copied!');
            } else {
                alert('Profile link copied!');
            }
        }).catch(function () {
            alert('Could not copy link. Long-press the URL bar to copy manually.');
        });
    } else {
        var tempInput = document.createElement('input');
        tempInput.value = link;
        document.body.appendChild(tempInput);
        tempInput.select();
        document.execCommand('copy');
        document.body.removeChild(tempInput);
        alert('Profile link copied!');
    }
}

function shareProfile(userId){
    const url = window.location.origin + '/profile.php?user_id=' + userId;

    if(navigator.share){
        navigator.share({
            title: 'Check out this profile on Peura',
            url: url
        }).catch(err => console.log(err));
    } else {
        // fallback: copy to clipboard
        navigator.clipboard.writeText(url).then(() => {
            let notif = document.createElement('div');
            notif.innerText = 'Profile link copied to clipboard!';
            notif.style.cssText = `
                position: fixed;
                top: 20px;
                left: 50%;
                transform: translateX(-50%);
                background: #e83939;
                color: #fff;
                padding: 12px 20px;
                border-radius: 12px;
                font-weight: 600;
                z-index: 3000;
                box-shadow: 0 4px 15px rgba(0,0,0,0.3);
                opacity: 0;
                transition: 0.3s;
            `;
            document.body.appendChild(notif);
            setTimeout(() => { notif.style.opacity = 1; }, 50);
            setTimeout(() => { notif.style.opacity = 0; notif.remove(); }, 2500);
        });
    }
}

function reportUser(profileId) {
    if (!confirm('Are you sure you want to report this user?')) return;

    fetch('report_user.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'reported_id=' + profileId
    })
    .then(response => response.text())
    .then(data => {
        // Show a simple popup at the top
        const popup = document.createElement('div');
        popup.innerText = 'This peumer has been reported!';
        popup.style.position = 'fixed';
        popup.style.top = '20px';
        popup.style.left = '50%';
        popup.style.transform = 'translateX(-50%)';
        popup.style.background = 'rgb(255, 0, 0)';
        popup.style.color = '#fff9f9';
        popup.style.padding = '10px 20px';
        popup.style.borderRadius = '12px';
        popup.style.fontWeight = '400';
        popup.style.zIndex = '9999';
        document.body.appendChild(popup);

        setTimeout(() => popup.remove(), 3000);
    })
    .catch(err => console.error(err));
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeProfileKebabModal();
});
</script>

<!-- ══════════════════════════════════════════════════════════
     REPORT MODAL v3 — Full-screen style matching plus modal
     Updated: scrollbar CSS only, no X on sub-modals,
     chevron-only back btn (bigger), all icons filled
══════════════════════════════════════════════════════════ -->

<!-- Trigger link (replace your existing one) -->
<!-- 
<a href="#" onclick="openReportModal({userId: <?php echo (int)$profile_id; ?>}); return false;" 
   style="color:#FF2D2D;">
   <i class="ri-flag-line"></i> Report
</a>
-->

<style>
/* ═══════════════════════════════════════════
   REPORT MODAL — FULL SCREEN
═══════════════════════════════════════════ */
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

/* Step 1 nested screen */
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

/* Success screen */
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

/* webkit scrollbar — CSS only, all three screens */
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

/* ── Topbar ── */
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

/* Chevron-only back button — bigger, no text */
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
    font-size: 30px; /* bigger chevron */
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

/* invisible spacer to keep centre aligned when no right button */
.dzrpt-topbar-spacer {
    width: 30px; height: 30px; flex-shrink: 0;
}

/* ── Section label ── */
.dzrpt-section-label {
    font-size: 9.5px; font-weight: 700; letter-spacing: 1.5px;
    text-transform: uppercase; color: rgba(255,255,255,.24);
    padding: 20px 22px 8px;
}

/* ── Category rows ── */
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

/* ── User ID field ── */
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

/* ── Reason rows ── */
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

/* ── Other textarea ── */
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

/* ── Footer buttons ── */
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

/* ── Success screen ── */
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

/* ── Toast ── */
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

/* ══ RESPONSIVE ══ */
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

    <div class="dzrpt-cat-row" onclick="dzRptSelectCat('message')">
        <div class="dzrpt-cat-icon"><i class="ri-message-2-line"></i></div>
        <div class="dzrpt-cat-info">
            <div class="dzrpt-cat-label">Reporting a Message</div>
            <div class="dzrpt-cat-sub">Something they sent you in DMs</div>
        </div>
        <i class="ri-arrow-right-s-line dzrpt-cat-chevron"></i>
    </div>

</div>


<!-- ══ SCREEN 1: Reason picker — NO X button, chevron-only back ══ -->
<div id="dzRptStep1Screen">

    <div class="dzrpt-topbar">
        <!-- Chevron only, no text, bigger -->
        <button class="dzrpt-back-btn" onclick="dzRptBack()" aria-label="Back">
            <i class="ri-arrow-left-s-line"></i>
        </button>
        <div class="dzrpt-topbar-center">
            <div class="dzrpt-topbar-title" id="dzRptTitle">Select a reason</div>
            <div class="dzrpt-topbar-sub" id="dzRptSubtitle">Your report is anonymous</div>
        </div>
        <!-- Spacer keeps title centred without an X -->
        <div class="dzrpt-topbar-spacer"></div>
    </div>

    <!-- User ID — only shown for user reports -->
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


<!-- ══ SCREEN 2: Success — NO X button ══ -->
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


<script>
(function () {
    var _rState = {
        step: 0, category: null,
        targetId: null, contentType: null,
        ownerId: null, reason: null
    };

    /* ── All reason lists — every item has an icon ── */
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
    var _messageReasons = [
        { icon:'ri-sword-line',           text:'Threatening or Violent Language' },
        { icon:'ri-psychotherapy-line',   text:'Sexual Harassment' },
        { icon:'ri-eye-2-line',           text:'Unwanted Sexual Content' },
        { icon:'ri-phishing-line',        text:'Scam or Phishing Link' },
        { icon:'ri-spam-2-line',          text:'Spam Messages' },
        { icon:'ri-prohibited-line',      text:'Hate Speech' },
        { icon:'ri-eye-off-line',         text:'Sharing Private Information' },
        { icon:'ri-spy-line',             text:'Impersonating Someone' },
        { icon:'ri-team-line',            text:'Coordinated Harassment' },
        { icon:'ri-scales-3-line',        text:'Illegal Content' },
        { icon:'ri-lock-password-line',   text:'Blackmail or Extortion' },
        { icon:'ri-question-line',        text:'Other' }
    ];

    /* ── helpers ── */
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

    /* ── public API ── */
    window.openReportModal = function (opts) {
        opts = opts || {};
        _rState.targetId    = opts.userId || opts.contentId || opts.messageId || null;
        _rState.contentType = opts.contentType || null;
        _rState.ownerId     = opts.ownerId || null;

        if (opts.userId && !opts.skipCat) {
            _rState.category = 'user';
            _rState.targetId = opts.userId;
            _buildReasonScreen('user');
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
        var reasons = cat === 'user' ? _userReasons
                    : cat === 'content' ? _contentReasons
                    : _messageReasons;
        var titles = {
            user:    'Why are you reporting this user?',
            content: 'Why are you reporting this content?',
            message: 'Why are you reporting this message?'
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
        if (cat === 'user') {
            fd.append('type', 'user');
            fd.append('reported_id', _rState.targetId);
            var uid = (document.getElementById('dzRptUserIdInput').value || '').trim();
            if (uid) fd.append('user_id_hint', uid);
        } else if (cat === 'content') {
            fd.append('type', 'content');
            fd.append('reported_id', _rState.targetId);
            fd.append('content_type', _rState.contentType || 'meme');
        } else {
            fd.append('type', 'content');
            fd.append('reported_id', _rState.targetId);
            fd.append('content_type', 'message');
        }
        fd.append('reason', _rState.reason);
        var desc = (document.getElementById('dzRptOtherInput').value || '').trim();
        if (desc) fd.append('description', desc);

        fetch('report_user.php', { method: 'POST', body: fd })
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

    /* ESC key */
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

<style>
/* ─── Trigger Button ─── */
.profile-kebab-btn {
    background: transparent;
    border: transparent;
    color: #fff;
    font-size: 24px;
    padding: 6px 10px;
    border-radius: 12px;
    cursor: pointer;
    backdrop-filter: blur(12px);
    position: relative;
    transition: 0.3s;
}
.profile-kebab-btn:hover {
    color: #ff1111;
}

@media (max-width: 768px) {
    .profile-kebab-btn {
        font-size: 18px;
        padding: 4px 7px;
        border-radius: 50%;
        width: 28px;
        height: 28px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        position: absolute;
        right: 46px;
        top: 20px;
    }
}

@media (max-width: 480px) {
    .profile-kebab-btn {
        font-size: 18px;
        padding: 4px 7px;
        width: 26px;
        height: 26px;
        right: 42px;
        top: 20px;
    }
}
</style>
<?php endif; ?>
</div>
<?php endif; ?>

        <!-- Change Profile Picture (only for self) -->
<style>
/* =========================
   DEZZME TAB SHEET
========================= */

#dzTabSheet{
    padding: 0 12px !important;
}

#dzTabSheet > div{
    width:100% !important;
    max-width:420px !important;
    margin-bottom:10px !important;
    border-radius:22px 22px 16px 16px !important;
}

/* Title */
#dzTabSheetTitle{
    font-size:13px !important;
    padding:6px 18px 14px !important;
}

/* Option rows */
#dzTabSheet .dz-option,
#dzTabSheet > div > div[onclick]{
    padding:14px 18px !important;
}

/* Icons container */
#dzTabSheet .icon-box,
#dzTabSheet > div > div[onclick] > div:first-child{
    width:40px !important;
    height:40px !important;
    border-radius:12px !important;
}

/* Main icons */
#dzTabSheet i{
    font-size:18px !important;
}

/* Main option title */
#dzTabSheet > div > div[onclick] > div:nth-child(2) > div:first-child{
    font-size:13px !important;
    font-weight:600 !important;
}

/* Description */
#dzTabSheet > div > div[onclick] > div:nth-child(2) > div:last-child{
    font-size:10px !important;
    color:rgba(255,255,255,.45) !important;
}

/* Right arrow */
#dzTabSheet .ri-arrow-right-s-line{
    font-size:18px !important;
}

/* Cancel button */
#dzTabSheet > div > div:last-child{
    font-size:12px !important;
    padding:12px 20px 16px !important;
}

@media (max-width: 768px) {

    #dzTabSheet{
        padding: 0 10px !important;
    }

    #dzTabSheet > div{
        max-width: 100% !important;
        margin-bottom: 8px !important;
    }

    /* Smaller title area */
    #dzTabSheetTitle{
        font-size: 12px !important;
        padding: 4px 16px 10px !important;
    }

    /* Smaller option rows */
    #dzTabSheet > div > div[onclick]{
        padding: 10px 16px !important;
        gap: 12px !important;
    }

    /* Smaller icon boxes */
    #dzTabSheet > div > div[onclick] > div:first-child{
        width: 36px !important;
        height: 36px !important;
        border-radius: 10px !important;
    }

    /* Smaller icons */
    #dzTabSheet i{
        font-size: 16px !important;
    }

    /* Smaller titles */
    #dzTabSheet > div > div[onclick] > div:nth-child(2) > div:first-child{
        font-size: 12px !important;
        margin-bottom: 1px !important;
    }

    /* Smaller descriptions */
    #dzTabSheet > div > div[onclick] > div:nth-child(2) > div:last-child{
        font-size: 9px !important;
        line-height: 1.2 !important;
    }

    /* Smaller cancel button */
    #dzTabSheet > div > div:last-child{
        padding: 10px 16px 14px !important;
        font-size: 11px !important;
    }

    /* Smaller handle */
    #dzTabSheet > div > div:first-child{
        padding: 8px 0 2px !important;
    }
}

.message-user {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    cursor: pointer;
    border-bottom: 1px solid rgba(255,255,255,0.06);
    transition: background 0.2s ease;
    border-radius: 10px;
    margin-bottom: 4px;
}

.message-user:hover {
    background: rgba(255,255,255,0.05);
}

.message-user-info {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-width: 0;
}

.message-user-name {
    font-size: 0.82rem;
    font-weight: 600;
    color: white;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.message-user-preview {
    font-size: 0.72rem;
    color: rgba(255,255,255,0.5);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-top: 2px;
}

.message-unread-badge {
    background: #f24455;
    color: white;

    min-width: 10px;
     height: 10px;

    border-radius: 999px;

    display: flex;
    align-items: center;
    justify-content: center;

    padding: 0 3px;
    flex-shrink: 0;
}

/* phones */
@media (max-width: 768px) {
    .message-unread-badge {
        min-width: 10px;
        height: 10px;
        padding: 0 2px;
    }
}

/* very small phones */
@media (max-width: 480px) {
    .message-unread-badge {
        min-width: 6.5px;
        height: 6.5px;
        padding: 0;
    }
}

  /* ─── Base Profile Actions ─── */
  .profile-actions {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 5px;
    width: 100%;
  }

  /* ─── Shared pill button base ─── */
  .profile-pill-btn {
    flex: 0 0 auto;
    padding: 8px 28px;
    border-radius: 999px;
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    cursor: pointer;
    transition: 0.3s;
    font-weight: 500;
    font-size: 0.9rem;
    white-space: nowrap;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    font-family: 'Poppins', sans-serif;
    text-decoration: none;
  }

  /* ─── Edit Picture Button — red text + red border ─── */
  .profile-pic-label {
    background: rgba(255, 255, 255, 0.1);
    border: 2px solid rgba(255, 45, 45, 0.5);
    color: #f93838;
  }

  .profile-pic-label:hover {
    background:transparent;
    border-color: rgba(255, 45, 45, 0.8);
    color: #ff5555;
    transform: translateY(-2px);
  }

  /* ─── Untracked Button — original glassy white style ─── */
  .profile-untracked-btn {
    background: rgba(255, 255, 255, 0.1);
    border: 1.5px solid rgba(255, 255, 255, 0.23);
    color: #fff;
  }

  .profile-untracked-btn:hover {
    background: transparent;
    border-color: rgba(255, 255, 255, 0.5);
    color: #fff;
    transform: translateY(-2px);
  }

  /* ─── Profile Message (kept for compatibility, unused now) ─── */
  #profile-msg {
    font-size: 0.85rem;
    color: #aeffd8;
    padding: 4px 10px;
    border-radius: 10px;
    background: rgba(0, 255, 170, 0.1);
    border: 1px solid rgba(0, 255, 170, 0.2);
    white-space: nowrap;
  }

  @media (max-width: 770px) {
    .profile-actions { gap: 8px; }
    .profile-pill-btn { padding: 5px 18px; font-size: 0.77rem; border-radius: 999px; }
  }
  @media (max-width: 490px) {
    .profile-pill-btn { padding: 4px 15px; font-size: 0.67rem; border-radius: 999px; }
  }
  @media (max-width: 380px) {
    .profile-pill-btn { padding: 4px 13px; font-size: 0.65rem; border-radius: 999px; }
  }

@media (max-width: 320px) {
    .profile-pill-btn { padding: 3px 11px; font-size: 0.63rem; border-radius: 999px; }
  }
  /* =========================
     EDIT PICTURE MODAL
  ========================== */
  #epPicModal {
    display: none;
    position: fixed; inset: 0;
    z-index: 999999;
    background: rgba(0,0,0,0.82);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    align-items: center;
    justify-content: center;
    padding: 20px;
  }
  #epPicModal.open { display: flex; }

  .ep-box {
    background: rgba(14,13,26,0.98);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 26px;
    width: 100%;
    max-width: 380px;
    padding: 28px 26px 26px;
    box-shadow: 0 24px 60px rgba(0,0,0,0.7);
    animation: epPopIn 0.25s cubic-bezier(.34,1.56,.64,1);
    font-family: 'Poppins', sans-serif;
    position: relative;
  }
  @keyframes epPopIn {
    from { opacity:0; transform: translateY(20px) scale(0.96); }
    to   { opacity:1; transform: translateY(0) scale(1); }
  }

  .ep-close {
    position: absolute;
    top: 16px; right: 16px;
    width: 30px; height: 30px;
    border-radius: 50%;
    background: rgba(255,255,255,0.06);
    border: none;
    color: rgba(255,255,255,0.5);
    font-size: 16px;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer;
    transition: background 0.2s, color 0.2s;
  }
  .ep-close:hover { background: rgba(255,45,45,0.18); color: #ff4040; }

  .ep-title {
    font-size: 16px;
    font-weight: 700;
    color: #fff;
    text-align: center;
    margin-bottom: 22px;
    letter-spacing: -.2px;
  }

  .ep-pic-wrap {
    width: 130px; height: 130px;
    margin: 0 auto 18px;
    position: relative;
  }
  .ep-pic-wrap img {
    width: 100%; height: 100%;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid rgba(255,45,45,0.45);
    display: block;
  }
  .ep-pic-overlay {
    position: absolute; inset: 0;
    border-radius: 50%;
    background: rgba(0,0,0,0.4);
    display: flex; align-items: center; justify-content: center;
    opacity: 0;
    transition: opacity 0.2s;
    pointer-events: none;
  }
  .ep-pic-wrap:hover .ep-pic-overlay { opacity: 1; }
  .ep-pic-overlay i { font-size: 26px; color: #fff; }

  #epFileInput { display: none; }

  .ep-change-btn {
    display: block;
    margin: 0 auto 6px;
    background: none;
    border: none;
    color: #FF2D2D;
    font-size: 13.5px;
    font-weight: 700;
    cursor: pointer;
    font-family: 'Poppins', sans-serif;
    transition: color 0.18s;
    text-align: center;
    width: 100%;
  }
  .ep-change-btn:hover { color: #ff5555; }

  .ep-preview-area {
    display: none;
    flex-direction: column;
    align-items: center;
    gap: 14px;
    margin-top: 18px;
    padding: 18px;
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.07);
    border-radius: 18px;
  }
  .ep-preview-area.visible { display: flex; }
  .ep-preview-area img {
    width: 92px; height: 92px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid rgba(255,45,45,0.5);
  }
  .ep-preview-label {
    font-size: 10px;
    color: rgba(255,255,255,0.35);
    text-transform: uppercase;
    letter-spacing: 1.2px;
    font-weight: 700;
  }
  .ep-preview-actions {
    display: flex;
    gap: 28px;
  }
  .ep-save-word, .ep-cancel-word {
    background: none;
    border: none;
    font-size: 13.5px;
    font-weight: 700;
    font-family: 'Poppins', sans-serif;
    cursor: pointer;
    transition: color 0.18s;
  }
  .ep-save-word { color: #FF2D2D; }
  .ep-save-word:hover { color: #ff5555; }
  .ep-cancel-word { color: rgba(255,255,255,0.4); }
  .ep-cancel-word:hover { color: rgba(255,255,255,0.7); }
  .ep-save-word:disabled { opacity: 0.5; pointer-events: none; }

  .ep-hint {
    text-align: center;
    font-size: 11px;
    color: rgba(255,255,255,0.28);
    margin-top: 16px;
    line-height: 1.5;
  }

  /* ─── Toast (reuses same look as pm-toast) ─── */
  .ep-toast {
    position: fixed;
    bottom: 30px; left: 50%;
    transform: translateX(-50%) translateY(10px);
    background: rgba(8,8,22,.97);
    border: 1px solid rgba(255,45,45,.25);
    color: #fff; padding: 8px 20px; border-radius: 50px;
    font-size: 12px; font-family: 'Poppins', sans-serif; font-weight: 500;
    z-index: 9999999; pointer-events: none;
    box-shadow: 0 8px 28px rgba(0,0,0,.65);
    white-space: nowrap; opacity: 0;
    transition: opacity .22s ease, transform .22s ease;
  }
  .ep-toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }

  /* ═══════════════════════════════════════════
     RESPONSIVE — 780 / 490 / 380 / 330
  ═══════════════════════════════════════════ */
  @media (max-width: 780px) {
    .ep-box { max-width: 340px; padding: 24px 22px 22px; border-radius: 22px; }
    .ep-title { font-size: 15px; margin-bottom: 18px; }
    .ep-pic-wrap { width: 112px; height: 112px; margin-bottom: 15px; }
    .ep-change-btn { font-size: 13px; }
    .ep-preview-area { padding: 15px; gap: 12px; margin-top: 15px; }
    .ep-preview-area img { width: 80px; height: 80px; }
  }

  @media (max-width: 490px) {
    .ep-box { max-width: 92vw; padding: 22px 18px 20px; border-radius: 20px; }
    .ep-close { width: 27px; height: 27px; font-size: 14px; top: 13px; right: 13px; }
    .ep-title { font-size: 14px; margin-bottom: 16px; }
    .ep-pic-wrap { width: 98px; height: 98px; margin-bottom: 13px; }
    .ep-pic-overlay i { font-size: 22px; }
    .ep-change-btn { font-size: 12.5px; }
    .ep-preview-area { padding: 13px; gap: 10px; margin-top: 13px; border-radius: 16px; }
    .ep-preview-area img { width: 72px; height: 72px; }
    .ep-preview-label { font-size: 9.5px; }
    .ep-save-word, .ep-cancel-word { font-size: 12.5px; }
    .ep-preview-actions { gap: 22px; }
    .ep-hint { font-size: 10.5px; margin-top: 14px; }
  }

  @media (max-width: 380px) {
    .ep-box { padding: 20px 16px 18px; border-radius: 18px; }
    .ep-title { font-size: 13.5px; margin-bottom: 14px; }
    .ep-pic-wrap { width: 88px; height: 88px; margin-bottom: 12px; }
    .ep-pic-overlay i { font-size: 20px; }
    .ep-change-btn { font-size: 12px; }
    .ep-preview-area { padding: 12px; gap: 9px; margin-top: 12px; }
    .ep-preview-area img { width: 64px; height: 64px; }
    .ep-preview-label { font-size: 9px; }
    .ep-save-word, .ep-cancel-word { font-size: 12px; }
    .ep-preview-actions { gap: 18px; }
    .ep-hint { font-size: 10px; margin-top: 12px; }
  }

  @media (max-width: 330px) {
    .ep-box { padding: 18px 14px 16px; border-radius: 16px; }
    .ep-close { width: 24px; height: 24px; font-size: 13px; top: 11px; right: 11px; }
    .ep-title { font-size: 13px; margin-bottom: 12px; }
    .ep-pic-wrap { width: 78px; height: 78px; margin-bottom: 10px; }
    .ep-pic-overlay i { font-size: 18px; }
    .ep-change-btn { font-size: 11.5px; }
    .ep-preview-area { padding: 10px; gap: 8px; margin-top: 10px; border-radius: 14px; }
    .ep-preview-area img { width: 56px; height: 56px; }
    .ep-preview-label { font-size: 8.5px; }
    .ep-save-word, .ep-cancel-word { font-size: 11.5px; }
    .ep-preview-actions { gap: 16px; }
    .ep-hint { font-size: 9.5px; margin-top: 10px; }
  }
</style>

<!-- Trigger row: Edit Picture button + Untracked button + post count -->
<div class="profile-actions">
    <?php if($current_user == $profile_id): ?>
    <button type="button" class="profile-pill-btn profile-pic-label" onclick="epOpenModal()">
        Edit Picture
    </button>

    <a href="untracked.php" class="profile-pill-btn profile-untracked-btn">
        Untracked
    </a>
    <?php endif; ?>

    <?php if($current_user == $profile_id): ?>
    <div class="post-count-btn" title="Total uploads">
        <i class="ri-folder-2-line"></i>
        <span><?php echo shortNum($post_count); ?></span>
    </div>
    <?php endif; ?>
</div>
</div>
</div>

<?php if($current_user == $profile_id): ?>
<!-- Edit Picture Modal -->
<div id="epPicModal" onclick="if(event.target===this)epClose()">
    <div class="ep-box">
        <button class="ep-close" onclick="epClose()" aria-label="Close"><i class="ri-close-line"></i></button>
        <div class="ep-title">Edit Profile Picture</div>

        <div class="ep-pic-wrap">
            <img src="<?= htmlspecialchars($profilePic) ?>" id="epCurrentPicImg" alt="Profile" onerror="this.src='uploads/mee.jpeg';">
            <div class="ep-pic-overlay"><i class="ri-camera-line"></i></div>
        </div>

        <button class="ep-change-btn" onclick="document.getElementById('epFileInput').click()">
            Change Picture
        </button>
        <input type="file" id="epFileInput" accept="image/*" onchange="epHandleSelect(this)">

        <div id="epPreviewArea" class="ep-preview-area">
            <span class="ep-preview-label">Preview</span>
            <img id="epPreviewImg" src="" alt="Preview">
            <div class="ep-preview-actions">
                <button class="ep-save-word" onclick="epSave(this)">Save Photo</button>
                <button class="ep-cancel-word" onclick="epCancel()">Cancel</button>
            </div>
        </div>

        <div class="ep-hint">JPG, PNG, GIF or WebP &middot; max 5MB</div>
    </div>
</div>

<div id="epToast" class="ep-toast"></div>

<script>
(function(){
    var _epFile = null;

    window.epOpenModal = function(){
        var m = document.getElementById('epPicModal');
        if(!m) return;
        m.classList.add('open');
        document.body.style.overflow = 'hidden';
    };

    window.epClose = function(){
        var m = document.getElementById('epPicModal');
        if(!m) return;
        m.classList.remove('open');
        document.body.style.overflow = '';
        epCancel();
    };

    window.epHandleSelect = function(input){
        var f = input.files[0];
        if(!f) return;
        if(!f.type.startsWith('image/')){ epToast('Please pick an image file'); return; }
        _epFile = f;
        var r = new FileReader();
        r.onload = function(e){
            document.getElementById('epPreviewImg').src = e.target.result;
            document.getElementById('epPreviewArea').classList.add('visible');
        };
        r.readAsDataURL(f);
    };

    window.epCancel = function(){
        _epFile = null;
        var pa = document.getElementById('epPreviewArea');
        if(pa) pa.classList.remove('visible');
        var pi = document.getElementById('epPreviewImg');
        if(pi) pi.src = '';
        var fi = document.getElementById('epFileInput');
        if(fi) fi.value = '';
    };

    window.epSave = function(btn){
        if(!_epFile) return;
        var orig = btn.textContent;
        btn.textContent = 'Saving…'; btn.disabled = true;
        var fd = new FormData();
        fd.append('action', 'update_profile_pic');
        fd.append('profile_pic_file', _epFile);
        fetch(window.location.pathname, { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(d){
            btn.disabled = false;
            if(d.success && d.url){
                var c = document.getElementById('epCurrentPicImg');
                var t = document.getElementById('profilePic');
                if(c) c.src = d.url;
                if(t) t.src = d.url;
                epCancel();
                btn.textContent = orig;
                epToast('Profile pic updated ✓');
                setTimeout(epClose, 700);
            } else {
                btn.textContent = orig;
                epToast(d.msg || 'Could not save — try again');
            }
        })
        .catch(function(){
            btn.disabled = false; btn.textContent = orig;
            epToast('Network error — check connection');
        });
    };

    window.epToast = function(msg){
        var t = document.getElementById('epToast');
        if(!t) return;
        t.textContent = msg; t.classList.add('show');
        clearTimeout(t._tid);
        t._tid = setTimeout(function(){ t.classList.remove('show'); }, 2600);
    };

    document.addEventListener('keydown', function(e){
        if(e.key === 'Escape'){
            var m = document.getElementById('epPicModal');
            if(m && m.classList.contains('open')) epClose();
        }
    });
})();
</script>
<?php endif; ?>



<?php if ($current_user == $profile_id): ?>
    <?php include 'profile_crossroads.php'; ?>
<?php endif; ?>

<?php if(!$is_blocked && !$is_blocked_by_owner): ?>
<?php include 'profile_mutuals.php'; ?>
<?php endif; ?>

<?php
$is_following = false;
if ($current_user && $current_user != $profile_id) {
    $check = $conn->query("SELECT 1 FROM followers WHERE follower_id = $current_user AND following_id = $profile_id");
    $is_following = $check->num_rows > 0;
}
?>
<!-- Sentinel: marks the position of the tabs bar in normal flow.
     When this scrolls out of view, the tabs bar has docked under
     the topbar (position: sticky), and we flip the topbar's
     search/menu buttons over to the Track button. -->
<div id="dzTabsSentinel" style="height:1px;"></div>


<!-- ══════════════════════════════════════════════════════════
     DEZZ CARD SKELETON LOADER  (v2 — visibility-driven)
     Matches .meme-card exactly: top-bar (avatar+username),
     media-box, caption, icon-bar. Pure HTML/CSS shimmer,
     no spinner.

     WHAT CHANGED FROM v1
     - v1 fired dzskInit() for ALL SIX grids on DOMContentLoaded,
       whether or not that tab was visible. Since browsers fetch
       every <img>/<video> as soon as it's parsed (display:none
       doesn't stop the request), by the time you clicked a tab
       the media was already loaded and the skeleton never showed.
     - v2 watches each tab section (#memes/#reposts/#tags/
       #favorites/#likes/#uploads) for its `style` attribute
       changing (that's exactly what toggleSection() flips) and
       only runs dzskInit() on a grid the FIRST TIME its section
       actually becomes visible.
     - Once a grid has run its skeleton, it's marked done and will
       never show the skeleton again — even if you flip tabs back
       and forth — until you refresh the page.
     - A short minimum display time (280ms) is enforced so the
       shimmer is actually visible for a beat on tab switches, even
       when the media was already fully loaded in the background.

     ONLY KEEP ONE COPY OF THIS BLOCK ON THE PAGE.
     ══════════════════════════════════════════════════════════ -->

<style>
/* ── skeleton overlay wraps 1:1 over the real grid ── */
.dzsk-wrap{
    position:relative;
}
.dzsk-overlay{
    position:absolute;
    inset:0;
    display:grid;
    grid-template-columns:repeat(auto-fill, minmax(200px, 1fr));
    gap:5.5px;
    z-index:5;
    opacity:1;
    transition:opacity .16s ease;
    pointer-events:none;
}
.dzsk-overlay.dzsk-hide{
    opacity:0;
    pointer-events:none;
}
.dzsk-wrap .grid{
    opacity:0;
    transition:opacity .18s ease;
}
.dzsk-wrap .grid.dzsk-real-show{
    opacity:1;
}

/* ── skeleton card — same shell as .meme-card ── */
.dzsk-card{
    background:rgba(255,255,255,0.05);
    border-radius:15px;
    overflow:hidden;
    position:relative;
    height:100%;
}

/* shared shimmer surface */
.dzsk-shimmer{
    position:relative;
    overflow:hidden;
    background:rgba(255,255,255,0.06);
}
.dzsk-shimmer::after{
    content:"";
    position:absolute;
    inset:0;
    background:linear-gradient(
        100deg,
        rgba(255,255,255,0) 30%,
        rgba(255,255,255,0.10) 45%,
        rgba(255,255,255,0.20) 50%,
        rgba(255,255,255,0.10) 55%,
        rgba(255,255,255,0) 70%
    );
    background-size:250% 100%;
    animation:dzskSweep 1.15s ease-in-out infinite;
}
@keyframes dzskSweep{
    0%{ background-position:150% 0; }
    100%{ background-position:-150% 0; }
}

/* ── media block (matches .meme-card .media-box img/video height:180px) ── */
.dzsk-media{
    width:100%;
    height:180px;
    border-bottom:1px solid rgba(255,255,255,0.06);
}

/* ── top-bar overlay: avatar circle + username pill ── */
.dzsk-top-bar{
    position:absolute;
    top:10px;
    left:10px;
    display:flex;
    align-items:center;
    gap:8px;
    z-index:2;
}
.dzsk-avatar{
    width:32px;
    height:32px;
    border-radius:50%;
    flex-shrink:0;
    background:rgba(255,255,255,0.14);
    overflow:hidden;
    position:relative;
    border:2px solid rgba(255,255,255,0.10);
}
.dzsk-username{
    width:64px;
    height:11px;
    border-radius:5px;
    background:rgba(255,255,255,0.14);
    overflow:hidden;
    position:relative;
}

/* ── icon-bar overlay: two small circular button placeholders, top-right ── */
.dzsk-icon-bar{
    position:absolute;
    top:10px;
    right:10px;
    display:flex;
    flex-direction:column;
    gap:6px;
    z-index:2;
}
.dzsk-icon-btn{
    width:14px;
    height:14px;
    border-radius:6px;
    background:rgba(255,255,255,0.14);
    overflow:hidden;
    position:relative;
}

/* ── caption: two placeholder lines ── */
.dzsk-caption{
    padding:10px;
    display:flex;
    flex-direction:column;
    gap:6px;
}
.dzsk-cap-line{
    height:9px;
    border-radius:5px;
    background:rgba(255,255,255,0.09);
    overflow:hidden;
    position:relative;
}
.dzsk-cap-line.dzsk-cap-full{ width:88%; }
.dzsk-cap-line.dzsk-cap-half{ width:52%; }

/* ══════════════════════════════════════════════════════════
   RESPONSIVE — mirrors .meme-card img/video height breakpoints
   exactly: 768px→150px, 480px→130px, 390px→115px, 330px→100px
   ══════════════════════════════════════════════════════════ */
@media (max-width:788px){
    .dzsk-overlay{ grid-template-columns:repeat(auto-fill, minmax(160px, 1fr)); gap:12px; }
    .dzsk-media{ height:150px; }
    .dzsk-avatar{ width:28px; height:28px; }
    .dzsk-username{ width:56px; height:10px; }
    .dzsk-icon-btn{ width:13px; height:13px; border-radius:5px; }
    .dzsk-caption{ padding:8px; gap:5px; }
    .dzsk-cap-line{ height:8px; }
}
@media (max-width:480px){
    .dzsk-overlay{ grid-template-columns:repeat(auto-fill, minmax(140px, 1fr)); gap:10px; }
    .dzsk-media{ height:130px; }
    .dzsk-avatar{ width:24px; height:24px; }
    .dzsk-username{ width:48px; height:9px; }
    .dzsk-top-bar{ top:8px; left:8px; gap:6px; }
    .dzsk-icon-bar{ top:8px; right:8px; gap:5px; }
    .dzsk-icon-btn{ width:12px; height:12px; }
    .dzsk-caption{ padding:7px; gap:5px; }
    .dzsk-cap-line{ height:7.5px; }
}
@media (max-width:390px){
    .dzsk-media{ height:115px; }
    .dzsk-avatar{ width:22px; height:22px; }
    .dzsk-username{ width:42px; height:8px; }
    .dzsk-caption{ padding:6px; gap:4px; }
    .dzsk-cap-line{ height:7px; }
}
@media (max-width:330px){
    .dzsk-overlay{ grid-template-columns:repeat(2, 1fr); gap:6px; }
    .dzsk-media{ height:100px; }
    .dzsk-avatar{ width:20px; height:20px; }
    .dzsk-username{ width:38px; height:7.5px; }
    .dzsk-icon-btn{ width:11px; height:11px; }
    .dzsk-caption{ padding:5px; gap:4px; }
    .dzsk-cap-line{ height:6.5px; }
}
</style>

<script>
(function(){

    /* which tab (dropdown-section) drives which grid — extend this
       if you ever add another tab/grid pair */
    var dzskSectionGridMap = {
        memes:      'memesGrid',
        reposts:    'repostsGrid',
        tags:       'tagsGrid',
        favorites:  'favsGrid',
        likes:      'likesGrid',
        uploads:    'uploadsGrid'
    };

    /* grids that have already run their skeleton once this page
       load — never re-run until a real refresh resets this object */
    var dzskDone = {};

    var MIN_DISPLAY_MS = 280; // keep the shimmer visible for at least this long
    var HARD_CAP_MS     = 900; // never let it feel stuck on a slow connection

    /* Flips to true once dzskWatchSections() has finished its first,
       synchronous pass over every section on page load. Any dzskInit()
       call that happens *before* this flips is the automatic default-tab
       check on load — not a real user-triggered tab switch — so it
       shouldn't pay the MIN_DISPLAY_MS "make the shimmer feel real" tax. */
    var dzskInitialLoadDone = false;

    /* ══════════════════════════════════════════════════════════
       dzskInit(gridId, isInitialLoad)
       Wraps #gridId in a .dzsk-wrap (if not already), injects a
       skeleton overlay of N fake cards (N = however many real
       cards are already in the grid, capped 4–12), then waits for
       every real img/video in the grid to finish loading (or the
       hard cap) AND — unless this is the automatic first-load check
       for the default tab — for at least MIN_DISPLAY_MS to pass,
       before cross-fading skeleton out and the real grid in.
       Runs at most once per gridId per page load.
       ══════════════════════════════════════════════════════════ */
    function dzskInit(gridId, isInitialLoad){
        if(dzskDone[gridId]) return;

        var grid = document.getElementById(gridId);
        if(!grid) return;

        // Don't wrap twice
        if(grid.parentNode.classList && grid.parentNode.classList.contains('dzsk-wrap')) return;

        dzskDone[gridId] = true; // mark immediately so re-entrant calls bail out

        var wrap = document.createElement('div');
        wrap.className = 'dzsk-wrap';
        grid.parentNode.insertBefore(wrap, grid);
        wrap.appendChild(grid);

        var realCards = grid.querySelectorAll('.meme-card').length;
        var count = Math.min(12, Math.max(4, realCards || 6));

        var overlay = document.createElement('div');
        overlay.className = 'dzsk-overlay';
        overlay.id = gridId + 'Skeleton';

        var cardHTML =
            '<div class="dzsk-card">' +
                '<div class="dzsk-top-bar">' +
                    '<div class="dzsk-avatar dzsk-shimmer"></div>' +
                    '<div class="dzsk-username dzsk-shimmer"></div>' +
                '</div>' +
                '<div class="dzsk-media dzsk-shimmer"></div>' +
                '<div class="dzsk-icon-bar">' +
                    '<div class="dzsk-icon-btn dzsk-shimmer"></div>' +
                    '<div class="dzsk-icon-btn dzsk-shimmer"></div>' +
                '</div>' +
                '<div class="dzsk-caption">' +
                    '<div class="dzsk-cap-line dzsk-cap-full dzsk-shimmer"></div>' +
                    '<div class="dzsk-cap-line dzsk-cap-half dzsk-shimmer"></div>' +
                '</div>' +
            '</div>';

        var html = '';
        for(var i = 0; i < count; i++) html += cardHTML;
        overlay.innerHTML = html;
        wrap.insertBefore(overlay, grid);

        var media   = grid.querySelectorAll('img, video');
        var started = Date.now();
        var done    = false;

        function finish(){
            if(done) return;
            done = true;
            var elapsed  = Date.now() - started;
            // Skip the artificial floor entirely for the default tab's
            // automatic check on first load — there's nothing to "cover
            // up" there, media is either cached or genuinely loading.
            var waitMore = isInitialLoad ? 0 : Math.max(0, MIN_DISPLAY_MS - elapsed);
            setTimeout(function(){
                overlay.classList.add('dzsk-hide');
                grid.classList.add('dzsk-real-show');
                setTimeout(function(){
                    if(overlay.parentNode) overlay.parentNode.removeChild(overlay);
                }, 200);
            }, waitMore);
        }

        if(media.length === 0){ finish(); return; }

        var total = media.length, loaded = 0;
        function tick(){ loaded++; if(loaded >= total) finish(); }

        media.forEach(function(m){
            if(m.tagName === 'IMG'){
                if(m.complete) tick();
                else { m.addEventListener('load', tick); m.addEventListener('error', tick); }
            } else {
                if(m.readyState >= 2) tick();
                else { m.addEventListener('loadeddata', tick); m.addEventListener('error', tick); }
            }
        });

        // Hard cap — never let it feel stuck
        setTimeout(finish, HARD_CAP_MS);
    }

    /* Cheap visibility check. toggleSection() drives visibility via
       inline style.display, so read that directly when it's set —
       avoids forcing a style/layout flush via getComputedStyle().
       Only the section(s) with no inline style yet (i.e. the default
       tab, styled purely by CSS) fall back to getComputedStyle. */
    function dzskIsVisible(section){
        if(section.style.display) return section.style.display !== 'none';
        return window.getComputedStyle(section).display !== 'none';
    }

    /* ══════════════════════════════════════════════════════════
       Watch every tab section for visibility. toggleSection()
       flips each section's inline `style.display` — that's the
       only signal we need. The first time a section's display
       isn't "none", fire its grid's skeleton (once).
       ══════════════════════════════════════════════════════════ */
    function dzskWatchSections(){
        Object.keys(dzskSectionGridMap).forEach(function(sectionId){
            var section = document.getElementById(sectionId);
            if(!section) return;
            var gridId = dzskSectionGridMap[sectionId];

            function checkVisible(){
                if(dzskIsVisible(section)){
                    dzskInit(gridId, !dzskInitialLoadDone);
                }
            }

            // Covers the tab that's visible by default on page load
            checkVisible();

            var mo = new MutationObserver(checkVisible);
            mo.observe(section, { attributes:true, attributeFilter:['style'] });
        });

        // All sections have had their synchronous initial check by now —
        // anything from here on is a real, user-triggered tab switch.
        dzskInitialLoadDone = true;
    }

    if(document.readyState === 'loading'){
        document.addEventListener('DOMContentLoaded', dzskWatchSections);
    } else {
        dzskWatchSections();
    }

})();
</script>

<div class="tabs">
  <?php if (!$is_blocked): ?>
    <?php
    $blocked_check = $conn->query("
        SELECT 1 FROM blocks 
        WHERE (blocker_id = $current_user AND blocked_id = $profile_id)
           OR (blocker_id = $profile_id AND blocked_id = $current_user)
    ")->num_rows > 0;

    if ($blocked_check) {
        $meme_action   = "alert('You are blocked, you cannot view this.'); return false;";
        $repost_action = "alert('You are blocked, you cannot view this.'); return false;";
        $tags_action   = "alert('You are blocked, you cannot view this.'); return false;";
    } elseif (!$can_view) {
        $meme_action   = "alert('Track this Dezzmer to view their memes'); return false;";
        $repost_action = "alert('Track this Dezzmer to view their reposts'); return false;";
        $tags_action   = "alert('Track this Dezzmer to view their tags'); return false;";
    } else {
        $meme_action   = "toggleSection('memes')";
        $repost_action = "toggleSection('reposts')";
        $tags_action   = "toggleSection('tags')";
    }

    $can_view_about = $is_following;
    if ($can_view_about) {
        $about_me_btn = '<button onclick="dzOpenAboutMe(' . $profile_id . ')"><i class="ri-user-heart-line"></i> More About Me</button>';
    } else {
        $about_me_btn = '<button onclick="alert(\'Track this Dezzmer to view their About Me\'); return false;"><i class="ri-user-heart-line"></i> More About Me</button>';
    }
    ?>

    <?php if ($current_user == $profile_id): ?>
        <!-- ── OWNER: browse directly on this page, no sheet ── -->
        <button id="tabMemes" onclick="toggleSection('memes')">
            <i class="bi bi-grid-3x3-gap-fill"></i> Memes
        </button>
        <button id="tabReposts" onclick="toggleSection('reposts')">
            <i class="ri-repeat-line"></i> Reposts
        </button>
    <?php else: ?>
        <!-- ── OTHER USERS: browse directly on this page, no sheet ── -->
        <button id="tabMemes" onclick="<?php echo $meme_action; ?>">
            <i class="bi bi-grid-3x3-gap-fill"></i> Memes
        </button>
        <button onclick="<?php echo $repost_action; ?>">
            <i class="ri-repeat-line"></i> Reposts
        </button>
        <button onclick="<?php echo $tags_action; ?>">
            <i class="ri-account-pin-box-line" style="font-size:1.25rem;vertical-align:middle;"></i> Tags
        </button>
        <?php echo $about_me_btn; ?>
        <div class="post-count-btn" title="Total uploads">
            <i class="ri-folder-2-line"></i>
            <span><?php echo shortNum($post_count); ?></span>
        </div>
    <?php endif; ?>

  <?php endif; ?>

  <?php if ($current_user == $profile_id): ?>
        <button onclick="toggleSection('favorites')">
            <i class="ri-bookmark-line"></i> Favorites
        </button>
        <button onclick="toggleSection('messages')" style="position:relative;">
            <i class="ri-chat-1-line"></i> Messages
            <?php if (!empty($total_unread) && $total_unread > 0): ?>
            <span class="msg-badge-desktop" style="position:absolute;top:10px;right:8px;min-width:13px;height:13px;background:#FF2D2D;border-radius:50px;font-size:9px;font-weight:700;color:#fff;display:flex;align-items:center;justify-content:center;padding:0 3px;line-height:1;pointer-events:none;">
                <?php echo $total_unread > 99 ? '99+' : $total_unread; ?>
            </span>
            <span class="msg-badge-mobile" style="position:absolute;top:6px;right:29px;width:7px;height:7px;background:#FF2D2D;border-radius:50%;border:1.5px solid #06071A;pointer-events:none;display:none;"></span>
            <?php endif; ?>
        </button>
        <style>
        @media (max-width: 768px) {
            .msg-badge-desktop { display: none !important; }
            .msg-badge-mobile  { display: block !important; }
        }
        </style>
        <button onclick="toggleSection('tags')">
            <i class="ri-account-pin-box-line" style="font-size:1.25rem;vertical-align:middle;"></i> Tags
        </button>
  <?php endif; ?>
</div>

<!-- ===================== ABOUT ME VIEW MODAL ===================== -->
<div id="aboutMeModal" class="dz-fullscreen-modal">
    <div class="dz-fs-header">
        <button class="dz-fs-close" onclick="dzCloseAboutMe()"><i class="ri-arrow-left-s-line"></i></button>
        <h2>About Me</h2>
        <button id="aboutMeEditBtn" class="dz-fs-edit" onclick="dzOpenEditAboutMe()" style="display:none;">
            <i class="ri-edit-line"></i> Edit
        </button>
    </div>
    <div class="dz-fs-body" id="aboutMeBody">
        <!-- Skeleton loader (static fallback — dzSkeletonHTML() renders the live version) -->
        <div class="dz-am-skeleton" id="aboutMeSkeleton">
            <div class="dz-sk-card">
                <div class="dz-sk-title"></div>
                <div class="dz-sk-row"><div class="dz-sk-icon"></div><div class="dz-sk-lines"><div class="dz-sk-line short"></div><div class="dz-sk-line"></div></div></div>
                <div class="dz-sk-row"><div class="dz-sk-icon"></div><div class="dz-sk-lines"><div class="dz-sk-line short"></div><div class="dz-sk-line med"></div></div></div>
                <div class="dz-sk-row"><div class="dz-sk-icon"></div><div class="dz-sk-lines"><div class="dz-sk-line short"></div><div class="dz-sk-line"></div></div></div>
                <div class="dz-sk-row"><div class="dz-sk-icon"></div><div class="dz-sk-lines"><div class="dz-sk-line short"></div><div class="dz-sk-line med"></div></div></div>
            </div>
            <div class="dz-sk-card">
                <div class="dz-sk-title"></div>
                <div class="dz-sk-row"><div class="dz-sk-icon"></div><div class="dz-sk-lines"><div class="dz-sk-line short"></div><div class="dz-sk-line"></div></div></div>
                <div class="dz-sk-row"><div class="dz-sk-icon"></div><div class="dz-sk-lines"><div class="dz-sk-line short"></div><div class="dz-sk-line med"></div></div></div>
            </div>
            <div class="dz-sk-card">
                <div class="dz-sk-title"></div>
                <div class="dz-sk-row"><div class="dz-sk-icon"></div><div class="dz-sk-lines"><div class="dz-sk-line short"></div><div class="dz-sk-line"></div></div></div>
                <div class="dz-sk-row"><div class="dz-sk-icon"></div><div class="dz-sk-lines"><div class="dz-sk-line short"></div><div class="dz-sk-line med"></div></div></div>
            </div>
            <div class="dz-sk-card">
                <div class="dz-sk-title"></div>
                <div class="dz-sk-row"><div class="dz-sk-icon"></div><div class="dz-sk-lines"><div class="dz-sk-line short"></div><div class="dz-sk-line med"></div></div></div>
                <div class="dz-sk-chip-row">
                    <div class="dz-sk-chip"></div><div class="dz-sk-chip"></div><div class="dz-sk-chip"></div>
                </div>
            </div>
            <div class="dz-sk-card">
                <div class="dz-sk-title"></div>
                <div class="dz-sk-line full" style="height:60px;border-radius:8px;"></div>
            </div>
            <div class="dz-sk-card">
                <div class="dz-sk-title"></div>
                <div class="dz-sk-quote"></div>
                <div class="dz-sk-quote" style="width:80%;"></div>
            </div>
            <div class="dz-sk-card">
                <div class="dz-sk-title"></div>
                <div class="dz-sk-fam-grid">
                    <div class="dz-sk-fam-card"><div class="dz-sk-avatar"></div><div class="dz-sk-fam-name"></div><div class="dz-sk-fam-rel"></div></div>
                    <div class="dz-sk-fam-card"><div class="dz-sk-avatar"></div><div class="dz-sk-fam-name"></div><div class="dz-sk-fam-rel"></div></div>
                    <div class="dz-sk-fam-card"><div class="dz-sk-avatar"></div><div class="dz-sk-fam-name"></div><div class="dz-sk-fam-rel"></div></div>
                </div>
            </div>
            <div class="dz-sk-card">
                <div class="dz-sk-title"></div>
                <div class="dz-sk-event-row"><div class="dz-sk-icon"></div><div class="dz-sk-lines"><div class="dz-sk-line short"></div><div class="dz-sk-line med"></div></div></div>
                <div class="dz-sk-event-row"><div class="dz-sk-icon"></div><div class="dz-sk-lines"><div class="dz-sk-line short"></div><div class="dz-sk-line"></div></div></div>
            </div>
        </div>
    </div>
</div>

<!-- ===================== EDIT ABOUT ME MODAL ===================== -->
<div id="editAboutMeModal" class="dz-fullscreen-modal">
    <div class="dz-fs-header">
        <button class="dz-fs-close" onclick="dzCloseEditAboutMe()"><i class="ri-close-line"></i></button>
        <h2>Edit About Me</h2>
        <button class="dz-fs-save" onclick="dzSaveAboutMe()">Save</button>
    </div>

    <div class="dz-am-edit-tabs">
        <button class="dz-am-etab active" data-tab="core"    onclick="dzSwitchEditTab('core')">Profile</button>
        <button class="dz-am-etab"         data-tab="contact" onclick="dzSwitchEditTab('contact')">Contact</button>
        <button class="dz-am-etab"         data-tab="views"   onclick="dzSwitchEditTab('views')">Views</button>
        <button class="dz-am-etab"         data-tab="quotes"  onclick="dzSwitchEditTab('quotes')">Quotes</button>
        <button class="dz-am-etab"         data-tab="family"  onclick="dzSwitchEditTab('family')">Family</button>
        <button class="dz-am-etab"         data-tab="events"  onclick="dzSwitchEditTab('events')">Events</button>
    </div>

    <form id="editAboutMeForm" class="dz-fs-body">

        <!-- ── TAB: Core Profile ─────────────────────────────── -->
        <div class="dz-am-etab-panel active" id="etab-core">
            <div class="dz-am-edit-group">
                <label>Full Name</label>
                <input type="text" name="full_name" maxlength="100" placeholder="Your full name">
            </div>
            <div class="dz-am-edit-group">
                <label>Nickname</label>
                <input type="text" name="nickname" maxlength="50" placeholder="What people call you">
            </div>
            <div class="dz-am-edit-row">
                <div class="dz-am-edit-group">
                    <label>Occupation</label>
                    <input type="text" name="occupation" maxlength="100" placeholder="What do you do?">
                </div>
                <div class="dz-am-edit-group">
                    <label>Education</label>
                    <input type="text" name="education" maxlength="150" placeholder="School / field of study">
                </div>
            </div>
            <div class="dz-am-edit-row">
                <div class="dz-am-edit-group">
                    <label>Current Location</label>
                    <input type="text" name="current_location" maxlength="100" placeholder="Where you're based">
                </div>
                <div class="dz-am-edit-group">
                    <label>Hometown</label>
                    <input type="text" name="hometown" maxlength="100" placeholder="Where you're from">
                </div>
            </div>
            <div class="dz-am-edit-row">
                <div class="dz-am-edit-group">
                    <label>Relationship Status</label>
                    <select name="relationship_status">
                        <option value="">Prefer not to say</option>
                        <option value="Single">Single</option>
                        <option value="In a relationship">In a relationship</option>
                        <option value="Engaged">Engaged</option>
                        <option value="Married">Married</option>
                        <option value="It's complicated">It's complicated</option>
                    </select>
                </div>
                <div class="dz-am-edit-group">
                    <label>Date of Birth</label>
                    <input type="date" name="date_of_birth">
                </div>
            </div>
            <div class="dz-am-edit-group">
                <label>Website</label>
                <input type="text" name="website" maxlength="255" placeholder="yourwebsite.com">
            </div>
            <div class="dz-am-edit-group">
                <label>Interests</label>
                <input type="text" name="interests" maxlength="255" placeholder="music, art, gaming, travel">
            </div>
            <div class="dz-am-edit-group">
                <label>Description</label>
                <textarea name="description" maxlength="1000" rows="5" placeholder="Tell your followers more about yourself..."></textarea>
            </div>
        </div>

        <!-- ── TAB: Contact ──────────────────────────────────── -->
        <div class="dz-am-etab-panel" id="etab-contact">
            <div class="dz-am-section-note">
                <i class="ri-shield-check-line"></i>
                Only visible to your followers. Share only what you're comfortable with.
            </div>
            <div class="dz-am-edit-group">
                <label>Email Address</label>
                <input type="email" name="contact_email" maxlength="150" placeholder="your@email.com">
            </div>
            <div class="dz-am-edit-group">
                <label>Phone Number</label>
                <input type="tel" name="contact_phone" maxlength="30" placeholder="+234 800 000 0000">
            </div>
        </div>

        <!-- ── TAB: Views ────────────────────────────────────── -->
        <div class="dz-am-etab-panel" id="etab-views">
            <div class="dz-am-section-note">
                <i class="ri-information-line"></i>
                These are entirely optional. Share only if you want to.
            </div>
            <div class="dz-am-edit-group">
                <label>Political Views</label>
                <input type="text" name="political_views" maxlength="100" placeholder="e.g. Progressive, Conservative, Moderate…">
            </div>
            <div class="dz-am-edit-group">
                <label>Religious Views</label>
                <input type="text" name="religious_views" maxlength="100" placeholder="e.g. Christian, Muslim, Agnostic…">
            </div>
        </div>

        <!-- ── TAB: Quotes ───────────────────────────────────── -->
        <div class="dz-am-etab-panel" id="etab-quotes">
            <div class="dz-am-edit-group">
                <label>Favorite Quotes</label>
                <textarea name="favorite_quotes" maxlength="2000" rows="8"
                    placeholder='"Be the change you wish to see." — Mahatma Gandhi&#10;&#10;Add one quote per line or just your personal motto...'></textarea>
                <div class="dz-am-char-hint">Max 2,000 characters</div>
            </div>
        </div>

        <!-- ── TAB: Family ───────────────────────────────────── -->
        <div class="dz-am-etab-panel" id="etab-family">
            <div class="dz-am-section-note">
                <i class="ri-user-heart-line"></i>
                Family members you add are visible to your followers. Only add people who have given consent.
            </div>
            <div class="dz-am-sub-card" id="familyAddCard">
                <div class="dz-am-sub-card-title">Add Family Member</div>
                <div class="dz-am-edit-row">
                    <div class="dz-am-edit-group">
                        <label>Name</label>
                        <input type="text" id="famName" maxlength="100" placeholder="Their name">
                    </div>
                    <div class="dz-am-edit-group">
                        <label>Relationship</label>
                        <select id="famRelationship">
                            <option value="">Select…</option>
                            <option>Mother</option><option>Father</option><option>Sister</option>
                            <option>Brother</option><option>Spouse / Partner</option><option>Daughter</option>
                            <option>Son</option><option>Grandmother</option><option>Grandfather</option>
                            <option>Aunt</option><option>Uncle</option><option>Cousin</option>
                            <option>Best Friend</option><option>Other</option>
                        </select>
                    </div>
                </div>
                <div class="dz-am-edit-group">
                    <label>Their Photo <span class="dz-am-optional">(optional)</span></label>
                    <div class="dz-am-pic-upload" id="famPicUpload" onclick="document.getElementById('famPicInput').click()">
                        <img id="famPicPreview" src="" alt="" style="display:none;">
                        <div id="famPicPlaceholder"><i class="ri-image-add-line"></i><span>Tap to add photo</span></div>
                    </div>
                    <input type="file" id="famPicInput" accept="image/*" style="display:none;" onchange="dzPreviewFamilyPic(this)">
                </div>
                <label class="dz-am-consent-row">
                    <input type="checkbox" id="famConsent">
                    <span>I confirm this person has given consent to be listed on my profile.</span>
                </label>
                <button type="button" class="dz-am-add-btn" onclick="dzAddFamilyMember()">
                    <i class="ri-user-add-line"></i> Add Member
                </button>
            </div>
            <div id="familyList" class="dz-am-item-list"></div>
        </div>

        <!-- ── TAB: Events ───────────────────────────────────── -->
        <div class="dz-am-etab-panel" id="etab-events">
            <div class="dz-am-section-note">
                <i class="ri-calendar-event-line"></i>
                Highlight key moments in your life. These appear in your About Me in reverse chronological order.
            </div>
            <div class="dz-am-sub-card">
                <div class="dz-am-sub-card-title">Add Life Event</div>
                <div class="dz-am-edit-row">
                    <div class="dz-am-edit-group">
                        <label>Event Type</label>
                        <select id="evType">
                            <option value="">Select…</option>
                            <option>Graduation</option><option>Marriage</option><option>New Job</option>
                            <option>Promotion</option><option>Moved Cities</option><option>New Home</option>
                            <option>New Baby</option><option>Lost a Loved One</option><option>Retirement</option>
                            <option>Business Launch</option><option>Award / Achievement</option>
                            <option>Milestone Birthday</option><option>Travel</option><option>Other</option>
                        </select>
                    </div>
                    <div class="dz-am-edit-group">
                        <label>Date <span class="dz-am-optional">(optional)</span></label>
                        <input type="date" id="evDate">
                    </div>
                </div>
                <div class="dz-am-edit-group">
                    <label>Title</label>
                    <input type="text" id="evTitle" maxlength="150" placeholder="e.g. Graduated from UNILAG">
                </div>
                <div class="dz-am-edit-group">
                    <label>Details <span class="dz-am-optional">(optional)</span></label>
                    <textarea id="evDesc" maxlength="500" rows="3" placeholder="A little more about this moment…"></textarea>
                </div>
                <button type="button" class="dz-am-add-btn" onclick="dzAddLifeEvent()">
                    <i class="ri-add-circle-line"></i> Add Event
                </button>
            </div>
            <div id="eventList" class="dz-am-item-list"></div>
        </div>

    </form>
</div>

<!-- ===================== ABOUT ME CSS ===================== -->
<style>
/* ── Base modal & shared ──────────────────────────────────────── */
.dz-fullscreen-modal{
    display:none;
    position:fixed;
    inset:0;
    z-index: 999999999999;
    flex-direction:column;
    color:#fff;
    font-family:'Poppins', sans-serif;
    background: linear-gradient(-45deg,#010218,#070924,#070924);
    background-attachment: fixed;
}
.dz-fullscreen-modal.active{display:flex;}

.dz-fs-header{
    position:sticky;
    top:0;
    z-index:10;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:16px 60px;
    border-bottom:1px solid rgba(255,255,255,.08);
    background: linear-gradient(-45deg,#010218,#070924,#070924);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
}
.dz-fs-header h2{
    font-size:17px;
    font-weight:600;
    margin:0;
    text-align:center;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
    max-width:60vw;
}
.dz-fs-close{
    position:absolute;
    left:18px;
    top:50%;
    transform:translateY(-50%);
}
.dz-fs-edit,.dz-fs-save{
    position:absolute;
    right:18px;
    top:50%;
    transform:translateY(-50%);
}
.dz-fs-close,.dz-fs-edit,.dz-fs-save{background:transparent;border:none;color:#fff;font-size:30px;cursor:pointer;display:flex;align-items:center;gap:6px;}
.dz-fs-edit{font-size:14px;font-weight:600;color:#E0351A;}
.dz-fs-save{font-size:15px;font-weight:700;color: #d23119;}
.dz-fs-body{flex:1;overflow-y:auto;padding:18px;max-width:640px;margin:0 auto;width:100%;-webkit-overflow-scrolling:touch;box-sizing:border-box;}

/* ── Premium shimmer skeleton loader ─────────────────────────── */
.dz-am-skeleton{display:flex;flex-direction:column;gap:14px;}
.dz-sk-card{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.06);border-radius:16px;padding:16px;display:flex;flex-direction:column;gap:12px;}

/* stagger each card's shimmer very slightly for a premium cascading feel */
.dz-sk-card:nth-child(1){--d:0s;}
.dz-sk-card:nth-child(2){--d:.07s;}
.dz-sk-card:nth-child(3){--d:.14s;}
.dz-sk-card:nth-child(4){--d:.21s;}
.dz-sk-card:nth-child(5){--d:.28s;}
.dz-sk-card:nth-child(6){--d:.35s;}
.dz-sk-card:nth-child(7){--d:.42s;}
.dz-sk-card:nth-child(8){--d:.49s;}
.dz-sk-card:nth-child(9){--d:.56s;}
.dz-sk-card:nth-child(10){--d:.63s;}
.dz-sk-card *{animation-delay:var(--d);}

.dz-sk-title,.dz-sk-icon,.dz-sk-line,.dz-sk-avatar,.dz-sk-chip,.dz-sk-quote,.dz-sk-fam-name,.dz-sk-fam-rel{
    background: linear-gradient(
        90deg,
        rgba(255,255,255,0.05) 25%,
        rgba(255,255,255,0.13) 37%,
        rgba(255,255,255,0.05) 63%
    );
    background-size:400% 100%;
    animation: dzShimmer 1.4s ease-in-out infinite;
}
@keyframes dzShimmer{
    0%   { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
}

.dz-sk-title{height:12px;width:90px;border-radius:6px;}
.dz-sk-row{display:flex;align-items:center;gap:12px;}
.dz-sk-icon{width:22px;height:22px;border-radius:50%;flex-shrink:0;}
.dz-sk-lines{flex:1;display:flex;flex-direction:column;gap:6px;}
.dz-sk-line{height:11px;border-radius:6px;}
.dz-sk-line.short{width:60px;height:9px;}
.dz-sk-line.med{width:55%;}
.dz-sk-line.full{width:100%;}

.dz-sk-chip-row{display:flex;flex-wrap:wrap;gap:8px;margin-top:4px;}
.dz-sk-chip{height:26px;width:72px;border-radius:999px;}

.dz-sk-quote{height:38px;border-radius:0 8px 8px 0;border-left:3px solid rgba(255,255,255,.08);width:100%;}

.dz-sk-fam-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(100px,1fr));gap:10px;}
.dz-sk-fam-card{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.06);border-radius:12px;padding:12px 8px;display:flex;flex-direction:column;align-items:center;gap:8px;}
.dz-sk-avatar{width:52px;height:52px;border-radius:50%;}
.dz-sk-fam-name{width:70%;height:10px;border-radius:5px;}
.dz-sk-fam-rel{width:50%;height:8px;border-radius:4px;}

.dz-sk-event-row{display:flex;align-items:center;gap:12px;padding:2px 0;}

/* ── View modal cards ──────────────────────────────────────────── */
.dz-am-card{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.06);border-radius:16px;padding:16px;margin-bottom:14px;}
.dz-am-card h3{font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#9B9BB5;margin:0 0 12px 0;}
.dz-am-row{display:flex;align-items:flex-start;gap:12px;padding:8px 0;}
.dz-am-row:not(:last-child){border-bottom:1px solid rgba(255,255,255,.04);}
.dz-am-row i{font-size:18px;color:#E0351A;margin-top:2px;flex-shrink:0;width:22px;text-align:center;}
.dz-am-row-content{flex:1;min-width:0;}
.dz-am-label{font-size:12px;color:#8A8AA0;margin-bottom:2px;}
.dz-am-value{font-size:15px;color:#fff;word-break:break-word;}
.dz-am-value.empty{color:#5C5C70;font-style:italic;font-weight:400;}
.dz-am-value a{color:#E0351A;text-decoration:none;word-break:break-all;}
.dz-am-description{font-size:14.5px;line-height:1.6;color:#DADAE8;white-space:pre-wrap;}
.dz-am-interests{display:flex;flex-wrap:wrap;gap:8px;margin-top:4px;}
.dz-am-tag{background:rgba(224,53,26,.15);color:#F2907D;font-size:13px;padding:5px 12px;border-radius:999px;}

/* Quotes */
.dz-am-quotes-list{display:flex;flex-direction:column;gap:10px;}
.dz-am-quote-item{border-left:3px solid #E0351A;padding:8px 12px;background:rgba(224,53,26,.06);border-radius:0 8px 8px 0;font-size:14px;color:#DADAE8;line-height:1.5;white-space:pre-wrap;word-break:break-word;}

/* Family */
.dz-am-family-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px;margin-top:4px;}
.dz-am-family-card{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.06);border-radius:12px;padding:12px 8px;text-align:center;}
.dz-am-family-card img{width:52px;height:52px;border-radius:50%;object-fit:cover;margin-bottom:7px;border:2px solid rgba(224,53,26,.4);}
.dz-am-family-card .dz-fam-avatar{width:52px;height:52px;border-radius:50%;background:rgba(224,53,26,.15);display:flex;align-items:center;justify-content:center;margin:0 auto 7px;border:2px solid rgba(224,53,26,.3);}
.dz-am-family-card .dz-fam-avatar i{font-size:22px;color:#E0351A;}
.dz-am-family-name{font-size:13px;font-weight:600;color:#fff;margin-bottom:2px;word-break:break-word;}
.dz-am-family-rel{font-size:11.5px;color:#8A8AA0;}

/* Life events */
.dz-am-events-list{display:flex;flex-direction:column;gap:0;}
.dz-am-event-item{display:flex;gap:12px;padding:10px 0;}
.dz-am-event-item:not(:last-child){border-bottom:1px solid rgba(255,255,255,.04);}
.dz-am-event-icon{width:36px;height:36px;border-radius:50%;background:rgba(224,53,26,.15);display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:2px;}
.dz-am-event-icon i{font-size:17px;color:#E0351A;}
.dz-am-event-body{flex:1;min-width:0;}
.dz-am-event-type{font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#E0351A;margin-bottom:2px;}
.dz-am-event-title{font-size:14.5px;font-weight:600;color:#fff;word-break:break-word;}
.dz-am-event-date{font-size:12px;color:#8A8AA0;margin-top:2px;}
.dz-am-event-desc{font-size:13px;color:#B0B0C5;margin-top:4px;line-height:1.5;word-break:break-word;}

/* ── Edit modal tabs ───────────────────────────────────────────── */
.dz-am-edit-tabs{display:flex;overflow-x:auto;border-bottom:1px solid rgba(255,255,255,.08);padding:0 10px;gap:4px;-webkit-overflow-scrolling:touch;scrollbar-width:none;flex-shrink:0;}
.dz-am-edit-tabs::-webkit-scrollbar{display:none;}
.dz-am-etab{background:transparent;border:none;color:#8A8AA0;font-size:13px;font-weight:600;padding:10px 12px;cursor:pointer;white-space:nowrap;border-bottom:2px solid transparent;margin-bottom:-1px;}
.dz-am-etab.active{color:#E0351A;border-bottom-color:#E0351A;}

.dz-am-etab-panel{display:none;}
.dz-am-etab-panel.active{display:block;}

/* ── Edit form elements ────────────────────────────────────────── */
.dz-am-edit-group{margin-bottom:16px;flex:1;}
.dz-am-edit-row{display:flex;gap:12px;}
.dz-am-edit-group label{display:block;font-size:13px;font-weight:600;color:#B0B0C5;margin-bottom:6px;}
.dz-am-optional{font-weight:400;color:#5C5C70;font-size:12px;}
.dz-am-edit-group input,
.dz-am-edit-group select,
.dz-am-edit-group textarea{width:100%;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:11px 12px;color:#fff;font-size:14px;font-family:inherit;box-sizing:border-box;}
.dz-am-edit-group input:focus,
.dz-am-edit-group select:focus,
.dz-am-edit-group textarea:focus{outline:none;border-color:#E0351A;}
.dz-am-edit-group textarea{resize:vertical;}
.dz-am-char-hint{font-size:11.5px;color:#5C5C70;margin-top:5px;text-align:right;}

.dz-am-section-note{display:flex;align-items:center;gap:8px;background:rgba(224,53,26,.08);border:1px solid rgba(224,53,26,.2);border-radius:10px;padding:10px 12px;margin-bottom:16px;font-size:13px;color:#F2907D;line-height:1.4;}
.dz-am-section-note i{font-size:16px;flex-shrink:0;color:#E0351A;}

/* Sub-card */
.dz-am-sub-card{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:14px;margin-bottom:16px;}
.dz-am-sub-card-title{font-size:13px;font-weight:700;color:#9B9BB5;text-transform:uppercase;letter-spacing:.4px;margin-bottom:12px;}

/* Family pic upload */
.dz-am-pic-upload{width:80px;height:80px;border-radius:50%;border:2px dashed rgba(224,53,26,.4);cursor:pointer;display:flex;align-items:center;justify-content:center;overflow:hidden;background:rgba(224,53,26,.06);transition:border-color .2s;}
.dz-am-pic-upload:hover{border-color:#E0351A;}
.dz-am-pic-upload img{width:100%;height:100%;object-fit:cover;}
#famPicPlaceholder{display:flex;flex-direction:column;align-items:center;gap:4px;color:#E0351A;font-size:11px;text-align:center;}
#famPicPlaceholder i{font-size:22px;}

/* Consent row */
.dz-am-consent-row{display:flex;align-items:flex-start;gap:10px;cursor:pointer;margin-bottom:14px;font-size:13px;color:#B0B0C5;line-height:1.45;}
.dz-am-consent-row input[type=checkbox]{accent-color:#E0351A;width:16px;height:16px;flex-shrink:0;margin-top:1px;}

/* Add button */
.dz-am-add-btn{width:100%;background:rgba(224,53,26,.15);border:1px solid rgba(224,53,26,.3);border-radius:10px;color:#F2907D;font-size:14px;font-weight:600;padding:11px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:7px;transition:background .2s;}
.dz-am-add-btn:hover{background:rgba(224,53,26,.25);}
.dz-am-add-btn:disabled{opacity:.5;cursor:not-allowed;}

/* Item lists */
.dz-am-item-list{display:flex;flex-direction:column;gap:8px;}
.dz-am-list-item{display:flex;align-items:center;gap:10px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:10px 12px;}
.dz-am-list-item img{width:38px;height:38px;border-radius:50%;object-fit:cover;flex-shrink:0;}
.dz-am-list-item .dz-li-avatar{width:38px;height:38px;border-radius:50%;background:rgba(224,53,26,.15);display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.dz-am-list-item .dz-li-avatar i{font-size:18px;color:#E0351A;}
.dz-am-list-item-body{flex:1;min-width:0;}
.dz-am-list-item-name{font-size:14px;font-weight:600;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.dz-am-list-item-sub{font-size:12px;color:#8A8AA0;margin-top:1px;}
.dz-am-list-del{background:transparent;border:none;color:#555570;font-size:18px;cursor:pointer;padding:4px;flex-shrink:0;border-radius:6px;transition:color .2s;}
.dz-am-list-del:hover{color:#FF5F5F;}

/* Empty state */
.dz-am-empty-state{text-align:center;padding:60px 20px;color:#8A8AA0;}
.dz-am-empty-state i{font-size:40px;color:#4A4A60;margin-bottom:12px;display:block;}

/* ── 768px ─────────────────────────────────────────────────────── */
@media (max-width:768px){
  .dz-fs-header{padding:13px 52px;}
  .dz-fs-header h2{font-size:15px;}
  .dz-fs-close{font-size:25px;left:15px;}
  .dz-fs-edit{font-size:13px;right:15px;}
  .dz-fs-save{font-size:14px;right:15px;}
  .dz-fs-body{padding:14px;}
  .dz-am-card{padding:14px;margin-bottom:12px;border-radius:14px;}
  .dz-am-card h3{font-size:12px;margin-bottom:10px;}
  .dz-am-row{gap:10px;padding:7px 0;}
  .dz-am-row i{font-size:16px;width:20px;}
  .dz-am-label{font-size:11px;}
  .dz-am-value{font-size:14px;}
  .dz-am-description{font-size:13.5px;}
  .dz-am-tag{font-size:12px;padding:4px 10px;}
  .dz-am-interests{gap:6px;}
  .dz-am-family-grid{grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:8px;}
  .dz-am-family-card img,.dz-am-family-card .dz-fam-avatar{width:46px;height:46px;}
  .dz-am-family-name{font-size:12.5px;}
  .dz-am-family-rel{font-size:11px;}
  .dz-am-event-icon{width:32px;height:32px;}
  .dz-am-event-icon i{font-size:15px;}
  .dz-am-event-type{font-size:11px;}
  .dz-am-event-title{font-size:13.5px;}
  .dz-am-event-desc{font-size:12.5px;}
  .dz-am-etab{font-size:12.5px;padding:9px 10px;}
  .dz-am-edit-group{margin-bottom:13px;}
  .dz-am-edit-group label{font-size:12px;margin-bottom:5px;}
  .dz-am-edit-group input,.dz-am-edit-group select,.dz-am-edit-group textarea{padding:10px 11px;font-size:13px;border-radius:9px;}
  .dz-am-sub-card{padding:12px;}
  .dz-am-empty-state{padding:48px 16px;}
  .dz-am-empty-state i{font-size:34px;margin-bottom:10px;}
}

/* ── 480px ─────────────────────────────────────────────────────── */
@media (max-width:480px){
  .dz-fs-header{padding:11px 46px;}
  .dz-fs-header h2{font-size:14px;}
  .dz-fs-close{font-size:24px;left:13px;}
  .dz-fs-edit,.dz-fs-save{font-size:12px;gap:4px;right:13px;}
  .dz-fs-body{padding:12px;}
  .dz-am-card{padding:12px;margin-bottom:10px;border-radius:12px;}
  .dz-am-card h3{font-size:11px;letter-spacing:.3px;margin-bottom:9px;}
  .dz-am-row{gap:9px;padding:6px 0;}
  .dz-am-row i{font-size:15px;width:18px;margin-top:1px;}
  .dz-am-label{font-size:11px;margin-bottom:1px;}
  .dz-am-value{font-size:13.5px;}
  .dz-am-description{font-size:13px;line-height:1.55;}
  .dz-am-tag{font-size:11.5px;padding:3px 9px;}
  .dz-am-interests{gap:5px;margin-top:3px;}
  .dz-am-family-grid{grid-template-columns:repeat(auto-fill,minmax(95px,1fr));gap:7px;}
  .dz-am-family-card{padding:10px 6px;border-radius:10px;}
  .dz-am-family-card img,.dz-am-family-card .dz-fam-avatar{width:42px;height:42px;margin-bottom:6px;}
  .dz-am-family-name{font-size:12px;}
  .dz-am-family-rel{font-size:10.5px;}
  .dz-am-quote-item{font-size:13px;padding:7px 10px;}
  .dz-am-event-item{gap:10px;}
  .dz-am-event-icon{width:30px;height:30px;}
  .dz-am-event-icon i{font-size:14px;}
  .dz-am-event-type{font-size:10.5px;}
  .dz-am-event-title{font-size:13px;}
  .dz-am-event-date{font-size:11.5px;}
  .dz-am-event-desc{font-size:12px;}
  .dz-am-etab{font-size:12px;padding:8px 9px;}
  .dz-am-edit-row{flex-direction:column;gap:0;}
  .dz-am-edit-group{margin-bottom:11px;}
  .dz-am-edit-group label{font-size:11.5px;margin-bottom:4px;}
  .dz-am-edit-group input,.dz-am-edit-group select,.dz-am-edit-group textarea{padding:9px 10px;font-size:13px;border-radius:8px;}
  .dz-am-sub-card{padding:11px;border-radius:12px;}
  .dz-am-section-note{font-size:12.5px;padding:9px 11px;}
  .dz-am-empty-state{padding:40px 14px;}
  .dz-am-empty-state i{font-size:30px;margin-bottom:9px;}
}

/* ── 366px ─────────────────────────────────────────────────────── */
@media (max-width:380px){
  .dz-fs-header{padding:10px 40px;}
  .dz-fs-header h2{font-size:13px;}
  .dz-fs-close{font-size:22px;left:11px;}
  .dz-fs-edit,.dz-fs-save{font-size:11.5px;gap:3px;right:11px;}
  .dz-fs-body{padding:10px;}
  .dz-am-card{padding:10px;margin-bottom:9px;border-radius:11px;}
  .dz-am-card h3{font-size:10.5px;letter-spacing:.2px;margin-bottom:8px;}
  .dz-am-row{gap:8px;padding:5px 0;}
  .dz-am-row i{font-size:14px;width:17px;}
  .dz-am-label{font-size:10.5px;}
  .dz-am-value{font-size:13px;}
  .dz-am-description{font-size:12.5px;line-height:1.5;}
  .dz-am-tag{font-size:11px;padding:3px 8px;}
  .dz-am-interests{gap:4px;margin-top:2px;}
  .dz-am-family-grid{grid-template-columns:repeat(auto-fill,minmax(85px,1fr));gap:6px;}
  .dz-am-family-card{padding:9px 5px;}
  .dz-am-family-card img,.dz-am-family-card .dz-fam-avatar{width:38px;height:38px;margin-bottom:5px;}
  .dz-am-family-name{font-size:11.5px;}
  .dz-am-event-icon{width:28px;height:28px;}
  .dz-am-event-icon i{font-size:13px;}
  .dz-am-event-type{font-size:10px;}
  .dz-am-event-title{font-size:12.5px;}
  .dz-am-etab{font-size:11.5px;padding:7px 8px;}
  .dz-am-edit-group{margin-bottom:10px;}
  .dz-am-edit-group label{font-size:11px;margin-bottom:4px;}
  .dz-am-edit-group input,.dz-am-edit-group select,.dz-am-edit-group textarea{padding:8px 9px;font-size:12.5px;border-radius:8px;}
  .dz-am-empty-state{padding:36px 12px;}
  .dz-am-empty-state i{font-size:27px;margin-bottom:8px;}
}

/* ── 320px ─────────────────────────────────────────────────────── */
@media (max-width:320px){
  .dz-fs-header{padding:9px 36px;}
  .dz-fs-header h2{font-size:12.5px;}
  .dz-fs-close{font-size:19px;left:10px;}
  .dz-fs-edit,.dz-fs-save{font-size:11px;gap:2px;right:10px;}
  .dz-fs-body{padding:8px;}
  .dz-am-card{padding:9px;margin-bottom:8px;border-radius:10px;}
  .dz-am-card h3{font-size:10px;letter-spacing:0;margin-bottom:7px;}
  .dz-am-row{gap:7px;padding:5px 0;}
  .dz-am-row i{font-size:13px;width:16px;}
  .dz-am-label{font-size:10px;}
  .dz-am-value{font-size:12.5px;}
  .dz-am-description{font-size:12px;line-height:1.5;}
  .dz-am-tag{font-size:10.5px;padding:2px 7px;}
  .dz-am-interests{gap:4px;}
  .dz-am-family-grid{grid-template-columns:repeat(auto-fill,minmax(78px,1fr));}
  .dz-am-family-card img,.dz-am-family-card .dz-fam-avatar{width:34px;height:34px;}
  .dz-am-family-name{font-size:11px;}
  .dz-am-family-rel{font-size:10px;}
  .dz-am-etab{font-size:11px;padding:7px 7px;}
  .dz-am-edit-group{margin-bottom:9px;}
  .dz-am-edit-group label{font-size:10.5px;margin-bottom:3px;}
  .dz-am-edit-group input,.dz-am-edit-group select,.dz-am-edit-group textarea{padding:7px 8px;font-size:12px;border-radius:7px;}
  .dz-am-empty-state{padding:30px 10px;}
  .dz-am-empty-state i{font-size:24px;margin-bottom:7px;}
}

/* ── Custom scrollbars (About Me modals) ─────────────────────── */
.dz-fs-body::-webkit-scrollbar {
    width: 4px;
}
.dz-fs-body::-webkit-scrollbar-track {
    background: rgba(255, 255, 255, 0.03);
    border-radius: 999px;
}
.dz-fs-body::-webkit-scrollbar-thumb {
    background: rgba(255, 255, 255, 0.4);
    border-radius: 999px;
    transition: background 0.2s;
}
.dz-fs-body::-webkit-scrollbar-thumb:hover {
    background: rgb(255, 255, 255);
}

/* Firefox */
.dz-fs-body {
    scrollbar-width: thin;
    scrollbar-color: rgba(255, 255, 255, 0.4) rgba(255, 255, 255, 0.03);
}
</style>

<!-- ===================== ABOUT ME JS ===================== -->
<script>
let dzAboutMeData        = null;
let dzFamilyData         = [];
let dzEventsData         = [];
let dzAboutMeProfileId   = null;
let dzFamPicFile         = null;
let dzAboutMeSkeletonShown = false; // gates the shimmer loader to once per page load/refresh

// ── Skeleton markup (kept as a function so it stays in sync everywhere) ────
function dzSkeletonHTML() {
    return `
        <div class="dz-am-skeleton">
            <div class="dz-sk-card">
                <div class="dz-sk-title"></div>
                <div class="dz-sk-row"><div class="dz-sk-icon"></div><div class="dz-sk-lines"><div class="dz-sk-line short"></div><div class="dz-sk-line"></div></div></div>
                <div class="dz-sk-row"><div class="dz-sk-icon"></div><div class="dz-sk-lines"><div class="dz-sk-line short"></div><div class="dz-sk-line med"></div></div></div>
                <div class="dz-sk-row"><div class="dz-sk-icon"></div><div class="dz-sk-lines"><div class="dz-sk-line short"></div><div class="dz-sk-line"></div></div></div>
                <div class="dz-sk-row"><div class="dz-sk-icon"></div><div class="dz-sk-lines"><div class="dz-sk-line short"></div><div class="dz-sk-line med"></div></div></div>
            </div>
            <div class="dz-sk-card">
                <div class="dz-sk-title"></div>
                <div class="dz-sk-row"><div class="dz-sk-icon"></div><div class="dz-sk-lines"><div class="dz-sk-line short"></div><div class="dz-sk-line"></div></div></div>
                <div class="dz-sk-row"><div class="dz-sk-icon"></div><div class="dz-sk-lines"><div class="dz-sk-line short"></div><div class="dz-sk-line med"></div></div></div>
            </div>
            <div class="dz-sk-card">
                <div class="dz-sk-title"></div>
                <div class="dz-sk-row"><div class="dz-sk-icon"></div><div class="dz-sk-lines"><div class="dz-sk-line short"></div><div class="dz-sk-line"></div></div></div>
                <div class="dz-sk-row"><div class="dz-sk-icon"></div><div class="dz-sk-lines"><div class="dz-sk-line short"></div><div class="dz-sk-line med"></div></div></div>
            </div>
            <div class="dz-sk-card">
                <div class="dz-sk-title"></div>
                <div class="dz-sk-row"><div class="dz-sk-icon"></div><div class="dz-sk-lines"><div class="dz-sk-line short"></div><div class="dz-sk-line med"></div></div></div>
                <div class="dz-sk-chip-row">
                    <div class="dz-sk-chip"></div><div class="dz-sk-chip"></div><div class="dz-sk-chip"></div>
                </div>
            </div>
            <div class="dz-sk-card">
                <div class="dz-sk-title"></div>
                <div class="dz-sk-line full" style="height:60px;border-radius:8px;"></div>
            </div>
            <div class="dz-sk-card">
                <div class="dz-sk-title"></div>
                <div class="dz-sk-quote"></div>
                <div class="dz-sk-quote" style="width:80%;"></div>
            </div>
            <div class="dz-sk-card">
                <div class="dz-sk-title"></div>
                <div class="dz-sk-fam-grid">
                    <div class="dz-sk-fam-card"><div class="dz-sk-avatar"></div><div class="dz-sk-fam-name"></div><div class="dz-sk-fam-rel"></div></div>
                    <div class="dz-sk-fam-card"><div class="dz-sk-avatar"></div><div class="dz-sk-fam-name"></div><div class="dz-sk-fam-rel"></div></div>
                    <div class="dz-sk-fam-card"><div class="dz-sk-avatar"></div><div class="dz-sk-fam-name"></div><div class="dz-sk-fam-rel"></div></div>
                </div>
            </div>
            <div class="dz-sk-card">
                <div class="dz-sk-title"></div>
                <div class="dz-sk-event-row"><div class="dz-sk-icon"></div><div class="dz-sk-lines"><div class="dz-sk-line short"></div><div class="dz-sk-line med"></div></div></div>
                <div class="dz-sk-event-row"><div class="dz-sk-icon"></div><div class="dz-sk-lines"><div class="dz-sk-line short"></div><div class="dz-sk-line"></div></div></div>
            </div>
        </div>`;
}

// ── Open / close view modal ────────────────────────────────────────────────
function dzOpenAboutMe(profileId) {
    dzAboutMeProfileId = profileId;
    const modal = document.getElementById('aboutMeModal');
    const body  = document.getElementById('aboutMeBody');
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';

    // Only show the shimmer skeleton the first time this modal opens per page load/refresh.
    // Every open after that goes straight to the fetch and pops the real content in when ready.
    if (!dzAboutMeSkeletonShown) {
        body.innerHTML = dzSkeletonHTML();
    }

    fetch('about_me_fetch.php?user_id=' + profileId)
        .then(r => r.json())
        .then(data => {
            dzAboutMeSkeletonShown = true;
            if (!data.success) {
                body.innerHTML = `<div class="dz-am-empty-state"><i class="ri-lock-line"></i><p>${dzEscape(data.message)}</p></div>`;
                document.getElementById('aboutMeEditBtn').style.display = 'none';
                return;
            }
            dzAboutMeData = data.about;
            dzFamilyData  = data.family  || [];
            dzEventsData  = data.events  || [];
            document.getElementById('aboutMeEditBtn').style.display = data.is_owner ? 'flex' : 'none';
            body.innerHTML = dzRenderAboutMe(data.about, data.family, data.events, data.is_owner);
        })
        .catch(() => {
            dzAboutMeSkeletonShown = true;
            body.innerHTML = '<div class="dz-am-empty-state"><i class="ri-error-warning-line"></i><p>Something went wrong. Try again.</p></div>';
        });
}

function dzCloseAboutMe() {
    document.getElementById('aboutMeModal').classList.remove('active');
    document.body.style.overflow = '';
}

// ── Utilities ──────────────────────────────────────────────────────────────
function dzEscape(str) {
    const div = document.createElement('div');
    div.textContent = str ?? '';
    return div.innerHTML;
}

function dzVal(v) {
    if (v === null || v === undefined || v.toString().trim() === '')
        return `<span class="dz-am-value empty">Not filled yet</span>`;
    return `<span class="dz-am-value">${dzEscape(v)}</span>`;
}

function dzFormatDOB(dob) {
    if (!dob) return null;
    const d = new Date(dob + 'T00:00:00');
    if (isNaN(d.getTime())) return null;
    return d.toLocaleDateString('en-US', { year:'numeric', month:'long', day:'numeric' });
}

function dzFormatEventDate(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr + 'T00:00:00');
    if (isNaN(d.getTime())) return '';
    return d.toLocaleDateString('en-US', { year:'numeric', month:'long', day:'numeric' });
}

const DZ_EVENT_ICONS = {
    'Graduation'          : 'ri-graduation-cap-line',
    'Marriage'            : 'ri-heart-2-line',
    'New Job'             : 'ri-briefcase-4-line',
    'Promotion'           : 'ri-arrow-up-circle-line',
    'Moved Cities'        : 'ri-map-pin-2-line',
    'New Home'            : 'ri-home-4-line',
    'New Baby'            : 'ri-parent-line',
    'Lost a Loved One'    : 'ri-flower-line',
    'Retirement'          : 'ri-rest-time-line',
    'Business Launch'     : 'ri-rocket-2-line',
    'Award / Achievement' : 'ri-trophy-line',
    'Milestone Birthday'  : 'ri-cake-2-line',
    'Travel'              : 'ri-plane-line',
    'Other'               : 'ri-star-line',
};

// ── Render view modal ──────────────────────────────────────────────────────
function dzRenderAboutMe(a, family, events, isOwner) {
    const dobFormatted = dzFormatDOB(a.date_of_birth);

    const interestsHtml = (a.interests && a.interests.trim())
        ? `<div class="dz-am-interests">${a.interests.split(',').map(i=>i.trim()).filter(Boolean).map(i=>`<span class="dz-am-tag">${dzEscape(i)}</span>`).join('')}</div>`
        : `<span class="dz-am-value empty">Not filled yet</span>`;

    const websiteHtml = (a.website && a.website.trim())
        ? `<span class="dz-am-value"><a href="${dzEscape(a.website)}" target="_blank" rel="noopener noreferrer">${dzEscape(a.website.replace(/^https?:\/\//,'').replace(/\/$/,''))}</a></span>`
        : `<span class="dz-am-value empty">Not filled yet</span>`;

    const hasContact = (a.contact_email && a.contact_email.trim()) || (a.contact_phone && a.contact_phone.trim());
    const contactCard = hasContact ? `
        <div class="dz-am-card">
            <h3>Contact Information</h3>
            ${(a.contact_email && a.contact_email.trim()) ? `
            <div class="dz-am-row">
                <i class="ri-mail-line"></i>
                <div class="dz-am-row-content">
                    <div class="dz-am-label">Email</div>
                    <span class="dz-am-value"><a href="mailto:${dzEscape(a.contact_email)}">${dzEscape(a.contact_email)}</a></span>
                </div>
            </div>` : ''}
            ${(a.contact_phone && a.contact_phone.trim()) ? `
            <div class="dz-am-row">
                <i class="ri-phone-line"></i>
                <div class="dz-am-row-content">
                    <div class="dz-am-label">Phone</div>
                    <span class="dz-am-value"><a href="tel:${dzEscape(a.contact_phone)}">${dzEscape(a.contact_phone)}</a></span>
                </div>
            </div>` : ''}
        </div>` : '';

    const hasViews = (a.political_views && a.political_views.trim()) || (a.religious_views && a.religious_views.trim());
    const viewsCard = hasViews ? `
        <div class="dz-am-card">
            <h3>Views</h3>
            ${(a.political_views && a.political_views.trim()) ? `
            <div class="dz-am-row">
                <i class="ri-government-line"></i>
                <div class="dz-am-row-content">
                    <div class="dz-am-label">Political Views</div>
                    ${dzVal(a.political_views)}
                </div>
            </div>` : ''}
            ${(a.religious_views && a.religious_views.trim()) ? `
            <div class="dz-am-row">
                <i class="ri-book-open-line"></i>
                <div class="dz-am-row-content">
                    <div class="dz-am-label">Religious Views</div>
                    ${dzVal(a.religious_views)}
                </div>
            </div>` : ''}
        </div>` : '';

    const quotesCard = (a.favorite_quotes && a.favorite_quotes.trim()) ? `
        <div class="dz-am-card">
            <h3>Favorite Quotes</h3>
            <div class="dz-am-quotes-list">
                ${a.favorite_quotes.split('\n').map(q=>q.trim()).filter(Boolean).map(q=>
                    `<div class="dz-am-quote-item">${dzEscape(q)}</div>`
                ).join('')}
            </div>
        </div>` : '';

    const familyCard = (family && family.length > 0) ? `
        <div class="dz-am-card">
            <h3>Family Members</h3>
            <div class="dz-am-family-grid">
                ${family.map(m => `
                <div class="dz-am-family-card">
                    ${m.profile_pic
                        ? `<img src="${dzEscape(m.profile_pic)}" alt="${dzEscape(m.member_name)}">`
                        : `<div class="dz-fam-avatar"><i class="ri-user-3-line"></i></div>`}
                    <div class="dz-am-family-name">${dzEscape(m.member_name)}</div>
                    <div class="dz-am-family-rel">${dzEscape(m.relationship)}</div>
                </div>`).join('')}
            </div>
        </div>` : '';

    const eventsCard = (events && events.length > 0) ? `
        <div class="dz-am-card">
            <h3>Life Events</h3>
            <div class="dz-am-events-list">
                ${events.map(ev => {
                    const icon    = DZ_EVENT_ICONS[ev.event_type] || 'ri-star-line';
                    const dateStr = dzFormatEventDate(ev.event_date);
                    return `
                    <div class="dz-am-event-item">
                        <div class="dz-am-event-icon"><i class="${icon}"></i></div>
                        <div class="dz-am-event-body">
                            <div class="dz-am-event-type">${dzEscape(ev.event_type)}</div>
                            <div class="dz-am-event-title">${dzEscape(ev.event_title)}</div>
                            ${dateStr ? `<div class="dz-am-event-date">${dateStr}</div>` : ''}
                            ${(ev.description && ev.description.trim()) ? `<div class="dz-am-event-desc">${dzEscape(ev.description)}</div>` : ''}
                        </div>
                    </div>`;
                }).join('')}
            </div>
        </div>` : '';

    return `
        <div class="dz-am-card">
            <h3>Basic Info</h3>
            <div class="dz-am-row"><i class="ri-user-3-line"></i><div class="dz-am-row-content"><div class="dz-am-label">Full Name</div>${dzVal(a.full_name)}</div></div>
            <div class="dz-am-row"><i class="ri-emotion-line"></i><div class="dz-am-row-content"><div class="dz-am-label">Nickname</div>${dzVal(a.nickname)}</div></div>
            <div class="dz-am-row"><i class="ri-cake-2-line"></i><div class="dz-am-row-content"><div class="dz-am-label">Date of Birth</div>${dobFormatted ? `<span class="dz-am-value">${dobFormatted}</span>` : `<span class="dz-am-value empty">Not filled yet</span>`}</div></div>
            <div class="dz-am-row"><i class="ri-heart-2-line"></i><div class="dz-am-row-content"><div class="dz-am-label">Relationship Status</div>${dzVal(a.relationship_status)}</div></div>
        </div>
        <div class="dz-am-card">
            <h3>Work &amp; Education</h3>
            <div class="dz-am-row"><i class="ri-briefcase-4-line"></i><div class="dz-am-row-content"><div class="dz-am-label">Occupation</div>${dzVal(a.occupation)}</div></div>
            <div class="dz-am-row"><i class="ri-graduation-cap-line"></i><div class="dz-am-row-content"><div class="dz-am-label">Education</div>${dzVal(a.education)}</div></div>
        </div>
        <div class="dz-am-card">
            <h3>Location</h3>
            <div class="dz-am-row"><i class="ri-map-pin-2-line"></i><div class="dz-am-row-content"><div class="dz-am-label">Current Location</div>${dzVal(a.current_location)}</div></div>
            <div class="dz-am-row"><i class="ri-home-4-line"></i><div class="dz-am-row-content"><div class="dz-am-label">Hometown</div>${dzVal(a.hometown)}</div></div>
        </div>
        <div class="dz-am-card">
            <h3>Links &amp; Interests</h3>
            <div class="dz-am-row"><i class="ri-global-line"></i><div class="dz-am-row-content"><div class="dz-am-label">Website</div>${websiteHtml}</div></div>
            <div class="dz-am-row"><i class="ri-sparkling-2-line"></i><div class="dz-am-row-content"><div class="dz-am-label">Interests</div>${interestsHtml}</div></div>
        </div>
        <div class="dz-am-card">
            <h3>Description</h3>
            ${(a.description && a.description.trim()) ? `<div class="dz-am-description">${dzEscape(a.description).replace(/\n/g,'<br>')}</div>` : `<span class="dz-am-value empty">Not filled yet</span>`}
        </div>
        ${contactCard}${viewsCard}${quotesCard}${familyCard}${eventsCard}
    `;
}

// ── Open / close edit modal ────────────────────────────────────────────────
function dzOpenEditAboutMe() {
    if (!dzAboutMeData) return;
    const form = document.getElementById('editAboutMeForm');
    Object.keys(dzAboutMeData).forEach(key => {
        const field = form.elements[key];
        if (field) field.value = dzAboutMeData[key] || '';
    });
    dzSwitchEditTab('core');
    dzRenderFamilyList();
    dzRenderEventList();
    dzFamPicFile = null;
    document.getElementById('famPicPreview').style.display  = 'none';
    document.getElementById('famPicPlaceholder').style.display = 'flex';
    document.getElementById('famPicInput').value       = '';
    document.getElementById('famName').value           = '';
    document.getElementById('famRelationship').value   = '';
    document.getElementById('famConsent').checked      = false;
    document.getElementById('editAboutMeModal').classList.add('active');
}

function dzCloseEditAboutMe() {
    document.getElementById('editAboutMeModal').classList.remove('active');
}

// ── Edit tab switching ─────────────────────────────────────────────────────
function dzSwitchEditTab(tab) {
    document.querySelectorAll('.dz-am-etab').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
    document.querySelectorAll('.dz-am-etab-panel').forEach(p => p.classList.toggle('active', p.id === 'etab-' + tab));
}

// ── Save core fields ───────────────────────────────────────────────────────
function dzSaveAboutMe() {
    const form    = document.getElementById('editAboutMeForm');
    const fd      = new FormData(form);
    fd.set('action', 'core');
    const saveBtn = document.querySelector('#editAboutMeModal .dz-fs-save');
    saveBtn.disabled    = true;
    saveBtn.textContent = 'Saving…';
    fetch('about_me_save.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(data => {
            saveBtn.disabled    = false;
            saveBtn.textContent = 'Save';
            if (data.success) { dzCloseEditAboutMe(); dzOpenAboutMe(dzAboutMeProfileId); }
            else alert(data.message || 'Could not save. Try again.');
        })
        .catch(() => { saveBtn.disabled = false; saveBtn.textContent = 'Save'; alert('Something went wrong. Try again.'); });
}

// ── Family: pic preview ────────────────────────────────────────────────────
function dzPreviewFamilyPic(input) {
    if (!input.files || !input.files[0]) return;
    dzFamPicFile = input.files[0];
    const reader = new FileReader();
    reader.onload = e => {
        const img = document.getElementById('famPicPreview');
        img.src = e.target.result;
        img.style.display = 'block';
        document.getElementById('famPicPlaceholder').style.display = 'none';
    };
    reader.readAsDataURL(dzFamPicFile);
}

// ── Family: add ───────────────────────────────────────────────────────────
function dzAddFamilyMember() {
    const name    = document.getElementById('famName').value.trim();
    const rel     = document.getElementById('famRelationship').value;
    const consent = document.getElementById('famConsent').checked;
    if (!name)    { alert('Please enter their name.'); return; }
    if (!rel)     { alert('Please select a relationship.'); return; }
    if (!consent) { alert('You must confirm consent before adding a family member.'); return; }
    const btn = document.querySelector('#etab-family .dz-am-add-btn');
    btn.disabled = true;
    const fd = new FormData();
    fd.append('action', 'family_add');
    fd.append('member_name', name);
    fd.append('relationship', rel);
    fd.append('consent_given', '1');
    if (dzFamPicFile) fd.append('member_pic', dzFamPicFile);
    fetch('about_me_save.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            if (data.success) {
                dzFamilyData.push(data.member);
                dzRenderFamilyList();
                document.getElementById('famName').value         = '';
                document.getElementById('famRelationship').value = '';
                document.getElementById('famConsent').checked    = false;
                dzFamPicFile = null;
                document.getElementById('famPicPreview').style.display    = 'none';
                document.getElementById('famPicPlaceholder').style.display = 'flex';
                document.getElementById('famPicInput').value = '';
            } else { alert(data.message || 'Could not add member.'); }
        })
        .catch(() => { btn.disabled = false; alert('Something went wrong.'); });
}

// ── Family: delete ─────────────────────────────────────────────────────────
function dzDeleteFamilyMember(memberId) {
    if (!confirm('Remove this family member?')) return;
    const fd = new FormData();
    fd.append('action', 'family_delete');
    fd.append('member_id', memberId);
    fetch('about_me_save.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) { dzFamilyData = dzFamilyData.filter(m => m.id != memberId); dzRenderFamilyList(); }
            else alert(data.message || 'Could not remove member.');
        })
        .catch(() => alert('Something went wrong.'));
}

// ── Family: render list ────────────────────────────────────────────────────
function dzRenderFamilyList() {
    const list = document.getElementById('familyList');
    if (!dzFamilyData.length) {
        list.innerHTML = '<div style="text-align:center;padding:20px 0;color:#5C5C70;font-size:13px;">No family members added yet.</div>';
        return;
    }
    list.innerHTML = dzFamilyData.map(m => `
        <div class="dz-am-list-item">
            ${m.profile_pic ? `<img src="${dzEscape(m.profile_pic)}" alt="${dzEscape(m.member_name)}">` : `<div class="dz-li-avatar"><i class="ri-user-3-line"></i></div>`}
            <div class="dz-am-list-item-body">
                <div class="dz-am-list-item-name">${dzEscape(m.member_name)}</div>
                <div class="dz-am-list-item-sub">${dzEscape(m.relationship)}</div>
            </div>
            <button class="dz-am-list-del" onclick="dzDeleteFamilyMember(${m.id})" title="Remove"><i class="ri-delete-bin-6-line"></i></button>
        </div>`).join('');
}

// ── Events: add ───────────────────────────────────────────────────────────
function dzAddLifeEvent() {
    const type  = document.getElementById('evType').value;
    const title = document.getElementById('evTitle').value.trim();
    const date  = document.getElementById('evDate').value;
    const desc  = document.getElementById('evDesc').value.trim();
    if (!type)  { alert('Please select an event type.'); return; }
    if (!title) { alert('Please enter a title for this event.'); return; }
    const btn = document.querySelector('#etab-events .dz-am-add-btn');
    btn.disabled = true;
    const fd = new FormData();
    fd.append('action', 'event_add');
    fd.append('event_type', type);
    fd.append('event_title', title);
    fd.append('event_date', date);
    fd.append('event_description', desc);
    fetch('about_me_save.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            if (data.success) {
                dzEventsData.unshift(data.event);
                dzRenderEventList();
                document.getElementById('evType').value  = '';
                document.getElementById('evTitle').value = '';
                document.getElementById('evDate').value  = '';
                document.getElementById('evDesc').value  = '';
            } else { alert(data.message || 'Could not add event.'); }
        })
        .catch(() => { btn.disabled = false; alert('Something went wrong.'); });
}

// ── Events: delete ─────────────────────────────────────────────────────────
function dzDeleteLifeEvent(eventId) {
    if (!confirm('Remove this life event?')) return;
    const fd = new FormData();
    fd.append('action', 'event_delete');
    fd.append('event_id', eventId);
    fetch('about_me_save.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) { dzEventsData = dzEventsData.filter(e => e.id != eventId); dzRenderEventList(); }
            else alert(data.message || 'Could not remove event.');
        })
        .catch(() => alert('Something went wrong.'));
}

// ── Events: render list ────────────────────────────────────────────────────
function dzRenderEventList() {
    const list = document.getElementById('eventList');
    if (!dzEventsData.length) {
        list.innerHTML = '<div style="text-align:center;padding:20px 0;color:#5C5C70;font-size:13px;">No life events added yet.</div>';
        return;
    }
    list.innerHTML = dzEventsData.map(ev => {
        const icon    = DZ_EVENT_ICONS[ev.event_type] || 'ri-star-line';
        const dateStr = dzFormatEventDate(ev.event_date);
        return `
        <div class="dz-am-list-item">
            <div class="dz-li-avatar"><i class="${icon}"></i></div>
            <div class="dz-am-list-item-body">
                <div class="dz-am-list-item-name">${dzEscape(ev.event_title)}</div>
                <div class="dz-am-list-item-sub">${dzEscape(ev.event_type)}${dateStr ? ' · ' + dateStr : ''}</div>
            </div>
            <button class="dz-am-list-del" onclick="dzDeleteLifeEvent(${ev.id})" title="Remove"><i class="ri-delete-bin-6-line"></i></button>
        </div>`;
    }).join('');
}
</script>

<!-- ══ TAB CHOICE SHEET (owner only) ══ -->
<div id="dzTabSheet"
     onclick="if(event.target===this)dzCloseTabSheet()"
     style="display:none;position:fixed;inset:0;z-index:9999999;background:rgba(0,0,0,0.75);backdrop-filter:blur(6px);align-items:flex-end;justify-content:center;padding:0 16px;">
    <div style="background:rgba(14,13,26,0.98);border:1px solid rgba(255,255,255,0.08);border-radius:24px 24px 18px 18px;width:100%;max-width:480px;overflow:hidden;box-shadow:0 -8px 40px rgba(0,0,0,0.6);animation:sheetUp 0.28s cubic-bezier(0.34,1.56,0.64,1);margin-bottom:12px;">

        <!-- Handle -->
        <div style="display:flex;justify-content:center;padding:14px 0 4px;">
            <div style="width:36px;height:4px;border-radius:4px;background:rgba(255,255,255,0.18);"></div>
        </div>

        <!-- Title -->
        <div id="dzTabSheetTitle" style="font-size:14px;font-weight:700;color:white;text-align:center;padding:8px 20px 16px;letter-spacing:0.2px;">
            What would you like to do?
        </div>

        <!-- Go to Page -->
        <div onclick="dzTabGoToPage()" style="display:flex;align-items:center;gap:16px;padding:16px 22px;cursor:pointer;border-top:1px solid rgba(255,255,255,0.07);transition:background 0.15s;-webkit-tap-highlight-color:transparent;"
             onmouseover="this.style.background='rgba(255,255,255,0.05)'"
             onmouseout="this.style.background='transparent'">
            <div style="width:44px;height:44px;border-radius:14px;background:rgba(255,45,45,0.12);border:1px solid rgba(255,45,45,0.25);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="ri-external-link-line" style="font-size:20px;color:#f24455;"></i>
            </div>
            <div>
                <div style="font-size:14px;font-weight:600;color:white;margin-bottom:3px;">Go to Page</div>
                <div style="font-size:11px;color:rgba(255,255,255,0.4);">Open the full dedicated page</div>
            </div>
            <i class="ri-arrow-right-s-line" style="font-size:20px;color:rgba(255,255,255,0.25);margin-left:auto;flex-shrink:0;"></i>
        </div>

        <!-- Browse Here -->
        <div onclick="dzTabBrowseHere()" style="display:flex;align-items:center;gap:16px;padding:16px 22px;cursor:pointer;border-top:1px solid rgba(255,255,255,0.07);transition:background 0.15s;-webkit-tap-highlight-color:transparent;"
             onmouseover="this.style.background='rgba(255,255,255,0.05)'"
             onmouseout="this.style.background='transparent'">
            <div style="width:44px;height:44px;border-radius:14px;background:rgba(222, 77, 77, 0.1);border:1px solid rgba(222, 77, 77, 0.29);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="ri-grid-line" style="font-size:20px;color: #de4d4d;"></i>
            </div>
            <div>
                <div style="font-size:14px;font-weight:600;color:white;margin-bottom:3px;">Browse Here</div>
                <div style="font-size:11px;color:rgba(255,255,255,0.4);">View the grid on this profile page</div>
            </div>
            <i class="ri-arrow-right-s-line" style="font-size:20px;color:rgba(255,255,255,0.25);margin-left:auto;flex-shrink:0;"></i>
        </div>

        <!-- Cancel -->
        <div onclick="dzCloseTabSheet()" style="text-align:center;padding:14px 22px 20px;color:rgba(255,255,255,0.35);font-size:13px;font-weight:600;cursor:pointer;border-top:1px solid rgba(255,255,255,0.07);-webkit-tap-highlight-color:transparent;transition:color 0.15s;"
             onmouseover="this.style.color='white'"
             onmouseout="this.style.color='rgba(255,255,255,0.35)'">
            Cancel
        </div>
    </div>
</div>

<script>
// ── Active tab tracker ──
var _dzActiveTab  = null;
var _dzActiveUrl  = null;      // ← saved BEFORE sheet closes
var _dzActiveSec  = null;

var _dzTabConfig = {
    memes: {
        title:   'Your Memes',
        goUrl:   'meme_uploads.php?user_id=<?= (int)$profile_id ?>',
        section: 'memes',
    },
    reposts: {
        title:   'Your Reposts',
        goUrl:   'repost.php?user_id=<?= (int)$profile_id ?>',
        section: 'reposts',
    },
};

function dzShowTabSheet(tab) {
    _dzActiveTab = tab;
    var cfg      = _dzTabConfig[tab];

    // ── Save values NOW before any close happens ──
    _dzActiveUrl = cfg.goUrl;
    _dzActiveSec = cfg.section;

    document.getElementById('dzTabSheetTitle').textContent = cfg.title;
    document.getElementById('dzTabSheet').style.display    = 'flex';
}

function dzCloseTabSheet() {
    document.getElementById('dzTabSheet').style.display = 'none';
    // NOTE: do NOT clear _dzActiveUrl/_dzActiveSec here
    // They are read by dzTabGoToPage/dzTabBrowseHere first
}

function dzTabGoToPage() {
    var url = _dzActiveUrl;   // grab BEFORE closing
    dzCloseTabSheet();
    if (url) window.location.href = url;
}

function dzTabBrowseHere() {
    var sec = _dzActiveSec;   // grab BEFORE closing
    dzCloseTabSheet();
    if (sec) toggleSection(sec);
}
</script>



<style>
/* ══ SORT + TAB PILLS ══ */
.grid-sort-bar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:0 2px 14px;}
.g-pill{
    padding:6px 16px;border-radius:10px;border:1px solid rgba(255,255,255,0.14);
    background:transparent;color:rgba(255,255,255,0.65);font-size:12px;font-weight:600;
    cursor:pointer;font-family:Poppins,sans-serif;transition:all 0.22s ease;
    -webkit-tap-highlight-color:transparent;white-space:nowrap;position:relative;top:-8.5px;
}
.g-pill:hover{border-color:rgba(255,45,45,0.5);color:white;background:rgba(255,45,45,0.08);}
.g-pill.active{background:rgba(255,45,45,0.18);border-color:rgba(255,45,45,0.6);color:#FF5566;box-shadow:0 0 16px rgba(255,45,45,0.15);}
.g-pill-div{width:1px;height:20px;background:rgba(255,255,255,0.1);flex-shrink:0;position:relative;top:-8.5px;}

/* Collections pill count badge */
.g-pill-count{
    color:#ff2d2d;font-size:10px;font-weight:800;margin-left:4px;
    letter-spacing:0.2px;
}

/* ══ COLLECTIONS FOLDER GRID ══ */
.col-section{display:none;}

/* ── Collection search bar ── */
.col-search-wrap{position:relative;margin-bottom:14px;}
.col-search-input{
    width:100%;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);
    border-radius:12px;padding:9px 14px 9px 36px;color:white;font-size:12px;
    font-family:Poppins,sans-serif;outline:none;transition:border-color 0.2s;box-sizing:border-box;
}
.col-search-input:focus{border-color:rgba(255,45,45,0.4);background:rgba(255,255,255,0.07);}
.col-search-input::placeholder{color:rgba(255,255,255,0.25);}
.col-search-icon{position:absolute;left:11px;top:50%;transform:translateY(-50%);font-size:14px;color:rgba(255,255,255,0.3);pointer-events:none;}
.col-search-clear{position:absolute;right:10px;top:50%;transform:translateY(-50%);font-size:13px;color:rgba(255,255,255,0.3);cursor:pointer;display:none;background:none;border:none;padding:0;line-height:1;}
.col-search-clear:hover{color:rgba(255,255,255,0.7);}

.col-folders-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:14px;padding:4px 0 20px;}
.col-folder-card{border-radius:18px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);overflow:hidden;cursor:pointer;transition:all 0.25s;position:relative;}
.col-folder-card:hover{border-color:rgba(255,45,45,0.35);transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,0.3);}
.col-folder-card.is-public{border-color:rgba(255,85,102,0.2);}
.col-folder-card.is-pinned-col{border-color:rgba(255,45,45,0.5);box-shadow:0 0 18px rgba(255,45,45,0.12);}
.col-folder-preview{width:100%;aspect-ratio:1;display:grid;grid-template-columns:1fr 1fr;grid-template-rows:1fr 1fr;gap:2px;background:rgba(255,255,255,0.03);overflow:hidden;}
.col-fp-item{background:rgba(255,255,255,0.06);overflow:hidden;display:flex;align-items:center;justify-content:center;}
.col-fp-item img,.col-fp-item video{width:100%;height:100%;object-fit:cover;display:block;}
.col-fp-empty{background:rgba(255,255,255,0.04);display:flex;align-items:center;justify-content:center;}
.col-fp-empty i{font-size:14px;color:rgba(255,255,255,0.08);}
.col-folder-info{padding:10px 12px 10px;}
.col-folder-name{font-size:13px;font-weight:600;color:white;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:3px;}
.col-folder-count{font-size:11px;color:rgba(255,255,255,0.4);margin-bottom:5px;}
.col-folder-timeline{display:flex;flex-direction:column;gap:2px;border-top:1px solid rgba(255,255,255,0.06);padding-top:6px;margin-top:4px;}
.col-folder-tl-row{font-size:9.5px;color:rgba(255,255,255,0.28);display:flex;align-items:center;gap:4px;letter-spacing:.1px;}
.col-folder-tl-row i{font-size:10px;flex-shrink:0;color:rgba(255,255,255,0.2);}
.col-folder-tl-row span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.col-folder-vis{font-size:10px;font-weight:600;margin-top:5px;display:flex;align-items:center;gap:3px;letter-spacing:.3px;}
.col-folder-vis.pub{color:#4dde8a;}
.col-folder-vis.priv{color:rgba(255,255,255,0.3);}
.col-folder-opts{position:absolute;top:8px;right:8px;width:28px;height:28px;border-radius:50%;background:rgba(0,0,0,0.6);backdrop-filter:blur(8px);border:none;color:white;font-size:14px;display:flex;align-items:center;justify-content:center;cursor:pointer;opacity:0;transition:opacity 0.2s;z-index:5;-webkit-tap-highlight-color:transparent;}
.col-folder-card:hover .col-folder-opts{opacity:1;}
@media(max-width:768px){.col-folder-opts{opacity:1;}}
.col-new-btn{border-radius:18px;background:rgba(255,45,45,0.07);border:1.5px dashed rgba(255,45,45,0.3);cursor:pointer;transition:all 0.25s;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;min-height:150px;color:rgba(255,85,102,0.7);font-family:Poppins,sans-serif;font-size:12px;font-weight:600;-webkit-tap-highlight-color:transparent;width:100%;order:-1;}
.col-new-btn:hover{border-color:rgba(255,45,45,0.6);color:rgba(255,85,102,1);background:rgba(255,45,45,0.12);}
.col-new-btn i{font-size:26px;}

/* Public badge on card */
.col-public-badge{position:absolute;top:8px;left:8px;background:rgba(0,0,0,0.65);backdrop-filter:blur(8px);border:1px solid rgba(77,222,138,0.45);border-radius:20px;padding:3px 8px;font-size:9px;font-weight:700;color:#4dde8a;letter-spacing:.5px;text-transform:uppercase;z-index:5;pointer-events:none;}

/* Pinned badge on collection card */
.col-pin-badge{position:absolute;top:8px;left:8px;background:rgba(0,0,0,0.65);backdrop-filter:blur(8px);border:1px solid rgba(255,45,45,0.5);border-radius:20px;padding:3px 8px;font-size:9px;font-weight:700;color:#ff5566;letter-spacing:.5px;z-index:5;pointer-events:none;}
.col-pin-badge.with-pub{top:30px;}

/* ══ COLLECTION DETAIL ══ */
.col-detail{display:none;}
.col-detail-hdr{display:flex;align-items:center;gap:12px;padding:0 0 12px;}
.col-back-btn{width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);color:white;font-size:16px;display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;transition:all 0.2s;-webkit-tap-highlight-color:transparent;}
.col-back-btn:hover{background:rgba(255,45,45,0.15);border-color:rgba(255,45,45,0.4);}
.col-detail-title{font-size:16px;font-weight:700;color:white;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.col-detail-count{font-size:12px;color:rgba(255,255,255,0.4);flex-shrink:0;}

/* Detail search bar */
.col-detail-search-wrap{position:relative;margin-bottom:12px;}
.col-detail-search-input{
    width:100%;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);
    border-radius:10px;padding:8px 14px 8px 34px;color:white;font-size:12px;
    font-family:Poppins,sans-serif;outline:none;transition:border-color 0.2s;box-sizing:border-box;
}
.col-detail-search-input:focus{border-color:rgba(255,45,45,0.4);background:rgba(255,255,255,0.07);}
.col-detail-search-input::placeholder{color:rgba(255,255,255,0.22);}
.col-detail-search-icon{position:absolute;left:10px;top:50%;transform:translateY(-50%);font-size:13px;color:rgba(255,255,255,0.28);pointer-events:none;}
.col-detail-search-clear{position:absolute;right:9px;top:50%;transform:translateY(-50%);font-size:12px;color:rgba(255,255,255,0.28);cursor:pointer;display:none;background:none;border:none;padding:0;}
.col-detail-search-clear:hover{color:rgba(255,255,255,0.7);}
.col-detail-no-results{grid-column:1/-1;text-align:center;padding:30px 20px;color:rgba(255,255,255,0.3);font-size:13px;display:none;}
.col-detail-no-results i{font-size:2rem;display:block;margin-bottom:8px;color:rgba(255,85,102,0.35);}

/* ══ ADD TO COLLECTION SHEET ══ */
#addToColModal{display:none;position:fixed;inset:0;z-index:999999;background:rgba(0,0,0,0.88);backdrop-filter:blur(8px);align-items:flex-end;justify-content:center;padding:0 16px;}
#addToColModal.open{display:flex;}
.atc-sheet{background:rgba(14,13,26,0.98);border:1px solid rgba(255,255,255,0.08);border-radius:22px 22px 18px 18px;width:100%;max-width:480px;max-height:75vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 -8px 40px rgba(0,0,0,0.6);animation:sheetUp 0.28s cubic-bezier(0.34,1.56,0.64,1);}
@keyframes sheetUp{from{transform:translateY(40px);opacity:0;}to{transform:translateY(0);opacity:1;}}
.atc-hdr{display:flex;align-items:center;justify-content:space-between;padding:16px 18px 12px;border-bottom:1px solid rgba(255,255,255,0.07);flex-shrink:0;}
.atc-title{font-size:14px;font-weight:700;color:white;}
.atc-close{width:28px;height:28px;border-radius:50%;background:rgba(255,255,255,0.07);border:none;color:rgba(255,255,255,0.5);font-size:14px;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:background 0.2s;}
.atc-close:hover{background:rgba(255,45,45,0.2);color:#f24455;}
.atc-list{overflow-y:auto;flex:1;padding:6px 0;}
.atc-list::-webkit-scrollbar{width:4px;}.atc-list::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.1);border-radius:4px;}
.atc-item{display:flex;align-items:center;gap:12px;padding:11px 18px;cursor:pointer;transition:background 0.15s;-webkit-tap-highlight-color:transparent;}
.atc-item:hover{background:rgba(255,255,255,0.05);}
.atc-item-thumb{width:44px;height:44px;border-radius:10px;background:rgba(255,255,255,0.06);overflow:hidden;flex-shrink:0;display:flex;align-items:center;justify-content:center;}
.atc-item-thumb img,.atc-item-thumb video{width:100%;height:100%;object-fit:cover;}
.atc-item-info{flex:1;min-width:0;}
.atc-item-name{font-size:13px;font-weight:600;color:white;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.atc-item-count{font-size:11px;color:rgba(255,255,255,0.35);margin-top:2px;}
.atc-check{width:24px;height:24px;border-radius:50%;border:1.5px solid rgba(255,255,255,0.2);background:transparent;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:all 0.2s;font-size:12px;color:transparent;}
.atc-check.on{background:rgba(255,45,45,0.85);border-color:transparent;color:white;font-weight:700;}
.atc-new-row{display:flex;align-items:center;gap:10px;padding:12px 18px;border-top:1px solid rgba(255,255,255,0.07);cursor:pointer;color:#FF5566;font-size:13px;font-weight:600;transition:background 0.15s;flex-shrink:0;-webkit-tap-highlight-color:transparent;}
.atc-new-row:hover{background:rgba(255,45,45,0.07);}
.atc-new-row i{font-size:18px;}

/* ══ FOLDER OPTS DROPDOWN ══ */
.col-opts-dd{position:fixed;background:rgba(14,13,26,0.97);backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,0.08);border-radius:14px;min-width:180px;z-index:9999999;box-shadow:0 12px 40px rgba(0,0,0,0.7);overflow:hidden;display:none;}
.col-opts-dd.open{display:block;animation:dropIn 0.18s ease forwards;}
@keyframes dropIn{from{opacity:0;transform:translateY(-6px) scale(0.97);}to{opacity:1;transform:translateY(0) scale(1);}}
.col-opts-item{display:flex;align-items:center;gap:10px;padding:11px 15px;color:rgba(255,255,255,0.8);font-size:13px;font-weight:500;cursor:pointer;transition:background 0.15s;border-bottom:1px solid rgba(255,255,255,0.05);-webkit-tap-highlight-color:transparent;}
.col-opts-item:last-child{border-bottom:none;}
.col-opts-item:hover{background:rgba(255,255,255,0.06);}
.col-opts-item i{font-size:15px;color:#f24455;}
.col-opts-item.danger{color:#f24455;}
.col-opts-item.vis-toggle i{color:#4dde8a;}
.col-opts-item.pin-toggle i{color:#ff5566;}


/* ══ ADD BTN ON CARD ══ */
.card-add-col-btn{position:absolute;bottom:8px;right:8px;width:32px;height:32px;border-radius:50%;background:rgba(0,0,0,0.65);backdrop-filter:blur(8px);border:1px solid rgba(255,255,255,0.18);color:white;font-size:15px;display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:5;transition:all 0.2s;-webkit-tap-highlight-color:transparent;}
.card-add-col-btn:hover{background:rgba(255,45,45,0.75);border-color:transparent;transform:scale(1.1);}

/* ══ PIN BTN ON CARD (inline beside download, no bg) ══ */
.card-pin-btn{
    background:none;border:none;color:rgba(255,255,255,0.4);
    font-size:16px;display:flex;align-items:center;justify-content:center;
    cursor:pointer;padding:0 2px;transition:color 0.2s;
    -webkit-tap-highlight-color:transparent;line-height:1;
}
.card-pin-btn:hover{color:rgba(255,85,102,0.85);}
.card-pin-btn.pinned{color:#ff2d2d;}

/* Pin icon ON the media box (top-right corner indicator) */
.card-pin-indicator{
    position:absolute;top:10px;right:50px;
    font-size:14px;color:#ff2d2d;
    pointer-events:none;z-index:6;
    filter:drop-shadow(0 1px 3px rgba(0,0,0,0.7));
}

/* ══ REMOVE BTN IN DETAIL ══ */
.card-remove-btn{position:absolute;bottom:8px;right:8px;width:32px;height:32px;border-radius:50%;background:rgba(180,0,0,0.75);backdrop-filter:blur(8px);border:none;color:white;font-size:15px;display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:5;transition:all 0.2s;-webkit-tap-highlight-color:transparent;}
.card-remove-btn:hover{background:rgba(255,0,0,0.95);transform:scale(1.1);}

/* ══ NAME MODAL ══ */
#colNameModal{display:none;position:fixed;inset:0;z-index:9999999;background:rgba(0,0,0,0.88);backdrop-filter:blur(8px);align-items:center;justify-content:center;padding:20px;}
#colNameModal.open{display:flex;}
.col-nm-box{background:rgba(14,13,26,0.98);border:1px solid rgba(255,255,255,0.08);border-radius:20px;padding:24px;width:100%;max-width:360px;box-shadow:0 24px 60px rgba(0,0,0,0.7);animation:sheetUp 0.25s ease forwards;}
.col-nm-box h3{font-size:16px;font-weight:700;color:white;margin:0 0 16px;}
.col-nm-input{width:100%;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);border-radius:12px;padding:11px 14px;color:white;font-size:14px;font-family:Poppins,sans-serif;outline:none;margin-bottom:14px;transition:border-color 0.2s;box-sizing:border-box;}
.col-nm-input:focus{border-color:rgba(255,45,45,0.45);}
.col-nm-input::placeholder{color:rgba(255,255,255,0.2);}
.col-vis-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;padding:10px 14px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.07);border-radius:12px;}
.col-vis-label{font-size:12px;font-weight:600;color:rgba(255,255,255,0.65);display:flex;align-items:center;gap:7px;}
.col-vis-label i{font-size:14px;}
.col-vis-toggle{position:relative;width:40px;height:22px;flex-shrink:0;}
.col-vis-toggle input{opacity:0;width:0;height:0;position:absolute;}
.col-vis-slider{position:absolute;inset:0;border-radius:22px;background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.12);cursor:pointer;transition:background 0.22s,border-color 0.22s;}
.col-vis-slider:before{content:'';position:absolute;width:16px;height:16px;border-radius:50%;background:white;top:2px;left:2px;transition:transform 0.22s;}
.col-vis-toggle input:checked + .col-vis-slider{background:rgba(77,222,138,0.75);border-color:rgba(77,222,138,0.6);}
.col-vis-toggle input:checked + .col-vis-slider:before{transform:translateX(18px);}
.col-nm-btns{display:flex;gap:10px;}
.col-nm-cancel{flex:1;padding:10px;border-radius:12px;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);color:rgba(255,255,255,0.55);font-size:13px;font-weight:600;cursor:pointer;font-family:Poppins,sans-serif;transition:background 0.2s;}
.col-nm-cancel:hover{background:rgba(255,255,255,0.1);}
.col-nm-save{flex:1;padding:10px;border-radius:12px;background:rgba(255,45,45,0.85);border:none;color:white;font-size:13px;font-weight:700;cursor:pointer;font-family:Poppins,sans-serif;transition:background 0.2s;}
.col-nm-save:hover{background:#ff1a1a;}

/* ══ TOAST ══ */
.g-toast{position:fixed;bottom:90px;left:50%;transform:translateX(-50%);background:rgba(14,14,30,0.97);border:1px solid rgba(255,45,45,0.3);color:#fff;padding:9px 24px;border-radius:50px;font-size:12px;font-family:Poppins,sans-serif;z-index:99999999;pointer-events:none;box-shadow:0 8px 24px rgba(0,0,0,0.6);white-space:nowrap;}
.g-toast-in{animation:toastIn 0.25s ease forwards;}
@keyframes toastIn{from{opacity:0;transform:translateX(-50%) translateY(8px);}to{opacity:1;transform:translateX(-50%) translateY(0);}}

/* ══ EMPTY COLLECTIONS for visitors ══ */
.col-visitor-empty{grid-column:1/-1;text-align:center;padding:40px 20px;color:rgba(255,255,255,0.3);font-size:13px;}
.col-visitor-empty i{font-size:2.5rem;display:block;margin-bottom:10px;color:rgba(255,85,102,0.4);}
.col-search-no-results{grid-column:1/-1;text-align:center;padding:30px 20px;color:rgba(255,255,255,0.3);font-size:13px;display:none;}
.col-search-no-results i{font-size:1.8rem;display:block;margin-bottom:8px;color:rgba(255,85,102,0.3);}


/* ══ RESPONSIVE ══ */
@media(max-width:1100px){.col-folders-grid{grid-template-columns:repeat(auto-fill,minmax(165px,1fr));}}
@media(max-width:768px){
    .grid-sort-bar{gap:8px;padding-bottom:14px;}
    .g-pill{padding:8px 15px;font-size:11px;}
    .col-folders-grid{grid-template-columns:repeat(auto-fill,minmax(145px,1fr));gap:12px;}
    .col-new-btn{min-height:145px;}
    .col-folder-opts{opacity:1;}
}
@media(max-width:560px){
    .col-folders-grid{grid-template-columns:repeat(2,1fr);gap:10px;}
    .col-folder-card{border-radius:20px;}
    .col-folder-info{padding:12px;}
    .col-folder-name{font-size:13px;}
    .col-new-btn{min-height:130px;border-radius:20px;}
}
@media(max-width:480px){
    .col-folders-grid{grid-template-columns:repeat(2,1fr);gap:8px;}
    .col-new-btn{min-height:120px;font-size:11px;}
    .col-folder-name{font-size:12px;}
    .col-detail-title{font-size:14px;}
    .g-pill{padding:6px 14px;font-size:11px;}
}
@media(max-width:380px){
    .g-pill{padding:5px 11px;font-size:10px;}
    .col-folders-grid{gap:8px;}
}
@media(max-width:768px){.col-back-btn{width:34px;height:34px;font-size:15px;}}
@media(max-width:480px){.col-back-btn{width:30px;height:30px;font-size:13px;}}
@media(max-width:360px){.col-back-btn{width:24px;height:24px;font-size:12px;}}
@media(max-width:768px){
    #addToColModal{padding:0 10px 0;}
    .atc-sheet{max-width:100%;max-height:82vh;border-radius:20px 20px 16px 16px;}
    .atc-hdr{padding:14px 16px 12px;}
    .atc-title{font-size:13px;}
    .atc-close{width:26px;height:26px;font-size:13px;}
    .atc-item{padding:10px 16px;gap:10px;}
    .atc-item-thumb{width:40px;height:40px;border-radius:9px;}
    .atc-item-name{font-size:12px;}
    .atc-item-count{font-size:10px;}
    .atc-check{width:22px;height:22px;font-size:11px;}
    .atc-new-row{padding:11px 16px;font-size:12px;}
    .atc-new-row i{font-size:16px;}
    .col-nm-box{max-width:340px;padding:20px;border-radius:18px;}
    .col-nm-box h3{font-size:15px;margin-bottom:14px;}
    .col-nm-input{padding:10px 13px;font-size:13px;border-radius:11px;}
    .col-nm-cancel,.col-nm-save{padding:9px;font-size:12px;border-radius:11px;}
}
@media(max-width:480px){
    #addToColModal{padding:0;align-items:flex-end;}
    .atc-sheet{width:100%;max-width:100%;max-height:88vh;border-radius:18px 18px 0 0;}
    .atc-hdr{padding:13px 14px 10px;}
    .atc-title{font-size:12px;}
    .atc-close{width:24px;height:24px;font-size:12px;}
    .atc-list{padding:4px 0;}
    .atc-item{padding:9px 14px;gap:9px;}
    .atc-item-thumb{width:36px;height:36px;border-radius:8px;}
    .atc-item-name{font-size:11px;}
    .atc-item-count{font-size:9px;}
    .atc-check{width:20px;height:20px;font-size:10px;}
    .atc-new-row{padding:10px 14px;font-size:11px;}
    .atc-new-row i{font-size:15px;}

    #colNameModal{padding:16px;}
    .col-nm-box{width:100%;max-width:100%;padding:18px;border-radius:16px;}
    .col-nm-box h3{font-size:14px;margin-bottom:12px;}
    .col-nm-input{padding:9px 12px;font-size:12px;border-radius:10px;margin-bottom:12px;}
    .col-nm-btns{gap:8px;}
    .col-nm-cancel,.col-nm-save{padding:8px;font-size:11px;border-radius:10px;}
}
@media(max-width:360px){
    .atc-sheet{max-height:90vh;}
    .atc-item{padding:8px 12px;}
    .atc-item-thumb{width:32px;height:32px;}
    .atc-item-name{font-size:10px;}
    .atc-item-count{font-size:8px;}
    .atc-check{width:18px;height:18px;font-size:9px;}
    .atc-new-row{font-size:10px;}
    .col-nm-box{padding:16px;}
    .col-nm-input{font-size:11px;}
    .col-opts-dd{bottom:16px!important;right:10px!important;width:calc(100vw - 20px)!important;border-radius:12px!important;}
}
@media(max-width:770px){
    .col-opts-dd{
        position:fixed!important;
        bottom:24px!important;
        left:50%!important;
        right:auto!important;
        top:auto!important;
        transform:translateX(-50%)!important;
        width:calc(100vw - 32px)!important;
        max-width:400px!important;
        min-width:unset;
        border-radius:16px!important;
        z-index:2147483647!important;
    }
    .col-opts-dd.open{
        animation:dropInCentered 0.18s ease forwards!important;
    }
    .col-opts-item{
        padding:15px 17px!important;
        font-size:13.5px!important;
        gap:11px!important;
    }
    .col-opts-item i{font-size:15px!important;}
}

@media(max-width:480px){
    .col-opts-dd{
        bottom:20px!important;
        width:calc(100vw - 24px)!important;
        max-width:360px!important;
        border-radius:14px!important;
    }
    .col-opts-item{
        padding:14px 16px!important;
        font-size:13px!important;
        gap:10px!important;
    }
    .col-opts-item i{font-size:14px!important;}
}

@media(max-width:360px){
    .col-opts-dd{
        bottom:16px!important;
        width:calc(100vw - 20px)!important;
        max-width:320px!important;
        border-radius:12px!important;
    }
    .col-opts-item{
        padding:12px 14px!important;
        font-size:12px!important;
        gap:9px!important;
    }
    .col-opts-item i{font-size:13px!important;}
}

@media(max-width:330px){
    .col-opts-dd{
        bottom:12px!important;
        width:calc(100vw - 16px)!important;
        max-width:none!important;
        border-radius:11px!important;
    }
    .col-opts-item{
        padding:11px 12px!important;
        font-size:11.5px!important;
        gap:8px!important;
    }
    .col-opts-item i{font-size:12.5px!important;}
}

@keyframes dropInCentered{
    from{opacity:0;transform:translateX(-50%) translateY(10px) scale(0.97);}
    to{opacity:1;transform:translateX(-50%) translateY(0) scale(1);}
}
/* ══ FIX: detail grid meme cards normal size ══ */
#memesColDetailGrid .meme-card .media-box,
#repostsColDetailGrid .meme-card .media-box,
#tagsColDetailGrid .meme-card .media-box,
#favsColDetailGrid .meme-card .media-box,
#uploadsColDetailGrid .meme-card .media-box,
#visitorColDetailGrid .meme-card .media-box {
    height:180px;overflow:hidden;
}
#memesColDetailGrid .meme-card .media-box img,
#memesColDetailGrid .meme-card .media-box video,
#repostsColDetailGrid .meme-card .media-box img,
#repostsColDetailGrid .meme-card .media-box video,
#tagsColDetailGrid .meme-card .media-box img,
#tagsColDetailGrid .meme-card .media-box video,
#favsColDetailGrid .meme-card .media-box img,
#favsColDetailGrid .meme-card .media-box video,
#uploadsColDetailGrid .meme-card .media-box img,
#uploadsColDetailGrid .meme-card .media-box video,
#visitorColDetailGrid .meme-card .media-box img,
#visitorColDetailGrid .meme-card .media-box video {
    width:100%;height:100%;object-fit:cover;display:block;
}
@keyframes spin{to{transform:rotate(360deg);}}
</style>



<?php
// ══════════════════════════════════════════════════════════
// BLOCK CHECKS
// ══════════════════════════════════════════════════════════
$is_blocked_by_owner = false;
if($current_user && $current_user != $profile_id) {
    $block_check = $conn->prepare("SELECT id FROM blocks WHERE blocker_id=? AND blocked_id=?");
    $block_check->bind_param("ii", $profile_id, $current_user);
    $block_check->execute();
    $block_check->store_result();
    $is_blocked_by_owner = $block_check->num_rows > 0;
    $block_check->close();
}

// ══════════════════════════════════════════════════════════
// PRIVATE PROFILE CHECK
// ══════════════════════════════════════════════════════════
$profile_is_private   = !empty($settings['is_fully_private']);
$viewer_is_approved   = false;
$wall_status          = null;

if($profile_is_private && $current_user && $current_user != $profile_id) {
    $apr = $conn->prepare("SELECT id FROM track_requests WHERE requester_id=? AND target_id=? AND status='approved'");
    $apr->bind_param("ii", $current_user, $profile_id);
    $apr->execute();
    $viewer_is_approved = $apr->get_result()->num_rows > 0;
    $apr->close();

    $wrs = $conn->prepare("SELECT status FROM track_requests WHERE requester_id=? AND target_id=?");
    $wrs->bind_param("ii", $current_user, $profile_id);
    $wrs->execute();
    $wrs_row     = $wrs->get_result()->fetch_assoc();
    $wall_status = $wrs_row ? $wrs_row['status'] : null;
    $wrs->close();
}

// Track request POST actions
if(isset($_POST['send_track_request']) && $current_user && $current_user != $profile_id) {
    $str = $conn->prepare("INSERT INTO track_requests (requester_id,target_id,status,created_at) VALUES (?,?,'pending',NOW()) ON DUPLICATE KEY UPDATE status='pending', created_at=NOW()");
    $str->bind_param("ii", $current_user, $profile_id);
    $str->execute(); $str->close();
    header("Location: profile.php?user_id=$profile_id"); exit;
}
if(isset($_POST['cancel_track_request']) && $current_user && $current_user != $profile_id) {
    $ctr = $conn->prepare("DELETE FROM track_requests WHERE requester_id=? AND target_id=? AND status='pending'");
    $ctr->bind_param("ii", $current_user, $profile_id);
    $ctr->execute(); $ctr->close();
    header("Location: profile.php?user_id=$profile_id"); exit;
}

$show_private_wall = (
    $profile_is_private &&
    $current_user != $profile_id &&
    !$viewer_is_approved
);

// ══════════════════════════════════════════════════════════
// PINNED POSTS — fetch for this profile owner
// ══════════════════════════════════════════════════════════
$pinned_meme_ids = [];
$pinned_memes_data = [];
if(!$show_private_wall && !$is_blocked_by_owner && !$is_blocked) {
    $pp = $conn->prepare("SELECT meme_id FROM pinned_posts WHERE user_id=? ORDER BY pinned_at DESC LIMIT 3");
    $pp->bind_param("i", $profile_id);
    $pp->execute();
    $pp_res = $pp->get_result();
    while($pp_row = $pp_res->fetch_assoc()) {
        $pinned_meme_ids[] = (int)$pp_row['meme_id'];
    }
    $pp->close();
}

// ══════════════════════════════════════════════════════════
// PINNED COLLECTIONS — fetch for this profile owner
// ══════════════════════════════════════════════════════════
$pinned_col_ids = [];
if(!$show_private_wall && !$is_blocked_by_owner && !$is_blocked) {
    $pc = $conn->prepare("SELECT collection_id FROM pinned_collections WHERE user_id=? ORDER BY pinned_at DESC LIMIT 3");
    $pc->bind_param("i", $profile_id);
    $pc->execute();
    $pc_res = $pc->get_result();
    while($pc_row = $pc_res->fetch_assoc()) {
        $pinned_col_ids[] = (int)$pc_row['collection_id'];
    }
    $pc->close();
}

// Collection count for this user (for the pill badge)
$col_count_row = $conn->query("SELECT COUNT(*) as c FROM collections WHERE user_id=$profile_id")->fetch_assoc();
$col_count_total = (int)($col_count_row['c'] ?? 0);

// Helper: format large numbers
function dezzFmt($n) {
    $n = (int)$n;
    if($n >= 1000000000) return round($n/1000000000,1).'b';
    if($n >= 1000000)    return round($n/1000000,1).'m';
    if($n >= 1000)       return round($n/1000,1).'k';
    return $n;
}

// ══════════════════════════════════════════════════════════
// BATCH DATA LOADING — replaces ~11-13 queries PER MEME CARD
// (nickname, follow check, social proof, media items, group
// info, audio info, 4x COUNT, 3x interaction check, plus a
// 5th per-row timestamp query inside the Reposts/Favorites/
// Likes loops) with a fixed ~17 queries for the ENTIRE page,
// no matter how many memes or tabs are being rendered.
//
// How it works: every tab's mysqli result is fetched into a
// plain PHP array up front (a mysqli_result can only be looped
// once anyway, so this doesn't cost anything extra). That gives
// us the full set of meme_ids + uploader_ids appearing anywhere
// on the page BEFORE any HTML is rendered. dezz_prime_caches()
// then runs one batched query per data type using
// `WHERE id IN (...)` / `GROUP BY meme_id`, and stores results
// in static-style caches. The get_*() helper functions below
// keep their original per-meme_id signatures (so none of the
// call sites in buildMemeCard() or the 5 tab loops had to
// change) but now just do an array lookup instead of a query.
//
// FIX: this batch ONLY covers meme/uploader IDs found across the
// 6 page-load tabs (Memes/Reposts/Tags/Favorites/Likes/Uploads).
// A Collection can reference a meme that was never in any of
// those tabs (e.g. saved from someone else's post you never
// liked/reposted/tagged/favorited/uploaded), so get_col_item_states
// was calling these get_*() helpers with meme/uploader IDs that
// were never primed — and getting back silent blank defaults
// (no nickname, no social proof, single-item media, no group,
// no audio, zero counts). $PRIMED_MEME_IDS / $PRIMED_UPLOADER_IDS
// let each helper tell "genuinely zero" apart from "never looked
// up", so it can run a one-off lazy-fill query instead of quietly
// returning empty data.
//
// AUDIO FIX: both the batch query and the lazy-fill query now
// also select a.id AS audio_id and ma.is_original, so every
// audio_info array carries a real audio_id + is_original flag
// through to the feed objects below — the same fields the ccm
// modal's audio pill now reads (p.audioId / p.audioIsOriginal)
// instead of guessing off media type.
// ══════════════════════════════════════════════════════════

function dezz_fetch_all($result) {
    $rows = [];
    if($result) { while($row = $result->fetch_assoc()) $rows[] = $row; }
    return $rows;
}

$_tabs_allowed = !$show_private_wall && !$is_blocked_by_owner && !$is_blocked;

// Materialize every tab's result set exactly once, up front.
$memes_rows   = $_tabs_allowed ? dezz_fetch_all($memes)        : [];
$reposts_rows = $_tabs_allowed ? dezz_fetch_all($reposts)      : [];
$tags_rows    = $_tabs_allowed ? dezz_fetch_all($tagged_posts) : [];
$favs_rows    = $_tabs_allowed ? dezz_fetch_all($favorites)    : [];
$likes_rows   = $_tabs_allowed ? dezz_fetch_all($liked_posts)  : [];
$uploads_rows = $_tabs_allowed ? dezz_fetch_all($my_uploads)   : [];

// Union of every meme_id / uploader_id across all 6 tabs.
$_all_meme_ids = [];
$_all_uploader_ids = [];
foreach([$memes_rows, $reposts_rows, $tags_rows, $favs_rows, $likes_rows, $uploads_rows] as $_set) {
    foreach($_set as $_r) {
        $_all_meme_ids[(int)$_r['id']] = true;
        if(isset($_r['uploaded_by'])) $_all_uploader_ids[(int)$_r['uploaded_by']] = true;
    }
}
$_all_meme_ids = array_keys($_all_meme_ids);
$_all_uploader_ids = array_keys($_all_uploader_ids);

// FIX (Chunk 1): flip into O(1) lookup maps so the get_*() helpers
// below can tell "this ID was in the page-load priming batch" apart
// from "this ID was never seen" (e.g. a Collections-only meme).
$PRIMED_MEME_IDS = array_flip($_all_meme_ids);
$PRIMED_UPLOADER_IDS = array_flip($_all_uploader_ids);

$NICK_CACHE = [];
$FOLLOW_CACHE = [];
$SOCIAL_CACHE = [];
$MEDIA_CACHE = [];
$GROUP_CACHE = [];
$AUDIO_CACHE = [];
$COUNT_CACHE = [];
$INTERACTION_CACHE = [];
$REPOST_TS_CACHE = [];
$FAV_TS_CACHE = [];
$LIKE_TS_CACHE = [];

function dezz_prime_caches($conn, $meme_ids, $uploader_ids, $current_user, $profile_id) {
    global $NICK_CACHE, $FOLLOW_CACHE, $SOCIAL_CACHE, $MEDIA_CACHE, $GROUP_CACHE,
           $AUDIO_CACHE, $COUNT_CACHE, $INTERACTION_CACHE, $REPOST_TS_CACHE,
           $FAV_TS_CACHE, $LIKE_TS_CACHE;

    // ── nicknames + follow state (batched by uploader_id) ──
    if(!empty($uploader_ids)) {
        $ids = implode(',', array_map('intval', $uploader_ids));

        $r = $conn->query("SELECT id, nickname FROM users WHERE id IN ($ids)");
        while($row = $r->fetch_assoc()) $NICK_CACHE[(int)$row['id']] = $row['nickname'];

        if($current_user) {
            $r = $conn->query("SELECT following_id FROM followers WHERE follower_id=".(int)$current_user." AND following_id IN ($ids)");
            while($row = $r->fetch_assoc()) $FOLLOW_CACHE[(int)$row['following_id']] = true;
        }
    }

    if(empty($meme_ids)) return;
    $mids = implode(',', array_map('intval', $meme_ids));

    // ── social proof: likes/reposts by people the viewer follows, per meme (batched) ──
    if($current_user) {
        $r = $conn->query("
            SELECT meme_id, id, username, profile_pic, action_type FROM (
                SELECT l.meme_id AS meme_id, u2.id, u2.username, u2.profile_pic, 'like' AS action_type
                FROM likes l JOIN users u2 ON u2.id = l.user_id
                JOIN followers f ON f.following_id = u2.id
                WHERE l.meme_id IN ($mids) AND f.follower_id = ".(int)$current_user." AND u2.id != ".(int)$current_user."
                UNION
                SELECT r.meme_id AS meme_id, u2.id, u2.username, u2.profile_pic, 'repost' AS action_type
                FROM reposts r JOIN users u2 ON u2.id = r.user_id
                JOIN followers f ON f.following_id = u2.id
                WHERE r.meme_id IN ($mids) AND f.follower_id = ".(int)$current_user." AND u2.id != ".(int)$current_user."
            ) sp
        ");

        // collect ALL rows per meme first — no 4-cap yet
        $_social_raw = [];
        while($row = $r->fetch_assoc()) {
            $mid = (int)$row['meme_id'];
            if(!isset($_social_raw[$mid])) $_social_raw[$mid] = [];
            $_social_raw[$mid][] = [
                'id' => $row['id'], 'username' => $row['username'],
                'profile_pic' => $row['profile_pic'], 'action_type' => $row['action_type']
            ];
        }

        // sort reposts to the front, THEN cap at 4
        foreach($_social_raw as $mid => $rows) {
            usort($rows, function($a, $b){
                $aRank = $a['action_type'] === 'repost' ? 0 : 1;
                $bRank = $b['action_type'] === 'repost' ? 0 : 1;
                return $aRank <=> $bRank;
            });
            $SOCIAL_CACHE[$mid] = array_slice($rows, 0, 4);
        }
    }

    // ── media items (batched, ordered so grouping-by-first-seen preserves position order) ──
    $r = $conn->query("SELECT meme_id, file_name, type FROM meme_media WHERE meme_id IN ($mids) ORDER BY meme_id, position ASC");
    while($row = $r->fetch_assoc()) {
        $mid = (int)$row['meme_id'];
        if(!isset($MEDIA_CACHE[$mid])) $MEDIA_CACHE[$mid] = [];
        $MEDIA_CACHE[$mid][] = ['file' => 'uploads/'.$row['file_name'], 'type' => $row['type']];
    }

    // ── group/pod info (batched, first match per meme) ──
    $r = $conn->query("
        SELECT gp.meme_id AS meme_id, gp.group_id AS group_id, g.title AS group_name
        FROM group_posts gp JOIN groups g ON g.id = gp.group_id
        WHERE gp.meme_id IN ($mids)
    ");
    while($row = $r->fetch_assoc()) {
        $mid = (int)$row['meme_id'];
        if(!isset($GROUP_CACHE[$mid])) $GROUP_CACHE[$mid] = ['group_id' => $row['group_id'], 'group_name' => $row['group_name']];
    }

    // ── audio info (batched, first match per meme) ──
    // NOW includes a.id AS audio_id and ma.is_original so the JS-side
    // audio pill can be driven by real data instead of guessing.
    $r = $conn->query("
        SELECT ma.meme_id AS meme_id, a.id AS audio_id, a.title, a.artist, a.file_name,
               ma.is_original, ma.music_volume, ma.video_audio_volume
        FROM meme_audio ma JOIN audios a ON a.id = ma.audio_id
        WHERE ma.meme_id IN ($mids)
    ");
    while($row = $r->fetch_assoc()) {
        $mid = (int)$row['meme_id'];
        if(!isset($AUDIO_CACHE[$mid])) $AUDIO_CACHE[$mid] = $row;
    }

    // ── like/repost/favorite/share counts (4 batched GROUP BY queries instead of 4 per card) ──
    foreach(['like' => 'likes', 'repost' => 'reposts', 'fav' => 'favorites', 'share' => 'shares'] as $key => $table) {
        $r = $conn->query("SELECT meme_id, COUNT(*) c FROM $table WHERE meme_id IN ($mids) GROUP BY meme_id");
        while($row = $r->fetch_assoc()) $COUNT_CACHE[(int)$row['meme_id']][$key] = (int)$row['c'];
    }

    // ── current viewer's liked/reposted/faved flags (3 batched queries instead of 3 per card) ──
    if($current_user) {
        foreach(['liked' => 'likes', 'reposted' => 'reposts', 'faved' => 'favorites'] as $key => $table) {
            $r = $conn->query("SELECT meme_id FROM $table WHERE meme_id IN ($mids) AND user_id=".(int)$current_user);
            while($row = $r->fetch_assoc()) $INTERACTION_CACHE[(int)$row['meme_id']][$key] = true;
        }
    }

    // ── interaction timestamps for the profile owner (drives sort order in
    //    Reposts/Favorites/Likes tabs). Previously one extra query PER ROW
    //    inside each of those 3 loops — now 3 queries total for the page. ──
    $r = $conn->query("SELECT meme_id, MAX(created_at) mx FROM reposts WHERE meme_id IN ($mids) AND user_id=".(int)$profile_id." GROUP BY meme_id");
    while($row = $r->fetch_assoc()) $REPOST_TS_CACHE[(int)$row['meme_id']] = $row['mx'];

    $r = $conn->query("SELECT meme_id, MAX(created_at) mx FROM favorites WHERE meme_id IN ($mids) AND user_id=".(int)$profile_id." GROUP BY meme_id");
    while($row = $r->fetch_assoc()) $FAV_TS_CACHE[(int)$row['meme_id']] = $row['mx'];

    $r = $conn->query("SELECT meme_id, MAX(created_at) mx FROM likes WHERE meme_id IN ($mids) AND user_id=".(int)$profile_id." GROUP BY meme_id");
    while($row = $r->fetch_assoc()) $LIKE_TS_CACHE[(int)$row['meme_id']] = $row['mx'];
}

dezz_prime_caches($conn, $_all_meme_ids, $_all_uploader_ids, $current_user, $profile_id);

/// ══════════════════════════════════════════════════════════
// get_poster_meta() — nickname, real follow state, social proof.
// FIX: self-healing — if $uploaded_by / $meme_id weren't part of
// the page-load priming batch (e.g. a Collections item never seen
// in the 6 tabs), run a one-off query instead of returning blank
// defaults, then remember it so we don't re-query the same ID twice.
// ══════════════════════════════════════════════════════════
function get_poster_meta($conn, $meme_id, $uploaded_by, $current_user) {
    global $NICK_CACHE, $FOLLOW_CACHE, $SOCIAL_CACHE, $PRIMED_UPLOADER_IDS, $PRIMED_MEME_IDS;
    $uploaded_by = (int)$uploaded_by;
    $meme_id = (int)$meme_id;

    if(!isset($PRIMED_UPLOADER_IDS[$uploaded_by])){
        $r = $conn->query("SELECT nickname FROM users WHERE id=$uploaded_by LIMIT 1");
        $row = $r ? $r->fetch_assoc() : null;
        $NICK_CACHE[$uploaded_by] = $row ? $row['nickname'] : '';

        if($current_user){
            $chk = $conn->query("SELECT id FROM followers WHERE follower_id=".(int)$current_user." AND following_id=$uploaded_by LIMIT 1");
            if($chk && $chk->num_rows > 0) $FOLLOW_CACHE[$uploaded_by] = true;
        }
        $PRIMED_UPLOADER_IDS[$uploaded_by] = true;
    }

    if(!isset($PRIMED_MEME_IDS[$meme_id]) && !array_key_exists($meme_id, $SOCIAL_CACHE)){
        $sp = [];
        if($current_user){
            $r = $conn->query("
                SELECT id, username, profile_pic, action_type FROM (
                    SELECT u2.id, u2.username, u2.profile_pic, 'like' AS action_type
                    FROM likes l JOIN users u2 ON u2.id = l.user_id
                    JOIN followers f ON f.following_id = u2.id
                    WHERE l.meme_id=$meme_id AND f.follower_id=".(int)$current_user." AND u2.id != ".(int)$current_user."
                    UNION
                    SELECT u2.id, u2.username, u2.profile_pic, 'repost' AS action_type
                    FROM reposts r JOIN users u2 ON u2.id = r.user_id
                    JOIN followers f ON f.following_id = u2.id
                    WHERE r.meme_id=$meme_id AND f.follower_id=".(int)$current_user." AND u2.id != ".(int)$current_user."
                ) sp
            ");
            if($r){ while($row = $r->fetch_assoc()) $sp[] = $row; }

            usort($sp, function($a, $b){
                $aRank = $a['action_type'] === 'repost' ? 0 : 1;
                $bRank = $b['action_type'] === 'repost' ? 0 : 1;
                return $aRank <=> $bRank;
            });
            $sp = array_slice($sp, 0, 4);
        }
        $SOCIAL_CACHE[$meme_id] = $sp;
    }

    return [
        'nickname'     => $NICK_CACHE[$uploaded_by] ?? '',
        'is_following' => $current_user ? !empty($FOLLOW_CACHE[$uploaded_by]) : false,
        'social_proof' => $SOCIAL_CACHE[$meme_id] ?? [],
    ];
}

// ══════════════════════════════════════════════════════════
// get_meme_group_info() — resolves which group/pod (if any)
// a meme was posted into. FIX: lazy-fills via a one-off query
// if $meme_id wasn't in the priming batch.
// ══════════════════════════════════════════════════════════
function get_meme_group_info($conn, $meme_id) {
    global $GROUP_CACHE, $PRIMED_MEME_IDS;
    $meme_id = (int)$meme_id;
    if(!isset($PRIMED_MEME_IDS[$meme_id]) && !array_key_exists($meme_id, $GROUP_CACHE)){
        $r = $conn->query("
            SELECT gp.group_id AS group_id, g.title AS group_name
            FROM group_posts gp JOIN groups g ON g.id = gp.group_id
            WHERE gp.meme_id=$meme_id LIMIT 1
        ");
        $row = $r ? $r->fetch_assoc() : null;
        $GROUP_CACHE[$meme_id] = $row ? ['group_id'=>$row['group_id'],'group_name'=>$row['group_name']] : null;
    }
    return $GROUP_CACHE[$meme_id] ?? null;
}

// ══════════════════════════════════════════════════════════
// get_meme_audio_info() — resolves the attached soundtrack (if
// any) for a meme. FIX: lazy-fills via a one-off query if
// $meme_id wasn't in the priming batch. Now also returns
// audio_id + is_original alongside title/artist/file_name.
// ══════════════════════════════════════════════════════════
function get_meme_audio_info($conn, $meme_id) {
    global $AUDIO_CACHE, $PRIMED_MEME_IDS;
    $meme_id = (int)$meme_id;
    if(!isset($PRIMED_MEME_IDS[$meme_id]) && !array_key_exists($meme_id, $AUDIO_CACHE)){
        $r = $conn->query("
            SELECT a.id AS audio_id, a.title, a.artist, a.file_name,
                   ma.is_original, ma.music_volume, ma.video_audio_volume
            FROM meme_audio ma JOIN audios a ON a.id = ma.audio_id
            WHERE ma.meme_id=$meme_id LIMIT 1
        ");
        $AUDIO_CACHE[$meme_id] = $r ? $r->fetch_assoc() : null;
    }
    return $AUDIO_CACHE[$meme_id] ?? null;
}

// ══════════════════════════════════════════════════════════
// get_meme_media_items() — builds the full ordered media
// array (main item + any extra rows from meme_media) for a
// single Zeem. FIX: lazy-fills the extra-media cache via a
// one-off query if $meme_id wasn't in the priming batch.
// ══════════════════════════════════════════════════════════
function get_meme_media_items($conn, $meme_id, $main_smf, $main_type) {
    global $MEDIA_CACHE, $PRIMED_MEME_IDS;
    $meme_id = (int)$meme_id;
    if(!isset($PRIMED_MEME_IDS[$meme_id]) && !array_key_exists($meme_id, $MEDIA_CACHE)){
        $extra = [];
        $r = $conn->query("SELECT file_name, type FROM meme_media WHERE meme_id=$meme_id ORDER BY position ASC");
        if($r){ while($row = $r->fetch_assoc()) $extra[] = ['file'=>'uploads/'.$row['file_name'], 'type'=>$row['type']]; }
        $MEDIA_CACHE[$meme_id] = $extra;
    }
    $media = [['file' => $main_smf, 'type' => $main_type]];
    if(!empty($MEDIA_CACHE[$meme_id])) $media = array_merge($media, $MEDIA_CACHE[$meme_id]);
    return $media;
}

// ══════════════════════════════════════════════════════════
// get_meme_counts() — like/repost/fav/share counts. FIX:
// lazy-fills via one-off COUNT(*) queries if $meme_id wasn't
// in the priming batch, instead of silently returning zeros
// (this is why Collections items always showed 0 shares).
// ══════════════════════════════════════════════════════════
function get_meme_counts($meme_id) {
    global $COUNT_CACHE, $PRIMED_MEME_IDS, $conn;
    $meme_id = (int)$meme_id;
    $defaults = ['like' => 0, 'repost' => 0, 'fav' => 0, 'share' => 0];
    if(!isset($PRIMED_MEME_IDS[$meme_id]) && !array_key_exists($meme_id, $COUNT_CACHE)){
        $counts = [];
        foreach(['like'=>'likes','repost'=>'reposts','fav'=>'favorites','share'=>'shares'] as $key=>$table){
            $r = $conn->query("SELECT COUNT(*) c FROM $table WHERE meme_id=$meme_id");
            $row = $r ? $r->fetch_assoc() : null;
            $counts[$key] = $row ? (int)$row['c'] : 0;
        }
        $COUNT_CACHE[$meme_id] = $counts;
    }
    return ($COUNT_CACHE[$meme_id] ?? []) + $defaults;
}

// ══════════════════════════════════════════════════════════
// get_meme_interactions() — did the current viewer like/repost/
// favorite this meme. FIX: lazy-fills via one-off lookups if
// $meme_id wasn't in the priming batch.
// ══════════════════════════════════════════════════════════
function get_meme_interactions($meme_id, $current_user) {
    global $INTERACTION_CACHE, $PRIMED_MEME_IDS, $conn;
    if(!$current_user) return ['liked' => false, 'reposted' => false, 'faved' => false];
    $meme_id = (int)$meme_id;
    if(!isset($PRIMED_MEME_IDS[$meme_id])){
        $c = [];
        foreach(['liked'=>'likes','reposted'=>'reposts','faved'=>'favorites'] as $key=>$table){
            $r = $conn->query("SELECT id FROM $table WHERE meme_id=$meme_id AND user_id=".(int)$current_user." LIMIT 1");
            if($r && $r->num_rows > 0) $c[$key] = true;
        }
        $INTERACTION_CACHE[$meme_id] = $c;
    }
    $c = $INTERACTION_CACHE[$meme_id] ?? [];
    return [
        'liked'    => !empty($c['liked']),
        'reposted' => !empty($c['reposted']),
        'faved'    => !empty($c['faved']),
    ];
}
?>

<?php if($show_private_wall): ?>
<!-- ══ PRIVATE WALL ══ -->
<div id="memes" class="dropdown-section" style="display:grid;">
    <div style="grid-column:1/-1;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:40px 20px 70px;text-align:center;gap:16px;">
        <div style="width:80px;height:80px;border-radius:50%;background:rgba(255,45,45,0.08);border:1.5px solid rgba(255,45,45,0.2);display:flex;align-items:center;justify-content:center;">
            <i class="ri-lock-line" style="font-size:34px;color:rgba(255,76,76,0.75);"></i>
        </div>
        <div style="font-size:18px;font-weight:700;color:white;letter-spacing:-0.2px;">This profile is private</div>
        <div style="font-size:13px;color:rgba(255,255,255,0.4);max-width:270px;line-height:1.65;">
            <?php if($wall_status === 'pending'): ?>
                Your track request is pending. The owner will approve or deny it.
            <?php elseif($wall_status === 'denied'): ?>
                Your previous request was not approved. You can send a new one.
            <?php else: ?>
                Send a track request to see their memes and posts.
            <?php endif; ?>
        </div>
        <?php if(!$current_user): ?>
            <a href="login.php" style="padding:11px 28px;border-radius:999px;background:rgba(255,45,45,0.85);color:white;font-size:13px;font-weight:700;text-decoration:none;font-family:Poppins,sans-serif;">Login to Request</a>
        <?php elseif($wall_status === 'pending'): ?>
            <form method="POST" style="margin:0;">
                <button name="cancel_track_request" style="padding:9px 24px;border-radius:999px;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.14);color:rgba(255,255,255,0.45);font-size:12px;font-weight:600;font-family:Poppins,sans-serif;cursor:pointer;display:flex;align-items:center;gap:6px;">
                    <i class="ri-time-line" style="font-size:13px;"></i> Requested · Cancel
                </button>
            </form>
        <?php else: ?>
            <form method="POST" style="margin:0;">
                <button name="send_track_request" style="padding:11px 28px;border-radius:999px;background:rgba(255,45,45,0.85);border:none;color:white;font-size:13px;font-weight:700;font-family:Poppins,sans-serif;cursor:pointer;display:flex;align-items:center;gap:7px;transition:background 0.2s;"
                    onmouseover="this.style.background='#ff1a1a'" onmouseout="this.style.background='rgba(255,45,45,0.85)'">
                    <i class="ri-user-add-line" style="font-size:14px;"></i>
                    <?= $wall_status === 'denied' ? 'Re-request Track' : 'Request Track' ?>
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php elseif($is_blocked_by_owner): ?>
<!-- ══ BLOCKED BY OWNER ══ -->
<div id="memes" class="dropdown-section" style="display:grid;">
    <div style="grid-column:1/-1;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:20px 20px 60px;text-align:center;gap:14px;">
        <div style="width:72px;height:72px;border-radius:50%;background:rgba(255,45,45,0.08);border:1.5px solid rgba(255,45,45,0.2);display:flex;align-items:center;justify-content:center;margin-bottom:4px;">
            <i class="ri-lock-line" style="font-size:32px;color:rgba(255,76,76,0.7);"></i>
        </div>
        <div style="font-size:17px;font-weight:700;color:white;letter-spacing:-0.2px;">You've been blocked</div>
        <div style="font-size:13px;color:rgba(255,255,255,0.38);max-width:260px;line-height:1.6;">This user has blocked you. You can't view their profile content.</div>
    </div>
</div>

<?php elseif($is_blocked): ?>
<!-- ══ VIEWER HAS BLOCKED OWNER ══ -->
<div id="memes" class="dropdown-section" style="display:grid;">
    <div style="grid-column:1/-1;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:20px 20px 60px;text-align:center;gap:14px;">
        <div style="width:72px;height:72px;border-radius:50%;background:rgba(255,255,255,0.05);border:1.5px solid rgba(255,255,255,0.1);display:flex;align-items:center;justify-content:center;margin-bottom:4px;">
            <i class="ri-user-forbid-line" style="font-size:32px;color:rgba(255,255,255,0.3);"></i>
        </div>
        <div style="font-size:17px;font-weight:700;color:white;letter-spacing:-0.2px;">You have blocked <?= htmlspecialchars($user['username'] ?? 'this user') ?></div>
        <div style="font-size:13px;color:rgba(255,255,255,0.38);max-width:280px;line-height:1.6;">Unblock them from their profile to view their content again.</div>
    </div>
</div>

<?php else: ?>

<?php
// ── Helper: build a meme card HTML ──────────────────────────────────────────
// NEW: $context is an optional label ('Reposted'|'Saved'|'Tagged'|'Liked'|'Uploads')
// shown as a left-side tag in the ccm modal so the viewer knows why this
// Zeem is showing up in that particular dropdown.
function buildMemeCard($row, $conn, $current_user, $profile_id, $pinned_meme_ids, $feedVarName, $diff_override = null, $context = '') {
    $created = strtotime($row['created_at']);
    $now = time();
    $diff = $diff_override !== null ? $diff_override : ($now - $created);
    $is_new    = ($diff <= 86400);
    $is_fading = ($diff > 86400 && $diff <= 172800);
    $sc  = addslashes(htmlspecialchars($row['caption']));
    $su  = addslashes(htmlspecialchars($row['username'] ?? ''));
    $sp  = addslashes($row['uploader_pic'] ?? 'uploads/default.jpg');
    $sf  = addslashes($row['file_name'] ?? '');
    $st  = addslashes($row['created_at']);
    $iy  = ($current_user == $row['uploaded_by']) ? 'true' : 'false';
    $mf  = ($row['type'] === 'text' || empty($row['file_name']) || $row['file_name'] === '0') ? 'uploads/Text.jpg' : 'uploads/' . $row['file_name'];
    $smf = addslashes($mf);
    $mid = (int)$row['id'];
    $isPinned = in_array($mid, $pinned_meme_ids);
    $isOwner  = ($current_user == $profile_id);

    // nickname / real follow state / social proof for this poster+meme
    $_meta = get_poster_meta($conn, $mid, $row['uploaded_by'], $current_user);
    $smeta_nick = addslashes($_meta['nickname']);

    // full ordered media array for the Zeem gallery
    $_norm_type   = ($row['type'] === 'text' || empty($row['file_name']) || $row['file_name'] === '0') ? 'text' : $row['type'];
    $_media_items = get_meme_media_items($conn, $mid, $smf, $_norm_type);
    $s_context    = addslashes($context);

    // group/pod info for this meme (drives the "From <Group>" tag in ccm modal)
    $_group      = get_meme_group_info($conn, $mid);
    $_group_id   = $_group ? (int)$_group['group_id'] : null;
    $_group_name = $_group ? addslashes($_group['group_name']) : null;

    // Zeem Connect fields — drives the "▶ View · Connected Zeem" overlay
    // button in the ccm/ipv modals.
    $_connected_id    = !empty($row['connected_meme_id']) ? (int)$row['connected_meme_id'] : null;
    $_connected_label = !empty($row['connected_label']) ? addslashes($row['connected_label']) : null;

    // attached soundtrack info for this meme
    $_audio             = get_meme_audio_info($conn, $mid);
    $_audio_id          = $_audio ? (int)$_audio['audio_id'] : null;
    $_audio_is_original = $_audio ? (int)($_audio['is_original'] ?? 0) : 0;
    $_audio_file        = $_audio ? addslashes('uploads/'.$_audio['file_name']) : null;
    $_audio_title       = $_audio ? addslashes($_audio['title']) : null;
    $_audio_artist      = ($_audio && !empty($_audio['artist'])) ? addslashes($_audio['artist']) : null;
    $_music_vol         = ($_audio && $_audio['music_volume'] !== '' && $_audio['music_volume'] !== null) ? (int)$_audio['music_volume'] : 80;
    $_vid_vol           = ($_audio && $_audio['video_audio_volume'] !== '' && $_audio['video_audio_volume'] !== null) ? (int)$_audio['video_audio_volume'] : 40;

    // counts + current-viewer interaction flags — now pulled from the
    // page-level batch cache instead of 7 live queries per card.
    $_counts = get_meme_counts($mid);
    $plc = $_counts['like']; $prc = $_counts['repost']; $pfc = $_counts['fav']; $psc = $_counts['share'];
    $_inter = get_meme_interactions($mid, $current_user);
    $pli = $_inter['liked']; $pri = $_inter['reposted']; $pfi = $_inter['faved'];

    $pinIndicator = $isPinned ? '<div class="card-pin-indicator"><i class="ri-pushpin-fill"></i></div>' : '';

    // Pin button for icon-bar (only owner sees it)
    $pinBtn = '';
    if($isOwner) {
        $pinnedClass = $isPinned ? ' pinned' : '';
        $pinTitle = $isPinned ? 'Unpin from profile' : 'Pin to profile';
        $pinBtn = '<button class="card-pin-btn'.$pinnedClass.'" data-meme-id="'.$mid.'" title="'.$pinTitle.'" onclick="event.stopPropagation();gTogglePin('.$mid.',this)"><i class="ri-pushpin-'.($isPinned?'fill':'line').'"></i></button>';
    }

        // Add-to-collection btn
    $addColBtn = '';
    if($current_user) {
        $addColBtn = '<button class="card-add-col-btn" title="Add to Collection" onclick="event.stopPropagation();gOpenAddToCol('.$mid.')"><i class="ri-folder-add-line"></i></button>';
    }

    ob_start();
    ?>    
    
    <script><?=$feedVarName?>.push({
    id:<?=$mid?>, file:'<?=$smf?>', type:'<?=addslashes($row['type'])?>',
    pic:'<?=$sp?>', userId:<?=(int)$row['uploaded_by']?>, username:'<?=$su?>',
    caption:'<?=$sc?>', time:'<?=$st?>', isYou:<?=$iy?>,
    likeCount:<?=(int)$plc?>, repostCount:<?=(int)$prc?>, favCount:<?=(int)$pfc?>, shareCount:<?=(int)$psc?>,
    liked:<?=$pli?'true':'false'?>, reposted:<?=$pri?'true':'false'?>, faved:<?=$pfi?'true':'false'?>,
    isPinned:<?=$isPinned?'true':'false'?>,
    nickname:'<?=$smeta_nick?>',
    isFollowing:<?=$_meta['is_following']?'true':'false'?>,
    socialProof:<?=json_encode($_meta['social_proof'])?>,
    media:<?=json_encode($_media_items)?>,
    context:'<?=$s_context?>',
    groupId:<?= $_group_id !== null ? $_group_id : 'null' ?>,
    groupTitle:<?= $_group_name !== null ? "'".$_group_name."'" : 'null' ?>,
    connectedMemeId:<?= $_connected_id !== null ? $_connected_id : 'null' ?>,
    connectedLabel:<?= $_connected_label !== null ? "'".$_connected_label."'" : 'null' ?>,
    audioId:<?= $_audio_id !== null ? $_audio_id : 'null' ?>,
    audioIsOriginal:<?= $_audio_is_original ? 'true' : 'false' ?>,
    audioFile:<?= $_audio_file !== null ? "'".$_audio_file."'" : 'null' ?>,
    audioTitle:<?= $_audio_title !== null ? "'".$_audio_title."'" : 'null' ?>,
    audioArtist:<?= $_audio_artist !== null ? "'".$_audio_artist."'" : 'null' ?>,
    musicVolume:<?=$_music_vol?>,
    videoAudioVolume:<?=$_vid_vol?>,
    isPartnership:<?= !empty($row['is_partnership']) ? 'true' : 'false' ?>,
    partnerBrand:<?= !empty($row['partner_brand']) ? "'".addslashes($row['partner_brand'])."'" : "''" ?>,
    isAd:<?= !empty($row['is_ad']) ? 'true' : 'false' ?>,
    adCtaText:<?= !empty($row['ad_cta_text']) ? "'".addslashes($row['ad_cta_text'])."'" : 'null' ?>,
    adCtaUrl:<?= !empty($row['ad_cta_url']) ? "'".addslashes($row['ad_cta_url'])."'" : 'null' ?>,
    adCtaColor:<?= !empty($row['ad_cta_color']) ? "'".addslashes($row['ad_cta_color'])."'" : 'null' ?>,
    isAiGenerated:<?= !empty($row['is_ai_generated']) ? 'true' : 'false' ?>
});</script>
    <div class="meme-card <?=$is_new?'is-new':''?> <?=$is_fading?'is-fading':''?>"
         data-id="<?=$mid?>"
         data-ts="<?=(int)$created?>"
         data-pinned="<?=$isPinned?'1':'0'?>">
        <div class="top-bar">
            <img src="<?=$row['uploader_pic'] ?? 'uploads/default.jpg'?>" loading="lazy" decoding="async"
                 onclick="window.location.href='profile.php?user_id=<?=$row['uploaded_by']?>'">
            <div class="username" onclick="window.location.href='profile.php?user_id=<?=$row['uploaded_by']?>'">
                <?= ($current_user == $row['uploaded_by']) ? 'You' : htmlspecialchars($row['username'] ?? '') ?>
            </div>
        </div>
        <div class="media-box" style="position:relative;">
            <?php if($row['type'] == "image"): ?>
                <img src="uploads/<?=$sf?>" loading="lazy" decoding="async" onclick="openMemeModal('<?=$smf?>','<?=$row['type']?>','<?=$sc?>','<?=$sp?>','<?=$su?>',<?=$row['uploaded_by']?>,'<?=$feedVarName?>',<?=$mid?>)">
            <?php elseif($row['type'] == "video"): ?>
                <video class="lazy-video" data-src="uploads/<?=$sf?>" muted loop playsinline preload="none" onclick="openMemeModal('<?=$smf?>','<?=$row['type']?>','<?=$sc?>','<?=$sp?>','<?=$su?>',<?=$row['uploaded_by']?>,'<?=$feedVarName?>',<?=$mid?>)"></video>
            <?php else: ?>
                <img src="uploads/Text.jpg" loading="lazy" decoding="async" onclick="openMemeModal('uploads/Text.jpg','image','<?=$sc?>','<?=$sp?>','<?=$su?>',<?=$row['uploaded_by']?>,'<?=$feedVarName?>',<?=$mid?>)">
            <?php endif; ?>
            <?php if($diff <= 172800): ?><div class="new-badge">NEW</div><?php endif; ?>
            <?=$pinIndicator?>
            <?=$addColBtn?>
        </div>
        <div class="caption">
            <i class="ri-home-5-line" style="color:#FF4C4C;"></i><?= htmlspecialchars($row['caption']) ?>
        </div>
        <div class="icon-bar">
            <a href="download.php?file=<?= urlencode($row['file_name'] ?? '') ?>">
                <i class="ri-download-line"></i>
            </a>
            <?=$pinBtn?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
?>

<!-- ══ NORMAL MEMES GRID ══ -->
<div id="memes" class="dropdown-section" style="display:grid;">
    <div class="grid-sort-bar" id="memesSortBar">
        <button class="g-pill active" id="memesN" onclick="gSort('memesGrid','newest','memes')"><i class="ri-time-line" style="margin-right:5px;font-size:11px;"></i>Newest</button>
        <button class="g-pill" id="memesO" onclick="gSort('memesGrid','oldest','memes')"><i class="ri-history-line" style="margin-right:5px;font-size:11px;"></i>Oldest</button>
        <div class="g-pill-div"></div>
        <button class="g-pill" id="memesC" onclick="gOpenCol('memes')"><i class="ri-folder-3-line" style="margin-right:5px;font-size:11px;"></i>Collections<span class="g-pill-count"><?= $col_count_total > 0 ? dezzFmt($col_count_total) : '' ?></span></button>
    </div>
    <div class="grid" id="memesGrid">
        <?php if(empty($memes_rows)): ?>
        <div style="grid-column:1/-1;text-align:center;padding:40px 20px;color:#aaa;font-size:0.95rem;">
            <i class="bi bi-grid-3x3-gap-fill" style="font-size:2.5rem;display:block;margin-bottom:10px;color:#FF4C4C;"></i>
            <div style="color:#fff;font-weight:600;font-size:1rem;">No memes yet</div>
            <style>
                .memes-empty-sub{
                    color:#aaa;
                    font-size:0.8rem;
                    margin-top:6px;
                    max-width:320px;
                    margin-left:auto;
                    margin-right:auto;
                }
                .memes-upload-btn{
                    display:block;
                    width:280px;
                    max-width:90%;
                    margin:18px auto 0;
                    padding:11px 20px;
                    background:#C4161C;
                    color:#fff;
                    font-weight:700;
                    font-size:0.85rem;
                    border-radius:6px;
                    text-decoration:none;
                    letter-spacing:.3px;
                    text-align:center;
                    transition:background .15s ease,transform .15s ease;
                }
                .memes-upload-btn:hover{
                    background:#A31216;
                    transform:translateY(-1px);
                }
                @media (max-width:790px){
                    .memes-empty-sub{font-size:0.75rem;max-width:280px;}
                    .memes-upload-btn{width:250px;font-size:0.78rem;padding:10px 18px;}
                }
                @media (max-width:490px){
                    .memes-empty-sub{font-size:0.7rem;max-width:250px;}
                    .memes-upload-btn{width:220px;font-size:0.72rem;padding:9px 16px;}
                }
                @media (max-width:390px){
                    .memes-empty-sub{font-size:0.66rem;max-width:220px;}
                    .memes-upload-btn{width:190px;font-size:0.66rem;padding:8px 14px;border-radius:5px;}
                }
            </style>
        </div>
        <?php else: ?>
        <script>var profileFeed = [];</script>
        <?php
        // Separate pinned and normal rows
        $all_meme_rows = $memes_rows;

        // Sort: pinned first (by pin order), then rest by newest
        $pinned_rows = [];
        $normal_rows = [];
        foreach($all_meme_rows as $row) {
            if(in_array((int)$row['id'], $pinned_meme_ids)) {
                $pinned_rows[(int)$row['id']] = $row;
            } else {
                $normal_rows[] = $row;
            }
        }
        // Order pinned rows by the pin order array
        $ordered_pinned = [];
        foreach($pinned_meme_ids as $pid) {
            if(isset($pinned_rows[$pid])) $ordered_pinned[] = $pinned_rows[$pid];
        }
        $sorted_rows = array_merge($ordered_pinned, $normal_rows);

        foreach($sorted_rows as $row):
            echo buildMemeCard($row, $conn, $current_user, $profile_id, $pinned_meme_ids, 'profileFeed');
        endforeach;
        ?>
        <?php endif; ?>
    </div>

    <div class="col-section" id="memesColSec">
        <div id="memesColFolders">
            <div class="col-search-wrap" id="memesColSearchWrap">
                <i class="ri-search-line col-search-icon"></i>
                <input type="text" class="col-search-input" id="memesColSearch" placeholder="Search collections…" oninput="gSearchFolders('memes',this.value)" autocomplete="off">
                <button class="col-search-clear" id="memesColSearchClear" onclick="gClearFolderSearch('memes')">✕</button>
            </div>
            <div class="col-folders-grid" id="memesColGrid">
                <?php if($current_user == $profile_id): ?>
                <button class="col-new-btn" onclick="gOpenNameModal('create','memes')">
                    <i class="ri-folder-add-line"></i>New Collection
                </button>
                <?php endif; ?>
            </div>
            <div class="col-search-no-results" id="memesColNoResults">
                <i class="ri-folder-search-line"></i>No collections match
            </div>
        </div>
        <div class="col-detail" id="memesColDetail">
            <div class="col-detail-hdr">
                <button class="col-back-btn" onclick="gCloseDetail('memes')"><i class="ri-arrow-left-line"></i></button>
                <div class="col-detail-title" id="memesColTitle">Collection</div>
                <div class="col-detail-count" id="memesColCount">0 items</div>
            </div>
            <div class="col-detail-search-wrap">
                <i class="ri-hashtag col-detail-search-icon"></i>
                <input type="text" class="col-detail-search-input" id="memesDetailSearch" placeholder="Search by caption or #hashtag…" oninput="gSearchDetailItems('memesColDetailGrid','memesDetailSearch','memesDetailNoResults')" autocomplete="off">
                <button class="col-detail-search-clear" id="memesDetailSearchClear" onclick="gClearDetailSearch('memesColDetailGrid','memesDetailSearch','memesDetailNoResults','memesDetailSearchClear')">✕</button>
            </div>
            <div class="col-detail-no-results" id="memesDetailNoResults">
                <i class="ri-search-eye-line"></i>No memes found
            </div>
            <div class="grid" id="memesColDetailGrid"></div>
        </div>
    </div>
</div>



<!-- ══════════ REPOSTS ══════════ -->
<div id="reposts" class="dropdown-section" style="display:none;">
    <div class="grid-sort-bar" id="repostsSortBar">
        <button class="g-pill active" id="repostsN" onclick="gSort('repostsGrid','newest','reposts')"><i class="ri-time-line" style="margin-right:5px;font-size:11px;"></i>Newest</button>
        <button class="g-pill" id="repostsO" onclick="gSort('repostsGrid','oldest','reposts')"><i class="ri-history-line" style="margin-right:5px;font-size:11px;"></i>Oldest</button>
        <div class="g-pill-div"></div>
        <button class="g-pill" id="repostsC" onclick="gOpenCol('reposts')"><i class="ri-folder-3-line" style="margin-right:5px;font-size:11px;"></i>Collections<span class="g-pill-count"><?= $col_count_total > 0 ? dezzFmt($col_count_total) : '' ?></span></button>
    </div>
    <div class="grid" id="repostsGrid">
    <?php if(empty($reposts_rows)): ?>
        <div style="grid-column:1/-1;text-align:center;padding:40px 20px;color:#aaa;font-size:0.95rem;"><i class="ri-repeat-line" style="font-size:2.5rem;display:block;margin-bottom:10px;color:#FF4C4C;"></i>No reposts yet</div>
    <?php else: ?>
    <script>var repostFeed=[];</script>
    <?php
    $all_repost_rows = $reposts_rows;
    $pinned_repost = [];
    $normal_repost = [];
    foreach($all_repost_rows as $row) {
        if(in_array((int)$row['id'], $pinned_meme_ids)) $pinned_repost[(int)$row['id']] = $row;
        else $normal_repost[] = $row;
    }
    $ordered_repost_pinned = [];
    foreach($pinned_meme_ids as $pid) { if(isset($pinned_repost[$pid])) $ordered_repost_pinned[] = $pinned_repost[$pid]; }
    $sorted_reposts = array_merge($ordered_repost_pinned, $normal_repost);

    foreach($sorted_reposts as $row):
        // interaction timestamp now comes from the batched REPOST_TS_CACHE
        // primed above instead of one query per row.
        global $REPOST_TS_CACHE;
        $mid_r_ts = (int)$row['id'];
        $interacted_at = isset($REPOST_TS_CACHE[$mid_r_ts]) ? strtotime($REPOST_TS_CACHE[$mid_r_ts]) : strtotime($row['created_at']??'now');
        $created=strtotime($row['created_at']??'now'); $now=time(); $diff=$now-$created;
        $is_new=($diff<=86400); $is_fading=($diff>86400&&$diff<=172800);
        $sc=addslashes(htmlspecialchars($row['caption']));
        $su=addslashes(htmlspecialchars($row['creator']));
        $sp=addslashes($row['creator_pic']?:'uploads/default.jpg');
        $sf=addslashes($row['file_name']);
        $st=addslashes($row['created_at']??'');
        $iy=($current_user==$row['uploaded_by'])?'true':'false';
        $mf=($row['type']==='text'||empty($row['file_name'])||$row['file_name']==='0')?'uploads/Text.jpg':'uploads/'.$row['file_name'];
        $smf=addslashes($mf);
        $mid_r=(int)$row['id'];
        $isPinned_r=in_array($mid_r,$pinned_meme_ids);

        $_meta_r = get_poster_meta($conn, $mid_r, $row['uploaded_by'], $current_user);

        // media gallery array for this Zeem
        $_norm_type_r  = ($row['type']==='text'||empty($row['file_name'])||$row['file_name']==='0')?'text':$row['type'];
        $_media_r      = get_meme_media_items($conn, $mid_r, $smf, $_norm_type_r);

        // group/pod info for the "From <Group>" tag
        $_group_r      = get_meme_group_info($conn, $mid_r);
        $_group_id_r   = $_group_r ? (int)$_group_r['group_id'] : null;
        $_group_name_r = $_group_r ? addslashes($_group_r['group_name']) : null;

        // Zeem Connect fields
        $_connected_id_r    = !empty($row['connected_meme_id']) ? (int)$row['connected_meme_id'] : null;
        $_connected_label_r = !empty($row['connected_label']) ? addslashes($row['connected_label']) : null;

        // attached soundtrack info for this Zeem
        $_audio_r             = get_meme_audio_info($conn, $mid_r);
        $_audio_id_r          = $_audio_r ? (int)$_audio_r['audio_id'] : null;
        $_audio_is_original_r = $_audio_r ? (int)($_audio_r['is_original'] ?? 0) : 0;
        $_audio_file_r        = $_audio_r ? addslashes('uploads/'.$_audio_r['file_name']) : null;
        $_audio_title_r       = $_audio_r ? addslashes($_audio_r['title']) : null;
        $_audio_artist_r      = ($_audio_r && !empty($_audio_r['artist'])) ? addslashes($_audio_r['artist']) : null;
        $_music_vol_r         = ($_audio_r && $_audio_r['music_volume'] !== '' && $_audio_r['music_volume'] !== null) ? (int)$_audio_r['music_volume'] : 80;
        $_vid_vol_r           = ($_audio_r && $_audio_r['video_audio_volume'] !== '' && $_audio_r['video_audio_volume'] !== null) ? (int)$_audio_r['video_audio_volume'] : 40;

        // counts + interaction flags — from the batch cache
        $_counts_r = get_meme_counts($mid_r);
        $rlc = $_counts_r['like']; $rrc = $_counts_r['repost']; $rfc = $_counts_r['fav']; $rsc = $_counts_r['share'];
        $_inter_r = get_meme_interactions($mid_r, $current_user);
        $rli = $_inter_r['liked']; $rri = $_inter_r['reposted']; $rfi = $_inter_r['faved'];

        // FIX: Paid Partnership badge fields — previously omitted from
        // this feed object entirely, so the badge never rendered outside
        // the Memes tab. Also requires m.is_partnership/m.partner_brand
        // to actually be SELECTed in the $reposts query (see note above).
        $_is_partnership_r = !empty($row['is_partnership']) ? 'true' : 'false';
        $_partner_brand_r  = !empty($row['partner_brand']) ? "'".addslashes($row['partner_brand'])."'" : "''";

        // NEW: post-as-ad + AI-generated fields, same treatment as the
        // partnership fields directly above — requires m.is_ad,
        // m.is_ai_generated, and the meme_ads join's cta_text/cta_url/
        // cta_color columns to actually be SELECTed in the $reposts
        // query for these to carry real data through to the modal.
        $_is_ad_r          = !empty($row['is_ad']) ? 'true' : 'false';
        $_ad_cta_text_r    = !empty($row['ad_cta_text'])  ? "'".addslashes($row['ad_cta_text'])."'"  : 'null';
        $_ad_cta_url_r     = !empty($row['ad_cta_url'])   ? "'".addslashes($row['ad_cta_url'])."'"   : 'null';
        $_ad_cta_color_r   = !empty($row['ad_cta_color']) ? "'".addslashes($row['ad_cta_color'])."'" : 'null';
        $_is_ai_generated_r = !empty($row['is_ai_generated']) ? 'true' : 'false';
    ?>
    <script>repostFeed.push({id:<?=$mid_r?>,file:'<?=$smf?>',type:'<?=addslashes($row['type'])?>',pic:'<?=$sp?>',userId:<?=(int)$row['uploaded_by']?>,username:'<?=$su?>',caption:'<?=$sc?>',time:'<?=$st?>',isYou:<?=$iy?>,likeCount:<?=(int)$rlc?>,repostCount:<?=(int)$rrc?>,favCount:<?=(int)$rfc?>,shareCount:<?=(int)$rsc?>,liked:<?=$rli?'true':'false'?>,reposted:<?=$rri?'true':'false'?>,faved:<?=$rfi?'true':'false'?>,isPinned:<?=$isPinned_r?'true':'false'?>,nickname:'<?=addslashes($_meta_r['nickname'])?>',isFollowing:<?=$_meta_r['is_following']?'true':'false'?>,socialProof:<?=json_encode($_meta_r['social_proof'])?>,media:<?=json_encode($_media_r)?>,context:'Reposted',groupId:<?= $_group_id_r !== null ? $_group_id_r : 'null' ?>,groupTitle:<?= $_group_name_r !== null ? "'".$_group_name_r."'" : 'null' ?>,connectedMemeId:<?= $_connected_id_r !== null ? $_connected_id_r : 'null' ?>,connectedLabel:<?= $_connected_label_r !== null ? "'".$_connected_label_r."'" : 'null' ?>,audioId:<?= $_audio_id_r !== null ? $_audio_id_r : 'null' ?>,audioIsOriginal:<?= $_audio_is_original_r ? 'true' : 'false' ?>,audioFile:<?= $_audio_file_r !== null ? "'".$_audio_file_r."'" : 'null' ?>,audioTitle:<?= $_audio_title_r !== null ? "'".$_audio_title_r."'" : 'null' ?>,audioArtist:<?= $_audio_artist_r !== null ? "'".$_audio_artist_r."'" : 'null' ?>,musicVolume:<?=$_music_vol_r?>,videoAudioVolume:<?=$_vid_vol_r?>,isPartnership:<?=$_is_partnership_r?>,partnerBrand:<?=$_partner_brand_r?>,isAd:<?=$_is_ad_r?>,adCtaText:<?=$_ad_cta_text_r?>,adCtaUrl:<?=$_ad_cta_url_r?>,adCtaColor:<?=$_ad_cta_color_r?>,isAiGenerated:<?=$_is_ai_generated_r?>});</script>
    <div class="meme-card <?=$is_new?'is-new':''?> <?=$is_fading?'is-fading':''?>" data-id="<?=$mid_r?>" data-ts="<?=(int)$interacted_at?>" data-pinned="<?=$isPinned_r?'1':'0'?>">
        <div class="top-bar">
            <img src="<?=$row['creator_pic']?:'uploads/default.jpg'?>" loading="lazy" decoding="async" onclick="window.location.href='profile.php?user_id=<?=$row['uploaded_by']?>'">
            <div class="username" onclick="window.location.href='profile.php?user_id=<?=$row['uploaded_by']?>'">
                <?=($current_user==$row['uploaded_by'])?'You':htmlspecialchars($row['creator'])?>
            </div>
        </div>
        <div class="media-box" style="position:relative;">
            <?php if($row['type']=="image"): ?><img src="uploads/<?=$sf?>" loading="lazy" decoding="async" onclick="openMemeModal('<?=$smf?>','<?=$row['type']?>','<?=$sc?>','<?=$sp?>','<?=$su?>',<?=$row['uploaded_by']?>,'repostFeed',<?=$mid_r?>)">
            <?php elseif($row['type']=="video"): ?><video class="lazy-video" data-src="uploads/<?=$sf?>" muted loop playsinline preload="none" onclick="openMemeModal('<?=$smf?>','<?=$row['type']?>','<?=$sc?>','<?=$sp?>','<?=$su?>',<?=$row['uploaded_by']?>,'repostFeed',<?=$mid_r?>)"></video>
            <?php else: ?><img src="uploads/Text.jpg" loading="lazy" decoding="async" onclick="openMemeModal('uploads/Text.jpg','image','<?=$sc?>','<?=$sp?>','<?=$su?>',<?=$row['uploaded_by']?>,'repostFeed',<?=$mid_r?>)">
            <?php endif; ?>
            <?php if($diff<=172800): ?><div class="new-badge">NEW</div><?php endif; ?>
            <?php if($isPinned_r): ?><div class="card-pin-indicator"><i class="ri-pushpin-fill"></i></div><?php endif; ?>
            <?php if($current_user): ?><button class="card-add-col-btn" title="Add to Collection" onclick="event.stopPropagation();gOpenAddToCol(<?=$mid_r?>)"><i class="ri-folder-add-line"></i></button><?php endif; ?>
        </div>
        <div class="icon-bar">
            <a href="download.php?file=<?=urlencode($row['file_name'])?>" title="Download"><i class="ri-download-line"></i></a>
            <?php if($current_user==$profile_id): ?>
            <button class="card-pin-btn<?=$isPinned_r?' pinned':''?>" data-meme-id="<?=$mid_r?>" title="<?=$isPinned_r?'Unpin':'Pin to profile'?>" onclick="event.stopPropagation();gTogglePin(<?=$mid_r?>,this)"><i class="ri-pushpin-<?=$isPinned_r?'fill':'line'?>"></i></button>
            <?php endif; ?>
        </div>
        <div class="caption"><i class="ri-repeat-line" style="color:#FF4C4C;"></i><?=htmlspecialchars($row['caption'])?></div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
    </div>
    <div class="col-section" id="repostsColSec">
        <div id="repostsColFolders">
            <div class="col-search-wrap" id="repostsColSearchWrap">
                <i class="ri-search-line col-search-icon"></i>
                <input type="text" class="col-search-input" id="repostsColSearch" placeholder="Search collections…" oninput="gSearchFolders('reposts',this.value)" autocomplete="off">
                <button class="col-search-clear" id="repostsColSearchClear" onclick="gClearFolderSearch('reposts')">✕</button>
            </div>
            <div class="col-folders-grid" id="repostsColGrid">
                <?php if($current_user==$profile_id): ?>
                <button class="col-new-btn" onclick="gOpenNameModal('create','reposts')"><i class="ri-folder-add-line"></i>New Collection</button>
                <?php endif; ?>
            </div>
            <div class="col-search-no-results" id="repostsColNoResults"><i class="ri-folder-search-line"></i>No collections match</div>
        </div>
        <div class="col-detail" id="repostsColDetail">
            <div class="col-detail-hdr"><button class="col-back-btn" onclick="gCloseDetail('reposts')"><i class="ri-arrow-left-line"></i></button><div class="col-detail-title" id="repostsColTitle">Collection</div><div class="col-detail-count" id="repostsColCount">0 items</div></div>
            <div class="col-detail-search-wrap">
                <i class="ri-hashtag col-detail-search-icon"></i>
                <input type="text" class="col-detail-search-input" id="repostsDetailSearch" placeholder="Search by caption or #hashtag…" oninput="gSearchDetailItems('repostsColDetailGrid','repostsDetailSearch','repostsDetailNoResults')" autocomplete="off">
                <button class="col-detail-search-clear" id="repostsDetailSearchClear" onclick="gClearDetailSearch('repostsColDetailGrid','repostsDetailSearch','repostsDetailNoResults','repostsDetailSearchClear')">✕</button>
            </div>
            <div class="col-detail-no-results" id="repostsDetailNoResults"><i class="ri-search-eye-line"></i>No memes found</div>
            <div class="grid" id="repostsColDetailGrid"></div>
        </div>
    </div>
</div>

<!-- ══════════ TAGS ══════════ -->
<div id="tags" class="dropdown-section" style="display:none;">
    <div class="grid-sort-bar" id="tagsSortBar">
        <button class="g-pill active" id="tagsN" onclick="gSort('tagsGrid','newest','tags')"><i class="ri-time-line" style="margin-right:5px;font-size:11px;"></i>Newest</button>
        <button class="g-pill" id="tagsO" onclick="gSort('tagsGrid','oldest','tags')"><i class="ri-history-line" style="margin-right:5px;font-size:11px;"></i>Oldest</button>
        <div class="g-pill-div"></div>
        <button class="g-pill" id="tagsC" onclick="gOpenCol('tags')"><i class="ri-folder-3-line" style="margin-right:5px;font-size:11px;"></i>Collections<span class="g-pill-count"><?= $col_count_total > 0 ? dezzFmt($col_count_total) : '' ?></span></button>
    </div>
    <div class="grid" id="tagsGrid">
    <?php if(empty($tags_rows)): ?>
        <div style="grid-column:1/-1;text-align:center;padding:40px 20px;color:#aaa;font-size:0.95rem;"><i class="ri-account-pin-box-line" style="font-size:2.5rem;display:block;margin-bottom:10px;color:#FF4C4C;"></i>No tagged posts yet</div>
    <?php else: ?>
    <script>var tagsFeed=[];</script>
    <?php
    $all_tags_rows = $tags_rows;
    $pinned_tags=[]; $normal_tags=[];
    foreach($all_tags_rows as $row){
        if(in_array((int)$row['id'],$pinned_meme_ids)) $pinned_tags[(int)$row['id']]=$row;
        else $normal_tags[]=$row;
    }
    $ordered_tags_pinned=[];
    foreach($pinned_meme_ids as $pid){if(isset($pinned_tags[$pid]))$ordered_tags_pinned[]=$pinned_tags[$pid];}
    $sorted_tags=array_merge($ordered_tags_pinned,$normal_tags);
    foreach($sorted_tags as $row):
        $created=strtotime($row['created_at']); $now=time(); $diff=$now-$created;
        $is_new=($diff<=86400); $is_fading=($diff>86400&&$diff<=172800);
        $sc=addslashes(htmlspecialchars($row['caption']));
        $su=addslashes(htmlspecialchars($row['username']));
        $sp=addslashes($row['uploader_pic']?:'uploads/default.jpg');
        $sf=addslashes($row['file_name']);
        $st=addslashes($row['created_at']);
        $iy=($current_user==$row['uploaded_by'])?'true':'false';
        $mf=($row['type']==='text'||empty($row['file_name'])||$row['file_name']==='0')?'uploads/Text.jpg':'uploads/'.$row['file_name'];
        $smf=addslashes($mf);
        $mid_t=(int)$row['id'];
        $isPinned_t=in_array($mid_t,$pinned_meme_ids);

        $_meta_t = get_poster_meta($conn, $mid_t, $row['uploaded_by'], $current_user);

        // media gallery array for this Zeem
        $_norm_type_t  = ($row['type']==='text'||empty($row['file_name'])||$row['file_name']==='0')?'text':$row['type'];
        $_media_t      = get_meme_media_items($conn, $mid_t, $smf, $_norm_type_t);

        // group/pod info for the "From <Group>" tag
        $_group_t      = get_meme_group_info($conn, $mid_t);
        $_group_id_t   = $_group_t ? (int)$_group_t['group_id'] : null;
        $_group_name_t = $_group_t ? addslashes($_group_t['group_name']) : null;

        // Zeem Connect fields
        $_connected_id_t    = !empty($row['connected_meme_id']) ? (int)$row['connected_meme_id'] : null;
        $_connected_label_t = !empty($row['connected_label']) ? addslashes($row['connected_label']) : null;

        // attached soundtrack info for this Zeem
        $_audio_t             = get_meme_audio_info($conn, $mid_t);
        $_audio_id_t          = $_audio_t ? (int)$_audio_t['audio_id'] : null;
        $_audio_is_original_t = $_audio_t ? (int)($_audio_t['is_original'] ?? 0) : 0;
        $_audio_file_t        = $_audio_t ? addslashes('uploads/'.$_audio_t['file_name']) : null;
        $_audio_title_t       = $_audio_t ? addslashes($_audio_t['title']) : null;
        $_audio_artist_t      = ($_audio_t && !empty($_audio_t['artist'])) ? addslashes($_audio_t['artist']) : null;
        $_music_vol_t         = ($_audio_t && $_audio_t['music_volume'] !== '' && $_audio_t['music_volume'] !== null) ? (int)$_audio_t['music_volume'] : 80;
        $_vid_vol_t           = ($_audio_t && $_audio_t['video_audio_volume'] !== '' && $_audio_t['video_audio_volume'] !== null) ? (int)$_audio_t['video_audio_volume'] : 40;

        // counts + interaction flags — from the batch cache
        $_counts_t = get_meme_counts($mid_t);
        $tlc = $_counts_t['like']; $trc = $_counts_t['repost']; $tfc = $_counts_t['fav']; $tsc = $_counts_t['share'];
        $_inter_t = get_meme_interactions($mid_t, $current_user);
        $tli = $_inter_t['liked']; $tri = $_inter_t['reposted']; $tfi = $_inter_t['faved'];

        // FIX: Paid Partnership badge fields (see Reposts section note above)
        $_is_partnership_t = !empty($row['is_partnership']) ? 'true' : 'false';
        $_partner_brand_t  = !empty($row['partner_brand']) ? "'".addslashes($row['partner_brand'])."'" : "''";

        // NEW: post-as-ad + AI-generated fields (see Reposts section note above)
        $_is_ad_t          = !empty($row['is_ad']) ? 'true' : 'false';
        $_ad_cta_text_t    = !empty($row['ad_cta_text'])  ? "'".addslashes($row['ad_cta_text'])."'"  : 'null';
        $_ad_cta_url_t     = !empty($row['ad_cta_url'])   ? "'".addslashes($row['ad_cta_url'])."'"   : 'null';
        $_ad_cta_color_t   = !empty($row['ad_cta_color']) ? "'".addslashes($row['ad_cta_color'])."'" : 'null';
        $_is_ai_generated_t = !empty($row['is_ai_generated']) ? 'true' : 'false';
    ?>
    <script>tagsFeed.push({id:<?=$mid_t?>,file:'<?=$smf?>',type:'<?=addslashes($row['type'])?>',pic:'<?=$sp?>',userId:<?=(int)$row['uploaded_by']?>,username:'<?=$su?>',caption:'<?=$sc?>',time:'<?=$st?>',isYou:<?=$iy?>,likeCount:<?=(int)$tlc?>,repostCount:<?=(int)$trc?>,favCount:<?=(int)$tfc?>,shareCount:<?=(int)$tsc?>,liked:<?=$tli?'true':'false'?>,reposted:<?=$tri?'true':'false'?>,faved:<?=$tfi?'true':'false'?>,isPinned:<?=$isPinned_t?'true':'false'?>,nickname:'<?=addslashes($_meta_t['nickname'])?>',isFollowing:<?=$_meta_t['is_following']?'true':'false'?>,socialProof:<?=json_encode($_meta_t['social_proof'])?>,media:<?=json_encode($_media_t)?>,context:'Tagged',groupId:<?= $_group_id_t !== null ? $_group_id_t : 'null' ?>,groupTitle:<?= $_group_name_t !== null ? "'".$_group_name_t."'" : 'null' ?>,connectedMemeId:<?= $_connected_id_t !== null ? $_connected_id_t : 'null' ?>,connectedLabel:<?= $_connected_label_t !== null ? "'".$_connected_label_t."'" : 'null' ?>,audioId:<?= $_audio_id_t !== null ? $_audio_id_t : 'null' ?>,audioIsOriginal:<?= $_audio_is_original_t ? 'true' : 'false' ?>,audioFile:<?= $_audio_file_t !== null ? "'".$_audio_file_t."'" : 'null' ?>,audioTitle:<?= $_audio_title_t !== null ? "'".$_audio_title_t."'" : 'null' ?>,audioArtist:<?= $_audio_artist_t !== null ? "'".$_audio_artist_t."'" : 'null' ?>,musicVolume:<?=$_music_vol_t?>,videoAudioVolume:<?=$_vid_vol_t?>,isPartnership:<?=$_is_partnership_t?>,partnerBrand:<?=$_partner_brand_t?>,isAd:<?=$_is_ad_t?>,adCtaText:<?=$_ad_cta_text_t?>,adCtaUrl:<?=$_ad_cta_url_t?>,adCtaColor:<?=$_ad_cta_color_t?>,isAiGenerated:<?=$_is_ai_generated_t?>});</script>
    <div class="meme-card <?=$is_new?'is-new':''?> <?=$is_fading?'is-fading':''?>" data-id="<?=$mid_t?>" data-ts="<?=(int)$created?>" data-pinned="<?=$isPinned_t?'1':'0'?>">
        <div class="top-bar">
            <img src="<?=$row['uploader_pic']?:'uploads/default.jpg'?>" loading="lazy" decoding="async" onclick="window.location.href='profile.php?user_id=<?=$row['uploaded_by']?>'">
            <div class="username" onclick="window.location.href='profile.php?user_id=<?=$row['uploaded_by']?>'">
                <?=htmlspecialchars($row['username'])?>
            </div>
        </div>
        <div class="media-box" style="position:relative;">
            <?php if($row['type']=="image"): ?><img src="uploads/<?=$sf?>" loading="lazy" decoding="async" onclick="openMemeModal('<?=$smf?>','<?=$row['type']?>','<?=$sc?>','<?=$sp?>','<?=$su?>',<?=$row['uploaded_by']?>,'tagsFeed',<?=$mid_t?>)">
            <?php elseif($row['type']=="video"): ?><video class="lazy-video" data-src="uploads/<?=$sf?>" muted loop playsinline preload="none" onclick="openMemeModal('<?=$smf?>','<?=$row['type']?>','<?=$sc?>','<?=$sp?>','<?=$su?>',<?=$row['uploaded_by']?>,'tagsFeed',<?=$mid_t?>)"></video>
            <?php else: ?><img src="uploads/Text.jpg" loading="lazy" decoding="async" onclick="openMemeModal('uploads/Text.jpg','image','<?=$sc?>','<?=$sp?>','<?=$su?>',<?=$row['uploaded_by']?>,'tagsFeed',<?=$mid_t?>)">
            <?php endif; ?>
            <?php if($diff<=172800): ?><div class="new-badge">NEW</div><?php endif; ?>
            <?php if($isPinned_t): ?><div class="card-pin-indicator"><i class="ri-pushpin-fill"></i></div><?php endif; ?>
            <?php if($current_user): ?><button class="card-add-col-btn" title="Add to Collection" onclick="event.stopPropagation();gOpenAddToCol(<?=$mid_t?>)"><i class="ri-folder-add-line"></i></button><?php endif; ?>
        </div>
        <div class="icon-bar">
            <a href="download.php?file=<?=urlencode($row['file_name'])?>" title="Download"><i class="ri-download-line"></i></a>
            <?php if($current_user==$profile_id): ?>
            <button class="card-pin-btn<?=$isPinned_t?' pinned':''?>" data-meme-id="<?=$mid_t?>" title="<?=$isPinned_t?'Unpin':'Pin to profile'?>" onclick="event.stopPropagation();gTogglePin(<?=$mid_t?>,this)"><i class="ri-pushpin-<?=$isPinned_t?'fill':'line'?>"></i></button>
            <?php endif; ?>
        </div>
        <div class="caption"><i class="ri-account-pin-box-line" style="color:#FF4C4C;"></i><?=htmlspecialchars($row['caption'])?></div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
    </div>
    <div class="col-section" id="tagsColSec">
        <div id="tagsColFolders">
            <div class="col-search-wrap" id="tagsColSearchWrap">
                <i class="ri-search-line col-search-icon"></i>
                <input type="text" class="col-search-input" id="tagsColSearch" placeholder="Search collections…" oninput="gSearchFolders('tags',this.value)" autocomplete="off">
                <button class="col-search-clear" id="tagsColSearchClear" onclick="gClearFolderSearch('tags')">✕</button>
            </div>
            <div class="col-folders-grid" id="tagsColGrid">
                <?php if($current_user==$profile_id): ?>
                <button class="col-new-btn" onclick="gOpenNameModal('create','tags')"><i class="ri-folder-add-line"></i>New Collection</button>
                <?php endif; ?>
            </div>
            <div class="col-search-no-results" id="tagsColNoResults"><i class="ri-folder-search-line"></i>No collections match</div>
        </div>
        <div class="col-detail" id="tagsColDetail">
            <div class="col-detail-hdr"><button class="col-back-btn" onclick="gCloseDetail('tags')"><i class="ri-arrow-left-line"></i></button><div class="col-detail-title" id="tagsColTitle">Collection</div><div class="col-detail-count" id="tagsColCount">0 items</div></div>
            <div class="col-detail-search-wrap">
                <i class="ri-hashtag col-detail-search-icon"></i>
                <input type="text" class="col-detail-search-input" id="tagsDetailSearch" placeholder="Search by caption or #hashtag…" oninput="gSearchDetailItems('tagsColDetailGrid','tagsDetailSearch','tagsDetailNoResults')" autocomplete="off">
                <button class="col-detail-search-clear" id="tagsDetailSearchClear" onclick="gClearDetailSearch('tagsColDetailGrid','tagsDetailSearch','tagsDetailNoResults','tagsDetailSearchClear')">✕</button>
            </div>
            <div class="col-detail-no-results" id="tagsDetailNoResults"><i class="ri-search-eye-line"></i>No memes found</div>
            <div class="grid" id="tagsColDetailGrid"></div>
        </div>
    </div>
</div>

<!-- ══════════ FAVORITES ══════════ -->
<div id="favorites" class="dropdown-section" style="display:none;">
    <div class="grid-sort-bar" id="favsSortBar">
        <button class="g-pill active" id="favsN" onclick="gSort('favsGrid','newest','favs')"><i class="ri-time-line" style="margin-right:5px;font-size:11px;"></i>Newest</button>
        <button class="g-pill" id="favsO" onclick="gSort('favsGrid','oldest','favs')"><i class="ri-history-line" style="margin-right:5px;font-size:11px;"></i>Oldest</button>
        <div class="g-pill-div"></div>
        <button class="g-pill" id="favsC" onclick="gOpenCol('favs')"><i class="ri-folder-3-line" style="margin-right:5px;font-size:11px;"></i>Collections<span class="g-pill-count"><?= $col_count_total > 0 ? dezzFmt($col_count_total) : '' ?></span></button>
    </div>
    <div class="grid" id="favsGrid">
    <?php if(empty($favs_rows)): ?>
        <div style="grid-column:1/-1;text-align:center;padding:40px 20px;color:#aaa;font-size:0.95rem;"><i class="ri-bookmark-line" style="font-size:2.5rem;display:block;margin-bottom:10px;color:#FF4C4C;"></i>No favorites yet</div>
    <?php else: ?>
    <script>var favsFeed=[];</script>
    <?php
    $all_favs_rows = $favs_rows;
    $pinned_favs=[]; $normal_favs=[];
    foreach($all_favs_rows as $row){
        if(in_array((int)$row['id'],$pinned_meme_ids)) $pinned_favs[(int)$row['id']]=$row;
        else $normal_favs[]=$row;
    }
    $ordered_favs_pinned=[];
    foreach($pinned_meme_ids as $pid){if(isset($pinned_favs[$pid]))$ordered_favs_pinned[]=$pinned_favs[$pid];}
    $sorted_favs=array_merge($ordered_favs_pinned,$normal_favs);
    foreach($sorted_favs as $row):
        $created=strtotime($row['created_at']); $now=time(); $diff=$now-$created;
        $is_new=($diff<=86400); $is_fading=($diff>86400&&$diff<=172800);
        $sc=addslashes(htmlspecialchars($row['caption']));
        $su=addslashes(htmlspecialchars($row['username']));
        $sp=addslashes($row['uploader_pic']?:'uploads/default.jpg');
        $sf=addslashes($row['file_name']);
        $st=addslashes($row['created_at']);
        $iy=($current_user==$row['uploaded_by'])?'true':'false';
        $mf=($row['type']==='text'||empty($row['file_name'])||$row['file_name']==='0')?'uploads/Text.jpg':'uploads/'.$row['file_name'];
        $smf=addslashes($mf);
        $mid_f=(int)$row['id'];
        $isPinned_f=in_array($mid_f,$pinned_meme_ids);

        $_meta_f = get_poster_meta($conn, $mid_f, $row['uploaded_by'], $current_user);

        // media gallery array for this Zeem
        $_norm_type_f  = ($row['type']==='text'||empty($row['file_name'])||$row['file_name']==='0')?'text':$row['type'];
        $_media_f      = get_meme_media_items($conn, $mid_f, $smf, $_norm_type_f);

        // group/pod info for the "From <Group>" tag
        $_group_f      = get_meme_group_info($conn, $mid_f);
        $_group_id_f   = $_group_f ? (int)$_group_f['group_id'] : null;
        $_group_name_f = $_group_f ? addslashes($_group_f['group_name']) : null;

        // Zeem Connect fields
        $_connected_id_f    = !empty($row['connected_meme_id']) ? (int)$row['connected_meme_id'] : null;
        $_connected_label_f = !empty($row['connected_label']) ? addslashes($row['connected_label']) : null;

        // attached soundtrack info for this Zeem
        $_audio_f             = get_meme_audio_info($conn, $mid_f);
        $_audio_id_f          = $_audio_f ? (int)$_audio_f['audio_id'] : null;
        $_audio_is_original_f = $_audio_f ? (int)($_audio_f['is_original'] ?? 0) : 0;
        $_audio_file_f        = $_audio_f ? addslashes('uploads/'.$_audio_f['file_name']) : null;
        $_audio_title_f       = $_audio_f ? addslashes($_audio_f['title']) : null;
        $_audio_artist_f      = ($_audio_f && !empty($_audio_f['artist'])) ? addslashes($_audio_f['artist']) : null;
        $_music_vol_f         = ($_audio_f && $_audio_f['music_volume'] !== '' && $_audio_f['music_volume'] !== null) ? (int)$_audio_f['music_volume'] : 80;
        $_vid_vol_f           = ($_audio_f && $_audio_f['video_audio_volume'] !== '' && $_audio_f['video_audio_volume'] !== null) ? (int)$_audio_f['video_audio_volume'] : 40;

        // interaction timestamp now comes from the batched FAV_TS_CACHE
        // primed above instead of one query per row.
        global $FAV_TS_CACHE;
        $interacted_at = isset($FAV_TS_CACHE[$mid_f]) ? strtotime($FAV_TS_CACHE[$mid_f]) : $created;

        // counts + interaction flags — from the batch cache
        $_counts_f = get_meme_counts($mid_f);
        $flc = $_counts_f['like']; $frc = $_counts_f['repost']; $ffc = $_counts_f['fav']; $fsc = $_counts_f['share'];
        $_inter_f = get_meme_interactions($mid_f, $current_user);
        $fli = $_inter_f['liked']; $fri = $_inter_f['reposted']; $ffi = $_inter_f['faved'];

        // FIX: Paid Partnership badge fields (see Reposts section note above)
        $_is_partnership_f = !empty($row['is_partnership']) ? 'true' : 'false';
        $_partner_brand_f  = !empty($row['partner_brand']) ? "'".addslashes($row['partner_brand'])."'" : "''";

        // NEW: post-as-ad + AI-generated fields (see Reposts section note above)
        $_is_ad_f          = !empty($row['is_ad']) ? 'true' : 'false';
        $_ad_cta_text_f    = !empty($row['ad_cta_text'])  ? "'".addslashes($row['ad_cta_text'])."'"  : 'null';
        $_ad_cta_url_f     = !empty($row['ad_cta_url'])   ? "'".addslashes($row['ad_cta_url'])."'"   : 'null';
        $_ad_cta_color_f   = !empty($row['ad_cta_color']) ? "'".addslashes($row['ad_cta_color'])."'" : 'null';
        $_is_ai_generated_f = !empty($row['is_ai_generated']) ? 'true' : 'false';
    ?>
    <script>favsFeed.push({id:<?=$mid_f?>,file:'<?=$smf?>',type:'<?=addslashes($row['type'])?>',pic:'<?=$sp?>',userId:<?=(int)$row['uploaded_by']?>,username:'<?=$su?>',caption:'<?=$sc?>',time:'<?=$st?>',isYou:<?=$iy?>,likeCount:<?=(int)$flc?>,repostCount:<?=(int)$frc?>,favCount:<?=(int)$ffc?>,shareCount:<?=(int)$fsc?>,liked:<?=$fli?'true':'false'?>,reposted:<?=$fri?'true':'false'?>,faved:<?=$ffi?'true':'false'?>,isPinned:<?=$isPinned_f?'true':'false'?>,nickname:'<?=addslashes($_meta_f['nickname'])?>',isFollowing:<?=$_meta_f['is_following']?'true':'false'?>,socialProof:<?=json_encode($_meta_f['social_proof'])?>,media:<?=json_encode($_media_f)?>,context:'Saved',groupId:<?= $_group_id_f !== null ? $_group_id_f : 'null' ?>,groupTitle:<?= $_group_name_f !== null ? "'".$_group_name_f."'" : 'null' ?>,connectedMemeId:<?= $_connected_id_f !== null ? $_connected_id_f : 'null' ?>,connectedLabel:<?= $_connected_label_f !== null ? "'".$_connected_label_f."'" : 'null' ?>,audioId:<?= $_audio_id_f !== null ? $_audio_id_f : 'null' ?>,audioIsOriginal:<?= $_audio_is_original_f ? 'true' : 'false' ?>,audioFile:<?= $_audio_file_f !== null ? "'".$_audio_file_f."'" : 'null' ?>,audioTitle:<?= $_audio_title_f !== null ? "'".$_audio_title_f."'" : 'null' ?>,audioArtist:<?= $_audio_artist_f !== null ? "'".$_audio_artist_f."'" : 'null' ?>,musicVolume:<?=$_music_vol_f?>,videoAudioVolume:<?=$_vid_vol_f?>,isPartnership:<?=$_is_partnership_f?>,partnerBrand:<?=$_partner_brand_f?>,isAd:<?=$_is_ad_f?>,adCtaText:<?=$_ad_cta_text_f?>,adCtaUrl:<?=$_ad_cta_url_f?>,adCtaColor:<?=$_ad_cta_color_f?>,isAiGenerated:<?=$_is_ai_generated_f?>});</script>
    <div class="meme-card <?=$is_new?'is-new':''?> <?=$is_fading?'is-fading':''?>" data-id="<?=$mid_f?>" data-ts="<?=(int)$interacted_at?>" data-pinned="<?=$isPinned_f?'1':'0'?>">
        <div class="top-bar">
            <img src="<?=$row['uploader_pic']?:'uploads/default.jpg'?>" loading="lazy" decoding="async" onclick="window.location.href='profile.php?user_id=<?=$row['uploaded_by']?>'">
            <div class="username" onclick="window.location.href='profile.php?user_id=<?=$row['uploaded_by']?>'">
                <?=htmlspecialchars($row['username'])?>
            </div>
        </div>
        <div class="media-box" style="position:relative;">
            <?php if($row['type']=="image"): ?><img src="uploads/<?=$sf?>" loading="lazy" decoding="async" onclick="openMemeModal('<?=$smf?>','<?=$row['type']?>','<?=$sc?>','<?=$sp?>','<?=$su?>',<?=$row['uploaded_by']?>,'favsFeed',<?=$mid_f?>)">
            <?php elseif($row['type']=="video"): ?><video class="lazy-video" data-src="uploads/<?=$sf?>" muted loop playsinline preload="none" onclick="openMemeModal('<?=$smf?>','<?=$row['type']?>','<?=$sc?>','<?=$sp?>','<?=$su?>',<?=$row['uploaded_by']?>,'favsFeed',<?=$mid_f?>)"></video>
            <?php else: ?><img src="uploads/Text.jpg" loading="lazy" decoding="async" onclick="openMemeModal('uploads/Text.jpg','image','<?=$sc?>','<?=$sp?>','<?=$su?>',<?=$row['uploaded_by']?>,'favsFeed',<?=$mid_f?>)">
            <?php endif; ?>
            <?php if($diff<=172800): ?><div class="new-badge">NEW</div><?php endif; ?>
            <?php if($isPinned_f): ?><div class="card-pin-indicator"><i class="ri-pushpin-fill"></i></div><?php endif; ?>
            <button class="card-add-col-btn" title="Add to Collection" onclick="event.stopPropagation();gOpenAddToCol(<?=$mid_f?>)"><i class="ri-folder-add-line"></i></button>
        </div>
        <div class="icon-bar">
            <a href="download.php?file=<?=urlencode($row['file_name'])?>" title="Download"><i class="ri-download-line"></i></a>
            <button class="card-pin-btn<?=$isPinned_f?' pinned':''?>" data-meme-id="<?=$mid_f?>" title="<?=$isPinned_f?'Unpin':'Pin to profile'?>" onclick="event.stopPropagation();gTogglePin(<?=$mid_f?>,this)"><i class="ri-pushpin-<?=$isPinned_f?'fill':'line'?>"></i></button>
        </div>
        <div class="caption"><i class="ri-bookmark-line" style="color:#FF4C4C;"></i><?=htmlspecialchars($row['caption'])?></div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
    </div>
    <div class="col-section" id="favsColSec">
        <div id="favsColFolders">
            <div class="col-search-wrap" id="favsColSearchWrap">
                <i class="ri-search-line col-search-icon"></i>
                <input type="text" class="col-search-input" id="favsColSearch" placeholder="Search collections…" oninput="gSearchFolders('favs',this.value)" autocomplete="off">
                <button class="col-search-clear" id="favsColSearchClear" onclick="gClearFolderSearch('favs')">✕</button>
            </div>
            <div class="col-folders-grid" id="favsColGrid">
                <button class="col-new-btn" onclick="gOpenNameModal('create','favs')"><i class="ri-folder-add-line"></i>New Collection</button>
            </div>
            <div class="col-search-no-results" id="favsColNoResults"><i class="ri-folder-search-line"></i>No collections match</div>
        </div>
        <div class="col-detail" id="favsColDetail">
            <div class="col-detail-hdr"><button class="col-back-btn" onclick="gCloseDetail('favs')"><i class="ri-arrow-left-line"></i></button><div class="col-detail-title" id="favsColTitle">Collection</div><div class="col-detail-count" id="favsColCount">0 items</div></div>
            <div class="col-detail-search-wrap">
                <i class="ri-hashtag col-detail-search-icon"></i>
                <input type="text" class="col-detail-search-input" id="favsDetailSearch" placeholder="Search by caption or #hashtag…" oninput="gSearchDetailItems('favsColDetailGrid','favsDetailSearch','favsDetailNoResults')" autocomplete="off">
                <button class="col-detail-search-clear" id="favsDetailSearchClear" onclick="gClearDetailSearch('favsColDetailGrid','favsDetailSearch','favsDetailNoResults','favsDetailSearchClear')">✕</button>
            </div>
            <div class="col-detail-no-results" id="favsDetailNoResults"><i class="ri-search-eye-line"></i>No memes found</div>
            <div class="grid" id="favsColDetailGrid"></div>
        </div>
    </div>
</div>

<!-- ══════════ LIKES ══════════ -->
<div id="likes" class="dropdown-section" style="display:none;">
    <div class="grid-sort-bar" id="likesSortBar">
        <button class="g-pill active" id="likesN" onclick="gSort('likesGrid','newest','likes')"><i class="ri-time-line" style="margin-right:5px;font-size:11px;"></i>Newest</button>
        <button class="g-pill" id="likesO" onclick="gSort('likesGrid','oldest','likes')"><i class="ri-history-line" style="margin-right:5px;font-size:11px;"></i>Oldest</button>
        <div class="g-pill-div"></div>
        <button class="g-pill" id="likesC" onclick="gOpenCol('likes')"><i class="ri-folder-3-line" style="margin-right:5px;font-size:11px;"></i>Collections<span class="g-pill-count"><?= $col_count_total > 0 ? dezzFmt($col_count_total) : '' ?></span></button>
    </div>

    <div id="likesGrid">
    <?php if(empty($likes_rows)): ?>
        <div style="text-align:center;padding:40px 20px;color:#aaa;font-size:0.95rem;">
            <i class="ri-heart-line" style="font-size:2.5rem;display:block;margin-bottom:10px;color:#FF4C4C;"></i>No liked posts yet
        </div>
    <?php else:
        $like_rows = $likes_rows;

        // Pinned likes first
        $pinned_likes=[]; $normal_likes=[];
        foreach($like_rows as $row){
            if(in_array((int)$row['id'],$pinned_meme_ids)) $pinned_likes[(int)$row['id']]=$row;
            else $normal_likes[]=$row;
        }
        $ordered_likes_pinned=[];
        foreach($pinned_meme_ids as $pid){if(isset($pinned_likes[$pid]))$ordered_likes_pinned[]=$pinned_likes[$pid];}
        $like_rows=array_merge($ordered_likes_pinned,$normal_likes);

        $grouped = [];
        foreach ($like_rows as $row) {
            // interaction timestamp now comes from the batched LIKE_TS_CACHE
            // primed above instead of one query per row.
            global $LIKE_TS_CACHE;
            $rid = (int)$row['id'];
            $liked_ts  = isset($LIKE_TS_CACHE[$rid]) ? strtotime($LIKE_TS_CACHE[$rid]) : strtotime($row['created_at']);
            $row['_liked_ts'] = $liked_ts;
            $now   = time();
            $diff  = $now - $liked_ts;
            $days  = floor($diff / 86400);
            if ($days === 0)        $label = 'Today';
            elseif ($days === 1)    $label = 'Yesterday';
            elseif ($days < 7)     $label = date('l', $liked_ts);
            elseif ($days < 365)   $label = date('F j', $liked_ts);
            else                    $label = date('F j, Y', $liked_ts);
            $grouped[$label][] = $row;
        }
    ?>
    <script>var likesFeed=[];</script>
    <style>
    .likes-date-group { margin-bottom: 28px; }
    .likes-date-label { display:flex;align-items:center;gap:10px;padding:0 4px;margin-bottom:12px; }
    .likes-date-label-text { font-size:11px;font-weight:700;color:rgba(255,255,255,0.38);text-transform:uppercase;letter-spacing:1.2px;font-family:'Poppins',sans-serif;white-space:nowrap; }
    .likes-date-label-line { flex:1;height:1px;background:rgba(255,255,255,0.07); }
    .likes-date-label-count { font-size:10px;color:rgba(255,255,255,0.22);font-family:'Poppins',sans-serif;white-space:nowrap; }
    </style>

    <?php foreach ($grouped as $label => $rows): ?>
    <div class="likes-date-group">
        <div class="likes-date-label">
            <span class="likes-date-label-text"><?= htmlspecialchars($label) ?></span>
            <div class="likes-date-label-line"></div>
            <span class="likes-date-label-count"><?= count($rows) ?> post<?= count($rows) !== 1 ? 's' : '' ?></span>
        </div>
        <div class="grid">
        <?php foreach ($rows as $row):
            $created   = strtotime($row['created_at']);
            $now       = time();
            $diff      = $now - $created;
            $is_new    = ($diff <= 86400);
            $is_fading = ($diff > 86400 && $diff <= 172800);
            $liked_ts  = $row['_liked_ts'];
            $sc  = addslashes(htmlspecialchars($row['caption']));
            $su  = addslashes(htmlspecialchars($row['username']));
            $sp  = addslashes($row['uploader_pic'] ?: 'uploads/default.jpg');
            $sf  = addslashes($row['file_name']);
            $st  = addslashes($row['created_at']);
            $iy  = ($current_user == $row['uploaded_by']) ? 'true' : 'false';
            $mf  = ($row['type'] === 'text' || empty($row['file_name']) || $row['file_name'] === '0') ? 'uploads/Text.jpg' : 'uploads/' . $row['file_name'];
            $smf = addslashes($mf);
            $mid_l=(int)$row['id'];
            $isPinned_l=in_array($mid_l,$pinned_meme_ids);

            $_meta_l = get_poster_meta($conn, $mid_l, $row['uploaded_by'], $current_user);

            // media gallery array for this Zeem
            $_norm_type_l  = ($row['type']==='text'||empty($row['file_name'])||$row['file_name']==='0')?'text':$row['type'];
            $_media_l      = get_meme_media_items($conn, $mid_l, $smf, $_norm_type_l);

            // group/pod info for the "From <Group>" tag
            $_group_l      = get_meme_group_info($conn, $mid_l);
            $_group_id_l   = $_group_l ? (int)$_group_l['group_id'] : null;
            $_group_name_l = $_group_l ? addslashes($_group_l['group_name']) : null;

            // Zeem Connect fields
            $_connected_id_l    = !empty($row['connected_meme_id']) ? (int)$row['connected_meme_id'] : null;
            $_connected_label_l = !empty($row['connected_label']) ? addslashes($row['connected_label']) : null;

            // attached soundtrack info for this Zeem
            $_audio_l             = get_meme_audio_info($conn, $mid_l);
            $_audio_id_l          = $_audio_l ? (int)$_audio_l['audio_id'] : null;
            $_audio_is_original_l = $_audio_l ? (int)($_audio_l['is_original'] ?? 0) : 0;
            $_audio_file_l        = $_audio_l ? addslashes('uploads/'.$_audio_l['file_name']) : null;
            $_audio_title_l       = $_audio_l ? addslashes($_audio_l['title']) : null;
            $_audio_artist_l      = ($_audio_l && !empty($_audio_l['artist'])) ? addslashes($_audio_l['artist']) : null;
            $_music_vol_l         = ($_audio_l && $_audio_l['music_volume'] !== '' && $_audio_l['music_volume'] !== null) ? (int)$_audio_l['music_volume'] : 80;
            $_vid_vol_l           = ($_audio_l && $_audio_l['video_audio_volume'] !== '' && $_audio_l['video_audio_volume'] !== null) ? (int)$_audio_l['video_audio_volume'] : 40;

            // counts + interaction flags — from the batch cache
            $_counts_l = get_meme_counts($mid_l);
            $llc = $_counts_l['like']; $lrc = $_counts_l['repost']; $lfc = $_counts_l['fav']; $lsc = $_counts_l['share'];
            $_inter_l = get_meme_interactions($mid_l, $current_user);
            $lli = $_inter_l['liked']; $lri = $_inter_l['reposted']; $lfi = $_inter_l['faved'];

            // FIX: Paid Partnership badge fields (see Reposts section note above)
            $_is_partnership_l = !empty($row['is_partnership']) ? 'true' : 'false';
            $_partner_brand_l  = !empty($row['partner_brand']) ? "'".addslashes($row['partner_brand'])."'" : "''";

            // NEW: post-as-ad + AI-generated fields (see Reposts section note above)
            $_is_ad_l          = !empty($row['is_ad']) ? 'true' : 'false';
            $_ad_cta_text_l    = !empty($row['ad_cta_text'])  ? "'".addslashes($row['ad_cta_text'])."'"  : 'null';
            $_ad_cta_url_l     = !empty($row['ad_cta_url'])   ? "'".addslashes($row['ad_cta_url'])."'"   : 'null';
            $_ad_cta_color_l   = !empty($row['ad_cta_color']) ? "'".addslashes($row['ad_cta_color'])."'" : 'null';
            $_is_ai_generated_l = !empty($row['is_ai_generated']) ? 'true' : 'false';
        ?>
        <script>likesFeed.push({id:<?=$mid_l?>,file:'<?=$smf?>',type:'<?=addslashes($row['type'])?>',pic:'<?=$sp?>',userId:<?=(int)$row['uploaded_by']?>,username:'<?=$su?>',caption:'<?=$sc?>',time:'<?=$st?>',isYou:<?=$iy?>,likeCount:<?=(int)$llc?>,repostCount:<?=(int)$lrc?>,favCount:<?=(int)$lfc?>,shareCount:<?=(int)$lsc?>,liked:<?=$lli?'true':'false'?>,reposted:<?=$lri?'true':'false'?>,faved:<?=$lfi?'true':'false'?>,isPinned:<?=$isPinned_l?'true':'false'?>,nickname:'<?=addslashes($_meta_l['nickname'])?>',isFollowing:<?=$_meta_l['is_following']?'true':'false'?>,socialProof:<?=json_encode($_meta_l['social_proof'])?>,media:<?=json_encode($_media_l)?>,context:'Liked',groupId:<?= $_group_id_l !== null ? $_group_id_l : 'null' ?>,groupTitle:<?= $_group_name_l !== null ? "'".$_group_name_l."'" : 'null' ?>,connectedMemeId:<?= $_connected_id_l !== null ? $_connected_id_l : 'null' ?>,connectedLabel:<?= $_connected_label_l !== null ? "'".$_connected_label_l."'" : 'null' ?>,audioId:<?= $_audio_id_l !== null ? $_audio_id_l : 'null' ?>,audioIsOriginal:<?= $_audio_is_original_l ? 'true' : 'false' ?>,audioFile:<?= $_audio_file_l !== null ? "'".$_audio_file_l."'" : 'null' ?>,audioTitle:<?= $_audio_title_l !== null ? "'".$_audio_title_l."'" : 'null' ?>,audioArtist:<?= $_audio_artist_l !== null ? "'".$_audio_artist_l."'" : 'null' ?>,musicVolume:<?=$_music_vol_l?>,videoAudioVolume:<?=$_vid_vol_l?>,isPartnership:<?=$_is_partnership_l?>,partnerBrand:<?=$_partner_brand_l?>,isAd:<?=$_is_ad_l?>,adCtaText:<?=$_ad_cta_text_l?>,adCtaUrl:<?=$_ad_cta_url_l?>,adCtaColor:<?=$_ad_cta_color_l?>,isAiGenerated:<?=$_is_ai_generated_l?>});</script>
        <div class="meme-card <?=$is_new?'is-new':''?> <?=$is_fading?'is-fading':''?>" data-id="<?=$mid_l?>" data-ts="<?=(int)$liked_ts?>" data-pinned="<?=$isPinned_l?'1':'0'?>">
            <div class="top-bar">
                <img src="<?=$row['uploader_pic'] ?: 'uploads/default.jpg'?>" loading="lazy" decoding="async" onclick="window.location.href='profile.php?user_id=<?=$row['uploaded_by']?>'">
                <div class="username" onclick="window.location.href='profile.php?user_id=<?=$row['uploaded_by']?>'">
                    <?= htmlspecialchars($row['username']) ?>
                </div>
            </div>
            <div class="media-box" style="position:relative;">
                <?php if($row['type'] == "image"): ?>
                    <img src="uploads/<?=$sf?>" loading="lazy" decoding="async" onclick="openMemeModal('<?=$smf?>','<?=$row['type']?>','<?=$sc?>','<?=$sp?>','<?=$su?>',<?=$row['uploaded_by']?>,'likesFeed',<?=$mid_l?>)">
                <?php elseif($row['type'] == "video"): ?>
                    <video class="lazy-video" data-src="uploads/<?=$sf?>" muted loop playsinline preload="none" onclick="openMemeModal('<?=$smf?>','<?=$row['type']?>','<?=$sc?>','<?=$sp?>','<?=$su?>',<?=$row['uploaded_by']?>,'likesFeed',<?=$mid_l?>)"></video>
                <?php else: ?>
                    <img src="uploads/Text.jpg" loading="lazy" decoding="async" onclick="openMemeModal('uploads/Text.jpg','image','<?=$sc?>','<?=$sp?>','<?=$su?>',<?=$row['uploaded_by']?>,'likesFeed',<?=$mid_l?>)">
                <?php endif; ?>
                <?php if($diff <= 172800): ?><div class="new-badge">NEW</div><?php endif; ?>
                <?php if($isPinned_l): ?><div class="card-pin-indicator"><i class="ri-pushpin-fill"></i></div><?php endif; ?>
                <button class="card-add-col-btn" title="Add to Collection" onclick="event.stopPropagation();gOpenAddToCol(<?=$mid_l?>)">
                    <i class="ri-folder-add-line"></i>
                </button>
            </div>
            <div class="icon-bar">
                <a href="download.php?file=<?= urlencode($row['file_name']) ?>" title="Download"><i class="ri-download-line"></i></a>
                <button class="card-pin-btn<?=$isPinned_l?' pinned':''?>" data-meme-id="<?=$mid_l?>" title="<?=$isPinned_l?'Unpin':'Pin to profile'?>" onclick="event.stopPropagation();gTogglePin(<?=$mid_l?>,this)"><i class="ri-pushpin-<?=$isPinned_l?'fill':'line'?>"></i></button>
            </div>
            <div class="caption"><?= htmlspecialchars($row['caption']) ?></div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
    </div>

    <div class="col-section" id="likesColSec">
        <div id="likesColFolders">
            <div class="col-search-wrap" id="likesColSearchWrap">
                <i class="ri-search-line col-search-icon"></i>
                <input type="text" class="col-search-input" id="likesColSearch" placeholder="Search collections…" oninput="gSearchFolders('likes',this.value)" autocomplete="off">
                <button class="col-search-clear" id="likesColSearchClear" onclick="gClearFolderSearch('likes')">✕</button>
            </div>
            <div class="col-folders-grid" id="likesColGrid">
                <button class="col-new-btn" onclick="gOpenNameModal('create','likes')"><i class="ri-folder-add-line"></i>New Collection</button>
            </div>
            <div class="col-search-no-results" id="likesColNoResults"><i class="ri-folder-search-line"></i>No collections match</div>
        </div>
        <div class="col-detail" id="likesColDetail">
            <div class="col-detail-hdr">
                <button class="col-back-btn" onclick="gCloseDetail('likes')"><i class="ri-arrow-left-line"></i></button>
                <div class="col-detail-title" id="likesColTitle">Collection</div>
                <div class="col-detail-count" id="likesColCount">0 items</div>
            </div>
            <div class="col-detail-search-wrap">
                <i class="ri-hashtag col-detail-search-icon"></i>
                <input type="text" class="col-detail-search-input" id="likesDetailSearch" placeholder="Search by caption or #hashtag…" oninput="gSearchDetailItems('likesColDetailGrid','likesDetailSearch','likesDetailNoResults')" autocomplete="off">
                <button class="col-detail-search-clear" id="likesDetailSearchClear" onclick="gClearDetailSearch('likesColDetailGrid','likesDetailSearch','likesDetailNoResults','likesDetailSearchClear')">✕</button>
            </div>
            <div class="col-detail-no-results" id="likesDetailNoResults"><i class="ri-search-eye-line"></i>No memes found</div>
            <div class="grid" id="likesColDetailGrid"></div>
        </div>
    </div>
</div>



<!-- ══════════ UPLOADS ══════════ -->
<div id="uploads" class="dropdown-section" style="display:none;">
    <div class="grid-sort-bar" id="uploadsSortBar">
        <button class="g-pill active" id="uploadsN" onclick="gSort('uploadsGrid','newest','uploads')"><i class="ri-time-line" style="margin-right:5px;font-size:11px;"></i>Newest</button>
        <button class="g-pill" id="uploadsO" onclick="gSort('uploadsGrid','oldest','uploads')"><i class="ri-history-line" style="margin-right:5px;font-size:11px;"></i>Oldest</button>
        <div class="g-pill-div"></div>
        <button class="g-pill" id="uploadsC" onclick="gOpenCol('uploads')"><i class="ri-folder-3-line" style="margin-right:5px;font-size:11px;"></i>Collections<span class="g-pill-count"><?= $col_count_total > 0 ? dezzFmt($col_count_total) : '' ?></span></button>
    </div>
    <div class="grid" id="uploadsGrid">
    <?php if(!empty($uploads_rows)):
    $all_upload_rows = $uploads_rows;
    $pinned_ups=[]; $normal_ups=[];
    foreach($all_upload_rows as $u){
        if(in_array((int)$u['id'],$pinned_meme_ids)) $pinned_ups[(int)$u['id']]=$u;
        else $normal_ups[]=$u;
    }
    $ordered_ups_pinned=[];
    foreach($pinned_meme_ids as $pid){if(isset($pinned_ups[$pid]))$ordered_ups_pinned[]=$pinned_ups[$pid];}
    $sorted_ups=array_merge($ordered_ups_pinned,$normal_ups);
    ?>
    <script>var uploadsFeed=[];</script>
    <?php foreach($sorted_ups as $u):
        $created=strtotime($u['created_at']); $now=time(); $diff=$now-$created;
        $is_new=($diff<=86400); $is_fading=($diff>86400&&$diff<=172800);
        $sc=addslashes(htmlspecialchars($u['caption']));
        $su=addslashes(htmlspecialchars($u['username']));
        $sp=addslashes($u['uploader_pic']?:'uploads/default.jpg');
        $sf=addslashes($u['file_name']);
        $st=addslashes($u['created_at']);
        $mf=($u['type']==='text'||empty($u['file_name'])||$u['file_name']==='0')?'uploads/Text.jpg':'uploads/'.$u['file_name'];
        $smf=addslashes($mf);
        $mid_u=(int)$u['id'];
        $isPinned_u=in_array($mid_u,$pinned_meme_ids);

        $_meta_u = get_poster_meta($conn, $mid_u, $u['uploaded_by'], $current_user);

        // media gallery array for this Zeem
        $_norm_type_u  = ($u['type']==='text'||empty($u['file_name'])||$u['file_name']==='0')?'text':$u['type'];
        $_media_u      = get_meme_media_items($conn, $mid_u, $smf, $_norm_type_u);

        // group/pod info for the "From <Group>" tag
        $_group_u      = get_meme_group_info($conn, $mid_u);
        $_group_id_u   = $_group_u ? (int)$_group_u['group_id'] : null;
        $_group_name_u = $_group_u ? addslashes($_group_u['group_name']) : null;

        // Zeem Connect fields
        $_connected_id_u    = !empty($u['connected_meme_id']) ? (int)$u['connected_meme_id'] : null;
        $_connected_label_u = !empty($u['connected_label']) ? addslashes($u['connected_label']) : null;

        // attached soundtrack info for this Zeem
        $_audio_u             = get_meme_audio_info($conn, $mid_u);
        $_audio_id_u          = $_audio_u ? (int)$_audio_u['audio_id'] : null;
        $_audio_is_original_u = $_audio_u ? (int)($_audio_u['is_original'] ?? 0) : 0;
        $_audio_file_u        = $_audio_u ? addslashes('uploads/'.$_audio_u['file_name']) : null;
        $_audio_title_u       = $_audio_u ? addslashes($_audio_u['title']) : null;
        $_audio_artist_u      = ($_audio_u && !empty($_audio_u['artist'])) ? addslashes($_audio_u['artist']) : null;
        $_music_vol_u         = ($_audio_u && $_audio_u['music_volume'] !== '' && $_audio_u['music_volume'] !== null) ? (int)$_audio_u['music_volume'] : 80;
        $_vid_vol_u           = ($_audio_u && $_audio_u['video_audio_volume'] !== '' && $_audio_u['video_audio_volume'] !== null) ? (int)$_audio_u['video_audio_volume'] : 40;

        // counts + interaction flags — from the batch cache
        $_counts_u = get_meme_counts($mid_u);
        $ulc = $_counts_u['like']; $urc = $_counts_u['repost']; $ufc = $_counts_u['fav']; $usc = $_counts_u['share'];
        $_inter_u = get_meme_interactions($mid_u, $current_user);
        $uli = $_inter_u['liked']; $uri = $_inter_u['reposted']; $ufi = $_inter_u['faved'];

        // FIX: Paid Partnership badge fields — Uploads is your own posts
        // list, so it reads straight from $u (not $row) like everything
        // else in this loop.
        $_is_partnership_u = !empty($u['is_partnership']) ? 'true' : 'false';
        $_partner_brand_u  = !empty($u['partner_brand']) ? "'".addslashes($u['partner_brand'])."'" : "''";

        // NEW: post-as-ad + AI-generated fields — same $u source as the
        // partnership fields directly above, for the same reason.
        $_is_ad_u          = !empty($u['is_ad']) ? 'true' : 'false';
        $_ad_cta_text_u    = !empty($u['ad_cta_text'])  ? "'".addslashes($u['ad_cta_text'])."'"  : 'null';
        $_ad_cta_url_u     = !empty($u['ad_cta_url'])   ? "'".addslashes($u['ad_cta_url'])."'"   : 'null';
        $_ad_cta_color_u   = !empty($u['ad_cta_color']) ? "'".addslashes($u['ad_cta_color'])."'" : 'null';
        $_is_ai_generated_u = !empty($u['is_ai_generated']) ? 'true' : 'false';
    ?>
    <script>uploadsFeed.push({id:<?=$mid_u?>,file:'<?=$smf?>',type:'<?=addslashes($u['type'])?>',pic:'<?=$sp?>',userId:<?=(int)$u['uploaded_by']?>,username:'<?=$su?>',caption:'<?=$sc?>',time:'<?=$st?>',isYou:true,likeCount:<?=(int)$ulc?>,repostCount:<?=(int)$urc?>,favCount:<?=(int)$ufc?>,shareCount:<?=(int)$usc?>,liked:<?=$uli?'true':'false'?>,reposted:<?=$uri?'true':'false'?>,faved:<?=$ufi?'true':'false'?>,isPinned:<?=$isPinned_u?'true':'false'?>,nickname:'<?=addslashes($_meta_u['nickname'])?>',isFollowing:<?=$_meta_u['is_following']?'true':'false'?>,socialProof:<?=json_encode($_meta_u['social_proof'])?>,media:<?=json_encode($_media_u)?>,context:'Uploads',groupId:<?= $_group_id_u !== null ? $_group_id_u : 'null' ?>,groupTitle:<?= $_group_name_u !== null ? "'".$_group_name_u."'" : 'null' ?>,connectedMemeId:<?= $_connected_id_u !== null ? $_connected_id_u : 'null' ?>,connectedLabel:<?= $_connected_label_u !== null ? "'".$_connected_label_u."'" : 'null' ?>,audioId:<?= $_audio_id_u !== null ? $_audio_id_u : 'null' ?>,audioIsOriginal:<?= $_audio_is_original_u ? 'true' : 'false' ?>,audioFile:<?= $_audio_file_u !== null ? "'".$_audio_file_u."'" : 'null' ?>,audioTitle:<?= $_audio_title_u !== null ? "'".$_audio_title_u."'" : 'null' ?>,audioArtist:<?= $_audio_artist_u !== null ? "'".$_audio_artist_u."'" : 'null' ?>,musicVolume:<?=$_music_vol_u?>,videoAudioVolume:<?=$_vid_vol_u?>,isPartnership:<?=$_is_partnership_u?>,partnerBrand:<?=$_partner_brand_u?>,isAd:<?=$_is_ad_u?>,adCtaText:<?=$_ad_cta_text_u?>,adCtaUrl:<?=$_ad_cta_url_u?>,adCtaColor:<?=$_ad_cta_color_u?>,isAiGenerated:<?=$_is_ai_generated_u?>});</script>
    <div class="meme-card <?=$is_new?'is-new':''?> <?=$is_fading?'is-fading':''?>" data-id="<?=$mid_u?>" data-ts="<?=(int)$created?>" data-pinned="<?=$isPinned_u?'1':'0'?>">
        <div class="top-bar">
            <img src="<?=$u['uploader_pic']?:'uploads/default.jpg'?>" loading="lazy" decoding="async">
            <div class="username"><?=($current_user==$profile_id)?"You":htmlspecialchars($user['username'])?></div>
        </div>
        <div class="media-box" style="position:relative;">
            <?php if($u['type']=="image"): ?><img src="uploads/<?=$sf?>" loading="lazy" decoding="async" onclick="openMemeModal('<?=$smf?>','<?=$u['type']?>','<?=$sc?>','<?=$sp?>','<?=$su?>',<?=$u['uploaded_by']?>,'uploadsFeed',<?=$mid_u?>)">
            <?php elseif($u['type']=="video"): ?><video class="lazy-video" data-src="uploads/<?=$sf?>" muted loop playsinline preload="none" onclick="openMemeModal('<?=$smf?>','<?=$u['type']?>','<?=$sc?>','<?=$sp?>','<?=$su?>',<?=$u['uploaded_by']?>,'uploadsFeed',<?=$mid_u?>)"></video>
            <?php else: ?><img src="uploads/Text.jpg" loading="lazy" decoding="async" onclick="openMemeModal('uploads/Text.jpg','image','<?=$sc?>','<?=$sp?>','<?=$su?>',<?=$u['uploaded_by']?>,'uploadsFeed',<?=$mid_u?>)">
            <?php endif; ?>
            <?php if($diff<=172800): ?><div class="new-badge">NEW</div><?php endif; ?>
            <?php if($isPinned_u): ?><div class="card-pin-indicator"><i class="ri-pushpin-fill"></i></div><?php endif; ?>
            <button class="card-add-col-btn" title="Add to Collection" onclick="event.stopPropagation();gOpenAddToCol(<?=$mid_u?>)"><i class="ri-folder-add-line"></i></button>
        </div>
        <div class="icon-bar">
            <form method="POST" onsubmit="return confirm('Are you sure you want to delete this meme?');">
                <input type="hidden" name="meme_id" value="<?=$mid_u?>">
                <button type="submit" name="delete_meme" title="Delete"><i class="ri-delete-bin-line"></i></button>
            </form>
            <button class="card-pin-btn<?=$isPinned_u?' pinned':''?>" data-meme-id="<?=$mid_u?>" title="<?=$isPinned_u?'Unpin':'Pin to profile'?>" onclick="event.stopPropagation();gTogglePin(<?=$mid_u?>,this)"><i class="ri-pushpin-<?=$isPinned_u?'fill':'line'?>"></i></button>
        </div>
        <div class="caption"><i class="ri-upload-2-line" style="color:#FF4C4C;"></i><?=htmlspecialchars($u['caption'])?></div>
    </div>
    <?php endforeach;?>
    <?php else:?>
    <div style="grid-column:1/-1;text-align:center;padding:40px 20px;color:#aaa;font-size:0.95rem;"><i class="ri-upload-cloud-2-line" style="font-size:2.5rem;display:block;margin-bottom:10px;color:#FF4C4C;"></i>No uploads yet</div>
    <?php endif;?>
    </div>
    <div class="col-section" id="uploadsColSec">
        <div id="uploadsColFolders">
            <div class="col-search-wrap" id="uploadsColSearchWrap">
                <i class="ri-search-line col-search-icon"></i>
                <input type="text" class="col-search-input" id="uploadsColSearch" placeholder="Search collections…" oninput="gSearchFolders('uploads',this.value)" autocomplete="off">
                <button class="col-search-clear" id="uploadsColSearchClear" onclick="gClearFolderSearch('uploads')">✕</button>
            </div>
            <div class="col-folders-grid" id="uploadsColGrid">
                <button class="col-new-btn" onclick="gOpenNameModal('create','uploads')"><i class="ri-folder-add-line"></i>New Collection</button>
            </div>
            <div class="col-search-no-results" id="uploadsColNoResults"><i class="ri-folder-search-line"></i>No collections match</div>
        </div>
        <div class="col-detail" id="uploadsColDetail">
            <div class="col-detail-hdr"><button class="col-back-btn" onclick="gCloseDetail('uploads')"><i class="ri-arrow-left-line"></i></button><div class="col-detail-title" id="uploadsColTitle">Collection</div><div class="col-detail-count" id="uploadsColCount">0 items</div></div>
            <div class="col-detail-search-wrap">
                <i class="ri-hashtag col-detail-search-icon"></i>
                <input type="text" class="col-detail-search-input" id="uploadsDetailSearch" placeholder="Search by caption or #hashtag…" oninput="gSearchDetailItems('uploadsColDetailGrid','uploadsDetailSearch','uploadsDetailNoResults')" autocomplete="off">
                <button class="col-detail-search-clear" id="uploadsDetailSearchClear" onclick="gClearDetailSearch('uploadsColDetailGrid','uploadsDetailSearch','uploadsDetailNoResults','uploadsDetailSearchClear')">✕</button>
            </div>
            <div class="col-detail-no-results" id="uploadsDetailNoResults"><i class="ri-search-eye-line"></i>No memes found</div>
            <div class="grid" id="uploadsColDetailGrid"></div>
        </div>
    </div>
</div>


<script>
// ══════════════════════════════════════════════════════════
// LAZY VIDEO PLAYBACK
// Fixes: (1) every video no longer force-loads/plays on page
// load — <video> now ships with preload="none" and a
// data-src instead of src, so the browser fetches nothing
// until the card is actually scrolled into view; (2) no more
// dozens of videos decoding/playing at once — playback is
// driven entirely by IntersectionObserver, so a video only
// gets its src assigned + play() called once it is inside the
// viewport, and gets pause()'d (source kept, so no re-buffer
// is required) the instant it scrolls out.
//
// This also solves the "hidden tabs still fetch everything"
// problem for video specifically: elements inside a
// display:none tab have no layout box, so the observer never
// reports them as intersecting — nothing loads until the tab
// is switched to AND the card is scrolled into view. Whatever
// toggleSection()/gSwitchTab() function flips each tab's
// display between 'none' and 'grid' does not need to change
// for this to work; it is driven purely by actual visibility.
// ══════════════════════════════════════════════════════════
(function() {
    if(!('IntersectionObserver' in window)) {
        // Fallback for very old browsers: just hydrate src, no autoplay.
        document.querySelectorAll('video.lazy-video[data-src]').forEach(function(v) {
            v.src = v.dataset.src;
        });
        return;
    }

    var videoObserver = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            var video = entry.target;
            if(entry.isIntersecting) {
                if(!video.src && video.dataset.src) {
                    video.src = video.dataset.src;
                }
                var playPromise = video.play();
                if(playPromise && playPromise.catch) playPromise.catch(function(){ /* autoplay blocked, ignore */ });
            } else {
                if(!video.paused) video.pause();
            }
        });
    }, { root: null, rootMargin: '150px 0px', threshold: 0.25 });

    function observeAllLazyVideos(root) {
        (root || document).querySelectorAll('video.lazy-video[data-src]').forEach(function(v) {
            if(!v.dataset.observed) {
                videoObserver.observe(v);
                v.dataset.observed = '1';
            }
        });
    }

    observeAllLazyVideos(document);

    // Any card injected later (e.g. Collections detail view rendered via JS)
    // gets picked up automatically too.
    var gridMutationObserver = new MutationObserver(function(mutations) {
        mutations.forEach(function(m) {
            m.addedNodes && m.addedNodes.forEach(function(node) {
                if(node.nodeType !== 1) return;
                if(node.matches && node.matches('video.lazy-video[data-src]')) {
                    videoObserver.observe(node);
                    node.dataset.observed = '1';
                } else if(node.querySelectorAll) {
                    observeAllLazyVideos(node);
                }
            });
        });
    });
    gridMutationObserver.observe(document.body, { childList: true, subtree: true });
})();
</script>

<!-- MESSAGES SECTION — LEFT BLANK AS REQUESTED, RESTORE YOUR OWN CODE HERE -->
<div id="messages" class="dropdown-section">

<?php if($current_user == $profile_id): ?>

<?php
// ── Fetch message requests count for profile page ──
$profile_req_count = 0;
$profile_requests  = [];

$req_res = $conn->query("
    SELECT 
        u.id, u.username, u.profile_pic,
        m.message AS last_message,
        m.file_attachment AS last_attachment,
        m.created_at AS last_time,
        m.sender_id,
        m.seen,
        (SELECT COUNT(*) FROM messages 
         WHERE sender_id = u.id AND receiver_id = $current_user AND seen = 0) AS unread_count
    FROM (
        SELECT 
            CASE WHEN sender_id = $current_user THEN receiver_id ELSE sender_id END AS other_user_id,
            MAX(id) AS last_msg_id
        FROM messages
        WHERE sender_id = $current_user OR receiver_id = $current_user
        GROUP BY other_user_id
    ) AS convos
    JOIN messages m ON m.id = convos.last_msg_id
    JOIN users u ON u.id = convos.other_user_id
    WHERE u.id IN (
        SELECT restricted_id FROM restricted_users WHERE restrictor_id = $current_user
    )
    ORDER BY m.created_at DESC
");

if ($req_res && $req_res->num_rows > 0) {
    $profile_req_count = $req_res->num_rows;
    while ($r = $req_res->fetch_assoc()) {
        $profile_requests[] = $r;
    }
}

// ── Collect restricted user IDs to exclude from normal list ──
$restricted_ids = array_column($profile_requests, 'id');
?>

<style>
/* ── MESSAGE FILTER BAR ── */
.msg-filter-bar {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
    padding: 0 2px 10px;
    margin-top: -15px;
}
.msg-pill {
    padding: 6px 15px;
    border-radius: 999px;
    border: 1px solid rgba(255,255,255,0.13);
    background: transparent;
    color: rgba(255,255,255,0.6);
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    font-family: Poppins, sans-serif;
    transition: all 0.22s ease;
    -webkit-tap-highlight-color: transparent;
    white-space: nowrap;
    position: relative;
    top: -2px;
}
.msg-pill:hover {
    border-color: rgba(255,45,45,0.5);
    color: white;
    background: rgba(255,45,45,0.08);
}
.msg-pill.active {
    background: rgba(255,45,45,0.18);
    border-color: rgba(255,45,45,0.6);
    color: #FF5566;
    box-shadow: 0 0 14px rgba(255,45,45,0.14);
}
.msg-pill .req-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #FF2D2D;
    color: white;
    font-size: 8px;
    font-weight: 800;
    min-width: 14px;
    height: 14px;
    border-radius: 999px;
    padding: 0 3px;
    margin-left: 5px;
    vertical-align: middle;
    line-height: 1;
}

/* ── SEARCH BAR ── */
.msg-search-wrap {
    position: relative;
    margin-bottom: 12px;
}
.msg-search-input {
    width: 100%;
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 12px;
    padding: 9px 14px 9px 36px;
    color: white;
    font-size: 12px;
    font-family: Poppins, sans-serif;
    outline: none;
    transition: border-color 0.2s, background 0.2s;
    box-sizing: border-box;
}
.msg-search-input:focus {
    border-color: rgba(255,45,45,0.4);
    background: rgba(255,255,255,0.07);
}
.msg-search-input::placeholder { color: rgba(255,255,255,0.25); }
.msg-search-icon {
    position: absolute;
    left: 11px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 14px;
    color: rgba(255,255,255,0.3);
    pointer-events: none;
}
.msg-search-clear {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 13px;
    color: rgba(255,255,255,0.3);
    cursor: pointer;
    display: none;
    background: none;
    border: none;
    padding: 0;
    line-height: 1;
}
.msg-search-clear:hover { color: rgba(255,255,255,0.7); }
.msg-search-no-results {
    text-align: center;
    padding: 20px;
    color: rgba(255,255,255,0.3);
    font-size: 12px;
    display: none;
}
.msg-search-no-results i {
    font-size: 1.6rem;
    display: block;
    margin-bottom: 6px;
    color: rgba(255,85,102,0.4);
}

/* ── MESSAGE LIST ── */
.msg-list { display: flex; flex-direction: column; }

/* empty state */
.msg-empty {
    text-align: center;
    padding: 40px 20px;
    color: #aaa;
    font-size: 0.95rem;
}
.msg-empty i {
    font-size: 2.5rem;
    display: block;
    margin-bottom: 10px;
    color: #FF4C4C;
}

/* ── REQUEST ITEMS ── */
.msg-request-item {
    display: flex;
    align-items: center;
    gap: 12px;
    background: rgba(255,45,45,0.05);
    border: 1px solid rgba(255,45,45,0.15);
    padding: 10px 15px;
    border-radius: 16px;
    margin-bottom: 8px;
    transition: background 0.2s;
}
.msg-request-item:hover { background: rgba(255,45,45,0.1); }
.msg-request-item img {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid rgba(255,45,45,0.4);
    flex-shrink: 0;
    cursor: pointer;
}
.msg-request-info { flex: 1; min-width: 0; cursor: pointer; }
.msg-request-name {
    font-size: 13px;
    font-weight: 600;
    color: white;
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 2px;
    flex-wrap: wrap;
}
.msg-request-tag {
    font-size: 9px;
    font-weight: 700;
    padding: 2px 6px;
    border-radius: 6px;
    background: rgba(255,45,45,0.15);
    border: 1px solid rgba(255,45,45,0.3);
    color: #ff5566;
    letter-spacing: 0.3px;
}
.msg-request-preview {
    font-size: 11px;
    color: rgba(255,255,255,0.4);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.msg-request-actions {
    display: flex;
    flex-direction: column;
    gap: 5px;
    flex-shrink: 0;
}
.msg-req-accept {
    padding: 5px 12px;
    border-radius: 8px;
    background: rgba(77,222,138,0.15);
    border: 1px solid rgba(77,222,138,0.35);
    color: #4dde8a;
    font-size: 10px;
    font-weight: 700;
    cursor: pointer;
    font-family: Poppins, sans-serif;
    transition: background 0.2s;
    white-space: nowrap;
}
.msg-req-accept:hover { background: rgba(77,222,138,0.25); }
.msg-req-decline {
    padding: 5px 12px;
    border-radius: 8px;
    background: rgba(255,45,45,0.1);
    border: 1px solid rgba(255,45,45,0.25);
    color: rgba(255,255,255,0.4);
    font-size: 10px;
    font-weight: 700;
    cursor: pointer;
    font-family: Poppins, sans-serif;
    transition: background 0.2s;
    white-space: nowrap;
}
.msg-req-decline:hover { background: rgba(255,45,45,0.18); color: #ff5566; }

/* ── REQUESTS SECTION ── */
.msg-requests-section { display: none; }
.msg-requests-section.active { display: block; }
.msg-requests-header {
    font-size: 10px;
    font-weight: 700;
    color: rgba(255,255,255,0.3);
    text-transform: uppercase;
    letter-spacing: 1px;
    padding: 0 2px 10px;
    display: flex;
    align-items: center;
    gap: 5px;
}

/* ── RESPONSIVE ── */
@media (max-width: 480px) {
    .msg-filter-bar { gap: 5px; }
    .msg-pill { padding: 5px 11px; font-size: 10px; }
    .msg-request-item { padding: 9px 12px; gap: 10px; }
    .msg-request-item img { width: 38px; height: 38px; }
    .msg-req-accept, .msg-req-decline { padding: 4px 10px; font-size: 9px; }
    .msg-search-input { font-size: 11px; padding: 8px 12px 8px 32px; }
    .msg-search-icon { font-size: 13px; left: 10px; }
}
@media (max-width: 360px) {
    .msg-pill { padding: 4px 9px; font-size: 9px; }
}
</style>

<!-- ── FILTER PILLS ── -->
<div class="msg-filter-bar">
    <button class="msg-pill active" id="mpAll"     onclick="msgFilter('all')">All</button>
    <button class="msg-pill" id="mpUnread"         onclick="msgFilter('unread')">
        <i class="ri-mail-unread-line" style="margin-right:3px;font-size:10px;"></i>Unread
    </button>
    <button class="msg-pill" id="mpDry"            onclick="msgFilter('dry')">
        <i class="ri-zzz-line" style="margin-right:3px;font-size:10px;"></i>Dry Chat
    </button>
    <?php if($profile_req_count > 0): ?>
    <button class="msg-pill" id="mpRequests"       onclick="msgFilter('requests')">
        <i class="ri-lock-line" style="margin-right:3px;font-size:10px;"></i>Requests
        <span class="req-badge"><?= $profile_req_count ?></span>
    </button>
    <?php endif; ?>
</div>

<!-- ── SEARCH BAR ── -->
<div class="msg-search-wrap" id="msgSearchWrap">
    <i class="ri-search-line msg-search-icon"></i>
    <input type="text"
           class="msg-search-input"
           id="msgSearchInput"
           placeholder="Search messages…"
           autocomplete="off"
           oninput="msgSearch(this.value)">
    <button class="msg-search-clear" id="msgSearchClear" onclick="msgClearSearch()">✕</button>
</div>
<div class="msg-search-no-results" id="msgSearchNoResults">
    <i class="ri-search-eye-line"></i>No conversations found
</div>

<!-- ── REQUESTS SECTION ── -->
<?php if($profile_req_count > 0): ?>
<div class="msg-requests-section" id="msgRequestsSection">
    <div class="msg-requests-header">
        <i class="ri-lock-line" style="font-size:10px;"></i>
        Message Requests — only you can see these
    </div>
    <?php foreach($profile_requests as $r):
        $r_preview = $r['last_message'] ?? '';
        $r_unread  = (int)$r['unread_count'];
        $r_attach  = $r['last_attachment'] ?? '';
    ?>
    <div class="msg-request-item"
         id="msgReq-<?= $r['id'] ?>"
         data-username="<?= strtolower(htmlspecialchars($r['username'])) ?>">
        <img src="<?= htmlspecialchars($r['profile_pic'] ?? 'uploads/default.jpg') ?>"
             onerror="this.src='uploads/default.jpg'"
             onclick="event.stopPropagation();window.location.href='messages.php?user=<?= $r['id'] ?>'">
        <div class="msg-request-info"
             onclick="window.location.href='messages.php?user=<?= $r['id'] ?>'">
            <div class="msg-request-name">
                <?= htmlspecialchars($r['username']) ?>
                <span class="msg-request-tag">Request</span>
                <?php if($r_unread > 0): ?>
                <span style="background:#FF4C4C;color:white;font-size:8px;font-weight:700;padding:1px 5px;border-radius:999px;"><?= $r_unread ?></span>
                <?php endif; ?>
            </div>
            <div class="msg-request-preview">
                <?php
                if(!empty($r_attach)) {
                    $ext = strtolower(pathinfo($r_attach, PATHINFO_EXTENSION));
                    if(in_array($ext,['jpg','jpeg','png','gif','webp']))    echo '📷 Photo';
                    elseif(in_array($ext,['mp4','webm','mov','avi']))       echo '🎥 Video';
                    elseif(in_array($ext,['mp3','aac','ogg','wav']))        echo '🎵 Audio';
                    else echo '📎 File';
                } elseif(trim($r_preview) === '[deleted]') {
                    echo '<i style="color:rgba(255,255,255,.25);">Message deleted</i>';
                } else {
                    echo htmlspecialchars(mb_substr($r_preview, 0, 45)) . (mb_strlen($r_preview) > 45 ? '…' : '');
                }
                ?>
            </div>
        </div>
        <div class="msg-request-actions">
            <button class="msg-req-accept"
                    onclick="event.stopPropagation();msgAcceptRequest(<?= $r['id'] ?>, this)">
                Accept
            </button>
            <button class="msg-req-decline"
                    onclick="event.stopPropagation();msgDeclineRequest(<?= $r['id'] ?>, this)">
                Decline
            </button>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── NORMAL MESSAGES LIST ── -->
<div class="msg-list" id="msgList">

<?php
$all_messages = [];
if($messages && $messages->num_rows > 0):
    while($m = $messages->fetch_assoc()):
        // Skip restricted users — they only appear in Requests tab
        if(in_array($m['id'], $restricted_ids)) continue;
        $all_messages[] = $m;
    endwhile;
endif;
?>

<?php if(empty($all_messages)): ?>
    <div class="msg-empty" id="msgEmptyState">
        <i class="ri-chat-1-line"></i>
        No messages yet
    </div>
<?php else: ?>

<?php foreach($all_messages as $m):
    $has_unread = ($m['sender_id'] != $current_user && isset($m['unread_count']) && $m['unread_count'] > 0);
    $show_seen  = ($m['sender_id'] == $current_user && isset($m['seen']) && $m['seen'] == 1);
    $msg_count  = isset($m['message_count']) ? (int)$m['message_count'] : 0;
    $last_ts    = isset($m['last_message_time']) ? strtotime($m['last_message_time']) : 0;
    $inactive   = ($last_ts > 0 && (time() - $last_ts) > (7 * 86400));
?>
<div class="message-user"
     data-unread="<?= $has_unread ? '1' : '0' ?>"
     data-count="<?= $msg_count ?>"
     data-ts="<?= $last_ts ?>"
     data-inactive="<?= $inactive ? '1' : '0' ?>"
     data-username="<?= strtolower(htmlspecialchars($m['username'] ?? '')) ?>"
     onclick="window.location.href='messages.php?user=<?= $m['id'] ?>'">

    <img src="<?= htmlspecialchars($m['profile_pic'] ?? 'uploads/default.jpg') ?>"
         onerror="this.src='uploads/default.jpg'">

    <div class="message-user-info">
        <div class="message-user-name">
            <svg xmlns="http://www.w3.org/2000/svg" width="7" height="7" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="12" fill="#FF0000"/>
                <path d="M9 12l2 2 4-4" stroke="white" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <?= htmlspecialchars($m['username']) ?>
            <?php if($show_seen): ?>
                <span style="color:#227cd6;font-size:10px;font-weight:600;margin-left:4px;letter-spacing:.2px;">Seen</span>
            <?php endif; ?>
            <?php if($has_unread): ?>
                <span style="background:#FF4C4C;color:white;font-size:9px;font-weight:700;padding:1px 6px;border-radius:999px;margin-left:4px;"><?= (int)$m['unread_count'] ?></span>
            <?php endif; ?>
        </div>
        <div class="message-user-preview">
        <?php
            $preview    = $m['last_message'];
            $attachment = $m['last_attachment'] ?? '';
            $is_sent    = $m['sender_id'] == $current_user;
            $prefix     = $is_sent ? '<span style="color:#d62222;font-weight:500;">You:</span> ' : '';

            if(!empty($attachment)){
                $ext = strtolower(pathinfo($attachment, PATHINFO_EXTENSION));
                if(in_array($ext,['jpg','jpeg','png','gif','webp']))      $ml='<i class="ri-image-line" style="color:#2231d6;font-size:13px;vertical-align:-2px;"></i> Photo';
                elseif(in_array($ext,['mp4','webm','mov','avi']))         $ml='<i class="ri-video-line" style="color:#2231d6;font-size:13px;vertical-align:-2px;"></i> Video';
                elseif(in_array($ext,['mp3','aac','ogg','wav']))          $ml='<i class="ri-music-2-line" style="color:#2231d6;font-size:13px;vertical-align:-2px;"></i> Audio';
                else                                                       $ml='<i class="ri-file-line" style="color:#2231d6;font-size:13px;vertical-align:-2px;"></i> File';
                if(!empty(trim($preview)) && trim($preview) !== '[deleted]') echo $prefix.$ml.' · '.htmlspecialchars(mb_substr($preview,0,30));
                else echo $prefix.$ml;
            } elseif(trim($preview)==='[deleted]'){
                echo $prefix.'<span style="color:rgba(255,255,255,.3);font-style:italic;">Message deleted</span>';
            } else {
                echo $prefix.htmlspecialchars(mb_substr($preview,0,50)).(mb_strlen($preview)>50?'…':'');
            }
        ?>
        </div>
    </div>

    <?php if($inactive && !$has_unread): ?>
        <span style="font-size:9px;color:rgba(255,255,255,0.25);font-weight:600;letter-spacing:0.3px;flex-shrink:0;">DRY</span>
    <?php endif; ?>

</div>
<?php endforeach; ?>

<div class="msg-empty" id="msgFilterEmpty" style="display:none;">
    <i class="ri-inbox-line"></i>
    <span id="msgFilterEmptyText">Nothing here</span>
</div>

<?php endif; ?>
</div>

<script>
var _msgFilter  = 'all';
var _msgQuery   = '';

function msgFilter(type) {
    _msgFilter = type;
    _msgQuery  = '';

    // Reset search
    var si = document.getElementById('msgSearchInput');
    var sc = document.getElementById('msgSearchClear');
    var nr = document.getElementById('msgSearchNoResults');
    if(si) si.value = '';
    if(sc) sc.style.display = 'none';
    if(nr) nr.style.display = 'none';

    // Update pills
    ['All','Unread','Active','Dry','Requests'].forEach(function(k){
        var p = document.getElementById('mp'+k);
        if(p) p.classList.remove('active');
    });
    var activeMap = {all:'All', unread:'Unread', active:'Active', dry:'Dry', requests:'Requests'};
    var ap = document.getElementById('mp' + activeMap[type]);
    if(ap) ap.classList.add('active');

    // Requests tab — show requests, hide normal list + search
    var reqSec = document.getElementById('msgRequestsSection');
    var list   = document.getElementById('msgList');
    var searchWrap = document.getElementById('msgSearchWrap');

    if(type === 'requests') {
        if(reqSec) reqSec.classList.add('active');
        if(list) list.style.display = 'none';
        if(searchWrap) searchWrap.style.display = 'none';
        return;
    }

    // Normal tabs — hide requests, show list + search
    if(reqSec) reqSec.classList.remove('active');
    if(list) list.style.display = 'flex';
    if(searchWrap) searchWrap.style.display = '';

    var items   = document.querySelectorAll('#msgList .message-user');
    var visible = 0;

    items.forEach(function(el) {
        var unread   = el.dataset.unread === '1';
        var count    = parseInt(el.dataset.count) || 0;
        var inactive = el.dataset.inactive === '1';
        var show     = false;

        if(type === 'all')    show = true;
        if(type === 'unread') show = unread;
        if(type === 'active') show = count >= 10;
        if(type === 'dry')    show = inactive;

        el.style.display = show ? '' : 'none';
        if(show) visible++;
    });

    var empty = document.getElementById('msgFilterEmpty');
    if(empty) {
        empty.style.display = (visible === 0) ? 'block' : 'none';
        var txt = document.getElementById('msgFilterEmptyText');
        if(txt) {
            var msgs = {
                all:    'No messages yet',
                unread: 'No unread messages',
                active: 'No highly active chats',
                dry:    'No dry chats — keep the conversation going!'
            };
            txt.textContent = msgs[type] || 'Nothing here';
        }
    }
}

function msgSearch(query) {
    _msgQuery = query.trim().toLowerCase();
    var sc = document.getElementById('msgSearchClear');
    var nr = document.getElementById('msgSearchNoResults');
    if(sc) sc.style.display = _msgQuery ? 'block' : 'none';

    // If in requests tab, search request items
    var isRequests = _msgFilter === 'requests';

    if(isRequests) {
        var reqItems = document.querySelectorAll('.msg-request-item');
        var visible  = 0;
        reqItems.forEach(function(el) {
            var name = el.dataset.username || '';
            var show = !_msgQuery || name.includes(_msgQuery);
            el.style.display = show ? '' : 'none';
            if(show) visible++;
        });
        if(nr) nr.style.display = (_msgQuery && visible === 0) ? 'block' : 'none';
        return;
    }

    // Search normal list
    var items   = document.querySelectorAll('#msgList .message-user');
    var visible = 0;

    items.forEach(function(el) {
        var name = el.dataset.username || '';

        // Respect active filter AND search query together
        var passesFilter = false;
        var unread   = el.dataset.unread === '1';
        var count    = parseInt(el.dataset.count) || 0;
        var inactive = el.dataset.inactive === '1';

        if(_msgFilter === 'all')    passesFilter = true;
        if(_msgFilter === 'unread') passesFilter = unread;
        if(_msgFilter === 'active') passesFilter = count >= 10;
        if(_msgFilter === 'dry')    passesFilter = inactive;

        var passesSearch = !_msgQuery || name.includes(_msgQuery);
        var show = passesFilter && passesSearch;

        el.style.display = show ? '' : 'none';
        if(show) visible++;
    });

    var empty = document.getElementById('msgFilterEmpty');
    if(empty) empty.style.display = (visible === 0) ? 'block' : 'none';
    if(nr) nr.style.display = (_msgQuery && visible === 0) ? 'block' : 'none';
}

function msgClearSearch() {
    var si = document.getElementById('msgSearchInput');
    if(si) { si.value = ''; si.focus(); }
    msgSearch('');
}

function msgAcceptRequest(userId, btn) {
    btn.disabled = true;
    btn.textContent = '…';
    fetch('messages.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'accept_request=1&from_user=' + userId
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if(d.success) {
            var item = document.getElementById('msgReq-' + userId);
            if(item) {
                item.style.transition = 'opacity 0.3s, transform 0.3s';
                item.style.opacity    = '0';
                item.style.transform  = 'translateX(20px)';
                setTimeout(function(){
                    item.remove();
                    // Redirect to the now-accepted chat
                    window.location.href = 'messages.php?user=' + userId;
                }, 300);
            }
            _updateReqBadge(-1);
        }
    })
    .catch(function(){ btn.disabled = false; btn.textContent = 'Accept'; });
}

function msgDeclineRequest(userId, btn) {
    if(!confirm('Delete all messages from this user?')) return;
    btn.disabled = true;
    btn.textContent = '…';
    fetch('messages.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'decline_request=1&from_user=' + userId
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if(d.success) {
            var item = document.getElementById('msgReq-' + userId);
            if(item) {
                item.style.transition = 'opacity 0.3s, transform 0.3s';
                item.style.opacity    = '0';
                item.style.transform  = 'translateX(-20px)';
                setTimeout(function(){ item.remove(); }, 300);
            }
            _updateReqBadge(-1);
        }
    })
    .catch(function(){ btn.disabled = false; btn.textContent = 'Decline'; });
}

function _updateReqBadge(delta) {
    var badge = document.querySelector('#mpRequests .req-badge');
    if(!badge) return;
    var count = parseInt(badge.textContent) + delta;
    if(count <= 0) {
        var btn = document.getElementById('mpRequests');
        if(btn) btn.remove();
        msgFilter('all');
    } else {
        badge.textContent = count;
    }
}
</script>

<?php else: ?>
<div style="text-align:center;padding:40px 20px;color:#aaa;font-size:0.95rem;">
    <i class="ri-chat-1-line" style="font-size:2.5rem;display:block;margin-bottom:10px;color:#FF4C4C;"></i>
    No messages yet
</div>
<?php endif; ?>

</div>




<!-- ================================================================
     IMMERSIVE POST VIEWER  (Instagram-style single-post scroll feed)
     v5 — updates on top of v4:
       - NEW: double-tap-to-like on post media (image/video/text-only),
         with the same red heart-burst + expanding ring + scattering
         mini-hearts animation used in the reel modal. Single tap still
         opens the reel modal (with a short delay so it can tell single
         from double tap); double tap only ever LIKES (never unlikes)
         and never opens the reel.
       - FIX: "Liked by ..." bottom sheet now fetches the FULL like and
         repost lists for the post via AJAX (new `get_meme_social`
         action — add the PHP block noted at the bottom of this file to
         profile.php) instead of reusing each post's `socialProof`
         array. socialProof is a mutuals-only, likes-first, max-4-total
         list built for the small floating avatar strip on the media —
         it was never a full list, which is why reposters almost never
         showed up in the Reposts tab. The sheet still shows the old
         capped data instantly for a snappy open, then swaps in the
         full lists the moment the fetch resolves.
     ----------------------------------------------------------------
     WHERE THIS GOES: paste this ENTIRE block right before </body>
     in profile.php — after every other <script> on the page.
     ================================================================ -->
<div id="ipvOverlay" class="ipv-overlay">
    <div class="ipv-topbar">
        <button class="ipv-back-btn" onclick="ipvClose()" aria-label="Back">
            <i class="ri-arrow-left-s-line"></i>
        </button>
        <div class="ipv-topbar-title" id="ipvTopbarTitle">Posts</div>
        <div class="ipv-topbar-action" id="ipvTopbarAction"></div>
    </div>
    <div class="ipv-list" id="ipvList"></div>
    <audio id="ipvAudioPlayer" loop muted style="display:none"></audio>
</div>

<!-- likers bottom sheet -->
<div id="ipvLikersOverlay" class="ipv-likers-overlay" onclick="if(event.target===this)ipvCloseLikersSheet()">
    <div class="ipv-likers-panel">
        <div class="ipv-likers-handle"></div>
        <div class="ipv-likers-tabs" id="ipvLikersTabs">
            <button class="ipv-likers-tab active" data-tab="likes" onclick="ipvSwitchLikersTab(this,'likes')">Likes</button>
            <button class="ipv-likers-tab" data-tab="reposts" onclick="ipvSwitchLikersTab(this,'reposts')">Reposts</button>
        </div>
        <div class="ipv-likers-list" id="ipvLikersList"></div>
    </div>
</div>

<style>
/* ============================================================
   IMMERSIVE POST VIEWER — styles
   Colours now match the home page:
     lane (post column + top bar) = #070924
     page behind the lane         = same gradient as home body
     dark line between posts      = #02030e, 3px
   ============================================================ */
.ipv-overlay,
.ipv-likers-overlay{
    --ipv-lane:#070924;
    --ipv-sep:#02030e;
}

.ipv-overlay{
    display:none;
    position:fixed; inset:0;
    z-index:999998;
    background:linear-gradient(-45deg,#010218,#070924,#070924);
    overflow-y:scroll;
    -webkit-overflow-scrolling:touch;
    opacity:0;
    transform:translateY(14px);
    transition:opacity .22s ease, transform .26s cubic-bezier(.22,1,.36,1);
    font-family:'Poppins',sans-serif;
    scrollbar-width:auto;
    scrollbar-color:rgba(255,255,255,.35) rgba(255,255,255,.05);
}
.ipv-overlay.ipv-open{ display:block; opacity:1; transform:translateY(0); }
.ipv-overlay::-webkit-scrollbar{ width:9px; }
.ipv-overlay::-webkit-scrollbar-track{ background:rgba(255,255,255,.04); }
.ipv-overlay::-webkit-scrollbar-thumb{ background:rgba(255,255,255,.28); border-radius:10px; border:2px solid transparent; background-clip:padding-box; }
.ipv-overlay::-webkit-scrollbar-thumb:hover{ background:rgba(255,255,255,.5); background-clip:padding-box; }

/* top bar — exactly the same colour as the feed background (no blur, no border) */
.ipv-topbar{
    display:flex; align-items:center; justify-content:space-between;
    padding:7px 16px;
    position:sticky; top:0; z-index:50;
    background:var(--ipv-lane);
    max-width:470px;
    margin:0 auto;
}
.ipv-back-btn{
    background:none; border:none; color:#fff; display:flex; align-items:center;
    cursor:pointer; padding:2px; margin-left:-6px; -webkit-tap-highlight-color:transparent;
}
.ipv-back-btn i{ font-size:30px; }
.ipv-topbar-title{ font-size:15px; font-weight:700; color:#fff; letter-spacing:-.2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:70vw; }

.ipv-topbar-title-two-line{
    display:flex; flex-direction:row; align-items:center; gap:5px;
    max-width:70vw; min-width:0; overflow:hidden;
}
.ipv-topbar-username-sm{
    font-size:13.5px; font-weight:600; color:rgba(220,224,255,.6);
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    min-width:0; flex:0 1 auto;
}
.ipv-topbar-sep{ color:rgba(255,255,255,.32); font-size:12px; flex-shrink:0; line-height:1; }
.ipv-topbar-noun{
    font-size:13.5px; font-weight:700; color:#fff; letter-spacing:-.2px;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    flex-shrink:0;
}

.ipv-topbar-action{ min-width:26px; flex-shrink:0; display:flex; align-items:center; justify-content:flex-end; }

.ipv-track-btn.ipv-topbar-track-btn{
    background:none;
    border:1.7px solid var(--red,#FF2D2D);
    border-radius:8px;
    padding:5px 12px;
    color:var(--red,#FF2D2D);
    font-size:12.5px; font-weight:700;
    cursor:pointer; font-family:inherit;
    text-decoration:none !important;
    position:static;
    white-space:nowrap;
    -webkit-tap-highlight-color:transparent;
}

/* the post column — same lane colour as the home feed */
.ipv-list{
    max-width:470px; margin:0 auto; padding-bottom:50px;
    background:var(--ipv-lane);
    min-height:calc(100vh - 47px);
}

/* desktop: thin borders either side of the column, like the home page */
@media(min-width:769px){
    .ipv-topbar,
    .ipv-list{
        border-left:1px solid var(--border,rgba(255,255,255,.07));
        border-right:1px solid var(--border,rgba(255,255,255,.07));
    }
}

/* ── POST + DARK SEPARATOR LINES (same system as home) ── */
.ipv-post{
    border-bottom:3px solid var(--ipv-sep);
    padding-bottom:12px;
    margin-bottom:0;
    scroll-margin-top:48px;
}
/* dark line above the FIRST post (under the top bar) — skipped when that post is a long one whose header floats on the media */
.ipv-list>.ipv-post:first-child:not(.ipv-long-header),
.ipv-list>.ipv-skel-post:first-child{ border-top:3px solid var(--ipv-sep); }
/* long posts (header sits on the media): no dark line above them — the line belongs to the post right before */
.ipv-post:has(+ .ipv-post.ipv-long-header){ border-bottom:none; }

.ipv-header{ display:flex; align-items:center; gap:10px; padding:11px 12px; }
.ipv-avatar{
    width:34px; height:34px; border-radius:50%; object-fit:cover;
    border:1.6px solid var(--red,#FF2D2D); cursor:pointer; flex-shrink:0;
}
.ipv-header-info{ flex:1; min-width:0; display:flex; flex-direction:column; }
.ipv-username-row{ display:flex; align-items:center; gap:6px; min-width:0; margin-bottom:2px; }
.ipv-username{
    font-size:13px; font-weight:700; color:#fff; cursor:pointer;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.ipv-new-tag{ color:var(--red,#FF2D2D); font-size:11px; font-weight:800; flex-shrink:0; }
.ipv-dot-sep{ color:rgba(255,255,255,.35); font-size:12px; flex-shrink:0; }
.ipv-dot-sep-red{ color:var(--red,#FF2D2D); font-size:13px; flex-shrink:0; line-height:1; }

.ipv-track-btn{
    background:none; border:none; padding:0; color:var(--red,#FF2D2D);
    font-size:12px; font-weight:700; cursor:pointer; font-family:inherit; flex-shrink:0;
    text-decoration:underline; text-decoration-color:var(--red,#FF2D2D);
    text-underline-offset:2px; text-decoration-thickness:1.5px;
    position:relative; top:-1px;
}

.ipv-info-switcher{ position:relative; min-height:14px; }
.ipv-switch-pane{ position:absolute; left:0; top:0; right:0; opacity:0; pointer-events:none; transition:opacity .35s ease; }
.ipv-switch-pane.active{ position:relative; opacity:1; pointer-events:all; }
.ipv-sub-line{ font-size:11px; color:rgba(220,224,255,.55); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.ipv-sub-line a{ color:var(--red,#FF2D2D); text-decoration:none; font-weight:600; }
.ipv-audio-tag{ display:flex; align-items:center; gap:6px; }
.ipv-audio-icon{ display:inline-flex; align-items:flex-end; gap:2px; height:9px; flex-shrink:0; }
.ipv-eq-bar{ width:2px; border-radius:2px; background:rgba(255,255,255,.65); display:inline-block; transform-origin:bottom; animation:ipvEq .9s ease-in-out infinite; }
.ipv-eq-bar:nth-child(1){ height:9px; animation-delay:-.6s; }
.ipv-eq-bar:nth-child(2){ height:9px; animation-delay:-.2s; }
.ipv-eq-bar:nth-child(3){ height:9px; animation-delay:-.9s; }
@keyframes ipvEq{ 0%{transform:scaleY(.35);} 25%{transform:scaleY(1);} 50%{transform:scaleY(.5);} 75%{transform:scaleY(.85);} 100%{transform:scaleY(.35);} }
.ipv-audio-text{ font-size:11px; font-weight:500; color:rgba(220,224,255,.6); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:200px; }

.ipv-kebab{
    background:none; border:none; color:#fff; font-size:22px;
    cursor:pointer; padding:6px 4px 6px 10px; margin-right:-6px; flex-shrink:0; -webkit-tap-highlight-color:transparent;
    position:relative; top:-3px;
    display:flex; align-items:center; justify-content:center;
    line-height:1;
}
.ipv-kebab i{ display:inline-block; transform:scaleX(1.18); }
.ipv-kebab:active{ transform:scale(1.08); }

/* Facebook-style caption under the header (default for normal posts) */
.ipv-header-caption{
    padding:0 12px 9px; font-size:12.5px; line-height:1.5; color:#fff; word-break:break-word;
}
.ipv-post.ipv-long-header .ipv-header-caption{ display:none; }

.ipv-post.ipv-long-header{ position:relative; }
.ipv-post.ipv-long-header .ipv-header{
    position:absolute; top:0; left:0; right:0; z-index:8;
    padding-bottom:22px;
    background:linear-gradient(180deg, rgba(0,0,0,.68) 0%, rgba(0,0,0,.34) 55%, transparent 100%);
}
.ipv-post.ipv-long-header .ipv-username,
.ipv-post.ipv-long-header .ipv-sub-line,
.ipv-post.ipv-long-header .ipv-audio-text,
.ipv-post.ipv-long-header .ipv-kebab{ color:#fff; text-shadow:0 1px 4px rgba(0,0,0,.5); }

.ipv-post.ipv-long-header .ipv-top-left-tags,
.ipv-post.ipv-long-header .ipv-top-right-tags{ top:58px; }

/* ── media carousel ──
   touch-action:pan-y → the browser only handles UP/DOWN scrolling on the media.
   Sideways gestures are left to the carousel script (dead-zone + lock),
   so a thumb that is scrolling the feed can never drag the slides around. */
.ipv-media-wrap{
    position:relative; width:100%; background:#000; overflow:hidden;
    aspect-ratio:4/4.2;
    transition:height .18s ease;
    touch-action:pan-y;
}
.ipv-media-track{ display:flex; height:100%; transition:transform .28s cubic-bezier(.22,1,.36,1); will-change:transform; }
.ipv-media-item{ flex:0 0 100%; height:100%; position:relative; background:#000; }
.ipv-media-item img,
.ipv-media-item video{
    width:100%; height:100%; object-fit:cover; display:block; cursor:pointer;
    -webkit-user-drag:none; user-select:none; -webkit-user-select:none;
}

.ipv-media-wrap.ipv-multi{ aspect-ratio:4/3.5; }

/* multi-media posts: each slide FITS inside the fixed box (contain); JS adds .ipv-fit-cover when close enough to fill */
.ipv-media-wrap.ipv-multi .ipv-media-item{ display:flex; align-items:center; justify-content:center; }
.ipv-media-wrap.ipv-multi .ipv-media-item img,
.ipv-media-wrap.ipv-multi .ipv-media-item video{
    width:100%; height:100%;
    object-fit:contain;
    background:#000;
}
.ipv-media-wrap.ipv-multi .ipv-media-item img.ipv-fit-cover,
.ipv-media-wrap.ipv-multi .ipv-media-item video.ipv-fit-cover{
    object-fit:cover;
}

/* ── TEXT POSTS (same design as home) ──
   Text sits on the lane colour. The "Text" tag is in the right corner of the text area;
   a small invisible spacer (.ipv-tag-gap) floats in the right corner of the FIRST line only,
   so a word never runs into the tag. Line 2 onwards is full width.
   Avatars / CTA / partnership tag sit UNDER the text, in the flow. */
.ipv-media-wrap.ipv-textonly{
    aspect-ratio:auto; min-height:130px;
    background:var(--ipv-lane);
    display:flex; flex-direction:column;
}
.ipv-media-wrap.ipv-textonly .ipv-social-proof-strip,
.ipv-media-wrap.ipv-textonly .ipv-cta-pill,
.ipv-media-wrap.ipv-textonly .ipv-partnership-tag{
    position:relative; left:auto; bottom:auto; align-self:flex-start; margin:0 18px 20px;
}
.ipv-textonly-clickable{
    flex:1 1 auto; display:flex; align-items:flex-start; justify-content:flex-start;
    width:100%; padding:14px 18px 18px; cursor:pointer;
}
.ipv-media-wrap.ipv-textonly.ipv-tl .ipv-textonly-clickable{ padding-top:70px; } /* room for the group / AI tags on the top-left */
.ipv-media-wrap.ipv-textonly .ipv-top-right-tags{ top:12px; right:14px; }
.ipv-center-caption-wrap{ width:100%; display:block; text-align:left; }
.ipv-tag-gap{ display:block; float:right; width:70px; height:1em; font-size:12.5px; pointer-events:none; }
.ipv-center-caption-text{
    color:#fff; font-size:12.5px; font-weight:400; line-height:1.6;
    max-width:100%; word-break:break-word; text-align:left;
}
/* hairline grey lines on text posts — under the header and above the like / repost / save row */
.ipv-post.ipv-post-text .ipv-header{ border-bottom:1px solid rgba(255,255,255,.13); }
.ipv-post.ipv-post-text .ipv-actions{ border-top:1px solid rgba(255,255,255,.13); }

.ipv-dots{
    position:absolute; left:0; right:0; bottom:10px; z-index:6;
    display:flex; align-items:center; justify-content:center;
    gap:5px; padding:0; flex-wrap:wrap; row-gap:5px;
}
.ipv-dot{ width:5px; height:5px; border-radius:50%; background:rgba(255,255,255,.35); box-shadow:0 0 3px rgba(0,0,0,.65); transition:background .18s, transform .18s; flex-shrink:0; }
.ipv-dot.active{ background:var(--red,#FF2D2D); transform:scale(1.3); }

.ipv-media-arrow{
    position:absolute; top:50%; transform:translateY(-50%); z-index:6;
    width:32px; height:32px; border-radius:50%;
    background:rgba(0,0,0,.42); border:none; color:#fff;
    display:flex; align-items:center; justify-content:center; cursor:pointer;
    -webkit-tap-highlight-color:transparent;
}
.ipv-media-arrow i{ font-size:20px; }
.ipv-arrow-left{ left:10px; }
.ipv-arrow-right{ right:10px; }

.ipv-social-proof-strip{
    position:absolute; left:12px; bottom:30px; z-index:6;
    display:flex; flex-direction:row-reverse; gap:7px; pointer-events:none;
    transition:opacity .25s ease, visibility .25s ease;
}
.ipv-social-proof-strip.ipv-sp-raised{ bottom:76px; }
.ipv-sp-avatar-wrap{ position:relative; pointer-events:all; display:inline-block; text-decoration:none; }
.ipv-sp-avatar-wrap:nth-child(1){ animation:ipvSpFloat 3s ease-in-out infinite; }
.ipv-sp-avatar-wrap:nth-child(2){ animation:ipvSpFloat 3s ease-in-out infinite .4s; }
.ipv-sp-avatar-wrap:nth-child(3){ animation:ipvSpFloat 3s ease-in-out infinite .8s; }
.ipv-sp-avatar-wrap:nth-child(4){ animation:ipvSpFloat 3s ease-in-out infinite 1.2s; }
@keyframes ipvSpFloat{ 0%,100%{ transform:translateY(0); } 50%{ transform:translateY(-6px); } }
.ipv-sp-avatar{
    width:46px; height:46px; border-radius:50%; object-fit:cover;
    border:2.5px solid var(--red,#FF2D2D); display:block;
    box-shadow:0 4px 12px rgba(0,0,0,.7);
}
.ipv-sp-badge{
    position:absolute; bottom:-2px; right:-2px;
    width:16px; height:16px; border-radius:50%;
    display:flex; align-items:center; justify-content:center; z-index:2;
}
.ipv-sp-badge i{ font-size:8px; color:#fff; line-height:1; }

.ipv-media-controls{ position:absolute; bottom:12px; right:12px; z-index:6; display:flex; flex-direction:column; gap:8px; }
.ipv-mute-btn{
    width:28px; height:28px; border-radius:50%;
    background:rgba(0,0,0,.5); border:none; color:#fff;
    display:flex; align-items:center; justify-content:center; cursor:pointer;
    -webkit-tap-highlight-color:transparent;
}
.ipv-mute-btn i{ font-size:14px; }

.ipv-actions{ display:flex; align-items:center; justify-content:space-between; padding:9px 10px 0; position:relative; }
.ipv-actions-left{ display:flex; align-items:center; gap:18px; }
.ipv-action-group{ display:flex; align-items:center; gap:6px; }
.ipv-action-btn{
    background:none; border:none; color:#fff; font-size:22px;
    cursor:pointer; padding:2px; display:flex; align-items:center;
    -webkit-tap-highlight-color:transparent; transition:transform .12s ease;
}
.ipv-action-btn:active{ transform:scale(1.22); }
.ipv-action-btn.liked{ color:var(--red,#FF2D2D); }
.ipv-action-btn.faved{ color:var(--red,#FF2D2D); }
.ipv-action-count{ font-size:12px; font-weight:700; color:#fff; }

@keyframes ipv-repost-arc-swap {
    0%   { transform: rotate(0deg) scale(1); }
    35%  { transform: rotate(140deg) scale(1.15); }
    50%  { transform: rotate(180deg) scale(1.2); }
    65%  { transform: rotate(220deg) scale(1.15); }
    100% { transform: rotate(360deg) scale(1); }
}
.ipv-action-btn.arc-swap-active i {
    animation: ipv-repost-arc-swap 0.6s cubic-bezier(.33,1,.68,1);
    transition: none !important;
}

.ipv-social-row{ display:flex; align-items:center; gap:7px; padding:6px 12px 0; cursor:pointer; }
.ipv-social-avatars{ display:flex; flex-shrink:0; }
.ipv-social-avatars img{ width:20px; height:20px; border-radius:50%; object-fit:cover; border:1.6px solid rgba(200,203,214,.65); margin-left:-7px; }
.ipv-social-avatars img:first-child{ margin-left:0; }
.ipv-likes-text{ font-size:12.5px; color:#fff; }
.ipv-likes-text b{ font-weight:700; }
.ipv-likes-dim{ color:rgba(220,224,255,.72); font-weight:400; }

/* caption below media: ONLY shown for long-header posts (header floats over the media) */
.ipv-caption{ display:none; padding:5px 12px 0; font-size:12.5px; line-height:1.5; color:#fff; word-break:break-word; }
.ipv-post.ipv-long-header .ipv-caption{ display:block; }
.ipv-caption b.ipv-cap-user{ margin-right:5px; color:var(--red,#FF2D2D); }
.ipv-more-toggle{ color:var(--red,#FF2D2D); cursor:pointer; font-weight:700; }
.ipv-more-wrap{ cursor:pointer; }
.ipv-ellipsis{ color:#fff; font-weight:400; }

.ipv-partnership-tag{
    position:absolute; z-index:7; left:12px; bottom:34px;
    display:inline-flex; align-items:center; gap:6px;
    padding:5px 10px;
    border-radius:8px;
    background:rgba(0,0,0,0.55);
    color:#ff4c4c;
    font-size:11px;
    font-weight:600;
    max-width:calc(100% - 24px);
    backdrop-filter:blur(6px);
    -webkit-backdrop-filter:blur(6px);
    pointer-events:none;
    transition:opacity .25s ease, visibility .25s ease;
}
.ipv-partnership-tag i{ font-size:13px; flex-shrink:0; }
.ipv-partnership-tag b{ color:#fff; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

.ipv-time{ padding:6px 12px 0; font-size:10px; color:rgba(220,224,255,.4); letter-spacing:.3px; }
.ipv-time.ipv-time-row{ display:flex; align-items:center; justify-content:space-between; gap:8px; }
.ipv-time-left{ display:flex; align-items:center; flex-wrap:wrap; gap:2px 10px; min-width:0; }
.ipv-zeem-hint{ color:var(--red,#FF2D2D); font-weight:300; font-size:10px; letter-spacing:.2px; }

/* when a caption is expanded ("more"), the overlays on the media step out of the way */
.ipv-post.ipv-cap-open .ipv-social-proof-strip,
.ipv-post.ipv-cap-open .ipv-cta-pill,
.ipv-post.ipv-cap-open .ipv-partnership-tag{ opacity:0; visibility:hidden; pointer-events:none; }
.ipv-post.ipv-cap-open .ipv-textonly .ipv-social-proof-strip,
.ipv-post.ipv-cap-open .ipv-textonly .ipv-cta-pill,
.ipv-post.ipv-cap-open .ipv-textonly .ipv-partnership-tag{ display:none; }

/* ============================================================
   DOUBLE-TAP LIKE HEART BURST
   ============================================================ */
.ipv-double-tap-heart{
    position:absolute; z-index:14; pointer-events:none;
    transform:translate(-50%,-50%) scale(0) rotate(0deg);
    opacity:0;
}
.ipv-double-tap-heart i{
    font-size:84px; color:#ff2d4f;
    filter:drop-shadow(0 6px 20px rgba(255,45,79,.6));
    -webkit-text-stroke:1.3px rgba(255,255,255,.32);
}
.ipv-double-tap-heart.show{
    animation: ipvHeartBurst .95s cubic-bezier(.17,.89,.32,1.28) forwards;
}
@keyframes ipvHeartBurst{
    0%{   transform:translate(-50%,-50%) scale(0)    rotate(-28deg); opacity:0; }
    14%{  transform:translate(-50%,-50%) scale(1.35) rotate(10deg);  opacity:1; }
    28%{  transform:translate(-50%,-50%) scale(.88)  rotate(-6deg);  opacity:1; }
    42%{  transform:translate(-50%,-50%) scale(1.14) rotate(4deg);   opacity:1; }
    58%{  transform:translate(-50%,-50%) scale(.97)  rotate(-1deg);  opacity:1; }
    75%{  transform:translate(-50%,-50%) scale(1.05) rotate(0deg);   opacity:1; }
    100%{ transform:translate(-50%,-50%) scale(1.15) rotate(0deg) translateY(-40px); opacity:0; }
}
.ipv-heart-ring{
    position:absolute; z-index:13; pointer-events:none;
    width:95px; height:95px;
    margin-left:-47.5px; margin-top:-47.5px;
    border-radius:50%;
    border:3px solid rgba(255,45,79,.62);
    transform:scale(.25);
    opacity:0;
}
.ipv-heart-ring.show{
    animation: ipvRingPulse .75s cubic-bezier(.15,.6,.35,1) forwards;
}
@keyframes ipvRingPulse{
    0%{   transform:scale(.25); opacity:.9; border-width:4px; }
    100%{ transform:scale(2.3); opacity:0;  border-width:1px; }
}
.ipv-heart-particle{
    position:absolute; z-index:12; pointer-events:none;
    left:0; top:0;
    transform:translate(-50%,-50%) scale(0);
    opacity:0;
    color:#ff2d4f;
}
.ipv-heart-particle i{ filter:drop-shadow(0 2px 7px rgba(255,45,79,.5)); }
.ipv-heart-particle.show{
    animation: ipvParticleFly .8s cubic-bezier(.2,.7,.3,1) forwards;
}
@keyframes ipvParticleFly{
    0%{   transform:translate(-50%,-50%) scale(0)    rotate(0deg);        opacity:0; }
    12%{  opacity:1; }
    100%{ transform:translate(calc(-50% + var(--tx)), calc(-50% + var(--ty))) scale(1) rotate(var(--tr)); opacity:0; }
}

/* ============================================================
   LIKERS BOTTOM SHEET
   ============================================================ */
.ipv-likers-overlay{
    display:none; position:fixed; inset:0; z-index:999999;
    background:rgba(0,0,0,.55);
    align-items:flex-end; justify-content:center;
    opacity:0; transition:opacity .2s ease;
}
.ipv-likers-overlay.ipv-open{ display:flex; opacity:1; }
.ipv-likers-panel{
    width:100%; max-width:470px; max-height:70vh;
    background:var(--ipv-lane); border-radius:16px 16px 0 0;
    border:1px solid var(--border,rgba(255,255,255,.08)); border-bottom:none;
    padding:10px 0 18px; display:flex; flex-direction:column;
    transform:translateY(100%); transition:transform .28s cubic-bezier(.22,1,.36,1);
    font-family:'Poppins',sans-serif;
}
.ipv-likers-overlay.ipv-open .ipv-likers-panel{ transform:translateY(0); }
.ipv-likers-handle{ width:36px; height:4px; border-radius:3px; background:rgba(255,255,255,.25); margin:0 auto 12px; }
.ipv-likers-title{ font-size:14px; font-weight:700; color:#fff; padding:0 16px 10px; border-bottom:1px solid var(--border,rgba(255,255,255,.07)); }
.ipv-likers-list{ overflow-y:auto; padding:2px 0 6px; }
.ipv-likers-tabs{ display:flex; gap:6px; padding:0 16px 10px; border-bottom:1px solid var(--border,rgba(255,255,255,.07)); }
.ipv-likers-tab{
    background:none; border:none; font-family:inherit; font-size:12.5px; font-weight:700;
    color:rgba(220,224,255,.5); padding:6px 14px; border-radius:20px; cursor:pointer;
    -webkit-tap-highlight-color:transparent; transition:color .15s ease, background .15s ease;
}
.ipv-likers-tab.active{ color:#fff; background:rgba(255,45,45,.14); }
.ipv-liker-row{ display:flex; align-items:center; gap:10px; padding:10px 16px; border-bottom:1px solid var(--border,rgba(255,255,255,.07)); }
.ipv-liker-avatar{ width:38px; height:38px; border-radius:50%; object-fit:cover; border:1.5px solid var(--red,#FF2D2D); flex-shrink:0; }
.ipv-liker-name{ font-size:13px; font-weight:600; color:#fff; flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.ipv-liker-icon{ font-size:14px; color:var(--red,#FF2D2D); flex-shrink:0; }
.ipv-likers-empty{ padding:24px 16px; text-align:center; font-size:12.5px; color:rgba(220,224,255,.5); }

/* ============================================================
   TOP-RIGHT TAGS
   ============================================================ */
.ipv-top-right-tags{
    position:absolute; top:10px; right:10px; z-index:6;
    display:flex; flex-direction:column; align-items:flex-end; gap:6px;
    pointer-events:none;
}
.ipv-media-counter{
    background:rgba(22,22,24,.78); color:#fff;
    font-size:10px; font-weight:800; letter-spacing:.3px;
    padding:4px 8px; border-radius:6px;
}
.ipv-type-tag{
    display:flex; align-items:center; gap:4px;
    background:rgba(22,22,24,.78); border-radius:6px;
    padding:4px 8px 4px 6px;
}
.ipv-type-tag i{ font-size:12px; color:#fff; }
.ipv-type-tag span{ font-size:10px; font-weight:700; color:#fff; text-transform:uppercase; letter-spacing:.3px; white-space:nowrap; }

/* ============================================================
   TOP-LEFT TAG STACK
   ============================================================ */
.ipv-top-left-tags{
    position:absolute; top:10px; left:10px; z-index:6;
    display:flex; flex-direction:column; align-items:flex-start; gap:6px;
    pointer-events:none;
}
.ipv-top-left-tags > *{ pointer-events:all; flex:none; width:max-content; }

.ipv-group-tag{
    display:flex; align-items:center; gap:4px;
    background:rgba(40,42,50,.78); border-radius:7px;
    padding:5px 9px 5px 7px; text-decoration:none;
    font-size:10px; font-weight:600; color:rgba(255,255,255,.85);
    max-width:100%; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.ipv-group-tag i{ font-size:12px; color:rgba(255,255,255,.55); flex-shrink:0; }
.ipv-group-tag b{ color:#ff5f5f; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

.ipv-ai-tag{
    display:flex; align-items:center; gap:5px;
    background:rgba(59,130,246,.18);
    border:1px solid rgba(59,130,246,.35);
    border-radius:7px; padding:5px 9px 5px 7px;
    font-size:10px; font-weight:600; color:#fff;
    backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px);
    white-space:nowrap;
}
.ipv-ai-tag i{ font-size:12.5px; color:#7fb2ff; }

/* ============================================================
   POST-AS-AD CTA PILL
   ============================================================ */
.ipv-cta-pill{
    position:absolute; left:12px; bottom:30px; z-index:6;
    display:flex; align-items:center; justify-content:flex-start;
    padding:9px 14px;
    border-radius:8px;
    background:rgba(30,30,34,.55);
    border:1px solid rgba(255,255,255,.28);
    color:#fff;
    font-weight:700;
    font-size:11.5px;
    text-decoration:none;
    box-shadow:0 4px 14px rgba(0,0,0,.3);
    white-space:nowrap;
    overflow:hidden;
    max-width:calc(100% - 24px);
    transition:padding .4s cubic-bezier(.22,1,.36,1), background .4s ease, border-color .4s ease,
               box-shadow .4s ease, min-width .4s cubic-bezier(.22,1,.36,1), opacity .25s ease, visibility .25s ease;
}
.ipv-cta-pill.ipv-sp-raised{ bottom:76px; font-size:13px; }
.ipv-cta-pill-text{ position:relative; z-index:1; overflow:hidden; text-overflow:ellipsis; }
.ipv-cta-pill-chevron{
    display:inline-block; font-size:0; opacity:0; max-width:0;
    overflow:hidden; margin-left:0; flex-shrink:0;
    transition:opacity .3s ease, max-width .3s ease, margin-left .3s ease, font-size .3s ease;
}
.ipv-cta-pill.ipv-cta-pill-expanded{
    background:var(--cta-color, #FF4C4C);
    border-color:transparent;
    box-shadow:0 6px 22px rgba(0,0,0,.45);
    padding:12px 18px;
    min-width:min(300px, calc(100% - 24px));
    justify-content:space-between;
}
.ipv-cta-pill.ipv-cta-pill-expanded .ipv-cta-pill-chevron{
    opacity:1; max-width:20px; margin-left:auto; padding-left:8px; font-size:15px;
}

.ipv-ad-tag{
    position:absolute; right:9px; top:50%; transform:translateY(-50%);
    display:flex; align-items:center;
    font-size:12.5px; font-weight:800;
    color:#FF3B30;
    letter-spacing:.03em;
    text-transform:uppercase;
    pointer-events:none;
    user-select:none;
}

/* ============================================================
   SKELETON LOADER (same 3px dark separators as the posts)
   ============================================================ */
.ipv-skel-post{
    border-bottom:3px solid var(--ipv-sep);
    padding-bottom:12px;
    margin-bottom:0;
}
.ipv-skel-header{ display:flex; align-items:center; gap:10px; padding:11px 12px; }
.ipv-skel-avatar{ width:34px; height:34px; border-radius:50%; flex-shrink:0; }
.ipv-skel-header-lines{ flex:1; display:flex; flex-direction:column; gap:6px; }
.ipv-skel-line{ height:10px; border-radius:5px; }
.ipv-skel-line.w1{ width:120px; }
.ipv-skel-line.w2{ width:75px; height:8px; }
.ipv-skel-media{ width:100%; aspect-ratio:4/3.5; }
.ipv-skel-actions{ display:flex; gap:18px; padding:9px 12px 0; }
.ipv-skel-icon{ width:22px; height:22px; border-radius:6px; }
.ipv-skel-cap{ margin:11px 12px 0; height:10px; border-radius:5px; width:82%; }
.ipv-skel-cap2{ margin:6px 12px 0; height:10px; border-radius:5px; width:52%; }

.ipv-skel-avatar,.ipv-skel-line,.ipv-skel-media,.ipv-skel-icon,.ipv-skel-cap,.ipv-skel-cap2{
    background: linear-gradient(90deg, rgba(255,255,255,.05) 25%, rgba(255,255,255,.13) 37%, rgba(255,255,255,.05) 63%);
    background-size:400% 100%;
    animation: ipvSkelShimmer 1.4s ease-in-out infinite;
}
@keyframes ipvSkelShimmer{ 0%{background-position:100% 50%;} 100%{background-position:0% 50%;} }

.ipv-skel-post:nth-child(2) .ipv-skel-avatar,.ipv-skel-post:nth-child(2) .ipv-skel-line,
.ipv-skel-post:nth-child(2) .ipv-skel-media,.ipv-skel-post:nth-child(2) .ipv-skel-icon,
.ipv-skel-post:nth-child(2) .ipv-skel-cap,.ipv-skel-post:nth-child(2) .ipv-skel-cap2{ animation-delay:.08s; }
.ipv-skel-post:nth-child(3) .ipv-skel-avatar,.ipv-skel-post:nth-child(3) .ipv-skel-line,
.ipv-skel-post:nth-child(3) .ipv-skel-media,.ipv-skel-post:nth-child(3) .ipv-skel-icon,
.ipv-skel-post:nth-child(3) .ipv-skel-cap,.ipv-skel-post:nth-child(3) .ipv-skel-cap2{ animation-delay:.16s; }
.ipv-skel-post:nth-child(4) .ipv-skel-avatar,.ipv-skel-post:nth-child(4) .ipv-skel-line,
.ipv-skel-post:nth-child(4) .ipv-skel-media,.ipv-skel-post:nth-child(4) .ipv-skel-icon,
.ipv-skel-post:nth-child(4) .ipv-skel-cap,.ipv-skel-post:nth-child(4) .ipv-skel-cap2{ animation-delay:.24s; }

/* batch-loading sentinel */
.ipv-load-more{ display:flex; align-items:center; justify-content:center; padding:24px 0; }
.ipv-load-more-dot{ width:6px; height:6px; border-radius:50%; background:rgba(255,255,255,.35); margin:0 3px; animation:ipvLoadDot 1.1s ease-in-out infinite; }
.ipv-load-more-dot:nth-child(2){ animation-delay:.15s; }
.ipv-load-more-dot:nth-child(3){ animation-delay:.3s; }
@keyframes ipvLoadDot{ 0%,80%,100%{ transform:scale(.6); opacity:.4; } 40%{ transform:scale(1); opacity:1; } }

@keyframes ipvPostFadeIn{ from{ opacity:0; transform:translateY(10px); } to{ opacity:1; transform:translateY(0); } }
.ipv-post-enter{ animation: ipvPostFadeIn .45s cubic-bezier(.22,1,.36,1) both; }

/* ============================================================
   RESPONSIVE — 790 / 490 / 390 / 330
   ============================================================ */
@media (max-width:790px){
    .ipv-overlay{ scrollbar-width:none; -ms-overflow-style:none; }
    .ipv-overlay::-webkit-scrollbar{ display:none; width:0; height:0; }

    .ipv-topbar{ padding:6px 14px; } .ipv-topbar-title{ font-size:14px; } .ipv-back-btn i{ font-size:32px; } .ipv-topbar-action{ min-width:24px; }
    .ipv-track-btn.ipv-topbar-track-btn{ font-size:12px; padding:4.5px 11px; border-width:1.6px; }
    .ipv-topbar-username-sm{ font-size:12.5px; } .ipv-topbar-noun{ font-size:12.5px; } .ipv-topbar-sep{ font-size:11px; }
    .ipv-header{ padding:10px 11px; gap:9px; } .ipv-avatar{ width:32px; height:32px; }
    .ipv-username{ font-size:12.5px; } .ipv-sub-line,.ipv-audio-text{ font-size:10.5px; }
    .ipv-kebab{ font-size:21px; padding:6px 4px 6px 9px; margin-right:-5px; }
    .ipv-actions{ padding:8px 9px 0; } .ipv-action-btn{ font-size:21px; } .ipv-actions-left{ gap:15px; }
    .ipv-ad-tag{ font-size:12px; right:9px; }
    .ipv-social-row,.ipv-caption,.ipv-header-caption,.ipv-time{ padding-left:11px; padding-right:11px; } .ipv-caption,.ipv-header-caption{ font-size:12px; }
    .ipv-social-proof-strip.ipv-sp-raised{ bottom:62px; }
    .ipv-partnership-tag{ font-size:10.5px; padding:4.5px 9px; left:11px; bottom:30px; gap:5px; max-width:calc(100% - 22px); }
    .ipv-partnership-tag i{ font-size:12.5px; }
    .ipv-group-tag,.ipv-media-counter{ font-size:9.5px; }
    .ipv-top-right-tags{ top:9px; right:9px; gap:5px; }
    .ipv-top-left-tags{ top:9px; left:9px; gap:5px; }
    .ipv-ai-tag{ font-size:9.5px; padding:4px 8px 4px 6px; }
    .ipv-ai-tag i{ font-size:11px; }
    .ipv-cta-pill{ left:11px; bottom:30px; font-size:10.5px; padding:8px 12px; }
    .ipv-cta-pill.ipv-sp-raised{ bottom:62px; font-size:12px; }
    .ipv-cta-pill.ipv-cta-pill-expanded{ padding:10px 16px; }
    .ipv-type-tag i{ font-size:11px; } .ipv-type-tag span{ font-size:9px; }
    .ipv-post.ipv-long-header .ipv-top-right-tags,
    .ipv-post.ipv-long-header .ipv-top-left-tags{ top:54px; }
    .ipv-sp-avatar{ width:42px; height:42px; }
    .ipv-social-avatars img{ width:18px; height:18px; margin-left:-6px; }
    .ipv-media-arrow{ width:29px; height:29px; } .ipv-media-arrow i{ font-size:18px; }
    .ipv-double-tap-heart i{ font-size:70px; }
}
@media (max-width:490px){
    .ipv-topbar{ padding:5px 12px; } .ipv-topbar-title{ font-size:13px; } .ipv-back-btn i{ font-size:30px; } .ipv-topbar-action{ min-width:22px; }
    .ipv-track-btn.ipv-topbar-track-btn{ font-size:11.5px; padding:4px 10px; border-width:1.5px; }
    .ipv-topbar-username-sm{ font-size:12px; } .ipv-topbar-noun{ font-size:12px; } .ipv-topbar-sep{ font-size:10.5px; }
    .ipv-header{ padding:9px 10px; gap:8px; } .ipv-avatar{ width:29px; height:29px; border-width:1.4px; }
    .ipv-username{ font-size:12px; } .ipv-track-btn{ font-size:11px; } .ipv-sub-line,.ipv-audio-text{ font-size:10px; }
    .ipv-kebab{ font-size:20px; padding:5px 3px 5px 8px; margin-right:-4px; }
    .ipv-actions{ padding:7px 8px 0; } .ipv-actions-left{ gap:13px; }
    .ipv-action-btn{ font-size:19px; } .ipv-action-count{ font-size:11px; }
    .ipv-ad-tag{ font-size:11.5px; right:8px; }
    .ipv-mute-btn{ width:25px; height:25px; } .ipv-mute-btn i{ font-size:12.5px; }
    .ipv-sp-avatar{ width:38px; height:38px; }
    .ipv-social-proof-strip.ipv-sp-raised{ bottom:58px; }
    .ipv-social-avatars img{ width:17px; height:17px; margin-left:-6px; } .ipv-likes-text{ font-size:11.5px; }
    .ipv-caption,.ipv-header-caption,.ipv-time{ padding-left:10px; padding-right:10px; } .ipv-caption,.ipv-header-caption{ font-size:11.5px; } .ipv-time{ font-size:9.5px; }
    .ipv-partnership-tag{ font-size:10px; padding:4px 8px; left:10px; bottom:26px; gap:5px; max-width:calc(100% - 20px); }
    .ipv-partnership-tag i{ font-size:12px; }
    .ipv-group-tag,.ipv-media-counter{ font-size:9px; padding:4px 7px; }
    .ipv-top-right-tags{ top:8px; right:8px; gap:5px; }
    .ipv-top-left-tags{ top:8px; left:8px; gap:5px; }
    .ipv-media-wrap.ipv-textonly .ipv-top-right-tags{ top:10px; right:12px; }
    .ipv-ai-tag{ font-size:9px; padding:3.5px 7px 3.5px 5px; }
    .ipv-ai-tag i{ font-size:10px; }
    .ipv-cta-pill{ left:10px; bottom:26px; font-size:10px; padding:7px 11px; }
    .ipv-cta-pill.ipv-sp-raised{ bottom:58px; font-size:11.5px; }
    .ipv-cta-pill.ipv-cta-pill-expanded{ padding:9px 14px; }
    .ipv-type-tag{ padding:4px 7px 4px 5px; }
    .ipv-type-tag i{ font-size:10px; } .ipv-type-tag span{ font-size:8.5px; }
    .ipv-post.ipv-long-header .ipv-top-right-tags,
    .ipv-post.ipv-long-header .ipv-top-left-tags{ top:50px; }
    .ipv-media-arrow{ width:26px; height:26px; } .ipv-media-arrow i{ font-size:16px; }
    .ipv-double-tap-heart i{ font-size:60px; }
}
@media (max-width:390px){
    .ipv-topbar{ padding:5px 11px; } .ipv-topbar-title{ font-size:12.5px; } .ipv-back-btn i{ font-size:28px; } .ipv-topbar-action{ min-width:20px; }
    .ipv-track-btn.ipv-topbar-track-btn{ font-size:11px; padding:3.5px 9px; border-width:1.4px; }
    .ipv-topbar-username-sm{ font-size:11.5px; } .ipv-topbar-noun{ font-size:11.5px; } .ipv-topbar-sep{ font-size:10px; }
    .ipv-header{ padding:8px 9px; gap:7px; } .ipv-avatar{ width:27px; height:27px; }
    .ipv-username{ font-size:11.5px; } .ipv-track-btn{ font-size:10.5px; } .ipv-sub-line,.ipv-audio-text{ font-size:9.5px; }
    .ipv-kebab{ font-size:19px; padding:5px 3px 5px 7px; margin-right:-3px; }
    .ipv-actions{ padding:6px 7px 0; } .ipv-actions-left{ gap:11px; }
    .ipv-action-btn{ font-size:18px; } .ipv-dot{ width:4.5px; height:4.5px; }
    .ipv-ad-tag{ font-size:11px; right:7px; }
    .ipv-sp-avatar{ width:34px; height:34px; }
    .ipv-social-proof-strip.ipv-sp-raised{ bottom:54px; }
    .ipv-social-avatars img{ width:16px; height:16px; margin-left:-5.5px; } .ipv-likes-text{ font-size:11px; }
    .ipv-caption,.ipv-header-caption,.ipv-time{ padding-left:9px; padding-right:9px; } .ipv-caption,.ipv-header-caption{ font-size:11px; line-height:1.45; } .ipv-time{ font-size:9px; }
    .ipv-partnership-tag{ font-size:9.5px; padding:3.5px 7px; left:9px; bottom:24px; gap:4px; max-width:calc(100% - 18px); }
    .ipv-partnership-tag i{ font-size:11px; }
    .ipv-top-right-tags{ top:8px; right:8px; gap:4px; }
    .ipv-top-left-tags{ top:8px; left:8px; gap:4px; }
    .ipv-ai-tag{ font-size:8.5px; padding:3px 6.5px 3px 5px; }
    .ipv-ai-tag i{ font-size:9.5px; }
    .ipv-cta-pill{ left:9px; bottom:24px; font-size:9.5px; padding:6.5px 10px; gap:4px; }
    .ipv-cta-pill.ipv-sp-raised{ bottom:54px; font-size:11px; }
    .ipv-cta-pill.ipv-cta-pill-expanded{ padding:8px 13px; }
    .ipv-type-tag{ padding:3.5px 6.5px 3.5px 5px; }
    .ipv-type-tag i{ font-size:9.5px; } .ipv-type-tag span{ font-size:8px; }
    .ipv-post.ipv-long-header .ipv-top-right-tags,
    .ipv-post.ipv-long-header .ipv-top-left-tags{ top:46px; }
    .ipv-double-tap-heart i{ font-size:52px; }
}
@media (max-width:330px){
    .ipv-topbar{ padding:4px 9px; } .ipv-topbar-title{ font-size:12px; } .ipv-back-btn i{ font-size:26px; } .ipv-topbar-action{ min-width:18px; }
    .ipv-track-btn.ipv-topbar-track-btn{ font-size:10.5px; padding:3px 8px; border-width:1.3px; }
    .ipv-topbar-username-sm{ font-size:11px; } .ipv-topbar-noun{ font-size:11px; } .ipv-topbar-sep{ font-size:9.5px; }
    .ipv-header{ padding:7px 8px; gap:6px; } .ipv-avatar{ width:25px; height:25px; }
    .ipv-username{ font-size:11px; } .ipv-track-btn{ font-size:10px; } .ipv-sub-line,.ipv-audio-text{ font-size:9px; }
    .ipv-kebab{ font-size:18px; padding:4px 3px 4px 6px; margin-right:-3px; }
    .ipv-actions{ padding:6px 6px 0; } .ipv-actions-left{ gap:9px; }
    .ipv-action-btn{ font-size:17px; } .ipv-mute-btn{ width:22px; height:22px; } .ipv-mute-btn i{ font-size:11px; }
    .ipv-ad-tag{ font-size:10.5px; right:6px; }
    .ipv-social-proof-strip.ipv-sp-raised{ bottom:48px; }
    .ipv-caption,.ipv-header-caption,.ipv-time{ padding-left:8px; padding-right:8px; } .ipv-caption,.ipv-header-caption{ font-size:10.3px; } .ipv-time{ font-size:8.5px; }
    .ipv-partnership-tag{ font-size:9px; padding:3px 6px; left:8px; bottom:22px; gap:4px; border-radius:7px; max-width:calc(100% - 16px); }
    .ipv-partnership-tag i{ font-size:10px; }
    .ipv-top-right-tags{ top:7px; right:7px; gap:4px; }
    .ipv-top-left-tags{ top:7px; left:7px; gap:4px; }
    .ipv-ai-tag{ font-size:8px; padding:3px 6px 3px 4.5px; }
    .ipv-ai-tag i{ font-size:9px; }
    .ipv-cta-pill{ left:8px; bottom:22px; font-size:9px; padding:6px 9px; gap:4px; }
    .ipv-cta-pill.ipv-sp-raised{ bottom:48px; font-size:10.5px; }
    .ipv-cta-pill.ipv-cta-pill-expanded{ padding:7px 11px; }
    .ipv-type-tag{ padding:3px 6px 3px 4.5px; }
    .ipv-type-tag i{ font-size:9px; } .ipv-type-tag span{ font-size:7.5px; }
    .ipv-post.ipv-long-header .ipv-top-right-tags,
    .ipv-post.ipv-long-header .ipv-top-left-tags{ top:42px; }
    .ipv-double-tap-heart i{ font-size:46px; }
}
</style>

<script>
(function(){

/* ============================================================
   HOOK INTO YOUR EXISTING REEL MODAL
   ============================================================ */
function ipvInstallHooks(){
    if(typeof window.openMemeModal !== 'function' || typeof window.closeMemeModal !== 'function'){
        setTimeout(ipvInstallHooks, 50);
        return;
    }
    if(window._ipvHooksInstalled) return;
    window._ipvHooksInstalled = true;

    window._ccmOpenReel  = window.openMemeModal;
    window._ccmCloseReel = window.closeMemeModal;

    window.openMemeModal = function(src, type, caption, pic, username, userId, feedName, memeId){
        ipvOpen(feedName, memeId);
    };

    window.closeMemeModal = function(){
        var playback = (typeof window.ccmGetCurrentPlayback === 'function') ? window.ccmGetCurrentPlayback() : null;
        window._ccmCloseReel();
        if(_ipvActive){
            document.body.style.overflow = 'hidden';

            var landingMemeId = (playback && playback.memeId) ? playback.memeId : _ipvReelOriginMemeId;

            if(playback && playback.memeId){
                _ipvApplyPlaybackToPost(playback);
            }

            if(landingMemeId != null){
                var landingPost = document.querySelector('#ipvList .ipv-post[data-id="'+landingMemeId+'"]');
                if(landingPost) landingPost.scrollIntoView({ block:'start' });
            }

            _ipvResumeFocusedVideo();
            _ipvReelOriginMemeId = null;
            _ipvReelOriginFeed   = null;
        }
    };
}
ipvInstallHooks();

/* ============================================================
   STATE
   ============================================================ */
var _ipvActive        = false;
var _ipvFeedName      = null;
var _ipvFeedArray     = [];
var _ipvObserver      = null;
var _ipvActionLock    = {};
var _ipvSwitchers     = {};
var _ipvCtaPillTimers = {};
var _ipvVisibility    = {};
var _ipvCurrentAudioPostId = null;
var _ipvPendingShare  = null;
var _ipvTrackMuted    = true;

var _ipvReelOriginMemeId = null;
var _ipvReelOriginFeed   = null;

/* batched infinite-scroll state */
var _ipvBatchSize        = 4;
var _ipvRenderedCount    = 0;
var _ipvSentinelObserver = null;
var _ipvSkeletonTimer    = null;
var _ipvLoadingBatch     = false;

/* the shimmer skeleton only appears ONCE per page load */
var _ipvSkeletonShownOnce = false;

/* ============================================================
   HELPERS
   ============================================================ */
function ipvEsc(str){
    if(str === null || str === undefined) return '';
    var d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

function ipvFmtNum(n){
    n = parseInt(n) || 0;
    if(n >= 1000000000) return (n/1000000000).toFixed(1).replace(/\.0$/,'')+'B';
    if(n >= 1000000)    return (n/1000000).toFixed(1).replace(/\.0$/,'')+'M';
    if(n >= 1000)       return (n/1000).toFixed(1).replace(/\.0$/,'')+'K';
    return n;
}

function _ipvTypeTagInfo(rawType){
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

function ipvLinkify(caption){
    caption = caption || '';
    return caption
        .replace(/#([a-zA-Z0-9_]+)/g, function(m, tag){
            var mq = encodeURIComponent('#'+tag);
            return '<a href="search.php?focus=1&mq='+mq+
                   '" onclick="event.stopPropagation()" style="color:var(--violet,#7c5cff);font-weight:600;text-decoration:none;">'+m+'</a>';
        })
        .replace(/@([a-zA-Z0-9_]+)/g, function(m, u){
            return '<a href="profile.php?username='+encodeURIComponent(u)+
                   '" onclick="event.stopPropagation()" style="color:var(--red,#FF2D2D);font-weight:600;text-decoration:none;">'+m+'</a>';
        });
}

function ipvFormatFullDate(timeStr){
    if(!timeStr) return '';
    var d = new Date((timeStr||'').replace(' ','T'));
    if(isNaN(d)) return timeStr;
    var days   = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return days[d.getDay()]+', '+d.getDate()+' '+months[d.getMonth()]+' '+d.getFullYear()+
           ' · '+String(d.getHours()).padStart(2,'0')+':'+String(d.getMinutes()).padStart(2,'0');
}

function ipvIsNew(timeStr){
    if(!timeStr) return false;
    var d = new Date((timeStr||'').replace(' ','T'));
    if(isNaN(d)) return false;
    return (Date.now() - d.getTime()) <= (172800*1000);
}

function ipvLikesText(p){
    var proof = Array.isArray(p.socialProof) ? p.socialProof : [];
    var likeProof = proof.filter(function(sp){ return sp.action_type === 'like'; });
    var count = p.likeCount || 0;
    var repostCount = p.repostCount || 0;

    var base = '';
    if(count > 0){
        if(likeProof.length > 0){
            var first = ipvEsc(likeProof[0].username || '');
            var rest  = count - 1;
            base = rest > 0
                ? '<span class="ipv-likes-dim">Liked by</span> <b>'+first+'</b> <span class="ipv-likes-dim">and</span> <b>'+ipvFmtNum(rest)+' other'+(rest===1?'':'s')+'</b>'
                : '<span class="ipv-likes-dim">Liked by</span> <b>'+first+'</b>';
        } else {
            base = '<b>'+ipvFmtNum(count)+' like'+(count===1?'':'s')+'</b>';
        }
    }
    if(repostCount > 0){
        var repostPart = '<b>'+ipvFmtNum(repostCount)+' repost'+(repostCount===1?'':'s')+'</b>';
        base = base ? base+' · '+repostPart : repostPart;
    }
    return base;
}

function _ipvEscapeHtml(str){ return ipvEsc(str); }

/* ============================================================
   RENDER A SINGLE POST CARD
   ============================================================ */
function ipvRenderPost(p, feedName){
    var media = (Array.isArray(p.media) && p.media.length) ? p.media : [{file:p.file, type:p.type}];
    var mediaCount = media.length;
    var isOwn = (p.isYou === true);

    var mediaSet   = ['image','video','gif','sticker','stickers'];
    var firstType  = ((media[0].type)||'').toLowerCase();
    var isTextOnly = mediaCount === 1 && mediaSet.indexOf(firstType) === -1;
    var hasVideo   = media.some(function(m){ return (m.type||'').toLowerCase() === 'video'; });

    var mediaInner;
    if(isTextOnly){
        /* TEXT POST — sits on the lane colour, tag-gap keeps the first line clear of the "Text" tag */
        var full  = ipvLinkify(p.caption || '');
        var words = (p.caption||'').trim().split(/\s+/).filter(Boolean);
        var trunc = words.length > 40 ? ipvLinkify(words.slice(0,40).join(' '))+
            ' <span class="ipv-more-wrap" onclick="event.stopPropagation();ipvToggleCenterCaption(this)"><span class="ipv-ellipsis">...</span><span class="ipv-more-toggle"> more</span></span>' : full;
        mediaInner =
            '<div class="ipv-textonly-clickable" onclick="ipvHandleMediaTap(event,this,\''+feedName+'\','+p.id+')">'+
                '<div class="ipv-center-caption-wrap">'+
                    '<span class="ipv-tag-gap"></span>'+
                    '<div class="ipv-center-caption-text" data-full="'+ipvEsc(p.caption||'')+'">'+trunc+'</div>'+
                '</div>'+
            '</div>';
    } else {
        mediaInner = '<div class="ipv-media-track">'+media.map(function(m, i){
            var t = (m.type||'').toLowerCase();
            if(t === 'video'){
                return '<div class="ipv-media-item" data-idx="'+i+'" data-type="video">'+
                    '<video src="'+ipvEsc(m.file)+'" muted loop playsinline preload="metadata" '+(i===0?'autoplay':'')+
                    ' class="ipv-video" data-idx="'+i+'" '+
                    'onloadedmetadata="ipvMeasureAspect(this)" '+
                    'onclick="event.stopPropagation();ipvHandleMediaTap(event,this,\''+feedName+'\','+p.id+')"></video>'+
                '</div>';
            }
            var src = (t === 'text' || !m.file) ? 'uploads/Text.jpg' : m.file;
            return '<div class="ipv-media-item" data-idx="'+i+'" data-type="'+(t||'image')+'">'+
                '<img src="'+ipvEsc(src)+'" data-idx="'+i+'" loading="lazy" draggable="false" onerror="this.src=\'uploads/Text.jpg\'" '+
                'onload="ipvMeasureAspect(this)" '+
                'onclick="event.stopPropagation();ipvHandleMediaTap(event,this,\''+feedName+'\','+p.id+')">'+
            '</div>';
        }).join('')+'</div>';
    }

    var dotsHtml = (!isTextOnly && mediaCount > 1)
        ? '<div class="ipv-dots">'+media.slice(0,20).map(function(_,i){return '<span class="ipv-dot'+(i===0?' active':'')+'"></span>';}).join('')+'</div>'
        : '';
    var counterHtml = (!isTextOnly && mediaCount > 1) ? '<div class="ipv-media-counter">1/'+mediaCount+'</div>' : '';

    var typeInfo     = _ipvTypeTagInfo(isTextOnly ? 'text' : firstType);
    var typeTagHtml  = '<div class="ipv-type-tag"><i class="'+typeInfo.icon+'"></i><span>'+typeInfo.label+'</span></div>';
    var topRightHtml = '<div class="ipv-top-right-tags">'+counterHtml+typeTagHtml+'</div>';

    var arrowsHtml = (!isTextOnly && mediaCount > 1)
        ? '<button class="ipv-media-arrow ipv-arrow-left" onclick="event.stopPropagation();ipvArrowNav(this,-1)"><i class="ri-arrow-left-s-line"></i></button>'+
          '<button class="ipv-media-arrow ipv-arrow-right" onclick="event.stopPropagation();ipvArrowNav(this,1)"><i class="ri-arrow-right-s-line"></i></button>'
        : '';

    var topLeftInner = '';
    if(p.groupTitle){
        topLeftInner += '<a href="group.php?id='+p.groupId+'" onclick="event.stopPropagation()" class="ipv-group-tag">'+
              '<i class="ri-team-line"></i>From&nbsp;<b>'+ipvEsc(p.groupTitle)+'</b>'+
          '</a>';
    }
    if(p.isAiGenerated){
        topLeftInner += '<div class="ipv-ai-tag"><i class="ri-sparkling-2-fill"></i><span>AI Generated</span></div>';
    }
    var topLeftHtml = topLeftInner ? '<div class="ipv-top-left-tags">'+topLeftInner+'</div>' : '';

    var partnershipHtml = '';
    if(p.isPartnership){
        var brandText = p.partnerBrand ? ' with <b>'+ipvEsc(p.partnerBrand)+'</b>' : '';
        partnershipHtml = '<div class="ipv-partnership-tag"><i class="ri-handshake-line"></i>Paid partnership'+brandText+'</div>';
    }

    var socialStripHtml = '';
    if(!p.isAd && Array.isArray(p.socialProof) && p.socialProof.length){
        var ipvSpClass = 'ipv-social-proof-strip' + (p.isPartnership ? ' ipv-sp-raised' : '');
        socialStripHtml = '<div class="'+ipvSpClass+'">'+
            p.socialProof.slice(0,4).map(function(sp){
                    var isLike = sp.action_type === 'like';
                    var icon   = isLike ? 'ri-heart-3-fill' : 'ri-repeat-line';
                    var color  = isLike ? '#f24455' : '#7a4aff';
                    return '<a href="profile.php?user_id='+sp.id+'" onclick="event.stopPropagation()" class="ipv-sp-avatar-wrap">'+
                        '<img src="'+ipvEsc(sp.profile_pic||'uploads/mee.jpeg')+'" onerror="this.src=\'uploads/mee.jpeg\'" class="ipv-sp-avatar">'+
                        '<span class="ipv-sp-badge" style="background:'+color+';"><i class="'+icon+'"></i></span>'+
                    '</a>';
                }).join('')+
            '</div>';
    }

    var ctaPillHtml = '';
    if(p.isAd && p.adCtaUrl){
        var ctaClass = 'ipv-cta-pill' + (p.isPartnership ? ' ipv-sp-raised' : '');
        var ctaColor = p.adCtaColor || '#FF4C4C';
        var ctaLabel = p.adCtaText || 'Learn more';
        ctaPillHtml = '<a href="'+ipvEsc(p.adCtaUrl)+'" target="_blank" rel="noopener" id="ipv-cta-pill-'+p.id+'" class="'+ctaClass+'" '+
            'data-meme-id="'+p.id+'" style="--cta-color:'+ipvEsc(ctaColor)+';" onclick="event.stopPropagation()">'+
            '<span class="ipv-cta-pill-text">'+_ipvEscapeHtml(ctaLabel)+'</span>'+
            '<i class="ri-arrow-right-s-line ipv-cta-pill-chevron"></i>'+
        '</a>';
    }

    var controlsHtml = '';
    if(!isTextOnly && (hasVideo || p.audioFile)){
        controlsHtml = '<div class="ipv-media-controls">';
        if(hasVideo){
            var firstIsVideo = firstType === 'video';
            var hideStyle = (mediaCount > 1 && !firstIsVideo) ? ' style="display:none"' : '';
            controlsHtml += '<button class="ipv-mute-btn ipv-video-mute-btn"'+hideStyle+' onclick="event.stopPropagation();ipvToggleVideoMute(this)"><i class="ri-volume-mute-fill"></i></button>';
        }
        if(p.audioFile)  controlsHtml += '<button class="ipv-mute-btn ipv-track-mute-btn" onclick="event.stopPropagation();ipvToggleTrackMute(this)"><i class="ri-volume-mute-fill"></i></button>';
        controlsHtml += '</div>';
    }

    var isPostsTab = (!p.context || p.context === 'Uploads');
    var trackBtn = (!isOwn && !isPostsTab)
        ? '<span class="ipv-dot-sep-red">•</span><button class="ipv-track-btn" data-user="'+p.userId+'" onclick="event.stopPropagation();ipvToggleTrack(this)">'+(p.isFollowing?'Untrack':'Track')+'</button>'
        : '';

    var displayName = isOwn ? 'You' : ipvEsc(p.username || '');
    var isNew = ipvIsNew(p.time);
    var newTag = isNew ? '<span class="ipv-dot-sep">·</span><span class="ipv-new-tag">New</span>' : '';

    var likeIcon = p.liked ? 'ri-heart-3-fill' : 'ri-heart-3-line';
    var favIcon  = p.faved ? 'ri-bookmark-fill' : 'ri-bookmark-line';

    var nicknameHtml = (!isOwn && p.nickname) ? '@'+ipvEsc(p.nickname) : '';

    var audioHtml = '';
    if(p.audioId !== undefined && p.audioId !== null){
        var audioLabel;
        if(p.audioIsOriginal){
            audioLabel = (isOwn ? p.username : displayName) + ' · Original audio';
        } else {
            audioLabel = (p.audioTitle || 'Audio') + (p.audioArtist ? ' — '+p.audioArtist : '');
        }

        audioHtml = '<div class="ipv-audio-tag" onclick="event.stopPropagation();ccmOpenSoundsPage('+p.userId+','+p.audioId+')" style="cursor:pointer;">'+
            '<span class="ipv-audio-icon"><span class="ipv-eq-bar"></span><span class="ipv-eq-bar"></span><span class="ipv-eq-bar"></span></span>'+
            '<span class="ipv-audio-text">'+ipvEsc(audioLabel)+'</span>'+
        '</div>';
    }

    var switcherHtml = '';
    if(nicknameHtml && audioHtml){
        switcherHtml = '<div class="ipv-info-switcher" data-switcher="1">'+
            '<div class="ipv-switch-pane active"><div class="ipv-sub-line">'+nicknameHtml+'</div></div>'+
            '<div class="ipv-switch-pane">'+audioHtml+'</div>'+
        '</div>';
    } else if(nicknameHtml){
        switcherHtml = '<div class="ipv-sub-line">'+nicknameHtml+'</div>';
    } else if(audioHtml){
        switcherHtml = audioHtml;
    }

    var socialAvatars = '';
    var likeProofForRow = Array.isArray(p.socialProof) ? p.socialProof.filter(function(sp){ return sp.action_type === 'like'; }) : [];
    if(likeProofForRow.length){
        socialAvatars = '<div class="ipv-social-avatars">'+
            likeProofForRow.slice(0,3).map(function(sp){
                return '<img src="'+ipvEsc(sp.profile_pic||'uploads/mee.jpeg')+'" onerror="this.src=\'uploads/mee.jpeg\'">';
            }).join('')+
        '</div>';
    }
    var likesText = ipvLikesText(p);
    var socialRow = (socialAvatars || likesText)
        ? '<div class="ipv-social-row" onclick="event.stopPropagation();ipvOpenLikersSheet('+p.id+',\''+feedName+'\')">'+socialAvatars+(likesText?'<div class="ipv-likes-text">'+likesText+'</div>':'')+'</div>'
        : '';

    /* Caption: rendered twice from the same markup —
       header caption (under the avatar row) shows by default;
       the below-media caption (with red username) only shows on long-header posts. CSS decides. */
    var headerCaptionBlock = '';
    var captionBlock = '';
    if(!isTextOnly){
        var words2  = (p.caption || '').trim().split(/\s+/).filter(Boolean);
        var isLong  = words2.length > 14;
        var full2   = ipvLinkify(p.caption || '');
        var capHtml;
        if(isLong){
            var trunc2 = ipvLinkify(words2.slice(0,14).join(' '));
            capHtml = '<span class="ipv-cap-short" data-full="'+ipvEsc(p.caption||'')+'" data-expanded="0">'+trunc2+
                '<span class="ipv-more-wrap" onclick="event.stopPropagation();ipvToggleCaption(this)"><span class="ipv-ellipsis">...</span><span class="ipv-more-toggle"> more</span></span></span>';
        } else {
            capHtml = full2;
        }
        headerCaptionBlock = '<div class="ipv-header-caption">'+capHtml+'</div>';
        captionBlock = '<div class="ipv-caption"><b class="ipv-cap-user">'+displayName+'</b>'+capHtml+'</div>';
    }

    /* time row — text posts get the "Click Text to zeem" hint like home */
    var timeHtml = '';
    if(isTextOnly){
        timeHtml = '<div class="ipv-time ipv-time-row"><div class="ipv-time-left"><span>'+(p.time ? ipvEsc(ipvFormatFullDate(p.time)) : '')+'</span>'+
                   '<span class="ipv-zeem-hint">Click Text to zeem</span></div></div>';
    } else if(p.time){
        timeHtml = '<div class="ipv-time">'+ipvEsc(ipvFormatFullDate(p.time))+'</div>';
    }

    var wrapClass = 'ipv-media-wrap' + (!isTextOnly && mediaCount > 1 ? ' ipv-multi' : '') +
                    (isTextOnly ? ' ipv-textonly' + (topLeftInner ? ' ipv-tl' : '') : '');

    var shareCount = p.shareCount !== undefined ? p.shareCount : 0;

    var adTagHtml = p.isAd ? '<div class="ipv-ad-tag">Ad</div>' : '';

    /* text posts: tags first, then the text, then avatars / CTA / partnership UNDER it (in the flow) */
    var wrapInner = isTextOnly
        ? (topLeftHtml+topRightHtml+mediaInner+socialStripHtml+ctaPillHtml+partnershipHtml)
        : (topLeftHtml+topRightHtml+socialStripHtml+ctaPillHtml+mediaInner+arrowsHtml+controlsHtml+partnershipHtml+dotsHtml);

    return (
        '<div class="ipv-post'+(isTextOnly?' ipv-post-text':'')+'" data-id="'+p.id+'" data-feed="'+feedName+'" '+
             (p.audioFile ? 'data-audio="'+ipvEsc(p.audioFile)+'" data-music-vol="'+(p.musicVolume!==undefined&&p.musicVolume!==null?p.musicVolume:80)+'" data-video-vol="'+(p.videoAudioVolume!==undefined&&p.videoAudioVolume!==null?p.videoAudioVolume:40)+'"' : '')+
        '>'+
            '<div class="ipv-header">'+
                '<img class="ipv-avatar" src="'+ipvEsc(p.pic||'uploads/mee.jpeg')+'" onerror="this.src=\'uploads/mee.jpeg\'" onclick="location.href=\'profile.php?user_id='+p.userId+'\'">'+
                '<div class="ipv-header-info">'+
                    '<div class="ipv-username-row">'+
                        '<span class="ipv-username" onclick="location.href=\'profile.php?user_id='+p.userId+'\'">'+displayName+'</span>'+
                        newTag+
                        trackBtn+
                    '</div>'+
                    switcherHtml+
                '</div>'+
                '<button class="ipv-kebab" onclick="event.stopPropagation();ipvOpenMenu('+p.id+',\''+feedName+'\')"><i class="ri-menu-line"></i></button>'+
            '</div>'+

            headerCaptionBlock+

            '<div class="'+wrapClass+'" data-count="'+mediaCount+'" data-active-idx="0">'+
                wrapInner+
            '</div>'+

            '<div class="ipv-actions">'+
                '<div class="ipv-actions-left">'+
                    '<div class="ipv-action-group">'+
                        '<button class="ipv-action-btn ipv-like-btn'+(p.liked?' liked':'')+'" onclick="event.stopPropagation();ipvAction('+p.id+',\'like\',this,\''+feedName+'\')"><i class="'+likeIcon+'"></i></button>'+
                        '<span class="ipv-action-count" data-count="like">'+ipvFmtNum(p.likeCount||0)+'</span>'+
                    '</div>'+
                    '<div class="ipv-action-group">'+
                        '<button class="ipv-action-btn ipv-repost-btn'+(p.reposted?' liked':'')+'" onclick="event.stopPropagation();ipvAction('+p.id+',\'repost\',this,\''+feedName+'\')"><i class="ri-repeat-line"></i></button>'+
                        '<span class="ipv-action-count" data-count="repost">'+ipvFmtNum(p.repostCount||0)+'</span>'+
                    '</div>'+
                    '<div class="ipv-action-group">'+
                        '<button class="ipv-action-btn ipv-fav-btn'+(p.faved?' faved':'')+'" onclick="event.stopPropagation();ipvAction('+p.id+',\'fav\',this,\''+feedName+'\')"><i class="'+favIcon+'"></i></button>'+
                        '<span class="ipv-action-count" data-count="fav">'+ipvFmtNum(p.favCount||0)+'</span>'+
                    '</div>'+
                    '<div class="ipv-action-group">'+
                        '<button class="ipv-action-btn ipv-share-btn" onclick="event.stopPropagation();ipvShareAction('+p.id+',this,\''+feedName+'\')"><i class="ri-share-forward-line"></i></button>'+
                        '<span class="ipv-action-count" data-count="share">'+ipvFmtNum(shareCount)+'</span>'+
                    '</div>'+
                '</div>'+
                adTagHtml+
            '</div>'+

            socialRow+
            captionBlock+
            timeHtml+
        '</div>'
    );
}

/* ============================================================
   SKELETON LOADER MARKUP
   ============================================================ */
function _ipvSkeletonHTML(n){
    var one = '<div class="ipv-skel-post">'+
        '<div class="ipv-skel-header"><div class="ipv-skel-avatar"></div>'+
        '<div class="ipv-skel-header-lines"><div class="ipv-skel-line w1"></div><div class="ipv-skel-line w2"></div></div></div>'+
        '<div class="ipv-skel-media"></div>'+
        '<div class="ipv-skel-actions"><div class="ipv-skel-icon"></div><div class="ipv-skel-icon"></div><div class="ipv-skel-icon"></div><div class="ipv-skel-icon"></div></div>'+
        '<div class="ipv-skel-cap"></div><div class="ipv-skel-cap2"></div>'+
    '</div>';
    var out = '';
    for(var i=0;i<n;i++) out += one;
    return out;
}

/* ============================================================
   CAPTION EXPAND / COLLAPSE TOGGLES
   While any caption on a post is expanded ("more"), the post gets
   .ipv-cap-open, which hides the avatars, CTA pill and partnership
   tag on the media. They return when the caption is collapsed.
   ============================================================ */
function _ipvSyncCapState(post){
    if(!post) return;
    var open = post.querySelector('.ipv-cap-short[data-expanded="1"], .ipv-center-caption-text[data-expanded-now="1"]');
    post.classList.toggle('ipv-cap-open', !!open);
}

window.ipvToggleCaption = function(el){
    var wrap = el.closest('.ipv-cap-short');
    if(!wrap) return;
    var post = el.closest('.ipv-post');
    var expanded = wrap.dataset.expanded === '1';
    if(!expanded){
        wrap.dataset.expanded = '1';
        wrap.innerHTML = ipvLinkify(wrap.dataset.full) +
            ' <span class="ipv-more-toggle" onclick="event.stopPropagation();ipvToggleCaption(this)"> less</span>';
    } else {
        wrap.dataset.expanded = '0';
        var words = (wrap.dataset.full||'').trim().split(/\s+/).filter(Boolean);
        var trunc = ipvLinkify(words.slice(0,14).join(' '));
        wrap.innerHTML = trunc + '<span class="ipv-more-wrap" onclick="event.stopPropagation();ipvToggleCaption(this)"><span class="ipv-ellipsis">...</span><span class="ipv-more-toggle"> more</span></span>';
    }
    _ipvSyncCapState(post);
};

window.ipvToggleCenterCaption = function(el){
    var textEl = el.closest('.ipv-center-caption-text');
    if(!textEl) return;
    var post = el.closest('.ipv-post');
    var expanded = textEl.dataset.expandedNow === '1';
    if(!expanded){
        textEl.dataset.expandedNow = '1';
        textEl.innerHTML = ipvLinkify(textEl.dataset.full) +
            ' <span class="ipv-more-toggle" onclick="event.stopPropagation();ipvToggleCenterCaption(this)"> less</span>';
    } else {
        textEl.dataset.expandedNow = '0';
        var words = (textEl.dataset.full||'').trim().split(/\s+/).filter(Boolean);
        textEl.innerHTML = ipvLinkify(words.slice(0,40).join(' ')) +
            '<span class="ipv-more-wrap" onclick="event.stopPropagation();ipvToggleCenterCaption(this)"><span class="ipv-ellipsis">...</span><span class="ipv-more-toggle"> more</span></span>';
    }
    _ipvSyncCapState(post);
};

/* ============================================================
   OPEN / CLOSE THE IMMERSIVE VIEWER
   ============================================================ */
function _ipvGetProfileOwnerInfo(){
    var isOwner = false;
    try {
        if (typeof currentUserId !== 'undefined' && typeof profileOwnerId !== 'undefined') {
            isOwner = (parseInt(currentUserId, 10) === parseInt(profileOwnerId, 10));
        }
    } catch(e){}

    var username = '';
    var el = document.getElementById('profileMainUsername');
    if (el) {
        username = (el.textContent || '').replace(/\s+/g, ' ').trim();
    }
    if (!username) {
        var tb = document.querySelector('.dz-pt-username-text');
        if (tb) username = (tb.textContent || '').trim();
    }
    if (!username && document.title) {
        username = document.title.split('|')[0].trim();
    }

    return { isOwner: isOwner, username: username };
}

function _ipvComputeTitleParts(arr, focusMemeId){
    var post = focusMemeId ? arr.find(function(x){ return String(x.id)===String(focusMemeId); }) : null;
    if(!post) post = arr[0];
    if(!post) return { isOwner:true, text:'Posts' };

    var labelMap = {
        'Reposted':    'Reposts',
        'Saved':       'Saved',
        'Tagged':      'Tagged Posts',
        'Liked':       'Likes',
        'Uploads':     'Posts',
        'Collections': 'Collection'
    };
    var noun = labelMap[post.context] || 'Posts';

    if(!post.context || post.context === 'Uploads'){
        if(post.isYou) return { isOwner:true, text:'Your ' + noun };
        return { isOwner:false, username: ipvEsc(post.username||'User'), noun: noun };
    }

    var owner = _ipvGetProfileOwnerInfo();
    if(owner.isOwner) return { isOwner:true, text:'Your ' + noun };
    return { isOwner:false, username: ipvEsc(owner.username||'User'), noun: noun };
}

function _ipvRenderTopbarTitle(arr, focusMemeId){
    var titleEl = document.getElementById('ipvTopbarTitle');
    if(!titleEl) return;
    var parts = _ipvComputeTitleParts(arr, focusMemeId);
    if(parts.isOwner){
        titleEl.textContent = parts.text;
    } else {
        titleEl.innerHTML =
            '<span class="ipv-topbar-title-two-line">'+
                '<span class="ipv-topbar-username-sm">'+parts.username+'</span>'+
                '<span class="ipv-topbar-sep">·</span>'+
                '<span class="ipv-topbar-noun">'+parts.noun+'</span>'+
            '</span>';
    }
}

function _ipvComputeTopbarTrack(arr, focusMemeId){
    var post = focusMemeId ? arr.find(function(x){ return String(x.id)===String(focusMemeId); }) : null;
    if(!post) post = arr[0];
    if(!post) return { show:false };

    var isPostsTab = (!post.context || post.context === 'Uploads');
    if(!isPostsTab) return { show:false };
    if(post.isYou) return { show:false };

    return { show:true, userId:post.userId, isFollowing:!!post.isFollowing };
}

function _ipvRenderTopbarAction(arr, focusMemeId){
    var actionEl = document.getElementById('ipvTopbarAction');
    if(!actionEl) return;
    var info = _ipvComputeTopbarTrack(arr, focusMemeId);
    if(info.show){
        actionEl.innerHTML = '<button class="ipv-track-btn ipv-topbar-track-btn" data-user="'+info.userId+'" onclick="event.stopPropagation();ipvToggleTrack(this)">'+(info.isFollowing?'Untrack':'Track')+'</button>';
    } else {
        actionEl.innerHTML = '';
    }
}

function _ipvInitAllCtaPillCycles(root){
    Object.keys(_ipvCtaPillTimers).forEach(function(k){ clearInterval(_ipvCtaPillTimers[k]); });
    _ipvCtaPillTimers = {};

    root.querySelectorAll('.ipv-cta-pill[data-meme-id]').forEach(function(pill){
        var memeId = pill.dataset.memeId;
        pill.classList.remove('ipv-cta-pill-expanded');
        var expanded = false;
        _ipvCtaPillTimers[memeId] = setInterval(function(){
            expanded = !expanded;
            pill.classList.toggle('ipv-cta-pill-expanded', expanded);
        }, 3000);
    });
}

/* ============================================================
   BATCHED RENDERING — groups of 4, next 4 load as the sentinel
   scrolls into view.
   ============================================================ */
function _ipvEnsureSentinel(){
    var listEl = document.getElementById('ipvList');
    var sentinel = document.getElementById('ipvSentinel');
    if(!sentinel){
        sentinel = document.createElement('div');
        sentinel.id = 'ipvSentinel';
        sentinel.className = 'ipv-load-more';
        sentinel.innerHTML = '<div class="ipv-load-more-dot"></div><div class="ipv-load-more-dot"></div><div class="ipv-load-more-dot"></div>';
        listEl.appendChild(sentinel);
    }
    if(_ipvSentinelObserver) _ipvSentinelObserver.disconnect();
    if(!('IntersectionObserver' in window)) return;
    _ipvSentinelObserver = new IntersectionObserver(function(entries){
        entries.forEach(function(entry){
            if(entry.isIntersecting && !_ipvLoadingBatch && _ipvRenderedCount < _ipvFeedArray.length){
                _ipvLoadingBatch = true;
                setTimeout(function(){
                    _ipvRenderBatch(_ipvFeedName, _ipvBatchSize, true);
                    _ipvLoadingBatch = false;
                }, 110);
            }
        });
    }, { rootMargin: '600px 0px 600px 0px', root: document.getElementById('ipvOverlay') });
    _ipvSentinelObserver.observe(sentinel);
}

function _ipvRenderBatch(feedName, count, markEnter){
    var listEl = document.getElementById('ipvList');
    var end = Math.min(_ipvRenderedCount + count, _ipvFeedArray.length);
    var html = '';
    for(var i=_ipvRenderedCount; i<end; i++){
        html += ipvRenderPost(_ipvFeedArray[i], feedName);
    }

    var tmp = document.createElement('div');
    tmp.innerHTML = html;
    var frag = document.createDocumentFragment();
    var newPosts = Array.prototype.slice.call(tmp.children);
    newPosts.forEach(function(node){
        if(markEnter) node.classList.add('ipv-post-enter');
        frag.appendChild(node);
    });

    var sentinel = document.getElementById('ipvSentinel');
    if(sentinel) listEl.insertBefore(frag, sentinel);
    else listEl.appendChild(frag);

    _ipvRenderedCount = end;

    _ipvBindCarousels(listEl);
    _ipvInitAllSwitchers(listEl);
    _ipvInitAllCtaPillCycles(listEl);
    _ipvInitObserver();

    if(_ipvRenderedCount >= _ipvFeedArray.length){
        if(sentinel) sentinel.remove();
        if(_ipvSentinelObserver){ _ipvSentinelObserver.disconnect(); _ipvSentinelObserver = null; }
    } else {
        _ipvEnsureSentinel();
    }
}

window.ipvOpen = function(feedName, focusMemeId){
    var arr = window[feedName];
    if(!Array.isArray(arr) || !arr.length) return;

    _ipvFeedName      = feedName;
    _ipvFeedArray     = arr;
    _ipvActive        = true;
    _ipvRenderedCount = 0;
    _ipvLoadingBatch  = false;

    _ipvRenderTopbarTitle(arr, focusMemeId);
    _ipvRenderTopbarAction(arr, focusMemeId);

    var listEl = document.getElementById('ipvList');

    document.getElementById('ipvOverlay').classList.add('ipv-open');
    document.body.style.overflow = 'hidden';

    var focusIdx = focusMemeId ? arr.findIndex(function(x){ return String(x.id)===String(focusMemeId); }) : 0;
    if(focusIdx < 0) focusIdx = 0;
    var initialCount = Math.max(4, Math.ceil((focusIdx+1)/4)*4);
    initialCount = Math.min(initialCount, arr.length);

    function ipvDoInitialRender(){
        listEl.innerHTML = '';
        _ipvRenderBatch(feedName, initialCount, true);

        requestAnimationFrame(function(){
            var target = focusMemeId ? listEl.querySelector('.ipv-post[data-id="'+focusMemeId+'"]') : null;
            if(target) target.scrollIntoView({ block:'start' });
        });

        _ipvUpdateTrackMuteIcons();
    }

    if(!_ipvSkeletonShownOnce){
        listEl.innerHTML = _ipvSkeletonHTML(4);
        if(_ipvSkeletonTimer) clearTimeout(_ipvSkeletonTimer);
        _ipvSkeletonTimer = setTimeout(function(){
            _ipvSkeletonShownOnce = true;
            ipvDoInitialRender();
        }, 520);
    } else {
        ipvDoInitialRender();
    }
};

window.ipvClose = function(){
    _ipvActive = false;
    document.getElementById('ipvOverlay').classList.remove('ipv-open');
    document.body.style.overflow = '';
    document.querySelectorAll('#ipvList video').forEach(function(v){ v.pause(); });
    if(_ipvObserver){ _ipvObserver.disconnect(); _ipvObserver = null; }
    if(_ipvSentinelObserver){ _ipvSentinelObserver.disconnect(); _ipvSentinelObserver = null; }
    if(_ipvSkeletonTimer){ clearTimeout(_ipvSkeletonTimer); _ipvSkeletonTimer = null; }
    _ipvLoadingBatch = false;
    Object.keys(_ipvSwitchers).forEach(function(k){ clearInterval(_ipvSwitchers[k]); });
    _ipvSwitchers = {};
    Object.keys(_ipvCtaPillTimers).forEach(function(k){ clearInterval(_ipvCtaPillTimers[k]); });
    _ipvCtaPillTimers = {};
    _ipvVisibility = {};
    _ipvStopAudio();
    ipvCloseLikersSheet();
};

/* ============================================================
   opening the reel modal from a post, and returning to the exact
   post + slide + playback position when it closes.
   ============================================================ */
window.ipvOpenReelFromPost = function(feedName, memeId){
    var startIdx = 0, startTime = 0, startAudioTime = 0, posterDataUrl = null;
    var postEl = document.querySelector('#ipvList .ipv-post[data-id="'+memeId+'"]');
    if(postEl){
        var wrap = postEl.querySelector('.ipv-media-wrap[data-count]');
        if(wrap){
            startIdx = parseInt(wrap.dataset.activeIdx || '0', 10) || 0;
            var activeVideo = wrap.querySelector('.ipv-media-item[data-idx="'+startIdx+'"] video');
            if(activeVideo && !isNaN(activeVideo.currentTime)) startTime = activeVideo.currentTime || 0;

            if(activeVideo && activeVideo.readyState >= 2 && activeVideo.videoWidth){
                try{
                    var cvs = document.createElement('canvas');
                    cvs.width  = activeVideo.videoWidth;
                    cvs.height = activeVideo.videoHeight;
                    cvs.getContext('2d').drawImage(activeVideo, 0, 0, cvs.width, cvs.height);
                    posterDataUrl = cvs.toDataURL('image/jpeg', 0.72);
                }catch(e){ posterDataUrl = null; }
            }
        }

        var audioEl = document.getElementById('ipvAudioPlayer');
        if(audioEl && postEl.dataset.audio &&
           audioEl.getAttribute('src') === postEl.dataset.audio &&
           String(_ipvCurrentAudioPostId) === String(memeId) &&
           !isNaN(audioEl.currentTime)){
            startAudioTime = audioEl.currentTime || 0;
        }
    }

    _ipvReelOriginMemeId = memeId;
    _ipvReelOriginFeed   = feedName;

    _ipvPauseAllVideos();
    _ipvStopAudio();
    var arr = window[feedName] || [];
    var p = arr.find(function(x){ return String(x.id) === String(memeId); });
    if(!p) return;
    var mainType = (p.type === 'text') ? 'image' : p.type;
    window._ccmOpenReel(p.file, mainType, p.caption, p.pic, p.username, p.userId, feedName, p.id, startIdx, startTime, startAudioTime, posterDataUrl);
};

function _ipvApplyPlaybackToPost(playback){
    var post = document.querySelector('#ipvList .ipv-post[data-id="'+playback.memeId+'"]');
    if(!post) return;

    var wrap = post.querySelector('.ipv-media-wrap[data-count]');
    if(wrap){
        var count = parseInt(wrap.dataset.count || '1', 10) || 1;
        var curIdx = parseInt(wrap.dataset.activeIdx || '0', 10) || 0;
        var targetIdx = Math.max(0, Math.min(playback.mediaIndex || 0, count - 1));

        if(targetIdx !== curIdx && typeof wrap._ipvGoTo === 'function'){
            wrap._ipvGoTo(targetIdx - curIdx);
        }

        if(playback.currentTime > 0){
            var video = wrap.querySelector('.ipv-media-item[data-idx="'+targetIdx+'"] video');
            if(video){
                var setTime = function(){
                    try{ video.currentTime = playback.currentTime; }catch(e){}
                };
                if(video.readyState >= 1){ setTime(); }
                else { video.addEventListener('loadedmetadata', setTime, { once:true }); }
            }
        }
    }

    if(playback.audioTime > 0 && post.dataset.audio){
        var audioEl = document.getElementById('ipvAudioPlayer');
        if(audioEl){
            if(audioEl.getAttribute('src') === post.dataset.audio){
                try{ audioEl.currentTime = playback.audioTime; }catch(e){}
            } else {
                post.dataset.pendingAudioTime = String(playback.audioTime);
            }
        }
    }
}

function _ipvPauseAllVideos(){ document.querySelectorAll('#ipvList video').forEach(function(v){ v.pause(); }); }
function _ipvResumeFocusedVideo(){
    var posts = document.querySelectorAll('#ipvList .ipv-post');
    var mid = window.innerHeight / 2;
    posts.forEach(function(post){
        var r = post.getBoundingClientRect();
        if(r.top < mid && r.bottom > mid){
            var v = post.querySelector('.ipv-video');
            if(v) v.play().catch(function(){});
        }
    });
    _ipvSyncAudioFocus();
}

/* ============================================================
   AUTOPLAY-ON-SCROLL + ATTACHED-TRACK SYNC
   ============================================================ */
function _ipvInitObserver(){
    if(_ipvObserver) _ipvObserver.disconnect();
    if(!('IntersectionObserver' in window)) return;
    _ipvObserver = new IntersectionObserver(function(entries){
        entries.forEach(function(entry){
            var post = entry.target;
            var id = post.dataset.id;
            _ipvVisibility[id] = entry.intersectionRatio;
            var v = post.querySelector('.ipv-video');
            if(v){
                if(entry.isIntersecting && entry.intersectionRatio > 0.6) v.play().catch(function(){});
                else v.pause();
            }
        });
        _ipvSyncAudioFocus();
    }, { threshold:[0,.6,1] });
    document.querySelectorAll('#ipvList .ipv-post').forEach(function(post){ _ipvObserver.observe(post); });
}

function _ipvSyncAudioFocus(){
    var bestId = null, bestRatio = 0;
    Object.keys(_ipvVisibility).forEach(function(id){
        if(_ipvVisibility[id] > bestRatio){ bestRatio = _ipvVisibility[id]; bestId = id; }
    });
    var audioEl = document.getElementById('ipvAudioPlayer');
    if(bestRatio < 0.6 || !bestId){ _ipvStopAudio(); return; }
    if(bestId === _ipvCurrentAudioPostId) return;

    var postEl = document.querySelector('.ipv-post[data-id="'+bestId+'"]');
    if(!postEl) return;
    var audioUrl = postEl.dataset.audio;

    if(!audioUrl){
        if(audioEl){ audioEl.pause(); }
        _ipvCurrentAudioPostId = bestId;
        return;
    }

    _ipvCurrentAudioPostId = bestId;
    if(audioEl.getAttribute('src') !== audioUrl){
        audioEl.src = audioUrl;
        var pending = parseFloat(postEl.dataset.pendingAudioTime || '0');
        audioEl.currentTime = pending > 0 ? pending : 0;
        delete postEl.dataset.pendingAudioTime;
    }
    audioEl.muted = _ipvTrackMuted;
    audioEl.volume = (parseInt(postEl.dataset.musicVol,10) || 80) / 100;
    audioEl.play().catch(function(){});

    var vidVol = (parseInt(postEl.dataset.videoVol,10) || 40) / 100;
    postEl.querySelectorAll('video.ipv-video').forEach(function(v){ v.volume = vidVol; });

    _ipvUpdateTrackMuteIcons();
}

function _ipvStopAudio(){
    var audioEl = document.getElementById('ipvAudioPlayer');
    if(audioEl){ audioEl.pause(); }
    _ipvCurrentAudioPostId = null;
}

/* ============================================================
   MEDIA ASPECT RATIO
   ============================================================ */
var IPV_MULTI_BOX_RATIO     = 3.5 / 4;
var IPV_MULTI_FIT_TOLERANCE = 0.15;

window.ipvMeasureAspect = function(el){
    var wrap = el.closest('.ipv-media-wrap');
    if(!wrap) return;

    var w = el.tagName === 'VIDEO' ? el.videoWidth  : el.naturalWidth;
    var h = el.tagName === 'VIDEO' ? el.videoHeight : el.naturalHeight;
    if(!w || !h) return;
    var ratio = h / w;
    el.dataset.ratio = ratio;

    if(wrap.classList.contains('ipv-multi')){
        var diff = Math.abs(ratio - IPV_MULTI_BOX_RATIO) / IPV_MULTI_BOX_RATIO;
        el.classList.toggle('ipv-fit-cover', diff <= IPV_MULTI_FIT_TOLERANCE);
        return;
    }

    var idx = parseInt(el.dataset.idx || '0', 10);
    var activeIdx = parseInt(wrap.dataset.activeIdx || '0', 10);
    if(idx === activeIdx) _ipvApplyAspect(wrap, ratio);
};

var IPV_LONG_MEDIA_RATIO = 1.2;

function _ipvApplyAspect(wrap, ratio){
    var clamped = Math.max(0.55, Math.min(ratio, 1.6));
    var width = wrap.clientWidth || wrap.offsetWidth;
    if(!width) return;
    wrap.style.aspectRatio = 'auto';
    wrap.style.height = Math.round(width * clamped) + 'px';

    var post = wrap.closest('.ipv-post');
    if(post) post.classList.toggle('ipv-long-header', clamped >= IPV_LONG_MEDIA_RATIO);
}

/* ============================================================
   NICKNAME / ATTACHED-AUDIO CROSS-FADE SWITCHER
   ============================================================ */
function _ipvInitAllSwitchers(root){
    root.querySelectorAll('.ipv-post').forEach(function(post){
        var switcher = post.querySelector('[data-switcher="1"]');
        if(!switcher) return;
        var postId = post.dataset.id;
        var panes = switcher.querySelectorAll('.ipv-switch-pane');
        if(panes.length < 2) return;
        var activeIdx = 0;
        panes.forEach(function(p,i){ p.classList.toggle('active', i===0); });
        if(_ipvSwitchers[postId]) clearInterval(_ipvSwitchers[postId]);
        _ipvSwitchers[postId] = setInterval(function(){
            panes[activeIdx].classList.remove('active');
            activeIdx = (activeIdx+1) % panes.length;
            panes[activeIdx].classList.add('active');
        }, 5000);
    });
}

/* ============================================================
   PER-POST MEDIA CAROUSEL (swipe + arrows) — ANTI-SWAY

   A thumb that is scrolling the feed always wiggles sideways a
   little. So now:
     1. we wait for a 12px "dead zone" before deciding anything
     2. then LOCK the gesture:
          - clearly sideways (horizontal > 2x vertical) -> slides follow the finger
          - anything else -> it is a page scroll, the slides never move
     3. a swipe must travel ~20% of the width (max 90px) to change
        slide, otherwise it snaps back
     4. rubber-band resistance at the first / last slide
   A dataset guard means a wrap already bound in an earlier batch
   is never re-bound when a later batch runs over the whole list.
   ============================================================ */
function _ipvBindCarousels(root){
    root.querySelectorAll('.ipv-media-wrap[data-count]').forEach(function(wrap){
        if(wrap.dataset.ipvBound) return;
        var count = parseInt(wrap.dataset.count, 10);
        if(count <= 1) return;
        wrap.dataset.ipvBound = '1';

        var post  = wrap.closest('.ipv-post');
        var track = wrap.querySelector('.ipv-media-track');
        var dots  = wrap.querySelectorAll('.ipv-dot');
        var counterEl = wrap.querySelector('.ipv-media-counter');
        var idx = 0, startX = 0, startY = 0, dx = 0, dragging = false, lock = null;
        var DEAD = 12;

        function goTo(newIdx){
            newIdx = Math.max(0, Math.min(newIdx, count-1));
            idx = newIdx;
            wrap.dataset.activeIdx = String(idx);
            track.style.transform = 'translateX(-'+(idx*100)+'%)';
            dots.forEach(function(d,i){ d.classList.toggle('active', i===idx); });
            if(counterEl) counterEl.textContent = (idx+1)+'/'+count;

            /* only the visible slide's video plays */
            wrap.querySelectorAll('.ipv-media-item video').forEach(function(v){
                var vi = parseInt(v.dataset.idx, 10);
                if(vi !== idx) v.pause();
                else if(post && (_ipvVisibility[post.dataset.id] || 0) > 0.6) v.play().catch(function(){});
            });

            var videoMuteBtn = wrap.querySelector('.ipv-video-mute-btn');
            if(videoMuteBtn){
                var activeHasVideo = !!wrap.querySelector('.ipv-media-item[data-idx="'+idx+'"] video');
                videoMuteBtn.style.display = activeHasVideo ? 'flex' : 'none';
            }

            var typeTagEl = wrap.querySelector('.ipv-type-tag');
            if(typeTagEl){
                var activeItem = wrap.querySelector('.ipv-media-item[data-idx="'+idx+'"]');
                var activeType = activeItem ? (activeItem.dataset.type || 'image') : 'image';
                var info = _ipvTypeTagInfo(activeType);
                typeTagEl.innerHTML = '<i class="'+info.icon+'"></i><span>'+info.label+'</span>';
            }
        }
        wrap._ipvGoTo = function(delta){ goTo(idx + delta); };

        wrap.addEventListener('touchstart', function(e){
            if(e.touches.length !== 1){ dragging = false; lock = null; return; } // pinch etc. -> ignore
            startX = e.touches[0].clientX;
            startY = e.touches[0].clientY;
            dx = 0; lock = null; dragging = true;
        }, { passive:true });

        wrap.addEventListener('touchmove', function(e){
            if(!dragging) return;
            var rawX = e.touches[0].clientX - startX;
            var rawY = e.touches[0].clientY - startY;

            if(lock === null){
                if(Math.abs(rawX) < DEAD && Math.abs(rawY) < DEAD) return;   // still inside the dead zone
                lock = (Math.abs(rawX) > Math.abs(rawY) * 2) ? 'h' : 'v';    // only a clearly sideways swipe counts
                if(lock === 'h') track.style.transition = 'none';
            }
            if(lock !== 'h') return;                                          // vertical scroll -> slides stay put

            dx = rawX > 0 ? rawX - DEAD : rawX + DEAD;                        // start from 0 so there is no jump
            var atEdge = (idx === 0 && dx > 0) || (idx === count-1 && dx < 0);
            track.style.transform = 'translateX(calc(-'+(idx*100)+'% + '+(atEdge ? dx*0.25 : dx)+'px))';
        }, { passive:true });

        function endTouch(){
            if(!dragging) return;
            dragging = false;
            track.style.transition = 'transform .28s cubic-bezier(.22,1,.36,1)';
            var next = idx;
            if(lock === 'h'){
                var need = Math.min(90, (wrap.clientWidth || 300) * 0.2);
                if(Math.abs(dx) > need) next = dx < 0 ? idx+1 : idx-1;
            }
            lock = null; dx = 0;
            goTo(next);                                                       // always re-settle (snaps back if too small)
        }
        wrap.addEventListener('touchend', endTouch, { passive:true });
        wrap.addEventListener('touchcancel', endTouch, { passive:true });
    });
}

window.ipvArrowNav = function(btn, dir){
    var wrap = btn.closest('.ipv-media-wrap');
    if(wrap && wrap._ipvGoTo) wrap._ipvGoTo(dir);
};

/* ============================================================
   DOUBLE-TAP TO LIKE (media area)
   ============================================================ */
window.ipvHandleMediaTap = function(e, el, feedName, memeId){
    var mediaBox = el.closest('.ipv-media-wrap') || el;
    var now = Date.now();
    var lastTap = mediaBox._ipvLastTap || 0;
    var dt = now - lastTap;

    if(dt > 0 && dt < 320){
        mediaBox._ipvLastTap = 0;
        if(mediaBox._ipvTapTimer){ clearTimeout(mediaBox._ipvTapTimer); mediaBox._ipvTapTimer = null; }
        ipvDoubleTapLike(mediaBox, e, memeId, feedName);
    } else {
        mediaBox._ipvLastTap = now;
        mediaBox._ipvTapTimer = setTimeout(function(){
            ipvOpenReelFromPost(feedName, memeId);
        }, 260);
    }
};

function ipvDoubleTapLike(mediaBox, e, memeId, feedName){
    var rect = mediaBox.getBoundingClientRect();
    var cx = (e && typeof e.clientX === 'number' && e.clientX !== 0) ? e.clientX : (rect.left + rect.width/2);
    var cy = (e && typeof e.clientY === 'number' && e.clientY !== 0) ? e.clientY : (rect.top + rect.height/2);
    ipvShowHeartBurst(mediaBox, cx - rect.left, cy - rect.top);

    var post = mediaBox.closest('.ipv-post');
    var likeBtn = post ? post.querySelector('.ipv-like-btn') : null;
    if(likeBtn && !likeBtn.classList.contains('liked')){
        ipvAction(memeId, 'like', likeBtn, feedName);
    }
}

function ipvShowHeartBurst(mediaBox, x, y){
    mediaBox.querySelectorAll('.ipv-double-tap-heart, .ipv-heart-ring, .ipv-heart-particle')
        .forEach(function(el){ el.remove(); });

    var ring = document.createElement('div');
    ring.className = 'ipv-heart-ring';
    ring.style.left = x+'px';
    ring.style.top  = y+'px';
    mediaBox.appendChild(ring);
    requestAnimationFrame(function(){ ring.classList.add('show'); });
    setTimeout(function(){ ring.remove(); }, 800);

    var heart = document.createElement('div');
    heart.className = 'ipv-double-tap-heart';
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
            var distance = 60 + Math.random()*42;
            var tx       = Math.cos(rad) * distance;
            var ty       = Math.sin(rad) * distance;
            var rot      = (Math.random()*80 - 40);
            var size     = 12 + Math.random()*10;

            var p = document.createElement('div');
            p.className = 'ipv-heart-particle';
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

/* ============================================================
   MUTE — video's own audio, and the attached-track player.
   ============================================================ */
window.ipvToggleVideoMute = function(btn){
    var wrap = btn.closest('.ipv-media-wrap');
    var idx = parseInt(wrap.dataset.activeIdx || '0', 10);
    var video = wrap.querySelector('.ipv-media-item[data-idx="'+idx+'"] video');
    if(!video) return;
    video.muted = !video.muted;
    btn.querySelector('i').className = video.muted ? 'ri-volume-mute-fill' : 'ri-volume-up-fill';
};

window.ipvToggleTrackMute = function(btn){
    var audioEl = document.getElementById('ipvAudioPlayer');
    if(!audioEl) return;
    _ipvTrackMuted = !_ipvTrackMuted;
    audioEl.muted = _ipvTrackMuted;
    _ipvUpdateTrackMuteIcons();
};

function _ipvUpdateTrackMuteIcons(){
    document.querySelectorAll('#ipvList .ipv-track-mute-btn').forEach(function(btn){
        var icon = btn.querySelector('i');
        if(icon) icon.className = _ipvTrackMuted ? 'ri-volume-mute-fill' : 'ri-volume-up-fill';
    });
}

/* ============================================================
   LIKE / REPOST / FAVORITE
   ============================================================ */
window.ipvAction = function(memeId, type, btn, feedName){
    var lockKey = memeId+':'+type;
    if(_ipvActionLock[lockKey]) return;
    _ipvActionLock[lockKey] = true;

    var wasActive = btn.classList.contains('liked') || btn.classList.contains('faved');
    var next = !wasActive;
    var group = btn.closest('.ipv-action-group');
    var countEl = group ? group.querySelector('.ipv-action-count') : null;
    var prevCount = countEl ? parseInt((countEl.textContent||'0').replace(/[^\d.]/g,''))||0 : 0;

    if(type === 'like'){ btn.classList.toggle('liked', next); btn.querySelector('i').className = next?'ri-heart-3-fill':'ri-heart-3-line'; }
    if(type === 'repost'){ btn.classList.toggle('liked', next); }
    if(type === 'fav'){ btn.classList.toggle('faved', next); btn.querySelector('i').className = next?'ri-bookmark-fill':'ri-bookmark-line'; }
    if(countEl) countEl.textContent = ipvFmtNum(next ? prevCount+1 : Math.max(0,prevCount-1));

    if(type === 'repost'){
        var actionIcon = btn.querySelector('i');
        if(actionIcon){
            btn.classList.remove('arc-swap-active');
            void actionIcon.offsetWidth;
            btn.classList.add('arc-swap-active');
            actionIcon.addEventListener('animationend', function handler(){
                btn.classList.remove('arc-swap-active');
                actionIcon.removeEventListener('animationend', handler);
            });
        }
    }

    if(type === 'fav'){
        if(next && typeof ccmShowSavedToast === 'function') ccmShowSavedToast(memeId);
        else if(!next && typeof ccmHideSavedToast === 'function') ccmHideSavedToast();
    }

    fetch('profile.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action='+type+'&meme_id='+memeId
    }).then(function(r){ return r.json(); }).then(function(data){
        delete _ipvActionLock[lockKey];
        if(!data.ok){
            if(type==='like'){ btn.classList.toggle('liked', wasActive); btn.querySelector('i').className = wasActive?'ri-heart-3-fill':'ri-heart-3-line'; }
            if(type==='fav'){
                btn.classList.toggle('faved', wasActive);
                btn.querySelector('i').className = wasActive?'ri-bookmark-fill':'ri-bookmark-line';
                if(next && typeof ccmHideSavedToast === 'function') ccmHideSavedToast();
            }
            if(countEl) countEl.textContent = ipvFmtNum(prevCount);
            return;
        }
        var stateKey = { like:'liked', repost:'reposted', fav:'faved' }[type];
        var cntField = { like:'likeCount', repost:'repostCount', fav:'favCount' }[type];
        var active = data[stateKey];
        var arr = window[feedName] || [];
        var item = arr.find(function(x){ return String(x.id)===String(memeId); });
        if(item){ item[stateKey] = active; item[cntField] = data.count; }
        if(countEl) countEl.textContent = ipvFmtNum(data.count);
        if(typeof _gSyncAllFeedsForMeme === 'function'){
            _gSyncAllFeedsForMeme(memeId, cntField, data.count, stateKey, active);
        }
        if(type === 'like'){
            var post = btn.closest('.ipv-post');
            var row = post.querySelector('.ipv-social-row .ipv-likes-text');
            if(row && item) row.innerHTML = ipvLikesText(item);
        }
        if(type === 'fav' && active !== next){
            if(active && typeof ccmShowSavedToast === 'function') ccmShowSavedToast(memeId);
            else if(!active && typeof ccmHideSavedToast === 'function') ccmHideSavedToast();
        }
    }).catch(function(){
        delete _ipvActionLock[lockKey];
        if(type === 'fav' && next && typeof ccmHideSavedToast === 'function') ccmHideSavedToast();
    });
};

/* ============================================================
   TRACK / UNTRACK
   ============================================================ */
window.ipvToggleTrack = function(btn){
    var userId = btn.dataset.user;
    var wasFollowing = btn.textContent.trim() === 'Untrack';
    btn.textContent = wasFollowing ? 'Track' : 'Untrack';
    fetch('profile.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=toggle_follow&user_id='+userId
    }).then(function(r){ return r.json(); }).then(function(d){
        if(d.status !== 'ok'){ btn.textContent = wasFollowing ? 'Untrack' : 'Track'; return; }
        document.querySelectorAll('.ipv-track-btn[data-user="'+userId+'"]').forEach(function(b){
            b.textContent = d.following ? 'Untrack' : 'Track';
        });
    }).catch(function(){ btn.textContent = wasFollowing ? 'Untrack' : 'Track'; });
};

/* ============================================================
   SHARE
   ============================================================ */
window.ipvShareAction = function(memeId, btn, feedName){
    _ipvPendingShare = { memeId: memeId, feedName: feedName };

    if(typeof window.openShareModal === 'function'){
        window.openShareModal(memeId);
        _ipvWatchShareModalClose();
    } else {
        ipvShare(memeId);
    }
};

function _ipvWatchShareModalClose(){
    var panel = document.getElementById('shareModalPanel');
    if(!panel || panel._ipvWatching) return;
    panel._ipvWatching = true;

    var mo = new MutationObserver(function(){
        if(!panel.classList.contains('show')){
            mo.disconnect();
            panel._ipvWatching = false;
            _ipvRefreshShareCountFromFeed();
        }
    });
    mo.observe(panel, { attributes:true, attributeFilter:['class'] });
}

function _ipvRefreshShareCountFromFeed(){
    if(!_ipvPendingShare) return;
    var memeId   = _ipvPendingShare.memeId;
    var feedName = _ipvPendingShare.feedName;
    _ipvPendingShare = null;

    var arr = window[feedName] || [];
    var item = arr.find(function(x){ return String(x.id) === String(memeId); });
    if(!item) return;

    var post = document.querySelector('#ipvList .ipv-post[data-id="'+memeId+'"]');
    if(!post) return;
    var countEl = post.querySelector('.ipv-share-btn').closest('.ipv-action-group').querySelector('.ipv-action-count');
    if(countEl && item.shareCount !== undefined) countEl.textContent = ipvFmtNum(item.shareCount);
}

window.ipvSyncActionCount = function(memeId, type, active, count){
    var post = document.querySelector('#ipvList .ipv-post[data-id="'+memeId+'"]');
    if(!post) return;

    var btnClass = { like:'.ipv-like-btn', repost:'.ipv-repost-btn', fav:'.ipv-fav-btn' }[type];
    var btn = post.querySelector(btnClass);
    if(!btn) return;

    if(type === 'like'){
        btn.classList.toggle('liked', active);
        btn.querySelector('i').className = active ? 'ri-heart-3-fill' : 'ri-heart-3-line';
    } else if(type === 'repost'){
        btn.classList.toggle('liked', active);
    } else if(type === 'fav'){
        btn.classList.toggle('faved', active);
        btn.querySelector('i').className = active ? 'ri-bookmark-fill' : 'ri-bookmark-line';
    }

    var countEl = btn.closest('.ipv-action-group').querySelector('.ipv-action-count');
    if(countEl) countEl.textContent = ipvFmtNum(count);

    if(type === 'like'){
        var row = post.querySelector('.ipv-social-row .ipv-likes-text');
        var feedName = post.dataset.feed;
        var arr = feedName ? (window[feedName]||[]) : [];
        var item = arr.find(function(x){ return String(x.id)===String(memeId); });
        if(row && item) row.innerHTML = ipvLikesText(item);
    }
};

window.ipvSyncShareCount = function(memeId, count){
    var post = document.querySelector('#ipvList .ipv-post[data-id="'+memeId+'"]');
    if(!post) return;
    var btn = post.querySelector('.ipv-share-btn');
    if(!btn) return;
    var countEl = btn.closest('.ipv-action-group').querySelector('.ipv-action-count');
    if(countEl) countEl.textContent = ipvFmtNum(count);
    var feedName = post.dataset.feed;
    var arr = feedName ? (window[feedName]||[]) : [];
    var item = arr.find(function(x){ return String(x.id)===String(memeId); });
    if(item) item.shareCount = count;
};

window.ipvShare = function(memeId){
    var url = window.location.origin + '/profile.php?meme=' + memeId;
    if(navigator.share){
        navigator.share({ title:'Check this on Dezzme', url:url });
    } else {
        navigator.clipboard.writeText(url).then(function(){ ipvToast('Link copied!'); });
    }
};

window.ipvToast = function(msg){
    if(typeof gToast === 'function'){ gToast(msg); return; }
    var ex = document.getElementById('ipv-toast'); if(ex) ex.remove();
    var t = document.createElement('div');
    t.id = 'ipv-toast';
    t.textContent = msg;
    t.style.cssText = 'position:fixed;bottom:90px;left:50%;transform:translateX(-50%);background:rgba(20,20,25,.95);border:1px solid rgba(255,45,45,.3);color:#fff;padding:8px 20px;border-radius:50px;font-size:12px;z-index:99999999;pointer-events:none;box-shadow:0 8px 24px rgba(0,0,0,.6);transition:opacity .3s;white-space:nowrap;';
    document.body.appendChild(t);
    setTimeout(function(){ t.style.opacity='0'; setTimeout(function(){ t.remove(); },300); }, 2200);
};

/* ============================================================
   KEBAB MENU (hamburger)
   ============================================================ */
window.ipvOpenMenu = function(memeId, feedName){
    var arr = window[feedName] || [];
    var p = arr.find(function(x){ return String(x.id)===String(memeId); });
    if(!p) return;

    var listEl = document.getElementById('ccmSheetList');
    var overlayEl = document.getElementById('ccmSheetOverlay');
    var panelEl = document.getElementById('ccmSheetPanel');
    if(!listEl || !overlayEl || !panelEl){
        ipvShare(memeId);
        return;
    }
    if(typeof _ccmSheetHTML === 'function'){
        listEl.innerHTML = _ccmSheetHTML(p);
    } else {
        listEl.innerHTML =
            '<div class="idx-sheet-item" onclick="ipvShareAction('+memeId+',null,\''+feedName+'\');closeCcmMenuSheet();"><i class="ri-share-line"></i> Share Post</div>'+
            '<div class="idx-sheet-item" onclick="openReportModal({contentId:'+memeId+',contentType:\'meme\'});closeCcmMenuSheet();"><i class="ri-flag-line"></i> Report</div>';
    }
    overlayEl.classList.add('show');
    panelEl.classList.add('show');
};

/* ============================================================
   LIKERS BOTTOM SHEET
   ============================================================ */
var _ipvLikersData = { likes:[], reposts:[] };
var _ipvLikersFetchToken = 0;

window.ipvOpenLikersSheet = function(memeId, feedName){
    var arr = window[feedName] || [];
    var p = arr.find(function(x){ return String(x.id)===String(memeId); });
    var proof = (p && Array.isArray(p.socialProof)) ? p.socialProof : [];

    _ipvLikersData.likes   = proof.filter(function(sp){ return sp.action_type === 'like'; });
    _ipvLikersData.reposts = proof.filter(function(sp){ return sp.action_type === 'repost'; });

    var tabsEl = document.getElementById('ipvLikersTabs');
    if(tabsEl){
        tabsEl.querySelectorAll('.ipv-likers-tab').forEach(function(b){ b.classList.toggle('active', b.dataset.tab === 'likes'); });
    }
    _ipvRenderLikersTab('likes');
    document.getElementById('ipvLikersOverlay').classList.add('ipv-open');

    var myToken = ++_ipvLikersFetchToken;
    fetch('profile.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=get_meme_social&meme_id='+memeId
    }).then(function(r){ return r.json(); }).then(function(data){
        if(myToken !== _ipvLikersFetchToken) return;
        if(!data || !data.ok) return;

        _ipvLikersData.likes = Array.isArray(data.likes) ? data.likes.map(function(u){
            return { id:u.id, username:u.username, profile_pic:u.profile_pic, action_type:'like' };
        }) : _ipvLikersData.likes;

        _ipvLikersData.reposts = Array.isArray(data.reposts) ? data.reposts.map(function(u){
            return { id:u.id, username:u.username, profile_pic:u.profile_pic, action_type:'repost' };
        }) : _ipvLikersData.reposts;

        var activeBtn = tabsEl ? tabsEl.querySelector('.ipv-likers-tab.active') : null;
        _ipvRenderLikersTab(activeBtn ? activeBtn.dataset.tab : 'likes');
    }).catch(function(){});
};

function _ipvRenderLikersTab(tab){
    var listEl = document.getElementById('ipvLikersList');
    if(!listEl) return;
    var list = _ipvLikersData[tab] || [];
    if(!list.length){
        listEl.innerHTML = '<div class="ipv-likers-empty">No '+tab+' yet</div>';
        return;
    }
    listEl.innerHTML = list.map(function(sp){
        var isLike = sp.action_type === 'like';
        var icon = tab === 'reposts' ? 'ri-repeat-line' : (isLike ? 'ri-heart-3-fill' : 'ri-repeat-line');
        return '<a href="profile.php?user_id='+sp.id+'" class="ipv-liker-row" style="text-decoration:none;">'+
            '<img class="ipv-liker-avatar" src="'+ipvEsc(sp.profile_pic||'uploads/mee.jpeg')+'" onerror="this.src=\'uploads/mee.jpeg\'">'+
            '<span class="ipv-liker-name">'+ipvEsc(sp.username||'')+'</span>'+
            '<i class="ipv-liker-icon '+icon+'"></i>'+
        '</a>';
    }).join('');
}

window.ipvSwitchLikersTab = function(btn, tab){
    document.querySelectorAll('#ipvLikersTabs .ipv-likers-tab').forEach(function(b){ b.classList.toggle('active', b===btn); });
    _ipvRenderLikersTab(tab);
};

window.ipvCloseLikersSheet = function(){
    var el = document.getElementById('ipvLikersOverlay');
    if(el) el.classList.remove('ipv-open');
};

/* ESC closes the topmost layer */
document.addEventListener('keydown', function(e){
    if(e.key !== 'Escape') return;
    var likers = document.getElementById('ipvLikersOverlay');
    if(likers && likers.classList.contains('ipv-open')){ ipvCloseLikersSheet(); return; }
    var reel = document.getElementById('memeModal');
    if(reel && reel.classList.contains('ccm-open')) return;
    if(_ipvActive) ipvClose();
});

/* recalc single-media aspect ratios (and long-header state) on resize */
window.addEventListener('resize', function(){
    if(!_ipvActive) return;
    document.querySelectorAll('#ipvList .ipv-media-wrap:not(.ipv-multi):not(.ipv-textonly)').forEach(function(wrap){
        var idx = parseInt(wrap.dataset.activeIdx || '0', 10);
        var activeEl = wrap.querySelector('.ipv-media-item[data-idx="'+idx+'"] img, .ipv-media-item[data-idx="'+idx+'"] video');
        if(activeEl && activeEl.dataset.ratio) _ipvApplyAspect(wrap, parseFloat(activeEl.dataset.ratio));
    });
});

})();
</script>


<!-- MEME CARD CAPTION MODAL — same reels/tiktok style as home.php, now with
     multi-media Zeem galleries (swipe left/right within a post, swipe
     up/down between posts), a left-side context tag, a video progress bar,
     and a double-tap-to-like heart animation. -->
<!-- MEME CARD CAPTION MODAL — same reels/tiktok style as home.php, now with
     multi-media Zeem galleries (swipe left/right within a post, swipe
     up/down between posts), a left-side context tag, a video progress bar,
     and a double-tap-to-like heart animation. -->
<div id="memeModal" onclick="if(event.target===this) closeMemeModal()">
    <div id="ccm-stage">
        <button type="button" id="ccmFixedBackBtnMeme" class="ccm-icon-btn ccm-back-btn"
                onclick="event.stopPropagation();ccmHandleBackTap()">
            <i class="ri-arrow-left-s-line"></i>
        </button>
        <div id="ccm-inner"></div>
    </div>

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
   (matches home.php, plus multi-media gallery + context tag +
   video progress bar + double-tap like heart)

   ── GAPLESS VERTICAL SCROLL ENGINE ──
   #ccm-inner now hosts a #ccm-vtrack containing up to 3
   .ccm-vslide wrappers (prev / current / next), each already
   fully rendered before the person ever swipes. Dragging moves
   the whole track by the raw touch delta (see _ccmInitVerticalDrag),
   so the neighbor post is visibly there under your finger the
   instant you start dragging — no black gap, no rebuild-on-release.
   On a committed swipe, the neighbor slide that was already
   mounted just gets promoted to "current" (its video/img element
   is reused as-is, already decoded) — only the now-vacant side
   gets a fresh, silent, paused slide built in the background.
   ══════════════════════════════════════════════════════════ */

/* ── FIX (smooth open/close): originally this modal just toggled
   display:none -> display:flex with no transition at all, so it
   popped open/closed instantly ("bam"), unlike the immersive
   modal (IPV) which fades+slides in via opacity/transform. CSS
   cannot transition the `display` property itself, so we now add
   an `opacity` (+ a subtle scale on #ccm-stage) that gets flipped
   on a SEPARATE class (`ccm-visible`), toggled one frame after
   `ccm-open` is added (see openMemeModal/closeMemeModal in the
   script below). `ccm-open` still controls display:flex/none;
   `ccm-visible` controls the actual fade/scale transition.

   ── FIX (subtler/smoother): the original version used a fairly
   large scale delta (.96 → 1) combined with a short duration and
   an overshoot-y cubic-bezier(.22,1,.36,1), so it read as a small
   "zoom + bounce" rather than a calm settle. This version:
   - shrinks the scale delta to .985 (barely perceptible as a
     "zoom", reads as a gentle settle instead)
   - adds a tiny translateY(6px) so it settles like a soft
     fade+drift rather than pure scale, matching IPV's own feel
   - swaps to cubic-bezier(.25,.1,.25,1), a standard ease-out with
     NO overshoot past the target value, so motion decelerates
     smoothly into rest instead of bouncing
   - stretches the durations (opacity .22s→.32s, transform
     .26s→.38s) so the eye can actually perceive it as a
     transition rather than a flip ── */
#memeModal{
    display:none;
    position:fixed;inset:0;
    z-index:9999999;
    background:#000;
    align-items:center;
    justify-content:center;
    opacity:0;
    /* FIX (faster open/close): shortened from .32s -> .12s. Same
       ease-out curve, just compressed — reads as snappy/instant
       instead of a slow fade, while still being an actual CSS
       transition (not a hard cut) so it doesn't feel like a glitch. */
    transition:opacity .12s cubic-bezier(.25,.1,.25,1);
}
#memeModal.ccm-open{ display:flex; }
#memeModal.ccm-visible{ opacity:1; }

#ccm-stage{
    position:relative;
    width:100%;
    height:100%;
    max-width:480px;
    background:#000;
    overflow:hidden;
    touch-action:pan-y;
    will-change:transform;
    /* FIX: starts very slightly scaled down + drifted, settles to
       full size/position once #memeModal.ccm-visible is applied —
       a subtle, bounce-free settle-in.
       FIX (faster open/close): shortened from .38s -> .15s to match
       the quicker opacity fade above, so the whole open/close reads
       as near-instantaneous instead of taking a third of a second. */
    transform:scale(.985) translateY(6px);
    transition:transform .15s cubic-bezier(.25,.1,.25,1);
}
#memeModal.ccm-visible #ccm-stage{ transform:scale(1) translateY(0); }

#memeModal.ccm-instant,
#memeModal.ccm-instant #ccm-stage{
    transition:none !important;
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
    object-fit:cover;          /* ← FIXED: default to cover so tall media fill immediately */
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

#ccmFixedBackBtn, #ccmFixedBackBtnMeme{
    position:absolute;
    top:14px;
    left:4px;
    z-index:50;
}
@media (max-width:490px){
    #ccmFixedBackBtn, #ccmFixedBackBtnMeme{ top:11px; left:3px; }
}

/* ══════════════════════════════════════════════════════════
   VIDEO PROGRESS BAR — identical to home.php's card caption modal
   Sits as a SIBLING of .ccm-bottom (NOT inside .ccm-media-box or
   .ccm-media-gallery), so it escapes the stacking context created
   by the gallery's transform:translateX(...). If you nest it back
   inside the gallery, no z-index will save it.
   ══════════════════════════════════════════════════════════ */
.ccm-video-progress{
    position:absolute; bottom:0; left:0; right:0;
    height:26px;
    z-index:11;
    display:none;                /* toggled by JS based on active media */
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
   DOUBLE-TAP LIKE HEART BURST — red heart + pulse ring + particles
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
.ccm-paused-stack{
    display:flex; flex-direction:column; align-items:center; gap:14px;
}
.ccm-paused-row{
    display:flex; align-items:center; gap:22px;
}
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

.ccm-textonly-bg{
    position:absolute; inset:0; z-index:0;
    background-image:url('uploads/Text.jpg');
    background-size:cover; background-position:center;
}
.ccm-textonly-overlay{
    position:absolute; inset:0; z-index:0;
    background:rgba(0,0,0,.45);
}

/* ── CENTER CAPTION (text-only posts) ── */
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

/* ── TOP-RIGHT TAG STACK — media counter ("x/y") + media type tag ── */
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
.ccm-type-tag span{ font-size:10px; font-weight:700; color:#fff; text-transform:uppercase; letter-spacing:.4px; white-space:nowrap; }

/* ── TOP-LEFT TAG STACK — context tag ("Reposted"/"Saved"/etc) + group tag ── */
.ccm-top-left-tags{
    position:absolute; top:80px; left:14px; z-index:8;
    display:flex; flex-direction:column; align-items:flex-start; gap:6px;
    pointer-events:none;
}
/* Force each pill to size off its own content only — without this,
   the group-tag pill's width/height can get squeezed to match its
   sibling (context-tag) when both are stacked, instead of staying
   the same rectangle it renders as when it's alone. */
.ccm-top-left-tags > *{
    flex:none;
    width:max-content;
}
.ccm-context-tag{
    display:flex; align-items:center; gap:4px;
    background:rgba(255,45,45,.85); border:none;
    border-radius:6px; padding:5px 9px 5px 7px;
}
.ccm-context-tag i{ font-size:12.5px; color:#fff; }
.ccm-context-tag span{ font-size:10px; font-weight:700; color:#fff; text-transform:uppercase; letter-spacing:.4px; white-space:nowrap; }

/* ── GROUP / POD TAG — "From <Group Name>", clickable, links to group.php ── */
.ccm-group-tag{
    display:flex; align-items:center; gap:4px;
    background:rgba(22,22,24,.72); border:none;
    border-radius:6px; padding:5px 9px 5px 7px;
    font-size:10px; font-weight:500; color:rgba(255,255,255,.85);
    text-decoration:none; pointer-events:all;
    flex-shrink:0;
    max-width:220px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.ccm-group-tag i{ font-size:12.5px; color:rgba(255,255,255,.5); flex-shrink:0; }
.ccm-group-tag-link{
    color:#ff4c4c; font-weight:700; text-decoration:none;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.ccm-group-tag:hover .ccm-group-tag-link{ text-decoration:underline; }

/* ── RIGHT ACTION RAIL (like/repost/fav/SHARE/menu) ──
   .ccm-rail on its own sits at its automatic (lower) position — this
   is what posts WITHOUT the multi-media dashes use. When a post DOES
   render the dashes, _ccmRenderPost() adds the .ccm-rail-raised
   modifier class alongside .ccm-rail, lifting it clear of them.
   Icon/gap sizes are trimmed at every width; only the modifier's
   "bottom" value differs per breakpoint. ── */
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

/* Share icon — ri-share-forward-fill renders as the classic share glyph */
#ccm-share-btn i{ font-size:26px; }

.ccm-rail-msg{ text-decoration:none; }
.ccm-rail-msg-pic{
    width:30px; height:30px; object-fit:cover;
    border-radius:10px; border:2px solid #fff;
    box-shadow:0 3px 8px rgba(0,0,0,.55);
    display:block; transition:transform .15s ease;
}
.ccm-rail-msg:active .ccm-rail-msg-pic{ transform:scale(1.12); }

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
.ccm-user-info{ display:flex; flex-direction:column; justify-content:center; gap:0px; min-width:0; flex:1; }
.ccm-user-line1{ display:flex; align-items:center; gap:8px; min-width:0; line-height:1.1; margin-bottom:4px; }
.ccm-username{
    color:#fff; font-weight:700; font-size:14px; cursor:pointer;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    text-shadow:0 1px 3px rgba(0,0,0,.5); line-height:1.1;
}
.ccm-nickname{ color:rgba(255,255,255,.5); font-size:11.5px; font-weight:500; margin:0; line-height:1.1; }

.ccm-info-switcher{
    position:relative;
    min-height:14px;
}
.ccm-switch-pane{
    position:absolute;
    left:0; top:0; right:0;
    opacity:0;
    pointer-events:none;
    transition:opacity .35s ease;
}
.ccm-switch-pane.active{
    position:relative;
    opacity:1;
    pointer-events:all;
}

.ccm-audio-tag{
    display:flex;
    align-items:center;
    gap:7px;
    max-width:100%;
}
.ccm-audio-icon{
    display:inline-flex;
    align-items:flex-end;
    gap:2px;
    height:11px;
    flex-shrink:0;
}

.ccm-eq-bar{
    width:2.5px;
    border-radius:2px;
    background:rgba(255,255,255,.75);
    display:inline-block;
    transform-origin:bottom;
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
    position:relative;
    overflow:hidden;
    max-width:230px;
    flex:0 1 auto;
    display:flex;
    align-items:center;
}

.ccm-audio-track-text{
    display:inline-block;
    white-space:nowrap;
    font-size:12.5px;
    font-weight:500;
    line-height:1;
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
.ccm-social-proof-strip.ccm-sp-hidden{
    opacity:0;
    transform:translateY(10px);
}
/* base / desktop rule */
.ccm-social-proof-strip.ccm-sp-raised{ bottom:195px; }  /* was 210px, base is 175px → now only +20 */
.ccm-sp-avatar-wrap{ position:relative; pointer-events:all; display:inline-block; text-decoration:none; }
.ccm-sp-avatar-wrap:nth-child(1){ animation:ccmSpFloat 3s ease-in-out infinite; }
.ccm-sp-avatar-wrap:nth-child(2){ animation:ccmSpFloat 3s ease-in-out infinite .4s; }
.ccm-sp-avatar-wrap:nth-child(3){ animation:ccmSpFloat 3s ease-in-out infinite .8s; }
.ccm-sp-avatar-wrap:nth-child(4){ animation:ccmSpFloat 3s ease-in-out infinite 1.2s; }
@keyframes ccmSpFloat{ 0%,100%{ transform:translateY(0); } 50%{ transform:translateY(-6px); } }
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

.ccm-sp-badge svg{
    width:9px;
    height:9px;
    display:block;
    color:#fff;
    flex-shrink:0;
}

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
.ccm-track-btn.following{ background:transparent; border:none; color:#ff2b2b; text-decoration:underline; text-decoration-color:#ff2b2b; }
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
    max-height:34vh;
    overflow-y:auto;
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
    color:#ff2b2b; font-size:inherit; font-weight:600;
    line-height:inherit;
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

/* ══════════════════════════════════════════════════════════
   AI-GENERATED TAG (top-left stack, alongside context/group tags)
   ══════════════════════════════════════════════════════════ */
.ccm-ai-tag{
    display:flex; align-items:center; gap:5px;
    background:rgba(59,130,246,.18);
    border:1px solid rgba(59,130,246,.35);
    border-radius:6px; padding:5px 9px 5px 7px;
    font-size:11px; font-weight:600; color:#fff;
    backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px);
    white-space:nowrap;
}
.ccm-ai-tag i{ font-size:12.5px; color:#7fb2ff; }

/* ══════════════════════════════════════════════════════════
   POST-TYPE AD (meme itself is an ad — rendered inside
   _ccmRenderPost, not the separate sponsorship-carousel slide)
   ══════════════════════════════════════════════════════════ */

/* Rail: replaces the message-pic button with a plain "AD" badge */
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

/* CTA pill: occupies the social-proof-strip's exact slot on ad posts */
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
    width:auto; right:auto;
    transition:right .4s cubic-bezier(.22,1,.36,1), padding .4s cubic-bezier(.22,1,.36,1),
               background .4s ease, border-color .4s ease, box-shadow .4s ease,
               opacity .22s ease, transform .22s ease;
}
.ccm-cta-pill.ccm-sp-raised{ bottom:195px; }
.ccm-cta-pill.ccm-sp-hidden{
    opacity:0;
    transform:translateY(10px);
    pointer-events:none;
}
.ccm-cta-pill-text{ position:relative; z-index:1; overflow:hidden; text-overflow:ellipsis; }
.ccm-cta-pill-chevron{
    display:inline-block; font-size:0; opacity:0; max-width:0;
    overflow:hidden; margin-left:0; flex-shrink:0;
    transition:opacity .3s ease, max-width .3s ease, margin-left .3s ease, font-size .3s ease;
}
.ccm-cta-pill.ccm-cta-pill-expanded{
    right:74px; padding:13px 18px;
    background:var(--cta-color, #FF4C4C);
    border-color:transparent;
    box-shadow:0 6px 22px rgba(0,0,0,.45);
}
.ccm-cta-pill.ccm-cta-pill-expanded .ccm-cta-pill-chevron{
    opacity:1; max-width:20px; margin-left:auto; padding-left:8px; font-size:16px;
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

@media (max-width:790px){
    .ccm-icon-btn i{ font-size:28px; }
    .ccm-search-btn i{ font-size:22px; }
    .ccm-back-btn i{ font-size:34px; }
    .ccm-brand-label{ font-size:15px; }
    .ccm-brand-date{ font-size:11.5px; }
    .ccm-top-right-tags{ top:73px; right:13px; gap:5px; }
    .ccm-top-left-tags{ top:73px; left:13px; gap:5px; }
    .ccm-type-tag{ padding:5px 8px 5px 6px; }
    .ccm-type-tag i{ font-size:12px; }
    .ccm-type-tag span{ font-size:9.5px; }
    .ccm-context-tag{ padding:5px 8px 5px 6px; }
    .ccm-context-tag i{ font-size:12px; }
    .ccm-context-tag span{ font-size:9.5px; }
    .ccm-group-tag{ font-size:9.5px; padding:4px 7px 4px 5px; }
    .ccm-group-tag i{ font-size:12px; }
    .ccm-ai-tag{ font-size:10.5px; padding:4px 7px 4px 5px; }
    .ccm-ai-tag i{ font-size:12px; }
    .ccm-media-counter{ font-size:9.5px; padding:4.5px 8px; }
    .ccm-media-arrow-left{ left:48px; }
    .ccm-media-arrow-right{ right:56px; }
    .ccm-media-arrow{ width:32px; height:32px; }
    .ccm-media-arrow i{ font-size:18px; }
    .ccm-media-dashes{ gap:5px; margin-left:-14px; margin-right:-70px; width:calc(100% + 84px); }
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
    .ccm-double-tap-heart i{ font-size:80px; }
    .ccm-video-progress{ height:16px; }
}

@media (max-width:490px){
    .ccm-icon-btn i{ font-size:26px; }
    .ccm-search-btn i{ font-size:20px; }
    .ccm-back-btn i{ font-size:32px; }
    .ccm-brand-label{ font-size:14px; }
    .ccm-brand-date{ font-size:11px; }
    .ccm-top-right-tags{ top:66px; right:12px; gap:5px; }
    .ccm-top-left-tags{ top:66px; left:12px; gap:5px; }
    .ccm-type-tag{ padding:4.5px 7px 4.5px 5px; }
    .ccm-type-tag i{ font-size:11px; }
    .ccm-type-tag span{ font-size:9px; }
    .ccm-context-tag{ padding:4.5px 7px 4.5px 5px; }
    .ccm-context-tag i{ font-size:11px; }
    .ccm-context-tag span{ font-size:9px; }
    .ccm-group-tag{ font-size:9px; padding:3.5px 6.5px 3.5px 5px; max-width:190px; }
    .ccm-group-tag i{ font-size:11px; }
    .ccm-ai-tag{ font-size:10px; padding:3.5px 6.5px 3.5px 5px; }
    .ccm-ai-tag i{ font-size:11px; }
    .ccm-media-counter{ font-size:9px; padding:4px 7px; }
    .ccm-media-arrow-left{ left:42px; }
    .ccm-media-arrow-right{ right:50px; }
    .ccm-media-arrow{ width:30px; height:30px; }
    .ccm-media-arrow i{ font-size:16px; }
    .ccm-media-dashes{ margin-left:-12px; margin-right:-62px; width:calc(100% + 74px); }
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
    .ccm-double-tap-heart i{ font-size:70px; }
    .ccm-video-progress{ height:15px; }
}

@media (max-width:390px){
    .ccm-icon-btn i{ font-size:24px; }
    .ccm-search-btn i{ font-size:19px; }
    .ccm-back-btn i{ font-size:30px; }
    .ccm-brand-label{ font-size:13px; }
    .ccm-brand-date{ font-size:10.5px; }
    .ccm-top-right-tags{ top:60px; right:10px; gap:4px; }
    .ccm-top-left-tags{ top:60px; left:10px; gap:4px; }
    .ccm-type-tag{ padding:4px 6.5px 4px 5px; }
    .ccm-type-tag i{ font-size:10px; }
    .ccm-type-tag span{ font-size:8.5px; }
    .ccm-context-tag{ padding:4px 6.5px 4px 5px; }
    .ccm-context-tag i{ font-size:10px; }
    .ccm-context-tag span{ font-size:8.5px; }
    .ccm-group-tag{ font-size:9px; padding:3px 6px 3px 4.5px; max-width:170px; }
    .ccm-group-tag i{ font-size:10.5px; }
    .ccm-ai-tag{ font-size:9.5px; padding:3px 6px 3px 4.5px; }
    .ccm-ai-tag i{ font-size:10.5px; }
    .ccm-media-counter{ font-size:8.5px; padding:3.5px 6px; }
    .ccm-media-arrow-left{ left:38px; }
    .ccm-media-arrow-right{ right:46px; }
    .ccm-media-arrow{ width:28px; height:28px; }
    .ccm-media-arrow i{ font-size:15px; }
    .ccm-media-dashes{ margin-left:-10px; margin-right:-56px; width:calc(100% + 66px); }
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
    .ccm-double-tap-heart i{ font-size:62px; }
    .ccm-video-progress{ height:14px; }
}

@media (max-width:330px){
    .ccm-icon-btn i{ font-size:22px; }
    .ccm-search-btn i{ font-size:18px; }
    .ccm-back-btn i{ font-size:28px; }
    .ccm-brand-label{ font-size:12px; }
    .ccm-brand-date{ font-size:10px; }
    .ccm-top-right-tags{ top:56px; right:9px; gap:4px; }
    .ccm-top-left-tags{ top:56px; left:9px; gap:4px; }
    .ccm-type-tag{ padding:3.5px 6px 3.5px 4.5px; }
    .ccm-type-tag i{ font-size:9px; }
    .ccm-type-tag span{ font-size:8px; }
    .ccm-context-tag{ padding:3.5px 6px 3.5px 4.5px; }
    .ccm-context-tag i{ font-size:9px; }
    .ccm-context-tag span{ font-size:8px; }
    .ccm-group-tag{ font-size:8.5px; padding:3px 5.5px 3px 4px; max-width:150px; }
    .ccm-group-tag i{ font-size:10px; }
    .ccm-ai-tag{ font-size:9px; padding:3px 5.5px 3px 4px; }
    .ccm-ai-tag i{ font-size:10px; }
    .ccm-media-counter{ font-size:8px; padding:3px 5.5px; }
    .ccm-media-arrow-left{ left:34px; }
    .ccm-media-arrow-right{ right:42px; }
    .ccm-media-arrow{ width:26px; height:26px; }
    .ccm-media-arrow i{ font-size:14px; }
    .ccm-media-dashes{ margin-left:-9px; margin-right:-50px; width:calc(100% + 59px); }
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
    .ccm-double-tap-heart i{ font-size:54px; }
    .ccm-video-progress{ height:13px; }
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
var _ccmIndex           = 0;
var _ccmMemeId          = null;
var _ccmPostUserId      = null;
var _ccmMediaIndex      = 0;
var ccmFeed             = [];
var _activeFeed         = [];
var _ccmCaptionFullHtml = '';
var _ccmCaptionTruncHtml= '';
var _ccmCaptionExpanded = false;
var _ccmInfoSwitchTimer = null;

/* ── NEW: drives the small ↔ expanded (chevron) cycle on the
   post-as-ad CTA pill, mirroring home.php's _ccmInitCtaPillCycle.
   Lives alongside _ccmInfoSwitchTimer and is cleared/restarted in
   exactly the same places that timer already is, so an ad post's
   pill never keeps animating after you've swiped away from it. ── */
var _ccmCtaPillTimer    = null;

var _ccmPendingStartIdx  = 0;
var _ccmPendingStartTime = 0;

var _ccmPendingStartAudioTime = 0;

var _ccmPendingPosterUrl = null;
var _ccmLastPosterUsed   = false;

var _ccmVSwipeLocked = false;

/* Per-post pause state, so returning to a post you manually paused
   before scrolling away keeps it paused instead of silently
   resuming audio/video behind a stale "paused" overlay. Keyed by
   meme id. */
var _ccmPausedMemeIds = {};

/* Per-post horizontal media (gallery) index, keyed by post id — NOT
   a single global. Written by ccmMediaNext/ccmMediaPrev/the swipe
   handler, and READ by _ccmFinishTrackMove every time a vertical
   swipe promotes a neighbor slide to "current" (see the fix inside
   _ccmFinishTrackMove below). Activation is authoritative over
   whatever the DOM happened to be showing before, which is what
   fixes the "media 1 -> media 3" skip when swiping away from and
   back to a multi-media post. */
var _ccmMediaIndexByPost = {};

function _ccmGetSavedMediaIndex(postId){
    if(postId === null || postId === undefined) return 0;
    var v = _ccmMediaIndexByPost[postId];
    return (typeof v === 'number' && v >= 0) ? v : 0;
}

function _ccmSaveMediaIndex(postId, idx){
    if(postId === null || postId === undefined) return;
    _ccmMediaIndexByPost[postId] = idx;
}

/* Remembers each POST's attached background track playback position,
   keyed by the post's own id — NOT by the audio file's URL. Keying
   by post id (rather than file URL) is what lets two different posts
   that happen to share the same underlying audio file stay
   independent: switching to a different post always starts that
   post's attachment at its own saved position (0 the first time)
   instead of continuing wherever a previous post's playback of that
   same file had gotten to. Returning to a post you scrolled away
   from still resumes it from where it left off, matching how the
   post's own <video> element already behaves via DOM reuse. */
var _ccmTrackPositions = {};

const currentUserId     = <?php echo (int)$current_user; ?>;
const profileOwnerId    = <?php echo (int)$profile_id; ?>;
const profileOwnerFollowing = <?php echo $follow_check ? 'true' : 'false'; ?>;

function _ccmComputeDashGap(count){
    return Math.max(3, Math.min(6, Math.round(60 / count)));
}

function _buildCcmFeed(){
    ccmFeed = [];
    _activeFeed.forEach(function(post){
        ccmFeed.push({ type:'post', data:post });
    });
}

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

function _ccmTruncateGroupName(name){
    return name || '';
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

function _ccmContextTagInfo(context){
    var map = {
        'Reposted': 'ri-repeat-line',
        'Saved':    'ri-bookmark-fill',
        'Tagged':   'ri-account-pin-box-line',
        'Liked':    'ri-heart-3-fill',
        'Uploads':  'ri-upload-2-line'
    };
    return map[context] || 'ri-price-tag-3-line';
}

/* ── FIX (the actual bug): this used to run the @mention regex
   directly against the RAW caption string, then hand the result
   straight to .innerHTML with the "more"/"see less" toggle span
   concatenated right after it. Any caption containing a stray
   <, >, &, or " (a smiley like :->, "quoted text", a URL, literal
   HTML-ish text, etc.) got interpreted by the browser's HTML parser
   as the start of a tag instead of literal text — which corrupted
   everything concatenated after it in that same innerHTML string,
   including the toggle span. Depending on the exact characters, the
   span could end up missing, mis-nested, or have its onclick
   attribute silently fail to parse as a live handler — so tapping
   "more" did nothing, but ONLY on posts whose caption happened to
   contain one of those characters. Posts with plain captions were
   never affected, which is exactly why it looked like "some posts
   work, some don't" with no code-path difference at all.

   Escaping the caption with the existing _ccmEscapeHtml() helper
   BEFORE running the @mention regex neutralizes every HTML-breaking
   character in the caption text itself, while leaving the regex free
   to keep matching @mentions normally (usernames are alphanumeric/
   underscore only, so escaping the surrounding text never touches
   the match). This removes the bug for every caption, permanently,
   regardless of what characters end up in future captions. ── */
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
    if(!slideEl) return;
    ['#ccm-social-proof', '#ccm-cta-pill', '#ccm-connected-tag'].forEach(function(sel){
        var el = slideEl.querySelector(sel);
        if(el) el.classList.toggle('ccm-sp-hidden', _ccmCaptionExpanded);
    });
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

/* Reels/TikTok/Shorts-style chrome fade during vertical swipe.
   The post's "chrome" (top bar, tags, social proof strip, the like/
   repost/fav/share rail, and the bottom user+caption block) fades
   and drifts slightly as you drag from one post to the next, and is
   only at full opacity while a post is actually settled as current
   — same as those apps. The media itself (.ccm-media-box) is
   intentionally excluded so the photo/video/text background stays
   full-brightness throughout; only the UI overlay dims. */
var CCM_OVERLAY_SELECTOR = '.ccm-top-bar, .ccm-top-left-tags, .ccm-top-right-tags, .ccm-social-proof-strip, .ccm-cta-pill, .ccm-rail, .ccm-bottom';

function _ccmOverlayEls(slideEl){
    return slideEl ? slideEl.querySelectorAll(CCM_OVERLAY_SELECTOR) : [];
}

/* progress: 0 = fully visible/resting, 1 = fully faded out.
   dir: -1/0/1, which way the chrome drifts as it fades (kept in
   sync with the swipe direction so the chrome feels like it's
   travelling with the post, not just fading in place).

   FIX (dim tuning): opacity used to go all the way to 0 at
   progress===1, which — combined with progress being measured
   against the FULL stage height while a swipe actually commits at
   just 18% of that (see stage.threshold in _ccmInitVerticalDrag) —
   meant a normal, quick flick barely dimmed the chrome at all, and
   the one time it did fully fade it looked like a flicker/pop on
   snap-back. Flooring opacity at 0.3 keeps the chrome always
   faintly present (matching how TikTok/Reels never let it go fully
   invisible mid-drag) and removes that flicker on settle. */
function _ccmSetOverlayProgress(slideEl, progress, dir){
    if(!slideEl) return;
    var opacity   = 1 - (progress * 0.7);
    var translate = (dir || 0) * progress * 16;
    _ccmOverlayEls(slideEl).forEach(function(el){
        el.style.opacity   = opacity;
        el.style.transform = 'translateY('+translate+'px)';
    });
}

/* Clears every inline opacity/transform/transition override this
   feature sets, across every slide currently mounted in the track.
   Called once a swipe (or programmatic step) has fully settled, so
   the new current slide's chrome is back to a clean default state
   instead of carrying leftover inline styles from mid-drag. */
/* ══════════════════════════════════════════════════════════
   NEW GLOBALS — Connected Zeem (linked-post) horizontal stack
   ══════════════════════════════════════════════════════════ */
var _ccmHStack           = [];   // stack of { layer: <DOM .ccm-hlayer>, item: {type:'post', data:...} }
var _ccmConnectedCache   = {};   // memeId -> fetched post payload, so re-opening the same link doesn't re-fetch
var _ccmConnectedFetchLock = false;

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

/* ── NEW: cycles the post-as-ad CTA pill between its small default
   state and an expanded state (chevron visible) every 3s, on loop,
   for as long as this ad slide is the active one. Ported verbatim
   from home.php's _ccmInitCtaPillCycle. Safe no-op on any slide
   that doesn't have a #ccm-cta-pill element — i.e. every ordinary
   (non-ad) post — so it's fine to call this unconditionally from
   _ccmActivateCurrentSlide the same way _ccmInitInfoSwitcher is. ── */
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

    var pendingTime = _ccmPendingStartAudioTime;
    _ccmPendingStartAudioTime = 0;

    var prevMemeId = audioEl.dataset.memeId || null;

    if(!item || item.type !== 'post' || !item.data.audioFile || item.data.audioIsOriginal){
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
        audioEl.currentTime = pendingTime > 0 ? pendingTime : savedTime;
        audioEl.muted = false;
    } else if(pendingTime > 0){
        audioEl.currentTime = pendingTime;
    }
    audioEl.volume = (p.musicVolume !== undefined && p.musicVolume !== null ? p.musicVolume : 80) / 100;

    var mediaBox = document.getElementById('ccm-media-box');
    if(_ccmPausedMemeIds[p.id]){
        audioEl.pause();
        if(mediaBox){
            var activeItemForPause = mediaBox.querySelector('.ccm-media-item[data-idx="'+_ccmMediaIndex+'"]') || mediaBox;
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

/* ══════════════════════════════════════════════════════════
   VIDEO PROGRESS BAR — brought in line with home.php.
   Reads/writes a SINGLE bar per slide that lives as a sibling of
   .ccm-bottom (built inside _ccmRenderPost, appended after the
   .ccm-bottom block) — NOT one bar per .ccm-media-item nested
   inside the transformed .ccm-media-gallery. That nesting was the
   bug: .ccm-media-gallery carries transform:translateX(...), which
   creates its own stacking context, so no z-index on a bar buried
   inside it could ever escape above sibling UI reliably. Skips
   updating while the user is actively dragging the thumb (bar has
   the "scrubbing" class). ══════════════════════════════════════ */
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

    var likeBtn = document.getElementById('ccm-slide') ? document.getElementById('ccm-slide').querySelector('#ccm-like-btn') : null;
    if(likeBtn && !likeBtn.classList.contains('active')){
        ccmAction('like');
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

/* ══════════════════════════════════════════════════════════
   DRAG-TO-SEEK — ported from home.php, unmodified in behavior.
   Binds ONCE per bar (bar._ccmScrubBound guards re-binding when a
   slide gets reused/promoted). getMedia() resolves both the
   currently-active gallery video AND, if this post has a separate
   attached soundtrack actually playing for it right now
   (ccmSoundtrackPlayer.dataset.memeId === _ccmMemeId), that audio
   element too — so dragging the bar seeks video and soundtrack
   together instead of letting them drift out of sync. Pausing on
   drag-start and resuming on drag-end respects _ccmPausedMemeIds,
   so a scrub never un-pauses a video the person paused on purpose.
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
        // Taller/narrower than the box: 'cover' here scales by width to
        // fill it exactly, and only crops the OVERFLOW off the top/bottom
        // — the sides are never touched in this case. Safe as-is.
        el.style.objectFit = 'cover';
        return;
    }

    // Wider/flatter than the box: 'contain' already fills the full width
    // exactly (that part is never the issue) and only leaves a gap top/
    // bottom. To close that gap WITHOUT ever cropping the sides, the only
    // option is to stretch the height — never crop, never touch width.
    var scaledHeight = cW / mediaAspect;   // height under plain 'contain'
    var fillRatio    = scaledHeight / cH;  // how close that gets to full box height

    // Tweak this: the ONLY dial that controls which lengths get stretched.
    // Raise it (closer to 1.0) to be stricter, lower it to allow looser
    // matches to stretch.
    var FILL_THRESHOLD = 0.84;

    if(fillRatio >= FILL_THRESHOLD){
        // Close enough — stretch height to fill completely. 'fill' never
        // crops; width stays exactly cW (already matched), only height
        // gets stretched from scaledHeight up to cH.
        el.style.objectFit = 'fill';
    } else {
        // Far off — leave it alone, full media visible, letterboxed top/bottom.
        el.style.objectFit = 'contain';
    }
}

function ccmToggleTrack(btn){
    var userId = btn.dataset.user;
    fetch('profile.php', {
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
        }
    });
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

    /* ── Progress bar now lives OUTSIDE the gallery (sibling of
       .ccm-bottom), so switching media items inside a multi-media
       post has to explicitly show/hide it and rebind scrub against
       whichever media item is now active — the gallery swipe itself
       doesn't touch it automatically the way it did when the bar
       was nested per-item. ── */
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

/* ──────────────────────────────────────────────────────────────
   SAVED TOAST — injected once. Shows on fav (bookmark) save, with
   a "Collections" shortcut that opens the save-to-collection modal.
   ────────────────────────────────────────────────────────────── */
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
    openSaveModal(memeId);
}
/* ─────────────────────────────────────────────────────────────── */


function _ccmRenderPost(item, isPreview){
    var p = item.data;
    var isOwnPost = (p.isYou === true) || (String(p.userId) === String(currentUserId));

    var media = (Array.isArray(p.media) && p.media.length) ? p.media.slice() : [{file:p.file, type:p.type}];
    var mediaCount = media.length;

    var mediaSet   = ['image','video','gif','sticker','stickers'];
    var firstType  = (media[0].type || '').toLowerCase();
    var isTextOnly = mediaCount === 1 && mediaSet.indexOf(firstType) === -1;

    var hasSeparateTrack = !!(p.audioFile) && !p.audioIsOriginal;
    var isMemePaused = !!_ccmPausedMemeIds[p.id];

    var initIdx, initTime, pendingPoster;
    if(isPreview){
        initIdx = 0;
        initTime = 0;
        pendingPoster = null;
    } else {
        initIdx  = isTextOnly ? 0 : Math.max(0, Math.min(_ccmPendingStartIdx, mediaCount - 1));
        initTime = _ccmPendingStartTime;
        _ccmPendingStartIdx  = 0;
        _ccmPendingStartTime = 0;
        _ccmMediaIndex = initIdx;
        pendingPoster = _ccmPendingPosterUrl;
        _ccmPendingPosterUrl = null;
    }

    var isFollowing = p.isFollowing === true;

    var capState = _ccmComputeCaptionHtml(p);
    var mediaBoxInner;
    var localCaptionTruncHtml = capState.trunc;

    if(isTextOnly){
        mediaBoxInner = '<div class="ccm-textonly-bg"></div>'+
                    '<div class="ccm-textonly-overlay"></div>'+
                    '<div class="ccm-center-caption-wrap" id="ccm-center-caption-wrap">'+
                        '<div class="ccm-center-caption-text" id="ccm-center-caption-text">'+localCaptionTruncHtml+'</div>'+
                    '</div>';
    } else {
        /* ── Per-item progress bar removed from here. It's now a
           single bar built once at the bottom of this function, as
           a sibling of .ccm-bottom — see progressBarHtml below. ── */
        mediaBoxInner = '<div class="ccm-media-gallery" id="ccm-media-gallery">'+
            media.map(function(m, i){
                var t = (m.type||'').toLowerCase();
                var isActive = (i === initIdx);
                var loadHook = isActive ? "ccmHideSkeleton(this.closest('.ccm-slide'))" : "";
                if(t === 'video'){
                    var seekHook = (isActive && initTime > 0) ? ("this.currentTime="+initTime+";") : "";
                    var mutedAttr = hasSeparateTrack ? 'muted ' : '';
                    var posterAttr = '';
                    if(isActive && pendingPoster){
                        posterAttr = 'poster="'+pendingPoster+'" ';
                        if(!isPreview) _ccmLastPosterUsed = true;
                    }
                    var autoplayAttr = (isActive && !isPreview && !isMemePaused) ? 'autoplay' : '';

                    var isAdjacent = !isPreview && mediaCount > 1 && Math.abs(i - initIdx) === 1;
                    var preloadAttr = (isActive && !isPreview) ? 'preload="auto"' :
                                       (isAdjacent ? 'preload="auto"' : 'preload="metadata"');

                    return '<div class="ccm-media-item" data-idx="'+i+'" data-type="video">'+
                        '<video src="'+m.file+'" '+posterAttr+mutedAttr+autoplayAttr+' loop playsinline '+preloadAttr+' '+
                        'onloadedmetadata="'+seekHook+'ccmFixObjectFit(this);" '+
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
    var topRightHtml = '<div class="ccm-top-right-tags">'+counterHtml+typeTagHtml+'</div>';

    var contextTagInner = '';
    if(p.context){
        var cIcon = _ccmContextTagInfo(p.context);
        contextTagInner += '<div class="ccm-context-tag"><i class="'+cIcon+'"></i><span>'+p.context+'</span></div>';
    }
    if(p.groupId){
        contextTagInner += '<a href="group.php?id='+p.groupId+'" onclick="event.stopPropagation()" class="ccm-group-tag">'+
            '<i class="ri-team-line"></i>From&nbsp;<span class="ccm-group-tag-link">'+_ccmTruncateGroupName(p.groupTitle||'Group')+'</span>'+
        '</a>';
    }
    /* ── NEW: AI-generated badge, ported from home.php's .ccm-ai-tag.
       home.php renders this as its own absolutely-positioned element
       because home.php has no shared top-left tag container. This
       file already stacks the context tag and group tag together
       inside .ccm-top-left-tags, so the AI badge is added to that
       same accumulator instead of getting its own absolute-position
       rule — it inherits the same flex stacking the other two tags
       already use, which keeps one consistent "stack of top-left
       badges" pattern rather than mixing two different positioning
       systems on the same slide. ── */
    if(p.isAiGenerated){
        contextTagInner += '<div class="ccm-ai-tag"><i class="ri-sparkling-2-fill"></i><span>AI Generated</span></div>';
    }
    var topLeftHtml = contextTagInner ? '<div class="ccm-top-left-tags">'+contextTagInner+'</div>' : '';

    var dashGap    = mediaCount > 1 ? _ccmComputeDashGap(mediaCount) : 6;
    var dashesHtml = (mediaCount > 1)
        ? '<div class="ccm-media-dashes" id="ccm-media-dashes" style="gap:'+dashGap+'px;">'+
            media.map(function(_, i){ return '<span class="ccm-dash'+(i===initIdx?' active':'')+'"></span>'; }).join('')+
          '</div>'
        : '';

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

    /* ── NEW: shared "should this bottom-left slot sit higher" flag.
       Raised either because of the paid-partnership badge sitting
       just below it, OR because this post has multi-media dashes
       running along the very bottom of .ccm-bottom — in both cases
       the strip/pill/tag needs to lift clear of what's underneath
       it. All three (.ccm-social-proof-strip / .ccm-cta-pill /
       .ccm-connected-tag) now share this single flag instead of
       each independently checking p.isPartnership on its own. ── */
    var _ccmNeedsRaise = p.isPartnership || mediaCount > 1;

    /* ── UPDATED: social proof never renders for ad posts OR
       connected-Zeem posts — the CTA pill / connected pill take
       this exact strip slot instead. A post can be an ad AND
       connected at the same time; social proof is suppressed by
       either condition alone. ── */
    var socialProofHtml = '';
    if(!p.isAd && !p.connectedMemeId && Array.isArray(p.socialProof) && p.socialProof.length){
        var spClass = 'ccm-social-proof-strip' + (_ccmNeedsRaise ? ' ccm-sp-raised' : '');
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

    /* ── NEW: post-as-ad CTA pill, ported verbatim from home.php.
       Occupies the social-proof strip's exact slot (same absolute
       position, same .ccm-sp-raised offset) since a post is never
       both an ad and something with social proof shown at once.
       Auto-cycles small ↔ expanded via the .ccm-cta-pill-expanded
       class — see _ccmInitCtaPillCycle, started from
       _ccmActivateCurrentSlide. Only renders when the post is
       flagged is_ad AND has a CTA url to send people to. ── */
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

    /* ── NEW: Connected-Zeem pill. Occupies the same slot as
       social-proof/CTA (mutually exclusive with social proof —
       see the guard on socialProofHtml above). If this post is
       ALSO an ad (ctaPillHtml is non-empty), the connected pill
       drops to a lower position via .ccm-connected-below-cta so
       it sits BELOW the CTA pill without touching it or the
       user-row/caption block underneath. Tapping it never
       navigates the page — it slides a new horizontal layer over
       the modal via ccmOpenConnectedZeem(). ── */
    var connectedHtml = '';
    if(p.connectedMemeId){
        var stackedClass = ctaPillHtml ? ' ccm-connected-below-cta' : '';
        var raisedClass  = _ccmNeedsRaise ? ' ccm-sp-raised' : '';
        var connLabel    = p.connectedLabel ? _ccmEscapeHtml(p.connectedLabel) : 'Connected Zeem';
        connectedHtml = '<a href="#" id="ccm-connected-tag" class="ccm-connected-tag'+raisedClass+stackedClass+'" '+
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

    /* Paid Partnership badge — sits under the caption, never mixed into
       the caption text itself. Brand name (if the poster set one) is
       pulled straight from p.partnerBrand; otherwise falls back to a
       generic "Paid partnership" label. */
    var partnershipHtml = '';
    if(p.isPartnership){
        var brandText = p.partnerBrand ? ' with <strong>'+_ccmEscapeHtml(p.partnerBrand)+'</strong>' : '';
        partnershipHtml = '<div class="ccm-partnership-tag"><i class="ri-handshake-line"></i>Paid partnership'+brandText+'</div>';
    }

    if(!isPreview){
        if(!pendingPoster) _ccmLastPosterUsed = false;
    }

    var railClass = 'ccm-rail' + (mediaCount > 1 ? ' ccm-rail-raised' : '');

    /* ── FIX (unnecessary skeleton flash on text-only posts): a
       text-only post's content (the caption) is already fully
       rendered and static the instant this HTML is inserted — there
       is no real image/video load to wait on, so ccmHideSkeleton()
       for these posts always fires immediately and synchronously
       from _ccmActivateCurrentSlide (see the `else if(textBg)`
       branch there). But "hide" only toggles a class; the skeleton
       still has to play out its own .32s opacity transition before
       it's actually gone, and since nothing underneath is fading in
       to match, that transition reads as a real loading screen even
       though nothing was ever loading. Media/video posts don't have
       this problem because their hide is triggered by the media's
       own onload/onloadeddata — the fade overlaps with the real
       content popping in, so the two blend together.

       Simplest correct fix: don't render the skeleton at all for
       text-only posts. There's nothing to mask, so there's nothing
       to show a loading state for. ccmHideSkeleton() already
       no-ops safely if it can't find a #ccm-skeleton element, so
       omitting it here needs no other changes. ── */
    var skeletonHtml = isTextOnly ? '' : _ccmSkeletonHtml();

    /* ── Single progress bar, sibling of .ccm-bottom, matching
       home.php exactly. Initial visibility is decided by whether
       the initially-active media item is a video; ccmUpdateMediaGallery
       keeps this in sync whenever the person swipes between media
       items inside a multi-media post. ── */
    var initialActiveType = isTextOnly ? '' : ((media[initIdx] && media[initIdx].type) || firstType).toLowerCase();
    var progressBarHtml = !isTextOnly ? (
        '<div class="ccm-video-progress" style="display:'+(initialActiveType === 'video' ? 'flex' : 'none')+';">'+
            '<div class="ccm-video-progress-track">'+
                '<div class="ccm-video-progress-fill"></div>'+
                '<div class="ccm-video-progress-thumb"></div>'+
            '</div>'+
        '</div>'
    ) : '';

    /* ── NEW: rail's message-shortcut button swaps for a plain "AD"
       badge on ad posts, ported from home.php's railMsgOrAdHtml.
       Ad posts never show the DM-shortcut avatar (there's no
       meaningful "message the advertiser" action here), so the
       slot is repurposed the exact same way home.php repurposes it
       — same footprint, `.ccm-rail-ad-label` instead of
       `.ccm-rail-msg-pic`, so the rail's spacing never shifts
       between an ordinary post and an ad post. ── */
    var railMsgOrAdHtml = '';
    if(p.isAd){
        railMsgOrAdHtml = '<span class="ccm-rail-ad-label">AD</span>';
    } else if(!isOwnPost){
        railMsgOrAdHtml = '<div class="ccm-rail-btn ccm-rail-msg" onclick="event.stopPropagation();'+(p.audioId !== undefined && p.audioId !== null ? 'ccmOpenSoundsPage('+p.userId+','+p.audioId+')' : '')+'" style="cursor:pointer;">'+
                '<img src="'+(p.pic||'uploads/mee.jpeg')+'" onerror="this.src=\'uploads/mee.jpeg\'" class="ccm-rail-msg-pic">'+
            '</div>';
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

        topLeftHtml+
        topRightHtml+

        socialProofHtml+
        ctaPillHtml+
        connectedHtml+

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

            '<button class="ccm-rail-btn" onclick="event.stopPropagation();openCcmMenuSheet()">'+
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

function _ccmSlideItem(idx){
    if(idx < 0 || idx >= ccmFeed.length) return null;
    return ccmFeed[idx];
}

function _ccmBuildSlideWrap(item, pos){
    var wrap = document.createElement('div');
    wrap.className = 'ccm-vslide';
    wrap.dataset.pos = pos;
    if(item && item.type === 'post'){
        var slideEl = document.createElement('div');
        slideEl.className = 'ccm-slide';
        if(pos === 'current') slideEl.id = 'ccm-slide';
        slideEl.innerHTML = _ccmRenderPost(item, pos !== 'current');
        wrap.appendChild(slideEl);
        /* Bind drag-to-seek the instant the slide is built — whether
           it's the live "current" slide or a preloaded prev/next
           preview — so a promoted neighbor slide is already
           scrub-ready with no re-binding needed mid-swipe. */
        wrap.querySelectorAll('.ccm-video-progress').forEach(ccmInitProgressScrub);
    }
    return wrap;
}

/* ── FIX: this used to blindly call .play() on EVERY <video> inside
   the current slide's gallery. For a multi-media post with more than
   one video, that meant several videos (and their audio) could all
   start playing at once instead of only the one actually shown.
   Now it defers entirely to ccmUpdateMediaGallery(), which already
   knows how to play only the slide at _ccmMediaIndex and
   pause/preload the rest — the same helper the swipe/arrow handlers
   use, so activation and manual navigation can never disagree with
   each other. Called unconditionally (not just when index > 0) so
   the gallery transform/dashes/counter/type-tag are always freshly
   applied on activation too.

   FIX (paused post silently resumed on return): looks up the post
   in _ccmPausedMemeIds and, if the user had manually paused it,
   tells ccmUpdateMediaGallery()/_ccmSyncSoundtrack() to keep it
   paused (and show the paused overlay) instead of autoplaying it
   again.

   NEW: also starts/stops the post-as-ad CTA pill's small↔expanded
   cycle via _ccmInitCtaPillCycle(), the same way _ccmInitInfoSwitcher
   is started/stopped here — it's a safe no-op on any slide without
   a #ccm-cta-pill element, so calling it unconditionally for every
   activated slide is fine.

   NOTE: this function now also runs correctly for a slide living
   inside a .ccm-hlayer (Connected-Zeem horizontal layer) instead of
   a .ccm-vslide, because it only ever looks the current slide up by
   id (#ccm-slide) — never by walking up to a .ccm-vslide ancestor —
   so it doesn't care which container the slide physically lives in. ── */
function _ccmActivateCurrentSlide(currentItem){
    if(currentItem && currentItem.type === 'post'){
        var capState = _ccmComputeCaptionHtml(currentItem.data);
        _ccmCaptionFullHtml  = capState.full;
        _ccmCaptionTruncHtml = capState.trunc;
        _ccmCaptionExpanded  = false;
    }

    var newMemeId = (currentItem && currentItem.type === 'post') ? currentItem.data.id : null;
    var shouldStayPaused = newMemeId !== null && !!_ccmPausedMemeIds[newMemeId];

    // Switching slides — any "Saved" toast from the previous post no longer applies.
    ccmHideSavedToast();

    var curSlide = document.getElementById('ccm-slide');
    if(curSlide){
        _ccmInitMediaSwipe(curSlide);
        _ccmInitAudioMarquee(curSlide);
        _ccmInitInfoSwitcher(curSlide);
        _ccmInitCtaPillCycle(curSlide);
        ccmUpdateMediaGallery(curSlide, shouldStayPaused);

        // Re-apply object-fit after layout is settled (handles late loads + gallery switches)
        curSlide.querySelectorAll('.ccm-media').forEach(function(el){
            if(el.tagName === 'VIDEO' ? el.videoWidth : el.naturalWidth){
                ccmFixObjectFit(el);
            }
        });

        var mediaImg = curSlide.querySelector('img.ccm-media');
        var textBg   = curSlide.querySelector('.ccm-textonly-bg');

        var activeVid = curSlide.querySelector('.ccm-media-item[data-idx="'+_ccmMediaIndex+'"] video.ccm-media')
                      || curSlide.querySelector('video.ccm-media');

        if(_ccmLastPosterUsed){
            ccmHideSkeleton(curSlide);
        } else if(textBg){
            /* FIX (text-post skeleton flash): a text-only post's
               content — the caption — is already in the DOM
               synchronously by this point. There is no real media
               to wait for; 'uploads/Text.jpg' is just decorative
               background art. The old code gated skeleton removal
               on that image's network fetch (via a throwaway
               Image() probe), so any time that one fetch was slow
               (cold cache, CDN hiccup, first request of the
               session, etc.) the skeleton stayed visible for no
               reason tied to whether the post itself was ready.
               Media/video posts never had this problem because
               their own real <img>/<video> onload/onloadeddata
               hook fires close to when the pixels are actually
               painted — there's no separate decorative-asset gate
               in their path. Hiding the skeleton immediately here
               (like the real content already is) removes that
               artificial network dependency entirely; the
               background image itself still loads and paints
               whenever it's ready, same as any lazy background art,
               it just no longer blocks the skeleton. */
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

    _ccmMemeId     = newMemeId;
    _ccmPostUserId = (currentItem && currentItem.type === 'post') ? currentItem.data.userId : null;
}

function _ccmMountTrack(centerIndex){
    _ccmVSwipeLocked = false;

    var inner = document.getElementById('ccm-inner');
    inner.querySelectorAll('.ccm-vtrack video').forEach(function(v){ v.pause(); v.src=''; v.load(); });

    var track = document.createElement('div');
    track.className = 'ccm-vtrack';
    track.id = 'ccm-vtrack';
    track.appendChild(_ccmBuildSlideWrap(_ccmSlideItem(centerIndex - 1), 'prev'));
    track.appendChild(_ccmBuildSlideWrap(_ccmSlideItem(centerIndex),     'current'));
    track.appendChild(_ccmBuildSlideWrap(_ccmSlideItem(centerIndex + 1), 'next'));

    var existingVtrack = document.getElementById('ccm-vtrack');
    if(existingVtrack) existingVtrack.remove();
    inner.insertBefore(track, inner.firstChild);

    _ccmIndex = centerIndex;
    _ccmActivateCurrentSlide(_ccmSlideItem(centerIndex));
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
        // NEW: vertical feed navigation is fully locked while a
        // Connected-Zeem layer is open — the only way out is the
        // back button (ccmHandleBackTap), same as a linked reel.
        if(_ccmHStack.length > 0) return;
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

    fetch('profile.php', {
        method:  'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body:    'action=get_connected_meme&meme_id='+encodeURIComponent(memeId)
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        _ccmConnectedFetchLock = false;
        if(!data || !data.ok || !data.post){
            ccmToast('Could not load this Zeem');
            return;
        }
        _ccmConnectedCache[memeId] = data.post;
        _ccmPushConnectedLayer(data.post);
    })
    .catch(function(){
        _ccmConnectedFetchLock = false;
        ccmToast('Network error — try again');
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

    var slideEl = document.createElement('div');
    slideEl.className = 'ccm-slide';
    slideEl.id = 'ccm-slide';
    slideEl.innerHTML = _ccmRenderPost(item, false);
    layer.appendChild(slideEl);

    layer.style.transform  = 'translateX(100%)';
    layer.style.transition = 'none';
    hstack.appendChild(layer);
    hstack.style.pointerEvents = 'all';

    void layer.offsetHeight; // force reflow so the slide-in actually animates
    layer.style.transition = 'transform .32s cubic-bezier(.22,1,.36,1)';
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

/* ── Back button dispatcher — wired to #ccmFixedBackBtnMeme's
   onclick instead of closeMemeModal() directly. If a Connected-
   Zeem layer is open, one tap slides back exactly one hop (never
   closes the modal); only once the stack is empty does back close
   the modal, same as before. ── */
function ccmHandleBackTap(){
    if(_ccmHStack.length > 0){
        ccmCloseConnectedZeem();
    } else {
        closeMemeModal();
    }
}

/* ── FIX (open-jump): moved the modal's display:flex + forced
   reflow to happen BEFORE _ccmMountTrack() runs, instead of after.

   Why this fixes the jump: ccmFixObjectFit() decides cover vs.
   contain by reading box.clientWidth/clientHeight. Previously
   _ccmMountTrack() (which renders every slide and fires the very
   first onload/onloadedmetadata -> ccmFixObjectFit pass) ran while
   #memeModal was still display:none, so #ccm-stage had 0x0
   dimensions and ccmFixObjectFit() silently bailed out
   (`if(!cW || !cH) return;`), leaving the CSS default (cover) in
   place. The instant the modal actually became visible a moment
   later, that same media's load event could fire AGAIN (or a
   already-loaded <img>'s onload simply hadn't been the deciding
   factor yet) with real dimensions this time — flipping the fit
   from cover to contain (or vice versa) and snapping the image/video
   to a different size/position. That single visible correction was
   the "jump".

   By opening the modal (display:flex) and forcing a layout pass
   FIRST, #ccm-stage already has its true width/height by the time
   _ccmMountTrack() builds and activates the first slide, so
   ccmFixObjectFit() gets the right answer on its one and only run —
   there is nothing left to correct after the media is visible. ── */
function openMemeModal(src, type, caption, pic, username, userId, feedName, memeId, startIdx, startTime, startAudioTime, posterDataUrl){
    _activeFeed = window[feedName] || (typeof profileFeed !== 'undefined' ? profileFeed : []);
    _buildCcmFeed();

    var idx = -1;
    if(memeId){
        idx = ccmFeed.findIndex(function(s){ return s.type === 'post' && String(s.data.id) === String(memeId); });
    }
    if(idx < 0){
        idx = ccmFeed.findIndex(function(s){
            return s.type === 'post' && String(s.data.userId) === String(userId) && s.data.caption === caption;
        });
    }
    var targetIdx = idx >= 0 ? idx : 0;

    _ccmPendingStartIdx  = (typeof startIdx  === 'number' && startIdx  > 0) ? startIdx  : 0;
    _ccmPendingStartTime = (typeof startTime === 'number' && startTime > 0) ? startTime : 0;
    _ccmPendingStartAudioTime = (typeof startAudioTime === 'number' && startAudioTime > 0) ? startAudioTime : 0;
    _ccmPendingPosterUrl = (typeof posterDataUrl === 'string' && posterDataUrl) ? posterDataUrl : null;

    _ccmVSwipeLocked = false;

    // NEW: always start a fresh open with no Connected-Zeem layers
    // carried over from a previous session.
    var existingHstack = document.getElementById('ccm-hstack');
    if(existingHstack){
        existingHstack.querySelectorAll('video').forEach(function(v){ v.pause(); v.src=''; });
        existingHstack.innerHTML = '';
        existingHstack.style.pointerEvents = 'none';
    }
    _ccmHStack = [];

    // ── FIX: open (display:flex) + force layout FIRST, so #ccm-stage
    // already has real width/height before any slide/media is built.
    // This is what stops ccmFixObjectFit()'s very first run from
    // seeing clientWidth/clientHeight === 0 and silently no-op'ing,
    // which is what previously caused a visible cover<->contain snap
    // a moment after the modal appeared (the "jump").
    var modal = document.getElementById('memeModal');
    modal.classList.add('ccm-instant');
    modal.classList.add('ccm-open');
    document.body.style.overflow = 'hidden';
    void modal.offsetHeight; // commit display:flex + real layout now, before mounting slides

    _ccmMountTrack(targetIdx);
    _ccmEnsureHStackEl();
    _ccmInitVerticalDrag();

    // force layout so display:flex is committed before we flip
    // opacity — this is what stops any single-frame flash of the
    // modal at opacity:0/display:none before it snaps to visible.
    void modal.offsetHeight;
    modal.classList.add('ccm-visible');

    void modal.offsetHeight;
    modal.classList.remove('ccm-instant');
}

window.ccmGetCurrentPlayback = function(){
    if(!_ccmMemeId) return null;
    var slide = document.getElementById('ccm-slide');
    if(!slide) return null;
    var mediaBox = slide.querySelector('#ccm-media-box');
    if(!mediaBox) return null;

    var activeItem = mediaBox.querySelector('.ccm-media-item[data-idx="'+_ccmMediaIndex+'"]');
    var currentTime = 0;
    if(activeItem){
        var vid = activeItem.querySelector('video.ccm-media');
        if(vid && !isNaN(vid.currentTime)) currentTime = vid.currentTime || 0;
    }

    var audioTime = 0;
    var trackEl = document.getElementById('ccmSoundtrackPlayer');
    if(trackEl && trackEl.src && !isNaN(trackEl.currentTime)) audioTime = trackEl.currentTime || 0;

    return { memeId: _ccmMemeId, mediaIndex: _ccmMediaIndex, currentTime: currentTime, audioTime: audioTime };
};

/* ── FIX (instant close, still a real transition): mirrors the
   open path — `ccm-instant` strips the transition, then
   `ccm-visible`/`ccm-open` are both removed while it's active (with
   a reflow so the removal is committed before we drop `ccm-instant`
   again), so the modal disappears in one blink without permanently
   losing its transition for the next open. ── */
function closeMemeModal(){
    // clear hstack instantly so a fresh open never starts mid-chain
    var hstack = document.getElementById('ccm-hstack');
    if(hstack){
        hstack.querySelectorAll('video').forEach(function(v){ v.pause(); v.src=''; });
        hstack.innerHTML = '';
        hstack.style.pointerEvents = 'none';
    }
    _ccmHStack = [];

    document.getElementById('ccm-inner').querySelectorAll('video').forEach(function(v){ v.pause(); v.src=''; });
    _ccmStopSoundtrack();
    if(_ccmInfoSwitchTimer){ clearInterval(_ccmInfoSwitchTimer); _ccmInfoSwitchTimer = null; }
    if(_ccmCtaPillTimer){ clearInterval(_ccmCtaPillTimer); _ccmCtaPillTimer = null; }
    ccmHideSavedToast();

    var modal = document.getElementById('memeModal');
    modal.classList.add('ccm-instant');
    modal.classList.remove('ccm-visible');
    modal.classList.remove('ccm-open');

    void modal.offsetHeight;
    modal.classList.remove('ccm-instant');

    document.body.style.overflow = '';
    _ccmMemeId     = null;
    _ccmPostUserId = null;
    _ccmMediaIndex = 0;
    _ccmVSwipeLocked = false;
}

function _gSyncAllFeedsForMeme(memeId, cntField, count, stateKey, active){
    Object.keys(window).forEach(function(key){
        if(!/Feed$/.test(key) && !/^colFeed_/.test(key)) return;
        var arr = window[key];
        if(!Array.isArray(arr)) return;
        arr.forEach(function(item){
            if(item && String(item.id) === String(memeId)){
                item[cntField] = count;
                item[stateKey] = active;
            }
        });
    });
}

var _ccmActionLocks = {};

function ccmAction(type){
    if(!_ccmMemeId) return;
    var memeId  = _ccmMemeId;
    var lockKey = memeId + ':' + type;

    if(_ccmActionLocks[lockKey]) return;
    _ccmActionLocks[lockKey] = true;

    var btnMap      = { like:'ccm-like-btn', repost:'ccm-repost-btn', fav:'ccm-fav-btn' };
    var cntMap      = { like:'ccm-like-count', repost:'ccm-repost-count', fav:'ccm-fav-count' };
    var cntField    = { like:'likeCount', repost:'repostCount', fav:'favCount' };
    var stateKey    = { like:'liked', repost:'reposted', fav:'faved' };

    var slideEl = document.getElementById('ccm-slide');
    var cmBtn  = slideEl ? slideEl.querySelector('#'+btnMap[type]) : null;
    var cmSpan = slideEl ? slideEl.querySelector('#'+cntMap[type]) : null;

    var wasActive        = cmBtn ? cmBtn.classList.contains('active') : false;
    var optimisticNext    = !wasActive;
    var prevCountText     = cmSpan ? cmSpan.textContent : '0';
    var prevCountNum      = parseInt(prevCountText, 10) || 0;
    var optimisticCount   = optimisticNext ? prevCountNum + 1 : Math.max(0, prevCountNum - 1);

    if(cmBtn)  cmBtn.classList.toggle('active', optimisticNext);
    if(cmSpan) cmSpan.textContent = optimisticCount;

    // arc-swap spin on tap — ONLY for repost, never like/fav
    if(type === 'repost' && cmBtn){
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

    // Saving to favorites: show the "Saved · Collections" toast right
    // away (optimistic, matches the optimistic button/count above).
    // Un-saving just dismisses it — no "Removed" toast requested.
    if(type === 'fav'){
        if(optimisticNext){
            ccmShowSavedToast(memeId);
        } else {
            ccmHideSavedToast();
        }
    }

    fetch('profile.php', {
        method:  'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body:    'action='+type+'&meme_id='+memeId
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        delete _ccmActionLocks[lockKey];

        if(!data.ok){
            if(cmBtn)  cmBtn.classList.toggle('active', wasActive);
            if(cmSpan) cmSpan.textContent = prevCountText;
            // Rollback: the save never actually happened, so the toast shouldn't stand.
            if(type === 'fav' && optimisticNext) ccmHideSavedToast();
            return;
        }

        var active = data[stateKey[type]];

        if(cmBtn)  cmBtn.classList.toggle('active', active);
        if(cmSpan) cmSpan.textContent = data.count;

        // Server disagreed with our optimistic guess (e.g. already faved
        // elsewhere) — reconcile the toast to match the real end state.
        if(type === 'fav' && active !== optimisticNext){
            if(active) ccmShowSavedToast(memeId); else ccmHideSavedToast();
        }

        var feedItem = ccmFeed.find(function(s){
            return s.type === 'post' && String(s.data.id) === String(memeId);
        });
        if(feedItem){
            feedItem.data[cntField[type]] = data.count;
            feedItem.data[stateKey[type]] = active;
        }

        _gSyncAllFeedsForMeme(memeId, cntField[type], data.count, stateKey[type], active);

        if(typeof window.ipvSyncActionCount === 'function'){
            window.ipvSyncActionCount(memeId, type, active, data.count);
        }

        if((type === 'repost' || type === 'fav' || type === 'like') && feedItem){
            _gSyncDropdownOnAction(type, active, feedItem.data);
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

function _gBuildDropdownCard(p, feedVarName, contextLabel){
    var mid = p.id;
    var pinBtn = '';
    if(typeof _gIsOwner !== 'undefined' && _gIsOwner){
        pinBtn = '<button class="card-pin-btn" data-meme-id="'+mid+'" title="Pin to profile" '+
            'onclick="event.stopPropagation();gTogglePin('+mid+',this)"><i class="ri-pushpin-line"></i></button>';
    }
    var addColBtn = '<button class="card-add-col-btn" title="Add to Collection" '+
        'onclick="event.stopPropagation();gOpenAddToCol('+mid+')"><i class="ri-folder-add-line"></i></button>';

    var safeCaption = (p.caption || '').replace(/\\/g,'\\\\').replace(/'/g,"\\'");
    var typeForOpen = p.type === 'text' ? 'image' : p.type;
    var openArgs = "'"+p.file+"','"+typeForOpen+"','"+safeCaption+"','"+p.pic+"','"+
                   (p.username||'')+"',"+p.userId+",'"+feedVarName+"',"+mid;

    var mediaInner;
    if(p.type === 'image'){
        mediaInner = '<img src="'+p.file+'" onclick="openMemeModal('+openArgs+')">';
    } else if(p.type === 'video'){
        mediaInner = '<video src="'+p.file+'" autoplay muted loop onclick="openMemeModal('+openArgs+')"></video>';
    } else {
        mediaInner = '<img src="uploads/Text.jpg" onclick="openMemeModal(\'uploads/Text.jpg\',\'image\',\''+
                      safeCaption+"','"+p.pic+"','"+(p.username||'')+"',"+p.userId+",'"+feedVarName+"',"+mid+')">';
    }

    var iconMap = { Reposted:'ri-repeat-line', Saved:'ri-bookmark-line', Liked:'ri-heart-3-fill' };
    var capIcon = iconMap[contextLabel] ? '<i class="'+iconMap[contextLabel]+'" style="color:#FF4C4C;"></i>' : '';

    var downloadName = p.file.replace('uploads/','');
    var card = document.createElement('div');
    card.className = 'meme-card';
    card.dataset.id = mid;
    card.dataset.ts = Math.floor(Date.now()/1000);
    card.dataset.pinned = '0';
    card.innerHTML =
        '<div class="top-bar">'+
            '<img src="'+p.pic+'" onclick="window.location.href=\'profile.php?user_id='+p.userId+'\'">'+
            '<div class="username" onclick="window.location.href=\'profile.php?user_id='+p.userId+'\'">'+
                (p.isYou ? 'You' : (p.username||'')) +
            '</div>'+
        '</div>'+
        '<div class="media-box" style="position:relative;">'+mediaInner+addColBtn+'</div>'+
        '<div class="icon-bar">'+
            '<a href="download.php?file='+encodeURIComponent(downloadName)+'" title="Download"><i class="ri-download-line"></i></a>'+
            pinBtn+
        '</div>'+
        '<div class="caption">'+capIcon+(p.caption||'')+'</div>';
    return card;
}

function _gRemoveFromGrid(grid, memeId, emptyIcon, emptyMsg){
    var card = grid.querySelector('.meme-card[data-id="'+memeId+'"]');
    if(card) card.remove();
    if(grid.querySelectorAll('.meme-card').length === 0){
        grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:40px 20px;color:#aaa;font-size:0.95rem;">'+
            '<i class="'+emptyIcon+'" style="font-size:2.5rem;display:block;margin-bottom:10px;color:#FF4C4C;"></i>'+emptyMsg+'</div>';
    }
}

function _gInsertIntoFlatGrid(grid, p, feedVarName, contextLabel){
    if(grid.querySelector('.meme-card[data-id="'+p.id+'"]')) return;
    var placeholder = grid.querySelector(':scope > div');
    if(placeholder && !placeholder.classList.contains('meme-card') && !placeholder.classList.contains('dezz-grid-loader')){
        placeholder.remove();
    }
    var newCard = _gBuildDropdownCard(p, feedVarName, contextLabel);
    grid.insertBefore(newCard, grid.firstChild);
}

function _gSyncFeedArray(feedVarName, active, p, contextLabel){
    if(!window[feedVarName]) window[feedVarName] = [];
    if(active){
        if(!window[feedVarName].some(function(f){ return f.id == p.id; })){
            var entry = Object.assign({}, p, { context: contextLabel });
            window[feedVarName].unshift(entry);
        }
    } else {
        window[feedVarName] = window[feedVarName].filter(function(f){ return f.id != p.id; });
    }
}

function _gSyncDropdownOnAction(type, active, p){
    if(type === 'repost'){
        var grid = document.getElementById('repostsGrid');
        if(grid){
            if(active) _gInsertIntoFlatGrid(grid, p, 'repostFeed', 'Reposted');
            else       _gRemoveFromGrid(grid, p.id, 'ri-repeat-line', 'No reposts yet');
        }
        _gSyncFeedArray('repostFeed', active, p, 'Reposted');

    } else if(type === 'fav'){
        var grid = document.getElementById('favsGrid');
        if(grid){
            if(active) _gInsertIntoFlatGrid(grid, p, 'favsFeed', 'Saved');
            else       _gRemoveFromGrid(grid, p.id, 'ri-bookmark-line', 'No favorites yet');
        }
        _gSyncFeedArray('favsFeed', active, p, 'Saved');

    } else if(type === 'like'){
        var likesGrid = document.getElementById('likesGrid');
        if(likesGrid){
            if(active){
                _gSyncFeedArray('likesFeed', true, p, 'Liked');
                var firstGroup = likesGrid.querySelector('.likes-date-group');
                var firstLabel = firstGroup ? firstGroup.querySelector('.likes-date-label-text') : null;
                var innerGrid;
                if(firstGroup && firstLabel && firstLabel.textContent.trim() === 'Today'){
                    innerGrid = firstGroup.querySelector('.grid');
                } else {
                    var group = document.createElement('div');
                    group.className = 'likes-date-group';
                    group.innerHTML =
                        '<div class="likes-date-label">'+
                            '<span class="likes-date-label-text">Today</span>'+
                            '<div class="likes-date-label-line"></div>'+
                            '<span class="likes-date-label-count">1 post</span>'+
                        '</div><div class="grid"></div>';
                    var emptyMsg = likesGrid.querySelector(':scope > div:not(.likes-date-group)');
                    if(emptyMsg) emptyMsg.remove();
                    likesGrid.insertBefore(group, likesGrid.firstChild);
                    innerGrid = group.querySelector('.grid');
                }
                if(innerGrid && !innerGrid.querySelector('.meme-card[data-id="'+p.id+'"]')){
                    innerGrid.insertBefore(_gBuildDropdownCard(p, 'likesFeed', 'Liked'), innerGrid.firstChild);
                }
            } else {
                _gSyncFeedArray('likesFeed', false, p, 'Liked');
                var card = likesGrid.querySelector('.meme-card[data-id="'+p.id+'"]');
                if(card){
                    var group = card.closest('.likes-date-group');
                    card.remove();
                    if(group && group.querySelectorAll('.meme-card').length === 0) group.remove();
                }
                if(likesGrid.querySelectorAll('.meme-card').length === 0){
                    likesGrid.innerHTML = '<div style="text-align:center;padding:40px 20px;color:#aaa;font-size:0.95rem;">'+
                        '<i class="ri-heart-line" style="font-size:2.5rem;display:block;margin-bottom:10px;color:#FF4C4C;"></i>No liked posts yet</div>';
                }
            }
        }
    }
}

function showToast(msg){
    if(typeof gToast === 'function'){ gToast(msg); return; }

    var existing = document.getElementById('peura-toast');
    if(existing) existing.remove();

    var t = document.createElement('div');
    t.id = 'peura-toast';
    t.textContent = msg;
    Object.assign(t.style, {
        position: 'fixed',
        bottom: '90px',
        left: '50%',
        transform: 'translateX(-50%)',
        background: 'rgba(20,20,25,.95)',
        border: '1px solid rgba(255,45,45,.3)',
        color: '#fff',
        padding: '8px 20px',
        borderRadius: '50px',
        fontSize: '12px',
        zIndex: '9999999',
        pointerEvents: 'none',
        boxShadow: '0 8px 24px rgba(0,0,0,.6)',
        transition: 'opacity .3s',
        whiteSpace: 'nowrap'
    });
    document.body.appendChild(t);
    setTimeout(function(){
        t.style.opacity = '0';
        setTimeout(function(){ t.remove(); }, 300);
    }, 2200);
}

/* Resize handler so object-fit stays correct on orientation change / desktop resize */
window.addEventListener('resize', function(){
    var slide = document.getElementById('ccm-slide');
    if(!slide) return;
    slide.querySelectorAll('.ccm-media').forEach(ccmFixObjectFit);
});


/* ────────────────────────────────────────────────────────────
   SHARE MODAL — external apps + follow-list picker → shares table
──────────────────────────────────────────────────────────── */
var _shareMemeId   = null;
var _shareAllUsers = [];
var _shareSelected = new Set();

function _shareGetLink(){
    return window.location.origin + '/profile.php?meme_id=' + _shareMemeId;
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

    fetch('profile.php?type=following')
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
    var memeId = _shareMemeId;

    fetch('profile.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=share_post&meme_id='+memeId+'&receiver_ids='+encodeURIComponent(ids)
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        btn.disabled = false;
        btn.textContent = 'Send';
        if(data.status === 'ok'){
            var countEl = document.getElementById('ccm-share-count');
            if(countEl) countEl.textContent = data.count;

            var feedItem = ccmFeed.find(function(s){
                return s.type === 'post' && String(s.data.id) === String(memeId);
            });
            if(feedItem) feedItem.data.shareCount = data.count;

            _gSyncAllFeedsForMeme(memeId, 'shareCount', data.count, 'shared', true);

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

document.getElementById('memeModal').addEventListener('wheel', function(e){
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
document.getElementById('memeModal').addEventListener('touchstart', function(e){
    ccmTouchInCaption = _ccmIsInsideExpandedCaption(e.target);
    if(ccmTouchInCaption) return;
    ccmTouchStartY = e.touches[0].clientY;
}, { passive:true });

document.getElementById('memeModal').addEventListener('touchend', function(e){
    if(ccmTouchInCaption){ ccmTouchInCaption = false; return; }
    var diff = ccmTouchStartY - e.changedTouches[0].clientY;
    if(Math.abs(diff) > 40){
        if(diff > 0) ccmLoadIndex(_ccmIndex+1);
        else         ccmLoadIndex(_ccmIndex-1);
    }
}, { passive:true });

document.addEventListener('keydown', function(e){
    if(document.getElementById('memeModal').classList.contains('ccm-open')){
        if(e.key === 'Escape')     closeMemeModal();
        if(e.key === 'ArrowDown')  ccmLoadIndex(_ccmIndex+1);
        if(e.key === 'ArrowUp')    ccmLoadIndex(_ccmIndex-1);
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


<!-- ═══════════════════════════════════════════════════════
     MEME MODAL — BOTTOM SHEET (same pattern as search.php)
     ═══════════════════════════════════════════════════════ -->
<div id="ccmSheetOverlay" class="idx-sheet-overlay" onclick="closeCcmMenuSheet()"></div>
<div id="ccmSheetPanel" class="idx-sheet-panel">
    <div class="idx-sheet-handle"></div>

    <div class="idx-sheet-track" id="ccmSheetTrack">
        <!-- SCREEN A: option list -->
        <div class="idx-sheet-screen">
            <div class="idx-sheet-list" id="ccmSheetList"></div>
        </div>

        <!-- SCREEN B: "why am I seeing this ad" detail -->
        <div class="idx-sheet-screen">
            <div class="idx-detail-header">
                <button class="idx-detail-back" onclick="ccmSheetBackToList()"><i class="ri-arrow-left-s-line"></i></button>
                <div class="idx-detail-title">Why you're seeing this Ad</div>
                <span class="idx-detail-spacer"></span>
            </div>
            <div class="idx-detail-body" id="ccmDetailBody"></div>
        </div>
    </div>

    <button class="idx-sheet-cancel" onclick="closeCcmMenuSheet()">Cancel</button>
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

/* two-line title+subtitle rows (used by ad-post items) */
.idx-sheet-item-title{font-size:13px;font-weight:500;color:#fff;line-height:1.3;}
.idx-sheet-item-sub{font-size:10.5px;color:rgba(255,255,255,0.4);margin-top:2px;line-height:1.3;}

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
    padding:16px 18px 18px;
    font-size:12.5px;line-height:1.7;color:rgba(255,255,255,0.75);
}
.idx-detail-body::-webkit-scrollbar{width:4px;}
.idx-detail-body::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.15);border-radius:999px;}

@media (min-width:791px){
    .idx-sheet-panel{
        left:50%;right:auto;bottom:auto;top:50%;
        width:100%;max-width:340px;
        border-radius:18px;
        transform:translate(-50%,-40%) scale(0.95);
        opacity:0;pointer-events:none;max-height:70vh;
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
    .idx-detail-body{padding:13px 16px 16px;font-size:12px;}
}

@media (max-width:490px){
    .idx-sheet-panel{border-radius:16px 16px 0 0;max-height:76vh;padding:6px 0 max(8px, env(safe-area-inset-bottom));backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);}
    .idx-sheet-handle{width:30px;height:3px;margin:0 auto 3px;}
    .idx-sheet-item{padding:7px 14px;font-size:12px;gap:9px;}
    .idx-sheet-item i{font-size:14px;width:16px;}
    .idx-sheet-cancel{margin:5px 10px 0;padding:8px;font-size:11.5px;border-radius:10px;}
    .idx-detail-header{padding:4px 12px 9px;}
    .idx-detail-body{padding:11px 14px 14px;font-size:11.5px;}
}

@media (max-width:390px){
    .idx-sheet-panel{border-radius:14px 14px 0 0;max-height:74vh;padding:6px 0 max(7px, env(safe-area-inset-bottom));backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);box-shadow:0 -8px 30px rgba(0,0,0,0.45);}
    .idx-sheet-handle{width:28px;height:3px;margin:0 auto 3px;}
    .idx-sheet-item{padding:6.5px 12px;font-size:11.5px;gap:8px;}
    .idx-sheet-item i{font-size:13.5px;width:15px;}
    .idx-sheet-cancel{margin:4px 9px 0;padding:7px;font-size:11px;border-radius:9px;}
    .idx-detail-body{padding:10px 12px 12px;font-size:11px;}
}

@media (max-width:330px){
    .idx-sheet-panel{border-radius:12px 12px 0 0;max-height:72vh;padding:5px 0 max(6px, env(safe-area-inset-bottom));backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);box-shadow:0 -6px 24px rgba(0,0,0,0.4);}
    .idx-sheet-handle{width:24px;height:2.5px;margin:0 auto 3px;}
    .idx-sheet-list::-webkit-scrollbar{width:3px;}
    .idx-sheet-item{padding:5.5px 10px;font-size:10.5px;gap:7px;}
    .idx-sheet-item i{font-size:12.5px;width:13px;}
    .idx-sheet-cancel{margin:4px 8px 0;padding:6px;font-size:10px;border-radius:8px;}
    .idx-detail-body{padding:9px 10px 10px;font-size:10.5px;}
}
</style>

<script>
/* ═══ Dispatcher — branches on p.isAd ═══ */
function _ccmSheetHTML(p){
    return p.isAd ? _ccmSheetAdHTML(p) : _ccmSheetPostHTML(p);
}

/* ── NORMAL POST (unchanged) ── */
function _ccmSheetPostHTML(p){
    var items = [];
    var safeCaption = (p.caption || '').replace(/\\/g,'\\\\').replace(/'/g,"\\'");

    items.push('<a class="idx-sheet-item" href="remix_upload.php?meme_id='+p.id+'"><i class="ri-scissors-cut-line"></i> Remix</a>');
    items.push('<div class="idx-sheet-item" onclick="ccmCopyCaption(\''+safeCaption+'\');closeCcmMenuSheet();"><i class="ri-file-copy-line"></i> Copy Caption</div>');

    if(p.type !== 'text' && p.file){
        items.push('<a class="idx-sheet-item" href="'+p.file+'" download><i class="ri-download-2-line"></i> Download</a>');
    }

    items.push('<div class="idx-sheet-item" onclick="ccmShowQR('+p.id+');closeCcmMenuSheet();"><i class="ri-qr-code-line"></i> QR Code</div>');
    items.push('<a class="idx-sheet-item" href="account.php?user_id='+p.userId+'"><i class="ri-user-search-line"></i> About Account</a>');
    items.push('<div class="idx-sheet-item" onclick="ccmShareMeme('+p.id+');closeCcmMenuSheet();"><i class="ri-share-line"></i> Share Post</div>');
    items.push('<div class="idx-sheet-item" onclick="closeCcmMenuSheet();openSaveModal('+p.id+');"><i class="ri-bookmark-line"></i> Add to Collection</div>');
    items.push('<div class="idx-sheet-item" onclick="openReportModal({contentId:'+p.id+',contentType:\'meme\'});closeCcmMenuSheet();"><i class="ri-flag-line"></i> Report</div>');

    return items.join('');
}

/* ── AD POST — 4 items, each with a title + small descriptive line ── */
function _ccmSheetAdHTML(p){
    var items = [];
    var uname = p.username || 'this account';

    function row(icon, onclick, href, title, sub){
        var tag = href ? 'a' : 'div';
        var hrefAttr = href ? ' href="'+href+'"' : '';
        var onclickAttr = onclick ? ' onclick="'+onclick+'"' : '';
        return '<'+tag+' class="idx-sheet-item"'+hrefAttr+onclickAttr+'>'+
            '<i class="'+icon+'"></i>'+
            '<div><div class="idx-sheet-item-title">'+title+'</div>'+
                 '<div class="idx-sheet-item-sub">'+sub+'</div></div>'+
        '</'+tag+'>';
    }

    items.push(row(
        'ri-information-line', null, 'account.php?user_id='+p.userId,
        'About this Ad',
        'See who\'s behind this ad and why it was made'
    ));

    items.push(row(
        'ri-question-line',
        'ccmShowAdReason('+JSON.stringify(p).replace(/"/g,'&quot;')+')', null,
        'Why am I seeing this Ad',
        'Learn how ads are chosen for your feed'
    ));

    items.push(row(
        'ri-bookmark-line',
        'closeCcmMenuSheet();openSaveModal('+p.id+');', null,
        'Add to Collections',
        'Save this ad to one of your collections'
    ));

    items.push(row(
        'ri-flag-line',
        'openReportModal({contentId:'+p.id+',contentType:\'meme\'});closeCcmMenuSheet();', null,
        'Report Ad',
        'Flag this ad for review — your report stays anonymous'
    ));

    return items.join('');
}

/* ═══ Detail slide — "Why am I seeing this Ad" ═══ */
function ccmShowAdReason(p){
    var uname = p.username || 'this account';
    var body =
        'This is one of <strong style="color:#fff;">'+_ccmEscHtml(uname)+'</strong>\'s ads. '+
        'You\'re seeing it because you\'re currently viewing pages posted by '+_ccmEscHtml(uname)+' — '+
        'when you browse an account\'s profile, any ads that account has placed are shown alongside their '+
        'regular posts, since that\'s an audience already interested in what they share. It isn\'t based on '+
        'anything else about your activity elsewhere on Dezzme.';

    document.getElementById('ccmDetailBody').innerHTML = body;
    document.getElementById('ccmSheetTrack').classList.add('show-detail');
}

function ccmSheetBackToList(){
    document.getElementById('ccmSheetTrack').classList.remove('show-detail');
}

function _ccmEscHtml(str){
    var div = document.createElement('div');
    div.textContent = str || '';
    return div.innerHTML;
}

function openCcmMenuSheet(){
    var item = ccmFeed[_ccmIndex];
    if(!item || item.type !== 'post') return;
    var p = item.data;

    document.getElementById('ccmSheetTrack').classList.remove('show-detail');
    document.getElementById('ccmSheetList').innerHTML = _ccmSheetHTML(p);
    document.getElementById('ccmSheetOverlay').classList.add('show');
    document.getElementById('ccmSheetPanel').classList.add('show');
}

function closeCcmMenuSheet(){
    document.getElementById('ccmSheetOverlay').classList.remove('show');
    document.getElementById('ccmSheetPanel').classList.remove('show');
    setTimeout(function(){
        document.getElementById('ccmSheetTrack').classList.remove('show-detail');
    }, 300);
}

function ccmCopyCaption(caption){
    navigator.clipboard.writeText(caption).then(function(){
        ccmToast('Caption copied!');
    }).catch(function(){
        var el = document.createElement('textarea');
        el.value = caption;
        document.body.appendChild(el);
        el.select();
        document.execCommand('copy');
        document.body.removeChild(el);
        ccmToast('Caption copied!');
    });
}

function ccmShowQR(memeId){
    var url = window.location.origin + '/profile.php?meme=' + memeId;
    var qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(url);
    var win = window.open('', '_blank', 'width=260,height=280');
    win.document.write('<body style="margin:0;background:#000;display:flex;align-items:center;justify-content:center;height:100vh;"><img src="'+qrUrl+'" style="border-radius:12px;"></body>');
}

function ccmShareMeme(memeId){
    var url = window.location.origin + '/profile.php?meme=' + memeId;
    if(navigator.share){
        navigator.share({ title: 'Check this on Dezzme', url: url });
    } else {
        navigator.clipboard.writeText(url);
        ccmToast('Link copied to clipboard!');
    }
}

function ccmToast(msg){
    var ex = document.getElementById('ccm-toast');
    if(ex) ex.remove();
    var t = document.createElement('div');
    t.id = 'ccm-toast';
    t.textContent = msg;
    t.style.cssText = 'position:fixed;bottom:90px;left:50%;transform:translateX(-50%);background:rgba(20,20,25,.95);border:1px solid rgba(255,45,45,.3);color:#fff;padding:8px 20px;border-radius:50px;font-size:12px;z-index:999999999;pointer-events:none;box-shadow:0 8px 24px rgba(0,0,0,.6);transition:opacity .3s;white-space:nowrap;';
    document.body.appendChild(t);
    setTimeout(function(){ t.style.opacity='0'; setTimeout(function(){ t.remove(); },300); },2200);
}

document.addEventListener('keydown', function(e){
    if(e.key === 'Escape'){
        var panel = document.getElementById('ccmSheetPanel');
        if(!panel || !panel.classList.contains('show')) return;
        var track = document.getElementById('ccmSheetTrack');
        if(track && track.classList.contains('show-detail')){ ccmSheetBackToList(); }
        else { closeCcmMenuSheet(); }
    }
});
</script>



<!-- SHARED MODALS -->
<div id="addToColModal" onclick="if(event.target===this)gCloseAddToCol()">
    <div class="atc-sheet">
        <div class="atc-hdr"><div class="atc-title">Add to Collection</div><button class="atc-close" onclick="gCloseAddToCol()">✕</button></div>
        <div class="atc-list" id="atcList"></div>
        <div class="atc-new-row" onclick="gOpenNameModal('create',_gColCtx);gCloseAddToCol();"><i class="ri-add-circle-line"></i> New Collection</div>
    </div>
</div>
<div class="col-opts-dd" id="colOptsDd">
    <div class="col-opts-item pin-toggle" id="colOptsPinItem" onclick="gColOptDo('toggle_pin')"><i class="ri-pushpin-line"></i> <span id="colOptsPinLabel">Pin Collection</span></div>
    <div class="col-opts-item vis-toggle" id="colOptsVisItem" onclick="gColOptDo('toggle_vis')"><i class="ri-earth-line"></i> <span id="colOptsVisLabel">Make Public</span></div>
    <div class="col-opts-item" onclick="gColOptDo('rename')"><i class="ri-edit-line"></i> Rename</div>
    <div class="col-opts-item danger" onclick="gColOptDo('delete')"><i class="ri-delete-bin-line"></i> Delete</div>
</div>
<div id="colNameModal" onclick="if(event.target===this)gCloseNameModal()">
    <div class="col-nm-box">
        <h3 id="colNmTitle">New Collection</h3>
        <input type="text" class="col-nm-input" id="colNmInput" placeholder="e.g. Cartoon Memes" maxlength="60" onkeydown="if(event.key==='Enter')gSaveName()">
        <div class="col-vis-row" id="colVisRow">
            <label class="col-vis-label" for="colVisToggle"><i class="ri-earth-line"></i> Make Public</label>
            <label class="col-vis-toggle"><input type="checkbox" id="colVisToggle"><span class="col-vis-slider"></span></label>
        </div>
        <div class="col-nm-btns">
            <button class="col-nm-cancel" onclick="gCloseNameModal()">Cancel</button>
            <button class="col-nm-save" onclick="gSaveName()">Save</button>
        </div>
    </div>
</div>

<script>
// ─────────────────────────────────────────────────────────
//  PIN TOGGLE ENGINE
// ─────────────────────────────────────────────────────────
var _gPinnedMemeIds = <?= json_encode($pinned_meme_ids) ?>;
var _gIsOwner = <?= ($current_user == $profile_id) ? 'true' : 'false' ?>;

function gTogglePin(memeId, btn) {
    if(!_gIsOwner) return;
    var isPinned = btn.classList.contains('pinned');

    if(!isPinned && _gPinnedMemeIds.length >= 3) {
        gToast('Max 3 pins allowed. Unpin one first.');
        return;
    }

    fetch(window.location.href, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'col_action=' + (isPinned ? 'unpin_post' : 'pin_post') + '&meme_id=' + memeId
    }).then(function(r){return r.json();}).then(function(d){
        if(d.status !== 'ok') { gToast(d.message || 'Could not update pin'); return; }

        // Update local pin list
        if(isPinned) {
            _gPinnedMemeIds = _gPinnedMemeIds.filter(function(id){return id != memeId;});
        } else {
            _gPinnedMemeIds.unshift(memeId);
        }

        // Update all pin buttons for this meme across all grids
        document.querySelectorAll('.card-pin-btn[data-meme-id="'+memeId+'"]').forEach(function(b){
            b.classList.toggle('pinned', !isPinned);
            b.title = !isPinned ? 'Unpin from profile' : 'Pin to profile';
            b.querySelector('i').className = 'ri-pushpin-' + (!isPinned ? 'fill' : 'line');
        });

        // Update pin indicator icons on all cards with this meme id
        document.querySelectorAll('.meme-card[data-id="'+memeId+'"]').forEach(function(card){
            card.dataset.pinned = !isPinned ? '1' : '0';
            var existing = card.querySelector('.card-pin-indicator');
            if(!isPinned) {
                if(!existing) {
                    var ind = document.createElement('div');
                    ind.className = 'card-pin-indicator';
                    ind.innerHTML = '<i class="ri-pushpin-fill"></i>';
                    card.querySelector('.media-box').appendChild(ind);
                }
            } else {
                if(existing) existing.remove();
            }
        });

        // Re-sort all grids to move pinned card to top
        _gResortAllGrids(memeId, !isPinned);

        gToast(!isPinned ? '📌 Pinned to profile' : 'Unpinned from profile');
    }).catch(function(){ gToast('Something went wrong'); });
}

function _gResortAllGrids(memeId, isNowPinned) {
    var grids = ['memesGrid','repostsGrid','tagsGrid','favsGrid','uploadsGrid'];
    grids.forEach(function(gridId){
        var grid = document.getElementById(gridId);
        if(!grid) return;
        var cards = Array.from(grid.querySelectorAll('.meme-card'));
        if(isNowPinned) {
            // Move this card to front, after other pinned cards
            var thisCard = cards.find(function(c){return c.dataset.id == memeId;});
            if(!thisCard) return;
            var pinnedCards = cards.filter(function(c){return c.dataset.pinned==='1'&&c.dataset.id!=memeId;});
            var insertAfter = pinnedCards.length > 0 ? pinnedCards[pinnedCards.length-1] : null;
            if(insertAfter && insertAfter.nextSibling) {
                grid.insertBefore(thisCard, insertAfter.nextSibling);
            } else if(!insertAfter) {
                // No other pinned cards, insert at very top
                var firstCard = cards[0];
                if(firstCard && firstCard !== thisCard) grid.insertBefore(thisCard, firstCard);
            }
        } else {
            // Move this card to after last pinned card (i.e. first non-pinned slot, sorted newest)
            var allCards = Array.from(grid.querySelectorAll('.meme-card'));
            var thisCard = allCards.find(function(c){return c.dataset.id == memeId;});
            if(!thisCard) return;
            var firstNonPinned = allCards.find(function(c){return c.dataset.pinned!=='1';});
            if(firstNonPinned && firstNonPinned !== thisCard) {
                grid.insertBefore(thisCard, firstNonPinned);
            }
        }
    });
}

// ─────────────────────────────────────────────────────────
//  COLLECTIONS ENGINE
// ─────────────────────────────────────────────────────────
var _gProfileId    = <?= (int)$profile_id ?>;
var _gCols         = <?= json_encode(array_values($user_collections)) ?>;
// Always the LOGGED-IN user's own collections, regardless of whose
// profile is being viewed. _gCols stays scoped to $profile_id (for
// browsing folders on a profile); _gMyCols is what the "Add to
// Collection" modal must always use, since you only ever save into
// YOUR OWN collections.
var _gMyCols       = <?= json_encode(array_values($my_collections)) ?>;
var _gPinnedColIds = <?= json_encode($pinned_col_ids) ?>;
var _gActiveMemeId=null,_gActiveColId=null,_gActiveColCtx=null,_gColCtx='favs',_gNmMode='create',_gOptsColId=null,_gOptsColName=null,_gOptsColCtx=null,_gOptsColPublic=false,_gOptsColPinned=false;

// ── FIX: normalize ID/number fields coming from PHP/mysqli ──
function _gNormalizeCols(arr){
    (arr||[]).forEach(function(c){
        c.id              = parseInt(c.id, 10);
        c.item_count      = parseInt(c.item_count, 10) || 0;
        c.is_public       = parseInt(c.is_public, 10) || 0;
        c.created_at      = c.created_at      ? parseInt(c.created_at, 10)      : null;
        c.latest_addition = c.latest_addition ? parseInt(c.latest_addition, 10) : null;
    });
    return arr || [];
}

_gCols         = _gNormalizeCols(_gCols);
_gMyCols       = _gNormalizeCols(_gMyCols);
_gPinnedColIds = (_gPinnedColIds || []).map(function(id){ return parseInt(id, 10); });

function _gIsColPinned(id){
    id = parseInt(id, 10);
    return _gPinnedColIds.indexOf(id) !== -1;
}

var _gMap={
    memes:   {sortBar:'memesSortBar',  grid:'memesGrid',   colSec:'memesColSec',   folders:'memesColFolders',   foldGrid:'memesColGrid',   detail:'memesColDetail',   dtTitle:'memesColTitle',   dtCount:'memesColCount',   dtGrid:'memesColDetailGrid',   nPill:'memesN',   oPill:'memesO',   cPill:'memesC',   searchInput:'memesColSearch',   searchClear:'memesColSearchClear',   noResults:'memesColNoResults',   dtSearch:'memesDetailSearch',   dtNoResults:'memesDetailNoResults',   dtSearchClear:'memesDetailSearchClear'   },
    reposts: {sortBar:'repostsSortBar',grid:'repostsGrid', colSec:'repostsColSec', folders:'repostsColFolders', foldGrid:'repostsColGrid', detail:'repostsColDetail', dtTitle:'repostsColTitle', dtCount:'repostsColCount', dtGrid:'repostsColDetailGrid', nPill:'repostsN', oPill:'repostsO', cPill:'repostsC', searchInput:'repostsColSearch', searchClear:'repostsColSearchClear', noResults:'repostsColNoResults', dtSearch:'repostsDetailSearch', dtNoResults:'repostsDetailNoResults', dtSearchClear:'repostsDetailSearchClear' },
    tags:    {sortBar:'tagsSortBar',   grid:'tagsGrid',    colSec:'tagsColSec',    folders:'tagsColFolders',    foldGrid:'tagsColGrid',    detail:'tagsColDetail',    dtTitle:'tagsColTitle',    dtCount:'tagsColCount',    dtGrid:'tagsColDetailGrid',    nPill:'tagsN',    oPill:'tagsO',    cPill:'tagsC',    searchInput:'tagsColSearch',    searchClear:'tagsColSearchClear',    noResults:'tagsColNoResults',    dtSearch:'tagsDetailSearch',    dtNoResults:'tagsDetailNoResults',    dtSearchClear:'tagsDetailSearchClear'    },
    favs:    {sortBar:'favsSortBar',   grid:'favsGrid',    colSec:'favsColSec',    folders:'favsColFolders',    foldGrid:'favsColGrid',    detail:'favsColDetail',    dtTitle:'favsColTitle',    dtCount:'favsColCount',    dtGrid:'favsColDetailGrid',    nPill:'favsN',    oPill:'favsO',    cPill:'favsC',    searchInput:'favsColSearch',    searchClear:'favsColSearchClear',    noResults:'favsColNoResults',    dtSearch:'favsDetailSearch',    dtNoResults:'favsDetailNoResults',    dtSearchClear:'favsDetailSearchClear'    },
    likes:   {sortBar:'likesSortBar',  grid:'likesGrid',   colSec:'likesColSec',   folders:'likesColFolders',   foldGrid:'likesColGrid',   detail:'likesColDetail',   dtTitle:'likesColTitle',   dtCount:'likesColCount',   dtGrid:'likesColDetailGrid',   nPill:'likesN',   oPill:'likesO',   cPill:'likesC',   searchInput:'likesColSearch',   searchClear:'likesColSearchClear',   noResults:'likesColNoResults',   dtSearch:'likesDetailSearch',   dtNoResults:'likesDetailNoResults',   dtSearchClear:'likesDetailSearchClear'   },
    uploads: {sortBar:'uploadsSortBar',grid:'uploadsGrid', colSec:'uploadsColSec', folders:'uploadsColFolders', foldGrid:'uploadsColGrid', detail:'uploadsColDetail', dtTitle:'uploadsColTitle', dtCount:'uploadsColCount', dtGrid:'uploadsColDetailGrid', nPill:'uploadsN', oPill:'uploadsO', cPill:'uploadsC', searchInput:'uploadsColSearch', searchClear:'uploadsColSearchClear', noResults:'uploadsColNoResults', dtSearch:'uploadsDetailSearch', dtNoResults:'uploadsDetailNoResults', dtSearchClear:'uploadsDetailSearchClear' }
};

// Smart number formatter (mirrors PHP dezzFmt)
function _gFmtNum(n){
    n=parseInt(n)||0;
    if(n>=1000000000)return(n/1000000000).toFixed(1).replace(/\.0$/,'')+'b';
    if(n>=1000000)return(n/1000000).toFixed(1).replace(/\.0$/,'')+'m';
    if(n>=1000)return(n/1000).toFixed(1).replace(/\.0$/,'')+'k';
    return n;
}

function _gFetch(body,cb){fetch(window.location.href,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body}).then(function(r){return r.json();}).then(cb).catch(function(err){console.error('Col err:',err);gToast('Something went wrong');});}

function _gRelTime(ts){
    if(!ts)return'—';
    var now=Math.floor(Date.now()/1000),diff=now-ts;
    if(diff<60)return diff+'s ago';
    if(diff<3600)return Math.floor(diff/60)+'m ago';
    if(diff<86400)return Math.floor(diff/3600)+'h ago';
    if(diff<604800)return Math.floor(diff/86400)+'d ago';
    var d=new Date(ts*1000);
    var months=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return months[d.getMonth()]+' '+d.getDate()+', '+d.getFullYear();
}

function _gFmtDate(ts){
    if(!ts)return'—';
    var d=new Date(ts*1000);
    var months=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return months[d.getMonth()]+' '+d.getDate()+', '+d.getFullYear();
}

function gSort(gridId,order,ctx){
    var grid=document.getElementById(gridId);if(!grid)return;
    var cards=Array.from(grid.querySelectorAll('.meme-card[data-ts]'));
    var pinnedCards=cards.filter(function(c){return c.dataset.pinned==='1';});
    var normalCards=cards.filter(function(c){return c.dataset.pinned!=='1';});
    normalCards.sort(function(a,b){var ta=parseInt(a.dataset.ts)||0,tb=parseInt(b.dataset.ts)||0;return order==='newest'?tb-ta:ta-tb;});
    pinnedCards.forEach(function(c){grid.appendChild(c);});
    normalCards.forEach(function(c){grid.appendChild(c);});
    var allCards=Array.from(grid.querySelectorAll('.meme-card'));
    var firstNormal=allCards.find(function(c){return c.dataset.pinned!=='1';});
    pinnedCards.forEach(function(c){
        if(firstNormal) grid.insertBefore(c,firstNormal);
        else grid.appendChild(c);
    });
    _pmmSortDir = order;
    var m=_gMap[ctx];if(!m)return;
    var np=document.getElementById(m.nPill),op=document.getElementById(m.oPill);
    if(np)np.classList.toggle('active',order==='newest');
    if(op)op.classList.toggle('active',order==='oldest');
}

function gOpenCol(ctx){
    var m=_gMap[ctx];if(!m)return;
    var ge=document.getElementById(m.grid),se=document.getElementById(m.colSec),np=document.getElementById(m.nPill),op=document.getElementById(m.oPill),cp=document.getElementById(m.cPill);
    if(ge)ge.style.display='none';
    if(np)np.style.display='none';if(op)op.style.display='none';
    if(cp){cp.classList.add('active');cp.innerHTML='<i class="ri-arrow-left-s-line" style="margin-right:3px;font-size:13px;"></i>Back';cp.onclick=function(){gCloseCol(ctx);};}
    if(se)se.style.display='block';
    gRenderFolders(ctx);
}

function gCloseCol(ctx){
    var m=_gMap[ctx];if(!m)return;
    var ge=document.getElementById(m.grid),se=document.getElementById(m.colSec),np=document.getElementById(m.nPill),op=document.getElementById(m.oPill),cp=document.getElementById(m.cPill);
    var si=document.getElementById(m.searchInput);if(si)si.value='';
    var sc=document.getElementById(m.searchClear);if(sc)sc.style.display='none';
    var nr=document.getElementById(m.noResults);if(nr)nr.style.display='none';
    if(ge)ge.style.display='';if(se)se.style.display='none';
    if(np)np.style.display='';if(op)op.style.display='';
    if(cp){
        cp.classList.remove('active');
        var colCount=_gCols.length;
        var countStr=colCount>0?'<span class="g-pill-count">'+_gFmtNum(colCount)+'</span>':'';
        cp.innerHTML='<i class="ri-folder-3-line" style="margin-right:5px;font-size:11px;"></i>Collections'+countStr;
        cp.onclick=function(){gOpenCol(ctx);};
    }
}

function gRenderFolders(ctx){
    var m=_gMap[ctx];if(!m)return;
    var fg=document.getElementById(m.foldGrid),fw=document.getElementById(m.folders),dw=document.getElementById(m.detail);
    if(!fg)return;
    if(fw)fw.style.display='block';if(dw)dw.style.display='none';
    Array.from(fg.querySelectorAll('.col-folder-card')).forEach(function(e){e.remove();});
    Array.from(fg.querySelectorAll('.col-visitor-empty')).forEach(function(e){e.remove();});

    if(_gCols.length===0&&!_gIsOwner){
        var empty=document.createElement('div');
        empty.className='col-visitor-empty';
        empty.innerHTML='<i class="ri-folder-open-line"></i>No public collections yet';
        fg.appendChild(empty);
        return;
    }

    var pinnedCols=_gCols.filter(function(c){return _gIsColPinned(c.id);});
    var normalCols=_gCols.filter(function(c){return !_gIsColPinned(c.id);});
    pinnedCols.sort(function(a,b){return _gPinnedColIds.indexOf(parseInt(a.id,10))-_gPinnedColIds.indexOf(parseInt(b.id,10));});
    var sortedCols=pinnedCols.concat(normalCols);

    sortedCols.forEach(function(col){
        var isPublic = col.is_public==1;
        var isPinnedCol = _gIsColPinned(col.id);
        var card=document.createElement('div');
        card.className='col-folder-card'+(isPublic?' is-public':'')+(isPinnedCol?' is-pinned-col':'');
        card.dataset.colId=col.id;
        card.dataset.colName=(col.name||'').toLowerCase();
        card.dataset.pinned=isPinnedCol?'1':'0';

        var createdStr   = col.created_at   ? _gFmtDate(col.created_at)      : '—';
        var latestStr    = col.latest_addition ? _gRelTime(col.latest_addition) : 'No items yet';
        var itemCountFmt = _gFmtNum(col.item_count||0);

        var optsBtn = _gIsOwner
            ? '<button class="col-folder-opts" onclick="event.stopPropagation();gOpenOpts(event,'+col.id+',\''+col.name.replace(/'/g,"\\'")+'\',\''+ctx+'\','+(isPublic?'true':'false')+','+(isPinnedCol?'true':'false')+')" title="Options"><i class="ri-more-2-line"></i></button>'
            : '';
        var pubBadge  = isPublic  ? '<div class="col-public-badge">Public</div>'                : '';
        var pinBadge  = isPinnedCol ? '<div class="col-pin-badge'+(isPublic?' with-pub':'')+'">📌 Pinned</div>' : '';

        card.innerHTML=
            pubBadge+pinBadge+
            '<div class="col-folder-preview" id="gfp-'+ctx+'-'+col.id+'"><div class="col-fp-empty"><i class="ri-image-line"></i></div><div class="col-fp-empty"><i class="ri-image-line"></i></div><div class="col-fp-empty"><i class="ri-image-line"></i></div><div class="col-fp-empty"><i class="ri-image-line"></i></div></div>'+
            '<div class="col-folder-info">'+
                '<div class="col-folder-name">'+_gEsc(col.name)+'</div>'+
                '<div class="col-folder-count" id="gfc-'+col.id+'">'+itemCountFmt+' item'+(col.item_count==1?'':'s')+'</div>'+
                '<div class="col-folder-timeline">'+
                    '<div class="col-folder-tl-row"><i class="ri-calendar-line"></i><span>Created: '+_gEsc(createdStr)+'</span></div>'+
                    '<div class="col-folder-tl-row" id="gflt-'+col.id+'"><i class="ri-time-line"></i><span>Latest: '+_gEsc(latestStr)+'</span></div>'+
                '</div>'+
                (_gIsOwner ? '<div class="col-folder-vis '+(isPublic?'pub':'priv')+'" id="gfv-'+col.id+'">'+(isPublic?'<i class="ri-earth-line"></i> Public':'<i class="ri-lock-line"></i> Private')+'</div>' : '')+
            '</div>'+
            optsBtn;

        card.addEventListener('click',function(){gOpenDetail(ctx,col.id,col.name);});
        fg.appendChild(card);
        _gLoadPreview(ctx,col.id);
    });
}

function _gLoadPreview(ctx,colId){_gFetch('col_action=get_previews&col_id='+colId,function(data){var el=document.getElementById('gfp-'+ctx+'-'+colId);if(!el)return;el.innerHTML='';for(var i=0;i<4;i++){var div=document.createElement('div');var item=data.items&&data.items[i];if(item){div.className='col-fp-item';var f=item.file_name,t=item.type;if(t==='video')div.innerHTML='<video src="uploads/'+_gEsc(f)+'" muted style="width:100%;height:100%;object-fit:cover;"></video>';else if(t==='text'||!f||f==='0')div.innerHTML='<img src="uploads/Text.jpg">';else div.innerHTML='<img src="uploads/'+_gEsc(f)+'" onerror="this.src=\'uploads/Text.jpg\'">';}else{div.className='col-fp-empty';div.innerHTML='<i class="ri-image-line"></i>';}el.appendChild(div);}});}

function gSearchFolders(ctx, query){
    var m=_gMap[ctx];if(!m)return;
    var fg=document.getElementById(m.foldGrid);
    var nr=document.getElementById(m.noResults);
    var sc=document.getElementById(m.searchClear);
    var q=(query||'').trim().toLowerCase();
    if(sc)sc.style.display=q?'block':'none';
    var cards=Array.from(fg.querySelectorAll('.col-folder-card'));
    var visible=0;
    cards.forEach(function(card){
        var name=card.dataset.colName||'';
        var show=!q||name.indexOf(q)!==-1;
        card.style.display=show?'':'none';
        if(show)visible++;
    });
    if(nr)nr.style.display=(visible===0&&q)?'block':'none';
}

function gClearFolderSearch(ctx){
    var m=_gMap[ctx];if(!m)return;
    var si=document.getElementById(m.searchInput);
    if(si){si.value='';si.focus();}
    gSearchFolders(ctx,'');
}

function gSearchDetailItems(gridId, searchId, noResultsId){
    var grid=document.getElementById(gridId);
    var si=document.getElementById(searchId);
    var nr=document.getElementById(noResultsId);
    var clearBtn=document.getElementById(searchId.replace('Search','SearchClear'));
    if(!grid||!si)return;
    var q=(si.value||'').trim().toLowerCase();
    if(clearBtn)clearBtn.style.display=q?'block':'none';
    var cards=Array.from(grid.querySelectorAll('.meme-card'));
    var visible=0;
    cards.forEach(function(card){
        var cap=(card.querySelector('.caption')||{}).textContent||'';
        cap=cap.toLowerCase();
        var show=!q||cap.indexOf(q)!==-1;
        card.style.display=show?'':'none';
        if(show)visible++;
    });
    if(nr)nr.style.display=(visible===0&&q)?'block':'none';
}

function gClearDetailSearch(gridId,searchId,noResultsId,clearBtnId){
    var si=document.getElementById(searchId);
    var nr=document.getElementById(noResultsId);
    var cb=document.getElementById(clearBtnId);
    if(si){si.value='';si.focus();}
    if(nr)nr.style.display='none';
    if(cb)cb.style.display='none';
    var grid=document.getElementById(gridId);
    if(grid)Array.from(grid.querySelectorAll('.meme-card')).forEach(function(c){c.style.display='';});
}

function gOpenDetail(ctx, colId, colName) {
    var m = _gMap[ctx]; if (!m) return;
    colId = parseInt(colId, 10);
    _gActiveColId = colId; _gActiveColCtx = ctx;
    var fw = document.getElementById(m.folders), dw = document.getElementById(m.detail),
        dt = document.getElementById(m.dtTitle), dc = document.getElementById(m.dtCount),
        dg = document.getElementById(m.dtGrid);
    var dsi=document.getElementById(m.dtSearch);if(dsi)dsi.value='';
    var dsc=document.getElementById(m.dtSearchClear);if(dsc)dsc.style.display='none';
    var dnr=document.getElementById(m.dtNoResults);if(dnr)dnr.style.display='none';
    if (fw) fw.style.display = 'none';
    if (dw) dw.style.display = 'block';
    if (dt) dt.textContent = colName;
    if (dc) dc.textContent = 'Loading...';
    if (dg) dg.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:30px;color:rgba(255,255,255,0.3);font-size:13px;display:flex;align-items:center;justify-content:center;gap:8px;"><i class="ri-loader-4-line" style="font-size:20px;animation:spin 0.8s linear infinite;"></i> Loading...</div>';

    _gFetch('col_action=get_collection_items&col_id=' + colId, function (data) {
        if (data.status !== 'ok') {
            if (dg) dg.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:30px;color:#f24455;font-size:13px;">Error loading items</div>';
            return;
        }
        var items = data.items || [];
        var fmtCount = _gFmtNum(items.length);
        if (dc) dc.textContent = fmtCount + ' item' + (items.length == 1 ? '' : 's');
        if (!dg) return;
        dg.innerHTML = '';
        if (items.length === 0) {
            dg.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:40px 20px;color:#aaa;font-size:0.95rem;"><i class="ri-folder-open-line" style="font-size:2.5rem;display:block;margin-bottom:10px;color:#FF4C4C;"></i>No items yet<br><small style="font-size:11px;opacity:0.6;">Tap the folder button on any meme to add it here</small></div>';
            return;
        }

        var colFeedName = 'colFeed_' + colId;
        _gFetch('col_action=get_col_item_states&col_id=' + colId, function (stateData) {
            var stateMap = {};
            if (stateData.status === 'ok' && stateData.states) {
                stateData.states.forEach(function (s) { stateMap[s.id] = s; });
            }

window[colFeedName] = items.map(function (item) {
    var f = item.file_name || ''; var t = item.type || 'image';
    var mf = (t === 'text' || !f || f === '0') ? 'uploads/Text.jpg' : 'uploads/' + f;
    var s = stateMap[item.id] || {};
    return {
        id: item.id, file: mf, type: t,
        pic: item.uploader_pic || 'uploads/default.jpg',
        userId: item.uploaded_by, username: item.username || '',
        caption: item.caption || '', time: item.created_at || '',
        isYou: false,
        likeCount:   s.likeCount   || 0,
        repostCount: s.repostCount || 0,
        favCount:    s.favCount    || 0,
        shareCount:  s.shareCount  || 0,
        liked:   s.liked   || false,
        reposted: s.reposted || false,
        faved:   s.faved   || false,
        nickname:    s.nickname    || '',
        isFollowing: s.isFollowing || false,
        socialProof: s.socialProof || [],
        media: (s.media && s.media.length) ? s.media : [{ file: mf, type: t }],
        context: 'Collections',
        groupId: (s.groupId !== undefined && s.groupId !== null) ? s.groupId : null,
        groupTitle: s.groupTitle || null,
        audioId: (s.audioId !== undefined && s.audioId !== null) ? s.audioId : null,
        audioIsOriginal: !!s.audioIsOriginal,
        audioFile: (s.audioFile !== undefined && s.audioFile !== null) ? s.audioFile : null,
        audioTitle: (s.audioTitle !== undefined && s.audioTitle !== null) ? s.audioTitle : null,
        audioArtist: (s.audioArtist !== undefined && s.audioArtist !== null) ? s.audioArtist : null,
        musicVolume: (s.musicVolume !== undefined && s.musicVolume !== null) ? s.musicVolume : 80,
        videoAudioVolume: (s.videoAudioVolume !== undefined && s.videoAudioVolume !== null) ? s.videoAudioVolume : 40,
        // FIX: Paid Partnership badge fields
        isPartnership: !!s.isPartnership,
        partnerBrand: s.partnerBrand || null,
        // FIX: post-as-ad + AI-generated fields — needed so the CTA pill,
        // AD rail badge, and AI tag render on ad/AI posts opened from
        // inside a Collection, same as they do everywhere else.
        isAd: !!s.isAd,
        adCtaText: (s.adCtaText !== undefined && s.adCtaText !== null) ? s.adCtaText : null,
        adCtaUrl: (s.adCtaUrl !== undefined && s.adCtaUrl !== null) ? s.adCtaUrl : null,
        adCtaColor: (s.adCtaColor !== undefined && s.adCtaColor !== null) ? s.adCtaColor : null,
        isAiGenerated: !!s.isAiGenerated
    };
});

            items.forEach(function (item) {
                var f = item.file_name || '', t = item.type || 'image';
                var cap = item.caption || '', up = item.uploader_pic || 'uploads/default.jpg', un = item.username || '';
                var mf = (t === 'text' || !f || f === '0') ? 'uploads/Text.jpg' : 'uploads/' + f;
                var mediaHtml = '';
                if (t === 'video') mediaHtml = '<video src="uploads/' + _gEsc(f) + '" autoplay muted loop playsinline style="width:100%;height:100%;object-fit:cover;display:block;"></video>';
                else mediaHtml = '<img src="' + _gEsc(mf) + '" onerror="this.src=\'uploads/Text.jpg\'" style="width:100%;height:100%;object-fit:cover;display:block;">';

                // FIX: any logged-in viewer can save this item into their
                // OWN collections, not just the owner of the collection
                // they're currently browsing.
                var addColBtnHtml = currentUserId
                    ? '<button class="card-add-col-btn" title="Add to Collection" onclick="event.stopPropagation();gOpenAddToCol(' + item.id + ')"><i class="ri-folder-add-line"></i></button>'
                    : '';

                var removeBtn = _gIsOwner
                    ? '<button class="card-remove-btn" title="Remove from collection" onclick="event.stopPropagation();gRemoveItem(' + item.id + ',this)"><i class="ri-folder-reduce-line"></i></button>'
                    : '';

                var card = document.createElement('div');
                card.className = 'meme-card';
                card.dataset.caption = (cap||'').toLowerCase();
                card.innerHTML =
                    '<div class="top-bar"><img src="' + _gEsc(up) + '" onerror="this.src=\'uploads/default.jpg\'"><div class="username">' + _gEsc(un) + '</div></div>' +
                    '<div class="media-box" style="position:relative;">' + mediaHtml + addColBtnHtml + removeBtn + '</div>' +
                    '<div class="caption"><i class="ri-folder-3-line" style="color:#FF4C4C;"></i> ' + _gEsc(cap) + '</div>' +
                    '<div class="icon-bar"><a href="download.php?file=' + encodeURIComponent(f) + '" title="Download"><i class="ri-download-line"></i></a></div>';

                card.querySelector('.media-box').addEventListener('click', function (e) {
                    if (e.target.closest('.card-remove-btn') || e.target.closest('.card-add-col-btn')) return;
                    openMemeModal(mf, t === 'text' ? 'image' : t, cap, up, un, item.uploaded_by, colFeedName, item.id);
                });
                dg.appendChild(card);
            });

            var col=_gCols.find(function(c){return c.id==colId;});
            if(col&&items.length>0){
                var maxTs=0;
                items.forEach(function(it){var ts=parseInt(it.added_at||it.created_at_ts||0);if(ts>maxTs)maxTs=ts;});
                if(maxTs>0){
                    col.latest_addition=maxTs;
                    var ltEl=document.getElementById('gflt-'+colId);
                    if(ltEl)ltEl.querySelector('span').textContent='Latest: '+_gRelTime(maxTs);
                }
            }
        });
    });
}

function gCloseDetail(ctx){
    var m=_gMap[ctx];if(!m)return;
    _gActiveColId=null;_gActiveColCtx=null;
    var dsi=document.getElementById(m.dtSearch);if(dsi)dsi.value='';
    var dsc=document.getElementById(m.dtSearchClear);if(dsc)dsc.style.display='none';
    var dnr=document.getElementById(m.dtNoResults);if(dnr)dnr.style.display='none';
    var fw=document.getElementById(m.folders),dw=document.getElementById(m.detail);
    if(fw)fw.style.display='block';if(dw)dw.style.display='none';
}

function gRemoveItem(memeId,btn){if(!_gActiveColId||!_gIsOwner)return;var ctx=_gActiveColCtx;_gFetch('col_action=remove_from_collection&col_id='+_gActiveColId+'&meme_id='+memeId,function(d){if(d.status!=='ok'){gToast('Could not remove');return;}var card=btn.closest('.meme-card');if(card){card.style.transition='opacity 0.3s,transform 0.3s';card.style.opacity='0';card.style.transform='scale(0.9)';setTimeout(function(){card.remove();if(ctx)_gUpdateDetailCount(ctx);},300);}_gCols.forEach(function(c){if(c.id==_gActiveColId)c.item_count=Math.max(0,c.item_count-1);});_gRefreshCountBadge(_gActiveColId);gToast('Removed from collection');});}

function _gUpdateDetailCount(ctx){var m=_gMap[ctx];if(!m)return;var dg=document.getElementById(m.dtGrid),dc=document.getElementById(m.dtCount);if(!dg||!dc)return;var r=dg.querySelectorAll('.meme-card:not([style*="display: none"])').length;dc.textContent=_gFmtNum(r)+' item'+(r==1?'':'s');}

// FIX: this badge is shown both on browsed folder cards (profile-scoped,
// lives in _gCols) and inside the add-to-collection modal (your-scoped,
// lives in _gMyCols), so it needs to check both arrays.
function _gRefreshCountBadge(colId){
    var col=_gCols.find(function(c){return c.id==colId;}) || _gMyCols.find(function(c){return c.id==colId;});
    if(!col)return;
    document.querySelectorAll('[id="gfc-'+colId+'"]').forEach(function(el){el.textContent=_gFmtNum(col.item_count)+' item'+(col.item_count==1?'':'s');});
}

function gOpenAddToCol(memeId){
    // FIX: this used to check _gIsOwner (profile ownership), which meant
    // the "Add to Collection" button only worked when you were viewing
    // YOUR OWN profile. It should check whether someone is logged in at
    // all, since you're always adding to YOUR OWN collections regardless
    // of whose profile/post/collection you're currently viewing. And the
    // list populated here must always come from _gMyCols, not _gCols.
    if(!currentUserId){gToast('Log in to add to collections');return;}
    _gActiveMemeId=memeId;
    var list=document.getElementById('atcList');
    list.innerHTML='<div style="padding:20px;text-align:center;color:rgba(255,255,255,0.3);font-size:13px;">Loading...</div>';
    document.getElementById('addToColModal').classList.add('open');
    if(_gMyCols.length===0){list.innerHTML='<div style="padding:20px;text-align:center;color:rgba(255,255,255,0.35);font-size:13px;">No collections yet.<br>Create one below.</div>';return;}
    list.innerHTML='';
    _gMyCols.forEach(function(col){
        var item=document.createElement('div');item.className='atc-item';item.dataset.colId=col.id;
        item.innerHTML='<div class="atc-item-thumb"><i class="ri-folder-3-line" style="color:rgba(255,255,255,0.2);font-size:20px;"></i></div><div class="atc-item-info"><div class="atc-item-name">'+_gEsc(col.name)+'</div><div class="atc-item-count" id="atcCnt-'+col.id+'">'+_gFmtNum(col.item_count)+' item'+(col.item_count==1?'':'s')+'</div></div><div class="atc-check" id="atcChk-'+col.id+'">✓</div>';
        item.addEventListener('click',function(){gToggleColItem(col.id,memeId);});
        list.appendChild(item);
    });
    _gMyCols.forEach(function(col){
        _gFetch('col_action=get_collection_items&col_id='+col.id,function(d){
            if(d.status!=='ok')return;
            var has=(d.items||[]).some(function(i){return i.id==memeId;});
            var chk=document.getElementById('atcChk-'+col.id);
            if(chk)chk.classList.toggle('on',has);
        });
    });
}

function gCloseAddToCol(){document.getElementById('addToColModal').classList.remove('open');_gActiveMemeId=null;}

function gToggleColItem(colId,memeId){
    var chk=document.getElementById('atcChk-'+colId);
    var isAdded=chk&&chk.classList.contains('on');
    if(isAdded){
        _gFetch('col_action=remove_from_collection&col_id='+colId+'&meme_id='+memeId,function(d){
            if(d.status!=='ok'){gToast('Could not remove');return;}
            if(chk)chk.classList.remove('on');
            _gMyCols.forEach(function(c){if(c.id==colId)c.item_count=Math.max(0,c.item_count-1);});
            _gRefreshCountBadge(colId);
            var ce=document.getElementById('atcCnt-'+colId);
            if(ce){var col=_gMyCols.find(function(c){return c.id==colId;});if(col)ce.textContent=_gFmtNum(col.item_count)+' item'+(col.item_count==1?'':'s');}
            gToast('Removed from collection');
        });
    }else{
        _gFetch('col_action=add_to_collection&col_id='+colId+'&meme_id='+memeId,function(d){
            if(d.status==='already'){if(chk)chk.classList.add('on');gToast('Already in this collection');return;}
            if(d.status!=='ok'){gToast('Could not add');return;}
            if(chk)chk.classList.add('on');
            _gMyCols.forEach(function(c){if(c.id==colId){c.item_count=(d.item_count||c.item_count+1);c.latest_addition=Math.floor(Date.now()/1000);}});
            _gRefreshCountBadge(colId);
            var ce=document.getElementById('atcCnt-'+colId);
            if(ce){var col=_gMyCols.find(function(c){return c.id==colId;});if(col)ce.textContent=_gFmtNum(col.item_count)+' item'+(col.item_count==1?'':'s');}
            var ltEl=document.getElementById('gflt-'+colId);
            if(ltEl)ltEl.querySelector('span').textContent='Latest: just now';
            gToast('Added to collection ✓');
        });
    }
}

function gOpenOpts(e, colId, colName, ctx, isPublic, isPinned) {
    if(!_gIsOwner)return;
    e.stopPropagation();
    _gOptsColId     = parseInt(colId, 10);
    _gOptsColName   = colName;
    _gOptsColCtx    = ctx;
    _gOptsColPublic = isPublic;
    _gOptsColPinned = isPinned;

    var visLabel = document.getElementById('colOptsVisLabel');
    var visItem  = document.getElementById('colOptsVisItem');
    if(visLabel) visLabel.textContent = isPublic ? 'Make Private' : 'Make Public';
    if(visItem){
        var icon = visItem.querySelector('i');
        if(icon) icon.className = isPublic ? 'ri-lock-line' : 'ri-earth-line';
        visItem.querySelector('i').style.color = isPublic ? '#f24455' : '#4dde8a';
    }

    var pinLabel = document.getElementById('colOptsPinLabel');
    var pinItem  = document.getElementById('colOptsPinItem');
    if(pinLabel) pinLabel.textContent = isPinned ? 'Unpin Collection' : 'Pin Collection';
    if(pinItem){
        var pi = pinItem.querySelector('i');
        if(pi) pi.className = isPinned ? 'ri-pushpin-fill' : 'ri-pushpin-line';
    }

    var dd = document.getElementById('colOptsDd');
    if (window.innerWidth <= 768) { dd.classList.add('open'); return; }
    var rect = e.currentTarget.getBoundingClientRect();
    dd.style.visibility = 'hidden'; dd.style.display = 'block';
    var ddH = dd.offsetHeight;
    dd.style.display = ''; dd.style.visibility = '';
    var GAP = 8, spaceAbove = rect.top + window.scrollY;
    var top = spaceAbove >= ddH + GAP ? rect.top + window.scrollY - ddH - GAP : rect.bottom + window.scrollY + GAP;
    var left = Math.min(rect.left, window.innerWidth - 200);
    dd.style.top = top + 'px'; dd.style.left = left + 'px';
    dd.classList.add('open');
}

function gColOptDo(action){
    document.getElementById('colOptsDd').classList.remove('open');
    if(action==='rename'){
        gOpenNameModal('rename',_gOptsColCtx,_gOptsColName);
    } else if(action==='delete'){
        if(!confirm('Delete "'+_gOptsColName+'"?\nMemes inside will NOT be deleted.'))return;
        _gFetch('col_action=delete_collection&col_id='+_gOptsColId,function(d){
            if(d.status!=='ok'){gToast('Could not delete');return;}
            _gCols=_gCols.filter(function(c){return c.id!=_gOptsColId;});
            _gMyCols=_gMyCols.filter(function(c){return c.id!=_gOptsColId;});
            _gPinnedColIds=_gPinnedColIds.filter(function(id){return id!=_gOptsColId;});
            if(_gOptsColCtx)gRenderFolders(_gOptsColCtx);
            gToast('Collection deleted');
        });
    } else if(action==='toggle_vis'){
        var newVal = _gOptsColPublic ? 0 : 1;
        _gFetch('col_action=set_visibility&col_id='+_gOptsColId+'&is_public='+newVal,function(d){
            if(d.status!=='ok'){gToast('Could not update');return;}
            _gCols.forEach(function(c){if(c.id==_gOptsColId)c.is_public=newVal;});
            _gMyCols.forEach(function(c){if(c.id==_gOptsColId)c.is_public=newVal;});
            if(_gOptsColCtx)gRenderFolders(_gOptsColCtx);
            gToast(newVal ? 'Collection is now Public 🌐' : 'Collection is now Private 🔒');
        });
    } else if(action==='toggle_pin'){
        var isPinnedNow = _gIsColPinned(_gOptsColId);
        if(!isPinnedNow && _gPinnedColIds.length >= 3){
            gToast('Max 3 pinned collections. Unpin one first.');
            return;
        }
        var pinAction = isPinnedNow ? 'unpin_collection' : 'pin_collection';
        _gFetch('col_action='+pinAction+'&col_id='+_gOptsColId,function(d){
            if(d.status!=='ok'){gToast('Could not update');return;}
            if(isPinnedNow){
                _gPinnedColIds=_gPinnedColIds.filter(function(id){return id!=_gOptsColId;});
                gToast('Collection unpinned');
            } else {
                _gPinnedColIds.unshift(parseInt(_gOptsColId,10));
                gToast('📌 Collection pinned');
            }
            if(_gOptsColCtx)gRenderFolders(_gOptsColCtx);
        });
    }
}

document.addEventListener('click',function(e){var dd=document.getElementById('colOptsDd');if(dd&&!dd.contains(e.target))dd.classList.remove('open');});

function gOpenNameModal(mode,ctx,currentName){
    _gNmMode=mode; _gColCtx=ctx||'favs';
    var input=document.getElementById('colNmInput'),title=document.getElementById('colNmTitle'),visRow=document.getElementById('colVisRow'),visTog=document.getElementById('colVisToggle');
    if(title)title.textContent=mode==='rename'?'Rename Collection':'New Collection';
    if(input)input.value=mode==='rename'?(currentName||''):'';
    if(visRow)visRow.style.display=mode==='rename'?'none':'flex';
    if(visTog&&mode==='create')visTog.checked=false;
    document.getElementById('colNameModal').classList.add('open');
    setTimeout(function(){if(input)input.focus();},280);
}
function gCloseNameModal(){document.getElementById('colNameModal').classList.remove('open');}

function gSaveName(){
    var name=(document.getElementById('colNmInput').value||'').trim();
    if(!name){gToast('Please enter a name');return;}
    if(_gNmMode==='create'){
        var isPublic=document.getElementById('colVisToggle').checked?1:0;
        _gFetch('col_action=create_collection&name='+encodeURIComponent(name)+'&is_public='+isPublic,function(d){
            if(d.status!=='ok'){gToast('Could not create');return;}
            var newCol={
                id: parseInt(d.id,10), name:d.name, is_public:d.is_public||0, item_count:0,
                created_at: Math.floor(Date.now()/1000),
                latest_addition: null
            };
            if(d.collections) _gCols=_gNormalizeCols(d.collections);
            else _gCols.unshift(newCol);
            // FIX: a collection created from the "Add to Collection" modal
            // (e.g. while browsing someone else's profile) is always one
            // of YOUR OWN, so it must also land in _gMyCols or it won't
            // show up in that modal until a page refresh.
            if(!_gMyCols.some(function(c){return c.id===newCol.id;})) _gMyCols.unshift(newCol);
            gCloseNameModal();
            if(_gColCtx)gRenderFolders(_gColCtx);
            _gUpdateAllColCounts();
            gToast('Collection "'+d.name+'" created ✓');
        });
    }else{
        _gFetch('col_action=rename_collection&col_id='+_gOptsColId+'&name='+encodeURIComponent(name),function(d){
            if(d.status!=='ok'){gToast('Could not rename');return;}
            _gCols.forEach(function(c){if(c.id==_gOptsColId)c.name=name;});
            _gMyCols.forEach(function(c){if(c.id==_gOptsColId)c.name=name;});
            gCloseNameModal();
            if(_gOptsColCtx)gRenderFolders(_gOptsColCtx);
            gToast('Collection renamed ✓');
        });
    }
}

function _gUpdateAllColCounts(){
    var count=_gCols.length;
    var countStr=count>0?_gFmtNum(count):'';
    document.querySelectorAll('.g-pill-count').forEach(function(el){
        el.textContent=countStr;
    });
}

function gToast(msg){var ex=document.querySelector('.g-toast');if(ex)ex.remove();var t=document.createElement('div');t.className='g-toast g-toast-in';t.textContent=msg;document.body.appendChild(t);setTimeout(function(){t.style.transition='opacity 0.3s';t.style.opacity='0';setTimeout(function(){t.remove();},300);},2400);}

function _gEsc(s){if(!s)return'';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');}
</script>
<?php endif; ?>


<!-- ── SCREEN TIME BREAK OVERLAY ── -->
<?php if($current_user == $profile_id): ?>
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
<?php else: ?>
<div id="breakOverlay" style="display:none!important;pointer-events:none;"></div>
<?php endif; ?>

</div>
</div>

</div>
</div>

<script>
/* ══════════════════════════════════════════
   TABS — SLIDING UNDERLINE (mobile only)
══════════════════════════════════════════ */
function isMobile() {
    return window.innerWidth <= 768;
}

function moveTabUnderline(activeBtn) {
    if (!isMobile()) return;

    var tabs = activeBtn.closest('.tabs');
    if (!tabs) return;

    var tabsRect = tabs.getBoundingClientRect();
    var btnRect  = activeBtn.getBoundingClientRect();

    var icon     = activeBtn.querySelector('i') || activeBtn;  /* ← get the icon */
    var iconRect = icon.getBoundingClientRect();               /* ← measure icon */

    var width = iconRect.width;                                /* ← icon width, not button width */
    var left  = btnRect.left - tabsRect.left + tabs.scrollLeft
                + (btnRect.width / 2) - (iconRect.width / 2); /* ← center under icon */

    var indicator = tabs.querySelector('.tabs-indicator');
    if (!indicator) {
        indicator = document.createElement('span');
        indicator.className = 'tabs-indicator';
        tabs.appendChild(indicator);
    }

    indicator.style.left  = left  + 'px';
    indicator.style.width = width + 'px';
}

function hideTabUnderline() {
    var indicator = document.querySelector('.tabs-indicator');
    if (indicator) indicator.style.width = '0';
}

/* Click — move underline + update active class */
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.tabs button');
    if (!btn) return;

    var tabs = btn.closest('.tabs');
    if (tabs) {
        tabs.querySelectorAll('button').forEach(function(b) {
            b.classList.remove('active');
        });
        btn.classList.add('active');
    }

    if (!isMobile()) return;

    requestAnimationFrame(function() {
        moveTabUnderline(btn);
    });
});

/* Load — default underline on #tabMemes, mobile + all profile pages */
window.addEventListener('load', function() {
    if (!isMobile()) return;

    var defaultTab = document.getElementById('tabMemes')
                  || document.querySelector('.tabs button.active')
                  || document.querySelector('.tabs button');

    if (!defaultTab) return;

    var tabs = defaultTab.closest('.tabs');
    if (tabs) {
        tabs.querySelectorAll('button').forEach(function(b) {
            b.classList.remove('active');
        });
    }
    defaultTab.classList.add('active');

    setTimeout(function() {
        moveTabUnderline(defaultTab);
    }, 60);
});

/* Resize — reposition on mobile, hide on desktop */
window.addEventListener('resize', function() {
    if (!isMobile()) {
        hideTabUnderline();
        return;
    }
    var activeTab = document.querySelector('.tabs button.active');
    if (activeTab) {
        setTimeout(function() {
            moveTabUnderline(activeTab);
        }, 30);
    }
});

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

// Select all kebab wrappers (even if only one exists)
document.querySelectorAll('.kebab-wrapper').forEach(wrapper => {
    const dropdown = wrapper.querySelector('.kebab-dropdown');

    wrapper.addEventListener('click', (e) => {
        e.stopPropagation(); // prevent closing immediately
        // Toggle this dropdown only
        dropdown.classList.toggle('show');
    });
});

// Close all dropdowns if click outside
document.addEventListener('click', () => {
    document.querySelectorAll('.kebab-dropdown').forEach(dd => {
        dd.classList.remove('show');
    });
});

function toggleSection(id){
    const sections = document.querySelectorAll('.dropdown-section');
    sections.forEach(s => s.style.display='none');

    const target = document.getElementById(id);
    if(!target) return;

    // Determine display type
    target.style.display = (id === 'messages') ? 'block' : 'grid';

    // Animate cards inside the section if any
    const cards = target.querySelectorAll('.meme-card, .user-row'); // include meme cards + user cards
    cards.forEach((card, index) => {
        card.style.opacity = 0;
        card.style.transform = 'translateY(30px) scale(0.95)';

        setTimeout(() => {
            card.style.opacity = 1;
            card.style.transform = 'translateY(0) scale(1)';
        }, index * 100); // stagger by 100ms
    });
}

// Set default visible section
document.getElementById('memes').style.display='grid';
toggleSection('memes'); // animate by default on page load



// MODAL FUNCTIONS
function openModal(src){
    const img=document.getElementById('modalImg');
    const video=document.getElementById('modalVideo');
    if(src.endsWith('.mp4') || src.endsWith('.webm')){
        video.src=src;
        video.style.display='block';
        img.style.display='none';
    } else {
        img.src=src;
        img.style.display='block';
        video.style.display='none';
    }
    document.getElementById('mediaModal').style.display='flex';
}
function closeModal(){
    const modal=document.getElementById('mediaModal');
    modal.style.display='none';
    document.getElementById('modalVideo').pause();
}

// ====================
// AJAX LIKE/UNLIKE
// ====================
// LIKE BUTTON FUNCTIONALITY
document.querySelectorAll('.like-btn').forEach(btn => {
    btn.addEventListener('click', function(e){
        e.preventDefault(); // prevents accidental form submits

        const memeId = this.dataset.id;
        const heart = this.querySelector('i');
        const countSpan = this.querySelector('span');

        fetch('like_toggle.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'meme_id=' + memeId
        })
        .then(res => res.json())
        .then(data => {
            if(data.liked){
                heart.style.color = '#ff2b55';
            } else {
                heart.style.color = 'white';
            }
            countSpan.textContent = data.count;
        })
        .catch(err => console.error(err));
    });
});
</script>
</body>
</html>