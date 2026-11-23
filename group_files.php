<?php
session_start();
include 'config.php';

/* ══════════════════════════════════════════════════════════════════
   AUTH + CORE IDENTIFIERS
   ══════════════════════════════════════════════════════════════════ */
$current_user = (int)($_SESSION['user_id'] ?? 0);
if (!$current_user) { header("Location: login.php"); exit(); }

$group_id = (int)($_GET['id'] ?? 0);
if (!$group_id) { echo "Group not found"; exit(); }

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

/* ══════════════════════════════════════════════════════════════════
   AUTO-CREATE TABLES / MIGRATE COLUMNS
   ══════════════════════════════════════════════════════════════════ */
$conn->query("
    CREATE TABLE IF NOT EXISTS group_files (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        group_id INT UNSIGNED NOT NULL,
        uploaded_by INT UNSIGNED NOT NULL,
        stored_name VARCHAR(255) NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        file_ext VARCHAR(10) NOT NULL,
        file_size INT UNSIGNED NOT NULL,
        title VARCHAR(150) NOT NULL,
        description TEXT,
        is_pinned TINYINT(1) NOT NULL DEFAULT 0,
        download_count INT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_group (group_id),
        INDEX idx_pinned (is_pinned)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$conn->query("
    CREATE TABLE IF NOT EXISTS group_file_reports (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        file_id INT UNSIGNED NOT NULL,
        reported_by INT UNSIGNED NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_file_user (file_id, reported_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$conn->query("
    CREATE TABLE IF NOT EXISTS group_file_saves (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        file_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_file_user (file_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* migrate: drop old boolean column if present, add new enum column */
$old_col = $conn->query("SHOW COLUMNS FROM groups LIKE 'files_upload_admins_only'");
if ($old_col && $old_col->num_rows > 0) {
    $conn->query("ALTER TABLE groups DROP COLUMN files_upload_admins_only");
}
$new_col = $conn->query("SHOW COLUMNS FROM groups LIKE 'files_upload_permission'");
if ($new_col && $new_col->num_rows === 0) {
    $conn->query("ALTER TABLE groups ADD COLUMN files_upload_permission ENUM('everyone','admins','owner') NOT NULL DEFAULT 'admins'");
}

/* ── Fetch group ── */
$stmt = $conn->prepare("SELECT g.*, u.username AS creator_name FROM groups g JOIN users u ON g.created_by=u.id WHERE g.id=?");
$stmt->bind_param("i", $group_id);
$stmt->execute();
$group = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$group) { echo "Group not found"; exit(); }

$group_creator_id = (int)$group['created_by'];
$is_owner = ($group_creator_id === $current_user);

$is_admin = false;
$stmt = $conn->prepare("SELECT 1 FROM group_admins WHERE group_id=? AND user_id=?");
$stmt->bind_param("ii", $group_id, $current_user);
$stmt->execute();
if ($stmt->get_result()->num_rows > 0) $is_admin = true;
$stmt->close();

$is_member = $is_owner || $is_admin;
if (!$is_member) {
    $stmt = $conn->prepare("SELECT 1 FROM group_members WHERE group_id=? AND user_id=?");
    $stmt->bind_param("ii", $group_id, $current_user);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) $is_member = true;
    $stmt->close();
}
if (!$is_member) { header("Location: group.php?id=$group_id"); exit(); }

$upload_permission = $group['files_upload_permission'] ?? 'admins';
$can_upload = ($upload_permission === 'everyone')
    || ($upload_permission === 'admins' && ($is_owner || $is_admin))
    || ($upload_permission === 'owner' && $is_owner);

$img_src = empty($group['group_pic']) ? 'uploads/default_group.png' : (strpos($group['group_pic'],'uploads/')===0 ? $group['group_pic'] : 'uploads/'.$group['group_pic']);

/* current user's own avatar, for the "Add File..." composer row */
$cu_pic = 'uploads/me.jpeg';
$cu_stmt = $conn->prepare("SELECT profile_pic FROM users WHERE id=?");
$cu_stmt->bind_param("i", $current_user);
$cu_stmt->execute();
$cu_row = $cu_stmt->get_result()->fetch_assoc();
$cu_stmt->close();
if (!empty($cu_row['profile_pic'])) $cu_pic = $cu_row['profile_pic'];

function gf_confirm_js($str) { return htmlspecialchars(json_encode($str), ENT_QUOTES, 'UTF-8'); }

$ALLOWED_EXT = ['pdf','doc','docx','odt','xls','xlsx','ppt','pptx','zip','rar','7z','txt','csv','jpg','jpeg','png','gif','webp','mp3','mp4'];
$MAX_FILE_BYTES = 25 * 1024 * 1024; // 25MB

function gf_icon_for_ext($ext) {
    $ext = strtolower($ext);
    $map = [
        'pdf'  => ['ri-file-pdf-2-fill', '#f04747'],
        'doc'  => ['ri-file-word-2-fill', '#3b82f6'],
        'docx' => ['ri-file-word-2-fill', '#3b82f6'],
        'odt'  => ['ri-file-word-2-fill', '#3b82f6'],
        'xls'  => ['ri-file-excel-2-fill', '#22c55e'],
        'xlsx' => ['ri-file-excel-2-fill', '#22c55e'],
        'csv'  => ['ri-file-excel-2-fill', '#22c55e'],
        'ppt'  => ['ri-file-ppt-2-fill', '#f97316'],
        'pptx' => ['ri-file-ppt-2-fill', '#f97316'],
        'zip'  => ['ri-file-zip-fill', '#eab308'],
        'rar'  => ['ri-file-zip-fill', '#eab308'],
        '7z'   => ['ri-file-zip-fill', '#eab308'],
        'txt'  => ['ri-file-text-fill', '#94a3b8'],
        'jpg'  => ['ri-image-2-fill', '#ec4899'],
        'jpeg' => ['ri-image-2-fill', '#ec4899'],
        'png'  => ['ri-image-2-fill', '#ec4899'],
        'gif'  => ['ri-image-2-fill', '#ec4899'],
        'webp' => ['ri-image-2-fill', '#ec4899'],
        'mp3'  => ['ri-file-music-fill', '#a855f7'],
        'mp4'  => ['ri-file-video-fill', '#a855f7'],
    ];
    return $map[$ext] ?? ['ri-file-fill', '#7fa0ff'];
}

function gf_fmt_size($bytes) {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

function gf_fmt_dt($ts) {
    return date('d/m/y, H:i', strtotime($ts));
}

/* ══════════════════════════════════════════════════════════════════
   FILE DOWNLOAD (before any HTML output) — stays on this page for
   everything else; this route only serves the raw bytes.
   ══════════════════════════════════════════════════════════════════ */
if (isset($_GET['dl'])) {
    $dl_id = (int)$_GET['dl'];
    $stmt = $conn->prepare("SELECT stored_name, original_name FROM group_files WHERE id=? AND group_id=?");
    $stmt->bind_param("ii", $dl_id, $group_id);
    $stmt->execute();
    $f = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $path = $f ? 'uploads/files/' . $f['stored_name'] : null;
    if ($f && is_file($path)) {
        $conn->query("UPDATE group_files SET download_count = download_count + 1 WHERE id=" . $dl_id);
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($f['original_name']) . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit();
    } else {
        header("Location: group_files.php?id=$group_id");
        exit();
    }
}

/* ══════════════════════════════════════════════════════════════════
   POST HANDLING — every action redirects back to THIS page (id=$group_id),
   never to group.php.
   ══════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $valid_csrf = isset($_POST['csrf_token']) && hash_equals($csrf_token, $_POST['csrf_token']);

    /* ── Upload file ── */
    if ($valid_csrf && isset($_POST['upload_file']) && $can_upload) {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($title !== '' && !empty($_FILES['group_file']['name']) && $_FILES['group_file']['error'] === UPLOAD_ERR_OK) {
            $orig_name = $_FILES['group_file']['name'];
            $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
            $size = (int)$_FILES['group_file']['size'];

            if (in_array($ext, $ALLOWED_EXT, true) && $size > 0 && $size <= $MAX_FILE_BYTES) {
                if (!is_dir('uploads/files')) { @mkdir('uploads/files', 0755, true); }
                $stored = 'gf_' . uniqid() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (@move_uploaded_file($_FILES['group_file']['tmp_name'], 'uploads/files/' . $stored)) {
                    $stmt = $conn->prepare("INSERT INTO group_files (group_id, uploaded_by, stored_name, original_name, file_ext, file_size, title, description) VALUES (?,?,?,?,?,?,?,?)");
                    $stmt->bind_param("iisssiss", $group_id, $current_user, $stored, $orig_name, $ext, $size, $title, $description);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }
        header("Location: group_files.php?id=$group_id");
        exit();
    }

    /* ── Delete file (uploader OR admin OR owner) ── */
    if ($valid_csrf && isset($_POST['delete_file'])) {
        $file_id = (int)($_POST['file_id'] ?? 0);
        if ($file_id) {
            $stmt = $conn->prepare("SELECT uploaded_by, stored_name FROM group_files WHERE id=? AND group_id=?");
            $stmt->bind_param("ii", $file_id, $group_id);
            $stmt->execute();
            $f = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($f && ((int)$f['uploaded_by'] === $current_user || $is_owner || $is_admin)) {
                $del = $conn->prepare("DELETE FROM group_files WHERE id=? AND group_id=?");
                $del->bind_param("ii", $file_id, $group_id);
                $del->execute();
                $del->close();
                $conn->query("DELETE FROM group_file_reports WHERE file_id=" . $file_id);
                $conn->query("DELETE FROM group_file_saves WHERE file_id=" . $file_id);
                @unlink('uploads/files/' . $f['stored_name']);
            }
        }
        header("Location: group_files.php?id=$group_id");
        exit();
    }

    /* ── Edit file title/description (uploader OR admin OR owner) ── */
    if ($valid_csrf && isset($_POST['edit_file'])) {
        $file_id = (int)($_POST['file_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');

        $stmt = $conn->prepare("SELECT uploaded_by FROM group_files WHERE id=? AND group_id=?");
        $stmt->bind_param("ii", $file_id, $group_id);
        $stmt->execute();
        $f = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($f && $title !== '' && ((int)$f['uploaded_by'] === $current_user || $is_owner || $is_admin)) {
            $upd = $conn->prepare("UPDATE group_files SET title=?, description=? WHERE id=? AND group_id=?");
            $upd->bind_param("ssii", $title, $description, $file_id, $group_id);
            $upd->execute();
            $upd->close();
        }
        header("Location: group_files.php?id=$group_id");
        exit();
    }

    /* ── Toggle pin (owner/admin only) ── */
    if ($valid_csrf && isset($_POST['toggle_pin']) && ($is_owner || $is_admin)) {
        $file_id = (int)($_POST['file_id'] ?? 0);
        $conn->query("UPDATE group_files SET is_pinned = 1 - is_pinned WHERE id=" . $file_id . " AND group_id=" . $group_id);
        header("Location: group_files.php?id=$group_id");
        exit();
    }

    /* ── Toggle save/bookmark ── */
    if ($valid_csrf && isset($_POST['toggle_save'])) {
        $file_id = (int)($_POST['file_id'] ?? 0);
        $stmt = $conn->prepare("SELECT id FROM group_file_saves WHERE file_id=? AND user_id=?");
        $stmt->bind_param("ii", $file_id, $current_user);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($existing) {
            $conn->query("DELETE FROM group_file_saves WHERE id=" . (int)$existing['id']);
        } else {
            $ins = $conn->prepare("INSERT INTO group_file_saves (file_id, user_id) VALUES (?,?)");
            $ins->bind_param("ii", $file_id, $current_user);
            $ins->execute();
            $ins->close();
        }
        header("Location: group_files.php?id=$group_id");
        exit();
    }

    /* ── Report file ── */
    if ($valid_csrf && isset($_POST['report_file'])) {
        $file_id = (int)($_POST['file_id'] ?? 0);
        $ins = $conn->prepare("INSERT IGNORE INTO group_file_reports (file_id, reported_by) VALUES (?,?)");
        $ins->bind_param("ii", $file_id, $current_user);
        $ins->execute();
        $ins->close();
        header("Location: group_files.php?id=$group_id");
        exit();
    }

    /* ── Update upload permission (owner only) ── */
    if ($valid_csrf && isset($_POST['update_upload_permission']) && $is_owner) {
        $new_perm = $_POST['upload_permission'] ?? 'admins';
        if (!in_array($new_perm, ['everyone','admins','owner'], true)) $new_perm = 'admins';
        $upd = $conn->prepare("UPDATE groups SET files_upload_permission=? WHERE id=?");
        $upd->bind_param("si", $new_perm, $group_id);
        $upd->execute();
        $upd->close();
        header("Location: group_files.php?id=$group_id");
        exit();
    }
}

/* ══════════════════════════════════════════════════════════════════
   FETCH FILES (+ my saves) — with optional search
   ══════════════════════════════════════════════════════════════════ */
$search_q = trim($_GET['q'] ?? '');

$sql = "
    SELECT gf.*, u.username AS uploader_name, u.profile_pic AS uploader_pic
    FROM group_files gf
    JOIN users u ON u.id = gf.uploaded_by
    WHERE gf.group_id = ?
";
$params = [$group_id];
$types = "i";
if ($search_q !== '') {
    $sql .= " AND (gf.title LIKE ? OR gf.original_name LIKE ?)";
    $like = '%' . $search_q . '%';
    $params[] = $like; $params[] = $like;
    $types .= "ss";
}
$sql .= " ORDER BY gf.is_pinned DESC, gf.created_at DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$all_files = [];
while ($r = $res->fetch_assoc()) $all_files[] = $r;
$stmt->close();

$my_saves = [];
if (!empty($all_files)) {
    $ids = implode(',', array_map(fn($f) => (int)$f['id'], $all_files));
    $sres = $conn->query("SELECT file_id FROM group_file_saves WHERE user_id=$current_user AND file_id IN ($ids)");
    while ($r = $sres->fetch_assoc()) $my_saves[(int)$r['file_id']] = true;
}
$total_files_count = count($all_files);

$perm_labels = [
    'everyone' => 'Every member can upload',
    'admins'   => 'Only admins &amp; owner can upload',
    'owner'    => 'Only the owner can upload',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?php echo htmlspecialchars($group['title']); ?> | Files</title>
<script>try{if(localStorage.getItem('dz_theme')==='light')document.documentElement.setAttribute('data-theme','light');}catch(e){}</script>

<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">

<style>
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; font-family:'Poppins', sans-serif; }
button, a { cursor:pointer; outline:none; -webkit-tap-highlight-color:transparent; }

[class^="ri-"], [class*=" ri-"],
[class^="ri-"]::before, [class*=" ri-"]::before,
[class^="ri-"]::after,  [class*=" ri-"]::after {
    font-family:'remixicon' !important;
    font-style:normal; font-weight:normal; line-height:1;
    speak:none; text-transform:none;
    -webkit-font-smoothing:antialiased;
}

:root{
    --accent:        #1a56c4;
    --accent-soft:   rgba(26,86,196,.15);
    --accent-border: rgba(26,86,196,.40);
    --accent-strong: #13449d;
    --accent-bright: #2a68de;
    --accent-glow:   rgba(26,86,196,.30);
    --accent-text:   #1a56c4;      /* icons / hover text on the page background */
    --on-accent:     #000;         /* text on accent-coloured buttons */
    --fg:            255,255,255;  /* R,G,B of the tint used for text, lines and soft fills (dark theme = white) */

    --bg:       linear-gradient(-45deg,#010218,#070924,#070924);
    --surface:  rgba(var(--fg),0.05);
    --line:     rgba(var(--fg),0.09);
    --line2:    rgba(var(--fg),0.14);
    --text:     #ffffff;
    --ui-text:  #ffffff;
    --muted:    rgba(var(--fg),0.45);
    --sub:      rgba(var(--fg),0.62);

    --sheet:        rgba(12,12,28,0.98);   /* bottom-sheet / menu background */
    --scrim:        rgba(0,0,0,0.7);
    --toast-bg:     rgba(8,8,22,.97);
    --toast-shadow: rgba(0,0,0,.6);
    --ph:           #1a1a1a;               /* image placeholder */
    --danger-text:  #ff8a8a;
    --danger:       #ef4444;

    --max-w:    580px;
    --topbar-h: 54px;
    --pad:      clamp(9px, 3vw, 16px);
    --ctl-h:    clamp(30px, 8vw, 36px);
    --r:        5px;

    /* same size / corners as the buttons on the untracked page */
    --act-h:    clamp(25px, 7.2vw, 30px);
    --act-r:    4px;
}
@media (max-width:490px){ :root{ --topbar-h:48px; } }
@media (max-width:380px){ :root{ --topbar-h:44px; } }

html{ scroll-behavior:smooth; -webkit-text-size-adjust:100%; }

/* RESPONSIVE view: no scrollbars anywhere — scrolling still works. */
@media (max-width:768px){
    html, body, *{ scrollbar-width:none !important; -ms-overflow-style:none !important; }
    *::-webkit-scrollbar, html::-webkit-scrollbar, body::-webkit-scrollbar{ display:none !important; width:0 !important; height:0 !important; background:transparent !important; }
}

body{
    background:var(--bg);
    background-attachment:fixed;
    color:var(--text);
    min-height:100vh;
    display:flex; justify-content:center;
    overflow-x:hidden;
    -webkit-font-smoothing:antialiased;
}

/* ── centered lane + grey side lines ── */
.ac-lane{
    width:100%; max-width:var(--max-w);
    min-height:100vh;
    display:flex; flex-direction:column;
    padding-bottom:72px;
}
@media (min-width:801px){
    .ac-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); }
}
@media (max-width:800px){ .ac-lane{ max-width:100%; } }

/* ══════════════════════════════════════
   TOPBAR — X on the left, "Files" in the middle, search/settings on the right
══════════════════════════════════════ */
.ac-topbar{
    position:sticky; top:0; z-index:500;
    height:var(--topbar-h);
    display:flex; align-items:center; gap:2px;
    padding:0 10px;
    background:var(--bg);
    background-attachment:fixed;
    border-bottom:1px solid var(--line);
}
.ac-x{
    width:34px; height:34px;
    background:none; border:none; color:var(--ui-text);
    display:flex; align-items:center; justify-content:center;
    opacity:.9; transition:opacity .2s, color .2s;
    padding:0; flex-shrink:0; text-decoration:none;
}
.ac-x:hover{ opacity:1; color:var(--accent-text); }
.ac-x svg{ width:22px; height:22px; display:block; }

/* "Files" — exactly in the middle of the topbar */
.ac-title{
    position:absolute; left:50%; top:0; height:100%;
    transform:translateX(-50%);
    display:flex; align-items:center; justify-content:center;
    font-size:.95rem; font-weight:700; color:var(--ui-text); letter-spacing:-.1px;
    white-space:nowrap; pointer-events:none;
}
@media (max-width:490px){ .ac-title{ font-size:.88rem; } }

.ac-actions{ display:flex; align-items:center; gap:6px; margin-left:auto; flex-shrink:0; }
.ac-icon-btn{
    width:40px; height:40px; border-radius:12px; border:none;
    background:none; color:rgba(var(--fg),.65); font-size:1.25rem;
    display:flex; align-items:center; justify-content:center; transition:.2s;
}
.ac-icon-btn:hover{ background:var(--accent-soft); color:var(--ui-text); }
/* search icon = the exact icon from nav.php (ring + handle, gets thicker when pressed / search open) */
.ac-icon-btn svg{ width:1.2em; height:1.2em; display:block; }
.s-ring{ stroke-width:2.5; transition:stroke-width .15s ease; }
.s-handle{ stroke:currentColor; stroke-width:0; stroke-linejoin:round; transition:stroke-width .15s ease; }
.ac-icon-btn.on, .ac-icon-btn:active{ color:var(--ui-text); }
.ac-icon-btn.on .s-ring, .ac-icon-btn:active .s-ring{ stroke-width:3.4; }
.ac-icon-btn.on .s-handle, .ac-icon-btn:active .s-handle{ stroke-width:1.2; }
@media (max-width:490px){
    .ac-x{ width:30px; height:30px; }
    .ac-icon-btn{ width:36px; height:36px; }
}
@media (max-width:390px){ .ac-icon-btn{ width:32px; height:32px; } }

/* ── Add File composer row ── */
.composer-row{
    width:100%; display:flex; align-items:center; gap:clamp(10px, 3vw, 14px);
    padding:clamp(11px, 3.2vw, 16px) var(--pad);
    background:none; border:none; border-bottom:1px solid var(--line);
    transition:background .18s;
}
.composer-row:hover{ background:rgba(var(--fg),.04); }
.composer-avatar{
    width:clamp(32px, 9vw, 38px); height:clamp(32px, 9vw, 38px);
    border-radius:50%; object-fit:cover; flex-shrink:0; border:2px solid var(--accent); background:var(--ph);
}
.composer-text{ flex:1; text-align:left; font-size:clamp(.74rem, 2.4vw, .86rem); color:var(--muted); }

/* plain big blue + (no box) — same style as the + on the events page */
.composer-plus{
    width:36px; height:36px;
    background:none; border:none; padding:0;
    color:var(--accent-text);
    display:flex; align-items:center; justify-content:center;
    flex-shrink:0;
    transition:color .2s ease, transform .15s ease;
}
.composer-plus svg{ width:28px; height:28px; display:block; }
.composer-row:hover .composer-plus{ color:var(--accent-bright); }
.composer-row:active .composer-plus{ transform:scale(.92); }
@media (max-width:490px){
    .composer-plus{ width:32px; height:32px; }
    .composer-plus svg{ width:26px; height:26px; }
}

/* ── Search toggle ── */
.search-wrap{ width:100%; overflow:hidden; max-height:0; transition:max-height .25s ease; }
.search-wrap.open{ max-height:70px; border-bottom:1px solid var(--line); }
.search-box{
    position:relative; display:flex; align-items:center; gap:10px; margin:10px var(--pad);
    height:var(--ctl-h);
    background:var(--surface); border:1px solid var(--line2); border-radius:var(--r);
    padding:0 12px;
    transition:border-color .2s, background .2s, box-shadow .2s;
}
.search-box:focus-within{
    border-color:var(--accent); background:rgba(var(--fg),.07);
    box-shadow:0 0 0 3px var(--accent-soft);
}
.search-box i{ color:var(--muted); font-size:.95rem; flex-shrink:0; }
.search-box .sb-ico{ width:1.05rem; height:1.05rem; color:var(--muted); flex-shrink:0; display:block; }
.search-box input{ flex:1; min-width:0; background:none; border:none; outline:none; color:var(--ui-text); font-size:.76rem; }
.search-box input::placeholder{ color:var(--muted); }
.search-box button{ background:none; border:none; color:var(--muted); font-size:1rem; display:flex; flex-shrink:0; }
.search-box button:hover{ color:var(--ui-text); }

/* ── Flat file list row ── */
.file-row-wrap{ position:relative; width:100%; border-bottom:1px solid var(--line); }
.file-row{
    width:100%; display:flex; align-items:flex-start; gap:clamp(10px, 3vw, 13px);
    padding:clamp(8px, 2.4vw, 10px) var(--pad);
    text-align:left; transition:background .15s;
    text-decoration:none; color:inherit;
}
.file-row:hover{ background:rgba(var(--fg),.04); }
.file-icon{
    width:clamp(32px, 9vw, 42px); height:clamp(43px, 12vw, 56px); border-radius:6px; flex-shrink:0;
    display:flex; align-items:center; justify-content:center; font-size:clamp(1.55rem, 5vw, 2rem);
}
.file-thumb{ width:clamp(31px, 9vw, 38px); height:clamp(31px, 9vw, 38px); border-radius:6px; object-fit:cover; flex-shrink:0; background:var(--ph); }
.file-info{ flex:1; min-width:0; padding-right:clamp(30px, 8vw, 38px); }
.file-name-line{ display:flex; align-items:center; gap:6px; min-width:0; }
.file-name{ font-size:clamp(.76rem, 2.5vw, .87rem); font-weight:500; color:var(--ui-text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; min-width:0; }
.file-pin-ic{ color:var(--accent-text); font-size:.8rem; flex-shrink:0; }
.file-uploader{ font-size:clamp(.64rem, 2vw, .72rem); color:var(--sub); margin-top:3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.file-date{ font-size:clamp(.6rem, 1.9vw, .68rem); color:var(--muted); margin-top:2px; }
.file-more-btn{
    position:absolute; top:7px; right:clamp(8px, 2.4vw, 12px);
    width:clamp(32px, 9vw, 40px); height:clamp(26px, 7.5vw, 32px); border-radius:var(--r); flex-shrink:0;
    background:none; border:none; color:rgba(var(--fg),.55); font-size:1.1rem;
    display:flex; align-items:center; justify-content:center; transition:.2s;
}
.file-more-btn:hover{ background:var(--accent-soft); color:var(--ui-text); }

/* ══════════════════════════════════════
   MENU + MODALS — centered (desktop) / bottom sheet (responsive)
══════════════════════════════════════ */
.dropdown-overlay{
    position:fixed; inset:0; z-index:99999; display:none;
    align-items:flex-end; justify-content:center;
    background:var(--scrim);
    backdrop-filter:blur(4px); -webkit-backdrop-filter:blur(4px);
}
.dropdown-overlay.show{ display:flex; animation:acFade .18s ease both; }
@keyframes acFade{ from{ opacity:0; } to{ opacity:1; } }
@keyframes acSlideUp{ from{ transform:translateY(60px); opacity:0; } to{ transform:none; opacity:1; } }

.dropdown-modal{
    width:100%; max-width:480px;
    background:var(--sheet);
    border:1px solid var(--line); border-bottom:none;
    border-radius:10px 10px 0 0;
    padding:0 8px calc(8px + env(safe-area-inset-bottom, 0px));
    max-height:80vh; overflow-y:auto;
    animation:acSlideUp .28s cubic-bezier(.32,.72,0,1);
}
.dropdown-modal-handle{
    display:block; width:36px; height:4px; background:rgba(var(--fg),.18);
    border-radius:4px; margin:10px auto 4px;
}
.dropdown-modal-title{
    font-size:.66rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em;
    color:var(--muted); padding:6px 10px 8px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.dropdown-item{
    display:flex; align-items:center; gap:10px; width:100%; padding:10px 10px; border-radius:var(--r);
    background:none; border:none; color:rgba(var(--fg),.88); font-size:.8rem; font-weight:500; text-align:left;
    text-decoration:none; transition:background .15s;
}
.dropdown-item:hover{ background:rgba(var(--fg),.06); color:var(--ui-text); }
.dropdown-item.active{ color:var(--accent-text); }
.dropdown-item.danger{ color:var(--danger-text); }
.dropdown-item.danger:hover{ background:rgba(239,68,68,.12); }
.dropdown-item i{ font-size:1rem; color:var(--accent-text); flex-shrink:0; width:18px; text-align:center; }
.dropdown-item.danger i{ color:var(--danger); }
.dropdown-item.cancel{ justify-content:center; color:var(--muted); font-weight:600; }
.dropdown-sep{ height:1px; background:var(--line); margin:4px 6px; }

@media (min-width:600px){
    .dropdown-overlay{ align-items:center; padding:20px; }
    .dropdown-modal{ border-radius:8px; border-bottom:1px solid var(--line); max-width:340px; padding:8px; }
    .dropdown-modal-handle{ display:none; }
}
@media (max-width:490px){
    .dropdown-item{ padding:8px 9px; font-size:.74rem; gap:8px; }
    .dropdown-item i{ font-size:.9rem; width:16px; }
    .dropdown-modal-title{ font-size:.6rem; }
}
@media (max-width:380px){
    .dropdown-item{ font-size:.71rem; padding:7px 8px; }
}

/* ── Empty state (same look as the untracked page) ── */
.empty-state{
    flex:1; min-height:52vh;
    padding:clamp(28px, 8vw, 48px) clamp(20px, 7vw, 36px) clamp(36px, 10vw, 60px);
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    text-align:center;
}
.es-title{ font-size:clamp(.95rem, 3.4vw, 1.1rem); font-weight:700; color:var(--ui-text); letter-spacing:-.2px; }
.es-text{
    margin-top:6px; max-width:340px;
    font-size:clamp(.66rem, 2.1vw, .76rem); color:var(--muted); line-height:1.7;
}
.es-tips{
    width:100%; max-width:360px;
    margin-top:clamp(16px, 5vw, 24px);
    display:flex; flex-direction:column; gap:7px;
}
.es-tip{
    display:flex; align-items:center; gap:10px;
    padding:10px 12px; border-radius:var(--act-r);
    background:rgba(var(--fg),0.05);
    text-align:left;
}
.es-tip i{ font-size:1rem; color:var(--accent-text); flex-shrink:0; }
.es-tip span{ font-size:clamp(.62rem, 2vw, .7rem); color:var(--sub); line-height:1.5; }
.es-btn{
    margin-top:clamp(16px, 5vw, 22px);
    display:flex; align-items:center; justify-content:center;
    height:var(--act-h); min-width:150px; padding:0 22px;
    border-radius:var(--act-r); border:none;
    background:rgba(var(--fg),0.1); color:var(--ui-text);
    font-size:.72rem; font-weight:600; text-decoration:none;
    transition:background .2s ease, transform .15s ease;
}
.es-btn:hover{ background:rgba(var(--fg),0.2); }
.es-btn:active{ transform:scale(0.98); }
@media (max-width:490px){ .es-btn{ font-size:.62rem; } }

/* ── Full-screen forms (upload / edit / permissions) ── */
.modal-overlay{
    position:fixed; inset:0; z-index:1000; display:none;
    justify-content:center; background:var(--bg); background-attachment:fixed;
}
.modal-overlay.active{ display:flex; }
.modal-lane{
    width:100%; max-width:var(--max-w); height:100%;
    display:flex; flex-direction:column;
}
@media (min-width:801px){ .modal-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); } }
@media (max-width:800px){ .modal-lane{ max-width:100%; } }
.modal-topbar{
    height:var(--topbar-h); display:flex; align-items:center; gap:2px; padding:0 10px;
    border-bottom:1px solid var(--line); flex-shrink:0;
}
.modal-title{
    flex:1; min-width:0; font-size:.92rem; font-weight:700; text-align:right; margin:0 6px 0 10px;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis; color:var(--ui-text);
}
@media (max-width:490px){ .modal-title{ font-size:.84rem; } }
.modal-body{ flex:1; overflow-y:auto; padding:clamp(14px, 4vw, 20px) var(--pad) 40px; width:100%; }

.field-block{ margin-bottom:20px; }
.field-block:last-child{ margin-bottom:0; }
.field-label{
    font-size:.6rem; font-weight:700; text-transform:uppercase; letter-spacing:1.2px; color:var(--muted);
    display:flex; align-items:center; gap:6px; margin-bottom:9px;
}
.field-label i{ color:var(--accent-text); font-size:.85rem; }

.file-drop{
    border-radius:var(--r); padding:clamp(16px, 4.5vw, 22px) 14px;
    border:1.5px dashed var(--line2); background:var(--surface);
    display:flex; flex-direction:column; align-items:center; gap:8px; text-align:center; margin-bottom:10px;
    transition:.2s;
}
.file-drop.has-file{ border-color:var(--accent-border); background:var(--accent-soft); }
.file-drop i{ font-size:1.8rem; color:var(--accent-text); }
.file-drop-text{ font-size:clamp(.7rem, 2.3vw, .78rem); color:rgba(var(--fg),.75); font-weight:600; word-break:break-all; }
.file-drop-sub{ font-size:clamp(.6rem, 1.9vw, .66rem); color:var(--muted); }

.gc-input{
    width:100%; height:var(--ctl-h); min-height:38px; padding:0 12px; border-radius:var(--r);
    border:1px solid var(--line2); background:var(--surface);
    color:var(--ui-text); font-size:clamp(.74rem, 2.4vw, .82rem); outline:none; transition:.2s;
    -webkit-appearance:none; appearance:none;
}
.gc-input:focus{ border-color:var(--accent); background:rgba(var(--fg),.07); box-shadow:0 0 0 3px var(--accent-soft); }
.gc-input::placeholder{ color:var(--muted); }

.gc-textarea{
    width:100%; min-height:100px; padding:11px 12px; border-radius:var(--r);
    border:1px solid var(--line2); background:var(--surface);
    color:var(--ui-text); font-size:clamp(.72rem, 2.3vw, .8rem); outline:none; resize:vertical; transition:.2s;
}
.gc-textarea:focus{ border-color:var(--accent); background:rgba(var(--fg),.07); box-shadow:0 0 0 3px var(--accent-soft); }
.gc-textarea::placeholder{ color:var(--muted); }

.file-label{
    display:inline-flex; align-items:center; justify-content:center; gap:6px;
    height:var(--act-h); padding:0 14px; border-radius:var(--act-r);
    background:rgba(var(--fg),0.1); border:none; color:var(--ui-text);
    font-size:.72rem; font-weight:600;
    cursor:pointer; transition:background .2s ease, transform .15s ease; white-space:nowrap;
}
.file-label:hover{ background:rgba(var(--fg),0.2); }
.file-label:active{ transform:scale(0.98); }
@media (max-width:490px){ .file-label{ font-size:.62rem; } }
.file-label input{ display:none; }

.gc-btn{
    height:var(--act-h); padding:0 14px; border-radius:var(--act-r); border:none;
    background:var(--accent); color:var(--on-accent); font-size:.72rem; font-weight:700;
    cursor:pointer; transition:background .2s ease, transform .15s ease; width:100%;
    display:flex; align-items:center; justify-content:center; gap:6px;
    margin-top:6px; line-height:1;
}
.gc-btn i{ color:var(--on-accent); font-size:.85rem; }
.gc-btn:hover{ background:#2a68de; }
.gc-btn:active{ transform:scale(0.98); }
@media (max-width:490px){ .gc-btn{ font-size:.62rem; } }

/* ── Permission radio rows ── */
.perm-opt{
    display:flex; align-items:center; gap:11px; padding:11px 12px;
    border:1px solid var(--line2); border-radius:var(--r); margin-bottom:8px; transition:.2s;
    background:var(--surface);
}
.perm-opt.selected{ border-color:var(--accent-border); background:var(--accent-soft); }
.perm-radio{
    width:16px; height:16px; border-radius:50%; border:2px solid var(--line2); flex-shrink:0;
    display:flex; align-items:center; justify-content:center; transition:.2s;
}
.perm-opt.selected .perm-radio{ border-color:var(--accent); }
.perm-radio::after{ content:''; width:7px; height:7px; border-radius:50%; background:var(--accent); transform:scale(0); transition:.2s; }
.perm-opt.selected .perm-radio::after{ transform:scale(1); }
.perm-opt input{ display:none; }
.perm-label{ flex:1; }
.perm-label b{ display:block; font-size:clamp(.74rem, 2.4vw, .84rem); color:var(--ui-text); font-weight:600; }
.perm-label span{ display:block; font-size:clamp(.62rem, 2vw, .68rem); color:var(--muted); margin-top:2px; line-height:1.5; }

/* ── Toast ── */
.toast{
    position:fixed; bottom:88px; left:50%; transform:translateX(-50%) translateY(12px);
    background:var(--toast-bg); border:1px solid var(--accent-border); color:var(--ui-text);
    padding:9px 18px; border-radius:var(--r); font-size:.74rem; font-weight:500;
    display:flex; align-items:center; gap:8px; z-index:999999; opacity:0; pointer-events:none;
    transition:opacity .22s ease, transform .22s ease; box-shadow:0 8px 24px var(--toast-shadow);
    max-width:calc(100% - 40px); text-align:center;
}
.toast.show{ opacity:1; transform:translateX(-50%) translateY(0); }
.toast i{ color:var(--accent-text); flex-shrink:0; }

/* ══════════════════════════════════════
   SKELETON — same lane, same lines
══════════════════════════════════════ */
#page-skeleton{
    position:fixed; inset:0; z-index:9999;
    background:var(--bg); background-attachment:fixed;
    display:flex; justify-content:center;
    opacity:1; transition:opacity .35s ease; pointer-events:none;
}
#page-skeleton.hide{ opacity:0; }
.sk-lane{ width:100%; max-width:var(--max-w); display:flex; flex-direction:column; }
@media (min-width:801px){ .sk-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); } }
@media (max-width:800px){ .sk-lane{ max-width:100%; } }
.sk{ position:relative; overflow:hidden; background:rgba(var(--fg),.06); border-radius:6px; }
.sk::after{
    content:''; position:absolute; inset:0;
    background:linear-gradient(90deg, transparent, rgba(var(--fg),.09), transparent);
    transform:translateX(-100%); animation:skShimmer 1.5s infinite;
}
@keyframes skShimmer{ 100%{ transform:translateX(100%); } }
.sk-topbar{ position:relative; height:var(--topbar-h); display:flex; align-items:center; justify-content:space-between; padding:0 16px 0 14px; border-bottom:1px solid var(--line); }
.sk-back{ width:20px; height:20px; }
.sk-title{ position:absolute; left:50%; top:50%; transform:translate(-50%,-50%); width:50px; height:14px; }
.sk-row-item{ display:flex; align-items:center; gap:14px; padding:clamp(11px,3.2vw,16px) var(--pad); border-bottom:1px solid var(--line); }
.sk-avatar{ width:clamp(32px,9vw,38px); height:clamp(32px,9vw,38px); border-radius:50%; flex-shrink:0; }
.sk-icon{ width:clamp(32px,9vw,42px); height:clamp(43px,12vw,56px); border-radius:6px; flex-shrink:0; }
.sk-text{ display:flex; flex-direction:column; gap:6px; flex:1; min-width:0; }
.sk-lbl{ width:45%; height:12px; }
.sk-val{ width:30%; height:9px; }

/* ═══════════════════════════════════════════════════════════════
   RESPONSIVE ONLY — everything 10% bigger (desktop untouched)
   Kept near the end so it wins over the rules above.
═══════════════════════════════════════════════════════════════ */
@media (max-width:800px){
    :root{
        --topbar-h:59px;
        --pad:clamp(10px, 3.3vw, 17.6px);
        --ctl-h:clamp(33px, 8.8vw, 39.6px);
        --r:5.5px;
        --act-h:clamp(27.5px, 7.92vw, 33px);
        --act-r:4.4px;
    }

    /* topbar */
    .ac-topbar{ padding:0 11px; }
    .ac-x{ width:37px; height:37px; }
    .ac-x svg{ width:24px; height:24px; }
    .ac-title{ font-size:1.045rem; }
    .ac-actions{ gap:6.6px; }
    .ac-icon-btn{ width:44px; height:44px; border-radius:13.2px; font-size:1.375rem; }

    /* add file row */
    .composer-row{ gap:clamp(11px, 3.3vw, 15px); padding:clamp(12px, 3.52vw, 17.6px) var(--pad); }
    .composer-avatar{ width:clamp(35px, 9.9vw, 42px); height:clamp(35px, 9.9vw, 42px); border-width:2.2px; }
    .composer-text{ font-size:clamp(.81rem, 2.64vw, .95rem); }
    .composer-plus{ width:40px; height:40px; }
    .composer-plus svg{ width:31px; height:31px; }

    /* search */
    .search-wrap.open{ max-height:77px; }
    .search-box{ gap:11px; margin:11px var(--pad); padding:0 13px; }
    .search-box i{ font-size:1.045rem; }
    .search-box .sb-ico{ width:1.15rem; height:1.15rem; }
    .search-box input{ font-size:.84rem; }
    .search-box button{ font-size:1.1rem; }

    /* file rows */
    .file-row{ gap:clamp(11px, 3.3vw, 14px); padding:clamp(9px, 2.64vw, 11px) var(--pad); }
    .file-icon{ width:clamp(35px, 9.9vw, 46px); height:clamp(47px, 13.2vw, 62px); border-radius:6.6px; font-size:clamp(1.7rem, 5.5vw, 2.2rem); }
    .file-thumb{ width:clamp(34px, 9.9vw, 42px); height:clamp(34px, 9.9vw, 42px); border-radius:6.6px; }
    .file-info{ padding-right:clamp(33px, 8.8vw, 42px); }
    .file-name-line{ gap:6.6px; }
    .file-name{ font-size:clamp(.84rem, 2.75vw, .96rem); }
    .file-pin-ic{ font-size:.88rem; }
    .file-uploader{ font-size:clamp(.7rem, 2.2vw, .79rem); margin-top:3.3px; }
    .file-date{ font-size:clamp(.66rem, 2.09vw, .75rem); margin-top:2.2px; }
    .file-more-btn{ top:7.7px; right:clamp(9px, 2.64vw, 13px); width:clamp(35px, 9.9vw, 44px); height:clamp(29px, 8.25vw, 35px); font-size:1.21rem; }

    /* bottom-sheet menu */
    .dropdown-modal{ padding:0 8.8px calc(8.8px + env(safe-area-inset-bottom, 0px)); }
    .dropdown-modal-handle{ width:40px; height:4.4px; margin:11px auto 4.4px; }
    .dropdown-modal-title{ font-size:.73rem; padding:6.6px 11px 8.8px; }
    .dropdown-item{ gap:11px; padding:11px; font-size:.88rem; }
    .dropdown-item i{ font-size:1.1rem; width:20px; }
    .dropdown-sep{ margin:4.4px 6.6px; }

    /* empty state */
    .empty-state{ padding:clamp(31px, 8.8vw, 53px) clamp(22px, 7.7vw, 40px) clamp(40px, 11vw, 66px); }
    .es-title{ font-size:clamp(1.045rem, 3.74vw, 1.21rem); }
    .es-text{ margin-top:6.6px; max-width:374px; font-size:clamp(.73rem, 2.31vw, .84rem); }
    .es-tips{ max-width:396px; margin-top:clamp(18px, 5.5vw, 26px); gap:7.7px; }
    .es-tip{ gap:11px; padding:11px 13px; }
    .es-tip i{ font-size:1.1rem; }
    .es-tip span{ font-size:clamp(.68rem, 2.2vw, .77rem); }
    .es-btn{ margin-top:clamp(18px, 5.5vw, 24px); min-width:165px; padding:0 24px; font-size:.79rem; }

    /* full-screen forms */
    .modal-topbar{ padding:0 11px; }
    .modal-title{ font-size:.99rem; margin:0 7px 0 11px; }
    .modal-body{ padding:clamp(15px, 4.4vw, 22px) var(--pad) 44px; }
    .field-block{ margin-bottom:22px; }
    .field-label{ font-size:.66rem; letter-spacing:1.32px; gap:6.6px; margin-bottom:10px; }
    .field-label i{ font-size:.94rem; }
    .file-drop{ padding:clamp(18px, 4.95vw, 24px) 15.4px; gap:8.8px; margin-bottom:11px; }
    .file-drop i{ font-size:1.98rem; }
    .file-drop-text{ font-size:clamp(.77rem, 2.53vw, .86rem); }
    .file-drop-sub{ font-size:clamp(.66rem, 2.09vw, .73rem); }
    .gc-input{ min-height:42px; padding:0 13px; font-size:clamp(.81rem, 2.64vw, .9rem); }
    .gc-textarea{ min-height:110px; padding:12px 13px; font-size:clamp(.79rem, 2.53vw, .88rem); }
    .file-label{ gap:6.6px; padding:0 15.4px; font-size:.79rem; }
    .gc-btn{ padding:0 15.4px; font-size:.79rem; gap:6.6px; margin-top:6.6px; }
    .gc-btn i{ font-size:.94rem; }
    .perm-opt{ gap:12px; padding:12px 13px; margin-bottom:8.8px; }
    .perm-radio{ width:17.6px; height:17.6px; }
    .perm-radio::after{ width:7.7px; height:7.7px; }
    .perm-label b{ font-size:clamp(.81rem, 2.64vw, .92rem); }
    .perm-label span{ font-size:clamp(.68rem, 2.2vw, .75rem); margin-top:2.2px; }

    /* toast */
    .toast{ bottom:97px; padding:10px 20px; font-size:.81rem; gap:8.8px; }

    /* skeleton */
    .sk-topbar{ padding:0 17.6px 0 15px; }
    .sk-back{ width:22px; height:22px; }
    .sk-title{ width:55px; height:15.4px; }
    .sk-row-item{ gap:15px; padding:clamp(12px, 3.52vw, 17.6px) var(--pad); }
    .sk-avatar{ width:clamp(35px, 9.9vw, 42px); height:clamp(35px, 9.9vw, 42px); }
    .sk-icon{ width:clamp(35px, 9.9vw, 46px); height:clamp(47px, 13.2vw, 62px); border-radius:6.6px; }
    .sk-text{ gap:6.6px; }
    .sk-lbl{ height:13px; }
    .sk-val{ height:10px; }
}

/* centered-card range: keep the menu a touch wider to match */
@media (min-width:600px) and (max-width:800px){
    .dropdown-modal{ max-width:374px; padding:8.8px; }
}

@media (max-width:490px){
    :root{ --topbar-h:53px; }
    .ac-x{ width:33px; height:33px; }
    .ac-title{ font-size:.97rem; }
    .ac-icon-btn{ width:40px; height:40px; }
    .composer-plus{ width:35px; height:35px; }
    .composer-plus svg{ width:28.6px; height:28.6px; }
    .dropdown-item{ padding:8.8px 10px; font-size:.81rem; gap:8.8px; }
    .dropdown-item i{ font-size:.99rem; width:17.6px; }
    .dropdown-modal-title{ font-size:.66rem; }
    .es-btn{ font-size:.68rem; }
    .modal-title{ font-size:.92rem; }
    .file-label{ font-size:.68rem; }
    .gc-btn{ font-size:.68rem; }
}

@media (max-width:390px){
    .ac-icon-btn{ width:35px; height:35px; }
}

@media (max-width:380px){
    :root{ --topbar-h:48px; }
    .dropdown-item{ font-size:.78rem; padding:7.7px 8.8px; }
}

/* ═══════════ LIGHT MODE — <html data-theme="light"> (dark mode is never touched) ═══════════
   Same palette as the community page. The theme is read from localStorage 'dz_theme'. */
:root[data-theme="light"]{
    color-scheme:light;
    --fg:6,7,26;
    --ui-text:#0b0d24;
    --text:#0b0d24;
    --bg:linear-gradient(-45deg,#ffffff,#f4f5fb,#f4f5fb);
    --surface:rgba(6,7,26,.05);
    --line:rgba(6,7,26,.1);
    --line2:rgba(6,7,26,.2);
    --muted:rgba(6,7,26,.52);
    --sub:rgba(6,7,26,.66);
    --accent-text:#1a56db;
    --accent:#2a5fd8;
    --accent-soft:rgba(42,95,216,.14);
    --accent-border:rgba(42,95,216,.42);
    --sheet:#ffffff;
    --scrim:rgba(6,7,26,.45);
    --toast-bg:#ffffff;
    --toast-shadow:rgba(0,0,0,.18);
    --ph:#eceef5;
    --danger-text:#d92b2b;
    --danger:#d92b2b;
}
[data-theme="light"] .gc-btn:hover{ background:#234fb5; }
[data-theme="light"] .dropdown-item.danger:hover{ background:rgba(217,43,43,.1); }
</style>
</head>
<body>

<!-- ══ SKELETON ══ -->
<div id="page-skeleton">
    <div class="sk-lane">
        <div class="sk-topbar">
            <div class="sk sk-back"></div>
            <div class="sk sk-title"></div>
        </div>
        <div class="sk-row-item">
            <div class="sk sk-avatar"></div>
            <div class="sk-text"><div class="sk sk-lbl" style="width:35%;"></div></div>
        </div>
        <?php for($i=0;$i<5;$i++): ?>
        <div class="sk-row-item">
            <div class="sk sk-icon"></div>
            <div class="sk-text"><div class="sk sk-lbl"></div><div class="sk sk-val"></div></div>
        </div>
        <?php endfor; ?>
    </div>
</div>

<div class="ac-lane">

    <!-- ══ TOPBAR ══ -->
    <div class="ac-topbar">
        <a class="ac-x" href="group.php?id=<?php echo $group_id; ?>" aria-label="Close">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </a>
        <span class="ac-title">Files</span>
        <div class="ac-actions">
            <button type="button" class="ac-icon-btn" id="searchToggleBtn" onclick="toggleSearch()" aria-label="Search files"><svg viewBox="0 0 24 24" aria-hidden="true"><circle class="s-ring" cx="10" cy="10" r="6.5" fill="none" stroke="currentColor" stroke-width="2.5"/><path class="s-handle" fill="currentColor" d="M15.37 14.23L21.41 18.59A2 2 0 0 1 18.59 21.41L14.23 15.37Z"/></svg></button>
            <?php if ($is_owner): ?>
            <button type="button" class="ac-icon-btn" onclick="openModal('modal-permissions')" aria-label="Upload settings"><i class="ri-settings-3-line"></i></button>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══ SEARCH (toggleable) ══ -->
    <div class="search-wrap" id="searchWrap">
        <form method="GET" action="group_files.php" id="searchForm">
            <input type="hidden" name="id" value="<?php echo $group_id; ?>">
            <div class="search-box">
                <svg class="sb-ico" viewBox="0 0 24 24" aria-hidden="true"><circle class="s-ring" cx="10" cy="10" r="6.5" fill="none" stroke="currentColor" stroke-width="2.5"/><path class="s-handle" fill="currentColor" d="M15.37 14.23L21.41 18.59A2 2 0 0 1 18.59 21.41L14.23 15.37Z"/></svg>
                <input type="text" name="q" id="searchInput" placeholder="Search files" value="<?php echo htmlspecialchars($search_q); ?>" autocomplete="off">
                <?php if ($search_q !== ''): ?>
                <button type="button" onclick="clearSearch()" aria-label="Clear"><i class="ri-close-line"></i></button>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- ══ ADD FILE COMPOSER ROW ══ -->
    <?php if ($can_upload): ?>
    <button type="button" class="composer-row" onclick="openModal('modal-upload-file')">
        <img class="composer-avatar" src="<?php echo htmlspecialchars($cu_pic); ?>" onerror="this.src='uploads/me.jpeg'" alt="">
        <span class="composer-text">Add File...</span>
        <span class="composer-plus">
            <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 4v16M4 12h16"/></svg>
        </span>
    </button>
    <?php endif; ?>

<?php
/* ═══ Reusable flat file row renderer ═══ */
function render_file_row($f, $current_user, $is_owner, $is_admin, $csrf_token, $my_saves, $group_id) {
    [$icon, $color] = gf_icon_for_ext($f['file_ext']);
    $is_image = in_array(strtolower($f['file_ext']), ['jpg','jpeg','png','gif','webp'], true);
    $can_manage = $is_owner || $is_admin;
    $can_edit_delete = $can_manage || ((int)$f['uploaded_by'] === $current_user);
    $is_saved = isset($my_saves[(int)$f['id']]);
    $is_own = ((int)$f['uploaded_by'] === $current_user);
    $del_msg = 'Delete "' . $f['title'] . '" permanently? This cannot be undone.';
    $menu_id = 'menu-' . (int)$f['id'];
    $dl_url = 'group_files.php?id=' . $group_id . '&dl=' . (int)$f['id'];
    ?>
    <div class="file-row-wrap">
        <a class="file-row" href="<?php echo $dl_url; ?>">
            <?php if ($is_image): ?>
                <img class="file-thumb" src="uploads/files/<?php echo htmlspecialchars($f['stored_name']); ?>" onerror="this.outerHTML='<div class=&quot;file-icon&quot; style=&quot;color:<?php echo $color; ?>;&quot;><i class=&quot;<?php echo $icon; ?>&quot;></i></div>'">
            <?php else: ?>
                <div class="file-icon" style="color:<?php echo $color; ?>;"><i class="<?php echo $icon; ?>"></i></div>
            <?php endif; ?>
            <div class="file-info">
                <div class="file-name-line">
                    <?php if ($f['is_pinned']): ?><i class="ri-pushpin-2-fill file-pin-ic" title="Pinned"></i><?php endif; ?>
                    <span class="file-name"><?php echo htmlspecialchars($f['title']); ?></span>
                </div>
                <div class="file-uploader"><?php echo htmlspecialchars($f['uploader_name']); ?></div>
                <div class="file-date"><?php echo gf_fmt_dt($f['created_at']); ?> · <?php echo gf_fmt_size($f['file_size']); ?></div>
            </div>
        </a>
        <button type="button" class="file-more-btn" onclick="toggleDropdown('<?php echo $menu_id; ?>', event)" aria-label="More options"><i class="ri-menu-line"></i></button>

        <div class="dropdown-overlay" id="<?php echo $menu_id; ?>">
        <div class="dropdown-modal">
            <div class="dropdown-modal-handle"></div>
            <div class="dropdown-modal-title"><?php echo htmlspecialchars($f['title']); ?></div>

            <a class="dropdown-item" href="<?php echo $dl_url; ?>"><i class="ri-download-2-line"></i> Download</a>

            <form method="POST" action="group_files.php?id=<?php echo $group_id; ?>">
                <input type="hidden" name="file_id" value="<?php echo (int)$f['id']; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <button type="submit" name="toggle_save" class="dropdown-item<?php echo $is_saved ? ' active' : ''; ?>">
                    <i class="ri-bookmark-<?php echo $is_saved ? 'fill' : 'line'; ?>"></i> <?php echo $is_saved ? 'Unsave' : 'Save'; ?>
                </button>
            </form>

            <button type="button" class="dropdown-item" onclick="shareFile(<?php echo (int)$f['id']; ?>)"><i class="ri-share-forward-line"></i> Share</button>

            <?php if ($can_manage): ?>
            <div class="dropdown-sep"></div>
            <form method="POST" action="group_files.php?id=<?php echo $group_id; ?>">
                <input type="hidden" name="file_id" value="<?php echo (int)$f['id']; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <button type="submit" name="toggle_pin" class="dropdown-item">
                    <i class="ri-pushpin-2-line"></i> <?php echo $f['is_pinned'] ? 'Unpin file' : 'Pin file'; ?>
                </button>
            </form>
            <?php endif; ?>

            <?php if ($can_edit_delete): ?>
            <button type="button" class="dropdown-item" onclick="openEditModal(<?php echo (int)$f['id']; ?>, <?php echo htmlspecialchars(json_encode($f['title']), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode($f['description'] ?? ''), ENT_QUOTES); ?>)">
                <i class="ri-edit-line"></i> Edit info
            </button>
            <?php endif; ?>

            <?php if (!$is_own && !$can_manage): ?>
            <div class="dropdown-sep"></div>
            <form method="POST" action="group_files.php?id=<?php echo $group_id; ?>">
                <input type="hidden" name="file_id" value="<?php echo (int)$f['id']; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <button type="submit" name="report_file" class="dropdown-item danger"><i class="ri-flag-line"></i> Report file</button>
            </form>
            <?php endif; ?>

            <?php if ($can_edit_delete): ?>
            <div class="dropdown-sep"></div>
            <form method="POST" action="group_files.php?id=<?php echo $group_id; ?>" onsubmit="return confirm(<?php echo gf_confirm_js($del_msg); ?>)">
                <input type="hidden" name="file_id" value="<?php echo (int)$f['id']; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <button type="submit" name="delete_file" class="dropdown-item danger"><i class="ri-delete-bin-line"></i> Delete file</button>
            </form>
            <?php endif; ?>

            <div class="dropdown-sep"></div>
            <button type="button" class="dropdown-item cancel" onclick="toggleDropdown('<?php echo $menu_id; ?>')">Cancel</button>
        </div>
        </div>
    </div>
    <?php
}
?>

    <!-- ══ FLAT FILE LIST ══ -->
    <?php if (!empty($all_files)): ?>
        <?php foreach ($all_files as $f) render_file_row($f, $current_user, $is_owner, $is_admin, $csrf_token, $my_saves, $group_id); ?>
    <?php else: ?>
    <div class="empty-state">
        <?php if ($search_q !== ''): ?>
            <div class="es-title">No files match your search</div>
            <p class="es-text">Try a different keyword or clear the search to see everything shared in this pod.</p>
            <a class="es-btn" href="group_files.php?id=<?php echo $group_id; ?>">Clear search</a>
        <?php else: ?>
            <div class="es-title">No files yet</div>
            <p class="es-text">
                <?php if ($can_upload): ?>
                    Share the first file with this pod. Documents, images, audio and video all live here.
                <?php else: ?>
                    <?php echo $perm_labels[$upload_permission]; ?>. Check back later for shared files.
                <?php endif; ?>
            </p>
            <div class="es-tips">
                <div class="es-tip"><i class="ri-file-upload-line"></i><span>PDF, Word, Excel, PowerPoint, ZIP, image, audio and video files up to 25MB.</span></div>
                <div class="es-tip"><i class="ri-pushpin-2-line"></i><span>Pinned files always stay at the top of the list.</span></div>
            </div>
            <?php if ($can_upload): ?>
                <button type="button" class="es-btn" onclick="openModal('modal-upload-file')">Add File</button>
            <?php else: ?>
                <a class="es-btn" href="group.php?id=<?php echo $group_id; ?>">Back to pod</a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div><!-- /.ac-lane -->

<!-- ══ UPLOAD FILE MODAL ══ -->
<div class="modal-overlay" id="modal-upload-file">
  <div class="modal-lane">
    <div class="modal-topbar">
        <button type="button" class="ac-x" onclick="closeModal('modal-upload-file')" aria-label="Close">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
        <span class="modal-title">Add File</span>
    </div>
    <div class="modal-body">
        <form method="POST" action="group_files.php?id=<?php echo $group_id; ?>" enctype="multipart/form-data">
            <div class="field-block">
                <div class="field-label"><i class="ri-attachment-2"></i> Select File</div>
                <div class="file-drop" id="file-drop-box">
                    <i class="ri-upload-cloud-2-line"></i>
                    <span class="file-drop-text" id="file-drop-text">No file selected</span>
                    <span class="file-drop-sub">PDF, Word, Excel, PowerPoint, ZIP, image, audio, video · up to 25MB</span>
                </div>
                <label class="file-label"><i class="ri-folder-upload-line"></i> <span>Choose File</span>
                    <input type="file" name="group_file" id="group_file_input" required onchange="previewFileName(this)">
                </label>
            </div>

            <div class="field-block">
                <div class="field-label"><i class="ri-text"></i> Title</div>
                <input class="gc-input" type="text" name="title" placeholder="e.g. Beginner's Python Guide" maxlength="150" required>
            </div>

            <div class="field-block">
                <div class="field-label"><i class="ri-file-text-line"></i> Description</div>
                <textarea class="gc-textarea" name="description" placeholder="What's this file about?" maxlength="1000"></textarea>
            </div>

            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <button class="gc-btn" type="submit" name="upload_file"><i class="ri-upload-2-line"></i> Upload to <?php echo htmlspecialchars($group['title']); ?></button>
        </form>
    </div>
  </div>
</div>

<!-- ══ EDIT FILE MODAL ══ -->
<div class="modal-overlay" id="modal-edit-file">
  <div class="modal-lane">
    <div class="modal-topbar">
        <button type="button" class="ac-x" onclick="closeModal('modal-edit-file')" aria-label="Close">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
        <span class="modal-title">Edit File Info</span>
    </div>
    <div class="modal-body">
        <form method="POST" action="group_files.php?id=<?php echo $group_id; ?>" id="editFileForm">
            <input type="hidden" name="file_id" id="edit_file_id" value="">
            <div class="field-block">
                <div class="field-label"><i class="ri-text"></i> Title</div>
                <input class="gc-input" type="text" name="title" id="edit_file_title" maxlength="150" required>
            </div>
            <div class="field-block">
                <div class="field-label"><i class="ri-file-text-line"></i> Description</div>
                <textarea class="gc-textarea" name="description" id="edit_file_description" maxlength="1000"></textarea>
            </div>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <button class="gc-btn" type="submit" name="edit_file"><i class="ri-save-line"></i> Save Changes</button>
        </form>
    </div>
  </div>
</div>

<!-- ══ UPLOAD PERMISSIONS MODAL (owner only) ══ -->
<?php if ($is_owner): ?>
<div class="modal-overlay" id="modal-permissions">
  <div class="modal-lane">
    <div class="modal-topbar">
        <button type="button" class="ac-x" onclick="closeModal('modal-permissions')" aria-label="Close">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
        <span class="modal-title">Who Can Upload Files</span>
    </div>
    <div class="modal-body">
        <form method="POST" action="group_files.php?id=<?php echo $group_id; ?>">
            <label class="perm-opt<?php echo $upload_permission==='everyone'?' selected':''; ?>" onclick="selectPerm(this)">
                <input type="radio" name="upload_permission" value="everyone" <?php echo $upload_permission==='everyone'?'checked':''; ?>>
                <span class="perm-radio"></span>
                <span class="perm-label"><b>Everyone</b><span>Any member of this pod can upload files</span></span>
            </label>
            <label class="perm-opt<?php echo $upload_permission==='admins'?' selected':''; ?>" onclick="selectPerm(this)">
                <input type="radio" name="upload_permission" value="admins" <?php echo $upload_permission==='admins'?'checked':''; ?>>
                <span class="perm-radio"></span>
                <span class="perm-label"><b>Admins &amp; Owner</b><span>Only pod admins and you can upload files</span></span>
            </label>
            <label class="perm-opt<?php echo $upload_permission==='owner'?' selected':''; ?>" onclick="selectPerm(this)">
                <input type="radio" name="upload_permission" value="owner" <?php echo $upload_permission==='owner'?'checked':''; ?>>
                <span class="perm-radio"></span>
                <span class="perm-label"><b>Only Me</b><span>Only you, the pod owner, can upload files</span></span>
            </label>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <button class="gc-btn" type="submit" name="update_upload_permission"><i class="ri-save-line"></i> Save Setting</button>
        </form>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="toast" id="toast"><i class="ri-checkbox-circle-fill"></i> <span id="toastMsg">Link copied</span></div>

<script>
function openModal(id){
    var m = document.getElementById(id);
    if(!m) return;
    m.classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeModal(id){
    var m = document.getElementById(id);
    if(!m) return;
    m.classList.remove('active');
    document.body.style.overflow = '';
}
document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.active').forEach(function(m){ m.classList.remove('active'); });
        document.querySelectorAll('.dropdown-overlay.show').forEach(function(d){ d.classList.remove('show'); });
        document.body.style.overflow = '';
    }
});

function previewFileName(input){
    var box = document.getElementById('file-drop-box');
    var text = document.getElementById('file-drop-text');
    if (input.files && input.files[0]) {
        text.textContent = input.files[0].name;
        box.classList.add('has-file');
    } else {
        text.textContent = 'No file selected';
        box.classList.remove('has-file');
    }
}

function openEditModal(id, title, description){
    document.getElementById('edit_file_id').value = id;
    document.getElementById('edit_file_title').value = title;
    document.getElementById('edit_file_description').value = description;
    document.querySelectorAll('.dropdown-overlay.show').forEach(function(d){ d.classList.remove('show'); });
    openModal('modal-edit-file');
}

function selectPerm(label){
    document.querySelectorAll('.perm-opt').forEach(function(el){ el.classList.remove('selected'); });
    label.classList.add('selected');
    label.querySelector('input').checked = true;
}

function toggleDropdown(id, evt){
    if (evt) evt.preventDefault();
    var target = document.getElementById(id);
    if (!target) return;
    var wasOpen = target.classList.contains('show');
    document.querySelectorAll('.dropdown-overlay.show').forEach(function(d){ d.classList.remove('show'); });
    if (!wasOpen) {
        target.classList.add('show');
        document.body.style.overflow = 'hidden';
    } else {
        document.body.style.overflow = '';
    }
}
document.addEventListener('click', function(e){
    if (e.target.classList && e.target.classList.contains('dropdown-overlay')) {
        e.target.classList.remove('show');
        document.body.style.overflow = '';
    }
});

function toggleSearch(){
    var w = document.getElementById('searchWrap');
    w.classList.toggle('open');
    var tb = document.getElementById('searchToggleBtn');
    if (tb) tb.classList.toggle('on', w.classList.contains('open'));
    if (w.classList.contains('open')) {
        setTimeout(function(){ document.getElementById('searchInput').focus(); }, 200);
    }
}

function showToast(msg){
    var t = document.getElementById('toast');
    document.getElementById('toastMsg').textContent = msg;
    t.classList.add('show');
    clearTimeout(window._toastTimer);
    window._toastTimer = setTimeout(function(){ t.classList.remove('show'); }, 2200);
}

function shareFile(fileId){
    document.querySelectorAll('.dropdown-overlay.show').forEach(function(d){ d.classList.remove('show'); });
    document.body.style.overflow = '';
    var url = window.location.origin + window.location.pathname + '?id=<?php echo $group_id; ?>&dl=' + fileId;
    if (navigator.share) {
        navigator.share({ title: 'File from <?php echo htmlspecialchars(addslashes($group['title'])); ?>', url: url }).catch(function(){});
    } else if (navigator.clipboard) {
        navigator.clipboard.writeText(url).then(function(){ showToast('Link copied to clipboard'); });
    } else {
        showToast('Copy this link: ' + url);
    }
}

function clearSearch(){
    document.getElementById('searchInput').value = '';
    document.getElementById('searchForm').submit();
}
<?php if ($search_q !== ''): ?>
document.addEventListener('DOMContentLoaded', function(){ document.getElementById('searchWrap').classList.add('open'); var tb=document.getElementById('searchToggleBtn'); if(tb) tb.classList.add('on'); });
<?php endif; ?>

/* ── Skeleton loader dismissal ── */
(function(){
    var sk = document.getElementById('page-skeleton');
    if(!sk) return;
    var done = false;
    function hide(){
        if(done) return; done = true;
        sk.classList.add('hide');
        setTimeout(function(){ if(sk.parentNode) sk.remove(); }, 380);
    }
    window.addEventListener('load', function(){ setTimeout(hide, 280); });
    setTimeout(hide, 1500);   // safety net
})();
</script>

</body>
</html>