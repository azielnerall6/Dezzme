<?php
session_start();
include 'config.php';

$current_user = $_SESSION['user_id'] ?? 0;
if (!$current_user) {
    header("Location: login.php");
    exit();
}

/* Where the Settings page lives (used by the "Change in settings" link shown to the owner) */
const GL_SETTINGS_URL = 'setting.php';
/* true  = Limited / Private / Full Private profiles also hide the Pods list from people who do not
           track them (same rule as the Trackers + Tracking lists on tracks.php).
   false = only the Pods list setting (Everyone / My trackers / Only me + limits) decides. */
const GL_LEVEL_LOCKS = true;

/* ══════════════════════════════════════════════════════════════
   CSRF PROTECTION (same system as tracks.php)
   • One random token per session, embedded in the page (<meta name="csrf-token">).
   • EVERY state-changing request (Join / Leave here, Pin in toggle_pin_group.php) must send it
     back as the X-CSRF-Token header or the csrf_token field.
   • Compared with hash_equals() (timing-safe).
   • Second layer: when the browser sends an Origin / Referer header it must be this same host.
   • A failed check answers 403 and changes nothing.
══════════════════════════════════════════════════════════════ */
if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

function csrf_same_origin(): bool {
    $host = strtolower((string)parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $h) {
        if (!empty($_SERVER[$h])) {
            $src = strtolower((string)parse_url($_SERVER[$h], PHP_URL_HOST));
            return ($src !== '' && $src === $host);     // first header present decides
        }
    }
    return true;   // some browsers/privacy tools send neither — the token still has to match
}
function csrf_verify(): bool {
    $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || $sent === '') return false;
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $sent)) return false;
    return csrf_same_origin();
}
function csrf_fail(): void {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'csrf']);
    exit();
}

// ══════════════════════════════════════════════════════════
// AJAX: JOIN POD (handled directly on this page)
// ══════════════════════════════════════════════════════════
if (isset($_POST['ajax_join_group'])) {
    if (!csrf_verify()) { csrf_fail(); }     // 🔒 CSRF check
    header('Content-Type: application/json');

    $join_group_id = (int)($_POST['group_id'] ?? 0);
    if (!$join_group_id) {
        echo json_encode(['success' => false, 'error' => 'invalid_group']);
        exit();
    }

    $g_stmt = $conn->prepare("SELECT id, created_by FROM `groups` WHERE id = ?");
    $g_stmt->bind_param("i", $join_group_id);
    $g_stmt->execute();
    $join_group = $g_stmt->get_result()->fetch_assoc();
    $g_stmt->close();

    if (!$join_group) {
        echo json_encode(['success' => false, 'error' => 'not_found']);
        exit();
    }

    $owner_id = (int)$join_group['created_by'];

    // Blocked by pod owner (sitewide block)
    $b_stmt = $conn->prepare("SELECT 1 FROM blocks WHERE blocker_id = ? AND blocked_id = ?");
    $b_stmt->bind_param("ii", $owner_id, $current_user);
    $b_stmt->execute();
    $owner_blocked = $b_stmt->get_result()->num_rows > 0;
    $b_stmt->close();

    // Group-scoped ban
    // NOTE: table group_bans must already exist — run the setup SQL once (see setup.php)
    $gb_stmt = $conn->prepare("SELECT 1 FROM group_bans WHERE group_id = ? AND banned_id = ?");
    $gb_stmt->bind_param("ii", $join_group_id, $current_user);
    $gb_stmt->execute();
    $group_banned = $gb_stmt->get_result()->num_rows > 0;
    $gb_stmt->close();

    if ($owner_blocked || $group_banned) {
        echo json_encode(['success' => false, 'error' => 'blocked']);
        exit();
    }

    // Already a member?
    $m_stmt = $conn->prepare("SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ?");
    $m_stmt->bind_param("ii", $join_group_id, $current_user);
    $m_stmt->execute();
    $already_member = $m_stmt->get_result()->num_rows > 0;
    $m_stmt->close();

    if (!$already_member) {
        $ins_stmt = $conn->prepare("INSERT INTO group_members (group_id, user_id, joined_at) VALUES (?, ?, NOW())");
        $ins_stmt->bind_param("ii", $join_group_id, $current_user);
        $ins_stmt->execute();
        $ins_stmt->close();
    }

    $c_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM group_members WHERE group_id = ?");
    $c_stmt->bind_param("i", $join_group_id);
    $c_stmt->execute();
    $join_member_count = (int)$c_stmt->get_result()->fetch_assoc()['c'];
    $c_stmt->close();

    echo json_encode([
        'success'      => true,
        'member_count' => $join_member_count,
        'is_member'    => true,
    ]);
    exit();
}

// ══════════════════════════════════════════════════════════
// AJAX: LEAVE POD (handled directly on this page)
// ══════════════════════════════════════════════════════════
if (isset($_POST['ajax_leave_group'])) {
    if (!csrf_verify()) { csrf_fail(); }     // 🔒 CSRF check
    header('Content-Type: application/json');

    $leave_group_id = (int)($_POST['group_id'] ?? 0);
    if (!$leave_group_id) {
        echo json_encode(['success' => false, 'error' => 'invalid_group']);
        exit();
    }

    $g_stmt = $conn->prepare("SELECT id FROM `groups` WHERE id = ?");
    $g_stmt->bind_param("i", $leave_group_id);
    $g_stmt->execute();
    $leave_group = $g_stmt->get_result()->fetch_assoc();
    $g_stmt->close();

    if (!$leave_group) {
        echo json_encode(['success' => false, 'error' => 'not_found']);
        exit();
    }

    $del_stmt = $conn->prepare("DELETE FROM group_members WHERE group_id = ? AND user_id = ?");
    $del_stmt->bind_param("ii", $leave_group_id, $current_user);
    $del_stmt->execute();
    $del_stmt->close();

    // Drop any pin the user had on this pod
    // NOTE: table group_pins must already exist — run the setup SQL once (see setup.php)
    $unpin_stmt = $conn->prepare("DELETE FROM group_pins WHERE group_id = ? AND user_id = ?");
    $unpin_stmt->bind_param("ii", $leave_group_id, $current_user);
    $unpin_stmt->execute();
    $unpin_stmt->close();

    $c_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM group_members WHERE group_id = ?");
    $c_stmt->bind_param("i", $leave_group_id);
    $c_stmt->execute();
    $leave_member_count = (int)$c_stmt->get_result()->fetch_assoc()['c'];
    $c_stmt->close();

    echo json_encode([
        'success'      => true,
        'member_count' => $leave_member_count,
        'is_member'    => false,
    ]);
    exit();
}

// Any other POST that reaches this page is not valid
if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_fail(); }

$profile_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : $current_user;
$is_own     = ($profile_id === $current_user);

// ── User info ──────────────────────────────────────────────
$stmt = $conn->prepare("SELECT id, username, profile_pic FROM users WHERE id = ?");
$stmt->bind_param("i", $profile_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
if (!$user) { echo "User not found."; exit(); }

/* ══════════════════════════════════════════════════════════════
   PRIVACY — reads what the person saved on the Settings page
   (user_privacy + user_settings.is_fully_private) for the PODS list
══════════════════════════════════════════════════════════════ */
const GL_LEVELS = ['public', 'limited', 'private', 'full'];
const GL_LEVEL_NAMES = ['public' => 'Public', 'limited' => 'Limited', 'private' => 'Private', 'full' => 'Full Private'];

function gl_count($conn, $sql) {
    try { $r = $conn->query($sql); if ($r instanceof mysqli_result) { $row = $r->fetch_row(); return (int)($row[0] ?? 0); } } catch (Throwable $e) {}
    return 0;
}

function gl_privacy($conn, int $uid): array {
    $p = ['level' => 'public', 'show_pods' => 'everyone', 'lim_pods_trk' => 0, 'lim_pods_non' => 0];
    $stored = null; $flag = false;
    try {
        $r = $conn->query("SELECT * FROM user_privacy WHERE user_id=$uid LIMIT 1");
        if ($r instanceof mysqli_result && ($row = $r->fetch_assoc())) {
            $stored = $row['privacy_level'] ?? null;
            if (isset($row['show_pods']) && in_array($row['show_pods'], ['everyone', 'trackers', 'me'], true)) $p['show_pods'] = $row['show_pods'];
            foreach (['lim_pods_trk', 'lim_pods_non'] as $k) if (isset($row[$k])) $p[$k] = max(-1, (int)$row[$k]);
        }
    } catch (Throwable $e) { /* table not created yet: defaults */ }
    try {
        $r = $conn->query("SELECT is_fully_private FROM user_settings WHERE user_id=$uid LIMIT 1");
        if ($r instanceof mysqli_result && ($row = $r->fetch_assoc())) $flag = !empty($row['is_fully_private']);
    } catch (Throwable $e) {}
    // same reconciliation as the Settings page
    $lv = in_array($stored, GL_LEVELS, true) ? $stored : null;
    if ($flag && !in_array($lv, ['private', 'full'], true)) $lv = 'full';
    if (!$flag && in_array($lv, ['private', 'full'], true)) $lv = 'public';
    $p['level'] = $lv ?: ($flag ? 'full' : 'public');
    return $p;
}

/* What may ONE viewer see of the Pods list?
   returns ['open'=>bool, 'reason'=>'me'|'level'|'trackers'|'group'|'', 'limit'=>int (0 = no limit)] */
function gl_resolve(string $vis, bool $level_lock, bool $is_owner, bool $is_tracker, int $lim_trk, int $lim_non): array {
    if ($is_owner)                              return ['open' => true,  'reason' => '',         'limit' => 0];
    if ($vis === 'me')                          return ['open' => false, 'reason' => 'me',       'limit' => 0];
    if ($level_lock)                            return ['open' => false, 'reason' => 'level',    'limit' => 0];
    if ($vis === 'trackers' && !$is_tracker)    return ['open' => false, 'reason' => 'trackers', 'limit' => 0];
    $lim = $is_tracker ? $lim_trk : $lim_non;
    if ($lim < 0)                               return ['open' => false, 'reason' => 'group',    'limit' => 0, 'audience' => $is_tracker ? 'their trackers' : 'other Dezzmers'];
    return ['open' => true, 'reason' => '', 'limit' => $lim];
}

$pv = gl_privacy($conn, $profile_id);
$viewer_is_tracker = (!$is_own && gl_count($conn, "SELECT COUNT(*) FROM followers WHERE follower_id=" . (int)$current_user . " AND following_id=$profile_id") > 0);
$level_lock = (GL_LEVEL_LOCKS && !$is_own && !$viewer_is_tracker && in_array($pv['level'], ['limited', 'private', 'full'], true));
$level_name = GL_LEVEL_NAMES[$pv['level']] ?? 'Public';
$acc = gl_resolve($pv['show_pods'], $level_lock, $is_own, $viewer_is_tracker, $pv['lim_pods_trk'], $pv['lim_pods_non']);

// real total — ALWAYS shown (even when the list itself is hidden); only the pods are hidden
$pods_total = gl_count($conn, "SELECT COUNT(*) FROM group_members WHERE user_id=$profile_id");

// ── Fetch this user's joined groups (never fetched when the list is hidden from this viewer) ──
$groups_raw = [];
if ($acc['open']) {
    $lim_sql = $acc['limit'] > 0 ? ' LIMIT ' . (int)$acc['limit'] : '';
    $groups_result = $conn->prepare("
        SELECT g.id, g.title, g.description, g.group_pic, g.category,
               (SELECT COUNT(*) FROM group_members WHERE group_id = g.id) AS member_count,
               gm.joined_at,
               IF(gp.group_id IS NOT NULL, 1, 0) AS is_pinned
        FROM group_members gm
        JOIN `groups` g ON gm.group_id = g.id
        LEFT JOIN group_pins gp ON gp.group_id = g.id AND gp.user_id = ?
        WHERE gm.user_id = ?
        ORDER BY is_pinned DESC, gm.joined_at DESC$lim_sql
    ");
    $groups_result->bind_param("ii", $profile_id, $profile_id);
    $groups_result->execute();
    $groups_raw = $groups_result->get_result()->fetch_all(MYSQLI_ASSOC);
}
$pods_cut = ($acc['open'] && $acc['limit'] > 0 && $pods_total > count($groups_raw));

// ── Which of these pods has the VIEWER already joined? ────
// Joined pods open straight away; the preview modal is only for pods you have NOT joined.
$viewer_joined = [];
if ($acc['open']) {
    if ($is_own) {
        foreach ($groups_raw as $g) $viewer_joined[(int)$g['id']] = true;
    } else {
        $vj = $conn->prepare("SELECT group_id FROM group_members WHERE user_id = ?");
        $vj->bind_param("i", $current_user);
        $vj->execute();
        $vj_res = $vj->get_result();
        while ($vr = $vj_res->fetch_assoc()) $viewer_joined[(int)$vr['group_id']] = true;
        $vj->close();
    }
}

$page_title = $is_own ? 'Your Pods' : $user['username'] . "'s Pods";
$who_name   = htmlspecialchars($user['username']);

/* ── Locked message: [icon, short message] (the "N PODs · PODs X has joined." line is printed above it) ── */
function gl_locked_view(array $acc, string $who, string $level_name): array {
    // NOTE: the curly apostrophe (’) counts as a valid variable-name character in PHP,
    // so the variable MUST be wrapped in {$...} when ’ comes right after it.
    switch ($acc['reason']) {
        case 'me':
            return ['ri-lock-line', "{$who}’s pods list is currently hidden"];
        case 'level':
            return ['ri-lock-line', "Only people who track {$who} can see it"];
        case 'group':
            return ['ri-lock-line', "{$who} hides it from " . ($acc['audience'] ?? 'you')];
        default:
            return ['ri-lock-line', "Only trackers of {$who} can see it"];
    }
}
function gl_owner_note(array $pv): string {
    $vis = $pv['show_pods'];
    $t = $vis === 'everyone' ? 'Anyone can see this list.' : ($vis === 'trackers' ? 'Only your trackers can see this list.' : 'Only you can see this list.');
    if ($vis !== 'me') {
        $trk = (int)$pv['lim_pods_trk']; $non = (int)$pv['lim_pods_non'];
        $parts = [$trk < 0 ? 'your trackers see nothing' : ($trk > 0 ? "your trackers see up to {$trk}" : 'your trackers see all of them')];
        if ($vis === 'everyone') $parts[] = $non < 0 ? 'other Dezzmers see nothing' : ($non > 0 ? "other Dezzmers see up to {$non}" : 'other Dezzmers see all of them');
        $t .= ' Limits: ' . implode(', ', $parts) . '.';
        if (GL_LEVEL_LOCKS && in_array($pv['level'], ['limited', 'private', 'full'], true))
            $t .= ' Your privacy level (' . (GL_LEVEL_NAMES[$pv['level']] ?? '') . ') also hides this list from people who don’t track you.';
    }
    return $t;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
<title><?php echo htmlspecialchars($user['username']); ?> · PODs</title>
<script>try{if(localStorage.getItem('dz_theme')==='light')document.documentElement.setAttribute('data-theme','light');}catch(e){}</script>

<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">

<style>
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; font-family:'Poppins', sans-serif; }
button, a { cursor:pointer; outline:none; -webkit-tap-highlight-color:transparent; }
a{ color:inherit; text-decoration:none; }

[class^="ri-"], [class*=" ri-"],
[class^="ri-"]::before, [class*=" ri-"]::before,
[class^="ri-"]::after,  [class*=" ri-"]::after {
    font-family:'remixicon' !important;
    font-style:normal; font-weight:normal; line-height:1;
    speak:none; text-transform:none;
    -webkit-font-smoothing:antialiased;
}

:root{
    --red:       #FF2D2D;
    --red-soft:  rgba(255,45,45,.14);
    --red-border:rgba(255,45,45,.4);
    --red-text:  #ff8a8a;
    --blue:      #2a5fd8;
    --blue-h:    #234fb5;
    --blue-l:    #7ea6ff;
    --blue-dim:  rgba(42,95,216,.18);
    --pod-border:#2a5fd8;   /* same blue as the post modal */

    --bg:        linear-gradient(-45deg,#010218,#070924,#070924);
    --surface:   rgba(var(--fg),0.05);
    --line:      rgba(var(--fg),0.09);
    --line2:     rgba(var(--fg),0.14);
    --text:      #ffffff;
    --fg:        255,255,255;   /* R,G,B of the tint used for lines + soft fills (dark theme = white) */
    --muted:     rgba(var(--fg),0.45);
    --sub:       rgba(var(--fg),0.62);

    --max-w:     580px;
    --topbar-h:  54px;
    --pad:       clamp(9px, 3vw, 16px);
    --ctl-h:     clamp(30px, 8vw, 36px);
    --r:         5px;        /* slightly rounded rectangle */
    --r-card:    6px;
}
@media (max-width:490px){ :root{ --topbar-h:48px; } }
@media (max-width:380px){ :root{ --topbar-h:44px; } }

html{ scroll-behavior:smooth; }

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
.gl-lane{
    width:100%; max-width:var(--max-w);
    min-height:100vh;
    display:flex; flex-direction:column;
    padding-bottom:80px;
}
@media (min-width:801px){
    .gl-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); }
}
@media (max-width:800px){ .gl-lane{ max-width:100%; } }

/* ══════════════════════════════════════
   TOPBAR — X on the left, title on the RIGHT
══════════════════════════════════════ */
.gl-topbar{
    position:sticky; top:0; z-index:500;
    height:var(--topbar-h);
    display:flex; align-items:center; gap:2px;
    padding:0 10px;
    background:var(--bg);
    background-attachment:fixed;
}
.gl-x{
    width:34px; height:34px;
    background:none; border:none; color:var(--ui-text,#fff);
    display:flex; align-items:center; justify-content:center;
    opacity:.9; transition:opacity .2s, color .2s;
    padding:0; flex-shrink:0;
}
.gl-x:hover{ opacity:1; color:var(--red); }
.gl-x svg{ width:22px; height:22px; display:block; }
.gl-title{
    flex:1; min-width:0;
    font-size:.92rem; font-weight:700; color:var(--ui-text,#fff); letter-spacing:-.1px;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    text-align:right; margin:0 6px 0 10px;
}
@media (max-width:490px){
    .gl-x{ width:30px; height:30px; }
    .gl-title{ font-size:.84rem; }
}
@media (max-width:380px){
    .gl-title{ font-size:.8rem; }
}

/* RESPONSIVE: grey line under the topbar (toolbar's own top line is dropped so it never doubles) */
@media (max-width:768px){
    .gl-topbar{ border-bottom:1px solid var(--line); }
    .gl-lane .toolbar{ border-top:none; }
}

/* ══════════════════════════════════════
   TOOLBAR — search + view toggle + sort, all rectangular
══════════════════════════════════════ */
.toolbar{
    display:flex; align-items:center; gap:8px;
    padding:8px var(--pad) 6px;
    border-top:1px solid var(--line);
    border-bottom:1px solid var(--line);
}
.search-wrap{ flex:1; position:relative; display:flex; align-items:center; min-width:0; }
.search-icon{ position:absolute; left:11px; color:var(--muted); font-size:1rem; pointer-events:none; }
.search-input{
    width:100%; height:var(--ctl-h);
    background:var(--surface); border:1px solid var(--line2);
    border-radius:var(--r);
    padding:0 12px 0 34px;
    font-size:.76rem; color:var(--text); outline:none;
    transition:border-color .2s, background .2s, box-shadow .2s;
    -webkit-appearance:none; appearance:none;
}
.search-input::placeholder{ color:var(--muted); }
.search-input:focus{
    border-color:rgba(255,45,45,.7); background:rgba(var(--fg),0.07);
    box-shadow:0 0 0 3px rgba(255,45,45,.12);
}

.view-btns{ display:flex; gap:4px; flex-shrink:0; }
.view-btn{
    width:var(--ctl-h); height:var(--ctl-h);
    background:rgba(var(--fg),0.1);
    backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    border:none; border-radius:var(--r);
    color:rgba(var(--fg),.7); font-size:1rem;
    display:flex; align-items:center; justify-content:center;
    transition:background .2s, color .2s, transform .15s;
}
.view-btn:hover{ background:rgba(var(--fg),0.2); }
.view-btn:active{ transform:scale(.96); }
.view-btn.active{ background:rgba(var(--fg),0.1); color:var(--red); }

.sort-link{
    height:var(--ctl-h); padding:0 12px; flex-shrink:0;
    display:flex; align-items:center; justify-content:center;
    background:rgba(var(--fg),0.1);
    backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    border:none; border-radius:var(--r);
    color:var(--ui-text,#fff); font-size:.7rem; font-weight:600; white-space:nowrap;
    transition:background .2s, transform .15s;
}
.sort-link:hover{ background:rgba(var(--fg),0.2); }
.sort-link:active{ transform:scale(.97); }

@media (max-width:490px){
    .search-input{ font-size:.7rem; padding-left:32px; }
    .search-icon{ left:10px; font-size:.92rem; }
    .view-btn{ font-size:.92rem; }
    .sort-link{ font-size:.64rem; padding:0 10px; }
    .toolbar{ gap:6px; }
}
@media (max-width:380px){
    .sort-link{ font-size:.6rem; padding:0 8px; }
    .view-btns{ gap:3px; }
}

/* ══════════════════════════════════════
   PRIVACY NOTICES — what the owner chose, shown to whoever is looking
══════════════════════════════════════ */
.gl-notice{ padding:12px var(--pad); border-bottom:1px solid var(--line); font-size:.7rem; line-height:1.6; color:var(--muted); }
.gl-notice > i{ display:none; }
.gl-notice span{ display:block; }
.gl-notice b{ display:block; color:var(--ui-text,#fff); font-weight:600; font-size:.74rem; margin-bottom:2px; }
.gl-notice .gl-sub{ display:block; }
.gl-notice a.gl-link{ display:inline-block; margin-top:4px; color:var(--red-text); font-weight:600; }
.gl-notice a.gl-link:hover{ text-decoration:underline; }

.gl-cut{ padding:14px var(--pad); text-align:center; font-size:.66rem; line-height:1.6; color:var(--muted); }
.gl-cut i{ display:none; }

/* locked list: normal "N PODs · PODs X has joined." line on top, then lock icon + short message */
.gl-locked{ display:flex; flex-direction:column; align-items:center; text-align:center; gap:6px; padding:70px 24px 30px; }
.gl-locked-ic{ display:block; font-size:3.4rem; line-height:1; color:rgba(var(--fg),.3); margin-bottom:10px; }
.gl-locked p{ font-size:.74rem; color:var(--sub); line-height:1.7; max-width:340px; }
.gl-locked .gl-cta{ margin-top:8px; color:var(--red-text); font-size:.72rem; font-weight:600; }
.gl-locked .gl-cta i{ display:none; }
.gl-locked .gl-cta:hover{ text-decoration:underline; }
@media (max-width:490px){
    .gl-notice{ font-size:.64rem; }
    .gl-notice b{ font-size:.7rem; }
    .gl-locked{ padding:44px 20px 24px; }
    .gl-locked p{ font-size:.68rem; }
    .gl-locked .gl-cta{ font-size:.66rem; }
}

/* ── Group lists ── */
.group-list{ display:none; flex-direction:column; width:100%; }
.group-list.active{ display:flex; animation:glFade .3s ease both; }
@keyframes glFade{ from{ opacity:0; transform:translateY(6px); } to{ opacity:1; transform:none; } }

.context-note{
    font-size:.7rem; color:var(--muted); line-height:1.55;
    padding:12px var(--pad) 10px;
    border-bottom:1px solid var(--line);
}
.context-note b{ color:var(--ui-text,#fff); font-weight:600; }
@media (max-width:490px){ .context-note{ font-size:.64rem; } }

/* ── LIST VIEW ── */
.group-row{
    display:flex; align-items:center; gap:clamp(10px, 3vw, 14px);
    padding:12px var(--pad);
    border-bottom:1px solid rgba(var(--fg),0.06);
    cursor:pointer; transition:background .15s;
    color:var(--text); text-decoration:none;
}
.group-row:hover{ background:rgba(var(--fg),0.03); }

.group-icon{
    width:clamp(42px, 11.5vw, 50px); height:clamp(42px, 11.5vw, 50px);
    border-radius:8px; object-fit:cover; flex-shrink:0;
    border:2px solid var(--pod-border);
}
@media (max-width:490px){ .group-icon{ border-width:1.5px; } }

.group-info{ flex:1; min-width:0; }
.group-name{
    display:flex; align-items:center; gap:6px;
    font-size:.84rem; font-weight:600; color:var(--text);
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.pin-badge{
    display:inline-flex; align-items:center; gap:3px;
    background:rgba(255,45,45,.14);
    border-radius:4px; padding:2px 6px;
    font-size:.56rem; font-weight:700; color:var(--red);
    text-transform:uppercase; letter-spacing:.05em; flex-shrink:0;
}
.group-meta{ font-size:.7rem; color:var(--muted); margin-top:2px; }
.group-desc{
    font-size:.7rem; color:var(--muted); margin-top:3px;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
@media (max-width:490px){
    .group-name{ font-size:.78rem; }
    .group-meta, .group-desc{ font-size:.64rem; }
}
@media (max-width:380px){
    .group-name{ font-size:.74rem; }
    .group-meta, .group-desc{ font-size:.6rem; }
}

.group-actions{ display:flex; align-items:center; gap:6px; flex-shrink:0; }
.group-actions > i{ color:var(--muted); font-size:1rem; }

.pin-btn{
    width:28px; height:28px; flex-shrink:0;
    background:rgba(var(--fg),0.08); border:none; border-radius:4px;
    color:var(--muted); font-size:.9rem;
    display:flex; align-items:center; justify-content:center;
    transition:background .2s, color .2s;
}
.pin-btn:hover{ background:rgba(var(--fg),0.16); color:var(--red); }
.pin-btn.pinned{ color:var(--red); background:rgba(255,45,45,.14); }
.pin-btn:disabled{ opacity:.5; }

/* ── ICON / GRID VIEW ── */
.group-grid-wrap{ display:none; width:100%; flex-direction:column; padding:var(--pad) var(--pad) 0; }
.group-grid-wrap.active{ display:flex; }
.group-grid{
    display:grid; grid-template-columns:repeat(2, minmax(0,1fr));
    gap:clamp(8px, 2.6vw, 12px);
}
.grid-card{
    position:relative;
    display:flex; flex-direction:column; align-items:center; gap:clamp(7px, 2vw, 10px);
    padding:clamp(12px, 3.4vw, 18px) 10px clamp(10px, 3vw, 14px);
    border-radius:var(--r-card);
    border:1px solid var(--line);
    background:rgba(var(--fg),0.03);
    cursor:pointer; transition:background .2s, border-color .2s;
}
.grid-card:hover{ background:rgba(var(--fg),0.06); border-color:rgba(55,138,221,.4); }
.grid-img{
    width:clamp(64px, 22vw, 110px); height:clamp(64px, 22vw, 110px);
    border-radius:10px; object-fit:cover;
    border:2px solid var(--pod-border);
}
.grid-pin-dot{
    position:absolute; top:8px; right:8px;
    width:8px; height:8px; border-radius:2px;
    background:var(--red); display:none;
}
.grid-pin-dot.show{ display:block; }
.grid-name{
    font-size:.8rem; font-weight:600; color:var(--text);
    text-align:center; line-height:1.35; width:100%;
    overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical;
}
.grid-meta{ font-size:.66rem; color:var(--muted); text-align:center; }
@media (max-width:490px){
    .grid-name{ font-size:.74rem; }
    .grid-meta{ font-size:.6rem; }
}

/* ── Empty / no results ── */
.empty-state{
    padding:48px 20px 32px; text-align:center; color:var(--muted);
    display:flex; flex-direction:column; align-items:center; gap:10px;
}
.empty-state i{ font-size:2.1rem; color:var(--red); opacity:.85; }
.empty-state span{ font-size:.8rem; }
.search-no-results{ display:none; }
@media (max-width:490px){ .empty-state span{ font-size:.74rem; } }

/* ══════════════════════════════════════
   SORT MODAL — centered form (desktop) / bottom sheet (responsive)
══════════════════════════════════════ */
.modal-overlay{
    position:fixed; inset:0; z-index:999;
    background:rgba(0,0,0,0.7);
    backdrop-filter:blur(4px); -webkit-backdrop-filter:blur(4px);
    display:none; align-items:flex-end; justify-content:center;
}
.modal-overlay.show{ display:flex; }
.modal{
    position:relative; width:100%; max-width:480px;
    background:var(--sheet,rgba(12,12,28,0.98));
    border:1px solid var(--line);
    border-bottom:none;
    border-radius:10px 10px 0 0;
    padding:8px 18px calc(env(safe-area-inset-bottom, 0px) + 18px);
    animation:glSlideUp .28s cubic-bezier(.32,.72,0,1);
}
@keyframes glSlideUp{ from{ transform:translateY(60px); opacity:0; } to{ transform:none; opacity:1; } }
.modal-handle{ width:36px; height:4px; border-radius:4px; background:rgba(var(--fg),.18); margin:8px auto 14px; }
.modal-close{
    position:absolute; top:12px; right:12px; width:28px; height:28px;
    background:rgba(var(--fg),.08); border:none; border-radius:4px; color:var(--sub);
    font-size:1.05rem; display:flex; align-items:center; justify-content:center;
    transition:background .2s, color .2s;
}
.modal-close:hover{ background:rgba(var(--fg),.18); color:var(--ui-text,#fff); }
.modal h3{
    font-size:.9rem; font-weight:700; margin-bottom:10px;
    display:flex; align-items:center; gap:8px; padding-right:34px;
}
.modal h3 i{ color:var(--red); }

.sort-option{
    display:flex; align-items:center; gap:12px; width:100%; text-align:left;
    padding:13px 0; background:none; border:none;
    border-bottom:1px solid rgba(var(--fg),.06);
    color:var(--text); font-size:.8rem; font-weight:500;
    transition:color .2s;
}
.sort-option:last-child{ border-bottom:none; padding-bottom:2px; }
.sort-option:hover{ color:var(--red); }
.sort-option i{ color:var(--red); font-size:1.05rem; width:20px; text-align:center; }
.sort-option.active-sort{ color:var(--red); }
.sort-option.active-sort::after{ content:'✓'; margin-left:auto; font-size:.8rem; }

@media (min-width:600px){
    .modal-overlay{ align-items:center; padding:20px; }
    .modal{ border-radius:8px; border-bottom:1px solid var(--line); padding:20px 20px 16px; max-width:380px; }
    .modal-handle{ display:none; }
}
@media (max-width:790px){
    .modal{ max-height:78vh; overflow-y:auto; padding:7px 16px calc(env(safe-area-inset-bottom, 0px) + 16px); }
    .modal-handle{ margin:7px auto 12px; }
    .modal h3{ font-size:.86rem; margin-bottom:8px; }
    .sort-option{ font-size:.77rem; padding:12px 0; gap:11px; }
    .sort-option i{ font-size:1rem; width:19px; }
}
@media (max-width:490px){
    .modal{ padding:6px 14px calc(env(safe-area-inset-bottom, 0px) + 14px); }
    .modal-handle{ width:32px; height:3.5px; margin:7px auto 10px; }
    .modal-close{ width:26px; height:26px; top:10px; right:10px; font-size:1rem; }
    .modal h3{ font-size:.8rem; gap:7px; }
    .sort-option{ font-size:.72rem; padding:11px 0; gap:10px; }
    .sort-option i{ font-size:.95rem; width:18px; }
    .sort-option.active-sort::after{ font-size:.74rem; }
}
@media (max-width:390px){
    .modal{ padding:6px 12px calc(env(safe-area-inset-bottom, 0px) + 12px); }
    .modal h3{ font-size:.76rem; }
    .sort-option{ font-size:.68rem; padding:10px 0; gap:9px; }
    .sort-option i{ font-size:.9rem; width:17px; }
}
@media (max-width:330px){
    .modal{ padding:5px 10px calc(env(safe-area-inset-bottom, 0px) + 10px); }
    .modal-handle{ width:28px; height:3px; margin:6px auto 8px; }
    .modal-close{ width:24px; height:24px; }
    .modal h3{ font-size:.72rem; }
    .sort-option{ font-size:.64rem; padding:9px 0; gap:8px; }
    .sort-option i{ font-size:.85rem; width:16px; }
}

/* ══════════════════════════════════════
   POD PREVIEW MODAL (only for pods you have NOT joined)
══════════════════════════════════════ */
.pim2-overlay{
    display:none; position:fixed; inset:0; z-index:99999999;
    background:rgba(0,0,0,0.72);
    backdrop-filter:blur(5px); -webkit-backdrop-filter:blur(5px);
    align-items:flex-end; justify-content:center;
}
.pim2-overlay.show{ display:flex; animation:pim2FadeIn .2s ease; }
@keyframes pim2FadeIn{ from{opacity:0;} to{opacity:1;} }

.pim2-sheet{
    width:100%; max-width:480px; max-height:80vh;
    background:var(--sheet-solid,#0c0c1c);
    border:1px solid var(--line);
    border-bottom:none;
    border-radius:10px 10px 0 0;
    overflow-y:auto; overflow-x:hidden; -webkit-overflow-scrolling:touch;
    animation:glSlideUp .28s cubic-bezier(.22,1,.36,1);
    scrollbar-width:thin; scrollbar-color:rgba(var(--fg),.14) transparent;
}
.pim2-sheet::-webkit-scrollbar{ width:4px; }
.pim2-sheet::-webkit-scrollbar-thumb{ background:rgba(var(--fg),.14); border-radius:999px; }
@media (min-width:600px){
    .pim2-overlay{ align-items:center; padding:20px; }
    .pim2-sheet{ max-width:380px; max-height:82vh; border-radius:8px; border-bottom:1px solid var(--line); }
}

.pim2-handle{
    display:block; width:34px; height:3.5px; background:rgba(var(--fg),.22);
    border-radius:3px; margin:8px auto 0; position:sticky; top:0; z-index:3;
}
@media (min-width:600px){ .pim2-handle{ display:none; } }

.pim2-banner-frame{ position:relative; flex-shrink:0; }
.pim2-banner-wrap{ width:100%; height:clamp(78px, 24vw, 110px); overflow:hidden; position:relative; }
.pim2-banner{ width:100%; height:100%; object-fit:cover; display:block; background:rgba(var(--fg),.04); }
.pim2-avatar-wrap{
    position:absolute; bottom:-20px; left:clamp(12px, 3.6vw, 16px);
    width:clamp(42px, 12vw, 56px); height:clamp(42px, 12vw, 56px);
    border-radius:10px; border:3px solid var(--sheet-solid,#0c0c1c);
    overflow:hidden; background:var(--ph,#1a1a2e); z-index:2;
}
.pim2-avatar{ width:100%; height:100%; object-fit:cover; display:block; }

.pim2-banner-see-btn{
    position:absolute; top:10px; right:10px; z-index:3;
    display:flex; align-items:center; gap:4px;
    height:26px; padding:0 10px; border-radius:var(--r); border:none;
    background:rgba(0,0,0,.5);
    backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px);
    color:#fff; font-size:.66rem; font-weight:600;
    transition:background .2s;
}
.pim2-banner-see-btn i{ font-size:.8rem; }
.pim2-banner-see-btn:hover{ background:rgba(0,0,0,.7); }
@media (max-width:390px){ .pim2-banner-see-btn{ height:24px; padding:0 8px; font-size:.6rem; top:7px; right:7px; } }

.pim2-body{ padding:28px clamp(12px, 3.6vw, 16px) 16px; }

.pim2-loading{ display:flex; align-items:center; justify-content:center; padding:36px 0; color:rgba(var(--fg),.3); font-size:22px; }
.pim2-spin{ animation:pim2Spin .8s linear infinite; }
@keyframes pim2Spin{ to{ transform:rotate(360deg); } }

.pim2-title{ font-size:clamp(.86rem, 2.6vw, 1rem); font-weight:700; color:var(--ui-text,#fff); line-height:1.25; margin-bottom:3px; word-break:break-word; }
.pim2-sub{ display:flex; align-items:center; gap:5px; font-size:clamp(.62rem, 1.9vw, .7rem); color:var(--muted); }
.pim2-sub i{ font-size:.82rem; color:rgba(var(--fg),.35); }
.pim2-divider{ height:1px; background:var(--line); margin:12px calc(-1 * clamp(12px, 3.6vw, 16px)) 2px; }

.pim2-section-label{
    font-size:clamp(.6rem, 1.8vw, .68rem); font-weight:700; text-transform:uppercase;
    letter-spacing:.4px; color:rgba(var(--fg),.4); margin:12px 0 5px;
}
.pim2-about{ font-size:clamp(.68rem, 2vw, .76rem); color:var(--sub); line-height:1.5; word-break:break-word; white-space:pre-wrap; }
.pim2-about-more{ color:var(--blue-l); font-weight:600; cursor:pointer; white-space:nowrap; }
.pim2-about-more:hover{ text-decoration:underline; }

.pim2-activity-list{ display:flex; flex-direction:column; }
.pim2-act-row{
    display:flex; align-items:center; gap:8px;
    font-size:clamp(.64rem, 1.95vw, .74rem); color:rgba(var(--fg),.75); padding:4px 0;
}
.pim2-act-row i{ font-size:.88rem; color:rgba(var(--fg),.4); width:16px; text-align:center; flex-shrink:0; }
.pim2-sub-row{ padding-top:0; padding-left:24px; font-size:clamp(.58rem, 1.7vw, .66rem); color:var(--muted); }

.pim2-btns{ display:flex; flex-direction:column; gap:8px; margin-top:14px; }
.pim2-btn-join, .pim2-btn-see{
    display:flex; align-items:center; justify-content:center; gap:6px;
    width:100%; height:clamp(32px, 8.4vw, 36px); border:none; border-radius:var(--r);
    font-size:clamp(.68rem, 2vw, .78rem); font-weight:700; line-height:1;
    transition:background .2s, transform .15s;
}
.pim2-btn-join:active, .pim2-btn-see:active{ transform:scale(.98); }
.pim2-btn-join{ background:var(--blue); color:#000; }
.pim2-btn-join:hover{ background:var(--blue-h); }
.pim2-btn-join.pim2-joined{ background:var(--blue-dim); color:var(--blue-l); }
.pim2-btn-join:disabled{ cursor:default; }
.pim2-btn-see{
    background:rgba(var(--fg),.1);
    backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    color:var(--ui-text,#fff); font-weight:600;
}
.pim2-btn-see:hover{ background:rgba(var(--fg),.2); }

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
.sk-topbar{ height:var(--topbar-h); display:flex; align-items:center; gap:12px; padding:0 16px 0 14px; }
.sk-back{ width:20px; height:20px; }
.sk-title{ width:110px; height:14px; }
.sk-bar{ height:calc(var(--ctl-h) + 14px); border-top:1px solid var(--line); border-bottom:1px solid var(--line); display:flex; align-items:center; gap:8px; padding:0 var(--pad); }
.sk-bar .sk{ height:var(--ctl-h); border-radius:var(--r); }
.sk-row{ display:flex; align-items:center; gap:14px; padding:12px var(--pad); border-bottom:1px solid rgba(var(--fg),.06); }
.sk-icon{ width:clamp(42px,11.5vw,50px); height:clamp(42px,11.5vw,50px); border-radius:8px; flex-shrink:0; }
.sk-info{ flex:1; display:flex; flex-direction:column; gap:7px; }
.sk-name{ width:130px; height:12px; }
.sk-sub{ width:80px; height:9px; }

/* ═══════════════════════════════════════════════════════════════
   RESPONSIVE ONLY — everything 10% bigger (desktop untouched)
   Kept at the very end so it wins over the rules above.
═══════════════════════════════════════════════════════════════ */
@media (max-width:800px){
    :root{
        --topbar-h:59px;
        --pad:clamp(10px, 3.3vw, 17.6px);
        --ctl-h:clamp(33px, 8.8vw, 39.6px);
        --r:5.5px;
        --r-card:6.6px;
    }

    /* topbar */
    .gl-topbar{ padding:0 11px; }
    .gl-x{ width:37px; height:37px; }
    .gl-x svg{ width:24px; height:24px; }
    .gl-title{ font-size:1.01rem; margin:0 7px 0 11px; }

    /* toolbar */
    .toolbar{ gap:8.8px; padding:9px var(--pad) 6.6px; }
    .search-icon{ left:12px; font-size:1.1rem; }
    .search-input{ padding:0 13px 0 37px; font-size:.84rem; }
    .view-btns{ gap:4.4px; }
    .view-btn{ font-size:1.1rem; }
    .sort-link{ padding:0 13px; font-size:.77rem; }

    /* notices */
    .gl-notice{ padding:13px var(--pad); font-size:.77rem; }
    .gl-notice b{ font-size:.81rem; margin-bottom:2.2px; }
    .gl-notice a.gl-link{ margin-top:4.4px; }
    .gl-cut{ padding:15px var(--pad); font-size:.73rem; }

    /* locked */
    .gl-locked{ gap:6.6px; padding:77px 26px 33px; }
    .gl-locked-ic{ font-size:3.74rem; margin-bottom:11px; }
    .gl-locked p{ font-size:.81rem; max-width:374px; }
    .gl-locked .gl-cta{ margin-top:8.8px; font-size:.79rem; }

    /* list view */
    .context-note{ font-size:.77rem; padding:13px var(--pad) 11px; }
    .group-row{ gap:clamp(11px, 3.3vw, 15px); padding:13px var(--pad); }
    .group-icon{ width:clamp(46px, 12.65vw, 55px); height:clamp(46px, 12.65vw, 55px); border-radius:8.8px; border-width:2.2px; }
    .group-name{ font-size:.92rem; gap:6.6px; }
    .pin-badge{ border-radius:4.4px; padding:2.2px 6.6px; font-size:.62rem; }
    .group-meta{ font-size:.77rem; margin-top:2.2px; }
    .group-desc{ font-size:.77rem; margin-top:3.3px; }
    .group-actions{ gap:6.6px; }
    .group-actions > i{ font-size:1.1rem; }
    .pin-btn{ width:31px; height:31px; border-radius:4.4px; font-size:.99rem; }

    /* grid view */
    .group-grid{ gap:clamp(9px, 2.86vw, 13px); }
    .grid-card{ gap:clamp(8px, 2.2vw, 11px); padding:clamp(13px, 3.74vw, 20px) 11px clamp(11px, 3.3vw, 15px); }
    .grid-img{ width:clamp(70px, 24.2vw, 121px); height:clamp(70px, 24.2vw, 121px); border-radius:11px; border-width:2.2px; }
    .grid-pin-dot{ top:8.8px; right:8.8px; width:8.8px; height:8.8px; }
    .grid-name{ font-size:.88rem; }
    .grid-meta{ font-size:.73rem; }

    /* empty */
    .empty-state{ padding:53px 22px 35px; gap:11px; }
    .empty-state i{ font-size:2.31rem; }
    .empty-state span{ font-size:.88rem; }

    /* sort modal (sheet) */
    .modal{ padding:7.7px 17.6px calc(env(safe-area-inset-bottom, 0px) + 17.6px); }
    .modal-handle{ width:40px; height:4.4px; margin:7.7px auto 13px; }
    .modal-close{ width:31px; height:31px; top:13px; right:13px; font-size:1.15rem; }
    .modal h3{ font-size:.95rem; margin-bottom:8.8px; gap:8.8px; padding-right:37px; }
    .sort-option{ font-size:.85rem; padding:13px 0; gap:12px; }
    .sort-option i{ font-size:1.1rem; width:21px; }
    .sort-option.active-sort::after{ font-size:.88rem; }

    /* pod preview modal */
    .pim2-handle{ width:37px; height:3.85px; margin-top:8.8px; }
    .pim2-banner-wrap{ height:clamp(86px, 26.4vw, 121px); }
    .pim2-avatar-wrap{ bottom:-22px; left:clamp(13px, 3.96vw, 17.6px); width:clamp(46px, 13.2vw, 62px); height:clamp(46px, 13.2vw, 62px); border-radius:11px; border-width:3.3px; }
    .pim2-banner-see-btn{ top:11px; right:11px; gap:4.4px; height:28.6px; padding:0 11px; font-size:.73rem; }
    .pim2-banner-see-btn i{ font-size:.88rem; }
    .pim2-body{ padding:31px clamp(13px, 3.96vw, 17.6px) 17.6px; }
    .pim2-loading{ padding:40px 0; font-size:24px; }
    .pim2-title{ font-size:clamp(.95rem, 2.86vw, 1.1rem); margin-bottom:3.3px; }
    .pim2-sub{ gap:5.5px; font-size:clamp(.68rem, 2.09vw, .77rem); }
    .pim2-sub i{ font-size:.9rem; }
    .pim2-divider{ margin:13px calc(-1 * clamp(13px, 3.96vw, 17.6px)) 2.2px; }
    .pim2-section-label{ font-size:clamp(.66rem, 1.98vw, .75rem); margin:13px 0 5.5px; }
    .pim2-about{ font-size:clamp(.75rem, 2.2vw, .84rem); }
    .pim2-act-row{ gap:8.8px; font-size:clamp(.7rem, 2.15vw, .81rem); padding:4.4px 0; }
    .pim2-act-row i{ font-size:.97rem; width:17.6px; }
    .pim2-sub-row{ padding-left:26px; font-size:clamp(.64rem, 1.87vw, .73rem); }
    .pim2-btns{ gap:8.8px; margin-top:15px; }
    .pim2-btn-join, .pim2-btn-see{ gap:6.6px; height:clamp(35px, 9.24vw, 40px); font-size:clamp(.75rem, 2.2vw, .86rem); }

    /* skeleton */
    .sk-topbar{ gap:13px; padding:0 17.6px 0 15px; }
    .sk-back{ width:22px; height:22px; }
    .sk-title{ width:121px; height:15.4px; }
    .sk-bar{ height:calc(var(--ctl-h) + 15px); gap:8.8px; }
    .sk-row{ gap:15px; padding:13px var(--pad); }
    .sk-icon{ width:clamp(46px, 12.65vw, 55px); height:clamp(46px, 12.65vw, 55px); border-radius:8.8px; }
    .sk-info{ gap:7.7px; }
    .sk-name{ width:143px; height:13px; }
    .sk-sub{ width:88px; height:10px; }
}

/* centered-card range: keep the sort modal a little wider to match */
@media (min-width:600px) and (max-width:800px){
    .modal{ max-width:418px; }
}

@media (max-width:490px){
    :root{ --topbar-h:53px; }
    .gl-x{ width:33px; height:33px; }
    .gl-title{ font-size:.92rem; }
    .search-input{ font-size:.77rem; padding-left:35px; }
    .search-icon{ left:11px; font-size:1.01rem; }
    .view-btn{ font-size:1.01rem; }
    .sort-link{ font-size:.7rem; padding:0 11px; }
    .toolbar{ gap:6.6px; }
    .gl-notice{ font-size:.7rem; }
    .gl-notice b{ font-size:.77rem; }
    .gl-locked{ padding:48px 22px 26px; }
    .gl-locked p{ font-size:.75rem; }
    .gl-locked .gl-cta{ font-size:.73rem; }
    .context-note{ font-size:.7rem; }
    .group-icon{ border-width:1.65px; }
    .group-name{ font-size:.86rem; }
    .group-meta, .group-desc{ font-size:.7rem; }
    .grid-name{ font-size:.81rem; }
    .grid-meta{ font-size:.66rem; }
    .empty-state span{ font-size:.81rem; }
    .modal{ padding:6.6px 15.4px calc(env(safe-area-inset-bottom, 0px) + 15.4px); }
    .modal-handle{ width:35px; height:3.85px; margin:7.7px auto 11px; }
    .modal-close{ width:28.6px; height:28.6px; top:11px; right:11px; font-size:1.1rem; }
    .modal h3{ font-size:.88rem; gap:7.7px; }
    .sort-option{ font-size:.79rem; padding:12px 0; gap:11px; }
    .sort-option i{ font-size:1.045rem; width:19.8px; }
    .sort-option.active-sort::after{ font-size:.81rem; }
}

@media (max-width:390px){
    .modal{ padding:6.6px 13px calc(env(safe-area-inset-bottom, 0px) + 13px); }
    .modal h3{ font-size:.84rem; }
    .sort-option{ font-size:.75rem; padding:11px 0; gap:10px; }
    .sort-option i{ font-size:.99rem; width:18.7px; }
    .pim2-banner-see-btn{ height:26.4px; padding:0 8.8px; font-size:.66rem; top:7.7px; right:7.7px; }
}

@media (max-width:380px){
    :root{ --topbar-h:48px; }
    .gl-title{ font-size:.88rem; }
    .sort-link{ font-size:.66rem; padding:0 8.8px; }
    .view-btns{ gap:3.3px; }
    .group-name{ font-size:.81rem; }
    .group-meta, .group-desc{ font-size:.66rem; }
}

@media (max-width:330px){
    .modal{ padding:5.5px 11px calc(env(safe-area-inset-bottom, 0px) + 11px); }
    .modal-handle{ width:31px; height:3.3px; margin:6.6px auto 8.8px; }
    .modal-close{ width:26.4px; height:26.4px; }
    .modal h3{ font-size:.79rem; }
    .sort-option{ font-size:.7rem; padding:10px 0; gap:8.8px; }
    .sort-option i{ font-size:.935rem; width:17.6px; }
}

/* ═══════════ LIGHT MODE — <html data-theme="light"> (dark mode is never touched) ═══════════ */
:root[data-theme="light"]{
    color-scheme:light;
    --fg:6,7,26;
    --ui-text:#0b0d24;
    --text:#0b0d24;
    --surface:rgba(6,7,26,.05);
    --line:rgba(6,7,26,.1);
    --line2:rgba(6,7,26,.2);
    --muted:rgba(6,7,26,.52);
    --sub:rgba(6,7,26,.66);
    --red-text:#d42525;
    --blue-l:#2a5fd8;
    --bg:linear-gradient(-45deg,#ffffff,#f4f5fb,#f4f5fb);
    --sheet:rgba(255,255,255,.99); --sheet-solid:#ffffff; --ph:#eceef5;
}
[data-theme="light"] .modal-overlay,
[data-theme="light"] .pim2-overlay{ background:rgba(0,0,0,.42); }
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
        <?php if ($acc['open']): ?>
        <div class="sk-bar">
            <div class="sk" style="flex:1;"></div>
            <div class="sk" style="width:var(--ctl-h);"></div>
            <div class="sk" style="width:var(--ctl-h);"></div>
            <div class="sk" style="width:64px;"></div>
        </div>
        <?php endif; ?>
        <?php for ($i = 0; $i < 6; $i++): ?>
        <div class="sk-row">
            <div class="sk sk-icon"></div>
            <div class="sk-info">
                <div class="sk sk-name"></div>
                <div class="sk sk-sub"></div>
            </div>
        </div>
        <?php endfor; ?>
    </div>
</div>

<div class="gl-lane">

    <!-- ══ TOPBAR ══ -->
    <div class="gl-topbar">
        <button type="button" class="gl-x" onclick="glClose()" aria-label="Close">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
        <span class="gl-title"><?php echo htmlspecialchars($page_title); ?></span>
    </div>

<?php if (!$acc['open']):
    [$lk_ic, $lk_text] = gl_locked_view($acc, $who_name, $level_name); ?>

    <!-- ══ LOCKED: same top line as the normal list, then lock icon + short message ══ -->
    <p class="context-note">
        <b><?php echo number_format($pods_total); ?> <?php echo $pods_total === 1 ? 'POD' : 'PODs'; ?></b> ·
        PODs <?php echo $who_name; ?> has joined.
    </p>

    <div class="gl-locked">
        <span class="gl-locked-ic"><i class="<?php echo $lk_ic; ?>"></i></span>
        <p><?php echo $lk_text; ?></p>
        <?php if ($acc['reason'] === 'trackers' || $acc['reason'] === 'level'): ?>
        <a class="gl-cta" href="profile.php?user_id=<?php echo (int)$profile_id; ?>"><i class="ri-user-add-line"></i> View profile to track</a>
        <?php endif; ?>
    </div>

<?php else: ?>

    <!-- ══ TOOLBAR ══ -->
    <div class="toolbar" id="pods-toolbar">
        <div class="search-wrap">
            <i class="ri-search-line search-icon"></i>
            <input type="text" id="pods-search" class="search-input" placeholder="Search PODs..." oninput="filterPods()" autocomplete="off">
        </div>
        <div class="view-btns">
            <button type="button" class="view-btn active" id="btn-list" onclick="setView('list')" title="List view" aria-label="List view">
                <i class="ri-list-check-2"></i>
            </button>
            <button type="button" class="view-btn" id="btn-grid" onclick="setView('grid')" title="Icon view" aria-label="Icon view">
                <i class="ri-apps-2-line"></i>
            </button>
        </div>
        <button type="button" class="sort-link" onclick="openSortModal()" aria-label="Sort groups">Sort by</button>
    </div>

    <?php if ($is_own): ?>
    <div class="gl-notice soft"><i class="ri-eye-line"></i><span><b>Your pods list.</b> <?php echo htmlspecialchars(gl_owner_note($pv)); ?> <a class="gl-link" href="<?php echo GL_SETTINGS_URL; ?>?open=lists">Change in settings</a></span></div>
    <?php elseif ($acc['limit'] > 0): ?>
    <div class="gl-notice"><span><b><?php echo $who_name; ?> limit</b>
        <?php if ($pods_cut): ?><span class="gl-sub"><?php echo $who_name; ?> chose to show only <?php echo (int)$acc['limit']; ?> of their <?php echo number_format($pods_total); ?> pods.</span><?php endif; ?>
        <span class="gl-sub"><?php echo $viewer_is_tracker ? 'As a tracker you can see up to ' : 'You can see up to '; ?><?php echo (int)$acc['limit']; ?>.<?php if ($pods_cut): ?> The rest are hidden.<?php endif; ?></span></span></div>
    <?php endif; ?>

    <!-- LIST VIEW -->
    <div class="group-list active" id="list-pods">
        <?php if (!empty($groups_raw)): ?>
            <p class="context-note">
                <b><?php echo number_format($pods_total); ?> <?php echo $pods_total === 1 ? 'POD' : 'PODs'; ?></b> ·
                <?php if ($is_own): ?>
                    PODs you've joined. Pin your favorites to the top — only you can see or change pins.
                <?php else: ?>
                    PODs <?php echo $who_name; ?> has joined.
                <?php endif; ?>
            </p>
            <?php foreach ($groups_raw as $idx => $g):
                $pic = $g['group_pic'] ?? '';
                if (empty($pic)) { $src = 'uploads/default_group.png'; }
                elseif (str_starts_with($pic, 'uploads/')) { $src = $pic; }
                else { $src = 'uploads/' . $pic; }
                $joined_ts = (int)strtotime($g['joined_at']);
            ?>
            <div class="group-row pod-row"
                 data-id="<?php echo (int)$g['id']; ?>"
                 data-title="<?php echo htmlspecialchars(strtolower($g['title'])); ?>"
                 data-pinned="<?php echo $g['is_pinned'] ? '1' : '0'; ?>"
                 data-joined="<?php echo $joined_ts; ?>"
                 onclick="podTap(<?php echo (int)$g['id']; ?>)">
                <img class="group-icon" src="<?php echo htmlspecialchars($src); ?>" onerror="this.src='uploads/default_group.png'" alt="">
                <div class="group-info">
                    <div class="group-name">
                        <?php echo htmlspecialchars($g['title']); ?>
                        <?php if ($g['is_pinned']): ?>
                        <span class="pin-badge"><i class="ri-pushpin-2-fill" style="font-size:.6rem;"></i> Pinned</span>
                        <?php endif; ?>
                    </div>
                    <div class="group-meta">
                        <i class="ri-user-heart-line"></i> <?php echo number_format($g['member_count']); ?> members
                        <?php if ($g['category']): ?> · <?php echo htmlspecialchars($g['category']); ?><?php endif; ?>
                    </div>
                    <div class="group-desc"><?php echo htmlspecialchars(mb_substr((string)$g['description'], 0, 70)); ?></div>
                </div>
                <div class="group-actions">
                    <?php if ($is_own): ?>
                    <button type="button" class="pin-btn <?php echo $g['is_pinned'] ? 'pinned' : ''; ?>"
                        title="<?php echo $g['is_pinned'] ? 'Unpin' : 'Pin to top'; ?>"
                        onclick="event.stopPropagation(); togglePin(this, <?php echo (int)$g['id']; ?>)"
                        aria-label="<?php echo $g['is_pinned'] ? 'Unpin group' : 'Pin group'; ?>">
                        <i class="<?php echo $g['is_pinned'] ? 'ri-pushpin-2-fill' : 'ri-pushpin-2-line'; ?>"></i>
                    </button>
                    <?php endif; ?>
                    <i class="ri-arrow-right-s-line"></i>
                </div>
            </div>
            <?php endforeach; ?>
            <div class="empty-state search-no-results">
                <i class="ri-search-line"></i>
                <span>No PODs found</span>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="ri-ghost-line"></i>
                <span><?php echo $is_own ? "You haven't joined any PODs yet." : $who_name . " hasn't joined any PODs yet."; ?></span>
            </div>
        <?php endif; ?>
    </div>

    <!-- ICON / GRID VIEW -->
    <div class="group-grid-wrap" id="grid-pods">
        <?php if (!empty($groups_raw)): ?>
            <div class="group-grid" id="grid-inner">
                <?php foreach ($groups_raw as $g):
                    $pic = $g['group_pic'] ?? '';
                    if (empty($pic)) { $src = 'uploads/default_group.png'; }
                    elseif (str_starts_with($pic, 'uploads/')) { $src = $pic; }
                    else { $src = 'uploads/' . $pic; }
                    $joined_ts = (int)strtotime($g['joined_at']);
                ?>
                <div class="grid-card pod-grid-card"
                     data-id="<?php echo (int)$g['id']; ?>"
                     data-title="<?php echo htmlspecialchars(strtolower($g['title'])); ?>"
                     data-pinned="<?php echo $g['is_pinned'] ? '1' : '0'; ?>"
                     data-joined="<?php echo $joined_ts; ?>"
                     onclick="podTap(<?php echo (int)$g['id']; ?>)">
                    <span class="grid-pin-dot<?php echo $g['is_pinned'] ? ' show' : ''; ?>" id="pin-dot-<?php echo (int)$g['id']; ?>"></span>
                    <img class="grid-img" src="<?php echo htmlspecialchars($src); ?>" onerror="this.src='uploads/default_group.png'" alt="">
                    <div class="grid-name"><?php echo htmlspecialchars($g['title']); ?></div>
                    <div class="grid-meta"><?php echo number_format($g['member_count']); ?> members</div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="empty-state search-no-results" id="grid-no-results">
                <i class="ri-search-line"></i>
                <span>No PODs found</span>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="ri-ghost-line"></i>
                <span><?php echo $is_own ? "You haven't joined any PODs yet." : $who_name . " hasn't joined any PODs yet."; ?></span>
            </div>
        <?php endif; ?>
    </div>


<?php endif; ?>

</div><!-- /.gl-lane -->

<!-- ══ SORT MODAL ══ -->
<div class="modal-overlay" id="sortModal" onclick="closeSortOutside(event)">
    <div class="modal">
        <div class="modal-handle"></div>
        <button type="button" class="modal-close" onclick="closeSort()" aria-label="Close"><i class="ri-close-line"></i></button>
        <h3><i class="ri-sort-desc"></i> Sort PODs</h3>
        <button type="button" class="sort-option active-sort" data-sort="pinned" onclick="applySort('pinned')">
            <i class="ri-pushpin-2-line"></i> Pinned first
        </button>
        <button type="button" class="sort-option" data-sort="az" onclick="applySort('az')">
            <i class="ri-sort-asc"></i> Groups A–Z
        </button>
        <button type="button" class="sort-option" data-sort="recent" onclick="applySort('recent')">
            <i class="ri-time-line"></i> Recently joined
        </button>
    </div>
</div>

<!-- ══ POD PREVIEW MODAL (pods you have not joined) ══ -->
<div class="pim2-overlay" id="podPreviewOverlay" onclick="if(event.target===this) closePodModal()">
    <div class="pim2-sheet" id="podPreviewSheet">
        <div class="pim2-handle"></div>

        <div class="pim2-banner-frame">
            <div class="pim2-banner-wrap">
                <img id="ppBanner" class="pim2-banner" alt="" onerror="this.src='uploads/default_group.png'">
            </div>
            <div class="pim2-avatar-wrap">
                <img id="ppAvatar" class="pim2-avatar" alt="" onerror="this.src='uploads/default_group.png'">
            </div>
            <button type="button" class="pim2-banner-see-btn" onclick="ppSeeGroup()">
                <i class="ri-arrow-right-up-line"></i> See Group
            </button>
        </div>

        <div class="pim2-body">

            <div id="ppSkeleton" class="pim2-loading">
                <i class="ri-loader-4-line pim2-spin"></i>
            </div>

            <div id="ppContent" style="display:none;">
                <h2 class="pim2-title" id="ppTitle"></h2>
                <div class="pim2-sub">
                    <i class="ri-earth-line" id="ppPrivacyIcon"></i> <span id="ppPrivacyLabel">Public Pod</span> · <span id="ppMemberCount">0</span> members
                </div>

                <div class="pim2-divider"></div>

                <div class="pim2-section-label">About</div>
                <div class="pim2-about" id="ppAbout"></div>

                <div class="pim2-section-label">Pod activity</div>
                <div class="pim2-activity-list">
                    <div class="pim2-act-row">
                        <i class="ri-discuss-line"></i>
                        <span id="ppTotalPosts">0</span> total posts in the pod
                    </div>
                    <div class="pim2-act-row">
                        <i class="ri-team-line"></i>
                        <span id="ppMembers2">0</span> members in total
                    </div>
                    <div class="pim2-act-row pim2-sub-row">
                        <span id="ppNewWeek">+0</span> in the last week
                    </div>
                    <div class="pim2-act-row">
                        <i class="ri-eye-line"></i>
                        <span id="ppVisitors">0</span> visitors this week
                    </div>
                    <div class="pim2-act-row">
                        <i class="ri-calendar-line"></i>
                        Created <span id="ppCreated"></span>
                    </div>
                </div>

                <div class="pim2-btns">
                    <button type="button" class="pim2-btn-join" id="ppJoinBtn" onclick="ppJoinGroup()"><i class="ri-group-line"></i> Join Pod</button>
                    <button type="button" class="pim2-btn-see" onclick="ppSeeGroup()">See Pod</button>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
/* ── CSRF token (from the <meta> tag) — sent with every POST ── */
var CSRF_TOKEN = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

/* ── Pods the VIEWER has joined: these open straight away, no modal ── */
var viewerJoined = <?php echo json_encode((object)$viewer_joined); ?>;

function podTap(groupId) {
    if (viewerJoined[groupId]) {
        location.href = 'group.php?id=' + groupId;
    } else {
        openPodModal(groupId);
    }
}

/* ── X: go back, or to the profile if there is no history ── */
function glClose() {
    if (document.referrer && document.referrer.indexOf(window.location.host) !== -1) history.back();
    else location.href = 'profile.php?user_id=<?php echo (int)$profile_id; ?>';
}

/* ── Remove skeleton ── */
(function () {
    var sk = document.getElementById('page-skeleton');
    if (!sk) return;
    var done = false;
    function hideSkeleton() {
        if (done) return;
        done = true;
        sk.classList.add('hide');
        setTimeout(function () { if (sk.parentNode) sk.remove(); }, 380);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { setTimeout(hideSkeleton, 50); });
    } else {
        setTimeout(hideSkeleton, 50);
    }
    setTimeout(hideSkeleton, 1200);
})();

/* ── View toggle (list / icon) — these elements do not exist when the list is hidden from you ── */
var listPods = document.getElementById('list-pods');
var gridPods = document.getElementById('grid-pods');

function setView(view) {
    if (!listPods || !gridPods) return;
    document.getElementById('btn-list').classList.toggle('active', view === 'list');
    document.getElementById('btn-grid').classList.toggle('active', view === 'grid');
    listPods.style.display = (view === 'list') ? 'flex' : 'none';
    gridPods.style.display = (view === 'grid') ? 'flex' : 'none';
    filterPods();
}

/* ── Search / filter ── */
function filterPods() {
    if (!listPods) return;
    var q = document.getElementById('pods-search').value.trim().toLowerCase();

    var rows = document.querySelectorAll('.pod-row');
    var listVisible = 0;
    rows.forEach(function (row) {
        var match = !q || row.dataset.title.indexOf(q) > -1;
        row.style.display = match ? '' : 'none';
        if (match) listVisible++;
    });
    var listNoResult = listPods.querySelector('.search-no-results');
    if (listNoResult) listNoResult.style.display = (q && listVisible === 0) ? 'flex' : 'none';

    var cards = document.querySelectorAll('.pod-grid-card');
    var gridVisible = 0;
    cards.forEach(function (card) {
        var match = !q || card.dataset.title.indexOf(q) > -1;
        card.style.display = match ? '' : 'none';
        if (match) gridVisible++;
    });
    var gridNoResult = document.getElementById('grid-no-results');
    if (gridNoResult) gridNoResult.style.display = (q && gridVisible === 0) ? 'flex' : 'none';
}

/* ── Pin / Unpin ── */
function togglePin(btn, groupId) {
    var isPinned = btn.classList.contains('pinned');
    btn.disabled = true;

    fetch('toggle_pin_group.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF_TOKEN },
        credentials: 'same-origin',
        body: (isPinned ? 'unpin=1' : 'pin=1') + '&group_id=' + groupId + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        btn.disabled = false;
        if (data && data.error === 'csrf') { location.reload(); return; }
        if (!data.success) return;

        var row  = btn.closest('.group-row');
        var icon = btn.querySelector('i');
        var card = document.querySelector('.pod-grid-card[data-id="' + groupId + '"]');
        var dot  = document.getElementById('pin-dot-' + groupId);
        var nowPinned = !isPinned;

        btn.classList.toggle('pinned', nowPinned);
        icon.className = nowPinned ? 'ri-pushpin-2-fill' : 'ri-pushpin-2-line';
        btn.title = nowPinned ? 'Unpin' : 'Pin to top';
        if (row)  row.dataset.pinned  = nowPinned ? '1' : '0';
        if (card) card.dataset.pinned = nowPinned ? '1' : '0';
        if (dot)  dot.classList.toggle('show', nowPinned);

        if (row) {
            var nameEl = row.querySelector('.group-name');
            var badge  = nameEl.querySelector('.pin-badge');
            if (nowPinned && !badge) {
                badge = document.createElement('span');
                badge.className = 'pin-badge';
                badge.innerHTML = '<i class="ri-pushpin-2-fill" style="font-size:.6rem;"></i> Pinned';
                nameEl.appendChild(badge);
            } else if (!nowPinned && badge) {
                badge.remove();
            }
        }
    })
    .catch(function () { btn.disabled = false; });
}

/* ── Sort modal ── */
function openSortModal() { document.getElementById('sortModal').classList.add('show'); }
function closeSort()     { document.getElementById('sortModal').classList.remove('show'); }
function closeSortOutside(e) { if (e.target === document.getElementById('sortModal')) closeSort(); }

function applySort(sort) {
    document.querySelectorAll('.sort-option').forEach(function (el) {
        el.classList.toggle('active-sort', el.dataset.sort === sort);
    });
    if (!listPods) { closeSort(); return; }

    function cmp(a, b) {
        var ja = parseInt(a.dataset.joined, 10) || 0, jb = parseInt(b.dataset.joined, 10) || 0;
        if (sort === 'az')     return a.dataset.title.localeCompare(b.dataset.title);
        if (sort === 'recent') return jb - ja;
        var pa = a.dataset.pinned === '1' ? 1 : 0, pb = b.dataset.pinned === '1' ? 1 : 0;
        return (pb - pa) || (jb - ja);   // pinned first, then recently joined
    }

    var rows = Array.prototype.slice.call(listPods.querySelectorAll('.pod-row')).sort(cmp);
    var noResult = listPods.querySelector('.search-no-results');
    rows.forEach(function (row) { listPods.insertBefore(row, noResult); });

    var gridInner = document.getElementById('grid-inner');
    if (gridInner) {
        Array.prototype.slice.call(gridInner.querySelectorAll('.pod-grid-card')).sort(cmp)
            .forEach(function (card) { gridInner.appendChild(card); });
    }
    closeSort();
}

/* ══════════════════════════════════════════════════════
   POD PREVIEW MODAL — only for pods the viewer has NOT joined
══════════════════════════════════════════════════════ */
var _ppCurrentGroupId = null;
var _ppAboutExpanded = false;

function openPodModal(groupId) {
    _ppCurrentGroupId = groupId;
    _ppAboutExpanded = false;

    document.getElementById('podPreviewOverlay').classList.add('show');
    document.body.style.overflow = 'hidden';

    document.getElementById('ppSkeleton').style.display = 'flex';
    document.getElementById('ppContent').style.display = 'none';
    document.getElementById('podPreviewSheet').scrollTop = 0;

    fetch('get_pod_info.php?group_id=' + groupId)
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.error) { closePodModal(); return; }
            // already a member (stale page)? skip the modal and go straight in
            if (data.is_member) {
                viewerJoined[groupId] = true;
                location.href = 'group.php?id=' + groupId;
                return;
            }
            renderPodModal(data);
        })
        .catch(function () { closePodModal(); });
}

function renderPodModal(data) {
    document.getElementById('ppBanner').src = data.banner;
    document.getElementById('ppAvatar').src = data.img;
    document.getElementById('ppTitle').textContent = data.title;

    var privacyIcon  = document.getElementById('ppPrivacyIcon');
    var privacyLabel = document.getElementById('ppPrivacyLabel');
    if (data.is_private) {
        privacyIcon.className = 'ri-lock-line';
        privacyLabel.textContent = 'Private Pod';
    } else {
        privacyIcon.className = 'ri-earth-line';
        privacyLabel.textContent = 'Public Pod';
    }

    document.getElementById('ppMemberCount').textContent = ppNumberFormat(data.member_count);
    document.getElementById('ppTotalPosts').textContent  = ppNumberFormat(data.total_posts);
    document.getElementById('ppMembers2').textContent    = ppNumberFormat(data.member_count);
    document.getElementById('ppNewWeek').textContent     = '+' + ppNumberFormat(data.new_week) + ' joined';
    document.getElementById('ppVisitors').textContent    = ppNumberFormat(data.visitors_week);
    document.getElementById('ppCreated').textContent     = ppTimeAgo(data.created_at);

    ppRenderAbout(data.description || '');

    var joinBtn = document.getElementById('ppJoinBtn');
    joinBtn.disabled = false;
    joinBtn.innerHTML = '<i class="ri-group-line"></i> Join Pod';
    joinBtn.classList.remove('pim2-joined');

    document.getElementById('ppSkeleton').style.display = 'none';
    document.getElementById('ppContent').style.display = 'block';
}

/* ── About: truncate at 30 words with See more / See less ── */
function ppEscapeHtml(str) {
    var d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}
function ppRenderAbout(desc) {
    var box = document.getElementById('ppAbout');
    var text = desc.trim() ? desc.trim() : 'No description set for this pod.';
    var words = text.split(/\s+/);

    if (words.length <= 30) {
        box.innerHTML = ppEscapeHtml(text);
        window._ppAboutDraw = null;
        return;
    }
    var short = words.slice(0, 30).join(' ');
    function draw() {
        if (_ppAboutExpanded) {
            box.innerHTML = ppEscapeHtml(text) + ' <span class="pim2-about-more" onclick="ppToggleAbout()">See less</span>';
        } else {
            box.innerHTML = ppEscapeHtml(short) + '… <span class="pim2-about-more" onclick="ppToggleAbout()">See more</span>';
        }
    }
    draw();
    window._ppAboutDraw = draw;
}
function ppToggleAbout() {
    _ppAboutExpanded = !_ppAboutExpanded;
    if (window._ppAboutDraw) window._ppAboutDraw();
}

function closePodModal() {
    document.getElementById('podPreviewOverlay').classList.remove('show');
    document.body.style.overflow = '';
    _ppCurrentGroupId = null;
}

function ppSeeGroup() {
    if (_ppCurrentGroupId) location.href = 'group.php?id=' + _ppCurrentGroupId;
}

/* ── Join / Leave (toggles based on current button state) ── */
function ppJoinGroup() {
    if (!_ppCurrentGroupId) return;
    var btn = document.getElementById('ppJoinBtn');
    if (btn.dataset.busy === '1') return;

    var gid = _ppCurrentGroupId;
    var wasJoined = btn.classList.contains('pim2-joined');
    var action = wasJoined ? 'ajax_leave_group=1' : 'ajax_join_group=1';

    btn.dataset.busy = '1';
    btn.textContent = wasJoined ? 'Leaving…' : 'Joining…';

    fetch('group_list.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF_TOKEN },
        credentials: 'same-origin',
        body: action + '&group_id=' + gid + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        btn.dataset.busy = '0';

        if (data.success) {
            document.getElementById('ppMemberCount').textContent = ppNumberFormat(data.member_count);
            document.getElementById('ppMembers2').textContent    = ppNumberFormat(data.member_count);

            if (data.is_member) {
                viewerJoined[gid] = true;     // from now on this pod opens straight away
                btn.textContent = 'Joined ✓';
                btn.classList.add('pim2-joined');
            } else {
                delete viewerJoined[gid];
                btn.innerHTML = '<i class="ri-group-line"></i> Join Pod';
                btn.classList.remove('pim2-joined');
            }
        } else {
            if (wasJoined) btn.textContent = 'Joined ✓';
            else btn.innerHTML = '<i class="ri-group-line"></i> Join Pod';
            if (data.error === 'blocked') alert('You cannot join this pod.');
            else if (data.error === 'csrf') location.reload();
            else if (data.error === 'not_logged_in') location.href = 'login.php';
            else alert('Something went wrong. Please try again.');
        }
    })
    .catch(function () {
        btn.dataset.busy = '0';
        if (wasJoined) btn.textContent = 'Joined ✓';
        else btn.innerHTML = '<i class="ri-group-line"></i> Join Pod';
    });
}

function ppNumberFormat(n) {
    n = parseInt(n, 10) || 0;
    return n.toLocaleString();
}

function ppTimeAgo(dateStr) {
    if (!dateStr) return '';
    var d = new Date(dateStr.replace(' ', 'T'));
    if (isNaN(d.getTime())) return dateStr;
    var diffDays = Math.floor((new Date() - d) / 86400000);
    if (diffDays < 1) return 'today';
    if (diffDays === 1) return '1 day ago';
    if (diffDays < 30) return diffDays + ' days ago';
    var months = Math.floor(diffDays / 30);
    if (months < 12) return 'about ' + months + (months === 1 ? ' month ago' : ' months ago');
    var years = Math.floor(months / 12);
    return 'about ' + years + (years === 1 ? ' year ago' : ' years ago');
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        closeSort();
        closePodModal();
    }
});
</script>

</body>
</html>