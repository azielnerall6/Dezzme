<?php
/* group_highlight_api.php : viewer API for Pod Highlights (members side)
   Needs tables: group_highlight_comments (with parent_id), group_highlight_likes,
                 group_highlight_comment_likes, group_highlight_comment_dislikes */
session_start();
include 'config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function hv_out($a){ echo json_encode($a, JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function hv_fail($m){ hv_out(['success' => false, 'msg' => $m]); }
function hv_txt($s, $max){ $s = trim(strip_tags(str_replace(["\r\n", "\r"], "\n", (string)$s))); return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max); }

$uid = (int)($_SESSION['user_id'] ?? 0);
if (!$uid) hv_fail('Please log in again.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') hv_fail('Bad request.');
if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) hv_fail('Session expired. Refresh the page.');
$gid = (int)($_GET['id'] ?? 0);
if (!$gid) hv_fail('Pod not found.');

/* ── pod + who is asking ── */
$st = $conn->prepare("SELECT created_by, privacy, is_paused FROM `groups` WHERE id=?");
$st->bind_param("i", $gid); $st->execute();
$G = $st->get_result()->fetch_assoc(); $st->close();
if (!$G) hv_fail('Pod not found.');

$owner_id = (int)$G['created_by'];
$is_owner = ($uid === $owner_id);
$admins = [];
$r = $conn->query("SELECT user_id FROM group_admins WHERE group_id=$gid");
while ($x = $r->fetch_assoc()) $admins[(int)$x['user_id']] = true;
$is_admin = isset($admins[$uid]);
$is_mod   = $is_owner || $is_admin;

$st = $conn->prepare("SELECT 1 FROM group_members WHERE group_id=? AND user_id=? LIMIT 1");
$st->bind_param("ii", $gid, $uid); $st->execute();
$is_member = $st->get_result()->num_rows > 0 || $is_mod; $st->close();

$st = $conn->prepare("SELECT 1 FROM group_bans WHERE group_id=? AND banned_id=? LIMIT 1");
$st->bind_param("ii", $gid, $uid); $st->execute();
$is_banned = $st->get_result()->num_rows > 0; $st->close();

if ($is_banned && !$is_mod) hv_fail('You cannot view this pod.');
if (($G['privacy'] ?? 'public') === 'private' && !$is_member) hv_fail('Join this pod to view highlights.');
$paused = !empty($G['is_paused']);
$can_act = $is_member && (!$paused || $is_mod);
$act_msg = !$is_member ? 'Join this pod to take part' : (($paused && !$is_mod) ? 'This pod is paused. Check back later' : '');
$perm = [
    'mod' => $is_mod, 'comment' => $can_act, 'vote' => $can_act, 'like' => $can_act,
    'comment_msg' => $can_act ? '' : ($is_member ? $act_msg : 'Join this pod to comment'),
    'vote_msg'    => $can_act ? '' : ($is_member ? $act_msg : 'Join this pod to vote'),
    'like_msg'    => $can_act ? '' : ($is_member ? $act_msg : 'Join this pod to like'),
];

/* ── helpers ── */
/* one like per MEDIA of a highlight (media 1 and 3 can be liked while 2 is not). Creates itself the first time. */
function hv_mlike_table($conn){
    static $done = false; if ($done) return; $done = true;
    $conn->query("CREATE TABLE IF NOT EXISTS group_highlight_media_likes (media_id INT UNSIGNED NOT NULL, user_id INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (media_id, user_id), KEY k_ghml_user (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function hv_mlikes($conn, $mid, $uid) {
    hv_mlike_table($conn);
    $st = $conn->prepare("SELECT COUNT(*) c, SUM(user_id=?) m FROM group_highlight_media_likes WHERE media_id=?");
    $st->bind_param("ii", $uid, $mid); $st->execute();
    $r = $st->get_result()->fetch_assoc(); $st->close();
    return ['n' => (int)($r['c'] ?? 0), 'liked' => ((int)($r['m'] ?? 0)) > 0];
}
function hv_user($conn, $id) {
    static $c = [];
    $id = (int)$id;
    if (isset($c[$id])) return $c[$id];
    $st = $conn->prepare("SELECT id, username, profile_pic FROM users WHERE id=? LIMIT 1");
    $st->bind_param("i", $id); $st->execute();
    $u = $st->get_result()->fetch_assoc(); $st->close();
    return $c[$id] = [
        'id' => $id, 'name' => $u['username'] ?? 'Deleted user',
        'pic' => !empty($u['profile_pic']) ? $u['profile_pic'] : 'mee.jpg',
    ];
}
function hv_role($id) { global $owner_id, $admins; return ((int)$id === $owner_id) ? 'owner' : (isset($admins[(int)$id]) ? 'admin' : 'member'); }

function hv_resolve($conn, $text) {
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
    try {
        $st = $conn->prepare("SELECT id, title FROM `groups` WHERE CHAR_LENGTH(title) >= 2 AND LOCATE(LOWER(title), LOWER(?)) > 0 ORDER BY CHAR_LENGTH(title) DESC LIMIT 20");
        $st->bind_param("s", $text); $st->execute();
        $res = $st->get_result();
        while ($g = $res->fetch_assoc()) $groups[] = ['id' => (int)$g['id'], 'name' => $g['title']];
        $st->close();
    } catch (Throwable $e) {}
    return ['users' => (object)$users, 'groups' => $groups];
}

function hv_poll($conn, $mid, $uid, $mod) {
    $st = $conn->prepare("SELECT id, question, multi, results_mode, closes_at FROM group_highlight_polls WHERE media_id=? LIMIT 1");
    $st->bind_param("i", $mid); $st->execute();
    $p = $st->get_result()->fetch_assoc(); $st->close();
    if (!$p) return null;
    $pid = (int)$p['id'];

    $st = $conn->prepare("SELECT o.id, o.label, (SELECT COUNT(*) FROM group_highlight_poll_votes v WHERE v.option_id=o.id) AS votes
                          FROM group_highlight_poll_options o WHERE o.poll_id=? ORDER BY o.sort_order ASC, o.id ASC");
    $st->bind_param("i", $pid); $st->execute();
    $res = $st->get_result(); $opts = [];
    while ($o = $res->fetch_assoc()) $opts[] = ['id' => (int)$o['id'], 'label' => $o['label'], 'votes' => (int)$o['votes']];
    $st->close();

    $st = $conn->prepare("SELECT option_id FROM group_highlight_poll_votes WHERE poll_id=? AND user_id=?");
    $st->bind_param("ii", $pid, $uid); $st->execute();
    $res = $st->get_result(); $mine = [];
    while ($v = $res->fetch_assoc()) $mine[] = (int)$v['option_id'];
    $st->close();

    $st = $conn->prepare("SELECT COUNT(DISTINCT user_id) AS c FROM group_highlight_poll_votes WHERE poll_id=?");
    $st->bind_param("i", $pid); $st->execute();
    $total = (int)($st->get_result()->fetch_assoc()['c'] ?? 0); $st->close();

    $closed = !empty($p['closes_at']) && strtotime($p['closes_at']) <= time();
    $mode = $p['results_mode'];
    $show = $mod || $closed || $mode === 'always' || ($mode === 'after_vote' && count($mine) > 0);
    if (!$show) foreach ($opts as &$o) $o['votes'] = null;
    unset($o);
    return [
        'id' => $pid, 'q' => $p['question'], 'multi' => (int)$p['multi'], 'results' => $mode,
        'closed' => $closed, 'closes' => !empty($p['closes_at']) ? date('j M, g:i A', strtotime($p['closes_at'])) : null,
        'options' => $opts, 'mine' => $mine, 'total' => $show ? $total : null, 'show' => $show,
    ];
}

/* one comment (top-level or reply) in the shape the modal reads.
   likes / liked / disliked / ol (liked by the highlight's creator) / replies (count) / rfirst come from the list query */
function hv_item($conn, $row, $uid, $mod) {
    $u = hv_user($conn, $row['user_id']);
    $rp = (!empty($row['rfirst'])) ? hv_user($conn, (int)$row['rfirst'])['pic'] : null;
    return [
        'id' => (int)$row['id'], 'uid' => (int)$row['user_id'], 'name' => $u['name'], 'pic' => $u['pic'],
        'role' => hv_role($row['user_id']), 'text' => $row['body'], 'time' => date('c', strtotime($row['created_at'])),
        'mine' => ((int)$row['user_id'] === $uid), 'canDel' => ((int)$row['user_id'] === $uid) || $mod,
        'parent' => !empty($row['parent_id']) ? (int)$row['parent_id'] : null,
        'likes' => (int)($row['likes'] ?? 0), 'liked' => !empty($row['liked']), 'disliked' => !empty($row['disliked']),
        'ol' => !empty($row['ol']), 'replies' => (int)($row['replies'] ?? 0), 'rpic' => $rp,
    ];
}
/* total = comments + replies */
function hv_count($conn, $hid) {
    $st = $conn->prepare("SELECT COUNT(*) c FROM group_highlight_comments WHERE highlight_id=?");
    $st->bind_param("i", $hid); $st->execute();
    $n = (int)($st->get_result()->fetch_assoc()['c'] ?? 0); $st->close();
    return $n;
}
function hv_likes($conn, $hid, $uid) {
    $st = $conn->prepare("SELECT COUNT(*) c, SUM(user_id=?) m FROM group_highlight_likes WHERE highlight_id=?");
    $st->bind_param("ii", $uid, $hid); $st->execute();
    $r = $st->get_result()->fetch_assoc(); $st->close();
    return ['n' => (int)($r['c'] ?? 0), 'liked' => ((int)($r['m'] ?? 0)) > 0];
}
function hv_clikes($conn, $cid) {
    $st = $conn->prepare("SELECT COUNT(*) c FROM group_highlight_comment_likes WHERE comment_id=?");
    $st->bind_param("i", $cid); $st->execute();
    $n = (int)($st->get_result()->fetch_assoc()['c'] ?? 0); $st->close();
    return $n;
}
function hv_highlight($conn, $hid, $gid) {
    $st = $conn->prepare("SELECT * FROM group_highlights WHERE id=? AND group_id=? LIMIT 1");
    $st->bind_param("ii", $hid, $gid); $st->execute();
    $r = $st->get_result()->fetch_assoc(); $st->close();
    return $r ?: null;
}
function hv_comment($conn, $cid, $gid) {
    $st = $conn->prepare("SELECT id, user_id, highlight_id, parent_id FROM group_highlight_comments WHERE id=? AND group_id=? LIMIT 1");
    $st->bind_param("ii", $cid, $gid); $st->execute();
    $r = $st->get_result()->fetch_assoc(); $st->close();
    return $r ?: null;
}

try {
    $act = (string)($_POST['action'] ?? '');
    $hid = (int)($_POST['highlight_id'] ?? 0);

    /* ───── get one highlight ───── */
    if ($act === 'get') {
        $H = $hid ? hv_highlight($conn, $hid, $gid) : null;
        if (!$H) hv_fail('This highlight is no longer available.');
        $st = $conn->prepare("SELECT id, file_path, media_type, caption, audio_path, audio_title, mute_video FROM group_highlight_media WHERE highlight_id=? ORDER BY id ASC");
        $st->bind_param("i", $hid); $st->execute();
        $res = $st->get_result(); $media = [];
        while ($m = $res->fetch_assoc()) {
            $cap = (string)($m['caption'] ?? '');
            $row = [
                'id' => (int)$m['id'], 'path' => $m['file_path'], 'type' => $m['media_type'], 'caption' => $cap,
                'audio' => $m['audio_path'], 'audio_title' => $m['audio_title'], 'mute' => (int)$m['mute_video'],
                'map' => ($cap !== '') ? hv_resolve($conn, $cap) : null, 'poll' => null,
            ];
            if ($m['media_type'] === 'poll') $row['poll'] = hv_poll($conn, (int)$m['id'], $uid, $is_mod);
            $ml = hv_mlikes($conn, (int)$m['id'], $uid); $row['likes'] = $ml['n']; $row['liked'] = $ml['liked'];
            $media[] = $row;
        }
        $st->close();
        if (!$media) hv_fail('This highlight is empty.');
        $by = hv_user($conn, $H['created_by']);
        hv_out(['success' => true, 'h' => [
            'id' => (int)$H['id'], 'title' => $H['title'], 'created' => date('c', strtotime($H['created_at'])),
            'by' => ['id' => $by['id'], 'name' => $by['name'], 'pic' => $by['pic'], 'role' => hv_role($H['created_by'])],
            'song' => $H['song_path'], 'song_title' => $H['song_title'], 'mode' => ($H['song_mode'] ?? 'whole'),
            'media' => $media, 'comments' => hv_count($conn, $hid),
            'perm' => $perm,
            'me' => ['pic' => hv_user($conn, $uid)['pic']],
        ]]);
    }

    /* ───── vote ───── */
    if ($act === 'vote') {
        if (!$perm['vote']) hv_fail($perm['vote_msg']);
        $pid = (int)($_POST['poll_id'] ?? 0); $oid = (int)($_POST['option_id'] ?? 0);
        $st = $conn->prepare("SELECT id, media_id, multi, closes_at FROM group_highlight_polls WHERE id=? AND group_id=? LIMIT 1");
        $st->bind_param("ii", $pid, $gid); $st->execute();
        $P = $st->get_result()->fetch_assoc(); $st->close();
        if (!$P) hv_fail('Poll not found.');
        if (!empty($P['closes_at']) && strtotime($P['closes_at']) <= time()) hv_fail('This poll has ended.');
        $st = $conn->prepare("SELECT 1 FROM group_highlight_poll_options WHERE id=? AND poll_id=? LIMIT 1");
        $st->bind_param("ii", $oid, $pid); $st->execute();
        if ($st->get_result()->num_rows === 0) hv_fail('That option no longer exists.');
        $st->close();

        $st = $conn->prepare("SELECT 1 FROM group_highlight_poll_votes WHERE poll_id=? AND option_id=? AND user_id=? LIMIT 1");
        $st->bind_param("iii", $pid, $oid, $uid); $st->execute();
        $had = $st->get_result()->num_rows > 0; $st->close();

        if ((int)$P['multi'] === 1) {            // pick several: tap again to take a vote back
            if ($had) $st = $conn->prepare("DELETE FROM group_highlight_poll_votes WHERE poll_id=? AND option_id=? AND user_id=?");
            else      $st = $conn->prepare("INSERT IGNORE INTO group_highlight_poll_votes (poll_id, option_id, user_id) VALUES (?,?,?)");
            $st->bind_param("iii", $pid, $oid, $uid); $st->execute(); $st->close();
        } else {                                 // pick one: your vote moves to the new option
            if (!$had) {
                $st = $conn->prepare("DELETE FROM group_highlight_poll_votes WHERE poll_id=? AND user_id=?");
                $st->bind_param("ii", $pid, $uid); $st->execute(); $st->close();
                $st = $conn->prepare("INSERT IGNORE INTO group_highlight_poll_votes (poll_id, option_id, user_id) VALUES (?,?,?)");
                $st->bind_param("iii", $pid, $oid, $uid); $st->execute(); $st->close();
            }
        }
        hv_out(['success' => true, 'poll' => hv_poll($conn, (int)$P['media_id'], $uid, $is_mod)]);
    }

    /* ───── like / unlike ONE media of a highlight (toggle). The count that comes back is that media's own count ───── */
    if ($act === 'like') {
        if (!$perm['like']) hv_fail($perm['like_msg']);
        if (!$hid || !hv_highlight($conn, $hid, $gid)) hv_fail('Highlight not found.');
        $mid = (int)($_POST['media_id'] ?? 0);
        hv_mlike_table($conn);
        $st = $conn->prepare("SELECT 1 FROM group_highlight_media WHERE id=? AND highlight_id=? LIMIT 1");
        $st->bind_param("ii", $mid, $hid); $st->execute();
        $okm = $st->get_result()->num_rows > 0; $st->close();
        if (!$mid || !$okm) hv_fail('Media not found.');
        $st = $conn->prepare("SELECT 1 FROM group_highlight_media_likes WHERE media_id=? AND user_id=? LIMIT 1");
        $st->bind_param("ii", $mid, $uid); $st->execute();
        $had = $st->get_result()->num_rows > 0; $st->close();
        if ($had) $st = $conn->prepare("DELETE FROM group_highlight_media_likes WHERE media_id=? AND user_id=?");
        else      $st = $conn->prepare("INSERT IGNORE INTO group_highlight_media_likes (media_id, user_id) VALUES (?,?)");
        $st->bind_param("ii", $mid, $uid); $st->execute(); $st->close();
        $lk = hv_mlikes($conn, $mid, $uid);
        hv_out(['success' => true, 'liked' => $lk['liked'], 'likes' => $lk['n']]);
    }

    /* ───── comments: list.
       No parent_id  → top-level comments (sort = top | new | old, 20 per page).
       With parent_id → the direct replies to that comment, oldest first, 10 per page (works at any depth). ───── */
    if ($act === 'comments') {
        $H = $hid ? hv_highlight($conn, $hid, $gid) : null;
        if (!$H) hv_fail('Highlight not found.');
        $creator = (int)$H['created_by'];
        $sort = (string)($_POST['sort'] ?? 'top');
        if (!in_array($sort, ['top', 'new', 'old'], true)) $sort = 'top';
        $offset = max(0, (int)($_POST['offset'] ?? 0));
        $parent = (int)($_POST['parent_id'] ?? 0);
        $page = $parent ? 10 : 20;

        $bl = [];
        $r = $conn->query("SELECT blocked_id AS b FROM blocks WHERE blocker_id=$uid UNION SELECT blocker_id FROM blocks WHERE blocked_id=$uid");
        if ($r) while ($x = $r->fetch_assoc()) $bl[] = (int)$x['b'];
        $ex = $bl ? " AND c.user_id NOT IN (" . implode(',', $bl) . ")" : '';

        if ($parent) $order = "c.id ASC";
        elseif ($sort === 'new') $order = "c.id DESC";
        elseif ($sort === 'old') $order = "c.id ASC";
        else $order = "likes DESC, c.id DESC";
        $pw = $parent ? "c.parent_id=?" : "c.parent_id IS NULL";

        $sql = "SELECT c.id, c.user_id, c.body, c.created_at, c.parent_id,
                  (SELECT COUNT(*) FROM group_highlight_comment_likes l WHERE l.comment_id=c.id) AS likes,
                  (SELECT COUNT(*) FROM group_highlight_comment_likes l WHERE l.comment_id=c.id AND l.user_id=?) AS liked,
                  (SELECT COUNT(*) FROM group_highlight_comment_likes l WHERE l.comment_id=c.id AND l.user_id=?) AS ol,
                  (SELECT COUNT(*) FROM group_highlight_comment_dislikes d WHERE d.comment_id=c.id AND d.user_id=?) AS disliked,
                  (SELECT COUNT(*) FROM group_highlight_comments r WHERE r.parent_id=c.id) AS replies,
                  (SELECT r2.user_id FROM group_highlight_comments r2 WHERE r2.parent_id=c.id ORDER BY (r2.user_id=?) DESC, r2.id ASC LIMIT 1) AS rfirst
                FROM group_highlight_comments c
                WHERE c.highlight_id=? AND c.group_id=? AND $pw $ex
                ORDER BY $order LIMIT ? OFFSET ?";
        $params = [$uid, $creator, $uid, $creator, $hid, $gid];
        if ($parent) $params[] = $parent;
        $params[] = $page + 1; $params[] = $offset;
        $st = $conn->prepare($sql);
        $st->bind_param(str_repeat('i', count($params)), ...$params);
        $st->execute();
        $res = $st->get_result(); $rows = [];
        while ($x = $res->fetch_assoc()) $rows[] = $x;
        $st->close();
        $more = count($rows) > $page; $rows = array_slice($rows, 0, $page);
        $items = array_map(function ($x) use ($conn, $uid, $is_mod) { return hv_item($conn, $x, $uid, $is_mod); }, $rows);
        hv_out(['success' => true, 'items' => $items, 'more' => $more, 'total' => hv_count($conn, $hid)]);
    }

    /* ───── comments: add. parent_id is kept exactly as given, so a reply to a reply builds its own sub-thread ───── */
    if ($act === 'comment_add') {
        if (!$perm['comment']) hv_fail($perm['comment_msg']);
        if (!$hid || !hv_highlight($conn, $hid, $gid)) hv_fail('Highlight not found.');
        $body = hv_txt($_POST['text'] ?? '', 500);
        if ($body === '') hv_fail('Write something first.');

        $parent = null;
        $pid_in = (int)($_POST['parent_id'] ?? 0);
        if ($pid_in) {
            $st = $conn->prepare("SELECT id FROM group_highlight_comments WHERE id=? AND highlight_id=? AND group_id=? LIMIT 1");
            $st->bind_param("iii", $pid_in, $hid, $gid); $st->execute();
            $pr = $st->get_result()->fetch_assoc(); $st->close();
            if (!$pr) hv_fail('That comment is no longer there.');
            $parent = (int)$pr['id'];
        }

        $st = $conn->prepare("SELECT 1 FROM group_highlight_comments WHERE user_id=? AND created_at > DATE_SUB(NOW(), INTERVAL 2 SECOND) LIMIT 1");
        $st->bind_param("i", $uid); $st->execute();
        if ($st->get_result()->num_rows > 0) hv_fail('Slow down a little.');
        $st->close();

        if ($parent === null) {
            $st = $conn->prepare("INSERT INTO group_highlight_comments (highlight_id, group_id, user_id, body) VALUES (?,?,?,?)");
            $st->bind_param("iiis", $hid, $gid, $uid, $body);
        } else {
            $st = $conn->prepare("INSERT INTO group_highlight_comments (highlight_id, group_id, user_id, body, parent_id) VALUES (?,?,?,?,?)");
            $st->bind_param("iiisi", $hid, $gid, $uid, $body, $parent);
        }
        if (!$st->execute()) hv_fail('Could not post your comment.');
        $new = $conn->insert_id; $st->close();
        $item = hv_item($conn, ['id' => $new, 'user_id' => $uid, 'body' => $body, 'created_at' => date('Y-m-d H:i:s'), 'parent_id' => $parent], $uid, $is_mod);
        hv_out(['success' => true, 'item' => $item, 'total' => hv_count($conn, $hid)]);
    }

    /* ───── comment like (toggle; a like replaces a dislike) ───── */
    if ($act === 'comment_like') {
        if (!$perm['like']) hv_fail($perm['like_msg']);
        $cid = (int)($_POST['comment_id'] ?? 0);
        $c = hv_comment($conn, $cid, $gid);
        if (!$c) hv_fail('Comment not found.');
        $H = hv_highlight($conn, (int)$c['highlight_id'], $gid);
        $creator = $H ? (int)$H['created_by'] : 0;

        $st = $conn->prepare("SELECT 1 FROM group_highlight_comment_likes WHERE comment_id=? AND user_id=? LIMIT 1");
        $st->bind_param("ii", $cid, $uid); $st->execute();
        $had = $st->get_result()->num_rows > 0; $st->close();
        if ($had) {
            $st = $conn->prepare("DELETE FROM group_highlight_comment_likes WHERE comment_id=? AND user_id=?");
            $st->bind_param("ii", $cid, $uid); $st->execute(); $st->close();
            $liked = false;
        } else {
            $st = $conn->prepare("INSERT IGNORE INTO group_highlight_comment_likes (comment_id, user_id) VALUES (?,?)");
            $st->bind_param("ii", $cid, $uid); $st->execute(); $st->close();
            $st = $conn->prepare("DELETE FROM group_highlight_comment_dislikes WHERE comment_id=? AND user_id=?");
            $st->bind_param("ii", $cid, $uid); $st->execute(); $st->close();
            $liked = true;
        }
        hv_out(['success' => true, 'liked' => $liked, 'disliked' => false, 'likes' => hv_clikes($conn, $cid), 'ol' => ($uid === $creator) ? $liked : null]);
    }

    /* ───── comment dislike (toggle; a dislike replaces a like) ───── */
    if ($act === 'comment_dislike') {
        if (!$perm['like']) hv_fail($perm['like_msg']);
        $cid = (int)($_POST['comment_id'] ?? 0);
        $c = hv_comment($conn, $cid, $gid);
        if (!$c) hv_fail('Comment not found.');
        $H = hv_highlight($conn, (int)$c['highlight_id'], $gid);
        $creator = $H ? (int)$H['created_by'] : 0;

        $st = $conn->prepare("SELECT 1 FROM group_highlight_comment_dislikes WHERE comment_id=? AND user_id=? LIMIT 1");
        $st->bind_param("ii", $cid, $uid); $st->execute();
        $had = $st->get_result()->num_rows > 0; $st->close();
        $ol = null;
        if ($had) {
            $st = $conn->prepare("DELETE FROM group_highlight_comment_dislikes WHERE comment_id=? AND user_id=?");
            $st->bind_param("ii", $cid, $uid); $st->execute(); $st->close();
            $dis = false;
        } else {
            $st = $conn->prepare("INSERT IGNORE INTO group_highlight_comment_dislikes (comment_id, user_id) VALUES (?,?)");
            $st->bind_param("ii", $cid, $uid); $st->execute(); $st->close();
            $dis = true;
            $st = $conn->prepare("SELECT 1 FROM group_highlight_comment_likes WHERE comment_id=? AND user_id=? LIMIT 1");
            $st->bind_param("ii", $cid, $uid); $st->execute();
            $hadLike = $st->get_result()->num_rows > 0; $st->close();
            if ($hadLike) {
                $st = $conn->prepare("DELETE FROM group_highlight_comment_likes WHERE comment_id=? AND user_id=?");
                $st->bind_param("ii", $cid, $uid); $st->execute(); $st->close();
                if ($uid === $creator) $ol = false;
            }
        }
        hv_out(['success' => true, 'liked' => false, 'disliked' => $dis, 'likes' => hv_clikes($conn, $cid), 'ol' => $ol]);
    }

    /* ───── comments: delete (author, owner or admin). The whole sub-thread under it goes too ───── */
    if ($act === 'comment_del') {
        $cid = (int)($_POST['comment_id'] ?? 0);
        $c = hv_comment($conn, $cid, $gid);
        if (!$c) hv_fail('Comment not found.');
        if ((int)$c['user_id'] !== $uid && !$is_mod) hv_fail('You cannot delete this comment.');

        $ids = [$cid]; $frontier = [$cid]; $guard = 0;
        while ($frontier && $guard < 500) {
            $guard++;
            $in = implode(',', array_map('intval', $frontier));
            $rs = $conn->query("SELECT id FROM group_highlight_comments WHERE parent_id IN ($in)");
            $next = [];
            if ($rs) while ($x = $rs->fetch_row()) { $next[] = (int)$x[0]; $ids[] = (int)$x[0]; }
            $frontier = $next;
        }
        $in = implode(',', array_map('intval', $ids));
        $conn->query("DELETE FROM group_highlight_comment_likes WHERE comment_id IN ($in)");
        $conn->query("DELETE FROM group_highlight_comment_dislikes WHERE comment_id IN ($in)");
        $conn->query("DELETE FROM group_highlight_comments WHERE id IN ($in)");
        hv_out(['success' => true, 'removed' => count($ids), 'parent_id' => !empty($c['parent_id']) ? (int)$c['parent_id'] : null, 'total' => hv_count($conn, (int)$c['highlight_id'])]);
    }

    hv_fail('Unknown action.');
} catch (Throwable $e) {
    hv_fail('Something went wrong.');
}