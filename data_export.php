<?php
/* ══════════════════════════════════════════════════════════════
   data_export.php  –  "Download your data"
   POST action=status   -> current export state (JSON)
   POST action=request  -> builds a ZIP of the user's own data (JSON)
   GET  ?download=ID    -> streams a finished ZIP (owner only, 7 days)

   The ZIP holds: index.html, README.txt, data/*.json and media/… (the user's own
   uploaded files). Passwords, tokens and secrets are never included. Private
   messages are limited to the ones the user SENT.
   When it is ready the bell shows "Your data download is ready"
   (profile_notifications.php reads the data_exports table).
══════════════════════════════════════════════════════════════ */
$IS_HTTPS = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $IS_HTTPS, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
include 'config.php';

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

const DX_DIR        = 'data_exports';
const DX_KEEP_DAYS  = 7;          // how long a finished ZIP stays downloadable
const DX_COOLDOWN_H = 24;         // one new export per user per 24 hours
const DX_MAX_ROWS   = 100000;     // per table
const DX_MAX_FILE   = 200 * 1024 * 1024;    // one media file
const DX_MAX_MEDIA  = 800 * 1024 * 1024;    // all media together

function dx_json(array $a, int $code = 200): void { http_response_code($code); header('Content-Type: application/json'); echo json_encode($a); exit; }

$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') dx_json(['success' => false, 'msg' => 'Please log in again.'], 401);
    header('Location: login.php'); exit;
}

/* table */
$conn->query("CREATE TABLE IF NOT EXISTS data_exports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'pending',
    file_name VARCHAR(120) NULL,
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    ready_at DATETIME NULL,
    expires_at DATETIME NULL,
    KEY k_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function dx_dir(): string {
    $d = __DIR__ . '/' . DX_DIR;
    if (!is_dir($d)) @mkdir($d, 0755, true);
    if (!is_file($d . '/.htaccess')) @file_put_contents($d . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    if (!is_file($d . '/index.html')) @file_put_contents($d . '/index.html', '');
    return $d;
}

/* remove expired ZIPs */
function dx_cleanup($conn): void {
    try {
        $r = $conn->query("SELECT id, file_name FROM data_exports WHERE status='ready' AND expires_at < NOW()");
        while ($r && ($row = $r->fetch_assoc())) {
            if ($row['file_name']) @unlink(dx_dir() . '/' . basename($row['file_name']));
            $conn->query("UPDATE data_exports SET status='expired', file_name=NULL WHERE id=" . (int)$row['id']);
        }
    } catch (Throwable $e) {}
}

function dx_latest($conn, int $uid): ?array {
    $st = $conn->prepare("SELECT id, status, file_size, note, created_at, ready_at, expires_at FROM data_exports WHERE user_id=? ORDER BY id DESC LIMIT 1");
    $st->bind_param('i', $uid); $st->execute();
    $res = $st->get_result(); $row = $res ? $res->fetch_assoc() : null; $st->close();
    return $row ?: null;
}
function dx_public(?array $r): ?array {
    if (!$r) return null;
    return [
        'id' => (int)$r['id'], 'status' => $r['status'], 'size' => (int)$r['file_size'],
        'requested' => $r['created_at'], 'ready' => $r['ready_at'], 'expires' => $r['expires_at'],
        'note' => $r['note'],
    ];
}

/* ───────── download ───────── */
if (isset($_GET['download'])) {
    $id = (int)$_GET['download'];
    $st = $conn->prepare("SELECT file_name, created_at FROM data_exports WHERE id=? AND user_id=? AND status='ready' AND expires_at > NOW() LIMIT 1");
    $st->bind_param('ii', $id, $uid); $st->execute();
    $res = $st->get_result(); $row = $res ? $res->fetch_assoc() : null; $st->close();
    $path = $row ? dx_dir() . '/' . basename((string)$row['file_name']) : '';
    if (!$row || !is_file($path)) { http_response_code(404); header('Content-Type: text/plain'); echo 'This download is not available any more. Request a new one from Settings.'; exit; }
    $name = 'dezzme-data-' . date('Y-m-d', strtotime($row['created_at'])) . '.zip';
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

/* ───────── POST: CSRF (same token as settings.php) ───────── */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
$sfs = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
$tok = $_SESSION['st_csrf'] ?? '';
$sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (($sfs !== '' && !in_array($sfs, ['same-origin', 'none'], true)) || !is_string($tok) || $tok === '' || !is_string($sent) || !hash_equals($tok, $sent)) {
    dx_json(['success' => false, 'msg' => 'This page expired. Please reload it and try again.'], 403);
}
session_write_close();   // do not block other tabs while the ZIP is built

dx_cleanup($conn);
$action = (string)($_POST['action'] ?? 'status');

/* a "pending" row older than 20 minutes means the build died */
$conn->query("UPDATE data_exports SET status='failed', note='The export did not finish.' WHERE status='pending' AND created_at < (NOW() - INTERVAL 20 MINUTE)");

if ($action === 'status') {
    dx_json(['success' => true, 'export' => dx_public(dx_latest($conn, $uid))]);
}
if ($action !== 'request') dx_json(['success' => false, 'msg' => 'Unknown action.'], 400);

/* ───────── request: rules ───────── */
if (!class_exists('ZipArchive')) dx_json(['success' => false, 'msg' => 'Zip support is not enabled on the server (enable extension=zip in php.ini).']);

$last = dx_latest($conn, $uid);
if ($last && $last['status'] === 'pending') dx_json(['success' => true, 'export' => dx_public($last)]);
if ($last && in_array($last['status'], ['ready', 'expired'], true)) {
    $st = $conn->prepare("SELECT TIMESTAMPDIFF(MINUTE, created_at, NOW()) FROM data_exports WHERE id=?");
    $id0 = (int)$last['id']; $st->bind_param('i', $id0); $st->execute(); $st->bind_result($mins); $st->fetch(); $st->close();
    if ((int)$mins < DX_COOLDOWN_H * 60) {
        $left = DX_COOLDOWN_H * 60 - (int)$mins;
        dx_json(['success' => false, 'msg' => 'You can request a new export in ' . ($left >= 60 ? ceil($left / 60) . ' hours' : $left . ' minutes') . '.', 'export' => dx_public($last)]);
    }
}

@set_time_limit(0);
@ignore_user_abort(true);
@ini_set('memory_limit', '512M');

$st = $conn->prepare("INSERT INTO data_exports (user_id, status, created_at) VALUES (?, 'pending', NOW())");
$st->bind_param('i', $uid); $st->execute(); $expId = (int)$conn->insert_id; $st->close();

$tmp = '';
try {
    /* what to export: [file, title, table, WHERE with ? = user id] */
    $plan = [
        ['profile',            'Profile',                 'users',                       'id = ?'],
        ['about',              'About',                   'user_about',                  'user_id = ?'],
        ['settings',           'Settings',                'user_settings',               'user_id = ?'],
        ['privacy',            'Privacy settings',        'user_privacy',                'user_id = ?'],
        ['username_history',   'Username history',        'username_history',            'user_id = ?'],
        ['posts',              'Posts',                   'memes',                       'uploaded_by = ?'],
        ['videos',             'Long-form videos',        'longform_posts',              'user_id = ?'],
        ['video_comments',     'Video comments',          'longform_comments',           'user_id = ?'],
        ['highlights',         'Highlights',              'highlights',                  'user_id = ?'],
        ['highlight_media',    'Highlight media',         'highlight_media',             'user_id = ?'],
        ['pod_discussions',    'Pod discussions',         'pod_discussions',             'user_id = ?'],
        ['pod_replies',        'Pod replies',             'pod_discussion_replies',      'user_id = ?'],
        ['pod_highlight_comments', 'Pod highlight comments', 'group_highlight_comments', 'user_id = ?'],
        ['event_comments',     'Event comments',          'group_event_comments',        'user_id = ?'],
        ['collections',        'Collections',             'collections',                 'user_id = ?'],
        ['likes',              'Likes',                   'likes',                       'user_id = ?'],
        ['reposts',            'Reposts',                 'reposts',                     'user_id = ?'],
        ['saved',              'Saved posts',             'favorites',                   'user_id = ?'],
        ['word_filters',       'Word filters',            'user_word_filters',           'user_id = ?'],
        ['tracking',           'People you Track',        'followers',                   'follower_id = ?'],
        ['trackers',           'People who Track you',    'followers',                   'following_id = ?'],
        ['pods_joined',        'Pods you joined',         'group_members',               'user_id = ?'],
        ['messages_sent',      'Messages you sent',       'messages',                    'sender_id = ?'],
    ];
    $SECRET = '/pass|pwd|token|secret|hash|salt|otp|2fa|totp|api_key|session|remember|reset|csrf/i';
    $MEDIA  = '/\.(jpe?g|png|gif|webp|avif|bmp|svg|mp4|webm|mov|m4v|mkv|mp3|m4a|wav|ogg|aac|pdf|docx?|xlsx?|pptx?|zip|txt)$/i';

    $tmp = sys_get_temp_dir() . '/dzx_' . $expId . '_' . bin2hex(random_bytes(4));
    @mkdir($tmp, 0700, true);

    $zipName = 'u' . $uid . '_' . bin2hex(random_bytes(16)) . '.zip';
    $zipPath = dx_dir() . '/' . $zipName;
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new Exception('Could not create the archive.');

    $base = realpath(__DIR__);
    $media = []; $mediaBytes = 0; $mediaSkipped = 0;
    $summary = [];

    foreach ($plan as [$file, $title, $table, $where]) {
        /* table must exist */
        $chk = $conn->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $chk->bind_param('s', $table); $chk->execute(); $chk->bind_result($exists); $chk->fetch(); $chk->close();
        if (!$exists) continue;
        try {
            $q = $conn->prepare("SELECT * FROM `$table` WHERE $where LIMIT " . DX_MAX_ROWS);
            if (!$q) continue;
            $q->bind_param('i', $uid); $q->execute();
            $res = $q->get_result();
            if (!$res) { $q->close(); continue; }
            $jf = $tmp . '/' . $file . '.json';
            $fh = fopen($jf, 'w'); fwrite($fh, "[\n");
            $n = 0;
            while ($row = $res->fetch_assoc()) {
                foreach ($row as $col => $val) {
                    if (preg_match($SECRET, (string)$col)) { unset($row[$col]); continue; }
                    if (!is_string($val) || $val === '') continue;
                    if (strlen($val) < 400 && preg_match($MEDIA, $val) && !preg_match('#^[a-z][a-z0-9+.-]*:#i', $val)) {
                        $rel = ltrim(str_replace('\\', '/', $val), '/');
                        if (strpos($rel, '..') !== false || isset($media[$rel])) continue;
                        $abs = realpath(__DIR__ . '/' . $rel);
                        if ($abs && is_file($abs) && strpos($abs, $base) === 0 && !preg_match('/\.(php|phtml|phar|ini|env|htaccess)$/i', $abs)
                            && strpos($abs, DX_DIR) === false) {
                            $sz = filesize($abs);
                            if ($sz > DX_MAX_FILE || $mediaBytes + $sz > DX_MAX_MEDIA) { $mediaSkipped++; continue; }
                            $media[$rel] = $abs; $mediaBytes += $sz;
                        }
                    }
                }
                fwrite($fh, ($n ? ",\n" : '') . json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE));
                $n++;
            }
            fwrite($fh, "\n]\n"); fclose($fh);
            $q->close();
            if ($n === 0 && !in_array($file, ['profile'], true)) { @unlink($jf); $summary[] = [$file, $title, 0]; continue; }
            $zip->addFile($jf, 'data/' . $file . '.json');
            $summary[] = [$file, $title, $n];
        } catch (Throwable $e) {
            error_log('data_export ' . $table . ': ' . $e->getMessage());
        }
    }

    /* media */
    foreach ($media as $rel => $abs) $zip->addFile($abs, 'media/' . $rel);

    /* index.html + README */
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $rows = '';
    foreach ($summary as [$f, $t, $n]) {
        $rows .= '<tr><td>' . ($n ? '<a href="data/' . $e($f) . '.json">' . $e($t) . '</a>' : $e($t)) . '</td><td>' . (int)$n . '</td></tr>';
    }
    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Your Dezzme data</title>'
          . '<style>body{font:16px/1.5 system-ui,sans-serif;max-width:640px;margin:40px auto;padding:0 16px;color:#10122a}h1{font-size:1.5rem}table{width:100%;border-collapse:collapse;margin:16px 0}td,th{padding:8px 10px;border-bottom:1px solid #e3e5f0;text-align:left}th:last-child,td:last-child{text-align:right}a{color:#2a5bd7}small{color:#666}</style></head><body>'
          . '<h1>Your Dezzme data</h1><p>Exported on ' . $e(gmdate('j M Y, H:i')) . ' UTC.</p>'
          . '<table><tr><th>What</th><th>Items</th></tr>' . $rows . '</table>'
          . '<p>Your uploaded files are in the <b>media</b> folder. Each file in <b>data</b> opens in any text editor.</p>'
          . '<p><small>' . count($media) . ' media file(s) included' . ($mediaSkipped ? ', ' . $mediaSkipped . ' too large to include' : '') . '. Passwords and security tokens are never part of an export. Private messages include only the ones you sent.</small></p></body></html>';
    $zip->addFromString('index.html', $html);
    $zip->addFromString('README.txt', "Your Dezzme data\nExported: " . gmdate('c') . "\n\nOpen index.html to see what is inside.\ndata/  - your information as JSON files\nmedia/ - the pictures, videos and audio you uploaded\n\nPasswords and security tokens are never included. Private messages include only the ones you sent.\n");
    if (!$zip->close()) throw new Exception('Could not finish the archive.');

    /* temp files can go now */
    foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
    @rmdir($tmp);

    $size = (int)filesize($zipPath);
    $note = $mediaSkipped ? $mediaSkipped . ' large file(s) were left out.' : null;
    $st = $conn->prepare("UPDATE data_exports SET status='ready', file_name=?, file_size=?, note=?, ready_at=NOW(), expires_at=(NOW() + INTERVAL " . DX_KEEP_DAYS . " DAY) WHERE id=?");
    $st->bind_param('sisi', $zipName, $size, $note, $expId); $st->execute(); $st->close();

    dx_json(['success' => true, 'export' => dx_public(dx_latest($conn, $uid))]);
} catch (Throwable $e) {
    error_log('data_export failed: ' . $e->getMessage());
    if ($tmp !== '' && is_dir($tmp)) { foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f); @rmdir($tmp); }
    if (!empty($zipPath) && is_file($zipPath)) @unlink($zipPath);
    $st = $conn->prepare("UPDATE data_exports SET status='failed', note=? WHERE id=?");
    $m = 'Something went wrong. Please try again.'; $st->bind_param('si', $m, $expId); $st->execute(); $st->close();
    dx_json(['success' => false, 'msg' => $m, 'export' => dx_public(dx_latest($conn, $uid))]);
}