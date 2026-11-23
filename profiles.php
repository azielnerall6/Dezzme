<?php
session_start();
include 'config.php';

// 1. Identify who is logged in
$current_user = $_SESSION['user_id'] ?? 0;

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

// 3. Handle Block / Unblock actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- Block User ---
    if (isset($_POST['block'])) {
        // 1. Insert block record
        $stmt = $conn->prepare("INSERT INTO blocks (blocker_id, blocked_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $current_user, $profile_id);
        $stmt->execute();

        // 2. Remove follow/track if exists
        $stmt2 = $conn->prepare("DELETE FROM follows WHERE follower_id = ? AND following_id = ?");
        $stmt2->bind_param("ii", $current_user, $profile_id);
        $stmt2->execute();

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

// 5. THE QUERIES (Fixed to use $profile_id)

// MEMES (Things this user uploaded)
$memes = $conn->query("
    SELECT m.*, u.username, u.profile_pic AS uploader_pic
    FROM memes m
    JOIN users u ON m.uploaded_by = u.id
    WHERE m.uploaded_by = $profile_id
    ORDER BY m.created_at DESC
");

// REPOSTS (Things this user shared)
$reposts = $conn->query("
    SELECT m.id, m.uploaded_by, m.file_name, m.caption, m.type, u.username as creator, u.profile_pic as creator_pic
    FROM reposts r
    JOIN memes m ON r.meme_id = m.id
    JOIN users u ON m.uploaded_by = u.id
    WHERE r.user_id = $profile_id
    ORDER BY r.id DESC
");

$favorites = $conn->query("
    SELECT m.*, u.username, u.profile_pic as uploader_pic 
    FROM favorites f
    JOIN memes m ON f.meme_id = m.id 
    JOIN users u ON m.uploaded_by = u.id 
    WHERE f.user_id = $profile_id
    ORDER BY f.created_at DESC
");

// MESSAGES (Only if looking at own profile)
$messages = [];
if($current_user == $profile_id){
    $messages = $conn->query("
        SELECT DISTINCT u.id, u.username, u.profile_pic
        FROM messages m
        JOIN users u ON u.id = CASE WHEN m.sender_id=$current_user THEN m.receiver_id ELSE m.sender_id END
        WHERE m.sender_id=$current_user OR m.receiver_id=$current_user
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
        header("Location: profile.php?user_id=$profile_id"); exit();
    }
    if (isset($_POST['unfollow'])) {
        $conn->query("DELETE FROM followers WHERE follower_id=$current_user AND following_id=$profile_id");
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

// Count user memes
$post_count = $memes->num_rows ?? 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $user['username']; ?> | Peura</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<style>

*{margin:0;padding:0;box-sizing:border-box;font-family:'Poppins',sans-serif;}
body{min-height:100vh;color:white;overflow-x:hidden;background:linear-gradient(-45deg,#010218,
    #030521,
    #030415,
    #040237,
    #06051b,
    #00032a,
    #00033D,
    #030414);background-size:500% 500%;animation:gradientShift 20s ease infinite;}
@keyframes gradientShift{0%{background-position:0% 50%;}50%{background-position:100% 50%;}100%{background-position:0% 50%;}}

nav{display:flex;justify-content:space-between;align-items:center;padding:20px 40px;position:sticky;top:0;z-index:100;background:transparent;border-radius:30px;backdrop-filter:blur(12px);box-shadow:0 4px 25px rgba(0,0,0,0.4);}
nav .logo-container{display:flex;align-items:center;gap:15px;}
nav .logo-container img{width:60px;height:60px;border-radius:20px;object-fit:cover;transition:0.4s;cursor:pointer;filter:drop-shadow(0 10px 30px rgba(0,0,0,0.5));}
nav .logo-container img:hover{transform:scale(1.15) rotate(-2deg);box-shadow:0 0 40px rgba(255,255,255,0.5);}
nav .logo-container .title{font-size:32px;font-weight:600;color:white;letter-spacing:5px;text-shadow:0 5px 15px rgba(0,0,0,0.3);}
.nav-links {
    display: flex;
    align-items: center;
    gap: clamp(40px, 2vw, 32px);
}
.nav-links a {display: flex;width: 50px;height: 30px;align-items: center;justify-content: center;gap: 6px;padding: 10px 18px;border-radius: 18px;text-decoration: none;font-weight: 600;font-size: 0.9rem;color: white;background: rgba(255,255,255,0.08);backdrop-filter: blur(12px);-webkit-backdrop-filter: blur(12px);border: 1px solid rgba(255,255,255,0.18);box-shadow:0 5px 15px rgba(0,0,0,0.3),inset 0 1px 0 rgba(255,255,255,0.2);transition: all 0.3s ease;position: relative;overflow: hidden;}
.nav-links a:hover {transform: translateY(-3px) scale(1.05);background: rgba(255,255,255,0.15);box-shadow:0 10px 25px rgba(255,255,255,0.2),0 0 15px rgba(255,255,255,0.1);}

.container{max-width:1000px;margin:auto;padding:30px;}
.profile-header{display:flex;align-items:center;margin-bottom:20px;gap:20px;}
/* 👤 PROFILE IMAGE */
.profile-header img {
    width: 100px;
    height: 100px;
    cursor: pointer;
    border-radius: 20px;
    border: 3px solid #FF4C4C;
    object-fit: cover;

    /* Depth + glow */
    box-shadow:
        0 10px 30px rgba(0,0,0,0.5),
        0 0 15px rgba(255,76,76,0.3);

    transition: all 0.4s ease;
    position: relative;
}

/* ✨ Hover effect */
.profile-header img:hover {
    transform: scale(1.08) rotate(1deg);
    box-shadow:
        0 6px 28px rgba(240, 29, 6, 0.8),
        0 0 20px rgba(255,255,255,0.2) inset;
    border-color: rgb(243, 74, 13); /* brighter border on hover */
}

/* 🧠 USERNAME */
.profile-header h2 {
    font-size: 28px;
    color: rgba(255,255,255,0.95);
    font-weight: 600;
    letter-spacing: 0.6px;

    display: flex;
    align-items: center;
    gap: 8px;

    transition: 0.3s ease;
}

/* Subtle hover sync with image */
.profile-header:hover h2 {
    color: #ffffff;
    text-shadow: 0 0 10px rgba(255,255,255,0.25);
}
/* 🌟 TABS BUBBLE STYLE */
.tabs {
    display: flex;
    gap: 15px; /* bigger gap for breathing space */
    margin-bottom: 20px;
    flex-wrap: wrap;
}

/* Individual tab buttons */
.tabs button {
    background: rgba(255,255,255,0.15);
    border: none;
    color: white;
    padding: 10px 22px;
    border-radius: 25px;
    cursor: pointer;
    transition: all 0.3s ease;
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    font-weight: 600;
    position: relative;
    overflow: hidden;
}

/* Hover effect */
.tabs button:hover {
    background: #FF4C4C;
    transform: translateY(-2px) scale(1.05);
    box-shadow:
        0 10px 25px rgba(255,76,76,0.3),
        0 0 15px rgba(255,255,255,0.1);
}

/* Click effect */
.tabs button:active {
    transform: scale(0.95);
    box-shadow: inset 0 4px 10px rgba(0,0,0,0.4);
}

/* Active tab state */
.tabs button.active {
    background: #FF4C4C;
    box-shadow:
        0 8px 20px rgba(255,76,76,0.5),
        0 0 10px rgba(255,76,76,0.3);
}

/* Optional icon animation if tabs have icons */
.tabs button i {
    transition: transform 0.3s ease;
}

.tabs button:hover i {
    transform: scale(1.2) rotate(5deg);
}

/* Shine sweep effect */
.tabs button::before {
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

.tabs button:hover::before {
    left: 120%;
}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:15px;}
/* =========================
   MEME CARD (ENHANCED)
   ========================= */
.meme-card {
    background: rgba(255,255,255,0.05);
    backdrop-filter: blur(10px);
    border-radius: 20px;
    overflow: hidden;
    position: relative;

    transition: transform 0.5s cubic-bezier(0.68,-0.55,0.27,1.55), 
                opacity 0.5s cubic-bezier(0.68,-0.55,0.27,1.55),
                box-shadow 0.3s;

    opacity: 1;
    transform: translateY(0) scale(1);
}

/* hover stays clean */
.meme-card:hover {
    transform: translateY(-3px) scale(1.02);
    box-shadow: 0 0 25px rgba(255,255,255,0.25);
}

/* =========================
   MEDIA
   ========================= */
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

/* =========================
   CAPTION
   ========================= */
.caption {
    background: transparent;
    padding: 10px;
    font-size: 12.5px;
    line-height: 1.4;
}

/* =========================
   🔥 NEW BADGE (INLINE / GLASS STYLE)
   ========================= */
/* =========================
   NEW BADGE (MEDIA OVERLAY GLASS)
   ========================= */
.new-badge{
    position:absolute;
    bottom:10px;
    left:10px;

    padding:4px 9px;
    border-radius:8px;

    font-size:9px;
    font-weight:700;
    letter-spacing:0.8px;

    color:rgba(255,255,255,0.92);

    /* cleaner glass */
    background:rgba(255, 76, 94, 0.14);
    border:1px solid rgba(255, 76, 94, 0.28);

    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);

    z-index:10;

    /* subtle depth */
    box-shadow: 0 4px 12px rgba(0,0,0,0.18);

    /* smooth transitions */
    transition: opacity 0.6s ease, transform 0.6s ease, background 0.6s ease;
}

/* optional glow layer */
.new-badge::after{
    content:"";
    position:absolute;
    inset:0;
    border-radius:inherit;

    box-shadow: 0 0 10px rgba(255, 76, 94, 0.25);

    opacity:0.6;
    pointer-events:none;
}

/* fade after time (visual only) */
.new-badge.fade{
    opacity:0.35;
    transform: scale(0.95);
}

@keyframes newPulse{
    0%{ opacity:0.3; transform:scale(1); }
    50%{ opacity:0.9; transform:scale(1.08); }
    100%{ opacity:0.3; transform:scale(1); }
}

/* =========================
   OPTIONAL: NEW CARD GLOW
   (only if you add .is-new class)
   ========================= */
.meme-card.is-new{
    box-shadow: 0 0 18px rgba(242, 227, 229, 0.26);
}

/* hover intensifies if new */
.meme-card.is-new:hover{
    box-shadow: 0 0 30px rgba(249, 238, 240, 0.46);
}

.media-box{
    position: relative;
    width: 100%;
}

.media-box img,
.media-box video{
    width: 100%;
    height: 180px;
    object-fit: cover;
    display: block;
}

.top-bar {
    position: absolute;
    top: 10px;
    left: 10px;
    display: flex;
    align-items: center;
    gap: 8px;
    z-index: 10; /* above video */
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

.icon-bar {
    position: absolute;
    top: 10px;
    right: 10px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    z-index: 10; /* above video */
}

.icon-bar button,
.icon-bar a {
    width: 36px;     /* square size */
    height: 36px;    /* square size */
    background: rgba(25, 11, 228, 0.15);
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
/* 🌟 FOLLOW & MESSAGE BUTTONS - GLASSY SILVER STYLE */
.follow-btn,
.message-btn {
    background: rgba(255, 255, 255, 0.1); /* soft transparent glass */
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.3);
    color: white;
    font-weight: 500;
    padding: 8px 22px;
    border-radius: 25px;
    cursor: pointer;
    transition: all 0.35s ease;
    position: relative;
    overflow: hidden;
}

/* Hover: silver sheen with subtle lift */
.follow-btn:hover,
.message-btn:hover {
    background: rgba(255, 255, 255, 0.25); /* brighter glass */
    transform: translateY(-2px) scale(1.05);
    border-color: rgba(255, 255, 255, 0.5);
}

/* Optional shine sweep effect */
.follow-btn::before,
.message-btn::before {
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
.follow-btn:hover::before,
.message-btn:hover::before {
    left: 120%;
}

/* 🌟 MESSAGES CONTAINER */
#messages {
    display: none;
    flex-direction: column;
    gap: 12px;
    padding: 10px 0;
}

/* 🌟 INDIVIDUAL MESSAGE USER CARD */
.message-user {
    display: flex;
    align-items: center;
    gap: 12px;
    background: rgba(255, 255, 255, 0.08); /* subtle glass */
    padding: 10px 15px;
    border-radius: 20px;
    transition: transform 0.3s, box-shadow 0.3s, background 0.3s;
    cursor: pointer;
}

/* Hover: glassy lift, no dark glow */
.message-user:hover {
    transform: translateY(-2px) scale(1.02);
    background: rgba(255, 255, 255, 0.18); /* brighter glass */
    box-shadow: 0 0 12px rgba(255,255,255,0.15); /* subtle silver-ish halo */
}

/* Profile image inside message card */
.message-user img {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    object-fit: cover;
    cursor: pointer;
    border: 3px solid rgba(223, 20, 20, 0.96); /* soft silver border */
    transition: transform 0.3s, box-shadow 0.3s, border-color 0.3s;
}

/* Hover: silver shine on image */
.message-user img:hover {
    box-shadow: 0 0 12px rgb(243, 14, 14), 0 0 20px rgba(255,255,255,0.2);
    transform: scale(1.1);
    border-color: rgba(255, 36, 36, 0.92);
}

/* Username / link in message card */
.message-user a {
    color: #ffffff;
    font-weight: 600;
    text-decoration: none;
    font-size: 16px;
    cursor: pointer;
    transition: color 0.3s, text-shadow 0.3s;
}

/* Hover: subtle silver glow */
.message-user a:hover {
    color: white;
    text-shadow: 0 0 6px rgb(255, 255, 255);
}
#profile-upload input[type="file"]{margin-bottom:10px;border-radius:12px;padding:5px;border:none;background:rgba(255,255,255,0.1);color:white;}
#profile-msg{color:#FF4C4C;margin-top:5px;}
.bottom-logo{position:fixed;bottom:25px;right:25px;width:60px;border-radius:20px;filter:drop-shadow(0 10px 30px rgba(0,0,0,0.5));transition:.4s;animation:floatLogo 5s ease-in-out infinite;}
@keyframes floatLogo{0%{transform:translateY(0);}50%{transform:translateY(-15px);}100%{transform:translateY(0);}}
canvas{position:fixed;top:0;left:0;z-index:-1;width:100%;height:100%;}
.action-btn{background:rgba(255,255,255,0.1);backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,0.3);color:white;padding:8px 20px;border-radius:25px;cursor:pointer;transition:.3s;}
.action-btn:hover{background:#FF4C4C;}
.profile-header img {
    width:100px;
    height:100px;
    border-radius:20px;
    border:linear-gradient(45deg, var(--peura-red), var(--neon-purple));
    object-fit:cover;
    filter:drop-shadow(0 10px 25px rgba(0,0,0,0.5));
    transition:.4s;
}

.profile-header img:hover {
    transform:scale(1.1);
}

.profile-header h2 {
    font-size:28px;
    color:white;
    margin-bottom:5px;
}

.profile-header p {
    line-height:1.4;
}

.action-btn {
    background: rgba(255,255,255,0.1);
    backdrop-filter: blur(10px);
    border:1px solid rgba(255,255,255,0.3);
    color:white;
    padding:6px 20px;
    border-radius:25px;
    cursor:pointer;
    transition:.3s;
    font-size:14px;
}
.action-btn:hover {
    background:#FF4C4C;
}

/* 🌟 GROUP BUTTON - GLASS SQUARE STYLE */
.group-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    width: 90px;           /* square width */
    height: 40px;          /* square height */
    background: rgba(255, 255, 255, 0.08); /* subtle glass */
    color: white;
    font-weight: 500;
    border-radius: 15px;   /* rounded corners */
    text-decoration: none;
    font-size: 16px;
    cursor: pointer;
    position: relative;
    overflow: hidden;
    box-shadow:
        0 5px 15px rgba(0,0,0,0.25),
        inset 0 1px 0 rgba(255,255,255,0.2);
    transition: all 0.35s ease;
}

/* Icon sizing inside */
.group-btn i {
    font-size: 20px;
    transition: transform 0.3s ease;
}

/* Hover effect - silver glass with glow */
.group-btn:hover {
    background: rgba(0, 0, 0, 0.59); /* silver glass look */
    color: #ff1111; /* optional contrast on hover */
    transform: scale(1.08) translateY(-2px);
    box-shadow:
        0 10px 25px rgba(255,255,255,0.5),
        0 0 15px rgba(200,200,200,0.3);
}

/* Click effect */
.group-btn:active {
    transform: scale(0.95);
    box-shadow: inset 0 4px 12px rgba(0,0,0,0.4);
}

/* Optional shine sweep for polish */
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

/* Other Accounts links */
.other-account-link {
    color: #c0bfc6;
    text-decoration: underline;
    font-size: 14px;
    transition: color 0.2s, text-decoration 0.2s;
}

.other-account-link:hover {
    color: #ea1e1eff;      /* change color on hover */
    text-decoration: none; /* remove underline on hover */
    cursor: pointer;
}

.track-btn {
    display: inline-block;
    background: rgba(255,255,255,0.1);
    padding: 6px 16px;
    border-radius: 20px;
    color: #fff;
    text-decoration: none;
    font-weight: 500;
    margin-right: 8px;
    transition: all 0.2s ease;
}
.track-btn:hover {
    background: rgba(255,76,76,0.4);
    transform: translateY(-2px);
}

.blur-count {
    filter: blur(6px);
    user-select: none;
    transition: 0.3s;
}

/* Optional: tease effect on hover */
.blur-count:hover {
    filter: blur(4px);
}

/* 🌟 BLOCK / UNBLOCK BUTTON - GLASS SQUARE STYLE */
.block-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    width: 105px;           /* square width */
    height: 40px;           /* square height */
    background: rgba(255, 255, 255, 0.08); /* subtle glass */
    font-weight: 500;
    border-radius: 15px;    /* rounded corners */
    border:transparent;
    text-decoration: none;
    font-size: 16px;
    cursor: pointer;
    position: relative;
    overflow: hidden;
    box-shadow:
        0 5px 15px rgba(0,0,0,0.25),
        inset 0 1px 0 rgba(255,255,255,0.2);
    transition: all 0.35s ease;
}

/* Icon sizing inside */
.block-btn i {
    font-size: 20px;
    transition: transform 0.3s ease;
}

/* Hover effect - silver glass with glow */
.block-btn:hover {
    background: rgba(230, 230, 230, 0.25); /* silver glass look */
    transform: scale(1.08) translateY(-2px);
    box-shadow:
        0 10px 25px rgba(255,255,255,0.5),
        0 0 15px rgba(200,200,200,0.3);
}

/* Click effect */
.block-btn:active {
    transform: scale(0.95);
    box-shadow: inset 0 4px 12px rgba(0,0,0,0.4);
}

/* Optional shine sweep for polish */
.block-btn::before {
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

.block-btn:hover::before {
    left: 120%;
}

/* Text colors */
.block-btn.block {
    color: white; /* Block button text */
}

.block-btn.unblock {
    color: red;   /* Unblock button text */
}

.view-btn{
    width:45px;
    height:45px;

    display:flex;
    align-items:center;
    justify-content:center;

    border-radius:50%;

    background:rgba(255,255,255,0.08);
    backdrop-filter:blur(12px);

    border:1px solid rgba(255,255,255,0.15);

    color:#fff;
    font-size:20px;

    cursor:pointer;
    transition:all 0.3s ease;

    position:relative;
    overflow:hidden;
    box-shadow:
        0 5px 15px rgba(0,0,0,0.25),
        inset 0 1px 0 rgba(255,255,255,0.2);
    transition: all 0.35s ease;
}

/* glow layer */
.view-btn::before{
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

/* hover */
.view-btn:hover{
   background: rgba(0, 0, 0, 0.68); /* silver glass look */
    color: #ff1111; /* optional contrast on hover */
    transform: scale(1.08) translateY(-2px);
    box-shadow:
        0 10px 25px rgba(255,255,255,0.5),
        0 0 15px rgba(200,200,200,0.3);
}

.view-btn:hover::before{
    left: 120%;
}

/* click */
.view-btn:active{
    transform: scale(0.95);
    box-shadow: inset 0 4px 12px rgba(0,0,0,0.4);
}

/* ================= NOTIFICATION DOT ================= */
.notification-dot {
    position: absolute;            /* absolutely positioned relative to button */
    top: 9px;
    right: 8px;
    width: 6.2px;
    height: 6.2px;
    background: #f11515;          /* notification blue */
    border-radius: 50%;
    border: 2px solid rgba(255,255,255,0.2);  /* subtle border for contrast */
    box-shadow: 0 0 6px rgba(0,0,0,0.3);
    animation: pulseDot 1.5s infinite;
}

/* Pulse animation for dot */
@keyframes pulseDot {
    0% { transform: scale(0.9); opacity: 0.8; }
    50% { transform: scale(1.2); opacity: 1; }
    100% { transform: scale(0.9); opacity: 0.8; }
}

/* =================== KEBAB ICON =================== */
.kebab-wrapper{
    position: relative; 
    margin-top:5px; /* for dropdown positioning */
    display: inline-block;
    width:23px;
    cursor: pointer;
}
.kebab-icon{
    font-size:28px;
     width:10px;
    color: #fff;
    padding: 6px;
    border-radius: 30px;
    background:transparent;
    backdrop-filter: blur(12px);
    border:transparent;
    transition: all 0.3s ease;
}

.kebab-icon:hover{
    color: #ff1111; /* optional contrast on hover */
}

/* =================== DROPDOWN =================== */
.kebab-dropdown{
    display: none;
    position: absolute;
    top: 30px;  /* just below the icon */
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

/* Dropdown links */
.kebab-dropdown a{
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 15px;
    color: white;
    text-decoration: none;
    font-size: 14px;
    transition: all 0.25s ease;
}
.kebab-dropdown a i{
    font-size: 18px;
    color: #f21d1d; /* icon color */
}
.kebab-dropdown a:hover{
    background: rgba(255,255,255,0.15);
    color: #ee2626;
}

/* Smooth fade animation */
.kebab-dropdown.show{
    display: flex;
    animation: fadeDown 0.25s ease forwards;
}
@keyframes fadeDown{
    0%{ opacity: 0; transform: translateY(-5px);}
    100%{ opacity: 1; transform: translateY(0);}
}

.post-count-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    width: 50px;
    height: 50px;
    background: rgba(255,255,255,0.08);
    backdrop-filter: blur(12px);
    border: 1px solid rgba(255, 255, 255, 0.09);
    border-radius: 12px;
    color: white;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 5px 15px rgba(0,0,0,0.25);
    position: relative;
    font-size: 14px;
}

.post-count-btn i {
    font-size: 18px;
    color: #ff4c4c;
}

.post-count-btn:hover {
    background: rgba(0, 0, 0, 0.59); /* silver glass look */
    color: #fff7f7; /* optional contrast on hover */
    transform: scale(1.08) translateY(-2px);
    box-shadow:
        0 10px 25px rgba(255,255,255,0.5),
        0 0 15px rgba(200,200,200,0.3);
}

.profile-name{
    font-size: 14px;
    font-weight: 700;
    margin-top: 8px;
    margin-left: 15px;
    color: #c7baba;
}

.profile-link{
    text-decoration: none;
    color: inherit;
    display: inline-block;
}

.profile-link:hover .profile-name{
    color: #f23939;
    text-shadow: 0 0 10px rgba(255, 0, 0, 0.4);
}

.profile-link:hover .profile-username{
    color: #fff;
}

.top-actions{
    position: absolute;
    top: 105px;
    right: 25px;

    display: flex;
    align-items: center;
    gap: 15px;
    z-index: 999;
}

/* ============================================================
   RESPONSIVE — TABLET (≤768px) + PHONE (≤480px) + SMALL (≤360px)
   Desktop styles are untouched. All rules below are mobile/tablet only.
   ============================================================ */

/* ===================== TABLET — 768px ===================== */
@media (max-width: 768px) {

    /* ── NAV ── */
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
        border-radius: 0;
        justify-content: center;
        z-index: 1000;
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

    /* ── CONTAINER ── */
    .container {
        max-width: 100%;
        padding: 20px 16px;
    }

    /* ── PROFILE HEADER ── */
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
        letter-spacing: 0.3px;
    }

    .profile-header p {
        font-size: 13px;
    }

    /* ── ACTION BUTTONS ── */
    .follow-btn,
    .message-btn,
    .action-btn {
        padding: 7px 16px;
        font-size: 13px;
        border-radius: 22px;
    }

    /* ── GROUP / BLOCK / VIEW BTNS ── */
    .group-btn {
        width: 80px;
        height: 36px;
        font-size: 14px;
        border-radius: 12px;
    }

    .block-btn {
        width: 95px;
        height: 36px;
        font-size: 14px;
        border-radius: 12px;
    }

    .view-btn {
        width: 40px;
        height: 40px;
        font-size: 18px;
    }

    /* ── TOP ACTIONS ROW ── */
    .top-actions {
        top: 88px;
        right: 16px;
        gap: 10px;
    }

    /* ── TABS ── */
    .tabs {
        gap: 8px;
        margin-bottom: 14px;
        flex-wrap: nowrap;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none;
        padding-bottom: 4px;
    }

    .tabs::-webkit-scrollbar {
        display: none;
    }

    .tabs button {
        padding: 8px 16px;
        font-size: 13px;
        border-radius: 20px;
        white-space: nowrap;
        flex-shrink: 0;
    }

    /* ── MEME GRID ── */
    .grid {
        grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
        gap: 12px;
    }

    .meme-card img,
    .media-box img {
        height: 150px;
    }

    .meme-card video,
    .media-box video {
        height: 180px;
    }

    /* ── CARD INTERNALS ── */
    .caption {
        font-size: 11.5px;
        padding: 8px;
    }

    .top-bar img {
        width: 28px;
        height: 28px;
    }

    .top-bar .username {
        font-size: 12px;
    }

    .icon-bar button,
    .icon-bar a {
        width: 32px;
        height: 32px;
        font-size: 16px;
        border-radius: 10px;
    }

    .new-badge {
        font-size: 8px;
        padding: 3px 7px;
        border-radius: 7px;
    }

    /* ── POST COUNT BTN ── */
    .post-count-btn {
        width: 44px;
        height: 44px;
        font-size: 13px;
        border-radius: 10px;
    }

    .post-count-btn i {
        font-size: 16px;
    }

    /* ── PROFILE NAME ── */
    .profile-name {
        font-size: 13px;
        margin-left: 12px;
        margin-top: 6px;
    }

    /* ── MESSAGES SECTION ── */
    #messages {
        gap: 10px;
    }

    .message-user {
        padding: 9px 12px;
        border-radius: 16px;
        gap: 10px;
    }

    .message-user img {
        width: 44px;
        height: 44px;
    }

    .message-user a {
        font-size: 14px;
    }

    /* ── KEBAB ── */
    .kebab-icon {
        font-size: 24px;
    }

    .kebab-dropdown {
        min-width: 155px;
        border-radius: 13px;
    }

    .kebab-dropdown a {
        font-size: 13px;
        padding: 9px 13px;
    }

    .kebab-dropdown a i {
        font-size: 16px;
    }

    /* ── BOTTOM LOGO ── */
    .bottom-logo {
        width: 48px;
        bottom: 18px;
        right: 18px;
    }

    /* ── TRACK BTNS ── */
    .track-btn {
        font-size: 12px;
        padding: 5px 13px;
        border-radius: 16px;
        margin-right: 6px;
    }
}

/* ===================== PHONE — 480px ===================== */
@media (max-width: 480px) {

    /* ── NAV ── */
    nav {
        padding: 0 12px;
        min-height: 52px;
    }

    .nav-links a {
        font-size: 14px;
        height: 42px;
        padding: 0 10px;
    }

    /* ── CONTAINER ── */
    .container {
        padding: 16px 12px;
    }

    /* ── PROFILE HEADER ── */
    .profile-header {
        gap: 12px;
        margin-bottom: 12px;
    }

    .profile-header img {
        width: 70px;
        height: 70px;
        border-radius: 14px;
    }

    .profile-header h2 {
        font-size: 16px;
    }

    .profile-header p {
        font-size: 12px;
        line-height: 1.4;
    }

    /* ── ACTION BUTTONS ── */
    .follow-btn,
    .message-btn,
    .action-btn {
        padding: 6px 13px;
        font-size: 12px;
        border-radius: 20px;
    }

    /* ── GROUP / BLOCK BTNS ── */
    .group-btn {
        width: 72px;
        height: 34px;
        font-size: 13px;
        border-radius: 10px;
    }

    .block-btn {
        width: 86px;
        height: 34px;
        font-size: 13px;
        border-radius: 10px;
    }

    .view-btn {
        width: 36px;
        height: 36px;
        font-size: 17px;
    }

    /* ── TOP ACTIONS ── */
    .top-actions {
        top: 80px;
        right: 12px;
        gap: 8px;
    }

    /* ── TABS ── */
    .tabs {
        gap: 6px;
        margin-bottom: 12px;
    }

    .tabs button {
        padding: 7px 13px;
        font-size: 12px;
        border-radius: 18px;
    }

    /* ── MEME GRID ── */
    .grid {
        grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
        gap: 10px;
    }

    .meme-card img,
    .media-box img {
        height: 130px;
    }

    .meme-card video,
    .media-box video {
        height: 160px;
    }

    /* ── CARD INTERNALS ── */
    .caption {
        font-size: 11px;
        padding: 7px;
    }

    .top-bar {
        gap: 5px;
    }

    .top-bar img {
        width: 24px;
        height: 24px;
    }

    .top-bar .username {
        font-size: 11px;
    }

    .icon-bar {
        gap: 5px;
    }

    .icon-bar button,
    .icon-bar a {
        width: 28px;
        height: 28px;
        font-size: 14px;
        border-radius: 8px;
    }

    .new-badge {
        font-size: 7.5px;
        padding: 2px 6px;
    }

    /* ── POST COUNT BTN ── */
    .post-count-btn {
        width: 40px;
        height: 40px;
        font-size: 12px;
    }

    .post-count-btn i {
        font-size: 15px;
    }

    /* ── PROFILE NAME ── */
    .profile-name {
        font-size: 12px;
        margin-left: 10px;
        margin-top: 5px;
    }

    /* ── MESSAGES ── */
    .message-user {
        padding: 8px 10px;
        border-radius: 14px;
        gap: 9px;
    }

    .message-user img {
        width: 40px;
        height: 40px;
    }

    .message-user a {
        font-size: 13px;
    }

    /* ── KEBAB ── */
    .kebab-icon {
        font-size: 22px;
    }

    .kebab-dropdown {
        min-width: 145px;
        top: 26px;
        left: 20px;
        border-radius: 12px;
    }

    .kebab-dropdown a {
        font-size: 12.5px;
        padding: 8px 12px;
        gap: 8px;
    }

    .kebab-dropdown a i {
        font-size: 15px;
    }

    /* ── BOTTOM LOGO ── */
    .bottom-logo {
        width: 42px;
        bottom: 14px;
        right: 14px;
    }

    /* ── TRACK BTNS ── */
    .track-btn {
        font-size: 11px;
        padding: 5px 11px;
        margin-right: 5px;
    }

    /* ── OTHER ACCOUNT LINKS ── */
    .other-account-link {
        font-size: 12.5px;
    }
}

/* ===================== SMALL PHONE — 360px ===================== */
@media (max-width: 360px) {

    /* ── NAV ── */
    nav {
        padding: 0 10px;
        min-height: 48px;
    }

    nav .nav-toggle {
        width: 32px;
        height: 32px;
        font-size: 18px;
    }

    .nav-links a {
        font-size: 13.5px;
        height: 40px;
        padding: 0 8px;
    }

    /* ── CONTAINER ── */
    .container {
        padding: 14px 10px;
    }

    /* ── PROFILE HEADER ── */
    .profile-header {
        gap: 10px;
    }

    .profile-header img {
        width: 62px;
        height: 62px;
        border-radius: 12px;
    }

    .profile-header h2 {
        font-size: 14.5px;
    }

    .profile-header p {
        font-size: 11.5px;
    }

    /* ── ACTION BUTTONS ── */
    .follow-btn,
    .message-btn,
    .action-btn {
        padding: 5px 11px;
        font-size: 11.5px;
    }

    /* ── GROUP / BLOCK BTNS ── */
    .group-btn {
        width: 64px;
        height: 32px;
        font-size: 12px;
        border-radius: 9px;
    }

    .block-btn {
        width: 78px;
        height: 32px;
        font-size: 12px;
        border-radius: 9px;
    }

    .view-btn {
        width: 34px;
        height: 34px;
        font-size: 16px;
    }

    /* ── TOP ACTIONS ── */
    .top-actions {
        top: 76px;
        right: 10px;
        gap: 7px;
    }

    /* ── TABS ── */
    .tabs {
        gap: 5px;
    }

    .tabs button {
        padding: 6px 11px;
        font-size: 11.5px;
        border-radius: 16px;
    }

    /* ── MEME GRID ── */
    .grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 8px;
    }

    .meme-card img,
    .media-box img {
        height: 115px;
    }

    .meme-card video,
    .media-box video {
        height: 140px;
    }

    /* ── CARD INTERNALS ── */
    .caption {
        font-size: 10.5px;
        padding: 6px;
    }

    .top-bar img {
        width: 22px;
        height: 22px;
    }

    .top-bar .username {
        font-size: 10.5px;
    }

    .icon-bar button,
    .icon-bar a {
        width: 26px;
        height: 26px;
        font-size: 13px;
        border-radius: 7px;
    }

    .new-badge {
        font-size: 7px;
        padding: 2px 5px;
        bottom: 6px;
        left: 6px;
    }

    /* ── POST COUNT BTN ── */
    .post-count-btn {
        width: 36px;
        height: 36px;
        font-size: 11px;
    }

    .post-count-btn i {
        font-size: 14px;
    }

    /* ── PROFILE NAME ── */
    .profile-name {
        font-size: 11.5px;
        margin-left: 8px;
    }

    /* ── MESSAGES ── */
    .message-user {
        padding: 7px 9px;
        border-radius: 12px;
        gap: 8px;
    }

    .message-user img {
        width: 36px;
        height: 36px;
    }

    .message-user a {
        font-size: 12.5px;
    }

    /* ── KEBAB ── */
    .kebab-dropdown {
        min-width: 135px;
        top: 24px;
        left: 16px;
    }

    .kebab-dropdown a {
        font-size: 12px;
        padding: 7px 10px;
    }

    .kebab-dropdown a i {
        font-size: 14px;
    }

    /* ── BOTTOM LOGO ── */
    .bottom-logo {
        width: 36px;
        bottom: 12px;
        right: 12px;
    }

    /* ── TRACK BTNS ── */
    .track-btn {
        font-size: 10.5px;
        padding: 4px 10px;
    }

    .other-account-link {
        font-size: 11.5px;
    }
}

/* ─────────────────────────────
   📱 TABLET + MOBILE BASE RULES
   (clean app-style scaling)
──────────────────────────── */
@media (max-width: 1024px) {

    .follow-btn,
    .message-btn,
    .action-btn,
    .track-btn,
    .group-btn,
    .block-btn,
    .view-btn,
    .post-count-btn {

        transition: all 0.25s ease;
    }
}

/* ─────────────────────────────
   📱 TABLET (≤ 768px)
   compact but POSITION-STABLE
──────────────────────────── */
@media (max-width: 768px) {

    /* KEEP BUTTON ROWS FROM BREAKING */
    .follow-btn,
    .message-btn,
    .action-btn,
    .track-btn,
    .block-btn,
    .group-btn,
    .view-btn,
    .post-count-btn {
        flex-shrink: 0; /* 🔥 prevents movement/shrinking weirdly */
    }

    /* TEXT BUTTONS */
    .follow-btn,
    .message-btn,
    .action-btn,
    .track-btn {
        padding: 7px 14px;
        font-size: 13px;
        border-radius: 18px;
        white-space: nowrap; /* 🔥 prevents line breaking */
    }

    /* BLOCK + GROUP */
    .block-btn,
    .group-btn {
        width: auto;
        height: 36px;
        padding: 6px 14px;
        font-size: 13px;
        border-radius: 14px;
        white-space: nowrap;
    }

    /* ICON BUTTONS */
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

    .track-btn {
        margin-right: 5px;
    }
}

/* ─────────────────────────────
   📱 PHONE (≤ 480px)
   compact but STILL LOCKED POSITION
──────────────────────────── */
@media (max-width: 480px) {

    .follow-btn,
    .message-btn,
    .action-btn,
    .track-btn {
        padding: 6px 12px;
        font-size: 12px;
        border-radius: 16px;
        white-space: nowrap;
        flex-shrink: 0;
    }

    .block-btn,
    .group-btn {
        height: 34px;
        padding: 5px 12px;
        font-size: 12px;
        border-radius: 12px;
        white-space: nowrap;
        flex-shrink: 0;
    }

    .view-btn {
        width: 36px;
        height: 36px;
        font-size: 16px;
        flex-shrink: 0;
    }

    .post-count-btn {
        width: 38px;
        height: 38px;
        font-size: 12px;
        flex-shrink: 0;
    }

    /* DISABLE HOVER SHIFTING ON TOUCH */
    .follow-btn:hover,
    .message-btn:hover,
    .group-btn:hover,
    .block-btn:hover,
    .view-btn:hover {
        transform: none;
    }
}

/* ─────────────────────────────
   📱 VERY SMALL (≤ 360px)
──────────────────────────── */
@media (max-width: 360px) {

    .follow-btn,
    .message-btn,
    .action-btn,
    .track-btn {
        padding: 5px 10px;
        font-size: 11px;
        white-space: nowrap;
    }

    .block-btn,
    .group-btn {
        height: 32px;
        font-size: 11px;
    }

    .view-btn {
        width: 34px;
        height: 34px;
    }

    .post-count-btn {
        width: 36px;
        height: 36px;
    }
}

/* ─────────────────────────────
   📱 PHONE ONLY (ICON MODE)
──────────────────────────── */
@media (max-width: 480px) {

    .group-btn {
        width: 42px;              /* compact icon pill */
        height: 36px;
        padding: 0;

        display: inline-flex;
        align-items: center;
        justify-content: center;

        border-radius: 12px;
        font-size: 0;             /* hides text */
        gap: 0;
    }

    /* keep icon visible */
    .group-btn i {
        font-size: 18px;
        line-height: 1;
    }
}

/* ─────────────────────────────
   VERY SMALL PHONES
──────────────────────────── */
@media (max-width: 360px) {

    .group-btn {
        width: 38px;
        height: 34px;
        border-radius: 10px;
    }

    .group-btn i {
        font-size: 16px;
    }
}

@media (max-width: 768px) {
    .bottom-logo {
        display: none !important;
    }
}

@media (max-width: 480px) {
    .bottom-logo {
        display: none !important;
    }
}

/* ─────────────────────────────
   BASE (DESKTOP)
──────────────────────────── */
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

/* ─────────────────────────────
   📱 TABLET (≤ 768px)
──────────────────────────── */
@media (max-width: 768px) {

    .other-accounts-inline {
        font-size: 12.5px;
        gap: 6px;
    }

    .other-accounts-inline .label {
        font-weight: 600;
        font-size: 12.5px;
    }

    .other-accounts-inline .other-account-link {
        font-size: 12.5px;
    }
}

/* ─────────────────────────────
   📱 MOBILE (≤ 480px)
──────────────────────────── */
@media (max-width: 480px) {

    .other-accounts-inline {
        font-size: 11.5px;
        gap: 5px;
        line-height: 1.4;
    }

    .other-accounts-inline .label {
        font-size: 11.5px;
        font-weight: 600;
    }

    .other-accounts-inline .other-account-link {
        font-size: 11.5px;
    }
}

/* ─────────────────────────────
   VERY SMALL (≤ 360px)
──────────────────────────── */
@media (max-width: 360px) {

    .other-accounts-inline {
        font-size: 11px;
        gap: 4px;
    }
}


/* default: visible on desktop */
.hide-mobile {
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

/* 📱 TABLET + PHONE */
@media (max-width: 768px) {
    .hide-mobile {
        display: none !important;
    }
}

/* ─────────────────────────────
   📱 PHONE ONLY TAB ICON MODE
   RECTANGULAR MINI PILLS
──────────────────────────── */
@media (max-width: 768px) {

    .tabs {
        display: flex;
        flex-wrap: nowrap;
        overflow-x: auto;
      gap: clamp(50px, 18vw, 90px);
        padding: 0 8px 10px;
        scrollbar-width: none;
        -webkit-overflow-scrolling: touch;
        justify-content: flex-start;
        border-bottom: 1px solid rgba(255,255,255,0.07);
        padding: 0 12px
    }

    .tabs::-webkit-scrollbar { display: none; }

    .tabs button {
        background: transparent !important;
        border: none !important;
        box-shadow: none !important;
        backdrop-filter: none !important;

        padding: 6px 0;
        min-width: 0;
        height: auto;
        width: auto;
        flex-shrink: 0;

        border-radius: 0;
        white-space: nowrap;
        font-size: 0 !important;

        color: rgba(255,255,255,0.72); /* visible white */

        display: flex;
        align-items: center;
        justify-content: center;

        position: relative;
        transition: color 0.2s ease;
        -webkit-tap-highlight-color: transparent;
    }

    .tabs button i {
        font-size: clamp(14px, 3.5vw, 17px);
        display: block;
    }

    .tabs button.active {
        color: #ffffff !important;
        background: transparent !important;
    }

    .tabs button.active::after {
        content: "";
        position: absolute;
        bottom: -10px;
        left: 0;
        right: 0;
        height: 2px;
        background: #ff2a2a;
        border-radius: 999px;
    }

    .tabs button:active {
        color: #fff !important;
        transform: scale(0.88);
        opacity: 0.75;
    }

    .tabs button:hover {
        color: #ffffff !important;
        background: transparent !important;
    }

    .tabs .post-count-btn {
        width: clamp(32px, 8vw, 40px);
        height: clamp(32px, 8vw, 40px);
        flex-shrink: 0;
        font-size: clamp(11px, 2.8vw, 13px) !important;
    }

    .tabs .kebab-wrapper {
        display: flex;
        align-items: center;
        flex-shrink: 0;
    }

    .tabs .kebab-icon {
        font-size: clamp(17px, 4vw, 20px);
        color: rgba(255,255,255,0.72);
    }
}

@media (max-width: 480px) {

    .tabs {
        gap: clamp(45px,16vw, 75px);
        padding: 0 6px 10px;
        padding: 0 12px
    }

    .tabs button {
        padding: 5px 0;
    }

    .tabs button i {
        font-size: clamp(14px, 3.5vw, 17px);
    }

    .tabs button.active::after {
        bottom: -10px;
        height: 2px;
    }

    .tabs .post-count-btn {
        width: clamp(30px, 7.5vw, 38px);
        height: clamp(30px, 7.5vw, 38px);
    }

    .tabs .kebab-icon {
        font-size: clamp(17px, 4vw, 20px);
    }
}


@media (max-width: 360px) {

    .tabs {
        gap: clamp(45px,16vw, 75px);
        padding: 0 4px 8px;
        padding: 0 12px
    }

    .tabs button i {
        font-size: 16px;
    }

    .tabs button {
        padding: 5px 0;
    }
}

/* default: visible on desktop */
.hide-mobile {
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

/* 📱 TABLET + PHONE */
@media (max-width: 768px) {
    .hide-mobile {
        display: none !important;
    }
}

/* =========================
   HIDE LOGO ON TABLET + PHONE
========================= */

@media (max-width:768px){

    .logo-container{
        display:none !important;
    }

}

/* Optional tighter protection */
@media (max-width:480px){

    .logo-container{
        display:none !important;
    }

}

@media (max-width:360px){

    .logo-container{
        display:none !important;
    }

}

</style>
</head>
<body>
<?php if($profile_id != $current_user): ?>
<!-- PARTICLES BACKGROUND -->
<canvas id="particles"></canvas>

<script>
// Only run if canvas exists
const canvas = document.getElementById("particles");
if(canvas){
    const ctx = canvas.getContext("2d");
    canvas.width = window.innerWidth;
    canvas.height = window.innerHeight;

    let particles = [];
    for(let i=0;i<100;i++){
        particles.push({
            x: Math.random()*canvas.width,
            y: Math.random()*canvas.height,
            size: Math.random()*2+1,
            speedX: (Math.random()-0.5)*0.5,
            speedY: (Math.random()-0.5)*0.5
        });
    }

    function animate(){
        ctx.clearRect(0,0,canvas.width,canvas.height);
        particles.forEach(p=>{
            p.x += p.speedX;
            p.y += p.speedY;
            if(p.x<0||p.x>canvas.width) p.speedX*=-1;
            if(p.y<0||p.y>canvas.height) p.speedY*=-1;
            ctx.beginPath();
            ctx.arc(p.x,p.y,p.size,0,Math.PI*2);
            ctx.fillStyle="rgba(255,255,255,0.9)"; // brighter white
            ctx.fill();
        });
        requestAnimationFrame(animate);
    }
    animate();

    window.addEventListener('resize',()=>{
        canvas.width = window.innerWidth;
        canvas.height = window.innerHeight;
    });
}
</script>
<?php endif; ?>

<canvas id="particles"></canvas>

<nav>
<div class="logo-container">
<img src="PEURA.jpg" alt="Logo Top" onclick="window.location.href='index.php'">
<div class="title">PEURA<span style="font-size:10px; color: #106ff4" ;>RD</span></div>
</div>
<div class="nav-links">
<a class="glass nav-icon" href="index.php"><i class="ri-home-5-line" style="font-size:15px; color:white;"></i></a>
        <a class="glass nav-icon" href="text_feed.php"><i class="ri-send-plane-line" style="font-size:15px; color:white;"></i></a>
        <a class="glass nav-icon" href="search.php"><i class="ri-search-line" style="font-size:15px; color:white;"></i></a>
        <a class="glass nav-icon" href="upload.php"><i class="ri-add-line" style="font-size:15px; color:white;"></i></a>
        <a class="glass nav-icon" href="profile.php"><i class="ri-user-3-line" style="font-size:15px; color:white;"></i></a>
       <a class="glass nav-icon" href="community.php"><i class="ri-team-line" style="font-size:15px; color:white;"></i></a>
       <a class="glass nav-icon hide-mobile" href="sponsorships.php">
    <i class="ri-megaphone-line"></i>
</a>

<a class="glass nav-icon hide-mobile" href="logout.php">
    <i class="ri-login-box-line"></i>
</a>
</div>
</nav>


<div class="container">

<div class="top-right-tools">
    <?php include 'profile_notifications.php'; ?>

    <?php if($current_user == $profile_id): ?>
<!-- Kebab Dropdown Button (Top Right) -->
<div class="kebab-container">
    <button class="kebab-btn"><i class="ri-equalizer-line"></i></button>
    <div class="kebab-dropdown">
       <a href="audio.php?user_id=<?php echo $profile_id; ?>"><i class="ri-play-list-line"></i> Your Sounds</a>
        <a href="highlights.php?user_id=<?php echo $profile_id; ?>"><i class="ri-star-line"></i> Highlights</a>
        <a href="subs.php?user_id=<?php echo $profile_id; ?>"><i class="ri-article-line"></i> Subscriptions</a>
        <a href="collabs.php?user_id=<?php echo $profile_id; ?>"><i class="ri-group-line" style="color:#ff2626;"></i> Collabs</a>
        <a href="links.php?user_id=<?php echo $profile_id; ?>"><i class="ri-link-m"></i> Links</a>
        <a href="algorithms.php?user_id=<?php echo $profile_id; ?>"><i class="ri-code-s-slash-line" style="color:#ff2626;"></i> Algorithms</a>
        <a href="account.php?user_id=<?php echo $profile_id; ?>"><i class="ri-user-line"></i> About Account</a>
        <a href="#" onclick="shareProfile(<?php echo $profile_id; ?>)"><i class="ri-share-line"></i> Share Profile</a>
         <a href="setting.php"><i class="ri-settings-3-line"></i> Your Settings</a>

    </div>
</div>
</div>

<style>
    .top-right-tools{
    position: absolute;
    top: 105px;
    right: 25px;

    display: flex;
    align-items: center;
    gap: 12px; /* THIS is what makes them sit side by side */
}

/* 🌟 Kebab Button */
.kebab-btn {
    background: transparent;
    border: transparent;
    color: #fff;
    font-size: 24px;
    padding: 6px 12px;
    border-radius: 18px;
    cursor: pointer;
    backdrop-filter: blur(12px);
    transition: all 0.3s ease;
}

.kebab-btn:hover {
    color: #ff2626;
}

/* 🌟 Dropdown Menu */
.kebab-dropdown {
    display: none;
    position: absolute;
    top: 45px;
    left: -150px;
    background: rgba(255,255,255,0.08);
    backdrop-filter: blur(15px);
    border-radius: 12px;
    min-width: 180px;
    flex-direction: column;
    box-shadow: 0 8px 25px rgba(0,0,0,0.35);
}

/.kebab-dropdown a {
    padding: 10px 15px;
    color: white;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    transition: 0.2s;
}
.kebab-dropdown a:hover {
    background: rgba(255,255,255,0.15);
    color: #ff2626;
}
.kebab-dropdown a:last-child {
    border-bottom: none;
}

.kebab-dropdown a:hover i {
    transform: scale(1.2) rotate(10deg);
}

/* 🌟 Show dropdown */
.kebab-dropdown.show {
    display: flex;
    animation: fadeDown 0.25s ease forwards;
}

@keyframes fadeDown {
    0% { opacity: 0; transform: translateY(-5px); }
    100% { opacity: 1; transform: translateY(0); }
}

/* =========================
   📱 TABLET (≤ 768px)
========================= */
@media (max-width: 768px) {

    .top-right-tools {
        top: 25px; /* safe visible position */
        right: 14px;
        gap: 10px;
    }

    .kebab-btn {
        font-size: 22px;
        padding: 6px 12px;
    }

    .kebab-dropdown {
        min-width: 180px;
        top: 42px;
    }

    .kebab-dropdown a {
        font-size: 13.5px;
        padding: 10px 14px;
    }
}


/* =========================
   📱 MOBILE (≤ 480px)
========================= */
@media (max-width: 480px) {

    .top-right-tools {
        top: 14px;        /* even higher for phone */
        right: 10px;
        gap: 8px;
    }

    .kebab-btn {
        font-size: 20px;
        padding: 6px 10px;
        border-radius: 14px;
    }

    .kebab-dropdown {
        min-width: 170px;
        top: 38px;
    }

    .kebab-dropdown a {
        font-size: 12.5px;
        padding: 9px 12px;
    }
}


/* =========================
   📱 VERY SMALL (≤ 360px)
========================= */
@media (max-width: 360px) {

    .top-right-tools {
        top:25px;
        right: 8px;
    }

    .kebab-btn {
        font-size: 18px;
    }

    .kebab-dropdown {
        min-width: 160px;
    }

    .kebab-dropdown a {
        font-size: 12px;
    }
}

</style>

<script>
// Toggle dropdown menu
document.addEventListener('DOMContentLoaded', function() {
    const kebabBtn = document.querySelector('.kebab-btn');
    const kebabDropdown = document.querySelector('.kebab-dropdown');

    if(kebabBtn && kebabDropdown){
        kebabBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            kebabDropdown.classList.toggle('show');
        });

        // Close dropdown if clicked outside
        document.addEventListener('click', () => {
            kebabDropdown.classList.remove('show');
        });
    }
});
</script>
<?php endif; ?>

<div class="profile-header" style="display:flex; align-items:flex-start; gap:12px;">
<div style="display:flex; flex-direction:column; gap:4px; left: 20px;">

<!-- Back button — mobile/tablet only -->
<?php if($current_user != $profile_id): ?>
<button class="profile-back-btn" onclick="history.back()">
    <i class="ri-arrow-left-s-line"></i>
</button>
<?php endif; ?>

<!-- Back button — mobile/tablet only -->
<?php if($current_user != $profile_id): ?>
<a href="search.php" class="profile-search-btn" title="Search">
    <i class="ri-search-line"></i>
</a>
<?php endif; ?>

<!-- New account button — current user only -->
<?php if($current_user == $profile_id): ?>
<div class="profile-top-btns">
    <a href="register.php" class="profile-new-account-btn" title="Create new account">
        <i class="ri-add-line"></i>
    </a>
    <a href="history.php" class="profile-history-btn" title="History">
        <i class="ri-history-line"></i>
    </a>
</div>
<?php endif; ?>

<?php
$profilePic = $user['profile_pic'] ?? '';
if (empty($profilePic)) {
    $profilePic = 'uploads/mee.jpeg';
}
?>

<img src="<?php echo htmlspecialchars($profilePic); ?>"
     alt="Profile Pic"
     id="profilePic"
     style="border:3px solid red; cursor:pointer;"
     onerror="this.src='uploads/mee.jpeg';">

<!-- Modal -->
<div id="picModal" class="modal">
  <span class="close">&times;</span>
  <img class="modal-content" id="modalImg">
</div>
<style>
/* ── Back Button ── */

/* ── Search Button ── */
.profile-search-btn {
    display: none;
}

@media (max-width: 768px) {

    .profile-search-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: transparent;
        color: #fff;
        font-size: 18px;
        width: 28px;
        height: 28px;
        border-radius: 50%;
        text-decoration: none;
        transition: 0.3s;
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        position: absolute;
        right: 10px;
        top: 20px;
    }


    .profile-search-btn:hover {
        background: rgba(255, 0, 0, 0.7);
        box-shadow: 0 4px 15px rgba(255,0,0,0.35);
    }


    .profile-search-btn:active {
        transform: scale(0.93);
    }
}

@media (max-width: 480px) {
    .profile-search-btn {
        font-size: 16px;
        width: 26px;
        height: 26px;
       top: 20px;
    }
} 

/* Wrapper to sit them side by side */
.profile-top-btns {
    display: none;
}

@media (max-width: 768px) {
    .profile-top-btns {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 6px;
    }

    .profile-new-account-btn,
    .profile-history-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 34px;
        height: 34px;
        border-radius: 50%;
        background: transparent;
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        color: #fff;
        font-size: 20px;
        text-decoration: none;
        transition: 0.3s;
        box-shadow: 0 4px 12px rgba(0,0,0,0.25);
    }

    .profile-new-account-btn:hover,
    .profile-history-btn:hover {
        background: rgba(255, 0, 0, 0.7);
        box-shadow: 0 4px 15px rgba(255,0,0,0.35);
    }

    .profile-new-account-btn:active,
    .profile-history-btn:active {
        transform: scale(0.93);
    }
}

@media (max-width: 480px) {
    .profile-new-account-btn,
    .profile-history-btn {
        width: 30px;
        height: 30px;
        font-size: 18px;
    }
}
/* Hidden on desktop by default */
.profile-back-btn {
    display: none;
}

/* Show only on tablet and mobile */
@media (max-width: 768px) {
    .profile-back-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: transparent;
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        border: none;
        color: #fff;
        font-size: 22px;
        width: 34px;
        height: 34px;
        border-radius: 50%;
        cursor: pointer;
        transition: 0.3s;
        box-shadow: 0 4px 12px rgba(0,0,0,0.25);
        margin-bottom: 4px;
    }

    .profile-back-btn:hover {
        background: rgba(255, 0, 0, 0.7);
        box-shadow: 0 4px 15px rgba(255,0,0,0.35);
    }

    .profile-back-btn:active {
        transform: scale(0.93);
    }
}

@media (max-width: 480px) {
    .profile-back-btn {
        font-size: 20px;
        width: 30px;
        height: 30px;
    }
}

/* 🖥️ DESKTOP (bigger + cleaner presence) */
#profilePic {
    width: 120px;
    height: 120px;
    border-radius: 20%;
    object-fit: cover;
    border: 3px solid red;
    cursor: pointer;
}

/* 📱 TABLET */
@media (max-width: 768px) {
    #profilePic {
        width: 105px !important;
        height: 105px !important;
        border-radius: 50%;
        border: 2.5px solid red;
    }
}

/* 📱 PHONE */
@media (max-width: 480px) {
    #profilePic {
        width: 88px !important;
        height: 88px !important;
        border-radius: 50%;
        border: 2px solid red;
    }
}

/* 📱 VERY SMALL PHONES */
@media (max-width: 360px) {
    #profilePic {
        width: 78px !important;
        height: 78px !important;
    }
}
/* The Modal background */
.modal {
  display: none; /* Hidden by default */
  position: fixed;
  z-index: 1000;
  padding-top: 60px;
  left: 0;
  top: 0;
  width: 100%;
  height: 100%;
  overflow: auto;
  background-color: rgba(0,0,0,0.85);
}

/* Modal Image */
.modal-content {
  margin: auto;
  display: block;
  max-width: 900%;
  max-height: 900%;
  border-radius: 20px; /* keep rounded corners */
  border: 3px solid red; /* same border */
  object-fit: cover; /* keep object-fit */
  transition: 0.3s;
}

/* Close button */
.close {
  position: absolute;
  top: 15px;
  right: 35px;
  color: #fff;
  font-size: 30px;
  font-weight: bold;
  cursor: pointer;
  transition: 0.3s;
}
.close:hover {
  color: red;
}

@media (max-width: 768px) {
    .profile-link {
        display: none !important;
    }
}

/* default (desktop) */
.label-full {
    display: inline;
}

.label-short {
    display: none;
}

/* 📱 PHONE */
@media (max-width: 480px) {
    .label-full {
        display: none;
    }

    .label-short {
        display: inline;
    }
}

/* DEFAULT (desktop) */
.label-short {
    display: none;
}

/* 📱 PHONE */
@media (max-width: 480px){

    /* hide full labels */
    .label-full {
        display: none;
    }

    /* show short labels */
    .label-short {
        display: inline;
        font-weight: 600;
        color: #f24455;
    }

    /* 🔥 special styling for pronouns + type values */
    .pronouns-pill,
    .type-pill {
        color: #f24455; /* turns value red */
    }
}

/* default: show everything */
.pronoun-label {
    display: inline;
}

/* 📱 PHONE + TABLET */
@media (max-width: 768px) {
    .pronoun-label {
        display: none;
    }
}

/* default (desktop stays normal) */
.responsive-flex {
    display: flex;
    align-items: center;
    gap: 9px;
    flex-wrap: wrap;
}

/* 📱 PHONE + TABLET behavior */
@media (max-width: 768px) {
    .responsive-flex {
        flex-direction: row;
        gap: 6px;
        justify-content: flex-start;
        align-items: center;
    }
}


@media (max-width: 480px) {
    .view-btn {
        display: none !important;
    }
}

/* default desktop stays as-is */
.bio-text {
    margin: 5px 0 0 0;
    color: #fff;
    max-width: 600px;
    font-size: 13px;
    line-height: 1.4;
    opacity: 0.8;
}

/* 📱 TABLET */
@media (max-width: 768px) {
    .bio-text {
        font-size: 8px;
        max-width: 100%;
        line-height: 1.35;
         opacity: 0.8;
    }
}

@media (max-width: 480px) {
    .bio-text {
        font-size: 7px;
        line-height: 1.2;
        opacity: 0.8;
    }
}

@media (max-width: 360px) {
    .bio-text {
        font-size: 6.5px;     /* lowest usable range */
        line-height: 1.2;
        transform: scale(0.9); /* makes it visually smaller */
        transform-origin: left top;
        opacity: 0.75;
    }
}

/* default (desktop) */
.account-handle {
    font-size: 12.5px;
    color: #adacb3;
    transition: all 0.2s ease;
}

/* 📱 TABLET */
@media (max-width: 768px) {
    .account-handle {
        font-size: 11px;
    }
}

/* 📱 PHONE */
@media (max-width: 480px) {
    .account-handle {
        transform: translateY(-2px);
         margin-top: -2px;
        line-height: 1.2;
        font-size: 11px;  /* increased from 10px */
        color: #c9c9c9;      /* light grey */
    }
}

/* 📱 VERY SMALL */
@media (max-width: 360px) {
    .account-handle {
        transform: translateY(-2px);
         margin-top: -2px;
        line-height: 1.2;
        font-size:11px; /* increased from 9px */
        color: #bdbdbd;      /* slightly darker grey */
    }
}

/* =========================
   TEXT SHRINK RESPONSIVE
   (ONLY WORDS / TEXT)
========================= */

/* ---------- TABLET ---------- */
@media (max-width:768px){

    .pronoun-label,
    .label-full,
    .label-short,
    .type-short{
        font-size:11px !important;
    }

    div[style*="border-radius:20px"]{
        font-size:12px !important;
    }

}

/* ---------- PHONE ---------- */
@media (max-width:480px){

    .pronoun-label,
    .label-full,
    .label-short,
    .type-short{
        font-size:10px !important;
    }

    div[style*="border-radius:20px"]{
        font-size:11px !important;
    }

}

/* ---------- SMALL PHONES ---------- */
@media (max-width:360px){

    .pronoun-label,
    .label-full,
    .label-short,
    .type-short{
        font-size:9px !important;
    }

    div[style*="border-radius:20px"]{
        font-size:10px !important;
    }

}

.status-icon svg {
    width: 12px;
    height: 12px;
}

@media (max-width: 768px) {
    .status-icon svg {
        width: 10px;
        height: 10px;
    }
}

@media (max-width: 480px) {
    .status-icon svg {
        width: 9px;
        height: 9px;
    }
}
</style>

<script>
// Get elements
const modal = document.getElementById("picModal");
const img = document.getElementById("profilePic");
const modalImg = document.getElementById("modalImg");
const closeBtn = document.getElementsByClassName("close")[0];

// Open modal
img.onclick = function(){
  modal.style.display = "block";
  modalImg.src = this.src;
}

// Close modal
closeBtn.onclick = function() {
  modal.style.display = "none";
}

// Close modal on clicking outside the image
modal.onclick = function(e) {
  if(e.target === modal) {
    modal.style.display = "none";
  }
}
</script>
<a href="profile.php?user_id=<?php echo $profile_id; ?>" class="profile-link">
    <div class="profile-name">
        <?php echo htmlspecialchars($display_name); ?>
    </div>
</a>
</div>

  <!-- User Info -->
  <div style="display:flex; flex-direction:column; gap:4.5px;">

    <!-- Username + Followers / Following -->
    <div style="display:flex; align-items:center; gap:9px; flex-wrap:wrap;">
    <h2 style="margin:0;"><span class="status-icon">
    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24">
        <circle cx="12" cy="12" r="12" fill="#FF0000"/>
        <path d="M9 12l2 2 4-4" stroke="white" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
</span> <?php echo $user['username']; ?></h2><div style="display:flex; gap: 5px; font-size:14px; color:#fff;">

<div class="responsive-flex">
    <?php if (!$is_blocked): ?>
<?php
// Check if current user has blocked or is blocked by this profile
$blocked_check = $conn->query("
    SELECT 1 FROM blocks 
    WHERE (blocker_id = $current_user AND blocked_id = $profile_id)
       OR (blocker_id = $profile_id AND blocked_id = $current_user)
")->num_rows > 0;
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
    <span class="<?php echo !$can_view ? 'blur-count' : ''; ?>">
        <?php echo $followers_count; ?>
    </span>
</a>

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
    <span class="<?php echo !$can_view ? 'blur-count' : ''; ?>">
        <?php echo $following_count; ?>
    </span>
</a>
</div>

<!-- Pods -->
<?php
// Reuse this if already defined earlier, otherwise keep it
$blocked_check = $conn->query("
    SELECT 1 FROM blocks 
    WHERE (blocker_id = $current_user AND blocked_id = $profile_id)
       OR (blocker_id = $profile_id AND blocked_id = $current_user)
")->num_rows > 0;
?>

<!-- Pods -->
<a href="<?php echo (!$blocked_check && $can_view) ? 'group_list.php?user_id='.$profile_id : '#'; ?>" 
   class="group-btn"
   <?php if($blocked_check) echo 'onclick="alert(\'You are blocked, you cannot view this.\'); return false;"';
         elseif(!$can_view) echo 'onclick="alert(\'Track this Peumer to view their pods\'); return false;"'; ?>>
    <i class="ri-group-2-line"></i> Pods
</a>
    </div>

   <div class="other-accounts-inline">
    <?php if(!empty($other_accounts)): ?>
        <span class="label">
    <span class="label-full">Other Accounts:</span>
    <span class="label-short">O/A:</span>
</span>
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

                    $display_name = $row['nickname'] ?: $row['username'];

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

                    // ADD @ DISPLAY FIX HERE
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

    </div>

    <!-- Pronouns, Activity Vibe, and User Type -->
<div style="display:flex; align-items:center; gap:15px; flex-wrap:wrap; margin-top:5px;">

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
    ">
        <span class="pronoun-label">Pronouns:</span>  
<?php echo htmlspecialchars($user['pronouns']); ?>
    </div>
<?php endif; ?>

    <!-- Activity Vibe -->
    <?php if(!empty($activity_vibe)): ?>
        <div style="background: rgba(255,255,255,0.1); padding:4px 12px; border-radius:20px; font-size:14px; color:#fff; max-width: 300px;">
            <span class="label-full"style="color:#f24455; font-weight:600;">Activity Hours:</span>
<span class="label-short">A/H:</span>
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
   <?php if($current_user == $profile_id): ?>
    <a href="peeps.php?user_id=<?php echo $profile_id; ?>" class="view-btn">
        <i class="ri-eye-line"></i>
        <?php if($new_views > 0): ?>
            <span class="notification-dot"></span>
        <?php endif; ?>
    </a>
<?php endif; ?>

</div>

    <!-- Bio -->
    <?php if(!empty($user['bio'])): ?>
     <p class="bio-text">
    <?php echo nl2br(htmlspecialchars($user['bio'])); ?>
</p>
    <?php endif; ?>
   <?php endif; ?>
   

    <!-- Follow / Unfollow, Message, Block / Unblock Button   -->
   <?php if($current_user != $profile_id): ?>
<div style="display:flex; gap:10px; margin-top:10px; align-items:center;">

    <!-- Follow / Unfollow -->
    <form method="POST" style="margin:0;">
        <?php if($follow_check): ?>
            <button class="action-btn follow-btn" name="unfollow">UnTrack</button>
        <?php else: ?>
            <button class="action-btn follow-btn" name="follow">Track</button>
        <?php endif; ?>
    </form>

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
<button class="profile-kebab-btn"><i class="ri-more-2-line"></i></button>

<!-- Dropdown -->
<div class="profile-kebab-dropdown">
    <?php if (!$is_blocked): ?>
    <a href="<?php echo (!$blocked_check && $can_view) ? 'audio.php?user_id='.$profile_id : '#'; ?>"
   <?php 
   if($blocked_check){
       echo 'onclick="alert(\'You are blocked, you cannot view this.\'); return false;"';
   } elseif(!$can_view){
       echo 'onclick="alert(\'Track this Peumer to view their playlist.\'); return false;"';
   }
   ?>>
   <i class="ri-play-list-line"></i> User Sounds
</a>

<a href="<?php echo (!$blocked_check && $can_view) ? 'highlights.php?user_id='.$profile_id : '#'; ?>"
   <?php 
   if($blocked_check){
       echo 'onclick="alert(\'You are blocked, you cannot view this.\'); return false;"';
   } elseif(!$can_view){
       echo 'onclick="alert(\'Track this Peumer to view highlights.\'); return false;"';
   }
   ?>>
   <i class="ri-star-line"></i> Highlights
</a>

<a href="<?php echo (!$blocked_check && $can_view) ? 'subs.php?user_id='.$profile_id : '#'; ?>"
   <?php 
   if($blocked_check){
       echo 'onclick="alert(\'You are blocked, you cannot view subscriptions.\'); return false;"';
   } elseif(!$can_view){
       echo 'onclick="alert(\'Track this Peumer to view subscriptions.\'); return false;"';
   }
   ?>>
   <i class="ri-article-line"></i> Subscriptions
</a>

<a href="<?php echo (!$blocked_check && $can_view) ? 'account.php?user_id='.$profile_id : '#'; ?>"
   <?php 
   if($blocked_check){
       echo 'onclick="alert(\'You are blocked, you cannot view this account.\'); return false;"';
   } elseif(!$can_view){
       echo 'onclick="alert(\'Track this Peumer to view their account.\'); return false;"';
   }
   ?>>
   <i class="ri-user-line"></i> About Account
</a>

<a href="<?php echo (!$blocked_check && $can_view) ? 'links.php?user_id='.$profile_id : '#'; ?>"
   <?php 
       if($blocked_check){
           echo 'onclick="alert(\'You are blocked, you cannot view this user\'s links.\'); return false;"';
       } elseif(!$can_view){
           echo 'onclick="alert(\'Track this Peumer to view their links.\'); return false;"';
       }
   ?>>
   <i class="ri-link-m"></i> Links
</a>

<!-- LEAVE THESE UNTOUCHED -->
<a href="#" onclick="shareProfile(<?php echo $profile_id; ?>)">
    <i class="ri-share-line"></i> Share Profile
</a>
    <?php endif; ?>
    <a href="#" onclick="reportUser(<?php echo $profile_id; ?>)"><i class="ri-flag-line"></i> Report</a>
</div>

<style>
/* ─── Button ─── */
.profile-kebab-btn {
     background:transparent;
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

/* ─── Dropdown ─── */
.profile-kebab-dropdown {
   display: none;
    position: absolute;
    width:60px;
    top: 320px;
    left:665px;
    background: rgba(255,255,255,0.08);
    backdrop-filter: blur(15px);
    border-radius: 12px;
    min-width: 180px;
    flex-direction: column;
    z-index: 999;
    box-shadow: 0 8px 25px rgba(0,0,0,0.35);
}
.profile-kebab-dropdown a {
    padding: 10px 15px;
    color: white;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    transition: 0.2s;
}
.profile-kebab-dropdown a:hover {
    background: rgba(255,255,255,0.15);
    color: #ff2626;
}
.profile-kebab-dropdown a:hover i {
    transform: scale(1.2) rotate(10deg);
}
.profile-kebab-dropdown.show {
    display: flex;
    animation: profileFadeDown 0.25s ease forwards;
}
.profile-kebab-dropdown a i {
    font-size: 18px;
    color: #ff2626;
}
@keyframes profileFadeDown {
    0%   { opacity: 0; transform: translateY(-5px); }
    100% { opacity: 1; transform: translateY(0); }
}

/* ════════════════════════════════════
   TABLET (≤768px)
════════════════════════════════════ */
@media (max-width: 768px) {
    .profile-kebab-btn {
        font-size: 20px;
        padding: 5px 8px;
        border-radius: 10px;
    }

    .profile-kebab-dropdown {
        top: 270px;
        left: auto;
        right: 12px;
        min-width: 165px;
    }

    .profile-kebab-dropdown a {
        padding: 9px 13px;
        font-size: 13px;
    }
}

/* ════════════════════════════════════
   MOBILE (≤480px)
════════════════════════════════════ */
@media (max-width: 480px) {
    .profile-kebab-btn {
        font-size: 18px;
        padding: 4px 7px;
        border-radius: 8px;
    }

    .profile-kebab-dropdown {
        top: 230px;
        left: auto;
        right: 10px;
        min-width: 155px;
        border-radius: 10px;
    }

    .profile-kebab-dropdown a {
        padding: 8px 12px;
        font-size: 12.5px;
        gap: 6px;
    }
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
        right: 46px;  /* sits just left of search btn */
        top: 20px;
    }

    .profile-kebab-dropdown {
        top: 55px;
        left: auto;
        right: 10px;
        min-width: 165px;
    }

    .profile-kebab-dropdown a {
        padding: 9px 13px;
        font-size: 13px;
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

    .profile-kebab-dropdown {
        top: 50px;
        right: 10px;
        min-width: 155px;
        border-radius: 10px;
    }

    .profile-kebab-dropdown a {
        padding: 8px 12px;
        font-size: 12.5px;
        gap: 6px;
    }
}
</style>


<script>
// Wait until DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    const kebabBtn = document.querySelector('.profile-kebab-btn');
    const kebabDropdown = document.querySelector('.profile-kebab-dropdown');

    if(kebabBtn && kebabDropdown){
        kebabBtn.addEventListener('click', (e) => {
            e.stopPropagation(); // Prevent page click from immediately closing
            kebabDropdown.classList.toggle('show');
        });

        // Close dropdown if clicked outside
        document.addEventListener('click', () => {
            kebabDropdown.classList.remove('show');
        });
    }
});

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
</script>
<?php endif; ?>
</div>
<?php endif; ?>

        <!-- Change Profile Picture (only for self) -->
<style>
  /* ─── Base Profile Actions ─── */
  .profile-actions {
    display: flex;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
    margin-top: 5px;
  }

  /* ─── Upload Form ─── */
  #profile-upload {
    margin-top: 10px;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
  }

  /* ─── Change Picture Label ─── */
  .profile-pic-label {
    padding: 8px 16px;
    border-radius: 20px;
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    background: rgba(220, 217, 217, 0.33);
    color: #fff;
    cursor: pointer;
    transition: 0.3s;
    font-weight: 500;
    box-shadow: 0 4px 15px rgba(0, 255, 170, 0.2);
    font-size: 0.9rem;
    white-space: nowrap;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-align: center;
  }

  .profile-pic-label:hover {
    background: rgba(220, 217, 217, 0.5);
    box-shadow: 0 4px 18px rgba(0, 255, 170, 0.35);
  }

  /* ─── Hidden File Input ─── */
  #profile_pic {
    display: none;
  }

  /* ─── Update Button ─── */
  .profile-update-btn {
    padding: 10px 22px;
    border-radius: 25px;
    background: rgba(152, 143, 143, 0.1);
    color: #fff;
    font-weight: 500;
    border: none;
    cursor: pointer;
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    box-shadow: 0 6px 20px rgba(0, 255, 170, 0.25);
    transition: background 0.3s ease, box-shadow 0.3s ease, transform 0.2s ease;
    font-size: 0.9rem;
    white-space: nowrap;
    display: inline-flex;
    align-items: center;
    justify-content: center;
  }

  .profile-update-btn:hover {
    background: rgba(255, 0, 0, 0.8);
    box-shadow: 0 8px 25px rgba(255, 0, 0, 0.4);
    transform: translateY(-2px);
  }

  .profile-update-btn:active {
    background: rgba(200, 0, 0, 0.9);
    box-shadow: 0 4px 15px rgba(150, 0, 0, 0.4);
    transform: translateY(1px);
  }

  /* ─── Profile Message ─── */
  #profile-msg {
    font-size: 0.85rem;
    color: #aeffd8;
    padding: 4px 10px;
    border-radius: 10px;
    background: rgba(0, 255, 170, 0.1);
    border: 1px solid rgba(0, 255, 170, 0.2);
    white-space: nowrap;
  }

  /* ─── Post Count Button ─── */
  .post-count-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 0.9rem;
    color: #fff;
    background: rgba(255, 255, 255, 0.08);
    padding: 8px 14px;
    border-radius: 12px;
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
    white-space: nowrap;
  }

  /* ── Tablet (≤768px) ── */
@media (max-width: 768px) {
  .profile-actions {
    gap: 10px;
  }

  #profile-upload {
    gap: 8px;
  }

  .profile-pic-label {
    padding: 6px 13px;
    font-size: 0.78rem;
    border-radius: 18px;
  }

  .profile-update-btn {
    padding: 7px 16px;
    font-size: 0.78rem;
    border-radius: 22px;
  }

  .post-count-btn {
    padding: 6px 11px;
    font-size: 0.78rem;
  }

  #profile-msg {
    font-size: 0.75rem;
  }
}

/* ── Mobile (≤480px) ── */
@media (max-width: 480px) {
  .profile-actions {
    gap: 7px;
  }

  #profile-upload {
    gap: 6px;
  }

  .profile-pic-label {
    padding: 5px 11px;
    font-size: 0.70rem;
    border-radius: 16px;
  }

  .profile-update-btn {
    padding: 6px 13px;
    font-size: 0.70rem;
    border-radius: 20px;
  }

  .post-count-btn {
    padding: 5px 9px;
    font-size: 0.70rem;
  }

  #profile-msg {
    font-size: 0.68rem;
  }
}

/* =========================
   TABLET
========================= */
@media (max-width:768px){

    /* post count bubble */
    .post-count-btn{
        width:42px !important;
        height:42px !important;

        border-radius:12px !important;

        font-size:11px !important;

        gap:4px !important;
    }

    .post-count-btn i{
        font-size:15px !important;
    }

    .post-count-btn span{
        font-size:11px !important;
    }

}


/* =========================
   PHONE
========================= */
@media (max-width:480px){


    /* smaller count button */
    .post-count-btn{
        width:38px !important;
        height:38px !important;

        border-radius:10px !important;

        gap:3px !important;
    }

    .post-count-btn i{
        font-size:14px !important;
    }

    .post-count-btn span{
        font-size:10px !important;
    }

   
    

}


/* =========================
   SMALL PHONE
========================= */
@media (max-width:360px){

    .post-count-btn{
        width:34px !important;
        height:34px !important;

        border-radius:9px !important;
    }

    .post-count-btn i{
        font-size:13px !important;
    }

    .post-count-btn span{
        font-size:9px !important;
    }

}
</style>

<!-- HTML/PHP unchanged -->
<div class="profile-actions">
    <?php if($current_user == $profile_id): ?>
    <form id="profile-upload" method="POST" enctype="multipart/form-data">

      <!-- Glassy Choose File -->
      <label for="profile_pic" class="profile-pic-label">
        Change Picture
      </label>
      <input type="file" name="profile_pic" id="profile_pic" accept="image/*">

      <!-- Glassy Bubble Update Button -->
      <button type="submit" name="update_profile" class="profile-update-btn">
        Update
      </button>

      <?php if($profile_msg) echo "<div id='profile-msg'>$profile_msg</div>"; ?>
    </form>
    <?php endif; ?>

    <?php if($current_user == $profile_id): ?>
    <div class="post-count-btn" title="Total uploads">
        <i class="ri-folder-2-line"></i>
        <span><?php echo $post_count; ?></span>
    </div>
    <?php endif; ?>
</div>
</div>
</div>

<div class="tabs">
  <?php if (!$is_blocked): ?>
    <?php
// Make sure this exists once on the page
$blocked_check = $conn->query("
    SELECT 1 FROM blocks 
    WHERE (blocker_id = $current_user AND blocked_id = $profile_id)
       OR (blocker_id = $profile_id AND blocked_id = $current_user)
")->num_rows > 0;

// Decide behavior for buttons
if($blocked_check){
    $meme_action = "alert('You are blocked, you cannot view this.'); return false;";
    $repost_action = "alert('You are blocked, you cannot view this.'); return false;";
} elseif(!$can_view){
    $meme_action = "alert('Track this Peumer to view their memes'); return false;";
    $repost_action = "alert('Track this Peumer to view their reposts'); return false;";
} else {
    $meme_action = "window.location.href='meme_uploads.php?user_id=".$profile_id."'";
    $repost_action = "window.location.href='repost.php?user_id=".$profile_id."'";
}
?>

 <button onclick="toggleSection('reposts')"><i class="ri-repeat-line"></i> reposts</button>
<!-- MEMES -->
<button onclick="<?php echo $meme_action; ?>">
    <i class="ri-home-5-line"></i> Memes
</button>

<!-- REPOSTS -->
<button onclick="<?php echo $repost_action; ?>">
    <i class="ri-repeat-line"></i> Reposts
</button>

<!-- Posts Count Button -->
 <?php if($current_user != $profile_id): ?>
<div class="post-count-btn" title="Total uploads">
    <i class="ri-folder-2-line"></i>
    <span><?php echo $post_count; ?></span>
</div>
<?php endif; ?>
 <?php endif; ?>
    <?php if($current_user == $profile_id): ?>
        <button onclick="toggleSection('favorites')"><i class="ri-bookmark-line"></i> Favorites</button>
        <button onclick="toggleSection('messages')"><i class="ri-chat-1-line"></i> Messages</button>
        <button onclick="toggleSection('uploads')"><i class="ri-upload-2-line"></i> My Uploads</button>
        <!-- Single kebab component for any user -->
    <?php endif; ?>

</div>

<?php if (!$is_blocked): ?>
<div id="memes" class="dropdown-section" style="display:grid;">
    <div class="grid">

<?php while($row = $memes->fetch_assoc()): ?>

<?php
$created = strtotime($row['created_at']);
$now = time();
$diff = $now - $created;

$is_new = ($diff <= 86400);          // 0–24h
$is_fading = ($diff > 86400 && $diff <= 172800); // 24–48h
?>

<div class="meme-card 
    <?= $is_new ? 'is-new' : '' ?> 
    <?= $is_fading ? 'is-fading' : '' ?>">

    <!-- TOP BAR -->
    <div class="top-bar">
        <img src="<?= $row['uploader_pic'] ?: 'uploads/default.jpg'; ?>"
             onclick="window.location.href='profile.php?user_id=<?= $row['uploaded_by']; ?>'">

        <div class="username"
             onclick="window.location.href='profile.php?user_id=<?= $row['uploaded_by']; ?>'">

            <?php
            if($current_user == $row['uploaded_by']){
                echo "You";
            } else {
                echo htmlspecialchars($row['username']);
            }
            ?>
        </div>
    </div>

    <!-- MEDIA -->
    <div class="media-box">

        <?php if($row['type'] == "image"): ?>
            <img src="uploads/<?= $row['file_name']; ?>" onclick="openModal(this.src)">
        <?php elseif($row['type'] == "video"): ?>
            <video src="uploads/<?= $row['file_name']; ?>" autoplay muted loop onclick="openModal(this.src)"></video>
        <?php else: ?>
            <img src="uploads/Text.jpg" onclick="openModal(this.src)">
        <?php endif; ?>

        <?php if($diff <= 172800): ?>
            <div class="new-badge">NEW</div>
        <?php endif; ?>

    </div>

    <!-- CAPTION -->
    <div class="caption">
        <i class="ri-home-5-line" style="color:#FF4C4C;"></i>
        <?= htmlspecialchars($row['caption']); ?>
    </div>

    <!-- ICON BAR -->
    <div class="icon-bar">
        <a href="download.php?file=<?= urlencode($row['file_name']); ?>">
            <i class="ri-download-line"></i>
        </a>
    </div>

</div>

<?php endwhile; ?>

    </div>
</div>
<?php endif; ?>

<!-- FAVORITES GRID -->
<div id="favorites" class="dropdown-section" style="display:none;">
    <div class="grid">

<?php while($row = $favorites->fetch_assoc()): ?>

<?php
$created = strtotime($row['created_at']);
$now = time();
$diff = $now - $created;

$is_new = ($diff <= 86400); // 24h
$is_fading = ($diff > 86400 && $diff <= 172800); // 24h - 48h
?>

<div class="meme-card 
    <?= $is_new ? 'is-new' : '' ?> 
    <?= $is_fading ? 'is-fading' : '' ?>">

    <!-- TOP BAR -->
    <div class="top-bar">
        <img src="<?= $row['uploader_pic'] ?: 'uploads/default.jpg'; ?>"
             onclick="window.location.href='profile.php?user_id=<?= $row['uploaded_by']; ?>'">

        <div class="username"
             onclick="window.location.href='profile.php?user_id=<?= $row['uploaded_by']; ?>'">

            <?= htmlspecialchars($row['username']); ?>
        </div>
    </div>

    <!-- MEDIA -->
    <div class="media-box">

        <?php if($row['type'] == "image"): ?>
            <img src="uploads/<?= $row['file_name']; ?>" onclick="openModal(this.src)">
        <?php elseif($row['type'] == "video"): ?>
            <video src="uploads/<?= $row['file_name']; ?>" autoplay muted loop onclick="openModal(this.src)"></video>
        <?php else: ?>
            <img src="uploads/Text.jpg" onclick="openModal(this.src)">
        <?php endif; ?>

        <?php if($diff <= 172800): ?>
            <div class="new-badge">NEW</div>
        <?php endif; ?>

    </div>

    <!-- ICON BAR -->
    <div class="icon-bar">
        <a href="download.php?file=<?= urlencode($row['file_name']); ?>" title="Download">
            <i class="ri-download-line"></i>
        </a>
    </div>

    <!-- CAPTION -->
    <div class="caption">
        <i class="ri-bookmark-line" style="color:#FF4C4C;"></i>
        <?= htmlspecialchars($row['caption']); ?>
    </div>

</div>

<?php endwhile; ?>

    </div>
</div>

<!-- MESSAGES -->
<div id="messages" class="dropdown-section">
<?php if($current_user==$profile_id && $messages->num_rows>0): ?>
  <?php while($m=$messages->fetch_assoc()): ?>
  <div class="message-user">
      <img src="<?php echo $m['profile_pic'] ?? 'uploads/default.jpg'; ?>" onclick="window.location.href='messages.php?user=<?php echo $m['id']; ?>'">
      <a href="messages.php?user=<?php echo $m['id']; ?>"><svg xmlns="http://www.w3.org/2000/svg" width="9" height="9" viewBox="0 0 24 24">
  <circle cx="12" cy="12" r="12" fill="#FF0000"/>
  <path d="M9 12l2 2 4-4" stroke="white" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
</svg> <?php echo $m['username']; ?></a>
  </div>
  <?php endwhile; ?>
<?php elseif($current_user==$profile_id): ?>
  <p>No messages yet.</p>
<?php endif; ?>
</div>

<!-- MY UPLOADS -->
<div id="uploads" class="dropdown-section" style="display:none;">
  <div class="grid">

<?php while($u = $my_uploads->fetch_assoc()): ?>

<?php
$is_new = (strtotime($u['created_at']) > strtotime('-24 hours'));
$is_fading = (strtotime($u['created_at']) > strtotime('-48 hours') && !$is_new);
?>

<div class="meme-card 
    <?= $is_new ? 'is-new' : '' ?> 
    <?= $is_fading ? 'is-fading' : '' ?>">

    <!-- TOP BAR -->
    <div class="top-bar">
        <img src="<?= $u['uploader_pic'] ?: 'uploads/default.jpg'; ?>">

        <div class="username">
            <?= ($current_user == $profile_id) ? "You" : htmlspecialchars($user['username']); ?>
        </div>
    </div>

    <!-- MEDIA -->
    <div class="media-box">

        <?php if($u['type'] == "image"): ?>
            <img src="uploads/<?= $u['file_name']; ?>" onclick="openModal(this.src)">
        <?php elseif($u['type'] == "video"): ?>
            <video src="uploads/<?= $u['file_name']; ?>" autoplay muted loop onclick="openModal(this.src)"></video>
        <?php else: ?>
            <img src="uploads/Text.jpg" onclick="openModal(this.src)">
        <?php endif; ?>

        <!-- OPTIONAL: NEW BADGE (if you want consistency) -->
        <?php if($is_new || $is_fading): ?>
            <div class="new-badge <?= $is_fading ? 'fade' : '' ?>">NEW</div>
        <?php endif; ?>

    </div>

    <!-- DELETE BUTTON -->
    <div class="icon-bar">
        <form method="POST" onsubmit="return confirm('Are you sure you want to delete this meme?');">
            <input type="hidden" name="meme_id" value="<?= $u['id']; ?>">
            <button type="submit" name="delete_meme" title="Delete">
                <i class="ri-delete-bin-line"></i>
            </button>
        </form>
    </div>

    <!-- CAPTION -->
    <div class="caption">
        <i class="ri-upload-2-line" style="color:#FF4C4C;"></i>
        <?= htmlspecialchars($u['caption']); ?>
    </div>

</div>

<?php endwhile; ?>

  </div>
</div>

<img class="bottom-logo" src="PEURA.jpg" alt="Floating Logo" onclick="window.location.href='index.php'">

<!-- MODAL -->
<div id="mediaModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.9);justify-content:center;align-items:center;z-index:200;">
<span style="position:absolute;top:20px;right:30px;font-size:40px;color:white;cursor:pointer;" onclick="closeModal()">&times;</span>
<img id="modalImg" style="max-width:90%;max-height:90%;display:none;">
<video id="modalVideo" style="max-width:90%;max-height:90%;display:none;" controls autoplay></video>
</div>

<script>
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


const canvas=document.getElementById('particles');
const ctx=canvas.getContext('2d');
canvas.width=window.innerWidth;
canvas.height=window.innerHeight;
let particlesArray=[];

class Particle{
    constructor(){
        this.x=Math.random()*canvas.width;
        this.y=Math.random()*canvas.height;
        this.size=Math.random()*2+1;
        this.speedX=Math.random()*1-0.5;
        this.speedY=Math.random()*1-0.5;
    }
    update(){
        this.x+=this.speedX;
        this.y+=this.speedY;
        if(this.x>canvas.width)this.x=0;
        if(this.x<0)this.x=canvas.width;
        if(this.y>canvas.height)this.y=0;
        if(this.y<0)this.y=canvas.height;
    }
    draw(){
        ctx.fillStyle='rgba(255, 255, 255, 0.64)';
        ctx.beginPath();
        ctx.arc(this.x,this.y,this.size,0,Math.PI*2);
        ctx.fill();
    }
}

function initParticles(){
    particlesArray=[];
    for(let i=0;i<120;i++) particlesArray.push(new Particle());
}

function animateParticles(){
    ctx.clearRect(0,0,canvas.width,canvas.height);
    particlesArray.forEach(p=>{
        p.update();
        p.draw();
    });
    requestAnimationFrame(animateParticles);
}

initParticles();
animateParticles();

window.addEventListener('resize',()=>{
    canvas.width=window.innerWidth;
    canvas.height=window.innerHeight;
});

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


































<?php if (!$is_blocked): ?>
<div id="memes" class="dropdown-section" style="display:grid;">
    <div class="grid" id="memesGrid">

        <?php if(!$memes || $memes->num_rows === 0): ?>
            <div style="grid-column:1/-1;text-align:center;padding:40px 20px;color:#aaa;font-size:0.95rem;">
                <i class="ri-home-5-line" style="font-size:2.5rem;display:block;margin-bottom:10px;color:#FF4C4C;"></i>
                No memes yet
            </div>

        <?php else: ?>
        <script>var profileFeed = [];</script>

        <?php while($row = $memes->fetch_assoc()): ?>
        <?php
            $created         = strtotime($row['created_at']);
            $now             = time();
            $diff            = $now - $created;
            $is_new          = ($diff <= 86400);
            $is_fading       = ($diff > 86400 && $diff <= 172800);
            $safe_caption    = addslashes(htmlspecialchars($row['caption']));
            $safe_username   = addslashes(htmlspecialchars($row['username']));
            $safe_pic        = addslashes($row['uploader_pic'] ?: 'uploads/default.jpg');
            $safe_file       = addslashes($row['file_name']);
            $safe_time       = addslashes($row['created_at']);
            $is_you          = ($current_user == $row['uploaded_by']) ? 'true' : 'false';
            $modal_file      = ($row['type'] === 'text' || empty($row['file_name']) || $row['file_name'] === '0') ? 'uploads/Text.jpg' : 'uploads/'.$row['file_name'];
            $safe_modal_file = addslashes($modal_file);
            $p_like_count    = $conn->query("SELECT COUNT(*) as c FROM likes WHERE meme_id=".(int)$row['id'])->fetch_assoc()['c'];
            $p_repost_count  = $conn->query("SELECT COUNT(*) as c FROM reposts WHERE meme_id=".(int)$row['id'])->fetch_assoc()['c'];
            $p_fav_count     = $conn->query("SELECT COUNT(*) as c FROM favorites WHERE meme_id=".(int)$row['id'])->fetch_assoc()['c'];
            $p_liked         = $conn->query("SELECT id FROM likes WHERE meme_id=".(int)$row['id']." AND user_id=$current_user")->num_rows > 0;
            $p_reposted      = $conn->query("SELECT id FROM reposts WHERE meme_id=".(int)$row['id']." AND user_id=$current_user")->num_rows > 0;
            $p_faved         = $conn->query("SELECT id FROM favorites WHERE meme_id=".(int)$row['id']." AND user_id=$current_user")->num_rows > 0;
        ?>
        <script>
        profileFeed.push({
            id:          <?= (int)$row['id'] ?>,
            file:        '<?= $safe_modal_file ?>',
            type:        '<?= addslashes($row['type']) ?>',
            pic:         '<?= $safe_pic ?>',
            userId:      <?= (int)$row['uploaded_by'] ?>,
            username:    '<?= $safe_username ?>',
            caption:     '<?= $safe_caption ?>',
            time:        '<?= $safe_time ?>',
            isYou:       <?= $is_you ?>,
            likeCount:   <?= (int)$p_like_count ?>,
            repostCount: <?= (int)$p_repost_count ?>,
            favCount:    <?= (int)$p_fav_count ?>,
            liked:       <?= $p_liked    ? 'true' : 'false' ?>,
            reposted:    <?= $p_reposted ? 'true' : 'false' ?>,
            faved:       <?= $p_faved    ? 'true' : 'false' ?>
        });
        </script>

        <div class="meme-card <?= $is_new ? 'is-new' : '' ?> <?= $is_fading ? 'is-fading' : '' ?>">

            <!-- TOP BAR -->
            <div class="top-bar">
                <img src="<?= $row['uploader_pic'] ?: 'uploads/default.jpg'; ?>"
                     onclick="window.location.href='profile.php?user_id=<?= $row['uploaded_by']; ?>'">
                <div class="username" onclick="window.location.href='profile.php?user_id=<?= $row['uploaded_by']; ?>'">
                    <?php echo ($current_user == $row['uploaded_by']) ? 'You' : htmlspecialchars($row['username']); ?>
                </div>
            </div>

            <!-- MEDIA -->
            <div class="media-box">
                <?php if($row['type'] == "image"): ?>
                    <img src="uploads/<?= $safe_file ?>"
                         onclick="openMemeModal('<?= $safe_modal_file ?>','<?= $row['type'] ?>','<?= $safe_caption ?>','<?= $safe_pic ?>','<?= $safe_username ?>',<?= $row['uploaded_by'] ?>,<?= $is_you ?>,'profileFeed')">
                <?php elseif($row['type'] == "video"): ?>
                    <video src="uploads/<?= $safe_file ?>" autoplay muted loop
                           onclick="openMemeModal('<?= $safe_modal_file ?>','<?= $row['type'] ?>','<?= $safe_caption ?>','<?= $safe_pic ?>','<?= $safe_username ?>',<?= $row['uploaded_by'] ?>,<?= $is_you ?>,'profileFeed')">
                    </video>
                <?php else: ?>
                    <img src="uploads/Text.jpg"
                         onclick="openMemeModal('uploads/Text.jpg','image','<?= $safe_caption ?>','<?= $safe_pic ?>','<?= $safe_username ?>',<?= $row['uploaded_by'] ?>,<?= $is_you ?>,'profileFeed')">
                <?php endif; ?>
                <?php if($diff <= 172800): ?>
                    <div class="new-badge">NEW</div>
                <?php endif; ?>
            </div>

            <!-- CAPTION -->
            <div class="caption">
                <i class="ri-home-5-line" style="color:#FF4C4C;"></i>
                <?= htmlspecialchars($row['caption']); ?>
            </div>

            <!-- ICON BAR -->
            <div class="icon-bar">
                <a href="download.php?file=<?= urlencode($row['file_name']); ?>">
                    <i class="ri-download-line"></i>
                </a>
            </div>

        </div>

        <?php endwhile; ?>
        <?php endif; ?>

    </div><!-- /.grid -->
</div><!-- /#memes -->
<?php endif; ?>

<script>
(function () {
    var sk   = document.getElementById('profileMemesSkeleton');
    var grid = document.getElementById('memesGrid');

    if (!sk) return;

    if (!grid) {
        sk.style.display = 'none';
        return;
    }

    // Show skeleton, hide grid immediately
    sk.style.display      = 'grid';
    sk.style.opacity      = '1';
    grid.style.visibility = 'hidden';
    grid.style.opacity    = '0';

    function doSwap() {
        sk.style.transition = 'opacity 0.4s ease';
        sk.style.opacity    = '0';
        setTimeout(function () {
            sk.style.display      = 'none';
            grid.style.visibility = 'visible';
            grid.style.transition = 'opacity 0.4s ease';
            grid.style.opacity    = '0';
            requestAnimationFrame(function () {
                requestAnimationFrame(function () {
                    grid.style.opacity = '1';
                });
            });
        }, 400);
    }

    if (document.readyState === 'complete') {
        setTimeout(doSwap, 200);
    } else {
        window.addEventListener('load', function () {
            setTimeout(doSwap, 200);
        });
    }
})();
</script>



<!-- MODAL — outside blocked check so it always renders -->
<div id="memeModal" onclick="if(event.target===this)closeMemeModal()" style="
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

    <div id="meme-modal-card" style="background:rgba(18,17,17,0.95);border:1px solid rgba(255,255,255,0.07);border-radius:20px;width:100%;max-width:420px;overflow:hidden;display:flex;flex-direction:column;max-height:90vh;">
        <div id="meme-modal-inner"></div>
    </div>

    <div id="meme-modal-arrows" style="display:flex;flex-direction:column;align-items:center;gap:10px;flex-shrink:0;">
        <button onclick="_profileFeedLoadIndex(profileFeedIndex-1)" onmouseover="this.style.background='rgba(255,255,255,0.15)'" onmouseout="this.style.background='rgba(255,255,255,0.07)'" style="background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.12);border-radius:50%;width:40px;height:40px;display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;transition:background 0.2s;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.7)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"/></svg>
        </button>
        <button onclick="_profileFeedLoadIndex(profileFeedIndex+1)" onmouseover="this.style.background='rgba(255,255,255,0.15)'" onmouseout="this.style.background='rgba(255,255,255,0.07)'" style="background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.12);border-radius:50%;width:40px;height:40px;display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;transition:background 0.2s;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.7)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
    </div>

    <div id="meme-modal-hint" style="position:absolute;bottom:10px;left:50%;transform:translateX(-50%);color:rgba(255,255,255,0.18);font-size:10px;white-space:nowrap;pointer-events:none;">swipe or use arrows ↕</div>
</div>

<style>
#meme-modal-inner { display:flex; flex-direction:column; overflow:hidden; flex:1; }
.pmm-caption-box::-webkit-scrollbar { width:4px; }
.pmm-caption-box::-webkit-scrollbar-thumb { background:rgba(255,255,255,0.15); border-radius:999px; }
.pmm-action-bar { display:flex; align-items:center; gap:6px; padding:10px 14px; border-top:1px solid rgba(255,255,255,0.06); flex-shrink:0; }
.pmm-action-btn { display:flex; align-items:center; justify-content:center; gap:5px; background:transparent; border:1px solid rgba(255,255,255,0.1); border-radius:10px; padding:7px 12px; color:white; cursor:pointer; font-size:12px; font-weight:600; font-family:Poppins,sans-serif; transition:background 0.2s; flex:1; }
.pmm-action-btn:hover { background:rgba(255,255,255,0.07); }
.pmm-close-btn { display:flex; align-items:center; justify-content:center; background:transparent; border:1px solid rgba(255,255,255,0.1); border-radius:10px; padding:7px 12px; color:rgba(255,255,255,0.45); cursor:pointer; font-size:12px; font-family:Poppins,sans-serif; }
.pmm-ad-wrap { padding:14px; display:flex; flex-direction:column; gap:10px; overflow-y:auto; flex:1; }
.pmm-ad-header { display:flex; align-items:center; gap:8px; }
.pmm-ad-logo { width:28px; height:28px; border-radius:50%; border:1.5px solid #FF2D2D; object-fit:cover; flex-shrink:0; }
.pmm-ad-brand { font-size:13px; font-weight:600; color:white; }
.pmm-ad-type { font-size:10px; color:rgba(255,255,255,0.4); }
.pmm-ad-media { width:100%; border-radius:12px; overflow:hidden; border:1.5px solid rgba(255,45,45,0.45); }
.pmm-ad-media img, .pmm-ad-media video { width:100%; display:block; object-fit:cover; max-height:200px; }
.pmm-ad-caption { font-size:11px; color:rgba(255,255,255,0.7); line-height:1.45; }
.pmm-ad-cta { display:flex; align-items:center; justify-content:center; gap:6px; padding:10px; background:rgba(255,255,255,0.07); border:1px solid rgba(255,255,255,0.1); border-radius:12px; color:white; font-weight:600; font-size:12px; text-decoration:none; transition:background 0.2s; }
.pmm-ad-cta:hover { background:rgba(255,255,255,0.12); }
.pmm-sugg-label { font-size:10px; font-weight:700; letter-spacing:1.2px; color:#f24455; text-transform:uppercase; }
.pmm-sugg-scroll { display:flex; flex-wrap:nowrap; overflow-x:auto; gap:14px; padding:4px 0 8px; scrollbar-width:none; -ms-overflow-style:none; -webkit-overflow-scrolling:touch; }
.pmm-sugg-scroll::-webkit-scrollbar { display:none; }
.pmm-sugg-user { flex:0 0 auto; width:68px; display:flex; flex-direction:column; align-items:center; gap:5px; text-decoration:none; color:white; }
.pmm-sugg-user img { width:52px; height:52px; border-radius:50%; border:2px solid #FF2D2D; object-fit:cover; display:block; transition:transform 0.2s; }
.pmm-sugg-user:hover img { transform:scale(1.08); }
.pmm-sugg-name { font-size:10px; font-weight:500; text-align:center; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; width:100%; }
.pmm-sugg-track { font-size:9px; font-weight:600; padding:3px 0; width:100%; border-radius:8px; border:1px solid rgba(255,45,45,0.5); background:transparent; color:rgba(255,255,255,0.7); cursor:pointer; transition:all 0.2s; font-family:Poppins,sans-serif; text-align:center; }
.pmm-sugg-track:hover, .pmm-sugg-track.tracking { background:rgba(255,45,45,0.15); border-color:#FF2D2D; color:#FF2D2D; }
@media(max-width:480px) {
    #meme-modal-arrows, #meme-modal-hint { display:none !important; }
    #memeModal { padding:10px !important; align-items:center !important; }
    #meme-modal-card { border-radius:20px 20px 14px 14px !important; max-height:88vh !important; }
    .pmm-caption-box { font-size:12px !important; max-height:60px !important; }
    .pmm-action-btn { padding:7px 8px !important; font-size:11px !important; }
}
@media(max-width:360px) {
    .pmm-caption-box { font-size:11px !important; max-height:55px !important; }
    .pmm-action-btn { padding:6px !important; font-size:10px !important; }
}
</style>

<script>
let modalProfileId   = null;
let profileFeedIndex = 0;
let _activeFeed      = [];
let _pmmFeed         = [];
let _pmmMemeId       = null;

/* ── Fetch ads + suggested users then build feed ── */
function _loadPmmData() {
    Promise.all([
        fetch('profile.php?type=ads').then(function(r){ return r.json(); }).catch(function(){ return []; }),
        fetch('profile.php?type=sugg').then(function(r){ return r.json(); }).catch(function(){ return []; })
    ]).then(function(results) {
        window.cmAds       = results[0] || [];
        window.cmSuggUsers = results[1] || [];
    });
}

function _buildPmmFeed() {
    _pmmFeed = [];
    var adIdx = 0, suggIdx = 0;
    var ads  = window.cmAds       || [];
    var sugg = window.cmSuggUsers || [];
    _activeFeed.forEach(function(post, i) {
        _pmmFeed.push({ type: 'post', data: post });
        if ((i + 1) % 4 === 0 && ads.length > 0) {
            _pmmFeed.push({ type: 'ad', data: ads[adIdx % ads.length] });
            adIdx++;
        }
        if ((i + 1) % 7 === 0 && sugg.length > 0) {
            var slice = [];
            for (var j = 0; j < 6; j++) slice.push(sugg[(suggIdx + j) % sugg.length]);
            _pmmFeed.push({ type: 'sugg', data: slice });
            suggIdx += 6;
        }
    });
}

function _pmmFormatDate(timeStr) {
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

function _pmmLinkify(caption) {
    return caption.replace(/@([a-zA-Z0-9_]+)/g, function(match, username) {
        return '<a href="profile.php?username=' + encodeURIComponent(username) +
               '" onclick="event.stopPropagation()" style="color:#f24455;font-weight:600;text-decoration:none;"' +
               ' onmouseover="this.style.textDecoration=\'underline\'"' +
               ' onmouseout="this.style.textDecoration=\'none\'">' + match + '</a>';
    });
}

function _pmmRenderPost(item) {
    var p = item.data;
    _pmmMemeId = p.id;

    var mediaHtml = '';
    var isText = (
        p.type === 'text' || !p.file ||
        p.file === 'uploads/' || p.file === 'uploads/0' ||
        p.file.endsWith('/Text.jpg') || p.file.endsWith('/0')
    );
    if (!isText) {
        if (p.type === 'video') {
            mediaHtml = '<video src="' + p.file + '" autoplay muted loop playsinline style="width:100%;max-height:52vh;object-fit:contain;display:block;"></video>';
        } else {
            mediaHtml = '<img src="' + p.file + '" onerror="this.src=\'uploads/me.jpeg\'" style="width:100%;max-height:52vh;object-fit:contain;display:block;">';
        }
    }

    var likeCount   = p.likeCount   !== undefined ? p.likeCount   : 0;
    var repostCount = p.repostCount !== undefined ? p.repostCount : 0;
    var favCount    = p.favCount    !== undefined ? p.favCount    : 0;
    var liked    = p.liked    || false;
    var reposted = p.reposted || false;
    var faved    = p.faved    || false;

    var dateStr = _pmmFormatDate(p.time || '');

    return '' +
        '<div style="display:flex;align-items:center;gap:10px;padding:14px 16px;flex-shrink:0;">' +
            '<img src="' + (p.pic || 'uploads/me.jpeg') + '" onerror="this.src=\'uploads/me.jpeg\'"' +
                 ' onclick="goToModalProfile()"' +
                 ' style="width:40px;height:40px;border-radius:50%;border:2px solid #FF4C4C;cursor:pointer;object-fit:cover;flex-shrink:0;">' +
            '<div style="flex:1;min-width:0;">' +
                '<div onclick="goToModalProfile()" style="color:#FF4C4C;font-weight:700;font-size:14px;cursor:pointer;display:flex;align-items:center;gap:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" style="flex-shrink:0;"><circle cx="12" cy="12" r="12" fill="#FF0000"/><path d="M9 12l2 2 4-4" stroke="white" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
                    '<span>' + (p.isYou ? 'You' : p.username) + '</span>' +
                '</div>' +
                '<div style="font-size:11px;color:rgba(255,255,255,0.35);margin-top:3px;display:flex;align-items:center;gap:4px;">' +
                    '<i class="ri-time-line" style="font-size:10px;"></i>' +
                    '<span>' + dateStr + '</span>' +
                '</div>' +
            '</div>' +
            '<button onclick="closeMemeModal()" style="background:rgba(255,255,255,0.06);border:none;border-radius:50%;width:30px;height:30px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:rgba(255,255,255,0.5);font-size:16px;flex-shrink:0;">✕</button>' +
        '</div>' +
        (mediaHtml ? '<div style="width:100%;background:#000;flex-shrink:0;">' + mediaHtml + '</div>' : '') +
        '<div class="pmm-caption-box" style="padding:10px 16px;color:rgba(255,255,255,0.8);font-size:13px;line-height:1.55;border-top:1px solid rgba(255,255,255,0.06);word-break:break-word;white-space:pre-wrap;overflow-y:auto;max-height:80px;flex-shrink:0;">' +
            _pmmLinkify((p.caption || '').replace(/\\n/g, '\n')) +
        '</div>' +
        '<div class="pmm-action-bar">' +
            '<button id="pmm-like-btn" class="pmm-action-btn" style="color:' + (liked ? '#ff4c4c' : 'white') + ';border-color:' + (liked ? 'rgba(255,76,76,0.4)' : 'rgba(255,255,255,0.1)') + ';" onclick="pmmAction(\'like\')">' +
                '<i class="ri-heart-fill" style="font-size:14px;"></i><span id="pmm-like-count">' + likeCount + '</span>' +
            '</button>' +
            '<button id="pmm-repost-btn" class="pmm-action-btn" style="color:' + (reposted ? '#ff4c4c' : 'white') + ';border-color:' + (reposted ? 'rgba(255,76,76,0.4)' : 'rgba(255,255,255,0.1)') + ';" onclick="pmmAction(\'repost\')">' +
                '<i class="ri-repeat-line" style="font-size:14px;"></i><span id="pmm-repost-count">' + repostCount + '</span>' +
            '</button>' +
            '<button id="pmm-fav-btn" class="pmm-action-btn" style="color:' + (faved ? '#ff4c4c' : 'white') + ';border-color:' + (faved ? 'rgba(255,76,76,0.4)' : 'rgba(255,255,255,0.1)') + ';" onclick="pmmAction(\'fav\')">' +
                '<i class="ri-bookmark-fill" style="font-size:14px;"></i><span id="pmm-fav-count">' + favCount + '</span>' +
            '</button>' +
            '<button class="pmm-close-btn" onclick="closeMemeModal()">Close</button>' +
        '</div>';
}

function _pmmRenderAd(item) {
    var ad  = item.data;
    var ext = (ad.meme_image || '').split('.').pop().toLowerCase();
    var isVideo = ['mp4','webm','mov'].indexOf(ext) !== -1;
    _pmmMemeId = null;
    return '' +
        '<div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px 0;flex-shrink:0;">' +
            '<span style="font-size:9px;font-weight:800;letter-spacing:1px;color:rgba(255,255,255,0.35);text-transform:uppercase;border:1px solid rgba(255,255,255,0.12);border-radius:4px;padding:2px 6px;">AD</span>' +
            '<button onclick="closeMemeModal()" style="background:rgba(255,255,255,0.06);border:none;border-radius:50%;width:30px;height:30px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:rgba(255,255,255,0.5);font-size:16px;">✕</button>' +
        '</div>' +
        '<div class="pmm-ad-wrap">' +
            '<div class="pmm-ad-header">' +
                '<img src="uploads/' + (ad.image || '') + '" onerror="this.src=\'uploads/me.jpeg\'" class="pmm-ad-logo">' +
                '<div style="min-width:0;"><div class="pmm-ad-brand">' + (ad.title || '') + '</div><div class="pmm-ad-type">' + (ad.type || '') + '</div></div>' +
            '</div>' +
            '<div class="pmm-ad-media">' +
                (isVideo ? '<video src="uploads/' + ad.meme_image + '" autoplay muted loop playsinline></video>'
                         : '<img src="uploads/' + (ad.meme_image || '') + '" onerror="this.src=\'uploads/me.jpeg\'">') +
            '</div>' +
            '<div class="pmm-ad-caption">' + (ad.caption || '') + '</div>' +
            '<a href="' + (ad.link || '#') + '" target="_blank" class="pmm-ad-cta" onclick="event.stopPropagation()">Visit Brand <i class="ri-arrow-right-up-line"></i></a>' +
        '</div>' +
        '<div class="pmm-action-bar">' +
            '<button class="pmm-action-btn" onclick="_profileFeedLoadIndex(profileFeedIndex-1)"><i class="ri-arrow-up-line"></i> Prev</button>' +
            '<button class="pmm-action-btn" onclick="_profileFeedLoadIndex(profileFeedIndex+1)">Next <i class="ri-arrow-down-line"></i></button>' +
            '<button class="pmm-close-btn" onclick="closeMemeModal()">Close</button>' +
        '</div>';
}

function _pmmRenderSugg(item) {
    var users = item.data;
    _pmmMemeId = null;
    var usersHtml = users.map(function(u) {
        var following = parseInt(u.is_following) > 0;
        return '<div class="pmm-sugg-user">' +
            '<a href="profile.php?user_id=' + u.id + '" onclick="event.stopPropagation()" style="display:contents;color:inherit;text-decoration:none;">' +
                '<img src="' + (u.profile_pic || 'uploads/me.jpeg') + '" onerror="this.src=\'uploads/me.jpeg\'">' +
                '<span class="pmm-sugg-name">' + u.username + '</span>' +
            '</a>' +
            '<button class="pmm-sugg-track ' + (following ? 'tracking' : '') + '" data-user="' + u.id + '" onclick="event.stopPropagation();pmmToggleSuggTrack(this)">' +
                (following ? 'Untrack' : 'Track') +
            '</button>' +
        '</div>';
    }).join('');
    return '' +
        '<div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px 0;flex-shrink:0;">' +
            '<span class="pmm-sugg-label">Suggested Peumers</span>' +
            '<button onclick="closeMemeModal()" style="background:rgba(255,255,255,0.06);border:none;border-radius:50%;width:30px;height:30px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:rgba(255,255,255,0.5);font-size:16px;">✕</button>' +
        '</div>' +
        '<div style="padding:10px 14px;overflow-y:auto;flex:1;"><div class="pmm-sugg-scroll">' + usersHtml + '</div></div>' +
        '<div class="pmm-action-bar">' +
            '<button class="pmm-action-btn" onclick="_profileFeedLoadIndex(profileFeedIndex-1)"><i class="ri-arrow-up-line"></i> Prev</button>' +
            '<button class="pmm-action-btn" onclick="_profileFeedLoadIndex(profileFeedIndex+1)">Next <i class="ri-arrow-down-line"></i></button>' +
            '<button class="pmm-close-btn" onclick="closeMemeModal()">Close</button>' +
        '</div>';
}

function _pmmRenderSlot(index) {
    if (index < 0 || index >= _pmmFeed.length) return;
    var item  = _pmmFeed[index];
    var inner = document.getElementById('meme-modal-inner');
    inner.querySelectorAll('video').forEach(function(v){ v.pause(); v.src=''; });
    if      (item.type === 'post') inner.innerHTML = _pmmRenderPost(item);
    else if (item.type === 'ad')   inner.innerHTML = _pmmRenderAd(item);
    else if (item.type === 'sugg') inner.innerHTML = _pmmRenderSugg(item);
}

function openMemeModal(src, type, caption, pic, username, userId, isYou, feedName) {
    _activeFeed = window[feedName] || profileFeed;
    modalProfileId = userId;

    _buildPmmFeed();

    var idx = _pmmFeed.findIndex(function(s) {
        return s.type === 'post' &&
               String(s.data.userId) === String(userId) &&
               s.data.caption === caption.replace(/\\'/g, "'");
    });
    profileFeedIndex = idx >= 0 ? idx : 0;

    _pmmRenderSlot(profileFeedIndex);
    document.getElementById('memeModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function _profileFeedLoadIndex(index) {
    if (index < 0 || index >= _pmmFeed.length) return;
    var card = document.getElementById('meme-modal-card');
    var dir  = index > profileFeedIndex ? 1 : -1;
    card.style.transition = 'transform 0.22s ease, opacity 0.22s ease';
    card.style.transform  = 'translateY(' + (dir * -30) + 'px)';
    card.style.opacity    = '0';
    setTimeout(function() {
        profileFeedIndex = index;
        _pmmRenderSlot(profileFeedIndex);
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

function closeMemeModal() {
    document.getElementById('meme-modal-inner').querySelectorAll('video').forEach(function(v){ v.pause(); v.src=''; });
    document.getElementById('memeModal').style.display = 'none';
    document.body.style.overflow = '';
    _pmmMemeId = null;
}

function goToModalProfile() {
    if(modalProfileId) window.location.href = 'profile.php?user_id=' + modalProfileId;
}

function pmmAction(type) {
    if (!_pmmMemeId) return;

    var btnId   = { like: 'pmm-like-btn',   repost: 'pmm-repost-btn',   fav: 'pmm-fav-btn'   };
    var cntId   = { like: 'pmm-like-count', repost: 'pmm-repost-count', fav: 'pmm-fav-count' };
    var stateKey= { like: 'liked',          repost: 'reposted',         fav: 'faved'         };

    fetch('profile.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=' + type + '&meme_id=' + _pmmMemeId
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (!data.ok) return;

        var activeNow = data.liked || data.reposted || data.faved || false;
        var newCount  = data.count;

        var btn = document.getElementById(btnId[type]);
        var cnt = document.getElementById(cntId[type]);
        if (btn) {
            btn.style.color       = activeNow ? '#ff4c4c' : 'white';
            btn.style.borderColor = activeNow ? 'rgba(255,76,76,0.4)' : 'rgba(255,255,255,0.1)';
        }
        if (cnt) cnt.textContent = newCount;

        var feedItem = _pmmFeed.find(function(s) {
            return s.type === 'post' && String(s.data.id) === String(_pmmMemeId);
        });
        if (feedItem) {
            var cntField = { like: 'likeCount', repost: 'repostCount', fav: 'favCount' };
            feedItem.data[cntField[type]]  = newCount;
            feedItem.data[stateKey[type]]  = activeNow;
        }
    })
    .catch(function(err) { console.error('pmmAction error:', err); });
}

function pmmToggleSuggTrack(btn) {
    var userId = btn.dataset.user;
    fetch('profile.php', {
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
window.addEventListener('load', function(){ _loadPmmData(); });

document.getElementById('memeModal').addEventListener('wheel', function(e) {
    e.preventDefault();
    if (e.deltaY > 0) _profileFeedLoadIndex(profileFeedIndex + 1);
    else              _profileFeedLoadIndex(profileFeedIndex - 1);
}, { passive: false });

var _profileTouchY = 0;
document.getElementById('memeModal').addEventListener('touchstart', function(e) {
    _profileTouchY = e.touches[0].clientY;
}, { passive: true });

document.getElementById('memeModal').addEventListener('touchend', function(e) {
    var diff = _profileTouchY - e.changedTouches[0].clientY;
    if (Math.abs(diff) > 40) {
        if (diff > 0) _profileFeedLoadIndex(profileFeedIndex + 1);
        else          _profileFeedLoadIndex(profileFeedIndex - 1);
    }
}, { passive: true });

document.addEventListener('keydown', function(e) {
    if (document.getElementById('memeModal').style.display === 'flex') {
        if (e.key === 'Escape')    closeMemeModal();
        if (e.key === 'ArrowDown') _profileFeedLoadIndex(profileFeedIndex + 1);
        if (e.key === 'ArrowUp')   _profileFeedLoadIndex(profileFeedIndex - 1);
    }
});
</script>

<!-- REPOSTS GRID -->
<div id="reposts" class="dropdown-section" style="display:none;">
    <div class="grid">

<?php if(!$reposts || $reposts->num_rows === 0): ?>

    <div style="
        grid-column: 1/-1;
        text-align: center;
        padding: 40px 20px;
        color: #aaa;
        font-size: 0.95rem;">
        <i class="ri-repeat-line"
           style="font-size: 2.5rem; display:block; margin-bottom:10px; color:#FF4C4C;"></i>
        No reposts yet
    </div>

<?php else: ?>

<script>var repostFeed = [];</script>

<?php while($row = $reposts->fetch_assoc()): ?>

<?php
$created = strtotime($row['created_at'] ?? 'now');
$now = time();
$diff = $now - $created;

$is_new = ($diff <= 86400);
$is_fading = ($diff > 86400 && $diff <= 172800);

$safe_caption  = addslashes(htmlspecialchars($row['caption']));
$safe_username = addslashes(htmlspecialchars($row['creator']));
$safe_pic      = addslashes($row['creator_pic'] ?: 'uploads/default.jpg');
$safe_file     = addslashes($row['file_name']);
$safe_time     = addslashes($row['created_at'] ?? '');
$is_you        = ($current_user == $row['uploaded_by']) ? 'true' : 'false';

$r_modal_file = ($row['type'] === 'text' || empty($row['file_name']) || $row['file_name'] === '0')
    ? 'uploads/Text.jpg'
    : 'uploads/'.$row['file_name'];
$safe_r_modal_file = addslashes($r_modal_file);
?>

<?php
$r_like_count   = $conn->query("SELECT COUNT(*) as c FROM likes WHERE meme_id=".(int)$row['id'])->fetch_assoc()['c'];
$r_repost_count = $conn->query("SELECT COUNT(*) as c FROM reposts WHERE meme_id=".(int)$row['id'])->fetch_assoc()['c'];
$r_fav_count    = $conn->query("SELECT COUNT(*) as c FROM favorites WHERE meme_id=".(int)$row['id'])->fetch_assoc()['c'];
$r_liked        = $conn->query("SELECT id FROM likes WHERE meme_id=".(int)$row['id']." AND user_id=$current_user")->num_rows > 0;
$r_reposted     = $conn->query("SELECT id FROM reposts WHERE meme_id=".(int)$row['id']." AND user_id=$current_user")->num_rows > 0;
$r_faved        = $conn->query("SELECT id FROM favorites WHERE meme_id=".(int)$row['id']." AND user_id=$current_user")->num_rows > 0;
?>
<script>
repostFeed.push({
    id:          <?= (int)$row['id'] ?>,
    file:        '<?= $safe_r_modal_file ?>',
    type:        '<?= addslashes($row['type']) ?>',
    pic:         '<?= $safe_pic ?>',
    userId:      <?= (int)$row['uploaded_by'] ?>,
    username:    '<?= $safe_username ?>',
    caption:     '<?= $safe_caption ?>',
    time:        '<?= $safe_time ?>',
    isYou:       <?= $is_you ?>,
    likeCount:   <?= (int)$r_like_count ?>,
    repostCount: <?= (int)$r_repost_count ?>,
    favCount:    <?= (int)$r_fav_count ?>,
    liked:       <?= $r_liked    ? 'true' : 'false' ?>,
    reposted:    <?= $r_reposted ? 'true' : 'false' ?>,
    faved:       <?= $r_faved    ? 'true' : 'false' ?>
});
</script>

<div class="meme-card 
    <?= $is_new ? 'is-new' : '' ?> 
    <?= $is_fading ? 'is-fading' : '' ?>">

    <!-- TOP BAR -->
    <div class="top-bar">
        <img src="<?= $row['creator_pic'] ?: 'uploads/default.jpg'; ?>"
             onclick="window.location.href='profile.php?user_id=<?= $row['uploaded_by']; ?>'">
        <div class="username"
             onclick="window.location.href='profile.php?user_id=<?= $row['uploaded_by']; ?>'">
            <?= ($current_user == $row['uploaded_by']) ? 'You' : htmlspecialchars($row['creator']); ?>
        </div>
    </div>

    <!-- MEDIA -->
    <div class="media-box">
        <?php if($row['type'] == "image"): ?>
            <img src="uploads/<?= $safe_file ?>"
                 onclick="openMemeModal('<?= $safe_r_modal_file ?>','<?= $row['type'] ?>','<?= $safe_caption ?>','<?= $safe_pic ?>','<?= $safe_username ?>',<?= $row['uploaded_by'] ?>,<?= $is_you ?>,'repostFeed')">

        <?php elseif($row['type'] == "video"): ?>
            <video src="uploads/<?= $safe_file ?>" autoplay muted loop
                   onclick="openMemeModal('<?= $safe_r_modal_file ?>','<?= $row['type'] ?>','<?= $safe_caption ?>','<?= $safe_pic ?>','<?= $safe_username ?>',<?= $row['uploaded_by'] ?>,<?= $is_you ?>,'repostFeed')">
            </video>

        <?php else: ?>
            <img src="uploads/Text.jpg"
                 onclick="openMemeModal('uploads/Text.jpg','image','<?= $safe_caption ?>','<?= $safe_pic ?>','<?= $safe_username ?>',<?= $row['uploaded_by'] ?>,<?= $is_you ?>,'repostFeed')">
        <?php endif; ?>

        <?php if($diff <= 172800): ?>
            <div class="new-badge">NEW</div>
        <?php endif; ?>
    </div>

    <!-- ICON BAR -->
    <div class="icon-bar">
        <a href="download.php?file=<?= urlencode($row['file_name']); ?>" title="Download">
            <i class="ri-download-line"></i>
        </a>
    </div>

    <!-- CAPTION -->
    <div class="caption">
        <i class="ri-repeat-line" style="color:#FF4C4C;"></i>
        <?= htmlspecialchars($row['caption']); ?>
    </div>

</div>

<?php endwhile; ?>

<?php endif; ?>

    </div>
</div>

<!-- TAGS GRID -->
<div id="tags" class="dropdown-section" style="display:none;">
    <div class="grid">

    <?php if(!$tagged_posts || $tagged_posts->num_rows === 0): ?>

        <div style="
            grid-column: 1/-1; 
            text-align: center; 
            padding: 40px 20px;
            color: #aaa;
            font-size: 0.95rem;">
            <i class="ri-account-pin-box-line" 
               style="font-size: 2.5rem; display:block; margin-bottom:10px; color:#FF4C4C;"></i>
            No tagged posts yet
        </div>

    <?php else: ?>

    <script>var tagsFeed = [];</script>

    <?php while($row = $tagged_posts->fetch_assoc()): ?>

    <?php
    $created  = strtotime($row['created_at']);
    $now      = time();
    $diff     = $now - $created;

    $is_new    = ($diff <= 86400);
    $is_fading = ($diff > 86400 && $diff <= 172800);

    $safe_caption  = addslashes(htmlspecialchars($row['caption']));
    $safe_username = addslashes(htmlspecialchars($row['username']));
    $safe_pic      = addslashes($row['uploader_pic'] ?: 'uploads/default.jpg');
    $safe_file     = addslashes($row['file_name']);
    $safe_time     = addslashes($row['created_at']);
    $is_you        = ($current_user == $row['uploaded_by']) ? 'true' : 'false';

    $t_modal_file = ($row['type'] === 'text' || empty($row['file_name']) || $row['file_name'] === '0')
        ? 'uploads/Text.jpg'
        : 'uploads/'.$row['file_name'];
    $safe_t_modal_file = addslashes($t_modal_file);
    ?>

    <?php
    $t_like_count   = $conn->query("SELECT COUNT(*) as c FROM likes WHERE meme_id=".(int)$row['id'])->fetch_assoc()['c'];
    $t_repost_count = $conn->query("SELECT COUNT(*) as c FROM reposts WHERE meme_id=".(int)$row['id'])->fetch_assoc()['c'];
    $t_fav_count    = $conn->query("SELECT COUNT(*) as c FROM favorites WHERE meme_id=".(int)$row['id'])->fetch_assoc()['c'];
    $t_liked        = $conn->query("SELECT id FROM likes WHERE meme_id=".(int)$row['id']." AND user_id=$current_user")->num_rows > 0;
    $t_reposted     = $conn->query("SELECT id FROM reposts WHERE meme_id=".(int)$row['id']." AND user_id=$current_user")->num_rows > 0;
    $t_faved        = $conn->query("SELECT id FROM favorites WHERE meme_id=".(int)$row['id']." AND user_id=$current_user")->num_rows > 0;
    ?>
    <script>
    tagsFeed.push({
        id:          <?= (int)$row['id'] ?>,
        file:        '<?= $safe_t_modal_file ?>',
        type:        '<?= addslashes($row['type']) ?>',
        pic:         '<?= $safe_pic ?>',
        userId:      <?= (int)$row['uploaded_by'] ?>,
        username:    '<?= $safe_username ?>',
        caption:     '<?= $safe_caption ?>',
        time:        '<?= $safe_time ?>',
        isYou:       <?= $is_you ?>,
        likeCount:   <?= (int)$t_like_count ?>,
        repostCount: <?= (int)$t_repost_count ?>,
        favCount:    <?= (int)$t_fav_count ?>,
        liked:       <?= $t_liked    ? 'true' : 'false' ?>,
        reposted:    <?= $t_reposted ? 'true' : 'false' ?>,
        faved:       <?= $t_faved    ? 'true' : 'false' ?>
    });
    </script>

    <div class="meme-card 
        <?= $is_new    ? 'is-new'    : '' ?> 
        <?= $is_fading ? 'is-fading' : '' ?>">

        <!-- TOP BAR -->
        <div class="top-bar">
            <img src="<?= $row['uploader_pic'] ?: 'uploads/default.jpg'; ?>"
                 onclick="window.location.href='profile.php?user_id=<?= $row['uploaded_by']; ?>'">
            <div class="username"
                 onclick="window.location.href='profile.php?user_id=<?= $row['uploaded_by']; ?>'">
                <?= htmlspecialchars($row['username']); ?>
            </div>
        </div>

        <!-- MEDIA -->
        <div class="media-box">
            <?php if($row['type'] == "image"): ?>
                <img src="uploads/<?= $safe_file ?>"
                     onclick="openMemeModal('<?= $safe_t_modal_file ?>','<?= $row['type'] ?>','<?= $safe_caption ?>','<?= $safe_pic ?>','<?= $safe_username ?>',<?= $row['uploaded_by'] ?>,<?= $is_you ?>,'tagsFeed')">

            <?php elseif($row['type'] == "video"): ?>
                <video src="uploads/<?= $safe_file ?>" autoplay muted loop
                       onclick="openMemeModal('<?= $safe_t_modal_file ?>','<?= $row['type'] ?>','<?= $safe_caption ?>','<?= $safe_pic ?>','<?= $safe_username ?>',<?= $row['uploaded_by'] ?>,<?= $is_you ?>,'tagsFeed')">
                </video>

            <?php else: ?>
                <img src="uploads/Text.jpg"
                     onclick="openMemeModal('uploads/Text.jpg','image','<?= $safe_caption ?>','<?= $safe_pic ?>','<?= $safe_username ?>',<?= $row['uploaded_by'] ?>,<?= $is_you ?>,'tagsFeed')">
            <?php endif; ?>

            <?php if($diff <= 172800): ?>
                <div class="new-badge">NEW</div>
            <?php endif; ?>
        </div>

        <!-- ICON BAR -->
        <div class="icon-bar">
            <a href="download.php?file=<?= urlencode($row['file_name']); ?>" 
               title="Download">
                <i class="ri-download-line"></i>
            </a>
        </div>

        <!-- CAPTION -->
        <div class="caption">
            <i class="ri-account-pin-box-line" style="color:#FF4C4C;"></i>
            <?= htmlspecialchars($row['caption']); ?>
        </div>

    </div>

    <?php endwhile; ?>
    <?php endif; ?>

    </div>
</div>

<!-- FAVORITES GRID -->
<div id="favorites" class="dropdown-section" style="display:none;">
    <div class="grid">

    <?php if(!$favorites || $favorites->num_rows === 0): ?>

        <div style="
            grid-column: 1/-1; 
            text-align: center; 
            padding: 40px 20px;
            color: #aaa;
            font-size: 0.95rem;">
            <i class="ri-bookmark-line" 
               style="font-size: 2.5rem; display:block; margin-bottom:10px; color:#FF4C4C;"></i>
            No favorites yet
        </div>

    <?php else: ?>

    <script>var favsFeed = [];</script>

    <?php while($row = $favorites->fetch_assoc()): ?>

    <?php
    $created = strtotime($row['created_at']);
    $now = time();
    $diff = $now - $created;

    $is_new = ($diff <= 86400);
    $is_fading = ($diff > 86400 && $diff <= 172800);

    $safe_caption  = addslashes(htmlspecialchars($row['caption']));
    $safe_username = addslashes(htmlspecialchars($row['username']));
    $safe_pic      = addslashes($row['uploader_pic'] ?: 'uploads/default.jpg');
    $safe_file     = addslashes($row['file_name']);
    $safe_time     = addslashes($row['created_at']);
    $is_you        = ($current_user == $row['uploaded_by']) ? 'true' : 'false';

    $f_modal_file = ($row['type'] === 'text' || empty($row['file_name']) || $row['file_name'] === '0')
        ? 'uploads/Text.jpg'
        : 'uploads/'.$row['file_name'];
    $safe_f_modal_file = addslashes($f_modal_file);
    ?>

    <?php
    $f_like_count   = $conn->query("SELECT COUNT(*) as c FROM likes WHERE meme_id=".(int)$row['id'])->fetch_assoc()['c'];
    $f_repost_count = $conn->query("SELECT COUNT(*) as c FROM reposts WHERE meme_id=".(int)$row['id'])->fetch_assoc()['c'];
    $f_fav_count    = $conn->query("SELECT COUNT(*) as c FROM favorites WHERE meme_id=".(int)$row['id'])->fetch_assoc()['c'];
    $f_liked        = $conn->query("SELECT id FROM likes WHERE meme_id=".(int)$row['id']." AND user_id=$current_user")->num_rows > 0;
    $f_reposted     = $conn->query("SELECT id FROM reposts WHERE meme_id=".(int)$row['id']." AND user_id=$current_user")->num_rows > 0;
    $f_faved        = $conn->query("SELECT id FROM favorites WHERE meme_id=".(int)$row['id']." AND user_id=$current_user")->num_rows > 0;
    ?>
    <script>
    favsFeed.push({
        id:          <?= (int)$row['id'] ?>,
        file:        '<?= $safe_f_modal_file ?>',
        type:        '<?= addslashes($row['type']) ?>',
        pic:         '<?= $safe_pic ?>',
        userId:      <?= (int)$row['uploaded_by'] ?>,
        username:    '<?= $safe_username ?>',
        caption:     '<?= $safe_caption ?>',
        time:        '<?= $safe_time ?>',
        isYou:       <?= $is_you ?>,
        likeCount:   <?= (int)$f_like_count ?>,
        repostCount: <?= (int)$f_repost_count ?>,
        favCount:    <?= (int)$f_fav_count ?>,
        liked:       <?= $f_liked    ? 'true' : 'false' ?>,
        reposted:    <?= $f_reposted ? 'true' : 'false' ?>,
        faved:       <?= $f_faved    ? 'true' : 'false' ?>
    });
    </script>

    <div class="meme-card 
        <?= $is_new ? 'is-new' : '' ?> 
        <?= $is_fading ? 'is-fading' : '' ?>">

        <!-- TOP BAR -->
        <div class="top-bar">
            <img src="<?= $row['uploader_pic'] ?: 'uploads/default.jpg'; ?>"
                 onclick="window.location.href='profile.php?user_id=<?= $row['uploaded_by']; ?>'">
            <div class="username"
                 onclick="window.location.href='profile.php?user_id=<?= $row['uploaded_by']; ?>'">
                <?= htmlspecialchars($row['username']); ?>
            </div>
        </div>

        <!-- MEDIA -->
        <div class="media-box">
            <?php if($row['type'] == "image"): ?>
                <img src="uploads/<?= $safe_file ?>"
                     onclick="openMemeModal('<?= $safe_f_modal_file ?>','<?= $row['type'] ?>','<?= $safe_caption ?>','<?= $safe_pic ?>','<?= $safe_username ?>',<?= $row['uploaded_by'] ?>,<?= $is_you ?>,'favsFeed')">

            <?php elseif($row['type'] == "video"): ?>
                <video src="uploads/<?= $safe_file ?>" autoplay muted loop
                       onclick="openMemeModal('<?= $safe_f_modal_file ?>','<?= $row['type'] ?>','<?= $safe_caption ?>','<?= $safe_pic ?>','<?= $safe_username ?>',<?= $row['uploaded_by'] ?>,<?= $is_you ?>,'favsFeed')">
                </video>

            <?php else: ?>
                <img src="uploads/Text.jpg"
                     onclick="openMemeModal('uploads/Text.jpg','image','<?= $safe_caption ?>','<?= $safe_pic ?>','<?= $safe_username ?>',<?= $row['uploaded_by'] ?>,<?= $is_you ?>,'favsFeed')">
            <?php endif; ?>

            <?php if($diff <= 172800): ?>
                <div class="new-badge">NEW</div>
            <?php endif; ?>
        </div>

        <!-- ICON BAR -->
        <div class="icon-bar">
            <a href="download.php?file=<?= urlencode($row['file_name']); ?>" title="Download">
                <i class="ri-download-line"></i>
            </a>
        </div>

        <!-- CAPTION -->
        <div class="caption">
            <i class="ri-bookmark-line" style="color:#FF4C4C;"></i>
            <?= htmlspecialchars($row['caption']); ?>
        </div>

    </div>

    <?php endwhile; ?>
    <?php endif; ?>

    </div>
</div>


<!-- MESSAGES -->
<div id="messages" class="dropdown-section">

<?php if($current_user == $profile_id && $messages->num_rows > 0): ?>

    <?php while($m = $messages->fetch_assoc()): ?>

    <?php
        // unread messages from them
        $has_unread = (
            $m['sender_id'] != $current_user &&
            isset($m['unread_count']) &&
            $m['unread_count'] > 0
        );

        // show seen if YOU sent the last message and they saw it
        $show_seen = (
            $m['sender_id'] == $current_user &&
            isset($m['seen']) &&
            $m['seen'] == 1
        );
    ?>

    <div class="message-user"
         onclick="window.location.href='messages.php?user=<?php echo $m['id']; ?>'">

        <!-- PROFILE PIC -->
        <img src="<?php echo $m['profile_pic'] ?? 'uploads/default.jpg'; ?>"
             onerror="this.src='uploads/default.jpg'">

        <!-- NAME + LAST MESSAGE -->
        <div class="message-user-info">

            <div class="message-user-name">

                <svg xmlns="http://www.w3.org/2000/svg"
                     width="7"
                     height="7"
                     viewBox="0 0 24 24">
                    <circle cx="12" cy="12" r="12" fill="#FF0000"/>
                    <path d="M9 12l2 2 4-4"
                          stroke="white"
                          stroke-width="2.5"
                          fill="none"
                          stroke-linecap="round"
                          stroke-linejoin="round"/>
                </svg>

                <?php echo htmlspecialchars($m['username']); ?>

                <!-- SEEN -->
                <?php if($show_seen): ?>
                    <span style="
                        color:#227cd6;
                        font-size:10px;
                        font-weight:600;
                        margin-left:4px;
                        letter-spacing:.2px;
                    ">
                        Seen
                    </span>
                <?php endif; ?>

            </div>

            <div class="message-user-preview">
            <?php
                $preview    = $m['last_message'];
                $attachment = $m['last_attachment'] ?? '';
                $is_sent    = $m['sender_id'] == $current_user;
                $prefix     = $is_sent ? '<span style="color:#d62222;font-weight:500;">You:</span> ' : '';

                if (!empty($attachment)) {
                    $ext = strtolower(pathinfo($attachment, PATHINFO_EXTENSION));

                    if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
                        $media_label = '<i class="ri-image-line" style="color:#2231d6;font-size:13px;vertical-align:-2px;"></i> Photo';
                    } elseif (in_array($ext, ['mp4','webm','mov','avi'])) {
                        $media_label = '<i class="ri-video-line" style="color:#2231d6;font-size:13px;vertical-align:-2px;"></i> Video';
                    } elseif (in_array($ext, ['mp3','aac','ogg','wav'])) {
                        $media_label = '<i class="ri-music-2-line" style="color:#2231d6;font-size:13px;vertical-align:-2px;"></i> Audio';
                    } else {
                        $media_label = '<i class="ri-file-line" style="color:#2231d6;font-size:13px;vertical-align:-2px;"></i> File';
                    }

                    if (!empty(trim($preview)) && trim($preview) !== '[deleted]') {
                        echo $prefix . $media_label . ' · ' . htmlspecialchars(mb_substr($preview, 0, 30));
                    } else {
                        echo $prefix . $media_label;
                    }

                } elseif (trim($preview) === '[deleted]') {
                    echo $prefix . '<span style="color:rgba(255,255,255,.3);font-style:italic;">Message deleted</span>';
                } else {
                    echo $prefix . htmlspecialchars(mb_substr($preview, 0, 50)) . (mb_strlen($preview) > 50 ? '…' : '');
                }
            ?>
            </div>

        </div>

        <!-- UNREAD BADGE -->
        <?php if($has_unread): ?>
            <div class="message-unread-badge"></div>
        <?php endif; ?>

    </div>

    <?php endwhile; ?>

<?php elseif($current_user == $profile_id): ?>

    <div style="
        text-align:center;
        padding:40px 20px;
        color:#aaa;
        font-size:0.95rem;">
        
        <i class="ri-chat-1-line"
           style="font-size:2.5rem;display:block;margin-bottom:10px;color:#FF4C4C;"></i>

        No messages yet
    </div>

<?php endif; ?>

</div>


<!-- MY UPLOADS -->
<div id="uploads" class="dropdown-section" style="display:none;">
<div class="grid">

<?php if($my_uploads->num_rows > 0): ?>

<script>var uploadsFeed = [];</script>

<?php while($u = $my_uploads->fetch_assoc()): ?>

<?php
$created = strtotime($u['created_at']);
$now = time();
$diff = $now - $created;

$is_new = ($diff <= 86400);
$is_fading = ($diff > 86400 && $diff <= 172800);

$safe_caption  = addslashes(htmlspecialchars($u['caption']));
$safe_username = addslashes(htmlspecialchars($u['username']));
$safe_pic      = addslashes($u['uploader_pic'] ?: 'uploads/default.jpg');
$safe_file     = addslashes($u['file_name']);
$safe_time     = addslashes($u['created_at']);

$u_modal_file = ($u['type'] === 'text' || empty($u['file_name']) || $u['file_name'] === '0')
    ? 'uploads/Text.jpg'
    : 'uploads/'.$u['file_name'];
$safe_u_modal_file = addslashes($u_modal_file);
?>
<?php
$u_like_count   = $conn->query("SELECT COUNT(*) as c FROM likes WHERE meme_id=".(int)$u['id'])->fetch_assoc()['c'];
$u_repost_count = $conn->query("SELECT COUNT(*) as c FROM reposts WHERE meme_id=".(int)$u['id'])->fetch_assoc()['c'];
$u_fav_count    = $conn->query("SELECT COUNT(*) as c FROM favorites WHERE meme_id=".(int)$u['id'])->fetch_assoc()['c'];
$u_liked        = $conn->query("SELECT id FROM likes WHERE meme_id=".(int)$u['id']." AND user_id=$current_user")->num_rows > 0;
$u_reposted     = $conn->query("SELECT id FROM reposts WHERE meme_id=".(int)$u['id']." AND user_id=$current_user")->num_rows > 0;
$u_faved        = $conn->query("SELECT id FROM favorites WHERE meme_id=".(int)$u['id']." AND user_id=$current_user")->num_rows > 0;
?>
<script>
uploadsFeed.push({
    id:          <?= (int)$u['id'] ?>,
    file:        '<?= $safe_u_modal_file ?>',
    type:        '<?= addslashes($u['type']) ?>',
    pic:         '<?= $safe_pic ?>',
    userId:      <?= (int)$u['uploaded_by'] ?>,
    username:    '<?= $safe_username ?>',
    caption:     '<?= $safe_caption ?>',
    time:        '<?= $safe_time ?>',
    isYou:       true,
    likeCount:   <?= (int)$u_like_count ?>,
    repostCount: <?= (int)$u_repost_count ?>,
    favCount:    <?= (int)$u_fav_count ?>,
    liked:       <?= $u_liked    ? 'true' : 'false' ?>,
    reposted:    <?= $u_reposted ? 'true' : 'false' ?>,
    faved:       <?= $u_faved    ? 'true' : 'false' ?>
});
</script>

<div class="meme-card <?= $is_new ? 'is-new' : '' ?> <?= $is_fading ? 'is-fading' : '' ?>">

    <!-- TOP BAR -->
    <div class="top-bar">
        <img src="<?= $u['uploader_pic'] ?: 'uploads/default.jpg'; ?>">
        <div class="username">
            <?= ($current_user == $profile_id) ? "You" : htmlspecialchars($user['username']); ?>
        </div>
    </div>

    <!-- MEDIA -->
    <div class="media-box">

        <?php if($u['type'] == "image"): ?>

            <img src="uploads/<?= $safe_file ?>" 
                 onclick="openMemeModal('<?= $safe_u_modal_file ?>','<?= $u['type'] ?>','<?= $safe_caption ?>','<?= $safe_pic ?>','<?= $safe_username ?>',<?= $u['uploaded_by'] ?>,true,'uploadsFeed')">

        <?php elseif($u['type'] == "video"): ?>

            <video src="uploads/<?= $safe_file ?>" autoplay muted loop
                   onclick="openMemeModal('<?= $safe_u_modal_file ?>','<?= $u['type'] ?>','<?= $safe_caption ?>','<?= $safe_pic ?>','<?= $safe_username ?>',<?= $u['uploaded_by'] ?>,true,'uploadsFeed')">
            </video>

        <?php else: ?>

            <img src="uploads/Text.jpg" 
                 onclick="openMemeModal('uploads/Text.jpg','image','<?= $safe_caption ?>','<?= $safe_pic ?>','<?= $safe_username ?>',<?= $u['uploaded_by'] ?>,true,'uploadsFeed')">

        <?php endif; ?>

        <?php if($diff <= 172800): ?>
            <div class="new-badge">NEW</div>
        <?php endif; ?>

    </div>

    <!-- DELETE BUTTON -->
    <div class="icon-bar">
        <form method="POST" onsubmit="return confirm('Are you sure you want to delete this meme?');">
            <input type="hidden" name="meme_id" value="<?= $u['id']; ?>">
            <button type="submit" name="delete_meme" title="Delete">
                <i class="ri-delete-bin-line"></i>
            </button>
        </form>
    </div>

    <!-- CAPTION -->
    <div class="caption">
        <i class="ri-upload-2-line" style="color:#FF4C4C;"></i>
        <?= htmlspecialchars($u['caption']); ?>
    </div>

</div>

<?php endwhile; ?>

<?php else: ?>

<div style="
    grid-column:1/-1;
    text-align:center;
    padding:40px 20px;
    color:#aaa;
    font-size:0.95rem;">

    <i class="ri-upload-cloud-2-line"
       style="font-size:2.5rem;display:block;margin-bottom:10px;color:#FF4C4C;"></i>

    No uploads yet
</div>

<?php endif; ?>