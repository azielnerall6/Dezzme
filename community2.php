<?php
session_start();
include 'config.php';

$current_user = $_SESSION['user_id'] ?? 0;
if(!$current_user){ header("Location: login.php"); exit(); }

// ── nav profile pic ──
$nav_pic = 'uploads/mee.jpeg';
$nav_row = $conn->query("SELECT profile_pic FROM users WHERE id=$current_user LIMIT 1")->fetch_assoc();
if (!empty($nav_row['profile_pic'])) $nav_pic = $nav_row['profile_pic'];

/* ================= TOGGLE FOLLOW HANDLER ================= */
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_follow'){
    header('Content-Type: application/json');
    if(!$current_user){ echo json_encode(['status'=>'error','message'=>'not logged in']); exit; }

    $target_id = intval($_POST['user_id']);

    if($target_id <= 0 || $target_id === (int)$current_user){
        echo json_encode(['status'=>'error','message'=>'invalid target']); exit;
    }

    $stmt = $conn->prepare("SELECT id FROM followers WHERE follower_id=? AND following_id=?");
    $stmt->bind_param("ii", $current_user, $target_id);
    $stmt->execute();
    $res = $stmt->get_result();

    if($res->num_rows > 0){
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

/* ================= BLOCKED USERS ================= */
$blocked_users = [];
$res = $conn->query("SELECT blocked_id FROM blocks WHERE blocker_id = $current_user");
while($row = $res->fetch_assoc()){
    $blocked_users[] = (int)$row['blocked_id'];
}
$blocked_sql = !empty($blocked_users) ? implode(',', $blocked_users) : '';

/* ================= MUTED USERS ================= */
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
$muted_sql = !empty($muted_users) ? implode(',', $muted_users) : '';

/* ================= MUTED GROUPS ================= */
$conn->query("
    CREATE TABLE IF NOT EXISTS muted_groups (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NOT NULL,
        group_id INT UNSIGNED NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY unique_mute (user_id, group_id)
    ) ENGINE=InnoDB
");
$conn->query("
    CREATE TABLE IF NOT EXISTS group_notif_off (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NOT NULL,
        group_id INT UNSIGNED NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY unique_notif_off (user_id, group_id)
    ) ENGINE=InnoDB
");

/* ── Mute group POST handler ── */
if (isset($_POST['mute_group'])) {
    header('Content-Type: application/json');
    $muted_val = (int)($_POST['muted'] ?? 0);
    if ($muted_val) {
        $conn->query("INSERT IGNORE INTO muted_groups (user_id, group_id) VALUES ($current_user, $group_id)");
    } else {
        $conn->query("DELETE FROM muted_groups WHERE user_id=$current_user AND group_id=$group_id");
    }
    echo json_encode(['ok' => true]);
    exit;
}

/* ── Group notifications off POST handler ── */
if (isset($_POST['notif_group'])) {
    header('Content-Type: application/json');
    $off_val = (int)($_POST['off'] ?? 0);
    if ($off_val) {
        $conn->query("INSERT IGNORE INTO group_notif_off (user_id, group_id) VALUES ($current_user, $group_id)");
    } else {
        $conn->query("DELETE FROM group_notif_off WHERE user_id=$current_user AND group_id=$group_id");
    }
    echo json_encode(['ok' => true]);
    exit;
}

/* ── Fetch muted group IDs ── */
$muted_group_ids = [];
$mg_res = $conn->query("SELECT group_id FROM muted_groups WHERE user_id=$current_user");
if ($mg_res) while ($r = $mg_res->fetch_assoc()) $muted_group_ids[] = (int)$r['group_id'];
$muted_group_ids_sql = !empty($muted_group_ids) ? implode(',', $muted_group_ids) : '';

/* ── Fetch meme IDs that belong to muted groups ── */
$muted_group_meme_ids  = [];
$muted_group_meme_sql  = '';
if (!empty($muted_group_ids_sql)) {
    $mid_res = $conn->query("SELECT DISTINCT meme_id FROM group_posts WHERE group_id IN ($muted_group_ids_sql)");
    if ($mid_res) while ($r = $mid_res->fetch_assoc()) $muted_group_meme_ids[] = (int)$r['meme_id'];
    $muted_group_meme_sql = !empty($muted_group_meme_ids) ? implode(',', $muted_group_meme_ids) : '';
}

/* ── Combined exclusion (blocked + muted users) for uploaders ── */
$excluded_uploaders = array_unique(array_merge($blocked_users, $muted_users));
$excluded_sql       = !empty($excluded_uploaders) ? implode(',', $excluded_uploaders) : '';

/* ================= SEARCH ================= */
$search = $_GET['search'] ?? '';

if($blocked_sql){
    $users = $conn->query("
        SELECT id, username, profile_pic 
        FROM users 
        WHERE username LIKE '%$search%' 
        AND id NOT IN ($blocked_sql)
        LIMIT 10
    ");
} else {
    $users = $conn->query("
        SELECT id, username, profile_pic 
        FROM users 
        WHERE username LIKE '%$search%' 
        LIMIT 10
    ");
}

$groups_search = $conn->query("
    SELECT id, title, group_pic 
    FROM groups 
    WHERE title LIKE '%$search%' 
    LIMIT 10
");

/* ================= RECENT UPLOADS ================= */
$uploads_where   = "WHERE (memes.is_adult = 0 OR EXISTS (
        SELECT 1 FROM group_posts gp
        JOIN group_members gm ON gm.group_id = gp.group_id
        WHERE gp.meme_id = memes.id AND gm.user_id = $current_user
    ))";

if (!empty($excluded_sql)) {
    $uploads_where .= " AND memes.uploaded_by NOT IN ($excluded_sql)";
}

if (!empty($muted_group_meme_sql)) {
    $uploads_where .= " AND memes.id NOT IN ($muted_group_meme_sql)";
}

$uploads = $conn->query("
    SELECT memes.*, users.username, users.profile_pic 
    FROM memes 
    JOIN users ON users.id = memes.uploaded_by 
    $uploads_where
    ORDER BY created_at DESC 
    LIMIT 20
");

/* ================= FOLLOWING USERS ================= */
if($blocked_sql){
    $following = $conn->query("
        SELECT users.id, username, profile_pic 
        FROM follows 
        JOIN users ON users.id = follows.following_id 
        WHERE follower_id = $current_user
        AND users.id NOT IN ($blocked_sql)
    ");
} else {
    $following = $conn->query("
        SELECT users.id, username, profile_pic 
        FROM follows 
        JOIN users ON users.id = follows.following_id 
        WHERE follower_id = $current_user
    ");
}

/* ================= GROUPS ================= */
/* Groups list — hide muted groups from the community page listing */
$groups_where = "WHERE 1=1";
if (!empty($blocked_sql)) {
    $groups_where .= " AND g.created_by NOT IN ($blocked_sql)";
}
if (!empty($muted_group_ids_sql)) {
    $groups_where .= " AND g.id NOT IN ($muted_group_ids_sql)";
}

$groups = $conn->query("
    SELECT g.id, g.title, g.description, g.group_pic, g.created_at, g.created_by,
           u.username, u.profile_pic AS creator_pic
    FROM groups g
    JOIN users u ON g.created_by = u.id
    $groups_where
    ORDER BY g.created_at DESC
");

/* ================= POD CREATION ELIGIBILITY ================= */
$pod_eligible   = true;
$pod_block_msg  = '';
$pod_owns_count = 0;
$pod_max        = 5;

if ($current_user) {

    $age_row = $conn->query("SELECT DATEDIFF(NOW(), created_at) AS age FROM users WHERE id = $current_user")->fetch_assoc();
    $account_age_days = (int)($age_row['age'] ?? 0);

    $ev_row = $conn->query("SELECT email_verified FROM users WHERE id = $current_user")->fetch_assoc();
    $email_verified = (bool)($ev_row['email_verified'] ?? false);

    $upload_count = (int)$conn->query("SELECT COUNT(*) AS c FROM memes WHERE uploaded_by = $current_user")->fetch_assoc()['c'];
    $like_count   = (int)$conn->query("SELECT COUNT(*) AS c FROM likes    WHERE user_id  = $current_user")->fetch_assoc()['c'];
    $repost_count = (int)$conn->query("SELECT COUNT(*) AS c FROM reposts  WHERE user_id  = $current_user")->fetch_assoc()['c'];
    $has_activity = ($upload_count >= 5) || (($like_count + $repost_count) >= 10);

    $pod_owns_count = (int)$conn->query("SELECT COUNT(*) AS c FROM groups WHERE created_by = $current_user")->fetch_assoc()['c'];
    $under_limit    = $pod_owns_count < $pod_max;

    $reasons = [];
    if ($account_age_days < 7)  $reasons[] = 'your account must be at least 7 days old (currently ' . $account_age_days . ' day' . ($account_age_days == 1 ? '' : 's') . ')';
    if (!$email_verified)       $reasons[] = 'your email must be verified — check your settings';
    if (!$has_activity)         $reasons[] = 'you need at least 5 uploads or 10 interactions (likes/reposts)';
    if (!$under_limit)          $reasons[] = 'you\'ve reached the maximum of ' . $pod_max . ' Pods';

    if (!empty($reasons)) {
        $pod_eligible  = false;
        $pod_block_msg = 'You can\'t create a Pod yet: ' . implode('; ', $reasons) . '.';
    }
}

/* ================= DUPLICATE NAME CHECK (AJAX) ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_pod_name') {
    header('Content-Type: application/json');
    $query = trim($_POST['name'] ?? '');
    if (strlen($query) < 3) { echo json_encode(['similar' => []]); exit; }
    $safe  = $conn->real_escape_string($query);
    $rows  = $conn->query("
        SELECT g.id, g.title, COUNT(gm.id) AS member_count
        FROM groups g
        LEFT JOIN group_members gm ON gm.group_id = g.id
        WHERE g.title LIKE '%$safe%' OR SOUNDEX(g.title) = SOUNDEX('$safe')
        GROUP BY g.id
        LIMIT 5
    ");
    $similar = [];
    while ($r = $rows->fetch_assoc()) $similar[] = $r;
    echo json_encode(['similar' => $similar]);
    exit;
}

/* ================= CREATE GROUP ================= */
if (isset($_POST['create_group']) && $pod_eligible) {
    $title      = $conn->real_escape_string(trim($_POST['title']       ?? ''));
    $desc       = $conn->real_escape_string(trim($_POST['description'] ?? ''));
    $guidelines = $conn->real_escape_string(trim($_POST['guidelines']  ?? ''));
    $category   = $conn->real_escape_string(trim($_POST['category']    ?? ''));

    if ($title && $desc && $guidelines && $category) {
        $pic = 'uploads/default_group.png';
        if (!empty($_FILES['group_pic']['name'])) {
            $name = time() . '_' . basename($_FILES['group_pic']['name']);
            move_uploaded_file($_FILES['group_pic']['tmp_name'], "uploads/$name");
            $pic = "uploads/$name";
        }
        $conn->query("INSERT INTO groups (title, description, guidelines, category, group_pic, created_by)
                      VALUES ('$title','$desc','$guidelines','$category','$pic',$current_user)");
        $new_id = $conn->insert_id;
        $conn->query("INSERT IGNORE INTO group_members (user_id, group_id) VALUES ($current_user, $new_id)");
        header("Location: group.php?id=$new_id");
        exit;
    }
}

/* ================= AJAX ACTION HANDLER (LIKE/REPOST/FAV) ================= */
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['toggle_like','toggle_repost','toggle_favorite'])){
    header('Content-Type: application/json');
    $meme_id = (int)$_POST['meme_id'];
    $action  = $_POST['action'];

    function countRows($conn, $table, $meme_id){
        $res = $conn->query("SELECT COUNT(*) as c FROM $table WHERE meme_id=$meme_id");
        return (int)$res->fetch_assoc()['c'];
    }

    if($action === 'toggle_like'){
        $check = $conn->query("SELECT id FROM likes WHERE user_id=$current_user AND meme_id=$meme_id");
        if($check->num_rows){
            $conn->query("DELETE FROM likes WHERE user_id=$current_user AND meme_id=$meme_id");
        } else {
            $conn->query("INSERT INTO likes (user_id, meme_id) VALUES ($current_user, $meme_id)");
        }
        echo json_encode(['count' => countRows($conn,'likes',$meme_id)]);
        exit;
    }

    if($action === 'toggle_repost'){
        $check = $conn->query("SELECT id FROM reposts WHERE user_id=$current_user AND meme_id=$meme_id");
        if($check->num_rows){
            $conn->query("DELETE FROM reposts WHERE user_id=$current_user AND meme_id=$meme_id");
        } else {
            $conn->query("INSERT INTO reposts (user_id, meme_id) VALUES ($current_user, $meme_id)");
        }
        echo json_encode(['count' => countRows($conn,'reposts',$meme_id)]);
        exit;
    }

    if($action === 'toggle_favorite'){
        $check = $conn->query("SELECT id FROM favorites WHERE user_id=$current_user AND meme_id=$meme_id");
        if($check->num_rows){
            $conn->query("DELETE FROM favorites WHERE user_id=$current_user AND meme_id=$meme_id");
        } else {
            $conn->query("INSERT INTO favorites (user_id, meme_id, created_at) VALUES ($current_user, $meme_id, NOW())");
        }
        echo json_encode(['count' => countRows($conn,'favorites',$meme_id)]);
        exit;
    }
}
?>
<?php
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
<?php
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dezzme | Community</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;1,9..40,300&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<style>

/* ══════════════════════════════════════
   ROOT & RESET
══════════════════════════════════════ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

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

/* Noise grain overlay */
body::before {
    content: '';
    position: fixed;
    inset: 0;
    background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)' opacity='0.04'/%3E%3C/svg%3E");
    pointer-events: none;
    z-index: 0;
    opacity: 0.55;
}

.sidebar {
    position: fixed;
    left: 0; top: 0;
    width: 100px;
    height: 100vh;
    display: flex;
    flex-direction: column;
    gap: 32px;
    padding: 20px;
    border-radius:15px;
    background: transparent;
    backdrop-filter: blur(15px);
    box-shadow: 0 0 40px rgba(0,0,0,0.6);
    z-index: 100;
}

.logo img {
    width: 55px;
    height: 55px;
    border-radius: 15px;
    transition: .3s;
    animation: floatUpDown 2s ease-in-out infinite;
}

.logo img:hover {
    box-shadow: 0 0 15px white;
    transform: scale(1.1);
}

@keyframes floatUpDown {
    0%, 100% { transform: translateY(0); }
    50%       { transform: translateY(-5px); }
}

.nav-icon {
    width: 50px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 15px;
    background: transparent;
    color: white;
    font-size: 22px;
    text-decoration: none;
    transition: all 0.3s ease;
    cursor: pointer;
    position: relative;
    overflow: hidden;
    box-shadow:transparent;
    -webkit-tap-highlight-color: transparent;
}

.nav-icon:hover {
    transform: translateY(-3px) scale(1.1);
    background: rgba(255,255,255,0.18);
    box-shadow: 0 10px 25px rgba(255,255,255,0.2), 0 0 15px rgba(255,255,255,0.1);
}

.nav-icon:active {
    transform: scale(0.95);
    box-shadow: inset 0 4px 10px rgba(0,0,0,0.4);
}

.nav-icon.active {
    background: #FF4C4C;
    box-shadow: 0 8px 20px rgba(255,76,76,0.5), 0 0 10px rgba(255,76,76,0.3);
}

.nav-icon i { transition: transform 0.3s ease; }
.nav-icon:hover i { transform: scale(1.2) rotate(5deg); }

.nav-icon::before {
    content: "";
    position: absolute;
    top: 0; left: -80%;
    width: 50%; height: 100%;
    background: linear-gradient(120deg, transparent, rgba(255,255,255,0.4), transparent);
    transform: skewX(-20deg);
    transition: 0.6s;
}
.nav-icon:hover::before { left: 120%; }

/* ══════════════════════════════════════
   TITLE BADGE (mobile)
══════════════════════════════════════ */
.title {
    font-family: 'DM Sans', sans-serif;
    font-size: 22px;
    font-weight: 800;
    letter-spacing: 3px;
    color: var(--text);
    display: none;
}
.title strong { font-size: 9px; color: var(--blue); letter-spacing: 1px; }

/* ══════════════════════════════════════
   MAIN LAYOUT
══════════════════════════════════════ */
.main {
    margin-left: var(--sidebar-w);
    padding: 48px 48px 80px;
    position: relative;
    z-index: 1;
    max-width: 1400px;
}

/* ══════════════════════════════════════
   PAGE HEADER
══════════════════════════════════════ */
.page-header {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    margin-bottom: 40px;
    gap: 20px;
    flex-wrap: wrap;
}

.page-header-left h1 {
    font-family: 'DM Sans', sans-serif;
    font-size: clamp(28px, 4vw, 48px);
    font-weight: 800;
    letter-spacing: -1px;
    line-height: 1.05;
    background: #fff;
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.page-header-left p {
    font-size: 14px;
    color: var(--text-3);
    margin-top: 6px;
    font-weight: 300;
    letter-spacing: 0.3px;
}


.section-label {
    font-family: var(--font-head);
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 2.5px;
    text-transform: uppercase;
    color: white;
    margin-bottom: 18px;
    display: flex;
    align-items: center;
    gap: 10px;
    background: transparent;
}
.section-label::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--border);
}

.section-title {
    font-family: var(--font-head);
    font-size: clamp(18px, 2.5vw, 24px);
    font-weight: 700;
    color: white;
    margin-bottom: 20px;
    letter-spacing: -0.4px;
    background: transparent;
}

/* ══════════════════════════════════════
   SECTION BLOCK
══════════════════════════════════════ */
.section {
    margin-bottom: 52px;
}

/* ══════════════════════════════════════
   SCROLLTRACK (horizontal scroll rows)
══════════════════════════════════════ */
.scrolltrack {
    display: flex;
    gap: 14px;
    overflow-x: auto;
    padding: 4px 2px 16px;
    scroll-behavior: smooth;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
}
.scrolltrack::-webkit-scrollbar { display: none; }

/* ══════════════════════════════════════
   USER PILL CARDS
══════════════════════════════════════ */
.user-pill {
    flex: 0 0 auto;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 18px 14px 14px;
    min-width: 110px;
    cursor: pointer;
    transition: all 0.25s ease;
    text-decoration: none;
    position: relative;
    overflow: hidden;
}

.user-pill::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(145deg, rgba(0,87,255,0.06), transparent 60%);
    opacity: 0;
    transition: opacity 0.3s;
}

.user-pill:hover {
    background: var(--surface-2);
    border-color: var(--border-2);
    transform: translateY(-5px);
    box-shadow: 0 20px 40px rgba(0,0,0,0.35);
}
.user-pill:hover::before { opacity: 1; }

.user-pill-avatar {
    width: 58px; height: 58px;
    border-radius: 50%;
    border: 2px solid var(--red);
    object-fit: cover;
    flex-shrink: 0;
    transition: box-shadow 0.25s;
}
.user-pill:hover .user-pill-avatar {
    box-shadow: 0 0 16px var(--red-glow);
}

.user-pill-name {
    font-size: 12px;
    font-weight: 500;
    color: var(--text-2);
    text-align: center;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 88px;
}

.user-pill-badge {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 10px;
    color: var(--blue);
    background: var(--blue-dim);
    border-radius: 20px;
    padding: 2px 8px;
    font-weight: 500;
}

.following-tag {
    font-size: 10px;
    color: var(--text-3);
    background: var(--surface-2);
    padding: 2px 8px;
    border-radius: 20px;
    border: 1px solid var(--border);
}

/* ══════════════════════════════════════
   MEME / UPLOAD CARDS
══════════════════════════════════════ */
.meme-card {
    flex: 0 0 auto;
    width: 200px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    overflow: hidden;
    cursor: pointer;
    transition: all 0.25s ease;
    position: relative;
}

.meme-card:hover {
    border-color: var(--border-2);
    transform: translateY(-6px);
    box-shadow: 0 24px 48px rgba(0,0,0,0.4);
}

.meme-thumb {
    width: 100%;
    height: 148px;
    object-fit: cover;
    display: block;
    background: var(--ink-3);
}

.meme-footer {
    padding: 10px 12px;
    display: flex;
    align-items: center;
    gap: 9px;
}

.meme-footer img {
    width: 26px; height: 26px;
    border-radius: 50%;
    border: 1.5px solid var(--red);
    object-fit: cover;
    flex-shrink: 0;
    cursor: pointer;
    transition: box-shadow 0.2s;
}
.meme-footer img:hover { box-shadow: 0 0 10px var(--red-glow); }

.meme-footer-text {
    display: flex;
    flex-direction: column;
    gap: 1px;
    min-width: 0;
}

.meme-footer-user {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--red);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    text-decoration: underline;
    cursor: pointer;
}

.meme-footer-caption {
    font-size: 11px;
    color: var(--text-3);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* ══════════════════════════════════════
   SEARCH RESULTS
══════════════════════════════════════ */
.result-card {
    flex: 0 0 auto;
    display: flex;
    align-items: center;
    gap: 12px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: 12px 16px;
    min-width: 200px;
    cursor: pointer;
    transition: all 0.22s;
}
.result-card:hover {
    background: var(--surface-2);
    border-color: var(--border-2);
    transform: translateY(-3px);
}
.result-card img {
    width: 42px; height: 42px;
    border-radius: 50%;
    border: 2px solid var(--red);
    object-fit: cover;
    flex-shrink: 0;
}
.result-card .rc-name {
    font-size: 13px;
    font-weight: 600;
    color: var(--text);
}

/* ══════════════════════════════════════
   CREATE POD PANEL
══════════════════════════════════════ */
.create-panel {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 28px 30px;
    margin-bottom: 52px;
    position: relative;
    overflow: hidden;
}

.create-panel::before {
    content: '';
    position: absolute;
    top: -60px; right: -60px;
    width: 240px; height: 240px;
    background: radial-gradient(circle, rgba(0,87,255,0.08) 0%, transparent 70%);
    pointer-events: none;
}

.create-panel::after {
    content: '';
    position: absolute;
    bottom: -40px; left: -40px;
    width: 180px; height: 180px;
    background: radial-gradient(circle, rgba(255,45,45,0.05) 0%, transparent 70%);
    pointer-events: none;
}

.create-panel-title {
    font-family: var(--font-head);
    font-size: 15px;
    font-weight: 700;
    color: var(--text);
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
    letter-spacing: -0.2px;
}

.create-panel-title i {
    font-size: 17px;
    color: var(--blue);
}

/* ── GRID LAYOUT ── */
.pod-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}

.pod-full { grid-column: 1 / -1; }

/* ── FIELD WRAPPER ── */
.field-wrap {
    position: relative;
    display: flex;
    flex-direction: column;
}

.field-label {
    font-size: 11px;
    font-weight: 500;
    color: var(--text-3);
    letter-spacing: 0.8px;
    text-transform: uppercase;
    margin-bottom: 6px;
    padding-left: 2px;
}

/* ── INPUTS ── */
.field-input {
    width: 100%;
    padding: 11px 16px;
    background: rgba(255,255,255,0.03);
    border: 1px solid var(--border);
    border-radius: 12px;
    color: var(--text);
    font-family: var(--font-body);
    font-size: 13.5px;
    font-weight: 300;
    transition: border-color 0.2s, background 0.2s, box-shadow 0.2s;
    outline: none;
}

.field-input::placeholder { color: var(--text-3); font-weight: 300; }

.field-input:focus {
    border-color: rgba(0,87,255,0.45);
    background: rgba(0,87,255,0.04);
    box-shadow: 0 0 0 3px rgba(0,87,255,0.08);
}

select.field-input { appearance: none; cursor: pointer; }

textarea.field-input {
    resize: vertical;
    min-height: 76px;
    line-height: 1.5;
    padding-top: 10px;
    padding-bottom: 10px;
}

.field-char {
    font-size: 10px;
    color: var(--text-3);
    text-align: right;
    margin-top: 4px;
    padding-right: 2px;
}

/* ── UPLOAD BUTTON ── */
.btn-upload {
    width: 100%;
    padding: 11px 16px;
    background: rgba(255,255,255,0.03);
    border: 1px dashed var(--border-2);
    border-radius: 12px;
    color: var(--text-2);
    font-family: var(--font-body);
    font-size: 13px;
    font-weight: 400;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.btn-upload:hover {
    border-color: rgba(0,87,255,0.4);
    background: rgba(0,87,255,0.06);
    color: var(--text);
}

/* ── PRIMARY BUTTON ── */
.btn-primary {
    padding: 10px 24px;
    background: var(--red);
    border: none;
    border-radius: 10px;
    color: #fff;
    font-family: var(--font-head);
    font-size: 13px;
    font-weight: 700;
    letter-spacing: 0.2px;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    -webkit-tap-highlight-color: transparent;
}

.btn-primary:hover {
    background: #ff1a1a;
    box-shadow: 0 6px 20px var(--red-glow);
    transform: translateY(-1px);
}

.btn-primary:active { transform: scale(0.97); }

.btn-primary:disabled {
    opacity: 0.35;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

/* ── GHOST BUTTON ── */
.btn-ghost {
    padding: 10px 18px;
    background: transparent;
    border: 1px solid var(--border-2);
    border-radius: 10px;
    color: var(--text-2);
    font-family: var(--font-body);
    font-size: 13px;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-ghost:hover {
    background: var(--surface-2);
    color: var(--text);
    border-color: rgba(255,255,255,0.2);
}

/* ── PANEL FOOTER ── */
.panel-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-top: 16px;
    border-top: 1px solid var(--border);
    margin-top: 14px;
    gap: 10px;
    flex-wrap: wrap;
}

.pod-counter {
    font-size: 11px;
    color: var(--text-3);
    display: flex;
    align-items: center;
    gap: 5px;
}

.pod-counter span { color: var(--text-2); font-weight: 500; }

/* ── ELIGIBILITY PILLS ── */
.eligibility-row {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 18px;
}

.elig-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 500;
    padding: 3px 10px;
    border-radius: 20px;
}

.elig-pill.pass {
    color: #44c87a;
    background: rgba(68,200,122,0.1);
    border: 1px solid rgba(68,200,122,0.2);
}

.elig-pill.fail {
    color: #ff6b6b;
    background: rgba(255,45,45,0.1);
    border: 1px solid rgba(255,45,45,0.2);
}

/* ── RESPONSIVE ── */
@media (max-width: 640px) {
    .pod-grid { grid-template-columns: 1fr; }
    .pod-full { grid-column: 1; }
    .create-panel { padding: 18px 16px; }
    .panel-footer { flex-direction: column; align-items: stretch; }
    .panel-footer > div { display: flex; flex-direction: column; gap: 8px; }
    .btn-primary, .btn-ghost { width: 100%; justify-content: center; }
}

@media (max-width: 760px) {
    .create-panel { padding: 16px !important; border-radius: 18px !important; }
    .field-input { font-size: 13px !important; }
}

/* POD GRID */
.pod-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}
.pod-full { grid-column: 1 / -1; }

.field-wrap {
    display: flex;
    flex-direction: column;
}

.field-label {
    font-size: 11px;
    font-weight: 500;
    color: var(--text-3);
    letter-spacing: 0.8px;
    text-transform: uppercase;
    margin-bottom: 6px;
    padding-left: 2px;
}

.field-char {
    font-size: 10px;
    color: var(--text-3);
    text-align: right;
    margin-top: 4px;
}

.panel-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-top: 16px;
    border-top: 1px solid var(--border);
    margin-top: 14px;
    gap: 10px;
    flex-wrap: wrap;
}

.pod-counter {
    font-size: 11px;
    color: var(--text-3);
    display: flex;
    align-items: center;
    gap: 5px;
}

.pod-counter span { color: var(--text-2); font-weight: 500; }

/* Chevron hover */
#podChevronBtn:hover {
    background: var(--surface-2);
    border-color: rgba(255,255,255,0.2);
}

/* Responsive */
@media (max-width: 640px) {
    .pod-grid { grid-template-columns: 1fr; }
    .pod-full { grid-column: 1; }
    .panel-footer { flex-direction: column; align-items: stretch; }
    .btn-primary { width: 100%; justify-content: center; }
}

/* ══════════════════════════════════════
   GROUPS GRID
══════════════════════════════════════ */
.groups-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 16px;
}

.group-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 22px;
    cursor: pointer;
    transition: all 0.28s ease;
    position: relative;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.group-card::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, rgba(0,87,255,0.05) 0%, transparent 50%);
    opacity: 0;
    transition: opacity 0.3s;
    pointer-events: none;
}

.group-card:hover {
    border-color: rgba(0,87,255,0.28);
    transform: translateY(-6px);
    box-shadow: 0 28px 56px rgba(0,0,0,0.4), 0 0 40px rgba(0,87,255,0.06);
}
.group-card:hover::after { opacity: 1; }

.group-card-header {
    display: flex;
    gap: 16px;
    align-items: flex-start;
}

.group-pic {
    width: 72px; height: 72px;
    border-radius: var(--radius-md);
    object-fit: cover;
    border: 2px solid rgba(0,87,255,0.4);
    flex-shrink: 0;
    transition: box-shadow 0.25s, border-color 0.25s;
}
.group-card:hover .group-pic {
    border-color: var(--blue);
    box-shadow: 0 0 20px var(--blue-glow);
}

.group-info h3 {
    font-family: var(--font-head);
    font-size: 16px;
    font-weight: 700;
    color: var(--text);
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.group-info p {
    font-size: 13px;
    color: var(--text-2);
    line-height: 1.5;
    font-weight: 300;
}

.group-verified {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.group-creator-row {
    display: flex;
    align-items: center;
    gap: 8px;
    padding-top: 14px;
    border-top: 1px solid var(--border);
}

.group-creator-row img {
    width: 22px; height: 22px;
    border-radius: 50%;
    border: 1.5px solid rgba(255,45,45,0.5);
    object-fit: cover;
    flex-shrink: 0;
}

.group-creator-row span {
    font-size: 12px;
    color: var(--text-3);
    display: flex;
    align-items: center;
    gap: 5px;
}

.group-join-btn {
    margin-left: auto;
    padding: 5px 14px;
    background: var(--blue-dim);
    border: 1px solid rgba(0,87,255,0.25);
    border-radius: 20px;
    color: var(--blue);
    font-size: 11px;
    font-weight: 600;
    font-family: var(--font-head);
    letter-spacing: 0.5px;
    text-transform: uppercase;
    transition: all 0.2s;
    flex-shrink: 0;
}
.group-card:hover .group-join-btn {
    background: var(--blue);
    color: white;
    border-color: transparent;
    box-shadow: 0 4px 14px var(--blue-glow);
}

/* ══════════════════════════════════════
   FLOATING MESSAGES
══════════════════════════════════════ */
.floating-message {
    position: fixed;
    bottom: 28px;
    left: calc(var(--sidebar-w) + 20px);
    width: 48px; height: 48px;
    background: var(--surface-2);
    backdrop-filter: blur(16px);
    border: 1px solid var(--border-2);
    border-radius: var(--radius-sm);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.25s;
    z-index: 500;
    animation: floatUpDown 3s ease-in-out infinite;
    -webkit-tap-highlight-color: transparent;
}
.floating-message:hover {
    background: var(--surface-2);
    box-shadow: 0 8px 28px rgba(0,87,255,0.25);
    border-color: rgba(0,87,255,0.35);
    transform: translateY(-3px);
}

/* ══════════════════════════════════════
   PARTICLES
══════════════════════════════════════ */
#particles {
    position: fixed;
    width: 100%;
    height: 100%;
    overflow: hidden;
    top: 0; left: 0;
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
    0%   { transform: translate(0,0) scale(1); opacity: 0; }
    10%  { opacity: 1; }
    50%  { transform: translate(var(--x-move), var(--y-move)) scale(1.2); }
    100% { transform: translate(calc(var(--x-move)*-1), calc(var(--y-move)*-1)) scale(0.8); opacity: 0; }
}

/* ══════════════════════════════════════
   ACCENT DIVIDER
══════════════════════════════════════ */
.accent-line {
    width: 36px; height: 3px;
    border-radius: 2px;
    background: var(--red);
    margin-bottom: 20px;
}

.nav-profile-pic {
    display: none;
}

/* ══════════════════════════════════════
   MOBILE — max 768px
══════════════════════════════════════ */
@media (max-width: 768px) {

    :root { --sidebar-w: 0px; }

    body { padding-bottom: 74px; padding-top: 52px; }


    .sidebar {
    position: fixed;
    bottom: 0; left: 0; right: 0; top: auto;
    width: 100%; height: 60px;
    flex-direction: row;
    justify-content: space-evenly;
    align-items: center;
    padding: 0;
    gap: 0;
    border-right: none;
    border-top: 1px solid var(--border);
    background: rgba(6,7,26,0.96);
    backdrop-filter: blur(24px);
}

.sidebar .logo { display: none; }

.nav-icon {
    flex: 0 0 auto;
    width: 45px;
    height: 60px;
    border-radius: 0;
    border: none;
    font-size: clamp(18px, 5vw, 22px);
    font-weight: 900;
    -webkit-text-stroke: 0.5px #fff;
}

.nav-icon::before { display: none; }

.nav-icon:hover { transform: none; }

    .main {
        margin-left: 0;
        padding: clamp(14px, 4vw, 24px);
        padding-bottom: 80px;
    }

    .page-header { margin-bottom: 24px; }

    .search-wrap { max-width: 100%; }

    .groups-grid { grid-template-columns: 1fr; }

    .floating-message {
        bottom: 72px;
        left: 14px;
    }

    .create-panel { padding: 20px; }

    .meme-card { width: clamp(160px, 44vw, 200px); }

    .user-pill { min-width: 96px; padding: 14px 10px 12px; }
    .user-pill-avatar { width: 48px; height: 48px; }
}

@media (max-width: 380px) {
    .main { padding: 12px; }
    .user-pill { min-width: 84px; }
    .user-pill-avatar { width: 42px; height: 42px; }
    .meme-card { width: clamp(140px, 46vw, 180px); }
}

@media (max-width: 1024px) {
    .nav-links a.nav-icon[title="Sponsorships/Ads"] { display: none !important; }
    body { padding-top: 55px; }
    .title {
    display: block;
    position: absolute;
    top: 13px; left: 15px;
    z-index: 10000;
    font-size: clamp(20px, 4vw, 22px);
    letter-spacing: 3px;
    font-weight: 800;
    line-height: 1;
    margin-bottom: -10px;
}
}

@media (max-width: 768px) {
    .sidebar {
        position: fixed;
        bottom: 0; left: 0; right: 0;
        top: auto;
        width: 100%;
        height: 60px;
        flex-direction: row;
        justify-content: space-evenly;
        align-items: center;
        padding: 0;
        gap: 0;
        border-radius: 0;
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        background: rgba(3,4,21,0.97);
        border-top: 1px solid rgba(255,255,255,0.08);
        box-shadow: 0 -4px 20px rgba(0,0,0,0.4);
    }

    .sidebar .logo { display: none; }

    .nav-icon {
        flex: 0 0 auto;
        width: 45px;
        height: 60px;
        border-radius: 0;
        background: transparent;
        box-shadow: none;
        font-size: clamp(18px, 5vw, 22px);
        font-weight: 900;
        -webkit-text-stroke: 0.5px #fff;
        border: none;
    }

    .nav-icon::before { display: none; }

    .nav-icon:hover {
        transform: none;
        background: rgba(255,255,255,0.05);
        box-shadow: none;
    }

    .nav-icon:active {
        transform: scale(0.88);
        opacity: 0.6;
    }

    /* hide icon, show pic on mobile */
    .nav-profile-icon { display: none !important; }

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
}

.search-wrap {
    position: relative;
    width: 100%;
    max-width: 520px;
}

.search-wrap form {
    display: flex;
    gap: 8px;
    width: 100%;
    align-items: center;
    padding: 8px 8px 8px 16px;
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 15px;
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    transition: border-color 0.3s, box-shadow 0.3s;
}

.search-wrap form:focus-within {
    border-color: rgba(255,255,255,0.2);
    box-shadow: 0 0 0 4px rgba(255,45,45,0.08), 0 16px 40px rgba(0,0,0,0.35);
}

.search-icon-abs {
    color: rgba(255,255,255,0.3);
    font-size: 18px;
    display: flex;
    align-items: center;
    flex-shrink: 0;
    pointer-events: none;
    transition: color 0.3s;
}

.search-wrap form:focus-within .search-icon-abs {
    color: rgba(255,255,255,0.55);
}

.search-wrap input,
.search-wrap .field-input {
    flex: 1;
    padding: 0;
    background: transparent;
    border: none;
    border-radius: 0;
    color: var(--text);
    font-family: var(--font-body);
    font-size: 14px;
    font-weight: 300;
    outline: none;
    box-shadow: none;
    min-width: 0;
}

.search-wrap input::placeholder,
.search-wrap .field-input::placeholder {
    color: rgba(255,255,255,0.25);
    font-weight: 300;
}

.search-wrap button,
.search-wrap .btn-primary {
    border: none;
    background: #FF2D2D;
    width: 58px;
    height: 38px;
    border-radius: 13px;
    color: white;
    font-size: 17px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    cursor: pointer;
    transition: all 0.22s ease;
    position: relative;
    overflow: hidden;
    padding: 0;
    -webkit-tap-highlight-color: transparent;
}

.search-wrap button::before,
.search-wrap .btn-primary::before {
    content: "";
    position: absolute;
    top: 0; left: -80%;
    width: 50%; height: 100%;
    background: linear-gradient(120deg, transparent, rgba(255,255,255,0.25), transparent);
    transform: skewX(-20deg);
    transition: 0.5s;
}

.search-wrap button:hover,
.search-wrap .btn-primary:hover {
    background: #ff1a1a;
    box-shadow: 0 6px 20px rgba(255,45,45,0.45);
    transform: scale(1.06);
}
.search-wrap button:hover::before,
.search-wrap .btn-primary:hover::before { left: 120%; }
.search-wrap button:active,
.search-wrap .btn-primary:active { transform: scale(0.95); }

/* ===========================
   RESPONSIVE — 790px
=========================== */
@media (max-width: 790px) {
    .search-wrap {
        max-width: 440px;
    }
    .search-wrap form {
        gap: 7px;
        padding: 7px 7px 7px 14px;
        border-radius: 13px;
    }
    .search-icon-abs {
        font-size: 16px;
    }
    .search-wrap input,
    .search-wrap .field-input {
        font-size: 13px;
    }
    .search-wrap button,
    .search-wrap .btn-primary {
        width: 50px;
        height: 36px;
        border-radius: 11px;
        font-size: 15px;
    }
}

/* ===========================
   RESPONSIVE — 480px
=========================== */
@media (max-width: 480px) {
    .search-wrap {
        max-width: 100%;
    }
    .search-wrap form {
        gap: 6px;
        padding: 6px 6px 6px 12px;
        border-radius: 12px;
    }
    .search-icon-abs {
        font-size: 14px;
    }
    .search-wrap input,
    .search-wrap .field-input {
        font-size: 12px;
    }
    .search-wrap button,
    .search-wrap .btn-primary {
        width: 42px;
        height: 32px;
        border-radius: 5px;
        font-size: 13px;
    }
}

/* ===========================
   RESPONSIVE — 380px
=========================== */
@media (max-width: 380px) {
    .search-wrap form {
        gap: 5px;
        padding: 5px 5px 5px 10px;
        border-radius: 10px;
    }
    .search-icon-abs {
        font-size: 13px;
    }
    .search-wrap input,
    .search-wrap .field-input {
        font-size: 11px;
    }
    .search-wrap button,
    .search-wrap .btn-primary {
        width: 34px;
        height: 30px;
        border-radius: 5px;
        font-size: 12px;
    }
}

/* ===========================
   RESPONSIVE — 330px
=========================== */
@media (max-width: 330px) {
    .search-wrap form {
        gap: 4px;
        padding: 4px 4px 4px 8px;
        border-radius: 9px;
    }
    .search-icon-abs {
        font-size: 12px;
    }
    .search-wrap input,
    .search-wrap .field-input {
        font-size: 10px;
    }
    .search-wrap button,
    .search-wrap .btn-primary {
        width: 28px;
        height: 28px;
        border-radius: 5px;
        font-size: 11px;
    }
}

/* ─────────────────────────────
   CREATE PANEL RESPONSIVE ONLY
──────────────────────────── */

/* 📱 760px */
@media (max-width:760px){

    .create-panel{
        padding:16px !important;
        border-radius:18px !important;
    }

    .create-panel .create-panel-title{
        font-size:15px !important;
        gap:7px !important;
        margin-bottom:14px !important;
    }

    .create-panel .create-panel-title i{
        font-size:18px !important;
    }

    .create-panel .field-input{
        height:42px !important;
        font-size:13px !important;
        padding:0 14px !important;
        border-radius:12px !important;
        margin-bottom:10px !important;
    }

    .create-panel .field-input::placeholder{
        font-size:13px !important;
    }

    .create-panel .btn-upload{
        height:42px !important;
        padding:0 14px !important;
        font-size:13px !important;
        border-radius:12px !important;
        gap:7px !important;
    }

    .create-panel .btn-upload i{
        font-size:17px !important;
    }

    .create-panel .btn-primary{
        height:44px !important;
        font-size:13px !important;
        border-radius:13px !important;
        margin-top:12px !important;
    }
}

/* 📱 480px */
@media (max-width:480px){

    .create-panel{
        padding:14px !important;
        border-radius:16px !important;
    }

    .create-panel .create-panel-title{
        font-size:14px !important;
        gap:6px !important;
        margin-bottom:12px !important;
    }

    .create-panel .create-panel-title i{
        font-size:17px !important;
    }

    .create-panel .field-input{
        height:39px !important;
        font-size:12px !important;
        padding:0 12px !important;
        border-radius:11px !important;
        margin-bottom:9px !important;
    }

    .create-panel .field-input::placeholder{
        font-size:12px !important;
    }

    .create-panel .btn-upload{
        height:39px !important;
        padding:0 12px !important;
        font-size:12px !important;
        border-radius:11px !important;
        gap:6px !important;
    }

    .create-panel .btn-upload i{
        font-size:16px !important;
    }

    .create-panel .btn-primary{
        height:41px !important;
        font-size:12px !important;
        border-radius:12px !important;
        margin-top:10px !important;
    }
}

/* 📱 360px */
@media (max-width:360px){

    .create-panel{
        padding:12px !important;
        border-radius:14px !important;
    }

    .create-panel .create-panel-title{
        font-size:13px !important;
        margin-bottom:10px !important;
    }

    .create-panel .create-panel-title i{
        font-size:16px !important;
    }

    .create-panel .field-input{
        height:36px !important;
        font-size:11px !important;
        padding:0 10px !important;
        border-radius:10px !important;
        margin-bottom:8px !important;
    }

    .create-panel .field-input::placeholder{
        font-size:11px !important;
    }

    .create-panel .btn-upload{
        height:36px !important;
        font-size:11px !important;
        padding:0 10px !important;
        border-radius:10px !important;
        gap:5px !important;
    }

    .create-panel .btn-upload i{
        font-size:15px !important;
    }

    .create-panel .btn-primary{
        height:38px !important;
        font-size:11px !important;
        border-radius:10px !important;
        margin-top:9px !important;
    }
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

@media (max-width: 768px) {
    a.nav-icon[href="upload.php"] {
        display: none !important;
    }
}
</style>
</head>
<body>

<!-- TITLE (mobile/tablet) -->
<div class="title">Dezzme<strong></strong></div>

<!-- SIDEBAR (untouched JS/PHP structure) -->
<div class="sidebar">
    <div class="logo" onclick="location.href='index.php'">
        <img src="Dezzme.jpg">
    </div>
    <a href="home.php" class="nav-icon" title="Home"><i class="ri-home-5-line" style="font-size:15px; color:white;"></i></a>
    <a class="glass nav-icon" href="text_feed.php"><i class="ri-send-plane-line" style="font-size:15px; color:white;"></i></a>
    <a href="search.php" class="nav-icon" title="Discover"><i class="ri-search-line" style="font-size:15px; color:white;"></i></a>
    <a href="upload.php" class="nav-icon" title="Upload"><i class="ri-add-circle-line" style="font-size:29px; color:white;"></i></a>
    <a href="community.php" class="nav-icon" title="Community"><i class="ri-team-line" style="font-size:15px; color:white;"></i></a>
    <a class="glass nav-icon nav-profile-link" href="profile.php">
        <i class="ri-user-3-line nav-profile-icon" style="font-size:15px; color:white;"></i>
        <img class="nav-profile-pic"
             src="<?php echo htmlspecialchars($nav_pic); ?>"
             onerror="this.src='uploads/mee.jpeg'"
             alt="Profile">
    </a>
</div>

<div class="main">

    <!-- ═══════════════════════════════════════════════
         PAGE HEADER + SEARCH
    ═══════════════════════════════════════════════ -->
    <div class="page-header">
        <div class="page-header-left">
            <h1>Community</h1>
            <p>Discover people, pods & recent drops</p>
        </div>
        <div class="search-wrap">
            <form method="GET">
                <i class="ri-search-line search-icon-abs"></i>
                <input class="field-input" name="search" placeholder="Search users or groups…" value="<?php echo htmlspecialchars($search); ?>">
                <button class="btn-primary" type="submit"><i class="ri-arrow-right-line"></i></button>
            </form>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════
         SEARCH RESULTS
    ═══════════════════════════════════════════════ -->
    <?php if($search): ?>
    <div class="section">
        <div class="accent-line"></div>
        <div class="section-title">Results for "<?php echo htmlspecialchars($search); ?>"</div>
        <div class="scrolltrack">

            <?php while($u = $users->fetch_assoc()): ?>
            <div class="result-card" onclick="location='profile.php?user_id=<?php echo $u['id']; ?>'">
                <img src="<?php echo $u['profile_pic']; ?>" onerror="this.src='uploads/me.jpeg'" alt="">
                <div>
                    <div class="rc-name">
                        <svg xmlns="http://www.w3.org/2000/svg" width="9" height="9" viewBox="0 0 24 24" style="vertical-align:1px;">
                            <circle cx="12" cy="12" r="12" fill="#FF0000"/>
                            <path d="M9 12l2 2 4-4" stroke="white" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <?php echo htmlspecialchars($u['username']); ?>
                    </div>
                    <div style="font-size:11px;color:var(--text-3);margin-top:2px;">User</div>
                </div>
            </div>
            <?php endwhile; ?>

            <?php while($g = $groups_search->fetch_assoc()): ?>
            <div class="result-card" onclick="location='group.php?id=<?php echo $g['id']; ?>'">
                <img src="<?php echo $g['group_pic']; ?>" onerror="this.src='uploads/default_group.png'" alt="" style="border-radius:8px;border-color:rgba(0,87,255,0.5);">
                <div>
                    <div class="rc-name">
                        <svg xmlns="http://www.w3.org/2000/svg" width="9" height="9" viewBox="0 0 24 24" style="vertical-align:1px;">
                            <circle cx="12" cy="12" r="12" fill="#0673f0ff"/>
                            <path d="M9 12l2 2 4-4" stroke="white" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <?php echo htmlspecialchars($g['title']); ?>
                    </div>
                    <div style="font-size:11px;color:var(--text-3);margin-top:2px;">Pod</div>
                </div>
            </div>
            <?php endwhile; ?>

        </div>
    </div>
    <?php endif; ?>

   <!-- ═══════════════════════════════════════════════
         CREATE POD PANEL
    ═══════════════════════════════════════════════ -->
    <div class="section">

        <?php if (!$pod_eligible): ?>

        <!-- BLOCKED STATE -->
        <div class="create-panel" style="border-color:rgba(255,45,45,0.25);">
            <div class="create-panel-title" style="color:var(--red);">
                <i class="ri-lock-line"></i> Pod Creation Locked
            </div>
            <p style="font-size:13px;color:var(--text-2);line-height:1.6;margin:0;">
                <?php echo htmlspecialchars($pod_block_msg); ?>
            </p>
            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:16px;">
                <?php
                $checks = [
                    ['label' => '7-day account',        'pass' => $account_age_days >= 7],
                    ['label' => 'Email verified',        'pass' => $email_verified],
                    ['label' => '5 uploads / activity', 'pass' => $has_activity],
                    ['label' => 'Under pod limit',       'pass' => $pod_owns_count < $pod_max],
                ];
                foreach ($checks as $c):
                    $color = $c['pass'] ? '#44c87a' : 'var(--red)';
                    $icon  = $c['pass'] ? 'ri-checkbox-circle-fill' : 'ri-close-circle-fill';
                ?>
                <span style="display:inline-flex;align-items:center;gap:5px;
                             font-size:12px;font-weight:500;color:<?php echo $color; ?>;
                             background:<?php echo $c['pass'] ? 'rgba(68,200,122,0.12)' : 'rgba(255,45,45,0.1)'; ?>;
                             border:1px solid <?php echo $c['pass'] ? 'rgba(68,200,122,0.3)' : 'rgba(255,45,45,0.25)'; ?>;
                             border-radius:20px;padding:4px 12px;">
                    <i class="<?php echo $icon; ?>" style="font-size:12px;"></i>
                    <?php echo htmlspecialchars($c['label']); ?>
                </span>
                <?php endforeach; ?>
            </div>
        </div>

        <?php else: ?>

        <!-- CREATION FORM -->
        <div class="create-panel" id="podCreatePanel">

            <!-- HEADER ROW — always visible -->
            <div style="display:flex;align-items:center;justify-content:space-between;
                        cursor:pointer;user-select:none;-webkit-tap-highlight-color:transparent;"
                 onclick="togglePodPanel()">
                <div class="create-panel-title" style="margin-bottom:0;flex:1;">
                    <i class="ri-add-circle-line"></i>
                    Start a Pod
                    <span style="margin-left:8px;font-size:11px;font-weight:400;color:var(--text-3);">
                        <?php echo $pod_owns_count; ?> / <?php echo $pod_max; ?> pods
                    </span>
                </div>
                <button type="button" id="podChevronBtn"
                        aria-label="Toggle pod creation panel"
                        style="width:32px;height:32px;border-radius:50%;
                               background:var(--surface);border:1px solid var(--border-2);
                               display:flex;align-items:center;justify-content:center;
                               color:var(--text-2);cursor:pointer;flex-shrink:0;
                               transition:background 0.2s,transform 0.2s;
                               -webkit-tap-highlight-color:transparent;">
                    <i class="ri-arrow-down-s-line" id="podChevronIcon"
                       style="font-size:18px;transition:transform 0.3s ease;transform:rotate(-180deg);"></i>
                </button>
            </div>

            <!-- COLLAPSIBLE BODY — collapsed by default -->
            <div id="podPanelBody"
                 style="overflow:hidden;transition:max-height 0.35s ease,opacity 0.3s ease;
                        max-height:0px;opacity:0;">

                <div style="height:18px;"></div>

                <!-- DUPLICATE WARNING -->
                <div id="podDupWarn" style="display:none;background:rgba(255,160,0,0.1);
                     border:1px solid rgba(255,160,0,0.3);border-radius:12px;
                     padding:12px 14px;margin-bottom:14px;">
                    <p style="font-size:12px;color:#c97a00;margin:0 0 8px;
                              display:flex;align-items:center;gap:6px;">
                        <i class="ri-alert-line"></i> Similar Pods already exist — consider joining instead:
                    </p>
                    <div id="podDupList" style="display:flex;flex-direction:column;gap:6px;"></div>
                    <p style="font-size:11px;color:var(--text-3);margin:8px 0 0;">
                        Your Pod is genuinely different? Carry on.
                    </p>
                </div>

                <form method="POST" enctype="multipart/form-data" id="podForm"
                      onsubmit="return validatePodForm()">

                    <!-- NAME + CATEGORY -->
                    <div class="pod-grid" style="margin-bottom:10px;">
                        <div class="field-wrap">
                            <label class="field-label">Pod name *</label>
                            <input class="field-input" name="title" id="podNameInput"
                                   placeholder="e.g. Funny Memes" maxlength="60" required
                                   autocomplete="off" oninput="onPodNameInput(this)">
                            <div class="field-char" id="podNameCount">0 / 60</div>
                        </div>
                        <div class="field-wrap">
                            <label class="field-label">Category *</label>
                            <select class="field-input" name="category" id="podCategory" required
                                    style="appearance:none;cursor:pointer;"
                                    onchange="checkPodFields()">
                                <option value="" disabled selected>Choose a category</option>
                                <?php
                                $cats = ['Memes & Humor','Gaming','Art & Design','Music','Tech & Dev',
                                         'Sports','Lifestyle','Finance','Education','Entertainment',
                                         'Fashion','Food','Other'];
                                foreach ($cats as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>">
                                    <?php echo htmlspecialchars($cat); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- DESCRIPTION -->
                    <div class="field-wrap pod-full" style="margin-bottom:10px;">
                        <label class="field-label">Description *</label>
                        <textarea class="field-input" name="description" id="podDescInput"
                                  placeholder="What's this Pod about? Who's it for?"
                                  maxlength="300" required rows="3"
                                  oninput="podCharCount(this,'podDescCount',300)"
                                  style="resize:vertical;min-height:72px;"></textarea>
                        <div class="field-char" id="podDescCount">0 / 300</div>
                    </div>

                    <!-- GUIDELINES -->
                    <div class="field-wrap pod-full" style="margin-bottom:10px;">
                        <label class="field-label">Community guidelines *</label>
                        <textarea class="field-input" name="guidelines" id="podGuidelinesInput"
                                  placeholder="e.g. Keep posts on-topic. No hate speech. Credit original creators…"
                                  maxlength="500" required rows="3"
                                  oninput="podCharCount(this,'podGuidelinesCount',500)"
                                  style="resize:vertical;min-height:76px;"></textarea>
                        <div class="field-char" id="podGuidelinesCount">0 / 500</div>
                    </div>

                    <!-- COVER IMAGE -->
                    <div class="field-wrap pod-full" style="margin-bottom:0;">
                        <label class="field-label">Cover image <span style="text-transform:none;letter-spacing:0;font-weight:400">(optional)</span></label>
                        <label class="btn-upload">
                            <i class="ri-image-add-line"></i>
                            <span id="coverLabel">Choose a file or drag &amp; drop</span>
                            <input type="file" name="group_pic" id="podCoverFile" hidden accept="image/*"
                                   onchange="document.getElementById('coverLabel').textContent = this.files[0]?.name || 'Choose a file or drag & drop'">
                        </label>
                    </div>

                    <!-- FOOTER -->
                    <div class="panel-footer">
                        <span class="pod-counter">
                            <i class="ri-folder-line" style="font-size:12px;"></i>
                            <span><?php echo $pod_owns_count; ?></span> / <?php echo $pod_max; ?> pods
                        </span>
                        <button class="btn-primary" name="create_group" id="podSubmitBtn"
        type="submit">
    <i class="ri-add-line" style="font-size:14px;"></i>
    Create Pod
</button>
                    </div>

                </form>
            </div><!-- /podPanelBody -->

        </div><!-- /create-panel -->

        <?php endif; ?>

    </div><!-- /section -->

    <!-- ═══════════════════════════════════════════════
         FELLOW PEUMERS
    ═══════════════════════════════════════════════ -->
    <div class="section">
        <div class="section-label">People</div>
        <div class="section-title">Fellow Peumers</div>
        <div class="scrolltrack">
        <?php
        $blocked_users = [];
        $res = $conn->query("SELECT blocked_id FROM blocks WHERE blocker_id = $current_user");
        while($row = $res->fetch_assoc()){ $blocked_users[] = $row['blocked_id']; }

        $_fp_map = [];
        $pp = $conn->query("SELECT user_id FROM user_settings WHERE is_fully_private=1");
        if($pp) while($r=$pp->fetch_assoc()) $_fp_map[(int)$r['user_id']] = true;

        $excluded     = array_merge([$current_user], $blocked_users);
        $excluded_ids = implode(',', $excluded);
        $rand_seed    = crc32($current_user . date('YmdHi') . uniqid());

        $all_users = $conn->query("
            SELECT u.id, u.username, u.profile_pic,
            (SELECT COUNT(*) FROM followers f WHERE f.follower_id=$current_user AND f.following_id=u.id) as is_following
            FROM users u
            WHERE u.id NOT IN ($excluded_ids)
            ORDER BY RAND($rand_seed)
            LIMIT 50
        ");

        while($u = $all_users->fetch_assoc()):
            $uid          = (int)$u['id'];
            $is_following = (int)$u['is_following'] > 0;
            $is_private   = !empty($_fp_map[$uid]);

            if($is_following) {
                $btn_label = 'Untrack';
                $btn_class = 'fp-track-btn following';
                $btn_priv  = $is_private ? '1' : '0';
                $show_btn  = true;
            } elseif($is_private) {
                $show_btn  = false;
                $btn_label = '';
                $btn_class = '';
                $btn_priv  = '1';
            } else {
                $btn_label = 'Track';
                $btn_class = 'fp-track-btn';
                $btn_priv  = '0';
                $show_btn  = true;
            }
        ?>
        <div class="user-pill" data-profile="profile.php?user_id=<?= $uid ?>">
            <img class="user-pill-avatar"
                 src="<?= !empty($u['profile_pic']) ? htmlspecialchars($u['profile_pic']) : 'uploads/me.jpeg' ?>"
                 onerror="this.src='uploads/me.jpeg'" alt="">
            <div class="user-pill-name"><?= htmlspecialchars($u['username']) ?></div>
            <?php if($show_btn): ?>
            <button class="<?= $btn_class ?>"
                    data-user="<?= $uid ?>"
                    data-private="<?= $btn_priv ?>"><?= $btn_label ?></button>
            <?php endif; ?>
        </div>
        <?php endwhile; ?>
        </div>
    </div>

    <style>
    .fp-track-btn {
        margin-top: 8px;
        padding: 5px 14px;
        border-radius: 20px;
        border: none;
        font-size: 11px;
        font-weight: 600;
        background: rgba(255,43,43,0.55);
        color: white;
        cursor: pointer;
        transition: all 0.25s ease;
        width: 100%;
        font-family: Poppins, sans-serif;
        -webkit-tap-highlight-color: transparent;
    }
    .fp-track-btn.following {
        background: transparent;
        border: 1.5px solid rgba(255,43,43,0.5);
        color: #ff2b2b;
    }
    .fp-track-btn:hover { background:#ff2b2b; color:white; transform:scale(1.04); }
    .fp-track-btn:active { transform:scale(0.97); }
    </style>

    <?php
    $_private_profile_map = [];
    $_approved_map        = [];
    if($current_user){
        $pp = $conn->query("SELECT user_id FROM user_settings WHERE is_fully_private=1");
        if($pp) while($r=$pp->fetch_assoc()) $_private_profile_map[(int)$r['user_id']] = true;
        $ap = $conn->query("SELECT target_id FROM track_requests WHERE requester_id=$current_user AND status='approved'");
        if($ap) while($r=$ap->fetch_assoc()) $_approved_map[(int)$r['target_id']] = true;
    }
    ?>

    <?php
    $tmp_uploads = [];
    while($m = $uploads->fetch_assoc()) {
        $m_uploader = (int)$m['uploaded_by'];
        if($m_uploader != (int)$current_user && !empty($_private_profile_map[$m_uploader]) && empty($_approved_map[$m_uploader])) continue;
        $mid = (int)$m['id'];
        $m['_like_count']    = (int)$conn->query("SELECT COUNT(*) as c FROM likes     WHERE meme_id=$mid")->fetch_assoc()['c'];
        $m['_repost_count']  = (int)$conn->query("SELECT COUNT(*) as c FROM reposts   WHERE meme_id=$mid")->fetch_assoc()['c'];
        $m['_fav_count']     = (int)$conn->query("SELECT COUNT(*) as c FROM favorites WHERE meme_id=$mid")->fetch_assoc()['c'];
        $m['_user_liked']    = $current_user ? $conn->query("SELECT id FROM likes     WHERE meme_id=$mid AND user_id=$current_user")->num_rows > 0 : false;
        $m['_user_reposted'] = $current_user ? $conn->query("SELECT id FROM reposts   WHERE meme_id=$mid AND user_id=$current_user")->num_rows > 0 : false;
        $m['_user_faved']    = $current_user ? $conn->query("SELECT id FROM favorites WHERE meme_id=$mid AND user_id=$current_user")->num_rows > 0 : false;
        $tmp_uploads[] = $m;
    }
    ?>

    <!-- ═══════════════════════════════════════════════
         RECENT UPLOADS
    ═══════════════════════════════════════════════ -->
    <div class="section">
        <div class="section-label">Fresh drops</div>
        <div class="section-title">Recent Uploads</div>
        <div class="scrolltrack" id="uploadsScrolltrack">

        <?php foreach($tmp_uploads as $m):
            $mid    = (int)$m['id'];
            $mtype  = $m['type'] ?? 'image';
            $mfile  = $m['file_name'] ?? '';
            $mpic   = !empty($m['profile_pic']) ? $m['profile_pic'] : 'uploads/me.jpeg';
            $muname = addslashes(htmlspecialchars($m['username']));
            $mcap   = addslashes(htmlspecialchars($m['caption']));
            $muid   = (int)$m['uploaded_by'];
            $mtime  = addslashes($m['created_at'] ?? '');
            $mthumb = ($mtype === 'text' || empty($mfile) || $mfile === '0') ? 'uploads/Text.jpg' : 'uploads/' . $mfile;
        ?>
        <script>
        if(typeof peuraUploadsFeed === 'undefined') var peuraUploadsFeed = [];
        peuraUploadsFeed.push({
            id:          <?= $mid ?>,
            file:        '<?= addslashes($mthumb) ?>',
            type:        '<?= addslashes($mtype) ?>',
            pic:         '<?= addslashes($mpic) ?>',
            userId:      '<?= $muid ?>',
            username:    '<?= $muname ?>',
            caption:     '<?= $mcap ?>',
            time:        '<?= $mtime ?>',
            likeCount:   <?= $m['_like_count'] ?>,
            repostCount: <?= $m['_repost_count'] ?>,
            favCount:    <?= $m['_fav_count'] ?>,
            liked:       <?= $m['_user_liked']    ? 'true' : 'false' ?>,
            reposted:    <?= $m['_user_reposted'] ? 'true' : 'false' ?>,
            faved:       <?= $m['_user_faved']    ? 'true' : 'false' ?>
        });
        </script>

        <div class="meme-card ru-card" data-id="<?= $mid ?>"
             onclick="openUploadsModal('<?= addslashes($mthumb) ?>','<?= addslashes($mtype) ?>','<?= addslashes($mpic) ?>','<?= $muid ?>','<?= $muname ?>','<?= $mcap ?>')">
            <?php if($mtype === 'video'): ?>
                <video class="meme-thumb" src="<?= htmlspecialchars($mthumb) ?>" muted loop></video>
            <?php else: ?>
                <img class="meme-thumb" src="<?= htmlspecialchars($mthumb) ?>" alt="" onerror="this.src='uploads/Text.jpg'">
            <?php endif; ?>
            <div class="meme-footer">
                <img src="<?= htmlspecialchars($mpic) ?>" onerror="this.src='uploads/me.jpeg'"
                     onclick="event.stopPropagation();location.href='profile.php?user_id=<?= $muid ?>'" alt="">
                <div class="meme-footer-text">
                    <div class="meme-footer-user"
                         onclick="event.stopPropagation();location.href='profile.php?user_id=<?= $muid ?>'">
                        <?= htmlspecialchars($m['username']) ?>
                    </div>
                    <div class="meme-footer-caption"><?= htmlspecialchars($m['caption']) ?></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        </div>
    </div>

<!-- ═══════════════════════════════════════════════
     UPLOADS MODAL — must live OUTSIDE .main
     (sibling of .main, .sidebar, #breakOverlay)
═══════════════════════════════════════════════ -->
<div id="uploadsModal"
     onclick="if(event.target===this)closeUploadsModal()"
     style="display:none;position:fixed;inset:0;z-index:999999;
            background:rgba(0,0,0,0.92);backdrop-filter:blur(6px);
            -webkit-backdrop-filter:blur(6px);align-items:center;
            justify-content:center;padding:16px;flex-direction:row;gap:12px;">

    <div id="um-card"
         style="background:rgba(18,17,17,0.95);border:1px solid rgba(255,255,255,0.07);
                border-radius:20px;width:100%;max-width:420px;overflow:hidden;
                display:flex;flex-direction:column;max-height:90vh;">
        <div id="um-inner"></div>
    </div>

    <div id="um-arrows" style="display:flex;flex-direction:column;align-items:center;gap:10px;flex-shrink:0;">
        <button onclick="umLoadIndex(umCurrentIndex-1)"
                onmouseover="this.style.background='rgba(255,255,255,0.15)'"
                onmouseout="this.style.background='rgba(255,255,255,0.07)'"
                style="background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.12);
                       border-radius:50%;width:40px;height:40px;display:flex;align-items:center;
                       justify-content:center;cursor:pointer;flex-shrink:0;transition:background 0.2s;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                 fill="none" stroke="rgba(255,255,255,0.7)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="18 15 12 9 6 15"/>
            </svg>
        </button>
        <button onclick="umLoadIndex(umCurrentIndex+1)"
                onmouseover="this.style.background='rgba(255,255,255,0.15)'"
                onmouseout="this.style.background='rgba(255,255,255,0.07)'"
                style="background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.12);
                       border-radius:50%;width:40px;height:40px;display:flex;align-items:center;
                       justify-content:center;cursor:pointer;flex-shrink:0;transition:background 0.2s;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                 fill="none" stroke="rgba(255,255,255,0.7)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="6 9 12 15 18 9"/>
            </svg>
        </button>
    </div>

    <div id="um-hint"
         style="position:absolute;bottom:10px;left:50%;transform:translateX(-50%);
                color:rgba(255,255,255,0.18);font-size:10px;white-space:nowrap;pointer-events:none;">
        swipe or use arrows ↕
    </div>
</div>

<style>
#um-inner { display:flex; flex-direction:column; overflow:hidden; flex:1; }
.um-caption-box::-webkit-scrollbar { width:4px; }
.um-caption-box::-webkit-scrollbar-thumb { background:rgba(255,255,255,0.15); border-radius:999px; }
.um-action-bar {
    display:flex; align-items:center; gap:6px;
    padding:10px 14px;
    border-top:1px solid rgba(255,255,255,0.06);
    flex-shrink:0;
}
.um-action-btn {
    display:flex; align-items:center; justify-content:center; gap:5px;
    background:transparent; border:1px solid rgba(255,255,255,0.1);
    border-radius:10px; padding:7px 12px;
    color:white; cursor:pointer;
    font-size:12px; font-weight:600;
    font-family:Poppins,sans-serif;
    transition:background 0.2s; flex:1;
}
.um-action-btn:hover { background:rgba(255,255,255,0.07); }
.um-close-btn {
    display:flex; align-items:center; justify-content:center;
    background:transparent; border:1px solid rgba(255,255,255,0.1);
    border-radius:10px; padding:7px 12px;
    color:rgba(255,255,255,0.45); cursor:pointer;
    font-size:12px; font-family:Poppins,sans-serif;
}
.um-track-btn {
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
    -webkit-tap-highlight-color: transparent;
    line-height: 1;
}
.um-track-btn.following {
    background: transparent;
    border: 2px solid rgba(255, 43, 43, 0.51);
    color: #ff2b2b;
}
.um-track-btn:hover {
    background: #ff2b2b;
    color: white;
    transform: scale(1.05);
}
@media(max-width:480px){
    #um-arrows, #um-hint { display:none !important; }
    #uploadsModal { padding:10px !important; align-items:center !important; }
    #um-card { border-radius:20px 20px 14px 14px !important; max-height:88vh !important; }
    .um-caption-box { font-size:12px !important; max-height:60px !important; }
    .um-action-btn  { padding:7px 8px !important; font-size:11px !important; }
    .um-track-btn   { font-size:10px; padding:4px 9px; }
}
@media(max-width:360px){
    .um-caption-box { font-size:11px !important; max-height:55px !important; }
    .um-action-btn  { padding:6px !important; font-size:10px !important; }
    .um-track-btn   { font-size:9px; padding:3px 8px; }
}
</style>


    <!-- ═══════════════════════════════════════════════
         PODS / GROUPS
    ═══════════════════════════════════════════════ -->
    <div class="section">
        <div class="section-label">Pods</div>
        <div class="section-title">Active Pods</div>
        <div class="groups-grid">
        <?php while($g = $groups->fetch_assoc()):
            $g_creator = (int)$g['created_by'];
            if($g_creator != (int)$current_user && !empty($_private_profile_map[$g_creator]) && empty($_approved_map[$g_creator])) continue;
            $group_pic   = $g['group_pic'] ?? '';
            $group_img   = empty($group_pic)   ? 'uploads/default_group.png' : (strpos($group_pic,'uploads/')===0   ? $group_pic   : 'uploads/'.$group_pic);
            $creator_pic = $g['creator_pic'] ?? '';
            $creator_img = empty($creator_pic) ? 'uploads/default.jpg'       : (strpos($creator_pic,'uploads/')===0 ? $creator_pic : 'uploads/'.$creator_pic);
        ?>
        <div class="group-card" onclick="window.location.href='group.php?id=<?php echo $g['id']; ?>'">
            <div class="group-card-header">
                <img class="group-pic" src="<?php echo $group_img; ?>" onerror="this.src='uploads/default_group.png'" alt="">
                <div class="group-info">
                    <h3>
                        <span class="group-verified">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24">
                                <circle cx="12" cy="12" r="12" fill="#0673f0ff"/>
                                <path d="M9 12l2 2 4-4" stroke="white" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </span>
                        <?php echo htmlspecialchars($g['title']); ?>
                    </h3>
                    <p><?php
                    $desc = $g['description'] ?? '';
                    echo htmlspecialchars(mb_strlen($desc) > 60 ? mb_substr($desc,0,60).'...' : $desc);
                    ?></p>
                </div>
            </div>
            <div class="group-creator-row">
                <img src="<?php echo $creator_img; ?>" onerror="this.src='uploads/default.jpg'" alt="">
                <span>
                    <svg xmlns="http://www.w3.org/2000/svg" width="8" height="8" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="12" fill="#FF0000"/>
                        <path d="M9 12l2 2 4-4" stroke="white" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <?php echo htmlspecialchars($g['username']); ?>
                </span>
                <div class="group-join-btn">Enter</div>
            </div>
        </div>
        <?php endwhile; ?>
        </div>
    </div>

</div><!-- end .main -->

<!-- ═══════════════════════════════════════════════
     SCREEN TIME BREAK OVERLAY
═══════════════════════════════════════════════ -->
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



<!-- ═══════════════════════════════════════════════
     ALL JAVASCRIPT
═══════════════════════════════════════════════ -->
<script>

// ─────────────────────────────────────────────
// 1. TRACK BUTTON (Fellow Peumers)
// ─────────────────────────────────────────────
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.fp-track-btn');
    if (btn) { e.stopPropagation(); e.preventDefault(); fpHandleTrack(btn); return; }
    var pill = e.target.closest('.user-pill[data-profile]');
    if (pill) { location.href = pill.dataset.profile; }
});

function fpHandleTrack(btn) {
    var userId    = btn.dataset.user;
    var isPrivate = btn.dataset.private === '1';
    var isUntrack = btn.classList.contains('following');
    var orig      = btn.innerHTML;
    btn.disabled  = true;
    btn.innerHTML = '<i class="ri-loader-4-line" style="animation:spin 0.6s linear infinite;display:inline-block;font-style:normal;"></i>';
    fetch('home.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=toggle_follow&user_id=' + userId
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        btn.disabled = false;
        if (d.status === 'ok') {
            if (isUntrack) {
                if (isPrivate) { btn.style.display = 'none'; }
                else { btn.classList.remove('following'); btn.style.cssText = ''; btn.innerHTML = 'Track'; }
            } else {
                btn.classList.add('following');
                btn.innerHTML        = 'Untrack';
                btn.style.background = 'transparent';
                btn.style.border     = '1.5px solid rgba(255,43,43,0.5)';
                btn.style.color      = '#ff2b2b';
            }
        } else { btn.innerHTML = orig; }
    })
    .catch(function(){ btn.disabled = false; btn.innerHTML = orig; });
}

if (!document.getElementById('fpSpinKf')) {
    var _s = document.createElement('style');
    _s.id  = 'fpSpinKf';
    _s.textContent = '@keyframes spin{to{transform:rotate(360deg);}}';
    document.head.appendChild(_s);
}

// ─────────────────────────────────────────────
// 2. POD PANEL TOGGLE
// ─────────────────────────────────────────────
var _podOpen = false;

function togglePodPanel() {
    var body = document.getElementById('podPanelBody');
    var icon = document.getElementById('podChevronIcon');
    var btn  = document.getElementById('podChevronBtn');
    _podOpen = !_podOpen;
    if (_podOpen) {
        body.style.maxHeight = '1000px';
        body.style.opacity   = '1';
        icon.style.transform = 'rotate(0deg)';
        btn.style.background = 'var(--surface-2)';
    } else {
        body.style.maxHeight = '0px';
        body.style.opacity   = '0';
        icon.style.transform = 'rotate(-180deg)';
        btn.style.background = 'var(--surface)';
    }
}

// ─────────────────────────────────────────────
// 3. POD FORM — VALIDATION + DUPLICATE CHECK
// ─────────────────────────────────────────────

var _podDupTimer = null;

function checkPodFields() {
    var submitBtn = document.getElementById('podSubmitBtn');
    if (!submitBtn) return;
    var title = (document.getElementById('podNameInput')?.value       || '').trim();
    var cat   = (document.getElementById('podCategory')?.value        || '').trim();
    var desc  = (document.getElementById('podDescInput')?.value       || '').trim();
    var rules = (document.getElementById('podGuidelinesInput')?.value || '').trim();
    submitBtn.disabled = !(title.length > 0 && cat.length > 0 && desc.length > 0 && rules.length > 0);
}

function onPodNameInput(input) {
    document.getElementById('podNameCount').textContent = input.value.length + '/60';
    clearTimeout(_podDupTimer);
    _podDupTimer = setTimeout(function() { fetchPodDuplicates(input.value.trim()); }, 450);
    checkPodFields();
}

function podCharCount(el, countId, max) {
    document.getElementById(countId).textContent = el.value.length + '/' + max;
    checkPodFields();
}

function fetchPodDuplicates(name) {
    if (name.length < 3) { document.getElementById('podDupWarn').style.display = 'none'; return; }
    var fd = new FormData();
    fd.append('action', 'check_pod_name');
    fd.append('name',   name);
    fetch('community.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            var list = document.getElementById('podDupList');
            var warn = document.getElementById('podDupWarn');
            if (!d.similar || d.similar.length === 0) { warn.style.display = 'none'; return; }
            list.innerHTML = d.similar.map(function(g) {
                return '<div style="display:flex;align-items:center;justify-content:space-between;' +
                       'background:rgba(255,160,0,0.08);border:1px solid rgba(255,160,0,0.2);' +
                       'border-radius:10px;padding:7px 12px;font-size:12px;color:var(--text-2);">' +
                       '<span><strong style="color:var(--text);">' + g.title + '</strong>' +
                       ' · ' + g.member_count + ' member' + (g.member_count == 1 ? '' : 's') + '</span>' +
                       '<a href="group.php?id=' + g.id + '" style="font-size:11px;color:var(--blue);' +
                       'text-decoration:none;background:rgba(0,87,255,0.1);border-radius:8px;padding:3px 10px;">View</a>' +
                       '</div>';
            }).join('');
            warn.style.display = 'block';
        })
        .catch(function() {});
}

function validatePodForm() {
    var title = (document.getElementById('podNameInput')?.value       || '').trim();
    var cat   = (document.getElementById('podCategory')?.value        || '').trim();
    var desc  = (document.getElementById('podDescInput')?.value       || '').trim();
    var rules = (document.getElementById('podGuidelinesInput')?.value || '').trim();
    if (!title || !cat || desc.length < 20 || rules.length < 20) {
        alert('Please fill in all required fields. Description and guidelines need at least 20 characters each.');
        return false;
    }
    return true;
}

// Run once on load so button state is correct immediately
document.addEventListener('DOMContentLoaded', function() {
    checkPodFields();
});

// ─────────────────────────────────────────────
// 4. UPLOADS MODAL
// ─────────────────────────────────────────────
var umCurrentIndex = 0;
var _umMemeId      = null;

function _umFormatDate(timeStr) {
    if (!timeStr) return '';
    var d = new Date(timeStr.replace(' ', 'T'));
    if (isNaN(d)) return timeStr;
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return d.getDate() + ' ' + months[d.getMonth()] + ' ' + d.getFullYear() +
           ' · ' + String(d.getHours()).padStart(2,'0') + ':' + String(d.getMinutes()).padStart(2,'0');
}

function _umLinkify(caption) {
    return caption.replace(/@([a-zA-Z0-9_]+)/g, function(match, username) {
        return '<a href="profile.php?username=' + encodeURIComponent(username) +
               '" onclick="event.stopPropagation()" style="color:#f24455;font-weight:600;text-decoration:none;" ' +
               'onmouseover="this.style.textDecoration=\'underline\'" onmouseout="this.style.textDecoration=\'none\'">' + match + '</a>';
    });
}

function umToggleTrack(btn) {
    var userId = btn.dataset.user;
    fetch('community.php', {
        method:  'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body:    'action=toggle_follow&user_id=' + userId
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        if (data.status === 'ok') {
            if (data.following) {
                btn.textContent = 'Untrack';
                btn.classList.add('following');
            } else {
                btn.textContent = 'Track';
                btn.classList.remove('following');
            }
            document.querySelectorAll('.track-btn[data-user="' + userId + '"], .fp-track-btn[data-user="' + userId + '"]').forEach(function(fb){
                if (data.following) {
                    fb.textContent = 'Untrack';
                    fb.classList.add('following');
                } else {
                    fb.textContent = 'Track';
                    fb.classList.remove('following');
                }
            });
            if (typeof peuraUploadsFeed !== 'undefined') {
                var feedItem = peuraUploadsFeed.find(function(p){ return String(p.userId) === String(userId); });
                if (feedItem) feedItem.isFollowing = data.following;
            }
        }
    })
    .catch(function(err){ console.error('umToggleTrack error:', err); });
}

function _umRenderPost(item) {
    var p = item.data;
    _umMemeId = p.id;
    var mediaHtml = '';
    if (p.type === 'video') {
        mediaHtml = '<video src="' + p.file + '" autoplay muted loop playsinline style="width:100%;max-height:52vh;object-fit:contain;display:block;"></video>';
    } else if (p.type !== 'text' && p.file) {
        mediaHtml = '<img src="' + p.file + '" onerror="this.src=\'uploads/Text.jpg\'" style="width:100%;max-height:52vh;object-fit:contain;display:block;">';
    }
    var lc = p.likeCount || 0, rc = p.repostCount || 0, fc = p.favCount || 0;
    var li = p.liked || false, re = p.reposted || false, fa = p.faved || false;
    var currentUserId = <?php echo (int)$current_user; ?>;

    var isOwnPost = String(p.userId) === String(currentUserId);

    var isFollowing = false;
    if (typeof p.isFollowing !== 'undefined') {
        isFollowing = !!p.isFollowing;
    } else {
        var feedTrackBtn = document.querySelector('.track-btn[data-user="' + p.userId + '"], .fp-track-btn[data-user="' + p.userId + '"]');
        isFollowing = feedTrackBtn ? feedTrackBtn.classList.contains('following') : false;
    }

    var trackBtnHtml = !isOwnPost
        ? '<button class="um-track-btn ' + (isFollowing ? 'following' : '') + '" ' +
              'data-user="' + p.userId + '" ' +
              'onclick="event.stopPropagation();umToggleTrack(this)">' +
              (isFollowing ? 'Untrack' : 'Track') +
          '</button>'
        : '';

    return '' +
        '<div style="display:flex;align-items:center;gap:10px;padding:14px 16px;flex-shrink:0;">' +
            '<img src="' + (p.pic || 'uploads/me.jpeg') + '" onerror="this.src=\'uploads/me.jpeg\'" ' +
                 'onclick="location.href=\'profile.php?user_id=' + p.userId + '\'" ' +
                 'style="width:40px;height:40px;border-radius:50%;border:2px solid #FF4C4C;cursor:pointer;object-fit:cover;flex-shrink:0;">' +
            '<div style="flex:1;min-width:0;">' +
                '<div style="display:flex;align-items:center;gap:7px;flex-wrap:nowrap;">' +
                    '<div onclick="location.href=\'profile.php?user_id=' + p.userId + '\'" ' +
                         'style="color:#FF4C4C;font-weight:700;font-size:14px;cursor:pointer;display:flex;align-items:center;gap:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex-shrink:1;min-width:0;">' +
                        '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" style="flex-shrink:0;">' +
                            '<circle cx="12" cy="12" r="12" fill="#FF0000"/>' +
                            '<path d="M9 12l2 2 4-4" stroke="white" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>' +
                        '</svg>' +
                        '<span>' + (isOwnPost ? 'You' : p.username) + '</span>' +
                    '</div>' +
                    trackBtnHtml +
                '</div>' +
                '<div style="font-size:11px;color:rgba(255,255,255,0.35);margin-top:3px;display:flex;align-items:center;gap:4px;">' +
                    '<i class="ri-time-line" style="font-size:10px;"></i>' +
                    '<span>' + _umFormatDate(p.time || '') + '</span>' +
                '</div>' +
            '</div>' +
            '<button onclick="closeUploadsModal()" style="background:rgba(255,255,255,0.06);border:none;border-radius:50%;width:30px;height:30px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:rgba(255,255,255,0.5);font-size:16px;flex-shrink:0;">✕</button>' +
        '</div>' +
        (mediaHtml ? '<div style="width:100%;background:#000;flex-shrink:0;">' + mediaHtml + '</div>' : '') +
        '<div class="um-caption-box" style="padding:10px 16px;color:rgba(255,255,255,0.8);font-size:13px;line-height:1.55;border-top:1px solid rgba(255,255,255,0.06);word-break:break-word;white-space:pre-wrap;overflow-y:auto;max-height:80px;flex-shrink:0;">' + _umLinkify(p.caption || '') + '</div>' +
        '<div class="um-action-bar">' +
            '<button id="um-like-btn" class="um-action-btn" onclick="umAction(\'like\')" style="color:' + (li?'#ff4c4c':'white') + ';border-color:' + (li?'rgba(255,76,76,0.4)':'rgba(255,255,255,0.1)') + ';">' +
                '<i class="ri-heart-fill" style="font-size:14px;"></i><span id="um-like-count">' + lc + '</span></button>' +
            '<button id="um-repost-btn" class="um-action-btn" onclick="umAction(\'repost\')" style="color:' + (re?'#ff4c4c':'white') + ';border-color:' + (re?'rgba(255,76,76,0.4)':'rgba(255,255,255,0.1)') + ';">' +
                '<i class="ri-repeat-line" style="font-size:14px;"></i><span id="um-repost-count">' + rc + '</span></button>' +
            '<button id="um-fav-btn" class="um-action-btn" onclick="umAction(\'fav\')" style="color:' + (fa?'#ff4c4c':'white') + ';border-color:' + (fa?'rgba(255,76,76,0.4)':'rgba(255,255,255,0.1)') + ';">' +
                '<i class="ri-bookmark-fill" style="font-size:14px;"></i><span id="um-fav-count">' + fc + '</span></button>' +
            '<button class="um-close-btn" onclick="closeUploadsModal()">Close</button>' +
        '</div>';
}

function _umRenderSlot(index) {
    if (index < 0 || index >= peuraUploadsFeed.length) return;
    var inner = document.getElementById('um-inner');
    inner.querySelectorAll('video').forEach(function(v){ v.pause(); v.src = ''; });
    inner.innerHTML = _umRenderPost({ type: 'post', data: peuraUploadsFeed[index] });
}

function openUploadsModal(file, type, pic, userId, username, caption) {
    var idx = peuraUploadsFeed.findIndex(function(p){ return p.file === file && String(p.userId) === String(userId); });
    umCurrentIndex = idx >= 0 ? idx : 0;
    _umRenderSlot(umCurrentIndex);
    var modal = document.getElementById('uploadsModal');
    modal.style.display = 'flex';
    modal.style.overscrollBehavior  = 'contain';
    document.body.style.overflow    = 'hidden';
    document.body.style.touchAction = 'none';
}

function umLoadIndex(index) {
    if (index < 0 || index >= peuraUploadsFeed.length) return;
    var card = document.getElementById('um-card');
    var dir  = index > umCurrentIndex ? 1 : -1;
    card.style.transition = 'transform 0.22s ease,opacity 0.22s ease';
    card.style.transform  = 'translateY(' + (dir * -30) + 'px)';
    card.style.opacity    = '0';
    setTimeout(function() {
        umCurrentIndex = index;
        _umRenderSlot(umCurrentIndex);
        card.style.transition = 'none';
        card.style.transform  = 'translateY(' + (dir * 30) + 'px)';
        card.style.opacity    = '0';
        requestAnimationFrame(function() {
            card.style.transition = 'transform 0.22s ease,opacity 0.22s ease';
            card.style.transform  = 'translateY(0)';
            card.style.opacity    = '1';
        });
    }, 220);
}

function closeUploadsModal() {
    document.getElementById('um-inner').querySelectorAll('video').forEach(function(v){ v.pause(); v.src=''; });
    var modal = document.getElementById('uploadsModal');
    modal.style.display             = 'none';
    document.body.style.overflow    = '';
    document.body.style.touchAction = '';
    _umMemeId = null;
}

function umAction(type) {
    if (!_umMemeId) return;
    var btnMap    = { like:'um-like-btn',    repost:'um-repost-btn',    fav:'um-fav-btn'      };
    var cntMap    = { like:'um-like-count',  repost:'um-repost-count',  fav:'um-fav-count'    };
    var actionMap = { like:'toggle_like',    repost:'toggle_repost',    fav:'toggle_favorite' };
    var cntField  = { like:'likeCount',      repost:'repostCount',      fav:'favCount'        };
    var stateField= { like:'liked',          repost:'reposted',         fav:'faved'           };
    fetch('community.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=' + actionMap[type] + '&meme_id=' + _umMemeId
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if (d.count === undefined) return;
        var feedItem  = peuraUploadsFeed.find(function(p){ return String(p.id) === String(_umMemeId); });
        var wasActive = feedItem ? feedItem[stateField[type]] : false;
        var nowActive = !wasActive;
        var umBtn  = document.getElementById(btnMap[type]);
        var umSpan = document.getElementById(cntMap[type]);
        if (umBtn)  { umBtn.style.color = nowActive ? '#ff4c4c' : 'white'; umBtn.style.borderColor = nowActive ? 'rgba(255,76,76,0.4)' : 'rgba(255,255,255,0.1)'; }
        if (umSpan) umSpan.textContent = d.count;
        if (feedItem) { feedItem[cntField[type]] = d.count; feedItem[stateField[type]] = nowActive; }
        var gridSelMap = { like:'.like-btn', repost:'.repost-btn', fav:'.fav-btn' };
        var gridBtn = document.querySelector(gridSelMap[type] + '[data-id="' + _umMemeId + '"]');
        if (gridBtn) {
            if (nowActive) gridBtn.classList.add('active'); else gridBtn.classList.remove('active');
            var countEl = gridBtn.querySelector('.count');
            if (countEl) countEl.textContent = d.count;
        }
    })
    .catch(function(err){ console.error('umAction error:', err); });
}

// Modal — wheel
document.getElementById('uploadsModal').addEventListener('wheel', function(e) {
    e.preventDefault(); e.stopPropagation();
    if (e.deltaY > 0) umLoadIndex(umCurrentIndex + 1);
    else              umLoadIndex(umCurrentIndex - 1);
}, { passive: false });

// Modal — touch swipe
var umTouchStartY = 0;
document.getElementById('uploadsModal').addEventListener('touchstart', function(e) { umTouchStartY = e.touches[0].clientY; }, { passive: true });
document.getElementById('uploadsModal').addEventListener('touchmove',  function(e) { e.stopPropagation(); e.preventDefault(); }, { passive: false });
document.getElementById('uploadsModal').addEventListener('touchend',   function(e) {
    var diff = umTouchStartY - e.changedTouches[0].clientY;
    if (Math.abs(diff) > 40) { if (diff > 0) umLoadIndex(umCurrentIndex + 1); else umLoadIndex(umCurrentIndex - 1); }
}, { passive: true });

// Modal — keyboard
document.addEventListener('keydown', function(e) {
    if (document.getElementById('uploadsModal').style.display === 'flex') {
        if (e.key === 'Escape')    closeUploadsModal();
        if (e.key === 'ArrowDown') umLoadIndex(umCurrentIndex + 1);
        if (e.key === 'ArrowUp')   umLoadIndex(umCurrentIndex - 1);
    }
});

// ─────────────────────────────────────────────
// 5. SCREEN TIME BREAK OVERLAY
// ─────────────────────────────────────────────
(function() {
    var LIMIT_MS  = 60 * 60 * 1000;
    var STORE_KEY = 'peura_session_start';
    var CODE      = '1212';
    if (!sessionStorage.getItem(STORE_KEY)) sessionStorage.setItem(STORE_KEY, Date.now());
    function showBreak() { document.getElementById('breakOverlay').classList.add('active');    document.body.style.overflow = 'hidden'; }
    function hideBreak() { document.getElementById('breakOverlay').classList.remove('active'); document.body.style.overflow = ''; sessionStorage.setItem(STORE_KEY, Date.now()); }
    function checkTime() { if (Date.now() - parseInt(sessionStorage.getItem(STORE_KEY)) >= LIMIT_MS) showBreak(); }
    setInterval(checkTime, 30000);
    window.addEventListener('load', checkTime);
    window.checkBreakCode = function() {
        var val = document.getElementById('breakCodeInput').value;
        var inp = document.getElementById('breakCodeInput');
        if (val.length === 4) {
            if (val === CODE) { inp.style.borderColor = '#44ff88'; setTimeout(hideBreak, 300); inp.value = ''; }
            else { inp.classList.remove('shake'); void inp.offsetWidth; inp.classList.add('shake'); inp.value = ''; setTimeout(function(){ inp.classList.remove('shake'); }, 400); }
        }
    };
    window.tryBreakReturn = function() {
        var val = document.getElementById('breakCodeInput').value;
        var inp = document.getElementById('breakCodeInput');
        if (val === CODE) { inp.style.borderColor = '#44ff88'; setTimeout(hideBreak, 300); inp.value = ''; }
        else { inp.classList.remove('shake'); void inp.offsetWidth; inp.classList.add('shake'); inp.value = ''; setTimeout(function(){ inp.classList.remove('shake'); }, 400); }
    };
})();

// ─────────────────────────────────────────────
// 6. DOM READY — particles, videos, drag-scroll, pod button
// ─────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {

    // Particles
    var container = document.getElementById('particles');
    for (var i = 0; i < 70; i++) {
        var p = document.createElement('div');
        p.className = 'particle';
        var size = Math.random() * 2 + 3;
        p.style.width  = size + 'px';
        p.style.height = size + 'px';
        p.style.left   = Math.random() * 100 + 'vw';
        p.style.top    = Math.random() * 100 + 'vh';
        p.style.setProperty('--x-move', (Math.random() * 200 - 100) + 'px');
        p.style.setProperty('--y-move', (Math.random() * -300 - 100) + 'px');
        p.style.animationDuration = (Math.random() * 15 + 10) + 's';
        p.style.opacity = Math.random() * 0.5 + 0.2;
        container.appendChild(p);
    }

    // Video autoplay
    document.querySelectorAll('video').forEach(function(v) { v.muted = true; v.play().catch(function(){}); });

    // Drag to scroll
    document.querySelectorAll('.scrolltrack').forEach(function(track) {
        var isDown = false, startX, scrollLeft;
        track.addEventListener('mousedown',  function(e) { isDown = true; track.style.cursor = 'grabbing'; startX = e.pageX - track.offsetLeft; scrollLeft = track.scrollLeft; });
        track.addEventListener('mouseleave', function()  { isDown = false; track.style.cursor = ''; });
        track.addEventListener('mouseup',    function()  { isDown = false; track.style.cursor = ''; });
        track.addEventListener('mousemove',  function(e) { if (!isDown) return; e.preventDefault(); track.scrollLeft = scrollLeft - (e.pageX - track.offsetLeft - startX) * 1.4; });
    });

});

</script>

</body>
</html>