<?php
session_start();
include 'config.php';

/* permanent Pod ID helper (PD-XXXXXXXXXX) — pd_assign_id() */
require_once __DIR__ . '/pod_id.php';

/* offline helpers (same ones the Home page uses: banner + full-screen "No internet" state).
   shared_core.php normally loads offline.php; here we load it directly if it isn't already loaded. */
if (!function_exists('dz_offline_ui') && file_exists(__DIR__ . '/offline.php')) {
    require_once __DIR__ . '/offline.php';
}

/* ══════════════════════════════════════════════════════════════════
   AUTH
   ══════════════════════════════════════════════════════════════════ */
$current_user = (int)($_SESSION['user_id'] ?? 0);
if (!$current_user) { header("Location: login.php"); exit(); }

/* ── nav profile pic (kept in case nav.php uses $nav_pic) ── */
$nav_pic = 'uploads/mee.jpeg';
$nav_row = $conn->query("SELECT profile_pic FROM users WHERE id=$current_user LIMIT 1")->fetch_assoc();
if (!empty($nav_row['profile_pic'])) $nav_pic = $nav_row['profile_pic'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$pod_category_options = [
    'Memes & Humor',
    'Internet Culture',
    'Gaming',
    'Technology',
    'Science',
    'Space',
    'Education & Careers',
    'Dezzme Official',
    'History',
    'Books & Writing',
    'Art & Design',
    'Photography',
    'Movies & TV',
    'Anime & Manga',
    'Comics & Cartoons',
    'Music',
    'Podcasts',
    'Entertainment',
    'Fandom',
    'Pop Culture',
    'News',
    'Politics',
    'Business',
    'Finance',
    'Lifestyle',
    'Fashion',
    'Beauty & Skincare',
    'Health & Fitness',
    'Mental Wellness',
    'Relationships & Family',
    'Travel',
    'Food & Drinks',
    'Sports',
    'Esports',
    'Pets',
    'Animals',
    'Nature & Wildlife',
    'Home & DIY',
    'Architecture',
    'Collectibles',
    'Automotive',
    'Luxury',
    'Shopping',
    'Language Learning',
    'Culture',
    'Religion & Spirituality',
    'Philosophy',
    'LGBTQ+',
    'Identity & Communities',
    'Creators',
    'Streaming',
    'Marketplace',
    'Support',
    'Discussions & Q&A',
    'Events & Challenges',
    'Creative Edits',
    'Media Sharing',
    'Wallpapers',
    'Aesthetics',
    'Brainrot',
    'Shitposting',
    'Trending',
    'Throwbacks & Nostalgia',
    'Other'
];

/* ══════════════════════════════════════════════════════════════════
   HELPERS
   ══════════════════════════════════════════════════════════════════ */
function cm_img($path, $default) {
    if (empty($path)) return $default;
    return strpos($path, 'uploads/') === 0 ? $path : 'uploads/' . $path;
}

/* suspended pods (set from the reports centre): the column is created here if it does not exist yet, so this page never errors before the first suspension */
if (empty($_SESSION['susp_cols_v1'])) {
    try {
        $__c = $conn->query("SHOW COLUMNS FROM `groups` LIKE 'is_suspended'");
        if ($__c && !$__c->num_rows) $conn->query("ALTER TABLE `groups` ADD COLUMN is_suspended TINYINT(1) NOT NULL DEFAULT 0");
        $_SESSION['susp_cols_v1'] = 1;
    } catch (Throwable $e) {}
}
function cm_pod_payload($conn, $group_id, $title, $category, $group_pic) {
    $suspended = 0;
    try { $sq = $conn->query("SELECT is_suspended FROM `groups` WHERE id=" . (int)$group_id . " LIMIT 1"); if ($sq && ($sr = $sq->fetch_assoc())) $suspended = (int)$sr['is_suspended']; } catch (Throwable $e) {}
    $member_count = (int)$conn->query("SELECT COUNT(*) c FROM group_members WHERE group_id=" . (int)$group_id)->fetch_assoc()['c'];
    $avatars = [];
    $res = $conn->query("SELECT u.profile_pic FROM group_members gm JOIN users u ON u.id = gm.user_id WHERE gm.group_id=" . (int)$group_id . " LIMIT 7");
    while ($r = $res->fetch_assoc()) {
        $avatars[] = cm_img($r['profile_pic'], 'mee.jpg');
    }
    return [
        'id' => (int)$group_id,
        'title' => $title,
        'category' => $category !== '' && $category !== null ? $category : 'Uncategorized',
        'group_pic' => cm_img($group_pic, 'uploads/default_group.png'),
        'member_count' => $member_count,
        'avatars' => $avatars,
        'suspended' => $suspended ? 1 : 0,
    ];
}

/* ══════════════════════════════════════════════════════════════════
   AJAX: LOAD PODS  (must run before any HTML output)
   ══════════════════════════════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'load_pods') {
    header('Content-Type: application/json');

    $tab      = $_GET['tab'] ?? 'all';
    $category = trim($_GET['category'] ?? '');
    $search   = trim($_GET['search'] ?? '');
    $offset   = max(0, (int)($_GET['offset'] ?? 0));
    $seed     = (int)($_GET['seed'] ?? 1);
    $limit    = 8; // ── 8 pods per page/infinite-scroll batch ──

    $where = [];
    if ($category !== '' && $category !== 'All') {
        $where[] = "g.category = '" . $conn->real_escape_string($category) . "'";
    }
    if ($search !== '') {
        $where[] = "g.title LIKE '%" . $conn->real_escape_string($search) . "%'";
    }

    $pods = [];

    if ($tab === 'created') {
        $where[] = "g.created_by = $current_user";
        $where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $sql = "SELECT g.id, g.title, g.category, g.group_pic FROM groups g $where_sql ORDER BY g.created_at DESC LIMIT $limit OFFSET $offset";
        $res = $conn->query($sql);
        while ($r = $res->fetch_assoc()) {
            $pods[] = cm_pod_payload($conn, $r['id'], $r['title'], $r['category'], $r['group_pic']);
        }
        $total_sql = "SELECT COUNT(*) c FROM groups g " . ($where ? ('WHERE ' . implode(' AND ', $where)) : '');
        $total = (int)$conn->query($total_sql)->fetch_assoc()['c'];

    } elseif ($tab === 'discover') {
        $cat_res = $conn->query("SELECT DISTINCT g2.category FROM group_members gm2 JOIN groups g2 ON g2.id = gm2.group_id WHERE gm2.user_id=$current_user AND g2.category IS NOT NULL AND g2.category!=''");
        $followed_cats = [];
        while ($c = $cat_res->fetch_assoc()) { if (!empty($c['category'])) $followed_cats[] = $c['category']; }

        if (empty($followed_cats)) {
            $pods = [];
            $total = 0;
        } else {
            $cat_list = implode(',', array_map(function($c) use ($conn) { return "'" . $conn->real_escape_string($c) . "'"; }, $followed_cats));
            $where[] = "g.category IN ($cat_list)";
            $where[] = "g.created_by != $current_user";
            $where[] = "g.id NOT IN (SELECT group_id FROM group_members WHERE user_id=$current_user)";
            $where_sql = 'WHERE ' . implode(' AND ', $where);
            $sql = "SELECT g.id, g.title, g.category, g.group_pic FROM groups g $where_sql ORDER BY RAND($seed) LIMIT $limit OFFSET $offset";
            $res = $conn->query($sql);
            while ($r = $res->fetch_assoc()) {
                $pods[] = cm_pod_payload($conn, $r['id'], $r['title'], $r['category'], $r['group_pic']);
            }
            $total_sql = "SELECT COUNT(*) c FROM groups g $where_sql";
            $total = (int)$conn->query($total_sql)->fetch_assoc()['c'];
        }

    } else { // all pods
        $where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $sql = "SELECT g.id, g.title, g.category, g.group_pic FROM groups g $where_sql ORDER BY RAND($seed) LIMIT $limit OFFSET $offset";
        $res = $conn->query($sql);
        while ($r = $res->fetch_assoc()) {
            $pods[] = cm_pod_payload($conn, $r['id'], $r['title'], $r['category'], $r['group_pic']);
        }
        $total_sql = "SELECT COUNT(*) c FROM groups g " . $where_sql;
        $total = (int)$conn->query($total_sql)->fetch_assoc()['c'];
    }

    echo json_encode([
        'pods' => $pods,
        'has_more' => ($offset + $limit) < $total,
    ]);
    exit();
}

/* ══════════════════════════════════════════════════════════════════
   CREATE POD — policy agreement + validation (enforced on the server too)
   ══════════════════════════════════════════════════════════════════ */
const CM_MAX_OWNED   = 3;   // pods one person can own
const CM_MAX_PER_DAY = 1;   // pods one person can create per 24 hours

function cm_len($s){ return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s); }
function cm_cut($s,$n){ return function_exists('mb_substr') ? mb_substr($s,0,$n) : substr($s,0,$n); }
function cm_count($conn, $sql){
    try { $r = $conn->query($sql); if ($r instanceof mysqli_result) { $row = $r->fetch_row(); return (int)($row[0] ?? 0); } } catch (Throwable $e) {}
    return 0;
}
function cm_redirect_err($code){ header("Location: community.php?error=" . urlencode($code)); exit(); }
function cm_unlink($name){ if ($name !== '' && strpos($name, '/') === false) @unlink(__DIR__ . '/uploads/' . $name); }

/* stores when the owner agreed to the Pods Policy (column is added automatically if it is missing) */
function cm_has_policy_col($conn){
    if (isset($_SESSION['cm_policy_col'])) return (bool)$_SESSION['cm_policy_col'];
    $has = false;
    try {
        $r = $conn->query("SHOW COLUMNS FROM groups LIKE 'policy_agreed_at'");
        if ($r && $r->num_rows > 0) { $has = true; }
        else { $conn->query("ALTER TABLE groups ADD COLUMN policy_agreed_at DATETIME NULL"); $has = true; }
    } catch (Throwable $e) { $has = false; }
    $_SESSION['cm_policy_col'] = $has ? 1 : 0;
    return $has;
}

/* validated image upload (real mime check, random file name) */
function cm_save_image($f, $prefix, $maxMB){
    if (!$f || !isset($f['error']) || $f['error'] === UPLOAD_ERR_NO_FILE) return ['ok'=>true, 'name'=>''];
    if ($f['error'] !== UPLOAD_ERR_OK) return ['ok'=>false];
    if ($f['size'] > $maxMB * 1048576) return ['ok'=>false];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $allowed = ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/gif'=>'gif', 'image/webp'=>'webp'];
    if (!isset($allowed[$mime])) return ['ok'=>false];
    $dir = __DIR__ . '/uploads/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($f['tmp_name'], $dir . $name)) return ['ok'=>false];
    return ['ok'=>true, 'name'=>$name];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_pod'])) {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        die('Invalid request.');
    }

    $pod_name       = preg_replace('/\s+/', ' ', trim(strip_tags((string)($_POST['pod_name'] ?? ''))));
    $pod_desc       = trim(strip_tags((string)($_POST['pod_description'] ?? '')));
    $pod_cat        = trim((string)($_POST['pod_category'] ?? ''));
    $pod_guidelines = trim(strip_tags((string)($_POST['pod_guidelines'] ?? '')));

    /* 1. the Pods Policy must be agreed to (both the agreement and the acknowledgement) */
    if (($_POST['agree'] ?? '') !== '1' || ($_POST['ack'] ?? '') !== '1') cm_redirect_err('policy_required');

    /* 2. name */
    $nl = cm_len($pod_name);
    if ($nl === 0) cm_redirect_err('name_required');
    if ($nl < 3 || $nl > 60) cm_redirect_err('name_short');
    if (!preg_match('/[\p{L}\p{N}]/u', $pod_name)) cm_redirect_err('name_invalid');
    if (preg_match('/dezzme\s*(official|staff|team|support|admin|moderator)|official\s*dezzme/i', $pod_name)) cm_redirect_err('name_reserved');

    /* 3. description + category + picture */
    if (cm_len($pod_desc) < 20) cm_redirect_err('desc_short');
    $pod_desc = cm_cut($pod_desc, 500);
    $pod_guidelines = cm_cut($pod_guidelines, 1000);
    if ($pod_cat === '' || !in_array($pod_cat, $pod_category_options, true)) cm_redirect_err('cat_required');
    if (empty($_FILES['pod_group_pic']) || ($_FILES['pod_group_pic']['error'] ?? 4) === UPLOAD_ERR_NO_FILE) cm_redirect_err('pic_required');

    /* 4. limits */
    if (cm_count($conn, "SELECT COUNT(*) FROM groups WHERE created_by=$current_user") >= CM_MAX_OWNED) cm_redirect_err('limit_owned');
    if (cm_count($conn, "SELECT COUNT(*) FROM groups WHERE created_by=$current_user AND created_at > (NOW() - INTERVAL 1 DAY)") >= CM_MAX_PER_DAY) cm_redirect_err('limit_daily');

    /* 5. unique name */
    $chk = $conn->prepare("SELECT id FROM groups WHERE LOWER(title)=LOWER(?) LIMIT 1");
    $chk->bind_param("s", $pod_name); $chk->execute();
    $taken = $chk->get_result()->num_rows > 0; $chk->close();
    if ($taken) cm_redirect_err('name_taken');

    /* 6. images (validated before anything is saved to the database) */
    $gp = cm_save_image($_FILES['pod_group_pic'], 'pod', 5);
    if (!$gp['ok']) cm_redirect_err('pic_bad');
    $bp = cm_save_image($_FILES['pod_banner_pic'] ?? null, 'pod_banner', 8);
    if (!$bp['ok']) { cm_unlink($gp['name']); cm_redirect_err('banner_bad'); }
    $group_pic_name  = $gp['name'];
    $banner_pic_name = $bp['name'];

    if (cm_has_policy_col($conn)) {
        $stmt = $conn->prepare("INSERT INTO groups (title, description, category, group_pic, banner_pic, custom_guidelines, created_by, privacy, policy_agreed_at, created_at) VALUES (?,?,?,?,?,?,?,'public',NOW(),NOW())");
    } else {
        $stmt = $conn->prepare("INSERT INTO groups (title, description, category, group_pic, banner_pic, custom_guidelines, created_by, privacy, created_at) VALUES (?,?,?,?,?,?,?,'public',NOW())");
    }
    $stmt->bind_param("ssssssi", $pod_name, $pod_desc, $pod_cat, $group_pic_name, $banner_pic_name, $pod_guidelines, $current_user);
    if (!$stmt->execute()) { $stmt->close(); cm_unlink($group_pic_name); cm_unlink($banner_pic_name); cm_redirect_err('create_failed'); }
    $new_group_id = $stmt->insert_id;
    $stmt->close();

    /* give the new pod its permanent, unique PD-XXXXXXXXXX id (never changes afterwards) */
    try { pd_assign_id($conn, (int)$new_group_id); } catch (Throwable $e) { /* the About page assigns it later if this ever fails */ }

    $stmt = $conn->prepare("INSERT INTO group_members (group_id, user_id) VALUES (?,?)");
    $stmt->bind_param("ii", $new_group_id, $current_user);
    $stmt->execute();
    $stmt->close();

    header("Location: group.php?id=$new_group_id");
    exit();
}

/* Random seed for this page load — reshuffles "All Pods" / "Discover" each refresh */
$rand_seed = mt_rand(1, 1000000);

/* ══════════════════════════════════════════════════════════════════
   RIGHT-COLUMN DATA (stats, top categories, popular pods)
   ══════════════════════════════════════════════════════════════════ */
$st_pods    = (int)$conn->query("SELECT COUNT(*) c FROM groups")->fetch_assoc()['c'];
$st_members = (int)$conn->query("SELECT COUNT(*) c FROM group_members")->fetch_assoc()['c'];
$st_mine    = (int)$conn->query("SELECT COUNT(*) c FROM groups WHERE created_by=$current_user")->fetch_assoc()['c'];
$st_joined  = (int)$conn->query("SELECT COUNT(*) c FROM group_members WHERE user_id=$current_user")->fetch_assoc()['c'];

$top_cats = [];
$res = $conn->query("SELECT category, COUNT(*) c FROM groups WHERE category IS NOT NULL AND category != '' GROUP BY category ORDER BY c DESC LIMIT 6");
if ($res) { while ($r = $res->fetch_assoc()) $top_cats[] = $r; }

$popular_pods = [];
$res = $conn->query("SELECT g.id, g.title, g.group_pic, g.is_suspended, COUNT(gm.user_id) mc FROM groups g LEFT JOIN group_members gm ON gm.group_id = g.id GROUP BY g.id, g.title, g.group_pic, g.is_suspended ORDER BY mc DESC LIMIT 5");
if ($res) { while ($r = $res->fetch_assoc()) $popular_pods[] = $r; }

/* search icon — the exact same icon as the Search button in nav.php */
$search_svg = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle class="s-ring" cx="10" cy="10" r="6.5" fill="none" stroke="currentColor" stroke-width="2.5"/><path class="s-handle" fill="currentColor" d="M15.37 14.23L21.41 18.59A2 2 0 0 1 18.59 21.41L14.23 15.37Z"/></svg>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dezzme Pods</title>
<script>try{if(localStorage.getItem('dz_theme')==='light')document.documentElement.setAttribute('data-theme','light');}catch(e){}</script>
<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&family=Bebas+Neue&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; font-family:'Poppins', sans-serif; }
button, a { cursor:pointer; outline:none; -webkit-tap-highlight-color:transparent; }
a { color:inherit; text-decoration:none; }
img { max-width:100%; display:block; }

[class^="ri-"], [class*=" ri-"],
[class^="ri-"]::before, [class*=" ri-"]::before,
[class^="ri-"]::after,  [class*=" ri-"]::after {
    font-family:'remixicon' !important;
    font-style:normal; font-weight:normal; line-height:1;
    speak:none; text-transform:none;
    -webkit-font-smoothing:antialiased;
}

:root{
    --accent:        #2a5fd8;
    --accent-hover:  #234fb5;
    --accent-soft:   rgba(42,95,216,.18);
    --accent-border: rgba(42,95,216,.42);
    --accent-fill:   rgba(42,95,216,.24);
    --accent-text:   #7ea6ff;
    --accent-focus:  rgba(42,95,216,.85);
    --accent-ring:   rgba(42,95,216,.18);
    --accent-sel:    rgba(42,95,216,.4);
    --scroll-thumb:  rgba(42,95,216,.55);
    --on-accent:     #000;
    --fg:            255,255,255;   /* R,G,B of the text-on-surface tint used for lines + soft fills (dark theme = white) */

    --danger:        #ff6b6b;
    --solid:         #070924;
    --surface:       rgba(var(--fg),0.05);
    --border:        rgba(var(--fg),0.14);
    --line:          rgba(var(--fg),0.09);
    --text:          #ffffff;
    --muted:         rgba(var(--fg),0.45);
    --muted-2:       rgba(var(--fg),0.3);
    --sub:           rgba(var(--fg),0.62);
    --bg: linear-gradient(-45deg,#010218,#070924,#070924);

    --max-w:    580px;
    --topbar-h: 54px;
    --pad:      clamp(9px, 3vw, 16px);
    --ctl-h:    clamp(30px, 8vw, 36px);
    --r:        5px;
    --act-h:    clamp(24px, 6.6vw, 28px);
    --act-r:    4px;
}

html{ scroll-behavior:smooth; -webkit-text-size-adjust:100%; }

/* responsive view: no scrollbars anywhere (scrolling still works) */
@media (max-width:768px){
    html, body, *{ scrollbar-width:none !important; -ms-overflow-style:none !important; }
    *::-webkit-scrollbar, html::-webkit-scrollbar, body::-webkit-scrollbar{ display:none !important; width:0 !important; height:0 !important; background:transparent !important; }
}
::selection{ background:var(--accent-sel); color:#fff; }

body{
    background:var(--bg);
    background-attachment:fixed;
    color:var(--text);
    min-height:100vh;
    overflow-x:hidden;
    -webkit-font-smoothing:antialiased;
}
/* room for nav.php: left rail on desktop, bottom bar on mobile.
   (If nav.php already offsets the page itself, delete these two rules.) */
@media (min-width:769px){ body{ padding-left:100px; } }
@media (max-width:768px){ body{ padding-bottom:72px; } }

/* ══ SHELL: main lane on the left, info column on the right ══ */
.cp-shell{ width:100%; display:flex; align-items:flex-start; justify-content:center; }
.cp-side{ display:none; }
@media (min-width:1100px){
    .cp-shell{ justify-content:flex-start; max-width:1240px; margin:0 auto; padding-left:22px; }
    .cp-side{
        display:block; flex:1 1 0; min-width:0; position:sticky; top:0; height:100vh;
        overflow-y:auto; overflow-x:hidden; padding:0 22px 30px 20px;
        scrollbar-width:thin; scrollbar-color:var(--scroll-thumb) transparent;
    }
    .cp-side::-webkit-scrollbar{ width:5px; }
    .cp-side::-webkit-scrollbar-thumb{ background:var(--scroll-thumb); border-radius:99px; }
}

/* ── centered lane + grey side lines ── */
.ac-lane{
    width:100%; max-width:var(--max-w);
    min-height:100vh; align-self:stretch;
    display:flex; flex-direction:column;
    padding-bottom:48px;
}
@media (min-width:801px){ .ac-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); } }
@media (max-width:800px){ .ac-lane{ max-width:100%; } }
@media (min-width:1100px){ .ac-lane{ flex:0 0 var(--max-w); width:var(--max-w); } }

/* ══ TOPBAR — title left, icons right, grey line under it ══ */
.ac-topbar{
    position:sticky; top:0; z-index:200;
    height:var(--topbar-h); flex-shrink:0;
    display:flex; align-items:center; gap:2px;
    padding:0 10px 0 var(--pad);
    background:var(--bg); background-attachment:fixed;
}
.ac-topbar::after{
    content:''; position:absolute; left:0; right:0; bottom:0;
    height:1px; background:rgba(var(--fg),0.14);
    pointer-events:none; z-index:2;
}
.ac-x{
    width:34px; height:34px; background:none; border:none; color:var(--ui-text,#fff);
    display:flex; align-items:center; justify-content:center;
    opacity:.9; transition:opacity .2s, color .2s; padding:0; flex-shrink:0;
}
.ac-x:hover{ opacity:1; color:var(--accent-text); }
.ac-x svg{ width:22px; height:22px; display:block; }
.ac-title{
    flex:1; min-width:0;
    font-size:.92rem; font-weight:700; color:var(--ui-text,#fff); letter-spacing:.2px;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis; text-align:left;
}
.ac-title.pad-l{ margin-left:4px; }

/* ══════════ MAIN TOPBAR — icons copied exactly from the Search page ══════════
   icons   1.5rem + 1.55rem  ->  1.35rem (490) -> 1.25rem (390) -> 1.15rem (330);  gap 16px -> 14px (490)
   phones  (768px and below) the whole bar is zoomed 1.1x, like the search page does
   The "Dezzme" word is the SAME as the Home page (Bebas Neue, wide letter-spacing) — only the colour differs:
   Home uses brand red, here it follows the theme (white in dark mode, black in light mode). */
.sticky-head{
    position:sticky; top:0; z-index:200;
    background:var(--bg); background-attachment:fixed;
}
.cm-topbar{ position:relative; display:flex; align-items:center; gap:12px; padding:14px 16px 10px; }
.cm-topbar::after{
    content:''; position:absolute; left:0; right:0; bottom:0;
    height:1px; background:rgba(var(--fg),0.14); pointer-events:none;
}
/* responsive: no grey line under the topbar */
@media (max-width:800px){ .ac-topbar::after, .cm-topbar::after{ display:none; } }

/* header word: same look as the "Dezzme" word on the Home page (Bebas Neue, wide letter-spacing), white in dark mode / black in light mode */
.tb-title,
html[data-theme="light"] .tb-title{
    font-family:'Bebas Neue',sans-serif !important;
    font-weight:400 !important;
    color:var(--ui-text,#fff) !important;
    font-size:clamp(24px,6.5vw,32px) !important;
    letter-spacing:clamp(5px,1.8vw,8px) !important;
    line-height:1 !important;
    padding-left:clamp(5px,1.8vw,8px) !important;
    text-transform:none;
    white-space:nowrap;
    margin-right:auto;
}
@media (max-width:768px){
    .tb-title,
    html[data-theme="light"] .tb-title{ font-size:clamp(24px,6.8vw,31px) !important; }
}

.tb-right{ display:flex; align-items:center; gap:16px; position:relative; }
.hft-btn, .rp-tb-btn{ -webkit-appearance:none; appearance:none; background:none; border:0; border-radius:0; box-shadow:none; }
.hft-btn{
    color:var(--ui-text,#fff); font-size:1.5rem; line-height:1; display:flex; align-items:center; justify-content:center;
    padding:2px; opacity:.9; transition:opacity .2s,color .2s; flex-shrink:0;
}
.rp-tb-btn{
    background:none; border:none; color:var(--ui-text,#fff); font-size:1.55rem; line-height:1;
    display:flex; align-items:center; justify-content:center;
    opacity:.9; transition:opacity .2s,color .2s; padding:2px; position:relative; flex-shrink:0;
}
.hft-btn:hover, .hft-btn:active, .rp-tb-btn:hover, .rp-tb-btn:active, .hft-btn.on, .rp-tb-btn.on{ opacity:1; color:var(--accent-text); }
.hft-btn i, .rp-tb-btn i{ display:block; font-size:inherit; line-height:1; }
/* search icon = the nav bar's search icon */
.rp-tb-btn svg{ width:1.25em; height:1.25em; display:block; }
.hft-btn svg{ width:1.3em; height:1.3em; display:block; }
.s-ring{ stroke-width:2.5; transition:stroke-width .15s ease; }
.s-handle{ stroke:currentColor; stroke-width:0; stroke-linejoin:round; transition:stroke-width .15s ease; }
.rp-tb-btn.on .s-ring, .rp-tb-btn:active .s-ring{ stroke-width:3.4; }
.rp-tb-btn.on .s-handle, .rp-tb-btn:active .s-handle{ stroke-width:1.2; }

@media (max-width:790px){
    .cm-topbar{ padding:14px 15px 10px; }
}
@media (max-width:490px){
    .cm-topbar{ padding:12px 12px 8px; }
    .hft-btn{ font-size:1.35rem; }
    .rp-tb-btn{ font-size:1.35rem; }
    .tb-right{ gap:14px; }
}
@media (max-width:390px){
    .cm-topbar{ padding-left:10px; padding-right:10px; }
    .hft-btn{ font-size:1.25rem; }
    .rp-tb-btn{ font-size:1.25rem; }
}
@media (max-width:330px){
    .cm-topbar{ padding-left:8px; padding-right:8px; }
    .hft-btn{ font-size:1.15rem; }
    .rp-tb-btn{ font-size:1.15rem; }
}
@media (max-width:768px){
    @supports (zoom:1){
        .cm-topbar{ zoom:1.1; }
    }
}

/* ══ TOOLBAR: tabs + category chips (one solid colour bar) ══ */
.toolbar{
    position:relative; margin-top:-4px;
    padding:10px var(--pad) 10px;
    background:var(--solid);
    border-bottom:1px solid var(--line);
    display:flex; flex-direction:column; gap:8px;
}
.tab-row{ display:flex; gap:6px; }
.chip{
    display:flex; align-items:center; justify-content:center; gap:5px; flex-shrink:0;
    height:var(--act-h); padding:0 12px; border-radius:var(--act-r);
    border:none; background:rgba(var(--fg),0.1);
    backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    color:rgba(var(--fg),.75); font-size:.7rem; font-weight:600;
    transition:background .2s, color .2s, transform .15s; white-space:nowrap;
}
.chip:hover{ background:rgba(var(--fg),0.2); color:var(--ui-text,#fff); }
.chip:active{ transform:scale(.96); }
.chip.active{ background:var(--accent); color:var(--on-accent); font-weight:700; }
.tab-row .chip{ flex:1; }

.cat-bar{ display:flex; align-items:center; gap:6px; }
.cat-arrow{
    width:var(--act-h); height:var(--act-h); flex-shrink:0; border:none; border-radius:var(--act-r);
    background:rgba(var(--fg),.1); color:var(--ui-text,#fff); font-size:1rem;
    display:flex; align-items:center; justify-content:center; transition:background .2s;
}
.cat-arrow:hover{ background:var(--accent); color:var(--on-accent); }
@media (max-width:768px){
    .cat-arrow{ display:none !important; }
    /* chips scroll all the way to the screen edges instead of being cut off at the padding line */
    .cat-bar{ margin:0 calc(var(--pad) * -1); }
    .cat-scrollbar{ padding:0 var(--pad); }
}
.cat-scroll-wrap{ flex:1; min-width:0; overflow:hidden; }
.cat-scrollbar{
    display:flex; gap:6px; overflow-x:auto; white-space:nowrap;
    scrollbar-width:none; -ms-overflow-style:none; -webkit-overflow-scrolling:touch;
}
.cat-scrollbar::-webkit-scrollbar{ display:none; }

/* ══ SEARCH BAR (scrolls away with the page) ══ */
.search-wrap{ display:grid; grid-template-rows:0fr; transition:grid-template-rows .28s ease; }
.search-wrap.open{ grid-template-rows:1fr; }
.search-inner{ min-height:0; overflow:hidden; }
.search-wrap:not(.open) .search-inner{ visibility:hidden; }
.search-pad{ padding:12px var(--pad) 4px; }
.search-input-wrap{
    display:flex; align-items:center; gap:8px;
    height:var(--ctl-h); background:var(--surface); border:1px solid var(--border);
    border-radius:var(--r); padding:0 12px; transition:border-color .2s, background .2s, box-shadow .2s;
}
.search-input-wrap:focus-within{ border-color:var(--accent-focus); background:rgba(var(--fg),.07); box-shadow:0 0 0 3px var(--accent-ring); }
.search-input-wrap i{ color:var(--muted); font-size:.95rem; flex-shrink:0; }
.search-input-wrap > svg{ width:1.05rem; height:1.05rem; color:var(--muted); flex-shrink:0; display:block; }
.search-input-wrap input{ background:transparent; border:none; outline:none; flex:1; min-width:0; color:var(--text); font-size:.76rem; }
.search-input-wrap input::placeholder{ color:var(--muted); }
.search-clear-x{ background:none; border:none; color:var(--muted); font-size:1.05rem; display:none; flex-shrink:0; }
.search-clear-x:hover{ color:var(--accent-text); }
.search-clear-x.show{ display:flex; }

/* ══ PODS LIST ══ */
.pods-wrap{ display:flex; flex-direction:column; margin-top:8px; border-top:1px solid var(--line); }
.pod-row{
    display:flex; align-items:center; gap:clamp(11px, 3vw, 14px);
    padding:clamp(12px, 3.4vw, 15px) var(--pad); border-bottom:1px solid var(--line);
    transition:background .18s; cursor:pointer;
    opacity:0; animation:rowIn .4s ease forwards;
}
@keyframes rowIn{ from{ opacity:0; transform:translateY(8px);} to{ opacity:1; transform:translateY(0);} }
.pod-row:hover{ background:rgba(var(--fg),.04); }
.pod-pic{
    width:clamp(62px, 18vw, 80px); height:clamp(62px, 18vw, 80px);
    border-radius:var(--r); object-fit:cover; flex-shrink:0; background:var(--ph,#15171f);
    border:2px solid var(--accent);
}
.pod-body{ flex:1; min-width:0; }
.pod-row .pod-pic.pod-pic-susp{ background:rgba(var(--fg),.1); }   /* keeps the same blue ring as every pod picture */   /* a slightly lighter shade of the page background */
.pod-susp{ display:block; font-size:clamp(.7rem, 2.2vw, .8rem); font-weight:700; color:var(--accent-text); }
.pod-name{
    font-size:clamp(.78rem, 2.5vw, .9rem); font-weight:700; color:var(--ui-text,#fff); line-height:1.35; margin-bottom:4px;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.pod-cat{
    display:inline-flex; align-items:center; font-size:.58rem; font-weight:700; padding:2px 8px; border-radius:var(--act-r);
    background:var(--accent-soft); border:1px solid var(--accent-border); color:var(--accent-text);
    max-width:100%; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.pod-avatars{ display:flex; align-items:center; margin-top:8px; }
.pod-avatars img{
    width:clamp(19px, 5.4vw, 24px); height:clamp(19px, 5.4vw, 24px); border-radius:50%; object-fit:cover;
    border:2px solid var(--accent); margin-left:-8px; background:var(--ph2,#0b0c26);
}
.pod-avatars img:first-child{ margin-left:0; }
.pod-member-count{ font-size:clamp(.58rem, 1.9vw, .66rem); color:var(--muted-2); margin-left:8px; }

/* ══ SKELETON ROWS (initial load, tab switch, infinite scroll) ══ */
@keyframes skel-shimmer{ 0%{ background-position:150% 0; } 100%{ background-position:-50% 0; } }
.sk-shimmer-bg{
    background:linear-gradient(100deg, rgba(var(--fg),.05) 30%, rgba(var(--fg),.13) 50%, rgba(var(--fg),.05) 70%);
    background-size:250% 100%; animation:skel-shimmer 1.5s ease-in-out infinite;
}
.skeleton-row{ display:flex; align-items:center; gap:clamp(11px, 3vw, 14px); padding:clamp(12px, 3.4vw, 15px) var(--pad); border-bottom:1px solid var(--line); }
.skeleton-pic{ width:clamp(62px, 18vw, 80px); height:clamp(62px, 18vw, 80px); border-radius:var(--r); flex-shrink:0; }
.skeleton-lines{ flex:1; display:flex; flex-direction:column; gap:9px; }
.skeleton-line{ height:11px; border-radius:6px; }
.skeleton-line.w-70{ width:70%; } .skeleton-line.w-40{ width:40%; height:9px; } .skeleton-line.w-55{ width:55%; height:9px; }

/* ══ EMPTY STATE (same design as Memepedia) ══ */
.empty-state{
    flex:1; min-height:48vh; display:none;
    padding:clamp(28px, 8vw, 48px) clamp(20px, 7vw, 36px) clamp(36px, 10vw, 60px);
    flex-direction:column; align-items:center; justify-content:center; text-align:center;
}
.es-title{ font-size:clamp(.95rem, 3.4vw, 1.1rem); font-weight:700; color:var(--ui-text,#fff); letter-spacing:-.2px; }
.es-text{ margin-top:6px; max-width:340px; font-size:clamp(.66rem, 2.1vw, .76rem); color:var(--muted); line-height:1.7; }
.es-tips{ width:100%; max-width:360px; margin-top:clamp(16px, 5vw, 24px); display:flex; flex-direction:column; gap:7px; }
.es-tip{ display:flex; align-items:center; gap:10px; padding:10px 12px; border-radius:var(--act-r); background:rgba(var(--fg),0.05); text-align:left; }
.es-tip i{ font-size:1rem; color:var(--accent-text); flex-shrink:0; }
.es-tip span{ font-size:clamp(.62rem, 2vw, .7rem); color:var(--sub); line-height:1.5; }
.es-btn{
    margin-top:clamp(16px, 5vw, 22px);
    display:flex; align-items:center; justify-content:center; gap:6px;
    height:var(--act-h); min-width:150px; padding:0 22px; border:none; border-radius:var(--act-r);
    background:var(--accent); color:var(--on-accent); font-size:.72rem; font-weight:700;
    transition:background .2s ease, transform .15s ease;
}
.es-btn:hover{ background:var(--accent-hover); }
.es-btn:active{ transform:scale(0.98); }

/* ═════════ RIGHT COLUMN CARDS ═════════ */
.gs-cards{ padding:18px 0 2px; }
.gs-card{ margin-bottom:12px; }
.gs-bar{
    display:flex; align-items:center; gap:7px; width:100%; height:30px; padding:0 12px; border:none; border-radius:var(--act-r);
    background:rgba(var(--fg),.1); backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    color:var(--ui-text,#fff); font-size:.72rem; font-weight:600; line-height:1; text-align:left; transition:background .2s ease, transform .15s ease;
}
.gs-bar:hover{ background:rgba(var(--fg),.2); }
.gs-bar:active{ transform:scale(.99); }
.gs-bar-ic{ font-size:14px; color:var(--accent-text); flex-shrink:0; }
.gs-bar-t{ flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.gs-bar-chev{ font-size:18px; color:rgba(var(--fg),.75); flex-shrink:0; transition:transform .25s ease; }
.gs-card.collapsed .gs-bar-chev{ transform:rotate(-90deg); }
.gs-body{ display:grid; grid-template-rows:1fr; transition:grid-template-rows .3s ease; }
.gs-inner{ min-height:0; overflow:hidden; }
.gs-pad{ padding-top:8px; }
.gs-card.collapsed .gs-body{ grid-template-rows:0fr; }
.gs-row{ display:flex; align-items:center; justify-content:space-between; gap:12px; padding:9px 2px; border-bottom:1px solid rgba(var(--fg),.06); }
.gs-row:last-child{ border-bottom:none; }
.gs-row-l{ font-size:.66rem; color:rgba(var(--fg),.5); font-weight:500; white-space:nowrap; }
.gs-row-v{ font-size:.74rem; font-weight:600; color:var(--ui-text,#fff); text-align:right; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.gs-p{ font-size:.72rem; line-height:1.65; color:rgba(var(--fg),.7); overflow-wrap:anywhere; padding:2px 2px 4px; }
.gs-p b{ color:var(--ui-text,#fff); font-weight:600; }
.gs-callout{
    display:flex; gap:9px; margin:8px 0 4px; padding:10px 11px; border-radius:var(--act-r);
    background:var(--accent-soft); border:1px solid var(--accent-border);
    font-size:.67rem; line-height:1.6; color:rgba(var(--fg),.8);
}
.gs-callout i{ color:var(--accent-text); font-size:1rem; flex-shrink:0; margin-top:1px; }
.gs-callout b{ color:var(--ui-text,#fff); }
.gs-step{ display:flex; gap:11px; padding:10px 2px; border-bottom:1px solid rgba(var(--fg),.06); }
.gs-step:last-child{ border-bottom:none; }
.gs-sn{ width:22px; height:22px; border-radius:50%; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:.62rem; font-weight:700; color:var(--on-accent); background:var(--accent); }
.gs-st{ min-width:0; }
.gs-st b{ display:block; font-size:.72rem; margin-bottom:2px; }
.gs-st span{ display:block; font-size:.65rem; line-height:1.55; color:rgba(var(--fg),.58); overflow-wrap:anywhere; }
.gs-topic{
    display:flex; align-items:center; gap:10px; width:100%; padding:9px 2px; border:none; border-bottom:1px solid rgba(var(--fg),.06);
    background:none; color:inherit; font:inherit; text-align:left;
}
.gs-topic:last-child{ border-bottom:none; }
.gs-topic:hover .gs-tn b{ text-decoration:underline; }
.gs-ti{ width:28px; height:28px; border-radius:var(--act-r); display:flex; align-items:center; justify-content:center; font-size:.95rem; flex-shrink:0; background:var(--accent-soft); color:var(--accent-text); }
.gs-tn{ display:flex; align-items:center; gap:7px; flex:1; min-width:0; }
.gs-tn b{ font-size:.72rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.gs-tn small{ font-size:.56rem; font-weight:600; color:var(--muted); padding:1px 7px; border-radius:4px; background:rgba(var(--fg),.07); flex-shrink:0; }
.gs-rule{ display:flex; gap:9px; padding:6px 2px; font-size:.68rem; line-height:1.55; color:rgba(var(--fg),.74); overflow-wrap:anywhere; }
.gs-rule i{ color:var(--accent-text); font-size:.95rem; flex-shrink:0; margin-top:1px; }
.gs-th{
    display:flex; align-items:center; gap:10px; width:100%; padding:9px 2px; border-bottom:1px solid rgba(var(--fg),.06);
    color:inherit; text-align:left;
}
.gs-th:last-child{ border-bottom:none; }
.gs-th:hover{ opacity:.78; }
.gs-th-img{ width:38px; height:38px; border-radius:var(--act-r); overflow:hidden; flex-shrink:0; background:var(--ph,#15171f); border:1.5px solid var(--accent); }
.gs-th-img img{ width:100%; height:100%; object-fit:cover; }
.gs-th-img.gs-th-susp{ background:rgba(var(--fg),.1); }
.gs-th small.pod-susp-s{ color:var(--accent-text); font-weight:700; }
.gs-th-tx{ flex:1; min-width:0; }
.gs-th b{ display:block; font-size:.72rem; font-weight:600; line-height:1.4; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.gs-th small{ display:block; font-size:.62rem; color:var(--muted); margin-top:1px; }
.gs-note{ font-size:.68rem; color:rgba(var(--fg),.5); line-height:1.55; padding:4px 2px; }

/* ══ CREATE POD MODAL (full screen, same lane) ══ */
.modal-overlay{
    position:fixed; inset:0; z-index:1000; display:none;
    justify-content:center; align-items:flex-start; background:var(--bg); background-attachment:fixed;
    overflow-y:auto; overflow-x:hidden; -webkit-overflow-scrolling:touch;
}
.modal-overlay.active{ display:flex; }
@media (min-width:801px) and (hover:hover) and (pointer:fine){
    .modal-overlay{ scrollbar-width:thin; scrollbar-color:var(--scroll-thumb) transparent; }
    .modal-overlay::-webkit-scrollbar{ display:block; width:5px; }
    .modal-overlay::-webkit-scrollbar-track{ background:transparent; }
    .modal-overlay::-webkit-scrollbar-thumb{ background:var(--scroll-thumb); border-radius:99px; }
}
.modal-lane{ width:100%; max-width:var(--max-w); min-height:100%; display:flex; flex-direction:column; }
@media (min-width:801px){ .modal-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); } }
@media (max-width:800px){ .modal-lane{ max-width:100%; } }
.modal-topbar{ padding-left:10px; z-index:5; }
.modal-topbar .ac-title{ margin-left:6px; }
.modal-body{ flex:1; padding:6px var(--pad) 36px; width:100%; }

.field-block{ margin-top:clamp(14px, 4vw, 20px); }
.field-block:first-child{ margin-top:6px; }
.field-label{ font-size:.6rem; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; color:var(--muted); display:flex; align-items:center; gap:6px; margin-bottom:9px; }
.field-label i{ color:var(--accent-text); font-size:.85rem; }
.field-label .optional-tag{ font-size:.6rem; color:var(--muted-2); text-transform:none; letter-spacing:0; font-weight:400; }

.preview-box{
    border-radius:var(--r); overflow:hidden; height:clamp(70px, 20vw, 100px);
    border:1px solid var(--line); background:var(--surface);
    display:flex; align-items:center; justify-content:center; margin-bottom:9px;
}
.preview-box img{ width:100%; height:100%; object-fit:cover; display:block; }
.preview-empty{ font-size:.68rem; color:var(--muted); }
.file-label{
    display:inline-flex; align-items:center; gap:6px; height:var(--act-h); padding:0 14px; border-radius:var(--act-r);
    background:rgba(var(--fg),.1); color:var(--ui-text,#fff); font-size:.7rem; font-weight:600;
    transition:background .2s; white-space:nowrap; cursor:pointer;
}
.file-label:hover{ background:rgba(var(--fg),.2); }
.file-label input{ display:none; }

.gc-input, .gc-textarea, .gc-select{
    width:100%; padding:0 12px; height:var(--ctl-h); border-radius:var(--r);
    border:1px solid var(--border); background:var(--surface);
    color:var(--ui-text,#fff); font-size:.76rem; outline:none; transition:border-color .2s, background .2s, box-shadow .2s;
    -webkit-appearance:none; appearance:none;
}
.gc-textarea{ height:auto; padding:10px 12px; resize:vertical; min-height:90px; line-height:1.6; }
.gc-input:focus, .gc-textarea:focus, .gc-select:focus{ border-color:var(--accent-focus); background:rgba(var(--fg),.07); box-shadow:0 0 0 3px var(--accent-ring); }
.gc-input::placeholder, .gc-textarea::placeholder{ color:var(--muted); }
.gc-select{
    cursor:pointer; padding-right:36px; color-scheme:dark;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='rgba(var(--fg),0.5)' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
    background-repeat:no-repeat; background-position:right 12px center;
}
.gc-select option{ background:var(--solid); color:var(--ui-text,#fff); }
.gc-btn{
    height:var(--ctl-h); padding:0 16px; border-radius:var(--r); border:none;
    background:var(--accent); color:var(--on-accent); font-size:.78rem; font-weight:700;
    transition:background .2s, transform .15s; width:100%;
    display:flex; align-items:center; justify-content:center; gap:7px;
    margin-top:clamp(18px, 5vw, 24px);
}
.gc-btn:hover{ background:var(--accent-hover); }
.gc-btn:active{ transform:scale(.98); }

/* modal skeleton (shown once per page load, the first time the modal opens) */
.modal-skeleton{ display:none; }
.modal-skeleton.show{ display:block; }
.modal-skel-block{ margin-bottom:22px; }
.modal-skel-line{ height:11px; border-radius:6px; margin-bottom:9px; }
.modal-skel-box{ height:90px; border-radius:var(--r); margin-bottom:10px; }
.modal-real-form.hide{ display:none; }

/* required / optional pills, hints */
.req{ font-size:.56rem; font-weight:700; color:var(--on-accent); background:var(--accent); border-radius:99px; padding:1px 7px; letter-spacing:.3px; text-transform:none; }
.opt{ font-size:.56rem; font-weight:600; color:var(--muted); border:1px solid var(--border); border-radius:99px; padding:1px 7px; letter-spacing:.3px; text-transform:none; }
.hint{ font-size:.66rem; color:var(--muted); margin-top:6px; line-height:1.5; }
.cnt{ font-size:.66rem; color:var(--muted); text-align:right; margin-top:5px; }

/* policy row inside the create form */
.rows{ border-top:1px solid var(--line); margin-top:clamp(16px, 4.5vw, 22px); }
.row{ display:flex; align-items:center; gap:12px; padding:12px 0; cursor:pointer; color:var(--ui-text,#fff); border-bottom:1px solid var(--line); }
.row > i:first-child{ font-size:20px; width:24px; text-align:center; flex-shrink:0; color:var(--accent-text); }
.row .lbl{ flex:1; min-width:0; font-size:.8rem; }
.row .lbl small{ display:block; font-size:.66rem; color:var(--sub); line-height:1.5; margin-top:1px; }
.row .val{ max-width:130px; font-size:.72rem; color:var(--sub); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.row .val.ok{ color:#4dde8a; font-weight:600; }
.row .chev{ font-size:19px; color:var(--muted); flex-shrink:0; }

/* policy sheet */
#modal-policy{ z-index:1100; }
.pol h4{ font-size:.8rem; font-weight:700; margin:18px 0 6px; color:var(--ui-text,#fff); }
.pol h4:first-child{ margin-top:0; }
.pol p, .pol li{ font-size:.72rem; line-height:1.7; color:rgba(var(--fg),.72); }
.pol ul{ padding-left:18px; margin-top:4px; }
.pol li + li{ margin-top:4px; }
.pol b{ color:var(--ui-text,#fff); font-weight:600; }
.pol-foot{
    position:sticky; bottom:0; z-index:6; background:var(--solid); border-top:1px solid var(--line);
    padding:10px var(--pad) max(12px, env(safe-area-inset-bottom));
}
.pol-hint{ font-size:.68rem; color:var(--sub); text-align:center; margin-bottom:6px; }
.pol-ack{ display:flex; gap:9px; align-items:flex-start; padding:6px 0; font-size:.68rem; line-height:1.55; color:var(--sub); cursor:pointer; }
.pol-ack input{ accent-color:var(--accent); width:16px; height:16px; flex-shrink:0; margin-top:1px; }
.pol-ack.dis{ opacity:.45; cursor:default; }
.pol-foot .gc-btn{ margin-top:8px; }
.pol-foot .gc-btn:disabled{ opacity:.45; cursor:not-allowed; }

/* toast */
.toast-container{ position:fixed; bottom:84px; left:50%; transform:translateX(-50%); z-index:3000; display:flex; flex-direction:column; gap:8px; align-items:center; pointer-events:none; }
.toast{
    display:flex; align-items:center; gap:9px; padding:9px 18px; border-radius:var(--r);
    background:rgba(8,8,22,.97); border:1px solid var(--accent-border);
    font-size:.74rem; font-weight:500; color:var(--text); box-shadow:0 8px 24px rgba(0,0,0,.6);
    animation:toastIn .3s ease forwards; white-space:nowrap; max-width:calc(100vw - 32px);
}
.toast i{ color:var(--danger); }
@keyframes toastIn{ from{ opacity:0; transform:translateY(12px);} to{ opacity:1; transform:translateY(0);} }


/* ═══════════════════════════════════════════════════════════════
   RESPONSIVE VIEW: +10% SIZE (800px and below only, fluid clamp values)
   Desktop / laptop view is not affected.
   (The "Dezzme" title size is set in the TOPBAR block above, same as the Home page.)
   ═══════════════════════════════════════════════════════════════ */
@media (max-width:800px){
    :root{
        --topbar-h:clamp(3rem, 10.8vw, 3.7rem);
        --pad:clamp(.625rem, 3.3vw, 1.1rem);
        --ctl-h:clamp(2.06rem, 8.8vw, 2.475rem);
        --act-h:clamp(1.65rem, 7.26vw, 1.925rem);
    }

    /* topbar (close button + modal titles only; the brand title and the icons have their own sizes above) */
    .ac-x{ width:clamp(2.06rem, 7vw, 2.34rem); height:clamp(2.06rem, 7vw, 2.34rem); }
    .ac-x svg{ width:1.5rem; height:1.5rem; }
    .ac-title{ font-size:clamp(.814rem, 2.2vw + .35rem, 1.012rem); }

    /* tabs + category chips */
    .chip{ font-size:clamp(.715rem, 2.2vw, .77rem); padding:0 clamp(.62rem, 2.2vw, .825rem); }

    /* search */
    .search-input-wrap i{ font-size:1.045rem; }
    .search-input-wrap > svg{ width:1.15rem; height:1.15rem; }
    .search-input-wrap input{ font-size:.836rem; }
    .search-clear-x{ font-size:1.155rem; }

    /* pod rows + skeletons */
    .pod-row, .skeleton-row{ gap:clamp(.75rem, 3.3vw, .96rem); padding:clamp(.825rem, 3.74vw, 1.03rem) var(--pad); }
    .pod-pic, .skeleton-pic{ width:clamp(4.25rem, 19.8vw, 5.5rem); height:clamp(4.25rem, 19.8vw, 5.5rem); }
    .pod-name{ font-size:clamp(.858rem, 2.75vw, .99rem); }
    .pod-cat{ font-size:.638rem; padding:.125rem .56rem; }
    .pod-avatars img{ width:clamp(1.31rem, 5.94vw, 1.65rem); height:clamp(1.31rem, 5.94vw, 1.65rem); }
    .pod-member-count{ font-size:clamp(.638rem, 2.09vw, .726rem); }
    .skeleton-line{ height:.75rem; }
    .skeleton-line.w-40, .skeleton-line.w-55{ height:.625rem; }

    /* empty state */
    .es-title{ font-size:clamp(1.045rem, 3.74vw, 1.21rem); }
    .es-text{ font-size:clamp(.726rem, 2.31vw, .836rem); max-width:23.4rem; }
    .es-tips{ max-width:24.75rem; }
    .es-tip i{ font-size:1.1rem; }
    .es-tip span{ font-size:clamp(.682rem, 2.2vw, .77rem); }
    .es-btn{ font-size:clamp(.77rem, 2.2vw, .792rem); min-width:10.3rem; }

    /* create pod + policy screens */
    .field-block{ margin-top:clamp(.96rem, 4.4vw, 1.375rem); }
    .field-label{ font-size:.66rem; }
    .field-label i{ font-size:.935rem; }
    .req, .opt{ font-size:.616rem; }
    .preview-box{ height:clamp(4.8rem, 22vw, 6.875rem); }
    .preview-empty{ font-size:.748rem; }
    .file-label{ font-size:clamp(.748rem, 2.2vw, .77rem); }
    .gc-input, .gc-textarea, .gc-select{ font-size:clamp(.77rem, 2.4vw, .836rem); padding-left:clamp(.69rem, 2.5vw, .825rem); padding-right:clamp(.69rem, 2.5vw, .825rem); }
    .gc-textarea{ padding-top:.625rem; padding-bottom:.625rem; }
    .gc-select{ padding-right:2.25rem; }
    .gc-btn{ font-size:clamp(.792rem, 2.4vw, .858rem); }
    .hint, .cnt{ font-size:.726rem; }
    .row > i:first-child{ font-size:1.375rem; width:1.65rem; }
    .row .lbl{ font-size:.88rem; }
    .row .lbl small{ font-size:.726rem; }
    .row .val{ font-size:.792rem; }
    .row .chev{ font-size:1.3rem; }
    .pol h4{ font-size:.88rem; }
    .pol p, .pol li{ font-size:.792rem; }
    .pol-hint, .pol-ack{ font-size:.748rem; }
    .pol-ack input{ width:1.1rem; height:1.1rem; }
    .toast{ font-size:.814rem; }
}

/* ═══════════ LIGHT MODE — <html data-theme="light"> (dark mode is never touched) ═══════════ */
:root[data-theme="light"]{
    color-scheme:light;
    --fg:6,7,26;
    --ui-text:#0b0d24;
    --text:#0b0d24;
    --solid:#ffffff;
    --surface:rgba(6,7,26,.05);
    --border:rgba(6,7,26,.2);
    --line:rgba(6,7,26,.1);
    --muted:rgba(6,7,26,.52);
    --muted-2:rgba(6,7,26,.38);
    --sub:rgba(6,7,26,.66);
    --bg:linear-gradient(-45deg,#ffffff,#f4f5fb,#f4f5fb);
    --accent-text:#1a56db;
    --accent-focus:rgba(42,95,216,.85);
    --danger:#d92b2b;
    --scroll-thumb:rgba(42,95,216,.4);
    --ph:#eceef5; --ph2:#ffffff;
}
[data-theme="light"] .toast{ box-shadow:0 8px 24px rgba(0,0,0,.18); }
</style>
<?php if (function_exists('dz_offline_styles')) { dz_offline_styles(); /* offline.php */ } ?>
</head>
<body>
<?php if (function_exists('dz_offline_ui')) { dz_offline_ui(); /* offline.php: banner + full-screen "No internet" state, must come before the page scripts */ } ?>

<?php include 'nav.php'; ?>

<div class="cp-shell">

<!-- ═════════════════ MAIN LANE ═════════════════ -->
<div class="ac-lane">

    <!-- STICKY HEAD: topbar + tabs/categories stay pinned together -->
    <div class="sticky-head">

    <!-- TOPBAR (title left, icons right — title styled like the Home page) -->
    <div class="cm-topbar">
        <div class="tb-title">Dezzme Pods</div>
        <div class="tb-right">
            <button type="button" class="hft-btn" onclick="openModal('modal-create-pod')" aria-label="Create pod" title="Create Pod"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M12 4.5v15M4.5 12h15"/></svg></button>
            <button type="button" class="rp-tb-btn" id="searchToggleBtn" onclick="toggleSearch()" aria-label="Search pods" title="Search"><?php echo $search_svg; ?></button>
        </div>
    </div>

    <!-- TOOLBAR: tabs + categories -->
    <div class="toolbar">
        <div class="tab-row">
            <button type="button" class="chip active" id="tabBtn-all" onclick="switchTab('all')">All Pods</button>
            <button type="button" class="chip" id="tabBtn-discover" onclick="switchTab('discover')">Discover</button>
            <button type="button" class="chip" id="tabBtn-created" onclick="switchTab('created')">My Pods</button>
        </div>
        <div class="cat-bar">
            <button type="button" class="cat-arrow" id="catLeft" aria-label="Scroll categories left"><i class="ri-arrow-left-s-line"></i></button>
            <div class="cat-scroll-wrap">
                <div class="cat-scrollbar" id="catScrollbar">
                    <button type="button" class="chip active" data-cat="All" onclick="selectCategory(this.dataset.cat)">All</button>
                    <?php foreach ($pod_category_options as $opt): ?>
                    <button type="button" class="chip" data-cat="<?php echo htmlspecialchars($opt); ?>" onclick="selectCategory(this.dataset.cat)"><?php echo htmlspecialchars($opt); ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <button type="button" class="cat-arrow" id="catRight" aria-label="Scroll categories right"><i class="ri-arrow-right-s-line"></i></button>
        </div>
    </div>

    </div><!-- /.sticky-head -->

    <!-- SEARCH BAR (hidden until the search icon is pressed) -->
    <div class="search-wrap" id="searchWrap">
      <div class="search-inner"><div class="search-pad">
        <div class="search-input-wrap">
            <?php echo $search_svg; ?>
            <input type="text" id="searchInput" placeholder="Search for a Community" autocomplete="off" spellcheck="false">
            <button type="button" class="search-clear-x" id="searchClearBtn" onclick="clearCommunitySearch()" aria-label="Clear search"><i class="ri-close-line"></i></button>
        </div>
      </div></div>
    </div>

    <!-- PODS LIST -->
    <div class="pods-wrap" id="podsWrap"></div>
    <div class="empty-state" id="emptyState"></div>
    <div id="scrollSentinel"></div>

</div><!-- /.ac-lane -->

<!-- ═════════════════ RIGHT COLUMN (normal / laptop view) ═════════════════ -->
<aside class="cp-side" aria-label="About Dezzme Pods">
  <div class="gs-cards">

    <div class="gs-card" data-k="about">
      <button type="button" class="gs-bar" onclick="gsToggle(this)" aria-expanded="true"><i class="ri-team-line gs-bar-ic"></i><span class="gs-bar-t">About Dezzme Pods</span><i class="ri-arrow-down-s-line gs-bar-chev"></i></button>
      <div class="gs-body"><div class="gs-inner"><div class="gs-pad">
        <p class="gs-p"><b>Pods</b> are Dezzme's communities. Each pod is a space around one topic, with its own posts, discussions, events and members.</p>
        <div class="gs-callout"><i class="ri-information-line"></i><span>Join a pod to post and discuss. <b>Create your own</b> and you become its owner.</span></div>
      </div></div></div>
    </div>

    <div class="gs-card" data-k="stats">
      <button type="button" class="gs-bar" onclick="gsToggle(this)" aria-expanded="true"><i class="ri-bar-chart-2-line gs-bar-ic"></i><span class="gs-bar-t">Pods at a glance</span><i class="ri-arrow-down-s-line gs-bar-chev"></i></button>
      <div class="gs-body"><div class="gs-inner"><div class="gs-pad">
        <div class="gs-row"><span class="gs-row-l">Pods on Dezzme</span><span class="gs-row-v"><?php echo number_format($st_pods); ?></span></div>
        <div class="gs-row"><span class="gs-row-l">Memberships</span><span class="gs-row-v"><?php echo number_format($st_members); ?></span></div>
        <div class="gs-row"><span class="gs-row-l">Pods you joined</span><span class="gs-row-v"><?php echo number_format($st_joined); ?></span></div>
        <div class="gs-row"><span class="gs-row-l">Pods you created</span><span class="gs-row-v"><?php echo number_format($st_mine); ?></span></div>
      </div></div></div>
    </div>

    <?php if (!empty($top_cats)): ?>
    <div class="gs-card" data-k="cats">
      <button type="button" class="gs-bar" onclick="gsToggle(this)" aria-expanded="true"><i class="ri-price-tag-3-line gs-bar-ic"></i><span class="gs-bar-t">Top categories</span><i class="ri-arrow-down-s-line gs-bar-chev"></i></button>
      <div class="gs-body"><div class="gs-inner"><div class="gs-pad">
        <?php foreach ($top_cats as $tc): ?>
        <button type="button" class="gs-topic" data-cat="<?php echo htmlspecialchars($tc['category']); ?>" onclick="pickCategory(this.dataset.cat)">
          <span class="gs-ti"><i class="ri-hashtag"></i></span>
          <span class="gs-tn"><b><?php echo htmlspecialchars($tc['category']); ?></b><small><?php echo (int)$tc['c']; ?> pod<?php echo (int)$tc['c'] === 1 ? '' : 's'; ?></small></span>
        </button>
        <?php endforeach; ?>
      </div></div></div>
    </div>
    <?php endif; ?>

    <?php if (!empty($popular_pods)): ?>
    <div class="gs-card" data-k="popular">
      <button type="button" class="gs-bar" onclick="gsToggle(this)" aria-expanded="true"><i class="ri-fire-line gs-bar-ic"></i><span class="gs-bar-t">Popular pods</span><i class="ri-arrow-down-s-line gs-bar-chev"></i></button>
      <div class="gs-body"><div class="gs-inner"><div class="gs-pad">
        <?php foreach ($popular_pods as $pp): ?>
        <a class="gs-th" href="group.php?id=<?php echo (int)$pp['id']; ?>">
          <?php if (!empty($pp['is_suspended'])): ?>
          <span class="gs-th-img gs-th-susp"></span>
          <span class="gs-th-tx"><b><?php echo htmlspecialchars($pp['title']); ?></b><small class="pod-susp-s">Suspended</small></span>
          <?php else: ?>
          <span class="gs-th-img"><img src="<?php echo htmlspecialchars(cm_img($pp['group_pic'], 'uploads/default_group.png')); ?>" alt="" loading="lazy" onerror="this.src='uploads/default_group.png'"></span>
          <span class="gs-th-tx"><b><?php echo htmlspecialchars($pp['title']); ?></b><small><?php echo number_format((int)$pp['mc']); ?> member<?php echo (int)$pp['mc'] === 1 ? '' : 's'; ?></small></span>
          <?php endif; ?>
        </a>
        <?php endforeach; ?>
      </div></div></div>
    </div>
    <?php endif; ?>

    <div class="gs-card" data-k="how">
      <button type="button" class="gs-bar" onclick="gsToggle(this)" aria-expanded="true"><i class="ri-compass-3-line gs-bar-ic"></i><span class="gs-bar-t">How pods work</span><i class="ri-arrow-down-s-line gs-bar-chev"></i></button>
      <div class="gs-body"><div class="gs-inner"><div class="gs-pad">
        <div class="gs-step"><span class="gs-sn">1</span><span class="gs-st"><b>Find your pod</b><span>Browse All Pods, filter by category, or search by name.</span></span></div>
        <div class="gs-step"><span class="gs-sn">2</span><span class="gs-st"><b>Join and take part</b><span>Post, vote on polls, join discussions and events.</span></span></div>
        <div class="gs-step"><span class="gs-sn">3</span><span class="gs-st"><b>Start your own</b><span>Tap + to create a pod with a banner, picture and guidelines.</span></span></div>
      </div></div></div>
    </div>

    <div class="gs-card" data-k="rules">
      <button type="button" class="gs-bar" onclick="gsToggle(this)" aria-expanded="true"><i class="ri-shield-check-line gs-bar-ic"></i><span class="gs-bar-t">Community guidelines</span><i class="ri-arrow-down-s-line gs-bar-chev"></i></button>
      <div class="gs-body"><div class="gs-inner"><div class="gs-pad">
        <div class="gs-rule"><i class="ri-check-line"></i><span>Keep posts on topic for the pod you're in.</span></div>
        <div class="gs-rule"><i class="ri-check-line"></i><span>Be respectful. No harassment or hateful content.</span></div>
        <div class="gs-rule"><i class="ri-check-line"></i><span>No spam or repeated posting.</span></div>
        <div class="gs-rule"><i class="ri-check-line"></i><span>Follow each pod's own extra guidelines.</span></div>
      </div></div></div>
    </div>

  </div>
</aside>

</div><!-- /.cp-shell -->

<!-- ══════════════════════════════════════════════
     CREATE POD — full screen modal
     ══════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-create-pod">
  <div class="modal-lane">
    <div class="ac-topbar modal-topbar">
        <button type="button" class="ac-x" onclick="closeModal('modal-create-pod')" aria-label="Close">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
        <div class="ac-title">Create Pod</div>
    </div>
    <div class="modal-body">

        <!-- skeleton — shown ONCE per page load/refresh the first time this modal opens -->
        <div class="modal-skeleton" id="podModalSkeleton">
            <div class="modal-skel-block"><div class="modal-skel-line sk-shimmer-bg" style="width:35%;"></div><div class="modal-skel-box sk-shimmer-bg"></div></div>
            <div class="modal-skel-block"><div class="modal-skel-line sk-shimmer-bg" style="width:30%;"></div><div class="modal-skel-box sk-shimmer-bg"></div></div>
            <div class="modal-skel-block"><div class="modal-skel-line sk-shimmer-bg" style="width:25%;"></div><div class="modal-skel-line sk-shimmer-bg" style="width:100%;height:34px;"></div></div>
            <div class="modal-skel-block"><div class="modal-skel-line sk-shimmer-bg" style="width:40%;"></div><div class="modal-skel-line sk-shimmer-bg" style="width:100%;height:70px;"></div></div>
            <div class="modal-skel-block"><div class="modal-skel-line sk-shimmer-bg" style="width:20%;"></div><div class="modal-skel-line sk-shimmer-bg" style="width:100%;height:34px;"></div></div>
        </div>

        <form method="POST" action="community.php" enctype="multipart/form-data" id="podModalRealForm" class="modal-real-form hide" onsubmit="return podSubmit(event)" novalidate>

            <div class="field-block">
                <div class="field-label"><i class="ri-landscape-line"></i> Banner Picture <span class="opt">Optional</span></div>
                <div class="preview-box" id="bannerPreviewBox"><span class="preview-empty">No banner selected</span></div>
                <label class="file-label"><i class="ri-upload-cloud-line"></i> <span>Choose Banner</span>
                    <input type="file" id="podBanner" name="pod_banner_pic" accept="image/*" onchange="previewImage(this,'bannerPreviewBox',8)">
                </label>
            </div>

            <div class="field-block">
                <div class="field-label"><i class="ri-image-2-line"></i> Group Picture <span class="req">Required</span></div>
                <div class="preview-box" id="picPreviewBox"><span class="preview-empty">No group picture selected</span></div>
                <label class="file-label"><i class="ri-upload-cloud-line"></i> <span>Choose Picture</span>
                    <input type="file" id="podPic" name="pod_group_pic" accept="image/*" onchange="previewImage(this,'picPreviewBox',5)">
                </label>
            </div>

            <div class="field-block">
                <div class="field-label"><i class="ri-text"></i> Pod Name <span class="req">Required</span></div>
                <input class="gc-input" type="text" id="podName" name="pod_name" maxlength="60" placeholder="Give your pod a name" autocomplete="off">
                <p class="hint">3 to 60 characters. Names are unique, and names that imitate Dezzme staff or teams aren't allowed.</p>
            </div>

            <div class="field-block">
                <div class="field-label"><i class="ri-align-left"></i> Pod Description <span class="req">Required</span></div>
                <textarea class="gc-textarea" id="podDesc" name="pod_description" maxlength="500" placeholder="What's this pod about? (at least 20 characters)" oninput="document.getElementById('podDescCnt').textContent=this.value.length+'/500'"></textarea>
                <div class="cnt" id="podDescCnt">0/500</div>
            </div>

            <div class="field-block">
                <div class="field-label"><i class="ri-file-edit-line"></i> Additional Guidelines <span class="opt">Optional</span></div>
                <textarea class="gc-textarea" name="pod_guidelines" maxlength="1000" placeholder="Any extra rules for members? They can't override the Pods Policy."></textarea>
            </div>

            <div class="field-block">
                <div class="field-label"><i class="ri-price-tag-3-line"></i> Pod Category <span class="req">Required</span></div>
                <select name="pod_category" id="podCat" class="gc-select">
                    <option value="" disabled selected>— choose a category —</option>
                    <?php foreach ($pod_category_options as $opt): ?>
                    <option value="<?php echo htmlspecialchars($opt); ?>"><?php echo htmlspecialchars($opt); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="hint">Pick the category that really fits. Pods in the wrong category can be moved or removed.</p>
            </div>

            <div class="rows"><div class="row" onclick="openPolicy()"><i class="ri-shield-check-line"></i><span class="lbl">Dezzme Pods Policy<small>Read it to the end, then agree to create your pod. You can own up to <?php echo CM_MAX_OWNED; ?> pods and create <?php echo CM_MAX_PER_DAY; ?> a day.</small></span><span class="val" id="podPolVal">Read &amp; agree</span><i class="ri-arrow-right-s-line chev"></i></div></div>

            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="agree" id="podAgree" value="0">
            <input type="hidden" name="ack" id="podAck" value="0">
            <button class="gc-btn" id="podSubmitBtn" type="submit" name="create_pod"><i class="ri-add-circle-line"></i> Create Pod</button>
        </form>
    </div>
  </div>
</div>


<!-- ══════════════════════════════════════════════
     PODS POLICY — must be read to the end and agreed to before a pod can be created
     ══════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-policy">
  <div class="modal-lane">
    <div class="ac-topbar modal-topbar">
        <button type="button" class="ac-x" onclick="closeModal('modal-policy')" aria-label="Close">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
        <div class="ac-title">Pods Policy</div>
    </div>
    <div class="modal-body pol">
        <p style="color:#fff;font-weight:600;font-size:.8rem;margin-bottom:6px">Dezzme Pods Policy</p>
        <p>A pod is a community that other people trust you to look after. When you create a pod you become its owner, and you agree to the rules below. They apply to the owner, admins and moderators of every pod, and they come before any rules you add yourself.</p>

        <h4>1. You are responsible for your pod</h4>
        <p>The owner is accountable for everything that happens in the pod, including what admins, moderators and members post. If you give someone a team role, you answer for how they use it. You can remove team members at any time.</p>

        <h4>2. Be honest about what your pod is</h4>
        <ul><li>Your name, picture, banner and category must describe the pod truthfully.</li><li>Do not impersonate Dezzme, its staff or teams, another person, a brand or an organisation. Names such as "Dezzme Official" or "Dezzme Support" are not allowed.</li><li>Parody and fan pods must say so clearly in the description.</li><li>Do not create a new pod to get around the removal or restriction of another pod.</li></ul>

        <h4>3. What is never allowed</h4>
        <ul><li><b>Child safety:</b> any sexual content involving minors, or any attempt to groom, solicit or contact minors for sexual or private purposes. This leads to immediate removal and may be reported to the authorities.</li><li>Content that is illegal, or that promotes or helps with illegal activity.</li><li>Hate, harassment, threats, bullying or targeting people for who they are.</li><li>Graphic violence, gore, or sexual content shared to shock or exploit.</li><li>Sharing private information about people without their permission, including doxxing.</li><li>Scams, phishing, fake giveaways, counterfeit goods, malware or anything built to cheat people.</li><li>Spam, mass invitations, and repeated copy-paste posts.</li></ul>

        <h4>4. You must moderate</h4>
        <p>Owners and admins are expected to keep the pod within these rules. That means reviewing reports sent to your pod and acting on them in good time, removing content that breaks the rules, and not encouraging members to break them. A pod that is left unmoderated while it fills with rule-breaking content can be restricted even if you did not post it.</p>

        <h4>5. No coordinated abuse</h4>
        <p>Do not use your pod to organise raids, brigading, mass reporting, ban evasion or pressure campaigns against other pods, pages or people. Do not push members to follow or join other communities through threats, bribes or deception.</p>

        <h4>6. Respect other people's work</h4>
        <p>Only share content you made or have the right to use. If you do not own it, you need permission. We may remove content after a valid complaint from its owner.</p>

        <h4>7. Money, promotion and members' data</h4>
        <ul><li>Paid promotions and paid partnerships must be clearly disclosed.</li><li>Do not sell or trade access, members, or members' information.</li><li>Do not collect personal details from members in a misleading way, or push them to share private information.</li></ul>

        <h4>8. Keep your pod active</h4>
        <p>Pods that are abandoned may be archived. If you can no longer run your pod, pass it to a trusted admin or ask us for help rather than leaving it unattended.</p>

        <h4>9. What happens if rules are broken</h4>
        <p>Depending on how serious or how repeated the problem is, Dezzme may remove content, limit the pod, remove admins, delete the pod, or restrict the owner's account and their ability to create pods. Serious breaches, especially anything in section 3 about children, can skip the warning steps. We may also act where the law requires it. Our decisions on enforcement are final, but you can ask us to review them.</p>

        <h4>10. Limits</h4>
        <p>You can own up to <?php echo CM_MAX_OWNED; ?> pods and create <?php echo CM_MAX_PER_DAY; ?> pod every 24 hours. Pod names must be unique. A pod needs a picture, a clear description and a category.</p>

        <h4>11. Changes to this policy</h4>
        <p>We may update this policy as Dezzme grows. If we make an important change we will let pod owners know. Continuing to run a pod means you accept the updated policy.</p>

        <p style="margin-top:20px;color:#fff;font-weight:600">That's everything. Thank you for helping keep Dezzme a good place.</p>
    </div>
    <div class="pol-foot">
        <div class="pol-hint" id="polHint">Scroll to the end to continue.</div>
        <label class="pol-ack dis" id="polAck1Wrap"><input type="checkbox" id="polAck1" disabled onchange="polUpdate()"><span>I am responsible for my pod, including the actions of my admins and moderators.</span></label>
        <label class="pol-ack dis" id="polAck2Wrap"><input type="checkbox" id="polAck2" disabled onchange="polUpdate()"><span>I understand Dezzme can remove content, restrict or delete my pod, and limit my account if these rules are broken.</span></label>
        <button type="button" class="gc-btn" id="polAgreeBtn" disabled onclick="agreePolicy()"><i class="ri-check-line"></i> I have read and agree</button>
    </div>
  </div>
</div>

<div class="toast-container" id="toastContainer" aria-live="polite"></div>

<script>
const RAND_SEED = <?php echo (int)$rand_seed; ?>;

let currentTab = 'all';
let currentCategory = 'All';
let currentSearch = '';
let currentOffset = 0;
let hasMore = true;
let isLoading = false;
let searchDebounce = null;
let loadToken = 0;                     // ignores stale responses after a tab/category/search change

const SKEL_INITIAL = 8;                // skeleton rows on first load / tab switch / refresh
const SKEL_MORE    = 4;                // skeleton rows while loading the next batch

let podModalSkeletonShown = false;     // resets on every page load/refresh

/* ── utils ── */
function escapeHTML(str){
    const d = document.createElement('div');
    d.innerText = String(str == null ? '' : str);
    return d.innerHTML.replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
function toast(msg){
    const c = document.getElementById('toastContainer');
    const t = document.createElement('div');
    t.className = 'toast';
    const i = document.createElement('i'); i.className = 'ri-error-warning-line';
    const s = document.createElement('span'); s.textContent = msg;
    t.appendChild(i); t.appendChild(s); c.appendChild(t);
    setTimeout(() => t.remove(), 3200);
}
const CM_MAX_OWNED = <?php echo (int)CM_MAX_OWNED; ?>;
const CM_MAX_PER_DAY = <?php echo (int)CM_MAX_PER_DAY; ?>;
const ERR_MSG = {
    name_required:   'Please give your pod a name',
    name_short:      'Pod name must be 3 to 60 characters',
    name_invalid:    'Pod name needs letters or numbers',
    name_taken:      'That pod name is already taken. Try another one.',
    name_reserved:   "Pod names can't imitate Dezzme staff or teams",
    desc_short:      'Write at least 20 characters in the description',
    cat_required:    'Pick a category for your pod',
    pic_required:    'A group picture is required',
    pic_bad:         'The group picture must be a JPG, PNG, GIF or WebP under 5 MB',
    banner_bad:      'The banner must be a JPG, PNG, GIF or WebP under 8 MB',
    policy_required: 'Please read and agree to the Pods Policy first',
    limit_owned:     'You can own up to ' + CM_MAX_OWNED + ' pods',
    limit_daily:     'You can create ' + CM_MAX_PER_DAY + ' pod per day. Try again tomorrow.',
    create_failed:   'Could not create the pod. Please try again.'
};
(function(){
    const err = new URLSearchParams(window.location.search).get('error');
    if (err) toast(ERR_MSG[err] || 'Something went wrong');
})();

/* ── modal ── */
function openModal(id){
    const m = document.getElementById(id);
    if(!m) return;
    m.classList.add('active');
    m.scrollTop = 0;
    document.body.style.overflow = 'hidden';

    if(id === 'modal-create-pod'){
        const skel = document.getElementById('podModalSkeleton');
        const form = document.getElementById('podModalRealForm');
        if(!podModalSkeletonShown){
            podModalSkeletonShown = true;
            skel.classList.add('show');
            form.classList.add('hide');
            setTimeout(function(){
                skel.classList.remove('show');
                form.classList.remove('hide');
            }, 550);
        } else {
            skel.classList.remove('show');
            form.classList.remove('hide');
        }
    }
}
function closeModal(id){
    const m = document.getElementById(id);
    if(!m) return;
    m.classList.remove('active');
    if (!document.querySelector('.modal-overlay.active')) document.body.style.overflow = '';
}
document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    const open = Array.from(document.querySelectorAll('.modal-overlay.active'));
    if (open.length) closeModal(open[open.length - 1].id);   // closes only the top-most one
});

function previewImage(input, boxId, maxMB){
    const box = document.getElementById(boxId);
    if (input.files && input.files[0]){
        const f = input.files[0];
        if (!/^image\//.test(f.type)) { toast('Please choose an image'); input.value = ''; return; }
        if (f.size > (maxMB || 5) * 1048576) { toast('Image is too large (max ' + (maxMB || 5) + ' MB)'); input.value = ''; return; }
        const reader = new FileReader();
        reader.onload = e => { box.innerHTML = '<img src="' + e.target.result + '" alt="Preview">'; };
        reader.readAsDataURL(f);
    }
}

/* ── Pods Policy: scroll to the end, tick both boxes, then agree ── */
let podAgreed = false;
function openPolicy(){
    openModal('modal-policy');
    setTimeout(polCheck, 150);
}
function polCheck(){
    const m = document.getElementById('modal-policy');
    const end = m.scrollTop + m.clientHeight >= m.scrollHeight - 30;
    ['polAck1','polAck2'].forEach(id => {
        const c = document.getElementById(id);
        c.disabled = !end;
        document.getElementById(id + 'Wrap').classList.toggle('dis', !end);
    });
    document.getElementById('polHint').style.display = end ? 'none' : 'block';
    polUpdate();
}
function polUpdate(){
    const a = document.getElementById('polAck1'), b = document.getElementById('polAck2');
    document.getElementById('polAgreeBtn').disabled = !(a.checked && b.checked && !a.disabled);
}
document.getElementById('modal-policy').addEventListener('scroll', polCheck, { passive:true });
function agreePolicy(){
    podAgreed = true;
    document.getElementById('podAgree').value = '1';
    document.getElementById('podAck').value = '1';
    const v = document.getElementById('podPolVal');
    v.textContent = 'Agreed ✓';
    v.classList.add('ok');
    closeModal('modal-policy');
}

/* ── create-pod form: validate before sending (the server checks everything again) ── */
function podSubmit(e){
    const name = document.getElementById('podName').value.replace(/\s+/g, ' ').trim();
    const desc = document.getElementById('podDesc').value.trim();
    const pic  = document.getElementById('podPic');
    const ban  = document.getElementById('podBanner');
    if (!pic.files || !pic.files[0]) { toast(ERR_MSG.pic_required); return false; }
    if (name.length < 3 || name.length > 60) { toast(ERR_MSG.name_short); document.getElementById('podName').focus(); return false; }
    if (!/[\p{L}\p{N}]/u.test(name)) { toast(ERR_MSG.name_invalid); return false; }
    if (/dezzme\s*(official|staff|team|support|admin|moderator)|official\s*dezzme/i.test(name)) { toast(ERR_MSG.name_reserved); return false; }
    if (desc.length < 20) { toast(ERR_MSG.desc_short); document.getElementById('podDesc').focus(); return false; }
    if (!document.getElementById('podCat').value) { toast(ERR_MSG.cat_required); return false; }
    if (!podAgreed) { toast(ERR_MSG.policy_required); return false; }
    document.getElementById('podSubmitBtn').disabled = true;
    return true;
}

/* ── tabs / categories / search ── */
function switchTab(tab){
    currentTab = tab;
    document.querySelectorAll('.tab-row .chip').forEach(b => b.classList.remove('active'));
    document.getElementById('tabBtn-' + tab).classList.add('active');
    resetAndLoad();
}
function selectCategory(cat){
    currentCategory = cat;
    document.querySelectorAll('#catScrollbar .chip').forEach(p => p.classList.toggle('active', p.dataset.cat === cat));
    const a = document.querySelector('#catScrollbar .chip.active');
    if (a) { try { a.scrollIntoView({ inline:'nearest', block:'nearest' }); } catch(e){} }
    resetAndLoad();
}
/* used by the right-hand "Top categories" card */
function pickCategory(cat){
    selectCategory(cat);
    window.scrollTo({ top:0, behavior:'smooth' });
}
function toggleSearch(){
    const wrap = document.getElementById('searchWrap');
    const btn = document.getElementById('searchToggleBtn');
    const input = document.getElementById('searchInput');
    const open = !wrap.classList.contains('open');
    wrap.classList.toggle('open', open);
    btn.classList.toggle('on', open);
    if (open) {
        window.scrollTo({ top:0, behavior:'smooth' });
        setTimeout(() => input.focus(), 280);
    } else if (input.value.trim() !== '' || currentSearch !== '') {
        input.value = '';
        document.getElementById('searchClearBtn').classList.remove('show');
        currentSearch = '';
        resetAndLoad();
    }
}

document.getElementById('searchInput').addEventListener('input', function(){
    clearTimeout(searchDebounce);
    const val = this.value;
    document.getElementById('searchClearBtn').classList.toggle('show', val.trim() !== '');
    searchDebounce = setTimeout(() => {
        currentSearch = val.trim();
        resetAndLoad();
    }, 350);
});
function clearCommunitySearch(){
    const input = document.getElementById('searchInput');
    input.value = '';
    document.getElementById('searchClearBtn').classList.remove('show');
    currentSearch = '';
    resetAndLoad();
    input.focus();
}

function resetAndLoad(){
    loadToken++;
    isLoading = false;
    currentOffset = 0;
    hasMore = true;
    document.getElementById('podsWrap').innerHTML = '';
    hideEmpty();
    loadPods();
}

/* ── skeleton rows ── */
function skeletonRowHTML(){
    return '<div class="skeleton-row skel-item"><div class="skeleton-pic sk-shimmer-bg"></div><div class="skeleton-lines"><div class="skeleton-line w-70 sk-shimmer-bg"></div><div class="skeleton-line w-40 sk-shimmer-bg"></div><div class="skeleton-line w-55 sk-shimmer-bg"></div></div></div>';
}
function showSkeletons(count){
    let html = '';
    for (let i = 0; i < count; i++) html += skeletonRowHTML();
    document.getElementById('podsWrap').insertAdjacentHTML('beforeend', html);
}
function clearSkeletons(){ document.querySelectorAll('.skel-item').forEach(el => el.remove()); }

function podRowHTML(pod){
    if (pod.suspended) {   /* suspended pod: plain light tile instead of the picture, "Suspended" in blue instead of category + members */
        return '<div class="pod-row" onclick="location.href=\'group.php?id=' + parseInt(pod.id, 10) + '\'">' +
            '<div class="pod-pic pod-pic-susp"></div>' +
            '<div class="pod-body">' +
                '<div class="pod-name">' + escapeHTML(pod.title) + '</div>' +
                '<span class="pod-susp">Suspended</span>' +
            '</div></div>';
    }
    let avatarsHTML = '';
    pod.avatars.forEach(a => { avatarsHTML += '<img src="' + escapeHTML(a) + '" alt="" onerror="this.src=\'mee.jpg\'">'; });
    const count = pod.member_count > 7
        ? '<span class="pod-member-count">+' + (pod.member_count - 7) + ' more</span>'
        : (pod.member_count > 0 ? '<span class="pod-member-count">' + pod.member_count + ' member' + (pod.member_count === 1 ? '' : 's') + '</span>' : '');
    return '<div class="pod-row" onclick="location.href=\'group.php?id=' + parseInt(pod.id, 10) + '\'">' +
        '<img class="pod-pic" src="' + escapeHTML(pod.group_pic) + '" alt="" onerror="this.src=\'uploads/default_group.png\'">' +
        '<div class="pod-body">' +
            '<div class="pod-name">' + escapeHTML(pod.title) + '</div>' +
            '<span class="pod-cat">' + escapeHTML(pod.category) + '</span>' +
            '<div class="pod-avatars">' + avatarsHTML + count + '</div>' +
        '</div></div>';
}

function tip(icon, text){ return '<div class="es-tip"><i class="' + icon + '"></i><span>' + text + '</span></div>'; }
function showEmpty(){
    const filtered = currentCategory !== 'All' || currentSearch !== '';
    let title, desc, tips, btn;
    if (currentTab === 'discover' && !filtered) {
        title = 'Nothing to discover yet';
        desc  = "Join a few pods and we'll recommend similar ones here based on their categories.";
        tips  = tip('ri-team-line', 'Join pods you like. Suggestions are based on their categories.') + tip('ri-compass-3-line', 'Browse All Pods to find your first one.');
        btn   = '<button type="button" class="es-btn" onclick="switchTab(\'all\')"><i class="ri-compass-3-line"></i> Browse All Pods</button>';
    } else if (currentTab === 'created' && !filtered) {
        title = "You haven't created any pods";
        desc  = 'Start your own community and invite people in.';
        tips  = tip('ri-image-2-line', 'Add a banner and a picture so your pod stands out.') + tip('ri-shield-check-line', 'Set your own guidelines so members know the rules.');
        btn   = '<button type="button" class="es-btn" onclick="openModal(\'modal-create-pod\')"><i class="ri-add-line"></i> Create a Pod</button>';
    } else if (filtered) {
        title = 'No pods found';
        desc  = 'Nothing matches your filters. Try a different category or search term.';
        tips  = tip('ri-price-tag-3-line', 'Pick another category, or choose All.') + tip('ri-search-line', 'Check the spelling of your search.');
        btn   = '<button type="button" class="es-btn" onclick="clearFilters()"><i class="ri-close-line"></i> Clear Filters</button>';
    } else {
        title = 'No pods yet';
        desc  = 'There are no pods here yet. Be the first to start one.';
        tips  = tip('ri-team-line', 'Pods are communities built around one topic.') + tip('ri-add-circle-line', 'Anyone can create a pod in a minute.');
        btn   = '<button type="button" class="es-btn" onclick="openModal(\'modal-create-pod\')"><i class="ri-add-line"></i> Create the First Pod</button>';
    }
    const e = document.getElementById('emptyState');
    e.innerHTML = '<div class="es-title">' + title + '</div><p class="es-text">' + desc + '</p><div class="es-tips">' + tips + '</div>' + btn;
    e.style.display = 'flex';
}
function hideEmpty(){ document.getElementById('emptyState').style.display = 'none'; }
function clearFilters(){
    currentSearch = '';
    const input = document.getElementById('searchInput');
    input.value = '';
    document.getElementById('searchClearBtn').classList.remove('show');
    selectCategory('All');
}

function loadPods(){
    if (isLoading || !hasMore) return;
    isLoading = true;
    const myToken = loadToken;

    showSkeletons(currentOffset === 0 ? SKEL_INITIAL : SKEL_MORE);

    const params = new URLSearchParams({
        ajax: 'load_pods',
        tab: currentTab,
        category: currentCategory,
        search: currentSearch,
        offset: currentOffset,
        seed: RAND_SEED
    });

    fetch('community.php?' + params.toString())
        .then(r => r.json())
        .then(data => {
            if (myToken !== loadToken) return;   // a newer request replaced this one
            clearSkeletons();
            const wrap = document.getElementById('podsWrap');
            if (data.pods.length === 0 && currentOffset === 0) {
                wrap.innerHTML = '';
                showEmpty();
            } else {
                data.pods.forEach(pod => { wrap.insertAdjacentHTML('beforeend', podRowHTML(pod)); });
            }
            hasMore = data.has_more;
            currentOffset += data.pods.length;
            isLoading = false;
        })
        .catch(() => {
            if (myToken !== loadToken) return;
            clearSkeletons();
            isLoading = false;
            toast('Could not load pods — please try again');
        });
}

const observer = new IntersectionObserver(entries => {
    entries.forEach(entry => { if (entry.isIntersecting) loadPods(); });
}, { rootMargin:'400px' });
observer.observe(document.getElementById('scrollSentinel'));

loadPods();

/* ── category chips: scroll arrows (desktop) ── */
(function(){
    const b = document.getElementById('catScrollbar'), l = document.getElementById('catLeft'), r = document.getElementById('catRight');
    if (l && r && b) {
        l.addEventListener('click', () => b.scrollBy({ left:-200, behavior:'smooth' }));
        r.addEventListener('click', () => b.scrollBy({ left: 200, behavior:'smooth' }));
    }
})();

/* ── right-column cards: collapse / expand ── */
window.gsToggle = function(btn){
    const card = btn && btn.closest ? btn.closest('.gs-card') : null; if (!card) return;
    const c = card.classList.toggle('collapsed');
    btn.setAttribute('aria-expanded', c ? 'false' : 'true');
    try { localStorage.setItem('cp_gs_' + (card.getAttribute('data-k') || ''), c ? '1' : '0'); } catch(e){}
};
(function(){
    document.querySelectorAll('.gs-card').forEach(card => {
        try {
            if (localStorage.getItem('cp_gs_' + (card.getAttribute('data-k') || '')) === '1') {
                card.classList.add('collapsed');
                const b = card.querySelector('.gs-bar'); if (b) b.setAttribute('aria-expanded', 'false');
            }
        } catch(e){}
    });
})();
</script>

</body>
</html>