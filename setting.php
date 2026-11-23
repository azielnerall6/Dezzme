<?php
/* ══════════════════════════════════════════════════════════════
   SESSION: hardened cookie (HttpOnly + SameSite=Lax + Secure on HTTPS)
   (same as the login and password pages)
══════════════════════════════════════════════════════════════ */
$IS_HTTPS = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $IS_HTTPS,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
include 'config.php';
require_once __DIR__ . '/dz_privacy.php';   /* online / last-seen levels, download protection, default post settings (adds its columns itself) */
/* verified badge next to a name — self-contained (no other file needed) */
function st_badge() {
    return '<svg class="st-vb" viewBox="0 0 256 256" fill="currentColor" aria-label="Verified" style="width:1em;height:1em;vertical-align:-.14em;margin-left:.2em;color:var(--red,#FF2D2D);flex-shrink:0"><path fill-rule="evenodd" d="M245.9 128.0L245.5 132.6L244.2 137.1L242.3 141.5L239.7 145.7L236.5 149.6L233.0 153.2L229.3 156.6L225.6 159.7L221.9 162.6L218.5 165.5L215.4 168.3L212.7 171.2L210.5 174.2L208.7 177.5L207.3 181.0L206.3 184.9L205.4 189.1L204.7 193.5L204.0 198.2L203.1 203.1L201.9 208.0L200.4 212.8L198.4 217.4L196.0 221.6L193.0 225.3L189.5 228.4L185.6 230.8L181.3 232.5L176.6 233.5L171.8 233.7L166.9 233.4L161.9 232.4L157.1 231.1L152.4 229.5L147.8 227.8L143.5 226.1L139.4 224.6L135.5 223.5L131.7 222.8L128.0 222.5L124.3 222.8L120.5 223.5L116.6 224.6L112.5 226.1L108.2 227.8L103.6 229.5L98.9 231.1L94.1 232.4L89.1 233.4L84.2 233.7L79.4 233.5L74.7 232.5L70.4 230.8L66.5 228.4L63.0 225.3L60.0 221.6L57.6 217.4L55.6 212.8L54.1 208.0L52.9 203.1L52.0 198.2L51.3 193.5L50.6 189.1L49.7 184.9L48.7 181.0L47.3 177.5L45.5 174.2L43.3 171.2L40.6 168.3L37.5 165.5L34.1 162.6L30.4 159.7L26.7 156.6L23.0 153.2L19.5 149.6L16.3 145.7L13.7 141.5L11.8 137.1L10.5 132.6L10.1 128.0L10.5 123.4L11.8 118.9L13.7 114.5L16.3 110.3L19.5 106.4L23.0 102.8L26.7 99.4L30.4 96.3L34.1 93.4L37.5 90.5L40.6 87.7L43.3 84.8L45.5 81.8L47.3 78.5L48.7 75.0L49.7 71.1L50.6 66.9L51.3 62.5L52.0 57.8L52.9 52.9L54.1 48.0L55.6 43.2L57.6 38.6L60.0 34.4L63.0 30.7L66.5 27.6L70.4 25.2L74.7 23.5L79.4 22.5L84.2 22.3L89.1 22.6L94.1 23.6L98.9 24.9L103.6 26.5L108.2 28.2L112.5 29.9L116.6 31.4L120.5 32.5L124.3 33.2L128.0 33.5L131.7 33.2L135.5 32.5L139.4 31.4L143.5 29.9L147.8 28.2L152.4 26.5L157.1 24.9L161.9 23.6L166.9 22.6L171.8 22.3L176.6 22.5L181.3 23.5L185.6 25.2L189.5 27.6L193.0 30.7L196.0 34.4L198.4 38.6L200.4 43.2L201.9 48.0L203.1 52.9L204.0 57.8L204.7 62.5L205.4 66.9L206.3 71.1L207.3 75.0L208.7 78.5L210.5 81.8L212.7 84.8L215.4 87.7L218.5 90.5L221.9 93.4L225.6 96.3L229.3 99.4L233.0 102.8L236.5 106.4L239.7 110.3L242.3 114.5L244.2 118.9L245.5 123.4ZM84.1 131.9Q78.2 137.8 84.1 143.6L108.3 167.9Q114.1 173.7 119.9 167.8L174.2 111.6Q179.9 105.6 174.0 99.9L170.0 96.1Q164.1 90.4 158.4 96.3L119.6 136.4Q113.9 142.3 108.0 136.5L99.6 128.1Q93.8 122.2 87.9 128.1Z"/></svg>';
}

/* the same badge as an OUTLINE in the icon colour — for the Dezzme Verified menu / topic / status icons */
function st_badge_icon() {
    /* outline badge: the scalloped edge as a line, and the check as a simple open tick (nothing filled) */
    $b = st_badge();
    if (!preg_match('/ d="([^"]*?Z)/', $b, $m)) return $b;
    return '<svg class="st-vb" viewBox="0 0 256 256" fill="none" stroke="currentColor" stroke-width="19" stroke-linecap="round" stroke-linejoin="round" aria-label="Verified" style="width:1em;height:1em;flex-shrink:0"><path d="' . $m[1] . '"/><path d="M96 133L115 152L163 102"/></svg>';
}

/* ══════════════════════════════════════════════════════════════
   RESPONSE HEADERS (same policy as the login and password pages)
   This page uses onclick="" attributes and style="" attributes, so
   script-src-attr / style-src-attr allow those. Every <script> and <style>
   BLOCK still needs the nonce, so injected script blocks cannot run.
══════════════════════════════════════════════════════════════ */
$nonce = base64_encode(random_bytes(16));

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
if ($IS_HTTPS) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header(
    "Content-Security-Policy: " .
    "default-src 'self'; " .
    "script-src 'nonce-$nonce'; " .
    "script-src-attr 'unsafe-inline'; " .
    "style-src 'nonce-$nonce' https://fonts.googleapis.com https://cdn.jsdelivr.net; " .
    "style-src-attr 'unsafe-inline'; " .
    "font-src https://fonts.gstatic.com https://cdn.jsdelivr.net; " .
    "img-src 'self' data: blob: https:; " .
    "media-src 'self' blob:; " .
    "connect-src 'self'; " .
    "object-src 'none'; " .
    "base-uri 'self'; " .
    "form-action 'self'; " .
    "frame-ancestors 'none'"
);

$current_user = $_SESSION['user_id'] ?? 0;
if (!$current_user) { header("Location: login.php"); exit(); }

/* accounts whose deletion grace is over are removed for good (about once an hour) */
require_once __DIR__ . '/account_purge.php';
dz_purge_maybe($conn);
require_once __DIR__ . '/word_filters.php';

/* ══════════════════════════════════════════════════════════════
   CSRF PROTECTION (every POST on this page)
   1. POST only
   2. Sec-Fetch-Site (when sent) must be same-origin / none
   3. Origin / Referer (when sent) must match this host + port; "null" refused
   4. Secret token (kept in the session) sent as csrf_token or X-CSRF-Token,
      compared with hash_equals()
   The page puts the token in every request automatically. Other pages that post
   here (like the profile editor) must send the same $_SESSION['st_csrf'] value.
══════════════════════════════════════════════════════════════ */
function st_csrf_token(): string {
    if (empty($_SESSION['st_csrf']) || !is_string($_SESSION['st_csrf'])) $_SESSION['st_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['st_csrf'];
}
function st_same_origin(): bool {
    $sfs = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
    if ($sfs !== '' && !in_array($sfs, ['same-origin', 'none'], true)) return false;

    $hostHdr = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $myHost  = (string)parse_url('//' . $hostHdr, PHP_URL_HOST);
    $myPort  = parse_url('//' . $hostHdr, PHP_URL_PORT);

    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $h) {
        if (!empty($_SERVER[$h])) {
            $raw = (string)$_SERVER[$h];
            if (strtolower($raw) === 'null') return false;
            $srcHost = strtolower((string)parse_url($raw, PHP_URL_HOST));
            $srcPort = parse_url($raw, PHP_URL_PORT);
            if ($srcHost === '' || $srcHost !== $myHost) return false;
            if ($srcPort !== null && $myPort !== null && (int)$srcPort !== (int)$myPort) return false;
            return true;            // first header present decides
        }
    }
    return true;   // some browsers/privacy tools send neither; the token still applies
}
function st_csrf_ok(): bool {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return false;
    if (!st_same_origin()) return false;
    $s    = $_SESSION['st_csrf'] ?? '';
    $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($s) && $s !== '' && is_string($sent) && $sent !== '' && hash_equals($s, $sent);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !st_csrf_ok()) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'msg' => 'This page expired or could not be verified. Please reload it and try again.']);
    exit;
}
$csrf_token = st_csrf_token();

/* ══════════════════════════════════════════════════════════════
   PASSWORD-GUESS THROTTLE for deactivate / delete (5 wrong tries per 15 minutes)
   Uses the same login_attempts table as the login page.
══════════════════════════════════════════════════════════════ */
const ST_PW_WINDOW = 900;
const ST_PW_MAX    = 5;
function st_throttle_ready($conn): void {
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS login_attempts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ip VARCHAR(45) NOT NULL,
            ident_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            KEY k_ip (ip, created_at),
            KEY k_ident (ident_hash, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {}
}
function st_throttle_wait($conn, string $ih): int {
    try {
        $st = $conn->prepare("SELECT COUNT(*), TIMESTAMPDIFF(SECOND, MIN(created_at), NOW())
                              FROM login_attempts
                              WHERE ident_hash = ? AND created_at > (NOW() - INTERVAL " . ST_PW_WINDOW . " SECOND)");
        $st->bind_param('s', $ih);
        $st->execute();
        $st->bind_result($cnt, $age);
        $st->fetch();
        $st->close();
        if ((int)$cnt >= ST_PW_MAX) return max(0, ST_PW_WINDOW - (int)$age);
    } catch (Throwable $e) {}
    return 0;
}
function st_throttle_fail($conn, string $ip, string $ih): void {
    try {
        $st = $conn->prepare("INSERT INTO login_attempts (ip, ident_hash, created_at) VALUES (?, ?, NOW())");
        $st->bind_param('ss', $ip, $ih);
        $st->execute();
        $st->close();
    } catch (Throwable $e) {}
}
function st_throttle_clear($conn, string $ih): void {
    try {
        $st = $conn->prepare("DELETE FROM login_attempts WHERE ident_hash = ?");
        $st->bind_param('s', $ih);
        $st->execute();
        $st->close();
    } catch (Throwable $e) {}
}

// ── TRACK REQUEST HANDLERS (before any HTML) ──────────────
if (isset($_POST['approve_track']) && isset($_POST['requester_id'])) {
    $rid = (int)$_POST['requester_id'];
    $st = $conn->prepare("UPDATE track_requests SET status='approved' WHERE requester_id=? AND target_id=?");
    $st->bind_param("ii", $rid, $current_user); $st->execute(); $st->close();
    $st2 = $conn->prepare("INSERT IGNORE INTO followers (follower_id, following_id) VALUES (?,?)");
    $st2->bind_param("ii", $rid, $current_user); $st2->execute(); $st2->close();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'action' => 'approved']); exit;
}
if (isset($_POST['deny_track']) && isset($_POST['requester_id'])) {
    $rid = (int)$_POST['requester_id'];
    $st = $conn->prepare("UPDATE track_requests SET status='denied' WHERE requester_id=? AND target_id=?");
    $st->bind_param("ii", $rid, $current_user); $st->execute(); $st->close();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'action' => 'denied']); exit;
}

// ── HIGHLIGHT HANDLERS ────────────────────────────────────
const HL_MAX_MEDIA = 100;
const HL_MAX_TEXT  = 1000;                    // max characters of text on a highlight media
const HL_DIR = 'uploads/highlights/';
const HL_TEXT_BG = 'Text.jpg';                // background used by text-only highlights (put Text.jpg next to this file)
const HL_USER_URL = 'profile.php?user_id=';   // where a tagged @username / @nickname goes
const HL_GROUP_URL = 'group.php?id=';         // where a tagged group name goes (change to your real group page)
function hl_json($a) { header('Content-Type: application/json'); echo json_encode($a); exit; }
function hl_fail($m) { hl_json(['success' => false, 'msg' => $m]); }
function hl_title($s, $max) { $s = trim(strip_tags((string)$s)); return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max); }
function hl_text($s, $max) { $s = str_replace(["\r\n", "\r"], "\n", (string)$s); $s = trim(strip_tags($s)); return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max); }
function hl_unlink($path) { if ($path && strpos($path, HL_DIR) === 0) @unlink(__DIR__ . '/' . $path); }
function hl_fetch($conn, $hid, $uid) {
    $st = $conn->prepare("SELECT * FROM highlights WHERE id=? AND user_id=? LIMIT 1");
    $st->bind_param("ii", $hid, $uid); $st->execute();
    $r = $st->get_result()->fetch_assoc(); $st->close();
    return $r ?: null;
}
function hl_media($conn, $mid, $hid) {
    $st = $conn->prepare("SELECT * FROM highlight_media WHERE id=? AND highlight_id=? LIMIT 1");
    $st->bind_param("ii", $mid, $hid); $st->execute();
    $r = $st->get_result()->fetch_assoc(); $st->close();
    return $r ?: null;
}
function hl_types($kind) {
    $img = ['image/jpeg' => ['jpg', 'image'], 'image/png' => ['png', 'image'], 'image/gif' => ['gif', 'image'], 'image/webp' => ['webp', 'image']];
    if ($kind === 'image') return $img;
    if ($kind === 'audio') { $w = ['wav', 'audio']; return ['audio/wav' => $w, 'audio/x-wav' => $w, 'audio/wave' => $w, 'audio/vnd.wave' => $w]; }
    return $img + ['video/mp4' => ['mp4', 'video'], 'video/webm' => ['webm', 'video'], 'video/quicktime' => ['mov', 'video']];
}
// Song clips are trimmed in the browser and sent as WAV. This checks the clip really is short (30s max).
function hl_wav_ok($tmp) {
    $h = @file_get_contents($tmp, false, null, 0, 44);
    if ($h === false || strlen($h) < 44 || substr($h, 0, 4) !== 'RIFF' || substr($h, 8, 4) !== 'WAVE') return false;
    $br = unpack('V', substr($h, 28, 4))[1];
    if ($br <= 0) return false;
    return ((filesize($tmp) - 44) / $br) <= 31;
}
function hl_save($f, $allowed, $limit, $prefix, $uid) {
    if (!$f || !isset($f['error']) || $f['error'] !== UPLOAD_ERR_OK) {
        $e = $f['error'] ?? -1;
        return ['ok' => false, 'msg' => ($e === UPLOAD_ERR_INI_SIZE || $e === UPLOAD_ERR_FORM_SIZE) ? 'File is too large for the server.' : 'Upload failed.'];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if (!isset($allowed[$mime])) return ['ok' => false, 'msg' => 'That file type is not allowed.'];
    [$ext, $type] = $allowed[$mime];
    $lim = is_array($limit) ? ($limit[$type] ?? 0) : $limit;
    if ($f['size'] > $lim) return ['ok' => false, 'msg' => 'File is too large (max ' . round($lim / 1048576) . ' MB).'];
    if ($type === 'audio' && !hl_wav_ok($f['tmp_name'])) return ['ok' => false, 'msg' => 'Song clips can be 30 seconds at most.'];
    $dir = __DIR__ . '/' . HL_DIR;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = $prefix . '_' . $uid . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . $name)) return ['ok' => false, 'msg' => 'Could not save the file.'];
    return ['ok' => true, 'path' => HL_DIR . $name, 'type' => $type];
}

// Creates any missing tables/columns once per login session, so you never have to run the SQL by hand.
function hl_ensure_schema($conn) {
    if (!empty($_SESSION['hl_schema_v4'])) return;
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS highlights (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, title VARCHAR(30) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX (user_id, created_at))");
        $conn->query("CREATE TABLE IF NOT EXISTS highlight_media (id INT AUTO_INCREMENT PRIMARY KEY, highlight_id INT NOT NULL, user_id INT NOT NULL, file_path VARCHAR(255) NOT NULL, media_type ENUM('image','video','text') NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX (highlight_id, id))");
        $conn->query("CREATE TABLE IF NOT EXISTS verification_requests (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, plan ENUM('standard','plus','elite') NOT NULL, payment_method VARCHAR(20) NOT NULL, age_confirmed TINYINT(1) NOT NULL DEFAULT 0, status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending', trial_ends_at DATETIME NOT NULL, reviewed_at DATETIME NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX (user_id, status))");
        $need = [
            'highlights' => [
                'cover_path' => "VARCHAR(255) NULL", 'song_path' => "VARCHAR(255) NULL", 'song_title' => "VARCHAR(60) NULL",
                'song_mode' => "ENUM('whole','each') NOT NULL DEFAULT 'whole'",
            ],
            'highlight_media' => [
                'audio_path' => "VARCHAR(255) NULL", 'audio_title' => "VARCHAR(60) NULL", 'mute_video' => "TINYINT(1) NOT NULL DEFAULT 0",
                'caption' => "TEXT NULL",
            ],
            'users' => ['is_verified' => "TINYINT(1) NOT NULL DEFAULT 0"],
        ];
        foreach ($need as $tbl => $cols) {
            foreach ($cols as $col => $def) {
                $r = $conn->query("SHOW COLUMNS FROM `$tbl` LIKE '$col'");
                if ($r && $r->num_rows === 0) $conn->query("ALTER TABLE `$tbl` ADD COLUMN `$col` $def");
            }
        }
        // text-only highlights need media_type to accept 'text'
        $r = $conn->query("SHOW COLUMNS FROM `highlight_media` LIKE 'media_type'");
        if ($r && ($row = $r->fetch_assoc()) && stripos((string)$row['Type'], "'text'") === false) {
            $conn->query("ALTER TABLE `highlight_media` MODIFY `media_type` ENUM('image','video','text') NOT NULL");
        }
        $_SESSION['hl_schema_v4'] = 1;
    } catch (Throwable $e) { /* no ALTER permission: the page still works, run the SQL by hand */ }
}
hl_ensure_schema($conn);

// ── PRIVACY: levels, tags & mentions, list visibility ─────
// Creates the `user_privacy` table once per login session. Your other pages can read it to enforce the choices.
function priv_ensure_schema($conn) {
    if (!empty($_SESSION['priv_schema_v2'])) return;
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS user_privacy (
            user_id INT NOT NULL PRIMARY KEY,
            privacy_level VARCHAR(12) NOT NULL DEFAULT 'public',
            tag_who VARCHAR(10) NOT NULL DEFAULT 'everyone',
            tag_approve TINYINT(1) NOT NULL DEFAULT 0,
            mention_who VARCHAR(10) NOT NULL DEFAULT 'everyone',
            mention_feature TINYINT(1) NOT NULL DEFAULT 0,
            show_trackers VARCHAR(10) NOT NULL DEFAULT 'everyone',
            show_tracking VARCHAR(10) NOT NULL DEFAULT 'everyone',
            show_pods VARCHAR(10) NOT NULL DEFAULT 'everyone',
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
        foreach (['lim_trackers_trk', 'lim_trackers_non', 'lim_tracking_trk', 'lim_tracking_non', 'lim_pods_trk', 'lim_pods_non'] as $c) { // how many entries each group may see (0 = no limit)
            $r = $conn->query("SHOW COLUMNS FROM user_privacy LIKE '$c'");
            if ($r && $r->num_rows === 0) $conn->query("ALTER TABLE user_privacy ADD COLUMN `$c` INT NOT NULL DEFAULT 0");
        }
        $_SESSION['priv_schema_v2'] = 1;
    } catch (Throwable $e) { /* no CREATE permission: create the table by hand */ }
}
priv_ensure_schema($conn);

const PRIV_LEVELS = ['public', 'limited', 'private', 'full'];
// field => [table, bind type, allowed values]
const PRIV_FIELDS = [
    'tag_who'            => ['user_privacy', 's', ['everyone', 'tracked', 'none']],
    'tag_approve'        => ['user_privacy', 'i', [0, 1]],
    'mention_who'        => ['user_privacy', 's', ['everyone', 'tracked', 'none']],
    'mention_feature'    => ['user_privacy', 'i', [0, 1]],
    'show_trackers'      => ['user_privacy', 's', ['everyone', 'trackers', 'me']],
    'show_tracking'      => ['user_privacy', 's', ['everyone', 'trackers', 'me']],
    'show_pods'          => ['user_privacy', 's', ['everyone', 'trackers', 'me']],
    'lim_trackers_trk' => ['user_privacy', 'i', ['min' => -1, 'max' => 10000]],
    'lim_trackers_non' => ['user_privacy', 'i', ['min' => -1, 'max' => 10000]],
    'lim_tracking_trk' => ['user_privacy', 'i', ['min' => -1, 'max' => 10000]],
    'lim_tracking_non' => ['user_privacy', 'i', ['min' => -1, 'max' => 10000]],
    'lim_pods_trk' => ['user_privacy', 'i', ['min' => -1, 'max' => 10000]],
    'lim_pods_non' => ['user_privacy', 'i', ['min' => -1, 'max' => 10000]],
    'allow_messages'     => ['user_settings', 'i', [0, 1]],
    'show_online_status' => ['user_settings', 'i', [0, 1]],
    'online_visibility'    => ['user_settings', 'i', [0, 1, 2]],   /* 0 nobody · 1 my trackers · 2 everyone */
    'last_seen_visibility' => ['user_settings', 'i', [0, 1, 2]],
    'def_hide_likes'     => ['user_settings', 'i', [0, 1]],        /* default settings for new posts */
    'def_hide_reposts'   => ['user_settings', 'i', [0, 1]],
    'def_hide_saves'     => ['user_settings', 'i', [0, 1]],
    'def_hide_shares'    => ['user_settings', 'i', [0, 1]],
    'def_ai_label'       => ['user_settings', 'i', [0, 1]],
    'def_allow_download' => ['user_settings', 'i', [0, 1]],
    'notifications_enabled' => ['user_settings', 'i', [0, 1]],
    'haptics_enabled'    => ['user_settings', 'i', [0, 1]],   /* haptic feedback (Zeems modal and, later, the rest of Dezzme) */
];
function priv_set($conn, $uid, $field, $raw) {
    if (!isset(PRIV_FIELDS[$field])) return false;
    dz_priv_ensure($conn);
    [$tbl, $type, $allowed] = PRIV_FIELDS[$field];
    $val = ($type === 'i') ? (int)$raw : (string)$raw;
    if (isset($allowed['max'])) { if ($val < $allowed['min'] || $val > $allowed['max']) return false; }
    elseif (!in_array($val, $allowed, true)) return false;
    if ($tbl === 'user_privacy') {
        $st = $conn->prepare("INSERT INTO user_privacy (user_id, `$field`) VALUES (?,?) ON DUPLICATE KEY UPDATE `$field` = VALUES(`$field`)");
        $st->bind_param('i' . $type, $uid, $val);
    } else {
        $st = $conn->prepare("UPDATE user_settings SET `$field` = ? WHERE user_id = ?");
        $st->bind_param($type . 'i', $val, $uid);
    }
    $ok = $st->execute(); $st->close();
    /* keep the old on/off switch in step: on only when "everyone" — anything that still reads it hides rather than leaks */
    if ($ok && $field === 'online_visibility') {
        $so = ($val === 2) ? 1 : 0;
        $st = $conn->prepare("UPDATE user_settings SET show_online_status = ? WHERE user_id = ?");
        $st->bind_param('ii', $so, $uid); $st->execute(); $st->close();
    }
    return (bool)$ok;
}

if (isset($_POST['priv_action'])) {
    try {
        $pa = (string)$_POST['priv_action'];
        if ($pa === 'level') {
            $lv = (string)($_POST['level'] ?? '');
            if (!in_array($lv, PRIV_LEVELS, true)) hl_fail('Unknown privacy level.');
            $flag = ($lv === 'private' || $lv === 'full') ? 1 : 0; // Private + Full Private both need tracker approval
            $st = $conn->prepare("UPDATE user_settings SET is_fully_private = ? WHERE user_id = ?");
            $st->bind_param('ii', $flag, $current_user); $st->execute(); $st->close();
            $st = $conn->prepare("INSERT INTO user_privacy (user_id, privacy_level) VALUES (?,?) ON DUPLICATE KEY UPDATE privacy_level = VALUES(privacy_level)");
            $st->bind_param('is', $current_user, $lv); $st->execute(); $st->close();
            hl_json(['success' => true, 'level' => $lv]);
        }
        if ($pa === 'pref') {
            $f = (string)($_POST['field'] ?? '');
            if (!priv_set($conn, $current_user, $f, $_POST['value'] ?? '')) hl_fail('That setting could not be saved.');
            hl_json(['success' => true]);
        }
        if ($pa === 'lists') { // one switch for Trackers + Tracking + Pods lists
            $v = (string)($_POST['value'] ?? '');
            foreach (['show_trackers', 'show_tracking', 'show_pods'] as $f) {
                if (!priv_set($conn, $current_user, $f, $v)) hl_fail('That setting could not be saved.');
            }
            hl_json(['success' => true]);
        }
        hl_fail('Unknown action.');
    } catch (Throwable $e) { hl_fail('Something went wrong. Please try again.'); }
}

// ── ACCOUNT: username (once every 14 days), profile picture upload, deactivate, delete ──
const UN_COOLDOWN_DAYS = 14;                  // how often a username can be changed
const DEL_GRACE_DAYS   = 30;                  // days before a deleted account is really removed
const AV_DIR = 'uploads/avatars/';            // uploaded (cropped) profile pictures
const AV_MAX_BYTES = 5242880;                 // 5 MB

// Adds the columns this feature needs (once per login session).
function acct_ensure_schema($conn) {
    if (!empty($_SESSION['acct_schema_v4'])) return;
    try {
        $need = ['username_changed_at' => 'DATETIME NULL', 'deactivated_at' => 'DATETIME NULL', 'delete_after' => 'DATETIME NULL'];
        foreach ($need as $col => $def) {
            $r = $conn->query("SHOW COLUMNS FROM `users` LIKE '$col'");
            if ($r && $r->num_rows === 0) $conn->query("ALTER TABLE `users` ADD COLUMN `$col` $def");
        }
        // master notifications switch + time-spent table
        $r = $conn->query("SHOW COLUMNS FROM `user_settings` LIKE 'notifications_enabled'");
        if ($r && $r->num_rows === 0) $conn->query("ALTER TABLE `user_settings` ADD COLUMN `notifications_enabled` TINYINT(1) NOT NULL DEFAULT 1");
        $conn->query("CREATE TABLE IF NOT EXISTS user_time_spent (
            user_id INT NOT NULL,
            day DATE NOT NULL,
            seconds INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (user_id, day)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // archive, time zone, privacy check-up
        $add = function ($tbl, $col, $def) use ($conn) {
            try {
                $r = $conn->query("SHOW COLUMNS FROM `$tbl` LIKE '$col'");
                if ($r && $r->num_rows === 0) $conn->query("ALTER TABLE `$tbl` ADD COLUMN `$col` $def");
            } catch (Throwable $e) { /* table or permission missing: add this column by hand */ }
        };
        $add('memes', 'is_archived', 'TINYINT(1) NOT NULL DEFAULT 0');
        $add('memes', 'archived_at', 'DATETIME NULL');
        $add('user_settings', 'timezone', 'VARCHAR(64) NULL');
        $add('user_settings', 'haptics_enabled', 'TINYINT(1) NOT NULL DEFAULT 1');
        $add('user_privacy', 'checkup_at', 'DATETIME NULL');
        $_SESSION['acct_schema_v4'] = 1;
    } catch (Throwable $e) { /* no ALTER permission: add the 3 columns to `users` by hand */ }
}
acct_ensure_schema($conn);

function acct_user($conn, $uid) {
    $st = $conn->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
    $st->bind_param("i", $uid); $st->execute();
    $r = $st->get_result()->fetch_assoc(); $st->close();
    return $r ?: null;
}
// Same check the login page uses (passwords are stored with password_hash()).
function acct_pw_ok($stored, $plain) {
    $stored = (string)$stored; $plain = (string)$plain;
    if ($stored === '' || $plain === '' || strlen($plain) > 1024) return false;
    return password_verify($plain, $stored);
}
function av_unlink($path) { if ($path && strpos($path, AV_DIR) === 0 && strpos($path, '..') === false) @unlink(__DIR__ . '/' . $path); }

// Finds the picture/video path inside a `memes` row, whatever the column is called (text posts have none)
function acct_row_media($r) {
    $isMedia = function ($v) { return is_string($v) && preg_match('/\.(jpe?g|png|gif|webp|avif|bmp|mp4|webm|mov|m4v)(\?.*)?$/i', trim($v)); };
    $low = [];
    foreach ($r as $k => $v) $low[strtolower((string)$k)] = $k;
    foreach (['file_path', 'image_path', 'media_path', 'meme_image', 'image', 'media', 'img', 'photo', 'path', 'file', 'filename', 'url', 'video_path', 'video', 'thumbnail', 'thumb'] as $k) {
        if (isset($low[$k]) && $isMedia($r[$low[$k]])) return trim((string)$r[$low[$k]]);
    }
    foreach ($r as $k => $v) {   // any other column that holds a file path
        if (in_array(strtolower((string)$k), ['caption', 'description', 'title', 'text', 'content', 'body'], true)) continue;
        if ($isMedia($v)) return trim((string)$v);
    }
    return '';
}
// Turns a stored path into one the browser can open (tries the usual upload folders when only a file name is stored)
function acct_media_url($p) {
    if ($p === '' || preg_match('#^(https?:)?//#i', $p)) return $p;
    $p = ltrim($p, '/');
    foreach (['', 'uploads/', 'uploads/memes/', 'uploads/images/', 'uploads/videos/', 'memes/'] as $pre) {
        if (is_file(__DIR__ . '/' . $pre . $p)) return $pre . $p;
    }
    return $p;
}

if (isset($_POST['acct_action'])) {
    try {
        $aa = (string)$_POST['acct_action'];
        $me = acct_user($conn, $current_user);
        if (!$me) hl_fail('Account not found.');

        // ---- username: once every 14 days ----
        if ($aa === 'username') {
            $new = trim((string)($_POST['username'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9_.]{3,30}$/', $new)) hl_fail('Use 3 to 30 letters, numbers, underscores or dots.');
            if ($new === (string)$me['username']) hl_fail('That is already your username.');
            $last = !empty($me['username_changed_at']) ? strtotime($me['username_changed_at']) : 0;
            $next = $last + UN_COOLDOWN_DAYS * 86400;
            if ($last && time() < $next) {
                $d = (int)ceil(($next - time()) / 86400);
                hl_fail('You can change your username again in ' . $d . ' day' . ($d === 1 ? '' : 's') . '.');
            }
            $st = $conn->prepare("SELECT id FROM users WHERE username=? AND id<>? LIMIT 1");
            $st->bind_param("si", $new, $current_user); $st->execute();
            $taken = $st->get_result()->num_rows > 0; $st->close();
            if ($taken) hl_fail('That username is already taken.');
            $st = $conn->prepare("UPDATE users SET username=?, username_changed_at=NOW() WHERE id=?");
            $st->bind_param("si", $new, $current_user);
            $ok = $st->execute(); $st->close();
            if (!$ok) hl_fail('Could not save your username.');
            // verified badge: a username change removes it (see verification_common.php)
            require_once __DIR__ . '/verification_common.php';
            vf_on_username_change($conn, $current_user);
            hl_json(['success' => true, 'username' => $new, 'days' => UN_COOLDOWN_DAYS]);
        }

        // ---- profile picture: upload (already cropped in the browser) ----
        if ($aa === 'avatar') {
            $f = $_FILES['avatar'] ?? null;
            if (!$f || !isset($f['error']) || $f['error'] !== UPLOAD_ERR_OK) {
                $e = $f['error'] ?? -1;
                hl_fail(($e === UPLOAD_ERR_INI_SIZE || $e === UPLOAD_ERR_FORM_SIZE) ? 'That picture is too large for the server.' : 'Upload failed. Please try again.');
            }
            if ($f['size'] > AV_MAX_BYTES) hl_fail('That picture is too large (5 MB max).');
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
            $okTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (!isset($okTypes[$mime])) hl_fail('Use a JPG, PNG or WebP picture.');
            $dir = __DIR__ . '/' . AV_DIR;
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $base = 'av_' . $current_user . '_' . bin2hex(random_bytes(8));
            $saved = false; $name = '';
            if (function_exists('imagecreatefromstring')) {
                // re-encode: removes hidden data from the file and proves it is a real image
                $raw = @file_get_contents($f['tmp_name']);
                $im = $raw ? @imagecreatefromstring($raw) : false;
                if (!$im) hl_fail('That picture could not be read.');
                $w = imagesx($im); $h = imagesy($im); $max = 600;
                if ($w > $max || $h > $max) { $r = min($max / $w, $max / $h); $nw = max(1, (int)round($w * $r)); $nh = max(1, (int)round($h * $r)); }
                else { $nw = $w; $nh = $h; }
                $out = imagecreatetruecolor($nw, $nh);
                imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
                imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
                $name = $base . '.jpg';
                $saved = imagejpeg($out, $dir . $name, 88);
                imagedestroy($im); imagedestroy($out);
            } else {
                $name = $base . '.' . $okTypes[$mime];
                $saved = move_uploaded_file($f['tmp_name'], $dir . $name);
            }
            if (!$saved) hl_fail('Could not save the picture.');
            $path = AV_DIR . $name;
            $old = (string)($me['profile_pic'] ?? '');
            $st = $conn->prepare("UPDATE users SET profile_pic=? WHERE id=?");
            $st->bind_param("si", $path, $current_user);
            $ok = $st->execute(); $st->close();
            if (!$ok) { av_unlink($path); hl_fail('Could not save the picture.'); }
            av_unlink($old);
            hl_json(['success' => true, 'path' => $path]);
        }
        if ($aa === 'avatar_rm') {
            $old = (string)($me['profile_pic'] ?? '');
            $empty = '';
            $st = $conn->prepare("UPDATE users SET profile_pic=? WHERE id=?");
            $st->bind_param("si", $empty, $current_user); $st->execute(); $st->close();
            av_unlink($old);
            hl_json(['success' => true]);
        }

        // ---- deactivate (hide for now) / delete (gone after 30 days) ----
        if ($aa === 'deactivate' || $aa === 'delete') {
            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
            $ih = hash('sha256', 'acct:' . $current_user);
            st_throttle_ready($conn);
            $wait = st_throttle_wait($conn, $ih);
            if ($wait > 0) {
                http_response_code(429);
                $mins = max(1, (int)ceil($wait / 60));
                hl_fail('Too many attempts. Please try again in ' . $mins . ' minute' . ($mins === 1 ? '' : 's') . '.');
            }
            if (!acct_pw_ok($me['password'] ?? '', $_POST['password'] ?? '')) {
                st_throttle_fail($conn, $ip, $ih);
                usleep(random_int(250000, 450000));
                hl_fail('That password is not correct.');
            }
            st_throttle_clear($conn, $ih);
            if ($aa === 'delete' && strtoupper(trim((string)($_POST['confirm'] ?? ''))) !== 'DELETE') hl_fail('Type DELETE to confirm.');
            if ($aa === 'deactivate') {
                $st = $conn->prepare("UPDATE users SET deactivated_at=NOW(), delete_after=NULL WHERE id=?");
            } else {
                $st = $conn->prepare("UPDATE users SET deactivated_at=NOW(), delete_after=DATE_ADD(NOW(), INTERVAL " . (int)DEL_GRACE_DAYS . " DAY) WHERE id=?");
            }
            $st->bind_param("i", $current_user);
            $ok = $st->execute(); $st->close();
            if (!$ok) hl_fail('Could not update your account. Please try again.');
            // sign the person out on this device
            $_SESSION = [];
            if (ini_get('session.use_cookies')) { $p = session_get_cookie_params(); setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']); }
            session_destroy();
            hl_json(['success' => true, 'redirect' => 'login.php?account=' . ($aa === 'delete' ? 'deleted' : 'deactivated')]);
        }

        // ---- changed your mind about deleting ----
        if ($aa === 'cancel_delete') {
            $st = $conn->prepare("UPDATE users SET deactivated_at=NULL, delete_after=NULL WHERE id=?");
            $st->bind_param("i", $current_user); $st->execute(); $st->close();
            hl_json(['success' => true]);
        }

        // ---- time zone ----
        if ($aa === 'timezone') {
            $tz = trim((string)($_POST['tz'] ?? ''));
            if ($tz !== '' && !in_array($tz, DateTimeZone::listIdentifiers(), true)) hl_fail('That time zone is not recognised.');
            $st = $conn->prepare("UPDATE user_settings SET timezone = NULLIF(?, '') WHERE user_id = ?");
            $st->bind_param('si', $tz, $current_user); $st->execute(); $st->close();
            $_SESSION['dz_tz'] = ['u' => (int)$current_user, 'tz' => $tz];
            hl_json(['success' => true, 'tz' => $tz]);
        }

        // ---- word filters (posts / ads) ----
        if ($aa === 'wf_list' || $aa === 'wf_add' || $aa === 'wf_remove' || $aa === 'wf_clear') {
            dz_wf_table($conn);
            $kind = in_array(($_POST['kind'] ?? ''), ['ad', 'comment'], true) ? $_POST['kind'] : 'post';
            if ($aa === 'wf_add') {
                $new = dz_wf_parse((string)($_POST['words'] ?? ''));
                if (!$new) hl_fail('Type a word first.');
                $st = $conn->prepare("SELECT COUNT(*) FROM user_word_filters WHERE user_id = ? AND kind = ?");
                $st->bind_param('is', $current_user, $kind); $st->execute(); $st->bind_result($have); $st->fetch(); $st->close();
                $room = DZ_WF_MAX_WORDS - (int)$have;
                if ($room <= 0) hl_fail('You can filter up to ' . DZ_WF_MAX_WORDS . ' words here. Remove one to add another.');
                $ins = $conn->prepare("INSERT IGNORE INTO user_word_filters (user_id, kind, word) VALUES (?, ?, ?)");
                foreach (array_slice($new, 0, $room) as $w) { $ins->bind_param('iss', $current_user, $kind, $w); $ins->execute(); }
                $ins->close();
            } elseif ($aa === 'wf_remove') {
                $w = mb_strtolower(trim((string)($_POST['word'] ?? '')), 'UTF-8');
                $st = $conn->prepare("DELETE FROM user_word_filters WHERE user_id = ? AND kind = ? AND word = ?");
                $st->bind_param('iss', $current_user, $kind, $w); $st->execute(); $st->close();
            } elseif ($aa === 'wf_clear') {
                $st = $conn->prepare("DELETE FROM user_word_filters WHERE user_id = ? AND kind = ?");
                $st->bind_param('is', $current_user, $kind); $st->execute(); $st->close();
            }
            $out = ['post' => [], 'ad' => [], 'comment' => []];
            $st = $conn->prepare("SELECT kind, word FROM user_word_filters WHERE user_id = ? ORDER BY id DESC");
            $st->bind_param('i', $current_user); $st->execute();
            $rs = $st->get_result();
            while ($rs && ($row = $rs->fetch_assoc())) $out[$row['kind']][] = $row['word'];
            $st->close();
            hl_json(['success' => true, 'lists' => $out, 'max' => DZ_WF_MAX_WORDS]);
        }

        // ---- muted Pods ----
        if ($aa === 'pod_unmute') {
            $gid = (int)($_POST['group_id'] ?? 0);
            if ($gid <= 0) hl_fail('Pod not found.');
            foreach (['group_notif_off', 'muted_groups'] as $mt) {
                try {
                    $st = $conn->prepare("DELETE FROM `$mt` WHERE user_id = ? AND group_id = ?");
                    $st->bind_param('ii', $current_user, $gid); $st->execute(); $st->close();
                } catch (Throwable $e) { /* table not there */ }
            }
            hl_json(['success' => true]);
        }

        // ---- privacy check-up: "Looks good" ----
        if ($aa === 'checkup') {
            $st = $conn->prepare("INSERT INTO user_privacy (user_id, checkup_at) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE checkup_at = NOW()");
            $st->bind_param('i', $current_user); $st->execute(); $st->close();
            hl_json(['success' => true, 'when' => date('j M Y')]);
        }

        // ---- archive ----
        if ($aa === 'meme_list') {
            $tab = (($_POST['tab'] ?? '') === 'archived') ? 1 : 0;
            $off = max(0, (int)($_POST['offset'] ?? 0));
            $lim = 24; $lim1 = $lim + 1;
            $st = $conn->prepare("SELECT * FROM memes WHERE uploaded_by = ? AND is_archived = ? ORDER BY id DESC LIMIT ?, ?");
            $st->bind_param('iiii', $current_user, $tab, $off, $lim1); $st->execute();
            $res = $st->get_result(); $items = []; $more = false;
            while ($r = $res->fetch_assoc()) {
                if (count($items) >= $lim) { $more = true; break; }
                $media = acct_media_url(acct_row_media($r));
                $cap = trim(strip_tags((string)($r['caption'] ?? '')));
                $cap = function_exists('mb_substr') ? mb_substr($cap, 0, 90) : substr($cap, 0, 90);
                $items[] = ['id' => (int)$r['id'], 'cap' => $cap, 'date' => date('j M Y', strtotime((string)($r['created_at'] ?? 'now'))),
                            'media' => $media, 'video' => (bool)preg_match('/\.(mp4|webm|mov|m4v)(\?|$)/i', $media)];
            }
            $st->close();
            hl_json(['success' => true, 'items' => $items, 'more' => $more]);
        }
        if ($aa === 'meme_archive' || $aa === 'meme_restore') {
            $mid = (int)($_POST['meme_id'] ?? 0);
            $to  = ($aa === 'meme_archive') ? 1 : 0;
            $st = $conn->prepare("SELECT id FROM memes WHERE id = ? AND uploaded_by = ? LIMIT 1");
            $st->bind_param('ii', $mid, $current_user); $st->execute();
            $found = $st->get_result()->num_rows > 0; $st->close();
            if (!$found) hl_fail('Post not found.');
            $st = $conn->prepare("UPDATE memes SET is_archived = ?, archived_at = IF(? = 1, NOW(), NULL) WHERE id = ? AND uploaded_by = ?");
            $st->bind_param('iiii', $to, $to, $mid, $current_user);
            $ok = $st->execute(); $st->close();
            if (!$ok) hl_fail('Could not update that post.');
            hl_json(['success' => true]);
        }

        hl_fail('Unknown action.');
    } catch (Throwable $e) { hl_fail('Something went wrong. Please try again.'); }
}

if (isset($_POST['hl_action'])) {
    try {
        $act = (string)$_POST['hl_action'];
        $MB = 1048576;

        if ($act === 'create') {
            $title = hl_title($_POST['title'] ?? '', 30);
            if ($title === '') hl_fail('Give your highlight a name.');
            $mode = (($_POST['mode'] ?? '') === 'each') ? 'each' : 'whole';
            $st = $conn->prepare("INSERT INTO highlights (user_id, title, song_mode) VALUES (?,?,?)");
            $st->bind_param("iss", $current_user, $title, $mode);
            $ok = $st->execute(); $new_id = $conn->insert_id; $st->close();
            $ok ? hl_json(['success' => true, 'id' => $new_id]) : hl_fail('Could not create highlight.');
        }

        // Finds which @usernames / @nicknames and which group names inside a text really exist.
        // The profile page can call this too, so tags turn red / blue and become clickable there as well.
        if ($act === 'resolve') {
            $text = hl_text($_POST['text'] ?? '', HL_MAX_TEXT);
            $users = []; $groups = [];
            if (preg_match_all('/@([A-Za-z0-9_.]{2,40})/u', $text, $mm)) {
                $names = [];
                foreach ($mm[1] as $n) { $n = rtrim($n, '.'); if (strlen($n) >= 2) $names[strtolower($n)] = $n; }
                foreach (array_slice($names, 0, 30, true) as $low => $n) {
                    $st = $conn->prepare("SELECT id FROM users WHERE username=? OR nickname=? LIMIT 1");
                    $st->bind_param("ss", $n, $n); $st->execute();
                    $u = $st->get_result()->fetch_assoc(); $st->close();
                    if ($u) $users[$low] = (int)$u['id'];
                }
            }
            if ($text !== '') {
                try { // `groups` table: id + title (the group's name)
                    $st = $conn->prepare("SELECT id, title FROM `groups` WHERE CHAR_LENGTH(title) >= 2 AND LOCATE(LOWER(title), LOWER(?)) > 0 ORDER BY CHAR_LENGTH(title) DESC LIMIT 20");
                    $st->bind_param("s", $text); $st->execute();
                    $res = $st->get_result();
                    while ($g = $res->fetch_assoc()) $groups[] = ['id' => (int)$g['id'], 'name' => $g['title']];
                    $st->close();
                } catch (Throwable $e) { $groups = []; }
            }
            hl_json(['success' => true, 'users' => (object)$users, 'groups' => $groups]);
        }

        $hid = (int)($_POST['highlight_id'] ?? 0);
        $H = $hid ? hl_fetch($conn, $hid, $current_user) : null;
        if (!$H) hl_fail('Highlight not found.');

        switch ($act) {

            case 'get':
                $st = $conn->prepare("SELECT id, file_path, media_type, caption, audio_path, audio_title, mute_video, created_at FROM highlight_media WHERE highlight_id=? ORDER BY id ASC");
                $st->bind_param("i", $hid); $st->execute();
                $res = $st->get_result(); $media = [];
                while ($m = $res->fetch_assoc()) {
                    $media[] = ['id' => (int)$m['id'], 'path' => $m['file_path'], 'type' => $m['media_type'], 'caption' => (string)($m['caption'] ?? ''),
                        'audio' => $m['audio_path'], 'audio_title' => $m['audio_title'], 'mute' => (int)$m['mute_video'],
                        'added' => date('j M Y', strtotime($m['created_at']))];
                }
                $st->close();
                hl_json(['success' => true, 'h' => ['id' => (int)$H['id'], 'title' => $H['title'], 'cover' => $H['cover_path'],
                    'song' => $H['song_path'], 'song_title' => $H['song_title'], 'mode' => ($H['song_mode'] ?? 'whole'), 'media' => $media]]);

            case 'set_mode':
                // 'whole' = one song for every media, 'each' = every media has its own song. Songs are kept when you switch.
                $mode = (($_POST['mode'] ?? '') === 'each') ? 'each' : 'whole';
                $st = $conn->prepare("UPDATE highlights SET song_mode=? WHERE id=?");
                $st->bind_param("si", $mode, $hid); $st->execute(); $st->close();
                hl_json(['success' => true]);

            case 'rename':
                $title = hl_title($_POST['title'] ?? '', 30);
                if ($title === '') hl_fail('Give your highlight a name.');
                $st = $conn->prepare("UPDATE highlights SET title=? WHERE id=?");
                $st->bind_param("si", $title, $hid); $st->execute(); $st->close();
                hl_json(['success' => true]);

            case 'add_media':
                $st = $conn->prepare("SELECT COUNT(*) AS c FROM highlight_media WHERE highlight_id=?");
                $st->bind_param("i", $hid); $st->execute();
                $have = (int)($st->get_result()->fetch_assoc()['c'] ?? 0); $st->close();
                if ($have >= HL_MAX_MEDIA) hl_fail('A highlight can hold up to ' . HL_MAX_MEDIA . ' media.');
                $r = hl_save($_FILES['media'] ?? null, hl_types('media'), ['image' => 10 * $MB, 'video' => 50 * $MB], 'hl', $current_user);
                if (!$r['ok']) hl_fail($r['msg']);
                $cap = hl_text($_POST['caption'] ?? '', HL_MAX_TEXT);
                // created_at is filled by MySQL, so every media remembers the day it was added
                $st = $conn->prepare("INSERT INTO highlight_media (highlight_id, user_id, file_path, media_type, caption) VALUES (?,?,?,?,?)");
                $st->bind_param("iisss", $hid, $current_user, $r['path'], $r['type'], $cap);
                $ok = $st->execute(); $new_mid = $conn->insert_id; $st->close();
                if (!$ok) { hl_unlink($r['path']); hl_fail('Could not save the file.'); }
                hl_json(['success' => true, 'id' => $new_mid]);

            case 'add_text':
                // a text-only media: no file, it is shown on the Text.jpg background
                $st = $conn->prepare("SELECT COUNT(*) AS c FROM highlight_media WHERE highlight_id=?");
                $st->bind_param("i", $hid); $st->execute();
                $have = (int)($st->get_result()->fetch_assoc()['c'] ?? 0); $st->close();
                if ($have >= HL_MAX_MEDIA) hl_fail('A highlight can hold up to ' . HL_MAX_MEDIA . ' media.');
                $txt = hl_text($_POST['text'] ?? '', HL_MAX_TEXT);
                if ($txt === '') hl_fail('Write some text first.');
                $st = $conn->prepare("INSERT INTO highlight_media (highlight_id, user_id, file_path, media_type, caption) VALUES (?,?,'','text',?)");
                $st->bind_param("iis", $hid, $current_user, $txt);
                $ok = $st->execute(); $new_mid = $conn->insert_id; $st->close();
                if (!$ok) hl_fail('Could not save the text.');
                hl_json(['success' => true, 'id' => $new_mid]);

            case 'set_caption':
                $mid = (int)($_POST['media_id'] ?? 0);
                $M = $mid ? hl_media($conn, $mid, $hid) : null;
                if (!$M) hl_fail('Media not found.');
                $txt = hl_text($_POST['text'] ?? '', HL_MAX_TEXT);
                if ($M['media_type'] === 'text' && $txt === '') hl_fail('A text media needs some text.');
                $st = $conn->prepare("UPDATE highlight_media SET caption=? WHERE id=?");
                $st->bind_param("si", $txt, $mid); $st->execute(); $st->close();
                hl_json(['success' => true]);

            case 'del_media':
                $mid = (int)($_POST['media_id'] ?? 0);
                $M = $mid ? hl_media($conn, $mid, $hid) : null;
                if (!$M) hl_fail('Media not found.');
                $st = $conn->prepare("SELECT COUNT(*) AS c FROM highlight_media WHERE highlight_id=?");
                $st->bind_param("i", $hid); $st->execute();
                $have = (int)($st->get_result()->fetch_assoc()['c'] ?? 0); $st->close();
                if ($have <= 1) hl_fail('A highlight needs at least one media. Delete the highlight instead.');
                hl_unlink($M['file_path']); hl_unlink($M['audio_path']);
                $st = $conn->prepare("DELETE FROM highlight_media WHERE id=? AND highlight_id=?");
                $st->bind_param("ii", $mid, $hid); $st->execute(); $st->close();
                hl_json(['success' => true]);

            case 'set_cover':
                $r = hl_save($_FILES['cover'] ?? null, hl_types('image'), 10 * $MB, 'cover', $current_user);
                if (!$r['ok']) hl_fail($r['msg']);
                hl_unlink($H['cover_path']);
                $st = $conn->prepare("UPDATE highlights SET cover_path=? WHERE id=?");
                $st->bind_param("si", $r['path'], $hid); $st->execute(); $st->close();
                hl_json(['success' => true]);

            case 'rm_cover':
                hl_unlink($H['cover_path']);
                $st = $conn->prepare("UPDATE highlights SET cover_path=NULL WHERE id=?");
                $st->bind_param("i", $hid); $st->execute(); $st->close();
                hl_json(['success' => true]);

            case 'set_song':
                $r = hl_save($_FILES['song'] ?? null, hl_types('audio'), 7 * $MB, 'song', $current_user);
                if (!$r['ok']) hl_fail($r['msg']);
                hl_unlink($H['song_path']);
                $t = hl_title($_POST['title'] ?? '', 60);
                $st = $conn->prepare("UPDATE highlights SET song_path=?, song_title=? WHERE id=?");
                $st->bind_param("ssi", $r['path'], $t, $hid); $st->execute(); $st->close();
                hl_json(['success' => true]);

            case 'rm_song':
                hl_unlink($H['song_path']);
                $st = $conn->prepare("UPDATE highlights SET song_path=NULL, song_title=NULL WHERE id=?");
                $st->bind_param("i", $hid); $st->execute(); $st->close();
                hl_json(['success' => true]);

            case 'media_song':
                $mid = (int)($_POST['media_id'] ?? 0);
                $M = $mid ? hl_media($conn, $mid, $hid) : null;
                if (!$M) hl_fail('Media not found.');
                $r = hl_save($_FILES['song'] ?? null, hl_types('audio'), 7 * $MB, 'msong', $current_user);
                if (!$r['ok']) hl_fail($r['msg']);
                hl_unlink($M['audio_path']);
                $t = hl_title($_POST['title'] ?? '', 60);
                $st = $conn->prepare("UPDATE highlight_media SET audio_path=?, audio_title=? WHERE id=?");
                $st->bind_param("ssi", $r['path'], $t, $mid); $st->execute(); $st->close();
                hl_json(['success' => true]);

            case 'media_song_rm':
                $mid = (int)($_POST['media_id'] ?? 0);
                $M = $mid ? hl_media($conn, $mid, $hid) : null;
                if (!$M) hl_fail('Media not found.');
                hl_unlink($M['audio_path']);
                $st = $conn->prepare("UPDATE highlight_media SET audio_path=NULL, audio_title=NULL WHERE id=?");
                $st->bind_param("i", $mid); $st->execute(); $st->close();
                hl_json(['success' => true]);

            case 'media_mute':
                $mid = (int)($_POST['media_id'] ?? 0);
                $M = $mid ? hl_media($conn, $mid, $hid) : null;
                if (!$M) hl_fail('Media not found.');
                $mute = !empty($_POST['mute']) ? 1 : 0;
                $st = $conn->prepare("UPDATE highlight_media SET mute_video=? WHERE id=?");
                $st->bind_param("ii", $mute, $mid); $st->execute(); $st->close();
                hl_json(['success' => true]);

            case 'delete':
                $st = $conn->prepare("SELECT file_path, audio_path FROM highlight_media WHERE highlight_id=?");
                $st->bind_param("i", $hid); $st->execute();
                $res = $st->get_result();
                while ($r = $res->fetch_assoc()) { hl_unlink($r['file_path']); hl_unlink($r['audio_path']); }
                $st->close();
                hl_unlink($H['cover_path']); hl_unlink($H['song_path']);
                $st = $conn->prepare("DELETE FROM highlight_media WHERE highlight_id=?");
                $st->bind_param("i", $hid); $st->execute(); $st->close();
                $st = $conn->prepare("DELETE FROM highlights WHERE id=? AND user_id=?");
                $st->bind_param("ii", $hid, $current_user); $st->execute(); $st->close();
                hl_json(['success' => true]);
        }
        hl_fail('Unknown action.');
    } catch (Throwable $e) { hl_fail('Something went wrong.'); }
}

$profile_id = isset($_GET['user']) ? intval($_GET['user']) : $current_user;

// ── CHECK AVAILABILITY AJAX ───────────────────────────────
if (isset($_POST['check_availability'])) {
    header('Content-Type: application/json');
    $field = $_POST['field'] ?? '';
    $val   = trim($_POST['value'] ?? '');
    if (!in_array($field, ['username', 'nickname'])) { echo json_encode(['status' => 'error']); exit; }
    if (strlen($val) < 2) { echo json_encode(['status' => 'short']); exit; }

    $st = $conn->prepare("SELECT id FROM users WHERE `$field` = ? AND id <> ? LIMIT 1");
    $st->bind_param('si', $val, $current_user); $st->execute();
    $taken = $st->get_result()->num_rows > 0; $st->close();
    if (!$taken) { echo json_encode(['status' => 'available', 'suggestions' => []]); exit; }

    $suggestions = [];
    $base = preg_replace('/[^a-zA-Z0-9_]/', '', $val);
    $candidates = [
        $base . rand(10, 99), $base . '_' . rand(1, 999), $base . date('y'), '_' . $base . '_',
        $base . 'x', $base . rand(100, 999), $base . '_peura', 'the_' . $base,
    ];
    $chk = $conn->prepare("SELECT id FROM users WHERE `$field` = ? AND id <> ? LIMIT 1");
    foreach ($candidates as $c) {
        if (count($suggestions) >= 4) break;
        $chk->bind_param('si', $c, $current_user); $chk->execute();
        if ($chk->get_result()->num_rows === 0) $suggestions[] = $c;
    }
    $chk->close();
    echo json_encode(['status' => 'taken', 'suggestions' => $suggestions]); exit;
}

// ── GET USER ──────────────────────────────────────────────
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $profile_id); $stmt->execute();
$user = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$user) { echo "User not found."; exit; }

// ── GET / CREATE SETTINGS ─────────────────────────────────
$stmt = $conn->prepare("SELECT * FROM user_settings WHERE user_id = ? LIMIT 1");
$stmt->bind_param("i", $profile_id); $stmt->execute();
$settings = $stmt->get_result()->fetch_assoc(); $stmt->close();

if (!$settings) {
    $bio = $pronouns = $other_accounts = null;
    $activity_vibe = ''; $user_type_default = '';
    $vals = [$profile_id, 0, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, $bio, $pronouns, $activity_vibe, $other_accounts, $user_type_default];
    $ins = $conn->prepare("INSERT INTO user_settings
        (user_id,is_private,show_profile_pic,allow_messages,show_online_status,
         allow_anonymous_post,notify_likes,notify_follows,notify_messages,
         dark_mode,autoplay_videos,reduce_animation,Bio,pronouns,activity_vibe,other_accounts,user_type)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $ins->bind_param("iiiiiiiiiiiisssss", ...$vals); // 12 ints + 5 strings
    $ins->execute(); $ins->close();

    $stmt = $conn->prepare("SELECT * FROM user_settings WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $profile_id); $stmt->execute();
    $settings = $stmt->get_result()->fetch_assoc(); $stmt->close();
}

// ── SAFE DEFAULTS ─────────────────────────────────────────
foreach (['activity_vibe', 'user_type', 'profile_type', 'other_accounts', 'Bio', 'pronouns'] as $k) {
    $settings[$k] = $settings[$k] ?? '';
}
$user['bio']      = $settings['Bio'];
$user['pronouns'] = $user['pronouns'] ?? $settings['pronouns'] ?? '';

// ── PROFILE TYPE CATEGORIES ───────────────────────────────
$profile_categories = [
  '🎬 Content & Creator' => ['Content Creator','Digital Creator','Influencer','Social Media Creator','Video Creator','Video Blogger / Vlogger','Blogger','Streamer','Livestreamer','Podcaster','Writer','Author','Poet','Screenwriter','Copywriter','Journalist','News Creator','Meme Creator','Meme Curator','Photographer','Videographer','Filmmaker','Director','Producer','Animator','Voice Actor','Voice-over Artist'],
  '🎨 Art & Design' => ['Artist','Digital Artist','Painter','Sculptor','Illustrator','Graphic Designer','UI Designer','UX Designer','Product Designer','Fashion Designer','Jewelry Designer','Interior Designer','3D Artist','3D Designer','Art Director','Creative Director','Makeup Artist','Tattoo Artist','Nail Artist','Hair Stylist','Fashion Stylist'],
  '🎵 Music & Entertainment' => ['Musician','Singer','Songwriter','Rapper','DJ','Composer','Instrumentalist','Dancer','Choreographer','Actor','Actress','Model','Comedian','Entertainer','Performer','Magician','Celebrity','Public Figure'],
  '💻 Technology' => ['Software Engineer','Software Developer','Web Developer','Mobile App Developer','Front-End Developer','Back-End Developer','Full-Stack Developer','Game Developer','Game Designer','UI/UX Designer','Data Scientist','Data Analyst','AI Engineer','Machine Learning Engineer','Cybersecurity Specialist','Ethical Hacker','DevOps Engineer','Cloud Engineer','IT Specialist','Systems Administrator','Database Administrator','Computer Scientist','Tech Entrepreneur','Tech Reviewer'],
  '💼 Business & Professional' => ['Entrepreneur','Founder','CEO','Executive','Business Owner','Consultant','Freelancer','Freelancer/Independent Professional','Investor','Financial Analyst','Accountant','Banker','Economist','Real Estate Agent','Realtor','Salesperson','Marketing Specialist','Brand Strategist','Business Strategist','Recruiter','Human Resources Professional','Project Manager','Product Manager','Operations Manager'],
  '📣 Marketing & Social Media' => ['Social Media Manager','Community Manager','Social Media Strategist','Digital Marketer','Marketing Manager','Brand Manager','Public Relations Specialist','Publicist','SEO Specialist','Advertising Specialist','Influencer Manager','Talent Manager','Brand Ambassador','Affiliate Marketer'],
  '🔬 Science & Medicine' => ['Scientist','Researcher','Biologist','Chemist','Physicist','Mathematician','Engineer','Doctor','Surgeon','Dentist','Pharmacist','Nurse','Therapist','Psychologist','Veterinarian','Nutritionist','Dietitian','Medical Researcher','Healthcare Professional'],
  '⚖️ Law, Government & Public Service' => ['Lawyer','Attorney','Judge','Legal Consultant','Paralegal','Politician','Civil Servant','Government Official','Diplomat','Activist','Human Rights Advocate','Community Organizer','Nonprofit Worker','Policy Analyst'],
  '🎓 Education' => ['Student','Teacher','Professor','Lecturer','Tutor','Academic','Educational Creator','Education Consultant','School Administrator','Librarian','Academic Advisor','Coach','Mentor'],
  '🏋️ Health, Fitness & Lifestyle' => ['Personal Trainer','Fitness Coach','Athlete','Sports Professional','Yoga Instructor','Wellness Coach','Nutrition Coach','Life Coach','Lifestyle Creator','Health Educator','Chef','Baker','Food Creator'],
  '✈️ Travel & Hospitality' => ['Travel Creator','Travel Blogger','Travel Agent','Tour Guide','Pilot','Flight Attendant','Hotelier','Hotel Manager','Event Planner','Wedding Planner','Hospitality Professional','Food Critic','Restaurant Owner'],
  '👗 Fashion & Beauty' => ['Fashion Model','Fashion Creator','Fashion Blogger','Stylist','Personal Stylist','Beauty Creator','Hairstylist','Barber','Esthetician','Cosmetic Artist','Fashion Buyer','Fashion Photographer'],
  '🏗️ Trades & Skilled Work' => ['Electrician','Plumber','Carpenter','Mechanic','Welder','Builder','Construction Worker','Architect','Surveyor','Mason','Technician','Auto Technician','Tailor','Seamstress','Florist','Gardener','Farmer','Landscaper'],
  '📰 Media & Communications' => ['Reporter','Editor','News Anchor','Broadcaster','Radio Host','TV Host','Presenter','Correspondent','Photojournalist','Public Relations Professional','Communications Specialist'],
  '🕹️ Gaming' => ['Gamer','Professional Gamer','Esports Player','Esports Coach','Gaming Creator','Gaming Streamer','Game Reviewer','Gaming Commentator','Esports Analyst'],
  '🛍️ Commerce & Retail' => ['Shop Owner','Retailer','Online Seller','E-commerce Seller','Reseller','Dropshipper','Product Reviewer','Merchant','Wholesaler','Distributor'],
  '🧑‍💼 Other / Personal' => ['Retired','Homemaker','Stay-at-home Parent','Job Seeker','Intern','Apprentice','Volunteer','Military Personnel','Religious Worker','Self-employed','Independent Professional','Private Individual','Other'],
];

// ── ORGANISATION / ENTITY TYPES (for pages that represent a business, brand, institution, club…) ──
$org_categories = [
  '💻 Digital, internet & technology' => ['Digital Platform','Social Media Platform','Media Platform','Streaming Platform','Digital Community','Online Marketplace','E-commerce Platform','Software Company','Technology Company','SaaS Company','App Developer','Web Service','Internet Service','AI Company','AI Developer','Tech Startup','Cybersecurity Company','Cloud Service Provider','IT Services','Web Development Agency','Digital Product','Tech Brand','Gaming Platform','Fintech Platform','Digital Infrastructure'],
  '📺 Media, entertainment & publishing' => ['Media Brand','Media Company','Digital Publisher','News Organization','News Outlet','Magazine','Newspaper','Editorial Brand','Broadcasting Network','Television Network','Radio Station','Podcast Network','Production Company','Film Studio','Record Label','Music Publisher','Entertainment Company','Entertainment Brand','Streaming Network','Documentary Studio','Animation Studio','Publishing House','Book Publisher','Comic Publisher','Independent Media'],
  '🏢 Businesses, commerce & brands' => ['Business','Company','Corporate Brand','Consumer Brand','Retail Brand','Product Brand','Luxury Brand','Fashion Brand','Beauty Brand','Lifestyle Brand','Franchise','Startup','Small Business','Local Business','Online Store','Retail Store','Wholesale Business','Distributor','Manufacturer','Supplier','Marketplace Seller','Service Business','Family Business','Business Group','Holding Company'],
  '🎨 Creative & design industries' => ['Creative Studio','Design Studio','Graphic Design Studio','Branding Agency','Advertising Agency','Art Studio','Illustration Studio','Photography Studio','Film Production Studio','Animation Company','Creative Agency','Fashion House','Fashion Label','Interior Design Studio','Architecture Studio','Music Studio','Sound Studio','Creative Collective','Art Collective','Design Collective','Digital Art Brand','Production Agency'],
  '🎓 Education & knowledge' => ['Educational Institution','University','College','School','Academy','Training Institute','Learning Platform','Education Company','Educational Brand','Research Institute','Research Centre','Science Organization','Academic Department','Professional Institute','Online School','Tutoring Centre','Educational Publisher','Knowledge Platform','Learning Community','Study Organization'],
  '🏛️ Government, public & civic institutions' => ['Government Agency','Government Department','Public Institution','Public Service','Municipal Authority','City Administration','Regional Government','National Agency','Embassy','Consulate','Public Information Office','Political Organization','Civic Organization','Electoral Organization','Public Broadcasting','Law Enforcement Agency','Public Research Agency'],
  '🤝 Nonprofits, advocacy & social organizations' => ['Nonprofit Organization','Charity','Foundation','NGO','Advocacy Organization','Humanitarian Organization','Community Organization','Social Impact Organization','Volunteer Organization','Environmental Organization','Conservation Organization','Cultural Organization','Human Rights Organization','Youth Organization','Religious Organization','Welfare Organization','Fundraising Organization','Campaign Organization'],
  '💰 Finance, investment & professional services' => ['Financial Institution','Bank','Fintech Company','Investment Firm','Venture Capital Firm','Private Equity Firm','Insurance Company','Financial Services Company','Accounting Firm','Consulting Firm','Business Consultancy','Legal Firm','Law Office','Wealth Management Firm','Brokerage','Payment Service','Credit Institution','Financial Education Brand'],
  '🩺 Health, medicine & wellness' => ['Healthcare Institution','Hospital','Clinic','Medical Practice','Medical Centre','Health Organization','Health Brand','Pharmaceutical Company','Medical Technology Company','Laboratory','Diagnostic Centre','Health Research Institute','Wellness Brand','Fitness Brand','Mental Health Organization','Medical Publisher','Healthcare Provider Network'],
  '🏆 Sports & athletics' => ['Sports Organization','Sports Club','Sports Team','Football Club','Athletic Association','Sports Federation','Sports League','Sports Academy','Sports Brand','Sports Media','Sports Agency','Sports Management Company','Tournament Organizer','Sporting Event','Fitness Organization','Esports Organization','Esports Team'],
  '🍽️ Travel, hospitality & food' => ['Hospitality Brand','Hotel','Hotel Group','Resort','Travel Company','Travel Agency','Tour Operator','Tourism Organization','Tourism Board','Airline','Transportation Company','Restaurant','Restaurant Group','Food Brand','Beverage Brand','Food Company','Catering Company','Café','Bakery','Food Service','Travel Platform'],
  '🏗️ Real estate, construction & infrastructure' => ['Real Estate Company','Property Developer','Real Estate Agency','Property Management Company','Construction Company','Architecture Firm','Engineering Firm','Infrastructure Company','Housing Organization','Property Investment Firm','Building Contractor','Interior Design Company','Urban Development Organization','Facilities Management Company'],
  '🔬 Science, nature & environment' => ['Scientific Organization','Science Institution','Research Organization','Scientific Laboratory','Space Organization','Space Company','Environmental Organization','Conservation Group','Wildlife Organization','Botanical Institution','Zoological Institution','Museum','Natural History Institution','Observatory','Science Media','Scientific Publisher','Climate Organization'],
  '👥 Communities, clubs & membership organizations' => ['Community','Professional Association','Membership Organization','Club','Social Club','Alumni Association','Cultural Association','Interest Organization','Hobby Organization','Fan Organization','Fan Club','Community Network','Professional Network','Trade Association','Industry Association','Cooperative','Guild','Union'],
  '🎪 Events, exhibitions & experiences' => ['Event Organizer','Event Company','Event Brand','Exhibition Organizer','Festival Organizer','Conference Organizer','Convention Organizer','Trade Show Organizer','Cultural Event','Music Festival','Art Exhibition','Event Venue','Experience Brand','Entertainment Venue','Event Production Company'],
  '👗 Fashion, beauty & personal lifestyle' => ['Fashion Company','Clothing Brand','Fashion Retailer','Fashion Designer Label','Jewelry Brand','Accessories Brand','Beauty Company','Cosmetics Brand','Skincare Brand','Haircare Brand','Fragrance Brand','Grooming Brand','Lifestyle Company','Lifestyle Publisher','Homeware Brand','Furniture Brand'],
];

// ── FOLLOW CHECK + COUNTS ─────────────────────────────────
$follow_check = false;
$stmt = $conn->prepare("SELECT id FROM followers WHERE follower_id=? AND following_id=?");
$stmt->bind_param("ii", $current_user, $profile_id); $stmt->execute();
$follow_check = ($stmt->get_result()->num_rows > 0); $stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM followers WHERE following_id=?");
$stmt->bind_param("i", $profile_id); $stmt->execute();
$followers_count = $stmt->get_result()->fetch_assoc()['cnt'] ?? 0; $stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM followers WHERE follower_id=?");
$stmt->bind_param("i", $profile_id); $stmt->execute();
$following_count = $stmt->get_result()->fetch_assoc()['cnt'] ?? 0; $stmt->close();

// ── BLOCKED / MUTED / RESTRICTED ──────────────────────────
$blocked_users = $conn->query("SELECT u.id, u.username, u.profile_pic, u.is_verified FROM blocks b JOIN users u ON u.id = b.blocked_id WHERE b.blocker_id = $current_user ORDER BY u.username ASC");
$muted_users = $conn->query("SELECT u.id, u.username, u.profile_pic, u.is_verified FROM muted_users m JOIN users u ON u.id = m.muted_id WHERE m.muter_id = $current_user ORDER BY u.username ASC");
$restricted_users = $conn->query("SELECT u.id, u.username, u.profile_pic, u.is_verified FROM restricted_users r JOIN users u ON u.id = r.restricted_id WHERE r.restrictor_id = $current_user ORDER BY u.username ASC");

// ── PENDING TRACK REQUESTS ────────────────────────────────
$pending_requests = [];
$stmt = $conn->prepare("SELECT tr.requester_id, tr.created_at, u.username, u.profile_pic, u.is_verified FROM track_requests tr JOIN users u ON u.id = tr.requester_id WHERE tr.target_id = ? AND tr.status = 'pending' ORDER BY tr.created_at DESC");
$stmt->bind_param("i", $current_user); $stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) $pending_requests[] = $row;
$stmt->close();

// ── MY HIGHLIGHTS (cover = your custom cover, else the first media uploaded) ──
$my_highlights = [];
try {
    $stmt = $conn->prepare("SELECT h.id, h.title, h.cover_path, h.song_path,
        (SELECT COUNT(*) FROM highlight_media m WHERE m.highlight_id = h.id) AS media_count,
        (SELECT m2.file_path FROM highlight_media m2 WHERE m2.highlight_id = h.id ORDER BY m2.id ASC LIMIT 1) AS first_path,
        (SELECT m3.media_type FROM highlight_media m3 WHERE m3.highlight_id = h.id ORDER BY m3.id ASC LIMIT 1) AS first_type,
        (SELECT m4.caption FROM highlight_media m4 WHERE m4.highlight_id = h.id ORDER BY m4.id ASC LIMIT 1) AS first_caption
        FROM highlights h WHERE h.user_id = ? HAVING media_count > 0 ORDER BY h.created_at DESC, h.id DESC");
    $stmt->bind_param("i", $current_user); $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $my_highlights[] = $row;
    $stmt->close();
} catch (Throwable $e) {
    // new columns not there yet: fall back to the basic query so older highlights still show
    try {
        $my_highlights = [];
        $stmt = $conn->prepare("SELECT h.id, h.title, NULL AS cover_path, NULL AS song_path,
            (SELECT COUNT(*) FROM highlight_media m WHERE m.highlight_id = h.id) AS media_count,
            (SELECT m2.file_path FROM highlight_media m2 WHERE m2.highlight_id = h.id ORDER BY m2.id ASC LIMIT 1) AS first_path,
            (SELECT m3.media_type FROM highlight_media m3 WHERE m3.highlight_id = h.id ORDER BY m3.id ASC LIMIT 1) AS first_type,
            NULL AS first_caption
            FROM highlights h WHERE h.user_id = ? HAVING media_count > 0 ORDER BY h.created_at DESC, h.id DESC");
        $stmt->bind_param("i", $current_user); $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $my_highlights[] = $row;
        $stmt->close();
    } catch (Throwable $e2) { /* highlight tables do not exist yet */ }
}

// ── DEZZME VERIFIED: people you track who already have a badge ──
// Assumes users.is_verified (1/0). If the column doesn't exist the social-proof row is simply hidden.
$vf_tracked_count = 0; $vf_tracked_pics = [];
try {
    $q = $conn->query("SELECT COUNT(*) AS c FROM followers f JOIN users u ON u.id = f.following_id WHERE f.follower_id = " . (int)$current_user . " AND u.is_verified = 1");
    if ($q) $vf_tracked_count = (int)($q->fetch_assoc()['c'] ?? 0);
    if ($vf_tracked_count > 0) {
        $q2 = $conn->query("SELECT u.profile_pic FROM followers f JOIN users u ON u.id = f.following_id WHERE f.follower_id = " . (int)$current_user . " AND u.is_verified = 1 LIMIT 3");
        if ($q2) while ($r = $q2->fetch_assoc()) $vf_tracked_pics[] = $r['profile_pic'] ?: 'mee.jpg';
    }
} catch (Throwable $e) { /* column missing: hide social proof */ }
$vf_has_pic = !empty($user['profile_pic']);
$vf_has_bio = trim((string)$settings['Bio']) !== '';

// ── Values for the right-hand info column ──
$act_legacy = (isset($settings['show_online_status']) && (int)$settings['show_online_status'] === 0) ? 0 : 2;
$act_online = isset($settings['online_visibility']) && $settings['online_visibility'] !== null ? (int)$settings['online_visibility'] : $act_legacy;
$act_last   = isset($settings['last_seen_visibility']) && $settings['last_seen_visibility'] !== null ? (int)$settings['last_seen_visibility'] : $act_online;
$side_is_private  = !empty($settings['is_fully_private']);
$side_is_verified = !empty($user['is_verified']);
$side_hl_count    = count($my_highlights);
$side_mod_count   = ($blocked_users ? $blocked_users->num_rows : 0) + ($muted_users ? $muted_users->num_rows : 0) + ($restricted_users ? $restricted_users->num_rows : 0);

// ── Privacy values for the privacy / tags / lists modals ──
$priv = ['privacy_level' => null, 'tag_who' => 'everyone', 'tag_approve' => 0, 'mention_who' => 'everyone', 'mention_feature' => 0,
         'show_trackers' => 'everyone', 'show_tracking' => 'everyone', 'show_pods' => 'everyone'];
foreach (['trackers', 'tracking', 'pods'] as $s) foreach (['trk', 'non'] as $a) $priv["lim_{$s}_{$a}"] = 0;
$ck_last = null;
try {
    $st = $conn->prepare("SELECT * FROM user_privacy WHERE user_id = ? LIMIT 1");
    $st->bind_param("i", $current_user); $st->execute();
    $prow = $st->get_result()->fetch_assoc(); $st->close();
    if ($prow) foreach ($priv as $k => $v) if (isset($prow[$k]) && $prow[$k] !== null) $priv[$k] = $prow[$k];
    $ck_last = $prow['checkup_at'] ?? null;
} catch (Throwable $e) { /* table not created yet: defaults are used */ }
$priv_lim = [];
foreach (['trackers', 'tracking', 'pods'] as $s) foreach (['trk', 'non'] as $a) { $k = "lim_{$s}_{$a}"; $priv_lim[$k] = max(-1, (int)$priv[$k]); }
$priv_flag = !empty($settings['is_fully_private']);
$priv_level = in_array($priv['privacy_level'], PRIV_LEVELS, true) ? $priv['privacy_level'] : null;
if ($priv_flag && !in_array($priv_level, ['private', 'full'], true)) $priv_level = 'full';   // old Full Private setting
if (!$priv_flag && in_array($priv_level, ['private', 'full'], true)) $priv_level = 'public'; // switched off elsewhere
if (!$priv_level) $priv_level = $priv_flag ? 'full' : 'public';
$priv_labels = ['public' => 'Public', 'limited' => 'Limited', 'private' => 'Private', 'full' => 'Full Private'];
$side_is_private = in_array($priv_level, ['private', 'full'], true);

// ── Account status snapshot (shown in the Profile status modal) ──
$acct_raw = strtolower(trim((string)($user['account_status'] ?? 'active')));
if (in_array($acct_raw, ['suspended', 'banned', 'disabled', 'deactivated'], true)) $acct_state = 'suspended';
elseif (in_array($acct_raw, ['restricted', 'limited', 'flagged'], true)) $acct_state = 'limited';
else $acct_state = 'good';
$vf_last = null;
try {
    $q = $conn->query("SELECT status FROM verification_requests WHERE user_id = " . (int)$current_user . " ORDER BY id DESC LIMIT 1");
    if ($q) $vf_last = $q->fetch_assoc() ?: null;
} catch (Throwable $e) { /* table missing */ }
if (!empty($user['is_verified'])) { $vf_text = 'Verified'; $vf_cls = 'ok'; }
elseif ($vf_last && $vf_last['status'] === 'pending') { $vf_text = 'Request pending'; $vf_cls = 'warn'; }
elseif ($vf_last && $vf_last['status'] === 'rejected') { $vf_text = 'Not approved'; $vf_cls = 'warn'; }
else { $vf_text = 'Not verified'; $vf_cls = ''; }
$st_has_email = trim((string)($user['email'] ?? '')) !== '';
$st_has_type  = trim((string)$settings['profile_type']) !== '';
$st_done = (int)$vf_has_pic + (int)$vf_has_bio + (int)$st_has_email + (int)$st_has_type;

// ── Username change limit + pending deletion (for the Account Center and Deactivate/Delete modal) ──
$un_last      = !empty($user['username_changed_at']) ? strtotime($user['username_changed_at']) : 0;
$un_next      = $un_last ? $un_last + UN_COOLDOWN_DAYS * 86400 : 0;
$un_locked    = ($un_next && time() < $un_next);
$un_days_left = $un_locked ? (int)ceil(($un_next - time()) / 86400) : 0;
$del_ts       = !empty($user['delete_after']) ? strtotime($user['delete_after']) : 0;
$del_pending  = ($del_ts && $del_ts > time());
$del_date     = $del_pending ? date('j M Y', $del_ts) : '';

// ── Notifications on/off ──
$notif_on = !isset($settings['notifications_enabled']) || (int)$settings['notifications_enabled'] === 1;
$haptics_on = !isset($settings['haptics_enabled']) || (int)$settings['haptics_enabled'] === 1;

// ── Time management: last 30 days of time spent on Dezzme ──
function tm_fmt($s) {
    $s = (int)$s;
    if ($s <= 0) return '0m';
    $h = intdiv($s, 3600); $m = (int)round(($s % 3600) / 60);
    if ($m === 60) { $h++; $m = 0; }
    if ($h > 0) return $h . 'h' . ($m > 0 ? ' ' . $m . 'm' : '');
    return $m > 0 ? $m . 'm' : '<1m';
}
// "today" follows the person's own time zone when they picked one (Time zone setting), else the server clock
$user_tz_name = trim((string)($settings['timezone'] ?? ''));
$user_tz_ok   = ($user_tz_name !== '' && in_array($user_tz_name, DateTimeZone::listIdentifiers(), true));
$db_today = date('Y-m-d');
if ($user_tz_ok) {
    $db_today = (new DateTime('now', new DateTimeZone($user_tz_name)))->format('Y-m-d');
} else {
    try { $q = $conn->query("SELECT CURDATE() AS d"); if ($q && ($r = $q->fetch_assoc())) $db_today = $r['d']; } catch (Throwable $e) {}
}
$dt_today = new DateTime($db_today);
$tm_from  = (clone $dt_today)->modify('-29 day')->format('Y-m-d');

$tm_map = [];
try {
    $st = $conn->prepare("SELECT day, seconds FROM user_time_spent WHERE user_id = ? AND day >= ?");
    if ($st) {
        $st->bind_param('is', $current_user, $tm_from); $st->execute();
        $res = $st->get_result();
        while ($r = $res->fetch_assoc()) $tm_map[$r['day']] = (int)$r['seconds'];
        $st->close();
    }
} catch (Throwable $e) { /* table not created yet */ }
$tm_days = [];
for ($i = 29; $i >= 0; $i--) {
    $d = clone $dt_today; $d->modify("-$i day");
    $k = $d->format('Y-m-d');
    $tm_days[] = ['d' => $k, 's' => $tm_map[$k] ?? 0, 'l' => $d->format('D'), 'n' => (int)$d->format('j'), 'f' => $d->format('D j M')];
}
$tm_today = (int)$tm_days[29]['s'];

// ── Birthdays of the people you track ──
function bd_next($m, $d, DateTime $today) {
    $y = (int)$today->format('Y');
    for ($k = 0; $k < 2; $k++) {
        $dd = ($m === 2 && $d === 29 && !checkdate(2, 29, $y + $k)) ? 28 : $d;
        $c = new DateTime(sprintf('%04d-%02d-%02d', $y + $k, $m, $dd));
        if ($c >= $today) return $c;
    }
    return new DateTime(sprintf('%04d-%02d-%02d', $y + 1, $m, min($d, 28)));
}
$bd_rows = [];
$bd_base = "SELECT u.id, u.username, u.nickname, u.profile_pic, u.is_verified, ua.date_of_birth
            FROM followers f JOIN users u ON u.id = f.following_id JOIN user_about ua ON ua.user_id = u.id
            WHERE f.follower_id = ? AND ua.date_of_birth IS NOT NULL AND ua.date_of_birth > '1900-01-01'
              AND u.id NOT IN (SELECT blocked_id FROM blocks WHERE blocker_id = ?)
              AND u.id NOT IN (SELECT blocker_id FROM blocks WHERE blocked_id = ?)";
foreach ([$bd_base . " AND u.deactivated_at IS NULL", $bd_base] as $bsql) {
    try {
        $st = $conn->prepare($bsql);
        if (!$st) continue;
        $st->bind_param('iii', $current_user, $current_user, $current_user); $st->execute();
        $res = $st->get_result(); $bd_rows = [];
        while ($r = $res->fetch_assoc()) $bd_rows[] = $r;
        $st->close();
        break;
    } catch (Throwable $e) { $bd_rows = []; }
}
$bd_list = [];
foreach ($bd_rows as $r) {
    $dob = DateTime::createFromFormat('Y-m-d', substr((string)$r['date_of_birth'], 0, 10));
    if (!$dob) continue;
    $bm = (int)$dob->format('n'); $bdd = (int)$dob->format('j');
    if (!checkdate($bm, $bdd, 2000)) continue;
    $nx   = bd_next($bm, $bdd, $dt_today);
    $days = (int)$dt_today->diff($nx)->days;
    $nick = trim((string)($r['nickname'] ?? ''));
    $bd_list[] = [
        'id' => (int)$r['id'], 'name' => $nick !== '' ? $nick : (string)$r['username'], 'user' => (string)$r['username'],
        'pic' => (string)($r['profile_pic'] ?: 'mee.jpg'), 'ver' => !empty($r['is_verified']), 'days' => $days, 'date' => $nx->format('j M'),
        'month' => ($nx->format('Y') === $dt_today->format('Y')) ? $nx->format('F') : $nx->format('F Y'),
    ];
}
usort($bd_list, function ($a, $b) { return $a['days'] === $b['days'] ? strcasecmp($a['name'], $b['name']) : $a['days'] <=> $b['days']; });
$bd_today_n  = count(array_filter($bd_list, function ($x) { return $x['days'] === 0; }));
$bd_month_n  = count(array_filter($bd_list, function ($x) { return $x['days'] <= 30; }));
$bd_missing  = max(0, (int)$following_count - count($bd_list));
$bd_groups   = [];
foreach ($bd_list as $x) $bd_groups[$x['month']][] = $x;

// ── Time zone list ──
$tz_rows = [];
foreach (DateTimeZone::listIdentifiers() as $tzid) {
    $tz_rows[] = [$tzid, (new DateTime('now', new DateTimeZone($tzid)))->format('P')];
}
$tz_cur   = $user_tz_ok ? $user_tz_name : '';
$tz_short = $tz_cur !== '' ? str_replace('_', ' ', substr(strrchr('/' . $tz_cur, '/'), 1)) : '';

// ── Word filters ──
$wf_lists = ['post' => [], 'ad' => [], 'comment' => []];
try {
    dz_wf_table($conn);
    $wr = $conn->query("SELECT kind, word FROM user_word_filters WHERE user_id = " . (int)$current_user . " ORDER BY id DESC");
    while ($wr && ($row = $wr->fetch_assoc())) $wf_lists[$row['kind']][] = $row['word'];
} catch (Throwable $e) {}
$wf_total = count($wf_lists['post']) + count($wf_lists['ad']) + count($wf_lists['comment']);

// ── Muted Pods ──
$mp_list = [];
$cuQ = (int)$current_user;
foreach ([
    /* both tables the app uses for "notifications off" */
    "SELECT g.id, g.title, g.group_pic, (SELECT COUNT(*) FROM group_members gm WHERE gm.group_id = g.id) AS members FROM (SELECT group_id FROM group_notif_off WHERE user_id = $cuQ UNION SELECT group_id FROM muted_groups WHERE user_id = $cuQ) n JOIN `groups` g ON g.id = n.group_id ORDER BY g.title ASC",
    "SELECT g.id, g.title, g.group_pic, (SELECT COUNT(*) FROM group_members gm WHERE gm.group_id = g.id) AS members FROM muted_groups n JOIN `groups` g ON g.id = n.group_id WHERE n.user_id = $cuQ ORDER BY g.title ASC",
    "SELECT g.id, g.title, g.group_pic, (SELECT COUNT(*) FROM group_members gm WHERE gm.group_id = g.id) AS members FROM group_notif_off n JOIN `groups` g ON g.id = n.group_id WHERE n.user_id = $cuQ ORDER BY g.title ASC",
] as $msql) {
    try {
        $res = $conn->query($msql);
        if (!$res) continue;
        $mp_list = [];
        while ($r = $res->fetch_assoc()) $mp_list[] = $r;
        break;
    } catch (Throwable $e) { $mp_list = []; }
}


// ── Archive counts ──
$ar_live = 0; $ar_arch = 0;
try {
    $st = $conn->prepare("SELECT COALESCE(SUM(is_archived = 0), 0), COALESCE(SUM(is_archived = 1), 0) FROM memes WHERE uploaded_by = ?");
    if ($st) {
        $st->bind_param('i', $current_user); $st->execute();
        $row = $st->get_result()->fetch_row(); $st->close();
        if ($row) { $ar_live = (int)$row[0]; $ar_arch = (int)$row[1]; }
    }
} catch (Throwable $e) {}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="same-origin">
<meta name="theme-color" content="#070924">
<title>Dezzme · Settings</title>
<script nonce="<?php echo $nonce; ?>">try{if(localStorage.getItem('dz_theme')==='light')document.documentElement.setAttribute('data-theme','light');}catch(e){}</script>
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style nonce="<?php echo $nonce; ?>">
/* ══════════════════════════════════════════════════════════════
   DEZZME SETTINGS · same design language as Memepedia
   Black-navy gradient · red accent · Poppins · centered lane
   with grey side lines · right-hand info column on wide screens
   ══════════════════════════════════════════════════════════════ */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body,button,input,select,textarea{font-family:'Poppins',sans-serif}
button,a{cursor:pointer;outline:none;-webkit-tap-highlight-color:transparent}
a{color:inherit;text-decoration:none}
img,video{max-width:100%;display:block}
[class^="ri-"],[class*=" ri-"],[class^="ri-"]::before,[class*=" ri-"]::before{font-family:'remixicon' !important;font-style:normal;font-weight:normal;line-height:1;speak:none;text-transform:none;-webkit-font-smoothing:antialiased}

:root{
  --accent:#FF2D2D; --accent-hover:#ff1a1a;
  --accent-soft:rgba(255,45,45,.14); --accent-border:rgba(255,45,45,.4); --accent-fill:rgba(255,45,45,.2);
  --accent-glow:rgba(255,45,45,.28); --accent-text:#ff8a8a; --accent-focus:rgba(255,45,45,.7); --accent-ring:rgba(255,45,45,.12); --accent-sel:rgba(255,45,45,.35);
  --scroll-thumb:rgba(var(--fg),.32);
  --danger:#ff6b6b; --danger-soft:rgba(255,107,107,.14);
  --on-accent:#000; --solid:#070924;
  --surface:rgba(var(--fg),.05); --surface-2:rgba(var(--fg),.08);
  --border:rgba(var(--fg),.14); --line:rgba(var(--fg),.09);
  --fg:255,255,255; --text:#fff; --muted:rgba(var(--fg),.45); --muted-2:rgba(var(--fg),.3); --sub:rgba(var(--fg),.62);
  --bg:var(--bg-lin,linear-gradient(-45deg,#010218,#070924,#070924));
  --ok:#4ade80;
  --max-w:580px; --topbar-h:54px;
  --pad:clamp(11px,3.4vw,16px); --ctl-h:clamp(32px,8.4vw,38px);
  --r:5px; --act-h:clamp(26px,7.4vw,30px); --act-r:4px;
  --sidebar-w:210px; --tr:.2s ease;
  --ctl-b:calc(var(--ctl-h) * .94); --act-b:calc(var(--act-h) * .94);
}
@media (max-width:490px){:root{--topbar-h:48px}}
html{scroll-behavior:smooth;-webkit-text-size-adjust:100%}
@media (max-width:768px){
  html,body,*{scrollbar-width:none !important;-ms-overflow-style:none !important}
  *::-webkit-scrollbar{display:none !important;width:0 !important;height:0 !important}
}
::selection{background:var(--accent-sel);color:var(--ui-text,#fff)}
body{background:var(--bg);background-attachment:fixed;color:var(--text);min-height:100vh;display:flex;overflow-x:hidden;-webkit-font-smoothing:antialiased;font-size:13px}

/* ══ SIDEBAR (unchanged layout) ══ */
.sidebar{width:var(--sidebar-w);background:var(--sidebar-bg,rgba(7,9,36,.9));backdrop-filter:blur(30px);-webkit-backdrop-filter:blur(30px);border-right:1px solid var(--line);display:flex;flex-direction:column;padding:28px 14px;position:sticky;top:0;height:100vh;gap:5px;flex-shrink:0;z-index:50}
.logo{display:flex;align-items:center;gap:9px;font-size:18px;font-weight:700;margin-bottom:24px;letter-spacing:-.4px;color:var(--ui-text,#fff)}
.logo img{width:46px;border-radius:9px}
.nav-btn{display:flex;align-items:center;gap:9px;padding:8px 12px;border:1px solid var(--line);border-radius:var(--act-r);background:transparent;color:rgba(var(--fg),.62);font-size:12px;font-weight:500;transition:var(--tr)}
.nav-btn i{font-size:14px;flex-shrink:0}
.nav-btn:hover{background:rgba(var(--fg),.08);border-color:var(--border);color:var(--ui-text,#fff)}

/* ══ SHELL : lane on the left, info column on the right ══ */
.mp-shell{flex:1;min-width:0;display:flex;align-items:flex-start;justify-content:center;position:relative;z-index:1}
.mp-side{display:none}
@media (min-width:1180px){
  .mp-shell{justify-content:flex-start;padding-left:22px}
  .mp-side{display:block;flex:1 1 0;min-width:0;max-width:430px;position:sticky;top:0;height:100vh;overflow-y:auto;overflow-x:hidden;padding:0 22px 30px 20px;scrollbar-width:thin;scrollbar-color:var(--scroll-thumb) transparent}
  .mp-side::-webkit-scrollbar{width:5px}
  .mp-side::-webkit-scrollbar-thumb{background:var(--scroll-thumb);border-radius:99px}
}
.ac-lane{width:100%;max-width:var(--max-w);min-height:100vh;align-self:stretch;display:flex;flex-direction:column;padding-bottom:48px}
@media (min-width:801px){.ac-lane{border-left:1px solid var(--line);border-right:1px solid var(--line)}}
@media (max-width:800px){.ac-lane{max-width:100%}}
@media (min-width:1180px){.ac-lane{flex:0 0 var(--max-w);width:var(--max-w)}}

/* ══ TOPBAR (shared by the page and every modal) ══ */
.ac-topbar{position:sticky;top:0;z-index:200;height:var(--topbar-h);flex-shrink:0;display:flex;align-items:center;gap:2px;padding:0 10px;background:var(--bg);background-attachment:fixed}
.ac-topbar.plain{height:auto;min-height:var(--topbar-h);padding:7px var(--pad);top:0}
.ac-titles{display:flex;flex-direction:column;gap:1px;min-width:0}
.ac-titles .ac-title{margin:0;flex:none}
.ac-sub{font-size:.62rem;color:var(--muted);line-height:1.4}
.ac-topbar::after{content:'';position:absolute;left:0;right:0;bottom:0;height:1px;background:rgba(var(--fg),.14);pointer-events:none;z-index:2}
.ac-x{width:34px;height:34px;background:none;border:none;color:var(--ui-text,#fff);display:flex;align-items:center;justify-content:center;opacity:.9;transition:opacity .2s,color .2s;padding:0;flex-shrink:0}
.ac-x:hover{opacity:1;color:var(--accent)}
.ac-x svg{width:22px;height:22px;display:block}
.ac-x i{font-size:28px}
.ac-title{flex:1;min-width:0;font-size:.92rem;font-weight:700;color:var(--ui-text,#fff);letter-spacing:-.1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin:0 6px 0 10px;display:flex;align-items:center;gap:7px}
.ac-title.left{margin:0 6px 0 4px;letter-spacing:.2px}
.ac-topbar.plain .ac-title.left{margin-left:0}
.ac-title i{color:#4da3ff;font-size:1.05rem}
@media (max-width:490px){.ac-x{width:30px;height:30px}.ac-title{font-size:.84rem}}

/* ══ PAGE : intro, stats, menu list ══ */
.stat-row{display:flex;gap:8px;padding:10px var(--pad) 4px}
.stat-pill{flex:1;background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:11px 13px;display:flex;flex-direction:column;gap:3px;transition:background .2s,border-color .2s}
.stat-pill:hover{background:var(--accent-soft);border-color:var(--accent-border)}
.stat-pill-num{font-size:1.15rem;font-weight:700;color:var(--ui-text,#fff);line-height:1}
.stat-pill-label{font-size:.58rem;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--muted)}
.section-header{font-size:.6rem;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);padding:20px var(--pad) 8px}
.settings-section{border-bottom:none}
.menu-row{display:flex;align-items:center;gap:12px;padding:clamp(11px,3vw,13px) var(--pad);border-top:1px solid var(--line);color:var(--text);transition:background .18s}
.settings-section .menu-row:last-child{border-bottom:1px solid var(--line)}
.menu-row:hover{background:rgba(var(--fg),.04)}
.menu-row:active{background:rgba(var(--fg),.06)}
.menu-row:focus-visible{box-shadow:inset 0 0 0 2px var(--accent-focus)}
.menu-icon{width:32px;height:32px;border-radius:var(--act-r);background:var(--accent-soft);color:var(--accent-text);display:flex;align-items:center;justify-content:center;font-size:1.05rem;flex-shrink:0}
.menu-text{flex:1;min-width:0}
.menu-label{font-size:.8rem;font-weight:600;color:var(--ui-text,#fff);display:block}
.menu-desc{font-size:.64rem;color:var(--muted);margin-top:2px;display:block;line-height:1.5}
.menu-right{display:flex;align-items:center;gap:7px;flex-shrink:0}
.menu-chevron{font-size:1.15rem;color:var(--muted);transition:transform .2s,color .2s}
.menu-row:hover .menu-chevron{color:var(--ui-text,#fff);transform:translateX(2px)}
.menu-badge{background:var(--accent);color:var(--on-accent);font-size:.6rem;font-weight:700;padding:2px 7px;border-radius:99px;min-width:18px;text-align:center}
.menu-value{max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.6rem;font-weight:600;color:var(--accent-text);background:var(--accent-soft);border:1px solid var(--accent-border);padding:2px 9px;border-radius:var(--act-r)}
.menu-value:empty{display:none}
.menu-row-danger .menu-label{color:#c81e1e}
.menu-row-danger .menu-icon{background:rgba(160,10,10,.28);color:#d42323}
.menu-icon .st-vb,.gs-ti .st-vb,.st-row .st-vb{margin:0 !important;vertical-align:middle !important;width:1em !important;height:1em !important}

.menu-row-danger .menu-desc{color:rgba(200,50,50,.75)}
.menu-row-danger .menu-chevron{color:rgba(200,30,30,.6)}
.menu-row-danger:hover{background:rgba(160,10,10,.16)}
.menu-row-danger:hover .menu-chevron{color:#d42323}
.logout-wrap{margin-top:18px}

/* toggle */
.switch{position:relative;display:inline-block;width:42px;height:24px;flex-shrink:0}
.switch input{opacity:0;width:0;height:0}
.slider{position:absolute;cursor:pointer;inset:0;background:rgba(var(--fg),.38);border-radius:34px;transition:.25s;border:none}
.slider::before{position:absolute;content:"";height:14px;width:14px;left:5px;bottom:5px;background:#0a0c24;border-radius:50%;transition:.25s}
input:checked+.slider{background:var(--accent)}
input:checked+.slider::before{transform:translateX(18px);background:#0a0c24}

/* ══ RIGHT COLUMN CARDS (same as Memepedia) ══ */
.gs-cards{padding:18px 0 2px}
.gs-card{margin-bottom:12px}
.gs-bar{display:flex;align-items:center;gap:7px;width:100%;height:30px;padding:0 12px;border:none;border-radius:var(--act-r);background:rgba(var(--fg),.1);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);color:var(--ui-text,#fff);font-size:.72rem;font-weight:600;line-height:1;text-align:left;transition:background .2s,transform .15s}
.gs-bar:hover{background:rgba(var(--fg),.2)}
.gs-bar:active{transform:scale(.99)}
.gs-bar-ic{font-size:14px;color:var(--accent-text);flex-shrink:0}
.gs-bar-t{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.gs-bar-chev{font-size:18px;color:rgba(var(--fg),.75);flex-shrink:0;transition:transform .25s}
.gs-card.collapsed .gs-bar-chev{transform:rotate(-90deg)}
.gs-body{display:grid;grid-template-rows:1fr;transition:grid-template-rows .3s ease}
.gs-inner{min-height:0;overflow:hidden}
.gs-pad{padding-top:8px}
.gs-card.collapsed .gs-body{grid-template-rows:0fr}
.gs-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:9px 2px;border-bottom:1px solid rgba(var(--fg),.06)}
.gs-row:last-child{border-bottom:none}
.gs-row-l{font-size:.66rem;color:rgba(var(--fg),.5);font-weight:500;white-space:nowrap}
.gs-row-v{font-size:.74rem;font-weight:600;color:var(--ui-text,#fff);text-align:right;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.gs-row-v.ok{color:var(--ok)}
.gs-p{font-size:.72rem;line-height:1.65;color:rgba(var(--fg),.7);overflow-wrap:anywhere;padding:2px 2px 4px}
.gs-p b{color:var(--ui-text,#fff);font-weight:600}
.gs-callout{display:flex;gap:9px;margin:8px 0 4px;padding:10px 11px;border-radius:var(--act-r);background:var(--accent-soft);border:1px solid var(--accent-border);font-size:.67rem;line-height:1.6;color:rgba(var(--fg),.8)}
.gs-callout i{color:var(--accent-text);font-size:1rem;flex-shrink:0;margin-top:1px}
.gs-callout b{color:var(--ui-text,#fff)}
.gs-step{display:flex;gap:11px;padding:10px 2px;border-bottom:1px solid rgba(var(--fg),.06)}
.gs-step:last-child{border-bottom:none}
.gs-sn{width:22px;height:22px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:.62rem;font-weight:700;color:var(--on-accent);background:var(--accent)}
.gs-st{min-width:0}
.gs-st b{display:block;font-size:.72rem;margin-bottom:2px}
.gs-st span{display:block;font-size:.65rem;line-height:1.55;color:rgba(var(--fg),.58);overflow-wrap:anywhere}
.gs-topic{display:flex;align-items:flex-start;gap:10px;width:100%;padding:9px 2px;border:none;border-bottom:1px solid rgba(var(--fg),.06);background:none;color:inherit;font:inherit;text-align:left}
.gs-topic:last-child{border-bottom:none}
.gs-topic:hover .gs-tn b{text-decoration:underline}
.gs-ti{width:28px;height:28px;border-radius:var(--act-r);display:flex;align-items:center;justify-content:center;font-size:.95rem;flex-shrink:0;background:var(--accent-soft);color:var(--accent-text)}
.gs-tx{flex:1;min-width:0}
.gs-tn{display:flex;align-items:center;gap:7px;flex-wrap:wrap}
.gs-tn b{font-size:.72rem}
.gs-tn small{font-size:.56rem;font-weight:600;color:var(--muted);padding:1px 7px;border-radius:4px;background:rgba(var(--fg),.07)}
.gs-td{display:block;font-size:.64rem;line-height:1.5;color:rgba(var(--fg),.55);margin-top:2px;overflow-wrap:anywhere}
.gs-rule{display:flex;gap:9px;padding:6px 2px;font-size:.68rem;line-height:1.55;color:rgba(var(--fg),.74);overflow-wrap:anywhere}
.gs-rule i{color:var(--accent-text);font-size:.95rem;flex-shrink:0;margin-top:1px}
.gs-rule b{color:var(--ui-text,#fff);font-weight:600}
.gs-note{font-size:.68rem;color:rgba(var(--fg),.5);line-height:1.55;padding:4px 2px}

/* ══ MODALS : same shell as Memepedia (full screen, centered lane, grey side lines) ══ */
.modal-overlay{position:fixed;inset:0;z-index:1000;display:flex;justify-content:center;align-items:stretch;background:var(--bg);background-attachment:fixed;opacity:0;visibility:hidden;pointer-events:none;transition:opacity .22s ease,visibility 0s linear .22s}
.modal-overlay.open{opacity:1;visibility:visible;pointer-events:all;transition:opacity .22s ease}
.modal-lane{width:100%;max-width:var(--max-w);height:100%;display:flex;flex-direction:column;transform:translateY(12px);transition:transform .3s cubic-bezier(.22,1,.36,1)}
.modal-overlay.open .modal-lane{transform:none}
@media (min-width:801px){.modal-lane{border-left:1px solid var(--line);border-right:1px solid var(--line)}}
@media (max-width:800px){.modal-lane{max-width:100%}}
.modal-topbar{position:relative;padding-top:env(safe-area-inset-top)}
.modal-purpose,.mod-tabs,.pt-toolbar,.modal-body,.hle-footer{padding-left:var(--pad);padding-right:var(--pad)}
.modal-purpose{font-size:.7rem;color:var(--sub);line-height:1.65;padding-top:13px;padding-bottom:3px;flex-shrink:0}
.modal-body{position:relative;flex:1;overflow-y:auto;overflow-x:hidden;padding-top:18px;padding-bottom:max(34px,env(safe-area-inset-bottom));display:flex;flex-direction:column;overscroll-behavior:contain;-webkit-overflow-scrolling:touch;scrollbar-width:thin;scrollbar-color:var(--scroll-thumb) transparent}
.modal-body::-webkit-scrollbar{width:5px}
.modal-body::-webkit-scrollbar-thumb{background:var(--scroll-thumb);border-radius:99px}
.mb-in{width:100%}
#modal-hl-edit{z-index:1100}#modal-clip{z-index:1200}#modal-ht{z-index:1300}

/* modal loading skeleton */
.modal-skel{position:absolute;inset:0;background:transparent;z-index:5;display:flex;flex-direction:column;gap:16px;padding:20px var(--pad) 0;opacity:1;transition:opacity .12s ease;background:var(--solid)}
.modal-skel.hide{opacity:0;pointer-events:none}
.modal-skel-in{width:100%;display:flex;flex-direction:column;gap:14px}
.sk-bar{border-radius:var(--r);background:linear-gradient(90deg,rgba(var(--fg),.04) 25%,rgba(var(--fg),.09) 50%,rgba(var(--fg),.04) 75%);background-size:600px 100%;animation:shimmer 1.5s infinite linear;flex-shrink:0}
@keyframes shimmer{0%{background-position:-600px 0}100%{background-position:600px 0}}

/* note blocks / write-ups under controls */
.ig-row{display:flex;align-items:center;justify-content:space-between;gap:16px;padding-bottom:14px}
.ig-row-label{font-size:.9rem;font-weight:600;color:var(--ui-text,#fff)}
.ig-note{color:var(--sub);font-size:.68rem;line-height:1.7}
.ig-note p+p{margin-top:11px}
.ig-note a{color:var(--accent-text);font-weight:600}
.ig-note a:hover{text-decoration:underline}
.ig-note.after-control{padding-top:4px}
.ig-note.after-list{margin-top:14px;padding:12px 13px;border:1px solid var(--line);border-radius:var(--r);background:var(--surface)}

/* fields */
.field-row{display:flex;flex-direction:column;gap:7px;padding:16px 0;border-bottom:1px solid var(--line)}
.field-row:last-child{border-bottom:none;padding-bottom:0}
.field-row:first-child{padding-top:0}
.field-label{font-size:.6rem;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted)}
.field-hint{font-size:.62rem;color:var(--muted);font-weight:400;text-transform:none;letter-spacing:0;margin-left:4px}
.field-control{display:flex;gap:8px;align-items:center}
.field-help{font-size:.64rem;color:var(--muted);line-height:1.65}
input.f-input,textarea.f-input,select.f-input{flex:1;min-width:0;height:var(--ctl-h);padding:0 12px;border-radius:var(--r);border:1px solid var(--border);background:var(--surface);color:var(--ui-text,#fff);font-size:.76rem;outline:none;transition:border-color .2s,background .2s,box-shadow .2s;resize:none;-webkit-appearance:none;appearance:none}
textarea.f-input{height:auto;min-height:90px;padding:10px 12px;line-height:1.6;scrollbar-width:none}
textarea.f-input::-webkit-scrollbar{display:none}
input.f-input::placeholder,textarea.f-input::placeholder{color:var(--muted)}
input.f-input:focus,textarea.f-input:focus,select.f-select:focus{border-color:var(--accent-focus);background:rgba(var(--fg),.07);box-shadow:0 0 0 3px var(--accent-ring)}
select.f-select{width:100%;height:var(--ctl-h);padding:0 36px 0 12px;border-radius:var(--r);border:1px solid var(--border);background-color:var(--surface);color:var(--ui-text,#fff);font-size:.76rem;outline:none;-webkit-appearance:none;appearance:none;cursor:pointer;transition:border-color .2s,background .2s,box-shadow .2s;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='rgba(var(--fg),0.5)' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center}
.field-control select.f-select{flex:1;min-width:0;width:auto}
select.f-select option,select.f-select optgroup{background:var(--solid,#070924);color:var(--ui-text,#fff)}
.input-wrap{position:relative;flex:1;min-width:0}
.input-wrap input.f-input{width:100%;padding-right:32px}
.input-status{position:absolute;right:10px;top:50%;transform:translateY(-50%);font-size:13px;pointer-events:none}
.field-suggestions{display:flex;flex-wrap:wrap;gap:6px}
.suggestion-chip{padding:4px 10px;border-radius:var(--act-r);background:var(--accent-soft);border:1px solid var(--accent-border);color:var(--accent-text);font-size:.66rem;font-weight:600;cursor:pointer;transition:background .2s}
.suggestion-chip:hover{background:var(--accent-fill)}
.field-feedback{font-size:.66rem;min-height:14px;line-height:1.4}
.field-feedback.error{color:var(--danger)}
.field-feedback.success{color:var(--accent-text)}
.field-feedback.taken{color:#fb923c}

.save-btn,.save-btn-full{height:var(--ctl-b);border-radius:var(--r);background:var(--accent);border:none;color:var(--on-accent);font-size:.74rem;font-weight:700;transition:background .2s,transform .15s}
.save-btn{padding:0 18px;white-space:nowrap;flex-shrink:0}
.save-btn-full{width:100%;margin-top:8px;display:flex;align-items:center;justify-content:center;gap:7px}
.save-btn:hover,.save-btn-full:hover{background:var(--accent-hover)}
.save-btn:active,.save-btn-full:active{transform:scale(.98)}
.save-btn:disabled,.save-btn-full:disabled{opacity:.5;cursor:default}

.pw-meter{height:3px;border-radius:2px;background:rgba(var(--fg),.1);overflow:hidden}
.pw-meter span{display:block;height:100%;width:0;border-radius:2px;transition:width .25s,background .25s}
.pw-meter-label{font-size:.64rem;color:var(--muted);min-height:14px}
.pw-stack{display:flex;flex-direction:column;gap:9px;width:100%}
.pw-stack input.f-input{width:100%;flex:none}
.pw-stack .save-btn-full{margin-top:2px}

.radio-stack{display:flex;flex-direction:column;width:100%}
.radio-item{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:12px 0;border-bottom:1px solid var(--line);cursor:pointer}
.radio-item:last-child{border-bottom:none}
.radio-item-text{flex:1;min-width:0}
.radio-item-text strong{font-size:.76rem;font-weight:600;color:var(--ui-text,#fff);display:block}
.radio-item-text span{font-size:.64rem;color:var(--muted);margin-top:2px;display:block;line-height:1.5}
.radio-item input[type=radio]{margin:0;accent-color:var(--accent);flex-shrink:0;width:18px;height:18px;cursor:pointer}

/* empty states */
.empty-state,.mod-empty{display:flex;flex-direction:column;align-items:center;gap:6px;padding:26px 20px 14px;text-align:center}
.empty-title{font-size:.9rem;font-weight:700;color:var(--ui-text,#fff)}
.empty-desc{font-size:.7rem;color:var(--muted);line-height:1.7;max-width:310px}

/* track requests */
.track-req-row{display:flex;align-items:center;gap:11px;padding:12px 0;border-bottom:1px solid var(--line);transition:opacity .3s,transform .3s}
.track-req-row:first-child{padding-top:0}
.track-req-row img{width:40px;height:40px;border-radius:50%;object-fit:cover;cursor:pointer;flex-shrink:0;border:2px solid var(--accent)}
.track-req-meta{flex:1;min-width:0}
.track-req-meta strong{display:block;font-size:.76rem;font-weight:700;color:var(--ui-text,#fff);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.track-req-meta small{display:block;font-size:.6rem;color:var(--muted);margin-top:1px}
.track-req-btns{display:flex;gap:6px;flex-shrink:0}
.treq-approve,.treq-deny{height:var(--act-b);padding:0 13px;border-radius:var(--act-r);border:none;font-size:.66rem;font-weight:700;transition:background .2s}
.treq-approve{background:var(--accent);color:var(--on-accent)}
.treq-approve:hover{background:var(--accent-hover)}
.treq-deny{background:rgba(var(--fg),.1);color:rgba(var(--fg),.75)}
.treq-deny:hover{background:var(--danger-soft);color:var(--danger)}

/* profile type */
.pt-toolbar{padding-top:10px;padding-bottom:10px;border-bottom:1px solid var(--line);flex-shrink:0;display:flex;flex-direction:column;gap:9px}
.pt-search{position:relative;width:100%}
.pt-search i.ri-search-line{position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:.95rem;color:var(--muted);pointer-events:none}
.pt-search input{width:100%;height:var(--ctl-h);padding:0 32px 0 34px;border-radius:var(--r);border:1px solid var(--border);background:var(--surface);color:var(--ui-text,#fff);font-size:.76rem;outline:none;transition:border-color .2s,background .2s,box-shadow .2s}
.pt-search input::placeholder{color:var(--muted)}
.pt-search input:focus{border-color:var(--accent-focus);background:rgba(var(--fg),.07);box-shadow:0 0 0 3px var(--accent-ring)}
.pt-clear-search{position:absolute;right:7px;top:50%;transform:translateY(-50%);width:20px;height:20px;border-radius:var(--act-r);border:none;background:rgba(var(--fg),.1);color:var(--sub);font-size:.8rem;display:none;align-items:center;justify-content:center}
.pt-clear-search.show{display:flex}
.pt-current{display:flex;align-items:center;justify-content:space-between;gap:10px;font-size:.7rem;color:var(--sub)}
.pt-current strong{color:var(--ui-text,#fff);font-weight:700}
.pt-remove{height:var(--act-b);padding:0 12px;border-radius:var(--act-r);border:none;background:rgba(var(--fg),.1);color:rgba(var(--fg),.75);font-size:.66rem;font-weight:700;transition:.2s;white-space:nowrap}
.pt-remove:hover{background:var(--danger-soft);color:var(--danger)}
.pt-remove[hidden]{display:none}
.pt-cat{margin-bottom:20px}
.pt-cat:last-child{margin-bottom:0}
.pt-cat-title{font-size:.6rem;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);margin-bottom:9px;display:flex;align-items:center;gap:8px}
.pt-cat-title::after{content:'';flex:1;height:1px;background:var(--line)}
.pt-chips{display:flex;flex-wrap:wrap;gap:6px}
.pt-chip{height:var(--act-b);padding:0 12px;border-radius:var(--act-r);border:none;background:rgba(var(--fg),.1);color:rgba(var(--fg),.75);font-size:.68rem;font-weight:600;transition:background .2s,color .2s,transform .15s}
.pt-chip:hover{background:rgba(var(--fg),.2);color:var(--ui-text,#fff)}
.pt-chip:active{transform:scale(.96)}
.pt-chip.selected{background:var(--accent);color:var(--on-accent);font-weight:700}
.pt-chip.selected::before{content:'\eb7b';font-family:'remixicon';margin-right:4px;font-size:.7rem}
.pt-chip.busy{opacity:.55;pointer-events:none}
.pt-tabs{display:flex;gap:6px;margin-top:10px}
.pt-tab{flex:1;min-width:0;height:28px;padding:0 8px;border:none;border-radius:var(--act-r,5px);background:rgba(var(--fg),.08);color:rgba(var(--fg),.7);font-size:.64rem;font-weight:700;display:flex;align-items:center;justify-content:center;gap:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;transition:background .2s,color .2s,transform .15s}
.pt-tab i{font-size:13px;flex-shrink:0}
.pt-tab:active{transform:scale(.97)}
.pt-tab.on{background:var(--accent);color:var(--on-accent)}
.pt-cat-title .pt-grp{font-size:.52rem;letter-spacing:.6px;padding:2px 6px;border-radius:3px;background:rgba(var(--fg),.1);color:var(--sub,var(--muted))}
.pt-none{text-align:center;padding:36px 16px;color:var(--muted);font-size:.78rem;display:none}
.pt-none i{display:block;font-size:2.1rem;color:var(--accent);opacity:.7;margin-bottom:10px}
.pt-none.show{display:block}
.pt-intro{margin-bottom:18px}
.pt-skel{display:none}
.pt-skel-cat{margin-bottom:20px}
.pt-skel-title{height:8px;border-radius:3px;margin-bottom:11px}
.pt-skel-chips{display:flex;flex-wrap:wrap;gap:6px}
.pt-skel-chip{height:var(--act-b);border-radius:var(--act-r)}
.pt-skel-title,.pt-skel-chip{background:linear-gradient(90deg,rgba(var(--fg),.04) 25%,rgba(var(--fg),.1) 50%,rgba(var(--fg),.04) 75%);background-size:600px 100%;animation:shimmer 1.5s infinite linear}
@keyframes ptIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
.pt-cat.pt-in{animation:ptIn .38s cubic-bezier(.22,1,.36,1) both}
#pt-list.pt-reveal .pt-cat:nth-child(2){animation-delay:.05s}
#pt-list.pt-reveal .pt-cat:nth-child(3){animation-delay:.1s}
#pt-list.pt-reveal .pt-cat:nth-child(4){animation-delay:.15s}
#pt-list.pt-reveal .pt-cat:nth-child(n+5){animation-delay:.2s}

/* creator tools */
.ct-cat{margin-bottom:20px}
.ct-cat:last-child{margin-bottom:0}
.ct-list{display:flex;flex-direction:column;gap:7px}
.ct-row{display:flex;align-items:center;gap:11px;padding:10px 12px;border-radius:var(--r);background:var(--surface);border:1px solid var(--line);transition:background .2s,border-color .2s}
.ct-row:hover{background:var(--surface-2);border-color:var(--accent-border)}
.ct-ic{width:32px;height:32px;border-radius:var(--act-r);background:var(--accent-soft);color:var(--accent-text);display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0}
.ct-tx{flex:1;min-width:0}
.ct-tn{display:flex;align-items:center;gap:7px;flex-wrap:wrap}
.ct-tn b{font-size:.76rem;font-weight:700;color:var(--ui-text,#fff)}
.ct-tn small{font-size:.54rem;font-weight:700;color:var(--muted);padding:1px 7px;border-radius:4px;background:rgba(var(--fg),.07)}
.ct-td{display:block;font-size:.64rem;color:var(--muted);line-height:1.5;margin-top:2px}
.ct-go{color:var(--muted);font-size:1.05rem;flex-shrink:0;transition:color .2s}
.ct-row:hover .ct-go{color:var(--accent-text)}

/* moderation tabs (same tab style as Memepedia entries) */
.mod-tabs{display:flex;border-bottom:1px solid var(--line);flex-shrink:0}
.mod-tab{flex:1;display:flex;align-items:center;justify-content:center;gap:6px;padding:12px 4px;background:none;border:none;border-bottom:2px solid transparent;color:var(--muted);font-size:.7rem;font-weight:600;transition:color .2s;margin-bottom:-1px;white-space:nowrap}
.mod-tab:hover{color:var(--ui-text,#fff)}
.mod-tab.active{color:var(--ui-text,#fff);border-bottom-color:#fff}
.mod-tab-count{background:var(--accent-soft);color:var(--accent-text);font-size:.58rem;font-weight:700;padding:1px 7px;border-radius:99px;min-width:18px;text-align:center}
.mod-panel{display:none}
.mod-panel.active{display:block}
.mod-user-row{display:flex;align-items:center;gap:11px;padding:10px 12px;border-radius:var(--r);background:var(--surface);border:1px solid var(--line);margin-bottom:8px;transition:background .2s,opacity .25s,transform .25s}
.mod-user-row:hover{background:var(--surface-2)}
.mod-user-row img{width:34px;height:34px;border-radius:50%;object-fit:cover;flex-shrink:0;cursor:pointer;border:2px solid var(--accent)}
.mod-username{flex:1;font-size:.76rem;font-weight:600;color:var(--ui-text,#fff);cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}
.mod-username:hover{color:var(--accent-text)}
.mod-action-btn{height:var(--act-b);padding:0 13px;border-radius:var(--act-r);border:none;background:rgba(var(--fg),.1);color:rgba(var(--fg),.75);font-size:.66rem;font-weight:700;transition:.2s;flex-shrink:0;white-space:nowrap}
.mod-action-btn:hover{background:rgba(var(--fg),.2);color:var(--ui-text,#fff)}
.mod-action-btn:disabled{opacity:.5;pointer-events:none}
.mod-user-row.removing{opacity:0;transform:scale(.95);pointer-events:none}

/* highlights */
.hl-add{display:flex;flex-direction:column;gap:9px}
.hl-pick{display:flex;align-items:center;gap:11px;padding:10px 12px;border-radius:var(--r);border:1.5px dashed var(--accent-border);background:var(--accent-soft);cursor:pointer;transition:background .2s,border-color .2s;color:var(--sub);font-size:.72rem}
.hl-pick:hover{border-color:var(--accent);background:var(--accent-fill)}
.hl-pick>i{font-size:1.25rem;color:var(--accent);flex-shrink:0}
.hl-pick input{display:none}
button.hl-pick{width:100%;text-align:left}
.hl-pick-text{flex:1;min-width:0;line-height:1.45}
.hl-pick-text strong{display:block;font-size:.76rem;font-weight:700;color:var(--ui-text,#fff)}
.hl-pick-text span{display:block;font-size:.64rem;color:var(--muted)}
.hl-pick.small{padding:8px 11px}
.hl-pick.small strong{font-size:.72rem}
.hl-cover-prev{width:46px;height:46px;border-radius:var(--r);object-fit:cover;background:var(--ph,#15171f);border:1px solid var(--line);flex-shrink:0;display:none}
.hl-cover-prev.show{display:block}
.hl-progress{height:4px;border-radius:2px;background:rgba(var(--fg),.1);overflow:hidden;display:none}
.hl-progress.show{display:block}
.hl-progress span{display:block;height:100%;width:0;background:var(--accent);border-radius:2px;transition:width .2s}
.hl-status{font-size:.66rem;color:var(--sub);min-height:15px;line-height:1.4}
.hl-status.error{color:var(--danger)}
.hl-list-head{display:flex;align-items:baseline;justify-content:space-between;margin:22px 0 12px;padding-top:18px;border-top:1px solid var(--line)}
.hl-list-title{font-size:.6rem;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted)}
.hl-list-count{font-size:.66rem;color:var(--muted)}
.hl-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px 11px}
.hl-item{min-width:0;transition:opacity .25s,transform .25s}
.hl-item.removing{opacity:0;transform:scale(.94)}
.hl-sq{position:relative;width:100%;aspect-ratio:1/1;border-radius:var(--r);overflow:hidden;background:var(--ph,#15171f);border:1px solid var(--line)}
.hl-sq img,.hl-sq video{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;display:block;pointer-events:none}
.hl-vid,.hl-song{position:absolute;bottom:6px;width:20px;height:20px;border-radius:50%;background:rgba(4,6,18,.7);display:flex;align-items:center;justify-content:center;font-size:11px;color:var(--ui-text,#fff)}
.hl-vid{left:6px}.hl-song{right:6px}
.hl-del{position:absolute;top:5px;right:5px;width:24px;height:24px;border-radius:var(--act-r);border:none;background:rgba(0,0,0,.65);color:#fff;font-size:.8rem;display:flex;align-items:center;justify-content:center;transition:.2s;z-index:2}
.hl-del:hover{background:var(--accent);color:var(--on-accent)}
.hl-name{margin-top:7px;font-size:.72rem;font-weight:700;color:var(--ui-text,#fff);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hl-count{margin-top:1px;font-size:.62rem;color:var(--muted)}
.hl-empty{grid-column:1/-1;text-align:center;padding:12px 10px 4px}
.hl-tilecap{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;padding:7px;text-align:center;font-size:10px;font-weight:600;line-height:1.35;color:#fff;text-shadow:0 1px 4px rgba(0,0,0,.85);overflow:hidden;pointer-events:none;background:rgba(0,0,0,.18);word-break:break-word}
.hl-x{margin-left:auto;width:24px;height:24px;border-radius:var(--act-r);border:none;background:rgba(var(--fg),.1);color:var(--sub);flex-shrink:0;display:none;align-items:center;justify-content:center;font-size:.85rem}
.hl-x.show{display:flex}
.hl-x:hover{background:var(--danger-soft);color:var(--danger)}
.hl-seg{display:flex;padding:3px;border-radius:var(--r);background:var(--surface);border:1px solid var(--line);gap:3px}
.hl-seg button{flex:1;height:var(--act-b);border:none;border-radius:var(--act-r);background:none;color:var(--sub);font-size:.66rem;font-weight:700;transition:.2s}
.hl-seg button.on{background:var(--accent);color:var(--on-accent)}
.hl-rows{max-height:270px;overflow-y:auto;display:flex;flex-direction:column;gap:6px;scrollbar-width:thin;scrollbar-color:var(--scroll-thumb) transparent}
.hl-row{display:flex;align-items:center;gap:9px;padding:7px 9px;border:1px solid var(--line);border-radius:var(--r);background:var(--surface)}
.hl-row-th{width:38px;height:38px;border-radius:var(--act-r);overflow:hidden;background:var(--ph,#15171f);flex-shrink:0;display:flex;align-items:center;justify-content:center;color:var(--sub);font-size:1.1rem}
.hl-row-th img{width:100%;height:100%;object-fit:cover}
.hl-row-info{flex:1;min-width:0}
.hl-row-info strong{display:block;font-size:.68rem;font-weight:600;color:var(--ui-text,#fff);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hl-rows-head{font-size:.64rem;color:var(--muted);margin:2px 0 6px;line-height:1.55}

/* highlight editor */
.hle-sec{margin-top:18px;padding-top:16px;border-top:1px solid var(--line)}
.hle-h{font-size:.6rem;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);margin-bottom:10px}
.hle-cover{display:flex;align-items:center;gap:12px}
.hle-cover .hl-sq{width:76px;flex-shrink:0}
.hle-info{flex:1;min-width:0}
.hle-info strong{display:block;font-size:.74rem;font-weight:700;color:var(--ui-text,#fff)}
.hle-info span{display:block;font-size:.64rem;color:var(--muted);margin-top:1px;line-height:1.5}
.hle-btns{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.hle-btn{height:var(--act-b);padding:0 13px;border-radius:var(--act-r);background:rgba(var(--fg),.1);border:none;color:rgba(var(--fg),.85);font-size:.66rem;font-weight:700;transition:.2s;position:relative;overflow:hidden;display:inline-flex;align-items:center}
.hle-btn:hover{background:rgba(var(--fg),.2);color:var(--ui-text,#fff)}
.hle-btn.accent{background:var(--accent);color:var(--on-accent)}
.hle-btn.accent:hover{background:var(--accent-hover)}
.hle-btn.danger:hover{background:var(--danger-soft);color:var(--danger)}
.hle-btn:disabled{opacity:.5;pointer-events:none}
.hle-btn input{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;font-size:0}
.hle-song{display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid var(--line);border-radius:var(--r);background:var(--surface)}
.hle-song>i{font-size:1.25rem;color:var(--accent);flex-shrink:0}
.hle-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:13px 9px}
.hle-item{min-width:0}
.hle-date{margin-top:5px;font-size:.58rem;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hle-actions{display:flex;flex-wrap:wrap;gap:4px;margin-top:5px}
.hle-chip{display:inline-flex;align-items:center;gap:3px;height:calc(22px * .94);padding:0 8px;border-radius:var(--act-r);border:none;background:rgba(var(--fg),.1);color:rgba(var(--fg),.75);font-size:.58rem;font-weight:700;max-width:100%;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;transition:.2s}
.hle-chip.on{background:var(--accent-soft);color:var(--accent-text);box-shadow:inset 0 0 0 1px var(--accent-border)}
.hle-chip:hover{background:rgba(var(--fg),.2);color:var(--ui-text,#fff)}
.hle-chip.x{padding:0 6px}
.hle-danger{margin-top:22px}
.hle-footer{flex-shrink:0;padding-top:10px;padding-bottom:max(12px,env(safe-area-inset-bottom));border-top:1px solid var(--line)}
.hle-note{font-size:.64rem;color:var(--muted);min-height:15px;margin-bottom:7px;text-align:center;line-height:1.4}
.hle-note.warn{color:var(--accent-text)}
.hle-footer .save-btn-full{margin-top:0}
@media (max-width:330px){.hle-grid,.hl-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}

/* clip picker + text editor */
.cp-times{display:flex;justify-content:space-between;font-size:.68rem;color:var(--sub);margin:14px 0 6px}
.cp-times b{color:var(--ui-text,#fff);font-weight:700}
#cp-start{width:100%;accent-color:var(--accent)}
.cp-row{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:12px 0}
#cp-prev-btn{width:100%;margin-bottom:8px;text-align:left}
.hl-view{position:relative;width:100%;aspect-ratio:4/5;max-height:58vh;margin:0 auto;border-radius:var(--r);overflow:hidden;background:#000;border:1px solid var(--line)}
.hl-view>img,.hl-view>video{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;display:block}
#ht-capbox{position:absolute;inset:0}
.hl-cap-wrap{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;padding:18px;text-align:center;min-height:0}
.hl-cap{max-width:100%;min-height:0;padding:10px 13px;border-radius:var(--r);background:rgba(0,0,0,.34);color:#fff;font-size:15px;font-weight:600;line-height:1.5;text-shadow:0 1px 6px rgba(0,0,0,.85);word-break:break-word;overflow-wrap:anywhere}
.hl-cap.clamp{display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;overflow:hidden}
.hl-cap:not(.clamp){max-height:100%;overflow-y:auto;scrollbar-width:none}
.hl-cap:not(.clamp)::-webkit-scrollbar{display:none}
.hl-cap-empty{opacity:.6;font-weight:500}
.hl-more{flex-shrink:0;background:rgba(0,0,0,.5);border:1px solid rgba(255,255,255,.25);border-radius:99px;color:#fff;font-size:11px;font-weight:600;padding:3px 12px}
.hl-more:hover{background:rgba(0,0,0,.7)}
.hl-men{font-weight:700;text-decoration:none;cursor:pointer;text-shadow:0 1px 5px rgba(0,0,0,.9)}
.hl-men.user{color:#ff3b4e}.hl-men.group{color:#3ea6ff}
.hl-men:hover{text-decoration:underline}
.ht-count{font-size:.64rem;color:var(--muted);text-align:right;margin-top:5px}
#ht-input{width:100%;min-height:110px;flex:none}
.ht-preview-wrap{margin-top:14px}
.ht-actions{display:flex;gap:8px;margin-top:12px}
.ht-actions .save-btn-full{margin-top:0}
.ht-remove{flex-shrink:0;height:var(--ctl-b);padding:0 16px;border-radius:var(--r);background:rgba(var(--fg),.1);border:none;color:rgba(var(--fg),.75);font-size:.74rem;font-weight:700;transition:.2s}
.ht-remove:hover{background:var(--danger-soft);color:var(--danger)}

/* ══ DEZZME VERIFIED ══ */
.vf-check{display:flex;align-items:center;gap:11px;padding:10px 0;border-bottom:1px solid var(--line)}
.vf-check .ic{width:20px;height:20px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.8rem;flex-shrink:0}
.vf-check.ok .ic{background:rgba(74,222,128,.15);color:var(--ok)}
.vf-check.todo .ic{background:rgba(var(--fg),.08);color:var(--muted)}
.vf-check div{flex:1;min-width:0}
.vf-check strong{display:block;font-size:.72rem;font-weight:600;color:var(--ui-text,#fff)}
.vf-check span{display:block;font-size:.62rem;color:var(--muted);margin-top:1px;line-height:1.5}
.vf-check a{color:var(--accent-text);font-size:.66rem;font-weight:700;cursor:pointer;flex-shrink:0}
/* ══ PRIVACY LEVELS ══ */
.pv-hero{padding:14px;border:1px solid var(--accent-border);border-radius:var(--r);background:linear-gradient(160deg,var(--accent-soft),rgba(var(--fg),.02))}
.pv-hero-top{display:flex;align-items:center;gap:12px}
.pv-hero-ic{width:42px;height:42px;border-radius:var(--r);background:var(--accent);color:var(--on-accent);display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0}
.pv-hero-l{font-size:.56rem;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--accent-text)}
.pv-hero-n{font-size:1.05rem;font-weight:700;color:var(--ui-text,#fff);line-height:1.25}
.pv-hero-s{font-size:.68rem;color:var(--sub);line-height:1.65;margin-top:12px}
.pv-meter{display:flex;gap:4px;margin-top:14px}
.pv-meter span{flex:1;height:4px;border-radius:2px;background:rgba(var(--fg),.14);transition:background .3s}
.pv-meter span.on{background:var(--accent)}
.pv-meter-l{display:flex;margin-top:5px;font-size:.54rem;color:var(--muted)}
.pv-meter-l span{flex:1;text-align:left}
.pv-meter-l span:last-child{text-align:right}
.pv-h{font-size:.6rem;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);margin:24px 0 10px;display:flex;align-items:center;gap:8px}
.pv-h::after{content:'';flex:1;height:1px;background:var(--line)}
.pv-h-r{order:2;color:var(--accent-text);letter-spacing:.5px}
.pv-levels{display:flex;flex-direction:column;gap:9px}
.pv-card{display:block;width:100%;text-align:left;padding:13px;border:1px solid var(--border);border-radius:var(--r);background:var(--surface);color:inherit;font:inherit;transition:background .2s,border-color .2s,box-shadow .2s,opacity .2s}
.pv-card:hover{background:var(--surface-2)}
.pv-card.on{border-color:var(--accent);background:var(--accent-soft);box-shadow:0 0 0 1px var(--accent)}
.pv-levels.busy .pv-card{opacity:.6;pointer-events:none}
.pv-card-top{display:flex;align-items:center;gap:11px}
.pv-ic{width:34px;height:34px;border-radius:var(--act-r);background:var(--accent-soft);color:var(--accent-text);display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;transition:background .2s,color .2s}
.pv-ic.sm{width:30px;height:30px;font-size:1rem}
.pv-card.on .pv-ic{background:var(--accent);color:var(--on-accent)}
.pv-cn{font-size:.84rem;font-weight:700;color:var(--ui-text,#fff)}
.pv-ct{font-size:.64rem;color:var(--muted);margin-top:1px}
.pv-radio{width:20px;height:20px;border-radius:50%;border:2px solid rgba(var(--fg),.35);margin-left:auto;flex-shrink:0;display:flex;align-items:center;justify-content:center;transition:.2s}
.pv-card.on .pv-radio{border-color:var(--accent);background:var(--accent)}
.pv-card.on .pv-radio::after{content:'';width:6px;height:6px;border-radius:50%;background:#000}
.pv-cd{font-size:.68rem;color:var(--sub);line-height:1.65;margin-top:11px}
.pv-pts{list-style:none;margin-top:9px;display:flex;flex-direction:column;gap:6px}
.pv-pts li{display:flex;gap:8px;font-size:.66rem;color:rgba(var(--fg),.78);line-height:1.5}
.pv-pts i{color:var(--accent-text);font-size:.9rem;flex-shrink:0;margin-top:1px}
.pv-best{margin-top:11px;padding-top:10px;border-top:1px solid var(--line);font-size:.62rem;color:var(--muted);line-height:1.5}
.pv-best b{color:var(--ui-text,#fff);font-weight:600}
.pv-mx{border:1px solid var(--line);border-radius:var(--r);overflow:hidden;background:var(--surface)}
.pv-mx-r{display:grid;grid-template-columns:1fr repeat(4,clamp(42px,12vw,58px));align-items:center;border-top:1px solid var(--line)}
.pv-mx-r:first-child{border-top:none}
.pv-mx-r>*{padding:9px 4px;text-align:center;font-size:.62rem;transition:background .25s}
.pv-mx-r>:first-child{text-align:left;padding-left:12px;color:rgba(var(--fg),.82);font-size:.66rem;line-height:1.4}
.pv-mx-h>*{font-weight:700;color:var(--muted);font-size:.54rem;letter-spacing:.5px;text-transform:uppercase}
.pv-mx-r .sel{background:var(--accent-soft)}
.pv-mx-h .sel{color:var(--accent-text)}
.pv-mx i{font-size:1rem}
.pv-a{color:var(--ok)}.pv-t{color:#fbbf24}.pv-n{color:var(--danger)}
.pv-legend{display:flex;flex-wrap:wrap;gap:6px 14px;font-size:.6rem;color:var(--muted);margin-top:10px}
.pv-legend i{vertical-align:-2px;margin-right:4px;font-size:.85rem}
.pv-box{border:1px solid var(--line);border-radius:var(--r);background:var(--surface);padding:0 12px}
.pv-set{display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid var(--line)}
.pv-set.col{flex-direction:column;align-items:stretch;gap:0}
.pv-set.col .pv-seg{margin-top:10px}
.sd-list{display:flex;flex-direction:column;margin-top:14px;border:1px solid var(--line);border-radius:var(--r);background:var(--surface)}
.sd-row{display:flex;align-items:flex-start;gap:12px;padding:13px 14px;border-bottom:1px solid var(--line)}
.sd-row:last-child{border-bottom:none}
.sd-row i{color:var(--accent);font-size:1.15rem;margin-top:1px}
.sd-row div{flex:1;min-width:0}.sd-row b{display:block;font-size:.84rem}.sd-row span{display:block;font-size:.74rem;color:var(--muted);line-height:1.45;margin-top:2px}
.sd-row em{font-style:normal;font-weight:700;font-size:.9rem;white-space:nowrap}
.pv-set:last-child{border-bottom:none}
.pv-set-tx{flex:1;min-width:0}
.pv-set-tx strong{display:block;font-size:.76rem;color:var(--ui-text,#fff);font-weight:600}
.pv-set-tx span{display:block;font-size:.64rem;color:var(--muted);line-height:1.55;margin-top:2px}
.pv-go{cursor:pointer}
.pv-go:hover .menu-chevron{color:var(--ui-text,#fff);transform:translateX(2px)}
.pv-faq-wrap{border:1px solid var(--line);border-radius:var(--r);background:var(--surface);overflow:hidden}
.pv-faq{border-top:1px solid var(--line)}
.pv-faq:first-child{border-top:none}
.pv-faq summary{list-style:none;cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px;font-size:.72rem;font-weight:600;color:var(--ui-text,#fff);line-height:1.45}
.pv-faq summary::-webkit-details-marker{display:none}
.pv-faq summary::after{content:'\ea4e';font-family:'remixicon';font-size:1.1rem;color:var(--muted);transition:transform .25s;flex-shrink:0}
.pv-faq[open] summary::after{transform:rotate(180deg);color:var(--accent-text)}
.pv-faq p{padding:0 12px 13px;font-size:.66rem;color:var(--sub);line-height:1.7}

/* ══ TAGS, MENTIONS, LISTS (same cards as Privacy Controls) ══ */
.pv-card.compact .pv-cd{margin-top:10px}
.pv-seg{display:flex;padding:3px;gap:3px;margin-top:12px;border-radius:var(--r);background:rgba(0,0,0,.25);border:1px solid var(--line)}
.pv-seg button{flex:1;height:calc(var(--act-h) * .94 + 2px);border:none;border-radius:var(--act-r);background:none;color:var(--sub);font:inherit;font-size:.66rem;font-weight:700;transition:background .2s,color .2s}
.pv-seg button:hover{color:var(--ui-text,#fff)}
.pv-seg button.on{background:var(--accent);color:var(--on-accent)}
.pv-hint{margin-top:10px;font-size:.64rem;color:var(--muted);line-height:1.55}
.ls-card{cursor:default}
.ls-pv{display:flex;align-items:center;gap:10px;padding:11px 12px;border-top:1px solid var(--line)}
.ls-pv:first-child{border-top:none}
.ls-pv-ic{color:var(--accent-text);font-size:1rem}
.ls-pv-n{flex:1;font-size:.72rem;font-weight:600;color:var(--ui-text,#fff)}
.ls-pv-v{font-size:.64rem;font-weight:600}
.ls-pv-v.everyone{color:var(--ok)}.ls-pv-v.trackers{color:#fbbf24}.ls-pv-v.me{color:var(--danger)}
#modal-reviewtags{z-index:1100}

/* ══ PROFILE STATUS ══ */
.st-hero{text-align:center;padding:20px 14px;border:1px solid var(--line);border-radius:var(--r);background:var(--surface)}
.st-ring{width:62px;height:62px;border-radius:50%;margin:0 auto 12px;display:flex;align-items:center;justify-content:center;font-size:1.9rem}
.st-hero.good .st-ring{background:rgba(74,222,128,.13);border:1px solid rgba(74,222,128,.35);color:var(--ok)}
.st-hero.limited .st-ring{background:rgba(251,146,60,.13);border:1px solid rgba(251,146,60,.4);color:#fb923c}
.st-hero.suspended .st-ring{background:var(--danger-soft);border:1px solid var(--accent-border);color:var(--danger)}
.st-t{font-size:1rem;font-weight:700;color:var(--ui-text,#fff);line-height:1.35}
.st-s{font-size:.68rem;color:var(--sub);line-height:1.7;margin:7px auto 0;max-width:420px}
.st-list{border:1px solid var(--line);border-radius:var(--r);overflow:hidden;background:var(--surface)}
.st-row{display:flex;align-items:center;gap:11px;padding:12px;border-top:1px solid var(--line)}
.st-row:first-child{border-top:none}
.st-row .ic{width:30px;height:30px;border-radius:var(--act-r);background:var(--accent-soft);color:var(--accent-text);display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0}
.st-row .k{flex:1;min-width:0;font-size:.74rem;font-weight:600;color:var(--ui-text,#fff)}
.st-row .v{font-size:.66rem;color:var(--sub);text-align:right;flex-shrink:0}
.st-v.ok{color:var(--ok);font-weight:700}.st-v.warn{color:#fbbf24;font-weight:700}
.st-go{cursor:pointer;transition:background .15s}
.st-go:hover{background:rgba(var(--fg),.05)}
.st-go:hover .menu-chevron{color:var(--ui-text,#fff);transform:translateX(2px)}
.st-prog{height:4px;border-radius:2px;background:rgba(var(--fg),.12);overflow:hidden;margin-bottom:4px}
.st-prog span{display:block;height:100%;background:var(--accent);border-radius:2px;transition:width .4s}
a.save-btn-full{text-decoration:none}

/* toast (Memepedia style) */
.dz-toast{position:fixed;bottom:max(24px,env(safe-area-inset-bottom));left:50%;transform:translateX(-50%) translateY(10px);display:flex;align-items:center;padding:9px 18px;border-radius:var(--r);background:var(--sheet-solid,rgba(8,8,22,.97));border:1px solid var(--accent-border);color:var(--ui-text,#fff);font-size:.74rem;font-weight:500;z-index:99999;pointer-events:none;box-shadow:0 8px 24px rgba(0,0,0,.6);white-space:nowrap;max-width:calc(100vw - 32px);overflow:hidden;text-overflow:ellipsis;opacity:0;transition:opacity .2s,transform .2s}
.dz-toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
@keyframes spin{to{transform:rotate(360deg)}}
.spinning{animation:spin .7s linear infinite;display:inline-block}

/* page skeleton */
#page-skeleton{position:fixed;inset:0;background:var(--bg);background-attachment:fixed;z-index:9999;display:flex;opacity:1;transition:opacity .12s;pointer-events:none}
#page-skeleton.hide{opacity:0}
.sk-sidebar{width:var(--sidebar-w);flex-shrink:0;padding:28px 14px;border-right:1px solid var(--line);display:flex;flex-direction:column;gap:9px}
.sk-wrap{flex:1;min-width:0;display:flex;justify-content:center}
@media (min-width:1180px){.sk-wrap{justify-content:flex-start;padding-left:22px}}
.sk-lane{width:100%;max-width:var(--max-w);display:flex;flex-direction:column;overflow:hidden}
@media (min-width:801px){.sk-lane{border-left:1px solid var(--line);border-right:1px solid var(--line)}}
.sk-topbar{height:var(--topbar-h);display:flex;align-items:center;padding:0 var(--pad);border-bottom:1px solid rgba(var(--fg),.14);flex-shrink:0}
.sk-row{display:flex;align-items:center;gap:12px;padding:14px var(--pad);border-top:1px solid var(--line)}

/* ══ RESPONSIVE ══ */
@media (max-width:790px){
  body{flex-direction:column;font-size:12px}
  .sidebar{width:100%;height:auto;padding:6px 8px;gap:4px;z-index:300;background:var(--solid,#070924);border-right:none;border-bottom:1px solid var(--line);display:grid;grid-template-columns:repeat(4,1fr);align-items:center}
  .logo{display:none}
  .nav-btn{flex-direction:column;justify-content:center;gap:2px;padding:6px 2px;border-radius:var(--act-r);font-size:10px;font-weight:600;text-align:center;white-space:nowrap;overflow:hidden}
  .nav-btn .btn-label{display:block;font-size:10px;font-weight:600;color:inherit;line-height:1}
  .nav-btn i{font-size:16px}
  .mp-shell{width:100%}
  .ac-topbar.plain{position:relative}
  .menu-label{font-size:.74rem}
  .stat-row{gap:6px;padding:8px var(--pad) 2px}
  .stat-pill{padding:7px 10px;gap:2px}
  .stat-pill-num{font-size:.98rem}
  .stat-pill-label{font-size:.52rem;letter-spacing:1px}
  .field-control{flex-direction:column;gap:7px}
  .field-control .input-wrap,.field-control input.f-input,.field-control select.f-select{width:100%;flex:none}
  .save-btn{width:100%}
  .track-req-btns{flex-direction:column}
  #page-skeleton{flex-direction:column}
  .sk-sidebar{width:100%;flex-direction:row;padding:6px 8px;border-right:none;border-bottom:1px solid var(--line)}
  .sk-sidebar .sk-bar{flex:1;height:38px}
}
@media (max-width:490px){
  .menu-label{font-size:.7rem}
  .stat-pill{padding:6px 9px}
  .stat-pill-num{font-size:.9rem}
  .stat-pill-label{font-size:.5rem}
  .menu-icon{width:30px;height:30px}
  .menu-value{max-width:78px}
  .mod-tab i{display:none}
}
@media (max-width:390px){
  .treq-approve,.treq-deny{padding:0 9px}
  .menu-desc{font-size:.6rem}
}
@media (max-width:330px){
  .nav-btn .btn-label{font-size:9px}
  .menu-desc{display:none}
  .menu-value{max-width:56px}
  .mod-tab-count{display:none}
  .track-req-row img{width:30px;height:30px}
}
/* ══ PRIVACY / TAGS / LISTS / STATUS MODALS: premium responsive ══ */
.pv-card:active{transform:scale(.995)}
.pv-hero{box-shadow:0 10px 30px rgba(0,0,0,.25)}
.ls-pv-v{text-align:right}
@media (max-width:790px){
  .pv-hero{padding:12px}
  .pv-hero-ic{width:38px;height:38px;font-size:1.2rem}
  .pv-hero-n{font-size:.98rem}
  .pv-hero-s{font-size:.64rem;margin-top:10px}
  .pv-h{margin:20px 0 9px}
  .pv-card{padding:12px}
  .pv-ic{width:32px;height:32px;font-size:1rem}
  .pv-cn{font-size:.8rem}
  .pv-ct{font-size:.6rem}
  .pv-cd{font-size:.64rem;margin-top:9px}
  .pv-pts li{font-size:.62rem}
  .pv-best{font-size:.58rem}
  .pv-mx-r>:first-child{font-size:.62rem;padding-left:10px}
  .pv-set{padding:11px 0;gap:10px}
  .pv-set-tx strong{font-size:.72rem}
  .pv-set-tx span{font-size:.6rem}
  .pv-faq summary{font-size:.68rem;padding:11px}
  .pv-faq p{font-size:.62rem;padding:0 11px 12px}
  .pv-legend{font-size:.57rem}
  .ls-pv{padding:10px 11px}
  .ls-pv-n{font-size:.68rem}
  .ls-pv-v{font-size:.6rem}
  .st-t{font-size:.92rem}
  .st-s{font-size:.64rem}
  .st-row{padding:11px}
  .st-row .k{font-size:.7rem}
}
@media (max-width:490px){
  .pv-hero-n{font-size:.92rem}
  .pv-meter-l{font-size:.5rem}
  .pv-meter-l span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .pv-mx-r{grid-template-columns:1fr repeat(4,clamp(36px,11vw,46px))}
  .pv-mx-h>*{font-size:.5rem;letter-spacing:.3px}
  .pv-mx i{font-size:.92rem}
  .pv-seg button{font-size:.62rem}
  .pv-card-top{gap:9px}
}
@media (max-width:390px){
  .pv-ic{width:30px;height:30px}
  .pv-box{padding:0 10px}
  .pv-mx-r>*{padding:8px 2px}
  .pv-mx-r>:first-child{font-size:.58rem;padding-left:9px}
  .pv-legend{gap:5px 10px}
  .ls-pv-v{max-width:48%}
}
@media (max-width:330px){
  .pv-mx-r{grid-template-columns:1fr repeat(4,32px)}
  .pv-seg button{font-size:.58rem}
  .pv-hero-s{font-size:.6rem}
}
/* ══ EACH MODAL GETS ITS OWN LOOK (all still red/black theme) ══ */
.pv-hero{position:relative;overflow:hidden}
.pv-hero>*:not(.pv-wm){position:relative}
.pv-wm{position:absolute;right:-6px;top:-14px;font-size:7.5rem;color:var(--accent);opacity:.1;pointer-events:none;transform:rotate(-8deg)}
#modal-privacy,#modal-tags,#modal-reviewtags,#modal-lists{background:radial-gradient(900px 320px at 50% -90px,var(--glow,rgba(255,45,45,.17)),transparent 70%),var(--bg-lin,linear-gradient(-45deg,#010218,#070924,#070924))}
#modal-privacy .ac-topbar,#modal-tags .ac-topbar,#modal-reviewtags .ac-topbar,#modal-lists .ac-topbar{background:transparent}
/* Privacy Controls: signature red */
#modal-privacy{--glow:rgba(255,45,45,.17)}
/* Tags and mentions: deep crimson */
#modal-tags,#modal-reviewtags{--glow:rgba(232,34,46,.2);--accent:#e8222e;--accent-hover:#cf1620;--accent-soft:rgba(232,34,46,.15);--accent-border:rgba(232,34,46,.45);--accent-fill:rgba(232,34,46,.22);--accent-glow:rgba(232,34,46,.3);--accent-text:#ff7d85;--accent-focus:rgba(232,34,46,.7);--accent-ring:rgba(232,34,46,.12)}
/* Trackers, Tracking and Pods: true red */
#modal-lists{--glow:rgba(242,31,31,.2);--accent:#f21f1f;--accent-hover:#d91212;--accent-soft:rgba(242,31,31,.15);--accent-border:rgba(242,31,31,.45);--accent-fill:rgba(242,31,31,.22);--accent-glow:rgba(242,31,31,.3);--accent-text:#ff8080;--accent-focus:rgba(242,31,31,.7);--accent-ring:rgba(242,31,31,.12)}

/* Tags and mentions: tile selector */
.tg-tiles{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}
.pv-opt.tile{position:relative;display:flex;flex-direction:column;align-items:center;text-align:center;gap:5px;padding:16px 8px 12px;border:1px solid var(--border);border-radius:var(--r);background:var(--surface);color:inherit;font:inherit;transition:background .2s,border-color .2s,box-shadow .2s,transform .15s}
.pv-opt.tile:hover{background:var(--surface-2)}
.pv-opt.tile:active{transform:scale(.97)}
.pv-opt.tile.on{border-color:var(--accent);background:var(--accent-soft);box-shadow:0 0 0 1px var(--accent)}
.pv-opt.tile .pv-ic{width:40px;height:40px;font-size:1.25rem;margin-bottom:3px}
.pv-opt.tile.on .pv-ic{background:var(--accent);color:var(--on-accent)}
.pv-opt.tile b{font-size:.74rem;font-weight:700;color:var(--ui-text,#fff);line-height:1.25}
.pv-opt.tile small{font-size:.58rem;color:var(--muted);line-height:1.3}
.pv-opt.tile .tk{position:absolute;top:7px;right:7px;width:14px;height:14px;border-radius:50%;border:2px solid rgba(var(--fg),.28);transition:.2s}
.pv-opt.tile.on .tk{border-color:var(--accent);background:var(--accent)}
.pv-opt.tile.on .tk::after{content:'';position:absolute;inset:3px;border-radius:50%;background:#000}
.tg-desc{margin-top:10px;padding:11px 12px;border-left:3px solid var(--accent);background:var(--accent-soft);border-radius:0 var(--r) var(--r) 0;font-size:.68rem;color:rgba(var(--fg),.84);line-height:1.65}

/* Lists: hero stats, audience limits, group table */
.pv-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:14px}
.pv-stat{padding:9px 6px;border-radius:var(--act-r);background:rgba(0,0,0,.28);border:1px solid var(--line);text-align:center}
.pv-stat b{display:block;font-size:1.05rem;color:var(--ui-text,#fff);line-height:1.2}
.pv-stat span{font-size:.52rem;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--muted)}
.lm-wrap{margin-top:12px;padding-top:4px;border-top:1px solid var(--line)}
.lm-h{font-size:.56rem;font-weight:700;letter-spacing:1.3px;text-transform:uppercase;color:var(--accent-text);margin:10px 0 2px}
.lm-row{padding:11px 0;border-top:1px dashed var(--line)}
.lm-h+.lm-row{border-top:none}
.lm-top{display:flex;align-items:center;justify-content:space-between;gap:10px}
.lm-who{display:flex;align-items:center;gap:7px;font-size:.72rem;font-weight:600;color:var(--ui-text,#fff);min-width:0}
.lm-who i{color:var(--accent-text);font-size:.95rem}
.pv-seg.sm{margin-top:0;padding:2px;flex-shrink:0}
.pv-seg.sm button{flex:none;padding:0 11px;height:calc(var(--act-h) * .94)}
.lm-body{margin-top:10px}
.lm-step{display:flex;align-items:center;gap:8px}
.lm-step button{width:calc(var(--act-h) * .94);height:calc(var(--act-h) * .94);border:none;border-radius:var(--act-r);background:rgba(var(--fg),.1);color:var(--ui-text,#fff);font-size:1.05rem;font-weight:700;flex-shrink:0;transition:background .2s}
.lm-step button:hover{background:rgba(var(--fg),.2)}
.lm-step input{flex:1;min-width:0;height:calc(var(--act-h) * .94);text-align:center;border-radius:var(--act-r);border:1px solid var(--border);background:var(--surface);color:var(--ui-text,#fff);font-size:.8rem;font-weight:700;outline:none;-moz-appearance:textfield}
.lm-step input::-webkit-outer-spin-button,.lm-step input::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}
.lm-step input:focus{border-color:var(--accent-focus);box-shadow:0 0 0 3px var(--accent-ring)}
.lm-chips{display:flex;flex-wrap:wrap;gap:5px;margin-top:8px}
.lm-chips button{height:22px;padding:0 11px;border:none;border-radius:var(--act-r);background:rgba(var(--fg),.1);color:rgba(var(--fg),.75);font-size:.6rem;font-weight:700;transition:.2s}
.lm-chips button:hover{background:rgba(var(--fg),.2);color:var(--ui-text,#fff)}
.lm-chips button.on{background:var(--accent);color:var(--on-accent)}
.lm-state{margin-top:8px;font-size:.62rem;color:var(--muted);line-height:1.5}
.lm-row.off .pv-seg{opacity:.3;pointer-events:none}
.lm-row.off .lm-who{opacity:.5}
.pv-mx-r.lv{grid-template-columns:1fr minmax(74px,28%) minmax(74px,28%)}
.lv-c{font-size:.62rem;font-weight:700}
.lv-c.everyone{color:var(--ok)}.lv-c.trackers{color:#fbbf24}.lv-c.me{color:var(--danger)}

@media (max-width:790px){
  .pv-opt.tile{padding:14px 6px 11px}
  .pv-opt.tile b{font-size:.7rem}
  .tg-desc{font-size:.64rem;padding:10px 11px}
  .lm-who{font-size:.68rem}
  .lm-state{font-size:.6rem}
  .pv-stat b{font-size:.98rem}
  .pv-wm{font-size:6rem}
}
@media (max-width:490px){
  .pv-opt.tile .pv-ic{width:36px;height:36px;font-size:1.1rem}
  .pv-opt.tile small{font-size:.54rem}
  .pv-mx-r.lv{grid-template-columns:1fr minmax(64px,27%) minmax(64px,27%)}
  .lv-c{font-size:.58rem}
  .pv-stat span{font-size:.48rem;letter-spacing:.6px}
}
@media (max-width:390px){
  .tg-tiles{gap:6px}
  .pv-opt.tile{padding:12px 4px 10px}
  .pv-opt.tile b{font-size:.64rem}
  .lm-top{flex-wrap:wrap}
  .pv-seg.sm button{padding:0 9px}
}
@media (max-width:330px){
  .pv-opt.tile small{display:none}
  .pv-mx-r.lv{grid-template-columns:1fr 58px 58px}
}
/* ══ TRACK REQUESTS + MODERATION: same design, their own reds ══ */
#modal-trackreqs,#modal-blocked{background:radial-gradient(900px 320px at 50% -90px,var(--glow,rgba(255,45,45,.17)),transparent 70%),var(--bg-lin,linear-gradient(-45deg,#010218,#070924,#070924))}
#modal-trackreqs .ac-topbar,#modal-blocked .ac-topbar{background:transparent}
/* Track Requests: bright scarlet */
#modal-trackreqs{--glow:rgba(255,71,71,.2);--accent:#ff4747;--accent-hover:#ff2e2e;--accent-soft:rgba(255,71,71,.15);--accent-border:rgba(255,71,71,.45);--accent-fill:rgba(255,71,71,.22);--accent-glow:rgba(255,71,71,.3);--accent-text:#ff9494;--accent-focus:rgba(255,71,71,.7);--accent-ring:rgba(255,71,71,.12)}
/* Moderation: deep ruby */
#modal-blocked{--glow:rgba(214,34,34,.2);--accent:#d92222;--accent-hover:#c01616;--accent-soft:rgba(217,34,34,.16);--accent-border:rgba(217,34,34,.48);--accent-fill:rgba(217,34,34,.24);--accent-glow:rgba(217,34,34,.3);--accent-text:#ff7a7a;--accent-focus:rgba(217,34,34,.7);--accent-ring:rgba(217,34,34,.12)}
.pv-stat b.sm{font-size:.8rem;line-height:1.55}
.pv-card.static{cursor:default;margin-top:10px}
.pv-card.static:hover{background:var(--surface)}
.pv-card.static .pv-pts{margin-top:11px}

/* request cards */
#modal-trackreqs .track-req-row,#modal-trackreqs .track-req-row:first-child,#modal-trackreqs .track-req-row:last-child{padding:11px 12px;border:1px solid var(--line);border-radius:var(--r);background:var(--surface);margin-bottom:8px}
#modal-trackreqs .track-req-row:hover{background:var(--surface-2);border-color:var(--accent-border)}
#modal-trackreqs .track-req-meta small{line-height:1.45}

/* moderation: segmented list switcher + comparison table */
.mod-seg{display:flex;gap:3px;padding:3px;border-radius:var(--r);background:rgba(0,0,0,.25);border:1px solid var(--line)}
.mod-seg .mod-tab{flex:1;height:calc(var(--ctl-h) * .94);padding:0 4px;margin:0;border:none;border-bottom:none;border-radius:var(--act-r);color:var(--sub);font-size:.68rem}
.mod-seg .mod-tab:hover{color:var(--ui-text,#fff)}
.mod-seg .mod-tab.active{background:var(--accent);color:var(--on-accent);border-bottom:none}
.mod-seg .mod-tab-count{background:rgba(var(--fg),.12);color:var(--ui-text,#fff)}
.mod-seg .mod-tab.active .mod-tab-count{background:rgba(0,0,0,.25);color:#000}
.pv-mx-r.mx3{grid-template-columns:1fr repeat(3,clamp(50px,15vw,70px))}
#modal-blocked .mod-user-row:hover{border-color:var(--accent-border)}

@media (max-width:790px){
  .mod-seg .mod-tab{font-size:.64rem}
  .pv-stat b.sm{font-size:.74rem}
}
@media (max-width:490px){
  .pv-mx-r.mx3{grid-template-columns:1fr repeat(3,clamp(42px,13vw,56px))}
  .mod-seg .mod-tab{gap:4px}
}
@media (max-width:390px){
  #modal-trackreqs .track-req-row{padding:10px}
  .mod-seg .mod-tab{font-size:.6rem}
}
@media (max-width:330px){
  .pv-mx-r.mx3{grid-template-columns:1fr repeat(3,38px)}
  .mod-seg .mod-tab-count{display:none}
}

/* ══ NEW: PROFILE PICTURE UPLOAD + CROP ══ */
#modal-avatar{z-index:1150}
.av-row{display:flex;align-items:center;gap:14px}
.av-cur{width:68px;height:68px;border-radius:50%;object-fit:cover;background:var(--ph,#15171f);border:2px solid var(--accent);flex-shrink:0}
.av-btns{display:flex;flex-wrap:wrap;gap:6px}
.av-btns .hle-btn{margin-top:0}
.av-stage{position:relative;width:100%;max-width:340px;aspect-ratio:1/1;margin:4px auto 0;border-radius:var(--r);overflow:hidden;background:#000;border:1px solid var(--line);touch-action:none}
.av-stage canvas{width:100%;height:100%;display:block;cursor:grab;touch-action:none}
.av-stage canvas.drag{cursor:grabbing}
.av-zoom{display:flex;align-items:center;gap:10px;margin:14px auto 0;max-width:340px;color:var(--sub)}
.av-zoom i{font-size:1.1rem}
.av-zoom input{flex:1;min-width:0;accent-color:var(--accent)}
.av-actions{max-width:340px;margin:14px auto 0}
.av-actions .save-btn-full{margin-top:0}
.av-cancel{width:100%;margin-top:8px;height:var(--ctl-b);border-radius:var(--r);border:none;background:rgba(var(--fg),.1);color:rgba(var(--fg),.8);font-size:.74rem;font-weight:700;transition:.2s}
.av-cancel:hover{background:rgba(var(--fg),.2);color:var(--ui-text,#fff)}

/* ══ NEW: USERNAME LIMIT NOTE ══ */
.un-note{font-size:.64rem;color:var(--muted);line-height:1.65}
.un-note.locked{color:#fbbf24}
.un-note b{color:var(--ui-text,#fff);font-weight:600}
input.f-input[readonly]{opacity:.6;cursor:not-allowed}

/* ══ NEW: DEACTIVATE / DELETE ══ */
#modal-danger{background:radial-gradient(900px 320px at 50% -90px,rgba(214,34,34,.22),transparent 70%),var(--bg-lin,linear-gradient(-45deg,#010218,#070924,#070924));--accent:#d92222;--accent-hover:#c01616;--accent-soft:rgba(217,34,34,.16);--accent-border:rgba(217,34,34,.48);--accent-fill:rgba(217,34,34,.24);--accent-glow:rgba(217,34,34,.3);--accent-text:#ff7a7a;--accent-focus:rgba(217,34,34,.7);--accent-ring:rgba(217,34,34,.12)}
#modal-danger .ac-topbar{background:transparent}
#modal-danger .save-btn-full{color:var(--ui-text,#fff)}
#modal-danger .pv-card.on .pv-ic,#modal-danger .pv-hero-ic{color:var(--ui-text,#fff)}
.dg-form{margin-top:14px;display:flex;flex-direction:column;gap:9px}
.dg-form input.f-input{width:100%;flex:none}
.dg-label{font-size:.6rem;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted)}
.dg-banner{display:flex;align-items:center;gap:10px;margin:10px var(--pad) 0;padding:10px 12px;border:1px solid var(--accent-border);border-radius:var(--r);background:var(--danger-soft);font-size:.68rem;line-height:1.55;color:rgba(var(--fg),.88)}
.dg-banner i{color:var(--danger);font-size:1.15rem;flex-shrink:0}
.dg-banner span{flex:1;min-width:0}
.dg-banner .hle-btn{margin-top:0;flex-shrink:0}
.dg-pending{margin-bottom:14px;display:flex;gap:10px;align-items:flex-start;padding:11px 12px;border:1px solid var(--accent-border);border-radius:var(--r);background:var(--danger-soft);font-size:.68rem;line-height:1.6;color:rgba(var(--fg),.88)}
.dg-pending i{color:var(--danger);font-size:1.1rem;flex-shrink:0;margin-top:1px}
.dg-pending .hle-btns{margin-top:8px}
/* ══ NEW: TIME MANAGEMENT, NOTIFICATIONS, BIRTHDAYS ══ */
#modal-time,#modal-notifs,#modal-birthdays{background:radial-gradient(900px 320px at 50% -90px,var(--glow,rgba(255,45,45,.17)),transparent 70%),var(--bg-lin,linear-gradient(-45deg,#010218,#070924,#070924))}
#modal-time .ac-topbar,#modal-notifs .ac-topbar,#modal-birthdays .ac-topbar{background:transparent}
#modal-time{--glow:rgba(255,77,77,.2);--accent:#ff4d4d;--accent-hover:#ff3333;--accent-soft:rgba(255,77,77,.15);--accent-border:rgba(255,77,77,.45);--accent-fill:rgba(255,77,77,.22);--accent-text:#ff9a9a;--accent-focus:rgba(255,77,77,.7);--accent-ring:rgba(255,77,77,.12)}
#modal-notifs{--glow:rgba(232,34,46,.2);--accent:#e8222e;--accent-hover:#cf1620;--accent-soft:rgba(232,34,46,.15);--accent-border:rgba(232,34,46,.45);--accent-fill:rgba(232,34,46,.22);--accent-text:#ff7d85;--accent-focus:rgba(232,34,46,.7);--accent-ring:rgba(232,34,46,.12)}
#modal-birthdays{--glow:rgba(255,79,121,.2);--accent:#ff4f79;--accent-hover:#ff2f61;--accent-soft:rgba(255,79,121,.15);--accent-border:rgba(255,79,121,.45);--accent-fill:rgba(255,79,121,.22);--accent-text:#ff9db5;--accent-focus:rgba(255,79,121,.7);--accent-ring:rgba(255,79,121,.12)}

/* time chart */
.tm-chart{margin-top:14px;padding:14px 12px 10px;border:1px solid var(--line);border-radius:var(--r);background:var(--surface)}
.tm-plot{position:relative;height:190px;margin-left:38px}
.tm-gl{position:absolute;left:0;right:0;height:0;border-top:1px dashed rgba(var(--fg),.12)}
.tm-gl span{position:absolute;right:100%;margin-right:7px;transform:translateY(-50%);font-size:.54rem;color:var(--muted);white-space:nowrap}
.tm-gl.t{top:0}.tm-gl.m{top:50%}.tm-gl.b{bottom:0;top:auto;border-top:1px solid rgba(var(--fg),.18)}
.tm-bars{position:absolute;inset:0;display:flex;align-items:flex-end;gap:4px}
.tm-col{flex:1;min-width:0;height:100%;display:flex;align-items:flex-end;background:none;border:none;padding:0;position:relative}
.tm-col::after{content:'';position:absolute;inset:0;border-radius:3px;background:transparent;transition:background .2s}
.tm-col:hover::after,.tm-col.sel::after{background:rgba(var(--fg),.07)}
.tm-bar{display:block;width:100%;min-height:0;border-radius:4px 4px 1px 1px;background:linear-gradient(180deg,var(--accent),rgba(255,77,77,.35));transition:height .35s cubic-bezier(.22,1,.36,1)}
.tm-col.today .tm-bar{background:linear-gradient(180deg,#fff,rgba(var(--fg),.4))}
.tm-col.sel .tm-bar{box-shadow:0 0 0 2px rgba(var(--fg),.55)}
.tm-avgline{position:absolute;left:0;right:0;height:0;border-top:1.5px dashed #fbbf24;pointer-events:none}
.tm-avgline b{position:absolute;right:0;bottom:3px;padding:1px 6px;border-radius:4px;background:var(--sheet,rgba(7,9,36,.92));font-size:.54rem;font-weight:700;color:#fbbf24;white-space:nowrap}
.tm-axis{display:flex;gap:4px;margin:7px 0 0 38px}
.tm-axis span{flex:1;min-width:0;text-align:center;font-size:.56rem;color:var(--muted);white-space:nowrap}
.tm-read{margin-top:12px;text-align:center;font-size:.7rem;font-weight:600;color:var(--sub);min-height:18px}
.tm-read b{color:var(--ui-text,#fff)}

/* birthdays */
.bd-row{display:flex;align-items:center;gap:12px;padding:10px 12px;margin-bottom:8px;border:1px solid var(--line);border-radius:var(--r);background:var(--surface);transition:background .2s,border-color .2s}
.bd-row:hover{background:var(--surface-2);border-color:var(--accent-border)}
.bd-row img{width:38px;height:38px;border-radius:50%;object-fit:cover;flex-shrink:0;border:2px solid var(--accent);background:var(--ph,#15171f)}
.bd-info{flex:1;min-width:0}
.bd-info b{display:block;font-size:.76rem;font-weight:700;color:var(--ui-text,#fff);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.bd-info span{display:block;font-size:.62rem;color:var(--muted);margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.bd-when{flex-shrink:0;text-align:right}
.bd-when b{display:block;font-size:.72rem;font-weight:700;color:var(--ui-text,#fff)}
.bd-when span{display:inline-block;margin-top:2px;padding:1px 8px;border-radius:99px;font-size:.56rem;font-weight:700;background:rgba(var(--fg),.08);color:var(--sub)}
.bd-when span.soon{background:rgba(251,191,36,.14);color:#fbbf24}
.bd-when span.today{background:var(--accent);color:#000}
.bd-row.is-today{border-color:var(--accent);background:var(--accent-soft)}
.bd-group{margin-bottom:6px}

/* ══ NEW: PRIVACY CHECK-UP, TIME ZONE, MUTED PODS ══ */
#modal-checkup,#modal-timezone,#modal-mutedpods,#modal-archive{background:radial-gradient(900px 320px at 50% -90px,var(--glow,rgba(255,45,45,.17)),transparent 70%),var(--bg-lin,linear-gradient(-45deg,#010218,#070924,#070924))}
#modal-checkup .ac-topbar,#modal-timezone .ac-topbar,#modal-mutedpods .ac-topbar,#modal-archive .ac-topbar{background:transparent}
#modal-checkup{--glow:rgba(242,31,31,.2);--accent:#f21f1f;--accent-hover:#d91212;--accent-soft:rgba(242,31,31,.15);--accent-border:rgba(242,31,31,.45);--accent-fill:rgba(242,31,31,.22);--accent-text:#ff8080;--accent-focus:rgba(242,31,31,.7);--accent-ring:rgba(242,31,31,.12)}
#modal-timezone{--glow:rgba(255,77,77,.2);--accent:#ff4d4d;--accent-hover:#ff3333;--accent-soft:rgba(255,77,77,.15);--accent-border:rgba(255,77,77,.45);--accent-fill:rgba(255,77,77,.22);--accent-text:#ff9a9a;--accent-focus:rgba(255,77,77,.7);--accent-ring:rgba(255,77,77,.12)}
#modal-mutedpods{--glow:rgba(214,34,34,.2);--accent:#d92222;--accent-hover:#c01616;--accent-soft:rgba(217,34,34,.16);--accent-border:rgba(217,34,34,.48);--accent-fill:rgba(217,34,34,.24);--accent-text:#ff7a7a;--accent-focus:rgba(217,34,34,.7);--accent-ring:rgba(217,34,34,.12)}

#modal-appearance{background:radial-gradient(900px 320px at 50% -90px,var(--glow,rgba(255,45,45,.17)),transparent 70%),var(--bg-lin,linear-gradient(-45deg,#010218,#070924,#070924));--glow:rgba(255,77,77,.2)}
#modal-appearance .ac-topbar{background:transparent}
.pkbm-theme-row{display:flex;align-items:center;gap:12px;width:100%;padding:14px;background:var(--surface);border:1px solid var(--line);border-radius:var(--r);color:inherit;font:inherit;text-align:left;cursor:pointer;transition:background .15s}
.pkbm-theme-row:hover{background:rgba(var(--fg),.05)}
.pkbm-theme-row .pt-ic{width:38px;height:38px;border-radius:50%;display:grid;place-items:center;flex-shrink:0;background:rgba(var(--fg),.08);font-size:1.15rem}
.pkbm-theme-row .pt-tx{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px}
.pkbm-theme-row .pt-tx b{font-size:.92rem;font-weight:600}
.pkbm-theme-row .pt-tx span{font-size:.74rem;color:var(--muted)}
.pkbm-theme-row .pt-sw{position:relative;width:46px;height:26px;border-radius:34px;background:#ff4d4d;flex-shrink:0;transition:background .25s}
.pkbm-theme-row .pt-sw::before{content:"";position:absolute;top:3px;left:23px;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.35);transition:left .25s}
.pkbm-theme-row[aria-checked="false"] .pt-sw{background:rgba(var(--fg),.38)}
.pkbm-theme-row[aria-checked="false"] .pt-sw::before{left:3px}
.pkbm-theme-btns{display:flex;gap:10px;margin-top:12px}
.pkbm-theme-btns .hle-btn{flex:1;display:inline-flex;align-items:center;justify-content:center}
.pkbm-theme-btns .hle-btn.on{background:#ff4d4d;border-color:#ff4d4d;color:#fff}
#modal-datadl,#modal-postdef,#modal-storeddata{background:radial-gradient(900px 320px at 50% -90px,var(--glow,rgba(255,45,45,.17)),transparent 70%),var(--bg-lin,linear-gradient(-45deg,#010218,#070924,#070924));--glow:rgba(255,77,77,.2);--accent:#ff4d4d;--accent-hover:#ff3333;--accent-soft:rgba(255,77,77,.15);--accent-border:rgba(255,77,77,.45);--accent-text:#ff9a9a}
#modal-datadl .ac-topbar,#modal-postdef .ac-topbar,#modal-storeddata .ac-topbar{background:transparent}
.dx-inc{list-style:none;margin:14px 0 0;padding:0;display:grid;grid-template-columns:1fr 1fr;gap:8px}
.dx-inc li{display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid var(--line);border-radius:var(--r);background:var(--surface);font-size:.8rem}
.dx-inc i{color:var(--accent);font-size:1.05rem}
.dx-state{margin-top:14px;padding:14px;border:1px solid var(--line);border-radius:var(--r);background:var(--surface);display:flex;flex-direction:column;gap:6px}
.dx-state b{font-size:.92rem}
.dx-state span{font-size:.78rem;color:var(--muted);line-height:1.45}
.dx-bar{height:4px;border-radius:4px;background:rgba(var(--fg),.12);overflow:hidden;margin-top:6px}
.dx-bar::after{content:"";display:block;height:100%;width:35%;border-radius:4px;background:var(--accent);animation:dxslide 1.1s ease-in-out infinite}
@keyframes dxslide{0%{margin-left:-35%}100%{margin-left:100%}}
.dx-btn{margin-top:14px}
.mp-pic{position:relative;width:50px;height:50px;border-radius:7px;overflow:hidden;flex-shrink:0;background:var(--ph,#15171f);border:2px solid #24428a;display:grid;place-items:center;color:var(--accent-text);font-size:1.1rem}
#mp-list .mod-user-row .mp-pic{width:54px;height:54px;border-radius:8px;border:2px solid #24428a}
#mp-list .mod-user-row .mp-pic img{position:absolute;inset:0;width:100%;height:100%;border-radius:0;border:0;cursor:default;object-fit:cover}
.mp-pic i{display:none}
.mp-pic.nopic i,.mp-pic:not(:has(img)) i{display:block}
#modal-wordfilters{background:radial-gradient(900px 320px at 50% -90px,var(--glow,rgba(255,45,45,.17)),transparent 70%),var(--bg-lin,linear-gradient(-45deg,#010218,#070924,#070924));--glow:rgba(255,77,77,.2);--accent:#ff4d4d;--accent-hover:#ff3333;--accent-soft:rgba(255,77,77,.15);--accent-border:rgba(255,77,77,.45);--accent-text:#ff9a9a}
#modal-wordfilters .ac-topbar{background:transparent}
:root[data-theme="light"] #modal-wordfilters{--accent-text:#d42525}
.wf-tabs{display:flex;gap:4px;padding:4px;border:1px solid var(--line);border-radius:var(--r);background:var(--surface);margin-bottom:14px}
.wf-tab{flex:1;height:36px;border:none;border-radius:calc(var(--r) - 2px);background:transparent;color:var(--sub);font-size:.8rem;font-weight:600;transition:.2s;display:inline-flex;align-items:center;justify-content:center;gap:6px}
.wf-tab b{font-size:.68rem;font-weight:700;padding:1px 7px;border-radius:99px;background:rgba(var(--fg),.1)}
.wf-tab.on{background:var(--accent);color:#fff}
.wf-tab.on b{background:rgba(255,255,255,.25)}
.wf-panel{display:none}
.wf-panel.on{display:block}
.wf-add{display:flex;gap:8px}
.wf-add .f-input{flex:1;min-width:0;width:auto}
.wf-add .hle-btn{flex-shrink:0}
.wf-hint{font-size:.72rem;color:var(--muted);line-height:1.6;margin:8px 2px 0}
.wf-meta{display:flex;align-items:center;justify-content:space-between;margin:16px 2px 8px;font-size:.72rem;color:var(--muted)}
.wf-meta button{background:none;border:none;color:var(--accent-text);font-size:.72rem;font-weight:700;padding:2px 4px}
.wf-chips{display:flex;flex-wrap:wrap;gap:7px}
.wf-chip{display:inline-flex;align-items:center;gap:6px;max-width:100%;padding:6px 6px 6px 12px;border:1px solid var(--line);border-radius:99px;background:var(--surface);font-size:.8rem;color:var(--ui-text,#fff);animation:wfIn .2s ease}
.wf-chip span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.wf-chip button{width:22px;height:22px;border-radius:50%;border:none;background:rgba(var(--fg),.1);color:inherit;display:grid;place-items:center;font-size:.9rem;flex-shrink:0}
.wf-chip button:hover{background:var(--accent);color:#fff}
@keyframes wfIn{from{opacity:0;transform:scale(.92)}to{opacity:1;transform:none}}
.wf-empty{font-size:.8rem;color:var(--muted);padding:18px 4px;text-align:center}
/* privacy check-up */
.ck-list{border:1px solid var(--line);border-radius:var(--r);background:var(--surface);overflow:hidden}
.ck-row{display:flex;align-items:center;gap:11px;width:100%;padding:12px;background:none;border:none;border-top:1px solid var(--line);color:inherit;font:inherit;text-align:left;transition:background .15s}
.ck-row:first-child{border-top:none}
.ck-row:hover{background:rgba(var(--fg),.05)}
.ck-row:hover .menu-chevron{color:var(--ui-text,#fff);transform:translateX(2px)}
.ck-t{flex:1;min-width:0}
.ck-t strong{display:block;font-size:.72rem;font-weight:600;color:var(--ui-text,#fff)}
.ck-v{display:block;font-size:.64rem;font-weight:600;margin-top:2px;line-height:1.45}
.ck-v.open{color:var(--ok)}.ck-v.limited{color:#fbbf24}.ck-v.closed{color:var(--danger)}.ck-v.info{color:var(--sub)}
.ck-last{margin-top:14px;text-align:center;font-size:.66rem;color:var(--muted)}

/* time zone */
.tz-list{border:1px solid var(--line);border-radius:var(--r);background:var(--surface);max-height:340px;overflow-y:auto;margin-top:12px;scrollbar-width:thin;scrollbar-color:var(--scroll-thumb) transparent}
.tz-row{display:flex;align-items:center;gap:10px;width:100%;padding:11px 12px;background:none;border:none;border-top:1px solid var(--line);color:var(--ui-text,#fff);font:inherit;text-align:left}
.tz-row:first-child{border-top:none}
.tz-row:hover{background:rgba(var(--fg),.05)}
.tz-n{flex:1;min-width:0;font-size:.72rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tz-o{font-size:.62rem;color:var(--muted);flex-shrink:0}
.tz-radio{width:18px;height:18px;border-radius:50%;border:2px solid rgba(var(--fg),.3);flex-shrink:0;display:flex;align-items:center;justify-content:center;transition:.2s}
.tz-row.on{background:var(--accent-soft)}
.tz-row.on .tz-radio{border-color:var(--accent);background:var(--accent)}
.tz-row.on .tz-radio::after{content:'';width:6px;height:6px;border-radius:50%;background:#000}
.tz-btns{display:flex;flex-wrap:wrap;gap:6px;margin-top:12px}
.tz-btns .hle-btn{margin-top:0}

#modal-archive{--glow:rgba(255,92,92,.2);--accent:#ff5c5c;--accent-hover:#ff4040;--accent-soft:rgba(255,92,92,.15);--accent-border:rgba(255,92,92,.45);--accent-fill:rgba(255,92,92,.22);--accent-text:#ffa3a3;--accent-focus:rgba(255,92,92,.7);--accent-ring:rgba(255,92,92,.12)}

/* archive */
.ar-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px 10px;margin-top:14px}
.ar-item{min-width:0;transition:opacity .25s,transform .25s}
.ar-item.removing{opacity:0;transform:scale(.94)}
.ar-item .hle-btn{margin-top:6px;width:100%;justify-content:center}
.ar-more{margin-top:14px;display:none}
@media (max-width:330px){.ar-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}

/* ══ RESPONSIVE VIEW (790px and below): everything 10% bigger ══
   html font-size 110% makes every rem size (text, many paddings) 10% larger.
   The px sizes below (icons, avatars, switches, bars) are raised by hand to match. */
@media (max-width:790px){
  html{font-size:110%}
  body{font-size:13.2px}
  :root{--topbar-h:59px;--pad:clamp(12px,3.74vw,17.6px);--ctl-h:clamp(35px,9.24vw,42px);--act-h:clamp(29px,8.14vw,33px)}
  .nav-btn{font-size:11px}
  .nav-btn .btn-label{font-size:11px}
  .nav-btn i{font-size:17.6px}
  .menu-icon{width:35px;height:35px}
  .ac-x{width:37px;height:37px}
  .ac-x svg{width:24px;height:24px}
  .switch{width:46px;height:26px}
  .slider::before{height:15.4px;width:15.4px;left:5.5px;bottom:5.3px}
  input:checked+.slider::before{transform:translateX(19.6px)}
  .av-cur{width:75px;height:75px}
  .av-stage,.av-zoom,.av-actions{max-width:374px}
  .vf-check .ic,.vf-how li::before{width:22px;height:22px}
  .st-ring{width:68px;height:68px}
  .st-row .ic{width:33px;height:33px}
  .pv-hero-ic{width:42px;height:42px}
  .pv-ic{width:35px;height:35px}
  .pv-ic.sm{width:33px;height:33px}
  .pv-radio{width:22px;height:22px}
  .pv-opt.tile .pv-ic{width:44px;height:44px}
  .ct-ic{width:35px;height:35px}
  .hl-row-th{width:42px;height:42px}
  .hl-del,.hl-x{width:26px;height:26px}
  .hl-vid,.hl-song{width:22px;height:22px;font-size:12px}
  .hle-cover .hl-sq{width:84px}
  .hle-chip{height:calc(24.2px * .94)}
  .lm-chips button{height:24px}
  .hl-cap{font-size:16.5px}
  .hl-tilecap{font-size:11px}
  .hl-more{font-size:12px}
  .track-req-row img{width:44px;height:44px}
  .mod-user-row img{width:37px;height:37px}
}
@media (max-width:490px){
  :root{--topbar-h:53px}
  .menu-icon{width:33px;height:33px}
  .ac-x{width:33px;height:33px}
  .pv-hero-ic{width:42px;height:42px}
  .pv-opt.tile .pv-ic{width:40px;height:40px}
}
@media (max-width:390px){
  .pv-ic{width:33px;height:33px}
}
@media (max-width:330px){
  .track-req-row img{width:33px;height:33px}
}
@media (prefers-reduced-motion:reduce){*{transition-duration:.01ms !important;animation-duration:.01ms !important}}

/* ═══════════ LIGHT MODE — <html data-theme="light"> (dark mode is never touched; text over photos keeps its dark scrim + white text) ═══════════ */
:root[data-theme="light"]{
  color-scheme:light;
  --fg:6,7,26;
  --ui-text:#0b0d24;
  --text:#0b0d24;
  --solid:#ffffff; --sheet:rgba(255,255,255,.97); --sheet-solid:#ffffff; --sidebar-bg:rgba(255,255,255,.92); --ph:#eceef5;
  --surface:rgba(6,7,26,.05); --surface-2:rgba(6,7,26,.08);
  --border:rgba(6,7,26,.2); --line:rgba(6,7,26,.1);
  --muted:rgba(6,7,26,.52); --muted-2:rgba(6,7,26,.38); --sub:rgba(6,7,26,.66);
  --scroll-thumb:rgba(6,7,26,.28);
  --accent-text:#d42525;
  --danger:#d92b2b;
  --bg:linear-gradient(-45deg,#ffffff,#f4f5fb,#f4f5fb);
  --bg-lin:linear-gradient(-45deg,#ffffff,#f4f5fb,#f4f5fb);
}
[data-theme="light"] body{ color:#0b0d24; }
</style>
</head>
<body>

<!-- SKELETON LOADER -->
<div id="page-skeleton" aria-hidden="true">
  <div class="sk-sidebar"><div class="sk-bar" style="height:32px;width:120px;margin-bottom:16px"></div><div class="sk-bar" style="height:34px"></div><div class="sk-bar" style="height:34px"></div><div class="sk-bar" style="height:34px"></div><div class="sk-bar" style="height:34px"></div></div>
  <div class="sk-wrap"><div class="sk-lane">
    <div class="sk-topbar"><div class="sk-bar" style="height:14px;width:90px"></div></div>
    <div style="display:flex;gap:8px;padding:14px var(--pad)"><div class="sk-bar" style="flex:1;height:52px"></div><div class="sk-bar" style="flex:1;height:52px"></div></div>
    <?php for ($i = 0; $i < 7; $i++): ?>
    <div class="sk-row"><div class="sk-bar" style="width:32px;height:32px"></div><div style="flex:1;display:flex;flex-direction:column;gap:7px"><div class="sk-bar" style="width:45%;height:11px"></div><div class="sk-bar" style="width:75%;height:8px"></div></div></div>
    <?php endfor; ?>
  </div></div>
</div>

<!-- SIDEBAR (unchanged) -->
<div class="sidebar">
  <a href="index.php" class="logo"><img src="dezzme.jpg" alt="Dezzme">DEZZME</a>
  <a href="profile.php" class="nav-btn"><i class="ri-arrow-left-s-line"></i><span class="btn-label">Profile</span></a>
  <a href="untracked.php" class="nav-btn"><i class="ri-user-unfollow-line"></i><span class="btn-label">Untracked</span></a>
  <a href="register.php" class="nav-btn"><i class="ri-user-add-line"></i><span class="btn-label">New Account</span></a>
  <a href="logout.php" class="nav-btn"><i class="ri-shut-down-line"></i><span class="btn-label">Log Out</span></a>
</div>

<div class="mp-shell">

<!-- ═════════ MAIN LANE ═════════ -->
<div class="ac-lane">

  <div class="ac-topbar plain">
    <div class="ac-titles">
      <div class="ac-title left">Settings</div>
      <div class="ac-sub">Manage your account, privacy, and preferences</div>
    </div>
  </div>

  <div class="stat-row">
    <div class="stat-pill"><span class="stat-pill-num"><?= (int)$followers_count ?></span><span class="stat-pill-label">Trackers</span></div>
    <div class="stat-pill"><span class="stat-pill-num"><?= (int)$following_count ?></span><span class="stat-pill-label">Tracking</span></div>
  </div>

  <?php if ($del_pending): ?>
  <div class="dg-banner" id="dg-banner">
    <i class="ri-error-warning-line"></i>
    <span>Your account is scheduled to be deleted on <b><?= htmlspecialchars($del_date) ?></b>. Changed your mind?</span>
    <button type="button" class="hle-btn accent" onclick="cancelDelete(this)">Cancel deletion</button>
  </div>
  <?php endif; ?>

  <!-- ACCOUNT -->
  <div class="settings-section">
    <div class="section-header">Account</div>

    <div class="menu-row" onclick="openModal('modal-account')">
      <div class="menu-icon"><i class="ri-user-line"></i></div>
      <div class="menu-text"><span class="menu-label">Dezz Account Center</span><span class="menu-desc">Username, nickname, email, bio, pronouns and picture</span></div>
      <div class="menu-right"><i class="ri-arrow-right-s-line menu-chevron"></i></div>
    </div>

    <div class="menu-row" onclick="openModal('modal-profiletype')">
      <div class="menu-icon"><i class="ri-price-tag-3-line"></i></div>
      <div class="menu-text"><span class="menu-label">Professional Mode Type</span><span class="menu-desc">Show what you do or what your organisation is — creator, developer, brand, school and more</span></div>
      <div class="menu-right">
        <span class="menu-value" id="menu-profile-type"><?= htmlspecialchars($settings['profile_type']) ?></span>
        <i class="ri-arrow-right-s-line menu-chevron"></i>
      </div>
    </div>

    <div class="menu-row" onclick="openModal('modal-password')">
      <div class="menu-icon"><i class="ri-lock-line"></i></div>
      <div class="menu-text"><span class="menu-label">Password &amp; Security</span><span class="menu-desc">Change your password and keep your account secure</span></div>
      <div class="menu-right"><i class="ri-arrow-right-s-line menu-chevron"></i></div>
    </div>

    <div class="menu-row" onclick="openModal('modal-vibe')">
      <div class="menu-icon"><i class="ri-time-line"></i></div>
      <div class="menu-text"><span class="menu-label">Activity Vibe</span><span class="menu-desc">Let trackers know when you're usually around</span></div>
      <div class="menu-right"><i class="ri-arrow-right-s-line menu-chevron"></i></div>
    </div>

    <div class="menu-row" onclick="openModal('modal-status')">
      <div class="menu-icon"><i class="ri-user-settings-line"></i></div>
      <div class="menu-text"><span class="menu-label">Profile status update</span><span class="menu-desc">See how your profile is doing and what to improve</span></div>
      <div class="menu-right"><i class="ri-arrow-right-s-line menu-chevron"></i></div>
    </div>

    <a href="Account_status.php" class="menu-row">
      <div class="menu-icon"><i class="ri-shield-check-line"></i></div>
      <div class="menu-text"><span class="menu-label">Account status</span><span class="menu-desc">See if your account is in good standing, limited or suspended</span></div>
      <div class="menu-right"><i class="ri-arrow-right-s-line menu-chevron"></i></div>
    </a>

    <div class="menu-row" onclick="openModal('modal-notifs')">
      <div class="menu-icon"><i class="ri-notification-off-line"></i></div>
      <div class="menu-text"><span class="menu-label">Turn off notifications</span><span class="menu-desc">Switch all Dezzme notifications on or off</span></div>
      <div class="menu-right"><span class="menu-value" id="menu-notif-state"><?= $notif_on ? '' : 'Off' ?></span><i class="ri-arrow-right-s-line menu-chevron"></i></div>
    </div>

    <div class="menu-row" onclick="openModal('modal-timezone')">
      <div class="menu-icon"><i class="ri-earth-line"></i></div>
      <div class="menu-text"><span class="menu-label">Time zone</span><span class="menu-desc">Make "today" match your real day</span></div>
      <div class="menu-right"><span class="menu-value" id="menu-tz"><?= htmlspecialchars($tz_short) ?></span><i class="ri-arrow-right-s-line menu-chevron"></i></div>
    </div>
  </div>

  <!-- APPEARANCE -->
  <div class="settings-section">
    <div class="section-header">Appearance</div>
    <div class="menu-row" onclick="openModal('modal-appearance')">
      <div class="menu-icon"><i class="ri-contrast-2-line"></i></div>
      <div class="menu-text"><span class="menu-label">Appearance</span><span class="menu-desc">Dark mode and display.</span></div>
      <div class="menu-right"><i class="ri-arrow-right-s-line menu-chevron"></i></div>
    </div>
  </div>

  <!-- PRIVACY -->
  <div class="settings-section">
    <div class="section-header">Privacy</div>

    <div class="menu-row" onclick="openModal('modal-checkup')">
      <div class="menu-icon"><i class="ri-shield-check-line"></i></div>
      <div class="menu-text"><span class="menu-label">Privacy check-up</span><span class="menu-desc">Review who can see and reach you in one place</span></div>
      <div class="menu-right"><i class="ri-arrow-right-s-line menu-chevron"></i></div>
    </div>

    <div class="menu-row" onclick="openModal('modal-privacy')">
      <div class="menu-icon"><i class="ri-eye-off-line"></i></div>
      <div class="menu-text"><span class="menu-label">Privacy Controls</span><span class="menu-desc">Choose a level: Public, Limited, Private or Full Private</span></div>
      <div class="menu-right"><span class="menu-value" id="menu-priv-level"><?= htmlspecialchars($priv_labels[$priv_level]) ?></span><i class="ri-arrow-right-s-line menu-chevron"></i></div>
    </div>

    <div class="menu-row" onclick="openModal('modal-tags')">
      <div class="menu-icon"><i class="ri-at-line"></i></div>
      <div class="menu-text"><span class="menu-label">Tags and mentions</span><span class="menu-desc">Choose who can tag you and @mention you</span></div>
      <div class="menu-right"><i class="ri-arrow-right-s-line menu-chevron"></i></div>
    </div>

    <div class="menu-row" onclick="openModal('modal-lists')">
      <div class="menu-icon"><i class="ri-team-line"></i></div>
      <div class="menu-text"><span class="menu-label">Hide Trackers, Tracking &amp; Pods lists</span><span class="menu-desc">Don't let people see who you track, who tracks you or your pods</span></div>
      <div class="menu-right"><i class="ri-arrow-right-s-line menu-chevron"></i></div>
    </div>

    <div class="menu-row" id="row-trackreqs" onclick="openModal('modal-trackreqs')"<?= $side_is_private ? '' : ' style="display:none"' ?>>
      <div class="menu-icon"><i class="ri-user-received-line"></i></div>
      <div class="menu-text"><span class="menu-label">Track Requests</span><span class="menu-desc">Approve or deny requests to view your profile</span></div>
      <div class="menu-right">
        <?php if (count($pending_requests) > 0): ?><span class="menu-badge"><?= count($pending_requests) ?></span><?php endif; ?>
        <i class="ri-arrow-right-s-line menu-chevron"></i>
      </div>
    </div>

    <div class="menu-row" onclick="openModal('modal-blocked')">
      <div class="menu-icon"><i class="ri-shield-user-line"></i></div>
      <div class="menu-text"><span class="menu-label">Moderation</span><span class="menu-desc">Blocked, muted &amp; restricted accounts</span></div>
      <div class="menu-right"><i class="ri-arrow-right-s-line menu-chevron"></i></div>
    </div>

    <div class="menu-row" onclick="openModal('modal-mutedpods')">
      <div class="menu-icon"><i class="ri-volume-mute-line"></i></div>
      <div class="menu-text"><span class="menu-label">Muted Pods</span><span class="menu-desc">Pods whose notifications you turned off</span></div>
      <div class="menu-right"><span class="menu-value"><?= count($mp_list) > 0 ? (int)count($mp_list) : '' ?></span><i class="ri-arrow-right-s-line menu-chevron"></i></div>
    </div>
  </div>

  <!-- ACTIVITY -->
  <div class="settings-section">
    <div class="section-header">Activity</div>
    <a href="profile_dashboard.php" class="menu-row"><div class="menu-icon"><i class="ri-dashboard-line"></i></div><div class="menu-text"><span class="menu-label">Your Dashboard</span><span class="menu-desc">Your profile stats and tools in one place</span></div><div class="menu-right"><i class="ri-arrow-right-s-line menu-chevron"></i></div></a>
    <a href="history.php" class="menu-row"><div class="menu-icon"><i class="ri-history-line"></i></div><div class="menu-text"><span class="menu-label">History</span><span class="menu-desc">Review your recent activity on Dezzme</span></div><div class="menu-right"><i class="ri-arrow-right-s-line menu-chevron"></i></div></a>
    <a href="analytics.php" class="menu-row"><div class="menu-icon"><i class="ri-pie-chart-line"></i></div><div class="menu-text"><span class="menu-label">Analytics</span><span class="menu-desc">See how your memes and profile are performing</span></div><div class="menu-right"><i class="ri-arrow-right-s-line menu-chevron"></i></div></a>
    <div class="menu-row" onclick="openModal('modal-time')">
      <div class="menu-icon"><i class="ri-time-line"></i></div>
      <div class="menu-text"><span class="menu-label">Time management</span><span class="menu-desc">See your average daily time on Dezzme</span></div>
      <div class="menu-right"><span class="menu-value"><?= htmlspecialchars(tm_fmt($tm_today)) ?></span><i class="ri-arrow-right-s-line menu-chevron"></i></div>
    </div>
    <div class="menu-row" onclick="openModal('modal-birthdays')">
      <div class="menu-icon"><i class="ri-cake-2-line"></i></div>
      <div class="menu-text"><span class="menu-label">Birthdays</span><span class="menu-desc">Birthdays of the people you track</span></div>
      <div class="menu-right"><span class="menu-value"><?= $bd_today_n > 0 ? (int)$bd_today_n . ' today' : '' ?></span><i class="ri-arrow-right-s-line menu-chevron"></i></div>
    </div>
    <a href="peeps.php?user_id=<?= (int)$profile_id ?>" class="menu-row"><div class="menu-icon"><i class="ri-eye-line"></i></div><div class="menu-text"><span class="menu-label">Profile Views</span><span class="menu-desc">See who has been looking at your profile</span></div><div class="menu-right"><i class="ri-arrow-right-s-line menu-chevron"></i></div></a>
    <a href="family.php" class="menu-row"><div class="menu-icon"><i class="ri-parent-line"></i></div><div class="menu-text"><span class="menu-label">Family Centre</span><span class="menu-desc">Supervision and family safety tools</span></div><div class="menu-right"><i class="ri-arrow-right-s-line menu-chevron"></i></div></a>
  </div>

  <!-- CREATE -->
  <div class="settings-section">
    <div class="section-header">Create</div>
    <div class="menu-row" onclick="openModal('modal-creator')">
      <div class="menu-icon"><i class="ri-palette-line"></i></div>
      <div class="menu-text"><span class="menu-label">Dezzme Creator Tools</span><span class="menu-desc">Meme makers, editors and more to create memes for Dezzme</span></div>
      <div class="menu-right"><i class="ri-arrow-right-s-line menu-chevron"></i></div>
    </div>
    <div class="menu-row" onclick="openModal('modal-archive')">
      <div class="menu-icon"><i class="ri-archive-line"></i></div>
      <div class="menu-text"><span class="menu-label">Archive</span><span class="menu-desc">Hide posts from your profile without deleting them</span></div>
      <div class="menu-right"><span class="menu-value" id="menu-ar"><?= $ar_arch > 0 ? (int)$ar_arch : '' ?></span><i class="ri-arrow-right-s-line menu-chevron"></i></div>
    </div>

  </div>

  <!-- MEMBERSHIP -->
  <div class="settings-section">
    <div class="section-header">Membership</div>
    <a class="menu-row" href="profile_verified.php">
      <div class="menu-icon"><?= st_badge_icon() ?></div>
      <div class="menu-text"><span class="menu-label">Dezzme Verified</span><span class="menu-desc">Apply for the Dezzme verified badge</span></div>
      <div class="menu-right"><i class="ri-arrow-right-s-line menu-chevron"></i></div>
    </a>
    <a href="Ad_payment.php" class="menu-row">
      <div class="menu-icon"><i class="ri-bank-card-line"></i></div>
      <div class="menu-text"><span class="menu-label">Ad Payment</span><span class="menu-desc">Pay for your ads and manage your ad billing</span></div>
      <div class="menu-right"><i class="ri-arrow-right-s-line menu-chevron"></i></div>
    </a>
    <div class="menu-row" onclick="openModal('modal-highlights')">
      <div class="menu-icon"><i class="ri-trophy-line"></i></div>
      <div class="menu-text"><span class="menu-label">Highlights</span><span class="menu-desc">Create and manage the highlights on your profile</span></div>
      <div class="menu-right"><i class="ri-arrow-right-s-line menu-chevron"></i></div>
    </div>
  </div>

  <!-- POSTING -->
  <div class="settings-section">
    <div class="section-header">Posting</div>
    <div class="menu-row" onclick="openModal('modal-postdef')">
      <div class="menu-icon"><i class="ri-edit-box-line"></i></div>
      <div class="menu-text"><span class="menu-label">Default post settings</span><span class="menu-desc">Hide counts, AI label and downloads, set once for every new post.</span></div>
      <div class="menu-right"><i class="ri-arrow-right-s-line menu-chevron"></i></div>
    </div>
  </div>

  <!-- FEED -->
  <div class="settings-section">
    <div class="section-header">Feed</div>
    <div class="menu-row" onclick="openModal('modal-wordfilters')">
      <div class="menu-icon"><i class="ri-filter-3-line"></i></div>
      <div class="menu-text"><span class="menu-label">Word filters</span><span class="menu-desc">Hide posts and ads that contain words you choose.</span></div>
      <div class="menu-right"><span class="menu-value" id="wf-menu-count"><?= $wf_total > 0 ? (int)$wf_total : '' ?></span><i class="ri-arrow-right-s-line menu-chevron"></i></div>
    </div>
  </div>

  <!-- ADS -->
  <div class="settings-section">
    <div class="section-header">Ads</div>
    <a class="menu-row" href="Ads_setting.php">
      <div class="menu-icon"><i class="ri-advertisement-line"></i></div>
      <div class="menu-text"><span class="menu-label">Ads Management</span><span class="menu-desc">Manage your ads and ad settings.</span></div>
      <div class="menu-right"><i class="ri-arrow-right-s-line menu-chevron"></i></div>
    </a>
  </div>

  <!-- YOUR DATA -->
  <div class="settings-section">
    <div class="section-header">Your data</div>
    <div class="menu-row" onclick="openModal('modal-datadl')">
      <div class="menu-icon"><i class="ri-download-cloud-2-line"></i></div>
      <div class="menu-text"><span class="menu-label">Download your data</span><span class="menu-desc">Get a copy of your posts, media and profile.</span></div>
      <div class="menu-right"><i class="ri-arrow-right-s-line menu-chevron"></i></div>
    </div>
    <div class="menu-row" onclick="openModal('modal-storeddata')">
      <div class="menu-icon"><i class="ri-database-2-line"></i></div>
      <div class="menu-text"><span class="menu-label">What we store about you</span><span class="menu-desc">See the kinds of information Dezzme keeps, and how much.</span></div>
      <div class="menu-right"><i class="ri-arrow-right-s-line menu-chevron"></i></div>
    </div>
  </div>

  <!-- SUPPORT -->
  <div class="settings-section">
    <div class="section-header">Support</div>
    <a href="help.php" class="menu-row"><div class="menu-icon"><i class="ri-question-line"></i></div><div class="menu-text"><span class="menu-label">Help</span><span class="menu-desc">Guides, answers and ways to contact support</span></div><div class="menu-right"><i class="ri-arrow-right-s-line menu-chevron"></i></div></a>
    <a href="feedback.php" class="menu-row"><div class="menu-icon"><i class="ri-feedback-line"></i></div><div class="menu-text"><span class="menu-label">Send Feedback</span><span class="menu-desc">Tell us what to fix or improve</span></div><div class="menu-right"><i class="ri-arrow-right-s-line menu-chevron"></i></div></a>
    <a href="account.php" class="menu-row"><div class="menu-icon"><i class="ri-information-line"></i></div><div class="menu-text"><span class="menu-label">About</span><span class="menu-desc">Your account details, join date and how your account works</span></div><div class="menu-right"><i class="ri-arrow-right-s-line menu-chevron"></i></div></a>
  </div>

  <!-- DEACTIVATE / DELETE + LOG OUT -->
  <div class="settings-section logout-wrap">
    <div class="menu-row menu-row-danger" onclick="openModal('modal-danger')">
      <div class="menu-icon"><i class="ri-delete-bin-line"></i></div>
      <div class="menu-text"><span class="menu-label">Deactivate or delete account</span><span class="menu-desc">Hide your account for now, or remove it for good</span></div>
      <div class="menu-right"><i class="ri-arrow-right-s-line menu-chevron"></i></div>
    </div>
    <a href="logout.php" class="menu-row menu-row-danger"><div class="menu-icon"><i class="ri-shut-down-line"></i></div><div class="menu-text"><span class="menu-label">Log Out</span><span class="menu-desc">Sign out of Dezzme on this device</span></div><div class="menu-right"><i class="ri-arrow-right-s-line menu-chevron"></i></div></a>
  </div>

</div><!-- /.ac-lane -->

<!-- ═════════ RIGHT COLUMN (laptop view): everything to know about Settings ═════════ -->
<aside class="mp-side" aria-label="About Settings">
  <div class="gs-cards">

    <div class="gs-card" data-k="about">
      <button type="button" class="gs-bar" onclick="gsToggle(this)" aria-expanded="true"><i class="ri-settings-3-line gs-bar-ic"></i><span class="gs-bar-t">About Settings</span><i class="ri-arrow-down-s-line gs-bar-chev"></i></button>
      <div class="gs-body"><div class="gs-inner"><div class="gs-pad">
        <p class="gs-p"><b>Settings</b> is where you control how your Dezzme account looks, who can see it and how it is kept safe. Everything is grouped into <b>Account</b>, <b>Privacy</b>, <b>Activity</b>, <b>Create</b>, <b>Membership</b> and <b>Support</b>.</p>
        <div class="gs-callout"><i class="ri-information-line"></i><span>Changes save as you make them. In Dezz Account Center, <b>each field has its own Save button</b>, so you can change one thing without touching the rest.</span></div>
      </div></div></div>
    </div>

    <div class="gs-card" data-k="glance">
      <button type="button" class="gs-bar" onclick="gsToggle(this)" aria-expanded="true"><i class="ri-bar-chart-2-line gs-bar-ic"></i><span class="gs-bar-t">Your account at a glance</span><i class="ri-arrow-down-s-line gs-bar-chev"></i></button>
      <div class="gs-body"><div class="gs-inner"><div class="gs-pad">
        <div class="gs-row"><span class="gs-row-l">Username</span><span class="gs-row-v" id="gsUsername">@<?= htmlspecialchars($user['username'] ?? '') ?></span></div>
        <div class="gs-row"><span class="gs-row-l">Trackers</span><span class="gs-row-v"><?= (int)$followers_count ?></span></div>
        <div class="gs-row"><span class="gs-row-l">Tracking</span><span class="gs-row-v"><?= (int)$following_count ?></span></div>
        <div class="gs-row"><span class="gs-row-l">Professional Mode type</span><span class="gs-row-v" id="gsProfileType"><?= $settings['profile_type'] !== '' ? htmlspecialchars($settings['profile_type']) : 'Not set' ?></span></div>
        <div class="gs-row"><span class="gs-row-l">Account privacy</span><span class="gs-row-v" id="gsPrivacy"><?= htmlspecialchars($priv_labels[$priv_level]) ?></span></div>
        <div class="gs-row"><span class="gs-row-l">Verified badge</span><span class="gs-row-v<?= $side_is_verified ? ' ok' : '' ?>"><?= $side_is_verified ? 'Verified' : 'Not verified' ?></span></div>
        <div class="gs-row"><span class="gs-row-l">Highlights</span><span class="gs-row-v"><?= (int)$side_hl_count ?></span></div>
        <div class="gs-row"><span class="gs-row-l">Blocked, muted &amp; restricted</span><span class="gs-row-v"><?= (int)$side_mod_count ?></span></div>
        <div class="gs-row" id="gsReqRow"<?= $side_is_private ? '' : ' style="display:none"' ?>><span class="gs-row-l">Pending track requests</span><span class="gs-row-v"><?= count($pending_requests) ?></span></div>
      </div></div></div>
    </div>

    <div class="gs-card" data-k="jump">
      <button type="button" class="gs-bar" onclick="gsToggle(this)" aria-expanded="true"><i class="ri-compass-3-line gs-bar-ic"></i><span class="gs-bar-t">Jump to a setting</span><i class="ri-arrow-down-s-line gs-bar-chev"></i></button>
      <div class="gs-body"><div class="gs-inner"><div class="gs-pad">
        <a class="gs-topic" href="profile_dashboard.php"><span class="gs-ti"><i class="ri-dashboard-line"></i></span><span class="gs-tx"><span class="gs-tn"><b>Your Dashboard</b></span><span class="gs-td">Your profile stats and tools in one place.</span></span></a>
        <button type="button" class="gs-topic" onclick="openModal('modal-account')"><span class="gs-ti"><i class="ri-user-line"></i></span><span class="gs-tx"><span class="gs-tn"><b>Dezz Account Center</b></span><span class="gs-td">Username, nickname, email, bio, pronouns and picture.</span></span></button>
        <button type="button" class="gs-topic" onclick="openModal('modal-status')"><span class="gs-ti"><i class="ri-user-settings-line"></i></span><span class="gs-tx"><span class="gs-tn"><b>Profile status update</b></span><span class="gs-td">See how your profile is doing and what to improve.</span></span></button>
        <a class="gs-topic" href="Account_status.php"><span class="gs-ti"><i class="ri-shield-check-line"></i></span><span class="gs-tx"><span class="gs-tn"><b>Account status</b></span><span class="gs-td">Check if your account is in good standing.</span></span></a>
        <button type="button" class="gs-topic" onclick="openModal('modal-creator')"><span class="gs-ti"><i class="ri-palette-line"></i></span><span class="gs-tx"><span class="gs-tn"><b>Dezzme Creator Tools</b></span><span class="gs-td">Free and paid tools to make memes, GIFs, videos and sounds.</span></span></button>
        <button type="button" class="gs-topic" onclick="openModal('modal-profiletype')"><span class="gs-ti"><i class="ri-price-tag-3-line"></i></span><span class="gs-tx"><span class="gs-tn"><b>Professional Mode Type</b></span><span class="gs-td">The short label under your name, like Photographer or Meme Creator.</span></span></button>
        <button type="button" class="gs-topic" onclick="openModal('modal-password')"><span class="gs-ti"><i class="ri-lock-line"></i></span><span class="gs-tx"><span class="gs-tn"><b>Password &amp; Security</b></span><span class="gs-td">Change your password. Use 8 or more characters.</span></span></button>
        <button type="button" class="gs-topic" onclick="openModal('modal-privacy')"><span class="gs-ti"><i class="ri-eye-off-line"></i></span><span class="gs-tx"><span class="gs-tn"><b>Privacy Controls</b></span><span class="gs-td">Pick Public, Limited, Private or Full Private.</span></span></button>
        <button type="button" class="gs-topic" onclick="openModal('modal-tags')"><span class="gs-ti"><i class="ri-at-line"></i></span><span class="gs-tx"><span class="gs-tn"><b>Tags and mentions</b></span><span class="gs-td">Choose who can tag you and @mention you.</span></span></button>
        <button type="button" class="gs-topic" onclick="openModal('modal-lists')"><span class="gs-ti"><i class="ri-team-line"></i></span><span class="gs-tx"><span class="gs-tn"><b>Hide Trackers, Tracking &amp; Pods</b></span><span class="gs-td">Choose who can see your lists.</span></span></button>
        <button type="button" class="gs-topic" onclick="openModal('modal-blocked')"><span class="gs-ti"><i class="ri-shield-user-line"></i></span><span class="gs-tx"><span class="gs-tn"><b>Moderation</b></span><span class="gs-td">Manage blocked, muted and restricted accounts.</span></span></button>
        <button type="button" class="gs-topic" onclick="openModal('modal-highlights')"><span class="gs-ti"><i class="ri-trophy-line"></i></span><span class="gs-tx"><span class="gs-tn"><b>Highlights</b></span><span class="gs-td">Collections of photos, videos and text on your profile.</span></span></button>
        <a class="gs-topic" href="profile_verified.php"><span class="gs-ti"><?= st_badge_icon() ?></span><span class="gs-tx"><span class="gs-tn"><b>Dezzme Verified</b></span><span class="gs-td">Apply for the badge with a free first month.</span></span></a>
        <button type="button" class="gs-topic" onclick="openModal('modal-notifs')"><span class="gs-ti"><i class="ri-notification-off-line"></i></span><span class="gs-tx"><span class="gs-tn"><b>Turn off notifications</b></span><span class="gs-td">Switch all notifications on or off.</span></span></button>
        <button type="button" class="gs-topic" onclick="openModal('modal-time')"><span class="gs-ti"><i class="ri-time-line"></i></span><span class="gs-tx"><span class="gs-tn"><b>Time management</b></span><span class="gs-td">Your average daily time on Dezzme.</span></span></button>
        <button type="button" class="gs-topic" onclick="openModal('modal-birthdays')"><span class="gs-ti"><i class="ri-cake-2-line"></i></span><span class="gs-tx"><span class="gs-tn"><b>Birthdays</b></span><span class="gs-td">Birthdays of the people you track.</span></span></button>
        <button type="button" class="gs-topic" onclick="openModal('modal-checkup')"><span class="gs-ti"><i class="ri-shield-check-line"></i></span><span class="gs-tx"><span class="gs-tn"><b>Privacy check-up</b></span><span class="gs-td">Review who can see and reach you.</span></span></button>
        <button type="button" class="gs-topic" onclick="openModal('modal-timezone')"><span class="gs-ti"><i class="ri-earth-line"></i></span><span class="gs-tx"><span class="gs-tn"><b>Time zone</b></span><span class="gs-td">Make "today" match your real day.</span></span></button>
        <button type="button" class="gs-topic" onclick="openModal('modal-mutedpods')"><span class="gs-ti"><i class="ri-volume-mute-line"></i></span><span class="gs-tx"><span class="gs-tn"><b>Muted Pods</b></span><span class="gs-td">Unmute Pods you silenced.</span></span></button>
        <button type="button" class="gs-topic" onclick="openModal('modal-archive')"><span class="gs-ti"><i class="ri-archive-line"></i></span><span class="gs-tx"><span class="gs-tn"><b>Archive</b></span><span class="gs-td">Hide posts without deleting them.</span></span></button>
        <button type="button" class="gs-topic" onclick="openModal('modal-appearance')"><span class="gs-ti"><i class="ri-contrast-2-line"></i></span><span class="gs-tx"><span class="gs-tn"><b>Appearance</b></span><span class="gs-td">Switch dark mode on or off.</span></span></button>
        <button type="button" class="gs-topic" onclick="openModal('modal-wordfilters')"><span class="gs-ti"><i class="ri-filter-3-line"></i></span><span class="gs-tx"><span class="gs-tn"><b>Word filters</b></span><span class="gs-td">Hide posts and ads with words you choose.</span></span></button>
        <a class="gs-topic" href="Ads_setting.php"><span class="gs-ti"><i class="ri-advertisement-line"></i></span><span class="gs-tx"><span class="gs-tn"><b>Ads Management</b></span><span class="gs-td">Manage your ads and ad settings.</span></span></a>
        <button type="button" class="gs-topic" onclick="openModal('modal-postdef')"><span class="gs-ti"><i class="ri-edit-box-line"></i></span><span class="gs-tx"><span class="gs-tn"><b>Default post settings</b></span><span class="gs-td">Hide counts, AI label and downloads for new posts.</span></span></button>
        <button type="button" class="gs-topic" onclick="openModal('modal-storeddata')"><span class="gs-ti"><i class="ri-database-2-line"></i></span><span class="gs-tx"><span class="gs-tn"><b>What we store about you</b></span><span class="gs-td">The kinds of information Dezzme keeps.</span></span></button>
        <button type="button" class="gs-topic" onclick="openModal('modal-datadl')"><span class="gs-ti"><i class="ri-download-cloud-2-line"></i></span><span class="gs-tx"><span class="gs-tn"><b>Download your data</b></span><span class="gs-td">Get a copy of your posts, media and profile.</span></span></button>
        <a class="gs-topic" href="Ad_payment.php"><span class="gs-ti"><i class="ri-bank-card-line"></i></span><span class="gs-tx"><span class="gs-tn"><b>Ad Payment</b></span><span class="gs-td">Pay for your ads and manage your ad billing.</span></span></a>
        <button type="button" class="gs-topic" onclick="openModal('modal-danger')"><span class="gs-ti"><i class="ri-delete-bin-line"></i></span><span class="gs-tx"><span class="gs-tn"><b>Deactivate or delete account</b></span><span class="gs-td">Hide your account for now, or remove it for good.</span></span></button>
      </div></div></div>
    </div>

    <div class="gs-card" data-k="who">
      <button type="button" class="gs-bar" onclick="gsToggle(this)" aria-expanded="true"><i class="ri-question-answer-line gs-bar-ic"></i><span class="gs-bar-t">Block, mute or restrict?</span><i class="ri-arrow-down-s-line gs-bar-chev"></i></button>
      <div class="gs-body"><div class="gs-inner"><div class="gs-pad">
        <div class="gs-step"><span class="gs-sn">1</span><span class="gs-st"><b>Mute to stop seeing posts</b><span>They can still track and message you. Their posts leave your feed and they are never told.</span></span></div>
        <div class="gs-step"><span class="gs-sn">2</span><span class="gs-st"><b>Restrict to tidy your inbox</b><span>Their messages move to a requests folder. Nothing looks different on their side.</span></span></div>
        <div class="gs-step"><span class="gs-sn">3</span><span class="gs-st"><b>Block for no contact at all</b><span>They can't see your profile or content, or message you. You can unblock any time.</span></span></div>
      </div></div></div>
    </div>

    <div class="gs-card" data-k="safe">
      <button type="button" class="gs-bar" onclick="gsToggle(this)" aria-expanded="true"><i class="ri-shield-check-line gs-bar-ic"></i><span class="gs-bar-t">Keep your account safe</span><i class="ri-arrow-down-s-line gs-bar-chev"></i></button>
      <div class="gs-body"><div class="gs-inner"><div class="gs-pad">
        <div class="gs-rule"><i class="ri-check-line"></i><span>Use a <b>strong, unique password</b>. Never reuse one from another app.</span></div>
        <div class="gs-rule"><i class="ri-check-line"></i><span>Keep your <b>email up to date</b> so you can always recover your account.</span></div>
        <div class="gs-rule"><i class="ri-check-line"></i><span>Pick a <b>privacy level</b> that fits you: Public, Limited, Private or Full Private.</span></div>
        <div class="gs-rule"><i class="ri-check-line"></i><span>Review who you've <b>blocked or muted</b> from time to time.</span></div>
        <div class="gs-rule"><i class="ri-check-line"></i><span>Never share your password with anyone, including people claiming to be Dezzme.</span></div>
      </div></div></div>
    </div>

    <div class="gs-card" data-k="help">
      <button type="button" class="gs-bar" onclick="gsToggle(this)" aria-expanded="true"><i class="ri-customer-service-2-line gs-bar-ic"></i><span class="gs-bar-t">Need a hand?</span><i class="ri-arrow-down-s-line gs-bar-chev"></i></button>
      <div class="gs-body"><div class="gs-inner"><div class="gs-pad">
        <a class="gs-topic" href="help.php"><span class="gs-ti"><i class="ri-question-line"></i></span><span class="gs-tx"><span class="gs-tn"><b>Help</b></span><span class="gs-td">Guides, answers and ways to contact support.</span></span></a>
        <a class="gs-topic" href="feedback.php"><span class="gs-ti"><i class="ri-feedback-line"></i></span><span class="gs-tx"><span class="gs-tn"><b>Send Feedback</b></span><span class="gs-td">Tell us what to fix or improve.</span></span></a>
        <a class="gs-topic" href="account.php"><span class="gs-ti"><i class="ri-information-line"></i></span><span class="gs-tx"><span class="gs-tn"><b>About your account</b></span><span class="gs-td">Join date and how your account works.</span></span></a>
      </div></div></div>
    </div>

  </div>
</aside>

</div><!-- /.mp-shell -->


<!-- ═════════ FULL-SCREEN MODALS (Memepedia shell) ═════════ -->

<!-- ACCOUNT CENTRE -->
<div class="modal-overlay" id="modal-account">
  <div class="modal-lane">
    <div class="ac-topbar modal-topbar">
      <button type="button" class="ac-x" onclick="closeModal('modal-account')" aria-label="Close"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button>
      <div class="ac-title left modal-head-title">Dezz Account Center</div>
    </div>
    <div class="modal-body"><div class="mb-in">

      <div class="ig-note" style="margin-bottom:18px">
        <p>Edit your username, nickname, email, profile picture, pronouns, bio and linked accounts. Each field saves on its own with the Save button beside it, so you can change one thing without touching the rest.</p>
      </div>

      <div class="field-row">
        <label class="field-label">Username <span class="field-hint">Unique identifier on Dezzme</span></label>
        <div class="field-control">
          <div class="input-wrap">
            <input type="text" class="f-input" name="username" id="input-username" value="<?= htmlspecialchars($user['username'] ?? '') ?>" maxlength="30" autocomplete="off" oninput="checkAvailability('username', this.value)"<?= $un_locked ? ' readonly' : '' ?>>
            <span id="status-username" class="input-status"></span>
          </div>
          <button class="save-btn" id="save-username" onclick="saveUsername(this)"<?= $un_locked ? ' disabled' : '' ?>>Save</button>
        </div>
        <div id="feedback-username" class="field-feedback"></div>
        <div id="suggestions-username" class="field-suggestions"></div>
        <p class="field-help">Your username is how people find and mention you. Use 3 to 30 letters, numbers, underscores or dots. It has to be unique, so we check availability as you type.</p>
        <p class="un-note" id="un-note"></p>
      </div>

      <div class="field-row">
        <label class="field-label">Nickname <span class="field-hint">Display name shown on your profile</span></label>
        <div class="field-control">
          <div class="input-wrap">
            <input type="text" class="f-input" name="nickname" id="input-nickname" value="<?= htmlspecialchars($user['nickname'] ?? '') ?>" autocomplete="off" oninput="checkAvailability('nickname', this.value)">
            <span id="status-nickname" class="input-status"></span>
          </div>
          <button class="save-btn" id="save-nickname" onclick="updateField('nickname', this)">Save</button>
        </div>
        <div id="feedback-nickname" class="field-feedback"></div>
        <div id="suggestions-nickname" class="field-suggestions"></div>
        <p class="field-help">Your nickname is the display name at the top of your profile. Nicknames are unique too, so we check availability as you type.</p>
      </div>

      <div class="field-row">
        <label class="field-label">Email</label>
        <div class="field-control">
          <input type="text" class="f-input" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>">
          <button class="save-btn" onclick="updateField('email', this)">Save</button>
        </div>
        <p class="field-help">Used for account notices and recovery. Make sure it's an address you still have access to.</p>
      </div>

      <div class="field-row">
        <label class="field-label">Profile Picture <span class="field-hint">JPG, PNG or WebP</span></label>
        <div class="av-row">
          <img id="av-cur" class="av-cur" src="<?= htmlspecialchars($user['profile_pic'] ?: 'mee.jpg') ?>" onerror="this.src='mee.jpg'" alt="Your profile picture">
          <div class="av-btns">
            <span class="hle-btn accent">Upload new picture<input type="file" accept="image/*" onchange="avPicked(this)"></span>
            <button type="button" class="hle-btn danger" id="av-rm" onclick="avRemove()"<?= $vf_has_pic ? '' : ' style="display:none"' ?>>Remove</button>
          </div>
        </div>
        <p class="field-help">Choose a photo from your phone or computer, then drag and zoom to crop it into a square. Clear faces and logos look best.</p>
      </div>

      <?php
      $pronoun_groups = [
        'Common'       => ['He/Him', 'She/Her', 'They/Them'],
        'Mixed'        => ['He/They', 'She/They', 'They/He', 'They/She'],
        'Neopronouns'  => ['Ze/Zir', 'Xe/Xem', 'Ey/Em', 'Fae/Faer', 'Ve/Ver'],
      ];
      $all_pronouns = array_merge(...array_values($pronoun_groups));
      $saved_pronouns = trim((string)($user['pronouns'] ?? ''));
      $current_pronouns = $saved_pronouns;
      $is_custom_pronoun = false;
      if ($saved_pronouns !== '') {
        $found = false;
        foreach ($all_pronouns as $opt) { if (strcasecmp($opt, $saved_pronouns) === 0) { $current_pronouns = $opt; $found = true; break; } }
        $is_custom_pronoun = !$found;
      }
      ?>
      <div class="field-row">
        <label class="field-label">Pronouns <span class="field-hint">Shown on your profile</span></label>
        <div class="field-control">
          <select class="f-select" name="pronouns">
            <option value="" <?= $current_pronouns === '' ? 'selected' : '' ?>>Don't show pronouns</option>
            <?php if ($is_custom_pronoun): ?>
            <option value="<?= htmlspecialchars($saved_pronouns) ?>" selected><?= htmlspecialchars($saved_pronouns) ?> (current)</option>
            <?php endif; ?>
            <?php foreach ($pronoun_groups as $grp => $opts): ?>
            <optgroup label="<?= htmlspecialchars($grp) ?>">
              <?php foreach ($opts as $opt): ?>
              <option value="<?= htmlspecialchars($opt) ?>" <?= ($current_pronouns === $opt) ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
              <?php endforeach; ?>
            </optgroup>
            <?php endforeach; ?>
          </select>
          <button class="save-btn" onclick="updateField('pronouns', this)">Save</button>
        </div>
        <p class="field-help">Choose the pronouns you'd like people to use. Pick "Don't show pronouns" to hide them from your profile.</p>
      </div>

      <div class="field-row">
        <label class="field-label">Bio <span class="field-hint">Max 600 characters · <span id="bio-count">0/600</span></span></label>
        <textarea class="f-input" name="bio" maxlength="600"><?= htmlspecialchars($settings['Bio'] ?? '') ?></textarea>
        <button class="save-btn-full" onclick="updateFieldFromModal('bio', this)">Save Bio</button>
        <p class="field-help">Tell people who you are and what you post. Your bio shows on your profile, so keep it to what you're happy for trackers to read.</p>
      </div>

      <div class="field-row">
        <label class="field-label">Other Accounts <span class="field-hint">Comma-separated usernames</span></label>
        <div class="field-control">
          <input type="text" class="f-input" name="other_accounts" value="<?= htmlspecialchars($settings['other_accounts'] ?? '') ?>" placeholder="@Username,@Username">
          <button class="save-btn" onclick="updateField('other_accounts', this)">Save</button>
        </div>
        <p class="field-help">List your other Dezzme accounts, separated by commas, for example @first,@second.</p>
      </div>

      <div class="field-row">
        <label class="field-label">User Type <span class="field-hint">Your meme activity style</span></label>
        <div class="radio-stack">
          <?php
          $types = ['' => "Don't show a user type on your profile.", 'Dezzmer' => 'Create memes and post random memes.'];
          $current_type = (string)($settings['user_type'] ?? '');
          foreach ($types as $val => $desc):
            $checked = ($current_type === $val) ? 'checked' : '';
            $label   = ($val === '') ? 'None' : $val;
          ?>
          <label class="radio-item">
            <div class="radio-item-text"><strong><?= htmlspecialchars($label) ?></strong><span><?= htmlspecialchars($desc) ?></span></div>
            <input type="radio" name="user_type" value="<?= htmlspecialchars($val) ?>" <?= $checked ?> onchange="updateFieldFromRadio('user_type', this)">
          </label>
          <?php endforeach; ?>
        </div>
      </div>

    </div></div>
  </div>
</div>

<!-- PROFILE PICTURE: CROP -->
<div class="modal-overlay" id="modal-avatar">
  <div class="modal-lane">
    <div class="ac-topbar modal-topbar">
      <button type="button" class="ac-x" onclick="avClose()" aria-label="Close"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button>
      <div class="ac-title left modal-head-title">Crop your picture</div>
    </div>
    <div class="modal-body"><div class="mb-in">
      <p class="field-help" style="text-align:center;margin-bottom:12px">Drag the picture to move it. Use the slider, or your mouse wheel, to zoom.</p>
      <div class="av-stage"><canvas id="av-canvas" width="640" height="640"></canvas></div>
      <div class="av-zoom"><i class="ri-image-line"></i><input type="range" id="av-zoom" min="1" max="4" step="0.01" value="1" oninput="avZoom(+this.value)" aria-label="Zoom"><i class="ri-image-2-line"></i></div>
      <div class="hl-status" id="av-status" style="text-align:center;margin-top:10px" aria-live="polite"></div>
      <div class="av-actions">
        <button type="button" class="save-btn-full" id="av-save" onclick="avSave(this)">Use this picture</button>
        <button type="button" class="av-cancel" onclick="avClose()">Cancel</button>
      </div>
      <div class="ig-note after-list" style="max-width:340px;margin-left:auto;margin-right:auto">
        <p>The circle shows how your picture will look next to your name. Your picture is saved as a square, so nothing outside the frame is kept.</p>
      </div>
    </div></div>
  </div>
</div>

<!-- PROFILE TYPE -->
<div class="modal-overlay" id="modal-profiletype">
  <div class="modal-lane">
    <div class="ac-topbar modal-topbar">
      <button type="button" class="ac-x" onclick="closeModal('modal-profiletype')" aria-label="Close"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button>
      <div class="ac-title left modal-head-title">Professional Mode Type</div>
    </div>
    <div class="pt-toolbar">
      <div class="pt-search">
        <i class="ri-search-line"></i>
        <input type="text" id="pt-search-input" placeholder="Search e.g. Photographer, Developer, Chef…" autocomplete="off">
        <button type="button" class="pt-clear-search" id="pt-clear-search" aria-label="Clear search"><i class="ri-close-line"></i></button>
      </div>
      <div class="pt-tabs" id="pt-tabs" role="tablist">
        <button type="button" class="pt-tab on" data-grp="people" role="tab"><i class="ri-user-3-line"></i>Individuals</button>
        <button type="button" class="pt-tab" data-grp="org" role="tab"><i class="ri-building-2-line"></i>Organisations &amp; entities</button>
      </div>
      <div class="pt-current">
        <span>Current: <strong id="pt-current-label">None</strong></span>
        <button type="button" class="pt-remove" id="pt-remove" onclick="saveProfileType('')" hidden>Remove</button>
      </div>
    </div>
    <div class="modal-body"><div class="mb-in">
      <div class="ig-note pt-intro">
        <p>Your Professional Mode type is a short label that appears on your profile and tells people what you do, such as Photographer, Meme Creator or Student. It helps new visitors understand who you are before they read your bio.</p>
        <p>You can only have one Professional Mode type at a time. Tap a label below to set it, tap Remove to clear it, or search to find one faster. You can change it whenever you like, and it doesn't change what you can do on Dezzme.</p>
        <p><strong>Individuals</strong> covers people, such as creators, professionals and students. <strong>Organisations &amp; entities</strong> covers businesses, brands, schools, clubs, media, institutions and more.</p>
        <p>Can't find an exact match? Choose the closest one, or pick Other under Other / Personal.</p>
      </div>
      <div class="pt-skel" id="pt-skel"></div>
      <div id="pt-list"></div>
      <div class="pt-none" id="pt-none"><i class="ri-search-eye-line"></i>No Professional Mode type matches your search</div>
    </div></div>
  </div>
</div>

<!-- PASSWORD -->
<div class="modal-overlay" id="modal-password">
  <div class="modal-lane">
    <div class="ac-topbar modal-topbar">
      <button type="button" class="ac-x" onclick="closeModal('modal-password')" aria-label="Close"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button>
      <div class="ac-title left modal-head-title">Password &amp; Security</div>
    </div>
    <div class="modal-body"><div class="mb-in">
      <div class="pw-stack">
        <input type="password" class="f-input" id="pw-current" placeholder="Current password" autocomplete="current-password">
        <input type="password" class="f-input" id="pw-new" placeholder="New password (min. 8 characters)" autocomplete="new-password">
        <div class="pw-meter" aria-hidden="true"><span id="pw-meter-bar"></span></div>
        <div class="pw-meter-label" id="pw-meter-label" aria-live="polite"></div>
        <input type="password" class="f-input" id="pw-confirm" placeholder="Confirm new password" autocomplete="new-password">
        <div id="pw-feedback" class="field-feedback"></div>
        <button class="save-btn-full" id="pw-submit" onclick="changePassword(this)">Update Password</button>
      </div>
      <div class="ig-note after-list">
        <p>To change your password, enter your current password first, then type your new password twice so we can be sure it was entered correctly. Your new password must be at least 8 characters long.</p>
        <p>A strong password is longer, mixes letters, numbers and symbols, and isn't used on any other site or app. Avoid your username, your name, your birthday or common words, since these are the first things people try.</p>
        <p>If you can't remember your current password, <a href="help.php">visit Help</a> for ways to get back into your account.</p>
      </div>
    </div></div>
  </div>
</div>

<!-- ACTIVITY VIBE -->
<div class="modal-overlay" id="modal-vibe">
  <div class="modal-lane">
    <div class="ac-topbar modal-topbar">
      <button type="button" class="ac-x" onclick="closeModal('modal-vibe')" aria-label="Close"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button>
      <div class="ac-title left modal-head-title">Activity Vibe</div>
    </div>
    <div class="modal-body"><div class="mb-in">
      <?php
      $activity_options = [
        '' => 'None', 'Flexible' => 'Flexible',
        'Early morning (5–9 AM)' => 'Early morning (5–9 AM)', 'Late morning (9–12 PM)' => 'Late morning (9–12 PM)',
        'Afternoon (12–5 PM)' => 'Afternoon (12–5 PM)', 'Evening (5–9 PM)' => 'Evening (5–9 PM)',
        'Night (9 PM–1 AM)' => 'Night (9 PM–1 AM)', 'Late night (1–5 AM)' => 'Late night (1–5 AM)',
      ];
      $current_vibe = (string)($settings['activity_vibe'] ?? '');
      ?>
      <select class="f-select" name="activity_vibe" id="vibe-select">
        <?php foreach ($activity_options as $val => $lbl): ?>
        <option value="<?= htmlspecialchars($val) ?>" <?= ($current_vibe === $val) ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="save-btn-full" onclick="updateFieldFromSelect('activity_vibe', document.getElementById('vibe-select'), this)" style="margin-top:12px">Save Vibe</button>

      <div class="ig-note after-list">
        <p>Your activity vibe tells people on your profile when you're usually around on Dezzme, so trackers know when they're most likely to get a reply or see something new from you.</p>
        <p>Pick the window that best matches your routine, or choose Flexible if your schedule changes from day to day. Choose None if you'd rather not show anything.</p>
        <p>This is a setting you choose yourself. Dezzme doesn't track your activity to fill it in, and it doesn't limit when you can use the app. You can change it at any time.</p>
      </div>
    </div></div>
  </div>
</div>

<!-- PRIVACY CONTROLS : privacy levels -->
<?php
$pv_levels = [
  'public' => ['ri-earth-line', 'Public', 'Open to everyone',
    'Anyone on Dezzme can find you, see your memes, bio and stats, and start tracking you without asking.',
    ['Your memes can reach people who don\'t track you yet', 'Anyone can comment on your memes and message you', 'Best chance of showing up in search, suggestions and trending'],
    'Creators who want the biggest audience.'],
  'limited' => ['ri-shield-line', 'Limited', 'Visible, with limits',
    'Your profile and memes stay visible to everyone, but you decide who can interact. Strangers can look, only your trackers can talk.',
    ['Anyone can see your photo, bio and memes', 'Only your trackers can comment on your memes and message you', 'Tracker and Tracking counts are hidden from visitors'],
    'Public creators who want fewer strangers in their comments and inbox.'],
  'private' => ['ri-lock-line', 'Private', 'Approve who sees your memes',
    'Visitors can see your name, photo and bio, but your memes are only for trackers you approve. New trackers have to send a request first.',
    ['Your memes stay hidden until you approve a tracker', 'Requests wait for you under Track Requests in Settings', 'You still show up in search, so friends can find you'],
    'Sharing with people you know while staying easy to find.'],
  'full' => ['ri-lock-2-line', 'Full Private', 'Invisible to strangers',
    'Only your name and photo are visible. Your bio, memes and stats are for approved trackers only, and you are hidden from search and suggestions.',
    ['New trackers must send a request that you approve', 'Hidden from search, suggestions and trending', 'Only approved trackers can comment or message you'],
    'Maximum privacy and total control over who sees you.'],
];
$pv_rows = [
  ['Photo and name',                  ['a', 'a', 'a', 'a']],
  ['Bio',                             ['a', 'a', 'a', 't']],
  ['Memes',                           ['a', 'a', 't', 't']],
  ['Shown in search and suggestions', ['a', 'a', 'a', 'n']],
  ['Track you without asking',        ['a', 'a', 't', 't']],
  ['Comment on your memes',           ['a', 't', 't', 't']],
  ['Message you',                     ['a', 't', 't', 't']],
  ['See Tracker and Tracking counts', ['a', 't', 't', 't']],
];
$pv_icons = ['a' => ['ri-check-line', 'pv-a', 'Anyone'], 't' => ['ri-user-follow-line', 'pv-t', 'Only approved trackers'], 'n' => ['ri-lock-line', 'pv-n', 'No one']];
$pv_js = [];
foreach ($pv_levels as $k => $l) $pv_js[$k] = ['name' => $l[1], 'tag' => $l[2], 'sum' => $l[3], 'icon' => $l[0], 'n' => array_search($k, PRIV_LEVELS, true) + 1];
$pv_meter_words = ['Open', 'Balanced', 'Protected', 'Locked down'];
?>
<div class="modal-overlay" id="modal-privacy">
  <div class="modal-lane">
    <div class="ac-topbar modal-topbar">
      <button type="button" class="ac-x" onclick="closeModal('modal-privacy')" aria-label="Close"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button>
      <div class="ac-title left modal-head-title">Privacy Controls</div>
    </div>
    <div class="modal-body"><div class="mb-in">

      <div class="pv-hero">
        <i class="pv-wm ri-lock-2-line"></i>
        <div class="pv-hero-top">
          <span class="pv-hero-ic"><i id="pv-hero-ic" class="<?= htmlspecialchars($pv_levels[$priv_level][0]) ?>"></i></span>
          <div style="min-width:0">
            <div class="pv-hero-l">Your privacy level</div>
            <div class="pv-hero-n" id="pv-hero-n"><?= htmlspecialchars($pv_levels[$priv_level][1]) ?></div>
          </div>
        </div>
        <div class="pv-meter" id="pv-meter"><span></span><span></span><span></span><span></span></div>
        <div class="pv-meter-l"><?php foreach ($pv_meter_words as $w): ?><span><?= $w ?></span><?php endforeach; ?></div>
        <p class="pv-hero-s" id="pv-hero-s"><?= htmlspecialchars($pv_levels[$priv_level][3]) ?></p>
      </div>

      <div class="pv-h">Choose a level</div>
      <div class="pv-levels" id="pv-levels" role="radiogroup" aria-label="Privacy level">
        <?php foreach ($pv_levels as $k => $l): ?>
        <button type="button" class="pv-card<?= $k === $priv_level ? ' on' : '' ?>" data-lv="<?= $k ?>" role="radio" aria-checked="<?= $k === $priv_level ? 'true' : 'false' ?>" onclick="prPickLevel('<?= $k ?>')">
          <div class="pv-card-top">
            <span class="pv-ic"><i class="<?= htmlspecialchars($l[0]) ?>"></i></span>
            <div style="min-width:0"><div class="pv-cn"><?= htmlspecialchars($l[1]) ?></div><div class="pv-ct"><?= htmlspecialchars($l[2]) ?></div></div>
            <span class="pv-radio"></span>
          </div>
          <p class="pv-cd"><?= htmlspecialchars($l[3]) ?></p>
          <ul class="pv-pts"><?php foreach ($l[4] as $pt): ?><li><i class="ri-check-line"></i><span><?= htmlspecialchars($pt) ?></span></li><?php endforeach; ?></ul>
          <div class="pv-best"><b>Best for:</b> <?= htmlspecialchars($l[5]) ?></div>
        </button>
        <?php endforeach; ?>
      </div>

      <div class="pv-h">What people can see</div>
      <div class="pv-mx" id="pv-mx">
        <div class="pv-mx-r pv-mx-h"><span></span><?php foreach (PRIV_LEVELS as $k): ?><span data-lv="<?= $k ?>"><?= $k === 'full' ? 'Full' : htmlspecialchars($pv_levels[$k][1]) ?></span><?php endforeach; ?></div>
        <?php foreach ($pv_rows as $row): ?>
        <div class="pv-mx-r"><span><?= htmlspecialchars($row[0]) ?></span><?php foreach ($row[1] as $i => $c): ?><span data-lv="<?= PRIV_LEVELS[$i] ?>" title="<?= $pv_icons[$c][2] ?>"><i class="<?= $pv_icons[$c][0] ?> <?= $pv_icons[$c][1] ?>"></i></span><?php endforeach; ?></div>
        <?php endforeach; ?>
      </div>
      <div class="pv-legend">
        <span><i class="ri-check-line pv-a"></i>Anyone</span>
        <span><i class="ri-user-follow-line pv-t"></i>Only approved trackers</span>
        <span><i class="ri-lock-line pv-n"></i>No one</span>
      </div>

      <div class="pv-h">Contact and activity</div>
      <div class="pv-box">
        <div class="pv-set">
          <div class="pv-set-tx"><strong>Allow messages</strong><span>Turn this off and nobody can start a new message with you, whatever your level is.</span></div>
          <label class="switch"><input type="checkbox" data-pf="allow_messages" <?= !empty($settings['allow_messages']) ? 'checked' : '' ?> onchange="prField('allow_messages', this.checked ? 1 : 0)"><span class="slider"></span></label>
        </div>
        <div class="pv-set col">
          <div class="pv-set-tx"><strong>Online status</strong><span>Who sees the green dot when you are online. If you pick Nobody, you won't see anyone's either.</span></div>
          <div class="pv-seg" role="radiogroup" aria-label="Online status">
            <?php foreach (['2' => 'Everyone', '1' => 'My trackers', '0' => 'Nobody'] as $v => $lbl): ?>
            <button type="button" class="pv-opt<?= (string)$act_online === (string)$v ? ' on' : '' ?>" data-g="online_visibility" data-v="<?= $v ?>" role="radio" aria-checked="<?= (string)$act_online === (string)$v ? 'true' : 'false' ?>" onclick="prOpt('online_visibility', '<?= $v ?>')"><?= $lbl ?></button>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="pv-set col">
          <div class="pv-set-tx"><strong>Last seen</strong><span>Who sees "Active 5m ago" next to your name. Same rule: hide yours and you won't see theirs.</span></div>
          <div class="pv-seg" role="radiogroup" aria-label="Last seen">
            <?php foreach (['2' => 'Everyone', '1' => 'My trackers', '0' => 'Nobody'] as $v => $lbl): ?>
            <button type="button" class="pv-opt<?= (string)$act_last === (string)$v ? ' on' : '' ?>" data-g="last_seen_visibility" data-v="<?= $v ?>" role="radio" aria-checked="<?= (string)$act_last === (string)$v ? 'true' : 'false' ?>" onclick="prOpt('last_seen_visibility', '<?= $v ?>')"><?= $lbl ?></button>
            <?php endforeach; ?>
          </div>
          <?php if (!empty($settings['is_fully_private'])): ?><p class="pv-hint" style="margin-top:8px">Your account is Full Private, so only people who track you can ever see your status — even if you pick Everyone.</p><?php endif; ?>
        </div>
      </div>

      <div class="pv-h">More privacy tools</div>
      <div class="pv-box">
        <div class="pv-set pv-go" onclick="openModal('modal-tags')"><span class="pv-ic sm"><i class="ri-at-line"></i></span><div class="pv-set-tx"><strong>Tags and mentions</strong><span>Choose who can tag you and @mention you.</span></div><i class="ri-arrow-right-s-line menu-chevron"></i></div>
        <div class="pv-set pv-go" onclick="openModal('modal-lists')"><span class="pv-ic sm"><i class="ri-team-line"></i></span><div class="pv-set-tx"><strong>Trackers, Tracking &amp; Pods lists</strong><span>Hide the people and pods you are connected to.</span></div><i class="ri-arrow-right-s-line menu-chevron"></i></div>
        <div class="pv-set pv-go" onclick="openModal('modal-blocked')"><span class="pv-ic sm"><i class="ri-shield-user-line"></i></span><div class="pv-set-tx"><strong>Block, mute or restrict</strong><span>Keep specific people away without changing your level.</span></div><i class="ri-arrow-right-s-line menu-chevron"></i></div>
      </div>

      <div class="pv-h">Good to know</div>
      <div class="pv-faq-wrap">
        <details class="pv-faq"><summary>What happens to people who already track me?</summary><p>Nothing. Going Private or Full Private never removes anyone who already tracks you. They keep seeing what you share, and only new trackers have to ask first.</p></details>
        <details class="pv-faq"><summary>Does my level apply to memes I posted before?</summary><p>Yes. Your level covers your whole profile, including everything you have already posted, so you don't have to change memes one by one.</p></details>
        <details class="pv-faq"><summary>Can I switch back whenever I want?</summary><p>Yes. Changes apply straight away, and you can move between levels as often as you like. Requests that are waiting stay in Track Requests until you answer them.</p></details>
        <details class="pv-faq"><summary>What is the difference between Private and Full Private?</summary><p>With Private, strangers can still read your bio and find you in search. With Full Private, only your name and photo show and you are hidden from search and suggestions.</p></details>
        <details class="pv-faq"><summary>I only want to keep certain people away</summary><p>You don't need to change your level for that. Use Block, Mute or Restrict under Moderation to deal with specific accounts.</p></details>
      </div>

    </div></div>
  </div>
</div>

<!-- TAGS AND MENTIONS -->
<?php
$tg_sets = [
  'tag_who' => ['Who can tag you', [
    'everyone' => ['ri-earth-line', 'Everyone', 'Open', 'Any Dezzmer can tag you in their memes and photos. Potential spam is always filtered out.'],
    'tracked'  => ['ri-user-follow-line', 'People I track', 'Your circle', 'Only accounts you track can tag you. Everyone else is told that you don\'t allow tags from everyone.'],
    'none'     => ['ri-forbid-line', 'No one', 'Off', 'Nobody can tag you. People who try will see that you don\'t allow tags.'],
  ]],
  'mention_who' => ['Who can @mention you', [
    'everyone' => ['ri-earth-line', 'Everyone', 'Open', 'Any Dezzmer can link your profile with @ in their memes, comments, highlights, bio and captions.'],
    'tracked'  => ['ri-user-follow-line', 'People I track', 'Your circle', 'Only accounts you track can @mention you. Everyone else is told that you don\'t allow @mentions.'],
    'none'     => ['ri-forbid-line', 'No one', 'Off', 'Nobody can @mention you. People who try will see that you don\'t allow @mentions.'],
  ]],
];
$tg_n = 1 + ($priv['tag_who'] !== 'everyone' ? 1 : 0) + ($priv['mention_who'] !== 'everyone' ? 1 : 0) + (!empty($priv['tag_approve']) ? 1 : 0);
$tg_names = ['Open to everyone', 'Balanced', 'Protected', 'Locked down'];
?>
<div class="modal-overlay" id="modal-tags">
  <div class="modal-lane">
    <div class="ac-topbar modal-topbar">
      <button type="button" class="ac-x" onclick="closeModal('modal-tags')" aria-label="Close"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button>
      <div class="ac-title left modal-head-title">Tags and mentions</div>
    </div>
    <div class="modal-body"><div class="mb-in">

      <div class="pv-hero">
        <i class="pv-wm ri-at-line"></i>
        <div class="pv-hero-top">
          <span class="pv-hero-ic"><i class="ri-at-line"></i></span>
          <div style="min-width:0">
            <div class="pv-hero-l">Your tags &amp; mentions</div>
            <div class="pv-hero-n" id="tg-hero-n"><?= htmlspecialchars($tg_names[$tg_n - 1]) ?></div>
          </div>
        </div>
        <div class="pv-meter" id="tg-meter"><span></span><span></span><span></span><span></span></div>
        <div class="pv-meter-l"><?php foreach ($pv_meter_words as $w): ?><span><?= $w ?></span><?php endforeach; ?></div>
        <p class="pv-hero-s" id="tg-hero-s"></p>
      </div>

      <?php foreach ($tg_sets as $field => $set): ?>
      <div class="pv-h"><?= htmlspecialchars($set[0]) ?></div>
      <div class="tg-tiles" role="radiogroup" aria-label="<?= htmlspecialchars($set[0]) ?>">
        <?php foreach ($set[1] as $val => $c): $on = ($priv[$field] === $val); ?>
        <button type="button" class="pv-opt tile<?= $on ? ' on' : '' ?>" data-g="<?= $field ?>" data-v="<?= $val ?>" data-desc="<?= htmlspecialchars($c[3]) ?>" role="radio" aria-checked="<?= $on ? 'true' : 'false' ?>" onclick="prOpt('<?= $field ?>', '<?= $val ?>')">
          <span class="tk"></span>
          <span class="pv-ic"><i class="<?= $c[0] ?>"></i></span>
          <b><?= htmlspecialchars($c[1]) ?></b>
          <small><?= htmlspecialchars($c[2]) ?></small>
        </button>
        <?php endforeach; ?>
      </div>
      <p class="tg-desc" data-for="<?= $field ?>"><?= htmlspecialchars($set[1][$priv[$field]][3] ?? '') ?></p>
      <?php endforeach; ?>

      <div class="pv-h">How you manage tags</div>
      <div class="pv-box">
        <div class="pv-set">
          <div class="pv-set-tx"><strong>Manually approve tags</strong><span>A tag stays hidden from your profile until you accept it. You decide what gets linked to you.</span></div>
          <label class="switch"><input type="checkbox" data-pf="tag_approve" <?= !empty($priv['tag_approve']) ? 'checked' : '' ?> onchange="prField('tag_approve', this.checked ? 1 : 0)"><span class="slider"></span></label>
        </div>
        <div class="pv-set pv-go" onclick="openModal('modal-reviewtags')" role="button" tabindex="0"><span class="pv-ic sm"><i class="ri-checkbox-multiple-line"></i></span><div class="pv-set-tx"><strong>Review tags</strong><span>See tags that are waiting for your approval.</span></div><i class="ri-arrow-right-s-line menu-chevron"></i></div>
      </div>

      <div class="pv-h">Reach</div>
      <div class="pv-box">
        <div class="pv-set">
          <div class="pv-set-tx"><strong>Allow people to feature memes that mention you</strong><span>Featuring helps a meme reach more people on Dezzme, and featured memes may stay visible for longer. Anyone can see them.</span></div>
          <label class="switch"><input type="checkbox" data-pf="mention_feature" <?= !empty($priv['mention_feature']) ? 'checked' : '' ?> onchange="prField('mention_feature', this.checked ? 1 : 0)"><span class="slider"></span></label>
        </div>
      </div>
      <div class="gs-callout" style="margin-top:10px"><i class="ri-information-line"></i><span>Memes that mention you, including ones that share your posts, can only be featured if your privacy level is <b>Public</b> or <b>Limited</b> and you allow @mentions.</span></div>

      <div class="pv-h">What people see when they try</div>
      <div class="pv-mx">
        <div class="ls-pv" id="tg-pv-tag"><i class="ri-price-tag-3-line ls-pv-ic"></i><span class="ls-pv-n">Tagging you</span><span class="ls-pv-v"></span></div>
        <div class="ls-pv" id="tg-pv-mention"><i class="ri-at-line ls-pv-ic"></i><span class="ls-pv-n">Mentioning you</span><span class="ls-pv-v"></span></div>
      </div>

      <div class="pv-h">Good to know</div>
      <div class="pv-faq-wrap">
        <details class="pv-faq"><summary>What is the difference between a tag and a mention?</summary><p>A tag links your profile to a meme or photo, like saying "this person is in it". A mention is when someone types @ and your name in a comment, caption, bio or highlight.</p></details>
        <details class="pv-faq"><summary>Will people know I don't allow tags?</summary><p>Yes, but only when they try. They see a short message that you don't allow tags or mentions from everyone. You are never named in public.</p></details>
        <details class="pv-faq"><summary>Is spam still filtered if I allow everyone?</summary><p>Yes. Potential spam is always filtered, whatever you choose here.</p></details>
        <details class="pv-faq"><summary>Do these choices change my privacy level?</summary><p>No. They work alongside your level. Your level controls who can see your profile, these settings control who can link to it.</p></details>
      </div>

    </div></div>
  </div>
</div>

<!-- REVIEW TAGS -->
<div class="modal-overlay" id="modal-reviewtags">
  <div class="modal-lane">
    <div class="ac-topbar modal-topbar">
      <button type="button" class="ac-x" onclick="closeModal('modal-reviewtags')" aria-label="Close"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button>
      <div class="ac-title left modal-head-title">Review tags</div>
    </div>
    <div class="modal-body"><div class="mb-in">
      <div class="pv-hero">
        <i class="pv-wm ri-checkbox-multiple-line"></i>
        <div class="pv-hero-top">
          <span class="pv-hero-ic"><i class="ri-checkbox-multiple-line"></i></span>
          <div style="min-width:0"><div class="pv-hero-l">Waiting for you</div><div class="pv-hero-n">Tags to review will show here</div></div>
        </div>
        <p class="pv-hero-s">Turn on Manually approve tags in Tags and mentions. Each new tag then waits here until you accept or remove it.</p>
      </div>
      <div class="pv-h">How it works</div>
      <div class="pv-box">
        <div class="pv-set"><span class="pv-ic sm"><i class="ri-price-tag-3-line"></i></span><div class="pv-set-tx"><strong>Someone tags you</strong><span>The tag is held back and does not show on your profile.</span></div></div>
        <div class="pv-set"><span class="pv-ic sm"><i class="ri-eye-line"></i></span><div class="pv-set-tx"><strong>You look at it</strong><span>Open the meme or photo and decide if you want to be linked to it.</span></div></div>
        <div class="pv-set"><span class="pv-ic sm"><i class="ri-check-double-line"></i></span><div class="pv-set-tx"><strong>Accept or remove</strong><span>Accept to show it on your profile, or remove it so it never appears.</span></div></div>
      </div>
    </div></div>
  </div>
</div>

<!-- TRACKERS / TRACKING / PODS LISTS -->
<?php
$ls_defs = [
  'show_trackers' => ['ri-user-heart-line', 'Trackers list', 'The people who track you.', 'trackers'],
  'show_tracking' => ['ri-user-follow-line', 'Tracking list', 'The people you track.', 'tracking'],
  'show_pods'     => ['ri-group-line', 'Pods list', 'The pods you have joined or created.', 'pods'],
];
$ls_opts = ['everyone' => 'Everyone', 'trackers' => 'My trackers', 'me' => 'Only me'];
$ls_counts = ['show_trackers' => (int)$followers_count, 'show_tracking' => (int)$following_count, 'show_pods' => null];
$lm_auds = ['trk' => ['ri-user-heart-line', 'Your trackers'], 'non' => ['ri-global-line', 'Other Dezzmers']];
$lm_presets = [5, 10, 25, 50, 100];
?>
<div class="modal-overlay" id="modal-lists">
  <div class="modal-lane">
    <div class="ac-topbar modal-topbar">
      <button type="button" class="ac-x" onclick="closeModal('modal-lists')" aria-label="Close"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button>
      <div class="ac-title left modal-head-title">Trackers, Tracking &amp; Pods</div>
    </div>
    <div class="modal-body"><div class="mb-in">

      <div class="pv-hero">
        <i class="pv-wm ri-team-line"></i>
        <div class="pv-hero-top">
          <span class="pv-hero-ic"><i class="ri-team-line"></i></span>
          <div style="min-width:0">
            <div class="pv-hero-l">Your lists</div>
            <div class="pv-hero-n" id="ls-hero-n">All visible</div>
          </div>
        </div>
        <div class="pv-meter" id="ls-meter"><span></span><span></span><span></span><span></span></div>
        <div class="pv-meter-l"><span>Visible</span><span>Partly hidden</span><span>Mostly hidden</span><span>All hidden</span></div>
        <p class="pv-hero-s" id="ls-hero-s"></p>
        <div class="pv-stats">
          <div class="pv-stat"><b><?= (int)$followers_count ?></b><span>Trackers</span></div>
          <div class="pv-stat"><b><?= (int)$following_count ?></b><span>Tracking</span></div>
          <div class="pv-stat"><b id="ls-stat-limits">0</b><span>Limits set</span></div>
        </div>
      </div>

      <div class="pv-h">Quick control</div>
      <div class="pv-box">
        <div class="pv-set">
          <span class="pv-ic sm"><i class="ri-eye-off-line"></i></span>
          <div class="pv-set-tx"><strong>Hide all three lists from everyone</strong><span>One switch for Trackers, Tracking and Pods. Turn it on and only you can open them. Turn it off and every list goes back to Everyone.</span></div>
          <label class="switch"><input type="checkbox" id="ls-master" onchange="prListsAll(this.checked)"><span class="slider"></span></label>
        </div>
      </div>

      <div class="pv-h">Choose for each list</div>
      <div class="pv-levels">
        <?php foreach ($ls_defs as $f => $d): ?>
        <div class="pv-card ls-card">
          <div class="pv-card-top">
            <span class="pv-ic"><i class="<?= $d[0] ?>"></i></span>
            <div style="min-width:0"><div class="pv-cn"><?= htmlspecialchars($d[1]) ?></div><div class="pv-ct"><?= htmlspecialchars($d[2]) ?><?= $ls_counts[$f] !== null ? ' · ' . (int)$ls_counts[$f] . ' now' : '' ?></div></div>
          </div>
          <div class="pv-seg" role="radiogroup" aria-label="<?= htmlspecialchars($d[1]) ?>">
            <?php foreach ($ls_opts as $v => $lbl): ?>
            <button type="button" class="pv-opt<?= $priv[$f] === $v ? ' on' : '' ?>" data-g="<?= $f ?>" data-v="<?= $v ?>" role="radio" aria-checked="<?= $priv[$f] === $v ? 'true' : 'false' ?>" onclick="prOpt('<?= $f ?>', '<?= $v ?>')"><?= $lbl ?></button>
            <?php endforeach; ?>
          </div>
          <p class="pv-hint" id="ls-hint-<?= $f ?>"></p>

          <div class="lm-wrap">
            <div class="lm-h">How many can they see?</div>
            <p class="pv-hint" style="margin:2px 0 0">Choose No limit, a number, or Hide to show them nothing at all.</p>
            <?php foreach ($lm_auds as $a => $ad): ?>
            <div class="lm-row" id="lm-<?= $f ?>-<?= $a ?>">
              <div class="lm-top">
                <span class="lm-who"><i class="<?= $ad[0] ?>"></i><?= htmlspecialchars($ad[1]) ?></span>
                <div class="pv-seg sm">
                  <button type="button" class="lm-nolimit" onclick="lmMode('<?= $f ?>','<?= $a ?>',0)">No limit</button>
                  <button type="button" class="lm-limit" onclick="lmMode('<?= $f ?>','<?= $a ?>',1)">Limit</button>
                  <button type="button" class="lm-hide" onclick="lmMode('<?= $f ?>','<?= $a ?>',-1)">Hide</button>
                </div>
              </div>
              <div class="lm-body" style="display:none">
                <div class="lm-step">
                  <button type="button" aria-label="Fewer" onclick="lmStep('<?= $f ?>','<?= $a ?>',-5)">&minus;</button>
                  <input type="number" min="1" max="10000" inputmode="numeric" aria-label="Number they can see" oninput="lmType('<?= $f ?>','<?= $a ?>',this.value)">
                  <button type="button" aria-label="More" onclick="lmStep('<?= $f ?>','<?= $a ?>',5)">+</button>
                </div>
                <div class="lm-chips"><?php foreach ($lm_presets as $n): ?><button type="button" data-n="<?= $n ?>" onclick="lmSet('<?= $f ?>','<?= $a ?>',<?= $n ?>,true)"><?= $n ?></button><?php endforeach; ?></div>
              </div>
              <p class="lm-state"></p>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="pv-h">What each group sees right now</div>
      <div class="pv-mx">
        <div class="pv-mx-r pv-mx-h lv"><span></span><span>Trackers</span><span>Dezzmers</span></div>
        <?php foreach ($ls_defs as $f => $d): ?>
        <div class="pv-mx-r lv"><span><i class="<?= $d[0] ?>" style="color:var(--accent-text);margin-right:6px;vertical-align:-2px"></i><?= htmlspecialchars($d[1]) ?></span><span class="lv-c" id="lv-<?= $f ?>-trk"></span><span class="lv-c" id="lv-<?= $f ?>-non"></span></div>
        <?php endforeach; ?>
      </div>
      <p class="pv-hint" style="margin-top:9px">Trackers are people who track you. Dezzmers are everyone else on Dezzme. Limits only apply to a group that is allowed to open the list.</p>

      <div class="pv-h">Good to know</div>
      <div class="pv-faq-wrap">
        <details class="pv-faq"><summary>What does a limit do?</summary><p>It caps how many people or pods a group can scroll through. With a limit of 25, a visitor sees up to 25 entries and the rest stay hidden. No limit means they see the whole list, and Hide shows that group nothing at all.</p></details>
        <details class="pv-faq"><summary>Can trackers and other Dezzmers have different limits?</summary><p>Yes. Each list has two separate settings, one for your trackers and one for everyone else, so you can give trackers more than strangers.</p></details>
        <details class="pv-faq"><summary>Does hiding a list remove anyone from it?</summary><p>No. Everyone stays exactly where they are. Hiding and limits only change what other people can open.</p></details>
        <details class="pv-faq"><summary>Will my trackers be told?</summary><p>No. Nobody is notified when you change who can see your lists or how much they see.</p></details>
        <details class="pv-faq"><summary>Can I still see my own lists?</summary><p>Yes. You can always open your full Trackers, Tracking and Pods lists, whatever you choose here.</p></details>
      </div>

    </div></div>
  </div>
</div>

<!-- PROFILE STATUS (account status) -->
<?php
$st_text = [
  'good'      => ['ri-checkbox-circle-fill', 'Your profile is in good standing', 'You can post memes, comment, message and track people as normal. If anything ever limits your profile, it will be shown here.'],
  'limited'   => ['ri-error-warning-fill', 'Your profile has some limits', 'Some features may be restricted for now. Open the full account status page to see what is limited and how to fix it.'],
  'suspended' => ['ri-forbid-2-fill', 'Your profile is suspended', 'You can\'t use most features right now. Open the full account status page to see why and what you can do next.'],
];
?>
<div class="modal-overlay" id="modal-status">
  <div class="modal-lane">
    <div class="ac-topbar modal-topbar">
      <button type="button" class="ac-x" onclick="closeModal('modal-status')" aria-label="Close"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button>
      <div class="ac-title left modal-head-title">Profile status</div>
    </div>
    <div class="modal-body"><div class="mb-in">

      <div class="st-hero <?= $acct_state ?>">
        <div class="st-ring"><i class="<?= $st_text[$acct_state][0] ?>"></i></div>
        <div class="st-t"><?= htmlspecialchars($st_text[$acct_state][1]) ?></div>
        <p class="st-s"><?= htmlspecialchars($st_text[$acct_state][2]) ?></p>
      </div>

      <div class="pv-h">Overview</div>
      <div class="st-list">
        <div class="st-row"><span class="ic"><?= st_badge_icon() ?></span><span class="k">Verified badge</span><span class="v st-v <?= $vf_cls ?>"><?= htmlspecialchars($vf_text) ?></span></div>
        <div class="st-row"><i class="ri-eye-off-line ic"></i><span class="k">Privacy level</span><span class="v" id="st-priv"><?= htmlspecialchars($priv_labels[$priv_level]) ?></span></div>
        <div class="st-row"><i class="ri-price-tag-3-line ic"></i><span class="k">Professional Mode Type</span><span class="v" id="st-type"><?= $st_has_type ? htmlspecialchars($settings['profile_type']) : 'Not set' ?></span></div>
        <div class="st-row"><i class="ri-user-received-line ic"></i><span class="k">Track requests waiting</span><span class="v"><?= count($pending_requests) ?></span></div>
        <div class="st-row"><i class="ri-shield-user-line ic"></i><span class="k">Blocked, muted &amp; restricted</span><span class="v"><?= (int)$side_mod_count ?></span></div>
      </div>

      <div class="pv-h">Complete your profile <span class="pv-h-r"><?= (int)$st_done ?> of 4</span></div>
      <div class="st-prog"><span style="width:<?= (int)($st_done * 25) ?>%"></span></div>
      <div class="st-checks">
        <div class="vf-check <?= $vf_has_pic ? 'ok' : 'todo' ?>"><span class="ic"><i class="<?= $vf_has_pic ? 'ri-check-line' : 'ri-subtract-line' ?>"></i></span><div><strong>Profile picture</strong><span><?= $vf_has_pic ? 'Your picture is set.' : 'Add a clear picture so people recognise you.' ?></span></div><?php if (!$vf_has_pic): ?><a onclick="stGo('modal-account')">Add</a><?php endif; ?></div>
        <div class="vf-check <?= $vf_has_bio ? 'ok' : 'todo' ?>"><span class="ic"><i class="<?= $vf_has_bio ? 'ri-check-line' : 'ri-subtract-line' ?>"></i></span><div><strong>Bio</strong><span><?= $vf_has_bio ? 'Your bio is set.' : 'Tell people who you are in a line or two.' ?></span></div><?php if (!$vf_has_bio): ?><a onclick="stGo('modal-account')">Add</a><?php endif; ?></div>
        <div class="vf-check <?= $st_has_email ? 'ok' : 'todo' ?>"><span class="ic"><i class="<?= $st_has_email ? 'ri-check-line' : 'ri-subtract-line' ?>"></i></span><div><strong>Email</strong><span><?= $st_has_email ? 'Your email is set for notices and recovery.' : 'Add an email so you can recover your account.' ?></span></div><?php if (!$st_has_email): ?><a onclick="stGo('modal-account')">Add</a><?php endif; ?></div>
        <div class="vf-check <?= $st_has_type ? 'ok' : 'todo' ?>"><span class="ic"><i class="<?= $st_has_type ? 'ri-check-line' : 'ri-subtract-line' ?>"></i></span><div><strong>Professional Mode Type</strong><span><?= $st_has_type ? 'Your Professional Mode type is set.' : 'Show what you do, like Meme Creator or Student.' ?></span></div><?php if (!$st_has_type): ?><a onclick="stGo('modal-profiletype')">Add</a><?php endif; ?></div>
      </div>

      <div class="pv-h">Keep your profile safe</div>
      <div class="st-list">
        <div class="st-row st-go" onclick="stGo('modal-password')"><i class="ri-lock-line ic"></i><span class="k">Password &amp; Security</span><i class="ri-arrow-right-s-line menu-chevron"></i></div>
        <div class="st-row st-go" onclick="stGo('modal-privacy')"><i class="ri-eye-off-line ic"></i><span class="k">Privacy Controls</span><i class="ri-arrow-right-s-line menu-chevron"></i></div>
        <div class="st-row st-go" onclick="stGo('modal-blocked')"><i class="ri-shield-user-line ic"></i><span class="k">Moderation</span><i class="ri-arrow-right-s-line menu-chevron"></i></div>
      </div>

      <a class="save-btn-full" href="Account_status.php" style="margin-top:20px"><i class="ri-external-link-line"></i> See full account status</a>
      <div class="ig-note after-list">
        <p>This is a quick snapshot of your profile. For anything more detailed, open the full account status page.</p>
      </div>

    </div></div>
  </div>
</div>

<!-- TRACK REQUESTS -->
<?php
$trk_n = count($pending_requests);
$mod_b = $blocked_users ? $blocked_users->num_rows : 0;
$mod_m = $muted_users ? $muted_users->num_rows : 0;
$mod_r = $restricted_users ? $restricted_users->num_rows : 0;
$X_SVG = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>';
?>
<div class="modal-overlay" id="modal-trackreqs">
  <div class="modal-lane">
    <div class="ac-topbar modal-topbar">
      <button type="button" class="ac-x" onclick="closeModal('modal-trackreqs')" aria-label="Close"><?= $X_SVG ?></button>
      <div class="ac-title left modal-head-title">Track Requests</div>
    </div>
    <div class="modal-body"><div class="mb-in">

      <div class="pv-hero">
        <i class="pv-wm ri-user-received-line"></i>
        <div class="pv-hero-top">
          <span class="pv-hero-ic"><i class="ri-user-received-line"></i></span>
          <div style="min-width:0">
            <div class="pv-hero-l">Waiting for you</div>
            <div class="pv-hero-n" id="trk-hero-n"><?= $trk_n > 0 ? $trk_n . ' request' . ($trk_n === 1 ? '' : 's') . ' waiting' : 'All caught up' ?></div>
          </div>
        </div>
        <p class="pv-hero-s">People who ask to track you appear here. Approve a request to let them in, or deny it to keep them out. Nothing happens until you answer.</p>
        <div class="pv-stats">
          <div class="pv-stat"><b id="trk-stat-wait"><?= (int)$trk_n ?></b><span>Waiting</span></div>
          <div class="pv-stat"><b><?= (int)$followers_count ?></b><span>Trackers</span></div>
          <div class="pv-stat"><b id="trk-stat-lvl" class="sm"><?= htmlspecialchars($priv_labels[$priv_level]) ?></b><span>Your level</span></div>
        </div>
      </div>

      <div class="pv-h">Requests</div>
      <div id="trackRequestsList">
        <?php if (empty($pending_requests)): ?>
          <div class="empty-state">
            <div class="empty-title">No pending requests</div>
            <p class="empty-desc">When someone asks to track you, their request will show up here.</p>
          </div>
        <?php else: foreach ($pending_requests as $req): ?>
          <div class="track-req-row" id="treq-<?= (int)$req['requester_id'] ?>">
            <img src="<?= htmlspecialchars($req['profile_pic'] ?: 'mee.jpg') ?>" onerror="this.src='mee.jpg'" onclick="window.location.href='profile.php?user_id=<?= (int)$req['requester_id'] ?>'" alt="">
            <div class="track-req-meta">
              <strong><?= htmlspecialchars($req['username']) ?><?= !empty($req['is_verified']) ? ' ' . st_badge() : '' ?></strong>
              <small>Wants to track you · <?= date('d M Y · g:i A', strtotime($req['created_at'])) ?></small>
            </div>
            <div class="track-req-btns">
              <button class="treq-approve" onclick="handleTrackRequest('approve', <?= (int)$req['requester_id'] ?>, this)">Approve</button>
              <button class="treq-deny" onclick="handleTrackRequest('deny', <?= (int)$req['requester_id'] ?>, this)">Deny</button>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <div class="pv-h">When you answer</div>
      <div class="pv-box">
        <div class="pv-set"><span class="pv-ic sm"><i class="ri-check-line"></i></span><div class="pv-set-tx"><strong>Approve</strong><span>They become one of your trackers straight away and can see your memes, bio and stats, as far as your privacy level allows.</span></div></div>
        <div class="pv-set"><span class="pv-ic sm"><i class="ri-close-line"></i></span><div class="pv-set-tx"><strong>Deny</strong><span>They are not added as a tracker and the request leaves this list.</span></div></div>
        <div class="pv-set"><span class="pv-ic sm"><i class="ri-user-search-line"></i></span><div class="pv-set-tx"><strong>Look first</strong><span>Tap a profile picture to open that profile before you decide.</span></div></div>
        <div class="pv-set pv-go" onclick="openModal('modal-privacy')" role="button" tabindex="0"><span class="pv-ic sm"><i class="ri-eye-off-line"></i></span><div class="pv-set-tx"><strong>Change your privacy level</strong><span>Requests only arrive on Private and Full Private.</span></div><i class="ri-arrow-right-s-line menu-chevron"></i></div>
      </div>

      <div class="pv-h">Good to know</div>
      <div class="pv-faq-wrap">
        <details class="pv-faq"><summary>Do requests expire?</summary><p>No. Requests stay here until you act on them, so there is no rush.</p></details>
        <details class="pv-faq"><summary>What if I switch to Public or Limited?</summary><p>Track Requests is hidden from your settings and new people can track you without asking. Requests that are still waiting stay here for when you come back.</p></details>
        <details class="pv-faq"><summary>Can I remove someone after approving them?</summary><p>Yes. You can block or restrict any account under Moderation whenever you like.</p></details>
        <details class="pv-faq"><summary>Is there a limit to how many I can approve?</summary><p>No. Approve as many as you want, and answer them in any order.</p></details>
      </div>

    </div></div>
  </div>
</div>

<!-- MODERATION -->
<div class="modal-overlay" id="modal-blocked">
  <div class="modal-lane">
    <div class="ac-topbar modal-topbar">
      <button type="button" class="ac-x" onclick="closeModal('modal-blocked')" aria-label="Close"><?= $X_SVG ?></button>
      <div class="ac-title left modal-head-title">Moderation</div>
    </div>
    <div class="modal-body"><div class="mb-in">

      <div class="pv-hero">
        <i class="pv-wm ri-shield-user-line"></i>
        <div class="pv-hero-top">
          <span class="pv-hero-ic"><i class="ri-shield-user-line"></i></span>
          <div style="min-width:0">
            <div class="pv-hero-l">Your moderation</div>
            <div class="pv-hero-n" id="md-hero-n"><?= ($mod_b + $mod_m + $mod_r) > 0 ? ($mod_b + $mod_m + $mod_r) . ' account' . (($mod_b + $mod_m + $mod_r) === 1 ? '' : 's') . ' managed' : 'Nobody managed yet' ?></div>
          </div>
        </div>
        <p class="pv-hero-s">Keep specific people away without changing your privacy level. Block for no contact, mute to stop seeing their posts, or restrict to move their messages out of your main inbox.</p>
        <div class="pv-stats">
          <div class="pv-stat"><b id="modstat-blocked"><?= (int)$mod_b ?></b><span>Blocked</span></div>
          <div class="pv-stat"><b id="modstat-muted"><?= (int)$mod_m ?></b><span>Muted</span></div>
          <div class="pv-stat"><b id="modstat-restricted"><?= (int)$mod_r ?></b><span>Restricted</span></div>
        </div>
      </div>

      <div class="pv-h">Choose a list</div>
      <div class="mod-seg" role="tablist">
        <button class="mod-tab active" id="modtab-blocked" onclick="switchModTab('blocked')">Blocked <span class="mod-tab-count" id="modcount-blocked"><?= (int)$mod_b ?></span></button>
        <button class="mod-tab" id="modtab-muted" onclick="switchModTab('muted')">Muted <span class="mod-tab-count" id="modcount-muted"><?= (int)$mod_m ?></span></button>
        <button class="mod-tab" id="modtab-restricted" onclick="switchModTab('restricted')">Restricted <span class="mod-tab-count" id="modcount-restricted"><?= (int)$mod_r ?></span></button>
      </div>

      <div class="mod-panel active" id="modpanel-blocked" style="margin-top:12px">
        <div class="mod-list">
        <?php if (!$blocked_users || $blocked_users->num_rows === 0): ?>
          <div class="mod-empty"><div class="empty-title">No blocked accounts</div></div>
        <?php else: $blocked_users->data_seek(0); while ($bu = $blocked_users->fetch_assoc()): ?>
          <div class="mod-user-row" id="blocked-<?= (int)$bu['id'] ?>">
            <img src="<?= htmlspecialchars($bu['profile_pic'] ?: 'mee.jpg') ?>" onerror="this.src='mee.jpg'" onclick="window.location.href='profile.php?user_id=<?= (int)$bu['id'] ?>'" alt="">
            <span class="mod-username" onclick="window.location.href='profile.php?user_id=<?= (int)$bu['id'] ?>'"><?= htmlspecialchars($bu['username']) ?><?= !empty($bu['is_verified']) ? ' ' . st_badge() : '' ?></span>
            <button class="mod-action-btn" onclick="modAction('unblock', <?= (int)$bu['id'] ?>, this)">Unblock</button>
          </div>
        <?php endwhile; endif; ?>
        </div>
        <div class="pv-card static">
          <div class="pv-card-top"><span class="pv-ic"><i class="ri-forbid-2-line"></i></span><div style="min-width:0"><div class="pv-cn">Blocking is the strongest option</div><div class="pv-ct">No contact at all</div></div></div>
          <ul class="pv-pts">
            <li><i class="ri-check-line"></i><span>Blocked accounts can't view your profile or your content</span></li>
            <li><i class="ri-check-line"></i><span>They can't message you on Dezzme</span></li>
            <li><i class="ri-check-line"></i><span>They aren't told when you block them, and you can unblock at any time</span></li>
          </ul>
        </div>
      </div>

      <div class="mod-panel" id="modpanel-muted" style="margin-top:12px">
        <div class="mod-list">
        <?php if (!$muted_users || $muted_users->num_rows === 0): ?>
          <div class="mod-empty"><div class="empty-title">No muted accounts</div></div>
        <?php else: while ($mu = $muted_users->fetch_assoc()): ?>
          <div class="mod-user-row" id="muted-<?= (int)$mu['id'] ?>">
            <img src="<?= htmlspecialchars($mu['profile_pic'] ?: 'mee.jpg') ?>" onerror="this.src='mee.jpg'" onclick="window.location.href='profile.php?user_id=<?= (int)$mu['id'] ?>'" alt="">
            <span class="mod-username" onclick="window.location.href='profile.php?user_id=<?= (int)$mu['id'] ?>'"><?= htmlspecialchars($mu['username']) ?><?= !empty($mu['is_verified']) ? ' ' . st_badge() : '' ?></span>
            <button class="mod-action-btn" onclick="modAction('unmute', <?= (int)$mu['id'] ?>, this)">Unmute</button>
          </div>
        <?php endwhile; endif; ?>
        </div>
        <div class="pv-card static">
          <div class="pv-card-top"><span class="pv-ic"><i class="ri-volume-mute-line"></i></span><div style="min-width:0"><div class="pv-cn">Muting is the quiet option</div><div class="pv-ct">Out of sight, still connected</div></div></div>
          <ul class="pv-pts">
            <li><i class="ri-check-line"></i><span>Muted accounts can still track you and message you</span></li>
            <li><i class="ri-check-line"></i><span>Their posts won't appear in your feed</span></li>
            <li><i class="ri-check-line"></i><span>They're never notified, and unmuting brings their posts back</span></li>
          </ul>
        </div>
      </div>

      <div class="mod-panel" id="modpanel-restricted" style="margin-top:12px">
        <div class="mod-list">
        <?php if (!$restricted_users || $restricted_users->num_rows === 0): ?>
          <div class="mod-empty"><div class="empty-title">No restricted accounts</div></div>
        <?php else: while ($ru = $restricted_users->fetch_assoc()): ?>
          <div class="mod-user-row" id="restricted-<?= (int)$ru['id'] ?>">
            <img src="<?= htmlspecialchars($ru['profile_pic'] ?: 'mee.jpg') ?>" onerror="this.src='mee.jpg'" onclick="window.location.href='profile.php?user_id=<?= (int)$ru['id'] ?>'" alt="">
            <span class="mod-username" onclick="window.location.href='profile.php?user_id=<?= (int)$ru['id'] ?>'"><?= htmlspecialchars($ru['username']) ?><?= !empty($ru['is_verified']) ? ' ' . st_badge() : '' ?></span>
            <button class="mod-action-btn" onclick="modAction('unrestrict', <?= (int)$ru['id'] ?>, this)">Remove</button>
          </div>
        <?php endwhile; endif; ?>
        </div>
        <div class="pv-card static">
          <div class="pv-card-top"><span class="pv-ic"><i class="ri-inbox-line"></i></span><div style="min-width:0"><div class="pv-cn">Restricting is a middle ground</div><div class="pv-ct">Messages kept apart</div></div></div>
          <ul class="pv-pts">
            <li><i class="ri-check-line"></i><span>Restricted accounts can still track you and message you</span></li>
            <li><i class="ri-check-line"></i><span>Their messages go to a separate requests folder, not your main inbox</span></li>
            <li><i class="ri-check-line"></i><span>They're never notified, and removing the restriction puts things back to normal</span></li>
          </ul>
        </div>
      </div>

      <div class="pv-h">Which one should I use?</div>
      <div class="pv-mx" id="md-mx">
        <div class="pv-mx-r pv-mx-h mx3"><span></span><span data-md="blocked" class="sel">Block</span><span data-md="muted">Mute</span><span data-md="restricted">Restrict</span></div>
        <?php
        $md_rows = [
          ['See your profile and memes', ['n', 'a', 'a']],
          ['Track you', ['n', 'a', 'a']],
          ['Their posts show in your feed', ['n', 'n', 'a']],
          ['Message you', ['n', 'a', 't']],
        ];
        $md_icons = ['a' => ['ri-check-line', 'pv-a', 'Yes'], 't' => ['ri-inbox-line', 'pv-t', 'Goes to requests folder'], 'n' => ['ri-close-line', 'pv-n', 'No']];
        $md_keys = ['blocked', 'muted', 'restricted'];
        foreach ($md_rows as $row): ?>
        <div class="pv-mx-r mx3"><span><?= htmlspecialchars($row[0]) ?></span><?php foreach ($row[1] as $i => $c): ?><span data-md="<?= $md_keys[$i] ?>"<?= $i === 0 ? ' class="sel"' : '' ?> title="<?= $md_icons[$c][2] ?>"><i class="<?= $md_icons[$c][0] ?> <?= $md_icons[$c][1] ?>"></i></span><?php endforeach; ?></div>
        <?php endforeach; ?>
      </div>
      <div class="pv-legend">
        <span><i class="ri-check-line pv-a"></i>Yes</span>
        <span><i class="ri-inbox-line pv-t"></i>Goes to requests folder</span>
        <span><i class="ri-close-line pv-n"></i>No</span>
      </div>

      <div class="pv-h">Good to know</div>
      <div class="pv-faq-wrap">
        <details class="pv-faq"><summary>Will they know I blocked, muted or restricted them?</summary><p>No. None of the three notify the other person, and nothing looks different on their side.</p></details>
        <details class="pv-faq"><summary>Can I change my mind later?</summary><p>Yes. Unblock, unmute or remove the restriction from the lists above and things go back to normal straight away.</p></details>
        <details class="pv-faq"><summary>Do I need these if my profile is private?</summary><p>They work alongside your privacy level. Your level controls who can see your profile, and these tools deal with specific accounts.</p></details>
      </div>

    </div></div>
  </div>
</div>

<!-- DEACTIVATE OR DELETE ACCOUNT -->
<div class="modal-overlay" id="modal-danger">
  <div class="modal-lane">
    <div class="ac-topbar modal-topbar">
      <button type="button" class="ac-x" onclick="closeModal('modal-danger')" aria-label="Close"><?= $X_SVG ?></button>
      <div class="ac-title left modal-head-title">Deactivate or delete account</div>
    </div>
    <div class="modal-body"><div class="mb-in">

      <div class="pv-hero">
        <i class="pv-wm ri-delete-bin-line"></i>
        <div class="pv-hero-top">
          <span class="pv-hero-ic"><i class="ri-error-warning-line"></i></span>
          <div style="min-width:0">
            <div class="pv-hero-l">Account control</div>
            <div class="pv-hero-n">Take a break, or leave Dezzme</div>
          </div>
        </div>
        <p class="pv-hero-s">Deactivating hides your account until you come back. Deleting removes it for good after <?= (int)DEL_GRACE_DAYS ?> days. You will be signed out on this device either way.</p>
      </div>

      <?php if ($del_pending): ?>
      <div class="pv-h">Deletion scheduled</div>
      <div class="dg-pending" id="dg-pending">
        <i class="ri-time-line"></i>
        <div>Your account will be deleted on <b><?= htmlspecialchars($del_date) ?></b>. Until then you can cancel and keep everything.
          <div class="hle-btns"><button type="button" class="hle-btn accent" onclick="cancelDelete(this)">Cancel deletion</button></div>
        </div>
      </div>
      <?php endif; ?>

      <div class="pv-h">Choose what to do</div>
      <div class="pv-levels" id="dg-levels" role="radiogroup" aria-label="Deactivate or delete">
        <button type="button" class="pv-card on" data-dg="deactivate" role="radio" aria-checked="true" onclick="dgPick('deactivate')">
          <div class="pv-card-top">
            <span class="pv-ic"><i class="ri-pause-circle-line"></i></span>
            <div style="min-width:0"><div class="pv-cn">Deactivate</div><div class="pv-ct">Hide your account for now</div></div>
            <span class="pv-radio"></span>
          </div>
          <p class="pv-cd">A break from Dezzme. Nothing is deleted, and you can come back whenever you like.</p>
          <ul class="pv-pts">
            <li><i class="ri-check-line"></i><span>Your profile, memes and highlights are hidden from everyone</span></li>
            <li><i class="ri-check-line"></i><span>Your trackers, tracking and pods are kept as they are</span></li>
            <li><i class="ri-check-line"></i><span>Log in again at any time to turn your account back on</span></li>
          </ul>
        </button>
        <button type="button" class="pv-card" data-dg="delete" role="radio" aria-checked="false" onclick="dgPick('delete')">
          <div class="pv-card-top">
            <span class="pv-ic"><i class="ri-delete-bin-line"></i></span>
            <div style="min-width:0"><div class="pv-cn">Delete permanently</div><div class="pv-ct"><?= (int)DEL_GRACE_DAYS ?> days to change your mind</div></div>
            <span class="pv-radio"></span>
          </div>
          <p class="pv-cd">Your account is hidden straight away, then removed for good after <?= (int)DEL_GRACE_DAYS ?> days.</p>
          <ul class="pv-pts">
            <li><i class="ri-check-line"></i><span>Log in within <?= (int)DEL_GRACE_DAYS ?> days and the deletion is cancelled</span></li>
            <li><i class="ri-check-line"></i><span>After that, your profile, memes, highlights, trackers and messages are gone and can't be recovered</span></li>
            <li><i class="ri-check-line"></i><span>Your username may become available for someone else to use</span></li>
          </ul>
        </button>
      </div>

      <div class="pv-h">Confirm it's you</div>
      <div class="dg-form">
        <label class="dg-label" for="dg-pw">Your password</label>
        <input type="password" class="f-input" id="dg-pw" placeholder="Enter your password" autocomplete="current-password">
        <div id="dg-confirm-wrap" style="display:none">
          <label class="dg-label" for="dg-confirm" style="display:block;margin-bottom:7px">Type DELETE to confirm</label>
          <input type="text" class="f-input" id="dg-confirm" placeholder="DELETE" autocomplete="off" autocapitalize="characters" style="width:100%">
        </div>
        <div id="dg-feedback" class="field-feedback" aria-live="polite"></div>
        <button type="button" class="save-btn-full" id="dg-go" style="margin-top:0" onclick="dgSubmit(this)">Deactivate my account</button>
      </div>

      <div class="pv-h">Good to know</div>
      <div class="pv-faq-wrap">
        <details class="pv-faq"><summary>What is the difference between deactivating and deleting?</summary><p>Deactivating is a pause. Everything is kept and your account returns when you log in. Deleting starts a countdown, and when it ends everything is removed for good.</p></details>
        <details class="pv-faq"><summary>Can I change my mind after deleting?</summary><p>Yes, for <?= (int)DEL_GRACE_DAYS ?> days. Log in again and your deletion is cancelled. After that it can't be undone.</p></details>
        <details class="pv-faq"><summary>Will people be told?</summary><p>No. Your trackers are not notified. Your profile and memes simply stop showing while your account is deactivated or waiting to be deleted.</p></details>
        <details class="pv-faq"><summary>I just want fewer people to see me</summary><p>You don't have to leave for that. Try Privacy Controls to go Private, or use Moderation to block, mute or restrict specific accounts.</p></details>
      </div>

    </div></div>
  </div>
</div>

<!-- DEZZME CREATOR TOOLS -->
<?php
$creator_tools = [
  'Meme makers (images)' => ['ri-image-add-line', [
    ['Imgflip Meme Generator','https://imgflip.com/memegenerator','Browser','Pick a popular template and add captions in seconds.'],
    ['Kapwing Meme Maker','https://www.kapwing.com/meme-maker','Browser','Caption images, GIFs and videos right in your browser.'],
    ['Canva Meme Maker','https://www.canva.com/create/memes/','Browser, Phone','Meme templates with full design tools behind them.'],
    ['Adobe Express Meme Maker','https://www.adobe.com/express/create/meme','Browser, Phone','Quick meme layouts with fonts, stickers and effects.'],
    ['Make a Meme','https://makeameme.org','Browser','Simple top and bottom text memes with no sign up.'],
    ['Meme Generator','https://memegenerator.net','Browser','Classic meme templates and a huge community library.'],
    ['BeFunky','https://www.befunky.com','Browser','Photo editor with collage, text and meme tools.'],
  ]],
  'Image editors' => ['ri-brush-line', [
    ['Photopea','https://www.photopea.com','Browser','A Photoshop style editor that runs in your browser.'],
    ['Pixlr','https://pixlr.com','Browser, Phone','Fast editor for layers, cutouts, filters and text.'],
    ['GIMP','https://www.gimp.org','Desktop','Free and powerful desktop image editor.'],
    ['Krita','https://krita.org','Desktop','Free painting and illustration app for original art.'],
    ['Paint.NET','https://www.getpaint.net','Windows','Light, quick editor for cropping and text.'],
    ['Adobe Photoshop','https://www.adobe.com/products/photoshop.html','Desktop','The industry standard for photo editing (paid).'],
    ['Figma','https://www.figma.com','Browser, Desktop','Design layouts, comic panels and multi image memes.'],
    ['Remove.bg','https://www.remove.bg','Browser','Remove a photo background in one click.'],
  ]],
  'GIF makers' => ['ri-file-gif-line', [
    ['GIPHY GIF Maker','https://giphy.com/create/gifmaker','Browser','Turn videos, images and links into GIFs.'],
    ['Tenor GIF Maker','https://tenor.com/gif-maker','Browser','Make GIFs from clips, photos or your webcam.'],
    ['Ezgif','https://ezgif.com','Browser','Crop, resize, reverse, speed up and add text to GIFs.'],
    ['ScreenToGif','https://www.screentogif.com','Windows','Record your screen and save it as a GIF.'],
    ['LICEcap','https://www.cockos.com/licecap/','Windows, Mac','Tiny screen recorder that saves straight to GIF.'],
    ['Gifski','https://gif.ski','Mac, Browser','Convert videos to high quality GIFs.'],
  ]],
  'Video editors' => ['ri-movie-line', [
    ['CapCut','https://www.capcut.com','Browser, Phone, Desktop','Popular editor with captions, effects and templates.'],
    ['InShot','https://inshot.com','Phone','Easy phone editor for trims, text and music.'],
    ['Clipchamp','https://clipchamp.com','Browser, Windows','Simple timeline editor with stock clips and captions.'],
    ['VEED Meme Maker','https://www.veed.io/tools/meme-maker','Browser','Add text and subtitles to video memes.'],
    ['Kapwing Studio','https://www.kapwing.com','Browser','Full online video editor with collaboration.'],
    ['DaVinci Resolve','https://www.blackmagicdesign.com/products/davinciresolve','Desktop','Pro editing and colour tools with a free version.'],
    ['Shotcut','https://shotcut.org','Desktop','Free, open source video editor.'],
    ['OpenShot','https://www.openshot.org','Desktop','Free editor that is easy to learn.'],
    ['Kdenlive','https://kdenlive.org','Desktop','Free, open source multi track video editor.'],
    ['Adobe Premiere Pro','https://www.adobe.com/products/premiere.html','Desktop','Professional video editing (paid).'],
    ['OBS Studio','https://obsproject.com','Desktop','Free screen and game recording.'],
    ['HandBrake','https://handbrake.fr','Desktop','Convert and shrink videos so they upload faster.'],
  ]],
  'Audio and voice' => ['ri-music-2-line', [
    ['Audacity','https://www.audacityteam.org','Desktop','Free audio recorder and editor.'],
    ['BandLab','https://www.bandlab.com','Browser, Phone','Record and mix music and voice online.'],
    ['TTSMaker','https://ttsmaker.com','Browser','Turn text into speech in many voices.'],
    ['Voicemod','https://www.voicemod.net','Desktop','Change your voice and add sound effects live.'],
    ['Myinstants','https://www.myinstants.com','Browser','Huge soundboard of meme sounds.'],
    ['Freesound','https://freesound.org','Browser','Free sound effects shared by the community.'],
  ]],
  'AI image and video' => ['ri-sparkling-line', [
    ['Microsoft Designer','https://designer.microsoft.com','Browser','Generate images and layouts from a short prompt.'],
    ['Bing Image Creator','https://www.bing.com/images/create','Browser','Create images from text prompts.'],
    ['Adobe Firefly','https://firefly.adobe.com','Browser','AI images and effects from Adobe.'],
    ['Leonardo AI','https://leonardo.ai','Browser','AI image generation with many art styles.'],
    ['Ideogram','https://ideogram.ai','Browser','AI images that can include readable text.'],
    ['Craiyon','https://www.craiyon.com','Browser','Free and quick AI image generator.'],
    ['Runway','https://runwayml.com','Browser','AI video generation and editing.'],
  ]],
  'Templates and meme research' => ['ri-book-open-line', [
    ['Know Your Meme','https://knowyourmeme.com','Browser','Find out where a meme came from before you remix it.'],
    ['Imgflip Templates','https://imgflip.com/memetemplates','Browser','Browse thousands of ready to use templates.'],
    ['r/MemeTemplatesOfficial','https://www.reddit.com/r/MemeTemplatesOfficial/','Browser','Community shared blank meme templates.'],
    ['GIPHY','https://giphy.com','Browser','Search GIFs and stickers for reactions.'],
    ['Tenor','https://tenor.com','Browser','Search reaction GIFs.'],
  ]],
  'Free images, fonts and extras' => ['ri-gallery-line', [
    ['Pexels','https://www.pexels.com','Browser','Free photos and videos for backgrounds.'],
    ['Pixabay','https://pixabay.com','Browser','Free images, videos and sounds.'],
    ['Unsplash','https://unsplash.com','Browser','Free high quality photos.'],
    ['Google Fonts','https://fonts.google.com','Browser','Free fonts to give your memes a look.'],
    ['DaFont','https://www.dafont.com','Browser','Thousands of fun and bold fonts.'],
  ]],
  'Compress and convert' => ['ri-tools-line', [
    ['Squoosh','https://squoosh.app','Browser','Shrink images without losing quality.'],
    ['TinyPNG','https://tinypng.com','Browser','Compress PNG and JPG files.'],
    ['CloudConvert','https://cloudconvert.com','Browser','Convert almost any image, video or audio format.'],
  ]],
];
$ct_total = 0; foreach ($creator_tools as $c) $ct_total += count($c[1]);
?>
<div class="modal-overlay" id="modal-creator">
  <div class="modal-lane">
    <div class="ac-topbar modal-topbar">
      <button type="button" class="ac-x" onclick="closeModal('modal-creator')" aria-label="Close"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button>
      <div class="ac-title left modal-head-title">Dezzme Creator Tools</div>
    </div>
    <div class="pt-toolbar">
      <div class="pt-search">
        <i class="ri-search-line"></i>
        <input type="text" id="ct-search" placeholder="Search e.g. GIF, video, voice, AI…" autocomplete="off">
        <button type="button" class="pt-clear-search" id="ct-clear" aria-label="Clear search"><i class="ri-close-line"></i></button>
      </div>
      <div class="pt-current"><span><strong id="ct-count"><?= (int)$ct_total ?></strong> tools to help you create</span></div>
    </div>
    <div class="modal-body"><div class="mb-in">
      <div class="ig-note pt-intro">
        <p>Everything here helps you make memes outside Dezzme. Pick a tool, create your meme, save it as a JPG, PNG, GIF or MP4, then come back and upload it to Dezzme.</p>
        <p>Tools open in a new tab on their own websites. Many are free, and some have paid plans or add a watermark to free exports, so check each site before you start. These tools are not made by Dezzme.</p>
      </div>
      <div id="ct-list">
      <?php foreach ($creator_tools as $cat => $info): ?>
        <div class="ct-cat">
          <div class="pt-cat-title"><?= htmlspecialchars($cat) ?></div>
          <div class="ct-list">
          <?php foreach ($info[1] as $t): ?>
            <a class="ct-row" href="<?= htmlspecialchars($t[1]) ?>" target="_blank" rel="noopener noreferrer" data-search="<?= htmlspecialchars(strtolower($t[0] . ' ' . $t[2] . ' ' . $t[3] . ' ' . $cat)) ?>">
              <span class="ct-ic"><i class="<?= htmlspecialchars($info[0]) ?>"></i></span>
              <span class="ct-tx">
                <span class="ct-tn"><b><?= htmlspecialchars($t[0]) ?></b><small><?= htmlspecialchars($t[2]) ?></small></span>
                <span class="ct-td"><?= htmlspecialchars($t[3]) ?></span>
              </span>
              <i class="ri-external-link-line ct-go"></i>
            </a>
          <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
      </div>
      <div class="pt-none" id="ct-none"><i class="ri-search-eye-line"></i>No tools match your search</div>
    </div></div>
  </div>
</div>

<!-- TURN OFF NOTIFICATIONS -->
<div class="modal-overlay" id="modal-notifs">
  <div class="modal-lane">
    <div class="ac-topbar modal-topbar">
      <button type="button" class="ac-x" onclick="closeModal('modal-notifs')" aria-label="Close"><?= $X_SVG ?></button>
      <div class="ac-title left modal-head-title">Notifications</div>
    </div>
    <div class="modal-body"><div class="mb-in">

      <div class="pv-hero">
        <i class="pv-wm ri-notification-3-line"></i>
        <div class="pv-hero-top">
          <span class="pv-hero-ic"><i id="nt-ic" class="<?= $notif_on ? 'ri-notification-3-line' : 'ri-notification-off-line' ?>"></i></span>
          <div style="min-width:0">
            <div class="pv-hero-l">Your notifications</div>
            <div class="pv-hero-n" id="nt-state"><?= $notif_on ? 'Notifications are on' : 'Notifications are off' ?></div>
          </div>
        </div>
        <p class="pv-hero-s" id="nt-sub"></p>
      </div>

      <div class="pv-h">Switch</div>
      <div class="pv-box">
        <div class="pv-set">
          <span class="pv-ic sm"><i class="ri-notification-3-line"></i></span>
          <div class="pv-set-tx"><strong>Notifications</strong><span>Turn this off and Dezzme stops sending you notifications completely. Turn it back on any time.</span></div>
          <label class="switch"><input type="checkbox" data-pf="notifications_enabled" <?= $notif_on ? 'checked' : '' ?> onchange="ntChange(this)"><span class="slider"></span></label>
        </div>
      </div>

      <div class="pv-h">When notifications are off</div>
      <div class="pv-box">
        <div class="pv-set"><span class="pv-ic sm"><i class="ri-notification-off-line"></i></span><div class="pv-set-tx"><strong>The bell goes quiet</strong><span>The notification bell disappears and no counts, alerts or pop-ups show up.</span></div></div>
        <div class="pv-set"><span class="pv-ic sm"><i class="ri-smartphone-line"></i></span><div class="pv-set-tx"><strong>No browser alerts</strong><span>You won't get alerts on your phone or computer from Dezzme.</span></div></div>
        <div class="pv-set"><span class="pv-ic sm"><i class="ri-chat-3-line"></i></span><div class="pv-set-tx"><strong>Everything still works</strong><span>Messages stay in your inbox and track requests stay under Track Requests. You just aren't alerted.</span></div></div>
      </div>

      <div class="pv-h">Good to know</div>
      <div class="pv-faq-wrap">
        <details class="pv-faq"><summary>Will I miss things while it's off?</summary><p>Nothing is deleted. You just won't see alerts. When you turn notifications back on, you only see new activity from that point.</p></details>
        <details class="pv-faq"><summary>Does this tell other people?</summary><p>No. Nobody is told that you turned notifications off.</p></details>
        <details class="pv-faq"><summary>Is this the same as muting someone?</summary><p>No. This switches off all notifications. To silence one person, use Mute or Block under Moderation.</p></details>
      </div>

    </div></div>
  </div>
</div>

<!-- TIME MANAGEMENT -->
<div class="modal-overlay" id="modal-time">
  <div class="modal-lane">
    <div class="ac-topbar modal-topbar">
      <button type="button" class="ac-x" onclick="closeModal('modal-time')" aria-label="Close"><?= $X_SVG ?></button>
      <div class="ac-title left modal-head-title">Time management</div>
    </div>
    <div class="modal-body"><div class="mb-in">

      <div class="pv-hero">
        <i class="pv-wm ri-time-line"></i>
        <div class="pv-hero-top">
          <span class="pv-hero-ic"><i class="ri-time-line"></i></span>
          <div style="min-width:0">
            <div class="pv-hero-l">Your daily average</div>
            <div class="pv-hero-n" id="tm-avg">0m</div>
          </div>
        </div>
        <p class="pv-hero-s" id="tm-avg-sub">Time you spend on Dezzme each day, on average.</p>
        <div class="pv-stats">
          <div class="pv-stat"><b id="tm-today">0m</b><span>Today</span></div>
          <div class="pv-stat"><b id="tm-week">0m</b><span>Last 7 days</span></div>
          <div class="pv-stat"><b id="tm-busy">-</b><span>Busiest day</span></div>
        </div>
      </div>

      <div class="pv-h">Time on Dezzme</div>
      <div class="pv-seg" id="tm-seg" role="radiogroup" aria-label="Range">
        <button type="button" class="on" data-r="7" role="radio" aria-checked="true">7 days</button>
        <button type="button" data-r="30" role="radio" aria-checked="false">30 days</button>
      </div>

      <div class="tm-chart">
        <div class="tm-plot">
          <div class="tm-gl t"><span id="tm-top">0m</span></div>
          <div class="tm-gl m"><span id="tm-mid">0m</span></div>
          <div class="tm-gl b"><span>0</span></div>
          <div class="tm-bars" id="tm-bars"></div>
          <div class="tm-avgline" id="tm-avgline"><b id="tm-avglab">avg</b></div>
        </div>
        <div class="tm-axis" id="tm-axis"></div>
      </div>
      <div class="tm-read" id="tm-read">Tap a bar to see that day.</div>

      <div class="ig-note after-list">
        <p>The bars show how long you spent on Dezzme each day. The dashed line is your daily average for the days shown, and today is the white bar.</p>
        <p>Time counts while Dezzme is open and you are active. It stops when the tab is hidden or you have been idle for a minute. Only you can see this chart.</p>
      </div>

    </div></div>
  </div>
</div>

<!-- BIRTHDAYS -->
<div class="modal-overlay" id="modal-birthdays">
  <div class="modal-lane">
    <div class="ac-topbar modal-topbar">
      <button type="button" class="ac-x" onclick="closeModal('modal-birthdays')" aria-label="Close"><?= $X_SVG ?></button>
      <div class="ac-title left modal-head-title">Birthdays</div>
    </div>
    <div class="modal-body"><div class="mb-in">

      <div class="pv-hero">
        <i class="pv-wm ri-cake-2-line"></i>
        <div class="pv-hero-top">
          <span class="pv-hero-ic"><i class="ri-cake-2-line"></i></span>
          <div style="min-width:0">
            <div class="pv-hero-l">People you track</div>
            <div class="pv-hero-n"><?php
              if ($bd_today_n > 0) echo (int)$bd_today_n . ' birthday' . ($bd_today_n === 1 ? '' : 's') . ' today';
              elseif ($bd_list) echo htmlspecialchars('Next: ' . $bd_list[0]['name'] . ' · ' . $bd_list[0]['date']);
              else echo 'No birthdays yet';
            ?></div>
          </div>
        </div>
        <p class="pv-hero-s">Every birthday from the people you track, starting with the one coming up next.</p>
        <div class="pv-stats">
          <div class="pv-stat"><b><?= count($bd_list) ?></b><span>Birthdays</span></div>
          <div class="pv-stat"><b><?= (int)$bd_today_n ?></b><span>Today</span></div>
          <div class="pv-stat"><b><?= (int)$bd_month_n ?></b><span>Next 30 days</span></div>
        </div>
      </div>

      <?php if ($bd_list): ?>
      <input type="text" class="f-input" id="bd-search" placeholder="Search people…" autocomplete="off" style="flex:none;width:100%;margin-top:14px">
      <div id="bd-list">
        <?php foreach ($bd_groups as $month => $items): ?>
        <div class="bd-group">
          <div class="pv-h"><?= htmlspecialchars($month) ?></div>
          <?php foreach ($items as $x):
            $when = $x['days'] === 0 ? 'Today' : ($x['days'] === 1 ? 'Tomorrow' : 'in ' . $x['days'] . ' days');
            $cls  = $x['days'] === 0 ? 'today' : ($x['days'] <= 7 ? 'soon' : ''); ?>
          <a class="bd-row<?= $x['days'] === 0 ? ' is-today' : '' ?>" href="profile.php?user_id=<?= (int)$x['id'] ?>" data-name="<?= htmlspecialchars(strtolower($x['name'] . ' ' . $x['user'])) ?>">
            <img src="<?= htmlspecialchars($x['pic']) ?>" onerror="this.src='mee.jpg'" alt="">
            <div class="bd-info"><b><?= htmlspecialchars($x['name']) ?><?= !empty($x['ver']) ? ' ' . st_badge() : '' ?></b><span>@<?= htmlspecialchars($x['user']) ?></span></div>
            <div class="bd-when"><b><?= $x['days'] === 0 ? '🎂 ' : '' ?><?= htmlspecialchars($x['date']) ?></b><span class="<?= $cls ?>"><?= htmlspecialchars($when) ?></span></div>
          </a>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="pt-none" id="bd-none"><i class="ri-search-eye-line"></i>No one matches your search</div>
      <?php else: ?>
      <div class="empty-state">
        <div class="empty-title">No birthdays to show</div>
        <p class="empty-desc">When the people you track add their birthday to their profile, it will show up here.</p>
      </div>
      <?php endif; ?>

      <?php if ($bd_missing > 0): ?>
      <div class="ig-note after-list"><p><?= (int)$bd_missing ?> <?= $bd_missing === 1 ? 'person you track hasn\'t' : 'people you track haven\'t' ?> shared a birthday. Only the day and month are shown, never the year.</p></div>
      <?php endif; ?>

    </div></div>
  </div>
</div>

<!-- PRIVACY CHECK-UP -->
<div class="modal-overlay" id="modal-checkup">
  <div class="modal-lane">
    <div class="ac-topbar modal-topbar">
      <button type="button" class="ac-x" onclick="closeModal('modal-checkup')" aria-label="Close"><?= $X_SVG ?></button>
      <div class="ac-title left modal-head-title">Privacy check-up</div>
    </div>
    <div class="modal-body"><div class="mb-in">

      <div class="pv-hero">
        <i class="pv-wm ri-shield-check-line"></i>
        <div class="pv-hero-top">
          <span class="pv-hero-ic"><i class="ri-shield-check-line"></i></span>
          <div style="min-width:0">
            <div class="pv-hero-l">Your privacy right now</div>
            <div class="pv-hero-n" id="ck-hero-n">Balanced</div>
          </div>
        </div>
        <div class="pv-meter" id="ck-meter"><span></span><span></span><span></span><span></span></div>
        <div class="pv-meter-l"><?php foreach ($pv_meter_words as $w): ?><span><?= $w ?></span><?php endforeach; ?></div>
        <p class="pv-hero-s" id="ck-hero-s"></p>
      </div>

      <div class="pv-h">Who can see and reach you</div>
      <div class="ck-list" id="ck-list"></div>

      <div class="pv-h">What's hidden or kept away</div>
      <div class="ck-list" id="ck-hidden"></div>

      <button type="button" class="save-btn-full" id="ck-ok" style="margin-top:18px" onclick="ckOk(this)">Looks good</button>
      <div class="ck-last" id="ck-last"></div>

      <div class="ig-note after-list">
        <p>Tap any line to change it. Colours show how open each setting is: green is open to anyone, yellow is limited to your trackers and red is closed. Tapping Looks good just records the date you last reviewed your privacy.</p>
      </div>

    </div></div>
  </div>
</div>

<!-- TIME ZONE -->
<div class="modal-overlay" id="modal-timezone">
  <div class="modal-lane">
    <div class="ac-topbar modal-topbar">
      <button type="button" class="ac-x" onclick="closeModal('modal-timezone')" aria-label="Close"><?= $X_SVG ?></button>
      <div class="ac-title left modal-head-title">Time zone</div>
    </div>
    <div class="modal-body"><div class="mb-in">

      <div class="pv-hero">
        <i class="pv-wm ri-earth-line"></i>
        <div class="pv-hero-top">
          <span class="pv-hero-ic"><i class="ri-earth-line"></i></span>
          <div style="min-width:0">
            <div class="pv-hero-l">Your time zone</div>
            <div class="pv-hero-n" id="tz-hero-n"><?= $tz_cur !== '' ? htmlspecialchars(str_replace('_', ' ', $tz_cur)) : 'Dezzme server time' ?></div>
          </div>
        </div>
        <p class="pv-hero-s">Your day starts and ends at midnight in this time zone. Birthdays, Time management and your daily notifications all follow it.</p>
        <div class="pv-stats">
          <div class="pv-stat"><b id="tz-time">--:--</b><span>Time now</span></div>
          <div class="pv-stat"><b id="tz-date" class="sm">-</b><span>Date</span></div>
          <div class="pv-stat"><b id="tz-off" class="sm">-</b><span>UTC offset</span></div>
        </div>
      </div>

      <div class="tz-btns">
        <button type="button" class="hle-btn accent" onclick="tzDetect()"><i class="ri-map-pin-line" style="margin-right:5px"></i>Detect my time zone</button>
        <button type="button" class="hle-btn" onclick="tzSelect('')">Use server time</button>
      </div>

      <input type="text" class="f-input" id="tz-search" placeholder="Search e.g. Lagos, London, New York…" autocomplete="off" style="flex:none;width:100%;margin-top:12px">
      <div class="tz-list" id="tz-list">
        <?php foreach ($tz_rows as $tzr): ?>
        <button type="button" class="tz-row<?= $tzr[0] === $tz_cur ? ' on' : '' ?>" data-tz="<?= htmlspecialchars($tzr[0]) ?>" data-off="<?= htmlspecialchars($tzr[1]) ?>" data-s="<?= htmlspecialchars(strtolower(str_replace(['_', '/'], ' ', $tzr[0]))) ?>">
          <span class="tz-n"><?= htmlspecialchars(str_replace('_', ' ', $tzr[0])) ?></span><span class="tz-o">UTC<?= htmlspecialchars($tzr[1]) ?></span><span class="tz-radio"></span>
        </button>
        <?php endforeach; ?>
      </div>
      <div class="pt-none" id="tz-none"><i class="ri-search-eye-line"></i>No time zone matches your search</div>

      <button type="button" class="save-btn-full" id="tz-save" style="margin-top:14px" onclick="tzSave(this)" disabled>Save time zone</button>

      <div class="ig-note after-list">
        <p>Tap Detect and Dezzme picks the time zone your phone or computer is using. If you never choose one, Dezzme uses its own server time, so your "today" may start a few hours early or late.</p>
        <p>Changing it only moves the start of your day. Nothing you posted is changed.</p>
      </div>

    </div></div>
  </div>
</div>

<!-- APPEARANCE -->
<div class="modal-overlay" id="modal-appearance">
  <div class="modal-lane">
    <div class="ac-topbar modal-topbar">
      <button type="button" class="ac-x" onclick="closeModal('modal-appearance')" aria-label="Close"><?= $X_SVG ?></button>
      <div class="ac-title left modal-head-title">Appearance</div>
    </div>
    <div class="modal-body"><div class="mb-in">
      <button type="button" class="pkbm-theme-row" id="dzThemeRow" role="switch" aria-checked="true" onclick="dzToggleTheme()">
        <span class="pt-ic"><i class="ri-moon-line"></i></span>
        <span class="pt-tx"><b>Dark mode</b><span>Midnight blue. Turn off for the white look.</span></span>
        <span class="pt-sw"></span>
      </button>
      <div class="pkbm-theme-btns" id="dzThemeBtns">
        <button type="button" class="hle-btn" data-t="dark" onclick="dzApplyTheme(false)"><i class="ri-moon-line" style="margin-right:5px"></i>Dark mode</button>
        <button type="button" class="hle-btn" data-t="light" onclick="dzApplyTheme(true)"><i class="ri-sun-line" style="margin-right:5px"></i>Light mode</button>
      </div>
      <div class="ig-note after-list">
        <p>Dark mode is Midnight blue. Light mode is white. Your choice is saved on this device and used across Dezzme.</p>
      </div>
      <div class="pv-h" style="margin-top:18px">Haptic feedback</div>
      <div class="pv-box">
        <div class="pv-set">
          <span class="pv-ic sm"><i class="ri-vibrate-line"></i></span>
          <div class="pv-set-tx"><strong>Haptic feedback</strong><span>A tiny vibration when you like, repost, save, track and open things. Works on phones that allow it; on iPhone it is a soft tap on newer iOS versions.</span></div>
          <label class="switch"><input type="checkbox" data-pf="haptics_enabled" <?= $haptics_on ? 'checked' : '' ?> onchange="prField('haptics_enabled', this.checked ? 1 : 0)"><span class="slider"></span></label>
        </div>
      </div>
    </div></div>
  </div>
</div>

<!-- DEFAULT POST SETTINGS -->
<?php
$pd_defs = [
  ['def_hide_likes',     'ri-heart-3-line',      'Hide like counts',   'Nobody but you sees how many likes a new post has.'],
  ['def_hide_reposts',   'ri-repeat-2-line',     'Hide repost counts', 'Keep the number of reposts private on new posts.'],
  ['def_hide_saves',     'ri-bookmark-line',     'Hide save counts',   'Keep the number of saves private on new posts.'],
  ['def_hide_shares',    'ri-share-forward-line','Hide share counts',  'Keep the number of shares private on new posts.'],
  ['def_ai_label',       'ri-robot-2-line',      'Label as AI-generated', 'New posts start with the AI label already on. You can change it on any post.'],
  ['def_allow_download', 'ri-download-2-line',   'Allow downloads',    'Let other people download your photos and videos. Turn it off and the Download button is hidden on new posts.'],
];
?>
<div class="modal-overlay" id="modal-postdef">
  <div class="modal-lane">
    <div class="ac-topbar modal-topbar">
      <button type="button" class="ac-x" onclick="closeModal('modal-postdef')" aria-label="Close"><?= $X_SVG ?></button>
      <div class="ac-title left modal-head-title">Default post settings</div>
    </div>
    <div class="modal-body"><div class="mb-in">
      <div class="pv-hero">
        <i class="pv-wm ri-edit-box-line"></i>
        <div class="pv-hero-top">
          <span class="pv-hero-ic"><i class="ri-edit-box-line"></i></span>
          <div style="min-width:0">
            <div class="pv-hero-l">New posts</div>
            <div class="pv-hero-n">Set it once</div>
          </div>
        </div>
        <p class="pv-hero-s">These choices are applied to every post you make from now on. Posts you already made are not changed, and you can still change any single post from its menu.</p>
      </div>
      <div class="pv-h">Start every new post with</div>
      <div class="pv-box">
        <?php foreach ($pd_defs as $d): ?>
        <div class="pv-set">
          <span class="pv-ic sm"><i class="<?= $d[1] ?>"></i></span>
          <div class="pv-set-tx"><strong><?= htmlspecialchars($d[2]) ?></strong><span><?= htmlspecialchars($d[3]) ?></span></div>
          <label class="switch"><input type="checkbox" data-pf="<?= $d[0] ?>" <?= (int)($d[0] === 'def_allow_download' ? ($settings[$d[0]] ?? 1) : ($settings[$d[0]] ?? 0)) ? 'checked' : '' ?> onchange="prField('<?= $d[0] ?>', this.checked ? 1 : 0)"><span class="slider"></span></label>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="ig-note after-list">
        <p>Downloads: turning them off hides the Download button for other people. It can't stop someone from taking a screenshot or screen recording.</p>
      </div>
    </div></div>
  </div>
</div>

<!-- WHAT WE STORE ABOUT YOU -->
<?php
function sd_count($conn, $sql) { try { $r = $conn->query($sql); if ($r instanceof mysqli_result) { $x = $r->fetch_row(); return (int)($x[0] ?? 0); } } catch (Throwable $e) {} return null; }
$sd_u = (int)$current_user;   /* always the signed-in person, never ?user= */
$sd_groups = [
  ['Your account', [
     ['ri-user-3-line', 'Profile and sign-in details', 'Username, name, email, phone, DZ-ID, bio, profile photo, birthday, language and your settings. Your password is stored only as a scrambled hash.', null],
  ]],
  ['What you posted', [
     ['ri-image-line', 'Posts', 'Photos, videos, GIFs and text posts, with captions and tags.', sd_count($conn, "SELECT COUNT(*) FROM memes WHERE uploaded_by = $sd_u")],
     ['ri-video-line', 'Long-form videos', 'Videos you uploaded.', sd_count($conn, "SELECT COUNT(*) FROM longform_posts WHERE user_id = $sd_u")],
     ['ri-chat-3-line', 'Comments and replies', 'Everything you wrote under posts.', sd_count($conn, "SELECT COUNT(*) FROM comments WHERE user_id = $sd_u")],
     ['ri-star-line', 'Highlights', 'Highlights on your profile.', sd_count($conn, "SELECT COUNT(*) FROM highlights WHERE user_id = $sd_u")],
  ]],
  ['What you did', [
     ['ri-heart-3-line', 'Likes', 'Posts you liked.', sd_count($conn, "SELECT COUNT(*) FROM likes WHERE user_id = $sd_u")],
     ['ri-repeat-2-line', 'Reposts', 'Posts you reposted.', sd_count($conn, "SELECT COUNT(*) FROM reposts WHERE user_id = $sd_u")],
     ['ri-bookmark-line', 'Saves and collections', 'Posts you saved and the collections you made.', sd_count($conn, "SELECT (SELECT COUNT(*) FROM favorites WHERE user_id = $sd_u) + (SELECT COUNT(*) FROM collections WHERE user_id = $sd_u)")],
     ['ri-share-forward-line', 'Shares', 'Times you shared a post.', sd_count($conn, "SELECT COUNT(*) FROM shares WHERE user_id = $sd_u")],
     ['ri-user-follow-line', 'Tracking', 'People you track.', sd_count($conn, "SELECT COUNT(*) FROM followers WHERE follower_id = $sd_u")],
     ['ri-forbid-2-line', 'Blocked accounts', 'Accounts you blocked.', sd_count($conn, "SELECT COUNT(*) FROM blocks WHERE blocker_id = $sd_u")],
  ]],
  ['Messages', [
     ['ri-message-3-line', 'Messages you sent', 'Direct and group messages, and files you attached.', sd_count($conn, "SELECT (SELECT COUNT(*) FROM messages WHERE sender_id = $sd_u) + (SELECT COUNT(*) FROM msg_gc_messages WHERE sender_id = $sd_u)")],
  ]],
  ['How Dezzme learns what you like', [
     ['ri-eye-line', 'Posts you viewed', 'Used for your history and to show you better posts.', sd_count($conn, "SELECT COUNT(*) FROM user_interactions WHERE user_id = $sd_u")],
     ['ri-layout-masonry-line', 'Feed activity', 'Which posts were shown to you and how long you stayed on them.', sd_count($conn, "SELECT COUNT(*) FROM dz_feed_impressions WHERE user_id = $sd_u")],
     ['ri-user-search-line', 'Profiles you visited', 'Shown on your History page.', sd_count($conn, "SELECT COUNT(*) FROM profile_views WHERE viewer_id = $sd_u")],
     ['ri-search-line', 'Recent searches', 'What you searched for, so you can search again quickly.', sd_count($conn, "SELECT COUNT(*) FROM search_recents WHERE user_id = $sd_u")],
     ['ri-sparkling-line', 'Interests', 'Topics and accounts that Dezzme thinks you like, used to rank your feed.', sd_count($conn, "SELECT COUNT(*) FROM dz_user_affinity WHERE user_id = $sd_u")],
  ]],
  ['Security and devices', [
     ['ri-smartphone-line', 'Signed-in sessions', 'Devices and browsers you are signed in on.', sd_count($conn, "SELECT COUNT(*) FROM user_sessions WHERE user_id = $sd_u")],
     ['ri-notification-3-line', 'Notifications', 'Notifications sent to you.', sd_count($conn, "SELECT COUNT(*) FROM notifications WHERE user_id = $sd_u")],
  ]],
];
?>
<div class="modal-overlay" id="modal-storeddata">
  <div class="modal-lane">
    <div class="ac-topbar modal-topbar">
      <button type="button" class="ac-x" onclick="closeModal('modal-storeddata')" aria-label="Close"><?= $X_SVG ?></button>
      <div class="ac-title left modal-head-title">What we store about you</div>
    </div>
    <div class="modal-body"><div class="mb-in">
      <div class="pv-hero">
        <i class="pv-wm ri-database-2-line"></i>
        <div class="pv-hero-top">
          <span class="pv-hero-ic"><i class="ri-database-2-line"></i></span>
          <div style="min-width:0">
            <div class="pv-hero-l">Your information</div>
            <div class="pv-hero-n">Everything Dezzme keeps</div>
          </div>
        </div>
        <p class="pv-hero-s">Here are the kinds of information linked to your account, and how many items of each we hold right now.</p>
      </div>
      <?php foreach ($sd_groups as $g): ?>
      <div class="pv-h"><?= htmlspecialchars($g[0]) ?></div>
      <div class="sd-list">
        <?php foreach ($g[1] as $r): ?>
        <div class="sd-row"><i class="<?= $r[0] ?>"></i><div><b><?= htmlspecialchars($r[1]) ?></b><span><?= htmlspecialchars($r[2]) ?></span></div><?php if ($r[3] !== null): ?><em><?= number_format($r[3]) ?></em><?php endif; ?></div>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
      <button type="button" class="save-btn-full dx-btn" onclick="closeModal('modal-storeddata'); openModal('modal-datadl')">Download my data</button>
      <div class="ig-note after-list">
        <p>You can remove your own posts, comments and saved items yourself. If you delete your account, it is removed after the 30-day grace period.</p>
      </div>
    </div></div>
  </div>
</div>

<!-- DOWNLOAD YOUR DATA -->
<div class="modal-overlay" id="modal-datadl">
  <div class="modal-lane">
    <div class="ac-topbar modal-topbar">
      <button type="button" class="ac-x" onclick="closeModal('modal-datadl')" aria-label="Close"><?= $X_SVG ?></button>
      <div class="ac-title left modal-head-title">Download your data</div>
    </div>
    <div class="modal-body"><div class="mb-in">
      <div class="pv-hero">
        <i class="pv-wm ri-download-cloud-2-line"></i>
        <div class="pv-hero-top">
          <span class="pv-hero-ic"><i class="ri-download-cloud-2-line"></i></span>
          <div style="min-width:0">
            <div class="pv-hero-l">Your information</div>
            <div class="pv-hero-n">Get a copy of your data</div>
          </div>
        </div>
        <p class="pv-hero-s">We pack your posts, media, comments and profile info into one ZIP file. When it is ready you get a notification, and you can download it here.</p>
      </div>
      <ul class="dx-inc">
        <li><i class="ri-user-3-line"></i>Profile info</li>
        <li><i class="ri-image-line"></i>Posts and media</li>
        <li><i class="ri-chat-3-line"></i>Comments and replies</li>
        <li><i class="ri-star-line"></i>Highlights</li>
        <li><i class="ri-video-line"></i>Long-form videos</li>
        <li><i class="ri-heart-3-line"></i>Likes and saves</li>
      </ul>
      <div class="dx-state" id="dxState"><b>Checking…</b></div>
      <button type="button" class="save-btn-full dx-btn" id="dxBtn" onclick="dxAction()" disabled>Request my data</button>
      <div class="ig-note after-list">
        <p>Your password and security tokens are never included. Private messages include only the ones you sent.</p>
        <p>A finished download stays available for 7 days. You can request a new one every 24 hours.</p>
      </div>
    </div></div>
  </div>
</div>

<!-- WORD FILTERS -->
<div class="modal-overlay" id="modal-wordfilters">
  <div class="modal-lane">
    <div class="ac-topbar modal-topbar">
      <button type="button" class="ac-x" onclick="closeModal('modal-wordfilters')" aria-label="Close"><?= $X_SVG ?></button>
      <div class="ac-title left modal-head-title">Word filters</div>
    </div>
    <div class="modal-body"><div class="mb-in">
      <div class="pv-hero">
        <i class="pv-wm ri-filter-3-line"></i>
        <div class="pv-hero-top">
          <span class="pv-hero-ic"><i class="ri-filter-3-line"></i></span>
          <div style="min-width:0">
            <div class="pv-hero-l">Your feed</div>
            <div class="pv-hero-n">Words you don't want to see</div>
          </div>
        </div>
        <p class="pv-hero-s">Type words, names or phrases. Any post or ad that contains one of them will not show in your feed, and a comment that contains one stays hidden on your own posts. Posts, ads and comments have separate lists.</p>
      </div>

      <div class="wf-tabs" role="tablist" style="margin-top:14px">
        <button type="button" class="wf-tab on" data-k="post" role="tab" onclick="wfTab('post')">Posts <b id="wf-n-post">0</b></button>
        <button type="button" class="wf-tab" data-k="ad" role="tab" onclick="wfTab('ad')">Ads <b id="wf-n-ad">0</b></button>
        <button type="button" class="wf-tab" data-k="comment" role="tab" onclick="wfTab('comment')">Comments <b id="wf-n-comment">0</b></button>
      </div>

      <div class="wf-panel on" data-k="post">
        <div class="wf-add"><input type="text" class="f-input" id="wf-in-post" placeholder="Add a word or phrase" maxlength="400" autocomplete="off" onkeydown="if(event.key==='Enter'){event.preventDefault();wfAdd('post')}"><button type="button" class="hle-btn accent" onclick="wfAdd('post')">Add</button></div>
        <p class="wf-hint">Filters normal meme posts. Separate several words with commas.</p>
        <div class="wf-meta"><span id="wf-c-post"></span><button type="button" id="wf-x-post" onclick="wfClear('post')" hidden>Clear all</button></div>
        <div class="wf-chips" id="wf-l-post"></div>
      </div>
      <div class="wf-panel" data-k="ad">
        <div class="wf-add"><input type="text" class="f-input" id="wf-in-ad" placeholder="Add a word or phrase" maxlength="400" autocomplete="off" onkeydown="if(event.key==='Enter'){event.preventDefault();wfAdd('ad')}"><button type="button" class="hle-btn accent" onclick="wfAdd('ad')">Add</button></div>
        <p class="wf-hint">Filters meme ads, including the words on their button. Separate several words with commas.</p>
        <div class="wf-meta"><span id="wf-c-ad"></span><button type="button" id="wf-x-ad" onclick="wfClear('ad')" hidden>Clear all</button></div>
        <div class="wf-chips" id="wf-l-ad"></div>
      </div>
      <div class="wf-panel" data-k="comment">
        <div class="wf-add"><input type="text" class="f-input" id="wf-in-comment" placeholder="Add a word or phrase" maxlength="400" autocomplete="off" onkeydown="if(event.key==='Enter'){event.preventDefault();wfAdd('comment')}"><button type="button" class="hle-btn accent" onclick="wfAdd('comment')">Add</button></div>
        <p class="wf-hint">Comments on YOUR posts that contain these words stay hidden from everyone except the person who wrote them. Separate several words with commas.</p>
        <div class="wf-meta"><span id="wf-c-comment"></span><button type="button" id="wf-x-comment" onclick="wfClear('comment')" hidden>Clear all</button></div>
        <div class="wf-chips" id="wf-l-comment"></div>
      </div>

      <div class="ig-note after-list">
        <p>Whole words only, and capital letters don't matter. "gym" hides posts with the word gym, but not "gymnast".</p>
        <p>Add a star to match more: "politic*" hides politics and political, "*gram" hides Instagram. "#gym" hides that hashtag.</p>
      </div>
    </div></div>
  </div>
</div>

<!-- MUTED PODS -->
<div class="modal-overlay" id="modal-mutedpods">
  <div class="modal-lane">
    <div class="ac-topbar modal-topbar">
      <button type="button" class="ac-x" onclick="closeModal('modal-mutedpods')" aria-label="Close"><?= $X_SVG ?></button>
      <div class="ac-title left modal-head-title">Muted Pods</div>
    </div>
    <div class="modal-body"><div class="mb-in">

      <div class="pv-hero">
        <i class="pv-wm ri-volume-mute-line"></i>
        <div class="pv-hero-top">
          <span class="pv-hero-ic"><i class="ri-volume-mute-line"></i></span>
          <div style="min-width:0">
            <div class="pv-hero-l">Notifications off</div>
            <div class="pv-hero-n" id="mp-hero-n"><?= count($mp_list) > 0 ? count($mp_list) . ' Pod' . (count($mp_list) === 1 ? '' : 's') . ' muted' : 'No muted Pods' ?></div>
          </div>
        </div>
        <p class="pv-hero-s">You don't get notifications from these Pods. You can still open them and read everything.</p>
        <div class="pv-stats">
          <div class="pv-stat"><b id="mp-count"><?= count($mp_list) ?></b><span>Muted</span></div>
          <div class="pv-stat"><b>0</b><span>Left Pods</span></div>
          <div class="pv-stat"><b class="sm">Unmute</b><span>Any time</span></div>
        </div>
      </div>

      <div class="pv-h">Your muted Pods</div>
      <div id="mp-list">
        <?php if (!$mp_list): ?>
          <div class="mod-empty" id="mp-empty"><div class="empty-title">No muted Pods</div><p class="empty-desc">When you turn notifications off for a Pod, it appears here so you can turn them back on.</p></div>
        <?php else: foreach ($mp_list as $mp): ?>
          <div class="mod-user-row" id="mp-<?= (int)$mp['id'] ?>">
            <?php $mpPic = trim((string)($mp['group_pic'] ?? '')); if ($mpPic !== '' && strpos($mpPic, 'uploads/') !== 0 && !preg_match('#^https?://#i', $mpPic)) $mpPic = 'uploads/' . $mpPic; ?>
            <span class="mp-pic"><?php if ($mpPic !== ''): ?><img src="<?= htmlspecialchars($mpPic) ?>" alt="" loading="lazy" onerror="this.parentNode.classList.add('nopic');this.remove()"><?php endif; ?><i class="ri-group-line"></i></span>
            <span class="mod-username" onclick="window.location.href='group.php?id=<?= (int)$mp['id'] ?>'"><?= htmlspecialchars((string)$mp['title']) ?><?= $mp['members'] !== null ? ' · ' . (int)$mp['members'] . ' member' . ((int)$mp['members'] === 1 ? '' : 's') : '' ?></span>
            <button type="button" class="mod-action-btn" onclick="mpUnmute(<?= (int)$mp['id'] ?>, this)">Unmute</button>
          </div>
        <?php endforeach; endif; ?>
      </div>
      <div id="mp-more" style="height:1px"></div>

      <div class="ig-note after-list">
        <p>Muting a Pod only stops its notifications. You stay a member and keep your place. Tap Unmute and you'll get that Pod's notifications again from now on.</p>
      </div>

    </div></div>
  </div>
</div>

<!-- ARCHIVE -->
<div class="modal-overlay" id="modal-archive">
  <div class="modal-lane">
    <div class="ac-topbar modal-topbar">
      <button type="button" class="ac-x" onclick="closeModal('modal-archive')" aria-label="Close"><?= $X_SVG ?></button>
      <div class="ac-title left modal-head-title">Archive</div>
    </div>
    <div class="modal-body"><div class="mb-in">

      <div class="pv-hero">
        <i class="pv-wm ri-archive-line"></i>
        <div class="pv-hero-top">
          <span class="pv-hero-ic"><i class="ri-archive-line"></i></span>
          <div style="min-width:0">
            <div class="pv-hero-l">Your posts</div>
            <div class="pv-hero-n" id="ar-hero-n"></div>
          </div>
        </div>
        <p class="pv-hero-s">Archive a post to hide it from your profile and feeds without deleting it. Only you can see archived posts, and you can restore them any time.</p>
        <div class="pv-stats">
          <div class="pv-stat"><b id="ar-n-live"><?= (int)$ar_live ?></b><span>On profile</span></div>
          <div class="pv-stat"><b id="ar-n-arch"><?= (int)$ar_arch ?></b><span>Archived</span></div>
          <div class="pv-stat"><b class="sm">Kept safe</b><span>Not deleted</span></div>
        </div>
      </div>

      <div class="pv-h">Choose a list</div>
      <div class="pv-seg" id="ar-seg" role="radiogroup" aria-label="Posts">
        <button type="button" class="on" data-t="live" role="radio" aria-checked="true">On profile</button>
        <button type="button" data-t="archived" role="radio" aria-checked="false">Archived</button>
      </div>

      <div class="ar-grid" id="ar-grid"></div>
      <div class="mod-empty" id="ar-empty" style="display:none"><div class="empty-title" id="ar-empty-t">Nothing here</div><p class="empty-desc" id="ar-empty-d"></p></div>
      <button type="button" class="save-btn-full ar-more" id="ar-more" onclick="arLoad(false)">Load more</button>

      <div class="ig-note after-list">
        <p>Archived posts disappear from your profile, from search and from other people's feeds. Likes, saves and remixes are kept and come back when you restore the post. Text posts show on the Text background.</p>
      </div>

    </div></div>
  </div>
</div>

<!-- HIGHLIGHTS -->
<div class="modal-overlay" id="modal-highlights">
  <div class="modal-lane">
    <div class="ac-topbar modal-topbar">
      <button type="button" class="ac-x" onclick="closeModal('modal-highlights')" aria-label="Close"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button>
      <div class="ac-title left modal-head-title">Highlights</div>
    </div>
    <div class="modal-body"><div class="mb-in">

      <div class="hl-add">
        <input type="text" class="f-input" id="hl-title" maxlength="30" placeholder="Highlight name" autocomplete="off" style="flex:none;width:100%">
        <label class="hl-pick" for="hl-files">
          <img class="hl-cover-prev" id="hl-cover-prev" alt="">
          <i class="ri-image-add-line" id="hl-pick-icon"></i>
          <div class="hl-pick-text">
            <strong id="hl-pick-title">Choose photos or videos</strong>
            <span id="hl-pick-sub">Add 1 to 100 per highlight.</span>
          </div>
          <input type="file" id="hl-files" accept="image/*,video/*" multiple>
        </label>
        <button type="button" class="hl-pick small" onclick="hlAddText('c')">
          <i class="ri-text"></i>
          <div class="hl-pick-text">
            <strong>Add a text highlight</strong>
            <span>Words on a Text.jpg background. You can also write on any photo, video or GIF below.</span>
          </div>
        </button>
        <div id="hl-items"></div>
        <label class="hl-pick small" for="hl-cover-file">
          <img class="hl-cover-prev" id="hl-cv-prev" alt="" style="width:34px;height:34px">
          <i class="ri-image-line" id="hl-cv-icon"></i>
          <div class="hl-pick-text">
            <strong id="hl-cv-title">Cover image (optional)</strong>
            <span id="hl-cv-sub">Skip it and your first media becomes the cover.</span>
          </div>
          <button type="button" class="hl-x" id="hl-cv-x" aria-label="Remove cover" onclick="event.preventDefault();event.stopPropagation();hlCoverClear()"><i class="ri-close-line"></i></button>
          <input type="file" id="hl-cover-file" accept="image/*">
        </label>
        <div class="hl-seg" id="hl-mode">
          <button type="button" class="on" data-mode="whole" onclick="hlSetMode('whole')">One song for all</button>
          <button type="button" data-mode="each" onclick="hlSetMode('each')">Song per media</button>
        </div>
        <p class="field-help" id="hl-mode-help">One song clip plays across every media in this highlight.</p>
        <div id="hl-song-wrap">
        <div class="hl-pick small" onclick="hlSongPick()" role="button" tabindex="0">
          <i class="ri-music-2-line"></i>
          <div class="hl-pick-text">
            <strong id="hl-song-title">Song clip (optional)</strong>
            <span id="hl-song-sub">Pick the part of a song you want, up to 30 seconds.</span>
          </div>
          <button type="button" class="hl-x" id="hl-song-x" aria-label="Remove song" onclick="event.stopPropagation();hlSongClear()"><i class="ri-close-line"></i></button>
        </div>
        </div>
        <div class="hl-progress" id="hl-progress"><span id="hl-progress-bar"></span></div>
        <div class="hl-status" id="hl-status" aria-live="polite"></div>
        <button class="save-btn-full" id="hl-submit" style="margin-top:0" onclick="hlUpload(this)">Create highlight</button>
      </div>

      <div class="hl-list-head">
        <span class="hl-list-title">Your highlights</span>
        <span class="hl-list-count" id="hl-total"><?= count($my_highlights) ?></span>
      </div>
      <div class="hl-grid" id="hl-grid">
        <?php if (empty($my_highlights)): ?>
          <div class="hl-empty" id="hl-empty"><div class="empty-title">No highlights yet</div><div class="empty-desc" style="margin:4px auto 0">Name your first highlight and add some media above.</div></div>
        <?php else: foreach ($my_highlights as $h): ?>
          <div class="hl-item" id="hl-<?= (int)$h['id'] ?>">
            <div class="hl-sq">
              <?php if (!empty($h['cover_path'])): ?>
                <img src="<?= htmlspecialchars($h['cover_path']) ?>" alt="" loading="lazy">
              <?php elseif ($h['first_type'] === 'text'): ?>
                <img src="<?= htmlspecialchars(HL_TEXT_BG) ?>" alt="" loading="lazy">
                <span class="hl-tilecap"><?= htmlspecialchars(function_exists('mb_substr') ? mb_substr((string)$h['first_caption'], 0, 90) : substr((string)$h['first_caption'], 0, 90)) ?></span>
              <?php elseif ($h['first_type'] === 'video'): ?>
                <video src="<?= htmlspecialchars($h['first_path']) ?>#t=0.1" muted playsinline preload="metadata"></video>
                <span class="hl-vid"><i class="ri-play-fill"></i></span>
              <?php else: ?>
                <img src="<?= htmlspecialchars($h['first_path']) ?>" alt="" loading="lazy">
              <?php endif; ?>
              <?php if (!empty($h['song_path'])): ?><span class="hl-song"><i class="ri-music-2-fill"></i></span><?php endif; ?>
              <button class="hl-del" type="button" aria-label="Edit highlight" onclick="hlEdit(<?= (int)$h['id'] ?>)"><i class="ri-pencil-line"></i></button>
            </div>
            <div class="hl-name"><?= htmlspecialchars($h['title']) ?></div>
            <div class="hl-count"><?= (int)$h['media_count'] ?> media</div>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <div class="ig-note after-list">
        <p>Highlights are collections of photos, videos, GIFs and text that sit on your profile. Give each one a name, add 1 to 100 media, and optionally upload a cover image and a song clip. With no cover image, your first media is the cover.</p>
        <p>You can write on any photo, video or GIF, or add a text highlight that shows on a Text.jpg background. Text sits in the middle and long text is shortened with a "more" button. Tag a person with @username or @nickname and it turns red, and type a group's name (no @ needed) and it turns blue. Tap a name to open that page.</p>
        <p>Tap the pencil on a tile to edit it: rename it, add or remove media, change the cover, song and text, and set a song or mute for each media. Your edits are collected, and nothing changes until you tap Save new changes at the bottom.</p>
        <p>Tiles here are for managing only, so they don't open. To watch a highlight, visit your profile page, where trackers can open them too.</p>
      </div>
    </div></div>
  </div>
</div>

<!-- HIGHLIGHT EDITOR -->
<div class="modal-overlay" id="modal-hl-edit">
  <div class="modal-lane">
    <div class="ac-topbar modal-topbar">
      <button type="button" class="ac-x" onclick="closeModal('modal-hl-edit')" aria-label="Close"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button>
      <div class="ac-title left modal-head-title">Edit highlight</div>
    </div>
    <div class="modal-body"><div class="mb-in" id="hle-root"></div></div>
    <div class="hle-footer">
      <div class="hle-note" id="hle-note" aria-live="polite">No new changes.</div>
      <button class="save-btn-full" id="hle-save" onclick="hlSaveAll()" disabled>Save new changes</button>
    </div>
  </div>
</div>

<!-- TEXT EDITOR -->
<div class="modal-overlay" id="modal-ht">
  <div class="modal-lane">
    <div class="ac-topbar modal-topbar">
      <button type="button" class="ac-x" onclick="htClose()" aria-label="Close"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button>
      <div class="ac-title left modal-head-title">Highlight text</div>
    </div>
    <div class="modal-body"><div class="mb-in">
      <textarea class="f-input" id="ht-input" maxlength="1000" placeholder="Write something. Tag people with @username or @nickname, and groups by their name." oninput="htUpdate()"></textarea>
      <div class="ht-count" id="ht-count">0/1000</div>
      <div class="hl-status" id="ht-status" aria-live="polite"></div>
      <div class="ht-preview-wrap">
        <div class="hle-h">Preview</div>
        <div id="ht-preview"></div>
      </div>
      <div class="ht-actions">
        <button type="button" class="ht-remove" id="ht-remove" onclick="htRemove()" style="display:none">Remove text</button>
        <button type="button" class="save-btn-full" onclick="htDone()">Done</button>
      </div>
      <div class="ig-note after-list">
        <p>Your text appears in the middle of the media. On a text-only highlight it sits on the Text.jpg background. Long text is shortened with a "more" button that opens the rest, and "less" folds it back.</p>
        <p>Tag a person with @username or @nickname and it turns red. Type a group's name exactly as it is (no @ needed) and it turns blue. Tap either one to open their page.</p>
        <p>Tapping Done keeps the text in your edit. It is saved for real when you tap Save new changes.</p>
      </div>
    </div></div>
  </div>
</div>

<!-- SONG CLIP PICKER -->
<div class="modal-overlay" id="modal-clip">
  <div class="modal-lane">
    <div class="ac-topbar modal-topbar">
      <button type="button" class="ac-x" onclick="cpClose()" aria-label="Close"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button>
      <div class="ac-title left modal-head-title">Choose a song clip</div>
    </div>
    <div class="modal-body"><div class="mb-in">
      <label class="hl-pick" for="cp-file">
        <i class="ri-music-2-line"></i>
        <div class="hl-pick-text"><strong id="cp-file-title">Choose a song</strong><span>MP3, M4A, WAV and more. Only the part you pick is kept.</span></div>
        <input type="file" id="cp-file" accept="audio/*">
      </label>
      <div id="cp-editor" style="display:none">
        <input type="text" class="f-input" id="cp-title" maxlength="60" placeholder="Song name (optional)" style="flex:none;width:100%;margin-top:12px">
        <div class="cp-times"><span>Starts at <b id="cp-start-lbl">0:00</b></span><span>Ends at <b id="cp-end-lbl">0:15</b></span></div>
        <input type="range" id="cp-start" min="0" max="100" step="0.1" value="0" oninput="cpUpdate()">
        <div class="cp-row">
          <span class="field-label" style="margin:0">Clip length</span>
          <select class="f-select" id="cp-len" style="width:auto" onchange="cpUpdate()">
            <option value="10">10 seconds</option><option value="15" selected>15 seconds</option><option value="20">20 seconds</option><option value="30">30 seconds</option>
          </select>
        </div>
        <button type="button" class="hl-pick small" id="cp-prev-btn" onclick="cpPreview()"><i class="ri-play-circle-line"></i><div class="hl-pick-text"><strong id="cp-prev-txt">Preview clip</strong></div></button>
        <button class="save-btn-full" id="cp-use" onclick="cpUse(this)">Use this clip</button>
      </div>
      <div class="hl-status" id="cp-status" style="margin-top:10px"></div>
      <div class="ig-note after-list">
        <p>Drag the slider to the part of the song you want, choose how long the clip should be (up to 30 seconds), and tap Preview to hear it before you use it.</p>
        <p>Only that short clip is uploaded to Dezzme. The full song never leaves your device.</p>
      </div>
    </div></div>
  </div>
</div>

<div class="dz-toast" id="dz-toast" role="status" aria-live="polite"></div>

<script nonce="<?php echo $nonce; ?>">
// ── Theme (dark default; light = html[data-theme="light"]) ──
(function(){
  function isLight(){ return document.documentElement.getAttribute('data-theme') === 'light'; }
  function sync(){
    var r = document.getElementById('dzThemeRow');
    if (r) r.setAttribute('aria-checked', isLight() ? 'false' : 'true');
    var bs = document.querySelectorAll('#dzThemeBtns [data-t]');
    for (var i = 0; i < bs.length; i++) bs[i].classList.toggle('on', (bs[i].getAttribute('data-t') === 'light') === isLight());
  }
  window.dzApplyTheme = function(light){
    var h = document.documentElement;
    if (light) h.setAttribute('data-theme','light'); else h.removeAttribute('data-theme');
    try { localStorage.setItem('dz_theme', light ? 'light' : 'dark'); } catch(e){}
    try {
      var fd = new FormData(); fd.append('theme', light ? 'light' : 'dark');
      fetch('theme_css.php?set=1', {method:'POST', body:fd, keepalive:true, credentials:'same-origin'}).catch(function(){});
    } catch(e){}
    try { document.cookie = 'dz_theme=' + (light ? 'light' : 'dark') + '; path=/; max-age=31536000; SameSite=Lax'; } catch(e){}
    var m = document.querySelector('meta[name="theme-color"]');
    if (m) m.setAttribute('content', light ? '#ffffff' : '#070924');
    sync();
  };
  window.dzToggleTheme = function(){ window.dzApplyTheme(!isLight()); };
  sync();
})();
// ── CSRF: every POST carries the session token (checked at the top of this file) ──
const CSRF = <?= json_encode($csrf_token) ?>;

// ── Download your data ──
let DX = { ex: null, busy: false, timer: null };
function dxFmtSize(b) { return b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; }
function dxFmtDate(d) { try { return new Date(String(d).replace(' ', 'T')).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }); } catch (e) { return d; } }
async function dxCall(action) {
  const fd = new FormData(); fd.append('action', action);
  const r = await fetch('data_export.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': CSRF } });
  return r.json();
}
function dxRender() {
  const st = document.getElementById('dxState'), btn = document.getElementById('dxBtn');
  if (!st || !btn) return;
  const ex = DX.ex, esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  btn.disabled = false; btn.onclick = dxAction; btn.dataset.mode = 'request';
  if (DX.busy || (ex && ex.status === 'pending')) {
    st.innerHTML = '<b>Preparing your data…</b><span>This can take a minute. You can close this window. We will notify you when it is ready.</span><div class="dx-bar"></div>';
    btn.disabled = true; btn.textContent = 'Preparing…'; return;
  }
  if (ex && ex.status === 'ready') {
    st.innerHTML = '<b>Your data is ready</b><span>' + dxFmtSize(ex.size) + ' · requested ' + esc(dxFmtDate(ex.requested)) + '. Available until ' + esc(dxFmtDate(ex.expires)) + '.' + (ex.note ? ' ' + esc(ex.note) : '') + '</span>';
    btn.textContent = 'Download ZIP'; btn.dataset.mode = 'download'; return;
  }
  if (ex && ex.status === 'failed') {
    st.innerHTML = '<b>The last export did not finish</b><span>' + esc(ex.note || 'Please try again.') + '</span>';
    btn.textContent = 'Try again'; return;
  }
  if (ex && ex.status === 'expired') {
    st.innerHTML = '<b>Your last download expired</b><span>Request a new copy any time.</span>';
    btn.textContent = 'Request my data'; return;
  }
  st.innerHTML = '<b>No export yet</b><span>Tap the button to start. We will notify you when your file is ready.</span>';
  btn.textContent = 'Request my data';
}
async function dxOpen() {
  dxRender();
  try { const d = await dxCall('status'); if (d && d.success) { DX.ex = d.export; dxRender(); } } catch (e) {}
}
async function dxAction() {
  const btn = document.getElementById('dxBtn');
  if (btn && btn.dataset.mode === 'download' && DX.ex) { window.location.href = 'data_export.php?download=' + DX.ex.id; return; }
  if (DX.busy) return;
  DX.busy = true; dxRender();
  try {
    const d = await dxCall('request');
    if (d && d.export) DX.ex = d.export;
    if (d && d.success) { showToast(DX.ex && DX.ex.status === 'ready' ? 'Your data is ready to download' : 'We are preparing your data'); }
    else showToast((d && d.msg) || 'Could not start the export.');
  } catch (e) { showToast('Connection problem. Check the status and try again.'); try { const d = await dxCall('status'); if (d && d.export) DX.ex = d.export; } catch (e2) {} }
  DX.busy = false; dxRender();
}
(function () {
  const _fetch = window.fetch;
  window.fetch = function (url, opts) {
    opts = opts || {};
    if (String(opts.method || 'GET').toUpperCase() === 'POST') {
      const h = new Headers(opts.headers || {});
      h.set('X-CSRF-Token', CSRF);
      opts.headers = h;
    }
    return _fetch.call(this, url, opts);
  };
})();

// ── Modal control ──────────────────────────────────────────
let _lastFocus = null; // element that opened the modal, focus returns to it on close
function openModal(id) {
  const el = document.getElementById(id);
  if (!el) return;
  _lastFocus = document.activeElement;
  el.classList.add('open');
  document.body.style.overflow = 'hidden';
  setTimeout(() => { const b = el.querySelector('.ac-x'); if (b) b.focus({ preventScroll: true }); }, 60);
  const body = el.querySelector('.modal-body');
  if (body) body.scrollTop = 0;
  if (id === 'modal-checkup' && typeof ckRender === 'function') ckRender();
  if (id === 'modal-timezone' && typeof tzOpen === 'function') tzOpen();
  if (id === 'modal-datadl' && typeof dxOpen === 'function') dxOpen();
  if (id === 'modal-mutedpods' && typeof mpInitScroll === 'function') mpInitScroll();
  if (id === 'modal-archive' && typeof arOpen === 'function') arOpen();
  if (id === 'modal-profiletype') showPtSkeleton(); else showModalSkeleton(el);
}

// Profile Type loader: skeleton mirrors the real categories/chips (widths follow label length), then a staggered reveal
function showPtSkeleton() {
  const list = document.getElementById('pt-list'), sk = document.getElementById('pt-skel');
  if (!list || !sk) return;
  /* open on the group the user's current type belongs to */
  if (typeof ptSetGroup === 'function') {
    const inOrg = !!currentProfileType && Object.values(ORG_CATEGORIES).some(a => a.indexOf(currentProfileType) > -1)
                  && !Object.values(PROFILE_CATEGORIES).some(a => a.indexOf(currentProfileType) > -1);
    ptSetGroup(inOrg ? 'org' : 'people', false);
  }
  const src = ptGroup === 'org' ? ORG_CATEGORIES : PROFILE_CATEGORIES;
  if (sk.dataset.built !== ptGroup) {
    let html = '';
    Object.keys(src).slice(0, 4).forEach((cat, g) => {
      html += '<div class="pt-skel-cat"><div class="pt-skel-title" style="width:' + (26 + (g * 9) % 20) + '%"></div><div class="pt-skel-chips">';
      src[cat].slice(0, 12).forEach(name => {
        html += '<div class="pt-skel-chip" style="width:' + Math.round(name.length * 6.4 + 22) + 'px"></div>';
      });
      html += '</div></div>';
    });
    sk.innerHTML = html; sk.dataset.built = ptGroup;
  }
  list.classList.remove('pt-reveal');
  sk.style.display = 'block'; list.style.display = 'none';
  clearTimeout(sk._t);
  sk._t = setTimeout(() => { sk.style.display = 'none'; list.style.display = ''; ptReveal(); }, 220);
}
/* the buttons appear in staggered waves; each wave gives a soft tick you can feel (the loading, not a tap) */
function ptReveal() { if (typeof ptRender === 'function') ptRender(true); }

// Brief shimmer over a modal's content each time it opens.
function showModalSkeleton(el) {
  const body = el.querySelector('.modal-body');
  if (!body) return;
  let skel = body.querySelector('.modal-skel');
  if (!skel) {
    skel = document.createElement('div');
    skel.className = 'modal-skel';
    skel.innerHTML =
      '<div class="modal-skel-in">' +
        '<div class="sk-bar" style="height:12px;width:38%"></div>' +
        '<div class="sk-bar" style="height:42px;width:100%"></div>' +
        '<div class="sk-bar" style="height:42px;width:100%"></div>' +
        '<div class="sk-bar" style="height:12px;width:52%"></div>' +
        '<div class="sk-bar" style="height:42px;width:100%"></div>' +
        '<div class="sk-bar" style="height:90px;width:100%"></div>' +
      '</div>';
    body.prepend(skel);
  }
  skel.classList.remove('hide');
  clearTimeout(skel._t);
  skel._t = setTimeout(() => skel.classList.add('hide'), 120);
}
function closeModal(id) {
  const el = document.getElementById(id);
  if (!el) return;
  if (id === 'modal-clip' && typeof cpClose === 'function') { cpClose(); return; }
  if (id === 'modal-ht' && typeof htClose === 'function') { htClose(); return; }
  if (id === 'modal-avatar' && typeof avClose === 'function') { avClose(); return; }
  if (id === 'modal-hl-edit') {
    if (hlHasPending() && !confirm('You have changes that are not saved yet. Leave without saving?')) return;
    hlDiscard();
  }
  el.classList.remove('open');
  if (!document.querySelector('.modal-overlay.open')) document.body.style.overflow = '';
  if (id === 'modal-hl-edit' && hlDirty) { hlDirty = false; window.location.href = window.location.pathname + '?open=highlights'; return; }
  if (_lastFocus && _lastFocus.focus) _lastFocus.focus({ preventScroll: true });
}
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') {
    const open = Array.from(document.querySelectorAll('.modal-overlay.open'));
    if (open.length) closeModal(open[open.length - 1].id);
  }
});
// Warn before the tab is closed or a tag link is followed while edits are unsaved
window.addEventListener('beforeunload', function (e) {
  if (typeof hlHasPending === 'function' && hlHasPending()) { e.preventDefault(); e.returnValue = ''; }
});

// ── Auto-open a modal from ?open=... (e.g. settings.php?open=trackreqs) ──
(function autoOpenFromQuery() {
  const params = new URLSearchParams(window.location.search);
  const target = params.get('open');
  if (!target) return;
  if (target === 'verified') { window.location.replace('profile_verified.php'); return; }
  const map = {
    trackreqs: 'modal-trackreqs', account: 'modal-account', password: 'modal-password',
    privacy: 'modal-privacy', blocked: 'modal-blocked', profiletype: 'modal-profiletype', vibe: 'modal-vibe',
    highlights: 'modal-highlights', creatortools: 'modal-creator', tags: 'modal-tags', lists: 'modal-lists', status: 'modal-status', danger: 'modal-danger', time: 'modal-time', notifications: 'modal-notifs', birthdays: 'modal-birthdays', checkup: 'modal-checkup', timezone: 'modal-timezone', mutedpods: 'modal-mutedpods', archive: 'modal-archive'
  };
  const modalId = map[target];
  if (!modalId) return;
  window.addEventListener('load', function () {
    setTimeout(function () {
      if (document.getElementById(modalId)) openModal(modalId);
      params.delete('open');
      const qs = params.toString();
      history.replaceState(null, '', window.location.pathname + (qs ? '?' + qs : ''));
    }, 450);
  });
})();

// ── Keep the Settings topbar directly under the sidebar on small screens ──
(function () {
  const sb = document.querySelector('.sidebar'), mq = window.matchMedia('(max-width:790px)');
  function setSb() { document.documentElement.style.setProperty('--sb-h', (mq.matches && sb ? sb.offsetHeight : 0) + 'px'); }
  setSb();
  window.addEventListener('resize', setSb);
  window.addEventListener('load', setSb);
  if (window.ResizeObserver && sb) new ResizeObserver(setSb).observe(sb);
})();

// ── Remove skeleton as soon as the page is ready (no waiting for images) ──
(function () {
  function hide() {
    const sk = document.getElementById('page-skeleton');
    if (!sk || sk._h) return; sk._h = 1;
    sk.classList.add('hide');
    setTimeout(function () { sk.remove(); }, 140);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', hide); else hide();
})();

// ── Right column: collapsible cards (remembered between visits) ──
window.gsToggle = function (btn) {
  const card = btn && btn.closest ? btn.closest('.gs-card') : null; if (!card) return;
  const c = card.classList.toggle('collapsed');
  btn.setAttribute('aria-expanded', c ? 'false' : 'true');
  try { localStorage.setItem('st_gs_' + (card.getAttribute('data-k') || ''), c ? '1' : '0'); } catch (e) {}
};
(function () {
  document.querySelectorAll('.gs-card').forEach(card => {
    try {
      if (localStorage.getItem('st_gs_' + (card.getAttribute('data-k') || '')) === '1') {
        card.classList.add('collapsed');
        const b = card.querySelector('.gs-bar'); if (b) b.setAttribute('aria-expanded', 'false');
      }
    } catch (e) {}
  });
})();

// ── Toast ──────────────────────────────────────────────────
function showToast(msg) {
  const t = document.getElementById('dz-toast');
  t.textContent = msg;
  t.classList.add('show');
  clearTimeout(t._tid);
  t._tid = setTimeout(() => t.classList.remove('show'), 2600);
}

// ── Update field ───────────────────────────────────────────
function updateField(field, btn) {
  const row = btn.closest('.field-row');
  const input = row ? row.querySelector('[name="' + field + '"]') : document.querySelector('[name="' + field + '"]');
  if (!input) return;
  doUpdate(field, input.value, btn);
}
function updateFieldFromRadio(field, radioEl) { doUpdate(field, radioEl.value, radioEl); }
function updateFieldFromModal(field, btn) {
  const modal = btn.closest('.modal-body');
  const input = modal ? modal.querySelector('[name="' + field + '"]') : document.querySelector('[name="' + field + '"]');
  if (!input) return;
  doUpdate(field, input.value, btn);
}
function updateFieldFromSelect(field, selectEl, btn) { doUpdate(field, selectEl.value, btn); }

function doUpdate(field, value, btn) {
  const orig = btn.textContent;
  btn.disabled = true;
  btn.textContent = 'Saving…';
  fetch('ajax_update_user.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'field=' + encodeURIComponent(field) + '&value=' + encodeURIComponent(value)
  })
  .then(r => r.json())
  .then(data => {
    btn.disabled = false;
    if (data.success) {
      btn.textContent = 'Saved ✓';
      setTimeout(() => btn.textContent = orig, 1400);
      showToast(field.replace(/_/g, ' ') + ' updated');
    } else {
      btn.textContent = orig;
      showToast('Error: ' + (data.msg || field));
    }
  })
  .catch(() => {
    btn.disabled = false;
    btn.textContent = orig;
    showToast('Network error — could not save ' + field);
  });
}

function updateToggle(field, state) {
  fetch('ajax_update_user.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'field=' + encodeURIComponent(field) + '&value=' + (state ? 1 : 0)
  })
  .then(r => r.json())
  .then(data => {
    showToast(data.success ? 'Privacy updated' : 'Error updating ' + field);
    if (data.success && field === 'is_fully_private') {
      const p = document.getElementById('gsPrivacy'); if (p) p.textContent = state ? 'Full Private' : 'Public';
    }
  })
  .catch(() => showToast('Network error'));
}

// ══ HAPTIC ENGINE (same one the Zeems modal uses: Android vibrates; iPhone gives a system tick where the browser allows it) ══
window.DZ_HAPTICS_ON = <?= $haptics_on ? 'true' : 'false' ?>;
window.dzHaptic = window.dzHaptic || (function(){
    var enabled = window.DZ_HAPTICS_ON !== false, last = 0, tickEl = null;
    var canVibrate = false; try{ canVibrate = typeof navigator.vibrate === 'function'; }catch(e){}
    var isIOS = false; try{ isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1); }catch(e){}
    var P = { light:[16], medium:[30], heavy:[55], success:[18,50,32], error:[45,60,45,60,60], selection:[11], soft:[21], longPress:[40], double:[18,60,18] };
    var IOS_TICKS = { light:1, medium:2, heavy:3, success:3, error:4, selection:1, soft:1, longPress:2, double:2 };
    function reduced(){ try{ return !!(window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches); }catch(e){ return false; } }
    function iosTick(){
        try{
            if(!tickEl){
                tickEl = document.createElement('label'); tickEl.setAttribute('aria-hidden', 'true');
                tickEl.style.cssText = 'position:fixed;left:-9999px;top:0;width:1px;height:1px;opacity:0;pointer-events:none;overflow:hidden';
                var i = document.createElement('input'); i.type = 'checkbox'; i.setAttribute('switch', ''); i.tabIndex = -1; tickEl.appendChild(i);
                document.body.appendChild(tickEl);
            }
            tickEl.click();
        }catch(e){}
    }
    function fire(kind){
        try{
            if(!enabled || reduced()) return;
            var now = Date.now(); if(now - last < 40) return;       /* one buzz per action, never a burst */
            last = now;
            if(canVibrate){ navigator.vibrate(P[kind] || P.light); return; }
            if(isIOS){ var n = IOS_TICKS[kind] || 1; iosTick(); for(var k = 1; k < n; k++) setTimeout(iosTick, k * 55); }
        }catch(e){}
    }
    var api = { enable:function(on){ enabled = !!on; }, isEnabled:function(){ return enabled; }, supported:function(){ return canVibrate || isIOS; } };
    Object.keys(P).forEach(function(k){ api[k] = function(){ fire(k); }; });
    return api;
})();

// ══ PROFILE TYPE PICKER ══
const PROFILE_CATEGORIES = <?= json_encode($profile_categories, JSON_UNESCAPED_UNICODE) ?>;
const ORG_CATEGORIES = <?= json_encode($org_categories, JSON_UNESCAPED_UNICODE) ?>;
let ptGroup = 'people';
let currentProfileType   = <?= json_encode($settings['profile_type'], JSON_UNESCAPED_UNICODE) ?>;

(function initProfileType() {
  const list     = document.getElementById('pt-list');
  const search   = document.getElementById('pt-search-input');
  const clearBtn = document.getElementById('pt-clear-search');
  const noneEl   = document.getElementById('pt-none');
  const tabs     = document.querySelectorAll('.pt-tab');
  if (!list) return;
  const BATCH = 3;                       /* categories added per scroll step */
  let items = [], shown = 0, io = null, sentinel = null, tickN = 0;

  function collect() {
    const q = search.value.trim().toLowerCase(), out = [];
    [['people', PROFILE_CATEGORIES], ['org', ORG_CATEGORIES]].forEach(([grp, cats]) => {
      if (!q && grp !== ptGroup) return;
      Object.keys(cats).forEach(cat => {
        const names = cats[cat].filter(n => !q || (n + ' ' + cat + (grp === 'org' ? ' organisation organization entity company' : '')).toLowerCase().includes(q));
        if (names.length) out.push({cat, names, grp});
      });
    });
    return out;
  }
  function addBatch(animate) {
    const next = items.slice(shown, shown + BATCH);
    next.forEach((it, k) => {
      const wrap = document.createElement('div');
      wrap.className = 'pt-cat' + (animate ? ' pt-in' : '');
      if (animate) wrap.style.animationDelay = (k * 0.06) + 's';
      const title = document.createElement('div');
      title.className = 'pt-cat-title'; title.textContent = it.cat; wrap.appendChild(title);
      const chips = document.createElement('div'); chips.className = 'pt-chips';
      it.names.forEach(name => {
        const chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'pt-chip' + (name === currentProfileType ? ' selected' : '');
        chip.dataset.value = name; chip.textContent = name;
        chip.addEventListener('click', () => saveProfileType(name, chip));
        chips.appendChild(chip);
      });
      wrap.appendChild(chips);
      list.insertBefore(wrap, sentinel);
      setTimeout(() => dzHaptic.medium(), k * 70);          /* a soft buzz as each group of buttons loads */
    });
    shown += next.length;
    if (shown >= items.length && sentinel.parentNode) { if (io) io.unobserve(sentinel); sentinel.style.display = 'none'; }
  }
  window.ptRender = function (animate) {
    items = collect(); shown = 0;
    list.innerHTML = '';
    sentinel = document.createElement('div'); sentinel.id = 'pt-sentinel'; sentinel.style.cssText = 'height:2px';
    list.appendChild(sentinel);
    noneEl.classList.toggle('show', items.length === 0);
    if (io) io.disconnect();
    if ('IntersectionObserver' in window) {
      io = new IntersectionObserver(es => { if (es[0].isIntersecting && shown < items.length) addBatch(true); }, {rootMargin: '400px 0px'});
      io.observe(sentinel);
    }
    addBatch(!!animate);
    /* make sure the first screen is full even on tall displays */
    let guard = 0;
    while (shown < items.length && sentinel.getBoundingClientRect().top < innerHeight + 200 && guard++ < 4) addBatch(true);
  };
  window.ptSetGroup = function (g, replay) {
    ptGroup = g;
    tabs.forEach(t => { const on = t.dataset.grp === g; t.classList.toggle('on', on); t.setAttribute('aria-selected', on); });
    search.value = ''; clearBtn.classList.remove('show');
    if (replay) { const b = list.closest('.modal-body'); if (b) b.scrollTop = 0; }
    ptRender(replay);
  };
  tabs.forEach(t => t.addEventListener('click', () => {
    if (t.dataset.grp === ptGroup && !search.value) return;
    dzHaptic.selection();
    ptSetGroup(t.dataset.grp, true);
  }));
  search.addEventListener('input', () => { clearBtn.classList.toggle('show', search.value.trim().length > 0); ptRender(false); });
  clearBtn.addEventListener('click', () => { search.value = ''; clearBtn.classList.remove('show'); ptRender(false); search.focus(); });
  ptRender(false);
  refreshProfileTypeUI();
})();

function refreshProfileTypeUI() {
  document.querySelectorAll('.pt-chip').forEach(chip => {
    chip.classList.toggle('selected', chip.dataset.value === currentProfileType);
  });
  const label  = document.getElementById('pt-current-label');
  const remove = document.getElementById('pt-remove');
  const menuEl = document.getElementById('menu-profile-type');
  const sideEl = document.getElementById('gsProfileType'), stEl = document.getElementById('st-type');
  if (label)  label.textContent = currentProfileType || 'None';
  if (remove) remove.hidden = !currentProfileType;
  if (menuEl) menuEl.textContent = currentProfileType;
  if (sideEl) sideEl.textContent = currentProfileType || 'Not set';
  if (stEl) stEl.textContent = currentProfileType || 'Not set';
}

function saveProfileType(value, chipEl) {
  if (chipEl) chipEl.classList.add('busy');
  fetch('ajax_update_user.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'field=profile_type&value=' + encodeURIComponent(value)
  })
  .then(r => r.json())
  .then(data => {
    if (chipEl) chipEl.classList.remove('busy');
    if (data.success) {
      currentProfileType = value;
      refreshProfileTypeUI();
      showToast(value ? 'Professional Mode type set to ' + value : 'Professional Mode type removed');
    } else {
      showToast('Error: ' + (data.msg || 'could not save Professional Mode type'));
    }
  })
  .catch(() => {
    if (chipEl) chipEl.classList.remove('busy');
    showToast('Network error — could not save Professional Mode type');
  });
}

// ══ PRIVACY: levels, tags & mentions, lists ══
const PRIV_LV = <?= json_encode($pv_js, JSON_UNESCAPED_UNICODE) ?>;
const PRF = <?= json_encode([
  'tag_who' => $priv['tag_who'], 'tag_approve' => (int)$priv['tag_approve'],
  'mention_who' => $priv['mention_who'], 'mention_feature' => (int)$priv['mention_feature'],
  'show_trackers' => $priv['show_trackers'], 'show_tracking' => $priv['show_tracking'], 'show_pods' => $priv['show_pods'],
  'allow_messages' => !empty($settings['allow_messages']) ? 1 : 0, 'show_online_status' => !empty($settings['show_online_status']) ? 1 : 0,
  'online_visibility' => (string)$act_online, 'last_seen_visibility' => (string)$act_last,
  'def_hide_likes' => (int)($settings['def_hide_likes'] ?? 0), 'def_hide_reposts' => (int)($settings['def_hide_reposts'] ?? 0),
  'def_hide_saves' => (int)($settings['def_hide_saves'] ?? 0), 'def_hide_shares' => (int)($settings['def_hide_shares'] ?? 0),
  'def_ai_label' => (int)($settings['def_ai_label'] ?? 0), 'def_allow_download' => (int)($settings['def_allow_download'] ?? 1),
  'notifications_enabled' => $notif_on ? 1 : 0,
  'haptics_enabled' => $haptics_on ? 1 : 0,
] + $priv_lim) ?>;
const PR = { level: <?= json_encode($priv_level) ?>, busy: false };
const LS_FIELDS = ['show_trackers', 'show_tracking', 'show_pods'];
const LS_TXT = { everyone: 'Anyone can see it', trackers: 'Only your trackers', me: 'Hidden. Only you' };
const gid = id => document.getElementById(id);

async function prCall(action, fields) {
  const fd = new FormData(); fd.append('priv_action', action);
  Object.keys(fields || {}).forEach(k => fd.append(k, fields[k]));
  let d;
  try { const r = await fetch(window.location.pathname, { method: 'POST', body: fd }); d = await r.json(); }
  catch (e) { throw new Error('Network error. Please try again.'); }
  if (!d.success) throw new Error(d.msg || 'Could not save.');
  return d;
}

function prRender() {
  const L = PRIV_LV[PR.level]; if (!L) return;
  gid('pv-hero-n').textContent = L.name;
  gid('pv-hero-s').textContent = L.sum;
  gid('pv-hero-ic').className = L.icon;
  document.querySelectorAll('#pv-meter span').forEach((s, i) => s.classList.toggle('on', i < L.n));
  document.querySelectorAll('#pv-levels .pv-card').forEach(c => {
    const on = c.dataset.lv === PR.level;
    c.classList.toggle('on', on); c.setAttribute('aria-checked', on ? 'true' : 'false');
  });
  document.querySelectorAll('#pv-mx [data-lv]').forEach(c => c.classList.toggle('sel', c.dataset.lv === PR.level));
  const needs = PR.level === 'private' || PR.level === 'full';
  ['row-trackreqs', 'gsReqRow'].forEach(id => { const e = gid(id); if (e) e.style.display = needs ? '' : 'none'; });
  ['gsPrivacy', 'st-priv', 'menu-priv-level', 'trk-stat-lvl'].forEach(id => { const e = gid(id); if (e) e.textContent = L.name; });
}

async function prPickLevel(lv) {
  if (PR.busy || lv === PR.level) return;
  const prev = PR.level;
  PR.busy = true; gid('pv-levels').classList.add('busy');
  PR.level = lv; prRender();
  try { await prCall('level', { level: lv }); showToast('Privacy level set to ' + PRIV_LV[lv].name); }
  catch (e) { PR.level = prev; prRender(); showToast(e.message); }
  PR.busy = false; gid('pv-levels').classList.remove('busy');
}

function prRevert(field) {
  document.querySelectorAll('[data-pf="' + field + '"]').forEach(el => {
    if (el.type === 'radio') el.checked = (String(el.value) === String(PRF[field]));
    else if (el.type === 'checkbox') el.checked = (+PRF[field] === 1);
  });
}
async function prField(field, value) {
  try {
    await prCall('pref', { field: field, value: value });
    PRF[field] = value;
    prOptRender();
    showToast('Saved');
  } catch (e) { prRevert(field); showToast(e.message); }
}
const TG_NAMES = { everyone: 'everyone', tracked: 'people you track', none: 'no one' };
const TG_HERO = ['Open to everyone', 'Balanced', 'Protected', 'Locked down'];
const LS_NAMES = { show_trackers: 'Trackers', show_tracking: 'Tracking', show_pods: 'Pods' };
const LS_NOUN = { show_trackers: 'trackers', show_tracking: 'people you track', show_pods: 'pods' };
const LS_HERO = ['All visible', 'Partly hidden', 'Mostly hidden', 'All hidden'];
const LM_KEYS = { show_trackers: 'trackers', show_tracking: 'tracking', show_pods: 'pods' };
const lmField = (f, a) => 'lim_' + LM_KEYS[f] + '_' + a;
const LMSAVED = Object.assign({}, PRF);
const lmTimers = {};
function prMeter(id, n) { document.querySelectorAll('#' + id + ' span').forEach((s, i) => s.classList.toggle('on', i < n)); }

// every choice tile / card / segmented button shares one handler
async function prOpt(field, value) {
  if (PRF[field] === value) return;
  const prev = PRF[field];
  PRF[field] = value; prOptRender();
  try { await prCall('pref', { field: field, value: value }); showToast('Saved'); }
  catch (e) { PRF[field] = prev; prOptRender(); showToast(e.message); }
}
function prOptRender() {
  document.querySelectorAll('.pv-opt').forEach(c => {
    const on = PRF[c.dataset.g] === c.dataset.v;
    c.classList.toggle('on', on); c.setAttribute('aria-checked', on ? 'true' : 'false');
  });
  document.querySelectorAll('.tg-desc').forEach(p => {
    const t = document.querySelector('.pv-opt.tile.on[data-g="' + p.dataset.for + '"]');
    if (t) p.textContent = t.dataset.desc;
  });
  prTagsUI(); prListsUI();
}
function prTagsUI() {
  const n = gid('tg-hero-n'); if (!n) return;
  const lvl = 1 + (PRF.tag_who !== 'everyone' ? 1 : 0) + (PRF.mention_who !== 'everyone' ? 1 : 0) + (+PRF.tag_approve ? 1 : 0);
  n.textContent = TG_HERO[lvl - 1]; prMeter('tg-meter', lvl);
  gid('tg-hero-s').textContent = 'Tags from ' + TG_NAMES[PRF.tag_who] + ', @mentions from ' + TG_NAMES[PRF.mention_who] + '.' +
    (+PRF.tag_approve ? ' You approve every tag before it shows on your profile.' : ' Tags show on your profile straight away.');
  const put = (id, who, approve, noun) => {
    const v = gid(id).querySelector('.ls-pv-v');
    if (who === 'everyone') { v.textContent = 'Anyone can ' + noun + (approve ? ', you approve first' : ''); v.className = 'ls-pv-v everyone'; }
    else if (who === 'tracked') { v.textContent = 'Only people you track'; v.className = 'ls-pv-v trackers'; }
    else { v.textContent = 'Nobody can'; v.className = 'ls-pv-v me'; }
  };
  put('tg-pv-tag', PRF.tag_who, +PRF.tag_approve, 'tag you');
  put('tg-pv-mention', PRF.mention_who, 0, 'mention you');
}

// ── how many entries each group may see (0 = no limit) ──
function lmAllowed(f, a) { const v = PRF[f]; return v === 'everyone' || (v === 'trackers' && a === 'trk'); }
function lmSet(f, a, n, now) {
  const fld = lmField(f, a);
  n = Math.max(-1, Math.min(10000, Math.round(+n || 0)));
  PRF[fld] = n; prOptRender();
  clearTimeout(lmTimers[fld]);
  lmTimers[fld] = setTimeout(async () => {
    const sent = PRF[fld];
    try { await prCall('pref', { field: fld, value: sent }); LMSAVED[fld] = sent; showToast('Saved'); }
    catch (e) { PRF[fld] = LMSAVED[fld]; prOptRender(); showToast(e.message); }
  }, now ? 0 : 600);
}
function lmMode(f, a, mode) { const cur = +PRF[lmField(f, a)] || 0; lmSet(f, a, mode < 0 ? -1 : mode > 0 ? (cur > 0 ? cur : 25) : 0, true); }
function lmStep(f, a, d) { const c0 = +PRF[lmField(f, a)] || 25, cur = c0 < 0 ? 25 : c0; lmSet(f, a, Math.max(1, cur + d), false); }
function lmType(f, a, v) { if (v === '' || isNaN(+v)) return; lmSet(f, a, Math.max(1, +v), false); }

function prListsUI() {
  if (!gid('ls-hero-n')) return;
  let score = 0, limits = 0;
  LS_FIELDS.forEach(f => {
    score += { everyone: 0, trackers: 1, me: 2 }[PRF[f]] || 0;
    const h = gid('ls-hint-' + f);
    if (h) h.textContent = PRF[f] === 'everyone' ? 'Anyone who visits your profile can open your ' + LS_NAMES[f] + ' list.'
      : PRF[f] === 'trackers' ? 'Only people who track you can open your ' + LS_NAMES[f] + ' list.'
      : 'Only you can open your ' + LS_NAMES[f] + ' list.';
    ['trk', 'non'].forEach(a => {
      const n = +PRF[lmField(f, a)] || 0, ok = lmAllowed(f, a);
      if (ok && n !== 0) limits++;
      const row = gid('lm-' + f + '-' + a);
      if (row) {
        row.classList.toggle('off', !ok);
        row.querySelector('.lm-nolimit').classList.toggle('on', n === 0);
        row.querySelector('.lm-limit').classList.toggle('on', n > 0);
        row.querySelector('.lm-hide').classList.toggle('on', n < 0);
        row.querySelector('.lm-body').style.display = (ok && n > 0) ? '' : 'none';
        const inp = row.querySelector('input'); if (inp && document.activeElement !== inp) inp.value = n > 0 ? n : '';
        row.querySelectorAll('.lm-chips button').forEach(b => b.classList.toggle('on', +b.dataset.n === n));
        row.querySelector('.lm-state').textContent = !ok ? "This group can't open this list."
          : n < 0 ? 'Hidden. This group sees nothing and is told you keep the list private.'
          : n > 0 ? 'Can see up to ' + n + ' ' + LS_NOUN[f] + '.' : 'Can see the whole list.';
      }
      const cell = gid('lv-' + f + '-' + a);
      if (cell) { cell.textContent = (!ok || n < 0) ? 'Hidden' : n > 0 ? 'Up to ' + n : 'Sees all'; cell.className = 'lv-c ' + ((!ok || n < 0) ? 'me' : n > 0 ? 'trackers' : 'everyone'); }
    });
  });
  const lvl = score === 0 ? 1 : score <= 2 ? 2 : score <= 5 ? 3 : 4;
  gid('ls-hero-n').textContent = LS_HERO[lvl - 1]; prMeter('ls-meter', lvl);
  gid('ls-hero-s').textContent = LS_FIELDS.map(f => LS_NAMES[f] + ': ' + ({ everyone: 'everyone', trackers: 'my trackers', me: 'only me' }[PRF[f]])).join('  ·  ');
  const sl = gid('ls-stat-limits'); if (sl) sl.textContent = limits;
  const m = gid('ls-master'); if (m) m.checked = LS_FIELDS.every(f => PRF[f] === 'me');
}
async function prListsAll(on) {
  const val = on ? 'me' : 'everyone';
  try {
    await prCall('lists', { value: val });
    LS_FIELDS.forEach(f => { PRF[f] = val; });
    prOptRender(); showToast(on ? 'All three lists are hidden' : 'Lists are visible to everyone');
  } catch (e) { prOptRender(); showToast(e.message); }
}
// open another modal from the Profile status modal
function stGo(id) { closeModal('modal-status'); setTimeout(() => openModal(id), 60); }
prRender(); prOptRender();

// ══ CREATOR TOOLS: search ══
(function initCreatorTools() {
  const input = document.getElementById('ct-search'), clear = document.getElementById('ct-clear'),
        none = document.getElementById('ct-none'), count = document.getElementById('ct-count');
  if (!input) return;
  const rows = Array.from(document.querySelectorAll('#ct-list .ct-row'));
  function apply() {
    const q = input.value.trim().toLowerCase();
    clear.classList.toggle('show', q.length > 0);
    let shown = 0;
    rows.forEach(r => { const m = !q || r.dataset.search.includes(q); r.style.display = m ? '' : 'none'; if (m) shown++; });
    document.querySelectorAll('#ct-list .ct-cat').forEach(c => {
      c.style.display = Array.from(c.querySelectorAll('.ct-row')).some(r => r.style.display !== 'none') ? '' : 'none';
    });
    none.classList.toggle('show', shown === 0);
    if (count) count.textContent = shown;
  }
  input.addEventListener('input', apply);
  clear.addEventListener('click', () => { input.value = ''; apply(); input.focus(); });
})();

// ── Availability check ─────────────────────────────────────
let _availTimers = {};
function checkAvailability(field, val) {
  clearTimeout(_availTimers[field]);
  const statusEl   = document.getElementById('status-' + field);
  const feedbackEl = document.getElementById('feedback-' + field);
  const suggestEl  = document.getElementById('suggestions-' + field);
  const saveBtn    = document.getElementById('save-' + field);
  if (!statusEl) return;
  if (val.length < 2) {
    statusEl.textContent = ''; feedbackEl.textContent = ''; suggestEl.innerHTML = '';
    return;
  }
  statusEl.textContent = '⏳';
  _availTimers[field] = setTimeout(() => {
    fetch(window.location.pathname, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'check_availability=1&field=' + encodeURIComponent(field) + '&value=' + encodeURIComponent(val)
    })
    .then(r => r.json())
    .then(data => {
      if (data.status === 'available') {
        statusEl.textContent = '✅';
        feedbackEl.textContent = '';
        feedbackEl.className = 'field-feedback success';
        suggestEl.innerHTML = '';
        if (saveBtn) saveBtn.disabled = (field === 'username' && UN.locked);
      } else if (data.status === 'taken') {
        statusEl.textContent = '❌';
        feedbackEl.textContent = 'Already taken — try one below:';
        feedbackEl.className = 'field-feedback taken';
        if (saveBtn) saveBtn.disabled = true;
        suggestEl.innerHTML = '';
        (data.suggestions || []).forEach(s => {
          const c = document.createElement('span');
          c.className = 'suggestion-chip';
          c.textContent = s;
          c.onclick = () => {
            if (field === 'username' && UN.locked) return;
            document.getElementById('input-' + field).value = s;
            checkAvailability(field, s);
          };
          suggestEl.appendChild(c);
        });
      } else if (data.status === 'short') {
        statusEl.textContent = '';
        feedbackEl.textContent = 'Too short';
        feedbackEl.className = 'field-feedback error';
      }
    });
  }, 500);
}

// ── Password change ────────────────────────────────────────
function changePassword(btn) {
  const currentEl = document.getElementById('pw-current');
  const newEl     = document.getElementById('pw-new');
  const confirmEl = document.getElementById('pw-confirm');
  const feedback  = document.getElementById('pw-feedback');
  const current = currentEl.value, next = newEl.value, confirm = confirmEl.value;

  feedback.textContent = '';
  feedback.className = 'field-feedback';

  if (!current || !next || !confirm) { feedback.textContent = 'Please fill in all three fields.'; feedback.className = 'field-feedback error'; return; }
  if (next !== confirm) { feedback.textContent = 'New passwords do not match.'; feedback.className = 'field-feedback error'; return; }
  if (next.length < 8) { feedback.textContent = 'New password must be at least 8 characters.'; feedback.className = 'field-feedback error'; return; }

  const orig = btn.textContent;
  btn.disabled = true;
  btn.textContent = 'Updating…';

  fetch('change_password.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'current_password=' + encodeURIComponent(current)
        + '&new_password=' + encodeURIComponent(next)
        + '&confirm_password=' + encodeURIComponent(confirm)
  })
  .then(r => r.json())
  .then(data => {
    btn.disabled = false;
    if (data.success) {
      feedback.textContent = data.msg || 'Password updated successfully.';
      feedback.className = 'field-feedback success';
      btn.textContent = 'Updated ✓';
      currentEl.value = newEl.value = confirmEl.value = '';
      setTimeout(() => btn.textContent = orig, 1500);
      showToast('Password updated');
    } else {
      feedback.textContent = data.msg || 'Could not update password.';
      feedback.className = 'field-feedback error';
      btn.textContent = orig;
    }
  })
  .catch(() => {
    btn.disabled = false;
    btn.textContent = orig;
    feedback.textContent = 'Network error — could not update password.';
    feedback.className = 'field-feedback error';
  });
}

// ── Track requests ─────────────────────────────────────────
function handleTrackRequest(action, requesterId, btn) {
  const row  = document.getElementById('treq-' + requesterId);
  const btns = row ? row.querySelectorAll('button') : [];
  btns.forEach(b => { b.disabled = true; b.style.opacity = '0.5'; });
  const origHtml = btn.innerHTML;
  btn.innerHTML = '<i class="ri-loader-4-line spinning"></i>';

  fetch(window.location.pathname, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: (action === 'approve' ? 'approve_track=1' : 'deny_track=1') + '&requester_id=' + requesterId
  })
  .then(r => r.json())
  .then(data => {
    if (!data.success) throw new Error('fail');
    if (row) {
      row.style.opacity = '0';
      row.style.transform = 'translateX(16px)';
      setTimeout(() => {
        row.remove(); trkRefresh();
        const badge = document.querySelector('[onclick="openModal(\'modal-trackreqs\')"] .menu-badge');
        if (badge) {
          const n = parseInt(badge.textContent) - 1;
          if (n <= 0) badge.remove(); else badge.textContent = n;
        }
        const list = document.getElementById('trackRequestsList');
        if (list && list.querySelectorAll('.track-req-row').length === 0) {
          list.innerHTML = '<div class="empty-state"><div class="empty-title">No pending requests</div><p class="empty-desc">When someone asks to track you, their request will show up here.</p></div>';
        }
      }, 320);
    }
    showToast(action === 'approve' ? 'Approved — they can now view your profile' : 'Request denied');
  })
  .catch(() => {
    btn.innerHTML = origHtml;
    btns.forEach(b => { b.disabled = false; b.style.opacity = '1'; });
    showToast('Something went wrong — please try again');
  });
}

// keep the hero cards in step with what you do in the lists
function trkRefresh() {
  const n = document.querySelectorAll('#trackRequestsList .track-req-row').length;
  const h = document.getElementById('trk-hero-n'), s = document.getElementById('trk-stat-wait');
  if (h) h.textContent = n > 0 ? n + ' request' + (n === 1 ? '' : 's') + ' waiting' : 'All caught up';
  if (s) s.textContent = n;
}
function modRefresh() {
  let t = 0;
  ['blocked', 'muted', 'restricted'].forEach(k => {
    const c = document.getElementById('modcount-' + k), n = c ? (parseInt(c.textContent) || 0) : 0;
    t += n; const s = document.getElementById('modstat-' + k); if (s) s.textContent = n;
  });
  const h = document.getElementById('md-hero-n');
  if (h) h.textContent = t > 0 ? t + ' account' + (t === 1 ? '' : 's') + ' managed' : 'Nobody managed yet';
}

// ── Moderation tabs ────────────────────────────────────────
function switchModTab(tab) {
  document.querySelectorAll('.mod-tab').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.mod-panel').forEach(p => p.classList.remove('active'));
  document.getElementById('modtab-' + tab).classList.add('active');
  document.getElementById('modpanel-' + tab).classList.add('active');
  document.querySelectorAll('#md-mx [data-md]').forEach(c => c.classList.toggle('sel', c.dataset.md === tab));
}

// ── Unblock / Unmute / Unrestrict ──────────────────────────
function modAction(action, uid, btn) {
  btn.disabled = true;
  const origText = btn.textContent;
  btn.textContent = '…';

  const endpoints = {
    unblock:    { url: 'unblock.php',            body: 'unblock_user_id=' + uid },
    unmute:     { url: 'privacy_moderation.php', body: 'mr_action=unmute&target_id=' + uid },
    unrestrict: { url: 'privacy_moderation.php', body: 'mr_action=unrestrict&target_id=' + uid },
  };
  const prefixes    = { unblock: 'blocked', unmute: 'muted', unrestrict: 'restricted' };
  const emptyLabels = { blocked: 'No blocked accounts', muted: 'No muted accounts', restricted: 'No restricted accounts' };
  const ep = endpoints[action], prefix = prefixes[action];
  if (!ep) return;

  fetch(ep.url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: ep.body
  })
  .then(r => r.json())
  .then(data => {
    if (!(data.success || data.status === 'ok')) throw new Error('fail');
    const card = document.getElementById(prefix + '-' + uid);
    if (card) {
      card.classList.add('removing');
      setTimeout(() => {
        card.remove();
        const countEl = document.getElementById('modcount-' + prefix);
        if (countEl) countEl.textContent = Math.max(0, parseInt(countEl.textContent) - 1);
        modRefresh();
        const panel = document.getElementById('modpanel-' + prefix);
        const listEl = panel ? panel.querySelector('.mod-list') : null;
        if (listEl && listEl.querySelectorAll('.mod-user-row').length === 0) {
          listEl.insertAdjacentHTML('beforeend',
            '<div class="mod-empty"><div class="empty-title">' + emptyLabels[prefix] + '</div></div>');
        }
      }, 260);
    }
    showToast({ unblock: 'User unblocked', unmute: 'User unmuted', unrestrict: 'Restriction removed' }[action] || 'Done');
  })
  .catch(() => {
    btn.disabled = false;
    btn.textContent = origText;
    showToast('Something went wrong — please try again');
  });
}

// ══ HIGHLIGHTS ══
const HL_MAX = 100, HL_IMG_MB = 10, HL_VID_MB = 50;
const HL_TEXT_BG   = <?= json_encode(HL_TEXT_BG) ?>;
const HL_USER_URL  = <?= json_encode(HL_USER_URL) ?>;
const HL_GROUP_URL = <?= json_encode(HL_GROUP_URL) ?>;
let hlItems = [], hlMode = 'whole', hlBusy = false, hlCover = null, hlSong = null, hlCur = null, hlDirty = false, hlStage = [];
let hlP = null, hlSaving = false; // hlP = edits waiting for "Save new changes"
const esc = v => String(v == null ? '' : v).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const hlShort = (v, n) => { v = String(v || ''); return v.length > n ? v.slice(0, n - 1) + '…' : v; };
const $id = id => document.getElementById(id);

// One helper for every highlight request. Files always go one per request.
async function hlCall(action, fields, files) {
  const fd = new FormData(); fd.append('hl_action', action);
  Object.keys(fields || {}).forEach(k => fd.append(k, fields[k]));
  Object.keys(files || {}).forEach(k => fd.append(k, files[k], files[k].name || 'clip.wav'));
  let d;
  try { const r = await fetch(window.location.pathname, { method: 'POST', body: fd }); d = await r.json(); }
  catch (e) { throw new Error('Upload failed. The file may be too large for the server.'); }
  if (!d.success) throw new Error(d.msg || 'Something went wrong.');
  return d;
}

// ── Text on highlights: @user (red), group name (blue), more / less ──
// These three functions (hlResolve, hlRichText, hlCapHTML) can be copied to profile.php to show highlights there.
const hlResolveCache = {};
async function hlResolve(text) {
  const t = String(text || '').trim();
  if (!t) return { users: {}, groups: [] };
  if (hlResolveCache[t]) return hlResolveCache[t];
  try {
    const d = await hlCall('resolve', { text: t });
    const map = { users: d.users || {}, groups: d.groups || [] };
    hlResolveCache[t] = map; return map;
  } catch (e) { return { users: {}, groups: [] }; }
}
function hlRichText(text, map) {
  text = String(text || ''); map = map || {};
  const users = map.users || {};
  const groups = (map.groups || []).slice().sort((a, b) => b.name.length - a.name.length);
  const gid = {}; groups.forEach(g => { gid[g.name.toLowerCase()] = g.id; });
  let re = '(@[A-Za-z0-9_.]+)';
  if (groups.length) re += '|(^|[^\\w@])(' + groups.map(g => g.name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|') + ')(?![\\w])';
  const rx = new RegExp(re, 'gi');
  let out = '', last = 0, m;
  while ((m = rx.exec(text)) !== null) {
    if (m[1]) {
      const core = m[1].slice(1).replace(/\.+$/, ''), id = users[core.toLowerCase()];
      if (!id) continue;
      out += esc(text.slice(last, m.index)) + '<a class="hl-men user" href="' + esc(HL_USER_URL + id) + '">@' + esc(core) + '</a>';
      last = m.index + 1 + core.length;
      rx.lastIndex = last;
    } else {
      const start = m.index + m[2].length, id = gid[m[3].toLowerCase()];
      if (!id) continue;
      out += esc(text.slice(last, start)) + '<a class="hl-men group" href="' + esc(HL_GROUP_URL + id) + '">' + esc(m[3]) + '</a>';
      last = start + m[3].length;
    }
  }
  return (out + esc(text.slice(last))).replace(/\n/g, '<br>');
}
// The words shown in the middle of a media. Long text is clamped, "more" opens it and "less" folds it back.
function hlCapHTML(text, map) {
  const long = text.length > 120 || (text.match(/\n/g) || []).length >= 3;
  return '<div class="hl-cap-wrap"><div class="hl-cap' + (long ? ' clamp' : '') + '">' + hlRichText(text, map) + '</div>' +
    (long ? '<button type="button" class="hl-more" onclick="hlMore(this)">more</button>' : '') + '</div>';
}
function hlMore(btn) {
  const cap = btn.previousElementSibling, collapsed = cap.classList.toggle('clamp');
  btn.textContent = collapsed ? 'more' : 'less';
}

// Filters a picked list: only images/videos, size limits, and a max count.
function hlFilterFiles(list, max) {
  let ok = [], skipped = 0;
  list.forEach(f => {
    const isImg = f.type.startsWith('image/'), isVid = f.type.startsWith('video/');
    if (!isImg && !isVid) { skipped++; return; }
    if (f.size > (isImg ? HL_IMG_MB : HL_VID_MB) * 1048576) { skipped++; return; }
    ok.push(f);
  });
  let trimmed = false;
  if (ok.length > max) { ok = ok.slice(0, max); trimmed = true; }
  return { ok, skipped, trimmed };
}
// A staged item = a photo/video/GIF or a text-only item, plus its own words, optional song and mute choice
const hlMake = files => files.map(f => ({ file: f, kind: f.type.startsWith('video/') ? 'video' : 'image', text: '', song: null, mute: false, url: URL.createObjectURL(f) }));
const hlFree = arr => arr.forEach(it => { if (it.url) URL.revokeObjectURL(it.url); });
const hlIsVid = it => it.kind === 'video';
const hlArr = ctx => ctx === 'c' ? hlItems : hlStage;

(function initHighlights() {
  const input = $id('hl-files');
  if (input) input.addEventListener('change', function () {
    const texts = hlItems.filter(i => i.kind === 'text');
    const r = hlFilterFiles(Array.from(input.files || []), Math.max(0, HL_MAX - texts.length));
    let msg = '';
    if (r.trimmed) msg = 'Only the first ' + HL_MAX + ' media were kept.';
    if (r.skipped) msg = (msg ? msg + ' ' : '') + r.skipped + ' file' + (r.skipped === 1 ? ' was' : 's were') + ' skipped (wrong type or too large).';
    hlFree(hlItems.filter(i => i.kind !== 'text'));
    hlItems = hlMake(r.ok).concat(texts);
    hlRenderPick(); hlRenderItems('c'); hlSetStatus(msg, !!msg && !hlItems.length);
    input.value = '';
  });
  const cv = $id('hl-cover-file');
  if (cv) cv.addEventListener('change', function () {
    const f = cv.files && cv.files[0]; cv.value = '';
    if (!f) return;
    if (!f.type.startsWith('image/') || f.size > HL_IMG_MB * 1048576) { hlSetStatus('The cover must be an image under ' + HL_IMG_MB + ' MB.', true); return; }
    hlCover = f; hlRenderExtras(); hlSetStatus('');
  });
})();

function hlSetStatus(msg, isErr) {
  const s = $id('hl-status'); if (!s) return;
  s.textContent = msg || ''; s.className = 'hl-status' + (isErr ? ' error' : '');
}
function hlRenderPick() {
  const title = $id('hl-pick-title'), sub = $id('hl-pick-sub');
  const prev = $id('hl-cover-prev'), icon = $id('hl-pick-icon');
  if (prev._url) { URL.revokeObjectURL(prev._url); prev._url = null; }
  if (!hlItems.length) {
    title.textContent = 'Choose photos or videos'; sub.textContent = 'Add 1 to 100 per highlight.';
    prev.classList.remove('show'); icon.style.display = ''; icon.className = 'ri-image-add-line'; return;
  }
  title.textContent = hlItems.length + ' of ' + HL_MAX + ' selected';
  sub.textContent = 'Tap to change your photos and videos.';
  const first = hlItems[0];
  if (first.kind === 'image') {
    prev._url = URL.createObjectURL(first.file); prev.src = prev._url; prev.classList.add('show'); icon.style.display = 'none';
  } else { prev.classList.remove('show'); icon.style.display = ''; icon.className = first.kind === 'video' ? 'ri-video-line' : 'ri-text'; }
}
function hlRenderExtras() {
  const cvPrev = $id('hl-cv-prev'), cvIcon = $id('hl-cv-icon');
  if (cvPrev._url) { URL.revokeObjectURL(cvPrev._url); cvPrev._url = null; }
  if (hlCover) {
    cvPrev._url = URL.createObjectURL(hlCover); cvPrev.src = cvPrev._url; cvPrev.classList.add('show'); cvIcon.style.display = 'none';
    $id('hl-cv-title').textContent = 'Cover image added';
    $id('hl-cv-sub').textContent = hlShort(hlCover.name, 34);
  } else {
    cvPrev.classList.remove('show'); cvIcon.style.display = '';
    $id('hl-cv-title').textContent = 'Cover image (optional)';
    $id('hl-cv-sub').textContent = 'Skip it and your first media becomes the cover.';
  }
  $id('hl-cv-x').classList.toggle('show', !!hlCover);
  $id('hl-song-title').textContent = hlSong ? '♪ ' + hlShort(hlSong.title || 'Song clip', 30) : 'Song clip (optional)';
  $id('hl-song-sub').textContent = hlSong ? Math.round(hlSong.secs) + ' second clip. Tap to change it.' : 'Pick the part of a song you want, up to 30 seconds.';
  $id('hl-song-x').classList.toggle('show', !!hlSong);
}
function hlCoverClear() { hlCover = null; hlRenderExtras(); }
function hlSongClear() { hlSong = null; hlRenderExtras(); }
function hlSongPick() { cpOpen(res => { hlSong = res; hlRenderExtras(); }); }

// Create form: choose one song for all media, or a song for each media
function hlSetMode(mode) {
  hlMode = mode;
  document.querySelectorAll('#hl-mode button').forEach(b => b.classList.toggle('on', b.dataset.mode === mode));
  $id('hl-song-wrap').style.display = mode === 'whole' ? '' : 'none';
  $id('hl-mode-help').textContent = mode === 'whole'
    ? 'One song clip plays across every media in this highlight.'
    : 'Each media can have its own song clip. Choose your media, then tap Add song on the ones you want.';
  hlRenderItems('c');
}

// Rows for staged media (used by the create form and by "Add more media" in the editor)
function hlRows(ctx, mode) {
  const arr = hlArr(ctx);
  return arr.map((it, i) => {
    let acts = '';
    acts += '<button type="button" class="hle-chip' + (it.text ? ' on' : '') + '" onclick="hlItemText(\'' + ctx + '\',' + i + ')"><i class="ri-text"></i>' + (it.text ? 'Edit text' : 'Add text') + '</button>';
    if (mode === 'each') {
      acts += '<button type="button" class="hle-chip' + (it.song ? ' on' : '') + '" onclick="hlItemSong(\'' + ctx + '\',' + i + ')"><i class="ri-music-2-line"></i>' + (it.song ? esc(hlShort(it.song.title || 'Song', 12)) : 'Add song') + '</button>';
      if (it.song) acts += '<button type="button" class="hle-chip x" aria-label="Remove song" onclick="hlItemSongRm(\'' + ctx + '\',' + i + ')"><i class="ri-close-line"></i></button>';
    }
    if (hlIsVid(it)) {
      acts += '<button type="button" class="hle-chip' + (it.mute ? ' on' : '') + '" onclick="hlItemMute(\'' + ctx + '\',' + i + ')"><i class="' + (it.mute ? 'ri-volume-mute-line' : 'ri-volume-up-line') + '"></i>' + (it.mute ? 'Muted' : 'Sound') + '</button>';
    }
    const th = it.kind === 'image' ? '<img src="' + it.url + '" alt="">' : '<i class="' + (it.kind === 'video' ? 'ri-video-line' : 'ri-text') + '"></i>';
    const nm = it.kind === 'text' ? 'Text: ' + hlShort(it.text.replace(/\s+/g, ' '), 24) : hlShort(it.file.name, 28);
    return '<div class="hl-row"><div class="hl-row-th">' + th + '</div>' +
      '<div class="hl-row-info"><strong>' + esc(nm) + '</strong><div class="hle-actions">' + acts + '</div></div>' +
      '<button type="button" class="hl-x show" aria-label="Remove from selection" onclick="hlItemDel(\'' + ctx + '\',' + i + ')"><i class="ri-close-line"></i></button></div>';
  }).join('');
}
function hlRenderItems(ctx) {
  if (ctx === 'e') { hlRenderStageBox(); return; }
  const box = $id('hl-items'); if (!box) return;
  const show = hlItems.length > 0;
  box.innerHTML = show
    ? '<div class="hl-rows-head">Tap Add text to write words on a media (tag people with @name).' + (hlMode === 'each' ? ' Tap Add song on any media that should have its own song.' : '') + (hlItems.some(hlIsVid) ? ' Videos: Sound keeps the video\'s own audio, Muted lets only the song play.' : '') + '</div><div class="hl-rows">' + hlRows('c', hlMode) + '</div>'
    : '';
  box.style.marginBottom = show ? '2px' : '0';
}
function hlItemSong(ctx, i) { cpOpen(res => { const it = hlArr(ctx)[i]; if (it) { it.song = res; hlRenderItems(ctx); } }); }
function hlItemSongRm(ctx, i) { const it = hlArr(ctx)[i]; if (it) { it.song = null; hlRenderItems(ctx); } }
function hlItemMute(ctx, i) { const it = hlArr(ctx)[i]; if (it) { it.mute = !it.mute; hlRenderItems(ctx); } }
function hlItemDel(ctx, i) {
  const arr = hlArr(ctx), it = arr[i]; if (!it) return;
  if (it.url) URL.revokeObjectURL(it.url);
  arr.splice(i, 1);
  if (ctx === 'c') hlRenderPick();
  hlRenderItems(ctx);
}
function hlItemText(ctx, i) {
  const it = hlArr(ctx)[i]; if (!it) return;
  htOpen({ src: it.url, type: it.kind, text: it.text, required: it.kind === 'text', allowRemove: it.kind !== 'text' && !!it.text }, t => {
    const cur = hlArr(ctx)[i]; if (!cur) return;
    cur.text = t; if (ctx === 'c') hlRenderPick(); hlRenderItems(ctx);
  });
}
// A brand new text-only media (shown on Text.jpg)
function hlAddText(ctx) {
  const used = ctx === 'c' ? hlItems.length : (hlCur.media.length - hlP.del.length + hlStage.length);
  if (used >= HL_MAX) { showToast('A highlight can hold up to ' + HL_MAX + ' media.'); return; }
  htOpen({ src: null, type: 'text', text: '', required: true, allowRemove: false }, t => {
    hlArr(ctx).push({ file: null, kind: 'text', text: t, song: null, mute: false, url: null });
    if (ctx === 'c') hlRenderPick();
    hlRenderItems(ctx);
  });
}

async function hlUpload(btn) {
  if (hlBusy) return;
  const titleEl = $id('hl-title');
  const name = titleEl.value.trim();
  if (!name) { hlSetStatus('Give your highlight a name.', true); titleEl.focus(); return; }
  if (!hlItems.length) { hlSetStatus('Choose at least one photo or video, or add a text highlight.', true); return; }

  hlBusy = true; btn.disabled = true;
  const orig = btn.textContent; btn.textContent = 'Uploading…';
  const bar = $id('hl-progress'), fill = $id('hl-progress-bar');
  bar.classList.add('show'); fill.style.width = '0';
  hlSetStatus('Creating highlight…');

  const useSong = hlMode === 'whole' && hlSong;
  const items = hlItems.slice();
  let hid = 0, done = 0, failed = 0, lastErr = '', extraErr = '';
  const steps = items.length + (hlCover ? 1 : 0) + (useSong ? 1 : 0); let stepNo = 0;
  const tick = () => { stepNo++; fill.style.width = Math.round(stepNo / steps * 100) + '%'; };
  try {
    hid = (await hlCall('create', { title: name, mode: hlMode })).id;
    for (let i = 0; i < items.length; i++) {
      const it = items[i];
      hlSetStatus('Uploading ' + (i + 1) + ' of ' + items.length + '…');
      try {
        const r = it.kind === 'text'
          ? await hlCall('add_text', { highlight_id: hid, text: it.text })
          : await hlCall('add_media', { highlight_id: hid, caption: it.text || '' }, { media: it.file });
        done++;
        if (hlMode === 'each' && it.song) {
          try { await hlCall('media_song', { highlight_id: hid, media_id: r.id, title: it.song.title || '' }, { song: it.song.blob }); }
          catch (e) { extraErr = 'Some songs did not upload (' + e.message + ')'; }
        }
        if (hlIsVid(it) && it.mute) { try { await hlCall('media_mute', { highlight_id: hid, media_id: r.id, mute: 1 }); } catch (e) {} }
      } catch (e) { failed++; lastErr = e.message; }
      tick();
    }
    if (done === 0) { try { await hlCall('delete', { highlight_id: hid }); } catch (e) {} throw new Error(lastErr || 'No files could be uploaded.'); }
    if (hlCover) { hlSetStatus('Uploading cover…'); try { await hlCall('set_cover', { highlight_id: hid }, { cover: hlCover }); } catch (e) { extraErr += (extraErr ? ' ' : '') + 'Cover: ' + e.message; } tick(); }
    if (useSong) { hlSetStatus('Uploading song clip…'); try { await hlCall('set_song', { highlight_id: hid, title: hlSong.title || '' }, { song: hlSong.blob }); } catch (e) { extraErr += (extraErr ? ' ' : '') + 'Song: ' + e.message; } tick(); }
    showToast(extraErr ? 'Highlight created. ' + extraErr : 'Highlight created' + (failed ? ' (' + failed + ' item' + (failed === 1 ? '' : 's') + ' failed)' : ''));
    hlSetStatus('Done.');
    setTimeout(() => { window.location.href = window.location.pathname + '?open=highlights'; }, extraErr ? 1800 : 700);
  } catch (err) {
    hlSetStatus(err.message || 'Upload failed.', true);
    bar.classList.remove('show'); btn.disabled = false; btn.textContent = orig; hlBusy = false;
  }
}

// ── Text editor sheet ──────────────────────────────────────
const HT = { cb: null, o: null, timer: null, seq: 0, map: null };
function htOpen(o, cb) {
  HT.cb = cb; HT.o = o; HT.map = null;
  $id('ht-input').value = o.text || '';
  $id('ht-status').textContent = ''; $id('ht-status').className = 'hl-status';
  $id('ht-remove').style.display = o.allowRemove ? '' : 'none';
  let bg;
  if (o.type === 'image' && o.src) bg = '<img src="' + esc(o.src) + '" alt="">';
  else if (o.type === 'video' && o.src) bg = '<video src="' + esc(o.src) + '" muted playsinline loop autoplay></video>';
  else bg = '<img src="' + esc(HL_TEXT_BG) + '" alt="">';
  $id('ht-preview').innerHTML = '<div class="hl-view">' + bg + '<div id="ht-capbox"></div></div>';
  const m = $id('modal-ht'); m.classList.add('open'); document.body.style.overflow = 'hidden';
  const body = m.querySelector('.modal-body'); if (body) body.scrollTop = 0;
  htUpdate(true);
  setTimeout(() => $id('ht-input').focus({ preventScroll: true }), 80);
}
function htRender(t) {
  const box = $id('ht-capbox'); if (!box) return;
  box.innerHTML = t.trim() ? hlCapHTML(t, HT.map) : '<div class="hl-cap-wrap"><div class="hl-cap hl-cap-empty">Your text appears here</div></div>';
}
function htUpdate(now) {
  const t = $id('ht-input').value;
  $id('ht-count').textContent = t.length + '/1000';
  htRender(t);
  clearTimeout(HT.timer);
  HT.timer = setTimeout(async () => {
    const seq = ++HT.seq;
    const map = await hlResolve(t);
    if (seq !== HT.seq) return;
    HT.map = map; htRender($id('ht-input').value);
  }, now ? 0 : 350);
}
function htDone() {
  const t = $id('ht-input').value.trim();
  if (HT.o && HT.o.required && !t) { const s = $id('ht-status'); s.textContent = 'Write something first.'; s.className = 'hl-status error'; return; }
  const cb = HT.cb; htClose(); if (cb) cb(t);
}
function htRemove() { const cb = HT.cb; htClose(); if (cb) cb(''); }
function htClose() {
  clearTimeout(HT.timer); HT.seq++; HT.cb = null;
  $id('modal-ht').classList.remove('open');
  $id('ht-preview').innerHTML = '';
  if (!document.querySelector('.modal-overlay.open')) document.body.style.overflow = '';
}

// ── Highlight editor: edits are collected first, then saved together ──
const hlPNew = () => ({ title: null, mode: null, cover: undefined, coverUrl: null, song: undefined, media: {}, del: [] });
const hlPm = id => (hlP.media[id] = hlP.media[id] || {});
const hlFind = id => hlCur.media.find(m => m.id === id);
const hlEffMode = () => (hlP.mode !== null ? hlP.mode : hlCur.mode);
function hlEffMedia(m) {
  const p = hlP.media[m.id] || {};
  let audio = null;
  if (p.song === undefined) { if (m.audio) audio = { title: m.audio_title, pending: false }; }
  else if (p.song) audio = { title: p.song.title, pending: true };
  return { id: m.id, path: m.path, type: m.type, added: m.added,
    text: p.text !== undefined ? p.text : (m.caption || ''), mute: p.mute !== undefined ? p.mute : m.mute, audio: audio };
}
function hlHasPending() {
  if (!hlCur || !hlP) return false;
  if (hlP.title !== null && hlP.title.trim() !== hlCur.title) return true;
  if (hlP.mode !== null && hlP.mode !== hlCur.mode) return true;
  if (hlP.cover !== undefined || hlP.song !== undefined || hlP.del.length || hlStage.length) return true;
  return Object.keys(hlP.media).some(k => Object.keys(hlP.media[k]).length > 0);
}
function hlPendUI() {
  const b = $id('hle-save'), n = $id('hle-note'); if (!b || !n) return;
  const d = hlHasPending();
  b.disabled = !d || hlSaving;
  if (!hlSaving) { n.textContent = d ? 'You have changes that are not saved yet.' : 'No new changes.'; n.className = 'hle-note' + (d ? ' warn' : ''); }
}
function hlDiscard() {
  hlFree(hlStage); hlStage = [];
  if (hlP && hlP.coverUrl) URL.revokeObjectURL(hlP.coverUrl);
  hlP = null;
}

async function hlEdit(id) {
  const m = $id('modal-hl-edit'), root = $id('hle-root');
  hlDirty = false; hlDiscard(); hlP = hlPNew(); hlCur = null;
  root.innerHTML = '<div class="empty-state"><div class="empty-title">Loading…</div></div>';
  hlPendUI();
  m.classList.add('open'); document.body.style.overflow = 'hidden';
  const body = m.querySelector('.modal-body'); if (body) body.scrollTop = 0;
  try { hlCur = (await hlCall('get', { highlight_id: id })).h; hlRenderEdit(); }
  catch (e) { showToast(e.message); hlDiscard(); closeModal('modal-hl-edit'); }
}
async function hlRefresh() { hlCur = (await hlCall('get', { highlight_id: hlCur.id })).h; hlRenderEdit(); }

function hlThumb(path, type, text) {
  let h = type === 'video'
    ? '<video src="' + esc(path) + '#t=0.1" muted playsinline preload="metadata"></video><span class="hl-vid"><i class="ri-play-fill"></i></span>'
    : type === 'text' ? '<img src="' + esc(HL_TEXT_BG) + '" alt="">'
    : '<img src="' + esc(path) + '" alt="" loading="lazy">';
  if (text) h += '<span class="hl-tilecap">' + esc(hlShort(text.replace(/\s+/g, ' '), 90)) + '</span>';
  return h;
}
function hlRenderEdit() {
  const h = hlCur, mode = hlEffMode(), each = mode === 'each';
  const med = h.media.filter(m => hlP.del.indexOf(m.id) < 0).map(hlEffMedia);
  const first = med[0];
  const hasCover = (hlP.cover instanceof File) || (hlP.cover === undefined && !!h.cover);
  let coverHtml;
  if (hlP.cover instanceof File) coverHtml = '<img src="' + hlP.coverUrl + '" alt="">';
  else if (hlP.cover === undefined && h.cover) coverHtml = '<img src="' + esc(h.cover) + '" alt="">';
  else coverHtml = first ? hlThumb(first.path, first.type, '') : '<img src="' + esc(HL_TEXT_BG) + '" alt="">';

  const tiles = med.map(m =>
    '<div class="hle-item" id="hlm-' + m.id + '">' +
      '<div class="hl-sq">' + hlThumb(m.path, m.type, m.text) +
        '<button class="hl-del" type="button" aria-label="Remove media" onclick="hlMediaDel(' + m.id + ')"><i class="ri-close-line"></i></button></div>' +
      '<div class="hle-date">Added ' + esc(m.added) + '</div>' +
      '<div class="hle-actions">' +
        '<button type="button" class="hle-chip' + (m.text ? ' on' : '') + '" onclick="hlMediaText(' + m.id + ')"><i class="ri-text"></i>' + (m.text ? 'Edit text' : 'Add text') + '</button>' +
        (each ? '<button type="button" class="hle-chip' + (m.audio ? ' on' : '') + '" onclick="hlMediaSong(' + m.id + ')"><i class="ri-music-2-line"></i>' + (m.audio ? esc(hlShort(m.audio.title || 'Song', 9)) : 'Add song') + '</button>' +
          (m.audio ? '<button type="button" class="hle-chip x" aria-label="Remove song" onclick="hlMediaSongRm(' + m.id + ')"><i class="ri-close-line"></i></button>' : '') : '') +
        (m.type === 'video' ? '<button type="button" class="hle-chip' + (m.mute ? ' on' : '') + '" onclick="hlMediaMute(' + m.id + ')"><i class="' + (m.mute ? 'ri-volume-mute-line' : 'ri-volume-up-line') + '"></i>' + (m.mute ? 'Muted' : 'Sound') + '</button>' : '') +
      '</div></div>').join('');

  const ws = hlP.song === undefined ? (h.song ? { title: h.song_title, path: h.song } : null) : (hlP.song ? { title: hlP.song.title, pending: true } : null);
  let music;
  if (!each) {
    music = ws
      ? '<div class="hle-song"><i class="ri-music-2-fill"></i><div class="hle-info"><strong>' + esc(ws.title || 'Song clip') + '</strong><span>' + (ws.pending ? 'New clip. It is saved when you tap Save new changes.' : 'Plays across every media in this highlight.') + '</span></div></div>' +
        (ws.path ? '<audio controls preload="none" src="' + esc(ws.path) + '" style="width:100%;height:32px;margin-top:8px"></audio>' : '') +
        '<div class="hle-btns"><button class="hle-btn" onclick="hlSongSet()">Change song</button><button class="hle-btn danger" onclick="hlSongRm()">Remove song</button></div>'
      : '<p class="field-help">No song yet. Add one clip and it plays across every media in this highlight.</p>' +
        '<div class="hle-btns"><button class="hle-btn accent" onclick="hlSongSet()">Add a song clip</button></div>';
  } else {
    music = '<p class="field-help">Each media has its own song. Tap Add song under a media below to give it one, or the × to remove it. A media with no song stays silent (a video keeps its own sound unless you mute it).</p>' +
      (ws ? '<p class="field-help" style="margin-top:6px">Your one-song clip "' + esc(ws.title || 'Song clip') + '" is kept and comes back if you switch to One song for all.</p>' : '');
  }

  const titleVal = hlP.title !== null ? hlP.title : h.title;
  const count = med.length + hlStage.length;
  $id('hle-root').innerHTML =
    '<div class="field-row" style="padding-top:0"><label class="field-label">Name</label>' +
      '<div class="field-control"><input type="text" class="f-input" id="hle-title" maxlength="30" value="' + esc(titleVal) + '" oninput="hlP.title=this.value;hlPendUI()"></div></div>' +

    '<div class="hle-sec"><div class="hle-h">Cover</div><div class="hle-cover"><div class="hl-sq">' + coverHtml + '</div>' +
      '<div class="hle-info"><strong>' + (hasCover ? 'Custom cover' : 'Using your first media') + '</strong><span>' + (hasCover ? 'Not part of the media in this highlight.' : 'Upload an image to use a different cover.') + '</span>' +
      '<div class="hle-btns"><span class="hle-btn accent">' + (hasCover ? 'Change cover' : 'Upload cover') + '<input type="file" accept="image/*" onchange="hlCoverPicked(this)"></span>' +
      (hasCover ? '<button class="hle-btn danger" onclick="hlCoverRm()">Remove</button>' : '') + '</div></div></div></div>' +

    '<div class="hle-sec"><div class="hle-h">Music</div>' +
      '<div class="hl-seg"><button type="button" class="' + (each ? '' : 'on') + '" onclick="hlModeSet(\'whole\')">One song for all</button><button type="button" class="' + (each ? 'on' : '') + '" onclick="hlModeSet(\'each\')">Song per media</button></div>' +
      '<div style="margin-top:12px">' + music + '</div></div>' +

    '<div class="hle-sec"><div class="hle-h">Media · ' + count + ' of ' + HL_MAX + '</div>' +
      '<div class="hle-grid">' + tiles + '</div>' +
      '<p class="field-help" style="margin-top:12px">Tap Add text to write words in the middle of a photo, video or GIF. For videos, Sound keeps the video\'s own audio and Muted silences it so only the song plays.</p>' +
      (count < HL_MAX
        ? '<div class="hle-btns"><span class="hle-btn accent">Add more media<input type="file" accept="image/*,video/*" multiple onchange="hlAddMore(this)"></span><button class="hle-btn" onclick="hlAddText(\'e\')">Add text highlight</button></div><div id="hle-stage" style="margin-top:10px"></div>'
        : '<p class="field-help" style="margin-top:8px">This highlight is full (' + HL_MAX + ' media).</p><div id="hle-stage" style="margin-top:10px"></div>') + '</div>' +

    '<div class="hle-sec hle-danger"><button class="hle-btn danger" onclick="hlDeleteAll()">Delete this highlight</button>' +
      '<p class="field-help" style="margin-top:8px">This permanently deletes the highlight, its media, cover and songs right away.</p></div>';
  hlRenderStageBox();
}

function hlModeSet(mode) {
  hlP.mode = (mode === hlCur.mode) ? null : mode;
  hlRenderEdit();
}
function hlCoverPicked(input) {
  const f = input.files && input.files[0]; input.value = '';
  if (!f) return;
  if (!f.type.startsWith('image/') || f.size > HL_IMG_MB * 1048576) { showToast('The cover must be an image under ' + HL_IMG_MB + ' MB.'); return; }
  if (hlP.coverUrl) URL.revokeObjectURL(hlP.coverUrl);
  hlP.cover = f; hlP.coverUrl = URL.createObjectURL(f);
  hlRenderEdit();
}
function hlCoverRm() {
  if (hlP.coverUrl) { URL.revokeObjectURL(hlP.coverUrl); hlP.coverUrl = null; }
  hlP.cover = hlCur.cover ? null : undefined;
  hlRenderEdit();
}
function hlSongSet() { cpOpen(res => { hlP.song = res; hlRenderEdit(); }); }
function hlSongRm() { hlP.song = hlCur.song ? null : undefined; hlRenderEdit(); }
function hlMediaSong(mid) { cpOpen(res => { hlPm(mid).song = res; hlRenderEdit(); }); }
function hlMediaSongRm(mid) {
  const m = hlFind(mid), pm = hlPm(mid);
  if (m && m.audio) pm.song = null; else delete pm.song;
  hlRenderEdit();
}
function hlMediaMute(mid) {
  const m = hlFind(mid); if (!m) return;
  const pm = hlPm(mid), cur = pm.mute !== undefined ? pm.mute : m.mute, next = cur ? 0 : 1;
  if (next === m.mute) delete pm.mute; else pm.mute = next;
  hlRenderEdit();
}
function hlMediaText(mid) {
  const m = hlFind(mid); if (!m) return;
  const e = hlEffMedia(m);
  htOpen({ src: m.path, type: m.type, text: e.text, required: m.type === 'text', allowRemove: m.type !== 'text' && !!e.text }, t => {
    const pm = hlPm(mid);
    if (t === (m.caption || '')) delete pm.text; else pm.text = t;
    hlRenderEdit();
  });
}
function hlMediaDel(mid) {
  const left = hlCur.media.length - hlP.del.length + hlStage.length;
  if (left <= 1) { showToast('A highlight needs at least one media. Delete the highlight instead.'); return; }
  if (hlP.del.indexOf(mid) < 0) hlP.del.push(mid);
  delete hlP.media[mid];
  hlRenderEdit();
}

// "Add more media": stage the files first so each one can get its own words / song / mute, uploaded on Save
function hlAddMore(input) {
  const room = HL_MAX - (hlCur.media.length - hlP.del.length) - hlStage.length;
  const r = hlFilterFiles(Array.from(input.files || []), Math.max(0, room)); input.value = '';
  if (!r.ok.length) { showToast(room <= 0 ? 'This highlight is full.' : 'No valid files selected'); return; }
  hlStage = hlStage.concat(hlMake(r.ok));
  hlRenderEdit();
  if (r.trimmed || r.skipped) showToast((r.trimmed ? 'Extra files skipped (100 max). ' : '') + (r.skipped ? r.skipped + ' skipped (wrong type or too large).' : ''));
}
function hlRenderStageBox() {
  const box = $id('hle-stage');
  if (box) {
    if (!hlStage.length) box.innerHTML = '';
    else box.innerHTML =
      '<div class="hl-rows-head">' + hlStage.length + ' waiting to be added.' + (hlEffMode() === 'each' ? ' Tap Add song on any that should have its own song.' : '') + ' They upload when you tap Save new changes.</div>' +
      '<div class="hl-rows">' + hlRows('e', hlEffMode()) + '</div>' +
      '<div class="hle-btns"><button class="hle-btn" onclick="hlStageClear()">Clear these</button></div>';
  }
  hlPendUI();
}
function hlStageClear() { hlFree(hlStage); hlStage = []; hlRenderEdit(); }

// Applies every collected edit in one go
async function hlSaveAll() {
  if (hlSaving || !hlCur || !hlP || !hlHasPending()) return;
  const title = (hlP.title !== null ? hlP.title : hlCur.title).trim();
  if (!title) { showToast('Give your highlight a name.'); return; }
  for (const m of hlCur.media) {
    if (hlP.del.indexOf(m.id) >= 0) continue;
    if (m.type === 'text' && !hlEffMedia(m).text.trim()) { showToast('A text media needs some text. Write something or remove it.'); return; }
  }
  const P = hlP, S = hlStage.slice(), id = hlCur.id, mode = hlEffMode(), errs = [];
  const remaining = hlCur.media.length - P.del.length + S.length;
  if (remaining < 1) { showToast('A highlight needs at least one media. Delete the highlight instead.'); return; }

  hlSaving = true;
  const btn = $id('hle-save'), note = $id('hle-note');
  btn.disabled = true; btn.textContent = 'Saving…';

  const T = [], add = (label, fn) => T.push({ label, fn });
  if (P.title !== null && title !== hlCur.title) add('Saving name…', () => hlCall('rename', { highlight_id: id, title: title }));
  if (P.mode !== null && P.mode !== hlCur.mode) add('Saving music mode…', () => hlCall('set_mode', { highlight_id: id, mode: P.mode }));
  if (P.cover instanceof File) add('Uploading cover…', () => hlCall('set_cover', { highlight_id: id }, { cover: P.cover }));
  else if (P.cover === null) add('Removing cover…', () => hlCall('rm_cover', { highlight_id: id }));
  if (P.song) add('Uploading song clip…', () => hlCall('set_song', { highlight_id: id, title: P.song.title || '' }, { song: P.song.blob }));
  else if (P.song === null) add('Removing song…', () => hlCall('rm_song', { highlight_id: id }));

  const dels = P.del.slice();
  const delTasks = () => dels.forEach(mid => add('Removing media…', () => hlCall('del_media', { highlight_id: id, media_id: mid })));
  const delFirst = (hlCur.media.length + S.length > HL_MAX) && (hlCur.media.length - dels.length >= 1);
  if (delFirst) delTasks();

  S.forEach((it, i) => add('Adding ' + (i + 1) + ' of ' + S.length + '…', async () => {
    const r = it.kind === 'text'
      ? await hlCall('add_text', { highlight_id: id, text: it.text })
      : await hlCall('add_media', { highlight_id: id, caption: it.text || '' }, { media: it.file });
    if (mode === 'each' && it.song) { try { await hlCall('media_song', { highlight_id: id, media_id: r.id, title: it.song.title || '' }, { song: it.song.blob }); } catch (e) { errs.push('Song: ' + e.message); } }
    if (hlIsVid(it) && it.mute) { try { await hlCall('media_mute', { highlight_id: id, media_id: r.id, mute: 1 }); } catch (e) {} }
  }));

  Object.keys(P.media).forEach(k => {
    const mid = +k, p = P.media[k];
    if (dels.indexOf(mid) >= 0) return;
    if (p.text !== undefined) add('Saving text…', () => hlCall('set_caption', { highlight_id: id, media_id: mid, text: p.text }));
    if (p.mute !== undefined) add('Saving sound…', () => hlCall('media_mute', { highlight_id: id, media_id: mid, mute: p.mute }));
    if (p.song) add('Uploading song…', () => hlCall('media_song', { highlight_id: id, media_id: mid, title: p.song.title || '' }, { song: p.song.blob }));
    else if (p.song === null) add('Removing song…', () => hlCall('media_song_rm', { highlight_id: id, media_id: mid }));
  });
  if (!delFirst) delTasks();

  for (let i = 0; i < T.length; i++) {
    note.textContent = T[i].label + ' (' + (i + 1) + '/' + T.length + ')'; note.className = 'hle-note warn';
    try { await T[i].fn(); } catch (e) { errs.push(e.message); }
  }

  hlFree(hlStage); hlStage = [];
  if (P.coverUrl) URL.revokeObjectURL(P.coverUrl);
  hlP = hlPNew(); hlDirty = true; hlSaving = false;
  btn.textContent = 'Save new changes';
  try { await hlRefresh(); } catch (e) {}
  hlPendUI();
  showToast(errs.length ? 'Saved, but ' + errs.length + ' change' + (errs.length === 1 ? '' : 's') + ' failed: ' + errs[0] : 'All changes saved');
}

async function hlDeleteAll() {
  if (!confirm('Delete this highlight and everything in it?')) return;
  try { await hlCall('delete', { highlight_id: hlCur.id }); hlDirty = true; hlDiscard(); showToast('Highlight deleted'); closeModal('modal-hl-edit'); }
  catch (e) { showToast(e.message); }
}

// ── Song clip picker: choose a song, pick a part, we cut and upload only that part ──
const CP = { file: null, url: null, dur: 0, cb: null, audio: null, timer: null };
const cpEl = id => document.getElementById(id);
const cpFmt = t => { t = Math.max(0, t); return Math.floor(t / 60) + ':' + String(Math.floor(t % 60)).padStart(2, '0'); };
const cpLen = () => Math.min(+cpEl('cp-len').value, CP.dur || 0);

function cpOpen(cb) {
  CP.cb = cb; cpReset();
  cpEl('modal-clip').classList.add('open'); document.body.style.overflow = 'hidden';
}
function cpReset() {
  cpStop();
  if (CP.url) URL.revokeObjectURL(CP.url);
  CP.file = null; CP.url = null; CP.dur = 0; CP.audio = null;
  cpEl('cp-editor').style.display = 'none'; cpEl('cp-file-title').textContent = 'Choose a song';
  cpEl('cp-title').value = ''; cpEl('cp-status').textContent = ''; cpEl('cp-status').className = 'hl-status';
  cpEl('cp-use').disabled = false; cpEl('cp-use').textContent = 'Use this clip';
}
function cpClose() {
  cpReset(); CP.cb = null;
  cpEl('modal-clip').classList.remove('open');
  if (!document.querySelector('.modal-overlay.open')) document.body.style.overflow = '';
}
function cpStatus(msg, err) { const s = cpEl('cp-status'); s.textContent = msg || ''; s.className = 'hl-status' + (err ? ' error' : ''); }

cpEl('cp-file').addEventListener('change', function () {
  const f = this.files && this.files[0]; this.value = '';
  if (!f) return;
  if (!f.type.startsWith('audio/') && !/\.(mp3|m4a|aac|wav|ogg|flac|opus)$/i.test(f.name)) { cpStatus('Please choose an audio file.', true); return; }
  if (f.size > 40 * 1048576) { cpStatus('That file is too large (40 MB max).', true); return; }
  cpStop(); if (CP.url) URL.revokeObjectURL(CP.url);
  CP.file = f; CP.url = URL.createObjectURL(f);
  const a = new Audio(); a.preload = 'metadata'; a.src = CP.url; CP.audio = a;
  cpStatus('Loading song…');
  a.addEventListener('loadedmetadata', function () {
    if (!isFinite(a.duration) || a.duration < 1) { cpStatus('Could not read that song.', true); return; }
    CP.dur = a.duration;
    cpEl('cp-file-title').textContent = hlShort(f.name, 34);
    cpEl('cp-title').value = f.name.replace(/\.[^.]+$/, '').slice(0, 60);
    cpEl('cp-start').value = 0;
    cpEl('cp-editor').style.display = 'block'; cpStatus('');
    cpUpdate();
  }, { once: true });
  a.addEventListener('error', () => cpStatus('This browser cannot play that file. Try MP3, M4A or WAV.', true), { once: true });
});

function cpUpdate() {
  if (!CP.dur) return;
  const len = cpLen(), sl = cpEl('cp-start');
  sl.max = Math.max(0, CP.dur - len);
  if (+sl.value > +sl.max) sl.value = sl.max;
  const st = +sl.value;
  cpEl('cp-start-lbl').textContent = cpFmt(st);
  cpEl('cp-end-lbl').textContent = cpFmt(st + len);
  if (CP.audio && !CP.audio.paused) cpStop();
}
function cpStop() {
  clearInterval(CP.timer);
  if (CP.audio) CP.audio.pause();
  const t = cpEl('cp-prev-txt'); if (t) t.textContent = 'Preview clip';
}
function cpPreview() {
  const a = CP.audio; if (!a || !CP.dur) return;
  if (!a.paused) { cpStop(); return; }
  const st = +cpEl('cp-start').value, len = cpLen();
  a.currentTime = st; a.play().catch(() => cpStatus('Could not play the preview.', true));
  cpEl('cp-prev-txt').textContent = 'Stop preview';
  clearInterval(CP.timer);
  CP.timer = setInterval(() => { if (a.currentTime >= st + len || a.ended) cpStop(); }, 100);
}

// Cuts the chosen part out of the song and turns it into a small WAV (32 kHz, 16-bit, with a short fade in/out)
async function cpBuildClip(file, start, len) {
  const AC = window.AudioContext || window.webkitAudioContext;
  const ctx = new AC();
  let buf;
  try { buf = await ctx.decodeAudioData(await file.arrayBuffer()); }
  finally { if (ctx.close) ctx.close(); }
  const sr = buf.sampleRate, ch = Math.min(2, buf.numberOfChannels);
  const from = Math.floor(start * sr), to = Math.min(buf.length, Math.floor((start + len) * sr)), frames = to - from;
  if (frames < sr) throw new Error('That clip is too short.');
  const RATE = 32000;
  const off = new OfflineAudioContext(ch, Math.max(1, Math.ceil(frames * RATE / sr)), RATE);
  const slice = off.createBuffer(ch, frames, sr);
  for (let c = 0; c < ch; c++) slice.copyToChannel(buf.getChannelData(c).subarray(from, to), c);
  const src = off.createBufferSource(); src.buffer = slice; src.connect(off.destination); src.start();
  return cpWav(await off.startRendering());
}
function cpWav(b) {
  const ch = b.numberOfChannels, n = b.length, sr = b.sampleRate, bytes = n * ch * 2;
  const ab = new ArrayBuffer(44 + bytes), v = new DataView(ab);
  const w = (o, t) => { for (let i = 0; i < t.length; i++) v.setUint8(o + i, t.charCodeAt(i)); };
  w(0, 'RIFF'); v.setUint32(4, 36 + bytes, true); w(8, 'WAVE'); w(12, 'fmt ');
  v.setUint32(16, 16, true); v.setUint16(20, 1, true); v.setUint16(22, ch, true);
  v.setUint32(24, sr, true); v.setUint32(28, sr * ch * 2, true); v.setUint16(32, ch * 2, true); v.setUint16(34, 16, true);
  w(36, 'data'); v.setUint32(40, bytes, true);
  const fade = Math.min(Math.floor(sr * 0.15), Math.floor(n / 2)), d = [];
  for (let c = 0; c < ch; c++) d.push(b.getChannelData(c));
  let o = 44;
  for (let i = 0; i < n; i++) {
    const g = i < fade ? i / fade : (i > n - fade ? (n - i) / fade : 1);
    for (let c = 0; c < ch; c++) { const x = Math.max(-1, Math.min(1, d[c][i] * g)); v.setInt16(o, x < 0 ? x * 0x8000 : x * 0x7fff, true); o += 2; }
  }
  return new Blob([ab], { type: 'audio/wav' });
}
async function cpUse(btn) {
  if (!CP.file || !CP.dur) return;
  const len = cpLen(), start = +cpEl('cp-start').value, title = cpEl('cp-title').value.trim();
  cpStop(); btn.disabled = true; btn.textContent = 'Preparing clip…'; cpStatus('');
  try {
    const blob = await cpBuildClip(CP.file, start, len);
    if (blob.size > 6.5 * 1048576) throw new Error('That clip is too big. Try a shorter length.');
    const cb = CP.cb;
    await cb({ blob: blob, title: title, secs: len });
    cpClose();
  } catch (e) {
    btn.disabled = false; btn.textContent = 'Use this clip';
    cpStatus(e && e.message ? e.message : 'Could not prepare that clip.', true);
  }
}

// ══ ACCOUNT: username limit, profile picture crop + upload, deactivate / delete ══
async function acctCall(action, fields, files) {
  const fd = new FormData(); fd.append('acct_action', action);
  Object.keys(fields || {}).forEach(k => fd.append(k, fields[k]));
  Object.keys(files || {}).forEach(k => fd.append(k, files[k], 'avatar.jpg'));
  let d;
  try { const r = await fetch(window.location.pathname, { method: 'POST', body: fd }); d = await r.json(); }
  catch (e) { throw new Error('Network error. Please try again.'); }
  if (!d.success) throw new Error(d.msg || 'Something went wrong.');
  return d;
}

// ── Username: once every 14 days ──
const UN = { locked: <?= json_encode($un_locked) ?>, days: <?= (int)$un_days_left ?>, cur: <?= json_encode($user['username'] ?? '') ?> };
function unRender() {
  const inp = $id('input-username'), btn = $id('save-username'), note = $id('un-note');
  if (!inp || !btn || !note) return;
  inp.readOnly = UN.locked; btn.disabled = UN.locked;
  if (UN.locked) {
    note.className = 'un-note locked';
    note.innerHTML = 'You changed your username recently. You can change it again in <b>' + UN.days + ' day' + (UN.days === 1 ? '' : 's') + '</b>.';
  } else {
    note.className = 'un-note';
    note.innerHTML = 'You can change your username <b>once every <?= (int)UN_COOLDOWN_DAYS ?> days</b>. This stops people from copying other accounts.';
  }
}
async function saveUsername(btn) {
  if (UN.locked) return;
  const input = $id('input-username'), val = input.value.trim(), fb = $id('feedback-username');
  fb.className = 'field-feedback error'; fb.textContent = '';
  if (val === UN.cur) { fb.textContent = 'That is already your username.'; return; }
  if (!/^[A-Za-z0-9_.]{3,30}$/.test(val)) { fb.textContent = 'Use 3 to 30 letters, numbers, underscores or dots.'; return; }
  if (!confirm('You can only change your username once every <?= (int)UN_COOLDOWN_DAYS ?> days. Change it to "' + val + '" now?')) return;
  const orig = btn.textContent;
  btn.disabled = true; btn.textContent = 'Saving…';
  try {
    const d = await acctCall('username', { username: val });
    UN.cur = d.username; UN.locked = true; UN.days = d.days || <?= (int)UN_COOLDOWN_DAYS ?>;
    input.value = d.username;
    $id('status-username').textContent = ''; $id('suggestions-username').innerHTML = ''; fb.textContent = '';
    const g = $id('gsUsername'); if (g) g.textContent = '@' + d.username;
    unRender();
    btn.textContent = 'Saved ✓'; setTimeout(() => { btn.textContent = orig; }, 1400);
    showToast('Username updated');
  } catch (e) {
    btn.disabled = false; btn.textContent = orig;
    fb.textContent = e.message; showToast(e.message);
  }
}
unRender();

// ── Profile picture: pick, crop (drag + zoom), upload ──
const AV = { img: null, url: null, z: 1, min: 1, ox: 0, oy: 0, V: 640, drag: null, busy: false };
function avPicked(input) {
  const f = input.files && input.files[0]; input.value = '';
  if (!f) return;
  if (!f.type.startsWith('image/')) { showToast('Please choose a picture file.'); return; }
  if (f.size > 15 * 1048576) { showToast('That picture is too large (15 MB max).'); return; }
  avOpen(f);
}
function avOpen(file) {
  const url = URL.createObjectURL(file), img = new Image();
  img.onload = function () {
    if (AV.url) URL.revokeObjectURL(AV.url);
    AV.url = url; AV.img = img; AV.z = 1;
    AV.min = Math.max(AV.V / img.naturalWidth, AV.V / img.naturalHeight);
    AV.ox = (AV.V - img.naturalWidth * AV.min) / 2; AV.oy = (AV.V - img.naturalHeight * AV.min) / 2;
    $id('av-zoom').value = 1;
    $id('av-status').textContent = ''; $id('av-status').className = 'hl-status';
    $id('av-save').disabled = false; $id('av-save').textContent = 'Use this picture';
    openModal('modal-avatar'); avDraw();
  };
  img.onerror = function () { URL.revokeObjectURL(url); showToast('That picture could not be opened. Try a JPG or PNG.'); };
  img.src = url;
}
function avClamp() {
  const s = AV.min * AV.z, w = AV.img.naturalWidth * s, h = AV.img.naturalHeight * s;
  AV.ox = Math.min(0, Math.max(AV.V - w, AV.ox));
  AV.oy = Math.min(0, Math.max(AV.V - h, AV.oy));
}
function avDraw() {
  if (!AV.img) return;
  const c = $id('av-canvas'), x = c.getContext('2d'), V = AV.V, s = AV.min * AV.z;
  x.fillStyle = '#000'; x.fillRect(0, 0, V, V);
  x.drawImage(AV.img, AV.ox, AV.oy, AV.img.naturalWidth * s, AV.img.naturalHeight * s);
  const r = V / 2 - 6;
  x.save();
  x.fillStyle = 'rgba(0,0,0,.55)';
  x.beginPath(); x.rect(0, 0, V, V); x.moveTo(V / 2 + r, V / 2); x.arc(V / 2, V / 2, r, 0, Math.PI * 2); x.fill('evenodd');
  x.restore();
  x.strokeStyle = 'rgba(255,255,255,.85)'; x.lineWidth = 3;
  x.beginPath(); x.arc(V / 2, V / 2, r, 0, Math.PI * 2); x.stroke();
}
function avZoom(z) {
  if (!AV.img) return;
  const old = AV.min * AV.z, cx = (AV.V / 2 - AV.ox) / old, cy = (AV.V / 2 - AV.oy) / old;
  AV.z = z;
  const s = AV.min * z;
  AV.ox = AV.V / 2 - cx * s; AV.oy = AV.V / 2 - cy * s;
  avClamp(); avDraw();
}
(function initAvatarCanvas() {
  const c = $id('av-canvas'); if (!c) return;
  c.addEventListener('pointerdown', e => {
    if (!AV.img) return;
    AV.drag = { x: e.clientX, y: e.clientY, ox: AV.ox, oy: AV.oy };
    try { c.setPointerCapture(e.pointerId); } catch (err) {}
    c.classList.add('drag');
  });
  c.addEventListener('pointermove', e => {
    if (!AV.drag || !AV.img) return;
    const k = AV.V / c.getBoundingClientRect().width;
    AV.ox = AV.drag.ox + (e.clientX - AV.drag.x) * k;
    AV.oy = AV.drag.oy + (e.clientY - AV.drag.y) * k;
    avClamp(); avDraw();
  });
  const end = () => { AV.drag = null; c.classList.remove('drag'); };
  c.addEventListener('pointerup', end); c.addEventListener('pointercancel', end);
  c.addEventListener('wheel', e => {
    if (!AV.img) return;
    e.preventDefault();
    const z = Math.min(4, Math.max(1, AV.z * (e.deltaY < 0 ? 1.06 : 0.94)));
    $id('av-zoom').value = z; avZoom(z);
  }, { passive: false });
})();
function avClose() {
  if (AV.url) { URL.revokeObjectURL(AV.url); AV.url = null; }
  AV.img = null; AV.drag = null;
  $id('modal-avatar').classList.remove('open');
  if (!document.querySelector('.modal-overlay.open')) document.body.style.overflow = '';
}
function avApply(path) {
  const u = path + '?v=' + Date.now();
  const cur = $id('av-cur'); if (cur) cur.src = u;
  document.querySelectorAll('.vf-avatar img').forEach(i => { i.src = u; });
  const rm = $id('av-rm'); if (rm) rm.style.display = '';
}
async function avSave(btn) {
  if (!AV.img || AV.busy) return;
  AV.busy = true; btn.disabled = true; btn.textContent = 'Uploading…';
  const st = $id('av-status'); st.textContent = ''; st.className = 'hl-status';
  try {
    const out = document.createElement('canvas'); out.width = out.height = 512;
    const x = out.getContext('2d'), k = 512 / AV.V, s = AV.min * AV.z;
    x.fillStyle = '#fff'; x.fillRect(0, 0, 512, 512);
    x.drawImage(AV.img, AV.ox * k, AV.oy * k, AV.img.naturalWidth * s * k, AV.img.naturalHeight * s * k);
    const blob = await new Promise((res, rej) => out.toBlob(b => b ? res(b) : rej(new Error('Could not prepare the picture.')), 'image/jpeg', 0.9));
    const d = await acctCall('avatar', {}, { avatar: blob });
    avApply(d.path); avClose();
    showToast('Profile picture updated');
  } catch (e) {
    st.textContent = e.message || 'Upload failed.'; st.className = 'hl-status error';
    btn.disabled = false; btn.textContent = 'Use this picture';
  }
  AV.busy = false;
}
async function avRemove() {
  if (!confirm('Remove your profile picture?')) return;
  try {
    await acctCall('avatar_rm');
    const cur = $id('av-cur'); if (cur) cur.src = 'mee.jpg';
    document.querySelectorAll('.vf-avatar img').forEach(i => { i.src = 'mee.jpg'; });
    const rm = $id('av-rm'); if (rm) rm.style.display = 'none';
    showToast('Profile picture removed');
  } catch (e) { showToast(e.message); }
}

// ── Deactivate / delete ──
const DG = { mode: 'deactivate', busy: false };
function dgPick(m) {
  DG.mode = m;
  document.querySelectorAll('#dg-levels .pv-card').forEach(c => {
    const on = c.dataset.dg === m;
    c.classList.toggle('on', on); c.setAttribute('aria-checked', on ? 'true' : 'false');
  });
  $id('dg-confirm-wrap').style.display = m === 'delete' ? '' : 'none';
  $id('dg-go').textContent = m === 'delete' ? 'Delete my account' : 'Deactivate my account';
  $id('dg-feedback').textContent = '';
}
async function dgSubmit(btn) {
  if (DG.busy) return;
  const pw = $id('dg-pw').value, f = $id('dg-feedback');
  f.className = 'field-feedback error'; f.textContent = '';
  if (!pw) { f.textContent = 'Enter your password to continue.'; return; }
  let conf = '';
  if (DG.mode === 'delete') {
    conf = $id('dg-confirm').value.trim();
    if (conf.toUpperCase() !== 'DELETE') { f.textContent = 'Type DELETE to confirm.'; return; }
  }
  const msg = DG.mode === 'delete'
    ? 'Delete your account? It is hidden right away and removed for good after <?= (int)DEL_GRACE_DAYS ?> days. Logging in again before then cancels it.'
    : 'Deactivate your account? Your profile will be hidden until you log in again.';
  if (!confirm(msg)) return;
  DG.busy = true; btn.disabled = true;
  const orig = btn.textContent; btn.textContent = 'Please wait…';
  try {
    const d = await acctCall(DG.mode, { password: pw, confirm: conf });
    showToast(DG.mode === 'delete' ? 'Account scheduled for deletion' : 'Account deactivated');
    setTimeout(() => { window.location.href = d.redirect || 'login.php'; }, 900);
  } catch (e) {
    DG.busy = false; btn.disabled = false; btn.textContent = orig;
    f.textContent = e.message;
  }
}
async function cancelDelete(btn) {
  btn.disabled = true;
  try {
    await acctCall('cancel_delete');
    const p = $id('dg-pending');
    if (p && p.previousElementSibling && p.previousElementSibling.classList.contains('pv-h')) p.previousElementSibling.remove();
    ['dg-banner', 'dg-pending'].forEach(id => { const e = $id(id); if (e) e.remove(); });
    showToast('Deletion cancelled. Your account is safe.');
  } catch (e) { btn.disabled = false; showToast(e.message); }
}

// ══ NOTIFICATIONS ON / OFF ══
const NT_TXT = {
  on:  'Dezzme sends you alerts about likes, follows, messages and more. Turn the switch off to stop all of them.',
  off: 'All notifications are paused. The bell is hidden and you get no alerts until you turn this back on.'
};
function ntRender() {
  const on = +PRF.notifications_enabled === 1;
  $id('nt-state').textContent = on ? 'Notifications are on' : 'Notifications are off';
  $id('nt-ic').className = on ? 'ri-notification-3-line' : 'ri-notification-off-line';
  $id('nt-sub').textContent = on ? NT_TXT.on : NT_TXT.off;
  const v = $id('menu-notif-state'); if (v) v.textContent = on ? '' : 'Off';
}
async function ntChange(cb) {
  await prField('notifications_enabled', cb.checked ? 1 : 0);   // reverts the switch by itself if saving fails
  ntRender();
}
ntRender();

// ══ TIME MANAGEMENT: bar chart ══
const TM = <?= json_encode($tm_days) ?>;
const TMS = { range: 7 };
function tmFmt(s) {
  s = Math.round(s);
  if (s <= 0) return '0m';
  let h = Math.floor(s / 3600), m = Math.round((s % 3600) / 60);
  if (m === 60) { h++; m = 0; }
  if (h > 0) return h + 'h' + (m ? ' ' + m + 'm' : '');
  return m > 0 ? m + 'm' : '<1m';
}
function tmRead(x) { const r = $id('tm-read'); r.innerHTML = '<b>' + esc(x.f) + '</b> · ' + tmFmt(x.s); }
function tmRender() {
  const data = TM.slice(-TMS.range), n = data.length;
  const total = data.reduce((a, x) => a + x.s, 0), avg = total / n;
  const max = Math.max.apply(null, data.map(x => x.s));
  const top = Math.max(600, Math.ceil(max / 900) * 900);   // round the top of the chart up to 15 minutes
  $id('tm-avg').textContent = tmFmt(avg);
  $id('tm-avg-sub').textContent = total > 0 ? 'per day, over the last ' + n + ' days' : 'No time recorded yet. It starts counting while you use Dezzme.';
  $id('tm-today').textContent = tmFmt(TM[TM.length - 1].s);
  $id('tm-week').textContent = tmFmt(TM.slice(-7).reduce((a, x) => a + x.s, 0));
  let best = data[0]; data.forEach(x => { if (x.s > best.s) best = x; });
  $id('tm-busy').textContent = best.s > 0 ? best.l : '-';
  $id('tm-top').textContent = tmFmt(top); $id('tm-mid').textContent = tmFmt(top / 2);

  const bars = $id('tm-bars'), axis = $id('tm-axis');
  bars.textContent = ''; axis.textContent = '';
  data.forEach((x, i) => {
    const col = document.createElement('button');
    col.type = 'button'; col.className = 'tm-col' + (i === n - 1 ? ' today' : ''); col.title = x.f + ': ' + tmFmt(x.s);
    const bar = document.createElement('span'); bar.className = 'tm-bar';
    let h = x.s / top * 100; if (x.s > 0 && h < 2) h = 2;
    bar.style.height = h + '%';
    col.appendChild(bar);
    col.addEventListener('click', () => {
      bars.querySelectorAll('.tm-col').forEach(c => c.classList.remove('sel'));
      col.classList.add('sel'); tmRead(x);
    });
    bars.appendChild(col);
    const lab = document.createElement('span');
    lab.textContent = n <= 7 ? x.l : (((n - 1 - i) % 5 === 0) ? x.n : '');
    axis.appendChild(lab);
  });
  const line = $id('tm-avgline');
  line.style.display = avg > 0 ? '' : 'none';
  line.style.bottom = (avg / top * 100) + '%';
  $id('tm-avglab').textContent = 'avg ' + tmFmt(avg);
  tmRead(TM[TM.length - 1]);
}
document.querySelectorAll('#tm-seg button').forEach(b => b.addEventListener('click', () => {
  TMS.range = +b.dataset.r;
  document.querySelectorAll('#tm-seg button').forEach(x => { const on = x === b; x.classList.toggle('on', on); x.setAttribute('aria-checked', on ? 'true' : 'false'); });
  tmRender();
}));
tmRender();

// ══ BIRTHDAYS: search ══
(function () {
  const i = $id('bd-search'); if (!i) return;
  i.addEventListener('input', () => {
    const q = i.value.trim().toLowerCase(); let any = false;
    document.querySelectorAll('#bd-list .bd-group').forEach(g => {
      let vis = false;
      g.querySelectorAll('.bd-row').forEach(r => { const m = !q || r.dataset.name.indexOf(q) !== -1; r.style.display = m ? '' : 'none'; if (m) vis = true; });
      g.style.display = vis ? '' : 'none'; if (vis) any = true;
    });
    $id('bd-none').classList.toggle('show', !any);
  });
})();

// ══ PRIVACY CHECK-UP ══
let CK_LAST = <?= json_encode($ck_last ? date('j M Y', strtotime($ck_last)) : '') ?>;
const CK_WORDS = ['Open', 'Balanced', 'Protected', 'Locked down'];
function ckGo(id) { closeModal('modal-checkup'); setTimeout(() => openModal(id), 60); }
function ckRow(icon, title, val, tone, target) {
  const b = document.createElement('button'); b.type = 'button'; b.className = 'ck-row';
  const ic = document.createElement('span'); ic.className = 'pv-ic sm'; const i = document.createElement('i'); i.className = icon; ic.appendChild(i);
  const t = document.createElement('div'); t.className = 'ck-t';
  const s = document.createElement('strong'); s.textContent = title;
  const v = document.createElement('span'); v.className = 'ck-v ' + tone; v.textContent = val;
  t.appendChild(s); t.appendChild(v);
  const ch = document.createElement('i'); ch.className = 'ri-arrow-right-s-line menu-chevron';
  b.appendChild(ic); b.appendChild(t); b.appendChild(ch);
  b.addEventListener('click', () => ckGo(target));
  return b;
}
function ckRender() {
  const L = PRIV_LV[PR.level], n = L.n;
  $id('ck-hero-n').textContent = CK_WORDS[n - 1] + ' · ' + L.name;
  prMeter('ck-meter', n);
  $id('ck-hero-s').textContent = L.sum;
  const wh = { everyone: ['Everyone', 'open'], trackers: ['Only your trackers', 'limited'], me: ['Only you', 'closed'] };
  const tg = { everyone: ['Everyone', 'open'], tracked: ['Only people you track', 'limited'], none: ['No one', 'closed'] };
  const list = $id('ck-list'); list.textContent = '';
  const prof = { public: ['Everyone can see your profile and memes', 'open'], limited: ['Everyone can look, only your trackers can interact', 'limited'],
                 private: ['Your name, photo and bio are public. Memes need your approval', 'limited'], full: ['Only your name and photo are visible', 'closed'] }[PR.level];
  list.appendChild(ckRow('ri-eye-line', 'Who can see your profile', prof[0], prof[1], 'modal-privacy'));
  const msg = +PRF.allow_messages !== 1 ? ['No one', 'closed'] : (PR.level === 'public' ? ['Anyone on Dezzme', 'open'] : ['Only your trackers', 'limited']);
  list.appendChild(ckRow('ri-chat-3-line', 'Who can message you', msg[0], msg[1], 'modal-privacy'));
  const apNeeded = PR.level === 'private' || PR.level === 'full';
  const waiting = document.querySelectorAll('#trackRequestsList .track-req-row').length;
  list.appendChild(ckRow('ri-user-add-line', 'Who can track you', apNeeded ? 'Only people you approve' + (waiting ? ' · ' + waiting + ' waiting' : '') : 'Anyone, without asking', apNeeded ? 'closed' : 'open', apNeeded ? 'modal-trackreqs' : 'modal-privacy'));
  const tag = tg[PRF.tag_who] || tg.everyone;
  list.appendChild(ckRow('ri-price-tag-3-line', 'Who can tag you', tag[0] + (+PRF.tag_approve ? ' · you approve each tag' : ''), tag[1], 'modal-tags'));
  const men = tg[PRF.mention_who] || tg.everyone;
  list.appendChild(ckRow('ri-at-line', 'Who can @mention you', men[0], men[1], 'modal-tags'));
  [['show_trackers', 'Who sees your Trackers list'], ['show_tracking', 'Who sees your Tracking list'], ['show_pods', 'Who sees your Pods list']].forEach(a => {
    const w = wh[PRF[a[0]]] || wh.everyone;
    list.appendChild(ckRow('ri-team-line', a[1], w[0], w[1], 'modal-lists'));
  });
  const OV = { '2': ['Everyone can see when you are online', 'open'], '1': ['Only people who track you', 'limited'], '0': ['Hidden. You do not see theirs either', 'closed'] };
  const ov = OV[String(PRF.online_visibility)] || OV['2'], lv = OV[String(PRF.last_seen_visibility)] || OV['2'];
  list.appendChild(ckRow('ri-radio-button-line', 'Online status', ov[0], ov[1], 'modal-privacy'));
  list.appendChild(ckRow('ri-time-line', 'Last seen', lv[0], lv[1], 'modal-privacy'));

  const hid = $id('ck-hidden'); hid.textContent = '';
  const cnt = k => { const e = $id('modcount-' + k); return e ? (parseInt(e.textContent) || 0) : 0; };
  hid.appendChild(ckRow('ri-forbid-2-line', 'Blocked accounts', cnt('blocked') + ' blocked', 'info', 'modal-blocked'));
  hid.appendChild(ckRow('ri-volume-mute-line', 'Muted accounts', cnt('muted') + ' muted', 'info', 'modal-blocked'));
  hid.appendChild(ckRow('ri-inbox-line', 'Restricted accounts', cnt('restricted') + ' restricted', 'info', 'modal-blocked'));
  hid.appendChild(ckRow('ri-notification-off-line', 'Muted Pods', document.querySelectorAll('#mp-list .mod-user-row').length + ' muted', 'info', 'modal-mutedpods'));

  $id('ck-last').textContent = CK_LAST ? 'Last checked on ' + CK_LAST : "You haven't done a check-up yet.";
  const b = $id('ck-ok'); b.disabled = false; b.textContent = 'Looks good';
}
async function ckOk(btn) {
  btn.disabled = true; btn.textContent = 'Saving…';
  try {
    const d = await acctCall('checkup');
    CK_LAST = d.when; $id('ck-last').textContent = 'Last checked on ' + CK_LAST;
    btn.textContent = 'Checked ✓'; showToast('Privacy check-up saved');
  } catch (e) { btn.disabled = false; btn.textContent = 'Looks good'; showToast(e.message); }
}

// ══ TIME ZONE ══
const TZ = { cur: <?= json_encode($tz_cur) ?>, sel: <?= json_encode($tz_cur) ?>, timer: null };
function tzRows() { return document.querySelectorAll('#tz-list .tz-row'); }
function tzClock() {
  const tz = TZ.sel;
  if (!tz) { $id('tz-time').textContent = '--:--'; $id('tz-date').textContent = 'Server'; $id('tz-off').textContent = '-'; return; }
  try {
    const now = new Date();
    $id('tz-time').textContent = new Intl.DateTimeFormat([], { timeZone: tz, hour: 'numeric', minute: '2-digit' }).format(now);
    $id('tz-date').textContent = new Intl.DateTimeFormat([], { timeZone: tz, weekday: 'short', day: 'numeric', month: 'short' }).format(now);
    const row = document.querySelector('#tz-list .tz-row[data-tz="' + tz + '"]');
    $id('tz-off').textContent = row ? 'UTC' + row.dataset.off : '-';
  } catch (e) {}
}
function tzMark() {
  tzRows().forEach(r => r.classList.toggle('on', r.dataset.tz === TZ.sel));
  $id('tz-hero-n').textContent = TZ.sel ? TZ.sel.replace(/_/g, ' ') : 'Dezzme server time';
  $id('tz-save').disabled = (TZ.sel === TZ.cur);
  tzClock();
}
function tzSelect(tz) { TZ.sel = tz; tzMark(); }
function tzOpen() {
  TZ.sel = TZ.cur; tzMark();
  clearInterval(TZ.timer);
  TZ.timer = setInterval(() => { if ($id('modal-timezone').classList.contains('open')) tzClock(); else clearInterval(TZ.timer); }, 1000);
  const on = document.querySelector('#tz-list .tz-row.on');
  if (on) setTimeout(() => on.scrollIntoView({ block: 'center' }), 80);
}
function tzDetect() {
  let tz = '';
  try { tz = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) {}
  const row = tz ? document.querySelector('#tz-list .tz-row[data-tz="' + tz + '"]') : null;
  if (!row) { showToast("We couldn't match your time zone. Search for it below."); return; }
  tzSelect(tz); row.scrollIntoView({ block: 'center' }); showToast('Detected ' + tz.replace(/_/g, ' '));
}
(function () {
  tzRows().forEach(r => r.addEventListener('click', () => tzSelect(r.dataset.tz)));
  const q = $id('tz-search');
  q.addEventListener('input', () => {
    const v = q.value.trim().toLowerCase(); let any = false;
    tzRows().forEach(r => { const m = !v || r.dataset.s.indexOf(v) !== -1; r.style.display = m ? '' : 'none'; if (m) any = true; });
    $id('tz-none').classList.toggle('show', !any);
  });
})();
async function tzSave(btn) {
  btn.disabled = true; btn.textContent = 'Saving…';
  try {
    await acctCall('timezone', { tz: TZ.sel });
    TZ.cur = TZ.sel;
    const m = $id('menu-tz'); if (m) m.textContent = TZ.cur ? TZ.cur.split('/').pop().replace(/_/g, ' ') : '';
    showToast('Time zone saved');
    setTimeout(() => { window.location.href = window.location.pathname + '?open=timezone'; }, 700);   // reload so Birthdays and Time management use it
  } catch (e) { btn.disabled = false; btn.textContent = 'Save time zone'; showToast(e.message); }
}

// ══ WORD FILTERS ══
const WF = { lists: <?= json_encode($wf_lists, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>, max: <?= (int)DZ_WF_MAX_WORDS ?>, kind: 'post', busy: false };
function wfRender() {
  ['post', 'ad', 'comment'].forEach(k => {
    const box = $id('wf-l-' + k), list = WF.lists[k] || [];
    box.innerHTML = '';
    if (!list.length) { const e = document.createElement('div'); e.className = 'wf-empty'; e.textContent = 'No ' + k + ' words yet.'; box.appendChild(e); }
    list.forEach(w => {
      const c = document.createElement('span'); c.className = 'wf-chip';
      const t = document.createElement('span'); t.textContent = w;
      const b = document.createElement('button'); b.type = 'button'; b.setAttribute('aria-label', 'Remove ' + w); b.innerHTML = '&times;';
      b.onclick = () => wfRemove(k, w);
      c.appendChild(t); c.appendChild(b); box.appendChild(c);
    });
    $id('wf-n-' + k).textContent = list.length;
    $id('wf-c-' + k).textContent = list.length + ' of ' + WF.max + ' words';
    $id('wf-x-' + k).hidden = list.length === 0;
  });
  const total = (WF.lists.post || []).length + (WF.lists.ad || []).length + (WF.lists.comment || []).length;
  const m = $id('wf-menu-count'); if (m) m.textContent = total > 0 ? total : '';
}
function wfTab(k) {
  WF.kind = k;
  document.querySelectorAll('.wf-tab').forEach(t => t.classList.toggle('on', t.dataset.k === k));
  document.querySelectorAll('.wf-panel').forEach(p => p.classList.toggle('on', p.dataset.k === k));
}
async function wfCall(action, fields) {
  if (WF.busy) return; WF.busy = true;
  try { const d = await acctCall(action, fields); WF.lists = d.lists; wfRender(); return d; }
  catch (e) { showToast(e.message); }
  finally { WF.busy = false; }
}
async function wfAdd(k) {
  const inp = $id('wf-in-' + k), v = inp.value.trim();
  if (!v) { inp.focus(); return; }
  const before = (WF.lists[k] || []).length;
  const d = await wfCall('wf_add', { kind: k, words: v });
  if (d) { inp.value = ''; const n = (WF.lists[k] || []).length - before; showToast(n > 0 ? (n === 1 ? 'Word added' : n + ' words added') : 'Already in your list'); }
}
function wfRemove(k, w) { wfCall('wf_remove', { kind: k, word: w }); }
async function wfClear(k) { if (confirm('Remove all ' + k + ' words?')) { const d = await wfCall('wf_clear', { kind: k }); if (d) showToast('List cleared'); } }
wfRender();

// ══ MUTED PODS ══
/* infinite scroll: show 20 Pods at a time, the next 20 when you reach the end */
const MP_PAGE = 20;
let mpShown = 0, mpObs = null;
function mpReveal() {
  const rows = document.querySelectorAll('#mp-list .mod-user-row');
  const end = Math.min(rows.length, mpShown + MP_PAGE);
  rows.forEach((r, i) => { r.style.display = i < end ? '' : 'none'; });
  mpShown = end;
}
function mpInitScroll() {
  mpShown = 0; mpReveal();
  const s = $id('mp-more'); if (!s || mpObs) return;
  const root = s.closest('.modal-body');
  mpObs = new IntersectionObserver(es => {
    if (es.some(e => e.isIntersecting) && mpShown < document.querySelectorAll('#mp-list .mod-user-row').length) mpReveal();
  }, { root: root || null, rootMargin: '200px' });
  mpObs.observe(s);
}
function mpCount() {
  const n = document.querySelectorAll('#mp-list .mod-user-row').length;
  $id('mp-count').textContent = n;
  $id('mp-hero-n').textContent = n > 0 ? n + ' Pod' + (n === 1 ? '' : 's') + ' muted' : 'No muted Pods';
  if (n === 0 && !$id('mp-empty')) $id('mp-list').innerHTML = '<div class="mod-empty" id="mp-empty"><div class="empty-title">No muted Pods</div><p class="empty-desc">When you turn notifications off for a Pod, it appears here so you can turn them back on.</p></div>';
}
async function mpUnmute(id, btn) {
  btn.disabled = true;
  try {
    await acctCall('pod_unmute', { group_id: id });
    const row = btn.closest('.mod-user-row'); row.classList.add('removing');
    setTimeout(() => { row.remove(); mpShown = Math.max(0, mpShown - 1); mpReveal(); mpCount(); }, 260);
    showToast('Pod unmuted');
  } catch (e) { btn.disabled = false; showToast(e.message); }
}

// ══ ARCHIVE ══
const AR = { tab: 'live', offset: 0, busy: false, loaded: false, live: <?= (int)$ar_live ?>, arch: <?= (int)$ar_arch ?> };
function arCounts() {
  $id('ar-n-live').textContent = AR.live; $id('ar-n-arch').textContent = AR.arch;
  $id('ar-hero-n').textContent = AR.arch + ' archived · ' + AR.live + ' on profile';
  const m = $id('menu-ar'); if (m) m.textContent = AR.arch > 0 ? AR.arch : '';
}
function arOpen() { arCounts(); if (!AR.loaded) arLoad(true); }
function arTile(it) {
  const wrap = document.createElement('div'); wrap.className = 'ar-item';
  const sq = document.createElement('div'); sq.className = 'hl-sq';
  if (it.media && it.video) {
    const v = document.createElement('video'); v.src = it.media + '#t=0.1'; v.muted = true; v.playsInline = true; v.preload = 'metadata'; sq.appendChild(v);
    const b = document.createElement('span'); b.className = 'hl-vid'; b.innerHTML = '<i class="ri-play-fill"></i>'; sq.appendChild(b);
  } else if (it.media) {
    const im = document.createElement('img'); im.alt = ''; im.loading = 'lazy';
    im.onerror = function () {   // the file could not be opened: fall back to the Text background
      this.onerror = null; this.src = HL_TEXT_BG;
      const c = document.createElement('span'); c.className = 'hl-tilecap'; c.textContent = it.cap; sq.appendChild(c);
    };
    im.src = it.media; sq.appendChild(im);
  } else {   // text post: shown on the Text background, with its words on top
    const im = document.createElement('img'); im.src = HL_TEXT_BG; im.alt = ''; sq.appendChild(im);
    const c = document.createElement('span'); c.className = 'hl-tilecap'; c.textContent = it.cap; sq.appendChild(c);
  }
  const name = document.createElement('div'); name.className = 'hl-name'; name.textContent = it.cap || 'Post';
  const date = document.createElement('div'); date.className = 'hl-count'; date.textContent = it.date;
  const btn = document.createElement('button'); btn.type = 'button';
  btn.className = 'hle-btn' + (AR.tab === 'live' ? '' : ' accent'); btn.textContent = AR.tab === 'live' ? 'Archive' : 'Restore';
  btn.addEventListener('click', async () => {
    btn.disabled = true;
    try {
      await acctCall(AR.tab === 'live' ? 'meme_archive' : 'meme_restore', { meme_id: it.id });
      if (AR.tab === 'live') { AR.live--; AR.arch++; } else { AR.arch--; AR.live++; }
      arCounts(); wrap.classList.add('removing');
      setTimeout(() => { wrap.remove(); arEmpty(); }, 250);
      showToast(AR.tab === 'live' ? 'Post archived' : 'Post restored');
    } catch (e) { btn.disabled = false; showToast(e.message); }
  });
  wrap.appendChild(sq); wrap.appendChild(name); wrap.appendChild(date); wrap.appendChild(btn);
  return wrap;
}
function arEmpty() {
  const none = $id('ar-grid').children.length === 0;
  $id('ar-empty').style.display = none ? '' : 'none';
  $id('ar-empty-t').textContent = AR.tab === 'live' ? 'No posts on your profile' : 'Nothing archived';
  $id('ar-empty-d').textContent = AR.tab === 'live' ? 'Post something and it will show up here.' : 'Archive a post and it will wait here until you restore it.';
}
async function arLoad(reset) {
  if (AR.busy) return; AR.busy = true;
  if (reset) { AR.offset = 0; $id('ar-grid').textContent = ''; $id('ar-more').style.display = 'none'; }
  try {
    const d = await acctCall('meme_list', { tab: AR.tab, offset: AR.offset });
    d.items.forEach(it => $id('ar-grid').appendChild(arTile(it)));
    AR.offset += d.items.length; AR.loaded = true;
    $id('ar-more').style.display = d.more ? 'flex' : 'none';
  } catch (e) { showToast(e.message); }
  AR.busy = false; arEmpty();
}
document.querySelectorAll('#ar-seg button').forEach(b => b.addEventListener('click', () => {
  AR.tab = b.dataset.t;
  document.querySelectorAll('#ar-seg button').forEach(x => { const on = x === b; x.classList.toggle('on', on); x.setAttribute('aria-checked', on ? 'true' : 'false'); });
  arLoad(true);
}));
arCounts();


// ── Accessibility + small behaviours ───────────────────────
(function initEnhancements() {
  // Menu rows that open modals behave like buttons for keyboard and screen-reader users
  document.querySelectorAll('.menu-row[onclick]').forEach(function (row) {
    row.setAttribute('role', 'button');
    row.tabIndex = 0;
    row.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); row.click(); }
    });
  });
  // Modals announce themselves as dialogs
  document.querySelectorAll('.modal-overlay').forEach(function (m) {
    m.setAttribute('role', 'dialog');
    m.setAttribute('aria-modal', 'true');
    const t = m.querySelector('.modal-head-title');
    if (t) { t.id = t.id || (m.id + '-title'); m.setAttribute('aria-labelledby', t.id); }
  });

  // Live character counter for the bio
  const bio = document.querySelector('textarea[name="bio"]'), bioCount = document.getElementById('bio-count');
  if (bio && bioCount) {
    const upd = () => { bioCount.textContent = bio.value.length + '/600'; };
    bio.addEventListener('input', upd); upd();
  }

  // Password strength meter (length, mixed case, digits, symbols)
  const pw = document.getElementById('pw-new'), bar = document.getElementById('pw-meter-bar'), lab = document.getElementById('pw-meter-label');
  if (pw && bar && lab) {
    pw.addEventListener('input', function () {
      const v = pw.value;
      if (!v) { bar.style.width = '0'; lab.textContent = ''; return; }
      let score = 0;
      if (v.length >= 8) score++;
      if (v.length >= 12) score++;
      if (/[a-z]/.test(v) && /[A-Z]/.test(v)) score++;
      if (/\d/.test(v)) score++;
      if (/[^A-Za-z0-9]/.test(v)) score++;
      const lv = score <= 1 ? ['Weak', '20%', '#fb923c'] : score === 2 ? ['Fair', '45%', '#fbbf24'] : score === 3 ? ['Good', '70%', '#a3e635'] : ['Strong', '100%', '#4ade80'];
      bar.style.width = lv[1]; bar.style.background = lv[2];
      lab.textContent = 'Password strength: ' + lv[0];
    });
  }
})();

// ── Back/forward cache: never show a stale page ──
window.addEventListener('pageshow', function (e) { if (e.persisted) location.reload(); });
</script>
<script nonce="<?php echo $nonce; ?>" src="time_track.js" data-csrf="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>"></script>
</body>
</html>