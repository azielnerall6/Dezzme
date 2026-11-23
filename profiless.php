<?php
session_start();
include 'config.php';
require_once 'mute_restrict_api.php';
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


/* ── CCM DATA (ads + suggested users for profile modal) ── */
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
        u.banner_pic,
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
$activity_vibe = $user['activity_vibe'] ?? 'Flexible';

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

// --- Banner Update via Modal (AJAX) ---
if (isset($_POST['action']) && $_POST['action'] === 'update_banner_pic' && $current_user) {
    header('Content-Type: application/json');

    if (empty($_FILES['banner_pic_file']) || $_FILES['banner_pic_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'msg' => 'Upload error']);
        exit;
    }

    $file     = $_FILES['banner_pic_file'];
    $maxBytes = 8 * 1024 * 1024; // 8 MB

    if ($file['size'] > $maxBytes) {
        echo json_encode(['success' => false, 'msg' => 'File too large (max 8 MB)']);
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

    $banner_filename = 'banner_' . $current_user . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $banner_destPath = $uploadDir . $banner_filename;

    if (!move_uploaded_file($file['tmp_name'], $banner_destPath)) {
        echo json_encode(['success' => false, 'msg' => 'Could not save file — check server permissions']);
        exit;
    }

    // Delete old banner (cleanup)
    $old_banner_q = $conn->prepare("SELECT banner_pic FROM users WHERE id = ? LIMIT 1");
    $old_banner_q->bind_param("i", $current_user);
    $old_banner_q->execute();
    $old_banner_row = $old_banner_q->get_result()->fetch_assoc();
    $old_banner_q->close();

    if (!empty($old_banner_row['banner_pic'])) {
        $oldBannerPath = ltrim($old_banner_row['banner_pic'], '/');
        if (strpos($oldBannerPath, 'uploads/') === 0 && file_exists($oldBannerPath)) {
            @unlink($oldBannerPath);
        }
    }

    $upd_banner = $conn->prepare("UPDATE users SET banner_pic = ? WHERE id = ?");
    $upd_banner->bind_param("si", $banner_destPath, $current_user);
    $upd_banner->execute();
    $upd_banner->close();

    echo json_encode(['success' => true, 'url' => $banner_destPath]);
    exit;
}

// Count user memes
$post_count = $memes->num_rows ?? 0;
?>
<?php
/* ───────────────── PROFILE STATUS ───────────────── */

$profilePic = $user['profile_pic'] ?? '';
$bannerPic = $user['banner_pic'] ?? '';
$has_banner = !empty($bannerPic);

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

// ── Ensure pin tables exist (safe to run every load — no-op if already created) ──
$conn->query("
    CREATE TABLE IF NOT EXISTS pinned_posts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        meme_id INT NOT NULL,
        pinned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_meme (user_id, meme_id)
    )
");
$conn->query("
    CREATE TABLE IF NOT EXISTS pinned_collections (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        collection_id INT NOT NULL,
        pinned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_col (user_id, collection_id)
    )
");

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
        $res = $conn->query("SELECT meme_id FROM collection_items WHERE collection_id=$col_id");
        while($r = $res->fetch_assoc()) {
            $mid = (int)$r['meme_id'];
            $lc  = $conn->query("SELECT COUNT(*) as c FROM likes     WHERE meme_id=$mid")->fetch_assoc()['c'];
            $rc  = $conn->query("SELECT COUNT(*) as c FROM reposts   WHERE meme_id=$mid")->fetch_assoc()['c'];
            $fc  = $conn->query("SELECT COUNT(*) as c FROM favorites WHERE meme_id=$mid")->fetch_assoc()['c'];
            $li  = $current_user ? $conn->query("SELECT id FROM likes     WHERE meme_id=$mid AND user_id=$current_user")->num_rows > 0 : false;
            $ri  = $current_user ? $conn->query("SELECT id FROM reposts   WHERE meme_id=$mid AND user_id=$current_user")->num_rows > 0 : false;
            $fi  = $current_user ? $conn->query("SELECT id FROM favorites WHERE meme_id=$mid AND user_id=$current_user")->num_rows > 0 : false;
            $states[] = [
                'id'          => $mid,
                'likeCount'   => (int)$lc,
                'repostCount' => (int)$rc,
                'favCount'    => (int)$fc,
                'liked'       => $li,
                'reposted'    => $ri,
                'faved'       => $fi
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

    // Ensure the table exists (same table notifications.php writes to)
    $conn->query("
        CREATE TABLE IF NOT EXISTS notification_reads (
            user_id INT PRIMARY KEY,
            last_read_at DATETIME NOT NULL
        )
    ");

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
   3. NAV — DESKTOP (now a fixed left sidebar)
   ============================================================ */
nav {
    position: fixed;
    left: 0;
    top: 0;
    width: 100px;
    height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 32px;
    padding: 20px;
    border-radius: 15px;
    background: transparent;
    backdrop-filter: blur(15px);
    -webkit-backdrop-filter: blur(15px);
    box-shadow: 0 0 40px rgba(0,0,0,0.6);
    z-index: 100;
}

nav .logo-container {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
}

nav .logo-container img {
    width: 55px;
    height: 55px;
    border-radius: 15px;
    object-fit: cover;
    cursor: pointer;
    transition: 0.3s;
    animation: navLogoFloat 2s ease-in-out infinite;
}

nav .logo-container img:hover {
    transform: scale(1.1);
    box-shadow: 0 0 15px rgba(255,255,255,0.5);
}

/* No room for the wordmark in a 100px column — hide it, logo icon carries the brand */
nav .logo-container .title {
    display: none;
}

@keyframes navLogoFloat {
    0%, 100% { transform: translateY(0); }
    50%      { transform: translateY(-5px); }
}


/* ============================================================
   4. NAV LINKS — DESKTOP (sidebar, stacked vertically)
   ============================================================ */
.nav-links {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 20px;
    width: 100%;
}

.nav-links a {
    width: 50px;
    height: 40px;
    min-width: unset;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    border-radius: 15px;
    text-decoration: none;
    color: white;
    background: transparent;
    backdrop-filter: none;
    -webkit-backdrop-filter: none;
    border: transparent;
    box-shadow: none;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
    -webkit-tap-highlight-color: transparent;
}

.nav-links a:hover {
    transform: scale(1.12);
    box-shadow: 0 0 15px rgba(255,255,255,0.6);
}

.nav-links a:active {
    transform: scale(0.92);
    box-shadow: inset 0 4px 10px rgba(0,0,0,0.4);
}

.nav-links a i {
    transition: transform 0.3s ease;
}

.nav-links a:hover i {
    transform: scale(1.15);
}

/* Shine sweep doesn't read well on small square buttons — drop it */
.nav-links a::before {
    display: none;
}


/* ============================================================
   5. CONTAINER
   ============================================================ */
.container {
    max-width: 1000px;
    margin: 0 auto 0 100px;
    padding: 30px;
    box-sizing: border-box;
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
    gap: 20px;
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
        width: 100%;              /* ← reset sidebar's 100px width */
        height: 60px;
        flex-direction: row;      /* ← reset sidebar's column direction */
        gap: 0;                   /* ← reset sidebar's 32px gap */
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
        flex-direction: row;      /* ← reset sidebar's column direction */
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
        width: auto;               /* ← reset sidebar's fixed 50px width */
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
        margin-left: 0;           /* ← reset sidebar's 100px offset */
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

    /* ── Meme grid ── */
    .grid {
        grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
        gap: 12px;
    }

    .meme-card img,
    .media-box img  { height: 150px; }

    .meme-card video,
    .media-box video { height: 180px; }

    /* ── Card internals ── */
    .caption { font-size: 11.5px; padding: 8px; }

    .top-bar img      { width: 28px; height: 28px; }
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

    /* ── Grid ── */
    .grid { grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 10px; }

    .meme-card img, .media-box img   { height: 130px; }
    .meme-card video, .media-box video { height: 160px; }

    /* ── Card internals ── */
    .caption        { font-size: 11px; padding: 7px; }
    .top-bar        { gap: 5px; }
    .top-bar img    { width: 24px; height: 24px; }
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

    /* ── Grid ── */
    .grid { grid-template-columns: repeat(2, 1fr); gap: 8px; }

    .meme-card img, .media-box img     { height: 115px; }
    .meme-card video, .media-box video { height: 140px; }

    /* ── Card internals ── */
    .caption        { font-size: 10.5px; padding: 6px; }
    .top-bar img    { width: 22px; height: 22px; }
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

    /* ── Grid ── */
    .grid { grid-template-columns: repeat(2, 1fr); gap: 6px; }

    .meme-card img, .media-box img     { height: 100px; }
    .meme-card video, .media-box video { height: 125px; }

    /* ── Card internals ── */
    .caption        { font-size: 9.5px; padding: 5px; }
    .top-bar img    { width: 20px; height: 20px; }
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

    <div class="dz-pt-title">@<?php echo htmlspecialchars($display_name); ?></div>

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

<!-- ══════════ PROFILE BANNER ══════════ -->
<div class="profile-banner-wrap" id="profileBannerWrap">
    <?php if($has_banner): ?>
    <img src="<?= htmlspecialchars($bannerPic) ?>"
         alt=""
         id="profileBannerImg"
         class="profile-banner-img"
         onerror="this.style.display='none';">
    <?php endif; ?>

    <?php if($current_user == $profile_id): ?>
    <button type="button" class="banner-edit-btn" onclick="bnOpenModal()" aria-label="Edit banner">
        <i class="ri-camera-line"></i> <span class="banner-edit-text">Edit banner</span>
    </button>
    <?php endif; ?>
</div>

<?php if($current_user == $profile_id): ?>
<div id="bnBannerModal" onclick="if(event.target===this)bnClose()">
    <div class="bn-box">
        <button class="bn-close" onclick="bnClose()" aria-label="Close"><i class="ri-close-line"></i></button>
        <div class="bn-title">Edit Banner</div>

        <div class="bn-preview-wrap">
            <?php if($has_banner): ?>
            <img src="<?= htmlspecialchars($bannerPic) ?>" id="bnCurrentImg" alt="" onerror="this.style.display='none';">
            <?php else: ?>
            <img src="" id="bnCurrentImg" alt="" style="display:none;">
            <div class="bn-current-empty" id="bnCurrentEmpty"><i class="ri-image-add-line"></i><span>No banner yet</span></div>
            <?php endif; ?>
        </div>

        <button class="bn-change-btn" onclick="document.getElementById('bnFileInput').click()">
            Choose New Banner
        </button>
        <input type="file" id="bnFileInput" accept="image/*" onchange="bnHandleSelect(this)">

        <div id="bnPreviewArea" class="bn-preview-area">
            <span class="bn-preview-label">Preview</span>
            <img id="bnPreviewImg" src="" alt="Preview">
            <div class="bn-preview-actions">
                <button class="bn-save-word" onclick="bnSave(this)">Save Banner</button>
                <button class="bn-cancel-word" onclick="bnCancelPreview()">Cancel</button>
            </div>
        </div>

        <div class="bn-hint">Recommended: 1600×400px (4:1 ratio) &middot; JPG, PNG, GIF or WebP &middot; max 8MB</div>
    </div>
</div>
<div id="bnToast" class="bn-toast"></div>

<style>
#bnBannerModal{display:none;position:fixed;inset:0;z-index:999999;background:rgba(0,0,0,0.82);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);align-items:center;justify-content:center;padding:20px;}
#bnBannerModal.open{display:flex;}
.bn-box{
    background:rgba(14,13,26,0.98);border:1px solid rgba(255,255,255,0.08);
    border-radius:24px;width:100%;max-width:460px;padding:28px 26px 26px;
    box-shadow:0 24px 60px rgba(0,0,0,0.7);position:relative;
    font-family:'Poppins',sans-serif;animation:bnPopIn 0.25s cubic-bezier(.34,1.56,.64,1);
}
@keyframes bnPopIn{from{opacity:0;transform:translateY(20px) scale(0.96);}to{opacity:1;transform:translateY(0) scale(1);}}
.bn-close{position:absolute;top:16px;right:16px;width:30px;height:30px;border-radius:50%;background:rgba(255,255,255,0.06);border:none;color:rgba(255,255,255,0.5);font-size:16px;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:background 0.2s,color 0.2s;}
.bn-close:hover{background:rgba(255,45,45,0.18);color:#ff4040;}
.bn-title{font-size:16px;font-weight:700;color:#fff;text-align:center;margin-bottom:20px;letter-spacing:-.2px;}
.bn-preview-wrap{width:100%;aspect-ratio:4/1;border-radius:14px;overflow:hidden;margin-bottom:16px;border:2px solid rgba(255,45,45,0.4);background:linear-gradient(120deg,#1a1533,#3a0f1f 40%,#0c0e28);position:relative;}
.bn-preview-wrap img{width:100%;height:100%;object-fit:cover;display:block;}
.bn-current-empty{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;color:rgba(255,255,255,0.3);font-size:12px;}
.bn-current-empty i{font-size:26px;}
.bn-change-btn{display:block;margin:0 auto 6px;background:none;border:none;color:#FF2D2D;font-size:13.5px;font-weight:700;cursor:pointer;font-family:'Poppins',sans-serif;transition:color 0.18s;text-align:center;width:100%;}
.bn-change-btn:hover{color:#ff5555;}
#bnFileInput{display:none;}
.bn-preview-area{display:none;flex-direction:column;align-items:center;gap:12px;margin-top:16px;padding:16px;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.07);border-radius:16px;}
.bn-preview-area.visible{display:flex;}
.bn-preview-area img{width:100%;aspect-ratio:4/1;object-fit:cover;border-radius:12px;border:2px solid rgba(255,45,45,0.5);}
.bn-preview-label{font-size:10px;color:rgba(255,255,255,0.35);text-transform:uppercase;letter-spacing:1.1px;font-weight:600;}
.bn-preview-actions{display:flex;gap:24px;}
.bn-save-word,.bn-cancel-word{background:none;border:none;font-size:13px;font-weight:700;font-family:'Poppins',sans-serif;cursor:pointer;transition:color 0.18s;}
.bn-save-word{color:#FF2D2D;}
.bn-save-word:hover{color:#ff5555;}
.bn-cancel-word{color:rgba(255,255,255,0.4);}
.bn-cancel-word:hover{color:rgba(255,255,255,0.7);}
.bn-save-word:disabled{opacity:0.5;pointer-events:none;}
.bn-hint{text-align:center;font-size:11px;color:rgba(255,255,255,0.28);margin-top:16px;line-height:1.5;}
.bn-toast{position:fixed;bottom:30px;left:50%;transform:translateX(-50%) translateY(10px);background:rgba(8,8,22,.97);border:1px solid rgba(255,45,45,.25);color:#fff;padding:8px 20px;border-radius:50px;font-size:12px;font-family:'Poppins',sans-serif;font-weight:500;z-index:9999999;pointer-events:none;box-shadow:0 8px 28px rgba(0,0,0,.65);white-space:nowrap;opacity:0;transition:opacity .22s ease,transform .22s ease;}
.bn-toast.show{opacity:1;transform:translateX(-50%) translateY(0);}

@media (max-width:490px){
    .bn-box{max-width:92vw;padding:22px 18px 20px;border-radius:20px;}
    .bn-title{font-size:14px;margin-bottom:16px;}
    .bn-change-btn{font-size:12.5px;}
    .bn-hint{font-size:10.5px;}
}
@media (max-width:380px){
    .bn-box{padding:20px 16px 18px;border-radius:18px;}
    .bn-title{font-size:13.5px;margin-bottom:14px;}
}
</style>

<script>
(function(){
    var _bnFile = null;

    window.bnOpenModal = function(){
        var m = document.getElementById('bnBannerModal');
        if(!m) return;
        m.classList.add('open');
        document.body.style.overflow = 'hidden';
    };

    window.bnClose = function(){
        var m = document.getElementById('bnBannerModal');
        if(!m) return;
        m.classList.remove('open');
        document.body.style.overflow = '';
        bnCancelPreview();
    };

    window.bnHandleSelect = function(input){
        var f = input.files[0];
        if(!f) return;
        if(!f.type.startsWith('image/')){ bnToast('Please pick an image file'); return; }
        _bnFile = f;
        var r = new FileReader();
        r.onload = function(e){
            document.getElementById('bnPreviewImg').src = e.target.result;
            document.getElementById('bnPreviewArea').classList.add('visible');
        };
        r.readAsDataURL(f);
    };

    window.bnCancelPreview = function(){
        _bnFile = null;
        var pa = document.getElementById('bnPreviewArea');
        if(pa) pa.classList.remove('visible');
        var pi = document.getElementById('bnPreviewImg');
        if(pi) pi.src = '';
        var fi = document.getElementById('bnFileInput');
        if(fi) fi.value = '';
    };

    window.bnSave = function(btn){
        if(!_bnFile) return;
        var orig = btn.textContent;
        btn.textContent = 'Saving…'; btn.disabled = true;
        var fd = new FormData();
        fd.append('action', 'update_banner_pic');
        fd.append('banner_pic_file', _bnFile);
        fetch(window.location.pathname, { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(d){
            btn.disabled = false;
            if(d.success && d.url){
                var c = document.getElementById('bnCurrentImg');
                var wrap = document.getElementById('profileBannerWrap');
                var empty = document.getElementById('bnCurrentEmpty');
                if(empty) empty.style.display = 'none';
                if(c){ c.src = d.url; c.style.display = 'block'; }

                // Update or create the live banner img on the page
                var live = document.getElementById('profileBannerImg');
                if(live){
                    live.src = d.url;
                    live.style.display = 'block';
                } else if(wrap){
                    var img = document.createElement('img');
                    img.src = d.url;
                    img.id = 'profileBannerImg';
                    img.className = 'profile-banner-img';
                    img.alt = '';
                    wrap.insertBefore(img, wrap.firstChild);
                }

                bnCancelPreview();
                btn.textContent = orig;
                bnToast('Banner updated ✓');
                setTimeout(bnClose, 700);
            } else {
                btn.textContent = orig;
                bnToast(d.msg || 'Could not save — try again');
            }
        })
        .catch(function(){
            btn.disabled = false; btn.textContent = orig;
            bnToast('Network error — check connection');
        });
    };

    window.bnToast = function(msg){
        var t = document.getElementById('bnToast');
        if(!t) return;
        t.textContent = msg; t.classList.add('show');
        clearTimeout(t._tid);
        t._tid = setTimeout(function(){ t.classList.remove('show'); }, 2600);
    };

    document.addEventListener('keydown', function(e){
        if(e.key === 'Escape'){
            var m = document.getElementById('bnBannerModal');
            if(m && m.classList.contains('open')) bnClose();
        }
    });
})();
</script>
<?php endif; ?>


<style>
/* ══════════════════════════════════════════════
   PROFILE BANNER
══════════════════════════════════════════════ */
.profile-banner-wrap{
    position:relative;
    width:91%;
    height:210px;
    left:137px;
    overflow:hidden;
    margin:10px -30px -15px -30px;
    background:linear-gradient(120deg,#1a1533,#3a0f1f 40%,#0c0e28);
}
.profile-banner-img{
    width:100%;
    height:100%;
    object-fit:cover;
    display:block;
}
.banner-edit-btn{
    position:absolute;
    bottom:14px;
    right:20px;
    display:flex;
    align-items:center;
    gap:6px;
    background:rgba(0,0,0,0.55);
    backdrop-filter:blur(10px);
    -webkit-backdrop-filter:blur(10px);
    border:1px solid rgba(255,255,255,0.2);
    color:#fff;
    font-size:13px;
    font-weight:600;
    padding:8px 16px;
    border-radius:999px;
    cursor:pointer;
    transition:background 0.2s,transform 0.2s;
    font-family:'Poppins',sans-serif;
    z-index:5;
}
.banner-edit-btn:hover{background:rgba(255,45,45,0.75);transform:translateY(-1px);}
.banner-edit-btn i{font-size:15px;}

/* Responsive — note: top margin stays 0 on mobile so it doesn't collide
   with the fixed .dz-profile-topbar (which already reserves top space
   via .container's padding-top). Only left/right/bottom get cancelled. */
@media (max-width:788px){
    .profile-banner-wrap{
        left:15px;
        width:100%;
        height:160px;
        margin:0 -16px -40px -16px;
    }
    .banner-edit-btn{bottom:10px;right:12px;padding:6px 10px;font-size:11px;}
    .banner-edit-text{display:none;}
    .banner-edit-btn i{font-size:16px;}
}
@media (max-width:490px){
    .profile-banner-wrap{height:140px;}
}
@media (max-width:380px){
    .profile-banner-wrap{left:10px;height:120px;margin:0 -10px -40px -10px;}
}
@media (max-width:330px){
    .profile-banner-wrap{height:105px;margin:0 -8px -30px -8px;}
}

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

.dz-pt-title {
    flex: 1;
    min-width: 0;
    text-align: center;
    font-size: 0.8rem;
    font-weight: 600;
    color: rgba(255,255,255,0.55);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    padding: 0 8px;
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
    .dz-pt-title { font-size: 0.72rem; }
    .dz-pt-btn { font-size: 1.35rem; }
    .dz-pt-btn.dz-pt-back { font-size: 1.75rem; }
    .container { padding-top: 58px !important; }
}
@media (max-width: 380px) {
    .dz-profile-topbar { padding: 10px 10px; }
    .dz-pt-title { font-size: 0.68rem; }
    .dz-pt-btn { font-size: 1.25rem; }
    .dz-pt-btn.dz-pt-back { font-size: 1.6rem; }
    .container { padding-top: 54px !important; }
}
@media (max-width: 330px) {
    .dz-profile-topbar { padding: 9px 9px; }
    .dz-pt-title { font-size: 0.64rem; }
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
    top: 270px;
    right: 20px;
    display: flex;
    align-items: center;
    gap: 10px; /* THIS is what makes them sit side by side */
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
        <a href="setting.php" class="kbm-row">
            <i class="ri-settings-3-line"></i> Your Settings
        </a>
        <a href="search.php?focus=1" class="kbm-row">
            <i class="ri-search-line"></i> Search
        </a>

        <a href="audio.php?user_id=<?php echo $profile_id; ?>" class="kbm-row">
            <i class="ri-play-list-line"></i> Your Sounds
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

<script>
function openKebabModal() {
    var m = document.getElementById('kebabFullModal');
    if (!m) return;
    m.style.display = 'block';
    document.body.style.overflow = 'hidden';
    requestAnimationFrame(function () {
        requestAnimationFrame(function () { m.classList.add('kbm-open'); });
    });
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
    bottom: 6px; right: -1px;
    width: 30px; height: 30px;
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
    z-index: 99990;
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
    z-index: 99996;
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
    z-index: 3;
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
    border-radius: 50%;
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
    .bio-text { font-size: 10px; line-height: 1.3; transform: scale(0.81); transform-origin: left top; opacity: 0.9; }
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
    div[style*="border-radius:20px"]                       { font-size: 12px !important; line-height: 1.25; transform: scale(1);    transform-origin: left top; }
}

@media (max-width: 480px) {
    .pronoun-label, .label-full, .label-short, .type-short { font-size: 10px !important; line-height: 1.2;  transform: scale(0.98); transform-origin: left top; }
    div[style*="border-radius:20px"]                       { font-size: 11px !important; line-height: 1.2;  transform: scale(0.98); transform-origin: left top; }
}

@media (max-width: 400px) {
    .pronoun-label, .label-full, .label-short, .type-short { font-size: 10px   !important; line-height: 1.15; transform: scale(0.95); transform-origin: left top; }
    div[style*="border-radius:20px"]                       { font-size: 10px !important; line-height: 1.15; transform: scale(0.95); transform-origin: left top; }
} 

@media (max-width: 360px) {
    .pronoun-label, .label-full, .label-short, .type-short { font-size: 10px   !important; line-height: 1.15; transform: scale(0.95); transform-origin: left top; }
    div[style*="border-radius:20px"]                       { font-size: 10px !important; line-height: 1.15; transform: scale(0.95); transform-origin: left top; }
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

<h2 style="margin:0; cursor:pointer; user-select:none; position:relative; display:inline-flex; align-items:center; gap:3px;" onclick="toggleUserKebab(event)">
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

<!-- Username + Followers / Following -->
<div style="display:flex; align-items:center; gap: 9px; flex-wrap:wrap;">

<div style="display:flex; gap: 5px; font-size:14px; color:#fff; margin-left:-8px;">

<div class="responsive-flex">
    <?php if (!$is_blocked): ?>
<?php
$blocked_check = $conn->query("
    SELECT 1 FROM blocks 
    WHERE (blocker_id = $current_user AND blocked_id = $profile_id)
       OR (blocker_id = $profile_id AND blocked_id = $current_user)
")->num_rows > 0;
?>
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
<?php
$blocked_check = $conn->query("
    SELECT 1 FROM blocks 
    WHERE (blocker_id = $current_user AND blocked_id = $profile_id)
       OR (blocker_id = $profile_id AND blocked_id = $current_user)
")->num_rows > 0;
?>

<a href="<?php echo (!$blocked_check && $can_view) ? 'group_list.php?user_id='.$profile_id : '#'; ?>" 
   class="group-btn"
   <?php if($blocked_check) echo 'onclick="alert(\'You are blocked, you cannot view this.\'); return false;"';
         elseif(!$can_view) echo 'onclick="alert(\'Track this Peumer to view their pods\'); return false;"'; ?>>
    <i class="ri-group-2-line"></i> Pods
</a>
    </div>
</div>

    <!-- Pronouns, Activity Vibe, and User Type -->
<div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-top:5px;  margin-left:-4px">

    <!-- Pronouns -->
    <?php if(!empty($user['pronouns'])): ?>
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
    <?php if(!empty($activity_vibe)): ?>
        <div style="background: rgba(255,255,255,0.1); padding:4px 12px; border-radius:20px; font-size:14px; color:#fff; max-width: 300px;">
            <span class="label-full"style="color:#f24455; font-weight:600;">Active Hours:</span>
<span class="label-short"></span>
            <?php echo htmlspecialchars($activity_vibe); ?>
        </div>
    <?php endif; ?>

    <!-- User Type -->
    <?php if(!empty($user['user_type'])): ?>
        <div style="background: rgba(255,255,255,0.1); padding:4px 12px; border-radius:20px; font-size:14px; color:#fff; max-width: 160px;">
           <span class="label-full"style="color:#f24455; font-weight:600;"> Type:</span>
<span class="label-short type-short"></span>
            <?php echo htmlspecialchars($user['user_type']); ?>
        </div>
    <?php endif; ?>

</div>

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
// Owner always sees it
// Private profile: only show if viewer is approved tracker
// Public profile: always show
$bio_visible = false;

if($current_user == $profile_id) {
    // Owner always sees their own bio
    $bio_visible = true;

} elseif(!empty($settings['is_fully_private'])) {
    // Private profile — only approved trackers see the bio
    if($current_user) {
        $bio_chk = $conn->prepare("SELECT id FROM track_requests WHERE requester_id=? AND target_id=? AND status='approved'");
        $bio_chk->bind_param("ii", $current_user, $profile_id);
        $bio_chk->execute();
        $bio_visible = $bio_chk->get_result()->num_rows > 0;
        $bio_chk->close();

        // Also approved if already a follower (covers edge cases)
        if(!$bio_visible && $follow_check) {
            $bio_visible = true;
        }
    }

} else {
    // Public profile — everyone sees it
    $bio_visible = true;
}
?>

<?php if($bio_visible && !empty($user['bio'])): ?>
    <p class="bio-text">
        <?php
        $bio   = linkifyBio(htmlspecialchars($user['bio']));
        $limit = 105;
        if(mb_strlen(strip_tags($bio)) > $limit):
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


<style>
.bio-toggle-btn {
    font-size: 12px;
}
@media (max-width: 768px) {
    .bio-toggle-btn { font-size: 11.5px; }
}
@media (max-width: 480px) {
    .bio-toggle-btn { font-size: 11.5px; }
}

/* ---------- MOBILE POSITIONING ---------- */
@media (max-width:768px){

    .profile-action-buttons{
        margin-top:2px !important;
    }

}

@media (max-width:480px){

    .profile-action-buttons{
        margin-top:2px !important;
    }

}


/* ── Tablet (≤768px) ── */
@media (max-width: 768px) {

  .profile-action-buttons .action-btn,
  .profile-action-buttons .block-btn {
    font-size: 0.72rem !important;
  }

}

/* ── Mobile (≤480px) ── */
@media (max-width: 480px) {

  .profile-action-buttons .action-btn,
  .profile-action-buttons .block-btn {
    font-size: 0.63rem !important;
  }

}

</style>
   

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
<?php
// Check if current user has blocked or is blocked by this profile
$blocked_check = $conn->query("
    SELECT 1 FROM blocks 
    WHERE (blocker_id = $current_user AND blocked_id = $profile_id)
       OR (blocker_id = $profile_id AND blocked_id = $current_user)
")->num_rows > 0;
?>

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
    z-index: 99999999;
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

/* Responsive */
@media (max-width: 768px) {
    .pkbm-topbar { padding: 13px 16px; }
    .pkbm-topbar-title { font-size: 14.5px; }
    .pkbm-row { padding: 13px 18px; font-size: 13.5px; gap: 11px; }
    .pkbm-row i { font-size: 17px; }
}

@media (max-width: 480px) {
    .pkbm-topbar { padding: 11px 14px; }
    .pkbm-back-btn { font-size: 26px; }
    .pkbm-topbar-title { font-size: 13.5px; }
    .pkbm-row { padding: 12px 16px; font-size: 12.5px; gap: 10px; }
    .pkbm-row i { font-size: 16px; }
}

@media (max-width: 380px) {
    .pkbm-topbar { padding: 10px 12px; }
    .pkbm-back-btn { font-size: 23px; }
    .pkbm-topbar-title { font-size: 12.5px; }
    .pkbm-row { padding: 10px 13px; font-size: 11.5px; gap: 8px; }
    .pkbm-row i { font-size: 14px; width: 18px; }
}

@media (max-width: 360px) {
    .pkbm-row { font-size: 11px; padding: 9px 12px; }
    .pkbm-row i { font-size: 13.5px; width: 17px; }
}

@media (max-width: 330px) {
    .pkbm-topbar { padding: 9px 10px; }
    .pkbm-back-btn { font-size: 21px; }
    .pkbm-topbar-title { font-size: 11.5px; }
    .pkbm-row { font-size: 10.5px; padding: 8px 10px; gap: 7px; }
    .pkbm-row i { font-size: 12.5px; width: 16px; }
}
</style>


<script>
function openProfileKebabModal() {
    var m = document.getElementById('profileKebabFullModal');
    if (!m) return;
    m.style.display = 'block';
    document.body.style.overflow = 'hidden';
    requestAnimationFrame(function () {
        requestAnimationFrame(function () { m.classList.add('pkbm-open'); });
    });
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
    } elseif (!$can_view) {
        $meme_action   = "alert('Track this Dezzmer to view their memes'); return false;";
        $repost_action = "alert('Track this Dezzmer to view their reposts'); return false;";
    } else {
        $meme_action   = "window.location.href='meme_uploads.php?user_id=".$profile_id."'";
        $repost_action = "window.location.href='repost.php?user_id=".$profile_id."'";
    }

    $can_view_about = $is_following;
    if ($can_view_about) {
        $about_me_btn = '<button onclick="dzOpenAboutMe(' . $profile_id . ')"><i class="ri-user-heart-line"></i> More About Me</button>';
    } else {
        $about_me_btn = '<button onclick="alert(\'Track this Dezzmer to view their About Me\'); return false;"><i class="ri-user-heart-line"></i> More About Me</button>';
    }
    ?>

    <?php if ($current_user == $profile_id): ?>
        <!-- ── OWNER: shows choice sheet ── -->
        <button id="tabMemes" onclick="dzShowTabSheet('memes')">
            <i class="bi bi-grid-3x3-gap-fill"></i> Memes
        </button>
        <button id="tabReposts" onclick="dzShowTabSheet('reposts')">
            <i class="ri-repeat-line"></i> Reposts
        </button>
    <?php else: ?>
        <!-- ── OTHER USERS: go directly to page ── -->
        <button id="tabMemes" onclick="<?php echo $meme_action; ?>">
            <i class="bi bi-grid-3x3-gap-fill"></i> Memes
        </button>
        <button onclick="<?php echo $repost_action; ?>">
            <i class="ri-repeat-line"></i> Reposts
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
        <!-- Skeleton loader -->
        <div class="dz-am-skeleton" id="aboutMeSkeleton">
            <div class="dz-sk-card">
                <div class="dz-sk-title"></div>
                <div class="dz-sk-row"><div class="dz-sk-icon"></div><div class="dz-sk-lines"><div class="dz-sk-line short"></div><div class="dz-sk-line"></div></div></div>
                <div class="dz-sk-row"><div class="dz-sk-icon"></div><div class="dz-sk-lines"><div class="dz-sk-line short"></div><div class="dz-sk-line"></div></div></div>
                <div class="dz-sk-row"><div class="dz-sk-icon"></div><div class="dz-sk-lines"><div class="dz-sk-line short"></div><div class="dz-sk-line med"></div></div></div>
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
                <div class="dz-sk-line full" style="height:60px;border-radius:8px;"></div>
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
.dz-fullscreen-modal{display:none;position:fixed;inset:0;background:#06071A;z-index:9999;flex-direction:column;color:#fff;}
.dz-fullscreen-modal.active{display:flex;}
.dz-fs-header{display:flex;align-items:center;justify-content:space-between;padding:16px 18px;border-bottom:1px solid rgba(255,255,255,.08);position:sticky;top:0;background:#06071A;z-index:10;}
.dz-fs-header h2{font-size:17px;font-weight:600;margin:0;}
.dz-fs-close,.dz-fs-edit,.dz-fs-save{background:transparent;border:none;color:#fff;font-size:30px;cursor:pointer;display:flex;align-items:center;gap:6px;}
.dz-fs-edit{font-size:14px;font-weight:600;color:#E0351A;}
.dz-fs-save{font-size:15px;font-weight:700;color: #d23119;}
.dz-fs-body{flex:1;overflow-y:auto;padding:18px;max-width:640px;margin:0 auto;width:100%;-webkit-overflow-scrolling:touch;box-sizing:border-box;}

/* ── Skeleton loader ──────────────────────────────────────────── */
.dz-am-skeleton{display:flex;flex-direction:column;gap:14px;}
.dz-sk-card{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.06);border-radius:16px;padding:16px;display:flex;flex-direction:column;gap:12px;}
.dz-sk-title{height:12px;width:90px;border-radius:6px;background:rgba(255,255,255,.07);animation:dzShimmer 1.4s infinite;}
.dz-sk-row{display:flex;align-items:center;gap:12px;}
.dz-sk-icon{width:22px;height:22px;border-radius:50%;flex-shrink:0;background:rgba(255,255,255,.07);animation:dzShimmer 1.4s infinite;}
.dz-sk-lines{flex:1;display:flex;flex-direction:column;gap:6px;}
.dz-sk-line{height:11px;border-radius:6px;background:rgba(255,255,255,.07);animation:dzShimmer 1.4s infinite;}
.dz-sk-line.short{width:60px;height:9px;}
.dz-sk-line.med{width:55%;}
.dz-sk-line.full{width:100%;}
@keyframes dzShimmer{
    0%{opacity:.4;}50%{opacity:.9;}100%{opacity:.4;}
}

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
  .dz-fs-header{padding:13px 15px;}
  .dz-fs-header h2{font-size:15px;}
  .dz-fs-close{font-size:25px;}
  .dz-fs-edit{font-size:13px;}
  .dz-fs-save{font-size:14px;}
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
  .dz-fs-header{padding:11px 13px;}
  .dz-fs-header h2{font-size:14px;}
  .dz-fs-close{font-size:24px;}
  .dz-fs-edit,.dz-fs-save{font-size:12px;gap:4px;}
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
  .dz-fs-header{padding:10px 11px;}
  .dz-fs-header h2{font-size:13px;}
  .dz-fs-close{font-size:22px;}
  .dz-fs-edit,.dz-fs-save{font-size:11.5px;gap:3px;}
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
  .dz-fs-header{padding:9px 10px;}
  .dz-fs-header h2{font-size:12.5px;}
  .dz-fs-close{font-size:19px;}
  .dz-fs-edit,.dz-fs-save{font-size:11px;gap:2px;}
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
let dzAboutMeData      = null;
let dzFamilyData       = [];
let dzEventsData       = [];
let dzAboutMeProfileId = null;
let dzFamPicFile       = null;

// ── Open / close view modal ────────────────────────────────────────────────
function dzOpenAboutMe(profileId) {
    dzAboutMeProfileId = profileId;
    const modal = document.getElementById('aboutMeModal');
    const body  = document.getElementById('aboutMeBody');
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';

    // Show skeleton
    body.innerHTML = `
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
                <div class="dz-sk-line full" style="height:60px;border-radius:8px;margin-top:4px;"></div>
            </div>
        </div>`;

    fetch('about_me_fetch.php?user_id=' + profileId)
        .then(r => r.json())
        .then(data => {
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
    // Assumes a table: pinned_posts (id, user_id, meme_id, pinned_at)
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
    // Assumes a column: is_pinned in collections table, or a separate pinned_collections table
    // Using pinned_collections (id, user_id, collection_id, pinned_at)
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
function buildMemeCard($row, $conn, $current_user, $profile_id, $pinned_meme_ids, $feedVarName, $diff_override = null) {
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

    $plc = $conn->query("SELECT COUNT(*) as c FROM likes     WHERE meme_id=$mid")->fetch_assoc()['c'];
    $prc = $conn->query("SELECT COUNT(*) as c FROM reposts   WHERE meme_id=$mid")->fetch_assoc()['c'];
    $pfc = $conn->query("SELECT COUNT(*) as c FROM favorites WHERE meme_id=$mid")->fetch_assoc()['c'];
    $pli = $conn->query("SELECT id FROM likes     WHERE meme_id=$mid AND user_id=$current_user")->num_rows > 0;
    $pri = $conn->query("SELECT id FROM reposts   WHERE meme_id=$mid AND user_id=$current_user")->num_rows > 0;
    $pfi = $conn->query("SELECT id FROM favorites WHERE meme_id=$mid AND user_id=$current_user")->num_rows > 0;

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
    if($current_user == $profile_id) {
        $addColBtn = '<button class="card-add-col-btn" title="Add to Collection" onclick="event.stopPropagation();gOpenAddToCol('.$mid.')"><i class="ri-folder-add-line"></i></button>';
    }

    ob_start();
    ?>
    <script><?=$feedVarName?>.push({
        id:<?=$mid?>, file:'<?=$smf?>', type:'<?=addslashes($row['type'])?>',
        pic:'<?=$sp?>', userId:<?=(int)$row['uploaded_by']?>, username:'<?=$su?>',
        caption:'<?=$sc?>', time:'<?=$st?>', isYou:<?=$iy?>,
        likeCount:<?=(int)$plc?>, repostCount:<?=(int)$prc?>, favCount:<?=(int)$pfc?>,
        liked:<?=$pli?'true':'false'?>, reposted:<?=$pri?'true':'false'?>, faved:<?=$pfi?'true':'false'?>,
        isPinned:<?=$isPinned?'true':'false'?>
    });</script>
    <div class="meme-card <?=$is_new?'is-new':''?> <?=$is_fading?'is-fading':''?>"
         data-id="<?=$mid?>"
         data-ts="<?=(int)$created?>"
         data-pinned="<?=$isPinned?'1':'0'?>">
        <div class="top-bar">
            <img src="<?=$row['uploader_pic'] ?? 'uploads/default.jpg'?>"
                 onclick="window.location.href='profile.php?user_id=<?=$row['uploaded_by']?>'">
            <div class="username" onclick="window.location.href='profile.php?user_id=<?=$row['uploaded_by']?>'">
                <?= ($current_user == $row['uploaded_by']) ? 'You' : htmlspecialchars($row['username'] ?? '') ?>
            </div>
        </div>
        <div class="media-box" style="position:relative;">
            <?php if($row['type'] == "image"): ?>
                <img src="uploads/<?=$sf?>" onclick="openMemeModal('<?=$smf?>','<?=$row['type']?>','<?=$sc?>','<?=$sp?>','<?=$su?>',<?=$row['uploaded_by']?>,'<?=$feedVarName?>',<?=$mid?>)">
            <?php elseif($row['type'] == "video"): ?>
                <video src="uploads/<?=$sf?>" autoplay muted loop onclick="openMemeModal('<?=$smf?>','<?=$row['type']?>','<?=$sc?>','<?=$sp?>','<?=$su?>',<?=$row['uploaded_by']?>,'<?=$feedVarName?>',<?=$mid?>)"></video>
            <?php else: ?>
                <img src="uploads/Text.jpg" onclick="openMemeModal('uploads/Text.jpg','image','<?=$sc?>','<?=$sp?>','<?=$su?>',<?=$row['uploaded_by']?>,'<?=$feedVarName?>',<?=$mid?>)">
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

<!-- ══════════════════════════════════════════════════════════
     SHARED GRID LOADER — dashed ring, red, fast spin
     One CSS block + one JS block, reused by every grid below.
     ══════════════════════════════════════════════════════════ -->
<style>
.dezz-grid-loader{display:flex;align-items:center;justify-content:center;width:100%;padding:54px 20px;grid-column:1/-1;transition:opacity 0.2s ease;}
.dezz-grid-loader.dezz-hide{display:none;}
.dezz-dashed-ring{width:40px;height:40px;border-radius:50%;border:3px dashed #FF2D2D;animation:dezzRingSpin 0.4s linear infinite;}
@keyframes dezzRingSpin{to{transform:rotate(360deg);}}
</style>
<script>
function dezzInitGridLoader(targetId){
    var grid = document.getElementById(targetId);
    if(!grid) return;
    var loader = grid.parentNode.querySelector('.dezz-grid-loader[data-target="'+targetId+'"]');
    if(!loader) return;
    var media = grid.querySelectorAll('img, video');
    var done = false;
    function finish(){
        if(done) return;
        done = true;
        loader.classList.add('dezz-hide');
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
    setTimeout(finish, 2500);
}
document.addEventListener('DOMContentLoaded', function(){
    ['memesGrid','repostsGrid','tagsGrid','favsGrid','likesGrid','uploadsGrid'].forEach(dezzInitGridLoader);
});
</script>

<!-- ══ NORMAL MEMES GRID ══ -->
<div id="memes" class="dropdown-section" style="display:grid;">
    <div class="grid-sort-bar" id="memesSortBar">
        <button class="g-pill active" id="memesN" onclick="gSort('memesGrid','newest','memes')"><i class="ri-time-line" style="margin-right:5px;font-size:11px;"></i>Newest</button>
        <button class="g-pill" id="memesO" onclick="gSort('memesGrid','oldest','memes')"><i class="ri-history-line" style="margin-right:5px;font-size:11px;"></i>Oldest</button>
        <div class="g-pill-div"></div>
        <button class="g-pill" id="memesC" onclick="gOpenCol('memes')"><i class="ri-folder-3-line" style="margin-right:5px;font-size:11px;"></i>Collections<span class="g-pill-count"><?= $col_count_total > 0 ? dezzFmt($col_count_total) : '' ?></span></button>
    </div>
    <div class="grid" id="memesGrid">
        <div class="dezz-grid-loader" data-target="memesGrid"><div class="dezz-dashed-ring"></div></div>
        <?php if(!$memes || $memes->num_rows === 0): ?>
        <div style="grid-column:1/-1;text-align:center;padding:40px 20px;color:#aaa;font-size:0.95rem;">
            <i class="bi bi-grid-3x3-gap-fill" style="font-size:2.5rem;display:block;margin-bottom:10px;color:#FF4C4C;"></i>No memes yet
        </div>
        <?php else: ?>
        <script>var profileFeed = [];</script>
        <?php
        // Separate pinned and normal rows
        $all_meme_rows = [];
        while($row = $memes->fetch_assoc()) $all_meme_rows[] = $row;

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


<!-- MODAL -->
<div id="memeModal" onclick="if(event.target===this)closeMemeModal()" style="display:none;position:fixed;inset:0;z-index:9999999999999;background:rgba(0,0,0,0.92);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);align-items:center;justify-content:center;padding:16px;flex-direction:row;gap:12px;">
    <div id="meme-modal-card" style="background:rgba(18,17,17,0.95);border:1px solid rgba(255,255,255,0.07);border-radius:20px;width:100%;max-width:420px;overflow:hidden;display:flex;flex-direction:column;max-height:90vh;">
        <div id="meme-modal-inner"></div>
    </div>
    <div id="meme-modal-arrows" style="display:flex;flex-direction:column;align-items:center;gap:10px;flex-shrink:0;">
        <button onclick="_profileFeedLoadIndex(profileFeedIndex-1)" onmouseover="this.style.background='rgba(255,255,255,0.15)'" onmouseout="this.style.background='rgba(255,255,255,0.07)'" style="background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.12);border-radius:50%;width:40px;height:40px;display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;transition:background 0.2s;"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.7)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"/></svg></button>
        <button onclick="_profileFeedLoadIndex(profileFeedIndex+1)" onmouseover="this.style.background='rgba(255,255,255,0.15)'" onmouseout="this.style.background='rgba(255,255,255,0.07)'" style="background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.12);border-radius:50%;width:40px;height:40px;display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;transition:background 0.2s;"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.7)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></button>
    </div>
    <div id="meme-modal-hint" style="position:absolute;bottom:10px;left:50%;transform:translateX(-50%);color:rgba(255,255,255,0.18);font-size:10px;white-space:nowrap;pointer-events:none;">swipe or use arrows ↕</div>
</div>

<style>
#meme-modal-inner{display:flex;flex-direction:column;overflow:hidden;flex:1;}
.pmm-caption-box::-webkit-scrollbar{width:4px;}.pmm-caption-box::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.15);border-radius:999px;}
.pmm-action-bar{display:flex;align-items:center;gap:6px;padding:10px 14px;border-top:1px solid rgba(255,255,255,0.06);flex-shrink:0;}
.pmm-action-btn{display:flex;align-items:center;justify-content:center;gap:5px;background:transparent;border:1px solid rgba(255,255,255,0.1);border-radius:10px;padding:7px 12px;color:white;cursor:pointer;font-size:12px;font-weight:600;font-family:Poppins,sans-serif;transition:background 0.2s;flex:1;}
.pmm-action-btn:hover{background:rgba(255,255,255,0.07);}
.pmm-close-btn{display:flex;align-items:center;justify-content:center;background:transparent;border:1px solid rgba(255,255,255,0.1);border-radius:10px;padding:7px 12px;color:rgba(255,255,255,0.45);cursor:pointer;font-size:12px;font-family:Poppins,sans-serif;}
.pmm-ad-wrap{padding:14px;display:flex;flex-direction:column;gap:10px;overflow-y:auto;flex:1;}
.pmm-ad-header{display:flex;align-items:center;gap:8px;}.pmm-ad-logo{width:28px;height:28px;border-radius:50%;border:1.5px solid #FF2D2D;object-fit:cover;flex-shrink:0;}.pmm-ad-brand{font-size:13px;font-weight:600;color:white;}.pmm-ad-type{font-size:10px;color:rgba(255,255,255,0.4);}
.pmm-ad-media{width:100%;border-radius:12px;overflow:hidden;border:1.5px solid rgba(255,45,45,0.45);}.pmm-ad-media img,.pmm-ad-media video{width:100%;display:block;object-fit:cover;max-height:200px;}
.pmm-ad-caption{font-size:11px;color:rgba(255,255,255,0.7);line-height:1.45;}
.pmm-ad-cta{display:flex;align-items:center;justify-content:center;gap:6px;padding:10px;background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.1);border-radius:12px;color:white;font-weight:600;font-size:12px;text-decoration:none;transition:background 0.2s;}.pmm-ad-cta:hover{background:rgba(255,255,255,0.12);}
.pmm-sugg-label{font-size:10px;font-weight:700;letter-spacing:1.2px;color:#f24455;text-transform:uppercase;}
.pmm-sugg-scroll{display:flex;flex-wrap:nowrap;overflow-x:auto;gap:14px;padding:4px 0 8px;scrollbar-width:none;}.pmm-sugg-scroll::-webkit-scrollbar{display:none;}
.pmm-sugg-user{flex:0 0 auto;width:68px;display:flex;flex-direction:column;align-items:center;gap:5px;text-decoration:none;color:white;}
.pmm-sugg-user img{width:52px;height:52px;border-radius:50%;border:2px solid #FF2D2D;object-fit:cover;display:block;transition:transform 0.2s;}.pmm-sugg-user:hover img{transform:scale(1.08);}
.pmm-sugg-name{font-size:10px;font-weight:500;text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;width:100%;}
.pmm-sugg-track{font-size:9px;font-weight:600;padding:3px 0;width:100%;border-radius:8px;border:1px solid rgba(255,45,45,0.5);background:transparent;color:rgba(255,255,255,0.7);cursor:pointer;transition:all 0.2s;font-family:Poppins,sans-serif;text-align:center;}
.pmm-sugg-track:hover,.pmm-sugg-track.tracking{background:rgba(255,45,45,0.15);border-color:#FF2D2D;color:#FF2D2D;}
@media(max-width:480px){#meme-modal-arrows,#meme-modal-hint{display:none!important;}#memeModal{padding:10px!important;align-items:center!important;}#meme-modal-card{border-radius:20px 20px 14px 14px!important;max-height:88vh!important;}.pmm-caption-box{font-size:12px!important;max-height:60px!important;}.pmm-action-btn{padding:7px 8px!important;font-size:11px!important;}}
@media(max-width:360px){.pmm-caption-box{font-size:11px!important;max-height:55px!important;}.pmm-action-btn{padding:6px!important;font-size:10px!important;}}
</style>

<script>
let profileFeedIndex=0,_activeFeed=[],_pmmFeed=[],_pmmMemeId=null;
var _pmmSortDir = 'newest';

function _loadPmmData(){Promise.all([fetch('profile.php?type=ads').then(function(r){return r.json();}).catch(function(){return[];}),fetch('profile.php?type=sugg').then(function(r){return r.json();}).catch(function(){return[];})]).then(function(res){window.cmAds=res[0]||[];window.cmSuggUsers=res[1]||[];});}
function _buildPmmFeed(){_pmmFeed=[];var ai=0,si=0,ads=window.cmAds||[],sugg=window.cmSuggUsers||[];_activeFeed.forEach(function(p,i){_pmmFeed.push({type:'post',data:p});if((i+1)%4===0&&ads.length>0){_pmmFeed.push({type:'ad',data:ads[ai%ads.length]});ai++;}if((i+1)%7===0&&sugg.length>0){var sl=[];for(var j=0;j<6;j++)sl.push(sugg[(si+j)%sugg.length]);_pmmFeed.push({type:'sugg',data:sl});si+=6;}});}
function _pmmFmt(t){if(!t)return'';var d=new Date(t.replace(' ','T'));if(isNaN(d))return t;var m=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];return d.getDate()+' '+m[d.getMonth()]+' '+d.getFullYear()+' · '+String(d.getHours()).padStart(2,'0')+':'+String(d.getMinutes()).padStart(2,'0');}
function _pmmLink(c){return c.replace(/@([a-zA-Z0-9_]+)/g,function(m,u){return'<a href="profile.php?username='+encodeURIComponent(u)+'" onclick="event.stopPropagation()" style="color:#f24455;font-weight:600;text-decoration:none;" onmouseover="this.style.textDecoration=\'underline\'" onmouseout="this.style.textDecoration=\'none\'">'+m+'</a>';});}

var _pmmCurrentUserId = null;
function _pmmRenderPost(item){
    var p=item.data;
    _pmmMemeId=p.id;
    _pmmCurrentUserId=p.userId;
    var isT=(p.type==='text'||!p.file||p.file==='uploads/'||p.file==='uploads/0'||p.file.endsWith('/Text.jpg'));
    var mh=isT?'':p.type==='video'?'<video src="'+p.file+'" autoplay muted loop playsinline style="width:100%;max-height:52vh;object-fit:contain;display:block;"></video>':'<img src="'+p.file+'" onerror="this.src=\'uploads/me.jpeg\'" style="width:100%;max-height:52vh;object-fit:contain;display:block;">';
    var lc=p.likeCount||0,rc=p.repostCount||0,fc=p.favCount||0,li=p.liked||false,re=p.reposted||false,fa=p.faved||false;
    return'<div style="display:flex;align-items:center;gap:10px;padding:14px 16px;flex-shrink:0;"><img src="'+(p.pic||'uploads/me.jpeg')+'" onerror="this.src=\'uploads/me.jpeg\'" onclick="goToModalProfile()" style="width:40px;height:40px;border-radius:50%;border:2px solid #FF4C4C;cursor:pointer;object-fit:cover;flex-shrink:0;"><div style="flex:1;min-width:0;"><div onclick="goToModalProfile()" style="color:#FF4C4C;font-weight:700;font-size:14px;cursor:pointer;display:flex;align-items:center;gap:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" style="flex-shrink:0;"><circle cx="12" cy="12" r="12" fill="#FF0000"/><path d="M9 12l2 2 4-4" stroke="white" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg><span>'+(p.isYou?'You':p.username)+'</span></div><div style="font-size:11px;color:rgba(255,255,255,0.35);margin-top:3px;display:flex;align-items:center;gap:4px;"><i class="ri-time-line" style="font-size:10px;"></i><span>'+_pmmFmt(p.time||'')+'</span></div></div><button onclick="closeMemeModal()" style="background:rgba(255,255,255,0.06);border:none;border-radius:50%;width:30px;height:30px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:rgba(255,255,255,0.5);font-size:16px;flex-shrink:0;">✕</button></div>'+(mh?'<div style="width:100%;background:#000;flex-shrink:0;">'+mh+'</div>':'')+'<div class="pmm-caption-box" style="padding:10px 16px;color:rgba(255,255,255,0.8);font-size:13px;line-height:1.55;border-top:1px solid rgba(255,255,255,0.06);word-break:break-word;white-space:pre-wrap;overflow-y:auto;max-height:80px;flex-shrink:0;">'+_pmmLink((p.caption||'').replace(/\\n/g,'\n'))+'</div><div class="pmm-action-bar"><button id="pmm-like-btn" class="pmm-action-btn" style="color:'+(li?'#ff4c4c':'white')+';border-color:'+(li?'rgba(255,76,76,0.4)':'rgba(255,255,255,0.1)')+';" onclick="pmmAction(\'like\')"><i class="ri-heart-fill" style="font-size:14px;"></i><span id="pmm-like-count">'+lc+'</span></button><button id="pmm-repost-btn" class="pmm-action-btn" style="color:'+(re?'#ff4c4c':'white')+';border-color:'+(re?'rgba(255,76,76,0.4)':'rgba(255,255,255,0.1)')+';" onclick="pmmAction(\'repost\')"><i class="ri-repeat-line" style="font-size:14px;"></i><span id="pmm-repost-count">'+rc+'</span></button><button id="pmm-fav-btn" class="pmm-action-btn" style="color:'+(fa?'#ff4c4c':'white')+';border-color:'+(fa?'rgba(255,76,76,0.4)':'rgba(255,255,255,0.1)')+';" onclick="pmmAction(\'fav\')"><i class="ri-bookmark-fill" style="font-size:14px;"></i><span id="pmm-fav-count">'+fc+'</span></button><button class="pmm-close-btn" onclick="closeMemeModal()">Close</button></div>';
}

function _pmmRenderAd(item){var ad=item.data;var ext=(ad.meme_image||'').split('.').pop().toLowerCase();var iv=['mp4','webm','mov'].indexOf(ext)!==-1;_pmmMemeId=null;_pmmCurrentUserId=null;return'<div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px 0;flex-shrink:0;"><span style="font-size:9px;font-weight:800;letter-spacing:1px;color:rgba(255,255,255,0.35);text-transform:uppercase;border:1px solid rgba(255,255,255,0.12);border-radius:4px;padding:2px 6px;">AD</span><button onclick="closeMemeModal()" style="background:rgba(255,255,255,0.06);border:none;border-radius:50%;width:30px;height:30px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:rgba(255,255,255,0.5);font-size:16px;">✕</button></div><div class="pmm-ad-wrap"><div class="pmm-ad-header"><img src="uploads/'+(ad.image||'')+'" onerror="this.src=\'uploads/me.jpeg\'" class="pmm-ad-logo"><div style="min-width:0;"><div class="pmm-ad-brand">'+(ad.title||'')+'</div><div class="pmm-ad-type">'+(ad.type||'')+'</div></div></div><div class="pmm-ad-media">'+(iv?'<video src="uploads/'+ad.meme_image+'" autoplay muted loop playsinline></video>':'<img src="uploads/'+(ad.meme_image||'')+'" onerror="this.src=\'uploads/me.jpeg\'">')+'</div><div class="pmm-ad-caption">'+(ad.caption||'')+'</div><a href="'+(ad.link||'#')+'" target="_blank" class="pmm-ad-cta">Visit Brand <i class="ri-arrow-right-up-line"></i></a></div><div class="pmm-action-bar"><button class="pmm-action-btn" onclick="_profileFeedNav(-1)"><i class="ri-arrow-up-line"></i> Prev</button><button class="pmm-action-btn" onclick="_profileFeedNav(1)">Next <i class="ri-arrow-down-line"></i></button><button class="pmm-close-btn" onclick="closeMemeModal()">Close</button></div>';}
function _pmmRenderSugg(item){var users=item.data;_pmmMemeId=null;_pmmCurrentUserId=null;var uh=users.map(function(u){var f=parseInt(u.is_following)>0;return'<div class="pmm-sugg-user"><a href="profile.php?user_id='+u.id+'" onclick="event.stopPropagation()" style="display:contents;color:inherit;text-decoration:none;"><img src="'+(u.profile_pic||'uploads/me.jpeg')+'" onerror="this.src=\'uploads/me.jpeg\'"><span class="pmm-sugg-name">'+u.username+'</span></a><button class="pmm-sugg-track '+(f?'tracking':'')+'" data-user="'+u.id+'" onclick="event.stopPropagation();pmmToggleSugg(this)">'+(f?'Untrack':'Track')+'</button></div>';}).join('');return'<div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px 0;flex-shrink:0;"><span class="pmm-sugg-label">Suggested Peumers</span><button onclick="closeMemeModal()" style="background:rgba(255,255,255,0.06);border:none;border-radius:50%;width:30px;height:30px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:rgba(255,255,255,0.5);font-size:16px;">✕</button></div><div style="padding:10px 14px;overflow-y:auto;flex:1;"><div class="pmm-sugg-scroll">'+uh+'</div></div><div class="pmm-action-bar"><button class="pmm-action-btn" onclick="_profileFeedNav(-1)"><i class="ri-arrow-up-line"></i> Prev</button><button class="pmm-action-btn" onclick="_profileFeedNav(1)">Next <i class="ri-arrow-down-line"></i></button><button class="pmm-close-btn" onclick="closeMemeModal()">Close</button></div>';}

function _pmmRenderSlot(i){if(i<0||i>=_pmmFeed.length)return;var item=_pmmFeed[i];var inner=document.getElementById('meme-modal-inner');inner.querySelectorAll('video').forEach(function(v){v.pause();v.src='';});if(item.type==='post')inner.innerHTML=_pmmRenderPost(item);else if(item.type==='ad')inner.innerHTML=_pmmRenderAd(item);else if(item.type==='sugg')inner.innerHTML=_pmmRenderSugg(item);}

function _profileFeedNav(step){
    var actualStep = (_pmmSortDir === 'oldest') ? -step : step;
    _profileFeedLoadIndex(profileFeedIndex + actualStep);
}

function openMemeModal(src,type,caption,pic,username,userId,feedName,memeId){
    _activeFeed=window[feedName]||profileFeed;
    _buildPmmFeed();
    var idx=-1;
    if(memeId){
        idx=_pmmFeed.findIndex(function(s){return s.type==='post'&&String(s.data.id)===String(memeId);});
    }
    if(idx<0){
        idx=_pmmFeed.findIndex(function(s){return s.type==='post'&&String(s.data.userId)===String(userId)&&s.data.caption===caption.replace(/\\'/g,"'");});
    }
    profileFeedIndex=idx>=0?idx:0;
    _pmmRenderSlot(profileFeedIndex);
    document.getElementById('memeModal').style.display='flex';
    document.body.style.overflow='hidden';
}

function _profileFeedLoadIndex(idx){if(idx<0||idx>=_pmmFeed.length)return;var card=document.getElementById('meme-modal-card');var dir=idx>profileFeedIndex?1:-1;card.style.transition='transform 0.22s ease,opacity 0.22s ease';card.style.transform='translateY('+(dir*-30)+'px)';card.style.opacity='0';setTimeout(function(){profileFeedIndex=idx;_pmmRenderSlot(profileFeedIndex);card.style.transition='none';card.style.transform='translateY('+(dir*30)+'px)';card.style.opacity='0';requestAnimationFrame(function(){card.style.transition='transform 0.22s ease,opacity 0.22s ease';card.style.transform='translateY(0)';card.style.opacity='1';});},220);}

function closeMemeModal(){document.getElementById('meme-modal-inner').querySelectorAll('video').forEach(function(v){v.pause();v.src='';});document.getElementById('memeModal').style.display='none';document.body.style.overflow='';_pmmMemeId=null;_pmmCurrentUserId=null;}

function goToModalProfile(){if(_pmmCurrentUserId)window.location.href='profile.php?user_id='+_pmmCurrentUserId;}

function pmmAction(type){if(!_pmmMemeId)return;var bId={like:'pmm-like-btn',repost:'pmm-repost-btn',fav:'pmm-fav-btn'};var cId={like:'pmm-like-count',repost:'pmm-repost-count',fav:'pmm-fav-count'};var sk={like:'liked',repost:'reposted',fav:'faved'};fetch('profile.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action='+type+'&meme_id='+_pmmMemeId}).then(function(r){return r.json();}).then(function(d){if(!d.ok)return;var an=d.liked||d.reposted||d.faved||false;var btn=document.getElementById(bId[type]);var cnt=document.getElementById(cId[type]);if(btn){btn.style.color=an?'#ff4c4c':'white';btn.style.borderColor=an?'rgba(255,76,76,0.4)':'rgba(255,255,255,0.1)';}if(cnt)cnt.textContent=d.count;var fi=_pmmFeed.find(function(s){return s.type==='post'&&String(s.data.id)===String(_pmmMemeId);});if(fi){var cf={like:'likeCount',repost:'repostCount',fav:'favCount'};fi.data[cf[type]]=d.count;fi.data[sk[type]]=an;}});}
function pmmToggleSugg(btn){var uid=btn.dataset.user;fetch('profile.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=toggle_follow&user_id='+uid}).then(function(r){return r.json();}).then(function(d){if(d.status==='ok'){if(d.following){btn.textContent='Untrack';btn.classList.add('tracking');}else{btn.textContent='Track';btn.classList.remove('tracking');}}});}

window.addEventListener('load',function(){_loadPmmData();});

document.getElementById('memeModal').addEventListener('wheel',function(e){
    e.preventDefault();
    if(e.deltaY>0) _profileFeedNav(1); else _profileFeedNav(-1);
},{passive:false});

var _pty=0;
document.getElementById('memeModal').addEventListener('touchstart',function(e){_pty=e.touches[0].clientY;},{passive:true});
document.getElementById('memeModal').addEventListener('touchmove',function(e){e.stopPropagation();e.preventDefault();},{passive:false});
document.getElementById('memeModal').addEventListener('touchend',function(e){
    var d=_pty-e.changedTouches[0].clientY;
    if(Math.abs(d)>40){
        if(d>0) _profileFeedNav(1); else _profileFeedNav(-1);
    }
},{passive:true});

document.addEventListener('keydown',function(e){
    if(document.getElementById('memeModal').style.display==='flex'){
        if(e.key==='Escape')closeMemeModal();
        if(e.key==='ArrowDown') _profileFeedNav(1);
        if(e.key==='ArrowUp')   _profileFeedNav(-1);
    }
});
</script>

<!-- ══════════ REPOSTS ══════════ -->
<div id="reposts" class="dropdown-section" style="display:none;">
    <div class="grid-sort-bar" id="repostsSortBar">
        <button class="g-pill active" id="repostsN" onclick="gSort('repostsGrid','newest','reposts')"><i class="ri-time-line" style="margin-right:5px;font-size:11px;"></i>Newest</button>
        <button class="g-pill" id="repostsO" onclick="gSort('repostsGrid','oldest','reposts')"><i class="ri-history-line" style="margin-right:5px;font-size:11px;"></i>Oldest</button>
        <div class="g-pill-div"></div>
        <button class="g-pill" id="repostsC" onclick="gOpenCol('reposts')"><i class="ri-folder-3-line" style="margin-right:5px;font-size:11px;"></i>Collections<span class="g-pill-count"><?= $col_count_total > 0 ? dezzFmt($col_count_total) : '' ?></span></button>
    </div>
    <div class="grid" id="repostsGrid">
        <div class="dezz-grid-loader" data-target="repostsGrid"><div class="dezz-dashed-ring"></div></div>
    <?php if(!$reposts||$reposts->num_rows===0): ?>
        <div style="grid-column:1/-1;text-align:center;padding:40px 20px;color:#aaa;font-size:0.95rem;"><i class="ri-repeat-line" style="font-size:2.5rem;display:block;margin-bottom:10px;color:#FF4C4C;"></i>No reposts yet</div>
    <?php else: ?>
    <script>var repostFeed=[];</script>
    <?php
    $all_repost_rows = [];
    while($row=$reposts->fetch_assoc()) $all_repost_rows[] = $row;
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
        $repost_time_row=$conn->query("SELECT created_at FROM reposts WHERE meme_id=".(int)$row['id']." AND user_id=$profile_id ORDER BY created_at DESC LIMIT 1")->fetch_assoc();
        $interacted_at=$repost_time_row?strtotime($repost_time_row['created_at']):strtotime($row['created_at']??'now');
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
        $rlc=$conn->query("SELECT COUNT(*) as c FROM likes WHERE meme_id=$mid_r")->fetch_assoc()['c'];
        $rrc=$conn->query("SELECT COUNT(*) as c FROM reposts WHERE meme_id=$mid_r")->fetch_assoc()['c'];
        $rfc=$conn->query("SELECT COUNT(*) as c FROM favorites WHERE meme_id=$mid_r")->fetch_assoc()['c'];
        $rli=$conn->query("SELECT id FROM likes WHERE meme_id=$mid_r AND user_id=$current_user")->num_rows>0;
        $rri=$conn->query("SELECT id FROM reposts WHERE meme_id=$mid_r AND user_id=$current_user")->num_rows>0;
        $rfi=$conn->query("SELECT id FROM favorites WHERE meme_id=$mid_r AND user_id=$current_user")->num_rows>0;
    ?>
    <script>repostFeed.push({id:<?=$mid_r?>,file:'<?=$smf?>',type:'<?=addslashes($row['type'])?>',pic:'<?=$sp?>',userId:<?=(int)$row['uploaded_by']?>,username:'<?=$su?>',caption:'<?=$sc?>',time:'<?=$st?>',isYou:<?=$iy?>,likeCount:<?=(int)$rlc?>,repostCount:<?=(int)$rrc?>,favCount:<?=(int)$rfc?>,liked:<?=$rli?'true':'false'?>,reposted:<?=$rri?'true':'false'?>,faved:<?=$rfi?'true':'false'?>,isPinned:<?=$isPinned_r?'true':'false'?>});</script>
    <div class="meme-card <?=$is_new?'is-new':''?> <?=$is_fading?'is-fading':''?>" data-id="<?=$mid_r?>" data-ts="<?=(int)$interacted_at?>" data-pinned="<?=$isPinned_r?'1':'0'?>">
        <div class="top-bar">
            <img src="<?=$row['creator_pic']?:'uploads/default.jpg'?>" onclick="window.location.href='profile.php?user_id=<?=$row['uploaded_by']?>'">
            <div class="username" onclick="window.location.href='profile.php?user_id=<?=$row['uploaded_by']?>'">
                <?=($current_user==$row['uploaded_by'])?'You':htmlspecialchars($row['creator'])?>
            </div>
        </div>
        <div class="media-box" style="position:relative;">
            <?php if($row['type']=="image"): ?><img src="uploads/<?=$sf?>" onclick="openMemeModal('<?=$smf?>','<?=$row['type']?>','<?=$sc?>','<?=$sp?>','<?=$su?>',<?=$row['uploaded_by']?>,'repostFeed',<?=$mid_r?>)">
            <?php elseif($row['type']=="video"): ?><video src="uploads/<?=$sf?>" autoplay muted loop onclick="openMemeModal('<?=$smf?>','<?=$row['type']?>','<?=$sc?>','<?=$sp?>','<?=$su?>',<?=$row['uploaded_by']?>,'repostFeed',<?=$mid_r?>)"></video>
            <?php else: ?><img src="uploads/Text.jpg" onclick="openMemeModal('uploads/Text.jpg','image','<?=$sc?>','<?=$sp?>','<?=$su?>',<?=$row['uploaded_by']?>,'repostFeed',<?=$mid_r?>)">
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
        <div class="dezz-grid-loader" data-target="tagsGrid"><div class="dezz-dashed-ring"></div></div>
    <?php if(!$tagged_posts||$tagged_posts->num_rows===0): ?>
        <div style="grid-column:1/-1;text-align:center;padding:40px 20px;color:#aaa;font-size:0.95rem;"><i class="ri-account-pin-box-line" style="font-size:2.5rem;display:block;margin-bottom:10px;color:#FF4C4C;"></i>No tagged posts yet</div>
    <?php else: ?>
    <script>var tagsFeed=[];</script>
    <?php
    $all_tags_rows=[];
    while($row=$tagged_posts->fetch_assoc()) $all_tags_rows[]=$row;
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
        $tlc=$conn->query("SELECT COUNT(*) as c FROM likes WHERE meme_id=$mid_t")->fetch_assoc()['c'];
        $trc=$conn->query("SELECT COUNT(*) as c FROM reposts WHERE meme_id=$mid_t")->fetch_assoc()['c'];
        $tfc=$conn->query("SELECT COUNT(*) as c FROM favorites WHERE meme_id=$mid_t")->fetch_assoc()['c'];
        $tli=$conn->query("SELECT id FROM likes WHERE meme_id=$mid_t AND user_id=$current_user")->num_rows>0;
        $tri=$conn->query("SELECT id FROM reposts WHERE meme_id=$mid_t AND user_id=$current_user")->num_rows>0;
        $tfi=$conn->query("SELECT id FROM favorites WHERE meme_id=$mid_t AND user_id=$current_user")->num_rows>0;
    ?>
    <script>tagsFeed.push({id:<?=$mid_t?>,file:'<?=$smf?>',type:'<?=addslashes($row['type'])?>',pic:'<?=$sp?>',userId:<?=(int)$row['uploaded_by']?>,username:'<?=$su?>',caption:'<?=$sc?>',time:'<?=$st?>',isYou:<?=$iy?>,likeCount:<?=(int)$tlc?>,repostCount:<?=(int)$trc?>,favCount:<?=(int)$tfc?>,liked:<?=$tli?'true':'false'?>,reposted:<?=$tri?'true':'false'?>,faved:<?=$tfi?'true':'false'?>,isPinned:<?=$isPinned_t?'true':'false'?>});</script>
    <div class="meme-card <?=$is_new?'is-new':''?> <?=$is_fading?'is-fading':''?>" data-id="<?=$mid_t?>" data-ts="<?=(int)$created?>" data-pinned="<?=$isPinned_t?'1':'0'?>">
        <div class="top-bar">
            <img src="<?=$row['uploader_pic']?:'uploads/default.jpg'?>" onclick="window.location.href='profile.php?user_id=<?=$row['uploaded_by']?>'">
            <div class="username" onclick="window.location.href='profile.php?user_id=<?=$row['uploaded_by']?>'">
                <?=htmlspecialchars($row['username'])?>
            </div>
        </div>
        <div class="media-box" style="position:relative;">
            <?php if($row['type']=="image"): ?><img src="uploads/<?=$sf?>" onclick="openMemeModal('<?=$smf?>','<?=$row['type']?>','<?=$sc?>','<?=$sp?>','<?=$su?>',<?=$row['uploaded_by']?>,'tagsFeed',<?=$mid_t?>)">
            <?php elseif($row['type']=="video"): ?><video src="uploads/<?=$sf?>" autoplay muted loop onclick="openMemeModal('<?=$smf?>','<?=$row['type']?>','<?=$sc?>','<?=$sp?>','<?=$su?>',<?=$row['uploaded_by']?>,'tagsFeed',<?=$mid_t?>)"></video>
            <?php else: ?><img src="uploads/Text.jpg" onclick="openMemeModal('uploads/Text.jpg','image','<?=$sc?>','<?=$sp?>','<?=$su?>',<?=$row['uploaded_by']?>,'tagsFeed',<?=$mid_t?>)">
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
        <div class="dezz-grid-loader" data-target="favsGrid"><div class="dezz-dashed-ring"></div></div>
    <?php if(!$favorites||$favorites->num_rows===0): ?>
        <div style="grid-column:1/-1;text-align:center;padding:40px 20px;color:#aaa;font-size:0.95rem;"><i class="ri-bookmark-line" style="font-size:2.5rem;display:block;margin-bottom:10px;color:#FF4C4C;"></i>No favorites yet</div>
    <?php else: ?>
    <script>var favsFeed=[];</script>
    <?php
    $all_favs_rows=[];
    while($row=$favorites->fetch_assoc()) $all_favs_rows[]=$row;
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
        $fav_time_row=$conn->query("SELECT created_at FROM favorites WHERE meme_id=$mid_f AND user_id=$profile_id ORDER BY created_at DESC LIMIT 1")->fetch_assoc();
        $interacted_at=$fav_time_row?strtotime($fav_time_row['created_at']):$created;
        $flc=$conn->query("SELECT COUNT(*) as c FROM likes WHERE meme_id=$mid_f")->fetch_assoc()['c'];
        $frc=$conn->query("SELECT COUNT(*) as c FROM reposts WHERE meme_id=$mid_f")->fetch_assoc()['c'];
        $ffc=$conn->query("SELECT COUNT(*) as c FROM favorites WHERE meme_id=$mid_f")->fetch_assoc()['c'];
        $fli=$conn->query("SELECT id FROM likes WHERE meme_id=$mid_f AND user_id=$current_user")->num_rows>0;
        $fri=$conn->query("SELECT id FROM reposts WHERE meme_id=$mid_f AND user_id=$current_user")->num_rows>0;
        $ffi=$conn->query("SELECT id FROM favorites WHERE meme_id=$mid_f AND user_id=$current_user")->num_rows>0;
    ?>
    <script>favsFeed.push({id:<?=$mid_f?>,file:'<?=$smf?>',type:'<?=addslashes($row['type'])?>',pic:'<?=$sp?>',userId:<?=(int)$row['uploaded_by']?>,username:'<?=$su?>',caption:'<?=$sc?>',time:'<?=$st?>',isYou:<?=$iy?>,likeCount:<?=(int)$flc?>,repostCount:<?=(int)$frc?>,favCount:<?=(int)$ffc?>,liked:<?=$fli?'true':'false'?>,reposted:<?=$fri?'true':'false'?>,faved:<?=$ffi?'true':'false'?>,isPinned:<?=$isPinned_f?'true':'false'?>});</script>
    <div class="meme-card <?=$is_new?'is-new':''?> <?=$is_fading?'is-fading':''?>" data-id="<?=$mid_f?>" data-ts="<?=(int)$interacted_at?>" data-pinned="<?=$isPinned_f?'1':'0'?>">
        <div class="top-bar">
            <img src="<?=$row['uploader_pic']?:'uploads/default.jpg'?>" onclick="window.location.href='profile.php?user_id=<?=$row['uploaded_by']?>'">
            <div class="username" onclick="window.location.href='profile.php?user_id=<?=$row['uploaded_by']?>'">
                <?=htmlspecialchars($row['username'])?>
            </div>
        </div>
        <div class="media-box" style="position:relative;">
            <?php if($row['type']=="image"): ?><img src="uploads/<?=$sf?>" onclick="openMemeModal('<?=$smf?>','<?=$row['type']?>','<?=$sc?>','<?=$sp?>','<?=$su?>',<?=$row['uploaded_by']?>,'favsFeed',<?=$mid_f?>)">
            <?php elseif($row['type']=="video"): ?><video src="uploads/<?=$sf?>" autoplay muted loop onclick="openMemeModal('<?=$smf?>','<?=$row['type']?>','<?=$sc?>','<?=$sp?>','<?=$su?>',<?=$row['uploaded_by']?>,'favsFeed',<?=$mid_f?>)"></video>
            <?php else: ?><img src="uploads/Text.jpg" onclick="openMemeModal('uploads/Text.jpg','image','<?=$sc?>','<?=$sp?>','<?=$su?>',<?=$row['uploaded_by']?>,'favsFeed',<?=$mid_f?>)">
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
        <div class="dezz-grid-loader" data-target="likesGrid"><div class="dezz-dashed-ring"></div></div>
    <?php if(!$liked_posts || $liked_posts->num_rows === 0): ?>
        <div style="text-align:center;padding:40px 20px;color:#aaa;font-size:0.95rem;">
            <i class="ri-heart-line" style="font-size:2.5rem;display:block;margin-bottom:10px;color:#FF4C4C;"></i>No liked posts yet
        </div>
    <?php else:
        $like_rows = [];
        while($row = $liked_posts->fetch_assoc()) $like_rows[] = $row;

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
            $like_time_row = $conn->query("SELECT created_at FROM likes WHERE meme_id=".(int)$row['id']." AND user_id=$profile_id ORDER BY created_at DESC LIMIT 1")->fetch_assoc();
            $liked_ts  = $like_time_row ? strtotime($like_time_row['created_at']) : strtotime($row['created_at']);
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
            $llc = $conn->query("SELECT COUNT(*) as c FROM likes     WHERE meme_id=$mid_l")->fetch_assoc()['c'];
            $lrc = $conn->query("SELECT COUNT(*) as c FROM reposts   WHERE meme_id=$mid_l")->fetch_assoc()['c'];
            $lfc = $conn->query("SELECT COUNT(*) as c FROM favorites WHERE meme_id=$mid_l")->fetch_assoc()['c'];
            $lli = $conn->query("SELECT id FROM likes     WHERE meme_id=$mid_l AND user_id=$current_user")->num_rows > 0;
            $lri = $conn->query("SELECT id FROM reposts   WHERE meme_id=$mid_l AND user_id=$current_user")->num_rows > 0;
            $lfi = $conn->query("SELECT id FROM favorites WHERE meme_id=$mid_l AND user_id=$current_user")->num_rows > 0;
        ?>
        <script>likesFeed.push({id:<?=$mid_l?>,file:'<?=$smf?>',type:'<?=addslashes($row['type'])?>',pic:'<?=$sp?>',userId:<?=(int)$row['uploaded_by']?>,username:'<?=$su?>',caption:'<?=$sc?>',time:'<?=$st?>',isYou:<?=$iy?>,likeCount:<?=(int)$llc?>,repostCount:<?=(int)$lrc?>,favCount:<?=(int)$lfc?>,liked:<?=$lli?'true':'false'?>,reposted:<?=$lri?'true':'false'?>,faved:<?=$lfi?'true':'false'?>,isPinned:<?=$isPinned_l?'true':'false'?>});</script>
        <div class="meme-card <?=$is_new?'is-new':''?> <?=$is_fading?'is-fading':''?>" data-id="<?=$mid_l?>" data-ts="<?=(int)$liked_ts?>" data-pinned="<?=$isPinned_l?'1':'0'?>">
            <div class="top-bar">
                <img src="<?=$row['uploader_pic'] ?: 'uploads/default.jpg'?>" onclick="window.location.href='profile.php?user_id=<?=$row['uploaded_by']?>'">
                <div class="username" onclick="window.location.href='profile.php?user_id=<?=$row['uploaded_by']?>'">
                    <?= htmlspecialchars($row['username']) ?>
                </div>
            </div>
            <div class="media-box" style="position:relative;">
                <?php if($row['type'] == "image"): ?>
                    <img src="uploads/<?=$sf?>" onclick="openMemeModal('<?=$smf?>','<?=$row['type']?>','<?=$sc?>','<?=$sp?>','<?=$su?>',<?=$row['uploaded_by']?>,'likesFeed',<?=$mid_l?>)">
                <?php elseif($row['type'] == "video"): ?>
                    <video src="uploads/<?=$sf?>" autoplay muted loop onclick="openMemeModal('<?=$smf?>','<?=$row['type']?>','<?=$sc?>','<?=$sp?>','<?=$su?>',<?=$row['uploaded_by']?>,'likesFeed',<?=$mid_l?>)"></video>
                <?php else: ?>
                    <img src="uploads/Text.jpg" onclick="openMemeModal('uploads/Text.jpg','image','<?=$sc?>','<?=$sp?>','<?=$su?>',<?=$row['uploaded_by']?>,'likesFeed',<?=$mid_l?>)">
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

<!-- MESSAGES SECTION — LEFT BLANK AS REQUESTED, RESTORE YOUR OWN CODE HERE -->
<!-- MESSAGES -->
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

<!-- ══════════ UPLOADS ══════════ -->
<div id="uploads" class="dropdown-section" style="display:none;">
    <div class="grid-sort-bar" id="uploadsSortBar">
        <button class="g-pill active" id="uploadsN" onclick="gSort('uploadsGrid','newest','uploads')"><i class="ri-time-line" style="margin-right:5px;font-size:11px;"></i>Newest</button>
        <button class="g-pill" id="uploadsO" onclick="gSort('uploadsGrid','oldest','uploads')"><i class="ri-history-line" style="margin-right:5px;font-size:11px;"></i>Oldest</button>
        <div class="g-pill-div"></div>
        <button class="g-pill" id="uploadsC" onclick="gOpenCol('uploads')"><i class="ri-folder-3-line" style="margin-right:5px;font-size:11px;"></i>Collections<span class="g-pill-count"><?= $col_count_total > 0 ? dezzFmt($col_count_total) : '' ?></span></button>
    </div>
    <div class="grid" id="uploadsGrid">
        <div class="dezz-grid-loader" data-target="uploadsGrid"><div class="dezz-dashed-ring"></div></div>
    <?php if($my_uploads->num_rows>0):
    $all_upload_rows=[];
    while($u=$my_uploads->fetch_assoc()) $all_upload_rows[]=$u;
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
        $ulc=$conn->query("SELECT COUNT(*) as c FROM likes WHERE meme_id=$mid_u")->fetch_assoc()['c'];
        $urc=$conn->query("SELECT COUNT(*) as c FROM reposts WHERE meme_id=$mid_u")->fetch_assoc()['c'];
        $ufc=$conn->query("SELECT COUNT(*) as c FROM favorites WHERE meme_id=$mid_u")->fetch_assoc()['c'];
        $uli=$conn->query("SELECT id FROM likes WHERE meme_id=$mid_u AND user_id=$current_user")->num_rows>0;
        $uri=$conn->query("SELECT id FROM reposts WHERE meme_id=$mid_u AND user_id=$current_user")->num_rows>0;
        $ufi=$conn->query("SELECT id FROM favorites WHERE meme_id=$mid_u AND user_id=$current_user")->num_rows>0;
    ?>
    <script>uploadsFeed.push({id:<?=$mid_u?>,file:'<?=$smf?>',type:'<?=addslashes($u['type'])?>',pic:'<?=$sp?>',userId:<?=(int)$u['uploaded_by']?>,username:'<?=$su?>',caption:'<?=$sc?>',time:'<?=$st?>',isYou:true,likeCount:<?=(int)$ulc?>,repostCount:<?=(int)$urc?>,favCount:<?=(int)$ufc?>,liked:<?=$uli?'true':'false'?>,reposted:<?=$uri?'true':'false'?>,faved:<?=$ufi?'true':'false'?>,isPinned:<?=$isPinned_u?'true':'false'?>});</script>
    <div class="meme-card <?=$is_new?'is-new':''?> <?=$is_fading?'is-fading':''?>" data-id="<?=$mid_u?>" data-ts="<?=(int)$created?>" data-pinned="<?=$isPinned_u?'1':'0'?>">
        <div class="top-bar">
            <img src="<?=$u['uploader_pic']?:'uploads/default.jpg'?>">
            <div class="username"><?=($current_user==$profile_id)?"You":htmlspecialchars($user['username'])?></div>
        </div>
        <div class="media-box" style="position:relative;">
            <?php if($u['type']=="image"): ?><img src="uploads/<?=$sf?>" onclick="openMemeModal('<?=$smf?>','<?=$u['type']?>','<?=$sc?>','<?=$sp?>','<?=$su?>',<?=$u['uploaded_by']?>,'uploadsFeed',<?=$mid_u?>)">
            <?php elseif($u['type']=="video"): ?><video src="uploads/<?=$sf?>" autoplay muted loop onclick="openMemeModal('<?=$smf?>','<?=$u['type']?>','<?=$sc?>','<?=$sp?>','<?=$su?>',<?=$u['uploaded_by']?>,'uploadsFeed',<?=$mid_u?>)"></video>
            <?php else: ?><img src="uploads/Text.jpg" onclick="openMemeModal('uploads/Text.jpg','image','<?=$sc?>','<?=$sp?>','<?=$su?>',<?=$u['uploaded_by']?>,'uploadsFeed',<?=$mid_u?>)">
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
var _gPinnedColIds = <?= json_encode($pinned_col_ids) ?>;
var _gActiveMemeId=null,_gActiveColId=null,_gActiveColCtx=null,_gColCtx='favs',_gNmMode='create',_gOptsColId=null,_gOptsColName=null,_gOptsColCtx=null,_gOptsColPublic=false,_gOptsColPinned=false;

// ── FIX: normalize ID/number fields coming from PHP/mysqli ──
// mysqli can return numeric columns as strings depending on driver config,
// while values like insert_id() or explicitly (int)-cast PHP arrays come
// through as real numbers. That mismatch broke strict-equality checks
// (Array.indexOf) used for collection pinning — pin_collection succeeded
// on the backend, but the UI never recognized the collection as pinned.
// Normalizing everything to real numbers up front fixes it everywhere.
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
_gPinnedColIds = (_gPinnedColIds || []).map(function(id){ return parseInt(id, 10); });

// Single source of truth for "is this collection pinned" — always
// coerces to int before comparing, so it never matters whether the
// caller passes a string or a number.
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
    // Sort: pinned first (preserve pin order), then by date
    var pinnedCards=cards.filter(function(c){return c.dataset.pinned==='1';});
    var normalCards=cards.filter(function(c){return c.dataset.pinned!=='1';});
    normalCards.sort(function(a,b){var ta=parseInt(a.dataset.ts)||0,tb=parseInt(b.dataset.ts)||0;return order==='newest'?tb-ta:ta-tb;});
    pinnedCards.forEach(function(c){grid.appendChild(c);});
    normalCards.forEach(function(c){grid.appendChild(c);});
    // Re-inject pinned to front
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

    // Sort: pinned collections first, then rest
    // (uses _gIsColPinned so string/number id mismatches can never break this again)
    var pinnedCols=_gCols.filter(function(c){return _gIsColPinned(c.id);});
    var normalCols=_gCols.filter(function(c){return !_gIsColPinned(c.id);});
    // Order pinned by _gPinnedColIds order
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
                    liked:   s.liked   || false,
                    reposted: s.reposted || false,
                    faved:   s.faved   || false
                };
            });

            items.forEach(function (item) {
                var f = item.file_name || '', t = item.type || 'image';
                var cap = item.caption || '', up = item.uploader_pic || 'uploads/default.jpg', un = item.username || '';
                var mf = (t === 'text' || !f || f === '0') ? 'uploads/Text.jpg' : 'uploads/' + f;
                var mediaHtml = '';
                if (t === 'video') mediaHtml = '<video src="uploads/' + _gEsc(f) + '" autoplay muted loop playsinline style="width:100%;height:100%;object-fit:cover;display:block;"></video>';
                else mediaHtml = '<img src="' + _gEsc(mf) + '" onerror="this.src=\'uploads/Text.jpg\'" style="width:100%;height:100%;object-fit:cover;display:block;">';

                var removeBtn = _gIsOwner
                    ? '<button class="card-remove-btn" title="Remove from collection" onclick="event.stopPropagation();gRemoveItem(' + item.id + ',this)"><i class="ri-folder-reduce-line"></i></button>'
                    : '';

                var card = document.createElement('div');
                card.className = 'meme-card';
                card.dataset.caption = (cap||'').toLowerCase();
                card.innerHTML =
                    '<div class="top-bar"><img src="' + _gEsc(up) + '" onerror="this.src=\'uploads/default.jpg\'"><div class="username">' + _gEsc(un) + '</div></div>' +
                    '<div class="media-box" style="position:relative;">' + mediaHtml + removeBtn + '</div>' +
                    '<div class="caption"><i class="ri-folder-3-line" style="color:#FF4C4C;"></i> ' + _gEsc(cap) + '</div>' +
                    '<div class="icon-bar"><a href="download.php?file=' + encodeURIComponent(f) + '" title="Download"><i class="ri-download-line"></i></a></div>';

                card.querySelector('.media-box').addEventListener('click', function (e) {
                    if (e.target.closest('.card-remove-btn')) return;
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

function _gRefreshCountBadge(colId){var col=_gCols.find(function(c){return c.id==colId;});if(!col)return;document.querySelectorAll('[id="gfc-'+colId+'"]').forEach(function(el){el.textContent=_gFmtNum(col.item_count)+' item'+(col.item_count==1?'':'s');});}

function gOpenAddToCol(memeId){
    if(!_gIsOwner){gToast('Log in to add to collections');return;}
    _gActiveMemeId=memeId;
    var list=document.getElementById('atcList');
    list.innerHTML='<div style="padding:20px;text-align:center;color:rgba(255,255,255,0.3);font-size:13px;">Loading...</div>';
    document.getElementById('addToColModal').classList.add('open');
    if(_gCols.length===0){list.innerHTML='<div style="padding:20px;text-align:center;color:rgba(255,255,255,0.35);font-size:13px;">No collections yet.<br>Create one below.</div>';return;}
    list.innerHTML='';
    _gCols.forEach(function(col){
        var item=document.createElement('div');item.className='atc-item';item.dataset.colId=col.id;
        item.innerHTML='<div class="atc-item-thumb"><i class="ri-folder-3-line" style="color:rgba(255,255,255,0.2);font-size:20px;"></i></div><div class="atc-item-info"><div class="atc-item-name">'+_gEsc(col.name)+'</div><div class="atc-item-count" id="atcCnt-'+col.id+'">'+_gFmtNum(col.item_count)+' item'+(col.item_count==1?'':'s')+'</div></div><div class="atc-check" id="atcChk-'+col.id+'">✓</div>';
        item.addEventListener('click',function(){gToggleColItem(col.id,memeId);});
        list.appendChild(item);
    });
    _gCols.forEach(function(col){
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
            _gCols.forEach(function(c){if(c.id==colId)c.item_count=Math.max(0,c.item_count-1);});
            _gRefreshCountBadge(colId);
            var ce=document.getElementById('atcCnt-'+colId);
            if(ce){var col=_gCols.find(function(c){return c.id==colId;});if(col)ce.textContent=_gFmtNum(col.item_count)+' item'+(col.item_count==1?'':'s');}
            gToast('Removed from collection');
        });
    }else{
        _gFetch('col_action=add_to_collection&col_id='+colId+'&meme_id='+memeId,function(d){
            if(d.status==='already'){if(chk)chk.classList.add('on');gToast('Already in this collection');return;}
            if(d.status!=='ok'){gToast('Could not add');return;}
            if(chk)chk.classList.add('on');
            _gCols.forEach(function(c){if(c.id==colId){c.item_count=(d.item_count||c.item_count+1);c.latest_addition=Math.floor(Date.now()/1000);}});
            _gRefreshCountBadge(colId);
            var ce=document.getElementById('atcCnt-'+colId);
            if(ce){var col=_gCols.find(function(c){return c.id==colId;});if(col)ce.textContent=_gFmtNum(col.item_count)+' item'+(col.item_count==1?'':'s');}
            var ltEl=document.getElementById('gflt-'+colId);
            if(ltEl)ltEl.querySelector('span').textContent='Latest: just now';
            gToast('Added to collection ✓');
        });
    }
}

function gOpenOpts(e, colId, colName, ctx, isPublic, isPinned) {
    if(!_gIsOwner)return;
    e.stopPropagation();
    _gOptsColId     = parseInt(colId, 10); // FIX: always store as a real number
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
            _gPinnedColIds=_gPinnedColIds.filter(function(id){return id!=_gOptsColId;});
            if(_gOptsColCtx)gRenderFolders(_gOptsColCtx);
            gToast('Collection deleted');
        });
    } else if(action==='toggle_vis'){
        var newVal = _gOptsColPublic ? 0 : 1;
        _gFetch('col_action=set_visibility&col_id='+_gOptsColId+'&is_public='+newVal,function(d){
            if(d.status!=='ok'){gToast('Could not update');return;}
            _gCols.forEach(function(c){if(c.id==_gOptsColId)c.is_public=newVal;});
            if(_gOptsColCtx)gRenderFolders(_gOptsColCtx);
            gToast(newVal ? 'Collection is now Public 🌐' : 'Collection is now Private 🔒');
        });
    } else if(action==='toggle_pin'){
        // FIX: use the normalized helper instead of a raw strict-equality indexOf,
        // so this correctly detects current pin state regardless of how the id
        // type originated (string from PHP/mysqli vs number from onclick literal).
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
            // FIX: normalize ids whenever a fresh collections list comes back from PHP
            if(d.collections) _gCols=_gNormalizeCols(d.collections);
            else _gCols.unshift(newCol);
            gCloseNameModal();
            if(_gColCtx)gRenderFolders(_gColCtx);
            // Update all collection pill counts
            _gUpdateAllColCounts();
            gToast('Collection "'+d.name+'" created ✓');
        });
    }else{
        _gFetch('col_action=rename_collection&col_id='+_gOptsColId+'&name='+encodeURIComponent(name),function(d){
            if(d.status!=='ok'){gToast('Could not rename');return;}
            _gCols.forEach(function(c){if(c.id==_gOptsColId)c.name=name;});
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