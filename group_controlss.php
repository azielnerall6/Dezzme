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

/* ── Fetch group + permissions (same shape as group.php) ── */
$stmt = $conn->prepare("SELECT g.*, u.username AS creator_name, u.profile_pic AS creator_pic FROM groups g JOIN users u ON g.created_by=u.id WHERE g.id=?");
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

if (!$is_owner && !$is_admin) { header("Location: group.php?id=$group_id"); exit(); }

$is_private_group = ($group['privacy'] ?? 'public') === 'private';
$is_adult_group    = !empty($group['is_adult']);

/* ── Counts / lists ── */
$member_count = (int)$conn->query("SELECT COUNT(*) c FROM group_members WHERE group_id=$group_id")->fetch_assoc()['c'];

$admins = [];
$res = $conn->query("SELECT u.id,u.username,u.profile_pic FROM group_admins ga JOIN users u ON u.id=ga.user_id WHERE ga.group_id=$group_id");
while ($r = $res->fetch_assoc()) $admins[$r['id']] = $r;

$group_hashtags = [];
$res = $conn->query("SELECT id,tag FROM group_hashtags WHERE group_id=$group_id ORDER BY created_at ASC");
while ($r = $res->fetch_assoc()) $group_hashtags[] = $r;

$pending_join_requests = [];
if ($is_private_group) {
    $res = $conn->query("SELECT jr.user_id, jr.requested_at, u.username, u.profile_pic FROM group_join_requests jr JOIN users u ON u.id=jr.user_id WHERE jr.group_id=$group_id AND jr.status='pending' ORDER BY jr.requested_at ASC");
    while ($r = $res->fetch_assoc()) $pending_join_requests[] = $r;
}
$pending_request_count = count($pending_join_requests);

$reported_posts = [];
$res = $conn->query("
    SELECT gpr.id AS report_id, gpr.reason, gpr.description,
           gp.id AS group_post_id, m.id AS meme_id, m.file_name, m.caption, m.type,
           u.username AS poster_username
    FROM group_post_reports gpr
    JOIN group_posts gp ON gp.id = gpr.group_post_id
    JOIN memes m ON m.id = gp.meme_id
    JOIN users u ON u.id = gp.posted_by
    WHERE gpr.group_id=$group_id AND gpr.status='pending'
    ORDER BY gpr.created_at ASC
");
while ($r = $res->fetch_assoc()) $reported_posts[] = $r;
$reported_post_count = count($reported_posts);

$panel_members_sql = "SELECT u.id,u.username,u.profile_pic FROM group_members gm JOIN users u ON u.id=gm.user_id WHERE gm.group_id=$group_id AND u.id!=$current_user AND u.id!=$group_creator_id";
$panel_members = $conn->query($panel_members_sql);

$owner_bans = [];
if ($is_owner) {
    $res = $conn->query("SELECT u.id,u.username,u.profile_pic FROM blocks b JOIN users u ON u.id=b.blocked_id WHERE b.blocker_id=$current_user");
    while ($r = $res->fetch_assoc()) $owner_bans[$r['id']] = $r;
}
$group_ban_list = [];
$res = $conn->query("SELECT u.id,u.username,u.profile_pic FROM group_bans gb JOIN users u ON u.id=gb.banned_id WHERE gb.group_id=$group_id");
while ($r = $res->fetch_assoc()) $group_ban_list[$r['id']] = $r;
$visible_bans = $is_owner ? ($owner_bans + $group_ban_list) : $group_ban_list;

$img_src = empty($group['group_pic']) ? 'uploads/default_group.png' : (strpos($group['group_pic'],'uploads/')===0 ? $group['group_pic'] : 'uploads/'.$group['group_pic']);
$banner_src = !empty($group['banner_pic']) ? (strpos($group['banner_pic'],'uploads/')===0 ? $group['banner_pic'] : 'uploads/'.$group['banner_pic']) : $img_src;

function gc_confirm_js($str) { return htmlspecialchars(json_encode($str), ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta charset="UTF-8">
<title><?php echo htmlspecialchars($group['title']); ?> | Group Controls</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
* { font-family:'Poppins', sans-serif; }
button, a { cursor:pointer; outline:none; }

:root{
    --red:#FF4C4C;
    --surface:rgba(255,255,255,0.05);
    --border:rgba(255,255,255,0.10);
    --text:#ffffff;
    --muted:rgba(255,255,255,0.45);
    --divider:rgba(255,255,255,0.08);
}

body{
    font-family:'Poppins',sans-serif;
    background:linear-gradient(-45deg,#010218,#070924,#070924);
    background-attachment:fixed;
    color:var(--text);
    min-height:100vh;
    display:flex; flex-direction:column; align-items:center;
    padding:0 0 72px;
    overflow-x:hidden;
}

/* ── Topbar ── */
.topbar{
    width:100%; max-width:520px;
    display:flex; align-items:center; gap:14px;
    padding:20px 16px 14px;
    position:sticky; top:0; z-index:100;
    background:linear-gradient(-45deg,#010218,#070924,#070924);
    backdrop-filter:blur(12px); -webkit-backdrop-filter:blur(12px);
}
.back-btn{
    background:none; border:none; color:var(--text); font-size:1.7rem;
    cursor:pointer; opacity:.75; padding:0;
    display:flex; align-items:center; flex-shrink:0;
    transition:opacity .2s,color .2s; text-decoration:none;
}
.back-btn:hover{ opacity:1; color:var(--red); }
.topbar-title-wrap{ flex:1; min-width:0; }
.topbar-title{ font-size:1rem; font-weight:600; color:var(--text); display:flex; align-items:center; gap:8px; }
.topbar-title i{ color:var(--red); font-size:1.1rem; }
.topbar-subtitle{ font-size:.7rem; color:var(--muted); margin-top:2px; margin-left:21px; }

/* ── Header block ── */
.group-header{
    width:100%; max-width:520px;
    display:flex; flex-direction:column; align-items:center;
    padding:20px 20px 24px; text-align:center;
}
.group-title{ font-size:1.05rem; font-weight:700; display:flex; align-items:center; gap:6px; justify-content:center; margin-bottom:3px; }
.group-label{ font-size:.7rem; color:var(--muted); font-weight:400; text-transform:uppercase; letter-spacing:.08em; }
.role-badge{
    display:inline-flex; align-items:center; gap:5px; margin-top:10px;
    border:1.5px solid rgba(255,76,76,.4); border-radius:20px;
    padding:4px 12px; font-size:.7rem; font-weight:700; color:var(--red);
}

/* ── Stats bar ── */
.stats-bar{
    width:100%; max-width:520px;
    display:grid; grid-template-columns:repeat(3,1fr);
    border-top:1px solid var(--divider); border-bottom:1px solid var(--divider);
    padding:0 16px;
}
.stat-item{ display:flex; flex-direction:column; align-items:center; padding:16px 8px; text-align:center; }
.stat-item + .stat-item{ border-left:1px solid var(--divider); }
.stat-num{ font-size:1.2rem; font-weight:700; color:var(--text); line-height:1; }
.stat-lbl{ font-size:.6rem; color:var(--muted); margin-top:4px; text-transform:uppercase; letter-spacing:.03em; }

/* ── Section ── */
.section-label{ width:100%; max-width:520px; padding:22px 16px 10px; font-size:.6rem; font-weight:700; text-transform:uppercase; letter-spacing:1.5px; color:var(--muted); display:flex; align-items:center; gap:6px; }
.section-label i{ color:var(--red); font-size:.9rem; }
.badge-count{ background:var(--red); color:#fff; font-size:.58rem; font-weight:700; padding:2px 7px; border-radius:20px; }

.card-list{ width:100%; max-width:520px; padding:0 16px; display:flex; flex-direction:column; gap:10px; }

.card{
    background:var(--surface); border:1px solid var(--border); border-radius:16px;
    padding:16px; display:flex; flex-direction:column; gap:10px;
}
.card-label{ font-size:.7rem; color:var(--muted); display:flex; align-items:center; gap:6px; }
.card-label i{ color:#4dabff; }

/* ── Plain / compact settings rows (no card chrome) ── */
.settings-list{ gap:0; }
.card.plain{
    background:none; border:none; border-radius:0;
    padding:14px 0; gap:8px;
    border-bottom:1px solid var(--divider);
}
.card.plain:first-child{ padding-top:4px; }
.card.plain:last-child{ border-bottom:none; }

.preview-box{
    border-radius:12px; overflow:hidden; height:90px;
    border:1px solid var(--border); background:rgba(255,255,255,.03);
    display:flex; align-items:center; justify-content:center;
}
.preview-box img{ width:100%; height:90px; object-fit:cover; display:block; }
.preview-empty{ font-size:.72rem; color:var(--muted); }

.form-row{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
.gc-input{
    flex:1; min-width:0; padding:10px 14px; border-radius:12px;
    border:1px solid var(--border); background:rgba(255,255,255,.06);
    color:#fff; font-size:.85rem; outline:none; transition:.25s;
}
.gc-input:focus{ border-color:rgba(255,76,76,.7); box-shadow:0 0 8px rgba(255,76,76,.2); }
.gc-input::placeholder{ color:rgba(255,255,255,.3); }

.gc-textarea{
    width:100%; min-height:90px; padding:10px 13px; border-radius:12px;
    border:1px solid var(--border); background:rgba(255,255,255,.06);
    color:#fff; font-size:.8rem; outline:none; resize:vertical; box-sizing:border-box;
}
.gc-textarea:focus{ border-color:rgba(255,76,76,.7); }

.gc-btn{
    padding:10px 18px; border-radius:12px; border:none;
    background:rgba(255,255,255,.09); color:#fff; font-size:.8rem; font-weight:600;
    cursor:pointer; transition:.2s; white-space:nowrap; flex-shrink:0;
}
.gc-btn:hover{ background:rgba(255,255,255,.17); }
.gc-btn.danger{ background:rgba(255,45,45,.2); color:#ff7a7a; border:1px solid rgba(255,45,45,.3); }
.gc-btn.danger:hover{ background:rgba(255,45,45,.45); color:#fff; }
.gc-btn.wide{ width:100%; justify-content:center; display:flex; align-items:center; gap:6px; }

.file-label{
    display:inline-flex; align-items:center; gap:7px; padding:10px 16px; border-radius:12px;
    background:rgba(255,255,255,.06); border:1px solid var(--border); color:#fff; font-size:.8rem;
    cursor:pointer; transition:.2s; white-space:nowrap; flex-shrink:0;
}
.file-label:hover{ background:rgba(255,76,76,.1); border-color:rgba(255,76,76,.25); }
.file-label input{ display:none; }

.toggle-row{ display:flex; align-items:center; gap:10px; }
.toggle-note{ font-size:.78rem; color:var(--muted); flex:1; }
.toggle-note strong{ color:var(--text); }
.toggle-btn{
    padding:10px 18px; border-radius:12px; border:none; font-size:.8rem; font-weight:600;
    cursor:pointer; transition:.2s; white-space:nowrap;
}
.toggle-btn.on{ background:rgba(255,45,45,.25); color:#ff7a7a; border:1px solid rgba(255,45,45,.45); }
.toggle-btn.on:hover{ background:rgba(255,45,45,.4); color:#fff; }
.toggle-btn.off{ background:rgba(255,255,255,.08); color:rgba(255,255,255,.7); border:1px solid var(--border); }
.toggle-btn.off:hover{ background:rgba(255,76,76,.08); border-color:rgba(255,76,76,.2); color:#fff; }

/* Chips */
.chip-wrap{ display:flex; flex-wrap:wrap; gap:8px; }
.chip{
    display:flex; align-items:center; gap:7px; background:rgba(255,76,76,.08);
    border:1px solid rgba(255,76,76,.3); border-radius:30px; padding:5px 12px 5px 7px;
    font-size:.75rem; font-weight:600; color:var(--red);
}
.chip img{ width:24px; height:24px; border-radius:50%; border:1.5px solid var(--red); object-fit:cover; }
.chip button{ background:none; border:none; color:rgba(255,76,76,.55); cursor:pointer; font-size:14px; padding:0 0 0 2px; }
.chip button:hover{ color:#ff4c4c; }

/* Row lists (members / bans / requests / reports) */
.row-item{
    display:flex; align-items:center; gap:10px; padding:10px 12px;
    background:rgba(255,255,255,.03); border:1px solid var(--border); border-radius:14px;
}
.row-item img{ width:36px; height:36px; border-radius:50%; object-fit:cover; border:2px solid var(--red); flex-shrink:0; }
.row-item img.dim{ filter:grayscale(.4); border-color:rgba(255,76,76,.4); }
.row-info{ flex:1; min-width:0; }
.row-name{ font-size:.78rem; font-weight:600; color:#fff; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.row-sub{ font-size:.66rem; color:var(--muted); margin-top:2px; }
.row-sub.admin{ color:var(--red); font-weight:700; }
.row-actions{ display:flex; gap:6px; flex-shrink:0; }

.tiny-btn{
    padding:6px 11px; border-radius:10px; font-size:.7rem; font-weight:600; cursor:pointer;
    transition:.2s; border:1px solid; display:inline-flex; align-items:center; gap:4px; white-space:nowrap;
}
.tiny-btn.kick{ border-color:rgba(255,76,76,.25); background:rgba(255,76,76,.07); color:#ff8a8a; }
.tiny-btn.kick:hover{ background:rgba(255,76,76,.18); }
.tiny-btn.ban{ border-color:rgba(255,76,76,.4); background:rgba(255,76,76,.12); color:#ff6b6b; }
.tiny-btn.ban:hover{ background:rgba(255,76,76,.28); }
.tiny-btn.approve{ border-color:rgba(76,255,145,.3); background:rgba(76,255,145,.08); color:#4cff91; }
.tiny-btn.approve:hover{ background:rgba(76,255,145,.18); }

.no-items{ font-size:.78rem; color:var(--muted); font-style:italic; padding:6px 4px; }

.report-desc{ font-size:.7rem; color:rgba(255,255,255,.35); font-style:italic; margin-top:2px; }
.report-reason{ font-size:.7rem; color:#ff8a8a; margin-top:2px; display:flex; align-items:center; gap:4px; }

.divider{ width:100%; max-width:520px; padding:0 16px; }
.divider hr{ border:none; border-top:1px solid var(--divider); margin:6px 0 0; }

/* ══════════════════════════════════════════════
   RESPONSIVE — 790 / 490 / 390 / 330
   ══════════════════════════════════════════════ */

/* ── ≤790px : slightly tighter shell, still "desktop-ish" ── */
@media (max-width:790px){
    .topbar, .group-header, .stats-bar, .section-label, .card-list, .divider{
        max-width:100%;
    }
    .topbar{ padding:18px 16px 12px; gap:12px; }
    .group-header{ padding:18px 18px 22px; }
    .card{ padding:15px; border-radius:15px; }
    .gc-btn, .file-label{ padding:9px 16px; }
}

/* ── ≤490px : mobile baseline ── */
@media (max-width:490px){
    .topbar{ padding:16px 14px 12px; gap:10px; }
    .back-btn{ font-size:1.5rem; }
    .topbar-title{ font-size:.92rem; gap:6px; }
    .topbar-title i{ font-size:1rem; }
    .topbar-subtitle{ font-size:.66rem; margin-left:18px; }

    .group-header{ padding:16px 16px 20px; }
    .group-title{ font-size:.95rem; gap:5px; }
    .group-label{ font-size:.66rem; }
    .role-badge{ padding:4px 10px; font-size:.66rem; margin-top:8px; }

    .stats-bar{ padding:0 14px; }
    .stat-item{ padding:14px 6px; }
    .stat-num{ font-size:1.08rem; }
    .stat-lbl{ font-size:.56rem; }

    .section-label{ padding:20px 14px 9px; font-size:.58rem; letter-spacing:1.2px; }
    .card-list{ padding:0 14px; gap:9px; }
    .card{ padding:14px; border-radius:14px; gap:9px; }
    .card-label{ font-size:.68rem; }

    .preview-box{ height:80px; }
    .preview-box img{ height:80px; }

    .gc-input{ padding:9px 13px; font-size:.82rem; }
    .gc-textarea{ min-height:80px; padding:9px 12px; font-size:.78rem; }
    .gc-btn{ padding:9px 16px; font-size:.78rem; }
    .file-label{ padding:9px 15px; font-size:.78rem; }

    .toggle-note{ font-size:.75rem; }
    .toggle-btn{ padding:9px 16px; font-size:.78rem; }

    .chip{ padding:4px 10px 4px 6px; font-size:.72rem; }
    .chip img{ width:22px; height:22px; }

    .row-item{ padding:9px 11px; gap:9px; border-radius:13px; }
    .row-item img{ width:33px; height:33px; }
    .row-name{ font-size:.75rem; }
    .row-sub{ font-size:.63rem; }
    .tiny-btn{ padding:5px 10px; font-size:.66rem; }

    .divider{ padding:0 14px; }
}

/* ── ≤390px : compact phones ── */
@media (max-width:390px){
    .topbar{ padding:14px 12px 10px; gap:9px; }
    .back-btn{ font-size:1.35rem; }
    .topbar-title{ font-size:.85rem; }
    .topbar-title i{ font-size:.92rem; }
    .topbar-subtitle{ font-size:.62rem; margin-left:16px; }

    .group-header{ padding:14px 14px 18px; }
    .group-title{ font-size:.88rem; }
    .group-label{ font-size:.62rem; }
    .role-badge{ padding:3px 9px; font-size:.62rem; margin-top:7px; }

    .stats-bar{ padding:0 12px; }
    .stat-item{ padding:12px 5px; }
    .stat-num{ font-size:1rem; }
    .stat-lbl{ font-size:.53rem; margin-top:3px; }

    .section-label{ padding:18px 12px 8px; font-size:.55rem; letter-spacing:1px; }
    .badge-count{ font-size:.55rem; padding:2px 6px; }
    .card-list{ padding:0 12px; gap:8px; }
    .card{ padding:12px; border-radius:13px; gap:8px; }
    .card-label{ font-size:.65rem; }

    .preview-box{ height:72px; border-radius:11px; }
    .preview-box img{ height:72px; }
    .preview-empty{ font-size:.68rem; }

    .form-row{ gap:8px; }
    .gc-input{ padding:8px 12px; font-size:.78rem; border-radius:11px; }
    .gc-textarea{ min-height:72px; padding:8px 11px; font-size:.75rem; border-radius:11px; }
    .gc-btn{ padding:8px 14px; font-size:.75rem; border-radius:11px; }
    .file-label{ padding:8px 13px; font-size:.75rem; border-radius:11px; gap:6px; }

    .toggle-row{ gap:8px; }
    .toggle-note{ font-size:.72rem; }
    .toggle-btn{ padding:8px 14px; font-size:.75rem; border-radius:11px; }

    .chip-wrap{ gap:6px; }
    .chip{ padding:4px 9px 4px 5px; font-size:.68rem; gap:5px; }
    .chip img{ width:20px; height:20px; }

    .row-item{ padding:8px 10px; gap:8px; border-radius:12px; }
    .row-item img{ width:30px; height:30px; }
    .row-name{ font-size:.72rem; }
    .row-sub{ font-size:.6rem; }
    .row-actions{ gap:5px; }
    .tiny-btn{ padding:5px 9px; font-size:.63rem; border-radius:9px; gap:3px; }

    .no-items{ font-size:.72rem; }
    .report-desc, .report-reason{ font-size:.66rem; }

    .divider{ padding:0 12px; }
}

/* ── ≤330px : smallest supported (SE-class devices) ── */
@media (max-width:330px){
    .topbar{ padding:12px 10px 9px; gap:8px; }
    .back-btn{ font-size:1.2rem; }
    .topbar-title{ font-size:.78rem; gap:5px; }
    .topbar-title i{ font-size:.86rem; }
    .topbar-subtitle{ font-size:.58rem; margin-left:14px; }

    .group-header{ padding:12px 10px 16px; }
    .group-title{ font-size:.8rem; gap:4px; }
    .group-label{ font-size:.58rem; }
    .role-badge{ padding:3px 8px; font-size:.58rem; margin-top:6px; gap:4px; }

    .stats-bar{ padding:0 10px; }
    .stat-item{ padding:10px 4px; }
    .stat-num{ font-size:.92rem; }
    .stat-lbl{ font-size:.5rem; margin-top:3px; }

    .section-label{ padding:16px 10px 7px; font-size:.52rem; letter-spacing:.8px; gap:5px; }
    .badge-count{ font-size:.52rem; padding:1px 6px; }
    .card-list{ padding:0 10px; gap:7px; }
    .card{ padding:11px; border-radius:12px; gap:7px; }
    .card-label{ font-size:.62rem; gap:5px; }

    .preview-box{ height:64px; border-radius:10px; }
    .preview-box img{ height:64px; }
    .preview-empty{ font-size:.64rem; }

    .form-row{ gap:7px; }
    .gc-input{ padding:7px 11px; font-size:.74rem; border-radius:10px; }
    .gc-textarea{ min-height:64px; padding:7px 10px; font-size:.72rem; border-radius:10px; }
    .gc-btn{ padding:7px 12px; font-size:.72rem; border-radius:10px; }
    .file-label{ padding:7px 11px; font-size:.72rem; border-radius:10px; gap:5px; }

    .toggle-row{ gap:7px; flex-wrap:wrap; }
    .toggle-note{ font-size:.68rem; }
    .toggle-btn{ padding:7px 12px; font-size:.72rem; border-radius:10px; }

    .chip-wrap{ gap:5px; }
    .chip{ padding:3px 8px 3px 5px; font-size:.64rem; gap:4px; }
    .chip img{ width:18px; height:18px; }

    .row-item{ padding:7px 9px; gap:7px; border-radius:11px; }
    .row-item img{ width:27px; height:27px; border-width:1.5px; }
    .row-name{ font-size:.68rem; }
    .row-sub{ font-size:.56rem; }
    .row-actions{ gap:4px; }
    .tiny-btn{ padding:4px 8px; font-size:.6rem; border-radius:8px; gap:3px; }

    .no-items{ font-size:.68rem; }
    .report-desc, .report-reason{ font-size:.62rem; }

    .divider{ padding:0 10px; }
}
</style>
</head>
<body>

<!-- ══ TOPBAR ══ -->
<div class="topbar">
    <button class="back-btn" onclick="if(document.referrer && document.referrer.indexOf(window.location.host)!==-1){history.back();}else{location.href='group.php?id=<?php echo $group_id; ?>';}" aria-label="Go back">
        <i class="ri-arrow-left-s-line"></i>
    </button>
    <div class="topbar-title-wrap">
        <div class="topbar-title"><i class="ri-shield-star-line"></i> <?php echo $is_owner ? 'Group Controls' : 'Admin Controls'; ?></div>
    </div>
</div>

<!-- ══ HEADER ══ -->
<div class="group-header">
    <div class="group-title"><?php echo htmlspecialchars($group['title']); ?></div>
    <div class="group-label"><?php echo $is_private_group ? 'Private Pod' : 'Public Pod'; ?></div>
    <div class="role-badge"><i class="ri-<?php echo $is_owner ? 'vip-crown-line' : 'shield-check-line'; ?>"></i> <?php echo $is_owner ? 'Owner' : 'Admin'; ?></div>
</div>

<!-- ══ STATS ══ -->
<div class="stats-bar">
    <div class="stat-item"><div class="stat-num"><?php echo number_format($member_count); ?></div><div class="stat-lbl">Members</div></div>
    <div class="stat-item"><div class="stat-num"><?php echo count($admins); ?></div><div class="stat-lbl">Admins</div></div>
    <div class="stat-item"><div class="stat-num"><?php echo $pending_request_count + $reported_post_count; ?></div><div class="stat-lbl">Needs Review</div></div>
</div>

<?php if ($is_owner): ?>
<!-- ══ GROUP SETTINGS ══ -->
<div class="section-label"><i class="ri-settings-3-line"></i> Group Settings</div>
<div class="card-list settings-list">

    <div class="card plain">
        <div class="card-label"><i class="ri-landscape-line"></i> Banner Picture</div>
        <div class="preview-box">
            <?php if (!empty($group['banner_pic'])): ?>
                <img src="<?php echo htmlspecialchars($banner_src); ?>" onerror="this.style.display='none'">
            <?php else: ?><span class="preview-empty">No banner set</span><?php endif; ?>
        </div>
        <form method="POST" action="group.php?id=<?php echo $group_id; ?>" enctype="multipart/form-data">
            <div class="form-row">
                <label class="file-label"><i class="ri-upload-cloud-line"></i> <span>Choose Banner</span>
                    <input type="file" name="banner_pic" accept="image/*">
                </label>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <button class="gc-btn" type="submit" name="change_banner_pic">Save</button>
            </div>
        </form>
    </div>

    <div class="card plain">
        <div class="card-label"><i class="ri-text"></i> Group Name</div>
        <form method="POST" action="group.php?id=<?php echo $group_id; ?>">
            <div class="form-row">
                <input class="gc-input" type="text" name="new_title" value="<?php echo htmlspecialchars($group['title']); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <button class="gc-btn" type="submit" name="change_group_title">Rename</button>
            </div>
        </form>
    </div>

    <div class="card plain">
        <div class="card-label"><i class="ri-align-left"></i> Description</div>
        <form method="POST" action="group.php?id=<?php echo $group_id; ?>">
            <div class="form-row">
                <input class="gc-input" type="text" name="new_desc" placeholder="Update description..." value="<?php echo htmlspecialchars($group['description'] ?? ''); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <button class="gc-btn" type="submit" name="change_group_desc">Update</button>
            </div>
        </form>
    </div>

    <div class="card plain">
        <div class="card-label"><i class="ri-error-warning-line"></i> 18+ Content Toggle</div>
        <div class="toggle-row">
            <span class="toggle-note"><?php echo $is_adult_group ? 'Currently: <strong>18+ Enabled</strong>' : 'Currently: <strong>Not 18+</strong>'; ?></span>
            <form method="POST" action="group.php?id=<?php echo $group_id; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <button type="submit" name="toggle_adult" class="toggle-btn <?php echo $is_adult_group ? 'on' : 'off'; ?>">
                    <?php echo $is_adult_group ? 'Disable 18+' : 'Enable 18+'; ?>
                </button>
            </form>
        </div>
    </div>

    <div class="card plain">
        <div class="card-label"><i class="ri-lock-line"></i> Group Privacy</div>
        <div class="toggle-row">
            <span class="toggle-note"><?php echo $is_private_group ? 'Currently: <strong>Private</strong> — requests required' : 'Currently: <strong>Public</strong> — instant join'; ?></span>
            <?php $priv_msg = $is_private_group ? 'Make this group public? Pending requests will be auto-approved.' : 'Make this group private? New members must request to join.'; ?>
            <form method="POST" action="group.php?id=<?php echo $group_id; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <button type="submit" name="toggle_privacy" class="toggle-btn <?php echo $is_private_group ? 'on' : 'off'; ?>" onclick="return confirm(<?php echo gc_confirm_js($priv_msg); ?>)">
                    <?php echo $is_private_group ? 'Make Public' : 'Make Private'; ?>
                </button>
            </form>
        </div>
    </div>

    <div class="card plain">
        <div class="card-label"><i class="ri-file-edit-line"></i> Additional Guidelines</div>
        <form method="POST" action="group.php?id=<?php echo $group_id; ?>">
            <textarea class="gc-textarea" name="custom_guidelines" placeholder="Write your own rules..."><?php echo htmlspecialchars($group['custom_guidelines'] ?? ''); ?></textarea>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <div style="display:flex;justify-content:flex-end;margin-top:8px;">
                <button class="gc-btn" type="submit" name="save_guidelines"><i class="ri-save-line"></i> Save</button>
            </div>
        </form>
    </div>

</div>
<?php endif; ?>

<!-- ══ ADMINS ══ -->
<?php if ($is_owner): ?>
<div class="section-label"><i class="ri-admin-line"></i> Admins</div>
<div class="card-list">
    <div class="card">
        <div class="card-label"><i class="ri-admin-line"></i> Appoint Admin</div>
        <form method="POST" action="group.php?id=<?php echo $group_id; ?>">
            <div class="form-row">
                <select name="appoint_user_id" class="gc-input" style="cursor:pointer;">
                    <option value="">— select a member —</option>
                    <?php
                    $mem_opts = $conn->query("SELECT u.id,u.username FROM group_members gm JOIN users u ON u.id=gm.user_id WHERE gm.group_id=$group_id AND gm.user_id!=$current_user");
                    while ($mo = $mem_opts->fetch_assoc()):
                        $already = isset($admins[$mo['id']]);
                    ?>
                    <option value="<?php echo $mo['id']; ?>" <?php if($already) echo 'disabled'; ?>><?php echo htmlspecialchars($mo['username']); ?><?php if($already) echo ' (admin)'; ?></option>
                    <?php endwhile; ?>
                </select>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <button class="gc-btn" type="submit" name="appoint_admin">Appoint</button>
            </div>
        </form>
        <?php if (!empty($admins)): ?>
        <div class="chip-wrap">
            <?php foreach ($admins as $adm): ?>
            <div class="chip">
                <img src="<?php echo !empty($adm['profile_pic']) ? $adm['profile_pic'] : 'uploads/me.jpeg'; ?>" onerror="this.src='uploads/me.jpeg'">
                <?php echo htmlspecialchars($adm['username']); ?>
                <form method="POST" action="group.php?id=<?php echo $group_id; ?>" style="display:inline;">
                    <input type="hidden" name="remove_admin_id" value="<?php echo $adm['id']; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <button type="submit" name="remove_admin" title="Remove admin">×</button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ══ HASHTAGS ══ -->
<div class="section-label"><i class="ri-hashtag"></i> Pod Hashtags</div>
<div class="card-list">
    <div class="card">
        <div class="card-label"><i class="ri-filter-3-line"></i> Hashtags members can filter by</div>
        <form method="POST" action="group.php?id=<?php echo $group_id; ?>">
            <div class="form-row">
                <input class="gc-input" type="text" name="new_hashtag" placeholder="e.g. memes (no # needed)" maxlength="30">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <button class="gc-btn" type="submit" name="add_group_hashtag">Add</button>
            </div>
        </form>
        <?php if (!empty($group_hashtags)): ?>
        <div class="chip-wrap">
            <?php foreach ($group_hashtags as $tag): ?>
            <div class="chip">
                #<?php echo htmlspecialchars($tag['tag']); ?>
                <form method="POST" action="group.php?id=<?php echo $group_id; ?>" style="display:inline;">
                    <input type="hidden" name="remove_tag_id" value="<?php echo (int)$tag['id']; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <button type="submit" name="remove_group_hashtag" title="Remove tag">×</button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p class="no-items">No hashtags set yet.</p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ══ JOIN REQUESTS ══ -->
<?php if ($is_private_group): ?>
<div class="section-label"><i class="ri-user-add-line"></i> Join Requests <?php if($pending_request_count>0): ?><span class="badge-count"><?php echo $pending_request_count; ?></span><?php endif; ?></div>
<div class="card-list">
    <?php if (!empty($pending_join_requests)): foreach ($pending_join_requests as $req):
        $req_pic = !empty($req['profile_pic']) ? $req['profile_pic'] : 'uploads/me.jpeg';
        $deny_msg = 'Deny ' . $req['username'] . "'s request to join?";
    ?>
    <div class="row-item">
        <img src="<?php echo htmlspecialchars($req_pic); ?>" onerror="this.src='uploads/me.jpeg'">
        <div class="row-info">
            <div class="row-name"><?php echo htmlspecialchars($req['username']); ?></div>
            <div class="row-sub">Requested <?php echo date('d M Y', strtotime($req['requested_at'])); ?></div>
        </div>
        <div class="row-actions">
            <form method="POST" action="group.php?id=<?php echo $group_id; ?>">
                <input type="hidden" name="request_user_id" value="<?php echo (int)$req['user_id']; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <button type="submit" name="approve_request" class="tiny-btn approve"><i class="ri-check-line"></i> Approve</button>
            </form>
            <form method="POST" action="group.php?id=<?php echo $group_id; ?>">
                <input type="hidden" name="request_user_id" value="<?php echo (int)$req['user_id']; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <button type="submit" name="deny_request" class="tiny-btn kick" onclick="return confirm(<?php echo gc_confirm_js($deny_msg); ?>)"><i class="ri-close-line"></i> Deny</button>
            </form>
        </div>
    </div>
    <?php endforeach; else: ?>
    <p class="no-items">No pending join requests.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ══ REPORTED POSTS ══ -->
<div class="section-label"><i class="ri-flag-line"></i> Reported Posts <?php if($reported_post_count>0): ?><span class="badge-count"><?php echo $reported_post_count; ?></span><?php endif; ?></div>
<div class="card-list">
    <?php if (!empty($reported_posts)): foreach ($reported_posts as $rp):
        $rp_thumb = (!empty($rp['file_name']) && $rp['file_name']!=='0') ? 'uploads/'.$rp['file_name'] : '';
        $rp_cap = mb_strlen($rp['caption']) > 50 ? mb_substr($rp['caption'],0,50).'…' : $rp['caption'];
        $del_msg = 'Delete this reported post by '.$rp['poster_username']."?\nThis cannot be undone.";
        $dis_msg = 'Dismiss this report? The post will stay up.';
    ?>
    <div class="row-item" style="align-items:flex-start;">
        <?php if ($rp_thumb): ?>
        <img src="<?php echo htmlspecialchars($rp_thumb); ?>" onerror="this.src='uploads/Text.jpg'">
        <?php else: ?>
        <div style="width:36px;height:36px;border-radius:9px;flex-shrink:0;background:rgba(255,255,255,.05);display:flex;align-items:center;justify-content:center;border:2px solid rgba(255,76,76,.35);"><i class="ri-file-text-line" style="color:rgba(255,255,255,.3);"></i></div>
        <?php endif; ?>
        <div class="row-info">
            <div class="row-name"><?php echo $rp_cap!=='' ? htmlspecialchars($rp_cap) : '<em style="color:rgba(255,255,255,.3);">(no caption)</em>'; ?></div>
            <div class="row-sub">by <?php echo htmlspecialchars($rp['poster_username']); ?></div>
            <div class="report-reason"><i class="ri-flag-fill"></i> <?php echo htmlspecialchars($rp['reason']); ?></div>
            <?php if (!empty($rp['description'])): ?>
            <div class="report-desc">"<?php echo htmlspecialchars(mb_strlen($rp['description'])>80 ? mb_substr($rp['description'],0,80).'…' : $rp['description']); ?>"</div>
            <?php endif; ?>
        </div>
        <div class="row-actions" style="flex-direction:column;">
            <form method="POST" action="group.php?id=<?php echo $group_id; ?>" onsubmit="return confirm(<?php echo gc_confirm_js($del_msg); ?>)">
                <input type="hidden" name="delete_post_id" value="<?php echo (int)$rp['group_post_id']; ?>">
                <input type="hidden" name="delete_meme_id" value="<?php echo (int)$rp['meme_id']; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <button type="submit" name="delete_post" class="tiny-btn ban"><i class="ri-delete-bin-line"></i> Delete</button>
            </form>
            <form method="POST" action="group.php?id=<?php echo $group_id; ?>" onsubmit="return confirm(<?php echo gc_confirm_js($dis_msg); ?>)">
                <input type="hidden" name="report_id" value="<?php echo (int)$rp['report_id']; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <button type="submit" name="dismiss_report" class="tiny-btn kick"><i class="ri-close-line"></i> Dismiss</button>
            </form>
        </div>
    </div>
    <?php endforeach; else: ?>
    <p class="no-items">No reported posts right now.</p>
    <?php endif; ?>
</div>

<!-- ══ MANAGE MEMBERS ══ -->
<div class="section-label"><i class="ri-team-line"></i> Manage Members</div>
<div class="card-list">
    <?php if ($panel_members && $panel_members->num_rows > 0): while ($pm = $panel_members->fetch_assoc()):
        $pm_pic = !empty($pm['profile_pic']) ? $pm['profile_pic'] : 'uploads/me.jpeg';
        $pm_is_admin = isset($admins[$pm['id']]);
        $can_act = $is_owner || ($is_admin && !$pm_is_admin);
        $kick_msg = 'Kick '.$pm['username']."?\nThey can rejoin.";
        $ban_msg  = 'BAN '.$pm['username']."?\n".($is_owner ? 'Blocked from ALL your groups.' : 'Cannot rejoin THIS group.');
    ?>
    <div class="row-item">
        <img src="<?php echo htmlspecialchars($pm_pic); ?>" onerror="this.src='uploads/me.jpeg'">
        <div class="row-info">
            <div class="row-name"><?php echo htmlspecialchars($pm['username']); ?></div>
            <?php if ($pm_is_admin): ?><div class="row-sub admin">ADMIN</div><?php endif; ?>
        </div>
        <?php if ($can_act): ?>
        <div class="row-actions">
            <form method="POST" action="group.php?id=<?php echo $group_id; ?>">
                <input type="hidden" name="remove_member_id" value="<?php echo $pm['id']; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <button type="submit" name="remove_member" class="tiny-btn kick" onclick="return confirm(<?php echo gc_confirm_js($kick_msg); ?>)"><i class="ri-logout-box-line"></i> Kick</button>
            </form>
            <form method="POST" action="group.php?id=<?php echo $group_id; ?>">
                <input type="hidden" name="ban_user_id" value="<?php echo $pm['id']; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <button type="submit" name="ban_member" class="tiny-btn ban" onclick="return confirm(<?php echo gc_confirm_js($ban_msg); ?>)"><i class="ri-user-forbid-line"></i> Ban</button>
            </form>
        </div>
        <?php else: ?>
        <span class="row-sub" style="font-style:italic;">Admin — owner only</span>
        <?php endif; ?>
    </div>
    <?php endwhile; else: ?>
    <p class="no-items">No manageable members right now.</p>
    <?php endif; ?>
</div>

<!-- ══ BANNED MEMBERS ══ -->
<?php if (!empty($visible_bans)): ?>
<div class="section-label"><i class="ri-user-forbid-line"></i> Banned Members <span class="badge-count"><?php echo count($visible_bans); ?></span></div>
<div class="card-list">
    <?php foreach ($visible_bans as $banned):
        $bp = !empty($banned['profile_pic']) ? $banned['profile_pic'] : 'uploads/me.jpeg';
        $is_owner_ban = isset($owner_bans[$banned['id']]);
        $is_group_ban = isset($group_ban_list[$banned['id']]);
        $can_unban = $is_owner || ($is_admin && $is_group_ban && !$is_owner_ban);
        $unban_msg = 'Unban '.$banned['username']."?\nThey can rejoin the group.";
    ?>
    <div class="row-item">
        <img class="dim" src="<?php echo htmlspecialchars($bp); ?>" onerror="this.src='uploads/me.jpeg'">
        <div class="row-info">
            <div class="row-name" style="color:rgba(255,255,255,.7);"><?php echo htmlspecialchars($banned['username']); ?></div>
            <div class="row-sub"><?php echo ($is_owner_ban && $is_owner) ? '⚡ Creator ban — all your groups' : '🔒 Group ban — this group only'; ?></div>
        </div>
        <?php if ($can_unban): ?>
        <form method="POST" action="group.php?id=<?php echo $group_id; ?>">
            <input type="hidden" name="unban_user_id" value="<?php echo (int)$banned['id']; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <button type="submit" name="unban_member" class="tiny-btn approve" onclick="return confirm(<?php echo gc_confirm_js($unban_msg); ?>)"><i class="ri-user-follow-line"></i> Unban</button>
        </form>
        <?php else: ?>
        <span class="row-sub" style="font-style:italic;">Creator ban only</span>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ══ DELETE GROUP ══ -->
<?php if ($is_owner): ?>
<div class="divider"><hr></div>
<div class="card-list" style="margin-top:18px;">
    <form method="POST" action="group.php?id=<?php echo $group_id; ?>" onsubmit="return confirm(<?php echo gc_confirm_js('⚠️ Delete this group forever? This cannot be undone.'); ?>)">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <button class="gc-btn danger wide" type="submit" name="delete_group"><i class="ri-delete-bin-line"></i> Delete Group Permanently</button>
    </form>
</div>
<?php endif; ?>

</body>
</html>