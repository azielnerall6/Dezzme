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
// Can current user access this profile's features?
$is_owner = ($current_user == $profile_id);
$can_view = $is_owner || $follow_check;
$bio_visible = $can_view;   // <-- ADD THIS LINE

// Assuming $profile_id is the profile being viewed
$settings = $conn->query("SELECT * FROM user_settings WHERE user_id = $profile_id")->fetch_assoc();
$other_accounts = $settings['other_accounts'] ?? '';

// ── Default values to prevent undefined variable warnings ──
$profile_is_private   = !empty($settings['is_fully_private']);
$track_request_status = null;

// 5. THE QUERIES (Fixed to use $profile_id)

// MEMES (Things this user uploaded)
$memes = $conn->query("
    SELECT m.*, u.username, u.profile_pic AS uploader_pic
    FROM memes m
    JOIN users u ON m.uploaded_by = u.id
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
    ORDER BY m.created_at DESC
");

// REPOSTS (Things this user shared)
// AFTER
$reposts = $conn->query("
    SELECT m.id, m.uploaded_by, m.file_name, m.caption, m.type, 
           m.created_at,
           u.username as creator, u.profile_pic as creator_pic
    FROM reposts r
    JOIN memes m ON r.meme_id = m.id
    JOIN users u ON m.uploaded_by = u.id
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
    ORDER BY r.id DESC
");

// Get tagged posts (memes where @username appears in caption)
$profile_username = $user['username'];

$tagged_posts = $conn->query("
    SELECT m.*, u.username, u.profile_pic as uploader_pic 
    FROM memes m
    JOIN users u ON m.uploaded_by = u.id 
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
    ORDER BY m.created_at DESC
");


$favorites = $conn->query("
    SELECT m.*, u.username, u.profile_pic as uploader_pic 
    FROM favorites f
    JOIN memes m ON f.meme_id = m.id 
    JOIN users u ON m.uploaded_by = u.id 
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
    ORDER BY f.created_at DESC
");

$liked_posts = $conn->query("
    SELECT m.*, u.username, u.profile_pic as uploader_pic 
    FROM likes l
    JOIN memes m ON l.meme_id = m.id 
    JOIN users u ON m.uploaded_by = u.id 
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
        SELECT m.*, u.username, u.profile_pic as uploader_pic
        FROM memes m
        JOIN users u ON m.uploaded_by=u.id
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
    $res = $conn->query("
        SELECT ci.meme_id, m.uploaded_by, m.file_name, m.type
        FROM collection_items ci
        JOIN memes m ON m.id = ci.meme_id
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

        // FIX: attached soundtrack + group/pod info — gOpenDetail() JS already
        // reads s.audioFile/audioTitle/audioArtist/musicVolume/videoAudioVolume
        // and s.groupId/groupTitle off each state object, but this endpoint
        // never populated them, so Collections items always fell back to
        // no-audio / no-group defaults even when a track or pod was attached.
        $_audio = get_meme_audio_info($conn, $mid);
        $_group = get_meme_group_info($conn, $mid);

        // FIX: shareCount was never included in $states[] at all, so every
        // post opened from a Collection showed 0 shares regardless of the
        // real count. get_meme_counts() already fetches like/repost/fav/share
        // together (with the self-healing lazy-fill for non-primed memes),
        // so we just pull 'share' off it here.
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
            'audioFile'        => $_audio ? 'uploads/'.$_audio['file_name'] : null,
            'audioTitle'       => $_audio ? $_audio['title'] : null,
            'audioArtist'      => ($_audio && !empty($_audio['artist'])) ? $_audio['artist'] : null,
            'musicVolume'      => ($_audio && $_audio['music_volume'] !== '' && $_audio['music_volume'] !== null) ? (int)$_audio['music_volume'] : 80,
            'videoAudioVolume' => ($_audio && $_audio['video_audio_volume'] !== '' && $_audio['video_audio_volume'] !== null) ? (int)$_audio['video_audio_volume'] : 40,
            'groupId'          => $_group ? (int)$_group['group_id'] : null,
            'groupTitle'       => $_group ? $_group['group_name'] : null,
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
<?php
/* ─────────────────────────────────────────────────────────────
   NOTE: Assumes the usual PHP header block already ran above this
   (session, $conn, $current_user, $profile_id, $user, $profilePic,
   $activity_vibe, $bio_visible, $post_count, $followers_count,
   $following_count, $follow_check, $can_view, $profile_is_private,
   $memes, $reposts, $favorites, $liked_posts).
   Only small supporting PHP chunks are added below.
───────────────────────────────────────────────────────────── */

$nickname_display = $user['nickname'] ?? '';

$has_pronouns = !empty($user['pronouns']);
$has_type     = !empty($user['user_type']);

// Profile links (same visibility rule as bio)
$profile_links = [];
if (($bio_visible ?? false) || $current_user == $profile_id) {
    $pl_stmt = $conn->prepare("SELECT id, title, url FROM links WHERE user_id=? ORDER BY created_at DESC");
    $pl_stmt->bind_param("i", $profile_id);
    $pl_stmt->execute();
    $pl_res = $pl_stmt->get_result();
    while ($l = $pl_res->fetch_assoc()) { $profile_links[] = $l; }
    $pl_stmt->close();
}
$profile_link_count = count($profile_links);

// Collections count (for the Collections pill under the tabs)
$col_count_total = 0;
$col_count_res = $conn->query("SELECT COUNT(*) as c FROM collections WHERE user_id=" . (int)$profile_id);
if ($col_count_res) { $col_count_total = (int)($col_count_res->fetch_assoc()['c'] ?? 0); }

function ppShortNum($n){
    $n = (int)$n;
    if($n >= 1000000000) return round($n/1000000000,1).'b';
    if($n >= 1000000)    return round($n/1000000,1).'m';
    if($n >= 1000)       return round($n/1000,1).'k';
    return $n;
}

function ppLinkify($text){
    $text = preg_replace('/(https?:\/\/[^\s<]+)/i', '<a href="$1" target="_blank" rel="noopener noreferrer" style="color:#4A90D9;text-decoration:none;">$1</a>', $text);
    $text = preg_replace('/@([A-Za-z0-9_]+)/', '<a href="profile.php?username=$1" style="color:#4A90D9;text-decoration:none;">@$1</a>', $text);
    return $text;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($user['username']); ?> | Dezzme</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<style>
:root {
    --red: #FF2D2D;
    --ink: #06071A;
    --surface: rgba(255,255,255,0.05);
    --border: rgba(255,255,255,0.1);
    --text: #F0F2FF;
    --muted: rgba(220,224,255,0.55);
}

* { box-sizing: border-box; margin:0; padding:0; font-family:'Poppins',sans-serif; }

html { scroll-behavior: smooth; }

body {
    background: var(--ink);
    color: var(--text);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding-bottom: 60px;
}

.pp-wrap {
    width: 100%;
    max-width: 480px;
    margin: 0 auto;
    padding: 0 16px;
}

/* ════════════════════════════════════════════════════
   FIXED TOPBAR — slim, borderless, blends into the page
   background entirely. Back arrow (or Memepedia icon on
   own profile) / centered username / hamburger menu.
   ════════════════════════════════════════════════════ */
.pp-fixed-topbar{
    position:fixed; top:0; left:0; right:0;
    z-index:99999;
    display:flex; align-items:center; justify-content:space-between;
    gap:10px;
    padding:9px 16px;
    background:var(--ink);
}
.pp-ft-side{ display:flex; align-items:center; gap:10px; flex:0 0 auto; min-width:30px; }
.pp-ft-side.pp-ft-right{ justify-content:flex-end; }
.pp-ft-btn{
    background:none; border:none; color:#fff; font-size:1.35rem;
    display:flex; align-items:center; justify-content:center; cursor:pointer;
    padding:2px; opacity:.92; text-decoration:none;
    -webkit-tap-highlight-color:transparent;
}
.pp-ft-btn.pp-ft-back{ font-size:1.6rem; }
.pp-ft-title{ flex:1; text-align:center; font-size:.82rem; font-weight:700; color:#fff; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

/* Push page content below the fixed topbar */
.pp-page-body{ padding-top: 42px; }

/* sentinel used to detect when the tabs bar has docked under the topbar */
#ppTabsSentinel{ height:1px; }

/* ── Header row: avatar + (nickname above stats) ── */
.pp-header-row { display:flex; align-items:center; gap:16px; padding:14px 0 14px; }

.pp-avatar-wrap { flex-shrink:0; position:relative; }
.pp-avatar {
    width: 80px; height: 80px; border-radius: 50%; object-fit: cover;
    border: 3px solid var(--red);
    cursor:pointer;
}

.pp-header-info{ flex:1; min-width:0; display:flex; flex-direction:column; gap:6px; }

.pp-nickname-row{ font-size:12px; font-weight:600; color:rgba(255,255,255,0.4); }

/* Stats: count sits BESIDE the label on the same line */
.pp-stats { display:flex; justify-content:space-between; gap:8px; }
.pp-stat { display:flex; align-items:baseline; gap:5px; cursor:pointer; }
.pp-stat-num { font-size:14.5px; font-weight:700; color:#fff; }
.pp-stat-lbl { font-size:12px; color:var(--muted); }

/* ── Pronouns / user type pills (right under header row) ── */
.pp-pills-row{ display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-top:2px; margin-bottom:6px; }
.pp-pill{
    display:inline-flex; align-items:center; gap:5px;
    background:rgba(255,255,255,0.07); border-radius:16px;
    padding:4px 11px; font-size:12.5px; color:rgba(255,255,255,0.8);
}
.pp-pill.pp-pill-type{ color:#ff3b3b; font-weight:800; }

/* ── Bio — no left offset, single render only, more/less toggle ── */
.pp-bio { font-size:13px; line-height:1.5; margin:4px 0 0; padding:0; text-align:left; color:rgba(255,255,255,0.85); white-space:pre-wrap; }
.pp-bio .pp-more, .pp-bio .pp-less{ color:var(--red); cursor:pointer; font-weight:700; white-space:nowrap; }

/* ── Links row (after bio) ── */
.pp-links-wrap{ margin-top:8px; display:flex; flex-direction:column; gap:6px; }
.pp-link-row{
    display:flex; align-items:center; gap:7px; text-decoration:none;
    color:#9B7BD4; font-size:13px; font-weight:500; cursor:pointer;
}
.pp-link-row i{ font-size:16px; flex-shrink:0; }
.pp-link-row span{ white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:260px; }
.pp-link-more{ font-size:12.5px; font-weight:600; color:#B79BE0; }

/* ── Action buttons row — SQUARE, not pill ── */
.pp-actions { display:flex; gap:10px; margin-top:14px; }
.pp-btn {
    flex:1; padding:9px 0; border-radius:10px; font-size:13px; font-weight:600;
    text-align:center; cursor:pointer;
    display:flex; align-items:center; justify-content:center; gap:6px;
    text-decoration:none;
}
.pp-btn.pp-editpic{ background:rgba(255,255,255,0.1); border:2px solid rgba(255,45,45,0.5); color:#f93838; }
.pp-btn.pp-editpic:hover{ background:transparent; border-color:rgba(255,45,45,0.8); color:#ff5555; }
.pp-btn.pp-untracked{ background:rgba(255,255,255,0.1); border:1.5px solid rgba(255,255,255,0.23); color:#fff; }
.pp-btn.pp-untracked:hover{ background:transparent; border-color:rgba(255,255,255,0.5); }
.pp-btn.pp-track{ background:rgba(255,255,255,0.1); border:2px solid rgba(255,45,45,0.5); color:#FF2D2D; }
.pp-btn.pp-track:hover{ background:transparent; border-color:#FF2D2D; }
.pp-btn.pp-message{ background:rgba(255,255,255,0.1); border:1.5px solid rgba(255,255,255,0.3); color:#fff; }
.pp-btn.pp-message:hover{ background:rgba(255,255,255,0.25); border-color:rgba(255,255,255,0.5); }

.pp-divider { border-top:1px solid var(--border); margin:16px 0 0; }

/* ════════════════════════════════════════════════════
   TABS — full-bleed edge-to-edge on mobile, centered
   within the normal container on desktop. Sticks under
   the fixed topbar (position:sticky). No visual "docked"
   cue by design — it should blend, not gain a shadow/line.
   ════════════════════════════════════════════════════ */
.pp-tabs {
    position: sticky;
    top: 42px;
    z-index: 998;
    display: flex;
    justify-content: space-evenly;
    align-items: center;
    background: var(--ink);
    border-top: 1px solid var(--border);
    border-bottom: 1px solid var(--border);
}
.pp-tab {
    flex: 1;
    text-align: center;
    padding: 12px 0;
    background: none;
    border: none;
    cursor: pointer;
    color: var(--muted);
    font-size: 19px;
    position: relative;
    transition: color 0.2s;
}
.pp-tab.active { color: var(--text); }
.pp-tab.active::after {
    content: '';
    position: absolute;
    bottom: -1px; left: 50%;
    transform: translateX(-50%);
    width: 24px; height: 2px;
    background: var(--text);
    border-radius: 2px;
}
.pp-tab.pp-tab-pods{ display:flex; align-items:center; justify-content:center; gap:5px; font-size:13px; font-weight:700; }
.pp-tab.pp-tab-pods i{ font-size:15px; }

/* Full-bleed breakout — mobile only (790/490/390/330) */
@media (max-width:790px){
    .pp-tabs{
        width:100vw;
        left:50%;
        transform:translateX(-50%);
        position:sticky;
        margin-top:0;
    }
}
/* Desktop / normal view — stays contained + centered */
@media (min-width:791px){
    .pp-tabs{ max-width:480px; margin:0 auto; }
}

/* ── Sort / Collections bar — sits under the tabs, above the grid ── */
.pp-sortbar{ display:flex; align-items:center; gap:8px; flex-wrap:wrap; padding:12px 2px 10px; }
.pp-sort-pill{
    padding:6px 15px; border-radius:10px; border:1px solid rgba(255,255,255,0.14);
    background:transparent; color:rgba(255,255,255,0.65); font-size:12px; font-weight:600;
    cursor:pointer; transition:all .2s ease; white-space:nowrap;
}
.pp-sort-pill:hover{ border-color:rgba(255,45,45,0.5); color:#fff; background:rgba(255,45,45,0.08); }
.pp-sort-pill.active{ background:rgba(255,45,45,0.18); border-color:rgba(255,45,45,0.6); color:#FF5566; }
.pp-sort-div{ width:1px; height:18px; background:rgba(255,255,255,0.1); flex-shrink:0; }
.pp-sort-count{ color:#ff2d2d; font-size:10px; font-weight:800; margin-left:4px; }

/* ── Grid — long cards, no dead space ── */
.pp-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:3px; padding-top:3px; }
.pp-grid-item { position:relative; aspect-ratio:3/5; overflow:hidden; cursor:pointer; background:var(--surface); }
.pp-grid-item img, .pp-grid-item video { width:100%; height:100%; object-fit:cover; display:block; }
.pp-grid-badge { position:absolute; top:6px; right:6px; font-size:13px; color:#fff; filter:drop-shadow(0 1px 2px rgba(0,0,0,0.6)); }
.pp-grid-empty { grid-column:1/-1; text-align:center; padding:50px 20px; color:var(--muted); font-size:13px; }
.pp-grid-empty i { font-size:40px; color:var(--red); display:block; margin-bottom:10px; }

.pp-section { display:none; }
.pp-section.active { display:block; }

/* ════════════════════════════════════════════════════
   RESPONSIVE — 790 / 490 / 390 / 330
   ════════════════════════════════════════════════════ */
@media (max-width:790px){
    .pp-page-body{ padding-top:40px; }
    .pp-fixed-topbar{ padding:8px 14px; }
    .pp-ft-title{ font-size:.8rem; }
    .pp-ft-btn{ font-size:1.28rem; }
    .pp-ft-btn.pp-ft-back{ font-size:1.55rem; }
    .pp-tabs{ top:40px; }

    .pp-header-row{ gap:14px; padding:12px 0; }
    .pp-avatar{ width:74px; height:74px; border-width:2.5px; }
    .pp-nickname-row{ font-size:11.5px; }
    .pp-stat-num{ font-size:14px; }
    .pp-stat-lbl{ font-size:11.5px; }

    .pp-pill{ font-size:12px; padding:3.5px 10px; }
    .pp-bio{ font-size:12.5px; }
    .pp-link-row{ font-size:12.5px; }
    .pp-link-row span{ max-width:220px; }

    .pp-btn{ font-size:12.5px; padding:8px 0; border-radius:9px; }

    .pp-tab{ font-size:18px; padding:11px 0; }
    .pp-tab.pp-tab-pods{ font-size:12.5px; }
    .pp-sort-pill{ font-size:11.5px; padding:5.5px 13px; }
}

@media (max-width:490px){
    .pp-page-body{ padding-top:37px; }
    .pp-fixed-topbar{ padding:7px 12px; }
    .pp-ft-title{ font-size:.75rem; }
    .pp-ft-btn{ font-size:1.2rem; }
    .pp-ft-btn.pp-ft-back{ font-size:1.45rem; }
    .pp-tabs{ top:37px; }

    .pp-wrap{ padding:0 13px; }
    .pp-header-row{ gap:12px; padding:10px 0; }
    .pp-avatar{ width:66px; height:66px; }
    .pp-nickname-row{ font-size:11px; }
    .pp-stat-num{ font-size:13px; }
    .pp-stat-lbl{ font-size:11px; }
    .pp-stats{ gap:6px; }

    .pp-pills-row{ gap:6px; }
    .pp-pill{ font-size:11px; padding:3px 9px; }
    .pp-bio{ font-size:11.8px; }
    .pp-link-row{ font-size:11.8px; }
    .pp-link-row span{ max-width:185px; }
    .pp-link-row i{ font-size:15px; }

    .pp-actions{ gap:8px; margin-top:12px; }
    .pp-btn{ font-size:11.8px; padding:7.5px 0; border-radius:8px; }

    .pp-tab{ font-size:16.5px; padding:10px 0; }
    .pp-tab.active::after{ width:20px; }
    .pp-tab.pp-tab-pods{ font-size:11.8px; }
    .pp-tab.pp-tab-pods i{ font-size:14px; }

    .pp-sortbar{ padding:10px 2px 8px; gap:6px; }
    .pp-sort-pill{ font-size:11px; padding:5px 11px; border-radius:9px; }
    .pp-sort-count{ font-size:9.5px; }

    .pp-grid{ gap:2.5px; }
    .pp-grid-badge{ font-size:12px; }
}

@media (max-width:390px){
    .pp-page-body{ padding-top:34px; }
    .pp-fixed-topbar{ padding:6px 10px; }
    .pp-ft-title{ font-size:.7rem; }
    .pp-ft-btn{ font-size:1.1rem; }
    .pp-ft-btn.pp-ft-back{ font-size:1.35rem; }
    .pp-tabs{ top:34px; }

    .pp-wrap{ padding:0 11px; }
    .pp-header-row{ gap:10px; padding:9px 0; }
    .pp-avatar{ width:60px; height:60px; border-width:2px; }
    .pp-nickname-row{ font-size:10.3px; }
    .pp-stat-num{ font-size:12.3px; }
    .pp-stat-lbl{ font-size:10.3px; }
    .pp-stats{ gap:5px; }

    .pp-pill{ font-size:10.3px; padding:3px 8px; }
    .pp-bio{ font-size:11px; line-height:1.45; }
    .pp-link-row{ font-size:11px; }
    .pp-link-row span{ max-width:155px; }
    .pp-link-row i{ font-size:14px; }

    .pp-actions{ gap:7px; margin-top:11px; }
    .pp-btn{ font-size:11px; padding:7px 0; border-radius:8px; }

    .pp-tab{ font-size:15px; padding:9px 0; }
    .pp-tab.pp-tab-pods{ font-size:11px; }
    .pp-tab.pp-tab-pods i{ font-size:13px; }

    .pp-sortbar{ padding:9px 2px 7px; gap:5px; }
    .pp-sort-pill{ font-size:10.3px; padding:4.5px 10px; }

    .pp-grid{ gap:2px; }
    .pp-grid-badge{ font-size:11px; top:5px; right:5px; }
}

@media (max-width:330px){
    .pp-page-body{ padding-top:31px; }
    .pp-fixed-topbar{ padding:5px 8px; }
    .pp-ft-title{ font-size:.64rem; }
    .pp-ft-btn{ font-size:1rem; }
    .pp-ft-btn.pp-ft-back{ font-size:1.22rem; }
    .pp-tabs{ top:31px; }

    .pp-wrap{ padding:0 9px; }
    .pp-header-row{ gap:8px; padding:8px 0; }
    .pp-avatar{ width:52px; height:52px; border-width:1.8px; }
    .pp-nickname-row{ font-size:9.5px; }
    .pp-stat-num{ font-size:11.3px; }
    .pp-stat-lbl{ font-size:9.5px; }
    .pp-stats{ gap:4px; }

    .pp-pill{ font-size:9.5px; padding:2.5px 7px; }
    .pp-bio{ font-size:10.3px; line-height:1.4; }
    .pp-link-row{ font-size:10.3px; }
    .pp-link-row span{ max-width:130px; }
    .pp-link-row i{ font-size:13px; }

    .pp-actions{ gap:6px; margin-top:9px; }
    .pp-btn{ font-size:10.3px; padding:6px 0; border-radius:7px; }

    .pp-tab{ font-size:13.5px; padding:8px 0; }
    .pp-tab.active::after{ width:17px; }
    .pp-tab.pp-tab-pods{ font-size:10.3px; }
    .pp-tab.pp-tab-pods i{ font-size:12px; }

    .pp-sortbar{ padding:8px 2px 6px; gap:4px; }
    .pp-sort-pill{ font-size:9.5px; padding:4px 9px; border-radius:8px; }
    .pp-sort-count{ font-size:8.5px; }

    .pp-grid{ grid-template-columns:repeat(2,1fr); gap:2px; }
    .pp-grid-badge{ font-size:10px; }
}

@media (min-width:700px){
    .pp-wrap{ padding:0 24px; }
    .pp-fixed-topbar{ max-width:480px; left:50%; transform:translateX(-50%); }
}
</style>
</head>
<body>

<!-- ══════════════════════════════════════
     FIXED TOPBAR — slim, no border/shadow, blends
     completely with the page background at all times.
══════════════════════════════════════ -->
<div class="pp-fixed-topbar" id="ppFixedTopbar">
    <div class="pp-ft-side">
        <?php if($current_user == $profile_id): ?>
            <a href="memepedia.php" class="pp-ft-btn" aria-label="Memepedia"><i class="ri-book-open-line"></i></a>
        <?php else: ?>
            <button type="button" class="pp-ft-btn pp-ft-back" aria-label="Back" onclick="history.back()"><i class="ri-arrow-left-s-line"></i></button>
        <?php endif; ?>
    </div>

    <div class="pp-ft-title"><?php echo htmlspecialchars($user['username']); ?></div>

    <div class="pp-ft-side pp-ft-right">
        <?php if($current_user == $profile_id): ?>
            <button type="button" class="pp-ft-btn" aria-label="Menu" onclick="openKebabModal()"><i class="ri-menu-line"></i></button>
        <?php else: ?>
            <button type="button" class="pp-ft-btn" aria-label="Menu" onclick="openProfileKebabModal()"><i class="ri-menu-line"></i></button>
        <?php endif; ?>
    </div>
</div>

<div class="pp-page-body">
<div class="pp-wrap">

    <!-- AVATAR + (nickname sitting directly above the stats only) -->
    <div class="pp-header-row">
        <div class="pp-avatar-wrap">
            <img class="pp-avatar" id="profilePic" src="<?php echo htmlspecialchars($profilePic); ?>" onerror="this.src='uploads/mee.jpeg'" alt="" onclick="openPicModal()">
        </div>

        <div class="pp-header-info">
            <?php if(!empty($nickname_display)): ?>
            <div class="pp-nickname-row">@<?php echo htmlspecialchars($nickname_display); ?></div>
            <?php endif; ?>

            <div class="pp-stats">
                <div class="pp-stat" onclick="<?php echo ($can_view ?? true) ? "window.location.href='tracks.php?user_id={$profile_id}&type=followers'" : "alert('Track this Dezzmer to view their Trackers.')"; ?>">
                    <span class="pp-stat-num"><?php echo ppShortNum($followers_count); ?></span><span class="pp-stat-lbl">Trackers</span>
                </div>
                <div class="pp-stat" onclick="<?php echo ($can_view ?? true) ? "window.location.href='tracks.php?user_id={$profile_id}&type=following'" : "alert('Track this Dezzmer to view their Tracking.')"; ?>">
                    <span class="pp-stat-num"><?php echo ppShortNum($following_count); ?></span><span class="pp-stat-lbl">Tracking</span>
                </div>
                <div class="pp-stat" onclick="<?php echo ($can_view ?? true) ? "window.location.href='group_list.php?user_id={$profile_id}'" : "alert('Track this Dezzmer to view their Pods.')"; ?>">
                    <span class="pp-stat-num"><?php echo ppShortNum($post_count); ?></span><span class="pp-stat-lbl">Pods</span>
                </div>
            </div>
        </div>
    </div>

    <!-- PRONOUNS + USER TYPE — side by side, user type red + bold -->
    <?php if($has_pronouns || $has_type): ?>
    <div class="pp-pills-row">
        <?php if($has_pronouns): ?>
        <span class="pp-pill"><?php echo htmlspecialchars($user['pronouns']); ?></span>
        <?php endif; ?>
        <?php if($has_type): ?>
        <span class="pp-pill pp-pill-type"><?php echo htmlspecialchars($user['user_type']); ?></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if(!empty($activity_vibe)): ?>
        <div class="pp-pills-row"><span class="pp-pill"><i class="ri-time-line"></i> Active: <?php echo htmlspecialchars($activity_vibe); ?></span></div>
    <?php endif; ?>

    <!-- BIO — single render, more / less, starts flush left -->
    <?php if(($bio_visible ?? false) && !empty($user['bio'])):
        $bio_limit = 105;
        $bio_plain = $user['bio'];
        if (mb_strlen($bio_plain) > $bio_limit):
            $bio_short_html = ppLinkify(nl2br(htmlspecialchars(mb_substr($bio_plain, 0, $bio_limit))));
            $bio_rest_html  = ppLinkify(nl2br(htmlspecialchars(mb_substr($bio_plain, $bio_limit))));
    ?>
    <div class="pp-bio">
        <span id="ppBioShort"><?php echo $bio_short_html; ?><span id="ppBioDots">...</span></span><span id="ppBioRest" style="display:none;"><?php echo $bio_rest_html; ?></span><span id="ppBioMore" class="pp-more" onclick="ppToggleBio(true)"> more</span><span id="ppBioLess" class="pp-less" style="display:none;" onclick="ppToggleBio(false)"> less</span>
    </div>
    <?php else: ?>
    <div class="pp-bio"><?php echo ppLinkify(nl2br(htmlspecialchars($bio_plain))); ?></div>
    <?php endif; endif; ?>

    <!-- LINKS — after bio, same 1 / 2 / 3+ rules -->
    <?php if($profile_link_count > 0): ?>
        <?php if($profile_link_count <= 2): ?>
        <div class="pp-links-wrap">
            <?php foreach($profile_links as $lk): ?>
            <a class="pp-link-row" href="<?php echo htmlspecialchars($lk['url']); ?>" target="_blank" rel="noopener">
                <i class="ri-link-m"></i><span><?php echo htmlspecialchars($lk['url']); ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="pp-links-wrap" onclick="openLinksModal()" style="cursor:pointer;">
            <div class="pp-link-row"><i class="ri-link-m"></i><span><?php echo htmlspecialchars($profile_links[0]['url']); ?></span></div>
            <div class="pp-link-row"><i class="ri-link-m"></i><span><?php echo htmlspecialchars($profile_links[1]['url']); ?></span><span class="pp-link-more">+<?php echo $profile_link_count - 2; ?> more</span></div>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- ACTION BUTTONS — square, not pill -->
    <?php if($current_user == $profile_id): ?>
        <div class="pp-actions">
            <div class="pp-btn pp-editpic" onclick="openEditPicModal()">Edit Picture</div>
            <a href="untracked.php" class="pp-btn pp-untracked">Untracked</a>
        </div>
    <?php else: ?>
        <form method="POST" class="pp-actions">
            <?php if($follow_check): ?>
                <button type="button" class="pp-btn pp-track" onclick="openUntrackModal()">Untrack</button>
            <?php elseif($profile_is_private ?? false): ?>
                <button type="submit" name="send_track_request" class="pp-btn pp-track">Request Track</button>
            <?php else: ?>
                <button type="submit" name="follow" class="pp-btn pp-track">Track</button>
            <?php endif; ?>
            <button type="button" class="pp-btn pp-message" onclick="window.location.href='messages.php?user=<?php echo (int)$profile_id; ?>'">Message</button>
        </form>
    <?php endif; ?>

</div>
<div class="pp-divider"></div>

<!-- sentinel: when this scrolls out of view, tabs have docked -->
<div id="ppTabsSentinel"></div>

<div class="pp-wrap">

    <!-- TABS — evenly spaced, full-bleed on mobile, centered on desktop.
         Owner: Memes, Reposts, Favs, Messages, Tags, Pods(last).
         Visitor: Memes, Reposts, Favs, Tags, Pods(last). Likes tab removed. -->
    <div class="pp-tabs" id="ppTabsBar">
        <button class="pp-tab active" data-target="pp-sec-memes" onclick="ppSwitchTab(this)"><i class="ri-grid-fill"></i></button>
        <button class="pp-tab" data-target="pp-sec-reposts" onclick="ppSwitchTab(this)"><i class="ri-repeat-line"></i></button>
        <button class="pp-tab" data-target="pp-sec-favs" onclick="ppSwitchTab(this)"><i class="ri-bookmark-line"></i></button>
        <?php if($current_user == $profile_id): ?>
        <button class="pp-tab" data-target="pp-sec-messages" onclick="ppSwitchTab(this)"><i class="ri-chat-1-line"></i></button>
        <?php endif; ?>
        <button class="pp-tab" data-target="pp-sec-tags" onclick="ppSwitchTab(this)"><i class="ri-account-pin-box-line"></i></button>
        <button class="pp-tab pp-tab-pods" title="Total uploads" onclick="ppSwitchTab(this)"><i class="ri-folder-2-line"></i><span><?php echo ppShortNum($post_count); ?></span></button>
    </div>

    <!-- SORT / COLLECTIONS BAR -->
    <div class="pp-sortbar" id="ppSortBar">
        <button class="pp-sort-pill active" id="ppSortNewest" onclick="ppSort('newest')"><i class="ri-time-line" style="margin-right:5px;font-size:11px;"></i>Newest</button>
        <button class="pp-sort-pill" id="ppSortOldest" onclick="ppSort('oldest')"><i class="ri-history-line" style="margin-right:5px;font-size:11px;"></i>Oldest</button>
        <div class="pp-sort-div"></div>
        <button class="pp-sort-pill" onclick="openCollectionsModal()"><i class="ri-folder-3-line" style="margin-right:5px;font-size:11px;"></i>Collections<?php if($col_count_total > 0): ?><span class="pp-sort-count"><?php echo ppShortNum($col_count_total); ?></span><?php endif; ?></button>
    </div>

    <!-- MEMES GRID -->
    <div class="pp-section active" id="pp-sec-memes">
        <div class="pp-grid" id="ppMemesGrid">
            <?php if(!$memes || $memes->num_rows === 0): ?>
                <div class="pp-grid-empty"><i class="ri-grid-line"></i>No posts yet</div>
            <?php else: ?>
                <?php while($row = $memes->fetch_assoc()):
                    $mf = ($row['type'] === 'text' || empty($row['file_name']) || $row['file_name'] === '0')
                          ? 'uploads/Text.jpg' : 'uploads/'.$row['file_name'];
                ?>
                <div class="pp-grid-item" data-ts="<?php echo strtotime($row['created_at']); ?>" onclick="openMemeModal(<?php echo (int)$row['id']; ?>)">
                    <?php if($row['type'] === 'video'): ?>
                        <video src="<?php echo htmlspecialchars($mf); ?>" muted></video>
                        <span class="pp-grid-badge"><i class="ri-play-fill"></i></span>
                    <?php else: ?>
                        <img src="<?php echo htmlspecialchars($mf); ?>" onerror="this.src='uploads/Text.jpg'">
                    <?php endif; ?>
                </div>
                <?php endwhile; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- REPOSTS GRID -->
    <div class="pp-section" id="pp-sec-reposts">
        <div class="pp-grid" id="ppRepostsGrid">
            <?php if(!$reposts || $reposts->num_rows === 0): ?>
                <div class="pp-grid-empty"><i class="ri-repeat-line"></i>No reposts yet</div>
            <?php else: ?>
                <?php while($row = $reposts->fetch_assoc()):
                    $mf = ($row['type'] === 'text' || empty($row['file_name']) || $row['file_name'] === '0')
                          ? 'uploads/Text.jpg' : 'uploads/'.$row['file_name'];
                ?>
                <div class="pp-grid-item" data-ts="<?php echo strtotime($row['created_at'] ?? 'now'); ?>" onclick="openMemeModal(<?php echo (int)$row['id']; ?>)">
                    <?php if($row['type'] === 'video'): ?>
                        <video src="<?php echo htmlspecialchars($mf); ?>" muted></video>
                        <span class="pp-grid-badge"><i class="ri-play-fill"></i></span>
                    <?php else: ?>
                        <img src="<?php echo htmlspecialchars($mf); ?>" onerror="this.src='uploads/Text.jpg'">
                    <?php endif; ?>
                    <span class="pp-grid-badge" style="top:6px;left:6px;right:auto;"><i class="ri-repeat-line"></i></span>
                </div>
                <?php endwhile; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- FAVORITES GRID -->
    <div class="pp-section" id="pp-sec-favs">
        <div class="pp-grid" id="ppFavsGrid">
            <?php if(!$favorites || $favorites->num_rows === 0): ?>
                <div class="pp-grid-empty"><i class="ri-bookmark-line"></i>No favorites yet</div>
            <?php else: ?>
                <?php while($row = $favorites->fetch_assoc()):
                    $mf = ($row['type'] === 'text' || empty($row['file_name']) || $row['file_name'] === '0')
                          ? 'uploads/Text.jpg' : 'uploads/'.$row['file_name'];
                ?>
                <div class="pp-grid-item" data-ts="<?php echo strtotime($row['created_at'] ?? 'now'); ?>" onclick="openMemeModal(<?php echo (int)$row['id']; ?>)">
                    <?php if($row['type'] === 'video'): ?>
                        <video src="<?php echo htmlspecialchars($mf); ?>" muted></video>
                        <span class="pp-grid-badge"><i class="ri-play-fill"></i></span>
                    <?php else: ?>
                        <img src="<?php echo htmlspecialchars($mf); ?>" onerror="this.src='uploads/Text.jpg'">
                    <?php endif; ?>
                </div>
                <?php endwhile; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- MESSAGES (owner only) -->
    <?php if($current_user == $profile_id): ?>
    <div class="pp-section" id="pp-sec-messages">
        <div class="pp-grid-empty" style="grid-column:1/-1;"><i class="ri-chat-1-line"></i>Open Messages to view your conversations</div>
    </div>
    <?php endif; ?>

    <!-- TAGS — no gate/alert, opens normally for everyone -->
    <div class="pp-section" id="pp-sec-tags">
        <div class="pp-grid-empty" style="grid-column:1/-1;"><i class="ri-account-pin-box-line"></i>No tagged posts yet</div>
    </div>

</div>
</div><!-- /.pp-page-body -->

<!-- ══════════════════════════════════════════════════════
     MODAL HOOKS ONLY — buttons already call the right function;
     the real modal markup/behavior gets pasted in later.
══════════════════════════════════════════════════════ -->
<script>
function ppNoop(name){ console.log('[modal placeholder] ' + name + ' — paste real modal code here'); }

function openKebabModal(){ ppNoop('openKebabModal'); }
function openProfileKebabModal(){ ppNoop('openProfileKebabModal'); }
function openPicModal(){ ppNoop('openPicModal'); }
function openEditPicModal(){ ppNoop('openEditPicModal'); }
function openLinksModal(){ ppNoop('openLinksModal'); }
function openCollectionsModal(){ ppNoop('openCollectionsModal'); }
function openMemeModal(memeId){ ppNoop('openMemeModal(' + memeId + ')'); }
function openUntrackModal(){ ppNoop('openUntrackModal'); }
function openReportModal(opts){ ppNoop('openReportModal'); }
function shareProfile(id){ ppNoop('shareProfile(' + id + ')'); }
function copyProfileLink(id){ ppNoop('copyProfileLink(' + id + ')'); }
function showQR(id){ ppNoop('showQR(' + id + ')'); }

/* ── Bio more / less ── */
function ppToggleBio(expand){
    var rest = document.getElementById('ppBioRest');
    var dots = document.getElementById('ppBioDots');
    var more = document.getElementById('ppBioMore');
    var less = document.getElementById('ppBioLess');
    if(!rest) return;
    rest.style.display = expand ? 'inline' : 'none';
    dots.style.display = expand ? 'none' : 'inline';
    more.style.display = expand ? 'none' : 'inline';
    less.style.display = expand ? 'inline' : 'none';
}

/* ── Tabs ── */
function ppSwitchTab(btn){
    document.querySelectorAll('.pp-tab').forEach(function(t){ t.classList.remove('active'); });
    btn.classList.add('active');

    var target = btn.dataset.target;
    var sortBar = document.getElementById('ppSortBar');

    document.querySelectorAll('.pp-section').forEach(function(s){ s.classList.remove('active'); });

    if(!target){
        target = 'pp-sec-memes';
        var memesTab = document.querySelector('.pp-tab[data-target="pp-sec-memes"]');
        if(memesTab){ document.querySelectorAll('.pp-tab').forEach(function(t){ t.classList.remove('active'); }); memesTab.classList.add('active'); }
    }

    var sec = document.getElementById(target);
    if(sec) sec.classList.add('active');

    if(sortBar) sortBar.style.display = (target === 'pp-sec-messages' || target === 'pp-sec-tags') ? 'none' : 'flex';
}

/* ── Newest / Oldest sort ── */
function ppSort(order){
    document.getElementById('ppSortNewest').classList.toggle('active', order === 'newest');
    document.getElementById('ppSortOldest').classList.toggle('active', order === 'oldest');

    var activeSection = document.querySelector('.pp-section.active');
    if(!activeSection) return;
    var grid = activeSection.querySelector('.pp-grid');
    if(!grid) return;

    var items = Array.from(grid.querySelectorAll('.pp-grid-item[data-ts]'));
    items.sort(function(a,b){
        var ta = parseInt(a.dataset.ts) || 0, tb = parseInt(b.dataset.ts) || 0;
        return order === 'newest' ? tb - ta : ta - tb;
    });
    items.forEach(function(item){ grid.appendChild(item); });
}

/* ── Sticky watcher kept for JS hooks only — no visual change on dock,
   topbar stays borderless/shadowless by design. ── */
(function(){
    function initStickyWatcher(){
        var sentinel = document.getElementById('ppTabsSentinel');
        var topbar   = document.getElementById('ppFixedTopbar');
        if(!sentinel || !topbar || !('IntersectionObserver' in window)) return;
        var io = new IntersectionObserver(function(entries){
            entries.forEach(function(entry){
                topbar.classList.toggle('pp-tabs-stuck', !entry.isIntersecting);
            });
        }, { threshold:0, rootMargin:'0px' });
        io.observe(sentinel);
    }
    if(document.readyState === 'loading'){
        document.addEventListener('DOMContentLoaded', initStickyWatcher);
    } else {
        initStickyWatcher();
    }
})();
</script>

</body>
</html>
