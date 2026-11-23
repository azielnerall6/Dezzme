<?php
/* ═══════════════════════════════════════════════════════════════
   dz_collab.php — Post collaboration for Dezzme
   • The poster invites up to 14 other people. Each invite must be ACCEPTED before the post counts as theirs.
   • An accepted post appears on every collaborator's profile (use dz_collab_profile_clause() in the profile queries).
   • Zeems modal header: overlapping pics + "Poster and Sam" / "Poster and 3 others" → Collaborators sheet with Track buttons.
   • Invites show in the bell as "X invited you to collaborate on a post" with Accept / Decline until answered.
   Table meme_collaborators is created automatically.
   ═══════════════════════════════════════════════════════════════ */
if (defined('DZ_COLLAB_LOADED')) return;
define('DZ_COLLAB_LOADED', 1);
const DZ_COLLAB_MAX = 14;

function dz_collab_ensure($conn): void {
    static $done = false; if ($done) return; $done = true;
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS meme_collaborators (
            id INT AUTO_INCREMENT PRIMARY KEY,
            meme_id INT NOT NULL,
            user_id INT NOT NULL,
            invited_by INT NOT NULL,
            status ENUM('pending','accepted','declined') NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            responded_at DATETIME NULL DEFAULT NULL,
            UNIQUE KEY uq_meme_user (meme_id, user_id),
            KEY idx_user_status (user_id, status),
            KEY idx_meme_status (meme_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {}
}

/* SQL for a profile page: "posts by this person, or posts they collaborate on (accepted)". $m = memes alias, $uid = the profile owner. */
function dz_collab_profile_clause(string $m, int $uid): string {
    $u = (int)$uid;
    return "($m.uploaded_by = $u OR EXISTS (SELECT 1 FROM meme_collaborators dzc WHERE dzc.meme_id = $m.id AND dzc.user_id = $u AND dzc.status = 'accepted'))";
}

/* meme_id => ['owner'=>[…], 'list'=>[…accepted collaborators…]] — only for posts that have at least one. Used by Zeems header + Collaborators sheet. */
function dz_collab_payload($conn, array $meme_ids, int $viewer): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $meme_ids))));
    $out = [];
    if (!$ids) return $out;
    dz_collab_ensure($conn);
    try {
        $in = implode(',', $ids); $v = (int)$viewer;
        $r = $conn->query("SELECT mc.meme_id, u.id, u.username, u.nickname, u.profile_pic, COALESCE(u.is_verified,0) AS ver,
                                  EXISTS(SELECT 1 FROM followers f WHERE f.follower_id = $v AND f.following_id = u.id) AS fol
                           FROM meme_collaborators mc JOIN users u ON u.id = mc.user_id
                           WHERE mc.meme_id IN ($in) AND mc.status = 'accepted' AND u.deactivated_at IS NULL AND LOWER(COALESCE(u.user_rank,'')) NOT IN ('suspended','banned')
                           ORDER BY mc.responded_at ASC, mc.id ASC");
        if (!($r instanceof mysqli_result)) return $out;
        while ($x = $r->fetch_assoc()) {
            $out[(int)$x['meme_id']]['list'][] = ['id'=>(int)$x['id'], 'username'=>$x['username'], 'nickname'=>(string)($x['nickname'] ?? ''), 'pic'=>!empty($x['profile_pic']) ? $x['profile_pic'] : 'mee.jpg',
                                                 'isVerified'=>!empty($x['ver']), 'following'=>!empty($x['fol']), 'isMe'=>((int)$x['id'] === $v)];
        }
        if (!$out) return $out;
        $in2 = implode(',', array_keys($out));
        $r = $conn->query("SELECT m.id AS meme_id, u.id, u.username, u.nickname, u.profile_pic, COALESCE(u.is_verified,0) AS ver,
                                  EXISTS(SELECT 1 FROM followers f WHERE f.follower_id = $v AND f.following_id = u.id) AS fol
                           FROM memes m JOIN users u ON u.id = m.uploaded_by WHERE m.id IN ($in2)");
        if ($r instanceof mysqli_result) while ($x = $r->fetch_assoc()) {
            $out[(int)$x['meme_id']]['owner'] = ['id'=>(int)$x['id'], 'username'=>$x['username'], 'nickname'=>(string)($x['nickname'] ?? ''), 'pic'=>!empty($x['profile_pic']) ? $x['profile_pic'] : 'mee.jpg',
                                                 'isVerified'=>!empty($x['ver']), 'following'=>!empty($x['fol']), 'isMe'=>((int)$x['id'] === $v)];
        }
        foreach ($out as $k => $o) if (empty($o['owner'])) unset($out[$k]);
    } catch (Throwable $e) {}
    return $out;
}

function dz_collab_blocked($conn, int $a, int $b): bool {
    try {
        $r = $conn->query("SELECT 1 FROM blocks WHERE (blocker_id = $a AND blocked_id = $b) OR (blocker_id = $b AND blocked_id = $a) LIMIT 1");
        return ($r instanceof mysqli_result) && $r->num_rows > 0;
    } catch (Throwable $e) { return false; }
}

/* the poster invites people. Returns ['sent'=>n, 'skipped'=>[username=>reason], 'error'=>?] */
function dz_collab_invite($conn, int $owner, int $meme_id, array $user_ids): array {
    dz_collab_ensure($conn);
    $res = ['sent'=>0, 'skipped'=>[]];
    $st = $conn->prepare("SELECT uploaded_by FROM memes WHERE id = ?"); $st->bind_param("i", $meme_id); $st->execute();
    $m = $st->get_result()->fetch_assoc(); $st->close();
    if (!$m || (int)$m['uploaded_by'] !== $owner) return ['error'=>'Only the poster can invite collaborators.'] + $res;
    $r = $conn->query("SELECT COUNT(*) c FROM meme_collaborators WHERE meme_id = " . (int)$meme_id . " AND status IN ('pending','accepted')");
    $have = (int)(($r instanceof mysqli_result) ? ($r->fetch_assoc()['c'] ?? 0) : 0);
    foreach (array_unique(array_map('intval', $user_ids)) as $uid) {
        if ($uid <= 0 || $uid === $owner) continue;
        $u = $conn->query("SELECT id, username FROM users WHERE id = $uid AND deactivated_at IS NULL AND LOWER(COALESCE(user_rank,'')) NOT IN ('suspended','banned') LIMIT 1");
        $u = ($u instanceof mysqli_result) ? $u->fetch_assoc() : null;
        if (!$u) continue;
        $name = $u['username'];
        if ($have >= DZ_COLLAB_MAX) { $res['skipped'][$name] = 'A post can have up to ' . DZ_COLLAB_MAX . ' collaborators.'; continue; }
        if (dz_collab_blocked($conn, $owner, $uid)) { $res['skipped'][$name] = "Can't be invited."; continue; }
        /* respect Settings → Tags: "no one" / "people I track" */
        $pr = $conn->query("SELECT tag_who FROM user_privacy WHERE user_id = $uid LIMIT 1");
        $tw = ($pr instanceof mysqli_result) ? ($pr->fetch_assoc()['tag_who'] ?? 'everyone') : 'everyone';
        if ($tw === 'none') { $res['skipped'][$name] = "Doesn't accept collaborations."; continue; }
        if ($tw === 'tracked') {
            $f = $conn->query("SELECT 1 FROM followers WHERE follower_id = $uid AND following_id = $owner LIMIT 1");
            if (!($f instanceof mysqli_result) || !$f->num_rows) { $res['skipped'][$name] = 'Only accepts from people they track.'; continue; }
        }
        $ex = $conn->query("SELECT status FROM meme_collaborators WHERE meme_id = " . (int)$meme_id . " AND user_id = $uid");
        $ex = ($ex instanceof mysqli_result) ? $ex->fetch_assoc() : null;
        if ($ex && $ex['status'] !== 'declined') { $res['skipped'][$name] = $ex['status'] === 'accepted' ? 'Already a collaborator.' : 'Already invited.'; continue; }
        $ins = $conn->prepare("INSERT INTO meme_collaborators (meme_id, user_id, invited_by, status) VALUES (?,?,?, 'pending')
                               ON DUPLICATE KEY UPDATE status = 'pending', invited_by = VALUES(invited_by), created_at = NOW(), responded_at = NULL");
        $ins->bind_param("iii", $meme_id, $uid, $owner); $ins->execute(); $ins->close();
        $have++; $res['sent']++;
    }
    return $res;
}

function dz_collab_respond($conn, int $uid, int $meme_id, bool $accept): bool {
    dz_collab_ensure($conn);
    $s = $accept ? 'accepted' : 'declined';
    $st = $conn->prepare("UPDATE meme_collaborators SET status = ?, responded_at = NOW() WHERE meme_id = ? AND user_id = ? AND status = 'pending'");
    $st->bind_param("sii", $s, $meme_id, $uid); $st->execute(); $n = $st->affected_rows; $st->close();
    return $n > 0;
}

/* the poster removes anyone / a collaborator leaves the post themselves */
function dz_collab_remove($conn, int $actor, int $meme_id, int $user_id): bool {
    dz_collab_ensure($conn);
    $st = $conn->prepare("DELETE mc FROM meme_collaborators mc JOIN memes m ON m.id = mc.meme_id
                          WHERE mc.meme_id = ? AND mc.user_id = ? AND (m.uploaded_by = ? OR mc.user_id = ?)");
    $st->bind_param("iiii", $meme_id, $user_id, $actor, $actor); $st->execute(); $n = $st->affected_rows; $st->close();
    return $n > 0;
}

/* everything the owner's "Collaborators" manager shows: current people + their status */
function dz_collab_manage_list($conn, int $owner, int $meme_id): array {
    dz_collab_ensure($conn);
    $out = [];
    $r = $conn->query("SELECT mc.user_id AS id, mc.status, u.username, u.nickname, u.profile_pic FROM meme_collaborators mc JOIN users u ON u.id = mc.user_id
                       JOIN memes m ON m.id = mc.meme_id
                       WHERE mc.meme_id = " . (int)$meme_id . " AND m.uploaded_by = " . (int)$owner . " AND mc.status IN ('pending','accepted') ORDER BY mc.created_at");
    if ($r instanceof mysqli_result) while ($x = $r->fetch_assoc()) $out[] = ['id'=>(int)$x['id'], 'username'=>$x['username'], 'nickname'=>(string)($x['nickname'] ?? ''), 'pic'=>!empty($x['profile_pic']) ? $x['profile_pic'] : 'mee.jpg', 'status'=>$x['status']];
    return $out;
}

/* people the poster can pick: matches by username, never themselves, blocked or deactivated; Full Private hidden like in search */
function dz_collab_search($conn, int $uid, string $q): array {
    $q = trim($q); $out = [];
    if ($q === '') return $out;
    $like = '%' . $conn->real_escape_string(addcslashes($q, '%_')) . '%';
    $extra = function_exists('dz_fp_hide_sql') ? dz_fp_hide_sql('u.id', $uid) : '';
    $r = $conn->query("SELECT u.id, u.username, u.nickname, u.profile_pic, COALESCE(u.is_verified,0) AS ver FROM users u
                       WHERE u.username LIKE '$like' AND u.id <> " . (int)$uid . " AND u.deactivated_at IS NULL AND LOWER(COALESCE(u.user_rank,'')) NOT IN ('suspended','banned')
                         AND NOT EXISTS (SELECT 1 FROM blocks b WHERE (b.blocker_id = " . (int)$uid . " AND b.blocked_id = u.id) OR (b.blocker_id = u.id AND b.blocked_id = " . (int)$uid . "))
                         $extra
                       ORDER BY (u.username LIKE '" . $conn->real_escape_string(addcslashes($q, '%_')) . "%') DESC, u.username LIMIT 8");
    if ($r instanceof mysqli_result) while ($x = $r->fetch_assoc()) $out[] = ['id'=>(int)$x['id'], 'username'=>$x['username'], 'nickname'=>(string)($x['nickname'] ?? ''), 'pic'=>!empty($x['profile_pic']) ? $x['profile_pic'] : 'mee.jpg', 'isVerified'=>!empty($x['ver'])];
    return $out;
}

/* one call every page can make: answers the collaboration actions and exits; does nothing for any other request.
   actions: collab_search · collab_manage · collab_invite · collab_remove · collab_respond */
function dz_collab_handle_request($conn, int $uid): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $a = (string)($_POST['action'] ?? '');
    if (strncmp($a, 'collab_', 7) !== 0) return;
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    header('Content-Type: application/json');
    if ($uid <= 0) { echo json_encode(['status'=>'error','message'=>'not signed in']); exit; }
    $mid = (int)($_POST['meme_id'] ?? 0);
    switch ($a) {
        case 'collab_search':
            echo json_encode(['status'=>'ok', 'users'=>dz_collab_search($conn, $uid, (string)($_POST['q'] ?? ''))]); break;
        case 'collab_manage':
            echo json_encode(['status'=>'ok', 'list'=>dz_collab_manage_list($conn, $uid, $mid), 'max'=>DZ_COLLAB_MAX]); break;
        case 'collab_invite':
            $ids = array_filter(array_map('intval', explode(',', (string)($_POST['user_ids'] ?? ''))));
            $r = dz_collab_invite($conn, $uid, $mid, $ids);
            echo json_encode(isset($r['error']) ? ['status'=>'error','message'=>$r['error']] : ['status'=>'ok', 'sent'=>$r['sent'], 'skipped'=>$r['skipped']]); break;
        case 'collab_remove':
            $ok = dz_collab_remove($conn, $uid, $mid, (int)($_POST['user_id'] ?? 0));
            echo json_encode($ok ? ['status'=>'ok'] : ['status'=>'error','message'=>'Could not remove.']); break;
        case 'collab_respond':
            $ok = dz_collab_respond($conn, $uid, $mid, ($_POST['accept'] ?? '') === '1');
            echo json_encode($ok ? ['status'=>'ok'] : ['status'=>'error','message'=>'This invite is no longer open.']); break;
        default:
            echo json_encode(['status'=>'error','message'=>'unknown action']);
    }
    exit;
}