<?php
session_start();
include 'config.php';

$current_user = (int)($_SESSION['user_id'] ?? 0);
if (!$current_user) { header("Location: login.php"); exit(); }

$group_id = (int)($_GET['pod_id'] ?? 0);

/* verified badge (follows the text colour: white in dark mode, black in light mode) */
function ds_vb(): string { return '<svg class="ds-vb" viewBox="0 0 256 256" aria-label="Verified" fill="currentColor"><path fill-rule="evenodd" d="M245.9 128.0L245.5 132.6L244.2 137.1L242.3 141.5L239.7 145.7L236.5 149.6L233.0 153.2L229.3 156.6L225.6 159.7L221.9 162.6L218.5 165.5L215.4 168.3L212.7 171.2L210.5 174.2L208.7 177.5L207.3 181.0L206.3 184.9L205.4 189.1L204.7 193.5L204.0 198.2L203.1 203.1L201.9 208.0L200.4 212.8L198.4 217.4L196.0 221.6L193.0 225.3L189.5 228.4L185.6 230.8L181.3 232.5L176.6 233.5L171.8 233.7L166.9 233.4L161.9 232.4L157.1 231.1L152.4 229.5L147.8 227.8L143.5 226.1L139.4 224.6L135.5 223.5L131.7 222.8L128.0 222.5L124.3 222.8L120.5 223.5L116.6 224.6L112.5 226.1L108.2 227.8L103.6 229.5L98.9 231.1L94.1 232.4L89.1 233.4L84.2 233.7L79.4 233.5L74.7 232.5L70.4 230.8L66.5 228.4L63.0 225.3L60.0 221.6L57.6 217.4L55.6 212.8L54.1 208.0L52.9 203.1L52.0 198.2L51.3 193.5L50.6 189.1L49.7 184.9L48.7 181.0L47.3 177.5L45.5 174.2L43.3 171.2L40.6 168.3L37.5 165.5L34.1 162.6L30.4 159.7L26.7 156.6L23.0 153.2L19.5 149.6L16.3 145.7L13.7 141.5L11.8 137.1L10.5 132.6L10.1 128.0L10.5 123.4L11.8 118.9L13.7 114.5L16.3 110.3L19.5 106.4L23.0 102.8L26.7 99.4L30.4 96.3L34.1 93.4L37.5 90.5L40.6 87.7L43.3 84.8L45.5 81.8L47.3 78.5L48.7 75.0L49.7 71.1L50.6 66.9L51.3 62.5L52.0 57.8L52.9 52.9L54.1 48.0L55.6 43.2L57.6 38.6L60.0 34.4L63.0 30.7L66.5 27.6L70.4 25.2L74.7 23.5L79.4 22.5L84.2 22.3L89.1 22.6L94.1 23.6L98.9 24.9L103.6 26.5L108.2 28.2L112.5 29.9L116.6 31.4L120.5 32.5L124.3 33.2L128.0 33.5L131.7 33.2L135.5 32.5L139.4 31.4L143.5 29.9L147.8 28.2L152.4 26.5L157.1 24.9L161.9 23.6L166.9 22.6L171.8 22.3L176.6 22.5L181.3 23.5L185.6 25.2L189.5 27.6L193.0 30.7L196.0 34.4L198.4 38.6L200.4 43.2L201.9 48.0L203.1 52.9L204.0 57.8L204.7 62.5L205.4 66.9L206.3 71.1L207.3 75.0L208.7 78.5L210.5 81.8L212.7 84.8L215.4 87.7L218.5 90.5L221.9 93.4L225.6 96.3L229.3 99.4L233.0 102.8L236.5 106.4L239.7 110.3L242.3 114.5L244.2 118.9L245.5 123.4ZM84.1 131.9Q78.2 137.8 84.1 143.6L108.3 167.9Q114.1 173.7 119.9 167.8L174.2 111.6Q179.9 105.6 174.0 99.9L170.0 96.1Q164.1 90.4 158.4 96.3L119.6 136.4Q113.9 142.3 108.0 136.5L99.6 128.1Q93.8 122.2 87.9 128.1Z"/></svg>'; }

/* verified badge: only selected when the users table has the column */
$ds_has_ver = false;
try { $__c = $conn->query("SHOW COLUMNS FROM `users` LIKE 'is_verified'"); $ds_has_ver = ($__c && $__c->num_rows > 0); } catch (Throwable $e) { $ds_has_ver = false; }
$ds_ver_u = $ds_has_ver ? ", COALESCE(u.is_verified,0) AS is_verified" : "";
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

}

/* comments API (load / add / like / delete comments + working report-to-admins) */
require __DIR__ . '/discussions_comments_api.php';

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
        SELECT d.*, u.username, u.profile_pic{$ds_ver_u},
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
        SELECT d.*, u.username, u.profile_pic{$ds_ver_u},
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
<?php
/* ════════════════════════════════════════════════════════════════
   DISCUSSIONS PAGE — group.php ecosystem, blue theme
   Keep ALL of your existing PHP from the old discussions.php ABOVE this
   file's contents (up to and including the "$my_threads" query), and
   include discussions_comments_api.php BEFORE the
   "SORT / SEARCH / THREAD LIST" section.
   This file starts with a prelude that only derives display data
   from variables your PHP already made.
   ════════════════════════════════════════════════════════════════ */

$ds_tag_colors = [
    'Discussion'   => ['bg'=>'rgba(91,143,230,0.14)','color'=>'#8fb4ff'],
    'Question'     => ['bg'=>'rgba(74,168,255,0.13)','color'=>'#6fbcff'],
    'Announcement' => ['bg'=>'rgba(244,197,66,0.13)','color'=>'#f4c542'],
    'Suggestion'   => ['bg'=>'rgba(157,140,255,0.15)','color'=>'#b0a3ff'],
    'Poll'         => ['bg'=>'rgba(61,220,132,0.12)','color'=>'#3ddc84'],
    'Help'         => ['bg'=>'rgba(255,165,64,0.13)','color'=>'#ffab40'],
    'Meme Talk'    => ['bg'=>'rgba(255,120,160,0.12)','color'=>'#ff9cb8'],
    'News'         => ['bg'=>'rgba(74,182,255,0.12)','color'=>'#4ab6ff'],
];

$ds_tag_counts = [];
$ds_total_replies = 0;
foreach ($threads as $_t) {
    $tg = $_t['tag'] ?? 'Discussion';
    $ds_tag_counts[$tg] = ($ds_tag_counts[$tg] ?? 0) + 1;
    $ds_total_replies += (int)$_t['actual_replies'];
}
arsort($ds_tag_counts);

/* ── green "active now" dot: poster seen in the last 5 minutes, poster allows it, and you share yours too ── */
function ds_online_map($conn, $viewer, array $ids): array {
    $map = [];
    $viewer = (int)$viewer;
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($v) use ($viewer) { return $v > 0 && $v !== $viewer; })));
    if (!$viewer || !$ids) return $map;
    if (function_exists('getOnlineMap')) { try { return getOnlineMap($conn, $viewer, $ids); } catch (Throwable $e) {} }   /* the site's own rules, when loaded */
    try {
        $col = '';
        foreach (['last_active', 'last_seen', 'last_activity', 'last_online'] as $c) {
            $q = $conn->query("SHOW COLUMNS FROM users LIKE '$c'");
            if ($q && $q->num_rows) { $col = $c; break; }
        }
        if ($col === '') return $map;
        $set = false;
        $q = $conn->query("SHOW COLUMNS FROM user_settings LIKE 'show_online_status'");
        if ($q && $q->num_rows) $set = true;
        if ($set) {   /* you only see other people's dot if you show yours */
            $m = $conn->query("SELECT show_online_status AS s FROM user_settings WHERE user_id = $viewer LIMIT 1");
            if ($m && ($mr = $m->fetch_assoc()) && (int)$mr['s'] === 0) return $map;
        }
        $in = implode(',', $ids);
        $sql = $set
            ? "SELECT u.id FROM users u LEFT JOIN user_settings us ON us.user_id = u.id WHERE u.id IN ($in) AND u.`$col` >= (NOW() - INTERVAL 300 SECOND) AND (us.show_online_status IS NULL OR us.show_online_status <> 0)"
            : "SELECT u.id FROM users u WHERE u.id IN ($in) AND u.`$col` >= (NOW() - INTERVAL 300 SECOND)";
        $res = $conn->query($sql);
        if ($res) while ($x = $res->fetch_assoc()) $map[(int)$x['id']] = true;
    } catch (Throwable $e) {}
    return $map;
}
$ds_online = ds_online_map($conn, $current_user, array_map(function ($t) { return (int)$t['user_id']; }, $threads));
$ds_feed_out = [];
foreach ($threads as $t) {
    $tid = (int)$t['id'];
    $poll = null;
    if ($t['tag'] === 'Poll' && !empty($poll_data[$tid])) {
        $opts = $poll_data[$tid];
        $tot  = array_sum(array_column($opts, 'votes'));
        $vr   = $conn->query("SELECT option_id FROM pod_poll_votes WHERE discussion_id=$tid AND user_id=$current_user")->fetch_assoc();
        $exp  = $t['poll_expires_at'] ?? null;
        $poll = [
            'type'    => ($t['poll_type'] ?? '') === 'quiz' ? 'quiz' : 'vote',
            'expires' => $exp ? (int)strtotime($exp) : 0,
            'expired' => ($exp && strtotime($exp) <= time()),
            'voted'   => $vr ? (int)$vr['option_id'] : 0,
            'total'   => (int)$tot,
            'options' => array_map(function ($o) {
                return ['id'=>(int)$o['id'], 'text'=>(string)$o['option_text'], 'votes'=>(int)$o['votes'], 'correct'=>((int)$o['is_correct'] === 1)];
            }, $opts),
        ];
    }
    $media = [];
    foreach (($media_data[$tid] ?? []) as $m) $media[] = ['file' => 'uploads/' . $m['file'], 'type' => $m['type']];

    $ds_feed_out[] = [
        'id'         => $tid,
        'userId'     => (string)(int)$t['user_id'],
        'username'   => (string)$t['username'],
        'isVerified' => !empty($t['is_verified']),
        'online'     => !empty($ds_online[(int)$t['user_id']]),
        'pic'        => !empty($t['profile_pic']) ? $t['profile_pic'] : 'uploads/me.jpeg',
        'tag'        => (string)$t['tag'],
        'title'      => (string)$t['title'],
        'body'       => (string)$t['body'],
        'time'       => $t['created_at'],
        'pinned'     => !empty($t['is_pinned']),
        'locked'     => !empty($t['is_locked']),
        'commentsOff'=> !empty($t['comments_disabled']),
        'liked'      => ($t['user_like_type'] === 'like'),
        'disliked'   => ($t['user_like_type'] === 'dislike'),
        'likes'      => (int)$t['like_count'],
        'replies'    => (int)$t['actual_replies'],
        'views'      => (int)$t['view_count'],
        'media'      => $media,
        'poll'       => $poll,
        'sourceMeme' => !empty($t['source_meme_id']) ? (int)$t['source_meme_id'] : 0,
        'following'  => !empty($_disc_follow_map[(int)$t['user_id']]),
        'canDel'     => ($is_mod || (int)$t['user_id'] === (int)$current_user),
        'isOwn'      => ((int)$t['user_id'] === (int)$current_user),
    ];
}
$ds_json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE;

$ds_sort_opts = [
    'newest'  => ['ri-time-line',       'Newest',          'Most recent discussions first'],
    'replies' => ['ri-chat-3-line',     'Most replies',    'Longest conversations first'],
    'views'   => ['ri-eye-line',        'Most viewed',     'Highest view count first'],
    'active'  => ['ri-flashlight-line', 'Recently active', 'Latest activity first'],
];
$ds_sort_label = $ds_sort_opts[$sort][1] ?? 'Newest';
$ds_cat = trim((string)($group['category'] ?? ''));
$ds_url_base = 'discussions.php?pod_id=' . (int)$group_id;

/* ── helpers for the right-column cards (same look as group.php) ── */
if (!function_exists('gs_h')) { function gs_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('gs_open')) {
    function gs_open($k, $icon, $title, $count = '') {
        echo '<section class="gs-card" data-k="' . gs_h($k) . '">'
           . '<button type="button" class="gs-bar" onclick="gsToggle(this)" aria-expanded="true">'
           . '<i class="' . gs_h($icon) . ' gs-bar-ic"></i><span class="gs-bar-t">' . gs_h($title) . '</span>'
           . ($count !== '' ? '<span class="gs-bar-c">' . gs_h($count) . '</span>' : '')
           . '<i class="ri-arrow-down-s-fill gs-bar-chev"></i></button>'
           . '<div class="gs-body"><div class="gs-inner"><div class="gs-pad">';
    }
}
if (!function_exists('gs_close')) { function gs_close() { echo '</div></div></div></section>'; } }

/* ── transparency data ── */
$ds_private    = (($group['privacy'] ?? 'public') === 'private');
$ds_can_see    = (!$ds_private) || $is_member || $is_mod;
$ds_paused     = !empty($group['is_paused']);
$ds_created    = !empty($group['created_at']) ? date('F j, Y', strtotime($group['created_at'])) : '—';
$ds_creator_id = (int)($group['created_by'] ?? 0);
$ds_creator    = (string)($group['creator_name'] ?? '');
if ($ds_creator === '' && $ds_creator_id) {
    try {
        $r = $conn->query("SELECT username FROM users WHERE id = $ds_creator_id LIMIT 1");
        if ($r instanceof mysqli_result && ($x = $r->fetch_assoc())) $ds_creator = (string)$x['username'];
    } catch (Throwable $e) {}
}
$ds_admins = [];
if ($ds_can_see) {
    try {
        $r = $conn->query("SELECT u.id, u.username, u.profile_pic{$ds_ver_u} FROM group_admins ga JOIN users u ON u.id = ga.user_id WHERE ga.group_id = " . (int)$group_id);
        if ($r instanceof mysqli_result) while ($x = $r->fetch_assoc()) $ds_admins[] = $x;
    } catch (Throwable $e) { $ds_admins = []; }
}
$ds_name_changes = 0;
try {
    $r = $conn->query("SELECT COUNT(*) c FROM group_name_history WHERE group_id = " . (int)$group_id);
    if ($r instanceof mysqli_result) $ds_name_changes = (int)($r->fetch_assoc()['c'] ?? 0);
} catch (Throwable $e) {}

$ds_my = (isset($my_threads) && is_array($my_threads)) ? $my_threads : [];
$ds_my_likes = 0; $ds_my_replies = 0;
foreach ($ds_my as $_m) { $ds_my_likes += (int)$_m['like_count']; $ds_my_replies += (int)$_m['reply_count']; }

/* ── upcoming events (same rule as group.php) ── */
$ds_events = [];
$ds_ev_ok = ($is_member || $is_mod) && !($ds_paused && !$is_mod);
if ($ds_ev_ok) {
    try {
        $now_lagos = (new DateTime('now', new DateTimeZone('Africa/Lagos')))->format('Y-m-d H:i:s');
        $st = $conn->prepare("SELECT id, event_name, event_image, event_date, event_time, location_type
                              FROM group_events
                              WHERE group_id = ? AND TIMESTAMP(event_date, event_time) > ?
                              ORDER BY event_date ASC, event_time ASC LIMIT 20");
        if ($st) {
            $gid_i = (int)$group_id;
            $st->bind_param("is", $gid_i, $now_lagos);
            $st->execute();
            $rs = $st->get_result();
            while ($r = $rs->fetch_assoc()) $ds_events[] = $r;
            $st->close();
        }
    } catch (Throwable $e) { $ds_events = []; }
}
$ds_ev_count = count($ds_events);

/* ═════════ RIGHT-COLUMN / ABOUT CARDS (built once, printed in the right column AND in the About view) ═════════ */
$ds_topic_info = [
    'Discussion'   => ['ri-chat-smile-3-line','Open conversation about anything to do with the pod.'],
    'Question'     => ['ri-question-line','Ask the pod something and get answers from members.'],
    'Announcement' => ['ri-megaphone-line','Official updates and news from the pod team.'],
    'Suggestion'   => ['ri-lightbulb-line','Ideas to improve the pod. Members can react and reply.'],
    'Poll'         => ['ri-bar-chart-2-line','Quick votes, or quizzes that have one correct answer.'],
    'Help'         => ['ri-lifebuoy-line','Stuck? Ask for support with the pod or the app.'],
    'Meme Talk'    => ['ri-emotion-laugh-line','Chat about memes, trends and what is making you laugh.'],
    'News'         => ['ri-newspaper-line','Share and discuss what is happening around this community.'],
];

ob_start();

/* ── ABOUT ── */
gs_open('about', 'ri-information-line', 'About this page'); ?>
  <p class="gs-p">This is the <b>Discussions board</b> of <b><?= gs_h($group['title']) ?></b>. It is where members talk things through: ask questions, debate ideas, vote on polls, suggest improvements and get help. Every topic lives in its own <b>thread</b> with nested replies, so conversations stay organised and easy to find later.</p>
  <div class="gs-callout"><i class="ri-eye-off-line"></i><span><b>Stays in this pod.</b> Threads posted on this page only appear here, on the pod's Discussions page. They never show in anyone's feeds. Only the pod's <b>main posts</b> (the ones shared on the pod's home page) appear in feeds across the platform.</span></div>
  <div class="gs-vs">
    <div><b><i class="ri-image-2-line"></i> Pod posts</b><small>Quick media posts. Shown in feeds across the platform.</small></div>
    <div class="me"><b><i class="ri-discuss-line"></i> Discussions</b><small>Text-first threads. Shown only on this page.</small></div>
  </div>
<?php gs_close();

/* ── TRANSPARENCY ── */
gs_open('transp', 'ri-shield-check-line', 'Transparency'); ?>
  <div class="gs-row"><span class="gs-row-l">Discussions opened</span><span class="gs-row-v"><?= gs_h($ds_created) ?></span></div>
  <div class="gs-row"><span class="gs-row-l">Opened with</span><span class="gs-row-v">The pod itself</span></div>
  <div class="gs-row"><span class="gs-row-l">Pod creator</span>
    <?php if ($ds_creator !== ''): ?><a class="gs-row-v" href="profile.php?user_id=<?= $ds_creator_id ?>"><?= gs_h($ds_creator) ?></a><?php else: ?><span class="gs-row-v">—</span><?php endif; ?>
  </div>
  <div class="gs-row"><span class="gs-row-l">Members</span><span class="gs-row-v"><?= number_format((int)$member_count) ?></span></div>
  <div class="gs-row"><span class="gs-row-l">Threads</span><span class="gs-row-v"><?= number_format(count($threads)) ?></span></div>
  <div class="gs-row"><span class="gs-row-l">Replies</span><span class="gs-row-v"><?= number_format($ds_total_replies) ?></span></div>
  <div class="gs-row"><span class="gs-row-l">Who can post</span><span class="gs-row-v">Pod members</span></div>
  <div class="gs-row"><span class="gs-row-l">Who can read</span><span class="gs-row-v"><?= $ds_private ? 'Members only' : 'Everyone' ?></span></div>
  <div class="gs-row"><span class="gs-row-l">Shown in feeds</span><span class="gs-row-v">No, pod only</span></div>
  <div class="gs-row"><span class="gs-row-l">Former pod names</span><span class="gs-row-v"><?= $ds_name_changes === 0 ? 'Never changed' : $ds_name_changes . ' change' . ($ds_name_changes === 1 ? '' : 's') ?></span></div>
  <div class="gs-row"><span class="gs-row-l">Board status</span><span class="gs-row-v"><?= $ds_paused ? 'Paused' : 'Open' ?></span></div>
  <a class="gs-more" href="group.php?id=<?= (int)$group_id ?>">Back to pod home</a>
<?php gs_close();

/* ── ADMINS ── */
if ($ds_can_see) {
    gs_open('admins', 'ri-shield-star-line', 'Admins', (string)count($ds_admins));
    if (!empty($ds_admins)) {
        foreach ($ds_admins as $ad) { ?>
        <a class="gs-admin" href="profile.php?user_id=<?= (int)$ad['id'] ?>">
          <img src="<?= gs_h(!empty($ad['profile_pic']) ? $ad['profile_pic'] : 'uploads/me.jpeg') ?>" onerror="this.src='uploads/me.jpeg'" alt="">
          <span class="gs-admin-n"><?= gs_h($ad['username']) ?><?php if (!empty($ad['is_verified'])): ?><?= ds_vb() ?><?php endif; ?></span><span class="gs-admin-tag">ADMIN</span>
        </a>
        <?php }
    } else { ?><div class="gs-note">No admins besides the pod creator yet.</div><?php }
    gs_close();
}

/* ── HOW IT WORKS ── */
gs_open('how', 'ri-route-line', 'How it works');
foreach ([
    ['Start a thread','Pick a topic, add a title, then optionally attach media or build a poll.'],
    ['Join the conversation','Comment, reply to replies (threads nest), and like or dislike what you read.'],
    ['Vote and learn','Cast your vote on polls. Quizzes reveal the right answer once you have answered.'],
    ['Keep it tidy','Admins can pin key threads or lock them. See something wrong? Report it to the admins.'],
] as $i => $s) { ?>
  <div class="gs-step"><div class="gs-sn"><?= $i + 1 ?></div><div class="gs-st"><b><?= gs_h($s[0]) ?></b><span><?= gs_h($s[1]) ?></span></div></div>
<?php }
gs_close();

/* ── TOPICS ── */
gs_open('topics', 'ri-price-tag-3-line', 'Topics, explained');
foreach ($ds_tag_colors as $tn => $tc) {
    $ti = $ds_topic_info[$tn] ?? ['ri-price-tag-3-line', ''];
    $n  = (int)($ds_tag_counts[$tn] ?? 0); ?>
  <button type="button" class="gs-topic" data-tag="<?= gs_h($tn) ?>" onclick="dsFilterTag(this.dataset.tag)">
    <span class="gs-ti" style="background:<?= $tc['bg'] ?>;color:<?= $tc['color'] ?>"><i class="<?= gs_h($ti[0]) ?>"></i></span>
    <span class="gs-tx"><span class="gs-tn"><b><?= gs_h($tn) ?></b><small><?= $n ?> thread<?= $n === 1 ? '' : 's' ?></small></span><span class="gs-td"><?= gs_h($ti[1]) ?></span></span>
  </button>
<?php }
gs_close();

/* ── GROUND RULES ── */
gs_open('rules', 'ri-book-2-line', 'Ground rules');
foreach ([
    'Be respectful. Debate ideas, never people.',
    'Stay on topic and choose the tag that fits.',
    'No spam, ads or repeated posts.',
    'Search before you ask. Your answer may already be here.',
    'Spot a problem? Open the menu on a post and choose Report to admins.',
] as $rl) { ?>
  <div class="gs-rule"><i class="ri-checkbox-circle-fill"></i><span><?= gs_h($rl) ?></span></div>
<?php }
gs_close();

/* ── BOARD PULSE ── */
gs_open('pulse', 'ri-pulse-line', 'Board pulse'); ?>
  <div class="gs-row"><span class="gs-row-l">Threads</span><span class="gs-row-v"><?= number_format(count($threads)) ?></span></div>
  <div class="gs-row"><span class="gs-row-l">Replies</span><span class="gs-row-v"><?= number_format($ds_total_replies) ?></span></div>
  <div class="gs-row"><span class="gs-row-l">Members</span><span class="gs-row-v"><?= number_format((int)$member_count) ?></span></div>
  <div class="gs-row"><span class="gs-row-l">Online now</span><span class="gs-row-v" style="color:var(--green)"><?= (int)$active_count ?></span></div>
  <?php foreach (($active_users ?? []) as $au): $apic = !empty($au['profile_pic']) ? $au['profile_pic'] : 'uploads/me.jpeg'; ?>
  <a class="gs-admin" href="profile.php?user_id=<?= (int)$au['id'] ?>"><img src="<?= gs_h($apic) ?>" onerror="this.src='uploads/me.jpeg'" alt=""><span class="gs-admin-n">@<?= gs_h($au['username']) ?></span><span class="gs-live"></span></a>
  <?php endforeach; ?>
<?php gs_close();

/* ── RECENT THREADS ── */
if (count($threads) > 0) {
    gs_open('recent', 'ri-time-line', 'Recent threads', (string)min(5, count($threads)));
    foreach (array_slice($threads, 0, 5) as $rt) { ?>
      <button type="button" class="gs-th" onclick="dsGoTo(<?= (int)$rt['id'] ?>)"><b><?= gs_h($rt['title']) ?></b><small><span><i class="ri-chat-3-line"></i><?= (int)$rt['actual_replies'] ?></span></small></button>
    <?php }
    gs_close();
}

/* ── MY POSTS ── */
if (!empty($ds_my)) {
    gs_open('mine', 'ri-user-line', 'My posts', (string)count($ds_my));
    foreach (array_slice($ds_my, 0, 5) as $mt) { ?>
      <button type="button" class="gs-th" onclick="dsGoTo(<?= (int)$mt['id'] ?>)"><b><?= gs_h($mt['title']) ?></b><small><span><?= date('M j', strtotime($mt['created_at'])) ?></span></small></button>
    <?php }
    gs_close();
}

$ds_cards_html = ob_get_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#010218" id="metaTheme">
<script>try{if(localStorage.getItem('dz_theme')==='light'){document.documentElement.setAttribute('data-theme','light');document.getElementById('metaTheme').setAttribute('content','#f4f5fb');}}catch(e){}
/* keep the theme in sync if it is switched in another tab */
window.addEventListener('storage',function(e){
    if(e.key!=='dz_theme') return;
    var light=e.newValue==='light';
    if(light) document.documentElement.setAttribute('data-theme','light'); else document.documentElement.removeAttribute('data-theme');
    var mt=document.getElementById('metaTheme'); if(mt) mt.setAttribute('content', light?'#f4f5fb':'#010218');
});</script>
<title><?= htmlspecialchars($group['title']) ?> — Discussions · Dezzme Pods</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<script>
const CSRF_TOKEN = <?= json_encode($csrf_token) ?>;
var DS_GROUP_ID = <?= (int)$group_id ?>, DS_ME = <?= (int)$current_user ?>;
var DS_URL = <?= json_encode($ds_url_base) ?>;
var DS_IS_MEMBER = <?= $is_member ? 'true' : 'false' ?>;
var DS_IS_MOD = <?= $is_mod ? 'true' : 'false' ?>;
var DS_OPEN = <?= (int)$open ?>;
var DS_PREFILL_MEDIA = <?= json_encode($prefill_media) ?>;
var DS_HAS_PREFILL = <?= $has_prefill ? 'true' : 'false' ?>;
var DS_VB = <?= json_encode(ds_vb()) ?>;
var DS_ME_PIC = <?= json_encode($me_pic) ?>;
var DS_FEED = <?= json_encode($ds_feed_out, $ds_json_flags) ?>;
var DS_TAGS = <?= json_encode(array_keys($ds_tag_colors)) ?>;
var DS_TAG_COLORS = <?= json_encode($ds_tag_colors) ?>;
var DS_EMPTY_MSG = <?= json_encode($search !== '' ? 'No discussions match your search.' : 'No discussions yet.') ?>;
</script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;font-family:'Poppins',sans-serif}
button,a{cursor:pointer;outline:none;-webkit-tap-highlight-color:transparent}
button{-webkit-appearance:none;appearance:none}
a{color:inherit}
[class^="ri-"],[class*=" ri-"],[class^="ri-"]::before,[class*=" ri-"]::before{font-family:'remixicon'!important;font-style:normal;font-weight:normal;line-height:1;-webkit-font-smoothing:antialiased}

:root{
  /* phone-only size multipliers — IDENTICAL to the group page */
  --hd-s:1;
  --gf-s:1;
  --md-s:1;
  --red:#FF2D2D;
  --solid-rgb:7,9,36;
  --fg:255,255,255;      /* text/line colour as an RGB triplet — flips in light mode */
  --fg2:220,224,255;
  --blue-text:#8fb4ff;
  --flash:#0b1340;
  --bg:linear-gradient(-45deg,#010218,#070924,#070924);
  --solid:#070924;
  --post-sep:#02030e;
  --surface:rgba(var(--fg),.05);
  --surface-hover:rgba(var(--fg),.09);
  --border:rgba(var(--fg),.09);
  --divider:rgba(var(--fg),.07);
  --text:#fff;
  --muted:rgba(var(--fg),.45);
  --grey-text:#6F7282;
  --blue:#2459B8;
  --blue-hover:#2E68C9;
  --blue-soft:#5b8fe6;
  --feed-blue:#2b63d1;
  --feed-blue-rgb:43,99,209;
  --accent:#2b63d1;
  --conv:#4cc9f0;
  --violet:#7c5cff;
  --green:#3ddc84;
  --btn-grey:rgba(var(--fg),.1);
  --max-w:620px;
  --topbar-h:calc(52px * var(--hd-s));
  --topbar-pad-l:calc(4px * var(--hd-s));
  --back-x:calc(10px * var(--hd-s));
  --bbtn:calc(36px * var(--hd-s));
  --sidebar-w:100px;
  --pad-x:clamp(9px,3vw,16px);
  --banner-h:clamp(104px,29vw,178px);
  --action-h:calc(clamp(23px,6.7vw,28px) * var(--hd-s));
  --action-r:4px;
  --btn-r:4px;
  --arrow-r:8px;
  --fs-name:calc(clamp(.86rem,3.7vw,1rem) * var(--hd-s));
  --fs-meta:calc(clamp(.64rem,2.2vw,.76rem) * var(--hd-s) * 1.03);
  --fs-desc:calc(clamp(.64rem,1.93vw,.75rem) * var(--hd-s) * 1.03);
}
html{scroll-behavior:smooth}
body{background:var(--bg);background-attachment:fixed;color:var(--text);min-height:100vh;display:flex;flex-direction:column;align-items:center;padding:0 0 76px;overflow-x:hidden}

/* RESPONSIVE VIEW: no scroll bars anywhere. Normal view keeps them. */
@media(max-width:768px){
  :root{--hd-s:1.1;--gf-s:1.155;--md-s:1.1}
  *{scrollbar-width:none;-ms-overflow-style:none}
  *::-webkit-scrollbar{display:none;width:0;height:0;background:transparent}
}
/* sidebar comes from nav.php */
@media(min-width:769px){body{padding-left:var(--sidebar-w);align-items:flex-start}}
@media(min-width:490px) and (max-width:800px){:root{--max-w:100%}}
@media(min-width:801px){:root{--max-w:520px}}
@media(max-width:490px){:root{--topbar-h:calc(48px * var(--hd-s));--bbtn:calc(32px * var(--hd-s))}}

/* ── SHELL ── */
.ds-shell{width:100%;display:flex;align-items:flex-start;justify-content:center}
.ds-page{width:100%;max-width:var(--max-w);margin:0 auto}
@media(min-width:769px){.ds-page{border-left:1px solid var(--border);border-right:1px solid var(--border);min-height:100vh}}
.ds-side{display:none}
@media(min-width:1100px){
  .ds-shell{justify-content:flex-start;padding-left:22px}
  .ds-page{margin:0;flex:0 0 var(--max-w);width:var(--max-w)}
  .ds-side{display:block;flex:1 1 auto;min-width:0;position:sticky;top:0;height:100vh;overflow-y:auto;overflow-x:hidden;
    padding:0 22px 30px 20px;scrollbar-width:thin;scrollbar-color:#2e68c9 rgba(var(--fg),.07)}
  .ds-side::-webkit-scrollbar{width:9px}
  .ds-side::-webkit-scrollbar-track{background:rgba(var(--fg),.07);border-radius:99px}
  .ds-side::-webkit-scrollbar-thumb{background:#2e68c9;border-radius:99px;border:2px solid transparent;background-clip:padding-box}
  .ds-side::-webkit-scrollbar-thumb:hover{background:#4a82e6;background-clip:padding-box}
}

/* ═════════ RIGHT COLUMN CARDS — same design as the group page ═════════ */
.gs-cards{padding:18px 0 2px}
.gs-card{margin-bottom:12px}
.gs-bar{display:flex;align-items:center;gap:7px;width:100%;height:30px;padding:0 12px;border:none;border-radius:var(--action-r);
  background:rgba(var(--fg),.1);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);
  color:var(--text);font-size:.72rem;font-weight:600;line-height:1;text-align:left;transition:background .2s ease,transform .15s ease}
.gs-bar:hover{background:rgba(var(--fg),.2)}
.gs-bar:active{transform:scale(.99)}
.gs-bar-ic{font-size:14px;color:var(--blue-soft);flex-shrink:0}
.gs-bar-t{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.gs-bar-c{font-size:.64rem;font-weight:600;color:rgba(var(--fg),.55);flex-shrink:0}
.gs-bar-chev{font-size:18px;color:rgba(var(--fg),.75);flex-shrink:0;transition:transform .25s ease}
.gs-card.collapsed .gs-bar-chev{transform:rotate(-90deg)}
.gs-body{display:grid;grid-template-rows:1fr;transition:grid-template-rows .3s ease}
.gs-inner{min-height:0;overflow:hidden}
.gs-pad{padding-top:8px}
.gs-card.collapsed .gs-body{grid-template-rows:0fr}
.gs-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:9px 2px;border-bottom:1px solid rgba(var(--fg),.06)}
.gs-row:last-of-type{border-bottom:none}
.gs-row-l{font-size:.66rem;color:rgba(var(--fg),.5);font-weight:500;white-space:nowrap}
.gs-row-v{font-size:.74rem;font-weight:600;color:var(--text);text-align:right;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;text-decoration:none}
a.gs-row-v:hover{color:var(--blue-text);text-decoration:underline}
.gs-more{display:flex;align-items:center;justify-content:center;width:100%;height:30px;margin-top:8px;padding:0 12px;border:none;border-radius:var(--action-r);
  background:rgba(var(--fg),.1);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);
  color:var(--text);font-size:.7rem;font-weight:600;text-decoration:none;line-height:1;transition:background .2s ease,transform .15s ease}
.gs-more:hover{background:rgba(var(--fg),.2)}
.gs-more:active{transform:scale(.98)}
.gs-admin{display:flex;align-items:center;gap:10px;padding:8px 2px;color:var(--text);text-decoration:none;border-bottom:1px solid rgba(var(--fg),.06)}
.gs-admin:last-of-type{border-bottom:none}
.gs-admin img{width:32px;height:32px;border-radius:50%;object-fit:cover;border:2px solid var(--feed-blue);flex-shrink:0}
.gs-admin-n{flex:1;min-width:0;font-size:.76rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.gs-admin:hover .gs-admin-n{text-decoration:underline}
.gs-admin-tag{font-size:.56rem;font-weight:700;letter-spacing:.5px;color:var(--blue-text);background:rgba(var(--feed-blue-rgb),.16);border:1px solid rgba(var(--feed-blue-rgb),.4);padding:2px 7px;border-radius:4px;flex-shrink:0}
.gs-live{width:7px;height:7px;border-radius:50%;background:var(--green);flex-shrink:0}
.gs-note{font-size:.68rem;color:rgba(var(--fg),.5);line-height:1.55;padding:4px 2px}
.gs-p{font-size:.72rem;line-height:1.65;color:rgba(var(--fg),.7);overflow-wrap:anywhere;padding:2px 2px 4px}
.gs-p b{color:var(--text);font-weight:600}
.gs-callout{display:flex;gap:9px;margin:8px 0 4px;padding:10px 11px;border-radius:var(--action-r);background:rgba(var(--feed-blue-rgb),.12);border:1px solid rgba(var(--feed-blue-rgb),.4);font-size:.67rem;line-height:1.6;color:rgba(var(--fg),.8)}
.gs-callout i{color:var(--blue-soft);font-size:1rem;flex-shrink:0;margin-top:1px}
.gs-callout b{color:var(--text)}
.gs-vs{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px}
.gs-vs>div{padding:10px;border-radius:var(--action-r);background:rgba(var(--fg),.04);border:1px solid var(--divider);min-width:0}
.gs-vs>div.me{background:rgba(43,99,209,.14);border-color:rgba(43,99,209,.5)}
.gs-vs b{display:flex;align-items:center;gap:6px;font-size:.7rem;margin-bottom:4px}
.gs-vs b i{color:var(--blue-soft);font-size:.9rem}
.gs-vs small{display:block;font-size:.62rem;line-height:1.5;color:rgba(var(--fg),.6);overflow-wrap:anywhere}
.gs-step{display:flex;gap:11px;padding:10px 2px;border-bottom:1px solid rgba(var(--fg),.06)}
.gs-step:last-of-type{border-bottom:none}
.gs-sn{width:22px;height:22px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:.62rem;font-weight:700;color:#000;background:var(--blue)}
.gs-st{min-width:0}
.gs-st b{display:block;font-size:.72rem;margin-bottom:2px}
.gs-st span{display:block;font-size:.65rem;line-height:1.55;color:rgba(var(--fg),.58);overflow-wrap:anywhere}
.gs-topic{display:flex;align-items:flex-start;gap:10px;width:100%;padding:9px 2px;border:none;border-bottom:1px solid rgba(var(--fg),.06);background:none;color:inherit;font:inherit;text-align:left}
.gs-topic:last-of-type{border-bottom:none}
.gs-topic:hover .gs-tn b{text-decoration:underline}
.gs-ti{width:28px;height:28px;border-radius:var(--action-r);display:flex;align-items:center;justify-content:center;font-size:.95rem;flex-shrink:0}
.gs-tx{flex:1;min-width:0}
.gs-tn{display:flex;align-items:center;gap:7px;flex-wrap:wrap}
.gs-tn b{font-size:.72rem}
.gs-tn small{font-size:.56rem;font-weight:600;color:var(--muted);padding:1px 7px;border-radius:4px;background:rgba(var(--fg),.07)}
.gs-td{display:block;font-size:.64rem;line-height:1.5;color:rgba(var(--fg),.55);margin-top:2px;overflow-wrap:anywhere}
.gs-rule{display:flex;gap:9px;padding:6px 2px;font-size:.68rem;line-height:1.55;color:rgba(var(--fg),.74);overflow-wrap:anywhere}
.gs-rule i{color:var(--blue-soft);font-size:.95rem;flex-shrink:0;margin-top:1px}
.gs-th{display:block;width:100%;padding:9px 2px;border:none;border-bottom:1px solid rgba(var(--fg),.06);background:none;color:inherit;font:inherit;text-align:left}
.gs-th:last-of-type{border-bottom:none}
.gs-th:hover{opacity:.78}
.gs-th b{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;font-size:.72rem;font-weight:600;line-height:1.4;margin-bottom:3px;overflow-wrap:anywhere}
.gs-th small{display:flex;align-items:center;flex-wrap:wrap;gap:4px 12px;font-size:.62rem;color:var(--muted)}
.gs-th small span{display:inline-flex;align-items:center;gap:3px}

/* ── BANNER + TOP BAR (same sizes as the group page) ── */
.gp-banner{position:relative;width:100%;height:var(--banner-h);background:#0a0c30;overflow:hidden}
.gp-banner-img{width:100%;height:100%;object-fit:cover;display:block}
.gp-banner-shade{position:absolute;inset:0;background:linear-gradient(to bottom,rgba(0,0,0,.45),rgba(0,0,0,.05) 45%,rgba(0,0,0,.55))}
.gp-stats{position:absolute;bottom:calc(7px * var(--hd-s));left:var(--pad-x);right:var(--pad-x);display:flex;align-items:center;justify-content:flex-end;gap:calc(clamp(6px,2.2vw,12px) * var(--hd-s));z-index:5;min-width:0}
.gp-stat{display:flex;flex-direction:column;align-items:flex-end;gap:1px;min-width:0}
.gp-stat b{font-size:calc(clamp(9.5px,2.6vw,12px) * var(--hd-s) * 1.03);font-weight:700;color:rgba(255,255,255,.8);line-height:1.1}
.gp-stat span{font-size:calc(clamp(7.5px,2.1vw,9.5px) * var(--hd-s));font-weight:500;color:rgba(255,255,255,.5);white-space:nowrap;max-width:100%;overflow:hidden;text-overflow:ellipsis}
.gp-stat-div{width:1px;height:calc(clamp(16px,4.6vw,24px) * var(--hd-s));background:rgba(255,255,255,.22);flex-shrink:0}
@media(max-width:340px){.gp-stat-div{display:none}}

.gp-topbar{position:sticky;top:0;z-index:500;height:var(--topbar-h);margin-bottom:calc(var(--topbar-h) * -1);display:flex;align-items:center;justify-content:space-between;
  padding:0 calc(8px * var(--hd-s)) 0 var(--topbar-pad-l);border:none;transition:background .25s}
.gp-topbar.scrolled{background:var(--bg);background-attachment:fixed;backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px)}
@media(max-width:768px){.gp-bbtn .ri-add-line{font-size:1.0404em;-webkit-text-stroke:.003em currentColor}}
.gp-topbar.scrolled .gp-bbtn{background:rgba(var(--fg),.08)}
.gp-topbar.scrolled .gp-bbtn:hover,
.gp-topbar.scrolled .gp-bbtn.back:hover{background:rgba(var(--fg),.14)}
.gp-topbar.scrolled .gp-bbtn.back{margin-left:calc(var(--back-x) - var(--topbar-pad-l))}
.gp-topbar-title{position:absolute;left:calc(var(--back-x) + var(--bbtn) + calc(10px * var(--hd-s)));right:calc(130px * var(--hd-s));top:0;bottom:0;display:flex;align-items:center;font-size:calc(.76rem * var(--hd-s));font-weight:700;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;opacity:0;transform:translateY(4px);transition:opacity .25s,transform .25s;pointer-events:none}
.gp-topbar.show-title .gp-topbar-title{opacity:1;transform:none}
.gp-filterbar.is-stuck{box-shadow:0 8px 16px -10px rgba(0,0,0,.65)}
.gp-banner-right{display:flex;align-items:center;gap:calc(6px * var(--hd-s));margin-left:auto}
.gp-bbtn{width:var(--bbtn);height:var(--bbtn);flex:0 0 auto;padding:0;border-radius:50%;border:none;background:rgba(0,0,0,.45);backdrop-filter:blur(8px);
  -webkit-backdrop-filter:blur(8px);color:#fff;font-size:calc(18px * var(--hd-s));display:flex;align-items:center;justify-content:center;text-decoration:none;
  transition:background .2s,transform .15s}
.gp-bbtn:hover{background:rgba(0,0,0,.65)}
.tb-sicon{width:1em;height:1em;display:block;flex-shrink:0}
.gp-bbtn:active{transform:scale(.92)}
.gp-bbtn.back{background:transparent;backdrop-filter:none;-webkit-backdrop-filter:none;font-size:calc(clamp(26px, 24.03px + 1.18vw, 32px) * 1.155);margin-left:-2px;
  transition:background .2s,transform .15s,margin-left .25s ease}
.gp-bbtn.back:hover{background:transparent}
@media(min-width:769px){
  .gp-bbtn.back{display:none}
  .gp-topbar-title{left:calc(14px * var(--hd-s))}
}
@media(max-width:490px){.gp-bbtn{font-size:calc(16px * var(--hd-s))}.gp-bbtn.back{font-size:calc(clamp(26px, 24.03px + 1.18vw, 32px) * 1.155)}.gp-topbar-title{font-size:calc(.7rem * var(--hd-s))}}
@media(max-width:768px){:root{--bbtn:calc(36px * var(--hd-s) * 1.05)}.gp-bbtn{font-size:calc(18px * var(--hd-s) * 1.05)}}
@media(max-width:490px){:root{--bbtn:calc(32px * var(--hd-s) * 1.05)}.gp-bbtn{font-size:calc(16px * var(--hd-s) * 1.05)}}

/* ── HEADER BODY ── */
.gp-head{padding:calc(10px * var(--hd-s)) var(--pad-x) 0}
.gp-name{display:flex;align-items:baseline;gap:calc(6px * var(--hd-s));min-width:0;font-size:var(--fs-name);font-weight:700;letter-spacing:-.2px;line-height:1.3}
.gp-podlink{flex:0 1 auto;min-width:0;font:inherit;font-weight:700;letter-spacing:inherit;color:var(--text);text-decoration:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.gp-podlink:hover{text-decoration:underline}
.gp-name-d{flex:0 0 auto;color:var(--blue-hover)}
.gp-meta{display:flex;align-items:center;gap:calc(7px * var(--hd-s));margin-top:calc(3px * var(--hd-s));font-size:var(--fs-meta);font-weight:500;color:rgba(var(--fg),.75);flex-wrap:wrap}
.gp-dot{width:3px;height:3px;border-radius:50%;background:rgba(var(--fg),.4);flex-shrink:0}
.gp-cat{font-weight:700;color:var(--grey-text)}
.gp-desc{margin-top:calc(5px * var(--hd-s));font-size:var(--fs-desc);line-height:1.5;color:rgba(var(--fg),.72);width:100%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

/* ── ACTION BUTTONS ── */
.gp-actions{display:flex;flex-wrap:nowrap;align-items:stretch;gap:calc(8px * var(--hd-s));padding:calc(12px * var(--hd-s)) 0 calc(10px * var(--hd-s))}
.gp-btn{display:flex;align-items:center;justify-content:center;gap:calc(6px * var(--hd-s));flex:1 1 0;width:100%;min-width:0;height:var(--action-h);
  padding:calc(4px * var(--hd-s)) calc(10px * var(--hd-s));border:none;border-radius:var(--btn-r);font-size:calc(clamp(.58rem, .5rem + .6vw, .72rem) * var(--hd-s));font-weight:600;line-height:1;white-space:nowrap;
  overflow:hidden;text-decoration:none;color:var(--text);background:var(--btn-grey);backdrop-filter:blur(10px);
  -webkit-backdrop-filter:blur(10px);transition:background .2s,transform .15s}
.gp-btn:hover{background:rgba(var(--fg),.2)}
.gp-btn:active{transform:scale(.98)}
.gp-btn i{font-size:calc(12px * var(--hd-s))}
.gp-btn .gp-plus{width:calc(13px * var(--hd-s));height:calc(13px * var(--hd-s));flex-shrink:0;display:block}
.gp-btn.primary{background:var(--blue);color:#000;font-weight:800;flex:2 1 0;letter-spacing:.1px}
.gp-btn.primary:hover{background:var(--blue-hover)}
.gp-btn.locked{opacity:.55}
@media(max-width:490px){.gp-actions{gap:calc(6px * var(--hd-s))}.gp-btn{padding:calc(3px * var(--hd-s)) calc(8px * var(--hd-s))}.gp-btn i{font-size:calc(12px * var(--hd-s))}}
@media(max-width:360px){.gp-btn{padding:calc(3px * var(--hd-s)) calc(6px * var(--hd-s))}}
@media(max-width:768px){.gp-btn{font-size:calc(clamp(.58rem, .5rem + .6vw, .72rem) * var(--hd-s) * 1.015)}}

/* ── NAV BAR ── */
.gp-nav{display:flex;align-items:stretch;width:100%;border-top:1px solid var(--border);border-bottom:1px solid var(--border);margin-top:0}
.gp-nav-item{flex:1 1 0;min-width:0;display:flex;align-items:center;justify-content:center;gap:calc(5px * var(--hd-s));padding:calc(8px * var(--hd-s)) calc(4px * var(--hd-s));
  color:var(--blue);font-size:calc(.7rem * var(--hd-s));font-weight:600;text-decoration:none;white-space:nowrap;overflow:hidden;background:none;border:none;
  position:relative;transition:color .2s,background .2s}
.gp-nav-item:hover{color:var(--blue-hover);background:rgba(36,89,184,.1)}
.gp-nav-item + .gp-nav-item::before{content:'';position:absolute;left:0;top:50%;height:calc(16px * var(--hd-s));width:1px;transform:translateY(-50%);background:rgba(var(--fg),.12)}
.gp-nav-item.active{color:var(--text)}
.gp-nav-item.active::after{content:'';position:absolute;left:22%;right:22%;bottom:0;height:2px;border-radius:2px;background:var(--blue)}
.gp-nav-item img{width:calc(20px * var(--hd-s));height:calc(20px * var(--hd-s));border-radius:50%;object-fit:cover;border:2px solid var(--blue);flex-shrink:0}
.gp-nav-item .cnt{font-size:calc(.58rem * var(--hd-s));padding:1px calc(6px * var(--hd-s));border-radius:4px;background:rgba(36,89,184,.22);color:var(--blue-text)}
.gp-nav-item.locked{opacity:.4;cursor:default}
@media(max-width:490px){.gp-nav-item{padding:calc(7px * var(--hd-s)) calc(3px * var(--hd-s));font-size:calc(.62rem * var(--hd-s));gap:calc(4px * var(--hd-s))}.gp-nav-item img{width:calc(17px * var(--hd-s));height:calc(17px * var(--hd-s))}}

/* ── FILTER BAR ── */
.gp-filterbar{position:sticky;top:calc(var(--topbar-h) - 1px);z-index:400;background:var(--bg);background-attachment:fixed;
  border-bottom:1px solid var(--border);padding:calc(7px * var(--hd-s)) 0}
.gp-filter-scroll{display:flex;align-items:center;gap:calc(7px * var(--hd-s));overflow-x:auto;scrollbar-width:none;-webkit-overflow-scrolling:touch;padding:0 var(--pad-x)}
.gp-filter-scroll::-webkit-scrollbar{display:none}
.gp-fpill{flex:0 0 auto;display:inline-flex;align-items:center;gap:calc(5px * var(--hd-s));height:calc(26px * var(--hd-s));padding:0 calc(11px * var(--hd-s));border-radius:var(--action-r);
  background:rgba(36,89,184,.14);border:1px solid rgba(36,89,184,.5);color:var(--blue-hover);
  font-size:calc(.66rem * var(--hd-s));font-weight:700;line-height:1;white-space:nowrap;text-decoration:none;cursor:pointer;
  transition:background .2s,color .2s,border-color .2s,transform .15s}
.gp-fpill:hover{background:rgba(36,89,184,.26)}
.gp-fpill:active{transform:scale(.96)}
.gp-fpill i{font-size:calc(12px * var(--hd-s))}
.gp-fpill.active-tag{background:var(--blue);border-color:var(--blue);color:#000}
.gp-filter-sep{flex:0 0 1px;height:calc(16px * var(--hd-s));background:rgba(var(--fg),.14)}
@media(max-width:490px){.gp-filterbar{padding:calc(6px * var(--hd-s)) 0}.gp-filter-scroll{gap:calc(6px * var(--hd-s))}.gp-fpill{height:calc(24px * var(--hd-s));padding:0 calc(9px * var(--hd-s));font-size:calc(.62rem * var(--hd-s))}}
.gp-f-arrow{position:absolute;top:50%;transform:translateY(-50%);z-index:10;width:calc(26px * var(--hd-s));height:calc(26px * var(--hd-s));padding:0 0 2px;border-radius:var(--arrow-r);background:rgba(0,0,0,.7);border:1.5px solid rgba(255,255,255,.25);color:#fff;display:flex;align-items:center;justify-content:center;font-size:calc(23px * var(--hd-s));line-height:1;opacity:0;pointer-events:none;transition:opacity .25s ease,background .2s ease}
.gp-f-arrow-l{left:calc(6px * var(--hd-s))}.gp-f-arrow-r{right:calc(6px * var(--hd-s))}
.gp-f-arrow:hover{background:rgba(36,89,184,.9)}
.gp-filterbar:hover .gp-f-arrow:not(.hidden){opacity:1;pointer-events:auto}
.gp-f-arrow.hidden{opacity:0!important;pointer-events:none!important}
@media(max-width:768px){.gp-f-arrow{display:none!important}}

/* ═════════ ABOUT VIEW (the About button swaps it in for the events + feed) ═════════ */
#dsAboutView{display:none}
.ds-page.about-mode #dsAboutView{display:block}
.ds-page.about-mode #dsFeedView{display:none}
.ds-page.about-mode .gp-filterbar{display:none}
.ds-about{background:var(--solid);padding:4px var(--pad-x) 30px;border-top:3px solid var(--post-sep);border-bottom:3px solid var(--post-sep);min-height:60vh}
@media(max-width:768px){.ds-about{padding:calc(4px * 1.1) var(--pad-x) calc(30px * 1.1)}}

/* ═════════ UPCOMING EVENTS (same as the group page) ═════════ */
.gp-ev{background:var(--solid);border-bottom:none;padding:10px 0 2px;margin-top:4px}
.gp-ev-head{display:flex;align-items:center;gap:6px;padding:0 var(--pad-x) 6px;font-size:.66rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:rgba(var(--fg),.62)}
.gp-ev-head i{color:var(--blue-soft);font-size:.9rem}
.gp-ev-count{margin-left:2px;padding:1px 8px;border-radius:20px;background:rgba(36,89,184,.18);border:1px solid rgba(36,89,184,.45);color:var(--blue-text);font-size:.58rem;letter-spacing:0}
.gp-ev-row{display:flex;align-items:flex-start;gap:12px;padding:11px var(--pad-x);text-decoration:none;color:inherit;border-bottom:1px solid var(--border);transition:background .2s}
.gp-ev-row:hover{background:rgba(var(--fg),.035)}
.gp-ev-hold{display:none}
.gp-ev-in{animation:gpEvIn .32s cubic-bezier(.22,1,.36,1) both}
@keyframes gpEvIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
.gp-ev-thumb{position:relative;width:84px;height:84px;border-radius:9px;overflow:hidden;flex-shrink:0;background:linear-gradient(145deg,#010218,#0b1a5c 60%,#1d3f9e);display:flex;align-items:center;justify-content:center}
.gp-ev-thumb .gp-ev-fb{font-size:1.9rem;color:#7fa0ff;opacity:.85}
.gp-ev-thumb img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;display:block}
.gp-ev-info{flex:1;min-width:0;padding-top:1px}
.gp-ev-date{font-size:.7rem;font-weight:600;color:#8aa8ff;margin-bottom:3px}
.gp-ev-title{font-size:.88rem;font-weight:700;color:var(--text);line-height:1.32;margin-bottom:6px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;word-break:break-word}
.gp-ev-type{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:4px;background:rgba(var(--fg),.07);font-size:.64rem;font-weight:600;color:rgba(var(--fg),.72)}
.gp-ev-type i{font-size:.8rem;color:#8aa8ff}
.gp-ev-more{display:block;width:calc(100% - (var(--pad-x) * 2));margin:9px var(--pad-x) 10px;padding:9px;border-radius:var(--action-r);background:rgba(36,89,184,.14);border:1px solid rgba(36,89,184,.5);color:var(--blue-hover);font-size:.72rem;font-weight:700;transition:background .2s,transform .15s}
.gp-ev-more:hover{background:rgba(36,89,184,.26)}
.gp-ev-more:active{transform:scale(.98)}
@media(max-width:490px){.gp-ev-thumb{width:74px;height:74px}.gp-ev-title{font-size:.8rem}.gp-ev-date{font-size:.64rem}.gp-ev-type{font-size:.6rem}}

/* ═════════ FEED — same proportions as the group feed, BLUE theme ═════════ */
#dsFeed{padding:0 0 30px}
#dsFeed > .gf-card-unit:first-child > .gf-post{border-top:none}
.gf-card-unit{display:block}
.gf-post{cursor:pointer;position:relative;background:var(--solid);border-bottom:3px solid var(--post-sep);padding-bottom:calc(12px * var(--gf-s));scroll-margin-top:110px}
/* (the coloured strip down the left edge of pinned posts was removed) */
.gf-post-enter{animation:gfPostFadeIn .45s cubic-bezier(.22,1,.36,1) both}
@keyframes gfPostFadeIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.gf-post.flash{animation:dsFlash 1.6s ease}
@keyframes dsFlash{0%,60%{background:var(--flash,#0b1340)}100%{background:var(--solid)}}

.gf-header{display:flex;align-items:center;gap:calc(10px * var(--gf-s));padding:calc(11px * var(--gf-s)) calc(12px * var(--gf-s))}
.gf-avatar{width:calc(34px * var(--gf-s));height:calc(34px * var(--gf-s));border-radius:50%;object-fit:cover;border:2.6px solid var(--feed-blue);cursor:pointer;flex-shrink:0}
.gf-header-info{flex:1;min-width:0;display:flex;flex-direction:column}
.gf-username-row{display:flex;align-items:center;gap:calc(6px * var(--gf-s));min-width:0;margin-bottom:2px}
.gf-username{font-size:calc(13px * var(--gf-s));font-weight:700;color:var(--text);cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.gf-dot-sep-red{color:var(--feed-blue);font-size:calc(13px * var(--gf-s));flex-shrink:0;line-height:1}
.gf-track-btn{background:none;border:none;padding:0;color:var(--feed-blue);font-size:calc(12px * var(--gf-s));font-weight:700;flex-shrink:0;text-decoration:underline;text-decoration-color:var(--feed-blue);text-underline-offset:2px;text-decoration-thickness:1.5px;position:relative;top:-1px}
.gf-sub-line{display:flex;align-items:center;gap:calc(6px * var(--gf-s));font-size:calc(clamp(10.1px, 9.93px + 0.046vw, 10.6px) * var(--gf-s));color:rgba(var(--fg2),.55);white-space:nowrap;overflow:hidden}
.gf-sub-line i{font-size:calc(11px * var(--gf-s))}
.ds-tagchip{font-size:calc(9px * var(--gf-s));font-weight:700;padding:2px calc(7px * var(--gf-s));border-radius:calc(4px * var(--gf-s));letter-spacing:.3px;flex-shrink:0}
.ds-pinchip{color:var(--blue-soft);font-size:calc(12px * var(--gf-s));flex-shrink:0}
.ds-tagline{display:flex;align-items:center;gap:calc(6px * var(--gf-s));margin-bottom:calc(5px * var(--gf-s))}
.gf-kebab{background:none;border:none;color:var(--text);font-size:calc(22px * var(--gf-s));padding:calc(6px * var(--gf-s)) calc(4px * var(--gf-s)) calc(6px * var(--gf-s)) calc(10px * var(--gf-s));margin-right:calc(-6px * var(--gf-s));flex-shrink:0;position:relative;top:calc(-3px * var(--gf-s));display:flex;align-items:center;justify-content:center;line-height:1}
.gf-kebab i{display:inline-block;transform:scaleX(1.18)}
.gf-kebab:active{transform:scale(1.08)}

.ds-text{padding:0 calc(12px * var(--gf-s)) calc(9px * var(--gf-s))}
.ds-title{font-size:calc(clamp(11px, .37vw + 9.6px, 12.5px) * var(--gf-s));font-weight:700;line-height:1.5;color:var(--text);word-break:break-word;margin-bottom:2px}
.ds-body{font-size:calc(clamp(11px, .37vw + 9.6px, 12.5px) * var(--gf-s));line-height:1.5;color:rgba(var(--fg),.85);word-break:break-word;white-space:pre-wrap}
.ds-body.clamp{display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;overflow:hidden}
.ds-bw{position:relative}
.ds-more{position:absolute;right:0;bottom:0;margin:0;padding:0 0 0 calc(26px * var(--gf-s));border:none;font-size:calc(clamp(11px, .37vw + 9.6px, 12.5px) * var(--gf-s));line-height:1.5;font-weight:700;color:var(--text);background:linear-gradient(90deg,rgba(var(--solid-rgb,7,9,36),0),var(--solid) 24px)}
.ds-bw.open .ds-more{position:static;display:block;padding:0;margin-top:2px;background:none}
.ds-src{display:flex;align-items:center;gap:calc(6px * var(--gf-s));padding:0 calc(12px * var(--gf-s)) calc(9px * var(--gf-s));font-size:calc(11px * var(--gf-s));color:var(--muted);flex-wrap:wrap}
.ds-src a{display:inline-flex;align-items:center;gap:3px;color:var(--feed-blue);font-weight:700;text-decoration:none}
.ds-src a:hover{text-decoration:underline}
.gf-post.gf-post-text .gf-header{border-bottom:1px solid rgba(var(--fg),.13)}
.gf-post.gf-post-text .ds-text{padding-top:calc(12px * var(--gf-s))}

.gf-media-wrap,.gf-media-wrap.gf-multi{position:relative;width:100%;background:#000;overflow:hidden;aspect-ratio:4/4.4;touch-action:pan-y}
.gf-media-track{display:flex;height:100%;transition:transform .28s cubic-bezier(.22,1,.36,1);will-change:transform}
.gf-media-item{flex:0 0 100%;height:100%;position:relative;background:#000}
.gf-media-item img,.gf-media-item video{width:100%;height:100%;object-fit:contain;background:#000;display:block;-webkit-user-drag:none;user-select:none;-webkit-user-select:none}
.gf-dots{position:absolute;left:0;right:0;bottom:calc(10px * var(--gf-s));z-index:6;display:flex;align-items:center;justify-content:center;gap:calc(5px * var(--gf-s));flex-wrap:wrap;row-gap:calc(5px * var(--gf-s))}
.gf-dot{width:calc(5px * var(--gf-s));height:calc(5px * var(--gf-s));border-radius:50%;background:rgba(255,255,255,.35);box-shadow:0 0 3px rgba(0,0,0,.65);transition:background .18s,transform .18s;flex-shrink:0}
.gf-dot.active{background:var(--feed-blue);transform:scale(1.3)}
.gf-media-arrow{position:absolute;top:50%;transform:translateY(-50%);z-index:6;width:calc(32px * var(--gf-s));height:calc(32px * var(--gf-s));padding:0;margin:0;border:none;border-radius:50%;background:rgba(0,0,0,.5);color:#fff;
  display:flex;align-items:center;justify-content:center;overflow:hidden;transition:background .2s,transform .15s}
.gf-media-arrow:hover{background:rgba(0,0,0,.68)}
.gf-media-arrow:active{transform:translateY(-50%) scale(.92)}
.gf-media-arrow i{font-size:calc(20px * var(--gf-s));color:#fff;line-height:1;display:block}
.gf-arrow-left{left:calc(10px * var(--gf-s))}.gf-arrow-right{right:calc(10px * var(--gf-s))}
.gf-top-right-tags{position:absolute;top:calc(10px * var(--gf-s));right:calc(10px * var(--gf-s));z-index:6;display:flex;flex-direction:column;align-items:flex-end;gap:calc(5px * var(--gf-s));pointer-events:none}
.gf-media-counter{background:rgba(22,22,24,.78);color:#fff;font-size:calc(9px * var(--gf-s));font-weight:800;letter-spacing:.3px;padding:calc(3px * var(--gf-s)) calc(7px * var(--gf-s));border-radius:calc(5px * var(--gf-s))}
.gf-type-tag{display:flex;align-items:center;gap:calc(4px * var(--gf-s));background:rgba(22,22,24,.78);border-radius:calc(5px * var(--gf-s));padding:calc(3px * var(--gf-s)) calc(7px * var(--gf-s)) calc(3px * var(--gf-s)) calc(5px * var(--gf-s))}
.gf-type-tag i{font-size:calc(10.5px * var(--gf-s));color:#fff}
.gf-type-tag span{font-size:calc(9px * var(--gf-s));font-weight:700;color:#fff;text-transform:uppercase;letter-spacing:.3px;white-space:nowrap}
.gf-media-controls{position:absolute;bottom:calc(12px * var(--gf-s));right:calc(12px * var(--gf-s));z-index:6;display:flex;flex-direction:column;gap:calc(8px * var(--gf-s))}
.gf-mute-btn{width:calc(28px * var(--gf-s));height:calc(28px * var(--gf-s));padding:0;border-radius:50%;background:rgba(0,0,0,.5);color:#fff;display:flex;align-items:center;justify-content:center;border:none}
.gf-mute-btn i{font-size:calc(14px * var(--gf-s))}
/* mute / unmute icon (custom speaker) */
.dz-snd{display:inline-block;width:1.12em;height:1em;background-color:currentColor;-webkit-mask:var(--dz-snd) center/contain no-repeat;mask:var(--dz-snd) center/contain no-repeat;vertical-align:middle;font-style:normal}
.dz-snd.on{--dz-snd:url(data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%22261%20218%20681%20603%22%3E%3Cpath%20fill-rule%3D%22evenodd%22%20d%3D%22M576.2%2C820.0C560.6%2C817.2%20551.8%2C812.4%20515.6%2C787.7C500.1%2C777.2%20474.4%2C759.8%20458.5%2C749.0C442.6%2C738.3%20425.4%2C726.5%20420.5%2C722.9C415.6%2C719.3%20405.2%2C712.2%20397.5%2C707.0C381.1%2C696.0%20346.4%2C671.6%20333.5%2C661.9C314.1%2C647.4%20292.3%2C621.5%20281.6%2C600.2C258.9%2C555.2%20255.1%2C512.3%20270.0%2C467.9C277.7%2C444.5%20282.6%2C435.5%20299.8%2C412.0C309.3%2C399.1%20335.0%2C374.5%20346.8%2C367.0C365.5%2C355.1%20391.4%2C337.8%20401.0%2C330.6C412.7%2C322.0%20438.1%2C304.3%20460.9%2C289.0C470.3%2C282.7%20487.8%2C270.8%20499.8%2C262.6C559.6%2C221.4%20567.2%2C217.6%20589.5%2C217.6C628.4%2C217.6%20651.1%2C243.1%20654.0%2C290.0C655.3%2C310.7%20655.3%2C742.7%20654.0%2C756.0C649.5%2C802.0%20618.5%2C827.6%20576.2%2C820.0ZM747.9%2C729.0C732.2%2C726.4%20723.6%2C712.1%20725.3%2C691.7C727.0%2C671.8%20735.1%2C664.0%20760.3%2C658.0C794.4%2C649.8%20822.2%2C631.8%20843.7%2C603.9C862.9%2C578.9%20876.0%2C533.5%20872.1%2C504.8C869.5%2C485.1%20856.5%2C452.0%20846.5%2C439.5C819.9%2C406.3%20796.9%2C390.9%20760.7%2C382.0C736.8%2C376.1%20727.9%2C367.8%20724.9%2C348.7C721.1%2C324.7%20735.0%2C309.3%20758.5%2C311.4C807.1%2C315.8%20864.9%2C348.4%20895.2%2C388.5C915.9%2C416.0%20920.6%2C425.4%20936.6%2C471.0C943.6%2C491.1%20944.3%2C540.8%20938.0%2C565.7C923.1%2C623.6%20884.6%2C673.9%20832.7%2C702.9C803.4%2C719.3%20763.6%2C731.6%20747.9%2C729.0ZM738.0%2C584.8C732.1%2C581.6%20727.6%2C574.3%20726.0%2C565.2C724.3%2C556.1%20724.4%2C479.3%20726.1%2C472.4C737.4%2C425.3%20805.6%2C472.9%20801.7%2C525.2C799.0%2C559.4%20759.6%2C596.3%20738.0%2C584.8Z%22%2F%3E%3C%2Fsvg%3E)}
.dz-snd.off{--dz-snd:url(data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%22261%20218%20681%20603%22%3E%3Cpath%20d%3D%22M576.2%2C820.0C560.6%2C817.2%20551.8%2C812.4%20515.6%2C787.7C500.1%2C777.2%20474.4%2C759.8%20458.5%2C749.0C442.6%2C738.3%20425.4%2C726.5%20420.5%2C722.9C415.6%2C719.3%20405.2%2C712.2%20397.5%2C707.0C381.1%2C696.0%20346.4%2C671.6%20333.5%2C661.9C314.1%2C647.4%20292.3%2C621.5%20281.6%2C600.2C258.9%2C555.2%20255.1%2C512.3%20270.0%2C467.9C277.7%2C444.5%20282.6%2C435.5%20299.8%2C412.0C309.3%2C399.1%20335.0%2C374.5%20346.8%2C367.0C365.5%2C355.1%20391.4%2C337.8%20401.0%2C330.6C412.7%2C322.0%20438.1%2C304.3%20460.9%2C289.0C470.3%2C282.7%20487.8%2C270.8%20499.8%2C262.6C559.6%2C221.4%20567.2%2C217.6%20589.5%2C217.6C628.4%2C217.6%20651.1%2C243.1%20654.0%2C290.0C655.3%2C310.7%20655.3%2C742.7%20654.0%2C756.0C649.5%2C802.0%20618.5%2C827.6%20576.2%2C820.0Z%22%2F%3E%3Cpath%20d%3D%22M730%20400L910%20620M910%20400L730%20620%22%20stroke%3D%22%23000%22%20stroke-width%3D%2264%22%20stroke-linecap%3D%22round%22%20fill%3D%22none%22%2F%3E%3C%2Fsvg%3E)}
.gf-double-tap-heart{position:absolute;z-index:14;pointer-events:none;transform:translate(-50%,-50%) scale(0);opacity:0}
.gf-double-tap-heart i{font-size:calc(84px * var(--gf-s));color:var(--feed-blue);filter:drop-shadow(0 6px 20px rgba(var(--feed-blue-rgb),.6));-webkit-text-stroke:1.3px rgba(255,255,255,.32)}
.gf-double-tap-heart.show{animation:gfHeartBurst .95s cubic-bezier(.17,.89,.32,1.28) forwards}
@keyframes gfHeartBurst{0%{transform:translate(-50%,-50%) scale(0) rotate(-28deg);opacity:0}14%{transform:translate(-50%,-50%) scale(1.35) rotate(10deg);opacity:1}28%{transform:translate(-50%,-50%) scale(.88) rotate(-6deg);opacity:1}42%{transform:translate(-50%,-50%) scale(1.14) rotate(4deg);opacity:1}58%{transform:translate(-50%,-50%) scale(.97);opacity:1}75%{transform:translate(-50%,-50%) scale(1.05);opacity:1}100%{transform:translate(-50%,-50%) scale(1.15) translateY(-40px);opacity:0}}
.gf-heart-ring{position:absolute;z-index:13;pointer-events:none;width:calc(95px * var(--gf-s));height:calc(95px * var(--gf-s));margin-left:calc(-47.5px * var(--gf-s));margin-top:calc(-47.5px * var(--gf-s));border-radius:50%;border:3px solid rgba(var(--feed-blue-rgb),.62);transform:scale(.25);opacity:0}
.gf-heart-ring.show{animation:gfRingPulse .75s cubic-bezier(.15,.6,.35,1) forwards}
@keyframes gfRingPulse{0%{transform:scale(.25);opacity:.9;border-width:4px}100%{transform:scale(2.3);opacity:0;border-width:1px}}
.gf-heart-particle{position:absolute;z-index:12;pointer-events:none;left:0;top:0;transform:translate(-50%,-50%) scale(0);opacity:0;color:var(--feed-blue)}
.gf-heart-particle.show{animation:gfParticleFly .8s cubic-bezier(.2,.7,.3,1) forwards}
@keyframes gfParticleFly{0%{transform:translate(-50%,-50%) scale(0);opacity:0}12%{opacity:1}100%{transform:translate(calc(-50% + var(--tx)),calc(-50% + var(--ty))) scale(1) rotate(var(--tr));opacity:0}}
.gf-heart-particle i.gf-hs0{font-size:calc(12px * var(--gf-s))}.gf-heart-particle i.gf-hs1{font-size:calc(14px * var(--gf-s))}.gf-heart-particle i.gf-hs2{font-size:calc(16px * var(--gf-s))}
.gf-heart-particle i.gf-hs3{font-size:calc(18px * var(--gf-s))}.gf-heart-particle i.gf-hs4{font-size:calc(20px * var(--gf-s))}.gf-heart-particle i.gf-hs5{font-size:calc(22px * var(--gf-s))}

.gf-actions{display:flex;align-items:center;justify-content:space-between;padding:calc(9px * var(--gf-s)) calc(10px * var(--gf-s)) 0;position:relative}
.gf-actions-left{display:flex;align-items:center;gap:calc(18px * var(--gf-s))}
.gf-action-group{display:flex;align-items:center;gap:calc(6px * var(--gf-s))}
.gf-action-btn{background:none;border:none;color:var(--text);font-size:calc(22px * var(--gf-s));padding:2px;display:flex;align-items:center;transition:transform .12s ease}
.gf-action-btn:active{transform:scale(1.22)}
.gf-action-btn .gf-ic-on{display:none}
.gf-action-group.active .gf-action-btn .gf-ic-on{display:inline}
.gf-action-group.active .gf-action-btn .gf-ic-off{display:none}
.gf-action-group.active .gf-action-btn{color:var(--feed-blue)}
.gf-action-count{font-size:calc(12px * var(--gf-s));font-weight:700;color:var(--text)}
.gf-action-btn.off{opacity:.4}
.gf-time{padding:calc(6px * var(--gf-s)) calc(12px * var(--gf-s)) 0;font-size:calc(10px * var(--gf-s));color:rgba(var(--fg2),.4);letter-spacing:.3px}

/* ── POLL / QUIZ ── */
.ds-poll{margin:0 calc(12px * var(--gf-s)) calc(10px * var(--gf-s));padding:calc(10px * var(--gf-s));border-radius:4px;background:linear-gradient(160deg,rgba(43,99,209,.12),rgba(43,99,209,.03));border:1px solid rgba(43,99,209,.32)}
.ds-poll.quiz{background:linear-gradient(160deg,rgba(124,92,255,.12),rgba(124,92,255,.02));border-color:rgba(124,92,255,.32)}
.dp-hdr{display:flex;align-items:center;justify-content:space-between;margin-bottom:calc(7px * var(--gf-s))}
.dp-badge{display:inline-flex;align-items:center;gap:calc(6px * var(--gf-s));font-size:calc(10.5px * var(--gf-s));font-weight:800;color:var(--blue-soft);text-transform:uppercase;letter-spacing:.5px}
.ds-poll.quiz .dp-badge{color:#b0a3ff}
.dp-timer{display:inline-flex;align-items:center;gap:calc(5px * var(--gf-s));font-size:calc(10px * var(--gf-s));color:var(--muted);font-weight:600}
.dp-opts{display:flex;flex-direction:column;gap:calc(5px * var(--gf-s))}
.dp-opt{position:relative;display:flex;align-items:center;gap:calc(8px * var(--gf-s));padding:calc(6px * var(--gf-s)) calc(10px * var(--gf-s));border-radius:3px;background:rgba(var(--fg),.04);border:1px solid var(--border);overflow:hidden;transition:border-color .2s,background .2s}
label.dp-opt{cursor:pointer}
label.dp-opt:hover{border-color:rgba(43,99,209,.6);background:rgba(43,99,209,.08)}
.dp-opt input{display:none}
.dp-opt:has(input:checked){border-color:var(--feed-blue);background:rgba(43,99,209,.16)}
.dp-bar{position:absolute;left:0;top:0;height:100%;background:rgba(43,99,209,.2);transition:width .5s ease;pointer-events:none}
.dp-txt{position:relative;z-index:1;flex:1;font-size:calc(11.5px * var(--gf-s));font-weight:600;color:var(--text)}
.dp-pct{position:relative;z-index:1;font-size:calc(10.5px * var(--gf-s));font-weight:800;color:var(--blue-soft);min-width:calc(34px * var(--gf-s));text-align:right}
.dp-ic{position:relative;z-index:1;font-size:calc(13px * var(--gf-s))}
.dp-opt.picked{border-color:var(--feed-blue)}
.dp-opt.correct{border-color:var(--green);background:rgba(61,220,132,.08)}
.dp-opt.correct .dp-bar{background:rgba(61,220,132,.2)}
.dp-opt.correct .dp-pct,.dp-opt.correct .dp-ic{color:var(--green)}
.dp-opt.wrong{border-color:rgba(255,80,80,.5);background:rgba(255,80,80,.06)}
.dp-opt.wrong .dp-bar{background:rgba(255,80,80,.16)}
.dp-opt.wrong .dp-ic{color:#ff6b6b}
.dp-submit{width:100%;margin-top:calc(8px * var(--gf-s));padding:calc(7px * var(--gf-s));border:none;border-radius:var(--btn-r);background:var(--blue);color:#000;font-size:calc(11px * var(--gf-s));font-weight:700;transition:background .2s,transform .15s}
.dp-submit:hover{background:var(--blue-hover)}.dp-submit:active{transform:scale(.98)}
.dp-note{text-align:center;font-size:calc(10.5px * var(--gf-s));color:var(--muted);margin-top:calc(7px * var(--gf-s));font-weight:600}
.dp-note b{color:var(--green)}

/* hidden-post placeholder + skeleton + empty */
.gf-hidden-placeholder{display:flex;align-items:center;justify-content:space-between;gap:calc(12px * var(--gf-s));width:100%;min-height:calc(56px * var(--gf-s));background:#010723;padding:calc(12px * var(--gf-s)) calc(14px * var(--gf-s)) calc(12px * var(--gf-s)) calc(18px * var(--gf-s));border-bottom:3px solid var(--post-sep);animation:gfPostFadeIn .3s ease both}
.gf-hidden-left{display:flex;align-items:center;gap:calc(9px * var(--gf-s));min-width:0}
.gf-hidden-icon{width:calc(30px * var(--gf-s));height:calc(30px * var(--gf-s));border-radius:50%;background:rgba(var(--feed-blue-rgb),.12);border:1.5px solid rgba(var(--feed-blue-rgb),.4);display:flex;align-items:center;justify-content:center;font-size:calc(14px * var(--gf-s));color:var(--feed-blue);flex-shrink:0}
.gf-hidden-text{font-size:calc(13px * var(--gf-s));font-weight:600;color:rgba(var(--fg),.75)}
.gf-undo-btn{display:inline-flex;align-items:center;gap:calc(5px * var(--gf-s));background:rgba(var(--fg),.07);border:1px solid rgba(var(--fg),.15);color:var(--text);font-size:calc(12px * var(--gf-s));font-weight:700;padding:calc(7px * var(--gf-s)) calc(15px * var(--gf-s));border-radius:var(--action-r);flex-shrink:0}
.gf-undo-btn:active{transform:scale(.96)}
.gf-skel-post{border-bottom:3px solid var(--post-sep);padding-bottom:calc(12px * var(--gf-s));background:var(--solid)}
.gf-skel-header{display:flex;align-items:center;gap:calc(10px * var(--gf-s));padding:calc(11px * var(--gf-s)) calc(12px * var(--gf-s))}
.gf-skel-avatar{width:calc(34px * var(--gf-s));height:calc(34px * var(--gf-s));border-radius:50%;flex-shrink:0}
.gf-skel-header-lines{flex:1;display:flex;flex-direction:column;gap:calc(6px * var(--gf-s))}
.gf-skel-line{height:calc(10px * var(--gf-s));border-radius:calc(5px * var(--gf-s))}.gf-skel-line.w1{width:calc(120px * var(--gf-s))}.gf-skel-line.w2{width:calc(75px * var(--gf-s));height:calc(8px * var(--gf-s))}
.gf-skel-media{width:100%;aspect-ratio:4/3}
.gf-skel-actions{display:flex;gap:calc(18px * var(--gf-s));padding:calc(9px * var(--gf-s)) calc(12px * var(--gf-s)) 0}.gf-skel-icon{width:calc(22px * var(--gf-s));height:calc(22px * var(--gf-s));border-radius:calc(6px * var(--gf-s))}
.gf-skel-cap{margin:calc(11px * var(--gf-s)) calc(12px * var(--gf-s)) 0;height:calc(10px * var(--gf-s));border-radius:calc(5px * var(--gf-s));width:82%}
.gf-skel-avatar,.gf-skel-line,.gf-skel-media,.gf-skel-icon,.gf-skel-cap{background:linear-gradient(90deg,rgba(var(--fg),.05) 25%,rgba(var(--fg),.13) 37%,rgba(var(--fg),.05) 63%);background-size:400% 100%;animation:gfSkelShimmer 1.4s ease-in-out infinite}
@keyframes gfSkelShimmer{0%{background-position:100% 50%}100%{background-position:0% 50%}}
.gf-load-more{display:flex;align-items:center;justify-content:center;padding:calc(24px * var(--gf-s)) 0}
.gf-load-more-dot{width:calc(6px * var(--gf-s));height:calc(6px * var(--gf-s));border-radius:50%;background:rgba(var(--fg),.35);margin:0 calc(3px * var(--gf-s));animation:gfLoadDot 1.1s ease-in-out infinite}
.gf-load-more-dot:nth-child(2){animation-delay:.15s}.gf-load-more-dot:nth-child(3){animation-delay:.3s}
@keyframes gfLoadDot{0%,80%,100%{transform:scale(.6);opacity:.4}40%{transform:scale(1);opacity:1}}
.gf-empty{padding:calc(70px * var(--gf-s)) calc(24px * var(--gf-s));text-align:center;color:rgba(var(--fg2),.6);font-size:calc(13.5px * var(--gf-s));font-weight:500;line-height:1.6}
.gf-empty i{font-size:calc(42px * var(--gf-s));display:block;margin-bottom:10px;color:rgba(var(--fg),.25)}
.gf-empty button{margin-top:14px;padding:0 22px;height:var(--action-h);border:none;border-radius:var(--btn-r);background:var(--blue);color:#000;font-size:calc(.72rem * var(--hd-s));font-weight:700}

/* ── feed responsive (790 / 490 / 390 / 330) — same breakpoints as the group page ── */
@media(max-width:790px){
  .gf-header{padding:calc(10px * var(--gf-s)) calc(11px * var(--gf-s));gap:calc(9px * var(--gf-s))}.gf-avatar{width:calc(32px * var(--gf-s));height:calc(32px * var(--gf-s))}.gf-username{font-size:calc(12.5px * var(--gf-s))}
  .gf-sub-line{font-size:calc(10.04px * var(--gf-s))}.gf-actions{padding:calc(8px * var(--gf-s)) calc(9px * var(--gf-s)) 0}.gf-action-btn{font-size:calc(21px * var(--gf-s))}.gf-actions-left{gap:calc(15px * var(--gf-s))}
  .ds-text,.ds-src,.gf-time{padding-left:calc(11px * var(--gf-s));padding-right:calc(11px * var(--gf-s))}
  .ds-poll{margin-left:calc(11px * var(--gf-s));margin-right:calc(11px * var(--gf-s))}
  .gf-double-tap-heart i{font-size:calc(70px * var(--gf-s))}
}
@media(max-width:490px){
  .gf-header{padding:calc(9px * var(--gf-s)) calc(10px * var(--gf-s));gap:calc(8px * var(--gf-s))}.gf-avatar{width:calc(29px * var(--gf-s));height:calc(29px * var(--gf-s))}.gf-username{font-size:calc(12px * var(--gf-s))}.gf-track-btn{font-size:calc(11px * var(--gf-s))}
  .gf-kebab{font-size:calc(20px * var(--gf-s))}.gf-actions{padding:calc(7px * var(--gf-s)) calc(8px * var(--gf-s)) 0}.gf-actions-left{gap:calc(13px * var(--gf-s))}.gf-action-btn{font-size:calc(19px * var(--gf-s))}.gf-action-count{font-size:calc(11px * var(--gf-s))}
  .ds-text,.ds-src,.gf-time{padding-left:calc(10px * var(--gf-s));padding-right:calc(10px * var(--gf-s))}
  .ds-poll{margin-left:calc(10px * var(--gf-s));margin-right:calc(10px * var(--gf-s));padding:calc(9px * var(--gf-s))}
  .gf-media-arrow{width:calc(26px * var(--gf-s));height:calc(26px * var(--gf-s))}.gf-media-arrow i{font-size:calc(16px * var(--gf-s))}.gf-double-tap-heart i{font-size:calc(60px * var(--gf-s))}
  .gf-top-right-tags{top:calc(8px * var(--gf-s));right:calc(8px * var(--gf-s))}
  .gf-media-controls{right:calc(10px * var(--gf-s));bottom:calc(10px * var(--gf-s))}
}
@media(max-width:390px){
  .gf-avatar{width:calc(27px * var(--gf-s));height:calc(27px * var(--gf-s))}.gf-username{font-size:calc(11.5px * var(--gf-s))}.gf-action-btn{font-size:calc(18px * var(--gf-s))}.gf-actions-left{gap:calc(11px * var(--gf-s))}.dp-txt{font-size:calc(11px * var(--gf-s))}
}
@media(max-width:330px){
  .gf-avatar{width:calc(25px * var(--gf-s));height:calc(25px * var(--gf-s))}.gf-action-btn{font-size:calc(17px * var(--gf-s))}.gf-actions-left{gap:calc(9px * var(--gf-s))}.dp-txt{font-size:calc(10.5px * var(--gf-s))}
}

/* ── PAGE SKELETON ── */
#dsSkel{position:fixed;inset:0;z-index:900;background:var(--bg);background-attachment:fixed;overflow:hidden;transition:opacity .35s ease,visibility .35s}
#dsSkel.hide{opacity:0;visibility:hidden;pointer-events:none}
.sk-shell{display:flex;justify-content:center;width:100%;height:100%}
.sk-page{width:100%;max-width:var(--max-w);margin:0 auto;height:100%}
@media(min-width:769px){.sk-page{border-left:1px solid var(--border);border-right:1px solid var(--border)}}
.sk{background:linear-gradient(90deg,rgba(var(--fg),.05) 25%,rgba(var(--fg),.12) 50%,rgba(var(--fg),.05) 75%);background-size:200% 100%;animation:gpShim 1.3s linear infinite;border-radius:6px}
@keyframes gpShim{to{background-position:-200% 0}}
.sk-banner{width:100%;height:var(--banner-h);border-radius:0}
.sk-head{padding:12px var(--pad-x) 0}
.sk-line{height:10px;margin-top:8px}
.sk-actions{display:flex;gap:8px;padding:12px 0 10px}.sk-actions .sk{flex:1;height:var(--action-h);border-radius:var(--btn-r)}
.sk-nav{display:flex;border-top:1px solid var(--border);border-bottom:1px solid var(--border)}
.sk-nav div{flex:1;padding:12px 0;display:flex;justify-content:center}.sk-nav .sk{width:55%;height:9px}
.sk-filter{display:flex;gap:7px;padding:8px var(--pad-x);border-bottom:1px solid var(--border)}.sk-filter .sk{height:26px;width:84px;flex:0 0 auto;border-radius:4px}
.sk-post{padding-bottom:12px;border-bottom:3px solid var(--post-sep);margin-top:12px}
.sk-post-h{display:flex;align-items:center;gap:10px;padding:11px var(--pad-x)}
.sk-post-h .a{width:34px;height:34px;border-radius:50%}.sk-post-h .l{height:10px;width:110px}
.sk-post-m{width:100%;aspect-ratio:4/3;border-radius:0}

/* ══════════════════════════════════════════════════════════════
   FULL-SCREEN MODALS — same design as the group page modals
   ══════════════════════════════════════════════════════════════ */
.ds-ov{
  --pm-blue:#2a5fd8; --pm-blue-h:#234fb5; --pm-blue-l:#7ea6ff;
  --pm-blue-dim:rgba(42,95,216,.18); --pm-blue-glow:rgba(42,95,216,.34);
  --pm-on-blue:#000;
  --pm-card:rgba(var(--fg),.05); --pm-card2:rgba(var(--fg),.08);
  --pm-line:rgba(var(--fg),.09); --pm-line2:rgba(var(--fg),.14);
  --pm-sub:rgba(var(--fg),.62); --pm-mut:rgba(var(--fg),.45);
  --pm-pad:clamp(9px,3vw,16px);
  --pm-act-h:clamp(25px,7.2vw,30px);
  --pm-r:5px; --pm-fs:.8rem; --pm-tb-h:54px;
  display:none;position:fixed;inset:0;z-index:999999999;
  background:linear-gradient(-45deg,#010218,#070924,#070924);background-attachment:fixed;
  justify-content:center;overflow:hidden;font-size:var(--pm-fs);color:var(--text);-webkit-font-smoothing:antialiased}
@media(max-width:490px){.ds-ov{--pm-fs:.74rem;--pm-tb-h:48px}}
@media(max-width:390px){.ds-ov{--pm-fs:.7rem;--pm-tb-h:44px}}
.ds-ov.open{display:flex;animation:dzIn .28s cubic-bezier(.22,1,.36,1)}
@keyframes dzIn{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
.ds-ov *,.ds-ov *::before,.ds-ov *::after{box-sizing:border-box}
.ds-ov button{cursor:pointer;-webkit-tap-highlight-color:transparent;outline:none}
.ds-ov *{scrollbar-width:none;-ms-overflow-style:none}
.ds-ov ::-webkit-scrollbar{display:none;width:0;height:0;background:transparent}

.dz-lane{width:100%;max-width:580px;height:100%;display:flex;flex-direction:column;min-height:0;margin:0}
@media(min-width:801px){.dz-lane{border-left:1px solid var(--pm-line);border-right:1px solid var(--pm-line)}}
@media(max-width:800px){.dz-lane{max-width:100%}}

.dz-tb{flex-shrink:0;display:flex;align-items:center;min-height:var(--pm-tb-h);padding:env(safe-area-inset-top) 10px 0;background:transparent}
.dz-side{flex:0 0 38px;display:flex;align-items:center}
.dz-side.right{justify-content:flex-end}
.dz-x{width:34px;height:34px;background:none;border:none;color:var(--text);display:flex;align-items:center;justify-content:center;opacity:.9;padding:0;transition:.2s}
.dz-x:hover{color:var(--pm-blue-l);opacity:1}
.dz-x svg{width:22px;height:22px;display:block}
.dz-tc{flex:1;min-width:0;text-align:center}
.dz-tt{font-size:.92rem;font-weight:700;color:var(--text);line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
@media(max-width:490px){.dz-tt{font-size:.84rem}.dz-x{width:30px;height:30px}}
@media(max-width:390px){.dz-tt{font-size:.8rem}}

.dz-body{flex:1;min-height:0;overflow-y:auto;overflow-x:hidden;-webkit-overflow-scrolling:touch;padding:4px var(--pm-pad) 18px}
@media(min-width:801px) and (hover:hover) and (pointer:fine){
  .dz-body{scrollbar-width:thin;scrollbar-color:rgba(42,95,216,.45) transparent}
  .dz-body::-webkit-scrollbar{display:block;width:4px}
  .dz-body::-webkit-scrollbar-thumb{background:rgba(42,95,216,.45);border-radius:99px}
}
.dz-note{font-size:.66rem;color:var(--pm-mut);line-height:1.5;margin:6px 0 2px}
.dz-group{display:flex;align-items:center;gap:6px;margin:16px 0 9px;padding-top:14px;border-top:1px solid var(--pm-line);font-size:.64rem;font-weight:600;letter-spacing:.6px;text-transform:uppercase;color:var(--pm-sub)}
.dz-note + .dz-group{margin-top:10px;padding-top:0;border-top:none}

.dz-btn{display:flex;align-items:center;justify-content:center;gap:6px;flex:1;min-width:0;height:var(--pm-act-h);padding:0 14px;border:none;border-radius:var(--pm-r);background:var(--pm-blue);color:var(--pm-on-blue);font-size:.79rem;font-weight:700;line-height:1;white-space:nowrap;text-decoration:none;transition:background .2s ease,transform .15s ease,box-shadow .2s ease}
.dz-btn:hover{background:var(--pm-blue-h);box-shadow:0 4px 16px var(--pm-blue-glow)}
.dz-btn:active{transform:scale(.98)}
.dz-btn:disabled{opacity:.5;cursor:not-allowed;box-shadow:none;pointer-events:none}
.dz-btn.ghost{background:rgba(var(--fg),.1);color:var(--text);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);box-shadow:none}
.dz-btn.ghost:hover{background:rgba(var(--fg),.2)}
.dz-btn i{font-size:14px}
.dz-act{flex-shrink:0;display:flex;gap:8px;padding:8px var(--pm-pad) calc(10px + env(safe-area-inset-bottom))}
.dz-act .dz-btn.main{flex:1.4}
@media(max-width:490px){.dz-btn{font-size:.68rem;padding:0 10px;gap:4px}.dz-btn i{font-size:12px}}

.dz-option{display:flex;align-items:center;gap:10px;padding:8px;margin-bottom:6px;border-radius:8px;background:var(--pm-card);border:1px solid var(--pm-line);text-decoration:none;color:inherit;transition:.2s}
.dz-option:hover{background:rgba(var(--fg),.08)}
.dz-option:active{transform:scale(.99)}
.dz-option.selected,.dz-option.selected:hover{background:#1b3873;border-color:#1b3873}
.dz-oi{width:34px;height:34px;border-radius:6px;background:var(--pm-blue-dim);display:flex;align-items:center;justify-content:center;color:var(--pm-blue-l);font-size:15px;flex-shrink:0}
.dz-option.selected .dz-oi{background:rgba(255,255,255,.1);color:#fff}
.dz-ot{flex:1;min-width:0}
.dz-on{font-size:.74rem;font-weight:600;color:var(--text);line-height:1.25;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dz-od{font-size:.64rem;color:var(--pm-mut);margin-top:2px}
.dz-ock{width:20px;height:20px;border-radius:5px;border:1.5px solid var(--pm-line2);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:12px;font-weight:700;color:transparent}
.dz-option.selected .dz-ock{background:none;border-color:transparent;color:#fff}

.dz-stop{flex-shrink:0;display:flex;align-items:center;gap:8px;min-height:var(--pm-tb-h);padding:calc(env(safe-area-inset-top) + 14px) var(--pm-pad) 6px 0}
.dz-back{width:42px;height:42px;flex-shrink:0;background:none;border:none;color:var(--text);display:flex;align-items:center;justify-content:center;font-size:28px;padding:0;margin-left:2px;opacity:.9;transition:.2s}
.dz-back:hover{color:var(--pm-blue-l);opacity:1}
.dz-swrap{flex:1;min-width:0;display:flex;align-items:center;height:clamp(33px,9vw,38px);background:var(--pm-card);border:1px solid var(--pm-line2);border-radius:var(--pm-r);overflow:hidden;transition:border-color .2s,box-shadow .2s}
.dz-swrap:focus-within{border-color:rgba(42,95,216,.85);box-shadow:0 0 0 3px rgba(42,95,216,.18)}
.dz-swrap i{padding:0 6px 0 12px;color:var(--pm-mut);font-size:16px}
.dz-swrap input{flex:1;min-width:0;height:100%;padding:0 10px 0 0;background:transparent;border:none;outline:none;color:var(--text);font-size:.8rem}
.dz-swrap input::placeholder{color:var(--pm-mut)}
.dz-sbtn{flex-shrink:0;height:clamp(33px,9vw,38px);padding:0 16px;border:none;border-radius:var(--pm-r);background:var(--pm-blue);color:#fff;font-size:.79rem;font-weight:700;transition:background .2s,transform .15s}
.dz-sbtn:hover{background:var(--pm-blue-h)}
.dz-sbtn:active{transform:scale(.98)}
.dz-ctx{display:flex;align-items:center;gap:7px;margin:8px var(--pm-pad) 0;padding:0 11px;min-height:var(--pm-act-h);font-size:.7rem;font-weight:500;color:var(--pm-sub);background:var(--pm-blue-dim);border:1px solid rgba(42,95,216,.35);border-radius:var(--pm-r);flex-shrink:0}
.dz-ctx i{font-size:14px;color:var(--pm-blue-l);flex-shrink:0}
.dz-ctx span{min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dz-ctx strong{color:var(--text);font-weight:700}
.dz-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:64px 20px;gap:8px;color:var(--pm-mut);text-align:center}
.dz-empty i{font-size:36px;color:rgba(126,166,255,.45)}
.dz-empty p{font-size:.8rem;font-weight:500;color:var(--text)}
.dz-empty small{font-size:.66rem}
@media(max-width:490px){.dz-back{width:38px;height:38px}.dz-swrap input{font-size:.74rem}.dz-sbtn{font-size:.68rem;padding:0 10px}.dz-ctx{font-size:.64rem}}

.dz-field{margin-top:14px}
.dz-lbl{display:flex;align-items:center;gap:6px;margin-bottom:8px;font-size:.64rem;font-weight:600;letter-spacing:.6px;text-transform:uppercase;color:var(--pm-sub)}
.dz-inp,.dz-ta,.dz-sel{width:100%;height:var(--pm-act-h);padding:0 11px;border-radius:var(--pm-r);border:1px solid var(--pm-line2);background:var(--pm-card);color:var(--text);font-size:.76rem;transition:.2s;-webkit-appearance:none;appearance:none;outline:none;color-scheme:dark}
.dz-inp:focus,.dz-ta:focus,.dz-sel:focus{border-color:rgba(42,95,216,.85);box-shadow:0 0 0 3px rgba(42,95,216,.18)}
.dz-inp::placeholder,.dz-ta::placeholder{color:var(--pm-mut)}
.dz-ta{height:auto;min-height:84px;padding:9px 11px;line-height:1.6;resize:none;font-size:.82rem}
.dz-sel option{background:#0a0c38}
@media(max-width:490px){.dz-inp,.dz-sel{font-size:.7rem}.dz-ta{font-size:.76rem}}
.ds-tags{display:flex;flex-wrap:wrap;gap:7px}
.ds-tagpill{height:var(--pm-act-h);padding:0 12px;border:none;border-radius:var(--pm-r);background:rgba(var(--fg),.1);color:var(--text);font-size:.74rem;font-weight:600;transition:.2s}
.ds-tagpill:hover{background:rgba(var(--fg),.2)}
.ds-tagpill.sel{background:var(--pm-blue);color:var(--pm-on-blue)}
@media(max-width:490px){.ds-tagpill{font-size:.66rem;padding:0 10px}}
.ds-ptype{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.ds-ptc{padding:12px 10px;border-radius:8px;border:1px solid var(--pm-line);background:var(--pm-card);text-align:center;transition:.2s;color:var(--text)}
.ds-ptc i{display:block;font-size:20px;color:var(--pm-mut);margin-bottom:5px}
.ds-ptc b{display:block;font-size:.72rem}
.ds-ptc small{display:block;font-size:.6rem;color:var(--pm-mut);margin-top:2px}
.ds-ptc.sel{border-color:rgba(42,95,216,.85);background:var(--pm-blue-dim)}
.ds-ptc.sel i{color:var(--pm-blue-l)}
.ds-popt{display:flex;align-items:center;gap:8px;margin-bottom:7px}
.ds-popt input[type=radio]{accent-color:var(--pm-blue);width:16px;height:16px;flex-shrink:0}
.ds-addopt{border:none;background:none;color:var(--pm-blue-l);font-size:.72rem;font-weight:600;padding:4px 0}
.ds-qhint{display:none;align-items:center;gap:6px;font-size:.64rem;color:var(--pm-blue-l);margin-top:6px}
.ds-attach{display:flex;align-items:center;gap:8px;padding:12px 14px;border-radius:10px;border:1.5px dashed var(--pm-line2);background:var(--pm-card);color:var(--text);font-size:.76rem;cursor:pointer;transition:.2s}
.ds-attach:hover{border-color:var(--pm-blue);background:var(--pm-blue-dim)}
.ds-attach i{color:var(--pm-blue-l);font-size:18px}
.ds-attach input{display:none}
.ds-prev{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}
.ds-thumb{position:relative;width:58px;height:58px;border-radius:8px;overflow:hidden;border:2px solid var(--pm-line2);background:#000}
.ds-thumb img,.ds-thumb video{width:100%;height:100%;object-fit:cover;display:block}
.ds-thumb.existing{border-color:var(--pm-blue)}
.ds-thumb button{position:absolute;top:3px;right:3px;width:17px;height:17px;border-radius:4px;background:rgba(0,0,0,.8);border:none;color:#fff;font-size:9px;display:flex;align-items:center;justify-content:center;padding:0}
.ds-thumb button:hover{background:var(--pm-blue);color:#000}
.ds-thumb em{position:absolute;bottom:3px;left:3px;font-style:normal;font-size:7.5px;font-weight:800;padding:1px 5px;border-radius:4px;background:var(--pm-blue);color:#000;text-transform:uppercase}
.ds-prefill{display:flex;align-items:center;gap:7px;font-size:.66rem;color:var(--pm-blue-l);background:var(--pm-blue-dim);border:1px solid rgba(42,95,216,.35);border-radius:var(--pm-r);padding:8px 10px;margin-bottom:9px}
.ds-toggle{display:flex;align-items:center;gap:10px;padding:10px 12px;background:var(--pm-card);border:1px solid var(--pm-line);border-radius:8px}
.ds-toggle label{flex:1;font-size:.74rem;color:rgba(var(--fg),.8);cursor:pointer;display:flex;align-items:center;gap:7px}
.ds-toggle label i{color:var(--pm-blue-l)}
.ds-toggle input{accent-color:var(--pm-blue);width:16px;height:16px}

.dz-hero{display:flex;align-items:center;justify-content:space-between;gap:14px;margin:8px 0 18px}
.dz-tabs{display:flex;flex-direction:column;gap:8px;flex:0 1 190px;min-width:0}
.dz-pill{display:flex;align-items:center;justify-content:center;gap:6px;width:100%;height:var(--pm-act-h);padding:0 14px;border:none;border-radius:var(--pm-r);background:#1b3a85;color:#fff;font-size:.79rem;font-weight:700;line-height:1;white-space:nowrap;transition:background .2s,transform .15s}
.dz-pill i{font-size:14px}
.dz-pill:hover{background:#234799}
.dz-pill:active{transform:scale(.98)}
.dz-pill.active{background:var(--pm-blue)}
.dz-pill.active:hover{background:var(--pm-blue-h)}
@media(max-width:490px){.dz-pill{font-size:.68rem;padding:0 10px;gap:4px}.dz-pill i{font-size:12px}.dz-tabs{flex-basis:160px}}
.dz-bigpic{width:clamp(78px,22vw,104px);height:clamp(78px,22vw,104px);border-radius:50%;object-fit:cover;display:block;flex-shrink:0;border:3px solid var(--pm-blue)}
.dz-tab{display:none}.dz-tab.active{display:block}
.dz-stats{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px;margin-bottom:10px}
.dz-stat{background:var(--pm-card);border:1px solid var(--pm-line);border-radius:var(--pm-r);height:var(--pm-act-h);padding:0 10px;display:flex;align-items:center;gap:7px;min-width:0}
.dz-stat b{font-size:.86rem;font-weight:700;color:var(--text);line-height:1;flex-shrink:0}
.dz-stat span{font-size:.62rem;color:var(--pm-mut);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dz-sorts{display:flex;gap:7px;margin-bottom:12px;flex-wrap:wrap}
.dz-spill{height:calc(var(--pm-act-h) - 6px);padding:0 13px;border:none;border-radius:var(--pm-r);background:#1b3a85;color:#fff;font-size:.7rem;font-weight:700;transition:.2s}
.dz-spill:hover{background:#234799}
.dz-spill:active{transform:scale(.96)}
.dz-spill.on{background:var(--pm-blue)}
.dz-nopost{text-align:center;padding:34px 16px;color:var(--pm-mut)}
.dz-nopost i{font-size:30px;display:block;margin-bottom:8px;color:rgba(126,166,255,.45)}
.dz-nopost p{font-size:.76rem}
.ds-yitem{display:flex;align-items:center;gap:11px;padding:10px 0;border-bottom:1px solid var(--pm-line)}
.ds-yitem:last-child{border-bottom:none}
.ds-ymain{flex:1;min-width:0;text-decoration:none;color:inherit;display:flex;flex-direction:column;gap:4px}
.ds-yt{font-size:.76rem;font-weight:600;color:rgba(var(--fg),.9);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ds-yb{font-size:.64rem;color:var(--pm-mut);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ds-ys{display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:.64rem;color:var(--pm-mut)}
.ds-ys i{font-size:11px;margin-right:2px}
.ds-ys .d{margin-left:auto;opacity:.75}
.ds-ymeta{display:flex;align-items:center;gap:6px}
.ds-ydel{margin:0}
.ds-ydel button{width:32px;height:var(--pm-act-h);border-radius:var(--pm-r);border:none;background:var(--pm-blue);color:#000;font-size:15px;display:flex;align-items:center;justify-content:center;padding:0;transition:.2s}
.ds-ydel button:hover{background:var(--pm-blue-h)}
.ds-ydel button:active{transform:scale(.94)}
.dz-about{margin-top:16px;padding:14px 12px 12px;background:var(--pm-card);border:1px solid var(--pm-line);border-radius:8px}
.dz-about-h{display:flex;align-items:center;gap:7px;font-size:.8rem;font-weight:700;color:var(--text);margin-bottom:8px}
.dz-about-h i{font-size:16px;color:var(--pm-blue-l)}
.dz-about p{font-size:.72rem;color:var(--pm-sub);line-height:1.65;margin:0 0 6px}
.dz-about p strong{color:var(--text)}
.dz-about-sub{font-size:.74rem;font-weight:700;color:var(--pm-blue-l);margin:12px 0 5px}
@media(max-width:490px){.dz-about p{font-size:.66rem}.dz-about-h{font-size:.74rem}}

.ds-rsn{display:flex;align-items:center;gap:12px;padding:13px var(--pm-pad);margin:0 calc(var(--pm-pad) * -1);border-bottom:1px solid var(--pm-line);cursor:pointer;transition:background .2s}
.ds-rsn:hover{background:rgba(42,95,216,.1)}
.ds-rsn.selected{background:var(--pm-blue-dim)}
.ds-rsn i.ic{font-size:18px;color:var(--pm-blue-l);width:24px;text-align:center;flex-shrink:0}
.ds-rsn span{flex:1;min-width:0;font-size:.8rem;font-weight:500;color:rgba(var(--fg),.88)}
.ds-rsn.selected span{color:#fff;font-weight:600}
.ds-rsn .ck{width:20px;height:20px;border-radius:5px;border:1.5px solid rgba(255,255,255,.25);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:transparent;flex-shrink:0}
.ds-rsn.selected .ck{background:var(--pm-blue);border-color:var(--pm-blue);color:#000}
@media(max-width:490px){.ds-rsn{padding:12px var(--pm-pad)}.ds-rsn span{font-size:.72rem}}
.ds-rother{display:none;padding:12px 0 6px}
.ds-rdone{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:30px 24px 60px;text-align:center}
.ds-rdone i{display:flex;align-items:center;justify-content:center;width:64px;height:64px;margin:0 auto 16px;border-radius:50%;background:rgba(77,222,138,.12);border:1px solid rgba(77,222,138,.4);font-size:28px;color:#4dde8a}
.ds-rdone b{display:block;font-size:.95rem;margin-bottom:8px}
.ds-rdone p{font-size:.74rem;color:var(--pm-sub);line-height:1.7;max-width:290px;margin:0 auto 22px}
@keyframes dsShake{0%,100%{transform:translateX(0)}20%{transform:translateX(-6px)}40%{transform:translateX(6px)}60%{transform:translateX(-4px)}80%{transform:translateX(4px)}}

/* ── ⋯ menu sheet (same as the group page sheet) ── */
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
    border-radius:22px 22px 0 0;
    z-index:99999999;
    max-height:80vh;
    display:flex;flex-direction:column;
    padding:8.8px 0 max(11px, env(safe-area-inset-bottom));
    transform:translateY(100%);
    transition:transform 0.3s cubic-bezier(0.32,0.72,0,1);
    box-shadow:0 -10px 40px rgba(0,0,0,0.5);
    overflow:hidden;
}
.idx-sheet-panel.show{transform:translateY(0);}
/* closed sheets wait just below the screen: their upward shadow must not bleed onto the bottom of the page (it dimmed Zeems' progress bar and caption) */
.svsh-panel:not(.show),.idx-sheet-panel:not(.show){ box-shadow:none !important; }

.idx-sheet-handle{
    width:37.4px;height:3.85px;border-radius:1098.9px;
    background:rgba(255,255,255,0.2);
    margin:0 auto 4.4px;flex-shrink:0;
}

/* ── SLIDING TRACK: list ↔ detail ── */
.idx-sheet-track{
    display:flex;width:200%;
    flex:1;min-height:0;
    transition:transform .3s cubic-bezier(.32,.72,0,1);
}
.idx-sheet-track.show-detail{ transform:translateX(-50%); }

.idx-sheet-screen{
    width:50%;flex-shrink:0;
    display:flex;flex-direction:column;min-height:0;
}

.idx-sheet-list{overflow-y:auto;flex:1;-webkit-overflow-scrolling:touch;}
.idx-sheet-list::-webkit-scrollbar{width:4px;}
.idx-sheet-list::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.15);border-radius:999px;}

.idx-sheet-item{
    display:flex;align-items:center;gap:12.1px;
    padding:9.9px 19.8px;
    color:#fff;text-decoration:none;
    font-size:14.3px;font-weight:500;
    font-family:Poppins,sans-serif;
    cursor:pointer;
    -webkit-tap-highlight-color:transparent;
    transition:background 0.15s ease;
    border-bottom:1px solid rgba(255,255,255,0.06);
}
.idx-sheet-item:last-child{border-bottom:none;}
.idx-sheet-item{width:100%;border-left:none;border-right:none;border-top:none;background:none;text-align:left;box-sizing:border-box}
.idx-sheet-item:active{background:rgba(255,255,255,0.06);}
.idx-sheet-item i{font-size:17.05px;color:#f24455;width:19.8px;text-align:center;flex-shrink:0;}
.idx-sheet-item.danger{color:#ff4c4c;}
.idx-sheet-item.danger i{color:#ff4c4c;}

/* item with a small subtitle line (e.g. "Not Interested") */
.idx-sheet-item-title{font-size:14.3px;font-weight:500;color:#fff;line-height:1.3;}
.idx-sheet-item-sub{font-size:11.55px;color:rgba(255,255,255,0.4);margin-top:2.2px;line-height:1.3;}
.idx-sheet-item.danger .idx-sheet-item-title{color:#ff4c4c;}

.idx-sheet-cancel{
    margin:6.6px 13.2px 0;
    padding:11px;
    background:rgba(255,255,255,0.06);
    border:none;border-radius:13.2px;
    color:rgba(255,255,255,0.7);
    font-weight:600;font-size:13.75px;
    font-family:Poppins,sans-serif;
    cursor:pointer;flex-shrink:0;
    -webkit-tap-highlight-color:transparent;
}
.idx-sheet-cancel:active{background:rgba(255,255,255,0.1);}
@media (min-width:791px){
    .idx-sheet-panel{
        left:50%;right:auto;bottom:auto;top:50%;
        width:100%;max-width:374px;
        border-radius:19.8px;
        transform:translate(-50%,-40%) scale(0.95);
        opacity:0;pointer-events:none;
        max-height:76vh;
    }
    .idx-sheet-panel.show{
        transform:translate(-50%,-50%) scale(1);
        opacity:1;pointer-events:all;
    }
    .idx-sheet-handle{display:none;}
}
@media (max-width:790px){
    .idx-sheet-panel{ border-radius:19.8px 19.8px 0 0; max-height:78vh; padding:7.7px 0 max(9.9px, env(safe-area-inset-bottom)); }
    .idx-sheet-handle{ width:35.2px;height:3.85px;margin:0 auto 4.4px; }
    .idx-sheet-item{ padding:8.8px 17.6px; font-size:13.75px; gap:11px; }
    .idx-sheet-item i{ font-size:16.5px; width:18.7px; }
    .idx-sheet-cancel{ margin:5.5px 12.1px 0; padding:9.9px; font-size:13.2px; border-radius:12.1px; }
}
@media (max-width:490px){
    .idx-sheet-panel{ border-radius:17.6px 17.6px 0 0; max-height:76vh; padding:6.6px 0 max(8.8px, env(safe-area-inset-bottom)); backdrop-filter:blur(16px); -webkit-backdrop-filter:blur(16px); }
    .idx-sheet-handle{ width:33px;height:3.3px;margin:0 auto 3.3px; }
    .idx-sheet-item{ padding:7.7px 15.4px; font-size:13.2px; gap:9.9px; }
    .idx-sheet-item i{ font-size:15.4px; width:17.6px; }
    .idx-sheet-item-sub{ font-size:11px; }
    .idx-sheet-cancel{ margin:5.5px 11px 0; padding:8.8px; font-size:12.65px; border-radius:11px; }
}
@media (max-width:390px){
    .idx-sheet-panel{ border-radius:15.4px 15.4px 0 0; max-height:74vh; padding:6.6px 0 max(7.7px, env(safe-area-inset-bottom)); backdrop-filter:blur(14px); -webkit-backdrop-filter:blur(14px); box-shadow:0 -8px 30px rgba(0,0,0,0.45); }
    .idx-sheet-handle{ width:30.8px;height:3.3px;margin:0 auto 3.3px; }
    .idx-sheet-item{ padding:7.15px 13.2px; font-size:12.65px; gap:8.8px; }
    .idx-sheet-item i{ font-size:14.85px; width:16.5px; }
    .idx-sheet-cancel{ margin:4.4px 9.9px 0; padding:7.7px; font-size:12.1px; border-radius:9.9px; }
}
.idx-sheet-item.report{color:#ff4c4c}.idx-sheet-item.report i{color:#ff4c4c}
[data-theme="light"] .idx-sheet-overlay{background:rgba(20,24,40,.38)}
[data-theme="light"] .idx-sheet-handle{background:rgba(15,20,40,.18)}
[data-theme="light"] .idx-sheet-list::-webkit-scrollbar-thumb{background:rgba(15,20,40,.2)}
[data-theme="light"] .idx-sheet-item{color:#14171f;border-bottom-color:rgba(15,20,40,.08)}
[data-theme="light"] .idx-sheet-item:active{background:rgba(15,20,40,.06)}
[data-theme="light"] .idx-sheet-item.report,[data-theme="light"] .idx-sheet-item.danger{color:#e02525}
[data-theme="light"] .idx-sheet-item.report i,[data-theme="light"] .idx-sheet-item.danger i{color:#e02525}
[data-theme="light"] .idx-sheet-cancel{background:rgba(15,20,40,.06);color:rgba(20,23,31,.75)}
[data-theme="light"] .idx-sheet-cancel:active{background:rgba(15,20,40,.12)}

/* ═════════ COMMENTS SHEET ═════════ */
.dcs-ov{position:fixed;inset:0;z-index:2200;background:rgba(2,3,20,.62);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);opacity:0;visibility:hidden;transition:opacity .25s,visibility 0s .25s}
.dcs-ov.open{opacity:1;visibility:visible;transition:opacity .25s}
.dcs{position:fixed;z-index:2300;left:50%;bottom:0;width:min(480px,100vw);height:min(70vh,560px);display:flex;flex-direction:column;background:linear-gradient(180deg,#0a0d2e,#060820);border:1px solid var(--border);border-bottom:none;border-radius:16px 16px 0 0;box-shadow:0 -14px 40px rgba(0,0,0,.5);transform:translate(-50%,102%);visibility:hidden;transition:transform .3s cubic-bezier(.22,1,.36,1),visibility 0s .3s}
.dcs.open{transform:translate(-50%,0);visibility:visible;transition:transform .3s cubic-bezier(.22,1,.36,1),visibility 0s}
@media(max-width:790px){.dcs{width:100vw;height:72vh;border-left:none;border-right:none}}
.dcs-handle{display:flex;justify-content:center;padding:9px 0 2px;touch-action:none;flex-shrink:0;cursor:grab}
.dcs-handle span{width:40px;height:4px;border-radius:4px;background:rgba(var(--fg),.24)}
.dcs-head{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-shrink:0;padding:4px 10px 9px 14px;border-bottom:1px solid var(--divider);touch-action:none}
.dcs-title{font-size:.88rem;font-weight:700}
.dcs-count{color:var(--muted);font-weight:500;font-size:.74rem;margin-left:5px}
.dcs-x{width:30px;height:30px;border-radius:50%;border:none;background:none;color:var(--text);font-size:1.2rem;display:flex;align-items:center;justify-content:center;transition:background .2s}
.dcs-x:hover{background:var(--surface-hover)}
.dcs-sort{position:relative;margin-left:auto;margin-right:4px}
.dcs-sortbtn{display:flex;align-items:center;gap:2px;padding:4px 7px;border-radius:var(--action-r);border:none;background:var(--surface);color:rgba(var(--fg),.85);font-size:.68rem;font-weight:600}
.dcs-sortbtn:hover{background:var(--surface-hover)}
.dcs-sortmenu{position:absolute;top:calc(100% + 6px);right:0;z-index:5;display:none;min-width:150px;background:#0c0e2a;border:1px solid var(--border);border-radius:10px;padding:6px;box-shadow:0 10px 30px rgba(0,0,0,.5)}
.dcs-sortmenu.show{display:block}
.dcs-sortmenu button{display:block;width:100%;text-align:left;padding:8px 10px;border:none;background:none;color:var(--text);font-size:.76rem;border-radius:6px}
.dcs-sortmenu button:hover{background:var(--surface-hover)}
.dcs-sortmenu button.active{color:var(--blue-soft);font-weight:600}
.dcs-thead{display:none;gap:2px;padding:4px 10px 4px 4px}
.dcs-tback{width:var(--bbtn);height:var(--bbtn);border-radius:50%;border:none;background:rgba(var(--fg),.08);color:var(--text);font-size:1.6rem;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin:0 4px 0 6px;transition:background .2s}
.dcs-tback:hover{background:var(--surface-hover)}
.dcs-thead .dcs-title{flex:1}
.dcs-scroll{flex:1;min-height:0;overflow-y:auto;overscroll-behavior:contain;padding:4px 14px 8px;-webkit-overflow-scrolling:touch;scrollbar-width:thin;scrollbar-color:rgba(36,89,184,.4) transparent}
.dcs-scroll::-webkit-scrollbar{width:5px}.dcs-scroll::-webkit-scrollbar-thumb{background:rgba(36,89,184,.4);border-radius:999px}
.dcs-empty{text-align:center;color:var(--muted);font-size:.8rem;padding:40px 10px}
.dcs-note{display:flex;align-items:center;gap:8px;margin:6px 0 0;padding:6px 10px;border-radius:var(--action-r);background:var(--surface);border:1px solid var(--border);font-size:.68rem;color:rgba(var(--fg),.86)}
.dcs-note i{font-size:1rem;color:var(--blue-soft);flex-shrink:0}
.dcs-thrd{padding:10px 0 4px;margin:0 -14px;padding-left:14px;padding-right:14px;border-bottom:1px solid var(--divider)}
.dcs-thrd b{display:block;font-size:.72rem;font-weight:700;line-height:1.4;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.dcs-thrd small{display:block;font-size:.6rem;color:var(--muted);margin-top:2px}
.dcs-item{--csav:28px;--csgap:9px;--thread:rgba(var(--fg),.24);padding:9px 0}
.dcs-main{display:flex;gap:var(--csgap)}
.dcs-avcol{display:flex;flex-direction:column;align-items:center;flex-shrink:0;width:var(--csav)}
.dcs-line{display:none;flex:1;width:2px;margin-top:6px;border-radius:2px;background:var(--thread)}
.dcs-item.has-thread > .dcs-main .dcs-line{display:block}
.dcs-item.has-thread > .dcs-main .dcs-body{padding-bottom:12px}
.dcs-av{width:var(--csav);height:var(--csav);border-radius:50%;overflow:hidden;flex-shrink:0;text-decoration:none;border:2px solid var(--accent);background:linear-gradient(135deg,#262b3d,#171a24);display:flex;align-items:center;justify-content:center}
.dcs-av img{width:100%;height:100%;object-fit:cover;display:block}
.dcs-body{flex:1;min-width:0}
.dcs-line1{display:flex;align-items:center;flex-wrap:wrap;gap:2px 8px;min-width:0}
.dcs-user{font-size:.72rem;font-weight:500;color:rgba(var(--fg),.62);text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:100%}
.dcs-user.is-owner{display:inline-flex;align-items:center;gap:3px;padding:0 8px;border-radius:4px;background:#fff;color:#0a0c24;font-size:.7rem;line-height:1.4}
.dcs-user.is-owner i{font-size:.78rem}
.dcs-ownertag{font-size:.58rem;color:rgba(var(--fg),.72);padding:0 6px;border-radius:4px;border:1px solid var(--border)}
.dcs-time{font-size:.62rem;color:var(--muted);flex-shrink:0}
.dcs-time::before{content:'·';margin-right:6px}
.dcs-more{margin-left:auto;margin-right:-6px;width:28px;height:28px;flex-shrink:0;border:none;background:none;color:var(--muted);font-size:1.15rem;border-radius:50%;display:flex;align-items:center;justify-content:center;transition:color .2s,background .2s}
.dcs-more:hover{color:var(--text);background:var(--surface-hover)}
.dcs-text{font-size:.74rem;line-height:1.45;color:rgba(var(--fg),.92);margin-top:2px;overflow-wrap:anywhere}
.dcs-mention{color:#4fa8ff;font-weight:600}
.dcs-link{color:var(--blue-soft);text-decoration:none;word-break:break-all}
.dcs-link:hover{text-decoration:underline}
.dcs-text .dcs-full{display:none}
.dcs-text.expanded .dcs-full{display:inline}
.dcs-text.expanded .dcs-short{display:none}
.dcs-readmore{border:none;background:none;padding:0;margin-left:2px;font-size:inherit;color:rgba(var(--fg),.7)}
.dcs-readmore:hover{color:var(--text)}
.dcs-row2{display:flex;align-items:center;gap:16px;margin-top:6px}
.dcs-like,.dcs-dislike,.dcs-reply{display:flex;align-items:center;gap:6px;border:none;background:none;padding:0;color:rgba(var(--fg),.85);font-size:.7rem;font-weight:500;transition:color .2s,transform .12s}
.dcs-like i,.dcs-dislike i{font-size:1rem;display:block}
.dcs-like:hover,.dcs-dislike:hover{color:var(--text)}
.dcs-like:active,.dcs-dislike:active,.dcs-reply:active{transform:scale(.9)}
.dcs-like.on,.dcs-dislike.on{color:var(--feed-blue)}
.dcs-like.on i,.dcs-dislike.on i{animation:dsPop .34s ease}
@keyframes dsPop{0%{transform:scale(1)}45%{transform:scale(1.38)}100%{transform:scale(1)}}
.dcs-reply{color:var(--muted)}.dcs-reply:hover{color:var(--text)}
.dcs-crlike{position:relative;display:inline-flex;width:16px;height:16px;flex-shrink:0;margin-left:-2px}
.dcs-crav{width:16px;height:16px;border-radius:50%;overflow:hidden;border:1.5px solid var(--accent);background:linear-gradient(135deg,#262b3d,#171a24)}
.dcs-crav img{width:100%;height:100%;object-fit:cover;display:block}
.dcs-crbadge{position:absolute;right:-5px;bottom:-4px;font-size:.68rem;color:var(--accent);filter:drop-shadow(0 0 1.5px #060820)}
.dcs-viewreplies{position:relative;display:flex;align-items:center;gap:7px;height:26px;margin-left:calc(var(--csav) + var(--csgap));border:none;background:none;padding:0;color:var(--text);font-size:.72rem;font-weight:600}
.dcs-viewreplies::before{content:'';position:absolute;top:0;height:50%;box-sizing:border-box;left:calc(-1 * (var(--csav) / 2 + var(--csgap) + 1px));width:calc(var(--csav) / 2 + var(--csgap) + 1px);border-left:2px solid var(--thread);border-bottom:2px solid var(--thread);border-bottom-left-radius:12px}
.dcs-vr-av{width:22px;height:22px;border-radius:50%;overflow:hidden;border:1.5px solid var(--accent);background:linear-gradient(135deg,#262b3d,#171a24)}
.dcs-vr-av img{width:100%;height:100%;object-fit:cover;display:block}
.dcs-vr-dot{opacity:.7}
.dcs-vr-chev{font-size:1.1rem;transition:transform .2s ease}
.dcs-viewreplies.open .dcs-vr-chev{transform:rotate(90deg)}
.dcs-sk{padding:12px 0;display:flex;gap:10px}
.dcs-subthread{margin-left:calc(var(--csav) + var(--csgap));margin-top:2px}
.dcs-subthread .dcs-item{padding:10px 0}
.dcs-subthread .dcs-item:first-child{padding-top:8px}
.dcs-submore{display:block;margin:2px 0 6px;padding:0;border:none;background:none;color:rgba(var(--fg),.7);font-size:.7rem;font-weight:600}
.dcs-submore:hover{color:var(--text)}
.dcs-sub-sk{padding:8px 0;display:flex;gap:8px}
.dcs-menu{position:absolute;z-index:8;display:none;min-width:180px;background:#0c0e2a;border:1px solid var(--border);border-radius:10px;padding:6px;box-shadow:0 10px 30px rgba(0,0,0,.55)}
.dcs-menu.show{display:block}
.dcs-menu button{display:flex;align-items:center;gap:10px;width:100%;text-align:left;padding:10px;border:none;background:none;color:var(--text);font-size:.78rem;border-radius:6px}
.dcs-menu button i{font-size:1.1rem;color:#2f6fd6}
.dcs-menu button:hover{background:var(--surface-hover)}
.dcs-composer{display:flex;align-items:center;gap:10px;padding:8px 12px calc(8px + env(safe-area-inset-bottom));flex-shrink:0;border-top:1px solid var(--divider);background:#060820}
.dcs-myav{width:26px;height:26px;border-radius:50%;overflow:hidden;flex-shrink:0;border:2px solid var(--accent);background:linear-gradient(135deg,#262b3d,#171a24)}
.dcs-myav img{width:100%;height:100%;object-fit:cover;display:block}
.dcs-composer input{flex:1;min-width:0;border:none;outline:none;background:var(--surface);color:var(--text);padding:8px 12px;border-radius:999px;font-size:.74rem}
.dcs-composer input::placeholder{color:var(--muted)}
.dcs-send{width:28px;height:28px;border:none;border-radius:50%;padding:0;flex-shrink:0;background:var(--accent);color:var(--text);display:flex;align-items:center;justify-content:center;transition:background .2s,color .2s,opacity .2s,transform .15s}
.dcs-send svg{width:16px;height:16px;display:block}
.dcs-send:not(:disabled):active{transform:scale(.88)}
.dcs-send:disabled{background:var(--surface);color:var(--muted);opacity:.75}

/* ═════════ POST VIEW (Reddit-style): tapping a post opens it full-screen with its comments below ═════════ */
.dcs{width:min(720px,100vw);height:100vh;height:100dvh;border:none;border-radius:0;box-shadow:none;background:var(--solid,#060820)}
.dcs-ov{background:rgba(2,3,20,.78)}
.dcs-handle{display:none}
.dcs-head{padding:max(8px,env(safe-area-inset-top)) 10px 8px 6px;gap:6px;justify-content:flex-start}
.dcs-head #dcsClose{order:-1;width:36px;height:36px;font-size:1.5rem}
.dcs-scroll{padding:0 0 8px}
#dcsList,#dcsSkel,#dcsEmpty,.dcs-note{padding-left:14px;padding-right:14px}
.dcs-thrd{padding:0;margin:0;border-bottom:none}
.dpv-wrap .gf-post{cursor:default;border-bottom:3px solid var(--post-sep)}
.dcs-cbar{display:flex;align-items:center;gap:8px;padding:10px 14px 8px;border-bottom:1px solid var(--divider)}
.dcs-cbar .dcs-title{flex:1}
.dcs-cbar .dcs-sort{margin:0}
.dcs-head .dcs-title{flex:1;font-size:.9rem}
.dcs-composer{padding-top:10px}
.dcs-composer input{padding:10px 14px;font-size:.8rem}
@media(min-width:791px){.dcs{left:50%;border-left:1px solid var(--border);border-right:1px solid var(--border)}}
.dcs-troot{background:rgba(var(--fg),.035);margin:0 -14px 2px;padding:10px 14px 2px}
.dcs-tscroll .dcs-item .dcs-line,.dcs-troot .dcs-item .dcs-line{display:block}
.dcs-tscroll .dcs-item:last-child .dcs-line{display:none}
.dcs-troot .dcs-item,.dcs-tscroll .dcs-item{--csav:28px;--csgap:9px}
.sk-sh{background:linear-gradient(90deg,rgba(var(--fg),.05) 25%,rgba(var(--fg),.12) 50%,rgba(var(--fg),.05) 75%);background-size:200% 100%;animation:gpShim 1.3s linear infinite;border-radius:6px}

/* toast */
#dsToast{position:fixed;bottom:90px;left:50%;transform:translateX(-50%) translateY(10px);background:rgba(20,20,25,.95);border:1px solid rgba(36,89,184,.5);color:#fff;padding:9px 22px;border-radius:50px;font-size:12px;z-index:2147483000;pointer-events:none;box-shadow:0 8px 24px rgba(0,0,0,.6);white-space:nowrap;opacity:0;transition:opacity .25s,transform .25s}
#dsToast.show{opacity:1;transform:translateX(-50%) translateY(0)}
#gpNavSentinel{height:1px}
@media(prefers-reduced-motion:reduce){*{animation-duration:.01ms!important;transition-duration:.01ms!important}}

/* ═════════ FEED = RED THEME (same as the group page feed) ═════════
   Re-points the feed's theme variables, so the profile-picture ring, Track/Untrack,
   liked/saved icons, carousel dots, the double-tap heart, the "New" tag, links
   and the pinned bar all turn red. */
#dsFeed{--feed-blue:#ff2b2b;--feed-blue-rgb:255,43,43;--blue:#ff2b2b;--blue-hover:#ff4c4c;--blue-soft:#ff6b6b}

/* the empty state stays blue, same as the group page */
#dsFeed .gf-empty{--feed-blue:#2b63d1;--feed-blue-rgb:43,99,209;--blue:#2459B8;--blue-hover:#2E68C9;--blue-soft:#5b8fe6}

/* vote polls (the poll colours were hard-coded blue) */
#dsFeed .ds-poll{background:linear-gradient(160deg,rgba(255,43,43,.12),rgba(255,43,43,.03));border-color:rgba(255,43,43,.32)}
#dsFeed .ds-poll.quiz{background:linear-gradient(160deg,rgba(124,92,255,.12),rgba(124,92,255,.02));border-color:rgba(124,92,255,.32)}
#dsFeed label.dp-opt:hover{border-color:rgba(255,43,43,.6);background:rgba(255,43,43,.08)}
#dsFeed .dp-opt:has(input:checked){border-color:var(--feed-blue);background:rgba(255,43,43,.16)}
#dsFeed .dp-bar{background:rgba(255,43,43,.2)}
#dsFeed .dp-opt.correct .dp-bar{background:rgba(61,220,132,.2)}
#dsFeed .dp-opt.wrong .dp-bar{background:rgba(255,80,80,.16)}
#dsFeed .dp-pct{color:var(--blue-soft)}
#dsFeed .dp-opt.correct .dp-pct{color:var(--green)}

/* green "active now" dot on the poster's avatar (ring = page colour, bottom-right of the pic) */
.gf-header .av-wrap{position:relative;display:block;flex:none;line-height:0}
.on-dot{position:absolute;right:2%;bottom:2%;width:32%;min-width:9px;max-width:16px;aspect-ratio:1;border-radius:50%;background:var(--green);border:calc(clamp(1.5px,.5vw,2.5px) * var(--gf-s)) solid var(--solid);box-sizing:border-box;pointer-events:none;z-index:3}

/* verified badge — scales with the name, white in dark mode / black in light mode */
.ds-vb{width:.95em;height:.95em;flex-shrink:0;display:inline-block;vertical-align:-.14em;margin-left:4px;color:var(--text)}
.gs-admin-n .ds-vb{width:.9em;height:.9em;margin-left:3px}
.dcs-user .ds-vb{margin-left:3px}

/* You / Sort / Start-a-discussion modals: 10% bigger (text, buttons, rows — everything inside scales together) */
#dsYouOv,#dsSortOv,#dsCreateOv{zoom:1.1}

/* ═════════ BACK BUTTON — same chevron as the Zeems modal, no circle behind it ═════════ */
.gp-bbtn.back,
.gp-topbar.scrolled .gp-bbtn.back,
.gp-bbtn.back:hover,
.gp-topbar.scrolled .gp-bbtn.back:hover{background:transparent!important;backdrop-filter:none;-webkit-backdrop-filter:none;box-shadow:none}
.gp-bbtn.back{padding:0 0 0 2px;color:#fff}
.gp-bbtn.back .ms-back-svg{height:23.1px;width:auto;aspect-ratio:14 / 24;display:block;overflow:visible;filter:drop-shadow(0 2px 6px rgba(0,0,0,.7))}
.gp-topbar.scrolled .gp-bbtn{color:var(--text)}
.gp-topbar.scrolled .gp-bbtn.back .ms-back-svg{filter:none}
.dcs-tback{background:none}
.dcs-tback:hover{background:none}

/* ═════════ LIGHT MODE — <html data-theme="light"> (dark mode is never touched) ═════════
   every white/near-white colour above runs through --fg / --text, so the page flips with these variables;
   banner, media and image overlays deliberately stay as they are. */
:root[data-theme="light"]{
  --fg:6,7,26; --fg2:6,7,26; --solid-rgb:255,255,255;
  --bg:linear-gradient(-45deg,#ffffff,#f4f5fb,#f4f5fb);
  --solid:#ffffff; --post-sep:#e6e8f2;
  --text:#0b0d24; --grey-text:#5f647a;
  --blue-text:#1a56db; --flash:#e6ecff;
  --btn-grey:rgba(6,7,26,.07);
}
[data-theme="light"] .gp-btn.primary,
[data-theme="light"] .gp-fpill.active-tag,
[data-theme="light"] .gs-sn,
[data-theme="light"] .dp-submit,
[data-theme="light"] .gf-empty button{color:#fff}
[data-theme="light"] .gp-fpill{color:var(--blue)}
[data-theme="light"] .gp-nav-item.active{color:var(--text)}
[data-theme="light"] .gp-nav-item .cnt,
[data-theme="light"] .gp-ev-count{color:#1a56db}
[data-theme="light"] .gs-admin-tag{color:#1a56db;background:rgba(var(--feed-blue-rgb),.1)}
[data-theme="light"] .gp-ev-thumb{background:linear-gradient(145deg,#cfdcff,#9db8f5 60%,#6f93e8)}
[data-theme="light"] .ds-tagchip{filter:brightness(.6) saturate(1.35)}
[data-theme="light"] .ds-ov{
  --pm-blue-l:#2a5fd8; --pm-on-blue:#fff;
  background:linear-gradient(-45deg,#ffffff,#f4f5fb,#f4f5fb);color:var(--text)}
[data-theme="light"] .dz-sel option{background:#fff;color:#0b0d24}
[data-theme="light"] .idx-sheet-panel{background:rgba(255,255,255,.99);border-top-color:rgba(6,7,26,.1);box-shadow:0 -10px 40px rgba(6,7,26,.18)}
[data-theme="light"] .dcs{background:linear-gradient(180deg,#ffffff,#f4f5fb);box-shadow:0 -14px 40px rgba(6,7,26,.2)}
[data-theme="light"] .dcs-sortmenu,
[data-theme="light"] .dcs-menu{background:#ffffff;box-shadow:0 10px 30px rgba(6,7,26,.2)}
[data-theme="light"] .dcs-composer{background:#ffffff}
[data-theme="light"] .dcs-av,
[data-theme="light"] .dcs-crav,
[data-theme="light"] .dcs-vr-av,
[data-theme="light"] .dcs-myav{background:linear-gradient(135deg,#dfe2f0,#cdd2e6)}
[data-theme="light"] .dcs-crbadge{filter:drop-shadow(0 0 1.5px #ffffff)}
[data-theme="light"] .gf-hidden-placeholder{background:#f1f3fa}
[data-theme="light"] .gp-filterbar.is-stuck{box-shadow:0 8px 16px -10px rgba(6,7,26,.25)}
</style>
</head>
<body>

<!-- ═════════ SIDEBAR — nav.php (same as the group page) ═════════ -->
<?php include 'nav.php'; ?>

<!-- ═════════ PAGE SKELETON ═════════ -->
<div id="dsSkel" aria-hidden="true">
 <div class="sk-shell"><div class="sk-page">
    <div class="sk sk-banner"></div>
    <div class="sk-head">
      <div class="sk sk-line" style="width:48%;height:15px"></div>
      <div class="sk sk-line" style="width:62%"></div>
      <div class="sk sk-line" style="width:34%"></div>
      <div class="sk-actions"><div class="sk"></div><div class="sk"></div></div>
    </div>
    <div class="sk-nav"><div><span class="sk"></span></div><div><span class="sk"></span></div><div><span class="sk"></span></div></div>
    <div class="sk-filter"><div class="sk"></div><div class="sk" style="width:62px"></div><div class="sk" style="width:70px"></div><div class="sk" style="width:58px"></div></div>
    <div class="sk-post"><div class="sk-post-h"><div class="sk a"></div><div class="sk l"></div></div><div class="sk sk-post-m"></div></div>
    <div class="sk-post"><div class="sk-post-h"><div class="sk a"></div><div class="sk l"></div></div><div class="sk sk-post-m"></div></div>
 </div></div>
</div>
<noscript><style>#dsSkel{display:none!important}</style></noscript>
<script>
(function(){var s=document.getElementById('dsSkel');if(!s)return;var done=false;
function hide(){if(done)return;done=true;s.classList.add('hide');setTimeout(function(){if(s.parentNode)s.parentNode.removeChild(s)},450)}
if(document.readyState==='complete')hide();else window.addEventListener('load',hide);
window.addEventListener('pageshow',function(e){if(e.persisted)hide()});setTimeout(hide,5000)})();
</script>

<div class="ds-shell">
<div class="ds-page" id="dsPage">

  <!-- ═════════ STICKY TOP BAR ═════════ -->
  <div class="gp-topbar" id="gpTopbar">
    <a class="gp-bbtn back" href="group.php?id=<?= (int)$group_id ?>" aria-label="Back to pod"><svg class="ms-back-svg" viewBox="0 0 14 24" aria-hidden="true"><path d="M12.4 1.6 L2 12 L12.4 22.4" fill="none" stroke="currentColor" stroke-width="1.94" stroke-linejoin="miter" stroke-linecap="butt"/></svg></a>
    <span class="gp-topbar-title"><?= htmlspecialchars($group['title']) ?> Discussions</span>
    <div class="gp-banner-right">
      <button class="gp-bbtn" onclick="dsOpen('dsSearchOv')" aria-label="Search"><svg class="tb-sicon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.2" cy="10.2" r="7.9" fill="none" stroke="currentColor" stroke-width="2.5"/><path fill="currentColor" d="M16.2 15.1L21.41 18.59A2 2 0 0 1 18.59 21.41L15.1 16.2Z"/></svg></button>
      <button class="gp-bbtn" onclick="dsOpen('dsSortOv')" aria-label="Sort"><i class="ri-arrow-up-down-line"></i></button>
      <?php if ($is_member): ?>
      <button class="gp-bbtn" onclick="openCreateModal()" aria-label="New discussion"><i class="ri-add-line"></i></button>
      <?php endif; ?>
    </div>
  </div>

  <!-- ═════════ BANNER ═════════ -->
  <div class="gp-banner" id="gpBanner">
    <img class="gp-banner-img" src="<?= htmlspecialchars($disc_banner_src) ?>" onerror="this.src='uploads/default_group.png'" alt="Pod banner">
    <div class="gp-banner-shade"></div>
    <div class="gp-stats">
      <div class="gp-stat"><b><?= number_format(count($threads)) ?></b><span>Threads</span></div>
      <div class="gp-stat-div"></div>
      <div class="gp-stat"><b><?= number_format($ds_total_replies) ?></b><span>Replies</span></div>
      <div class="gp-stat-div"></div>
      <div class="gp-stat"><b><?= number_format($active_count) ?></b><span>Online now</span></div>
    </div>
  </div>

  <!-- ═════════ HEADER BODY ═════════ -->
  <div class="gp-head">
    <h1 class="gp-name"><a class="gp-podlink" href="group.php?id=<?= (int)$group_id ?>"><?= htmlspecialchars($group['title']) ?></a><span class="gp-name-d">Discussions</span></h1>
    <div class="gp-meta">
      <?php if ($ds_cat !== ''): ?><span class="gp-cat"><?= htmlspecialchars($ds_cat) ?></span><span class="gp-dot"></span><?php endif; ?>
      <span><?= number_format($member_count) ?> member<?= $member_count == 1 ? '' : 's' ?></span>
    </div>
    <div class="gp-desc" id="gpDesc">Ask for help, share ideas and vote on polls, one thread per topic.</div>

    <div class="gp-actions">
      <?php if ($is_member): ?>
        <button type="button" class="gp-btn primary" onclick="openCreateModal()"><svg class="gp-plus" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><path d="M12 4.5v15M4.5 12h15"/></svg> New discussion</button>
      <?php else: ?>
        <a class="gp-btn primary locked" href="group.php?id=<?= (int)$group_id ?>"><i class="ri-lock-line"></i> Join pod to post</a>
      <?php endif; ?>
      <a class="gp-btn" href="group.php?id=<?= (int)$group_id ?>">Pod home</a>
    </div>
  </div>

  <!-- ═════════ NAV BAR — Discussions · You · About ═════════ -->
  <div class="gp-nav" id="gpNav">
    <button type="button" class="gp-nav-item active" id="dsNavFeed" onclick="dsSetAbout(false)">Discussions</button>
    <?php if ($is_member): ?>
    <button type="button" class="gp-nav-item" onclick="dsOpen('dsYouOv')"><img src="<?= htmlspecialchars($me_pic) ?>" onerror="this.src='uploads/me.jpeg'" alt=""> You<?php if (!empty($ds_my)): ?> <span class="cnt"><?= count($ds_my) ?></span><?php endif; ?></button>
    <?php else: ?>
    <span class="gp-nav-item locked" onclick="dsToast('Join this pod to see your posts')">You</span>
    <?php endif; ?>
    <button type="button" class="gp-nav-item" id="dsNavAbout" onclick="dsSetAbout(!document.getElementById('dsPage').classList.contains('about-mode'))" aria-pressed="false">About</button>
  </div>

  <!-- ═════════ FILTER BAR ═════════ -->
  <div id="gpNavSentinel"></div>
  <div class="gp-filterbar" id="gpFilterBar">
    <button type="button" class="gp-f-arrow gp-f-arrow-l hidden" aria-label="Scroll left" onclick="dsFilterScroll(-1)">&#8249;</button>
    <div class="gp-filter-scroll" id="gpFilterScrollEl">
      <button type="button" class="gp-fpill" onclick="dsOpen('dsSortOv')"><i class="ri-arrow-up-down-line"></i> Sort: <span><?= htmlspecialchars($ds_sort_label) ?></span></button>
      <?php if ($search !== ''): ?>
      <a class="gp-fpill active-tag" href="<?= $ds_url_base . '&sort=' . urlencode($sort) ?>"><i class="ri-close-line"></i> “<?= htmlspecialchars($search) ?>”</a>
      <?php endif; ?>
      <span class="gp-filter-sep"></span>
      <button type="button" class="gp-fpill gp-tag-pill active-tag" data-tag="" onclick="dsFilterTag('')">All</button>
      <?php foreach ($ds_tag_counts as $tg => $cnt): ?>
      <button type="button" class="gp-fpill gp-tag-pill" data-tag="<?= htmlspecialchars($tg) ?>" onclick="dsFilterTag(this.dataset.tag)"><?= htmlspecialchars($tg) ?></button>
      <?php endforeach; ?>
    </div>
    <button type="button" class="gp-f-arrow gp-f-arrow-r hidden" aria-label="Scroll right" onclick="dsFilterScroll(1)">&#8250;</button>
  </div>

  <!-- ═════════ ABOUT VIEW (opened by the About button) ═════════ -->
  <div id="dsAboutView">
    <div class="ds-about"><div class="gs-cards"><?= $ds_cards_html ?></div></div>
  </div>

  <!-- ═════════ EVENTS + FEED VIEW ═════════ -->
  <div id="dsFeedView">

  <?php if ($ds_ev_count > 0): ?>
  <div class="gp-ev" id="gpEv">
    <div class="gp-ev-head"><i class="ri-calendar-event-line"></i> Upcoming events <span class="gp-ev-count"><?= $ds_ev_count ?></span></div>
    <?php foreach ($ds_events as $ei => $ev):
        $ev_virtual = ($ev['location_type'] === 'virtual');
        $ev_when = date('D, j M · g:i A', strtotime($ev['event_date'] . ' ' . $ev['event_time']));
        $ev_img  = !empty($ev['event_image']) ? 'uploads/' . $ev['event_image'] : '';
    ?>
    <a class="gp-ev-row<?= $ei >= 2 ? ' gp-ev-hold' : '' ?>" href="group_events.php?id=<?= (int)$group_id ?>#event-detail-<?= (int)$ev['id'] ?>">
      <div class="gp-ev-thumb">
        <i class="ri-calendar-event-fill gp-ev-fb"></i>
        <?php if ($ev_img): ?><img src="<?= htmlspecialchars($ev_img) ?>" alt="" loading="lazy" onerror="this.style.display='none'"><?php endif; ?>
      </div>
      <div class="gp-ev-info">
        <div class="gp-ev-date"><?= htmlspecialchars($ev_when) ?></div>
        <div class="gp-ev-title"><?= htmlspecialchars($ev['event_name']) ?></div>
        <span class="gp-ev-type"><i class="ri-<?= $ev_virtual ? 'live-line' : 'map-pin-line' ?>"></i> <?= $ev_virtual ? 'Virtual meetup' : 'Physical meetup' ?></span>
      </div>
    </a>
    <?php endforeach; ?>
    <?php if ($ds_ev_count > 2): ?>
    <button type="button" class="gp-ev-more" id="gpEvMore" onclick="gpEvMore()">See more</button>
    <?php endif; ?>
  </div>
  <script>
  function gpEvMore(){
    var holds=document.querySelectorAll('#gpEv .gp-ev-row.gp-ev-hold');
    for(var i=0;i<holds.length && i<2;i++){ holds[i].classList.remove('gp-ev-hold'); holds[i].classList.add('gp-ev-in'); }
    if(holds.length<=2){ var b=document.getElementById('gpEvMore'); if(b) b.remove(); }
  }
  </script>
  <?php endif; ?>

  <!-- ═════════ FEED ═════════ -->
  <div id="dsFeed"></div>

  </div><!-- /#dsFeedView -->

</div><!-- /.ds-page -->

<!-- ═════════ RIGHT COLUMN (laptop / normal view) — same design as the group page ═════════ -->
<aside class="ds-side" aria-label="About this Discussions page">
  <div class="gs-cards"><?= $ds_cards_html ?></div>
</aside>
</div><!-- /.ds-shell -->

<div id="dsToast"></div>

<!-- ═════════ SORT MODAL ═════════ -->
<div class="ds-ov" id="dsSortOv" role="dialog" aria-modal="true" aria-label="Sort discussions">
 <div class="dz-lane">
  <div class="dz-tb">
    <div class="dz-side"><button type="button" class="dz-x" onclick="dsClose('dsSortOv')" aria-label="Close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button></div>
    <div class="dz-tc"><div class="dz-tt">Sort discussions</div></div>
    <div class="dz-side right"></div>
  </div>
  <div class="dz-body">
    <div class="dz-note">Choose how the feed is ordered</div>
    <div class="dz-group">Order by</div>
    <?php foreach ($ds_sort_opts as $sv => $si): ?>
    <a class="dz-option<?= $sort === $sv ? ' selected' : '' ?>" href="<?= $ds_url_base . ($search !== '' ? '&q=' . urlencode($search) : '') . '&sort=' . $sv ?>">
      <div class="dz-oi"><i class="<?= $si[0] ?>"></i></div>
      <div class="dz-ot"><div class="dz-on"><?= $si[1] ?></div><div class="dz-od"><?= $si[2] ?></div></div>
      <div class="dz-ock"><?= $sort === $sv ? '✓' : '' ?></div>
    </a>
    <?php endforeach; ?>
  </div>
 </div>
</div>

<!-- ═════════ SEARCH MODAL ═════════ -->
<div class="ds-ov" id="dsSearchOv" role="dialog" aria-modal="true" aria-label="Search discussions">
 <form class="dz-lane" method="GET" action="discussions.php">
  <input type="hidden" name="pod_id" value="<?= (int)$group_id ?>">
  <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
  <div class="dz-stop">
    <button type="button" class="dz-back" onclick="dsClose('dsSearchOv')" aria-label="Close search"><i class="ri-arrow-left-s-line"></i></button>
    <label class="dz-swrap"><i class="ri-search-line"></i><input type="text" name="q" id="dsSearchInput" placeholder="Search titles and post text…" value="<?= htmlspecialchars($search) ?>" autocomplete="off"></label>
    <button type="submit" class="dz-sbtn">Search</button>
  </div>
  <div class="dz-ctx"><i class="ri-shield-check-line"></i><span>Searching in <strong><?= htmlspecialchars($group['title']) ?></strong> Discussions</span></div>
  <div class="dz-body">
    <div class="dz-empty"><i class="ri-search-2-line"></i><p>Search this pod's discussions</p><small>Press Enter to search. Tap a topic on the main page to filter by type instead.</small></div>
  </div>
 </form>
</div>

<!-- ═════════ YOU MODAL ═════════ -->
<?php if ($is_member): ?>
<div class="ds-ov" id="dsYouOv" role="dialog" aria-modal="true" aria-label="You in this pod's discussions">
 <div class="dz-lane">
  <div class="dz-tb">
    <div class="dz-side"><button type="button" class="dz-x" onclick="dsClose('dsYouOv')" aria-label="Close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button></div>
    <div class="dz-tc"><div class="dz-tt">You in Discussions</div></div>
    <div class="dz-side right"></div>
  </div>
  <div class="dz-body" id="dsYouBody">
    <div class="dz-hero">
      <div class="dz-tabs">
        <button type="button" class="dz-pill active" onclick="dsYouTab(this,'dsYouOverview')"><i class="ri-dashboard-line"></i> Overview</button>
        <button type="button" class="dz-pill" onclick="dsYouTab(this,'dsYouManage')"><i class="ri-settings-3-line"></i> Manage posts</button>
      </div>
      <img src="<?= htmlspecialchars($me_pic) ?>" onerror="this.src='uploads/me.jpeg'" class="dz-bigpic" alt="Your profile picture">
    </div>

    <div id="dsYouOverview" class="dz-tab active">
      <div class="dz-stats">
        <div class="dz-stat"><b><?= count($ds_my) ?></b><span>Threads</span></div>
        <div class="dz-stat"><b><?= number_format($ds_my_likes) ?></b><span>Likes received</span></div>
        <div class="dz-stat"><b><?= number_format($ds_my_replies) ?></b><span>Replies received</span></div>
        <div class="dz-stat"><b><?= number_format($ds_total_replies) ?></b><span>Replies on board</span></div>
      </div>
    </div>

    <div id="dsYouManage" class="dz-tab">
      <div class="dz-sorts">
        <button type="button" class="dz-spill on" data-sort="newest" onclick="dsSortYou('newest',this)">Newest</button>
        <button type="button" class="dz-spill" data-sort="popular" onclick="dsSortYou('popular',this)">Popular</button>
        <button type="button" class="dz-spill" data-sort="oldest" onclick="dsSortYou('oldest',this)">Oldest</button>
      </div>
      <?php if (empty($ds_my)): ?>
        <div class="dz-nopost"><i class="ri-quill-pen-line"></i><p>You haven't posted any discussions in this pod yet.</p></div>
      <?php else: ?>
      <div id="dsYouList">
      <?php foreach ($ds_my as $mt):
          $tcc = $ds_tag_colors[$mt['tag']] ?? ['bg'=>'rgba(255,255,255,.06)','color'=>'rgba(255,255,255,.6)'];
          $ts = strtotime($mt['created_at']); ?>
        <div class="ds-yitem" data-ts="<?= $ts ?>" data-pop="<?= (int)$mt['like_count'] + (int)$mt['reply_count'] ?>">
          <a class="ds-ymain" href="<?= $ds_url_base ?>&open=<?= (int)$mt['id'] ?>">
            <span class="ds-ymeta">
              <span class="ds-tagchip" style="background:<?= $tcc['bg'] ?>;color:<?= $tcc['color'] ?>"><?= htmlspecialchars($mt['tag']) ?></span>
              <?php if ($mt['is_pinned']): ?><i class="ri-pushpin-fill ds-pinchip"></i><?php endif; ?>
              <?php if ($mt['is_locked']): ?><i class="ri-lock-fill" style="font-size:12px;color:var(--muted)"></i><?php endif; ?>
            </span>
            <span class="ds-yt"><?= htmlspecialchars($mt['title']) ?></span>
            <?php if ($mt['body']): ?><span class="ds-yb"><?= htmlspecialchars(mb_strimwidth($mt['body'], 0, 120, '…')) ?></span><?php endif; ?>
            <span class="ds-ys"><span><i class="ri-heart-line"></i><?= (int)$mt['like_count'] ?></span><span><i class="ri-chat-3-line"></i><?= (int)$mt['reply_count'] ?></span><span class="d"><?= date('d M Y', $ts) ?></span></span>
          </a>
          <form method="POST" class="ds-ydel" onsubmit="return confirm('Permanently delete this discussion?')">
            <input type="hidden" name="del_thread_id" value="<?= (int)$mt['id'] ?>">
            <button type="submit" name="delete_thread" title="Delete" aria-label="Delete discussion"><i class="ri-delete-bin-line"></i></button>
          </form>
        </div>
      <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <div class="dz-about">
      <div class="dz-about-h"><i class="ri-information-line"></i> What is this page?</div>
      <p>This is your personal space inside this pod's Discussions. Only you can see it. It brings together <strong>your own threads</strong> here and how people have reacted to them.</p>
      <div class="dz-about-sub">Overview tab</div>
      <p>A quick summary of your threads and the <strong>likes and replies</strong> other members have given them.</p>
      <div class="dz-about-sub">Manage posts tab</div>
      <p>Every thread you started, with its tag, likes, replies and date. Sort by newest, popular or oldest. The bin button deletes a thread after you confirm. <strong>Deleting is permanent and can't be undone.</strong></p>
      <div class="dz-about-sub">Where do these appear?</div>
      <p>Threads only show on this pod's Discussions page. They never appear in feeds.</p>
    </div>
  </div>
 </div>
</div>
<?php endif; ?>

<!-- ═════════ CREATE MODAL ═════════ -->
<?php if ($is_member): ?>
<div class="ds-ov" id="dsCreateOv" role="dialog" aria-modal="true" aria-label="Start a discussion">
 <form class="dz-lane" method="POST" enctype="multipart/form-data">
  <div class="dz-tb">
    <div class="dz-side"><button type="button" class="dz-x" onclick="closeCreateModal()" aria-label="Close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button></div>
    <div class="dz-tc"><div class="dz-tt">New Discussion</div></div>
    <div class="dz-side right"></div>
  </div>
  <div class="dz-body" id="dsCreateBody">
    <div class="dz-note">Posting in <b style="color:var(--text)"><?= htmlspecialchars($group['title']) ?></b>. Threads here only show on this Discussions page, never in feeds.</div>
    <div class="dz-field"><div class="dz-lbl">Title</div><input class="dz-inp" type="text" name="title" placeholder="What's this about?" required maxlength="200"></div>
    <div class="dz-field"><div class="dz-lbl">Body (optional)</div><textarea class="dz-ta" name="body" rows="3" placeholder="Share more context…"></textarea></div>
    <div class="dz-field">
      <div class="dz-lbl">Tag</div>
      <div class="ds-tags">
        <?php foreach (['Discussion','Question','Announcement','Suggestion','Poll','Help','Meme Talk','News'] as $tg): ?>
        <button type="button" class="ds-tagpill<?= $tg === 'Discussion' ? ' sel' : '' ?>" onclick="selectTag(this,'<?= $tg ?>')"><?= $tg ?></button>
        <?php endforeach; ?>
      </div>
      <input type="hidden" name="tag" id="tag-hid" value="Discussion">
    </div>

    <div id="poll-sec" style="display:none">
      <div class="dz-field">
        <div class="dz-lbl">Poll type</div>
        <div class="ds-ptype">
          <button type="button" class="ds-ptc sel" onclick="selectPollType(this,'vote')"><i class="ri-bar-chart-2-line"></i><b>Vote poll</b><small>Opinion, no right answer</small></button>
          <button type="button" class="ds-ptc" onclick="selectPollType(this,'quiz')"><i class="ri-trophy-line"></i><b>Quiz poll</b><small>One correct answer</small></button>
        </div>
        <input type="hidden" name="poll_type" id="poll-type-hid" value="vote">
      </div>
      <div class="dz-field">
        <div class="dz-lbl">Duration</div>
        <select class="dz-sel" name="poll_duration">
          <option value="1h">1 hour</option><option value="6h">6 hours</option><option value="1d">1 day</option><option value="3d">3 days</option>
          <option value="1w">1 week</option><option value="1mo">1 month</option><option value="1y">1 year</option><option value="forever" selected>Forever</option>
        </select>
      </div>
      <div class="dz-field">
        <div class="dz-lbl">Options</div>
        <div id="poll-opts">
          <div class="ds-popt"><input type="radio" name="correct_option" value="0" class="correct-radio" style="display:none"><input class="dz-inp" type="text" name="poll_options[]" placeholder="Option 1"></div>
          <div class="ds-popt"><input type="radio" name="correct_option" value="1" class="correct-radio" style="display:none"><input class="dz-inp" type="text" name="poll_options[]" placeholder="Option 2"></div>
        </div>
        <button type="button" class="ds-addopt" onclick="addPollOpt()"><i class="ri-add-line"></i> Add option</button>
        <div class="ds-qhint" id="pollQuizHint"><i class="ri-information-line"></i> Tap the circle next to the correct option</div>
      </div>
    </div>

    <div class="dz-field">
      <div class="dz-lbl">Attachments (optional)</div>
      <?php if ($has_prefill): ?>
      <div class="ds-prefill" id="cmPrefillNote"><i class="ri-information-line"></i><span>Carried over from your post. Remove any you don't want.</span></div>
      <?php endif; ?>
      <label class="ds-attach"><i class="ri-image-add-line"></i><span id="cm-file-lbl">Image / Video / GIF</span><input type="file" id="cm-media-input" accept="image/*,video/*,.gif" multiple></label>
      <div class="ds-prev" id="cm-media-preview"></div>
      <input type="file" name="media[]" id="cm-media-hidden" multiple style="display:none">
      <input type="hidden" name="existing_media" id="cm-existing-media-hidden" value="">
    </div>

    <div class="dz-field"><div class="ds-toggle"><input type="checkbox" name="disable_comments" id="disableComments" value="1"><label for="disableComments"><i class="ri-chat-off-line"></i> Turn off comments on this post</label></div></div>
  </div>
  <div class="dz-act">
    <button type="button" class="dz-btn ghost" onclick="closeCreateModal()">Cancel</button>
    <button type="submit" name="create_thread" class="dz-btn main"><i class="ri-send-plane-fill"></i> Post discussion</button>
  </div>
 </form>
</div>
<?php endif; ?>

<!-- ═════════ REPORT MODAL ═════════ -->
<div class="ds-ov" id="dsRptOv" role="dialog" aria-modal="true" aria-label="Report to pod admins">
 <div class="dz-lane">
  <div class="dz-tb">
    <div class="dz-side"><button type="button" class="dz-x" onclick="closeDsRpt()" aria-label="Close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button></div>
    <div class="dz-tc"><div class="dz-tt" id="dsRptTitle">Report to pod admins</div></div>
    <div class="dz-side right"></div>
  </div>
  <div class="dz-body" id="dsRptBody">
    <div class="dz-note">Sent directly to this pod's owner and admins. They never tell the poster who reported them.</div>
    <div class="dz-group">What should the admins know?</div>
    <div id="dsRptList"></div>
    <div class="ds-rother" id="dsRptOther">
      <textarea class="dz-ta" id="dsRptText" maxlength="500" placeholder="Please describe the issue in detail…" oninput="document.getElementById('dsRptCnt').textContent=this.value.length+' / 500'"></textarea>
      <div id="dsRptCnt" style="text-align:right;font-size:.62rem;color:var(--pm-mut);margin-top:5px">0 / 500</div>
    </div>
  </div>
  <div class="ds-rdone" id="dsRptDone" style="display:none">
    <i class="ri-shield-check-line"></i><b>Sent to admins</b>
    <p>This post has been flagged for the owner and admins of this pod to review.</p>
    <button type="button" class="dz-btn" style="flex:none;padding:0 40px" onclick="closeDsRpt()">Done</button>
  </div>
  <div class="dz-act" id="dsRptFoot">
    <button type="button" class="dz-btn ghost" onclick="closeDsRpt()">Cancel</button>
    <button type="button" class="dz-btn main" id="dsRptSubmit" onclick="dsRptSubmit()">Submit Report</button>
  </div>
 </div>
</div>

<style>
/* ═══ ACTION ROW — long-form modal icons (heart-shaped like, lucide heart-x dislike, immersive-viewer drawings),
   laid out left-to-right like the group page: icon with its count beside it, red when active ═══ */
:root{ --ds-ico:calc(clamp(17px, 1.06vw + 13.5px, 22px) * 1.166); }
@media (max-width:768px){ :root{ --ds-ico:calc(clamp(17px, 1.06vw + 13.5px, 22px) * 1.166 * 1.155); } }
.gf-actions-left{ flex:none; justify-content:flex-start; gap:calc(18px * var(--gf-s)); }
.gf-action-group{ flex:none; justify-content:flex-start; gap:calc(6px * var(--gf-s)); }
.gf-action-btn{ flex-direction:row; align-items:center; justify-content:flex-start; gap:calc(6px * var(--gf-s)); width:auto; height:auto; padding:2px; background:none; border:none; font-size:inherit; color:var(--text); -webkit-tap-highlight-color:transparent; transition:color .2s, transform .12s; }
.gf-action-btn:active{ transform:scale(1.18); }
.gf-action-btn .gf-ic{ display:flex; align-items:center; justify-content:center; width:var(--ds-ico); height:var(--ds-ico); font-style:normal; line-height:1; }
.gf-action-btn .gf-ic svg{ display:block; width:100%; height:100%; overflow:visible; }
.gf-action-count{ display:block; line-height:1; font-size:calc(12px * var(--gf-s)); font-weight:700; color:var(--text); }
.gf-action-count:empty{ display:none; }
.gf-action-group.active .gf-action-btn{ color:var(--red,#FF2D2D); }
.gf-action-group.active .gf-fillable svg{ fill:currentColor; }
.gf-action-group.active .gf-ic{ animation:dsActPop .34s ease; }
@keyframes dsActPop{ 0%{ transform:scale(1); } 45%{ transform:scale(1.28); } 100%{ transform:scale(1); } }
.gf-action-btn.off{ opacity:.4; }
@media (prefers-reduced-motion:reduce){ .gf-action-group.active .gf-ic{ animation:none; } }

/* ═══ POST OVERLAY (post + comments) — red theme, same as the feed ═══ */
.dcs,.dcs-ov{ --feed-blue:#ff2b2b; --feed-blue-rgb:255,43,43; --blue:#ff2b2b; --blue-hover:#ff4c4c; --blue-soft:#ff6b6b; --accent:#ff2b2b; --blue-text:#ff8a8a; }
:root[data-theme="light"] .dcs{ --blue-text:#d41f1f; --blue-soft:#e03a3a; }
.dcs-scroll{ scrollbar-color:rgba(255,43,43,.4) transparent; }
.dcs-scroll::-webkit-scrollbar-thumb{ background:rgba(255,43,43,.4); }
.dcs .ds-poll{ background:linear-gradient(160deg,rgba(255,43,43,.12),rgba(255,43,43,.03)); border-color:rgba(255,43,43,.32); }
.dcs .ds-poll.quiz{ background:linear-gradient(160deg,rgba(124,92,255,.12),rgba(124,92,255,.02)); border-color:rgba(124,92,255,.32); }
.dcs label.dp-opt:hover{ border-color:rgba(255,43,43,.6); background:rgba(255,43,43,.08); }
.dcs .dp-opt:has(input:checked){ border-color:var(--feed-blue); background:rgba(255,43,43,.16); }
.dcs .dp-bar{ background:rgba(255,43,43,.2); }
.dcs .dp-opt.correct .dp-bar{ background:rgba(61,220,132,.2); }
.dcs .dp-opt.wrong .dp-bar{ background:rgba(255,80,80,.16); }
.dcs .dp-pct{ color:var(--blue-soft); }
.dcs .dp-opt.correct .dp-pct{ color:var(--green); }

/* ═══ COMMENT SECTION — 8% smaller than the long-form sizes ═══ */
.dcs{ --dcs-s:.92; }
@media (max-width:790px){ .dcs{ --dcs-s:.994; } }
.gf-action-group.gf-dis.active .gf-ic svg{ fill:currentColor; }
.gf-action-group.gf-dis.active .gf-ic svg path:nth-child(-n+2){ stroke:var(--solid,#070924); }
.dcs-like.on,.dcs-dislike.on{ color:var(--red,#FF2D2D); }
.dcs-item{ --csav:calc(32px * var(--dcs-s)); --csgap:calc(10px * var(--dcs-s)); padding:calc(12px * var(--dcs-s)) 0; }
.dcs-user{ font-size:calc(.78rem * var(--dcs-s)); }
.dcs-user.is-owner{ font-size:calc(.74rem * var(--dcs-s)); }
.dcs-user.is-owner i{ font-size:calc(.78rem * var(--dcs-s)); }
.dcs-time{ font-size:calc(.68rem * var(--dcs-s)); }
.dcs-text{ font-size:calc(.8rem * var(--dcs-s)); line-height:1.5; margin-top:calc(2px * var(--dcs-s)); }
.dcs-row2{ gap:calc(18px * var(--dcs-s)); margin-top:calc(8px * var(--dcs-s)); }
.dcs-like,.dcs-dislike,.dcs-reply{ font-size:calc(.74rem * var(--dcs-s)); }
.dcs-like i,.dcs-dislike i{ font-size:calc(1.15rem * var(--dcs-s)); }
.dcs-more{ width:calc(30px * var(--dcs-s)); height:calc(30px * var(--dcs-s)); font-size:calc(1.2rem * var(--dcs-s)); }
.dcs-user .ds-vb{ width:.95em; height:.95em; margin-left:4px; }

/* ═══ ⋯ MENU SHEET — same design as the Post options sheet in shared_modals (lane-coloured sheet, thin handle,
   one rounded inner card with a tiny uppercase title, round dashed icons, separate Cancel card) ═══ */
#dsSheet.idx-sheet-panel{ --dz-s:1.1; --dz-lane:#070924; --dz-card:#090b29; --dz-fg:255,255,255; --dz-text:#F0F2FF; --dz-red:#FF2D2D;
    background:var(--dz-lane); border:0; border-radius:0 !important; box-shadow:none; backdrop-filter:none; -webkit-backdrop-filter:none;
    padding:calc(5px * var(--dz-s)) 0 calc(env(safe-area-inset-bottom,0px) + 10px * var(--dz-s)); gap:calc(8px * var(--dz-s));
    color:var(--dz-text); font-family:Poppins,sans-serif; max-height:60vh; display:flex; flex-direction:column; }
:root[data-theme="light"] #dsSheet.idx-sheet-panel{ --dz-lane:#ffffff; --dz-card:#eef1f7; --dz-fg:15,20,40; --dz-text:#14171f; }
#dsSheetOv.idx-sheet-overlay{ background:rgba(0,0,0,.6); backdrop-filter:blur(2px); -webkit-backdrop-filter:blur(2px); }
#dsSheet .idx-sheet-handle{ display:block; width:calc(30px * var(--dz-s)); height:calc(3px * var(--dz-s)); border-radius:2px; background:rgba(var(--dz-fg),.22); margin:0 auto; flex-shrink:0; }
#dsSheet .idx-sheet-list{ background:var(--dz-card); border-radius:calc(6px * var(--dz-s)); margin:0 calc(10px * var(--dz-s)); flex:0 1 auto; min-height:0; overflow-y:auto; scrollbar-width:thin; scrollbar-color:rgba(var(--dz-fg),.15) transparent; }
#dsSheet .idx-sheet-list::-webkit-scrollbar-thumb{ background:rgba(var(--dz-fg),.15); }
#dsSheetList::before{ content:'Post options'; display:block; padding:calc(7px * var(--dz-s)) calc(12px * var(--dz-s)) calc(3px * var(--dz-s)); font-size:calc(8.5px * var(--dz-s)); color:rgba(var(--dz-fg),.35); letter-spacing:1px; text-transform:uppercase; }
#dsSheet .idx-sheet-item{ display:flex; align-items:center; gap:calc(9px * var(--dz-s)); padding:calc(6px * var(--dz-s)) calc(12px * var(--dz-s)); color:var(--dz-text); font-size:calc(11.5px * var(--dz-s)); font-weight:600; border:0; border-top:1px solid rgba(var(--dz-fg),.08); transition:background .2s; text-decoration:none; }
#dsSheet .idx-sheet-item:hover,#dsSheet .idx-sheet-item:active{ background:rgba(var(--dz-fg),.06); }
#dsSheet .idx-sheet-item > i{ width:calc(27px * var(--dz-s)); height:calc(27px * var(--dz-s)); border-radius:50%; flex-shrink:0; box-sizing:border-box; display:flex; align-items:center; justify-content:center; text-align:center; border:1.5px dashed rgba(var(--dz-fg),.3); color:rgba(var(--dz-fg),.6); font-size:calc(14px * var(--dz-s)); }
/* Delete Post / Report to Admins are white like every other item */
:root #dsSheet .idx-sheet-item.danger,:root #dsSheet .idx-sheet-item.report{ color:var(--dz-text); }
:root #dsSheet .idx-sheet-item.danger > i,:root #dsSheet .idx-sheet-item.report > i{ color:rgba(var(--dz-fg),.6); border-color:rgba(var(--dz-fg),.3); }
#dsSheet .idx-sheet-cancel{ display:flex; align-items:center; justify-content:center; gap:calc(6px * var(--dz-s)); margin:0 calc(10px * var(--dz-s)); padding:calc(10px * var(--dz-s)); background:var(--dz-card); border:0; border-radius:calc(6px * var(--dz-s)); color:rgba(var(--dz-fg),.85); font-size:calc(12px * var(--dz-s)); font-weight:600; font-family:Poppins,sans-serif; flex-shrink:0; -webkit-tap-highlight-color:transparent; transition:background .2s, color .2s; }
#dsSheet .idx-sheet-cancel:hover{ color:var(--dz-red); background:var(--dz-card); }
@media (min-width:791px){
    #dsSheet.idx-sheet-panel{ max-width:360px; width:90vw; border-radius:calc(8px * var(--dz-s)) !important; padding:calc(14px * var(--dz-s)) 0 calc(16px * var(--dz-s)); gap:calc(12px * var(--dz-s)); max-height:80vh; box-shadow:0 16px 40px rgba(0,0,0,.6); }
    #dsSheet .idx-sheet-handle{ display:none; }
    #dsSheet .idx-sheet-list{ margin:0 calc(14px * var(--dz-s)); border-radius:calc(4px * var(--dz-s)); }
    #dsSheet .idx-sheet-cancel{ margin:0 calc(14px * var(--dz-s)); }
    #dsSheetList::before{ padding:calc(12px * var(--dz-s)) calc(16px * var(--dz-s)) calc(6px * var(--dz-s)); font-size:calc(10px * var(--dz-s)); }
    #dsSheet .idx-sheet-item{ gap:calc(10px * var(--dz-s)); padding:calc(10px * var(--dz-s)) calc(14px * var(--dz-s)); font-size:calc(13px * var(--dz-s)); }
    #dsSheet .idx-sheet-item > i{ width:calc(34px * var(--dz-s)); height:calc(34px * var(--dz-s)); font-size:calc(17px * var(--dz-s)); }
}
</style>

<!-- ═════════ ⋯ MENU SHEET ═════════ -->
<div class="idx-sheet-overlay" id="dsSheetOv" onclick="closeDsSheet()"></div>
<div class="idx-sheet-panel" id="dsSheet" role="dialog" aria-label="Post options">
  <div class="idx-sheet-handle"></div>
  <div class="idx-sheet-list" id="dsSheetList"></div>
  <button type="button" class="idx-sheet-cancel" onclick="closeDsSheet()">Cancel</button>
</div>
<form method="POST" id="dsActForm" style="display:none"></form>

<!-- ═════════ COMMENTS SHEET ═════════ -->
<div class="dcs-ov" id="dcsOv" onclick="dcsClose()"></div>
<div class="dcs" id="dcs" role="dialog" aria-label="Comments">
  <div class="dcs-handle" id="dcsHandle"><span></span></div>

  <div class="dcs-head" id="dcsHead">
    <button type="button" class="dcs-x" id="dcsClose" aria-label="Close"><i class="ri-close-line"></i></button>
    <div class="dcs-title">Post</div>
  </div>

  <div class="dcs-head dcs-thead" id="dcsTHead">
    <button type="button" class="dcs-tback" id="dcsTBack" aria-label="Back to comments"><i class="ri-arrow-left-s-line"></i></button>
    <div class="dcs-title">Replies</div>
    <button type="button" class="dcs-x" id="dcsTClose" aria-label="Close comments"><i class="ri-close-line"></i></button>
  </div>

  <div class="dcs-scroll" id="dcsScroll">
    <div class="dcs-thrd dpv-wrap" id="dcsThreadInfo"></div>
    <div class="dcs-cbar" id="dcsCmtBar">
    <div class="dcs-title">Comments<span class="dcs-count" id="dcsCount"></span></div>
    <div class="dcs-sort">
      <button type="button" class="dcs-sortbtn" id="dcsSortBtn"><span id="dcsSortLbl">Top</span><i class="ri-arrow-down-s-line"></i></button>
      <div class="dcs-sortmenu" id="dcsSortMenu">
        <button type="button" class="active" data-sort="top">Top comments</button>
        <button type="button" data-sort="new">Newest first</button>
        <button type="button" data-sort="old">Oldest first</button>
      </div>
    </div>
    </div>
    <div class="dcs-note"><i class="ri-shield-check-line"></i><span>Remember to keep comments respectful.</span></div>
    <div id="dcsSkel">
      <div class="dcs-sk"><div class="sk-sh" style="width:28px;height:28px;border-radius:50%;flex-shrink:0"></div><div style="flex:1"><div class="sk-sh" style="height:10px;width:40%"></div><div class="sk-sh" style="height:10px;width:85%;margin-top:8px"></div></div></div>
      <div class="dcs-sk"><div class="sk-sh" style="width:28px;height:28px;border-radius:50%;flex-shrink:0"></div><div style="flex:1"><div class="sk-sh" style="height:10px;width:30%"></div><div class="sk-sh" style="height:10px;width:70%;margin-top:8px"></div></div></div>
    </div>
    <div id="dcsList"></div>
    <div class="dcs-empty" id="dcsEmpty" style="display:none">No comments yet. Start the conversation.</div>
    <div id="dcsSentinel" style="height:2px"></div>
  </div>

  <div class="dcs-scroll dcs-tscroll" id="dcsTScroll" style="display:none">
    <div class="dcs-troot" id="dcsTRoot"></div>
    <div id="dcsTSkel" style="display:none"><div class="dcs-sk"><div class="sk-sh" style="width:28px;height:28px;border-radius:50%;flex-shrink:0"></div><div style="flex:1"><div class="sk-sh" style="height:10px;width:40%"></div><div class="sk-sh" style="height:10px;width:85%;margin-top:8px"></div></div></div></div>
    <div id="dcsTList"></div>
    <div id="dcsTSentinel" style="height:2px"></div>
  </div>

  <div class="dcs-composer" id="dcsComposer">
    <span class="dcs-myav" id="dcsMyAv"></span>
    <input type="text" id="dcsInput" placeholder="Add a comment..." maxlength="1000">
    <button type="button" class="dcs-send" id="dcsSend" aria-label="Post comment" disabled>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5"/><path d="M5.5 11.5 12 5l6.5 6.5"/></svg>
    </button>
  </div>
  <div class="dcs-menu" id="dcsMenu" role="menu">
    <button type="button" data-act="download" role="menuitem"><i class="ri-download-2-line"></i><span>Download comment</span></button>
    <button type="button" data-act="delete" role="menuitem"><i class="ri-delete-bin-line"></i><span>Delete comment</span></button>
  </div>
</div>

<script>
/* ═════════ helpers ═════════ */
function $(id){return document.getElementById(id)}
function esc(s){if(s===null||s===undefined)return'';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;')}
function fmtNum(n){n=parseInt(n)||0;if(n>=1e9)return(n/1e9).toFixed(1).replace(/\.0$/,'')+'B';if(n>=1e6)return(n/1e6).toFixed(1).replace(/\.0$/,'')+'M';if(n>=1e3)return(n/1e3).toFixed(1).replace(/\.0$/,'')+'K';return n}
function fullDate(s){if(!s)return'';var d=new Date(String(s).replace(' ','T'));if(isNaN(d))return s;var D=['Sun','Mon','Tue','Wed','Thu','Fri','Sat'],M=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];return D[d.getDay()]+', '+d.getDate()+' '+M[d.getMonth()]+' '+d.getFullYear()+' · '+String(d.getHours()).padStart(2,'0')+':'+String(d.getMinutes()).padStart(2,'0')}
function shortDate(s){var d=new Date(String(s||'').replace(' ','T'));if(isNaN(d))return'';var M=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];return M[d.getMonth()]+' '+d.getDate()}
function isNewPost(s){var d=new Date(String(s||'').replace(' ','T'));return!isNaN(d)&&(Date.now()-d.getTime())<=172800000}
function dsToast(msg){var t=$('dsToast');t.textContent=msg;t.classList.add('show');clearTimeout(t._t);t._t=setTimeout(function(){t.classList.remove('show')},2300)}
function findPost(id){return DS_FEED.find(function(x){return String(x.id)===String(id)})}
function dsPost(action,data){var b=new URLSearchParams();b.append(action,'1');b.append('csrf_token',CSRF_TOKEN);for(var k in(data||{})){if(data[k]!==null&&data[k]!==undefined)b.append(k,data[k])}
  return fetch(DS_URL,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},body:b.toString()}).then(function(r){return r.json()})}
function dsGet(qs){return fetch(DS_URL+'&'+qs,{headers:{'X-Requested-With':'XMLHttpRequest'}}).then(function(r){return r.json()})}
function typeInfo(t){t=(t||'').toLowerCase();return({video:{icon:'ri-vidicon-line',label:'Video'},gif:{icon:'ri-file-gif-line',label:'GIF'}})[t]||{icon:'ri-file-copy-line',label:'Photo'}}

/* CSRF on every POST form */
document.addEventListener('DOMContentLoaded',function(){document.querySelectorAll('form[method="POST"],form[method="post"]').forEach(function(f){if(!f.querySelector('input[name="csrf_token"]')){var i=document.createElement('input');i.type='hidden';i.name='csrf_token';i.value=CSRF_TOKEN;f.appendChild(i)}})});

/* header description: one single line */
(function(){
  var el=$('gpDesc');if(!el)return;
  function fit(){el.style.fontSize='';var cs=parseFloat(getComputedStyle(el).fontSize)||11;
    while(el.scrollWidth>el.clientWidth+1&&cs>8.5){cs-=.25;el.style.fontSize=cs+'px'}}
  fit();window.addEventListener('resize',fit);window.addEventListener('load',fit);
  if(document.fonts&&document.fonts.ready)document.fonts.ready.then(fit);
})();

/* ═════════ full-screen modals ═════════ */
function dsLock(){var any=document.querySelector('.ds-ov.open,.dcs.open,.idx-sheet-panel.show');document.body.style.overflow=any?'hidden':''}
function dsOpen(id){var e=$(id);if(!e)return;e.classList.add('open');document.body.style.overflow='hidden';var b=e.querySelector('.dz-body');if(b)b.scrollTop=0;if(id==='dsSearchOv')setTimeout(function(){var i=$('dsSearchInput');if(i)i.focus()},150)}
function dsClose(id){var e=$(id);if(!e)return;e.style.animation='none';e.classList.remove('open');e.style.animation='';dsLock()}

/* ═════════ top bar behaviour ═════════ */
(function(){
  var bar=$('gpTopbar'),banner=$('gpBanner'),nameEl=document.querySelector('.gp-name'),nav=$('gpFilterBar'),sent=$('gpNavSentinel');
  if(!bar||!banner)return;
  function on(){var h=bar.offsetHeight;document.documentElement.style.setProperty('--topbar-h',h+'px');
    bar.classList.toggle('scrolled',window.scrollY>banner.offsetHeight-h);
    if(nameEl)bar.classList.toggle('show-title',nameEl.getBoundingClientRect().bottom<=h)}
  window.addEventListener('scroll',on,{passive:true});window.addEventListener('resize',on);on();
  if(sent&&nav&&'IntersectionObserver' in window){var io;function watch(){if(io)io.disconnect();var h=Math.round(bar.offsetHeight);
    io=new IntersectionObserver(function(es){es.forEach(function(e){var stuck=!e.isIntersecting&&e.boundingClientRect.top<h;nav.classList.toggle('is-stuck',stuck)})},{threshold:0,rootMargin:'-'+h+'px 0px 0px 0px'});io.observe(sent)}
    watch();window.addEventListener('resize',watch)}
})();

/* ═════════ About ⇄ Feed ═════════ */
window.dsSetAbout=function(on){
  var page=$('dsPage');if(!page)return;
  if(page.classList.contains('about-mode')===!!on)return;
  page.classList.toggle('about-mode',!!on);
  $('dsNavFeed').classList.toggle('active',!on);
  var ab=$('dsNavAbout');ab.classList.toggle('active',!!on);ab.setAttribute('aria-pressed',on?'true':'false');
  if(on)document.querySelectorAll('#dsFeed video').forEach(function(v){try{v.pause()}catch(e){}});
  var nw=$('gpNav'),bar=$('gpTopbar');
  if(nw){var limit=nw.getBoundingClientRect().bottom+window.scrollY-(bar?bar.offsetHeight:0);if(window.scrollY>limit)window.scrollTo(0,Math.max(0,limit))}
  setTimeout(function(){window.dispatchEvent(new Event('resize'));window.dispatchEvent(new Event('scroll'))},30);
};

/* right-column cards: collapse / expand */
window.gsToggle=function(btn){
  var card=btn&&btn.closest?btn.closest('.gs-card'):null;if(!card)return;
  var c=card.classList.toggle('collapsed');btn.setAttribute('aria-expanded',c?'false':'true');
  try{localStorage.setItem('ds_gs_'+DS_GROUP_ID+'_'+(card.getAttribute('data-k')||''),c?'1':'0')}catch(e){}
};
(function(){document.querySelectorAll('.gs-card').forEach(function(card){try{
  if(localStorage.getItem('ds_gs_'+DS_GROUP_ID+'_'+(card.getAttribute('data-k')||''))==='1'){card.classList.add('collapsed');var b=card.querySelector('.gs-bar');if(b)b.setAttribute('aria-expanded','false')}}catch(e){}})})();

/* filter bar hover arrows (normal view) */
(function(){
  var el=$('gpFilterScrollEl'),bar=$('gpFilterBar');if(!el||!bar)return;
  var l=bar.querySelector('.gp-f-arrow-l'),r=bar.querySelector('.gp-f-arrow-r');
  function up(){var max=el.scrollWidth-el.clientWidth;l.classList.toggle('hidden',el.scrollLeft<=4);r.classList.toggle('hidden',max<=4||el.scrollLeft>=max-4)}
  el.addEventListener('scroll',up,{passive:true});window.addEventListener('resize',up);window.addEventListener('load',up);up();setTimeout(up,300);setTimeout(up,1000);
  window.dsFilterScroll=function(d){el.scrollBy({left:d*Math.max(120,el.clientWidth*.75),behavior:'smooth'})};
})();

/* ═════════ FEED ENGINE ═════════ */
(function(){
  var BATCH=4,listEl=$('dsFeed'),FEED=[],rendered=0,loading=false,sentObs=null,buildId=0,postObs=null;
  window.DS_TAG='';

  function pollHtml(p){
    var pl=p.poll;if(!pl)return'';
    var quiz=pl.type==='quiz',show=pl.voted||pl.expired,canVote=!show&&DS_IS_MEMBER;
    var timer=pl.expires?('<span class="dp-timer"><i class="ri-time-line"></i><span class="dp-cd" data-expires="'+pl.expires+'">'+(pl.expired?'Ended':'')+'</span></span>'):'<span class="dp-timer"><i class="ri-infinity-line"></i>'+(quiz?'No time limit':'Forever')+'</span>';
    var h='<div class="ds-poll'+(quiz?' quiz':'')+'"><div class="dp-hdr"><span class="dp-badge"><i class="'+(quiz?'ri-trophy-fill':'ri-bar-chart-2-fill')+'"></i>'+(quiz?'Quiz':'Poll')+'</span>'+timer+'</div>';
    if(canVote){
      h+='<form method="POST" action="'+esc(DS_URL)+'"><input type="hidden" name="csrf_token" value="'+esc(CSRF_TOKEN)+'"><input type="hidden" name="discussion_id" value="'+p.id+'"><input type="hidden" name="vote_poll" value="1"><div class="dp-opts">';
      pl.options.forEach(function(o){h+='<label class="dp-opt"><input type="radio" name="option_id" value="'+o.id+'" required><span class="dp-txt">'+esc(o.text)+'</span></label>'});
      h+='</div><button type="submit" class="dp-submit">'+(quiz?'Submit answer':'Cast vote')+'</button></form>';
    } else {
      h+='<div class="dp-opts">';
      pl.options.forEach(function(o){
        var pct=pl.total>0?Math.round(o.votes/pl.total*100):0,mine=pl.voted===o.id,cls='dp-opt';
        if(quiz){if(o.correct)cls+=' correct';else if(mine)cls+=' wrong'}
        if(mine)cls+=' picked';
        var ic=quiz?(o.correct?'<i class="ri-checkbox-circle-fill dp-ic"></i>':(mine?'<i class="ri-close-circle-fill dp-ic"></i>':'')):(mine?'<i class="ri-checkbox-circle-fill dp-ic" style="color:var(--blue-soft)"></i>':'');
        h+='<div class="'+cls+'"><div class="dp-bar" style="width:'+pct+'%"></div><span class="dp-txt">'+esc(o.text)+'</span>'+ic+'<span class="dp-pct">'+pct+'%</span></div>';
      });
      h+='</div>';
      var note=pl.total+(quiz?' answer':' vote')+(pl.total!==1?'s':'');
      if(quiz&&pl.voted){var co=pl.options.filter(function(o){return o.correct})[0];note+=' · '+((co&&co.id===pl.voted)?'<b>You got it right! 🎉</b>':'Not quite — correct answer highlighted')}
      else if(pl.expired)note+=' · '+(quiz?'Quiz':'Poll')+' ended';
      else if(!DS_IS_MEMBER)note+=' · Join the pod to take part';
      h+='<div class="dp-note">'+note+'</div>';
    }
    return h+'</div>';
  }

  function renderPost(p){
    var media=p.media||[],mc=media.length,textOnly=(mc===0);
    var tc=DS_TAG_COLORS[p.tag]||{bg:'rgba(255,255,255,.06)',color:'rgba(255,255,255,.6)'};
    var name=p.isOwn?'You':esc(p.username);
    var track=!p.isOwn?'<span class="gf-dot-sep-red">•</span><button type="button" class="gf-track-btn" data-user="'+p.userId+'" onclick="event.stopPropagation();dsTrack(this)">'+(p.following?'Untrack':'Track')+'</button>':'';
    var sub='<i class="ri-time-line"></i><span>'+esc(isNewPost(p.time)?'New · '+shortDate(p.time):shortDate(p.time))+'</span>'+(p.locked?'<i class="ri-lock-fill" title="Locked"></i>':'')+(p.commentsOff?'<i class="ri-chat-off-line" title="Comments off"></i>':'');
    var body=p.body?'<div class="ds-bw"><div class="ds-body clamp">'+esc(p.body)+'</div><button type="button" class="ds-more" style="display:none" onclick="dsToggleBody(this)">…more</button></div>':'';
    var src=p.sourceMeme?'<div class="ds-src"><i class="ri-image-2-line"></i><span>From Zeem</span><span>·</span><a href="group.php?id='+DS_GROUP_ID+'&meme='+p.sourceMeme+'" onclick="event.stopPropagation()">View Zeem <i class="ri-arrow-right-up-line"></i></a></div>':'';
    var tagLine='<div class="ds-tagline"><span class="ds-tagchip" style="background:'+tc.bg+';color:'+tc.color+'">'+esc(p.tag)+'</span>'+(p.pinned?'<i class="ri-pushpin-fill ds-pinchip" title="Pinned"></i>':'')+'</div>';
    var textBlock='<div class="ds-text">'+tagLine+(p.title?'<div class="ds-title">'+esc(p.title)+'</div>':'')+body+'</div>';

    var mediaBlock='';
    if(!textOnly){
      var hasVideo=media.some(function(m){return m.type==='video'});
      var inner='<div class="gf-media-track">'+media.map(function(m,i){
        if(m.type==='video')return'<div class="gf-media-item" data-idx="'+i+'" data-type="video"><video src="'+esc(m.file)+'" muted loop playsinline preload="metadata" class="gf-video" onclick="event.stopPropagation();dsMediaTap(event,this)"></video></div>';
        return'<div class="gf-media-item" data-idx="'+i+'" data-type="'+esc(m.type||'image')+'"><img src="'+esc(m.file)+'" loading="lazy" draggable="false" onclick="event.stopPropagation();dsMediaTap(event,this)"></div>'}).join('')+'</div>';
      var ti=typeInfo(media[0].type);
      var dots=mc>1?'<div class="gf-dots">'+media.slice(0,20).map(function(_,i){return'<span class="gf-dot'+(i===0?' active':'')+'"></span>'}).join('')+'</div>':'';
      var tags='<div class="gf-top-right-tags">'+(mc>1?'<div class="gf-media-counter">1/'+mc+'</div>':'')+'</div>';
      var arrows=mc>1?'<button type="button" class="gf-media-arrow gf-arrow-left" aria-label="Previous" onclick="event.stopPropagation();dsArrow(this,-1)"><i class="ri-arrow-left-s-line"></i></button><button type="button" class="gf-media-arrow gf-arrow-right" aria-label="Next" onclick="event.stopPropagation();dsArrow(this,1)"><i class="ri-arrow-right-s-line"></i></button>':'';
      var ctl=hasVideo?'<div class="gf-media-controls"><button type="button" class="gf-mute-btn" onclick="event.stopPropagation();dsToggleMute(this)"><i class="dz-snd off"></i></button></div>':'';
      mediaBlock='<div class="gf-media-wrap'+(mc>1?' gf-multi':'')+'" data-count="'+mc+'" data-active-idx="0">'+tags+inner+arrows+ctl+dots+'</div>';
    }

    return'<div class="gf-post'+(textOnly?' gf-post-text':'')+(p.pinned?' is-pinned':'')+'" id="ds-post-'+p.id+'" data-id="'+p.id+'" data-tag="'+esc(p.tag)+'">'+
      '<div class="gf-header">'+
        '<span class="av-wrap" data-uid="'+p.userId+'"><img class="gf-avatar" src="'+esc(p.pic)+'" onerror="this.src=\'uploads/me.jpeg\'" onclick="location.href=\'profile.php?user_id='+p.userId+'\'">'+(p.online?'<i class="on-dot"></i>':'')+'</span>'+
        '<div class="gf-header-info"><div class="gf-username-row"><span class="gf-username" onclick="location.href=\'profile.php?user_id='+p.userId+'\'">'+name+(p.isVerified?DS_VB:'')+'</span>'+
          track+'</div>'+
          '<div class="gf-sub-line">'+sub+'</div></div>'+
        '<button type="button" class="gf-kebab" onclick="event.stopPropagation();openDsSheet('+p.id+')" aria-label="More options"><i class="ri-menu-line"></i></button>'+
      '</div>'+
      textBlock+src+mediaBlock+pollHtml(p)+
      '<div class="gf-actions"><div class="gf-actions-left">'+
        '<div class="gf-action-group gf-like'+(p.liked?' active':'')+'"><button type="button" class="gf-action-btn" aria-label="Like" onclick="event.stopPropagation();dsLike('+p.id+',\'like\')"><i class="gf-ic gf-fillable"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg></i><span class="gf-action-count gf-like-count">'+fmtNum(p.likes)+'</span></button></div>'+
        '<div class="gf-action-group gf-dis'+(p.disliked?' active':'')+'"><button type="button" class="gf-action-btn" aria-label="Dislike" onclick="event.stopPropagation();dsLike('+p.id+',\'dislike\')"><i class="gf-ic"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="m15.5 12.5 5 5"/><path d="m20.5 12.5-5 5"/><path d="M21.955 8.774a5.5 5.5 0 0 0-9.546-2.95.6.6 0 0 1-.818 0A5.5 5.5 0 0 0 2 9.5c0 2.3 1.5 4 3 5.5l5.508 5.332a2 2 0 0 0 2.57.352"/></svg></i><span class="gf-action-count"></span></button></div>'+
        '<div class="gf-action-group gf-comm"><button type="button" class="gf-action-btn'+(p.commentsOff?' off':'')+'" aria-label="Comments" onclick="event.stopPropagation();dcsOpen('+p.id+')"><i class="gf-ic"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4.5A1.5 1.5 0 0 1 4.5 3h15A1.5 1.5 0 0 1 21 4.5v11a1.5 1.5 0 0 1-1.5 1.5H7l-4 4z"/></svg></i><span class="gf-action-count gf-comm-count">'+(p.commentsOff?'':fmtNum(p.replies))+'</span></button></div>'+
        '<div class="gf-action-group"><button type="button" class="gf-action-btn" aria-label="Share" onclick="event.stopPropagation();dsShare('+p.id+')"><i class="gf-ic"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M13.4 4.3c0-.7.8-1 1.3-.6l6.9 6.2c.4.4.4 1 0 1.4l-6.9 6.2c-.5.4-1.3.1-1.3-.6v-3.3c-4.3-.1-7.3 1-9.4 4-.4.6-1.3.3-1.2-.4.7-6 4-10.2 10.6-10.7z"/></svg></i><span class="gf-action-count"></span></button></div>'+
      '</div></div>'+
      '<div class="gf-time">'+esc(fullDate(p.time))+'</div>'+
    '</div>';
  }

  function skeletonHTML(n){var one='<div class="gf-skel-post"><div class="gf-skel-header"><div class="gf-skel-avatar"></div><div class="gf-skel-header-lines"><div class="gf-skel-line w1"></div><div class="gf-skel-line w2"></div></div></div><div class="gf-skel-cap"></div><div class="gf-skel-media" style="margin-top:12px"></div><div class="gf-skel-actions"><div class="gf-skel-icon"></div><div class="gf-skel-icon"></div><div class="gf-skel-icon"></div><div class="gf-skel-icon"></div></div></div>';return new Array(n+1).join(one)}

  window.dsToggleBody=function(btn){var w=btn.parentNode,b=w.querySelector('.ds-body');var c=b.classList.toggle('clamp');w.classList.toggle('open',!c);btn.textContent=c?'…more':'less'};
  function measureBodies(root){(root||document).querySelectorAll('.ds-body.clamp').forEach(function(el){var btn=el.nextElementSibling;if(btn&&btn.classList.contains('ds-more')&&el.scrollHeight>el.clientHeight+2)btn.style.display='inline-block'})}

  function renderBatch(count,animate){
    var end=Math.min(rendered+count,FEED.length),html='';
    for(var i=rendered;i<end;i++)html+='<div class="gf-card-unit">'+renderPost(FEED[i])+'</div>';
    var tmp=document.createElement('div');tmp.innerHTML=html;
    var frag=document.createDocumentFragment(),units=[];
    Array.prototype.slice.call(tmp.children).forEach(function(n){units.push(n);if(animate&&n.firstElementChild)n.firstElementChild.classList.add('gf-post-enter');frag.appendChild(n)});
    var s=$('dsSentinel');if(s)listEl.insertBefore(frag,s);else listEl.appendChild(frag);
    rendered=end;
    applyHidden(units);bindCarousels(units);observePosts(units);
    measureBodies(listEl);updateCountdowns();
    if(rendered>=FEED.length){var s2=$('dsSentinel');if(s2)s2.remove();if(sentObs){sentObs.disconnect();sentObs=null}}else ensureSentinel();
  }
  function ensureSentinel(){
    var s=$('dsSentinel');
    if(!s){s=document.createElement('div');s.id='dsSentinel';s.className='gf-load-more';s.innerHTML='<div class="gf-load-more-dot"></div><div class="gf-load-more-dot"></div><div class="gf-load-more-dot"></div>';listEl.appendChild(s)}
    if(sentObs)sentObs.disconnect();
    if(!('IntersectionObserver' in window)){renderBatch(FEED.length,false);return}
    sentObs=new IntersectionObserver(function(es){es.forEach(function(e){if(e.isIntersecting&&!loading&&rendered<FEED.length){loading=true;setTimeout(function(){renderBatch(BATCH,true);loading=false},110)}})},{rootMargin:'600px 0px'});
    sentObs.observe(s);
  }

  function rebuild(){
    var my=++buildId;
    if(sentObs){sentObs.disconnect();sentObs=null}
    if(postObs){postObs.disconnect();postObs=null}
    loading=false;rendered=0;
    FEED=window.DS_TAG?DS_FEED.filter(function(p){return p.tag===window.DS_TAG}):DS_FEED.slice();
    if(!FEED.length){
      var none=DS_FEED.length&&window.DS_TAG;
      listEl.innerHTML='<div class="gf-empty"><i class="ri-chat-4-line"></i>'+(none?'No “'+esc(window.DS_TAG)+'” discussions here yet.':esc(DS_EMPTY_MSG)+(DS_IS_MEMBER?'<br>Be the first to start a conversation.':'<br>Join this pod to start one.'))+(DS_IS_MEMBER&&!none?'<br><button type="button" onclick="openCreateModal()">New discussion</button>':'')+'</div>';
      return;
    }
    listEl.innerHTML=skeletonHTML(3);
    setTimeout(function(){if(my!==buildId)return;listEl.innerHTML='';renderBatch(BATCH,true);if(window.DS_OPEN&&!window._dsOpened){window._dsOpened=true;dsGoTo(DS_OPEN)}},350);
  }
  window.dsRebuild=rebuild;

  window.dsFilterTag=function(tag){
    if($('dsPage').classList.contains('about-mode'))window.dsSetAbout(false);
    window.DS_TAG=(window.DS_TAG===tag)?'':tag;
    document.querySelectorAll('.gp-tag-pill').forEach(function(p){p.classList.toggle('active-tag',p.dataset.tag===window.DS_TAG)});
    rebuild();
    var fb=$('gpFilterBar');if(fb&&window.scrollY>fb.offsetTop)window.scrollTo({top:Math.max(0,fb.offsetTop-document.querySelector('.gp-topbar').offsetHeight+1),behavior:'smooth'});
  };

  window.dsGoTo=function(id){
    if($('dsPage').classList.contains('about-mode'))window.dsSetAbout(false);
    var tries=0;
    (function seek(){
      var el=$('ds-post-'+id);
      if(el){var off=(document.querySelector('.gp-topbar').offsetHeight||52)+($('gpFilterBar').offsetHeight||40)+6;window.scrollTo({top:el.getBoundingClientRect().top+window.scrollY-off,behavior:'smooth'});el.classList.add('flash');setTimeout(function(){el.classList.remove('flash')},1700);return}
      if(window.DS_TAG&&!FEED.some(function(p){return String(p.id)===String(id)})){window.dsFilterTag(window.DS_TAG)}
      if(rendered<FEED.length&&tries<60){tries++;renderBatch(BATCH,false);setTimeout(seek,30)}
    })();
  };

  function observePosts(units){
    if(!('IntersectionObserver' in window))return;
    if(!postObs)postObs=new IntersectionObserver(function(es){es.forEach(function(e){var v=e.target.querySelector('.gf-media-item video');if(!v)return;
      var wrap=e.target.querySelector('.gf-media-wrap'),idx=wrap?parseInt(wrap.dataset.activeIdx||'0',10):0;
      var act=e.target.querySelector('.gf-media-item[data-idx="'+idx+'"] video');
      e.target.querySelectorAll('video').forEach(function(x){if(x!==act)x.pause()});
      if(act){if(e.isIntersecting&&e.intersectionRatio>.6)act.play().catch(function(){});else act.pause()}})},{threshold:[0,.6,1]});
    units.forEach(function(u){var p=u.querySelector('.gf-post');if(p)postObs.observe(p)});
  }

  function bindCarousels(units){
    units.forEach(function(unit){
      var wrap=unit.querySelector('.gf-media-wrap[data-count]');if(!wrap)return;
      var count=parseInt(wrap.dataset.count,10);if(count<=1)return;
      var track=wrap.querySelector('.gf-media-track'),dots=wrap.querySelectorAll('.gf-dot'),counter=wrap.querySelector('.gf-media-counter');
      var idx=0,startX=0,startY=0,dx=0,dragging=false,lock=null,DEAD=12;
      function goTo(n){idx=Math.max(0,Math.min(n,count-1));wrap.dataset.activeIdx=String(idx);track.style.transform='translateX(-'+(idx*100)+'%)';
        dots.forEach(function(d,i){d.classList.toggle('active',i===idx)});if(counter)counter.textContent=(idx+1)+'/'+count;
        wrap.querySelectorAll('video').forEach(function(v){var vi=parseInt(v.closest('.gf-media-item').dataset.idx,10);if(vi!==idx)v.pause();else v.play().catch(function(){})});
        var tt=wrap.querySelector('.gf-type-tag'),it=wrap.querySelector('.gf-media-item[data-idx="'+idx+'"]');
        var mb=wrap.querySelector('.gf-mute-btn');if(mb)mb.style.display=wrap.querySelector('.gf-media-item[data-idx="'+idx+'"] video')?'flex':'none'}
      wrap._go=function(d){goTo(idx+d)};
      wrap.addEventListener('touchstart',function(e){if(e.touches.length!==1){dragging=false;lock=null;return}startX=e.touches[0].clientX;startY=e.touches[0].clientY;dx=0;lock=null;dragging=true},{passive:true});
      wrap.addEventListener('touchmove',function(e){if(!dragging)return;var rx=e.touches[0].clientX-startX,ry=e.touches[0].clientY-startY;
        if(lock===null){if(Math.abs(rx)<DEAD&&Math.abs(ry)<DEAD)return;lock=(Math.abs(rx)>Math.abs(ry)*2)?'h':'v';if(lock==='h')track.style.transition='none'}
        if(lock!=='h')return;dx=rx>0?rx-DEAD:rx+DEAD;var edge=(idx===0&&dx>0)||(idx===count-1&&dx<0);track.style.transform='translateX(calc(-'+(idx*100)+'% + '+(edge?dx*.25:dx)+'px))'},{passive:true});
      function end(){if(!dragging)return;dragging=false;track.style.transition='transform .28s cubic-bezier(.22,1,.36,1)';var n=idx;if(lock==='h'){var need=Math.min(90,(wrap.clientWidth||300)*.2);if(Math.abs(dx)>need)n=dx<0?idx+1:idx-1}lock=null;dx=0;goTo(n)}
      wrap.addEventListener('touchend',end,{passive:true});wrap.addEventListener('touchcancel',end,{passive:true});
    });
  }
  window.dsArrow=function(btn,dir){var w=btn.closest('.gf-media-wrap');if(w&&w._go)w._go(dir)};
  window.dsToggleMute=function(btn){var w=btn.closest('.gf-media-wrap'),i=parseInt(w.dataset.activeIdx||'0',10),v=w.querySelector('.gf-media-item[data-idx="'+i+'"] video');if(!v)return;v.muted=!v.muted;btn.querySelector('i').className=v.muted?'dz-snd off':'dz-snd on'};

  /* media tap: NO modal. double tap = like, single tap on a video = play / pause */
  window.dsMediaTap=function(e,el){
    var box=el.closest('.gf-media-wrap')||el,now=Date.now(),dt=now-(box._lastTap||0),post=el.closest('.gf-post'),pid=post?post.dataset.id:null;
    if(dt>0&&dt<320){box._lastTap=0;if(box._tapT){clearTimeout(box._tapT);box._tapT=null}
      var r=box.getBoundingClientRect(),cx=(e&&e.clientX)?e.clientX:(r.left+r.width/2),cy=(e&&e.clientY)?e.clientY:(r.top+r.height/2);
      heartBurst(box,cx-r.left,cy-r.top);var p=findPost(pid);if(p&&!p.liked)dsLike(pid,'like')}
    else{box._lastTap=now;box._tapT=setTimeout(function(){if(el.tagName==='VIDEO'){if(el.paused)el.play().catch(function(){});else el.pause()}},260)}
  };
  function heartBurst(box,x,y){
    box.querySelectorAll('.gf-double-tap-heart,.gf-heart-ring,.gf-heart-particle').forEach(function(el){el.remove()});
    var ring=document.createElement('div');ring.className='gf-heart-ring';ring.style.left=x+'px';ring.style.top=y+'px';box.appendChild(ring);requestAnimationFrame(function(){ring.classList.add('show')});setTimeout(function(){ring.remove()},800);
    var h=document.createElement('div');h.className='gf-double-tap-heart';h.style.left=x+'px';h.style.top=y+'px';h.innerHTML='<i class="ri-heart-3-fill"></i>';box.appendChild(h);requestAnimationFrame(function(){h.classList.add('show')});setTimeout(function(){h.remove()},950);
    for(var i=0;i<8;i++)(function(i){var rad=((360/8)*i+(Math.random()*20-10))*Math.PI/180,dist=60+Math.random()*42;var p=document.createElement('div');p.className='gf-heart-particle';p.style.left=x+'px';p.style.top=y+'px';p.style.setProperty('--tx',Math.cos(rad)*dist+'px');p.style.setProperty('--ty',Math.sin(rad)*dist+'px');p.style.setProperty('--tr',(Math.random()*80-40)+'deg');p.innerHTML='<i class="ri-heart-3-fill gf-hs'+Math.floor(Math.random()*6)+'"></i>';box.appendChild(p);setTimeout(function(){requestAnimationFrame(function(){p.classList.add('show')})},40+i*15);setTimeout(function(){p.remove()},950+i*15)})(i);
  }

  var likeLock={};
  window.dsLike=function(id,type){
    if(likeLock[id])return;likeLock[id]=true;
    fetch(DS_URL,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'ajax_like=1&discussion_id='+id+'&type='+type+'&csrf_token='+encodeURIComponent(CSRF_TOKEN)})
    .then(function(r){return r.json()}).then(function(d){
      delete likeLock[id];var p=findPost(id);if(!p)return;
      p.likes=d.likes;p.liked=d.user_type==='like';p.disliked=d.user_type==='dislike';syncPost(id);
    }).catch(function(){delete likeLock[id];dsToast('Network error')});
  };
  window.dsRenderPost=renderPost;
  window.syncPost=function(id){
    var p=findPost(id),el=$('ds-post-'+id);if(p)dpvSync(p);if(!p||!el)return;
    el.querySelector('.gf-like').classList.toggle('active',!!p.liked);el.querySelector('.gf-dis').classList.toggle('active',!!p.disliked);el.querySelector('.gf-like-count').textContent=fmtNum(p.likes);
    var cc=el.querySelector('.gf-comm-count');if(cc&&!p.commentsOff)cc.textContent=fmtNum(p.replies);
  };

  window.dsShare=function(id){
    var url=location.origin+location.pathname+'?pod_id='+DS_GROUP_ID+'&open='+id,p=findPost(id);
    if(navigator.share)navigator.share({title:p?p.title:'Discussion',url:url}).catch(function(){});
    else if(navigator.clipboard)navigator.clipboard.writeText(url).then(function(){dsToast('Link copied to clipboard')});
    else dsToast(url);
  };

  window.dsTrack=function(btn){
    var uid=btn.dataset.user;
    fetch('group.php?id='+DS_GROUP_ID,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=toggle_follow&user_id='+uid+'&csrf_token='+encodeURIComponent(CSRF_TOKEN)})
    .then(function(r){return r.json()}).then(function(d){if(d.status!=='ok')return;
      document.querySelectorAll('.gf-track-btn[data-user="'+uid+'"]').forEach(function(b){b.textContent=d.following?'Untrack':'Track'});
      DS_FEED.forEach(function(p){if(String(p.userId)===String(uid))p.following=!!d.following});
      dsToast(d.following?'Now tracking':'Untracked')}).catch(function(){dsToast('Network error')});
  };

  var HKEY='ds_hidden_threads_group_'+DS_GROUP_ID+'_user_'+DS_ME;
  function loadHidden(){try{var a=JSON.parse(localStorage.getItem(HKEY)||'[]');return Array.isArray(a)?a.map(String):[]}catch(e){return[]}}
  function saveHidden(a){try{localStorage.setItem(HKEY,JSON.stringify(a))}catch(e){}}
  window.dsHidePost=function(id,silent){
    id=String(id);var ids=loadHidden();if(ids.indexOf(id)===-1){ids.push(id);saveHidden(ids)}
    var post=$('ds-post-'+id);if(!post)return;var unit=post.closest('.gf-card-unit');post.querySelectorAll('video').forEach(function(v){v.pause()});
    if(postObs)postObs.unobserve(post);
    unit.innerHTML='<div class="gf-hidden-placeholder"><div class="gf-hidden-left"><div class="gf-hidden-icon"><i class="ri-eye-off-line"></i></div><div class="gf-hidden-text">Post hidden</div></div><button type="button" class="gf-undo-btn" onclick="dsUndoHide('+id+')"><i class="ri-arrow-go-back-line"></i> Undo</button></div>';
    if(!silent)dsToast('Post hidden');
  };
  window.dsUndoHide=function(id){
    id=String(id);saveHidden(loadHidden().filter(function(x){return x!==id}));
    var p=findPost(id),ph=document.querySelector('.gf-hidden-placeholder button[onclick="dsUndoHide('+id+')"]');if(!p||!ph)return;
    var unit=ph.closest('.gf-card-unit');unit.innerHTML=renderPost(p);unit.firstElementChild.classList.add('gf-post-enter');
    bindCarousels([unit]);observePosts([unit]);measureBodies(unit);updateCountdowns();dsToast('Post restored');
  };
  function applyHidden(units){var ids=loadHidden();if(!ids.length)return;units.forEach(function(u){var p=u.querySelector('.gf-post');if(p&&ids.indexOf(String(p.dataset.id))!==-1)window.dsHidePost(p.dataset.id,true)})}

  function fmtCd(s){if(s<=0)return'Ended';var d=Math.floor(s/86400),h=Math.floor((s%86400)/3600),m=Math.floor((s%3600)/60);if(d>0)return d+'d '+h+'h left';if(h>0)return h+'h '+m+'m left';return m+'m left'}
  function updateCountdowns(){var now=Math.floor(Date.now()/1000);document.querySelectorAll('.dp-cd[data-expires]').forEach(function(el){var e=parseInt(el.dataset.expires,10);if(e)el.textContent=fmtCd(e-now)})}
  setInterval(updateCountdowns,60000);

  rebuild();
})();

/* ═════════ ⋯ MENU SHEET ═════════ */
var dsCur=null;
function openDsSheet(id){
  var p=findPost(id);if(!p)return;dsCur=p;var items=[];
  items.push('<button type="button" class="idx-sheet-item" onclick="dsCopy()"><i class="ri-file-copy-line"></i> Copy Caption</button>');
  if(p.media&&p.media.length)items.push('<a class="idx-sheet-item" href="'+esc(p.media[0].file)+'" download><i class="ri-download-2-line"></i> Download</a>');
  items.push('<button type="button" class="idx-sheet-item" onclick="closeDsSheet();dsShare('+p.id+')"><i class="ri-share-line"></i> Share Post</button>');
  items.push('<button type="button" class="idx-sheet-item" onclick="closeDsSheet();dsHidePost('+p.id+')"><i class="ri-eye-off-line"></i> Hide Post</button>');
  if(!p.isOwn)items.push('<button type="button" class="idx-sheet-item" onclick="closeDsSheet();document.querySelector(\'#ds-post-'+p.id+' .gf-track-btn\').click()"><i class="ri-user-'+(p.following?'unfollow':'follow')+'-line"></i> '+(p.following?'Untrack':'Track')+' Dezzmer</button>');
  if(DS_IS_MOD){
    items.push('<button type="button" class="idx-sheet-item" onclick="dsMod('+p.id+',\''+(p.pinned?'unpin':'pin')+'\')"><i class="ri-pushpin-'+(p.pinned?'fill':'line')+'"></i> '+(p.pinned?'Unpin':'Pin')+' Post</button>');
    items.push('<button type="button" class="idx-sheet-item" onclick="dsMod('+p.id+',\''+(p.locked?'unlock':'lock')+'\')"><i class="ri-lock-'+(p.locked?'unlock-line':'line')+'"></i> '+(p.locked?'Unlock':'Lock')+' Thread</button>');
  }
  if(p.canDel)items.push('<button type="button" class="idx-sheet-item danger" onclick="dsDelete('+p.id+')"><i class="ri-delete-bin-line"></i> Delete Post</button>');
  if(!p.isOwn&&DS_IS_MEMBER)items.push('<button type="button" class="idx-sheet-item report" onclick="closeDsSheet();openDsRpt('+p.id+')"><i class="ri-flag-line"></i> Report to Admins</button>');
  $('dsSheetList').innerHTML=items.join('');
  $('dsSheetOv').classList.add('show');$('dsSheet').classList.add('show');document.body.style.overflow='hidden';
}
function closeDsSheet(){$('dsSheetOv').classList.remove('show');$('dsSheet').classList.remove('show');dsLock()}
function dsCopy(){
  if(!dsCur)return;var text=(dsCur.title||'')+(dsCur.body?('\n\n'+dsCur.body):'');
  (navigator.clipboard?navigator.clipboard.writeText(text):Promise.reject()).then(function(){dsToast('Caption copied')}).catch(function(){var el=document.createElement('textarea');el.value=text;document.body.appendChild(el);el.select();document.execCommand('copy');document.body.removeChild(el);dsToast('Caption copied')});
  closeDsSheet();
}
function dsSubmitAction(fields){var f=$('dsActForm');f.innerHTML='';fields.concat([['csrf_token',CSRF_TOKEN]]).forEach(function(kv){var i=document.createElement('input');i.type='hidden';i.name=kv[0];i.value=kv[1];f.appendChild(i)});f.submit()}
function dsMod(id,action){closeDsSheet();dsSubmitAction([['mod_did',id],['mod_action',action]])}
function dsDelete(id){closeDsSheet();if(!confirm('Delete this discussion permanently? This cannot be undone.'))return;dsSubmitAction([['del_thread_id',id],['delete_thread','1']])}

/* swipe a bottom sheet / sheet header down to close */
function dsSwipe(panel,zones,close,mobileOnly){
  var sy=0,dy=0,drag=false,pid=null;
  function ok(){return!mobileOnly||window.matchMedia('(max-width:790px)').matches}
  function start(e){if(!ok())return;if(e.pointerType==='mouse'&&e.button!==0)return;if(e.target.closest('button,.dcs-sort'))return;drag=true;dy=0;sy=e.clientY;pid=e.pointerId;panel.style.transition='none';try{e.currentTarget.setPointerCapture(e.pointerId)}catch(x){}}
  function move(e){if(!drag||e.pointerId!==pid)return;dy=Math.max(0,e.clientY-sy);panel.style.transform=panel._base?panel._base(dy):'translateY('+dy+'px)'}
  function end(e){if(!drag||(e&&e.pointerId!==pid))return;drag=false;pid=null;panel.style.transition='transform .22s ease';
    if(dy>90){panel.style.transform=panel._base?panel._base('100%'):'translateY(100%)';setTimeout(function(){close();panel.style.transition='';panel.style.transform=''},200)}
    else{panel.style.transform='';setTimeout(function(){panel.style.transition=''},240)}}
  zones.forEach(function(z){if(!z)return;z.addEventListener('pointerdown',start);z.addEventListener('pointermove',move);z.addEventListener('pointerup',end);z.addEventListener('pointercancel',end)});
}
(function(){var dcs=$('dcs');dcs._base=function(v){return'translate(-50%,'+(typeof v==='number'?v+'px':v)+')'};
  dsSwipe(dcs,[$('dcsHandle'),$('dcsHead'),$('dcsTHead')],function(){dcsClose()},false);
  dsSwipe($('dsSheet'),[document.querySelector('.idx-sheet-handle')],closeDsSheet,true)})();

/* ═════════ YOU modal ═════════ */
function dsYouTab(btn,id){
  document.querySelectorAll('#dsYouOv .dz-pill').forEach(function(p){p.classList.remove('active')});btn.classList.add('active');
  document.querySelectorAll('#dsYouOv .dz-tab').forEach(function(t){t.classList.remove('active')});
  var t=$(id);if(t)t.classList.add('active');var b=$('dsYouBody');if(b)b.scrollTop=0;
}
function dsSortYou(mode,btn){
  document.querySelectorAll('#dsYouOv .dz-spill').forEach(function(b){b.classList.remove('on')});if(btn)btn.classList.add('on');
  var list=$('dsYouList');if(!list)return;var items=Array.prototype.slice.call(list.querySelectorAll('.ds-yitem'));
  items.sort(function(a,b){if(mode==='newest')return b.dataset.ts-a.dataset.ts;if(mode==='oldest')return a.dataset.ts-b.dataset.ts;return b.dataset.pop-a.dataset.pop});
  items.forEach(function(i){list.appendChild(i)});
}

/* ═════════ CREATE modal ═════════ */
var cmFileStore=new DataTransfer();
var dsPrefillState=Array.isArray(DS_PREFILL_MEDIA)?DS_PREFILL_MEDIA.slice():[];
function openCreateModal(){if(!DS_IS_MEMBER){dsToast('Join this pod to post');return}dsOpen('dsCreateOv')}
function closeCreateModal(){dsClose('dsCreateOv');resetCmMedia()}
function selectTag(el,tag){document.querySelectorAll('.ds-tagpill').forEach(function(p){p.classList.remove('sel')});el.classList.add('sel');$('tag-hid').value=tag;$('poll-sec').style.display=tag==='Poll'?'block':'none'}
function selectPollType(el,type){document.querySelectorAll('.ds-ptc').forEach(function(c){c.classList.remove('sel')});el.classList.add('sel');$('poll-type-hid').value=type;var q=type==='quiz';document.querySelectorAll('.correct-radio').forEach(function(r){r.style.display=q?'inline-block':'none'});$('pollQuizHint').style.display=q?'flex':'none'}
function addPollOpt(){var wrap=$('poll-opts'),n=wrap.children.length,q=$('poll-type-hid').value==='quiz';var row=document.createElement('div');row.className='ds-popt';
  var r=document.createElement('input');r.type='radio';r.name='correct_option';r.value=n;r.className='correct-radio';r.style.display=q?'inline-block':'none';
  var i=document.createElement('input');i.className='dz-inp';i.type='text';i.name='poll_options[]';i.placeholder='Option '+(n+1);row.appendChild(r);row.appendChild(i);wrap.appendChild(row)}
function syncExisting(){var h=$('cm-existing-media-hidden');if(h)h.value=JSON.stringify(dsPrefillState)}
function syncHiddenMedia(){$('cm-media-hidden').files=cmFileStore.files}
function renderCmPreview(){
  var wrap=$('cm-media-preview');if(!wrap)return;wrap.innerHTML='';
  dsPrefillState.forEach(function(item,idx){var url='uploads/'+item.file,vid=item.type==='video';var t=document.createElement('div');t.className='ds-thumb existing';
    t.innerHTML=(vid?'<video src="'+esc(url)+'" muted></video>':'<img src="'+esc(url)+'">')+'<em>From post</em><button type="button" data-source="existing" data-idx="'+idx+'">✕</button>';wrap.appendChild(t)});
  Array.from(cmFileStore.files).forEach(function(file,idx){var url=URL.createObjectURL(file),ext=file.name.split('.').pop().toLowerCase(),vid=['mp4','webm','mov'].indexOf(ext)>-1;var t=document.createElement('div');t.className='ds-thumb';
    t.innerHTML=(vid?'<video src="'+url+'" muted></video>':'<img src="'+url+'">')+'<button type="button" data-source="new" data-idx="'+idx+'">✕</button>';wrap.appendChild(t)});
  var total=dsPrefillState.length+cmFileStore.files.length;$('cm-file-lbl').textContent=total?total+' file'+(total>1?'s':'')+' selected':'Image / Video / GIF';
  var note=$('cmPrefillNote');if(note)note.style.display=dsPrefillState.length?'flex':'none';syncExisting();
}
function resetCmMedia(){cmFileStore=new DataTransfer();dsPrefillState=[];renderCmPreview();syncHiddenMedia()}
(function(){var inp=$('cm-media-input');if(!inp)return;
  inp.addEventListener('change',function(e){Array.from(e.target.files).forEach(function(f){cmFileStore.items.add(f)});renderCmPreview();this.value='';syncHiddenMedia()});
  $('cm-media-preview').addEventListener('click',function(e){var b=e.target.closest('button[data-source]');if(!b)return;var idx=parseInt(b.dataset.idx,10);
    if(b.dataset.source==='existing'){dsPrefillState.splice(idx,1);renderCmPreview()}
    else{var ns=new DataTransfer();Array.from(cmFileStore.files).forEach(function(f,i){if(i!==idx)ns.items.add(f)});cmFileStore=ns;renderCmPreview();syncHiddenMedia()}});
  document.addEventListener('DOMContentLoaded',function(){if(DS_HAS_PREFILL){renderCmPreview();openCreateModal()}});
})();

/* ═════════ REPORT modal ═════════ */
(function(){
  var st={id:null,reason:null};
  var reasons=[['ri-focus-3-line','Off-topic / doesn\'t fit this pod'],['ri-spam-2-line','Spam or repeated posting'],['ri-emotion-unhappy-line','Harassment within the pod'],['ri-prohibited-line','Hate speech or discrimination'],['ri-error-warning-line','Breaks this pod\'s guidelines'],['ri-user-forbid-line','Suspected rule-breaking member'],['ri-question-line','Other']];
  window.openDsRpt=function(id){st.id=id;st.reason=null;$('dsRptBody').style.display='';$('dsRptDone').style.display='none';$('dsRptFoot').style.display='';$('dsRptOther').style.display='none';$('dsRptText').value='';$('dsRptCnt').textContent='0 / 500';$('dsRptTitle').textContent='Report to pod admins';
    var list=$('dsRptList');list.innerHTML='';reasons.forEach(function(r){var row=document.createElement('div');row.className='ds-rsn';row.dataset.reason=r[1];row.innerHTML='<i class="'+r[0]+' ic"></i><span>'+esc(r[1])+'</span><div class="ck"></div>';row.addEventListener('click',function(){pick(r[1])});list.appendChild(row)});
    dsOpen('dsRptOv');$('dsRptBody').scrollTop=0};
  window.closeDsRpt=function(){dsClose('dsRptOv')};
  function pick(reason){st.reason=reason;document.querySelectorAll('#dsRptList .ds-rsn').forEach(function(r){var s=r.dataset.reason===reason;r.classList.toggle('selected',s);r.querySelector('.ck').textContent=s?'✓':''});$('dsRptOther').style.display=reason==='Other'?'block':'none';
    if(reason==='Other')setTimeout(function(){var b=$('dsRptBody');b.scrollTo({top:b.scrollHeight,behavior:'smooth'})},60)}
  window.dsRptSubmit=function(){
    if(!st.reason){var l=$('dsRptList');l.style.animation='none';void l.offsetWidth;l.style.animation='dsShake .35s ease';dsToast('Please select a reason first');return}
    var desc=($('dsRptText').value||'').trim();if(st.reason==='Other'&&!desc){$('dsRptText').focus();dsToast('Please describe the issue');return}
    var btn=$('dsRptSubmit');btn.disabled=true;btn.textContent='Submitting…';
    dsPost('report_thread_to_admins',{discussion_id:st.id,reason:st.reason,description:desc}).then(function(d){
      btn.disabled=false;btn.textContent='Submit Report';
      if(d&&(d.success||d.msg==='already_reported')){$('dsRptBody').style.display='none';$('dsRptFoot').style.display='none';$('dsRptDone').style.display='flex';$('dsRptTitle').textContent='Report Submitted'}
      else dsToast((d&&d.msg)||'Something went wrong. Try again.');
    }).catch(function(){btn.disabled=false;btn.textContent='Submit Report';dsToast('Network error — check your connection')});
  };
})();

/* ═════════════════════════════════════════════════════════════
   COMMENTS SHEET — two screens sharing one sheet (list + Replies).
   ═════════════════════════════════════════════════════════════ */
var DCS={open:false,postId:0,sort:'top',offset:0,hasMore:false,loading:false,replyTo:null,map:{},menuFor:0,view:'list',threadRoot:0,threadOffset:0,threadHasMore:false,threadLoading:false};
var DCS_SUB={};
var DCS_WORDS=30,DCS_CHARS=280,DCS_SORT_LABELS={top:'Top','new':'Newest',old:'Oldest'};
function dcsAv(u){return'<img src="'+esc(u)+'" alt="" onerror="this.src=\'uploads/me.jpeg\'">'}
function dcsLinkify(text){
  text=String(text||'');var mention='',rest=text,mm=text.match(/^(@[^\s<>"']{1,40})(\s|$)/);if(mm){mention=mm[1];rest=text.slice(mm[1].length)}
  var re=/((?:https?:\/\/|www\.)[^\s<>"']+)/gi,out=mention?'<span class="dcs-mention">'+esc(mention)+'</span>':'',last=0,m;
  while((m=re.exec(rest))!==null){var url=m[0],trail='',tm=url.match(/[.,!?;:)\]}]+$/);if(tm){trail=tm[0];url=url.slice(0,url.length-trail.length)}if(!url)continue;
    var href=/^www\./i.test(url)?'https://'+url:url;out+=esc(rest.slice(last,m.index))+'<a class="dcs-link" href="'+esc(href)+'" target="_blank" rel="noopener noreferrer nofollow">'+esc(url)+'</a>'+esc(trail);last=m.index+m[0].length}
  return out+esc(rest.slice(last));
}
function dcsCut(text){var full=String(text||'').replace(/\s+$/,''),re=/\S+/g,m,n=0,end=-1;while((m=re.exec(full))!==null){n++;if(n===DCS_WORDS){end=m.index+m[0].length;break}}
  var cut=end!==-1?full.slice(0,end):full;if(cut.length>DCS_CHARS){var s=cut.slice(0,DCS_CHARS),sp=s.lastIndexOf(' ');cut=sp>DCS_CHARS*.5?s.slice(0,sp):s}cut=cut.replace(/\s+$/,'');return cut.length>=full.length?null:cut}
function dcsTextHtml(text){var cut=dcsCut(text);if(cut===null)return'<div class="dcs-text">'+dcsLinkify(text)+'</div>';
  return'<div class="dcs-text"><span class="dcs-short">'+dcsLinkify(cut)+'</span><span class="dcs-full">'+dcsLinkify(text)+'</span> <button type="button" class="dcs-readmore">...Read more</button></div>'}
function dcsLbl(n){return n+(n===1?' reply':' replies')}
function dcsToggleHtml(id,count,avatar,inline){
  return'<button type="button" class="dcs-viewreplies'+(inline?' dcs-subtoggle':'')+'" data-id="'+id+'" data-count="'+count+'"'+(inline?' data-inline="1"':'')+'><span class="dcs-vr-av">'+dcsAv(avatar||'uploads/me.jpeg')+'</span><span class="dcs-vr-dot">&middot;</span><span class="dcs-vr-label">'+dcsLbl(count)+'</span><i class="ri-arrow-right-s-line dcs-vr-chev"></i></button>'}

function dcsCommentHtml(c,kind){
  kind=kind||'top';DCS.map[c.id]=c;
  var post=findPost(DCS.postId),crAv=post?post.pic:'uploads/me.jpeg';
  var name=c.is_owner?'<a class="dcs-user is-owner" title="Owner" href="profile.php?user_id='+c.user_id+'"><span>'+esc(c.username)+'</span>'+(c.is_verified?DS_VB:'')+'<i class="ri-checkbox-circle-fill"></i></a>':'<a class="dcs-user" href="profile.php?user_id='+c.user_id+'">'+esc(c.username)+(c.is_verified?DS_VB:'')+'</a>';
  var crl='<span class="dcs-crlike" title="Liked by creator"'+(c.owner_liked?'':' style="display:none"')+'><span class="dcs-crav">'+dcsAv(crAv)+'</span><i class="dcs-crbadge ri-heart-fill"></i></span>';
  var row2='<div class="dcs-row2">'+
    '<button type="button" class="dcs-like'+(c.liked?' on':'')+'" data-id="'+c.id+'" data-raw="'+c.likes+'" aria-label="Like comment"><i class="'+(c.liked?'ri-thumb-up-fill':'ri-thumb-up-line')+'"></i><span>'+(c.likes>0?fmtNum(c.likes):'')+'</span></button>'+
    '<button type="button" class="dcs-dislike'+(c.disliked?' on':'')+'" data-id="'+c.id+'" aria-label="Dislike comment"><i class="'+(c.disliked?'ri-thumb-down-fill':'ri-thumb-down-line')+'"></i></button>'+
    '<button type="button" class="dcs-reply" data-id="'+c.id+'" data-user="'+esc(c.username)+'">Reply</button>'+crl+'</div>';
  var thread='',has=false;
  if(kind==='top'){has=c.replies>0;if(has)thread='<div class="dcs-thread">'+dcsToggleHtml(c.id,c.replies,c.reply_avatar,false)+'</div>'}
  else if(kind==='nested'){has=c.replies>0;thread='<div class="dcs-thread" id="dcs-tw-'+c.id+'"'+(has?'':' style="display:none"')+'>'+(has?dcsToggleHtml(c.id,c.replies,c.reply_avatar,true):'')+'</div><div class="dcs-subthread" id="dcs-sub-'+c.id+'" style="display:none"></div>'}
  return'<div class="dcs-item'+(has?' has-thread':'')+'" data-id="'+c.id+'"><div class="dcs-main"><div class="dcs-avcol"><a class="dcs-av" href="profile.php?user_id='+c.user_id+'">'+dcsAv(c.avatar)+'</a><span class="dcs-line"></span></div>'+
    '<div class="dcs-body"><div class="dcs-line1">'+name+(c.is_owner?'<span class="dcs-ownertag">Owner</span>':'')+'<span class="dcs-time">'+esc(c.ago)+'</span>'+
    '<button type="button" class="dcs-more" data-id="'+c.id+'" aria-label="Comment options" aria-haspopup="menu"><i class="ri-more-2-fill"></i></button></div>'+dcsTextHtml(c.text)+row2+'</div></div>'+thread+'</div>';
}

function dcsLoad(first){
  if(DCS.loading||(!first&&!DCS.hasMore))return;DCS.loading=true;var pid=DCS.postId,sort=DCS.sort;
  dsGet('ds_comments=1&post='+pid+'&sort='+sort+'&offset='+DCS.offset).then(function(d){
    if(pid!==DCS.postId||sort!==DCS.sort||!DCS.open)return;DCS.loading=false;$('dcsSkel').style.display='none';if(!d||!d.success)return;
    var box=$('dcsList'),html=d.comments.filter(function(c){return!box.querySelector('.dcs-item[data-id="'+c.id+'"]')}).map(function(c){return dcsCommentHtml(c,'top')}).join('');
    if(first)box.innerHTML=html;else box.insertAdjacentHTML('beforeend',html);
    DCS.offset+=d.comments.length;DCS.hasMore=d.has_more;$('dcsEmpty').style.display=(first&&!d.comments.length)?'':'none';
  }).catch(function(){DCS.loading=false;$('dcsSkel').style.display='none'});
}
function dcsLoadThread(first){
  if(DCS.threadLoading||(!first&&!DCS.threadHasMore))return;DCS.threadLoading=true;var pid=DCS.postId,root=DCS.threadRoot;
  dsGet('ds_replies=1&post='+pid+'&comment_id='+root+'&offset='+DCS.threadOffset).then(function(d){
    if(pid!==DCS.postId||root!==DCS.threadRoot||DCS.view!=='replies')return;DCS.threadLoading=false;$('dcsTSkel').style.display='none';if(!d||!d.success)return;
    var box=$('dcsTList'),html=d.replies.filter(function(c){return!box.querySelector('.dcs-item[data-id="'+c.id+'"]')}).map(function(c){return dcsCommentHtml(c,'nested')}).join('');
    box.insertAdjacentHTML('beforeend',html);DCS.threadOffset+=d.replies.length;DCS.threadHasMore=d.has_more;
  }).catch(function(){DCS.threadLoading=false;$('dcsTSkel').style.display='none'});
}
(function(){if(!('IntersectionObserver' in window))return;
  new IntersectionObserver(function(es){if(es[0].isIntersecting)dcsLoad(false)},{root:$('dcsScroll'),rootMargin:'250px 0px'}).observe($('dcsSentinel'));
  new IntersectionObserver(function(es){if(es[0].isIntersecting)dcsLoadThread(false)},{root:$('dcsTScroll'),rootMargin:'250px 0px'}).observe($('dcsTSentinel'))})();

function dcsEnsureViewBtn(pid,count,avatar){
  var item=document.querySelector('#dcsList .dcs-item[data-id="'+pid+'"]');if(!item)return;var btn=item.querySelector('.dcs-viewreplies');
  if(count<=0){if(btn&&btn.parentNode)btn.parentNode.removeChild(btn);item.classList.remove('has-thread');return}
  item.classList.add('has-thread');
  if(btn){btn.setAttribute('data-count',count);btn.querySelector('.dcs-vr-label').textContent=dcsLbl(count);if(avatar)btn.querySelector('.dcs-vr-av').innerHTML=dcsAv(avatar);return}
  var t=document.createElement('div');t.className='dcs-thread';t.innerHTML=dcsToggleHtml(pid,count,avatar,false);item.appendChild(t);
}
function dcsOpenThread(rootId){
  var root=DCS.map[rootId];if(!root)return;
  DCS.view='replies';DCS.threadRoot=rootId;DCS.threadOffset=0;DCS.threadHasMore=false;DCS.threadLoading=false;DCS_SUB={};
  dcsCloseMenu();$('dcsSortMenu').classList.remove('show');
  $('dcsHead').style.display='none';$('dcsScroll').style.display='none';$('dcsTHead').style.display='flex';$('dcsTScroll').style.display='';
  $('dcsTRoot').innerHTML=dcsCommentHtml(root,'root');$('dcsTList').innerHTML='';$('dcsTSkel').style.display='';$('dcsTScroll').scrollTop=0;
  dcsSetReply(rootId,root.username,false);dcsLoadThread(true);
}
function dcsCloseThread(){
  DCS.view='list';DCS.threadRoot=0;DCS_SUB={};$('dcsTHead').style.display='none';$('dcsTScroll').style.display='none';$('dcsHead').style.display='';$('dcsScroll').style.display='';dcsSetReply(null,'');
}
$('dcsTBack').addEventListener('click',dcsCloseThread);$('dcsTClose').addEventListener('click',function(){dcsClose()});

function dcsUpdateSubToggle(pid,count,avatar){
  var wrap=document.getElementById('dcs-tw-'+pid);if(!wrap)return;var btn=wrap.querySelector('.dcs-subtoggle');
  if(!btn)wrap.innerHTML=dcsToggleHtml(pid,count,avatar,true);
  else{btn.setAttribute('data-count',count);btn.querySelector('.dcs-vr-label').textContent=dcsLbl(count);if(avatar)btn.querySelector('.dcs-vr-av').innerHTML=dcsAv(avatar)}
  wrap.style.display=count>0?'':'none';var item=wrap.closest('.dcs-item');if(item)item.classList.toggle('has-thread',count>0);
}
function dcsSubMore(container,id,state){var old=container.querySelector('.dcs-submore');if(old&&old.parentNode)old.parentNode.removeChild(old);
  if(state.hasMore){var b=document.createElement('button');b.type='button';b.className='dcs-submore';b.setAttribute('data-id',id);b.textContent='Show more replies';container.appendChild(b)}}
function dcsLoadSub(id,more){
  var st=DCS_SUB[id];if(!st||st.loading||(more&&!st.hasMore))return;st.loading=true;var container=document.getElementById('dcs-sub-'+id),pid=DCS.postId;
  dsGet('ds_replies=1&post='+pid+'&comment_id='+id+'&offset='+st.offset).then(function(d){
    st.loading=false;if(pid!==DCS.postId||!DCS.open||!container)return;if(!d||!d.success){if(!more)container.innerHTML='';return}
    st.loaded=true;var html=d.replies.map(function(c){return dcsCommentHtml(c,'nested')}).join('');
    if(!more)container.innerHTML=html;else container.insertAdjacentHTML('beforeend',html);st.offset+=d.replies.length;st.hasMore=d.has_more;dcsSubMore(container,id,st);
  }).catch(function(){st.loading=false});
}
function dcsToggleSub(id,btn){
  var st=DCS_SUB[id]||(DCS_SUB[id]={open:false,offset:0,hasMore:false,loading:false,loaded:false}),container=document.getElementById('dcs-sub-'+id);if(!container)return;
  st.open=!st.open;btn.classList.toggle('open',st.open);container.style.display=st.open?'':'none';
  if(st.open&&!st.loaded){container.innerHTML='<div class="dcs-sub-sk"><div class="sk-sh" style="width:26px;height:26px;border-radius:50%;flex-shrink:0"></div><div style="flex:1"><div class="sk-sh" style="height:9px;width:50%"></div></div></div>';dcsLoadSub(id,false)}
}
function dcsInsertReply(pid,c){
  if(pid===DCS.threadRoot){$('dcsTList').insertAdjacentHTML('beforeend',dcsCommentHtml(c,'nested'));$('dcsTScroll').scrollTop=$('dcsTScroll').scrollHeight;DCS.threadOffset+=1;return}
  var wrap=document.getElementById('dcs-tw-'+pid),cur=0;if(wrap){var b=wrap.querySelector('.dcs-subtoggle');cur=b?(parseInt(b.getAttribute('data-count'),10)||0):0}
  dcsUpdateSubToggle(pid,cur+1,c.avatar);
  var st=DCS_SUB[pid],container=document.getElementById('dcs-sub-'+pid);
  if(st&&st.open&&container){var mb=container.querySelector('.dcs-submore'),html=dcsCommentHtml(c,'nested');if(mb)mb.insertAdjacentHTML('beforebegin',html);else container.insertAdjacentHTML('beforeend',html);st.offset+=1}
}
function dcsSetReply(id,username,mention){
  DCS.replyTo=id?{id:id,username:username}:null;var input=$('dcsInput');
  if(mention&&id&&!input.value.trim())input.value='@'+username+' ';
  input.placeholder=DCS.view==='replies'?'Reply...':'Add a comment...';$('dcsSend').disabled=!input.value.trim();
}
function dcsSetOwnerLike(id,on){document.querySelectorAll('#dcs .dcs-item[data-id="'+id+'"]').forEach(function(it){var b=it.querySelector('.dcs-crlike');if(b)b.style.display=on?'':'none'});if(DCS.map[id])DCS.map[id].owner_liked=!!on}

function dcsCloseMenu(){$('dcsMenu').classList.remove('show');DCS.menuFor=0}
function dcsOpenMenu(btn){
  var id=parseInt(btn.getAttribute('data-id'),10),c=DCS.map[id];if(!c)return;var menu=$('dcsMenu');
  if(menu.classList.contains('show')&&DCS.menuFor===id){dcsCloseMenu();return}
  DCS.menuFor=id;menu.querySelector('[data-act="delete"]').style.display=c.can_delete?'':'none';menu.classList.add('show');
  var sh=$('dcs').getBoundingClientRect(),br=btn.getBoundingClientRect(),mh=menu.offsetHeight,top=br.bottom-sh.top+4;
  if(top+mh>sh.height-8)top=br.top-sh.top-mh-4;menu.style.left='auto';menu.style.top=Math.max(8,top)+'px';menu.style.right=Math.max(8,sh.right-br.right)+'px';
}
$('dcsMenu').addEventListener('click',function(e){var b=e.target.closest('button[data-act]');if(!b)return;var act=b.getAttribute('data-act'),id=DCS.menuFor;dcsCloseMenu();if(act==='download')dcsDownload(id);else if(act==='delete')dcsDelete(id)});
$('dcsScroll').addEventListener('scroll',dcsCloseMenu,{passive:true});$('dcsTScroll').addEventListener('scroll',dcsCloseMenu,{passive:true});
function dcsDownload(id){
  var c=DCS.map[id];if(!c)return;var p=findPost(DCS.postId),title=p?p.title:'';
  var body='Comment by @'+c.username+'\nPosted: '+c.ago+'\n'+(title?'On discussion: '+title+'\n':'')+'\n'+c.text+'\n';
  var url=URL.createObjectURL(new Blob([body],{type:'text/plain;charset=utf-8'}));var a=document.createElement('a');a.href=url;a.download='comment-'+id+'.txt';document.body.appendChild(a);a.click();document.body.removeChild(a);setTimeout(function(){URL.revokeObjectURL(url)},1500);dsToast('Comment downloaded');
}
function dcsBumpPost(delta){var p=findPost(DCS.postId);if(!p)return;p.replies=Math.max(0,(p.replies||0)+delta);$('dcsCount').textContent=' · '+fmtNum(p.replies);syncPost(p.id)}
function dcsDelete(id){
  var c=DCS.map[id];if(!c||!c.can_delete)return;if(!confirm('Delete this comment? This cannot be undone.'))return;
  var wasRoot=(DCS.view==='replies'&&id===DCS.threadRoot);
  dsPost('ds_comment_delete',{comment_id:id}).then(function(d){
    if(!d||!d.success){dsToast((d&&d.error)||'Could not delete this comment.');return}
    delete DCS.map[id];delete DCS_SUB[id];
    if(wasRoot){var mi=document.querySelector('#dcsList .dcs-item[data-id="'+id+'"]');if(mi&&mi.parentNode)mi.parentNode.removeChild(mi);DCS.offset=Math.max(0,DCS.offset-1);dcsCloseThread()}
    else if(d.parent_id){
      var el=document.querySelector('#dcs .dcs-item[data-id="'+id+'"]');if(el&&el.parentNode)el.parentNode.removeChild(el);
      if(d.parent_id===DCS.threadRoot){DCS.threadOffset=Math.max(0,DCS.threadOffset-1);var cb=document.querySelector('#dcsList .dcs-item[data-id="'+d.parent_id+'"] .dcs-viewreplies');dcsEnsureViewBtn(d.parent_id,Math.max(0,(cb?(parseInt(cb.getAttribute('data-count'),10)||1):1)-1),null)}
      else{var st=DCS_SUB[d.parent_id];if(st)st.offset=Math.max(0,st.offset-1);var w=document.getElementById('dcs-tw-'+d.parent_id),tb=w?w.querySelector('.dcs-subtoggle'):null;dcsUpdateSubToggle(d.parent_id,Math.max(0,(tb?(parseInt(tb.getAttribute('data-count'),10)||1):1)-1),null)}
    } else {var e2=document.querySelector('#dcsList .dcs-item[data-id="'+id+'"]');if(e2&&e2.parentNode)e2.parentNode.removeChild(e2);DCS.offset=Math.max(0,DCS.offset-1)}
    dcsBumpPost(-(d.removed||1));
    if(DCS.view==='list'&&!$('dcsList').children.length&&!DCS.hasMore)$('dcsEmpty').style.display='';
    dsToast('Comment deleted');
  }).catch(function(){dsToast('Could not delete this comment.')});
}

document.addEventListener('click',function(e){
  var t=e.target;function up(s){return(t&&t.closest)?t.closest(s):null}
  $('dcsSortMenu').classList.remove('show');if(!DCS.open)return;
  var more=up('.dcs-more');if(more){dcsOpenMenu(more);return}if(!up('#dcsMenu'))dcsCloseMenu();
  var lk=up('.dcs-like');if(lk){dcsLike(lk);return}
  var dl=up('.dcs-dislike');if(dl){dcsDislike(dl);return}
  var rp=up('.dcs-reply');if(rp){var rid=parseInt(rp.getAttribute('data-id'),10),ru=rp.getAttribute('data-user');if(DCS.view==='replies')dcsSetReply(rid,ru,rid!==DCS.threadRoot);else dcsOpenThread(rid);$('dcsInput').focus();return}
  var vb=up('.dcs-viewreplies');if(vb){var vid=parseInt(vb.getAttribute('data-id'),10);if(vb.getAttribute('data-inline')==='1')dcsToggleSub(vid,vb);else dcsOpenThread(vid);return}
  var sm=up('.dcs-submore');if(sm){dcsLoadSub(parseInt(sm.getAttribute('data-id'),10),true);return}
  var rm=up('.dcs-readmore');if(rm){var box=rm.parentNode,o=box.classList.toggle('expanded');rm.textContent=o?'Show less':'...Read more';return}
});
function dcsPaintLike(btn,on,n){btn.classList.toggle('on',!!on);btn.querySelector('i').className=on?'ri-thumb-up-fill':'ri-thumb-up-line';btn.querySelector('span').textContent=n>0?fmtNum(n):'';btn.setAttribute('data-raw',n)}
function dcsPaintDis(btn,on){btn.classList.toggle('on',!!on);btn.querySelector('i').className=on?'ri-thumb-down-fill':'ri-thumb-down-line'}
function dcsLike(btn){
  var id=btn.getAttribute('data-id'),row=btn.closest('.dcs-row2'),dBtn=row?row.querySelector('.dcs-dislike'):null;
  var wasL=btn.classList.contains('on'),wasD=!!(dBtn&&dBtn.classList.contains('on')),n=parseInt(btn.getAttribute('data-raw'),10)||0;
  dcsPaintLike(btn,!wasL,Math.max(0,n+(!wasL?1:-1)));if(dBtn)dcsPaintDis(dBtn,false);
  function revert(){dcsPaintLike(btn,wasL,n);if(dBtn)dcsPaintDis(dBtn,wasD)}
  dsPost('ds_comment_like',{comment_id:id}).then(function(d){if(!d||!d.success){revert();return}dcsPaintLike(btn,d.liked,d.likes);if(dBtn)dcsPaintDis(dBtn,!!d.disliked);if(d.owner_liked!==null&&d.owner_liked!==undefined)dcsSetOwnerLike(id,d.owner_liked)}).catch(revert);
}
function dcsDislike(btn){
  var id=btn.getAttribute('data-id'),row=btn.closest('.dcs-row2'),lBtn=row?row.querySelector('.dcs-like'):null;
  var wasD=btn.classList.contains('on'),wasL=!!(lBtn&&lBtn.classList.contains('on')),n=lBtn?(parseInt(lBtn.getAttribute('data-raw'),10)||0):0;
  dcsPaintDis(btn,!wasD);if(lBtn&&!wasD&&wasL)dcsPaintLike(lBtn,false,Math.max(0,n-1));
  function revert(){dcsPaintDis(btn,wasD);if(lBtn)dcsPaintLike(lBtn,wasL,n)}
  dsPost('ds_comment_dislike',{comment_id:id}).then(function(d){if(!d||!d.success){revert();return}dcsPaintDis(btn,!!d.disliked);if(lBtn)dcsPaintLike(lBtn,!!d.liked,d.likes);if(d.owner_liked!==null&&d.owner_liked!==undefined)dcsSetOwnerLike(id,d.owner_liked)}).catch(revert);
}

function dcsSend(){
  var input=$('dcsInput'),text=input.value.trim();if(!text||!DCS.postId)return;
  if(!DS_IS_MEMBER){dsToast('Join this pod to comment');return}
  var send=$('dcsSend');send.disabled=true;var inThread=(DCS.view==='replies'),target=inThread?(DCS.replyTo?DCS.replyTo.id:DCS.threadRoot):0,pid=DCS.postId,rootAtSend=DCS.threadRoot;
  dsPost('ds_comment_add',{post_id:pid,text:text,parent_id:target}).then(function(d){
    send.disabled=!input.value.trim();if(!d||!d.success){dsToast((d&&d.error)||'Could not post your comment.');return}
    input.value='';send.disabled=true;var c=d.comment;
    if(inThread&&d.parent_id)dcsInsertReply(d.parent_id,c);
    else if(!inThread){var list=$('dcsList'),atEnd=(DCS.sort==='old'&&!DCS.hasMore),h=dcsCommentHtml(c,'top');
      if(atEnd)list.insertAdjacentHTML('beforeend',h);else list.insertAdjacentHTML('afterbegin',h);DCS.offset+=1;$('dcsEmpty').style.display='none';
      var ne=list.querySelector('.dcs-item[data-id="'+c.id+'"]');if(atEnd&&ne&&ne.scrollIntoView)ne.scrollIntoView({block:'nearest'});else $('dcsScroll').scrollTop=0}
    dcsBumpPost(1);
    if(inThread){var rc=DCS.map[rootAtSend];dcsSetReply(rootAtSend,rc?rc.username:'',false)}else dcsSetReply(null,'');
  }).catch(function(){send.disabled=!input.value.trim();dsToast('Could not post your comment.')});
}
$('dcsSend').addEventListener('click',dcsSend);
$('dcsInput').addEventListener('input',function(){$('dcsSend').disabled=!this.value.trim()});
$('dcsInput').addEventListener('keydown',function(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();if(this.value.trim())dcsSend()}});

$('dcsSortBtn').addEventListener('click',function(e){e.stopPropagation();dcsCloseMenu();$('dcsSortMenu').classList.toggle('show')});
Array.prototype.forEach.call($('dcsSortMenu').querySelectorAll('button'),function(b){b.addEventListener('click',function(e){e.stopPropagation();var s=this.getAttribute('data-sort');$('dcsSortMenu').classList.remove('show');if(s===DCS.sort)return;DCS.sort=s;$('dcsSortLbl').textContent=DCS_SORT_LABELS[s]||'Top';Array.prototype.forEach.call($('dcsSortMenu').querySelectorAll('button'),function(x){x.classList.remove('active')});this.classList.add('active');dcsRestart()})});
function dcsRestart(){DCS.offset=0;DCS.hasMore=false;DCS.loading=false;DCS.map={};DCS_SUB={};dcsCloseMenu();dcsCloseThread();$('dcsList').innerHTML='';$('dcsSkel').style.display='';$('dcsEmpty').style.display='none';dcsLoad(true)}

/* ── post view: the post itself, shown above its comments ── */
function dpvHtml(p){
  var f=window.dsRenderPost?window.dsRenderPost(p):'';
  return f.replace('id="ds-post-'+p.id+'"','id="dpv-post-'+p.id+'"').replace('ds-body clamp','ds-body');
}
function dpvSync(p){
  if(!DCS.open||String(DCS.postId)!==String(p.id))return;var r=$('dcsThreadInfo');if(!r)return;
  var l=r.querySelector('.gf-like'),d=r.querySelector('.gf-dis');if(!l||!d)return;
  l.classList.toggle('active',!!p.liked);d.classList.toggle('active',!!p.disliked);
  var lc=r.querySelector('.gf-like-count'),cc=r.querySelector('.gf-comm-count');if(lc)lc.textContent=fmtNum(p.likes);if(cc&&!p.commentsOff)cc.textContent=fmtNum(p.replies);
}
/* tap a post (not a button / link / media / poll) to open it */
document.addEventListener('click',function(e){
  var post=e.target.closest&&e.target.closest('.gf-post');if(!post||String(post.id).indexOf('ds-post-')!==0)return;
  if(e.target.closest('button,a,video,img,form,label,input,textarea,.ds-poll,.gf-actions,.gf-media-wrap,.gf-avatar,.gf-username,.ds-src,.av-wrap,.ds-more'))return;
  var sel=window.getSelection&&window.getSelection().toString();if(sel)return;
  dcsOpen(post.dataset.id);
});
function dcsOpen(postId){
  var p=findPost(postId);if(!p)return;
  if(DCS.open&&String(DCS.postId)===String(postId)){var cb=$('dcsCmtBar');if(cb&&cb.scrollIntoView)cb.scrollIntoView({behavior:'smooth',block:'start'});return}
  DCS.open=true;DCS.postId=postId;DCS.view='list';DCS.threadRoot=0;DCS.threadOffset=0;DCS.threadHasMore=false;DCS.threadLoading=false;DCS_SUB={};
  $('dcsHead').style.display='';$('dcsScroll').style.display='';$('dcsTHead').style.display='none';$('dcsTScroll').style.display='none';
  $('dcsCount').textContent=' · '+fmtNum(p.replies||0);
  $('dcsThreadInfo').innerHTML=dpvHtml(p);
  $('dcsMyAv').innerHTML=dcsAv(DS_ME_PIC);
  var locked=p.locked||p.commentsOff||!DS_IS_MEMBER;$('dcsInput').disabled=locked;$('dcsInput').placeholder=p.commentsOff?'Comments are turned off':(p.locked?'This thread is locked':(DS_IS_MEMBER?'Join the conversation':'Join this pod to comment'));$('dcsInput').value='';$('dcsSend').disabled=true;
  dcsSetReply(null,'');$('dcsScroll').scrollTop=0;
  $('dcsOv').classList.add('open');$('dcs').classList.add('open');document.body.style.overflow='hidden';
  if(p.commentsOff){DCS.offset=0;DCS.hasMore=false;DCS.loading=false;DCS.map={};$('dcsList').innerHTML='';$('dcsSkel').style.display='none';$('dcsEmpty').textContent='Comments are turned off for this post.';$('dcsEmpty').style.display=''}
  else{$('dcsEmpty').textContent='No comments yet. Start the conversation.';dcsRestart()}
}
function dcsClose(){
  var d=$('dcs');d.classList.remove('open');d.style.transition='';d.style.transform='';$('dcsOv').classList.remove('open');
  dcsCloseMenu();$('dcsSortMenu').classList.remove('show');DCS.open=false;DCS.view='list';DCS.threadRoot=0;DCS_SUB={};dsLock();
}
$('dcsClose').addEventListener('click',dcsClose);

/* ═════════ keyboard ═════════ */
document.addEventListener('keydown',function(e){
  if(e.key!=='Escape')return;
  if($('dsSheet').classList.contains('show')){closeDsSheet();return}
  if($('dcsMenu').classList.contains('show')){dcsCloseMenu();return}
  if($('dcs').classList.contains('open')){if(DCS.view==='replies')dcsCloseThread();else dcsClose();return}
  var o=document.querySelector('.ds-ov.open');if(o){if(o.id==='dsCreateOv')closeCreateModal();else dsClose(o.id)}
});
</script>
</body>
</html>