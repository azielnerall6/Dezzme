<?php
session_start();
include 'config.php';

$current_user = $_SESSION['user_id'] ?? 0;
if (!$current_user) {
    header("Location: login.php");
    exit();
}

$group_id = $_GET['id'] ?? 0;

// Fetch group info
$group = $conn->query("
    SELECT g.*, u.username AS creator_name, u.profile_pic AS creator_pic 
    FROM groups g 
    JOIN users u ON g.created_by = u.id
    WHERE g.id = $group_id
")->fetch_assoc();

if (!$group) { echo "Group not found"; exit(); }

$is_owner = ($group['created_by'] == $current_user);

// ─── Check if current user is an admin ───────────────────────────────────────
$is_admin = false;
$admin_check = $conn->query("SELECT 1 FROM group_admins WHERE group_id=$group_id AND user_id=$current_user");
if ($admin_check && $admin_check->num_rows > 0) $is_admin = true;

// ─── OWNER ACTIONS ────────────────────────────────────────────────────────────

// Change group picture
if (isset($_POST['change_group_pic']) && $is_owner) {
    if (!empty($_FILES['group_pic']['name'])) {
        $file = time() . '_' . $_FILES['group_pic']['name'];
        move_uploaded_file($_FILES['group_pic']['tmp_name'], "uploads/" . $file);
        $conn->query("UPDATE groups SET group_pic='$file' WHERE id=$group_id");
    }
    header("Location: group.php?id=$group_id"); exit();
}

// Change group title
if (isset($_POST['change_group_title']) && $is_owner) {
    $new_title = $conn->real_escape_string($_POST['new_title']);
    $conn->query("UPDATE groups SET title='$new_title' WHERE id=$group_id");
    header("Location: group.php?id=$group_id"); exit();
}

// Change group description (owner only)
if (isset($_POST['change_group_desc']) && $is_owner) {
    $new_desc = $conn->real_escape_string($_POST['new_desc']);
    $conn->query("UPDATE groups SET description='$new_desc' WHERE id=$group_id");
    header("Location: group.php?id=$group_id"); exit();
}

// Toggle 18+ (owner only)
if (isset($_POST['toggle_adult']) && $is_owner) {
    $new_adult = ($group['is_adult'] ? 0 : 1);
    $conn->query("UPDATE groups SET is_adult=$new_adult WHERE id=$group_id");
    header("Location: group.php?id=$group_id"); exit();
}

// Save custom guidelines (owner only)
if (isset($_POST['save_guidelines']) && $is_owner) {
    $new_guidelines = $conn->real_escape_string($_POST['custom_guidelines']);
    $conn->query("UPDATE groups SET custom_guidelines='$new_guidelines' WHERE id=$group_id");
    header("Location: group.php?id=$group_id"); exit();
}

// Delete group (owner only)
if (isset($_POST['delete_group']) && $is_owner) {
    $conn->query("DELETE FROM group_members WHERE group_id=$group_id");
    $conn->query("DELETE FROM group_posts WHERE group_id=$group_id");
    $conn->query("DELETE FROM group_admins WHERE group_id=$group_id");
    $conn->query("DELETE FROM groups WHERE id=$group_id");
    header("Location: community.php"); exit();
}

// Appoint admin (owner only)
if (isset($_POST['appoint_admin']) && $is_owner) {
    $appoint_id = (int)$_POST['appoint_user_id'];
    $conn->query("INSERT IGNORE INTO group_admins (group_id, user_id) VALUES ($group_id, $appoint_id)");
    header("Location: group.php?id=$group_id"); exit();
}

// Remove admin (owner only)
if (isset($_POST['remove_admin']) && $is_owner) {
    $remove_admin_id = (int)$_POST['remove_admin_id'];
    $conn->query("DELETE FROM group_admins WHERE group_id=$group_id AND user_id=$remove_admin_id");
    header("Location: group.php?id=$group_id"); exit();
}

// Delete post (owner or admin)
if (isset($_POST['delete_post']) && ($is_owner || $is_admin)) {
    $del_post_id    = (int)$_POST['delete_post_id'];
    $del_meme_id    = (int)$_POST['delete_meme_id'];
    $conn->query("DELETE FROM group_posts WHERE id=$del_post_id AND group_id=$group_id");
    if ($is_owner) {
        $conn->query("DELETE FROM memes WHERE id=$del_meme_id");
    }
    header("Location: group.php?id=$group_id"); exit();
}

// Remove member (owner or admin)
if (isset($_POST['remove_member']) && ($is_owner || $is_admin)) {
    $rm_user = (int)$_POST['remove_member_id'];
    if ($is_admin && !$is_owner) {
        $target_is_admin = $conn->query("SELECT 1 FROM group_admins WHERE group_id=$group_id AND user_id=$rm_user")->num_rows > 0;
        if (!$target_is_admin && $rm_user != $group['created_by']) {
            $conn->query("DELETE FROM group_members WHERE group_id=$group_id AND user_id=$rm_user");
        }
    } else {
        $conn->query("DELETE FROM group_members WHERE group_id=$group_id AND user_id=$rm_user");
        $conn->query("DELETE FROM group_admins WHERE group_id=$group_id AND user_id=$rm_user");
    }
    header("Location: group.php?id=$group_id"); exit();
}

// ─── BLOCK RULES ──────────────────────────────────────────────────────────────
$blocked_by_owner = [];
$res = $conn->query("SELECT blocked_id FROM blocks WHERE blocker_id=".$group['created_by']);
while($row = $res->fetch_assoc()) $blocked_by_owner[] = $row['blocked_id'];
$blocked_sql = !empty($blocked_by_owner) ? implode(',', $blocked_by_owner) : '';

if (!empty($blocked_by_owner) && in_array($current_user, $blocked_by_owner)) {
    $conn->query("DELETE FROM group_members WHERE group_id=$group_id AND user_id=$current_user");
}

// ─── SORT ─────────────────────────────────────────────────────────────────────
$sort = $_GET['sort'] ?? 'newest';
switch($sort) {
    case 'favorited':  $order = "(SELECT COUNT(*) FROM favorites f WHERE f.meme_id=m.id) DESC"; break;
    case 'reposted':   $order = "(SELECT COUNT(*) FROM reposts r WHERE r.meme_id=m.id) DESC"; break;
    case 'liked':      $order = "(SELECT COUNT(*) FROM likes l WHERE l.meme_id=m.id) DESC"; break;
    case 'downloaded': $order = "(SELECT COUNT(*) FROM downloads d WHERE d.meme_id=m.id) DESC"; break;
    default:           $order = "gp.created_at DESC"; break;
}

// ─── MEMBERSHIP ───────────────────────────────────────────────────────────────
$is_member = $conn->query("SELECT 1 FROM group_members WHERE group_id=$group_id AND user_id=$current_user")->num_rows > 0;

if (isset($_POST['join_group'])) {
    $blocked = $conn->query("SELECT 1 FROM blocks WHERE blocker_id=".$group['created_by']." AND blocked_id=$current_user")->num_rows > 0;
    if (!$is_member && !$blocked) $conn->query("INSERT INTO group_members (group_id,user_id) VALUES ($group_id,$current_user)");
    header("Location: group.php?id=$group_id"); exit();
}
if (isset($_POST['leave_group'])) {
    $conn->query("DELETE FROM group_members WHERE group_id=$group_id AND user_id=$current_user");
    header("Location: group.php?id=$group_id"); exit();
}

// ─── AJAX HANDLERS ───────────────────────────────────────────────────────────
foreach (['ajax_like'=>'likes','ajax_repost'=>'reposts','ajax_favorite'=>'favorites'] as $action=>$table) {
    if (isset($_POST[$action])) {
        header('Content-Type: application/json');
        $meme_id = (int)($_POST['meme_id'] ?? 0);
        if (!$meme_id) { echo json_encode(['error'=>'Invalid']); exit(); }
        $check = $conn->query("SELECT 1 FROM $table WHERE user_id=$current_user AND meme_id=$meme_id");
        $key = $action === 'ajax_like' ? 'liked' : ($action === 'ajax_repost' ? 'reposted' : 'favorited');
        if ($check && $check->num_rows > 0) {
            $conn->query("DELETE FROM $table WHERE user_id=$current_user AND meme_id=$meme_id");
            $val = false;
        } else {
            $conn->query("INSERT INTO $table (user_id,meme_id) VALUES ($current_user,$meme_id)");
            $val = true;
        }
        $cnt = $conn->query("SELECT COUNT(*) as c FROM $table WHERE meme_id=$meme_id")->fetch_assoc()['c'] ?? 0;
        echo json_encode([$key=>$val,'count'=>$cnt]); exit();
    }
}

// ─── POST UPLOAD ──────────────────────────────────────────────────────────────
if (isset($_POST['upload_post'])) {
    if (!$is_member) die("Join first.");
    $caption = $conn->real_escape_string($_POST['caption'] ?? '');
    if (strpos($caption, '#') === false) {
        $upload_error = "You must include at least one hashtag (#) in your caption.";
    } else {
        $file_name = ''; $type = 'text';
        if (!empty($_FILES['post_file']['name'])) {
            $file_name = time().'_'.$_FILES['post_file']['name'];
            move_uploaded_file($_FILES['post_file']['tmp_name'], "uploads/".$file_name);
            $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            $type = in_array($ext,['mp4','webm','mov']) ? 'video' : 'image';
        }

        /* ── Mark as adult if this group is 18+ ── */
        $is_adult_post = !empty($group['is_adult']) ? 1 : 0;

        $conn->query("INSERT INTO memes (uploaded_by, file_name, caption, type, is_adult)
                      VALUES ($current_user, '$file_name', '$caption', '$type', $is_adult_post)");
        $meme_id = $conn->insert_id;
        $conn->query("INSERT INTO group_posts (group_id, posted_by, meme_id)
                      VALUES ($group_id, $current_user, $meme_id)");
        header("Location: group.php?id=$group_id"); exit();
    }
}

// ─── MEMBER COUNT ─────────────────────────────────────────────────────────────
$member_count = $conn->query("SELECT COUNT(*) as total FROM group_members WHERE group_id=$group_id")->fetch_assoc()['total'];

// ─── MEMBERS LIST ─────────────────────────────────────────────────────────────
$search_query = $_GET['search'] ?? '';
$search_safe  = $conn->real_escape_string($search_query);

$members_sql = "SELECT DISTINCT u.id, u.username, u.profile_pic FROM group_members gm JOIN users u ON u.id=gm.user_id WHERE gm.group_id=$group_id";
if (!empty($search_query)) $members_sql .= " AND u.username LIKE '%$search_safe%'";
if (!empty($blocked_by_owner)) $members_sql .= " AND u.id NOT IN ($blocked_sql)";
$members = $conn->query($members_sql);

// ─── ADMINS LIST ──────────────────────────────────────────────────────────────
$admins = [];
$admin_res = $conn->query("SELECT u.id, u.username, u.profile_pic FROM group_admins ga JOIN users u ON u.id=ga.user_id WHERE ga.group_id=$group_id");
while ($row = $admin_res->fetch_assoc()) $admins[$row['id']] = $row;

// ─── POSTS QUERY ──────────────────────────────────────────────────────────────
$post_sql = "
SELECT gp.id AS group_post_id, gp.created_at AS post_created,
       u.id AS user_id, u.username, u.profile_pic,
       m.id AS meme_id, m.file_name, m.caption, m.type,
       (SELECT COUNT(*) FROM likes l       WHERE l.meme_id=m.id) AS like_count,
       (SELECT COUNT(*) FROM reposts r     WHERE r.meme_id=m.id) AS repost_count,
       (SELECT COUNT(*) FROM favorites f   WHERE f.meme_id=m.id) AS favorites_count,
       (SELECT COUNT(*) FROM likes l       WHERE l.meme_id=m.id AND l.user_id=$current_user) AS is_liked,
       (SELECT COUNT(*) FROM reposts r     WHERE r.meme_id=m.id AND r.user_id=$current_user) AS is_reposted,
       (SELECT COUNT(*) FROM favorites f   WHERE f.meme_id=m.id AND f.user_id=$current_user) AS is_favorited
FROM group_posts gp
JOIN memes m ON m.id=gp.meme_id
JOIN users u ON u.id=gp.posted_by
WHERE gp.group_id=$group_id
";
if (!empty($search_query)) $post_sql .= " AND (m.caption LIKE '%$search_safe%' OR u.username LIKE '%$search_safe%')";
if (!empty($blocked_by_owner)) $post_sql .= " AND u.id NOT IN ($blocked_sql)";
$post_sql .= " ORDER BY $order";
$posts = $conn->query($post_sql);
if (!$posts) die("Posts query failed: ".$conn->error);

$blocked_by_creator = $conn->query("SELECT 1 FROM blocks WHERE blocker_id=".$group['created_by']." AND blocked_id=$current_user")->num_rows > 0;

// ─── 18+ CHECK ────────────────────────────────────────────────────────────────
$is_adult_group = !empty($group['is_adult']);
// Show age gate if: group is 18+, user is NOT a member, and user hasn't confirmed this session
$show_age_gate = $is_adult_group && !$is_member;
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
?>
<!DOCTYPE html>
<html>
<head>
<!-- PWA -->
<link rel="manifest" href="/Peura/manifest.json">
<meta name="theme-color" content="#FF2D2D">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Peura">
<link rel="apple-touch-icon" href="/Peura/Peura.jpg">

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
<title><?php echo htmlspecialchars($group['title']); ?> - Dezzme Pods</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<style>
* {
    box-sizing: border-box;
}

html, body {
    width: 100%;
    max-width: 100%;
    overflow-x: hidden;
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

html { scroll-behavior: smooth; }

body {
    background-color: var(--ink);
    background-image:
        radial-gradient(ellipse 80% 60% at 10% 0%, rgba(0,60,255,0.18) 0%, transparent 60%),
        radial-gradient(ellipse 40% 40% at 50% 50%, rgba(0,30,120,0.12) 0%, transparent 70%);
    color: var(--text);
    font-family: 'Poppins', sans-serif;
    min-height: 100vh;
    overflow-x: hidden;
}

/* ─── SIDEBAR ─────────────────────────────────────────────── */
.sidebar {
    position: fixed; left:0; top:0;
    width:100px; height:100vh;
    display:flex; flex-direction:column;
    gap:32px; padding:20px;
    border-radius:15px;
    background:transparent;
    backdrop-filter:blur(15px);
    box-shadow:0 0 40px rgba(0,0,0,0.6);
    z-index:100;
}
.sidebar .logo img {
    width:55px; height:55px; border-radius:15px;
    transition:.3s;
    animation:floatUpDown 2s ease-in-out infinite;
}
.sidebar .logo img:hover { box-shadow:0 0 15px white; transform:scale(1.1); }
@keyframes floatUpDown { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-5px)} }

.nav-icon {
    width:50px; height:30px;
    display:flex; align-items:center; justify-content:center;
    border-radius:15px; background:transparent;
    color:white; font-size:22px;
    text-decoration:none;
    transition:all 0.3s ease;
    cursor:pointer; position:relative; overflow:hidden;
    -webkit-tap-highlight-color:transparent;
}
.nav-icon:hover { transform:scale(1.12); box-shadow:0 0 15px rgba(255,255,255,0.6); }
.nav-icon::before {
    content:""; position:absolute;
    top:0; left:-90%; width:50%; height:100%;
    background:linear-gradient(120deg,transparent,rgba(255,255,255,0.25),transparent);
    transform:skewX(-20deg); transition:0.6s; border-radius:15px;
}
.nav-icon:hover::before { left:120%; }

/* ─── MAIN ────────────────────────────────────────────────── */
.main { margin-left:115px; padding:30px; }

/* ─── GROUP HEADER ────────────────────────────────────────── */
.group-header {
    background: transparent;
    backdrop-filter: none; /* ← remove the blur */
    border-radius: 35px;
    padding: clamp(16px,4vw,30px) clamp(18px,4vw,35px);
    border: transparent;
    display: flex; gap: clamp(14px,3vw,25px);
    align-items: center;
    margin-bottom: clamp(20px,5vw,40px);
    transition: all 0.3s ease;
    flex-wrap: wrap;
}
.group-header:hover { transform:translateY(-3px); box-shadow:0 20px 45px rgba(0,0,0,0.35); }

.group-header img.group-pic {
    width:clamp(80px,18vw,150px);
    height:clamp(80px,18vw,150px);
    border-radius:28px;
    object-fit:cover;
    border:4px solid rgba(6,115,240,0.8);
    box-shadow:0 4px 20px rgba(6,115,240,0.6),0 0 15px rgba(255,255,255,0.1) inset;
    transition:all 0.3s ease;
    cursor:pointer; flex-shrink:0;
}
.group-header img.group-pic:hover {
    transform:scale(1.08) rotate(1deg);
    box-shadow:0 6px 28px rgba(6,115,240,0.8),0 0 20px rgba(255,255,255,0.2) inset;
    border-color:rgba(6,115,240,1);
}

.group-header h1 {
    font-size: clamp(1rem, 4vw, 2.2rem);
    font-weight: 600; margin: 0;
    display: flex; align-items: center; gap: 10px;
    letter-spacing: -0.5px;
    background: transparent;
    border-radius: 20px;
    border: 1px solid rgba(255,255,255,0.15);
    box-shadow: 0 8px 25px rgba(0,0,0,0.25);
    padding: clamp(8px,2vw,12px) clamp(14px,3vw,24px);
    transition: all 0.3s ease; cursor: default; flex-wrap: wrap;
}
.group-header h1:hover {
    transform: translateY(-3px) scale(1.02);
    box-shadow: 0 12px 30px rgba(0,0,0,0.35), 0 0 15px rgba(255,255,255,0.1);
    border-color: rgba(255,255,255,0.25);
}
.group-header p {
    opacity: .7; font-weight: 300;
    font-size: clamp(12px,2.8vw,14px);
    margin: 10px 0 15px 0;
}

/* ─── MOBILE ───────────────────────────────────────── */
@media (max-width: 480px) {
    .group-header h1 {
        font-size: clamp(0.88rem, 4.8vw, 1.15rem);
        gap: 6px;
        border-radius: 13px;
        padding: 7px 13px;
        letter-spacing: -0.2px;
        box-shadow: 0 5px 16px rgba(0,0,0,0.2);
    }
    .group-header h1:hover {
        transform: translateY(-1px) scale(1.01);
    }
    .group-header p {
        font-size: clamp(11px, 3vw, 12.5px);
        margin: 7px 0 10px 0;
    }
}

/* ─── SMALL MOBILE ─────────────────────────────────── */
@media (max-width: 360px) {
    .group-header h1 {
        font-size: clamp(0.82rem, 5vw, 1rem);
        gap: 5px;
        border-radius: 11px;
        padding: 6px 11px;
        letter-spacing: 0px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.18);
    }
    .group-header h1:hover {
        transform: none;
    }
    .group-header p {
        font-size: clamp(10.5px, 3.2vw, 12px);
        margin: 6px 0 9px 0;
    }
}

/* ─── 18+ BADGE ───────────────────────────────────────────── */
.adult-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    border: 1.5px solid rgba(255, 45, 45, 0.7);
    border-radius: 10px;
    padding: 3px 12px 3px 8px;
    font-size: 12px;
    font-weight: 700;
    color: #FF2D2D;
    letter-spacing: 0.5px;
    margin-top: 6px;
    width: fit-content;
    animation: adultPulse 2.5s ease-in-out infinite;
}

@media (max-width: 480px) {
    .adult-badge {
        font-size: 10.5px;
        padding: 2px 9px 2px 6px;
        gap: 4px;
        border-radius: 10px;
        margin-top: 4px;
        letter-spacing: 0.3px;
    }
}

@media (max-width: 360px) {
    .adult-badge {
        font-size: 10px;
        padding: 1px 5px 1px 4px;
        gap: 3px;
        border-radius: 9px;
        margin-top: 4px;
        letter-spacing: 0.2px;
    }
}

/* ─── RED BORDER on all profile pics ────────────────────── */
img.profile-pic,
.member-card img,
.bottom-info img,
#modalText img,
#ccm-pic,
.creator-info img {
    border: 2px solid #FF2D2D !important;
}

/* ─── CREATOR INFO ────────────────────────────────────────── */
.creator-info {
    display:flex; align-items:center; gap:10px;
    padding:6px 15px; border-radius:20px;
    width:fit-content; background:transparent;
    transition:all 0.3s ease;
}
.creator-info img {
    width:28px; height:28px; border-radius:50%;
    box-shadow:0 2px 6px rgba(0,0,0,0.3);
    flex-shrink:0; object-fit:cover;
}
.creator-info span {
    font-size: clamp(11px,2.5vw,13px);
    font-weight: 500;
    color: rgba(255,255,255,0.95);
}

@media (max-width: 768px) {
    .creator-info span { font-size: 12px; }
}

@media (max-width: 480px) {
    .creator-info span { font-size: 11.5px; }
}

@media (max-width: 360px) {
    .creator-info span { font-size: 11px; }
}

@media (max-width: 320px) {
    .creator-info span { font-size: 10.5px; }
}
.creator-info:hover { transform:translateY(-2px) scale(1.02); }

/* ─── ACTION ROW ───────────────────────────────────────────── */
.action-row {
    display: flex; align-items: center;
    gap: clamp(8px,2vw,15px);
    margin-top: clamp(12px,3vw,20px);
    flex-wrap: wrap;
}

.join-btn, .leave-btn, .report-btn {
    margin-top: 0;
    padding: clamp(9px,2.5vw,12px) clamp(16px,4vw,28px);
    border-radius: 28px;
    border: 1px solid rgba(255,255,255,0.2);
    background: rgba(255,255,255,0.08);
    color: white; font-family: 'Poppins', sans-serif;
    font-weight: 600; font-size: clamp(12px,2.8vw,14px);
    cursor: pointer; transition: all 0.35s ease;
    display: flex; align-items: center; justify-content: center; gap: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.25), inset 0 1px 3px rgba(255,255,255,0.2);
    position: relative; overflow: hidden;
    -webkit-tap-highlight-color: transparent; white-space: nowrap;
}
.join-btn   { background: rgba(51,137,230,0.65);  border-color: rgba(0,123,255,0.65); }
.leave-btn  { background: rgba(195,96,96,0.85);   border-color: rgba(255,76,76,0.6); color: #f7eeee; }
.report-btn { background: rgba(255,255,255,0.08); border-color: rgba(255,255,255,0.2); }

.join-btn:hover, .leave-btn:hover, .report-btn:hover {
    transform: translateY(-3px) scale(1.08);
    box-shadow: 0 10px 25px rgba(0,0,0,0.28), inset 0 1px 4px rgba(255,255,255,0.25);
}
.join-btn:active, .leave-btn:active, .report-btn:active { transform: scale(0.95); }

.join-btn::before, .leave-btn::before, .report-btn::before {
    content: ""; position: absolute;
    top: 0; left: -90%; width: 50%; height: 100%;
    background: linear-gradient(120deg,transparent,rgba(255,255,255,0.3),transparent);
    transform: skewX(-20deg); transition: 0.6s; border-radius: 28px;
}
.join-btn:hover::before, .leave-btn:hover::before, .report-btn:hover::before { left: 120%; }

/* ─── TABLET ────────────────────────────────────────────────── */
@media (max-width: 768px) {
    .action-row { gap: 8px; margin-top: 12px; }

    .join-btn, .leave-btn, .report-btn {
        padding: 9px 18px;
        font-size: 12.5px;
        border-radius: 24px;
        gap: 7px;
    }
    .join-btn:hover, .leave-btn:hover, .report-btn:hover {
        transform: translateY(-2px) scale(1.05);
    }
}

/* ─── MOBILE ────────────────────────────────────────────────── */
@media (max-width: 480px) {
    .action-row { gap: 7px; margin-top: 10px; }

    .join-btn, .leave-btn, .report-btn {
        padding: 4px 10px;
        font-size: 12px;
        border-radius: 20px;
        gap: 6px;
        box-shadow: 0 3px 8px rgba(0,0,0,0.2), inset 0 1px 2px rgba(255,255,255,0.15);
    }
    .join-btn:hover, .leave-btn:hover, .report-btn:hover {
        transform: translateY(-1px) scale(1.04);
    }
    .join-btn:active, .leave-btn:active, .report-btn:active {
        transform: scale(0.93);
    }
}

@media (max-width: 360px) {
    .action-row { gap: 6px; margin-top: 9px; }

    .join-btn, .leave-btn, .report-btn {
        padding: 6px 10px;  /* ← was 7px 13px */
        font-size: 11.5px;
        border-radius: 18px;
        gap: 5px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.18);
    }
    .join-btn:hover, .leave-btn:hover, .report-btn:hover { transform: none; }
    .join-btn:active, .leave-btn:active, .report-btn:active { transform: scale(0.92); }
}

@media (max-width: 320px) {
    .action-row { gap: 5px; margin-top: 8px; }

    .join-btn, .leave-btn, .report-btn {
        padding: 3px 10px;  /* ← was 6px 11px */
        font-size: 11px;
        border-radius: 16px;
        gap: 4px;
        box-shadow: 0 2px 5px rgba(0,0,0,0.16);
    }
    .join-btn:hover, .leave-btn:hover, .report-btn:hover { transform: none; }
    .join-btn:active, .leave-btn:active, .report-btn:active { transform: scale(0.91); }
}

/* ─── MEMBER STAT ───────────────────────────────────────────── */
.member-stat {
    background: rgba(255,255,255,0.05);
    padding: clamp(5px,1.5vw,8px) clamp(10px,2.5vw,18px);
    border-radius: 20px;
    border: 1px dashed rgba(255,255,255,0.2);
    display: flex; align-items: center; gap: 8px;
    font-size: clamp(11px,2.5vw,13px);
}

@media (max-width: 768px) {
    .member-stat {
        padding: 5px 13px;
        font-size: 12px;
        border-radius: 16px;
        gap: 6px;
    }
}

@media (max-width: 480px) {
    .member-stat {
        padding: 4px 11px;
        font-size: 11.5px;
        border-radius: 14px;
        gap: 5px;
    }
}

@media (max-width: 360px) {
    .member-stat {
        padding: 3px 6.5px;
        font-size: 11px;
        border-radius: 12px;
        gap: 5px;
    }
}

@media (max-width: 320px) {
    .member-stat {
        padding: 3px 9px;
        font-size: 10.5px;
        border-radius: 11px;
        gap: 4px;
    }
}

/* ─── MEMBERS CAROUSEL ────────────────────────────────────── */
.members-carousel {
    display:flex; gap:clamp(12px,3vw,20px);
    overflow-x:auto; padding:10px 5px 20px 5px;
    scrollbar-width:none; -webkit-overflow-scrolling:touch;
    position: relative;
    z-index: 0;
}
.members-carousel::-webkit-scrollbar { display:none; }

.member-card {
    text-align:center; cursor:pointer;
    transition:0.3s;
    min-width:clamp(60px,15vw,80px); flex-shrink:0;
    position:relative;
    z-index: 0;
}
.member-card:hover { transform:translateY(-8px); }
.member-card img {
    width:clamp(42px,10vw,55px); height:clamp(42px,10vw,55px);
    border-radius:50%; object-fit:cover;
    padding:3px; background:rgba(0,0,0,0.3);
    transition:0.3s; display:block; margin:0 auto;
}
.member-card:hover img { box-shadow:0 0 15px var(--red); }
.member-card div {
    font-size:clamp(9px,2.2vw,11px); margin-top:8px;
    font-weight:500; color:rgba(255,255,255,0.8);
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    max-width:clamp(60px,15vw,80px);
}

.post-form {
    display: flex; gap: 10px; align-items: center; flex-wrap: wrap;
}

.custom-file-upload {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 10px 16px; border-radius: 20px;
    background: rgba(255,255,255,0.08);
    color: white; cursor: pointer; transition: 0.3s;
    font-size: 13px; font-weight: 500;
    border: 1px solid rgba(255,255,255,0.12);
    -webkit-tap-highlight-color: transparent;
    white-space: nowrap;
}
.custom-file-upload:hover {
    background: rgba(255,255,255,0.15);
    box-shadow: 0 0 12px rgba(255,255,255,0.3);
    transform: scale(1.03);
}
.custom-file-upload input { display: none; }

.file-name-display {
    font-size: 11px; color: rgba(255,255,255,0.5);
    margin-top: 4px; text-align: center;
}

.caption-wrap { flex: 1; min-width: 0; position: relative; }
.caption-wrap input[type="text"] {
    width: 100%; padding: 11px 16px;
    border-radius: 18px; border: 1px solid rgba(255,255,255,0.12);
    background: rgba(255,255,255,0.08);
    color: white; font-size: 14px; outline: none;
    transition: all 0.3s ease;
    font-family: 'Poppins', sans-serif;
}
.caption-wrap input[type="text"]::placeholder { color: rgba(255,255,255,0.45); }
.caption-wrap input[type="text"]:focus {
    border-color: rgba(255,76,76,0.85);
    box-shadow: 0 0 10px rgba(255,76,76,0.3);
    background: rgba(255,255,255,0.11);
}

.hashtag-hint {
    font-size: 11px; color: rgba(255,255,255,0.38);
    margin-top: 5px; padding-left: 6px;
}
.hashtag-hint.valid   { color: #4cff91; }
.hashtag-hint.invalid { color: #ff6b6b; }

.upload-btn {
    width: 46px; height: 46px; border-radius: 14px;
    border: none; background: rgba(255,45,45,0.75);
    color: white; cursor: pointer; transition: all 0.3s ease;
    flex-shrink: 0; display: flex; align-items: center;
    justify-content: center; font-size: 18px;
    -webkit-tap-highlight-color: transparent;
}
.upload-btn:hover {
    background: rgba(255,45,45,1);
    transform: scale(1.08);
    box-shadow: 0 0 16px rgba(255,45,45,0.5);
}

/* ─── POSTS VERTICAL LIST ─────────────────────────────────── */
.posts-list {
    display:grid;
    grid-template-columns: repeat(3, 1fr);
    gap:14px;
    margin-top:10px;
}

/* ─── POST CARD ───────────────────────────────────────────── */
.post-card {
    background:transparent;
    backdrop-filter:blur(15px);
    border:1px solid rgba(255,255,255,0.08);
    border-radius:20px;
    overflow:hidden;
    transition:all 0.3s ease;
    cursor:pointer;
    position:relative;
}
.post-card:hover { transform:translateY(-3px); box-shadow:0 12px 30px rgba(0,0,0,0.4); border-color:rgba(255,255,255,0.14); }

.post-card-header {
    display:flex; align-items:center; gap:10px;
    padding:12px 14px 10px;
}
.post-card-header img {
    width:36px; height:36px; border-radius:50%;
    object-fit:cover; flex-shrink:0;
    border:2px solid #FF2D2D;
}
.post-card-header .uname {
    font-size:13px; font-weight:600; color:#ff6b6b;
    cursor:pointer; text-decoration:none;
    display:flex; align-items:center; gap:5px;
}
.post-card-header .uname:hover { text-decoration:underline; }
.post-card-header .ts { font-size:11px; color:rgba(255,255,255,0.35); margin-left:auto; }

.post-media {
    width:100%; max-height:160px; object-fit:cover;
    display:block; background:#000;
}
video.post-media { max-height:160px; object-fit:cover; }
.post-caption {
    padding:10px 14px 4px;
    font-size:13px; color:rgba(255,255,255,0.75);
    line-height:1.5; word-break:break-word;
}
.post-caption .hashtag { color:#4dabff; }

/* ─── ENGAGEMENT ROW ──────────────────────────────────────── */
.engagement-row {
    display:flex; align-items:center; gap:6px;
    padding:10px 14px 12px;
}
.eng-btn {
    display:flex; align-items:center; gap:5px;
    padding:6px 12px; border-radius:14px;
    border:none; background:rgba(255,255,255,0.06);
    color:rgba(255,255,255,0.75); font-size:13px;
    cursor:pointer; transition:all 0.25s;
    font-family:'Poppins',sans-serif;
    -webkit-tap-highlight-color:transparent;
}
.eng-btn:hover { background:rgba(255,255,255,0.12); color:#fff; transform:scale(1.05); }
.eng-btn.liked   { color:#FF3B3B; }
.eng-btn.reposted{ color:#3bbaff; }
.eng-btn.saved   { color:#FFD700; }

.eng-btn-share {
    margin-left:auto;
    display:flex; align-items:center; gap:5px;
    padding:6px 12px; border-radius:14px;
    border:none; background:rgba(255,255,255,0.06);
    color:rgba(255,255,255,0.65); font-size:13px;
    cursor:pointer; transition:all 0.25s;
    -webkit-tap-highlight-color:transparent;
}
.eng-btn-share:hover { background:rgba(255,255,255,0.12); color:#fff; transform:scale(1.05); }

.mod-delete-btn {
    margin-left:4px;
    padding:6px 12px; border-radius:14px;
    border:none; background:rgba(255,45,45,0.15);
    color:#ff7a7a; font-size:12px;
    cursor:pointer; transition:all 0.25s;
    font-family:'Poppins',sans-serif;
    -webkit-tap-highlight-color:transparent;
}
.mod-delete-btn:hover { background:rgba(255,45,45,0.35); color:#fff; }

/* ─── MODAL ───────────────────────────────────────────────── */
#cardCaptionModal {
    display:none;
    position:fixed; inset:0;
    z-index:9999999;
    background:rgba(0,0,0,0.88);
    align-items:center; justify-content:center;
    padding:20px;
}
#cardCaptionModal.open { display:flex; }

.ccm-inner {
    background:rgba(21,20,20,0.92);
    border-radius:20px;
    width:100%; max-width:460px;
    overflow:hidden;
    border:1px solid rgba(255,255,255,0.08);
    box-shadow:0 30px 80px rgba(0,0,0,0.7);
    animation:modalIn 0.25s ease;
}
@keyframes modalIn { from{opacity:0;transform:scale(0.94)} to{opacity:1;transform:scale(1)} }

.ccm-header {
    display:flex; align-items:center; gap:10px;
    padding:14px 16px;
    border-bottom:1px solid rgba(255,255,255,0.07);
}
.ccm-header img {
    width:40px; height:40px; border-radius:50%;
    border:2px solid #FF2D2D; object-fit:cover;
    cursor:pointer; flex-shrink:0;
}
.ccm-username {
    color:#FF4C4C; font-weight:700; font-size:14px;
    cursor:pointer; display:flex; align-items:center; gap:5px;
}
.ccm-username:hover { text-decoration:underline; }
.ccm-close {
    margin-left:auto; background:none; border:none;
    color:rgba(255,255,255,0.5); font-size:22px;
    cursor:pointer; padding:4px; transition:0.2s;
    -webkit-tap-highlight-color:transparent;
}
.ccm-close:hover { color:#fff; transform:scale(1.15); }
#ccm-media { width:100%; max-height:58vh; overflow:hidden; background:#000; }
#ccm-media img,#ccm-media video { width:100%; max-height:58vh; object-fit:contain; display:block; }
#ccm-caption {
    padding:14px 16px;
    color:rgba(255,255,255,0.75); font-size:13px;
    line-height:1.6; word-break:break-word;
    white-space:pre-wrap; max-height:160px; overflow-y:auto;
    border-top:1px solid rgba(255,255,255,0.06);
}
#ccm-caption .hashtag { color:#4dabff; }
.ccm-footer {
    padding:12px 16px;
    border-top:1px solid rgba(255,255,255,0.06);
}
.ccm-footer button {
    width:100%; padding:10px;
    background:rgba(255,255,255,0.05);
    border:1px solid rgba(255,255,255,0.08);
    border-radius:12px; color:white;
    cursor:pointer; font-size:14px;
    font-family:'Poppins',sans-serif;
    transition:0.2s;
}
.ccm-footer button:hover { background:rgba(255,255,255,0.1); }

/* ─── OWNER PANEL ─────────────────────────────────────────── */
.owner-panel {
    background:rgba(0,87,255,0.07);
    border:1px solid rgba(0,87,255,0.2);
    border-radius:18px; padding:18px 20px;
    margin-bottom:22px;
}
.owner-panel h4 { margin:0 0 14px; font-size:14px; color:rgba(255,255,255,0.7); font-weight:600; }
.owner-panel .row { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:10px; align-items:center; }
.owner-input {
    flex:1; min-width:0; padding:9px 14px;
    border-radius:14px;
    border:1px solid rgba(255,255,255,0.12);
    background:rgba(255,255,255,0.07);
    color:#fff; font-size:13px; outline:none;
    font-family:'Poppins',sans-serif;
    transition:0.3s;
}
.owner-input:focus { border-color:rgba(255,76,76,0.7); box-shadow:0 0 8px rgba(255,76,76,0.25); }
.owner-input::placeholder { color:rgba(255,255,255,0.35); }
.owner-btn {
    padding:9px 18px; border-radius:14px; border:none;
    background:rgba(255,255,255,0.08); color:#fff;
    font-size:13px; font-weight:600; cursor:pointer;
    font-family:'Poppins',sans-serif; transition:0.25s;
    white-space:nowrap;
    -webkit-tap-highlight-color:transparent;
}
.owner-btn:hover { background:rgba(255,255,255,0.16); transform:scale(1.03); }
.owner-btn.danger { background:rgba(255,45,45,0.2); color:#ff7a7a; border:1px solid rgba(255,45,45,0.3); }
.owner-btn.danger:hover { background:rgba(255,45,45,0.5); color:#fff; }
.owner-label { display:flex; align-items:center; gap:8px; margin-bottom:8px; font-size:13px; color:rgba(255,255,255,0.55); }
.owner-divider { border:none; border-top:1px solid rgba(255,255,255,0.07); margin:14px 0; }
.custom-file-label {
    display:inline-flex; align-items:center; gap:7px;
    padding:9px 16px; border-radius:14px;
    background:rgba(255,255,255,0.07);
    border:1px solid rgba(255,255,255,0.12);
    color:#fff; font-size:13px; cursor:pointer;
    transition:0.25px; white-space:nowrap;
}
.custom-file-label:hover { background:rgba(255,255,255,0.14); }
.custom-file-label input { display:none; }

/* ─── ADMINS LIST — RED ───────────────────────────────────── */
.admin-list { display:flex; flex-wrap:wrap; gap:8px; margin-top:8px; }
.admin-chip {
    display:flex; align-items:center; gap:7px;
    background:rgba(255,45,45,0.1);
    border:1px solid rgba(255,45,45,0.35);
    border-radius:30px; padding:5px 12px 5px 7px;
    font-size:12px; font-weight:600; color:#FF2D2D;
}
.admin-chip img { width:24px; height:24px; border-radius:50%; border:1.5px solid #FF2D2D !important; object-fit:cover; }
.admin-chip form { display:inline; }
.admin-chip button {
    background:none; border:none;
    color:rgba(255,45,45,0.6); cursor:pointer;
    font-size:14px; padding:0 0 0 4px; transition:0.2s;
}
.admin-chip button:hover { color:#ff4c4c; }

/* ─── KEBAB MENU ──────────────────────────────────────────── */
.kebab-wrap {
    position: relative;
    display: inline-block;
}

.kebab-btn {
    border: none;
    background: transparent;
    border-radius: 50%;
    width: 36px; height: 36px;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; color: rgba(255,255,255,0.75);
    font-size: 18px; transition: 0.25s;
    -webkit-tap-highlight-color: transparent;
    flex-shrink: 0;
}
.kebab-btn:hover {
    background: rgba(255,255,255,0.1);
    color: #fff;
    transform: scale(1.08);
}

/* ─── DROPDOWN ─────────────────────────────────────── */
.kebab-dropdown {
    display: none;
    position: absolute;
    top: 42px; right: 0;
    z-index: 9999;
    width: clamp(240px, 75vw, 340px);
    background: rgba(12,12,28,0.98);
    border: 1px solid rgba(255,255,255,0.09);
    border-radius: 16px;
    box-shadow: 0 16px 50px rgba(0,0,0,0.65);
    padding: 14px 14px 11px;
    animation: kebabIn 0.2s ease;
}
@keyframes kebabIn {
    from { opacity:0; transform:translateY(-6px) scale(0.97); }
    to   { opacity:1; transform:translateY(0) scale(1); }
}
.kebab-dropdown.open { display: block; }

/* ─── TITLE ────────────────────────────────────────── */
.kebab-title {
    font-size: 12px; font-weight: 700;
    color: rgba(255,255,255,0.88);
    margin: 0 0 9px 0;
    display: flex; align-items: center; gap: 6px;
}

/* ─── RULES ────────────────────────────────────────── */
.kebab-rule {
    font-size: 11.5px;
    color: rgba(255,255,255,0.6);
    line-height: 1.55;
    padding: 6px 0;
    border-bottom: 1px solid rgba(255,255,255,0.05);
    display: flex; gap: 7px; align-items: flex-start;
}
.kebab-rule:last-child { border-bottom: none; }
.kebab-rule i {
    color: #ff6b6b; font-size: 13px;
    flex-shrink: 0; margin-top: 2px;
}

/* ─── DIVIDER ──────────────────────────────────────── */
.kebab-divider {
    border: none;
    border-top: 1px dashed rgba(255,255,255,0.08);
    margin: 10px 0 8px;
}

/* ─── CUSTOM LABEL ─────────────────────────────────── */
.kebab-custom-label {
    font-size: 11px; font-weight: 700;
    color: rgba(255,200,100,0.88);
    margin: 0 0 7px 0;
    display: flex; align-items: center; gap: 5px;
}

/* ─── CUSTOM TEXT ──────────────────────────────────── */
.kebab-custom-text {
    font-size: 11px;
    color: rgba(255,255,255,0.55);
    line-height: 1.55;
    white-space: pre-wrap;
    word-break: break-word;
    background: rgba(255,255,255,0.03);
    border-radius: 9px;
    padding: 7px 9px;
    border: 1px solid rgba(255,255,255,0.06);
}

.kebab-no-custom {
    font-size: 10.5px;
    color: rgba(255,255,255,0.28);
    font-style: italic;
}

/* ─── TABLET ───────────────────────────────────────── */
@media (max-width: 768px) {
    .kebab-btn { width: 34px; height: 34px; font-size: 17px; }

    .kebab-dropdown {
        width: clamp(210px, 72vw, 300px);
        padding: 12px 12px 10px;
        border-radius: 14px;
        top: 40px;
    }
    .kebab-title  { font-size: 11.5px; margin-bottom: 8px; gap: 5px; }
    .kebab-rule   { font-size: 11px; padding: 5px 0; gap: 6px; }
    .kebab-rule i { font-size: 12px; }
    .kebab-divider { margin: 8px 0 7px; }
    .kebab-custom-label { font-size: 10.5px; margin-bottom: 6px; }
    .kebab-custom-text  { font-size: 10.5px; padding: 6px 8px; border-radius: 8px; }
    .kebab-no-custom    { font-size: 10px; }
}

/* ─── MOBILE ───────────────────────────────────────── */
@media (max-width: 480px) {
    .kebab-btn { width: 32px; height: 32px; font-size: 16px; }

    .kebab-dropdown {
        width: clamp(190px, 78vw, 270px);
        padding: 10px 10px 8px;
        border-radius: 13px;
        top: 38px;
        right: -4px;
    }
    .kebab-title  { font-size: 11px; margin-bottom: 7px; }
    .kebab-rule   { font-size: 10.5px; padding: 5px 0; gap: 6px; }
    .kebab-rule i { font-size: 11.5px; }
    .kebab-divider { margin: 7px 0 6px; }
    .kebab-custom-label { font-size: 10px; margin-bottom: 5px; }
    .kebab-custom-text  { font-size: 10px; padding: 5px 7px; border-radius: 7px; }
    .kebab-no-custom    { font-size: 9.5px; }
}

/* ─── SMALL MOBILE ─────────────────────────────────── */
@media (max-width: 360px) {
    .kebab-btn { width: 30px; height: 30px; font-size: 15px; }

    .kebab-dropdown {
        width: clamp(175px, 82vw, 240px);
        padding: 9px 9px 7px;
        border-radius: 12px;
        top: 36px;
        right: -6px;
    }
    .kebab-title  { font-size: 10.5px; margin-bottom: 6px; }
    .kebab-rule   { font-size: 10px; padding: 4px 0; }
    .kebab-rule i { font-size: 11px; }
    .kebab-divider { margin: 6px 0 5px; }
    .kebab-custom-label { font-size: 9.5px; }
    .kebab-custom-text  { font-size: 9.5px; padding: 5px 6px; }
    .kebab-no-custom    { font-size: 9px; }
}

/* ─── GUIDELINES TEXTAREA (owner) ────────────────────────── */
.guidelines-textarea {
    width: 100%; min-height: 90px;
    padding: 10px 13px;
    border-radius: 13px;
    border: 1px solid rgba(255,255,255,0.12);
    background: rgba(255,255,255,0.07);
    color: #fff; font-size: 12px;
    font-family: 'Poppins', sans-serif;
    outline: none; resize: vertical;
    transition: 0.3s;
    line-height: 1.5;
}
.guidelines-textarea::placeholder { color: rgba(255,255,255,0.3); }
.guidelines-textarea:focus {
    border-color: rgba(255,76,76,0.7);
    box-shadow: 0 0 8px rgba(255,76,76,0.2);
}

/* ─── ADULT TOGGLE BTN ──────────────────────────────────────── */
.adult-toggle-btn {
    padding: 9px 18px; border-radius: 14px; border: none;
    font-size: 13px; font-weight: 600; cursor: pointer;
    font-family: 'Poppins', sans-serif; transition: 0.25s;
    white-space: nowrap; -webkit-tap-highlight-color: transparent;
}
.adult-toggle-btn.on {
    background: rgba(255,45,45,0.25);
    color: #ff7a7a;
    border: 1px solid rgba(255,45,45,0.45);
}
.adult-toggle-btn.on:hover { background: rgba(255,45,45,0.4); color: #fff; }
.adult-toggle-btn.off {
    background: rgba(255,255,255,0.08);
    color: rgba(255,255,255,0.7);
    border: 1px solid rgba(255,255,255,0.14);
}
.adult-toggle-btn.off:hover { background: rgba(255,255,255,0.15); color: #fff; }

/* ─── TABLET ────────────────────────────────────────────────── */
@media (max-width: 768px) {
    .adult-toggle-btn {
        padding: 7px 15px;
        font-size: 12px;
        border-radius: 12px;
    }
}

/* ─── MOBILE ────────────────────────────────────────────────── */
@media (max-width: 480px) {
    .adult-toggle-btn {
        padding: 6px 13px;
        font-size: 11.5px;
        border-radius: 11px;
    }
}

/* ─── SMALL MOBILE ──────────────────────────────────────────── */
@media (max-width: 360px) {
   .adult-toggle-btn {
        padding: 4px 8px;
        font-size: 10.5px;
        border-radius: 9px;
    }
}

/* ─── 320 ───────────────────────────────────────────────────── */
@media (max-width: 320px) {
    .adult-toggle-btn {
        padding: 4px 8px;
        font-size: 10.5px;
        border-radius: 9px;
    }
}

/* ─── AGE GATE OVERLAY ────────────────────────────────────── */
#ageGateOverlay {
    display: none;
    position: fixed; inset: 0;
    z-index: 99999999;
    background: rgba(0,0,0,0.93);
    backdrop-filter: blur(18px);
    align-items: center; justify-content: center;
    padding: 20px;
    flex-direction: column;
    text-align: center;
}
#ageGateOverlay.open { display: flex; }

.age-gate-inner {
    background: rgba(18,10,10,0.97);
    border: 1.5px solid rgba(255,45,45,0.45);
    border-radius: 24px;
    padding: clamp(24px,6vw,44px) clamp(22px,6vw,44px);
    max-width: 420px; width: 100%;
    box-shadow: 0 0 60px rgba(255,45,45,0.25), 0 30px 70px rgba(0,0,0,0.7);
    animation: ageGateIn 0.3s ease;
}
@keyframes ageGateIn {
    from { opacity:0; transform:scale(0.92) translateY(20px); }
    to   { opacity:1; transform:scale(1) translateY(0); }
}
.age-gate-icon {
    font-size: clamp(42px,10vw,64px);
    margin-bottom: 14px;
    display: block;
}
.age-gate-title {
    font-size: clamp(18px,4.5vw,24px);
    font-weight: 700; color: #fff;
    margin: 0 0 8px 0;
    line-height: 1.2;
}
.age-gate-sub {
    font-size: clamp(12px,2.8vw,14px);
    color: rgba(255,255,255,0.6);
    line-height: 1.6;
    margin: 0 0 24px 0;
}
.age-gate-warning {
    background: rgba(255,45,45,0.12);
    border: 1px solid rgba(255,45,45,0.3);
    border-radius: 12px;
    padding: 10px 14px;
    font-size: 12px;
    color: #ff8a8a;
    margin-bottom: 22px;
    line-height: 1.5;
    display: flex; align-items: center; gap: 8px;
    text-align: left;
}
.age-gate-warning i { font-size: 16px; flex-shrink: 0; }
.age-gate-btns {
    display: flex; gap: 10px; flex-wrap: wrap;
    justify-content: center;
}
.age-gate-yes {
    flex: 1; min-width: 120px;
    padding: 13px 20px; border-radius: 50px;
    border: none;
    background: rgba(255,45,45,0.75);
    color: #fff; font-size: 14px; font-weight: 700;
    cursor: pointer; font-family: 'Poppins', sans-serif;
    transition: 0.25s; -webkit-tap-highlight-color: transparent;
}
.age-gate-yes:hover { background: rgba(255,45,45,1); transform: scale(1.04); box-shadow: 0 0 20px rgba(255,45,45,0.5); }
.age-gate-no {
    flex: 1; min-width: 120px;
    padding: 13px 20px; border-radius: 50px;
    border: 1px solid rgba(255,255,255,0.15);
    background: rgba(255,255,255,0.07);
    color: rgba(255,255,255,0.75); font-size: 14px; font-weight: 600;
    cursor: pointer; font-family: 'Poppins', sans-serif;
    transition: 0.25s; -webkit-tap-highlight-color: transparent;
}
.age-gate-no:hover { background: rgba(255,255,255,0.13); color: #fff; }

/* ─── TABLET ────────────────────────────────────────────────── */
@media (max-width: 768px) {
    .age-gate-inner {
        padding: 22px 20px;
        border-radius: 20px;
        max-width: 380px;
    }
    .age-gate-icon    { font-size: 48px; margin-bottom: 12px; }
    .age-gate-title   { font-size: 20px; margin-bottom: 7px; }
    .age-gate-sub     { font-size: 13px; margin-bottom: 18px; }
    .age-gate-warning { font-size: 11.5px; padding: 9px 12px; margin-bottom: 18px; border-radius: 10px; }
    .age-gate-warning i { font-size: 15px; }
    .age-gate-btns    { gap: 8px; }
    .age-gate-yes, .age-gate-no {
        padding: 11px 18px;
        font-size: 13px;
        min-width: 110px;
    }
}

/* ─── MOBILE ────────────────────────────────────────────────── */
@media (max-width: 480px) {
    .age-gate-inner {
        padding: 18px 16px;
        border-radius: 18px;
    }
    .age-gate-icon    { font-size: 40px; margin-bottom: 10px; }
    .age-gate-title   { font-size: 17px; margin-bottom: 6px; }
    .age-gate-sub     { font-size: 12px; margin-bottom: 15px; line-height: 1.5; }
    .age-gate-warning { font-size: 11px; padding: 8px 10px; margin-bottom: 15px; gap: 6px; border-radius: 9px; }
    .age-gate-warning i { font-size: 14px; }
    .age-gate-btns    { gap: 7px; }
    .age-gate-yes, .age-gate-no {
        padding: 10px 16px;
        font-size: 12.5px;
        min-width: 100px;
        border-radius: 40px;
    }
}

/* ─── SMALL MOBILE ──────────────────────────────────────────── */
@media (max-width: 360px) {
    .age-gate-inner {
        padding: 16px 14px;
        border-radius: 16px;
    }
    .age-gate-icon    { font-size: 34px; margin-bottom: 9px; }
    .age-gate-title   { font-size: 15px; margin-bottom: 5px; }
    .age-gate-sub     { font-size: 11.5px; margin-bottom: 13px; }
    .age-gate-warning { font-size: 10.5px; padding: 7px 9px; margin-bottom: 13px; gap: 5px; border-radius: 8px; }
    .age-gate-warning i { font-size: 13px; }
    .age-gate-btns    { gap: 6px; }
    .age-gate-yes, .age-gate-no {
        padding: 9px 14px;
        font-size: 12px;
        min-width: 90px;
        border-radius: 36px;
    }
}

/* ─── 320 ───────────────────────────────────────────────────── */
@media (max-width: 320px) {
    .age-gate-inner {
        padding: 14px 12px;
        border-radius: 14px;
    }
    .age-gate-icon    { font-size: 30px; margin-bottom: 8px; }
    .age-gate-title   { font-size: 14px; margin-bottom: 5px; }
    .age-gate-sub     { font-size: 11px; margin-bottom: 11px; }
    .age-gate-warning { font-size: 10px; padding: 6px 8px; margin-bottom: 11px; gap: 5px; border-radius: 7px; }
    .age-gate-warning i { font-size: 12px; }
    .age-gate-btns    { gap: 5px; }
    .age-gate-yes, .age-gate-no {
        padding: 8px 12px;
        font-size: 11.5px;
        min-width: 80px;
        border-radius: 32px;
    }
}

/* ─── PARTICLES ───────────────────────────────────────────── */
#particles { position:fixed; width:100%; height:100%; overflow:hidden; top:0; left:0; z-index:-1; }
.particle {
    position:absolute; background:white; border-radius:50%;
    pointer-events:none; box-shadow:0 0 10px rgba(255,255,255,0.6);
    animation:floatParticle linear infinite;
}
@keyframes floatParticle {
    0%  { transform:translate(0,0) scale(1); opacity:0; }
    10% { opacity:1; }
    50% { transform:translate(var(--x-move),var(--y-move)) scale(1.2); }
    100%{ transform:translate(calc(var(--x-move)*-1),calc(var(--y-move)*-1)) scale(0.8); opacity:0; }
}

/* ─── MOBILE ──────────────────────────────────────────────── */
@media (max-width:768px) {
    body { padding-bottom:70px; }
    .sidebar {
        display:none;
    }
    .sidebar .logo { display:none; }
    .nav-icon {
        flex:1; height:60px; width:auto;
        border-radius:0; background:transparent;
        box-shadow:none; font-size:clamp(18px,5vw,22px);
        border:none; backdrop-filter:none;
    }
    .nav-icon:hover { transform:none; background:rgba(255,255,255,0.05); box-shadow:none; }
    .nav-icon::before { display:none; }
    .nav-icon:active { transform:scale(0.88); opacity:0.6; }
    .main { margin-left:0; padding:clamp(12px,3vw,20px); padding-bottom:80px; }
    .group-header { flex-direction:column; align-items:flex-start; border-radius:22px; padding:clamp(14px,4vw,20px); gap:12px; }
    .group-header img.group-pic { width:clamp(72px,18vw,100px); height:clamp(72px,18vw,100px); border-radius:18px; }
    .action-row { gap:8px; }
    .post-form { flex-wrap:wrap; gap:8px; }
    .ccm-inner { max-width:100%; border-radius:16px; }
    #ccm-media { max-height:50vh; }
    #ccm-media img,#ccm-media video { max-height:50vh; }
    .search-wrapper { max-width:100%; }
    .member-card { min-width:clamp(52px,13vw,70px); }
    .member-card img { width:clamp(38px,9.5vw,50px); height:clamp(38px,9.5vw,50px); }
    .post-filters { gap:6px; }
    .filter-btn { padding:6px 12px; font-size:11px; }
    .owner-panel { padding:14px 14px; }
    .kebab-dropdown { right: -10px; }
}
@media (max-width:380px) {
    .main { padding:10px; }
    .group-header { padding:12px; border-radius:16px; }
    .group-header img.group-pic { width:64px; height:64px; border-radius:14px; }
    .nav-icon { font-size:18px; }
}

/* Mobile back btn */
.mobile-back-btn { display:none; }
@media (max-width:768px) {
    .mobile-back-btn {
        display:flex; align-items:center; justify-content:center;
        top:14px; left:20px; z-index:9999;
        width:38px; height:38px;
        border:none; border-radius:50%;
        background:transparent; backdrop-filter:blur(14px);
        color:#fff; font-size:22px; cursor:pointer;
        transition:0.3s ease;
        box-shadow:0 6px 20px rgba(0,0,0,0.25);
    }
    .mobile-back-btn:hover { color:#FF4C4C; transform:scale(1.05); }
    .mobile-back-btn:active { transform:scale(0.94); }
}
@media (max-width:480px) {
    .mobile-back-btn { width:34px; height:34px; font-size:19px; }
}

@media (max-width:768px) {
    .posts-list {
        grid-template-columns: repeat(1, 1fr);
        gap:10px;
    }
    .post-media {
        max-height:150px;
    }
    video.post-media { max-height:150px; }
}

@media (max-width:380px) {
    .posts-list {
        grid-template-columns: repeat(1, 1fr);
        gap:8px;
    }
}

/* ─── MOBILE ONLY — post grid & cards ─────────────────────── */
@media (max-width: 768px) {
    .members-carousel { padding: 8px 12px 16px; }
    .upload-section   { margin: 0 12px 18px; }
    .post-filters     { padding: 0 12px; gap: 5px; }
    .search-wrapper   { margin: 0 12px 16px; }
    h3 { padding: 0 12px; }
    .owner-panel { margin: 0 12px 18px; }
}

@media (max-width: 480px) {
    .members-carousel { padding: 6px 10px 14px; }
    .upload-section   { margin: 0 10px 16px; }
    .post-filters     { padding: 0 10px; }
    .search-wrapper   { margin: 0 10px 14px; }
    h3                { padding: 0 10px; }
    .owner-panel      { margin: 0 10px 16px; }
}

/* ─── UPLOAD SECTION ──────────────────────────────────────── */
.upload-section {
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 22px;
    padding: 20px 22px;
    margin: 0 0 26px 0;
    backdrop-filter: blur(14px);
}

.upload-section h3 {
    margin: 0 0 14px 0;
    font-size: 15px;
    font-weight: 600;
    color: #fff;
    display: flex;
    align-items: center;
    gap: 7px;
}

.post-form {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    flex-wrap: wrap;
}

.custom-file-upload {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 10px 16px;
    border-radius: 20px;
    background: rgba(255,255,255,0.08);
    backdrop-filter: blur(10px);
    color: white;
    cursor: pointer;
    transition: 0.3s;
    font-size: 13px;
    font-weight: 500;
    border: 1px solid rgba(255,255,255,0.12);
    white-space: nowrap;
    flex-shrink: 0;
}
.custom-file-upload:hover {
    background: rgba(255,255,255,0.15);
    box-shadow: 0 0 12px rgba(255,255,255,0.2);
    transform: scale(1.02);
}
.custom-file-upload input { display: none; }

.caption-wrap {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.caption-wrap input[type="text"] {
    width: 100%;
    padding: 11px 16px;
    border-radius: 18px;
    border: 1px solid rgba(255,255,255,0.12);
    background: rgba(255,255,255,0.08);
    color: white;
    font-size: 13px;
    outline: none;
    transition: all 0.3s ease;
    backdrop-filter: blur(12px);
    font-family: 'Poppins', sans-serif;
}
.caption-wrap input[type="text"]::placeholder { color: rgba(255,255,255,0.38); }
.caption-wrap input[type="text"]:focus {
    border-color: rgba(255,76,76,0.85);
    box-shadow: 0 0 10px rgba(255,76,76,0.25);
    background: rgba(255,255,255,0.11);
}

.hashtag-hint {
    font-size: 11px;
    color: rgba(255,255,255,0.35);
    padding-left: 4px;
    transition: color 0.25s;
}
.hashtag-hint.valid   { color: #4cff91; }
.hashtag-hint.invalid { color: #ff6b6b; }

.upload-btn {
    width: 46px;
    height: 46px;
    border-radius: 14px;
    border: none;
    background: rgba(255,45,45,0.75);
    color: white;
    cursor: pointer;
    transition: all 0.3s ease;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    align-self: flex-start;
    -webkit-tap-highlight-color: transparent;
}
.upload-btn:hover {
    background: rgba(255,45,45,1);
    transform: scale(1.08);
    box-shadow: 0 0 16px rgba(255,45,45,0.5);
}

.file-name-display {
    font-size: 11px;
    color: rgba(255,255,255,0.4);
    margin-top: 8px;
    padding-left: 4px;
}

.upload-error {
    background: rgba(255,45,45,0.12);
    border: 1px solid rgba(255,45,45,0.35);
    border-radius: 12px;
    padding: 10px 14px;
    color: #ff7a7a;
    font-size: 12px;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 7px;
}

@media (max-width: 768px) {
    .upload-section {
        margin: 0 12px 20px;
        padding: 16px 16px;
        border-radius: 18px;
    }
    .upload-section h3 {
        font-size: 13px;
        margin-bottom: 12px;
    }
    .post-form { gap: 8px; flex-wrap: wrap; }
    .custom-file-upload { font-size: 11.5px; padding: 8px 13px; border-radius: 16px; gap: 5px; }
    .caption-wrap input[type="text"] { font-size: 12px; padding: 9px 13px; border-radius: 14px; }
    .hashtag-hint { font-size: 10px; }
    .upload-btn { width: 40px; height: 40px; font-size: 16px; border-radius: 12px; }
    .file-name-display { font-size: 10px; }
}

@media (max-width: 480px) {
    .upload-section { margin: 0 10px 16px; padding: 13px 13px; border-radius: 16px; }
    .upload-section h3 { font-size: 12px; margin-bottom: 10px; }
    .post-form { gap: 7px; }
    .custom-file-upload { font-size: 11px; padding: 7px 11px; border-radius: 14px; gap: 4px; }
    .caption-wrap input[type="text"] { font-size: 11px; padding: 8px 11px; border-radius: 12px; }
    .hashtag-hint { font-size: 9.5px; }
    .upload-btn { width: 36px; height: 36px; font-size: 15px; border-radius: 10px; }
}

@media (max-width: 360px) {
    .upload-section { margin: 0 8px 14px; padding: 11px 11px; border-radius: 14px; }
    .upload-section h3 { font-size: 11px; }
    .custom-file-upload { font-size: 10px; padding: 6px 10px; border-radius: 12px; }
    .caption-wrap input[type="text"] { font-size: 10.5px; padding: 7px 10px; }
    .hashtag-hint { font-size: 9px; }
    .upload-btn { width: 33px; height: 33px; font-size: 14px; border-radius: 9px; }
}

/* ─── OWNER PANEL ─────────────────────────────────────────── */
.owner-panel {
    background: rgba(0,87,255,0.07);
    border: 1px solid rgba(0,87,255,0.2);
    border-radius: 22px;
    padding: 20px 22px;
    margin: 0 0 22px 0;
}
.owner-panel h4 {
    margin: 0 0 16px;
    font-size: 14px;
    font-weight: 600;
    color: rgba(255,255,255,0.85);
    display: flex;
    align-items: center;
    gap: 7px;
}
.owner-label {
    display: flex;
    align-items: center;
    gap: 7px;
    margin-bottom: 8px;
    font-size: 12px;
    color: rgba(255,255,255,0.5);
}
.owner-divider {
    border: none;
    border-top: 1px solid rgba(255,255,255,0.07);
    margin: 16px 0;
}
.owner-panel .row {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 10px;
    align-items: center;
}
.owner-input {
    flex: 1;
    min-width: 0;
    padding: 9px 14px;
    border-radius: 14px;
    border: 1px solid rgba(255,255,255,0.12);
    background: rgba(255,255,255,0.07);
    color: #fff;
    font-size: 13px;
    outline: none;
    font-family: 'Poppins', sans-serif;
    transition: 0.3s;
}
.owner-input:focus { border-color: rgba(255,76,76,0.7); box-shadow: 0 0 8px rgba(255,76,76,0.2); }
.owner-input::placeholder { color: rgba(255,255,255,0.3); }
.owner-btn {
    padding: 9px 18px;
    border-radius: 14px;
    border: none;
    background: rgba(255,255,255,0.08);
    color: #fff;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    font-family: 'Poppins', sans-serif;
    transition: 0.25s;
    white-space: nowrap;
    flex-shrink: 0;
    -webkit-tap-highlight-color: transparent;
}
.owner-btn:hover { background: rgba(255,255,255,0.16); transform: scale(1.03); }
.owner-btn.danger { background: rgba(255,45,45,0.2); color: #ff7a7a; border: 1px solid rgba(255,45,45,0.3); }
.owner-btn.danger:hover { background: rgba(255,45,45,0.45); color: #fff; }
.custom-file-label {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 9px 16px;
    border-radius: 14px;
    background: rgba(255,255,255,0.07);
    border: 1px solid rgba(255,255,255,0.12);
    color: #fff;
    font-size: 13px;
    cursor: pointer;
    transition: 0.25s;
    white-space: nowrap;
    flex-shrink: 0;
}
.custom-file-label:hover { background: rgba(255,255,255,0.14); }
.custom-file-label input { display: none; }

@media (max-width: 768px) {
    .owner-panel { margin: 0 12px 20px; padding: 16px 16px; border-radius: 18px; }
    .owner-panel h4 { font-size: 13px; margin-bottom: 13px; }
    .owner-label { font-size: 11px; margin-bottom: 7px; gap: 6px; }
    .owner-input { font-size: 12px; padding: 8px 12px; border-radius: 12px; }
    .owner-btn { font-size: 12px; padding: 8px 14px; border-radius: 12px; }
    .custom-file-label { font-size: 12px; padding: 8px 13px; border-radius: 12px; gap: 5px; }
    .admin-chip { font-size: 11px; padding: 4px 10px 4px 6px; gap: 6px; }
    .admin-chip img { width: 21px; height: 21px; }
    .owner-divider { margin: 13px 0; }
}

@media (max-width: 480px) {
    .owner-panel { margin: 0 10px 16px; padding: 13px 13px; border-radius: 16px; }
    .owner-panel h4 { font-size: 12px; margin-bottom: 11px; }
    .owner-label { font-size: 10.5px; }
    .owner-input { font-size: 11px; padding: 7px 11px; border-radius: 11px; }
    .owner-btn { font-size: 11px; padding: 7px 12px; border-radius: 11px; }
    .custom-file-label { font-size: 11px; padding: 7px 11px; border-radius: 11px; }
    .admin-chip { font-size: 10px; padding: 3px 9px 3px 5px; }
    .admin-chip img { width: 19px; height: 19px; }
    .owner-divider { margin: 11px 0; }
}

@media (max-width: 360px) {
    .owner-panel { margin: 0 8px 14px; padding: 11px 11px; border-radius: 14px; }
    .owner-panel h4 { font-size: 11px; }
    .owner-label { font-size: 10px; }
    .owner-input { font-size: 10.5px; padding: 6px 10px; border-radius: 10px; }
    .owner-btn { font-size: 10.5px; padding: 6px 10px; border-radius: 10px; }
    .custom-file-label { font-size: 10px; padding: 6px 10px; border-radius: 10px; }
    .admin-chip { font-size: 9.5px; }
    .admin-chip img { width: 17px; height: 17px; }
}

/* ─── SEARCH WRAPPER ──────────────────────────────────────── */
.search-wrapper {
    display: flex;
    align-items: center;
    gap: 10px;
    background: rgba(255,255,255,0.06);
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 12px;
    padding: 6px 10px 6px 18px;
    transition: all 0.3s ease;
    margin-bottom: 22px;
}
.search-wrapper:focus-within {
    border-color: rgba(255,45,45,0.7);
    box-shadow: 0 0 14px rgba(255,45,45,0.2);
    background: rgba(255,255,255,0.09);
}
.search-wrapper i { color: rgba(255,255,255,0.4); font-size: 17px; flex-shrink: 0; }
.search-wrapper input {
    flex: 1; border: none;
    background: transparent;
    color: #fff;
    font-family: 'Poppins', sans-serif;
    font-size: 13px;
    outline: none;
    padding: 6px 0;
    min-width: 0;
}
.search-wrapper input::placeholder { color: rgba(255,255,255,0.32); }
.search-wrapper button {
    border: none;
    border-radius: 12px;
    background: rgba(252,41,41,0.79);
    color: #fff;
    width: 60px;
    height: 34px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    cursor: pointer;
    transition: all 0.3s;
    flex-shrink: 0;
}
.search-wrapper button i { color: #fff; }
.search-wrapper button:hover { background: rgba(255,45,45,1); transform: scale(1.08); }

@media (max-width: 768px) {
    .search-wrapper { margin: 0 12px 18px; padding: 5px 8px 5px 14px; border-radius: 10px; }
    .search-wrapper i { font-size: 15px; }
    .search-wrapper input { font-size: 12px; }
    .search-wrapper button { font-size: 10px; padding: 7px 14px; border-radius: 10px; }
}

@media (max-width: 480px) {
    .search-wrapper { margin: 0 10px 16px; padding: 4px 7px 4px 13px; border-radius:10px; }
    .search-wrapper input { font-size: 11px; }
    .search-wrapper button { font-size: 11px; padding: 6px 12px; border-radius: 10px; }
}

@media (max-width: 360px) {
    .search-wrapper { margin: 0 8px 14px; padding: 4px 6px 4px 12px; }
    .search-wrapper input { font-size: 10.5px; }
    .search-wrapper button { font-size: 10.5px; padding: 5px 10px; }
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

@media (max-height: 680px) {
    .break-angel { font-size: 45px; margin-bottom: 10px; }
    .break-title { font-size: 18px; }
    .break-subtitle { margin-bottom: 10px; font-size: 12px; }
    .break-code-label { margin-bottom: 8px; }
    .break-input { padding: 9px 16px; margin-bottom: 8px; }
    .break-return-btn { padding: 10px 0; margin-bottom: 10px; }
}

/* ─── POST FILTERS ──────────────────────────────────────────── */
.post-filters {
    margin-bottom: 20px;
    margin-top: 5px;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.filter-btn {
    padding: 7px 16px;
    border-radius: 20px;
    background: rgba(255,255,255,0.06);
    border: 1px solid rgba(255,255,255,0.1);
    color: rgba(255,255,255,0.7);
    font-size: 12px;
    font-family: 'Poppins', sans-serif;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.25s;
    white-space: nowrap;
    display: flex; align-items: center; gap: 5px;
    -webkit-tap-highlight-color: transparent;
}
.filter-btn:hover { background: rgba(255,255,255,0.12); color: #fff; }
.filter-btn.active {
    background: rgba(255,45,45,0.25);
    border-color: rgba(255,45,45,0.6);
    color: #ff7a7a;
    box-shadow: 0 0 10px rgba(255,45,45,0.2);
}

/* ─── TABLET ────────────────────────────────────────────────── */
@media (max-width: 768px) {
    .post-filters { gap: 6px; margin-bottom: 16px; }

    .filter-btn {
        padding: 6px 13px;
        font-size: 11.5px;
        border-radius: 16px;
        gap: 4px;
    }
}

/* ─── MOBILE ────────────────────────────────────────────────── */
@media (max-width: 480px) {
    .post-filters { gap: 5px; margin-bottom: 14px; }

    .filter-btn {
        padding: 5px 11px;
        font-size: 11px;
        border-radius: 14px;
        gap: 4px;
    }
    .filter-btn i { font-size: 12px; }
}

/* ─── SMALL MOBILE ──────────────────────────────────────────── */
@media (max-width: 360px) {
    .post-filters { gap: 5px; margin-bottom: 12px; }

    .filter-btn {
        padding: 4px 10px;
        font-size: 10.5px;
        border-radius: 12px;
        gap: 3px;
    }
    .filter-btn i { font-size: 11px; }
}

/* ─── 320 ───────────────────────────────────────────────────── */
@media (max-width: 320px) {
    .post-filters { gap: 4px; margin-bottom: 10px; }

    .filter-btn {
        padding: 4px 9px;
        font-size: 10px;
        border-radius: 11px;
        gap: 3px;
    }
    .filter-btn i { font-size: 10.5px; }
}

.group-header h1 svg {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
}

@media (max-width: 768px) {
    .group-header h1 svg { width: 13px; height: 13px; }
}

@media (max-width: 480px) {
    .group-header h1 svg { width: 11px; height: 11px; }
}

@media (max-width: 360px) {
    .group-header h1 svg { width: 9px; height: 9px; }
}

@media (max-width: 320px) {
    .group-header h1 svg { width: 9px; height: 9px; }
}
</style>
</head>
<body>
<div class="particles-container" id="particles"></div>

<!-- ─── AGE GATE OVERLAY ───────────────────────────────────── -->
<?php if ($show_age_gate): ?>
<div id="ageGateOverlay" class="open">
    <div class="age-gate-inner">
        <span class="age-gate-icon">🔞</span>
        <h2 class="age-gate-title">Adults Only Content</h2>
        <p class="age-gate-sub">This group has been marked as an <strong>18+ community</strong> by its creator. You must be 18 years of age or older to view and join this group.</p>
        <div class="age-gate-warning">
            <i class="ri-alert-line"></i>
            <span>This group contains sensitive and graphic content intended for mature audiences only. Viewer discretion is strongly advised.</span>
        </div>
        <div class="age-gate-btns">
            <button class="age-gate-yes" onclick="confirmAge()"><i class="ri-checkbox-circle-line"></i> Yes, I am 18+</button>
            <button class="age-gate-no" onclick="history.back()"><i class="ri-arrow-left-line"></i> No, Go Back</button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Sidebar -->
<div class="sidebar">
    <div class="logo" onclick="location.href='profile.php'"><img src="PEURA.jpg"></div>
    <a href="home.php" class="nav-icon" title="Home"><i class="ri-home-5-line" style="font-size:15px;color:white;"></i></a>
    <a class="glass nav-icon" href="text_feed.php"><i class="ri-send-plane-line" style="font-size:15px;color:white;"></i></a>
    <a href="search.php" class="nav-icon" title="Discover"><i class="ri-search-line" style="font-size:15px;color:white;"></i></a>
    <a href="upload.php" class="nav-icon" title="Upload"><i class="ri-add-line" style="font-size:15px;color:white;"></i></a>
    <a href="profile.php" class="nav-icon" title="Profile" style="position:relative;">
    <i class="ri-user-3-line" style="font-size:15px;color:white;"></i>
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
    <a href="community.php" class="nav-icon active" title="Community"><i class="ri-team-line" style="font-size:15px;color:white;"></i></a>
</div>

<div class="main">
<button class="mobile-back-btn" onclick="history.back()"><i class="ri-arrow-left-s-line"></i></button>

<!-- ─── GROUP HEADER ────────────────────────────────────────── -->
<div class="group-header">
<?php
$group_pic = $group['group_pic'] ?? '';
if (empty($group_pic)) $img_src = 'uploads/default_group.png';
elseif (strpos($group_pic,'uploads/') === 0) $img_src = $group_pic;
else $img_src = 'uploads/'.$group_pic;
?>
    <img class="group-pic" src="<?php echo $img_src; ?>" onerror="this.src='uploads/default_group.png';">

    <div style="flex:1; min-width:0;">
        <!-- Title row with kebab menu -->
        <div style="display:flex; align-items:flex-start; gap:10px; flex-wrap:wrap;">
            <h1 style="flex:1; min-width:0;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
                    <circle cx="12" cy="12" r="12" fill="#0673f0ff"/>
                    <path d="M9 12l2 2 4-4" stroke="white" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <?php echo htmlspecialchars($group['title']); ?>
            </h1>
            <!-- Kebab menu -->
            <div class="kebab-wrap" id="kebabWrap">
                <button class="kebab-btn" onclick="toggleKebab(event)" title="Group Guidelines">
                    <i class="ri-menu-line"></i>
                </button>
                <div class="kebab-dropdown" id="kebabDropdown">
                    <div class="kebab-title"><i class="ri-shield-check-line" style="color:#4dabff;"></i> Universal Group Rules</div>
                    <div class="kebab-rule"><i class="ri-focus-3-line"></i><span>Post only content relevant to the group's genre/theme — off-topic posts get removed immediately</span></div>
                    <div class="kebab-rule"><i class="ri-links-line"></i><span>Tag the original creator when remixing content</span></div>
                    <div class="kebab-rule"><i class="ri-spam-2-line"></i><span>No spam — repeated posting of the same meme is an instant removal</span></div>
                    <div class="kebab-rule"><i class="ri-user-forbid-line"></i><span>No impersonating other users or creators</span></div>
                    <div class="kebab-rule"><i class="ri-emotion-line"></i><span>Respect the group's tone — every group has its own culture</span></div>
                    <div class="kebab-rule">
                        <i class="ri-alarm-warning-line"></i>
                        <span><strong style="color:rgba(255,255,255,0.85);">Strike system:</strong><br>
                        • 1st offense → Warning<br>
                        • 2nd offense → Temporary removal<br>
                        • 3rd offense → Permanent block — you can <strong>NEVER</strong> rejoin that group or ANY group created by that creator, ever</span>
                    </div>
                    <hr class="kebab-divider">
                    <div class="kebab-custom-label"><i class="ri-file-text-line"></i> Creator's Additional Guidelines</div>
                    <?php if (!empty($group['custom_guidelines'])): ?>
                        <div class="kebab-custom-text"><?php echo nl2br(htmlspecialchars($group['custom_guidelines'])); ?></div>
                    <?php else: ?>
                        <div class="kebab-no-custom">No additional guidelines set by the creator.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- 18+ badge below group name -->
        <?php if ($is_adult_group): ?>
        <div class="adult-badge">
            <i class="ri-error-warning-fill"></i> 18+ Meme Group
        </div>
        <?php endif; ?>

        <p><?php echo htmlspecialchars($group['description'] ?? ''); ?></p>

        <div class="creator-info">
            <a href="profile.php?user_id=<?php echo $group['created_by']; ?>" style="display:flex;align-items:center;gap:10px;text-decoration:none;">
                <img class="profile-pic" src="<?php echo !empty($group['creator_pic']) ? $group['creator_pic'] : 'uploads/me.jpeg'; ?>" onerror="this.src='uploads/me.jpeg';" style="cursor:pointer;">
            </a>
            <span>Created by
                <svg xmlns="http://www.w3.org/2000/svg" width="8" height="8" viewBox="0 0 24 24">
                    <circle cx="12" cy="12" r="12" fill="#FF0000"/>
                    <path d="M9 12l2 2 4-4" stroke="white" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <?php echo htmlspecialchars($group['creator_name']); ?>
            </span>
        </div>

        <div class="action-row">
            <?php if ($check = $conn->query("SELECT * FROM group_members WHERE group_id=$group_id AND user_id=$current_user") and $check->num_rows == 0): ?>
                <form method="POST" onsubmit="<?php if($blocked_by_creator) echo 'alert(\'You cannot join – blocked by the creator.\'); return false;'; ?>">
                    <button name="join_group" class="join-btn"><i class="ri-group-line"></i> Join Pod</button>
                </form>
            <?php else: ?>
                <form method="POST"><button name="leave_group" class="leave-btn"><i class="ri-logout-box-line"></i> Leave</button></form>
                <button onclick="reportGroup()" class="report-btn"><i class="ri-flag-line"></i> Report</button>
            <?php endif; ?>
            <div class="member-stat">
                <i class="ri-team-line" style="color:#4dabff;"></i>
                <strong style="color:#4dabff;"><?php echo $member_count; ?></strong> Pod Members
            </div>
        </div>
    </div>
</div>

<!-- ─── OWNER / ADMIN PANEL ───────────────────────────────── -->
<?php if ($is_owner): ?>
<div class="owner-panel">
    <h4><i class="ri-shield-star-line" style="color:#FFD700;"></i> Owner Controls</h4>

    <form method="POST" enctype="multipart/form-data">
        <div class="owner-label"><i class="ri-image-edit-line"></i> Group Picture</div>
        <div class="row">
            <label class="custom-file-label">
                <i class="ri-upload-cloud-line"></i> Choose Image
                <input type="file" name="group_pic">
            </label>
            <button class="owner-btn" type="submit" name="change_group_pic">Save Pic</button>
        </div>
    </form>

    <hr class="owner-divider">

    <form method="POST">
        <div class="owner-label"><i class="ri-text"></i> Group Name</div>
        <div class="row">
            <input class="owner-input" type="text" name="new_title" placeholder="New group name" value="<?php echo htmlspecialchars($group['title']); ?>">
            <button class="owner-btn" type="submit" name="change_group_title">Rename</button>
        </div>
    </form>

    <form method="POST">
        <div class="owner-label"><i class="ri-align-left"></i> Description</div>
        <div class="row">
            <input class="owner-input" type="text" name="new_desc" placeholder="Update description..." value="<?php echo htmlspecialchars($group['description'] ?? ''); ?>">
            <button class="owner-btn" type="submit" name="change_group_desc">Update</button>
        </div>
    </form>

    <hr class="owner-divider">

    <!-- 18+ Toggle -->
    <div class="owner-label"><i class="ri-error-warning-line" style="color:#FF2D2D;"></i> 18+ Meme Group Toggle</div>
    <div class="row">
        <span style="font-size:12px; color:rgba(255,255,255,0.5); flex:1;">
            <?php echo $is_adult_group ? 'Currently: <strong style="color:#FF2D2D;">18+ Enabled</strong> — non-members will see an age confirmation gate before viewing.' : 'Currently: <strong style="color:rgba(255,255,255,0.4);">Not 18+</strong> — enable to mark this as adult content.'; ?>
        </span>
        <form method="POST" style="display:inline;">
            <button type="submit" name="toggle_adult" class="adult-toggle-btn <?php echo $is_adult_group ? 'on' : 'off'; ?>">
                <?php echo $is_adult_group ? '<i class="ri-toggle-fill"></i> Disable 18+' : '<i class="ri-toggle-line"></i> Enable 18+'; ?>
            </button>
        </form>
    </div>

    <hr class="owner-divider">

    <!-- Custom Guidelines -->
    <div class="owner-label"><i class="ri-file-edit-line" style="color:#4dabff;"></i> Additional Group Guidelines</div>
    <form method="POST">
        <div class="row" style="flex-direction:column; align-items:stretch;">
            <textarea class="guidelines-textarea" name="custom_guidelines" placeholder="Write your own additional rules for this group... (visible in the kebab menu ⋯ above)"><?php echo htmlspecialchars($group['custom_guidelines'] ?? ''); ?></textarea>
            <div style="display:flex; justify-content:flex-end; margin-top:8px;">
                <button class="owner-btn" type="submit" name="save_guidelines"><i class="ri-save-line"></i> Save Guidelines</button>
            </div>
        </div>
    </form>

    <hr class="owner-divider">

    <!-- Appoint Admin -->
    <div class="owner-label"><i class="ri-admin-line" style="color:#FF2D2D;"></i> Appoint Admin</div>
    <form method="POST">
        <div class="row">
            <select name="appoint_user_id" class="owner-input" style="cursor:pointer;">
                <option value="">— select a member —</option>
                <?php
                $mem_opts = $conn->query("SELECT u.id, u.username FROM group_members gm JOIN users u ON u.id=gm.user_id WHERE gm.group_id=$group_id AND gm.user_id != $current_user");
                while ($mo = $mem_opts->fetch_assoc()):
                    $already = isset($admins[$mo['id']]);
                ?>
                    <option value="<?php echo $mo['id']; ?>" <?php if($already) echo 'disabled'; ?>>
                        <?php echo htmlspecialchars($mo['username']); ?><?php if($already) echo ' (admin)'; ?>
                    </option>
                <?php endwhile; ?>
            </select>
            <button class="owner-btn" type="submit" name="appoint_admin">Appoint</button>
        </div>
    </form>

    <?php if (!empty($admins)): ?>
    <div class="owner-label" style="margin-top:10px;"><i class="ri-medal-line" style="color:#FF2D2D;"></i> Current Admins</div>
    <div class="admin-list">
        <?php foreach ($admins as $adm): ?>
        <div class="admin-chip">
            <img src="<?php echo !empty($adm['profile_pic']) ? $adm['profile_pic'] : 'uploads/me.jpeg'; ?>" onerror="this.src='uploads/me.jpeg';">
            <?php echo htmlspecialchars($adm['username']); ?>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="remove_admin_id" value="<?php echo $adm['id']; ?>">
                <button type="submit" name="remove_admin" title="Remove admin">×</button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <hr class="owner-divider">

    <form method="POST" onsubmit="return confirm('⚠️ Delete this group forever?')">
        <button class="owner-btn danger" type="submit" name="delete_group"><i class="ri-delete-bin-line"></i> Delete Group</button>
    </form>
</div>
<?php endif; ?>

<!-- ─── DISCUSSIONS LINK ───────────────────────────────────── -->
<?php
$disc_active = $conn->query("
    SELECT COUNT(*) as c FROM group_members gm
    JOIN users u ON u.id = gm.user_id
    WHERE gm.group_id = $group_id
    AND u.last_seen >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
")->fetch_assoc()['c'];
?>

<style>
.disc-link-row {
    display: flex;
    justify-content: flex-end;
    width: 100%;
    margin-bottom: 14px;
     padding-right: 10px; /* ← increase this to push more left */
}
.disc-link {
    color: #FF2D2D;
    font-family: 'Poppins', sans-serif;
    font-size: 14px;
    font-weight: 700;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 16px;
    border-radius: 20px;
    border: 1px solid rgba(255,45,45,0.35);
    background: rgba(255,45,45,0.08);
    transition: all 0.2s;
    white-space: nowrap;
}
.disc-link:hover { background: rgba(255,45,45,0.18); }
.disc-link .disc-active-badge {
    background: #2ecc71;
    color: #000;
    font-size: 10px;
    font-weight: 800;
    padding: 2px 7px;
    border-radius: 20px;
}
.disc-link .disc-inactive-text {
    color: rgba(255,255,255,0.3);
    font-size: 11px;
    font-weight: 400;
}

@media (max-width: 768px) {
    .disc-link-row { margin-bottom: 12px; padding-right: 20px; /* ← increase this to push more left */ }
    .disc-link { font-size: 13px; padding: 6px 14px; gap: 5px; border-radius: 18px; }
    .disc-link .disc-active-badge { font-size: 9px; padding: 2px 6px; }
    .disc-link .disc-inactive-text { font-size: 10.5px; }
}

@media (max-width: 480px) {
    .disc-link-row { margin-bottom: 10px;  padding-right: 20px; /* ← increase this to push more left */}
    .disc-link { font-size: 12px; padding: 5px 12px; gap: 5px; border-radius: 16px; }
    .disc-link .disc-active-badge { font-size: 9px; padding: 1px 6px; }
    .disc-link .disc-inactive-text { font-size: 10px; }
}

@media (max-width: 360px) {
    .disc-link-row { margin-bottom: 8px;  padding-right: 20px; /* ← increase this to push more left */}
    .disc-link { font-size: 11px; padding: 4px 8px; gap: 4px; border-radius: 11px; }
    .disc-link .disc-active-badge { font-size: 8px; padding: 1px 4px; }
    .disc-link .disc-inactive-text { font-size: 9px; }
}

</style>

<div class="disc-link-row">
    <a href="discussions.php?pod_id=<?= $group_id ?>" class="disc-link">
        <i class="ri-discuss-line"></i> Discussions
        <?php if ($disc_active > 0): ?>
            <span class="disc-active-badge"><?= $disc_active ?> active</span>
        <?php else: ?>
            <span class="disc-inactive-text">inactive</span>
        <?php endif; ?>
    </a>
</div>

<!-- ─── SEARCH ────────────────────────────────────────────── -->
<form method="GET" action="group.php">
    <input type="hidden" name="id" value="<?php echo $group_id; ?>">
    <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">
    <div class="search-wrapper">
        <i class="ri-search-line"></i>
        <input type="text" name="search" placeholder="Search members, posts, captions…" value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
        <button type="submit"><i class="ri-arrow-right-line"></i></button>
    </div>
</form>

<!-- ─── MEMBERS ───────────────────────────────────────────── -->
<h3 style="margin:0 0 12px; font-size:15px; font-weight:600;">Pod Members</h3>
<div class="members-carousel">
<?php
$members->data_seek(0);
while($m = $members->fetch_assoc()):
    $m_is_admin = isset($admins[$m['id']]);
?>
    <div class="member-card" onclick="location.href='profile.php?user_id=<?php echo $m['id']; ?>'">
        <?php if ($m_is_admin): ?><div class="admin-badge">★</div><?php endif; ?>
        <img class="profile-pic" src="<?php echo !empty($m['profile_pic']) ? $m['profile_pic'] : 'uploads/me.jpeg'; ?>" onerror="this.src='uploads/me.jpeg';">
        <div>
            <svg xmlns="http://www.w3.org/2000/svg" width="9" height="9" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="12" fill="#FF0000"/>
                <path d="M9 12l2 2 4-4" stroke="white" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <?php echo htmlspecialchars($m['username']); ?>
        </div>
        <?php if (($is_owner || $is_admin) && $m['id'] != $current_user && $m['id'] != $group['created_by']): ?>
        <?php if (!($is_admin && isset($admins[$m['id']]))): ?>
        <form method="POST" onclick="event.stopPropagation();" style="margin-top:5px;">
            <input type="hidden" name="remove_member_id" value="<?php echo $m['id']; ?>">
            <button type="submit" name="remove_member" style="background:rgba(255,45,45,0.2);border:none;border-radius:10px;color:#ff7a7a;font-size:10px;padding:3px 8px;cursor:pointer;" onclick="return confirm('Remove this member?')">Remove</button>
        </form>
        <?php endif; ?>
        <?php endif; ?>
    </div>
<?php endwhile; ?>
</div>

<!-- ─── POST UPLOAD ───────────────────────────────────────── -->
<?php if($is_member): ?>
<div class="upload-section">
    <h3><i class="ri-add-circle-line" style="color:#ff6b6b;"></i> Post to Pod</h3>
    <?php if (!empty($upload_error)): ?>
        <div class="upload-error"><i class="ri-error-warning-line"></i> <?php echo $upload_error; ?></div>
    <?php endif; ?>
    <form method="POST" enctype="multipart/form-data" class="post-form">
        <label class="custom-file-upload">
            <i class="ri-image-line"></i> Choose Meme
            <input type="file" name="post_file" id="postFileInput" onchange="showFileName(this)">
        </label>
        <div class="caption-wrap">
            <input type="text" name="caption" id="captionInput" placeholder="Write a caption… include a #hashtag" oninput="checkHashtag(this)">
            <div class="hashtag-hint" id="hashtagHint">Must include at least one #hashtag to post</div>
        </div>
        <button type="submit" name="upload_post" class="upload-btn"><i class="ri-upload-line"></i></button>
    </form>
    <div class="file-name-display" id="fileNameDisplay"></div>
</div>
<?php else: ?>
<div style="padding:14px 18px; border-radius:15px; background:rgba(255,255,255,0.04); border:1px dashed rgba(255,255,255,0.12); text-align:center; color:rgba(255,255,255,0.45); font-size:13px; margin-bottom:22px;">
    <i class="ri-lock-line"></i> Join this pod to post
</div>
<?php endif; ?>

<!-- ─── POST FILTERS ──────────────────────────────────────── -->
<div class="post-filters">
    <?php
    $filters = [
        'newest'    => ['ri-time-line',      'Newest'],
        'liked'     => ['ri-heart-line',     'Most Liked'],
        'reposted'  => ['ri-repeat-line',    'Most Reposted'],
        'favorited' => ['ri-bookmark-line',  'Most Saved'],
        'downloaded'=> ['ri-download-line',  'Most Downloaded'],
    ];
    foreach ($filters as $val => $info):
        $active = ($sort === $val) ? 'active' : '';
    ?>
    <button class="filter-btn <?php echo $active; ?>" onclick="applyFilter('<?php echo $val; ?>')">
        <i class="<?php echo $info[0]; ?>"></i> <?php echo $info[1]; ?>
    </button>
    <?php endforeach; ?>
</div>

<?php
$posts->data_seek(0);
$group_feed_rows = [];
while($p = $posts->fetch_assoc()) {
    $group_feed_rows[] = $p;
}
$posts->data_seek(0);
?>

<style>
/* ── CARD GRID ── */
.group-feed {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(280px, 100%), 1fr));
    gap: clamp(14px, 3vw, 28px);
    padding: clamp(16px, 4vw, 40px) 0;
    max-width: 100%;
    align-items: start;
}
@media (min-width: 1024px) { .group-feed { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 768px)  { .group-feed { grid-template-columns: repeat(2, 1fr); gap: 14px; padding: 16px 0; } }
@media (max-width: 480px)  { .group-feed { grid-template-columns: 1fr; gap: 12px; padding: 12px 0; } }

.gf-card-unit { display: flex; flex-direction: column; }

.gf-card {
    position: relative;
    border-radius: 25px;
    cursor: pointer;
    transition: transform 0.35s, box-shadow 0.35s;
    background: transparent;
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    border: 1px solid rgba(255,255,255,0.08);
    overflow: hidden;
}
.gf-card:hover { transform: translateY(-3px) scale(1.02); box-shadow: 0 12px 30px rgba(0,0,0,0.4); }

.gf-card img,
.gf-card video {
    width: 100%;
    height: clamp(170px, 30vw, 280px);
    object-fit: cover;
    border-radius: 20px;
    display: block;
}
@media (max-width: 768px) { .gf-card img, .gf-card video { height: clamp(160px, 35vw, 240px); } }
@media (max-width: 480px) { .gf-card img, .gf-card video { height: clamp(220px, 60vw, 300px); } }

.gf-top-buttons {
    position: absolute;
    top: 12px; left: 12px; right: 12px;
    display: flex;
    justify-content: space-between;
    gap: 10px;
    z-index: 5;
}
.gf-btn-group { display: flex; align-items: center; gap: 6px; }

.gf-action-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: clamp(4px,1vw,6px) clamp(8px,2vw,12px);
    border-radius: 20px;
    background: rgba(62,46,237,0.15);
    border: none;
    color: white;
    font-size: clamp(10px,2.5vw,12px);
    font-family: Poppins, sans-serif;
    cursor: pointer;
    transition: all 0.2s ease;
    min-height: 32px;
    -webkit-tap-highlight-color: transparent;
}
.gf-action-btn i { color: white; transition: color 0.2s; }
.gf-action-btn.active i { color: #ff2a2a; }
.gf-action-btn:hover { box-shadow: 0 0 20px rgba(255,255,255,0.3); }

.gf-posted-by {
    position: absolute;
    top: 50px; left: 12px;
    font-size: clamp(0.6rem,2vw,0.8rem);
    font-weight: 500;
    z-index: 5;
    display: flex;
    align-items: center;
    gap: 4px;
    flex-wrap: nowrap;
    max-width: calc(100% - 16px);
}
.gf-posted-by a { color: white; text-decoration: underline; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 80px; }
.gf-posted-by span { color: #f24455; font-weight: 600; white-space: nowrap; }

.gf-del-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 20px;
    background: rgba(255,45,45,0.2);
    border: none;
    color: #ff7a7a;
    font-size: clamp(10px,2.5vw,12px);
    font-family: Poppins, sans-serif;
    cursor: pointer;
    transition: all 0.2s;
    min-height: 32px;
    -webkit-tap-highlight-color: transparent;
}
.gf-del-btn:hover { background: rgba(255,45,45,0.45); color: white; }

.gf-user-bar {
    position: absolute;
    bottom: 10px;
    left: 50%;
    transform: translateX(-50%);
    width: calc(100% - clamp(16px,4vw,60px));
    display: flex;
    align-items: center;
    gap: clamp(5px,1.5vw,10px);
    padding: clamp(4px,1vw,8px) clamp(8px,2vw,12px);
    border-radius: clamp(10px,2vw,15px);
    background: rgba(14,10,239,0.15);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    z-index: 3;
    overflow: hidden;
    min-width: 0;
}
.gf-user-bar img {
    width: clamp(22px,5vw,38px);
    height: clamp(22px,5vw,38px);
    min-width: clamp(22px,5vw,38px);
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid red;
    display: block;
    flex-shrink: 0;
    transition: transform 0.2s, box-shadow 0.2s;
}
.gf-user-bar img:hover { transform: scale(1.08); box-shadow: 0 0 10px rgba(255,0,0,0.6); }
.gf-user-bar-caption {
    color: rgba(255,255,255,0.9);
    font-size: clamp(0.65rem,2.2vw,0.82rem);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    min-width: 0;
    flex: 1;
    font-weight: 500;
}

.gf-social-proof {
    position: absolute;
    bottom: 71px; left: 10px;
    display: flex; flex-direction: row;
    z-index: 3; pointer-events: none; gap: 7px;
}
.gf-sp-wrap {
    position: relative; pointer-events: all;
    display: inline-block; text-decoration: none;
    margin-right: 6px;
    animation: spFloat 3s ease-in-out infinite;
}
@keyframes spFloat { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-6px)} }
.gf-sp-avatar {
    width: 38px !important; height: 38px !important;
    min-width: 38px !important; max-width: 38px !important;
    border-radius: 50% !important; object-fit: cover !important;
    border: 2px solid red !important; display: block !important;
    box-shadow: 0 4px 12px rgba(0,0,0,0.7);
}
.gf-sp-badge {
    position: absolute; bottom: -2px; right: -2px;
    width: 15px; height: 15px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center; z-index: 21;
}
.gf-sp-badge i { font-size: 7px; color: white; line-height: 1; }
@media (max-width: 768px) {
    .gf-social-proof { bottom: 56px; left: 8px; }
    .gf-sp-avatar { width: 35px !important; height: 35px !important; min-width: 35px !important; max-width: 35px !important; }
}

.gf-eng-row {
    display: flex; align-items: center; gap: 8px;
    padding: 6px 4px; cursor: pointer;
    -webkit-tap-highlight-color: transparent;
}
.gf-eng-avatars { display: flex; flex-direction: row; }
.gf-eng-avatar {
    width: 20px !important; height: 20px !important;
    min-width: 20px !important; max-width: 20px !important;
    border-radius: 50% !important; object-fit: cover !important;
    border: 1.5px solid rgba(255,255,255,0.4) !important;
    margin-right: -5px; display: block !important;
}
.gf-eng-avatars .gf-eng-avatar:last-child { margin-right: 4px; }
.gf-eng-text { font-size: 0.7rem; color: rgba(255,255,255,0.75); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex: 1; }
.gf-eng-text strong { color: white; font-weight: 600; }

.gf-new-tag {
    position: absolute; bottom: 55px; left: 12px;
    background: transparent; color: #ffffff;
    border: transparent 1px solid; padding: 4px 10px;
    font-size: 0.72rem; font-weight: 600; border-radius: 10px;
    letter-spacing: 0.5px; display: inline-flex;
    align-items: center; justify-content: center;
    z-index: 20; pointer-events: none;
}

/* ══════════════════════════════════════════════
   MODAL — uses .open class, NOT style.display
   so CSS specificity never fights JS
══════════════════════════════════════════════ */
#gfModal {
    /* hidden by default — use visibility trick so
       display:flex is always set, but visually gone */
    position: fixed; inset: 0;
    z-index: 9999999999999;
    background: rgba(0,0,0,0.92);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    display: flex;                /* ALWAYS flex */
    align-items: center;
    justify-content: center;
    padding: 16px;
    flex-direction: row;
    gap: 12px;
    /* Hidden state */
    opacity: 0;
    pointer-events: none;
    visibility: hidden;
    transition: opacity 0.2s ease, visibility 0.2s ease;
}

/* Open state — toggled by JS adding .gfm-open */
#gfModal.gfm-open {
    opacity: 1;
    pointer-events: all;
    visibility: visible;
}

#gfm-card {
    background: rgba(18,17,17,0.95);
    border: 1px solid rgba(255,255,255,0.07);
    border-radius: 20px;
    width: 100%; max-width: 420px;
    overflow: hidden;
    display: flex; flex-direction: column;
    max-height: 90vh;
    transform: scale(0.95);
    transition: transform 0.2s ease;
}
#gfModal.gfm-open #gfm-card {
    transform: scale(1);
}

#gfm-inner { display: flex; flex-direction: column; overflow: hidden; flex: 1; }

.gfm-caption-box::-webkit-scrollbar { width: 4px; }
.gfm-caption-box::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.15); border-radius: 999px; }

.gfm-action-bar {
    display: flex; align-items: center; gap: 6px;
    padding: 10px 14px;
    border-top: 1px solid rgba(255,255,255,0.06);
    flex-shrink: 0;
}
.gfm-action-btn {
    display: flex; align-items: center; justify-content: center; gap: 5px;
    background: transparent;
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 10px; padding: 7px 12px;
    color: white; cursor: pointer;
    font-size: 12px; font-weight: 600;
    font-family: Poppins, sans-serif;
    transition: background 0.2s; flex: 1;
}
.gfm-action-btn:hover { background: rgba(255,255,255,0.07); }
.gfm-close-btn {
    display: flex; align-items: center; justify-content: center;
    background: transparent;
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 10px; padding: 7px 12px;
    color: rgba(255,255,255,0.45); cursor: pointer;
    font-size: 12px; font-family: Poppins, sans-serif;
}

#gfm-arrows {
    display: flex; flex-direction: column;
    align-items: center; gap: 10px; flex-shrink: 0;
}
#gfm-hint {
    position: absolute; bottom: 10px; left: 50%;
    transform: translateX(-50%);
    color: rgba(255,255,255,0.18);
    font-size: 10px; white-space: nowrap; pointer-events: none;
}

@media (max-width: 480px) {
    #gfm-arrows, #gfm-hint { display: none !important; }
    #gfModal { padding: 10px !important; }
    #gfm-card { border-radius: 20px 20px 14px 14px !important; max-height: 88vh !important; }
    .gfm-caption-box { font-size: 12px !important; max-height: 60px !important; }
    .gfm-action-btn { padding: 7px 8px !important; font-size: 11px !important; }
}
@media (max-width: 360px) {
    .gfm-caption-box { font-size: 11px !important; max-height: 55px !important; }
    .gfm-action-btn { padding: 6px !important; font-size: 10px !important; }
}
</style>

<?php
echo '<script>var peuraGroupFeed = [];</script>';
foreach($group_feed_rows as $p) {
    $mid   = (int)$p['meme_id'];
    $mtype = $p['type'] ?? 'image';
    $mfile = !empty($p['file_name']) ? 'uploads/' . $p['file_name'] : '';
    $mpic  = !empty($p['profile_pic']) ? $p['profile_pic'] : 'uploads/me.jpeg';
    echo '<script>peuraGroupFeed.push({
        id:          ' . $mid . ',
        file:        "' . addslashes($mfile) . '",
        type:        "' . addslashes($mtype) . '",
        pic:         "' . addslashes($mpic) . '",
        userId:      "' . (int)$p['user_id'] . '",
        username:    "' . addslashes(htmlspecialchars($p['username'])) . '",
        caption:     "' . addslashes($p['caption']) . '",
        time:        "' . addslashes($p['post_created']) . '",
        likeCount:   ' . (int)$p['like_count'] . ',
        repostCount: ' . (int)$p['repost_count'] . ',
        favCount:    ' . (int)$p['favorites_count'] . ',
        liked:       ' . ($p['is_liked'] ? 'true' : 'false') . ',
        reposted:    ' . ($p['is_reposted'] ? 'true' : 'false') . ',
        faved:       ' . ($p['is_favorited'] ? 'true' : 'false') . '
    });</script>';
}
?>

<!-- GRID -->
<div class="group-feed" id="groupFeed">
<?php
foreach($group_feed_rows as $p):
    $mid          = (int)$p['meme_id'];
    $mtype        = $p['type'] ?? 'image';
    $mfile        = !empty($p['file_name']) ? 'uploads/' . $p['file_name'] : '';
    $mpic         = !empty($p['profile_pic']) ? $p['profile_pic'] : 'uploads/me.jpeg';
    $muname       = htmlspecialchars($p['username']);
    $mcap         = htmlspecialchars($p['caption']);
    $muid         = (int)$p['user_id'];
    $is_liked     = (bool)$p['is_liked'];
    $is_reposted  = (bool)$p['is_reposted'];
    $is_faved     = (bool)$p['is_favorited'];
    $like_count   = (int)$p['like_count'];
    $repost_count = (int)$p['repost_count'];
    $fav_count    = (int)$p['favorites_count'];
    $is_new       = (time() - strtotime($p['post_created'])) <= 172800;
    $can_delete   = ($is_owner || $is_admin);
    $poster_is_admin = isset($admins[$muid]);

    $sp_list = [];
    if($current_user){
        $sp_res = $conn->query("
            SELECT u.id, u.username, u.profile_pic, 'like' AS action_type
            FROM likes l JOIN users u ON u.id=l.user_id
            JOIN followers f ON f.following_id=u.id
            WHERE l.meme_id=$mid AND f.follower_id=$current_user AND u.id!=$current_user
            UNION
            SELECT u.id, u.username, u.profile_pic, 'repost' AS action_type
            FROM reposts r JOIN users u ON u.id=r.user_id
            JOIN followers f ON f.following_id=u.id
            WHERE r.meme_id=$mid AND f.follower_id=$current_user AND u.id!=$current_user
            LIMIT 4
        ");
        while($sp = $sp_res->fetch_assoc()) $sp_list[] = $sp;
    }

    // Safe JS args — no double quotes inside
    $js_file     = addslashes($mfile);
    $js_type     = addslashes($mtype);
    $js_pic      = addslashes($mpic);
    $js_username = addslashes($p['username']);
    $js_caption  = addslashes($p['caption']);
?>
<div class="gf-card-unit">
<div class="gf-card"
     onclick="openGFModal('<?= $js_file ?>','<?= $js_type ?>','<?= $js_pic ?>',<?= $muid ?>,'<?= $js_username ?>','<?= $js_caption ?>')">

    <!-- TOP BUTTONS — all stop propagation -->
    <div class="gf-top-buttons" onclick="event.stopPropagation()">
        <div class="gf-btn-group">
            <button class="gf-action-btn gf-like <?= $is_liked ? 'active' : '' ?>" data-id="<?= $mid ?>" onclick="event.stopPropagation()">
                <i class="ri-heart-3-fill"></i>
                <span class="gf-like-count"><?= $like_count ?></span>
            </button>
            <button class="gf-action-btn gf-repost <?= $is_reposted ? 'active' : '' ?>" data-id="<?= $mid ?>" onclick="event.stopPropagation()">
                <i class="ri-repeat-line"></i>
                <span class="gf-repost-count"><?= $repost_count ?></span>
            </button>
            <button class="gf-action-btn gf-fav <?= $is_faved ? 'active' : '' ?>" data-id="<?= $mid ?>" onclick="event.stopPropagation()">
                <i class="ri-bookmark-fill"></i>
                <span class="gf-fav-count"><?= $fav_count ?></span>
            </button>
        </div>
        <div class="gf-btn-group">
            <?php if($can_delete): ?>
            <form method="POST" onclick="event.stopPropagation()" style="display:inline;">
                <input type="hidden" name="delete_post_id" value="<?= $p['group_post_id'] ?>">
                <input type="hidden" name="delete_meme_id" value="<?= $mid ?>">
                <button class="gf-del-btn" type="submit" name="delete_post" onclick="event.stopPropagation();return confirm('Delete this post?')">
                    <i class="ri-delete-bin-line"></i>
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- POSTED BY -->
    <div class="gf-posted-by" onclick="event.stopPropagation()">
        <span>Posted By</span>
        <strong>
            <a href="profile.php?user_id=<?= $muid ?>" onclick="event.stopPropagation()">
                <?= $muid == $current_user ? 'You' : (mb_strlen($p['username']) > 10 ? mb_substr($p['username'],0,10).'...' : $muname) ?>
            </a>
        </strong>
        <?php if($poster_is_admin): ?>
        <span style="color:#FFD700;font-size:9px;font-weight:700;background:rgba(255,215,0,0.1);padding:1px 6px;border-radius:8px;border:1px solid rgba(255,215,0,0.3);">ADMIN</span>
        <?php endif; ?>
    </div>

    <!-- MEDIA -->
    <?php if($mtype === 'video' && $mfile): ?>
        <video src="<?= htmlspecialchars($mfile) ?>" autoplay muted loop playsinline></video>
    <?php elseif($mtype === 'image' && $mfile): ?>
        <img src="<?= htmlspecialchars($mfile) ?>" onerror="this.src='uploads/Text.jpg'" alt="">
    <?php else: ?>
        <img src="uploads/Text.jpg" alt="">
    <?php endif; ?>

    <?php if($is_new): ?><div class="gf-new-tag">NEW</div><?php endif; ?>

    <!-- SOCIAL PROOF -->
    <?php if(!empty($sp_list)): ?>
    <div class="gf-social-proof">
        <?php foreach($sp_list as $i => $sp):
            $sp_pic   = !empty($sp['profile_pic']) ? $sp['profile_pic'] : 'uploads/me.jpeg';
            $sp_icon  = $sp['action_type'] === 'like' ? 'ri-heart-3-fill' : 'ri-repeat-line';
            $sp_color = $sp['action_type'] === 'like' ? '#f24455' : '#7a4aff';
        ?>
        <a href="profile.php?user_id=<?= $sp['id'] ?>" onclick="event.stopPropagation()" class="gf-sp-wrap" style="animation-delay:<?= $i*0.4 ?>s">
            <img src="<?= $sp_pic ?>" onerror="this.src='uploads/me.jpeg'" class="gf-sp-avatar">
            <span class="gf-sp-badge" style="background:<?= $sp_color ?>;"><i class="<?= $sp_icon ?>"></i></span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- USER BAR -->
    <div class="gf-user-bar" onclick="event.stopPropagation()">
        <a href="profile.php?user_id=<?= $muid ?>" onclick="event.stopPropagation()" style="flex-shrink:0;">
            <img src="<?= htmlspecialchars($mpic) ?>" onerror="this.src='uploads/me.jpeg'">
        </a>
        <div class="gf-user-bar-caption"><?= $mcap ?></div>
    </div>

</div><!-- /gf-card -->

<!-- ENGAGEMENT ROW -->
<?php
$eng_q = $conn->query("SELECT u.id, u.username, u.profile_pic FROM likes l JOIN users u ON u.id=l.user_id WHERE l.meme_id=$mid LIMIT 3");
$eng_users = [];
while($r = $eng_q->fetch_assoc()) $eng_users[] = $r;

if($like_count > 0 || $repost_count > 0):
?>
<div class="gf-eng-row" onclick="openEngModal(<?= $mid ?>, 'likes')" style="cursor:pointer;">
    <?php if(!empty($eng_users)): ?>
    <div class="gf-eng-avatars">
        <?php foreach($eng_users as $eu): 
            $ep = !empty($eu['profile_pic']) ? $eu['profile_pic'] : 'uploads/me.jpeg'; 
        ?>
        <img src="<?= $ep ?>" onerror="this.src='uploads/me.jpeg'" class="gf-eng-avatar">
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <span class="gf-eng-text">
        <?php
        if(!empty($eng_users)){
            echo 'Liked by <strong>' . htmlspecialchars($eng_users[0]['username']) . '</strong>';
            if($like_count > 1) echo ' and <strong>' . ($like_count-1) . ' others</strong>';
            if($repost_count > 0) echo ' · <strong>' . $repost_count . '</strong> reposts';
        } else {
            echo '<strong>' . $repost_count . '</strong> reposts';
        }
        ?>
    </span>
</div>
<?php endif; ?>

</div><!-- /gf-card-unit -->
<?php endforeach; ?>
</div><!-- /group-feed -->

<?php if(empty($group_feed_rows)): ?>
<div style="text-align:center;padding:40px 20px;color:rgba(255,255,255,0.3);font-size:14px;">
    <i class="ri-image-line" style="font-size:36px;display:block;margin-bottom:10px;"></i>
    No posts yet<?php if(!empty($search_query)) echo ' matching your search'; ?>.
</div>
<?php endif; ?>


<!-- Engagement Modal -->
<div id="gf-eng-modal" onclick="if(event.target===this) closeEngModal()">
    <div onclick="event.stopPropagation()" style="
        background: rgba(18,17,17,0.98);
        backdrop-filter: blur(20px);
        border-radius: 16px;
        width: 90%;
        max-width: 420px;
        max-height: 82vh;
        overflow: hidden;
        border: 1px solid rgba(255,255,255,0.12);
        display: flex;
        flex-direction: column;
        box-shadow: 0 20px 60px rgba(0,0,0,0.7);
    ">
        <!-- Header -->
        <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid rgba(255,255,255,0.1);flex-shrink:0;">
            <span style="color:white;font-weight:600;font-size:0.95rem;">Engagement</span>
            <button onclick="closeEngModal()" style="background:transparent;border:none;color:white;font-size:1.3rem;cursor:pointer;padding:0 8px;">✕</button>
        </div>

        <!-- Search -->
        <div style="padding:12px 16px;border-bottom:1px solid rgba(255,255,255,0.08);flex-shrink:0;">
            <input id="gf-eng-search" type="text" placeholder="Search users..." oninput="filterEngList()" 
            style="width:100%;background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.12);border-radius:10px;padding:9px 14px;color:white;font-size:0.82rem;outline:none;font-family:Poppins,sans-serif;">
        </div>

        <!-- Tabs -->
        <div style="display:flex;gap:10px;padding:12px 16px;border-bottom:1px solid rgba(255,255,255,0.08);flex-shrink:0;">
            <button id="gf-pill-likes" class="eng-pill active-pill" onclick="switchEngTab('likes')">
                <i class="ri-heart-3-fill"></i> Liked
            </button>
            <button id="gf-pill-reposts" class="eng-pill" onclick="switchEngTab('reposts')">
                <i class="ri-repeat-line"></i> Reposted
            </button>
        </div>

        <!-- List -->
        <div id="gf-eng-modal-list" style="overflow-y:auto;flex:1;padding:8px 0;"></div>
    </div>
</div>

<style>
/* CRITICAL FIX FOR MODAL */
#gf-eng-modal {
    display: none;
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    width: 100% !important;
    height: 100% !important;
    background: rgba(0,0,0,0.88) !important;
    z-index: 999999999 !important;
    align-items: center;
    justify-content: center;
    padding: 15px;
    overflow: hidden;
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
</style>

<script>
// Engagement Modal Logic
let currentEngMemeId = null;
let currentEngTab = 'likes';
let currentEngUsers = [];

function openEngModal(memeId, type = 'likes') {
    event.stopPropagation();
    currentEngMemeId = memeId;
    currentEngTab = type;

    const modal = document.getElementById('gf-eng-modal');
    modal.style.display = 'flex';

    document.getElementById('gf-eng-search').value = '';
    document.getElementById('gf-pill-likes').classList.add('active-pill');
    document.getElementById('gf-pill-reposts').classList.remove('active-pill');

    loadEngTab(type);
}

function switchEngTab(type) {
    currentEngTab = type;
    document.getElementById('gf-pill-likes').classList.toggle('active-pill', type === 'likes');
    document.getElementById('gf-pill-reposts').classList.toggle('active-pill', type === 'reposts');
    document.getElementById('gf-eng-search').value = '';
    loadEngTab(type);
}

function loadEngTab(type) {
    const list = document.getElementById('gf-eng-modal-list');
    list.innerHTML = '<div style="color:white;padding:40px;text-align:center;">Loading...</div>';

    fetch(`get_engagement.php?meme_id=${currentEngMemeId}&type=${type}`)
    .then(res => res.json())
    .then(users => {
        currentEngUsers = users;
        renderEngList(users);
    })
    .catch(() => {
        list.innerHTML = '<div style="color:rgba(255,255,255,0.5);padding:40px;text-align:center;">Could not load</div>';
    });
}

function renderEngList(users) {
    const list = document.getElementById('gf-eng-modal-list');
    list.innerHTML = '';

    if (users.length === 0) {
        list.innerHTML = '<div style="color:rgba(255,255,255,0.5);padding:40px;text-align:center;">No activity yet</div>';
        return;
    }

    users.forEach(u => {
        const pic = u.profile_pic || 'uploads/me.jpeg';
        list.innerHTML += `
        <div style="display:flex;align-items:center;gap:12px;padding:12px 16px;border-bottom:1px solid rgba(255,255,255,0.06);">
            <img src="${pic}" onerror="this.src='uploads/me.jpeg'" style="width:42px;height:42px;border-radius:50%;object-fit:cover;border:2px solid #ff2a2a;flex-shrink:0;">
            <span style="color:white;font-weight:500;flex:1;">${u.username}</span>
            <a href="profile.php?user_id=${u.id}" style="padding:6px 16px;border-radius:20px;background:transparent;border:1px solid rgba(255,43,43,0.6);color:#f24455;font-size:0.75rem;font-weight:600;text-decoration:none;white-space:nowrap;">View Profile</a>
        </div>`;
    });
}

function filterEngList() {
    const q = document.getElementById('gf-eng-search').value.toLowerCase().trim();
    const filtered = currentEngUsers.filter(u => u.username.toLowerCase().includes(q));
    renderEngList(filtered);
}

function closeEngModal() {
    document.getElementById('gf-eng-modal').style.display = 'none';
}
</script>

<!-- MODAL -->
<div id="gfModal" onclick="if(event.target===this)closeGFModal()">

    <div id="gfm-card">
        <div id="gfm-inner"></div>
    </div>

    <div id="gfm-arrows">
        <button onclick="gfmLoadIndex(_gfmIndex-1)"
            onmouseover="this.style.background='rgba(255,255,255,0.15)'"
            onmouseout="this.style.background='rgba(255,255,255,0.07)'"
            style="background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.12);border-radius:50%;width:40px;height:40px;display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;transition:background 0.2s;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.7)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"/></svg>
        </button>
        <button onclick="gfmLoadIndex(_gfmIndex+1)"
            onmouseover="this.style.background='rgba(255,255,255,0.15)'"
            onmouseout="this.style.background='rgba(255,255,255,0.07)'"
            style="background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.12);border-radius:50%;width:40px;height:40px;display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;transition:background 0.2s;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.7)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
    </div>

    <div id="gfm-hint">swipe or use arrows ↕</div>
</div>

<script>
var _gfmIndex  = 0;
var _gfmMemeId = null;
const gfCurrentUserId = <?php echo (int)$current_user; ?>;
const gfGroupId       = <?php echo (int)$group_id; ?>;

/* ── helpers ── */
function _gfmFormatDate(t) {
    if(!t) return '';
    var d = new Date(t.replace(' ','T'));
    if(isNaN(d)) return t;
    var mo = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return d.getDate()+' '+mo[d.getMonth()]+' '+d.getFullYear()+' · '+
           String(d.getHours()).padStart(2,'0')+':'+String(d.getMinutes()).padStart(2,'0');
}

function _gfmLinkify(cap) {
    return cap.replace(/@([a-zA-Z0-9_]+)/g, function(match, u) {
        return '<a href="profile.php?username='+encodeURIComponent(u)+'" onclick="event.stopPropagation()" '+
               'style="color:#f24455;font-weight:600;text-decoration:none;" '+
               'onmouseover="this.style.textDecoration=\'underline\'" '+
               'onmouseout="this.style.textDecoration=\'none\'">'+match+'</a>';
    });
}

/* ── render ── */
function _gfmRenderPost(p) {
    _gfmMemeId = p.id;
    var mediaHtml = '';
    if(p.type === 'image' && p.file)
        mediaHtml = '<img src="'+p.file+'" onerror="this.src=\'uploads/me.jpeg\'" style="width:100%;max-height:52vh;object-fit:contain;display:block;">';
    else if(p.type === 'video' && p.file)
        mediaHtml = '<video src="'+p.file+'" autoplay muted loop playsinline style="width:100%;max-height:52vh;object-fit:contain;display:block;"></video>';

    var li = p.liked||false, re = p.reposted||false, fa = p.faved||false;
    var lc = p.likeCount||0, rc = p.repostCount||0, fc = p.favCount||0;

    return ''+
    '<div style="display:flex;align-items:center;gap:10px;padding:14px 16px;flex-shrink:0;">'+
        '<img src="'+(p.pic||'uploads/me.jpeg')+'" onerror="this.src=\'uploads/me.jpeg\'"'+
             ' onclick="location.href=\'profile.php?user_id='+p.userId+'\'"'+
             ' style="width:40px;height:40px;border-radius:50%;border:2px solid #FF4C4C;cursor:pointer;object-fit:cover;flex-shrink:0;">'+
        '<div style="flex:1;min-width:0;">'+
            '<div onclick="location.href=\'profile.php?user_id='+p.userId+'\'"'+
                 ' style="color:#FF4C4C;font-weight:700;font-size:14px;cursor:pointer;display:flex;align-items:center;gap:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'+
                '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" style="flex-shrink:0;">'+
                    '<circle cx="12" cy="12" r="12" fill="#FF0000"/>'+
                    '<path d="M9 12l2 2 4-4" stroke="white" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>'+
                '</svg>'+
                '<span>'+(p.userId==gfCurrentUserId?'You':p.username)+'</span>'+
            '</div>'+
            '<div style="font-size:11px;color:rgba(255,255,255,0.35);margin-top:3px;display:flex;align-items:center;gap:4px;">'+
                '<i class="ri-time-line" style="font-size:10px;"></i>'+
                '<span>'+_gfmFormatDate(p.time||'')+'</span>'+
            '</div>'+
        '</div>'+
        '<button onclick="closeGFModal()"'+
                ' style="background:rgba(255,255,255,0.06);border:none;border-radius:50%;width:30px;height:30px;'+
                        'display:flex;align-items:center;justify-content:center;cursor:pointer;'+
                        'color:rgba(255,255,255,0.5);font-size:16px;flex-shrink:0;">✕</button>'+
    '</div>'+
    (mediaHtml?'<div style="width:100%;background:#000;flex-shrink:0;">'+mediaHtml+'</div>':'')+
    '<div class="gfm-caption-box" style="padding:10px 16px;color:rgba(255,255,255,0.8);font-size:13px;'+
         'line-height:1.55;border-top:1px solid rgba(255,255,255,0.06);word-break:break-word;'+
         'white-space:pre-wrap;overflow-y:auto;max-height:80px;flex-shrink:0;">'+
        _gfmLinkify(p.caption||'')+
    '</div>'+
    '<div class="gfm-action-bar">'+
        '<button id="gfm-like-btn" class="gfm-action-btn" onclick="gfmAction(\'like\')"'+
                ' style="color:'+(li?'#ff4c4c':'white')+';border-color:'+(li?'rgba(255,76,76,0.4)':'rgba(255,255,255,0.1)')+';">'+
            '<i class="ri-heart-fill" style="font-size:14px;"></i><span id="gfm-like-count">'+lc+'</span>'+
        '</button>'+
        '<button id="gfm-repost-btn" class="gfm-action-btn" onclick="gfmAction(\'repost\')"'+
                ' style="color:'+(re?'#ff4c4c':'white')+';border-color:'+(re?'rgba(255,76,76,0.4)':'rgba(255,255,255,0.1)')+';">'+
            '<i class="ri-repeat-line" style="font-size:14px;"></i><span id="gfm-repost-count">'+rc+'</span>'+
        '</button>'+
        '<button id="gfm-fav-btn" class="gfm-action-btn" onclick="gfmAction(\'fav\')"'+
                ' style="color:'+(fa?'#ff4c4c':'white')+';border-color:'+(fa?'rgba(255,76,76,0.4)':'rgba(255,255,255,0.1)')+';">'+
            '<i class="ri-bookmark-fill" style="font-size:14px;"></i><span id="gfm-fav-count">'+fc+'</span>'+
        '</button>'+
        '<button class="gfm-close-btn" onclick="closeGFModal()">Close</button>'+
    '</div>';
}

function _gfmRenderSlot(index) {
    if(index < 0 || index >= peuraGroupFeed.length) return;
    var inner = document.getElementById('gfm-inner');
    inner.querySelectorAll('video').forEach(function(v){ v.pause(); v.src=''; });
    inner.innerHTML = _gfmRenderPost(peuraGroupFeed[index]);
}

/* ── OPEN — class-based so CSS never fights JS ── */
function openGFModal(file, type, pic, userId, username, caption) {
    var idx = peuraGroupFeed.findIndex(function(p){
        return p.file === file && String(p.userId) === String(userId);
    });
    _gfmIndex = (idx >= 0) ? idx : 0;
    _gfmRenderSlot(_gfmIndex);
    document.getElementById('gfModal').classList.add('gfm-open');
    document.body.style.overflow = 'hidden';
}

/* ── CLOSE ── */
function closeGFModal() {
    document.getElementById('gfm-inner').querySelectorAll('video').forEach(function(v){
        v.pause(); v.src = '';
    });
    document.getElementById('gfModal').classList.remove('gfm-open');
    document.body.style.overflow = '';
    _gfmMemeId = null;
}

/* ── NAVIGATE ── */
function gfmLoadIndex(index) {
    if(index < 0 || index >= peuraGroupFeed.length) return;
    var card = document.getElementById('gfm-card');
    var dir  = index > _gfmIndex ? 1 : -1;
    card.style.transition = 'transform 0.22s ease, opacity 0.22s ease';
    card.style.transform  = 'translateY('+(dir*-30)+'px)';
    card.style.opacity    = '0';
    setTimeout(function(){
        _gfmIndex = index;
        _gfmRenderSlot(_gfmIndex);
        card.style.transition = 'none';
        card.style.transform  = 'translateY('+(dir*30)+'px)';
        card.style.opacity    = '0';
        requestAnimationFrame(function(){
            card.style.transition = 'transform 0.22s ease, opacity 0.22s ease';
            card.style.transform  = 'translateY(0)';
            card.style.opacity    = '1';
        });
    }, 220);
}

/* ── LIKE/REPOST/FAV in modal ── */
function gfmAction(type) {
    if(!_gfmMemeId) return;
    var btnMap    = { like:'gfm-like-btn',   repost:'gfm-repost-btn',   fav:'gfm-fav-btn'    };
    var cntMap    = { like:'gfm-like-count', repost:'gfm-repost-count', fav:'gfm-fav-count'  };
    var cntField  = { like:'likeCount',      repost:'repostCount',      fav:'favCount'        };
    var stateField= { like:'liked',          repost:'reposted',         fav:'faved'           };
    var gridSelMap= { like:'.gf-like',       repost:'.gf-repost',       fav:'.gf-fav'         };

    var gridBtn = document.querySelector(gridSelMap[type]+'[data-id="'+_gfmMemeId+'"]');
    if(gridBtn) gridBtn.click();

    setTimeout(function(){
        var feedItem = peuraGroupFeed.find(function(p){ return String(p.id)===String(_gfmMemeId); });
        var nowActive = feedItem ? feedItem[stateField[type]] : false;
        var mBtn  = document.getElementById(btnMap[type]);
        var mSpan = document.getElementById(cntMap[type]);
        if(mBtn){
            mBtn.style.color       = nowActive ? '#ff4c4c' : 'white';
            mBtn.style.borderColor = nowActive ? 'rgba(255,76,76,0.4)' : 'rgba(255,255,255,0.1)';
        }
        if(feedItem && gridBtn){
            var cntEl = gridBtn.querySelector('span');
            if(cntEl && mSpan) mSpan.textContent = cntEl.textContent;
            feedItem[cntField[type]] = parseInt(cntEl ? cntEl.textContent : '0') || 0;
        }
    }, 350);
}

/* ── GRID BUTTONS (capture phase — fires before card onclick) ── */
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.gf-like, .gf-repost, .gf-fav');
    if(!btn) return;
    e.stopPropagation();

    var mid      = btn.dataset.id;
    var isLike   = btn.classList.contains('gf-like');
    var isRepost = btn.classList.contains('gf-repost');
    var isFav    = btn.classList.contains('gf-fav');
    var postKey  = isLike ? 'ajax_like' : (isRepost ? 'ajax_repost' : 'ajax_favorite');
    var countEl  = btn.querySelector('span');

    fetch('group.php?id=' + gfGroupId, {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: postKey + '=1&meme_id=' + mid
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        if(countEl) countEl.textContent = data.count;
        var active = data[isLike ? 'liked' : (isRepost ? 'reposted' : 'favorited')];
        if(active) btn.classList.add('active');
        else       btn.classList.remove('active');
        var stateKey = isLike ? 'liked' : (isRepost ? 'reposted' : 'faved');
        var cntField = isLike ? 'likeCount' : (isRepost ? 'repostCount' : 'favCount');
        var item = peuraGroupFeed.find(function(p){ return String(p.id)===String(mid); });
        if(item){ item[cntField] = data.count; item[stateKey] = active; }
    })
    .catch(function(err){ console.error('gf action error', err); });
}, true);

/* ── WHEEL ── */
document.getElementById('gfModal').addEventListener('wheel', function(e){
    e.preventDefault();
    if(e.deltaY > 0) gfmLoadIndex(_gfmIndex+1);
    else             gfmLoadIndex(_gfmIndex-1);
}, {passive:false});

/* ── TOUCH SWIPE ── */
var _gfmTouchY = 0;
document.getElementById('gfModal').addEventListener('touchstart', function(e){
    _gfmTouchY = e.touches[0].clientY;
}, {passive:true});
document.getElementById('gfModal').addEventListener('touchend', function(e){
    var diff = _gfmTouchY - e.changedTouches[0].clientY;
    if(Math.abs(diff) > 40){
        if(diff > 0) gfmLoadIndex(_gfmIndex+1);
        else         gfmLoadIndex(_gfmIndex-1);
    }
}, {passive:true});

/* ── KEYBOARD ── */
document.addEventListener('keydown', function(e){
    if(document.getElementById('gfModal').classList.contains('gfm-open')){
        if(e.key === 'Escape')    closeGFModal();
        if(e.key === 'ArrowDown') gfmLoadIndex(_gfmIndex+1);
        if(e.key === 'ArrowUp')   gfmLoadIndex(_gfmIndex-1);
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
// ─── AGE GATE ──────────────────────────────────────────────
var AGE_GATE_CONFIRMED = sessionStorage.getItem('ageConfirmed_<?php echo (int)$group_id; ?>');
var SHOW_AGE_GATE = <?php echo $show_age_gate ? 'true' : 'false'; ?>;

(function(){
    if (SHOW_AGE_GATE && !AGE_GATE_CONFIRMED) {
        document.getElementById('ageGateOverlay').classList.add('open');
        document.body.style.overflow = 'hidden';
    }
})();

function confirmAge() {
    sessionStorage.setItem('ageConfirmed_<?php echo (int)$group_id; ?>', '1');
    var overlay = document.getElementById('ageGateOverlay');
    if (overlay) {
        overlay.classList.remove('open');
        document.body.style.overflow = '';
    }
}

// ─── KEBAB MENU ────────────────────────────────────────────
function toggleKebab(e) {
    e.stopPropagation();
    var dd = document.getElementById('kebabDropdown');
    dd.classList.toggle('open');
}

document.addEventListener('click', function(e) {
    var wrap = document.getElementById('kebabWrap');
    if (wrap && !wrap.contains(e.target)) {
        var dd = document.getElementById('kebabDropdown');
        if (dd) dd.classList.remove('open');
    }
});

// ─── SCREEN TIME BREAK ─────────────────────────────────────
(function(){
    const LIMIT_MS  = 60 * 60 * 1000;
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
        sessionStorage.setItem(STORE_KEY, Date.now());
    }

    function checkTime(){
        var start = parseInt(sessionStorage.getItem(STORE_KEY));
        if(Date.now() - start >= LIMIT_MS){
            showBreak();
        }
    }

    setInterval(checkTime, 30000);
    window.addEventListener('load', checkTime);

    window.checkBreakCode = function(){
        var val = document.getElementById('breakCodeInput').value;
        var input = document.getElementById('breakCodeInput');
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
                setTimeout(function(){ input.classList.remove('shake'); }, 400);
            }
        }
    };

    window.tryBreakReturn = function(){
        var val = document.getElementById('breakCodeInput').value;
        var input = document.getElementById('breakCodeInput');
        if(val === CODE){
            input.style.borderColor = '#44ff88';
            setTimeout(hideBreak, 300);
            input.value = '';
        } else {
            input.classList.remove('shake');
            void input.offsetWidth;
            input.classList.add('shake');
            input.value = '';
            setTimeout(function(){ input.classList.remove('shake'); }, 400);
        }
    };
})();

// ─── PARTICLES ─────────────────────────────────────────────
var container = document.getElementById('particles');
for (var i = 0; i < 70; i++) {
    var p = document.createElement('div');
    p.className = 'particle';
    var size = Math.random() * 2 + 3;
    p.style.width = size + 'px';
    p.style.height = size + 'px';
    p.style.left = Math.random() * 100 + 'vw';
    p.style.top = Math.random() * 100 + 'vh';
    p.style.setProperty('--x-move', (Math.random() * 200 - 100) + 'px');
    p.style.setProperty('--y-move', (Math.random() * -300 - 100) + 'px');
    p.style.animationDuration = (Math.random() * 15 + 10) + 's';
    p.style.opacity = Math.random() * 0.5 + 0.2;
    container.appendChild(p);
}

// ─── FILTERS ───────────────────────────────────────────────
function applyFilter(val) {
    var url = new URL(window.location.href);
    url.searchParams.set('sort', val);
    window.location.href = url.toString();
}

// ─── HASHTAG CHECK ─────────────────────────────────────────
function checkHashtag(input) {
    var hint = document.getElementById('hashtagHint');
    if (input.value.includes('#')) {
        hint.textContent = '✓ Hashtag detected — good to go!';
        hint.className = 'hashtag-hint valid';
    } else {
        hint.textContent = 'Must include at least one #hashtag to post';
        hint.className = 'hashtag-hint invalid';
    }
}

function showFileName(input) {
    var display = document.getElementById('fileNameDisplay');
    display.textContent = input.files[0] ? '📎 ' + input.files[0].name : '';
}

// ─── AJAX ENGAGEMENT ───────────────────────────────────────
var BASE = 'group.php?id=<?php echo $group_id; ?>';

document.querySelectorAll('.likeBtn').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        fetch(BASE, {
            method:'POST',
            headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:'ajax_like=1&meme_id='+btn.dataset.id
        }).then(function(r){ return r.json(); }).then(function(data) {
            btn.querySelector('.like-count').textContent = data.count;
            if (data.liked) {
                btn.classList.add('liked');
                btn.querySelector('i').className = 'ri-heart-fill';
            } else {
                btn.classList.remove('liked');
                btn.querySelector('i').className = 'ri-heart-line';
            }
        });
    });
});

document.querySelectorAll('.repostBtn').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        fetch(BASE, {
            method:'POST',
            headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:'ajax_repost=1&meme_id='+btn.dataset.id
        }).then(function(r){ return r.json(); }).then(function(data) {
            btn.querySelector('.repost-count').textContent = data.count;
            data.reposted ? btn.classList.add('reposted') : btn.classList.remove('reposted');
        });
    });
});

document.querySelectorAll('.favBtn').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        fetch(BASE, {
            method:'POST',
            headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:'ajax_favorite=1&meme_id='+btn.dataset.id
        }).then(function(r){ return r.json(); }).then(function(data) {
            btn.querySelector('.fav-count').textContent = data.count;
            if (data.favorited) {
                btn.classList.add('saved');
                btn.querySelector('i').className = 'ri-bookmark-fill';
            } else {
                btn.classList.remove('saved');
                btn.querySelector('i').className = 'ri-bookmark-line';
            }
        });
    });
});

// ─── MODAL ─────────────────────────────────────────────────
var _ccmUserId = 0;

function openCCM(type, src, userId, username, profile, caption) {
    _ccmUserId = userId;

    document.getElementById('ccm-pic').src = profile || 'uploads/me.jpeg';
    document.getElementById('ccm-username-text').textContent = '' + username;

    var mediaEl = document.getElementById('ccm-media');
    mediaEl.innerHTML = '';
    if (type === 'image') {
        var img = document.createElement('img');
        img.src = src;
        mediaEl.appendChild(img);
    } else if (type === 'video') {
        var vid = document.createElement('video');
        vid.src = src; vid.controls = true; vid.autoplay = true; vid.loop = true; vid.muted = false;
        mediaEl.appendChild(vid);
    }

    var capEl = document.getElementById('ccm-caption');
    capEl.innerHTML = caption
        ? caption.replace(/(#\w+)/g, '<span class="hashtag">$1</span>')
        : '';

    document.getElementById('cardCaptionModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeCCM() {
    document.getElementById('cardCaptionModal').classList.remove('open');
    document.body.style.overflow = '';
    var vid = document.querySelector('#ccm-media video');
    if (vid) vid.pause();
}

function goCCMProfile() {
    location.href = 'profile.php?user_id=' + _ccmUserId;
}

// ─── MISC ──────────────────────────────────────────────────
function reportGroup() {
    alert("Group reported. Our moderators will review it.");
}

document.querySelectorAll('video.post-media').forEach(function(v){ v.play().catch(function(){}); });
</script>
</body>
</html>