<?php
session_start();
ob_start();
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$current_user = (int)$_SESSION['user_id'];

/* view counts for posts opened in the Zeems modal on this page (same rules as the feed: dz_views.php) */
require_once __DIR__ . '/dz_views.php';
require_once __DIR__ . '/dz_privacy.php';
require_once __DIR__ . '/dz_collab.php';

/* social proof for the Zeems strip — same rule as the home feed: reposts first (newest first), then likes, from people I track,
   and MY OWN repost takes the first slot (so it is still there after a refresh). Top 4. */
function ms_social_proof($conn, int $mid, int $me): array {
$out = [];
$q = $conn->query("
    SELECT u.id, u.username, u.profile_pic, 'like' AS action_type, NULL AS acted_at
    FROM likes l JOIN users u ON u.id = l.user_id JOIN followers f ON f.following_id = u.id
    WHERE l.meme_id = $mid AND f.follower_id = $me AND u.id != $me AND u.deactivated_at IS NULL
    UNION ALL
    SELECT u.id, u.username, u.profile_pic, 'repost' AS action_type, r.created_at AS acted_at
    FROM reposts r JOIN users u ON u.id = r.user_id JOIN followers f ON f.following_id = u.id
    WHERE r.meme_id = $mid AND f.follower_id = $me AND u.id != $me AND u.deactivated_at IS NULL
    UNION ALL
    SELECT u.id, u.username, u.profile_pic, 'repost' AS action_type, r.created_at AS acted_at
    FROM reposts r JOIN users u ON u.id = r.user_id
    WHERE r.meme_id = $mid AND r.user_id = $me
");
if ($q) while ($x = $q->fetch_assoc()) $out[] = $x;
usort($out, function ($a, $b) use ($me) {
    if ((int)$a['id'] === $me) return -1; if ((int)$b['id'] === $me) return 1;
    $ar = $a['action_type'] === 'repost'; $br = $b['action_type'] === 'repost';
    if ($ar !== $br) return $ar ? -1 : 1;
    return strcmp((string)($b['acted_at'] ?? ''), (string)($a['acted_at'] ?? ''));
});
foreach ($out as &$o) unset($o['acted_at']);
return array_slice($out, 0, 4);
}

dz_collab_handle_request($conn, (int)$current_user);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string)($_POST['action'] ?? ''), ['impressions', 'view_stats', 'toggle_download'], true)) {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    header('Content-Type: application/json');
    if ($_POST['action'] === 'impressions') {
        $__v = []; $__d = [];
        foreach (explode(',', (string)($_POST['ids'] ?? '')) as $x) { $x = (int)$x; if ($x > 0) $__v[$x] = $x; }
        foreach (explode(',', (string)($_POST['dw'] ?? '')) as $pair) { $pp = explode(':', $pair); if (count($pp) === 2 && (int)$pp[0] > 0) $__d[(int)$pp[0]] = (int)$pp[1]; }
        if ($__v) dz_record_views($conn, $current_user, array_values($__v), 'history' === basename($_SERVER['SCRIPT_NAME'], '.php') || strpos($_SERVER['SCRIPT_NAME'], 'history') !== false ? 'history' : 'zeems');
        if ($__d) dz_view_add_watch($conn, $current_user, $__d);
        echo json_encode(['status' => 'ok', 'views' => dz_view_counts($conn, array_values($__v))]);
    } elseif ($_POST['action'] === 'toggle_download') {
        $__v = dz_toggle_download($conn, (int)$current_user, (int)($_POST['meme_id'] ?? 0));
        echo json_encode($__v === null ? ['status' => 'error', 'message' => 'only the owner can change this'] : ['status' => 'ok', 'allow_download' => $__v]);
    } else {
        $mid = (int)($_POST['meme_id'] ?? 0);
        $st = $conn->prepare("SELECT uploaded_by FROM memes WHERE id = ?");
        $st->bind_param("i", $mid); $st->execute();
        $rw = $st->get_result()->fetch_assoc(); $st->close();
        if ($rw && (int)$rw['uploaded_by'] === $current_user) echo json_encode(['status' => 'ok', 'stats' => dz_view_stats($conn, $mid)]);
        else echo json_encode(['status' => 'error', 'message' => 'only the owner can see this']);
    }
    exit;
}
$chat_user    = isset($_GET['user']) ? intval($_GET['user']) : null;
if ($chat_user !== null && ($chat_user <= 0 || $chat_user === $current_user)) $chat_user = null;
$chat_group   = isset($_GET['group']) ? intval($_GET['group']) : null;   // a group chat is open (instead of a one-to-one chat)
if ($chat_group !== null && $chat_group <= 0) $chat_group = null;
if ($chat_group) $chat_user = null;

/* ══════════════════════════════════════════════════════════════
   CSRF PROTECTION
   One random token per login session. Every POST to this page
   (forms, fetch calls, the meme modal's calls) must send it back,
   either in the X-CSRF-Token header or in a csrf_token field.
   The page adds the header automatically to every same-origin
   POST, so the meme modal needed no changes.
   ══════════════════════════════════════════════════════════════ */
if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

const MS_EDIT_WINDOW   = 120;               // seconds a sent message can still be edited
const MS_ACTIVE_WINDOW = 150;               // seconds since last activity that still counts as "Active now"
const MS_MAX_UPLOAD    = 25 * 1024 * 1024;  // 25 MB per attachment
const MS_MAX_TEXT      = 4000;              // characters per message

function jsonOut(array $data, int $code = 200): void {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function ms_csrf_valid(): bool {
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    return is_string($sent) && $sent !== '' && hash_equals($_SESSION['csrf_token'], $sent);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // A body bigger than post_max_size arrives empty, so tell the person what really happened.
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $m = 'That file is too large to send.';
        jsonOut(['success' => false, 'status' => 'error', 'error' => $m, 'message' => $m], 413);
    }
    if (!ms_csrf_valid()) {
        $m = 'Your session expired. Refresh the page and try again.';
        jsonOut(['success' => false, 'status' => 'error', 'error' => $m, 'message' => $m, 'csrf' => false], 419);
    }
}

function ms_h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/* Small prepared-statement helpers. They return null instead of crashing when a table is missing. */
function ms_q($conn, string $sql, string $types = '', array $params = []) {
    try {
        $st = $conn->prepare($sql);
        if (!$st) return null;
        if ($types !== '') $st->bind_param($types, ...$params);
        if (!$st->execute()) return null;
        $res = $st->get_result();
        return $res ?: true;
    } catch (Throwable $e) { return null; }
}
function ms_row($conn, string $sql, string $types = '', array $params = []): ?array {
    $res = ms_q($conn, $sql, $types, $params);
    if (!$res || $res === true) return null;
    $row = $res->fetch_assoc();
    return $row ?: null;
}
function ms_rows($conn, string $sql, string $types = '', array $params = []): array {
    $res = ms_q($conn, $sql, $types, $params);
    $out = [];
    if ($res && $res !== true) while ($r = $res->fetch_assoc()) $out[] = $r;
    return $out;
}
function ms_like(string $q): string {
    return '%' . str_replace(['|', '%', '_'], ['||', '|%', '|_'], $q) . '%';
}

/* ── OPTIONAL COLUMNS (created once per login session, like the settings page does) ── */
function ms_has_reply_col($conn): bool {
    if (isset($_SESSION['ms_reply_col'])) return (bool)$_SESSION['ms_reply_col'];
    $ok = false;
    try {
        $r = $conn->query("SHOW COLUMNS FROM messages LIKE 'reply_to'");
        if ($r && $r->num_rows === 0) $conn->query("ALTER TABLE messages ADD COLUMN reply_to INT NULL DEFAULT NULL");
        $r = $conn->query("SHOW COLUMNS FROM messages LIKE 'reply_to'");
        $ok = (bool)($r && $r->num_rows > 0);
    } catch (Throwable $e) { $ok = false; }
    $_SESSION['ms_reply_col'] = $ok ? 1 : 0;
    return $ok;
}

/* Which users column holds "last active"? Uses an existing one, or creates users.last_active. */
function ms_active_col($conn): ?array {
    if (isset($_SESSION['ms_active_col'])) return $_SESSION['ms_active_col'] ?: null;
    $found = null;
    try {
        foreach (['last_active', 'last_seen', 'last_activity', 'last_online'] as $c) {
            $r = $conn->query("SHOW COLUMNS FROM users LIKE '$c'");
            if ($r && ($row = $r->fetch_assoc())) {
                $found = ['name' => $c, 'int' => (bool)preg_match('/int/i', (string)$row['Type'])];
                break;
            }
        }
        if (!$found) {
            $conn->query("ALTER TABLE users ADD COLUMN last_active DATETIME NULL DEFAULT NULL");
            $r = $conn->query("SHOW COLUMNS FROM users LIKE 'last_active'");
            if ($r && $r->fetch_assoc()) $found = ['name' => 'last_active', 'int' => false];
        }
    } catch (Throwable $e) { $found = null; }
    $_SESSION['ms_active_col'] = $found ?: 0;
    return $found;
}
/* SQL expression: seconds since the user was last active (NULL when unknown) */
function ms_active_expr(?array $c, string $alias): string {
    if (!$c) return 'NULL';
    $col = "$alias.`{$c['name']}`";
    return $c['int'] ? "(UNIX_TIMESTAMP() - $col)" : "TIMESTAMPDIFF(SECOND, $col, NOW())";
}
/* Marks the current user as active (at most once every 45 seconds). */
function ms_touch($conn, int $uid): void {
    if (!empty($_SESSION['ms_touch']) && time() - (int)$_SESSION['ms_touch'] < 45) return;
    $c = ms_active_col($conn);
    if (!$c) return;
    $_SESSION['ms_touch'] = time();
    try {
        if ($c['int']) {
            $now = time();
            $st = $conn->prepare("UPDATE users SET `{$c['name']}` = ? WHERE id = ?");
            if ($st) { $st->bind_param('ii', $now, $uid); $st->execute(); }
        } else {
            $st = $conn->prepare("UPDATE users SET `{$c['name']}` = NOW() WHERE id = ?");
            if ($st) { $st->bind_param('i', $uid); $st->execute(); }
        }
    } catch (Throwable $e) { /* presence is best-effort */ }
}

/* ── PRIVACY SETTINGS (the ones saved on the settings page) ── */
function ms_settings($conn, int $uid): array {
    $d = ['allow_messages' => 1, 'show_online_status' => 1, 'is_fully_private' => 0];
    $row = ms_row($conn, "SELECT allow_messages, show_online_status, is_fully_private FROM user_settings WHERE user_id = ? LIMIT 1", 'i', [$uid]);
    if (!$row) $row = ms_row($conn, "SELECT allow_messages, show_online_status FROM user_settings WHERE user_id = ? LIMIT 1", 'i', [$uid]);
    if ($row) foreach ($d as $k => $v) if (isset($row[$k]) && $row[$k] !== null) $d[$k] = (int)$row[$k];
    return $d;
}
/* Same rules the settings page uses to turn user_privacy + is_fully_private into one level */
function ms_privacy_level($conn, int $uid, array $settings): string {
    $row = ms_row($conn, "SELECT privacy_level FROM user_privacy WHERE user_id = ? LIMIT 1", 'i', [$uid]);
    $lv  = $row['privacy_level'] ?? null;
    $flag = !empty($settings['is_fully_private']);
    if (!in_array($lv, ['public', 'limited', 'private', 'full'], true)) $lv = $flag ? 'full' : 'public';
    if ($flag && !in_array($lv, ['private', 'full'], true)) $lv = 'full';
    if (!$flag && in_array($lv, ['private', 'full'], true)) $lv = 'public';
    return $lv;
}

/* ══════════════════════════════════════════════════════════════
   WHO MAY MESSAGE WHOM  — checked on the server for every send
   and every shared post, so typing the URL cannot get around it.
   Returns [allowed (bool), reason code]
     blocked  one of you blocked the other
     off      they turned "Allow messages" off in settings
     trackers their privacy level only allows messages from trackers
   If they already wrote to you first, you can still reply.
   ══════════════════════════════════════════════════════════════ */
function ms_can_message($conn, int $from, int $to): array {
    if ($to <= 0 || $to === $from) return [false, 'invalid'];
    $to_row = ms_row($conn, "SELECT id, user_rank FROM users WHERE id = ? LIMIT 1", 'i', [$to]);
    if (!$to_row) return [false, 'invalid'];
    if (strtolower((string)($to_row['user_rank'] ?? '')) === 'suspended') return [false, 'suspended'];

    if (ms_row($conn, "SELECT 1 AS x FROM blocks WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?) LIMIT 1", 'iiii', [$from, $to, $to, $from]))
        return [false, 'blocked'];

    $they_wrote = (bool)ms_row($conn, "SELECT 1 AS x FROM messages WHERE sender_id = ? AND receiver_id = ? LIMIT 1", 'ii', [$to, $from]);

    $s = ms_settings($conn, $to);
    if ((int)$s['allow_messages'] === 0 && !$they_wrote) return [false, 'off'];

    $lv = ms_privacy_level($conn, $to, $s);
    if ($lv !== 'public' && !$they_wrote) {
        $tracks = ms_row($conn, "SELECT id FROM followers WHERE follower_id = ? AND following_id = ? LIMIT 1", 'ii', [$from, $to]);
        if (!$tracks) return [false, 'trackers'];
    }
    return [true, ''];
}
function ms_block_text(string $code, string $name): string {
    switch ($code) {
        case 'off':      return "$name isn't accepting new messages.";
        case 'trackers': return "$name only accepts messages from people who track them. Track them first.";
        case 'suspended': return "This account is suspended.";
        case 'blocked':  return "You can't message this account.";
        default:         return "You can't message this account.";
    }
}

/* ── PRESENCE ("green dot") ──
   Shown only when BOTH people keep "Show online status" switched on, exactly as the
   settings page promises: "If you turn it off, you won't see theirs either." */
function ms_presence(?float $ago, int $partner_show, int $viewer_show): array {
    /* both numbers are bit masks: 1 = "online" may be shown, 2 = "last seen" may be shown (3 = both).
       A person chooses Everyone / My trackers / Nobody for each; you only see theirs if you share your own. */
    $none = ['visible' => false, 'active' => false, 'label' => ''];
    if ($ago === null || $ago < 0) return $none;
    $okOnline = ($partner_show & 1) && ($viewer_show & 1);
    $okLast   = ($partner_show & 2) && ($viewer_show & 2);
    if ($ago <= MS_ACTIVE_WINDOW) return $okOnline ? ['visible' => true, 'active' => true, 'label' => 'Active now'] : $none;
    if (!$okLast) return $none;
    if ($ago < 3600)       return ['visible' => true, 'active' => false, 'label' => 'Active ' . max(1, (int)floor($ago / 60)) . 'm ago'];
    if ($ago < 86400)      return ['visible' => true, 'active' => false, 'label' => 'Active ' . (int)floor($ago / 3600) . 'h ago'];
    if ($ago < 86400 * 7)  return ['visible' => true, 'active' => false, 'label' => 'Active ' . (int)floor($ago / 86400) . 'd ago'];
    return $none;
}

/* ── DATE / TEXT HELPERS ── */
function dateLabel(string $ts): string {
    $t = strtotime($ts);
    if (date('Y-m-d', $t) === date('Y-m-d'))                        return 'Today';
    if (date('Y-m-d', $t) === date('Y-m-d', strtotime('-1 day')))   return 'Yesterday';
    return date('l, M j', $t);
}
function ms_short_time(?string $ts): string {
    if (!$ts) return '';
    $t = strtotime($ts);
    if (date('Y-m-d', $t) === date('Y-m-d')) return date('g:ia', $t);
    if (date('Y-m-d', $t) === date('Y-m-d', strtotime('-1 day'))) return 'Yesterday';
    if ($t > strtotime('-6 days')) return date('D', $t);
    return date('M j', $t);
}
function ms_preview(array $c, int $me): array {
    $m = trim((string)($c['last_msg'] ?? ''));
    $italic = false;
    if ($m === '[deleted]')                          { $t = 'Message deleted'; $italic = true; }
    elseif (preg_match('/^\[meme:\d+\]$/', $m))      { $t = 'Shared a post'; }
    elseif ($m === '' && !empty($c['last_file']))    { $t = 'Sent an attachment'; }
    else                                             { $t = mb_substr(preg_replace('/\s+/', ' ', $m), 0, 70); }
    if (!$italic && (int)($c['last_sender'] ?? 0) === $me) $t = 'You: ' . $t;
    // two or more unread: "2+ new messages", "20+ new messages" ... once read it shows the last message again
    $un = (int)($c['unread'] ?? 0);
    if ($un >= 2 && (int)($c['last_sender'] ?? 0) !== $me) { $t = ($un > 99 ? '99' : $un) . '+ new messages'; $italic = false; }
    return [$t, $italic];
}

/* ── MEDIA TYPE TAG HELPER (mirrors home.php's _ccmTypeTagInfo) ──── */
function msgTypeTagInfo(string $rawType): array {
    $type = strtolower($rawType);
    $map = [
        'image'    => ['icon' => 'ri-file-copy-line', 'label' => 'Photo'],
        'video'    => ['icon' => 'ri-vidicon-line',    'label' => 'Video'],
        'gif'      => ['icon' => 'ri-file-gif-line',   'label' => 'GIF'],
        'sticker'  => ['icon' => 'ri-shapes-line',     'label' => 'Sticker'],
        'stickers' => ['icon' => 'ri-shapes-line',     'label' => 'Sticker'],
        'text'     => ['icon' => 'ri-file-text-line',  'label' => 'Text'],
    ];
    return $map[$type] ?? ['icon' => 'ri-file-copy-line', 'label' => 'Photo'];
}



/* ══════════════════════════════════════════════════════════════
   EXTRA STORAGE: mute, disappearing messages, download permission
   Created once per login session. If the database user may not create
   tables/columns, these features switch themselves off and chat keeps working.
   ══════════════════════════════════════════════════════════════ */
const MS_DISAPPEAR_MODES = [0 => 'Off', 1 => 'After seen', 2 => '24 hours', 3 => '7 days', 4 => '2 weeks'];
const MS_DISAPPEAR_SECS  = [2 => 86400, 3 => 604800, 4 => 1209600];

function ms_ensure_schema($conn): bool {
    if (isset($_SESSION['ms_feat'])) return (bool)$_SESSION['ms_feat'];
    $ok = false;
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS ms_chat_mutes (user_id INT NOT NULL, other_id INT NOT NULL, PRIMARY KEY (user_id, other_id))");
        $conn->query("CREATE TABLE IF NOT EXISTS ms_disappear (user_a INT NOT NULL, user_b INT NOT NULL, mode TINYINT NOT NULL DEFAULT 0, set_by INT NULL, PRIMARY KEY (user_a, user_b))");
        $conn->query("CREATE TABLE IF NOT EXISTS ms_user_prefs (user_id INT NOT NULL PRIMARY KEY, allow_download TINYINT NOT NULL DEFAULT 1)");
        foreach ([['expires_at', 'DATETIME NULL'], ['vanish', 'TINYINT NOT NULL DEFAULT 0']] as [$col, $def]) {
            $r = $conn->query("SHOW COLUMNS FROM messages LIKE '$col'");
            if ($r && $r->num_rows === 0) $conn->query("ALTER TABLE messages ADD COLUMN $col $def");
        }
        $ok = true;
        foreach (['ms_chat_mutes', 'ms_disappear', 'ms_user_prefs'] as $t) if (ms_q($conn, "SELECT 1 AS x FROM $t LIMIT 1") === null) $ok = false;
        foreach (['expires_at', 'vanish'] as $col) {
            $r = $conn->query("SHOW COLUMNS FROM messages LIKE '$col'");
            if (!$r || $r->num_rows === 0) $ok = false;
        }
    } catch (Throwable $e) { $ok = false; }
    $_SESSION['ms_feat'] = $ok ? 1 : 0;
    return $ok;
}
$MS_FEAT = ms_ensure_schema($conn);

function ms_pair(int $a, int $b): array { return $a < $b ? [$a, $b] : [$b, $a]; }
function ms_disappear_mode($conn, int $a, int $b): int {
    global $MS_FEAT; if (!$MS_FEAT) return 0;
    [$x, $y] = ms_pair($a, $b);
    $r = ms_row($conn, "SELECT mode FROM ms_disappear WHERE user_a = ? AND user_b = ? LIMIT 1", 'ii', [$x, $y]);
    return $r ? (int)$r['mode'] : 0;
}
function ms_is_muted($conn, int $me, int $other): bool {
    global $MS_FEAT; if (!$MS_FEAT) return false;
    return (bool)ms_row($conn, "SELECT 1 AS x FROM ms_chat_mutes WHERE user_id = ? AND other_id = ? LIMIT 1", 'ii', [$me, $other]);
}
/* Whether the sender of a message lets the other person download their photos and videos (default: yes) */
function ms_allows_download($conn, int $uid): bool {
    global $MS_FEAT; if (!$MS_FEAT) return true;
    $r = ms_row($conn, "SELECT allow_download FROM ms_user_prefs WHERE user_id = ? LIMIT 1", 'i', [$uid]);
    return $r ? (int)$r['allow_download'] === 1 : true;
}
function ms_unlink_upload($path): void {
    $path = (string)$path;
    if (strpos($path, 'uploads/messages/') !== 0 || strpos($path, '..') !== false) return;
    if (is_file($path)) @unlink($path);
}
/* Deletes messages whose time is up. $seen_too also removes "after seen" messages that were already seen
   (done when a conversation page is opened, i.e. after the people have left the chat). */
function ms_purge($conn, int $me, bool $seen_too): void {
    global $MS_FEAT; if (!$MS_FEAT) return;
    $now = date('Y-m-d H:i:s');
    $sql = "SELECT id, file_attachment FROM messages WHERE (sender_id = ? OR receiver_id = ?) AND ((expires_at IS NOT NULL AND expires_at <= ?)" . ($seen_too ? " OR (vanish = 1 AND seen = 1)" : "") . ") LIMIT 500";
    $rows = ms_rows($conn, $sql, 'iis', [$me, $me, $now]);
    if (!$rows) return;
    $ids = [];
    foreach ($rows as $r) { $ids[] = (int)$r['id']; ms_unlink_upload($r['file_attachment']); }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    ms_q($conn, "DELETE FROM messages WHERE id IN ($ph)", str_repeat('i', count($ids)), $ids);
}

/* total unread messages, not counting chats you muted */
function ms_unread_total($conn, int $me): int {
    global $MS_FEAT;
    $sql = "SELECT COUNT(*) AS n FROM messages WHERE receiver_id = ? AND seen = 0" . ($MS_FEAT ? " AND sender_id NOT IN (SELECT other_id FROM ms_chat_mutes WHERE user_id = " . (int)$me . ")" : "");
    $r = ms_row($conn, $sql, 'i', [$me]);
    return $r ? (int)$r['n'] : 0;
}
/* ── NEW: MULTI-MEDIA HELPER ─────────────────────────────────
   Returns the full array of media items for a meme: the main
   memes.file_name/type row PLUS any extra rows in meme_media,
   ordered by position. Mirrors home.php's $_media_map logic
   but as a per-meme fetch for messages.php's endpoints. */
function getMemeMediaArray($conn, int $mid, string $mainFile, string $mainType): array {
    $items = [[ 'file' => 'uploads/' . $mainFile, 'type' => $mainType ]];
    $stmt = $conn->prepare("SELECT file_name, type FROM meme_media WHERE meme_id=? ORDER BY position ASC");
    $stmt->bind_param("i", $mid);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $items[] = [ 'file' => 'uploads/' . $r['file_name'], 'type' => $r['type'] ];
    }
    return $items;
}

/* ── NEW: ATTACHED-AUDIO HELPER ───────────────────────────────
   Returns the attached soundtrack info for a meme (title,
   artist, file, volumes) if one exists in meme_audio/audios,
   or null if the meme has no attached track. Mirrors
   home.php's $_audio_map logic but as a per-meme fetch. */
function getMemeAudioInfo($conn, int $mid): ?array {
    $stmt = $conn->prepare("
        SELECT a.title, a.artist, a.file_name, ma.music_volume, ma.video_audio_volume
        FROM meme_audio ma
        JOIN audios a ON a.id = ma.audio_id
        WHERE ma.meme_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $mid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) return null;
    return [
        'audioTitle'       => $row['title'],
        'audioArtist'      => $row['artist'] ?: null,
        'audioFile'        => 'uploads/' . $row['file_name'],
        'musicVolume'      => (int)$row['music_volume'],
        'videoAudioVolume' => (int)$row['video_audio_volume'],
    ];
}

/* ── NEW: GROUP/POD INFO HELPER ───────────────────────────────
   Returns the group a meme was posted in (id + title) if it was
   posted inside a group/pod, or null otherwise. Mirrors home.php's
   $_group_map logic but as a per-meme fetch for messages.php's
   single_meme endpoint. (chat_shared_memes bulk-prefetches this
   the same way home.php does, since it handles multiple memes.) */
function getMemeGroupInfo($conn, int $mid): ?array {
    $stmt = $conn->prepare("
        SELECT g.id AS group_id, g.title AS group_title
        FROM group_posts gp
        JOIN groups g ON g.id = gp.group_id
        WHERE gp.meme_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $mid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) return null;
    return [
        'groupId'    => (int)$row['group_id'],
        'groupTitle' => $row['group_title'],
    ];
}
/* ══════════════════════════════════════════════════════════════
   GROUP CHATS + TYPING INDICATOR (extra tables, created once per login)
   Groups live in their own tables, so one-to-one chats are untouched.
   If the database user cannot create tables, groups switch themselves off.
   ══════════════════════════════════════════════════════════════ */
const MS_GROUP_MAX     = 50;     // people in one group, including you
const MS_GROUP_MIN_NEW = 2;      // people you must pick when you start a group
const MS_TYPING_WINDOW = 6;      // seconds a "typing…" signal stays alive
const MS_PAGE_SIZE     = 30;
const MS_GROUP_PAGE    = 15;     // groups listed at a time (infinite scroll)
const MS_CONV_PAGE     = 15;     // messages loaded at a time (older ones load while you scroll up)

function ms_ensure_groups($conn): bool {
    if (isset($_SESSION['ms_grp2'])) return (bool)$_SESSION['ms_grp2'];
    $ok = false;
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS msg_gc_groups (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, owner_id INT NOT NULL, created_at DATETIME NULL, bg VARCHAR(255) NULL, bg_type VARCHAR(40) NULL)");
        $conn->query("CREATE TABLE IF NOT EXISTS msg_gc_members (group_id INT NOT NULL, user_id INT NOT NULL, role TINYINT NOT NULL DEFAULT 0, joined_at DATETIME NULL, PRIMARY KEY (group_id, user_id))");
        $conn->query("CREATE TABLE IF NOT EXISTS msg_gc_messages (id INT AUTO_INCREMENT PRIMARY KEY, group_id INT NOT NULL, sender_id INT NOT NULL, message TEXT NULL, file_attachment VARCHAR(255) NULL, reply_to INT NULL, kind TINYINT NOT NULL DEFAULT 0, created_at DATETIME NULL)");
        $conn->query("CREATE TABLE IF NOT EXISTS msg_gc_reads (group_id INT NOT NULL, user_id INT NOT NULL, last_read_id INT NOT NULL DEFAULT 0, PRIMARY KEY (group_id, user_id))");
        $conn->query("CREATE TABLE IF NOT EXISTS msg_gc_typing (user_id INT NOT NULL, kind CHAR(1) NOT NULL, target_id INT NOT NULL, ts INT NOT NULL, PRIMARY KEY (user_id, kind, target_id))");
        $ok = true;
        foreach (['msg_gc_groups', 'msg_gc_members', 'msg_gc_messages', 'msg_gc_reads', 'msg_gc_typing'] as $t) if (ms_q($conn, "SELECT 1 AS x FROM $t LIMIT 1") === null) $ok = false;
        if ($ok) {
            foreach (["CREATE INDEX ms_gm_group ON msg_gc_messages (group_id, id)",
                      "ALTER TABLE msg_gc_messages CONVERT TO CHARACTER SET utf8mb4",
                      "ALTER TABLE msg_gc_groups CONVERT TO CHARACTER SET utf8mb4"] as $q) { try { $conn->query($q); } catch (Throwable $e) {} }
        }
    } catch (Throwable $e) { $ok = false; }
    $_SESSION['ms_grp2'] = $ok ? 1 : 0;
    return $ok;
}
/* group background columns (added to the table if it was created without them) */
function ms_group_bg_ok($conn): bool {
    global $MS_GRP; if (!$MS_GRP) return false;
    if (isset($_SESSION['ms_grp_bg'])) return (bool)$_SESSION['ms_grp_bg'];
    $ok = false;
    try {
        $r = $conn->query("SHOW COLUMNS FROM msg_gc_groups LIKE 'bg'");
        if ($r && $r->num_rows === 0) { $conn->query("ALTER TABLE msg_gc_groups ADD COLUMN bg VARCHAR(255) NULL"); $conn->query("ALTER TABLE msg_gc_groups ADD COLUMN bg_type VARCHAR(40) NULL"); }
        $r = $conn->query("SHOW COLUMNS FROM msg_gc_groups LIKE 'bg'");
        $ok = (bool)($r && $r->num_rows > 0);
    } catch (Throwable $e) { $ok = false; }
    $_SESSION['ms_grp_bg'] = $ok ? 1 : 0;
    return $ok;
}
$MS_GRP = ms_ensure_groups($conn);
$MS_GBG = ms_group_bg_ok($conn);   // group background can be stored
$MS_GROUP_CTX = false;     // true while group messages are being rendered

/* ── archive (chats and groups you tucked away) ── */
function ms_ensure_archive($conn): bool {
    if (isset($_SESSION['ms_arc'])) return (bool)$_SESSION['ms_arc'];
    $ok = false;
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS msg_chat_archive (user_id INT NOT NULL, kind CHAR(1) NOT NULL, target_id INT NOT NULL, PRIMARY KEY (user_id, kind, target_id))");
        $ok = ms_q($conn, "SELECT 1 AS x FROM msg_chat_archive LIMIT 1") !== null;
    } catch (Throwable $e) { $ok = false; }
    $_SESSION['ms_arc'] = $ok ? 1 : 0;
    return $ok;
}
$MS_ARC = ms_ensure_archive($conn);
function ms_arc_sub(int $me, string $kind): string {
    return "(SELECT target_id FROM msg_chat_archive WHERE user_id = " . (int)$me . " AND kind = '" . ($kind === 'g' ? 'g' : 'u') . "')";
}
/* is any (unmuted) group waiting for you: new messages, or you were added and have not opened it yet */
function ms_group_attention($conn, int $me): bool {
    global $MS_GRP, $MS_FEAT; if (!$MS_GRP) return false;
    $r = ms_row($conn, "SELECT COUNT(*) AS n FROM msg_gc_members mem LEFT JOIN msg_gc_reads gr ON gr.group_id = mem.group_id AND gr.user_id = mem.user_id
        WHERE mem.user_id = ? AND (gr.group_id IS NULL OR EXISTS (SELECT 1 FROM msg_gc_messages x WHERE x.group_id = mem.group_id AND x.sender_id <> ? AND x.kind = 0 AND x.id > COALESCE(gr.last_read_id, 0)))
        " . ($MS_FEAT ? "AND mem.group_id NOT IN (SELECT -other_id FROM ms_chat_mutes WHERE user_id = " . (int)$me . " AND other_id < 0)" : ""), 'ii', [$me, $me]);
    return $r && (int)$r['n'] > 0;
}

/* ── typing ── */
function ms_typing_set($conn, int $me, string $kind, int $target): void {
    global $MS_GRP; if (!$MS_GRP) return;
    ms_q($conn, "DELETE FROM msg_gc_typing WHERE user_id = ? AND kind = ? AND target_id = ?", 'isi', [$me, $kind, $target]);
    ms_q($conn, "INSERT INTO msg_gc_typing (user_id, kind, target_id, ts) VALUES (?, ?, ?, ?)", 'isii', [$me, $kind, $target, time()]);
}
function ms_typing_clear($conn, int $me, string $kind, int $target): void {
    global $MS_GRP; if (!$MS_GRP) return;
    ms_q($conn, "DELETE FROM msg_gc_typing WHERE user_id = ? AND kind = ? AND target_id = ?", 'isi', [$me, $kind, $target]);
}
/* who is typing to me right now: [] or a list of ['id','name','pic'] */
function ms_typing_get($conn, int $me, string $kind, int $target): array {
    global $MS_GRP; if (!$MS_GRP) return [];
    $min = time() - MS_TYPING_WINDOW;
    if ($kind === 'u') {   // the other person typing in our one-to-one chat
        $rows = ms_rows($conn, "SELECT u.id, u.username, u.profile_pic FROM msg_gc_typing t JOIN users u ON u.id = t.user_id WHERE t.kind = 'u' AND t.user_id = ? AND t.target_id = ? AND t.ts >= ? LIMIT 1", 'iii', [$target, $me, $min]);
    } else {
        $rows = ms_rows($conn, "SELECT u.id, u.username, u.profile_pic FROM msg_gc_typing t JOIN users u ON u.id = t.user_id WHERE t.kind = 'g' AND t.target_id = ? AND t.user_id <> ? AND t.ts >= ? LIMIT 3", 'iii', [$target, $me, $min]);
    }
    return array_map(function ($r) { return ['id' => (int)$r['id'], 'name' => $r['username'], 'pic' => $r['profile_pic'] ?: 'mee.jpg']; }, $rows);
}

/* ── groups ── */
function ms_group_is_member($conn, int $gid, int $uid): bool {
    global $MS_GRP; if (!$MS_GRP || $gid <= 0) return false;
    return (bool)ms_row($conn, "SELECT 1 AS x FROM msg_gc_members WHERE group_id = ? AND user_id = ? LIMIT 1", 'ii', [$gid, $uid]);
}
function ms_group_get($conn, int $gid, int $me): ?array {
    if (!ms_group_is_member($conn, $gid, $me)) return null;
    global $MS_GBG;
    return ms_row($conn, "SELECT id, name, owner_id, created_at" . ($MS_GBG ? ", bg, bg_type" : ", NULL AS bg, NULL AS bg_type") . " FROM msg_gc_groups WHERE id = ? LIMIT 1", 'i', [$gid]);
}
function msg_gc_members($conn, int $gid): array {
    return ms_rows($conn, "SELECT u.id, u.username, u.nickname, u.profile_pic, COALESCE(u.is_verified, 0) AS is_verified, gm.role
        FROM msg_gc_members gm JOIN users u ON u.id = gm.user_id WHERE gm.group_id = ? ORDER BY gm.role DESC, gm.joined_at ASC, u.id ASC", 'i', [$gid]);
}
/* up to 3 pictures for the group avatar: other members first, you only if the group is tiny */
function ms_group_pics(array $members, int $me): array {
    $pics = [];
    foreach ($members as $m) if ((int)$m['id'] !== $me) $pics[] = $m['profile_pic'] ?: 'mee.jpg';
    if (count($pics) < 3) foreach ($members as $m) if ((int)$m['id'] === $me) { $pics[] = $m['profile_pic'] ?: 'mee.jpg'; break; }
    return array_slice($pics, 0, 3);
}
function ms_group_stack(array $pics, string $cls = ''): string {
    $n = max(1, count($pics));
    $h = '<span class="gstack n' . $n . ' ' . $cls . '">';
    foreach ($pics as $p) $h .= '<img src="' . ms_h($p) . '" alt="" loading="lazy" onerror="this.src=\'mee.jpg\'">';
    if (!$pics) $h .= '<img src="mee.jpg" alt="">';
    return $h . '</span>';
}
function ms_group_pic_map($conn, array $gids, int $me): array {
    if (!$gids) return [];
    $ph = implode(',', array_fill(0, count($gids), '?'));
    $rows = ms_rows($conn, "SELECT gm.group_id, u.id, u.profile_pic FROM msg_gc_members gm JOIN users u ON u.id = gm.user_id WHERE gm.group_id IN ($ph) ORDER BY gm.group_id ASC, gm.joined_at ASC, u.id ASC", str_repeat('i', count($gids)), $gids);
    $by = [];
    foreach ($rows as $r) $by[(int)$r['group_id']][] = ['id' => (int)$r['id'], 'profile_pic' => $r['profile_pic']];
    $out = [];
    foreach ($gids as $g) $out[$g] = ms_group_pics($by[$g] ?? [], $me);
    return $out;
}
function ms_group_msg_sql($conn): string {
    global $MS_FEAT;
    return "SELECT m.id, m.group_id, m.sender_id, m.message, m.file_attachment, m.reply_to, m.kind, m.created_at, 0 AS seen, NULL AS expires_at, 0 AS vanish,
            " . ($MS_FEAT ? "COALESCE(up.allow_download, 1)" : "1") . " AS sender_dl, u.username, u.profile_pic,
            rm.message AS reply_text, rm.sender_id AS reply_sender, ru.username AS reply_name
        FROM msg_gc_messages m JOIN users u ON u.id = m.sender_id
        " . ($MS_FEAT ? "LEFT JOIN ms_user_prefs up ON up.user_id = m.sender_id " : "") . "
        LEFT JOIN msg_gc_messages rm ON rm.id = m.reply_to LEFT JOIN users ru ON ru.id = rm.sender_id";
}
function ms_group_sys($conn, int $gid, int $actor, string $text): void {
    ms_q($conn, "INSERT INTO msg_gc_messages (group_id, sender_id, message, kind, created_at) VALUES (?, ?, ?, 1, NOW())", 'iis', [$gid, $actor, $text]);
}
/* ══════════════════════════════════════════════════════════════
   GROUP POLLS  (a poll is a group message with kind = 2; its question is the message text)
   ══════════════════════════════════════════════════════════════ */
const MS_POLL_MAX_OPTS = 12;
function ms_poll_ok($conn): bool {
    global $MS_GRP; if (!$MS_GRP) return false;
    if (isset($_SESSION['ms_poll1'])) return (bool)$_SESSION['ms_poll1'];
    $ok = true;
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS msg_gc_polls (id INT AUTO_INCREMENT PRIMARY KEY, message_id INT NOT NULL, group_id INT NOT NULL, creator_id INT NOT NULL, question VARCHAR(200) NOT NULL, multi TINYINT NOT NULL DEFAULT 0, closed TINYINT NOT NULL DEFAULT 0, created_at DATETIME NULL, KEY ms_pl_msg (message_id), KEY ms_pl_grp (group_id)) DEFAULT CHARSET=utf8mb4");
        $conn->query("CREATE TABLE IF NOT EXISTS msg_gc_poll_options (id INT AUTO_INCREMENT PRIMARY KEY, poll_id INT NOT NULL, label VARCHAR(100) NOT NULL, pos TINYINT NOT NULL DEFAULT 0, KEY ms_po_poll (poll_id)) DEFAULT CHARSET=utf8mb4");
        $conn->query("CREATE TABLE IF NOT EXISTS msg_gc_poll_votes (poll_id INT NOT NULL, option_id INT NOT NULL, user_id INT NOT NULL, created_at DATETIME NULL, PRIMARY KEY (option_id, user_id), KEY ms_pv_poll (poll_id, user_id)) DEFAULT CHARSET=utf8mb4");
        foreach (['msg_gc_polls', 'msg_gc_poll_options', 'msg_gc_poll_votes'] as $t) if (ms_q($conn, "SELECT 1 AS x FROM $t LIMIT 1") === null) $ok = false;
    } catch (Throwable $e) { $ok = false; }
    $_SESSION['ms_poll1'] = $ok ? 1 : 0;
    return $ok;
}
/* poll data keyed by poll id ($by = 'id') or by the id of its chat message ($by = 'message_id'); $gid > 0 limits to one group */
function ms_poll_load($conn, array $keys, int $me, string $by = 'id', int $gid = 0): array {
    $keys = array_values(array_unique(array_filter(array_map('intval', $keys), function ($x) { return $x > 0; })));
    if (!$keys || !ms_poll_ok($conn)) return [];
    $col = $by === 'message_id' ? 'p.message_id' : 'p.id';
    $ph = implode(',', array_fill(0, count($keys), '?')); $ty = str_repeat('i', count($keys));
    $polls = ms_rows($conn, "SELECT p.*, u.username, u.profile_pic, COALESCE(u.is_verified, 0) AS is_verified FROM msg_gc_polls p JOIN users u ON u.id = p.creator_id WHERE $col IN ($ph)" . ($gid > 0 ? " AND p.group_id = " . (int)$gid : ''), $ty, $keys);
    if (!$polls) return [];
    $pids = array_map(function ($r) { return (int)$r['id']; }, $polls);
    $ph2 = implode(',', array_fill(0, count($pids), '?')); $ty2 = str_repeat('i', count($pids));
    $opts  = ms_rows($conn, "SELECT id, poll_id, label FROM msg_gc_poll_options WHERE poll_id IN ($ph2) ORDER BY pos, id", $ty2, $pids);
    $votes = ms_rows($conn, "SELECT v.poll_id, v.option_id, v.user_id, u.profile_pic FROM msg_gc_poll_votes v JOIN users u ON u.id = v.user_id WHERE v.poll_id IN ($ph2) ORDER BY v.created_at DESC", $ty2, $pids);
    $out = [];
    foreach ($polls as $r) {
        $out[(int)$r['id']] = ['id' => (int)$r['id'], 'message_id' => (int)$r['message_id'], 'group_id' => (int)$r['group_id'], 'creator_id' => (int)$r['creator_id'],
            'question' => (string)$r['question'], 'multi' => (int)$r['multi'], 'closed' => (int)$r['closed'], 'username' => (string)$r['username'],
            'pic' => (string)($r['profile_pic'] ?: 'mee.jpg'), 'verified' => (int)$r['is_verified'], 'options' => [], 'voters' => 0, 'mine' => []];
    }
    foreach ($opts as $o) $out[(int)$o['poll_id']]['options'][(int)$o['id']] = ['id' => (int)$o['id'], 'label' => (string)$o['label'], 'n' => 0, 'mine' => 0, 'pics' => []];
    $seen = [];
    foreach ($votes as $v) {
        $pid = (int)$v['poll_id']; $oid = (int)$v['option_id']; $uid = (int)$v['user_id'];
        if (!isset($out[$pid]['options'][$oid])) continue;
        $out[$pid]['options'][$oid]['n']++;
        if (count($out[$pid]['options'][$oid]['pics']) < 3) $out[$pid]['options'][$oid]['pics'][] = (string)($v['profile_pic'] ?: 'mee.jpg');
        if ($uid === $me) { $out[$pid]['options'][$oid]['mine'] = 1; $out[$pid]['mine'][] = $oid; }
        if (!isset($seen[$pid][$uid])) { $seen[$pid][$uid] = 1; $out[$pid]['voters']++; }
    }
    return $out;
}
function ms_poll_fmt(int $n): string {
    if ($n >= 1000000) return rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.') . 'M';
    if ($n >= 1000)    return rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'K';
    return (string)$n;
}
/* the card itself (also what the live refresh and a vote send back) */
function ms_poll_card(array $p, int $me): string {
    $opts = array_values($p['options']); $n = count($opts); $base = max(1, (int)$p['voters']);
    $can_close = !$p['closed'] && ((int)$p['creator_id'] === $me);
    ob_start(); ?>
<div class="poll-card<?= $p['closed'] ? ' pc-closed' : '' ?><?= $p['multi'] ? ' pc-multi' : '' ?>" data-poll="<?= (int)$p['id'] ?>" data-multi="<?= (int)$p['multi'] ?>" data-v="<?= ms_h(implode(',', $p['mine'])) ?>">
  <div class="pc-head">
    <img class="pc-av" src="<?= ms_h($p['pic']) ?>" alt="" onerror="this.src='mee.jpg'">
    <div class="pc-hd"><div class="pc-q"><?= ms_h($p['question']) ?></div>
    <div class="pc-sub"><?= $p['closed'] ? 'Final results' : ($p['multi'] ? 'Choose as many as you like' : 'Choose one') ?></div></div>
  </div>
  <div class="pc-opts">
  <?php foreach ($opts as $i => $o): $pct = $o['n'] > 0 ? max(4, (int)round($o['n'] / $base * 100)) : 0; ?>
    <button type="button" class="pc-opt<?= $o['mine'] ? ' mine sel' : '' ?><?= $i >= 3 ? ' pc-hid' : '' ?>" data-opt="<?= (int)$o['id'] ?>"<?= $p['closed'] ? ' disabled' : '' ?>>
      <span class="pc-row">
        <span class="pc-mark"><i class="ri-check-line"></i></span>
        <span class="pc-lab"><?= ms_h($o['label']) ?></span>
        <span class="pc-right">
          <?php if ($o['pics']): ?><span class="pc-stack"><?php foreach (array_reverse($o['pics']) as $pp): ?><img src="<?= ms_h($pp) ?>" alt="" onerror="this.src='mee.jpg'"><?php endforeach; ?></span><?php endif; ?>
          <span class="pc-cnt<?= $o['n'] ? '' : ' zero' ?>"><?= ms_poll_fmt((int)$o['n']) ?></span>
        </span>
      </span>
      <span class="pc-bar"><i style="width:<?= $pct ?>%"></i></span>
    </button>
  <?php endforeach; ?>
  </div>
  <?php if ($n > 3): ?><button type="button" class="pc-more" data-n="<?= $n - 3 ?>"><?= $n - 3 ?> more option<?= $n - 3 === 1 ? '' : 's' ?></button><?php endif; ?>
  <?php if (!$p['closed']): ?><button type="button" class="pc-vote" disabled><?= $p['mine'] ? 'Update vote' : 'Vote' ?></button><?php endif; ?>
  <div class="pc-foot"><span><?= ms_poll_fmt((int)$p['voters']) ?> vote<?= (int)$p['voters'] === 1 ? '' : 's' ?><?= $p['closed'] ? ' · Poll ended' : '' ?></span><?php if ($can_close): ?><button type="button" class="pc-end">End poll</button><?php endif; ?></div>
</div>
<?php
    return ob_get_clean();
}
/* the whole chat row: "name created a poll" + the card */
function ms_poll_row_html(array $msg, int $me, ?array $p): string {
    $who = ((int)$msg['sender_id'] === $me) ? 'You' : (string)($msg['username'] ?? '');
    $head = '<div class="msg-row sys poll-row" data-id="' . (int)$msg['id'] . '" data-ids="' . (int)$msg['id'] . '" data-sender="0" data-ts="' . (int)strtotime($msg['created_at']) . '">';
    if (!$p) return $head . '<div class="sys-text"><b>' . ms_h($who) . '</b> created a poll</div></div>';
    return $head . '<div class="sys-text"><b>' . ms_h($who) . '</b> created a poll</div>' . ms_poll_card($p, $me) . '</div>';
}
function ms_poll_clean(string $s, int $max): string {
    return trim(mb_substr(trim(preg_replace('/\s+/u', ' ', $s)), 0, $max));
}
function ms_group_mark_read($conn, int $gid, int $uid): void {
    $mx = ms_row($conn, "SELECT MAX(id) AS m FROM msg_gc_messages WHERE group_id = ?", 'i', [$gid]);
    $mx = (int)($mx['m'] ?? 0);
    ms_q($conn, "DELETE FROM msg_gc_reads WHERE group_id = ? AND user_id = ?", 'ii', [$gid, $uid]);
    ms_q($conn, "INSERT INTO msg_gc_reads (group_id, user_id, last_read_id) VALUES (?, ?, ?)", 'iii', [$gid, $uid, $mx]);
}
function ms_group_unread_total($conn, int $me): int {
    global $MS_GRP, $MS_FEAT; if (!$MS_GRP) return 0;
    $r = ms_row($conn, "SELECT COUNT(*) AS n FROM msg_gc_messages gm JOIN msg_gc_members mem ON mem.group_id = gm.group_id AND mem.user_id = ?
        LEFT JOIN msg_gc_reads gr ON gr.group_id = gm.group_id AND gr.user_id = ?
        WHERE gm.sender_id <> ? AND gm.kind = 0 AND gm.id > COALESCE(gr.last_read_id, 0)
          " . ($MS_FEAT ? "AND gm.group_id NOT IN (SELECT -other_id FROM ms_chat_mutes WHERE user_id = " . (int)$me . " AND other_id < 0)" : ""), 'iii', [$me, $me, $me]);
    return $r ? (int)$r['n'] : 0;
}
/* the group list (your groups, newest activity first) */
function ms_fetch_groups($conn, int $me, ?int $limit = null, int $offset = 0, bool $archived = false): array {
    global $MS_GRP, $MS_FEAT, $MS_ARC; if (!$MS_GRP) return [];
    $arc_where = $MS_ARC ? ($archived ? " AND g.id IN " : " AND g.id NOT IN ") . ms_arc_sub($me, 'g') : '';
    $sql = "SELECT g.id, g.name, g.owner_id,
            (SELECT COUNT(*) FROM msg_gc_members WHERE group_id = g.id) AS mcount,
            lm.message AS last_msg, lm.file_attachment AS last_file, lm.sender_id AS last_sender, lm.kind AS last_kind, lm.created_at AS last_time, lu.username AS last_name,
            COALESCE(lm.created_at, g.created_at) AS sort_time,
            (SELECT COUNT(*) FROM msg_gc_messages x WHERE x.group_id = g.id AND x.sender_id <> ? AND x.kind = 0 AND x.id > COALESCE(gr.last_read_id, 0)) AS unread,
            (CASE WHEN gr.group_id IS NULL THEN 1 ELSE 0 END) AS fresh,
            " . ($MS_FEAT ? "(SELECT COUNT(*) FROM ms_chat_mutes WHERE user_id = " . (int)$me . " AND other_id = -g.id)" : "0") . " AS muted
        FROM msg_gc_members gmem
        JOIN msg_gc_groups g ON g.id = gmem.group_id
        LEFT JOIN msg_gc_reads gr ON gr.group_id = g.id AND gr.user_id = ?
        LEFT JOIN msg_gc_messages lm ON lm.id = (SELECT MAX(id) FROM msg_gc_messages WHERE group_id = g.id)
        LEFT JOIN users lu ON lu.id = lm.sender_id
        WHERE gmem.user_id = ?$arc_where
        ORDER BY sort_time DESC, g.id DESC" . ($limit !== null ? " LIMIT " . (int)$limit . " OFFSET " . max(0, (int)$offset) : '');
    $rows = ms_rows($conn, $sql, 'iii', [$me, $me, $me]);
    $pics = ms_group_pic_map($conn, array_map(function ($r) { return (int)$r['id']; }, $rows), $me);
    foreach ($rows as &$r) $r['pics'] = $pics[(int)$r['id']] ?? [];
    unset($r);
    return $rows;
}
function ms_group_preview(array $g, int $me): array {
    $m = trim((string)($g['last_msg'] ?? ''));
    $who = ((int)($g['last_sender'] ?? 0) === $me) ? 'You' : (string)($g['last_name'] ?? '');
    if (empty($g['last_time']))                         return ['No messages yet', true];
    if ((int)($g['last_kind'] ?? 0) === 1)              return [$who . ' ' . $m, true];
    $italic = false;
    if ($m === '[deleted]')                             { $t = 'Message deleted'; $italic = true; }
    elseif ($m === '' && !empty($g['last_file']))       { $t = 'Sent an attachment'; }
    else                                                { $t = mb_substr(preg_replace('/\s+/', ' ', $m), 0, 60); }
    if ((int)($g['last_kind'] ?? 0) === 2)              { $t = 'Poll: ' . mb_substr(preg_replace('/\s+/', ' ', $m), 0, 50); }
    $un = (int)($g['unread'] ?? 0);
    if ($un >= 2 && (int)($g['last_sender'] ?? 0) !== $me) return [($un > 99 ? '99' : $un) . '+ new messages', false];
    return [$who . ': ' . $t, $italic];
}
function ms_render_group_conv(array $g, int $me, int $active_gid): string {
    [$prev, $italic] = ms_group_preview($g, $me);
    $unread = (int)$g['unread'];
    $dot = $unread > 0 || !empty($g['fresh']);
    ob_start(); ?>
<div class="conv-item group-item <?= $active_gid === (int)$g['id'] ? 'active' : '' ?>" data-gid="<?= (int)$g['id'] ?>" onclick="groupClick(event, <?= (int)$g['id'] ?>)">
  <div class="conv-checkbox"><div class="box"><i class="ri-check-line"></i></div></div>
  <div class="conv-avatar"><?= ms_group_stack($g['pics']) ?></div>
  <div class="conv-body">
    <div class="conv-name"><span class="conv-name-t"><?= ms_h($g['name']) ?></span><?= !empty($g['muted']) ? '<i class="ri-notification-off-fill conv-mute" title="Muted"></i>' : '' ?></div>
    <div class="conv-last <?= $unread > 0 ? 'unread' : '' ?>"><?= $italic ? '<i>' . ms_h($prev) . '</i>' : ms_h($prev) ?></div>
  </div>
  <div class="conv-meta">
    <span class="conv-time"><?= ms_h(ms_short_time($g['last_time'] ?? null)) ?></span>
    <?php if ($dot): ?><span class="conv-badge<?= !empty($g['muted']) ? ' muted' : '' ?>" title="<?= $unread > 0 ? $unread . ' unread' : 'New group' ?>"></span><?php endif; ?>
  </div>
</div>
<?php
    return ob_get_clean();
}

/* ══════════════════════════════════════════════════════════════
   GROUP + "OLDER MESSAGES" ENDPOINTS
   (placed before the one-to-one endpoints; each handles only its own names)
   ══════════════════════════════════════════════════════════════ */
function ms_group_html($conn, array $rows, int $me, ?int $prev_sender, ?string $prev_date): string {
    global $MS_GROUP_CTX;
    $MS_GROUP_CTX = true;
    $h = ms_render_batch($rows, $me, ms_shared_memes($conn, $rows), $prev_sender, $prev_date);
    $MS_GROUP_CTX = false;
    return $h;
}
/* who sent the message just before $before_id (null when it was a system line), and its date label */
function ms_group_prev($conn, int $gid, int $before_id): array {
    $p = ms_row($conn, "SELECT sender_id, kind, created_at FROM msg_gc_messages WHERE group_id = ? AND id < ? ORDER BY id DESC LIMIT 1", 'ii', [$gid, $before_id]);
    if (!$p) return [null, null];
    return [((int)$p['kind'] >= 1) ? null : (int)$p['sender_id'], dateLabel($p['created_at'])];
}
/* people you already have a chat with (either of you has messaged the other) */
function ms_has_chat($conn, int $a, int $b): bool {
    return (bool)ms_row($conn, "SELECT 1 AS x FROM messages WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?) LIMIT 1", 'iiii', [$a, $b, $b, $a]);
}
/* archived chats + groups as list rows, newest first: [html, count] */
function ms_render_archive($conn, int $me, ?int $chat_user, ?int $chat_group, int $viewer_show): array {
    global $MS_ARC; if (!$MS_ARC) return ['', 0];
    $items = [];
    foreach (ms_fetch_convs($conn, $me, null, null, 0, true) as $c)
        $items[] = [strtotime((string)($c['last_time'] ?? '')) ?: 0, ms_render_conv($c, $me, $chat_user, false, $viewer_show)];
    foreach (ms_fetch_groups($conn, $me, null, 0, true) as $g)
        $items[] = [strtotime((string)($g['sort_time'] ?? '')) ?: 0, ms_render_group_conv($g, $me, (int)$chat_group)];
    usort($items, function ($a, $b) { return $b[0] <=> $a[0]; });
    return [implode('', array_map(function ($i) { return $i[1]; }, $items)), count($items)];
}
function ms_clean_group_name(string $n): string {
    $n = trim(preg_replace('/\s+/u', ' ', $n));
    return mb_substr($n, 0, 60);
}
/* validates and stores uploads (photos / videos); $fail() must exit */
function ms_collect_uploads(callable $fail, array &$saved): void {
    if (!isset($_FILES['attachment'])) return;
    $fa = $_FILES['attachment']; $files = [];
    if (is_array($fa['name'])) { foreach ($fa['name'] as $i => $nm) $files[] = ['name' => $nm, 'tmp_name' => $fa['tmp_name'][$i], 'size' => $fa['size'][$i], 'error' => $fa['error'][$i]]; }
    else $files[] = $fa;
    $files = array_values(array_filter($files, function ($f) { return $f['error'] !== UPLOAD_ERR_NO_FILE; }));
    if (count($files) > 10) $fail('You can send up to 10 photos or videos at once.');
    $allowed = ['jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'gif' => 'image', 'webp' => 'image', 'mp4' => 'video', 'webm' => 'video', 'mov' => 'video'];
    foreach ($files as $f) {
        if ($f['error'] !== UPLOAD_ERR_OK) $fail('The upload failed. Try a smaller file.');
        if ($f['size'] > MS_MAX_UPLOAD)    $fail('That file is too large (25 MB max).');
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!isset($allowed[$ext]))        $fail('Only photos and videos can be sent.');
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE); $mime = (string)finfo_file($fi, $f['tmp_name']); finfo_close($fi);
            if (strpos($mime, $allowed[$ext] . '/') !== 0) $fail('That file does not look like a real ' . $allowed[$ext] . '.');
        }
        $dir = 'uploads/messages/';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $target = $dir . bin2hex(random_bytes(12)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $target)) $fail('Could not save the file. Please try again.', 500);
        $saved[] = $target;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ms']) && in_array((string)$_GET['ms'], ['groups', 'older', 'gdl', 'ginfo', 'archive'], true)) {
    $ms = (string)$_GET['ms'];
    session_write_close();

    if ($ms === 'groups') {          // next page of the group list (infinite scroll)
        if (!$MS_GRP) jsonOut(['success' => true, 'more' => false, 'html' => '']);
        $off = max(0, (int)($_GET['offset'] ?? 0));
        $act_g = (int)($_GET['group'] ?? 0);
        $rows = ms_fetch_groups($conn, $current_user, MS_GROUP_PAGE + 1, $off);
        $more = count($rows) > MS_GROUP_PAGE;
        $rows = array_slice($rows, 0, MS_GROUP_PAGE);
        jsonOut(['success' => true, 'more' => $more, 'html' => implode('', array_map(function ($g) use ($current_user, $act_g) { return ms_render_group_conv($g, $current_user, $act_g); }, $rows))]);
    }

    if ($ms === 'archive') {          // the archived list (chats and groups)
        $viewer_show = dz_viewer_mask($conn, (int)$current_user);
        [$h, $n] = ms_render_archive($conn, $current_user, (int)($_GET['user'] ?? 0) ?: null, (int)($_GET['group'] ?? 0) ?: null, $viewer_show);
        jsonOut(['success' => true, 'html' => $h, 'count' => $n]);
    }

    if ($ms === 'older') {           // 30 older messages, for scrolling up in a chat
        $before = max(0, (int)($_GET['before'] ?? 0));
        $gid = (int)($_GET['group'] ?? 0); $uid = (int)($_GET['user'] ?? 0);
        if ($gid > 0) {
            if (!ms_group_is_member($conn, $gid, $current_user)) jsonOut(['success' => false, 'error' => 'You are not in this group.'], 403);
            $rows = ms_rows($conn, ms_group_msg_sql($conn) . " WHERE m.group_id = ? AND m.id < ? ORDER BY m.id DESC LIMIT " . (MS_PAGE_SIZE + 1), 'ii', [$gid, $before]);
        } elseif ($uid > 0 && $uid !== $current_user) {
            $rows = ms_rows($conn, ms_msg_sql($conn) . " WHERE ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?)) AND m.id < ? ORDER BY m.id DESC LIMIT " . (MS_PAGE_SIZE + 1), 'iiiii', [$current_user, $uid, $uid, $current_user, $before]);
        } else jsonOut(['success' => false, 'error' => 'Invalid chat'], 400);
        $more = count($rows) > MS_PAGE_SIZE;
        $rows = array_reverse(array_slice($rows, 0, MS_PAGE_SIZE));
        if ($gid > 0) $html = $rows ? ms_group_html($conn, $rows, $current_user, null, null) : '';
        else          $html = $rows ? ms_render_batch($rows, $current_user, ms_shared_memes($conn, $rows), null, null) : '';
        jsonOut(['success' => true, 'more' => $more, 'count' => count($rows), 'html' => $html]);
    }

    if ($ms === 'ginfo') {           // members of a group (for the Group info window)
        $gid = (int)($_GET['group'] ?? 0);
        $g = $MS_GRP ? ms_group_get($conn, $gid, $current_user) : null;
        if (!$g) jsonOut(['success' => false, 'error' => 'You are not in this group.'], 403);
        $mem = array_map(function ($m) use ($g) {
            return ['id' => (int)$m['id'], 'username' => $m['username'], 'nickname' => (string)($m['nickname'] ?? ''), 'pic' => $m['profile_pic'] ?: 'mee.jpg', 'verified' => !empty($m['is_verified']), 'owner' => (int)$m['id'] === (int)$g['owner_id']];
        }, msg_gc_members($conn, $gid));
        jsonOut(['success' => true, 'id' => $gid, 'name' => $g['name'], 'owner' => (int)$g['owner_id'], 'is_owner' => (int)$g['owner_id'] === $current_user, 'members' => $mem, 'max' => MS_GROUP_MAX]);
    }

    if ($ms === 'gdl') {             // download a photo/video from a group, only if the sender allows it
        $id = (int)($_GET['id'] ?? 0);
        $m = $MS_GRP ? ms_row($conn, "SELECT id, group_id, sender_id, file_attachment FROM msg_gc_messages WHERE id = ? LIMIT 1", 'i', [$id]) : null;
        while (ob_get_level() > 0) ob_end_clean();
        if ($m && !ms_group_is_member($conn, (int)$m['group_id'], $current_user)) $m = null;
        $path = $m ? (string)$m['file_attachment'] : '';
        if (!$m || $path === '' || strpos($path, 'uploads/messages/') !== 0 || strpos($path, '..') !== false || !is_file($path)) { http_response_code(404); exit('File not found.'); }
        if ((int)$m['sender_id'] !== $current_user && !ms_allows_download($conn, (int)$m['sender_id'])) { http_response_code(403); exit('The sender has turned off downloads for their photos and videos.'); }
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime'][$ext] ?? 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: attachment; filename="dezzme-' . preg_replace('/[^a-z0-9._-]/i', '', basename($path)) . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string)($_POST['ms_action'] ?? ''), ['typing', 'archive', 'group_create', 'group_add', 'group_leave', 'group_delete', 'group_rename', 'group_bg', 'gsend', 'gedit', 'gdelete', 'gmute', 'gpoll_create', 'gpoll_vote', 'gpoll_close'], true)) {
    $act = (string)$_POST['ms_action'];

    /* ── "is typing…" signal (one-to-one or group); ignored if nothing to show ── */
    if ($act === 'typing') {
        $kind = ((string)($_POST['kind'] ?? 'u')) === 'g' ? 'g' : 'u';
        $target = (int)($_POST['target'] ?? 0);
        $on = !empty($_POST['on']);
        if ($MS_GRP && $target > 0) {
            if ($kind === 'g') { if (ms_group_is_member($conn, $target, $current_user)) { $on ? ms_typing_set($conn, $current_user, 'g', $target) : ms_typing_clear($conn, $current_user, 'g', $target); } }
            elseif ($target !== $current_user && ms_can_message($conn, $current_user, $target)[0]) { $on ? ms_typing_set($conn, $current_user, 'u', $target) : ms_typing_clear($conn, $current_user, 'u', $target); }
        }
        jsonOut(['success' => true]);
    }

    /* ── ARCHIVE / UNARCHIVE chats (kind u) and groups (kind g) ── */
    if ($act === 'archive') {
        if (!$MS_ARC) jsonOut(['success' => false, 'error' => 'Archiving is not available right now.'], 503);
        $kind = ((string)($_POST['kind'] ?? 'u')) === 'g' ? 'g' : 'u';
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? ''))), function ($x) { return $x > 0; })));
        $on = !empty($_POST['on']);
        foreach ($ids as $id) {
            if ($kind === 'u' && $id === $current_user) continue;
            if ($kind === 'g' && !ms_group_is_member($conn, $id, $current_user)) continue;
            ms_q($conn, "DELETE FROM msg_chat_archive WHERE user_id = ? AND kind = ? AND target_id = ?", 'isi', [$current_user, $kind, $id]);
            if ($on) ms_q($conn, "INSERT INTO msg_chat_archive (user_id, kind, target_id) VALUES (?, ?, ?)", 'isi', [$current_user, $kind, $id]);
        }
        jsonOut(['success' => true, 'on' => $on]);
    }

    if (!$MS_GRP) jsonOut(['success' => false, 'error' => 'Group chats are not available right now.'], 503);

    /* ── CREATE A GROUP ── */
    if ($act === 'group_create') {
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_POST['members'] ?? ''))), function ($x) use ($current_user) { return $x > 0 && $x !== $current_user; })));
        if (count($ids) > MS_GROUP_MAX - 1) $ids = array_slice($ids, 0, MS_GROUP_MAX - 1);
        $users = [];
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            foreach (ms_rows($conn, "SELECT id, username FROM users WHERE id IN ($ph)", str_repeat('i', count($ids)), $ids) as $u) $users[(int)$u['id']] = $u['username'];
        }
        $ok_ids = []; $skipped = [];
        foreach ($ids as $id) {
            if (!isset($users[$id])) continue;
            [$okm] = ms_can_message($conn, $current_user, $id);
            if ($okm && ms_has_chat($conn, $current_user, $id)) $ok_ids[] = $id; else $skipped[] = $users[$id];
        }
        if (count($ok_ids) < MS_GROUP_MIN_NEW) {
            jsonOut(['success' => false, 'error' => 'Pick at least ' . MS_GROUP_MIN_NEW . ' people you have chats with who accept messages from you.' . ($skipped ? ' Not allowed: ' . implode(', ', $skipped) . '.' : ''), 'skipped' => $skipped], 400);
        }
        $name = ms_clean_group_name((string)($_POST['name'] ?? ''));
        if ($name === '') $name = ms_clean_group_name(implode(', ', array_map(function ($i) use ($users) { return $users[$i]; }, array_slice($ok_ids, 0, 3))));   /* no name given: the first three people's names (the owner can rename it any time) */
        $stmt = $conn->prepare("INSERT INTO msg_gc_groups (name, owner_id, created_at) VALUES (?, ?, NOW())");
        $stmt->bind_param('si', $name, $current_user);
        if (!$stmt->execute()) jsonOut(['success' => false, 'error' => 'Could not create the group.'], 500);
        $gid = (int)$stmt->insert_id;
        ms_q($conn, "INSERT INTO msg_gc_members (group_id, user_id, role, joined_at) VALUES (?, ?, 1, NOW())", 'ii', [$gid, $current_user]);
        foreach ($ok_ids as $id) ms_q($conn, "INSERT INTO msg_gc_members (group_id, user_id, role, joined_at) VALUES (?, ?, 0, NOW())", 'ii', [$gid, $id]);
        ms_group_sys($conn, $gid, $current_user, 'created the group');
        ms_group_mark_read($conn, $gid, $current_user);
        jsonOut(['success' => true, 'id' => $gid, 'skipped' => $skipped]);
    }

    $gid = (int)($_POST['group'] ?? 0);
    $g = ms_group_get($conn, $gid, $current_user);
    if (!$g) jsonOut(['success' => false, 'error' => 'You are not in this group.'], 403);
    $is_owner = (int)$g['owner_id'] === $current_user;

    /* ── ADD PEOPLE (only the person who created the group; each person's own "Allow messages" setting decides) ── */
    if ($act === 'group_add') {
        if (!$is_owner) jsonOut(['success' => false, 'error' => 'Only the person who created this group can add people.'], 403);
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_POST['members'] ?? ''))), function ($x) use ($current_user) { return $x > 0 && $x !== $current_user; })));
        if (!$ids) jsonOut(['success' => false, 'error' => 'Pick someone to add.'], 400);
        $cnt = count(msg_gc_members($conn, $gid));
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $users = [];
        foreach (ms_rows($conn, "SELECT id, username FROM users WHERE id IN ($ph)", str_repeat('i', count($ids)), $ids) as $u) $users[(int)$u['id']] = $u['username'];
        $added = []; $skipped = []; $full = false;
        foreach ($ids as $id) {
            if (!isset($users[$id]) || ms_group_is_member($conn, $gid, $id)) continue;
            if ($cnt >= MS_GROUP_MAX) { $full = true; $skipped[] = $users[$id]; continue; }
            [$okm] = ms_can_message($conn, $current_user, $id);
            if (!$okm || !ms_has_chat($conn, $current_user, $id)) { $skipped[] = $users[$id]; continue; }
            ms_q($conn, "INSERT INTO msg_gc_members (group_id, user_id, role, joined_at) VALUES (?, ?, 0, NOW())", 'ii', [$gid, $id]);
            // they only see messages from now on
            $mx = ms_row($conn, "SELECT MAX(id) AS m FROM msg_gc_messages WHERE group_id = ?", 'i', [$gid]);
            $added[] = $users[$id]; $cnt++;
        }
        if (!$added) jsonOut(['success' => false, 'error' => $full ? 'This group is full (' . MS_GROUP_MAX . ' people).' : ($skipped ? 'You can only add people you have chats with, who accept messages from you. Not added: ' . implode(', ', $skipped) . '.' : 'Nobody new to add.'), 'skipped' => $skipped], 400);
        ms_group_sys($conn, $gid, $current_user, 'added ' . implode(', ', $added));
        jsonOut(['success' => true, 'added' => $added, 'skipped' => $skipped]);
    }

    /* ── LEAVE ── */
    if ($act === 'group_leave') {
        $new_owner = (int)($_POST['new_owner'] ?? 0);
        ms_group_sys($conn, $gid, $current_user, 'left the group');
        ms_q($conn, "DELETE FROM msg_gc_members WHERE group_id = ? AND user_id = ?", 'ii', [$gid, $current_user]);
        ms_q($conn, "DELETE FROM msg_gc_reads WHERE group_id = ? AND user_id = ?", 'ii', [$gid, $current_user]);
        ms_q($conn, "DELETE FROM msg_gc_typing WHERE user_id = ? AND kind = 'g' AND target_id = ?", 'ii', [$current_user, $gid]);
        if ($GLOBALS['MS_FEAT']) ms_q($conn, "DELETE FROM ms_chat_mutes WHERE user_id = ? AND other_id = ?", 'ii', [$current_user, -$gid]);
        if ($MS_ARC) ms_q($conn, "DELETE FROM msg_chat_archive WHERE user_id = ? AND kind = 'g' AND target_id = ?", 'ii', [$current_user, $gid]);
        $left = msg_gc_members($conn, $gid);
        if (!$left) {   // last person out: the group and its files are removed
            foreach (ms_rows($conn, "SELECT file_attachment FROM msg_gc_messages WHERE group_id = ? AND file_attachment IS NOT NULL", 'i', [$gid]) as $r) ms_unlink_upload($r['file_attachment']);
            if (!empty($g['bg'])) ms_unlink_upload($g['bg']);
            ms_q($conn, "DELETE FROM msg_gc_messages WHERE group_id = ?", 'i', [$gid]);
            ms_q($conn, "DELETE FROM msg_gc_reads WHERE group_id = ?", 'i', [$gid]);
            ms_q($conn, "DELETE FROM msg_gc_groups WHERE id = ?", 'i', [$gid]);
        } elseif ($is_owner) {   // the person who has been here longest becomes the owner
            $nu = (int)$left[0]['id']; $best = null;
            foreach (ms_rows($conn, "SELECT user_id FROM msg_gc_members WHERE group_id = ? ORDER BY joined_at ASC, user_id ASC LIMIT 1", 'i', [$gid]) as $r) $best = (int)$r['user_id'];
            if ($best) $nu = $best;
            if ($new_owner > 0) foreach ($left as $lm) if ((int)$lm['id'] === $new_owner) $nu = $new_owner;   // the owner picked who takes over
            ms_q($conn, "UPDATE msg_gc_groups SET owner_id = ? WHERE id = ?", 'ii', [$nu, $gid]);
            ms_q($conn, "UPDATE msg_gc_members SET role = 1 WHERE group_id = ? AND user_id = ?", 'ii', [$gid, $nu]);
            ms_group_sys($conn, $gid, $nu, 'is now the group owner');
        }
        jsonOut(['success' => true]);
    }

    /* ── DELETE the whole group (owner only): messages, files, members and every trace go ── */
    if ($act === 'group_delete') {
        if (!$is_owner) jsonOut(['success' => false, 'error' => 'Only the person who created this group can delete it.'], 403);
        foreach (ms_rows($conn, "SELECT file_attachment FROM msg_gc_messages WHERE group_id = ? AND file_attachment IS NOT NULL", 'i', [$gid]) as $r) ms_unlink_upload($r['file_attachment']);
        if (!empty($g['bg'])) ms_unlink_upload($g['bg']);
        ms_q($conn, "DELETE FROM msg_gc_messages WHERE group_id = ?", 'i', [$gid]);
        ms_q($conn, "DELETE FROM msg_gc_reads WHERE group_id = ?", 'i', [$gid]);
        ms_q($conn, "DELETE FROM msg_gc_typing WHERE kind = 'g' AND target_id = ?", 'i', [$gid]);
        ms_q($conn, "DELETE FROM msg_gc_members WHERE group_id = ?", 'i', [$gid]);
        if ($GLOBALS['MS_FEAT']) ms_q($conn, "DELETE FROM ms_chat_mutes WHERE other_id = ?", 'i', [-$gid]);
        if ($MS_ARC) ms_q($conn, "DELETE FROM msg_chat_archive WHERE kind = 'g' AND target_id = ?", 'i', [$gid]);
        ms_q($conn, "DELETE FROM msg_gc_groups WHERE id = ?", 'i', [$gid]);
        jsonOut(['success' => true]);
    }

    if ($act === 'group_rename') {
        if (!$is_owner) jsonOut(['success' => false, 'error' => 'Only the person who created this group can rename it.'], 403);
        $name = ms_clean_group_name((string)($_POST['name'] ?? ''));
        if ($name === '') jsonOut(['success' => false, 'error' => 'Give the group a name.'], 400);
        ms_q($conn, "UPDATE msg_gc_groups SET name = ? WHERE id = ?", 'si', [$name, $gid]);
        ms_group_sys($conn, $gid, $current_user, 'renamed the group to “' . $name . '”');
        jsonOut(['success' => true, 'name' => $name]);
    }

    /* ── GROUP BACKGROUND: only the owner can set it; everybody in the group sees it ── */
    if ($act === 'group_bg') {
        global $MS_GBG;
        if (!$is_owner) jsonOut(['success' => false, 'error' => 'Only the person who created this group can change its background.'], 403);
        if (!$MS_GBG)   jsonOut(['success' => false, 'error' => 'Group backgrounds are not available right now.'], 503);
        $old = (string)($g['bg'] ?? '');
        if (!empty($_POST['remove'])) {
            ms_q($conn, "UPDATE msg_gc_groups SET bg = NULL, bg_type = NULL WHERE id = ?", 'i', [$gid]);
            if ($old !== '') ms_unlink_upload($old);
            ms_group_sys($conn, $gid, $current_user, 'removed the group background');
            jsonOut(['success' => true, 'bg' => '', 'type' => '']);
        }
        $saved = [];
        $fail = function (string $m, int $code = 400) use (&$saved) { foreach ($saved as $sp) ms_unlink_upload($sp); jsonOut(['success' => false, 'error' => $m], $code); };
        ms_collect_uploads($fail, $saved);
        if (count($saved) !== 1) $fail('Choose one image, GIF or video.');
        $ext = strtolower(pathinfo($saved[0], PATHINFO_EXTENSION));
        $type = in_array($ext, ['mp4', 'webm', 'mov'], true) ? 'video/' . ($ext === 'mov' ? 'quicktime' : $ext) : 'image/' . $ext;
        ms_q($conn, "UPDATE msg_gc_groups SET bg = ?, bg_type = ? WHERE id = ?", 'ssi', [$saved[0], $type, $gid]);
        if ($old !== '') ms_unlink_upload($old);
        ms_group_sys($conn, $gid, $current_user, 'changed the group background');
        jsonOut(['success' => true, 'bg' => $saved[0], 'type' => $type]);
    }

    if ($act === 'gmute') {
        if (!$GLOBALS['MS_FEAT']) jsonOut(['success' => false, 'error' => 'Muting is not available right now.']);
        $on = !empty($_POST['on']);
        ms_q($conn, "DELETE FROM ms_chat_mutes WHERE user_id = ? AND other_id = ?", 'ii', [$current_user, -$gid]);
        if ($on) ms_q($conn, "INSERT INTO ms_chat_mutes (user_id, other_id) VALUES (?, ?)", 'ii', [$current_user, -$gid]);
        jsonOut(['success' => true, 'muted' => $on]);
    }

    if ($act === 'gedit') {
        $edit_id = (int)($_POST['id'] ?? 0);
        $new_msg = trim((string)($_POST['new_message'] ?? ''));
        if (mb_strlen($new_msg) > MS_MAX_TEXT) $new_msg = mb_substr($new_msg, 0, MS_MAX_TEXT);
        if ($edit_id <= 0 || $new_msg === '') jsonOut(['success' => false, 'error' => 'Invalid input']);
        $row = ms_row($conn, "SELECT message, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age FROM msg_gc_messages WHERE id = ? AND group_id = ? AND sender_id = ? AND kind = 0 LIMIT 1", 'iii', [$edit_id, $gid, $current_user]);
        if (!$row) jsonOut(['success' => false, 'error' => 'Message not found'], 404);
        if (trim($row['message']) === '[deleted]') jsonOut(['success' => false, 'error' => 'This message cannot be edited']);
        if ((int)$row['age'] > MS_EDIT_WINDOW + 10) jsonOut(['success' => false, 'error' => 'The 2 minute edit window has passed.']);
        ms_q($conn, "UPDATE msg_gc_messages SET message = ? WHERE id = ? AND sender_id = ?", 'sii', [$new_msg, $edit_id, $current_user]);
        jsonOut(['success' => true]);
    }

    if ($act === 'gdelete') {   // unsend: your own messages become "Message deleted" for everyone
        $ids = array_values(array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? '')))));
        if (!$ids) jsonOut(['success' => false, 'error' => 'No IDs provided']);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        foreach (ms_rows($conn, "SELECT file_attachment FROM msg_gc_messages WHERE id IN ($ph) AND group_id = ? AND sender_id = ? AND kind = 0 AND file_attachment IS NOT NULL", str_repeat('i', count($ids)) . 'ii', array_merge($ids, [$gid, $current_user])) as $r) ms_unlink_upload($r['file_attachment']);
        ms_q($conn, "UPDATE msg_gc_messages SET message = '[deleted]', file_attachment = NULL WHERE id IN ($ph) AND group_id = ? AND sender_id = ? AND kind = 0", str_repeat('i', count($ids)) . 'ii', array_merge($ids, [$gid, $current_user]));
        jsonOut(['success' => true]);
    }


    /* ── POLLS ── */
    if ($act === 'gpoll_create') {
        if (!ms_poll_ok($conn)) jsonOut(['success' => false, 'error' => 'Polls are not available right now.'], 503);
        $q = ms_poll_clean((string)($_POST['question'] ?? ''), 200);
        $raw = $_POST['options'] ?? [];
        $labels = []; $seen_l = [];
        foreach ((is_array($raw) ? $raw : []) as $o) {
            $l = ms_poll_clean((string)$o, 100);
            if ($l === '' || isset($seen_l[mb_strtolower($l)])) continue;
            $seen_l[mb_strtolower($l)] = 1; $labels[] = $l;
        }
        if ($q === '')                      jsonOut(['success' => false, 'error' => 'Ask a question first.']);
        if (count($labels) < 2)             jsonOut(['success' => false, 'error' => 'Add at least two different options.']);
        if (count($labels) > MS_POLL_MAX_OPTS) $labels = array_slice($labels, 0, MS_POLL_MAX_OPTS);
        $multi = !empty($_POST['multi']) ? 1 : 0;
        if (ms_q($conn, "INSERT INTO msg_gc_messages (group_id, sender_id, message, kind, created_at) VALUES (?, ?, ?, 2, NOW())", 'iis', [$gid, $current_user, $q]) === null)
            jsonOut(['success' => false, 'error' => 'Could not create the poll. Please try again.'], 500);
        $mid = (int)$conn->insert_id;
        ms_q($conn, "INSERT INTO msg_gc_polls (message_id, group_id, creator_id, question, multi, closed, created_at) VALUES (?, ?, ?, ?, ?, 0, NOW())", 'iiisi', [$mid, $gid, $current_user, $q, $multi]);
        $pid = (int)$conn->insert_id;
        if ($pid <= 0) { ms_q($conn, "DELETE FROM msg_gc_messages WHERE id = ?", 'i', [$mid]); jsonOut(['success' => false, 'error' => 'Could not create the poll. Please try again.'], 500); }
        foreach ($labels as $i => $l) ms_q($conn, "INSERT INTO msg_gc_poll_options (poll_id, label, pos) VALUES (?, ?, ?)", 'isi', [$pid, $l, $i]);
        ms_touch($conn, $current_user);
        ms_typing_clear($conn, $current_user, 'g', $gid);
        if ($MS_ARC) ms_q($conn, "DELETE FROM msg_chat_archive WHERE kind = 'g' AND target_id = ? AND user_id <> ?", 'ii', [$gid, $current_user]);
        ms_group_mark_read($conn, $gid, $current_user);
        $rows = ms_rows($conn, ms_group_msg_sql($conn) . " WHERE m.id = ? ORDER BY m.id ASC", 'i', [$mid]);
        [$ps, $pd] = ms_group_prev($conn, $gid, $mid);
        jsonOut(['success' => true, 'id' => $mid, 'ids' => [$mid], 'html' => $rows ? ms_group_html($conn, $rows, $current_user, $ps, $pd) : '']);
    }
    if ($act === 'gpoll_vote' || $act === 'gpoll_close') {
        $pid = (int)($_POST['poll'] ?? 0);
        $pl = ms_poll_load($conn, [$pid], $current_user, 'id', $gid)[$pid] ?? null;
        if (!$pl) jsonOut(['success' => false, 'error' => 'That poll is not available.'], 404);
        if ($act === 'gpoll_close') {
            if (!$is_owner && (int)$pl['creator_id'] !== $current_user) jsonOut(['success' => false, 'error' => 'Only the person who made this poll can end it.'], 403);
            ms_q($conn, "UPDATE msg_gc_polls SET closed = 1 WHERE id = ?", 'i', [$pid]);
        } else {
            if ($pl['closed']) jsonOut(['success' => false, 'error' => 'This poll has ended.']);
            $want = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_POST['opts'] ?? ''))), function ($x) { return $x > 0; })));
            $want = array_values(array_filter($want, function ($x) use ($pl) { return isset($pl['options'][$x]); }));
            if (!$pl['multi']) $want = array_slice($want, 0, 1);
            if (!$want) jsonOut(['success' => false, 'error' => 'Pick an option first.']);
            ms_q($conn, "DELETE FROM msg_gc_poll_votes WHERE poll_id = ? AND user_id = ?", 'ii', [$pid, $current_user]);
            foreach ($want as $oid) ms_q($conn, "INSERT IGNORE INTO msg_gc_poll_votes (poll_id, option_id, user_id, created_at) VALUES (?, ?, ?, NOW())", 'iii', [$pid, $oid, $current_user]);
        }
        $pl = ms_poll_load($conn, [$pid], $current_user, 'id', $gid)[$pid] ?? null;
        jsonOut(['success' => true, 'html' => $pl ? ms_poll_card($pl, $current_user) : '']);
    }

    /* ── SEND TO A GROUP (text and/or up to 10 photos & videos) ── */
    if ($act === 'gsend') {
        $message = trim((string)($_POST['message'] ?? ''));
        if (mb_strlen($message) > MS_MAX_TEXT) $message = mb_substr($message, 0, MS_MAX_TEXT);
        $saved = [];
        $fail = function (string $m, int $code = 400) use (&$saved) { foreach ($saved as $sp) ms_unlink_upload($sp); jsonOut(['success' => false, 'error' => $m], $code); };
        ms_collect_uploads($fail, $saved);
        if ($message === '' && !$saved) $fail('Write a message first.');
        $reply_val = null;
        $reply_to = (int)($_POST['reply_to'] ?? 0);
        if ($reply_to > 0 && ms_row($conn, "SELECT id FROM msg_gc_messages WHERE id = ? AND group_id = ? AND kind = 0 LIMIT 1", 'ii', [$reply_to, $gid])) $reply_val = $reply_to;
        $ins = function (string $text, ?string $file, ?int $reply) use ($conn, $current_user, $gid): int {
            $stmt = $conn->prepare("INSERT INTO msg_gc_messages (group_id, sender_id, message, file_attachment, reply_to, kind, created_at) VALUES (?, ?, ?, ?, ?, 0, NOW())");
            if (!$stmt) return 0;
            $stmt->bind_param('iissi', $gid, $current_user, $text, $file, $reply);
            return $stmt->execute() ? (int)$stmt->insert_id : 0;
        };
        $new_ids = [];
        if (count($saved) === 1) { $id = $ins($message, $saved[0], $reply_val); if ($id) $new_ids[] = $id; }
        elseif ($saved) {
            foreach ($saved as $i => $sp) { $id = $ins('', $sp, $i === 0 ? $reply_val : null); if ($id) $new_ids[] = $id; }
            if ($message !== '' && $new_ids) { $id = $ins($message, null, null); if ($id) $new_ids[] = $id; }
        } else { $id = $ins($message, null, $reply_val); if ($id) $new_ids[] = $id; }
        if (!$new_ids) $fail('Could not send the message. Please try again.', 500);
        ms_touch($conn, $current_user);
        ms_typing_clear($conn, $current_user, 'g', $gid);
        if ($MS_ARC) ms_q($conn, "DELETE FROM msg_chat_archive WHERE kind = 'g' AND target_id = ? AND user_id <> ?", 'ii', [$gid, $current_user]);   // new activity brings an archived group back
        ms_group_mark_read($conn, $gid, $current_user);
        $first = min($new_ids);
        $ph = implode(',', array_fill(0, count($new_ids), '?'));
        $rows = ms_rows($conn, ms_group_msg_sql($conn) . " WHERE m.id IN ($ph) ORDER BY m.id ASC", str_repeat('i', count($new_ids)), $new_ids);
        [$ps, $pd] = ms_group_prev($conn, $gid, $first);
        jsonOut(['success' => true, 'id' => $first, 'ids' => $new_ids, 'html' => $rows ? ms_group_html($conn, $rows, $current_user, $ps, $pd) : '']);
    }
}

/* ══════════════════════════════════════════════════════════════
   MESSAGE QUERIES + RENDERING (one renderer for the first page
   load, for live updates and for freshly sent messages, so a
   message always looks the same however it arrives)
   ══════════════════════════════════════════════════════════════ */
function ms_msg_sql($conn): string {
    global $MS_FEAT;
    $rc  = ms_has_reply_col($conn);
    $sel = "m.*, u.username, u.profile_pic, " . ($MS_FEAT ? "COALESCE(up.allow_download, 1)" : "1") . " AS sender_dl" . ($rc
        ? ", rm.message AS reply_text, rm.sender_id AS reply_sender, ru.username AS reply_name"
        : ", NULL AS reply_text, NULL AS reply_sender, NULL AS reply_name");
    $join = "JOIN users u ON u.id = m.sender_id" . ($MS_FEAT ? " LEFT JOIN ms_user_prefs up ON up.user_id = m.sender_id" : "") . ($rc
        ? " LEFT JOIN messages rm ON rm.id = m.reply_to LEFT JOIN users ru ON ru.id = rm.sender_id"
        : "");
    return "SELECT $sel FROM messages m $join";
}

/* Posts shared inside the given message rows, keyed by meme id */
function ms_shared_memes($conn, array $rows): array {
    $ids = [];
    foreach ($rows as $row) {
        if (preg_match('/\[meme:(\d+)\]/', (string)($row['message'] ?? ''), $mm)) $ids[] = (int)$mm[1];
    }
    $map = [];
    if (!$ids) return $map;
    $ids = array_values(array_unique($ids));
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $res = ms_q($conn, "
        SELECT memes.id, memes.file_name, memes.type, memes.caption, memes.uploaded_by,
               users.username AS owner_username, users.profile_pic AS owner_pic
        FROM memes
        JOIN users ON users.id = memes.uploaded_by
        WHERE memes.id IN ($ph)", str_repeat('i', count($ids)), $ids);
    if ($res && $res !== true) while ($r = $res->fetch_assoc()) $map[(int)$r['id']] = $r;
    try {
        $cm = dz_collab_payload($conn, array_keys($map), (int)($GLOBALS['current_user'] ?? 0));
        foreach ($map as $k => $_r) $map[$k]['collab'] = $cm[$k] ?? null;
    } catch (Throwable $e) {}
    return $map;
}

function ms_linkify(string $escaped): string {
    return preg_replace_callback('~(https?://[^\s<]+)~i', function ($m) {
        $url = $m[1]; $tail = '';
        while ($url !== '' && preg_match('/[.,!?;:)\]]$/', $url)) { $tail = substr($url, -1) . $tail; $url = substr($url, 0, -1); }
        return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer nofollow">' . $url . '</a>' . $tail;
    }, $escaped);
}

/* Photo / video tiles, like Instagram: 1 big, 2 side by side, 3 with a tall one, 4 in a grid with "+N" on the last tile */
function ms_render_media(array $rows, int $me): string {
    $items = [];
    foreach ($rows as $r) {
        $path = (string)$r['file_attachment'];
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $items[] = ['s' => $path, 't' => in_array($ext, ['mp4', 'webm', 'mov'], true) ? 'v' : 'i', 'id' => (int)$r['id'],
                    'd' => ((int)$r['sender_id'] === $me || !isset($r['sender_dl']) || (int)$r['sender_dl'] === 1) ? 1 : 0];
    }
    $n = count($items); $shown = array_slice($items, 0, 4); $extra = $n - count($shown);
    ob_start(); ?>
<div class="mcol n<?= count($shown) ?>" data-media="<?= ms_h(json_encode($items)) ?>">
  <?php foreach ($shown as $i => $it): ?>
  <button type="button" class="mt" aria-label="<?= $it['t'] === 'v' ? 'Open video' : 'Open photo' ?>" onclick="event.stopPropagation();openViewer(this.closest('.mcol'), <?= $i ?>)">
    <?php if ($it['t'] === 'v'): ?>
      <video src="<?= ms_h($it['s']) ?>#t=0.1" muted playsinline preload="metadata"></video><span class="mt-play"><i class="ri-play-fill"></i></span>
    <?php else: ?>
      <img src="<?= ms_h($it['s']) ?>" alt="Photo" loading="lazy" onerror="this.closest('.mt').classList.add('broken')">
    <?php endif; ?>
    <?php if ($i === 3 && $extra > 0): ?><span class="mt-more">+<?= (int)$extra + 1 ?></span><?php endif; ?>
  </button>
  <?php endforeach; ?>
</div>
<?php
    return ob_get_clean();
}

function ms_render_row(array $msg, int $me, ?int $prev_sender, ?array $shared_meme, int $now, array $run = []): string {
    global $MS_GROUP_CTX;
    $in_group = !empty($MS_GROUP_CTX);
    if ($in_group && (int)($msg['kind'] ?? 0) === 1) {   // "Sam added Bob", "Sam left the group" …
        $who = ((int)$msg['sender_id'] === $me) ? 'You' : (string)($msg['username'] ?? '');
        return '<div class="msg-row sys" data-id="' . (int)$msg['id'] . '" data-ids="' . (int)$msg['id'] . '" data-sender="0" data-ts="' . (int)strtotime($msg['created_at']) . '"><div class="sys-text"><b>' . ms_h($who) . '</b> ' . ms_h((string)$msg['message']) . '</div></div>';
    }
    $is_sent     = (int)$msg['sender_id'] === $me;
    $text        = (string)($msg['message'] ?? '');
    $is_deleted  = trim($text) === '[deleted]';
    $is_meme_msg = (bool)preg_match('/\[meme:\d+\]/', $text);
    $msg_time    = strtotime($msg['created_at']);
    $can_edit    = $is_sent && !$is_deleted && !$is_meme_msg && ($now - $msg_time) <= MS_EDIT_WINDOW;
    $group_class = ($prev_sender === (int)$msg['sender_id']) ? '' : 'new-group';
    $seen        = !empty($msg['seen']);
    $emoji_only  = !$is_deleted && !$is_meme_msg && empty($msg['file_attachment']) && $text !== ''
                   && preg_match('/^(?:\p{Extended_Pictographic}[\x{FE0F}\x{200D}\x{1F3FB}-\x{1F3FF}]*\s*)+$/u', $text)
                   && preg_match_all('/\p{Extended_Pictographic}/u', $text) <= 3;
    $id = (int)$msg['id'];
    $media_rows = (!empty($msg['file_attachment']) && !$is_deleted) ? ($run ?: [$msg]) : [];
    $media_only = $media_rows && $text === '' && empty($msg['reply_text']);
    $all_ids    = $run ? array_map(function ($r) { return (int)$r['id']; }, $run) : [$id];
    if ($run) $seen = !empty(end($run)['seen']);
    $exp_ts = 0; $has_vanish = false;
    foreach (($run ?: [$msg]) as $_r) {
        if (!empty($_r['expires_at'])) { $t = strtotime($_r['expires_at']); if ($t && (!$exp_ts || $t < $exp_ts)) $exp_ts = $t; }
        if (!empty($_r['vanish'])) $has_vanish = true;
    }

    ob_start(); ?>
<div class="msg-row <?= $is_sent ? 'sent' : 'recv' ?> <?= $group_class ?>"
     data-id="<?= $id ?>"
     data-ids="<?= ms_h(implode(' ', $all_ids)) ?>"
     data-exp="<?= $exp_ts ?>"
     data-sender="<?= (int)$msg['sender_id'] ?>"
     data-text="<?= ms_h($text) ?>"
     data-time="<?= ms_h(date('M j, Y g:ia', $msg_time)) ?>"
     data-ts="<?= $msg_time ?>"
     data-seen="<?= $seen ? 1 : 0 ?>"
     data-username="<?= ms_h($msg['username'] ?? '') ?>"
     data-has-file="<?= !empty($msg['file_attachment']) ? 1 : 0 ?>"
     data-can-edit="<?= $can_edit ? '1' : '0' ?>">

  <div class="msg-select-check"><i class="ri-check-line"></i></div>
  <?php if ($in_group && !$is_sent && $group_class): ?><div class="g-sender"><?= ms_h($msg['username'] ?? '') ?></div><?php endif; ?>

  <div class="msg-wrap">
    <?php if ($is_sent): ?>
    <div class="msg-chevron" onclick="toggleDropdown(event, this)" title="Options"><i class="ri-arrow-down-s-line"></i></div>
    <?php else: ?>
    <img class="msg-avatar" src="<?= ms_h($msg['profile_pic'] ?? 'mee.jpg') ?>" alt="" loading="lazy" onerror="this.src='mee.jpg'">
    <?php endif; ?>

    <div class="bubble <?= $is_sent ? 'b-sent' : 'b-recv' ?> <?= $is_deleted ? 'deleted' : '' ?> <?= $shared_meme ? 'shared-meme-bubble' : '' ?> <?= $emoji_only ? 'emoji-only' : '' ?> <?= $media_only ? 'bubble-collage' : '' ?>"
         onclick="handleBubbleClick(event, <?= $id ?>, <?= $is_sent ? 'true' : 'false' ?>)"
         oncontextmenu="return msgLongPressOpen(event, this)">

      <?php if (!$is_deleted && !empty($msg['reply_text'])):
          $rt = trim((string)$msg['reply_text']);
          if ($rt === '[deleted]') $rt = 'Message deleted';
          elseif (preg_match('/^\[meme:\d+\]$/', $rt)) $rt = 'Shared a post';
          $rn = ((int)$msg['reply_sender'] === $me) ? 'You' : ($msg['reply_name'] ?? ''); ?>
        <div class="reply-preview" onclick="event.stopPropagation();jumpToMsg(<?= (int)$msg['reply_to'] ?>)">
          <strong><?= ms_h($rn) ?></strong><?= ms_h(mb_substr($rt, 0, 90)) ?>
        </div>
      <?php endif; ?>

      <?php if ($media_rows): ?><?= ms_render_media($media_rows, $me) ?><?php endif; ?>

      <?php if (!$is_deleted): ?>
        <?php if ($shared_meme):
            $sm_owner_pic  = !empty($shared_meme['owner_pic']) ? $shared_meme['owner_pic'] : 'mee.jpg';
            $sm_owner_name = ms_h($shared_meme['owner_username'] ?? 'user');
            $sm_type_info  = msgTypeTagInfo($shared_meme['type']);
        ?>
          <div class="shared-meme-card" onclick="event.stopPropagation();openSharedMemeModal(<?= (int)$shared_meme['id'] ?>, <?= (int)$msg['sender_id'] ?>, '<?= addslashes(htmlspecialchars($msg['username'] ?? '', ENT_QUOTES)) ?>', '<?= addslashes(htmlspecialchars($msg['profile_pic'] ?? 'mee.jpg', ENT_QUOTES)) ?>', '<?= addslashes($msg['created_at']) ?>')">
            <div class="shared-meme-media-wrap">
              <?php if ($shared_meme['type'] === 'video'): ?>
                <video src="uploads/<?= ms_h($shared_meme['file_name']) ?>#t=0.1" class="shared-meme-media" muted loop playsinline preload="metadata"></video>
                <div class="sm-play-icon"><i class="ri-play-fill"></i></div>
              <?php elseif ($shared_meme['type'] === 'image'): ?>
                <img src="uploads/<?= ms_h($shared_meme['file_name']) ?>" class="shared-meme-media" alt="" loading="lazy" onerror="this.src='mee.jpg'">
              <?php else: ?>
                <img src="uploads/Text.jpg" class="shared-meme-media" alt="">
              <?php endif; ?>
              <?php $sm_co = (!empty($shared_meme['collab']['list'])) ? $shared_meme['collab']['list'] : null; ?>
              <div class="sm-owner-pill">
                <?php if ($sm_co): $sm_n = min(2, count($sm_co)); ?>
                <span class="sm-cl n<?= $sm_n ?>">
                  <img src="<?= ms_h($sm_owner_pic) ?>" alt="" onerror="this.src='mee.jpg'">
                  <?php foreach (array_slice($sm_co, 0, 2) as $sm_c): ?><img class="co" src="<?= ms_h($sm_c['pic']) ?>" alt="" onerror="this.src='mee.jpg'"><?php endforeach; ?>
                </span>
                <span><?= $sm_owner_name ?> and <?= count($sm_co) === 1 ? ms_h($sm_co[0]['username']) : count($sm_co) . ' others' ?></span>
                <?php else: ?>
                <img src="<?= ms_h($sm_owner_pic) ?>" alt="" onerror="this.src='mee.jpg'">
                <span><?= $sm_owner_name ?></span>
                <?php endif; ?>
              </div>
              <div class="sm-type-tag"><i class="<?= $sm_type_info['icon'] ?>"></i><span><?= $sm_type_info['label'] ?></span></div>
            </div>
            <?php if (!empty($shared_meme['caption'])):
                $cap  = $shared_meme['caption'];
                $capd = (mb_strlen($cap) > 80) ? rtrim(mb_substr($cap, 0, 80)) . '…' : $cap; ?>
              <div class="shared-meme-caption"><?= nl2br(ms_h($capd)) ?></div>
            <?php endif; ?>
            <div class="shared-meme-tag"><i class="ri-external-link-line"></i> Tap to view</div>
          </div>
        <?php elseif ($is_meme_msg): ?>
          <div class="bubble-text"><i>This shared post is no longer available</i></div>
        <?php elseif ($text !== ''): ?>
          <div class="bubble-text"><?= nl2br(ms_linkify(ms_h($text))) ?></div>
        <?php endif; ?>
      <?php else: ?>
        <div class="bubble-text"><i class="ri-forbid-line"></i> Message deleted</div>
      <?php endif; ?>
    </div>

    <?php if (!$is_sent): ?>
    <div class="msg-chevron" onclick="toggleDropdown(event, this)" title="Options"><i class="ri-arrow-down-s-line"></i></div>
    <?php endif; ?>

    <div class="msg-dropdown" id="dd-<?= $id ?>">
      <div class="dd-react-row">
        <?php foreach (['❤️', '😂', '😮', '😢', '🔥', '👍', '💀', '🫡'] as $re): ?>
        <div class="dd-react-emoji" onclick="reactToMessage(<?= $id ?>, '<?= $re ?>')" title="<?= $re ?>"><?= $re ?></div>
        <?php endforeach; ?>
      </div>
      <div class="dd-item" onclick="ddAction('reply', <?= $id ?>)"><i class="ri-reply-line"></i> Reply</div>
      <?php if (!$is_meme_msg && $text !== ''): ?>
      <div class="dd-item" onclick="ddAction('copy', <?= $id ?>)"><i class="ri-file-copy-line"></i> Copy</div>
      <?php endif; ?>
      <?php if (!$is_deleted && ($text !== '' || $is_meme_msg)): ?>
      <div class="dd-item" onclick="ddAction('forward', <?= $id ?>)"><i class="ri-share-forward-line"></i> Forward</div>
      <?php endif; ?>
      <div class="dd-item" onclick="ddAction('pin', <?= $id ?>)"><i class="ri-pushpin-line"></i> Pin</div>
      <?php if (!$in_group): ?>
      <div class="dd-item" onclick="ddAction('info', <?= $id ?>)"><i class="ri-information-line"></i> Info</div>
      <div class="dd-item" onclick="ddAction('select', <?= $id ?>)"><i class="ri-checkbox-line"></i> Select</div>
      <?php endif; ?>
      <div class="dd-divider"></div>
      <?php if ($is_sent && !$is_deleted && !$is_meme_msg && $text !== ''): ?>
      <div class="dd-item <?= !$can_edit ? 'disabled' : '' ?>" id="edit-btn-<?= $id ?>" onclick="ddAction('edit', <?= $id ?>)">
        <i class="ri-edit-line"></i> Edit<?= !$can_edit ? ' <span class="edited-tag">(expired)</span>' : '' ?>
      </div>
      <?php endif; ?>
      <?php if (!$in_group): ?><div class="dd-item" onclick="ddAction('delete', <?= $id ?>)"><i class="ri-delete-bin-line"></i> Delete</div><?php endif; ?>
      <?php if ($is_sent && !$is_deleted): ?>
      <div class="dd-item danger" onclick="ddAction('unsend', <?= $id ?>)"><i class="ri-eraser-line"></i> Unsend</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="msg-reactions" id="reactions-<?= $id ?>"></div>

  <div class="msg-meta">
    <span><?= ms_h(date('g:ia', $msg_time)) ?></span>
    <?php if ($exp_ts || $has_vanish): ?><i class="ri-timer-line vanish-ico" title="<?= $exp_ts ? 'Disappears ' . ms_h(date('M j, g:ia', $exp_ts)) : 'Disappears after it is seen' ?>"></i><?php endif; ?>
    <?php if ($is_sent && !$in_group): ?><i class="seen-tick <?= $seen ? 'ri-check-double-line seen' : 'ri-check-line' ?>"></i><?php endif; ?>
  </div>
</div>
<?php
    return ob_get_clean();
}

function ms_media_only(array $m): bool {
    return !empty($m['file_attachment']) && trim((string)($m['message'] ?? '')) === '' && empty($m['reply_text']) && trim((string)($m['message'] ?? '')) !== '[deleted]';
}
function ms_render_batch(array $rows, int $me, array $shared_map, ?int $prev_sender, ?string $prev_date): string {
    $html = ''; $now = time(); $n = count($rows);
    $poll_map = [];
    if (!empty($GLOBALS['MS_GROUP_CTX'])) {
        $pm = [];
        foreach ($rows as $r0) if ((int)($r0['kind'] ?? 0) === 2) $pm[] = (int)$r0['id'];
        if ($pm) { global $conn; foreach (ms_poll_load($conn, $pm, $me, 'message_id') as $pl) $poll_map[$pl['message_id']] = $pl; }
    }
    for ($i = 0; $i < $n; $i++) {
        $msg = $rows[$i];
        $label = dateLabel($msg['created_at']);
        if ($label !== $prev_date) {
            $html .= '<div class="date-divider"><span>' . ms_h($label) . '</span></div>';
            $prev_date = $label;
        }
        $run = [];
        if ((int)($msg['kind'] ?? 0) === 2 && !empty($GLOBALS['MS_GROUP_CTX'])) {   // a poll
            $html .= ms_poll_row_html($msg, $me, $poll_map[(int)$msg['id']] ?? null);
            $prev_sender = null;
            continue;
        }
        if ((int)($msg['kind'] ?? 0) === 1) {
            $html .= ms_render_row($msg, $me, null, null, $now);
            $prev_sender = null;
            continue;
        }
        if (ms_media_only($msg)) {   // photos sent together (within a few seconds) become one collage
            $run = [$msg];
            while ($i + 1 < $n && count($run) < 10) {
                $nx = $rows[$i + 1]; $lastr = end($run);
                if ((int)$nx['sender_id'] !== (int)$msg['sender_id'] || !ms_media_only($nx)) break;
                if (strtotime($nx['created_at']) - strtotime($lastr['created_at']) > 8) break;
                $run[] = $nx; $i++;
            }
            if (count($run) === 1) $run = [];
        }
        $shared = null;
        if (preg_match('/\[meme:(\d+)\]/', (string)($msg['message'] ?? ''), $mm)) $shared = $shared_map[(int)$mm[1]] ?? null;
        $html .= ms_render_row($msg, $me, $prev_sender, $shared, $now, $run);
        $prev_sender = (int)$msg['sender_id'];
    }
    return $html;
}

/* ══════════════════════════════════════════════════════════════
   CONVERSATION LIST
   $requests: false = main inbox · true = message requests
              (people you restricted) · null = both
   ══════════════════════════════════════════════════════════════ */
function ms_fetch_convs($conn, int $me, ?bool $requests, ?int $limit = null, int $offset = 0, bool $archived = false): array {
    global $MS_ARC;
    $ac    = ms_active_col($conn);
    $act   = ms_active_expr($ac, 'u');
    $where = "u.id <> ?";
    $types = 'iiiiii'; $params = [$me, $me, $me, $me, $me];
    if ($requests === false)     { $where .= " AND u.id NOT IN (SELECT restricted_id FROM restricted_users WHERE restrictor_id = ?)"; $params[] = $me; }
    elseif ($requests === true)  { $where .= " AND u.id IN (SELECT restricted_id FROM restricted_users WHERE restrictor_id = ?)";     $params[] = $me; }
    else                         { $types = 'iiiii'; }
    if ($MS_ARC) { if ($archived) $where .= " AND u.id IN " . ms_arc_sub($me, 'u'); elseif ($requests === false) $where .= " AND u.id NOT IN " . ms_arc_sub($me, 'u'); }
    $sql = "
        SELECT u.id, u.username, u.nickname, u.profile_pic, COALESCE(u.is_verified, 0) AS is_verified, (LOWER(COALESCE(u.user_rank,'')) = 'suspended') AS is_susp,
               lm.message AS last_msg, lm.file_attachment AS last_file, lm.sender_id AS last_sender, lm.created_at AS last_time,
               (SELECT COUNT(*) FROM messages WHERE sender_id = u.id AND receiver_id = ? AND seen = 0) AS unread,
               " . ($GLOBALS['MS_FEAT'] ? "(SELECT COUNT(*) FROM ms_chat_mutes WHERE user_id = " . (int)$me . " AND other_id = u.id)" : "0") . " AS muted,
               $act AS active_ago,
               " . dz_vis_mask_sql('us', 'u', (int)$me) . " AS p_show_online
        FROM (SELECT IF(sender_id = ?, receiver_id, sender_id) AS partner, MAX(id) AS last_id
              FROM messages WHERE sender_id = ? OR receiver_id = ? GROUP BY partner) lp
        JOIN users u ON u.id = lp.partner
        JOIN messages lm ON lm.id = lp.last_id
        LEFT JOIN user_settings us ON us.user_id = u.id
        WHERE $where
        ORDER BY lm.created_at DESC, lm.id DESC" . ($limit !== null ? " LIMIT " . (int)$limit . " OFFSET " . max(0, (int)$offset) : '');
    // placeholder order in the SQL: unread(me), IF(me), sender(me), receiver(me), u.id<>me, [restrictor(me)]
    return ms_rows($conn, $sql, $types, $params);
}

function ms_verified_svg(): string {
    return '<svg class="vbadge" width="14" height="14" viewBox="0 0 256 256" aria-label="Verified"><use href="#dz-flower-f"/></svg>';
}

function ms_render_conv(array $c, int $me, ?int $chat_user, bool $is_req, int $viewer_show): string {
    $pres = ms_presence(isset($c['active_ago']) && $c['active_ago'] !== null ? (float)$c['active_ago'] : null, (int)$c['p_show_online'], $viewer_show);
    [$prev, $italic] = ms_preview($c, $me);
    $unread = (int)$c['unread'];
    $search = strtolower($c['username'] . ' ' . ($c['nickname'] ?? ''));
    ob_start(); ?>
<div class="conv-item <?= $is_req ? 'request-item' : '' ?> <?= ($chat_user && $chat_user == $c['id']) ? 'active' : '' ?>"
     data-uid="<?= (int)$c['id'] ?>" data-search="<?= ms_h($search) ?>" onclick="convClick(event, <?= (int)$c['id'] ?>)">
  <div class="conv-checkbox"><div class="box"><i class="ri-check-line"></i></div></div>
  <div class="conv-avatar">
    <?php if (!empty($c['is_susp'])): ?><span class="susp-av"></span><?php else: ?>
    <img src="<?= ms_h($c['profile_pic'] ?? 'mee.jpg') ?>" alt="" loading="lazy" onerror="this.src='mee.jpg'">
    <?php endif; ?>
    <?php if ($pres['active'] && empty($c['is_susp'])): ?><span class="online-dot" title="Active now"></span><?php endif; ?>
    <?php if ($is_req): ?><span class="conv-avatar-lock"><i class="ri-lock-line"></i></span><?php endif; ?>
  </div>
  <div class="conv-body">
    <div class="conv-name"><span class="conv-name-t"><?= ms_h($c['username']) ?></span><?= !empty($c['is_verified']) ? ms_verified_svg() : '' ?><?= !empty($c['muted']) ? '<i class="ri-notification-off-fill conv-mute" title="Muted"></i>' : '' ?><?= $is_req ? '<span class="request-tag">Request</span>' : '' ?></div>
    <?php if (!empty($c['is_susp'])): ?><div class="conv-last susp-txt">Suspended</div><?php else: ?>
    <div class="conv-last <?= $unread > 0 ? 'unread' : '' ?>"><?= $italic ? '<i>' . ms_h($prev) . '</i>' : ms_h($prev) ?></div>
    <?php endif; ?>
  </div>
  <div class="conv-meta">
    <span class="conv-time"><?= ms_h(ms_short_time($c['last_time'])) ?></span>
    <?php if ($unread > 0): ?><span class="conv-badge<?= !empty($c['muted']) ? ' muted' : '' ?>" title="<?= $unread ?> unread"></span><?php endif; ?>
  </div>
</div>
<?php
    return ob_get_clean();
}

/* ══════════════════════════════════════════════════════════════
   SEARCH THAT LOOKS INSIDE CONVERSATIONS
   A conversation matches when the person's username/nickname matches,
   OR when any message in it (or the caption of a post shared in it)
   contains the search text.
   ══════════════════════════════════════════════════════════════ */
function ms_search_convs($conn, int $me, string $q): array {
    $q = trim($q);
    if ($q === '') return [];
    $convs = ms_fetch_convs($conn, $me, null);
    $like  = ms_like($q);

    $hits = [];   // partner id => latest matching message id
    if (mb_strlen($q) >= 2) {
        $rows = ms_rows($conn, "
            SELECT IF(m.sender_id = ?, m.receiver_id, m.sender_id) AS partner, MAX(m.id) AS hit_id
            FROM messages m
            WHERE (m.sender_id = ? OR m.receiver_id = ?)
              AND m.message <> '[deleted]'
              AND (m.message LIKE ? ESCAPE '|'
                   OR EXISTS (SELECT 1 FROM memes mm WHERE m.message = CONCAT('[meme:', mm.id, ']') AND mm.caption LIKE ? ESCAPE '|'))
            GROUP BY partner", 'iiiss', [$me, $me, $me, $like, $like]);
        foreach ($rows as $r) $hits[(int)$r['partner']] = (int)$r['hit_id'];
    }

    $hit_msgs = [];
    if ($hits) {
        $ids = array_values($hits);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        foreach (ms_rows($conn, "SELECT id, message, created_at FROM messages WHERE id IN ($ph)", str_repeat('i', count($ids)), $ids) as $r) $hit_msgs[(int)$r['id']] = $r;
    }
    // captions of shared posts that matched
    $captions = [];
    $meme_ids = [];
    foreach ($hit_msgs as $r) if (preg_match('/^\[meme:(\d+)\]$/', trim($r['message']), $mm)) $meme_ids[(int)$r['id']] = (int)$mm[1];
    if ($meme_ids) {
        $ph = implode(',', array_fill(0, count($meme_ids), '?'));
        foreach (ms_rows($conn, "SELECT id, caption FROM memes WHERE id IN ($ph)", str_repeat('i', count($meme_ids)), array_values($meme_ids)) as $r) $captions[(int)$r['id']] = (string)$r['caption'];
    }

    $snip = function (string $text, string $q): string {
        $text = trim(preg_replace('/\s+/', ' ', $text));
        $pos  = mb_stripos($text, $q);
        if ($pos === false) return mb_substr($text, 0, 80);
        $start = max(0, $pos - 24);
        $out = mb_substr($text, $start, 90);
        return ($start > 0 ? '…' : '') . $out . (mb_strlen($text) > $start + 90 ? '…' : '');
    };

    $out = [];
    foreach ($convs as $c) {
        $uid = (int)$c['id'];
        $name_hit = mb_stripos($c['username'], $q) !== false || mb_stripos((string)($c['nickname'] ?? ''), $q) !== false;
        $hit_id   = $hits[$uid] ?? null;
        if (!$name_hit && !$hit_id) continue;
        $snippet = ''; $hit_time = '';
        if ($hit_id && isset($hit_msgs[$hit_id])) {
            $hm = $hit_msgs[$hit_id];
            if (isset($meme_ids[$hit_id])) $snippet = 'Shared post: ' . $snip($captions[$meme_ids[$hit_id]] ?? '', $q);
            else                           $snippet = $snip((string)$hm['message'], $q);
            $hit_time = ms_short_time($hm['created_at']);
        }
        [$prev, $italic] = ms_preview($c, $me);
        $out[] = [
            'id' => $uid, 'username' => $c['username'], 'nickname' => (string)($c['nickname'] ?? ''), 'pic' => $c['profile_pic'] ?: 'mee.jpg',
            'verified' => !empty($c['is_verified']), 'name_hit' => $name_hit, 'hit_id' => $hit_id, 'snippet' => $snippet, 'hit_time' => $hit_time,
            'last' => $prev, 'time' => ms_short_time($c['last_time']),
            'request' => (bool)ms_row($conn, "SELECT 1 AS x FROM restricted_users WHERE restrictor_id = ? AND restricted_id = ? LIMIT 1", 'ii', [$me, $uid]),
        ];
        if (count($out) >= 40) break;
    }
    // message hits first (they are what the person is hunting for), then name-only matches
    usort($out, function ($a, $b) { return ((int)!empty($b['hit_id'])) <=> ((int)!empty($a['hit_id'])); });
    return $out;
}

/* ══════════════════════════════════════════════════════════════
   LIVE ENDPOINTS (read-only GET): poll, search, people
   ══════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ms'])) {
    $ms = (string)$_GET['ms'];

    if ($ms === 'poll') {
        ms_touch($conn, $current_user);
        ms_purge($conn, $current_user, false);
        $viewer_show = dz_viewer_mask($conn, (int)$current_user);
        $have_csv = (string)($_GET['have'] ?? '');
        session_write_close();

        $out  = ['success' => true];
        $chat = (int)($_GET['user'] ?? 0);
        if ($chat > 0 && $chat !== $current_user) {
            $after = max(0, (int)($_GET['after'] ?? 0));
            if (!empty($_GET['vis'])) {
                ms_q($conn, "UPDATE messages SET seen = 1 WHERE sender_id = ? AND receiver_id = ? AND seen = 0", 'ii', [$chat, $current_user]);
            }
            $rows = ms_rows($conn, ms_msg_sql($conn) . " WHERE ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?)) AND m.id > ? ORDER BY m.id ASC LIMIT 100",
                'iiiii', [$current_user, $chat, $chat, $current_user, $after]);
            if ($rows) {
                $prev = ms_row($conn, "SELECT sender_id, created_at FROM messages WHERE ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)) AND id <= ? ORDER BY id DESC LIMIT 1",
                    'iiiii', [$current_user, $chat, $chat, $current_user, $after]);
                $out['html']    = ms_render_batch($rows, $current_user, ms_shared_memes($conn, $rows), $prev ? (int)$prev['sender_id'] : null, $prev ? dateLabel($prev['created_at']) : null);
                $out['last_id'] = (int)end($rows)['id'];
                $out['has_incoming'] = (bool)array_filter($rows, function ($r) use ($current_user) { return (int)$r['sender_id'] !== $current_user; });
            }
            $sm = ms_row($conn, "SELECT MAX(id) AS mx FROM messages WHERE sender_id = ? AND receiver_id = ? AND seen = 1", 'ii', [$current_user, $chat]);
            $out['seen_upto'] = (int)($sm['mx'] ?? 0);
            $out['typing']    = ms_typing_get($conn, $current_user, 'u', $chat);
            $out['disappear'] = ms_disappear_mode($conn, $current_user, $chat);
            $out['muted']     = ms_is_muted($conn, $current_user, $chat);
            $ac = ms_active_col($conn);
            $pr = ms_row($conn, "SELECT " . ms_active_expr($ac, 'u') . " AS active_ago, " . dz_vis_mask_sql('us', 'u', (int)$current_user) . " AS p_show FROM users u LEFT JOIN user_settings us ON us.user_id = u.id WHERE u.id = ? LIMIT 1", 'i', [$chat]);
            if ($pr) $out['presence'] = ms_presence($pr['active_ago'] !== null ? (float)$pr['active_ago'] : null, (int)$pr['p_show'], $viewer_show);
        }

        $gchat = (int)($_GET['group'] ?? 0);
        if ($gchat > 0 && $MS_GRP) {
            if (!ms_group_is_member($conn, $gchat, $current_user)) { $out['left'] = true; }
            else {
                $after = max(0, (int)($_GET['after'] ?? 0));
                if (!empty($_GET['vis'])) ms_group_mark_read($conn, $gchat, $current_user);
                $rows = ms_rows($conn, ms_group_msg_sql($conn) . " WHERE m.group_id = ? AND m.id > ? ORDER BY m.id ASC LIMIT 100", 'ii', [$gchat, $after]);
                if ($rows) {
                    [$ps, $pd] = ms_group_prev($conn, $gchat, (int)$rows[0]['id']);
                    $out['html']    = ms_group_html($conn, $rows, $current_user, $ps, $pd);
                    $out['last_id'] = (int)end($rows)['id'];
                    $out['has_incoming'] = (bool)array_filter($rows, function ($r) use ($current_user) { return (int)$r['sender_id'] !== $current_user && (int)$r['kind'] !== 1; });
                }
                $pl_ids = array_slice(array_values(array_filter(array_map('intval', explode(',', (string)($_GET['polls'] ?? ''))))), 0, 30);
                if ($pl_ids) { $out['polls'] = []; foreach (ms_poll_load($conn, $pl_ids, $current_user, 'id', $gchat) as $pid2 => $pl2) $out['polls'][$pid2] = ms_poll_card($pl2, $current_user); }
                $out['typing'] = ms_typing_get($conn, $current_user, 'g', $gchat);
                $out['muted']  = ms_is_muted($conn, $current_user, -$gchat);
                $gm = ms_row($conn, "SELECT g.name, (SELECT COUNT(*) FROM msg_gc_members WHERE group_id = g.id) AS n FROM msg_gc_groups g WHERE g.id = ? LIMIT 1", 'i', [$gchat]);
                if ($gm) { $out['gname'] = $gm['name']; $out['gcount'] = (int)$gm['n']; }
                $gg = ms_group_get($conn, $gchat, $current_user);
                if ($gg) { $out['gbg'] = (string)($gg['bg'] ?? ''); $out['gbgt'] = (string)($gg['bg_type'] ?? ''); }
            }
        }

        $lim  = max(MS_CONV_PAGE, min(300, (int)($_GET['lim'] ?? MS_CONV_PAGE)));   // how many chats the page has loaded so far
        $main = ms_fetch_convs($conn, $current_user, false, $lim, 0);
        $reqs = ms_fetch_convs($conn, $current_user, true);
        $have = array_filter(array_map('intval', explode(',', $have_csv)));
        sort($have);
        $now_ids = array_map(function ($c) { return (int)$c['id']; }, array_merge($main, $reqs));
        sort($now_ids);
        $items = []; $unread_total = ms_unread_total($conn, $current_user);
        foreach (array_merge($main, $reqs) as $c) {
            $p = ms_presence($c['active_ago'] !== null ? (float)$c['active_ago'] : null, (int)$c['p_show_online'], $viewer_show);
            [$prev_t, $it] = ms_preview($c, $current_user);
            $items[] = ['id' => (int)$c['id'], 'unread' => (int)$c['unread'], 'last' => $prev_t, 'italic' => $it, 'time' => ms_short_time($c['last_time']), 'active' => $p['active'], 'muted' => !empty($c['muted'])];
        }
        if ($MS_GRP) {
            $glim = max(MS_GROUP_PAGE, min(200, (int)($_GET['glim'] ?? MS_GROUP_PAGE)));
            $gs   = ms_fetch_groups($conn, $current_user, $glim, 0);
            $ghave = array_filter(array_map('intval', explode(',', (string)($_GET['ghave'] ?? '')))); sort($ghave);
            $gnow  = array_map(function ($g) { return (int)$g['id']; }, $gs); sort($gnow);
            $gitems = [];
            foreach ($gs as $g) { [$gp, $gi] = ms_group_preview($g, $current_user); $gitems[] = ['id' => (int)$g['id'], 'unread' => (int)$g['unread'], 'last' => $gp, 'italic' => $gi, 'time' => ms_short_time($g['last_time'] ?? null), 'muted' => !empty($g['muted']), 'fresh' => !empty($g['fresh'])]; }
            $out['groups'] = $gitems;
            if ($ghave !== $gnow) $out['groups_html'] = implode('', array_map(function ($g) use ($current_user, $gchat) { return ms_render_group_conv($g, $current_user, $gchat); }, $gs));
            $unread_total += ms_group_unread_total($conn, $current_user);
            $out['gdot'] = ms_group_attention($conn, $current_user);
        }
        if ($MS_ARC) {
            $an = ms_row($conn, "SELECT COUNT(*) AS n FROM msg_chat_archive WHERE user_id = ?", 'i', [$current_user]);
            $out['arch_n'] = ($an && (int)$an['n'] > 0) ? count(ms_fetch_convs($conn, $current_user, null, null, 0, true)) + count(ms_fetch_groups($conn, $current_user, null, 0, true)) : 0;
        }
        $out['inbox']  = $items;
        $out['unread'] = $unread_total;
        if ($have !== $now_ids) {
            $out['convs_html'] = implode('', array_map(function ($c) use ($current_user, $chat, $viewer_show) { return ms_render_conv($c, $current_user, $chat ?: null, false, $viewer_show); }, $main));
            $out['reqs_html']  = implode('', array_map(function ($c) use ($current_user, $chat, $viewer_show) { return ms_render_conv($c, $current_user, $chat ?: null, true, $viewer_show); }, $reqs));
            $out['req_count']  = count($reqs);
        }
        jsonOut($out);
    }

    if ($ms === 'dl') {   // download a photo/video, only if the sender allows it
        $id = (int)($_GET['id'] ?? 0);
        $m = ms_row($conn, "SELECT id, sender_id, receiver_id, file_attachment FROM messages WHERE id = ? AND (sender_id = ? OR receiver_id = ?) LIMIT 1", 'iii', [$id, $current_user, $current_user]);
        session_write_close();
        while (ob_get_level() > 0) ob_end_clean();
        $path = $m ? (string)$m['file_attachment'] : '';
        if (!$m || $path === '' || strpos($path, 'uploads/messages/') !== 0 || strpos($path, '..') !== false || !is_file($path)) { http_response_code(404); exit('File not found.'); }
        if ((int)$m['sender_id'] !== $current_user && !ms_allows_download($conn, (int)$m['sender_id'])) { http_response_code(403); exit('The sender has turned off downloads for their photos and videos.'); }
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime'][$ext] ?? 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: attachment; filename="dezzme-' . preg_replace('/[^a-z0-9._-]/i', '', basename($path)) . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    if ($ms === 'convs') {   // next page of the chat list (infinite scroll)
        $viewer_show = dz_viewer_mask($conn, (int)$current_user);
        $off  = max(0, (int)($_GET['offset'] ?? 0));
        $chat = (int)($_GET['user'] ?? 0);
        session_write_close();
        $rows = ms_fetch_convs($conn, $current_user, false, MS_CONV_PAGE + 1, $off);
        $more = count($rows) > MS_CONV_PAGE;
        $rows = array_slice($rows, 0, MS_CONV_PAGE);
        jsonOut(['success' => true, 'more' => $more, 'count' => count($rows),
                 'html' => implode('', array_map(function ($c) use ($current_user, $chat, $viewer_show) { return ms_render_conv($c, $current_user, $chat ?: null, false, $viewer_show); }, $rows))]);
    }

    if ($ms === 'search') {
        session_write_close();
        jsonOut(['success' => true, 'q' => (string)($_GET['q'] ?? ''), 'results' => ms_search_convs($conn, $current_user, (string)($_GET['q'] ?? ''))]);
    }

    if ($ms === 'people') {   // "New message" picker: people you track, or anyone you search for, who you are allowed to message
        session_write_close();
        $q = trim((string)($_GET['q'] ?? ''));
        $params = [$current_user, $current_user]; $types = 'ii';
        $chats_only = !empty($_GET['chats']);   // adding people to a group: only people you already have a chat with
        $cond = $chats_only ? "(u.id IN (SELECT receiver_id FROM messages WHERE sender_id = ? UNION SELECT sender_id FROM messages WHERE receiver_id = ?))" : "f.id IS NOT NULL";
        if ($chats_only) { $types .= 'ii'; array_push($params, $current_user, $current_user); }
        if ($q !== '') {
            $like = ms_like($q);
            $nm = "(u.username LIKE ? ESCAPE '|' OR COALESCE(u.nickname,'') LIKE ? ESCAPE '|')";
            $cond = $chats_only ? "$cond AND $nm" : $nm;
            $types .= 'ss'; array_push($params, $like, $like);
        }
        $types .= 'ii'; array_push($params, $current_user, $current_user);
        $in_g = [];
        $pg = (int)($_GET['group'] ?? 0);
        if ($pg > 0 && $MS_GRP && ms_group_is_member($conn, $pg, $current_user)) foreach (msg_gc_members($conn, $pg) as $gm) $in_g[(int)$gm['id']] = true;
        $rows = ms_rows($conn, "
            SELECT u.id, u.username, u.nickname, u.profile_pic, COALESCE(u.is_verified,0) AS is_verified, (f.id IS NOT NULL) AS tracked
            FROM users u LEFT JOIN followers f ON f.follower_id = ? AND f.following_id = u.id
            WHERE u.id <> ? AND $cond
              AND u.id NOT IN (SELECT blocked_id FROM blocks WHERE blocker_id = ?)
              AND u.id NOT IN (SELECT blocker_id FROM blocks WHERE blocked_id = ?)
            ORDER BY tracked DESC, u.username ASC LIMIT 60", $types, $params);
        $out = [];
        foreach ($rows as $r) {
            if (isset($in_g[(int)$r['id']])) continue;
            [$okm] = ms_can_message($conn, $current_user, (int)$r['id']);
            if (!$okm) continue;
            $out[] = ['id' => (int)$r['id'], 'username' => $r['username'], 'nickname' => (string)($r['nickname'] ?? ''), 'pic' => $r['profile_pic'] ?: 'mee.jpg', 'verified' => !empty($r['is_verified']), 'tracked' => (bool)$r['tracked']];
            if (count($out) >= 20) break;
        }
        jsonOut(['success' => true, 'q' => $q, 'results' => $out]);
    }
}

/* ══════════════════════════════════════════════════════════════
   ACTIONS (POST, CSRF already verified at the top of the file)
   ══════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ms_action'])) {
    $act = (string)$_POST['ms_action'];

    /* ── SEND (text and/or up to 10 photos & videos) ── */
    if ($act === 'send') {
        $receiver  = (int)($_POST['receiver'] ?? 0);
        $message   = trim((string)($_POST['message'] ?? ''));
        if (mb_strlen($message) > MS_MAX_TEXT) $message = mb_substr($message, 0, MS_MAX_TEXT);
        $ajax      = !empty($_POST['ajax']);
        $saved     = [];

        $fail = function (string $m, int $code = 400) use ($ajax, $receiver, &$saved) {
            foreach ($saved as $sp) ms_unlink_upload($sp);
            if ($ajax) jsonOut(['success' => false, 'error' => $m], $code);
            header("Location: messages.php" . ($receiver ? "?user=$receiver" : ''));
            exit;
        };

        /* "Allow messages", privacy level and blocks are enforced here, whatever URL or form sent the request */
        [$may, $why] = ms_can_message($conn, $current_user, $receiver);
        if (!$may) {
            $u = ms_row($conn, "SELECT username, nickname FROM users WHERE id = ? LIMIT 1", 'i', [$receiver]);
            $fail(ms_block_text($why, $u ? ($u['nickname'] ?: $u['username']) : 'This person'), 403);
        }

        /* uploads: accepts attachment (one) or attachment[] (many) */
        $files = [];
        if (isset($_FILES['attachment'])) {
            $fa = $_FILES['attachment'];
            if (is_array($fa['name'])) {
                foreach ($fa['name'] as $i => $nm) $files[] = ['name' => $nm, 'tmp_name' => $fa['tmp_name'][$i], 'size' => $fa['size'][$i], 'error' => $fa['error'][$i]];
            } else $files[] = $fa;
            $files = array_values(array_filter($files, function ($f) { return $f['error'] !== UPLOAD_ERR_NO_FILE; }));
        }
        if (count($files) > 10) $fail('You can send up to 10 photos or videos at once.');
        $allowed = ['jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'gif' => 'image', 'webp' => 'image', 'mp4' => 'video', 'webm' => 'video', 'mov' => 'video'];
        foreach ($files as $f) {
            if ($f['error'] !== UPLOAD_ERR_OK)  $fail('The upload failed. Try a smaller file.');
            if ($f['size'] > MS_MAX_UPLOAD)     $fail('That file is too large (25 MB max).');
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (!isset($allowed[$ext]))         $fail('Only photos and videos can be sent.');
            if (function_exists('finfo_open')) {
                $fi = finfo_open(FILEINFO_MIME_TYPE);
                $mime = (string)finfo_file($fi, $f['tmp_name']);
                finfo_close($fi);
                if (strpos($mime, $allowed[$ext] . '/') !== 0) $fail('That file does not look like a real ' . $allowed[$ext] . '.');
            }
            $dir = 'uploads/messages/';
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $target = $dir . bin2hex(random_bytes(12)) . '.' . $ext;
            if (!move_uploaded_file($f['tmp_name'], $target)) $fail('Could not save the file. Please try again.', 500);
            $saved[] = $target;
        }

        if ($message === '' && !$saved) $fail('Write a message first.');

        $reply_val = null;
        $reply_to  = (int)($_POST['reply_to'] ?? 0);
        if ($reply_to > 0 && ms_has_reply_col($conn)) {
            $ok = ms_row($conn, "SELECT id FROM messages WHERE id = ? AND ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)) LIMIT 1", 'iiiii', [$reply_to, $current_user, $receiver, $receiver, $current_user]);
            if ($ok) $reply_val = $reply_to;
        }

        /* disappearing messages: stamp each new message with its expiry */
        $mode = ms_disappear_mode($conn, $current_user, $receiver);
        $exp_val = ($mode >= 2) ? date('Y-m-d H:i:s', time() + MS_DISAPPEAR_SECS[$mode]) : null;
        $van_val = ($mode === 1) ? 1 : 0;

        $insert = function (string $text, ?string $file, ?int $reply) use ($conn, $current_user, $receiver, $exp_val, $van_val): int {
            global $MS_FEAT;
            $cols = "sender_id, receiver_id, message, file_attachment"; $q = "?,?,?,?"; $types = "iiss"; $vals = [$current_user, $receiver, $text, $file];
            if (ms_has_reply_col($conn)) { $cols .= ", reply_to"; $q .= ",?"; $types .= "i"; $vals[] = $reply; }
            if ($MS_FEAT)                { $cols .= ", expires_at, vanish"; $q .= ",?,?"; $types .= "si"; $vals[] = $exp_val; $vals[] = $van_val; }
            $stmt = $conn->prepare("INSERT INTO messages ($cols, created_at) VALUES ($q, NOW())");
            if (!$stmt) return 0;
            $stmt->bind_param($types, ...$vals);
            return $stmt->execute() ? (int)$stmt->insert_id : 0;
        };

        $new_ids = [];
        if (count($saved) === 1) {
            $id = $insert($message, $saved[0], $reply_val);
            if ($id) $new_ids[] = $id;
        } elseif ($saved) {
            foreach ($saved as $i => $sp) { $id = $insert('', $sp, $i === 0 ? $reply_val : null); if ($id) $new_ids[] = $id; }
            if ($message !== '' && $new_ids) { $id = $insert($message, null, null); if ($id) $new_ids[] = $id; }
        } else {
            $id = $insert($message, null, $reply_val);
            if ($id) $new_ids[] = $id;
        }
        if (!$new_ids) $fail('Could not send the message. Please try again.', 500);
        ms_touch($conn, $current_user);
        ms_typing_clear($conn, $current_user, 'u', $receiver);
        if ($MS_ARC) ms_q($conn, "DELETE FROM msg_chat_archive WHERE user_id = ? AND kind = 'u' AND target_id = ?", 'ii', [$receiver, $current_user]);   // a new message brings an archived chat back

        if (!$ajax) { header("Location: messages.php?user=$receiver"); exit; }

        $first = min($new_ids);
        $ph = implode(',', array_fill(0, count($new_ids), '?'));
        $rows = ms_rows($conn, ms_msg_sql($conn) . " WHERE m.id IN ($ph) ORDER BY m.id ASC", str_repeat('i', count($new_ids)), $new_ids);
        $prev = ms_row($conn, "SELECT sender_id, created_at FROM messages WHERE ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)) AND id < ? ORDER BY id DESC LIMIT 1", 'iiiii', [$current_user, $receiver, $receiver, $current_user, $first]);
        $html = $rows ? ms_render_batch($rows, $current_user, ms_shared_memes($conn, $rows), $prev ? (int)$prev['sender_id'] : null, $prev ? dateLabel($prev['created_at']) : null) : '';
        jsonOut(['success' => true, 'id' => $first, 'ids' => $new_ids, 'html' => $html]);
    }

    /* ── EDIT (own text messages, within the 2 minute window) ── */
    if ($act === 'edit') {
        $edit_id = (int)($_POST['id'] ?? 0);
        $new_msg = trim((string)($_POST['new_message'] ?? ''));
        if (mb_strlen($new_msg) > MS_MAX_TEXT) $new_msg = mb_substr($new_msg, 0, MS_MAX_TEXT);
        if ($edit_id <= 0 || $new_msg === '') jsonOut(['success' => false, 'error' => 'Invalid input']);
        $row = ms_row($conn, "SELECT message, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age FROM messages WHERE id = ? AND sender_id = ? LIMIT 1", 'ii', [$edit_id, $current_user]);
        if (!$row) jsonOut(['success' => false, 'error' => 'Message not found'], 404);
        if (trim($row['message']) === '[deleted]' || preg_match('/\[meme:\d+\]/', $row['message'])) jsonOut(['success' => false, 'error' => 'This message cannot be edited']);
        if ((int)$row['age'] > MS_EDIT_WINDOW + 10) jsonOut(['success' => false, 'error' => 'The 2 minute edit window has passed.']);
        $stmt = $conn->prepare("UPDATE messages SET message = ? WHERE id = ? AND sender_id = ?");
        $stmt->bind_param("sii", $new_msg, $edit_id, $current_user);
        $stmt->execute();
        jsonOut(['success' => true]);
    }

    /* ── DELETE / UNSEND ── */
    if ($act === 'delete') {
        $type = (string)($_POST['type'] ?? '1');
        $ids  = array_values(array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? '')))));
        if (empty($ids)) jsonOut(['success' => false, 'error' => 'No IDs provided']);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        if ($type === '1') {
            $types  = str_repeat('i', count($ids)) . 'ii';
            $params = array_merge($ids, [$current_user, $current_user]);
            $stmt   = $conn->prepare("DELETE FROM messages WHERE id IN ($placeholders) AND (sender_id=? OR receiver_id=?)");
        } else {
            $types  = str_repeat('i', count($ids)) . 'i';
            $params = array_merge($ids, [$current_user]);
            $stmt   = $conn->prepare("UPDATE messages SET message='[deleted]', file_attachment=NULL WHERE id IN ($placeholders) AND sender_id=?");
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        jsonOut(['success' => true, 'affected' => $stmt->affected_rows]);
    }

    /* ── CLEAR CHAT / DELETE WHOLE CONVERSATION ── */
    if ($act === 'clear_chat' || $act === 'delete_conv') {
        $with = (int)($_POST['with_user'] ?? 0);
        if ($with <= 0) jsonOut(['success' => false, 'error' => 'Invalid user']);
        $stmt = $conn->prepare("DELETE FROM messages WHERE (sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?)");
        $stmt->bind_param("iiii", $current_user, $with, $with, $current_user);
        $stmt->execute();
        jsonOut(['success' => true]);
    }

    /* ── REACT (reactions are kept on the device, this just acknowledges) ── */
    if ($act === 'react') {
        jsonOut(['success' => true, 'emoji' => mb_substr(trim((string)($_POST['emoji'] ?? '')), 0, 10), 'id' => (int)($_POST['id'] ?? 0)]);
    }

    /* ── BLOCK / UNBLOCK (a blocked person can never message you; enforced in ms_can_message) ── */
    if ($act === 'block') {
        $target = (int)($_POST['target_id'] ?? 0);
        if ($target <= 0 || $target === $current_user) jsonOut(['success' => false, 'error' => 'Invalid user']);
        $stmt = $conn->prepare("INSERT IGNORE INTO blocks (blocker_id, blocked_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $current_user, $target); $stmt->execute();
        $stmt = $conn->prepare("DELETE FROM followers WHERE (follower_id = ? AND following_id = ?) OR (follower_id = ? AND following_id = ?)");
        $stmt->bind_param("iiii", $current_user, $target, $target, $current_user); $stmt->execute();
        jsonOut(['success' => true]);
    }
    if ($act === 'unblock') {
        $target = (int)($_POST['target_id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM blocks WHERE blocker_id = ? AND blocked_id = ?");
        $stmt->bind_param("ii", $current_user, $target); $stmt->execute();
        jsonOut(['success' => true]);
    }

    /* ── MUTE / DISAPPEARING MESSAGES / DOWNLOAD PERMISSION ── */
    if ($act === 'mute') {
        global $MS_FEAT;
        if (!$MS_FEAT) jsonOut(['success' => false, 'error' => 'Muting is not available right now.']);
        $target = (int)($_POST['target_id'] ?? 0);
        if ($target <= 0 || $target === $current_user) jsonOut(['success' => false, 'error' => 'Invalid user']);
        $on = !empty($_POST['on']);
        $stmt = $conn->prepare("DELETE FROM ms_chat_mutes WHERE user_id = ? AND other_id = ?");
        $stmt->bind_param("ii", $current_user, $target); $stmt->execute();
        if ($on) { $stmt = $conn->prepare("INSERT INTO ms_chat_mutes (user_id, other_id) VALUES (?, ?)"); $stmt->bind_param("ii", $current_user, $target); $stmt->execute(); }
        jsonOut(['success' => true, 'muted' => $on]);
    }
    if ($act === 'set_disappear') {
        global $MS_FEAT;
        if (!$MS_FEAT) jsonOut(['success' => false, 'error' => 'Disappearing messages are not available right now.']);
        $target = (int)($_POST['target_id'] ?? 0);
        $mode   = (int)($_POST['mode'] ?? 0);
        if ($target <= 0 || $target === $current_user || !isset(MS_DISAPPEAR_MODES[$mode])) jsonOut(['success' => false, 'error' => 'Invalid request']);
        [$okm, $why] = ms_can_message($conn, $current_user, $target);
        if (!$okm && in_array($why, ['blocked', 'invalid'], true)) jsonOut(['success' => false, 'error' => "You can't change this chat."], 403);
        [$x, $y] = ms_pair($current_user, $target);
        $stmt = $conn->prepare("DELETE FROM ms_disappear WHERE user_a = ? AND user_b = ?");
        $stmt->bind_param("ii", $x, $y); $stmt->execute();
        if ($mode > 0) { $stmt = $conn->prepare("INSERT INTO ms_disappear (user_a, user_b, mode, set_by) VALUES (?, ?, ?, ?)"); $stmt->bind_param("iiii", $x, $y, $mode, $current_user); $stmt->execute(); }
        jsonOut(['success' => true, 'mode' => $mode]);
    }
    if ($act === 'set_download') {
        global $MS_FEAT;
        if (!$MS_FEAT) jsonOut(['success' => false, 'error' => 'Not available right now.']);
        $on = !empty($_POST['on']) ? 1 : 0;
        $stmt = $conn->prepare("DELETE FROM ms_user_prefs WHERE user_id = ?");
        $stmt->bind_param("i", $current_user); $stmt->execute();
        $stmt = $conn->prepare("INSERT INTO ms_user_prefs (user_id, allow_download) VALUES (?, ?)");
        $stmt->bind_param("ii", $current_user, $on); $stmt->execute();
        jsonOut(['success' => true, 'on' => (bool)$on]);
    }

    /* ── MESSAGE REQUESTS ── */
    if ($act === 'accept_request') {
        $from_user = (int)($_POST['from_user'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM restricted_users WHERE restrictor_id=? AND restricted_id=?");
        $stmt->bind_param("ii", $current_user, $from_user);
        $stmt->execute();
        jsonOut(['success' => true]);
    }
    if ($act === 'decline_request') {
        $from_user = (int)($_POST['from_user'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM messages WHERE sender_id=? AND receiver_id=?");
        $stmt->bind_param("ii", $from_user, $current_user);
        $stmt->execute();
        jsonOut(['success' => true]);
    }

    jsonOut(['success' => false, 'error' => 'Unknown action'], 400);
}

/* normal page view: mark the current user as active */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['type']) && !isset($_GET['ms'])) ms_touch($conn, $current_user);

if (isset($_GET['type']) && $_GET['type'] === 'single_meme') {
    header('Content-Type: application/json');
    $mid = (int)($_GET['id'] ?? 0);
    if (!$mid) { echo json_encode(null); exit; }

    $stmt = $conn->prepare("
        SELECT memes.*, users.username, users.nickname, users.profile_pic, users.is_verified,
            ma.cta_text AS ad_cta_text, ma.cta_url AS ad_cta_url, ma.cta_color AS ad_cta_color,
            (SELECT COUNT(*) FROM likes     WHERE likes.meme_id     = memes.id) AS like_count,
            (SELECT COUNT(*) FROM reposts   WHERE reposts.meme_id   = memes.id) AS repost_count,
            (SELECT COUNT(*) FROM favorites WHERE favorites.meme_id = memes.id) AS fav_count,
            (SELECT COUNT(*) FROM shares    WHERE shares.meme_id    = memes.id) AS share_count,
            (SELECT COUNT(*) FROM likes     WHERE likes.meme_id     = memes.id AND likes.user_id     = ?) AS user_liked,
            (SELECT COUNT(*) FROM reposts   WHERE reposts.meme_id   = memes.id AND reposts.user_id   = ?) AS user_reposted,
            (SELECT COUNT(*) FROM favorites WHERE favorites.meme_id = memes.id AND favorites.user_id = ?) AS user_fav,
            (SELECT COUNT(*) FROM followers WHERE followers.follower_id = ? AND followers.following_id = memes.uploaded_by) AS is_following
        FROM memes JOIN users ON memes.uploaded_by = users.id
        LEFT JOIN meme_ads ma ON ma.meme_id = memes.id
        WHERE memes.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("iiiii", $current_user, $current_user, $current_user, $current_user, $mid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row) { echo json_encode(null); exit; }

    $social_proof = ms_social_proof($conn, (int)$mid, (int)$current_user);

    $media_items = getMemeMediaArray($conn, $mid, $row['file_name'], $row['type']);
    $collab_one  = dz_collab_payload($conn, [$mid], (int)$current_user)[$mid] ?? null;
    $audio_info  = getMemeAudioInfo($conn, $mid);
    $group_info  = getMemeGroupInfo($conn, $mid);

    echo json_encode([
        'id'          => (int)$row['id'],
        'file'        => 'uploads/' . $row['file_name'],
        'type'        => $row['type'],
        'media'       => $media_items,
        'audioTitle'       => $audio_info['audioTitle']       ?? null,
        'audioArtist'      => $audio_info['audioArtist']      ?? null,
        'audioFile'        => $audio_info['audioFile']        ?? null,
        'musicVolume'      => $audio_info['musicVolume']      ?? 80,
        'videoAudioVolume' => $audio_info['videoAudioVolume'] ?? 40,
        'groupId'     => $group_info['groupId']    ?? null,
        'groupTitle'  => $group_info['groupTitle'] ?? null,
        'pic'         => $row['profile_pic'],
        'userId'      => (int)$row['uploaded_by'],
        'username'    => $row['username'],
        'nickname'    => $row['nickname'] ?? '',
        'isVerified'  => !empty($row['is_verified']),
        'caption'     => $row['caption'],
        'time'        => $row['created_at'],
        'likeCount'   => (int)$row['like_count'],
        'repostCount' => (int)$row['repost_count'],
        'favCount'    => (int)$row['fav_count'],
        'shareCount'  => (int)$row['share_count'],
        'liked'       => (int)$row['user_liked'] > 0,
        'reposted'    => (int)$row['user_reposted'] > 0,
        'faved'       => (int)$row['user_fav'] > 0,
        'following'   => (int)$row['is_following'] > 0,
        'socialProof' => $social_proof,
        'isAd'          => !empty($row['is_ad']),
        'adCtaText'     => $row['ad_cta_text'] ?? '',
        'adCtaUrl'      => $row['ad_cta_url'] ?? '',
        'adCtaColor'    => $row['ad_cta_color'] ?? '#FF4C4C',
        'isAiGenerated' => !empty($row['is_ai_generated']),
        'collab'        => $collab_one,
        'allowDownload' => (int)($row['allow_download'] ?? 1),
    ]);
    exit;
}

/* ── ALL SHARED MEMES IN A CHAT (for scroll-through in the reels modal) ──
   Returns every meme that has been shared as [meme:ID] between the
   current user and ?with=, in chronological order, so the caption
   modal can be swiped/scrolled through like a mini feed.
   UPDATED: no longer deduped by meme id — returns one entry PER
   SHARE INSTANCE, each carrying who actually sent it (sentByUserId/
   sentByUsername/sentByPic) and when (sentAt), so the reels modal's
   top bar always shows the correct sender + timestamp per post,
   even when the same meme was shared more than once in this chat.
   UPDATED AGAIN: now bulk-prefetches group/pod info (same pattern
   as the media/audio prefetches below) and includes groupId/
   groupTitle per meme, so the reels modal's "From <group>" tag
   works here too, matching home.php.
   UPDATED AGAIN: now joins meme_ads and returns isAd/adCtaText/
   adCtaUrl/adCtaColor plus isAiGenerated per meme, same as
   single_meme above, so ad/AI posts render correctly wherever
   they appear in a chat's shared-post feed. */
if (isset($_GET['type']) && $_GET['type'] === 'chat_shared_memes') {
    header('Content-Type: application/json');
    $with = (int)($_GET['with'] ?? 0);
    if (!$with) { echo json_encode([]); exit; }

    $stmt = $conn->prepare("
        SELECT m.message, m.sender_id, m.created_at,
               u.username AS sender_username, u.profile_pic AS sender_pic
        FROM messages m
        JOIN users u ON u.id = m.sender_id
        WHERE ((m.sender_id=? AND m.receiver_id=?) OR (m.sender_id=? AND m.receiver_id=?))
          AND m.message LIKE '[meme:%'
        ORDER BY m.created_at ASC
    ");
    $stmt->bind_param("iiii", $current_user, $with, $with, $current_user);
    $stmt->execute();
    $res = $stmt->get_result();

    $instances = []; // one entry PER SHARE, not deduped
    $mid_set   = [];
    while ($r = $res->fetch_assoc()) {
        if (preg_match('/\[meme:(\d+)\]/', $r['message'], $mm)) {
            $mid = (int)$mm[1];
            $instances[] = [
                'mid'             => $mid,
                'sender_id'       => (int)$r['sender_id'],
                'sender_username' => $r['sender_username'],
                'sender_pic'      => $r['sender_pic'] ?: 'mee.jpg',
                'created_at'      => $r['created_at'],
            ];
            $mid_set[$mid] = true;
        }
    }

    if (empty($instances)) { echo json_encode([]); exit; }

    $ids     = array_keys($mid_set);
    $id_list = implode(',', $ids);

    $mq = $conn->query("
        SELECT memes.*, users.username, users.nickname, users.profile_pic, users.is_verified,
            ma.cta_text AS ad_cta_text, ma.cta_url AS ad_cta_url, ma.cta_color AS ad_cta_color,
            (SELECT COUNT(*) FROM likes     WHERE likes.meme_id     = memes.id) AS like_count,
            (SELECT COUNT(*) FROM reposts   WHERE reposts.meme_id   = memes.id) AS repost_count,
            (SELECT COUNT(*) FROM favorites WHERE favorites.meme_id = memes.id) AS fav_count,
            (SELECT COUNT(*) FROM shares    WHERE shares.meme_id    = memes.id) AS share_count,
            (SELECT COUNT(*) FROM likes     WHERE likes.meme_id     = memes.id AND likes.user_id     = $current_user) AS user_liked,
            (SELECT COUNT(*) FROM reposts   WHERE reposts.meme_id   = memes.id AND reposts.user_id   = $current_user) AS user_reposted,
            (SELECT COUNT(*) FROM favorites WHERE favorites.meme_id = memes.id AND favorites.user_id = $current_user) AS user_fav,
            (SELECT COUNT(*) FROM followers WHERE followers.follower_id = $current_user AND followers.following_id = memes.uploaded_by) AS is_following
        FROM memes JOIN users ON memes.uploaded_by = users.id
        LEFT JOIN meme_ads ma ON ma.meme_id = memes.id
        WHERE memes.id IN ($id_list)
    ");

    $rows_by_id = [];
    if ($mq) while ($row = $mq->fetch_assoc()) { $rows_by_id[(int)$row['id']] = $row; }
    $collab_all = dz_collab_payload($conn, array_keys($rows_by_id), (int)$current_user);

    /* ── BULK PREFETCH: extra media items for every meme in this chat ── */
    $media_map = [];
    $mm_res = $conn->query("
        SELECT meme_id, file_name, type, position
        FROM meme_media
        WHERE meme_id IN ($id_list)
        ORDER BY meme_id, position ASC
    ");
    if ($mm_res) while ($mm = $mm_res->fetch_assoc()) {
        $media_map[(int)$mm['meme_id']][] = [ 'file' => 'uploads/' . $mm['file_name'], 'type' => $mm['type'] ];
    }

    /* ── BULK PREFETCH: attached audio for every meme in this chat ── */
    $audio_map = [];
    $au_res = $conn->query("
        SELECT ma.meme_id, a.title, a.artist, a.file_name, ma.music_volume, ma.video_audio_volume
        FROM meme_audio ma
        JOIN audios a ON a.id = ma.audio_id
        WHERE ma.meme_id IN ($id_list)
    ");
    if ($au_res) while ($au = $au_res->fetch_assoc()) {
        $audio_map[(int)$au['meme_id']] = $au;
    }

    /* ── BULK PREFETCH: group/pod info for every meme in this chat
       (mirrors home.php's $_group_map, but scoped to just the memes
       that were shared in this conversation) ── */
    $group_map = [];
    $gp_res = $conn->query("
        SELECT gp.meme_id, g.id AS group_id, g.title AS group_title
        FROM group_posts gp
        JOIN groups g ON g.id = gp.group_id
        WHERE gp.meme_id IN ($id_list)
    ");
    if ($gp_res) while ($gp = $gp_res->fetch_assoc()) {
        $group_map[(int)$gp['meme_id']] = $gp;
    }

    $out = [];
    foreach ($instances as $inst) {
        $mid = $inst['mid'];
        if (!isset($rows_by_id[$mid])) continue;
        $row = $rows_by_id[$mid];

        $social_proof = ms_social_proof($conn, (int)$mid, (int)$current_user);

        $media_items = array_merge(
            [[ 'file' => 'uploads/' . $row['file_name'], 'type' => $row['type'] ]],
            $media_map[$mid] ?? []
        );
        $audio_info = $audio_map[$mid] ?? null;
        $group_info = $group_map[$mid] ?? null;

        $out[] = [
            'id'          => (int)$row['id'],
            'file'        => 'uploads/' . $row['file_name'],
            'type'        => $row['type'],
            'media'       => $media_items,
            'audioTitle'       => $audio_info['title']              ?? null,
            'audioArtist'      => $audio_info ? ($audio_info['artist'] ?: null) : null,
            'audioFile'        => $audio_info ? ('uploads/' . $audio_info['file_name']) : null,
            'musicVolume'      => $audio_info ? (int)$audio_info['music_volume']       : 80,
            'videoAudioVolume' => $audio_info ? (int)$audio_info['video_audio_volume'] : 40,
            'groupId'     => $group_info ? (int)$group_info['group_id'] : null,
            'groupTitle'  => $group_info ? $group_info['group_title']  : null,
            'pic'         => $row['profile_pic'],
            'userId'      => (int)$row['uploaded_by'],
            'username'    => $row['username'],
            'nickname'    => $row['nickname'] ?? '',
            'isVerified'  => !empty($row['is_verified']),
            'caption'     => $row['caption'],
            'time'        => $row['created_at'],
            'likeCount'   => (int)$row['like_count'],
            'repostCount' => (int)$row['repost_count'],
            'favCount'    => (int)$row['fav_count'],
            'shareCount'  => (int)$row['share_count'],
            'liked'       => (int)$row['user_liked'] > 0,
            'reposted'    => (int)$row['user_reposted'] > 0,
            'faved'       => (int)$row['user_fav'] > 0,
            'following'   => (int)$row['is_following'] > 0,
            'socialProof' => $social_proof,

            /* ── who actually shared THIS instance in the chat, and when ── */
            'sentByUserId'   => $inst['sender_id'],
            'sentByUsername' => $inst['sender_username'],
            'sentByPic'      => $inst['sender_pic'],
            'sentAt'         => $inst['created_at'],

            /* ── ad / AI flags, matching home.php ── */
            'isAd'          => !empty($row['is_ad']),
            'adCtaText'     => $row['ad_cta_text'] ?? '',
            'adCtaUrl'      => $row['ad_cta_url'] ?? '',
            'adCtaColor'    => $row['ad_cta_color'] ?? '#FF4C4C',
            'collab'        => $collab_all[$mid] ?? null,
            'allowDownload' => (int)($row['allow_download'] ?? 1),
            'isAiGenerated' => !empty($row['is_ai_generated']),
        ];
    }

    echo json_encode($out);
    exit;
}

/* ── FOLLOWING LIST (for the share modal picker) ─────────── */
if (isset($_GET['type']) && $_GET['type'] === 'following') {
    header('Content-Type: application/json');
    $out = [];
    $stmt = $conn->prepare("
        SELECT u.id, u.username, u.profile_pic
        FROM followers f
        JOIN users u ON u.id = f.following_id
        WHERE f.follower_id = ?
        ORDER BY u.username ASC
    ");
    $stmt->bind_param("i", $current_user);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) { $out[] = $r; }
    echo json_encode($out);
    exit;
}

/* ── ACTION HANDLER (like / repost / fav / share / follow / block / not-interested / report) — mirrors home.php ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $meme_id = intval($_POST['meme_id'] ?? 0);
    $action  = $_POST['action'];

function msgCountRows($conn, $table, $meme_id) {
        $res = $conn->query("SELECT COUNT(*) as c FROM $table WHERE meme_id=$meme_id");
        return $res->fetch_assoc()['c'];
    }

    if ($action === 'toggle_like') {
        $check = $conn->query("SELECT id FROM likes WHERE user_id=$current_user AND meme_id=$meme_id");
        if ($check->num_rows) {
            $conn->query("DELETE FROM likes WHERE user_id=$current_user AND meme_id=$meme_id");
        } else {
            $conn->query("INSERT INTO likes (user_id,meme_id) VALUES ($current_user,$meme_id)");
        }
        echo json_encode(["count" => msgCountRows($conn, "likes", $meme_id)]);
        exit;
    }

    if ($action === 'toggle_repost') {
        $check = $conn->query("SELECT id FROM reposts WHERE user_id=$current_user AND meme_id=$meme_id");
        if ($check->num_rows) {
            $conn->query("DELETE FROM reposts WHERE user_id=$current_user AND meme_id=$meme_id");
        } else {
            $conn->query("INSERT INTO reposts (user_id,meme_id) VALUES ($current_user,$meme_id)");
        }
        echo json_encode(["count" => msgCountRows($conn, "reposts", $meme_id)]);
        exit;
    }

    if ($action === 'toggle_favorite') {
        $check = $conn->query("SELECT id FROM favorites WHERE user_id=$current_user AND meme_id=$meme_id");
        if ($check->num_rows) {
            $conn->query("DELETE FROM favorites WHERE user_id=$current_user AND meme_id=$meme_id");
        } else {
            $conn->query("INSERT INTO favorites (user_id,meme_id) VALUES ($current_user,$meme_id)");
        }
        echo json_encode(["count" => msgCountRows($conn, "favorites", $meme_id)]);
        exit;
    }

    if ($action === 'toggle_follow') {
        $target_id = intval($_POST['user_id'] ?? 0);
        if (!$target_id) { echo json_encode(['status'=>'error','message'=>'invalid user']); exit; }
        $stmt = $conn->prepare("SELECT id FROM followers WHERE follower_id=? AND following_id=?");
        $stmt->bind_param("ii", $current_user, $target_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->num_rows > 0) {
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

    if ($action === 'block_user') {
        $target = intval($_POST['target_id'] ?? 0);
        if ($target && $target !== $current_user) {
            $stmt = $conn->prepare("INSERT IGNORE INTO blocks (blocker_id, blocked_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $current_user, $target);
            $stmt->execute();
            $stmt2 = $conn->prepare("DELETE FROM followers WHERE follower_id=? AND following_id=?");
            $stmt2->bind_param("ii", $current_user, $target);
            $stmt2->execute();
            echo json_encode(['status' => 'ok']);
        } else {
            echo json_encode(['status' => 'error']);
        }
        exit;
    }

    if ($action === 'not_interested') {
        if ($meme_id) {
            $conn->query("INSERT IGNORE INTO user_interactions (user_id, meme_id, interaction_type) VALUES ($current_user, $meme_id, 'view')");
            $conn->query("UPDATE memes SET trend_velocity = trend_velocity * 0.5 WHERE id = $meme_id");
        }
        echo json_encode(['status' => 'ok']);
        exit;
    }

    if ($action === 'report_meme') {
        $reason = trim($_POST['reason'] ?? 'No reason given');
        if ($meme_id) {
            $owner = $conn->query("SELECT uploaded_by FROM memes WHERE id=$meme_id")->fetch_assoc()['uploaded_by'] ?? 0;
            if ($owner) {
                $conn->query("INSERT INTO reports (reporter_id, reported_id) VALUES ($current_user, $owner)");
            }
        }
        echo json_encode(['status' => 'ok']);
        exit;
    }

    if ($action === 'share_post') {
        $receiver_ids_raw = $_POST['receiver_ids'] ?? '';
        $receiver_ids = array_values(array_unique(array_filter(
            array_map('intval', explode(',', $receiver_ids_raw)),
            function($v) use ($current_user){ return $v > 0 && $v !== $current_user; }
        )));

        if (!$meme_id || empty($receiver_ids)) {
            echo json_encode(["status" => "error", "message" => "invalid request"]);
            exit;
        }

        $meme_check = $conn->query("SELECT id FROM memes WHERE id=$meme_id LIMIT 1");
        if (!$meme_check || $meme_check->num_rows === 0) {
            echo json_encode(["status" => "error", "message" => "meme not found"]);
            exit;
        }

        /* Allow messages / privacy level / blocks are enforced for shared posts too */
        $allowed_ids = []; $skipped_n = 0;
        foreach ($receiver_ids as $rid) { [$okm] = ms_can_message($conn, $current_user, $rid); if ($okm) $allowed_ids[] = $rid; else $skipped_n++; }
        $receiver_ids = $allowed_ids;
        if (empty($receiver_ids)) {
            echo json_encode(["status" => "error", "message" => "These people aren't accepting messages from you"]);
            exit;
        }

        $sent_to    = [];
        $stmt_share = $conn->prepare("INSERT INTO shares (user_id, meme_id, share_type) VALUES (?, ?, 'message')");
        $stmt_msg   = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message, seen) VALUES (?, ?, ?, 0)");

        foreach ($receiver_ids as $rid) {
            $stmt_share->bind_param("ii", $current_user, $meme_id);
            $stmt_share->execute();

            $msg_text = "[meme:$meme_id]";
            $stmt_msg->bind_param("iis", $current_user, $rid, $msg_text);
            $stmt_msg->execute();

            $sent_to[] = $rid;
        }

        echo json_encode([
            "status"  => "ok",
            "count"   => msgCountRows($conn, "shares", $meme_id),
            "sent_to" => $sent_to,
            "skipped" => $skipped_n
        ]);
        exit;
    }
}

/* ══════════════════════════════════════════════════════════════
   PAGE DATA
   ══════════════════════════════════════════════════════════════ */
ms_purge($conn, $current_user, true);   // disappearing messages whose time is up (or that were seen) are removed now
$me_settings = ms_settings($conn, $current_user);
$viewer_show = dz_viewer_mask($conn, (int)$current_user);

$conversations    = ms_fetch_convs($conn, $current_user, false, MS_CONV_PAGE + 1, 0);   // main inbox, first page (the rest loads while scrolling)
$conv_more        = count($conversations) > MS_CONV_PAGE;
$conversations    = array_slice($conversations, 0, MS_CONV_PAGE);
$message_requests = ms_fetch_convs($conn, $current_user, true);    // people you restricted
$request_count    = count($message_requests);
$unread_total     = 0;
$unread_total = ms_unread_total($conn, $current_user) + ms_group_unread_total($conn, $current_user);

$groups = []; $group_more = false;
if ($MS_GRP) {
    $groups     = ms_fetch_groups($conn, $current_user, MS_GROUP_PAGE + 1, 0);
    $group_more = count($groups) > MS_GROUP_PAGE;
    $groups     = array_slice($groups, 0, MS_GROUP_PAGE);
}
$arch_html = ''; $arch_count = 0; $g_dot = false;
if ($MS_ARC) [$arch_html, $arch_count] = ms_render_archive($conn, $current_user, $chat_user, $chat_group, $viewer_show);
$group_info = null; $g_members = []; $g_pics = []; $g_is_owner = false; $g_muted = false; $g_bg = ''; $g_bg_type = '';
$msgs_more = false;

$is_chat_user_restricted = false;
$all_msgs         = [];
$shared_memes_map = [];
$other_info       = null;
$other_pres       = ['visible' => false, 'active' => false, 'label' => ''];
$can_msg          = [true, ''];
$i_blocked        = false;
$lock_text        = '';
$last_msg_id      = 0;
$chat_stats       = ['messages' => 0, 'media' => 0, 'posts' => 0];
$chat_media       = [];
$chat_disappear   = 0;
$chat_muted       = false;
$my_allow_dl      = true;

if ($chat_user) {
    $ac = ms_active_col($conn);
    $other_info = ms_row($conn, "
        SELECT u.id, u.username, u.nickname, u.profile_pic, COALESCE(u.is_verified, 0) AS is_verified, (LOWER(COALESCE(u.user_rank,'')) = 'suspended') AS is_susp,
               " . ms_active_expr($ac, 'u') . " AS active_ago, " . dz_vis_mask_sql('us', 'u', (int)$current_user) . " AS p_show
        FROM users u LEFT JOIN user_settings us ON us.user_id = u.id
        WHERE u.id = ? LIMIT 1", 'i', [$chat_user]);

    if (!$other_info) {
        $chat_user = null;                       // that user does not exist: show the empty state
    } else {
        $is_chat_user_restricted = (bool)ms_row($conn, "SELECT id FROM restricted_users WHERE restrictor_id = ? AND restricted_id = ? LIMIT 1", 'ii', [$current_user, $chat_user]);

        ms_q($conn, "UPDATE messages SET seen = 1 WHERE sender_id = ? AND receiver_id = ? AND seen = 0", 'ii', [$chat_user, $current_user]);

        // the open chat is now read: its row in the list shows the last message again instead of "N+ new messages"
        foreach ($conversations as &$_cc) if ((int)$_cc['id'] === $chat_user) $_cc['unread'] = 0; unset($_cc);
        foreach ($message_requests as &$_cc) if ((int)$_cc['id'] === $chat_user) $_cc['unread'] = 0; unset($_cc);
        $unread_total = ms_unread_total($conn, $current_user) + ms_group_unread_total($conn, $current_user);

        // the newest 30 messages; older ones load while scrolling up
        $pair_sql = "((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))";
        $pair_p   = [$current_user, $chat_user, $chat_user, $current_user];
        $all_msgs = ms_rows($conn, ms_msg_sql($conn) . " WHERE $pair_sql ORDER BY m.id DESC LIMIT " . (MS_PAGE_SIZE + 1), 'iiii', $pair_p);
        $msgs_more = count($all_msgs) > MS_PAGE_SIZE;
        $all_msgs  = array_reverse(array_slice($all_msgs, 0, MS_PAGE_SIZE));
        $shared_memes_map = ms_shared_memes($conn, $all_msgs);
        foreach ($all_msgs as $_m) $last_msg_id = max($last_msg_id, (int)$_m['id']);

        $st = ms_row($conn, "SELECT COUNT(*) AS n, SUM(CASE WHEN m.file_attachment IS NOT NULL AND m.file_attachment <> '' THEN 1 ELSE 0 END) AS media,
                SUM(CASE WHEN m.message LIKE '%[meme:%' THEN 1 ELSE 0 END) AS posts FROM messages m WHERE $pair_sql", 'iiii', $pair_p);
        if ($st) $chat_stats = ['messages' => (int)$st['n'], 'media' => (int)$st['media'], 'posts' => (int)$st['posts']];
        foreach (ms_rows($conn, ms_msg_sql($conn) . " WHERE $pair_sql AND m.file_attachment IS NOT NULL AND m.file_attachment <> '' ORDER BY m.id DESC LIMIT 9", 'iiii', $pair_p) as $_m)
            $chat_media[] = ['path' => $_m['file_attachment'], 'id' => (int)$_m['id'], 'dl' => ((int)$_m['sender_id'] === $current_user || (int)($_m['sender_dl'] ?? 1) === 1) ? 1 : 0];

        $chat_disappear = ms_disappear_mode($conn, $current_user, $chat_user);
        $chat_muted     = ms_is_muted($conn, $current_user, $chat_user);
        $my_allow_dl    = ms_allows_download($conn, $current_user);
        $other_pres = ms_presence($other_info['active_ago'] !== null ? (float)$other_info['active_ago'] : null, (int)$other_info['p_show'], $viewer_show);
        $can_msg    = ms_can_message($conn, $current_user, $chat_user);
        $i_blocked = (bool)ms_row($conn, "SELECT 1 AS x FROM blocks WHERE blocker_id = ? AND blocked_id = ? LIMIT 1", 'ii', [$current_user, $chat_user]);
        if (!$can_msg[0]) $lock_text = $i_blocked
            ? 'You blocked ' . ($other_info['nickname'] ?: $other_info['username']) . '. They cannot message you, and you cannot message them until you unblock.'
            : ms_block_text($can_msg[1], $other_info['nickname'] ?: $other_info['username']);
    }
}

/* ── A GROUP CHAT IS OPEN ── */
if ($chat_group && $MS_GRP) {
    $group_info = ms_group_get($conn, $chat_group, $current_user);
    if (!$group_info) { $chat_group = null; }
    else {
        ms_group_mark_read($conn, $chat_group, $current_user);
        foreach ($groups as &$_gg) if ((int)$_gg['id'] === $chat_group) $_gg['unread'] = 0; unset($_gg);
        $unread_total = ms_unread_total($conn, $current_user) + ms_group_unread_total($conn, $current_user);
        $g_members  = msg_gc_members($conn, $chat_group);
        $g_pics     = ms_group_pics($g_members, $current_user);
        $g_is_owner = (int)$group_info['owner_id'] === $current_user;
        $g_muted    = ms_is_muted($conn, $current_user, -$chat_group);
        $g_bg = (string)($group_info['bg'] ?? ''); $g_bg_type = (string)($group_info['bg_type'] ?? '');
        $chat_muted = $g_muted;
        $my_allow_dl = ms_allows_download($conn, $current_user);
        $all_msgs = ms_rows($conn, ms_group_msg_sql($conn) . " WHERE m.group_id = ? ORDER BY m.id DESC LIMIT " . (MS_PAGE_SIZE + 1), 'i', [$chat_group]);
        $msgs_more = count($all_msgs) > MS_PAGE_SIZE;
        $all_msgs  = array_reverse(array_slice($all_msgs, 0, MS_PAGE_SIZE));
        $shared_memes_map = ms_shared_memes($conn, $all_msgs);
        foreach ($all_msgs as $_m) $last_msg_id = max($last_msg_id, (int)$_m['id']);
        $st = ms_row($conn, "SELECT SUM(CASE WHEN kind = 0 THEN 1 ELSE 0 END) AS n, SUM(CASE WHEN file_attachment IS NOT NULL AND file_attachment <> '' THEN 1 ELSE 0 END) AS media FROM msg_gc_messages WHERE group_id = ?", 'i', [$chat_group]);
        if ($st) $chat_stats = ['messages' => (int)$st['n'], 'media' => (int)$st['media'], 'posts' => 0];
        foreach (ms_rows($conn, ms_group_msg_sql($conn) . " WHERE m.group_id = ? AND m.file_attachment IS NOT NULL AND m.file_attachment <> '' ORDER BY m.id DESC LIMIT 9", 'i', [$chat_group]) as $_m)
            $chat_media[] = ['path' => $_m['file_attachment'], 'id' => (int)$_m['id'], 'dl' => ((int)$_m['sender_id'] === $current_user || (int)($_m['sender_dl'] ?? 1) === 1) ? 1 : 0];
    }
} else { $chat_group = null; }
$g_dot = ms_group_attention($conn, $current_user);
$cur_archived = false;
if ($MS_ARC && ($chat_group || $chat_user)) $cur_archived = (bool)ms_row($conn, "SELECT 1 AS x FROM msg_chat_archive WHERE user_id = ? AND kind = ? AND target_id = ? LIMIT 1", 'isi', [$current_user, $chat_group ? 'g' : 'u', $chat_group ?: $chat_user]);
$is_group = (bool)$group_info;
$in_chat  = $is_group || ($chat_user && $other_info);

/* ── CURRENT USER INFO ────────────────────────────────────── */
$me = ms_row($conn, "SELECT username, nickname, profile_pic FROM users WHERE id = ?", 'i', [$current_user]) ?: ['username' => '', 'nickname' => '', 'profile_pic' => null];
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<meta name="csrf-token" content="<?= ms_h($csrf_token) ?>">
<link rel="manifest" href="/Peura/manifest.json">
<meta name="theme-color" content="#070924">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Peura">
<link rel="apple-touch-icon" href="/Peura/Peura.jpg">
<title><?= $unread_total > 0 ? '(' . (int)$unread_total . ') ' : '' ?>Messages — Dezzme</title>
<script>try{var _t=localStorage.getItem('dz_theme');if(!_t){var _m=document.cookie.match(/(?:^|; )dz_theme=(light|dark)/);_t=_m?_m[1]:'';}if(_t==='light'){document.documentElement.setAttribute('data-theme','light');var _mc=document.querySelector('meta[name="theme-color"]');if(_mc)_mc.setAttribute('content','#ffffff');}}catch(e){}</script>
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/Peura/service-worker.js').catch(function(){});
    });
}
</script>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<style>
/* ══════════════════════════════════════════════════════════════
   DEZZME MESSAGES · same design language as the Settings page
   Black-navy gradient · red accent · Poppins · boxy 5px corners
   · 1px grey lines · fluid clamp() sizing · green "active" dot
   ══════════════════════════════════════════════════════════════ */
:root{
  --fg:255,255,255; --ui-text:#fff; --ring:#070924; --body-bg:#030420; --bubble-recv:#151a44; --card:#14163f; --field:#0b0e30; --ph:#10143a; --ov:rgba(1,2,24,.78); --ov2:rgba(7,9,36,.88); --btn:#23263f; --btn-h:#2e3250; --rp-bg:rgba(0,0,0,.22); --pin-bg:#0b0e2c; --shadow-pop:rgba(0,0,0,.7);
  --accent:#FF2D2D; --accent-hover:#ff1a1a;
  --accent-soft:rgba(255,45,45,.14); --accent-border:rgba(255,45,45,.4); --accent-fill:rgba(255,45,45,.2);
  --accent-glow:rgba(255,45,45,.28); --accent-text:#ff8a8a; --accent-focus:rgba(255,45,45,.7); --accent-ring:rgba(255,45,45,.12); --accent-sel:rgba(255,45,45,.35);
  --bubble:#e8222e;
  --scroll-thumb:rgba(var(--fg),.32);
  --danger:#ff6b6b; --danger-soft:rgba(255,107,107,.14);
  --on-accent:#000; --solid:#070924; --panel:#0a0c26;
  --surface:rgba(var(--fg),.05); --surface-2:rgba(var(--fg),.08);
  --border:rgba(var(--fg),.14); --line:rgba(var(--fg),.09);
  --text:#fff; --muted:rgba(var(--fg),.45); --muted-2:rgba(var(--fg),.3); --sub:rgba(var(--fg),.62);
  --bg-grad:linear-gradient(-45deg,#010218,#070924,#070924);
  --ok:#4ade80;
  --topbar-h:54px;
  --pad:clamp(11px,3.4vw,16px); --ctl-h:clamp(32px,8.4vw,38px);
  --r:5px; --act-h:clamp(26px,7.4vw,30px); --act-r:4px;
  --r-bubble:20px;
  --sidebar-w:360px; --info-w:300px; --ease:.2s ease;
  --bubble-fs:.74rem;
}
@media (max-width:490px){:root{--topbar-h:48px}}

*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
html,body{height:100%;overflow:hidden;}
/* body is left exactly as before so the meme modal and share sheet (which sit directly under body) look identical */
body{background:var(--body-bg,#030420);color:#f0f0f0;font-family:'DM Sans',sans-serif;font-size:14px;display:flex;flex-direction:column;}
a{text-decoration:none;color:inherit;}
button,a{cursor:pointer;outline:none;-webkit-tap-highlight-color:transparent;}
::selection{background:var(--accent-sel);color:var(--ui-text,#fff);}
[class^="ri-"],[class*=" ri-"],[class^="ri-"]::before,[class*=" ri-"]::before{font-family:'remixicon' !important;font-style:normal;font-weight:normal;line-height:1;speak:none;text-transform:none;-webkit-font-smoothing:antialiased}

/* everything that belongs to this page (not the meme modal) uses Poppins */
.app,.ms-ui{font-family:'Poppins',sans-serif;font-size:13px;color:var(--text);-webkit-font-smoothing:antialiased;}
.app button,.app input,.app textarea,.app select,.ms-ui button,.ms-ui input,.ms-ui textarea{font-family:'Poppins',sans-serif;}
.app img,.ms-ui img,.app video{max-width:100%;display:block;}
.app,.ms-ui{scrollbar-width:thin;scrollbar-color:var(--scroll-thumb) transparent;}
.app ::-webkit-scrollbar,.ms-ui ::-webkit-scrollbar{width:5px;height:5px}
.app ::-webkit-scrollbar-thumb,.ms-ui ::-webkit-scrollbar-thumb{background:var(--scroll-thumb);border-radius:99px}
@media (max-width:768px){
  .app *,.ms-ui *{scrollbar-width:none !important}
  .app *::-webkit-scrollbar,.ms-ui *::-webkit-scrollbar{display:none !important}
}

.app{display:flex;height:100vh;height:100dvh;position:relative;z-index:1;background:var(--bg-grad);background-attachment:fixed;overflow:hidden;}

/* ══ SIDEBAR ══ */
.ms-sidebar{width:var(--sidebar-w);min-width:var(--sidebar-w);background:var(--bg-grad);background-attachment:fixed;border-right:1px solid var(--line);display:flex;flex-direction:column;height:100%;position:relative;z-index:5;}

/* top bar (shared look with the settings page) */
.ac-topbar{position:relative;z-index:30;height:var(--topbar-h);flex-shrink:0;display:flex;align-items:center;gap:2px;padding:0 10px;background:var(--bg-grad);background-attachment:fixed;}
.ac-topbar::after{content:'';position:absolute;left:0;right:0;bottom:0;height:1px;background:rgba(var(--fg),.14);pointer-events:none;z-index:2;}
.ac-x{width:34px;height:34px;background:none;border:none;color:var(--ui-text,#fff);display:flex;align-items:center;justify-content:center;opacity:.9;transition:opacity .2s,color .2s;padding:0;flex-shrink:0;position:relative;}
.ac-x:hover{opacity:1;color:var(--accent);}
.ac-x i{font-size:24px;}
.ac-x.active{color:var(--accent);opacity:1;}
.ac-title{flex:1;min-width:0;font-size:.92rem;font-weight:700;color:var(--ui-text,#fff);letter-spacing:-.1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin:0 6px 0 4px;display:flex;align-items:center;gap:8px;}
.ac-count{font-size:.6rem;font-weight:700;background:var(--accent);color:var(--on-accent);padding:2px 7px;border-radius:99px;min-width:18px;text-align:center;}
.ac-count:empty{display:none;}
@media (max-width:490px){.ac-x{width:30px;height:30px}.ac-x i{font-size:22px}.ac-title{font-size:.84rem}}

.sb-bulk-bar{display:none;align-items:center;gap:8px;padding:9px var(--pad);background:var(--accent-soft);border-bottom:1px solid var(--accent-border);flex-shrink:0;}
.sb-bulk-bar.active{display:flex;}
.sb-bulk-count{font-size:.74rem;font-weight:700;color:var(--ui-text,#fff);flex:1;}
.act-btn{display:inline-flex;align-items:center;gap:6px;height:var(--act-h);padding:0 11px;border-radius:var(--act-r);background:rgba(var(--fg),.1);border:none;color:var(--ui-text,#fff);font-size:.68rem;font-weight:600;transition:background .2s,transform .15s;white-space:nowrap;}
.act-btn:hover{background:rgba(var(--fg),.2);}
.act-btn:active{transform:scale(.97);}
.act-btn i{font-size:14px;}
.act-btn.danger{background:rgba(255,107,107,.16);color:var(--danger);}
.act-btn.danger:hover{background:rgba(255,107,107,.28);}
.act-btn.accent{background:var(--accent);color:var(--on-accent);}
.act-btn.accent:hover{background:var(--accent-hover);}

.sb-me{padding:10px var(--pad);display:flex;align-items:center;gap:10px;border-bottom:1px solid var(--line);flex-shrink:0;}
.sb-me img{width:32px;height:32px;border-radius:50%;object-fit:cover;border:2px solid var(--accent);}
.sb-me-t{min-width:0;flex:1;}
.sb-me-name{font-size:.76rem;font-weight:600;color:var(--ui-text,#fff);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.sb-me-sub{font-size:.62rem;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.sb-me-state{font-size:.58rem;font-weight:600;color:var(--muted);display:flex;align-items:center;gap:5px;flex-shrink:0;}
.sb-me-state i{width:7px;height:7px;border-radius:50%;background:var(--muted-2);display:block;}
.sb-me-state.on{color:var(--ok);}
.sb-me-state.on i{background:var(--ok);box-shadow:0 0 0 2px rgba(74,222,128,.2);}

.sb-search{padding:10px var(--pad) 8px;flex-shrink:0;display:flex;align-items:center;gap:8px;}
.sb-search-inner{display:flex;align-items:center;gap:8px;background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:0 10px;height:var(--ctl-h);transition:border-color .2s,box-shadow .2s;flex:1;min-width:0;}
.sb-search-inner:focus-within{border-color:var(--accent-focus);box-shadow:0 0 0 3px var(--accent-ring);}
.sb-search-inner i{font-size:16px;color:var(--muted);flex-shrink:0;}
.sb-search-inner input{flex:1;background:none;border:none;outline:none;font-size:.76rem;color:var(--ui-text,#fff);min-width:0;height:100%;}
.sb-search-inner input::placeholder{color:var(--muted);}
.sb-search-clear{display:none;background:none;border:none;color:var(--muted);font-size:16px;padding:0;}
.sb-search-clear:hover{color:var(--ui-text,#fff);}
.sb-search-inner.has-q .sb-search-clear{display:flex;}
.sb-select-toggle{flex-shrink:0;width:var(--ctl-h);height:var(--ctl-h);border-radius:var(--r);background:var(--surface);border:1px solid var(--line);display:flex;align-items:center;justify-content:center;font-size:17px;color:var(--sub);transition:all .2s;}
.sb-select-toggle:hover{background:var(--surface-2);color:var(--ui-text,#fff);}
.sb-select-toggle.active{border-color:var(--accent-border);background:var(--accent-soft);color:var(--accent-text);}

.sb-tabs{display:flex;gap:3px;margin:0 var(--pad) 8px;padding:3px;border:1px solid var(--line);border-radius:var(--r);background:var(--surface);flex-shrink:0;}
.sb-tab{flex:1;height:calc(var(--act-h) + 2px);border:none;border-radius:var(--act-r);background:transparent;color:var(--sub);font-size:.7rem;font-weight:600;display:flex;align-items:center;justify-content:center;gap:6px;transition:background .2s,color .2s;}
.sb-tab:hover{color:var(--ui-text,#fff);}
.sb-tab.active{background:var(--accent);color:var(--on-accent);}
.sb-tab .tn{font-size:.58rem;font-weight:700;padding:1px 6px;border-radius:99px;background:rgba(var(--fg),.14);color:var(--ui-text,#fff);}
.sb-tab.active .tn{background:rgba(0,0,0,.25);color:#000;}
.sb-tab .tn:empty{display:none;}

.conv-list,.search-results{flex:1;overflow-y:auto;overflow-x:hidden;min-height:0;}
/* the last chat / group in the list scrolls ABOVE the bottom nav (the nav height is measured by the script below) */
.conv-list,.search-results{padding-bottom:calc(var(--ms-nav-pad,0px) + env(safe-area-inset-bottom,0px) + 8px);}
.sb-section{font-size:.6rem;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);padding:14px var(--pad) 8px;}
.conv-item{display:flex;align-items:center;gap:12px;padding:clamp(10px,2.8vw,12px) var(--pad);border-top:1px solid var(--line);cursor:pointer;transition:background .18s;position:relative;}
.conv-item:last-child{border-bottom:1px solid var(--line);}
.conv-item:hover{background:rgba(var(--fg),.04);}
.conv-item:active{background:rgba(var(--fg),.06);}
.conv-item.active{background:var(--accent-soft);box-shadow:inset 2px 0 0 var(--accent);}
.conv-item.leaving{opacity:0;transform:scale(.96);max-height:0;padding-top:0;padding-bottom:0;overflow:hidden;transition:all .35s ease;}
.conv-checkbox{width:0;height:20px;flex-shrink:0;overflow:hidden;display:flex;align-items:center;justify-content:center;transition:width .2s ease;}
.ms-sidebar.select-mode .conv-checkbox{width:22px;margin-right:-2px;}
.conv-checkbox .box{width:20px;height:20px;border-radius:var(--act-r);border:1.5px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:13px;color:transparent;transition:all .15s;flex-shrink:0;}
.conv-item.selected .conv-checkbox .box{background:var(--accent);border-color:var(--accent);color:#000;}
.conv-avatar{position:relative;flex-shrink:0;}
.conv-avatar img{width:clamp(42px,11vw,48px);height:clamp(42px,11vw,48px);border-radius:50%;object-fit:cover;border:2px solid var(--accent);}
.conv-avatar-lock{position:absolute;bottom:-2px;right:-2px;width:16px;height:16px;background:var(--accent);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:9px;color:#000;border:2px solid var(--ring,#070924);}
.online-dot{position:absolute;right:0;bottom:1px;width:11px;height:11px;border-radius:50%;background:var(--ok);border:2px solid var(--ring,#070924);box-shadow:0 0 8px rgba(74,222,128,.55);}
.conv-body{flex:1;min-width:0;}
.conv-name{display:flex;align-items:center;gap:5px;font-size:.8rem;font-weight:600;color:var(--ui-text,#fff);min-width:0;}
.conv-name-t{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0;}
.vbadge{flex-shrink:0;color:var(--red,#FF2D2D);margin-left:3px;vertical-align:-.15em;}
.susp-av{display:block;border-radius:50%;background:rgba(var(--fg,255,255,255),.1);border:2px solid var(--accent);box-sizing:border-box}
.conv-avatar .susp-av{width:clamp(42px,11vw,48px);height:clamp(42px,11vw,48px)}
.chat-header-avatar .susp-av{width:clamp(34px,9vw,38px);height:clamp(34px,9vw,38px)}
.ci-av .susp-av{width:84px;height:84px}
.es-avatar.susp-av{display:block;margin-left:auto;margin-right:auto}
.susp-txt{color:var(--accent)!important;font-weight:700}
.conv-last{font-size:.68rem;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px;line-height:1.4;}
.conv-last.unread{color:var(--ui-text,#fff);font-weight:600;}
.conv-meta{display:flex;flex-direction:column;align-items:flex-end;gap:5px;flex-shrink:0;}
.conv-time{font-size:.6rem;color:var(--muted);}
.conv-badge{background:var(--accent);color:var(--on-accent);font-size:.6rem;font-weight:700;padding:2px 7px;border-radius:99px;min-width:18px;text-align:center;}
.request-tag{font-size:.54rem;font-weight:700;padding:1px 6px;border-radius:var(--act-r);background:var(--accent-soft);border:1px solid var(--accent-border);color:var(--accent-text);letter-spacing:.3px;margin-left:2px;flex-shrink:0;}
.sb-empty{text-align:center;padding:48px 24px;color:var(--muted);font-size:.74rem;line-height:1.6;}
.sb-empty i{font-size:34px;display:block;margin-bottom:10px;opacity:.35;}
.sb-empty b{display:block;color:var(--ui-text,#fff);font-size:.8rem;margin-bottom:3px;}

/* search results (inside conversations) */
.sr-item .conv-last mark,.sr-snip mark{background:var(--accent-fill);color:var(--ui-text,#fff);border-radius:2px;padding:0 1px;}
.sr-snip{font-size:.68rem;color:var(--sub);margin-top:2px;line-height:1.45;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
.sr-snip i{color:var(--accent-text);margin-right:4px;font-size:.8rem;vertical-align:-1px;}
.sr-skel{display:flex;gap:12px;padding:13px var(--pad);border-top:1px solid var(--line);}
.sk-bar{border-radius:var(--r);background:linear-gradient(90deg,rgba(var(--fg),.04) 25%,rgba(var(--fg),.09) 50%,rgba(var(--fg),.04) 75%);background-size:600px 100%;animation:shimmer 1.5s infinite linear;}
@keyframes shimmer{from{background-position:-300px 0}to{background-position:300px 0}}

/* ══ CHAT AREA ══ */
.chat-area{flex:1;display:flex;flex-direction:column;height:100%;position:relative;overflow:hidden;min-width:0;}
.chat-bg-layer{position:absolute;inset:0;pointer-events:none;z-index:0;}
.chat-header{padding:0 10px 0 6px;gap:6px;}
.chat-header-back{display:none;}
.chat-header-avatar{position:relative;flex-shrink:0;margin:0 4px 0 2px;}
.chat-header-avatar img{width:clamp(34px,9vw,38px);height:clamp(34px,9vw,38px);border-radius:50%;object-fit:cover;border:2px solid var(--accent);}
.chat-header-avatar .online-dot{border-color:var(--ring,#080a26);}
.chat-header-info{flex:1;min-width:0;padding-left:2px;}
.chat-header-name{font-size:.86rem;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:flex;align-items:center;gap:5px;line-height:1.25;}
.chat-header-status{font-size:.62rem;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.4;}
.chat-header-status .live{color:var(--ok);font-weight:600;}
.chat-header-actions{display:flex;gap:2px;flex-shrink:0;position:relative;}

.bg-picker,.more-menu{display:none;position:absolute;top:calc(var(--topbar-h) - 4px);right:10px;background:var(--panel);border:1px solid var(--border);border-radius:var(--r);z-index:100;box-shadow:0 16px 48px rgba(0,0,0,.7);}
.bg-picker{padding:14px;width:260px;}
.bg-picker.open,.more-menu.open{display:block;animation:dropIn .16s ease;}
.bg-picker-title{font-size:.62rem;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);margin-bottom:12px;}
.bg-upload-box{border:1px dashed var(--border);border-radius:var(--r);padding:26px 16px;display:flex;flex-direction:column;align-items:center;gap:6px;cursor:pointer;transition:border-color .2s,background .2s;}
.bg-upload-box:hover{border-color:var(--accent-border);background:var(--accent-soft);}
.bg-upload-icon{font-size:26px;}
.bg-upload-text{font-size:.74rem;font-weight:600;color:var(--ui-text,#fff);}
.bg-upload-sub{font-size:.62rem;color:var(--muted);}
.bg-preview-wrap{display:flex;flex-direction:column;gap:10px;}
.bg-preview-box{width:100%;height:130px;border-radius:var(--r);overflow:hidden;background:#000;position:relative;}
.bg-preview-box img,.bg-preview-box video{width:100%;height:100%;object-fit:cover;}
.bg-preview-actions{display:flex;gap:6px;}
.bg-apply-btn,.bg-change-btn,.bg-remove-btn{flex:1;height:var(--act-h);border-radius:var(--act-r);border:none;font-size:.68rem;font-weight:700;transition:opacity .2s,background .2s;}
.bg-apply-btn{background:var(--accent);color:var(--on-accent);}
.bg-change-btn{background:rgba(var(--fg),.1);color:var(--ui-text,#fff);}
.bg-remove-btn{background:var(--danger-soft);color:var(--danger);}
.bg-apply-btn:hover{background:var(--accent-hover);}
.bg-change-btn:hover{background:rgba(var(--fg),.2);}
.more-menu{min-width:200px;overflow-x:hidden;overflow-y:auto;padding:4px 0;max-height:calc(100vh - var(--topbar-h) - 24px);max-height:calc(100dvh - var(--topbar-h) - 24px);overscroll-behavior:contain;-webkit-overflow-scrolling:touch;touch-action:pan-y;}
.bg-picker{max-height:calc(100vh - var(--topbar-h) - 24px);max-height:calc(100dvh - var(--topbar-h) - 24px);overflow-y:auto;overscroll-behavior:contain;-webkit-overflow-scrolling:touch;touch-action:pan-y;}

.msg-select-bar{display:none;align-items:center;gap:8px;padding:9px var(--pad);background:var(--accent-soft);border-bottom:1px solid var(--accent-border);flex-shrink:0;position:relative;z-index:10;}
.msg-select-bar.active{display:flex;}
.msg-select-count{font-size:.74rem;font-weight:700;color:var(--ui-text,#fff);flex:1;}
.msg-select-close{width:var(--act-h);height:var(--act-h);border-radius:var(--act-r);background:rgba(var(--fg),.1);border:none;display:flex;align-items:center;justify-content:center;font-size:16px;color:var(--ui-text,#fff);}
.msg-select-close:hover{background:rgba(var(--fg),.2);}

.pinned-bar{display:none;position:relative;z-index:5;padding:8px var(--pad);background:var(--pin-bg,#0b0e2c);border-bottom:1px solid var(--line);font-size:.7rem;color:var(--muted);align-items:center;gap:9px;cursor:pointer;}
.pinned-bar.visible{display:flex;}
.pinned-bar>i:first-child{color:var(--accent-text);font-size:15px;}
.pinned-bar .pin-t{min-width:0;flex:1;}
.pinned-bar strong{display:block;color:var(--ui-text,#fff);font-size:.62rem;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;}
.pinned-bar span{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--sub);}
.pinned-bar-close{font-size:17px;flex-shrink:0;color:var(--muted);}
.pinned-bar-close:hover{color:var(--ui-text,#fff);}

.messages-window{flex:1;overflow-y:auto;overflow-x:hidden;padding:18px clamp(20px,5vw,30px);display:flex;flex-direction:column;gap:2px;position:relative;z-index:1;overscroll-behavior:contain;}
.date-divider{display:flex;align-items:center;gap:12px;margin:18px 0 10px;}
.date-divider::before,.date-divider::after{content:'';flex:1;height:1px;background:var(--line);}
.date-divider span{font-size:.58rem;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;color:var(--muted);white-space:nowrap;}
.msg-row{display:flex;flex-direction:column;margin-bottom:2px;position:relative;transition:opacity .35s ease,transform .35s ease,max-height .35s ease,margin .35s ease;max-height:900px;}
.msg-row.sent{align-items:flex-end;}
.msg-row.recv{align-items:flex-start;}
.msg-row.new-group{margin-top:12px;}
.msg-row.msg-selectable{cursor:pointer;}
.msg-row.msg-selected .bubble{box-shadow:0 0 0 2px var(--accent) !important;opacity:.88;}
.msg-row .msg-select-check{display:none;width:20px;height:20px;border-radius:var(--act-r);border:1.5px solid var(--border);align-items:center;justify-content:center;font-size:12px;color:transparent;transition:all .15s;flex-shrink:0;margin-bottom:2px;position:absolute;top:8px;}
.msg-row.sent .msg-select-check{left:0;}
.msg-row.recv .msg-select-check{right:0;}
.msg-selectable .msg-select-check{display:flex;}
.msg-row.msg-selected .msg-select-check{background:var(--accent);border-color:var(--accent);color:#000;}
.msg-row.removing{opacity:0;transform:scale(.9);max-height:0;margin:0 !important;overflow:hidden;pointer-events:none;}
.msg-row.flash .bubble{animation:flashMsg 1.6s ease;}
@keyframes flashMsg{0%,100%{box-shadow:0 0 0 0 rgba(255,45,45,0)}20%,60%{box-shadow:0 0 0 4px var(--accent-glow)}}
.msg-row.pending{opacity:.62;}
.msg-row.failed .bubble{box-shadow:0 0 0 1px var(--danger);}
.msg-row.fresh{animation:msgIn .28s cubic-bezier(.22,1,.36,1);}
@keyframes msgIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
.bubble.fading-deleted{animation:fadeToDeleted .4s ease forwards;}
@keyframes fadeToDeleted{0%{opacity:1;transform:scale(1);}40%{opacity:0;transform:scale(.94);}60%{opacity:0;transform:scale(.94);}100%{opacity:.5;transform:scale(1);}}

.msg-avatar{width:28px;height:28px;border-radius:50%;object-fit:cover;border:2px solid var(--accent);flex-shrink:0;align-self:flex-end;margin-bottom:2px;}
.msg-wrap{display:flex;align-items:flex-end;gap:6px;max-width:100%;position:relative;}
.bubble{max-width:min(68vw,500px);padding:5px 11px;border-radius:var(--r-bubble);font-size:var(--bubble-fs);line-height:1.38;position:relative;word-break:break-word;overflow-wrap:anywhere;cursor:pointer;transition:transform .15s,box-shadow .2s;-webkit-touch-callout:none;}
.bubble:active{transform:scale(.985);}
.bubble-text a{color:inherit;text-decoration:underline;text-underline-offset:2px;}
.b-sent{background:#E02424;color:#000;box-shadow:0 3px 12px rgba(255,45,45,.16);}
.b-sent .reply-preview{background:rgba(0,0,0,.12);border-left-color:#000;color:rgba(0,0,0,.7);}
.b-sent .reply-preview strong{color:#000;}
.b-sent .msg-meta{color:var(--muted);}
.b-recv{background:var(--bubble-recv,#151a44);border:1px solid rgba(var(--fg),.07);color:var(--ui-text,#fff);}
.bubble.deleted{background:transparent !important;border:1px dashed var(--border) !important;box-shadow:none !important;color:var(--muted) !important;font-style:italic;}
.bubble.emoji-only{background:transparent !important;border:none !important;box-shadow:none !important;padding:0 2px;font-size:2.3rem;line-height:1.15;}
.reply-preview{background:var(--rp-bg,rgba(0,0,0,.22));border-left:2px solid rgba(var(--fg),.55);border-radius:10px;padding:5px 9px;font-size:.68rem;color:rgba(var(--fg),.72);margin-bottom:6px;cursor:pointer;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;}
.b-recv .reply-preview{background:rgba(var(--fg),.06);border-left-color:var(--accent);}
.reply-preview strong{color:var(--ui-text,#fff);display:block;font-size:.62rem;font-weight:700;margin-bottom:1px;}
.bubble-media{width:100%;max-width:300px;height:auto;max-height:380px;border-radius:4px;display:block;object-fit:cover;cursor:zoom-in;}
.bubble-media + .bubble-text{margin-top:6px;}
video.bubble-media{background:#000;cursor:default;}

.msg-meta{display:flex;align-items:center;gap:4px;font-size:.56rem;color:var(--muted);margin-top:3px;padding:0 3px;}
.seen-tick{font-size:13px;color:var(--muted-2);}
.seen-tick.seen{color:var(--accent-text);}
.msg-reactions{display:flex;flex-wrap:wrap;gap:4px;margin-top:4px;padding:0 2px;}
.msg-reactions:empty{display:none;}
.msg-reaction-pill{display:flex;align-items:center;gap:4px;background:var(--surface-2);border:1px solid var(--line);border-radius:99px;padding:2px 8px;font-size:.78rem;cursor:pointer;transition:all .2s;user-select:none;}
.msg-reaction-pill:hover{background:var(--accent-soft);border-color:var(--accent-border);}
.msg-reaction-pill.mine{background:var(--accent-soft);border-color:var(--accent-border);}
.msg-reaction-pill .r-count{font-size:.62rem;font-weight:700;color:var(--sub);}
.msg-chevron{width:24px;height:24px;border-radius:var(--act-r);display:flex;align-items:center;justify-content:center;background:rgba(var(--fg),.08);border:none;color:var(--sub);font-size:15px;cursor:pointer;flex-shrink:0;opacity:0;transform:translateY(3px);transition:opacity .15s,transform .15s,color .15s,background .15s;margin-bottom:3px;}
.msg-row.menu-open .msg-chevron,.msg-chevron.touch-visible{opacity:1;transform:none;}
@media (hover:hover){.msg-row:hover .msg-chevron{opacity:1;transform:none;}}   /* touch screens get no sticky hover, so the first tap opens things */
.msg-chevron:hover,.msg-chevron.active{background:var(--accent-soft);color:var(--accent-text);}
.msg-chevron.active i{transform:rotate(180deg);}
.msg-chevron i{transition:transform .2s ease;display:block;}
.msg-dropdown{position:absolute;z-index:200;min-width:196px;background:var(--panel);border:1px solid var(--border);border-radius:var(--r);overflow:hidden;box-shadow:0 16px 48px rgba(0,0,0,.75);display:none;top:calc(100% + 6px);}
.msg-row.sent .msg-dropdown{right:0;}
.msg-row.recv .msg-dropdown{left:0;}
.msg-dropdown.up{top:auto;bottom:calc(100% + 6px);}
.msg-dropdown.open{display:block;animation:dropIn .16s ease;}
@keyframes dropIn{from{opacity:0;transform:translateY(-6px) scale(.97);}to{opacity:1;transform:none;}}
.dd-react-row{display:flex;align-items:center;justify-content:space-around;padding:8px 8px 6px;border-bottom:1px solid var(--line);}
.dd-react-emoji{font-size:20px;cursor:pointer;transition:transform .15s,background .15s;width:30px;height:30px;display:flex;align-items:center;justify-content:center;border-radius:var(--act-r);}
.dd-react-emoji:hover{transform:scale(1.2);background:rgba(var(--fg),.08);}
.dd-item{display:flex;align-items:center;gap:10px;padding:10px 14px;font-size:.74rem;font-weight:500;cursor:pointer;transition:background .15s;color:var(--ui-text,#fff);white-space:nowrap;}
.dd-item i{font-size:16px;color:var(--muted);width:18px;text-align:center;}
.dd-item:hover{background:rgba(var(--fg),.05);}
.dd-item:hover i{color:var(--accent-text);}
.dd-item.danger,.dd-item.danger i{color:var(--danger);}
.dd-item.danger:hover{background:var(--danger-soft);}
.dd-item.disabled{opacity:.35;pointer-events:none;}
.dd-divider{height:1px;background:var(--line);margin:3px 0;}
.edited-tag{font-size:.56rem;opacity:.6;font-style:italic;margin-left:4px;}

/* date + status pills */
.new-pill{position:absolute;left:50%;bottom:calc(var(--cmp-h,64px) + 12px);transform:translateX(-50%) translateY(10px);z-index:12;display:none;align-items:center;gap:6px;height:var(--act-h);padding:0 12px;border:none;border-radius:999px;background:var(--accent);color:var(--on-accent);font-size:.7rem;font-weight:700;box-shadow:0 8px 24px rgba(0,0,0,.5);cursor:pointer;}
.new-pill.show{display:flex;animation:msgIn .25s ease forwards;}
.drop-hint{position:absolute;inset:10px;z-index:40;display:none;align-items:center;justify-content:center;flex-direction:column;gap:8px;border:1.5px dashed var(--accent-border);border-radius:var(--r);background:var(--ov2,rgba(7,9,36,.88));color:var(--ui-text,#fff);font-size:.8rem;font-weight:600;pointer-events:none;}
.drop-hint i{font-size:32px;color:var(--accent-text);}
.chat-area.dragging .drop-hint{display:flex;}

/* ══ COMPOSER ══ */
.input-area{position:relative;z-index:20;border-top:1px solid rgba(var(--fg),.14);flex-shrink:0;background:var(--bg-grad);background-attachment:fixed;padding-bottom:calc(env(safe-area-inset-bottom) * .4);}
.reply-bar{display:none;align-items:center;gap:10px;padding:8px var(--pad);border-bottom:1px solid var(--line);}
.reply-bar.visible{display:flex;}
.reply-bar>i:first-child{color:var(--accent-text);font-size:18px;flex-shrink:0;}
.reply-bar-content{flex:1;min-width:0;border-left:2px solid var(--accent);padding-left:9px;}
.reply-bar-who{font-size:.64rem;color:var(--accent-text);font-weight:700;}
.reply-bar-text{font-size:.7rem;color:var(--sub);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.reply-bar-close,.attach-remove{font-size:19px;color:var(--muted);cursor:pointer;transition:color .2s;flex-shrink:0;background:none;border:none;display:flex;}
.reply-bar-close:hover,.attach-remove:hover{color:var(--ui-text,#fff);}
.attach-preview{display:none;align-items:center;gap:10px;padding:9px var(--pad);border-bottom:1px solid var(--line);}
.attach-preview.visible{display:flex;}
.attach-strip{display:flex;gap:8px;overflow-x:auto;flex:1;min-width:0;padding:2px 0;scrollbar-width:thin;}
.at-t{position:relative;width:54px;height:54px;border-radius:14px;overflow:hidden;flex-shrink:0;background:#000;border:1px solid var(--border);}
.at-t img,.at-t video{width:100%;height:100%;object-fit:cover;display:block;}
.at-t .at-x{position:absolute;top:2px;right:2px;width:18px;height:18px;border-radius:50%;background:rgba(0,0,0,.72);border:none;color:#fff;font-size:12px;display:flex;align-items:center;justify-content:center;cursor:pointer;}
.at-t .at-v{position:absolute;left:3px;bottom:3px;font-size:13px;color:#fff;}
.at-add{width:54px;height:54px;border-radius:14px;border:1.5px dashed var(--accent-border);background:var(--accent-soft);color:var(--accent-text);font-size:22px;display:flex;align-items:center;justify-content:center;flex-shrink:0;cursor:pointer;}
.attach-meta{font-size:.62rem;color:var(--muted);min-width:70px;}
.attach-progress{height:3px;border-radius:99px;background:rgba(var(--fg),.1);margin-top:5px;overflow:hidden;display:none;}
.attach-progress.on{display:block;}
.attach-progress i{display:block;height:100%;width:0;background:var(--accent);transition:width .15s;}

.input-row{display:flex;align-items:flex-end;gap:8px;padding:clamp(8px,2.2vw,10px) var(--pad);}
.cmp-box{flex:1;min-width:0;display:flex;align-items:center;gap:6px;background:var(--field,#0b0e30);border:1px solid rgba(var(--fg),.18);border-radius:999px;padding:5px 6px 5px 5px;min-height:46px;transition:border-color .2s,box-shadow .2s;}
.cmp-box:focus-within{border-color:var(--accent-focus);box-shadow:0 0 0 3px var(--accent-ring);}
.cmp-cam{width:34px;height:34px;border-radius:50%;background:var(--accent);border:none;color:var(--on-accent);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;cursor:pointer;transition:background .2s,transform .15s;align-self:flex-end;}
.cmp-cam:hover{background:var(--accent-hover);}
.cmp-cam:active{transform:scale(.92);}
.cmp-right{display:flex;align-items:center;gap:0;flex-shrink:0;align-self:flex-end;}
.cmp-ico{width:34px;height:34px;border-radius:50%;background:transparent;border:none;display:flex;align-items:center;justify-content:center;font-size:22px;color:var(--ui-text,#fff);flex-shrink:0;transition:background .2s,color .2s,transform .15s;cursor:pointer;}
.cmp-ico:hover{background:rgba(var(--fg),.08);color:var(--accent-text);}
.cmp-ico:active{transform:scale(.9);}
.cmp-ico.on{color:var(--accent-text);}
.cmp-heart:hover{color:var(--accent-text);}
#msgInput{flex:1;min-width:0;background:none;border:none;outline:none;font-size:.84rem;color:var(--ui-text,#fff);resize:none;max-height:120px;min-height:34px;line-height:1.35;padding:7px 4px;scrollbar-width:none;align-self:center;}
#msgInput::-webkit-scrollbar{display:none;}
#msgInput::placeholder{color:rgba(var(--fg),.55);}
.send-pill{display:none;height:34px;padding:0 16px;border-radius:999px;background:var(--accent);border:none;color:var(--on-accent);font-size:.78rem;font-weight:700;cursor:pointer;flex-shrink:0;align-self:flex-end;transition:background .2s,transform .15s,opacity .2s;}
.send-pill:hover{background:var(--accent-hover);}
.send-pill:active{transform:scale(.95);}
.send-pill:disabled{opacity:.5;cursor:default;}
.cmp-box.has-text .send-pill,.cmp-box.has-attach .send-pill{display:block;}
.cmp-box.has-text .cmp-idle,.cmp-box.has-attach .cmp-idle{display:none;}
.cmp-count{font-size:.58rem;color:var(--muted);align-self:center;padding:0 4px;display:none;}
.cmp-count.show{display:block;}
.cmp-count.warn{color:var(--danger);}

.emoji-picker-wrap{display:none;position:absolute;bottom:calc(100% + 8px);left:var(--pad);background:var(--panel);border:1px solid var(--border);border-radius:var(--r);padding:12px;z-index:100;width:330px;max-width:calc(100vw - 24px);box-shadow:0 16px 48px rgba(0,0,0,.75);}
.emoji-picker-wrap.open{display:flex;flex-direction:column;animation:dropIn .16s ease;}
.emoji-tabs{display:flex;gap:3px;overflow-x:auto;padding-bottom:8px;margin-bottom:8px;border-bottom:1px solid var(--line);scrollbar-width:none;}
.emoji-tabs::-webkit-scrollbar{display:none;}
.emoji-tab{flex-shrink:0;width:32px;height:32px;border-radius:var(--act-r);display:flex;align-items:center;justify-content:center;font-size:16px;cursor:pointer;background:transparent;border:1px solid transparent;transition:all .15s;}
.emoji-tab:hover{background:rgba(var(--fg),.06);}
.emoji-tab.active{border-color:var(--accent-border);background:var(--accent-soft);}
.emoji-search{display:flex;align-items:center;gap:6px;background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:0 10px;height:32px;margin-bottom:8px;}
.emoji-search i{font-size:14px;color:var(--muted);}
.emoji-search input{flex:1;background:none;border:none;outline:none;font-size:.72rem;color:var(--ui-text,#fff);height:100%;}
.emoji-search input::placeholder{color:var(--muted);}
.emoji-scroll{max-height:220px;overflow-y:auto;}
.emoji-cat-label{font-size:.58rem;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;color:var(--muted);margin:6px 2px 4px;}
.emoji-grid{display:flex;flex-wrap:wrap;gap:2px;}
.ep-emoji{font-size:22px;cursor:pointer;transition:transform .15s,background .15s;width:36px;height:36px;display:flex;align-items:center;justify-content:center;border-radius:var(--act-r);}
.ep-emoji:hover{transform:scale(1.2);background:rgba(var(--fg),.08);}
.emoji-empty{text-align:center;padding:30px 10px;color:var(--muted);font-size:.72rem;}
.sticker-picker{display:none;position:absolute;bottom:calc(100% + 8px);left:calc(var(--pad) + 40px);background:var(--panel);border:1px solid var(--border);border-radius:var(--r);padding:12px;z-index:100;width:220px;box-shadow:0 16px 48px rgba(0,0,0,.75);}
.sticker-picker.open{display:block;animation:dropIn .16s ease;}
.sticker-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;}
.sticker{font-size:28px;cursor:pointer;text-align:center;transition:transform .15s;border-radius:var(--act-r);padding:2px 0;}
.sticker:hover{transform:scale(1.2);background:rgba(var(--fg),.06);}

.composer-locked{display:flex;align-items:flex-start;gap:12px;margin:clamp(9px,2.6vw,12px) var(--pad);padding:12px 14px;border:1px solid var(--accent-border);background:var(--accent-soft);border-radius:var(--r);}
.composer-locked>i{font-size:20px;color:var(--accent-text);flex-shrink:0;margin-top:1px;}
.composer-locked b{display:block;font-size:.76rem;color:var(--ui-text,#fff);margin-bottom:2px;}
.composer-locked span{display:block;font-size:.68rem;line-height:1.55;color:rgba(var(--fg),.72);}
.composer-locked a{color:var(--accent-text);font-weight:600;text-decoration:underline;text-underline-offset:2px;}

.edit-inline-bar{display:none;position:absolute;bottom:0;left:0;right:0;z-index:60;background:var(--bg-grad);background-attachment:fixed;border-top:1px solid var(--accent-border);padding:10px var(--pad) calc(10px + env(safe-area-inset-bottom));gap:8px;align-items:flex-end;backdrop-filter:blur(20px);}
.edit-inline-bar.visible{display:flex;}
.edit-label{font-size:.62rem;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;color:var(--accent-text);flex-shrink:0;padding-bottom:11px;}
.edit-textarea{flex:1;background:var(--surface);border:1px solid var(--accent-focus);border-radius:var(--r);padding:8px 11px;font-size:.82rem;color:var(--ui-text,#fff);resize:none;max-height:120px;min-height:var(--ctl-h);outline:none;scrollbar-width:none;line-height:1.45;box-shadow:0 0 0 3px var(--accent-ring);}
.edit-save-btn,.edit-cancel-btn{width:var(--ctl-h);height:var(--ctl-h);border-radius:var(--r);border:none;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;transition:all .2s;}
.edit-save-btn{background:var(--accent);color:var(--on-accent);}
.edit-save-btn:hover{background:var(--accent-hover);}
.edit-cancel-btn{background:rgba(var(--fg),.1);color:var(--ui-text,#fff);}
.edit-cancel-btn:hover{background:rgba(var(--fg),.2);}

/* empty states */
.no-chat{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;color:var(--muted);text-align:center;padding:40px 28px;position:relative;z-index:1;}
.no-chat .nc-ic{width:64px;height:64px;border-radius:var(--r);background:var(--accent-soft);border:1px solid var(--accent-border);display:flex;align-items:center;justify-content:center;margin-bottom:16px;}
.no-chat .nc-ic i{font-size:30px;color:var(--accent-text);}
.no-chat h2{font-size:.98rem;font-weight:700;color:var(--ui-text,#fff);margin-bottom:5px;}
.no-chat p{font-size:.72rem;color:var(--muted);line-height:1.6;max-width:300px;margin-bottom:14px;}
.mw-empty{margin:auto;text-align:center;padding:30px 20px;}

/* ══ RIGHT INFO COLUMN (wide screens, like the settings page) ══ */
.chat-info{display:none;}
@media (min-width:1180px){
  .chat-info{display:flex;flex-direction:column;width:var(--info-w);min-width:var(--info-w);border-left:1px solid var(--line);background:var(--bg-grad);background-attachment:fixed;overflow-y:auto;position:relative;z-index:4;}
}
.ci-hero{display:flex;flex-direction:column;align-items:center;text-align:center;padding:26px var(--pad) 18px;border-bottom:1px solid var(--line);}
.ci-hero .ci-av{position:relative;margin-bottom:11px;}
.ci-hero img.av{width:84px;height:84px;border-radius:50%;object-fit:cover;border:2px solid var(--accent);}
.ci-hero .online-dot{width:15px;height:15px;right:3px;bottom:3px;border-width:3px;border-color:var(--ring,#080a26);}
.ci-name{font-size:.92rem;font-weight:700;display:flex;align-items:center;gap:6px;justify-content:center;max-width:100%;}
.ci-name span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.ci-nick{font-size:.7rem;color:var(--accent-text);margin-top:1px;}
.ci-status{font-size:.64rem;color:var(--muted);margin-top:6px;}
.ci-status .live{color:var(--ok);font-weight:600;}
.ci-btn{margin-top:14px;width:100%;height:var(--ctl-h);display:flex;align-items:center;justify-content:center;gap:7px;border-radius:var(--r);background:var(--accent);color:var(--on-accent);font-size:.74rem;font-weight:700;transition:background .2s,transform .15s;}
.ci-btn:hover{background:var(--accent-hover);}
.ci-btn:active{transform:scale(.98);}
.ci-block{padding:4px var(--pad) 8px;}
.ci-stats{display:flex;gap:8px;padding:14px var(--pad) 4px;}
.stat-pill{flex:1;background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:10px 10px;display:flex;flex-direction:column;gap:3px;transition:background .2s,border-color .2s;}
.stat-pill:hover{background:var(--accent-soft);border-color:var(--accent-border);}
.stat-pill-num{font-size:1.05rem;font-weight:700;color:var(--ui-text,#fff);line-height:1;}
.stat-pill-label{font-size:.54rem;font-weight:700;letter-spacing:1.1px;text-transform:uppercase;color:var(--muted);}
.ci-h{font-size:.6rem;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);padding:16px var(--pad) 8px;}
.ci-media{display:grid;grid-template-columns:repeat(3,1fr);gap:4px;padding:0 var(--pad);}
.ci-media a{display:block;aspect-ratio:1;border-radius:var(--act-r);overflow:hidden;background:rgba(var(--fg),.05);border:1px solid var(--line);}
.ci-media img,.ci-media video{width:100%;height:100%;object-fit:cover;}
.ci-note{display:flex;gap:9px;margin:16px var(--pad) 18px;padding:10px 11px;border-radius:var(--act-r);background:var(--accent-soft);border:1px solid var(--accent-border);font-size:.64rem;line-height:1.6;color:rgba(var(--fg),.8);}
.ci-note i{color:var(--accent-text);font-size:1rem;flex-shrink:0;margin-top:1px;}
.ci-empty{padding:0 var(--pad);font-size:.66rem;color:var(--muted);}

/* ══ CENTERED MODALS (info, confirm, new message, lightbox) ══ */
.info-overlay,.confirm-overlay,.nm-overlay{display:none;position:fixed;inset:0;background:var(--ov,rgba(1,2,24,.78));backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);z-index:600;align-items:center;justify-content:center;padding:16px;}
.info-overlay.open,.confirm-overlay.open,.nm-overlay.open{display:flex;animation:fadeBg .2s ease;}
@keyframes fadeBg{from{opacity:0;}to{opacity:1;}}
@keyframes popIn{from{opacity:0;transform:translateY(10px) scale(.98);}to{opacity:1;transform:none;}}
.info-panel,.confirm-box,.nm-box{background:var(--panel);border:1px solid var(--border);border-radius:var(--r);box-shadow:0 24px 64px rgba(0,0,0,.85);animation:popIn .22s cubic-bezier(.22,1,.36,1);width:340px;max-width:100%;overflow:hidden;}
.modal-h{display:flex;align-items:center;gap:8px;padding:0 6px 0 var(--pad);height:46px;border-bottom:1px solid rgba(var(--fg),.14);}
.modal-h h3{font-size:.84rem;font-weight:700;flex:1;display:flex;align-items:center;gap:8px;}
.modal-h h3 i{color:var(--accent-text);font-size:1.05rem;}
.modal-b{padding:8px var(--pad) var(--pad);}
.info-row{display:flex;justify-content:space-between;gap:12px;padding:10px 0;border-bottom:1px solid var(--line);}
.info-row:last-of-type{border-bottom:none;}
.info-label{font-size:.66rem;color:var(--muted);}
.info-val{font-size:.72rem;font-weight:600;text-align:right;}
.info-close{margin-top:12px;width:100%;height:var(--ctl-h);background:rgba(var(--fg),.1);border:none;border-radius:var(--r);color:var(--ui-text,#fff);font-size:.74rem;font-weight:600;transition:background .2s;}
.info-close:hover{background:rgba(var(--fg),.2);}
.confirm-box{padding:20px var(--pad) var(--pad);text-align:left;width:360px;}
.confirm-box h4{font-size:.92rem;font-weight:700;margin-bottom:6px;}
.confirm-box p{font-size:.72rem;color:var(--sub);margin-bottom:16px;line-height:1.6;}
.confirm-actions{display:flex;gap:8px;}
.confirm-btn{flex:1;height:var(--ctl-h);border-radius:var(--r);font-size:.74rem;font-weight:700;border:none;transition:background .2s,transform .15s;}
.confirm-btn:active{transform:scale(.98);}
.confirm-btn.cancel{background:rgba(var(--fg),.1);color:var(--ui-text,#fff);}
.confirm-btn.cancel:hover{background:rgba(var(--fg),.2);}
.confirm-btn.confirm{background:var(--accent);color:var(--on-accent);}
.confirm-btn.confirm:hover{background:var(--accent-hover);}

.nm-box{width:460px;max-height:min(640px,88vh);display:flex;flex-direction:column;}
.nm-search{margin:10px var(--pad) 6px;}
.nm-list{overflow-y:auto;flex:1;min-height:180px;}
.nm-item{display:flex;align-items:center;gap:12px;padding:10px var(--pad);border-top:1px solid var(--line);cursor:pointer;transition:background .18s;}
.nm-item:hover{background:rgba(var(--fg),.04);}
.nm-item img{width:40px;height:40px;border-radius:50%;object-fit:cover;border:2px solid var(--accent);}
.nm-item .t{flex:1;min-width:0;}
.nm-item .n{font-size:.78rem;font-weight:600;display:flex;align-items:center;gap:5px;}
.nm-item .s{font-size:.64rem;color:var(--muted);}
.nm-item .tag{font-size:.54rem;font-weight:700;padding:1px 6px;border-radius:var(--act-r);background:var(--accent-soft);border:1px solid var(--accent-border);color:var(--accent-text);}
@media (max-width:800px){
  .nm-overlay{padding:0;align-items:stretch}
  .nm-box{width:100%;max-height:none;height:100%;border-radius:0;border:none}
}

.lightbox{display:none;position:fixed;inset:0;z-index:700;background:rgba(1,2,24,.94);align-items:center;justify-content:center;padding:20px;cursor:zoom-out;}
.lightbox.open{display:flex;animation:fadeBg .2s ease;}
.lightbox img{max-width:100%;max-height:100%;border-radius:var(--act-r);object-fit:contain;}

.toast{position:fixed;bottom:90px;left:50%;transform:translateX(-50%) translateY(10px);background:var(--panel);border:1px solid var(--accent-border);color:var(--ui-text,#fff);padding:9px 16px;border-radius:var(--r);font-family:'Poppins',sans-serif;font-size:.72rem;font-weight:500;z-index:99999999;pointer-events:none;box-shadow:0 8px 24px rgba(0,0,0,.6);opacity:0;transition:opacity .3s,transform .3s;max-width:calc(100vw - 32px);text-align:center;}
.toast.show{opacity:1;transform:translateX(-50%) translateY(0);}

/* ══ SHARED POST CARD (inside a chat bubble) — unchanged from before ══ */
/* ═══ SHARED MEME / POST CARD — caption + tag no longer transparent,
   type tag moved below media (just above caption), username shrunk
   and truncated ═══ */
.bubble.shared-meme-bubble{background:transparent !important;border:none !important;padding:0 !important;box-shadow:none !important;}
.shared-meme-card{display:block;width:300px;max-width:80vw;border-radius:14px;overflow:hidden;background:transparent;border:1px solid rgba(var(--fg),.1);text-decoration:none;color:inherit;cursor:pointer;transition:transform .15s ease,border-color .15s ease;-webkit-tap-highlight-color:transparent;}
@media (hover:hover){.shared-meme-card:hover{transform:scale(1.02);border-color:rgba(255,45,45,.4);}}
.shared-meme-card{touch-action:manipulation;}
.shared-meme-card:active{transform:scale(.98);}
.shared-meme-media-wrap{width:100%;aspect-ratio:4/3;background:#000;overflow:hidden;position:relative;}
.shared-meme-media{width:100%;height:100%;object-fit:cover;display:block;}
.sm-owner-pill{position:absolute;top:8px;left:8px;z-index:3;display:flex;align-items:center;gap:6px;background:transparent;padding:0;max-width:calc(100% - 16px);}
.sm-owner-pill img{width:24px;height:24px;border-radius:50%;object-fit:cover;border:1.5px solid #ff2b2b;flex-shrink:0;box-shadow:0 2px 8px rgba(0,0,0,.55);}
.sm-owner-pill .sm-cl{position:relative;display:block;flex:none;width:38px;height:38px;overflow:visible;color:inherit;text-shadow:none}
.sm-owner-pill .sm-cl img{position:absolute;width:66%;height:66%;margin:0;box-sizing:border-box}
.sm-owner-pill .sm-cl.n1 img:not(.co){left:0;bottom:0;z-index:2}
.sm-owner-pill .sm-cl.n1 img.co{right:0;top:0;z-index:1}
.sm-owner-pill .sm-cl.n2 img{width:58%;height:58%}
.sm-owner-pill .sm-cl.n2 img:not(.co){left:21%;top:0;z-index:3}
.sm-owner-pill .sm-cl.n2 img.co:nth-of-type(2){left:0;bottom:0;z-index:2}
.sm-owner-pill .sm-cl.n2 img.co:nth-of-type(3){right:0;bottom:0;z-index:1}
.sm-owner-pill span{color:#fff;font-size:10px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0;font-family:Poppins,'DM Sans',sans-serif;text-shadow:0 2px 6px rgba(0,0,0,.85),0 1px 2px rgba(0,0,0,.9);}
.sm-play-icon{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:44px;height:44px;border-radius:50%;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;z-index:2;pointer-events:none;}
.sm-play-icon i{font-size:20px;color:#fff;}
/* type tag overlays the media, sitting right at its bottom edge (just above the caption), pulled in close to the right side */
.sm-type-tag{position:absolute;bottom:6px;right:3px;z-index:3;display:flex;align-items:center;gap:4px;background:rgba(22,22,24,.72);border-radius:6px;padding:4px 7px 4px 5px;}
.sm-type-tag i{font-size:11px;color:#fff;}
.sm-type-tag span{font-size:9px;font-weight:700;color:#fff;text-transform:uppercase;letter-spacing:.3px;white-space:nowrap;}
.shared-meme-caption{padding:8px 10px 2px;font-size:12px;color:rgba(var(--fg),.85);line-height:1.4;word-break:break-word;background:var(--card,#14163f);}
.shared-meme-tag{display:flex;align-items:center;gap:5px;padding:6px 10px 10px;font-size:10.5px;font-weight:600;color:var(--red);background:var(--card,#14163f);}
.shared-meme-tag i{font-size:13px;}

/* ── multi-media badge on the shared-post chat bubble card ── */
.sm-media-count-badge{position:absolute;bottom:8px;right:8px;z-index:3;display:flex;align-items:center;gap:4px;background:rgba(22,22,24,.72);border-radius:6px;padding:3px 7px;font-size:9.5px;font-weight:700;color:#fff;}
.sm-media-count-badge i{font-size:11px;color:#fff;}


@media (max-width:768px){
  /* inside an open chat the bottom nav bar must not sit on top of the message box (the chat list keeps it) */
  body.ms-chat-open .sidebar:not(.ms-sidebar){display:none !important;}
  body.ms-chat-open{padding-bottom:0 !important;}
}

/* ══ RESPONSIVE ══ */
@media (max-width:1199px){:root{--sidebar-w:320px;}}
@media (max-width:1024px){:root{--sidebar-w:300px;}.bubble{max-width:min(74vw,460px);}}
@media (max-width:768px){
  .ms-sidebar{position:absolute;left:0;top:0;bottom:0;z-index:50;width:100%;min-width:100%;transition:transform .3s cubic-bezier(.22,1,.36,1);border-right:none;}
  .ms-sidebar.hidden{transform:translateX(-100%);pointer-events:none;}
  .chat-area{width:100%;}
  .chat-header-back{display:flex;}
  .bubble{max-width:min(82vw,420px);}
  .bg-picker,.more-menu{right:6px;}
}
@media (max-width:490px){
  :root{--bubble-fs:.71rem;}
  .bubble{max-width:86vw;padding:7px 10px;}
  .msg-avatar{width:28px;height:28px;}
  .emoji-picker-wrap,.sticker-picker{left:8px;right:8px;width:auto;max-width:none;}
  .shared-meme-card{width:230px;max-width:84vw;}
  .shared-meme-caption{font-size:11px;padding:6px 8px 2px;}
  .shared-meme-tag{font-size:9.5px;padding:5px 8px 8px;}
  .bubble-media{max-width:220px;}
}
@media (max-width:360px){.shared-meme-card{width:210px;max-width:82vw;}.chat-header-actions .ac-x:nth-child(2){display:none;}}
@media (max-width:320px){.shared-meme-card{width:195px;max-width:80vw;}.shared-meme-caption{font-size:10px;}.shared-meme-tag{font-size:9px;}}
@media (min-width:1400px){:root{--sidebar-w:380px;--bubble-fs:.77rem;}.bubble{max-width:560px;}.shared-meme-card{width:340px;max-width:420px;}}
@media (max-height:600px){.messages-window{padding-top:10px;padding-bottom:10px;}.date-divider{margin:10px 0 6px;}}
@media (prefers-reduced-motion:reduce){*{transition-duration:.01ms !important;animation-duration:.01ms !important}}

/* chat background (set from the palette button) */
.chat-bg-layer{background-size:cover;background-position:center;}
.chat-bg-layer.has-bg::after{content:'';position:absolute;inset:0;background:rgba(1,2,24,.55);}
.chat-bg-layer video{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;}
.msg-meta .retry{background:none;border:none;color:var(--danger);font-size:.58rem;font-weight:700;padding:0 2px;text-decoration:underline;}
.msg-meta .retry:hover{color:var(--ui-text,#fff);}
.pend-progress{position:absolute;left:10px;right:10px;bottom:0;height:3px;background:rgba(255,255,255,.15);border-radius:99px;overflow:hidden;}
.pend-progress i{display:block;height:100%;width:0;background:#000;transition:width .15s;}


/* ══ PILL BUBBLE TWEAKS ══ */
.bubble.shared-meme-bubble{border-radius:16px;}
.bubble.deleted{border-radius:20px;}
.bubble-media{border-radius:14px;}
@media (max-width:490px){.bubble{padding:6px 12px;}}

/* ══ PHOTO / VIDEO TILES (collage) ══ */
.bubble.bubble-collage{padding:0;background:transparent !important;border:none !important;box-shadow:none !important;overflow:visible;}
.mcol{display:grid;gap:3px;width:min(250px,64vw);border-radius:18px;overflow:hidden;}
.mcol .mt{position:relative;display:block;padding:0;border:none;background:var(--ph,#10143a);overflow:hidden;cursor:pointer;aspect-ratio:1;}
.mcol .mt img,.mcol .mt video{width:100%;height:100%;object-fit:cover;display:block;pointer-events:none;}
.mcol.n1{grid-template-columns:1fr;width:min(260px,66vw);}
.mcol.n1 .mt{aspect-ratio:4/5;}
.mcol.n2,.mcol.n4{grid-template-columns:1fr 1fr;}
.mcol.n2 .mt{aspect-ratio:3/4;}
.mcol.n3{grid-template-columns:1fr 1fr;grid-template-rows:1fr 1fr;}
.mcol.n3 .mt:first-child{grid-row:span 2;aspect-ratio:auto;}
.mcol .mt.broken{display:flex;align-items:center;justify-content:center;}
.mcol .mt.broken::after{content:"\eee4";font-family:remixicon;color:var(--muted);font-size:22px;}
.mt-play{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none;}
.mt-play i{width:38px;height:38px;border-radius:50%;background:rgba(0,0,0,.55);color:#fff;display:flex;align-items:center;justify-content:center;font-size:22px;}
.mt-more{position:absolute;inset:0;background:rgba(0,0,0,.55);color:#fff;font-size:1.25rem;font-weight:700;display:flex;align-items:center;justify-content:center;}
.ci-media a{position:relative;}
.msg-row.pending .mcol .mt{opacity:.8;}
.vanish-ico{font-size:12px;color:var(--accent-text);}

/* ══ MUTE + DISAPPEARING ══ */
.conv-mute{font-size:12px;color:var(--muted);margin-left:5px;flex-shrink:0;}
.conv-badge.muted{background:rgba(var(--fg),.16);color:var(--ui-text,#fff);}
.hdr-mute{font-size:13px;color:var(--muted);margin-left:6px;}
.dd-val{margin-left:auto;padding-left:14px;font-style:normal;font-size:.64rem;color:var(--accent-text);font-weight:600;}
.vanish-bar{display:flex;align-items:center;gap:8px;padding:6px var(--pad);font-size:.66rem;color:var(--sub);border-bottom:1px solid var(--line);flex-shrink:0;position:relative;z-index:10;}
.vanish-bar i{color:var(--accent-text);font-size:15px;}
.vanish-bar span{flex:1;}
.vanish-bar button{background:none;border:none;color:var(--accent-text);font-size:.66rem;font-weight:700;cursor:pointer;}
.dm-panel{width:380px;}
.dm-note{font-size:.68rem;color:var(--sub);line-height:1.6;margin:6px 0 10px;}
.dm-opt{display:flex;align-items:center;gap:12px;width:100%;text-align:left;background:none;border:none;border-top:1px solid var(--line);padding:11px 2px;color:var(--ui-text,#fff);cursor:pointer;}
.dm-opt:hover .dm-t b{color:var(--accent-text);}
.dm-radio{width:18px;height:18px;border-radius:50%;border:2px solid var(--border);flex-shrink:0;position:relative;transition:border-color .15s;}
.dm-opt.on .dm-radio{border-color:var(--accent);}
.dm-opt.on .dm-radio::after{content:"";position:absolute;inset:3px;border-radius:50%;background:var(--accent);}
.dm-t b{display:block;font-size:.76rem;font-weight:600;}
.dm-t small{font-size:.62rem;color:var(--muted);}

/* ══ NEW MESSAGE LOADING ══ */
.nm-loadbar{height:2px;background:transparent;overflow:hidden;opacity:0;transition:opacity .2s;}
.nm-loadbar.on{opacity:1;}
.nm-loadbar i{display:block;height:100%;width:40%;background:var(--accent);animation:nmSlide 1s infinite ease-in-out;}
@keyframes nmSlide{from{transform:translateX(-100%)}to{transform:translateX(260%)}}
.nm-loading{display:flex;align-items:center;justify-content:center;gap:8px;padding:12px;font-size:.68rem;color:var(--muted);}
.spin{width:14px;height:14px;border-radius:50%;border:2px solid rgba(var(--fg),.2);border-top-color:var(--accent);animation:spinr .7s linear infinite;display:inline-block;}
@keyframes spinr{to{transform:rotate(360deg)}}

/* ══ POLLS (group chats) ══ */
.poll-row{align-items:center !important;margin:12px 0 !important;}
.poll-card{position:relative;width:min(340px,calc(100% - 24px));margin:6px auto 4px;padding:13px 14px 11px;border-radius:6px;background:var(--card);border:1px solid rgba(var(--fg),.12);text-align:left;color:var(--text);animation:popIn .22s cubic-bezier(.22,1,.36,1);}
.pc-head{display:flex;align-items:center;gap:11px;}
.pc-av{flex:none;width:40px;height:40px;border-radius:50%;object-fit:cover;border:2px solid var(--accent);background:var(--field);}
.pc-hd{min-width:0;flex:1;}
.pc-q{font-size:.98rem;font-weight:700;line-height:1.3;word-break:break-word;}
.pc-sub{margin-top:2px;font-size:.66rem;color:var(--muted);letter-spacing:.02em;}
.pc-opts{margin-top:13px;display:grid;gap:11px;}
.pc-opt{display:block;width:100%;padding:0;border:0;background:none;color:inherit;font:inherit;text-align:left;cursor:pointer;-webkit-tap-highlight-color:transparent;}
.pc-opt:active .pc-row{opacity:.75;}
.pc-row{display:flex;align-items:center;gap:9px;margin-bottom:6px;}
.pc-mark{flex:none;width:19px;height:19px;border-radius:50%;border:2px solid rgba(var(--fg),.3);display:flex;align-items:center;justify-content:center;font-size:13px;color:transparent;transition:background .15s,border-color .15s,color .15s;}
.pc-multi .pc-mark{border-radius:3px;}
.pc-opt.sel .pc-mark{background:var(--accent);border-color:var(--accent);color:#14070a;}
.pc-lab{flex:1;min-width:0;font-size:.86rem;font-weight:600;line-height:1.3;word-break:break-word;}
.pc-right{flex:none;display:flex;align-items:center;gap:6px;}
.pc-stack{display:flex;}
.pc-stack img{width:20px;height:20px;border-radius:50%;object-fit:cover;border:2px solid var(--card);margin-left:-8px;background:var(--field);}
.pc-stack img:first-child{margin-left:0;}
.pc-cnt{min-width:30px;height:20px;padding:0 7px;border-radius:3px;background:var(--accent);color:#fff;font-size:.68rem;font-weight:700;display:flex;align-items:center;justify-content:center;}
.pc-cnt.zero{background:rgba(var(--fg),.1);color:var(--muted);}
.pc-bar{display:block;height:6px;border-radius:1px;background:rgba(var(--fg),.1);overflow:hidden;}
.pc-bar i{display:block;height:100%;background:var(--accent);transition:width .45s cubic-bezier(.22,1,.36,1);}
.pc-hid{display:none !important;}
.pc-expanded .pc-hid{display:block !important;}
.pc-more{display:block;width:100%;margin:11px 0 0;padding:0;border:0;background:none;color:var(--muted);font:inherit;font-size:.78rem;font-weight:600;text-align:left;cursor:pointer;}
.pc-expanded .pc-more{display:none;}
.pc-vote{display:block;width:100%;height:38px;margin-top:12px;border:0;border-radius:var(--r);background:var(--accent);color:#14070a;font:inherit;font-size:.86rem;font-weight:700;cursor:pointer;transition:transform .12s,background .15s;}
.pc-vote:active{transform:scale(.98);}
.pc-vote:disabled{background:rgba(var(--fg),.08);color:var(--muted);cursor:default;transform:none;}
.pc-foot{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:9px;font-size:.66rem;color:var(--muted);}
.pc-end{border:0;background:none;padding:0;color:var(--accent-text);font:inherit;font-size:.66rem;font-weight:700;cursor:pointer;}
.pc-closed .pc-opt{cursor:default;}
.pc-closed .pc-mark{display:none;}
.pc-busy{opacity:.7;pointer-events:none;}
/* new-poll modal: small, square-cornered, centred */
.pl-ov{display:none;position:fixed;inset:0;z-index:700;background:var(--ov,rgba(1,2,24,.78));backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);align-items:center;justify-content:center;padding:12px;}
.pl-ov.open{display:flex;animation:fadeBg .2s ease;}
.pl-sheet{width:340px;max-width:100%;max-height:84vh;max-height:84dvh;display:flex;flex-direction:column;background:var(--panel);border:1px solid var(--border);border-radius:var(--r);box-shadow:0 24px 64px rgba(0,0,0,.85);animation:popIn .22s cubic-bezier(.22,1,.36,1);overflow:hidden;}
.pl-head{display:flex;align-items:center;gap:8px;height:44px;padding:0 6px 0 14px;flex:none;border-bottom:1px solid rgba(var(--fg),.14);}
.pl-head h3{margin:0;flex:1;display:flex;align-items:center;gap:8px;font-size:.88rem;font-weight:700;}
.pl-head h3 i{color:var(--accent);font-size:17px;}
.pl-x{width:32px;height:32px;border-radius:50%;border:0;background:none;color:var(--text);font-size:19px;display:flex;align-items:center;justify-content:center;cursor:pointer;}
.pl-x:hover{background:var(--accent-soft);color:var(--accent);}
.pl-body{padding:6px 14px 4px;overflow-y:auto;overscroll-behavior:contain;-webkit-overflow-scrolling:touch;flex:1;min-height:0;}
.pl-lab{display:flex;justify-content:space-between;margin:10px 0 5px;font-size:.68rem;font-weight:600;color:var(--sub);}
.pl-lab em{font-style:normal;font-weight:400;color:var(--muted);}
.pl-q,.pl-o input{width:100%;box-sizing:border-box;border:1px solid var(--border);border-radius:var(--r);background:var(--field);color:var(--text);font:inherit;font-size:16px;outline:none;transition:border-color .15s;}
.pl-q{padding:9px 11px;min-height:56px;resize:none;line-height:1.35;font-weight:600;}
.pl-q:focus,.pl-o input:focus{border-color:var(--accent);}
.pl-o{display:flex;align-items:center;gap:6px;margin-bottom:6px;}
.pl-o input{height:38px;padding:0 11px;}
.pl-rm{flex:none;width:28px;height:28px;border:0;border-radius:50%;background:none;color:var(--muted);font-size:17px;display:flex;align-items:center;justify-content:center;cursor:pointer;}
.pl-rm:hover{color:var(--accent);}
.pl-rm[hidden]{display:none;}
.pl-add{display:flex;align-items:center;gap:7px;width:100%;height:36px;margin:2px 0 2px;padding:0 10px;border:1px dashed var(--accent-border);border-radius:var(--r);background:none;color:var(--accent-text);font:inherit;font-size:.8rem;font-weight:600;cursor:pointer;}
.pl-add:disabled{opacity:.4;cursor:default;}
.pl-multi{display:flex;align-items:center;gap:10px;margin-top:8px;padding:8px 2px 4px;cursor:pointer;}
.pl-multi .t{flex:1;font-size:.8rem;font-weight:600;}
.pl-multi input{position:absolute;opacity:0;pointer-events:none;}
.pl-multi .sw{flex:none;position:relative;width:38px;height:22px;border-radius:3px;background:rgba(var(--fg),.22);transition:background .15s;}
.pl-multi .sw::after{content:"";position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:2px;background:#fff;transition:transform .15s;}
.pl-multi input:checked + .sw{background:var(--accent);}
.pl-multi input:checked + .sw::after{transform:translateX(16px);}
.pl-foot{flex:none;padding:8px 14px 12px;}
.pl-send{display:flex;align-items:center;justify-content:center;gap:7px;width:100%;height:40px;border:0;border-radius:var(--r);background:var(--accent);color:#14070a;font:inherit;font-size:.86rem;font-weight:700;cursor:pointer;transition:transform .12s;}
.pl-send:active{transform:scale(.98);}
.pl-send:disabled{background:rgba(var(--fg),.08);color:var(--muted);cursor:default;transform:none;}

/* ══ MEDIA VIEWER (Instagram-style: blurred backdrop, round media, bare top bar) ══ */
.mv{display:none;position:fixed;inset:0;z-index:800;background:#05061a;flex-direction:column;overflow:hidden;color:#fff;}
.mv.open{display:flex;animation:fadeBg .2s ease;}
.mv-bg{position:absolute;inset:-40px;background:#0a0c2a center/cover no-repeat;filter:blur(46px) saturate(1.25) brightness(.5);transform:scale(1.15);transition:background-image .3s;}
.mv::after{content:'';position:absolute;inset:0;background:linear-gradient(180deg,rgba(4,5,22,.55),rgba(4,5,22,.25) 40%,rgba(4,5,22,.55));pointer-events:none;}
.mv-top{position:relative;z-index:3;display:flex;align-items:center;gap:12px;padding:calc(env(safe-area-inset-top,0px) + 14px) 16px 10px;flex-shrink:0;}
.mv-btn{width:38px;height:38px;border-radius:50%;background:none;border:none;color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;text-decoration:none;flex-shrink:0;transition:transform .12s,opacity .12s;-webkit-tap-highlight-color:transparent;}
.mv-btn:active{transform:scale(.88);opacity:.7;}
.mv-btn svg{width:26px;height:26px;display:block;}
.mv-av{width:30px;height:30px;border-radius:7px;box-sizing:border-box;border:2px solid var(--accent,#FF2D2D);object-fit:cover;flex-shrink:0;background:#1a1d3d;}
.mv-back{width:34px;margin-right:-4px;}
.mv-back svg{width:auto;height:21px;aspect-ratio:14/24;overflow:visible;}
.mv-title{flex:1;min-width:0;display:flex;flex-direction:column;line-height:1.25;}
.mv-title b{font-size:.8rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.mv-title span{font-size:.64rem;color:rgba(255,255,255,.6);}
.mv-acts{display:flex;align-items:center;gap:2px;flex-shrink:0;}
.mv-nodl{position:relative;width:38px;height:38px;display:none;align-items:center;justify-content:center;color:rgba(255,255,255,.45);}
.mv-nodl svg{width:26px;height:26px;}
.mv-nodl::after{content:'';position:absolute;width:30px;height:2px;border-radius:2px;background:var(--danger,#ff4d4d);transform:rotate(-45deg);}
.mv-stage{position:relative;z-index:2;flex:1;min-height:0;display:flex;align-items:center;justify-content:center;padding:0 10px;}
.mv-frame{position:relative;display:flex;align-items:center;justify-content:center;width:min(760px,100%);max-height:78%;border-radius:20px;overflow:hidden;background:transparent;}
.mv-stage img,.mv-stage video{display:block;width:100%;height:auto;max-height:100%;max-height:min(78vh,100%);object-fit:contain;border-radius:20px;background:transparent;animation:mvIn .25s ease;}
@keyframes mvIn{from{opacity:0;transform:scale(.97);}to{opacity:1;transform:none;}}
.mv-spin{position:absolute;width:26px;height:26px;border-radius:50%;border:3px solid rgba(255,255,255,.18);border-top-color:#fff;animation:spinr .7s linear infinite;display:none;}
.mv-spin.on{display:block;}
.mv-nav{position:absolute;top:50%;transform:translateY(-50%);z-index:4;width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,.14);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);border:none;color:#fff;font-size:22px;display:none;align-items:center;justify-content:center;cursor:pointer;transition:background .2s;}
.mv-nav:hover{background:rgba(255,255,255,.26);}
.mv-nav.prev{left:18px;}.mv-nav.next{right:18px;}
.mv-nav[hidden]{display:none !important;}
@media (hover:hover) and (min-width:769px){.mv-nav{display:flex;}}
.mv-dots{position:relative;z-index:3;flex-shrink:0;display:none;justify-content:center;gap:6px;padding:12px 0 calc(22px + env(safe-area-inset-bottom,0px));}
.mv-dots.on{display:flex;}
.mv-dots i{width:6px;height:6px;border-radius:50%;background:rgba(255,255,255,.35);transition:background .15s,transform .15s;}
.mv-dots i.on{background:#fff;transform:scale(1.25);}
.mv-spacer{flex-shrink:0;height:calc(34px + env(safe-area-inset-bottom,0px));}
.mv-dots.on + .mv-spacer{display:none;}
.cmp-box.tall{border-radius:24px;}

/* ══ COMPOSER: boxy + thin ══ */
.input-row{padding:clamp(6px,1.8vw,8px) var(--pad);}
.cmp-box{border-radius:var(--r);min-height:clamp(38px,10vw,43px);padding:3px 4px;gap:4px;align-items:center;}
.cmp-box.tall{border-radius:var(--r);}
.cmp-cam{width:clamp(31px,8.2vw,35px);height:clamp(31px,8.2vw,35px);border-radius:var(--act-r);font-size:16px;align-self:center;}
.cmp-right{align-self:center;}
.cmp-ico{width:clamp(31px,8.2vw,35px);height:clamp(31px,8.2vw,35px);border-radius:var(--act-r);font-size:19px;}
#msgInput{min-height:clamp(31px,8.2vw,35px);padding:6px 4px;font-size:.82rem;line-height:1.35;}
.send-pill{height:clamp(31px,8.2vw,35px);border-radius:var(--act-r);padding:0 14px;font-size:.74rem;align-self:center;}

/* menu (sidebar): X button and chat avatars sit a little further left, still clear of the screen edge */
.ms-sidebar .sb-close{margin-left:-4px;}
.ms-sidebar .conv-item,.ms-sidebar .sr-item{padding-left:max(5px,calc(var(--pad) - 10px));}

/* unread = a small red dot under the time */
.conv-badge{width:11px;height:11px;min-width:0;padding:0;border-radius:50%;font-size:0;display:block;margin-left:auto;border:2px solid var(--ring,#070924);box-shadow:0 0 8px rgba(255,45,45,.55);}
.conv-badge.muted{background:rgba(var(--fg),.35);box-shadow:none;}
.ms-sidebar .ac-topbar .ac-title{flex:1;text-align:center;justify-content:center;margin:0 0 0 -4px;}

.msg-wrap{transition:transform .3s cubic-bezier(.2,.9,.25,1.05);}
.msg-wrap.swiping{transition:none;}
.swipe-ico{position:absolute;left:4px;top:calc(50% - 14px);width:28px;height:28px;border-radius:50%;background:var(--accent-soft);color:var(--accent-text);display:flex;align-items:center;justify-content:center;font-size:16px;pointer-events:none;opacity:.8;transform:scale(.7);transition:transform .15s,background .15s,opacity .15s;}
.swipe-ico.on{opacity:1;transform:scale(1);background:var(--accent);color:var(--on-accent);}

/* empty chat ("Start the conversation") — same layout as the Untracked page empty state */
.empty-state{flex:1;min-height:52vh;padding:clamp(28px,8vw,48px) clamp(20px,7vw,36px) clamp(36px,10vw,60px);display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;position:relative;z-index:1;}
.es-title{font-size:clamp(.95rem,3.4vw,1.1rem);font-weight:700;color:var(--ui-text,#fff);letter-spacing:-.2px;}
.es-text{margin-top:6px;max-width:340px;font-size:clamp(.66rem,2.1vw,.76rem);color:var(--muted);line-height:1.7;}
.es-tips{width:100%;max-width:360px;margin-top:clamp(16px,5vw,24px);display:flex;flex-direction:column;gap:7px;}
.es-tip{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:var(--act-r);background:rgba(var(--fg),.05);text-align:left;}
.es-tip i{font-size:1rem;color:var(--accent);flex-shrink:0;}
.es-tip span{font-size:clamp(.62rem,2vw,.7rem);color:var(--sub);line-height:1.5;}
.es-btn{margin-top:clamp(16px,5vw,22px);display:flex;align-items:center;justify-content:center;height:var(--act-h);min-width:150px;padding:0 22px;border-radius:var(--act-r);background:rgba(var(--fg),.1);color:var(--ui-text,#fff);font-size:.72rem;font-weight:600;text-decoration:none;transition:background .2s ease,transform .15s ease;}
.es-btn:hover{background:rgba(var(--fg),.2);}
.es-btn:active{transform:scale(.98);}
@media (max-width:490px){.es-btn{font-size:.62rem;}}
.messages-window .mw-empty{margin:auto 0;width:100%;}

.es-avatar{width:clamp(72px,20vw,88px);height:clamp(72px,20vw,88px);border-radius:50%;object-fit:cover;border:2px solid var(--accent);margin-bottom:14px;background:var(--ph,#10143a);}
.input-row{padding-left:max(6px,calc(var(--pad) - 6px));padding-right:max(6px,calc(var(--pad) - 6px));}

/* chat header back button: bigger, and a little further left (still clear of the screen edge) */
.chat-header .chat-header-back{width:38px;height:38px;margin-left:-4px;}
.chat-header .chat-header-back i{font-size:30px;}
@media (max-width:490px){.chat-header .chat-header-back{width:36px;height:36px;}.chat-header .chat-header-back i{font-size:28px;}}
/* back arrow = the same thin, tall chevron (same size and shape) as the Zeems modal's back button */
.chat-header .chat-header-back{padding-left:2px;}
.chat-header .chat-header-back .ms-back-svg{height:calc((18px + 4 * var(--ccm-t,0.5px)) * var(--ccm-s,1.155));width:auto;aspect-ratio:14 / 24;display:block;overflow:visible;}

.ac-x svg{width:22px;height:22px;display:block;}
.conv-more-loader{display:flex;align-items:center;justify-content:center;gap:8px;padding:14px;font-size:.66rem;color:var(--muted);}
.ms-sidebar .sb-close svg{width:19px;height:19px;}
@media (max-width:490px){.bubble{padding:5px 10px;}}

.msg-row{touch-action:pan-y;}
.swipe-ico{opacity:0;transform:scale(.55);transition:opacity .2s,transform .2s,background .15s;}
.msg-wrap.swiping + .swipe-ico,.swipe-ico{will-change:transform,opacity;}
.msg-row .swiping ~ .swipe-ico{transition:none;}


/* ═══ ROUND 4: skeleton, pattern background, groups, typing, intro, picker ═══ */
.sb-lists{position:relative;flex:1;min-height:0;display:flex;flex-direction:column;}
.sb-skel{position:absolute;inset:0;z-index:6;background:var(--bg-grad);background-attachment:fixed;overflow:hidden;transition:opacity .28s ease;}
.sb-skel.out{opacity:0;pointer-events:none;}
.sk-row{display:flex;align-items:center;gap:12px;padding:clamp(10px,2.8vw,12px) var(--pad);padding-left:max(5px,calc(var(--pad) - 10px));border-top:1px solid var(--line);}
.sk-av{width:clamp(42px,11vw,48px);height:clamp(42px,11vw,48px);border-radius:50%;flex-shrink:0;}
.sk-lines{flex:1;display:flex;flex-direction:column;gap:8px;}
.sk-time{width:26px;height:9px;}

/* default chat background pattern (a custom background replaces it) */
.chat-bg-layer:not(.has-bg){background-image:none;}   /* no default pattern: plain background unless you pick one */

/* group avatar: three pictures */
.gstack{position:relative;display:block;width:clamp(42px,11vw,48px);height:clamp(42px,11vw,48px);flex-shrink:0;}
.gstack img{position:absolute;width:62%;height:62%;border-radius:50%;object-fit:cover;border:2px solid var(--accent);background:var(--ph,#10143a);box-sizing:border-box;}
.gstack.n3 img:nth-child(1){left:19%;top:0;z-index:3;}
.gstack.n3 img:nth-child(2){left:0;bottom:0;z-index:2;}
.gstack.n3 img:nth-child(3){right:0;bottom:0;z-index:1;}
.gstack.n2 img:nth-child(1){left:0;top:0;z-index:2;}
.gstack.n2 img:nth-child(2){right:0;bottom:0;z-index:1;}
.gstack.n1 img{width:100%;height:100%;}
.gstack.sm{width:40px;height:40px;}
.gstack.lg{width:clamp(84px,24vw,104px);height:clamp(84px,24vw,104px);}
.conv-avatar .gstack{margin:0;}
.es-stack{margin-bottom:14px;display:flex;justify-content:center;}
.chat-header-avatar .gstack{margin:0;}

/* intro block at the top of a conversation */
.chat-intro{display:flex;flex-direction:column;align-items:center;text-align:center;padding:clamp(18px,5vw,30px) 16px clamp(12px,3vw,20px);position:relative;z-index:1;}
.chat-intro .es-avatar{margin-bottom:12px;}
.ci-intro-name{font-size:clamp(.95rem,3.4vw,1.1rem);font-weight:700;color:var(--ui-text,#fff);letter-spacing:-.2px;}
.ci-intro-sub{font-size:.7rem;color:var(--muted);margin-top:2px;min-height:1em;}
.chat-intro .es-btn{margin-top:14px;border:none;cursor:pointer;font-family:inherit;}
.chat-intro .mw-empty{margin-top:clamp(18px,5vw,28px);width:100%;display:flex;flex-direction:column;align-items:center;}
.messages-window .mw-empty{margin:clamp(18px,5vw,28px) 0 0;}
.older-loader{display:flex;align-items:center;justify-content:center;gap:8px;padding:10px;font-size:.64rem;color:var(--muted);}

/* group message bits */
.g-sender{font-size:.6rem;font-weight:600;color:var(--accent-text);margin:0 0 2px 36px;}
.msg-row.sys{align-items:center;margin:10px 0;}
.sys-text{font-size:.62rem;color:var(--muted);background:rgba(var(--fg),.06);padding:4px 12px;border-radius:99px;text-align:center;max-width:90%;}
.sys-text b{color:var(--sub);font-weight:600;}

/* typing dots */
.msg-row.typing-row{margin-top:6px;}
.typing-b{display:flex;align-items:center;gap:4px;padding:11px 14px !important;}
.typing-b i{display:block;width:6px;height:6px;border-radius:50%;background:rgba(var(--fg),.75);animation:typeDot 1.2s infinite ease-in-out;}
.typing-b i:nth-child(2){animation-delay:.16s;}
.typing-b i:nth-child(3){animation-delay:.32s;}
@keyframes typeDot{0%,60%,100%{transform:translateY(0);opacity:.45}30%{transform:translateY(-4px);opacity:1}}

/* swipe to reply: only the bubble moves (avatars stay put) */
.msg-wrap,.msg-wrap.swiping{transition:none;}
.msg-row .bubble{position:relative;z-index:1;}
.bubble.springing{transition:transform .38s cubic-bezier(.22,1,.36,1);}
.msg-row .swipe-ico{z-index:0;}
.msg-row.recv .swipe-ico{left:40px;}

/* plus-in-a-circle media button */
.cmp-ico.cmp-plus{font-size:23px;}

/* new group / add people picker */
.nm-name{padding:4px var(--pad) 6px;}
.nm-name input{width:100%;height:var(--act-h);padding:0 12px;border-radius:var(--act-r);border:1px solid var(--line);background:var(--surface);color:var(--ui-text,#fff);font:inherit;font-size:.74rem;outline:none;}
.nm-name input:focus{border-color:var(--accent-border);}
.nm-item .pick{width:20px;height:20px;border-radius:50%;border:1.5px solid var(--border);display:flex;align-items:center;justify-content:center;color:transparent;font-size:12px;flex-shrink:0;transition:all .15s;}
.nm-item.sel .pick{background:var(--accent);border-color:var(--accent);color:#000;}
.nm-item.nm-newgroup{background:rgba(var(--fg),.03);}
.nm-item.nm-newgroup .ng-ic{width:40px;height:40px;border-radius:50%;background:var(--accent);color:#000;display:flex;align-items:center;justify-content:center;font-size:20px;}
.nm-foot{display:flex;align-items:center;gap:10px;padding:10px var(--pad);border-top:1px solid rgba(var(--fg),.14);font-size:.68rem;color:var(--sub);}
.nm-foot span{flex:1;}
.nm-foot .act-btn[disabled]{opacity:.45;pointer-events:none;}

/* group info */
.gi-panel{width:380px;}
.gi-body{padding:0 !important;}
.gi-name{display:flex;gap:8px;padding:14px var(--pad) 4px;}
.gi-name input{flex:1;min-width:0;height:var(--act-h);padding:0 12px;border-radius:var(--act-r);border:1px solid var(--line);background:var(--surface);color:var(--ui-text,#fff);font:inherit;font-size:.78rem;font-weight:600;outline:none;}
.gi-name input:not([readonly]){border-color:var(--accent-border);}
.gi-count{padding:2px var(--pad) 8px;font-size:.62rem;color:var(--muted);}
.gi-list{max-height:min(320px,42vh);overflow-y:auto;border-top:1px solid var(--line);}
.gi-m{display:flex;align-items:center;gap:12px;padding:9px var(--pad);border-bottom:1px solid var(--line);text-decoration:none;color:var(--ui-text,#fff);}
.gi-m:hover{background:rgba(var(--fg),.04);}
.gi-m img{width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid var(--accent);}
.gi-m .t{flex:1;min-width:0;}
.gi-m .n{font-size:.76rem;font-weight:600;display:flex;align-items:center;gap:5px;}
.gi-m .s{font-size:.62rem;color:var(--muted);}
.gi-m .tag{font-size:.54rem;font-weight:700;padding:1px 6px;border-radius:var(--act-r);background:var(--accent-soft);border:1px solid var(--accent-border);color:var(--accent-text);}
.gi-add,.gi-leave{width:calc(100% - 2 * var(--pad));margin:12px var(--pad) 0;justify-content:center;}
.gi-leave{margin-bottom:14px;}

/* thinner menu tabs */
.sb-tabs{padding:2px;gap:2px;margin-bottom:6px;}
.sb-tab{height:26px;font-size:.66rem;line-height:1;white-space:nowrap;padding:0 6px;}
.sb-tab .tn{padding:0 5px;font-size:.54rem;line-height:14px;}
/* New group button: label centred, no icon */
.act-btn.ng-btn{display:inline-flex;align-items:center;justify-content:center;min-width:150px;height:var(--act-h);padding:0 22px;margin-top:14px;line-height:1;font-size:.72rem;font-weight:600;text-align:center;}

/* green dot on the Group Chats tab: same size as the active dot */
.sb-tab .tab-dot{display:inline-block;width:11px;height:11px;border-radius:50%;background:var(--ok);border:2px solid var(--ring,#070924);box-shadow:0 0 8px rgba(74,222,128,.55);flex-shrink:0;box-sizing:content-box;margin-left:1px;}
.sb-tab.active .tab-dot{border-color:var(--accent);}
/* archive row + list */
.sb-arch{display:flex;align-items:center;gap:10px;width:calc(100% - 2 * var(--pad));margin:0 var(--pad) 6px;height:32px;padding:0 10px;border-radius:var(--r);border:1px solid var(--line);background:var(--surface);color:var(--sub);font:inherit;font-size:.7rem;font-weight:600;cursor:pointer;flex-shrink:0;transition:background .2s,color .2s;}
.sb-arch:hover{background:rgba(var(--fg),.08);color:var(--ui-text,#fff);}
.sb-arch i{font-size:1rem;color:var(--accent-text);}
.sb-arch span{flex:1;text-align:left;}
.sb-arch em{font-style:normal;font-size:.62rem;font-weight:700;color:var(--muted);}
.sb-arch i:last-child{color:var(--muted);}
.arch-head{display:flex;align-items:center;padding:4px 6px;}
.arch-head button{display:flex;align-items:center;gap:2px;background:none;border:none;color:var(--ui-text,#fff);font:inherit;font-size:.8rem;font-weight:700;cursor:pointer;padding:6px 8px 6px 2px;}
.arch-head i{font-size:1.4rem;color:var(--accent-text);}
.own-pick.sel{background:rgba(var(--fg),.06);}
.own-pick{cursor:pointer;}
.own-pick .pick{width:20px;height:20px;border-radius:50%;border:1.5px solid var(--border);display:flex;align-items:center;justify-content:center;color:transparent;font-size:12px;}
.own-pick.sel .pick{background:var(--accent);border-color:var(--accent);color:#000;}
.gi-leave[disabled]{opacity:.45;pointer-events:none;}
/* right-column buttons: no stray border; opaque grey instead of translucent */
button.ci-btn{border:none;outline:none;font-family:inherit;cursor:pointer;}
button.ci-btn:focus-visible{outline:2px solid #fff;outline-offset:2px;}
.es-btn{background:var(--btn,#23263f);}
.es-btn:hover{background:var(--btn-h,#2e3250);}

/* clickable profile pic / name in chat intro + info column */
.ci-link{color:inherit;text-decoration:none;cursor:pointer;-webkit-tap-highlight-color:transparent;}
.chat-intro a.es-avatar,.chat-intro .ci-link:has(>.es-avatar){display:block;line-height:0;}
.ci-link:hover .es-avatar,.ci-link:hover .av{filter:brightness(1.12);}
a.ci-intro-name.ci-link:hover,a.ci-name.ci-link:hover span{color:var(--accent-text);}
a.ci-av.ci-link{display:block;position:relative;}

/* ═══ bubble tails (curved tail, rounded tip) ═══ */
.bubble.b-sent:not(.emoji-only):not(.deleted):not(.bubble-collage):not(.shared-meme-bubble){border-bottom-right-radius:0;}
.bubble.b-recv:not(.emoji-only):not(.deleted):not(.bubble-collage):not(.shared-meme-bubble){border-bottom-left-radius:0;}
.bubble.b-sent:not(.emoji-only):not(.deleted):not(.bubble-collage):not(.shared-meme-bubble)::after,.bubble.b-recv:not(.emoji-only):not(.deleted):not(.bubble-collage):not(.shared-meme-bubble)::after{content:'';position:absolute;bottom:0;width:14px;height:18px;pointer-events:none;}
.bubble.b-sent:not(.emoji-only):not(.deleted):not(.bubble-collage):not(.shared-meme-bubble)::after{right:-13px;background:#E02424;clip-path:path('M0 0C.7 9.4 4.5 14.4 11.4 15.5A1.35 1.35 0 0 1 11.9 18H0Z');}
.bubble.b-recv:not(.emoji-only):not(.deleted):not(.bubble-collage):not(.shared-meme-bubble)::after{left:-13px;background:var(--bubble-recv,#151a44);clip-path:path('M14 0C13.3 9.4 9.5 14.4 2.6 15.5A1.35 1.35 0 0 0 2.1 18H14Z');}
/* tail seam fixes: no glow/outline on bubbles that carry a tail, so bubble + tail read as one shape */
.bubble.b-sent:not(.emoji-only):not(.deleted):not(.bubble-collage):not(.shared-meme-bubble){box-shadow:none;}
.bubble.b-recv:not(.emoji-only):not(.deleted):not(.bubble-collage):not(.shared-meme-bubble){border:none;padding:6px 12px;}
.bubble.b-sent:not(.emoji-only):not(.deleted):not(.bubble-collage):not(.shared-meme-bubble){padding:6px 12px;}

/* keep the tail inside the row so wide bubbles never clip it or tuck it behind the avatar */
.msg-row.sent .msg-wrap{max-width:calc(100% - 4px);margin-right:4px;}
.msg-row.recv .msg-wrap{gap:16px;max-width:calc(100% - 4px);}
.gi-del{margin-top:-6px;}

/* ═══ RESPONSIVE SIZE BUMP: everything 10% bigger on the phone layout (desktop untouched) ═══ */
@media (max-width:768px){
  .app{zoom:1.1;position:fixed;inset:0;height:auto;}   /* fills the visible screen exactly, in Safari too (no vh maths under zoom) */
  .ms-ui,.toast{zoom:1.1;}
  .bubble{max-width:min(calc(82vw/1.1),420px);}
}
@media (max-width:490px){
  .bubble{max-width:calc(86vw/1.1);}
  .shared-meme-card{max-width:calc(84vw/1.1);}
}



/* inbox title (responsive only, 790px and below): X hidden, title on the left, size + letter-spacing follow the table through clamp() (exact at 330/390/490/790px, smooth between). Normal view keeps the original look. */
@media (max-width:790px){
  .ms-sidebar .ac-topbar #sbClose{display:none;}
  .ms-sidebar .ac-topbar .ac-title{
    flex:1;text-align:left;justify-content:flex-start;margin:0 0 0 max(0px,calc(var(--pad) - 10px));
    font-weight:700;
    font-size:clamp(.95rem, min(calc(15.2px + (100vw - 330px) * .0266667), calc(16.8px + (100vw - 390px) * .024), calc(19.2px + (100vw - 490px) * .0053333)), 1.3rem);
    letter-spacing:clamp(1px, min(calc(1px + (100vw - 330px) * .0083333), calc(1.5px + (100vw - 390px) * .005), calc(2px + (100vw - 490px) * .0033333)), 3px);
  }
}

/* ══ LIGHT MODE ( <html data-theme="light"> — the meme modal and share sheets are left as they are ) ══ */
:root[data-theme="light"]{
  color-scheme:light;
  --fg:6,7,26; --ui-text:#0b0d24; --text:#0b0d24;
  --scroll-thumb:rgba(6,7,26,.28);
  --solid:#ffffff; --panel:#ffffff; --ring:#ffffff; --body-bg:#ffffff;
  --surface:rgba(6,7,26,.045); --surface-2:rgba(6,7,26,.08);
  --border:rgba(6,7,26,.18); --line:rgba(6,7,26,.1);
  --muted:rgba(6,7,26,.5); --muted-2:rgba(6,7,26,.34); --sub:rgba(6,7,26,.68);
  --bg-grad:linear-gradient(-45deg,#ffffff,#f4f5fb,#f4f5fb);
  --accent-text:#d42525; --accent-fill:rgba(255,45,45,.14); --accent-soft:rgba(255,45,45,.1);
  --danger:#d92d2d; --danger-soft:rgba(217,45,45,.1); --ok:#16a34a;
  --bubble-recv:#eceef6; --card:#f1f2f9; --field:#ffffff; --ph:#e6e8f1;
  --ov:rgba(235,237,247,.82); --ov2:rgba(255,255,255,.9);
  --btn:#e8eaf4; --btn-h:#dcdfee; --rp-bg:rgba(6,7,26,.07); --pin-bg:#f4f5fb; --shadow-pop:rgba(6,7,26,.2);
}
:root[data-theme="light"] .bg-picker,:root[data-theme="light"] .more-menu,:root[data-theme="light"] .msg-dropdown,
:root[data-theme="light"] .emoji-picker-wrap,:root[data-theme="light"] .sticker-picker{box-shadow:0 12px 36px rgba(6,7,26,.18);}
:root[data-theme="light"] .info-panel,:root[data-theme="light"] .confirm-box,:root[data-theme="light"] .nm-box{box-shadow:0 20px 56px rgba(6,7,26,.22);}
:root[data-theme="light"] .cmp-box{border-color:rgba(6,7,26,.22);}
:root[data-theme="light"] .input-area{border-top-color:rgba(6,7,26,.14);}
:root[data-theme="light"] .b-recv{border-color:rgba(6,7,26,.08);}
:root[data-theme="light"] .toast{box-shadow:0 8px 28px rgba(6,7,26,.2);}
:root[data-theme="light"] .sb-tab.active .tn{background:rgba(0,0,0,.2);color:#000;}
:root[data-theme="light"] .app,:root[data-theme="light"] .ms-ui{scrollbar-color:var(--scroll-thumb) transparent;}

</style>

</style>
</head>
<body<?= (!empty($chat_user) || !empty($chat_group)) ? ' class="ms-chat-open"' : '' ?>>
<svg width="0" height="0" style="position:absolute;width:0;height:0;overflow:hidden" aria-hidden="true" focusable="false"><symbol id="dz-flower-f" viewBox="0 0 256 256"><path fill="currentColor" fill-rule="evenodd" d="M245.9 128.0L245.5 132.6L244.2 137.1L242.3 141.5L239.7 145.7L236.5 149.6L233.0 153.2L229.3 156.6L225.6 159.7L221.9 162.6L218.5 165.5L215.4 168.3L212.7 171.2L210.5 174.2L208.7 177.5L207.3 181.0L206.3 184.9L205.4 189.1L204.7 193.5L204.0 198.2L203.1 203.1L201.9 208.0L200.4 212.8L198.4 217.4L196.0 221.6L193.0 225.3L189.5 228.4L185.6 230.8L181.3 232.5L176.6 233.5L171.8 233.7L166.9 233.4L161.9 232.4L157.1 231.1L152.4 229.5L147.8 227.8L143.5 226.1L139.4 224.6L135.5 223.5L131.7 222.8L128.0 222.5L124.3 222.8L120.5 223.5L116.6 224.6L112.5 226.1L108.2 227.8L103.6 229.5L98.9 231.1L94.1 232.4L89.1 233.4L84.2 233.7L79.4 233.5L74.7 232.5L70.4 230.8L66.5 228.4L63.0 225.3L60.0 221.6L57.6 217.4L55.6 212.8L54.1 208.0L52.9 203.1L52.0 198.2L51.3 193.5L50.6 189.1L49.7 184.9L48.7 181.0L47.3 177.5L45.5 174.2L43.3 171.2L40.6 168.3L37.5 165.5L34.1 162.6L30.4 159.7L26.7 156.6L23.0 153.2L19.5 149.6L16.3 145.7L13.7 141.5L11.8 137.1L10.5 132.6L10.1 128.0L10.5 123.4L11.8 118.9L13.7 114.5L16.3 110.3L19.5 106.4L23.0 102.8L26.7 99.4L30.4 96.3L34.1 93.4L37.5 90.5L40.6 87.7L43.3 84.8L45.5 81.8L47.3 78.5L48.7 75.0L49.7 71.1L50.6 66.9L51.3 62.5L52.0 57.8L52.9 52.9L54.1 48.0L55.6 43.2L57.6 38.6L60.0 34.4L63.0 30.7L66.5 27.6L70.4 25.2L74.7 23.5L79.4 22.5L84.2 22.3L89.1 22.6L94.1 23.6L98.9 24.9L103.6 26.5L108.2 28.2L112.5 29.9L116.6 31.4L120.5 32.5L124.3 33.2L128.0 33.5L131.7 33.2L135.5 32.5L139.4 31.4L143.5 29.9L147.8 28.2L152.4 26.5L157.1 24.9L161.9 23.6L166.9 22.6L171.8 22.3L176.6 22.5L181.3 23.5L185.6 25.2L189.5 27.6L193.0 30.7L196.0 34.4L198.4 38.6L200.4 43.2L201.9 48.0L203.1 52.9L204.0 57.8L204.7 62.5L205.4 66.9L206.3 71.1L207.3 75.0L208.7 78.5L210.5 81.8L212.7 84.8L215.4 87.7L218.5 90.5L221.9 93.4L225.6 96.3L229.3 99.4L233.0 102.8L236.5 106.4L239.7 110.3L242.3 114.5L244.2 118.9L245.5 123.4ZM84.1 131.9Q78.2 137.8 84.1 143.6L108.3 167.9Q114.1 173.7 119.9 167.8L174.2 111.6Q179.9 105.6 174.0 99.9L170.0 96.1Q164.1 90.4 158.4 96.3L119.6 136.4Q113.9 142.3 108.0 136.5L99.6 128.1Q93.8 122.2 87.9 128.1Z"/></symbol></svg>
<!-- SIDEBAR: now comes from nav.php -->
<?php include 'nav.php'; ?>

<div class="app" id="app">

<!-- ═══ SIDEBAR ════════════════════════════════════════════ -->
<aside class="ms-sidebar" id="ms-sidebar">

  <div class="ac-topbar">
    <a href="profile.php" class="ac-x sb-close" id="sbClose" title="Back" aria-label="Back"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></a>
    <div class="ac-title">Messages Inbox</div>
    <button type="button" class="ac-x" id="newMsgBtn" title="New message" onclick="openNewMsg()"><i class="ri-edit-box-line"></i></button>
  </div>

  <div class="sb-bulk-bar" id="bulkBar">
    <span class="sb-bulk-count" id="bulkCount">0 selected</span>
    <button type="button" class="act-btn" id="bulkSelectAll"><i class="ri-checkbox-multiple-line"></i> All</button>
    <?php if ($MS_ARC): ?><button type="button" class="act-btn" id="bulkArchiveBtn"><i class="ri-archive-line"></i> <span>Archive</span></button><?php endif; ?>
    <button type="button" class="act-btn danger" id="bulkDeleteBtn"><i class="ri-delete-bin-line"></i> Delete</button>
  </div>

  <div class="sb-me">
    <img src="<?= ms_h($me['profile_pic'] ?? 'mee.jpg') ?>" alt="" onerror="this.src='mee.jpg'">
    <div class="sb-me-t">
      <div class="sb-me-name"><?= ms_h($me['username']) ?></div>
      <?php if (!empty($me['nickname'])): ?><div class="sb-me-sub">@<?= ms_h($me['nickname']) ?></div><?php endif; ?>
    </div>
  </div>

  <div class="sb-search">
    <div class="sb-search-inner" id="convSearchWrap">
      <i class="ri-search-line"></i>
      <input type="text" id="convSearch" placeholder="Search names and messages" autocomplete="off" spellcheck="false" aria-label="Search conversations">
      <button type="button" class="sb-search-clear" id="convSearchClear" aria-label="Clear search"><i class="ri-close-circle-fill"></i></button>
    </div>
    <button type="button" class="sb-select-toggle" id="selectToggle" title="Select conversations" aria-label="Select conversations"><i class="ri-checkbox-multiple-blank-line"></i></button>
  </div>

  <div class="sb-tabs" id="sbTabs" style="<?= ($MS_GRP || $request_count > 0) ? '' : 'display:none' ?>">
    <button type="button" class="sb-tab <?= $is_group ? '' : 'active' ?>" id="tabMain" onclick="switchTab('main')">Chats</button>
    <?php if ($MS_GRP): ?><button type="button" class="sb-tab <?= $is_group ? 'active' : '' ?>" id="tabGroups" onclick="switchTab('groups')">Group Chats<span class="tab-dot" id="grpDot" style="<?= $g_dot ? '' : 'display:none' ?>" title="New activity in your groups"></span></button><?php endif; ?>
    <button type="button" class="sb-tab" id="tabReq" onclick="switchTab('req')" style="<?= $request_count > 0 ? '' : 'display:none' ?>">Requests <span class="tn" id="reqCount"><?= $request_count > 0 ? (int)$request_count : '' ?></span></button>
  </div>

  <?php if ($MS_ARC): ?>
  <button type="button" class="sb-arch" id="archRow" onclick="switchTab('arch')" style="<?= $arch_count > 0 ? '' : 'display:none' ?>"><i class="ri-archive-line"></i><span>Archived</span><em id="archCount"><?= (int)$arch_count ?></em><i class="ri-arrow-right-s-line"></i></button>
  <?php endif; ?>

  <div class="sb-lists">
  <div class="sb-skel" id="sbSkel" aria-hidden="true">
    <?php for ($i = 0; $i < 9; $i++): ?><div class="sk-row"><div class="sk-bar sk-av"></div><div class="sk-lines"><div class="sk-bar" style="height:11px;width:<?= 38 + ($i * 13) % 30 ?>%"></div><div class="sk-bar" style="height:9px;width:<?= 62 + ($i * 9) % 28 ?>%"></div></div><div class="sk-bar sk-time"></div></div><?php endfor; ?>
  </div>
  <script>
  /* Skeleton only on a fresh arrival from another page; never when moving between chats/tabs inside Messages */
  (function () {
      var sk = document.getElementById('sbSkel'), ref = document.referrer || '', fromMsg = false;
      try { var u = new URL(ref, location.href); fromMsg = u.origin === location.origin && /\/messages\.php$/.test(u.pathname); } catch (e) {}
      if (fromMsg && sk) sk.parentNode.removeChild(sk);
      /* remember the page we came from so the X can return there */
      try {
          if (ref && !fromMsg) sessionStorage.setItem('ms_back_url', ref);
          var x = document.getElementById('sbClose');
          if (x) x.addEventListener('click', function (e) {
              var b = sessionStorage.getItem('ms_back_url');
              e.preventDefault();
              if (b) location.assign(b);
              else if (history.length > 1) history.back();
              else location.href = 'profile.php';
          });
      } catch (e) {}
  })();
  </script>
  <div class="conv-list" id="convList" style="<?= $is_group ? 'display:none' : '' ?>">
    <?php if ($conversations): foreach ($conversations as $c) echo ms_render_conv($c, $current_user, $chat_user, false, $viewer_show);
    else: ?>
    <div class="sb-empty" id="convEmpty"><i class="ri-chat-3-line"></i><b>No conversations yet</b>Tap the pencil above to message someone.</div>
    <?php endif; ?>
  </div>
  <div class="conv-list" id="reqList" style="display:none">
    <?php foreach ($message_requests as $c) echo ms_render_conv($c, $current_user, $chat_user, true, $viewer_show); ?>
  </div>
  <?php if ($MS_GRP): ?>
  <div class="conv-list" id="groupList" style="<?= $is_group ? '' : 'display:none' ?>">
    <?php if ($groups): foreach ($groups as $g) echo ms_render_group_conv($g, $current_user, (int)$chat_group);
    else: ?>
    <div class="sb-empty" id="groupEmpty"><i class="ri-group-line"></i><b>No group chats yet</b>Start one with two or more people.<br><button type="button" class="act-btn accent ng-btn" onclick="openNewGroup()">New group</button></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <?php if ($MS_ARC): ?>
  <div class="conv-list" id="archList" style="display:none">
    <div class="arch-head"><button type="button" onclick="switchTab(archBack)"><i class="ri-arrow-left-s-line"></i> Archived</button></div>
    <div id="archItems"><?= $arch_html ?></div>
  </div>
  <?php endif; ?>
  <div class="search-results" id="searchResults" style="display:none"></div>
  </div>

</aside>

<!-- ═══ CHAT AREA ══════════════════════════════════════════ -->
<section class="chat-area" id="chatArea">

  <div class="chat-bg-layer" id="chatBgLayer"></div>
  <div class="drop-hint"><i class="ri-upload-cloud-2-line"></i>Drop a photo or video to send</div>

  <?php if ($in_chat):
      $o_nick = $other_info ? trim((string)($other_info['nickname'] ?? '')) : '';
      $status_bits = [];
      if ($is_group) { $status_bits[] = count($g_members) . ' member' . (count($g_members) === 1 ? '' : 's'); }
      else {
          if ($o_nick !== '') $status_bits[] = '@' . ms_h($o_nick);
          if ($other_pres['visible']) $status_bits[] = $other_pres['active'] ? '<span class="live">Active now</span>' : ms_h($other_pres['label']);
      }
  ?>

  <header class="ac-topbar chat-header">
    <button type="button" class="ac-x chat-header-back" onclick="if(window.dzHaptic)dzHaptic.light();showSidebar()" title="Back"><svg class="ms-back-svg" viewBox="0 0 14 24" aria-hidden="true"><path d="M12.4 1.6 L2 12 L12.4 22.4" fill="none" stroke="currentColor" stroke-width="1.94" stroke-linejoin="miter" stroke-linecap="butt"/></svg></button>
    <?php if ($is_group): ?>
    <a class="chat-header-avatar" href="#" onclick="openGroupInfo();return false" title="Group info"><?= ms_group_stack($g_pics, 'sm') ?></a>
    <?php else: ?>
    <a class="chat-header-avatar" href="profile.php?user_id=<?= (int)$other_info['id'] ?>" title="View profile">
      <?php if (!empty($other_info['is_susp'])): ?><span class="susp-av"></span><?php else: ?>
      <img src="<?= ms_h($other_info['profile_pic'] ?? 'mee.jpg') ?>" alt="" onerror="this.src='mee.jpg'">
      <?php endif; ?>
      <span class="online-dot" id="hdrDot" style="<?= ($other_pres['active'] && empty($other_info['is_susp'])) ? '' : 'display:none' ?>"></span>
    </a>
    <?php endif; ?>
    <div class="chat-header-info">
      <div class="chat-header-name"><span id="hdrName"><?= ms_h($is_group ? $group_info['name'] : $other_info['username']) ?></span><?= (!$is_group && !empty($other_info['is_verified'])) ? ms_verified_svg() : '' ?><i class="ri-notification-off-fill hdr-mute" id="hdrMute" title="Muted" style="<?= $chat_muted ? '' : 'display:none' ?>"></i></div>
      <div class="chat-header-status" id="hdrStatus" data-nick="<?= ms_h($o_nick) ?>" data-group="<?= $is_group ? 1 : 0 ?>"><?= implode(' · ', $status_bits) ?></div>
    </div>
    <div class="chat-header-actions">
      <?php if (!$is_group): ?><button type="button" class="ac-x" id="msgSelectToggleBtn" onclick="toggleMsgSelectMode()" title="Select messages"><i class="ri-checkbox-multiple-blank-line"></i></button><?php endif; ?>
      <?php if (!$is_group || $g_is_owner): ?><button type="button" class="ac-x" onclick="toggleBgPicker()" title="<?= $is_group ? 'Group background (everyone sees it)' : 'Change background' ?>"><i class="ri-landscape-line"></i></button><?php endif; ?>
      <button type="button" class="ac-x" onclick="toggleMoreMenu(event)" title="Menu"><i class="ri-menu-line"></i></button>
    </div>

    <div class="more-menu" id="moreMenu">
      <?php if ($is_group): ?>
      <div class="dd-item" onclick="closeMore();openGroupInfo()"><i class="ri-group-line"></i> Group info</div>
      <?php if (!empty($g_is_owner)): ?><div class="dd-item" onclick="closeMore();openGroupInfo(true)"><i class="ri-edit-line"></i> Rename group</div><?php endif; ?>
      <?php if ($g_is_owner): ?><div class="dd-item" onclick="closeMore();openAddPeople()"><i class="ri-user-add-line"></i> Add people</div><?php endif; ?>
      <?php if ($MS_FEAT): ?>
      <div class="dd-item" id="muteItem" onclick="closeMore();toggleMute()"><i class="<?= $chat_muted ? 'ri-notification-3-line' : 'ri-notification-off-line' ?>"></i> <span><?= $chat_muted ? 'Unmute' : 'Mute' ?></span></div>
      <div class="dd-item" id="dlItem" onclick="toggleDownloads()"><i class="ri-download-2-line"></i> <span>Let them download my media</span><em class="dd-val" id="dlVal"><?= $my_allow_dl ? 'On' : 'Off' ?></em></div>
      <?php endif; ?>
      <div class="dd-divider"></div>
      <?php if ($MS_ARC): ?><div class="dd-item" onclick="closeMore();toggleArchiveCurrent()"><i class="ri-archive-line"></i> <span><?= $cur_archived ? 'Unarchive group' : 'Archive group' ?></span></div><?php endif; ?>
      <div class="dd-item danger" onclick="closeMore();leaveGroup()"><i class="ri-logout-box-r-line"></i> Leave group</div>
      <?php if ($g_is_owner): ?><div class="dd-item danger" onclick="closeMore();deleteGroup()"><i class="ri-delete-bin-6-line"></i> Delete group</div><?php endif; ?>
      <?php else: ?>
      <?php if ($MS_ARC): ?><div class="dd-item" onclick="closeMore();toggleArchiveCurrent()"><i class="ri-archive-line"></i> <span><?= $cur_archived ? 'Unarchive chat' : 'Archive chat' ?></span></div><div class="dd-divider"></div><?php endif; ?>
      <?php if ($i_blocked): ?>
      <div class="dd-item" onclick="closeMore();unblockUser()"><i class="ri-user-follow-line"></i> Unblock</div>
      <?php else: ?>
      <div class="dd-item danger" onclick="closeMore();blockUser()"><i class="ri-user-forbid-line"></i> Block</div>
      <?php endif; ?>
      <?php if ($MS_FEAT): ?>
      <div class="dd-divider"></div>
      <div class="dd-item" id="muteItem" onclick="closeMore();toggleMute()"><i class="<?= $chat_muted ? 'ri-notification-3-line' : 'ri-notification-off-line' ?>"></i> <span><?= $chat_muted ? 'Unmute' : 'Mute' ?></span></div>
      <div class="dd-item" onclick="closeMore();openDisappear()"><i class="ri-timer-line"></i> <span>Disappearing messages</span><em class="dd-val" id="ddVanishVal"><?= ms_h(MS_DISAPPEAR_MODES[$chat_disappear]) ?></em></div>
      <div class="dd-item" id="dlItem" onclick="toggleDownloads()"><i class="ri-download-2-line"></i> <span>Let them download my media</span><em class="dd-val" id="dlVal"><?= $my_allow_dl ? 'On' : 'Off' ?></em></div>
      <?php endif; ?>
      <div class="dd-divider"></div>
      <div class="dd-item" onclick="closeMore();clearEntireChat()"><i class="ri-eraser-line"></i> Clear chat</div>
      <div class="dd-item danger" onclick="closeMore();deleteThisConversation()"><i class="ri-delete-bin-line"></i> Delete conversation</div>
      <?php endif; ?>
    </div>

    <div class="bg-picker" id="bgPicker">
      <div class="bg-picker-title"><?= $is_group ? 'Group background · everyone sees it' : 'Chat background' ?></div>
      <div class="bg-upload-area" id="bgUploadArea">
        <input type="file" id="bgFileInput" accept="image/*,video/*,.gif" hidden>
        <div class="bg-upload-box" onclick="document.getElementById('bgFileInput').click()">
          <span class="bg-upload-icon">🖼️</span>
          <span class="bg-upload-text">Tap to choose</span>
          <span class="bg-upload-sub">Image, GIF or video</span>
        </div>
      </div>
      <div class="bg-preview-wrap" id="bgPreviewWrap" style="display:none;">
        <div class="bg-preview-box" id="bgPreviewBox"></div>
        <div class="bg-preview-actions">
          <button type="button" class="bg-apply-btn" onclick="applyBg()">Apply</button>
          <button type="button" class="bg-change-btn" onclick="document.getElementById('bgFileInput').click()">Change</button>
          <button type="button" class="bg-remove-btn" onclick="removeBg()">Remove</button>
        </div>
      </div>
    </div>
  </header>

  <div class="msg-select-bar" id="msgSelectBar">
    <span class="msg-select-count" id="msgSelectCount">0 selected</span>
    <button type="button" class="act-btn" id="msgSelectAllBtn" onclick="selectAllMessages()"><i class="ri-checkbox-multiple-line"></i> All</button>
    <button type="button" class="act-btn danger" id="msgDeleteSelectedBtn" onclick="deleteSelectedMessages()"><i class="ri-delete-bin-line"></i> Delete</button>
    <button type="button" class="msg-select-close" onclick="exitMsgSelectMode()" aria-label="Cancel selection"><i class="ri-close-line"></i></button>
  </div>

  <div class="pinned-bar" id="pinnedBar" onclick="jumpToMsg(pinnedId)">
    <i class="ri-pushpin-fill"></i>
    <div class="pin-t"><strong>Pinned message</strong><span id="pinnedText"></span></div>
    <i class="ri-close-line pinned-bar-close" onclick="event.stopPropagation();unpin()"></i>
  </div>

  <?php if ($is_chat_user_restricted): ?>
  <div class="pinned-bar visible" id="reqBanner" style="cursor:default;gap:12px;flex-wrap:wrap">
    <i class="ri-inbox-line"></i>
    <div class="pin-t" style="min-width:180px"><strong>Message request</strong><span style="white-space:normal">You restricted <?= ms_h($other_info['username']) ?>. Their messages stay here, apart from your main inbox.</span></div>
    <button type="button" class="act-btn accent" onclick="requestAction('accept_request')"><i class="ri-inbox-unarchive-line"></i> Move to inbox</button>
    <button type="button" class="act-btn danger" onclick="requestAction('decline_request')"><i class="ri-delete-bin-line"></i> Delete</button>
  </div>
  <?php endif; ?>

  <div class="vanish-bar" id="vanishBar" style="<?= ($chat_disappear && !$is_group) ? '' : 'display:none' ?>"><i class="ri-timer-line"></i><span id="vanishText">Disappearing messages: <?= ms_h(MS_DISAPPEAR_MODES[$chat_disappear]) ?></span><button type="button" onclick="openDisappear()">Change</button></div>
  <div class="messages-window" id="msgWindow">
    <?php
      $intro_name = $is_group ? $group_info['name'] : $other_info['username'];
    ?>
    <div class="chat-intro" id="chatIntro" style="<?= $msgs_more ? 'display:none' : '' ?>">
      <?php if ($is_group): ?><div class="es-stack ci-link" onclick="openGroupInfo()" title="Group info" style="cursor:pointer"><?= ms_group_stack($g_pics, 'lg') ?></div>
      <?php else: ?><a class="ci-link" href="profile.php?user_id=<?= (int)$other_info['id'] ?>" title="View profile"><?php if (!empty($other_info['is_susp'])): ?><span class="susp-av es-avatar"></span><?php else: ?><img class="es-avatar" src="<?= ms_h($other_info['profile_pic'] ?? 'mee.jpg') ?>" alt="" onerror="this.src='mee.jpg'"><?php endif; ?></a><?php endif; ?>
      <?php if ($is_group): ?><div class="ci-intro-name"><?= ms_h($intro_name) ?></div><?php else: ?><a class="ci-intro-name ci-link" href="profile.php?user_id=<?= (int)$other_info['id'] ?>" style="display:inline-flex;align-items:center;justify-content:center;gap:5px"><span><?= ms_h($intro_name) ?></span><?= !empty($other_info['is_verified']) ? ms_verified_svg() : '' ?></a><?php endif; ?>
      <div class="ci-intro-sub"><?= $is_group ? count($g_members) . ' members' : ($o_nick !== '' ? '@' . ms_h($o_nick) : '') ?></div>
      <?php if ($is_group): ?><button type="button" class="es-btn" onclick="openGroupInfo()">Group info</button>
      <?php else: ?><a class="es-btn" href="profile.php?user_id=<?= (int)$other_info['id'] ?>">View profile</a><?php endif; ?>
      <?php if (!$all_msgs): ?>
      <div class="mw-empty" id="mwEmpty">
        <div class="es-title"><?= $is_group ? 'Start the group chat' : 'Start the conversation' ?></div>
        <p class="es-text"><?= $is_group ? 'Say hi to everyone in ' . ms_h($intro_name) . '. Messages, photos and videos will show up here.' : 'Say hi to ' . ms_h($other_info['username']) . '. Your messages, photos and videos will show up here.' ?></p>
        <div class="es-tips">
          <div class="es-tip"><i class="ri-reply-line"></i><span>Swipe a message to the right to reply to it.</span></div>
          <div class="es-tip"><i class="ri-timer-line"></i><span><?= $is_group ? 'Use the menu to mute this group or add people.' : 'Use the menu to mute this chat or turn on disappearing messages.' ?></span></div>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <?php if ($all_msgs): echo $is_group ? ms_group_html($conn, $all_msgs, $current_user, null, null) : ms_render_batch($all_msgs, $current_user, $shared_memes_map, null, null); endif; ?>
  </div>
  <button type="button" class="new-pill" id="newPill" onclick="scrollToBottom(true)"><i class="ri-arrow-down-line"></i> <span id="newPillText">New messages</span></button>

  <div class="input-area" id="inputArea">
    <div class="reply-bar" id="replyBar">
      <i class="ri-reply-line"></i>
      <div class="reply-bar-content">
        <div class="reply-bar-who" id="replyWho"></div>
        <div class="reply-bar-text" id="replyText"></div>
      </div>
      <button type="button" class="reply-bar-close" onclick="clearReply()" aria-label="Cancel reply"><i class="ri-close-line"></i></button>
    </div>

    <div class="attach-preview" id="attachPreview">
      <div class="attach-strip" id="attachStrip"></div>
      <div class="attach-meta"><span id="attachInfo"></span><div class="attach-progress" id="attachProgress"><i></i></div></div>
      <button type="button" class="attach-remove" onclick="clearAttach()" aria-label="Remove all attachments"><i class="ri-close-line"></i></button>
    </div>

    <div class="emoji-picker-wrap" id="emojiPicker">
      <div class="emoji-search">
        <i class="ri-search-line"></i>
        <input type="text" id="emojiSearchInput" placeholder="Search emoji">
      </div>
      <div class="emoji-tabs" id="emojiTabs"></div>
      <div class="emoji-scroll" id="emojiScroll"></div>
    </div>

    <div class="sticker-picker" id="stickerPicker">
      <div class="sticker-grid">
        <?php foreach (['🐸','🐱','💅','🦋','🌹','⚡','🎭','👑','🌙','🔮','🎪','🌈','💣','🦊','🐉','🌸'] as $s): ?>
        <div class="sticker" onclick="sendSticker('<?= $s ?>')"><?= $s ?></div>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if ($can_msg[0]): ?>
    <form method="POST" action="messages.php?<?= $is_group ? 'group=' . (int)$chat_group : 'user=' . (int)$chat_user ?>" enctype="multipart/form-data" id="chatForm" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= ms_h($csrf_token) ?>">
      <input type="hidden" name="ms_action" value="send">
      <input type="hidden" name="receiver" value="<?= (int)$chat_user ?>">
      <input type="hidden" name="reply_to" id="replyToId" value="">
      <input type="file" id="fileInput" accept="image/*,video/*" multiple style="display:none">
      <input type="file" id="camInput" accept="image/*,video/*" capture="environment" style="display:none">

      <div class="input-row">
        <div class="cmp-box" id="cmpBox">
          <button type="button" class="cmp-cam" onclick="$('camInput').click()" title="Camera" aria-label="Camera"><i class="ri-camera-fill"></i></button>
          <textarea id="msgInput" name="message" placeholder="Message…" rows="1" maxlength="4000" aria-label="Message"></textarea>
          <span class="cmp-count" id="cmpCount"></span>
          <div class="cmp-right">
            <button type="button" class="cmp-ico" id="emojiBtn" onclick="toggleEmoji()" title="Emoji" aria-label="Emoji"><i class="ri-emotion-happy-line"></i></button>
            <button type="button" class="cmp-ico cmp-idle cmp-plus" onclick="$('fileInput').click()" title="Photo or video" aria-label="Attach photos or videos"><i class="ri-add-circle-line"></i></button>
            <button type="button" class="cmp-ico cmp-idle" id="stickerBtn" onclick="toggleStickers()" title="Stickers" aria-label="Stickers"><i class="ri-sticky-note-line"></i></button>
            <?php if ($is_group): ?><button type="button" class="cmp-ico cmp-idle" id="pollBtn" onclick="openPollCreate()" title="Poll" aria-label="Create a poll"><i class="ri-bar-chart-horizontal-fill"></i></button><?php endif; ?>
          </div>
          <button type="submit" class="send-pill" id="sendBtn" aria-label="Send">Send</button>
        </div>
      </div>
    </form>
    <?php else: ?>
    <div class="composer-locked">
      <i class="ri-lock-2-line"></i>
      <div>
        <b>You can't message <?= ms_h($other_info['nickname'] ?: $other_info['username']) ?> right now</b>
        <span><?= ms_h($lock_text) ?><?php if ($can_msg[1] === 'trackers'): ?> <a href="profile.php?user_id=<?= (int)$other_info['id'] ?>">View profile</a><?php endif; ?></span>
        <?php if ($i_blocked): ?><button type="button" class="act-btn accent" style="margin-top:8px" onclick="unblockUser()"><i class="ri-user-follow-line"></i> Unblock</button><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="edit-inline-bar" id="editInlineBar">
    <span class="edit-label">Edit</span>
    <textarea class="edit-textarea" id="editTextarea" rows="1" placeholder="Edit message…" maxlength="4000"></textarea>
    <button type="button" class="edit-cancel-btn" onclick="cancelEdit()" aria-label="Cancel edit"><i class="ri-close-line"></i></button>
    <button type="button" class="edit-save-btn" onclick="saveEdit()" aria-label="Save edit"><i class="ri-check-line"></i></button>
  </div>

  <?php else: ?>
  <header class="ac-topbar chat-header">
    <button type="button" class="ac-x chat-header-back" onclick="if(window.dzHaptic)dzHaptic.light();showSidebar()" title="Back"><svg class="ms-back-svg" viewBox="0 0 14 24" aria-hidden="true"><path d="M12.4 1.6 L2 12 L12.4 22.4" fill="none" stroke="currentColor" stroke-width="1.94" stroke-linejoin="miter" stroke-linecap="butt"/></svg></button>
    <div class="chat-header-info"><div class="chat-header-name">Messages</div></div>
  </header>
  <div class="no-chat">
    <div class="nc-ic"><i class="ri-chat-smile-3-line"></i></div>
    <h2>Your messages</h2>
    <p>Pick a conversation on the left, or start a new one.</p>
    <button type="button" class="act-btn accent" onclick="openNewMsg()"><i class="ri-edit-box-line"></i> New message</button>
  </div>
  <?php endif; ?>

</section>

<?php if ($in_chat): ?>
<!-- ═══ RIGHT INFO COLUMN (wide screens) ═══════════════════ -->
<aside class="chat-info" id="chatInfo">
  <div class="ci-hero">
    <?php if ($is_group): ?>
    <div class="ci-av"><?= ms_group_stack($g_pics, 'lg') ?></div>
    <div class="ci-name"><span><?= ms_h($group_info['name']) ?></span></div>
    <div class="ci-status"><?= count($g_members) ?> members</div>
    <button type="button" class="ci-btn" onclick="openGroupInfo()"><i class="ri-group-line"></i> Group info</button>
    <?php else: ?>
    <a class="ci-av ci-link" href="profile.php?user_id=<?= (int)$other_info['id'] ?>" title="View profile">
      <?php if (!empty($other_info['is_susp'])): ?><span class="susp-av"></span><?php else: ?>
      <img class="av" src="<?= ms_h($other_info['profile_pic'] ?? 'mee.jpg') ?>" alt="" onerror="this.src='mee.jpg'">
      <?php endif; ?>
      <span class="online-dot" id="ciDot" style="<?= ($other_pres['active'] && empty($other_info['is_susp'])) ? '' : 'display:none' ?>"></span>
    </a>
    <a class="ci-name ci-link" href="profile.php?user_id=<?= (int)$other_info['id'] ?>"><span><?= ms_h($other_info['username']) ?></span><?= !empty($other_info['is_verified']) ? ms_verified_svg() : '' ?></a>
    <?php if ($o_nick !== ''): ?><div class="ci-nick">@<?= ms_h($o_nick) ?></div><?php endif; ?>
    <div class="ci-status" id="ciStatus"><?= $other_pres['visible'] ? ($other_pres['active'] ? '<span class="live">Active now</span>' : ms_h($other_pres['label'])) : '' ?></div>
    <a class="ci-btn" href="profile.php?user_id=<?= (int)$other_info['id'] ?>"><i class="ri-user-3-line"></i> View profile</a>
    <?php endif; ?>
  </div>
  <div class="ci-stats">
    <div class="stat-pill"><span class="stat-pill-num" id="ciMsgs"><?= (int)$chat_stats['messages'] ?></span><span class="stat-pill-label">Messages</span></div>
    <div class="stat-pill"><span class="stat-pill-num"><?= (int)$chat_stats['media'] ?></span><span class="stat-pill-label">Media</span></div>
    <?php if ($is_group): ?><div class="stat-pill"><span class="stat-pill-num"><?= count($g_members) ?></span><span class="stat-pill-label">Members</span></div>
    <?php else: ?><div class="stat-pill"><span class="stat-pill-num"><?= (int)$chat_stats['posts'] ?></span><span class="stat-pill-label">Posts</span></div><?php endif; ?>
  </div>
  <div class="ci-h">Shared photos &amp; videos</div>
  <?php if ($chat_media): ?>
  <div class="ci-media">
    <?php foreach ($chat_media as $k => $fm): $fx = strtolower(pathinfo($fm['path'], PATHINFO_EXTENSION)); $isv = in_array($fx, ['mp4', 'webm', 'mov'], true); ?>
      <a href="<?= ms_h($fm['path']) ?>" onclick="event.preventDefault();openViewerList(window.CI_MEDIA, <?= (int)$k ?>)">
        <?php if ($isv): ?><video src="<?= ms_h($fm['path']) ?>#t=0.1" muted preload="metadata"></video><span class="mt-play"><i class="ri-play-fill"></i></span>
        <?php else: ?><img src="<?= ms_h($fm['path']) ?>" alt="" loading="lazy" onerror="this.parentNode.style.display='none'"><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>
  <?php else: ?><div class="ci-empty">Nothing shared yet.</div><?php endif; ?>
  <div class="ci-note"><i class="ri-shield-check-line"></i><span>People can only message you the way your <b>Privacy Controls</b> allow. You can change <b>Allow messages</b> and <b>Show online status</b> in Settings.</span></div>
</aside>
<?php endif; ?>

</div><!-- end app -->

<!-- INFO PANEL -->
<div class="info-overlay ms-ui" id="infoOverlay" onclick="if(event.target===this)closeInfo()">
  <div class="info-panel">
    <div class="modal-h"><h3><i class="ri-information-line"></i> Message info</h3></div>
    <div class="modal-b">
      <div class="info-row"><span class="info-label">Sent</span><span class="info-val" id="infoSent">—</span></div>
      <div class="info-row"><span class="info-label">Seen</span><span class="info-val" id="infoSeen">—</span></div>
      <div class="info-row"><span class="info-label">Message ID</span><span class="info-val" id="infoId">—</span></div>
      <button type="button" class="info-close" onclick="closeInfo()">Close</button>
    </div>
  </div>
</div>

<!-- CONFIRM MODAL -->
<div class="confirm-overlay ms-ui" id="confirmOverlay">
  <div class="confirm-box">
    <h4 id="confirmTitle">Are you sure?</h4>
    <p id="confirmMsg">This action cannot be undone.</p>
    <div class="confirm-actions">
      <button type="button" class="confirm-btn cancel" onclick="closeConfirm()">Cancel</button>
      <button type="button" class="confirm-btn confirm" id="confirmOkBtn">Delete</button>
    </div>
  </div>
</div>

<!-- NEW MESSAGE / FORWARD PICKER -->
<div class="nm-overlay ms-ui" id="nmOverlay" onclick="if(event.target===this)closeNewMsg()">
  <div class="nm-box" role="dialog" aria-modal="true" aria-labelledby="nmTitle">
    <div class="modal-h">
      <h3 id="nmTitle"><i class="ri-edit-box-line"></i> <span id="nmTitleText">New message</span></h3>
      <button type="button" class="ac-x" onclick="closeNewMsg()" aria-label="Close"><i class="ri-close-line"></i></button>
    </div>
    <div class="sb-search nm-search" style="padding:10px var(--pad) 6px">
      <div class="sb-search-inner" id="nmSearchWrap">
        <i class="ri-search-line"></i>
        <input type="text" id="nmSearch" placeholder="Search people" autocomplete="off" spellcheck="false">
      </div>
    </div>
    <div class="nm-name" id="nmNameRow" style="display:none"><input type="text" id="ngName" maxlength="60" placeholder="Group name (optional)" autocomplete="off"></div>
    <div class="nm-loadbar" id="nmLoadBar"><i></i></div>
    <div class="sb-section" id="nmHead" style="padding-top:8px">People you track</div>
    <div class="nm-list" id="nmList"></div>
    <div class="nm-foot" id="nmFoot" style="display:none"><span id="nmFootText">Pick at least 2 people</span><button type="button" class="act-btn accent" id="nmGo" onclick="submitPicker()">Create group</button></div>
  </div>
</div>

<!-- CHOOSE A NEW OWNER (owner leaving a group) -->
<div class="info-overlay ms-ui" id="ownOverlay" onclick="if(event.target===this)closeOwner()">
  <div class="info-panel gi-panel">
    <div class="modal-h"><h3><i class="ri-vip-crown-line"></i> New owner</h3><button type="button" class="ac-x" onclick="closeOwner()" aria-label="Close"><i class="ri-close-line"></i></button></div>
    <div class="modal-b gi-body">
      <p class="dm-note" style="padding:14px var(--pad) 8px">You created this group. Pick who becomes the owner before you leave.</p>
      <div class="gi-list" id="ownList"></div>
      <button type="button" class="act-btn danger gi-leave" id="ownGo" onclick="confirmOwnerLeave()" disabled>Make owner &amp; leave</button>
    </div>
  </div>
</div>

<!-- GROUP INFO -->
<div class="info-overlay ms-ui" id="giOverlay" onclick="if(event.target===this)closeGroupInfo()">
  <div class="info-panel gi-panel">
    <div class="modal-h"><h3><i class="ri-group-line"></i> Group info</h3><button type="button" class="ac-x" onclick="closeGroupInfo()" aria-label="Close"><i class="ri-close-line"></i></button></div>
    <div class="modal-b gi-body">
      <div class="gi-name" id="giNameRow"><input type="text" id="giName" maxlength="60" aria-label="Group name" readonly><button type="button" class="act-btn accent" id="giSave" style="display:none" onclick="saveGroupName()">Save</button></div>
      <div class="gi-count" id="giCount"></div>
      <div class="gi-list" id="giList"><div class="nm-loading"><span class="spin"></span> Loading…</div></div>
      <button type="button" class="act-btn accent gi-add" id="giAdd" style="display:none" onclick="closeGroupInfo();openAddPeople()"><i class="ri-user-add-line"></i> Add people</button>
      <button type="button" class="act-btn danger gi-leave" onclick="closeGroupInfo();leaveGroup()"><i class="ri-logout-box-r-line"></i> Leave group</button>
      <?php if ($g_is_owner): ?><button type="button" class="act-btn danger gi-leave gi-del" onclick="closeGroupInfo();deleteGroup()"><i class="ri-delete-bin-6-line"></i> Delete group</button><?php endif; ?>
    </div>
  </div>
</div>

<!-- LIGHTBOX -->
<div class="lightbox ms-ui" id="lightbox" onclick="closeLightbox()"><img id="lightboxImg" src="" alt=""></div>

<!-- NEW POLL (group chats) -->
<div class="pl-ov ms-ui" id="plOv" onclick="if(event.target===this)closePollCreate()">
  <div class="pl-sheet" role="dialog" aria-modal="true" aria-label="New poll">
    <div class="pl-head">
      <h3><i class="ri-bar-chart-horizontal-fill"></i> New poll</h3>
      <button type="button" class="pl-x" onclick="closePollCreate()" aria-label="Close"><i class="ri-close-line"></i></button>
    </div>
    <div class="pl-body">
      <div class="pl-lab"><span>Question</span><em id="plQc">0/200</em></div>
      <textarea class="pl-q" id="plQ" maxlength="200" placeholder="What do you want to ask?" rows="2"></textarea>
      <div class="pl-lab"><span>Options</span><em>2 to 12</em></div>
      <div id="plOpts"></div>
      <button type="button" class="pl-add" id="plAdd"><i class="ri-add-line" style="font-size:16px"></i> Add option</button>
      <label class="pl-multi"><span class="t">Allow several answers</span><input type="checkbox" id="plMulti"><span class="sw"></span></label>
    </div>
    <div class="pl-foot"><button type="button" class="pl-send" id="plSend" disabled><i class="ri-send-plane-2-fill"></i> Send poll</button></div>
  </div>
</div>

<!-- PHOTO / VIDEO VIEWER -->
<div class="mv ms-ui" id="mvOverlay" role="dialog" aria-modal="true" aria-label="Photo and video viewer">
  <div class="mv-bg" id="mvBg"></div>
  <div class="mv-top">
    <button type="button" class="mv-btn mv-back" onclick="closeViewer()" aria-label="Back"><svg viewBox="0 0 14 24" fill="none" stroke="currentColor" stroke-width="1.94" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 2 12l10 10"/></svg></button>
    <img class="mv-av" id="mvAv" alt="" src="mee.jpg">
    <div class="mv-title"><b id="mvWho"></b><span id="mvCount"></span></div>
    <div class="mv-acts">
      <a class="mv-btn mv-dl" id="mvDl" href="#" download title="Download" aria-label="Download"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3.5v11M7.5 10.5 12 15l4.5-4.5M5 20h14"/></svg></a>
      <span class="mv-nodl" id="mvNoDl" title="The sender turned off downloads"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3.5v11M7.5 10.5 12 15l4.5-4.5M5 20h14"/></svg></span>
    </div>
  </div>
  <div class="mv-stage" id="mvStage"><div class="mv-spin" id="mvSpin"></div></div>
  <button type="button" class="mv-nav prev" id="mvPrev" onclick="viewerStep(-1)" aria-label="Previous"><i class="ri-arrow-left-s-line"></i></button>
  <button type="button" class="mv-nav next" id="mvNext" onclick="viewerStep(1)" aria-label="Next"><i class="ri-arrow-right-s-line"></i></button>
  <div class="mv-dots" id="mvDots"></div>
  <div class="mv-spacer"></div>
</div>

<!-- DISAPPEARING MESSAGES -->
<div class="info-overlay ms-ui" id="dmOverlay" onclick="if(event.target===this)closeDisappear()">
  <div class="info-panel dm-panel">
    <div class="modal-h"><h3><i class="ri-timer-line"></i> Disappearing messages</h3><button type="button" class="ac-x" onclick="closeDisappear()" aria-label="Close"><i class="ri-close-line"></i></button></div>
    <div class="modal-b">
      <p class="dm-note">New messages in this chat will disappear for both of you. Messages already sent are not changed.</p>
      <?php foreach ([0 => 'Nothing disappears', 1 => 'Removed once they are seen and the chat is reopened', 2 => 'Removed 24 hours after sending', 3 => 'Removed 7 days after sending', 4 => 'Removed 2 weeks after sending'] as $mk => $md): ?>
      <button type="button" class="dm-opt" data-mode="<?= $mk ?>" onclick="setDisappear(<?= $mk ?>)">
        <span class="dm-radio"></span><span class="dm-t"><b><?= ms_h(MS_DISAPPEAR_MODES[$mk]) ?></b><small><?= ms_h($md) ?></small></span>
      </button>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script>/* ── CSRF: every same-origin POST made with fetch() carries the token automatically.
      This is what protects the meme modal's calls without touching its code. ── */
(function () {
    const _fetch = window.fetch;
    const token  = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    window.fetch = function (input, init) {
        try {
            init = init || {};
            const isReq  = typeof input !== 'string' && input && typeof input.url === 'string';
            const url    = isReq ? input.url : String(input);
            const method = String(init.method || (isReq ? input.method : 'GET')).toUpperCase();
            if (method !== 'GET' && method !== 'HEAD' && new URL(url, location.href).origin === location.origin) {
                const h = new Headers(init.headers || (isReq ? input.headers : undefined) || {});
                if (!h.has('X-CSRF-Token')) h.set('X-CSRF-Token', token);
                init = Object.assign({}, init, { headers: h });
            }
        } catch (e) {}
        return _fetch.call(this, input, init);
    };
})();

// ── GLOBALS ──────────────────────────────────────────────────
var currentUserId = <?= (int)$current_user ?>;   /* var (not const): zeems_modal.php declares the same name */
const chatUserId    = <?= $chat_user ? (int)$chat_user : 'null' ?>;
const GROUP_ID      = <?= $is_group ? (int)$chat_group : 'null' ?>;
const IS_GROUP      = GROUP_ID !== null;
const CHAT_ON       = !!(chatUserId || GROUP_ID);
const chatKey       = IS_GROUP ? 'g' + GROUP_ID : chatUserId;
const GROUPS_ON     = <?= $MS_GRP ? 'true' : 'false' ?>;
let   GROUP_BG = <?= json_encode($g_bg) ?>;
/* measure the bottom nav so the lists always end above it */
(function(){
    function measure(){
        var h = 0, vh = window.innerHeight;
        document.querySelectorAll('body *').forEach(function(el){
            if(el.closest('.app')) return;
            var cs = getComputedStyle(el);
            if(cs.position !== 'fixed' || cs.display === 'none' || cs.visibility === 'hidden') return;
            var r = el.getBoundingClientRect();
            if(r.height > 30 && r.height < 160 && r.width > window.innerWidth * .6 && Math.abs(r.bottom - vh) < 3) h = Math.max(h, r.height);
        });
        if(!h && window.innerWidth <= 768 && document.querySelector('nav')) h = 64;
        document.documentElement.style.setProperty('--ms-nav-pad', h + 'px');
    }
    measure(); setTimeout(measure, 400); setTimeout(measure, 1500);
    window.addEventListener('resize', measure); window.addEventListener('orientationchange', function(){ setTimeout(measure, 200); });
})();
const IS_OWNER      = <?= $g_is_owner ? 'true' : 'false' ?>;
let   olderMore     = <?= $msgs_more ? 'true' : 'false' ?>, olderLoading = false;
let   groupMore     = <?= $group_more ? 'true' : 'false' ?>, groupLoading = false;
let   typingEl = null, typingSentAt = 0, typingOn = false;
let   archBack = 'main', archN = <?= (int)$arch_count ?>, curArchived = <?= $cur_archived ? 'true' : 'false' ?>;
const ARC_ON = <?= $MS_ARC ? 'true' : 'false' ?>;
let   curTab = <?= $is_group ? "'groups'" : "'main'" ?>;
const CSRF_TOKEN    = <?= json_encode($csrf_token) ?>;
const SERVER_NOW    = <?= time() ?>;
const SKEW          = SERVER_NOW - Math.floor(Date.now() / 1000);
const EDIT_WINDOW   = <?= (int)MS_EDIT_WINDOW ?>;
const MAX_TEXT      = <?= (int)MS_MAX_TEXT ?>;
const MAX_UPLOAD    = <?= (int)MS_MAX_UPLOAD ?>;
const FIND_ID       = <?= isset($_GET['find']) ? (int)$_GET['find'] : 0 ?>;
const CAN_SEND      = <?= ($in_chat && $can_msg[0]) ? 'true' : 'false' ?>;
const IS_REQ_CHAT   = <?= $is_chat_user_restricted ? 'true' : 'false' ?>;
let   lastMsgId     = <?= (int)$last_msg_id ?>;
const FEAT          = <?= $MS_FEAT ? 'true' : 'false' ?>;
let   convMore      = <?= $conv_more ? 'true' : 'false' ?>, convLoading = false;
let   vanishMode    = <?= (int)$chat_disappear ?>;
let   chatMuted     = <?= $chat_muted ? 'true' : 'false' ?>;
let   allowDl       = <?= $my_allow_dl ? 'true' : 'false' ?>;
let   newCount      = 0;
window.CI_MEDIA     = <?= json_encode(array_map(function ($m) { $x = strtolower(pathinfo($m['path'], PATHINFO_EXTENSION)); return ['s' => $m['path'], 't' => in_array($x, ['mp4','webm','mov'], true) ? 'v' : 'i', 'id' => $m['id'], 'd' => $m['dl']]; }, $chat_media)) ?>;
const VANISH_NAMES  = ['Off', 'After seen', '24 hours', '7 days', '2 weeks'];
const OTHER_NAME    = <?= json_encode($is_group ? $group_info['name'] : ($other_info ? ($other_info['username'] ?? '') : '')) ?>;
let   openDropdownId = null;
let   editingMsgId   = null;
let   msgSelectMode  = false;
let   selectedMsgIds = new Set();
let   pinnedId       = null;
let   reactionsStore = {}, myReactions = {};
try {
    reactionsStore = JSON.parse(localStorage.getItem('peura_reactions_' + chatKey) || '{}');
    myReactions    = JSON.parse(localStorage.getItem('peura_my_reactions_' + chatKey) || '{}');
} catch (e) {}

const $ = id => document.getElementById(id);
const nowSrv = () => Math.floor(Date.now() / 1000) + SKEW;
function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
function hl(text, q) {
    text = String(text == null ? '' : text);
    if (!q) return esc(text);
    const re = new RegExp(q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'ig');
    let out = '', last = 0, m;
    while ((m = re.exec(text))) {
        out += esc(text.slice(last, m.index)) + '<mark>' + esc(m[0]) + '</mark>';
        last = m.index + m[0].length;
        if (m[0].length === 0) re.lastIndex++;
    }
    return out + esc(text.slice(last));
}
function lsGet(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
function lsSet(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
function lsDel(k) { try { localStorage.removeItem(k); } catch (e) {} }

// ── TOAST ─────────────────────────────────────────────────────
function toast(msg) {
    const t = document.createElement('div');
    t.className = 'toast';
    t.textContent = msg;
    document.body.appendChild(t);
    requestAnimationFrame(() => t.classList.add('show'));
    setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 300); }, 2200);
}

// ── API ───────────────────────────────────────────────────────
async function apiFetch(params) {
    try {
        const res = await fetch('messages.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params instanceof URLSearchParams ? params : new URLSearchParams(params)
        });
        let data = null;
        try { data = await res.json(); } catch (e) {}
        if (!data) { toast('Server error. Please try again.'); return null; }
        if (res.status === 419) toast(data.error || 'Your session expired. Refresh the page.');
        return data;
    } catch (err) {
        toast('Request failed. Check your connection.');
        return null;
    }
}
const act = (ms_action, extra) => apiFetch(Object.assign({ ms_action: ms_action }, extra || {}));

// ── CONFIRM / INFO / LIGHTBOX ─────────────────────────────────
let confirmCallback = null;
function showConfirm(title, msg, onConfirm) {
    $('confirmTitle').textContent = title;
    $('confirmMsg').textContent   = msg;
    confirmCallback = onConfirm;
    $('confirmOverlay').classList.add('open');
}
function closeConfirm() { $('confirmOverlay').classList.remove('open'); confirmCallback = null; }
$('confirmOkBtn').addEventListener('click', () => { const cb = confirmCallback; closeConfirm(); if (cb) cb(); });
function closeInfo() { $('infoOverlay').classList.remove('open'); }
function openLightbox(src) { $('lightboxImg').src = src; $('lightbox').classList.add('open'); }
function closeLightbox() { $('lightbox').classList.remove('open'); }

document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    if ($('lightbox').classList.contains('open')) { closeLightbox(); return; }
    if ($('confirmOverlay').classList.contains('open')) { closeConfirm(); return; }
    if ($('infoOverlay').classList.contains('open')) { closeInfo(); return; }
    if ($('ownOverlay').classList.contains('open')) { closeOwner(); return; }
    if ($('giOverlay').classList.contains('open')) { closeGroupInfo(); return; }
    if ($('nmOverlay').classList.contains('open')) { closeNewMsg(); return; }
    if (editingMsgId) { cancelEdit(); return; }
    ['emojiPicker', 'stickerPicker', 'bgPicker', 'moreMenu'].forEach(id => $(id) && $(id).classList.remove('open'));
    if (msgSelectMode) exitMsgSelectMode();
});

// ── SIDEBAR: tabs, mobile, search ─────────────────────────────
const sidebar = $('ms-sidebar');
function showSidebar() { sidebar.classList.remove('hidden'); document.body.classList.remove('ms-chat-open'); }
if (CHAT_ON && window.innerWidth <= 768) { sidebar.classList.add('hidden'); document.body.classList.add('ms-chat-open'); }
function openChat(uid) { window.location.href = 'messages.php?user=' + uid; }

function switchTab(which) {
    if (which === 'arch' && !$('archList')) which = 'main';
    if (which === 'arch' && curTab !== 'arch') archBack = curTab;
    curTab = which;
    $('tabMain').classList.toggle('active', which === 'main');
    if ($('tabGroups')) $('tabGroups').classList.toggle('active', which === 'groups');
    $('tabReq').classList.toggle('active', which === 'req');
    $('convList').style.display = which === 'main' ? '' : 'none';
    $('reqList').style.display  = which === 'req' ? '' : 'none';
    if ($('groupList')) $('groupList').style.display = which === 'groups' ? '' : 'none';
    if ($('archList')) $('archList').style.display = which === 'arch' ? '' : 'none';
    if ($('bulkDeleteBtn')) $('bulkDeleteBtn').style.display = which === 'groups' ? 'none' : '';
    const ba = $('bulkArchiveBtn'); if (ba) ba.querySelector('span').textContent = which === 'arch' ? 'Unarchive' : 'Archive';
    if (selectedConvs.size) clearConvSelection();
    if (which === 'groups') loadMoreGroups(true);
}
const tabsShown = () => GROUPS_ON || parseInt($('reqCount').textContent || '0', 10) > 0;

const convSearch = $('convSearch'), searchWrap = $('convSearchWrap'), searchRes = $('searchResults');
let searchTimer = null, searchSeq = 0;

function skeletonRows(n) {
    let h = '';
    for (let i = 0; i < n; i++) h += '<div class="sr-skel"><div class="sk-bar" style="width:44px;height:44px;border-radius:50%"></div><div style="flex:1"><div class="sk-bar" style="height:11px;width:' + (40 + (i * 13) % 30) + '%;margin-bottom:8px"></div><div class="sk-bar" style="height:9px;width:' + (65 + (i * 9) % 25) + '%"></div></div></div>';
    return h;
}
function beginSearch() {
    $('convList').style.display = 'none';
    $('reqList').style.display = 'none';
    if ($('groupList')) $('groupList').style.display = 'none';
    $('sbTabs').style.display = 'none';
    searchRes.style.display = '';
}
function endSearch() {
    searchSeq++;
    searchRes.style.display = 'none';
    searchRes.innerHTML = '';
    $('sbTabs').style.display = tabsShown() ? '' : 'none';
    switchTab(curTab);
}
convSearch.addEventListener('input', () => {
    const q = convSearch.value.trim();
    searchWrap.classList.toggle('has-q', convSearch.value.length > 0);
    clearTimeout(searchTimer);
    if (!q) { endSearch(); return; }
    beginSearch();
    searchRes.innerHTML = skeletonRows(4);
    searchTimer = setTimeout(() => runSearch(q), 260);
});
$('convSearchClear').addEventListener('click', () => { convSearch.value = ''; convSearch.dispatchEvent(new Event('input')); convSearch.focus(); });

async function runSearch(q) {
    const seq = ++searchSeq;
    try {
        const res = await fetch('messages.php?ms=search&q=' + encodeURIComponent(q), { cache: 'no-store' });
        const d = await res.json();
        if (seq !== searchSeq) return;
        renderSearch(d.results || [], q);
    } catch (e) {
        if (seq === searchSeq) searchRes.innerHTML = '<div class="sb-empty"><i class="ri-wifi-off-line"></i><b>Search failed</b>Check your connection and try again.</div>';
    }
}
function renderSearch(list, q) {
    if (!list.length) {
        searchRes.innerHTML = '<div class="sb-empty"><i class="ri-search-eye-line"></i><b>No results</b>No conversation or message matches “' + esc(q) + '”.</div>';
        return;
    }
    let h = '<div class="sb-section">' + list.length + ' conversation' + (list.length === 1 ? '' : 's') + '</div>';
    list.forEach(r => {
        const href = 'messages.php?user=' + r.id + (r.hit_id ? '&find=' + r.hit_id : '');
        const line = r.snippet
            ? '<div class="sr-snip"><i class="ri-chat-search-line"></i>' + hl(r.snippet, q) + '</div>'
            : '<div class="conv-last">' + esc(r.last) + '</div>';
        h += '<div class="conv-item sr-item" onclick="location.href=\'' + href + '\'">'
           + '<div class="conv-avatar"><img src="' + esc(r.pic) + '" alt="" onerror="this.src=\'mee.jpg\'"></div>'
           + '<div class="conv-body"><div class="conv-name"><span class="conv-name-t">' + hl(r.username, q) + '</span>'
           + (r.nickname ? '<span style="font-weight:400;color:var(--muted);font-size:.66rem">@' + hl(r.nickname, q) + '</span>' : '')
           + (r.request ? '<span class="request-tag">Request</span>' : '') + '</div>' + line + '</div>'
           + '<div class="conv-meta"><span class="conv-time">' + esc(r.hit_time || r.time) + '</span></div></div>';
    });
    searchRes.innerHTML = h;
}

// ── SIDEBAR BULK SELECT ───────────────────────────────────────
const selectToggle = $('selectToggle'), bulkBar = $('bulkBar'), bulkCount = $('bulkCount');
const bulkDeleteBtn = $('bulkDeleteBtn'), bulkSelectAllBtn = $('bulkSelectAll');
let selectedConvs = new Set();
const convKey = el => el.dataset.gid ? 'g' + el.dataset.gid : String(el.dataset.uid);
const activeConvItems = () => Array.from(document.querySelectorAll({ main: '#convList', req: '#reqList', groups: '#groupList', arch: '#archItems' }[curTab] + ' .conv-item'));
function updateBulkUI() {
    const n = selectedConvs.size;
    bulkCount.textContent = n + ' selected';
    bulkDeleteBtn.style.opacity = n > 0 ? '1' : '.5';
    bulkDeleteBtn.style.pointerEvents = n > 0 ? 'auto' : 'none';
}
function clearConvSelection() {
    selectedConvs.clear();
    document.querySelectorAll('.conv-item.selected').forEach(el => el.classList.remove('selected'));
    updateBulkUI();
}
selectToggle.addEventListener('click', () => {
    const on = sidebar.classList.toggle('select-mode');
    selectToggle.classList.toggle('active', on);
    bulkBar.classList.toggle('active', on);
    if (!on) clearConvSelection();
    updateBulkUI();
});
bulkSelectAllBtn.addEventListener('click', () => {
    const items = activeConvItems();
    const all = items.length && items.every(el => selectedConvs.has(convKey(el)));
    items.forEach(el => { if (all) { el.classList.remove('selected'); selectedConvs.delete(convKey(el)); } else { el.classList.add('selected'); selectedConvs.add(convKey(el)); } });
    updateBulkUI();
});
bulkDeleteBtn.addEventListener('click', () => {
    if (!selectedConvs.size) return;
    const n = selectedConvs.size;
    showConfirm('Delete ' + n + ' conversation' + (n > 1 ? 's' : '') + '?', 'All messages in these conversations will be permanently removed.', async () => {
        const targets = Array.from(selectedConvs).filter(k => k[0] !== 'g');
        if (targets.length < selectedConvs.size) toast('Groups are left from their own menu');
        for (const uid of targets) {
            const data = await act('delete_conv', { with_user: uid });
            const el = document.querySelector('.conv-item[data-uid="' + uid + '"]');
            if (data && data.success && el) { el.classList.add('leaving'); setTimeout(() => el.remove(), 360); }
        }
        clearConvSelection();
        toast('Conversations deleted');
        if (targets.includes(String(chatUserId))) setTimeout(() => { window.location.href = 'messages.php'; }, 450);
    });
});
if (IS_REQ_CHAT) switchTab('req');
function groupClick(e, gid) {
    if (sidebar.classList.contains('select-mode')) {
        e.preventDefault(); e.stopPropagation();
        const el = e.currentTarget, k = 'g' + gid;
        if (selectedConvs.has(k)) { selectedConvs.delete(k); el.classList.remove('selected'); } else { selectedConvs.add(k); el.classList.add('selected'); }
        updateBulkUI();
        return;
    }
    openGroup(gid);
}
// ── ARCHIVE ────────────────────────────────────────────────────
async function refreshArchive() {
    if (!ARC_ON || !$('archItems')) return;
    try {
        const res = await fetch('messages.php?ms=archive' + (chatUserId ? '&user=' + chatUserId : '') + (IS_GROUP ? '&group=' + GROUP_ID : ''), { cache: 'no-store' });
        const d = await res.json();
        if (!d.success) return;
        $('archItems').innerHTML = d.html || '';
        archN = d.count; $('archCount').textContent = d.count;
        $('archRow').style.display = d.count > 0 ? '' : 'none';
        if (!d.count && curTab === 'arch') switchTab(archBack || 'main');
    } catch (e) {}
}
async function archiveKeys(keys, on) {
    const us = keys.filter(k => k[0] !== 'g'), gs = keys.filter(k => k[0] === 'g').map(k => k.slice(1));
    let ok = true;
    if (us.length) { const d = await act('archive', { kind: 'u', ids: us.join(','), on: on ? 1 : 0 }); ok = ok && !!(d && d.success); }
    if (gs.length) { const d = await act('archive', { kind: 'g', ids: gs.join(','), on: on ? 1 : 0 }); ok = ok && !!(d && d.success); }
    return ok;
}
if ($('bulkArchiveBtn')) $('bulkArchiveBtn').addEventListener('click', async () => {
    if (!selectedConvs.size) return;
    const keys = Array.from(selectedConvs), on = curTab !== 'arch';
    if (await archiveKeys(keys, on)) {
        toast(on ? 'Archived' : 'Moved back to your chats');
        if (!on) { window.location.reload(); return; }
        document.querySelectorAll('.conv-item.selected').forEach(el => el.remove());
        clearConvSelection(); if (sidebar.classList.contains('select-mode')) selectToggle.click();
        refreshArchive();
    } else toast('Could not archive. Try again.');
});
async function toggleArchiveCurrent() {
    const on = !curArchived;
    const ok = await archiveKeys([IS_GROUP ? 'g' + GROUP_ID : String(chatUserId)], on);
    if (!ok) { toast('Could not change the archive'); return; }
    curArchived = on;
    toast(on ? (IS_GROUP ? 'Group archived' : 'Chat archived') : 'Moved back to your chats');
    if (on) { document.querySelectorAll('#convList .conv-item.active, #groupList .conv-item.active').forEach(el => el.remove()); refreshArchive(); }
    else window.location.reload();
    document.querySelectorAll('#moreMenu .dd-item span').forEach(s => { if (/rchive (chat|group)$/.test(s.textContent)) s.textContent = (on ? 'Unarchive ' : 'Archive ') + (IS_GROUP ? 'group' : 'chat'); });
}
function convClick(e, uid) {
    if (sidebar.classList.contains('select-mode')) {
        e.preventDefault(); e.stopPropagation();
        const el = e.currentTarget, k = String(uid);
        if (selectedConvs.has(k)) { selectedConvs.delete(k); el.classList.remove('selected'); } else { selectedConvs.add(k); el.classList.add('selected'); }
        updateBulkUI();
        return;
    }
    openChat(uid);
}

// ── NEW MESSAGE / FORWARD / NEW GROUP / ADD PEOPLE PICKER ─────
let nmMode = 'new', nmPayload = null, nmTimer = null, nmSeq = 0;
const nmSel = new Set();
const nmMulti = () => nmMode === 'group' || nmMode === 'add';
function openNm() {
    $('nmOverlay').classList.add('open');
    $('nmSearch').value = '';
    $('nmNameRow').style.display = nmMode === 'group' ? '' : 'none';
    $('nmFoot').style.display = nmMulti() ? '' : 'none';
    if (!nmMulti()) nmSel.clear();
    updatePickFoot();
    loadPeople('');
    setTimeout(() => { if (window.innerWidth > 800) $('nmSearch').focus(); }, 80);
}
function openNewMsg() {
    if (GROUPS_ON && curTab === 'groups') { openNewGroup(); return; }
    nmMode = 'new'; nmPayload = null; $('nmTitleText').textContent = 'New message'; openNm();
}
function openForward(text) { nmMode = 'forward'; nmPayload = text; $('nmTitleText').textContent = 'Forward to'; openNm(); }
function openNewGroup() { nmMode = 'group'; nmSel.clear(); $('ngName').value = ''; $('nmTitleText').textContent = 'New group'; $('nmGo').textContent = 'Create group'; openNm(); }
function openAddPeople() { if (!IS_GROUP) return; nmMode = 'add'; nmSel.clear(); $('nmTitleText').textContent = 'Add people'; $('nmGo').textContent = 'Add'; openNm(); }
function closeNewMsg() { $('nmOverlay').classList.remove('open'); }
function updatePickFoot() {
    const n = nmSel.size, need = nmMode === 'group' ? 2 : 1, go = $('nmGo');
    $('nmFootText').textContent = n >= need ? n + ' selected' : (nmMode === 'group' ? 'Pick at least 2 people' : 'Pick someone to add');
    go.disabled = n < need;
}
$('nmSearch').addEventListener('input', () => { clearTimeout(nmTimer); nmTimer = setTimeout(() => loadPeople($('nmSearch').value.trim()), 240); });
async function loadPeople(q) {
    const seq = ++nmSeq;
    $('nmHead').textContent = q ? 'Results' : (nmMulti() ? 'People you chat with' : 'People you track');
    $('nmList').innerHTML = '<div class="nm-loading"><span class="spin"></span> Loading people…</div>' + skeletonRows(4);
    $('nmLoadBar').classList.add('on');
    try {
        const t0 = Date.now();
        const res = await fetch('messages.php?ms=people&q=' + encodeURIComponent(q) + (nmMulti() ? '&chats=1' : '') + (nmMode === 'add' ? '&group=' + GROUP_ID : ''), { cache: 'no-store' });
        const d = await res.json();
        const wait = 520 - (Date.now() - t0);                      // always show the loading state briefly so it never just "pops"
        if (wait > 0) await new Promise(r => setTimeout(r, wait));
        if (seq !== nmSeq) return;
        $('nmLoadBar').classList.remove('on');
        const list = d.results || [];
        const head = (nmMode === 'new' && GROUPS_ON && !q) ? '<div class="nm-item nm-newgroup" onclick="openNewGroup()"><div class="ng-ic"><i class="ri-group-line"></i></div><div class="t"><div class="n">New group</div><div class="s">Chat with two or more people</div></div><i class="ri-arrow-right-s-line" style="color:var(--muted);font-size:20px"></i></div>' : '';
        if (!list.length) {
            $('nmList').innerHTML = head + '<div class="sb-empty"><i class="ri-user-search-line"></i><b>' + (q ? 'Nobody found' : (nmMulti() ? 'No chats to pick from' : 'You are not tracking anyone yet')) + '</b>' + (nmMulti() ? 'You can only add people you already have a chat with, who accept messages from you.' : (q ? 'Only people who accept messages from you are listed.' : 'Search for a username or nickname above.')) + '</div>';
            return;
        }
        $('nmList').innerHTML = head + list.map(u => '<div class="nm-item' + (nmSel.has(u.id) ? ' sel' : '') + '" data-id="' + u.id + '" onclick="pickPerson(' + u.id + ', this)"><img src="' + esc(u.pic) + '" alt="" onerror="this.src=\'mee.jpg\'"><div class="t"><div class="n">' + hl(u.username, q) + (u.verified ? '<svg width="13" height="13" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="12" fill="#ff2d2d"/><path d="M8 12l3 3 5-5" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>' : '') + '</div><div class="s">' + (u.nickname ? '@' + hl(u.nickname, q) : '&nbsp;') + '</div></div>' + (nmMulti() ? '<span class="pick"><i class="ri-check-line"></i></span>' : (u.tracked ? '<span class="tag">Tracking</span>' : '')) + '</div>').join('');
    } catch (e) {
        if (seq === nmSeq) { $('nmLoadBar').classList.remove('on'); $('nmList').innerHTML = '<div class="sb-empty"><i class="ri-wifi-off-line"></i><b>Could not load people</b></div>'; }
    }
}
async function pickPerson(uid, el) {
    if (nmMulti()) {
        if (nmSel.has(uid)) nmSel.delete(uid); else nmSel.add(uid);
        if (el) el.classList.toggle('sel', nmSel.has(uid));
        updatePickFoot();
        return;
    }
    if (nmMode === 'new') { openChat(uid); return; }
    const text = nmPayload;
    closeNewMsg();
    const d = await act('send', { receiver: uid, message: text, ajax: 1 });
    if (d && d.success) {
        toast('Forwarded');
        if (uid === chatUserId && d.html) appendHtml(d.html, true);
    } else if (d) toast(d.error || 'Could not forward');
}
async function submitPicker() {
    const ids = Array.from(nmSel), btn = $('nmGo');
    if (!ids.length || btn.disabled) return;
    btn.disabled = true;
    if (nmMode === 'group') {
        const d = await act('group_create', { name: $('ngName').value.trim(), members: ids.join(',') });
        if (d && d.success) { if (d.skipped && d.skipped.length) toast('Not added (their settings): ' + d.skipped.join(', ')); window.location.href = 'messages.php?group=' + d.id; return; }
        toast((d && d.error) || 'Could not create the group');
    } else {
        const d = await act('group_add', { group: GROUP_ID, members: ids.join(',') });
        if (d && d.success) { toast('Added ' + d.added.join(', ') + (d.skipped && d.skipped.length ? ' · not added: ' + d.skipped.join(', ') : '')); closeNewMsg(); setTimeout(() => window.location.reload(), 700); return; }
        toast((d && d.error) || 'Could not add people');
    }
    updatePickFoot();
}

// ── GROUPS: list, info, leave ─────────────────────────────────
function openGroup(id) { window.location.href = 'messages.php?group=' + id; }
async function loadMoreGroups(fillOnly) {
    const gl = $('groupList');
    if (!gl || groupLoading || !groupMore) return;
    if (fillOnly && gl.scrollHeight > gl.clientHeight + 40) return;
    groupLoading = true;
    const loader = document.createElement('div'); loader.className = 'conv-more-loader'; loader.innerHTML = '<span class="spin"></span> Loading groups…';
    gl.appendChild(loader);
    try {
        const off = gl.querySelectorAll('.conv-item').length;
        const res = await fetch('messages.php?ms=groups&offset=' + off + (IS_GROUP ? '&group=' + GROUP_ID : ''), { cache: 'no-store' });
        const d = await res.json();
        await new Promise(r => setTimeout(r, 250));
        loader.remove();
        const tpl = document.createElement('template'); tpl.innerHTML = d.html || '';
        const have = new Set(Array.from(gl.querySelectorAll('.conv-item')).map(e => e.dataset.gid));
        Array.from(tpl.content.children).forEach(n => { if (!have.has(n.dataset.gid)) gl.appendChild(n); });
        groupMore = !!d.more;
    } catch (e) { loader.remove(); }
    groupLoading = false;
    if (groupMore && gl.scrollHeight <= gl.clientHeight + 40) loadMoreGroups();
}
(function () {
    const gl = $('groupList'); if (!gl) return;
    gl.addEventListener('scroll', () => { if (gl.scrollTop + gl.clientHeight >= gl.scrollHeight - 160) loadMoreGroups(); }, { passive: true });
})();
async function openGroupInfo(focusName) {
    if (!IS_GROUP) return;
    $('giOverlay').classList.add('open');
    $('giList').innerHTML = '<div class="nm-loading"><span class="spin"></span> Loading…</div>';
    try {
        const res = await fetch('messages.php?ms=ginfo&group=' + GROUP_ID, { cache: 'no-store' });
        const d = await res.json();
        if (!d.success) throw new Error('x');
        const nm = $('giName'); nm.value = d.name; nm.readOnly = !d.is_owner;
        $('giSave').style.display = d.is_owner ? '' : 'none';
        if (focusName && d.is_owner) setTimeout(() => { nm.focus(); nm.select(); }, 120);
        $('giAdd').style.display = d.is_owner ? '' : 'none';
        $('giCount').textContent = d.members.length + ' of ' + d.max + ' people' + (d.is_owner ? ' · you created this group' : '');
        $('giList').innerHTML = d.members.map(m => '<a class="gi-m" href="profile.php?user_id=' + m.id + '"><img src="' + esc(m.pic) + '" alt="" onerror="this.src=\'mee.jpg\'"><div class="t"><div class="n">' + esc(m.username) + (m.id === currentUserId ? ' <span style="color:var(--muted);font-weight:400">(you)</span>' : '') + '</div><div class="s">' + (m.nickname ? '@' + esc(m.nickname) : '&nbsp;') + '</div></div>' + (m.owner ? '<span class="tag">Owner</span>' : '') + '</a>').join('');
    } catch (e) { $('giList').innerHTML = '<div class="sb-empty"><i class="ri-wifi-off-line"></i><b>Could not load members</b></div>'; }
}
function closeGroupInfo() { $('giOverlay').classList.remove('open'); }
async function saveGroupName() {
    const name = $('giName').value.trim(); if (!name) { toast('Give the group a name'); return; }
    const d = await act('group_rename', { group: GROUP_ID, name: name });
    if (d && d.success) { toast('Group renamed'); const h = $('hdrName'); if (h) h.textContent = d.name; closeGroupInfo(); }
    else toast((d && d.error) || 'Could not rename');
}
let ownSel = 0;
async function leaveGroup() {
    if (IS_OWNER) {   // the owner hands the group to someone before leaving
        try {
            const res = await fetch('messages.php?ms=ginfo&group=' + GROUP_ID, { cache: 'no-store' });
            const d = await res.json();
            const others = (d.members || []).filter(m => m.id !== currentUserId);
            if (others.length) {
                ownSel = 0; $('ownGo').disabled = true;
                $('ownList').innerHTML = others.map(m => '<div class="gi-m own-pick" data-id="' + m.id + '" onclick="pickOwner(' + m.id + ', this)"><img src="' + esc(m.pic) + '" alt="" onerror="this.src=\'mee.jpg\'"><div class="t"><div class="n">' + esc(m.username) + '</div><div class="s">' + (m.nickname ? '@' + esc(m.nickname) : '&nbsp;') + '</div></div><span class="pick"><i class="ri-check-line"></i></span></div>').join('');
                $('ownOverlay').classList.add('open');
                return;
            }
        } catch (e) {}
    }
    showConfirm('Leave this group?', IS_OWNER ? 'You are the only person here, so the group will be deleted.' : 'You will stop receiving its messages. You can only be added back by the person who created it, and only if your message settings allow it.', async () => {
        const d = await act('group_leave', { group: GROUP_ID });
        if (d && d.success) window.location.href = 'messages.php'; else toast((d && d.error) || 'Could not leave the group');
    });
}
function deleteGroup() {
    if (!IS_OWNER) return;
    showConfirm('Delete this group?', 'The group, all its messages, photos and videos will be deleted for everyone. This cannot be undone.', async () => {
        const d = await act('group_delete', { group: GROUP_ID });
        if (d && d.success) window.location.href = 'messages.php'; else toast((d && d.error) || 'Could not delete the group');
    });
}
function pickOwner(id, el) { ownSel = id; document.querySelectorAll('#ownList .own-pick').forEach(r => r.classList.toggle('sel', r === el)); $('ownGo').disabled = false; }
function closeOwner() { $('ownOverlay').classList.remove('open'); }
async function confirmOwnerLeave() {
    if (!ownSel) return;
    $('ownGo').disabled = true;
    const d = await act('group_leave', { group: GROUP_ID, new_owner: ownSel });
    if (d && d.success) window.location.href = 'messages.php'; else { toast((d && d.error) || 'Could not leave the group'); $('ownGo').disabled = false; }
}

// ══════════════════════════════════════════════════════════════
//  CHAT (only when a conversation is open)
// ══════════════════════════════════════════════════════════════
const msgWindow = $('msgWindow');
const msgInput  = $('msgInput');
let stickBottom = true;

function nearBottom() { return !msgWindow || msgWindow.scrollHeight - msgWindow.scrollTop - msgWindow.clientHeight < 140; }
function scrollToBottom(smooth) {
    if (!msgWindow) return;
    msgWindow.scrollTo({ top: msgWindow.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
    stickBottom = true;
    newCount = 0;
    $('newPill') && $('newPill').classList.remove('show');
}
function placePill() { const ia = $('inputArea'); if (ia) $('chatArea').style.setProperty('--cmp-h', ia.offsetHeight + 'px'); }
function showNewPill(add) {
    newCount += add; placePill();
    const t = $('newPillText'); if (!t) return;
    t.textContent = newCount <= 1 ? 'New message' : (newCount > 99 ? '99' : newCount) + '+ new messages';
    $('newPill').classList.add('show');
}
const findRow = id => msgWindow ? msgWindow.querySelector('.msg-row[data-ids~="' + id + '"], .msg-row[data-id="' + id + '"]') : null;
async function jumpToMsg(id) {
    if (!msgWindow || !id) return;
    let row = findRow(id), guard = 0;
    while (!row && olderMore && guard++ < 40) { await loadOlder(); row = findRow(id); }   // the message may be further up: load older pages until it shows
    if (!row) { toast('That message is no longer here'); return; }
    stickBottom = false;
    row.scrollIntoView({ block: 'center', behavior: 'smooth' });
    row.classList.remove('flash'); void row.offsetWidth; row.classList.add('flash');
    setTimeout(() => row.classList.remove('flash'), 1800);
}
/* older messages: 30 at a time while scrolling up (the scroll position is kept) */
async function loadOlder() {
    if (!msgWindow || olderLoading || !olderMore) return false;
    olderLoading = true;
    const first = Array.from(msgWindow.querySelectorAll('.msg-row[data-id]')).find(r => /^\d+$/.test(r.dataset.id));
    const before = first ? parseInt(first.dataset.id, 10) : 0;
    const loader = document.createElement('div'); loader.className = 'older-loader'; loader.innerHTML = '<span class="spin"></span> Loading earlier messages…';
    msgWindow.insertBefore(loader, msgWindow.firstChild);
    let ok = false;
    try {
        const res = await fetch('messages.php?ms=older&' + (IS_GROUP ? 'group=' + GROUP_ID : 'user=' + chatUserId) + '&before=' + before, { cache: 'no-store' });
        const d = await res.json();
        await new Promise(r => setTimeout(r, 220));
        loader.remove();
        if (!d.success) throw new Error('older');
        const prevH = msgWindow.scrollHeight, prevTop = msgWindow.scrollTop;
        const intro = $('chatIntro');
        const anchor = intro ? intro.nextElementSibling : msgWindow.firstElementChild;
        const tpl = document.createElement('template'); tpl.innerHTML = d.html || '';
        const nodes = Array.from(tpl.content.children).filter(n => !(n.classList.contains('msg-row') && exists(n.dataset.id)));
        nodes.forEach(n => msgWindow.insertBefore(n, anchor));
        // glue the new page to the old top: no doubled date label, no doubled sender name
        if (nodes.length && anchor) {
            const newRows = nodes.filter(n => n.classList.contains('msg-row') && !n.classList.contains('sys'));
            const newDivs = nodes.filter(n => n.classList.contains('date-divider'));
            let sameDay = false, oldRow = anchor.classList.contains('msg-row') ? anchor : null;
            if (anchor.classList.contains('date-divider') && newDivs.length && newDivs[newDivs.length - 1].textContent.trim() === anchor.textContent.trim()) { oldRow = findFirstRowAfter(anchor); anchor.remove(); sameDay = true; }
            const lastNew = newRows[newRows.length - 1];
            if (oldRow && lastNew && (sameDay || anchor === oldRow) && oldRow.classList.contains('new-group') && String(oldRow.dataset.sender) === String(lastNew.dataset.sender)) {
                oldRow.classList.remove('new-group'); const gs = oldRow.querySelector('.g-sender'); if (gs) gs.remove();
            }
        }
        nodes.forEach(n => n.classList.contains('msg-row') && bindReactions(n));
        olderMore = !!d.more;
        if (!olderMore && intro) intro.style.display = '';
        msgWindow.scrollTop = prevTop + (msgWindow.scrollHeight - prevH);
        ok = true;
    } catch (e) { loader.remove(); }
    olderLoading = false;
    return ok;
}
function findFirstRowAfter(el) { let n = el && el.nextElementSibling; while (n && !n.classList.contains('msg-row')) n = n.nextElementSibling; return n; }
async function fillOlder() {
    let n = 0;
    while (olderMore && msgWindow.scrollHeight <= msgWindow.clientHeight + 60 && n++ < 6) { if (!(await loadOlder())) break; }
    if (n) scrollToBottom(false);
}
/* appends at the end of the chat, but above the typing dots */
function mwAppend(n) { typingEl && typingEl.parentNode === msgWindow ? msgWindow.insertBefore(n, typingEl) : msgWindow.appendChild(n); }
function setTyping(list) {
    if (!msgWindow || !CHAT_ON) return;
    if (!list || !list.length) { if (typingEl) { typingEl.remove(); typingEl = null; } return; }
    const near = nearBottom(), p = list[0];
    if (!typingEl) {
        typingEl = document.createElement('div');
        typingEl.className = 'msg-row recv typing-row new-group';
        msgWindow.appendChild(typingEl);
    }
    typingEl.innerHTML = (IS_GROUP ? '<div class="g-sender">' + esc(list.map(x => x.name).join(', ')) + '</div>' : '')
        + '<div class="msg-wrap"><img class="msg-avatar" src="' + esc(p.pic) + '" alt="" onerror="this.src=\'mee.jpg\'"><div class="bubble b-recv typing-b" aria-label="Typing"><i></i><i></i><i></i></div></div>';
    if (typingEl !== msgWindow.lastElementChild) msgWindow.appendChild(typingEl);
    if (near) scrollToBottom(false);
}
function sendTyping(on) {
    if (!CHAT_ON || !CAN_SEND) return;
    const body = new URLSearchParams({ ms_action: 'typing', kind: IS_GROUP ? 'g' : 'u', target: IS_GROUP ? GROUP_ID : chatUserId, on: on ? 1 : 0 });
    fetch('messages.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body }).catch(() => {});
}
function typingPing() {
    if (!msgInput || !CHAT_ON || !CAN_SEND) return;
    const has = msgInput.value.trim().length > 0, t = Date.now();
    if (has) { if (t - typingSentAt > 2400) { typingSentAt = t; typingOn = true; sendTyping(true); } }
    else if (typingOn) { typingOn = false; typingSentAt = 0; sendTyping(false); }
}

function exists(id) { return !!msgWindow.querySelector('.msg-row[data-ids~="' + id + '"], .msg-row[data-id="' + id + '"]'); }
function rowIds(row) { return row && row.dataset.ids ? row.dataset.ids.split(' ').filter(Boolean) : (row ? [row.dataset.id] : []); }

/* Adds server-rendered rows, skipping ones already on screen and keeping them in id order */
function appendHtml(html, forceScroll) {
    if (!html || !msgWindow) return;
    const wasNear = nearBottom();
    const tpl = document.createElement('template');
    tpl.innerHTML = html;
    const nodes = Array.from(tpl.content.children);
    const keep = [];
    nodes.forEach((n, i) => {
        if (n.classList.contains('msg-row')) { if (!exists(n.dataset.id)) keep.push(n); }
        else if (n.classList.contains('date-divider')) {
            const next = nodes.slice(i + 1).find(x => x.classList.contains('msg-row'));
            const lastDiv = Array.from(msgWindow.querySelectorAll('.date-divider')).pop();
            if (next && !exists(next.dataset.id) && !(lastDiv && lastDiv.textContent.trim() === n.textContent.trim() && !keep.length)) keep.push(n);
        }
    });
    if (!keep.length) return;
    $('mwEmpty') && $('mwEmpty').remove();
    keep.forEach(n => {
        if (n.classList.contains('msg-row')) {
            n.classList.add('fresh');
            const id = parseInt(n.dataset.id, 10);
            const later = Array.from(msgWindow.querySelectorAll('.msg-row[data-id]')).find(r => /^\d+$/.test(r.dataset.id) && parseInt(r.dataset.id, 10) > id);
            later ? msgWindow.insertBefore(n, later.previousElementSibling && later.previousElementSibling.classList.contains('date-divider') ? later.previousElementSibling : later) : mwAppend(n);
            bindReactions(n);
        } else mwAppend(n);
    });
    const cm = $('ciMsgs'); if (cm) cm.textContent = (parseInt(cm.textContent, 10) || 0) + keep.filter(n => n.classList.contains('msg-row') && !n.classList.contains('sys')).reduce((a, n) => a + rowIds(n).length, 0);
    const incoming = keep.filter(n => n.classList.contains('msg-row') && !n.classList.contains('sys') && String(n.dataset.sender) !== String(currentUserId)).reduce((a, n) => a + rowIds(n).length, 0);
    if (forceScroll || wasNear) scrollToBottom(!forceScroll); else if (incoming) showNewPill(incoming);
}

if (CHAT_ON && msgWindow) {
    msgWindow.addEventListener('scroll', () => { stickBottom = nearBottom(); if (stickBottom) { newCount = 0; $('newPill').classList.remove('show'); } if (msgWindow.scrollTop < 140) loadOlder(); }, { passive: true });
    msgWindow.querySelectorAll('img').forEach(img => { if (!img.complete) img.addEventListener('load', () => { if (stickBottom) scrollToBottom(false); }); });
    scrollToBottom(false);
    if (FIND_ID) setTimeout(() => jumpToMsg(FIND_ID), 120); else setTimeout(fillOlder, 200);
}

// ── SELECT MODE ───────────────────────────────────────────────
function toggleMsgSelectMode() { msgSelectMode ? exitMsgSelectMode() : enterMsgSelectMode(); }
function enterMsgSelectMode() {
    msgSelectMode = true; selectedMsgIds.clear();
    $('msgSelectBar').classList.add('active');
    msgWindow.querySelectorAll('.msg-row').forEach(r => r.classList.add('msg-selectable'));
    $('msgSelectToggleBtn') && $('msgSelectToggleBtn').classList.add('active');
    updateMsgSelectUI();
}
function exitMsgSelectMode() {
    msgSelectMode = false; selectedMsgIds.clear();
    $('msgSelectBar').classList.remove('active');
    msgWindow.querySelectorAll('.msg-row').forEach(r => r.classList.remove('msg-selectable', 'msg-selected'));
    $('msgSelectToggleBtn') && $('msgSelectToggleBtn').classList.remove('active');
}
function updateMsgSelectUI() {
    const n = selectedMsgIds.size, del = $('msgDeleteSelectedBtn');
    $('msgSelectCount').textContent = n + ' selected';
    if (del) { del.style.opacity = n > 0 ? '1' : '.5'; del.style.pointerEvents = n > 0 ? 'auto' : 'none'; }
}
function selectAllMessages() {
    const rows = msgWindow.querySelectorAll('.msg-row[data-id]');
    const all = selectedMsgIds.size === rows.length;
    selectedMsgIds.clear();
    rows.forEach(r => { if (all) r.classList.remove('msg-selected'); else { selectedMsgIds.add(r.dataset.id); r.classList.add('msg-selected'); } });
    updateMsgSelectUI();
}
function deleteSelectedMessages() {
    if (!selectedMsgIds.size) return;
    const ids = Array.from(selectedMsgIds);
    showConfirm('Delete ' + ids.length + ' message' + (ids.length > 1 ? 's' : '') + '?', 'These messages will be permanently removed.', () => {
        const rws = ids.map(id => msgWindow.querySelector('.msg-row[data-id="' + id + '"]')).filter(Boolean);
        doDelete([].concat(...rws.map(rowIds)), '1', rws, exitMsgSelectMode);
    });
}
if (msgWindow) {
    // capture phase so tapping a photo or shared post in select mode selects instead of opening it
    msgWindow.addEventListener('click', e => {
        if (!msgSelectMode) return;
        const row = e.target.closest('.msg-row');
        if (!row || e.target.closest('.msg-chevron') || e.target.closest('.msg-dropdown')) return;
        e.preventDefault(); e.stopPropagation();
        const id = row.dataset.id;
        if (selectedMsgIds.has(id)) { selectedMsgIds.delete(id); row.classList.remove('msg-selected'); } else { selectedMsgIds.add(id); row.classList.add('msg-selected'); }
        updateMsgSelectUI();
    }, true);
    // touch devices: tap a row to reveal its menu arrow
    if (window.matchMedia('(hover: none)').matches) {
        msgWindow.addEventListener('click', e => {
            if (msgSelectMode || e.target.closest('.msg-chevron') || e.target.closest('.msg-dropdown')) return;
            const row = e.target.closest('.msg-row'); if (!row) return;
            msgWindow.querySelectorAll('.msg-chevron.touch-visible').forEach(c => { if (!row.contains(c)) c.classList.remove('touch-visible'); });
            row.querySelectorAll('.msg-chevron').forEach(c => c.classList.toggle('touch-visible'));
        });
    }
    // long press on a bubble opens its menu
    let pressTimer = null, moved = false;
    msgWindow.addEventListener('touchstart', e => {
        const b = e.target.closest('.bubble'); if (!b) return;
        moved = false; clearTimeout(pressTimer);
        pressTimer = setTimeout(() => { if (moved) return; if (navigator.vibrate) navigator.vibrate(15); msgLongPressOpen({ preventDefault() {} }, b); }, 480);
    }, { passive: true });
    msgWindow.addEventListener('touchmove', () => { moved = true; clearTimeout(pressTimer); }, { passive: true });
    ['touchend', 'touchcancel'].forEach(t => msgWindow.addEventListener(t, () => clearTimeout(pressTimer), { passive: true }));

    // swipe a message to the right to reply to it (finger-tracking, rubber-band, spring back, haptic tick)
    (function () {
        const TH = 60, MAXPULL = 94;
        let row = null, wrap = null, ico = null, startX = 0, x0 = 0, y0 = 0, pos = 0, lastX = 0, lastT = 0, vx = 0;
        let locked = false, dead = false, raf = 0, ticked = false;
        const paint = () => {
            raf = 0;
            if (!wrap) return;
            wrap.style.transform = 'translate3d(' + pos + 'px,0,0)';
            if (ico) {
                const pr = Math.min(pos / TH, 1);
                ico.style.opacity = pr.toFixed(2);
                ico.style.transform = 'translate3d(' + Math.min(pos * 0.35, 22) + 'px,0,0) scale(' + (0.55 + 0.45 * pr).toFixed(2) + ')';
                ico.classList.toggle('on', pos >= TH);
            }
        };
        const release = (doReply) => {
            const w = wrap, i = ico, r = row;
            if (raf) { cancelAnimationFrame(raf); raf = 0; }
            if (w) {
                w.classList.add('springing');
                w.style.transform = '';                       // CSS transition springs it back
                setTimeout(() => { w.classList.remove('springing'); w.style.willChange = ''; }, 420);
            }
            if (i) { i.style.opacity = '0'; i.style.transform = 'scale(.55)'; setTimeout(() => i.remove(), 220); }
            row = wrap = ico = null; pos = 0; locked = dead = ticked = false;
            if (doReply && r) ddAction('reply', r.dataset.id);
        };
        msgWindow.addEventListener('touchstart', e => {
            if (row) release(false);
            if (msgSelectMode || e.touches.length !== 1 || e.target.closest('.msg-dropdown, video[controls]')) return;
            const r = e.target.closest('.msg-row[data-id]');
            if (!r || !/^\d+$/.test(r.dataset.id) || r.classList.contains('pending') || r.classList.contains('sys')) return;
            wrap = r.querySelector('.bubble'); if (!wrap) return;
            row = r;
            x0 = lastX = e.touches[0].clientX; y0 = e.touches[0].clientY; lastT = e.timeStamp; vx = 0; pos = 0; locked = dead = ticked = false;
        }, { passive: true });
        msgWindow.addEventListener('touchmove', e => {
            if (!row || dead) return;
            const t = e.touches[0], mx = t.clientX - x0, my = t.clientY - y0;
            if (!locked) {
                if (Math.abs(my) > 8 && Math.abs(my) > Math.abs(mx)) { dead = true; release(false); return; }   // it is a scroll
                if (mx > 9 && mx > Math.abs(my) * 1.2) {
                    locked = true; startX = t.clientX;          // start from 0 so there is no jump when the gesture locks in
                    wrap.classList.remove('springing'); wrap.style.willChange = 'transform';
                    ico = document.createElement('div'); ico.className = 'swipe-ico'; ico.innerHTML = '<i class="ri-reply-line"></i>'; row.appendChild(ico);
                } else return;
            }
            if (e.cancelable) e.preventDefault();
            const dt = Math.max(1, e.timeStamp - lastT);
            vx = 0.8 * vx + 0.2 * ((t.clientX - lastX) / dt); lastX = t.clientX; lastT = e.timeStamp;
            const d = Math.max(0, t.clientX - startX);
            pos = d <= TH ? d : Math.min(MAXPULL, TH + (d - TH) * 0.32);            // rubber band after the threshold
            if (pos >= TH && !ticked) { ticked = true; if (navigator.vibrate) navigator.vibrate(8); }
            else if (pos < TH - 6) ticked = false;
            if (!raf) raf = requestAnimationFrame(paint);
        }, { passive: false });
        msgWindow.addEventListener('touchend', () => {
            if (!row) return;
            release(locked && (pos >= TH || (pos > 34 && vx > 0.55)));                  // far enough, or a quick flick
        }, { passive: true });
        msgWindow.addEventListener('touchcancel', () => { if (row) release(false); }, { passive: true });
    })();
}

// ── DROPDOWN ──────────────────────────────────────────────────
function closeAllDropdowns(except) {
    document.querySelectorAll('.msg-dropdown.open').forEach(el => {
        if (el === except) return;
        el.classList.remove('open');
        const r = el.closest('.msg-row');
        r && r.classList.remove('menu-open');
        r && r.querySelectorAll('.msg-chevron').forEach(c => c.classList.remove('active'));
    });
}
function toggleDropdown(e, chevronEl) {
    e.preventDefault(); e.stopPropagation();
    if (msgSelectMode) return;
    const row = chevronEl.closest('.msg-row'), id = row.dataset.id, dd = $('dd-' + id);
    closeAllDropdowns(dd);
    const isOpen = dd.classList.toggle('open');
    row.classList.toggle('menu-open', isOpen);
    row.querySelectorAll('.msg-chevron').forEach(c => c.classList.toggle('active', isOpen));
    openDropdownId = isOpen ? id : null;
    if (!isOpen) return;
    const editBtn = $('edit-btn-' + id);
    if (editBtn) {
        const ok = (nowSrv() - parseInt(row.dataset.ts || '0', 10)) <= EDIT_WINDOW;
        row.dataset.canEdit = ok ? '1' : '0';
        editBtn.classList.toggle('disabled', !ok);
        editBtn.innerHTML = '<i class="ri-edit-line"></i> Edit' + (ok ? '' : ' <span class="edited-tag">(expired)</span>');
    }
    dd.classList.remove('up'); dd.style.left = dd.style.right = '';
    requestAnimationFrame(() => {
        const r = dd.getBoundingClientRect(), wr = msgWindow.getBoundingClientRect();
        if (r.bottom > wr.bottom - 4 && (r.top - wr.top) > r.height) dd.classList.add('up');
        if (r.right > window.innerWidth - 8) { dd.style.right = '0px'; dd.style.left = 'auto'; }
        if (r.left < 8) { dd.style.left = '0px'; dd.style.right = 'auto'; }
    });
}
function msgLongPressOpen(e, bubbleEl) {
    e.preventDefault();
    if (msgSelectMode) return false;
    const row = bubbleEl.closest('.msg-row'), chev = row && row.querySelector('.msg-chevron');
    if (chev) toggleDropdown(e, chev);
    return false;
}
document.addEventListener('click', e => {
    if (!e.target.closest('.msg-dropdown') && !e.target.closest('.msg-chevron')) { closeAllDropdowns(); openDropdownId = null; }
    if (!e.target.closest('#bgPicker') && !e.target.closest('.ac-x')) $('bgPicker') && $('bgPicker').classList.remove('open');
    if (!e.target.closest('#moreMenu') && !e.target.closest('.ac-x')) $('moreMenu') && $('moreMenu').classList.remove('open');
    if (!e.target.closest('#emojiPicker') && !e.target.closest('#emojiBtn')) $('emojiPicker') && $('emojiPicker').classList.remove('open');
    if (!e.target.closest('#stickerPicker') && !e.target.closest('#stickerBtn')) $('stickerPicker') && $('stickerPicker').classList.remove('open');
});
function handleBubbleClick() { /* reserved */ }
function toggleMoreMenu(e) { e.stopPropagation(); $('bgPicker').classList.remove('open'); $('moreMenu').classList.toggle('open'); }
function closeMore() { $('moreMenu').classList.remove('open'); }

// ── REACTIONS (kept on this device) ───────────────────────────
function reactToMessage(msgId, emoji) {
    closeAllDropdowns();
    const key = String(msgId), prev = myReactions[key];
    if (!reactionsStore[key]) reactionsStore[key] = {};
    if (prev === emoji) {
        reactionsStore[key][emoji] = Math.max(0, (reactionsStore[key][emoji] || 1) - 1);
        if (!reactionsStore[key][emoji]) delete reactionsStore[key][emoji];
        delete myReactions[key];
    } else {
        if (prev && reactionsStore[key][prev]) { reactionsStore[key][prev] = Math.max(0, reactionsStore[key][prev] - 1); if (!reactionsStore[key][prev]) delete reactionsStore[key][prev]; }
        reactionsStore[key][emoji] = (reactionsStore[key][emoji] || 0) + 1;
        myReactions[key] = emoji;
    }
    lsSet('peura_reactions_' + chatKey, JSON.stringify(reactionsStore));
    lsSet('peura_my_reactions_' + chatKey, JSON.stringify(myReactions));
    renderReactions(msgId);
    act('react', { id: msgId, emoji: emoji });
}
function renderReactions(msgId) {
    const c = $('reactions-' + msgId); if (!c) return;
    const key = String(msgId), data = reactionsStore[key] || {}, mine = myReactions[key];
    c.innerHTML = '';
    Object.entries(data).forEach(([emoji, count]) => {
        if (count <= 0) return;
        const p = document.createElement('div');
        p.className = 'msg-reaction-pill' + (mine === emoji ? ' mine' : '');
        p.innerHTML = '<span>' + emoji + '</span><span class="r-count">' + count + '</span>';
        p.onclick = () => reactToMessage(msgId, emoji);
        c.appendChild(p);
    });
}
function bindReactions(row) { renderReactions(row.dataset.id); }
if (msgWindow) msgWindow.querySelectorAll('.msg-row[data-id]').forEach(bindReactions);

// ── MESSAGE ACTIONS ───────────────────────────────────────────
function ddAction(action, id) {
    closeAllDropdowns();
    const row = msgWindow.querySelector('.msg-row[data-id="' + id + '"]');
    const text = row ? row.dataset.text : '', isSent = row && String(row.dataset.sender) === String(currentUserId);
    switch (action) {
        case 'copy': navigator.clipboard.writeText(text).then(() => toast('Copied')); break;
        case 'reply':
            $('replyWho').textContent = isSent ? 'You' : (row.dataset.username || '');
            $('replyText').textContent = /^\[meme:\d+\]$/.test(text.trim()) ? 'Shared a post' : text.substring(0, 80) + (text.length > 80 ? '…' : '');
            $('replyToId').value = id;
            $('replyBar').classList.add('visible');
            msgInput && msgInput.focus();
            break;
        case 'forward': openForward(text); break;
        case 'pin':
            lsSet('ms_pin_' + chatKey, JSON.stringify({ id: id, text: /^\[meme:\d+\]$/.test(text.trim()) ? 'Shared a post' : text.substring(0, 80) }));
            showPin(); toast('Message pinned');
            break;
        case 'info':
            $('infoSent').textContent = row.dataset.time;
            $('infoSeen').textContent = isSent ? (row.dataset.seen === '1' ? 'Seen' : 'Not seen yet') : 'Received';
            $('infoId').textContent = '#' + id;
            $('infoOverlay').classList.add('open');
            break;
        case 'select': enterMsgSelectMode(); selectedMsgIds.add(String(id)); row.classList.add('msg-selected'); updateMsgSelectUI(); break;
        case 'edit':
            if (row.dataset.canEdit !== '1' || (nowSrv() - parseInt(row.dataset.ts, 10)) > EDIT_WINDOW) { toast('The 2 minute edit window has passed'); return; }
            openEdit(id, text); break;
        case 'delete': showConfirm('Delete this message?', 'It will be removed from this conversation.', () => doDelete(rowIds(row), '1', [row])); break;
        case 'unsend':
            if (!isSent) return;
            showConfirm('Unsend this message?', 'It will be removed for everyone in this conversation.', () => doDelete(rowIds(row), '2', [row])); break;
    }
}

function showPin() {
    let p = null; try { p = JSON.parse(lsGet('ms_pin_' + chatKey) || 'null'); } catch (e) {}
    if (!p || !exists(p.id)) { $('pinnedBar').classList.remove('visible'); pinnedId = null; return; }
    pinnedId = p.id; $('pinnedText').textContent = p.text; $('pinnedBar').classList.add('visible');
}
function unpin() { lsDel('ms_pin_' + chatKey); pinnedId = null; $('pinnedBar').classList.remove('visible'); }
if (CHAT_ON) showPin();

function openEdit(id, currentText) {
    editingMsgId = id;
    const ta = $('editTextarea');
    ta.value = currentText; ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, 120) + 'px';
    $('editInlineBar').classList.add('visible');
    setTimeout(() => ta.focus(), 50);
}
function cancelEdit() { editingMsgId = null; $('editInlineBar').classList.remove('visible'); $('editTextarea').value = ''; }
async function saveEdit() {
    if (!editingMsgId) return;
    const newText = $('editTextarea').value.trim();
    if (!newText) { toast('A message cannot be empty'); return; }
    const id = editingMsgId; cancelEdit();
    const d = IS_GROUP ? await act('gedit', { group: GROUP_ID, id: id, new_message: newText }) : await act('edit', { id: id, new_message: newText });
    if (d && d.success) {
        const row = msgWindow.querySelector('.msg-row[data-id="' + id + '"]'), b = row && row.querySelector('.bubble-text');
        if (b) b.innerHTML = esc(newText).replace(/\n/g, '<br>') + ' <span class="edited-tag">edited</span>';
        if (row) row.dataset.text = newText;
        toast('Message updated');
    } else toast((d && d.error) || 'Edit failed');
}
if ($('editTextarea')) {
    $('editTextarea').addEventListener('input', function () { this.style.height = 'auto'; this.style.height = Math.min(this.scrollHeight, 120) + 'px'; });
    $('editTextarea').addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); saveEdit(); }
        if (e.key === 'Escape') { e.stopPropagation(); cancelEdit(); }
    });
}

async function doDelete(ids, type, rows, cb) {
    if (!ids || !ids.length) return;
    const d = IS_GROUP ? await act('gdelete', { group: GROUP_ID, ids: ids.join(',') }) : await act('delete', { ids: ids.join(','), type: type });
    if (!d || !d.success) { toast('Delete failed. Try again.'); return; }
    rows.forEach(row => {
        if (!row) return;
        if (type === '2') {
            const b = row.querySelector('.bubble');
            if (b) {
                b.classList.add('fading-deleted');
                setTimeout(() => {
                    b.classList.add('deleted'); b.classList.remove('fading-deleted', 'shared-meme-bubble', 'emoji-only', 'bubble-collage');
                    b.innerHTML = '<div class="bubble-text"><i class="ri-forbid-line"></i> Message deleted</div>';
                    row.dataset.text = '[deleted]';
                }, 400);
            }
        } else { row.classList.add('removing'); setTimeout(() => row.remove(), 380); }
    });
    toast(type === '2' ? 'Message unsent' : 'Message deleted');
    if (cb) cb();
}
function clearEntireChat() {
    showConfirm('Clear this conversation?', 'Every message with this person will be permanently deleted.', async () => {
        const d = await act('clear_chat', { with_user: chatUserId });
        if (d && d.success) { msgWindow.querySelectorAll('.msg-row,.date-divider').forEach(r => r.remove()); toast('Conversation cleared'); } else toast('Could not clear the chat');
    });
}
function deleteThisConversation() {
    showConfirm('Delete this conversation?', 'It will be removed permanently.', async () => {
        const d = await act('delete_conv', { with_user: chatUserId });
        if (d && d.success) window.location.href = 'messages.php'; else toast('Could not delete the conversation');
    });
}
function blockUser() {
    showConfirm('Block this person?', 'They will never be able to message you again, and you will not be able to message them. They are not told. You can unblock them any time.', async () => {
        const d = await act('block', { target_id: chatUserId });
        if (d && d.success) window.location.reload(); else toast((d && d.error) || 'Could not block');
    });
}
async function unblockUser() {
    const d = await act('unblock', { target_id: chatUserId });
    if (d && d.success) window.location.reload(); else toast('Could not unblock');
}
async function requestAction(kind) {
    const d = await act(kind, { from_user: chatUserId });
    if (d && d.success) window.location.href = kind === 'accept_request' ? 'messages.php?user=' + chatUserId : 'messages.php';
}

// ── BACKGROUND PICKER ─────────────────────────────────────────
let selectedBgFile = null, selectedBgURL = null;
function toggleBgPicker() { $('moreMenu').classList.remove('open'); $('bgPicker').classList.toggle('open'); }
function paintBg(src, type) {
    const layer = $('chatBgLayer'); if (!layer) return;
    layer.innerHTML = ''; layer.style.backgroundImage = '';
    if (!src) { layer.classList.remove('has-bg'); return; }
    layer.classList.add('has-bg');
    if (type && type.indexOf('video/') === 0) {
        const v = document.createElement('video');
        v.src = src; v.autoplay = true; v.loop = true; v.muted = true; v.playsInline = true;
        layer.appendChild(v);
    } else layer.style.backgroundImage = 'url(' + src + ')';
}
if ($('bgFileInput')) $('bgFileInput').addEventListener('change', function () {
    const f = this.files[0]; if (!f) return;
    selectedBgFile = f; selectedBgURL = URL.createObjectURL(f);
    const box = $('bgPreviewBox'); box.innerHTML = '';
    if (f.type.startsWith('video/')) { const v = document.createElement('video'); v.src = selectedBgURL; v.autoplay = v.loop = v.muted = v.playsInline = true; box.appendChild(v); }
    else { const i = document.createElement('img'); i.src = selectedBgURL; box.appendChild(i); }
    $('bgUploadArea').style.display = 'none'; $('bgPreviewWrap').style.display = 'flex';
});
async function applyGroupBg() {
    if (!selectedBgFile) return;
    const fd = new FormData(); fd.append('ms_action', 'group_bg'); fd.append('group', GROUP_ID); fd.append('csrf_token', CSRF_TOKEN); fd.append('attachment', selectedBgFile, selectedBgFile.name);
    toast('Uploading background…');
    const r = await postForm(fd);
    if (r.data && r.data.success) { GROUP_BG = r.data.bg; paintBg(r.data.bg, r.data.type); toast('Group background updated for everyone'); $('bgPicker').classList.remove('open'); selectedBgFile = null; $('bgPreviewBox').innerHTML = ''; $('bgPreviewWrap').style.display = 'none'; $('bgUploadArea').style.display = 'block'; $('bgFileInput').value = ''; }
    else toast((r.data && (r.data.error || r.data.message)) || 'Could not upload the background');
}
async function removeGroupBg() {
    const d = await act('group_bg', { group: GROUP_ID, remove: 1 });
    if (d && d.success) { GROUP_BG = ''; paintBg(null); toast('Group background removed'); selectedBgFile = selectedBgURL = null; $('bgPreviewBox').innerHTML = ''; $('bgPreviewWrap').style.display = 'none'; $('bgUploadArea').style.display = 'block'; $('bgFileInput').value = ''; }
    else toast((d && d.error) || 'Could not remove the background');
}
function applyBg() {
    if (IS_GROUP) { applyGroupBg(); return; }
    if (!selectedBgFile) return;
    paintBg(selectedBgURL, selectedBgFile.type);
    const rd = new FileReader();
    rd.onload = e => { lsSet('peura_chat_bg_file_' + chatKey, e.target.result); lsSet('peura_chat_bg_type_' + chatKey, selectedBgFile.type); };
    rd.readAsDataURL(selectedBgFile);
    toast('Background applied'); $('bgPicker').classList.remove('open');
}
function removeBg() {
    if (IS_GROUP) { removeGroupBg(); return; }
    paintBg(null);
    lsDel('peura_chat_bg_file_' + chatKey); lsDel('peura_chat_bg_type_' + chatKey);
    selectedBgFile = selectedBgURL = null;
    $('bgPreviewBox').innerHTML = ''; $('bgPreviewWrap').style.display = 'none'; $('bgUploadArea').style.display = 'block'; $('bgFileInput').value = '';
    toast('Background removed');
}
if (IS_GROUP) { if (GROUP_BG) paintBg(GROUP_BG, <?= json_encode($g_bg_type) ?>); }   // a group's background is set by its owner and shown to everyone
else if (CHAT_ON) { const sd = lsGet('peura_chat_bg_file_' + chatKey), st = lsGet('peura_chat_bg_type_' + chatKey); if (sd && st) paintBg(sd, st); }

// ── EMOJI PICKER ──────────────────────────────────────────────
const EMOJI_DATA = {
  "Smileys":    "😀😃😄😁😆😅🤣😂🙂🙃🫠😉😊😇🥰😍🤩😘😗☺️😚😙🥲😋😛😜🤪😝🤑🤗🤭🫢🫣🤫🤔🫡🤐🤨😐😑😶🫥😏😒🙄😬🤥🫨😌😔😪🤤😴😷🤒🤕🤢🤮🤧🥵🥶🥴😵🤯🤠🥳🥸😎🤓🧐😕🫤😟🙁☹️😮😯😲😳🥺🥹😦😧😨😰😥😢😭😱😖😣😞😓😩😫🥱😤😡😠🤬😈👿💀☠️💩🤡👹👺👻👽👾🤖😺😸😹😻😼😽🙀😿😾",
  "People":     "👋🤚🖐️✋🖖🫱🫲🫳🫴👌🤌🤏✌️🤞🫰🤟🤘👈👉👆🖕👇☝️👍👎✊👊🤛🤜👏🙌🫶👐🤲🤝🙏✍️💅🤳💪🦾🦵🦿🦶👣👂🦻👃🧠🫀🫁🦷🦴👀👁️👅👄🫦👶👧🧒👦👩🧑👨👵🧓👴👑",
  "Animals":    "🐶🐱🐭🐹🐰🦊🐻🐼🐨🐯🦁🐮🐷🐽🐸🐵🙈🙉🙊🐒🐔🐧🐦🐤🐣🐥🦆🦅🦉🦇🐺🐗🐴🦄🐝🐛🦋🐌🐞🐜🕷️🕸️🦂🐢🐍🦎🦖🦕🐙🦑🦐🦞🦀🐡🐠🐟🐬🐳🐋🦈🐊🐅🐆🦓🦍🐘🦛🦏🐪🐫🦒🦘🐃🐂🐄🐎🐖🐏🐑🐐🦌🐕🐩🐈🐓🦃🦚🦜🦢🕊️🐇🦨🦫🦦🦥🐁🐀🐿️🦔",
  "Food":       "🍏🍎🍐🍊🍋🍌🍉🍇🍓🍈🍒🍑🥭🍍🥥🥝🍅🍆🥑🥦🥬🥒🌶️🌽🥕🧄🧅🥔🍠🥐🥯🍞🥖🥨🧀🥚🍳🥞🧇🥓🥩🍗🍖🌭🍔🍟🍕🥙🌮🌯🥗🍝🍜🍲🍛🍣🍱🥟🍤🍙🍚🍘🍥🍢🍡🍧🍨🍦🥧🧁🍰🎂🍮🍭🍬🍫🍿🍩🍪🌰🥜🍯🥛🍼☕🍵🧃🥤🍶🍺🍻🥂🍷🥃🍸🍹🍾",
  "Activities": "⚽🏀🏈⚾🎾🏐🏉🎱🏓🏸🏒🏑🏏⛳🎣🥊🥋🎽🛹⛸️🥌🎿⛷️🏂🏋️‍♀️🏋️‍♂️🤸‍♀️🤸‍♂️🤺🏇🧘‍♀️🧘‍♂️🏄‍♀️🏄‍♂️🏊‍♀️🏊‍♂️🚣‍♀️🚣‍♂️🚵‍♀️🚵‍♂️🚴‍♀️🚴‍♂️🏆🥇🥈🥉🏅🎖️🎫🎟️🎪🎭🎨🎬🎤🎧🎼🎹🥁🎷🎺🎸🎻🎲♟️🎯🎳🎮🎰🧩",
  "Travel":     "🚗🚕🚙🚌🚎🏎️🚓🚑🚒🚐🚚🚛🚜🏍️🚲🛴🚨🚥🚦⛵🚤🛳️⛴️🚢✈️🛩️🚁🚀🛰️⌛⏳⌚⏰🕰️🌍🌎🌏🗺️🧭🏔️⛰️🌋🗻🏕️🏖️🏜️🏝️🏛️🏗️🏠🏡🏢💒🏰🗽⛪🕌🗼🌁🌃🏙️🌄🌅🌆🌇🌉",
  "Objects":    "👓🕶️👔👕👖🧣🧤🧥🧦👗👘👠👡👢👑👒🎩🎓🧢💄💍💎🔔📱💻⌨️🖥️🖨️📷📸📹🎥🔍💡🔦📔📕📖📗📘📙📚📝💼📁📅📆📌📎✂️🔒🔑🔨⚙️🔗🧰🧲🔬🔭📡💉💊🛒",
  "Symbols":    "❤️🧡💛💚💙💜🖤🤍🤎💔❣️💕💞💓💗💖💘💝💟☮️✝️☪️🕉️☸️✡️☯️⛎♈♉♊♋♌♍♎♏♐♑♒♓🆔⚛️❌⭕🛑⛔🚫💯💢‼️⁉️🔥⭐🌟✨⚡☀️⛅☁️🌧️⛈️❄️☃️🌊",
  "Flags":      "🏳️🏴🚩🏁🏳️‍🌈🇺🇸🇬🇧🇨🇦🇦🇺🇩🇪🇫🇷🇮🇹🇪🇸🇯🇵🇰🇷🇨🇳🇮🇳🇧🇷🇲🇽🇳🇬🇿🇦🇰🇪🇬🇭"
};

const EMOJI_TAB_ICONS = {
    "Smileys":"😀","People":"🖐️","Animals":"🐶","Food":"🍏",
    "Activities":"⚽","Travel":"✈️","Objects":"💡","Symbols":"❤️","Flags":"🏳️"
};

const emojiTabsEl   = document.getElementById('emojiTabs');
const emojiScrollEl = document.getElementById('emojiScroll');
const emojiSearchEl = document.getElementById('emojiSearchInput');
let activeEmojiCat  = 'Smileys';

function splitEmojis(str) {
    return Array.from(
        str.match(/(\p{Emoji_Presentation}|\p{Extended_Pictographic})(\u200D(\p{Emoji_Presentation}|\p{Extended_Pictographic}))*|\uFE0F?/gu) || []
    ).filter(e => e && e.trim().length);
}

function buildEmojiTabs() {
    emojiTabsEl.innerHTML = '';
    Object.keys(EMOJI_DATA).forEach(cat => {
        const tab       = document.createElement('div');
        tab.className   = 'emoji-tab' + (cat === activeEmojiCat ? ' active' : '');
        tab.textContent = EMOJI_TAB_ICONS[cat] || '🔸';
        tab.title       = cat;
        tab.onclick     = () => { activeEmojiCat = cat; buildEmojiTabs(); renderEmojiGrid(); emojiSearchEl.value = ''; };
        emojiTabsEl.appendChild(tab);
    });
}

function renderEmojiGrid(filter) {
    emojiScrollEl.innerHTML = '';
    if (filter && filter.trim().length) {
        const f = filter.trim().toLowerCase();
        let found = [];
        Object.entries(EMOJI_DATA).forEach(([cat, str]) => {
            if (cat.toLowerCase().includes(f)) found = found.concat(splitEmojis(str));
        });
        if (!found.length) {
            Object.values(EMOJI_DATA).forEach(str => { found = found.concat(splitEmojis(str)); });
            found = found.slice(0, 200);
        }
        if (!found.length) {
            const empty       = document.createElement('div');
            empty.className   = 'emoji-empty';
            empty.textContent = 'No emoji found';
            emojiScrollEl.appendChild(empty); return;
        }
        const label       = document.createElement('div'); label.className = 'emoji-cat-label'; label.textContent = 'Results';
        const grid        = document.createElement('div'); grid.className  = 'emoji-grid';
        found.forEach(e => grid.appendChild(makeEmojiSpan(e)));
        emojiScrollEl.appendChild(label); emojiScrollEl.appendChild(grid);
        return;
    }
    const label       = document.createElement('div'); label.className = 'emoji-cat-label'; label.textContent = activeEmojiCat;
    const grid        = document.createElement('div'); grid.className  = 'emoji-grid';
    splitEmojis(EMOJI_DATA[activeEmojiCat]).forEach(e => grid.appendChild(makeEmojiSpan(e)));
    emojiScrollEl.appendChild(label); emojiScrollEl.appendChild(grid);
}

function makeEmojiSpan(e) {
    const span      = document.createElement('span');
    span.className  = 'ep-emoji';
    span.textContent = e;
    span.onclick    = () => insertEmoji(e);
    return span;
}

emojiSearchEl?.addEventListener('input', function () { renderEmojiGrid(this.value); });

if (emojiTabsEl && emojiScrollEl) { buildEmojiTabs(); renderEmojiGrid(); }
function toggleEmoji()    { $('emojiPicker').classList.toggle('open'); $('stickerPicker').classList.remove('open'); $('emojiBtn').classList.toggle('on', $('emojiPicker').classList.contains('open')); }
function toggleStickers() { $('stickerPicker').classList.toggle('open'); $('emojiPicker').classList.remove('open'); }
function insertEmoji(e) {
    const t = msgInput; if (!t) return;
    const s = t.selectionStart;
    t.value = t.value.substring(0, s) + e + t.value.substring(t.selectionEnd);
    t.selectionStart = t.selectionEnd = s + e.length; t.focus(); onComposerInput();
}

// ── COMPOSER ──────────────────────────────────────────────────
const sendBtn = $('sendBtn'), chatForm = $('chatForm'), fileInput = $('fileInput'), camInput = $('camInput'), cmpBox = $('cmpBox');
let attachFiles = [], attachUrls = [], sending = 0;
const MAX_FILES = 10;
const coarse = window.matchMedia('(pointer: coarse)').matches;

function onComposerInput() {
    if (!msgInput) return;
    msgInput.style.height = 'auto';
    msgInput.style.height = Math.min(msgInput.scrollHeight, 120) + 'px';
    const len = msgInput.value.length, has = msgInput.value.trim().length > 0;
    cmpBox.classList.toggle('has-text', has);
    cmpBox.classList.toggle('has-attach', attachFiles.length > 0);
    cmpBox.classList.toggle('tall', msgInput.scrollHeight > 44);
    const c = $('cmpCount');
    c.textContent = (MAX_TEXT - len) + '';
    c.classList.toggle('show', len > MAX_TEXT - 300);
    c.classList.toggle('warn', len > MAX_TEXT - 50);
    lsSet('ms_draft_' + chatKey, msgInput.value);
    typingPing();
}
function pickedFiles(list) { return Array.from(list || []).filter(x => /^(image|video)\//.test(x.type)); }
if (msgInput && chatForm) {
    const draft = lsGet('ms_draft_' + chatKey); if (draft) msgInput.value = draft;
    onComposerInput();
    msgInput.addEventListener('input', onComposerInput);
    msgInput.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && !coarse) { e.preventDefault(); sendNow(); }
        if (e.key === 'Escape' && $('replyBar').classList.contains('visible')) clearReply();
    });
    msgInput.addEventListener('paste', e => {
        const fs = pickedFiles(e.clipboardData && e.clipboardData.files);
        if (fs.length) { e.preventDefault(); addAttach(fs); }
    });
    chatForm.addEventListener('submit', e => { e.preventDefault(); sendNow(); });
    [fileInput, camInput].forEach(inp => inp.addEventListener('change', () => { addAttach(pickedFiles(inp.files)); inp.value = ''; }));

    const area = $('chatArea');
    let dragDepth = 0;
    area.addEventListener('dragenter', e => { if (e.dataTransfer && Array.from(e.dataTransfer.types || []).includes('Files')) { dragDepth++; area.classList.add('dragging'); } });
    area.addEventListener('dragover', e => { if (area.classList.contains('dragging')) e.preventDefault(); });
    area.addEventListener('dragleave', () => { dragDepth = Math.max(0, dragDepth - 1); if (!dragDepth) area.classList.remove('dragging'); });
    area.addEventListener('drop', e => {
        if (!area.classList.contains('dragging')) return;
        e.preventDefault(); dragDepth = 0; area.classList.remove('dragging');
        const fs = pickedFiles(e.dataTransfer.files);
        if (fs.length) addAttach(fs); else toast('Only photos and videos can be sent');
    });
}

function fmtSize(b) { return b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; }
function addAttach(files) {
    let skipped = 0; const before = attachFiles.length;
    files.forEach(f => {
        if (attachFiles.length >= MAX_FILES) { skipped++; return; }
        if (f.size > MAX_UPLOAD) { toast('"' + f.name + '" is too large (25 MB max)'); return; }
        attachFiles.push(f);
    });
    if (skipped) toast('You can send up to ' + MAX_FILES + ' at once');
    renderAttach();
    if (msgInput) onComposerInput();
    if (attachFiles.length > before && window.mcOpen) { window.mcOpen(before); return; }   /* full-screen preview + caption */
    if (msgInput) msgInput.focus();
}
function renderAttach() {
    attachUrls.forEach(u => URL.revokeObjectURL(u)); attachUrls = [];
    const strip = $('attachStrip'); strip.innerHTML = '';
    attachFiles.forEach((f, i) => {
        const t = document.createElement('div'); t.className = 'at-t';
        const url = URL.createObjectURL(f); attachUrls.push(url);
        t.innerHTML = (f.type.startsWith('video/') ? '<video src="' + url + '#t=0.1" muted preload="metadata"></video><i class="ri-movie-line at-v"></i>' : '<img src="' + url + '" alt="">') + '<button type="button" class="at-x" aria-label="Remove"><i class="ri-close-line"></i></button>';
        t.querySelector('.at-x').onclick = e => { e.stopPropagation(); attachFiles.splice(i, 1); renderAttach(); onComposerInput(); };
        t.addEventListener('click', () => { if (window.mcOpen) window.mcOpen(i); });
        strip.appendChild(t);
    });
    if (attachFiles.length && attachFiles.length < MAX_FILES) {
        const add = document.createElement('button'); add.type = 'button'; add.className = 'at-add'; add.innerHTML = '<i class="ri-add-line"></i>'; add.setAttribute('aria-label', 'Add more');
        add.onclick = () => fileInput.click(); strip.appendChild(add);
    }
    const total = attachFiles.reduce((a, f) => a + f.size, 0);
    $('attachInfo').textContent = attachFiles.length ? attachFiles.length + (attachFiles.length > 1 ? ' files · ' : ' file · ') + fmtSize(total) : '';
    $('attachPreview').classList.toggle('visible', attachFiles.length > 0);
    if (window.mcRefresh) window.mcRefresh();
}
function clearAttach() {
    attachFiles = []; renderAttach();
    $('attachProgress').classList.remove('on');
    if (msgInput) onComposerInput();
}
function clearReply() { $('replyBar').classList.remove('visible'); $('replyToId').value = ''; }

function sendNow() {
    if (!CHAT_ON || !CAN_SEND) return;
    let text = msgInput.value.trim();
    const files = attachFiles.slice();
    if (!text && !files.length) return;
    sendPayload({ text: text, files: files, replyId: $('replyToId').value });
    msgInput.value = ''; typingOn = false; typingSentAt = 0; clearAttach(); clearReply(); onComposerInput(); lsDel('ms_draft_' + chatKey);
}
function sendSticker(s) { if (!CHAT_ON || !CAN_SEND) return; $('stickerPicker').classList.remove('open'); sendPayload({ text: s, files: [], replyId: '' }); }

function pendingRow(p) {
    const rows = msgWindow.querySelectorAll('.msg-row[data-sender]');
    const last = rows[rows.length - 1];
    const row = document.createElement('div');
    row.className = 'msg-row sent pending fresh' + (last && String(last.dataset.sender) === String(currentUserId) ? '' : ' new-group');
    row.dataset.id = 'tmp' + Date.now() + Math.floor(Math.random() * 999);
    const files = p.files || [];
    let media = '';
    if (files.length) {
        const shown = files.slice(0, 4), extra = files.length - shown.length;
        media = '<div class="mcol n' + shown.length + '">' + shown.map((f, i) => '<span class="mt">' + (f.type.startsWith('image/') ? '<img src="' + URL.createObjectURL(f) + '" alt="">' : '<span class="mt-play"><i class="ri-play-fill"></i></span>') + (i === 3 && extra > 0 ? '<span class="mt-more">+' + (extra + 1) + '</span>' : '') + '</span>').join('') + '</div>';
    }
    const only = files.length && !p.text;
    const big = !files.length && /^(?:\p{Extended_Pictographic}[️‍]*\s*){1,3}$/u.test(p.text);
    row.innerHTML = '<div class="msg-wrap"><div class="bubble b-sent' + (big ? ' emoji-only' : '') + (only ? ' bubble-collage' : '') + '">' + media + (p.text ? '<div class="bubble-text">' + esc(p.text).replace(/\n/g, '<br>') + '</div>' : '') + (files.length ? '<div class="pend-progress"><i></i></div>' : '') + '</div></div>'
        + '<div class="msg-meta"><span class="pend-state">Sending…</span></div>';
    $('mwEmpty') && $('mwEmpty').remove();
    mwAppend(row);
    scrollToBottom(false);
    return row;
}

function postForm(fd, onProgress) {
    return new Promise(resolve => {
        const x = new XMLHttpRequest();
        x.open('POST', 'messages.php');
        x.setRequestHeader('X-CSRF-Token', CSRF_TOKEN);
        if (x.upload && onProgress) x.upload.onprogress = e => { if (e.lengthComputable) onProgress(e.loaded / e.total); };
        x.onload = () => { let d = null; try { d = JSON.parse(x.responseText); } catch (e) {} resolve({ status: x.status, data: d }); };
        x.onerror = () => resolve({ status: 0, data: null });
        x.send(fd);
    });
}

async function sendPayload(p, existingRow) {
    if (!CHAT_ON || !CAN_SEND) return;
    const row = existingRow || pendingRow(p);
    row.classList.remove('failed'); row.classList.add('pending');
    row.querySelector('.msg-meta').innerHTML = '<span class="pend-state">Sending…</span>';
    const fd = new FormData();
    if (IS_GROUP) { fd.append('ms_action', 'gsend'); fd.append('group', GROUP_ID); } else { fd.append('ms_action', 'send'); fd.append('receiver', chatUserId); }
    fd.append('message', p.text); fd.append('ajax', '1'); fd.append('csrf_token', CSRF_TOKEN);
    if (p.replyId) fd.append('reply_to', p.replyId);
    (p.files || []).forEach(f => fd.append('attachment[]', f, f.name));
    const bar = row.querySelector('.pend-progress i');
    sending++;
    const r = await postForm(fd, f => { if (bar) bar.style.width = Math.round(f * 100) + '%'; });
    sending--;
    if (r.data && r.data.success) {
        if (exists(r.data.id)) row.remove();
        else { const tpl = document.createElement('template'); tpl.innerHTML = r.data.html; const nr = tpl.content.querySelector('.msg-row'); if (nr) { tpl.content.querySelectorAll('.msg-row').forEach(n => n.classList.add('fresh')); row.replaceWith(...tpl.content.childNodes); msgWindow.querySelectorAll('.msg-row[data-id]').forEach(bindReactions); } else row.remove(); }
        if (stickBottom) scrollToBottom(false);
        return;
    }
    row.classList.remove('pending'); row.classList.add('failed');
    const msg = (r.data && (r.data.error || r.data.message)) || 'Could not send';
    const meta = row.querySelector('.msg-meta');
    meta.innerHTML = '<span style="color:var(--danger)">' + esc(msg) + '</span> <button type="button" class="retry">Retry</button> <button type="button" class="retry">Discard</button>';
    const [retry, discard] = meta.querySelectorAll('.retry');
    retry.onclick = () => sendPayload(p, row);
    discard.onclick = () => { row.classList.add('removing'); setTimeout(() => row.remove(), 300); };
    if (r.status === 403) toast(msg);
}

// ── PHOTO / VIDEO VIEWER ──────────────────────────────────────
let mvItems = [], mvIdx = 0, mvWho = '', mvPic = '', mvTs = 0;
function mvAgo(ts) {
    if (!ts) return '';
    const s = Math.max(0, Math.floor(Date.now() / 1000 - ts));
    if (s < 60) return 'just now';
    const u = [[60, 'minute'], [3600, 'hour'], [86400, 'day'], [604800, 'week'], [2629800, 'month'], [31557600, 'year']];
    let best = u[0];
    for (const x of u) if (s >= x[0]) best = x;
    const n = Math.floor(s / best[0]);
    return n + ' ' + best[1] + (n === 1 ? '' : 's') + ' ago';
}
function openViewer(el, i) {
    let items = []; try { items = JSON.parse(el.dataset.media || '[]'); } catch (e) {}
    const row = el.closest('.msg-row');
    const who = row ? (String(row.dataset.sender) === String(currentUserId) ? 'You' : (row.dataset.username || '')) : '';
    const av = who === 'You' ? document.querySelector('.sb-me img') : (row && row.querySelector('.msg-avatar'));
    openViewerList(items, i, who, av ? av.src : '', row ? +row.dataset.ts : 0);
}
function openViewerList(items, i, who, pic, ts) {
    if (!items || !items.length) return;
    mvItems = items; mvWho = who || 'Shared in this chat'; mvPic = pic || ''; mvTs = ts || 0;
    mvIdx = Math.min(Math.max(0, i || 0), items.length - 1);
    $('mvOverlay').classList.add('open'); document.body.style.overflow = 'hidden';
    showViewer();
}
function mvClearStage() {
    const st = $('mvStage'), ov = $('mvOverlay');
    st.querySelectorAll('video').forEach(v => v.pause());
    ov.appendChild($('mvPrev')); ov.appendChild($('mvNext'));
    st.querySelectorAll('.mv-frame').forEach(f => f.remove());
}
function showViewer() {
    const it = mvItems[mvIdx], st = $('mvStage');
    mvClearStage();
    const spin = $('mvSpin'); spin.classList.add('on');
    let el;
    if (it.t === 'v') { el = document.createElement('video'); el.controls = true; el.autoplay = true; el.playsInline = true; el.setAttribute('playsinline', ''); el.onloadeddata = () => spin.classList.remove('on'); el.src = it.s; }
    else { el = new Image(); el.alt = 'Photo'; el.onload = () => spin.classList.remove('on'); el.src = it.s; }
    el.onerror = () => { spin.classList.remove('on'); toast('Could not load this file'); };
    const fr = document.createElement('div'); fr.className = 'mv-frame'; fr.appendChild(el); st.appendChild(fr);
    $('mvBg').style.backgroundImage = it.t === 'v' ? 'none' : 'url("' + String(it.s).replace(/"/g, '%22') + '")';
    $('mvWho').textContent = mvWho;
    const av = $('mvAv'); av.style.display = mvPic ? '' : 'none'; if (mvPic) av.src = mvPic;
    const ago = mvAgo(mvTs);
    $('mvCount').textContent = mvItems.length > 1 ? (ago ? ago + ' · ' : '') + (mvIdx + 1) + ' of ' + mvItems.length : (ago || (it.t === 'v' ? 'Video' : 'Photo'));
    const canDl = !!it.d && typeof it.id === 'number';
    $('mvDl').style.display = canDl ? 'flex' : 'none';
    $('mvNoDl').style.display = canDl ? 'none' : 'flex';
    if (canDl) $('mvDl').href = 'messages.php?ms=' + (IS_GROUP ? 'gdl' : 'dl') + '&id=' + it.id;
    $('mvPrev').hidden = mvIdx <= 0; $('mvNext').hidden = mvIdx >= mvItems.length - 1;
    const dots = $('mvDots');
    if (mvItems.length > 1) {
        dots.classList.add('on');
        dots.innerHTML = mvItems.map((m, k) => '<i class="' + (k === mvIdx ? 'on' : '') + '"></i>').join('');
    } else { dots.classList.remove('on'); dots.innerHTML = ''; }
}
function viewerStep(d) { const n = mvIdx + d; if (n < 0 || n >= mvItems.length) return; mvIdx = n; showViewer(); }
function closeViewer() {
    mvClearStage();
    $('mvDots').innerHTML = '';
    $('mvOverlay').classList.remove('open'); document.body.style.overflow = '';
    const f = $('mvOverlay'); f.style.transform = ''; f.style.opacity = '';
}
document.addEventListener('keydown', e => {
    if (!$('mvOverlay').classList.contains('open')) return;
    if (e.key === 'Escape') closeViewer(); else if (e.key === 'ArrowLeft') viewerStep(-1); else if (e.key === 'ArrowRight') viewerStep(1);
});
(function () {   // swipe sideways to change item, swipe down to close
    let x0 = null, y0 = null, dy = 0, drag = false; const ov = $('mvOverlay'), st = $('mvStage');
    st.addEventListener('touchstart', e => { if (e.target.closest('video')) { x0 = null; return; } x0 = e.touches[0].clientX; y0 = e.touches[0].clientY; dy = 0; drag = false; }, { passive: true });
    st.addEventListener('touchmove', e => {
        if (x0 === null) return;
        const mx = e.touches[0].clientX - x0, my = e.touches[0].clientY - y0;
        if (!drag && my > 12 && my > Math.abs(mx) * 1.4) drag = true;
        if (drag) { dy = Math.max(0, my); st.style.transform = 'translateY(' + dy + 'px) scale(' + (1 - Math.min(dy, 400) / 2400) + ')'; ov.style.opacity = String(1 - Math.min(dy, 300) / 520); }
    }, { passive: true });
    st.addEventListener('touchend', e => {
        if (x0 === null) return;
        const dx = e.changedTouches[0].clientX - x0; x0 = null;
        if (drag) { drag = false; st.style.transform = ''; if (dy > 110) closeViewer(); else ov.style.opacity = ''; return; }
        if (Math.abs(dx) > 60) viewerStep(dx < 0 ? 1 : -1);
    }, { passive: true });
})();

// ── POLLS ─────────────────────────────────────────────────────
function pHap(k) { try { if (window.dzHaptic && dzHaptic[k]) dzHaptic[k](); } catch (e) {} }
const pollOpen = new Set();
function pollIds() { return Array.from(document.querySelectorAll('.poll-card')).map(c => c.dataset.poll).slice(-30); }
function pollSel(card) { return Array.from(card.querySelectorAll('.pc-opt.sel')).map(b => b.dataset.opt); }
function pollDirty(card) {
    const a = pollSel(card).sort().join(','), b = (card.dataset.v || '').split(',').filter(Boolean).sort().join(',');
    return a !== b;
}
function pollSync(card) {
    const dirty = pollDirty(card), btn = card.querySelector('.pc-vote');
    card.classList.toggle('pc-dirty', dirty);
    if (btn) btn.disabled = !dirty || !pollSel(card).length;
}
function pollReplace(id, html) {
    const old = document.querySelector('.poll-card[data-poll="' + id + '"]'); if (!old || !html) return;
    if (old.classList.contains('pc-dirty') || old.classList.contains('pc-busy')) return;
    const tpl = document.createElement('template'); tpl.innerHTML = html.trim();
    const nw = tpl.content.firstElementChild; if (!nw) return;
    if (pollOpen.has(String(id))) nw.classList.add('pc-expanded');
    if (old.outerHTML === nw.outerHTML) return;
    old.replaceWith(nw);
}
function pollApplyAll(map) { if (map) Object.keys(map).forEach(id => pollReplace(id, map[id])); }
document.addEventListener('click', async e => {
    const card = e.target.closest('.poll-card'); if (!card) return;
    const id = card.dataset.poll;
    if (e.target.closest('.pc-more')) { card.classList.add('pc-expanded'); pollOpen.add(id); pHap('light'); return; }
    if (e.target.closest('.pc-end')) {
        if (!confirm('End this poll? Nobody will be able to vote after this.')) return;
        card.classList.add('pc-busy');
        const fd = new FormData(); fd.append('ms_action', 'gpoll_close'); fd.append('group', GROUP_ID); fd.append('poll', id);
        try { const r = await fetch('messages.php', { method: 'POST', body: fd }); const d = await r.json(); card.classList.remove('pc-busy', 'pc-dirty'); if (d.success) { pollReplace(id, d.html); pHap('success'); } else toast(d.error || 'Could not end the poll'); }
        catch (x) { card.classList.remove('pc-busy'); toast('Could not end the poll'); }
        return;
    }
    const opt = e.target.closest('.pc-opt');
    if (opt && !card.classList.contains('pc-closed')) {
        if (card.dataset.multi === '1') opt.classList.toggle('sel');
        else { card.querySelectorAll('.pc-opt.sel').forEach(b => b.classList.remove('sel')); opt.classList.add('sel'); }
        pHap('selection'); pollSync(card); return;
    }
    const vote = e.target.closest('.pc-vote');
    if (vote && !vote.disabled) {
        const sel = pollSel(card); if (!sel.length) return;
        card.classList.add('pc-busy');
        const fd = new FormData(); fd.append('ms_action', 'gpoll_vote'); fd.append('group', GROUP_ID); fd.append('poll', id); fd.append('opts', sel.join(','));
        try {
            const r = await fetch('messages.php', { method: 'POST', body: fd }); const d = await r.json();
            card.classList.remove('pc-busy', 'pc-dirty');
            if (d.success) { pollReplace(id, d.html); pHap('success'); } else { toast(d.error || 'Could not save your vote'); pollSync(card); }
        } catch (x) { card.classList.remove('pc-busy'); toast('Could not save your vote'); }
    }
});

// new poll sheet
function plRow(val) {
    const w = document.createElement('div'); w.className = 'pl-o';
    w.innerHTML = '<input type="text" maxlength="100" placeholder="Option" autocomplete="off"><button type="button" class="pl-rm" aria-label="Remove option"><i class="ri-close-circle-line"></i></button>';
    w.querySelector('input').value = val || '';
    return w;
}
function plRefresh() {
    const rows = $('plOpts').children, n = rows.length;
    Array.from(rows).forEach((r, i) => { r.querySelector('input').placeholder = 'Option ' + (i + 1); r.querySelector('.pl-rm').hidden = n <= 2; });
    $('plAdd').disabled = n >= 12;
    const q = $('plQ').value.trim(), filled = Array.from(rows).map(r => r.querySelector('input').value.trim().toLowerCase()).filter(Boolean);
    $('plQc').textContent = $('plQ').value.length + '/200';
    $('plSend').disabled = !q || new Set(filled).size < 2;
}
function openPollCreate() {
    if (!IS_GROUP || !CAN_SEND) return;
    $('plQ').value = ''; $('plMulti').checked = false; $('plOpts').innerHTML = '';
    $('plOpts').appendChild(plRow('')); $('plOpts').appendChild(plRow(''));
    plRefresh(); $('plOv').classList.add('open'); pHap('light');
    setTimeout(() => { try { $('plQ').focus(); } catch (e) {} }, 260);
}
function closePollCreate() { $('plOv').classList.remove('open'); if (document.activeElement && document.activeElement.blur) document.activeElement.blur(); }
$('plOpts').addEventListener('input', plRefresh);
$('plQ').addEventListener('input', plRefresh);
$('plOpts').addEventListener('click', e => { const b = e.target.closest('.pl-rm'); if (!b) return; if ($('plOpts').children.length > 2) { b.closest('.pl-o').remove(); plRefresh(); pHap('light'); } });
$('plOpts').addEventListener('keydown', e => {
    if (e.key !== 'Enter') return; e.preventDefault();
    const rows = Array.from($('plOpts').children), i = rows.indexOf(e.target.closest('.pl-o'));
    if (i < rows.length - 1) rows[i + 1].querySelector('input').focus(); else $('plAdd').click();
});
$('plAdd').addEventListener('click', () => {
    if ($('plOpts').children.length >= 12) return;
    const r = plRow(''); $('plOpts').appendChild(r); plRefresh(); pHap('light');
    r.querySelector('input').focus(); r.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
});
$('plSend').addEventListener('click', async () => {
    const btn = $('plSend'); if (btn.disabled) return;
    btn.disabled = true; const old = btn.innerHTML; btn.innerHTML = '<span class="spin"></span> Sending…';
    const fd = new FormData(); fd.append('ms_action', 'gpoll_create'); fd.append('group', GROUP_ID); fd.append('question', $('plQ').value);
    Array.from($('plOpts').children).forEach(r => fd.append('options[]', r.querySelector('input').value));
    if ($('plMulti').checked) fd.append('multi', '1');
    try {
        const res = await fetch('messages.php', { method: 'POST', body: fd }); const d = await res.json();
        if (d.success) { closePollCreate(); appendHtml(d.html, true); if (d.id) lastMsgId = Math.max(lastMsgId, d.id); pHap('success'); }
        else toast(d.error || 'Could not create the poll');
    } catch (x) { toast('Could not create the poll'); }
    btn.innerHTML = old; plRefresh();
});
document.addEventListener('keydown', e => { if (e.key === 'Escape' && $('plOv').classList.contains('open')) closePollCreate(); });

// ── MUTE · DISAPPEARING MESSAGES · DOWNLOAD PERMISSION ────────
function setMuted(on) {
    chatMuted = on;
    const hm = $('hdrMute'); if (hm) hm.style.display = on ? '' : 'none';
    const mi = $('muteItem');
    if (mi) { mi.querySelector('i').className = on ? 'ri-notification-3-line' : 'ri-notification-off-line'; mi.querySelector('span').textContent = on ? 'Unmute' : 'Mute'; }
}
async function toggleMute() {
    const on = !chatMuted;
    const d = IS_GROUP ? await act('gmute', { group: GROUP_ID, on: on ? 1 : 0 }) : await act('mute', { target_id: chatUserId, on: on ? 1 : 0 });
    if (d && d.success) { setMuted(on); toast(on ? 'Chat muted' : 'Chat unmuted'); poll(); } else toast((d && d.error) || 'Could not change mute');
}
function applyVanish(mode) {
    vanishMode = mode;
    const bar = $('vanishBar'); if (bar) { bar.style.display = mode ? '' : 'none'; $('vanishText').textContent = 'Disappearing messages: ' + VANISH_NAMES[mode]; }
    const v = $('ddVanishVal'); if (v) v.textContent = VANISH_NAMES[mode];
}
function openDisappear() {
    document.querySelectorAll('.dm-opt').forEach(b => b.classList.toggle('on', parseInt(b.dataset.mode, 10) === vanishMode));
    $('dmOverlay').classList.add('open');
}
function closeDisappear() { $('dmOverlay').classList.remove('open'); }
async function setDisappear(mode) {
    const d = await act('set_disappear', { target_id: chatUserId, mode: mode });
    if (d && d.success) { applyVanish(mode); closeDisappear(); toast(mode ? 'Disappearing messages: ' + VANISH_NAMES[mode] : 'Disappearing messages off'); }
    else toast((d && d.error) || 'Could not change this setting');
}
async function toggleDownloads() {
    closeMore();
    const on = !allowDl;
    const d = await act('set_download', { on: on ? 1 : 0 });
    if (d && d.success) { allowDl = on; $('dlVal').textContent = on ? 'On' : 'Off'; toast(on ? 'People you message can download your photos and videos' : 'Downloads of your photos and videos are off'); }
    else toast((d && d.error) || 'Could not change this setting');
}
// messages with a timer disappear from the screen the moment their time is up
setInterval(() => {
    if (!msgWindow) return;
    const t = nowSrv();
    msgWindow.querySelectorAll('.msg-row[data-exp]').forEach(r => {
        const e = parseInt(r.dataset.exp, 10);
        if (e > 0 && e <= t) { r.classList.add('removing'); setTimeout(() => r.remove(), 380); }
    });
}, 5000);

// ── CHAT LIST: infinite scroll (15 at a time) ─────────────────
async function loadMoreConvs() {
    const cl = $('convList');
    if (!cl || convLoading || !convMore) return;
    convLoading = true;
    const loader = document.createElement('div'); loader.className = 'conv-more-loader'; loader.innerHTML = '<span class="spin"></span> Loading chats…';
    cl.appendChild(loader);
    try {
        const off = cl.querySelectorAll('.conv-item').length;
        const res = await fetch('messages.php?ms=convs&offset=' + off + (chatUserId ? '&user=' + chatUserId : ''), { cache: 'no-store' });
        const d = await res.json();
        await new Promise(r => setTimeout(r, 250));
        loader.remove();
        const tpl = document.createElement('template'); tpl.innerHTML = d.html || '';
        const have = new Set(Array.from(cl.querySelectorAll('.conv-item')).map(e => e.dataset.uid));
        Array.from(tpl.content.children).forEach(n => { if (!have.has(n.dataset.uid)) cl.appendChild(n); });
        convMore = !!d.more;
    } catch (e) { loader.remove(); }
    convLoading = false;
    if (convMore && cl.scrollHeight <= cl.clientHeight + 40) loadMoreConvs();   // not tall enough to scroll yet
}
(function () {
    const cl = $('convList'); if (!cl) return;
    cl.addEventListener('scroll', () => { if (cl.scrollTop + cl.clientHeight >= cl.scrollHeight - 160) loadMoreConvs(); }, { passive: true });
})();

// ── LIVE UPDATES (new messages, seen ticks, active dot, inbox) ─
let pollTimer = null, pollBusy = false, pollFails = 0;
const haveUids = () => Array.from(document.querySelectorAll('#convList .conv-item, #reqList .conv-item')).map(e => e.dataset.uid).join(',');

function setPresence(p) {
    if (!p) return;
    [$('hdrDot'), $('ciDot')].forEach(d => { if (d) d.style.display = p.active ? '' : 'none'; });
    document.querySelectorAll('.ccm-av-dot[data-uid="' + chatUserId + '"]').forEach(d => { d.style.display = p.active ? '' : 'none'; });
    const live = p.visible ? (p.active ? '<span class="live">Active now</span>' : esc(p.label)) : '';
    const hs = $('hdrStatus');
    if (hs) { const nick = hs.dataset.nick; hs.innerHTML = [nick ? '@' + esc(nick) : '', live].filter(Boolean).join(' · '); }
    if ($('ciStatus')) $('ciStatus').innerHTML = live;
}
function markSeenUpTo(n) {
    if (!n || !msgWindow) return;
    msgWindow.querySelectorAll('.msg-row.sent[data-id]').forEach(r => {
        if (!/^\d+$/.test(r.dataset.id) || parseInt(r.dataset.id, 10) > n || r.dataset.seen === '1') return;
        r.dataset.seen = '1';
        const t = r.querySelector('.seen-tick'); if (t) t.className = 'seen-tick ri-check-double-line seen';
    });
}
function updateConvEl(el, it) {
    const last = el.querySelector('.conv-last');
    if (last) { last.innerHTML = it.italic ? '<i>' + esc(it.last) + '</i>' : esc(it.last); last.classList.toggle('unread', it.unread > 0); }
    const time = el.querySelector('.conv-time'); if (time) time.textContent = it.time;
    const meta = el.querySelector('.conv-meta'); let badge = el.querySelector('.conv-badge');
    if (it.unread > 0) { if (!badge) { badge = document.createElement('span'); meta.appendChild(badge); } badge.className = 'conv-badge' + (it.muted ? ' muted' : ''); badge.textContent = ''; }
    else if (badge) badge.remove();
    const nmEl = el.querySelector('.conv-name'); let mi = nmEl && nmEl.querySelector('.conv-mute');
    if (nmEl && it.muted && !mi) { mi = document.createElement('i'); mi.className = 'ri-notification-off-fill conv-mute'; mi.title = 'Muted'; nmEl.appendChild(mi); }
    else if (mi && !it.muted) mi.remove();
    if ('active' in it) {
        const av = el.querySelector('.conv-avatar'); let dot = av && av.querySelector('.online-dot');
        if (av && it.active && !dot) { dot = document.createElement('span'); dot.className = 'online-dot'; av.appendChild(dot); }
        else if (dot && !it.active) dot.remove();
    }
}
const GROUPS_EMPTY = '<div class="sb-empty" id="groupEmpty"><i class="ri-group-line"></i><b>No group chats yet</b>Start one with two or more people.<br><button type="button" class="act-btn accent ng-btn" onclick="openNewGroup()">New group</button></div>';
function applyInbox(d) {
    document.title = (d.unread > 0 ? '(' + d.unread + ') ' : '') + 'Messages — Dezzme';
    const uc = $('unreadCount'); if (uc) uc.textContent = d.unread > 0 ? (d.unread > 99 ? '99+' : d.unread) : '';
    if (d.gdot !== undefined && $('grpDot')) $('grpDot').style.display = d.gdot ? '' : 'none';
    if (d.arch_n !== undefined && d.arch_n !== archN) refreshArchive();
    const gl = $('groupList');
    if (gl) {
        if (d.groups_html !== undefined) { gl.innerHTML = d.groups_html || GROUPS_EMPTY; groupMore = true; }
        else (d.groups || []).forEach(it => { const el = gl.querySelector('.conv-item[data-gid="' + it.id + '"]'); if (el) updateConvEl(el, Object.assign({}, it, { unread: it.unread || (it.fresh ? 1 : 0) })); });
    }
    if (d.convs_html !== undefined) {
        const cl = $('convList');
        cl.innerHTML = d.convs_html || '<div class="sb-empty" id="convEmpty"><i class="ri-chat-3-line"></i><b>No conversations yet</b>Tap the pencil above to message someone.</div>';
        $('reqList').innerHTML = d.reqs_html || '';
        convMore = true;   // the list was rebuilt from the top; scrolling can load more again
        $('reqCount').textContent = d.req_count > 0 ? d.req_count : '';
        $('tabReq').style.display = d.req_count > 0 ? '' : 'none';
        if (searchRes.style.display === 'none') $('sbTabs').style.display = tabsShown() ? '' : 'none';
        if (!d.req_count && curTab === 'req') switchTab('main');
        return;
    }
    (d.inbox || []).forEach(it => { const el = document.querySelector('.conv-item[data-uid="' + it.id + '"]'); if (el) updateConvEl(el, it); });
}
async function poll() {
    if (pollBusy) return; pollBusy = true;
    try {
        const vis = document.visibilityState === 'visible';
        let url = 'messages.php?ms=poll&lim=' + Math.max(15, document.querySelectorAll('#convList .conv-item').length) + '&have=' + encodeURIComponent(haveUids());
        if (GROUPS_ON) url += '&glim=' + Math.max(15, document.querySelectorAll('#groupList .conv-item').length) + '&ghave=' + encodeURIComponent(Array.from(document.querySelectorAll('#groupList .conv-item')).map(e => e.dataset.gid).join(','));
        if (IS_GROUP) { url += '&group=' + GROUP_ID + '&after=' + lastMsgId + (vis ? '&vis=1' : ''); const pi = pollIds(); if (pi.length) url += '&polls=' + pi.join(','); }
        else if (chatUserId) url += '&user=' + chatUserId + '&after=' + lastMsgId + (vis ? '&vis=1' : '');
        const res = await fetch(url, { cache: 'no-store' });
        if (!res.ok) throw new Error('http');
        const d = await res.json();
        pollFails = 0;
        if (d.left) { toast('You are no longer in this group'); setTimeout(() => { window.location.href = 'messages.php'; }, 900); return; }
        if (d.html) { if (d.has_incoming) setTyping([]); appendHtml(d.html, false); if (d.last_id) lastMsgId = Math.max(lastMsgId, d.last_id); }
        if (d.polls) pollApplyAll(d.polls);
        if (d.typing !== undefined) setTyping(d.typing);
        if (d.gbg !== undefined && d.gbg !== GROUP_BG) { GROUP_BG = d.gbg; paintBg(d.gbg || null, d.gbgt); }
        if (d.gname !== undefined) { const h = $('hdrName'); if (h && h.textContent !== d.gname) h.textContent = d.gname; }
        if (d.gcount !== undefined) { const hs = $('hdrStatus'); if (hs) hs.textContent = d.gcount + ' member' + (d.gcount === 1 ? '' : 's'); }
        if (d.seen_upto) markSeenUpTo(d.seen_upto);
        if (d.presence) setPresence(d.presence);
        if (d.disappear !== undefined && d.disappear !== vanishMode) applyVanish(d.disappear);
        if (d.muted !== undefined && d.muted !== chatMuted) setMuted(d.muted);
        applyInbox(d);
    } catch (e) { pollFails++; }
    pollBusy = false; schedulePoll();
}
function schedulePoll() {
    clearTimeout(pollTimer);
    const base = document.visibilityState === 'visible' ? (CHAT_ON ? 2500 : 3500) : 15000;
    pollTimer = setTimeout(poll, pollFails > 2 ? Math.min(30000, base * pollFails) : base);
}
document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') { clearTimeout(pollTimer); poll(); } });
schedulePoll();

// ── MENU SKELETON: shown while the page loads, only over the menu list area ──
(function () {
    const sk = $('sbSkel'); if (!sk) return;
    let done = false;
    const hide = () => { if (done) return; done = true; sk.classList.add('out'); setTimeout(() => sk.remove(), 320); };
    if (document.readyState === 'complete') setTimeout(hide, 280); else window.addEventListener('load', () => setTimeout(hide, 280));
    setTimeout(hide, 1500);
})();
</script>
<script>/* ══════════════════════════════════════════════════════════════
   COPY CAPTION / QR (shared helpers used by the kebab sheet)
   ══════════════════════════════════════════════════════════════ */
function copyCaption(caption) {
    navigator.clipboard.writeText(caption).then(() => {
        toast('Caption copied!');
    }).catch(() => {
        const el = document.createElement('textarea');
        el.value = caption;
        document.body.appendChild(el);
        el.select();
        document.execCommand('copy');
        document.body.removeChild(el);
        toast('Caption copied!');
    });
}



/* ── Shared posts in a chat open in the shared Zeems modal (zeems_modal.php, the same one home / profile / history use) ── */
var _msFeed = [];
function _msNorm(it){
    it.isFollowing = !!(it.following || it.isFollowing);
    it.isYou = String(it.userId) === String(currentUserId);
    it.context = '';   /* the Zeems modal's top label stays "Zeems" */
    /* data for the ⋯ post menu (shared_modals.php) */
    window.kebabData = window.kebabData || {};
    var f = (it.media && it.media[0] && it.media[0].file) ? it.media[0].file : (it.file || '');
    window.kebabData[it.id] = { id: it.id, fileName: String(f).replace(/^uploads\//, '') || '0', type: (it.media && it.media[0] && it.media[0].type) || it.type || 'text',
        uploadedBy: it.userId, remixOf: null, caption: it.caption || '', isAd: !!it.isAd, posterPic: it.pic || 'mee.jpg', posterUsername: it.username || '', allowDownload: (it.allowDownload === 0 ? 0 : 1), collab: it.collab || null };
    return it;
}
function _msLoadFeed(cb){
    if(!chatUserId){ cb([]); return; }
    fetch('messages.php?type=chat_shared_memes&with=' + chatUserId)
        .then(function(r){ return r.json(); })
        .then(function(data){
            var seen = {}, out = [];
            (data || []).forEach(function(it){ if(!seen[it.id]){ seen[it.id] = 1; out.push(_msNorm(it)); } });   /* one entry per post, even if it was shared twice */
            _msFeed = out; cb(_msFeed);
        })
        .catch(function(){ _msFeed = []; cb([]); });
}
if(chatUserId){ _msLoadFeed(function(){}); }
window.addEventListener('load', function(){ setTimeout(function(){ if(document.querySelector('.shared-meme-card') && window.ccmOwnWarm) window.ccmOwnWarm(); }, 600); });   /* the algorithm run is ready before a post is tapped */

function openSharedMemeModal(memeId, senderId, senderUsername, senderPic, sentAt){
    /* the tapped post opens first, and scrolling goes on with the algorithm (the same run as the home page), not with the next post of this chat */
    function show(feed){
        if(typeof window.openCardModal !== 'function'){ toast('The viewer is not ready yet'); return; }
        var tapped = feed[0];
        var open = function(list){
            var go = function(){ window.openCardModal({ id: memeId, feed: list, mediaIndex: 0, startTime: 0 }); };
            go();
            setTimeout(function(){ var mm = document.getElementById('cardCaptionModal'); if(!(mm && mm.classList.contains('ccm-open'))) go(); }, 500);   /* if the first tap did not open it, open it again by itself: never two taps */
        };
        if(typeof window.ccmOwnRunFor === 'function'){
            window.ccmOwnRunFor(tapped).then(function(run){ run.forEach(function(it){ if(it !== tapped) _msNorm(it); }); open(run); });
        } else open([tapped]);
    }
    function proceed(){
        var hit = null;
        _msFeed.some(function(m){ if(String(m.id) === String(memeId)){ hit = m; return true; } return false; });
        if(hit){ show([hit]); return; }
        fetch('messages.php?type=single_meme&id=' + memeId)
            .then(function(r){ return r.json(); })
            .then(function(data){
                if(!data || !data.id){ toast('This post is no longer available'); return; }
                show([_msNorm(data)]);
            })
            .catch(function(){ toast('Could not load post'); });
    }
    if(_msFeed.length) proceed(); else _msLoadFeed(proceed);
}
</script>
<script>
/* host setup for zeems_modal.php (must come BEFORE the include) */
/* a post scrolled to in a chat's Zeems that is not in the chat yet: "Share this in the Chat" sends it as a normal shared post and the chat shows it at once */
window.CCM_CHAT_SHARE = {
    has: function(id){
        if(!chatUserId) return true;                                         /* group chats: no button */
        if(window._msSharedNow && window._msSharedNow[id]) return true;
        return _msFeed.some(function(m){ return String(m.id) === String(id); });
    },
    send: function(id){
        return fetch('messages.php', { method:'POST', credentials:'same-origin', headers:{ 'Content-Type':'application/x-www-form-urlencoded' },
            body:'action=share_post&meme_id=' + encodeURIComponent(id) + '&receiver_ids=' + encodeURIComponent(chatUserId) })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if(!d || d.status !== 'ok') return false;
            (window._msSharedNow = window._msSharedNow || {})[id] = 1;
            clearTimeout(pollTimer); poll();                                  /* the new message appears right now */
            setTimeout(function(){ clearTimeout(pollTimer); poll(); }, 700);
            return true;
        });
    }
};
window.CCM_OWN_FORCE = true; window.CCM_OWN_LAZY = true; window.CCM_OWN_URL = 'zeems_feed.php';   /* scrolling in a chat's Zeems = the algorithm run from home.php */
window.CCM_ENDPOINT = 'messages.php';             /* like / repost / save / follow go to this page's own action handler */
window.peuraFeed = window.peuraFeed || [];         /* the list comes from openSharedMemeModal() */
window._suggPromise = window._suggPromise || Promise.resolve([]);
window.showToast = window.showToast || function(m){ toast(m); };
window.kebabData = window.kebabData || {};
var _appTopZIndex = 2147483000;
function bringModalToFront(el){ if(el) el.style.zIndex = ++_appTopZIndex; }
</script>
<style>
/* Zeems modal text must look exactly like it does on the history page: Poppins at the browser's default size.
   messages.php's body is DM Sans 14px, which leaked into the modal and made the words look different. */
#cardCaptionModal{ font-family:'Poppins',sans-serif; font-size:16px; line-height:normal; letter-spacing:normal; -webkit-font-smoothing:antialiased; }
#cardCaptionModal *, #cardCaptionModal *::before, #cardCaptionModal *::after{ font-family:'Poppins',sans-serif; }
#cardCaptionModal button, #cardCaptionModal input, #cardCaptionModal textarea{ font-family:'Poppins',sans-serif; }
#cardCaptionModal [class^="ri-"], #cardCaptionModal [class*=" ri-"], #cardCaptionModal [class^="ri-"]::before, #cardCaptionModal [class*=" ri-"]::before{ font-family:'remixicon' !important; }
</style>
<!-- ═══ MEDIA COMPOSER: full-screen preview + caption for photos/videos you are about to send ═══ -->
<style>
.toast{z-index:9600;}
#mcOv{position:fixed;left:0;right:0;top:0;height:var(--mc-h,100dvh);z-index:9000;display:none;flex-direction:column;background:#000;color:#fff;-webkit-font-smoothing:antialiased;overscroll-behavior:contain;touch-action:manipulation;}
#mcOv.on{display:flex;animation:mcIn .24s cubic-bezier(.22,1,.36,1);}
@keyframes mcIn{from{opacity:0;transform:translateY(18px) scale(.985)}to{opacity:1;transform:none}}
#mcOv *{box-sizing:border-box;}
#mcOv button{font-family:inherit;border:0;background:none;color:inherit;cursor:pointer;padding:0;-webkit-tap-highlight-color:transparent;}
.mc-top{flex:none;display:flex;align-items:center;justify-content:space-between;gap:10px;padding:calc(env(safe-area-inset-top,0px) + 8px) 10px 8px;}
.mc-c{width:38px;height:38px;border-radius:50%;background:rgba(255,255,255,.12);display:flex;align-items:center;justify-content:center;font-size:21px;transition:transform .12s,background .15s;}
.mc-c:active{transform:scale(.9);background:rgba(255,255,255,.2);}
.mc-c.red{background:var(--accent,#FF2D2D);color:#14070a;}
.mc-c[disabled]{opacity:.35;pointer-events:none;}
.mc-pill{display:flex;align-items:center;gap:7px;height:34px;padding:0 14px;border-radius:99px;background:rgba(255,255,255,.12);font-size:13px;font-weight:600;letter-spacing:.02em;font-variant-numeric:tabular-nums;}
.mc-pill i{font-size:15px;color:var(--accent,#FF2D2D);}
.mc-acts{display:flex;gap:8px;}
.mc-stage{flex:1;min-height:0;position:relative;padding:0 8px;}
.mc-track{height:100%;display:flex;gap:8px;overflow-x:auto;overflow-y:hidden;scroll-snap-type:x mandatory;scrollbar-width:none;-webkit-overflow-scrolling:touch;overscroll-behavior-x:contain;}
.mc-track::-webkit-scrollbar{display:none;}
.mc-slide{flex:0 0 100%;height:100%;scroll-snap-align:center;scroll-snap-stop:always;position:relative;border-radius:18px;overflow:hidden;background:#0b0b10;display:flex;align-items:center;justify-content:center;}
.mc-bg{position:absolute;inset:-30px;background-size:cover;background-position:center;filter:blur(30px) saturate(1.3);opacity:.5;transform:scale(1.1);}
.mc-slide img,.mc-slide video{position:relative;z-index:1;max-width:100%;max-height:100%;width:auto;height:auto;object-fit:contain;display:block;border-radius:2px;}
.mc-tag{position:absolute;z-index:2;left:10px;bottom:10px;display:flex;align-items:center;gap:6px;height:26px;padding:0 10px;border-radius:99px;background:rgba(0,0,0,.55);font-size:11.5px;font-weight:500;}
.mc-vc{position:absolute;z-index:3;right:10px;bottom:10px;display:flex;gap:8px;}
.mc-vc button{width:36px;height:36px;border-radius:50%;background:rgba(0,0,0,.58);display:flex;align-items:center;justify-content:center;font-size:19px;transition:transform .12s;}
.mc-vc button:active{transform:scale(.9);}
.mc-vc button.paused{background:var(--accent,#FF2D2D);color:#14070a;}
.mc-thumbs{flex:none;display:none;gap:8px;overflow-x:auto;padding:10px 10px 4px;scrollbar-width:none;}
.mc-thumbs.on{display:flex;}
.mc-thumbs::-webkit-scrollbar{display:none;}
.mc-th{flex:none;position:relative;width:50px;height:50px;border-radius:12px;overflow:hidden;background:#15151c;outline:2px solid transparent;outline-offset:2px;opacity:.55;transition:opacity .15s,outline-color .15s,transform .12s;}
.mc-th.on{opacity:1;outline-color:var(--accent,#FF2D2D);}
.mc-th:active{transform:scale(.94);}
.mc-th img,.mc-th video{width:100%;height:100%;object-fit:cover;display:block;pointer-events:none;}
.mc-th i{position:absolute;right:3px;bottom:3px;font-size:13px;filter:drop-shadow(0 1px 2px #000);}
.mc-th.add{opacity:1;display:flex;align-items:center;justify-content:center;border:1.5px dashed rgba(255,255,255,.4);background:none;font-size:22px;}
.mc-bar{flex:none;display:flex;align-items:flex-end;gap:10px;padding:8px 10px calc(env(safe-area-inset-bottom,0px) + 10px);}
.mc-cap{flex:1;min-width:0;display:flex;align-items:center;min-height:46px;padding:0 6px 0 16px;border-radius:24px;background:rgba(255,255,255,.12);}
.mc-cap textarea{flex:1;min-width:0;max-height:104px;min-height:46px;padding:12px 0;border:0;outline:0;resize:none;background:none;color:#fff;font:inherit;font-size:16px;line-height:22px;}
.mc-cap textarea::placeholder{color:rgba(255,255,255,.55);}
.mc-send{flex:none;width:46px;height:46px;border-radius:50%;background:var(--accent,#FF2D2D);color:#14070a;display:flex;align-items:center;justify-content:center;font-size:22px;box-shadow:0 6px 18px rgba(255,45,45,.35);transition:transform .12s;}
.mc-send:active{transform:scale(.92);}
@media (min-width:769px){
  #mcOv{background:rgba(0,0,0,.88);align-items:center;}
  #mcOv.on{backdrop-filter:blur(6px);}
  .mc-top,.mc-stage,.mc-thumbs,.mc-bar{width:min(560px,100%);}
  .mc-stage{padding:0;}
}
</style>
<div id="mcOv" role="dialog" aria-modal="true" aria-label="Send photos and videos">
  <div class="mc-top">
    <button type="button" class="mc-c" id="mcClose" aria-label="Close"><i class="ri-close-line"></i></button>
    <div class="mc-pill" id="mcPill"><i class="ri-gallery-fill"></i><span id="mcCount">1 / 1</span></div>
    <div class="mc-acts">
      <button type="button" class="mc-c" id="mcAdd" aria-label="Add more"><i class="ri-add-line"></i></button>
      <button type="button" class="mc-c" id="mcDel" aria-label="Remove this one"><i class="ri-delete-bin-6-line"></i></button>
    </div>
  </div>
  <div class="mc-stage"><div class="mc-track" id="mcTrack"></div></div>
  <div class="mc-thumbs" id="mcThumbs"></div>
  <div class="mc-bar">
    <div class="mc-cap"><textarea id="mcCap" rows="1" maxlength="4000" placeholder="Add a caption…" aria-label="Caption"></textarea></div>
    <button type="button" class="mc-send" id="mcSend" aria-label="Send"><i class="ri-send-plane-2-fill"></i></button>
  </div>
</div>
<script>
(function(){
  var ov = document.getElementById('mcOv'); if (!ov) return;
  var track = document.getElementById('mcTrack'), thumbs = document.getElementById('mcThumbs'), cap = document.getElementById('mcCap');
  var urls = [], idx = 0, isOpen = false, raf = 0;
  function hap(k){ try { if (window.dzHaptic && dzHaptic[k]) dzHaptic[k](); } catch(e){} }
  function size(b){ return b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; }
  function dur(s){ s = Math.round(s); return Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2); }

  function pauseAll(){ track.querySelectorAll('video').forEach(function(v){ try { v.pause(); } catch(e){} }); }
  function playActive(){
    track.querySelectorAll('.mc-slide').forEach(function(s, i){
      var v = s.querySelector('video'); if (!v) return;
      if (i === idx) { var p = v.play(); if (p && p.catch) p.catch(function(){}); } else { try { v.pause(); } catch(e){} }
    });
  }
  function mark(){
    var n = attachFiles.length;
    document.getElementById('mcCount').textContent = (idx + 1) + ' / ' + n;
    thumbs.querySelectorAll('.mc-th[data-i]').forEach(function(t){ t.classList.toggle('on', +t.dataset.i === idx); });
    var on = thumbs.querySelector('.mc-th.on'); if (on && on.scrollIntoView) { try { on.scrollIntoView({inline:'center', block:'nearest'}); } catch(e){} }
    document.getElementById('mcAdd').disabled = n >= MAX_FILES;
  }
  function render(){
    urls.forEach(function(u){ URL.revokeObjectURL(u); }); urls = [];
    track.innerHTML = ''; thumbs.innerHTML = '';
    var n = attachFiles.length;
    attachFiles.forEach(function(f, i){
      var u = URL.createObjectURL(f); urls.push(u);
      var vid = f.type.indexOf('video/') === 0;
      var s = document.createElement('div'); s.className = 'mc-slide';
      s.innerHTML = (vid ? '' : '<div class="mc-bg" style="background-image:url(\'' + u + '\')"></div>')
        + (vid ? '<video src="' + u + '#t=0.1" muted loop playsinline preload="auto"></video>' : '<img src="' + u + '" alt="">')
        + '<span class="mc-tag">' + (vid ? '<i class="ri-movie-2-fill"></i><b class="mc-d" style="font-weight:500"></b>' : '<i class="ri-image-fill"></i>') + '<span>' + size(f.size) + '</span></span>'
        + (vid ? '<div class="mc-vc"><button type="button" class="mc-pp" aria-label="Play or pause"><i class="ri-pause-fill"></i></button><button type="button" class="mc-mu" aria-label="Sound"><i class="ri-volume-mute-fill"></i></button></div>' : '');
      track.appendChild(s);
      if (vid) {
        var v = s.querySelector('video'), pp = s.querySelector('.mc-pp'), mu = s.querySelector('.mc-mu');
        v.addEventListener('loadedmetadata', function(){ var d = s.querySelector('.mc-d'); if (d && isFinite(v.duration)) d.textContent = dur(v.duration) + ' ·'; });
        function ppUi(){ pp.classList.toggle('paused', v.paused); pp.firstChild.className = v.paused ? 'ri-play-fill' : 'ri-pause-fill'; }
        v.addEventListener('play', ppUi); v.addEventListener('pause', ppUi);
        function toggle(){ if (v.paused) { var p = v.play(); if (p && p.catch) p.catch(function(){}); } else v.pause(); hap('light'); }
        pp.onclick = toggle; v.onclick = toggle;
        mu.onclick = function(){ v.muted = !v.muted; mu.firstChild.className = v.muted ? 'ri-volume-mute-fill' : 'ri-volume-up-fill'; hap('light'); };
      }
      if (n > 1) {
        var t = document.createElement('button'); t.type = 'button'; t.className = 'mc-th'; t.dataset.i = i; t.setAttribute('aria-label', 'Item ' + (i + 1));
        t.innerHTML = vid ? '<video src="' + u + '#t=0.1" muted preload="metadata"></video><i class="ri-play-circle-fill"></i>' : '<img src="' + u + '" alt="">';
        t.onclick = function(){ goTo(i, true); hap('selection'); };
        thumbs.appendChild(t);
      }
    });
    if (n > 1 && n < MAX_FILES) {
      var a = document.createElement('button'); a.type = 'button'; a.className = 'mc-th add'; a.innerHTML = '<i class="ri-add-line" style="position:static"></i>'; a.setAttribute('aria-label', 'Add more');
      a.onclick = function(){ fileInput.click(); }; thumbs.appendChild(a);
    }
    thumbs.classList.toggle('on', n > 1);
    document.getElementById('mcPill').style.display = n ? '' : 'none';
    idx = Math.max(0, Math.min(idx, n - 1));
    mark();
    var sl = track.children[idx]; if (sl) track.scrollLeft = sl.offsetLeft - track.offsetLeft;
    playActive();
  }
  function goTo(i, smooth){
    var sl = track.children[i]; if (!sl) return;
    idx = i;
    track.scrollTo({ left: sl.offsetLeft - track.offsetLeft, behavior: smooth ? 'smooth' : 'auto' });
    mark(); playActive();
  }
  track.addEventListener('scroll', function(){
    if (raf) return;
    raf = requestAnimationFrame(function(){
      raf = 0;
      var w = track.clientWidth + 8, i = Math.round(track.scrollLeft / w);
      i = Math.max(0, Math.min(attachFiles.length - 1, i));
      if (i !== idx) { idx = i; mark(); playActive(); hap('selection'); }
    });
  }, { passive: true });

  function open(start){
    if (!attachFiles.length) return;
    idx = typeof start === 'number' ? start : 0;
    if (document.activeElement && document.activeElement.blur) document.activeElement.blur();
    cap.value = (typeof msgInput !== 'undefined' && msgInput) ? msgInput.value : '';
    ov.classList.add('on'); isOpen = true;
    grow();
    render();
    hap('light');
  }
  function close(clear){
    pauseAll(); ov.classList.remove('on'); isOpen = false;
    urls.forEach(function(u){ URL.revokeObjectURL(u); }); urls = []; track.innerHTML = ''; thumbs.innerHTML = '';
  }
  function grow(){ cap.style.height = 'auto'; cap.style.height = Math.min(cap.scrollHeight, 104) + 'px'; }
  cap.addEventListener('input', function(){
    grow();
    if (typeof msgInput !== 'undefined' && msgInput) { msgInput.value = cap.value; onComposerInput(); }
  });
  document.getElementById('mcClose').onclick = function(){ hap('light'); close(); };
  document.getElementById('mcAdd').onclick = function(){ hap('light'); fileInput.click(); };
  document.getElementById('mcDel').onclick = function(){
    hap('medium');
    attachFiles.splice(idx, 1); renderAttach(); onComposerInput();   /* renderAttach() refreshes or closes this screen */
  };
  document.getElementById('mcSend').onclick = function(){
    hap('success');
    if (typeof msgInput !== 'undefined' && msgInput) msgInput.value = cap.value;
    sendNow();
    close();
  };
  document.addEventListener('keydown', function(e){ if (isOpen && e.key === 'Escape') { e.stopPropagation(); close(); } }, true);

  /* the on-screen keyboard shrinks the visible area: keep the caption bar above it */
  if (window.visualViewport) {
    var vv = window.visualViewport, fit = function(){ ov.style.setProperty('--mc-h', vv.height + 'px'); ov.style.top = vv.offsetTop + 'px'; };
    vv.addEventListener('resize', fit); vv.addEventListener('scroll', fit); fit();
  }

  window.mcOpen = open;
  window.mcRefresh = function(){ if (!isOpen) return; if (!attachFiles.length) close(); else render(); };
})();
</script>

<script>
/* me (name + picture) for the Zeems modal: my own repost shows in the social-proof strip the moment I tap, and leaves when I undo */
window.dzMe = <?php
    $__me = ['id'=>(int)$current_user, 'username'=>'', 'profile_pic'=>'uploads/mee.jpeg'];
    try{ $__mq = $conn->prepare('SELECT username, profile_pic FROM users WHERE id = ?'); $__mq->bind_param('i', $__me['id']); $__mq->execute(); $__mr = $__mq->get_result()->fetch_assoc();
         if($__mr){ $__me['username'] = (string)$__mr['username']; if(!empty($__mr['profile_pic'])) $__me['profile_pic'] = $__mr['profile_pic']; } }catch(Throwable $e){}
    echo json_encode($__me, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
?>;
</script>
<?php include __DIR__ . '/zeems_modal.php'; ?>
<?php include __DIR__ . '/shared_modals.php'; /* post menu (⋯), Save to Collection and Report — the same ones home and search use */ ?>
<script>
/* the "⋯" button in the Zeems modal opens the shared post menu (Save, Report, Not interested…) — same as home and search —
   instead of the small built-in sheet that is used when a post is opened by id */
(function(){
    var orig = window.ccmMenuTap;
    window.ccmMenuTap = function(postId){
        if(typeof window.openIndexSheet === 'function' && window.kebabData && window.kebabData[postId]){ window.openIndexSheet(postId); return; }
        if(typeof orig === 'function') return orig.apply(this, arguments);
    };
})();
</script>
<script>
/* the menu, Save and Report sheets open on top of the Zeems modal (the share sheet keeps the very top) */
(function(){
    var MENU = '2147483637', BACK = '2147483636';
    var HIT  = /(index[-_]?sheet|index[-_]?menu|index[-_]?overlay|index[-_]?backdrop|post[-_]?menu|post[-_]?sheet|kebab[-_]?(menu|sheet)|options[-_]?(sheet|menu)|report|collection|save[-_]?(modal|sheet|to))/i;
    var SCRIM = /(overlay|backdrop|scrim|mask|dim)/i;
    function tag(el){ return ((el.id || '') + ' ' + (typeof el.className === 'string' ? el.className : '')); }
    function lift(){
        Array.prototype.forEach.call(document.body.children, function(el){
            if(el.nodeType !== 1 || el.tagName === 'SCRIPT' || el.tagName === 'STYLE') return;
            var t = tag(el);
            if(/share/i.test(t) || !HIT.test(t)) return;
            var cs = getComputedStyle(el);
            if(cs.position !== 'fixed' && cs.position !== 'absolute') return;
            el.style.setProperty('z-index', (SCRIM.test(t) && !/sheet|menu|modal|panel/i.test(t.replace(SCRIM, ''))) ? BACK : MENU, 'important');
        });
    }
    function wrap(name){
        var orig = window[name];
        if(typeof orig !== 'function' || orig._mnTop) return;
        var w = function(){ var r = orig.apply(this, arguments); lift(); setTimeout(lift, 60); setTimeout(lift, 300); return r; };
        w._mnTop = true; window[name] = w;
    }
    function wrapAll(){ ['openIndexSheet','openReportModal','openSaveModal','openCollectionsModal','openSaveToCollection'].forEach(wrap); }
    wrapAll(); window.addEventListener('load', wrapAll);
    new MutationObserver(function(muts){
        for(var i = 0; i < muts.length; i++){
            var added = muts[i].addedNodes;
            for(var j = 0; j < added.length; j++){
                var n = added[j];
                if(n.nodeType === 1 && HIT.test(tag(n))){ lift(); setTimeout(lift, 60); wrapAll(); return; }
            }
        }
    }).observe(document.body, {childList:true, subtree:false});
})();
</script>
</body></html>