<?php
/* ══════════════════════════════════════════════════════════════
   time_ping.php: records how long a logged-in person is active on Dezzme.
   Called every ~30 seconds by time_track.js. Counts only real activity:
   the seconds credited are the SMALLEST of what the page claims, the time
   since this account's last ping (so 5 open tabs do not count 5x) and 65.
   Same session, origin and CSRF rules as the settings page.
══════════════════════════════════════════════════════════════ */
$IS_HTTPS = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'secure' => $IS_HTTPS, 'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_start();
}
include 'config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

function tp_out($ok, $code = 200) { http_response_code($code); echo json_encode(['ok' => $ok]); exit; }

$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0 || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') tp_out(false, 403);

// same-origin + CSRF token (the one the Settings page uses: $_SESSION['st_csrf'])
$sfs = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
if ($sfs !== '' && !in_array($sfs, ['same-origin', 'none'], true)) tp_out(false, 403);
$myHost = (string)parse_url('//' . strtolower((string)($_SERVER['HTTP_HOST'] ?? '')), PHP_URL_HOST);
foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $h) {
    if (!empty($_SERVER[$h])) {
        $src = strtolower((string)parse_url((string)$_SERVER[$h], PHP_URL_HOST));
        if ($src === '' || $src !== $myHost) tp_out(false, 403);
        break;
    }
}
$tok  = (string)($_SESSION['st_csrf'] ?? '');
$sent = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? ''));
if ($tok === '' || $sent === '' || !hash_equals($tok, $sent)) tp_out(false, 403);

// how many seconds to credit
$claimed = max(0, min(65, (int)($_POST['seconds'] ?? 0)));
$now  = time();
$last = (int)($_SESSION['tm_last'] ?? 0);
$_SESSION['tm_last'] = $now;
$gap    = $last > 0 ? ($now - $last) : $claimed;
$credit = max(0, min($claimed, $gap, 65));
if ($credit <= 0) tp_out(true);

require_once __DIR__ . '/tz_helper.php';
$day = dz_local_date($conn, $uid);   // the person's own "today" (Settings > Time zone)

try {
    $conn->query("CREATE TABLE IF NOT EXISTS user_time_spent (
        user_id INT NOT NULL,
        day DATE NOT NULL,
        seconds INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (user_id, day)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $st = $conn->prepare("INSERT INTO user_time_spent (user_id, day, seconds) VALUES (?, ?, ?)
                          ON DUPLICATE KEY UPDATE seconds = LEAST(seconds + VALUES(seconds), 86400)");
    $st->bind_param('isi', $uid, $day, $credit);
    $st->execute();
    $st->close();
} catch (Throwable $e) { error_log('time_ping error: ' . $e->getMessage()); tp_out(false, 500); }

tp_out(true);