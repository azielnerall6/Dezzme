<?php
/* send_invite.php — invite a user to a Pod.
   Hardened: POST only, CSRF (same-site + token), prepared statements,
   inviter must be in the pod, target must exist / not be a member / not blocked,
   no duplicate or spammy invites. */

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
include 'config.php';

header('Content-Type: application/json; charset=utf-8');

function si_out($ok, $extra = [], $code = 200) {
    http_response_code($code);
    echo json_encode(array_merge(['ok' => (bool)$ok], $extra));
    exit();
}

/* POST only */
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') si_out(false, ['error' => 'Method not allowed'], 405);

/* CSRF: same-site check, then session token (header or form field) */
$host   = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
$source = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
if ($source !== '' && $source !== 'null') {
    $src_host = strtolower((string)parse_url($source, PHP_URL_HOST));
    if ($src_host !== '' && $host !== '' && $src_host !== $host) si_out(false, ['error' => 'Cross-site request blocked'], 403);
}
$sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$tok  = $_SESSION['csrf_token'] ?? '';
if (!is_string($sent) || $sent === '' || !is_string($tok) || $tok === '' || !hash_equals($tok, $sent)) {
    si_out(false, ['error' => 'Invalid or missing security token. Refresh and try again.'], 403);
}

$current_user = (int)($_SESSION['user_id'] ?? 0);
if (!$current_user) si_out(false, ['error' => 'Not logged in'], 401);

$target_id = (int)($_POST['user_id']  ?? 0);
$group_id  = (int)($_POST['group_id'] ?? 0);
if (!$target_id || !$group_id) si_out(false, ['error' => 'Bad request'], 400);
if ($target_id === $current_user) si_out(false, ['error' => 'You cannot invite yourself']);

/* helper: one-row prepared query */
function si_row($conn, $sql, $types, ...$args) {
    $st = $conn->prepare($sql);
    if (!$st) return null;
    $st->bind_param($types, ...$args);
    $st->execute();
    $r = $st->get_result();
    $row = $r ? $r->fetch_assoc() : null;
    $st->close();
    return $row ?: null;
}

try {
    $group = si_row($conn, "SELECT title, created_by FROM groups WHERE id=?", 'i', $group_id);
    $me    = si_row($conn, "SELECT username FROM users WHERE id=?", 'i', $current_user);
    $tgt   = si_row($conn, "SELECT id FROM users WHERE id=?", 'i', $target_id);
    if (!$group || !$me || !$tgt) si_out(false, ['error' => 'Not found']);

    /* inviter must belong to the pod (owner, admin or member) */
    $allowed = ((int)$group['created_by'] === $current_user)
        || si_row($conn, "SELECT 1 FROM group_members WHERE group_id=? AND user_id=?", 'ii', $group_id, $current_user)
        || si_row($conn, "SELECT 1 FROM group_admins WHERE group_id=? AND user_id=?", 'ii', $group_id, $current_user);
    if (!$allowed) si_out(false, ['error' => 'Only pod members can invite'], 403);

    /* target already in the pod? */
    if ((int)$group['created_by'] === $target_id
        || si_row($conn, "SELECT 1 FROM group_members WHERE group_id=? AND user_id=?", 'ii', $group_id, $target_id)
        || si_row($conn, "SELECT 1 FROM group_admins WHERE group_id=? AND user_id=?", 'ii', $group_id, $target_id)) {
        si_out(false, ['error' => 'Already in this pod', 'already' => true]);
    }

    /* blocked either way */
    if (si_row($conn, "SELECT 1 FROM blocks WHERE (blocker_id=? AND blocked_id=?) OR (blocker_id=? AND blocked_id=?)",
               'iiii', $current_user, $target_id, $target_id, $current_user)) {
        si_out(false, ['error' => 'Cannot invite this user']);
    }

    /* message is stored as plain text; escaping happens when it is displayed */
    $message = $me['username'] . ' invited you to join the Pod: ' . $group['title'];
    $type    = 'group_invite';

    /* no duplicate invite to the same pod from the same person */
    $dupe = si_row($conn,
        "SELECT 1 FROM notifications WHERE user_id=? AND actor_id=? AND type=? AND message=? LIMIT 1",
        'iiss', $target_id, $current_user, $type, $message);
    if ($dupe) si_out(true, ['duplicate' => true]);

    /* simple flood guard: max 30 invites per hour per user */
    $cnt = si_row($conn,
        "SELECT COUNT(*) c FROM notifications WHERE actor_id=? AND type=? AND created_at > (NOW() - INTERVAL 1 HOUR)",
        'is', $current_user, $type);
    if ($cnt && (int)$cnt['c'] >= 30) si_out(false, ['error' => 'Too many invites. Try again later.'], 429);

    $stmt = $conn->prepare("
        INSERT INTO notifications (user_id, actor_id, type, message, is_read, created_at)
        VALUES (?, ?, ?, ?, 0, NOW())
    ");
    $stmt->bind_param('iiss', $target_id, $current_user, $type, $message);
    $stmt->execute();
    $stmt->close();

    si_out(true);
} catch (Throwable $e) {
    si_out(false, ['error' => 'Something went wrong'], 500);
}