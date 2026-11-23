<?php
/* ═══════════════════════════════════════════════════════
   NAV.PHP — Standalone reusable Dezzme sidebar
   (identical to the sidebar in community.php)

   Include this file right after <body> on any page:

       <?php include 'nav.php'; ?>

   It handles its own session + profile-pic lookup, so it
   works whether or not the parent page already did that.

   LIGHT MODE: the colours come from the theme variables in theme_css.php
   (--lane, --fg, --ui-text, --border). Every one has the old dark colour as its
   fallback, so in dark mode the bar looks exactly as before, and on a page that
   doesn't load the theme it still works.

   HAPTICS: every touch on a bar button gives a MEDIUM vibration the moment the finger lands.
   Holding the Games slot buzzes a little stronger when the menu opens, and choosing a button in
   that menu gives a medium tap. It uses the page's own dzHaptic.medium() when the page has it
   (same feel as the home page), otherwise navigator.vibrate. It follows the Haptics switch in
   Your Algorithm > Feed controls (saved on this device as dz_fx): switch that off and the bar is silent too.
   ═══════════════════════════════════════════════════════ */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Only include config.php if the parent page hasn't already
// established a DB connection as $conn.
if (!isset($conn)) {
    include 'config.php';
}

$current_user = (int)($_SESSION['user_id'] ?? 0);

// ── CSRF token for the hold-menu save (one random token per session) ──
if (empty($_SESSION['dz_csrf']) || !is_string($_SESSION['dz_csrf'])) {
    $_SESSION['dz_csrf'] = bin2hex(random_bytes(32));
}
$nav_csrf = $_SESSION['dz_csrf'];

// ── Allowed values for the Games / Messages / Memepedia / Dayleez / Your Algorithm slot ──
$nav_valid_slots = ['games', 'messages', 'memepedia', 'dayleez', 'algorithm', 'zeems'];

// ── Save endpoint: the hold menu POSTs the chosen button here ──
// Only answers when nav.php itself is requested, never when it is included in a page.
if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'nav.php'
    && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && isset($_POST['setslot'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');

    // CSRF check. The token comes from the POST body (csrf) or the X-CSRF-Token header.
    // Turn on  define('DZ_CSRF_ENFORCE', true);  (e.g. in config.php) to REJECT requests with a bad or missing token.
    // While it is off (or not defined) a bad token is only written to the PHP error log, so nothing breaks during rollout.
    $nav_sent = (string)($_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    $nav_csrf_ok = $nav_sent !== '' && hash_equals($nav_csrf, $nav_sent);
    if (!$nav_csrf_ok) {
        error_log('nav.php setslot: missing/invalid CSRF token (user ' . $current_user . ')');
        if (defined('DZ_CSRF_ENFORCE') && DZ_CSRF_ENFORCE) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'csrf']);
            exit;
        }
    }

    $ok = false;
    $k  = (string)$_POST['setslot'];
    if ($current_user && in_array($k, $nav_valid_slots, true)) {
        try {
            $st = $conn->prepare("UPDATE users SET nav_slot=? WHERE id=? LIMIT 1");
            $st->bind_param('si', $k, $current_user);
            $ok = $st->execute();
            $st->close();
        } catch (Throwable $e) { $ok = false; }
    }
    echo json_encode(['ok' => $ok]);
    exit;
}

// ── Pages where the nav must be hidden on MOBILE only ──
// (desktop still shows the sidebar on these pages)
$nav_hidden_on_mobile_pages = ['upload.php'];
$nav_current_page = basename($_SERVER['SCRIPT_NAME'] ?? '');
$nav_hide_mobile  = in_array($nav_current_page, $nav_hidden_on_mobile_pages, true);

// ── Pages where the nav must be hidden on DESKTOP only ──
// (mobile still shows the bottom bar on these pages)
$nav_hidden_on_desktop_pages = ['messages.php'];
$nav_hide_desktop = in_array($nav_current_page, $nav_hidden_on_desktop_pages, true);

// ── Which button is "selected" (shows the filled icon) ──
// Profile has no filled icon: its picture ring turns red instead (see $nav_profile_active).
$nav_active = function (string $page) use ($nav_current_page): string {
    return $nav_current_page === $page ? ' is-active' : '';
};

// ── NAV PIC (profile icon on desktop + mobile bottom bar) + own identity (for the red ring check) ──
$nav_pic = 'mee.jpg';
$nav_me_username = '';
$nav_me_dzid     = '';
$nav_susp        = false;   // suspended account → only the profile-picture button is shown
if ($current_user) {
    try {
        $nav_row = $conn->query("SELECT profile_pic, username, dz_id, user_rank FROM users WHERE id=$current_user LIMIT 1")->fetch_assoc();
    } catch (Throwable $e) {
        $nav_row = $conn->query("SELECT profile_pic, username, dz_id FROM users WHERE id=$current_user LIMIT 1")->fetch_assoc();
    }
    $nav_susp = strtolower(trim((string)($nav_row['user_rank'] ?? ''))) === 'suspended';
    if (!empty($nav_row['profile_pic'])) {
        $nav_pic = $nav_row['profile_pic'];
    }
    $nav_me_username = strtolower(ltrim((string)($nav_row['username'] ?? ''), '@'));
    $nav_me_dzid     = strtolower(ltrim((string)($nav_row['dz_id'] ?? ''), '@'));
}

// ── Profile button: red ring ONLY on your OWN profile page ──
// If profile.php was opened for another user (?id=, ?user_id=, ?uid=, ?u=, ?user=,
// ?username=, ?dz_id=, ?profile=) and that value is not you, the ring stays white.
$nav_profile_active = '';
if ($nav_current_page === 'profile.php' && $current_user) {
    $nav_is_own = true;
    foreach (['id', 'user_id', 'uid', 'u', 'user', 'username', 'dz_id', 'profile'] as $nav_param) {
        if (!isset($_GET[$nav_param]) || is_array($_GET[$nav_param])) { continue; }
        $nav_val = strtolower(trim(ltrim((string)$_GET[$nav_param], '@')));
        if ($nav_val === '') { continue; }
        $nav_match = ctype_digit($nav_val)
            ? ((int)$nav_val === $current_user)
            : ($nav_val === $nav_me_username || $nav_val === $nav_me_dzid);
        if (!$nav_match) { $nav_is_own = false; break; }
    }
    if ($nav_is_own) { $nav_profile_active = ' is-profile-active'; }
}

// ── Saved slot choice from the database (follows the account across logouts and devices) ──
$nav_db_slot = null;
if ($current_user) {
    try {
        $r = $conn->query("SELECT nav_slot FROM users WHERE id=$current_user LIMIT 1");
        if ($r) {
            $row = $r->fetch_assoc();
            if (!empty($row['nav_slot']) && in_array($row['nav_slot'], $nav_valid_slots, true)) {
                $nav_db_slot = $row['nav_slot'];
            }
        }
    } catch (Throwable $e) { /* column not added yet: fall back to the cookie */ }
}

// ── Games / Messages / Memepedia / Dayleez slot (server-rendered so nothing flickers on page load) ──
$nav_slots = [
    'games' => [
        'href'  => 'games.php',
        'label' => 'Games',
        'bold'  => '<svg class="nav-ph nav-games ico-bold" viewBox="0 0 512 512" fill="currentColor" aria-hidden="true"><path d="M467.51 248.83c-18.4-83.18-45.69-136.24-89.43-149.17A91.5 91.5 0 0 0 352 96c-26.89 0-48.11 16-96 16s-69.15-16-96-16a99 99 0 0 0-27.2 3.66C89 112.59 61.94 165.7 43.33 248.83c-19 84.91-15.56 152 21.58 164.88 26 9 49.25-9.61 71.27-37 25-31.2 55.79-40.8 119.82-40.8s93.62 9.6 118.66 40.8c22 27.41 46.11 45.79 71.42 37.16 41.02-14.01 40.44-79.13 21.43-165.04Z" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="42"/><circle cx="292" cy="224" r="20"/><path d="M336 288a20 20 0 1 1 20-19.95A20 20 0 0 1 336 288"/><circle cx="336" cy="180" r="20"/><circle cx="380" cy="224" r="20"/><path d="M160 176v96M208 224h-96" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="42"/></svg>',
        'fill'  => '<svg class="nav-ph nav-games ico-fill" viewBox="0 0 512 512" fill="currentColor" aria-hidden="true"><path d="M483.13 245.38C461.92 149.49 430 98.31 382.65 84.33A107.1 107.1 0 0 0 352 80c-13.71 0-25.65 3.34-38.28 6.88C298.5 91.15 281.21 96 256 96s-42.51-4.84-57.76-9.11C185.6 83.34 173.67 80 160 80a115.7 115.7 0 0 0-31.73 4.32c-47.1 13.92-79 65.08-100.52 161C4.61 348.54 16 413.71 59.69 428.83a56.6 56.6 0 0 0 18.64 3.22c29.93 0 53.93-24.93 70.33-45.34 18.53-23.1 40.22-34.82 107.34-34.82 59.95 0 84.76 8.13 106.19 34.82 13.47 16.78 26.2 28.52 38.9 35.91 16.89 9.82 33.77 12 50.16 6.37 25.82-8.81 40.62-32.1 44-69.24 2.57-28.48-1.39-65.89-12.12-114.37M208 240h-32v32a16 16 0 0 1-32 0v-32h-32a16 16 0 0 1 0-32h32v-32a16 16 0 0 1 32 0v32h32a16 16 0 0 1 0 32m84 4a20 20 0 1 1 20-20 20 20 0 0 1-20 20m44 44a20 20 0 1 1 20-19.95A20 20 0 0 1 336 288m0-88a20 20 0 1 1 20-20 20 20 0 0 1-20 20m44 44a20 20 0 1 1 20-20 20 20 0 0 1-20 20"/></svg>',
    ],
    'messages' => [
        'href'  => 'messages.php',
        'label' => 'Messages',
        'bold'  => '<svg class="nav-ph ico-bold" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 9h8"/><path d="M8 13h6"/><path d="M18 4a3 3 0 0 1 3 3v8a3 3 0 0 1 -3 3h-5l-5 3v-3h-2a3 3 0 0 1 -3 -3v-8a3 3 0 0 1 3 -3h12"/></svg>',
        'fill'  => '<svg class="nav-ph ico-fill" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M18 3a4 4 0 0 1 4 4v8a4 4 0 0 1 -4 4h-4.724l-4.762 2.857a1 1 0 0 1 -1.508 -.743l-.006 -.114v-2h-1a4 4 0 0 1 -3.995 -3.8l-.005 -.2v-8a4 4 0 0 1 4 -4zm-4 9h-6a1 1 0 0 0 0 2h6a1 1 0 0 0 0 -2m2 -4h-8a1 1 0 1 0 0 2h8a1 1 0 0 0 0 -2"/></svg>',
    ],
    'memepedia' => [
        'href'  => 'memepedia.php',
        'label' => 'Memepedia',
        'bold'  => '<svg class="nav-ph ico-bold" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true"><path d="M232,44H160a43.86,43.86,0,0,0-32,13.85A43.86,43.86,0,0,0,96,44H24A12,12,0,0,0,12,56V200a12,12,0,0,0,12,12H96a20,20,0,0,1,20,20,12,12,0,0,0,24,0,20,20,0,0,1,20-20h72a12,12,0,0,0,12-12V56A12,12,0,0,0,232,44ZM96,188H36V68H96a20,20,0,0,1,20,20V192.81A43.79,43.79,0,0,0,96,188Zm124,0H160a43.71,43.71,0,0,0-20,4.83V88a20,20,0,0,1,20-20h60Z"/></svg>',
        'fill'  => '<svg class="nav-ph ico-fill" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true"><path d="M240,56V200a8,8,0,0,1-8,8H160a24,24,0,0,0-24,23.94,7.9,7.9,0,0,1-5.12,7.55A8,8,0,0,1,120,232a24,24,0,0,0-24-24H24a8,8,0,0,1-8-8V56a8,8,0,0,1,8-8H88a32,32,0,0,1,32,32v87.73a8.17,8.17,0,0,0,7.47,8.25,8,8,0,0,0,8.53-8V80a32,32,0,0,1,32-32h64A8,8,0,0,1,240,56Z"/></svg>',
    ],
    // Dayleez: history-toggle icon (dashed circle clock)
    'dayleez' => [
        'href'  => 'dayleez.php',
        'label' => 'Dayleez',
        'bold'  => '<svg class="nav-ph ico-bold" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 20.777a9 9 0 0 1-2.48-.969M14 3.223a9.003 9.003 0 0 1 0 17.554m-9.421-3.684a9 9 0 0 1-1.227-2.592M3.124 10.5c.16-.95.468-1.85.9-2.675l.169-.305m2.714-2.941A9 9 0 0 1 10 3.223"/><path d="M12 8v4l3 3"/></svg>',
        'fill'  => '<svg class="nav-ph ico-fill" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 20.777a9 9 0 0 1-2.48-.969M14 3.223a9.003 9.003 0 0 1 0 17.554m-9.421-3.684a9 9 0 0 1-1.227-2.592M3.124 10.5c.16-.95.468-1.85.9-2.675l.169-.305m2.714-2.941A9 9 0 0 1 10 3.223"/><path d="M12 8v4l3 3"/></svg>',
    ],
    // Zeems: the full-screen video player page (zeems.php)
    'zeems' => [
        'href'  => 'zeems.php',
        'label' => 'Zeems',
        'bold'  => '<svg class="nav-ph ico-bold" viewBox="0 0 256 256" fill="none" stroke="currentColor" stroke-width="24" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M94 24H162a30 30 0 0 1 30 30V202a30 30 0 0 1 -30 30H94a30 30 0 0 1 -30 -30V54a30 30 0 0 1 30 -30Z"/><path d="M113.8 98.0Q105.2 93.0 105.2 103.0L105.2 153.0Q105.2 163.0 113.8 158.0L156.6 133.0Q165.2 128.0 156.6 123.0Z" fill="currentColor" stroke="none"/></svg>',
        'fill'  => '<svg class="nav-ph ico-fill" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M92 12H164a40 40 0 0 1 40 40V204a40 40 0 0 1 -40 40H92a40 40 0 0 1 -40 -40V52a40 40 0 0 1 40 -40ZM113.8 98.0Q105.2 93.0 105.2 103.0L105.2 153.0Q105.2 163.0 113.8 158.0L156.6 133.0Q165.2 128.0 156.6 123.0Z"/></svg>',
    ],
    // Your Algorithm: sliders icon, opens the algorithm settings page
    'algorithm' => [
        'href'  => 'algorithm_settings.php',
        'label' => 'Your Algorithm',
        'bold'  => '<svg class="nav-ph ico-bold" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="14" cy="6" r="2"/><path d="M4 6h8M16 6h4"/><circle cx="8" cy="12" r="2"/><path d="M4 12h2M10 12h10"/><circle cx="17" cy="18" r="2"/><path d="M4 18h11M19 18h1"/></svg>',
        'fill'  => '<svg class="nav-ph ico-fill" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="14" cy="6" r="2.6" fill="currentColor"/><path d="M4 6h8M16 6h4"/><circle cx="8" cy="12" r="2.6" fill="currentColor"/><path d="M4 12h2M10 12h10"/><circle cx="17" cy="18" r="2.6" fill="currentColor"/><path d="M4 18h11M19 18h1"/></svg>',
    ],
];

// Signed-in users: the database decides.  Signed-out visitors (or no saved choice yet): the cookie.
$nav_slot_key = $nav_db_slot ?? ($_COOKIE['dz_nav_slot'] ?? 'games');
if (!isset($nav_slots[$nav_slot_key])) { $nav_slot_key = 'games'; }
$nav_slot = $nav_slots[$nav_slot_key];

// ── Where the slot (Games) and Pods buttons go ──
// Signed-out visitors are sent to index.php instead.
$nav_logged_in  = $current_user > 0;
$nav_slot_href  = $nav_logged_in ? $nav_slot['href'] : 'index.php';
$nav_pods_href  = $nav_logged_in ? 'community.php'   : 'index.php';

// ── Curved refresh arrow (shown on Home / Text Feed / Pods when tapped a second time on the same page) ──
$nav_refresh_svg = '<svg class="nav-ph nav-refresh-ico" viewBox="0 0 256 256" fill="currentColor" stroke="currentColor" stroke-width="10" stroke-linejoin="round" aria-hidden="true"><path d="M240,56v48a8,8,0,0,1-8,8H184a8,8,0,0,1,0-16H211.4L184.81,71.64l-.25-.24a80,80,0,1,0-1.67,114.78,8,8,0,0,1,11,11.63A95.44,95.44,0,0,1,128,224h-1.32A96,96,0,1,1,195.75,60L224,85.8V56a8,8,0,1,1,16,0Z"/></svg>';

// ── NEW-MESSAGE COUNT (badge on the Messages button) ──
$nav_unread = 0;
if ($current_user) {
    $nav_count = function (string $sql, string $fallback) use ($conn): int {
        foreach ([$sql, $fallback] as $q) {
            try { $r = $conn->query($q); if ($r) return (int)$r->fetch_assoc()['n']; } catch (Throwable $e) {}
        }
        return 0;
    };
    $nav_unread += $nav_count(
        "SELECT COUNT(*) n FROM messages WHERE receiver_id=$current_user AND seen=0
           AND sender_id NOT IN (SELECT other_id FROM ms_chat_mutes WHERE user_id=$current_user)",
        "SELECT COUNT(*) n FROM messages WHERE receiver_id=$current_user AND seen=0");
    $nav_unread += $nav_count(
        "SELECT COUNT(*) n FROM msg_gc_messages gm
           JOIN msg_gc_members mem ON mem.group_id=gm.group_id AND mem.user_id=$current_user
           LEFT JOIN msg_gc_reads gr ON gr.group_id=gm.group_id AND gr.user_id=$current_user
          WHERE gm.sender_id<>$current_user AND gm.kind=0 AND gm.id>COALESCE(gr.last_read_id,0)
            AND gm.group_id NOT IN (SELECT -other_id FROM ms_chat_mutes WHERE user_id=$current_user AND other_id<0)",
        "SELECT 0 n");
}

// ── NEW-DAYLEEZ COUNT (red dot on the Dayleez button) ──
// Live Dayleez from people you track (not yours, not blocked) that you have not watched yet.
$nav_dl_unseen = 0;
if ($current_user) {
    foreach ([
        "SELECT COUNT(*) n FROM stories s
          WHERE s.status='active' AND s.expires_at>NOW()
            AND s.user_id<>$current_user
            AND s.user_id IN (SELECT following_id FROM followers WHERE follower_id=$current_user)
            AND NOT EXISTS (SELECT 1 FROM blocks bk WHERE (bk.blocker_id=s.user_id AND bk.blocked_id=$current_user)
                                                        OR (bk.blocker_id=$current_user AND bk.blocked_id=s.user_id))
            AND NOT EXISTS (SELECT 1 FROM story_views sv WHERE sv.story_id=s.id AND sv.viewer_id=$current_user)",
        "SELECT 0 n"
    ] as $q) {
        try { $r = $conn->query($q); if ($r) { $nav_dl_unseen = (int)$r->fetch_assoc()['n']; break; } } catch (Throwable $e) {}
    }
}

// ── Should the dot show on the slot button right now? ──
$nav_badge_on = ($nav_slot_key === 'messages' && $nav_unread > 0     && $nav_current_page !== 'messages.php')
             || ($nav_slot_key === 'dayleez'  && $nav_dl_unseen > 0 && $nav_current_page !== 'dayleez.php');

// ── Live-count endpoint (the badge polls  nav.php?unread=1  every 20s) ──
// Only answers when nav.php itself is requested, never when it is included in a page.
if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'nav.php' && isset($_GET['unread'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['n' => $nav_unread, 'd' => $nav_dl_unseen]);
    exit;
}
?>
<style>
/* ══════════════════════════════════════
   SIDEBAR — left rail on desktop, bottom bar on mobile
   (colours: var(--theme-variable, dark-mode colour); light mode is fully self-contained at the bottom)
══════════════════════════════════════ */
.sidebar {
    display: flex;
    position: fixed;
    left: 0; top: 0;
    width: 102px;
    height: 100vh;
    flex-direction: column;
    align-items: flex-start;
    gap: 30px;
    padding: 22px 23px;
    box-sizing: border-box;
    border-radius: 0;
    background: var(--lane, #070924);
    border: none;
    box-shadow: none;
    overflow: hidden;
    transition: width 0.25s ease;
    z-index: 9999;
}

/* ── Logo (rounded box) ── */
.logo {
    display: flex;
    align-items: center;
    width: 100%;
    cursor: pointer;
    flex-shrink: 0;
}

.logo-box {
    width: 55px;
    height: 55px;
    flex: 0 0 55px;
    border-radius: 15px;
    overflow: hidden;
    background: var(--lane, #070924);
    transition: .3s;
    animation: floatUpDown 2s ease-in-out infinite;
}

.logo-box svg {
    display: block;
    width: 100%;
    height: 100%;
}

.nav-logo-bg { fill: var(--lane, #070924); }
.nav-logo-fg { stroke: var(--ui-text, #fff); }

.logo:hover .logo-box {
    box-shadow: 0 0 15px rgba(var(--fg, 255,255,255), .75);
    transform: scale(1.1);
}

@keyframes floatUpDown {
    0%, 100% { transform: translateY(0); }
    50%       { transform: translateY(-5px); }
}

/* ── Nav buttons (desktop) ── */
.nav-icon {
    width: 56px;
    height: 44px;
    display: flex;
    align-items: center;
    justify-content: flex-start;
    flex-shrink: 0;
    border-radius: 12px;
    background: transparent;
    color: var(--ui-text, white);
    text-decoration: none;
    transition: background 0.25s ease, transform 0.25s ease;
    cursor: pointer;
    position: relative;
    overflow: hidden;
    -webkit-tap-highlight-color: transparent;
}

.nav-icon:hover {
    background: rgba(var(--fg, 255,255,255), 0.12);
}

.nav-icon:active {
    transform: scale(0.97);
}

.nav-ico {
    width: 56px;
    height: 44px;
    flex: 0 0 56px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.nav-icon i {
    font-size: 19.2px;
    color: var(--ui-text, white);
    line-height: 1;
    -webkit-text-stroke: 0.6px var(--ui-text, #fff);
    transition: transform 0.25s ease;
}

.nav-icon svg {
    width: 21.12px;
    height: 21.12px;
    color: var(--ui-text, white);
    transition: transform 0.25s ease;
}

.sidebar .nav-icon svg.nav-plus-svg {
    display: block !important;
    width: 30px !important;
    height: 30px !important;
    flex-shrink: 0;
    color: var(--ui-text, #fff) !important;
    fill: none !important;
    stroke: var(--ui-text, #fff) !important;
    stroke-width: 2.6 !important;
    opacity: 1 !important;
    visibility: visible !important;
}

@media (min-width: 769px) {
    .sidebar a.sidebar-desktop-only { display: flex !important; }
}

.nav-icon:hover i,
.nav-icon:hover svg,
.nav-icon:hover .nav-profile-pic {
    transform: scale(1.15);
}

/* ── Bold <-> Fill swap ── */
.nav-icon svg.nav-ph {
    width: 24px;
    height: 24px;
    flex-shrink: 0;
}

.nav-icon svg.nav-ph.nav-games {
    width: 27.84px;
    height: 27.84px;
}

.nav-icon .ico-fill { display: none; }

.nav-icon:active .ico-bold,
.nav-icon.is-active .ico-bold { display: none; }

.nav-icon:active .ico-fill,
.nav-icon.is-active .ico-fill { display: block; }

/* ── Refresh arrow ── */
.nav-icon .nav-refresh-ico { display: none; }

.nav-icon.is-refreshing .ico-bold,
.nav-icon.is-refreshing .ico-fill { display: none !important; }

.nav-icon.is-refreshing .nav-refresh-ico {
    display: block;
    animation: navRefreshSpin 0.7s cubic-bezier(.45,.05,.25,1) forwards;
}

@keyframes navRefreshSpin {
    0%   { transform: rotate(0deg)   scale(0.7); opacity: 0.3; }
    25%  { transform: rotate(90deg)  scale(1.1); opacity: 1; }
    100% { transform: rotate(360deg) scale(1);   opacity: 1; }
}

/* ── Search ── */
.nav-search-ring {
    stroke-width: 2.5;
    transition: stroke-width 0.15s ease;
}
.nav-search-handle {
    stroke: currentColor;
    stroke-width: 0;
    stroke-linejoin: round;
    transition: stroke-width 0.15s ease;
}
.nav-icon:active .nav-search-ring,
.nav-icon.is-active .nav-search-ring { stroke-width: 3.4; }
.nav-icon:active .nav-search-handle,
.nav-icon.is-active .nav-search-handle { stroke-width: 1.2; }

/* ── Name label ── */
.nav-label {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    font-size: 16px;
    font-weight: 600;
    color: var(--ui-text, #fff);
    white-space: nowrap;
    opacity: 0;
    transform: translateX(-6px);
    transition: opacity 0.2s ease, transform 0.25s ease;
    pointer-events: none;
}

/* Profile */
.nav-profile-icon { display: none !important; }

.nav-profile-pic {
    display: block;
    width: 25.92px;
    height: 25.92px;
    min-width: 25.92px;
    min-height: 25.92px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid var(--ui-text, #ffffff);
    overflow: hidden;
    flex-shrink: 0;
    transition: transform 0.25s ease, border-color 0.2s ease, box-shadow 0.2s ease;
}

.nav-profile-link.is-profile-active .nav-profile-pic,
.nav-profile-link:active .nav-profile-pic {
    border-color: #ff2d2d;
    border-width: 1.9px;
    box-shadow: 0 0 0 0.71px #ff2d2d;
}
.nav-profile-link.is-profile-active .nav-label { color: #ff2d2d; }

/* ── desktop: expand the bar on hover, show the names ── */
@media (min-width: 769px) {
    body { padding-left: 102px; }

    .sidebar { border-right: 1px solid #23263f; }

    .sidebar:hover {
        width: 250px;
    }

    .sidebar:hover .nav-icon {
        width: 204px;
    }

    .sidebar:hover .nav-label {
        opacity: 1;
        transform: translateX(0);
    }
}

@media (max-width: 768px) {

    body { padding-bottom: calc(64px + env(safe-area-inset-bottom, 0px)); }

    .sidebar {
        display: flex;
        position: fixed;
        bottom: 0; left: 0; right: 0; top: auto;
        width: 100%;
        height: calc(52px + env(safe-area-inset-bottom, 0px));
        flex-direction: row;
        justify-content: space-evenly;
        align-items: center;
        padding: 0 0 env(safe-area-inset-bottom, 0px) 0;
        box-sizing: border-box;
        gap: 0;
        border-radius: 0;
        overflow: visible;
        border-top: 1px solid rgba(255,255,255,0.08);
        background: #070924;
        backdrop-filter: none;
        -webkit-backdrop-filter: none;
        box-shadow: none;
    }

    .sidebar .logo { display: none; }
    .sidebar-desktop-only { display: none !important; }

    .nav-icon {
        flex: 0 0 auto;
        width: 45px;
        height: 52px;
        justify-content: center;
        border-radius: 0;
        border: none;
        background: transparent;
        box-shadow: none;
    }

    .nav-ico {
        width: 45px;
        height: 52px;
        flex: 0 0 45px;
    }

    .nav-label { display: none; }

    .nav-icon i {
        font-size: 20.99px;
        -webkit-text-stroke: 0.7px var(--ui-text, #fff);
    }

    .nav-icon svg {
        width: 26.23px;
        height: 26.23px;
    }

    .nav-icon svg.nav-ph {
        width: 28px;
        height: 28px;
    }

    .nav-icon svg.nav-ph.nav-games {
        width: 31.68px;
        height: 31.68px;
    }

    .nav-icon:hover {
        transform: none;
        background: rgba(var(--fg, 255,255,255), 0.05);
        box-shadow: none;
    }

    .nav-icon:hover i,
    .nav-icon:hover svg,
    .nav-icon:hover .nav-profile-pic {
        transform: none;
    }

    .nav-icon:active {
        transform: scale(0.88);
        opacity: 0.6;
    }

    .nav-profile-pic {
        width: 31.48px;
        height: 31.48px;
        min-width: 31.48px;
        min-height: 31.48px;
    }
}

<?php if ($nav_hide_desktop): ?>
/* ── this page: hide the nav on DESKTOP only (mobile bottom bar unchanged) ── */
@media (min-width: 769px) {
    .sidebar { display: none !important; }
    body { padding-left: 0 !important; }
}
<?php endif; ?>

<?php if ($nav_hide_mobile): ?>
/* ── this page: hide the nav on MOBILE only (desktop unchanged) ── */
@media (max-width: 768px) {
    .sidebar { display: none !important; }
    body { padding-bottom: 0 !important; }
}
<?php endif; ?>

/* no iOS link-preview / text-select / tap flash when a bar button is pressed or held */
.sidebar a, .sidebar a * {
    -webkit-touch-callout: none;
    -webkit-user-select: none;
    user-select: none;
    -webkit-user-drag: none;
    -webkit-tap-highlight-color: transparent;
}

/* ── Hold-to-switch slot ── */
#dzSlot {
    -webkit-touch-callout: none;
    -webkit-user-select: none;
    user-select: none;
    touch-action: manipulation;
}

.dz-menu {
    position: fixed;
    z-index: 10000;
    min-width: 190px;
    padding: 6px;
    --dz-bg: #0a0d2c;
    --dz-bd: #1f2557;
    background: var(--dz-bg);
    border: 1px solid var(--dz-bd);
    border-radius: 10px;
    box-shadow: 0 12px 36px rgba(0,0,0,0.55);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    opacity: 0;
    transform: scale(0.94);
    transform-origin: left center;
    transition: opacity .14s ease, transform .14s ease;
}
.dz-menu.show { opacity: 1; transform: scale(1); }
.dz-menu .dz-title {
    padding: 8px 12px 6px;
    font-size: 11px;
    letter-spacing: .06em;
    text-transform: uppercase;
    color: #8f95c9;
}
.dz-menu button {
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
    min-height: 46px;
    padding: 0 12px;
    background: transparent;
    border: 0;
    border-radius: 7px;
    color: #fff;
    font: 600 15px/1 inherit;
    font-family: inherit;
    text-align: left;
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
}
.dz-menu button:hover { background: rgba(255,255,255,0.10); }
.dz-menu button[aria-checked="true"] { background: rgba(255,255,255,0.14); }
.dz-menu button svg { width: 22px; height: 22px; flex: 0 0 22px; }
.dz-menu .dz-check { margin-left: auto; font-size: 13px; color: #ffc857; }

@media (max-width: 768px) {
    /* the menu is the bottom bar's twin: same colour and border, no shadow, floating just above it with a gap on every side.
       Three choices on the left, three on the right. */
    .dz-menu {
        --dz-bg: #070924;
        --dz-bd: rgba(255,255,255,0.08);
        left: 10px !important; right: 10px !important;
        width: auto;
        min-width: 0;
        box-sizing: border-box;
        display: grid;
        grid-template-columns: 1fr 1fr;
        column-gap: 0;
        padding: 4px 10px 6px;
        border: 1px solid var(--dz-bd);
        border-radius: 18px;
        box-shadow: none;
        transform: translateY(10px);
        transform-origin: bottom center;
    }
    .dz-menu.show { transform: none; }
    .dz-menu .dz-title { grid-column: 1 / -1; grid-row: 1; font-size: 12px; padding: 9px 13px 5px; text-align: center; }
    .dz-menu button {
        min-height: 50px;
        gap: 14px;
        padding: 0 14px;
        border-radius: 8px;
        font-size: 16px;
    }
    .dz-menu button:nth-of-type(1) { grid-column: 1; grid-row: 2; }
    .dz-menu button:nth-of-type(2) { grid-column: 1; grid-row: 3; }
    .dz-menu button:nth-of-type(3) { grid-column: 1; grid-row: 4; }
    .dz-menu button:nth-of-type(4) { grid-column: 2; grid-row: 2; }
    .dz-menu button:nth-of-type(5) { grid-column: 2; grid-row: 3; }
    .dz-menu button:nth-of-type(6) { grid-column: 2; grid-row: 4; }
    .dz-menu button svg { width: 24px; height: 24px; flex: 0 0 24px; }
    .dz-menu .dz-check { font-size: 14px; }
}

/* ── Unread dot ── */
.nav-badge{position:absolute;top:9px;left:32px;width:11px;height:11px;padding:0;box-sizing:border-box;
  border-radius:50%;background:#FF2D2D;box-shadow:0 0 0 2px var(--lane,#070924);pointer-events:none;z-index:2}
.nav-badge[hidden]{display:none}
/* red dot at the bottom-right of the profile picture: you have a new notification.
   It is a <span> (not <i>) so the ".nav-icon i" icon rules (font size, text stroke, hover scale) never touch it. */
.nav-pic-wrap{position:relative;display:block;line-height:0}
.nav-pic-wrap .nav-notif-dot{position:absolute;right:-3px;bottom:-3px;width:10.45px;height:10.45px;border-radius:50%;background:#FF2D2D;box-shadow:0 0 0 2.06px var(--lane,#070924);pointer-events:none;display:block;z-index:5;transform:none !important}
.nav-pic-wrap .nav-notif-dot[hidden]{display:none}
@media (max-width:768px){.nav-pic-wrap .nav-notif-dot{width:11.4px;height:11.4px;right:-3px;bottom:-3px;box-shadow:0 0 0 2.06px #070924}}
[data-theme="light"] .sidebar .nav-pic-wrap .nav-notif-dot{box-shadow:0 0 0 2.06px #ffffff !important}
@media (max-width:768px){.nav-badge{top:11px;left:27px;box-shadow:0 0 0 2px #070924}}

.dz-menu .dz-dot{display:inline-block;width:9px;height:9px;flex:0 0 9px;border-radius:50%;background:#FF2D2D;margin-left:-4px}

/* ══════════════════════════════════════════════════════════════
   LIGHT THEME — only when <html data-theme="light"> is set (dark mode is never touched).
   Fully self-contained: every colour is set here, so it works on any page,
   whether or not that page defines --lane / --ui-text / --fg.
   Covers: desktop rail, mobile bottom bar, logo, icons, labels, profile ring, badge, hold menu.
══════════════════════════════════════════════════════════════ */

/* variables for the sidebar and everything inside it (desktop + mobile) */
[data-theme="light"] .sidebar {
    --lane: #ffffff;
    --ui-text: #0b0d24;
    --fg: 6,7,26;
    --border: rgba(6,7,26,.1);
}

/* colours shared by desktop and mobile */
[data-theme="light"] .sidebar .logo-box { background: #ffffff !important; }
[data-theme="light"] .sidebar .nav-logo-bg { fill: #ffffff !important; }
[data-theme="light"] .sidebar .nav-logo-fg { stroke: #0b0d24 !important; }
[data-theme="light"] .sidebar .nav-icon,
[data-theme="light"] .sidebar .nav-label { color: #0b0d24 !important; }
[data-theme="light"] .sidebar .nav-icon svg { color: #0b0d24 !important; }
[data-theme="light"] .sidebar .nav-icon i { color: #0b0d24 !important; -webkit-text-stroke-color: #0b0d24 !important; }
[data-theme="light"] .sidebar .nav-icon:hover { background: rgba(6,7,26,.08) !important; }
[data-theme="light"] .sidebar .nav-profile-pic { border-color: #0b0d24 !important; }
[data-theme="light"] .sidebar .nav-profile-link.is-profile-active .nav-profile-pic,
[data-theme="light"] .sidebar .nav-profile-link:active .nav-profile-pic { border-color: #ff2d2d !important; }
[data-theme="light"] .sidebar .nav-profile-link.is-profile-active .nav-label { color: #ff2d2d !important; }

/* desktop rail */
@media (min-width: 769px) {
    [data-theme="light"] .sidebar {
        background: #ffffff !important;
        border-right: 1px solid rgba(6,7,26,.1) !important;
    }
    [data-theme="light"] .sidebar .nav-badge { box-shadow: 0 0 0 2px #ffffff !important; }
}

/* mobile bottom bar */
@media (max-width: 768px) {
    [data-theme="light"] .sidebar {
        background: #ffffff !important;
        border-top: 1px solid rgba(6,7,26,.1) !important;
        box-shadow: none !important;
    }
    [data-theme="light"] .sidebar .nav-icon:hover { background: rgba(6,7,26,.05) !important; }
    [data-theme="light"] .sidebar .nav-badge { box-shadow: 0 0 0 2px #ffffff !important; }
}

/* hold-to-switch menu */
[data-theme="light"] .dz-menu {
    --dz-bg: #ffffff;
    --dz-bd: rgba(0,0,0,0.12);
    box-shadow: 0 12px 36px rgba(0,0,0,0.16);
}
@media (max-width: 768px) {
    [data-theme="light"] .dz-menu { --dz-bg: #ffffff; --dz-bd: rgba(6,7,26,.1); box-shadow: none; }   /* same as the light bottom bar */
}
[data-theme="light"] .dz-menu .dz-title { color: #52566a; }
[data-theme="light"] .dz-menu button { color: #0b0d24; }
[data-theme="light"] .dz-menu button:hover { background: rgba(6,7,26,0.07); }
[data-theme="light"] .dz-menu button[aria-checked="true"] { background: rgba(6,7,26,0.11); }
[data-theme="light"] .dz-menu .dz-check { color: #c98400; }
</style>

<!-- ══ SIDEBAR (left rail on desktop, bottom bar on mobile) ══ -->
<?php if ($nav_susp): ?>
<style>
/* Suspended account: the bar shows ONLY the profile-picture button (until unsuspended). */
.sidebar.nav-susp .logo,
.sidebar.nav-susp .nav-icon:not(.nav-profile-link),
.sidebar.nav-susp .sidebar-desktop-only,
.sidebar.nav-susp .nav-badge,
.sidebar.nav-susp .nav-notif-dot{ display:none !important; }
@media (min-width:769px){ .sidebar.nav-susp{ align-items:flex-start; } }
@media (max-width:768px){ .sidebar.nav-susp{ justify-content:center; } }
</style>
<?php endif; ?>
<div class="sidebar<?php echo $nav_susp ? ' nav-susp' : ''; ?>">

    <div class="logo" role="link" tabindex="0" aria-label="Dezzme home" onclick="location.href='index.php'" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();location.href='index.php';}">
        <div class="logo-box">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 466 466" fill="none" aria-label="Dezzme">
                <rect class="nav-logo-bg" width="466" height="466" fill="#070924"/>
                <g transform="translate(97.0 97.0) scale(0.14937) translate(-745.5 -745.9)">
                    <path class="nav-logo-fg" d="M1097.50,2562.95C1061.53,2561.74 1031.66,2557.17 1007.00,2549.09C1002.33,2547.55 995.80,2545.49 992.50,2544.49C971.69,2538.22 929.35,2518.37 912.11,2506.80C906.94,2503.34 901.09,2499.42 899.11,2498.10C883.14,2487.48 850.72,2457.19 833.18,2436.50C830.38,2433.20 826.93,2429.15 825.51,2427.50C814.82,2415.08 801.13,2394.51 790.24,2374.50C782.78,2360.78 767.19,2324.67 764.56,2315.00C763.58,2311.43 762.13,2306.93 761.33,2305.00C759.36,2300.29 753.36,2276.73 752.53,2270.50C752.16,2267.75 751.26,2262.12 750.52,2258.00C746.98,2238.10 746.76,2233.78 747.34,2194.50C747.65,2173.05 748.35,2152.80 748.88,2149.50C752.02,2130.14 755.02,2113.73 756.38,2108.49C758.17,2101.57 764.54,2082.81 765.96,2080.24C766.49,2079.28 768.02,2075.80 769.36,2072.50C778.09,2050.99 791.83,2022.49 797.15,2014.85C798.17,2013.39 799.00,2011.79 799.00,2011.29C799.00,2010.38 801.12,2007.31 810.94,1994.00C817.99,1984.44 831.23,1969.09 844.79,1954.74C850.64,1948.56 856.56,1942.16 857.96,1940.52C859.36,1938.88 868.86,1929.21 879.08,1919.02C889.29,1908.83 911.60,1886.55 928.66,1869.50C971.10,1827.07 968.02,1830.13 1028.13,1770.61C1057.62,1741.40 1085.07,1714.34 1089.13,1710.48C1093.18,1706.62 1100.10,1699.67 1104.50,1695.02C1108.90,1690.38 1125.78,1673.44 1142.00,1657.37C1158.22,1641.30 1174.89,1624.63 1179.04,1620.33C1183.19,1616.02 1197.97,1601.47 1211.88,1588.00C1225.80,1574.53 1245.35,1555.21 1255.34,1545.09C1280.79,1519.29 1305.76,1494.55 1331.94,1469.21C1344.28,1457.27 1360.67,1440.97 1368.37,1433.00C1376.06,1425.03 1393.00,1407.92 1406.01,1395.00C1430.29,1370.87 1437.19,1362.94 1443.01,1352.50C1444.85,1349.20 1447.40,1345.23 1448.67,1343.67C1449.95,1342.12 1451.00,1340.38 1451.00,1339.81C1451.00,1339.23 1452.74,1334.88 1454.87,1330.13C1460.02,1318.67 1461.01,1313.48 1461.71,1294.46C1462.77,1265.30 1458.61,1248.71 1444.38,1225.47C1436.43,1212.47 1424.82,1199.09 1414.76,1191.33C1408.58,1186.56 1386.65,1175.30 1378.50,1172.72C1369.37,1169.83 1350.78,1167.79 1339.71,1168.47C1318.84,1169.76 1312.54,1171.23 1293.16,1179.34C1276.36,1186.36 1271.86,1190.14 1227.32,1234.48C1206.87,1254.84 1187.30,1274.23 1183.82,1277.57C1180.35,1280.90 1171.68,1289.90 1164.57,1297.57C1157.46,1305.23 1138.59,1324.33 1122.65,1340.00C1106.70,1355.67 1072.88,1389.20 1047.49,1414.50C962.58,1499.12 946.80,1514.41 938.66,1519.95C931.21,1525.03 912.07,1533.61 902.86,1535.99C900.46,1536.61 896.70,1537.73 894.50,1538.47C872.24,1546.00 831.98,1542.01 813.15,1530.41C809.66,1528.26 805.39,1525.64 803.65,1524.58C792.71,1517.91 773.40,1499.71 765.91,1489.00C752.76,1470.20 747.42,1454.64 746.01,1431.00C744.37,1403.29 746.44,1077.95 748.35,1063.50C752.93,1029.00 758.59,1007.74 772.46,973.00C781.90,949.35 811.35,896.00 814.96,896.00C815.35,896.00 817.19,893.86 819.07,891.25C820.94,888.64 824.67,884.02 827.35,881.00C856.27,848.39 873.24,832.05 890.26,820.43C893.43,818.27 898.21,814.92 900.89,813.00C912.34,804.76 917.80,801.11 922.50,798.58C925.25,797.09 932.67,792.98 939.00,789.43C952.65,781.77 959.70,778.42 965.35,776.92C969.21,775.89 983.93,770.70 992.00,767.52C993.92,766.76 1000.45,764.73 1006.50,763.00C1012.55,761.27 1020.42,759.02 1024.00,757.99C1030.07,756.24 1038.36,754.62 1054.00,752.12C1073.96,748.93 1087.53,748.87 1667.00,749.20L2246.50,749.54L2258.50,751.72C2265.10,752.93 2273.43,754.39 2277.00,754.98C2280.57,755.57 2284.82,756.50 2286.42,757.04C2288.03,757.58 2292.29,758.49 2295.89,759.06C2299.48,759.64 2302.94,760.53 2303.57,761.06C2304.20,761.58 2309.39,763.54 2315.11,765.42C2320.82,767.30 2330.22,770.70 2336.00,772.99C2341.78,775.28 2348.75,777.96 2351.50,778.94C2356.01,780.55 2366.22,785.66 2389.44,797.94C2393.80,800.25 2398.62,803.12 2400.14,804.32C2401.66,805.52 2404.84,807.94 2407.20,809.70C2409.57,811.46 2415.55,815.92 2420.50,819.60C2425.45,823.28 2430.85,827.58 2432.50,829.15C2434.15,830.71 2437.16,833.23 2439.19,834.75C2445.79,839.68 2459.76,851.87 2461.85,854.54C2462.97,855.97 2468.66,862.17 2474.47,868.32C2489.97,884.70 2509.30,912.02 2520.57,933.50C2531.16,953.67 2533.37,957.81 2535.11,960.64C2537.33,964.28 2538.39,967.14 2547.95,995.38C2557.88,1024.75 2560.47,1037.91 2565.84,1086.50C2567.39,1100.52 2566.17,1144.21 2563.98,1153.00C2563.50,1154.92 2562.36,1161.67 2561.45,1168.00C2555.99,1205.86 2535.28,1269.02 2524.41,1281.00C2523.17,1282.38 2520.47,1286.88 2518.41,1291.00C2507.84,1312.25 2476.24,1354.26 2460.61,1367.86C2458.42,1369.76 2348.49,1478.87 2266.51,1560.50C2235.58,1591.30 2177.78,1648.67 2138.05,1688.00C2052.58,1772.61 2040.79,1784.36 2035.50,1790.17C2033.30,1792.58 2014.90,1810.75 1994.61,1830.53C1974.32,1850.31 1956.32,1868.06 1954.61,1869.96C1952.90,1871.86 1948.39,1876.36 1944.60,1879.96C1940.80,1883.56 1933.62,1890.78 1928.63,1896.00C1916.24,1908.99 1896.66,1928.38 1890.00,1934.26C1886.97,1936.93 1883.15,1940.85 1881.50,1942.97C1869.75,1958.09 1866.79,1962.58 1863.07,1971.00C1860.75,1976.22 1857.99,1982.35 1856.93,1984.61C1855.87,1986.87 1855.00,1989.65 1855.00,1990.80C1855.00,1991.94 1854.10,1996.61 1853.01,2001.19C1851.42,2007.80 1851.01,2012.98 1851.01,2026.50C1851.00,2049.63 1853.53,2060.28 1864.57,2083.56C1870.28,2095.58 1893.09,2120.71 1902.66,2125.51C1905.87,2127.12 1911.20,2129.96 1914.50,2131.82C1940.00,2146.18 1975.00,2148.97 2004.93,2139.02C2024.81,2132.41 2034.69,2126.70 2048.44,2113.90C2055.24,2107.56 2118.48,2044.35 2165.38,1997.00C2173.28,1989.03 2191.16,1971.48 2205.12,1958.00C2219.08,1944.53 2232.69,1931.26 2235.36,1928.50C2238.03,1925.75 2260.26,1903.47 2284.76,1879.00C2309.25,1854.53 2338.34,1825.46 2349.40,1814.40C2364.51,1799.30 2370.70,1793.78 2374.32,1792.18C2376.97,1791.01 2382.82,1787.83 2387.32,1785.12C2423.26,1763.45 2481.13,1765.33 2512.11,1789.18C2536.66,1808.08 2550.46,1824.87 2558.40,1845.50C2559.88,1849.35 2561.71,1854.08 2562.46,1856.00C2565.72,1864.37 2566.07,1883.17 2566.12,2048.50C2566.14,2138.70 2565.86,2217.68 2565.50,2224.00C2562.88,2269.92 2548.59,2322.90 2527.37,2365.39C2518.22,2383.70 2509.59,2398.42 2502.98,2407.00C2500.22,2410.57 2495.87,2416.43 2493.32,2420.00C2490.76,2423.57 2486.36,2428.97 2483.53,2432.00C2480.70,2435.03 2476.77,2439.53 2474.81,2442.00C2464.98,2454.40 2432.79,2484.23 2419.42,2493.33C2415.61,2495.92 2408.22,2501.00 2403.00,2504.62C2397.78,2508.24 2387.88,2513.92 2381.00,2517.25C2374.12,2520.57 2367.82,2523.82 2367.00,2524.46C2365.16,2525.90 2341.41,2536.66 2330.50,2541.01C2305.92,2550.81 2278.63,2557.76 2256.00,2559.99C2249.68,2560.62 2242.93,2561.65 2241.00,2562.29C2237.00,2563.62 1136.54,2564.25 1097.50,2562.95ZM1314.54,2141.93C1319.33,2140.80 1325.57,2138.84 1328.42,2137.58C1331.27,2136.32 1335.38,2134.71 1337.55,2134.00C1339.72,2133.29 1343.45,2131.38 1345.83,2129.75C1348.22,2128.12 1352.04,2125.73 1354.33,2124.44C1356.62,2123.15 1368.17,2112.51 1380.00,2100.80C1391.83,2089.08 1406.22,2074.94 1412.00,2069.38C1417.78,2063.81 1439.44,2042.21 1460.15,2021.38C1480.86,2000.54 1501.81,1979.67 1506.71,1975.00C1511.61,1970.33 1517.24,1964.70 1519.21,1962.50C1522.19,1959.19 1536.29,1944.86 1542.00,1939.35C1549.08,1932.52 1624.76,1857.01 1632.64,1848.92C1638.22,1843.19 1646.77,1834.63 1651.64,1829.90C1656.51,1825.17 1663.24,1818.42 1666.59,1814.90C1669.95,1811.38 1681.20,1800.40 1691.59,1790.50C1701.99,1780.60 1713.42,1769.38 1717.00,1765.56C1720.58,1761.75 1732.24,1750.05 1742.93,1739.56C1753.62,1729.08 1766.67,1716.04 1771.93,1710.59C1777.19,1705.13 1791.14,1691.18 1802.93,1679.59C1814.72,1667.99 1840.61,1642.30 1860.46,1622.50C1880.32,1602.70 1902.85,1580.38 1910.53,1572.90C1918.21,1565.42 1939.87,1543.82 1958.65,1524.90C1993.99,1489.30 2007.42,1475.82 2010.00,1473.36C2010.83,1472.58 2014.47,1469.14 2018.10,1465.72C2032.24,1452.39 2047.94,1436.78 2057.50,1426.53C2065.75,1417.68 2093.73,1389.47 2112.83,1370.74C2122.75,1361.01 2129.75,1349.17 2136.91,1330.00C2144.18,1310.54 2144.26,1310.14 2144.34,1290.50C2144.41,1273.27 2144.27,1271.95 2141.09,1259.50C2136.47,1241.40 2130.34,1226.68 2122.77,1215.50C2118.86,1209.72 2117.50,1208.45 2106.50,1200.30C2103.75,1198.26 2098.84,1194.41 2095.58,1191.73C2092.32,1189.05 2087.60,1185.75 2085.08,1184.40C2082.56,1183.05 2078.25,1180.54 2075.50,1178.82C2063.74,1171.48 2035.48,1166.61 2015.50,1168.47C2006.62,1169.30 1988.16,1173.49 1983.37,1175.76C1963.87,1185.00 1956.89,1189.48 1946.50,1199.44C1926.47,1218.61 1875.56,1268.73 1863.64,1281.00C1844.21,1301.00 1673.46,1471.60 1651.95,1492.50C1642.33,1501.85 1628.61,1515.51 1621.48,1522.85C1599.11,1545.87 1274.66,1868.67 1236.16,1906.22C1205.70,1935.91 1186.95,1957.19 1181.25,1968.52C1171.33,1988.25 1168.93,1998.45 1168.31,2023.50L1167.81,2043.50L1171.34,2056.00C1177.56,2078.00 1188.16,2095.96 1204.85,2112.80C1219.16,2127.23 1249.33,2141.59 1270.50,2144.06C1281.87,2145.38 1304.65,2144.28 1314.54,2141.93Z" stroke="#fff" stroke-width="120.5" stroke-linejoin="round" stroke-linecap="round"/>
                </g>
            </svg>
        </div>
    </div>

    <!-- Home: Phosphor house, bold → fill (inline SVG, no font needed).
         data-refresh: tapping it again while on home.php spins the arrow, then reloads. -->
    <a href="home.php" class="nav-icon<?php echo $nav_active('home.php'); ?>" title="Home" data-refresh="home.php">
        <span class="nav-ico">
            <svg class="nav-ph ico-bold" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true"><path d="M222.14,105.85l-80-80a20,20,0,0,0-28.28,0l-80,80A19.86,19.86,0,0,0,28,120v96a12,12,0,0,0,12,12h64a12,12,0,0,0,12-12V164h24v52a12,12,0,0,0,12,12h64a12,12,0,0,0,12-12V120A19.86,19.86,0,0,0,222.14,105.85ZM204,204H164V152a12,12,0,0,0-12-12H104a12,12,0,0,0-12,12v52H52V121.65l76-76,76,76Z"/></svg>
            <svg class="nav-ph ico-fill" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true"><path d="M224,120v96a8,8,0,0,1-8,8H160a8,8,0,0,1-8-8V164a4,4,0,0,0-4-4H108a4,4,0,0,0-4,4v52a8,8,0,0,1-8,8H40a8,8,0,0,1-8-8V120a16,16,0,0,1,4.69-11.31l80-80a16,16,0,0,1,22.62,0l80,80A16,16,0,0,1,224,120Z"/></svg>
            <?php echo $nav_refresh_svg; ?>
        </span>
        <span class="nav-label">Home</span>
    </a>

    <!-- Text Feed: Phosphor paper plane, bold → fill.
         data-refresh: tapping it again while on text_feed.php spins the arrow, then reloads. -->
    <a href="text_feed.php" class="nav-icon<?php echo $nav_active('text_feed.php'); ?>" title="Text Feed" data-refresh="text_feed.php">
        <span class="nav-ico">
            <svg class="nav-ph ico-bold" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true"><path d="M241.42,198.2l-.06-.09L145.3,30.17a20,20,0,0,0-34.82,0L14.58,198.2a20,20,0,0,0,24.06,28.65L128,196.67l89.36,30.18a20,20,0,0,0,6.69,1.15,20,20,0,0,0,17.37-29.8ZM140,175.39V120a12,12,0,0,0-24,0v55.39L40.72,200.82,127.89,48.06l87.37,152.75Z"/></svg>
            <svg class="nav-ph ico-fill" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true"><path d="M236.2,218.31A15.88,15.88,0,0,1,224,224a16.22,16.22,0,0,1-5.37-.92l-79.95-27a4,4,0,0,1-2.72-3.79V120a8,8,0,0,0-8.53-8,8.19,8.19,0,0,0-7.47,8.26v72a4,4,0,0,1-2.72,3.79l-79.95,27a16,16,0,0,1-19.26-22.92L114,32.13a16,16,0,0,1,27.89,0L237.9,200.1A15.89,15.89,0,0,1,236.2,218.31Z"/></svg>
            <?php echo $nav_refresh_svg; ?>
        </span>
        <span class="nav-label">Text Feed</span>
    </a>

    <a href="upload.php" class="nav-icon sidebar-desktop-only" title="Upload">
        <span class="nav-ico"><svg class="nav-plus-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M12 4.5v15M4.5 12h15"/></svg></span>
        <span class="nav-label">Upload</span>
    </a>

    <!-- Search: same icon, gets thicker when tapped / current page -->
    <a href="search.php" class="nav-icon<?php echo $nav_active('search.php'); ?>" title="Search">
        <span class="nav-ico">
            <svg viewBox="0 0 24 24" aria-hidden="true" style="color:var(--ui-text,white);">
                <circle class="nav-search-ring" cx="10" cy="10" r="6.5" fill="none" stroke="currentColor" stroke-width="2.5"/>
                <path class="nav-search-handle" fill="currentColor" d="M15.37 14.23L21.41 18.59A2 2 0 0 1 18.59 21.41L14.23 15.37Z"/>
            </svg>
        </span>
        <span class="nav-label">Search</span>
    </a>

    <!-- Games slot: default = Games. Hold it to switch to Messages, Memepedia or Dayleez (see script at the bottom).
         Signed-out visitors are sent to index.php instead. -->
    <a href="<?php echo $nav_slot_href; ?>" id="dzSlot" data-slot="<?php echo $nav_slot_key; ?>" aria-haspopup="menu" draggable="false" class="nav-icon<?php echo $nav_current_page === $nav_slot['href'] ? ' is-active' : ''; ?>" title="<?php echo $nav_slot['label']; ?>">
        <span class="nav-ico"><?php echo $nav_slot['bold'] . $nav_slot['fill']; ?></span>
        <span class="nav-label"><?php echo $nav_slot['label']; ?></span>
        <span class="nav-badge" id="dzBadge" data-n="<?php echo (int)$nav_unread; ?>" data-d="<?php echo (int)$nav_dl_unseen; ?>"<?php echo $nav_badge_on ? '' : ' hidden'; ?>></span>
    </a>

    <!-- Pods: Phosphor users three, bold → fill (signed-out visitors go to index.php).
         data-refresh: tapping it again while on community.php spins the arrow, then reloads. -->
    <a href="<?php echo $nav_pods_href; ?>" class="nav-icon<?php echo $nav_active('community.php'); ?>" title="Pods" data-refresh="community.php">
        <span class="nav-ico">
            <svg class="nav-ph ico-bold" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true"><path d="M164.38,181.1a52,52,0,1,0-72.76,0,75.89,75.89,0,0,0-30,28.89,12,12,0,0,0,20.78,12,53,53,0,0,1,91.22,0,12,12,0,1,0,20.78-12A75.89,75.89,0,0,0,164.38,181.1ZM100,144a28,28,0,1,1,28,28A28,28,0,0,1,100,144Zm147.21,9.59a12,12,0,0,1-16.81-2.39c-8.33-11.09-19.85-19.59-29.33-21.64a12,12,0,0,1-1.82-22.91,20,20,0,1,0-24.78-28.3,12,12,0,1,1-21-11.6,44,44,0,1,1,73.28,48.35,92.18,92.18,0,0,1,22.85,21.69A12,12,0,0,1,247.21,153.59Zm-192.28-24c-9.48,2.05-21,10.55-29.33,21.65A12,12,0,0,1,6.41,136.79,92.37,92.37,0,0,1,29.26,115.1a44,44,0,1,1,73.28-48.35,12,12,0,1,1-21,11.6,20,20,0,1,0-24.78,28.3,12,12,0,0,1-1.82,22.91Z"/></svg>
            <svg class="nav-ph ico-fill" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true"><path d="M64.12,147.8a4,4,0,0,1-4,4.2H16a8,8,0,0,1-7.8-6.17,8.35,8.35,0,0,1,1.62-6.93A67.79,67.79,0,0,1,37,117.51a40,40,0,1,1,66.46-35.8,3.94,3.94,0,0,1-2.27,4.18A64.08,64.08,0,0,0,64,144C64,145.28,64,146.54,64.12,147.8Zm182-8.91A67.76,67.76,0,0,0,219,117.51a40,40,0,1,0-66.46-35.8,3.94,3.94,0,0,0,2.27,4.18A64.08,64.08,0,0,1,192,144c0,1.28,0,2.54-.12,3.8a4,4,0,0,0,4,4.2H240a8,8,0,0,0,7.8-6.17A8.33,8.33,0,0,0,246.17,138.89Zm-89,43.18a48,48,0,1,0-58.37,0A72.13,72.13,0,0,0,65.07,212,8,8,0,0,0,72,224H184a8,8,0,0,0,6.93-12A72.15,72.15,0,0,0,157.19,182.07Z"/></svg>
            <?php echo $nav_refresh_svg; ?>
        </span>
        <span class="nav-label">Pods</span>
    </a>

    <!-- Profile: no filled version. White ring normally (dark ring in light mode); slightly thicker RED ring on your OWN profile.php (and while tapping it).
         The red dot at the bottom-right of the picture = you have a new notification. -->
    <a href="<?php echo $current_user ? 'profile.php' : 'index.php'; ?>" class="nav-icon nav-profile-link<?php echo $nav_profile_active; ?>" title="Profile">
        <span class="nav-ico">
            <span class="nav-pic-wrap">
                <img class="nav-profile-pic"
                     src="<?php echo htmlspecialchars($nav_pic); ?>"
                     onerror="this.src='mee.jpg'"
                     alt="Profile">
                <span class="nav-notif-dot" id="dzNotifDot" hidden></span>
            </span>
        </span>
        <span class="nav-label">Profile</span>
    </a>
</div>

<script>
/* Lets the page use the full screen on iPhones so the bottom bar can reserve space for the home line
   (needed for env(safe-area-inset-bottom) to work). Only adds it if the page doesn't have it already. */
(function () {
    var m = document.querySelector('meta[name="viewport"]');
    if (!m) {
        m = document.createElement('meta');
        m.name = 'viewport';
        m.content = 'width=device-width, initial-scale=1';
        document.head.appendChild(m);
    }
    if (m.content.indexOf('viewport-fit') === -1) {
        m.content = m.content + ', viewport-fit=cover';
    }
})();
</script>

<script>
/* ═══ NAV HAPTICS (medium) ═══
   Defines window.dzNavHaptic(kind). Loaded BEFORE the scripts below, which call it.
   - 'medium' : a medium tap (touch on a bar button, choosing a button in the hold menu, the refresh tap)
   - 'hold'   : a slightly stronger tap when the hold menu opens
   It uses the page's own dzHaptic.medium() when the page has it (same feel as the home page),
   otherwise navigator.vibrate (Android), otherwise a light tick on iPhone (iOS 17.4+ hidden-switch trick).
   Off when the Haptics switch in Your Algorithm > Feed controls is off (localStorage dz_fx = 0). */
(function () {
    var MEDIUM_MS = 30, HOLD_MS = 45, iosEl = null;

    function enabled() {
        try { return localStorage.getItem('dz_fx') !== '0'; } catch (e) { return true; }
    }
    /* the iPhone trick clicks a hidden switch: that click is a REAL click event, so it is swallowed here
       before any page handler (menus that close on an outside click, etc.) can mistake it for a tap */
    document.addEventListener('click', function (e) {
        if (iosEl && e.target && (e.target === iosEl || iosEl.contains(e.target))) {
            e.stopImmediatePropagation(); e.stopPropagation();
        }
    }, true);
    function iosTick() {
        try {
            if (!iosEl) {
                var l = document.createElement('label'), i = document.createElement('input');
                l.setAttribute('aria-hidden', 'true');
                l.style.cssText = 'position:fixed;left:-9999px;top:0;width:1px;height:1px;opacity:0;pointer-events:none';
                i.type = 'checkbox'; i.setAttribute('switch', '');
                l.appendChild(i); document.body.appendChild(l); iosEl = l;
            }
            iosEl.click();
        } catch (e) {}
    }
    window.dzNavHaptic = function (kind) {
        if (!enabled()) return;
        if (kind !== 'hold' && window.dzHaptic && typeof window.dzHaptic.medium === 'function') {
            try { window.dzHaptic.medium(); return; } catch (e) {}
        }
        var ms = (kind === 'hold') ? HOLD_MS : MEDIUM_MS;
        if (navigator.vibrate) { try { navigator.vibrate(ms); } catch (e) {} return; }
        iosTick();
        if (kind === 'hold') setTimeout(iosTick, 40);
    };

    /* every touch on a bar button: a medium tap the moment the finger lands (touch / pen only, not mouse) */
    document.addEventListener('pointerdown', function (e) {
        if (e.pointerType === 'mouse') return;
        var t = e.target && e.target.closest ? e.target.closest('.sidebar a.nav-icon') : null;
        if (!t) return;
        window.dzNavHaptic('medium');
    }, true);
})();
</script>

<script>
/* ═══ Home / Text Feed / Pods: tap once = go to the page.
   Tap again while you are already on it = curved arrow spins, then the page refreshes
   and the icon comes back. ═══ */
(function () {
    var page = location.pathname.split('/').pop() || '';
    var busy = false;
    var links = document.querySelectorAll('.sidebar a[data-refresh]');
    Array.prototype.forEach.call(links, function (a) {
        a.addEventListener('click', function (e) {
            if (a.getAttribute('data-refresh') !== page) return;     /* not on this page: normal navigation */
            if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button > 0) return;  /* let new-tab clicks work */
            e.preventDefault();
            if (busy) return;
            busy = true;
            a.classList.add('is-refreshing');
            setTimeout(function () { location.reload(); }, 700);     /* matches the 0.7s spin */
        });
    });
    /* coming back via the browser's back button (page restored from cache): clear the arrow */
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return;
        busy = false;
        Array.prototype.forEach.call(links, function (a) { a.classList.remove('is-refreshing'); });
    });
})();
</script>

<script>
/* ═══ Hold the Games button to switch it to Messages, Memepedia or Dayleez ═══
   Games is the default. Signed-in users: the choice is saved on the account (database),
   so it survives logout / login and follows them to other devices.
   Signed-out visitors: the choice is remembered on this device only. */
(function () {
    var SLOTS = <?php echo json_encode($nav_slots); ?>;
    var LOGGED_IN = <?php echo $nav_logged_in ? 'true' : 'false'; ?>;
    var DB_SET = <?php echo $nav_db_slot !== null ? 'true' : 'false'; ?>;
    var CSRF = <?php echo json_encode($nav_csrf); ?>;
    var KEY = 'dz_nav_slot';
    var slot = document.getElementById('dzSlot');
    if (!slot) return;
    var nameEl = slot.querySelector('.nav-label');
    var icoEl  = slot.querySelector('.nav-ico');
    var page   = location.pathname.split('/').pop();
    var menu = null, timer = null, longPressed = false, sx = 0, sy = 0;
    var badge = document.getElementById('dzBadge');
    var unread = badge ? parseInt(badge.getAttribute('data-n') || '0', 10) : 0;
    var dlNew  = badge ? parseInt(badge.getAttribute('data-d') || '0', 10) : 0;   /* unseen Dayleez from people you track */

    function haptic(kind) { if (window.dzNavHaptic) window.dzNavHaptic(kind); }

    /* signed-out visitors always go to index.php */
    function dest(key) { return LOGGED_IN ? SLOTS[key].href : 'index.php'; }

    function paintBadge() {
        if (!badge) return;
        var key = slot.getAttribute('data-slot');
        var on = (key === 'messages' && unread > 0 && page !== 'messages.php') ||
                 (key === 'dayleez'  && dlNew  > 0 && page !== 'dayleez.php');
        badge.hidden = !on;
    }
    if (LOGGED_IN) {
        setInterval(function () {
            if (document.hidden) return;
            fetch('nav.php?unread=1', {credentials: 'same-origin', cache: 'no-store'})
                .then(function (r) { return r.json(); })
                .then(function (d) { unread = d.n | 0; dlNew = d.d | 0; paintBadge(); })
                .catch(function () {});
        }, 20000);
    }

    function setCookie(v) {
        document.cookie = KEY + '=' + v + '; path=/; max-age=31536000; SameSite=Lax';
    }
    function hasCookie() { return document.cookie.indexOf(KEY + '=') > -1; }
    function lsGet() {
        try { var v = localStorage.getItem(KEY); if (SLOTS[v]) return v; } catch (e) {}
        return null;
    }
    /* save the choice on the account (keepalive lets it finish even if the page navigates right away) */
    function saveRemote(v) {
        if (!LOGGED_IN) return;
        try {
            fetch('nav.php', {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: true,
                headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF},
                body: 'setslot=' + encodeURIComponent(v) + '&csrf=' + encodeURIComponent(CSRF)
            }).catch(function () {});
        } catch (e) {}
    }
    function save(v) {
        try { localStorage.setItem(KEY, v); } catch (e) {}
        setCookie(v);
        saveRemote(v);
    }

    function apply(key) {
        var s = SLOTS[key];
        slot.setAttribute('href', dest(key));
        slot.setAttribute('title', s.label);
        slot.setAttribute('data-slot', key);
        icoEl.innerHTML = s.bold + s.fill;
        nameEl.textContent = s.label;
        slot.classList.toggle('is-active', page === s.href);
        paintBadge();
    }

    function closeMenu() {
        if (!menu) return;
        var m = menu; menu = null;
        m.classList.remove('show');
        setTimeout(function () { if (m.parentNode) m.parentNode.removeChild(m); }, 160);
        document.removeEventListener('pointerdown', outside, true);
        document.removeEventListener('keydown', onKey, true);
        window.removeEventListener('resize', closeMenu);
        window.removeEventListener('scroll', closeMenu, true);
    }
    function outside(e) { if (menu && !menu.contains(e.target)) closeMenu(); }
    function onKey(e) { if (e.key === 'Escape') closeMenu(); }

    function openMenu() {
        closeMenu();
        var cur = slot.getAttribute('data-slot') || 'games';
        menu = document.createElement('div');
        menu.className = 'dz-menu';
        menu.setAttribute('role', 'menu');
        var html = '<div class="dz-title">Change this button</div>';
        ['games', 'messages', 'memepedia', 'dayleez', 'algorithm', 'zeems'].forEach(function (k) {
            html += '<button type="button" role="menuitemradio" data-k="' + k + '" aria-checked="' + (k === cur) + '">' +
                    SLOTS[k].bold + '<span>' + SLOTS[k].label + '</span>' +
                    (k === 'messages' && unread > 0 ? '<span class="dz-dot" title="New messages"></span>' : '') +
                    (k === 'dayleez'  && dlNew  > 0 ? '<span class="dz-dot" title="New Dayleez"></span>' : '') +
                    (k === cur ? '<span class="dz-check">&#10003;</span>' : '') + '</button>';
        });
        menu.innerHTML = html;
        document.body.appendChild(menu);

        var r  = slot.getBoundingClientRect();
        var sb = slot.closest('.sidebar').getBoundingClientRect();
        var w  = menu.offsetWidth, h = menu.offsetHeight;
        var mobile = window.matchMedia('(max-width: 768px)').matches;
        var left, top;
        if (mobile) {
            left = 10;                                  /* almost the full width, not touching the screen sides */
            top  = Math.max(8, sb.top - h - 10);       /* a small gap above the bar */
        } else {
            left = sb.right + 10;
            top  = Math.min(Math.max(8, r.top - 6), window.innerHeight - h - 8);
        }
        menu.style.left = left + 'px';
        menu.style.top  = top + 'px';
        requestAnimationFrame(function () { menu.classList.add('show'); });

        /* a medium tap the moment a finger lands on a choice in the menu */
        menu.addEventListener('pointerdown', function (e) {
            if (e.pointerType === 'mouse') return;
            if (e.target.closest && e.target.closest('button[data-k]')) haptic('medium');
        });
        menu.addEventListener('click', function (e) {
            var b = e.target.closest('button[data-k]');
            if (!b) return;
            var k = b.getAttribute('data-k');
            save(k); apply(k); closeMenu();
            /* also open that page (skip if already on it); signed-out visitors go to index.php */
            var go = dest(k);
            if (page !== go) { location.href = go; }
        });
        document.addEventListener('pointerdown', outside, true);
        document.addEventListener('keydown', onKey, true);
        window.addEventListener('resize', closeMenu);
        window.addEventListener('scroll', closeMenu, true);
    }

    /* long press (touch or mouse) */
    slot.addEventListener('pointerdown', function (e) {
        if (e.button && e.button !== 0) return;
        longPressed = false; sx = e.clientX; sy = e.clientY;
        clearTimeout(timer);
        timer = setTimeout(function () { longPressed = true; haptic('hold'); openMenu(); }, 450);
    });
    function cancel() { clearTimeout(timer); }
    slot.addEventListener('pointerup', cancel);
    slot.addEventListener('pointercancel', cancel);
    slot.addEventListener('pointerleave', cancel);
    slot.addEventListener('pointermove', function (e) {
        if (Math.abs(e.clientX - sx) > 10 || Math.abs(e.clientY - sy) > 10) cancel();
    });
    /* a long press must not also navigate */
    slot.addEventListener('click', function (e) {
        if (longPressed) { e.preventDefault(); e.stopPropagation(); longPressed = false; }
    }, true);
    slot.addEventListener('contextmenu', function (e) { e.preventDefault(); });
    slot.addEventListener('dragstart', function (e) { e.preventDefault(); });

    /* PHP already drew the right icon, so nothing is redrawn (no flicker). */
    var cur = slot.getAttribute('data-slot') || 'games';
    var ls = lsGet();
    if (LOGGED_IN) {
        if (!DB_SET) {
            /* nothing saved on the account yet: carry over this device's old choice once */
            var pick = hasCookie() ? cur : (ls || cur);
            if (pick !== cur) apply(pick);
            if (pick !== 'games') saveRemote(pick);
        }
        /* keep the local copy in step with the account */
        var now = slot.getAttribute('data-slot') || 'games';
        setCookie(now);
        try { localStorage.setItem(KEY, now); } catch (e) {}
        paintBadge();
    } else {
        /* signed out: device-only memory (cookie, with localStorage as backup) */
        if (!hasCookie() && ls && ls !== cur) { apply(ls); setCookie(ls); }
        else { if (!hasCookie() && ls) setCookie(ls); paintBadge(); }
    }
})();
</script>

<?php if ($current_user && !$nav_susp): ?>
<script>
/* Red dot at the bottom-right of the profile picture = you have a new notification.
   It asks profile_notifications.php for the same "unread" number the bell uses.
   FIX: that file answers as plain text wrapped in @@NOTIF_START@@ ... @@NOTIF_END@@ (not pure JSON),
   so the old r.json() always failed silently. We now read the wrapped text (and still accept plain JSON). */
(function(){
    var dot = document.getElementById('dzNotifDot');
    if (!dot) return;
    var START = '@@NOTIF_START@@', END = '@@NOTIF_END@@';
    var URL_POLL = 'profile_notifications.php?poll_count=1&user_id=<?php echo (int)$current_user; ?>';
    var busy = false;

    function parse(t) {
        var a = t.lastIndexOf(START), b = t.lastIndexOf(END);
        if (a > -1 && b > a) return JSON.parse(t.substring(a + START.length, b));
        return JSON.parse(t);                       /* fallback: plain JSON */
    }
    function check() {
        if (busy || document.hidden) return;
        busy = true;
        fetch(URL_POLL + '&_=' + Date.now(), { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.text(); })
            .then(function (t) {
                var d = parse(t);
                dot.hidden = !(d && Number(d.unread) > 0);
            })
            .catch(function (err) { if (window.console) console.warn('nav notif dot:', err); })
            .then(function () { busy = false; });
    }
    check();
    setInterval(check, 20000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) check(); });
    window.addEventListener('focus', check);
    window.addEventListener('pageshow', function (e) { if (e.persisted) check(); });
    document.addEventListener('dz:notifications-seen', function () { dot.hidden = true; });
})();
</script>
<?php endif; ?>

<script>
/* lets the :active (tapped) state work on iOS Safari */
document.addEventListener('touchstart', function () {}, { passive: true });
</script>
<?php /* remote control / D-pad navigation (same file profile.php and group_page.php use); safe to include twice */
if(is_file(__DIR__ . '/tv_nav.php')) include __DIR__ . '/tv_nav.php'; ?>