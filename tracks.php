<?php
/* ══════════════════════════════════════════════════════════════
   SESSION — hardened cookie (HttpOnly + SameSite=Lax + Secure on HTTPS)
   so the session cookie is never sent on cross-site POSTs.
══════════════════════════════════════════════════════════════ */
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
include 'config.php';
/* verified flower badge (dz_badge_sprite() + dz_badge('f')) — same file the mutuals / crossroads use */
require_once __DIR__ . '/verified_badge.php';

// ── Auth & IDs ─────────────────────────────────────────────
$current_user = (int)($_SESSION['user_id'] ?? 0);
if(!$current_user){ header("Location: login.php"); exit(); }

/* Where the Settings page lives (used by the "Change in settings" links shown to the owner) */
const TK_SETTINGS_URL = 'setting.php';
/* true  = Limited / Private / Full Private profiles also hide the Trackers + Tracking LISTS
           from people who do not track them (the counts themselves always stay visible).
   false = only the list settings (Everyone / My trackers / Only me + limits) decide. */
const TK_LEVEL_LOCKS = true;

/* ══════════════════════════════════════════════════════════════
   CSRF PROTECTION
   • One random token per session, embedded in the page (<meta name="csrf-token">).
   • EVERY state-changing request (all POST actions on this page) must send it back,
     either as the X-CSRF-Token header or the csrf_token field.
   • Compared with hash_equals() (timing-safe).
   • As a second layer, when the browser sends an Origin / Referer header it must
     belong to this same host — otherwise the request is refused.
   • Failed checks answer 403 and change nothing.
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
    echo json_encode(['status' => 'error', 'msg' => 'csrf']);
    exit();
}

/* ── safe query helpers ── */
function tk_count($conn, $sql){
    try { $r = $conn->query($sql); if ($r instanceof mysqli_result) { $row = $r->fetch_row(); return (int)($row[0] ?? 0); } } catch (Throwable $e) {}
    return 0;
}
function tk_items($result){
    $o = [];
    if ($result instanceof mysqli_result) while ($x = $result->fetch_assoc()) $o[] = $x;
    return $o;
}

/* ══════════════════════════════════════════════════════════════
   PRIVACY — reads what the person saved on the Settings page
   (user_privacy + user_settings.is_fully_private)
══════════════════════════════════════════════════════════════ */
const TK_LEVELS = ['public', 'limited', 'private', 'full'];
const TK_LEVEL_NAMES = ['public' => 'Public', 'limited' => 'Limited', 'private' => 'Private', 'full' => 'Full Private'];

function tk_privacy($conn, int $uid): array {
    $p = [
        'level' => 'public',
        'show_trackers' => 'everyone', 'show_tracking' => 'everyone',
        'lim_trackers_trk' => 0, 'lim_trackers_non' => 0,
        'lim_tracking_trk' => 0, 'lim_tracking_non' => 0,
    ];
    $stored = null; $flag = false;
    try {
        $r = $conn->query("SELECT * FROM user_privacy WHERE user_id=$uid LIMIT 1");
        if ($r instanceof mysqli_result && ($row = $r->fetch_assoc())) {
            $stored = $row['privacy_level'] ?? null;
            foreach (['show_trackers', 'show_tracking'] as $k) {
                if (isset($row[$k]) && in_array($row[$k], ['everyone', 'trackers', 'me'], true)) $p[$k] = $row[$k];
            }
            foreach (['lim_trackers_trk', 'lim_trackers_non', 'lim_tracking_trk', 'lim_tracking_non'] as $k) {
                if (isset($row[$k])) $p[$k] = max(-1, (int)$row[$k]);
            }
        }
    } catch (Throwable $e) { /* table not created yet: defaults */ }
    try {
        $r = $conn->query("SELECT is_fully_private FROM user_settings WHERE user_id=$uid LIMIT 1");
        if ($r instanceof mysqli_result && ($row = $r->fetch_assoc())) $flag = !empty($row['is_fully_private']);
    } catch (Throwable $e) {}
    // same reconciliation as the Settings page
    $lv = in_array($stored, TK_LEVELS, true) ? $stored : null;
    if ($flag && !in_array($lv, ['private', 'full'], true)) $lv = 'full';
    if (!$flag && in_array($lv, ['private', 'full'], true)) $lv = 'public';
    $p['level'] = $lv ?: ($flag ? 'full' : 'public');
    return $p;
}

/* Decides what ONE viewer may see of ONE list.
   returns ['open'=>bool, 'reason'=>'me'|'level'|'trackers'|'group'|'', 'limit'=>int (0 = no limit)] */
function tk_resolve(string $vis, bool $level_lock, bool $is_owner, bool $is_tracker, int $lim_trk, int $lim_non): array {
    if ($is_owner)                              return ['open' => true,  'reason' => '',         'limit' => 0];
    if ($vis === 'me')                          return ['open' => false, 'reason' => 'me',       'limit' => 0];
    if ($level_lock)                            return ['open' => false, 'reason' => 'level',    'limit' => 0];
    if ($vis === 'trackers' && !$is_tracker)    return ['open' => false, 'reason' => 'trackers', 'limit' => 0];
    $lim = $is_tracker ? $lim_trk : $lim_non;
    if ($lim < 0)                               return ['open' => false, 'reason' => 'group',    'limit' => 0, 'audience' => $is_tracker ? 'their trackers' : 'other Dezzmers'];
    return ['open' => true, 'reason' => '', 'limit' => $lim];
}

$profile_id = (isset($_GET['user_id']) && (int)$_GET['user_id'] > 0)
    ? (int)$_GET['user_id']
    : $current_user;

$is_own_profile = ($current_user === $profile_id);

// ── User info ──────────────────────────────────────────────
$user = $conn->query("SELECT id, username, profile_pic, nickname FROM users WHERE id=$profile_id LIMIT 1")->fetch_assoc();
if(!$user){ header("Location: 404.php"); exit(); }

// ── Handle POST actions FIRST (before any output) ──────────
if($_SERVER['REQUEST_METHOD'] === 'POST' && $current_user){
    header('Content-Type: application/json');
    header('Cache-Control: no-store');

    // 🔒 CSRF check — nothing below runs without a valid token
    if(!csrf_verify()){ csrf_fail(); }

    $action  = $_POST['action'] ?? '';
    $user_id = (int)($_POST['user_id'] ?? 0);

    if($user_id === $current_user){
        echo json_encode(['status'=>'error','msg'=>'Not allowed']);
        exit();
    }

    $is_priv_target = false;
    if($user_id){
        $priv = $conn->query("SELECT is_fully_private FROM user_settings WHERE user_id=$user_id LIMIT 1")->fetch_assoc();
        $is_priv_target = !empty($priv['is_fully_private']);
    }

    if($action === 'toggle_follow' && $user_id){
        $exists = $conn->query("SELECT id FROM followers WHERE follower_id=$current_user AND following_id=$user_id")->num_rows;
        if($exists){
            $conn->query("DELETE FROM followers WHERE follower_id=$current_user AND following_id=$user_id");
        } else {
            // Private profiles can never be tracked directly — a request is required
            if($is_priv_target){
                echo json_encode(['status'=>'error','msg'=>'private','is_private'=>true]);
                exit();
            }
            $conn->query("INSERT INTO followers(follower_id,following_id) VALUES($current_user,$user_id)");
        }
        echo json_encode(['status'=>'ok','is_private'=>$is_priv_target]);

    } elseif($action === 'send_track_request' && $user_id){
        // Remove any old denied request first so INSERT IGNORE works cleanly
        $conn->query("DELETE FROM track_requests WHERE requester_id=$current_user AND target_id=$user_id AND status='denied'");
        $conn->query("INSERT IGNORE INTO track_requests(requester_id,target_id,status) VALUES($current_user,$user_id,'pending')");
        echo json_encode(['status'=>'ok','is_private'=>$is_priv_target]);

    } elseif($action === 'cancel_track_request' && $user_id){
        $conn->query("DELETE FROM track_requests WHERE requester_id=$current_user AND target_id=$user_id");
        echo json_encode(['status'=>'ok','is_private'=>$is_priv_target]);

    } else {
        echo json_encode(['status'=>'error','msg'=>'Unknown action']);
    }
    exit();
}

/* ══════════════════════════════════════════════════════════════
   WHAT MAY THIS VIEWER SEE?
   Owner → everything. Tracker → what "My trackers" allows. Other Dezzmer → what "Everyone" allows.
   Hidden LISTS are never fetched, so nothing leaks into the HTML.
   The COUNTS (tab numbers) are always shown, even when a list is private.
   MUTUALS are never private — they always show.
══════════════════════════════════════════════════════════════ */
$pv = tk_privacy($conn, $profile_id);
$viewer_is_tracker = (!$is_own_profile && tk_count($conn, "SELECT COUNT(*) FROM followers WHERE follower_id=$current_user AND following_id=$profile_id") > 0);
$level_lock = (TK_LEVEL_LOCKS && !$is_own_profile && !$viewer_is_tracker && in_array($pv['level'], ['limited', 'private', 'full'], true));
$level_name = TK_LEVEL_NAMES[$pv['level']] ?? 'Public';

$acc_trackers = tk_resolve($pv['show_trackers'], $level_lock, $is_own_profile, $viewer_is_tracker, $pv['lim_trackers_trk'], $pv['lim_trackers_non']);
$acc_tracking = tk_resolve($pv['show_tracking'], $level_lock, $is_own_profile, $viewer_is_tracker, $pv['lim_tracking_trk'], $pv['lim_tracking_non']);

// ── Real totals — ALWAYS computed and ALWAYS shown, even if the list itself is private ──
$followers_total = tk_count($conn, "SELECT COUNT(*) FROM followers WHERE following_id=$profile_id");
$following_total = tk_count($conn, "SELECT COUNT(*) FROM followers WHERE follower_id=$profile_id");

// ── Trackers list ──────────────────────────────────────────
$followers_items = [];
if($acc_trackers['open']){
    $lim = $acc_trackers['limit'] > 0 ? ' LIMIT ' . (int)$acc_trackers['limit'] : '';
    $followers_items = tk_items($conn->query("
        SELECT u.id, u.username, u.profile_pic, u.nickname, u.is_verified, u.user_rank
        FROM followers f
        JOIN users u ON f.follower_id = u.id
        WHERE f.following_id = $profile_id
        ORDER BY f.id DESC$lim
    "));
}

// ── Tracking list = profiles this profile tracks ───────────
$following_items = [];
if($acc_tracking['open']){
    $lim = $acc_tracking['limit'] > 0 ? ' LIMIT ' . (int)$acc_tracking['limit'] : '';
    $following_items = tk_items($conn->query("
        SELECT u.id, u.username, u.profile_pic, u.nickname, u.is_verified, u.user_rank
        FROM followers f
        JOIN users u ON f.following_id = u.id
        WHERE f.follower_id = $profile_id
        ORDER BY f.id DESC$lim
    "));
}

/* numbers shown on the tabs: always the REAL total (privacy only hides the list, never the number).
   Even when the owner limits how many entries you can open, the real total stays visible. */
$followers_shown = $followers_total;
$following_shown = $following_total;
$followers_cut   = $acc_trackers['open'] && $acc_trackers['limit'] > 0 && $followers_total > count($followers_items);
$following_cut   = $acc_tracking['open'] && $acc_tracking['limit'] > 0 && $following_total > count($following_items);

// ── Mutuals (other profiles only) — NEVER private ──────────
// Always shown, no matter what the owner chose for Trackers / Tracking,
// no privacy level hides them and no limit trims them.
$mutuals_count = 0;
$mutuals_total = 0;
$mutuals_items = [];
if(!$is_own_profile){
    $mutuals_items = tk_items($conn->query("
        SELECT u.id, u.username, u.profile_pic, u.nickname, u.is_verified, u.user_rank
        FROM   followers f1
        JOIN   followers f2 ON f2.follower_id = f1.following_id
        JOIN   users     u  ON u.id           = f1.following_id
        WHERE  f1.follower_id  = $current_user
          AND  f2.following_id = $profile_id
          AND  f1.following_id != $current_user
          AND  f1.following_id != $profile_id
        ORDER BY u.username ASC
    "));
    $mutuals_count = count($mutuals_items);
    $mutuals_total = $mutuals_count;
}

// ── Circle (own profile: people who follow each other) ──────
$circle_count = 0;
$circle_items = [];
if($is_own_profile){
    $circle_items = tk_items($conn->query("
        SELECT u.id, u.username, u.profile_pic, u.nickname, u.is_verified, u.user_rank
        FROM   followers f1
        JOIN   followers f2
            ON  f2.follower_id  = f1.following_id
           AND  f2.following_id = $current_user
        JOIN   users u ON u.id = f1.following_id
        WHERE  f1.follower_id   = $current_user
          AND  f1.following_id != $current_user
        ORDER BY u.username ASC
    "));
    $circle_count = count($circle_items);
}

// ── Track request states for every user the current viewer might see ──
$track_request_states = [];
$tr_res = $conn->query("
    SELECT target_id, status
    FROM track_requests
    WHERE requester_id = $current_user
");
if($tr_res){
    while($tr_row = $tr_res->fetch_assoc()){
        $track_request_states[(int)$tr_row['target_id']] = $tr_row['status'];
    }
}

// ── Tabs & which one is open (validated so a stale ?type= never leaves nothing active) ──
// 'count' is always a real number. 'locked' only tells the page the LIST is hidden
// (it hides the search box) — it never replaces the number any more.
$tabs = [
    'followers' => ['label' => 'Trackers', 'count' => $followers_shown, 'locked' => !$acc_trackers['open']],
    'following' => ['label' => 'Tracking', 'count' => $following_shown, 'locked' => !$acc_tracking['open']],
];
if(!$is_own_profile && $mutuals_count > 0) $tabs['mutuals'] = ['label' => 'Mutual', 'count' => $mutuals_total, 'locked' => false];
if($is_own_profile  && $circle_count  > 0) $tabs['circle']  = ['label' => 'Circle', 'count' => $circle_count,  'locked' => false];
$valid_tabs   = array_keys($tabs);
$default_tab  = $_GET['type'] ?? 'followers';
$active_tab   = in_array($default_tab, $valid_tabs, true) ? $default_tab : 'followers';
$active_index = (int)array_search($active_tab, $valid_tabs, true);

/* ══════════════════════════════════════════════════════════════
   NOTICE TEXT — tells each kind of viewer what is going on
══════════════════════════════════════════════════════════════ */
$who_name = htmlspecialchars($user['username']);

function tk_locked_view(array $acc, string $who, string $listName, string $level_name): array {
    // returns [icon, title, text]
    // NOTE: the curly apostrophe (’) counts as a valid variable-name character in PHP,
    // so the variable MUST be wrapped in {$...} when ’ comes right after it.
    $head = "{$who}’s {$listName} list is private";
    switch ($acc['reason']) {
        case 'me':
            return ['ri-lock-line', $head, "{$who}’s {$listName} list is currently hidden"];
        case 'level':
            return ['ri-lock-line', $head, "Only people who track {$who} can see it"];
        case 'group':
            return ['ri-lock-line', $head, "{$who} hides it from " . ($acc['audience'] ?? 'you')];
        default:
            return ['ri-lock-line', $head, "Only trackers of {$who} can see it"];
    }
}
function tk_visibility_word(string $v): string {
    return $v === 'everyone' ? 'Anyone can see this list'
         : ($v === 'trackers' ? 'Only your trackers can see this list' : 'Only you can see this list');
}
function tk_owner_note(array $pv, string $list, string $levelName): string {
    $vis = $pv['show_' . $list];
    $t = tk_visibility_word($vis) . '.';
    if ($vis !== 'me') {
        $trk = (int)$pv["lim_{$list}_trk"]; $non = (int)$pv["lim_{$list}_non"];
        $parts = [];
        $parts[] = $trk < 0 ? 'your trackers see nothing' : ($trk > 0 ? "your trackers see up to $trk" : 'your trackers see everyone');
        if ($vis === 'everyone') $parts[] = $non < 0 ? 'other Dezzmers see nothing' : ($non > 0 ? "other Dezzmers see up to $non" : 'other Dezzmers see everyone');
        $t .= ' Limits: ' . implode(', ', $parts) . '.';
    }
    if (TK_LEVEL_LOCKS && in_array($pv['level'], ['limited', 'private', 'full'], true) && $vis !== 'me')
        $t .= " Your privacy level ({$levelName}) also hides this list from people who don’t track you.";
    return $t;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
<meta name="referrer" content="same-origin">
<title><?php echo htmlspecialchars($user['username']); ?> · Tracks</title>
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
    --red:             #FF2D2D;
    --track-red:       #FF2D2D;
    --track-red-hover: #ff1a1a;
    --red-soft:        rgba(255,45,45,.14);
    --red-border:      rgba(255,45,45,.4);
    --red-text:        #ff8a8a;
    --ring:            #FF2D2D;   /* avatar border colour AND verified badge colour */

    /* page background — identical to the post modal */
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

    /* same action button metrics as the profile page */
    --rp-action-h:      clamp(25px, 7.2vw, 30px);
    --rp-action-radius: 4px;
    --rp-pill-radius:   5px;
}
@media (max-width:490px){ :root{ --topbar-h:48px; } }
@media (max-width:380px){ :root{ --topbar-h:44px; } }

html{ scroll-behavior:smooth; }

/* RESPONSIVE view: no scrollbars anywhere — scrolling still works.
   Normal view keeps its scrollbars. */
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

/* ── centered lane with the two grey side lines (like the post modal) ── */
.tk-lane{
    width:100%; max-width:var(--max-w);
    min-height:100vh;
    display:flex; flex-direction:column;
}
@media (min-width:801px){
    .tk-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); }
}
@media (max-width:800px){ .tk-lane{ max-width:100%; } }

/* ══════════════════════════════════════
   TOPBAR
══════════════════════════════════════ */
.tk-topbar{
    position:sticky; top:0; z-index:500;
    height:var(--topbar-h);
    display:flex; align-items:center; gap:2px;
    padding:0 10px;
    background:var(--bg);
    background-attachment:fixed;
}
.tk-back{
    width:34px; height:34px;
    background:none; border:none; color:var(--ui-text,#fff);
    display:flex; align-items:center; justify-content:center;
    opacity:.9; transition:opacity .2s, color .2s;
    padding:0; flex-shrink:0;
}
.tk-back:hover{ opacity:1; color:var(--red); }
.tk-back svg{ width:22px; height:22px; display:block; }
.tk-title{
    font-size:.92rem; font-weight:700; color:var(--ui-text,#fff); letter-spacing:-.1px;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    min-width:0; flex:1;
    text-align:right; margin:0 6px 0 10px;
}
@media (max-width:490px){
    .tk-back{ width:30px; height:30px; }
    .tk-title{ font-size:.84rem; }
}
@media (max-width:380px){
    .tk-title{ font-size:.8rem; }
}
@media (max-width:768px){
    .tk-topbar{ border-bottom:1px solid var(--line); }
}

/* ══════════════════════════════════════
   TABS
══════════════════════════════════════ */
.tk-tabs{
    position:sticky; top:calc(var(--topbar-h) - 1px); z-index:400;
    display:flex; align-items:stretch;
    background:var(--bg);
    background-attachment:fixed;
    border-top:1px solid var(--line);
    border-bottom:1px solid var(--line);
    overflow-x:auto; scrollbar-width:none; -ms-overflow-style:none;
    -webkit-overflow-scrolling:touch;
}
.tk-tabs::-webkit-scrollbar{ display:none; }
.tk-tabs-track{ position:relative; display:flex; align-items:stretch; flex:1 0 auto; }
.tk-tab-ind{
    position:absolute; bottom:0; left:0; height:2px; width:0;
    background:var(--ui-text,#fff); border-radius:2px 2px 0 0;
    transition:left .28s cubic-bezier(.4,0,.2,1), width .28s cubic-bezier(.4,0,.2,1), opacity .2s ease;
    opacity:0; pointer-events:none;
}
.tk-tab-ind.ready{ opacity:1; }
.tk-tab-ind.live{ transition:none; }
.tk-tab{
    flex:1 0 auto; min-width:72px;
    display:flex; flex-direction:column; align-items:center; justify-content:center; gap:2px;
    padding:10px 14px; background:none; border:none;
    color:rgba(var(--fg),0.5); transition:color .2s ease;
}
.tk-tab:hover{ color:rgba(var(--fg),0.85); }
.tk-tab.active{ color:var(--ui-text,#fff); }
.tk-tab-count{ font-size:.9rem; font-weight:700; color:var(--ui-text,#fff); line-height:1.1; display:flex; align-items:center; justify-content:center; min-height:1em; }
.tk-tab-label{ font-size:.66rem; font-weight:500; letter-spacing:.02em; }
@media (max-width:490px){
    .tk-tab{ padding:9px 12px; min-width:60px; }
    .tk-tab-count{ font-size:.82rem; }
    .tk-tab-label{ font-size:.6rem; }
}
@media (max-width:380px){
    .tk-tab{ padding:8px 10px; min-width:54px; }
    .tk-tab-count{ font-size:.76rem; }
    .tk-tab-label{ font-size:.56rem; }
}

/* ── Search ── */
.tk-search{
    position:relative; display:flex; align-items:center;
    padding:12px var(--pad) 4px;
}
.tk-search i{
    position:absolute; left:calc(var(--pad) + 11px);
    color:var(--muted); font-size:1rem; pointer-events:none;
}
.tk-search input{
    width:100%; height:clamp(30px, 8vw, 36px);
    background:var(--surface); border:1px solid var(--line2);
    border-radius:var(--rp-pill-radius);
    padding:0 12px 0 34px;
    font-size:.76rem; color:var(--text); outline:none;
    transition:border-color .2s, background .2s, box-shadow .2s;
    -webkit-appearance:none; appearance:none;
}
.tk-search input::placeholder{ color:var(--muted); }
.tk-search input:focus{
    border-color:rgba(255,45,45,.7); background:rgba(var(--fg),0.07);
    box-shadow:0 0 0 3px rgba(255,45,45,.12);
}
.tk-search.off{ display:none; }
@media (max-width:490px){
    .tk-search input{ font-size:.7rem; padding-left:32px; }
    .tk-search i{ left:calc(var(--pad) + 10px); font-size:.92rem; }
}

/* ── Lists ── */
.tk-pager{
    width:100%; overflow:hidden;
    touch-action:pan-y;
}
.tk-pager.dragging{ user-select:none; -webkit-user-select:none; cursor:grabbing; }
.tk-track{
    display:flex; align-items:flex-start; width:100%;
    will-change:transform; backface-visibility:hidden;
}
.user-list{ flex:0 0 100%; width:100%; min-width:0; display:flex; flex-direction:column; padding:0 0 40px; }
.tk-pager.idle .user-list:not(.active){ max-height:0; overflow:hidden; padding-bottom:0; visibility:hidden; }

.context-note{
    font-size:.7rem; color:var(--muted); line-height:1.55;
    padding:12px var(--pad) 10px;
    border-bottom:1px solid var(--line);
}
@media (max-width:490px){ .context-note{ font-size:.64rem; } }

/* ══════════════════════════════════════
   PRIVACY NOTICES — what the owner chose, shown to whoever is looking
══════════════════════════════════════ */
.tk-notice{ padding:12px var(--pad); border-bottom:1px solid var(--line); font-size:.7rem; line-height:1.6; color:var(--muted); }
.tk-notice > i{ display:none; }
.tk-notice span{ display:block; }
.tk-notice b{ display:block; color:var(--ui-text,#fff); font-weight:600; font-size:.74rem; margin-bottom:2px; }
.tk-notice .tk-sub{ display:block; }
.tk-notice a.tk-link{ display:inline-block; margin-top:4px; color:var(--red-text); font-weight:600; }
.tk-notice a.tk-link:hover{ text-decoration:underline; }

.tk-cut{ padding:14px var(--pad); text-align:center; font-size:.66rem; line-height:1.6; color:var(--muted); }
.tk-cut i{ display:none; }

/* locked list: words only */
.tk-locked{ display:flex; flex-direction:column; align-items:center; text-align:center; gap:6px; padding:90px 24px 30px; }
.tk-locked-ic{ display:block; font-size:3.4rem; line-height:1; color:rgba(var(--fg),.3); margin-bottom:12px; }
.tk-locked h3{ font-size:.86rem; font-weight:700; color:var(--ui-text,#fff); }
.tk-locked p{ font-size:.72rem; color:var(--muted); line-height:1.7; max-width:340px; }
.tk-locked .tk-cta{ margin-top:8px; color:var(--red-text); font-size:.72rem; font-weight:600; }
.tk-locked .tk-cta i{ display:none; }
.tk-locked .tk-cta:hover{ text-decoration:underline; }
@media (max-width:490px){
    .tk-notice{ font-size:.64rem; }
    .tk-notice b{ font-size:.7rem; }
    .tk-locked{ padding:44px 20px 24px; }
    .tk-locked h3{ font-size:.8rem; }
    .tk-locked p{ font-size:.66rem; }
    .tk-locked .tk-cta{ font-size:.66rem; }
}

/* ── User row ── */
.user-row{
    display:flex; align-items:center; gap:clamp(10px, 3vw, 14px);
    padding:12px var(--pad);
    border-bottom:1px solid rgba(var(--fg),0.06);
    cursor:pointer; transition:background .15s;
    color:var(--text); text-decoration:none;
}
.user-row:last-of-type{ border-bottom:none; }
.user-row:hover{ background:rgba(var(--fg),0.03); }

.user-avatar{
    width:clamp(38px, 10.5vw, 46px); height:clamp(38px, 10.5vw, 46px);
    border-radius:50%; object-fit:cover; flex-shrink:0;
    border:2px solid var(--ring);
}
@media (max-width:490px){ .user-avatar{ border-width:1.5px; } }
.user-avatar-susp{ display:block; background:rgba(var(--fg),.1); }   /* a slightly lighter shade of the page background, same ring as every picture */
.user-row:has(.user-avatar-susp) .tk-btn{ display:none !important; }
.user-info .unick.unick-susp{ color:var(--ring); font-weight:700; }

.user-info{ flex:1; min-width:0; }
/* name + badge on one line; a long name is cut with "…" but the badge never is */
.user-info .uname{
    display:flex; align-items:center; gap:.3em; flex-wrap:nowrap; min-width:0;
    font-size:.84rem; font-weight:600; color:var(--text);
}
.user-info .uname .un-nm{
    min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
/* verified badge: filled, same size as the name text (1em follows every responsive
   font-size below), colour = same variable as the avatar border, dark AND light mode */
.user-info .uname .vbadge{
    width:1em; height:1em;
    vertical-align:baseline;
    flex-shrink:0;
    color:var(--ring) !important;
    fill:currentColor;
}
.user-info .unick{
    font-size:.7rem; color:var(--muted); margin-top:2px;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
@media (max-width:490px){
    .user-info .uname{ font-size:.78rem; gap:.28em; }
    .user-info .uname .vbadge{ width:1em; height:1em; }
    .user-info .unick{ font-size:.64rem; }
}
@media (max-width:380px){
    .user-info .uname{ font-size:.74rem; gap:.26em; }
    .user-info .uname .vbadge{ width:1em; height:1em; }
    .user-info .unick{ font-size:.6rem; }
}

.new-post-badge{
    display:inline-block; color:var(--red);
    font-size:.62rem; font-weight:700; letter-spacing:.3px;
    margin-left:4px; vertical-align:middle; line-height:1;
    position:relative; top:-1px;
}
.new-post-badge::before{ content:'• '; font-size:.7rem; }

/* ══════════════════════════════════════
   TRACK / UNTRACK / REQUESTED
══════════════════════════════════════ */
.tk-btn{
    display:flex; align-items:center; justify-content:center; gap:6px;
    flex-shrink:0; min-width:96px;
    height:var(--rp-action-h); padding:0 9px;
    border:none; border-radius:var(--rp-action-radius);
    font-size:.72rem; font-weight:600; line-height:1; color:var(--ui-text,#fff);
    white-space:nowrap; overflow:hidden;
    transition:background .2s ease, transform .15s ease;
}
.tk-btn:active{ transform:scale(0.96); }
.tk-btn:disabled{ cursor:default; }

.tk-btn.track{ background:var(--track-red); color:#000; font-weight:700; }
.tk-btn.track:hover{ background:var(--track-red-hover); }

.tk-btn.untrack{
    background:rgba(var(--fg),0.1);
    backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    color:var(--ui-text,#fff); font-weight:600;
}
.tk-btn.untrack:hover{ background:rgba(var(--fg),0.2); }

.tk-btn.pending{
    background:rgba(var(--fg),0.1);
    color:rgba(var(--fg),0.45); font-weight:600;
}
.tk-btn.pending:hover{ background:rgba(var(--fg),0.16); }

@media (max-width:490px){
    .tk-btn{ min-width:82px; font-size:.62rem; padding:0 7px; }
}
@media (max-width:380px){
    .tk-btn{ min-width:74px; font-size:.62rem; padding:0 6px; }
}

.spinner-icon{
    display:inline-flex; align-items:center; justify-content:center;
    gap:3px; height:16px; vertical-align:middle;
}
.spinner-icon span{
    display:block; width:3px; background:currentColor; border-radius:2px;
    animation:btn-bar 1s ease-in-out infinite;
}
.spinner-icon span:nth-child(1){ animation-delay:0s; }
.spinner-icon span:nth-child(2){ animation-delay:.15s; }
.spinner-icon span:nth-child(3){ animation-delay:.3s; }
.spinner-icon span:nth-child(4){ animation-delay:.45s; }
@keyframes btn-bar{
    0%,100%{ height:4px; opacity:.4; }
    50%    { height:10px; opacity:1; }
}

/* ── Empty state ── */
.empty-state{
    padding:48px 20px 32px; text-align:center; color:var(--muted);
    display:flex; flex-direction:column; align-items:center; gap:10px;
}
.empty-state i{ font-size:2.1rem; color:var(--red); opacity:.85; }
.empty-state span{ font-size:.8rem; }
@media (max-width:490px){ .empty-state span{ font-size:.74rem; } }

/* ══════════════════════════════════════
   SKELETON
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

.sk{
    position:relative; overflow:hidden;
    background:rgba(var(--fg),0.06); border-radius:6px;
}
.sk::after{
    content:''; position:absolute; inset:0;
    background:linear-gradient(90deg, transparent, rgba(var(--fg),0.09), transparent);
    transform:translateX(-100%); animation:skShimmer 1.5s infinite;
}
@keyframes skShimmer{ 100%{ transform:translateX(100%); } }

.sk-topbar{ height:var(--topbar-h); display:flex; align-items:center; gap:12px; padding:0 16px 0 10px; }
.sk-back{ width:22px; height:22px; }
.sk-title{ width:120px; height:14px; }
.sk-tabs{ display:flex; border-top:1px solid var(--line); border-bottom:1px solid var(--line); }
.sk-tab{ flex:1; height:46px; border-radius:0; }
.sk-tab + .sk-tab{ margin-left:2px; }
.sk-row{ display:flex; align-items:center; gap:14px; padding:12px var(--pad); border-bottom:1px solid rgba(var(--fg),0.06); }
.sk-avatar{ width:clamp(38px,10.5vw,46px); height:clamp(38px,10.5vw,46px); border-radius:50%; flex-shrink:0; }
.sk-info{ flex:1; display:flex; flex-direction:column; gap:7px; }
.sk-name{ width:100px; height:12px; }
.sk-sub{ width:70px; height:9px; }
.sk-btn{ width:86px; height:var(--rp-action-h); border-radius:var(--rp-action-radius); flex-shrink:0; }

/* ═══════════════════════════════════════════════════════════════
   RESPONSIVE ONLY — everything 10% bigger (desktop untouched)
   Kept at the very end so it wins over the rules above.
   The verified badge is 1em, so it grows with the name text here too.
═══════════════════════════════════════════════════════════════ */
@media (max-width:800px){
    :root{
        --topbar-h:59px;
        --pad:clamp(10px, 3.3vw, 17.6px);
        --rp-action-h:clamp(27.5px, 7.92vw, 33px);
        --rp-action-radius:4.4px;
        --rp-pill-radius:5.5px;
    }

    /* topbar */
    .tk-topbar{ padding:0 11px; }
    .tk-back{ width:37px; height:37px; }
    .tk-back svg{ width:24px; height:24px; }
    .tk-title{ font-size:1.01rem; margin:0 7px 0 11px; }

    /* tabs */
    .tk-tab-ind{ height:2.2px; }
    .tk-tab{ min-width:79px; gap:2.2px; padding:11px 15.4px; }
    .tk-tab-count{ font-size:.99rem; }
    .tk-tab-label{ font-size:.73rem; }

    /* search */
    .tk-search{ padding:13px var(--pad) 4.4px; }
    .tk-search i{ left:calc(var(--pad) + 12px); font-size:1.1rem; }
    .tk-search input{ height:clamp(33px, 8.8vw, 39.6px); padding:0 13px 0 37px; font-size:.84rem; }

    /* notes + notices */
    .context-note{ font-size:.77rem; padding:13px var(--pad) 11px; }
    .tk-notice{ padding:13px var(--pad); font-size:.77rem; }
    .tk-notice b{ font-size:.81rem; margin-bottom:2.2px; }
    .tk-notice a.tk-link{ margin-top:4.4px; }
    .tk-cut{ padding:15px var(--pad); font-size:.73rem; }

    /* locked list */
    .tk-locked{ gap:6.6px; padding:99px 26px 33px; }
    .tk-locked-ic{ font-size:3.74rem; margin-bottom:13px; }
    .tk-locked h3{ font-size:.95rem; }
    .tk-locked p{ font-size:.79rem; max-width:374px; }
    .tk-locked .tk-cta{ margin-top:8.8px; font-size:.79rem; }

    /* user row */
    .user-row{ gap:clamp(11px, 3.3vw, 15px); padding:13px var(--pad); }
    .user-avatar{ width:clamp(42px, 11.55vw, 51px); height:clamp(42px, 11.55vw, 51px); border-width:2.2px; }
    .user-info .uname{ font-size:.92rem; gap:.3em; }
    .user-info .uname .vbadge{ width:1em; height:1em; }
    .user-info .unick{ font-size:.77rem; margin-top:2.2px; }
    .new-post-badge{ font-size:.68rem; margin-left:4.4px; }
    .new-post-badge::before{ font-size:.77rem; }

    /* track buttons */
    .tk-btn{ gap:6.6px; min-width:106px; padding:0 10px; font-size:.79rem; }

    /* empty state */
    .empty-state{ padding:53px 22px 35px; gap:11px; }
    .empty-state i{ font-size:2.31rem; }
    .empty-state span{ font-size:.88rem; }

    /* skeleton */
    .sk-topbar{ gap:13px; padding:0 17.6px 0 11px; }
    .sk-back{ width:24px; height:24px; }
    .sk-title{ width:132px; height:15.4px; }
    .sk-tab{ height:50.6px; }
    .sk-row{ gap:15px; padding:13px var(--pad); }
    .sk-avatar{ width:clamp(42px, 11.55vw, 51px); height:clamp(42px, 11.55vw, 51px); }
    .sk-info{ gap:7.7px; }
    .sk-name{ width:110px; height:13px; }
    .sk-sub{ width:77px; height:10px; }
    .sk-btn{ width:95px; }
}

@media (max-width:490px){
    :root{ --topbar-h:53px; }
    .tk-back{ width:33px; height:33px; }
    .tk-title{ font-size:.92rem; }
    .tk-tab{ padding:10px 13px; min-width:66px; }
    .tk-tab-count{ font-size:.9rem; }
    .tk-tab-label{ font-size:.66rem; }
    .tk-search input{ font-size:.77rem; padding-left:35px; }
    .tk-search i{ left:calc(var(--pad) + 11px); font-size:1.01rem; }
    .context-note{ font-size:.7rem; }
    .tk-notice{ font-size:.7rem; }
    .tk-notice b{ font-size:.77rem; }
    .tk-locked{ padding:48px 22px 26px; }
    .tk-locked h3{ font-size:.88rem; }
    .tk-locked p{ font-size:.73rem; }
    .tk-locked .tk-cta{ font-size:.73rem; }
    .user-avatar{ border-width:1.65px; }
    .user-info .uname{ font-size:.86rem; gap:.28em; }
    .user-info .uname .vbadge{ width:1em; height:1em; }
    .user-info .unick{ font-size:.7rem; }
    .tk-btn{ min-width:90px; font-size:.68rem; padding:0 7.7px; }
    .empty-state span{ font-size:.81rem; }
}

@media (max-width:380px){
    :root{ --topbar-h:48px; }
    .tk-title{ font-size:.88rem; }
    .tk-tab{ padding:9px 11px; min-width:59px; }
    .tk-tab-count{ font-size:.84rem; }
    .tk-tab-label{ font-size:.62rem; }
    .user-info .uname{ font-size:.81rem; gap:.26em; }
    .user-info .uname .vbadge{ width:1em; height:1em; }
    .user-info .unick{ font-size:.66rem; }
    .tk-btn{ min-width:81px; font-size:.68rem; padding:0 6.6px; }
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
    --bg:linear-gradient(-45deg,#ffffff,#f4f5fb,#f4f5fb);
}
/* badge keeps the avatar-border colour in light mode too (the name text turns dark, the badge does not) */
:root[data-theme="light"] .user-info .uname .vbadge{ color:var(--ring) !important; }
</style>
</head>
<body>

<?php echo dz_badge_sprite(); ?>

<!-- ══ SKELETON ══ -->
<div id="page-skeleton">
    <div class="sk-lane">
        <div class="sk-topbar">
            <div class="sk sk-back"></div>
        </div>
        <div class="sk-tabs">
            <?php foreach($tabs as $_k => $_t): ?><div class="sk sk-tab"></div><?php endforeach; ?>
        </div>
        <?php for($i=0;$i<8;$i++): ?>
        <div class="sk-row">
            <div class="sk sk-avatar"></div>
            <div class="sk-info">
                <div class="sk sk-name"></div>
                <div class="sk sk-sub"></div>
            </div>
            <div class="sk sk-btn"></div>
        </div>
        <?php endfor; ?>
    </div>
</div>

<div class="tk-lane">

    <!-- ══ TOPBAR ══ -->
    <div class="tk-topbar">
        <button type="button" class="tk-back" onclick="tkClose()" aria-label="Close">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
        <span class="tk-title"><?php echo $is_own_profile ? 'Your Profile Tracks' : htmlspecialchars($user['username']) . '’s Profile Tracks'; ?></span>
    </div>

    <!-- ══ TABS (the real number always shows, even when that list is private) ══ -->
    <div class="tk-tabs" id="tkTabs">
        <div class="tk-tabs-track" id="tkTabsTrack">
            <?php foreach($tabs as $key => $t): ?>
            <button type="button" class="tk-tab tab-btn<?php echo $active_tab === $key ? ' active' : ''; ?>" data-tab="tab-<?php echo $key; ?>" data-locked="<?php echo $t['locked'] ? '1' : '0'; ?>">
                <span class="tk-tab-count"><?php echo number_format((int)$t['count']); ?></span>
                <span class="tk-tab-label"><?php echo $t['label']; ?></span>
            </button>
            <?php endforeach; ?>
            <span class="tk-tab-ind" id="tkTabInd"></span>
        </div>
    </div>

    <!-- ══ SEARCH ══ -->
    <div class="tk-search" id="tkSearch">
        <i class="ri-search-line"></i>
        <input type="text" id="user-search" placeholder="Search users..." oninput="filterUsers()" autocomplete="off">
    </div>

<?php
// ── Reusable row renderer — Dezzmers (profiles) only ─────────────
function render_user_rows($items, $current_user, $conn, $track_request_states = []){
    if(empty($items)) return false;
    foreach($items as $u):
        $uid     = (int)$u['id'];
        $is_self = ($uid == $current_user);

        $priv_row   = $conn->query("SELECT is_fully_private FROM user_settings WHERE user_id=$uid LIMIT 1")->fetch_assoc();
        $is_private = !empty($priv_row['is_fully_private']);

        $i_track_them = (int)$conn->query("
            SELECT COUNT(*) AS c FROM followers
            WHERE follower_id=$current_user AND following_id=$uid
        ")->fetch_assoc()['c'] > 0;

        $req_status = $track_request_states[$uid] ?? null;

        if($is_self){
            $btn_class = ''; $btn_text = ''; $btn_mode = '';
        } elseif($i_track_them){
            $btn_class = 'untrack'; $btn_text = 'Untrack'; $btn_mode = 'untrack';
        } elseif($is_private && $req_status === 'pending'){
            $btn_class = 'pending'; $btn_text = 'Requested'; $btn_mode = 'cancel_request';
        } elseif($is_private){
            $btn_class = 'track'; $btn_text = 'Request to Track'; $btn_mode = 'send_request';
        } else {
            $btn_class = 'track'; $btn_text = 'Track'; $btn_mode = 'follow';
        }

        $has_new_post = false;
        $new_post_chk = $conn->query("
            SELECT id FROM memes
            WHERE uploaded_by = $uid
            AND created_at >= NOW() - INTERVAL 24 HOUR
            LIMIT 1
        ");
        if($new_post_chk && $new_post_chk->num_rows > 0) $has_new_post = true;

        $is_susp = (strtolower((string)($u['user_rank'] ?? '')) === 'suspended');   /* suspended account: plain tile instead of the picture, "Suspended" instead of the nickname */
        $display_username = $is_self ? 'You' : htmlspecialchars($u['username']);
        $vbdg = !empty($u['is_verified']) ? dz_badge('f') : '';
?>
    <div class="user-row" onclick="location.href='profile.php?user_id=<?php echo $uid; ?>'">
        <?php if($is_susp): ?>
        <span class="user-avatar user-avatar-susp"></span>
        <?php else: ?>
        <img class="user-avatar"
             src="<?php echo htmlspecialchars($u['profile_pic'] ?: 'mee.jpg'); ?>"
             onerror="this.src='mee.jpg'" alt="">
        <?php endif; ?>
        <div class="user-info">
            <div class="uname"><span class="un-nm"><?php echo $display_username; ?></span><?php echo $vbdg; ?></div>
            <?php if($is_susp): ?>
            <div class="unick unick-susp">Suspended</div>
            <?php elseif(!empty($u['nickname'])): ?>
            <div class="unick">
                @<?php echo htmlspecialchars($u['nickname']); ?>
                <?php if($has_new_post): ?><span class="new-post-badge">New Post</span><?php endif; ?>
            </div>
            <?php elseif($has_new_post): ?>
            <div class="unick"><span class="new-post-badge">New Post</span></div>
            <?php endif; ?>
        </div>
        <?php if(!$is_self && !$is_susp): ?>
        <button type="button" class="tk-btn <?php echo $btn_class; ?>"
                data-mode="<?php echo $btn_mode; ?>"
                onclick="event.stopPropagation(); handleTrackBtn(this, <?php echo $uid; ?>)">
            <?php echo $btn_text; ?>
        </button>
        <?php endif; ?>
    </div>
<?php
    endforeach;
    return true;
}

/* A locked list: the owner's choice explained to the person looking */
function render_locked($acc, $who, $listName, $level_name, $profile_id){
    [$ic, $title, $text] = tk_locked_view($acc, $who, $listName, $level_name);
?>
    <div class="tk-locked">
        <span class="tk-locked-ic"><i class="<?php echo $ic; ?>"></i></span>
        <h3><?php echo htmlspecialchars($title); ?></h3>
        <p><?php echo $text; ?></p>
        <?php if($acc['reason'] === 'trackers' || $acc['reason'] === 'level'): ?>
        <a class="tk-cta" href="profile.php?user_id=<?php echo (int)$profile_id; ?>"><i class="ri-user-add-line"></i> View profile to track</a>
        <?php endif; ?>
    </div>
<?php
}

/* Notice shown above an OPEN list: owner sees their own settings, others see limits */
function render_open_notice($acc, $is_own, $pv, $list, $who, $listLabel, $viewer_is_tracker, $cut, $total = 0){
    if($is_own){
        echo '<div class="tk-notice soft"><i class="ri-eye-line"></i><span><b>Your ' . $listLabel . ' list.</b> '
           . htmlspecialchars(tk_owner_note($pv, $list, TK_LEVEL_NAMES[$pv['level']] ?? 'Public'))
           . ' <a class="tk-link" href="' . TK_SETTINGS_URL . '?open=lists">Change in settings</a></span></div>';
        return;
    }
    if($acc['limit'] > 0){
        $n = (int)$acc['limit'];
        $what = $list === 'trackers' ? 'trackers' : 'people they track';
        echo '<div class="tk-notice"><span><b>' . $who . ' limit</b>'
           . ($cut ? '<span class="tk-sub">' . $who . ' chose to show only ' . $n . ' of their ' . number_format((int)$total) . ' ' . $what . '.</span>' : '')
           . '<span class="tk-sub">' . ($viewer_is_tracker ? 'As a tracker you can see up to ' : 'You can see up to ') . $n . '.'
           . ($cut ? ' The rest are hidden.' : '') . '</span></span></div>';
    }
}
?>

    <!-- ══ PAGER: the lists sit side by side and follow your finger ══ -->
    <div class="tk-pager idle" id="tkPager">
    <div class="tk-track" id="tkTrack" style="transform:translate3d(-<?php echo $active_index * 100; ?>%,0,0);">

    <!-- ══ TRACKERS LIST ══ -->
    <div class="user-list<?php echo $active_tab === 'followers' ? ' active' : ''; ?>" id="tab-followers">
        <?php if(!$acc_trackers['open']): ?>
            <?php render_locked($acc_trackers, $who_name, 'trackers', $level_name, $profile_id); ?>
        <?php elseif(!empty($followers_items)): ?>
            <?php render_open_notice($acc_trackers, $is_own_profile, $pv, 'trackers', $who_name, 'Trackers', $viewer_is_tracker, $followers_cut, $followers_total); ?>
            <p class="context-note">
                These are Dezzmers who track <?php echo $is_own_profile ? 'you' : $who_name; ?> — they see everything <?php echo $is_own_profile ? 'you post' : 'posted on this account'; ?>.
            </p>
            <?php render_user_rows($followers_items, $current_user, $conn, $track_request_states); ?>
            <div class="empty-state search-no-results" style="display:none;">
                <i class="ri-search-line"></i><span>No users found</span>
            </div>
        <?php else: ?>
            <?php render_open_notice($acc_trackers, $is_own_profile, $pv, 'trackers', $who_name, 'Trackers', $viewer_is_tracker, false); ?>
            <div class="empty-state">
                <i class="ri-user-follow-line"></i><span>No trackers yet</span>
            </div>
        <?php endif; ?>
    </div>

    <!-- ══ TRACKING LIST (Dezzmers only) ══ -->
    <div class="user-list<?php echo $active_tab === 'following' ? ' active' : ''; ?>" id="tab-following">
        <?php if(!$acc_tracking['open']): ?>
            <?php render_locked($acc_tracking, $who_name, 'tracking', $level_name, $profile_id); ?>
        <?php elseif(!empty($following_items)): ?>
            <?php render_open_notice($acc_tracking, $is_own_profile, $pv, 'tracking', $who_name, 'Tracking', $viewer_is_tracker, $following_cut, $following_total); ?>
            <?php render_user_rows($following_items, $current_user, $conn, $track_request_states); ?>
            <div class="empty-state search-no-results" style="display:none;">
                <i class="ri-search-line"></i><span>No users found</span>
            </div>
        <?php else: ?>
            <?php render_open_notice($acc_tracking, $is_own_profile, $pv, 'tracking', $who_name, 'Tracking', $viewer_is_tracker, false); ?>
            <div class="empty-state">
                <i class="ri-user-search-line"></i><span>Not tracking anyone yet</span>
            </div>
        <?php endif; ?>
    </div>

    <!-- ══ MUTUALS LIST (other profiles only) — never private ══ -->
    <?php if(isset($tabs['mutuals'])): ?>
    <div class="user-list<?php echo $active_tab === 'mutuals' ? ' active' : ''; ?>" id="tab-mutuals">
        <p class="context-note">
            These are people you both track — your shared connections with <?php echo $who_name; ?>.
        </p>
        <?php render_user_rows($mutuals_items, $current_user, $conn, $track_request_states); ?>
        <div class="empty-state search-no-results" style="display:none;">
            <i class="ri-search-line"></i><span>No users found</span>
        </div>
    </div>
    <?php endif; ?>

    <!-- ══ CIRCLE LIST (own profile only) ══ -->
    <?php if(isset($tabs['circle'])): ?>
    <div class="user-list<?php echo $active_tab === 'circle' ? ' active' : ''; ?>" id="tab-circle">
        <p class="context-note">
            These are people you track who track you back — your mutual connections.
        </p>
        <?php render_user_rows($circle_items, $current_user, $conn, $track_request_states); ?>
        <div class="empty-state search-no-results" style="display:none;">
            <i class="ri-search-line"></i><span>No users found</span>
        </div>
    </div>
    <?php endif; ?>

    </div><!-- /.tk-track -->
    </div><!-- /.tk-pager -->

</div><!-- /.tk-lane -->

<script>
// ── CSRF token (from the <meta> tag) — sent with every POST ──
var CSRF_TOKEN = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

// ── Close (X) — goes back, or to the profile if there is no history ──
function tkClose(){
    if (document.referrer && document.referrer.indexOf(window.location.host) !== -1) history.back();
    else location.href = 'profile.php?user_id=<?php echo (int)$profile_id; ?>';
}

// ── Shuffle helpers ────────────────────────────────────────
function shuffleArray(arr){
    for (var i = arr.length - 1; i > 0; i--) {
        var j = Math.floor(Math.random() * (i + 1));
        var t = arr[i]; arr[i] = arr[j]; arr[j] = t;
    }
    return arr;
}
function shuffleUserRows(list){
    if (!list) return;
    var rows = Array.prototype.slice.call(list.querySelectorAll('.user-row'));
    if (rows.length <= 1) return;
    shuffleArray(rows);
    var anchor = list.querySelector('.search-no-results');
    rows.forEach(function(row){
        if (anchor) list.insertBefore(row, anchor);
        else list.appendChild(row);
    });
}

// ── Sliding tab underline ──────────────────────────────────
function moveTabIndicator(btn){
    var ind = document.getElementById('tkTabInd');
    if (!ind || !btn) return;
    ind.style.left  = btn.offsetLeft + 'px';
    ind.style.width = btn.offsetWidth + 'px';
    ind.classList.add('ready');
}
function syncIndicator(){
    moveTabIndicator(document.querySelector('.tab-btn.active'));
}
window.addEventListener('resize', syncIndicator);

// the search box is pointless on a hidden list
function syncSearchBox(){
    var b = document.querySelector('.tab-btn.active'), s = document.getElementById('tkSearch');
    if (s && b) s.classList.toggle('off', b.dataset.locked === '1');
}

// ── Page skeleton ──────────────────────────────────────────
window.addEventListener('load', function(){
    document.querySelectorAll('.user-list').forEach(shuffleUserRows);
    syncIndicator(); syncSearchBox();

    var sk = document.getElementById('page-skeleton');
    if (!sk) return;
    setTimeout(function(){
        sk.classList.add('hide');
        setTimeout(function(){ sk.remove(); }, 380);
    }, 280);
});
document.addEventListener('DOMContentLoaded', function(){ syncIndicator(); syncSearchBox(); });

// ── PAGER: tabs bar + live finger-following swipe ──────────
var pager  = document.getElementById('tkPager');
var track  = document.getElementById('tkTrack');
var panels = Array.prototype.slice.call(track.querySelectorAll('.user-list'));
var tabBtns = Array.prototype.slice.call(document.querySelectorAll('.tab-btn'));
var tabInd  = document.getElementById('tkTabInd');
var curIdx  = Math.max(0, tabBtns.findIndex(function(b){ return b.classList.contains('active'); }));
var pageW   = pager.clientWidth;
var settleTimer = null;

function setX(x, animate){
    track.style.transition = animate ? 'transform .4s cubic-bezier(.22,1,.36,1)' : 'none';
    track.style.transform  = 'translate3d(' + x + 'px,0,0)';
}

function indicatorAt(p){
    if (!tabInd || !tabBtns.length) return;
    p = Math.max(0, Math.min(tabBtns.length - 1, p));
    var i = Math.floor(p), f = p - i, a = tabBtns[i], b = tabBtns[Math.min(i + 1, tabBtns.length - 1)];
    tabInd.style.left  = (a.offsetLeft  + (b.offsetLeft  - a.offsetLeft)  * f) + 'px';
    tabInd.style.width = (a.offsetWidth + (b.offsetWidth - a.offsetWidth) * f) + 'px';
    tabInd.classList.add('ready');
}

function finishSettle(prev){
    clearTimeout(settleTimer);
    pager.classList.add('idle');
    pager.classList.remove('dragging');
    if (prev !== curIdx && panels[prev]) shuffleUserRows(panels[prev]);
}

function goTo(i, animate){
    i = Math.max(0, Math.min(panels.length - 1, i));
    var prev = curIdx;
    curIdx = i;

    tabBtns.forEach(function(b, k){ b.classList.toggle('active', k === i); });
    panels.forEach(function(l, k){ l.classList.toggle('active', k === i); });
    syncSearchBox();

    if (prev !== i){
        var s = document.getElementById('user-search');
        if (s && s.value){ s.value = ''; filterUsers(); }
    }

    pager.classList.remove('idle');
    tabInd.classList.remove('live');
    moveTabIndicator(tabBtns[i]);
    try { tabBtns[i].scrollIntoView({ inline:'nearest', block:'nearest' }); } catch(e){}

    pageW = pager.clientWidth;
    setX(-i * pageW, animate);

    clearTimeout(settleTimer);
    if (animate) settleTimer = setTimeout(function(){ finishSettle(prev); }, 440);
    else finishSettle(prev);
}

tabBtns.forEach(function(btn, i){
    btn.addEventListener('click', function(){ if (i !== curIdx) goTo(i, true); });
});

window.addEventListener('resize', function(){
    pageW = pager.clientWidth;
    setX(-curIdx * pageW, false);
    moveTabIndicator(tabBtns[curIdx]);
});

(function(){
    var st = null;
    var swallowClick = false;

    document.addEventListener('click', function(e){
        if (swallowClick){ e.stopPropagation(); e.preventDefault(); }
    }, true);

    pager.addEventListener('pointerdown', function(e){
        if (e.pointerType === 'mouse' && e.button !== 0) return;
        if (e.target.closest && e.target.closest('input')) return;
        if (panels.length < 2) return;
        st = { id:e.pointerId, x:e.clientX, y:e.clientY, lock:null, samples:[{ x:e.clientX, t:performance.now() }] };
    });

    pager.addEventListener('pointermove', function(e){
        if (!st || e.pointerId !== st.id) return;
        var dx = e.clientX - st.x, dy = e.clientY - st.y;

        if (!st.lock){
            if (Math.abs(dx) < 6 && Math.abs(dy) < 6) return;
            if (Math.abs(dy) > Math.abs(dx)){ st = null; return; }
            st.lock = 'x';
            clearTimeout(settleTimer);
            pageW = pager.clientWidth;
            pager.classList.remove('idle');
            pager.classList.add('dragging');
            tabInd.classList.add('live');
            try { pager.setPointerCapture(e.pointerId); } catch(err){}
            var m = /translate3d\((-?[\d.]+)px/.exec(track.style.transform);
            st.base = m ? parseFloat(m[1]) : -curIdx * pageW;
            st.x = e.clientX; dx = 0;
        }

        var x = st.base + dx, minX = -(panels.length - 1) * pageW;
        if (x > 0)     x = x * 0.35;
        if (x < minX)  x = minX + (x - minX) * 0.35;
        setX(x, false);
        indicatorAt(-x / pageW);

        var now = performance.now();
        st.samples.push({ x:e.clientX, t:now });
        while (st.samples.length > 2 && now - st.samples[0].t > 110) st.samples.shift();
    });

    function release(e){
        if (!st || e.pointerId !== st.id) return;
        var g = st; st = null;
        if (g.lock !== 'x') return;

        try { pager.releasePointerCapture(e.pointerId); } catch(err){}
        swallowClick = true;
        setTimeout(function(){ swallowClick = false; }, 60);

        var dx = e.clientX - g.x;
        var a = g.samples[0], b = g.samples[g.samples.length - 1];
        var v = (b.t - a.t) > 10 ? (b.x - a.x) / (b.t - a.t) : 0;

        var target = curIdx;
        if (Math.abs(v) > 0.35)                  target = curIdx + (v < 0 ? 1 : -1);
        else if (Math.abs(dx) > pageW * 0.22)    target = curIdx + (dx < 0 ? 1 : -1);
        goTo(target, true);
    }
    pager.addEventListener('pointerup', release);
    pager.addEventListener('pointercancel', release);
})();

// ── Search / filter (by username / nickname) ──
function filterUsers(){
    var query      = document.getElementById('user-search').value.trim().toLowerCase();
    var activeList = document.querySelector('.user-list.active');
    if (!activeList) return;
    var rows = activeList.querySelectorAll('.user-row');
    var visible = 0;
    rows.forEach(function(row){
        var un = row.querySelector('.un-nm') || row.querySelector('.uname'), nk = row.querySelector('.unick');
        var uname = (un ? un.textContent : '').toLowerCase();
        var unick = (nk ? nk.textContent : '').toLowerCase();
        var match = uname.indexOf(query) > -1 || unick.indexOf(query) > -1;
        row.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    var noRes = activeList.querySelector('.search-no-results');
    if (noRes) noRes.style.display = (query && visible === 0 && rows.length > 0) ? 'flex' : 'none';
}

// ── Track button — identical states to the profile page ────
var BTN_STATES = {
    follow:         { cls: 'track',   txt: 'Track' },
    untrack:        { cls: 'untrack', txt: 'Untrack' },
    send_request:   { cls: 'track',   txt: 'Request to Track' },
    cancel_request: { cls: 'pending', txt: 'Requested' }
};
function paintBtn(btn, mode){
    var s = BTN_STATES[mode];
    if (!s) return;
    btn.className = 'tk-btn ' + s.cls;
    btn.textContent = s.txt;
    btn.dataset.mode = mode;
    btn.style.minWidth = '';
    btn.disabled = false;
}

function handleTrackBtn(btn, targetId){
    if (btn.disabled) return;
    var mode = btn.dataset.mode;
    var body;

    if (mode === 'follow' || mode === 'untrack') body = 'action=toggle_follow&user_id=' + targetId;
    else if (mode === 'send_request')            body = 'action=send_track_request&user_id=' + targetId;
    else if (mode === 'cancel_request')          body = 'action=cancel_track_request&user_id=' + targetId;
    else return;

    // 🔒 CSRF — token goes in the body AND the header
    body += '&csrf_token=' + encodeURIComponent(CSRF_TOKEN);

    btn.style.minWidth = btn.offsetWidth + 'px';
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-icon"><span></span><span></span><span></span><span></span></span>';

    fetch('tracks.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': CSRF_TOKEN
        },
        credentials: 'same-origin',
        body: body
    })
    .then(function(r){ return r.json().catch(function(){ return { status:'error' }; }); })
    .then(function(data){
        if (data.msg === 'csrf'){
            paintBtn(btn, mode);
            location.reload();
            return;
        }
        if (data.status === 'ok'){
            if (mode === 'follow')              paintBtn(btn, 'untrack');
            else if (mode === 'untrack')        paintBtn(btn, data.is_private ? 'send_request' : 'follow');
            else if (mode === 'send_request')   paintBtn(btn, 'cancel_request');
            else if (mode === 'cancel_request') paintBtn(btn, 'send_request');
        } else if (data.is_private && mode === 'follow'){
            paintBtn(btn, 'send_request');
        } else {
            paintBtn(btn, mode);
        }
    })
    .catch(function(){ paintBtn(btn, mode); });
}
</script>
</body>
</html>