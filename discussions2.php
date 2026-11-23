<?php
session_start();
include 'config.php';

$current_user = (int)($_SESSION['user_id'] ?? 0);
if (!$current_user) { header("Location: login.php"); exit(); }

$group_id = (int)($_GET['pod_id'] ?? 0);
if (!$group_id) { echo "No Pod specified."; exit(); }

$stmt = $conn->prepare("
    SELECT g.*, u.username AS creator_name
    FROM groups g
    JOIN users u ON g.created_by = u.id
    WHERE g.id = ?
");
$stmt->bind_param("i", $group_id);
$stmt->execute();
$group = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$group) { echo "Pod not found."; exit(); }

/* ══════════════════════════════════════════════════════════════════
   CSRF TOKEN
   ══════════════════════════════════════════════════════════════════ */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!$submitted_token || !hash_equals($_SESSION['csrf_token'], $submitted_token)) {
        http_response_code(403);
        if (isset($_POST['ajax_like']) || isset($_POST['ajax_react'])) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid or missing CSRF token']);
        } else {
            echo "Invalid request (CSRF check failed). Please go back and try again.";
        }
        exit();
    }
}

// ── Banner / group pic ──
$disc_group_pic_raw = $group['group_pic'] ?? '';
$disc_img_src = empty($disc_group_pic_raw)
    ? 'uploads/default_group.png'
    : (strpos($disc_group_pic_raw, 'uploads/') === 0 ? $disc_group_pic_raw : 'uploads/' . $disc_group_pic_raw);

$disc_banner_raw = $group['banner_pic'] ?? '';
$disc_banner_src = !empty($disc_banner_raw)
    ? (strpos($disc_banner_raw, 'uploads/') === 0 ? $disc_banner_raw : 'uploads/' . $disc_banner_raw)
    : $disc_img_src;

$stmt = $conn->prepare("SELECT 1 FROM group_members WHERE group_id=? AND user_id=?");
$stmt->bind_param("ii", $group_id, $current_user);
$stmt->execute();
$is_member = $stmt->get_result()->num_rows > 0;
$stmt->close();

$is_owner = ($group['created_by'] == $current_user);

$stmt = $conn->prepare("SELECT 1 FROM group_admins WHERE group_id=? AND user_id=?");
$stmt->bind_param("ii", $group_id, $current_user);
$stmt->execute();
$is_admin = $stmt->get_result()->num_rows > 0;
$stmt->close();

$is_mod = ($is_owner || $is_admin);

$stmt = $conn->prepare("SELECT COUNT(*) as c FROM group_members WHERE group_id=?");
$stmt->bind_param("i", $group_id);
$stmt->execute();
$member_count = $stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

$active_users = [];
$stmt = $conn->prepare("
    SELECT u.id, u.username, u.profile_pic
    FROM group_members gm
    JOIN users u ON u.id = gm.user_id
    WHERE gm.group_id = ?
      AND u.last_seen >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
    LIMIT 8
");
$stmt->bind_param("i", $group_id);
$stmt->execute();
$active_res = $stmt->get_result();
while ($arow = $active_res->fetch_assoc()) $active_users[] = $arow;
$stmt->close();
$active_count = count($active_users);

/* ══════════════════════════════════════════════════════════════════
   PREFILL FROM GROUP POST — "Discuss" button on group.php
   ══════════════════════════════════════════════════════════════════
   Only the meme's media transfers over (primary file + any meme_media
   gallery extras). Caption, audio, and partnership tags are NOT
   carried over — title/body start blank and the tag defaults to
   "Discussion" (already the default-selected pill in the modal).

   The meme_id is re-validated against THIS pod's group_posts table
   server-side, so a hand-edited URL can never pull media from a
   different pod or a post the viewer shouldn't have access to.

   $prefill_meme_id is also persisted into pod_discussions.source_meme_id
   when the new thread is created, so the resulting post can show a
   "From Zeem · View Zeem ↗" link back to the original group post.
   ══════════════════════════════════════════════════════════════════ */
$prefill_media = [];
$prefill_meme_id = (int)($_GET['prefill_meme_id'] ?? 0);
$prefill_is_external = !empty($_GET['external_meme']);

if ($prefill_meme_id && $is_member) {
    $prefill_primary = null;

    if ($prefill_is_external) {
        // Connected Zeem (or any meme the user owns) that was never
        // actually posted INTO this pod — no group_posts row exists
        // for it. Trust ownership instead, mirroring the exact
        // validation group.php's ajax_get_connected_meme handler
        // already uses.
        $stmt = $conn->prepare("
            SELECT file_name, type
            FROM memes
            WHERE id = ? AND uploaded_by = ?
            LIMIT 1
        ");
        $stmt->bind_param("ii", $prefill_meme_id, $current_user);
        $stmt->execute();
        $prefill_primary = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } else {
        // Normal case: meme must actually be posted in THIS pod.
        $stmt = $conn->prepare("
            SELECT m.file_name, m.type
            FROM group_posts gp
            JOIN memes m ON m.id = gp.meme_id
            WHERE gp.meme_id = ? AND gp.group_id = ?
            LIMIT 1
        ");
        $stmt->bind_param("ii", $prefill_meme_id, $group_id);
        $stmt->execute();
        $prefill_primary = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // Fallback: if it's not in group_posts for this pod, it might
        // still be a meme the user owns (e.g. they came via a
        // Connected Zeem but the client forgot to flag external_meme,
        // or the post was later removed from group_posts). Never
        // trust it for anyone other than its actual owner.
        if (!$prefill_primary) {
            $stmt = $conn->prepare("
                SELECT file_name, type
                FROM memes
                WHERE id = ? AND uploaded_by = ?
                LIMIT 1
            ");
            $stmt->bind_param("ii", $prefill_meme_id, $current_user);
            $stmt->execute();
            $prefill_primary = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
    }

    if ($prefill_primary) {
        if (!empty($prefill_primary['file_name'])) {
            $prefill_media[] = [
                'file' => $prefill_primary['file_name'],
                'type' => $prefill_primary['type'] ?: 'image',
            ];
        }

        $stmt = $conn->prepare("SELECT file_name, type FROM meme_media WHERE meme_id=? ORDER BY position ASC, id ASC");
        $stmt->bind_param("i", $prefill_meme_id);
        $stmt->execute();
        $mm_res = $stmt->get_result();
        while ($mm = $mm_res->fetch_assoc()) {
            $prefill_media[] = ['file' => $mm['file_name'], 'type' => $mm['type']];
        }
        $stmt->close();
    } else {
        // Neither path resolved it — never trust the id further downstream
        $prefill_meme_id = 0;
    }
}
$has_prefill = !empty($prefill_media);

// ── CREATE THREAD ──
if (isset($_POST['create_thread']) && $is_member) {
    $title             = trim($_POST['title'] ?? '');
    $body              = trim($_POST['body'] ?? '');
    $tag               = $_POST['tag'] ?? 'Discussion';
    $comments_disabled = isset($_POST['disable_comments']) ? 1 : 0;

    $poll_type_raw = $_POST['poll_type'] ?? '';
    $poll_type     = in_array($poll_type_raw, ['vote','quiz']) ? $poll_type_raw : null;

    $duration = $_POST['poll_duration'] ?? 'forever';
    $expires_interval = null;
    if ($tag === 'Poll' && $poll_type) {
        $intervals = [
            '1h' => '1 HOUR', '6h' => '6 HOUR', '1d' => '1 DAY',
            '3d' => '3 DAY', '1w' => '1 WEEK', '1mo' => '1 MONTH', '1y' => '1 YEAR',
        ];
        if (isset($intervals[$duration])) $expires_interval = $intervals[$duration];
    }

    $allowed_ext = ['jpg','jpeg','png','gif','webp','mp4','webm','mov'];
    $media_items = [];

    // ── Existing media carried over from a group post via the Discuss button ──
    // These files already live in uploads/ — nothing is re-uploaded, we just
    // point a new pod_discussion_media row at the same filename/type. Never
    // trust the client blindly: strip any path, re-check the extension, and
    // confirm the file actually exists on disk before recording it.
    if (!empty($_POST['existing_media'])) {
        $existing_raw = json_decode($_POST['existing_media'], true);
        if (is_array($existing_raw)) {
            foreach ($existing_raw as $em) {
                if (!isset($em['file'])) continue;
                $ef = basename((string)$em['file']); // strip any directory traversal
                $eext = strtolower(pathinfo($ef, PATHINFO_EXTENSION));
                if (!in_array($eext, $allowed_ext, true)) continue;
                if (!is_file("uploads/" . $ef)) continue;
                $etype = in_array($em['type'] ?? '', ['image', 'video', 'gif'], true) ? $em['type'] : 'image';
                $media_items[] = ['file' => $ef, 'type' => $etype];
            }
        }
    }

    // ── Freshly uploaded files ──
    if (!empty($_FILES['media']['name'][0])) {
        $count = count($_FILES['media']['name']);
        for ($i = 0; $i < $count; $i++) {
            if ($_FILES['media']['error'][$i] !== UPLOAD_ERR_OK) continue;
            $orig_name = $_FILES['media']['name'][$i];
            $tmp_name  = $_FILES['media']['tmp_name'][$i];
            $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_ext, true)) continue;
            $fname = time() . '_' . $i . '_' . basename($orig_name);
            if (move_uploaded_file($tmp_name, "uploads/$fname")) {
                $mtype = in_array($ext, ['mp4','webm','mov']) ? 'video' : (in_array($ext, ['gif']) ? 'gif' : 'image');
                $media_items[] = ['file' => $fname, 'type' => $mtype];
            }
        }
    }

    if ($title) {
        // Carry the source Zeem/meme id over onto the new thread, if this
        // post was created via the "Discuss" button on a group post.
        // Re-validated against this pod's group_posts (see above), so it
        // can never point at a post from a different pod.
        $source_meme_id_val = $prefill_meme_id > 0 ? $prefill_meme_id : null;

        if ($expires_interval) {
            $stmt = $conn->prepare("
                INSERT INTO pod_discussions
                    (group_id, user_id, title, body, tag, poll_type, poll_expires_at, media_file, media_type, comments_disabled, source_meme_id)
                VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL " . $conn->real_escape_string($expires_interval) . "), '', 'none', ?, ?)
            ");
            $stmt->bind_param("iissssii", $group_id, $current_user, $title, $body, $tag, $poll_type, $comments_disabled, $source_meme_id_val);
        } else {
            $stmt = $conn->prepare("
                INSERT INTO pod_discussions
                    (group_id, user_id, title, body, tag, poll_type, poll_expires_at, media_file, media_type, comments_disabled, source_meme_id)
                VALUES (?, ?, ?, ?, ?, ?, NULL, '', 'none', ?, ?)
            ");
            $stmt->bind_param("iissssii", $group_id, $current_user, $title, $body, $tag, $poll_type, $comments_disabled, $source_meme_id_val);
        }
        $stmt->execute();
        $new_did = $conn->insert_id;
        $stmt->close();

        $ins = $conn->prepare("INSERT INTO pod_discussion_media (discussion_id, file, type, sort_order) VALUES (?, ?, ?, ?)");
        foreach ($media_items as $order => $mi) {
            $ins->bind_param("issi", $new_did, $mi['file'], $mi['type'], $order);
            $ins->execute();
        }
        $ins->close();

        if ($tag === 'Poll' && $poll_type && !empty($_POST['poll_options']) && is_array($_POST['poll_options'])) {
            $correct_idx = isset($_POST['correct_option']) ? (int)$_POST['correct_option'] : -1;
            $opt_stmt = $conn->prepare("INSERT INTO pod_poll_options (discussion_id, option_text, votes, is_correct) VALUES (?, ?, 0, ?)");
            foreach ($_POST['poll_options'] as $i => $popt) {
                $popt = trim($popt);
                if ($popt !== '') {
                    $is_correct = ($poll_type === 'quiz' && $i === $correct_idx) ? 1 : 0;
                    $opt_stmt->bind_param("isi", $new_did, $popt, $is_correct);
                    $opt_stmt->execute();
                }
            }
            $opt_stmt->close();
        }

        header("Location: discussions.php?pod_id=$group_id"); exit();
    }
}

// ── DELETE THREAD ──
if (isset($_POST['delete_thread'])) {
    $did = (int)$_POST['del_thread_id'];
    $stmt = $conn->prepare("SELECT user_id FROM pod_discussions WHERE id=? AND group_id=?");
    $stmt->bind_param("ii", $did, $group_id);
    $stmt->execute();
    $trow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($trow) {
        if ($is_mod || (int)$trow['user_id'] === $current_user) {
            foreach ([
                "DELETE FROM pod_discussion_replies WHERE discussion_id=?",
                "DELETE FROM pod_reactions WHERE discussion_id=?",
                "DELETE FROM pod_poll_votes WHERE discussion_id=?",
                "DELETE FROM pod_poll_options WHERE discussion_id=?",
            ] as $sql) {
                $s = $conn->prepare($sql);
                $s->bind_param("i", $did);
                $s->execute();
                $s->close();
            }
            $s = $conn->prepare("DELETE FROM pod_discussions WHERE id=? AND group_id=?");
            $s->bind_param("ii", $did, $group_id);
            $s->execute();
            $s->close();
        }
    }
    header("Location: discussions.php?pod_id=$group_id"); exit();
}

// ── DELETE REPLY ──
if (isset($_POST['delete_reply'])) {
    $rid      = (int)$_POST['del_reply_id'];
    $back_did = (int)($_POST['back_did'] ?? 0);

    $stmt = $conn->prepare("SELECT user_id FROM pod_discussion_replies WHERE id=?");
    $stmt->bind_param("i", $rid);
    $stmt->execute();
    $rrow_del = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($rrow_del) {
        $is_reply_owner = ((int)$rrow_del['user_id'] === $current_user);
        if ($is_reply_owner || $is_mod) {
            $s = $conn->prepare("DELETE FROM pod_discussion_replies WHERE id=?");
            $s->bind_param("i", $rid);
            $s->execute();
            $s->close();
            if ($back_did) {
                $s = $conn->prepare("UPDATE pod_discussions SET reply_count=GREATEST(reply_count-1,0) WHERE id=?");
                $s->bind_param("i", $back_did);
                $s->execute();
                $s->close();
            }
        }
    }
    header("Location: discussions.php?pod_id=$group_id" . ($back_did ? "&open=$back_did" : "")); exit();
}

// ── POST REPLY ──
if (isset($_POST['post_reply']) && $is_member) {
    $did = (int)$_POST['discussion_id'];
    $pid = (int)($_POST['parent_id'] ?? 0);
    $pid_val = $pid ?: null;
    $content = trim($_POST['content'] ?? '');
    $media = ''; $mtype = 'none';

    $stmt = $conn->prepare("SELECT comments_disabled FROM pod_discussions WHERE id=?");
    $stmt->bind_param("i", $did);
    $stmt->execute();
    $disc_check = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($disc_check && $disc_check['comments_disabled']) {
        header("Location: discussions.php?pod_id=$group_id&open=$did"); exit();
    }

    if (!empty($_FILES['reply_media']['name'])) {
        $allowed_ext = ['jpg','jpeg','png','gif','webp','mp4','webm','mov'];
        $ext = strtolower(pathinfo($_FILES['reply_media']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowed_ext, true)) {
            $fname = time() . '_' . basename($_FILES['reply_media']['name']);
            move_uploaded_file($_FILES['reply_media']['tmp_name'], "uploads/$fname");
            $mtype = in_array($ext, ['mp4','webm','mov']) ? 'video' : (in_array($ext, ['gif']) ? 'gif' : 'image');
            $media = $fname;
        }
    }

    if ($content || $media) {
        $stmt = $conn->prepare("INSERT INTO pod_discussion_replies (discussion_id, user_id, parent_id, content, media_file, media_type) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiisss", $did, $current_user, $pid_val, $content, $media, $mtype);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("UPDATE pod_discussions SET reply_count=reply_count+1, last_activity=NOW() WHERE id=?");
        $stmt->bind_param("i", $did);
        $stmt->execute();
        $stmt->close();

        header("Location: discussions.php?pod_id=$group_id&open=$did"); exit();
    }
}

// ── VOTE POLL ──
if (isset($_POST['vote_poll'])) {
    $did    = (int)$_POST['discussion_id'];
    $opt_id = (int)$_POST['option_id'];

    $stmt = $conn->prepare("SELECT 1 FROM pod_poll_votes WHERE discussion_id=? AND user_id=?");
    $stmt->bind_param("ii", $did, $current_user);
    $stmt->execute();
    $already = $stmt->get_result()->num_rows;
    $stmt->close();

    if (!$already) {
        $stmt = $conn->prepare("INSERT INTO pod_poll_votes (discussion_id, option_id, user_id) VALUES (?, ?, ?)");
        $stmt->bind_param("iii", $did, $opt_id, $current_user);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("UPDATE pod_poll_options SET votes=votes+1 WHERE id=?");
        $stmt->bind_param("i", $opt_id);
        $stmt->execute();
        $stmt->close();
    }
    header("Location: discussions.php?pod_id=$group_id&open=$did"); exit();
}

// ── MOD ACTION ──
if ($is_mod && isset($_POST['mod_action'])) {
    $did    = (int)$_POST['mod_did'];
    $action = $_POST['mod_action'];
    $field_map = [
        'pin'    => ['is_pinned', 1],
        'unpin'  => ['is_pinned', 0],
        'lock'   => ['is_locked', 1],
        'unlock' => ['is_locked', 0],
    ];
    if (isset($field_map[$action])) {
        [$field, $val] = $field_map[$action];
        $stmt = $conn->prepare("UPDATE pod_discussions SET $field=? WHERE id=? AND group_id=?");
        $stmt->bind_param("iii", $val, $did, $group_id);
        $stmt->execute();
        $stmt->close();
    }
    header("Location: discussions.php?pod_id=$group_id"); exit();
}

// ── AJAX LIKE ──
if (isset($_POST['ajax_like'])) {
    header('Content-Type: application/json');
    $did  = (int)$_POST['discussion_id'];
    $type = $_POST['type'] === 'dislike' ? 'dislike' : 'like';

    $stmt = $conn->prepare("SELECT id, type FROM pod_likes WHERE discussion_id=? AND user_id=?");
    $stmt->bind_param("ii", $did, $current_user);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        if ($existing['type'] === $type) {
            $s = $conn->prepare("DELETE FROM pod_likes WHERE id=?");
            $s->bind_param("i", $existing['id']);
            $s->execute(); $s->close();
            $action = 'removed';
        } else {
            $s = $conn->prepare("UPDATE pod_likes SET type=? WHERE id=?");
            $s->bind_param("si", $type, $existing['id']);
            $s->execute(); $s->close();
            $action = 'switched';
        }
    } else {
        $s = $conn->prepare("INSERT INTO pod_likes (discussion_id, user_id, type) VALUES (?, ?, ?)");
        $s->bind_param("iis", $did, $current_user, $type);
        $s->execute(); $s->close();
        $action = 'added';
    }

    $stmt = $conn->prepare("SELECT COUNT(*) as c FROM pod_likes WHERE discussion_id=? AND type='like'");
    $stmt->bind_param("i", $did);
    $stmt->execute();
    $likes = $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    $stmt = $conn->prepare("SELECT type FROM pod_likes WHERE discussion_id=? AND user_id=?");
    $stmt->bind_param("ii", $did, $current_user);
    $stmt->execute();
    $user_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    echo json_encode(['action'=>$action,'likes'=>(int)$likes,'user_type'=>$user_row?$user_row['type']:null]);
    exit();
}

// ── AJAX REACT ──
if (isset($_POST['ajax_react'])) {
    header('Content-Type: application/json');
    $did   = (int)$_POST['discussion_id'];
    $emoji = $_POST['emoji'] ?? '';

    $stmt = $conn->prepare("SELECT id FROM pod_reactions WHERE discussion_id=? AND user_id=? AND emoji=?");
    $stmt->bind_param("iis", $did, $current_user, $emoji);
    $stmt->execute();
    $check = $stmt->get_result();
    $has_reaction = $check->num_rows > 0;
    $stmt->close();

    if ($has_reaction) {
        $s = $conn->prepare("DELETE FROM pod_reactions WHERE discussion_id=? AND user_id=? AND emoji=?");
        $s->bind_param("iis", $did, $current_user, $emoji);
        $s->execute(); $s->close();
        $action = 'removed';
    } else {
        $s = $conn->prepare("INSERT INTO pod_reactions (discussion_id, user_id, emoji) VALUES (?, ?, ?)");
        $s->bind_param("iis", $did, $current_user, $emoji);
        $s->execute(); $s->close();
        $action = 'added';
    }

    $counts = [];
    $stmt = $conn->prepare("SELECT emoji, COUNT(*) as c FROM pod_reactions WHERE discussion_id=? GROUP BY emoji");
    $stmt->bind_param("i", $did);
    $stmt->execute();
    $cr = $stmt->get_result();
    while ($crow = $cr->fetch_assoc()) $counts[$crow['emoji']] = $crow['c'];
    $stmt->close();

    echo json_encode(['action'=>$action,'counts'=>$counts]);
    exit();

    // ── REPORT THREAD TO POD ADMINS ──
if (isset($_POST['report_thread_to_admins'])) {
    header('Content-Type: application/json');

    if (!$is_member) {
        echo json_encode(['success' => false, 'msg' => 'You must be a member of this pod to report a post.']);
        exit();
    }

    $rpt_did    = (int)($_POST['discussion_id'] ?? 0);
    $rpt_reason = trim((string)($_POST['reason'] ?? ''));
    $rpt_desc   = trim((string)($_POST['description'] ?? ''));

    if (!$rpt_did || !$rpt_reason) {
        echo json_encode(['success' => false, 'msg' => 'Missing report details.']);
        exit();
    }

    $stmt = $conn->prepare("SELECT user_id FROM pod_discussions WHERE id=? AND group_id=?");
    $stmt->bind_param("ii", $rpt_did, $group_id);
    $stmt->execute();
    $trow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$trow) {
        echo json_encode(['success' => false, 'msg' => 'This post no longer exists in this pod.']);
        exit();
    }

    $rpt_target_id = (int)$trow['user_id'];

    $stmt = $conn->prepare("
        SELECT 1 FROM pod_discussion_reports
        WHERE discussion_id=? AND reported_by=? AND status='pending'
    ");
    $stmt->bind_param("ii", $rpt_did, $current_user);
    $stmt->execute();
    $already = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    if ($already) {
        echo json_encode(['success' => true, 'msg' => 'already_reported']);
        exit();
    }

    $stmt = $conn->prepare("
        INSERT INTO pod_discussion_reports
            (group_id, discussion_id, reported_by, reported_user_id, reason, description)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("iiiiss", $group_id, $rpt_did, $current_user, $rpt_target_id, $rpt_reason, $rpt_desc);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => (bool)$ok]);
    exit();
}
}

// ── SORT / SEARCH / THREAD LIST ──
$sort   = $_GET['sort'] ?? 'newest';
$search = $_GET['q'] ?? '';
$open   = (int)($_GET['open'] ?? 0);

switch ($sort) {
    case 'replies': $order = 'd.reply_count DESC'; break;
    case 'views':   $order = 'd.view_count DESC'; break;
    case 'active':  $order = 'd.last_activity DESC'; break;
    default:        $order = 'd.created_at DESC'; break;
}

$threads = [];
if ($search !== '') {
    $like = "%$search%";
    $sql = "
        SELECT d.*, u.username, u.profile_pic,
               (SELECT COUNT(*) FROM pod_discussion_replies r WHERE r.discussion_id=d.id) AS actual_replies,
               (SELECT COUNT(*) FROM pod_likes l WHERE l.discussion_id=d.id AND l.type='like') AS like_count,
               (SELECT type FROM pod_likes WHERE discussion_id=d.id AND user_id=? LIMIT 1) AS user_like_type
        FROM pod_discussions d
        JOIN users u ON u.id=d.user_id
        WHERE d.group_id=? AND (d.title LIKE ? OR d.body LIKE ?)
        ORDER BY d.is_pinned DESC, $order
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiss", $current_user, $group_id, $like, $like);
} else {
    $sql = "
        SELECT d.*, u.username, u.profile_pic,
               (SELECT COUNT(*) FROM pod_discussion_replies r WHERE r.discussion_id=d.id) AS actual_replies,
               (SELECT COUNT(*) FROM pod_likes l WHERE l.discussion_id=d.id AND l.type='like') AS like_count,
               (SELECT type FROM pod_likes WHERE discussion_id=d.id AND user_id=? LIMIT 1) AS user_like_type
        FROM pod_discussions d
        JOIN users u ON u.id=d.user_id
        WHERE d.group_id=?
        ORDER BY d.is_pinned DESC, $order
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $current_user, $group_id);
}
$stmt->execute();
$threads_q = $stmt->get_result();
while ($trow = $threads_q->fetch_assoc()) $threads[] = $trow;
$stmt->close();

// ── Follow status for thread authors (for Track/Untrack in the thread's ⋯ menu) ──
$_disc_follow_map = [];
if ($current_user && !empty($threads)) {
    $d_uids = array_unique(array_column($threads, 'user_id'));
    if (!empty($d_uids)) {
        $d_uid_str = implode(',', array_map('intval', $d_uids));
        $fres = $conn->query("SELECT following_id FROM followers WHERE follower_id=$current_user AND following_id IN ($d_uid_str)");
        if ($fres) {
            while ($r = $fres->fetch_assoc()) $_disc_follow_map[(int)$r['following_id']] = true;
        }
    }
}

if ($open) {
    $stmt = $conn->prepare("UPDATE pod_discussions SET view_count=view_count+1 WHERE id=? AND group_id=?");
    $stmt->bind_param("ii", $open, $group_id);
    $stmt->execute();
    $stmt->close();
}

$poll_data = [];
$opt_stmt = $conn->prepare("SELECT * FROM pod_poll_options WHERE discussion_id=?");
foreach ($threads as $titem) {
    if ($titem['tag'] === 'Poll') {
        $tid = (int)$titem['id'];
        $opt_stmt->bind_param("i", $tid);
        $opt_stmt->execute();
        $opts = $opt_stmt->get_result();
        $poll_data[$titem['id']] = [];
        while ($o = $opts->fetch_assoc()) $poll_data[$titem['id']][] = $o;
    }
}
$opt_stmt->close();

// ── Media galleries — dynamic IN() built safely with placeholders ──
$media_data = [];
if (!empty($threads)) {
    $ids = array_map(fn($t) => (int)$t['id'], $threads);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt = $conn->prepare("SELECT * FROM pod_discussion_media WHERE discussion_id IN ($placeholders) ORDER BY discussion_id, sort_order ASC, id ASC");
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $media_q = $stmt->get_result();
    while ($mrow = $media_q->fetch_assoc()) {
        $media_data[$mrow['discussion_id']][] = $mrow;
    }
    $stmt->close();
}

$stmt = $conn->prepare("SELECT username, profile_pic FROM users WHERE id=?");
$stmt->bind_param("i", $current_user);
$stmt->execute();
$me_row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$me_pic  = !empty($me_row['profile_pic']) ? $me_row['profile_pic'] : 'uploads/me.jpeg';
$me_name = $me_row['username'] ?? '';

$my_threads = [];
$stmt = $conn->prepare("
    SELECT d.id, d.title, d.body, d.tag, d.created_at, d.view_count, d.is_locked, d.is_pinned,
           (SELECT COUNT(*) FROM pod_discussion_replies r WHERE r.discussion_id=d.id) AS reply_count,
           (SELECT COUNT(*) FROM pod_likes l WHERE l.discussion_id=d.id AND l.type='like') AS like_count
    FROM pod_discussions d
    WHERE d.group_id=? AND d.user_id=?
    ORDER BY d.created_at DESC
");
$stmt->bind_param("ii", $group_id, $current_user);
$stmt->execute();
$mth = $stmt->get_result();
while ($mt = $mth->fetch_assoc()) $my_threads[] = $mt;
$stmt->close();

// ── renderReplyItem — unchanged (already uses htmlspecialchars for output escaping) ──
function renderReplyItem($r, $depth, $children, $viewer_id, $viewer_is_mod, $gid) {
    $rp     = !empty($r['profile_pic']) ? htmlspecialchars($r['profile_pic']) : 'uploads/me.jpeg';
    $rmedia = !empty($r['media_file'])  ? 'uploads/' . htmlspecialchars($r['media_file']) : '';
    $can_del_r = ((int)$r['user_id'] === (int)$viewer_id || $viewer_is_mod);

    echo '<div class="reply-item" id="reply-'.(int)$r['id'].'">';
    echo '<a href="profile.php?user_id='.(int)$r['user_id'].'" class="r-av">';
    echo '<img src="'.$rp.'" onerror="this.src=\'uploads/me.jpeg\'">';
    echo '</a>';
    echo '<div class="r-body">';
    echo '<div class="r-hdr">';
    echo '<a href="profile.php?user_id='.(int)$r['user_id'].'" class="r-uname">@'.htmlspecialchars($r['username']).'</a>';
    echo '<span class="r-time">'.date('M j · H:i', strtotime($r['created_at'])).'</span>';
    echo '</div>';
    echo '<div class="r-text">'.nl2br(htmlspecialchars($r['content'])).'</div>';
    if ($rmedia) {
        if (in_array($r['media_type'], ['image','gif']))
            echo '<img src="'.$rmedia.'" class="r-media-img">';
        elseif ($r['media_type'] === 'video')
            echo '<video src="'.$rmedia.'" class="r-media-img" controls muted style="max-width:200px;border-radius:10px;margin-top:6px;"></video>';
    }
    echo '<div class="r-actions">';
    if ($depth < 3) {
        $safe_u = addslashes(htmlspecialchars($r['username']));
        echo '<button class="r-act-btn" onclick="showNestedReply('.(int)$r['discussion_id'].','.(int)$r['id'].',\''.$safe_u.'\')">';
        echo '<i class="ri-reply-line"></i> Reply</button>';
    }
    if ($can_del_r) {
        echo '<form method="POST" style="display:inline;" onsubmit="return confirm(\'Delete this reply?\')">';
        echo '<input type="hidden" name="del_reply_id" value="'.(int)$r['id'].'">';
        echo '<input type="hidden" name="back_did" value="'.(int)$r['discussion_id'].'">';
        echo '<button type="submit" name="delete_reply" class="r-act-btn r-del-btn"><i class="ri-delete-bin-line"></i></button>';
        echo '</form>';
    }
    echo '</div></div></div>';
    if (!empty($children[$r['id']]) && $depth < 3) {
        echo '<div class="nested-replies">';
        foreach ($children[$r['id']] as $child)
            renderReplyItem($child, $depth+1, $children, $viewer_id, $viewer_is_mod, $gid);
        echo '</div>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($group['title']) ?> — Discussions</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<script>
const CSRF_TOKEN = <?= json_encode($csrf_token) ?>;

/* ── Media transferred over from a group post via the "Discuss" button.
     Populated server-side from $prefill_media above — used by the create
     modal's JS to pre-render attachment thumbnails and auto-open the modal
     on page load. Caption/audio/partnership data is intentionally not
     included here; only the media transfers. ── */
var DS_PREFILL_MEDIA = <?= json_encode($prefill_media) ?>;
var DS_HAS_PREFILL   = <?= $has_prefill ? 'true' : 'false' ?>;

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form[method="POST"], form[method="post"]').forEach(function (f) {
        if (!f.querySelector('input[name="csrf_token"]')) {
            var inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = 'csrf_token';
            inp.value = CSRF_TOKEN;
            f.appendChild(inp);
        }
    });
});
</script>

<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg:       #020216;
    --surface:  rgba(255,255,255,0.028);
    --surface-2:#0a0c38;
    --card:     rgba(255,255,255,0.028);
    --red:      #ff3b3b;
    --red-soft: rgba(255,59,59,0.10);
    --red-mid:  rgba(255,59,59,0.22);
    --border:   rgba(255,255,255,0.06);
    --border-2: rgba(255,255,255,0.12);
    --text:     #f5f5f7;
    --text-2:   rgba(245,245,247,0.58);
    --text-3:   rgba(245,245,247,0.34);
    --green:    #3ddc84;
    --gold:     #f4c542;
    --blue:     #4aa8ff;
    --violet:   #9d8cff;
    --font-h:   'Poppins', sans-serif;
    --font:     'Poppins', sans-serif;
    --r:        14px;
    --r-sm:     9px;
    --r-lg:     20px;
}

html { scroll-behavior: smooth; }

body {
    background: linear-gradient(-45deg,#010218,#070924,#070924);
    background-attachment: fixed;
    color: var(--text);
    font-family: var(--font);
    min-height: 100vh;
    overflow-x: hidden;
    position: relative;
}

body::before {
    content: '';
    position: fixed; inset: 0;
    background-image:
        linear-gradient(rgba(255,45,45,0.015) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,45,45,0.015) 1px, transparent 1px);
    background-size: 60px 60px;
    pointer-events: none; z-index: 0;
}

.page-shell { position: relative; z-index: 1; }

/* ── PAGE LAYOUT ── */
.page-shell {
    display: flex;
    gap: 28px;
    max-width: 1280px;
    margin: 0 auto;
    padding: 36px 24px 100px;
    align-items: flex-start;
}

/* LEFT SIDEBAR */
.sidebar-left {
    width: 236px;
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    gap: 14px;
    position: sticky;
    top: 28px;
}

/* RIGHT SIDEBAR */
.sidebar-right {
    width: 236px;
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    gap: 14px;
    position: sticky;
    top: 28px;
}

/* CENTRE */
.main {
    flex: 1;
    min-width: 0;
}

/* SIDEBAR WIDGET */
.sb-widget {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--r);
}
.sb-widget-hdr {
    padding: 13px 16px;
    border-bottom: 1px solid var(--border);
    font-family: var(--font-h);
    font-size: 11px;
    font-weight: 700;
    color: var(--text-2);
    text-transform: uppercase;
    letter-spacing: 1.4px;
    display: flex;
    align-items: center;
    gap: 7px;
}
.sb-widget-hdr i { color: var(--red); font-size: 13px; }
.sb-widget-body { padding: 12px 16px; }

/* sidebar stat row */
.sb-stat {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 7px 0;
    border-bottom: 1px solid var(--border);
    font-size: 12px;
}
.sb-stat:last-child { border-bottom: none; }
.sb-stat-lbl { color: var(--text-3); display: flex; align-items: center; gap: 6px; }
.sb-stat-lbl i { font-size: 13px; }
.sb-stat-val { font-weight: 700; color: var(--text); font-size: 13px; font-family: var(--font-h); }

/* sidebar active member list */
.sb-member {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 7px 0;
    border-bottom: 1px solid var(--border);
    text-decoration: none;
    transition: 0.15s;
}
.sb-member:last-child { border-bottom: none; }
.sb-member:hover { opacity: 0.7; }
.sb-member img {
    width: 26px; height: 26px;
    border-radius: 50%;
    object-fit: cover;
    flex-shrink: 0;
}
.sb-member-name {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-2);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.sb-online-dot {
    width: 6px; height: 6px;
    border-radius: 50%;
    background: var(--green);
    flex-shrink: 0;
    margin-left: auto;
}

/* sidebar tag list */
.sb-tag-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 7px 0;
    border-bottom: 1px solid var(--border);
    font-size: 11px;
    cursor: pointer;
    transition: 0.15s;
}
.sb-tag-row:last-child { border-bottom: none; }
.sb-tag-row:hover { opacity: 0.7; }
.sb-tag-pill {
    padding: 3px 9px;
    border-radius: 5px;
    font-size: 10px;
    font-weight: 700;
    font-family: var(--font-h);
    text-transform: uppercase;
    letter-spacing: 0.4px;
}
.sb-tag-count { color: var(--text-3); font-size: 11px; font-weight: 600; }

/* sidebar recent thread */
.sb-thread {
    padding: 8px 0;
    border-bottom: 1px solid var(--border);
    cursor: pointer;
    transition: 0.15s;
}
.sb-thread:last-child { border-bottom: none; }
.sb-thread:hover { opacity: 0.7; }
.sb-thread-title {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--text);
    line-height: 1.4;
    margin-bottom: 4px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.sb-thread-meta { font-size: 10px; color: var(--text-3); display: flex; gap: 7px; align-items: center; }

/* back button — no circle, no border, just a chevron */
.back-btn {
    width: 30px; height: 30px;
    border: none;
    background: transparent;
    color: var(--text);
    font-size: 22px;
    line-height: 1;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; text-decoration: none;
    transition: 0.2s; flex-shrink: 0;
    margin-left: -6px;
}
.back-btn:hover { color: var(--red); transform: translateX(-3px); }

/* page top */
.page-top {
    display: flex; align-items: center; gap: 10px;
    margin-bottom: 26px;
}
.page-title-wrap { flex: 1; min-width: 0; }
.page-title {
    font-family: var(--font-h);
    font-size: clamp(20px, 3.4vw, 30px);
    font-weight: 800; color: #fff;
    letter-spacing: -0.6px; line-height: 1.1;
}
.page-title span { color: var(--red); }
.page-sub {
    font-size: 10.5px; color: var(--text-3);
    text-transform: uppercase; letter-spacing: 2.2px;
    margin-top: 5px; font-family: var(--font-h); font-weight: 700;
}

/* stats strip */
.stats-strip {
    display: flex; align-items: center; gap: 10px;
    flex-wrap: nowrap;
    overflow-x: auto;
    scrollbar-width: none;
    margin-bottom: 18px;
    padding: 12px 16px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--r);
}
.stats-strip::-webkit-scrollbar { display: none; }
.s-chip { flex-shrink: 0; display: flex; align-items: center; gap: 6px; font-size: 11.5px; font-weight: 500; color: var(--text-2); }
.s-chip i { font-size: 13px; }
.s-chip strong { color: #fff; font-weight: 700; font-family: var(--font-h); }
.s-div { flex-shrink: 0; width: 1px; height: 14px; background: var(--border-2); }
.active-row {
    display: flex; align-items: center; gap: 6px;
    flex-wrap: nowrap;
    flex-shrink: 1;
    min-width: 0;
    overflow-x: auto;
    scrollbar-width: none;
}
.active-row::-webkit-scrollbar { display: none; }
.active-pill {
    flex-shrink: 0;
    display: flex; align-items: center; gap: 6px;
    font-size: 11px; font-weight: 700; color: var(--green);
    background: rgba(61,220,132,0.08);
    border: 1px solid rgba(61,220,132,0.22);
    border-radius: 20px; padding: 4px 10px;
    white-space: nowrap;
}
.a-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--green); flex-shrink: 0; }
.active-av {
    flex-shrink: 0;
    width: 22px; height: 22px; border-radius: 50%;
    object-fit: cover; cursor: pointer; transition: 0.2s;
}
.active-av:hover { transform: scale(1.15); }
.inactive-pill {
    flex-shrink: 0;
    font-size: 11px; color: var(--text-3);
    background: rgba(255,255,255,0.02);
    border: 1px dashed var(--border-2);
    border-radius: 20px; padding: 4px 11px;
    white-space: nowrap;
}

/* toolbar */
.toolbar {
    display: flex; gap: 9px; align-items: center;
    flex-wrap: wrap; margin-bottom: 22px;
}
.new-btn {
    display: flex; align-items: center; gap: 7px;
    padding: 10px 20px; border-radius: 40px;
    background: var(--red); border: none; color: #fff;
    font-family: var(--font-h); font-size: 12.5px; font-weight: 700;
    cursor: pointer; transition: 0.2s; white-space: nowrap;
}
.new-btn:hover { background: #ff5252; transform: translateY(-1px); }
.new-btn:active { transform: translateY(0); }
.search-box {
    flex: 1; min-width: 140px;
    display: flex; align-items: center; gap: 9px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 40px; padding: 9px 16px;
    transition: 0.2s;
}
.search-box:focus-within { border-color: var(--red-mid); }
.search-box i { color: var(--text-3); font-size: 14px; }
.search-box input {
    flex: 1; border: none; background: transparent;
    color: var(--text); font-family: var(--font); font-size: 12.5px; outline: none;
}
.search-box input::placeholder { color: var(--text-3); }
.sort-sel {
    padding: 9px 15px; border-radius: 40px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    color: var(--text); font-family: var(--font); font-size: 11.5px;
    outline: none; cursor: pointer;
    color-scheme: dark;
}
.sort-sel option {
    background: #0a0c38;
    color: var(--text);
}
.hist-wrap { position: relative; }
.hist-btn {
    display: flex; align-items: center; gap: 7px;
    padding: 9px 15px; border-radius: 40px;
    background: var(--surface);
    border: 1px solid var(--border);
    color: var(--text-2); font-size: 11.5px; font-weight: 600;
    font-family: var(--font); cursor: pointer; transition: 0.2s;
    white-space: nowrap;
}
.hist-btn:hover { border-color: var(--border-2); color: #fff; }
.hist-badge { background: var(--red); color: #fff; font-size: 9px; font-weight: 800; padding: 2px 6px; border-radius: 20px; }
.hist-dd {
    display: none; position: absolute;
    top: calc(100% + 10px); right: 0;
    width: clamp(240px, 65vw, 330px);
    background: var(--surface-2);
    border: 1px solid var(--border-2);
    border-radius: var(--r); z-index: 9999;
    box-shadow: 0 20px 60px rgba(0,0,0,0.55);
    overflow: hidden;
}
.hist-dd.open { display: block; }
.hist-dd-hdr { padding: 13px 16px; border-bottom: 1px solid var(--border); font-family: var(--font-h); font-size: 11px; font-weight: 700; color: #fff; display: flex; align-items: center; gap: 7px; }
.hist-item { display: flex; align-items: center; gap: 9px; padding: 10px 16px; border-bottom: 1px solid var(--border); transition: 0.15s; cursor: pointer; }
.hist-item:last-child { border-bottom: none; }
.hist-item:hover { background: rgba(255,255,255,0.025); }
.hist-item-title { flex: 1; min-width: 0; font-size: 11.5px; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.hist-item-date  { font-size: 10px; color: var(--text-3); white-space: nowrap; }
.hist-del { background: none; border: none; color: var(--text-3); font-size: 13px; cursor: pointer; padding: 2px 4px; border-radius: 4px; transition: 0.15s; }
.hist-del:hover { color: var(--red); background: var(--red-soft); }
.hist-empty { padding: 20px; font-size: 11.5px; color: var(--text-3); text-align: center; }

/* skeleton */
.skel-wrap { display: flex; flex-direction: column; gap: 10px; }
.skel-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--r); padding: 16px 18px; display: flex; gap: 13px; }
.skel { background: linear-gradient(90deg,rgba(255,255,255,0.035) 25%,rgba(255,255,255,0.07) 50%,rgba(255,255,255,0.035) 75%); background-size: 200% 100%; animation: shimmer 1.5s infinite; border-radius: 6px; }
@keyframes shimmer { 0%{background-position:200%} 100%{background-position:-200%} }
.skel-av { width: 40px; height: 40px; border-radius: 50%; flex-shrink: 0; }
.skel-lines { flex: 1; display: flex; flex-direction: column; gap: 9px; }
.skel-s { height: 9px; width: 28%; }
.skel-t { height: 13px; width: 65%; }
.skel-b { height: 9px; width: 88%; }
.skel-m { height: 9px; width: 45%; }

/* threads */
.threads { display: flex; flex-direction: column; gap: 10px; }

/* thread card */
.tc {
    border-radius:none;
    border: 1px solid var(--border);
    background:none;
    overflow: hidden;
    transition: border-color 0.2s;
}
.tc:hover { border-color: var(--border-2); }
.tc.pinned { border-color: rgba(244,197,66,0.28); }
.tc.is-open { border-color: var(--red-mid); }

/* thread card header */
.tc-hdr-row { display: flex; align-items: center; gap: 11px; padding: 15px 18px 0; }
.tc-av-link { flex-shrink: 0; display: block; }
.tc-av { width: 38px; height: 38px; border-radius: 50%; object-fit: cover; display: block; border: 2px solid var(--red); }
.tc-hdr-info { flex: 1; min-width: 0; }
.tc-uname { font-size: 13px; font-weight: 700; color: #fff; text-decoration: none; }
.tc-uname:hover { color: var(--red); }
.tc-hdr-meta { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-top: 3px; }
.tc-time { font-size: 10.5px; color: var(--text-3); }

.tag { font-size: 10px; font-weight: 700; padding: 3px 8px; border-radius: 5px; text-transform: uppercase; letter-spacing: 0.5px; font-family: var(--font-h); }
.t-Discussion   { background:rgba(255,59,59,0.11);  color:#ff7a7a; }
.t-Question     { background:rgba(74,168,255,0.11); color:#6fbcff; }
.t-Announcement { background:rgba(244,197,66,0.11); color:#f4c542; }
.t-Suggestion   { background:rgba(157,140,255,0.13);color:#b0a3ff; }
.t-Poll         { background:rgba(61,220,132,0.11); color:#3ddc84; }
.t-Help         { background:rgba(255,165,64,0.12); color:#ffab40; }
.t-MemeTalk     { background:rgba(255,59,59,0.09);  color:#ff9c9c; }
.t-News         { background:rgba(74,168,255,0.11); color:#4ab6ff; }
.pin-ico   { font-size: 12px; color: var(--gold); }
.lock-ico  { font-size: 12px; color: var(--text-3); }
.nocomm-ico{ font-size: 12px; color: var(--text-3); }

/* card content: title, body, media, poll */
.tc-content { padding: 10px 18px 4px; }
.tc-title { font-family: var(--font-h); font-size: 15px; font-weight: 700; color: #fff; margin-bottom: 6px; }
.tc-full-body { margin-bottom: 6px; }
.tc-body-text { font-size: 13px; color: var(--text-2); line-height: 1.55; white-space: pre-wrap; word-break: break-word; }
.tc-body-text.clamped { display: -webkit-box; -webkit-line-clamp: 4; -webkit-box-orient: vertical; overflow: hidden; }
.tc-readmore-btn { background: none; border: none; color: var(--text-3); font-size: 12px; font-weight: 700; cursor: pointer; margin-top: 4px; padding: 0; font-family: var(--font-h); }
.tc-readmore-btn:hover { color: #fff; }

.tc-media { width: 100%; max-height: 420px; overflow: hidden; border-radius: var(--r-sm); margin: 8px 0 4px; background: #000; }
.tc-media img, .tc-media video { width: 100%; max-height: 420px; object-fit: contain; display: block; background: #000; }

.tc-poll-wrap { margin: 10px 0 6px; padding: 12px 14px; border-radius: var(--r-sm); background: rgba(255,255,255,0.02); border: 1px solid var(--border); }

/* stats / actions row */
.like-row {
    display: flex; align-items: center; gap: 7px;
    padding: 10px 18px;
    border-top: 1px solid var(--border);
    flex-wrap: wrap;
}
.like-btn, .dislike-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 13px; border-radius: 20px;
    border: 1px solid var(--border);
    background: transparent;
    font-size: 11.5px; font-weight: 600; font-family: var(--font);
    cursor: pointer; transition: 0.15s; color: var(--text-2);
}
.like-btn:hover    { border-color:var(--red-mid); color:var(--red); background:var(--red-soft); }
.dislike-btn:hover { border-color:var(--border-2); color:#fff; }
.like-btn.active   { background:var(--red-soft); border-color:var(--red-mid); color:var(--red); }
.dislike-btn.active{ background:rgba(255,255,255,0.05); border-color:var(--border-2); color:#fff; }
.share-btn {
    display: inline-flex; align-items: center; justify-content: center;
    width: 30px; height: 30px; border-radius: 50%;
    border: 1px solid var(--border); background: transparent;
    color: var(--text-2); cursor: pointer; transition: 0.15s; font-size: 13px;
}
.share-btn:hover { border-color: var(--border-2); color: #fff; }
.lr-stat { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; color: var(--text-3); margin-left: 2px; }
.comm-toggle-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 13px; border-radius: 20px;
    border: 1px solid var(--border);
    background: transparent;
    font-size: 11.5px; font-weight: 600; font-family: var(--font);
    cursor: pointer; transition: 0.15s; color: var(--text-2);
    margin-left: 4px;
}
.comm-toggle-btn:hover  { border-color:var(--red-mid); color:var(--red); background:var(--red-soft); }
.comm-toggle-btn.active { border-color:var(--red-mid); color:var(--red); background:var(--red-soft); }
.comm-count-badge {
    background: var(--red); color: #fff; font-size: 9px; font-weight: 800;
    min-width: 16px; height: 16px; border-radius: 10px; padding: 0 4px;
    display: inline-flex; align-items: center; justify-content: center;
}

/* mod strip */
.mod-strip {
    display: flex; gap: 6px; padding: 8px 18px;
    border-top: 1px solid var(--border);
    background: rgba(0,0,0,0.15); flex-wrap: wrap;
}
.mod-btn { padding: 4px 11px; border-radius: 7px; font-size: 10px; font-weight: 700; font-family: var(--font-h); border: 1px solid; cursor: pointer; background: transparent; transition: 0.15s; }
.mb-pin  { border-color:rgba(244,197,66,0.3); color:var(--gold); }
.mb-pin:hover  { background:rgba(244,197,66,0.08); }
.mb-lock { border-color:var(--border-2); color:var(--text-3); }
.mb-lock:hover { background:rgba(255,255,255,0.04); }
.mb-del  { border-color:var(--red-mid); color:var(--red); }
.mb-del:hover  { background:var(--red-soft); }


/* create modal */
#createModal { display:none; position:fixed; inset:0; z-index:9999999; background:rgba(0,0,0,0.75); backdrop-filter:blur(6px); align-items:center; justify-content:center; padding:16px; }
#createModal.open { display:flex; }
.cm-inner { background:var(--surface-2); border:1px solid var(--border-2); border-radius:var(--r-lg); width:100%; max-width:520px; max-height:92vh; overflow-y:auto; animation:cm-in 0.2s ease; box-shadow:0 30px 80px rgba(0,0,0,0.6); }
@keyframes cm-in { from{opacity:0;transform:scale(0.96) translateY(6px)} to{opacity:1;transform:none} }
.cm-inner::-webkit-scrollbar { width:3px; }
.cm-inner::-webkit-scrollbar-thumb { background:var(--surface); border-radius:99px; }
.cm-hdr { display:flex; align-items:center; justify-content:space-between; padding:18px 20px 15px; border-bottom:1px solid var(--border); }
.cm-hdr-title { font-family:var(--font-h); font-size:16px; font-weight:800; }
.cm-close { background:none; border:none; color:var(--text-3); font-size:20px; cursor:pointer; transition:0.2s; padding:4px; }
.cm-close:hover { color:#fff; }
.cm-body { padding:18px 20px; display:flex; flex-direction:column; gap:16px; }
.cm-lbl { font-size:10.5px; font-weight:700; color:var(--text-3); text-transform:uppercase; letter-spacing:1.2px; margin-bottom:6px; font-family:var(--font-h); }
.cm-input, .cm-textarea { width:100%; padding:10px 14px; border-radius:var(--r-sm); border:1px solid var(--border); background:rgba(255,255,255,0.035); color:var(--text); font-family:var(--font); font-size:12.5px; outline:none; transition:0.2s; }
.cm-input:focus, .cm-textarea:focus { border-color:var(--red-mid); }
.cm-input::placeholder, .cm-textarea::placeholder { color:var(--text-3); }
.cm-textarea { resize:vertical; min-height:80px; }
.tag-grid { display:flex; flex-wrap:wrap; gap:6px; }
.tag-pill { padding:5px 13px; border-radius:20px; border:1px solid var(--border); font-size:11px; font-weight:700; font-family:var(--font-h); color:var(--text-2); cursor:pointer; transition:0.15s; background:transparent; }
.tag-pill.sel { background:var(--red-soft); border-color:var(--red-mid); color:var(--red); }
.cm-media-btn { display:flex; align-items:center; gap:8px; padding:9px 14px; border-radius:var(--r-sm); border:1px dashed var(--border-2); background:rgba(255,255,255,0.02); color:var(--text-2); font-size:11.5px; cursor:pointer; transition:0.2s; }
.cm-media-btn:hover { border-color:var(--border-2); color:#fff; }
.cm-media-btn input { display:none; }
.poll-opts-wrap { display:flex; flex-direction:column; gap:6px; }
.poll-opt-inp { padding:8px 12px; border-radius:var(--r-sm); border:1px solid var(--border); background:rgba(255,255,255,0.035); color:var(--text); font-family:var(--font); font-size:11.5px; outline:none; width:100%; }
.poll-opt-inp::placeholder { color:var(--text-3); }
.add-opt-btn { font-size:11.5px; color:var(--text-3); background:none; border:none; cursor:pointer; font-family:var(--font); text-align:left; padding:3px 0; transition:0.15s; }
.add-opt-btn:hover { color:var(--red); }
.cm-toggle { display:flex; align-items:center; gap:10px; padding:10px 14px; background:rgba(255,255,255,0.02); border:1px solid var(--border); border-radius:var(--r-sm); }
.cm-toggle label { font-size:11.5px; color:var(--text-2); cursor:pointer; flex:1; }
.cm-toggle input[type=checkbox] { accent-color:var(--red); width:15px; height:15px; cursor:pointer; }
.cm-ftr { padding:15px 20px; border-top:1px solid var(--border); display:flex; justify-content:flex-end; gap:9px; }
.cm-cancel { padding:9px 20px; border-radius:40px; background:transparent; border:1px solid var(--border); color:var(--text-2); font-family:var(--font); font-size:11.5px; cursor:pointer; }
.cm-cancel:hover { color:#fff; border-color: var(--border-2); }
.cm-submit { padding:9px 24px; border-radius:40px; background:var(--red); border:none; color:#fff; font-family:var(--font-h); font-size:12.5px; font-weight:700; cursor:pointer; transition:0.2s; }
.cm-submit:hover { background:#ff5252; }

/* empty state */
.empty-state { text-align:center; padding:60px 20px; color:var(--text-3); }
.empty-state i { font-size:46px; display:block; margin-bottom:12px; opacity:0.25; }
.empty-state p { font-size:12.5px; }

/* ── RESPONSIVE ── */
@media (max-width: 1024px) {
    .sidebar-right { display: none; }
    .page-shell { max-width: 900px; }
}

@media (max-width: 790px) {
    .sidebar-left { display: none; }
    .page-shell { padding: 16px 14px 90px; gap: 0; }
    body { padding-bottom: 70px; }
    .page-title { font-size: 18px; }
    .page-sub { font-size: 9.5px; letter-spacing: 1.8px; }
    .back-btn { width: 28px; height: 28px; font-size: 20px; }
    .toolbar { gap: 7px; }
    .new-btn { padding: 8px 15px; font-size: 11.5px; }
    .stats-strip { padding: 10px 14px; gap: 8px; }
    .tc-hdr-row { padding: 13px 14px 0; gap: 10px; }
    .tc-content { padding: 8px 14px 4px; }
    .tc-av { width: 34px; height: 34px; }
    .tc-title { font-size: 13px; }
    .nested-replies { margin-left: 22px; }
    .cp-replies-list { padding: 10px 14px; }
    .cp-input-area { padding: 10px 14px; }
    .like-row { padding: 7px 14px; }
    .mod-strip { padding: 6px 14px; }
}

@media (max-width: 490px) {
    .page-shell { padding: 12px 10px 84px; }
    .page-top { gap: 6px; margin-bottom: 18px; }
    .page-title { font-size: 16px; }
    .page-sub { font-size: 9px; letter-spacing: 1.4px; margin-top: 3px; }
    .back-btn { width: 26px; height: 26px; font-size: 18px; margin-left: -4px; }
    .stats-strip { padding: 9px 12px; gap: 7px; margin-bottom: 14px; }
    .s-chip { font-size: 10.5px; }
    .toolbar { margin-bottom: 16px; }
    .new-btn { padding: 7px 13px; font-size: 11px; }
    .search-box { padding: 7px 13px; }
    .search-box input { font-size: 11.5px; }
    .sort-sel, .hist-btn { padding: 7px 12px; font-size: 10.5px; }
    .tc-hdr-row { padding: 11px 12px 0; gap: 9px; }
    .tc-content { padding: 7px 12px 4px; }
    .tc-av { width: 30px; height: 30px; }
    .tc-title { font-size: 12.5px; }
    .tc-body-text { font-size: 12px; }
    .tag { font-size: 9px; padding: 2px 7px; }
    .like-btn, .dislike-btn, .comm-toggle-btn { padding: 4px 11px; font-size: 11px; }
    .cp-orig-body { padding: 12px 14px; font-size: 12.5px; }
    .cp-poll { padding: 12px 14px; }
    .r-media-img { max-width: 150px; }
    .cm-inner { max-width: 94vw; }
    .cm-body { padding: 15px 16px; gap: 13px; }
}

@media (max-width: 380px) {
    .page-shell { padding: 10px 8px 78px; }
    .page-title { font-size: 14.5px; }
    .back-btn { width: 24px; height: 24px; font-size: 17px; }
    .stats-strip { flex-wrap: wrap; padding: 8px 10px; }
    .s-div { display: none; }
    .toolbar { flex-direction: column; align-items: stretch; }
    .new-btn { justify-content: center; }
    .hist-dd { width: calc(100vw - 24px); right: -2px; }
    .tc-hdr-row { padding: 10px 10px 0; gap: 8px; }
    .tc-content { padding: 6px 10px 4px; }
    .tc-av { width: 28px; height: 28px; }
    .tc-title { font-size: 12px; }
    .tc-time { font-size: 9.5px; }
    .like-row { gap: 5px; padding: 6px 10px; }
    .like-btn, .dislike-btn, .comm-toggle-btn { padding: 4px 9px; font-size: 10px; }
    .cp-replies-list { max-height: 320px; }
    .nested-replies { margin-left: 16px; padding-left: 8px; }
    .r-av { width: 24px; height: 24px; }
    .cp-textarea { font-size: 11.5px; min-height: 34px; }
    .cp-media-btn, .cp-send-btn { width: 32px; height: 32px; font-size: 13px; }
}

@media (max-width: 330px) {
    .page-title { font-size: 13px; }
    .page-sub { display: none; }
    .back-btn { width: 22px; height: 22px; font-size: 15px; }
    .s-chip strong { font-size: inherit; }
    .new-btn span, .hist-btn span { display: none; }
    .new-btn { padding: 7px 11px; font-size: 10px; }
    .hist-btn { padding: 6px 10px; font-size: 10px; }
    .search-box input { font-size: 11px; }
    .tc-title { font-size: 11.5px; }
    .tag { font-size: 8.5px; padding: 2px 6px; }
    .like-btn span, .dislike-btn span { display: none; }
    .cm-hdr-title { font-size: 14px; }
    .cm-submit, .cm-cancel { padding: 8px 15px; font-size: 11px; }
}

/* ══════════════════════════════════════════════
   GROUP BANNER — mirrors group.php, stays in sync
   ══════════════════════════════════════════════ */
.db-banner-container {
    position: relative;
    width: 100%;
    height: 220px;
    border-radius: 5px;
    overflow: visible;
    margin-bottom: 44px;
}
.db-banner {
    width: 100%; height: 100%;
    object-fit: cover;
    border-radius: 5px;
    display: block;
}
.db-banner-overlay {
    position: absolute;
    inset: 0;
    background: linear-gradient(to bottom, rgba(2,2,22,0.15), rgba(2,2,22,0.92));
    border-radius: 5px;
}
.db-pic-wrap {
    position: absolute;
    bottom: -30px;
    left: 20px;
    width: 92px; height: 92px;
    border: 5px solid #020216;
    border-radius: 22%;
    overflow: hidden;
    background: var(--surface-2);
    box-shadow: 0 8px 24px rgba(0,0,0,0.5);
}
.db-pic { width: 100%; height: 100%; object-fit: cover; display: block; }
.db-title-overlay {
    position: absolute;
    left: 128px; bottom: 14px;
    right: 20px;
    display: flex;
    flex-direction: column;
    gap: 3px;
}
.db-title-overlay .db-name {
    font-family: var(--font-h);
    font-size: clamp(15px, 2.6vw, 20px);
    font-weight: 800;
    color: #fff;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.db-title-overlay .db-sub {
    font-size: 11px;
    color: rgba(255,255,255,0.55);
    display: flex; align-items: center; gap: 5px;
}

@media (max-width: 790px) {
    .db-banner-container { height: 180px; margin-bottom: 38px; }
    .db-pic-wrap { width: 80px; height: 80px; bottom: -26px; left: 16px; border-width: 4px; }
    .db-title-overlay { left: 108px; right: 16px; }
}
@media (max-width: 490px) {
    .db-banner-container { height: 140px; margin-bottom: 32px; }
    .db-pic-wrap { width: 66px; height: 66px; bottom: -22px; left: 12px; border-width: 4px; border-radius: 50%; }
    .db-title-overlay { left: 88px; right: 12px; bottom: 12px; }
}
@media (max-width: 380px) {
    .db-banner-container { height: 118px; margin-bottom: 28px; }
    .db-pic-wrap { width: 56px; height: 56px; bottom: -20px; left: 10px; border-width: 3px; }
    .db-title-overlay { left: 74px; right: 10px; bottom: 10px; }
    .db-title-overlay .db-name { font-size: 13.5px; }
    .db-title-overlay .db-sub { font-size: 10px; }
}
@media (max-width: 330px) {
    .db-banner-container { height: 100px; margin-bottom: 24px; }
    .db-pic-wrap { width: 46px; height: 46px; bottom: -16px; left: 8px; border-width: 3px; }
    .db-title-overlay { left: 62px; right: 8px; bottom: 8px; }
    .db-title-overlay .db-name { font-size: 12px; }
    .db-title-overlay .db-sub { font-size: 9px; }
}

/* ══════════════════════════════════════════════
   FULL PAGE SKELETON LOADER
   ══════════════════════════════════════════════ */
#dpSkeleton {
    position: fixed;
    inset: 0;
    z-index: 999999999;
    background: var(--bg);
    overflow-y: auto;
    overflow-x: hidden;
    opacity: 1;
    transition: opacity 0.35s ease;
}
#dpSkeleton.dp-fade-out { opacity: 0; pointer-events: none; }
.dp-shimmer {
    position: relative;
    background: rgba(255,255,255,0.045);
    border-radius: 8px;
    overflow: hidden;
}
.dp-shimmer::after {
    content: '';
    position: absolute; inset: 0;
    transform: translateX(-100%);
    background: linear-gradient(90deg, transparent 0%, rgba(255,255,255,0.05) 25%, rgba(255,255,255,0.13) 50%, rgba(255,255,255,0.05) 75%, transparent 100%);
    animation: dpSweep 1.6s ease-in-out infinite;
}
@keyframes dpSweep { 0% { transform: translateX(-100%); } 100% { transform: translateX(100%); } }
.dp-shell {
    display: flex; gap: 28px;
    max-width: 1280px; margin: 0 auto;
    padding: 36px 24px 100px;
}
.dp-side { width: 236px; flex-shrink: 0; display: flex; flex-direction: column; gap: 14px; }
.dp-widget { height: 140px; border-radius: var(--r); }
.dp-main { flex: 1; min-width: 0; }
.dp-banner { height: 220px; border-radius: var(--r-lg); margin-bottom: 44px; }
.dp-title { height: 26px; width: 55%; border-radius: 6px; margin-bottom: 20px; }
.dp-strip { height: 44px; border-radius: var(--r); margin-bottom: 18px; }
.dp-toolbar { height: 40px; border-radius: 40px; margin-bottom: 22px; }
.dp-card { height: 96px; border-radius: var(--r); margin-bottom: 10px; }
@media (max-width: 1024px) { .dp-side.dp-right { display: none; } }
@media (max-width: 790px) {
    .dp-side.dp-left { display: none; }
    .dp-shell { padding: 16px 14px 90px; }
    .dp-banner { height: 180px; margin-bottom: 38px; }
}
@media (max-width: 490px) {
    .dp-banner { height: 140px; margin-bottom: 32px; }
}
@media (max-width: 380px) {
    .dp-banner { height: 118px; margin-bottom: 28px; }
}
@media (max-width: 330px) {
    .dp-banner { height: 100px; margin-bottom: 24px; }
}

/* ══════════════════════════════════════════════
   PREMIUM BACK BUTTON — floats on banner, icon only
   ══════════════════════════════════════════════ */
.db-back-btn {
    position: absolute;
    top: 18px;
    left: 0;
    width: 50px;
    height: 50px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: none;
    border: none;
    color: #fff;
    text-decoration: none;
    z-index: 6;
    transition: transform 0.25s ease, color 0.25s ease, filter 0.25s ease;
}
.db-back-btn i {
    font-size: 34px;
    line-height: 1;
    filter: drop-shadow(0 2px 6px rgba(0,0,0,0.65)) drop-shadow(0 0 14px rgba(0,0,0,0.35));
    transition: transform 0.25s ease;
}
.db-back-btn:hover {
    color: var(--red);
    transform: translateX(-3px);
}
.db-back-btn:hover i { transform: translateX(-2px); }
.db-back-btn:active { transform: translateX(-1px) scale(0.92); }

@media (max-width: 790px) {
    .db-back-btn { width: 46px; height: 46px; top: 14px; left: -2px; }
    .db-back-btn i { font-size: 30px; }
}
@media (max-width: 490px) {
    .db-back-btn { width: 42px; height: 42px; top: 12px; left: -2px; }
    .db-back-btn i { font-size: 27px; }
}
@media (max-width: 380px) {
    .db-back-btn { width: 38px; height: 38px; top: 10px; left: -2px; }
    .db-back-btn i { font-size: 24px; }
}
@media (max-width: 330px) {
    .db-back-btn { width: 34px; height: 34px; top: 8px; left: -2px; }
    .db-back-btn i { font-size: 21px; }
}

.db-add-btn {
    position: absolute;
    top: 19px;
    right: 16px;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #2a2a2e;
    border: 1px solid rgba(255,255,255,0.14);
    color: #fff;
    cursor: pointer;
    z-index: 6;
    transition: background 0.25s ease, border-color 0.25s ease, transform 0.25s ease, color 0.25s ease;
}
.db-add-btn i {
    font-size: 26px;
    line-height: 1;
    transition: transform 0.25s ease;
}
.db-add-btn:hover {
    background: #2a2a2e;
    border: 1px solid rgba(255,255,255,0.14);
    transform: translateY(-2px);
}
.db-add-btn:hover i { transform: rotate(90deg); }
.db-add-btn:active { transform: translateY(0) scale(0.94); }

@media (max-width: 790px) {
    .db-add-btn { width: 36px; height: 36px; top: 20px; right: 14px; }
    .db-add-btn i { font-size: 23px; }
}
@media (max-width: 490px) {
    .db-add-btn { width: 32px; height: 32px; top: 16px; right: 12px; }
    .db-add-btn i { font-size: 20px; }
}
@media (max-width: 380px) {
    .db-add-btn { width: 30px; height: 30px; top: 13px; right: 10px; }
    .db-add-btn i { font-size: 18px; }
}
@media (max-width: 330px) {
    .db-add-btn { width: 28px; height: 28px; top: 11px; right: 8px; }
    .db-add-btn i { font-size: 16px; }
}


/* sort buttons */
.sort-btn-row {
    display: flex; gap: 8px; align-items: center;
    margin-bottom: 12px;
    overflow-x: auto;
    scrollbar-width: none;
}
.sort-btn-row::-webkit-scrollbar { display: none; }
.sort-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 15px; border-radius: var(--r-sm);
    background: var(--surface);
    border: 1px solid var(--border);
    color: var(--text-2); font-family: var(--font-h);
    font-size: 11.5px; font-weight: 600;
    text-decoration: none; white-space: nowrap;
    cursor: pointer; transition: 0.2s; flex-shrink: 0;
}
.sort-btn i { font-size: 13px; }
.sort-btn:hover { border-color: var(--border-2); color: #fff; }
.sort-btn.active {
    background: var(--red-soft);
    border-color: var(--red-mid);
    color: var(--red);
}
@media (max-width: 490px) {
    .sort-btn { padding: 6px 12px; font-size: 10.5px; }
    .sort-btn-row { gap: 6px; margin-bottom: 10px; }
}
@media (max-width: 330px) {
    .sort-btn i { font-size: 12px; }
    .sort-btn { padding: 5px 10px; font-size: 10px; }
}


/* you button */
.you-btn {
    flex-shrink: 0;
    display: flex; align-items: center; gap: 7px;
    padding: 4px 12px 4px 4px; border-radius: var(--r-sm);
    background: var(--surface);
    border: 1px solid var(--border);
    color: var(--text); font-size: 11.5px; font-weight: 700;
    font-family: var(--font-h); cursor: pointer; transition: 0.2s;
    white-space: nowrap;
}
.you-btn:hover { border-color: var(--red-mid); background: var(--red-soft); }
.you-btn-av { width: 24px; height: 24px; border-radius: 50%; object-fit: cover; flex-shrink: 0; border: 3px solid #ff3b3b; }
.you-badge {
    background: var(--red); color: #fff; font-size: 9px; font-weight: 800;
    min-width: 16px; height: 16px; border-radius: 10px; padding: 0 4px;
    display: inline-flex; align-items: center; justify-content: center;
}

/* you modal — fullscreen */
#youModal { display: none; position: fixed; inset: 0; z-index: 9999998; background: var(--bg); overflow-y: auto; }
#youModal.open { display: block; }
.you-inner { max-width: 720px; margin: 0 auto; padding: 20px 20px 60px; min-height: 100vh; }
.you-hdr {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 0 18px; border-bottom: 1px solid var(--border); margin-bottom: 18px;
    position: sticky; top: 0; background: var(--bg); z-index: 2;
}
.you-hdr-left { display: flex; align-items: center; gap: 12px; }
.you-hdr-av { width: 44px; height: 44px; border-radius: 50%; object-fit: cover; border: 2px solid var(--red); }
.you-hdr-title { font-family: var(--font-h); font-size: 17px; font-weight: 800; color: #fff; }
.you-hdr-sub { font-size: 11.5px; color: var(--text-3); margin-top: 2px; }
.you-close {
    width: 36px; height: 36px; border-radius: 50%;
    background: var(--surface); border: 1px solid var(--border);
    color: var(--text-2); font-size: 18px; cursor: pointer;
    display: flex; align-items: center; justify-content: center; transition: 0.2s; flex-shrink: 0;
}
.you-close:hover { color: #fff; border-color: var(--border-2); }
.you-sort-row { display: flex; gap: 8px; margin-bottom: 18px; flex-wrap: wrap; }
.you-sort-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 15px; border-radius: var(--r-sm);
    background: var(--surface); border: 1px solid var(--border);
    color: var(--text-2); font-family: var(--font-h); font-size: 11.5px; font-weight: 600;
    cursor: pointer; transition: 0.2s;
}
.you-sort-btn i { font-size: 13px; }
.you-sort-btn:hover { border-color: var(--border-2); color: #fff; }
.you-sort-btn.active { background: var(--red-soft); border-color: var(--red-mid); color: var(--red); }
.you-list { display: flex; flex-direction: column; gap: 10px; }
.you-item {
    display: flex; align-items: stretch;
    border: 1px solid var(--border); border-radius: var(--r);
    background: var(--surface); overflow: hidden; transition: border-color 0.2s;
}
.you-item:hover { border-color: var(--border-2); }
.you-item-main { flex: 1; min-width: 0; padding: 14px 16px; cursor: pointer; }
.you-item-top { display: flex; align-items: center; gap: 6px; margin-bottom: 6px; }
.you-item-title { font-family: var(--font-h); font-size: 14px; font-weight: 700; color: #fff; margin-bottom: 4px; }
.you-item-body { font-size: 11.5px; color: var(--text-2); line-height: 1.5; margin-bottom: 8px; }
.you-item-stats { display: flex; align-items: center; gap: 12px; font-size: 10.5px; color: var(--text-3); flex-wrap: wrap; }
.you-item-stats i { font-size: 11px; margin-right: 2px; }
.you-item-date { margin-left: auto; }
.you-item-del { display: flex; align-items: center; border-left: 1px solid var(--border); }
.you-del-btn { width: 44px; height: 100%; background: transparent; border: none; color: rgba(255,59,59,0.5); font-size: 16px; cursor: pointer; transition: 0.15s; }
.you-del-btn:hover { color: var(--red); background: var(--red-soft); }
.you-empty { text-align: center; padding: 60px 20px; color: var(--text-3); }
.you-empty i { font-size: 46px; display: block; margin-bottom: 12px; opacity: 0.25; }
.you-empty p { font-size: 12.5px; }

/* ============================================
   RESPONSIVE — 790px
============================================ */
@media (max-width: 790px) {
    .you-inner { padding: 16px 16px 50px; }
    .you-hdr { padding: 12px 0 16px; margin-bottom: 16px; }
    .you-hdr-av { width: 40px; height: 40px; }
    .you-hdr-title { font-size: 16px; }
    .you-hdr-sub { font-size: 11px; }
    .you-close { width: 34px; height: 34px; font-size: 17px; }
    .you-sort-row { gap: 7px; margin-bottom: 16px; }
    .you-sort-btn { padding: 6px 13px; font-size: 11px; }
    .you-item-main { padding: 13px 15px; }
    .you-item-title { font-size: 13.5px; }
    .you-item-body { font-size: 11px; }
}

/* ============================================
   RESPONSIVE — 490px
============================================ */
@media (max-width: 490px) {
    .you-btn { padding: 4px 10px 4px 4px; gap: 6px; font-size: 11px; }
    .you-btn-av { width: 22px; height: 22px; border-width: 2.5px; }
    .you-badge { font-size: 8.5px; min-width: 15px; height: 15px; }

    .you-inner { padding: 14px 14px 44px; }
    .you-hdr { padding: 10px 0 14px; margin-bottom: 14px; }
    .you-hdr-left { gap: 10px; }
    .you-hdr-av { width: 38px; height: 38px; border-width: 1.5px; }
    .you-hdr-title { font-size: 15px; }
    .you-hdr-sub { font-size: 10.5px; }
    .you-close { width: 32px; height: 32px; font-size: 16px; }

    .you-sort-row { gap: 6px; margin-bottom: 14px; }
    .you-sort-btn { padding: 6px 11px; font-size: 10.5px; gap: 5px; }
    .you-sort-btn i { font-size: 12px; }

    .you-list { gap: 8px; }
    .you-item-main { padding: 12px 14px; }
    .you-item-top { gap: 5px; margin-bottom: 5px; }
    .you-item-title { font-size: 13px; margin-bottom: 3px; }
    .you-item-body { font-size: 10.5px; margin-bottom: 7px; }
    .you-item-stats { gap: 10px; font-size: 10px; }
    .you-del-btn { width: 40px; font-size: 15px; }

    .you-empty { padding: 48px 16px; }
    .you-empty i { font-size: 40px; margin-bottom: 10px; }
    .you-empty p { font-size: 12px; }
}

/* ============================================
   RESPONSIVE — 390px
============================================ */
@media (max-width: 390px) {
    .you-btn { padding: 3px 9px 3px 3px; gap: 5px; font-size: 10.5px; }
    .you-btn-av { width: 18px; height: 18px; border-width: 2px; }
    .you-badge { font-size: 8px; min-width: 14px; height: 14px; padding: 0 3px; }

    .you-inner { padding: 12px 12px 40px; }
    .you-hdr { padding: 10px 0 12px; margin-bottom: 12px; }
    .you-hdr-left { gap: 9px; }
    .you-hdr-av { width: 36px; height: 36px; }
    .you-hdr-title { font-size: 14px; }
    .you-hdr-sub { font-size: 10px; }
    .you-close { width: 30px; height: 30px; font-size: 15px; }

    .you-sort-row { gap: 6px; margin-bottom: 12px; }
    .you-sort-btn { padding: 5px 10px; font-size: 10px; }
    .you-sort-btn i { font-size: 11px; }

    .you-item-main { padding: 11px 12px; }
    .you-item-title { font-size: 12.5px; }
    .you-item-body { font-size: 10px; line-height: 1.45; }
    .you-item-stats { gap: 8px; font-size: 9.5px; }
    .you-item-stats i { font-size: 10px; }
    .you-del-btn { width: 38px; font-size: 14px; }
}

/* ============================================
   RESPONSIVE — 330px
============================================ */
@media (max-width: 330px) {
    .you-btn { padding: 3px 7px 3px 3px; gap: 4px; font-size: 10px; }
    .you-btn-av { width: 16px; height: 16px; border-width: 2px; }
    .you-badge { font-size: 7.5px; min-width: 13px; height: 13px; }

    .you-inner { padding: 10px 10px 36px; }
    .you-hdr-av { width: 32px; height: 32px; }
    .you-hdr-title { font-size: 13px; }
    .you-hdr-sub { font-size: 9.5px; }
    .you-close { width: 28px; height: 28px; font-size: 14px; }

    .you-sort-row { gap: 5px; }
    .you-sort-btn { padding: 5px 9px; font-size: 9.5px; gap: 4px; }
    .you-sort-btn span { display: none; }

    .you-item { flex-direction: column; }
    .you-item-main { padding: 10px 11px; }
    .you-item-title { font-size: 12px; }
    .you-item-body { font-size: 9.5px; -webkit-line-clamp: 2; }
    .you-item-stats { gap: 7px; font-size: 9px; }
    .you-item-date { margin-left: 0; }
    .you-item-del { border-left: none; border-top: 1px solid var(--border); }
    .you-del-btn { width: 100%; height: 34px; }
}

/* ══════════════════════════════════════════════
   MEDIA GALLERY (multi-attachment) — redesigned
   ══════════════════════════════════════════════ */
.tc-gallery {
    display: grid; gap: 3px;
    margin: 10px 0 6px;
    border-radius: 16px;
    overflow: hidden;
    width: 100%;
    border: 1px solid rgba(255,255,255,0.07);
    box-shadow: 0 10px 28px rgba(0,0,0,0.35);
}
.tc-g-item {
    position: relative; overflow: hidden; background: #000; cursor: pointer;
    transition: filter 0.25s ease;
}
.tc-g-item:hover { filter: brightness(1.08); }
.tc-g-item img, .tc-g-item video {
    width: 100%; height: 100%; object-fit: cover; display: block;
    transition: transform 0.45s cubic-bezier(.22,1,.36,1);
}
.tc-g-item:hover img, .tc-g-item:hover video { transform: scale(1.05); }

.tc-gallery-1 { grid-template-columns: 1fr; height: 340px; }
.tc-gallery-1 .tc-g-item { height: 100%; }

.tc-gallery-2 { grid-template-columns: 1fr 1fr; height: 280px; }
.tc-gallery-2 .tc-g-item { height: 100%; }

.tc-gallery-3 { grid-template-columns: 1.2fr 1fr; grid-template-rows: 1fr 1fr; height: 280px; }
.tc-gallery-3 .tc-g-item:nth-child(1) { grid-row: 1 / 3; }
.tc-gallery-3 .tc-g-item { height: 100%; }

.tc-gallery-4 { grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr; height: 320px; }
.tc-gallery-4 .tc-g-item { height: 100%; }

.tc-g-vid-badge { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; pointer-events: none; }
.tc-g-vid-badge i {
    font-size: 20px; color: #fff;
    width: 46px; height: 46px; border-radius: 50%;
    background: rgba(0,0,0,0.45);
    backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 6px 18px rgba(0,0,0,0.45);
}
.tc-g-badge {
    position: absolute; top: 8px; left: 8px; font-size: 10px; font-weight: 800; padding: 3px 9px;
    border-radius: 20px; background: rgba(0,0,0,0.6); backdrop-filter: blur(6px);
    border: 1px solid rgba(255,255,255,0.15);
    color: #fff; text-transform: uppercase; z-index: 2; letter-spacing: 0.5px;
}
.tc-g-more {
    position: absolute; inset: 0; background: rgba(0,0,0,0.65); color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 24px; font-weight: 800; font-family: var(--font-h);
    backdrop-filter: blur(4px);
}

/* ══════════════════════════════════════════════
   RESPONSIVE — 790px
   ══════════════════════════════════════════════ */
@media (max-width: 790px) {
    .tc-gallery { gap: 3px; margin: 9px 0 5px; }
    .tc-gallery-1 { height: 300px; }
    .tc-gallery-2, .tc-gallery-3 { height: 240px; }
    .tc-gallery-4 { height: 260px; }
    .tc-g-vid-badge i { width: 40px; height: 40px; font-size: 17px; }
    .tc-g-badge { font-size: 9px; padding: 2px 8px; top: 6px; left: 6px; }
    .tc-g-more { font-size: 20px; }
}

/* ══════════════════════════════════════════════
   RESPONSIVE — 490px
   ══════════════════════════════════════════════ */
@media (max-width: 490px) {
    .tc-gallery { gap: 3px; margin: 8px 0 5px; border-radius: 13px; }
    .tc-gallery-1 { height: 250px; }
    .tc-gallery-2, .tc-gallery-3 { height: 200px; }
    .tc-gallery-4 { height: 220px; }
    .tc-g-vid-badge i { width: 36px; height: 36px; font-size: 15px; }
    .tc-g-badge { font-size: 8.5px; padding: 2px 7px; top: 5px; left: 5px; border-radius: 16px; }
    .tc-g-more { font-size: 18px; }
}

/* ══════════════════════════════════════════════
   RESPONSIVE — 390px
   ══════════════════════════════════════════════ */
@media (max-width: 390px) {
    .tc-gallery { gap: 2px; margin: 7px 0 4px; border-radius: 12px; }
    .tc-gallery-1 { height: 220px; }
    .tc-gallery-2, .tc-gallery-3 { height: 170px; }
    .tc-gallery-4 { height: 190px; }
    .tc-g-vid-badge i { width: 32px; height: 32px; font-size: 14px; }
    .tc-g-badge { font-size: 8px; padding: 2px 6px; top: 4px; left: 4px; }
    .tc-g-more { font-size: 16px; }
}

/* ══════════════════════════════════════════════
   RESPONSIVE — 330px
   ══════════════════════════════════════════════ */
@media (max-width: 330px) {
    .tc-gallery { gap: 2px; margin: 6px 0 3px; border-radius: 11px; }
    .tc-gallery-1 { height: 190px; }
    .tc-gallery-2, .tc-gallery-3 { height: 150px; }
    .tc-gallery-4 { height: 170px; }
    .tc-g-vid-badge i { width: 28px; height: 28px; font-size: 12px; }
    .tc-g-badge { font-size: 7.5px; padding: 1.5px 5px; top: 3px; left: 3px; }
    .tc-g-more { font-size: 14px; }
}

/* ══════════════════════════════════════════════
   LIGHTBOX — topbar (avatar+username left, X stacked above counter, right),
   type badge top-left, hover-reveal arrows on desktop that
   are fully removed on responsive, smart no-gap object-fit
   media box, circular dot progress, and a horizontal action
   row (like / dislike / comment / share / menu)
   ══════════════════════════════════════════════ */
#lightbox {
    display: none; position: fixed; inset: 0; z-index: 99999999;
    background: rgba(3,4,32,0.97);
    backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
    align-items: center; justify-content: center;
}
#lightbox.open { display: flex; animation: lbFadeIn 0.25s ease; }
@keyframes lbFadeIn { from{opacity:0} to{opacity:1} }

/* ── top bar: avatar + username (left), X + counter stacked (right) ── */
.lb-topbar {
    position: absolute; top: 0; left: 0; right: 0; z-index: 4;
    display: flex; align-items: flex-start; justify-content: space-between;
    padding: 18px 18px 40px;
    background: linear-gradient(to bottom, rgba(2,2,22,0.75) 0%, rgba(2,2,22,0.35) 60%, transparent 100%);
    pointer-events: none;
}
.lb-topbar > * { pointer-events: all; }
.lb-user-info { display: flex; align-items: center; gap: 10px; min-width: 0; }
.lb-user-pic {
    width: 38px; height: 38px; border-radius: 50%; object-fit: cover;
    border: 2px solid var(--red); flex-shrink: 0;
}
.lb-username {
    font-family: var(--font-h); font-size: 13.5px; font-weight: 700; color: #fff;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 46vw;
    text-shadow: 0 1px 4px rgba(0,0,0,0.5);
}

/* ── right cluster: close X on top, counter pill stacked directly below it.
     This is the ONLY .lb-topbar-right / .lb-close / .lb-counter definition
     in the stylesheet — do not duplicate these selectors elsewhere, or the
     later rule will win the cascade and break this stacking/flat look. ── */
.lb-topbar-right {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 6px;
    flex-shrink: 0;
    position: relative;
}
.lb-topbar-right .lb-close {
    order: 1;
    position: static;
}
.lb-topbar-right .lb-counter {
    order: 2;
    margin-top: 2px;
    position: static;
}

/* ── close X: flat icon, no circle, no background, no border ──
     !important here is intentional insurance against any stray duplicate
     .lb-close rule elsewhere in the stylesheet re-adding a circle. ── */
.lb-close {
    position: static;
    background: none !important;
    border: none !important;
    box-shadow: none !important;
    border-radius: 0 !important;
    width: auto !important;
    height: auto !important;
    color: #fff; cursor: pointer; padding: 4px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    transition: transform 0.2s ease, color 0.2s ease, opacity 0.2s ease;
}
.lb-close i {
    font-size: 34px;
    -webkit-text-stroke: 1.2px currentColor;
    filter: drop-shadow(0 2px 6px rgba(0,0,0,0.6));
}
.lb-close:hover { color: var(--red); transform: scale(1.1) rotate(90deg); }
.lb-close:active { transform: scale(0.92) rotate(90deg); }

/* ── media-type badge, top-left ── */
.lb-type-badge {
    position: absolute; top: 66px; left: 18px; z-index: 4;
    display: flex; align-items: center; gap: 6px;
    background: rgba(0,0,0,0.55); backdrop-filter: blur(6px);
    border: 1px solid rgba(255,255,255,0.14);
    border-radius: 20px; padding: 5px 12px 5px 10px;
    color: #fff; font-size: 10.5px; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.5px;
}
.lb-type-badge i { font-size: 13px; }

/* ── stage: fixed box so media can fill it without side gaps ── */
.lb-stage {
    position: relative;
    width: min(94vw, 620px);
    height: min(78vh, 760px);
    display: flex; align-items: center; justify-content: center;
    border-radius: 18px;
    overflow: hidden;
    background: #000;
    box-shadow: 0 30px 90px rgba(0,0,0,0.6), 0 0 0 1px rgba(255,255,255,0.06);
}
.lb-stage img, .lb-stage video {
    width: 100%; height: 100%;
    object-fit: contain; /* JS (lbFixObjectFit) refines this per item */
    display: block;
}

/* ── nav arrows: smooth, hover-reveal on desktop, fully hidden on mobile ── */
.lb-nav {
    position: absolute; top: 50%; transform: translateY(-50%); width: 50px; height: 50px; border-radius: 50%;
    background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.14); color: #fff;
    font-size: 28px; display: flex; align-items: center; justify-content: center; cursor: pointer; z-index: 2;
    backdrop-filter: blur(8px);
    opacity: 0;
    transition: opacity 0.28s ease, background 0.2s ease, border-color 0.2s ease, color 0.2s ease, transform 0.2s ease;
}
#lightbox:hover .lb-nav { opacity: 1; }
.lb-nav:hover { background: var(--red-soft); border-color: var(--red-mid); color: var(--red); transform: translateY(-50%) scale(1.08); }
.lb-prev { left: 22px; }
.lb-next { right: 22px; }

/* ── action row: like / dislike / comment / share / menu — flat icons,
     no pill background/border, bigger and much more spaced out ── */
.lb-actions-row {
    position: absolute; bottom: 96px; left: 50%; transform: translateX(-50%); z-index: 4;
    display: flex; align-items: center; gap: 32px;
}
.lb-act-btn {
    display: inline-flex; align-items: center; gap: 8px;
    background: none;
    border: none;
    box-shadow: none;
    color: #fff; font-size: 14px; font-weight: 700; font-family: var(--font-h);
    padding: 6px 2px;
    cursor: pointer; transition: color 0.2s ease, transform 0.15s ease;
    white-space: nowrap;
}
.lb-act-btn i {
    font-size: 22px;
    filter: drop-shadow(0 1px 4px rgba(0,0,0,0.5));
}
.lb-act-btn:hover { color: var(--red); transform: scale(1.08); }
.lb-act-btn.active { color: var(--red); }

/* ── progress indicator: pill-shaped bars instead of round dots ── */
.lb-dashes {
    position: absolute; bottom: 40px; left: 16px; right: 16px;
    display: flex; justify-content: center; align-items: center; gap: 6px; z-index: 2;
}
.lb-dash {
    flex: 1 1 auto; max-width: 400px; min-width: 6px;
    height: 3px;
    border-radius: 99px;
    background: rgba(255,255,255,0.28);
    transition: background 0.2s ease, transform 0.2s ease, max-width 0.2s ease;
}
.lb-dash.active { background: var(--red); transform: scale(1); }

/* ── counter: sits under the close X, top-right ── */
.lb-counter {
    font-size: 11px; font-weight: 700; color: rgba(255,255,255,0.8);
    background: rgba(255,255,255,0.08); padding: 4px 11px; border-radius: 20px; font-family: var(--font-h);
    border: 1px solid rgba(255,255,255,0.12); backdrop-filter: blur(8px);
    white-space: nowrap;
}

/* ══════════════════════════════════════════════
   RESPONSIVE — 790px
   ══════════════════════════════════════════════ */
@media (max-width: 790px) {
    .lb-topbar { padding: 14px 14px 34px; }
    .lb-topbar-right { gap: 5px; }
    .lb-user-pic { width: 33px; height: 33px; }
    .lb-username { font-size: 12.5px; max-width: 40vw; }
    .lb-close i { font-size: 30px; }
    .lb-type-badge { top: 56px; left: 14px; font-size: 9.5px; padding: 4px 10px 4px 8px; }
    .lb-type-badge i { font-size: 12px; }

    .lb-stage { width: 94vw; height: 74vh; border-radius: 15px; }

    .lb-nav { display: none !important; } /* arrows completely hidden in responsive view */

    .lb-actions-row { bottom: 82px; gap: 26px; }
    .lb-act-btn { font-size: 12.5px; gap: 7px; }
    .lb-act-btn i { font-size: 20px; }

    .lb-dashes { bottom: 36px; gap: 5px; }
    .lb-dash { width: 17px; height: 3px; }
    .lb-dash.active { width: 17px; }
    .lb-counter { font-size: 10.5px; padding: 4px 10px; }
}

/* ══════════════════════════════════════════════
   RESPONSIVE — 490px
   ══════════════════════════════════════════════ */
@media (max-width: 490px) {
    .lb-topbar { padding: 12px 12px 30px; }
    .lb-topbar-right { gap: 5px; }
    .lb-user-pic { width: 29px; height: 29px; border-width: 1.5px; }
    .lb-username { font-size: 11.5px; max-width: 38vw; }
    .lb-close i { font-size: 27px; }
    .lb-type-badge { top: 48px; left: 12px; font-size: 9px; padding: 4px 9px 4px 7px; border-radius: 16px; }
    .lb-type-badge i { font-size: 11px; }

    .lb-stage { width: 94vw; height: 68vh; border-radius: 13px; }

    .lb-actions-row { bottom: 70px; gap: 22px; }
    .lb-act-btn { font-size: 11.5px; gap: 6px; }
    .lb-act-btn i { font-size: 18px; }

    .lb-dashes { bottom: 32px; gap: 4px; }
    .lb-dash { width: 15px; height: 3px; }
    .lb-dash.active { width: 15px; }
    .lb-counter { font-size: 10px; padding: 4px 9px; }
}

/* ══════════════════════════════════════════════
   RESPONSIVE — 390px
   ══════════════════════════════════════════════ */
@media (max-width: 390px) {
    .lb-topbar { padding: 10px 10px 26px; }
    .lb-topbar-right { gap: 4px; }
    .lb-user-pic { width: 26px; height: 26px; }
    .lb-username { font-size: 11px; max-width: 34vw; }
    .lb-close i { font-size: 24px; }
    .lb-type-badge { top: 42px; left: 10px; font-size: 8.5px; padding: 3.5px 8px 3.5px 6px; }
    .lb-type-badge i { font-size: 10px; }

    .lb-stage { width: 95vw; height: 62vh; border-radius: 12px; }

    .lb-actions-row { bottom: 60px; gap: 17px; }
    .lb-act-btn { font-size: 11px; gap: 5px; }
    .lb-act-btn i { font-size: 17px; }
    /* Count text (like/comment) stays visible here, same as desktop —
       no more hiding the span on small screens. */

    .lb-dashes { bottom: 28px; gap: 4px; }
    .lb-dash { width: 13px; height: 2.5px; }
    .lb-dash.active { width: 13px; }
    .lb-counter { font-size: 9.5px; padding: 3px 8px; }
}

/* ══════════════════════════════════════════════
   RESPONSIVE — 330px
   ══════════════════════════════════════════════ */
@media (max-width: 330px) {
    .lb-topbar { padding: 8px 8px 22px; }
    .lb-topbar-right { gap: 4px; }
    .lb-user-pic { width: 23px; height: 23px; }
    .lb-username { font-size: 10px; max-width: 30vw; }
    .lb-close i { font-size: 21px; }
    .lb-type-badge { top: 38px; left: 8px; font-size: 8px; padding: 3px 7px 3px 5px; }
    .lb-type-badge i { font-size: 9.5px; }

    .lb-stage { width: 96vw; height: 56vh; border-radius: 10px; }

    .lb-actions-row { bottom: 52px; gap: 13px; }
    .lb-act-btn { padding: 5px 2px; font-size: 10px; gap: 4px; }
    .lb-act-btn i { font-size: 16px; }
    /* Count text (like/comment) stays visible here too. */

    .lb-dashes { bottom: 24px; gap: 3px; }
    .lb-dash { width: 11px; height: 2px; }
    .lb-dash.active { width: 11px; }
    .lb-counter { font-size: 9px; padding: 3px 8px; }
}

.poll-type-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.poll-type-card {
    padding: 14px 12px; border-radius: var(--r-sm);
    border: 1px solid var(--border); background: rgba(255,255,255,0.02);
    cursor: pointer; transition: 0.2s; text-align: center;
}
.poll-type-card i { font-size: 22px; color: var(--text-3); display: block; margin-bottom: 6px; transition: 0.2s; }
.ptc-title { font-family: var(--font-h); font-size: 12.5px; font-weight: 700; color: var(--text); margin-bottom: 3px; }
.ptc-sub { font-size: 10px; color: var(--text-3); line-height: 1.3; }
.poll-type-card.sel { border-color: var(--red-mid); background: var(--red-soft); }
.poll-type-card.sel i { color: var(--red); }
.poll-opt-row { display: flex; align-items: center; gap: 8px; }
.correct-radio { accent-color: var(--red); width: 16px; height: 16px; cursor: pointer; flex-shrink: 0; }
.poll-quiz-hint { display: flex; align-items: center; gap: 6px; font-size: 10.5px; color: var(--red); margin-top: 8px; }

/* ══════ VOTE POLL — clean, minimal, blue accent ══════ */
.vp-wrap { margin: 10px 0 6px; padding: 16px; border-radius: 14px; background: linear-gradient(160deg, rgba(74,168,255,0.06), rgba(74,168,255,0.01)); border: 1px solid rgba(74,168,255,0.18); }
.vp-hdr { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.vp-badge { display: inline-flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 800; color: var(--blue); font-family: var(--font-h); text-transform: uppercase; letter-spacing: 0.5px; }
.vp-timer { display: inline-flex; align-items: center; gap: 5px; font-size: 10.5px; color: var(--text-3); font-weight: 600; }
.vp-opts { display: flex; flex-direction: column; gap: 8px; }
.vp-opt { position: relative; display: flex; align-items: center; gap: 8px; padding: 12px 14px; border-radius: 11px; background: rgba(255,255,255,0.03); border: 1.5px solid var(--border); cursor: pointer; transition: 0.2s; overflow: hidden; }
.vp-opt:hover { border-color: rgba(74,168,255,0.4); background: rgba(74,168,255,0.04); }
.vp-opt input[type=radio] { display: none; }
.vp-bar { position: absolute; left: 0; top: 0; height: 100%; background: rgba(74,168,255,0.14); transition: width 0.5s ease; pointer-events: none; }
.vp-opt-text { position: relative; z-index: 1; flex: 1; font-size: 13px; font-weight: 600; color: var(--text); }
.vp-picked-icon { position: relative; z-index: 1; color: var(--blue); font-size: 15px; }
.vp-pct { position: relative; z-index: 1; font-size: 12px; font-weight: 800; color: var(--blue); font-family: var(--font-h); min-width: 34px; text-align: right; }
.vp-opt.vp-picked { border-color: var(--blue); }
.vp-submit-btn { width: 100%; margin-top: 12px; padding: 11px; border-radius: 11px; background: var(--blue); border: none; color: #fff; font-family: var(--font-h); font-size: 12.5px; font-weight: 800; cursor: pointer; transition: 0.2s; }
.vp-submit-btn:hover { background: #5fb4ff; transform: translateY(-1px); }
.vp-result-note { text-align: center; font-size: 11px; color: var(--text-3); margin-top: 10px; font-weight: 600; }

/* ══════ QUIZ POLL — bold, red accent, correct/wrong states ══════ */
.qz-wrap { margin: 10px 0 6px; padding: 16px; border-radius: 14px; background: linear-gradient(160deg, rgba(255,59,59,0.07), rgba(255,59,59,0.01)); border: 1px solid rgba(255,59,59,0.2); }
.qz-hdr { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.qz-badge { display: inline-flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 800; color: var(--red); font-family: var(--font-h); text-transform: uppercase; letter-spacing: 0.5px; }
.qz-timer { display: inline-flex; align-items: center; gap: 5px; font-size: 10.5px; color: var(--text-3); font-weight: 600; }
.qz-opts { display: flex; flex-direction: column; gap: 8px; }
.qz-opt { position: relative; display: flex; align-items: center; gap: 8px; padding: 12px 14px; border-radius: 11px; background: rgba(255,255,255,0.03); border: 1.5px solid var(--border); cursor: pointer; transition: 0.2s; overflow: hidden; }
.qz-opt:hover { border-color: rgba(255,59,59,0.4); background: rgba(255,59,59,0.04); }
.qz-opt input[type=radio] { display: none; }
.qz-bar { position: absolute; left: 0; top: 0; height: 100%; background: rgba(255,255,255,0.05); transition: width 0.5s ease; pointer-events: none; }
.qz-opt-text { position: relative; z-index: 1; flex: 1; font-size: 13px; font-weight: 600; color: var(--text); }
.qz-pct { position: relative; z-index: 1; font-size: 12px; font-weight: 800; color: var(--text-3); font-family: var(--font-h); min-width: 34px; text-align: right; }
.qz-opt.qz-picked { border-color: rgba(255,59,59,0.5); }
.qz-opt.qz-correct { border-color: var(--green); background: rgba(61,220,132,0.08); }
.qz-opt.qz-correct .qz-bar { background: rgba(61,220,132,0.18); }
.qz-opt.qz-correct .qz-pct { color: var(--green); }
.qz-opt.qz-wrong { border-color: var(--red-mid); background: rgba(255,59,59,0.06); }
.qz-opt.qz-wrong .qz-bar { background: rgba(255,59,59,0.14); }
.qz-icon-correct { position: relative; z-index: 1; color: var(--green); font-size: 16px; }
.qz-icon-wrong { position: relative; z-index: 1; color: var(--red); font-size: 16px; }
.qz-submit-btn { width: 100%; margin-top: 12px; padding: 11px; border-radius: 11px; background: var(--red); border: none; color: #fff; font-family: var(--font-h); font-size: 12.5px; font-weight: 800; cursor: pointer; transition: 0.2s; }
.qz-submit-btn:hover { background: #ff5252; transform: translateY(-1px); }
.qz-result-note { text-align: center; font-size: 11.5px; color: var(--text-3); margin-top: 10px; font-weight: 600; }
.qz-you-correct { color: var(--green); font-weight: 800; }
.qz-you-wrong { color: var(--text-2); }

/* ══════════════════════════════════════════════
   RESPONSIVE — 790px
   ══════════════════════════════════════════════ */
@media (max-width: 790px) {
    .poll-type-grid { gap: 7px; }
    .poll-type-card { padding: 12px 10px; }
    .poll-type-card i { font-size: 20px; margin-bottom: 5px; }
    .ptc-title { font-size: 12px; }
    .ptc-sub { font-size: 9.5px; }
    .poll-quiz-hint { font-size: 10px; }

    .vp-wrap, .qz-wrap { padding: 14px; border-radius: 12px; margin: 9px 0 5px; }
    .vp-hdr, .qz-hdr { margin-bottom: 10px; }
    .vp-badge, .qz-badge { font-size: 10.5px; gap: 5px; }
    .vp-timer, .qz-timer { font-size: 10px; }
    .vp-opts, .qz-opts { gap: 7px; }
    .vp-opt, .qz-opt { padding: 11px 13px; border-radius: 10px; gap: 7px; }
    .vp-opt-text, .qz-opt-text { font-size: 12.5px; }
    .vp-picked-icon { font-size: 14px; }
    .qz-icon-correct, .qz-icon-wrong { font-size: 15px; }
    .vp-pct, .qz-pct { font-size: 11.5px; min-width: 32px; }
    .vp-submit-btn, .qz-submit-btn { padding: 10px; font-size: 12px; border-radius: 10px; margin-top: 11px; }
    .vp-result-note, .qz-result-note { font-size: 10.5px; margin-top: 9px; }
}

/* ══════════════════════════════════════════════
   RESPONSIVE — 490px
   ══════════════════════════════════════════════ */
@media (max-width: 490px) {
    .poll-type-grid { gap: 6px; }
    .poll-type-card { padding: 10px 8px; }
    .poll-type-card i { font-size: 18px; margin-bottom: 4px; }
    .ptc-title { font-size: 11px; }
    .ptc-sub { font-size: 9px; }
    .correct-radio { width: 15px; height: 15px; }
    .poll-quiz-hint { font-size: 9.5px; gap: 5px; margin-top: 6px; }

    .vp-wrap, .qz-wrap { padding: 12px; border-radius: 11px; margin: 8px 0 5px; }
    .vp-hdr, .qz-hdr { margin-bottom: 9px; }
    .vp-badge, .qz-badge { font-size: 10px; gap: 5px; }
    .vp-timer, .qz-timer { font-size: 9.5px; }
    .vp-opts, .qz-opts { gap: 6px; }
    .vp-opt, .qz-opt { padding: 10px 12px; border-radius: 9px; gap: 6px; }
    .vp-opt-text, .qz-opt-text { font-size: 12px; }
    .vp-picked-icon { font-size: 13px; }
    .qz-icon-correct, .qz-icon-wrong { font-size: 14px; }
    .vp-pct, .qz-pct { font-size: 11px; min-width: 30px; }
    .vp-submit-btn, .qz-submit-btn { padding: 9px; font-size: 11.5px; border-radius: 9px; margin-top: 10px; }
    .vp-result-note, .qz-result-note { font-size: 10px; margin-top: 8px; }
}

/* ══════════════════════════════════════════════
   RESPONSIVE — 390px
   ══════════════════════════════════════════════ */
@media (max-width: 390px) {
    .poll-type-grid { gap: 5px; }
    .poll-type-card { padding: 9px 7px; }
    .poll-type-card i { font-size: 16px; margin-bottom: 3px; }
    .ptc-title { font-size: 10.5px; }
    .ptc-sub { font-size: 8.5px; }
    .correct-radio { width: 14px; height: 14px; }
    .poll-quiz-hint { font-size: 9px; margin-top: 5px; }

    .vp-wrap, .qz-wrap { padding: 11px; border-radius: 10px; margin: 7px 0 4px; }
    .vp-hdr, .qz-hdr { margin-bottom: 8px; }
    .vp-badge, .qz-badge { font-size: 9.5px; }
    .vp-timer, .qz-timer { font-size: 9px; }
    .vp-opts, .qz-opts { gap: 5px; }
    .vp-opt, .qz-opt { padding: 9px 11px; border-radius: 8px; gap: 5px; }
    .vp-opt-text, .qz-opt-text { font-size: 11.5px; }
    .vp-picked-icon { font-size: 12.5px; }
    .qz-icon-correct, .qz-icon-wrong { font-size: 13px; }
    .vp-pct, .qz-pct { font-size: 10.5px; min-width: 28px; }
    .vp-submit-btn, .qz-submit-btn { padding: 8px; font-size: 11px; border-radius: 8px; margin-top: 9px; }
    .vp-result-note, .qz-result-note { font-size: 9.5px; margin-top: 7px; }
}

/* ══════════════════════════════════════════════
   RESPONSIVE — 330px
   ══════════════════════════════════════════════ */
@media (max-width: 330px) {
    .poll-type-grid { gap: 5px; }
    .poll-type-card { padding: 8px 6px; }
    .poll-type-card i { font-size: 15px; margin-bottom: 3px; }
    .ptc-title { font-size: 10px; }
    .ptc-sub { display: none; }
    .correct-radio { width: 13px; height: 13px; }
    .poll-quiz-hint { font-size: 8.5px; gap: 4px; }

    .vp-wrap, .qz-wrap { padding: 9px; border-radius: 9px; margin: 6px 0 4px; }
    .vp-hdr, .qz-hdr { margin-bottom: 7px; }
    .vp-badge, .qz-badge { font-size: 9px; gap: 4px; }
    .vp-timer, .qz-timer { font-size: 8.5px; }
    .vp-opts, .qz-opts { gap: 5px; }
    .vp-opt, .qz-opt { padding: 8px 10px; border-radius: 7px; gap: 5px; }
    .vp-opt-text, .qz-opt-text { font-size: 11px; }
    .vp-picked-icon { font-size: 12px; }
    .qz-icon-correct, .qz-icon-wrong { font-size: 12px; }
    .vp-pct, .qz-pct { font-size: 10px; min-width: 26px; }
    .vp-submit-btn, .qz-submit-btn { padding: 7px; font-size: 10.5px; border-radius: 7px; margin-top: 8px; }
    .vp-result-note, .qz-result-note { font-size: 9px; margin-top: 6px; }
}

/* ══════════════════════════════════════════════
   COMMENTS — Premium Bottom Sheet
   ══════════════════════════════════════════════ */

#csBackdrop {
    position: fixed; inset: 0;
    background: linear-gradient(-45deg,#010218,#070924,#070924);
    z-index: 999997;
    opacity: 0; visibility: hidden;
}

.comments-panel {
    display: flex;
    flex-direction: column;
    position: fixed;
    left: 50%; bottom: 0;
    transform: translate(-50%, 100%);
    width: min(560px, 100vw);
    max-height: 84vh;
    background: linear-gradient(-45deg,#010218,#070924,#070924);
    border: 1px solid rgba(255,255,255,0.09);
    border-bottom: none;
    border-radius: 24px 24px 0 0;
    box-shadow:
        0 -24px 70px rgba(0, 0, 0, 0.65),
        0 0 0 1px rgba(255,255,255,0.02) inset,
        0 1px 0 rgba(255,255,255,0.06) inset;
    z-index: 999998;
    overflow: hidden;
    animation: none;
    transition: transform 0.45s cubic-bezier(.19,1,.22,1);
}
.comments-panel.open { transform: translate(-50%, 0); }
.tc { overflow: visible; } /* let the sheet escape the card */

.cs-handle-row {
    flex-shrink: 0;
    display: flex; justify-content: center;
    padding: 10px 0 4px;
    cursor: grab;
}
.cs-handle {
    width: 38px; height: 4px;
    border-radius: 99px;
    background: rgba(255,255,255,0.16);
    transition: 0.2s;
}
.cs-handle-row:hover .cs-handle { background: rgba(255,255,255,0.3); }

.cs-header {
    flex-shrink: 0;
    display: flex; align-items: center; justify-content: space-between;
    padding: 2px 20px 14px;
    border-bottom: 1px solid rgba(255,255,255,0.07);
}
.cs-title {
    font-family: var(--font-h); font-size: 15px; font-weight: 800; color: #fff;
    display: flex; align-items: baseline; gap: 5px;
}
.cs-title span { font-size: 12px; font-weight: 600; color: var(--text-3); }
.cs-close {
    width: 32px; height: 32px; border-radius: 10px;
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.08);
    color: var(--text-2);
    display: flex; align-items: center; justify-content: center;
    font-size: 17px; cursor: pointer; transition: 0.2s; flex-shrink: 0;
}
.cs-close:hover { background: var(--red-soft); border-color: var(--red-mid); color: var(--red); transform: rotate(90deg); }

/* ── replies list ── */
.cp-replies-list {
    flex: 1; min-height: 0;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
    touch-action: pan-y;
    padding: 16px 20px 10px;
    display: flex; flex-direction: column; gap: 16px;
    scrollbar-width: thin;
}
.cp-replies-list::-webkit-scrollbar { width: 5px; }
.cp-replies-list::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 99px; }
.cp-replies-list::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.2); }

.cp-empty-note { text-align: center; padding: 46px 16px; color: var(--text-3); }
.cp-empty-note i { font-size: 38px; display: block; margin-bottom: 10px; opacity: 0.3; }
.cp-empty-note p { font-size: 12px; }

/* ── reply item ── */
.reply-item { display: flex; gap: 11px; align-items: flex-start; }
.r-av {
    width: 32px; height: 32px; border-radius: 50%;
    flex-shrink: 0; cursor: pointer; text-decoration: none;
    display: block; overflow: hidden;
    padding: 2px;
    background: var(--red);
}
.r-av img { width: 100%; height: 100%; border-radius: 50%; object-fit: cover; display: block; border: 2px solid var(--red); }
.r-body { flex: 1; min-width: 0; }
.r-hdr { display: flex; align-items: center; gap: 8px; margin-bottom: 4px; flex-wrap: wrap; }
.r-uname { font-size: 12.5px; font-weight: 700; color: #fff; text-decoration: none; }
.r-uname:hover { color: var(--red); }
.r-time { font-size: 10px; color: var(--text-3); }
.r-time::before { content: '•'; margin-right: 8px; color: var(--text-3); }

.r-text {
    font-size: 13px; color: var(--text-2); line-height: 1.55;
    white-space: pre-wrap; word-break: break-word;
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.05);
    border-radius: 4px 14px 14px 14px;
    padding: 9px 13px;
    display: inline-block;
    max-width: 100%;
}
.r-media-img {
    max-width: 220px; border-radius: 14px; margin-top: 8px;
    display: block; border: 1px solid rgba(255,255,255,0.08);
}
.r-actions { display: flex; align-items: center; gap: 14px; margin-top: 6px; padding-left: 2px; flex-wrap: wrap; }
.r-act-btn {
    font-size: 11px; font-weight: 600; color: var(--text-3);
    background: none; border: none; cursor: pointer; font-family: var(--font);
    padding: 0; transition: 0.15s; display: flex; align-items: center; gap: 4px;
}
.r-act-btn:hover { color: var(--red); }
.r-del-btn { color: rgba(255,59,59,0.45); margin-left: auto; }
.r-del-btn:hover { color: var(--red); }
.nested-replies {
    margin-left: 42px; padding-left: 14px;
    border-left: 2px solid rgba(255,255,255,0.06);
    display: flex; flex-direction: column; gap: 14px; margin-top: 12px;
}

/* ── input area ── */
.cp-input-area {
    flex-shrink: 0;
    padding: 13px 20px calc(13px + env(safe-area-inset-bottom));
    border-top: 1px solid rgba(255,255,255,0.07);
    background: rgba(255,255,255,0.015);
}
.cp-input-lbl {
    font-size: 10.5px; font-weight: 700; color: var(--text-3);
    text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px;
    font-family: var(--font-h); display: flex; align-items: center; justify-content: space-between;
}
.cp-input-row { display: flex; gap: 9px; align-items: flex-end; }
.cp-input-av {
    width: 32px; height: 32px; border-radius: 50%; object-fit: cover;
    flex-shrink: 0; border: 2px solid var(--red);
}
.cp-textarea {
    flex: 1; min-width: 0;
    padding: 10px 15px; border-radius: 20px;
    border: 1px solid rgba(255,255,255,0.09);
    background: rgba(255,255,255,0.04);
    color: var(--text); font-family: var(--font); font-size: 13px;
    outline: none; resize: none; min-height: 40px; max-height: 100px;
    transition: 0.2s;
}
.cp-textarea:focus { border-color: var(--red-mid); background: rgba(255,255,255,0.06); box-shadow: 0 0 0 3px var(--red-soft); }
.cp-textarea::placeholder { color: var(--text-3); }
.cp-media-btn {
    width: 40px; height: 40px; border-radius: 12px;
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.09);
    color: var(--text-2); font-size: 16px; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; transition: 0.2s;
}
.cp-media-btn:hover { border-color: var(--border-2); color: #fff; }
.cp-media-btn input { display: none; }
.cp-send-btn {
    width: 40px; height: 40px; border-radius: 12px;
    background: linear-gradient(135deg, var(--red), #ff6b6b);
    border: none; color: #fff; font-size: 16px; cursor: pointer;
    flex-shrink: 0; display: flex; align-items: center; justify-content: center;
    transition: 0.2s; box-shadow: 0 4px 16px rgba(255,59,59,0.35);
}
.cp-send-btn:hover { transform: scale(1.08) rotate(8deg); box-shadow: 0 6px 20px rgba(255,59,59,0.5); }
.cp-send-btn:active { transform: scale(0.95); }

.cp-locked-notice, .cp-nocomm-notice {
    padding: 13px 20px calc(13px + env(safe-area-inset-bottom));
    font-size: 12.5px; color: var(--text-3);
    display: flex; align-items: center; gap: 8px;
    border-top: 1px solid rgba(255,255,255,0.07);
}

/* ── original post recap inside sheet (if you use it) ── */
.cp-orig-media { width: 100%; max-height: 260px; object-fit: contain; display: block; background: #000; }
.cp-orig-body {
    padding: 14px 20px; font-size: 13px; color: var(--text-2);
    line-height: 1.65; white-space: pre-wrap;
    border-bottom: 1px solid rgba(255,255,255,0.07);
}
.cp-poll { padding: 14px 20px; border-bottom: 1px solid rgba(255,255,255,0.07); }

/* ══════════════════════════════════════════════
   RESPONSIVE — 790px — becomes a BOTTOM SHEET
   Sharp top corners (no border-radius), anchored to bottom
   ══════════════════════════════════════════════ */
@media (max-width: 790px) {
    .comments-panel {
        left: 50%; top: auto; bottom: 0;
        transform: translate(-50%, 100%);
        width: 100vw;
        max-height: 88vh;
        border-radius: 0; /* sharp corners, no rounding at all on the sheet */
        border-bottom: none;
    }
    .comments-panel.open {
        transform: translate(-50%, 0);
    }

    .cs-handle-row {
        display: flex; /* drag handle only shows on the mobile bottom sheet */
        flex-shrink: 0;
        justify-content: center;
        padding: 10px 0 4px;
        cursor: grab;
    }

    .cs-header { padding: 2px 16px 12px; }
    .cs-title { font-size: 14px; }
    .cs-close { width: 30px; height: 30px; font-size: 15px; border-radius: 9px; }
    .cp-replies-list { padding: 14px 16px 8px; gap: 14px; }
    .r-av { width: 30px; height: 30px; }
    .r-text { font-size: 12.5px; padding: 8px 12px; }
    .r-media-img { max-width: 190px; }
    .nested-replies { margin-left: 36px; padding-left: 12px; gap: 12px; }
    .cp-input-area { padding: 12px 16px calc(12px + env(safe-area-inset-bottom)); }
    .cp-input-av { width: 30px; height: 30px; }
    .cp-textarea { font-size: 12.5px; min-height: 38px; }
    .cp-media-btn, .cp-send-btn { width: 38px; height: 38px; font-size: 15px; border-radius: 11px; }
}

/* ══════════════════════════════════════════════
   RESPONSIVE — 490px
   ══════════════════════════════════════════════ */
@media (max-width: 490px) {
    .comments-panel { max-height: 90vh; border-radius: 0; }
    .cs-handle-row { padding: 8px 0 3px; }
    .cs-handle { width: 34px; height: 3.5px; }
    .cs-header { padding: 2px 14px 10px; }
    .cs-title { font-size: 13px; }
    .cs-title span { font-size: 11px; }
    .cs-close { width: 28px; height: 28px; font-size: 14px; border-radius: 8px; }
    .cp-replies-list { padding: 12px 14px 6px; gap: 13px; }
    .reply-item { gap: 9px; }
    .r-av { width: 27px; height: 27px; padding: 1.5px; }
    .r-uname { font-size: 12px; }
    .r-time { font-size: 9.5px; }
    .r-text { font-size: 12px; padding: 7px 11px; border-radius: 4px 12px 12px 12px; }
    .r-media-img { max-width: 160px; border-radius: 12px; }
    .r-actions { gap: 11px; margin-top: 5px; }
    .r-act-btn { font-size: 10.5px; }
    .nested-replies { margin-left: 30px; padding-left: 10px; gap: 11px; margin-top: 10px; }
    .cp-input-area { padding: 10px 14px calc(10px + env(safe-area-inset-bottom)); }
    .cp-input-lbl { font-size: 10px; margin-bottom: 6px; }
    .cp-input-row { gap: 7px; }
    .cp-input-av { width: 27px; height: 27px; }
    .cp-textarea { padding: 8px 13px; font-size: 12px; min-height: 36px; }
    .cp-media-btn, .cp-send-btn { width: 34px; height: 34px; font-size: 14px; border-radius: 10px; }
    .cp-locked-notice, .cp-nocomm-notice { padding: 11px 14px calc(11px + env(safe-area-inset-bottom)); font-size: 11.5px; }
}

/* ══════════════════════════════════════════════
   RESPONSIVE — 390px
   ══════════════════════════════════════════════ */
@media (max-width: 390px) {
    .comments-panel { max-height: 92vh; border-radius: 0; }
    .cs-header { padding: 2px 12px 9px; }
    .cs-title { font-size: 12.5px; }
    .cs-close { width: 26px; height: 26px; font-size: 13px; border-radius: 8px; }
    .cp-replies-list { padding: 11px 12px 5px; gap: 12px; }
    .r-av { width: 25px; height: 25px; }
    .r-text { font-size: 11.5px; padding: 7px 10px; }
    .r-media-img { max-width: 140px; }
    .r-actions { gap: 9px; }
    .r-act-btn { font-size: 10px; }
    .nested-replies { margin-left: 24px; padding-left: 9px; gap: 10px; }
    .cp-input-area { padding: 9px 12px calc(9px + env(safe-area-inset-bottom)); }
    .cp-input-av { width: 25px; height: 25px; }
    .cp-textarea { font-size: 11.5px; min-height: 34px; padding: 7px 12px; }
    .cp-media-btn, .cp-send-btn { width: 32px; height: 32px; font-size: 13px; border-radius: 9px; }
}

/* ══════════════════════════════════════════════
   RESPONSIVE — 330px
   ══════════════════════════════════════════════ */
@media (max-width: 330px) {
    .comments-panel { max-height: 94vh; border-radius: 0; }
    .cs-handle { width: 30px; }
    .cs-header { padding: 2px 10px 8px; }
    .cs-title { font-size: 11.5px; }
    .cs-title span { display: none; }
    .cs-close { width: 24px; height: 24px; font-size: 12px; border-radius: 7px; }
    .cp-replies-list { padding: 9px 10px 4px; gap: 10px; }
    .r-av { width: 22px; height: 22px; padding: 1px; }
    .r-uname { font-size: 11px; }
    .r-text { font-size: 11px; padding: 6px 9px; border-radius: 3px 10px 10px 10px; }
    .r-media-img { max-width: 120px; }
    .r-act-btn span { display: none; } /* icon-only actions */
    .nested-replies { margin-left: 18px; padding-left: 7px; gap: 9px; }
    .cp-input-area { padding: 8px 10px calc(8px + env(safe-area-inset-bottom)); }
    .cp-input-av { width: 22px; height: 22px; }
    .cp-textarea { font-size: 11px; min-height: 32px; padding: 6px 11px; }
    .cp-media-btn, .cp-send-btn { width: 30px; height: 30px; font-size: 12px; border-radius: 8px; }
}

/* ══════ MEDIA GALLERY (multi-attachment) ══════ */
.tc-gallery { display: grid; gap: 4px; margin: 8px 0 4px; border-radius: var(--r-sm); overflow: hidden; width: 100%; }
.tc-g-item { position: relative; overflow: hidden; background: #000; cursor: pointer; }
.tc-g-item img, .tc-g-item video { width: 100%; height: 100%; object-fit: cover; display: block; }

.tc-gallery-1 { grid-template-columns: 1fr; height: 340px; }
.tc-gallery-1 .tc-g-item { height: 100%; }

.tc-gallery-2 { grid-template-columns: 1fr 1fr; height: 280px; }
.tc-gallery-2 .tc-g-item { height: 100%; }

.tc-gallery-3 { grid-template-columns: 1.2fr 1fr; grid-template-rows: 1fr 1fr; height: 280px; }
.tc-gallery-3 .tc-g-item:nth-child(1) { grid-row: 1 / 3; }
.tc-gallery-3 .tc-g-item { height: 100%; }

.tc-gallery-4 { grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr; height: 320px; }
.tc-gallery-4 .tc-g-item { height: 100%; }

.tc-g-vid-badge { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; pointer-events: none; }
.tc-g-vid-badge i { font-size: 32px; color: rgba(255,255,255,0.85); filter: drop-shadow(0 2px 6px rgba(0,0,0,0.6)); }
.tc-g-badge {
    position: absolute; top: 6px; left: 6px; font-size: 9px; font-weight: 800; padding: 2px 6px;
    border-radius: 4px; background: rgba(0,0,0,0.65); color: #fff; text-transform: uppercase; z-index: 2;
    letter-spacing: 0.4px;
}
.tc-g-more {
    position: absolute; inset: 0; background: rgba(0,0,0,0.62); color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; font-weight: 800; font-family: var(--font-h);
    backdrop-filter: blur(1px);
}

/* ══════ LIGHTBOX ══════ */
#lightbox { display: none; position: fixed; inset: 0; z-index: 99999999; background: rgba(0,0,0,0.95); align-items: center; justify-content: center; }
#lightbox.open { display: flex; }
.lb-stage { max-width: 92vw; max-height: 88vh; display: flex; align-items: center; justify-content: center; }
.lb-stage img, .lb-stage video { max-width: 92vw; max-height: 88vh; object-fit: contain; border-radius: 8px; }
.lb-close {
    position: absolute; top: 18px; right: 18px; width: 42px; height: 42px; border-radius: 50%;
    background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.14); color: #fff;
    font-size: 22px; display: flex; align-items: center; justify-content: center; cursor: pointer; z-index: 2; transition: 0.2s;
}
.lb-close:hover { background: rgba(255,255,255,0.16); }
.lb-nav {
    position: absolute; top: 50%; transform: translateY(-50%); width: 48px; height: 48px; border-radius: 50%;
    background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.14); color: #fff;
    font-size: 28px; display: flex; align-items: center; justify-content: center; cursor: pointer; z-index: 2; transition: 0.2s;
}
.lb-nav:hover { background: rgba(255,255,255,0.16); }
.lb-prev { left: 18px; }
.lb-next { right: 18px; }
.lb-counter {
    position: absolute; bottom: 20px; left: 50%; transform: translateX(-50%);
    font-size: 12px; font-weight: 700; color: rgba(255,255,255,0.7);
    background: rgba(255,255,255,0.08); padding: 5px 12px; border-radius: 20px; font-family: var(--font-h);
}

/* ══════════════════════════════════════════════
   RESPONSIVE — 790px
   ══════════════════════════════════════════════ */
@media (max-width: 790px) {
    .tc-gallery { gap: 3px; margin: 7px 0 4px; }
    .tc-gallery-1 { height: 300px; }
    .tc-gallery-2, .tc-gallery-3 { height: 240px; }
    .tc-gallery-4 { height: 260px; }
    .tc-g-vid-badge i { font-size: 28px; }
    .tc-g-badge { font-size: 8.5px; padding: 2px 5px; top: 5px; left: 5px; }
    .tc-g-more { font-size: 19px; }

    .lb-close { width: 38px; height: 38px; font-size: 19px; top: 14px; right: 14px; }
    .lb-nav { width: 44px; height: 44px; font-size: 25px; }
    .lb-prev { left: 12px; }
    .lb-next { right: 12px; }
    .lb-counter { font-size: 11px; padding: 4px 11px; bottom: 16px; }
}

/* ══════════════════════════════════════════════
   RESPONSIVE — 490px
   ══════════════════════════════════════════════ */
@media (max-width: 490px) {
    .tc-gallery { gap: 3px; margin: 6px 0 4px; border-radius: 8px; }
    .tc-gallery-1 { height: 250px; }
    .tc-gallery-2, .tc-gallery-3 { height: 200px; }
    .tc-gallery-4 { height: 220px; }
    .tc-g-vid-badge i { font-size: 24px; }
    .tc-g-badge { font-size: 8px; padding: 2px 5px; top: 5px; left: 5px; border-radius: 3px; }
    .tc-g-more { font-size: 17px; }

    .lb-close { width: 34px; height: 34px; font-size: 17px; top: 10px; right: 10px; }
    .lb-nav { width: 38px; height: 38px; font-size: 21px; }
    .lb-prev { left: 8px; }
    .lb-next { right: 8px; }
    .lb-counter { font-size: 10.5px; padding: 4px 10px; bottom: 12px; }
    .lb-stage img, .lb-stage video { max-width: 94vw; max-height: 82vh; border-radius: 6px; }
}

/* ══════════════════════════════════════════════
   RESPONSIVE — 390px
   ══════════════════════════════════════════════ */
@media (max-width: 390px) {
    .tc-gallery { gap: 2px; margin: 6px 0 3px; border-radius: 7px; }
    .tc-gallery-1 { height: 220px; }
    .tc-gallery-2, .tc-gallery-3 { height: 170px; }
    .tc-gallery-4 { height: 190px; }
    .tc-g-vid-badge i { font-size: 21px; }
    .tc-g-badge { font-size: 7.5px; padding: 1.5px 4px; top: 4px; left: 4px; }
    .tc-g-more { font-size: 15px; }

    .lb-close { width: 30px; height: 30px; font-size: 15px; top: 8px; right: 8px; }
    .lb-nav { width: 34px; height: 34px; font-size: 19px; }
    .lb-prev { left: 6px; }
    .lb-next { right: 6px; }
    .lb-counter { font-size: 10px; padding: 3px 9px; bottom: 10px; }
    .lb-stage img, .lb-stage video { max-width: 95vw; max-height: 78vh; border-radius: 5px; }
}

/* ══════════════════════════════════════════════
   RESPONSIVE — 330px
   ══════════════════════════════════════════════ */
@media (max-width: 330px) {
    .tc-gallery { gap: 2px; margin: 5px 0 3px; border-radius: 6px; }
    .tc-gallery-1 { height: 190px; }
    .tc-gallery-2, .tc-gallery-3 { height: 150px; }
    .tc-gallery-4 { height: 170px; }
    .tc-g-vid-badge i { font-size: 18px; }
    .tc-g-badge { font-size: 7px; padding: 1.5px 4px; top: 3px; left: 3px; }
    .tc-g-more { font-size: 13px; }

    .lb-close { width: 27px; height: 27px; font-size: 13px; top: 6px; right: 6px; }
    .lb-nav { width: 30px; height: 30px; font-size: 17px; }
    .lb-prev { left: 4px; }
    .lb-next { right: 4px; }
    .lb-counter { font-size: 9px; padding: 3px 8px; bottom: 8px; }
    .lb-stage img, .lb-stage video { max-width: 96vw; max-height: 74vh; border-radius: 4px; }
}


.tc-menu-btn {
    width: 32px; height: 32px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    background: rgba(255,255,255,0.05);
    border: 1px solid var(--border);
    color: var(--text-2);
    font-size: 16px;
    cursor: pointer;
    flex-shrink: 0;
    align-self: flex-start;
    transition: 0.2s;
    -webkit-tap-highlight-color: transparent;
}
.tc-menu-btn:hover { background: rgba(255,255,255,0.1); color: #fff; }

/* ── BOTTOM SHEET (identical to group.php's gfSheetPanel) ── */
.idx-sheet-overlay{
    position:fixed;inset:0;background:rgba(0,0,0,0.6);
    backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);
    z-index:99999998;opacity:0;pointer-events:none;
    transition:opacity 0.25s ease;
}
.idx-sheet-overlay.show{opacity:1;pointer-events:all;}
.idx-sheet-panel{
    position:fixed;left:0;right:0;bottom:0;
    background:rgba(12,12,28,0.98);
    backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);
    border-top:1px solid rgba(255,255,255,0.08);
    border-radius:20px 20px 0 0;
    z-index:99999999;max-height:80vh;
    display:flex;flex-direction:column;
    padding:8px 0 max(10px, env(safe-area-inset-bottom));
    transform:translateY(100%);
    transition:transform 0.3s cubic-bezier(0.32,0.72,0,1);
    box-shadow:0 -10px 40px rgba(0,0,0,0.5);
}
.idx-sheet-panel.show{transform:translateY(0);}
.idx-sheet-handle{
    width:34px;height:3.5px;border-radius:999px;
    background:rgba(255,255,255,0.2);
    margin:0 auto 4px;flex-shrink:0;
}
.idx-sheet-list{overflow-y:auto;flex:1;-webkit-overflow-scrolling:touch;}
.idx-sheet-item{
    display:flex;align-items:center;gap:11px;
    padding:9px 18px;color:#fff;text-decoration:none;
    font-size:13px;font-weight:500;cursor:pointer;
    -webkit-tap-highlight-color:transparent;
    transition:background 0.15s ease;
    border-bottom:1px solid rgba(255,255,255,0.06);
}
.idx-sheet-item:last-child{border-bottom:none;}
.idx-sheet-item:active{background:rgba(255,255,255,0.06);}
.idx-sheet-item i{font-size:15.5px;color:var(--red);width:18px;text-align:center;flex-shrink:0;}
.idx-sheet-item.danger{color:#ff4c4c;}
.idx-sheet-item.danger i{color:#ff4c4c;}
.idx-sheet-cancel{
    margin:6px 12px 0;padding:10px;
    background:rgba(255,255,255,0.06);
    border:none;border-radius:12px;
    color:rgba(255,255,255,0.7);
    font-weight:600;font-size:12.5px;
    cursor:pointer;flex-shrink:0;
    -webkit-tap-highlight-color:transparent;
}
.idx-sheet-cancel:active{background:rgba(255,255,255,0.1);}

@media (min-width:791px){
    .idx-sheet-panel{
        left:50%;right:auto;bottom:auto;top:50%;
        width:100%;max-width:320px;border-radius:18px;
        transform:translate(-50%,-40%) scale(0.95);
        opacity:0;pointer-events:none;max-height:none;
    }
    .idx-sheet-panel.show{
        transform:translate(-50%,-50%) scale(1);
        opacity:1;pointer-events:all;
    }
    .idx-sheet-handle{display:none;}
}

/* ── HIDDEN POST PLACEHOLDER (identical to group.php's gf-hidden-placeholder) ── */
.gf-hidden-placeholder {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    width: 100%;
    min-height: 56px;
    border-radius: 16px;
    background:#010723;
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    border:none;
    padding: 12px 14px 12px 18px;
    box-sizing: border-box;
    animation: gfHiddenFadeIn 0.3s ease;
}
@keyframes gfHiddenFadeIn {
    from { opacity: 0; transform: translateY(-4px); }
    to   { opacity: 1; transform: translateY(0); }
}
.gf-hidden-left {
    display: flex;
    align-items: center;
    gap: 9px;
    min-width: 0;
}
.gf-hidden-icon {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    background: rgba(255,45,45,0.1);
    border: 1.5px solid rgba(255,45,45,0.3);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    color: #ff5c5c;
    flex-shrink: 0;
}
.gf-hidden-text {
    font-size: 13px;
    font-weight: 600;
    color: rgba(255,255,255,0.75);
    font-family: var(--font-h);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.gf-undo-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: rgba(255,255,255,0.07);
    border: 1px solid rgba(255,255,255,0.15);
    color: #fff;
    font-size: 12px;
    font-weight: 700;
    padding: 7px 15px;
    border-radius: 8px;
    cursor: pointer;
    font-family: var(--font-h);
    transition: all 0.2s ease;
    -webkit-tap-highlight-color: transparent;
    flex-shrink: 0;
    white-space: nowrap;
}
.gf-undo-btn:hover {
    background: rgba(255,45,45,0.2);
    border-color: rgba(255,45,45,0.45);
    color: #ff8a8a;
    transform: scale(1.04);
}
.gf-undo-btn:active { transform: scale(0.96); }
.gf-undo-btn i { font-size: 13px; }

@media (max-width: 790px) {
    .tc-menu-btn{ width:30px; height:30px; font-size:15px; }
    .idx-sheet-panel{ border-radius:18px 18px 0 0; padding:7px 0 max(9px, env(safe-area-inset-bottom)); }
    .idx-sheet-handle{ width:32px; height:3.5px; margin:0 auto 4px; }
    .idx-sheet-item{ padding:8.5px 16px; font-size:12.5px; gap:10px; }
    .idx-sheet-item i{ font-size:15px; width:17px; }
    .idx-sheet-cancel{ margin:5px 10px 0; padding:9.5px; font-size:12px; }
    .gf-hidden-placeholder { min-height: 50px; border-radius: 14px; padding: 10px 12px 10px 15px; gap: 10px; }
    .gf-hidden-left { gap: 8px; }
    .gf-hidden-icon { width: 27px; height: 27px; font-size: 13px; }
    .gf-hidden-text { font-size: 12px; }
    .gf-undo-btn { font-size: 11px; padding: 6px 13px; gap: 4px; }
    .gf-undo-btn i { font-size: 12px; }
}
@media (max-width:490px){
    .tc-menu-btn{ width:28px; height:28px; font-size:14px; }
    .idx-sheet-panel{ border-radius:16px 16px 0 0; padding:6px 0 max(8px, env(safe-area-inset-bottom)); }
    .idx-sheet-handle{ width:30px; height:3px; margin:0 auto 3px; }
    .idx-sheet-item{ padding:8px 14px; font-size:12px; gap:9px; }
    .idx-sheet-item i{ font-size:14px; width:16px; }
    .idx-sheet-cancel{ margin:5px 9px 0; padding:9px; font-size:11.5px; border-radius:11px; }
    .gf-hidden-placeholder { min-height: 46px; border-radius: 13px; padding: 9px 10px 9px 13px; gap: 8px; }
    .gf-hidden-left { gap: 7px; }
    .gf-hidden-icon { width: 24px; height: 24px; font-size: 12px; }
    .gf-hidden-text { font-size: 11.5px; }
    .gf-undo-btn { font-size: 10.5px; padding: 5px 12px; border-radius: 8px; }
    .gf-undo-btn i { font-size: 11px; }
}
@media (max-width:390px){
    .tc-menu-btn{ width:26px; height:26px; font-size:13px; }
    .idx-sheet-panel{ border-radius:14px 14px 0 0; padding:6px 0 max(7px, env(safe-area-inset-bottom)); }
    .idx-sheet-handle{ width:28px; height:3px; margin:0 auto 3px; }
    .idx-sheet-item{ padding:7px 12px; font-size:11.5px; gap:8px; border-bottom:1px solid rgba(255,255,255,0.05); }
    .idx-sheet-item i{ font-size:13px; width:15px; }
    .idx-sheet-cancel{ margin:4px 8px 0; padding:8px; font-size:11px; border-radius:10px; }
    .gf-hidden-placeholder { min-height: 42px; border-radius: 12px; padding: 8px 9px 8px 11px; gap: 7px; }
    .gf-hidden-left { gap: 6px; }
    .gf-hidden-icon { width: 22px; height: 22px; font-size: 11px; }
    .gf-hidden-text { font-size: 11px; }
    .gf-undo-btn { font-size: 10px; padding: 5px 10px; border-radius: 8px; }
    .gf-undo-btn i { font-size: 10px; }
}
@media (max-width:330px){
    .tc-menu-btn{ width:24px; height:24px; font-size:12px; }
    .idx-sheet-panel{ border-radius:12px 12px 0 0; padding:5px 0 max(6px, env(safe-area-inset-bottom)); max-height:85vh; }
    .idx-sheet-handle{ width:26px; height:2.5px; margin:0 auto 2px; }
    .idx-sheet-item{ padding:6px 10px; font-size:11px; gap:7px; }
    .idx-sheet-item i{ font-size:12px; width:14px; }
    .idx-sheet-cancel{ margin:4px 7px 0; padding:7px; font-size:10.5px; border-radius:9px; }
    .gf-hidden-placeholder { min-height: 38px; border-radius: 10px; padding: 7px 8px 7px 9px; gap: 6px; }
    .gf-hidden-left { gap: 5px; }
    .gf-hidden-icon { width: 20px; height: 20px; font-size: 10px; }
    .gf-hidden-text { font-size: 10px; }
    .gf-undo-btn { font-size: 9px; padding: 4px 9px; border-radius: 8px; gap: 3px; }
    .gf-undo-btn i { font-size: 9px; }
}

/* ── REPORT MODAL (identical design to group.php's gfrpt-* modal) ── */
#dsRptOverlay, #dsRptStep2Screen {
    display: none;
    position: fixed; inset: 0;
    z-index: 999999999999;
    font-family: var(--font);
    background: linear-gradient(-45deg, #010218, #070924, #070924);
    overflow-y: scroll;
    overscroll-behavior: contain;
    opacity: 0;
    transform: translateY(18px);
    transition: opacity .25s ease, transform .28s cubic-bezier(.22,1,.36,1);
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
    scrollbar-color: rgba(255,255,255,.12) transparent;
}
#dsRptOverlay.gfrpt-open, #dsRptStep2Screen.gfrpt-open { opacity: 1; transform: translateY(0); }
#dsRptOverlay::-webkit-scrollbar, #dsRptStep2Screen::-webkit-scrollbar { width: 4px; }
#dsRptOverlay::-webkit-scrollbar-thumb, #dsRptStep2Screen::-webkit-scrollbar-thumb { background: rgba(255,255,255,.14); border-radius: 999px; }

.gfrpt-topbar {
    display:flex;align-items:center;justify-content:space-between;
    padding:15px 22px;border-bottom:1px solid rgba(255,255,255,.06);
    position:sticky;top:0;
    background:linear-gradient(-45deg,#010218,#070924);
    backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);
    z-index:3;
}
.gfrpt-topbar-title{font-size:15px;font-weight:700;color:#fff;letter-spacing:-.2px;}
.gfrpt-topbar-sub{font-size:10.5px;color:rgba(255,255,255,.32);margin-top:1px;}
.gfrpt-topbar-center{display:flex;flex-direction:column;}
.gfrpt-close-btn{
    width:30px;height:30px;border-radius:50%;
    background:rgba(255,255,255,.06);border:none;
    display:flex;align-items:center;justify-content:center;
    cursor:pointer;color:rgba(255,255,255,.45);font-size:17px;
    transition:background .18s,color .18s;
}
.gfrpt-close-btn:hover{background:rgba(255,45,45,.18);color:#ff6060;}
.gfrpt-topbar-spacer{width:30px;height:30px;flex-shrink:0;}

.gfrpt-section-label{
    font-size:9.5px;font-weight:700;letter-spacing:1.5px;
    text-transform:uppercase;color:rgba(255,255,255,.24);
    padding:20px 22px 8px;
}

.gfrpt-reason-row{
    display:flex;align-items:center;gap:14px;
    padding:14px 22px;border-bottom:1px solid rgba(255,255,255,.045);
    cursor:pointer;transition:background .18s;-webkit-tap-highlight-color:transparent;
}
.gfrpt-reason-row:hover{background:rgba(255,255,255,.035);}
.gfrpt-reason-row:active{background:rgba(255,255,255,.065);}
.gfrpt-reason-row.selected{background:rgba(255,45,45,.09);border-left:3px solid var(--red);}
.gfrpt-reason-icon{font-size:19px;color:var(--red);flex-shrink:0;width:24px;text-align:center;}
.gfrpt-reason-text{flex:1;font-size:13.5px;font-weight:500;color:rgba(255,255,255,.85);}
.gfrpt-reason-row.selected .gfrpt-reason-text{color:#fff;font-weight:600;}
.gfrpt-check{
    width:23px;height:23px;border-radius:50%;
    border:1.5px solid rgba(255,255,255,.2);
    display:flex;align-items:center;justify-content:center;
    font-size:12px;color:transparent;flex-shrink:0;transition:all .2s;
}
.gfrpt-reason-row.selected .gfrpt-check{background:var(--red);border-color:transparent;color:#fff;}

.gfrpt-other-wrap{padding:10px 22px 14px;display:none;}
.gfrpt-other-textarea{
    width:100%;min-height:100px;
    background:rgba(255,255,255,.045);
    border:1px solid rgba(255,255,255,.09);
    border-radius:13px;padding:13px 15px;
    color:#fff;font-size:13px;font-family:var(--font);
    resize:vertical;outline:none;box-sizing:border-box;
    transition:border-color .2s,background .2s;
}
.gfrpt-other-textarea::placeholder{color:rgba(255,255,255,.22);}
.gfrpt-other-textarea:focus{border-color:rgba(255,45,45,.4);background:rgba(255,45,45,.04);}
.gfrpt-char-count{text-align:right;font-size:10px;color:rgba(255,255,255,.25);margin-top:5px;}

.gfrpt-footer{padding:16px 22px 40px;}
.gfrpt-submit-btn{
    width:100%;padding:14px;border-radius:13px;
    background:var(--red);border:none;color:#fff;
    font-size:13.5px;font-weight:700;font-family:var(--font-h);
    cursor:pointer;letter-spacing:.3px;
    transition:background .18s,transform .15s,box-shadow .2s;
}
.gfrpt-submit-btn:hover{background:#ff5252;box-shadow:0 4px 20px rgba(255,45,45,.35);}
.gfrpt-submit-btn:active{transform:scale(.98);}
.gfrpt-submit-btn:disabled{opacity:.55;pointer-events:none;}
.gfrpt-cancel-btn{
    width:100%;padding:12px;margin-top:10px;border-radius:13px;
    background:rgba(255,255,255,.05);
    border:1px solid rgba(255,255,255,.09);
    color:rgba(255,255,255,.42);font-size:13px;font-weight:600;
    font-family:var(--font);cursor:pointer;transition:background .18s;
}
.gfrpt-cancel-btn:hover{background:rgba(255,255,255,.09);}

.gfrpt-success-body{
    display:flex;flex-direction:column;align-items:center;justify-content:center;
    min-height:calc(100vh - 70px);padding:40px 28px 60px;text-align:center;
}
.gfrpt-success-icon{
    width:86px;height:86px;border-radius:50%;
    background:rgba(77,222,138,.1);border:2px solid rgba(77,222,138,.3);
    display:flex;align-items:center;justify-content:center;
    margin:0 auto 24px;font-size:38px;color:#4dde8a;
}
.gfrpt-success-title{font-size:19px;font-weight:700;color:#fff;margin-bottom:12px;}
.gfrpt-success-desc{
    font-size:13.5px;color:rgba(255,255,255,.42);
    line-height:1.75;max-width:300px;margin:0 auto 36px;
}
.gfrpt-done-btn{
    padding:14px 48px;border-radius:50px;
    background:var(--red);border:none;color:#fff;
    font-size:14px;font-weight:700;font-family:var(--font-h);
    cursor:pointer;transition:background .18s;
}
.gfrpt-done-btn:hover{background:#ff5252;}

.gfrpt-toast{
    position:fixed;bottom:30px;left:50%;
    transform:translateX(-50%) translateY(10px);
    background:rgba(8,8,22,.97);
    border:1px solid rgba(255,45,45,.25);
    color:#fff;padding:9px 22px;border-radius:50px;
    font-size:12px;font-family:var(--font);font-weight:500;
    z-index:9999999999;pointer-events:none;
    box-shadow:0 8px 28px rgba(0,0,0,.65);
    white-space:nowrap;opacity:0;
    transition:opacity .22s ease,transform .22s ease;
}
.gfrpt-toast.show{opacity:1;transform:translateX(-50%) translateY(0);}

@keyframes gfRptShakeAnim{
    0%,100%{transform:translateX(0);}
    20%{transform:translateX(-6px);}
    40%{transform:translateX(6px);}
    60%{transform:translateX(-4px);}
    80%{transform:translateX(4px);}
}

@media (max-width:780px){
    .gfrpt-topbar{padding:13px 18px;}
    .gfrpt-topbar-title{font-size:14.5px;}
    .gfrpt-section-label{padding:18px 18px 7px;}
    .gfrpt-reason-row{padding:13px 18px;gap:13px;}
    .gfrpt-other-wrap{padding:9px 18px 12px;}
    .gfrpt-footer{padding:14px 18px 36px;}
}
@media (max-width:490px){
    .gfrpt-topbar{padding:11px 16px;}
    .gfrpt-topbar-title{font-size:13.5px;}
    .gfrpt-topbar-sub{font-size:10px;}
    .gfrpt-close-btn{width:27px;height:27px;font-size:15px;}
    .gfrpt-section-label{font-size:9px;letter-spacing:1.3px;padding:16px 16px 6px;}
    .gfrpt-reason-row{padding:12px 16px;gap:12px;}
    .gfrpt-reason-icon{font-size:17px;width:22px;}
    .gfrpt-reason-text{font-size:13px;}
    .gfrpt-other-wrap{padding:8px 16px 10px;}
    .gfrpt-other-textarea{font-size:12.5px;padding:12px 14px;border-radius:12px;min-height:90px;}
    .gfrpt-footer{padding:13px 16px 32px;}
    .gfrpt-submit-btn{padding:13px;font-size:13px;border-radius:12px;}
    .gfrpt-cancel-btn{padding:11px;font-size:12.5px;border-radius:12px;}
    .gfrpt-success-icon{width:76px;height:76px;font-size:32px;}
    .gfrpt-success-title{font-size:17px;}
    .gfrpt-success-desc{font-size:13px;}
    .gfrpt-done-btn{padding:13px 40px;font-size:13.5px;}
}
@media (max-width:380px){
    .gfrpt-topbar{padding:10px 14px;}
    .gfrpt-topbar-title{font-size:13px;}
    .gfrpt-close-btn{width:25px;height:25px;font-size:14px;}
    .gfrpt-section-label{font-size:8.5px;padding:14px 14px 5px;}
    .gfrpt-reason-row{padding:11px 14px;gap:11px;}
    .gfrpt-reason-icon{font-size:16px;width:20px;}
    .gfrpt-reason-text{font-size:12.5px;}
    .gfrpt-other-wrap{padding:7px 14px 10px;}
    .gfrpt-other-textarea{font-size:12px;padding:11px 13px;border-radius:11px;}
    .gfrpt-footer{padding:12px 14px 28px;}
    .gfrpt-submit-btn{padding:12px;font-size:12.5px;border-radius:11px;}
    .gfrpt-cancel-btn{padding:10px;font-size:12px;border-radius:11px;}
    .gfrpt-success-icon{width:70px;height:70px;font-size:28px;margin-bottom:18px;}
    .gfrpt-success-title{font-size:16px;}
    .gfrpt-success-desc{font-size:12.5px;}
    .gfrpt-done-btn{padding:12px 36px;font-size:13px;}
}
@media (max-width:330px){
    .gfrpt-topbar{padding:9px 12px;}
    .gfrpt-topbar-title{font-size:12.5px;}
    .gfrpt-close-btn{width:23px;height:23px;font-size:13px;}
    .gfrpt-section-label{font-size:8px;padding:12px 12px 4px;}
    .gfrpt-reason-row{padding:10px 12px;gap:10px;}
    .gfrpt-reason-icon{font-size:15px;width:18px;}
    .gfrpt-reason-text{font-size:12px;}
    .gfrpt-other-textarea{font-size:11.5px;padding:10px 12px;border-radius:10px;}
    .gfrpt-footer{padding:10px 12px 24px;}
    .gfrpt-submit-btn{padding:11px;font-size:12px;border-radius:10px;}
    .gfrpt-cancel-btn{padding:9px;font-size:11.5px;border-radius:10px;}
    .gfrpt-success-icon{width:64px;height:64px;font-size:26px;}
    .gfrpt-success-title{font-size:15px;}
    .gfrpt-success-desc{font-size:12px;}
}
</style>
</head>
<body>
<div id="csBackdrop" onclick="closeComments()"></div>
<!-- FULL PAGE SKELETON LOADER -->
<div id="dpSkeleton">
    <div class="dp-shell">
        <div class="dp-side dp-left">
            <div class="dp-widget dp-shimmer"></div>
            <div class="dp-widget dp-shimmer"></div>
        </div>
        <div class="dp-main">
            <div class="dp-banner dp-shimmer"></div>
            <div class="dp-title dp-shimmer"></div>
            <div class="dp-strip dp-shimmer"></div>
            <div class="dp-toolbar dp-shimmer"></div>
            <div class="dp-card dp-shimmer"></div>
            <div class="dp-card dp-shimmer"></div>
            <div class="dp-card dp-shimmer"></div>
            <div class="dp-card dp-shimmer"></div>
        </div>
        <div class="dp-side dp-right">
            <div class="dp-widget dp-shimmer"></div>
            <div class="dp-widget dp-shimmer"></div>
        </div>
    </div>
</div>
<script>
(function () {
    var sk = document.getElementById('dpSkeleton');
    function hideSkeleton() {
        if (!sk) return;
        sk.classList.add('dp-fade-out');
        setTimeout(function () { sk.style.display = 'none'; }, 360);
    }
    if (document.readyState === 'complete') {
        hideSkeleton();
    } else {
        window.addEventListener('load', hideSkeleton);
        setTimeout(hideSkeleton, 3000);
    }
})();
</script>

<div class="page-shell">

<!-- ══ LEFT SIDEBAR ══ -->
<aside class="sidebar-left">

    <!-- Pod stats -->
    <div class="sb-widget">
        <div class="sb-widget-hdr"><i class="ri-bar-chart-box-line"></i> Pod Stats</div>
        <div class="sb-widget-body">
            <div class="sb-stat">
                <span class="sb-stat-lbl"><i class="ri-team-line"></i> Members</span>
                <span class="sb-stat-val"><?= number_format($member_count) ?></span>
            </div>
            <div class="sb-stat">
                <span class="sb-stat-lbl"><i class="ri-discuss-line"></i> Threads</span>
                <span class="sb-stat-val"><?= count($threads) ?></span>
            </div>
            <div class="sb-stat">
                <span class="sb-stat-lbl"><i class="ri-user-voice-line"></i> Online</span>
                <span class="sb-stat-val" style="color:var(--green);"><?= $active_count ?></span>
            </div>
            <?php
            $total_replies = 0;
            foreach ($threads as $titem) $total_replies += (int)$titem['actual_replies'];
            ?>
            <div class="sb-stat">
                <span class="sb-stat-lbl"><i class="ri-chat-3-line"></i> Replies</span>
                <span class="sb-stat-val"><?= number_format($total_replies) ?></span>
            </div>
        </div>
    </div>

    <!-- Active now -->
    <?php if ($active_count > 0): ?>
    <div class="sb-widget">
        <div class="sb-widget-hdr"><i class="ri-pulse-line"></i> Active Now</div>
        <div class="sb-widget-body">
            <?php foreach ($active_users as $au):
                $apic = !empty($au['profile_pic']) ? $au['profile_pic'] : 'uploads/me.jpeg';
            ?>
            <a href="profile.php?user_id=<?= $au['id'] ?>" class="sb-member">
                <img src="<?= $apic ?>" onerror="this.src='uploads/me.jpeg'">
                <span class="sb-member-name">@<?= htmlspecialchars($au['username']) ?></span>
                <span class="sb-online-dot"></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Tags breakdown -->
    <?php
    $tag_counts = [];
    foreach ($threads as $titem) {
        $tg = $titem['tag'] ?? 'Discussion';
        $tag_counts[$tg] = ($tag_counts[$tg] ?? 0) + 1;
    }
    arsort($tag_counts);
    $tag_colors = [
        'Discussion'=>['bg'=>'rgba(255,59,59,0.11)','color'=>'#ff7a7a'],
        'Question'=>['bg'=>'rgba(74,168,255,0.11)','color'=>'#6fbcff'],
        'Announcement'=>['bg'=>'rgba(244,197,66,0.11)','color'=>'#f4c542'],
        'Suggestion'=>['bg'=>'rgba(157,140,255,0.13)','color'=>'#b0a3ff'],
        'Poll'=>['bg'=>'rgba(61,220,132,0.11)','color'=>'#3ddc84'],
        'Help'=>['bg'=>'rgba(255,165,64,0.12)','color'=>'#ffab40'],
        'Meme Talk'=>['bg'=>'rgba(255,59,59,0.09)','color'=>'#ff9c9c'],
        'News'=>['bg'=>'rgba(74,168,255,0.11)','color'=>'#4ab6ff'],
    ];
    if (!empty($tag_counts)):
    ?>
    <div class="sb-widget">
        <div class="sb-widget-hdr"><i class="ri-price-tag-3-line"></i> Topics</div>
        <div class="sb-widget-body">
            <?php foreach ($tag_counts as $tg => $cnt):
                $tc = $tag_colors[$tg] ?? ['bg'=>'rgba(255,255,255,0.06)','color'=>'rgba(255,255,255,0.6)'];
                $sort_link = '?pod_id='.$group_id.'&q='.urlencode($tg);
            ?>
            <div class="sb-tag-row" onclick="location.href='<?= $sort_link ?>'">
                <span class="sb-tag-pill" style="background:<?= $tc['bg'] ?>;color:<?= $tc['color'] ?>"><?= htmlspecialchars($tg) ?></span>
                <span class="sb-tag-count"><?= $cnt ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</aside>

<!-- ══ CENTRE ══ -->
<div class="main">

<!-- GROUP BANNER — mirrors group.php -->
<div class="db-banner-container">
    <a href="group.php?id=<?= $group_id ?>" class="db-back-btn" aria-label="Back to Pod">
        <i class="ri-arrow-left-s-line"></i>
    </a>

    <?php if ($is_member): ?>
<button type="button" class="db-add-btn" onclick="openCreateModal()" aria-label="New Discussion">
    <i class="ri-add-line"></i>
</button>
<?php endif; ?>

    <img class="db-banner" src="<?= htmlspecialchars($disc_banner_src) ?>"
         onerror="this.src='uploads/default_group.png';" alt="Pod banner">
    <div class="db-banner-overlay"></div>
    <div class="db-pic-wrap">
        <img class="db-pic" src="<?= htmlspecialchars($disc_img_src) ?>"
             onerror="this.src='uploads/default_group.png';" alt="Pod picture">
    </div>
    <div class="db-title-overlay">
        <div class="db-name"><?= htmlspecialchars($group['title']) ?></div>
        <div class="db-sub"><i class="ri-team-line"></i> <?= number_format($member_count) ?> members</div>
    </div>
</div>

<!-- ══ STICKY TOPBAR — appears once you scroll past the banner ══ -->
<div class="disc-sticky-wrap" id="discStickyWrap">
    <div class="disc-sticky-topbar">
        <a href="group.php?id=<?= $group_id ?>" class="dst-back" aria-label="Back">
            <i class="ri-arrow-left-s-line"></i>
        </a>

        <img class="dst-pic" src="<?= htmlspecialchars($disc_img_src) ?>"
             onerror="this.src='uploads/default_group.png';" alt="">

        <div class="dst-title-wrap">
            <div class="dst-title"><?= htmlspecialchars($group['title']) ?></div>
            <div class="dst-subtitle">Discussions · <?= number_format($member_count) ?> member<?= $member_count == 1 ? '' : 's' ?></div>
        </div>

        <?php if ($is_member): ?>
        <button type="button" class="dst-add" onclick="openCreateModal()" aria-label="New Discussion">
            <i class="ri-add-line"></i>
        </button>
        <?php endif; ?>
    </div>
</div>

<style>
/* ══════════════════════════════════════════════
   STICKY TOPBAR — aligned to .main column only (JS-positioned)
   ══════════════════════════════════════════════ */
.disc-sticky-wrap {
    position: fixed;
    top: 0;
    z-index: 999999;
    transform: translateY(-100%);
    opacity: 0;
    transition: transform 0.28s cubic-bezier(0.22,1,0.36,1), opacity 0.28s ease;
    pointer-events: none;
}
.disc-sticky-wrap.visible {
    transform: translateY(0);
    opacity: 1;
    pointer-events: all;
}

.disc-sticky-topbar {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 16px;
    padding-top: calc(env(safe-area-inset-top) + 10px);
    background: linear-gradient(-45deg,#070924,#070924);
}

.dst-back {
    width: 37px; height: 37px;
    display: flex; align-items: center; justify-content: center;
    border-radius: 50%;
    background: rgba(255,255,255,0.08);
    border: none;
    color: #fff;
    text-decoration: none;
    font-size: 29px;
    line-height: 1;
    cursor: pointer;
    transition: 0.2s;
    flex-shrink: 0;
    margin-left: -8px;
    -webkit-tap-highlight-color: transparent;
}
.dst-back:hover { color: var(--red); transform: translateX(-2px); }
.dst-back:active { transform: translateX(-1px) scale(0.92); }

.dst-pic {
    width: 36px; height: 36px;
    border-radius: 9px;
    object-fit: cover;
    border: 3px solid #0a3f8d;
    flex-shrink: 0;
    display: block;
}

.dst-title-wrap {
    flex: 1;
    min-width: 0;
}
.dst-title {
    font-family: var(--font-h);
    font-size: 14px;
    font-weight: 700;
    color: #fff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    line-height: 1.2;
}
.dst-subtitle {
    font-size: 10.5px;
    color: var(--text-3);
    margin-top: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.dst-add {
    width: 38px; height: 38px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    background: rgba(255,255,255,0.08);
    border: none;
    color: #fff;
    font-size: 20px;
    cursor: pointer;
    transition: 0.2s;
    flex-shrink: 0;
    -webkit-tap-highlight-color: transparent;
}
.dst-add:hover { background: rgba(255,255,255,0.14); }
.dst-add:active { transform: scale(0.92); }

@media (max-width: 490px) {
    .disc-sticky-topbar { padding: 9px 13px; gap: 10px; }
    .dst-back { width: 36px; height: 36px; font-size: 26px; margin-left: -6px; }
    .dst-pic { width: 31px; height: 31px; border-width: 1.5px; }
    .dst-title { font-size: 13.5px; }
    .dst-subtitle { font-size: 10.5px; }
    .dst-add { width: 36px; height: 36px; font-size: 19px; }
}
@media (max-width: 380px) {
    .disc-sticky-topbar { padding: 8px 11px; gap: 9px; }
    .dst-back { width: 30px; height: 30px; font-size: 23px; margin-left: -5px; }
    .dst-pic { width: 30px; height: 30px; }
    .dst-title { font-size: 13px; }
    .dst-subtitle { font-size: 10px; }
    .dst-add { width: 32px; height: 32px; font-size: 17px; }
}

/* ══════════════════════════════════════════════
   "FROM ZEEM" SOURCE LINK — shown on discussion cards
   created via the "Discuss" button on a group post
   ══════════════════════════════════════════════ */
.tc-source-zeem {
    display: flex; align-items: center; gap: 6px;
    font-size: 11px; color: var(--text-3);
    margin: 2px 0 8px;
    flex-wrap: wrap;
}
.tc-source-zeem i.ri-image-2-line { font-size: 12px; color: var(--text-3); }
.tc-source-dot { color: var(--text-3); }
.tc-source-link {
    display: inline-flex; align-items: center; gap: 3px;
    color: var(--red); font-weight: 700;
    text-decoration: none; transition: 0.15s;
}
.tc-source-link:hover { text-decoration: underline; opacity: 0.85; }
.tc-source-link i { font-size: 12px; }

@media (max-width: 490px) {
    .tc-source-zeem { font-size: 10px; margin: 2px 0 6px; }
}

</style>

<script>
(function () {
    var wrap     = document.getElementById('discStickyWrap');
    var bannerEl = document.querySelector('.db-banner-container');
    var mainEl   = document.querySelector('.main');
    if (!wrap || !bannerEl || !mainEl) return;

    var DESKTOP_BREAKPOINT = 790;
    var EXTRA_WIDTH = 40;

    function syncWidth() {
        if (window.innerWidth <= DESKTOP_BREAKPOINT) {
            wrap.style.left  = '0px';
            wrap.style.width = '100%';
        } else {
            var rect = mainEl.getBoundingClientRect();
            var half = EXTRA_WIDTH / 2;
            wrap.style.left  = (rect.left - half) + 'px';
            wrap.style.width = (rect.width + EXTRA_WIDTH) + 'px';
        }
    }
    syncWidth();
    window.addEventListener('resize', syncWidth);

    var threshold = 0;
    function updateThreshold() {
        threshold = bannerEl.offsetHeight - 40;
    }
    updateThreshold();
    window.addEventListener('resize', updateThreshold);

    var lastState = false;
    function onScroll() {
        var show = window.scrollY > threshold;
        if (show !== lastState) {
            wrap.classList.toggle('visible', show);
            lastState = show;
        }
        syncWidth();
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
})();
</script>


<!-- PAGE TOP -->
<div class="page-top">
    <div class="page-title-wrap">
        <div class="page-title"><?= htmlspecialchars($group['title']) ?> <span>Discussions</span></div>
        <div class="page-sub">Community Forum</div>
    </div>
</div>

<?php include 'group_more_members_strip.php'; ?>

<!-- STATS STRIP (mobile only context) -->
<div class="stats-strip">
    <div class="s-chip"><i class="ri-team-line" style="color:var(--blue);"></i><strong><?= number_format($member_count) ?></strong> Members</div>
    <div class="s-div"></div>
    <div class="s-chip"><i class="ri-discuss-line" style="color:var(--red);"></i><strong><?= count($threads) ?></strong> Threads</div>
    <div class="s-div"></div>
    <?php if ($active_count > 0): ?>
    <div class="active-row">
        <div class="active-pill"><span class="a-dot"></span><?= $active_count ?> Active</div>
        <?php foreach ($active_users as $au):
            $apic = !empty($au['profile_pic']) ? $au['profile_pic'] : 'uploads/me.jpeg';
        ?>
        <a href="profile.php?user_id=<?= $au['id'] ?>" title="@<?= htmlspecialchars($au['username']) ?>">
            <img src="<?= $apic ?>" class="active-av" onerror="this.src='uploads/me.jpeg'">
        </a>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <span class="inactive-pill"><i class="ri-zzz-line"></i> Quiet right now</span>
    <?php endif; ?>
</div>


<!-- YOU MODAL -->
<div id="youModal" onclick="if(event.target===this)closeYouModal()">
<div class="you-inner" onclick="event.stopPropagation()">

    <div class="you-hdr">
        <div class="you-hdr-left">
            <img src="<?= htmlspecialchars($me_pic) ?>" class="you-hdr-av" onerror="this.src='uploads/me.jpeg'">
            <div>
                <div class="you-hdr-title">Your Posts</div>
                <div class="you-hdr-sub"><?= count($my_threads) ?> discussion<?= count($my_threads) != 1 ? 's' : '' ?> in this Pod</div>
            </div>
        </div>
        <button class="you-close" onclick="closeYouModal()"><i class="ri-close-line"></i></button>
    </div>

    <div class="you-sort-row">
        <button class="you-sort-btn active" data-sort="newest" onclick="sortYouPosts('newest', this)"><i class="ri-time-line"></i> Newest</button>
        <button class="you-sort-btn" data-sort="oldest" onclick="sortYouPosts('oldest', this)"><i class="ri-history-line"></i> Oldest</button>
        <button class="you-sort-btn" data-sort="popular" onclick="sortYouPosts('popular', this)"><i class="ri-fire-line"></i> Most Popular</button>
    </div>

    <div class="you-list" id="youList">
    <?php if (empty($my_threads)): ?>
        <div class="you-empty">
            <i class="ri-quill-pen-line"></i>
            <p>You haven't posted any discussions in this Pod yet.</p>
        </div>
    <?php else: foreach ($my_threads as $mt):
        $pop      = (int)$mt['like_count'] + (int)$mt['reply_count'];
        $tag_cls2 = 't-' . str_replace(' ', '', $mt['tag']);
        $ts       = strtotime($mt['created_at']);
    ?>
    <div class="you-item" data-ts="<?= $ts ?>" data-pop="<?= $pop ?>">
        <div class="you-item-main" onclick="location.href='discussions.php?pod_id=<?= $group_id ?>&open=<?= $mt['id'] ?>'">
            <div class="you-item-top">
                <span class="tag <?= $tag_cls2 ?>"><?= htmlspecialchars($mt['tag']) ?></span>
                <?php if ($mt['is_pinned']): ?><i class="ri-pushpin-fill pin-ico" title="Pinned"></i><?php endif; ?>
                <?php if ($mt['is_locked']): ?><i class="ri-lock-fill lock-ico" title="Locked"></i><?php endif; ?>
            </div>
            <div class="you-item-title"><?= htmlspecialchars($mt['title']) ?></div>
            <?php if ($mt['body']): ?><div class="you-item-body"><?= htmlspecialchars(mb_strimwidth($mt['body'], 0, 140, '…')) ?></div><?php endif; ?>
            <div class="you-item-stats">
                <span><i class="ri-heart-line"></i> <?= (int)$mt['like_count'] ?></span>
                <span><i class="ri-chat-3-line"></i> <?= (int)$mt['reply_count'] ?></span>
                <span><i class="ri-eye-line"></i> <?= (int)$mt['view_count'] ?></span>
                <span class="you-item-date"><?= date('M j, Y', $ts) ?></span>
            </div>
        </div>
        <form method="POST" class="you-item-del" onclick="event.stopPropagation()" onsubmit="return confirm('Delete this discussion?')">
            <input type="hidden" name="del_thread_id" value="<?= $mt['id'] ?>">
            <button type="submit" name="delete_thread" class="you-del-btn" title="Delete"><i class="ri-delete-bin-line"></i></button>
        </form>
    </div>
    <?php endforeach; endif; ?>
    </div>

</div>
</div>

<!-- SORT BUTTONS -->
<div class="sort-btn-row">
    <button class="you-btn" onclick="openYouModal()">
        <img src="<?= htmlspecialchars($me_pic) ?>" class="you-btn-av" onerror="this.src='uploads/me.jpeg'">
        <span>You</span>
        <?php if (!empty($my_threads)): ?><span class="you-badge"><?= count($my_threads) ?></span><?php endif; ?>
    </button>
    <?php
    $sort_opts = [
        'newest'  => ['label' => 'Newest',         'icon' => 'ri-time-line'],
        'replies' => ['label' => 'Most Replies',   'icon' => 'ri-chat-3-line'],
        'views'   => ['label' => 'Most Viewed',    'icon' => 'ri-eye-line'],
        'active'  => ['label' => 'Recently Active','icon' => 'ri-flashlight-line'],
    ];
    foreach ($sort_opts as $sval => $sinfo):
        $is_active = ($sort === $sval);
        $link = '?pod_id=' . $group_id . ($search ? '&q=' . urlencode($search) : '') . '&sort=' . $sval;
    ?>
    <a href="<?= $link ?>" class="sort-btn <?= $is_active ? 'active' : '' ?>">
        <i class="<?= $sinfo['icon'] ?>"></i> <?= $sinfo['label'] ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- TOOLBAR -->
<div class="toolbar">
    <form method="GET" action="discussions.php" style="display:contents;">
        <input type="hidden" name="pod_id" value="<?= $group_id ?>">
        <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
        <div class="search-box">
            <i class="ri-search-line"></i>
            <input type="text" name="q" placeholder="Search discussions…" value="<?= htmlspecialchars($search) ?>">
        </div>
    </form>
</div>

<!-- SKELETON -->
<div class="skel-wrap" id="skelWrap">
    <?php for ($s = 0; $s < 4; $s++): ?>
    <div class="skel-card">
        <div class="skel skel-av"></div>
        <div class="skel-lines">
            <div class="skel skel-s"></div><div class="skel skel-t"></div>
            <div class="skel skel-b"></div><div class="skel skel-m"></div>
        </div>
    </div>
    <?php endfor; ?>
</div>

<!-- THREADS -->
<div class="threads" id="threadsList" style="display:none;">

<?php if (empty($threads)): ?>
<div class="empty-state">
    <i class="ri-chat-4-line"></i>
    <p>No discussions yet<?= $search ? ' matching your search' : '' ?>.</p>
    <?php if ($is_member): ?><p style="margin-top:6px;font-size:11.5px;color:var(--text-3);">Be the first to start a conversation.</p><?php endif; ?>
</div>

<?php else: foreach ($threads as $t):
    $tid       = (int)$t['id'];
    $is_open   = ($open === $tid);
    $tpic      = !empty($t['profile_pic']) ? $t['profile_pic'] : 'uploads/me.jpeg';
    $tag_cls   = 't-' . str_replace(' ', '', $t['tag']);
    $liked     = ($t['user_like_type'] === 'like');
    $disliked  = ($t['user_like_type'] === 'dislike');
    $can_del   = ($is_mod || (int)$t['user_id'] === (int)$current_user);
    $reply_cnt = (int)$t['actual_replies'];
?>
<div class="tc <?= $t['is_pinned']?'pinned':'' ?> <?= $is_open?'is-open':'' ?>" id="tc-<?= $tid ?>">

    <!-- HEADER: avatar + name + meta -->
    <div class="tc-hdr-row">
        <a href="profile.php?user_id=<?= $t['user_id'] ?>" class="tc-av-link">
            <img src="<?= $tpic ?>" class="tc-av" onerror="this.src='uploads/me.jpeg'">
        </a>
        <div class="tc-hdr-info">
            <a href="profile.php?user_id=<?= $t['user_id'] ?>" class="tc-uname"><?= htmlspecialchars($t['username']) ?></a>
            <div class="tc-hdr-meta">
                <span class="tag <?= $tag_cls ?>"><?= htmlspecialchars($t['tag']) ?></span>
                <?php if ($t['is_pinned']): ?><i class="ri-pushpin-fill pin-ico" title="Pinned"></i><?php endif; ?>
                <?php if ($t['is_locked']): ?><i class="ri-lock-fill lock-ico" title="Locked"></i><?php endif; ?>
                <?php if ($t['comments_disabled']): ?><i class="ri-chat-off-line nocomm-ico" title="Comments off"></i><?php endif; ?>
                <span class="tc-time">· <?= date('M j', strtotime($t['created_at'])) ?></span>
            </div>
        </div>
        <button type="button" class="tc-menu-btn"
            data-id="<?= $tid ?>"
            data-title="<?= htmlspecialchars($t['title'], ENT_QUOTES) ?>"
            data-body="<?= htmlspecialchars($t['body'], ENT_QUOTES) ?>"
            data-user-id="<?= (int)$t['user_id'] ?>"
            data-username="<?= htmlspecialchars($t['username'], ENT_QUOTES) ?>"
            data-following="<?= !empty($_disc_follow_map[(int)$t['user_id']]) ? '1' : '0' ?>"
            <?php if (!empty($media_data[$tid])): $fm = $media_data[$tid][0]; ?>
            data-media="uploads/<?= htmlspecialchars($fm['file'], ENT_QUOTES) ?>"
            data-media-type="<?= htmlspecialchars($fm['type'], ENT_QUOTES) ?>"
            <?php endif; ?>
            onclick="event.stopPropagation();openDsSheet(this)" aria-label="More options">
            <i class="ri-menu-line"></i>
        </button>
    </div>

    <!-- CONTENT: title, full body, media, poll -->
    <div class="tc-content">

        <?php if ($t['title']): ?>
        <div class="tc-title"><?= htmlspecialchars($t['title']) ?></div>
        <?php endif; ?>

        <?php if (!empty($t['source_meme_id'])): ?>
        <div class="tc-source-zeem">
            <i class="ri-image-2-line"></i>
            <span>From Zeem</span>
            <span class="tc-source-dot">·</span>
            <a href="group.php?id=<?= $group_id ?>&meme=<?= (int)$t['source_meme_id'] ?>"
               class="tc-source-link" onclick="event.stopPropagation()">
                View Zeem <i class="ri-arrow-right-up-line"></i>
            </a>
        </div>
        <?php endif; ?>

        <?php if ($t['body']): ?>
        <div class="tc-full-body">
            <div class="tc-body-text clamped" id="tcbodytext-<?= $tid ?>"><?= nl2br(htmlspecialchars($t['body'])) ?></div>
            <button class="tc-readmore-btn" id="tcreadmore-<?= $tid ?>" onclick="toggleReadMore(<?= $tid ?>)" style="display:none;">…Read more</button>
        </div>
        <?php endif; ?>

        <?php if (!empty($media_data[$tid])):
            $gallery = $media_data[$tid];
            $gcount  = count($gallery);
            $grid_n  = min($gcount, 4);
            $gallery_json = htmlspecialchars(json_encode(array_map(function($m) {
                return ['file' => $m['file'], 'type' => $m['type']];
            }, $gallery)), ENT_QUOTES);
        ?>
        <div class="tc-gallery tc-gallery-<?= $grid_n ?>" data-thread-id="<?= $tid ?>" data-gallery='<?= $gallery_json ?>'>
            <?php foreach ($gallery as $gi => $m):
                if ($gi >= 4) break;
                $gsrc = 'uploads/' . htmlspecialchars($m['file']);
                $show_more = ($gi === 3 && $gcount > 4);
            ?>
            <div class="tc-g-item" onclick="openLightbox(this, <?= $gi ?>)">
                <?php if ($m['type'] === 'video'): ?>
                    <video src="<?= $gsrc ?>" muted playsinline preload="metadata"></video>
                    <div class="tc-g-vid-badge"><i class="ri-play-fill"></i></div>
                <?php else: ?>
                    <img src="<?= $gsrc ?>" loading="lazy">
                <?php endif; ?>
                <?php if ($m['type'] === 'gif'): ?><span class="tc-g-badge">GIF</span><?php endif; ?>
                <?php if ($show_more): ?><div class="tc-g-more">+<?= $gcount - 4 ?></div><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($t['tag'] === 'Poll' && !empty($poll_data[$tid])):
    $opts       = $poll_data[$tid];
    $total_v    = array_sum(array_column($opts, 'votes'));
    $voted_row  = $conn->query("SELECT option_id FROM pod_poll_votes WHERE discussion_id=$tid AND user_id=$current_user")->fetch_assoc();
    $voted_opt  = $voted_row ? $voted_row['option_id'] : null;
    $ptype      = $t['poll_type'] ?? 'vote';
    $expires_at = $t['poll_expires_at'] ?? null;
    $is_expired = $expires_at && strtotime($expires_at) <= time();
    $show_result = $voted_opt || $is_expired;
?>

<?php if ($ptype === 'quiz'): ?>
<!-- QUIZ POLL -->
<div class="qz-wrap">
    <div class="qz-hdr">
        <div class="qz-badge"><i class="ri-trophy-fill"></i> Quiz</div>
        <div class="qz-timer" data-expires="<?= $expires_at ? strtotime($expires_at) : '' ?>" data-forever="<?= $expires_at ? '0' : '1' ?>">
            <?= $expires_at ? ($is_expired ? 'Ended' : '') : '<i class="ri-infinity-line"></i> No time limit' ?>
        </div>
    </div>

    <?php if (!$voted_opt && !$is_expired && $is_member): ?>
    <form method="POST">
        <input type="hidden" name="discussion_id" value="<?= $tid ?>">
        <div class="qz-opts">
        <?php foreach ($opts as $opt): ?>
            <label class="qz-opt">
                <input type="radio" name="option_id" value="<?= $opt['id'] ?>" required>
                <span class="qz-opt-text"><?= htmlspecialchars($opt['option_text']) ?></span>
            </label>
        <?php endforeach; ?>
        </div>
        <button type="submit" name="vote_poll" class="qz-submit-btn">Submit Answer</button>
    </form>

    <?php else: ?>
    <div class="qz-opts">
    <?php foreach ($opts as $opt):
        $pct     = $total_v > 0 ? round(($opt['votes'] / $total_v) * 100) : 0;
        $is_v    = ($voted_opt == $opt['id']);
        $correct = (int)$opt['is_correct'] === 1;
        $state = '';
        if ($correct) $state = 'qz-correct';
        elseif ($is_v && !$correct) $state = 'qz-wrong';
    ?>
        <div class="qz-opt <?= $state ?> <?= $is_v ? 'qz-picked' : '' ?>">
            <div class="qz-bar" style="width:<?= $pct ?>%"></div>
            <span class="qz-opt-text"><?= htmlspecialchars($opt['option_text']) ?></span>
            <?php if ($correct): ?><i class="ri-checkbox-circle-fill qz-icon-correct"></i>
            <?php elseif ($is_v): ?><i class="ri-close-circle-fill qz-icon-wrong"></i>
            <?php endif; ?>
            <span class="qz-pct"><?= $pct ?>%</span>
        </div>
    <?php endforeach; ?>
    </div>

    <?php if ($voted_opt): ?>
        <div class="qz-result-note"><?= $total_v ?> answer<?= $total_v!=1?'s':'' ?> · <?php $correct_opt = array_filter($opts, fn($o)=>$o['is_correct']); $co = reset($correct_opt); ?><?= (isset($co) && $voted_opt == $co['id']) ? '<span class="qz-you-correct">You got it right! 🎉</span>' : '<span class="qz-you-wrong">Not quite — correct answer highlighted above</span>' ?></div>
    <?php elseif ($is_expired): ?>
        <div class="qz-result-note">Quiz ended · <?= $total_v ?> answer<?= $total_v!=1?'s':'' ?></div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php else: ?>
<!-- VOTE POLL -->
<div class="vp-wrap">
    <div class="vp-hdr">
        <div class="vp-badge"><i class="ri-bar-chart-2-fill"></i> Poll</div>
        <div class="vp-timer">
            <?php if ($expires_at): ?>
                <i class="ri-time-line"></i> <span class="vp-countdown" data-expires="<?= strtotime($expires_at) ?>"><?= $is_expired ? 'Ended' : '' ?></span>
            <?php else: ?>
                <i class="ri-infinity-line"></i> Forever
            <?php endif; ?>
        </div>
    </div>
    <form method="POST">
        <input type="hidden" name="discussion_id" value="<?= $tid ?>">
        <div class="vp-opts">
        <?php foreach ($opts as $opt):
            $pct  = $total_v > 0 ? round(($opt['votes'] / $total_v) * 100) : 0;
            $is_v = ($voted_opt == $opt['id']);
        ?>
            <label class="vp-opt <?= $is_v ? 'vp-picked' : '' ?>">
                <input type="radio" name="option_id" value="<?= $opt['id'] ?>" <?= ($voted_opt || $is_expired) ? 'disabled' : '' ?> required>
                <?php if ($voted_opt || $is_expired): ?><div class="vp-bar" style="width:<?= $pct ?>%"></div><?php endif; ?>
                <span class="vp-opt-text"><?= htmlspecialchars($opt['option_text']) ?></span>
                <?php if ($voted_opt || $is_expired): ?>
                    <?php if ($is_v): ?><i class="ri-checkbox-circle-fill vp-picked-icon"></i><?php endif; ?>
                    <span class="vp-pct"><?= $pct ?>%</span>
                <?php endif; ?>
            </label>
        <?php endforeach; ?>
        </div>
        <?php if (!$voted_opt && !$is_expired && $is_member): ?>
        <button type="submit" name="vote_poll" class="vp-submit-btn">Cast Vote</button>
        <?php else: ?>
        <div class="vp-result-note"><?= $total_v ?> vote<?= $total_v!=1?'s':'' ?><?= $is_expired ? ' · Poll ended' : '' ?></div>
        <?php endif; ?>
    </form>
</div>
<?php endif; ?>

<?php endif; ?>

    </div>

    <!-- STATS / ACTIONS ROW -->
    <div class="like-row">
        <button class="like-btn <?= $liked?'active':'' ?>" data-id="<?= $tid ?>" onclick="doLike(<?= $tid ?>,'like',this)">
            <i class="ri-heart-<?= $liked?'fill':'line' ?>"></i>
            <span class="like-count"><?= (int)$t['like_count'] ?></span>
        </button>
        <button class="dislike-btn <?= $disliked?'active':'' ?>" data-id="<?= $tid ?>" onclick="doLike(<?= $tid ?>,'dislike',this)">
            <i class="ri-thumb-down-<?= $disliked?'fill':'line' ?>"></i>
        </button>
        <button class="share-btn" onclick="shareThread(<?= $tid ?>, this)"><i class="ri-share-forward-line"></i></button>
        <span class="lr-stat"><i class="ri-eye-line"></i><?= (int)$t['view_count'] ?></span>
        <?php if (!$t['comments_disabled']): ?>
        <button class="comm-toggle-btn <?= $is_open?'active':'' ?>" id="ctb-<?= $tid ?>" onclick="toggleComments(<?= $tid ?>)">
            <i class="ri-chat-3-<?= $is_open?'fill':'line' ?>"></i> <?= $reply_cnt ?>
        </button>
        <?php else: ?>
        <span class="lr-stat"><i class="ri-chat-off-line"></i> Off</span>
        <?php endif; ?>
    </div>

    <?php if ($can_del || $is_mod): ?>
    <div class="mod-strip">
        <?php if ($is_mod): ?>
        <form method="POST" style="display:contents;">
            <input type="hidden" name="mod_did" value="<?= $tid ?>">
            <button name="mod_action" value="<?= $t['is_pinned']?'unpin':'pin' ?>" class="mod-btn mb-pin"><?= $t['is_pinned']?'Unpin':'Pin' ?></button>
            <button name="mod_action" value="<?= $t['is_locked']?'unlock':'lock' ?>" class="mod-btn mb-lock"><?= $t['is_locked']?'Unlock':'Lock' ?></button>
        </form>
        <?php endif; ?>
        <?php if ($can_del): ?>
        <form method="POST" style="display:contents;" onsubmit="return confirm('Delete this thread?')">
            <input type="hidden" name="del_thread_id" value="<?= $tid ?>">
            <button type="submit" name="delete_thread" class="mod-btn mb-del"><i class="ri-delete-bin-line"></i> Delete</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- COMMENTS BOTTOM SHEET -->
    <?php if (!$t['comments_disabled']): ?>
    <div class="comments-panel <?= $is_open?'open':'' ?>" id="cp-<?= $tid ?>">

        <div class="cs-handle-row"><div class="cs-handle"></div></div>
        <div class="cs-header">
            <div class="cs-title"><?= $reply_cnt ?> <span>comment<?= $reply_cnt!=1?'s':'' ?></span></div>
            <button class="cs-close" onclick="closeComments()"><i class="ri-close-line"></i></button>
        </div>

        <div class="cp-replies-list" id="rlist-<?= $tid ?>">
        <?php
        $rq_inline = $conn->query("
            SELECT r.*, u.username, u.profile_pic
            FROM pod_discussion_replies r
            JOIN users u ON u.id = r.user_id
            WHERE r.discussion_id = $tid
            ORDER BY r.created_at ASC
        ");
        $inline_replies = [];
        while ($irow = $rq_inline->fetch_assoc()) $inline_replies[] = $irow;

        $roots    = array_filter($inline_replies, function($irow){ return !$irow['parent_id']; });
        $children = [];
        foreach ($inline_replies as $irow) {
            if ($irow['parent_id']) $children[$irow['parent_id']][] = $irow;
        }

        if (empty($roots)) {
            echo '<div class="cp-empty-note"><i class="ri-chat-smile-2-line"></i><p>No comments yet — be the first.</p></div>';
        } else {
            foreach ($roots as $irow) {
                renderReplyItem($irow, 1, $children, $current_user, $is_mod, $group_id);
            }
        }
        ?>
        </div>

        <?php if ($is_member && !$t['is_locked']): ?>
        <div class="cp-input-area" id="cpbox-<?= $tid ?>">
            <div class="cp-input-lbl">
                <span id="cplbl-<?= $tid ?>">Write a reply</span>
                <span id="cpcancel-<?= $tid ?>" style="display:none;">
                    <button onclick="cancelNested(<?= $tid ?>)" style="background:none;border:none;color:var(--red);font-size:10px;cursor:pointer;font-family:var(--font);">✕ Cancel</button>
                </span>
            </div>
            <form method="POST" enctype="multipart/form-data" class="cp-input-row">
                <input type="hidden" name="discussion_id" value="<?= $tid ?>">
                <input type="hidden" name="parent_id" value="" id="pid-<?= $tid ?>">
                <img src="<?= htmlspecialchars($me_pic) ?>" class="cp-input-av" onerror="this.src='uploads/me.jpeg'">
                <textarea class="cp-textarea" name="content" placeholder="Add a comment…" rows="1" id="cpta-<?= $tid ?>"></textarea>
                <label class="cp-media-btn" title="Attach image/video/GIF">
                    <i class="ri-image-line"></i>
                    <input type="file" name="reply_media" accept="image/*,video/*,.gif">
                </label>
                <button type="submit" name="post_reply" class="cp-send-btn"><i class="ri-send-plane-fill"></i></button>
            </form>
        </div>
        <?php elseif ($t['is_locked']): ?>
        <div class="cp-locked-notice"><i class="ri-lock-fill"></i> Thread is locked.</div>
        <?php elseif (!$is_member): ?>
        <div class="cp-locked-notice"><i class="ri-group-line"></i> Join this pod to reply.</div>
        <?php endif; ?>

    </div>
    <?php endif; ?>

</div>
<?php endforeach; endif; ?>

</div><!-- /threads -->
</div><!-- /main -->


<!-- THREAD MENU BOTTOM SHEET -->
<div id="dsSheetOverlay" class="idx-sheet-overlay" onclick="closeDsSheet()"></div>
<div id="dsSheetPanel" class="idx-sheet-panel">
    <div class="idx-sheet-handle"></div>
    <div class="idx-sheet-list" id="dsSheetList"></div>
    <button class="idx-sheet-cancel" onclick="closeDsSheet()">Cancel</button>
</div>

<!-- REPORT MODAL — Screen 1: reason picker -->
<div id="dsRptOverlay">
    <div class="gfrpt-topbar">
        <div class="gfrpt-topbar-spacer"></div>
        <div class="gfrpt-topbar-center">
            <div class="gfrpt-topbar-title">Report to Pod Admins</div>
            <div class="gfrpt-topbar-sub">Sent directly to this pod's owner &amp; admins</div>
        </div>
        <button class="gfrpt-close-btn" onclick="closeDsRptModal()" aria-label="Close">
            <i class="ri-close-line"></i>
        </button>
    </div>

    <div class="gfrpt-section-label">What should the admins know?</div>
    <div id="dsRptReasonList"></div>

    <div class="gfrpt-other-wrap" id="dsRptOtherWrap">
        <textarea class="gfrpt-other-textarea" id="dsRptOtherInput"
                  placeholder="Please describe the issue in detail…"
                  maxlength="500"
                  oninput="dsRptCharCount(this)"
                  onfocus="this.style.borderColor='rgba(255,45,45,0.45)'"
                  onblur="this.style.borderColor='rgba(255,255,255,0.09)'"></textarea>
        <div class="gfrpt-char-count" id="dsRptCharLabel">0 / 500</div>
    </div>

    <div class="gfrpt-footer">
        <button class="gfrpt-submit-btn" id="dsRptSubmitBtn" onclick="dsRptSubmit()">
            Submit Report
        </button>
        <button class="gfrpt-cancel-btn" onclick="closeDsRptModal()">Cancel</button>
    </div>
</div>

<!-- REPORT MODAL — Screen 2: success -->
<div id="dsRptStep2Screen">
    <div class="gfrpt-topbar">
        <div class="gfrpt-topbar-spacer"></div>
        <div class="gfrpt-topbar-center">
            <div class="gfrpt-topbar-title">Sent to Admins</div>
        </div>
        <div class="gfrpt-topbar-spacer"></div>
    </div>
    <div class="gfrpt-success-body">
        <div class="gfrpt-success-icon"><i class="ri-shield-check-line"></i></div>
        <div class="gfrpt-success-title">Sent to Admins</div>
        <div class="gfrpt-success-desc">
            This post has been flagged for the owner and admins of this pod to review.
        </div>
        <button class="gfrpt-done-btn" onclick="closeDsRptModal()">Done</button>
    </div>
</div>

<div id="dsRptToastEl" class="gfrpt-toast"></div>

<script>
var DS_CURRENT_USER_ID = <?= (int)$current_user ?>;
var DS_GROUP_ID = <?= (int)$group_id ?>;
var dsCurrentThread = null;

function openDsSheet(btn) {
    var d = btn.dataset;
    dsCurrentThread = d;
    var isOwn = String(d.userId) === String(DS_CURRENT_USER_ID);
    var following = d.following === '1';

    var items = [];
    items.push('<div class="idx-sheet-item" onclick="dsCopyCaption()"><i class="ri-file-copy-line"></i> Copy Caption</div>');
    if (d.media) {
        items.push('<a class="idx-sheet-item" href="'+d.media+'" download><i class="ri-download-2-line"></i> Download</a>');
    }
    items.push('<div class="idx-sheet-item" onclick="dsShareThread()"><i class="ri-share-line"></i> Share Post</div>');
    items.push('<div class="idx-sheet-item" onclick="dsHidePost()"><i class="ri-eye-off-line"></i> Hide Post</div>');
    if (!isOwn) {
        items.push('<div class="idx-sheet-item" onclick="dsToggleTrack()"><i class="ri-user-'+(following?'unfollow':'follow')+'-line"></i> '+(following?'Untrack':'Track')+' Dezzmer</div>');
    }
    items.push('<div class="idx-sheet-item danger" onclick="closeDsSheet();openDsRptModal()"><i class="ri-flag-line"></i> Report</div>');

    document.getElementById('dsSheetList').innerHTML = items.join('');
    document.getElementById('dsSheetOverlay').classList.add('show');
    document.getElementById('dsSheetPanel').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeDsSheet(){
    document.getElementById('dsSheetOverlay').classList.remove('show');
    document.getElementById('dsSheetPanel').classList.remove('show');
    document.body.style.overflow = '';
}

function dsCopyCaption(){
    if(!dsCurrentThread) return;
    var text = (dsCurrentThread.title||'') + (dsCurrentThread.body ? ('\n\n'+dsCurrentThread.body) : '');
    (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(function(){
        dsToast('Caption copied!');
    }).catch(function(){
        var el=document.createElement('textarea'); el.value=text; document.body.appendChild(el); el.select();
        document.execCommand('copy'); document.body.removeChild(el);
        dsToast('Caption copied!');
    });
    closeDsSheet();
}

function dsShareThread(){
    if(!dsCurrentThread) return;
    var url = window.location.origin + window.location.pathname + '?pod_id=' + DS_GROUP_ID + '&open=' + dsCurrentThread.id;
    if(navigator.share){
        navigator.share({title:dsCurrentThread.title||'Post', url:url}).catch(function(){});
    } else {
        navigator.clipboard.writeText(url);
        dsToast('Link copied to clipboard!');
    }
    closeDsSheet();
}

/* ── HIDE POST — identical persistence/placeholder pattern to group.php's gfHidePost ── */
var DS_HIDDEN_KEY = 'ds_hidden_threads_group_' + DS_GROUP_ID + '_user_' + DS_CURRENT_USER_ID;
var _dsHiddenState = {}; // threadId -> { unit, originalHTML }

function dsLoadHiddenIds(){
    try{ var raw=localStorage.getItem(DS_HIDDEN_KEY); var arr = raw?JSON.parse(raw):[]; return Array.isArray(arr)?arr:[]; }catch(e){return [];}
}
function dsSaveHiddenIds(arr){ try{ localStorage.setItem(DS_HIDDEN_KEY, JSON.stringify(arr)); }catch(e){} }
function dsAddHiddenId(id){
    var ids = dsLoadHiddenIds();
    if(ids.indexOf(String(id))===-1){ ids.push(String(id)); dsSaveHiddenIds(ids); }
}
function dsRemoveHiddenId(id){
    var ids = dsLoadHiddenIds().filter(function(x){ return x !== String(id); });
    dsSaveHiddenIds(ids);
}

function dsHidePost(skipCloseSheet){
    if(!dsCurrentThread) return;
    var tid = dsCurrentThread.id;
    var unit = document.getElementById('tc-'+tid);
    if(!unit) return;
    if(_dsHiddenState[tid]) return; // already hidden

    var originalHTML = unit.innerHTML;

    unit.innerHTML =
        '<div class="gf-hidden-placeholder" data-hidden-id="'+tid+'">' +
            '<div class="gf-hidden-left">' +
                '<div class="gf-hidden-icon"><i class="ri-eye-off-line"></i></div>' +
                '<div class="gf-hidden-text">Post Hidden</div>' +
            '</div>' +
            '<button type="button" class="gf-undo-btn" onclick="dsUndoHidePost('+tid+')">' +
                '<i class="ri-arrow-go-back-line"></i> Undo' +
            '</button>' +
        '</div>';

    _dsHiddenState[tid] = { unit: unit, originalHTML: originalHTML };
    dsAddHiddenId(tid);

    if (!skipCloseSheet) closeDsSheet();
    dsToast('Post hidden');
}

function dsUndoHidePost(tid){
    var pending = _dsHiddenState[tid];
    if(!pending) return;
    pending.unit.innerHTML = pending.originalHTML;
    delete _dsHiddenState[tid];
    dsRemoveHiddenId(tid);
    dsToast('Post restored');
}

/* Re-apply hidden state on load, before any interaction */
document.addEventListener('DOMContentLoaded', function(){
    dsLoadHiddenIds().forEach(function(id){
        var unit = document.getElementById('tc-'+id);
        if(!unit || _dsHiddenState[id]) return;
        var originalHTML = unit.innerHTML;
        unit.innerHTML =
            '<div class="gf-hidden-placeholder" data-hidden-id="'+id+'">' +
                '<div class="gf-hidden-left">' +
                    '<div class="gf-hidden-icon"><i class="ri-eye-off-line"></i></div>' +
                    '<div class="gf-hidden-text">Post Hidden</div>' +
                '</div>' +
                '<button type="button" class="gf-undo-btn" onclick="dsUndoHidePost('+id+')">' +
                    '<i class="ri-arrow-go-back-line"></i> Undo' +
                '</button>' +
            '</div>';
        _dsHiddenState[id] = { unit: unit, originalHTML: originalHTML };
    });
});

function dsToggleTrack(){
    if(!dsCurrentThread) return;
    var userId = dsCurrentThread.userId;
    fetch('group.php?id=' + DS_GROUP_ID, {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=toggle_follow&user_id='+userId+'&csrf_token='+encodeURIComponent(CSRF_TOKEN)
    })
    .then(function(r){return r.json();})
    .then(function(data){
        if(data.status==='ok'){
            document.querySelectorAll('.tc-menu-btn[data-user-id="'+userId+'"]').forEach(function(b){
                b.dataset.following = data.following ? '1' : '0';
            });
            dsToast(data.following ? 'Now tracking' : 'Untracked');
        } else {
            dsToast('Something went wrong');
        }
    })
    .catch(function(){ dsToast('Network error'); });
    closeDsSheet();
}

function dsToast(msg){
    var t=document.createElement('div');
    t.textContent = msg;
    Object.assign(t.style, {
        position:'fixed', bottom:'90px', left:'50%', transform:'translateX(-50%)',
        background:'rgba(20,20,25,.95)', border:'1px solid rgba(255,45,45,.3)',
        color:'#fff', padding:'8px 20px', borderRadius:'50px', fontSize:'12px',
        zIndex:'9999999', pointerEvents:'none', boxShadow:'0 8px 24px rgba(0,0,0,.6)',
        transition:'opacity .3s', whiteSpace:'nowrap'
    });
    document.body.appendChild(t);
    setTimeout(function(){ t.style.opacity='0'; setTimeout(function(){t.remove();},300); }, 2200);
}

document.addEventListener('keydown', function(e){
    if(e.key==='Escape'){
        var panel = document.getElementById('dsSheetPanel');
        if(panel && panel.classList.contains('show')) closeDsSheet();
    }
});

/* ═══════════════════════════════════════════════
   REPORT MODAL — mirrors group.php's gfrpt system,
   trimmed to Report-to-Admins only
═══════════════════════════════════════════════ */
(function () {
    var _dsRptState = { discussionId: null, reason: null };

    var _adminReasons = [
        { icon:'ri-focus-3-line',         text:'Off-Topic / Doesn\'t Fit This Pod' },
        { icon:'ri-spam-2-line',          text:'Spam or Repeated Posting' },
        { icon:'ri-emotion-unhappy-line', text:'Harassment Within the Pod' },
        { icon:'ri-prohibited-line',      text:'Hate Speech or Discrimination' },
        { icon:'ri-error-warning-line',   text:'Breaks This Pod\'s Guidelines' },
        { icon:'ri-user-forbid-line',     text:'Suspected Rule-Breaking Member' },
        { icon:'ri-question-line',        text:'Other' }
    ];

    function _openScreen(id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.style.display = 'block';
        document.body.style.overflow = 'hidden';
        requestAnimationFrame(function () {
            requestAnimationFrame(function () { el.classList.add('gfrpt-open'); });
        });
    }
    function _closeScreen(id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.classList.remove('gfrpt-open');
        setTimeout(function () { el.style.display = 'none'; }, 270);
    }
    function _closeAll() {
        ['dsRptOverlay','dsRptStep2Screen'].forEach(_closeScreen);
        document.body.style.overflow = '';
        setTimeout(function () {
            _dsRptState = { discussionId: null, reason: null };
            var ta = document.getElementById('dsRptOtherInput');
            if (ta) ta.value = '';
            var cl = document.getElementById('dsRptCharLabel');
            if (cl) cl.textContent = '0 / 500';
        }, 280);
    }

    window.openDsRptModal = function () {
        if (!dsCurrentThread) return;
        _dsRptState.discussionId = dsCurrentThread.id;
        _dsRptState.reason = null;
        _buildReasonScreen();
        _openScreen('dsRptOverlay');
    };
    window.closeDsRptModal = function () { _closeAll(); };

    function _buildReasonScreen() {
        document.getElementById('dsRptOtherWrap').style.display = 'none';
        var list = document.getElementById('dsRptReasonList');
        list.innerHTML = '';
        _adminReasons.forEach(function (r) {
            var row = document.createElement('div');
            row.className = 'gfrpt-reason-row';
            row.dataset.reason = r.text;
            row.innerHTML =
                '<i class="' + r.icon + ' gfrpt-reason-icon" aria-hidden="true"></i>' +
                '<span class="gfrpt-reason-text">' + _esc(r.text) + '</span>' +
                '<div class="gfrpt-check"></div>';
            row.addEventListener('click', function () { _selectReason(r.text); });
            list.appendChild(row);
        });
        var screen = document.getElementById('dsRptOverlay');
        if (screen) screen.scrollTop = 0;
    }

    function _selectReason(reason) {
        _dsRptState.reason = reason;
        document.querySelectorAll('#dsRptReasonList .gfrpt-reason-row').forEach(function (row) {
            var sel = row.dataset.reason === reason;
            row.classList.toggle('selected', sel);
            row.querySelector('.gfrpt-check').textContent = sel ? '✓' : '';
        });
        var ow = document.getElementById('dsRptOtherWrap');
        ow.style.display = reason === 'Other' ? 'block' : 'none';
        if (reason !== 'Other') {
            var ta = document.getElementById('dsRptOtherInput');
            if (ta) ta.value = '';
            var cl = document.getElementById('dsRptCharLabel');
            if (cl) cl.textContent = '0 / 500';
        }
    }

    window.dsRptCharCount = function (el) {
        var cl = document.getElementById('dsRptCharLabel');
        if (cl) cl.textContent = el.value.length + ' / 500';
    };

    window.dsRptSubmit = function () {
        if (!_dsRptState.reason) {
            var list = document.getElementById('dsRptReasonList');
            list.style.animation = 'none'; void list.offsetWidth;
            list.style.animation = 'gfRptShakeAnim 0.35s ease';
            setTimeout(function () { list.style.animation = ''; }, 400);
            _showToast('Please select a reason first');
            return;
        }
        if (_dsRptState.reason === 'Other') {
            var ta = document.getElementById('dsRptOtherInput');
            if (!ta.value.trim()) {
                ta.style.borderColor = 'rgba(255,45,45,0.7)';
                _showToast('Please describe the issue');
                return;
            }
        }

        var btn = document.getElementById('dsRptSubmitBtn');
        btn.disabled = true; btn.textContent = 'Submitting…';

        var desc = (document.getElementById('dsRptOtherInput').value || '').trim();
        var body = new URLSearchParams();
        body.append('report_thread_to_admins', '1');
        body.append('discussion_id', _dsRptState.discussionId);
        body.append('reason', _dsRptState.reason);
        if (desc) body.append('description', desc);
        body.append('csrf_token', CSRF_TOKEN);

        fetch('discussions.php?pod_id=' + DS_GROUP_ID, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            btn.disabled = false; btn.textContent = 'Submit Report';
            if (d.success || d.msg === 'already_reported') {
                _closeScreen('dsRptOverlay');
                setTimeout(function () { _openScreen('dsRptStep2Screen'); }, 80);
            } else {
                _showToast(d.msg || 'Something went wrong. Try again.');
            }
        })
        .catch(function () {
            btn.disabled = false; btn.textContent = 'Submit Report';
            _showToast('Network error — check your connection');
        });
    };

    function _showToast(msg) {
        var t = document.getElementById('dsRptToastEl');
        if (!t) return;
        t.textContent = msg; t.classList.add('show');
        clearTimeout(t._tid);
        t._tid = setTimeout(function () { t.classList.remove('show'); }, 2600);
    }

    function _esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var s2 = document.getElementById('dsRptStep2Screen');
        var s1 = document.getElementById('dsRptOverlay');
        if (s2 && s2.classList.contains('gfrpt-open')) { _closeAll(); }
        else if (s1 && s1.classList.contains('gfrpt-open')) { _closeAll(); }
    });
})();
</script>

<!-- ══ RIGHT SIDEBAR ══ -->
<aside class="sidebar-right">

    <!-- New discussion CTA -->
    <?php if ($is_member): ?>
    <div class="sb-widget">
        <div class="sb-widget-body" style="padding:16px;">
            <div style="font-size:13px;font-weight:700;color:var(--text);margin-bottom:9px;font-family:var(--font-h);">Start a Discussion</div>
            <div style="font-size:11.5px;color:var(--text-3);margin-bottom:13px;line-height:1.55;">Share a thought, ask a question, or start a poll with your pod.</div>
            <button class="new-btn" style="width:100%;justify-content:center;" onclick="openCreateModal()">
                <i class="ri-add-circle-line"></i> New Discussion
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Recent threads -->
    <?php if (count($threads) > 0): ?>
    <div class="sb-widget">
        <div class="sb-widget-hdr"><i class="ri-time-line"></i> Recent Threads</div>
        <div class="sb-widget-body">
            <?php
            $recent = array_slice($threads, 0, 6);
            foreach ($recent as $rt):
                $rtag = $rt['tag'] ?? 'Discussion';
                $rtc  = $tag_colors[$rtag] ?? ['bg'=>'rgba(255,255,255,0.06)','color'=>'rgba(255,255,255,0.6)'];
            ?>
            <div class="sb-thread" onclick="location.href='discussions.php?pod_id=<?= $group_id ?>&open=<?= $rt['id'] ?>'">
                <div class="sb-thread-title"><?= htmlspecialchars($rt['title']) ?></div>
                <div class="sb-thread-meta">
                    <span style="background:<?= $rtc['bg'] ?>;color:<?= $rtc['color'] ?>;padding:2px 7px;border-radius:4px;font-size:9px;font-weight:700;font-family:var(--font-h);text-transform:uppercase;"><?= htmlspecialchars($rtag) ?></span>
                    <span><i class="ri-chat-3-line"></i> <?= (int)$rt['actual_replies'] ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- My recent posts -->
    <?php if (!empty($my_threads)): ?>
    <div class="sb-widget">
        <div class="sb-widget-hdr"><i class="ri-user-line"></i> My Posts</div>
        <div class="sb-widget-body">
            <?php foreach (array_slice($my_threads, 0, 5) as $mt): ?>
            <div class="sb-thread" onclick="location.href='discussions.php?pod_id=<?= $group_id ?>&open=<?= $mt['id'] ?>'">
                <div class="sb-thread-title"><?= htmlspecialchars($mt['title']) ?></div>
                <div class="sb-thread-meta"><span><?= date('M j', strtotime($mt['created_at'])) ?></span></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</aside>

</div><!-- /page-shell -->

<!-- ═══ LIGHTBOX — topbar (avatar+username left, X + counter stacked top-right), type badge
     top-left, hover-reveal arrows on desktop / hidden on mobile,
     smart no-gap object-fit, circular dot progress, and a horizontal
     action row (like / dislike / comment / share / menu) ═══ -->
<div id="lightbox" onclick="if(event.target===this)closeLightbox()">

    <div class="lb-topbar">
        <div class="lb-user-info">
            <img id="lbUserPic" class="lb-user-pic" src="uploads/me.jpeg" onerror="this.src='uploads/me.jpeg'" alt="">
            <span id="lbUsername" class="lb-username"></span>
        </div>
        <div class="lb-topbar-right">
            <button class="lb-close" onclick="closeLightbox()"><i class="ri-close-line"></i></button>
            <div class="lb-counter" id="lbCounter"></div>
        </div>
    </div>

    <div class="lb-type-badge" id="lbTypeBadge"></div>

    <button class="lb-nav lb-prev" onclick="lbNav(-1)"><i class="ri-arrow-left-s-line"></i></button>
    <div class="lb-stage" id="lbStage"></div>
    <button class="lb-nav lb-next" onclick="lbNav(1)"><i class="ri-arrow-right-s-line"></i></button>

    <div class="lb-actions-row" id="lbActionsRow">
        <button type="button" class="lb-act-btn" id="lbLikeBtn" onclick="event.stopPropagation();lbAction('like')">
            <i class="ri-heart-line"></i><span id="lbLikeCount">0</span>
        </button>
        <button type="button" class="lb-act-btn" id="lbDislikeBtn" onclick="event.stopPropagation();lbAction('dislike')">
            <i class="ri-thumb-down-line"></i>
        </button>
        <button type="button" class="lb-act-btn" onclick="event.stopPropagation();lbComment()">
            <i class="ri-chat-3-line"></i><span id="lbCommentCount">0</span>
        </button>
        <button type="button" class="lb-act-btn" id="lbShareBtn" onclick="event.stopPropagation();lbShare()">
            <i class="ri-share-forward-line"></i>
        </button>
        <button type="button" class="lb-act-btn" onclick="event.stopPropagation();lbMenu()">
            <i class="ri-menu-line"></i>
        </button>
    </div>

    <div class="lb-dashes" id="lbDashes"></div>
</div>

<!-- CREATE MODAL -->
<style>
.cm-media-preview { display:flex; flex-wrap:wrap; gap:8px; margin-top:10px; }
.cm-media-thumb { position:relative; width:64px; height:64px; border-radius:8px; overflow:hidden; border:1px solid var(--border); background:#000; }
.cm-media-thumb img, .cm-media-thumb video { width:100%; height:100%; object-fit:cover; display:block; }
.cm-media-thumb-remove {
    position:absolute; top:2px; right:2px; width:18px; height:18px; border-radius:50%;
    background:rgba(0,0,0,0.65); border:none; color:#fff; font-size:11px;
    display:flex; align-items:center; justify-content:center; cursor:pointer; z-index:2;
}
.cm-media-thumb-remove:hover { background:var(--red); }
.cm-media-thumb-badge {
    position:absolute; bottom:2px; left:2px; font-size:8px; font-weight:800; padding:1px 5px;
    border-radius:4px; background:rgba(0,0,0,0.65); color:#fff; text-transform:uppercase; z-index:2;
}
.cm-media-thumb.cm-thumb-existing { border-color: rgba(74,168,255,0.4); }
.cm-media-thumb-tag {
    position:absolute; top:2px; left:2px; font-size:7.5px; font-weight:800; padding:1px 5px;
    border-radius:4px; background:rgba(74,168,255,0.85); color:#fff; text-transform:uppercase; z-index:2;
    letter-spacing:0.3px;
}
.cm-prefill-note {
    display:flex; align-items:center; gap:6px;
    font-size:10.5px; color:var(--blue);
    background:rgba(74,168,255,0.08);
    border:1px solid rgba(74,168,255,0.2);
    border-radius:8px;
    padding:7px 10px;
    margin-bottom:8px;
}
.cm-prefill-note i { font-size:13px; flex-shrink:0; }
</style>

<div id="createModal" onclick="if(event.target===this)closeCreateModal()">
<div class="cm-inner" onclick="event.stopPropagation()">
    <div class="cm-hdr">
        <span class="cm-hdr-title">Start a Discussion</span>
        <button class="cm-close" onclick="closeCreateModal()">✕</button>
    </div>
    <form method="POST" enctype="multipart/form-data">
        <div class="cm-body">
            <div>
                <div class="cm-lbl">Title *</div>
                <input class="cm-input" type="text" name="title" placeholder="What's this about?" required maxlength="200">
            </div>
            <div>
                <div class="cm-lbl">Body <span style="font-weight:400;text-transform:none;font-family:var(--font);">(optional)</span></div>
                <textarea class="cm-textarea" name="body" placeholder="Share more context…"></textarea>
            </div>
            <div>
                <div class="cm-lbl">Tag</div>
                <div class="tag-grid">
                    <?php foreach (['Discussion','Question','Announcement','Suggestion','Poll','Help','Meme Talk','News'] as $tg): ?>
                    <div class="tag-pill <?= $tg==='Discussion'?'sel':'' ?>" onclick="selectTag(this,'<?= $tg ?>')"><?= $tg ?></div>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="tag" id="tag-hid" value="Discussion">
            </div>
            <div id="poll-sec" style="display:none;">
    <div class="cm-lbl">Poll Type</div>
    <div class="poll-type-grid">
        <div class="poll-type-card sel" data-type="vote" onclick="selectPollType(this,'vote')">
            <i class="ri-bar-chart-2-line"></i>
            <div class="ptc-title">Vote Poll</div>
            <div class="ptc-sub">Opinion-based, no right answer</div>
        </div>
        <div class="poll-type-card" data-type="quiz" onclick="selectPollType(this,'quiz')">
            <i class="ri-trophy-line"></i>
            <div class="ptc-title">Quiz Poll</div>
            <div class="ptc-sub">Has one correct answer</div>
        </div>
    </div>
    <input type="hidden" name="poll_type" id="poll-type-hid" value="vote">

    <div class="cm-lbl" style="margin-top:14px;">Duration</div>
    <select class="cm-input" name="poll_duration" id="poll-duration-sel">
        <option value="1h">1 Hour</option>
        <option value="6h">6 Hours</option>
        <option value="1d">1 Day</option>
        <option value="3d">3 Days</option>
        <option value="1w">1 Week</option>
        <option value="1mo">1 Month</option>
        <option value="1y">1 Year</option>
        <option value="forever" selected>Forever</option>
    </select>

    <div class="cm-lbl" style="margin-top:14px;">Options</div>
    <div class="poll-opts-wrap" id="poll-opts">
        <div class="poll-opt-row">
            <input type="radio" name="correct_option" value="0" class="correct-radio" style="display:none;">
            <input class="poll-opt-inp" type="text" name="poll_options[]" placeholder="Option 1">
        </div>
        <div class="poll-opt-row">
            <input type="radio" name="correct_option" value="1" class="correct-radio" style="display:none;">
            <input class="poll-opt-inp" type="text" name="poll_options[]" placeholder="Option 2">
        </div>
    </div>
    <button type="button" class="add-opt-btn" onclick="addPollOpt()"><i class="ri-add-line"></i> Add option</button>
    <div class="poll-quiz-hint" id="pollQuizHint" style="display:none;">
        <i class="ri-information-line"></i> Tap the circle next to the correct option
    </div>
</div>
            <div>
                <div class="cm-lbl">Attachments <span style="font-weight:400;text-transform:none;font-family:var(--font);">(optional, add as many as you like)</span></div>
                <?php if ($has_prefill): ?>
                <div class="cm-prefill-note" id="cmPrefillNote">
                    <i class="ri-information-line"></i>
                    <span>Carried over from your post — remove any you don't want to include.</span>
                </div>
                <?php endif; ?>
                <label class="cm-media-btn">
                    <i class="ri-attachment-line"></i>
                    <span id="cm-file-lbl">Image / Video / GIF</span>
                    <input type="file" id="cm-media-input" accept="image/*,video/*,.gif" multiple>
                </label>
                <div class="cm-media-preview" id="cm-media-preview"></div>
                <input type="file" name="media[]" id="cm-media-hidden" multiple style="display:none;">
                <input type="hidden" name="existing_media" id="cm-existing-media-hidden" value="">
            </div>
            <div class="cm-toggle">
                <input type="checkbox" name="disable_comments" id="disableComments" value="1">
                <label for="disableComments"><i class="ri-chat-off-line"></i> Disable comments on this post</label>
            </div>
        </div>
        <div class="cm-ftr">
            <button type="button" class="cm-cancel" onclick="closeCreateModal()">Cancel</button>
            <button type="submit" name="create_thread" class="cm-submit">Post Discussion</button>
        </div>
    </form>
</div>
</div>

<script>
/* ── LIGHTBOX ── */
var lbItems = [];
var lbIndex = 0;
var lbCurrentTid = null;

function openLightbox(el, idx) {
    var wrap = el.closest('.tc-gallery');
    if (!wrap) return;
    try { lbItems = JSON.parse(wrap.dataset.gallery); } catch (e) { lbItems = []; }
    lbIndex = idx;
    lbCurrentTid = wrap.dataset.threadId || null;

    var card = lbCurrentTid ? document.getElementById('tc-' + lbCurrentTid) : null;
    if (card) {
        var av    = card.querySelector('.tc-av');
        var uname = card.querySelector('.tc-uname');
        var lbPic  = document.getElementById('lbUserPic');
        var lbName = document.getElementById('lbUsername');
        if (av && lbPic) lbPic.src = av.src;
        if (uname && lbName) lbName.textContent = uname.textContent.trim();
        lbSyncFromCard(lbCurrentTid);
    }

    renderLightbox();
    document.getElementById('lightbox').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeLightbox() {
    document.getElementById('lightbox').classList.remove('open');
    document.body.style.overflow = '';
    document.getElementById('lbStage').innerHTML = '';
    lbCurrentTid = null;
}
function lbNav(dir) {
    if (!lbItems.length) return;
    lbIndex = (lbIndex + dir + lbItems.length) % lbItems.length;
    renderLightbox();
}

/* ── smart object-fit: identical logic to group.php's gfdFixObjectFit,
     so media fills the stage box edge-to-edge without side gaps
     whenever it's close enough, and only lets true outliers stay
     letterboxed via 'contain'. ── */
function lbFixObjectFit(el) {
    var box = document.getElementById('lbStage');
    if (!box) return;
    var mW = el.tagName === 'VIDEO' ? el.videoWidth : el.naturalWidth;
    var mH = el.tagName === 'VIDEO' ? el.videoHeight : el.naturalHeight;
    if (!mW || !mH) return;
    var cW = box.clientWidth, cH = box.clientHeight;
    if (!cW || !cH) return;
    var mediaAspect = mW / mH;
    var boxAspect   = cW / cH;

    // Tall/long images (portrait, screenshots, etc.) should never be
    // cropped — show the full image, letterboxed on the sides if needed.
    if (mediaAspect < boxAspect) {
        el.style.objectFit = 'contain';
        return;
    }
    var scaledHeight = cW / mediaAspect;
    var fillRatio    = scaledHeight / cH;
    var FILL_THRESHOLD = 0.84;
    el.style.objectFit = (fillRatio >= FILL_THRESHOLD) ? 'fill' : 'contain';
}

function lbTypeInfo(type) {
    var t = (type || '').toLowerCase();
    if (t === 'video') return { icon: 'ri-vidicon-line', label: 'Video' };
    if (t === 'gif')   return { icon: 'ri-file-gif-line', label: 'GIF' };
    return { icon: 'ri-image-line', label: 'Photo' };
}

function renderLightbox() {
    var stage   = document.getElementById('lbStage');
    var counter = document.getElementById('lbCounter');
    var dashes  = document.getElementById('lbDashes');
    var badge   = document.getElementById('lbTypeBadge');
    var item = lbItems[lbIndex];
    if (!item) return;
    var src = 'uploads/' + item.file;

    stage.innerHTML = (item.type === 'video')
        ? '<video src="' + src + '" controls autoplay playsinline onloadedmetadata="lbFixObjectFit(this)"></video>'
        : '<img src="' + src + '" onload="lbFixObjectFit(this)">';

    var ti = lbTypeInfo(item.type);
    badge.innerHTML = '<i class="' + ti.icon + '"></i><span>' + ti.label + '</span>';

    counter.textContent = (lbIndex + 1) + ' / ' + lbItems.length;

    // Force the counter to sit BELOW the close X, stacked in the
    // top-right corner, regardless of any other CSS in the page.
    counter.style.position  = 'static';
    counter.style.top       = 'auto';
    counter.style.left      = 'auto';
    counter.style.bottom    = 'auto';
    counter.style.transform = 'none';
    counter.style.order     = '2';
    counter.style.marginTop = '2px';

    var rightWrap = document.querySelector('.lb-topbar-right');
    if (rightWrap) {
        rightWrap.style.display = 'flex';
        rightWrap.style.flexDirection = 'column';
        rightWrap.style.alignItems = 'flex-end';
        rightWrap.style.gap = '6px';
    }
    var closeBtn = document.querySelector('.lb-topbar-right .lb-close');
    if (closeBtn) closeBtn.style.order = '1';

    var showNav = lbItems.length > 1;
    document.querySelector('.lb-prev').style.display = showNav ? 'flex' : 'none';
    document.querySelector('.lb-next').style.display = showNav ? 'flex' : 'none';
    counter.style.display = showNav ? 'flex' : 'none';
    dashes.style.display = showNav ? 'flex' : 'none';
    if (showNav) {
        dashes.innerHTML = lbItems.map(function(_, i) {
            return '<span class="lb-dash' + (i === lbIndex ? ' active' : '') + '"></span>';
        }).join('');
    }
}

/* ── re-fit currently displayed media if the viewport / stage size changes ── */
window.addEventListener('resize', function () {
    var lb = document.getElementById('lightbox');
    if (!lb || !lb.classList.contains('open')) return;
    var media = document.querySelector('#lbStage img, #lbStage video');
    if (media) lbFixObjectFit(media);
});

/* ── lightbox action row: like / dislike / comment / share / menu ── */
function lbSyncFromCard(tid) {
    var card = document.getElementById('tc-' + tid);
    if (!card) return;

    var likeBtn    = card.querySelector('.like-btn');
    var dislikeBtn = card.querySelector('.dislike-btn');
    var likeCount  = card.querySelector('.like-count');
    var commBtn    = card.querySelector('.comm-toggle-btn');

    var lbLike        = document.getElementById('lbLikeBtn');
    var lbDislike      = document.getElementById('lbDislikeBtn');
    var lbLikeCount    = document.getElementById('lbLikeCount');
    var lbCommentCount = document.getElementById('lbCommentCount');

    if (likeBtn && lbLike) {
        var isLiked = likeBtn.classList.contains('active');
        lbLike.classList.toggle('active', isLiked);
        lbLike.querySelector('i').className = isLiked ? 'ri-heart-fill' : 'ri-heart-line';
    }
    if (dislikeBtn && lbDislike) {
        var isDisliked = dislikeBtn.classList.contains('active');
        lbDislike.classList.toggle('active', isDisliked);
        lbDislike.querySelector('i').className = isDisliked ? 'ri-thumb-down-fill' : 'ri-thumb-down-line';
    }
    if (likeCount && lbLikeCount) lbLikeCount.textContent = likeCount.textContent;
    if (commBtn && lbCommentCount) {
        var m = commBtn.textContent.match(/\d+/);
        lbCommentCount.textContent = m ? m[0] : '0';
    }
}

function lbAction(type) {
    if (!lbCurrentTid) return;
    doLike(lbCurrentTid, type);
}

function lbComment() {
    if (!lbCurrentTid) return;
    var tid = lbCurrentTid;
    closeLightbox();
    toggleComments(tid);
}

function lbShare() {
    if (!lbCurrentTid) return;
    var btn = document.getElementById('lbShareBtn');
    shareThread(lbCurrentTid, btn);
}

function lbMenu() {
    if (!lbCurrentTid) return;
    var card = document.getElementById('tc-' + lbCurrentTid);
    var menuBtn = card ? card.querySelector('.tc-menu-btn') : null;
    if (!menuBtn) return;
    closeLightbox();
    openDsSheet(menuBtn);
}

document.addEventListener('keydown', function(e) {
    var lb = document.getElementById('lightbox');
    if (!lb || !lb.classList.contains('open')) return;
    if (e.key === 'Escape')      closeLightbox();
    if (e.key === 'ArrowLeft')   lbNav(-1);
    if (e.key === 'ArrowRight')  lbNav(1);
});

(function() {
    var startX = 0;
    document.addEventListener('touchstart', function(e) {
        var lb = document.getElementById('lightbox');
        if (!lb || !lb.classList.contains('open')) return;
        startX = e.touches[0].clientX;
    }, { passive: true });
    document.addEventListener('touchend', function(e) {
        var lb = document.getElementById('lightbox');
        if (!lb || !lb.classList.contains('open')) return;
        var dx = e.changedTouches[0].clientX - startX;
        if (Math.abs(dx) > 50) lbNav(dx > 0 ? -1 : 1);
    }, { passive: true });
})();


function formatCountdown(seconds) {
    if (seconds <= 0) return 'Ended';
    var d = Math.floor(seconds / 86400);
    var h = Math.floor((seconds % 86400) / 3600);
    var m = Math.floor((seconds % 3600) / 60);
    if (d > 0) return d + 'd ' + h + 'h left';
    if (h > 0) return h + 'h ' + m + 'm left';
    return m + 'm left';
}
function updateCountdowns() {
    var now = Math.floor(Date.now() / 1000);
    document.querySelectorAll('.vp-countdown[data-expires], .qz-timer[data-expires]').forEach(function(el) {
        var exp = parseInt(el.dataset.expires, 10);
        if (!exp) return;
        var remaining = exp - now;
        el.textContent = formatCountdown(remaining);
    });
}
setInterval(updateCountdowns, 60000);
window.addEventListener('load', updateCountdowns);


function selectPollType(el, type) {
    document.querySelectorAll('.poll-type-card').forEach(function(c){ c.classList.remove('sel'); });
    el.classList.add('sel');
    document.getElementById('poll-type-hid').value = type;
    var isQuiz = (type === 'quiz');
    document.querySelectorAll('.correct-radio').forEach(function(r){ r.style.display = isQuiz ? 'inline-block' : 'none'; });
    document.getElementById('pollQuizHint').style.display = isQuiz ? 'flex' : 'none';
}

/* ── EXISTING (PREFILLED) MEDIA — carried over from a group post via the
     Discuss button. Kept as its own mutable list (dsPrefillMediaState),
     separate from cmFileStore (real File objects picked from disk), since
     these are just filenames that already live in uploads/. Both lists
     render into the SAME preview grid, and both feed the form on submit:
     existing items go out as a JSON hidden field, freshly picked ones go
     out as normal $_FILES via the hidden multi-file input. ── */
var dsPrefillMediaState = Array.isArray(DS_PREFILL_MEDIA) ? DS_PREFILL_MEDIA.slice() : [];

function syncExistingMediaInput() {
    var hidden = document.getElementById('cm-existing-media-hidden');
    if (hidden) hidden.value = JSON.stringify(dsPrefillMediaState);
}

/* ── MULTI-FILE ATTACHMENT HANDLING ── */
var cmFileStore = new DataTransfer(); // holds the "real" freshly-picked files across add/remove actions

document.getElementById('cm-media-input').addEventListener('change', function(e) {
    Array.from(e.target.files).forEach(function(f) { cmFileStore.items.add(f); });
    renderCmPreview();
    this.value = ''; // clear so picking the same file again still fires 'change'
    syncHiddenMediaInput();
});

function renderCmPreview() {
    var wrap = document.getElementById('cm-media-preview');
    wrap.innerHTML = '';

    // Existing media (carried over from the group post) renders first
    dsPrefillMediaState.forEach(function(item, idx) {
        var url = 'uploads/' + item.file;
        var isVideo = item.type === 'video';
        var isGif = item.type === 'gif';

        var thumb = document.createElement('div');
        thumb.className = 'cm-media-thumb cm-thumb-existing';

        var media = isVideo
            ? '<video src="' + url + '" muted></video>'
            : '<img src="' + url + '">';

        thumb.innerHTML = media +
            '<span class="cm-media-thumb-tag">From post</span>' +
            (isGif ? '<span class="cm-media-thumb-badge">GIF</span>' : '') +
            (isVideo ? '<span class="cm-media-thumb-badge">Video</span>' : '') +
            '<button type="button" class="cm-media-thumb-remove" data-source="existing" data-idx="' + idx + '">✕</button>';

        wrap.appendChild(thumb);
    });

    // Freshly picked uploads render after
    Array.from(cmFileStore.files).forEach(function(file, idx) {
        var url = URL.createObjectURL(file);
        var ext = file.name.split('.').pop().toLowerCase();
        var isVideo = ['mp4','webm','mov'].includes(ext);
        var isGif = ext === 'gif';

        var thumb = document.createElement('div');
        thumb.className = 'cm-media-thumb';

        var media = isVideo
            ? '<video src="' + url + '" muted></video>'
            : '<img src="' + url + '">';

        thumb.innerHTML = media +
            (isGif ? '<span class="cm-media-thumb-badge">GIF</span>' : '') +
            (isVideo ? '<span class="cm-media-thumb-badge">Video</span>' : '') +
            '<button type="button" class="cm-media-thumb-remove" data-source="new" data-idx="' + idx + '">✕</button>';

        wrap.appendChild(thumb);
    });

    var totalCount = dsPrefillMediaState.length + cmFileStore.files.length;
    var label = document.getElementById('cm-file-lbl');
    label.textContent = totalCount
        ? totalCount + ' file' + (totalCount > 1 ? 's' : '') + ' selected'
        : 'Image / Video / GIF';

    var note = document.getElementById('cmPrefillNote');
    if (note) note.style.display = dsPrefillMediaState.length ? 'flex' : 'none';

    syncExistingMediaInput();
}

document.getElementById('cm-media-preview').addEventListener('click', function(e) {
    var btn = e.target.closest('.cm-media-thumb-remove');
    if (!btn) return;
    var idx = parseInt(btn.dataset.idx, 10);
    var source = btn.dataset.source;

    if (source === 'existing') {
        dsPrefillMediaState.splice(idx, 1);
        renderCmPreview();
    } else {
        var newStore = new DataTransfer();
        Array.from(cmFileStore.files).forEach(function(f, i) {
            if (i !== idx) newStore.items.add(f);
        });
        cmFileStore = newStore;
        renderCmPreview();
        syncHiddenMediaInput();
    }
});

function syncHiddenMediaInput() {
    var hidden = document.getElementById('cm-media-hidden');
    hidden.files = cmFileStore.files;
}

function resetCmMediaPicker() {
    cmFileStore = new DataTransfer();
    dsPrefillMediaState = [];
    renderCmPreview();
    syncHiddenMediaInput();
}

function addPollOpt() {
    var wrap = document.getElementById('poll-opts');
    var n = wrap.children.length;
    var isQuiz = document.getElementById('poll-type-hid').value === 'quiz';

    var row = document.createElement('div');
    row.className = 'poll-opt-row';

    var radio = document.createElement('input');
    radio.type = 'radio'; radio.name = 'correct_option'; radio.value = n;
    radio.className = 'correct-radio';
    radio.style.display = isQuiz ? 'inline-block' : 'none';

    var inp = document.createElement('input');
    inp.className = 'poll-opt-inp'; inp.type = 'text';
    inp.name = 'poll_options[]'; inp.placeholder = 'Option ' + (n + 1);

    row.appendChild(radio);
    row.appendChild(inp);
    wrap.appendChild(row);
}

window.addEventListener('load', function() {
    document.getElementById('skelWrap').style.display = 'none';
    var tl = document.getElementById('threadsList');
    tl.style.display = 'flex';
    tl.style.flexDirection = 'column';
    tl.style.gap = '10px';

    document.querySelectorAll('.tc-body-text.clamped').forEach(function(el) {
        if (el.scrollHeight > el.clientHeight + 2) {
            var tid = el.id.replace('tcbodytext-', '');
            var btn = document.getElementById('tcreadmore-' + tid);
            if (btn) btn.style.display = 'inline-block';
        }
    });
});

/* ── COMMENTS BOTTOM SHEET ── */
function toggleComments(tid) {
    var panel = document.getElementById('cp-' + tid);
    var btn   = document.getElementById('ctb-' + tid);
    var card  = document.getElementById('tc-' + tid);
    if (!panel) return;
    var isOpen = panel.classList.contains('open');
    closeComments();
    if (!isOpen) {
        panel.classList.add('open');
        card.classList.add('is-open');
        var backdrop = document.getElementById('csBackdrop');
        if (backdrop) backdrop.classList.add('open');
        document.body.style.overflow = 'hidden';
        if (btn) {
            btn.classList.add('active');
            var i = btn.querySelector('i');
            if (i) i.className = i.className.replace('-line','-fill');
        }
    }
}

function closeComments() {
    document.querySelectorAll('.comments-panel.open').forEach(function(p){ p.classList.remove('open'); });
    document.querySelectorAll('.comm-toggle-btn.active').forEach(function(b){
        b.classList.remove('active');
        var i = b.querySelector('i');
        if (i) i.className = i.className.replace('-fill','-line');
    });
    document.querySelectorAll('.tc.is-open').forEach(function(c){ c.classList.remove('is-open'); });
    var backdrop = document.getElementById('csBackdrop');
    if (backdrop) backdrop.classList.remove('open');
    document.body.style.overflow = '';
}

/* ── UPDATED: doLike() now sends the CSRF token, and syncs the
     lightbox's action row if it's currently open on this thread ── */
function doLike(tid, type, btn) {
    fetch('discussions.php?pod_id=<?= $group_id ?>', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'ajax_like=1&discussion_id=' + tid + '&type=' + type + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
    })
    .then(function(r){ return r.json(); })
    .then(function(data) {
        var card = document.getElementById('tc-' + tid);
        var lb = card.querySelector('.like-btn');
        var db = card.querySelector('.dislike-btn');
        var lc = card.querySelector('.like-count');
        lb.classList.remove('active'); db.classList.remove('active');
        lb.querySelector('i').className = 'ri-heart-line';
        db.querySelector('i').className = 'ri-thumb-down-line';
        if (data.user_type === 'like')    { lb.classList.add('active'); lb.querySelector('i').className = 'ri-heart-fill'; }
        else if (data.user_type === 'dislike') { db.classList.add('active'); db.querySelector('i').className = 'ri-thumb-down-fill'; }
        lc.textContent = data.likes;

        if (typeof lbCurrentTid !== 'undefined' && lbCurrentTid !== null && String(lbCurrentTid) === String(tid) && typeof lbSyncFromCard === 'function') {
            lbSyncFromCard(tid);
        }
    }).catch(console.error);
}

/* ── NEW: doReact() — wires up the previously-dead ajax_react endpoint, CSRF-safe ── */
function doReact(tid, emoji) {
    fetch('discussions.php?pod_id=<?= $group_id ?>', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'ajax_react=1&discussion_id=' + tid + '&emoji=' + encodeURIComponent(emoji) + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
    })
    .then(function(r){ return r.json(); })
    .then(function(data) {
        console.log('reaction update', data);
    }).catch(console.error);
}

/* ── READ MORE (post body) ── */
function toggleReadMore(tid) {
    var el  = document.getElementById('tcbodytext-' + tid);
    var btn = document.getElementById('tcreadmore-' + tid);
    if (!el) return;
    var collapsed = el.classList.toggle('clamped');
    btn.textContent = collapsed ? '…Read more' : 'Show less';
}

/* ── SHARE ── */
function shareThread(tid, btn) {
    var url = window.location.origin + window.location.pathname + '?pod_id=<?= $group_id ?>&open=' + tid;
    navigator.clipboard.writeText(url).then(function() {
        var i = btn.querySelector('i');
        var old = i.className;
        i.className = 'ri-check-line';
        setTimeout(function(){ i.className = old; }, 1200);
    }).catch(console.error);
}

function showNestedReply(did, parentId, username) {
    var pid = document.getElementById('pid-' + did);
    var ta  = document.getElementById('cpta-' + did);
    var lbl = document.getElementById('cplbl-' + did);
    var can = document.getElementById('cpcancel-' + did);
    var box = document.getElementById('cpbox-' + did);
    if (!pid) return;
    pid.value = parentId;
    ta.placeholder = 'Replying to @' + username + '…';
    ta.focus();
    lbl.textContent = 'Replying to @' + username;
    can.style.display = 'inline';
    box.style.background = 'rgba(255,59,59,0.035)';
    box.style.borderTop  = '1px solid rgba(255,59,59,0.2)';
}
function cancelNested(did) {
    var pid = document.getElementById('pid-' + did);
    var ta  = document.getElementById('cpta-' + did);
    var lbl = document.getElementById('cplbl-' + did);
    var can = document.getElementById('cpcancel-' + did);
    var box = document.getElementById('cpbox-' + did);
    pid.value = ''; ta.placeholder = 'Write a reply…';
    lbl.textContent = 'Write a reply'; can.style.display = 'none';
    box.style.background = ''; box.style.borderTop = '';
}

function toggleHist() { document.getElementById('histDd').classList.toggle('open'); }
document.addEventListener('click', function(e) {
    var w = document.getElementById('histWrap');
    if (w && !w.contains(e.target)) document.getElementById('histDd').classList.remove('open');
});

function openCreateModal()  { document.getElementById('createModal').classList.add('open');    document.body.style.overflow = 'hidden'; }
function closeCreateModal() {
    document.getElementById('createModal').classList.remove('open');
    document.body.style.overflow = '';
    resetCmMediaPicker();
}

function selectTag(el, tag) {
    document.querySelectorAll('.tag-pill').forEach(function(p){ p.classList.remove('sel'); });
    el.classList.add('sel');
    document.getElementById('tag-hid').value = tag;
    document.getElementById('poll-sec').style.display = tag === 'Poll' ? 'block' : 'none';
}

/* ── YOU MODAL ── */
function openYouModal() {
    var m = document.getElementById('youModal');
    if (!m) return;
    m.classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeYouModal() {
    var m = document.getElementById('youModal');
    if (!m) return;
    m.classList.remove('open');
    document.body.style.overflow = '';
}
function sortYouPosts(mode, btn) {
    document.querySelectorAll('.you-sort-btn').forEach(function(b){ b.classList.remove('active'); });
    if (btn) btn.classList.add('active');

    var list = document.getElementById('youList');
    if (!list) return;
    var items = Array.prototype.slice.call(list.querySelectorAll('.you-item'));

    items.sort(function(a, b) {
        if (mode === 'newest')  return (b.dataset.ts  - a.dataset.ts);
        if (mode === 'oldest')  return (a.dataset.ts  - b.dataset.ts);
        if (mode === 'popular') return (b.dataset.pop - a.dataset.pop);
        return 0;
    });

    items.forEach(function(item) { list.appendChild(item); });
}

(function(){
    var open = <?= $open ?: 0 ?>;
    if (open) {
        var panel = document.getElementById('cp-' + open);
        var card  = document.getElementById('tc-' + open);
        var btn   = document.getElementById('ctb-' + open);
        var backdrop = document.getElementById('csBackdrop');
        if (panel) panel.classList.add('open');
        if (card)  card.classList.add('is-open');
        if (backdrop) backdrop.classList.add('open');
        if (btn)   { btn.classList.add('active'); var i=btn.querySelector('i'); if(i) i.className=i.className.replace('-line','-fill'); }
    }
})();

/* ── PREFILL AUTO-OPEN — if the page loaded with a valid prefill_meme_id
     (via group.php's Discuss button), render the carried-over media into
     the attachment preview and open the create modal automatically so the
     person lands straight into finishing their post. ── */
document.addEventListener('DOMContentLoaded', function () {
    if (DS_HAS_PREFILL) {
        renderCmPreview();
        openCreateModal();
    }
});

document.addEventListener('input', function(e) {
    if (e.target.classList.contains('cp-textarea')) {
        e.target.style.height = 'auto';
        e.target.style.height = Math.min(e.target.scrollHeight, 110) + 'px';
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeCreateModal();
        closeYouModal();
        closeComments();
    }
});
</script>