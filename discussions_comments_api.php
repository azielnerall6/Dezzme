<?php
/* ══════════════════════════════════════════════════════════════════
   discussions_comments_api.php
   The comments sheet (same design as the long-form one) loads and saves
   comments over AJAX. Put this in discussions.php with:

       require __DIR__ . '/discussions_comments_api.php';

   right AFTER $is_mod / $csrf_token / the CSRF check exist and BEFORE the
   "── SORT / SEARCH / THREAD LIST ──" section.

   ALSO: in your current file the "report_thread_to_admins" block sits
   after the exit() inside the ajax_react block, so it can never run.
   DELETE that old block — a working copy is included below.
   ══════════════════════════════════════════════════════════════════ */

if (!isset($conn, $current_user, $group_id, $csrf_token)) { http_response_code(403); exit; }

define('DS_CMT_PAGE', 10);

function ds_json($a) { header('Content-Type: application/json'); echo json_encode($a); exit; }

function ds_time_ago($t) {
    $s = max(0, time() - strtotime($t));
    if ($s < 60)     return 'Just now';
    if ($s < 3600)   return floor($s / 60) . 'm ago';
    if ($s < 86400)  return floor($s / 3600) . 'h ago';
    if ($s < 604800) return floor($s / 86400) . 'd ago';
    return date('M j, Y', strtotime($t));
}

function ds_api_schema($conn) {
    if (!empty($_SESSION['ds_api_v1'])) return;
    foreach (['pod_reply_likes' => 'uq_rl', 'pod_reply_dislikes' => 'uq_rd'] as $tb => $uq) {
        $conn->query("CREATE TABLE IF NOT EXISTS `$tb` (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            reply_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `$uq` (reply_id, user_id), KEY idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    $_SESSION['ds_api_v1'] = 1;
}

/* is_verified column is optional — selected only if the users table has it */
function ds_ver_sql($conn) {
    static $v = null;
    if ($v === null) {
        try { $c = $conn->query("SHOW COLUMNS FROM `users` LIKE 'is_verified'"); $v = ($c && $c->num_rows > 0) ? ", COALESCE(u.is_verified,0) AS is_verified" : ", 0 AS is_verified"; }
        catch (Throwable $e) { $v = ", 0 AS is_verified"; }
    }
    return $v;
}

function ds_thread($conn, $did, $gid) {
    $s = $conn->prepare("SELECT id, user_id, is_locked, comments_disabled FROM pod_discussions WHERE id=? AND group_id=?");
    $s->bind_param("ii", $did, $gid); $s->execute();
    $r = $s->get_result()->fetch_assoc(); $s->close();
    return $r ?: null;
}

/* $parent = null → top-level comments; int → direct children of that comment */
function ds_fetch($conn, $did, $parent, $sort, $offset, $limit, $viewer, $owner) {
    if ($parent === null) {
        $where = "(r.parent_id IS NULL OR r.parent_id = 0)";
        if ($sort === 'new')      $order = "r.created_at DESC, r.id DESC";
        elseif ($sort === 'old')  $order = "r.created_at ASC, r.id ASC";
        else                      $order = "likes_count DESC, r.created_at DESC";
    } else {
        $where = "r.parent_id = ?";
        $order = "r.created_at ASC, r.id ASC";
    }
    $sql = "SELECT r.id, r.user_id, r.content AS body, r.created_at, u.username, u.profile_pic" . ds_ver_sql($conn) . ",
        (SELECT COUNT(*) FROM pod_reply_likes l WHERE l.reply_id=r.id) AS likes_count,
        (SELECT COUNT(*) FROM pod_discussion_replies c WHERE c.parent_id=r.id) AS replies_count,
        (SELECT COUNT(*) FROM pod_reply_likes l WHERE l.reply_id=r.id AND l.user_id=?) AS liked,
        (SELECT COUNT(*) FROM pod_reply_likes l WHERE l.reply_id=r.id AND l.user_id=?) AS owner_liked,
        (SELECT COUNT(*) FROM pod_reply_dislikes d WHERE d.reply_id=r.id AND d.user_id=?) AS disliked,
        (SELECT u2.profile_pic FROM pod_discussion_replies c2 JOIN users u2 ON u2.id=c2.user_id
            WHERE c2.parent_id=r.id ORDER BY (c2.user_id=?) DESC, c2.id ASC LIMIT 1) AS reply_avatar
        FROM pod_discussion_replies r JOIN users u ON u.id=r.user_id
        WHERE r.discussion_id=? AND $where ORDER BY $order LIMIT ? OFFSET ?";
    $lim = $limit + 1;
    $params = [$viewer, $owner, $viewer, $owner, $did];
    $types  = "iiiii";
    if ($parent !== null) { $params[] = $parent; $types .= "i"; }
    $params[] = $lim; $params[] = $offset; $types .= "ii";
    $st = $conn->prepare($sql);
    $st->bind_param($types, ...$params);
    $st->execute();
    $res = $st->get_result(); $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $st->close();
    $more = count($rows) > $limit;
    return [array_slice($rows, 0, $limit), $more];
}

function ds_row_out($r, $owner_id, $viewer_id) {
    $uid = (int)$r['user_id'];
    return [
        'id'           => (int)$r['id'],
        'user_id'      => $uid,
        'username'     => (string)$r['username'],
        'is_verified'  => !empty($r['is_verified']),
        'avatar'       => !empty($r['profile_pic']) ? $r['profile_pic'] : 'uploads/me.jpeg',
        'text'         => (string)$r['body'],
        'ago'          => ds_time_ago($r['created_at']),
        'likes'        => (int)$r['likes_count'],
        'liked'        => (int)$r['liked'] > 0,
        'disliked'     => (int)$r['disliked'] > 0,
        'owner_liked'  => (int)$r['owner_liked'] > 0,
        'replies'      => (int)$r['replies_count'],
        'reply_avatar' => (int)$r['replies_count'] > 0 ? (!empty($r['reply_avatar']) ? $r['reply_avatar'] : 'uploads/me.jpeg') : null,
        'is_owner'     => $uid === (int)$owner_id,
        'can_delete'   => ($uid === (int)$viewer_id) || ((int)$owner_id === (int)$viewer_id) || !empty($GLOBALS['is_mod']),
        'pinned'       => false,
    ];
}

/* ── GET: top-level comments ── */
if (isset($_GET['ds_comments'])) {
    ds_api_schema($conn);
    $did = (int)($_GET['post'] ?? 0);
    $sort = in_array(($_GET['sort'] ?? 'top'), ['top','new','old'], true) ? $_GET['sort'] : 'top';
    $off = max(0, (int)($_GET['offset'] ?? 0));
    $t = ds_thread($conn, $did, $group_id);
    if (!$t) ds_json(['success' => false, 'error' => 'That post could not be found.']);
    [$rows, $more] = ds_fetch($conn, $did, null, $sort, $off, DS_CMT_PAGE, $current_user, (int)$t['user_id']);
    ds_json(['success' => true, 'has_more' => $more,
             'comments' => array_map(function ($r) use ($t, $current_user) { return ds_row_out($r, $t['user_id'], $current_user); }, $rows)]);
}

/* ── GET: replies to one comment (top-level OR another reply) ── */
if (isset($_GET['ds_replies'])) {
    ds_api_schema($conn);
    $did = (int)($_GET['post'] ?? 0);
    $cid = (int)($_GET['comment_id'] ?? 0);
    $off = max(0, (int)($_GET['offset'] ?? 0));
    $t = ds_thread($conn, $did, $group_id);
    if (!$t) ds_json(['success' => false, 'error' => 'That post could not be found.']);
    [$rows, $more] = ds_fetch($conn, $did, $cid, 'old', $off, DS_CMT_PAGE, $current_user, (int)$t['user_id']);
    ds_json(['success' => true, 'has_more' => $more,
             'replies' => array_map(function ($r) use ($t, $current_user) { return ds_row_out($r, $t['user_id'], $current_user); }, $rows)]);
}

/* ── POST actions ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (
        isset($_POST['ds_comment_add']) || isset($_POST['ds_comment_like']) ||
        isset($_POST['ds_comment_dislike']) || isset($_POST['ds_comment_delete']) ||
        isset($_POST['report_thread_to_admins']))) {

    /* (csrf was already verified for every POST by the top of discussions.php) */
    ds_api_schema($conn);

    /* add a comment / reply — parent_id is kept exactly (true nested threading) */
    if (isset($_POST['ds_comment_add'])) {
        $did  = (int)($_POST['post_id'] ?? 0);
        $text = trim((string)($_POST['text'] ?? ''));
        $par  = (int)($_POST['parent_id'] ?? 0);
        $t = ds_thread($conn, $did, $group_id);
        if (!$t) ds_json(['success' => false, 'error' => 'That post could not be found.']);
        if (!$is_member) ds_json(['success' => false, 'error' => 'Join this pod to comment.']);
        if (!empty($t['comments_disabled'])) ds_json(['success' => false, 'error' => 'Comments are turned off for this post.']);
        if (!empty($t['is_locked'])) ds_json(['success' => false, 'error' => 'This thread is locked.']);
        if ($text === '') ds_json(['success' => false, 'error' => 'Write something first.']);
        if (mb_strlen($text) > 1000) ds_json(['success' => false, 'error' => 'That comment is too long.']);

        $pid = null;
        if ($par > 0) {
            $s = $conn->prepare("SELECT id FROM pod_discussion_replies WHERE id=? AND discussion_id=?");
            $s->bind_param("ii", $par, $did); $s->execute();
            $pr = $s->get_result()->fetch_assoc(); $s->close();
            if ($pr) $pid = (int)$pr['id'];
        }
        $empty = ''; $none = 'none';
        $s = $conn->prepare("INSERT INTO pod_discussion_replies (discussion_id, user_id, parent_id, content, media_file, media_type) VALUES (?, ?, ?, ?, ?, ?)");
        $s->bind_param("iiisss", $did, $current_user, $pid, $text, $empty, $none);
        if (!$s->execute()) ds_json(['success' => false, 'error' => 'Could not post your comment.']);
        $new = $s->insert_id; $s->close();
        $conn->query("UPDATE pod_discussions SET reply_count=reply_count+1, last_activity=NOW() WHERE id=" . (int)$did);

        $s = $conn->prepare("SELECT u.username, u.profile_pic" . ds_ver_sql($conn) . " FROM users u WHERE u.id=?");
        $s->bind_param("i", $current_user); $s->execute();
        $me = $s->get_result()->fetch_assoc(); $s->close();
        ds_json(['success' => true, 'parent_id' => $pid, 'comment' => [
            'id' => (int)$new, 'user_id' => (int)$current_user, 'username' => (string)$me['username'], 'is_verified' => !empty($me['is_verified']),
            'avatar' => !empty($me['profile_pic']) ? $me['profile_pic'] : 'uploads/me.jpeg',
            'text' => $text, 'ago' => 'Just now', 'likes' => 0, 'liked' => false, 'disliked' => false,
            'owner_liked' => false, 'replies' => 0, 'reply_avatar' => null, 'pinned' => false,
            'is_owner' => ((int)$t['user_id'] === (int)$current_user), 'can_delete' => true,
        ]]);
    }

    /* like / dislike a comment (each cancels the other; tap again to undo) */
    if (isset($_POST['ds_comment_like']) || isset($_POST['ds_comment_dislike'])) {
        $isLike = isset($_POST['ds_comment_like']);
        $cid = (int)($_POST['comment_id'] ?? 0);
        $s = $conn->prepare("SELECT r.id, d.user_id AS owner_id FROM pod_discussion_replies r JOIN pod_discussions d ON d.id=r.discussion_id WHERE r.id=? AND d.group_id=?");
        $s->bind_param("ii", $cid, $group_id); $s->execute();
        $c = $s->get_result()->fetch_assoc(); $s->close();
        if (!$c) ds_json(['success' => false, 'error' => 'That comment could not be found.']);
        $viewerIsOwner = ((int)$c['owner_id'] === (int)$current_user);

        $mine  = $isLike ? 'pod_reply_likes'    : 'pod_reply_dislikes';
        $other = $isLike ? 'pod_reply_dislikes' : 'pod_reply_likes';
        $s = $conn->prepare("SELECT 1 FROM `$mine` WHERE reply_id=? AND user_id=?");
        $s->bind_param("ii", $cid, $current_user); $s->execute();
        $had = (bool)$s->get_result()->fetch_row(); $s->close();

        if ($had) {
            $s = $conn->prepare("DELETE FROM `$mine` WHERE reply_id=? AND user_id=?");
            $s->bind_param("ii", $cid, $current_user); $s->execute(); $s->close();
        } else {
            $s = $conn->prepare("INSERT IGNORE INTO `$mine` (reply_id, user_id) VALUES (?, ?)");
            $s->bind_param("ii", $cid, $current_user); $s->execute(); $s->close();
            $s = $conn->prepare("DELETE FROM `$other` WHERE reply_id=? AND user_id=?");
            $s->bind_param("ii", $cid, $current_user); $s->execute(); $s->close();
        }
        $s = $conn->prepare("SELECT COUNT(*) FROM pod_reply_likes WHERE reply_id=?");
        $s->bind_param("i", $cid); $s->execute(); $s->bind_result($n); $s->fetch(); $s->close();
        $s = $conn->prepare("SELECT COUNT(*) FROM pod_reply_likes WHERE reply_id=? AND user_id=?");
        $s->bind_param("ii", $cid, $current_user); $s->execute(); $s->bind_result($lk); $s->fetch(); $s->close();
        $s = $conn->prepare("SELECT COUNT(*) FROM pod_reply_dislikes WHERE reply_id=? AND user_id=?");
        $s->bind_param("ii", $cid, $current_user); $s->execute(); $s->bind_result($dk); $s->fetch(); $s->close();
        ds_json(['success' => true, 'likes' => (int)$n, 'liked' => $lk > 0, 'disliked' => $dk > 0,
                 'owner_liked' => $viewerIsOwner ? ($lk > 0) : null]);
    }

    /* delete a comment + its whole sub-tree (author, thread owner or pod mod) */
    if (isset($_POST['ds_comment_delete'])) {
        $cid = (int)($_POST['comment_id'] ?? 0);
        $s = $conn->prepare("SELECT r.id, r.user_id, r.parent_id, r.discussion_id, d.user_id AS owner_id FROM pod_discussion_replies r JOIN pod_discussions d ON d.id=r.discussion_id WHERE r.id=? AND d.group_id=?");
        $s->bind_param("ii", $cid, $group_id); $s->execute();
        $c = $s->get_result()->fetch_assoc(); $s->close();
        if (!$c) ds_json(['success' => false, 'error' => 'That comment could not be found.']);
        $ok = ((int)$c['user_id'] === (int)$current_user) || ((int)$c['owner_id'] === (int)$current_user) || !empty($is_mod);
        if (!$ok) ds_json(['success' => false, 'error' => 'You cannot delete this comment.']);

        $ids = [$cid]; $frontier = [$cid]; $guard = 0;
        while ($frontier && $guard++ < 500) {
            $in = implode(',', array_map('intval', $frontier));
            $res = $conn->query("SELECT id FROM pod_discussion_replies WHERE parent_id IN ($in)");
            $frontier = [];
            while ($r = $res->fetch_row()) { $frontier[] = (int)$r[0]; $ids[] = (int)$r[0]; }
        }
        $in = implode(',', array_map('intval', $ids));
        $conn->query("DELETE FROM pod_reply_likes WHERE reply_id IN ($in)");
        $conn->query("DELETE FROM pod_reply_dislikes WHERE reply_id IN ($in)");
        $conn->query("DELETE FROM pod_discussion_replies WHERE id IN ($in)");
        $conn->query("UPDATE pod_discussions SET reply_count=GREATEST(reply_count-" . count($ids) . ",0) WHERE id=" . (int)$c['discussion_id']);
        ds_json(['success' => true, 'removed' => count($ids), 'parent_id' => !empty($c['parent_id']) ? (int)$c['parent_id'] : null]);
    }

    /* report a post to the pod's owner & admins (fixed copy of your old handler) */
    if (isset($_POST['report_thread_to_admins'])) {
        if (!$is_member) ds_json(['success' => false, 'msg' => 'You must be a member of this pod to report a post.']);
        $did    = (int)($_POST['discussion_id'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? ''));
        $desc   = trim((string)($_POST['description'] ?? ''));
        if (!$did || !$reason) ds_json(['success' => false, 'msg' => 'Missing report details.']);
        $t = ds_thread($conn, $did, $group_id);
        if (!$t) ds_json(['success' => false, 'msg' => 'This post no longer exists in this pod.']);
        $target = (int)$t['user_id'];

        $s = $conn->prepare("SELECT 1 FROM pod_discussion_reports WHERE discussion_id=? AND reported_by=? AND status='pending'");
        $s->bind_param("ii", $did, $current_user); $s->execute();
        $already = $s->get_result()->num_rows > 0; $s->close();
        if ($already) ds_json(['success' => true, 'msg' => 'already_reported']);

        $s = $conn->prepare("INSERT INTO pod_discussion_reports (group_id, discussion_id, reported_by, reported_user_id, reason, description) VALUES (?, ?, ?, ?, ?, ?)");
        $s->bind_param("iiiiss", $group_id, $did, $current_user, $target, $reason, $desc);
        $okr = $s->execute(); $s->close();
        ds_json(['success' => (bool)$okr]);
    }
}