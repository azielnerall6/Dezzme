<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$uid = (int)$_SESSION['user_id'];

$u = $conn->prepare("SELECT username, nickname, profile_pic, user_rank FROM users WHERE id = ?");
$u->bind_param("i", $uid);
$u->execute();
$me = $u->get_result()->fetch_assoc();
$u->close();

$is_admin = ($me['user_rank'] ?? '') === 'Admin';

if (!$is_admin) {
    header('Location: feedback.php');
    exit;
}

$categories = [
    'bug'     => ['icon' => 'ri-bug-line',          'label' => 'Bug Report',       'color' => '#ff5757'],
    'feature' => ['icon' => 'ri-lightbulb-line',    'label' => 'Feature Request',  'color' => '#ffd166'],
    'general' => ['icon' => 'ri-chat-3-line',       'label' => 'General Feedback', 'color' => '#4cc9f0'],
    'abuse'   => ['icon' => 'ri-shield-cross-line', 'label' => 'Abuse Report',     'color' => '#ff8a8a'],
    'other'   => ['icon' => 'ri-more-line',         'label' => 'Other Suggestion', 'color' => '#a78bfa'],
];

$statuses = [
    'submitted'      => ['label' => 'Submitted',      'icon' => 'ri-send-plane-line',   'color' => '#9aa0ac'],
    'under_review'   => ['label' => 'Under Review',   'icon' => 'ri-eye-line',          'color' => '#4cc9f0'],
    'planned'        => ['label' => 'Planned',        'icon' => 'ri-road-map-line',     'color' => '#a78bfa'],
    'in_development' => ['label' => 'In Development', 'icon' => 'ri-code-s-slash-line', 'color' => '#ffd166'],
    'completed'      => ['label' => 'Completed',      'icon' => 'ri-check-double-line', 'color' => '#00cc6a'],
    'declined'       => ['label' => 'Declined',       'icon' => 'ri-close-circle-line', 'color' => '#FF4C4C'],
];

// ── STATS ────────────────────────────────────────────────────
$stats = [
    'total'     => 0,
    'submitted' => 0,
    'under_review' => 0,
    'planned' => 0,
    'in_development' => 0,
    'completed' => 0,
    'declined' => 0,
    'archived'  => 0,
];
$sres = $conn->query("SELECT status, is_archived, COUNT(*) c FROM feedback GROUP BY status, is_archived");
while ($row = $sres->fetch_assoc()) {
    $stats['total'] += (int)$row['c'];
    if ((int)$row['is_archived'] === 1) {
        $stats['archived'] += (int)$row['c'];
    } elseif (isset($stats[$row['status']])) {
        $stats[$row['status']] += (int)$row['c'];
    }
}

// ── FETCH ALL FEEDBACK ──────────────────────────────────────
$list_sql = "
    SELECT f.*, u.username, u.nickname, u.profile_pic, u.dz_id
    FROM feedback f
    JOIN users u ON f.user_id = u.id
    ORDER BY f.is_pinned DESC, f.created_at DESC
";
$all = $conn->query($list_sql)->fetch_all(MYSQLI_ASSOC);

// map of id -> title, for resolving "duplicate of" labels client-side without extra joins
$title_map = [];
foreach ($all as $row) {
    $title_map[(int)$row['id']] = $row['title'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Feedback Manager — Dezzme Admin</title>
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --red:     #FF4C4C;
    --surface: rgba(255,255,255,0.05);
    --surface2:rgba(255,255,255,0.03);
    --border:  rgba(255,255,255,0.10);
    --text:    #ffffff;
    --muted:   rgba(255,255,255,0.45);
    --divider: rgba(255,255,255,0.08);
    --r:       13px;
}

html { scroll-behavior: smooth; }

body {
    background: #000000;
    color: var(--text);
    font-family: ui-sans-serif, -apple-system, BlinkMacSystemFont, "Segoe UI", "Helvetica Neue", Arial, sans-serif;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 0 0 70px;
    overflow-x: hidden;
}

a { text-decoration: none; color: inherit; }
button { font-family: inherit; }

/* ── Top bar ── */
.topbar {
    width: 100%;
    max-width: 900px;
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 20px 16px 14px;
    position: sticky;
    top: 0;
    z-index: 100;
    background: #000000;
}
.back-btn {
    background: none;
    border: none;
    color: var(--text);
    font-size: 1.4rem;
    cursor: pointer;
    opacity: 0.75;
    padding: 0;
    display: flex;
    align-items: center;
    flex-shrink: 0;
    transition: opacity 0.2s, color 0.2s;
}
.back-btn:hover { opacity: 1; color: var(--red); }
.topbar-title { font-size: 1rem; font-weight: 600; flex: 1; }
.topbar-badge {
    display: flex; align-items: center; gap: 6px;
    padding: 5px 12px; border-radius: 20px;
    background: rgba(255,76,76,0.1); border: 1px solid rgba(255,76,76,0.3);
    font-size: 0.7rem; font-weight: 600; color: var(--red);
    flex-shrink: 0;
}

.page-main { width: 100%; max-width: 900px; padding: 6px 16px 0; }

/* ── Stats ── */
.stats-grid {
    display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px;
    margin-bottom: 20px;
}
.stat-card {
    padding: 13px 14px; border-radius: var(--r);
    background: var(--surface); border: 1px solid var(--border);
    cursor: pointer; transition: all 0.2s; text-align: left;
}
.stat-card:hover { border-color: rgba(255,255,255,0.2); }
.stat-card.active { background: rgba(255,76,76,0.1); border-color: rgba(255,76,76,0.4); }
.stat-num { font-size: 1.3rem; font-weight: 700; line-height: 1.1; }
.stat-label { font-size: 0.65rem; color: var(--muted); margin-top: 4px; text-transform: uppercase; letter-spacing: 0.03em; }

/* ── Filters ── */
.filters-row {
    display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; align-items: center;
}
.filter-select {
    padding: 9px 13px; border-radius: 10px;
    background: var(--surface); border: 1px solid var(--border);
    color: var(--text); font-size: 0.78rem; font-weight: 500;
    cursor: pointer; outline: none;
}
.filter-select:focus { border-color: rgba(255,76,76,0.4); }
.search-box {
    flex: 1; min-width: 140px;
    padding: 9px 13px; border-radius: 10px;
    background: var(--surface); border: 1px solid var(--border);
    color: var(--text); font-size: 0.78rem; outline: none;
}
.search-box::placeholder { color: var(--muted); }
.search-box:focus { border-color: rgba(255,76,76,0.4); }
.results-count { font-size: 0.7rem; color: var(--muted); white-space: nowrap; }

/* ── Feedback cards ── */
.fb-list { display: flex; flex-direction: column; gap: 10px; }
.fb-card {
    border-radius: var(--r);
    background: var(--surface); border: 1px solid var(--border);
    overflow: hidden; transition: border-color 0.2s;
}
.fb-card.is-pinned { border-color: rgba(255,76,76,0.35); }
.fb-card.is-archived { opacity: 0.5; }

.fb-head {
    display: flex; align-items: center; gap: 12px;
    padding: 14px; cursor: pointer; width: 100%;
    background: none; border: none; color: inherit; text-align: left;
}
.fb-icon {
    width: 38px; height: 38px; border-radius: 10px;
    background: var(--surface2); border: 1px solid var(--border);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.05rem; flex-shrink: 0;
}
.fb-head-info { flex: 1; min-width: 0; }
.fb-head-title { font-size: 0.86rem; font-weight: 600; display: flex; align-items: center; gap: 6px; }
.fb-head-title .pin-flag { color: var(--red); font-size: 0.85rem; flex-shrink: 0; }
.fb-head-title span.txt { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.fb-head-meta { font-size: 0.7rem; color: var(--muted); margin-top: 4px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.fb-head-meta img { width: 16px; height: 16px; border-radius: 50%; object-fit: cover; }
.fb-head-right { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.fb-chevron { font-size: 1.1rem; color: var(--muted); transition: transform 0.25s; }
.fb-card.open .fb-chevron { transform: rotate(180deg); }

.status-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 10px; border-radius: 14px;
    font-size: 0.62rem; font-weight: 600;
    border: 1px solid; white-space: nowrap; flex-shrink: 0;
}
.status-badge i { font-size: 0.76rem; }

.fb-body { display: none; padding: 0 14px 16px; border-top: 1px solid var(--divider); }
.fb-card.open .fb-body { display: block; padding-top: 14px; }

.fb-message { font-size: 0.8rem; color: rgba(255,255,255,0.8); line-height: 1.6; margin-bottom: 12px; white-space: pre-wrap; }
.fb-shot { width: 100%; max-width: 340px; border-radius: 10px; border: 1px solid var(--border); display: block; margin-bottom: 14px; cursor: pointer; }

.fb-user-row {
    display: flex; align-items: center; gap: 10px; margin-bottom: 14px;
    padding: 10px 12px; background: var(--surface2); border-radius: 10px; border: 1px solid var(--border);
}
.fb-user-row img { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; }
.fb-user-name { font-size: 0.78rem; font-weight: 600; }
.fb-user-sub { font-size: 0.66rem; color: var(--muted); margin-top: 1px; }
.fb-user-followup { margin-left: auto; font-size: 0.64rem; color: var(--muted); display: flex; align-items: center; gap: 4px; }
.fb-user-followup.on { color: #3ddc97; }

.control-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 12px; }
.control-block label {
    display: block; font-size: 0.62rem; font-weight: 600;
    text-transform: uppercase; letter-spacing: 0.05em;
    color: var(--muted); margin-bottom: 6px;
}
.control-select, .control-input, .control-textarea {
    width: 100%; padding: 9px 11px;
    background: var(--surface2); border: 1px solid var(--border);
    border-radius: 9px; outline: none; color: var(--text);
    font-size: 0.76rem; font-family: inherit;
}
.control-select:focus, .control-input:focus, .control-textarea:focus { border-color: rgba(255,76,76,0.4); }
.control-textarea { resize: vertical; min-height: 64px; line-height: 1.5; }

.action-row { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
.mini-btn {
    display: flex; align-items: center; gap: 6px;
    padding: 8px 13px; border-radius: 9px;
    background: var(--surface2); border: 1px solid var(--border);
    font-size: 0.72rem; font-weight: 600; color: var(--text);
    cursor: pointer; transition: all 0.18s;
}
.mini-btn i { font-size: 0.9rem; }
.mini-btn:hover { border-color: rgba(255,255,255,0.25); }
.mini-btn.primary { background: linear-gradient(135deg, var(--red), #c0141e); border: none; box-shadow: 0 4px 14px rgba(255,76,76,0.25); }
.mini-btn.primary:hover { transform: translateY(-1px); }
.mini-btn.on { background: rgba(255,76,76,0.14); border-color: rgba(255,76,76,0.4); color: var(--red); }
.mini-btn.danger:hover { border-color: rgba(255,76,76,0.4); color: var(--red); }
.mini-btn:disabled { opacity: 0.5; cursor: default; }

.save-status { font-size: 0.66rem; color: #3ddc97; margin-left: 4px; opacity: 0; transition: opacity 0.3s; }
.save-status.show { opacity: 1; }

/* ── Empty state ── */
.empty-state { width: 100%; text-align: center; padding: 60px 20px; color: var(--muted); }
.empty-state i { font-size: 2.2rem; display: block; margin-bottom: 12px; opacity: 0.35; }
.empty-state p { font-size: 0.85rem; line-height: 1.6; }

/* ── Lightbox ── */
.lightbox {
    display: none; position: fixed; inset: 0; z-index: 999;
    background: rgba(0,0,0,0.9); align-items: center; justify-content: center; padding: 24px;
}
.lightbox.open { display: flex; }
.lightbox img { max-width: 100%; max-height: 90vh; border-radius: 10px; }

/* ── Scroll top ── */
.scroll-top {
    position: fixed; bottom: 24px; right: 18px; z-index: 200;
    width: 40px; height: 40px; border-radius: 50%;
    background: rgba(255,76,76,0.12); border: 1px solid rgba(255,76,76,0.3);
    color: var(--red); font-size: 1.2rem;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; opacity: 0; pointer-events: none;
    transition: opacity 0.3s, transform 0.3s;
}
.scroll-top.visible { opacity: 1; pointer-events: all; }
.scroll-top:hover { transform: translateY(-3px); }

/* ════════════════════════════════════════════════════════
   RESPONSIVE BREAKPOINTS — 790 / 490 / 390 / 330
   ════════════════════════════════════════════════════════ */

@media (max-width: 790px) {
    body { padding-bottom: 60px; }
    .topbar { padding: 17px 14px 12px; gap: 12px; }
    .topbar-title { font-size: 0.95rem; }
    .topbar-badge { font-size: 0.66rem; padding: 4px 10px; }

    .page-main { padding: 5px 14px 0; }
    .stats-grid { gap: 7px; margin-bottom: 18px; }
    .stat-card { padding: 11px 12px; }
    .stat-num { font-size: 1.15rem; }
    .stat-label { font-size: 0.6rem; }

    .filters-row { gap: 7px; margin-bottom: 14px; }
    .filter-select, .search-box { padding: 8px 12px; font-size: 0.74rem; }

    .fb-list { gap: 9px; }
    .fb-head { padding: 12px; gap: 10px; }
    .fb-icon { width: 35px; height: 35px; font-size: 0.98rem; }
    .fb-head-title { font-size: 0.8rem; }
    .fb-head-meta { font-size: 0.66rem; }
    .status-badge { font-size: 0.6rem; padding: 2px 8px; }

    .fb-body { padding: 0 12px 14px; }
    .fb-card.open .fb-body { padding-top: 12px; }
    .fb-message { font-size: 0.76rem; }
    .control-grid { gap: 8px; }
    .mini-btn { padding: 7px 12px; font-size: 0.68rem; }

    .scroll-top { width: 37px; height: 37px; bottom: 20px; right: 16px; }
}

@media (max-width: 490px) {
    body { padding-bottom: 52px; }
    .topbar { padding: 14px 12px 10px; gap: 10px; }
    .topbar-title { font-size: 0.88rem; }
    .back-btn { font-size: 1.25rem; }
    .topbar-badge { font-size: 0.6rem; padding: 3px 9px; gap: 4px; }
    .topbar-badge span { display: none; }

    .page-main { padding: 4px 12px 0; }
    .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 6px; margin-bottom: 16px; }
    .stat-card { padding: 10px 11px; border-radius: 11px; }
    .stat-num { font-size: 1.1rem; }
    .stat-label { font-size: 0.58rem; margin-top: 3px; }

    .filters-row { gap: 6px; margin-bottom: 12px; }
    .filter-select, .search-box { padding: 7px 11px; font-size: 0.7rem; border-radius: 9px; }
    .results-count { font-size: 0.64rem; width: 100%; }

    .fb-list { gap: 8px; }
    .fb-head { padding: 11px; gap: 9px; }
    .fb-icon { width: 32px; height: 32px; border-radius: 9px; font-size: 0.9rem; }
    .fb-head-title { font-size: 0.76rem; gap: 5px; }
    .fb-head-meta { font-size: 0.62rem; gap: 5px; }
    .fb-head-meta img { width: 14px; height: 14px; }
    .fb-chevron { font-size: 1rem; }
    .status-badge { font-size: 0.56rem; padding: 2px 7px; }
    .status-badge i { font-size: 0.7rem; }

    .fb-body { padding: 0 11px 13px; }
    .fb-card.open .fb-body { padding-top: 11px; }
    .fb-message { font-size: 0.72rem; margin-bottom: 10px; }
    .fb-shot { margin-bottom: 12px; }
    .fb-user-row { padding: 9px 10px; gap: 8px; margin-bottom: 12px; }
    .fb-user-row img { width: 28px; height: 28px; }
    .fb-user-name { font-size: 0.72rem; }
    .fb-user-sub { font-size: 0.6rem; }
    .fb-user-followup { font-size: 0.58rem; }

    .control-grid { grid-template-columns: 1fr; gap: 8px; margin-bottom: 10px; }
    .control-block label { font-size: 0.58rem; margin-bottom: 5px; }
    .control-select, .control-input, .control-textarea { padding: 8px 10px; font-size: 0.72rem; border-radius: 8px; }
    .control-textarea { min-height: 56px; }

    .action-row { gap: 6px; margin-top: 8px; }
    .mini-btn { padding: 7px 11px; font-size: 0.64rem; gap: 5px; border-radius: 8px; }
    .mini-btn i { font-size: 0.82rem; }
    .save-status { font-size: 0.6rem; }

    .empty-state { padding: 44px 16px; }
    .empty-state i { font-size: 1.9rem; margin-bottom: 10px; }
    .empty-state p { font-size: 0.78rem; }

    .scroll-top { width: 34px; height: 34px; bottom: 16px; right: 14px; font-size: 1.05rem; }
}

@media (max-width: 390px) {
    body { padding-bottom: 46px; }
    .topbar { padding: 12px 10px 9px; gap: 8px; }
    .topbar-title { font-size: 0.82rem; }
    .back-btn { font-size: 1.15rem; }
    .topbar-badge { width: 26px; height: 26px; padding: 0; justify-content: center; }

    .page-main { padding: 4px 10px 0; }
    .stats-grid { gap: 5px; margin-bottom: 14px; }
    .stat-card { padding: 9px 10px; border-radius: 10px; }
    .stat-num { font-size: 1rem; }
    .stat-label { font-size: 0.54rem; margin-top: 2px; }

    .filters-row { gap: 5px; margin-bottom: 10px; }
    .filter-select, .search-box { padding: 7px 10px; font-size: 0.66rem; }

    .fb-list { gap: 7px; }
    .fb-head { padding: 9px; gap: 8px; }
    .fb-icon { width: 28px; height: 28px; border-radius: 8px; font-size: 0.82rem; }
    .fb-head-title { font-size: 0.7rem; }
    .fb-head-meta { font-size: 0.58rem; }
    .status-badge { font-size: 0.52rem; padding: 2px 6px; }
    .status-badge i { font-size: 0.64rem; }

    .fb-body { padding: 0 9px 11px; }
    .fb-card.open .fb-body { padding-top: 10px; }
    .fb-message { font-size: 0.68rem; line-height: 1.5; }
    .fb-user-row { padding: 8px 9px; }
    .fb-user-row img { width: 25px; height: 25px; }
    .fb-user-name { font-size: 0.68rem; }
    .fb-user-sub { font-size: 0.56rem; }

    .control-select, .control-input, .control-textarea { padding: 7px 9px; font-size: 0.68rem; }
    .control-textarea { min-height: 50px; }
    .mini-btn { padding: 6px 10px; font-size: 0.6rem; }

    .empty-state { padding: 36px 12px; }
    .empty-state i { font-size: 1.7rem; margin-bottom: 9px; }
    .empty-state p { font-size: 0.7rem; max-width: 220px; margin: 0 auto; }

    .scroll-top { width: 31px; height: 31px; bottom: 12px; right: 12px; font-size: 0.95rem; }
}

@media (max-width: 330px) {
    body { padding-bottom: 40px; }
    .topbar { padding: 10px 8px 8px; gap: 6px; }
    .topbar-title { font-size: 0.76rem; }
    .back-btn { font-size: 1.05rem; }

    .page-main { padding: 3px 8px 0; }
    .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 4px; margin-bottom: 12px; }
    .stat-card { padding: 8px 9px; border-radius: 9px; }
    .stat-num { font-size: 0.92rem; }
    .stat-label { font-size: 0.5rem; }

    .filters-row { gap: 4px; margin-bottom: 9px; }
    .filter-select, .search-box { padding: 6px 9px; font-size: 0.62rem; border-radius: 8px; }

    .fb-list { gap: 6px; }
    .fb-head { padding: 8px; gap: 7px; }
    .fb-icon { width: 25px; height: 25px; border-radius: 7px; font-size: 0.74rem; }
    .fb-head-title { font-size: 0.64rem; }
    .fb-head-meta { font-size: 0.54rem; gap: 4px; }
    .fb-head-meta img { width: 12px; height: 12px; }
    .status-badge { font-size: 0.48rem; padding: 1px 6px; }

    .fb-body { padding: 0 8px 10px; }
    .fb-message { font-size: 0.62rem; }
    .fb-user-name { font-size: 0.62rem; }
    .fb-user-sub { font-size: 0.52rem; }
    .fb-user-followup { font-size: 0.52rem; }

    .control-block label { font-size: 0.54rem; }
    .control-select, .control-input, .control-textarea { padding: 6px 8px; font-size: 0.62rem; }
    .control-textarea { min-height: 44px; }
    .mini-btn { padding: 6px 9px; font-size: 0.56rem; gap: 4px; }
    .mini-btn i { font-size: 0.74rem; }

    .empty-state { padding: 28px 10px; }
    .empty-state i { font-size: 1.5rem; margin-bottom: 7px; }
    .empty-state p { font-size: 0.64rem; max-width: 190px; }

    .scroll-top { width: 28px; height: 28px; bottom: 10px; right: 10px; font-size: 0.85rem; }
}
</style>
</head>
<body>

<header class="topbar">
    <button class="back-btn" onclick="history.back()" aria-label="Go back"><i class="ri-arrow-left-s-line"></i></button>
    <span class="topbar-title">Feedback Manager</span>
    <div class="topbar-badge"><i class="ri-shield-star-line"></i> <span>Admin</span></div>
</header>

<main class="page-main">

    <div class="stats-grid" id="statsGrid">
        <button class="stat-card active" data-status="all">
            <div class="stat-num"><?php echo (int)$stats['total']; ?></div>
            <div class="stat-label">All</div>
        </button>
        <?php foreach ($statuses as $key => $st): ?>
        <button class="stat-card" data-status="<?php echo $key; ?>">
            <div class="stat-num" style="color:<?php echo $st['color']; ?>"><?php echo (int)$stats[$key]; ?></div>
            <div class="stat-label"><?php echo $st['label']; ?></div>
        </button>
        <?php endforeach; ?>
    </div>

    <div class="filters-row">
        <select class="filter-select" id="categoryFilter">
            <option value="all">All Categories</option>
            <?php foreach ($categories as $val => $cat): ?>
            <option value="<?php echo $val; ?>"><?php echo $cat['label']; ?></option>
            <?php endforeach; ?>
        </select>
        <select class="filter-select" id="archiveFilter">
            <option value="active">Active</option>
            <option value="archived">Archived</option>
            <option value="everything">Everything</option>
        </select>
        <input type="text" class="search-box" id="searchBox" placeholder="Search title, message, username...">
        <span class="results-count" id="resultsCount"></span>
    </div>

    <div class="fb-list" id="fbList">
        <?php if (empty($all)): ?>
        <div class="empty-state">
            <i class="ri-inbox-line"></i>
            <p>No feedback has been submitted yet.</p>
        </div>
        <?php else: ?>
        <?php foreach ($all as $fb):
            $cat = $categories[$fb['category']] ?? $categories['other'];
            $st  = $statuses[$fb['status']] ?? $statuses['submitted'];
            $dup_title = $fb['is_duplicate_of'] ? ($title_map[(int)$fb['is_duplicate_of']] ?? ('#' . $fb['is_duplicate_of'])) : '';
        ?>
        <div class="fb-card <?php echo $fb['is_pinned'] ? 'is-pinned' : ''; ?> <?php echo $fb['is_archived'] ? 'is-archived' : ''; ?>"
             data-id="<?php echo (int)$fb['id']; ?>"
             data-status="<?php echo htmlspecialchars($fb['status']); ?>"
             data-category="<?php echo htmlspecialchars($fb['category']); ?>"
             data-archived="<?php echo (int)$fb['is_archived']; ?>"
             data-search="<?php echo htmlspecialchars(strtolower($fb['title'] . ' ' . $fb['message'] . ' ' . $fb['username'] . ' ' . ($fb['nickname'] ?? ''))); ?>">

            <button class="fb-head" onclick="this.closest('.fb-card').classList.toggle('open')">
                <div class="fb-icon"><i class="<?php echo $cat['icon']; ?>" style="color:<?php echo $cat['color']; ?>"></i></div>
                <div class="fb-head-info">
                    <div class="fb-head-title">
                        <?php if ($fb['is_pinned']): ?><i class="ri-pushpin-2-fill pin-flag"></i><?php endif; ?>
                        <span class="txt"><?php echo htmlspecialchars($fb['title']); ?></span>
                    </div>
                    <div class="fb-head-meta">
                        <img src="<?php echo htmlspecialchars($fb['profile_pic'] ?? 'uploads/me.png'); ?>" onerror="this.src='uploads/me.png'" alt="">
                        @<?php echo htmlspecialchars($fb['username']); ?> · <?php echo $cat['label']; ?> · <?php echo date('M j, Y', strtotime($fb['created_at'])); ?>
                        <?php if ($fb['category'] === 'feature'): ?> · <i class="ri-arrow-up-line"></i> <?php echo (int)$fb['vote_count']; ?><?php endif; ?>
                        <?php if ($fb['is_archived']): ?> · <span style="color:var(--red)">Archived</span><?php endif; ?>
                    </div>
                </div>
                <div class="fb-head-right">
                    <span class="status-badge" style="color:<?php echo $st['color']; ?>;border-color:<?php echo $st['color']; ?>40;background:<?php echo $st['color']; ?>1a;">
                        <i class="<?php echo $st['icon']; ?>"></i><?php echo $st['label']; ?>
                    </span>
                    <i class="ri-arrow-down-s-line fb-chevron"></i>
                </div>
            </button>

            <div class="fb-body">

                <div class="fb-user-row">
                    <img src="<?php echo htmlspecialchars($fb['profile_pic'] ?? 'uploads/me.png'); ?>" onerror="this.src='uploads/me.png'" alt="">
                    <div>
                        <div class="fb-user-name">@<?php echo htmlspecialchars($fb['username']); ?><?php echo $fb['nickname'] ? ' · ' . htmlspecialchars($fb['nickname']) : ''; ?></div>
                        <div class="fb-user-sub"><?php echo htmlspecialchars($fb['dz_id'] ?? ''); ?></div>
                    </div>
                    <div class="fb-user-followup <?php echo $fb['allow_followup'] ? 'on' : ''; ?>">
                        <i class="<?php echo $fb['allow_followup'] ? 'ri-checkbox-circle-fill' : 'ri-close-circle-line'; ?>"></i>
                        <?php echo $fb['allow_followup'] ? 'Follow-up OK' : 'No follow-up'; ?>
                    </div>
                </div>

                <div class="fb-message"><?php echo htmlspecialchars($fb['message']); ?></div>

                <?php if (!empty($fb['screenshot'])): ?>
                <img class="fb-shot" src="<?php echo htmlspecialchars($fb['screenshot']); ?>" alt="Screenshot" onclick="openLightbox('<?php echo htmlspecialchars($fb['screenshot']); ?>')">
                <?php endif; ?>

                <div class="control-grid">
                    <div class="control-block">
                        <label>Status</label>
                        <select class="control-select status-select">
                            <?php foreach ($statuses as $key => $st2): ?>
                            <option value="<?php echo $key; ?>" <?php echo $fb['status'] === $key ? 'selected' : ''; ?>><?php echo $st2['label']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="control-block">
                        <label>Mark as duplicate of (ID)</label>
                        <input type="number" class="control-input duplicate-input" placeholder="e.g. 12" value="<?php echo $fb['is_duplicate_of'] ? (int)$fb['is_duplicate_of'] : ''; ?>">
                    </div>
                </div>

                <div class="control-block" style="margin-bottom:12px;">
                    <label>Admin notes <span class="save-status">Saved</span></label>
                    <textarea class="control-textarea notes-input" placeholder="Internal notes, not visible to the user..."><?php echo htmlspecialchars($fb['admin_notes'] ?? ''); ?></textarea>
                </div>

                <div class="action-row">
                    <button class="mini-btn primary save-btn"><i class="ri-save-line"></i> Save Changes</button>
                    <button class="mini-btn pin-btn <?php echo $fb['is_pinned'] ? 'on' : ''; ?>">
                        <i class="ri-pushpin-2-<?php echo $fb['is_pinned'] ? 'fill' : 'line'; ?>"></i> <?php echo $fb['is_pinned'] ? 'Pinned' : 'Pin'; ?>
                    </button>
                    <button class="mini-btn archive-btn <?php echo $fb['is_archived'] ? 'on' : ''; ?>">
                        <i class="ri-archive-<?php echo $fb['is_archived'] ? 'fill' : 'line'; ?>"></i> <?php echo $fb['is_archived'] ? 'Archived' : 'Archive'; ?>
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="empty-state" id="noResults" style="display:none;">
        <i class="ri-search-line"></i>
        <p>No feedback matches your filters.</p>
    </div>

</main>

<div class="lightbox" id="lightbox" onclick="this.classList.remove('open')">
    <img id="lightboxImg" src="" alt="Screenshot">
</div>

<button class="scroll-top" id="scrollTop" onclick="window.scrollTo({top:0,behavior:'smooth'})">
    <i class="ri-arrow-up-line"></i>
</button>

<script>
const statusLabels = <?php echo json_encode(array_map(fn($s) => $s['label'], $statuses)); ?>;
const statusColors = <?php echo json_encode(array_map(fn($s) => $s['color'], $statuses)); ?>;
const statusIcons  = <?php echo json_encode(array_map(fn($s) => $s['icon'], $statuses)); ?>;

// ── FILTERING ────────────────────────────────────────────────
let currentStatus = 'all';
const categoryFilter = document.getElementById('categoryFilter');
const archiveFilter = document.getElementById('archiveFilter');
const searchBox = document.getElementById('searchBox');
const resultsCount = document.getElementById('resultsCount');
const noResults = document.getElementById('noResults');

document.querySelectorAll('.stat-card').forEach(card => {
    card.addEventListener('click', () => {
        document.querySelectorAll('.stat-card').forEach(c => c.classList.remove('active'));
        card.classList.add('active');
        currentStatus = card.dataset.status;
        applyFilters();
    });
});
categoryFilter.addEventListener('change', applyFilters);
archiveFilter.addEventListener('change', applyFilters);
searchBox.addEventListener('input', applyFilters);

function applyFilters() {
    const cat = categoryFilter.value;
    const arch = archiveFilter.value;
    const q = searchBox.value.trim().toLowerCase();
    let visible = 0;

    document.querySelectorAll('.fb-card').forEach(card => {
        const matchStatus = currentStatus === 'all' || card.dataset.status === currentStatus;
        const matchCat = cat === 'all' || card.dataset.category === cat;
        const isArchived = card.dataset.archived === '1';
        const matchArch = arch === 'everything' || (arch === 'archived' && isArchived) || (arch === 'active' && !isArchived);
        const matchSearch = !q || card.dataset.search.includes(q);
        const show = matchStatus && matchCat && matchArch && matchSearch;
        card.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    resultsCount.textContent = visible + (visible === 1 ? ' result' : ' results');
    noResults.style.display = visible === 0 ? 'block' : 'none';
}
applyFilters();

// ── LIGHTBOX ─────────────────────────────────────────────────
function openLightbox(src) {
    document.getElementById('lightboxImg').src = src;
    document.getElementById('lightbox').classList.add('open');
}

// ── SAVE / ACTIONS ───────────────────────────────────────────
async function postAction(payload) {
    const res = await fetch('feedback_actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(payload).toString()
    });
    return res.json();
}

document.querySelectorAll('.fb-card').forEach(card => {
    const id = card.dataset.id;
    const saveBtn = card.querySelector('.save-btn');
    const pinBtn = card.querySelector('.pin-btn');
    const archiveBtn = card.querySelector('.archive-btn');
    const statusSelect = card.querySelector('.status-select');
    const notesInput = card.querySelector('.notes-input');
    const dupInput = card.querySelector('.duplicate-input');
    const saveStatusEl = card.querySelector('.save-status');
    const headBadge = card.querySelector('.fb-head-right .status-badge');

    saveBtn.addEventListener('click', async (e) => {
        e.stopPropagation();
        saveBtn.disabled = true;
        try {
            const newStatus = statusSelect.value;
            const results = await Promise.all([
                postAction({ action: 'update_status', feedback_id: id, status: newStatus }),
                postAction({ action: 'save_notes', feedback_id: id, notes: notesInput.value }),
                postAction({ action: 'mark_duplicate', feedback_id: id, duplicate_of: dupInput.value })
            ]);

            const statusRes = results[0];
            if (statusRes.success) {
                card.dataset.status = statusRes.status;
                if (headBadge) {
                    headBadge.style.color = statusColors[statusRes.status];
                    headBadge.style.borderColor = statusColors[statusRes.status] + '40';
                    headBadge.style.background = statusColors[statusRes.status] + '1a';
                    headBadge.innerHTML = '<i class="' + statusIcons[statusRes.status] + '"></i>' + statusRes.status_label;
                }
            }

            saveStatusEl.classList.add('show');
            setTimeout(() => saveStatusEl.classList.remove('show'), 2000);
        } catch (err) {
            alert('Something went wrong saving changes. Please try again.');
        }
        saveBtn.disabled = false;
    });

    pinBtn.addEventListener('click', async (e) => {
        e.stopPropagation();
        pinBtn.disabled = true;
        try {
            const res = await postAction({ action: 'toggle_pin', feedback_id: id });
            if (res.success) {
                card.classList.toggle('is-pinned', res.pinned);
                pinBtn.classList.toggle('on', res.pinned);
                pinBtn.innerHTML = res.pinned
                    ? '<i class="ri-pushpin-2-fill"></i> Pinned'
                    : '<i class="ri-pushpin-2-line"></i> Pin';
                const flag = card.querySelector('.pin-flag');
                const titleTxt = card.querySelector('.fb-head-title');
                if (res.pinned && !flag) {
                    titleTxt.insertAdjacentHTML('afterbegin', '<i class="ri-pushpin-2-fill pin-flag"></i>');
                } else if (!res.pinned && flag) {
                    flag.remove();
                }
            }
        } catch (err) { /* silent */ }
        pinBtn.disabled = false;
    });

    archiveBtn.addEventListener('click', async (e) => {
        e.stopPropagation();
        archiveBtn.disabled = true;
        try {
            const res = await postAction({ action: 'toggle_archive', feedback_id: id });
            if (res.success) {
                card.dataset.archived = res.archived ? '1' : '0';
                card.classList.toggle('is-archived', res.archived);
                archiveBtn.classList.toggle('on', res.archived);
                archiveBtn.innerHTML = res.archived
                    ? '<i class="ri-archive-fill"></i> Archived'
                    : '<i class="ri-archive-line"></i> Archive';
                applyFilters();
            }
        } catch (err) { /* silent */ }
        archiveBtn.disabled = false;
    });
});

// ── SCROLL TOP ───────────────────────────────────────────────
window.addEventListener('scroll', () => {
    document.getElementById('scrollTop').classList.toggle('visible', window.scrollY > 300);
});
</script>
</body>
</html>