<?php
/* ccm_pref.php — saves small per-user Zeems preferences (so they survive refresh and other devices).
   Put it next to config.php.   POST  action=set_autoscroll  value=0|1   → {"status":"ok","value":0|1}
   It adds the users.auto_scroll column by itself if it is missing, but you can also run once:
       ALTER TABLE users ADD COLUMN auto_scroll TINYINT(1) NOT NULL DEFAULT 0;                          */
include 'config.php';
if (session_status() === PHP_SESSION_NONE) { @session_start(); }
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$uid = (int)($_SESSION['user_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $uid <= 0) { http_response_code(403); echo json_encode(['status' => 'error']); exit; }

/* same-site requests only */
$site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
if ($site !== '' && $site !== 'same-origin' && $site !== 'same-site' && $site !== 'none') { http_response_code(403); echo json_encode(['status' => 'error']); exit; }

if (($_POST['action'] ?? '') === 'set_autoscroll') {
    $v = ((int)($_POST['value'] ?? 0)) ? 1 : 0;
    try {
        $c = $conn->query("SHOW COLUMNS FROM users LIKE 'auto_scroll'");
        if ($c instanceof mysqli_result && $c->num_rows === 0) {
            $conn->query("ALTER TABLE users ADD COLUMN auto_scroll TINYINT(1) NOT NULL DEFAULT 0");
        }
        $st = $conn->prepare("UPDATE users SET auto_scroll = ? WHERE id = ? LIMIT 1");
        $st->bind_param('ii', $v, $uid);
        $st->execute();
        echo json_encode(['status' => 'ok', 'value' => $v]);
    } catch (Throwable $e) {
        http_response_code(500); echo json_encode(['status' => 'error']);
    }
    exit;
}
http_response_code(400); echo json_encode(['status' => 'error']);