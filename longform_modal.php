<?php
/* ══════════════════════════════════════════════════════════════════
   longform_modal.php — the LONG FORM MODAL for profile_longform.php
   Player + details + description sheet + comments sheet + settings sheet,
   all in one file:
     1) the PHP that feeds the modal (JSON endpoints)
     2) lfm_render() which prints the modal's CSS, HTML and JavaScript

   HOW IT CONNECTS (two lines in profile_longform.php):
     A) right after the line   $lf_max_video = (int)min($limits);
            require __DIR__ . '/longform_modal.php';
     B) right before the closing body tag, after the page's own script block
            (inside php tags):   lfm_render();
   ══════════════════════════════════════════════════════════════════ */

if (!isset($conn, $current_user, $profile_id, $owner, $csrf_token)) {
    http_response_code(403);
    exit('Include this file from profile_longform.php');
}

/* ══════════════════════════════════════════════════════════════════
   SETTINGS
   ══════════════════════════════════════════════════════════════════ */
define('LFM_LIST_SIZE', 8);   /* "more from this user" videos loaded per scroll */
define('LFM_CMT_PAGE',  10);  /* comments / replies loaded per page */

/* Track (follow) table — now points at the SAME table your profile page uses:
       followers (follower_id = the person who tracks, following_id = the person tracked)
   That is what makes Track / Untrack match the profile page exactly.
   (Before, the modal guessed the table and could pick the old `follows` table,
   which is why the button showed the wrong state.) */
define('LFM_TRACK_TABLE',  'followers');
define('LFM_TRACK_BY_COL', 'follower_id');
define('LFM_TRACK_TO_COL', 'following_id');

/* ══════════════════════════════════════════════════════════════════
   SCHEMA — makes sure every table/column the modal needs exists.
   Safe to run any number of times (once per login session).
   ══════════════════════════════════════════════════════════════════ */
function lfm_table_exists($conn, $table) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param("s", $table);
    $stmt->execute();
    $stmt->bind_result($n);
    $stmt->fetch();
    $stmt->close();
    return (int)$n > 0;
}

function lfm_col_exists($conn, $table, $col) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->bind_param("ss", $table, $col);
    $stmt->execute();
    $stmt->bind_result($n);
    $stmt->fetch();
    $stmt->close();
    return (int)$n > 0;
}

function lfm_ensure_col($conn, $table, $col, $definition) {
    if (lfm_table_exists($conn, $table) && !lfm_col_exists($conn, $table, $col)) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `$col` $definition");
    }
}

function lfm_ensure_schema($conn) {
    if (!empty($_SESSION['lfm_schema_v3'])) return;
    try {
        foreach (['longform_post_likes' => 'uq_lfm_like', 'longform_post_dislikes' => 'uq_lfm_dislike'] as $table => $uq) {
            $conn->query("CREATE TABLE IF NOT EXISTS `$table` (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                post_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `$uq` (post_id, user_id),
                KEY idx_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            lfm_ensure_col($conn, $table, 'user_id', 'INT UNSIGNED NOT NULL DEFAULT 0');
        }

        $conn->query("CREATE TABLE IF NOT EXISTS longform_post_downloads (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            post_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_lfm_download (post_id, user_id),
            KEY idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        lfm_ensure_col($conn, 'longform_post_downloads', 'user_id', 'INT UNSIGNED NOT NULL DEFAULT 0');

        $conn->query("CREATE TABLE IF NOT EXISTS longform_post_views (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            post_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            viewed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_post_user (post_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        lfm_ensure_col($conn, 'longform_post_views', 'user_id', 'INT UNSIGNED NOT NULL DEFAULT 0');

        $conn->query("CREATE TABLE IF NOT EXISTS longform_watch_progress (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            post_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            progress_seconds INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_lfm_progress (post_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        lfm_ensure_col($conn, 'longform_watch_progress', 'user_id', 'INT UNSIGNED NOT NULL DEFAULT 0');
        lfm_ensure_col($conn, 'longform_watch_progress', 'progress_seconds', 'INT UNSIGNED NOT NULL DEFAULT 0');

        /* ── comments (top-level + one level of replies) and comment likes ── */
        $conn->query("CREATE TABLE IF NOT EXISTS longform_comments (
            id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            post_id       INT UNSIGNED NOT NULL,
            user_id       INT UNSIGNED NOT NULL,
            parent_id     INT UNSIGNED DEFAULT NULL,
            comment       TEXT NOT NULL,
            likes_count   INT UNSIGNED NOT NULL DEFAULT 0,
            replies_count INT UNSIGNED NOT NULL DEFAULT 0,
            is_pinned     TINYINT(1) NOT NULL DEFAULT 0,
            is_edited     TINYINT(1) NOT NULL DEFAULT 0,
            created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_post_parent_time (post_id, parent_id, created_at),
            INDEX idx_user (user_id),
            INDEX idx_parent (parent_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        /* covers installs that already had a longform_comments table in an older shape */
        lfm_ensure_col($conn, 'longform_comments', 'parent_id',     'INT UNSIGNED DEFAULT NULL');
        lfm_ensure_col($conn, 'longform_comments', 'likes_count',   'INT UNSIGNED NOT NULL DEFAULT 0');
        lfm_ensure_col($conn, 'longform_comments', 'replies_count', 'INT UNSIGNED NOT NULL DEFAULT 0');
        lfm_ensure_col($conn, 'longform_comments', 'is_pinned',     'TINYINT(1) NOT NULL DEFAULT 0');
        lfm_ensure_col($conn, 'longform_comments', 'is_edited',     'TINYINT(1) NOT NULL DEFAULT 0');

        $conn->query("CREATE TABLE IF NOT EXISTS longform_comment_likes (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            comment_id INT UNSIGNED NOT NULL,
            user_id    INT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_comment_user (comment_id, user_id),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $conn->query("CREATE TABLE IF NOT EXISTS longform_comment_dislikes (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            comment_id INT UNSIGNED NOT NULL,
            user_id    INT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_cdislike_user (comment_id, user_id),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        /* the "hide count" switches from the upload form (like / dislike / save / share) */
        foreach (['hide_like_count', 'hide_dislike_count', 'hide_save_count', 'hide_share_count'] as $hideCol) {
            lfm_ensure_col($conn, 'longform_posts', $hideCol, 'TINYINT(1) NOT NULL DEFAULT 0');
        }

        $_SESSION['lfm_schema_v3'] = 1;
    } catch (Throwable $e) { /* the modal still works for everything that already exists */ }
}

/* ══════════════════════════════════════════════════════════════════
   TRACK (follow) SYSTEM — same logic as the profile page's toggle_follow
   ══════════════════════════════════════════════════════════════════ */
function lfm_track_map($conn) {
    static $map = false;
    if ($map !== false) return $map;

    /* the constants above win over everything (including an old cached guess) */
    if (LFM_TRACK_TABLE !== '' && LFM_TRACK_BY_COL !== '' && LFM_TRACK_TO_COL !== '') {
        return $map = [LFM_TRACK_TABLE, LFM_TRACK_BY_COL, LFM_TRACK_TO_COL];
    }
    if (!empty($_SESSION['lfm_track_map']) && is_array($_SESSION['lfm_track_map'])) {
        return $map = $_SESSION['lfm_track_map'];
    }

    $map    = null;
    $tables = ['followers', 'tracks', 'trackers', 'tracking', 'user_tracks', 'follows', 'user_follows'];
    $pairs  = [
        ['follower_id', 'following_id'], ['follower_id', 'followed_id'], ['follower_id', 'user_id'],
        ['tracker_id', 'tracked_id'], ['tracker_id', 'tracking_id'], ['tracker_id', 'tracked_user_id'], ['tracker_id', 'user_id'],
    ];
    try {
        foreach ($tables as $t) {
            if (!lfm_table_exists($conn, $t)) continue;
            foreach ($pairs as $p) {
                if (lfm_col_exists($conn, $t, $p[0]) && lfm_col_exists($conn, $t, $p[1])) {
                    $map = [$t, $p[0], $p[1]];
                    $_SESSION['lfm_track_map'] = $map;
                    break 2;
                }
            }
        }
    } catch (Throwable $e) { $map = null; }
    return $map;
}

function lfm_is_tracking($conn, $by, $to) {
    $m = lfm_track_map($conn);
    if (!$m || !$by || !$to) return false;
    try {
        $stmt = $conn->prepare("SELECT 1 FROM `{$m[0]}` WHERE `{$m[1]}` = ? AND `{$m[2]}` = ? LIMIT 1");
        $stmt->bind_param("ii", $by, $to);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        return (bool)$row;
    } catch (Throwable $e) { return false; }
}

/* Track / untrack. Returns true when the viewer is now tracking. Throws on failure.
   Same as the profile page: untracking also clears any track_requests row so a
   private profile shows "Request Track" again afterwards. */
function lfm_toggle_track($conn, $by, $to) {
    $m = lfm_track_map($conn);
    if (!$m) throw new Exception('Track table not found');
    if (lfm_is_tracking($conn, $by, $to)) {
        $stmt = $conn->prepare("DELETE FROM `{$m[0]}` WHERE `{$m[1]}` = ? AND `{$m[2]}` = ?");
        $stmt->bind_param("ii", $by, $to);
        if (!$stmt->execute()) throw new Exception('Untrack failed');
        $stmt->close();

        try {
            $s2 = $conn->prepare("DELETE FROM track_requests WHERE requester_id = ? AND target_id = ?");
            $s2->bind_param("ii", $by, $to);
            $s2->execute();
            $s2->close();
        } catch (Throwable $e) { /* table may not exist — not fatal */ }

        return false;
    }
    $stmt = $conn->prepare("INSERT INTO `{$m[0]}` (`{$m[1]}`, `{$m[2]}`) VALUES (?, ?)");
    $stmt->bind_param("ii", $by, $to);
    if (!$stmt->execute()) throw new Exception('Track failed');
    $stmt->close();
    return true;
}

/* ══════════════════════════════════════════════════════════════════
   SMALL DATABASE HELPERS (table names below are fixed strings, never user input)
   ══════════════════════════════════════════════════════════════════ */
function lfm_get_post($conn, $post_id) {
    $stmt = $conn->prepare("SELECT id, user_id, duration_seconds, hide_like_count, hide_dislike_count, hide_save_count, hide_share_count FROM longform_posts WHERE id=? AND status='published' LIMIT 1");
    $stmt->bind_param("i", $post_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/* Is this account verified? (users.is_verified — same flag the profile page uses). Never throws: false if the column is missing. */
function lfm_is_verified($conn, $uid) {
    try {
        $stmt = $conn->prepare("SELECT is_verified FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return !empty($row['is_verified']);
    } catch (Throwable $e) { return false; }
}

/* A user row (id, username, profile_pic) — used for the creator of whichever video is playing.
   The video can belong to anyone (for example a video you saved from another creator),
   so the modal never assumes the creator is the owner of the page. */
function lfm_user_row($conn, $uid) {
    $stmt = $conn->prepare("SELECT id, username, profile_pic FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function lfm_has_row($conn, $table, $post_id, $user_id) {
    $stmt = $conn->prepare("SELECT 1 FROM `$table` WHERE post_id=? AND user_id=? LIMIT 1");
    $stmt->bind_param("ii", $post_id, $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    return (bool)$row;
}

function lfm_add_row($conn, $table, $post_id, $user_id) {
    $stmt = $conn->prepare("INSERT INTO `$table` (post_id, user_id) VALUES (?, ?)");
    $stmt->bind_param("ii", $post_id, $user_id);
    if (!$stmt->execute()) throw new Exception('insert failed');
    $stmt->close();
}

function lfm_remove_row($conn, $table, $post_id, $user_id) {
    $stmt = $conn->prepare("DELETE FROM `$table` WHERE post_id=? AND user_id=?");
    $stmt->bind_param("ii", $post_id, $user_id);
    $stmt->execute();
    $stmt->close();
}

function lfm_count($conn, $table, $post_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM `$table` WHERE post_id=?");
    $stmt->bind_param("i", $post_id);
    $stmt->execute();
    $stmt->bind_result($n);
    $stmt->fetch();
    $stmt->close();
    return (int)$n;
}

/* The owner's nickname (same field the profile page shows as "@nickname").
   Falls back to the username when no nickname is set, exactly like the
   profile page's  $display_name = $user['nickname'] ?: $user['username']  */
function lfm_owner_nickname($conn, $owner_id, $username) {
    $nick = '';
    try {
        $stmt = $conn->prepare("SELECT nickname FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $owner_id);
        $stmt->execute();
        $stmt->bind_result($nick);
        $stmt->fetch();
        $stmt->close();
    } catch (Throwable $e) { $nick = ''; }
    $nick = trim((string)$nick);
    return $nick !== '' ? $nick : (string)$username;
}

/* Basic info about the person viewing the modal (for the comments composer avatar). */
function lfm_viewer_info($conn, $uid) {
    $out = ['id' => (int)$uid, 'username' => '', 'avatar' => 'uploads/default.jpg'];
    try {
        $stmt = $conn->prepare("SELECT username, profile_pic FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $out['username'] = (string)$row['username'];
            $out['verified'] = lfm_is_verified($conn, $uid);
            $out['avatar']   = !empty($row['profile_pic']) ? $row['profile_pic'] : 'uploads/default.jpg';
        }
    } catch (Throwable $e) { /* keep defaults */ }
    return $out;
}

/* Works out which columns the comments table actually has, once per session,
   so this keeps working whether the table was just created above or already
   existed under a slightly different shape. */
function lfm_comment_cols($conn) {
    if (!isset($_SESSION['lfm_cmt_cols'])) {
        $cols = ['text' => '', 'parent' => false, 'user' => false, 'likes' => false, 'replies' => false, 'pinned' => false];
        if (lfm_table_exists($conn, 'longform_comments')) {
            foreach (['comment', 'content', 'body', 'text', 'comment_text', 'message'] as $c) {
                if (lfm_col_exists($conn, 'longform_comments', $c)) { $cols['text'] = $c; break; }
            }
            $cols['parent']  = lfm_col_exists($conn, 'longform_comments', 'parent_id');
            $cols['user']    = lfm_col_exists($conn, 'longform_comments', 'user_id');
            $cols['likes']   = lfm_col_exists($conn, 'longform_comments', 'likes_count');
            $cols['replies'] = lfm_col_exists($conn, 'longform_comments', 'replies_count');
            $cols['pinned']  = lfm_col_exists($conn, 'longform_comments', 'is_pinned');
        }
        $_SESSION['lfm_cmt_cols'] = $cols;
    }
    return $_SESSION['lfm_cmt_cols'];
}

/* The newest comment, for the little preview on the comments teaser bar. */
function lfm_comment_preview($conn, $post_id) {
    try {
        $cols = lfm_comment_cols($conn);
        if ($cols['text'] === '' || !$cols['user']) return null;

        $where = "c.post_id = ?";
        if ($cols['parent']) $where .= " AND (c.parent_id IS NULL OR c.parent_id = 0)";
        $stmt = $conn->prepare("SELECT c.id, c.`{$cols['text']}` AS body, u.username, u.profile_pic FROM longform_comments c INNER JOIN users u ON u.id = c.user_id WHERE $where ORDER BY c.id DESC LIMIT 1");
        $stmt->bind_param("i", $post_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return null;
        return [
            'id'     => (int)$row['id'],
            'user'   => (string)$row['username'],
            'avatar' => !empty($row['profile_pic']) ? $row['profile_pic'] : 'uploads/default.jpg',
            'text'   => mb_substr(trim((string)$row['body']), 0, 200),
        ];
    } catch (Throwable $e) { return null; }
}

/* Top-level comments for a video (parent_id IS NULL), paged and sorted.
   $sort: 'top' (pinned, then most liked), 'new' (newest first), 'old' (oldest first).
   $owner_id is the video owner — used to flag comments the owner has liked
   (the "creator liked this" avatar badge, TikTok-style). */
function lfm_fetch_comments($conn, $post_id, $viewer_id, $sort, $offset, $limit, $owner_id) {
    $cols = lfm_comment_cols($conn);
    if ($cols['text'] === '' || !$cols['user']) return ['rows' => [], 'has_more' => false];
    $textCol = $cols['text'];

    if ($sort === 'new') {
        $order = "c.created_at DESC, c.id DESC";
    } elseif ($sort === 'old') {
        $order = "c.created_at ASC, c.id ASC";
    } elseif (!empty($cols['pinned'])) {
        $order = "c.is_pinned DESC, " . (!empty($cols['likes']) ? "c.likes_count DESC, " : "") . "c.created_at DESC";
    } else {
        $order = (!empty($cols['likes']) ? "c.likes_count DESC, " : "") . "c.created_at DESC";
    }
    $parentWhere = $cols['parent'] ? "(c.parent_id IS NULL OR c.parent_id = 0)" : "1=1";

    $sql = "SELECT c.id, c.user_id, c.`$textCol` AS body, c.created_at,
                   " . (!empty($cols['likes']) ? "c.likes_count" : "0") . " AS likes_count,
                   " . (!empty($cols['replies']) ? "c.replies_count" : "0") . " AS replies_count,
                   " . (!empty($cols['pinned']) ? "c.is_pinned" : "0") . " AS is_pinned,
                   u.username, u.profile_pic, u.is_verified AS verified,
                   (SELECT COUNT(*) FROM longform_comment_likes l WHERE l.comment_id = c.id AND l.user_id = ?) AS liked,
                   (SELECT COUNT(*) FROM longform_comment_likes l WHERE l.comment_id = c.id AND l.user_id = ?) AS owner_liked,
                   (SELECT COUNT(*) FROM longform_comment_dislikes d WHERE d.comment_id = c.id AND d.user_id = ?) AS disliked,
                   " . ($cols['parent'] ? "(SELECT u2.profile_pic FROM longform_comments r INNER JOIN users u2 ON u2.id = r.user_id WHERE r.parent_id = c.id ORDER BY (r.user_id = ?) DESC, r.id ASC LIMIT 1)" : "NULL") . " AS reply_avatar
            FROM longform_comments c
            INNER JOIN users u ON u.id = c.user_id
            WHERE c.post_id = ? AND $parentWhere
            ORDER BY $order
            LIMIT ? OFFSET ?";
    $lim  = $limit + 1;
    $rows = [];
    $stmt = $conn->prepare($sql);
    /* placeholders in order: liked, owner_liked, disliked, [reply avatar: prefer the creator's], post, limit, offset */
    $params = [$viewer_id, $owner_id, $viewer_id];
    if ($cols['parent']) $params[] = $owner_id;
    $params[] = $post_id; $params[] = $lim; $params[] = $offset;
    $stmt->bind_param(str_repeat('i', count($params)), ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) { $rows[] = $r; }
    $stmt->close();

    $has_more = count($rows) > $limit;
    $rows     = array_slice($rows, 0, $limit);
    return ['rows' => $rows, 'has_more' => $has_more];
}

/* Replies to one comment (top-level OR another reply — true nested threading),
   oldest first, paged. Because parent_id is no longer flattened to the thread
   root when a comment is inserted (see lfm_comment_add below), this same
   function fetches direct children at ANY depth: the first replies to a
   top-level comment, or the replies aimed at one specific reply. Each row
   also carries its OWN replies_count / reply_avatar, so a reply that itself
   has replies can show its own "N replies" toggle. */
function lfm_fetch_replies($conn, $post_id, $comment_id, $viewer_id, $offset, $limit, $owner_id) {
    $cols = lfm_comment_cols($conn);
    if ($cols['text'] === '' || !$cols['user'] || !$cols['parent']) return ['rows' => [], 'has_more' => false];
    $textCol = $cols['text'];
    $lim = $limit + 1;

    $stmt = $conn->prepare("
        SELECT c.id, c.user_id, c.`$textCol` AS body, c.created_at,
               " . (!empty($cols['likes']) ? "c.likes_count" : "0") . " AS likes_count,
               " . (!empty($cols['replies']) ? "c.replies_count" : "0") . " AS replies_count,
               u.username, u.profile_pic, u.is_verified AS verified,
               (SELECT COUNT(*) FROM longform_comment_likes l WHERE l.comment_id = c.id AND l.user_id = ?) AS liked,
               (SELECT COUNT(*) FROM longform_comment_likes l WHERE l.comment_id = c.id AND l.user_id = ?) AS owner_liked,
               (SELECT COUNT(*) FROM longform_comment_dislikes d WHERE d.comment_id = c.id AND d.user_id = ?) AS disliked,
               (SELECT u2.profile_pic FROM longform_comments r INNER JOIN users u2 ON u2.id = r.user_id WHERE r.parent_id = c.id ORDER BY (r.user_id = ?) DESC, r.id ASC LIMIT 1) AS reply_avatar
        FROM longform_comments c
        INNER JOIN users u ON u.id = c.user_id
        WHERE c.post_id = ? AND c.parent_id = ?
        ORDER BY c.created_at ASC, c.id ASC
        LIMIT ? OFFSET ?
    ");
    $stmt->bind_param("iiiiiiii", $viewer_id, $owner_id, $viewer_id, $owner_id, $post_id, $comment_id, $lim, $offset);
    $stmt->execute();
    $res  = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) { $rows[] = $r; }
    $stmt->close();

    $has_more = count($rows) > $limit;
    $rows     = array_slice($rows, 0, $limit);
    return ['rows' => $rows, 'has_more' => $has_more];
}

/* Shapes one DB row from lfm_fetch_comments/lfm_fetch_replies into what the JS expects.
   is_owner    → this comment was written by the owner of the video (gets the owner badge)
   owner_liked → the owner of the video has liked THIS comment (gets the small creator avatar + like badge)
   can_delete  → the viewer wrote it, or the viewer owns the video (either may delete it) */
function lfm_comment_row_out($r, $post_owner_id, $viewer_id) {
    $uid = (int)$r['user_id'];
    return [
        'id'          => (int)$r['id'],
        'user_id'     => $uid,
        'username'    => (string)$r['username'],
        'verified'    => !empty($r['verified']),
        'avatar'      => !empty($r['profile_pic']) ? $r['profile_pic'] : 'uploads/default.jpg',
        'text'        => (string)$r['body'],
        'ago'         => lf_time_ago($r['created_at']),
        'likes'       => (int)$r['likes_count'],
        'liked'       => (int)$r['liked'] > 0,
        'owner_liked' => (int)($r['owner_liked'] ?? 0) > 0,
        'disliked'    => (int)($r['disliked'] ?? 0) > 0,
        /* the small profile pic on the "N replies" row: the creator's if the creator replied, otherwise the first replier's */
        'reply_avatar' => (isset($r['replies_count']) && (int)$r['replies_count'] > 0) ? (!empty($r['reply_avatar']) ? $r['reply_avatar'] : 'uploads/default.jpg') : null,
        'replies'     => isset($r['replies_count']) ? (int)$r['replies_count'] : 0,
        'pinned'      => !empty($r['is_pinned']),
        'is_owner'    => $uid === (int)$post_owner_id,
        'can_delete'  => ($uid === (int)$viewer_id) || ((int)$post_owner_id === (int)$viewer_id),
    ];
}

/* ══════════════════════════════════════════════════════════════════
   GET ?lfm_post=ID  →  everything the modal shows under the video.
   The video may belong to ANY creator (for example one you saved), so the
   creator is worked out from the video itself, not from whose page this is.
   ══════════════════════════════════════════════════════════════════ */
if (isset($_GET['lfm_post'])) {
    lfm_ensure_schema($conn);
    $pid = (int)$_GET['lfm_post'];
    $row = null;
    try {
        $stmt = $conn->prepare("
            SELECT p.id, p.user_id, p.title, p.description, p.video_path, p.thumbnail_path, p.duration_seconds, p.views_count,
                   p.hide_like_count, p.hide_dislike_count, p.hide_save_count, p.hide_share_count,
                   p.comments_disabled, p.created_at,
                   (SELECT COUNT(*) FROM longform_post_likes x WHERE x.post_id = p.id)                        AS likes,
                   (SELECT COUNT(*) FROM longform_post_likes x WHERE x.post_id = p.id AND x.user_id = ?)      AS liked,
                   (SELECT COUNT(*) FROM longform_post_dislikes x WHERE x.post_id = p.id)                     AS dislikes,
                   (SELECT COUNT(*) FROM longform_post_dislikes x WHERE x.post_id = p.id AND x.user_id = ?)   AS disliked,
                   (SELECT COUNT(*) FROM longform_post_saves x WHERE x.post_id = p.id)                        AS saves,
                   (SELECT COUNT(*) FROM longform_post_saves x WHERE x.post_id = p.id AND x.user_id = ?)      AS saved,
                   (SELECT COUNT(*) FROM longform_post_shares x WHERE x.post_id = p.id)                       AS shares,
                   (SELECT COUNT(*) FROM longform_post_downloads x WHERE x.post_id = p.id)                    AS downloads,
                   (SELECT COUNT(*) FROM longform_comments x WHERE x.post_id = p.id)                          AS comments,
                   (SELECT w.progress_seconds FROM longform_watch_progress w WHERE w.post_id = p.id AND w.user_id = ? LIMIT 1) AS progress
            FROM longform_posts p
            WHERE p.id = ? AND p.status = 'published'
            LIMIT 1
        ");
        $stmt->bind_param("iiiii", $current_user, $current_user, $current_user, $current_user, $pid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (Throwable $e) {
        lf_json(['success' => false, 'error' => 'Could not load this video. Please try again.']);
    }
    if (!$row) lf_json(['success' => false, 'error' => 'That video could not be found.']);

    $post_owner_id = (int)$row['user_id'];
    $post_owner    = ($post_owner_id === (int)$owner['id']) ? $owner : lfm_user_row($conn, $post_owner_id);
    if (!$post_owner) lf_json(['success' => false, 'error' => 'That video could not be found.']);
    $is_owner = ($post_owner_id === (int)$current_user);

    $ts        = strtotime((string)$row['created_at']);
    /* each count is hidden from everyone except the creator when the creator switched it off */
    $hide_l    = !empty($row['hide_like_count'])    && !$is_owner;
    $hide_d    = !empty($row['hide_dislike_count']) && !$is_owner;
    $hide_s    = !empty($row['hide_save_count'])    && !$is_owner;
    $hide_sh   = !empty($row['hide_share_count'])   && !$is_owner;
    $desc      = trim((string)($row['description'] ?? ''));
    $can_track = (!$is_owner && lfm_track_map($conn) !== null);
    $off       = !empty($row['comments_disabled']);

    lf_json([
        'success' => true,
        'post' => [
            'id'            => (int)$row['id'],
            'title'         => (string)$row['title'],
            'video'         => (string)$row['video_path'],
            'thumb'         => (string)$row['thumbnail_path'],
            'duration'      => (int)$row['duration_seconds'],
            'views'         => (int)$row['views_count'],
            'ago'           => lf_time_ago($row['created_at']),
            'date_day'      => $ts ? date('j M', $ts) : '',
            'date_year'     => $ts ? date('Y', $ts) : '',
            'likes'         => $hide_l ? null : (int)$row['likes'],
            'hide_likes'    => $hide_l,
            'liked'         => (int)$row['liked'] > 0,
            'dislikes'      => $hide_d ? null : (int)$row['dislikes'],
            'hide_dislikes' => $hide_d,
            'disliked'      => (int)$row['disliked'] > 0,
            'saves'         => $hide_s ? null : (int)$row['saves'],
            'hide_saves'    => $hide_s,
            'saved'         => (int)$row['saved'] > 0,
            'shares'        => $hide_sh ? null : (int)$row['shares'],
            'hide_shares'   => $hide_sh,
            'downloads'     => (int)$row['downloads'],
            'comments'      => (int)$row['comments'],
            'comments_off'  => $off,
            'progress'      => (int)($row['progress'] ?? 0),
            'url'           => 'profile_longform.php?user_id=' . $post_owner_id . '&post=' . (int)$row['id'],
            'desc_html'     => $desc !== '' ? lf_format_description($desc) : '',
        ],
        'owner' => [
            'id'        => $post_owner_id,
            'username'  => (string)$post_owner['username'],
            'verified'  => lfm_is_verified($conn, $post_owner_id),
            'nickname'  => lfm_owner_nickname($conn, $post_owner_id, (string)$post_owner['username']),
            'avatar'    => !empty($post_owner['profile_pic']) ? $post_owner['profile_pic'] : 'uploads/default.jpg',
            'is_self'   => $is_owner,
            'can_track' => $can_track,
            /* true when the viewer already tracks (follows) this owner → button says "Untrack" */
            'tracking'  => $can_track ? lfm_is_tracking($conn, $current_user, $post_owner_id) : false,
        ],
        'viewer'  => lfm_viewer_info($conn, $current_user),
        'preview' => ($off || (int)$row['comments'] === 0) ? null : lfm_comment_preview($conn, (int)$row['id']),
    ]);
}

/* ══════════════════════════════════════════════════════════════════
   GET ?lfm_more=1&post=ID&offset=N  →  the same creator's other videos.
   Order: videos OLDER than the one playing first ("up next"), then the newer ones.
   ══════════════════════════════════════════════════════════════════ */
if (isset($_GET['lfm_more'])) {
    lfm_ensure_schema($conn);
    $cur    = (int)($_GET['post'] ?? 0);
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $limit  = LFM_LIST_SIZE + 1;
    $rows   = [];

    $curPost = lfm_get_post($conn, $cur);
    if (!$curPost) lf_json(['success' => false, 'error' => 'Could not load more videos.']);
    $creator_id = (int)$curPost['user_id'];
    $creator    = ($creator_id === (int)$owner['id']) ? $owner : lfm_user_row($conn, $creator_id);
    if (!$creator) lf_json(['success' => false, 'error' => 'Could not load more videos.']);

    try {
        $stmt = $conn->prepare("
            SELECT p.id, p.user_id, p.title, p.video_path, p.thumbnail_path, p.duration_seconds, p.views_count, p.created_at,
                   wp.progress_seconds,
                   (SELECT COUNT(*) FROM longform_post_saves sv WHERE sv.post_id = p.id AND sv.user_id = ?) AS is_saved
            FROM longform_posts p
            INNER JOIN longform_posts cur ON cur.id = ? AND cur.user_id = p.user_id
            LEFT JOIN longform_watch_progress wp ON wp.post_id = p.id AND wp.user_id = ?
            WHERE p.user_id = ? AND p.status = 'published' AND p.id <> cur.id
            ORDER BY (p.created_at > cur.created_at OR (p.created_at = cur.created_at AND p.id > cur.id)) ASC,
                     p.created_at DESC, p.id DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bind_param("iiiiii", $current_user, $cur, $current_user, $creator_id, $limit, $offset);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        $stmt->close();
    } catch (Throwable $e) {
        lf_json(['success' => false, 'error' => 'Could not load more videos.']);
    }

    $has_more = count($rows) > LFM_LIST_SIZE;
    $rows     = array_slice($rows, 0, LFM_LIST_SIZE);
    $html = ''; $ids = [];
    foreach ($rows as $r) {
        $rid   = (int)$r['id'];
        $ids[] = $rid;
        /* same card as the grid, but with its own id so nothing is duplicated on the page */
        $html .= str_replace('id="lf-card-' . $rid . '"', 'id="lfm-card-' . $rid . '"',
                             lf_render_card($r, $creator, ($creator_id === (int)$current_user), $creator_id));
    }
    lf_json(['success' => true, 'html' => $html, 'ids' => $ids, 'count' => count($rows), 'has_more' => $has_more]);
}

/* ══════════════════════════════════════════════════════════════════
   GET ?lfm_comments=1&post=ID&sort=top|new|old&offset=N  →  top-level comments
   ══════════════════════════════════════════════════════════════════ */
if (isset($_GET['lfm_comments'])) {
    lfm_ensure_schema($conn);
    $pid    = (int)($_GET['post'] ?? 0);
    $sort   = (string)($_GET['sort'] ?? 'top');
    if (!in_array($sort, ['top', 'new', 'old'], true)) $sort = 'top';
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $postRow = lfm_get_post($conn, $pid);
    if (!$postRow) lf_json(['success' => false, 'error' => 'That video could not be found.']);

    try {
        $res = lfm_fetch_comments($conn, $pid, $current_user, $sort, $offset, LFM_CMT_PAGE, (int)$postRow['user_id']);
    } catch (Throwable $e) {
        lf_json(['success' => false, 'error' => 'Could not load comments.']);
    }
    $out = [];
    foreach ($res['rows'] as $r) { $out[] = lfm_comment_row_out($r, (int)$postRow['user_id'], (int)$current_user); }
    lf_json(['success' => true, 'comments' => $out, 'has_more' => $res['has_more']]);
}

/* ══════════════════════════════════════════════════════════════════
   GET ?lfm_replies=1&comment_id=ID&post=ID&offset=N  →  replies to one comment
   (a top-level comment OR another reply — see lfm_fetch_replies)
   ══════════════════════════════════════════════════════════════════ */
if (isset($_GET['lfm_replies'])) {
    lfm_ensure_schema($conn);
    $pid    = (int)($_GET['post'] ?? 0);
    $cid    = (int)($_GET['comment_id'] ?? 0);
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $postRow = lfm_get_post($conn, $pid);
    if (!$postRow) lf_json(['success' => false, 'error' => 'That video could not be found.']);

    try {
        $res = lfm_fetch_replies($conn, $pid, $cid, $current_user, $offset, LFM_CMT_PAGE, (int)$postRow['user_id']);
    } catch (Throwable $e) {
        lf_json(['success' => false, 'error' => 'Could not load replies.']);
    }
    $out = [];
    foreach ($res['rows'] as $r) { $out[] = lfm_comment_row_out($r, (int)$postRow['user_id'], (int)$current_user); }
    lf_json(['success' => true, 'replies' => $out, 'has_more' => $res['has_more']]);
}

/* ══════════════════════════════════════════════════════════════════
   POST actions used by the modal
   ══════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (
        isset($_POST['lfm_react']) || isset($_POST['lfm_download']) || isset($_POST['lfm_track']) ||
        isset($_POST['lfm_progress']) || isset($_POST['lfm_view']) ||
        isset($_POST['lfm_comment_add']) || isset($_POST['lfm_comment_like']) || isset($_POST['lfm_comment_dislike']) || isset($_POST['lfm_comment_delete']))) {

    if (!isset($_POST['csrf_token']) || !hash_equals($csrf_token, (string)$_POST['csrf_token'])) {
        lf_json(['success' => false, 'error' => 'Your session expired. Refresh the page and try again.']);
    }
    lfm_ensure_schema($conn);

    /* ── like / dislike (tap again to undo; the two cancel each other) ── */
    if (isset($_POST['lfm_react'])) {
        $pid  = (int)($_POST['post_id'] ?? 0);
        $type = (($_POST['type'] ?? '') === 'dislike') ? 'dislike' : 'like';
        $post = lfm_get_post($conn, $pid);
        if (!$post) lf_json(['success' => false, 'error' => 'That video could not be found.']);

        $mine  = ($type === 'like') ? 'longform_post_likes'    : 'longform_post_dislikes';
        $other = ($type === 'like') ? 'longform_post_dislikes' : 'longform_post_likes';
        try {
            if (lfm_has_row($conn, $mine, $pid, $current_user)) {
                lfm_remove_row($conn, $mine, $pid, $current_user);
            } else {
                lfm_add_row($conn, $mine, $pid, $current_user);
                lfm_remove_row($conn, $other, $pid, $current_user);
            }
            $likes    = lfm_count($conn, 'longform_post_likes', $pid);
            $dislikes = lfm_count($conn, 'longform_post_dislikes', $pid);
            $liked    = lfm_has_row($conn, 'longform_post_likes', $pid, $current_user);
            $disliked = lfm_has_row($conn, 'longform_post_dislikes', $pid, $current_user);
        } catch (Throwable $e) {
            lf_json(['success' => false, 'error' => 'Could not save that. Please try again.']);
        }
        $isCreator = ((int)$post['user_id'] === $current_user);
        $hideL = !empty($post['hide_like_count'])    && !$isCreator;
        $hideD = !empty($post['hide_dislike_count']) && !$isCreator;
        lf_json([
            'success'  => true,
            'liked'    => $liked,
            'disliked' => $disliked,
            'likes'    => $hideL ? null : $likes,
            'dislikes' => $hideD ? null : $dislikes,
        ]);
    }

    /* ── download (counted once per person) ── */
    if (isset($_POST['lfm_download'])) {
        $pid = (int)($_POST['post_id'] ?? 0);
        if (!lfm_get_post($conn, $pid)) lf_json(['success' => false, 'error' => 'That video could not be found.']);
        try {
            if (!lfm_has_row($conn, 'longform_post_downloads', $pid, $current_user)) {
                lfm_add_row($conn, 'longform_post_downloads', $pid, $current_user);
            }
            $n = lfm_count($conn, 'longform_post_downloads', $pid);
        } catch (Throwable $e) {
            lf_json(['success' => false, 'error' => 'Could not record the download.']);
        }
        lf_json(['success' => true, 'downloads' => $n]);
    }

    /* ── track / untrack the creator of the video that is playing ── */
    if (isset($_POST['lfm_track'])) {
        $target = (int)($_POST['user_id'] ?? 0);
        if ($target <= 0 || $target === (int)$current_user || !lfm_user_row($conn, $target)) {
            lf_json(['success' => false, 'error' => 'You cannot track this account.']);
        }
        try {
            $now = lfm_toggle_track($conn, $current_user, $target);
        } catch (Throwable $e) {
            lf_json(['success' => false, 'error' => 'Could not update tracking. Please try again.']);
        }
        lf_json(['success' => true, 'tracking' => $now]);
    }

    /* ── remember where the viewer stopped (drives the red bar on the grid) ── */
    if (isset($_POST['lfm_progress'])) {
        $pid  = (int)($_POST['post_id'] ?? 0);
        $post = lfm_get_post($conn, $pid);
        if (!$post) lf_json(['success' => false, 'error' => 'That video could not be found.']);
        $sec = max(0, min((int)($_POST['seconds'] ?? 0), (int)$post['duration_seconds']));
        try {
            if (lfm_has_row($conn, 'longform_watch_progress', $pid, $current_user)) {
                $stmt = $conn->prepare("UPDATE longform_watch_progress SET progress_seconds = ? WHERE post_id = ? AND user_id = ?");
                $stmt->bind_param("iii", $sec, $pid, $current_user);
            } else {
                $stmt = $conn->prepare("INSERT INTO longform_watch_progress (post_id, user_id, progress_seconds) VALUES (?, ?, ?)");
                $stmt->bind_param("iii", $pid, $current_user, $sec);
            }
            if (!$stmt->execute()) throw new Exception('progress failed');
            $stmt->close();
        } catch (Throwable $e) {
            lf_json(['success' => false, 'error' => 'Could not save your progress.']);
        }
        lf_json(['success' => true]);
    }

    /* ── count a view (once per person, after they have really watched a few seconds) ── */
    if (isset($_POST['lfm_view'])) {
        $pid = (int)($_POST['post_id'] ?? 0);
        if (!lfm_get_post($conn, $pid)) lf_json(['success' => false, 'error' => 'That video could not be found.']);
        $counted = false;
        try {
            if (!lfm_has_row($conn, 'longform_post_views', $pid, $current_user)) {
                lfm_add_row($conn, 'longform_post_views', $pid, $current_user);
                $conn->query("UPDATE longform_posts SET views_count = views_count + 1 WHERE id = " . (int)$pid);
                $counted = true;
            }
            $stmt = $conn->prepare("SELECT views_count FROM longform_posts WHERE id = ?");
            $stmt->bind_param("i", $pid);
            $stmt->execute();
            $stmt->bind_result($views);
            $stmt->fetch();
            $stmt->close();
        } catch (Throwable $e) {
            lf_json(['success' => false, 'error' => 'Could not count the view.']);
        }
        lf_json(['success' => true, 'views' => (int)$views, 'counted' => $counted]);
    }

    /* ── add a comment, or a reply to a comment or to another reply.
          True nested threading: parent_id is always the comment being
          replied to directly — it is never rewritten to the thread's
          root — so a reply-to-a-reply creates its OWN sub-thread instead
          of being flattened into the top-level thread. ── */
    if (isset($_POST['lfm_comment_add'])) {
        $pid    = (int)($_POST['post_id'] ?? 0);
        $text   = trim((string)($_POST['text'] ?? ''));
        $parent = (int)($_POST['parent_id'] ?? 0);

        $post = lfm_get_post($conn, $pid);
        if (!$post) lf_json(['success' => false, 'error' => 'That video could not be found.']);
        if ($text === '') lf_json(['success' => false, 'error' => 'Write something first.']);
        if (mb_strlen($text) > 1000) lf_json(['success' => false, 'error' => 'That comment is too long.']);

        $off = 0;
        try {
            $stmt = $conn->prepare("SELECT comments_disabled FROM longform_posts WHERE id = ?");
            $stmt->bind_param("i", $pid);
            $stmt->execute();
            $stmt->bind_result($off);
            $stmt->fetch();
            $stmt->close();
        } catch (Throwable $e) { $off = 0; }
        if (!empty($off)) lf_json(['success' => false, 'error' => 'Comments are turned off for this video.']);

        $cols = lfm_comment_cols($conn);
        if ($cols['text'] === '' || !$cols['user']) lf_json(['success' => false, 'error' => 'Comments are not available right now.']);
        $textCol = $cols['text'];

        /* the immediate parent, verified to belong to the same video — this is kept
           EXACTLY as given, so replying to a reply builds a real sub-thread under
           that reply instead of collapsing back to the top-level comment */
        $parentId = null;
        if ($parent > 0 && $cols['parent']) {
            $stmt = $conn->prepare("SELECT id FROM longform_comments WHERE id = ? AND post_id = ? LIMIT 1");
            $stmt->bind_param("ii", $parent, $pid);
            $stmt->execute();
            $prow = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($prow) $parentId = (int)$prow['id'];
        }

        $newId = 0;
        try {
            $stmt = $conn->prepare("INSERT INTO longform_comments (post_id, user_id, parent_id, `$textCol`) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("iiis", $pid, $current_user, $parentId, $text);
            if (!$stmt->execute()) throw new Exception('insert failed');
            $newId = $stmt->insert_id;
            $stmt->close();
            if ($parentId && !empty($cols['replies'])) {
                $conn->query("UPDATE longform_comments SET replies_count = replies_count + 1 WHERE id = " . (int)$parentId);
            }
        } catch (Throwable $e) {
            lf_json(['success' => false, 'error' => 'Could not post your comment. Please try again.']);
        }

        $viewer = lfm_viewer_info($conn, $current_user);
        lf_json([
            'success'   => true,
            'parent_id' => $parentId,
            'comment'   => [
                'id' => (int)$newId, 'user_id' => (int)$current_user, 'username' => $viewer['username'], 'verified' => !empty($viewer['verified']),
                'avatar' => $viewer['avatar'], 'text' => $text, 'ago' => 'Just now',
                'likes' => 0, 'liked' => false, 'owner_liked' => false, 'disliked' => false, 'reply_avatar' => null, 'replies' => 0, 'pinned' => false,
                'is_owner'   => ((int)$post['user_id'] === (int)$current_user),
                'can_delete' => true,
            ],
        ]);
    }

    /* ── like / unlike a comment ── */
    if (isset($_POST['lfm_comment_like'])) {
        $cid  = (int)($_POST['comment_id'] ?? 0);
        $cols = lfm_comment_cols($conn);
        $liked = false; $n = 0; $ownerLiked = null;
        try {
            /* is the viewer the owner of the video this comment belongs to? drives the
               "creator liked this" badge — only the owner's own like toggles it */
            $isOwnerViewer = false;
            $postOwnerId = 0;
            $stmt = $conn->prepare("SELECT p.user_id FROM longform_comments c INNER JOIN longform_posts p ON p.id = c.post_id WHERE c.id = ? LIMIT 1");
            $stmt->bind_param("i", $cid);
            $stmt->execute();
            $stmt->bind_result($postOwnerId);
            if ($stmt->fetch()) $isOwnerViewer = ((int)$postOwnerId === (int)$current_user);
            $stmt->close();

            $stmt = $conn->prepare("SELECT 1 FROM longform_comment_likes WHERE comment_id = ? AND user_id = ? LIMIT 1");
            $stmt->bind_param("ii", $cid, $current_user);
            $stmt->execute();
            $already = (bool)$stmt->get_result()->fetch_row();
            $stmt->close();

            if ($already) {
                $stmt = $conn->prepare("DELETE FROM longform_comment_likes WHERE comment_id = ? AND user_id = ?");
                $stmt->bind_param("ii", $cid, $current_user);
                $stmt->execute();
                $stmt->close();
                if (!empty($cols['likes'])) $conn->query("UPDATE longform_comments SET likes_count = GREATEST(0, likes_count - 1) WHERE id = " . (int)$cid);
                $liked = false;
            } else {
                $stmt = $conn->prepare("INSERT IGNORE INTO longform_comment_likes (comment_id, user_id) VALUES (?, ?)");
                $stmt->bind_param("ii", $cid, $current_user);
                $stmt->execute();
                $stmt->close();
                /* a like replaces a dislike */
                $stmt = $conn->prepare("DELETE FROM longform_comment_dislikes WHERE comment_id = ? AND user_id = ?");
                $stmt->bind_param("ii", $cid, $current_user);
                $stmt->execute();
                $stmt->close();
                if (!empty($cols['likes'])) $conn->query("UPDATE longform_comments SET likes_count = likes_count + 1 WHERE id = " . (int)$cid);
                $liked = true;
            }

            if ($isOwnerViewer) $ownerLiked = $liked;

            if (!empty($cols['likes'])) {
                $stmt = $conn->prepare("SELECT likes_count FROM longform_comments WHERE id = ?");
                $stmt->bind_param("i", $cid);
                $stmt->execute();
                $stmt->bind_result($n);
                $stmt->fetch();
                $stmt->close();
            } else {
                $stmt = $conn->prepare("SELECT COUNT(*) FROM longform_comment_likes WHERE comment_id = ?");
                $stmt->bind_param("i", $cid);
                $stmt->execute();
                $stmt->bind_result($n);
                $stmt->fetch();
                $stmt->close();
            }
        } catch (Throwable $e) {
            lf_json(['success' => false, 'error' => 'Could not save that. Please try again.']);
        }
        lf_json(['success' => true, 'liked' => $liked, 'disliked' => false, 'likes' => (int)$n, 'owner_liked' => $ownerLiked]);
    }

    /* ── dislike / undislike a comment (tap again to undo; a dislike replaces a like and the other way round) ── */
    if (isset($_POST['lfm_comment_dislike'])) {
        $cid  = (int)($_POST['comment_id'] ?? 0);
        $cols = lfm_comment_cols($conn);
        $disliked = false; $n = 0; $ownerLiked = null;
        try {
            $found = false; $isOwnerViewer = false; $postOwnerId = 0;
            $stmt = $conn->prepare("SELECT p.user_id FROM longform_comments c INNER JOIN longform_posts p ON p.id = c.post_id WHERE c.id = ? LIMIT 1");
            $stmt->bind_param("i", $cid);
            $stmt->execute();
            $stmt->bind_result($postOwnerId);
            if ($stmt->fetch()) { $found = true; $isOwnerViewer = ((int)$postOwnerId === (int)$current_user); }
            $stmt->close();
            if (!$found) lf_json(['success' => false, 'error' => 'That comment could not be found.']);

            $stmt = $conn->prepare("SELECT 1 FROM longform_comment_dislikes WHERE comment_id = ? AND user_id = ? LIMIT 1");
            $stmt->bind_param("ii", $cid, $current_user);
            $stmt->execute();
            $had = (bool)$stmt->get_result()->fetch_row();
            $stmt->close();

            if ($had) {
                $stmt = $conn->prepare("DELETE FROM longform_comment_dislikes WHERE comment_id = ? AND user_id = ?");
                $stmt->bind_param("ii", $cid, $current_user);
                $stmt->execute();
                $stmt->close();
                $disliked = false;
            } else {
                $stmt = $conn->prepare("INSERT IGNORE INTO longform_comment_dislikes (comment_id, user_id) VALUES (?, ?)");
                $stmt->bind_param("ii", $cid, $current_user);
                $stmt->execute();
                $stmt->close();
                $disliked = true;

                $stmt = $conn->prepare("SELECT 1 FROM longform_comment_likes WHERE comment_id = ? AND user_id = ? LIMIT 1");
                $stmt->bind_param("ii", $cid, $current_user);
                $stmt->execute();
                $hadLike = (bool)$stmt->get_result()->fetch_row();
                $stmt->close();
                if ($hadLike) {
                    $stmt = $conn->prepare("DELETE FROM longform_comment_likes WHERE comment_id = ? AND user_id = ?");
                    $stmt->bind_param("ii", $cid, $current_user);
                    $stmt->execute();
                    $stmt->close();
                    if (!empty($cols['likes'])) $conn->query("UPDATE longform_comments SET likes_count = GREATEST(0, likes_count - 1) WHERE id = " . (int)$cid);
                    if ($isOwnerViewer) $ownerLiked = false;      /* the creator's like is gone, so the creator badge goes too */
                }
            }

            if (!empty($cols['likes'])) {
                $stmt = $conn->prepare("SELECT likes_count FROM longform_comments WHERE id = ?");
            } else {
                $stmt = $conn->prepare("SELECT COUNT(*) FROM longform_comment_likes WHERE comment_id = ?");
            }
            $stmt->bind_param("i", $cid);
            $stmt->execute();
            $stmt->bind_result($n);
            $stmt->fetch();
            $stmt->close();
        } catch (Throwable $e) {
            lf_json(['success' => false, 'error' => 'Could not save that. Please try again.']);
        }
        lf_json(['success' => true, 'disliked' => $disliked, 'liked' => false, 'likes' => (int)$n, 'owner_liked' => $ownerLiked]);
    }

    /* ── delete a comment: its author OR the owner of the video may delete it.
          Deleting a comment also removes every reply anywhere underneath it
          (its whole sub-tree), however many levels deep. ── */
    if (isset($_POST['lfm_comment_delete'])) {
        $cid  = (int)($_POST['comment_id'] ?? 0);
        $cols = lfm_comment_cols($conn);

        $crow = null;
        try {
            $stmt = $conn->prepare("SELECT id, post_id, user_id" . (!empty($cols['parent']) ? ", parent_id" : "") . " FROM longform_comments WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $cid);
            $stmt->execute();
            $crow = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } catch (Throwable $e) { $crow = null; }
        if (!$crow) lf_json(['success' => false, 'error' => 'That comment could not be found.']);

        $post = lfm_get_post($conn, (int)$crow['post_id']);
        if (!$post) lf_json(['success' => false, 'error' => 'That video could not be found.']);

        /* only the comment's own author, or the owner of the video it's on, may delete it */
        $isMine      = ((int)$crow['user_id'] === (int)$current_user);
        $isPostOwner = ((int)$post['user_id'] === (int)$current_user);
        if (!$isMine && !$isPostOwner) lf_json(['success' => false, 'error' => 'You cannot delete this comment.']);

        $parentId = (!empty($cols['parent']) && !empty($crow['parent_id'])) ? (int)$crow['parent_id'] : 0;

        /* walk the whole sub-tree under $cid, however many levels deep, breadth-first */
        $ids = [$cid];
        try {
            if (!empty($cols['parent'])) {
                $frontier = [$cid];
                $guard = 0;
                while (!empty($frontier) && $guard < 500) {
                    $guard++;
                    $in = implode(',', array_map('intval', $frontier));
                    $stmt = $conn->prepare("SELECT id FROM longform_comments WHERE parent_id IN ($in)");
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $next = [];
                    while ($r = $res->fetch_row()) { $next[] = (int)$r[0]; $ids[] = (int)$r[0]; }
                    $stmt->close();
                    $frontier = $next;
                }
            }
            $in = implode(',', array_map('intval', $ids));
            $conn->query("DELETE FROM longform_comment_likes WHERE comment_id IN ($in)");
            $conn->query("DELETE FROM longform_comment_dislikes WHERE comment_id IN ($in)");
            if (!$conn->query("DELETE FROM longform_comments WHERE id IN ($in)")) throw new Exception('delete failed');
            if ($parentId && !empty($cols['replies'])) {
                $conn->query("UPDATE longform_comments SET replies_count = GREATEST(0, replies_count - 1) WHERE id = " . (int)$parentId);
            }
        } catch (Throwable $e) {
            lf_json(['success' => false, 'error' => 'Could not delete this comment. Please try again.']);
        }
        lf_json(['success' => true, 'removed' => count($ids), 'parent_id' => $parentId ?: null]);
    }
}

/* ══════════════════════════════════════════════════════════════════
   THE MODAL ITSELF — CSS, HTML and JavaScript
   ══════════════════════════════════════════════════════════════════ */
function lfm_render() { ?>

<script>try{if(localStorage.getItem('dz_theme')==='light')document.documentElement.setAttribute('data-theme','light');}catch(e){}</script>

<style>
/* ══════════════════════════════════════════════════════════════
   LONG FORM MODAL  (everything is prefixed lfm- so it can never clash with the page)
   ══════════════════════════════════════════════════════════════ */
:root{ --lfm-w:560px; }
/* ═══ THEME + SIZE ═══
   • --lfm-s : 1 on desktop, 1.08 on responsive (<=790px) — every size below runs through it (8 % bigger on phones/tablets only).
   • Colours follow the site-wide light / dark setting (<html data-theme="light">, saved as dz_theme) exactly like the other Dezzme pages.
     The video player (black) and the mini player always stay dark. */
:root{ --lfm-s:1; }
@media (max-width:790px){ :root{ --lfm-s:1.08; } }
.lfm-overlay{ --lfm-fg:var(--fg,255,255,255); }
:root[data-theme="light"] .lfm-overlay{
    color-scheme:light;
    --fg:6,7,26; --lfm-fg:6,7,26;
    --text:#0b0d24; --solid:#ffffff;
    --muted:rgba(6,7,26,.55); --muted-2:rgba(6,7,26,.38); --sub:rgba(6,7,26,.7);
    --surface:rgba(6,7,26,.045); --surface-2:rgba(6,7,26,.075); --surface-hover:rgba(6,7,26,.08);
    --border:rgba(6,7,26,.16); --line:rgba(6,7,26,.1); --divider:rgba(6,7,26,.1);
    --bg:linear-gradient(-45deg,#ffffff,#f4f5fb,#f4f5fb);
    --lfm-sheet:#ffffff; --lfm-menu:#ffffff; --lfm-on-text:#ffffff; --lfm-mention:#1d6fd8;
}
:root[data-theme="light"] .lfm-overlay.is-fs{ --text:#fff; --lfm-fg:255,255,255; }
:root[data-theme="light"] .lfm-desc-sheet,:root[data-theme="light"] .lfm-cs-sheet{ box-shadow:0 -14px 40px rgba(6,7,26,.18); }
:root[data-theme="light"] .lfm-cs-sortmenu,:root[data-theme="light"] .lfm-cs-menu{ box-shadow:0 10px 30px rgba(6,7,26,.18); }

.lfm-overlay{ --lfm-seek-x:calc(8px * var(--lfm-s)); --lfm-seek-b:calc(5px * var(--lfm-s)); }   /* bar inset from the sides / height above the bottom edge */

.lfm-overlay{
    position:fixed; inset:0; z-index:1500; display:none;
    background:var(--bg); background-attachment:fixed; color:var(--text);
}
.lfm-overlay.open{ display:block; animation:lfm-fade .22s ease; }
@keyframes lfm-fade{ from{ opacity:0; } to{ opacity:1; } }
@keyframes lfm-spin{ to{ transform:rotate(360deg); } }
@keyframes lfm-pop{ 0%{ transform:scale(1); } 45%{ transform:scale(1.38); } 100%{ transform:scale(1); } }

.lfm-col{
    position:relative; width:100%; max-width:var(--lfm-w); height:100%; margin:0 auto;
    display:flex; flex-direction:column; overflow:hidden;
    background:transparent;   /* same color as the sides: the page background shows straight through */
    --lfm-ph:calc(min(100vw, var(--lfm-w)) * .5625);
}
@media (min-width:791px){
    .lfm-col{ border-left:1px solid var(--divider); border-right:1px solid var(--divider); }
}

/* ── PLAYER ── */
.lfm-player{
    position:relative; width:100%; aspect-ratio:16/9; flex-shrink:0;
    background:#000; overflow:hidden; z-index:30;
    -webkit-user-select:none; user-select:none; -webkit-touch-callout:none; -webkit-tap-highlight-color:transparent;
    touch-action:none;   /* JS owns every gesture here (tap / double-tap-to-like / drag-down-to-minimize);
                             "none" stops the browser from turning a downward drag into a native page scroll
                             or eating the gesture as a pan before our pointer handlers see it. */
}
.lfm-video{ position:absolute; inset:0; width:100%; height:100%; object-fit:contain; background:#000; display:block; }

.lfm-wheel-wrap{
    position:absolute; inset:0; z-index:6; display:none; align-items:center; justify-content:center;
    pointer-events:none; background:rgba(0,0,0,.3);
}
.lfm-wheel-wrap.show{ display:flex; }
.lfm-wheel{
    width:calc(48px * var(--lfm-s)); height:calc(48px * var(--lfm-s)); border-radius:50%;
    border:3.5px solid rgba(255,255,255,.2); border-top-color:var(--accent);
    animation:lfm-spin .55s linear infinite;
}

.lfm-err{
    position:absolute; inset:0; z-index:8; display:none; flex-direction:column; align-items:center; justify-content:center;
    gap:calc(10px * var(--lfm-s)); text-align:center; padding:calc(16px * var(--lfm-s)); background:rgba(0,0,0,.78);
    font-size:calc(.78rem * var(--lfm-s)); color:rgba(255,255,255,.86); line-height:1.5;
}
.lfm-err.show{ display:flex; }
.lfm-err i{ font-size:calc(1.8rem * var(--lfm-s)); color:var(--accent); }
.lfm-err button{
    padding:calc(8px * var(--lfm-s)) calc(20px * var(--lfm-s)); border-radius:calc(999px * var(--lfm-s)); border:1px solid rgba(255,255,255,.3);
    background:rgba(255,255,255,.1); color:#fff; font-size:calc(.75rem * var(--lfm-s)); font-weight:600;
}

/* controls layer: hidden until you tap the video (or, on a mouse, hover it) */
.lfm-controls{
    position:absolute; inset:0; z-index:5; opacity:0; pointer-events:none; transition:opacity .22s ease;
    background:linear-gradient(180deg, rgba(0,0,0,.62) 0, rgba(0,0,0,0) 34%, rgba(0,0,0,0) 56%, rgba(0,0,0,.74) 100%);
}
.lfm-player.ctl .lfm-controls{ opacity:1; pointer-events:auto; }
.lfm-player.buffering .lfm-center{ opacity:0; pointer-events:none; }

.lfm-btn{
    background:none; border:none; color:#fff; padding:0; flex-shrink:0;
    display:flex; align-items:center; justify-content:center;
    transition:transform .15s, opacity .2s, background .2s; -webkit-tap-highlight-color:transparent;
}
.lfm-btn:active{ transform:scale(.88); }

.lfm-top{ position:absolute; top:0; left:0; right:0; display:flex; align-items:center; justify-content:space-between; padding:calc(8px * var(--lfm-s)) calc(8px * var(--lfm-s)) 0; }
.lfm-chev{ width:calc(44px * var(--lfm-s)); height:calc(44px * var(--lfm-s)); font-size:calc(2rem * var(--lfm-s)); margin-left:calc(-2px * var(--lfm-s)); }
.lfm-top-right{ display:flex; align-items:center; gap:calc(8px * var(--lfm-s)); }
.lfm-gear{ width:calc(44px * var(--lfm-s)); height:calc(44px * var(--lfm-s)); font-size:calc(1.74rem * var(--lfm-s)); }
.lfm-mute{ width:calc(44px * var(--lfm-s)); height:calc(44px * var(--lfm-s)); font-size:calc(1.45rem * var(--lfm-s)); }
/* mute / unmute icon (custom speaker) */
.dz-snd{display:inline-block;width:1.12em;height:1em;background-color:currentColor;-webkit-mask:var(--dz-snd) center/contain no-repeat;mask:var(--dz-snd) center/contain no-repeat;vertical-align:middle;font-style:normal}
.dz-snd.on{--dz-snd:url(data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%22261%20218%20681%20603%22%3E%3Cpath%20fill-rule%3D%22evenodd%22%20d%3D%22M576.2%2C820.0C560.6%2C817.2%20551.8%2C812.4%20515.6%2C787.7C500.1%2C777.2%20474.4%2C759.8%20458.5%2C749.0C442.6%2C738.3%20425.4%2C726.5%20420.5%2C722.9C415.6%2C719.3%20405.2%2C712.2%20397.5%2C707.0C381.1%2C696.0%20346.4%2C671.6%20333.5%2C661.9C314.1%2C647.4%20292.3%2C621.5%20281.6%2C600.2C258.9%2C555.2%20255.1%2C512.3%20270.0%2C467.9C277.7%2C444.5%20282.6%2C435.5%20299.8%2C412.0C309.3%2C399.1%20335.0%2C374.5%20346.8%2C367.0C365.5%2C355.1%20391.4%2C337.8%20401.0%2C330.6C412.7%2C322.0%20438.1%2C304.3%20460.9%2C289.0C470.3%2C282.7%20487.8%2C270.8%20499.8%2C262.6C559.6%2C221.4%20567.2%2C217.6%20589.5%2C217.6C628.4%2C217.6%20651.1%2C243.1%20654.0%2C290.0C655.3%2C310.7%20655.3%2C742.7%20654.0%2C756.0C649.5%2C802.0%20618.5%2C827.6%20576.2%2C820.0ZM747.9%2C729.0C732.2%2C726.4%20723.6%2C712.1%20725.3%2C691.7C727.0%2C671.8%20735.1%2C664.0%20760.3%2C658.0C794.4%2C649.8%20822.2%2C631.8%20843.7%2C603.9C862.9%2C578.9%20876.0%2C533.5%20872.1%2C504.8C869.5%2C485.1%20856.5%2C452.0%20846.5%2C439.5C819.9%2C406.3%20796.9%2C390.9%20760.7%2C382.0C736.8%2C376.1%20727.9%2C367.8%20724.9%2C348.7C721.1%2C324.7%20735.0%2C309.3%20758.5%2C311.4C807.1%2C315.8%20864.9%2C348.4%20895.2%2C388.5C915.9%2C416.0%20920.6%2C425.4%20936.6%2C471.0C943.6%2C491.1%20944.3%2C540.8%20938.0%2C565.7C923.1%2C623.6%20884.6%2C673.9%20832.7%2C702.9C803.4%2C719.3%20763.6%2C731.6%20747.9%2C729.0ZM738.0%2C584.8C732.1%2C581.6%20727.6%2C574.3%20726.0%2C565.2C724.3%2C556.1%20724.4%2C479.3%20726.1%2C472.4C737.4%2C425.3%20805.6%2C472.9%20801.7%2C525.2C799.0%2C559.4%20759.6%2C596.3%20738.0%2C584.8Z%22%2F%3E%3C%2Fsvg%3E)}
.dz-snd.off{--dz-snd:url(data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%22261%20218%20681%20603%22%3E%3Cpath%20d%3D%22M576.2%2C820.0C560.6%2C817.2%20551.8%2C812.4%20515.6%2C787.7C500.1%2C777.2%20474.4%2C759.8%20458.5%2C749.0C442.6%2C738.3%20425.4%2C726.5%20420.5%2C722.9C415.6%2C719.3%20405.2%2C712.2%20397.5%2C707.0C381.1%2C696.0%20346.4%2C671.6%20333.5%2C661.9C314.1%2C647.4%20292.3%2C621.5%20281.6%2C600.2C258.9%2C555.2%20255.1%2C512.3%20270.0%2C467.9C277.7%2C444.5%20282.6%2C435.5%20299.8%2C412.0C309.3%2C399.1%20335.0%2C374.5%20346.8%2C367.0C365.5%2C355.1%20391.4%2C337.8%20401.0%2C330.6C412.7%2C322.0%20438.1%2C304.3%20460.9%2C289.0C470.3%2C282.7%20487.8%2C270.8%20499.8%2C262.6C559.6%2C221.4%20567.2%2C217.6%20589.5%2C217.6C628.4%2C217.6%20651.1%2C243.1%20654.0%2C290.0C655.3%2C310.7%20655.3%2C742.7%20654.0%2C756.0C649.5%2C802.0%20618.5%2C827.6%20576.2%2C820.0Z%22%2F%3E%3Cpath%20d%3D%22M730%20400L910%20620M910%20400L730%20620%22%20stroke%3D%22%23000%22%20stroke-width%3D%2264%22%20stroke-linecap%3D%22round%22%20fill%3D%22none%22%2F%3E%3C%2Fsvg%3E)}

/* autoplay switch — a real checkbox under the hood (same mechanism as the Loop toggle
   in the settings sheet) so it always flips reliably, styled as a pill switch */
.lfm-ap{
    position:relative; display:inline-flex; align-items:center; width:calc(40px * var(--lfm-s)); height:calc(22px * var(--lfm-s)); border-radius:calc(999px * var(--lfm-s));
    background:rgba(255,255,255,.3); transition:background .2s; flex-shrink:0; cursor:pointer; -webkit-tap-highlight-color:transparent;
}
.lfm-ap input{ position:absolute; inset:0; margin:0; opacity:0; cursor:pointer; }
.lfm-ap-knob{
    position:absolute; top:calc(1px * var(--lfm-s)); left:calc(1px * var(--lfm-s)); width:calc(20px * var(--lfm-s)); height:calc(20px * var(--lfm-s)); border-radius:50%;
    background:#fff; color:#111; display:flex; align-items:center; justify-content:center;
    font-size:calc(.82rem * var(--lfm-s)); transition:transform .2s, background .2s, color .2s; pointer-events:none;
}
.lfm-ap.on{ background:#fff; }
.lfm-ap.on .lfm-ap-knob{ transform:translateX(calc(18px * var(--lfm-s))); background:#0a0c24; color:#fff; }

.lfm-center{
    position:absolute; top:50%; left:50%; transform:translate(-50%,-50%);
    display:flex; align-items:center; gap:clamp(calc(20px * var(--lfm-s)), 9vw, calc(46px * var(--lfm-s))); transition:opacity .2s;
}
.lfm-skip{ width:calc(52px * var(--lfm-s)); height:calc(52px * var(--lfm-s)); font-size:calc(2.25rem * var(--lfm-s)); border-radius:50%; background:rgba(0,0,0,.42); }
.lfm-skip:hover{ background:rgba(0,0,0,.6); }
.lfm-play{ width:calc(66px * var(--lfm-s)); height:calc(66px * var(--lfm-s)); border-radius:50%; background:rgba(0,0,0,.42); font-size:calc(2.6rem * var(--lfm-s)); }
.lfm-play:hover{ background:rgba(0,0,0,.6); }

.lfm-bottom{
    position:absolute; left:0; right:0; bottom:calc(16px * var(--lfm-s));
    display:flex; align-items:center; justify-content:space-between; padding:0 calc(10px * var(--lfm-s)) 0 calc(12px * var(--lfm-s));
}
.lfm-time{
    font-size:calc(.72rem * var(--lfm-s)); font-weight:500; padding:calc(3px * var(--lfm-s)) calc(10px * var(--lfm-s)); border-radius:calc(999px * var(--lfm-s));
    background:rgba(0,0,0,.5); font-variant-numeric:tabular-nums; white-space:nowrap;
}
.lfm-time .sep{ opacity:.6; margin:0 calc(2px * var(--lfm-s)); }
.lfm-fs{ width:calc(38px * var(--lfm-s)); height:calc(38px * var(--lfm-s)); font-size:calc(1.35rem * var(--lfm-s)); border-radius:50%; background:rgba(0,0,0,.42); }

/* progress bar: thin, rounded ends, wide but never touching the screen edges, and it stays
   in the SAME spot whether the controls are showing or not (it can still be dragged when they are) */
.lfm-seek{ position:absolute; left:0; right:0; bottom:0; height:calc(24px * var(--lfm-s)); z-index:7; touch-action:none; cursor:pointer; pointer-events:none; }
.lfm-player.ctl .lfm-seek{ pointer-events:auto; }
.lfm-rail{
    position:absolute; left:var(--lfm-seek-x); right:var(--lfm-seek-x); bottom:var(--lfm-seek-b); height:calc(3px * var(--lfm-s));
    border-radius:calc(999px * var(--lfm-s)); background:rgba(255,255,255,.28); transition:height .15s;
}
.lfm-player.scrub .lfm-rail{ height:calc(5px * var(--lfm-s)); }
.lfm-buf, .lfm-played{ position:absolute; left:0; top:0; bottom:0; width:0; border-radius:calc(999px * var(--lfm-s)); }
.lfm-buf{ background:rgba(255,255,255,.34); }
.lfm-played{ background:var(--accent); }
.lfm-dot{
    position:absolute; top:50%; left:0; width:calc(8px * var(--lfm-s)); height:calc(8px * var(--lfm-s)); margin:calc(-4px * var(--lfm-s)) 0 0 calc(-4px * var(--lfm-s));
    border-radius:50%; background:var(--accent); box-shadow:0 0 0 3px var(--accent-glow);
    opacity:0; transform:scale(.4); transition:opacity .15s, transform .15s; pointer-events:none;
}
.lfm-player.ctl .lfm-dot{ opacity:1; transform:scale(1); }
.lfm-player.scrub .lfm-dot{ transform:scale(1.5); }

/* "up next" card shown when a video ends and autoplay is on */
.lfm-next{
    position:absolute; left:calc(10px * var(--lfm-s)); right:calc(10px * var(--lfm-s)); bottom:calc(38px * var(--lfm-s)); z-index:9; display:none; align-items:center; gap:calc(10px * var(--lfm-s));
    padding:calc(10px * var(--lfm-s)) calc(12px * var(--lfm-s)); border-radius:calc(12px * var(--lfm-s)); background:rgba(6,8,32,.94); border:1px solid var(--border);
}
.lfm-next.show{ display:flex; }
.lfm-next-txt{ flex:1; min-width:0; }
.lfm-next-lbl{ font-size:calc(.64rem * var(--lfm-s)); color:var(--muted); }
.lfm-next-title{ font-size:calc(.76rem * var(--lfm-s)); font-weight:600; color:#fff; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-top:calc(1px * var(--lfm-s)); }
.lfm-next-btn{
    padding:calc(7px * var(--lfm-s)) calc(14px * var(--lfm-s)); border-radius:calc(999px * var(--lfm-s)); border:none; flex-shrink:0;
    background:var(--accent); color:#fff; font-size:calc(.72rem * var(--lfm-s)); font-weight:600;
}
.lfm-next-btn.ghost{ background:rgba(255,255,255,.1); }

/* ── DOUBLE-TAP LIKE animation (thumbs-up, Instagram style):
   soft glow + expanding ring + a thumbs-up that jumps in with a bounce and floats away,
   plus a burst of little thumbs-ups and sparkles ── */
.lfm-fx{ --hs:calc(96px * var(--lfm-s)); position:absolute; inset:0; z-index:10; pointer-events:none; overflow:hidden; }
.lfm-fxp{ position:absolute; width:0; height:0; }
/* ── DOUBLE-TAP LIKE: same heart burst as the immersive post modal (ring + heart + flying little hearts),
   then the heart flies to the Like button and the like lands there ── */
.lfm-hb-ring{
    position:absolute; left:0; top:0; width:calc(95px * var(--lfm-s)); height:calc(95px * var(--lfm-s));
    margin:calc(-47.5px * var(--lfm-s)) 0 0 calc(-47.5px * var(--lfm-s)); border-radius:50%; box-sizing:border-box;
    border:3px solid rgba(255,45,79,.62); transform:scale(.25); opacity:0; pointer-events:none;
    animation:lfm-hb-ring .75s cubic-bezier(.15,.6,.35,1) forwards;
}
@keyframes lfm-hb-ring{ 0%{ transform:scale(.25); opacity:.9; border-width:4px; } 100%{ transform:scale(2.3); opacity:0; border-width:1px; } }
.lfm-hb-heart{
    position:absolute; left:0; top:0; pointer-events:none; opacity:0; line-height:1;
    transform:translate(-50%,-50%) scale(0) rotate(0deg);
    animation:lfm-hb-heart .95s cubic-bezier(.17,.89,.32,1.28) forwards;
}
.lfm-hb-heart i{
    display:block; font-size:calc(84px * var(--lfm-s)); color:#ff2d4f; line-height:1;
    filter:drop-shadow(0 6px 20px rgba(255,45,79,.6)); -webkit-text-stroke:1.3px rgba(255,255,255,.32);
}
@keyframes lfm-hb-heart{
    0%{   transform:translate(-50%,-50%) scale(0)    rotate(-28deg); opacity:0; }
    14%{  transform:translate(-50%,-50%) scale(1.35) rotate(10deg);  opacity:1; }
    28%{  transform:translate(-50%,-50%) scale(.88)  rotate(-6deg);  opacity:1; }
    42%{  transform:translate(-50%,-50%) scale(1.14) rotate(4deg);   opacity:1; }
    58%{  transform:translate(-50%,-50%) scale(.97)  rotate(-1deg);  opacity:1; }
    75%{  transform:translate(-50%,-50%) scale(1.05) rotate(0deg);   opacity:1; }
    100%{ transform:translate(-50%,-50%) scale(1.15) rotate(0deg) translateY(-40px); opacity:0; }
}
.lfm-hb-p{
    position:absolute; left:0; top:0; pointer-events:none; opacity:0; color:#ff2d4f; line-height:1;
    transform:translate(-50%,-50%) scale(0);
    animation:lfm-hb-p .8s cubic-bezier(.2,.7,.3,1) forwards;
}
.lfm-hb-p i{ display:block; filter:drop-shadow(0 2px 7px rgba(255,45,79,.5)); line-height:1; }
@keyframes lfm-hb-p{
    0%{   transform:translate(-50%,-50%) scale(0) rotate(0deg); opacity:0; }
    12%{  opacity:1; }
    100%{ transform:translate(calc(-50% + var(--tx)), calc(-50% + var(--ty))) scale(1) rotate(var(--tr)); opacity:0; }
}
.lfm-hb-fly{ position:fixed; z-index:2147483000; left:0; top:0; pointer-events:none; color:#ff2d4f; line-height:1; will-change:transform,opacity; }
.lfm-hb-fly i{ display:block; font-size:34px; line-height:1; filter:drop-shadow(0 4px 12px rgba(255,45,79,.6)); }

.lfm-glow{
    position:absolute; left:0; top:0; width:calc(230px * var(--lfm-s)); height:calc(230px * var(--lfm-s)); margin:calc(-115px * var(--lfm-s)) 0 0 calc(-115px * var(--lfm-s)); border-radius:50%;
    background:radial-gradient(circle, rgba(255,50,95,.55) 0, rgba(255,50,95,.2) 38%, rgba(255,50,95,0) 68%);
    opacity:0; animation:lfm-glow .75s ease-out forwards;
}
@keyframes lfm-glow{
    0%{ transform:scale(.3); opacity:0; }
    22%{ opacity:1; }
    100%{ transform:scale(1.35); opacity:0; }
}
.lfm-ring{
    position:absolute; left:0; top:0; width:calc(64px * var(--lfm-s)); height:calc(64px * var(--lfm-s)); margin:calc(-32px * var(--lfm-s)) 0 0 calc(-32px * var(--lfm-s)); border-radius:50%;
    border:3px solid rgba(255,130,160,.9); box-sizing:border-box; opacity:0;
    animation:lfm-ring .62s cubic-bezier(.15,.7,.3,1) forwards;
}
@keyframes lfm-ring{
    0%{ transform:scale(.3); opacity:.95; border-width:5px; }
    100%{ transform:scale(2.7); opacity:0; border-width:1px; }
}
.lfm-thumb{
    position:absolute; left:0; top:0; width:var(--hs); height:var(--hs);
    margin:calc(var(--hs) / -2) 0 0 calc(var(--hs) / -2);
    filter:drop-shadow(0 6px 16px rgba(255,30,80,.6)) drop-shadow(0 0 2px rgba(255,255,255,.35));
    transform-origin:50% 60%; will-change:transform, opacity; opacity:0;
    animation:lfm-thumb 1s cubic-bezier(.2,.85,.25,1) forwards;
}
@keyframes lfm-thumb{
    0%{   transform:translateY(calc(16px * var(--lfm-s))) scale(0) rotate(calc(var(--r) - 22deg)); opacity:0; }
    14%{  transform:translateY(calc(-12px * var(--lfm-s))) scale(1.35) rotate(var(--r)); opacity:1; }
    26%{  transform:translateY(calc(3px * var(--lfm-s))) scale(.9) rotate(var(--r)); }
    38%{  transform:translateY(calc(-5px * var(--lfm-s))) scale(1.1) rotate(var(--r)); }
    50%{  transform:translateY(0) scale(1) rotate(var(--r)); opacity:1; }
    70%{  transform:translateY(calc(-16px * var(--lfm-s))) scale(1.04) rotate(var(--r)); opacity:1; }
    100%{ transform:translateY(calc(-90px * var(--lfm-s))) scale(1.2) rotate(var(--r)); opacity:0; }
}
.lfm-spark{
    position:absolute; left:0; top:0; width:var(--s); height:var(--s);
    margin:calc(var(--s) / -2) 0 0 calc(var(--s) / -2);
    background:var(--c); opacity:0; will-change:transform, opacity;
    animation:lfm-spark .82s cubic-bezier(.12,.8,.3,1) forwards;
}
.lfm-spark.d{ border-radius:50%; }
.lfm-spark.t{
    -webkit-mask:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M1 21h4V9H1v12zm22-11c0-1.1-.9-2-2-2h-6.31l.95-4.57.03-.32c0-.41-.17-.79-.44-1.06L14.17 1 7.59 7.59C7.22 7.95 7 8.45 7 9v10c0 1.1.9 2 2 2h9c.83 0 1.54-.5 1.84-1.22l3.02-7.05c.09-.23.14-.47.14-.73v-2z'/%3E%3C/svg%3E") center/contain no-repeat;
            mask:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M1 21h4V9H1v12zm22-11c0-1.1-.9-2-2-2h-6.31l.95-4.57.03-.32c0-.41-.17-.79-.44-1.06L14.17 1 7.59 7.59C7.22 7.95 7 8.45 7 9v10c0 1.1.9 2 2 2h9c.83 0 1.54-.5 1.84-1.22l3.02-7.05c.09-.23.14-.47.14-.73v-2z'/%3E%3C/svg%3E") center/contain no-repeat;
}
@keyframes lfm-spark{
    0%{   transform:translate(0,0) scale(.2) rotate(0deg); opacity:0; }
    14%{  opacity:1; }
    70%{  opacity:1; }
    100%{ transform:translate(var(--dx), var(--dy)) scale(1) rotate(var(--sr)); opacity:0; }
}
@keyframes lfm-thumb-fade{
    0%{ opacity:0; transform:scale(1) rotate(var(--r)); }
    25%{ opacity:1; }
    100%{ opacity:0; transform:scale(1) rotate(var(--r)); }
}

/* ── everything under the video scrolls; the video never moves ──
   a thin scrollbar is shown on normal (desktop) view; it is hidden again
   in the mobile media query further down, same as before there */
.lfm-scroll{
    flex:1; min-height:0; overflow-y:auto; overflow-x:hidden;
    overscroll-behavior:contain; -webkit-overflow-scrolling:touch;
    scrollbar-width:thin; scrollbar-color:rgba(var(--lfm-fg),.25) transparent;
}
.lfm-scroll::-webkit-scrollbar{ width:calc(8px * var(--lfm-s)); }
.lfm-scroll::-webkit-scrollbar-track{ background:transparent; }
.lfm-scroll::-webkit-scrollbar-thumb{ background:rgba(var(--lfm-fg),.25); border-radius:calc(999px * var(--lfm-s)); }
.lfm-scroll::-webkit-scrollbar-thumb:hover{ background:rgba(var(--lfm-fg),.4); }

.lfm-info{ padding:calc(12px * var(--lfm-s)) calc(16px * var(--lfm-s)) 0; cursor:pointer; }

/* TITLE: one line only — if it is too long it is cut off with "..." */
.lfm-title{
    display:block; width:100%; max-width:100%;
    font-size:calc(.98rem * var(--lfm-s)); font-weight:600; color:var(--text,#fff); line-height:1.4;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.lfm-meta{
    display:flex; flex-wrap:wrap; align-items:center; gap:0 calc(6px * var(--lfm-s));
    font-size:calc(.72rem * var(--lfm-s)); color:var(--muted); margin-top:calc(5px * var(--lfm-s)); line-height:1.5;
}
/* the @nickname beside the view count — thin and a little darker */
.lfm-meta-user{ max-width:46%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:rgba(var(--lfm-fg),.6); font-weight:400; }
/* "...more" — thin */
.lfm-more{ color:rgba(var(--lfm-fg),.85); font-weight:400; }

/* ── action row: like / dislike / save / comments / share / report.
   Sits ABOVE the channel row, icons spaced out edge-to-edge (reference-image layout),
   with a divider line underneath separating it from the channel block.
   Every count label (.lfm-act span) reserves the SAME line-box height whether it holds
   a number or is empty (hidden count / no count by default, e.g. Report), so the icon
   above it never shifts up or down — only the text in that reserved spot changes. ── */
.lfm-actions{
    display:flex; align-items:center; justify-content:space-between; gap:calc(2px * var(--lfm-s)); padding:calc(14px * var(--lfm-s)) calc(10px * var(--lfm-s)) calc(12px * var(--lfm-s));
}
.lfm-act{
    display:flex; flex-direction:column; align-items:center; justify-content:center; gap:calc(5px * var(--lfm-s)); flex:1;
    min-width:0; height:calc(52px * var(--lfm-s)); padding:0 calc(2px * var(--lfm-s));
    border:none; background:none; color:rgba(var(--lfm-fg),.9);
    font-size:calc(.68rem * var(--lfm-s)); font-weight:600; white-space:nowrap; border-radius:calc(10px * var(--lfm-s));
    transition:color .2s, transform .12s; -webkit-tap-highlight-color:transparent;
}
.lfm-act i{ font-size:calc(1.35rem * var(--lfm-s)); display:block; line-height:1; }
/* the count/label slot: always reserves one line's height, empty or not, so hiding a
   count (or a button that never has one, like Report) never moves the icon above it */
.lfm-act span{ display:block; min-height:1em; line-height:1; }
.lfm-act:hover{ color:var(--text,#fff); background:none; }
.lfm-act:active{ transform:scale(.9); }
.lfm-act.on{ color:var(--accent); }
.lfm-act.on i{ animation:lfm-pop .34s ease; }
.lfm-act:disabled{ opacity:.55; }
.lfm-act-row{ display:flex; align-items:center; gap:calc(5px * var(--lfm-s)); }
.lfm-act-sep{ display:none; }
.lfm-actions-divider{ height:calc(2px * var(--lfm-s)); background:var(--divider); margin:0; }

/* comment badge (unread-style pill on the Comments icon, matches the reference image) */
.lfm-act-badge{ position:relative; }
.lfm-act-badge .lfm-badge-n{
    position:absolute; top:calc(-6px * var(--lfm-s)); right:calc(-10px * var(--lfm-s)); min-width:calc(16px * var(--lfm-s)); height:calc(16px * var(--lfm-s)); padding:0 calc(4px * var(--lfm-s)); border-radius:calc(999px * var(--lfm-s));
    background:var(--accent); color:#fff; font-size:calc(.6rem * var(--lfm-s)); font-weight:700; line-height:calc(16px * var(--lfm-s)); text-align:center;
}

/* profile pic + name + Track — now BELOW the actions row.
   Username and Track sit on the same line, separated by a small dot;
   the greyed @nickname sits on its own line right underneath. */
.lfm-channel{ display:flex; align-items:center; gap:calc(10px * var(--lfm-s)); padding:calc(14px * var(--lfm-s)) calc(16px * var(--lfm-s)) calc(4px * var(--lfm-s)); min-width:0; }
.lfm-ch-av{
    width:calc(38px * var(--lfm-s)); height:calc(38px * var(--lfm-s)); border-radius:50%; overflow:hidden; flex-shrink:0; text-decoration:none;
    border:2px solid var(--accent); background:linear-gradient(135deg,#262b3d,#171a24);
    display:flex; align-items:center; justify-content:center; color:var(--accent); font-size:calc(1rem * var(--lfm-s));
}
.lfm-ch-av img{ width:100%; height:100%; object-fit:cover; display:block; }
.lfm-ch-info{ flex:1; min-width:0; display:flex; flex-direction:column; gap:calc(2px * var(--lfm-s)); }
.lfm-ch-namerow{ display:flex; align-items:center; gap:calc(7px * var(--lfm-s)); min-width:0; }
.lfm-ch-name{
    font-size:calc(.82rem * var(--lfm-s)); font-weight:600; color:var(--text,#fff); text-decoration:none;
    min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; flex-shrink:1;
}
.lfm-ch-nick{
    font-size:calc(.7rem * var(--lfm-s)); font-weight:400; color:var(--muted);
    min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
}
/* the dot + Track group: sits beside the username, on the SAME line/level, with its
   own gap so the dot and the button are never crushed together */
.lfm-tr{ display:flex; align-items:center; gap:calc(6px * var(--lfm-s)); flex-shrink:0; }
.lfm-sep{ display:inline; color:var(--muted); font-size:calc(.8rem * var(--lfm-s)); flex-shrink:0; }

/* Track / Untrack — same look as the meme modal on the profile page:
   red, with a thin red underline, sitting right beside the username.
   Nudged up half a pixel and given a touch more left/right breathing room
   so its baseline reads level with the username text next to it. */
.lfm-track-btn{
    background:transparent; border:none; box-shadow:none; outline:none;
    -webkit-appearance:none; appearance:none;
    padding:0; margin:0 0 0 calc(2px * var(--lfm-s)); border-radius:0;
    color:#ff2b2b; font-size:calc(13px * var(--lfm-s)); font-weight:700; font-family:inherit; line-height:1.1;
    cursor:pointer; flex-shrink:0;
    text-decoration:underline; text-decoration-color:#ff2b2b;
    text-underline-offset:calc(2px * var(--lfm-s)); text-decoration-thickness:1.25px;
    position:relative; top:calc(-1px * var(--lfm-s));
    transition:opacity .2s, transform .15s; -webkit-tap-highlight-color:transparent;
}
.lfm-track-btn:hover{ opacity:.8; }
.lfm-track-btn:active{ transform:scale(.94); opacity:.7; }
.lfm-track-btn:disabled{ opacity:.5; }

/* comments teaser card — thinner and a touch wider than before, still clear of the corners */
.lfm-comments{
    display:block; width:calc(100% - calc(24px * var(--lfm-s))); margin:calc(9px * var(--lfm-s)) calc(12px * var(--lfm-s)) calc(3px * var(--lfm-s)); padding:calc(8px * var(--lfm-s)) calc(14px * var(--lfm-s)); text-align:left;
    border-radius:calc(12px * var(--lfm-s)); background:var(--surface); border:1px solid var(--border); color:var(--text,#fff); transition:background .2s;
}
.lfm-comments:hover{ background:var(--surface-hover); }
.lfm-cm-head{ display:flex; align-items:center; gap:calc(7px * var(--lfm-s)); font-size:calc(.76rem * var(--lfm-s)); font-weight:600; line-height:1.3; }
.lfm-cm-count{ color:var(--muted); font-weight:500; }
.lfm-cm-prev{ display:flex; align-items:center; gap:calc(9px * var(--lfm-s)); margin-top:calc(7px * var(--lfm-s)); min-width:0; }
.lfm-cm-av{
    width:calc(24px * var(--lfm-s)); height:calc(24px * var(--lfm-s)); border-radius:50%; overflow:hidden; flex-shrink:0; box-sizing:border-box;
    border:1.5px solid var(--accent);
    background:linear-gradient(135deg,#262b3d,#171a24); color:var(--accent);
    display:flex; align-items:center; justify-content:center; font-size:calc(.8rem * var(--lfm-s));
}
.lfm-cm-av img{ width:100%; height:100%; object-fit:cover; display:block; }
.lfm-cm-text{
    font-size:calc(.75rem * var(--lfm-s)); color:rgba(var(--lfm-fg),.84); line-height:1.45; min-width:0; overflow-wrap:anywhere;
    display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;
}
.lfm-cm-empty{ font-size:calc(.74rem * var(--lfm-s)); color:var(--muted); display:flex; align-items:center; gap:calc(8px * var(--lfm-s)); }
.lfm-cm-empty i{ font-size:calc(1rem * var(--lfm-s)); }

/* more videos from this user (same card as the page grid) */
.lfm-listwrap{ margin-top:calc(14px * var(--lfm-s)); border-top:1px solid var(--divider); }
.lfm-list .lf-card:last-child{ border-bottom:none; }

/* skeleton pieces (reuse the page's .sk-shimmer) */
.lfm-sk{ border-radius:calc(6px * var(--lfm-s)); }
.lfm-sk-row{ display:flex; align-items:center; gap:calc(10px * var(--lfm-s)); padding:calc(16px * var(--lfm-s)) calc(16px * var(--lfm-s)) calc(4px * var(--lfm-s)); }
.lfm-sk-card{ width:100%; padding-top:calc(8px * var(--lfm-s)); }
/* skeleton for the actions row: same evenly-spaced, icon-over-label shape as .lfm-actions/.lfm-act */
.lfm-sk-actions{ display:flex; align-items:center; justify-content:space-between; gap:calc(2px * var(--lfm-s)); padding:calc(14px * var(--lfm-s)) calc(10px * var(--lfm-s)) calc(12px * var(--lfm-s)); }
.lfm-sk-act{ display:flex; flex-direction:column; align-items:center; gap:calc(6px * var(--lfm-s)); flex:1; }
.lfm-sk-act-icon{ width:calc(22px * var(--lfm-s)); height:calc(22px * var(--lfm-s)); border-radius:calc(6px * var(--lfm-s)); }
.lfm-sk-act-label{ width:calc(18px * var(--lfm-s)); height:calc(7px * var(--lfm-s)); border-radius:calc(4px * var(--lfm-s)); }
.lfm-sk-divider{ height:calc(1px * var(--lfm-s)); margin:0 calc(16px * var(--lfm-s)); background:var(--divider); }
/* skeleton for the channel row: avatar + two stacked lines (name, nickname) + track placeholder */
.lfm-sk-chrow{ display:flex; align-items:center; gap:calc(10px * var(--lfm-s)); padding:calc(14px * var(--lfm-s)) calc(16px * var(--lfm-s)) calc(4px * var(--lfm-s)); }
.lfm-sk-chinfo{ flex:1; min-width:0; display:flex; flex-direction:column; gap:calc(6px * var(--lfm-s)); }
.lfm-sk-chname{ height:calc(11px * var(--lfm-s)); width:38%; border-radius:calc(5px * var(--lfm-s)); }
.lfm-sk-chnick{ height:calc(9px * var(--lfm-s)); width:26%; border-radius:calc(5px * var(--lfm-s)); }
.lfm-sk-track{ height:calc(13px * var(--lfm-s)); width:calc(44px * var(--lfm-s)); border-radius:calc(5px * var(--lfm-s)); flex-shrink:0; }

/* ── description sheet: slides up and stops at the bottom of the video.
   Top corners are sharp on purpose (no border-radius). ── */
.lfm-desc{
    position:absolute; left:0; right:0; bottom:0; top:var(--lfm-ph); z-index:20;
    display:flex; flex-direction:column;
    background:var(--lfm-sheet,#060820); border-top:1px solid var(--border); border-radius:0;
    box-shadow:0 -14px 40px rgba(0,0,0,.5);
    transform:translateY(102%); visibility:hidden;
    transition:transform .3s cubic-bezier(.22,1,.36,1), visibility 0s .3s;
}
.lfm-desc.open{ transform:none; visibility:visible; transition:transform .3s cubic-bezier(.22,1,.36,1), visibility 0s; }
.lfm-desc-handle{ display:flex; justify-content:center; padding:calc(9px * var(--lfm-s)) 0 calc(2px * var(--lfm-s)); touch-action:none; flex-shrink:0; }
.lfm-desc-handle span{ width:calc(40px * var(--lfm-s)); height:calc(4px * var(--lfm-s)); border-radius:calc(4px * var(--lfm-s)); background:rgba(var(--lfm-fg),.24); }
.lfm-desc-head{
    display:flex; align-items:center; justify-content:space-between; flex-shrink:0;
    padding:calc(6px * var(--lfm-s)) calc(12px * var(--lfm-s)) calc(12px * var(--lfm-s)) calc(16px * var(--lfm-s)); border-bottom:1px solid var(--divider); touch-action:none;
}
.lfm-desc-title{ font-size:calc(1rem * var(--lfm-s)); font-weight:700; }
.lfm-desc-x{
    width:calc(34px * var(--lfm-s)); height:calc(34px * var(--lfm-s)); border-radius:50%; border:none; background:none; color:var(--text,#fff); font-size:calc(1.4rem * var(--lfm-s));
    display:flex; align-items:center; justify-content:center; transition:background .2s;
}
.lfm-desc-x:hover{ background:var(--surface-hover); }
.lfm-desc-scroll{
    flex:1; min-height:0; overflow-y:auto; overscroll-behavior:contain; padding:calc(16px * var(--lfm-s)) calc(16px * var(--lfm-s)) calc(26px * var(--lfm-s));
    -webkit-overflow-scrolling:touch; scrollbar-width:thin; scrollbar-color:rgba(var(--lfm-fg),.25) transparent;
}
.lfm-desc-scroll::-webkit-scrollbar{ width:calc(8px * var(--lfm-s)); }
.lfm-desc-scroll::-webkit-scrollbar-track{ background:transparent; }
.lfm-desc-scroll::-webkit-scrollbar-thumb{ background:rgba(var(--lfm-fg),.25); border-radius:calc(999px * var(--lfm-s)); }
.lfm-desc-scroll::-webkit-scrollbar-thumb:hover{ background:rgba(var(--lfm-fg),.4); }
.lfm-desc-vtitle{ font-size:calc(1rem * var(--lfm-s)); font-weight:700; line-height:1.4; overflow-wrap:anywhere; }
.lfm-stats{ display:grid; grid-template-columns:repeat(3, 1fr); gap:calc(10px * var(--lfm-s)); margin-top:calc(14px * var(--lfm-s)); }
.lfm-stat{
    text-align:center; padding:calc(12px * var(--lfm-s)) calc(6px * var(--lfm-s)); border-radius:calc(14px * var(--lfm-s)); min-width:0;
    background:var(--surface); border:1px solid var(--border);
}
.lfm-stat b{ display:block; font-size:calc(1.05rem * var(--lfm-s)); font-weight:700; line-height:1.3; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.lfm-stat span{ display:block; font-size:calc(.66rem * var(--lfm-s)); color:var(--muted); margin-top:calc(1px * var(--lfm-s)); }
.lfm-desc-box{ margin-top:calc(12px * var(--lfm-s)); padding:calc(14px * var(--lfm-s)); border-radius:calc(14px * var(--lfm-s)); background:var(--surface); border:1px solid var(--border); }
.lfm-desc-text{ font-size:calc(.78rem * var(--lfm-s)); line-height:1.65; color:rgba(var(--lfm-fg),.92); overflow-wrap:anywhere; }
.lfm-desc-text.clamp{ display:-webkit-box; -webkit-line-clamp:5; -webkit-box-orient:vertical; overflow:hidden; }
.lfm-desc-text.none{ color:var(--muted); }
.lfm-desc-toggle{ margin-top:calc(9px * var(--lfm-s)); padding:0; border:none; background:none; color:var(--text,#fff); font-size:calc(.76rem * var(--lfm-s)); font-weight:400; }
.lfm-channel-card{
    margin-top:calc(12px * var(--lfm-s)); padding:calc(10px * var(--lfm-s)) calc(12px * var(--lfm-s)); border-radius:calc(14px * var(--lfm-s));
    background:var(--surface); border:1px solid var(--border);
}
.lfm-desc .lfm-channel.lfm-channel-card{ padding:calc(10px * var(--lfm-s)) calc(12px * var(--lfm-s)); gap:calc(10px * var(--lfm-s)); align-items:center; }
.lfm-desc .lfm-channel-card .lfm-ch-av{ margin:0; }

/* description text AND comment text: links red, #hashtags purple */
.lf-link{ color:var(--accent); text-decoration:none; word-break:break-all; }
.lf-link:hover{ text-decoration:underline; }
.lf-tag{ color:var(--tag); font-weight:500; }

/* ── comments sheet: same slide-up sheet as the description panel, plus a
   sort control up top, a scrollable comment list, a dedicated "Replies"
   screen for a top-level comment's first replies (back arrow + X), and a
   composer pinned to the bottom that is shared by both screens. Any reply
   that itself has replies (someone replied to THAT reply) shows its own
   inline "N replies" toggle instead of navigating anywhere — tapping it
   drops that nested sub-thread open right underneath it. Like (thumbs-up)
   + grey "Reply" text + a ⋯ menu (download / delete). ── */
.lfm-csheet{ display:flex; flex-direction:column; }
.lfm-cs-head{ gap:calc(8px * var(--lfm-s)); }
.lfm-cs-count{ color:var(--muted); font-weight:500; font-size:calc(.82rem * var(--lfm-s)); margin-left:calc(5px * var(--lfm-s)); }
.lfm-cs-sort{ position:relative; margin-left:auto; margin-right:calc(4px * var(--lfm-s)); }
.lfm-cs-sortbtn{
    display:flex; align-items:center; gap:calc(2px * var(--lfm-s)); padding:calc(5px * var(--lfm-s)) calc(8px * var(--lfm-s)); border-radius:calc(8px * var(--lfm-s)); border:none;
    background:var(--surface); color:rgba(var(--lfm-fg),.85); font-size:calc(.72rem * var(--lfm-s)); font-weight:600;
}
.lfm-cs-sortbtn:hover{ background:var(--surface-hover); }
.lfm-cs-sortmenu{
    position:absolute; top:calc(100% + calc(6px * var(--lfm-s))); right:0; z-index:5; display:none; min-width:calc(150px * var(--lfm-s));
    background:var(--lfm-menu,#0c0e2a); border:1px solid var(--border); border-radius:calc(12px * var(--lfm-s)); padding:calc(6px * var(--lfm-s)); box-shadow:0 10px 30px rgba(0,0,0,.5);
}
.lfm-cs-sortmenu.show{ display:block; }
.lfm-cs-sortmenu button{
    display:block; width:100%; text-align:left; padding:calc(8px * var(--lfm-s)) calc(10px * var(--lfm-s)); border:none; background:none;
    color:var(--text,#fff); font-size:calc(.78rem * var(--lfm-s)); border-radius:calc(8px * var(--lfm-s));
}
.lfm-cs-sortmenu button:hover{ background:var(--surface-hover); }
.lfm-cs-sortmenu button.active{ color:var(--accent); font-weight:600; }

/* the "Replies" screen header — thinner bar, with a bigger back arrow shifted closer to the left edge */
.lfm-cs-thead{ gap:calc(2px * var(--lfm-s)); padding:calc(4px * var(--lfm-s)) calc(10px * var(--lfm-s)) calc(4px * var(--lfm-s)) calc(4px * var(--lfm-s)); min-height:0; }
.lfm-cs-tback{
    width:calc(42px * var(--lfm-s)); height:calc(42px * var(--lfm-s)); border-radius:50%; border:none; background:none; color:var(--text,#fff); font-size:calc(1.85rem * var(--lfm-s));
    display:flex; align-items:center; justify-content:center; flex-shrink:0; margin:0 calc(2px * var(--lfm-s)) 0 calc(-4px * var(--lfm-s));
    transition:background .2s; -webkit-tap-highlight-color:transparent;
}
.lfm-cs-tback:hover{ background:var(--surface-hover); }

.lfm-cs-scroll{
    flex:1; min-height:0; overflow-y:auto; overscroll-behavior:contain; padding:calc(6px * var(--lfm-s)) calc(16px * var(--lfm-s)) calc(10px * var(--lfm-s));
    -webkit-overflow-scrolling:touch; scrollbar-width:thin; scrollbar-color:rgba(var(--lfm-fg),.25) transparent;
}
.lfm-cs-scroll::-webkit-scrollbar{ width:calc(8px * var(--lfm-s)); }
.lfm-cs-scroll::-webkit-scrollbar-thumb{ background:rgba(var(--lfm-fg),.25); border-radius:calc(999px * var(--lfm-s)); }
.lfm-cs-empty{ text-align:center; color:var(--muted); font-size:calc(.8rem * var(--lfm-s)); padding:calc(40px * var(--lfm-s)) calc(10px * var(--lfm-s)); }

/* "Remember to keep comments respectful" — sits above every comment */
.lfm-cs-note{
    display:flex; align-items:center; gap:calc(8px * var(--lfm-s)); margin:calc(8px * var(--lfm-s)) 0 calc(2px * var(--lfm-s)); padding:calc(8px * var(--lfm-s)) calc(12px * var(--lfm-s));
    border-radius:calc(12px * var(--lfm-s)); background:var(--surface); border:1px solid var(--border);
    font-size:calc(.74rem * var(--lfm-s)); line-height:1.3; color:rgba(var(--lfm-fg),.86);
}
.lfm-cs-note i{ display:flex; align-items:center; justify-content:center; font-size:calc(1rem * var(--lfm-s)); line-height:1; color:var(--muted); flex-shrink:0; }
.lfm-cs-note span{ display:block; min-width:0; }

.lfm-cs-item{ --csav:calc(32px * var(--lfm-s)); --csgap:calc(10px * var(--lfm-s)); --lfm-thread:rgba(var(--lfm-fg),.24); padding:calc(12px * var(--lfm-s)) 0; }
.lfm-cs-main{ display:flex; gap:var(--csgap); }
/* the avatar column: the profile pic, and (when replies chain below it) the thread line running down from under it */
.lfm-cs-avcol{ display:flex; flex-direction:column; align-items:center; flex-shrink:0; width:var(--csav); }
.lfm-cs-line{ display:none; flex:1; width:calc(2px * var(--lfm-s)); margin-top:calc(6px * var(--lfm-s)); border-radius:calc(2px * var(--lfm-s)); background:var(--lfm-thread); }
.lfm-cs-item.has-thread > .lfm-cs-main .lfm-cs-line{ display:block; }
.lfm-cs-item.has-thread > .lfm-cs-main .lfm-cs-body{ padding-bottom:calc(12px * var(--lfm-s)); }
/* comment profile pics: red border, same as the channel avatar */
.lfm-cs-av{
    width:var(--csav); height:var(--csav); border-radius:50%; overflow:hidden; flex-shrink:0; text-decoration:none; box-sizing:border-box;
    border:2px solid var(--accent);
    background:linear-gradient(135deg,#262b3d,#171a24); color:var(--accent);
    display:flex; align-items:center; justify-content:center; font-size:calc(.9rem * var(--lfm-s));
}
.lfm-cs-av img{ width:100%; height:100%; object-fit:cover; display:block; }
.lfm-cs-body{ flex:1; min-width:0; }
.lfm-cs-line1{ display:flex; align-items:center; flex-wrap:wrap; gap:calc(2px * var(--lfm-s)) calc(8px * var(--lfm-s)); min-width:0; }
.lfm-cs-user{ font-size:calc(.78rem * var(--lfm-s)); font-weight:500; color:rgba(var(--lfm-fg),.62); text-decoration:none; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:100%; }
/* the video owner's own comments: name inside a light pill with a tick, plus an "Owner" tag */
.lfm-cs-user.is-owner{
    display:inline-flex; align-items:center; gap:calc(3px * var(--lfm-s)); padding:0 calc(8px * var(--lfm-s)); border-radius:calc(999px * var(--lfm-s));
    background:var(--text,#fff); color:var(--lfm-on-text,#0a0c24); min-width:0; font-size:calc(.74rem * var(--lfm-s)); font-weight:500; line-height:1.4;
}
.lfm-cs-user.is-owner .lfm-cs-uname{ overflow:hidden; text-overflow:ellipsis; white-space:nowrap; min-width:0; }
.lfm-cs-user.is-owner i{ font-size:calc(.78rem * var(--lfm-s)); line-height:1; flex-shrink:0; }
.lfm-cs-ownertag{
    font-size:calc(.58rem * var(--lfm-s)); font-weight:400; line-height:1.45; color:rgba(var(--lfm-fg),.72); flex-shrink:0;
    padding:0 calc(6px * var(--lfm-s)); border-radius:calc(999px * var(--lfm-s)); border:1px solid var(--border);
}
.lfm-cs-time{ font-size:calc(.68rem * var(--lfm-s)); color:var(--muted); flex-shrink:0; }
.lfm-cs-time::before{ content:'·'; margin-right:calc(6px * var(--lfm-s)); }
.lfm-cs-pin{ font-size:calc(.64rem * var(--lfm-s)); color:var(--accent); font-weight:600; flex-shrink:0; }
/* the ⋯ button on every comment */
.lfm-cs-more{
    margin-left:auto; margin-right:calc(-6px * var(--lfm-s)); width:calc(28px * var(--lfm-s)); height:calc(28px * var(--lfm-s)); flex-shrink:0; border:none; background:none;
    color:var(--muted); font-size:calc(1.15rem * var(--lfm-s)); border-radius:50%; display:flex; align-items:center; justify-content:center;
    transition:color .2s, background .2s; -webkit-tap-highlight-color:transparent;
}
.lfm-cs-more:hover{ color:var(--text,#fff); background:var(--surface-hover); }
.lfm-cs-text{ font-size:calc(.8rem * var(--lfm-s)); line-height:1.5; color:rgba(var(--lfm-fg),.92); margin-top:calc(2px * var(--lfm-s)); overflow-wrap:anywhere; }
/* an @mention at the very start of a reply — colored, like tapping through to that person */
.lfm-mention{ color:var(--lfm-mention,#4fa8ff); font-weight:600; }
/* long comments: short version + "...Read more" */
.lfm-cs-text .lfm-cs-full{ display:none; }
.lfm-cs-text.expanded .lfm-cs-full{ display:inline; }
.lfm-cs-text.expanded .lfm-cs-short{ display:none; }
.lfm-cs-readmore{
    border:none; background:none; padding:0; margin-left:calc(2px * var(--lfm-s)); font-family:inherit; font-size:inherit;
    font-weight:400; color:rgba(var(--lfm-fg),.7); -webkit-tap-highlight-color:transparent;
}
.lfm-cs-readmore:hover{ color:var(--text,#fff); }
.lfm-cs-row2{ display:flex; align-items:center; gap:calc(18px * var(--lfm-s)); margin-top:calc(8px * var(--lfm-s)); }
.lfm-cs-like, .lfm-cs-dislike, .lfm-cs-reply{
    display:flex; align-items:center; gap:calc(6px * var(--lfm-s)); border:none; background:none; padding:0;
    color:rgba(var(--lfm-fg),.85); font-size:calc(.76rem * var(--lfm-s)); font-weight:500;
    transition:color .2s, transform .12s; -webkit-tap-highlight-color:transparent;
}
.lfm-cs-like i, .lfm-cs-dislike i{ font-size:calc(1.15rem * var(--lfm-s)); display:block; }
.lfm-cs-like:hover, .lfm-cs-dislike:hover{ color:var(--text,#fff); }
.lfm-cs-like:active, .lfm-cs-dislike:active, .lfm-cs-reply:active{ transform:scale(.9); }
.lfm-cs-like.on, .lfm-cs-dislike.on, .lfm-cs-like.on:hover, .lfm-cs-dislike.on:hover{ color:var(--accent); }
.lfm-cs-like.on i, .lfm-cs-dislike.on i{ animation:lfm-pop .34s ease; }
/* Reply is plain grey text, no icon */
.lfm-cs-reply{ color:var(--muted); font-weight:500; }
.lfm-cs-reply:hover{ color:var(--text,#fff); }

/* "creator liked this comment" — a SMALL profile pic of the creator with a red border, and a little red
   heart overlapping its bottom-right corner. Sits right after the reply button. */
.lfm-cs-crlike{ position:relative; display:inline-flex; width:calc(16px * var(--lfm-s)); height:calc(16px * var(--lfm-s)); flex-shrink:0; margin-left:calc(-2px * var(--lfm-s)); }
.lfm-cs-crav{
    width:calc(16px * var(--lfm-s)); height:calc(16px * var(--lfm-s)); border-radius:50%; overflow:hidden; box-sizing:border-box;
    border:1.5px solid var(--accent);
    background:linear-gradient(135deg,#262b3d,#171a24); color:var(--accent);
    display:flex; align-items:center; justify-content:center; font-size:calc(.5rem * var(--lfm-s));
}
.lfm-cs-crav img{ width:100%; height:100%; object-fit:cover; display:block; }
.lfm-cs-crbadge{
    position:absolute; right:calc(-5px * var(--lfm-s)); bottom:calc(-4px * var(--lfm-s)); font-size:calc(.68rem * var(--lfm-s)); line-height:1; color:var(--accent);
    filter:drop-shadow(0 0 1.5px var(--lfm-sheet,#060820)) drop-shadow(0 0 1px var(--lfm-sheet,#060820));
}

/* "· 9 replies ›" — shown under any comment that has its own replies.
   On a TOP-LEVEL comment (in the main list) it navigates into the dedicated
   Replies screen. Everywhere else (inside the Replies screen, at any depth)
   it is an inline TOGGLE instead — tapping it drops that reply's own
   sub-thread open right underneath it (see .lfm-cs-subtoggle / .lfm-cs-subthread). */
.lfm-cs-viewreplies{
    position:relative; display:flex; align-items:center; gap:calc(7px * var(--lfm-s)); height:calc(30px * var(--lfm-s));
    margin-left:calc(var(--csav) + var(--csgap));
    border:none; background:none; padding:0; color:var(--text,#fff); font-size:calc(.8rem * var(--lfm-s)); font-weight:600;
    -webkit-tap-highlight-color:transparent;
}
.lfm-cs-viewreplies::before{
    content:''; position:absolute; top:0; height:50%; box-sizing:border-box;
    left:calc(-1 * (var(--csav) / 2 + var(--csgap) + calc(1px * var(--lfm-s))));
    width:calc(var(--csav) / 2 + var(--csgap) + calc(1px * var(--lfm-s)));
    border-left:2px solid var(--lfm-thread); border-bottom:2px solid var(--lfm-thread);
    border-bottom-left-radius:calc(12px * var(--lfm-s));
}
.lfm-cs-vr-av{
    width:calc(24px * var(--lfm-s)); height:calc(24px * var(--lfm-s)); border-radius:50%; overflow:hidden; flex-shrink:0; box-sizing:border-box;
    border:1.5px solid var(--accent);
    background:linear-gradient(135deg,#262b3d,#171a24); color:var(--accent);
    display:flex; align-items:center; justify-content:center; font-size:calc(.7rem * var(--lfm-s));
}
.lfm-cs-vr-av img{ width:100%; height:100%; object-fit:cover; display:block; }
.lfm-cs-vr-dot{ opacity:.7; }
.lfm-cs-vr-chev{ font-size:calc(1.1rem * var(--lfm-s)); line-height:1; transition:transform .2s ease; }
.lfm-cs-viewreplies.open .lfm-cs-vr-chev{ transform:rotate(90deg); }
.lfm-cs-sk{ padding:calc(12px * var(--lfm-s)) 0; display:flex; gap:calc(10px * var(--lfm-s)); }

/* the sub-thread that drops down under a reply's own "N replies" toggle:
   indented under that reply's avatar, its own items reuse .lfm-cs-item so
   a reply-of-a-reply can show yet another toggle+dropdown of its own */
.lfm-cs-subthread{ margin-left:calc(var(--csav) + var(--csgap)); margin-top:calc(2px * var(--lfm-s)); }
.lfm-cs-subthread .lfm-cs-item{ padding:calc(10px * var(--lfm-s)) 0; }
.lfm-cs-subthread .lfm-cs-item:first-child{ padding-top:calc(8px * var(--lfm-s)); }
.lfm-cs-submore{
    display:block; margin:calc(2px * var(--lfm-s)) 0 calc(6px * var(--lfm-s)); padding:0; border:none; background:none;
    color:rgba(var(--lfm-fg),.7); font-size:calc(.74rem * var(--lfm-s)); font-weight:600; -webkit-tap-highlight-color:transparent;
}
.lfm-cs-submore:hover{ color:var(--text,#fff); }
.lfm-cs-sub-sk{ padding:calc(8px * var(--lfm-s)) 0; display:flex; gap:calc(8px * var(--lfm-s)); align-items:flex-start; }

/* the shared ⋯ menu (download / delete) */
.lfm-cs-menu{
    position:absolute; z-index:8; display:none; min-width:calc(180px * var(--lfm-s));
    background:var(--lfm-menu,#0c0e2a); border:1px solid var(--border); border-radius:calc(12px * var(--lfm-s)); padding:calc(6px * var(--lfm-s));
    box-shadow:0 10px 30px rgba(0,0,0,.55);
}
.lfm-cs-menu.show{ display:block; }
.lfm-cs-menu button{
    display:flex; align-items:center; gap:calc(10px * var(--lfm-s)); width:100%; text-align:left; padding:calc(10px * var(--lfm-s));
    border:none; background:none; color:var(--text,#fff); font-size:calc(.8rem * var(--lfm-s)); border-radius:calc(8px * var(--lfm-s));
}
.lfm-cs-menu button i{ font-size:calc(1.1rem * var(--lfm-s)); opacity:.85; }
.lfm-cs-menu button:hover{ background:var(--surface-hover); }
.lfm-cs-menu button.danger{ color:#ff5c5c; }

.lfm-cs-composer{
    display:flex; align-items:center; gap:calc(10px * var(--lfm-s)); padding:calc(10px * var(--lfm-s)) calc(14px * var(--lfm-s)); flex-shrink:0;
    border-top:1px solid var(--divider); background:var(--lfm-sheet,#060820);
}
.lfm-cs-my-av{
    width:calc(30px * var(--lfm-s)); height:calc(30px * var(--lfm-s)); border-radius:50%; overflow:hidden; flex-shrink:0; box-sizing:border-box;
    border:2px solid var(--accent);
    background:linear-gradient(135deg,#262b3d,#171a24); color:var(--accent);
    display:flex; align-items:center; justify-content:center; font-size:calc(.85rem * var(--lfm-s));
}
.lfm-cs-my-av img{ width:100%; height:100%; object-fit:cover; display:block; }
.lfm-cs-composer input{
    flex:1; min-width:0; border:none; outline:none; background:var(--surface); color:var(--text,#fff);
    padding:calc(9px * var(--lfm-s)) calc(14px * var(--lfm-s)); border-radius:calc(999px * var(--lfm-s)); font-size:calc(.8rem * var(--lfm-s));
}
.lfm-cs-composer input::placeholder{ color:var(--muted); }
/* send button: small round button with an up arrow — red when ready, dim when empty */
.lfm-cs-send{
    width:calc(32px * var(--lfm-s)); height:calc(32px * var(--lfm-s)); border:none; border-radius:50%; padding:0; flex-shrink:0;
    background:var(--accent); color:#fff; display:flex; align-items:center; justify-content:center;
    transition:background .2s, color .2s, opacity .2s, transform .15s; -webkit-tap-highlight-color:transparent;
}
.lfm-cs-send svg{ width:calc(18px * var(--lfm-s)); height:calc(18px * var(--lfm-s)); display:block; }
.lfm-cs-send:not(:disabled):active{ transform:scale(.88); }
.lfm-cs-send:disabled{ background:var(--surface); color:var(--muted); opacity:.75; }

/* ── the Replies screen: the tapped comment pinned at the top with a soft highlight,
   then every DIRECT reply to it underneath. Any of those replies that itself has
   replies gets its own inline dropdown (see .lfm-cs-subthread above) instead of
   navigating anywhere else. ── */
.lfm-cs-troot{
    background:rgba(var(--lfm-fg),.035); margin:0 calc(-16px * var(--lfm-s)) calc(2px * var(--lfm-s)); padding:calc(10px * var(--lfm-s)) calc(16px * var(--lfm-s)) calc(2px * var(--lfm-s));
}
.lfm-cs-tscroll .lfm-cs-item .lfm-cs-line,
.lfm-cs-troot .lfm-cs-item .lfm-cs-line{ display:block; }
.lfm-cs-tscroll .lfm-cs-item:last-child .lfm-cs-line{ display:none; }
.lfm-cs-troot .lfm-cs-item,
.lfm-cs-tscroll .lfm-cs-item{ --csav:calc(30px * var(--lfm-s)); --csgap:calc(10px * var(--lfm-s)); }

/* settings sheet rows (the sheet itself reuses the page's .lf-act-* look) */
.lfm-row .lfm-lbl{ flex:1; min-width:0; text-align:left; }
.lfm-row .lfm-val{ font-size:calc(.74rem * var(--lfm-s)); color:var(--muted); font-weight:400; white-space:nowrap; }
.lfm-row .lfm-go{ width:auto; font-size:calc(1.15rem * var(--lfm-s)); opacity:.45; margin-left:calc(-6px * var(--lfm-s)); }
.lfm-row .switch{ margin-left:auto; }

/* ═══ BOTTOM SHEETS — same design as the profile bottom sheets (post menu + video settings):
   lane-coloured sheet, thin handle, ONE rounded inner card with hairline rows, and a separate Close card. ═══ */
.lf-act-overlay{ --sh-s:calc(var(--m, 1) * 1.05); --sh-lane:#070924; --sh-card:#090b29; background:rgba(0,0,0,.6); padding:0; align-items:flex-end; justify-content:center; }
:root[data-theme="light"] .lf-act-overlay{ --sh-lane:#ffffff; --sh-card:#eef1f7; background:rgba(6,7,26,.45); }
.lf-act-overlay .lf-act-panel{
    width:100%; max-width:min(100%, 560px); max-height:80vh; max-height:80dvh; overflow-y:auto;
    display:flex; flex-direction:column; gap:calc(8px * var(--sh-s));
    background:var(--sh-lane); border:0; border-radius:0; box-shadow:none;
    padding:calc(5px * var(--sh-s)) calc(10px * var(--sh-s)) calc(env(safe-area-inset-bottom, 0px) + 10px * var(--sh-s));
    animation:lf-sheet-in .3s cubic-bezier(.32,.72,0,1) both;
}
@media (min-width:801px){ .lf-act-overlay .lf-act-panel{ border:1px solid var(--line); border-bottom:0; border-radius:10px 10px 0 0; } }
.lf-act-overlay .lf-act-handle{ display:flex; padding:calc(3px * var(--sh-s)) 0 0; flex-shrink:0; }
.lf-act-overlay .lf-act-handle span{ width:calc(30px * var(--sh-s)); height:calc(3px * var(--sh-s)); border-radius:2px; background:rgba(var(--fg),.22); }
.lf-act-overlay .lf-act-pane{ background:var(--sh-card); border-radius:calc(6px * var(--sh-s)); overflow:hidden; flex:0 1 auto; min-height:0; }
.lf-act-overlay .lf-act-head{ padding:calc(9px * var(--sh-s)) calc(12px * var(--sh-s)) calc(7px * var(--sh-s)); gap:calc(9px * var(--sh-s)); border-bottom:1px solid rgba(var(--fg),.08); }
.lf-act-overlay .lf-act-thumb{ width:calc(52px * var(--sh-s)); height:calc(30px * var(--sh-s)); border-radius:calc(3px * var(--sh-s)); }
.lf-act-overlay .lf-act-title{ font-size:calc(11.5px * var(--sh-s)); font-weight:600; line-height:1.35; }
.lf-act-overlay .lf-act-close{ display:none; }
.lf-act-overlay .lf-act-back{ font-size:calc(22px * var(--sh-s)); margin-left:calc(-4px * var(--sh-s)); }
.lf-act-overlay .lf-act-item{ gap:calc(11px * var(--sh-s)); padding:calc(9px * var(--sh-s)) calc(12px * var(--sh-s)); min-height:0; font-size:calc(12px * var(--sh-s)); font-weight:500; }
.lf-act-overlay .lf-act-item + .lf-act-item{ border-top:1px solid rgba(var(--fg),.08); }
.lf-act-overlay .lf-act-item i{ font-size:calc(17px * var(--sh-s)); width:calc(20px * var(--sh-s)); color:rgba(var(--fg),.85); }
.lf-act-overlay .lf-act-item.danger, .lf-act-overlay .lf-act-item.danger i{ color:#ff6b6b; }
.lf-act-overlay .lf-act-sub{ font-size:calc(10.5px * var(--sh-s)); padding:calc(9px * var(--sh-s)) calc(12px * var(--sh-s)) calc(7px * var(--sh-s)); }
.lf-act-overlay .lf-reasons{ border-top:0; }
.lf-act-overlay .lf-reason{ padding:calc(9px * var(--sh-s)) calc(12px * var(--sh-s)); font-size:calc(11.5px * var(--sh-s)); }
.lf-act-overlay .lf-reason + .lf-reason{ border-top:1px solid rgba(var(--fg),.08); }
.lf-act-overlay .lf-report-details{ width:calc(100% - 24px * var(--sh-s)); margin:calc(10px * var(--sh-s)) calc(12px * var(--sh-s)) 0; font-size:calc(11px * var(--sh-s)); }
.lf-act-overlay .lf-act-pane .gc-btn{ width:calc(100% - 24px * var(--sh-s)); margin:calc(10px * var(--sh-s)) calc(12px * var(--sh-s)) calc(10px * var(--sh-s)); }
.lf-act-cancel{
    display:flex; align-items:center; justify-content:center; width:100%; flex-shrink:0;
    padding:calc(10px * var(--sh-s)); background:var(--sh-card); border:0; border-radius:calc(6px * var(--sh-s));
    color:rgba(var(--fg),.85); font-size:calc(12px * var(--sh-s)); font-weight:600; font-family:'Poppins',sans-serif; cursor:pointer;
    transition:background .2s, color .2s;
}
.lf-act-cancel:hover{ color:var(--accent); }

.lfm-svgi svg{ display:block; width:1em; height:1em; margin:0 auto; }

/* ── expanded (fullscreen) and phone-on-its-side ── */
.lfm-overlay.is-fs{ background:#000; }
.lfm-overlay.is-fs .lfm-col{ max-width:none; border:none; background:#000; }
.lfm-overlay.is-fs .lfm-player{ position:absolute; inset:0; aspect-ratio:auto; height:100%; }
.lfm-overlay.is-fs .lfm-scroll, .lfm-overlay.is-fs .lfm-desc{ display:none; }
@media (orientation:landscape) and (max-height:480px){
    .lfm-col{ max-width:none; border:none; }
    .lfm-player{ position:absolute; inset:0; aspect-ratio:auto; height:100%; }
    .lfm-scroll, .lfm-desc{ display:none; }
}

/* ══════════════════════════════════════════════════════════════
   MINI PLAYER — the video shrunk into the bottom-right corner (YouTube style).
   The whole overlay turns into a small floating card, so the page behind stays usable.
   If your page has a bottom bar the card should sit above, raise  --lfm-mini-bottom  (default 12px).
   ══════════════════════════════════════════════════════════════ */
@keyframes lfm-mini-in{ from{ opacity:0; transform:translateY(calc(22px * var(--lfm-s))) scale(.92); } to{ opacity:1; transform:none; } }
.lfm-overlay.mini{
    inset:auto; right:calc(12px * var(--lfm-s)); bottom:calc(var(--lfm-mini-bottom, calc(12px * var(--lfm-s))) + env(safe-area-inset-bottom, calc(0px * var(--lfm-s))));
    width:clamp(calc(180px * var(--lfm-s)), 46vw, calc(320px * var(--lfm-s))); height:auto; z-index:1400;
    background:#000; border-radius:calc(12px * var(--lfm-s)); overflow:hidden;
    box-shadow:0 10px 30px rgba(0,0,0,.55), 0 0 0 1px rgba(255,255,255,.1);
    animation:lfm-mini-in .22s ease;
}
.lfm-overlay.mini .lfm-col{ max-width:none; height:auto; margin:0; display:block; border:none; background:transparent; overflow:hidden; }
.lfm-overlay.mini .lfm-player{ position:relative; inset:auto; height:auto; aspect-ratio:16/9; z-index:auto; cursor:pointer; touch-action:none; }
.lfm-overlay.mini.dragging, .lfm-overlay.mini.dragging .lfm-player{ cursor:grabbing; }
.lfm-overlay.mini .lfm-scroll, .lfm-overlay.mini .lfm-desc, .lfm-overlay.mini .lfm-controls,
.lfm-overlay.mini .lfm-next, .lfm-overlay.mini .lfm-fx{ display:none !important; }
.lfm-overlay.mini .lfm-wheel{ width:calc(26px * var(--lfm-s)); height:calc(26px * var(--lfm-s)); border-width:3px; }
.lfm-overlay.mini .lfm-err{ padding:calc(6px * var(--lfm-s)); }
.lfm-overlay.mini .lfm-err > div, .lfm-overlay.mini .lfm-err button{ display:none; }
.lfm-overlay.mini .lfm-err i{ font-size:calc(1.4rem * var(--lfm-s)); }
/* thin red progress line along the bottom edge */
.lfm-overlay.mini .lfm-seek{ height:calc(3px * var(--lfm-s)); z-index:4; pointer-events:none !important; }
.lfm-overlay.mini .lfm-rail{ left:0; right:0; bottom:0; height:calc(3px * var(--lfm-s)); border-radius:0; }
.lfm-overlay.mini .lfm-buf, .lfm-overlay.mini .lfm-played{ border-radius:0; }
.lfm-overlay.mini .lfm-dot{ display:none; }

/* double-tap the mini player → it gets wider. Phones / tablets: the screen width minus a small gap on each side.
   Normal (desktop) screen: a centered card a bit narrower than the page's middle layout
   (change --lfm-wide-w to make it wider or narrower). Double-tap again to shrink it. */
.lfm-overlay.mini.mini-wide{
    left:0 !important; right:0 !important; margin:0 auto;
    width:min(calc(100% - calc(24px * var(--lfm-s))), var(--lfm-wide-w, calc(480px * var(--lfm-s)))) !important;
}
.lfm-overlay.mini.mini-wide .lfm-mini-ui{ padding:calc(8px * var(--lfm-s)); }
.lfm-overlay.mini.mini-wide .lfm-mini-btn{ width:calc(40px * var(--lfm-s)); height:calc(40px * var(--lfm-s)); font-size:calc(1.4rem * var(--lfm-s)); }
.lfm-overlay.mini.mini-wide .lfm-rail, .lfm-overlay.mini.mini-wide .lfm-seek{ height:calc(4px * var(--lfm-s)); }
@media (max-width:790px){ .lfm-overlay.mini.mini-wide{ width:calc(100% - calc(24px * var(--lfm-s))) !important; } }

/* pause / play in the top-left corner, x in the top-right corner */
.lfm-mini-ui{ display:none; position:absolute; inset:0; z-index:9; pointer-events:none; }
.lfm-overlay.mini .lfm-mini-ui{ display:flex; align-items:flex-start; justify-content:space-between; padding:calc(6px * var(--lfm-s)); }
.lfm-mini-btn{
    pointer-events:auto; width:calc(34px * var(--lfm-s)); height:calc(34px * var(--lfm-s)); border-radius:50%; border:none; padding:0; flex-shrink:0;
    background:rgba(0,0,0,.26); color:#fff; font-size:calc(1.25rem * var(--lfm-s));
    display:flex; align-items:center; justify-content:center;
    transition:transform .15s, background .2s; -webkit-tap-highlight-color:transparent;
}
.lfm-mini-btn i{ display:block; filter:drop-shadow(0 0 2px rgba(0,0,0,.6)); }
.lfm-mini-btn:hover{ background:rgba(0,0,0,.42); }
.lfm-mini-btn:active{ transform:scale(.88); }

/* drag-down-to-minimize hint: while the whole modal is being dragged down (not yet released),
   it scales/fades slightly toward the corner so the gesture reads clearly */
.lfm-col.lfm-dragdown{ transition:none !important; }

@media (prefers-reduced-motion: reduce){
    .lfm-overlay.open, .lfm-act.on i, .lfm-cs-like.on i{ animation:none; }
    .lfm-desc, .lfm-desc.open, .lfm-controls{ transition:none; }
    .lfm-glow, .lfm-ring, .lfm-spark{ display:none; }
    .lfm-thumb{ animation:lfm-thumb-fade .8s ease forwards; }
}

/* ══════════════════════════════════════════════════════════════
   DESKTOP: show controls on hover, not on click
   (click-to-toggle stays for touch/mobile — see the media query below and the JS pointer-type check)
   ══════════════════════════════════════════════════════════════ */
@media (hover:hover) and (pointer:fine){
    .lfm-player.lfm-hover-ctl .lfm-controls{ opacity:1; pointer-events:auto; }
}

/* ══════════════════════════════════════════════════════════════
   RESPONSIVE — 790 / 490 / 390 / 330
   ══════════════════════════════════════════════════════════════ */
@media (max-width:790px){
    .lfm-overlay{ --lfm-w:100vw; --lfm-seek-x:calc(7px * var(--lfm-s)); }
    .lfm-col{ max-width:100%; }
    .lfm-track-btn{ font-size:calc(12px * var(--lfm-s)); }

    /* scrollbar stays hidden on responsive/mobile, as before — description and comments follow the same rule */
    .lfm-scroll, .lfm-desc-scroll, .lfm-cs-scroll{ scrollbar-width:none; }
    .lfm-scroll::-webkit-scrollbar, .lfm-desc-scroll::-webkit-scrollbar, .lfm-cs-scroll::-webkit-scrollbar{ display:none; }
}

@media (max-width:490px){
    .lfm-overlay{ --lfm-seek-x:calc(6px * var(--lfm-s)); }
    .lfm-fx{ --hs:calc(84px * var(--lfm-s)); }
    .lfm-top{ padding:calc(6px * var(--lfm-s)) calc(6px * var(--lfm-s)) 0; }
    .lfm-chev{ width:calc(40px * var(--lfm-s)); height:calc(40px * var(--lfm-s)); font-size:calc(1.85rem * var(--lfm-s)); }
    .lfm-gear{ width:calc(40px * var(--lfm-s)); height:calc(40px * var(--lfm-s)); font-size:calc(1.56rem * var(--lfm-s)); }
    .lfm-mute{ width:calc(40px * var(--lfm-s)); height:calc(40px * var(--lfm-s)); font-size:calc(1.3rem * var(--lfm-s)); }
    .lfm-skip{ width:calc(46px * var(--lfm-s)); height:calc(46px * var(--lfm-s)); font-size:calc(2rem * var(--lfm-s)); }
    .lfm-play{ width:calc(58px * var(--lfm-s)); height:calc(58px * var(--lfm-s)); font-size:calc(2.3rem * var(--lfm-s)); }
    .lfm-bottom{ padding:0 calc(8px * var(--lfm-s)) 0 calc(10px * var(--lfm-s)); bottom:calc(14px * var(--lfm-s)); }
    .lfm-time{ font-size:calc(.68rem * var(--lfm-s)); padding:calc(3px * var(--lfm-s)) calc(9px * var(--lfm-s)); }
    .lfm-fs{ width:calc(34px * var(--lfm-s)); height:calc(34px * var(--lfm-s)); font-size:calc(1.2rem * var(--lfm-s)); }
    .lfm-next{ left:calc(8px * var(--lfm-s)); right:calc(8px * var(--lfm-s)); bottom:calc(34px * var(--lfm-s)); padding:calc(8px * var(--lfm-s)) calc(10px * var(--lfm-s)); }

    .lfm-info{ padding:calc(11px * var(--lfm-s)) calc(12px * var(--lfm-s)) 0; }
    .lfm-title{ font-size:calc(.9rem * var(--lfm-s)); }
    .lfm-meta{ font-size:calc(.68rem * var(--lfm-s)); }
    .lfm-channel{ padding:calc(12px * var(--lfm-s)) calc(12px * var(--lfm-s)) calc(2px * var(--lfm-s)); gap:calc(9px * var(--lfm-s)); }
    .lfm-ch-av{ width:calc(34px * var(--lfm-s)); height:calc(34px * var(--lfm-s)); border-width:1.8px; font-size:calc(.9rem * var(--lfm-s)); }
    .lfm-ch-name{ font-size:calc(.78rem * var(--lfm-s)); }
    .lfm-ch-nick{ font-size:calc(.66rem * var(--lfm-s)); }
    .lfm-track-btn{ font-size:calc(11.5px * var(--lfm-s)); text-decoration-thickness:1.15px; }
    .lfm-actions{ padding:calc(12px * var(--lfm-s)) calc(6px * var(--lfm-s)) calc(10px * var(--lfm-s)); }
    .lfm-act{ height:calc(48px * var(--lfm-s)); font-size:calc(.64rem * var(--lfm-s)); gap:calc(4px * var(--lfm-s)); }
    .lfm-act i{ font-size:calc(1.2rem * var(--lfm-s)); }
    .lfm-actions-divider{ height:calc(2px * var(--lfm-s)); }
    .lfm-comments{ width:calc(100% - calc(20px * var(--lfm-s))); margin:calc(8px * var(--lfm-s)) calc(10px * var(--lfm-s)) calc(2px * var(--lfm-s)); padding:calc(7px * var(--lfm-s)) calc(12px * var(--lfm-s)); }
    .lfm-cm-head{ font-size:calc(.74rem * var(--lfm-s)); }
    .lfm-cm-text{ font-size:calc(.72rem * var(--lfm-s)); }
    .lfm-listwrap{ margin-top:calc(12px * var(--lfm-s)); }
    .lfm-sk-row{ padding:calc(14px * var(--lfm-s)) calc(12px * var(--lfm-s)) calc(2px * var(--lfm-s)); }
    .lfm-sk-actions{ padding:calc(12px * var(--lfm-s)) calc(6px * var(--lfm-s)) calc(10px * var(--lfm-s)); }
    .lfm-sk-chrow{ padding:calc(12px * var(--lfm-s)) calc(12px * var(--lfm-s)) calc(2px * var(--lfm-s)); }

    .lfm-desc-head{ padding:calc(4px * var(--lfm-s)) calc(8px * var(--lfm-s)) calc(10px * var(--lfm-s)) calc(12px * var(--lfm-s)); }
    .lfm-desc-title{ font-size:calc(.9rem * var(--lfm-s)); }
    .lfm-desc-scroll{ padding:calc(14px * var(--lfm-s)) calc(12px * var(--lfm-s)) calc(22px * var(--lfm-s)); }
    .lfm-desc-vtitle{ font-size:calc(.92rem * var(--lfm-s)); }
    .lfm-stats{ gap:calc(8px * var(--lfm-s)); }
    .lfm-stat{ padding:calc(10px * var(--lfm-s)) calc(4px * var(--lfm-s)); border-radius:calc(12px * var(--lfm-s)); }
    .lfm-stat b{ font-size:calc(.96rem * var(--lfm-s)); }
    .lfm-desc-box{ padding:calc(12px * var(--lfm-s)); }
    .lfm-desc-text{ font-size:calc(.74rem * var(--lfm-s)); }
    .lfm-row .lfm-val{ font-size:calc(.7rem * var(--lfm-s)); }

    .lfm-cs-sortbtn{ font-size:calc(.68rem * var(--lfm-s)); padding:calc(4px * var(--lfm-s)) calc(7px * var(--lfm-s)); }
    .lfm-cs-scroll{ padding:calc(4px * var(--lfm-s)) calc(12px * var(--lfm-s)) calc(8px * var(--lfm-s)); }
    .lfm-cs-note{ font-size:calc(.71rem * var(--lfm-s)); padding:calc(9px * var(--lfm-s)) calc(10px * var(--lfm-s)); }
    .lfm-cs-item{ --csav:calc(28px * var(--lfm-s)); --csgap:calc(8px * var(--lfm-s)); padding:calc(10px * var(--lfm-s)) 0; }
    .lfm-cs-text{ font-size:calc(.76rem * var(--lfm-s)); }
    .lfm-cs-thead{ padding:calc(3px * var(--lfm-s)) calc(8px * var(--lfm-s)) calc(3px * var(--lfm-s)) calc(2px * var(--lfm-s)); }
    .lfm-cs-tback{ width:calc(40px * var(--lfm-s)); height:calc(40px * var(--lfm-s)); font-size:calc(1.75rem * var(--lfm-s)); margin:0 calc(1px * var(--lfm-s)) 0 calc(-3px * var(--lfm-s)); }
    .lfm-cs-composer{ padding:calc(8px * var(--lfm-s)) calc(12px * var(--lfm-s)); }
    .lfm-cs-composer input{ padding:calc(8px * var(--lfm-s)) calc(12px * var(--lfm-s)); font-size:calc(.76rem * var(--lfm-s)); }
    .lfm-cs-my-av{ width:calc(28px * var(--lfm-s)); height:calc(28px * var(--lfm-s)); }
    .lfm-cs-send{ width:calc(30px * var(--lfm-s)); height:calc(30px * var(--lfm-s)); }
    .lfm-cs-send svg{ width:calc(17px * var(--lfm-s)); height:calc(17px * var(--lfm-s)); }
}

@media (max-width:390px){
    .lfm-overlay{ --lfm-seek-x:calc(6px * var(--lfm-s)); }
    .lfm-skip{ width:calc(42px * var(--lfm-s)); height:calc(42px * var(--lfm-s)); font-size:calc(1.85rem * var(--lfm-s)); }
    .lfm-play{ width:calc(54px * var(--lfm-s)); height:calc(54px * var(--lfm-s)); font-size:calc(2.15rem * var(--lfm-s)); }
    .lfm-center{ gap:clamp(calc(16px * var(--lfm-s)), 7vw, calc(30px * var(--lfm-s))); }
    .lfm-title{ font-size:calc(.86rem * var(--lfm-s)); }
    .lfm-track-btn{ font-size:calc(11px * var(--lfm-s)); text-decoration-thickness:1px; }
    .lfm-actions{ padding:calc(10px * var(--lfm-s)) calc(4px * var(--lfm-s)) calc(8px * var(--lfm-s)); }
    .lfm-act{ font-size:calc(.6rem * var(--lfm-s)); }
    .lfm-stat b{ font-size:calc(.9rem * var(--lfm-s)); }
    .lfm-stat span{ font-size:calc(.62rem * var(--lfm-s)); }
    .lfm-next-title{ font-size:calc(.72rem * var(--lfm-s)); }
}

@media (max-width:330px){
    .lfm-overlay{ --lfm-seek-x:calc(6px * var(--lfm-s)); }
    .lfm-fx{ --hs:calc(70px * var(--lfm-s)); }
    .lfm-top-right{ gap:calc(4px * var(--lfm-s)); }
    .lfm-chev, .lfm-gear, .lfm-mute{ width:calc(36px * var(--lfm-s)); height:calc(36px * var(--lfm-s)); }
    .lfm-chev{ font-size:calc(1.7rem * var(--lfm-s)); }
    .lfm-mute{ font-size:calc(1.2rem * var(--lfm-s)); }
    .lfm-gear{ font-size:calc(1.44rem * var(--lfm-s)); }
    .lfm-ap{ width:calc(36px * var(--lfm-s)); height:calc(20px * var(--lfm-s)); }
    .lfm-ap-knob{ width:calc(18px * var(--lfm-s)); height:calc(18px * var(--lfm-s)); font-size:calc(.74rem * var(--lfm-s)); }
    .lfm-ap.on .lfm-ap-knob{ transform:translateX(calc(16px * var(--lfm-s))); }
    .lfm-skip{ width:calc(38px * var(--lfm-s)); height:calc(38px * var(--lfm-s)); font-size:calc(1.7rem * var(--lfm-s)); }
    .lfm-play{ width:calc(50px * var(--lfm-s)); height:calc(50px * var(--lfm-s)); font-size:calc(2rem * var(--lfm-s)); }
    .lfm-time{ font-size:calc(.64rem * var(--lfm-s)); padding:calc(2px * var(--lfm-s)) calc(8px * var(--lfm-s)); }
    .lfm-fs{ width:calc(32px * var(--lfm-s)); height:calc(32px * var(--lfm-s)); font-size:calc(1.1rem * var(--lfm-s)); }
    .lfm-next .lfm-next-btn.ghost{ display:none; }
    .lfm-info{ padding:calc(10px * var(--lfm-s)) calc(10px * var(--lfm-s)) 0; }
    .lfm-title{ font-size:calc(.82rem * var(--lfm-s)); }
    .lfm-meta{ font-size:calc(.64rem * var(--lfm-s)); }
    .lfm-channel{ padding:calc(11px * var(--lfm-s)) calc(10px * var(--lfm-s)) calc(2px * var(--lfm-s)); gap:calc(8px * var(--lfm-s)); }
    .lfm-ch-av{ width:calc(30px * var(--lfm-s)); height:calc(30px * var(--lfm-s)); font-size:calc(.8rem * var(--lfm-s)); }
    .lfm-ch-name{ font-size:calc(.74rem * var(--lfm-s)); }
    .lfm-ch-nick{ font-size:calc(.62rem * var(--lfm-s)); }
    .lfm-track-btn{ font-size:calc(10.5px * var(--lfm-s)); text-decoration-thickness:1px; }
    .lfm-actions{ padding:calc(9px * var(--lfm-s)) calc(4px * var(--lfm-s)) calc(7px * var(--lfm-s)); }
    .lfm-act{ font-size:calc(.58rem * var(--lfm-s)); height:calc(44px * var(--lfm-s)); }
    .lfm-act i{ font-size:calc(1.05rem * var(--lfm-s)); }
    .lfm-comments{ width:calc(100% - calc(16px * var(--lfm-s))); margin:calc(7px * var(--lfm-s)) calc(8px * var(--lfm-s)) calc(2px * var(--lfm-s)); padding:calc(6px * var(--lfm-s)) calc(10px * var(--lfm-s)); }
    .lfm-desc-scroll{ padding:calc(12px * var(--lfm-s)) calc(10px * var(--lfm-s)) calc(20px * var(--lfm-s)); }
    .lfm-stats{ gap:calc(6px * var(--lfm-s)); }
    .lfm-stat b{ font-size:calc(.84rem * var(--lfm-s)); }
    .lfm-desc-text{ font-size:calc(.7rem * var(--lfm-s)); }
    .lfm-cs-thead{ padding:calc(2px * var(--lfm-s)) calc(6px * var(--lfm-s)) calc(2px * var(--lfm-s)) calc(2px * var(--lfm-s)); }
    .lfm-cs-tback{ width:calc(38px * var(--lfm-s)); height:calc(38px * var(--lfm-s)); font-size:calc(1.6rem * var(--lfm-s)); margin:0 0 0 calc(-3px * var(--lfm-s)); }
}
/* ═══ PLAYER: text always white on the black video (also in light mode) ═══ */
.lfm-player, .lfm-controls, .lfm-time, .lfm-next{ color:#fff; }
/* ═══ VERIFIED BADGE — always red (same flower badge as the profile page) ═══ */
.lfm-vb{ width:1em; height:1em; flex-shrink:0; display:inline-block; vertical-align:-.15em; margin-left:calc(3px * var(--lfm-s)); color:var(--red,#FF2D2D); }
.lfm-ch-namerow .lfm-vb{ width:calc(.82rem * var(--lfm-s)); height:calc(.82rem * var(--lfm-s)); margin-left:calc(-2px * var(--lfm-s)); }
.lfm-cs-user .lfm-vb{ width:calc(.82rem * var(--lfm-s)); height:calc(.82rem * var(--lfm-s)); margin-left:calc(2px * var(--lfm-s)); }
.lfm-cs-user.is-owner .lfm-vb{ margin-left:0; }
.lfm-ch-name{ display:inline-flex; align-items:center; gap:calc(3px * var(--lfm-s)); }
/* ═══ ACTION ROW: the immersive viewer's icons — same drawings, same size; heart-shaped like, lucide heart-x dislike ═══ */
:root{ --lfm-ics:1; --lfm-ico:calc(clamp(17px, 1.06vw + 13.5px, 22px) * 1.166 * var(--lfm-ics)); }
@media (max-width:768px){ :root{ --lfm-ics:1.155; } }
.lfm-actions .lfm-act i{ font-size:var(--lfm-ico); width:var(--lfm-ico); height:var(--lfm-ico); display:flex; align-items:center; justify-content:center; line-height:1; }
.lfm-actions .lfm-act i.lfm-ic svg{ display:block; width:100%; height:100%; overflow:visible; }
.lfm-actions .lfm-act.on{ color:var(--red,#FF2D2D); }
.lfm-actions .lfm-act.on .lfm-fillable svg{ fill:currentColor; }
</style>

<svg width="0" height="0" style="position:absolute;width:0;height:0;overflow:hidden" aria-hidden="true" focusable="false">
    <defs><path id="lfm-vb-p" fill-rule="evenodd" d="M245.9 128.0L245.5 132.6L244.2 137.1L242.3 141.5L239.7 145.7L236.5 149.6L233.0 153.2L229.3 156.6L225.6 159.7L221.9 162.6L218.5 165.5L215.4 168.3L212.7 171.2L210.5 174.2L208.7 177.5L207.3 181.0L206.3 184.9L205.4 189.1L204.7 193.5L204.0 198.2L203.1 203.1L201.9 208.0L200.4 212.8L198.4 217.4L196.0 221.6L193.0 225.3L189.5 228.4L185.6 230.8L181.3 232.5L176.6 233.5L171.8 233.7L166.9 233.4L161.9 232.4L157.1 231.1L152.4 229.5L147.8 227.8L143.5 226.1L139.4 224.6L135.5 223.5L131.7 222.8L128.0 222.5L124.3 222.8L120.5 223.5L116.6 224.6L112.5 226.1L108.2 227.8L103.6 229.5L98.9 231.1L94.1 232.4L89.1 233.4L84.2 233.7L79.4 233.5L74.7 232.5L70.4 230.8L66.5 228.4L63.0 225.3L60.0 221.6L57.6 217.4L55.6 212.8L54.1 208.0L52.9 203.1L52.0 198.2L51.3 193.5L50.6 189.1L49.7 184.9L48.7 181.0L47.3 177.5L45.5 174.2L43.3 171.2L40.6 168.3L37.5 165.5L34.1 162.6L30.4 159.7L26.7 156.6L23.0 153.2L19.5 149.6L16.3 145.7L13.7 141.5L11.8 137.1L10.5 132.6L10.1 128.0L10.5 123.4L11.8 118.9L13.7 114.5L16.3 110.3L19.5 106.4L23.0 102.8L26.7 99.4L30.4 96.3L34.1 93.4L37.5 90.5L40.6 87.7L43.3 84.8L45.5 81.8L47.3 78.5L48.7 75.0L49.7 71.1L50.6 66.9L51.3 62.5L52.0 57.8L52.9 52.9L54.1 48.0L55.6 43.2L57.6 38.6L60.0 34.4L63.0 30.7L66.5 27.6L70.4 25.2L74.7 23.5L79.4 22.5L84.2 22.3L89.1 22.6L94.1 23.6L98.9 24.9L103.6 26.5L108.2 28.2L112.5 29.9L116.6 31.4L120.5 32.5L124.3 33.2L128.0 33.5L131.7 33.2L135.5 32.5L139.4 31.4L143.5 29.9L147.8 28.2L152.4 26.5L157.1 24.9L161.9 23.6L166.9 22.6L171.8 22.3L176.6 22.5L181.3 23.5L185.6 25.2L189.5 27.6L193.0 30.7L196.0 34.4L198.4 38.6L200.4 43.2L201.9 48.0L203.1 52.9L204.0 57.8L204.7 62.5L205.4 66.9L206.3 71.1L207.3 75.0L208.7 78.5L210.5 81.8L212.7 84.8L215.4 87.7L218.5 90.5L221.9 93.4L225.6 96.3L229.3 99.4L233.0 102.8L236.5 106.4L239.7 110.3L242.3 114.5L244.2 118.9L245.5 123.4ZM84.1 131.9Q78.2 137.8 84.1 143.6L108.3 167.9Q114.1 173.7 119.9 167.8L174.2 111.6Q179.9 105.6 174.0 99.9L170.0 96.1Q164.1 90.4 158.4 96.3L119.6 136.4Q113.9 142.3 108.0 136.5L99.6 128.1Q93.8 122.2 87.9 128.1Z"/></defs>
    <symbol id="lfm-vb" viewBox="0 0 256 256"><use href="#lfm-vb-p" fill="currentColor"/></symbol>
</svg>

<!-- shared gradient used by the double-tap thumbs-up animation -->
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <defs>
        <linearGradient id="lfmHG" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stop-color="#ff7a9c"/>
            <stop offset=".5" stop-color="#ff2b55"/>
            <stop offset="1" stop-color="#d40a3a"/>
        </linearGradient>
    </defs>
</svg>

<!-- ══ LONG FORM MODAL ══ -->
<div class="lfm-overlay" id="lfmOverlay" role="dialog" aria-modal="true" aria-label="Video player">
    <div class="lfm-col" id="lfmCol">

        <!-- ── PLAYER (stays put while everything below scrolls) ── -->
        <div class="lfm-player" id="lfmPlayer">
            <video class="lfm-video" id="lfmVideo" playsinline webkit-playsinline preload="metadata"></video>

            <div class="lfm-wheel-wrap show" id="lfmWheel"><div class="lfm-wheel"></div></div>

            <div class="lfm-err" id="lfmErr">
                <i class="ri-error-warning-line"></i>
                <div>This video can&rsquo;t be played right now.</div>
                <button type="button" id="lfmRetry">Try again</button>
            </div>

            <div class="lfm-next" id="lfmNext">
                <div class="lfm-next-txt">
                    <div class="lfm-next-lbl" id="lfmNextLbl">Up next</div>
                    <div class="lfm-next-title" id="lfmNextTitle"></div>
                </div>
                <button type="button" class="lfm-next-btn" id="lfmNextGo">Play</button>
                <button type="button" class="lfm-next-btn ghost" id="lfmNextCancel">Cancel</button>
            </div>

            <!-- shown when you tap the video (or, with a mouse, when you hover it) -->
            <div class="lfm-controls" id="lfmControls">
                <div class="lfm-top">
                    <button type="button" class="lfm-btn lfm-chev" id="lfmChev" aria-label="Minimize video"><i class="ri-arrow-down-s-line"></i></button>
                    <div class="lfm-top-right">
                        <label class="lfm-ap" id="lfmApWrap" aria-label="Autoplay">
                            <input type="checkbox" id="lfmAp" checked>
                            <span class="lfm-ap-knob"><i class="ri-play-fill"></i></span>
                        </label>
                        <button type="button" class="lfm-btn lfm-mute" id="lfmMute" aria-label="Mute" aria-pressed="false"><i class="dz-snd on"></i></button>
                        <button type="button" class="lfm-btn lfm-gear" id="lfmGear" aria-label="Settings" aria-haspopup="dialog"><i class="ri-settings-3-line"></i></button>
                    </div>
                </div>
                <div class="lfm-center">
                    <button type="button" class="lfm-btn lfm-skip" id="lfmBack10" aria-label="Rewind 10 seconds"><i class="ri-replay-10-line"></i></button>
                    <button type="button" class="lfm-btn lfm-play" id="lfmPlay" aria-label="Pause"><i class="ri-pause-fill"></i></button>
                    <button type="button" class="lfm-btn lfm-skip" id="lfmFwd10" aria-label="Forward 10 seconds"><i class="ri-forward-10-line"></i></button>
                </div>
                <div class="lfm-bottom">
                    <div class="lfm-time"><span id="lfmCur">0:00</span><span class="sep">/</span><span id="lfmDur">0:00</span></div>
                    <button type="button" class="lfm-btn lfm-fs" id="lfmFs" aria-label="Expand video"><i class="ri-fullscreen-line"></i></button>
                </div>
            </div>

            <div class="lfm-seek" id="lfmSeek" aria-label="Seek">
                <div class="lfm-rail" id="lfmRail">
                    <div class="lfm-buf" id="lfmBuf"></div>
                    <div class="lfm-played" id="lfmPlayed"></div>
                    <div class="lfm-dot" id="lfmDot"></div>
                </div>
            </div>

            <!-- shown instead of the big controls when the video is shrunk to the corner -->
            <div class="lfm-mini-ui" id="lfmMiniUi">
                <button type="button" class="lfm-mini-btn" id="lfmMiniPlay" aria-label="Pause"><i class="ri-pause-fill"></i></button>
                <button type="button" class="lfm-mini-btn" id="lfmMiniClose" aria-label="Close video"><i class="ri-close-line"></i></button>
            </div>

            <!-- double-tap like animation is drawn here -->
            <div class="lfm-fx" id="lfmFx" aria-hidden="true"></div>
        </div>

        <!-- ── EVERYTHING UNDER THE VIDEO ── -->
        <div class="lfm-scroll" id="lfmScroll">

            <!-- skeleton while the details load — mirrors the real layout below:
                 title → actions row (evenly spaced, icon over label) → divider → channel row
                 (avatar + name/@nickname stacked + track placeholder) → comments card -->
            <div id="lfmSkel" aria-hidden="true">
                <div style="padding:14px 16px 0;">
                    <div class="sk-shimmer lfm-sk" style="height:15px;width:92%;"></div>
                    <div class="sk-shimmer lfm-sk" style="height:15px;width:64%;margin-top:8px;"></div>
                    <div class="sk-shimmer lfm-sk" style="height:10px;width:44%;margin-top:12px;"></div>
                </div>
                <div class="lfm-sk-actions">
                    <div class="lfm-sk-act"><div class="sk-shimmer lfm-sk-act-icon"></div><div class="sk-shimmer lfm-sk-act-label"></div></div>
                    <div class="lfm-sk-act"><div class="sk-shimmer lfm-sk-act-icon"></div><div class="sk-shimmer lfm-sk-act-label"></div></div>
                    <div class="lfm-sk-act"><div class="sk-shimmer lfm-sk-act-icon"></div><div class="sk-shimmer lfm-sk-act-label"></div></div>
                    <div class="lfm-sk-act"><div class="sk-shimmer lfm-sk-act-icon"></div><div class="sk-shimmer lfm-sk-act-label"></div></div>
                    <div class="lfm-sk-act"><div class="sk-shimmer lfm-sk-act-icon"></div><div class="sk-shimmer lfm-sk-act-label"></div></div>
                    <div class="lfm-sk-act"><div class="sk-shimmer lfm-sk-act-icon"></div><div class="sk-shimmer lfm-sk-act-label"></div></div>
                    <div class="lfm-sk-act"><div class="sk-shimmer lfm-sk-act-icon"></div><div class="sk-shimmer lfm-sk-act-label"></div></div>
                </div>
                <div class="lfm-sk-divider"></div>
                <div class="lfm-sk-chrow">
                    <div class="sk-shimmer" style="width:38px;height:38px;border-radius:50%;flex-shrink:0;"></div>
                    <div class="lfm-sk-chinfo">
                        <div style="display:flex;align-items:center;gap:8px;">
                            <div class="sk-shimmer lfm-sk-chname"></div>
                            <div class="sk-shimmer lfm-sk-track"></div>
                        </div>
                        <div class="sk-shimmer lfm-sk-chnick"></div>
                    </div>
                </div>
                <div style="padding:14px 16px 0;"><div class="sk-shimmer" style="height:70px;border-radius:14px;"></div></div>
            </div>

            <div id="lfmBody" style="display:none;">
                <!-- title + @nickname + views + ...more -->
                <div class="lfm-info" id="lfmInfo" role="button" tabindex="0" aria-label="Open description">
                    <h2 class="lfm-title" id="lfmTitle"></h2>
                    <div class="lfm-meta">
                        <span class="lfm-meta-user" id="lfmMetaUser"></span><span>&middot;</span>
                        <span id="lfmViews"></span>
                        <span id="lfmMetaLikeSep">&middot;</span><span id="lfmMetaLikes"></span>
                        <span class="lfm-more">...more</span>
                    </div>
                </div>

                <!-- like, dislike, save, comments, share, report — ABOVE the channel row, evenly spaced -->
                <div class="lfm-actions" id="lfmActions">
                    <button type="button" class="lfm-act" id="lfmLike" aria-label="Like"><i class="lfm-ic lfm-fillable"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg></i><span id="lfmLikeN">0</span></button>
                    <button type="button" class="lfm-act" id="lfmDislike" aria-label="Dislike"><i class="lfm-ic"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="m15.5 12.5 5 5"/><path d="m20.5 12.5-5 5"/><path d="M21.955 8.774a5.5 5.5 0 0 0-9.546-2.95.6.6 0 0 1-.818 0A5.5 5.5 0 0 0 2 9.5c0 2.3 1.5 4 3 5.5l5.508 5.332a2 2 0 0 0 2.57.352"/></svg></i><span id="lfmDislikeN">0</span></button>
                    <button type="button" class="lfm-act" id="lfmSave" aria-label="Save"><i class="lfm-ic lfm-fillable"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M19 21l-7-5-7 5V4a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1z"/></svg></i><span id="lfmSaveN">0</span></button>
                    <button type="button" class="lfm-act" id="lfmComments2" aria-label="Comments"><i class="lfm-ic"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4.5A1.5 1.5 0 0 1 4.5 3h15A1.5 1.5 0 0 1 21 4.5v11a1.5 1.5 0 0 1-1.5 1.5H7l-4 4z"/></svg></i><span id="lfmCmActN">0</span></button>
                    <button type="button" class="lfm-act" id="lfmShare" aria-label="Share"><i class="lfm-ic"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M13.4 4.3c0-.7.8-1 1.3-.6l6.9 6.2c.4.4.4 1 0 1.4l-6.9 6.2c-.5.4-1.3.1-1.3-.6v-3.3c-4.3-.1-7.3 1-9.4 4-.4.6-1.3.3-1.2-.4.7-6 4-10.2 10.6-10.7z"/></svg></i><span id="lfmShareN">0</span></button>
                    <button type="button" class="lfm-act" id="lfmDl" aria-label="Download"><i class="ri-download-line"></i><span id="lfmDlN">0</span></button>
                    <button type="button" class="lfm-act" id="lfmReport" aria-label="Report"><i class="ri-flag-line"></i></button>
                </div>
                <div class="lfm-actions-divider"></div>

                <!-- profile pic + username · Track (same line) + @nickname (grey, line below) -->
                <div class="lfm-channel">
                    <a class="lfm-ch-av lfm-js-av" href="#"><i class="ri-user-3-line"></i></a>
                    <div class="lfm-ch-info">
                        <div class="lfm-ch-namerow">
                            <a class="lfm-ch-name lfm-js-name" href="#"></a>
                            <span class="lfm-tr lfm-js-trwrap" style="display:none;"><span class="lfm-sep">&middot;</span><button type="button" class="lfm-track-btn lfm-js-track">Track</button></span>
                        </div>
                        <span class="lfm-ch-nick lfm-js-nick"></span>
                    </div>
                </div>

                <!-- comments card (opens the comments sheet) -->
                <button type="button" class="lfm-comments" id="lfmComments" aria-label="Open comments">
                    <div class="lfm-cm-head"><span>Comments</span><span class="lfm-cm-count" id="lfmCmCount"></span></div>
                    <div class="lfm-cm-prev" id="lfmCmPrev"></div>
                </button>
            </div>

            <!-- more videos from this user -->
            <div class="lfm-listwrap">
                <div id="lfmListSkel" aria-hidden="true">
                    <?php for ($i = 0; $i < 2; $i++): ?>
                    <div class="lfm-sk-card">
                        <div class="sk-card-head">
                            <div class="sk-avatar sk-shimmer"></div>
                            <div class="sk-lines">
                                <div class="sk-line sk-shimmer" style="width:82%;"></div>
                                <div class="sk-line sk-shimmer" style="width:52%;height:9px;"></div>
                            </div>
                        </div>
                        <div class="sk-thumb sk-shimmer"></div>
                    </div>
                    <?php endfor; ?>
                </div>
                <div class="lfm-list" id="lfmList"></div>
                <div class="lf-sentinel" id="lfmSentinel"><div class="lf-spinner"></div></div>
            </div>
        </div>

        <!-- ── DESCRIPTION SHEET (ends at the bottom of the video) ── -->
        <div class="lfm-desc" id="lfmDesc" role="dialog" aria-label="Description">
            <div class="lfm-desc-handle" id="lfmDescHandle"><span></span></div>
            <div class="lfm-desc-head" id="lfmDescHead">
                <div class="lfm-desc-title">Description</div>
                <button type="button" class="lfm-desc-x" id="lfmDescClose" aria-label="Close description"><i class="ri-close-line"></i></button>
            </div>
            <div class="lfm-desc-scroll" id="lfmDescScroll">
                <h3 class="lfm-desc-vtitle" id="lfmDescTitle"></h3>
                <div class="lfm-stats">
                    <div class="lfm-stat"><b id="lfmStLikes">0</b><span>Likes</span></div>
                    <div class="lfm-stat"><b id="lfmStViews">0</b><span>Views</span></div>
                    <div class="lfm-stat"><b id="lfmStDay"></b><span id="lfmStYear"></span></div>
                </div>
                <div class="lfm-desc-box">
                    <div class="lfm-desc-text clamp" id="lfmDescText"></div>
                    <button type="button" class="lfm-desc-toggle" id="lfmDescToggle" style="display:none;">...more</button>
                </div>
                <div class="lfm-channel lfm-channel-card">
                    <a class="lfm-ch-av lfm-js-av" href="#"><i class="ri-user-3-line"></i></a>
                    <div class="lfm-ch-info">
                        <div class="lfm-ch-namerow">
                            <a class="lfm-ch-name lfm-js-name" href="#"></a>
                            <span class="lfm-tr lfm-js-trwrap" style="display:none;"><span class="lfm-sep">&middot;</span><button type="button" class="lfm-track-btn lfm-js-track">Track</button></span>
                        </div>
                        <span class="lfm-ch-nick lfm-js-nick"></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── COMMENTS SHEET (same mechanics as the description sheet), with a second
             "Replies" screen you navigate into from a TOP-LEVEL comment's reply-count row.
             Inside it, any reply that itself has replies shows an inline dropdown instead. ── -->
        <div class="lfm-desc lfm-csheet" id="lfmCSheet" role="dialog" aria-label="Comments">
            <div class="lfm-desc-handle" id="lfmCSHandle"><span></span></div>

            <!-- list screen header -->
            <div class="lfm-desc-head lfm-cs-head" id="lfmCSHead">
                <div class="lfm-desc-title">Comments<span class="lfm-cs-count" id="lfmCSCount"></span></div>
                <div class="lfm-cs-sort">
                    <button type="button" class="lfm-cs-sortbtn" id="lfmCSSortBtn"><span id="lfmCSSortLbl">Top</span><i class="ri-arrow-down-s-line"></i></button>
                    <div class="lfm-cs-sortmenu" id="lfmCSSortMenu">
                        <button type="button" class="active" data-sort="top">Top comments</button>
                        <button type="button" data-sort="new">Newest first</button>
                        <button type="button" data-sort="old">Oldest first</button>
                    </div>
                </div>
                <button type="button" class="lfm-desc-x" id="lfmCSClose" aria-label="Close comments"><i class="ri-close-line"></i></button>
            </div>

            <!-- replies screen header (back arrow + title + X) -->
            <div class="lfm-desc-head lfm-cs-head lfm-cs-thead" id="lfmCSTHead" style="display:none;">
                <button type="button" class="lfm-cs-tback" id="lfmCSTBack" aria-label="Back to comments"><i class="ri-arrow-left-s-line"></i></button>
                <div class="lfm-desc-title">Replies</div>
                <button type="button" class="lfm-desc-x" id="lfmCSTClose" aria-label="Close comments"><i class="ri-close-line"></i></button>
            </div>

            <!-- list screen body -->
            <div class="lfm-cs-scroll" id="lfmCSScroll">
                <div class="lfm-cs-note"><i class="ri-shield-check-line"></i><span>Remember to keep comments respectful.</span></div>
                <div id="lfmCSSkel">
                    <div class="lfm-cs-sk"><div class="sk-shimmer" style="width:32px;height:32px;border-radius:50%;flex-shrink:0;"></div><div style="flex:1;"><div class="sk-shimmer" style="height:10px;width:40%;"></div><div class="sk-shimmer" style="height:10px;width:85%;margin-top:8px;"></div></div></div>
                    <div class="lfm-cs-sk"><div class="sk-shimmer" style="width:32px;height:32px;border-radius:50%;flex-shrink:0;"></div><div style="flex:1;"><div class="sk-shimmer" style="height:10px;width:30%;"></div><div class="sk-shimmer" style="height:10px;width:70%;margin-top:8px;"></div></div></div>
                </div>
                <div class="lfm-cs-list" id="lfmCSList"></div>
                <div class="lfm-cs-empty" id="lfmCSEmpty" style="display:none;">No comments yet. Start the conversation.</div>
                <div class="lf-sentinel" id="lfmCSSentinel"><div class="lf-spinner"></div></div>
            </div>

            <!-- replies screen body: the tapped comment pinned at the top, then its direct replies -->
            <div class="lfm-cs-scroll lfm-cs-tscroll" id="lfmCSTScroll" style="display:none;">
                <div class="lfm-cs-troot" id="lfmCSTRoot"></div>
                <div id="lfmCSTSkel" style="display:none;">
                    <div class="lfm-cs-sk"><div class="sk-shimmer" style="width:30px;height:30px;border-radius:50%;flex-shrink:0;"></div><div style="flex:1;"><div class="sk-shimmer" style="height:10px;width:40%;"></div><div class="sk-shimmer" style="height:10px;width:85%;margin-top:8px;"></div></div></div>
                </div>
                <div class="lfm-cs-list" id="lfmCSTList"></div>
                <div class="lf-sentinel" id="lfmCSTSentinel"><div class="lf-spinner"></div></div>
            </div>

            <!-- composer: shared by both screens (placeholder changes to match) -->
            <div class="lfm-cs-composer" id="lfmCSComposer">
                <span class="lfm-cs-my-av" id="lfmCSMyAv"><i class="ri-user-3-line"></i></span>
                <input type="text" id="lfmCSInput" placeholder="Add a comment..." maxlength="1000">
                <button type="button" class="lfm-cs-send" id="lfmCSSend" aria-label="Post comment" disabled>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5"/><path d="M5.5 11.5 12 5l6.5 6.5"/></svg>
                </button>
            </div>

            <!-- the ⋯ menu shared by every comment -->
            <div class="lfm-cs-menu" id="lfmCSMenu" role="menu">
                <button type="button" data-act="download" role="menuitem"><i class="ri-download-2-line"></i><span>Download comment</span></button>
                <button type="button" data-act="delete" class="danger" role="menuitem"><i class="ri-delete-bin-line"></i><span>Delete comment</span></button>
            </div>
        </div>
    </div>

    <!-- ── SETTINGS SHEET (dialog on desktop, bottom sheet on small screens) ── -->
    <div class="lf-act-overlay" id="lfmSetOverlay" onclick="lfmCloseSettings()">
        <div class="lf-act-panel" id="lfmSetPanel" role="dialog" aria-modal="true" aria-label="Video settings" onclick="event.stopPropagation()">
            <div class="lf-act-handle" id="lfmSetHandle"><span></span></div>

            <div class="lf-act-pane" id="lfmSetMain">
                <div class="lf-act-head" id="lfmSetHead">
                    <div class="lf-act-title">Settings</div>
                    <button type="button" class="lf-act-close" onclick="lfmCloseSettings()" aria-label="Close"><i class="ri-close-line"></i></button>
                </div>
                <button type="button" class="lf-act-item lfm-row" onclick="lfmSetPane('quality')"><i class="lfm-svgi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" aria-hidden="true"><path d="M4 7h9M17 7h3M4 17h3M11 17h9"/><circle cx="15" cy="7" r="2"/><circle cx="9" cy="17" r="2"/></svg></i><span class="lfm-lbl">Quality</span><span class="lfm-val" id="lfmValQuality">Auto</span><i class="ri-arrow-right-s-line lfm-go"></i></button>
                <button type="button" class="lf-act-item lfm-row" onclick="lfmSetPane('speed')"><i class="ri-timer-flash-line"></i><span class="lfm-lbl">Playback speed</span><span class="lfm-val" id="lfmValSpeed">Normal</span><i class="ri-arrow-right-s-line lfm-go"></i></button>
                <button type="button" class="lf-act-item lfm-row" onclick="lfmSetPane('captions')"><i class="ri-closed-captioning-line"></i><span class="lfm-lbl">Captions</span><span class="lfm-val" id="lfmValCaps">Off</span><i class="ri-arrow-right-s-line lfm-go"></i></button>
                <label class="lf-act-item lfm-row"><i class="ri-repeat-2-line"></i><span class="lfm-lbl">Loop video</span><span class="switch"><input type="checkbox" id="lfmLoop"><span class="slider"></span></span></label>
                <label class="lf-act-item lfm-row"><i class="ri-volume-up-line"></i><span class="lfm-lbl">Stable volume</span><span class="switch"><input type="checkbox" id="lfmStable"><span class="slider"></span></span></label>
            </div>

            <div class="lf-act-pane" id="lfmSetSub" style="display:none;">
                <div class="lf-act-head">
                    <button type="button" class="lf-act-back" onclick="lfmSetPane('main')" aria-label="Back"><i class="ri-arrow-left-s-line"></i></button>
                    <div class="lf-act-title" id="lfmSubTitle"></div>
                    <button type="button" class="lf-act-close" onclick="lfmCloseSettings()" aria-label="Close"><i class="ri-close-line"></i></button>
                </div>
                <div class="lf-reasons" id="lfmSubList"></div>
                <div class="lf-act-sub" id="lfmSubNote" style="display:none;"></div>
            </div>

            <button type="button" class="lf-act-cancel" onclick="lfmCloseSettings()">Close</button>
        </div>
    </div>
</div>

<script>
/* ══════════════════════════════════════════════════════════════
   LONG FORM MODAL — JavaScript
   Runs AFTER the page's own script, so it can safely replace
   openLongformModal() and the few page functions that must know
   about the modal.
   ══════════════════════════════════════════════════════════════ */
/* red verified badge markup (sprite above) */
function lfmVb(on){ return on ? '<svg class="lfm-vb" viewBox="0 0 256 256" aria-label="Verified" role="img" focusable="false"><use href="#lfm-vb"/></svg>' : ''; }
var LFM = {
    open: false, id: 0, token: 0, pushed: false,
    data: null, owner: null, preview: null, viewer: null,
    listIds: [], listOffset: 0, listMore: false, listLoading: false,
    rate: 1, loop: false, stable: false, autoplay: true,
    resumeAt: 0, resumed: true, playedSecs: 0, lastT: 0, viewSent: false, lastSent: 0,
    hideTimer: 0, scrubbing: false, scrubT: 0, raf: 0,
    pseudoFs: false, nextTimer: 0, nextId: 0, audio: null, mini: false, miniAt: 0, skipPop: false, wide: false, dragT: 0, miniTapTimer: 0, miniPos: null,
    busyReact: false, busySave: false, busyTrack: false, busyDl: false,
    player: $('lfmPlayer'), col: $('lfmCol')
};
var LV = $('lfmVideo');
var lfmLast = { cur: '', dur: '' };
var LFM_HOVER = (function(){ try { return window.matchMedia('(hover:hover) and (pointer:fine)').matches; } catch (e) { return false; } })();

try { var lfmApSaved = localStorage.getItem('lfm_autoplay'); if (lfmApSaved !== null) LFM.autoplay = (lfmApSaved === '1'); } catch (e) {}
$('lfmAp').checked = LFM.autoplay;
$('lfmApWrap').classList.toggle('on', LFM.autoplay);

/* ══════════════ small helpers ══════════════ */
function lfmFmtCount(n){
    n = Math.max(0, parseInt(n, 10) || 0);
    var v;
    if (n >= 1e9) { v = n / 1e9; return (v >= 10 ? Math.round(v) : Math.round(v * 10) / 10) + 'B'; }
    if (n >= 1e6) { v = n / 1e6; return (v >= 10 ? Math.round(v) : Math.round(v * 10) / 10) + 'M'; }
    if (n >= 1e3) { v = n / 1e3; return (v >= 10 ? Math.round(v) : Math.round(v * 10) / 10) + 'K'; }
    return String(n);
}
function lfmFmtNum(n){ return (parseInt(n, 10) || 0).toLocaleString('en-US'); }
function lfmDuration(){
    var d = LV.duration;
    if (!isFinite(d) || d <= 0) d = (LFM.data && LFM.data.duration) || 0;
    return d;
}
function lfmSheetOpen(){
    return $('lfmSetOverlay').classList.contains('open') || $('lfmDesc').classList.contains('open') || $('lfmCSheet').classList.contains('open');
}

/* ══════════════ progress bar, time, buffering ══════════════ */
function lfmBuffered(){
    var b = LV.buffered, t = LV.currentTime || 0;
    for (var i = 0; i < b.length; i++) {
        if (t >= b.start(i) - 0.1 && t <= b.end(i) + 0.1) return b.end(i);
    }
    return b.length ? b.end(b.length - 1) : 0;
}
function lfmTick(){
    var d = lfmDuration();
    var t = LFM.scrubbing ? LFM.scrubT : (LV.currentTime || 0);
    var pct = d ? Math.max(0, Math.min(100, t / d * 100)) : 0;
    $('lfmPlayed').style.width = pct + '%';
    $('lfmDot').style.left = pct + '%';
    $('lfmBuf').style.width = d ? Math.min(100, lfmBuffered() / d * 100) + '%' : '0';
    var c = lfFmtDuration(t), dd = lfFmtDuration(d);
    if (c !== lfmLast.cur) { $('lfmCur').textContent = c; lfmLast.cur = c; }
    if (dd !== lfmLast.dur) { $('lfmDur').textContent = dd; lfmLast.dur = dd; }
}
function lfmLoop(){
    lfmTick();
    LFM.raf = (LFM.open && !LV.paused) ? requestAnimationFrame(lfmLoop) : 0;
}
function lfmStartLoop(){ if (!LFM.raf) LFM.raf = requestAnimationFrame(lfmLoop); }

function lfmWheel(on){
    $('lfmWheel').classList.toggle('show', !!on);
    LFM.player.classList.toggle('buffering', !!on);
}
function lfmErr(on){ $('lfmErr').classList.toggle('show', !!on); }

/* ══════════════ show / hide the controls ══════════════ */
function lfmArmHide(){
    clearTimeout(LFM.hideTimer);
    if (LV.paused || LFM.scrubbing || lfmSheetOpen()) return;
    /* on a desktop mouse, controls stay up while the cursor is over the video (see hover handlers below) */
    if (LFM_HOVER && LFM.player.classList.contains('lfm-hover-ctl')) return;
    LFM.hideTimer = setTimeout(lfmHideControls, 3200);
}
function lfmShowControls(sticky){
    LFM.player.classList.add('ctl');
    if (sticky) clearTimeout(LFM.hideTimer); else lfmArmHide();
}
function lfmHideControls(){
    clearTimeout(LFM.hideTimer);
    if (LFM_HOVER && LFM.player.classList.contains('lfm-hover-ctl')) return;   /* mouse is still hovering — stay up */
    LFM.player.classList.remove('ctl');
}
function lfmSyncPlayIcon(){
    var ended = LV.ended;
    var cls = ended ? 'ri-restart-line' : (LV.paused ? 'ri-play-fill' : 'ri-pause-fill');
    var lbl = ended ? 'Replay' : (LV.paused ? 'Play' : 'Pause');
    var b = $('lfmPlay'), mb = $('lfmMiniPlay');
    b.querySelector('i').className = cls;
    b.setAttribute('aria-label', lbl);
    mb.querySelector('i').className = cls;
    mb.setAttribute('aria-label', lbl);
}

/* ── desktop-only: show the controls while the mouse hovers the video, hide them the moment it leaves.
   On touch devices (no fine pointer / no hover) this never fires, so tapping the video keeps working exactly as before. ── */
if (LFM_HOVER) {
    LFM.player.addEventListener('mouseenter', function(){
        if (!LFM.open || LFM.mini) return;
        LFM.player.classList.add('lfm-hover-ctl');
        lfmShowControls(true);
    });
    LFM.player.addEventListener('mousemove', function(){
        if (!LFM.open || LFM.mini) return;
        if (!LFM.player.classList.contains('lfm-hover-ctl')) LFM.player.classList.add('lfm-hover-ctl');
        lfmShowControls(true);
    });
    LFM.player.addEventListener('mouseleave', function(){
        if (!LFM.open || LFM.mini) return;
        LFM.player.classList.remove('lfm-hover-ctl');
        lfmArmHide();
    });
}

/* ══════════════ play / pause / seek ══════════════ */
function lfmTogglePlay(){
    if (LV.ended) { try { LV.currentTime = 0; } catch (e) {} }
    if (LV.paused) {
        var p = LV.play();
        if (p && p.catch) p.catch(function(){});
    } else {
        LV.pause();
    }
    lfmArmHide();
}
function lfmSeekBy(sec){
    var d = lfmDuration();
    if (!d) return;
    try { LV.currentTime = Math.max(0, Math.min(d, (LV.currentTime || 0) + sec)); } catch (e) {}
    lfmTick();
    lfmArmHide();
}
function lfmApplyResume(){
    if (LFM.resumed) return;
    LFM.resumed = true;
    if (LFM.resumeAt > 0) { try { LV.currentTime = LFM.resumeAt; } catch (e) {} }
    lfmTick();
}
function lfmStartPlayback(token){
    LV.loop = LFM.loop;
    LV.defaultPlaybackRate = LFM.rate;
    LV.playbackRate = LFM.rate;
    var p = LV.play();
    if (p && p.catch) {
        p.catch(function(){
            if (token !== LFM.token) return;
            lfmSyncPlayIcon();
            lfmShowControls(true);
        });
    }
    lfmShowControls();
}

/* drag the bar to scrub */
(function(){
    var seek = $('lfmSeek'), rail = $('lfmRail');
    function frac(e){
        var r = rail.getBoundingClientRect();
        return Math.max(0, Math.min(1, (e.clientX - r.left) / (r.width || 1)));
    }
    seek.addEventListener('pointerdown', function(e){
        var d = lfmDuration();
        if (!d) return;
        e.preventDefault(); e.stopPropagation();
        LFM.scrubbing = true;
        LFM.player.classList.add('scrub');
        try { seek.setPointerCapture(e.pointerId); } catch (err) {}
        clearTimeout(LFM.hideTimer);
        LFM.scrubT = frac(e) * d;
        lfmTick();
    });
    seek.addEventListener('pointermove', function(e){
        if (!LFM.scrubbing) return;
        LFM.scrubT = frac(e) * lfmDuration();
        lfmTick();
    });
    function end(e){
        if (!LFM.scrubbing) return;
        LFM.scrubbing = false;
        LFM.player.classList.remove('scrub');
        try { seek.releasePointerCapture(e.pointerId); } catch (err) {}
        try { LV.currentTime = LFM.scrubT; } catch (err) {}
        lfmTick();
        lfmArmHide();
    }
    seek.addEventListener('pointerup', end);
    seek.addEventListener('pointercancel', end);
})();

/* ══════════════ video events ══════════════ */
LV.addEventListener('loadedmetadata', function(){
    if (!LFM.open) return;
    lfmApplyResume();
    lfmTick();
    lfmRefreshSettings();
});
LV.addEventListener('loadeddata', function(){ if (LFM.open && LV.readyState >= 2) lfmWheel(false); });
LV.addEventListener('canplay', function(){ if (LFM.open) lfmWheel(false); });
LV.addEventListener('playing', function(){ if (LFM.open) { lfmWheel(false); lfmErr(false); } });
LV.addEventListener('waiting', function(){ if (LFM.open) lfmWheel(true); });
LV.addEventListener('seeking', function(){ if (LFM.open && LV.readyState < 3) lfmWheel(true); });
LV.addEventListener('seeked', function(){
    LFM.lastT = LV.currentTime;
    if (LFM.open && LV.readyState >= 3) lfmWheel(false);
    lfmTick();
});
LV.addEventListener('play', function(){
    lfmSyncPlayIcon();
    lfmStartLoop();
    lfmClearNext();
    LFM.lastT = LV.currentTime;
    lfmArmHide();
});
LV.addEventListener('pause', function(){
    lfmSyncPlayIcon();
    lfmTick();
    if (!LFM.open || !LFM.data || LV.ended) return;
    lfmShowControls(true);
    lfmSaveProgress(true);
});
LV.addEventListener('ended', function(){
    lfmSyncPlayIcon();
    lfmTick();
    lfmShowControls(true);
    lfmSaveProgress(true);
    lfmMaybeAutoNext();
});
LV.addEventListener('progress', function(){ if (LFM.open) lfmTick(); });
LV.addEventListener('timeupdate', function(){
    if (!LFM.open || !LFM.data) return;
    if (!LV.paused && !LV.seeking) {
        var dt = LV.currentTime - LFM.lastT;
        if (dt > 0 && dt < 1.5) LFM.playedSecs += dt;
    }
    LFM.lastT = LV.currentTime;
    if (!LFM.viewSent && LFM.playedSecs >= 10) lfmCountView();
    if (Math.abs(LV.currentTime - LFM.lastSent) >= 15) lfmSaveProgress(false);
    if (LV.paused) lfmTick();
});
LV.addEventListener('error', function(){
    if (!LFM.open || !LV.getAttribute('src')) return;
    lfmWheel(false);
    lfmErr(true);
});
$('lfmRetry').addEventListener('click', function(e){
    e.stopPropagation();
    lfmErr(false); lfmWheel(true);
    try { LV.load(); } catch (err) {}
    var p = LV.play();
    if (p && p.catch) p.catch(function(){});
});

/* ══════════════════════════════════════════════════════════════
   DOUBLE-TAP TO LIKE  (thumbs-up, Instagram style)
   Tap the video once → show / hide the controls.
   Tap it twice quickly → the video is liked, and a thumbs-up jumps
   in where you tapped (soft glow, expanding ring, a bouncy thumbs-up
   that floats away, and a burst of little thumbs-ups and sparkles).
   Keep tapping and more thumbs-ups keep coming.
   Double-tap never un-likes — use the Like button for that.
   ══════════════════════════════════════════════════════════════ */
var lfmTap = { t: 0, timer: 0, chain: 0 };
var LFM_THUMB_PATH = 'M1 21h4V9H1v12zm22-11c0-1.1-.9-2-2-2h-6.31l.95-4.57.03-.32c0-.41-.17-.79-.44-1.06L14.17 1 7.59 7.59C7.22 7.95 7 8.45 7 9v10c0 1.1.9 2 2 2h9c.83 0 1.54-.5 1.84-1.22l3.02-7.05c.09-.23.14-.47.14-.73v-2z';

function lfmTapReset(){
    clearTimeout(lfmTap.timer); clearTimeout(lfmTap.chain);
    lfmTap.timer = 0; lfmTap.chain = 0; lfmTap.t = 0;
    var fx = $('lfmFx');
    if (fx) fx.innerHTML = '';
}

function lfmLikeFx(x, y){
    var fx = $('lfmFx');
    if (!fx) return;
    while (fx.children.length > 8) fx.removeChild(fx.firstChild);

    var piece = document.createElement('div');
    piece.className = 'lfm-fxp';
    piece.style.left = x + 'px';
    piece.style.top  = y + 'px';

    var html = '<span class="lfm-hb-ring"></span><span class="lfm-hb-heart"><i class="ri-heart-3-fill"></i></span>';
    var n = 8;
    for (var i = 0; i < n; i++) {
        var ang  = (360 / n) * i + (Math.random() * 20 - 10);
        var rad  = ang * Math.PI / 180;
        var dist = 60 + Math.random() * 42;
        var size = 12 + Math.random() * 10;
        html += '<span class="lfm-hb-p" style="--tx:' + (Math.cos(rad) * dist).toFixed(1) + 'px;--ty:' + (Math.sin(rad) * dist).toFixed(1) + 'px;' +
            '--tr:' + Math.round(Math.random() * 80 - 40) + 'deg;animation-delay:' + (0.04 + i * 0.015).toFixed(3) + 's">' +
            '<i class="ri-heart-3-fill" style="font-size:' + size.toFixed(1) + 'px"></i></span>';
    }
    piece.innerHTML = html;
    fx.appendChild(piece);
    setTimeout(function(){ if (piece.parentNode) piece.parentNode.removeChild(piece); }, 1150);

    try { if (navigator.vibrate) navigator.vibrate(14); } catch (e) {}
}

/* the heart jumps from where you tapped to the Like button; the like lands when it arrives */
function lfmFlyHeart(x, y, done){
    var btn = $('lfmLike'), ic = btn && btn.querySelector('.lfm-ic, i'), pr = LFM.player.getBoundingClientRect();
    var tr = ic ? ic.getBoundingClientRect() : null;
    if (!tr || !tr.width || !pr.width || !window.Element || !Element.prototype.animate) { done(); return; }
    var sx = pr.left + x, sy = pr.top + y, ex = tr.left + tr.width / 2, ey = tr.top + tr.height / 2;
    var h = document.createElement('div');
    h.className = 'lfm-hb-fly';
    h.innerHTML = '<i class="ri-heart-3-fill"></i>';
    document.body.appendChild(h);
    var fired = false, fin = function(){ if (fired) return; fired = true; if (h.parentNode) h.parentNode.removeChild(h); done(); };
    var an = h.animate([
        { transform: 'translate(' + (sx - 17) + 'px,' + (sy - 17) + 'px) scale(1.5)', opacity: 1 },
        { transform: 'translate(' + (ex - 17) + 'px,' + (ey - 17) + 'px) scale(.8)', opacity: 1, offset: .88 },
        { transform: 'translate(' + (ex - 17) + 'px,' + (ey - 17) + 'px) scale(.4)', opacity: 0 }
    ], { duration: 520, delay: 520, easing: 'cubic-bezier(.5,0,.3,1)', fill: 'both' });
    an.onfinish = fin;
    setTimeout(fin, 1600);
}

function lfmDoubleLike(x, y){
    var p = LFM.data;
    if (!p) return;                                   /* details not loaded yet — nothing to like */
    lfmLikeFx(x, y);
    if (p.liked || LFM.busyReact || LFM.flyingLike) return;
    LFM.flyingLike = true;
    lfmFlyHeart(x, y, function(){
        LFM.flyingLike = false;
        if (LFM.data && !LFM.data.liked && !LFM.busyReact) lfmReact('like');
    });
}

/* tap the video: one tap shows / hides the controls, two quick taps like the video.
   On a desktop mouse this click handler still runs (for double-click-to-like and the
   scrub/seek/next exclusions) but showing/hiding on a single click is skipped, since
   hover already keeps the controls in view on non-touch pointers. */
LFM.player.addEventListener('click', function(e){
    /* a drag (however small) just finished on this pointerup — this click is its trailing
       synthetic click, not a real tap, so it never toggles controls / likes / mini-player */
    if (LFM.dragJustMoved) { LFM.dragJustMoved = false; return; }
    /* shrunk to the corner: tapping the video (not the pause / x buttons) opens it full size */
    if (LFM.mini) {
        /* the down-arrow click that just shrank the video is still bubbling up through this player:
           ignore it (and any big-control button), otherwise the video would open straight back up */
        if (e.target.closest('.lfm-mini-btn, .lfm-btn')) return;
        var nowT = Date.now();
        if (nowT - LFM.miniAt < 450) return;          /* just shrank */
        if (nowT - LFM.dragT < 350) return;           /* this click is only the end of a drag */
        if (LFM.miniTapTimer) {                       /* second tap → double-tap: wide / small */
            clearTimeout(LFM.miniTapTimer); LFM.miniTapTimer = 0;
            lfmMiniToggleWide();
            return;
        }
        /* first tap: wait a moment to see if a second one follows, otherwise open the full modal */
        LFM.miniTapTimer = setTimeout(function(){ LFM.miniTapTimer = 0; lfmExpand(); }, 280);
        return;
    }
    if (e.target.closest('.lfm-btn, .lfm-seek, .lfm-ap, .lfm-err, .lfm-next')) return;

    var r = LFM.player.getBoundingClientRect();
    var x = e.clientX - r.left, y = e.clientY - r.top;
    var now = Date.now();

    /* already in a run of likes → every further tap adds another thumbs-up */
    if (lfmTap.chain) {
        clearTimeout(lfmTap.chain);
        lfmTap.chain = setTimeout(function(){ lfmTap.chain = 0; }, 650);
        lfmDoubleLike(x, y);
        return;
    }
    /* second tap soon after the first → double-tap */
    if (lfmTap.timer && now - lfmTap.t < 300) {
        clearTimeout(lfmTap.timer); lfmTap.timer = 0;
        lfmTap.chain = setTimeout(function(){ lfmTap.chain = 0; }, 650);
        lfmDoubleLike(x, y);
        return;
    }
    /* first tap: wait a moment to see if a second one follows.
       On a real mouse (hover-capable), a single click never needs to toggle the
       controls itself — hovering already handles that — so it's skipped there. */
    lfmTap.t = now;
    lfmTap.timer = setTimeout(function(){
        lfmTap.timer = 0;
        if (LFM_HOVER) return;
        if (LFM.player.classList.contains('ctl')) lfmHideControls(); else lfmShowControls();
    }, 280);
});
$('lfmPlay').addEventListener('click', lfmTogglePlay);
$('lfmBack10').addEventListener('click', function(){ lfmSeekBy(-10); });
$('lfmFwd10').addEventListener('click', function(){ lfmSeekBy(10); });
$('lfmChev').addEventListener('click', function(){ if (lfmFsActive()) lfmExitFs(); else lfmMinimize(); });
$('lfmGear').addEventListener('click', lfmOpenSettings);
$('lfmFs').addEventListener('click', function(){ if (lfmFsActive()) lfmExitFs(); else lfmEnterFs(); lfmArmHide(); });

/* autoplay: a real checkbox (like the Loop toggle below), so this always works */
$('lfmAp').addEventListener('change', function(){
    LFM.autoplay = this.checked;
    $('lfmApWrap').classList.toggle('on', LFM.autoplay);
    try { localStorage.setItem('lfm_autoplay', LFM.autoplay ? '1' : '0'); } catch (e) {}
    lfToast(LFM.autoplay ? 'Autoplay is on' : 'Autoplay is off', 'success');
});
$('lfmApWrap').addEventListener('click', function(e){ e.stopPropagation(); });

/* mute / unmute: the icon always follows the real state of the video */
function lfmSyncMute(){
    var m = LV.muted || LV.volume === 0, b = $('lfmMute');
    b.querySelector('i').className = m ? 'dz-snd off' : 'dz-snd on';
    b.setAttribute('aria-label', m ? 'Unmute' : 'Mute');
    b.setAttribute('aria-pressed', m ? 'true' : 'false');
}
function lfmToggleMute(){
    if (LV.muted || LV.volume === 0) { LV.muted = false; if (LV.volume === 0) LV.volume = 1; }
    else { LV.muted = true; }
    try { localStorage.setItem('lfm_muted', LV.muted ? '1' : '0'); } catch (e) {}
    lfmSyncMute();
    lfmArmHide();
}
try { if (localStorage.getItem('lfm_muted') === '1') LV.muted = true; } catch (e) {}
LV.addEventListener('volumechange', lfmSyncMute);
$('lfmMute').addEventListener('click', function(e){ e.stopPropagation(); lfmToggleMute(); });
lfmSyncMute();

/* ══════════════ expand (fullscreen) ══════════════ */
function lfmFsActive(){
    return !!(document.fullscreenElement || document.webkitFullscreenElement) || LFM.pseudoFs;
}
function lfmSyncFs(){
    var on = lfmFsActive();
    $('lfmOverlay').classList.toggle('is-fs', on);
    $('lfmFs').querySelector('i').className = on ? 'ri-fullscreen-exit-line' : 'ri-fullscreen-line';
    $('lfmFs').setAttribute('aria-label', on ? 'Exit full screen' : 'Expand video');
    setTimeout(lfmSyncSheetTop, 60);
}
function lfmPseudoFs(){ LFM.pseudoFs = true; lfmSyncFs(); }
function lfmEnterFs(){
    var el = $('lfmOverlay');
    var req = el.requestFullscreen || el.webkitRequestFullscreen;
    if (req) {
        try {
            var p = req.call(el);
            if (p && p.catch) p.catch(lfmPseudoFs);
        } catch (e) { lfmPseudoFs(); }
    } else {
        lfmPseudoFs();      /* iPhone Safari has no element fullscreen, so we fill the screen ourselves */
    }
    try { if (screen.orientation && screen.orientation.lock) screen.orientation.lock('landscape').catch(function(){}); } catch (e) {}
}
function lfmExitFs(){
    LFM.pseudoFs = false;
    if (document.fullscreenElement || document.webkitFullscreenElement) {
        var ex = document.exitFullscreen || document.webkitExitFullscreen;
        try { ex.call(document); } catch (e) {}
    }
    try { if (screen.orientation && screen.orientation.unlock) screen.orientation.unlock(); } catch (e) {}
    lfmSyncFs();
}
document.addEventListener('fullscreenchange', lfmSyncFs);
document.addEventListener('webkitfullscreenchange', lfmSyncFs);

/* ══════════════ settings sheet ══════════════ */
function lfmOpenSettings(){
    lfmSetPane('main');
    lfmRefreshSettings();
    var pn = $('lfmSetPanel');
    pn.style.transition = ''; pn.style.transform = '';
    $('lfmSetOverlay').classList.add('open');
    clearTimeout(LFM.hideTimer);
}
function lfmCloseSettings(){
    $('lfmSetOverlay').classList.remove('open');
    lfmArmHide();
}
function lfmCapTracks(){
    var out = [], tt = LV.textTracks;
    if (!tt) return out;
    for (var i = 0; i < tt.length; i++) {
        if (tt[i].kind === 'captions' || tt[i].kind === 'subtitles') out.push(tt[i]);
    }
    return out;
}
function lfmCapLabel(){
    var t = lfmCapTracks();
    for (var i = 0; i < t.length; i++) { if (t[i].mode === 'showing') return t[i].label || t[i].language || 'On'; }
    return 'Off';
}
/* ── video quality: 144p up to 1080p, plus Auto. The source file itself is a single
   quality, so choosing a resolution here relabels playback at that target quality
   (capped to whatever the source actually is) rather than switching between separate
   files — there's only one file to serve. Auto always plays the source as uploaded. ── */
var LFM_QUALITIES = [144, 240, 360, 480, 720, 1080];
LFM.quality = 'auto';
function lfmQualityLabel(){
    if (LFM.quality === 'auto') return 'Auto' + (LV.videoHeight ? ' (' + LV.videoHeight + 'p)' : '');
    return LFM.quality + 'p';
}
function lfmRefreshSettings(){
    $('lfmValQuality').textContent = lfmQualityLabel();
    $('lfmValSpeed').textContent = LFM.rate === 1 ? 'Normal' : LFM.rate + 'x';
    $('lfmValCaps').textContent = lfmCapLabel();
    $('lfmLoop').checked = LFM.loop;
    $('lfmStable').checked = LFM.stable;
}
function lfmSetPane(name){
    var main = (name === 'main');
    $('lfmSetMain').style.display = main ? '' : 'none';
    $('lfmSetSub').style.display = main ? 'none' : '';
    $('lfmSetPanel').scrollTop = 0;
    if (main) return;

    var list = $('lfmSubList'), note = $('lfmSubNote');
    list.innerHTML = ''; note.style.display = 'none';
    var items = [], sel = '', pick = function(){}, title = '';

    if (name === 'quality') {
        title = 'Quality';
        items = [{ k: 'auto', t: 'Auto' + (LV.videoHeight ? ' (' + LV.videoHeight + 'p)' : '') }];
        LFM_QUALITIES.slice().reverse().forEach(function(q){ items.push({ k: String(q), t: q + 'p' }); });
        sel = LFM.quality;
        note.textContent = 'This video was uploaded in a single file, so every quality below plays the same source — pick the one that best matches your connection.';
        note.style.display = '';
        pick = function(k){ LFM.quality = k; };
    } else if (name === 'speed') {
        title = 'Playback speed';
        [0.25, 0.5, 0.75, 1, 1.25, 1.5, 1.75, 2].forEach(function(v){ items.push({ k: String(v), t: v === 1 ? 'Normal' : v + 'x' }); });
        sel = String(LFM.rate);
        pick = function(k){
            LFM.rate = parseFloat(k);
            LV.defaultPlaybackRate = LFM.rate;
            LV.playbackRate = LFM.rate;
        };
    } else if (name === 'captions') {
        title = 'Captions';
        var tracks = lfmCapTracks();
        items = [{ k: 'off', t: 'Off' }];
        sel = 'off';
        tracks.forEach(function(t, i){
            items.push({ k: String(i), t: t.label || t.language || ('Track ' + (i + 1)) });
            if (t.mode === 'showing') sel = String(i);
        });
        if (!tracks.length) { note.textContent = 'No captions are available for this video.'; note.style.display = ''; }
        pick = function(k){
            tracks.forEach(function(t, i){ t.mode = (String(i) === k) ? 'showing' : 'disabled'; });
        };
    }

    $('lfmSubTitle').textContent = title;
    items.forEach(function(it){
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'lf-reason' + (it.k === sel ? ' selected' : '');
        b.innerHTML = '<span class="lf-radio"></span><span>' + lfEsc(it.t) + '</span>';
        b.addEventListener('click', function(){ pick(it.k); lfmSetPane('main'); lfmRefreshSettings(); });
        list.appendChild(b);
    });
}
$('lfmLoop').addEventListener('change', function(){ LFM.loop = this.checked; LV.loop = this.checked; });
$('lfmStable').addEventListener('change', function(){ lfmSetStable(this.checked); });

/* Stable volume: a compressor evens out loud and quiet parts */
function lfmStableFail(){
    $('lfmStable').checked = false;
    LFM.stable = false;
    lfToast('Stable volume is not supported on this device', 'error');
}
function lfmSetStable(on){
    var AC = window.AudioContext || window.webkitAudioContext;
    if (!AC) { lfmStableFail(); return; }
    try {
        if (!LFM.audio) {
            var ctx = new AC();
            var src = ctx.createMediaElementSource(LV);
            var comp = ctx.createDynamicsCompressor();
            comp.threshold.value = -32; comp.knee.value = 24; comp.ratio.value = 10;
            comp.attack.value = 0.004; comp.release.value = 0.28;
            var gain = ctx.createGain();
            gain.gain.value = 1.35;
            LFM.audio = { ctx: ctx, src: src, comp: comp, gain: gain };
        }
        var a = LFM.audio;
        if (a.ctx.state === 'suspended') a.ctx.resume();
        try { a.src.disconnect(); } catch (e) {}
        try { a.comp.disconnect(); a.gain.disconnect(); } catch (e) {}
        if (on) { a.src.connect(a.comp); a.comp.connect(a.gain); a.gain.connect(a.ctx.destination); }
        else    { a.src.connect(a.ctx.destination); }
        LFM.stable = on;
    } catch (e) { lfmStableFail(); }
}

/* ══════════════ swipe a sheet down to close it ══════════════ */
function lfmSwipe(panel, zones, close, mobileOnly){
    var startY = 0, dy = 0, dragging = false, pid = null;
    function ok(){ return !mobileOnly || window.matchMedia('(max-width:790px)').matches; }
    function start(e){
        if (!ok()) return;
        if (e.pointerType === 'mouse' && e.button !== 0) return;
        dragging = true; dy = 0; startY = e.clientY; pid = e.pointerId;
        panel.style.transition = 'none';
        try { e.currentTarget.setPointerCapture(e.pointerId); } catch (err) {}
    }
    function move(e){
        if (!dragging || e.pointerId !== pid) return;
        dy = Math.max(0, e.clientY - startY);
        panel.style.transform = 'translateY(' + dy + 'px)';
    }
    function end(e){
        if (!dragging || (e && e.pointerId !== pid)) return;
        dragging = false; pid = null;
        panel.style.transition = 'transform .22s ease';
        if (dy > 90) {
            panel.style.transform = 'translateY(100%)';
            setTimeout(function(){ close(); panel.style.transition = ''; panel.style.transform = ''; }, 200);
        } else {
            panel.style.transform = '';
            setTimeout(function(){ panel.style.transition = ''; }, 240);
        }
    }
    zones.forEach(function(z){
        if (!z) return;
        z.addEventListener('pointerdown', start);
        z.addEventListener('pointermove', move);
        z.addEventListener('pointerup', end);
        z.addEventListener('pointercancel', end);
    });
}
lfmSwipe($('lfmSetPanel'), [$('lfmSetHandle'), $('lfmSetHead')], lfmCloseSettings, true);
/* dragging the description sheet down past the threshold minimizes the whole player into
   the mini player (lfmMinimize already closes the description sheet itself) */
lfmSwipe($('lfmDesc'), [$('lfmDescHandle'), $('lfmDescHead')], function(){ lfmMinimize(); }, false);

/* ══════════════════════════════════════════════════════════════
   DRAG THE WHOLE MODAL DOWN → MINI PLAYER
   Grab anywhere on the player background (not a button, the seek bar,
   or a sheet) and drag it down past a small threshold: it turns straight
   into the corner mini player, following your finger the rest of the way.
   A short drag that doesn't cross the threshold just snaps back.
   `.lfm-player` is `touch-action:none` (see CSS) specifically so the browser
   never intercepts this as a native scroll/pan before our own pointer
   handlers get a chance to see the movement — that was the reason the
   swipe-down gesture could silently fail to start on a touch screen.
   ══════════════════════════════════════════════════════════════ */
(function(){
    var col = $('lfmCol'), player = LFM.player;
    var d = null, THRESHOLD = 70;
    function eligible(e){
        if (!LFM.open || LFM.mini) return false;
        if (lfmFsActive()) return false;
        if (LFM.scrubbing) return false;
        if (lfmSheetOpen()) return false;
        if (e.target.closest('.lfm-btn, .lfm-seek, .lfm-ap, .lfm-err, .lfm-next, .lfm-mini-ui')) return false;
        return true;
    }
    player.addEventListener('pointerdown', function(e){
        if (e.pointerType === 'mouse' && e.button !== 0) return;   /* right/middle click only excluded — left-click-drag and touch both minimize */
        if (!eligible(e)) return;
        d = { id: e.pointerId, sy: e.clientY, dy: 0, moved: false };
        try { player.setPointerCapture(e.pointerId); } catch (err) {}
    });
    player.addEventListener('pointermove', function(e){
        if (!d || e.pointerId !== d.id) return;
        var dy = e.clientY - d.sy;
        if (dy <= 0) { d.dy = 0; return; }          /* only downward drags count */
        if (!d.moved && dy > 8) {
            d.moved = true;
            col.classList.add('lfm-dragdown');
            try { player.setPointerCapture(e.pointerId); } catch (err) {}
        }
        if (!d.moved) return;
        d.dy = dy;
        var p = Math.min(1, dy / (THRESHOLD * 2.4));
        col.style.transform = 'translateY(' + dy + 'px) scale(' + (1 - p * 0.14) + ')';
        col.style.opacity = String(1 - p * 0.35);
        e.preventDefault();
    }, { passive: false });
    function end(e){
        if (!d || e.pointerId !== d.id) return;
        var moved = d.moved, dy = d.dy;
        try { player.releasePointerCapture(e.pointerId); } catch (err) {}
        col.classList.remove('lfm-dragdown');
        col.style.transition = 'transform .18s ease, opacity .18s ease';
        col.style.transform = ''; col.style.opacity = '';
        setTimeout(function(){ col.style.transition = ''; }, 200);
        d = null;
        if (moved) {
            /* swallow the trailing click event so it doesn't also toggle controls / like.
               On a real touch drag the browser usually never fires that trailing click at
               all (it's suppressed once movement passes its own threshold), so this flag
               would otherwise stay stuck "true" forever and eat the NEXT genuine tap too —
               which is exactly what broke double-tap-to-widen on the mini player right after
               swiping down to minimize. Self-clearing it shortly after is a safety net: a
               real trailing click (when one does fire) still arrives well within this window
               and clears it immediately, but nothing is left armed to ambush a later tap. */
            LFM.dragJustMoved = true;
            setTimeout(function(){ LFM.dragJustMoved = false; }, 400);
        }
        if (moved && dy > THRESHOLD) lfmMinimize();
    }
    player.addEventListener('pointerup', end);
    player.addEventListener('pointercancel', end);
})();

/* ══════════════ description sheet ══════════════ */
function lfmSyncSheetTop(){
    var h = LFM.player.offsetHeight;
    if (h > 0) LFM.col.style.setProperty('--lfm-ph', h + 'px');
}
function lfmMeasureDesc(){
    var t = $('lfmDescText'), b = $('lfmDescToggle');
    b.style.display = 'none';
    if (t.classList.contains('none')) return;
    t.classList.add('clamp');
    b.textContent = '...more';
    if (t.scrollHeight > t.clientHeight + 2) b.style.display = '';
}
function lfmOpenDesc(){
    if (!LFM.data) return;
    lfmSyncSheetTop();
    $('lfmDesc').classList.add('open');
    $('lfmDescScroll').scrollTop = 0;
    lfmMeasureDesc();
    clearTimeout(LFM.hideTimer);
}
function lfmCloseDesc(){
    var d = $('lfmDesc');
    d.classList.remove('open');
    d.style.transition = ''; d.style.transform = '';
}
$('lfmInfo').addEventListener('click', lfmOpenDesc);
$('lfmInfo').addEventListener('keydown', function(e){ if (e.key === 'Enter') { e.preventDefault(); lfmOpenDesc(); } });
$('lfmDescClose').addEventListener('click', function(){ lfmCloseDesc(); });
$('lfmDescToggle').addEventListener('click', function(){
    var clamped = $('lfmDescText').classList.toggle('clamp');
    this.textContent = clamped ? '...more' : 'See less';
});
window.addEventListener('resize', lfmSyncSheetTop);
if (window.ResizeObserver) new ResizeObserver(lfmSyncSheetTop).observe(LFM.player);

/* ══════════════ drawing the details under the video ══════════════ */
/* a count that is hidden (null) shows an empty count slot — the span stays in place
   (same as a button with no count at all, like Report) so the icon never shifts position.
   The CSS rule `.lfm-act span{ min-height:1em; line-height:1; }` is what actually keeps the
   slot's height identical whether it holds digits or nothing — this function only ever
   changes the text, never the layout. */
function lfmSetCountEl(el, n){
    if (n === null || n === undefined) { el.textContent = ''; el.style.display = ''; }
    else { el.textContent = lfmFmtCount(n); el.style.display = ''; }
}
function lfmRenderMetaLikes(){
    var p = LFM.data;
    if (!p) return;
    var hide = !!p.hide_likes || p.likes === null || p.likes === undefined;
    $('lfmMetaLikeSep').style.display = hide ? 'none' : '';
    $('lfmMetaLikes').style.display = hide ? 'none' : '';
    if (!hide) $('lfmMetaLikes').textContent = lfmFmtCount(p.likes) + (p.likes === 1 ? ' like' : ' likes');
}
function lfmRenderMeta(){
    var p = LFM.data;
    /* @nickname (same as the profile page); falls back to the username if no nickname is set */
    var nm = LFM.owner ? (LFM.owner.nickname || LFM.owner.username) : '';
    $('lfmMetaUser').textContent = nm ? '@' + nm : '';
    $('lfmViews').textContent = lfmFmtCount(p.views) + (p.views === 1 ? ' view' : ' views');
    $('lfmStViews').textContent = lfmFmtNum(p.views);
    lfmRenderMetaLikes();
}
function lfmSetAct(btn, on){
    btn.classList.toggle('on', !!on);
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
}
function lfmRenderActions(){
    var p = LFM.data, o = LFM.owner;
    if (!p) return;
    lfmSetAct($('lfmLike'), p.liked);
    lfmSetAct($('lfmDislike'), p.disliked);
    lfmSetAct($('lfmSave'), p.saved);
    /* like / dislike / save / share counts: each one is hidden from everyone except the creator
       when the creator switched it off (the backend then sends null and a hide_* flag) */
    lfmSetCountEl($('lfmLikeN'),    p.hide_likes    ? null : p.likes);
    lfmSetCountEl($('lfmDislikeN'), p.hide_dislikes ? null : p.dislikes);
    lfmSetCountEl($('lfmSaveN'),    p.hide_saves    ? null : p.saves);
    lfmSetCountEl($('lfmShareN'),   p.hide_shares   ? null : p.shares);
    $('lfmDlN').textContent = lfmFmtCount(p.downloads);
    $('lfmCmActN').textContent = p.comments_off ? '' : lfmFmtCount(p.comments);
    var ownPost = !!(o && o.is_self);
    $('lfmReport').style.display = ownPost ? 'none' : '';
    $('lfmStLikes').textContent = p.hide_likes ? 'Hidden' : lfmFmtCount(p.likes);
    lfmRenderMetaLikes();
}
/* Track / Untrack: "Untrack" when the viewer already tracks this person, otherwise "Track" */
function lfmRenderTrack(){
    var o = LFM.owner;
    if (!o) return;
    document.querySelectorAll('.lfm-js-trwrap').forEach(function(w){ w.style.display = o.can_track ? '' : 'none'; });
    document.querySelectorAll('.lfm-js-track').forEach(function(b){
        b.textContent = o.tracking ? 'Untrack' : 'Track';
        b.disabled = LFM.busyTrack;
    });
}
function lfmRenderOwner(){
    var o = LFM.owner;
    var href = 'profile.php?user_id=' + o.id;
    document.querySelectorAll('.lfm-js-av').forEach(function(a){
        a.href = href;
        a.setAttribute('aria-label', 'View ' + o.username + "'s profile");
        a.innerHTML = '<img src="' + lfEsc(o.avatar) + '" alt="" onerror="lfAvatarFail(this)">';
    });
    document.querySelectorAll('.lfm-js-name').forEach(function(a){ a.href = href; a.innerHTML = lfEsc(o.username) + lfmVb(o.verified); });
    /* @nickname, greyed out, directly under the username — falls back to nothing extra when nickname === username */
    var nick = o.nickname && o.nickname !== o.username ? o.nickname : '';
    document.querySelectorAll('.lfm-js-nick').forEach(function(s){
        s.textContent = nick ? '@' + nick : '';
        s.style.display = nick ? '' : 'none';
    });
    lfmRenderTrack();
}
function lfmRenderComments(){
    var p = LFM.data, cnt = $('lfmCmCount'), prev = $('lfmCmPrev'), pv = LFM.preview;
    if (p.comments_off) {
        cnt.textContent = '';
        prev.innerHTML = '<div class="lfm-cm-empty"><i class="ri-chat-off-line"></i><span>Comments are turned off for this video.</span></div>';
        return;
    }
    cnt.textContent = lfmFmtCount(p.comments);
    if (pv) {
        prev.innerHTML = '<span class="lfm-cm-av"><img src="' + lfEsc(pv.avatar) + '" alt="" onerror="lfAvatarFail(this)"></span>' +
                         '<span class="lfm-cm-text">' + lfEsc(pv.text) + '</span>';
    } else {
        prev.innerHTML = '<div class="lfm-cm-empty"><i class="ri-chat-3-line"></i><span>' +
                         (p.comments ? 'View comments' : 'No comments yet. Start the conversation.') + '</span></div>';
    }
    if (LFCS.open && LFCS.postId === p.id) $('lfmCSCount').textContent = ' · ' + lfmFmtCount(p.comments);
}
function lfmRenderDesc(){
    var p = LFM.data, t = $('lfmDescText');
    $('lfmDescTitle').textContent = p.title;
    $('lfmStDay').textContent = p.date_day;
    $('lfmStYear').textContent = p.date_year;
    if (p.desc_html) { t.className = 'lfm-desc-text clamp'; t.innerHTML = p.desc_html; }
    else { t.className = 'lfm-desc-text none'; t.textContent = 'This video has no description.'; }
    $('lfmDescToggle').style.display = 'none';
}
function lfmRender(){
    var p = LFM.data;
    $('lfmTitle').textContent = p.title;
    $('lfmTitle').setAttribute('title', p.title);
    lfmRenderMeta();
    lfmRenderOwner();
    lfmRenderActions();
    lfmRenderComments();
    lfmRenderDesc();
    $('lfmSkel').style.display = 'none';
    $('lfmBody').style.display = '';
}

/* ══════════════ actions: like, dislike, save, download, share, report, track ══════════════ */
function lfmReact(type){
    var p = LFM.data;
    if (!p || LFM.busyReact) return;
    LFM.busyReact = true;
    var id = p.id, was = { liked: p.liked, disliked: p.disliked, likes: p.likes, dislikes: p.dislikes };
    /* counts that are hidden (null) stay hidden — only real numbers are adjusted */
    function adj(key, delta){ if (p[key] !== null && p[key] !== undefined) p[key] = Math.max(0, p[key] + delta); }
    if (type === 'like') {
        p.liked = !was.liked; p.disliked = false;
        adj('likes', p.liked ? 1 : -1);
        if (was.disliked) adj('dislikes', -1);
    } else {
        p.disliked = !was.disliked;
        adj('dislikes', p.disliked ? 1 : -1);
        if (p.disliked && was.liked) { p.liked = false; adj('likes', -1); }
    }
    lfmRenderActions();
    function revert(msg){
        p.liked = was.liked; p.disliked = was.disliked; p.likes = was.likes; p.dislikes = was.dislikes;
        if (LFM.data && LFM.data.id === id) lfmRenderActions();
        lfToast(msg || 'Could not save that. Please try again.', 'error');
    }
    lfPost('lfm_react', { post_id: id, type: type }).then(function(d){
        LFM.busyReact = false;
        if (!d || !d.success) { revert(d && d.error); return; }
        p.liked = !!d.liked; p.disliked = !!d.disliked;
        p.likes    = (d.likes === null || d.likes === undefined) ? null : d.likes;
        p.dislikes = (d.dislikes === null || d.dislikes === undefined) ? null : d.dislikes;
        if (LFM.data && LFM.data.id === id) lfmRenderActions();
    }).catch(function(){ LFM.busyReact = false; revert(); });
}

/* keeps the modal, the grid cards, the Saved list and the ⋯ sheet in agreement about "saved" */
function lfmSyncSaved(id, saved){
    document.querySelectorAll('.lf-card[data-post-id="' + id + '"]').forEach(function(c){ c.setAttribute('data-saved', saved ? '1' : '0'); });
    if (ACT.card && ACT.id === id) ACT.card.setAttribute('data-saved', saved ? '1' : '0');
    if (LFM.data && LFM.data.id === id && LFM.data.saved !== saved) {
        LFM.data.saved = saved;
        if (LFM.data.saves !== null && LFM.data.saves !== undefined) LFM.data.saves = Math.max(0, LFM.data.saves + (saved ? 1 : -1));
        lfmRenderActions();
    }
}
/* a video that was unsaved leaves the Saved list (if that list is loaded) */
function lfmRemoveFromSavedList(id){
    if (typeof lfSavedRemoveCard !== 'function') return;
    document.querySelectorAll('#savedList .lf-card[data-post-id="' + id + '"]').forEach(lfSavedRemoveCard);
}
function lfmSave(){
    var p = LFM.data;
    if (!p || LFM.busySave) return;
    LFM.busySave = true;
    var id = p.id, was = p.saved;
    lfmSyncSaved(id, !was);
    lfPost('toggle_save_longform', { post_id: id }).then(function(d){
        LFM.busySave = false;
        if (!d || !d.success) { lfmSyncSaved(id, was); lfToast((d && d.error) || 'Could not update your saved posts.', 'error'); return; }
        if (!d.saved) lfmRemoveFromSavedList(id);
        lfToast(d.saved ? 'Post saved' : 'Removed from saved', 'success');
    }).catch(function(){ LFM.busySave = false; lfmSyncSaved(id, was); lfToast('Could not update your saved posts.', 'error'); });
}

function lfmDownload(){
    var p = LFM.data;
    if (!p || !p.video) { lfToast('This video is not available to download.', 'error'); return; }
    var ext  = (p.video.match(/\.([a-z0-9]+)$/i) || [])[1] || 'mp4';
    var name = (p.title || 'video').replace(/[\\\/:*"<>|]+/g, '').trim().slice(0, 80) || 'video';
    var a = document.createElement('a');
    a.href = p.video; a.download = name + '.' + ext; a.rel = 'noopener';
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
    lfToast('Download started', 'success');
    var id = p.id;
    lfPost('lfm_download', { post_id: id }).then(function(d){
        if (d && d.success && LFM.data && LFM.data.id === id) { LFM.data.downloads = d.downloads; lfmRenderActions(); }
    }).catch(function(){});
}

function lfmBump(id, key){
    if (LFM.data && LFM.data.id === id) {
        if (LFM.data[key] !== null && LFM.data[key] !== undefined) LFM.data[key] = LFM.data[key] + 1;
        lfmRenderActions();
    }
}
function lfmShare(){
    var p = LFM.data;
    if (!p) return;
    var id = p.id, url = new URL(p.url, window.location.href).href;
    if (navigator.share) {
        navigator.share({ title: p.title, url: url })
            .then(function(){ lfRecordShare(id, 'native'); lfmBump(id, 'shares'); })
            .catch(function(){});
    } else {
        lfCopyText(url, 'Link copied. Paste it anywhere to share.');
        lfRecordShare(id, 'copy_link');
        lfmBump(id, 'shares');
    }
}

function lfmReport(){
    var p = LFM.data;
    if (!p) return;
    if (typeof openReportModal === 'function') { openReportModal(p.id); return; }
    /* until a dedicated report modal exists, use the report step already in the ⋯ sheet */
    var card = document.createElement('div');
    card.setAttribute('data-title', p.title);
    card.setAttribute('data-own', (LFM.owner && LFM.owner.is_self) ? '1' : '0');
    card.setAttribute('data-url', p.url);
    card.setAttribute('data-video', p.video);
    card.setAttribute('data-thumb', p.thumb);
    card.setAttribute('data-saved', p.saved ? '1' : '0');
    lfmOpenActionsSheet(p.id, card, 'report');
}

function lfmOpenComments(){
    var p = LFM.data;
    if (!p) return;
    if (p.comments_off) { lfToast('Comments are turned off for this video', 'error'); return; }
    lfmCsOpen(p.id);
}

function lfmToggleTrack(){
    var o = LFM.owner;
    if (!o || !o.can_track || LFM.busyTrack) return;
    LFM.busyTrack = true;
    var prev = o.tracking;
    o.tracking = !prev;
    lfmRenderTrack();
    lfPost('lfm_track', { user_id: o.id }).then(function(d){
        LFM.busyTrack = false;
        if (d && d.success) {
            o.tracking = !!d.tracking;
            lfmRenderTrack();
            lfToast(o.tracking ? 'Now tracking ' + o.username : 'Untracked ' + o.username, 'success');
        } else {
            o.tracking = prev; lfmRenderTrack();
            lfToast((d && d.error) || 'Could not update tracking.', 'error');
        }
    }).catch(function(){
        LFM.busyTrack = false; o.tracking = prev; lfmRenderTrack();
        lfToast('Could not update tracking.', 'error');
    });
}
document.addEventListener('click', function(e){
    var b = e.target.closest ? e.target.closest('.lfm-js-track') : null;
    if (b) { e.preventDefault(); e.stopPropagation(); lfmToggleTrack(); }
});

$('lfmLike').addEventListener('click', function(){ lfmReact('like'); });
$('lfmDislike').addEventListener('click', function(){ lfmReact('dislike'); });
$('lfmSave').addEventListener('click', lfmSave);
$('lfmDl').addEventListener('click', lfmDownload);
$('lfmShare').addEventListener('click', lfmShare);
$('lfmReport').addEventListener('click', lfmReport);
$('lfmComments').addEventListener('click', lfmOpenComments);
$('lfmComments2').addEventListener('click', lfmOpenComments);

/* ══════════════ watch progress + views ══════════════ */
function lfmUpdateCardProgress(id, t, d){
    if (!d) return;
    var pct = Math.max(0, Math.min(100, t / d * 100)).toFixed(1);
    document.querySelectorAll('.lf-card[data-post-id="' + id + '"] .lf-thumb').forEach(function(th){
        var bar = th.querySelector('.lf-progress');
        if (!bar) {
            bar = document.createElement('div');
            bar.className = 'lf-progress';
            bar.innerHTML = '<span></span>';
            th.appendChild(bar);
        }
        bar.firstElementChild.style.width = pct + '%';
    });
}
function lfmSaveProgress(beacon){
    if (!LFM.id || !LFM.data) return;
    var d = lfmDuration();
    var t = LV.ended ? Math.floor(d) : Math.floor(LV.currentTime || 0);
    if (t < 3) return;
    LFM.lastSent = t;
    lfmUpdateCardProgress(LFM.id, t, d);
    if (beacon && navigator.sendBeacon) {
        var fd = new FormData();
        fd.append('lfm_progress', '1');
        fd.append('csrf_token', LF_CFG.csrf);
        fd.append('post_id', LFM.id);
        fd.append('seconds', t);
        navigator.sendBeacon(LF_CFG.endpoint, fd);
    } else {
        lfPost('lfm_progress', { post_id: LFM.id, seconds: t }).catch(function(){});
    }
}
function lfmCountView(){
    LFM.viewSent = true;
    var id = LFM.id;
    lfPost('lfm_view', { post_id: id }).then(function(d){
        if (d && d.success && LFM.data && LFM.data.id === id) { LFM.data.views = d.views; lfmRenderMeta(); }
    }).catch(function(){});
}
window.addEventListener('pagehide', function(){ if (LFM.open) lfmSaveProgress(true); });

/* ══════════════ "up next" (autoplay) ══════════════ */
function lfmClearNext(){
    clearInterval(LFM.nextTimer);
    LFM.nextTimer = 0; LFM.nextId = 0;
    $('lfmNext').classList.remove('show');
}
function lfmMaybeAutoNext(){
    if (LV.loop || !LFM.autoplay || !LFM.listIds.length) return;
    var nid = LFM.listIds[0], card = $('lfm-card-' + nid), left = 5;
    if (LFM.mini) { openLongformModal(nid, true); return; }
    LFM.nextId = nid;
    $('lfmNextTitle').textContent = card ? (card.getAttribute('data-title') || '') : '';
    $('lfmNextLbl').textContent = 'Up next in ' + left;
    $('lfmNext').classList.add('show');
    clearInterval(LFM.nextTimer);
    LFM.nextTimer = setInterval(function(){
        left--;
        if (left <= 0) { var id = LFM.nextId; lfmClearNext(); if (id) openLongformModal(id); }
        else $('lfmNextLbl').textContent = 'Up next in ' + left;
    }, 1000);
}
$('lfmNextGo').addEventListener('click', function(e){ e.stopPropagation(); var id = LFM.nextId; lfmClearNext(); if (id) openLongformModal(id); });
$('lfmNextCancel').addEventListener('click', function(e){ e.stopPropagation(); lfmClearNext(); });

/* ══════════════ more videos from this user ══════════════ */
function lfmLoadList(token, first){
    if (LFM.listLoading || (!first && !LFM.listMore)) return;
    LFM.listLoading = true;
    fetch(LF_CFG.endpoint + '&lfm_more=1&post=' + LFM.id + '&offset=' + LFM.listOffset, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (token !== LFM.token) return;
            LFM.listLoading = false;
            $('lfmListSkel').style.display = 'none';
            if (!d || !d.success) return;
            $('lfmList').insertAdjacentHTML('beforeend', d.html);
            LFM.listOffset += d.count;
            LFM.listIds = LFM.listIds.concat(d.ids);
            LFM.listMore = d.has_more;
            $('lfmSentinel').classList.toggle('show', LFM.listMore);
        })
        .catch(function(){
            if (token !== LFM.token) return;
            LFM.listLoading = false;
            $('lfmListSkel').style.display = 'none';
        });
}
(function(){
    var s = $('lfmSentinel');
    if (!s || !('IntersectionObserver' in window)) return;
    new IntersectionObserver(function(entries){
        if (entries[0].isIntersecting) lfmLoadList(LFM.token, false);
    }, { root: $('lfmScroll'), rootMargin: '300px 0px' }).observe(s);
})();

/* ══════════════════════════════════════════════════════════════
   COMMENTS SHEET — two screens sharing one sheet:
     • the list screen   (#lfmCSHead / #lfmCSScroll / #lfmCSList) — top-level
       comments. A top-level comment's own "N replies" row NAVIGATES into:
     • the Replies screen (#lfmCSTHead / #lfmCSTScroll / #lfmCSTRoot / #lfmCSTList),
       showing that comment pinned at the top and its DIRECT replies below.

   TRUE NESTED THREADING: any reply shown ANYWHERE inside the Replies screen
   (a direct reply, or a reply already dropped open under another reply) that
   itself has replies shows its own inline "N replies" TOGGLE. Tapping it does
   NOT navigate — it drops that reply's own sub-thread open right underneath
   it (LFCS_SUB tracks each one's open/loaded state), so a chain of replies
   nests visually instead of jumping to another screen. Replying to any
   comment posts directly under THAT comment (its true parent_id on the
   server — see lfm_comment_add), so a reply to a reply builds its own
   sub-thread rather than collapsing back into the top-level thread.

   Like (thumbs-up) + a plain grey "Reply" + a ⋯ menu (download / delete) on
   every comment, at every depth.
   ══════════════════════════════════════════════════════════════ */
var LFCS = {
    open: false, postId: 0, sort: 'top', offset: 0, hasMore: false, loading: false,
    replyTo: null, map: {}, menuFor: 0,
    view: 'list', threadRoot: 0, threadOffset: 0, threadHasMore: false, threadLoading: false
};
/* per-comment state for the inline "N replies" dropdowns inside the Replies screen:
   id -> { open, loaded, loading, offset, hasMore } */
var LFCS_SUB = {};
var LFM_CMT_WORDS = 30;    /* comments longer than this many words are cut with "...Read more" */
var LFM_CMT_CHARS = 280;   /* safety cap for very long single "words" */
var LFM_SORT_LABELS = { top: 'Top', 'new': 'Newest', old: 'Oldest' };

function lfmCsAvatar(url){ return '<img src="' + lfEsc(url) + '" alt="" onerror="lfAvatarFail(this)">'; }

/* turns plain text into safe HTML where http(s):// and www. links are red and clickable
   (same .lf-link look as the description), and where a leading "@name" — the mark left when
   replying to someone other than the thread's root — is colored like a mention */
function lfmLinkify(text){
    text = String(text || '');
    var mention = '', rest = text;
    var mm = text.match(/^(@[^\s<>"']{1,40})(\s|$)/);
    if (mm) { mention = mm[1]; rest = text.slice(mm[1].length); }

    var re = /((?:https?:\/\/|www\.)[^\s<>"']+)/gi;
    var out = mention ? '<span class="lfm-mention">' + lfEsc(mention) + '</span>' : '';
    var last = 0, m;
    while ((m = re.exec(rest)) !== null) {
        var url = m[0], trail = '';
        var tm = url.match(/[.,!?;:)\]}]+$/);
        if (tm) { trail = tm[0]; url = url.slice(0, url.length - trail.length); }
        if (!url) continue;
        var href = /^www\./i.test(url) ? 'https://' + url : url;
        out += lfEsc(rest.slice(last, m.index));
        out += '<a class="lf-link" href="' + lfEsc(href) + '" target="_blank" rel="noopener noreferrer nofollow">' + lfEsc(url) + '</a>' + lfEsc(trail);
        last = m.index + m[0].length;
    }
    out += lfEsc(rest.slice(last));
    return out;
}

/* returns the shortened text for a long comment, or null when it is short enough to show whole */
function lfmCutText(text){
    var full = String(text || '').replace(/\s+$/, '');
    var re = /\S+/g, m, n = 0, end = -1;
    while ((m = re.exec(full)) !== null) {
        n++;
        if (n === LFM_CMT_WORDS) { end = m.index + m[0].length; break; }
    }
    var cut = (end !== -1) ? full.slice(0, end) : full;
    if (cut.length > LFM_CMT_CHARS) {
        var s = cut.slice(0, LFM_CMT_CHARS), sp = s.lastIndexOf(' ');
        cut = (sp > LFM_CMT_CHARS * 0.5) ? s.slice(0, sp) : s;
    }
    cut = cut.replace(/\s+$/, '');
    return cut.length >= full.length ? null : cut;
}

function lfmCsTextHtml(text){
    var cut = lfmCutText(text);
    if (cut === null) return '<div class="lfm-cs-text">' + lfmLinkify(text) + '</div>';
    return '<div class="lfm-cs-text">' +
        '<span class="lfm-cs-short">' + lfmLinkify(cut) + '</span>' +
        '<span class="lfm-cs-full">' + lfmLinkify(text) + '</span> ' +
        '<button type="button" class="lfm-cs-readmore">...Read more</button>' +
    '</div>';
}

function lfmCsReplyLabel(n){ return n + (n === 1 ? ' reply' : ' replies'); }

/* the navigate-only "· 9 replies ›" row used for a TOP-LEVEL comment in the main list */
function lfmCsSummaryHtml(id, count, avatar){
    return '<button type="button" class="lfm-cs-viewreplies" data-id="' + id + '" data-count="' + count + '">' +
        '<span class="lfm-cs-vr-av">' + lfmCsAvatar(avatar || 'uploads/default.jpg') + '</span>' +
        '<span class="lfm-cs-vr-dot">&middot;</span>' +
        '<span class="lfm-cs-vr-label">' + lfmCsReplyLabel(count) + '</span>' +
        '<i class="ri-arrow-right-s-line lfm-cs-vr-chev"></i>' +
    '</button>';
}
/* the inline TOGGLE version used for any reply, at any depth, inside the Replies screen */
function lfmCsInlineToggleHtml(id, count, avatar){
    return '<button type="button" class="lfm-cs-viewreplies lfm-cs-subtoggle" data-id="' + id + '" data-count="' + count + '" data-inline="1">' +
        '<span class="lfm-cs-vr-av">' + lfmCsAvatar(avatar || 'uploads/default.jpg') + '</span>' +
        '<span class="lfm-cs-vr-dot">&middot;</span>' +
        '<span class="lfm-cs-vr-label">' + lfmCsReplyLabel(count) + '</span>' +
        '<i class="ri-arrow-right-s-line lfm-cs-vr-chev"></i>' +
    '</button>';
}

/* kind: 'top' (main list top-level comment — navigates to the Replies screen),
         'root' (the pinned comment at the top of the Replies screen — its own
                 replies are already the screen's whole content, so no toggle),
         'nested' (any reply inside the Replies screen, at any depth — gets the
                   inline dropdown toggle + a hidden holder for its sub-thread) */
function lfmCsCommentHtml(c, kind){
    kind = kind || 'top';
    LFCS.map[c.id] = c;
    var nameHtml = c.is_owner
        ? '<a class="lfm-cs-user is-owner" title="Owner" href="profile.php?user_id=' + c.user_id + '"><span class="lfm-cs-uname">' + lfEsc(c.username) + '</span>' + (c.verified ? lfmVb(true) : '<i class="ri-checkbox-circle-fill"></i>') + '</a>'
        : '<a class="lfm-cs-user" href="profile.php?user_id=' + c.user_id + '">' + lfEsc(c.username) + lfmVb(c.verified) + '</a>';
    /* "creator liked this": the creator's small red-ringed profile pic with a little red heart on its corner.
       Always in the markup (display:none when off) so lfmCsSetOwnerLike can toggle it live. */
    var crAv = (LFM.owner && LFM.owner.avatar) ? LFM.owner.avatar : 'uploads/default.jpg';
    var creatorLike = '<span class="lfm-cs-crlike" title="Liked by creator" aria-label="Liked by creator"' + (c.owner_liked ? '' : ' style="display:none;"') + '>' +
            '<span class="lfm-cs-crav">' + lfmCsAvatar(crAv) + '</span>' +
            '<i class="lfm-cs-crbadge ri-heart-fill"></i>' +
        '</span>';
    var row2 = '<div class="lfm-cs-row2">' +
            '<button type="button" class="lfm-cs-like' + (c.liked ? ' on' : '') + '" data-id="' + c.id + '" data-raw="' + c.likes + '" aria-label="Like comment"><i class="' + (c.liked ? 'ri-thumb-up-fill' : 'ri-thumb-up-line') + '"></i><span>' + (c.likes > 0 ? lfmFmtCount(c.likes) : '') + '</span></button>' +
            '<button type="button" class="lfm-cs-dislike' + (c.disliked ? ' on' : '') + '" data-id="' + c.id + '" aria-label="Dislike comment"><i class="' + (c.disliked ? 'ri-thumb-down-fill' : 'ri-thumb-down-line') + '"></i></button>' +
            '<button type="button" class="lfm-cs-reply" data-id="' + c.id + '" data-user="' + lfEsc(c.username) + '" aria-label="Reply">Reply</button>' +
            creatorLike +
        '</div>';

    var threadHtml = '';
    var hasThread = false;
    if (kind === 'top') {
        hasThread = c.replies > 0;
        if (hasThread) threadHtml = '<div class="lfm-cs-thread">' + lfmCsSummaryHtml(c.id, c.replies, c.reply_avatar) + '</div>';
    } else if (kind === 'nested') {
        hasThread = c.replies > 0;
        threadHtml =
            '<div class="lfm-cs-thread" id="lfm-threadwrap-' + c.id + '"' + (hasThread ? '' : ' style="display:none;"') + '>' +
                (hasThread ? lfmCsInlineToggleHtml(c.id, c.replies, c.reply_avatar) : '') +
            '</div>' +
            '<div class="lfm-cs-subthread" id="lfm-sub-' + c.id + '" style="display:none;"></div>';
    }
    /* kind === 'root': no thread UI at all — its replies ARE the screen's own list */

    return '' +
    '<div class="lfm-cs-item' + (hasThread ? ' has-thread' : '') + '" data-id="' + c.id + '">' +
        '<div class="lfm-cs-main">' +
            '<div class="lfm-cs-avcol">' +
                '<a class="lfm-cs-av" href="profile.php?user_id=' + c.user_id + '">' + lfmCsAvatar(c.avatar) + '</a>' +
                '<span class="lfm-cs-line"></span>' +
            '</div>' +
            '<div class="lfm-cs-body">' +
                '<div class="lfm-cs-line1">' + nameHtml +
                    (c.is_owner ? '<span class="lfm-cs-ownertag">Owner</span>' : '') +
                    '<span class="lfm-cs-time">' + lfEsc(c.ago) + '</span>' +
                    (c.pinned ? '<span class="lfm-cs-pin">Pinned</span>' : '') +
                    '<button type="button" class="lfm-cs-more" data-id="' + c.id + '" aria-label="Comment options" aria-haspopup="menu"><i class="ri-more-2-fill"></i></button>' +
                '</div>' +
                lfmCsTextHtml(c.text) +
                row2 +
            '</div>' +
        '</div>' +
        threadHtml +
    '</div>';
}

function lfmCsRenderList(list, append){
    var box = $('lfmCSList');
    if (!append) { box.innerHTML = list.map(function(c){ return lfmCsCommentHtml(c, 'top'); }).join(''); return; }
    var html = list.filter(function(c){ return !box.querySelector('.lfm-cs-item[data-id="' + c.id + '"]'); })
                   .map(function(c){ return lfmCsCommentHtml(c, 'top'); }).join('');
    box.insertAdjacentHTML('beforeend', html);
}

function lfmCsLoad(first){
    if (LFCS.loading || (!first && !LFCS.hasMore)) return;
    LFCS.loading = true;
    var postId = LFCS.postId, sort = LFCS.sort;
    fetch(LF_CFG.endpoint + '&lfm_comments=1&post=' + postId + '&sort=' + sort + '&offset=' + LFCS.offset, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (postId !== LFCS.postId || sort !== LFCS.sort || !LFCS.open) return;
            LFCS.loading = false;
            $('lfmCSSkel').style.display = 'none';
            if (!d || !d.success) return;
            lfmCsRenderList(d.comments, !first);
            LFCS.offset += d.comments.length;
            LFCS.hasMore = d.has_more;
            $('lfmCSSentinel').classList.toggle('show', LFCS.hasMore);
            $('lfmCSEmpty').style.display = (first && !d.comments.length) ? '' : 'none';
        })
        .catch(function(){ LFCS.loading = false; $('lfmCSSkel').style.display = 'none'; });
}
(function(){
    var s = $('lfmCSSentinel');
    if (!s || !('IntersectionObserver' in window)) return;
    new IntersectionObserver(function(entries){
        if (entries[0].isIntersecting) lfmCsLoad(false);
    }, { root: $('lfmCSScroll'), rootMargin: '250px 0px' }).observe(s);
})();

/* ── the Replies screen (top-level comment → its direct replies) ── */
function lfmCsEnsureViewRepliesBtn(parentId, count, avatar){
    var item = document.querySelector('#lfmCSList .lfm-cs-item[data-id="' + parentId + '"]');
    if (!item) return;
    var btn = item.querySelector('.lfm-cs-viewreplies');
    if (count <= 0) {
        if (btn && btn.parentNode) btn.parentNode.removeChild(btn);
        item.classList.remove('has-thread');
        return;
    }
    item.classList.add('has-thread');
    if (btn) {
        btn.setAttribute('data-count', count);
        var lbl = btn.querySelector('.lfm-cs-vr-label');
        if (lbl) lbl.textContent = lfmCsReplyLabel(count);
        if (avatar) { var av = btn.querySelector('.lfm-cs-vr-av'); if (av) av.innerHTML = lfmCsAvatar(avatar); }
        return;
    }
    var thread = document.createElement('div');
    thread.className = 'lfm-cs-thread';
    thread.innerHTML = lfmCsSummaryHtml(parentId, count, avatar);
    item.appendChild(thread);
}
function lfmCsOpenThread(rootId){
    var root = LFCS.map[rootId];
    if (!root) return;
    LFCS.view = 'replies';
    LFCS.threadRoot = rootId;
    LFCS.threadOffset = 0; LFCS.threadHasMore = false; LFCS.threadLoading = false;
    LFCS_SUB = {};
    lfmCsCloseMenu();
    $('lfmCSSortMenu').classList.remove('show');
    $('lfmCSHead').style.display = 'none';
    $('lfmCSScroll').style.display = 'none';
    $('lfmCSTHead').style.display = '';
    $('lfmCSTScroll').style.display = '';
    $('lfmCSTRoot').innerHTML = lfmCsCommentHtml(root, 'root');
    $('lfmCSTList').innerHTML = '';
    $('lfmCSTSkel').style.display = '';
    $('lfmCSTSentinel').classList.remove('show');
    $('lfmCSTScroll').scrollTop = 0;
    lfmCsSetReply(rootId, root.username, false);
    lfmCsLoadThread(true);
}
function lfmCsCloseThread(){
    LFCS.view = 'list';
    LFCS.threadRoot = 0;
    LFCS_SUB = {};
    $('lfmCSTHead').style.display = 'none';
    $('lfmCSTScroll').style.display = 'none';
    $('lfmCSHead').style.display = '';
    $('lfmCSScroll').style.display = '';
    lfmCsSetReply(null, '');
}
function lfmCsLoadThread(first){
    if (LFCS.threadLoading || (!first && !LFCS.threadHasMore)) return;
    LFCS.threadLoading = true;
    var postId = LFCS.postId, rootId = LFCS.threadRoot;
    fetch(LF_CFG.endpoint + '&lfm_replies=1&post=' + postId + '&comment_id=' + rootId + '&offset=' + LFCS.threadOffset, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (postId !== LFCS.postId || rootId !== LFCS.threadRoot || LFCS.view !== 'replies') return;
            LFCS.threadLoading = false;
            $('lfmCSTSkel').style.display = 'none';
            if (!d || !d.success) return;
            var box = $('lfmCSTList');
            var html = d.replies.filter(function(c){ return !box.querySelector('.lfm-cs-item[data-id="' + c.id + '"]'); })
                                 .map(function(c){ return lfmCsCommentHtml(c, 'nested'); }).join('');
            box.insertAdjacentHTML('beforeend', html);
            LFCS.threadOffset += d.replies.length;
            LFCS.threadHasMore = d.has_more;
            $('lfmCSTSentinel').classList.toggle('show', LFCS.threadHasMore);
        })
        .catch(function(){ LFCS.threadLoading = false; $('lfmCSTSkel').style.display = 'none'; });
}
(function(){
    var s = $('lfmCSTSentinel');
    if (!s || !('IntersectionObserver' in window)) return;
    new IntersectionObserver(function(entries){
        if (entries[0].isIntersecting) lfmCsLoadThread(false);
    }, { root: $('lfmCSTScroll'), rootMargin: '250px 0px' }).observe(s);
})();
$('lfmCSTBack').addEventListener('click', lfmCsCloseThread);
$('lfmCSTClose').addEventListener('click', lfmCsClose);

/* ── inline dropdowns for a reply's OWN replies (nested, at any depth) ── */
function lfmCsUpdateSubToggle(parentId, count, avatar){
    var wrap = document.getElementById('lfm-threadwrap-' + parentId);
    if (!wrap) return;
    var btn = wrap.querySelector('.lfm-cs-subtoggle');
    if (!btn) {
        wrap.innerHTML = lfmCsInlineToggleHtml(parentId, count, avatar);
    } else {
        btn.setAttribute('data-count', count);
        var lbl = btn.querySelector('.lfm-cs-vr-label');
        if (lbl) lbl.textContent = lfmCsReplyLabel(count);
        if (avatar) { var av = btn.querySelector('.lfm-cs-vr-av'); if (av) av.innerHTML = lfmCsAvatar(avatar); }
    }
    wrap.style.display = count > 0 ? '' : 'none';
    var item = wrap.closest('.lfm-cs-item');
    if (item) item.classList.toggle('has-thread', count > 0);
}
function lfmCsRenderSubMore(container, id, state){
    var old = container.querySelector('.lfm-cs-submore');
    if (old && old.parentNode) old.parentNode.removeChild(old);
    if (state.hasMore) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'lfm-cs-submore';
        b.setAttribute('data-id', id);
        b.textContent = 'Show more replies';
        container.appendChild(b);
    }
}
function lfmCsLoadSub(id, more){
    var state = LFCS_SUB[id];
    if (!state || state.loading || (more && !state.hasMore)) return;
    state.loading = true;
    var container = document.getElementById('lfm-sub-' + id);
    var postId = LFCS.postId;
    fetch(LF_CFG.endpoint + '&lfm_replies=1&post=' + postId + '&comment_id=' + id + '&offset=' + state.offset, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r){ return r.json(); })
        .then(function(d){
            state.loading = false;
            if (postId !== LFCS.postId || !LFCS.open || !container) return;
            if (!d || !d.success) { if (!more) container.innerHTML = ''; return; }
            state.loaded = true;
            var html = d.replies.map(function(c){ return lfmCsCommentHtml(c, 'nested'); }).join('');
            if (!more) container.innerHTML = html; else container.insertAdjacentHTML('beforeend', html);
            state.offset += d.replies.length;
            state.hasMore = d.has_more;
            lfmCsRenderSubMore(container, id, state);
        })
        .catch(function(){ state.loading = false; });
}
function lfmCsToggleSub(id, btn){
    var state = LFCS_SUB[id] || (LFCS_SUB[id] = { open: false, offset: 0, hasMore: false, loading: false, loaded: false });
    var container = document.getElementById('lfm-sub-' + id);
    if (!container) return;
    state.open = !state.open;
    btn.classList.toggle('open', state.open);
    container.style.display = state.open ? '' : 'none';
    if (state.open && !state.loaded) {
        container.innerHTML = '<div class="lfm-cs-sub-sk"><div class="sk-shimmer" style="width:26px;height:26px;border-radius:50%;flex-shrink:0;"></div><div style="flex:1;"><div class="sk-shimmer" style="height:9px;width:50%;"></div></div></div>';
        lfmCsLoadSub(id, false);
    }
}
/* inserts a freshly-posted reply wherever its parent is currently shown, and
   bumps / creates that parent's "N replies" toggle */
function lfmCsInsertReply(parentId, c){
    if (parentId === LFCS.threadRoot) {
        $('lfmCSTList').insertAdjacentHTML('beforeend', lfmCsCommentHtml(c, 'nested'));
        $('lfmCSTScroll').scrollTop = $('lfmCSTScroll').scrollHeight;
        LFCS.threadOffset += 1;
        return;
    }
    var wrap = document.getElementById('lfm-threadwrap-' + parentId);
    var curCount = 0;
    if (wrap) {
        var btn = wrap.querySelector('.lfm-cs-subtoggle');
        curCount = btn ? (parseInt(btn.getAttribute('data-count'), 10) || 0) : 0;
    }
    lfmCsUpdateSubToggle(parentId, curCount + 1, c.avatar);

    var state = LFCS_SUB[parentId];
    var container = document.getElementById('lfm-sub-' + parentId);
    if (state && state.open && container) {
        var moreBtn = container.querySelector('.lfm-cs-submore');
        var html = lfmCsCommentHtml(c, 'nested');
        if (moreBtn) moreBtn.insertAdjacentHTML('beforebegin', html); else container.insertAdjacentHTML('beforeend', html);
        state.offset += 1;
    }
}

function lfmCsSetReply(id, username, mention){
    LFCS.replyTo = id ? { id: id, username: username } : null;
    var input = $('lfmCSInput');
    if (mention && id && !input.value.trim()) input.value = '@' + username + ' ';
    input.placeholder = (LFCS.view === 'replies') ? 'Reply...' : 'Add a comment...';
    $('lfmCSSend').disabled = !input.value.trim();
}

/* shows / hides the little "creator liked this" avatar on one comment in the currently open screen
   (called after the creator likes / unlikes a comment) */
function lfmCsSetOwnerLike(id, on){
    document.querySelectorAll('#lfmCSheet .lfm-cs-item[data-id="' + id + '"]').forEach(function(item){
        var badge = item.querySelector('.lfm-cs-crlike');
        if (badge) badge.style.display = on ? '' : 'none';
    });
    if (LFCS.map[id]) LFCS.map[id].owner_liked = !!on;
}

/* ── the ⋯ menu: download / delete a comment ── */
function lfmCsCloseMenu(){
    $('lfmCSMenu').classList.remove('show');
    LFCS.menuFor = 0;
}
function lfmCsOpenMenu(btn){
    var id = parseInt(btn.getAttribute('data-id'), 10), c = LFCS.map[id];
    if (!c) return;
    var menu = $('lfmCSMenu');
    if (menu.classList.contains('show') && LFCS.menuFor === id) { lfmCsCloseMenu(); return; }
    LFCS.menuFor = id;
    menu.querySelector('[data-act="delete"]').style.display = c.can_delete ? '' : 'none';
    menu.classList.add('show');
    var sh = $('lfmCSheet').getBoundingClientRect(), br = btn.getBoundingClientRect();
    var mh = menu.offsetHeight;
    var top = br.bottom - sh.top + 4;
    if (top + mh > sh.height - 8) top = br.top - sh.top - mh - 4;     /* not enough room below → open upward */
    menu.style.left = 'auto';
    menu.style.top = Math.max(8, top) + 'px';
    menu.style.right = Math.max(8, sh.right - br.right) + 'px';
}
$('lfmCSMenu').addEventListener('click', function(e){
    var b = e.target.closest ? e.target.closest('button[data-act]') : null;
    if (!b) return;
    var act = b.getAttribute('data-act'), id = LFCS.menuFor;
    lfmCsCloseMenu();
    if (act === 'download') lfmCsDownload(id);
    else if (act === 'delete') lfmCsDelete(id);
});
$('lfmCSScroll').addEventListener('scroll', lfmCsCloseMenu, { passive: true });
$('lfmCSTScroll').addEventListener('scroll', lfmCsCloseMenu, { passive: true });

function lfmCsDownload(id){
    var c = LFCS.map[id];
    if (!c) return;
    var title = (LFM.data && LFM.data.title) || '';
    var body = 'Comment by @' + c.username + '\n' +
               'Posted: ' + c.ago + '\n' +
               (title ? 'On video: ' + title + '\n' : '') +
               '\n' + c.text + '\n';
    var blob = new Blob([body], { type: 'text/plain;charset=utf-8' });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url; a.download = 'comment-' + id + '.txt'; a.rel = 'noopener';
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
    setTimeout(function(){ URL.revokeObjectURL(url); }, 1500);
    lfToast('Comment downloaded', 'success');
}

function lfmCsDelete(id){
    var c = LFCS.map[id];
    if (!c || !c.can_delete) return;
    if (!confirm('Delete this comment? This cannot be undone.')) return;
    var postId = LFCS.postId;
    var wasRoot = (LFCS.view === 'replies' && id === LFCS.threadRoot);
    lfPost('lfm_comment_delete', { comment_id: id }).then(function(d){
        if (!d || !d.success) { lfToast((d && d.error) || 'Could not delete this comment.', 'error'); return; }
        delete LFCS.map[id];
        delete LFCS_SUB[id];

        if (wasRoot) {
            var mainItem = document.querySelector('#lfmCSList .lfm-cs-item[data-id="' + id + '"]');
            if (mainItem && mainItem.parentNode) mainItem.parentNode.removeChild(mainItem);
            LFCS.offset = Math.max(0, LFCS.offset - 1);
            lfmCsCloseThread();
        } else if (d.parent_id) {
            var el = document.querySelector('#lfmCSheet .lfm-cs-item[data-id="' + id + '"]');
            if (el && el.parentNode) el.parentNode.removeChild(el);

            if (d.parent_id === LFCS.threadRoot) {
                LFCS.threadOffset = Math.max(0, LFCS.threadOffset - 1);
                var curBtn = document.querySelector('#lfmCSList .lfm-cs-item[data-id="' + d.parent_id + '"] .lfm-cs-viewreplies');
                var newCount = Math.max(0, (curBtn ? (parseInt(curBtn.getAttribute('data-count'), 10) || 0) : 1) - 1);
                lfmCsEnsureViewRepliesBtn(d.parent_id, newCount, null);
            } else {
                var st = LFCS_SUB[d.parent_id];
                if (st) st.offset = Math.max(0, st.offset - 1);
                var wrap = document.getElementById('lfm-threadwrap-' + d.parent_id);
                var tbtn = wrap ? wrap.querySelector('.lfm-cs-subtoggle') : null;
                var newSubCount = Math.max(0, (tbtn ? (parseInt(tbtn.getAttribute('data-count'), 10) || 0) : 1) - 1);
                lfmCsUpdateSubToggle(d.parent_id, newSubCount, null);
            }
        } else {
            var el2 = document.querySelector('#lfmCSList .lfm-cs-item[data-id="' + id + '"]');
            if (el2 && el2.parentNode) el2.parentNode.removeChild(el2);
            LFCS.offset = Math.max(0, LFCS.offset - 1);
        }

        if (LFM.data && LFM.data.id === postId) {
            LFM.data.comments = Math.max(0, (LFM.data.comments || 0) - (d.removed || 1));
            if (LFM.preview && LFM.preview.id === id) LFM.preview = null;
            lfmRenderComments();
            lfmRenderActions();
        }
        if (LFCS.view === 'list' && !$('lfmCSList').children.length && !LFCS.hasMore) $('lfmCSEmpty').style.display = '';
        lfToast('Comment deleted', 'success');
    }).catch(function(){ lfToast('Could not delete this comment.', 'error'); });
}

/* ── one click handler for everything inside the comments sheet ── */
document.addEventListener('click', function(e){
    var t = e.target;
    function up(sel){ return (t && t.closest) ? t.closest(sel) : null; }

    $('lfmCSSortMenu').classList.remove('show');       /* the sort button stops propagation itself when it opens the menu */
    if (!LFCS.open) return;

    var more = up('.lfm-cs-more');
    if (more) { lfmCsOpenMenu(more); return; }
    if (!up('#lfmCSMenu')) lfmCsCloseMenu();

    var likeBtn = up('.lfm-cs-like');
    if (likeBtn) { lfmCsLike(likeBtn); return; }
    var disBtn = up('.lfm-cs-dislike');
    if (disBtn) { lfmCsDislike(disBtn); return; }
    var replyBtn = up('.lfm-cs-reply');
    if (replyBtn) {
        var rid = parseInt(replyBtn.getAttribute('data-id'), 10), ruser = replyBtn.getAttribute('data-user');
        if (LFCS.view === 'replies') lfmCsSetReply(rid, ruser, rid !== LFCS.threadRoot);
        else lfmCsOpenThread(rid);
        return;
    }
    var viewBtn = up('.lfm-cs-viewreplies');
    if (viewBtn) {
        var vid = parseInt(viewBtn.getAttribute('data-id'), 10);
        if (viewBtn.getAttribute('data-inline') === '1') lfmCsToggleSub(vid, viewBtn);
        else lfmCsOpenThread(vid);
        return;
    }
    var moreBtn = up('.lfm-cs-submore');
    if (moreBtn) { lfmCsLoadSub(parseInt(moreBtn.getAttribute('data-id'), 10), true); return; }
    var rm = up('.lfm-cs-readmore');
    if (rm) {
        var box = rm.parentNode;
        var open = box.classList.toggle('expanded');
        rm.textContent = open ? 'Show less' : '...Read more';
        return;
    }
});

function lfmCsPaintLike(btn, on, n){
    btn.classList.toggle('on', !!on);
    btn.querySelector('i').className = on ? 'ri-thumb-up-fill' : 'ri-thumb-up-line';
    btn.querySelector('span').textContent = n > 0 ? lfmFmtCount(n) : '';
    btn.setAttribute('data-raw', n);
}
function lfmCsPaintDis(btn, on){
    btn.classList.toggle('on', !!on);
    btn.querySelector('i').className = on ? 'ri-thumb-down-fill' : 'ri-thumb-down-line';
}
/* like a comment (a like replaces a dislike) */
function lfmCsLike(btn){
    var id = btn.getAttribute('data-id'), row = btn.closest('.lfm-cs-row2');
    var dBtn = row ? row.querySelector('.lfm-cs-dislike') : null;
    var wasLike = btn.classList.contains('on'), wasDis = !!(dBtn && dBtn.classList.contains('on'));
    var n = parseInt(btn.getAttribute('data-raw'), 10) || 0;
    var nowLike = !wasLike, nowN = Math.max(0, n + (nowLike ? 1 : -1));
    lfmCsPaintLike(btn, nowLike, nowN);
    if (dBtn) lfmCsPaintDis(dBtn, false);
    function revert(){ lfmCsPaintLike(btn, wasLike, n); if (dBtn) lfmCsPaintDis(dBtn, wasDis); }
    lfPost('lfm_comment_like', { comment_id: id }).then(function(d){
        if (!d || !d.success) { revert(); return; }
        lfmCsPaintLike(btn, d.liked, d.likes);
        if (dBtn) lfmCsPaintDis(dBtn, !!d.disliked);
        /* the toggle only came from the video owner when the server sends owner_liked back */
        if (d.owner_liked !== null && d.owner_liked !== undefined) lfmCsSetOwnerLike(id, d.owner_liked);
    }).catch(revert);
}
/* dislike a comment (a dislike replaces a like) */
function lfmCsDislike(btn){
    var id = btn.getAttribute('data-id'), row = btn.closest('.lfm-cs-row2');
    var lBtn = row ? row.querySelector('.lfm-cs-like') : null;
    var wasDis = btn.classList.contains('on'), wasLike = !!(lBtn && lBtn.classList.contains('on'));
    var n = lBtn ? (parseInt(lBtn.getAttribute('data-raw'), 10) || 0) : 0;
    var nowDis = !wasDis;
    lfmCsPaintDis(btn, nowDis);
    if (lBtn && nowDis && wasLike) lfmCsPaintLike(lBtn, false, Math.max(0, n - 1));
    function revert(){ lfmCsPaintDis(btn, wasDis); if (lBtn) lfmCsPaintLike(lBtn, wasLike, n); }
    lfPost('lfm_comment_dislike', { comment_id: id }).then(function(d){
        if (!d || !d.success) { revert(); return; }
        lfmCsPaintDis(btn, !!d.disliked);
        if (lBtn) lfmCsPaintLike(lBtn, !!d.liked, d.likes);
        if (d.owner_liked !== null && d.owner_liked !== undefined) lfmCsSetOwnerLike(id, d.owner_liked);
    }).catch(revert);
}

function lfmCsSend(){
    var input = $('lfmCSInput'), text = input.value.trim();
    if (!text || !LFCS.postId) return;
    var send = $('lfmCSSend');
    send.disabled = true;
    var inThread = (LFCS.view === 'replies');
    var target = inThread ? (LFCS.replyTo ? LFCS.replyTo.id : LFCS.threadRoot) : 0;
    var postId = LFCS.postId, rootAtSend = LFCS.threadRoot;

    lfPost('lfm_comment_add', { post_id: postId, text: text, parent_id: target }).then(function(d){
        send.disabled = !input.value.trim();
        if (!d || !d.success) { lfToast((d && d.error) || 'Could not post your comment.', 'error'); return; }
        input.value = '';
        send.disabled = true;
        var c = d.comment;

        if (inThread && d.parent_id) {
            lfmCsInsertReply(d.parent_id, c);
        } else if (!inThread) {
            var listEl = $('lfmCSList');
            var atEnd = (LFCS.sort === 'old' && !LFCS.hasMore);
            var chtml = lfmCsCommentHtml(c, 'top');
            if (atEnd) listEl.insertAdjacentHTML('beforeend', chtml); else listEl.insertAdjacentHTML('afterbegin', chtml);
            LFCS.offset += 1;
            $('lfmCSEmpty').style.display = 'none';
            var newEl = listEl.querySelector('.lfm-cs-item[data-id="' + c.id + '"]');
            if (atEnd && newEl && newEl.scrollIntoView) newEl.scrollIntoView({ block: 'nearest' });
            else $('lfmCSScroll').scrollTop = 0;
            if (LFM.data && LFM.data.id === postId) {
                LFM.preview = { id: c.id, user: c.username, avatar: c.avatar, text: String(c.text).slice(0, 200) };
            }
        }
        if (LFM.data && LFM.data.id === postId) { LFM.data.comments = (LFM.data.comments || 0) + 1; lfmRenderComments(); lfmRenderActions(); }

        /* after sending, further typing goes back to the thread's root unless Reply is tapped again */
        if (inThread) {
            var rootC = LFCS.map[rootAtSend];
            lfmCsSetReply(rootAtSend, rootC ? rootC.username : '', false);
        } else {
            lfmCsSetReply(null, '');
        }
    }).catch(function(){ send.disabled = !input.value.trim(); lfToast('Could not post your comment.', 'error'); });
}
$('lfmCSSend').addEventListener('click', lfmCsSend);
$('lfmCSInput').addEventListener('input', function(){ $('lfmCSSend').disabled = !this.value.trim(); });
$('lfmCSInput').addEventListener('keydown', function(e){ if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); if (this.value.trim()) lfmCsSend(); } });

$('lfmCSSortBtn').addEventListener('click', function(e){ e.stopPropagation(); lfmCsCloseMenu(); $('lfmCSSortMenu').classList.toggle('show'); });
Array.prototype.forEach.call($('lfmCSSortMenu').querySelectorAll('button'), function(b){
    b.addEventListener('click', function(e){
        e.stopPropagation();
        var s = this.getAttribute('data-sort');
        $('lfmCSSortMenu').classList.remove('show');
        if (s === LFCS.sort) return;
        LFCS.sort = s;
        $('lfmCSSortLbl').textContent = LFM_SORT_LABELS[s] || 'Top';
        Array.prototype.forEach.call($('lfmCSSortMenu').querySelectorAll('button'), function(x){ x.classList.remove('active'); });
        this.classList.add('active');
        lfmCsRestart();
    });
});
function lfmCsRestart(){
    LFCS.offset = 0; LFCS.hasMore = false; LFCS.loading = false; LFCS.map = {};
    LFCS_SUB = {};
    lfmCsCloseMenu();
    lfmCsCloseThread();
    $('lfmCSList').innerHTML = '';
    $('lfmCSSkel').style.display = '';
    $('lfmCSEmpty').style.display = 'none';
    $('lfmCSSentinel').classList.remove('show');
    lfmCsLoad(true);
}

function lfmCsOpen(postId){
    LFCS.open = true; LFCS.postId = postId;
    LFCS.view = 'list'; LFCS.threadRoot = 0; LFCS.threadOffset = 0; LFCS.threadHasMore = false; LFCS.threadLoading = false;
    LFCS_SUB = {};
    $('lfmCSHead').style.display = '';
    $('lfmCSScroll').style.display = '';
    $('lfmCSTHead').style.display = 'none';
    $('lfmCSTScroll').style.display = 'none';
    lfmCsSetReply(null, '');
    if (LFM.viewer && LFM.viewer.avatar) $('lfmCSMyAv').innerHTML = lfmCsAvatar(LFM.viewer.avatar);
    if (LFM.data) $('lfmCSCount').textContent = ' · ' + lfmFmtCount(LFM.data.comments || 0);
    lfmSyncSheetTop();
    $('lfmCSScroll').scrollTop = 0;
    $('lfmCSheet').classList.add('open');
    clearTimeout(LFM.hideTimer);
    lfmCsRestart();
}
function lfmCsClose(){
    var d = $('lfmCSheet');
    d.classList.remove('open');
    d.style.transition = ''; d.style.transform = '';
    lfmCsCloseMenu();
    $('lfmCSSortMenu').classList.remove('show');
    LFCS.open = false;
    LFCS.view = 'list'; LFCS.threadRoot = 0;
    LFCS_SUB = {};
    lfmArmHide();
}
$('lfmCSClose').addEventListener('click', lfmCsClose);
/* dragging the comments sheet down past the threshold minimizes the whole player into
   the mini player (lfmMinimize already closes the comments sheet itself) */
lfmSwipe($('lfmCSheet'), [$('lfmCSHandle'), $('lfmCSHead'), $('lfmCSTHead')], function(){ lfmMinimize(); }, false);

/* ══════════════ shrink to the corner (mini player) and bring it back ══════════════ */
function lfmMinimize(fromPop){
    if (!LFM.open || LFM.mini) return;
    if (lfmFsActive()) lfmExitFs();
    lfmClearNext(); lfmCloseDesc(); lfmCloseSettings(); lfmCsClose(); lfmTapReset();
    lfmHideControls();
    LFM.player.classList.remove('lfm-hover-ctl');
    LFM.scrubbing = false; LFM.player.classList.remove('scrub');
    LFM.mini = true; LFM.miniAt = Date.now();
    $('lfmOverlay').classList.add('mini');
    lfmMiniApplyPos();                               /* back where you last left it */
    var up = $('uploadModal'), sv = $('savedModal');
    if ((!up || !up.classList.contains('active')) && (!sv || !sv.classList.contains('active'))) document.body.style.overflow = '';   /* the page scrolls again */
    if (!fromPop && LFM.pushed) {                    /* drop the history entry the full modal added */
        LFM.pushed = false; LFM.skipPop = true;
        try { history.back(); } catch (e) { LFM.skipPop = false; }
    }
    lfmSyncPlayIcon();
}
function lfmExpand(){
    if (!LFM.open || !LFM.mini) return;
    clearTimeout(LFM.miniTapTimer); LFM.miniTapTimer = 0;
    lfmMiniReset();
    LFM.mini = false;
    $('lfmOverlay').classList.remove('mini');
    document.body.style.overflow = 'hidden';
    if (!LFM.pushed && !LFM.skipPop) {
        try { history.pushState({ lfm: 1 }, '', window.location.href); LFM.pushed = true; } catch (e) {}
    }
    lfmSyncSheetTop();
    lfmTick();
    lfmShowControls();
}
/* ── mini player: drag it anywhere on the page, double-tap it to make it wide (and again to shrink it) ── */
function lfmMiniReset(){
    var ov = $('lfmOverlay');
    ov.style.left = ''; ov.style.top = ''; ov.style.right = ''; ov.style.bottom = '';
    ov.classList.remove('mini-wide', 'dragging');
    LFM.wide = false;
}
/* keeps the card fully on screen (when it is wide only the height is free to move) */
function lfmMiniClamp(){
    if (!LFM.mini) return;
    var ov = $('lfmOverlay'), w = ov.offsetWidth, h = ov.offsetHeight;      /* layout size: unaffected by the pop-in animation */
    var vw = document.documentElement.clientWidth;                          /* screen width without the page scrollbar */
    if (ov.style.top) {
        ov.style.top = Math.min(Math.max(0, parseFloat(ov.style.top) || 0), Math.max(0, window.innerHeight - h)) + 'px';
    }
    if (!LFM.wide && ov.style.left) {
        ov.style.left = Math.min(Math.max(0, parseFloat(ov.style.left) || 0), Math.max(0, vw - w)) + 'px';
    }
}
function lfmMiniApplyPos(){
    var p = LFM.miniPos, ov = $('lfmOverlay');
    if (!p) return;
    if (p.x !== null && p.x !== undefined) { ov.style.left = p.x + 'px'; ov.style.right = 'auto'; }
    ov.style.top = p.y + 'px'; ov.style.bottom = 'auto';
    lfmMiniClamp();
}
function lfmMiniToggleWide(){
    if (!LFM.mini) return;
    LFM.wide = !LFM.wide;
    $('lfmOverlay').classList.toggle('mini-wide', LFM.wide);
    lfmMiniClamp();
}
(function(){
    var pl = LFM.player, ov = $('lfmOverlay'), d = null;
    pl.addEventListener('pointerdown', function(e){
        if (!LFM.mini) return;
        if (e.target.closest('.lfm-mini-btn')) return;              /* the pause / x buttons stay plain buttons */
        if (e.pointerType === 'mouse' && e.button !== 0) return;
        var r = ov.getBoundingClientRect();
        d = { id: e.pointerId, sx: e.clientX, sy: e.clientY, ox: r.left, oy: r.top, w: r.width, h: r.height, moved: false };
        try { pl.setPointerCapture(e.pointerId); } catch (err) {}
    });
    pl.addEventListener('pointermove', function(e){
        if (!d || e.pointerId !== d.id || !LFM.mini) return;
        var dx = e.clientX - d.sx, dy = e.clientY - d.sy;
        if (!d.moved) {
            if (Math.abs(dx) < 6 && Math.abs(dy) < 6) return;       /* a tap, not a drag yet */
            d.moved = true;
            clearTimeout(LFM.miniTapTimer); LFM.miniTapTimer = 0;
            ov.classList.add('dragging');
            /* switch from the bottom-right anchor to plain left / top positioning */
            if (!LFM.wide) { ov.style.left = d.ox + 'px'; ov.style.right = 'auto'; }
            ov.style.top = d.oy + 'px'; ov.style.bottom = 'auto';
        }
        var x = Math.min(Math.max(0, d.ox + dx), Math.max(0, document.documentElement.clientWidth - d.w));
        var y = Math.min(Math.max(0, d.oy + dy), Math.max(0, window.innerHeight - d.h));
        if (!LFM.wide) ov.style.left = x + 'px';
        ov.style.top = y + 'px';
    });
    function end(e){
        if (!d || e.pointerId !== d.id) return;
        var moved = d.moved;
        d = null;
        try { pl.releasePointerCapture(e.pointerId); } catch (err) {}
        ov.classList.remove('dragging');
        if (!moved) return;
        LFM.dragT = Date.now();
        var x = (!LFM.wide && ov.style.left) ? parseFloat(ov.style.left) : (LFM.miniPos ? LFM.miniPos.x : null);
        LFM.miniPos = { x: x, y: parseFloat(ov.style.top) };        /* remembered for next time */
    }
    pl.addEventListener('pointerup', end);
    pl.addEventListener('pointercancel', end);
})();
window.addEventListener('resize', lfmMiniClamp);

$('lfmMiniPlay').addEventListener('click', function(e){ e.stopPropagation(); lfmTogglePlay(); });
$('lfmMiniClose').addEventListener('click', function(e){ e.stopPropagation(); lfmClose(); });

/* ══════════════ open / switch / close ══════════════ */
function openLongformModal(id, keepMini){
    id = parseInt(id, 10);
    if (!id) return;
    var ov = $('lfmOverlay');

    if (typeof lfPreviewStop === 'function') lfPreviewStop();   /* a thumbnail preview must not keep playing under the player */

    if (LFM.open && LFM.id === id) {                 /* same video: just bring the details back into view */
        if (LFM.mini) { lfmExpand(); return; }        /* ...or, if it is shrunk in the corner, open it full size */
        lfmCloseDesc(); lfmCsClose();
        $('lfmScroll').scrollTo({ top: 0, behavior: 'smooth' });
        return;
    }
    var switching = LFM.open;
    if (switching) lfmSaveProgress(true);
    if (LFM.mini && !keepMini) lfmExpand();          /* opening another video from the page brings the modal back full size */

    lfmClearNext(); lfmCloseDesc(); lfmCloseSettings(); lfmCsClose(); lfmTapReset();
    LFM.token++;
    var token = LFM.token;
    LFM.id = id; LFM.data = null; LFM.preview = null; LFM.viewer = null;
    LFM.playedSecs = 0; LFM.lastT = 0; LFM.viewSent = false; LFM.lastSent = 0;
    LFM.resumeAt = 0; LFM.resumed = true;
    LFM.listIds = []; LFM.listOffset = 0; LFM.listMore = false; LFM.listLoading = false;
    LFM.busyReact = false; LFM.busySave = false; LFM.busyDl = false;

    /* player back to a clean state */
    LV.pause();
    LV.removeAttribute('src'); LV.removeAttribute('poster');
    try { LV.load(); } catch (e) {}
    lfmErr(false); lfmWheel(true);
    LFM.player.classList.remove('ctl', 'scrub', 'lfm-hover-ctl');
    lfmLast.cur = ''; lfmLast.dur = '';
    lfmTick(); lfmSyncPlayIcon();

    /* start loading the file right away when the card already knows its path
       (grid card, a card inside this modal, or a card in the Saved list) */
    var card = $('lf-card-' + id) || $('lfm-card-' + id) || $('lf-scard-' + id);
    if (card) {
        var th = card.querySelector('.lf-thumb-img');
        if (th && th.src) LV.poster = th.src;
        var vp = card.getAttribute('data-video');
        if (vp) LV.src = vp;
    }
    LV.loop = LFM.loop;

    /* skeletons for everything under the video */
    $('lfmBody').style.display = 'none';
    $('lfmSkel').style.display = '';
    $('lfmList').innerHTML = '';
    $('lfmListSkel').style.display = '';
    $('lfmSentinel').classList.remove('show');
    $('lfmScroll').scrollTop = 0;

    if (!switching) {
        ov.classList.add('open');
        document.body.style.overflow = 'hidden';
        try { history.pushState({ lfm: 1 }, '', window.location.href); LFM.pushed = true; } catch (e) {}
    }
    LFM.open = true;
    lfmSyncSheetTop();

    fetch(LF_CFG.endpoint + '&lfm_post=' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (token !== LFM.token) return;
            if (!d || !d.success) { lfToast((d && d.error) || 'That video could not be found.', 'error'); lfmClose(); return; }
            LFM.data = d.post; LFM.owner = d.owner; LFM.preview = d.preview; LFM.viewer = d.viewer || null;
            lfmRender();

            if (!LV.getAttribute('src') && d.post.video) {
                if (d.post.thumb) LV.poster = d.post.thumb;
                LV.src = d.post.video;
            } else if (!LV.getAttribute('poster') && d.post.thumb) {
                LV.poster = d.post.thumb;
            }
            var pr = d.post.progress || 0;
            LFM.resumeAt = (pr > 5 && d.post.duration && pr < d.post.duration * 0.97) ? pr : 0;
            LFM.resumed = false;
            if (LV.readyState >= 1) lfmApplyResume();
            lfmSyncSheetTop();
            lfmStartPlayback(token);
            lfmLoadList(token, true);
        })
        .catch(function(){
            if (token !== LFM.token) return;
            lfToast('Could not load this video. Check your connection.', 'error');
            lfmClose();
        });
}

function lfmClose(fromPop){
    if (!LFM.open) return;
    lfmSaveProgress(true);
    lfmClearNext(); lfmCloseSettings(); lfmCloseDesc(); lfmCsClose(); lfmTapReset();
    if (lfmFsActive()) lfmExitFs();
    LFM.open = false; LFM.token++; LFM.id = 0;
    LV.pause();
    LV.removeAttribute('src'); LV.removeAttribute('poster');
    try { LV.load(); } catch (e) {}
    cancelAnimationFrame(LFM.raf); LFM.raf = 0;
    clearTimeout(LFM.hideTimer);
    lfmErr(false);
    clearTimeout(LFM.miniTapTimer); LFM.miniTapTimer = 0;
    lfmMiniReset();
    LFM.mini = false;
    LFM.player.classList.remove('lfm-hover-ctl');
    $('lfmOverlay').classList.remove('open', 'mini');
    var up = $('uploadModal'), sv = $('savedModal');
    if ((!up || !up.classList.contains('active')) && (!sv || !sv.classList.contains('active'))) document.body.style.overflow = '';
    if (!fromPop && LFM.pushed) { LFM.pushed = false; try { history.back(); } catch (e) {} }
}
window.addEventListener('popstate', function(){
    if (LFM.skipPop) { LFM.skipPop = false; return; }   /* that was our own history.back() from minimizing */
    if (LFM.open && !LFM.mini) { LFM.pushed = false; lfmMinimize(true); }
});

/* keyboard (capture phase, so it runs before the page's own Escape handlers) */
document.addEventListener('keydown', function(e){
    if (!LFM.open || LFM.mini) return;
    var tag = (e.target && e.target.tagName) || '';
    if (tag === 'INPUT' || tag === 'TEXTAREA') return;
    if (e.key === 'Escape') {
        if ($('lfActOverlay').classList.contains('open')) return;
        if ($('lfmSetOverlay').classList.contains('open')) { lfmCloseSettings(); return; }
        if ($('lfmCSMenu').classList.contains('show')) { lfmCsCloseMenu(); return; }
        if ($('lfmCSheet').classList.contains('open')) {
            if (LFCS.view === 'replies') { lfmCsCloseThread(); return; }
            lfmCsClose(); return;
        }
        if ($('lfmDesc').classList.contains('open')) { lfmCloseDesc(); return; }
        if (lfmFsActive()) { lfmExitFs(); return; }
        lfmMinimize();
        return;
    }
    if ($('lfActOverlay').classList.contains('open') || $('lfmSetOverlay').classList.contains('open') || $('lfmCSheet').classList.contains('open')) return;
    if ((e.key === ' ' || e.key === 'k') && tag !== 'BUTTON') { e.preventDefault(); lfmTogglePlay(); lfmShowControls(); }
    else if (e.key === 'ArrowLeft')  { e.preventDefault(); lfmSeekBy(-5); lfmShowControls(); }
    else if (e.key === 'ArrowRight') { e.preventDefault(); lfmSeekBy(5);  lfmShowControls(); }
    else if (e.key === 'f') { if (lfmFsActive()) lfmExitFs(); else lfmEnterFs(); }
    else if (e.key === 'm') { lfmToggleMute(); lfmShowControls(); }
}, true);

/* ══════════════════════════════════════════════════════════════
   PAGE FUNCTIONS THAT NOW HAVE TO KNOW ABOUT THE MODAL
   (they replace the versions in the page's own script)
   ══════════════════════════════════════════════════════════════ */

/* the ⋯ sheet: works for grid cards, cards inside the modal, cards in the Saved list, and the playing video */
function lfmOpenActionsSheet(id, card, pane){
    ACT.id = id; ACT.card = card; ACT.busy = false;
    var own = card.getAttribute('data-own') === '1';
    $('lfActTitle').textContent = card.getAttribute('data-title') || '';
    var img = card.querySelector('.lf-thumb-img');
    var th  = img ? img.src : (card.getAttribute('data-thumb') || '');
    $('lfActThumb').style.backgroundImage = th ? 'url("' + th.replace(/"/g, '%22') + '")' : 'none';
    $('lfActReport').style.display = own ? 'none' : '';
    $('lfActDelete').style.display = own ? '' : 'none';
    lfActRefreshSave();
    lfActShowPane((pane === 'report' && !own) ? 'report' : 'main');
    var panel = $('lfActPanel');
    panel.style.transition = ''; panel.style.transform = '';
    $('lfActOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function lfOpenActions(id, e){
    var btn = null;
    if (e) { e.stopPropagation(); e.preventDefault(); btn = e.currentTarget; }
    var card = (btn && btn.closest && btn.closest('.lf-card')) || $('lf-card-' + id) || $('lfm-card-' + id) || $('lf-scard-' + id);
    if (!card) return;
    lfmOpenActionsSheet(id, card);
}
function lfCloseActions(){
    $('lfActOverlay').classList.remove('open');
    var up = $('uploadModal'), sv = $('savedModal'), m = $('lfmOverlay');
    var upOpen = !!(up && up.classList.contains('active'));
    var svOpen = !!(sv && sv.classList.contains('active'));
    var lfmOpen = !!(m && m.classList.contains('open') && !LFM.mini);
    if (!upOpen && !svOpen && !lfmOpen) document.body.style.overflow = '';
}
function lfActSave(){
    if (ACT.busy) return;
    ACT.busy = true;
    var id = ACT.id;
    lfPost('toggle_save_longform', { post_id: id })
        .then(function(d){
            ACT.busy = false;
            if (!d || !d.success) { lfToast((d && d.error) || 'Could not update your saved posts.', 'error'); return; }
            lfmSyncSaved(id, !!d.saved);
            if (!d.saved) lfmRemoveFromSavedList(id);      /* unsaved from the Saved list (or anywhere): it leaves that list */
            lfCloseActions();
            lfToast(d.saved ? 'Post saved' : 'Removed from saved', 'success');
        })
        .catch(function(){ ACT.busy = false; lfToast('Could not update your saved posts.', 'error'); });
}
function lfDeletePost(id){
    if (!confirm('Delete this video permanently? This cannot be undone.')) return;
    var fd = new FormData();
    fd.append('delete_longform', '1');
    fd.append('post_id', id);
    fd.append('csrf_token', LF_CFG.csrf);
    fetch(LF_CFG.endpoint, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (!d || !d.success) { lfToast((d && d.error) || 'Could not delete this video.', 'error'); return; }
            if (LFM.open && LFM.id === id) lfmClose();
            var inGrid = !!$('lf-card-' + id);
            var cards  = document.querySelectorAll('.lf-card[data-post-id="' + id + '"]');
            cards.forEach(function(c){
                /* a card in the Saved list is removed by the Saved list itself (it also keeps its own paging in step) */
                if (c.closest('#savedList') && typeof lfSavedRemoveCard === 'function') lfSavedRemoveCard(c);
                else c.classList.add('removing');
            });
            LFM.listIds = LFM.listIds.filter(function(x){ return x !== id; });
            setTimeout(function(){
                cards.forEach(function(c){ if (c.parentNode) c.remove(); });
                if (inGrid) { LF.offset = Math.max(0, LF.offset - 1); lfSetTotal(LF.total - 1); }
            }, 260);
            if (!inGrid) lfSetTotal(LF.total - 1);
            lfToast('Video deleted', 'success');
        })
        .catch(function(){ lfToast('Could not delete this video.', 'error'); });
}

/* a shared link (?post=ID) opens the modal straight away */
if (LF_CFG.openPost) { openLongformModal(LF_CFG.openPost); }
</script>
<?php }