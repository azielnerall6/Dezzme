<?php
session_start();
include 'config.php';

/* ══════════════════════════════════════════════════════════════════
   AUTH + CORE IDENTIFIERS
   ══════════════════════════════════════════════════════════════════ */
$current_user = (int)($_SESSION['user_id'] ?? 0);
if (!$current_user) {
    header("Location: login.php");
    exit();
}

$group_id = (int)($_GET['id'] ?? 0);
if (!$group_id) {
    echo "Group not found";
    exit();
}

/* ══════════════════════════════════════════════════════════════════
   CSRF TOKEN
   ══════════════════════════════════════════════════════════════════ */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

/* Verify on every POST before any POST branch runs.
   Accepts the token from $_POST (form submissions) OR the
   X-CSRF-Token header (JSON-body fetch calls, e.g. gsearch_recents). */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!$submitted_token || !hash_equals($_SESSION['csrf_token'], $submitted_token)) {
        http_response_code(403);
        // JSON endpoints expect JSON back, not a plain die() page
        if (isset($_POST['ajax_like']) || isset($_POST['ajax_repost']) || isset($_POST['ajax_favorite'])
            || isset($_POST['mute_group']) || isset($_POST['notif_group'])
            || isset($_GET['gsearch_recents'])
            || isset($_POST['report_to_admins'])
            || (isset($_POST['action']) && $_POST['action'] === 'toggle_follow')) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid or missing CSRF token']);
        } else {
            echo "Invalid request (CSRF check failed). Please go back and try again.";
        }
        exit();
    }
}

/* current user's profile pic — prepared */
$stmt = $conn->prepare("SELECT profile_pic FROM users WHERE id=?");
$stmt->bind_param("i", $current_user);
$stmt->execute();
$current_user_pic = $stmt->get_result()->fetch_assoc()['profile_pic'] ?? '';
$stmt->close();
if (empty($current_user_pic)) $current_user_pic = 'uploads/me.jpeg';

/* ── Fetch group info — prepared ── */
$stmt = $conn->prepare("
    SELECT g.*, u.username AS creator_name, u.profile_pic AS creator_pic
    FROM groups g
    JOIN users u ON g.created_by = u.id
    WHERE g.id = ?
");
$stmt->bind_param("i", $group_id);
$stmt->execute();
$group = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$group) { echo "Group not found"; exit(); }

$group_creator_id = (int)$group['created_by'];
$is_owner = ($group_creator_id === $current_user);

// ─── Check if current user is an admin ───────────────────────────────────────
$is_admin = false;
$stmt = $conn->prepare("SELECT 1 FROM group_admins WHERE group_id=? AND user_id=?");
$stmt->bind_param("ii", $group_id, $current_user);
$stmt->execute();
$admin_check = $stmt->get_result();
if ($admin_check && $admin_check->num_rows > 0) $is_admin = true;
$stmt->close();

/* ══════════════════════════════════════════════════════════════════
   Schema is already migrated (group_bans, groups.banner_pic,
   groups.privacy, group_join_requests, muted_groups, group_notif_off,
   group_name_history, group_search_recents, group_post_reports,
   groups.is_paused, groups.paused_at, groups.paused_by all
   confirmed to exist).
   The CREATE TABLE IF NOT EXISTS / SHOW COLUMNS / ALTER TABLE checks
   that used to run here on every page load have been removed.
   ══════════════════════════════════════════════════════════════════ */
$is_private_group = ($group['privacy'] ?? 'public') === 'private';

// ─── PAUSE STATE ───────────────────────────────────────────────────────────
$is_paused = !empty($group['is_paused']);
$paused_by_name = null;
$paused_by_role = null;
if ($is_paused && !empty($group['paused_by'])) {
    $pb_id = (int)$group['paused_by'];
    $stmt = $conn->prepare("SELECT username FROM users WHERE id=?");
    $stmt->bind_param("i", $pb_id);
    $stmt->execute();
    $pb_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($pb_row) {
        $paused_by_name = $pb_row['username'];
        $paused_by_role = ($pb_id === $group_creator_id) ? 'owner' : 'admin';
    }
}

// map onto the names the template further down actually expects
$group_is_paused      = $is_paused;
$pause_actor_label    = $paused_by_role === 'owner' ? 'owner' : 'admin';
// no auto-resume duration is stored in the schema (only is_paused/paused_at/paused_by),
// so leave these as null — the template's `if ($group_pause_hours_left)` /
// `if ($group_pause_resumes_at)` checks will just skip those optional lines.
$group_pause_hours_left = null;
$group_pause_resumes_at = null;

// ─── OWNER ACTIONS ────────────────────────────────────────────────────────────

if (isset($_POST['change_group_pic']) && $is_owner) {
    if (!empty($_FILES['group_pic']['name'])) {
        $file = time() . '_' . basename($_FILES['group_pic']['name']);
        move_uploaded_file($_FILES['group_pic']['tmp_name'], "uploads/" . $file);
        $stmt = $conn->prepare("UPDATE groups SET group_pic=? WHERE id=?");
        $stmt->bind_param("si", $file, $group_id);
        $stmt->execute();
        $stmt->close();

        log_group_activity($conn, $group_id, $current_user, 'change_group_pic');
    }
    header("Location: group.php?id=$group_id"); exit();
}

// ─── TOGGLE GROUP PRIVACY (owner only) ────────────────────────────────────────
if (isset($_POST['toggle_privacy']) && $is_owner) {
    $new_privacy = ($is_private_group) ? 'public' : 'private';

    $stmt = $conn->prepare("UPDATE groups SET privacy=? WHERE id=?");
    $stmt->bind_param("si", $new_privacy, $group_id);
    $stmt->execute();
    $stmt->close();

    log_group_activity($conn, $group_id, $current_user, $new_privacy === 'private' ? 'make_private' : 'make_public');

    if ($new_privacy === 'public') {
        $stmt = $conn->prepare("SELECT user_id FROM group_join_requests WHERE group_id=? AND status='pending'");
        $stmt->bind_param("i", $group_id);
        $stmt->execute();
        $pending = $stmt->get_result();
        $ins = $conn->prepare("INSERT IGNORE INTO group_members (group_id,user_id) VALUES (?,?)");
        while ($row = $pending->fetch_assoc()) {
            $uid = (int)$row['user_id'];
            $ins->bind_param("ii", $group_id, $uid);
            $ins->execute();
        }
        $ins->close();
        $stmt->close();

        $stmt = $conn->prepare("UPDATE group_join_requests SET status='approved', decided_at=NOW() WHERE group_id=? AND status='pending'");
        $stmt->bind_param("i", $group_id);
        $stmt->execute();
        $stmt->close();
    }

    header("Location: group.php?id=$group_id"); exit();
}

// ─── MUTE GROUP ──────────────────────────────────────────────────────────────
if (isset($_POST['mute_group'])) {
    header('Content-Type: application/json');
    $muted = (int)($_POST['muted'] ?? 0);
    if ($muted) {
        $stmt = $conn->prepare("INSERT IGNORE INTO muted_groups (user_id, group_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $current_user, $group_id);
    } else {
        $stmt = $conn->prepare("DELETE FROM muted_groups WHERE user_id=? AND group_id=?");
        $stmt->bind_param("ii", $current_user, $group_id);
    }
    $stmt->execute();
    $stmt->close();
    echo json_encode(['ok' => true]);
    exit;
}

// ─── GROUP NOTIFICATIONS OFF ──────────────────────────────────────────────────
if (isset($_POST['notif_group'])) {
    header('Content-Type: application/json');
    $off = (int)($_POST['off'] ?? 0);
    if ($off) {
        $stmt = $conn->prepare("INSERT IGNORE INTO group_notif_off (user_id, group_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $current_user, $group_id);
    } else {
        $stmt = $conn->prepare("DELETE FROM group_notif_off WHERE user_id=? AND group_id=?");
        $stmt->bind_param("ii", $current_user, $group_id);
    }
    $stmt->execute();
    $stmt->close();
    echo json_encode(['ok' => true]);
    exit;
}

// ─── Change banner pic (owner only) ───────────────────────────────────────────
if (isset($_POST['change_banner_pic']) && $is_owner) {
    if (!empty($_FILES['banner_pic']['name'])) {
        $file = time() . '_' . basename($_FILES['banner_pic']['name']);
        move_uploaded_file($_FILES['banner_pic']['tmp_name'], "uploads/" . $file);
        $stmt = $conn->prepare("UPDATE groups SET banner_pic=? WHERE id=?");
        $stmt->bind_param("si", $file, $group_id);
        $stmt->execute();
        $stmt->close();

        log_group_activity($conn, $group_id, $current_user, 'change_banner_pic');
    }
    header("Location: group.php?id=$group_id"); exit();
}

if (isset($_POST['change_group_title']) && $is_owner) {
    $new_title = trim((string)($_POST['new_title'] ?? ''));

    if ($new_title !== '' && $new_title !== $group['title']) {
        $old_title = $group['title'];

        $stmt = $conn->prepare("INSERT INTO group_name_history (group_id, old_title) VALUES (?, ?)");
        $stmt->bind_param("is", $group_id, $old_title);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("UPDATE groups SET title=? WHERE id=?");
        $stmt->bind_param("si", $new_title, $group_id);
        $stmt->execute();
        $stmt->close();

        log_group_activity($conn, $group_id, $current_user, 'rename_group', null, "$old_title → $new_title");
    }
    header("Location: group.php?id=$group_id"); exit();
}

if (isset($_POST['change_group_desc']) && $is_owner) {
    $new_desc = (string)($_POST['new_desc'] ?? '');
    $stmt = $conn->prepare("UPDATE groups SET description=? WHERE id=?");
    $stmt->bind_param("si", $new_desc, $group_id);
    $stmt->execute();
    $stmt->close();

    log_group_activity($conn, $group_id, $current_user, 'change_desc');

    header("Location: group.php?id=$group_id"); exit();
}

if (isset($_POST['toggle_adult']) && $is_owner) {
    $new_adult = !empty($group['is_adult']) ? 0 : 1;
    $stmt = $conn->prepare("UPDATE groups SET is_adult=? WHERE id=?");
    $stmt->bind_param("ii", $new_adult, $group_id);
    $stmt->execute();
    $stmt->close();

    log_group_activity($conn, $group_id, $current_user, $new_adult ? 'enable_adult' : 'disable_adult');

    header("Location: group.php?id=$group_id"); exit();
}

if (isset($_POST['save_guidelines']) && $is_owner) {
    $new_guidelines = (string)($_POST['custom_guidelines'] ?? '');
    $stmt = $conn->prepare("UPDATE groups SET custom_guidelines=? WHERE id=?");
    $stmt->bind_param("si", $new_guidelines, $group_id);
    $stmt->execute();
    $stmt->close();

    log_group_activity($conn, $group_id, $current_user, 'change_guidelines');

    header("Location: group.php?id=$group_id"); exit();
}

if (isset($_POST['delete_group']) && $is_owner) {
    // group_activity_log rows for this group cascade-delete automatically
    // via its FK to groups(id), so no explicit cleanup needed here.
    foreach (['group_members', 'group_posts', 'group_admins', 'group_bans', 'group_join_requests', 'group_hashtags', 'group_post_reports'] as $tbl) {
        $stmt = $conn->prepare("DELETE FROM $tbl WHERE group_id=?");
        $stmt->bind_param("i", $group_id);
        $stmt->execute();
        $stmt->close();
    }
    $stmt = $conn->prepare("DELETE FROM groups WHERE id=?");
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $stmt->close();
    header("Location: community.php"); exit();
}

if (isset($_POST['appoint_admin']) && $is_owner) {
    $appoint_id = (int)($_POST['appoint_user_id'] ?? 0);
    if ($appoint_id > 0) {
        $stmt = $conn->prepare("INSERT IGNORE INTO group_admins (group_id, user_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $group_id, $appoint_id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected > 0) {
            log_group_activity($conn, $group_id, $current_user, 'appoint_admin', $appoint_id);
        }
    }
    header("Location: group.php?id=$group_id"); exit();
}

if (isset($_POST['remove_admin']) && $is_owner) {
    $remove_admin_id = (int)($_POST['remove_admin_id'] ?? 0);
    $stmt = $conn->prepare("DELETE FROM group_admins WHERE group_id=? AND user_id=?");
    $stmt->bind_param("ii", $group_id, $remove_admin_id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected > 0) {
        log_group_activity($conn, $group_id, $current_user, 'remove_admin', $remove_admin_id);
    }

    header("Location: group.php?id=$group_id"); exit();
}

// ─── PAUSE / RESUME GROUP (owner or admin) ────────────────────────────────────
if (isset($_POST['toggle_pause']) && ($is_owner || $is_admin)) {
    $new_paused = $is_paused ? 0 : 1;
    if ($new_paused) {
        $stmt = $conn->prepare("UPDATE groups SET is_paused=1, paused_at=NOW(), paused_by=? WHERE id=?");
        $stmt->bind_param("ii", $current_user, $group_id);
    } else {
        $stmt = $conn->prepare("UPDATE groups SET is_paused=0, paused_at=NULL, paused_by=NULL WHERE id=?");
        $stmt->bind_param("i", $group_id);
    }
    $stmt->execute();
    $stmt->close();

    log_group_activity($conn, $group_id, $current_user, $new_paused ? 'pause_group' : 'resume_group');

    header("Location: group.php?id=$group_id"); exit();
}

// ─── ADD GROUP HASHTAG (owner only) ───────────────────────────────────────────
if (isset($_POST['add_group_hashtag']) && $is_owner) {
    $new_tag = strtolower(trim((string)($_POST['new_hashtag'] ?? '')));
    $new_tag = preg_replace('/[^a-z0-9_]/', '', $new_tag); // letters, numbers, underscore only
    $new_tag = ltrim($new_tag, '#');

    if ($new_tag !== '' && mb_strlen($new_tag) <= 30) {
        $tag_count = (int)$conn->query("SELECT COUNT(*) as c FROM group_hashtags WHERE group_id=$group_id")->fetch_assoc()['c'];
        if ($tag_count < 20) {
            $stmt = $conn->prepare("INSERT IGNORE INTO group_hashtags (group_id, tag) VALUES (?, ?)");
            $stmt->bind_param("is", $group_id, $new_tag);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();

            if ($affected > 0) {
                log_group_activity($conn, $group_id, $current_user, 'add_hashtag', null, $new_tag);
            }
        }
    }
    header("Location: group.php?id=$group_id"); exit();
}

// ─── REMOVE GROUP HASHTAG (owner only) ────────────────────────────────────────
if (isset($_POST['remove_group_hashtag']) && $is_owner) {
    $remove_tag_id = (int)($_POST['remove_tag_id'] ?? 0);

    $removed_tag_text = null;
    $stmt = $conn->prepare("SELECT tag FROM group_hashtags WHERE id=? AND group_id=?");
    $stmt->bind_param("ii", $remove_tag_id, $group_id);
    $stmt->execute();
    $tag_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($tag_row) $removed_tag_text = $tag_row['tag'];

    $stmt = $conn->prepare("DELETE FROM group_hashtags WHERE id=? AND group_id=?");
    $stmt->bind_param("ii", $remove_tag_id, $group_id);
    $stmt->execute();
    $stmt->close();

    if ($removed_tag_text !== null) {
        log_group_activity($conn, $group_id, $current_user, 'remove_hashtag', null, $removed_tag_text);
    }

    header("Location: group.php?id=$group_id"); exit();
}

if (isset($_POST['delete_post'])) {
    $del_post_id = (int)($_POST['delete_post_id'] ?? 0);
    $del_meme_id = (int)($_POST['delete_meme_id'] ?? 0);

    $stmt = $conn->prepare("SELECT posted_by FROM group_posts WHERE id=? AND group_id=?");
    $stmt->bind_param("ii", $del_post_id, $group_id);
    $stmt->execute();
    $own_check = $stmt->get_result();
    $is_post_owner = false;
    if ($own_check && $own_check->num_rows > 0) {
        $row = $own_check->fetch_assoc();
        $is_post_owner = ((int)$row['posted_by'] === $current_user);
    }
    $stmt->close();

    if ($is_owner || $is_admin || $is_post_owner) {
        // grab the caption before we delete anything, for the activity log
        $del_caption = null;
        $stmt = $conn->prepare("SELECT caption FROM memes WHERE id=?");
        $stmt->bind_param("i", $del_meme_id);
        $stmt->execute();
        $cap_row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($cap_row) $del_caption = trim((string)$cap_row['caption']);

        $stmt = $conn->prepare("DELETE FROM group_posts WHERE id=? AND group_id=?");
        $stmt->bind_param("ii", $del_post_id, $group_id);
        $stmt->execute();
        $stmt->close();

        // ── resolve any pending reports pointing at this post ──
        $stmt = $conn->prepare("UPDATE group_post_reports SET status='resolved' WHERE group_post_id=?");
        $stmt->bind_param("i", $del_post_id);
        $stmt->execute();
        $stmt->close();

        foreach (['likes', 'reposts', 'favorites', 'downloads', 'comments', 'group_posts'] as $tbl) {
            $stmt = $conn->prepare("DELETE FROM $tbl WHERE meme_id=?");
            $stmt->bind_param("i", $del_meme_id);
            $stmt->execute();
            $stmt->close();
        }
        $stmt = $conn->prepare("DELETE FROM memes WHERE id=?");
        $stmt->bind_param("i", $del_meme_id);
        $stmt->execute();
        $stmt->close();

        // Only log this as a moderation action when an owner/admin removed
        // someone else's post — a member deleting their own post isn't
        // moderation activity worth surfacing in the Activity Log.
        if (($is_owner || $is_admin) && !($is_post_owner && !$is_owner && !$is_admin)) {
            $caption_snippet = $del_caption ? (mb_strlen($del_caption) > 60 ? mb_substr($del_caption, 0, 60) . '…' : $del_caption) : null;
            log_group_activity($conn, $group_id, $current_user, 'delete_post', null, $caption_snippet);
        }
    }

    header("Location: group.php?id=$group_id"); exit();
}

// ─── DISMISS A POST REPORT (owner/admin only, keeps the post up) ──────────────
if (isset($_POST['dismiss_report']) && ($is_owner || $is_admin)) {
    $dismiss_id = (int)($_POST['report_id'] ?? 0);
    $stmt = $conn->prepare("UPDATE group_post_reports SET status='dismissed' WHERE id=? AND group_id=?");
    $stmt->bind_param("ii", $dismiss_id, $group_id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected > 0) {
        log_group_activity($conn, $group_id, $current_user, 'dismiss_report');
    }

    header("Location: group.php?id=$group_id"); exit();
}

if (isset($_POST['remove_member']) && ($is_owner || $is_admin)) {
    $rm_user = (int)($_POST['remove_member_id'] ?? 0);
    $kicked = false;

    if ($is_admin && !$is_owner) {
        $stmt = $conn->prepare("SELECT 1 FROM group_admins WHERE group_id=? AND user_id=?");
        $stmt->bind_param("ii", $group_id, $rm_user);
        $stmt->execute();
        $target_is_admin = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if (!$target_is_admin && $rm_user !== $group_creator_id) {
            $stmt = $conn->prepare("DELETE FROM group_members WHERE group_id=? AND user_id=?");
            $stmt->bind_param("ii", $group_id, $rm_user);
            $stmt->execute();
            $kicked = $stmt->affected_rows > 0;
            $stmt->close();
        }
    } else {
        foreach (['group_members', 'group_admins'] as $tbl) {
            $stmt = $conn->prepare("DELETE FROM $tbl WHERE group_id=? AND user_id=?");
            $stmt->bind_param("ii", $group_id, $rm_user);
            $stmt->execute();
            if ($tbl === 'group_members') $kicked = $stmt->affected_rows > 0;
            $stmt->close();
        }
    }

    if ($kicked && $rm_user > 0) {
        log_group_activity($conn, $group_id, $current_user, 'kick_member', $rm_user);
    }

    header("Location: group.php?id=$group_id"); exit();
}

if (isset($_POST['ban_member']) && ($is_owner || $is_admin)) {
    $ban_user = (int)($_POST['ban_user_id'] ?? 0);
    if ($ban_user && $ban_user !== $current_user && $ban_user !== $group_creator_id) {
        if ($is_owner) {
            $stmt = $conn->prepare("INSERT IGNORE INTO blocks (blocker_id, blocked_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $current_user, $ban_user);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $conn->prepare("INSERT IGNORE INTO group_bans (group_id, banned_id, banned_by) VALUES (?, ?, ?)");
            $stmt->bind_param("iii", $group_id, $ban_user, $current_user);
            $stmt->execute();
            $stmt->close();
        }
        foreach (['group_members', 'group_admins', 'group_join_requests'] as $tbl) {
            $stmt = $conn->prepare("DELETE FROM $tbl WHERE group_id=? AND user_id=?");
            $stmt->bind_param("ii", $group_id, $ban_user);
            $stmt->execute();
            $stmt->close();
        }

        log_group_activity($conn, $group_id, $current_user, 'ban_member', $ban_user);
    }
    header("Location: group.php?id=$group_id"); exit();
}

if (isset($_POST['unban_member']) && ($is_owner || $is_admin)) {
    $unban_user = (int)($_POST['unban_user_id'] ?? 0);
    if ($unban_user) {
        if ($is_owner) {
            $stmt = $conn->prepare("DELETE FROM blocks WHERE blocker_id=? AND blocked_id=?");
            $stmt->bind_param("ii", $group_creator_id, $unban_user);
            $stmt->execute();
            $stmt->close();
        }
        $stmt = $conn->prepare("DELETE FROM group_bans WHERE group_id=? AND banned_id=?");
        $stmt->bind_param("ii", $group_id, $unban_user);
        $stmt->execute();
        $stmt->close();

        log_group_activity($conn, $group_id, $current_user, 'unban_member', $unban_user);
    }
    header("Location: group.php?id=$group_id"); exit();
}

// ─── JOIN REQUEST ACTIONS (private groups) ────────────────────────────────────

if (isset($_POST['approve_request']) && ($is_owner || $is_admin)) {
    $req_user = (int)($_POST['request_user_id'] ?? 0);
    if ($req_user) {
        $stmt = $conn->prepare("SELECT 1 FROM blocks WHERE blocker_id=? AND blocked_id=?");
        $stmt->bind_param("ii", $group_creator_id, $req_user);
        $stmt->execute();
        $owner_blocked = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        $stmt = $conn->prepare("SELECT 1 FROM group_bans WHERE group_id=? AND banned_id=?");
        $stmt->bind_param("ii", $group_id, $req_user);
        $stmt->execute();
        $group_banned = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if (!$owner_blocked && !$group_banned) {
            $stmt = $conn->prepare("INSERT IGNORE INTO group_members (group_id,user_id) VALUES (?,?)");
            $stmt->bind_param("ii", $group_id, $req_user);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("UPDATE group_join_requests SET status='approved', decided_at=NOW() WHERE group_id=? AND user_id=?");
            $stmt->bind_param("ii", $group_id, $req_user);
            $stmt->execute();
            $stmt->close();

            log_group_activity($conn, $group_id, $current_user, 'approve_request', $req_user);
        }
    }
    header("Location: group.php?id=$group_id"); exit();
}

if (isset($_POST['deny_request']) && ($is_owner || $is_admin)) {
    $req_user = (int)($_POST['request_user_id'] ?? 0);
    if ($req_user) {
        $stmt = $conn->prepare("UPDATE group_join_requests SET status='denied', decided_at=NOW() WHERE group_id=? AND user_id=?");
        $stmt->bind_param("ii", $group_id, $req_user);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected > 0) {
            log_group_activity($conn, $group_id, $current_user, 'deny_request', $req_user);
        }
    }
    header("Location: group.php?id=$group_id"); exit();
}

if (isset($_POST['cancel_request'])) {
    $stmt = $conn->prepare("DELETE FROM group_join_requests WHERE group_id=? AND user_id=? AND status='pending'");
    $stmt->bind_param("ii", $group_id, $current_user);
    $stmt->execute();
    $stmt->close();
    header("Location: group.php?id=$group_id"); exit();
}

// ─── BLOCK RULES ─────────────────────────────────────────────────────────────
$blocked_by_owner = [];
$stmt = $conn->prepare("SELECT blocked_id FROM blocks WHERE blocker_id=?");
$stmt->bind_param("i", $group_creator_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $blocked_by_owner[] = (int)$row['blocked_id'];
$stmt->close();
$blocked_sql = !empty($blocked_by_owner) ? implode(',', $blocked_by_owner) : '';

// ─── GROUP-SCOPED BAN LIST ────────────────────────────────────────────────────
$group_banned_ids = [];
$stmt = $conn->prepare("SELECT banned_id FROM group_bans WHERE group_id=?");
$stmt->bind_param("i", $group_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $group_banned_ids[] = (int)$row['banned_id'];
$stmt->close();
$group_banned_sql = !empty($group_banned_ids) ? implode(',', $group_banned_ids) : '';

// Auto-kick on page load
if (in_array($current_user, $blocked_by_owner, true) || in_array($current_user, $group_banned_ids, true)) {
    $stmt = $conn->prepare("DELETE FROM group_members WHERE group_id=? AND user_id=?");
    $stmt->bind_param("ii", $group_id, $current_user);
    $stmt->execute();
    $stmt->close();
}

// ─── VIEWER'S OWN BLOCKS + MUTES ─────────────────────────────────────────────
$viewer_excluded_ids = [];
$stmt = $conn->prepare("
    SELECT blocked_id AS uid FROM blocks WHERE blocker_id=?
    UNION
    SELECT muted_id AS uid FROM muted_users WHERE muter_id=?
");
$stmt->bind_param("ii", $current_user, $current_user);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $viewer_excluded_ids[] = (int)$row['uid'];
$stmt->close();
$viewer_excluded_sql = !empty($viewer_excluded_ids) ? implode(',', $viewer_excluded_ids) : '';

// ─── SORT ─────────────────────────────────────────────────────────────────────
$allowed_sorts = ['newest','liked','reposted','favorited','downloaded','videos','images','following','trending'];
$sort = $_GET['sort'] ?? 'newest';
if (!in_array($sort, $allowed_sorts, true)) $sort = 'newest';
$order = "gp.created_at DESC";

// ─── MEMBERSHIP ───────────────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT 1 FROM group_members WHERE group_id=? AND user_id=?");
$stmt->bind_param("ii", $group_id, $current_user);
$stmt->execute();
$is_member = $stmt->get_result()->num_rows > 0;
$stmt->close();

// ─── Current user's pending join-request state ────────────────────────────────
$my_request_status = null; // null | 'pending' | 'denied'
if (!$is_member) {
    $stmt = $conn->prepare("SELECT status FROM group_join_requests WHERE group_id=? AND user_id=?");
    $stmt->bind_param("ii", $group_id, $current_user);
    $stmt->execute();
    $rq = $stmt->get_result();
    if ($rq && $rq->num_rows > 0) {
        $my_request_status = $rq->fetch_assoc()['status'];
        if ($my_request_status === 'approved') $my_request_status = null;
    }
    $stmt->close();
}

// ─── join_group (branches on privacy) ─────────────────────────────────────────
if (isset($_POST['join_group'])) {
    if ($is_paused) {
        header("Location: group.php?id=$group_id"); exit();
    }

    $stmt = $conn->prepare("SELECT 1 FROM blocks WHERE blocker_id=? AND blocked_id=?");
    $stmt->bind_param("ii", $group_creator_id, $current_user);
    $stmt->execute();
    $owner_blocked = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    $stmt = $conn->prepare("SELECT 1 FROM group_bans WHERE group_id=? AND banned_id=?");
    $stmt->bind_param("ii", $group_id, $current_user);
    $stmt->execute();
    $group_banned = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    $blocked = $owner_blocked || $group_banned;

    if (!$is_member && !$blocked) {
        if ($is_private_group) {
            $stmt = $conn->prepare("
                INSERT INTO group_join_requests (group_id, user_id, status, requested_at)
                VALUES (?, ?, 'pending', NOW())
                ON DUPLICATE KEY UPDATE status='pending', requested_at=NOW(), decided_at=NULL
            ");
            $stmt->bind_param("ii", $group_id, $current_user);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $conn->prepare("INSERT INTO group_members (group_id,user_id) VALUES (?,?)");
            $stmt->bind_param("ii", $group_id, $current_user);
            $stmt->execute();
            $stmt->close();
        }
    }
    header("Location: group.php?id=$group_id"); exit();
}

if (isset($_POST['leave_group'])) {
    $stmt = $conn->prepare("DELETE FROM group_members WHERE group_id=? AND user_id=?");
    $stmt->bind_param("ii", $group_id, $current_user);
    $stmt->execute();
    $stmt->close();
    header("Location: group.php?id=$group_id"); exit();
}

// ─── AJAX HANDLERS (like / repost / favorite) ─────────────────────────────────
foreach (['ajax_like'=>'likes','ajax_repost'=>'reposts','ajax_favorite'=>'favorites'] as $action=>$table) {
    if (isset($_POST[$action])) {
        header('Content-Type: application/json');

        if ($is_paused && !$is_owner && !$is_admin) {
            echo json_encode(['error' => 'This pod is paused.']); exit();
        }

        $meme_id = (int)($_POST['meme_id'] ?? 0);
        if (!$meme_id) { echo json_encode(['error'=>'Invalid']); exit(); }

        $key = $action === 'ajax_like' ? 'liked' : ($action === 'ajax_repost' ? 'reposted' : 'favorited');

        $stmt = $conn->prepare("SELECT 1 FROM $table WHERE user_id=? AND meme_id=?");
        $stmt->bind_param("ii", $current_user, $meme_id);
        $stmt->execute();
        $check = $stmt->get_result();
        $stmt->close();

        if ($check && $check->num_rows > 0) {
            $stmt = $conn->prepare("DELETE FROM $table WHERE user_id=? AND meme_id=?");
            $stmt->bind_param("ii", $current_user, $meme_id);
            $stmt->execute();
            $stmt->close();
            $val = false;
        } else {
            $stmt = $conn->prepare("INSERT INTO $table (user_id,meme_id) VALUES (?,?)");
            $stmt->bind_param("ii", $current_user, $meme_id);
            $stmt->execute();
            $stmt->close();
            $val = true;
        }

        $cnt_sql = "SELECT COUNT(*) as c FROM $table WHERE meme_id=?";
        if (!empty($viewer_excluded_sql)) $cnt_sql .= " AND user_id NOT IN ($viewer_excluded_sql)";
        $stmt = $conn->prepare($cnt_sql);
        $stmt->bind_param("i", $meme_id);
        $stmt->execute();
        $cnt = $stmt->get_result()->fetch_assoc()['c'] ?? 0;
        $stmt->close();

        echo json_encode([$key=>$val,'count'=>$cnt]); exit();
    }
}

// ─── REPORT POST TO GROUP ADMINS ──────────────────────────────────────────────
if (isset($_POST['report_to_admins'])) {
    header('Content-Type: application/json');

    if (!$is_member) {
        echo json_encode(['success' => false, 'msg' => 'You must be a member of this pod to report a post.']);
        exit();
    }

    $rpt_meme_id   = (int)($_POST['meme_id'] ?? 0);
    $rpt_target_id = (int)($_POST['reported_user_id'] ?? 0);
    $rpt_reason    = trim((string)($_POST['reason'] ?? ''));
    $rpt_desc      = trim((string)($_POST['description'] ?? ''));

    if (!$rpt_meme_id || !$rpt_reason) {
        echo json_encode(['success' => false, 'msg' => 'Missing report details.']);
        exit();
    }

    // Confirm the meme actually belongs to a post in THIS group, and grab its group_post id.
    $stmt = $conn->prepare("SELECT id, posted_by FROM group_posts WHERE meme_id=? AND group_id=?");
    $stmt->bind_param("ii", $rpt_meme_id, $group_id);
    $stmt->execute();
    $gp_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$gp_row) {
        echo json_encode(['success' => false, 'msg' => 'This post no longer exists in this pod.']);
        exit();
    }

    $rpt_group_post_id = (int)$gp_row['id'];
    // Trust the DB's posted_by over the client-supplied reported_user_id, in case they diverge.
    if (!$rpt_target_id) $rpt_target_id = (int)$gp_row['posted_by'];

    // One pending report per (reporter, post) — avoid spam-reporting the same post repeatedly.
    $stmt = $conn->prepare("
        SELECT 1 FROM group_post_reports
        WHERE group_post_id=? AND reported_by=? AND status='pending'
    ");
    $stmt->bind_param("ii", $rpt_group_post_id, $current_user);
    $stmt->execute();
    $already = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    if ($already) {
        echo json_encode(['success' => true, 'msg' => 'already_reported']);
        exit();
    }

    $stmt = $conn->prepare("
        INSERT INTO group_post_reports
            (group_id, group_post_id, meme_id, reported_by, reported_user_id, reason, description)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        "iiiiiss",
        $group_id,
        $rpt_group_post_id,
        $rpt_meme_id,
        $current_user,
        $rpt_target_id,
        $rpt_reason,
        $rpt_desc
    );
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => (bool)$ok]);
    exit();
}

/* ── GROUP-SCOPED SEARCH AUTOCOMPLETE AJAX ── */
if (isset($_GET['gsearch_type']) && $_GET['gsearch_type'] === 'autocomplete') {
    header('Content-Type: application/json');

    if ($is_private_group && !$is_member && !$is_owner && !$is_admin) {
        echo json_encode([]);
        exit;
    }

    $gq = trim($_GET['q'] ?? '');
    $gresults = [];

    if (strlen($gq) >= 1) {
        $glike = "%$gq%";

        $gm_sql = "
            SELECT DISTINCT u.id, u.username, u.nickname, u.profile_pic
            FROM group_members gm
            JOIN users u ON u.id = gm.user_id
            WHERE gm.group_id = ?
              AND (u.username LIKE ? OR (u.nickname IS NOT NULL AND u.nickname LIKE ?))
        ";
        if (!empty($blocked_sql))         $gm_sql .= " AND u.id NOT IN ($blocked_sql)";
        if (!empty($group_banned_sql))    $gm_sql .= " AND u.id NOT IN ($group_banned_sql)";
        if (!empty($viewer_excluded_sql)) $gm_sql .= " AND u.id NOT IN ($viewer_excluded_sql)";
        $gm_sql .= " LIMIT 6";

        $stmt = $conn->prepare($gm_sql);
        $stmt->bind_param("iss", $group_id, $glike, $glike);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $gresults[] = [
                'type'        => 'member',
                'id'          => (int)$row['id'],
                'username'    => $row['username'],
                'nickname'    => $row['nickname'] ?? '',
                'profile_pic' => $row['profile_pic'] ?? '',
            ];
        }
        $stmt->close();

        $gp_sql = "
            SELECT gp.id AS group_post_id, m.id AS meme_id, m.file_name, m.caption, m.type,
                   u.id AS uid, u.username, u.profile_pic
            FROM group_posts gp
            JOIN memes m ON m.id = gp.meme_id
            JOIN users u ON u.id = gp.posted_by
            WHERE gp.group_id = ? AND m.caption LIKE ?
        ";
        if (!empty($blocked_sql))         $gp_sql .= " AND u.id NOT IN ($blocked_sql)";
        if (!empty($group_banned_sql))    $gp_sql .= " AND u.id NOT IN ($group_banned_sql)";
        if (!empty($viewer_excluded_sql)) $gp_sql .= " AND u.id NOT IN ($viewer_excluded_sql)";
        $gp_sql .= " ORDER BY gp.created_at DESC LIMIT 6";

        $stmt2 = $conn->prepare($gp_sql);
        $stmt2->bind_param("is", $group_id, $glike);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        while ($row = $res2->fetch_assoc()) {
            $gresults[] = [
                'type'           => 'gpost',
                'group_post_id'  => (int)$row['group_post_id'],
                'meme_id'        => (int)$row['meme_id'],
                'file_name'      => $row['file_name'],
                'meme_type'      => $row['type'],
                'caption'        => $row['caption'],
                'username'       => $row['username'],
                'profile_pic'    => $row['profile_pic'] ?? '',
            ];
        }
        $stmt2->close();
    }

    echo json_encode($gresults);
    exit;
}

/* ── GROUP SEARCH RECENTS — GET / ADD / REMOVE / CLEAR ── */
if (isset($_GET['gsearch_recents']) && $_GET['gsearch_recents'] === 'get') {
    header('Content-Type: application/json');
    $out = [];
    $stmt = $conn->prepare("SELECT item_data FROM group_search_recents WHERE user_id=? AND group_id=? ORDER BY created_at DESC LIMIT 30");
    $stmt->bind_param("ii", $current_user, $group_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $decoded = json_decode($row['item_data'], true);
        if ($decoded) $out[] = $decoded;
    }
    $stmt->close();
    echo json_encode($out);
    exit;
}

if (isset($_GET['gsearch_recents']) && $_GET['gsearch_recents'] === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $body = json_decode(file_get_contents('php://input'), true);
    if ($body && !empty($body['type']) && isset($body['id'])) {
        $itype = $body['type'];
        $iid   = (string)$body['id'];
        $idata = json_encode($body);
        $stmt = $conn->prepare("
            INSERT INTO group_search_recents (user_id, group_id, item_type, item_id, item_data)
            VALUES (?,?,?,?,?)
            ON DUPLICATE KEY UPDATE item_data=VALUES(item_data), created_at=CURRENT_TIMESTAMP
        ");
        $stmt->bind_param("iisss", $current_user, $group_id, $itype, $iid, $idata);
        $stmt->execute();
        $stmt->close();
    }
    echo json_encode(['ok' => true]);
    exit;
}

if (isset($_GET['gsearch_recents']) && $_GET['gsearch_recents'] === 'remove' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $body = json_decode(file_get_contents('php://input'), true);
    if ($body && !empty($body['type']) && isset($body['id'])) {
        $stmt = $conn->prepare("DELETE FROM group_search_recents WHERE user_id=? AND group_id=? AND item_type=? AND item_id=?");
        $itype = $body['type'];
        $iid   = (string)$body['id'];
        $stmt->bind_param("iiss", $current_user, $group_id, $itype, $iid);
        $stmt->execute();
        $stmt->close();
    }
    echo json_encode(['ok' => true]);
    exit;
}

if (isset($_GET['gsearch_recents']) && $_GET['gsearch_recents'] === 'clear' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $stmt = $conn->prepare("DELETE FROM group_search_recents WHERE user_id=? AND group_id=?");
    $stmt->bind_param("ii", $current_user, $group_id);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['ok' => true]);
    exit;
}

// ─── TOGGLE FOLLOW AJAX ───────────────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'toggle_follow') {
    header('Content-Type: application/json');
    $target_id = (int)($_POST['user_id'] ?? 0);
    if (!$current_user || !$target_id || $target_id === $current_user) {
        echo json_encode(['status' => 'error', 'message' => 'invalid']);
        exit();
    }
    $stmt = $conn->prepare("SELECT id FROM followers WHERE follower_id=? AND following_id=?");
    $stmt->bind_param("ii", $current_user, $target_id);
    $stmt->execute();
    $check = $stmt->get_result();
    $stmt->close();

    if ($check && $check->num_rows > 0) {
        $stmt = $conn->prepare("DELETE FROM followers WHERE follower_id=? AND following_id=?");
        $stmt->bind_param("ii", $current_user, $target_id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['status' => 'ok', 'following' => false]);
    } else {
        $stmt = $conn->prepare("INSERT INTO followers (follower_id, following_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $current_user, $target_id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['status' => 'ok', 'following' => true]);
    }
    exit();
}

// ─── POST UPLOAD ──────────────────────────────────────────────────────────────
if (isset($_POST['upload_post'])) {
    if (!$is_member) die("Join first.");
    if ($is_paused && !$is_owner && !$is_admin) {
        header("Location: group.php?id=$group_id"); exit();
    }
    $caption = (string)($_POST['caption'] ?? '');
    if (strpos($caption, '#') === false) {
        $upload_error = "You must include at least one hashtag (#) in your caption.";
    } else {
        $file_name = ''; $type = 'text';
        if (!empty($_FILES['post_file']['name'])) {
            $ext = strtolower(pathinfo($_FILES['post_file']['name'], PATHINFO_EXTENSION));
            $allowed_ext = ['jpg','jpeg','png','gif','webp','mp4','webm','mov'];
            if (!in_array($ext, $allowed_ext, true)) {
                $upload_error = "Unsupported file type.";
            } else {
                $file_name = time() . '_' . uniqid() . '.' . $ext;
                $upload_path = "uploads/" . $file_name;
                if (!move_uploaded_file($_FILES['post_file']['tmp_name'], $upload_path)) {
                    $upload_error = "File upload failed. Check that the uploads/ folder exists and is writable.";
                    $file_name = '';
                } else {
                    $type = in_array($ext, ['mp4','webm','mov'], true) ? 'video' : 'image';
                }
            }
        }
        if (empty($upload_error)) {
            $is_adult_post = !empty($group['is_adult']) ? 1 : 0;

            $stmt = $conn->prepare("INSERT INTO memes (uploaded_by, file_name, caption, type, is_adult) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("isssi", $current_user, $file_name, $caption, $type, $is_adult_post);
            $stmt->execute();
            $meme_id = $conn->insert_id;
            $stmt->close();

            $stmt = $conn->prepare("INSERT INTO group_posts (group_id, posted_by, meme_id) VALUES (?, ?, ?)");
            $stmt->bind_param("iii", $group_id, $current_user, $meme_id);
            $stmt->execute();
            $stmt->close();

            header("Location: group.php?id=$group_id"); exit();
        }
    }
}

// ─── MEMBER COUNT ─────────────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM group_members WHERE group_id=?");
$stmt->bind_param("i", $group_id);
$stmt->execute();
$member_count = $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

// ─── Can the viewer see content? ───────────────────────────────────────────────
$can_view_content = (!$is_private_group) || $is_member || $is_owner || $is_admin;

// ─── Pending join requests (owner/admin panel) ────────────────────────────────
$pending_join_requests = [];
if ($is_private_group && ($is_owner || $is_admin)) {
    $stmt = $conn->prepare("
        SELECT jr.user_id, jr.requested_at, u.username, u.profile_pic
        FROM group_join_requests jr
        JOIN users u ON u.id = jr.user_id
        WHERE jr.group_id = ? AND jr.status = 'pending'
        ORDER BY jr.requested_at ASC
    ");
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $pr_res = $stmt->get_result();
    if ($pr_res) {
        while ($row = $pr_res->fetch_assoc()) $pending_join_requests[] = $row;
    }
    $stmt->close();
}
$pending_request_count = count($pending_join_requests);

// ─── MEMBERS LIST ─────────────────────────────────────────────────────────────
$search_query = (string)($_GET['search'] ?? '');

$members_sql = "SELECT DISTINCT u.id, u.username, u.profile_pic FROM group_members gm JOIN users u ON u.id=gm.user_id WHERE gm.group_id=?";
$members_types = "i";
$members_params = [$group_id];

if ($search_query !== '') {
    $members_sql .= " AND u.username LIKE ?";
    $members_types .= "s";
    $members_params[] = "%$search_query%";
}
if (!empty($blocked_sql))         $members_sql .= " AND u.id NOT IN ($blocked_sql)";
if (!empty($group_banned_sql))    $members_sql .= " AND u.id NOT IN ($group_banned_sql)";
if (!empty($viewer_excluded_sql)) $members_sql .= " AND u.id NOT IN ($viewer_excluded_sql)";

$members = false;
if ($can_view_content) {
    $stmt = $conn->prepare($members_sql);
    $stmt->bind_param($members_types, ...$members_params);
    $stmt->execute();
    $members = $stmt->get_result();
}

// ─── ADMINS LIST ──────────────────────────────────────────────────────────────
$admins = [];
$stmt = $conn->prepare("SELECT u.id, u.username, u.profile_pic FROM group_admins ga JOIN users u ON u.id=ga.user_id WHERE ga.group_id=?");
$stmt->bind_param("i", $group_id);
$stmt->execute();
$admin_res = $stmt->get_result();
while ($row = $admin_res->fetch_assoc()) $admins[$row['id']] = $row;
$stmt->close();

// ─── GROUP HASHTAGS ────────────────────────────────────────────────────────────
$group_hashtags = [];
$stmt = $conn->prepare("SELECT id, tag FROM group_hashtags WHERE group_id=? ORDER BY created_at ASC");
$stmt->bind_param("i", $group_id);
$stmt->execute();
$gh_res = $stmt->get_result();
while ($row = $gh_res->fetch_assoc()) { $group_hashtags[] = $row; }
$stmt->close();

// ─── POSTS QUERY ─────────────────────────────────────────────────────────────
$post_sql = "
SELECT gp.id AS group_post_id, gp.created_at AS post_created,
       u.id AS user_id, u.username, u.profile_pic,
       m.id AS meme_id, m.file_name, m.caption, m.type,
       (SELECT COUNT(*) FROM likes l     WHERE l.meme_id=m.id" . (!empty($viewer_excluded_sql) ? " AND l.user_id NOT IN ($viewer_excluded_sql)" : "") . ") AS like_count,
       (SELECT COUNT(*) FROM reposts r   WHERE r.meme_id=m.id" . (!empty($viewer_excluded_sql) ? " AND r.user_id NOT IN ($viewer_excluded_sql)" : "") . ") AS repost_count,
       (SELECT COUNT(*) FROM favorites f WHERE f.meme_id=m.id" . (!empty($viewer_excluded_sql) ? " AND f.user_id NOT IN ($viewer_excluded_sql)" : "") . ") AS favorites_count,
       (SELECT COUNT(*) FROM downloads d WHERE d.meme_id=m.id) AS download_count,
       (SELECT COUNT(*) FROM likes l     WHERE l.meme_id=m.id AND l.user_id=?) AS is_liked,
       (SELECT COUNT(*) FROM reposts r   WHERE r.meme_id=m.id AND r.user_id=?) AS is_reposted,
       (SELECT COUNT(*) FROM favorites f WHERE f.meme_id=m.id AND f.user_id=?) AS is_favorited,
       (SELECT COUNT(*) FROM followers f WHERE f.follower_id=? AND f.following_id=u.id) AS poster_is_followed
FROM group_posts gp
JOIN memes m ON m.id=gp.meme_id
JOIN users u ON u.id=gp.posted_by
WHERE gp.group_id=?
";
$post_types  = "iiiii";
$post_params = [$current_user, $current_user, $current_user, $current_user, $group_id];

if ($search_query !== '') {
    $post_sql .= " AND (m.caption LIKE ? OR u.username LIKE ?)";
    $post_types  .= "ss";
    $like = "%$search_query%";
    $post_params[] = $like;
    $post_params[] = $like;
}
if (!empty($blocked_sql))         $post_sql .= " AND u.id NOT IN ($blocked_sql)";
if (!empty($group_banned_sql))    $post_sql .= " AND u.id NOT IN ($group_banned_sql)";
if (!empty($viewer_excluded_sql)) $post_sql .= " AND u.id NOT IN ($viewer_excluded_sql)";
$post_sql .= " ORDER BY gp.created_at DESC";

$posts = false;
if ($can_view_content) {
    $stmt = $conn->prepare($post_sql);
    if (!$stmt) die("Posts query failed: " . $conn->error);
    $stmt->bind_param($post_types, ...$post_params);
    $stmt->execute();
    $posts = $stmt->get_result();
    if (!$posts) die("Posts query failed: " . $conn->error);
}

$stmt = $conn->prepare("SELECT 1 FROM blocks WHERE blocker_id=? AND blocked_id=?");
$stmt->bind_param("ii", $group_creator_id, $current_user);
$stmt->execute();
$blocked_by_creator = $stmt->get_result()->num_rows > 0;
$stmt->close();

// ─── 18+ CHECK ────────────────────────────────────────────────────────────────
$is_adult_group = !empty($group['is_adult']);
$show_age_gate  = $is_adult_group && !$is_member;

// ─── IMAGE PATH HELPERS ───────────────────────────────────────────────────────
$group_pic  = $group['group_pic'] ?? '';
$img_src    = empty($group_pic)
    ? 'uploads/default_group.png'
    : (strpos($group_pic, 'uploads/') === 0 ? $group_pic : 'uploads/' . $group_pic);

$banner_raw = $group['banner_pic'] ?? '';
$banner_src = !empty($banner_raw)
    ? (strpos($banner_raw, 'uploads/') === 0 ? $banner_raw : 'uploads/' . $banner_raw)
    : $img_src;
?>
<?php
// ── Total unread messages across all linked accounts ──
$total_unread = 0;
if (!empty($_SESSION['accounts'])) {
    foreach ($_SESSION['accounts'] as $acc_id) {
        $acc_id = (int)$acc_id;
        $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM messages WHERE receiver_id=? AND seen=0");
        $stmt->bind_param("i", $acc_id);
        $stmt->execute();
        $unread = $stmt->get_result()->fetch_assoc()['cnt'] ?? 0;
        $stmt->close();
        $total_unread += $unread;
    }
}
?>
<!DOCTYPE html>
<html>
<head>

<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($group['title']); ?> - Dezzme Pods</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<script>
/* ══════════════════════════════════════════════════════════
   CSRF TOKEN — global constant used by every POST form/fetch
   on this page. $csrf_token must exist in your PHP scope
   (session-based, generated once near the top of group.php).
   ══════════════════════════════════════════════════════════ */
const CSRF_TOKEN = <?php echo json_encode($csrf_token); ?>;

/* Auto-inject a hidden csrf_token field into every POST form
   on the page, so you don't have to hand-edit each <form> tag.
   Runs once the DOM is ready; catches forms present at load
   time. Forms created dynamically in JS (e.g. goLeaveGroup())
   still need the field added manually where they're built. */
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
html, body {
    margin: 0;
    padding: 0;
    width: 100%;
    overflow-x: hidden;
}

/* Increased global spacing */
body {
    padding: 0;           /* Overall padding around the page */
    margin: 0;
    min-height: 100vh;
}

/* Even better responsive version */
@media (max-width: 780px) {
    body {
        padding: 5px;
    }
}

@media (max-width: 490px) {
    body {
        padding: 4.5px;
    }
}

@media (max-width: 380px) {
    body {
        padding: 4.5px;
    }
}

*, *::before, *::after {
    box-sizing: border-box;
}

.main {
    overflow-x: hidden;
}


html { scroll-behavior: smooth; }

body {
    background: linear-gradient(-45deg,#010218,#070924,#070924);
    background-attachment: fixed;
    color: var(--text);
    font-family: 'Poppins', sans-serif;
    min-height: 100vh;
    overflow-x: hidden;
}

/* ─── SIDEBAR ─────────────────────────────────────────────── */
.sidebar {
    position: fixed; left:0; top:0;
    width:100px; height:100vh;
    display:flex; flex-direction:column;
    gap:32px; padding:20px;
    border-radius:15px;
    background:transparent;
    backdrop-filter:blur(15px);
    box-shadow:0 0 40px rgba(0,0,0,0.6);
    z-index:100;
}
.sidebar .logo img {
    width:55px; height:55px; border-radius:15px;
    transition:.3s;
    animation:floatUpDown 2s ease-in-out infinite;
}
.sidebar .logo img:hover { box-shadow:0 0 15px white; transform:scale(1.1); }
@keyframes floatUpDown { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-5px)} }

.nav-icon {
    width:50px; height:30px;
    display:flex; align-items:center; justify-content:center;
    border-radius:15px; background:transparent;
    color:white; font-size:22px;
    text-decoration:none;
    transition:all 0.3s ease;
    cursor:pointer; position:relative; overflow:hidden;
    -webkit-tap-highlight-color:transparent;
}
.nav-icon:hover { transform:scale(1.12); box-shadow:0 0 15px rgba(255,255,255,0.6); }
.nav-icon::before {
    content:""; position:absolute;
    top:0; left:-90%; width:50%; height:100%;
    background:linear-gradient(120deg,transparent,rgba(255,255,255,0.25),transparent);
    transform:skewX(-20deg); transition:0.6s; border-radius:15px;
}
.nav-icon:hover::before { left:120%; }

/* ─── MAIN ────────────────────────────────────────────────── */
.main { margin-left:100px; padding:10px; }



/* ─── 18+ BADGE ───────────────────────────────────────────── */
.adult-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    border: 1.5px solid rgba(255, 45, 45, 0.7);
    border-radius: 10px;
    padding: 3px 12px 3px 8px;
    font-size: 12px;
    font-weight: 700;
    color: #FF2D2D;
    letter-spacing: 0.5px;
    margin-top: 6px;
    width: fit-content;
    animation: adultPulse 2.5s ease-in-out infinite;
}

@media (max-width: 480px) {
    .adult-badge {
        font-size: 10.5px;
        padding: 2px 9px 2px 6px;
        gap: 4px;
        border-radius: 10px;
        margin-top: 4px;
        letter-spacing: 0.3px;
    }
}

@media (max-width: 360px) {
    .adult-badge {
        font-size: 10px;
        padding: 1px 5px 1px 4px;
        gap: 3px;
        border-radius: 9px;
        margin-top: 4px;
        letter-spacing: 0.2px;
    }
}

/* ─── RED BORDER on all profile pics ────────────────────── */
img.profile-pic,
.member-card img,
.bottom-info img,
#modalText img,
#ccm-pic,
.creator-info img {
    border: 2px solid #FF2D2D !important;
}



/* ─── MEMBERS CAROUSEL ────────────────────────────────────── */
.members-carousel {
    display:flex; gap:clamp(12px,3vw,20px);
    overflow-x:auto; padding:10px 5px 20px 5px;
    scrollbar-width:none; -webkit-overflow-scrolling:touch;
    position: relative;
    z-index: 0;
}
.members-carousel::-webkit-scrollbar { display:none; }

.member-card {
    text-align:center; cursor:pointer;
    transition:0.3s;
    min-width:clamp(60px,15vw,80px); flex-shrink:0;
    position:relative;
    z-index: 0;
}
.member-card:hover { transform:translateY(-8px); }
.member-card img {
    width:clamp(42px,10vw,55px); height:clamp(42px,10vw,55px);
    border-radius:50%; object-fit:cover;
    padding:3px; background:rgba(0,0,0,0.3);
    transition:0.3s; display:block; margin:0 auto;
}
.member-card:hover img { box-shadow:0 0 15px var(--red); }
.member-card div {
    font-size:clamp(9px,2.2vw,11px); margin-top:8px;
    font-weight:500; color:rgba(255,255,255,0.8);
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    max-width:clamp(60px,15vw,80px);
}

.post-form {
    display: flex; gap: 10px; align-items: center; flex-wrap: wrap;
}

.custom-file-upload {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 10px 16px; border-radius: 20px;
    background: rgba(255,255,255,0.08);
    color: white; cursor: pointer; transition: 0.3s;
    font-size: 13px; font-weight: 500;
    border: 1px solid rgba(255,255,255,0.12);
    -webkit-tap-highlight-color: transparent;
    white-space: nowrap;
}
.custom-file-upload:hover {
    background: rgba(255,255,255,0.15);
    box-shadow: 0 0 12px rgba(255,255,255,0.3);
    transform: scale(1.03);
}
.custom-file-upload input { display: none; }

.file-name-display {
    font-size: 11px; color: rgba(255,255,255,0.5);
    margin-top: 4px; text-align: center;
}

.caption-wrap { flex: 1; min-width: 0; position: relative; }
.caption-wrap input[type="text"] {
    width: 100%; padding: 11px 16px;
    border-radius: 18px; border: 1px solid rgba(255,255,255,0.12);
    background: rgba(255,255,255,0.08);
    color: white; font-size: 14px; outline: none;
    transition: all 0.3s ease;
    font-family: 'Poppins', sans-serif;
}
.caption-wrap input[type="text"]::placeholder { color: rgba(255,255,255,0.45); }
.caption-wrap input[type="text"]:focus {
    border-color: rgba(255,76,76,0.85);
    box-shadow: 0 0 10px rgba(255,76,76,0.3);
    background: rgba(255,255,255,0.11);
}

.hashtag-hint {
    font-size: 11px; color: rgba(255,255,255,0.38);
    margin-top: 5px; padding-left: 6px;
}
.hashtag-hint.valid   { color: #4cff91; }
.hashtag-hint.invalid { color: #ff6b6b; }

.upload-btn {
    width: 46px; height: 46px; border-radius: 14px;
    border: none; background: rgba(255,45,45,0.75);
    color: white; cursor: pointer; transition: all 0.3s ease;
    flex-shrink: 0; display: flex; align-items: center;
    justify-content: center; font-size: 18px;
    -webkit-tap-highlight-color: transparent;
}
.upload-btn:hover {
    background: rgba(255,45,45,1);
    transform: scale(1.08);
    box-shadow: 0 0 16px rgba(255,45,45,0.5);
}

/* ─── POSTS VERTICAL LIST ─────────────────────────────────── */
.posts-list {
    display:grid;
    grid-template-columns: repeat(3, 1fr);
    gap:14px;
    margin-top:10px;
}

/* ─── POST CARD ───────────────────────────────────────────── */
.post-card {
    background:transparent;
    backdrop-filter:blur(15px);
    border:1px solid rgba(255,255,255,0.08);
    border-radius:20px;
    overflow:hidden;
    transition:all 0.3s ease;
    cursor:pointer;
    position:relative;
}
.post-card:hover { transform:translateY(-3px); box-shadow:0 12px 30px rgba(0,0,0,0.4); border-color:rgba(255,255,255,0.14); }

.post-card-header {
    display:flex; align-items:center; gap:10px;
    padding:12px 14px 10px;
}
.post-card-header img {
    width:36px; height:36px; border-radius:50%;
    object-fit:cover; flex-shrink:0;
    border:2px solid #FF2D2D;
}
.post-card-header .uname {
    font-size:13px; font-weight:600; color:#ff6b6b;
    cursor:pointer; text-decoration:none;
    display:flex; align-items:center; gap:5px;
}
.post-card-header .uname:hover { text-decoration:underline; }
.post-card-header .ts { font-size:11px; color:rgba(255,255,255,0.35); margin-left:auto; }

.post-media {
    width:100%; max-height:160px; object-fit:cover;
    display:block; background:#000;
}
video.post-media { max-height:160px; object-fit:cover; }
.post-caption {
    padding:10px 14px 4px;
    font-size:13px; color:rgba(255,255,255,0.75);
    line-height:1.5; word-break:break-word;
}
.post-caption .hashtag { color:#4dabff; }

/* ─── ENGAGEMENT ROW ──────────────────────────────────────── */
.engagement-row {
    display:flex; align-items:center; gap:6px;
    padding:10px 14px 12px;
}
.eng-btn {
    display:flex; align-items:center; gap:5px;
    padding:6px 12px; border-radius:14px;
    border:none; background:rgba(255,255,255,0.06);
    color:rgba(255,255,255,0.75); font-size:13px;
    cursor:pointer; transition:all 0.25s;
    font-family:'Poppins',sans-serif;
    -webkit-tap-highlight-color:transparent;
}
.eng-btn:hover { background:rgba(255,255,255,0.12); color:#fff; transform:scale(1.05); }
.eng-btn.liked   { color:#FF3B3B; }
.eng-btn.reposted{ color:#3bbaff; }
.eng-btn.saved   { color:#FFD700; }

.eng-btn-share {
    margin-left:auto;
    display:flex; align-items:center; gap:5px;
    padding:6px 12px; border-radius:14px;
    border:none; background:rgba(255,255,255,0.06);
    color:rgba(255,255,255,0.65); font-size:13px;
    cursor:pointer; transition:all 0.25s;
    -webkit-tap-highlight-color:transparent;
}
.eng-btn-share:hover { background:rgba(255,255,255,0.12); color:#fff; transform:scale(1.05); }

.mod-delete-btn {
    margin-left:4px;
    padding:6px 12px; border-radius:14px;
    border:none; background:rgba(255,45,45,0.15);
    color:#ff7a7a; font-size:12px;
    cursor:pointer; transition:all 0.25s;
    font-family:'Poppins',sans-serif;
    -webkit-tap-highlight-color:transparent;
}
.mod-delete-btn:hover { background:rgba(255,45,45,0.35); color:#fff; }

/* ─── MODAL ───────────────────────────────────────────────── */
#cardCaptionModal {
    display:none;
    position:fixed; inset:0;
    z-index:9999999;
    background:rgba(0,0,0,0.88);
    align-items:center; justify-content:center;
    padding:20px;
}
#cardCaptionModal.open { display:flex; }

.ccm-inner {
    background:rgba(21,20,20,0.92);
    border-radius:20px;
    width:100%; max-width:460px;
    overflow:hidden;
    border:1px solid rgba(255,255,255,0.08);
    box-shadow:0 30px 80px rgba(0,0,0,0.7);
    animation:modalIn 0.25s ease;
}
@keyframes modalIn { from{opacity:0;transform:scale(0.94)} to{opacity:1;transform:scale(1)} }

.ccm-header {
    display:flex; align-items:center; gap:10px;
    padding:14px 16px;
    border-bottom:1px solid rgba(255,255,255,0.07);
}
.ccm-header img {
    width:40px; height:40px; border-radius:50%;
    border:2px solid #FF2D2D; object-fit:cover;
    cursor:pointer; flex-shrink:0;
}
.ccm-username {
    color:#FF4C4C; font-weight:700; font-size:14px;
    cursor:pointer; display:flex; align-items:center; gap:5px;
}
.ccm-username:hover { text-decoration:underline; }
.ccm-close {
    margin-left:auto; background:none; border:none;
    color:rgba(255,255,255,0.5); font-size:22px;
    cursor:pointer; padding:4px; transition:0.2s;
    -webkit-tap-highlight-color:transparent;
}
.ccm-close:hover { color:#fff; transform:scale(1.15); }
#ccm-media { width:100%; max-height:58vh; overflow:hidden; background:#000; }
#ccm-media img,#ccm-media video { width:100%; max-height:58vh; object-fit:contain; display:block; }
#ccm-caption {
    padding:14px 16px;
    color:rgba(255,255,255,0.75); font-size:13px;
    line-height:1.6; word-break:break-word;
    white-space:pre-wrap; max-height:160px; overflow-y:auto;
    border-top:1px solid rgba(255,255,255,0.06);
}
#ccm-caption .hashtag { color:#4dabff; }
.ccm-footer {
    padding:12px 16px;
    border-top:1px solid rgba(255,255,255,0.06);
}
.ccm-footer button {
    width:100%; padding:10px;
    background:rgba(255,255,255,0.05);
    border:1px solid rgba(255,255,255,0.08);
    border-radius:12px; color:white;
    cursor:pointer; font-size:14px;
    font-family:'Poppins',sans-serif;
    transition:0.2s;
}
.ccm-footer button:hover { background:rgba(255,255,255,0.1); }


/* ─── KEBAB MENU ──────────────────────────────────────────── */
.kebab-wrap {
    position: relative;
    display: inline-block;
}

.kebab-btn {
    border: none;
    background: transparent;
    border-radius: 50%;
    width: 36px; height: 36px;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; color: rgba(255,255,255,0.75);
    font-size: 18px; transition: 0.25s;
    -webkit-tap-highlight-color: transparent;
    flex-shrink: 0;
}
.kebab-btn:hover {
    background: rgba(255,255,255,0.1);
    color: #fff;
    transform: scale(1.08);
}

/* ─── TABLET ───────────────────────────────────────── */
@media (max-width: 768px) {
    .kebab-btn { width: 34px; height: 34px; font-size: 17px; }
}

/* ─── MOBILE ───────────────────────────────────────── */
@media (max-width: 480px) {
    .kebab-btn { width: 32px; height: 32px; font-size: 16px; }
}

/* ─── SMALL MOBILE ─────────────────────────────────── */
@media (max-width: 360px) {
    .kebab-btn { width: 30px; height: 30px; font-size: 15px; }
}



/* ─── AGE GATE OVERLAY ────────────────────────────────────── */
#ageGateOverlay {
    display: none;
    position: fixed; inset: 0;
    z-index: 99999999;
    background: rgba(0,0,0,0.93);
    backdrop-filter: blur(18px);
    align-items: center; justify-content: center;
    padding: 20px;
    flex-direction: column;
    text-align: center;
}
#ageGateOverlay.open { display: flex; }

.age-gate-inner {
    background: rgba(18,10,10,0.97);
    border: 1.5px solid rgba(255,45,45,0.45);
    border-radius: 24px;
    padding: clamp(24px,6vw,44px) clamp(22px,6vw,44px);
    max-width: 420px; width: 100%;
    box-shadow: 0 0 60px rgba(255,45,45,0.25), 0 30px 70px rgba(0,0,0,0.7);
    animation: ageGateIn 0.3s ease;
}
@keyframes ageGateIn {
    from { opacity:0; transform:scale(0.92) translateY(20px); }
    to   { opacity:1; transform:scale(1) translateY(0); }
}
.age-gate-icon {
    font-size: clamp(42px,10vw,64px);
    margin-bottom: 14px;
    display: block;
}
.age-gate-title {
    font-size: clamp(18px,4.5vw,24px);
    font-weight: 700; color: #fff;
    margin: 0 0 8px 0;
    line-height: 1.2;
}
.age-gate-sub {
    font-size: clamp(12px,2.8vw,14px);
    color: rgba(255,255,255,0.6);
    line-height: 1.6;
    margin: 0 0 24px 0;
}
.age-gate-warning {
    background: rgba(255,45,45,0.12);
    border: 1px solid rgba(255,45,45,0.3);
    border-radius: 12px;
    padding: 10px 14px;
    font-size: 12px;
    color: #ff8a8a;
    margin-bottom: 22px;
    line-height: 1.5;
    display: flex; align-items: center; gap: 8px;
    text-align: left;
}
.age-gate-warning i { font-size: 16px; flex-shrink: 0; }
.age-gate-btns {
    display: flex; gap: 10px; flex-wrap: wrap;
    justify-content: center;
}
.age-gate-yes {
    flex: 1; min-width: 120px;
    padding: 13px 20px; border-radius: 50px;
    border: none;
    background: rgba(255,45,45,0.75);
    color: #fff; font-size: 14px; font-weight: 700;
    cursor: pointer; font-family: 'Poppins', sans-serif;
    transition: 0.25s; -webkit-tap-highlight-color: transparent;
}
.age-gate-yes:hover { background: rgba(255,45,45,1); transform: scale(1.04); box-shadow: 0 0 20px rgba(255,45,45,0.5); }
.age-gate-no {
    flex: 1; min-width: 120px;
    padding: 13px 20px; border-radius: 50px;
    border: 1px solid rgba(255,255,255,0.15);
    background: rgba(255,255,255,0.07);
    color: rgba(255,255,255,0.75); font-size: 14px; font-weight: 600;
    cursor: pointer; font-family: 'Poppins', sans-serif;
    transition: 0.25s; -webkit-tap-highlight-color: transparent;
}
.age-gate-no:hover { background: rgba(255,255,255,0.13); color: #fff; }

/* ─── TABLET ────────────────────────────────────────────────── */
@media (max-width: 768px) {
    .age-gate-inner {
        padding: 22px 20px;
        border-radius: 20px;
        max-width: 380px;
    }
    .age-gate-icon    { font-size: 48px; margin-bottom: 12px; }
    .age-gate-title   { font-size: 20px; margin-bottom: 7px; }
    .age-gate-sub     { font-size: 13px; margin-bottom: 18px; }
    .age-gate-warning { font-size: 11.5px; padding: 9px 12px; margin-bottom: 18px; border-radius: 10px; }
    .age-gate-warning i { font-size: 15px; }
    .age-gate-btns    { gap: 8px; }
    .age-gate-yes, .age-gate-no {
        padding: 11px 18px;
        font-size: 13px;
        min-width: 110px;
    }
}

/* ─── MOBILE ────────────────────────────────────────────────── */
@media (max-width: 480px) {
    .age-gate-inner {
        padding: 18px 16px;
        border-radius: 18px;
    }
    .age-gate-icon    { font-size: 40px; margin-bottom: 10px; }
    .age-gate-title   { font-size: 17px; margin-bottom: 6px; }
    .age-gate-sub     { font-size: 12px; margin-bottom: 15px; line-height: 1.5; }
    .age-gate-warning { font-size: 11px; padding: 8px 10px; margin-bottom: 15px; gap: 6px; border-radius: 9px; }
    .age-gate-warning i { font-size: 14px; }
    .age-gate-btns    { gap: 7px; }
    .age-gate-yes, .age-gate-no {
        padding: 10px 16px;
        font-size: 12.5px;
        min-width: 100px;
        border-radius: 40px;
    }
}

/* ─── SMALL MOBILE ──────────────────────────────────────────── */
@media (max-width: 360px) {
    .age-gate-inner {
        padding: 16px 14px;
        border-radius: 16px;
    }
    .age-gate-icon    { font-size: 34px; margin-bottom: 9px; }
    .age-gate-title   { font-size: 15px; margin-bottom: 5px; }
    .age-gate-sub     { font-size: 11.5px; margin-bottom: 13px; }
    .age-gate-warning { font-size: 10.5px; padding: 7px 9px; margin-bottom: 13px; gap: 5px; border-radius: 8px; }
    .age-gate-warning i { font-size: 13px; }
    .age-gate-btns    { gap: 6px; }
    .age-gate-yes, .age-gate-no {
        padding: 9px 14px;
        font-size: 12px;
        min-width: 90px;
        border-radius: 36px;
    }
}

/* ─── 320 ───────────────────────────────────────────────────── */
@media (max-width: 320px) {
    .age-gate-inner {
        padding: 14px 12px;
        border-radius: 14px;
    }
    .age-gate-icon    { font-size: 30px; margin-bottom: 8px; }
    .age-gate-title   { font-size: 14px; margin-bottom: 5px; }
    .age-gate-sub     { font-size: 11px; margin-bottom: 11px; }
    .age-gate-warning { font-size: 10px; padding: 6px 8px; margin-bottom: 11px; gap: 5px; border-radius: 7px; }
    .age-gate-warning i { font-size: 12px; }
    .age-gate-btns    { gap: 5px; }
    .age-gate-yes, .age-gate-no {
        padding: 8px 12px;
        font-size: 11.5px;
        min-width: 80px;
        border-radius: 32px;
    }
}

/* ─── PARTICLES ───────────────────────────────────────────── */
#particles { position:fixed; width:100%; height:100%; overflow:hidden; top:0; left:0; z-index:-1; }
.particle {
    position:absolute; background:white; border-radius:50%;
    pointer-events:none; box-shadow:0 0 10px rgba(255,255,255,0.6);
    animation:floatParticle linear infinite;
}
@keyframes floatParticle {
    0%  { transform:translate(0,0) scale(1); opacity:0; }
    10% { opacity:1; }
    50% { transform:translate(var(--x-move),var(--y-move)) scale(1.2); }
    100%{ transform:translate(calc(var(--x-move)*-1),calc(var(--y-move)*-1)) scale(0.8); opacity:0; }
}

/* ─── MOBILE ──────────────────────────────────────────────── */
@media (max-width:768px) {
    body { padding-bottom:70px; }
    .sidebar { display:none; }
    .sidebar .logo { display:none; }
    .nav-icon {
        flex:1; height:60px; width:auto;
        border-radius:0; background:transparent;
        box-shadow:none; font-size:clamp(18px,5vw,22px);
        border:none; backdrop-filter:none;
    }
    .nav-icon:hover { transform:none; background:rgba(255,255,255,0.05); box-shadow:none; }
    .nav-icon::before { display:none; }
    .nav-icon:active { transform:scale(0.88); opacity:0.6; }
    .group-header { flex-direction:column; align-items:flex-start; border-radius:22px; padding:clamp(14px,4vw,20px); gap:12px; }
    .group-header img.group-pic { width:clamp(72px,18vw,100px); height:clamp(72px,18vw,100px); border-radius:18px; }
    .action-row { gap:8px; }
    .post-form { flex-wrap:wrap; gap:8px; }
    .ccm-inner { max-width:100%; border-radius:16px; }
    #ccm-media { max-height:50vh; }
    #ccm-media img,#ccm-media video { max-height:50vh; }
    .search-wrapper { max-width:100%; }
    .member-card { min-width:clamp(52px,13vw,70px); }
    .member-card img { width:clamp(38px,9.5vw,50px); height:clamp(38px,9.5vw,50px); }
    .post-filters { gap:6px; }
    .filter-btn { padding:6px 12px; font-size:11px; }
    .owner-panel { padding:14px 14px; }
    .kebab-dropdown { right: -10px; }
}
@media (max-width:380px) {
    .group-header { padding:12px; border-radius:16px; }
    .group-header img.group-pic { width:64px; height:64px; border-radius:14px; }
    .nav-icon { font-size:18px; }
}


@media (max-width:768px) {
    .posts-list {
        grid-template-columns: repeat(1, 1fr);
        gap:10px;
    }
    .post-media {
        max-height:150px;
    }
    video.post-media { max-height:150px; }
}

@media (max-width:380px) {
    .posts-list {
        grid-template-columns: repeat(1, 1fr);
        gap:8px;
    }
}

/* ─── MOBILE ONLY — post grid & cards ─────────────────────── */
@media (max-width: 768px) {
    .members-carousel { padding: 8px 12px 16px; }
    .upload-section   { margin: 0 12px 18px; }
    .post-filters     { padding: 0 12px; gap: 5px; }
    .search-wrapper   { margin: 0 12px 16px; }
    h3 { padding: 0 12px; }
    .owner-panel { margin: 0 12px 18px; }
}

@media (max-width: 480px) {
    .members-carousel { padding: 6px 10px 14px; }
    .upload-section   { margin: 0 10px 16px; }
    .post-filters     { padding: 0 10px; }
    .search-wrapper   { margin: 0 10px 14px; }
    h3                { padding: 0 10px; }
    .owner-panel      { margin: 0 10px 16px; }
}

/* ─── UPLOAD SECTION ──────────────────────────────────────── */
.upload-section {
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 22px;
    padding: 20px 22px;
    margin: 0 0 26px 0;
    backdrop-filter: blur(14px);
}

.upload-section h3 {
    margin: 0 0 14px 0;
    font-size: 15px;
    font-weight: 600;
    color: #fff;
    display: flex;
    align-items: center;
    gap: 7px;
}

.post-form {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    flex-wrap: wrap;
}

.custom-file-upload {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 10px 16px;
    border-radius: 20px;
    background: rgba(255,255,255,0.08);
    backdrop-filter: blur(10px);
    color: white;
    cursor: pointer;
    transition: 0.3s;
    font-size: 13px;
    font-weight: 500;
    border: 1px solid rgba(255,255,255,0.12);
    white-space: nowrap;
    flex-shrink: 0;
}
.custom-file-upload:hover {
    background: rgba(255,255,255,0.15);
    box-shadow: 0 0 12px rgba(255,255,255,0.2);
    transform: scale(1.02);
}
.custom-file-upload input { display: none; }

.caption-wrap {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.caption-wrap input[type="text"] {
    width: 100%;
    padding: 11px 16px;
    border-radius: 18px;
    border: 1px solid rgba(255,255,255,0.12);
    background: rgba(255,255,255,0.08);
    color: white;
    font-size: 13px;
    outline: none;
    transition: all 0.3s ease;
    backdrop-filter: blur(12px);
    font-family: 'Poppins', sans-serif;
}
.caption-wrap input[type="text"]::placeholder { color: rgba(255,255,255,0.38); }
.caption-wrap input[type="text"]:focus {
    border-color: rgba(255,76,76,0.85);
    box-shadow: 0 0 10px rgba(255,76,76,0.25);
    background: rgba(255,255,255,0.11);
}

.hashtag-hint {
    font-size: 11px;
    color: rgba(255,255,255,0.35);
    padding-left: 4px;
    transition: color 0.25s;
}
.hashtag-hint.valid   { color: #4cff91; }
.hashtag-hint.invalid { color: #ff6b6b; }

.upload-btn {
    width: 46px;
    height: 46px;
    border-radius: 14px;
    border: none;
    background: rgba(255,45,45,0.75);
    color: white;
    cursor: pointer;
    transition: all 0.3s ease;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    align-self: flex-start;
    -webkit-tap-highlight-color: transparent;
}
.upload-btn:hover {
    background: rgba(255,45,45,1);
    transform: scale(1.08);
    box-shadow: 0 0 16px rgba(255,45,45,0.5);
}

.file-name-display {
    font-size: 11px;
    color: rgba(255,255,255,0.4);
    margin-top: 8px;
    padding-left: 4px;
}

.upload-error {
    background: rgba(255,45,45,0.12);
    border: 1px solid rgba(255,45,45,0.35);
    border-radius: 12px;
    padding: 10px 14px;
    color: #ff7a7a;
    font-size: 12px;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 7px;
}

@media (max-width: 768px) {
    .upload-section {
        margin: 0 12px 20px;
        padding: 16px 16px;
        border-radius: 18px;
    }
    .upload-section h3 {
        font-size: 13px;
        margin-bottom: 12px;
    }
    .post-form { gap: 8px; flex-wrap: wrap; }
    .custom-file-upload { font-size: 11.5px; padding: 8px 13px; border-radius: 16px; gap: 5px; }
    .caption-wrap input[type="text"] { font-size: 12px; padding: 9px 13px; border-radius: 14px; }
    .hashtag-hint { font-size: 10px; }
    .upload-btn { width: 40px; height: 40px; font-size: 16px; border-radius: 12px; }
    .file-name-display { font-size: 10px; }
}

@media (max-width: 480px) {
    .upload-section { margin: 0 10px 16px; padding: 13px 13px; border-radius: 16px; }
    .upload-section h3 { font-size: 12px; margin-bottom: 10px; }
    .post-form { gap: 7px; }
    .custom-file-upload { font-size: 11px; padding: 7px 11px; border-radius: 14px; gap: 4px; }
    .caption-wrap input[type="text"] { font-size: 11px; padding: 8px 11px; border-radius: 12px; }
    .hashtag-hint { font-size: 9.5px; }
    .upload-btn { width: 36px; height: 36px; font-size: 15px; border-radius: 10px; }
}

@media (max-width: 360px) {
    .upload-section { margin: 0 8px 14px; padding: 11px 11px; border-radius: 14px; }
    .upload-section h3 { font-size: 11px; }
    .custom-file-upload { font-size: 10px; padding: 6px 10px; border-radius: 12px; }
    .caption-wrap input[type="text"] { font-size: 10.5px; padding: 7px 10px; }
    .hashtag-hint { font-size: 9px; }
    .upload-btn { width: 33px; height: 33px; font-size: 14px; border-radius: 9px; }
}

/* ─── OWNER PANEL ─────────────────────────────────────────── */
.owner-panel {
    background: #030420;
    border: 1px solid #030420;
    border-radius: 22px;
    padding: 20px 22px;
    margin: 0 0 22px 0;
}
.owner-panel h4 {
    margin: 0 0 16px;
    font-size: 14px;
    font-weight: 600;
    color: rgba(255,255,255,0.85);
    display: flex;
    align-items: center;
    gap: 7px;
}
.owner-label {
    display: flex;
    align-items: center;
    gap: 7px;
    margin-bottom: 8px;
    font-size: 12px;
    color: rgba(255,255,255,0.5);
}
.owner-divider {
    border: none;
    border-top: 1px solid rgba(255,255,255,0.07);
    margin: 16px 0;
}
.owner-panel .row {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 10px;
    align-items: center;
}
.owner-input {
    flex: 1;
    min-width: 0;
    padding: 9px 14px;
    border-radius: 14px;
    border: 1px solid rgba(255,255,255,0.12);
    background: rgba(255,255,255,0.07);
    color: #fff;
    font-size: 13px;
    outline: none;
    font-family: 'Poppins', sans-serif;
    transition: 0.3s;
}
.owner-input:focus { border-color: rgba(255,76,76,0.7); box-shadow: 0 0 8px rgba(255,76,76,0.2); }
.owner-input::placeholder { color: rgba(255,255,255,0.3); }
.owner-btn {
    padding: 9px 18px;
    border-radius: 14px;
    border: none;
    background: rgba(255,255,255,0.08);
    color: #fff;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    font-family: 'Poppins', sans-serif;
    transition: 0.25s;
    white-space: nowrap;
    flex-shrink: 0;
    -webkit-tap-highlight-color: transparent;
}
.owner-btn:hover { background: rgba(255,255,255,0.16); transform: scale(1.03); }
.owner-btn.danger { background: rgba(255,45,45,0.2); color: #ff7a7a; border: 1px solid rgba(255,45,45,0.3); }
.owner-btn.danger:hover { background: rgba(255,45,45,0.45); color: #fff; }
.custom-file-label {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 9px 16px;
    border-radius: 14px;
    background: rgba(255,255,255,0.07);
    border: 1px solid rgba(255,255,255,0.12);
    color: #fff;
    font-size: 13px;
    cursor: pointer;
    transition: 0.25s;
    white-space: nowrap;
    flex-shrink: 0;
}
.custom-file-label:hover { background: rgba(255,255,255,0.14); }
.custom-file-label input { display: none; }

@media (max-width: 768px) {
    .owner-panel { margin: 0 12px 20px; padding: 16px 16px; border-radius: 18px; }
    .owner-panel h4 { font-size: 13px; margin-bottom: 13px; }
    .owner-label { font-size: 11px; margin-bottom: 7px; gap: 6px; }
    .owner-input { font-size: 12px; padding: 8px 12px; border-radius: 12px; }
    .owner-btn { font-size: 12px; padding: 8px 14px; border-radius: 12px; }
    .custom-file-label { font-size: 12px; padding: 8px 13px; border-radius: 12px; gap: 5px; }
    .admin-chip { font-size: 11px; padding: 4px 10px 4px 6px; gap: 6px; }
    .admin-chip img { width: 21px; height: 21px; }
    .owner-divider { margin: 13px 0; }
}

@media (max-width: 480px) {
    .owner-panel { margin: 0 10px 16px; padding: 13px 13px; border-radius: 16px; }
    .owner-panel h4 { font-size: 12px; margin-bottom: 11px; }
    .owner-label { font-size: 10.5px; }
    .owner-input { font-size: 11px; padding: 7px 11px; border-radius: 11px; }
    .owner-btn { font-size: 11px; padding: 7px 12px; border-radius: 11px; }
    .custom-file-label { font-size: 11px; padding: 7px 11px; border-radius: 11px; }
    .admin-chip { font-size: 10px; padding: 3px 9px 3px 5px; }
    .admin-chip img { width: 19px; height: 19px; }
    .owner-divider { margin: 11px 0; }
}

@media (max-width: 360px) {
    .owner-panel { margin: 0 8px 14px; padding: 11px 11px; border-radius: 14px; }
    .owner-panel h4 { font-size: 11px; }
    .owner-label { font-size: 10px; }
    .owner-input { font-size: 10.5px; padding: 6px 10px; border-radius: 10px; }
    .owner-btn { font-size: 10.5px; padding: 6px 10px; border-radius: 10px; }
    .custom-file-label { font-size: 10px; padding: 6px 10px; border-radius: 10px; }
    .admin-chip { font-size: 9.5px; }
    .admin-chip img { width: 17px; height: 17px; }
}

/* ─── SEARCH WRAPPER ──────────────────────────────────────── */
.search-wrapper {
    display: flex;
    align-items: center;
    gap: 10px;
    background: rgba(255,255,255,0.06);
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 12px;
    padding: 6px 10px 6px 18px;
    transition: all 0.3s ease;
    margin-bottom: 22px;
}
.search-wrapper:focus-within {
    border-color: rgba(255,45,45,0.7);
    box-shadow: 0 0 14px rgba(255,45,45,0.2);
    background: rgba(255,255,255,0.09);
}
.search-wrapper i { color: rgba(255,255,255,0.4); font-size: 17px; flex-shrink: 0; }
.search-wrapper input {
    flex: 1; border: none;
    background: transparent;
    color: #fff;
    font-family: 'Poppins', sans-serif;
    font-size: 13px;
    outline: none;
    padding: 6px 0;
    min-width: 0;
}
.search-wrapper input::placeholder { color: rgba(255,255,255,0.32); }
.search-wrapper button {
    border: none;
    border-radius: 12px;
    background: rgba(252,41,41,0.79);
    color: #fff;
    width: 60px;
    height: 34px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    cursor: pointer;
    transition: all 0.3s;
    flex-shrink: 0;
}
.search-wrapper button i { color: #fff; }
.search-wrapper button:hover { background: rgba(255,45,45,1); transform: scale(1.08); }

@media (max-width: 768px) {
    .search-wrapper { margin: 0 12px 18px; padding: 5px 8px 5px 14px; border-radius: 10px; }
    .search-wrapper i { font-size: 15px; }
    .search-wrapper input { font-size: 12px; }
    .search-wrapper button { width: 44px; height: 30px; font-size: 14px; border-radius: 6px; }
}

@media (max-width: 480px) {
    .search-wrapper { margin: 0 10px 16px; padding: 4px 7px 4px 13px; border-radius: 10px; }
    .search-wrapper input { font-size: 11px; }
    .search-wrapper button { width: 38px; height: 28px; font-size: 13px; border-radius: 6px; }
}

@media (max-width: 380px) {
    .search-wrapper { margin: 0 8px 14px; padding: 4px 6px 4px 12px; }
    .search-wrapper input { font-size: 10.5px; }
     .search-wrapper button { width: 34px; height: 26px; font-size: 13px; }
}


/* ── SCREEN TIME BREAK OVERLAY ── */
#breakOverlay {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 999999999;
    background: linear-gradient(135deg, #6c63ff 0%, #4e46cc 50%, #3a34b0 100%);
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 20px 30px;
    overflow: hidden;
    animation: breakFadeIn 0.5s ease forwards;
}

@keyframes breakFadeIn {
    from { opacity: 0; transform: scale(0.97); }
    to   { opacity: 1; transform: scale(1); }
}

#breakOverlay.active {
    display: flex;
}

.break-title {
    font-size: clamp(20px, 4.5vw, 34px);
    font-weight: 700;
    color: white;
    line-height: 1.2;
    margin-bottom: 6px;
    font-family: 'Poppins', sans-serif;
}

.break-subtitle {
    font-size: clamp(12px, 2.5vw, 15px);
    color: rgba(255,255,255,0.75);
    margin-bottom: 16px;
    font-family: 'Poppins', sans-serif;
}

.break-angel {
    font-size: clamp(55px, 12vw, 90px);
    margin-bottom: 16px;
    animation: breakFloat 3s ease-in-out infinite;
    filter: drop-shadow(0 8px 20px rgba(0,0,0,0.2));
    line-height: 1;
}

@keyframes breakFloat {
    0%, 100% { transform: translateY(0px); }
    50%       { transform: translateY(-10px); }
}

.break-code-label {
    font-size: clamp(12px, 2.5vw, 14px);
    color: rgba(255,255,255,0.6);
    margin-bottom: 10px;
    font-family: 'Poppins', sans-serif;
}

.break-code-label strong {
    color: white;
    font-size: clamp(15px, 3.5vw, 19px);
    display: block;
    margin-top: 3px;
    letter-spacing: 6px;
}

.break-input {
    width: clamp(150px, 45vw, 210px);
    padding: 11px 20px;
    border-radius: 50px;
    border: 2px solid rgba(255,255,255,0.3);
    background: rgba(255,255,255,0.12);
    color: white;
    font-size: 20px;
    letter-spacing: 8px;
    text-align: center;
    font-family: 'Poppins', sans-serif;
    outline: none;
    margin-bottom: 10px;
    transition: border 0.25s;
}

.break-input::placeholder {
    color: rgba(255,255,255,0.35);
    letter-spacing: 4px;
    font-size: 13px;
}

.break-input:focus {
    border-color: rgba(255,255,255,0.7);
}

.break-input.shake {
    animation: breakShake 0.35s ease;
    border-color: #ff4444;
}

@keyframes breakShake {
    0%,100% { transform: translateX(0); }
    20%      { transform: translateX(-8px); }
    40%      { transform: translateX(8px); }
    60%      { transform: translateX(-5px); }
    80%      { transform: translateX(5px); }
}

.break-return-btn {
    width: clamp(150px, 45vw, 210px);
    padding: 13px 0;
    border-radius: 50px;
    background: rgba(255,255,255,0.18);
    border: 2px solid rgba(255,255,255,0.3);
    color: white;
    font-size: clamp(13px, 3vw, 15px);
    font-weight: 600;
    cursor: pointer;
    font-family: 'Poppins', sans-serif;
    transition: background 0.25s, transform 0.2s;
    margin-bottom: 14px;
}

.break-return-btn:hover {
    background: rgba(255,255,255,0.28);
    transform: scale(1.03);
}

.break-return-btn:active {
    transform: scale(0.97);
}

.break-motivate {
    font-size: clamp(11px, 2.5vw, 13px);
    color: rgba(255,255,255,0.55);
    font-family: 'Poppins', sans-serif;
    font-style: italic;
    max-width: 260px;
    line-height: 1.4;
}

@media (max-height: 680px) {
    .break-angel { font-size: 45px; margin-bottom: 10px; }
    .break-title { font-size: 18px; }
    .break-subtitle { margin-bottom: 10px; font-size: 12px; }
    .break-code-label { margin-bottom: 8px; }
    .break-input { padding: 9px 16px; margin-bottom: 8px; }
    .break-return-btn { padding: 10px 0; margin-bottom: 10px; }
}


/* ============================================================
   GROUP HEADER — LinkedIn-style compact layout
   Breakpoints: 760 | 480 | 360 | 320
   ============================================================ */

/* === BASE === */
.gh {
    border-radius: 6px;
    overflow: visible;
    background: linear-gradient(-45deg,#010218,#070924,#070924);
    border: 1px solid #030420;
    margin-bottom: 20px;
}


/* === BODY === */
/* padding-top = avatar-overlap (28px) + breathing-room (8px) = 36px */
.gh-body {
    background: linear-gradient(-45deg,#010218,#070924,#070924);
    border-radius: 0 0 12px 12px;
    padding: 36px 18px 16px 18px;
}

/* === TITLE ROW === */
.gh-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 2px;
}

.gh-name {
    display: flex;
    align-items: center;
    gap: 7px;
    flex: 1;
    min-width: 0;
}

.gh-name h1 {
    font-size: 1.05rem;
    font-weight: 700;
    color: #f0f2ff;
    letter-spacing: -0.1px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    line-height: 1.3;
    margin: 0;
}

.gh-name svg {
    flex-shrink: 0;
}

/* === KEBAB BUTTON === */
.gh-kebab {
    background: rgba(255, 255, 255, 0.06);
    border: 1px solid rgba(255, 255, 255, 0.1);
    color: #999999;
    padding: 5px 8px;
    border-radius: 7px;
    cursor: pointer;
    font-size: 0.9rem;
    flex-shrink: 0;
    line-height: 1;
    transition: all 0.18s ease;
}

.gh-kebab:hover {
    background: rgba(255, 255, 255, 0.11);
    color: #ffffff;
}

/* === DESCRIPTION === */
.gh-desc {
    font-size: 0.77rem;
    color: #8892a4;
    line-height: 1.5;
    margin: 4px 0 8px;
    max-width: 500px;
    word-break: break-word;
}

/* === META ROW (creator + adult badge) === */
.gh-meta {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 10px;
    flex-wrap: wrap;
}

.gh-creator-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    text-decoration: none;
    transition: opacity 0.15s ease;
}

.gh-creator-link:hover {
    opacity: 0.8;
}

.gh-creator-avatar {
    width: 25px;
    height: 25px;
    border-radius: 50%;
    object-fit: cover;
    border: 1.5px solid #ff4c4c;
    flex-shrink: 0;
    display: block;
}

.gh-creator-text {
    font-size: 0.7rem;
    color: #7a8494;
    transform: translateY(4px);
}

.gh-creator-text strong {
    color: #c8cfdd;
    font-weight: 500;
}

/* === 18+ BADGE (inline beside creator) === */
.gh-adult {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: rgba(239, 68, 68, 0.12);
    color: #ed3232;
    border: 1px solid rgba(239, 68, 68, 0.25);
    padding: 2px 8px;
    border-radius: 20px;
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.3px;
    white-space: nowrap;
    transform: translateY(4px);
}

/* === ACTION ROW === */
.gh-actions {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 7px;
}

.gh-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 14px;
    border: none;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.18s ease;
    white-space: nowrap;
    letter-spacing: 0.1px;
}

.gh-btn-join {
    background: #045cc0;
    color: #ffffff;
}

.gh-btn-join:hover {
    background: #055cc0;
    transform: translateY(-1px);
}

.gh-btn-leave {
    background: rgba(255, 255, 255, 0.07);
    color: #bbc2cc;
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.gh-btn-leave:hover {
    background: rgba(255, 255, 255, 0.12);
}

.gh-btn-report {
    background: transparent;
    color: #666f7c;
    border: 1px solid rgba(255, 255, 255, 0.08);
}

.gh-btn-report:hover {
    color: #f87171;
    border-color: rgba(248, 113, 113, 0.28);
}

/* === MEMBER COUNT === */
.gh-stat {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 0.73rem;
    color: #606878;
    margin-left: 2px;
}

.gh-stat strong {
    color: #c0c7d4;
    font-weight: 600;
}




/* ============================================================
   ≤ 480px
   avatar: 52px | bottom: -20px | left: 12px
   body padding-top: 20+8 = 28px
   ============================================================ */
@media (max-width: 480px) {

    .gh-body { padding: 28px 12px 13px; }
    .gh-name h1 { font-size: 0.9rem; }
    .gh-desc { font-size: 0.65rem; margin: 3px 0 7px; }
    .gh-btn { padding: 5px 11px; font-size: 0.7rem; }
    .gh-adult { font-size: 0.58rem; padding: 2px 6px; }
    .gh-creator-text { font-size: 0.65rem; transform: translateY(4.1px); }
    .gh-stat { font-size: 0.69rem; }
}


/* ============================================================
   ≤ 360px
   avatar: 44px | bottom: -18px | left: 10px
   body padding-top: 18+6 = 24px
   ============================================================ */
@media (max-width: 380px) {
    .gh-banner { height: 100px; }

    .gh-avatar-wrap {
        width: 40px;
        height: 40px;
        bottom: -18px;
        left: 10px;
        border-width: 2px;
    }

    .gh-body { padding: 24px 10px 12px; }
    .gh-name h1 { font-size: 0.84rem; }
    .gh-desc { font-size: 0.62rem; margin: 3px 0 6px; }
    .gh-creator-text { font-size: 0.6rem; transform: translateY(5.1px); }
    .gh-adult { font-size: 0.5rem; padding: 2px 6px; }
    .gh-btn { padding: 5px 9px; font-size: 0.67rem; }
    .gh-stat { font-size: 0.65rem; }
}


/* ============================================================
   ≤ 320px — absolute floor
   avatar: 38px | bottom: -15px | left: 8px
   body padding-top: 15+5 = 20px
   ============================================================ */
@media (max-width: 320px) {
    .gh-banner { height: 88px; }

    .gh-avatar-wrap {
        width: 38px;
        height: 38px;
        bottom: -15px;
        left: 8px;
        border-width: 2px;
    }

    .gh-body { padding: 20px 8px 11px; }
    .gh-name h1 { font-size: 0.78rem; }
    .gh-desc { font-size: 0.6rem; }
    .gh-adult { font-size: 0.46rem; padding: 2px 6px; }
    .gh-btn { padding: 4px 8px; font-size: 0.63rem; }
    .gh-kebab { padding: 4px 6px; font-size: 0.8rem; }
}

.banner-container {
    position: relative;
    height: 250px;
    width: 100%;
}

.group-banner {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 0 0 0 0;
}

.banner-overlay {
    position: absolute;
    inset: 0;
    background: linear-gradient(to bottom, rgba(0,0,0,0.3), rgba(0,0,0,0.85));
    border-radius: 0 0 0 0;
}

.group-pic-container {
    position: absolute;
    bottom: -30px;
    left: 10px;
    width: 110px;
    height: 110px;
    border: 6px solid #070924;
    border-radius: 19%;
    overflow: hidden;
}

.group-pic {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

/* 760px */
@media (max-width: 780px) {
    .banner-container {
        height: 190px;
    }

    .group-pic-container {
        width: 100px;
        height: 100px;
        border-radius: 27%;
        bottom: -25px;
        left:8px;
        border-width: 5px;
    }
}

/* 480px */
@media (max-width: 480px) {
    .banner-container {
        height: 140px;
    }

    .group-pic-container {
        width: 75px;
        height: 75px;
        bottom: -22px;
        border-radius: 50%;
        left: 6px;
        border-width: 4px;
        box-shadow: 0 4px 14px rgba(0,0,0,0.35);
    }
}

/* 360px */
@media (max-width: 380px) {
    .banner-container {
        height: 125px;
    }

    .group-pic-container {
        width: 69px;
        height: 69px;
        bottom: -25px;
        left: 5px;
        border-radius: 50%;
        border-width: 4px;
        box-shadow: 0 3px 10px rgba(0,0,0,0.3);
    }
}

/* 320px */
@media (max-width: 330px) {
    .banner-container {
        height: 105px;
    }

    .group-pic-container {
        width: 55px;
        height: 55px;
        bottom: -18px;
        left: 6px;
        border-radius: 50%;
        border-width: 3px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.3);
    }
}

/* Group Pic Modal */
.group-pic-modal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 9999;
    background: rgba(0, 0, 0, 0.85);
    backdrop-filter: blur(6px);
    align-items: center;
    justify-content: center;
    animation: fadeIn 0.2s ease;
}

.group-pic-modal.active {
    display: flex;
}

.group-pic-modal-content {
    position: relative;
    max-width: 420px;
    width: 90%;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.6);
    animation: scaleIn 0.25s ease;
}

.group-pic-modal-content img {
    width: 100%;
    height: auto;
    display: block;
    object-fit: cover;
}

.group-pic-modal-close {
    position: absolute;
    top: 10px;
    right: 12px;
    background: rgba(0, 0, 0, 0.55);
    color: #fff;
    border: none;
    border-radius: 50%;
    width: 34px;
    height: 34px;
    font-size: 20px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
    transition: background 0.2s;
    z-index: 1;
}

.group-pic-modal-close:hover {
    background: rgba(255, 255, 255, 0.2);
}

@media (max-width: 760px) {
    .group-pic-modal-close {
        display: none;
    }
}

.group-pic-container {
    cursor: pointer;
    transition: opacity 0.2s;
}

.group-pic-container:hover {
    opacity: 0.85;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to   { opacity: 1; }
}

@keyframes scaleIn {
    from { transform: scale(0.85); opacity: 0; }
    to   { transform: scale(1);    opacity: 1; }
}

    /* ─── EDGE-TOUCHING FIX ──────────────────────────────────── */
@media (max-width: 768px) {
    .main {
        margin-left: 0 !important;
        padding: 0 0 80px 0 !important;   /* ← change this line */
        width: 100% !important;
        max-width: 100vw !important;
        box-sizing: border-box !important;
        overflow-x: hidden !important;
    }

    .gh,
    .owner-panel,
    .upload-section,
    .search-wrapper,
    .post-filters,
    .disc-link-row,
    .members-carousel,
    .group-feed,
    h3 {
        margin-left: 0 !important;
        margin-right: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
    }

    .group-feed {
        padding-left: 0 !important;
        padding-right: 0 !important;
    }

    .banner-container {
        border-radius: 0 !important;
        width: 100% !important;
    }
}

.banner-back-btn {
    display: none;
}

@media (max-width: 768px) {
    .banner-back-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    position: absolute;
    top: 8px;
    left: -2px;
    z-index: 10;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    border: none;
    background: transparent;
    backdrop-filter: none;
    box-shadow: none;
    color: #fff;
    font-size: 25px;
    cursor: pointer;
    transition: none;
    -webkit-tap-highlight-color: transparent;
    outline: none;
    appearance: none;
    -webkit-appearance: none;
}

.banner-back-btn:active,
.banner-back-btn:focus,
.banner-back-btn:hover {
    background: transparent;
    box-shadow: none;
    outline: none;
}

    .banner-back-btn:active {
        background: rgba(255, 255, 255, 0.15);
        transform: scale(0.92);
    }
}


/* ── BANNER STATS OVERLAY ── */
.banner-stats-overlay {
    position: absolute;
    bottom: 10px;
    left: 130px; /* sits right of the group pic */
    display: flex;
    align-items: center;
    gap: 14px;
    z-index: 5;
}

.bso-block {
    display: flex;
    flex-direction: column;
    gap: 1px;
}

.bso-num {
    font-size: 13px;
    font-weight: 700;
    color: rgba(255,255,255,0.72);
    font-family: 'Poppins', sans-serif;
    line-height: 1.1;
}

.bso-lbl {
    font-size: 10px;
    font-weight: 500;
    color: rgba(255,255,255,0.42);
    font-family: 'Poppins', sans-serif;
    white-space: nowrap;
}

.bso-divider {
    width: 1px;
    height: 28px;
    background: rgba(255,255,255,0.22);
    flex-shrink: 0;
}

/* ── 790px ── */
@media (max-width: 790px) {
    .banner-stats-overlay {
        left: 130px;
        bottom: 8px;
        gap: 11px;
    }
    .bso-num  { font-size: 12px; }
    .bso-lbl  { font-size: 9.5px; }
    .bso-divider { height: 24px; }
}

/* ── 490px ── */
@media (max-width: 490px) {
    .banner-stats-overlay {
        left: 90px;
        bottom: 7px;
        gap: 9px;
    }
    .bso-num  { font-size: 11px; }
    .bso-lbl  { font-size: 9px; }
    .bso-divider { height: 22px; }
}

/* ── 380px ── */
@media (max-width: 380px) {
    .banner-stats-overlay {
        left: 84px;
        bottom: 6px;   /* sit on the banner, not near the overhanging pic */
        gap: 7px;
    }
    .bso-num  { font-size: 10px; }
    .bso-lbl  { font-size: 8px; }
    .bso-divider { height: 18px; }
}

/* ── 330px ── */
@media (max-width: 330px) {
    .banner-stats-overlay {
        left: 70px;
        bottom: 6px;   /* same fix */
        gap: 6px;
    }
    .bso-num  { font-size: 9.5px; }
    .bso-lbl  { font-size: 7.5px; }
    .bso-divider { height: 16px; }
}
</style>
</head>
<body>



<!-- ═══════════════════════════════════════════════════════
     AGE GATE OVERLAY — full screen, premium dark-blue
═══════════════════════════════════════════════════════ -->
<?php if ($show_age_gate): ?>
<div id="ageGateOverlay" class="open">
    <div class="agv2-icon-wrap">
        <i class="ri-shield-line agv2-icon-shield"></i>
    </div>

    <h2 class="agv2-title">This group shares 18+ content</h2>

    <p class="agv2-sub">
        Their content may be inappropriate for people under 18.
        You must confirm you are <strong>18 years of age or older</strong>
        to view, join, or interact with this pod.
    </p>

    <div class="agv2-btns">
        <button class="agv2-btn agv2-btn-learn" onclick="openAgeLearnMore()">
            <i class="ri-information-line"></i> Learn more
        </button>
        <button class="agv2-btn agv2-btn-continue" onclick="confirmAge()">
            <i class="ri-checkbox-circle-line"></i> Continue
        </button>
        <button class="agv2-btn agv2-btn-back" onclick="history.back()">
            <i class="ri-arrow-left-line"></i> Go Back
        </button>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════
     LEARN MORE MODAL — full screen, same bg, blue-tiered text
═══════════════════════════════════════════════════════ -->
<div id="ageGateLearnMoreModal">

    <div class="aglm-topbar">
        <button class="aglm-back-btn" onclick="closeAgeLearnMore()" aria-label="Back">
            <i class="ri-arrow-left-line"></i>
        </button>
        <div class="aglm-topbar-center">
            <div class="aglm-topbar-title">About 18+ Pods</div>
            <div class="aglm-topbar-sub">Guidelines &amp; safety information</div>
        </div>
        <div class="aglm-topbar-spacer"></div>
    </div>

    <div class="aglm-body">

        <div class="aglm-lead-icon">
            <i class="ri-shield-line"></i>
        </div>

        <h3 class="aglm-h1">Learn More About 18+ NSFW Pods</h3>

        <p class="aglm-p">18+ NSFW Pods are communities on Dezzme intended exclusively for adults aged 18 years and older. These pods provide a space where mature discussions, artwork, media, humor, and other adult-oriented content can be shared responsibly among consenting adults.</p>

        <p class="aglm-p">Unlike standard pods, 18+ NSFW Pods may contain material considered inappropriate for minors or unsuitable for general audiences — including discussions of sexuality, artistic nudity, adult humor, relationship topics, mature memes, explicit language, or other adult-oriented content. Every pod sets its own focus and guidelines.</p>

        <div class="aglm-section-head"><i class="ri-shield-check-line"></i> Safeguards in place</div>
        <p class="aglm-p">All 18+ NSFW Pods are clearly labeled and require users to confirm they are at least 18 years old before joining or viewing content. This keeps adult content separated from the rest of the platform and visible only to eligible members. Joining is entirely optional — if you'd rather not see mature content, you're under no obligation to enter these communities.</p>

        <div class="aglm-section-head"><i class="ri-close-circle-line"></i> Strictly prohibited</div>
        <ul class="aglm-list">
            <li><i class="ri-forbid-line"></i> Any content involving or depicting minors</li>
            <li><i class="ri-forbid-line"></i> Non-consensual, exploitative, or abusive sexual content</li>
            <li><i class="ri-forbid-line"></i> Harassment, threats, intimidation, or hate speech</li>
            <li><i class="ri-forbid-line"></i> Sharing private or intimate content without consent</li>
            <li><i class="ri-forbid-line"></i> Human trafficking, coercion, or sexual exploitation</li>
            <li><i class="ri-forbid-line"></i> Content promoting or glorifying illegal activity</li>
            <li><i class="ri-forbid-line"></i> Spam, scams, deceptive practices, or malicious links</li>
            <li><i class="ri-forbid-line"></i> Attempts to bypass safety systems or age restrictions</li>
        </ul>

        <div class="aglm-section-head"><i class="ri-user-star-line"></i> Moderator responsibilities</div>
        <p class="aglm-p">Pod owners and admins enforce pod-specific rules, review reports, and moderate discussions. Depending on severity, this can include removing posts, issuing warnings, muting members, removing users, or escalating serious violations to Dezzme for review.</p>

        <div class="aglm-section-head"><i class="ri-flag-line"></i> Your role as a member</div>
        <p class="aglm-p">If you see content that breaks the rules or makes you uncomfortable, use Dezzme's reporting tools rather than engaging directly. Reports help moderators and the platform respond quickly and effectively.</p>

        <div class="aglm-section-head"><i class="ri-lock-2-line"></i> Privacy &amp; consent</div>
        <p class="aglm-p">Before sharing photos, videos, or conversations involving another person, you must have their permission where required. Respecting personal boundaries is essential to a trustworthy adult community. Laws around adult content also vary by region — you're responsible for ensuring your participation complies with local law.</p>

        <div class="aglm-note">
            <i class="ri-information-line"></i>
            By entering an 18+ NSFW Pod, you confirm you are at least 18 years old and agree to follow both the pod's rules and Dezzme's Community Guidelines at all times.
        </div>

        <button class="aglm-done-btn" onclick="closeAgeLearnMore()">
            <i class="ri-check-line"></i> Got it
        </button>

    </div>
</div>


<style>
/* ══════════════════════════════════════════════════════════
   AGE GATE — premium dark-blue full screen
   Backdrop matches site body gradient exactly.
   ══════════════════════════════════════════════════════════ */
#ageGateOverlay {
    display: none;
    position: fixed; inset: 0;
    z-index: 999999999999;
    background: linear-gradient(-45deg,#010218,#070924,#070924);
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 28px 24px;
    text-align: center;
    font-family: 'Poppins', sans-serif;
}
#ageGateOverlay.open {
    display: flex;
    animation: agv2FadeIn 0.32s cubic-bezier(0.22,1,0.36,1);
}
@keyframes agv2FadeIn {
    from { opacity: 0; transform: translateY(14px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* ── Icon: rounded-square gradient badge, shield + exclamation ── */
.agv2-icon-wrap {
    position: relative;
    width: 108px; height: 108px;
    border-radius: 28px;
    background: linear-gradient(135deg, #1a56db 0%, #0673f0 55%, #4dabff 100%);
    display: flex; align-items: center; justify-content: center;
    margin-bottom: 26px;
    box-shadow: 0 18px 50px rgba(6,115,240,0.35), 0 0 0 1px rgba(255,255,255,0.06) inset;
}
.agv2-icon-shield {
    font-size: 58px;
    color: rgba(255,255,255,0.92);
}
.agv2-icon-mark {
    position: absolute;
    font-size: 20px;
    color: #0a1224;
    top: 50%; left: 50%;
    transform: translate(-50%, -58%);
}

.agv2-title {
    font-size: 22px;
    font-weight: 700;
    color: #fff;
    margin: 0 0 12px;
    line-height: 1.3;
    max-width: 340px;
}

.agv2-sub {
    font-size: 13.5px;
    color: rgba(255,255,255,0.5);
    line-height: 1.65;
    max-width: 340px;
    margin: 0 0 30px;
}
.agv2-sub strong { color: #8fb4ff; font-weight: 700; }

/* ── Buttons: square, rounded corners (not pills) ── */
.agv2-btns {
    display: flex;
    flex-direction: column;
    gap: 10px;
    width: 100%;
    max-width: 320px;
}
.agv2-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    padding: 14px 18px;
    border-radius: 14px;
    border: none;
    font-size: 14px;
    font-weight: 700;
    font-family: 'Poppins', sans-serif;
    cursor: pointer;
    transition: all 0.22s ease;
    -webkit-tap-highlight-color: transparent;
}
.agv2-btn i { font-size: 16px; flex-shrink: 0; }

.agv2-btn-continue {
    background: #0a3f94;
    color: #fff;
}
.agv2-btn-continue:hover { background: #0a80ff; transform: translateY(-1px); }
.agv2-btn-continue:active { transform: scale(0.98); }

.agv2-btn-learn {
    background: rgba(77,171,255,0.1);
    border: 1px solid rgba(77,171,255,0.3);
    color: #8fb4ff;
}
.agv2-btn-learn:hover { background: rgba(77,171,255,0.18); }
.agv2-btn-learn:active { transform: scale(0.98); }

.agv2-btn-back {
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.1);
    color: rgba(255,255,255,0.55);
}
.agv2-btn-back:hover { background: rgba(255,255,255,0.09); color: #fff; }
.agv2-btn-back:active { transform: scale(0.98); }

/* ════════════════════════════════════════
   RESPONSIVE — 790 / 490 / 390 / 330px
   ════════════════════════════════════════ */
@media (max-width: 790px) {
    .agv2-icon-wrap { width: 92px; height: 92px; border-radius: 24px; margin-bottom: 22px; }
    .agv2-icon-shield { font-size: 49px; }
    .agv2-icon-mark { font-size: 17px; }
    .agv2-title { font-size: 19px; }
    .agv2-sub { font-size: 12.5px; margin-bottom: 26px; }
    .agv2-btn { padding: 12px 16px; font-size: 13px; border-radius: 13px; }
}
@media (max-width: 490px) {
    #ageGateOverlay { padding: 22px 18px; }
    .agv2-icon-wrap { width: 76px; height: 76px; border-radius: 20px; margin-bottom: 18px; }
    .agv2-icon-shield { font-size: 40px; }
    .agv2-icon-mark { font-size: 15px; }
    .agv2-title { font-size: 16.5px; max-width: 280px; }
    .agv2-sub { font-size: 11.5px; margin-bottom: 22px; max-width: 280px; }
    .agv2-btns { gap: 8px; max-width: 280px; }
    .agv2-btn { padding: 11px 14px; font-size: 12px; border-radius: 12px; gap: 6px; }
    .agv2-btn i { font-size: 14px; }
}
@media (max-width: 390px) {
    .agv2-icon-wrap { width: 64px; height: 64px; border-radius: 17px; margin-bottom: 15px; }
    .agv2-icon-shield { font-size: 33px; }
    .agv2-icon-mark { font-size: 13px; }
    .agv2-title { font-size: 15px; max-width: 250px; }
    .agv2-sub { font-size: 11px; margin-bottom: 18px; max-width: 250px; }
    .agv2-btns { max-width: 250px; }
    .agv2-btn { padding: 10px 12px; font-size: 11px; border-radius: 11px; }
    .agv2-btn i { font-size: 13px; }
}
@media (max-width: 330px) {
    .agv2-icon-wrap { width: 54px; height: 54px; border-radius: 14px; margin-bottom: 12px; }
    .agv2-icon-shield { font-size: 27px; }
    .agv2-icon-mark { font-size: 11px; }
    .agv2-title { font-size: 13.5px; max-width: 220px; }
    .agv2-sub { font-size: 10px; margin-bottom: 15px; max-width: 220px; }
    .agv2-btns { gap: 6px; max-width: 220px; }
    .agv2-btn { padding: 9px 10px; font-size: 10px; border-radius: 10px; }
    .agv2-btn i { font-size: 11.5px; }
}


/* ══════════════════════════════════════════════════════════
   LEARN MORE MODAL — full screen, same bg, tiered blue text
   ══════════════════════════════════════════════════════════ */
#ageGateLearnMoreModal {
    display: none;
    position: fixed; inset: 0;
    z-index: 9999999999999;
    background: linear-gradient(-45deg,#010218,#070924,#070924);
    flex-direction: column;
    overflow: hidden;
    font-family: 'Poppins', sans-serif;
}
#ageGateLearnMoreModal.open {
    display: flex;
    animation: aglmSlideIn 0.28s cubic-bezier(0.22,1,0.36,1);
}
@keyframes aglmSlideIn {
    from { opacity: 0; transform: translateY(20px); }
    to   { opacity: 1; transform: translateY(0); }
}
@keyframes aglmSlideOut {
    to { opacity: 0; transform: translateY(16px); }
}

.aglm-topbar {
    display: flex; align-items: center; gap: 12px;
    padding: 14px 20px;
    background: rgba(3,4,32,0.97);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    border-bottom: 1px solid rgba(77,171,255,0.12);
    flex-shrink: 0;
    position: sticky; top: 0; z-index: 10;
}
.aglm-back-btn {
    display: flex; align-items: center; justify-content: center;
    width: 36px; height: 36px;
    border-radius: 12px;
    border: 1px solid rgba(77,171,255,0.25);
    background: rgba(77,171,255,0.08);
    color: #8fb4ff;
    font-size: 20px;
    cursor: pointer;
    transition: 0.2s;
    flex-shrink: 0;
    -webkit-tap-highlight-color: transparent;
}
.aglm-back-btn:hover  { background: rgba(77,171,255,0.16); color: #fff; }
.aglm-back-btn:active { transform: scale(0.92); }

.aglm-topbar-center { flex: 1; min-width: 0; }
.aglm-topbar-title {
    font-size: 15px; font-weight: 700; color: #fff;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.aglm-topbar-sub {
    font-size: 11px; color: rgba(77,171,255,0.55);
    margin-top: 1px;
}
.aglm-topbar-spacer { width: 36px; height: 36px; flex-shrink: 0; }

.aglm-body {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
    scrollbar-color: rgba(77,171,255,0.2) transparent;
    padding: 26px 22px 60px;
    max-width: 620px;
    margin: 0 auto;
    width: 100%;
    box-sizing: border-box;
}
.aglm-body::-webkit-scrollbar { width: 4px; }
.aglm-body::-webkit-scrollbar-thumb { background: rgba(77,171,255,0.2); border-radius: 99px; }

.aglm-lead-icon {
    width: 56px; height: 56px;
    border-radius: 16px;
    background: linear-gradient(135deg, #1a56db, #0673f0);
    display: flex; align-items: center; justify-content: center;
    font-size: 28px; color: #fff;
    margin: 0 0 18px;
    box-shadow: 0 10px 26px rgba(6,115,240,0.3);
}

.aglm-h1 {
    font-size: 19px; font-weight: 700;
    color: #fff;
    margin: 0 0 16px;
    line-height: 1.3;
}

.aglm-p {
    font-size: 13px;
    color: rgba(255,255,255,0.55);
    line-height: 1.7;
    margin: 0 0 16px;
}

.aglm-section-head {
    display: flex; align-items: center; gap: 8px;
    font-size: 12px; font-weight: 700;
    color: #4dabff;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    margin: 26px 0 12px;
    padding-bottom: 8px;
    border-bottom: 1px solid rgba(77,171,255,0.15);
}
.aglm-section-head i { font-size: 15px; color: #1a56db; }

.aglm-list {
    list-style: none;
    margin: 0 0 6px;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.aglm-list li {
    display: flex; align-items: flex-start; gap: 10px;
    font-size: 12.5px;
    color: rgba(255,255,255,0.6);
    line-height: 1.55;
    background: rgba(77,171,255,0.04);
    border: 1px solid rgba(77,171,255,0.1);
    border-radius: 12px;
    padding: 10px 13px;
}
.aglm-list li i {
    font-size: 15px;
    color: #5c8fe0;
    flex-shrink: 0;
    margin-top: 1px;
}

.aglm-note {
    display: flex; align-items: flex-start; gap: 10px;
    background: rgba(26,86,219,0.08);
    border: 1px solid rgba(26,86,219,0.25);
    border-radius: 14px;
    padding: 14px 16px;
    font-size: 12px;
    color: #8fb4ff;
    line-height: 1.6;
    margin: 26px 0 24px;
}
.aglm-note i { font-size: 16px; color: #4dabff; flex-shrink: 0; margin-top: 1px; }

.aglm-done-btn {
    display: flex; align-items: center; justify-content: center; gap: 8px;
    width: 100%;
    padding: 14px;
    border-radius: 14px;
    border: none;
    background: #0673f0;
    color: #fff;
    font-size: 14px; font-weight: 700;
    font-family: 'Poppins', sans-serif;
    cursor: pointer;
    box-shadow: 0 10px 26px rgba(6,115,240,0.3);
    transition: 0.22s;
    -webkit-tap-highlight-color: transparent;
}
.aglm-done-btn:hover { background: #0a80ff; }
.aglm-done-btn:active { transform: scale(0.98); }

/* ════════════════════════════════════════
   RESPONSIVE — 790 / 490 / 390 / 330px
   ════════════════════════════════════════ */
@media (max-width: 790px) {
    .aglm-topbar { padding: 12px 16px; gap: 10px; }
    .aglm-back-btn { width: 32px; height: 32px; font-size: 17px; border-radius: 10px; }
    .aglm-topbar-spacer { width: 32px; height: 32px; }
    .aglm-topbar-title { font-size: 13.5px; }
    .aglm-topbar-sub { font-size: 10px; }

    .aglm-body { padding: 20px 17px 48px; }
    .aglm-lead-icon { width: 48px; height: 48px; font-size: 24px; border-radius: 14px; margin-bottom: 15px; }
    .aglm-h1 { font-size: 16.5px; margin-bottom: 13px; }
    .aglm-p { font-size: 12px; margin-bottom: 13px; }
    .aglm-section-head { font-size: 11px; margin: 21px 0 10px; }
    .aglm-list li { font-size: 11.5px; padding: 9px 11px; border-radius: 11px; }
    .aglm-note { font-size: 11px; padding: 12px 14px; border-radius: 12px; margin: 21px 0 20px; }
    .aglm-done-btn { padding: 12px; font-size: 12.5px; border-radius: 12px; }
}
@media (max-width: 490px) {
    .aglm-topbar { padding: 10px 14px; gap: 9px; }
    .aglm-back-btn { width: 30px; height: 30px; font-size: 16px; border-radius: 9px; }
    .aglm-topbar-spacer { width: 30px; height: 30px; }
    .aglm-topbar-title { font-size: 12.5px; }
    .aglm-topbar-sub { font-size: 9.5px; }

    .aglm-body { padding: 16px 14px 40px; }
    .aglm-lead-icon { width: 42px; height: 42px; font-size: 21px; border-radius: 12px; margin-bottom: 12px; }
    .aglm-h1 { font-size: 14.5px; margin-bottom: 11px; }
    .aglm-p { font-size: 11px; margin-bottom: 11px; line-height: 1.6; }
    .aglm-section-head { font-size: 10px; margin: 18px 0 9px; padding-bottom: 6px; gap: 6px; }
    .aglm-section-head i { font-size: 13px; }
    .aglm-list { gap: 8px; }
    .aglm-list li { font-size: 10.5px; padding: 8px 10px; border-radius: 10px; gap: 8px; }
    .aglm-list li i { font-size: 13px; }
    .aglm-note { font-size: 10px; padding: 11px 12px; border-radius: 11px; margin: 18px 0 17px; gap: 8px; }
    .aglm-note i { font-size: 14px; }
    .aglm-done-btn { padding: 11px; font-size: 11.5px; border-radius: 11px; }
}
@media (max-width: 390px) {
    .aglm-topbar { padding: 9px 12px; gap: 8px; }
    .aglm-back-btn { width: 28px; height: 28px; font-size: 15px; border-radius: 8px; }
    .aglm-topbar-spacer { width: 28px; height: 28px; }
    .aglm-topbar-title { font-size: 11.5px; }
    .aglm-topbar-sub { font-size: 9px; }

    .aglm-body { padding: 14px 12px 34px; }
    .aglm-lead-icon { width: 38px; height: 38px; font-size: 19px; border-radius: 11px; margin-bottom: 10px; }
    .aglm-h1 { font-size: 13px; margin-bottom: 10px; }
    .aglm-p { font-size: 10.5px; margin-bottom: 10px; }
    .aglm-section-head { font-size: 9.5px; margin: 15px 0 8px; }
    .aglm-list li { font-size: 10px; padding: 7px 9px; }
    .aglm-note { font-size: 9.5px; padding: 10px 11px; margin: 15px 0 15px; }
    .aglm-done-btn { padding: 10px; font-size: 11px; border-radius: 10px; }
}
@media (max-width: 330px) {
    .aglm-topbar { padding: 8px 10px; gap: 7px; }
    .aglm-back-btn { width: 26px; height: 26px; font-size: 14px; border-radius: 7px; }
    .aglm-topbar-spacer { width: 26px; height: 26px; }
    .aglm-topbar-title { font-size: 10.5px; }
    .aglm-topbar-sub { font-size: 8.5px; }

    .aglm-body { padding: 12px 10px 28px; }
    .aglm-lead-icon { width: 34px; height: 34px; font-size: 17px; border-radius: 10px; margin-bottom: 9px; }
    .aglm-h1 { font-size: 12px; margin-bottom: 9px; }
    .aglm-p { font-size: 9.5px; margin-bottom: 9px; }
    .aglm-section-head { font-size: 9px; margin: 13px 0 7px; }
    .aglm-list li { font-size: 9px; padding: 6px 8px; border-radius: 9px; }
    .aglm-note { font-size: 9px; padding: 9px 10px; margin: 13px 0 13px; border-radius: 10px; }
    .aglm-done-btn { padding: 9px; font-size: 10px; border-radius: 9px; }
}
</style>


<script>
/* ═══════════════════════════════════════════
   AGE GATE JS
═══════════════════════════════════════════ */
function confirmAge() {
    sessionStorage.setItem('ageConfirmed_<?php echo (int)$group_id; ?>', '1');
    var overlay = document.getElementById('ageGateOverlay');
    if (overlay) {
        overlay.classList.remove('open');
        document.body.style.overflow = '';
    }
}

var _aglmOpen = false;

function openAgeLearnMore() {
    if (_aglmOpen) return;
    _aglmOpen = true;
    var m = document.getElementById('ageGateLearnMoreModal');
    m.classList.add('open');
    m.querySelector('.aglm-body').scrollTop = 0;
    document.body.style.overflow = 'hidden';
}

function closeAgeLearnMore() {
    if (!_aglmOpen) return;
    _aglmOpen = false;
    var m = document.getElementById('ageGateLearnMoreModal');
    m.style.animation = 'aglmSlideOut 0.22s ease forwards';
    setTimeout(function () {
        m.classList.remove('open');
        m.style.animation = '';
        document.body.style.overflow = '';
    }, 210);
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && _aglmOpen) closeAgeLearnMore();
});
</script>

<!-- ═══════════════════════════════════════════════
     PREMIUM SKELETON PAGE LOADER
═══════════════════════════════════════════════ -->
<div id="skeletonLoader" aria-hidden="true">

    <!-- fake sidebar (desktop) -->
    <div class="skel-sidebar">
        <div class="skel-logo skel-shimmer"></div>
        <div class="skel-navicon skel-shimmer"></div>
        <div class="skel-navicon skel-shimmer"></div>
        <div class="skel-navicon skel-shimmer"></div>
        <div class="skel-navicon skel-shimmer"></div>
        <div class="skel-navicon skel-shimmer"></div>
    </div>

    <div class="skel-main">

        <!-- BANNER -->
        <div class="skel-banner-wrap">
            <div class="skel-banner skel-shimmer"></div>
            <div class="skel-avatar skel-shimmer"></div>
            <div class="skel-banner-actions">
                <div class="skel-circle-sm skel-shimmer"></div>
                <div class="skel-circle-sm skel-shimmer"></div>
                <div class="skel-circle-sm skel-shimmer"></div>
            </div>
            <div class="skel-banner-stats">
                <div class="skel-stat-block">
                    <div class="skel-line skel-shimmer" style="width:26px;height:12px;"></div>
                    <div class="skel-line skel-shimmer" style="width:50px;height:8px;margin-top:5px;"></div>
                </div>
                <div class="skel-stat-block">
                    <div class="skel-line skel-shimmer" style="width:26px;height:12px;"></div>
                    <div class="skel-line skel-shimmer" style="width:60px;height:8px;margin-top:5px;"></div>
                </div>
                <div class="skel-stat-block">
                    <div class="skel-line skel-shimmer" style="width:26px;height:12px;"></div>
                    <div class="skel-line skel-shimmer" style="width:44px;height:8px;margin-top:5px;"></div>
                </div>
            </div>
        </div>

        <!-- HEADER BODY -->
        <div class="skel-body">
            <div class="skel-title-row">
                <div class="skel-line skel-shimmer" style="width:52%;height:20px;"></div>
                <div class="skel-circle-xs skel-shimmer"></div>
            </div>
            <div class="skel-line skel-shimmer" style="width:78%;height:11px;margin-top:10px;"></div>
            <div class="skel-line skel-shimmer" style="width:40%;height:11px;margin-top:7px;"></div>

            <div class="skel-meta-row">
                <div class="skel-circle-xs skel-shimmer"></div>
                <div class="skel-line skel-shimmer" style="width:120px;height:10px;"></div>
            </div>

            <div class="skel-actions-row">
                <div class="skel-pill skel-shimmer" style="width:96px;"></div>
                <div class="skel-pill skel-shimmer" style="width:76px;"></div>
            </div>
        </div>

        <!-- NAV BAR -->
        <div class="skel-navbar">
            <div class="skel-line skel-shimmer" style="width:70%;height:10px;"></div>
            <div class="skel-navsep"></div>
            <div class="skel-line skel-shimmer" style="width:60%;height:10px;"></div>
            <div class="skel-navsep"></div>
            <div class="skel-line skel-shimmer" style="width:50%;height:10px;"></div>
            <div class="skel-navsep"></div>
            <div class="skel-line skel-shimmer" style="width:60%;height:10px;"></div>
        </div>

        <!-- TOP CONTRIBUTOR ROW -->
        <div class="skel-te-row">
            <div class="skel-circle-md skel-shimmer"></div>
            <div class="skel-te-text">
                <div class="skel-line skel-shimmer" style="width:40%;height:8px;"></div>
                <div class="skel-line skel-shimmer" style="width:55%;height:11px;margin-top:6px;"></div>
                <div class="skel-line skel-shimmer" style="width:70%;height:8px;margin-top:6px;"></div>
            </div>
        </div>

        <!-- DISCUSSIONS / INVITE ROW -->
        <div class="skel-disc-row">
            <div class="skel-pill skel-shimmer" style="width:80px;height:30px;"></div>
            <div class="skel-pill skel-shimmer" style="width:120px;height:30px;"></div>
        </div>

        <!-- MEMBERS CAROUSEL -->
        <div class="skel-members-row">
            <div class="skel-member">
                <div class="skel-circle-md skel-shimmer"></div>
                <div class="skel-line skel-shimmer" style="width:40px;height:8px;margin-top:8px;"></div>
            </div>
            <div class="skel-member">
                <div class="skel-circle-md skel-shimmer"></div>
                <div class="skel-line skel-shimmer" style="width:40px;height:8px;margin-top:8px;"></div>
            </div>
            <div class="skel-member">
                <div class="skel-circle-md skel-shimmer"></div>
                <div class="skel-line skel-shimmer" style="width:40px;height:8px;margin-top:8px;"></div>
            </div>
            <div class="skel-member">
                <div class="skel-circle-md skel-shimmer"></div>
                <div class="skel-line skel-shimmer" style="width:40px;height:8px;margin-top:8px;"></div>
            </div>
            <div class="skel-member">
                <div class="skel-circle-md skel-shimmer"></div>
                <div class="skel-line skel-shimmer" style="width:40px;height:8px;margin-top:8px;"></div>
            </div>
        </div>

        <!-- POST PILL -->
        <div class="skel-post-pill">
            <div class="skel-circle-xs skel-shimmer"></div>
            <div class="skel-pill-bar skel-shimmer"></div>
        </div>

        <!-- SORT BAR -->
        <div class="skel-sortbar skel-shimmer"></div>

        <!-- POST GRID -->
        <div class="skel-grid">
            <div class="skel-card">
                <div class="skel-card-media skel-shimmer"></div>
                <div class="skel-card-userbar">
                    <div class="skel-circle-xs skel-shimmer"></div>
                    <div class="skel-line skel-shimmer" style="width:60%;height:9px;"></div>
                </div>
            </div>
            <div class="skel-card">
                <div class="skel-card-media skel-shimmer"></div>
                <div class="skel-card-userbar">
                    <div class="skel-circle-xs skel-shimmer"></div>
                    <div class="skel-line skel-shimmer" style="width:50%;height:9px;"></div>
                </div>
            </div>
            <div class="skel-card">
                <div class="skel-card-media skel-shimmer"></div>
                <div class="skel-card-userbar">
                    <div class="skel-circle-xs skel-shimmer"></div>
                    <div class="skel-line skel-shimmer" style="width:65%;height:9px;"></div>
                </div>
            </div>
            <div class="skel-card">
                <div class="skel-card-media skel-shimmer"></div>
                <div class="skel-card-userbar">
                    <div class="skel-circle-xs skel-shimmer"></div>
                    <div class="skel-line skel-shimmer" style="width:55%;height:9px;"></div>
                </div>
            </div>
            <div class="skel-card">
                <div class="skel-card-media skel-shimmer"></div>
                <div class="skel-card-userbar">
                    <div class="skel-circle-xs skel-shimmer"></div>
                    <div class="skel-line skel-shimmer" style="width:60%;height:9px;"></div>
                </div>
            </div>
            <div class="skel-card">
                <div class="skel-card-media skel-shimmer"></div>
                <div class="skel-card-userbar">
                    <div class="skel-circle-xs skel-shimmer"></div>
                    <div class="skel-line skel-shimmer" style="width:48%;height:9px;"></div>
                </div>
            </div>
        </div>

    </div><!-- /skel-main -->

    <!-- fake bottom mobile nav -->
    <div class="skel-mobile-nav">
        <div class="skel-mnav-icon skel-shimmer"></div>
        <div class="skel-mnav-icon skel-shimmer"></div>
        <div class="skel-mnav-icon skel-shimmer"></div>
        <div class="skel-mnav-icon skel-shimmer"></div>
        <div class="skel-mnav-icon skel-shimmer"></div>
    </div>

</div>

<style>
/* ══════════════════════════════════════════════════
   PREMIUM SKELETON PAGE LOADER
══════════════════════════════════════════════════ */

#skeletonLoader {
    position: fixed;
    inset: 0;
    z-index: 999999999999;
    background: linear-gradient(-45deg,#010218,#070924,#070924);
    overflow-y: auto;
    overflow-x: hidden;
    -webkit-overflow-scrolling: touch;
    opacity: 1;
    transition: opacity 0.4s ease;
}
#skeletonLoader.skel-fade-out { opacity: 0; pointer-events: none; }

/* ── SHIMMER BASE ── */
.skel-shimmer {
    position: relative;
    background: rgba(255,255,255,0.045);
    overflow: hidden;
    border-radius: 8px;
}
.skel-shimmer::after {
    content: '';
    position: absolute;
    inset: 0;
    transform: translateX(-100%);
    background: linear-gradient(
        90deg,
        transparent 0%,
        rgba(255,255,255,0.055) 25%,
        rgba(255,255,255,0.14) 50%,
        rgba(255,255,255,0.055) 75%,
        transparent 100%
    );
    animation: skelSweep 1.6s ease-in-out infinite;
}
@keyframes skelSweep {
    0%   { transform: translateX(-100%); }
    100% { transform: translateX(100%); }
}

.skel-line { border-radius: 6px; }

/* ── SIDEBAR ── */
.skel-sidebar {
    position: fixed;
    left: 0; top: 0;
    width: 100px; height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 32px;
    padding: 20px;
}
.skel-logo { width: 55px; height: 55px; border-radius: 15px; }
.skel-navicon { width: 40px; height: 30px; border-radius: 12px; }

@media (max-width: 768px) {
    .skel-sidebar { display: none; }
}

/* ── MAIN ── */
.skel-main {
    margin-left: 100px;
    padding: 10px;
}
@media (max-width: 768px) {
    .skel-main { margin-left: 0; padding: 0; }
}

/* ── BANNER ── */
.skel-banner-wrap {
    position: relative;
    height: 250px;
    width: 100%;
    border-radius: 6px;
    overflow: visible;
    margin-bottom: 20px;
}
.skel-banner {
    width: 100%; height: 100%;
    border-radius: 6px;
}
.skel-avatar {
    position: absolute;
    bottom: -30px; left: 10px;
    width: 110px; height: 110px;
    border-radius: 19%;
    border: 6px solid #070924;
    box-sizing: border-box;
}
.skel-banner-actions {
    position: absolute;
    top: 8px; right: 8px;
    display: flex; gap: 6px;
}
.skel-circle-sm { width: 36px; height: 36px; border-radius: 50%; }
.skel-banner-stats {
    position: absolute;
    bottom: 10px; left: 130px;
    display: flex; align-items: center; gap: 14px;
}
.skel-stat-block { display: flex; flex-direction: column; gap: 2px; }

@media (max-width: 780px) {
    .skel-banner-wrap { height: 190px; margin-bottom: 16px; }
    .skel-avatar { width: 100px; height: 100px; border-radius: 27%; bottom: -25px; left: 8px; border-width: 5px; }
    .skel-banner-stats { left: 130px; bottom: 8px; gap: 11px; }
}
@media (max-width: 490px) {
    .skel-banner-wrap { height: 140px; }
    .skel-avatar { width: 75px; height: 75px; border-radius: 50%; bottom: -22px; left: 6px; border-width: 4px; }
    .skel-banner-actions { top: 8px; right: 8px; }
    .skel-circle-sm { width: 32px; height: 32px; }
    .skel-banner-stats { left: 90px; bottom: 7px; gap: 9px; }
}
@media (max-width: 380px) {
    .skel-banner-wrap { height: 125px; }
    .skel-avatar { width: 69px; height: 69px; bottom: -25px; left: 5px; border-width: 4px; }
    .skel-banner-stats { left: 84px; bottom: 6px; gap: 7px; }
}
@media (max-width: 330px) {
    .skel-banner-wrap { height: 105px; }
    .skel-avatar { width: 55px; height: 55px; bottom: -18px; left: 6px; border-width: 3px; }
    .skel-banner-stats { left: 70px; bottom: 6px; gap: 6px; }
    .skel-circle-sm { width: 28px; height: 28px; }
}

/* ── HEADER BODY ── */
.skel-body {
    padding: 36px 18px 16px;
}
.skel-title-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}
.skel-circle-xs { width: 22px; height: 22px; border-radius: 50%; flex-shrink: 0; }
.skel-meta-row {
    display: flex; align-items: center; gap: 8px;
    margin-top: 14px;
}
.skel-actions-row {
    display: flex; gap: 8px;
    margin-top: 14px;
}
.skel-pill { height: 28px; border-radius: 20px; }

@media (max-width: 480px) {
    .skel-body { padding: 28px 12px 13px; }
}
@media (max-width: 380px) {
    .skel-body { padding: 24px 10px 12px; }
}
@media (max-width: 330px) {
    .skel-body { padding: 20px 8px 11px; }
}

/* ── NAV BAR ── */
.skel-navbar {
    display: flex;
    align-items: center;
    justify-content: space-around;
    padding: 12px 16px;
    margin-top: 14px;
    border-top: 1px solid rgba(255,255,255,0.04);
    border-bottom: 1px solid rgba(255,255,255,0.07);
}
.skel-navsep { width: 1px; height: 18px; background: rgba(255,255,255,0.1); flex-shrink: 0; }

@media (max-width: 490px) {
    .skel-navbar { padding: 10px 12px; }
    .skel-navsep { height: 14px; }
}

/* ── TOP CONTRIBUTOR ── */
.skel-te-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 12px;
    margin: 14px 12px;
    border-radius: 14px;
    background: rgba(255,255,255,0.02);
    border: 1px solid rgba(255,255,255,0.06);
}
.skel-circle-md { width: 38px; height: 38px; border-radius: 50%; flex-shrink: 0; }
.skel-te-text { flex: 1; min-width: 0; }

@media (max-width: 490px) {
    .skel-te-row { margin: 10px 10px; padding: 8px 10px; gap: 8px; }
    .skel-circle-md { width: 30px; height: 30px; }
}

/* ── DISCUSSIONS / INVITE ROW ── */
.skel-disc-row {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    padding: 0 16px;
    margin-bottom: 14px;
}
@media (max-width: 480px) {
    .skel-disc-row { padding: 0 10px; margin-bottom: 10px; }
}

/* ── MEMBERS ROW ── */
.skel-members-row {
    display: flex;
    gap: 20px;
    padding: 10px 16px 20px;
    overflow: hidden;
}
.skel-member {
    display: flex;
    flex-direction: column;
    align-items: center;
    flex-shrink: 0;
}

@media (max-width: 768px) {
    .skel-members-row { padding: 8px 12px 16px; gap: 16px; }
    .skel-circle-md { width: 34px; height: 34px; }
}
@media (max-width: 480px) {
    .skel-members-row { padding: 6px 10px 14px; gap: 14px; }
}

/* ── POST PILL ── */
.skel-post-pill {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 6px 16px 6px 6px;
    margin: 0 16px 22px;
    border-radius: 999px;
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.06);
}
.skel-pill-bar {
    flex: 1;
    height: 40px;
    border-radius: 999px;
}

@media (max-width: 480px) {
    .skel-post-pill { margin: 0 10px 16px; padding: 4px 14px 4px 5px; }
    .skel-pill-bar { height: 32px; }
}

/* ── SORT BAR ── */
.skel-sortbar {
    height: 54px;
    margin: 0 16px 20px;
    border-radius: 14px;
}
@media (max-width: 780px) {
    .skel-sortbar { height: 47px; margin: 0 12px 16px; border-radius: 12px; }
}
@media (max-width: 480px) {
    .skel-sortbar { height: 44px; margin: 0 10px 14px; }
}

/* ── GRID ── */
.skel-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
    padding: 0 16px 40px;
}
.skel-card {
    border-radius: 25px;
    overflow: hidden;
    background: rgba(255,255,255,0.02);
    border: 1px solid rgba(255,255,255,0.06);
}
.skel-card-media {
    width: 100%;
    height: 220px;
}
.skel-card-userbar {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 12px;
}

@media (max-width: 1024px) {
    .skel-grid { grid-template-columns: repeat(2, 1fr); gap: 14px; padding: 0 12px 32px; }
    .skel-card-media { height: 180px; }
}
@media (max-width: 768px) {
    .skel-grid { grid-template-columns: repeat(2, 1fr); gap: 14px; padding: 0 12px 32px; }
    .skel-card-media { height: 160px; }
}
@media (max-width: 480px) {
    .skel-grid { grid-template-columns: 1fr; gap: 12px; padding: 0 10px 28px; }
    .skel-card-media { height: 220px; }
}

/* ── MOBILE BOTTOM NAV ── */
.skel-mobile-nav {
    display: none;
}
@media (max-width: 768px) {
    .skel-mobile-nav {
        display: flex;
        position: fixed;
        bottom: 0; left: 0; right: 0;
        height: 60px;
        background: rgba(3,4,21,0.97);
        border-top: 1px solid rgba(255,255,255,0.08);
        align-items: center;
        justify-content: space-evenly;
        z-index: 10;
    }
    .skel-mnav-icon {
        width: 22px; height: 22px;
        border-radius: 6px;
    }
}
</style> 

<script>
(function () {
  var sk = document.getElementById('skeletonLoader');
  var main = document.querySelector('.main') ||
             document.querySelector('.group-feed') ||
             document.querySelector('.gh');

  function hideSkeleton() {
    if (!sk) return;
    sk.style.transition = 'opacity 0.35s ease';
    sk.style.opacity = '0';
    setTimeout(function () {
      sk.style.display = 'none';
    }, 360);
  }

  if (document.readyState === 'complete') {
    hideSkeleton();
  } else {
    window.addEventListener('load', hideSkeleton);
    // Safety net — never block UI longer than 3 s
    setTimeout(hideSkeleton, 3000);
  }
})();
</script>

<!-- Sidebar -->
<div class="sidebar">
    <div class="logo" onclick="location.href='profile.php'"><img src="Dezzme.jpg"></div>
    <a href="home.php" class="nav-icon" title="Home"><i class="ri-home-5-line" style="font-size:15px;color:white;"></i></a>
    <a class="glass nav-icon" href="text_feed.php"><i class="ri-send-plane-line" style="font-size:15px;color:white;"></i></a>
    <a href="search.php" class="nav-icon" title="Discover"><i class="ri-search-line" style="font-size:15px;color:white;"></i></a>
    <a href="upload.php" class="nav-icon" title="Upload"><i class="ri-add-circle-line" style="font-size:29px; color:white;"></i></a>
    <a href="community.php" class="nav-icon active" title="Community"><i class="ri-team-line" style="font-size:15px;color:white;"></i></a>
    <a href="profile.php" class="nav-icon" title="Profile"><i class="ri-user-3-line" style="font-size:15px;color:white;"></i></a>
    </div>

<div class="main">

<!-- GROUP HEADER -->
<div class="gh">

    <?php
    $group_pic = $group['group_pic'] ?? '';
    $img_src = empty($group_pic) ? 'uploads/default_group.png' :
               (strpos($group_pic, 'uploads/') === 0 ? $group_pic : 'uploads/' . $group_pic);
    ?>

<?php
// ── Record this page visit (once per day per user per group — Reddit style)
if ($current_user) {
    $already_viewed = $conn->query("
        SELECT 1 FROM group_views 
        WHERE group_id = $group_id 
        AND viewer_id = $current_user 
        AND viewed_at >= CURDATE()
        LIMIT 1
    ");
    if ($already_viewed && $already_viewed->num_rows === 0) {
        $conn->query("
            INSERT INTO group_views (group_id, viewer_id) 
            VALUES ($group_id, $current_user)
        ");
    }
}

// ── Unique visitors in the last 7 days (exactly how Reddit counts it)
$visitors_res = $conn->query("
    SELECT COUNT(DISTINCT viewer_id) AS total
    FROM group_views
    WHERE group_id = $group_id
    AND viewed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
");
$visitors_week = (int)($visitors_res->fetch_assoc()['total'] ?? 0);

// ── Contributions in the last 7 days (posts + discussion replies)
$contrib_res = $conn->query("
    SELECT (
        SELECT COUNT(*) 
        FROM group_posts 
        WHERE group_id = $group_id 
        AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ) + (
        SELECT COUNT(*) 
        FROM pod_discussion_replies pdr
        JOIN pod_discussions pd ON pd.id = pdr.discussion_id
        WHERE pd.group_id = $group_id
        AND pdr.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ) AS total
");
$contributions_week = (int)($contrib_res->fetch_assoc()['total'] ?? 0);

// ── Total posts ever
$total_posts_res = $conn->query("
    SELECT COUNT(*) as c 
    FROM group_posts 
    WHERE group_id = $group_id
");
$total_posts_group = (int)$total_posts_res->fetch_assoc()['c'];
?>

<div class="banner-container">

    <!-- BACK BUTTON — mobile only -->
    <button class="banner-back-btn" onclick="history.back()">
        <i class="ri-arrow-left-s-line"></i>
    </button>

    <img class="group-banner" src="<?php echo $banner_src; ?>"
         onerror="this.src='uploads/default_group.png';" alt="Group Banner">

    <!-- Overlay -->
    <div class="banner-overlay"></div>
     
    <!-- Group Picture -->
    <div class="group-pic-container" onclick="openGroupPicModal()">
        <img class="group-pic" src="<?php echo $img_src; ?>"
             onerror="this.src='uploads/default_group.png';" alt="Group Picture">
    </div>

    <!-- BANNER ACTION BUTTONS -->
    <div class="banner-action-btns">
    <?php if ($is_adult_group): ?>
        <span class="gh-adult">
            <i class="ri-error-warning-fill"></i> 18+ Group
        </span>
    <?php endif; ?>
    <button class="banner-action-btn" onclick="openGroupSearchModal()" aria-label="Search">
        <i class="ri-search-line"></i>
    </button>
    <?php if ($is_member): ?>
    <button class="banner-action-btn" onclick="openPostModal()" aria-label="New post">
        <i class="ri-add-line"></i>
    </button>
    <?php endif; ?>
    <button class="banner-action-btn" onclick="openGroupOptionsModal()" aria-label="More options">
        <i class="ri-more-fill"></i>
    </button>
</div>

    <!-- BANNER STATS OVERLAY — Reddit style -->
    <div class="banner-stats-overlay">
        <div class="bso-block">
            <span class="bso-num"><?php echo number_format($visitors_week); ?></span>
            <span class="bso-lbl">Visitors / wk</span>
        </div>
        <div class="bso-divider"></div>
        <div class="bso-block">
            <span class="bso-num"><?php echo number_format($contributions_week); ?></span>
            <span class="bso-lbl">Contributions / wk</span>
        </div>
        <div class="bso-divider"></div>
        <div class="bso-block">
            <span class="bso-num"><?php echo number_format($total_posts_group); ?></span>
            <span class="bso-lbl">Total posts</span>
        </div>
    </div>

</div>

<style>
/* ══ BANNER ACTION BUTTONS — now always visible ══ */
.banner-action-btns {
    display: flex;
    position: absolute;
    top: 8px;
    right: 8px;
    z-index: 10;
    align-items: center;
    gap: 6px;
}

.banner-action-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    border: none;
    background: rgba(0, 0, 0, 0.45);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    color: #fff;
    font-size: 18px;
    cursor: pointer;
    transition: background 0.2s, transform 0.15s;
    -webkit-tap-highlight-color: transparent;
    outline: none;
    appearance: none;
    -webkit-appearance: none;
}

.banner-action-btn:hover  { background: rgba(0, 0, 0, 0.65); }
.banner-action-btn:active { background: rgba(255,255,255,0.15); transform: scale(0.92); }

@media (max-width: 790px) {
    .banner-action-btns { top: 8px; right: 8px; gap: 6px; }
    .banner-action-btn  { width: 34px; height: 34px; font-size: 17px; }
}
@media (max-width: 330px) {
    .banner-action-btns { top: 7px; right: 7px; gap: 5px; }
    .banner-action-btn  { width: 30px; height: 30px; font-size: 15px; }
}

/* ══════════════════════════════════════════════════════
   GROUP OPTIONS MODAL  (desktop = centered form modal,
   mobile  = bottom sheet)
   Sharp corners throughout — no border-radius on card
══════════════════════════════════════════════════════ */
#groupOptionsOverlay {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 9999999999;
    background: rgba(0, 0, 0, 0.72);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    align-items: center;
    justify-content: center;
    padding: 20px;
    font-family: 'Poppins', sans-serif;
}
#groupOptionsOverlay.go-open { display: flex; }

/* ── desktop card ── */
.go-card {
    background: linear-gradient(-45deg,#010218,#010218,#010218);
    border: 1px solid rgba(255,255,255,0.09);
    border-radius: 25px;
    width: 100%;
    max-width: 340px;
    overflow: hidden;
    box-shadow: 0 28px 70px rgba(0,0,0,0.7);
    animation: goCardIn 0.25s cubic-bezier(0.22,1,0.36,1);
    flex-shrink: 0;
}
@keyframes goCardIn {
    from { opacity:0; transform: scale(0.93) translateY(10px); }
    to   { opacity:1; transform: scale(1)    translateY(0); }
}

/* ── header ── */
.go-header {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 14px 16px 11px;
    border-bottom: 1px solid rgba(255,255,255,0.07);
}
.go-group-avatar {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    object-fit: cover;
    border: 2px solid #1a6fd4;
    flex-shrink: 0;
}
.go-group-name {
    font-size: 12px;
    font-weight: 700;
    color: #fff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.go-group-members {
    font-size: 9.5px;
    color: rgba(255,255,255,0.38);
    margin-top: 1px;
}
.go-close-btn {
    margin-left: auto;
    background: none;
    border: none;
    color: rgba(255,255,255,0.45);
    font-size: 11px;
    font-family: 'Poppins', sans-serif;
    font-weight: 500;
    cursor: pointer;
    flex-shrink: 0;
    padding: 3px 6px;
    letter-spacing: 0.01em;
    transition: 0.2s;
    -webkit-tap-highlight-color: transparent;
}
.go-close-btn:hover { color: #fff; }

/* ── option rows ── */
.go-option {
    display: flex;
    align-items: center;
    gap: 11px;
    padding: 11px 16px;
    cursor: pointer;
    border-bottom: 1px solid rgba(255,255,255,0.05);
    transition: background 0.15s;
    -webkit-tap-highlight-color: transparent;
    position: relative;
}
.go-option:last-child { border-bottom: none; }
.go-option:hover  { background: rgba(255,255,255,0.04); }
.go-option:active { background: rgba(255,255,255,0.08); }

.go-option-icon {
    width: 32px; height: 32px;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px;
    flex-shrink: 0;
}
.go-option-icon.blue    { background: rgba(6,115,240,0.12); color: #4dabff; }
.go-option-icon.red     { background: rgba(6,115,240,0.12); color: #4dabff; }
.go-option-icon.orange  { background: rgba(6,115,240,0.12); color: #4dabff; }
.go-option-icon.muted   { background: rgba(6,115,240,0.12); color: #4dabff; }
.go-option-icon.purple  { background: rgba(6,115,240,0.12); color: #4dabff; }

.go-option-text { flex: 1; min-width: 0; }
.go-option-title {
    font-size: 11.5px;
    font-weight: 600;
    color: rgba(255,255,255,0.88);
    line-height: 1.2;
}
.go-option-title.danger { color: #ff6b6b; }
.go-option-sub {
    font-size: 9.5px;
    color: rgba(255,255,255,0.32);
    margin-top: 2px;
    line-height: 1.35;
}
.go-option-arrow {
    color: rgba(255,255,255,0.2);
    font-size: 15px;
    flex-shrink: 0;
}

/* ── toggle switch ── */
.go-toggle-wrap {
    display: flex;
    align-items: center;
    flex-shrink: 0;
}
.go-toggle {
    position: relative;
    width: 36px;
    height: 20px;
    cursor: pointer;
}
.go-toggle input { opacity: 0; width: 0; height: 0; }
.go-toggle-slider {
    position: absolute;
    inset: 0;
    background: rgba(255,255,255,0.12);
    border-radius: 20px;
    transition: 0.28s;
    border: 1px solid rgba(255,255,255,0.1);
}
.go-toggle-slider::before {
    content: '';
    position: absolute;
    top: 2px; left: 2px;
    width: 14px; height: 14px;
    background: rgba(255,255,255,0.55);
    border-radius: 20px;
    transition: 0.28s;
}
.go-toggle input:checked + .go-toggle-slider { background: rgba(26,109,212,0.85); border-color: rgba(26,109,212,0.5); }
.go-toggle input:checked + .go-toggle-slider::before { transform: translateX(16px); background: #fff; }

/* ── divider ── */
.go-divider {
    border: none;
    border-top: 1px solid rgba(255,255,255,0.07);
    margin: 3px 0;
}

/* ══ MOBILE — bottom sheet, still sharp corners ══ */
@media (max-width: 790px) {
    #groupOptionsOverlay {
        align-items: flex-end;
        justify-content: center;
        padding: 0;
    }

    .go-card {
        border-radius: 0;
        max-width: 100%;
        width: 100%;
        animation: goSheetIn 0.3s cubic-bezier(0.22,1,0.36,1);
        border-bottom: none;
    }
    @keyframes goSheetIn {
        from { transform: translateY(100%); opacity: 0.6; }
        to   { transform: translateY(0);    opacity: 1; }
    }

    /* drag handle */
    .go-card::before {
        content: '';
        display: block;
        width: 30px; height: 3px;
        background: rgba(255,255,255,0.18);
        border-radius: 0;
        margin: 8px auto 0;
    }

    .go-header { padding: 11px 14px 9px; }
    .go-group-avatar { width: 32px; height: 32px; border-radius: 7px; }
    .go-group-name   { font-size: 11.5px; }
    .go-group-members{ font-size: 9px; }

    .go-option { padding: 10px 14px; gap: 10px; }
    .go-option-icon { width: 30px; height: 30px; border-radius: 7px; font-size: 13px; }
    .go-option-title { font-size: 11px; }
    .go-option-sub   { font-size: 9px; }
}

@media (max-width: 490px) {
    .go-header { padding: 12px 14px 10px; }
    .go-group-avatar { width: 34px; height: 34px; border-radius: 7px; }
    .go-group-name   { font-size: 12.5px; }
    .go-group-members{ font-size: 9.5px; }

    .go-option { padding: 11px 14px; gap: 10px; }
    .go-option-icon  { width: 31px; height: 31px; border-radius: 7px; font-size: 14px; }
    .go-option-title { font-size: 12px; }
    .go-option-sub   { font-size: 9.5px; }
    .go-option-arrow { font-size: 15px; }

    .go-toggle { width: 36px; height: 20px; }
    .go-toggle-slider::before { width: 14px; height: 14px; }
    .go-toggle input:checked + .go-toggle-slider::before { transform: translateX(16px); }
}

@media (max-width: 390px) {
    .go-header { padding: 11px 12px 9px; gap: 9px; }
    .go-group-avatar { width: 31px; height: 31px; border-radius: 7px; }
    .go-group-name   { font-size: 11.5px; }
    .go-group-members{ font-size: 9px; }
    .go-close-btn    { font-size: 11px; padding: 3px 6px; }

    .go-option { padding: 10px 12px; gap: 9px; }
    .go-option-icon  { width: 29px; height: 29px; border-radius: 6px; font-size: 13px; }
    .go-option-title { font-size: 11.5px; }
    .go-option-sub   { font-size: 9px; margin-top: 1px; }
    .go-option-arrow { font-size: 14px; }

    .go-toggle { width: 34px; height: 19px; }
    .go-toggle-slider::before { width: 13px; height: 13px; top: 2px; left: 2px; }
    .go-toggle input:checked + .go-toggle-slider::before { transform: translateX(15px); }

    .go-card::before { width: 28px; height: 3px; margin: 8px auto 0; }
}

@media (max-width: 330px) {
    .go-header { padding: 10px 11px 8px; gap: 8px; }
    .go-group-avatar { width: 27px; height: 27px; border-radius: 6px; border-width: 1.5px; }
    .go-group-name   { font-size: 10.5px; }
    .go-group-members{ font-size: 8.5px; }
    .go-close-btn    { font-size: 10px; padding: 3px 5px; }

    .go-option { padding: 9px 11px; gap: 8px; }
    .go-option-icon  { width: 27px; height: 27px; border-radius: 6px; font-size: 12px; }
    .go-option-title { font-size: 10.5px; }
    .go-option-sub   { font-size: 8.5px; }
    .go-option-arrow { font-size: 13px; }

    .go-toggle { width: 31px; height: 17px; }
    .go-toggle-slider::before { width: 11px; height: 11px; }
    .go-toggle input:checked + .go-toggle-slider::before { transform: translateX(14px); }

    .go-card::before { width: 25px; height: 2.5px; margin: 7px auto 0; }
}
</style>

<!-- ══ MOBILE STICKY WRAP — topbar + hashtag/sort bar, stacked with zero gap ══ -->
<div class="mobile-sticky-wrap" id="mobileStickyWrap">

    <div class="mobile-sticky-topbar" id="mobileStickyTopbar" style="--mst-banner: url('<?php echo htmlspecialchars($banner_src); ?>');">
        <button class="mst-btn mst-back" onclick="history.back()" aria-label="Back">
            <i class="ri-arrow-left-s-line"></i>
        </button>

        <div class="mst-title-wrap">
            <div class="mst-title">
                <?php
                $mst_title_full = $group['title'];
                echo mb_strlen($mst_title_full) > 30
                    ? htmlspecialchars(mb_substr($mst_title_full, 0, 30)) . '…'
                    : htmlspecialchars($mst_title_full);
                ?>
            </div>
            <div class="mst-subtitle" id="mstMemberCount">
                <?php echo number_format($member_count); ?> member<?php echo $member_count == 1 ? '' : 's'; ?>
            </div>
        </div>

        <div class="mst-actions">
            <button class="mst-btn" onclick="openGroupSearchModal()" aria-label="Search">
                <i class="ri-search-line"></i>
            </button>

            <?php if ($is_member): ?>
            <button class="mst-btn" onclick="openPostModal()" aria-label="New post">
                <i class="ri-add-line"></i>
            </button>
            <?php endif; ?>

            <button class="mst-btn" onclick="openGroupOptionsModal()" aria-label="More options">
                <i class="ri-more-fill"></i>
            </button>

            <?php if (!$is_member): ?>
                <?php if ($is_private_group && $my_request_status === 'pending'): ?>
                    <!-- Requested — tap to withdraw -->
                    <button class="mst-btn mst-join-btn mst-join-btn-requested" id="mstJoinBtn"
                            onclick="mstJoinGroup(this, 'cancel_request')" aria-label="Withdraw request">
                        <i class="ri-time-line"></i><span>Requested</span>
                    </button>

                <?php elseif ($is_private_group && $my_request_status === 'denied'): ?>
                    <!-- Denied — tap to re-request -->
                    <button class="mst-btn mst-join-btn mst-join-btn-denied" id="mstJoinBtn"
                            onclick="mstJoinGroup(this, 'join_group')" aria-label="Retry request">
                        <i class="ri-close-circle-line"></i><span>Retry</span>
                    </button>

                <?php else: ?>
                    <!-- No request yet — Join or Request to Join -->
                    <button class="mst-btn mst-join-btn" id="mstJoinBtn"
                            onclick="mstJoinGroup(this, 'join_group')" aria-label="Join pod">
                        <?php if ($is_private_group): ?>
                            <i class="ri-lock-line"></i><span>Request</span>
                        <?php else: ?>
                            <span>Join</span>
                        <?php endif; ?>
                    </button>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="mobile-hashtag-bar" id="mobileHashtagBar">
        <div class="ghc-scroll" id="ghcScroll">
        <button class="ghc-pill ghc-sort-pill" onclick="openSortSheet()">
                <i class="ri-arrow-up-down-line"></i> Sort: <span id="ghcSortLabel">Newest</span>
        </button>

            <?php if (empty($group_hashtags)): ?>
                <div class="ghc-pill ghc-none-pill">None</div>
            <?php else: ?>
                <?php foreach ($group_hashtags as $tag): ?>
                <button class="ghc-pill ghc-tag-pill"
                        data-tag="<?php echo htmlspecialchars($tag['tag']); ?>"
                        onclick="filterFeedByHashtag('<?php echo htmlspecialchars(addslashes($tag['tag'])); ?>', this)">
                    #<?php echo htmlspecialchars($tag['tag']); ?>
                </button>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

</div>

<style>
/* ── Requested / Retry state variants for the sticky join button ── */
.mst-btn.mst-join-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.mst-join-btn-requested {
    background: rgba(255,255,255,0.1) !important;
    color: rgba(255,255,255,0.75) !important;
}
.mst-join-btn-requested:hover { background: rgba(255,255,255,0.16) !important; }

.mst-join-btn-denied {
    background: rgba(255,45,45,0.18) !important;
    color: #ff8a8a !important;
}
.mst-join-btn-denied:hover { background: rgba(255,45,45,0.3) !important; }

/* ══════════════════════════════════════════════════
   STICKY WRAP — single fixed container, holds
   both bars stacked in normal flow (zero gap possible)
   Now enabled on ALL screen sizes, not just mobile.

   UPDATED: the wrap itself is now just a positioning
   shell with no background of its own. Each child bar
   owns its own background:
     - .mobile-sticky-topbar → blurred banner image
     - .mobile-hashtag-bar   → solid dark surface (unchanged look)
   This keeps the topbar's new banner-blur treatment
   fully scoped to the topbar only.
   ══════════════════════════════════════════════════ */
.mobile-sticky-wrap {
    display: flex;                 /* on by default now — was "none" */
    position: fixed;
    top: 0;
    left: 0;                       /* overridden on desktop below, to clear the sidebar */
    right: 0;
    z-index: 999999;
    flex-direction: column;
    transform: translateY(-100%) translateZ(0);
    opacity: 0;
    transition: transform 0.28s cubic-bezier(0.22,1,0.36,1), opacity 0.28s ease;
    pointer-events: none;
    backface-visibility: hidden;
}
.mobile-sticky-wrap.visible {
    transform: translateY(0) translateZ(0);
    opacity: 1;
    pointer-events: all;
    /* no background/blur here anymore — each child bar supplies its own */
}

/* ── DESKTOP: keep the bar clear of the fixed 100px sidebar ── */
@media (min-width: 769px) {
    .mobile-sticky-wrap { left: 100px; }
}

/* ══════════════════════════════════════════════════
   TOPBAR — blurred banner background
   The banner image is passed in via the --mst-banner
   CSS var (set inline on the element from PHP). A
   pseudo-element carries the blur so it never affects
   the actual buttons/text sitting on top of it, and is
   oversized + clipped so the blur edge never shows a
   lighter halo at the topbar boundary.
   ══════════════════════════════════════════════════ */
.mobile-sticky-topbar {
    position: relative;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px 12px 16px;
    padding-top: calc(env(safe-area-inset-top) + 12px);
    overflow: hidden;
    background: rgba(3,4,32,0.94); /* fallback if --mst-banner is ever empty */
}

.mobile-sticky-topbar::before {
    content: '';
    position: absolute;
    inset: 1px; /* less overscan needed since blur is lighter now */
    background-image: var(--mst-banner);
    background-size: cover;
    background-position: center 30%;
    filter: blur(6px) saturate(1.1);
    transform: scale(1.05);
    z-index: 0;
}

.mobile-sticky-topbar::after {
    content: '';
    position: absolute;
    inset: 0;
    background: rgba(3,4,32,0.72); /* dark tint for text legibility over the banner */
    z-index: 1;
}

.mobile-sticky-topbar > * {
    position: relative;
    z-index: 2;
}

.mst-btn {
    width: 44px; height: 44px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    background: rgba(255,255,255,0.08);
    border: none;
    color: #fff;
    font-size: 20px;
    cursor: pointer;
    flex-shrink: 0;
    transition: background 0.2s, transform 0.15s;
    -webkit-tap-highlight-color: transparent;
}
.mst-btn:hover  { background: rgba(255,255,255,0.14); }
.mst-btn:active { transform: scale(0.9); background: rgba(255,255,255,0.18); }
.mst-back {
    background: rgba(255,255,255,0.08);
    width: 40px; height: 40px;
    font-size: 24px;
    flex-shrink: 0;
}
.mst-back:hover  { background: rgba(255,255,255,0.14); opacity: 1; }
.mst-back:active { background: rgba(255,255,255,0.18); opacity: 1; transform: scale(0.9); }
.mst-title-wrap {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.mst-title {
    color: rgba(255,255,255,0.68);
    font-size: 12.5px;
    font-weight: 600;
    font-family: 'Poppins', sans-serif;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    line-height: 1.2;
}
.mst-subtitle {
    color: rgba(255,255,255,0.38);
    font-size: 10px;
    font-weight: 500;
    font-family: 'Poppins', sans-serif;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.mst-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
}
.mst-btn.mst-join-btn {
    width: auto !important;
    height: 38px !important;
    border-radius: 30px !important;
    padding: 0 18px !important;
    background: #045cc0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 12.5px;
    font-weight: 700;
    font-family: 'Poppins', sans-serif;
    white-space: nowrap;
}
.mst-join-btn:hover  { background: #0a6ad9; }
.mst-join-btn:active { background: #045cc0; transform: scale(0.95); }
.mst-join-btn.mst-joining { opacity: 0.6; pointer-events: none; }

/* ── HASHTAG + SORT BAR — unchanged solid dark surface, no banner blur ── */
.mobile-hashtag-bar {
    background: rgba(3,4,32,0.94);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    border-bottom: 1px solid rgba(255,255,255,0.08);
    padding: 8px 0;
}

.ghc-scroll {
    display: flex;
    align-items: center;
    gap: 7px;
    overflow-x: auto;
    scrollbar-width: none;
    -webkit-overflow-scrolling: touch;
    padding: 0 16px;
}
.ghc-scroll::-webkit-scrollbar { display: none; }

.ghc-pill {
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 12px;
    border-radius: 10px;
    background: rgba(77,171,255,0.1);
    border: 1px solid rgba(77,171,255,0.25);
    color: #1a4fe2;
    font-size: 11.5px;
    font-weight: 700;
    font-family: 'Poppins', sans-serif;
    cursor: pointer;
    white-space: nowrap;
    transition: background 0.2s, border-color 0.2s, transform 0.15s;
    -webkit-tap-highlight-color: transparent;
}
.ghc-pill:hover  { background: rgba(77,171,255,0.2); }
.ghc-pill:active { transform: scale(0.95); }

.ghc-none-pill {
    color: rgba(255,255,255,0.4);
    background: rgba(255,255,255,0.05);
    border-color: rgba(255,255,255,0.12);
    cursor: default;
}
.ghc-none-pill:hover { background: rgba(255,255,255,0.05); }

.ghc-tag-pill.active-tag {
    background: #2388e0;
    color: #06071A;
    border-color: #2388e0;
}

.ghc-sort-pill {
    margin-left: 1px;
    border-left: 1px solid rgba(255,255,255,0.1);
    padding-left: 13px;
}
.ghc-sort-pill i { font-size: 12px; }

@media (max-width: 768px) {
    .mobile-sticky-wrap { display: flex; }
}
@media (max-width: 480px) {
    .mobile-sticky-topbar { padding: 12px 16px 12px 14px; gap: 8px; }
    .mst-btn { width: 38px; height: 38px; font-size: 18px; }
    .mst-back { width: 37px; height: 37px; font-size: 21px; }
    .mst-title { font-size: 11.5px; }
    .mst-subtitle { font-size: 9.5px; }
    .mst-actions { gap: 6px; }
    .mst-btn.mst-join-btn { height: 30px !important; padding: 0 15px !important; font-size: 11.5px; }

    .mobile-hashtag-bar { padding: 7px 0; }
    .ghc-scroll { padding: 0 14px; gap: 6px; }
    .ghc-pill { padding: 5px 11px; font-size: 10.5px; border-radius: 9px; }
}
@media (max-width: 380px) {
    .mobile-sticky-topbar { padding: 12px 16px 12px 12px; gap: 7px; }
    .mst-btn { width: 34px; height: 34px; font-size: 16px; }
    .mst-back { width: 34px; height: 34px; font-size: 21px; }
    .mst-title { font-size: 11px; }
    .mst-subtitle { font-size: 8.5px; }
    .mst-actions { gap: 5px; }
    .mst-btn.mst-join-btn { height: 28px !important; padding: 0 12px !important; font-size: 10.5px; }

    .mobile-hashtag-bar { padding: 6px 0; }
    .ghc-scroll { padding: 0 12px; gap: 5px; }
    .ghc-pill { padding: 4px 9px; font-size: 10px; border-radius: 8px; }
}
</style>

<script>
/* ── MOBILE STICKY WRAP — show after scrolling past banner, hide near top ──
   Single toggle for the whole wrap; the two bars inside are static, so
   there's no positioning math and no possible gap between them. */
(function () {
    var wrap     = document.getElementById('mobileStickyWrap');
    var bannerEl = document.querySelector('.banner-container');
    if (!wrap || !bannerEl) return;

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
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
})();

/* ── STICKY TOPBAR JOIN — handles join_group, cancel_request states ──
   action: 'join_group'    -> join (public) or request-to-join / re-request (private)
           'cancel_request' -> withdraw a pending request                          */
function mstJoinGroup(btn, action) {
    if (btn.classList.contains('mst-joining')) return;

    if (action === 'cancel_request') {
        if (!confirm('Withdraw your request to join this pod?')) return;
    }

    btn.classList.add('mst-joining');

    var origHtml = btn.innerHTML;
    btn.innerHTML = '<i class="ri-loader-4-line" style="animation:mstSpin 0.7s linear infinite;"></i>';

    var fd = new URLSearchParams();
    fd.append(action, '1');
    fd.append('csrf_token', CSRF_TOKEN);

    fetch('group.php?id=<?php echo (int)$group_id; ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: fd.toString()
    })
    .then(function () {
        window.location.reload();
    })
    .catch(function () {
        btn.classList.remove('mst-joining');
        btn.innerHTML = origHtml;
    });
}

/* ══════════════════════════════════════════════════════════
   SHARED PERMANENT CARD LIST
   Both the hashtag filter and the sort feature (defined later
   on the page) need to enumerate every post card. If either
   feature physically removes cards from the DOM, a lazily-built
   cache elsewhere would only ever see whatever's left. So we
   share ONE lazy cache between both features, built the first
   time either one is actually used. Neither feature is allowed
   to delete nodes — only hide them via style.display — so once
   built, this list never goes stale.

   IMPORTANT: this must NOT be called eagerly here. #groupFeed
   and its .gf-card-unit cards live much further down the page
   and haven't been parsed into the DOM yet at this point in the
   script. Calling it now would permanently cache an empty list.
   It's safe to build lazily on first click, because by the time
   the user can click anything, the whole page has finished
   loading.
   ══════════════════════════════════════════════════════════ */
window._gfAllUnits = null;
window._gfGetAllUnits = function () {
    if (window._gfAllUnits) return window._gfAllUnits;
    var feed = document.getElementById('groupFeed');
    window._gfAllUnits = feed
        ? Array.prototype.slice.call(feed.querySelectorAll('.gf-card-unit'))
        : [];
    return window._gfAllUnits;
};

/* ══════════════════════════════════════════════════════════
   SHARED FILTER/SORT STATE
   Both the hashtag pills and the sort sheet (further down the
   page) write into these two variables and then call
   renderGroupFeedSort(), so a tag pick and a sort pick always
   compose correctly no matter which order the user clicks them
   in. Defined here (not down in the sort script) so the
   hashtag pills can reference them immediately.
   ══════════════════════════════════════════════════════════ */
window._gfActiveTag   = null;      // lowercase tag string, or null
window._gfCurrentSort = 'newest';  // last applied sort/type/social value

/* ── HASHTAG FILTER — updates shared state, delegates rendering ── */
function filterFeedByHashtag(tag, btn) {
    var feed = document.getElementById('groupFeed');
    if (!feed || typeof peuraGroupFeed === 'undefined') return;

    var wasActive = btn.classList.contains('active-tag');
    document.querySelectorAll('.ghc-tag-pill').forEach(function (p) {
        p.classList.remove('active-tag');
    });

    if (wasActive) {
        window._gfActiveTag = null;
    } else {
        btn.classList.add('active-tag');
        window._gfActiveTag = tag.toLowerCase();
    }

    /* renderGroupFeedSort is defined in the sort script further down
       the page; by the time this can be called (user interaction),
       the whole page — including that script — has already run. */
    if (typeof renderGroupFeedSort === 'function') {
        renderGroupFeedSort(window._gfCurrentSort);
    }
}

/* ── Keep the sticky bar's "Sort: X" label synced with the main sort pill ── */
(function () {
    var src = document.getElementById('sortBarValue');
    var dst = document.getElementById('ghcSortLabel');
    if (!src || !dst) return;
    var mo = new MutationObserver(function () { dst.textContent = src.textContent; });
    mo.observe(src, { childList: true, characterData: true, subtree: true });
})();
</script>
<style>
@keyframes mstSpin { to { transform: rotate(360deg); } }
</style>


<!-- ══ GROUP OPTIONS MODAL ══ -->
<div id="groupOptionsOverlay" onclick="if(event.target===this) closeGroupOptionsModal()">
    <div class="go-card">

        <!-- Header -->
        <div class="go-header">
            <img class="go-group-avatar"
                 src="<?php echo htmlspecialchars($img_src); ?>"
                 onerror="this.src='uploads/default_group.png'" alt="">
            <div style="flex:1;min-width:0;">
                <div class="go-group-name"><?php echo htmlspecialchars($group['title']); ?></div>
                <div class="go-group-members">
                    <i class="ri-team-line" style="font-size:11px;"></i>
                    <?php echo number_format($member_count); ?> members
                </div>
            </div>
            <button class="go-close-btn" onclick="closeGroupOptionsModal()" aria-label="Close">Close</button>
        </div>

        <!-- Mute group -->
        <div class="go-option" onclick="goToggleMute()">
            <div class="go-option-icon orange">
                <i class="ri-volume-mute-line"></i>
            </div>
            <div class="go-option-text">
                <div class="go-option-title" id="goMuteTitle">
                    Mute <?php echo htmlspecialchars($group['title']); ?>
                </div>
                <div class="go-option-sub" id="goMuteSub">Silence all posts and activity from this pod</div>
            </div>
            <div class="go-toggle-wrap" onclick="event.stopPropagation()">
                <label class="go-toggle" aria-label="Mute group">
                    <input type="checkbox" id="goMuteToggle" onchange="goToggleMute()">
                    <span class="go-toggle-slider"></span>
                </label>
            </div>
        </div>

        <!-- Notifications -->
        <div class="go-option" onclick="goToggleNotifs()">
            <div class="go-option-icon blue">
                <i class="ri-notification-off-line" id="goNotifIcon"></i>
            </div>
            <div class="go-option-text">
                <div class="go-option-title" id="goNotifTitle">Turn off This Pod notifications</div>
                <div class="go-option-sub" id="goNotifSub">Stop receiving any alerts from <?php echo htmlspecialchars($group['title']); ?></div>
            </div>
            <div class="go-toggle-wrap" onclick="event.stopPropagation()">
                <label class="go-toggle" aria-label="Group notifications">
                    <input type="checkbox" id="goNotifToggle" onchange="goToggleNotifs()">
                    <span class="go-toggle-slider"></span>
                </label>
            </div>
        </div>

        <hr class="go-divider">

        <!-- Share -->
        <div class="go-option" onclick="goShareGroup()">
            <div class="go-option-icon purple">
                <i class="ri-share-line"></i>
            </div>
            <div class="go-option-text">
                <div class="go-option-title">Share this pod</div>
                <div class="go-option-sub">Invite others by sharing a link</div>
            </div>
            <i class="ri-arrow-right-s-line go-option-arrow"></i>
        </div>

        <!-- Copy link -->
        <div class="go-option" onclick="goCopyLink()">
            <div class="go-option-icon muted">
                <i class="ri-links-line"></i>
            </div>
            <div class="go-option-text">
                <div class="go-option-title" id="goCopyLinkTitle">Copy pod link</div>
                <div class="go-option-sub">Share the direct link to this pod</div>
            </div>
            <i class="ri-arrow-right-s-line go-option-arrow"></i>
        </div>

        <hr class="go-divider">

        <!-- Report (only for non-owners) -->
        <?php if (!$is_owner): ?>
        <div class="go-option" onclick="closeGroupOptionsModal(); setTimeout(()=>openReportGroupModal(<?php echo (int)$group_id; ?>), 220)">
            <div class="go-option-icon red">
                <i class="ri-flag-line"></i>
            </div>
            <div class="go-option-text">
                <div class="go-option-title danger">Report this pod</div>
                <div class="go-option-sub">Flag this group for review</div>
            </div>
            <i class="ri-arrow-right-s-line go-option-arrow"></i>
        </div>
        <?php endif; ?>

        <!-- Leave (only for members who aren't owner) -->
        <?php if ($is_member && !$is_owner): ?>
        <div class="go-option" onclick="goLeaveGroup()">
            <div class="go-option-icon red">
                <i class="ri-logout-box-line"></i>
            </div>
            <div class="go-option-text">
                <div class="go-option-title danger">Leave pod</div>
                <div class="go-option-sub">You can rejoin at any time</div>
            </div>
            <i class="ri-arrow-right-s-line go-option-arrow"></i>
        </div>
        <?php endif; ?>

    </div>
</div>

<!-- ══ TOAST for copy feedback ══ -->
<div id="goToast" style="
    display:none;
    position:fixed;
    bottom:90px;
    left:50%;
    transform:translateX(-50%) translateY(8px);
    background:rgba(10,10,28,0.97);
    border:1px solid rgba(255,255,255,0.1);
    color:#fff;
    padding:9px 20px;
    border-radius:50px;
    font-size:12.5px;
    font-family:'Poppins',sans-serif;
    font-weight:500;
    z-index:9999999999;
    white-space:nowrap;
    box-shadow:0 8px 28px rgba(0,0,0,0.6);
    pointer-events:none;
    opacity:0;
    transition:opacity 0.22s ease, transform 0.22s ease;
"></div>

<script>
/* ══ GROUP OPTIONS MODAL ══ */
var _goOpen      = false;
var _goMuted     = false;
var _goNotifsOff = false;
var GO_GROUP_ID   = <?php echo (int)$group_id; ?>;
var GO_GROUP_NAME = <?php echo json_encode(htmlspecialchars($group['title'])); ?>;

/* ── Load saved states on page ready ── */
(function goLoadStates() {
    fetch('check_group_mute.php?group_id=' + GO_GROUP_ID)
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.muted) {
                _goMuted = true;
                var chk   = document.getElementById('goMuteToggle');
                var title = document.getElementById('goMuteTitle');
                var sub   = document.getElementById('goMuteSub');
                if (chk)   chk.checked    = true;
                if (title) title.textContent = 'Unmute ' + GO_GROUP_NAME;
                if (sub)   sub.textContent   = 'Posts and activity from this pod are silenced';
            }
            if (data.notifs_off) {
                _goNotifsOff = true;
                var nChk   = document.getElementById('goNotifToggle');
                var nTitle = document.getElementById('goNotifTitle');
                var nSub   = document.getElementById('goNotifSub');
                var nIcon  = document.getElementById('goNotifIcon');
                if (nChk)   nChk.checked    = true;
                if (nTitle) nTitle.textContent = 'Turn on group notifications';
                if (nSub)   nSub.textContent   = 'You\'re not receiving any alerts from ' + GO_GROUP_NAME;
                if (nIcon)  nIcon.className     = 'ri-notification-line';
            }
        })
        .catch(function () {});
})();

/* ── Open ── */
function openGroupOptionsModal() {
    if (_goOpen) return;
    _goOpen = true;
    var ov = document.getElementById('groupOptionsOverlay');
    ov.classList.add('go-open');
    document.body.style.overflow = 'hidden';
}

/* ── Close ── */
function closeGroupOptionsModal() {
    if (!_goOpen) return;
    _goOpen = false;
    var ov   = document.getElementById('groupOptionsOverlay');
    var card = ov.querySelector('.go-card');
    var isMobile = window.innerWidth <= 768;

    /* inject out-keyframes once */
    if (!document.getElementById('go-out-anim')) {
        var s = document.createElement('style');
        s.id  = 'go-out-anim';
        s.textContent =
            '@keyframes goSheetOut{to{transform:translateY(100%);opacity:0.4}}' +
            '@keyframes goCardOut{to{transform:scale(0.93) translateY(10px);opacity:0}}';
        document.head.appendChild(s);
    }

    card.style.animation = isMobile
        ? 'goSheetOut 0.22s ease forwards'
        : 'goCardOut 0.2s ease forwards';

    setTimeout(function () {
        ov.classList.remove('go-open');
        card.style.animation = '';
        document.body.style.overflow = '';
    }, 220);
}

/* ── Mute toggle ── */
function goToggleMute() {
    _goMuted = !_goMuted;

    var chk   = document.getElementById('goMuteToggle');
    var title = document.getElementById('goMuteTitle');
    var sub   = document.getElementById('goMuteSub');
    if (chk) chk.checked = _goMuted;

    if (_goMuted) {
        if (title) title.textContent = 'Unmute ' + GO_GROUP_NAME;
        if (sub)   sub.textContent   = 'Posts and activity from this pod are silenced';
        goShowToast('Pod muted — posts won\'t appear in your feeds');
    } else {
        if (title) title.textContent = 'Mute ' + GO_GROUP_NAME;
        if (sub)   sub.textContent   = 'Silence all posts and activity from this pod';
        goShowToast('Pod unmuted — posts will appear in your feeds again');
    }

    fetch('group.php?id=' + GO_GROUP_ID, {
        method:  'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body:    'mute_group=1&muted=' + (_goMuted ? 1 : 0) + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (!data.ok) {
            /* revert on failure */
            _goMuted = !_goMuted;
            if (chk) chk.checked = _goMuted;
            goShowToast('Something went wrong — try again');
        }
    })
    .catch(function () {
        _goMuted = !_goMuted;
        if (chk) chk.checked = _goMuted;
        goShowToast('Network error — try again');
    });
}

/* ── Notifications toggle ── */
function goToggleNotifs() {
    _goNotifsOff = !_goNotifsOff;

    var chk   = document.getElementById('goNotifToggle');
    var title = document.getElementById('goNotifTitle');
    var sub   = document.getElementById('goNotifSub');
    var icon  = document.getElementById('goNotifIcon');
    if (chk) chk.checked = _goNotifsOff;

    if (_goNotifsOff) {
        if (title) title.textContent = 'Turn on group notifications';
        if (sub)   sub.textContent   = 'You\'re not receiving any alerts from ' + GO_GROUP_NAME;
        if (icon)  icon.className     = 'ri-notification-line';
        goShowToast('Notifications off for this pod');
    } else {
        if (title) title.textContent = 'Turn off group notifications';
        if (sub)   sub.textContent   = 'Stop receiving any alerts from ' + GO_GROUP_NAME;
        if (icon)  icon.className     = 'ri-notification-off-line';
        goShowToast('Notifications turned back on');
    }

    fetch('group.php?id=' + GO_GROUP_ID, {
        method:  'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body:    'notif_group=1&off=' + (_goNotifsOff ? 1 : 0) + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (!data.ok) {
            _goNotifsOff = !_goNotifsOff;
            if (chk) chk.checked = _goNotifsOff;
            goShowToast('Something went wrong — try again');
        }
    })
    .catch(function () {
        _goNotifsOff = !_goNotifsOff;
        if (chk) chk.checked = _goNotifsOff;
        goShowToast('Network error — try again');
    });
}

/* ── Share ── */
function goShareGroup() {
    var url = window.location.origin + window.location.pathname + '?id=' + GO_GROUP_ID;
    if (navigator.share) {
        navigator.share({
            title: GO_GROUP_NAME,
            text:  'Check out ' + GO_GROUP_NAME + ' on Dezzme!',
            url:   url
        }).catch(function () {});
    } else {
        goCopyLink();
    }
}

/* ── Copy link ── */
function goCopyLink() {
    var url       = window.location.origin + window.location.pathname + '?id=' + GO_GROUP_ID;
    var titleEl   = document.getElementById('goCopyLinkTitle');
    var original  = titleEl ? titleEl.textContent : 'Copy pod link';

    if (!navigator.clipboard) {
        /* fallback for older browsers */
        var ta = document.createElement('textarea');
        ta.value = url;
        ta.style.cssText = 'position:fixed;opacity:0;';
        document.body.appendChild(ta);
        ta.select();
        try {
            document.execCommand('copy');
            goShowToast('Link copied to clipboard!');
            if (titleEl) {
                titleEl.textContent = 'Link copied!';
                setTimeout(function () { titleEl.textContent = original; }, 2400);
            }
        } catch (e) {
            goShowToast('Could not copy link');
        }
        document.body.removeChild(ta);
        return;
    }

    navigator.clipboard.writeText(url)
        .then(function () {
            goShowToast('Link copied to clipboard!');
            if (titleEl) {
                titleEl.textContent = 'Link copied!';
                setTimeout(function () { titleEl.textContent = original; }, 2400);
            }
        })
        .catch(function () {
            goShowToast('Could not copy link');
        });
}

/* ── Leave group ── */
function goLeaveGroup() {
    if (!confirm('Leave ' + GO_GROUP_NAME + '? You can rejoin at any time.')) return;
    closeGroupOptionsModal();
    var f   = document.createElement('form');
    f.method = 'POST';
    f.action = 'group.php?id=' + GO_GROUP_ID;
    var inp  = document.createElement('input');
    inp.type  = 'hidden';
    inp.name  = 'leave_group';
    inp.value = '1';
    f.appendChild(inp);
    var csrf = document.createElement('input');
    csrf.type  = 'hidden';
    csrf.name  = 'csrf_token';
    csrf.value = CSRF_TOKEN;
    f.appendChild(csrf);
    document.body.appendChild(f);
    f.submit();
}

/* ── Toast ── */
function goShowToast(msg) {
    var t = document.getElementById('goToast');
    if (!t) return;
    t.textContent = msg;
    t.style.display = 'block';
    clearTimeout(t._tid);
    requestAnimationFrame(function () {
        t.style.opacity   = '1';
        t.style.transform = 'translateX(-50%) translateY(0)';
    });
    t._tid = setTimeout(function () {
        t.style.opacity   = '0';
        t.style.transform = 'translateX(-50%) translateY(8px)';
        setTimeout(function () { t.style.display = 'none'; }, 230);
    }, 2600);
}

/* ── ESC key ── */
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && _goOpen) closeGroupOptionsModal();
});

/* ── Mobile swipe-down to close ── */
(function () {
    var startY = 0;
    var ov = document.getElementById('groupOptionsOverlay');
    if (!ov) return;
    ov.addEventListener('touchstart', function (e) {
        startY = e.touches[0].clientY;
    }, { passive: true });
    ov.addEventListener('touchend', function (e) {
        if (e.changedTouches[0].clientY - startY > 60) closeGroupOptionsModal();
    }, { passive: true });
})();
</script>

<!-- Group Pic Modal -->
<div id="groupPicModal" class="group-pic-modal" onclick="closeGroupPicModal()">
    <button class="group-pic-modal-close" onclick="closeGroupPicModal()">&times;</button>
    <div class="group-pic-modal-content" onclick="event.stopPropagation()">
        <img id="groupPicModalImg" src="<?php echo $img_src; ?>" 
             onerror="this.src='uploads/default_group.png';" alt="Group Picture">
    </div>
</div>


    <!-- BODY -->
    <div class="gh-body">

        <!-- TITLE + KEBAB -->
        <div class="gh-top">
            <!-- REPLACE your existing .gh-name div with this entire block -->

<div class="gh-name">
    <h1 onclick="openPodInfoModal()" style="cursor:pointer;" title="View Pod details">
        <?php echo htmlspecialchars($group['title']); ?>
    </h1>
    <button class="pod-chevron-btn" onclick="openPodInfoModal()" title="View Pod details" aria-label="View Pod info">
        <i class="ri-arrow-right-s-line"></i>
    </button>
</div>


<!-- ══════════════════════════════════════════
     POD INFO MODAL — full-screen community hub
══════════════════════════════════════════ -->
<!-- ══════════════════════════════════════════
     POD INFO MODAL — full page details view
══════════════════════════════════════════ -->
<style>
/* ── Chevron Button ── */
.pod-chevron-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    border-radius: 50%;
    border: none;
    background: transparent;
    color: rgba(255,255,255,0.6);
    font-size: 21px;
    cursor: pointer;
    flex-shrink: 0;
    transition: color 0.22s ease, transform 0.22s ease;
    -webkit-tap-highlight-color: transparent;
    margin-left: -9px;
}
.pod-chevron-btn:hover {
    color: #fff;
    transform: scale(1.1);
}
.pod-chevron-btn:active { transform: scale(0.93); }

@media (max-width: 480px) {
    .pod-chevron-btn {
        width: 22px;
        height: 22px;
        font-size: 15px;
    }
}

@media (max-width: 320px) {
    .pod-chevron-btn {
        width: 20px;
        height: 20px;
        font-size: 14px;
        margin-left: 0;
    }
}

/* ══ FULL PAGE MODAL ══ */
#podInfoModal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 999999999;
    background: #030420;
    overflow-y: auto;
    overflow-x: hidden;
    scrollbar-width: thin;
    scrollbar-color: rgba(255,255,255,0.12) transparent;
    flex-direction: column;
}
#podInfoModal::-webkit-scrollbar { width: 4px; }
#podInfoModal::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.12); border-radius: 99px; }
#podInfoModal.open {
    display: flex;
    animation: podPageIn 0.28s cubic-bezier(0.22,1,0.36,1);
}
@keyframes podPageIn {
    from { opacity: 0; transform: translateY(18px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* ── Top Bar ── */
.pim-topbar {
    position: sticky;
    top: 0;
    z-index: 10;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 20px;
    background: rgba(3,4,32,0.96);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    border-bottom: 1px solid rgba(255,255,255,0.07);
    flex-shrink: 0;
}
.pim-back-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px; height: 36px;
    border-radius: 50%;
    border: 1px solid rgba(255,255,255,0.14);
    background: rgba(255,255,255,0.06);
    color: #fff;
    font-size: 20px;
    cursor: pointer;
    transition: 0.2s;
    flex-shrink: 0;
    -webkit-tap-highlight-color: transparent;
}
.pim-back-btn:hover  { background: rgba(255,255,255,0.13); color: #fff; }
.pim-back-btn:active { transform: scale(0.9); }

.pim-topbar-title {
    flex: 1;
    min-width: 0;
    font-size: 15px;
    font-weight: 700;
    color: #fff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    font-family: 'Poppins', sans-serif;
}
.pim-topbar-sub {
    font-size: 11px;
    color: rgba(255,255,255,0.38);
    font-weight: 400;
    margin-top: 1px;
    font-family: 'Poppins', sans-serif;
}

/* ── Hero Banner ── */
.pim-hero {
    position: relative;
    width: 100%;
    height: clamp(160px, 28vw, 260px);
    flex-shrink: 0;
    overflow: hidden;
}
.pim-hero-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    opacity: 0.55;
}
.pim-hero-fade {
    position: absolute;
    inset: 0;
    background: linear-gradient(to bottom, transparent 30%, #030420 100%);
}
.pim-hero-text {
    padding-bottom: 6px;
    flex: 1;
    min-width: 0;
}
.pim-pod-name {
    font-size: clamp(17px, 4vw, 24px);
    font-weight: 700;
    color: #fff;
    font-family: 'Poppins', sans-serif;
    display: flex;
    align-items: center;
    gap: 7px;
    flex-wrap: wrap;
    line-height: 1.2;
}
.pim-pod-date {
    font-size: 12px;
    color: rgba(255,255,255,0.42);
    margin-top: 4px;
    font-family: 'Poppins', sans-serif;
    display: flex;
    align-items: center;
    gap: 4px;
}

/* ── Content Area ── */
.pim-content {
    padding: clamp(36px, 8vw, 50px) clamp(16px, 4vw, 28px) 48px;
    flex: 1;
}

/* ── Section heading ── */
.pim-section-head {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 1px;
    color: rgba(255,255,255,0.35);
    text-transform: uppercase;
    font-family: 'Poppins', sans-serif;
    margin: 28px 0 12px;
    padding-bottom: 8px;
    border-bottom: 1px solid rgba(255,255,255,0.07);
}
.pim-section-head:first-child { margin-top: 0; }
.pim-section-head i { font-size: 14px; }

/* ── Details Table ── */
.pim-details-table {
    width: 100%;
    border-collapse: collapse;
}
.pim-details-table tr {
    border-bottom: 1px solid rgba(255,255,255,0.05);
    transition: background 0.15s;
}
.pim-details-table tr:last-child { border-bottom: none; }
.pim-details-table tr:hover { background: rgba(255,255,255,0.03); }
.pim-details-table td {
    padding: 11px 10px;
    font-family: 'Poppins', sans-serif;
    vertical-align: middle;
}
.pim-dt-icon { width: 38px; text-align: center; }
.pim-dt-icon i { font-size: 18px; color: rgba(255,255,255,0.3); }
.pim-dt-label {
    width: 38%;
    font-size: 12px;
    color: rgba(255,255,255,0.42);
    font-weight: 500;
    white-space: nowrap;
}
.pim-dt-value {
    font-size: 13px;
    color: rgba(255,255,255,0.9);
    font-weight: 600;
    text-align: right;
}
.pim-dt-value.accent { color: #106dde; }
.pim-dt-value.green  { color: #106dde; }
.pim-dt-value.gold   { color: #106dde; }
.pim-dt-value.red    { color: #106dde; }

/* ── Stat Strip ── */
.pim-stat-strip {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
    margin-bottom: 4px;
}
.pim-stat-block {
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 16px;
    padding: 14px 8px;
    text-align: center;
}
.pim-stat-num {
    font-size: clamp(18px, 4vw, 24px);
    font-weight: 700;
    color: #fff;
    font-family: 'Poppins', sans-serif;
    line-height: 1;
    display: block;
}
.pim-stat-lbl {
    font-size: 10px;
    color: rgba(255,255,255,0.35);
    margin-top: 5px;
    display: block;
    font-family: 'Poppins', sans-serif;
}

/* ── Activity Strip ── */
.pim-activity-strip {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
}
.pim-activity-block {
    background: rgba(6,115,240,0.09);
    border: 1px solid rgba(6,115,240,0.22);
    border-radius: 14px;
    padding: 13px 8px;
    text-align: center;
}
.pim-activity-num {
    font-size: clamp(16px, 3.5vw, 20px);
    font-weight: 700;
    color: #4dabff;
    font-family: 'Poppins', sans-serif;
    display: block;
    line-height: 1;
}
.pim-activity-lbl {
    font-size: 10px;
    color: rgba(77,171,255,0.6);
    margin-top: 5px;
    display: block;
    font-family: 'Poppins', sans-serif;
}

/* ── Creator Strip ── */
.pim-creator-row {
    display: flex;
    align-items: center;
    gap: 12px;
    background: rgba(255,45,45,0.06);
    border: 1px solid rgba(255,45,45,0.18);
    border-radius: 16px;
    padding: 13px 15px;
    text-decoration: none;
    transition: background 0.2s;
}
.pim-creator-row:hover { background: rgba(255,45,45,0.12); }
.pim-creator-row img {
    width: 48px; height: 48px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #FF2D2D;
    flex-shrink: 0;
}
.pim-creator-label { font-size: 10px; color: rgba(255,255,255,0.38); font-family: 'Poppins', sans-serif; }
.pim-creator-name  { font-size: 14px; font-weight: 700; color: #fff; font-family: 'Poppins', sans-serif; margin-top: 2px; }

/* ── People Row ── */
.pim-people-row {
    display: flex;
    gap: 14px;
    overflow-x: auto;
    scrollbar-width: none;
    padding-bottom: 4px;
}
.pim-people-row::-webkit-scrollbar { display: none; }
.pim-person {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    flex-shrink: 0;
    min-width: 56px;
    text-decoration: none;
    transition: 0.2s;
}
.pim-person:hover { transform: translateY(-3px); }
.pim-person img {
    width: 50px; height: 50px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #FF2D2D;
    display: block;
}
.pim-person-name {
    font-size: 11px;
    font-weight: 600;
    color: rgba(255,255,255,0.78);
    font-family: 'Poppins', sans-serif;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 56px;
    text-align: center;
}
.pim-person-role {
    font-size: 9px;
    color: #FFD700;
    font-weight: 700;
    margin-top: -4px;
    font-family: 'Poppins', sans-serif;
}

/* ── Tags ── */
.pim-tags-wrap {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.pim-tag {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 6px 13px;
    border-radius: 30px;
    background: rgba(77,171,255,0.09);
    border: 1px solid rgba(77,171,255,0.22);
    color: #4dabff;
    font-size: 12px;
    font-weight: 600;
    font-family: 'Poppins', sans-serif;
    cursor: pointer;
    transition: 0.18s;
    -webkit-tap-highlight-color: transparent;
}
.pim-tag:hover { background: rgba(77,171,255,0.2); }

/* ── Similar Pods ── */
.pim-similar-list { display: flex; flex-direction: column; gap: 8px; }
.pim-similar-row {
    display: flex;
    align-items: center;
    gap: 12px;
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.07);
    border-radius: 14px;
    padding: 11px 14px;
    text-decoration: none;
    transition: 0.18s;
    cursor: pointer;
}
.pim-similar-row:hover { background: rgba(255,255,255,0.07); transform: translateX(3px); }
.pim-similar-img {
    width: 42px; height: 42px;
    border-radius: 11px;
    object-fit: cover;
    border: 2px solid rgba(255,45,45,0.45);
    flex-shrink: 0;
}
.pim-similar-name {
    font-size: 13px;
    font-weight: 700;
    color: #fff;
    font-family: 'Poppins', sans-serif;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.pim-similar-meta {
    font-size: 11px;
    color: rgba(255,255,255,0.38);
    margin-top: 2px;
    font-family: 'Poppins', sans-serif;
    display: flex;
    align-items: center;
    gap: 4px;
}
.pim-similar-arrow {
    margin-left: auto;
    color: rgba(255,255,255,0.22);
    font-size: 18px;
    flex-shrink: 0;
}

/* ── Description Box ── */
.pim-desc-box {
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.07);
    border-radius: 14px;
    padding: 14px 16px;
    font-size: 13px;
    color: rgba(255,255,255,0.65);
    line-height: 1.65;
    font-family: 'Poppins', sans-serif;
    word-break: break-word;
}


/* ════════════════════════════════════════
   RESPONSIVE — 770px
   ════════════════════════════════════════ */
@media (max-width: 770px) {
    .pim-topbar            { padding: 11px 16px; gap: 10px; }
    .pim-back-btn          { width: 32px; height: 32px; font-size: 17px; }
    .pim-topbar-title      { font-size: 13px; }
    .pim-topbar-sub        { font-size: 10px; }

    .pim-content           { padding: 28px 14px 40px; }

    .pim-section-head      { font-size: 10px; margin: 22px 0 10px; padding-bottom: 7px; }
    .pim-section-head i    { font-size: 12px; }

    .pim-details-table td  { padding: 9px 8px; }
    .pim-dt-icon i         { font-size: 16px; }
    .pim-dt-label          { font-size: 11px; }
    .pim-dt-value          { font-size: 12px; }

    .pim-stat-block        { padding: 12px 6px; border-radius: 13px; }
    .pim-stat-lbl          { font-size: 9px; margin-top: 4px; }

    .pim-activity-block    { padding: 11px 6px; border-radius: 12px; }
    .pim-activity-lbl      { font-size: 9px; margin-top: 4px; }

    .pim-creator-row       { padding: 11px 13px; gap: 10px; border-radius: 13px; }
    .pim-creator-row img   { width: 42px; height: 42px; }
    .pim-creator-label     { font-size: 9px; }
    .pim-creator-name      { font-size: 13px; }

    .pim-person img        { width: 44px; height: 44px; }
    .pim-person-name       { font-size: 10px; max-width: 50px; }
    .pim-person-role       { font-size: 8px; }

    .pim-tag               { padding: 5px 11px; font-size: 11px; }

    .pim-similar-row       { padding: 9px 12px; gap: 10px; border-radius: 12px; }
    .pim-similar-img       { width: 38px; height: 38px; border-radius: 9px; }
    .pim-similar-name      { font-size: 12px; }
    .pim-similar-meta      { font-size: 10px; }
    .pim-similar-arrow     { font-size: 16px; }

    .pim-desc-box          { padding: 12px 14px; font-size: 12px; border-radius: 12px; }

    .pim-pod-name          { font-size: clamp(15px, 3.5vw, 20px); }
    .pim-pod-date          { font-size: 11px; }
}


/* ════════════════════════════════════════
   RESPONSIVE — 480px
   ════════════════════════════════════════ */
@media (max-width: 480px) {
    .pim-topbar            { padding: 10px 14px; gap: 9px; }
    .pim-back-btn          { width: 32px; height: 32px; font-size: 17px; }
    .pim-topbar-title      { font-size: 13px; }
    .pim-topbar-sub        { font-size: 10px; }

    .pim-content           { padding: 22px 13px 36px; }

    .pim-section-head      { font-size: 9px; letter-spacing: 0.9px; margin: 20px 0 9px; padding-bottom: 6px; }
    .pim-section-head i    { font-size: 11px; }

    .pim-details-table td  { padding: 8px 6px; }
    .pim-dt-icon           { width: 30px; }
    .pim-dt-icon i         { font-size: 14px; }
    .pim-dt-label          { font-size: 11px; }
    .pim-dt-value          { font-size: 12px; }

    .pim-stat-strip        { gap: 7px; }
    .pim-stat-block        { padding: 11px 5px; border-radius: 12px; }
    .pim-stat-lbl          { font-size: 9px; margin-top: 4px; }

    .pim-activity-strip    { gap: 7px; }
    .pim-activity-block    { padding: 10px 5px; border-radius: 11px; }
    .pim-activity-lbl      { font-size: 9px; margin-top: 4px; }

    .pim-creator-row       { padding: 10px 12px; gap: 9px; border-radius: 13px; }
    .pim-creator-row img   { width: 40px; height: 40px; }
    .pim-creator-label     { font-size: 9px; }
    .pim-creator-name      { font-size: 13px; }

    .pim-people-row        { gap: 11px; }
    .pim-person img        { width: 42px; height: 42px; }
    .pim-person-name       { font-size: 10px; max-width: 48px; }
    .pim-person-role       { font-size: 8px; }

    .pim-tag               { padding: 5px 10px; font-size: 11px; gap: 3px; }

    .pim-similar-row       { padding: 8px 11px; gap: 9px; border-radius: 11px; }
    .pim-similar-img       { width: 36px; height: 36px; border-radius: 8px; }
    .pim-similar-name      { font-size: 12px; }
    .pim-similar-meta      { font-size: 10px; }
    .pim-similar-arrow     { font-size: 16px; }

    .pim-desc-box          { padding: 11px 13px; font-size: 12px; border-radius: 11px; line-height: 1.6; }

    .pim-pod-name          { font-size: clamp(14px, 3.5vw, 18px); gap: 5px; }
    .pim-pod-date          { font-size: 11px; }
}


/* ════════════════════════════════════════
   RESPONSIVE — 380px
   ════════════════════════════════════════ */
@media (max-width: 380px) {
    .pim-topbar            { padding: 9px 12px; gap: 8px; }
    .pim-back-btn          { width: 30px; height: 30px; font-size: 16px; }
    .pim-topbar-title      { font-size: 12px; }
    .pim-topbar-sub        { font-size: 9px; }

    .pim-content           { padding: 18px 12px 32px; }

    .pim-section-head      { font-size: 9px; letter-spacing: 0.8px; margin: 17px 0 8px; padding-bottom: 6px; }
    .pim-section-head i    { font-size: 11px; }

    .pim-details-table td  { padding: 7px 5px; }
    .pim-dt-icon           { width: 26px; }
    .pim-dt-icon i         { font-size: 13px; }
    .pim-dt-label          { font-size: 10px; }
    .pim-dt-value          { font-size: 11px; }

    .pim-stat-strip        { gap: 6px; }
    .pim-stat-block        { padding: 10px 4px; border-radius: 11px; }
    .pim-stat-lbl          { font-size: 9px; margin-top: 3px; }

    .pim-activity-strip    { gap: 6px; }
    .pim-activity-block    { padding: 9px 4px; border-radius: 10px; }
    .pim-activity-lbl      { font-size: 9px; margin-top: 3px; }

    .pim-creator-row       { padding: 9px 11px; gap: 8px; border-radius: 12px; }
    .pim-creator-row img   { width: 38px; height: 38px; }
    .pim-creator-label     { font-size: 9px; }
    .pim-creator-name      { font-size: 12px; }

    .pim-people-row        { gap: 10px; }
    .pim-person img        { width: 40px; height: 40px; }
    .pim-person-name       { font-size: 10px; max-width: 44px; }
    .pim-person-role       { font-size: 8px; }

    .pim-tag               { padding: 5px 10px; font-size: 11px; gap: 3px; }

    .pim-similar-row       { padding: 8px 10px; gap: 8px; border-radius: 10px; }
    .pim-similar-img       { width: 34px; height: 34px; border-radius: 8px; }
    .pim-similar-name      { font-size: 12px; }
    .pim-similar-meta      { font-size: 10px; }
    .pim-similar-arrow     { font-size: 15px; }

    .pim-desc-box          { padding: 10px 12px; font-size: 12px; border-radius: 10px; line-height: 1.6; }

    .pim-pod-name          { font-size: clamp(13px, 3vw, 17px); gap: 5px; }
    .pim-pod-date          { font-size: 10px; }
}


/* ════════════════════════════════════════
   RESPONSIVE — 330px
   ════════════════════════════════════════ */
@media (max-width: 330px) {
    .pim-topbar            { padding: 8px 11px; gap: 7px; }
    .pim-back-btn          { width: 28px; height: 28px; font-size: 15px; }
    .pim-topbar-title      { font-size: 11px; }
    .pim-topbar-sub        { font-size: 9px; }

    .pim-content           { padding: 15px 10px 28px; }

    .pim-section-head      { font-size: 8px; letter-spacing: 0.7px; margin: 14px 0 7px; padding-bottom: 5px; }
    .pim-section-head i    { font-size: 10px; }

    .pim-details-table td  { padding: 6px 4px; }
    .pim-dt-icon           { width: 22px; }
    .pim-dt-icon i         { font-size: 12px; }
    .pim-dt-label          { font-size: 10px; }
    .pim-dt-value          { font-size: 11px; }

    .pim-stat-strip        { gap: 5px; }
    .pim-stat-block        { padding: 9px 3px; border-radius: 10px; }
    .pim-stat-lbl          { font-size: 8px; margin-top: 3px; }

    .pim-activity-strip    { gap: 5px; }
    .pim-activity-block    { padding: 8px 3px; border-radius: 9px; }
    .pim-activity-lbl      { font-size: 8px; margin-top: 3px; }

    .pim-creator-row       { padding: 8px 10px; gap: 7px; border-radius: 11px; }
    .pim-creator-row img   { width: 34px; height: 34px; }
    .pim-creator-label     { font-size: 9px; }
    .pim-creator-name      { font-size: 12px; }

    .pim-people-row        { gap: 9px; }
    .pim-person img        { width: 36px; height: 36px; }
    .pim-person-name       { font-size: 10px; max-width: 40px; }
    .pim-person-role       { font-size: 8px; }

    .pim-tag               { padding: 4px 9px; font-size: 10px; gap: 3px; }

    .pim-similar-row       { padding: 7px 9px; gap: 7px; border-radius: 9px; }
    .pim-similar-img       { width: 30px; height: 30px; border-radius: 7px; }
    .pim-similar-name      { font-size: 11px; }
    .pim-similar-meta      { font-size: 9px; }
    .pim-similar-arrow     { font-size: 15px; }

    .pim-desc-box          { padding: 9px 10px; font-size: 11px; border-radius: 9px; line-height: 1.55; }

    .pim-pod-name          { font-size: clamp(12px, 2.8vw, 15px); gap: 4px; }
    .pim-pod-date          { font-size: 10px; }
}

</style>

<!-- ══ FULL PAGE MODAL ══ -->
<div id="podInfoModal">

    <?php
    /* ── Pre-compute all data ── */
    $stat_members    = (int)$conn->query("SELECT COUNT(*) as c FROM group_members WHERE group_id=$group_id")->fetch_assoc()['c'];
    $stat_posts      = (int)$conn->query("SELECT COUNT(*) as c FROM group_posts WHERE group_id=$group_id")->fetch_assoc()['c'];
    $stat_discs      = (int)$conn->query("SELECT COUNT(*) as c FROM pod_discussions WHERE group_id=$group_id")->fetch_assoc()['c'];
    $stat_admins     = (int)$conn->query("SELECT COUNT(*) as c FROM group_admins WHERE group_id=$group_id")->fetch_assoc()['c'];
    $stat_reposts    = (int)$conn->query("SELECT COUNT(*) as c FROM reposts r JOIN group_posts gp ON gp.meme_id=r.meme_id WHERE gp.group_id=$group_id")->fetch_assoc()['c'];
    $stat_likes      = (int)$conn->query("SELECT COUNT(*) as c FROM likes l JOIN group_posts gp ON gp.meme_id=l.meme_id WHERE gp.group_id=$group_id")->fetch_assoc()['c'];
    $stat_disc_replies = (int)$conn->query("SELECT COUNT(*) as c FROM pod_discussion_replies pdr JOIN pod_discussions pd ON pd.id=pdr.discussion_id WHERE pd.group_id=$group_id")->fetch_assoc()['c'];

    $act_posts_today  = (int)$conn->query("SELECT COUNT(*) as c FROM group_posts WHERE group_id=$group_id AND DATE(created_at)=CURDATE()")->fetch_assoc()['c'];
    $act_new_members  = (int)$conn->query("SELECT COUNT(*) as c FROM group_members WHERE group_id=$group_id AND joined_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetch_assoc()['c'];
    $act_active_now   = (int)$conn->query("SELECT COUNT(*) as c FROM group_members gm JOIN users u ON u.id=gm.user_id WHERE gm.group_id=$group_id AND u.last_seen >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)")->fetch_assoc()['c'];

    $ph_res = $conn->query("SELECT HOUR(created_at) as hr, COUNT(*) as cnt FROM group_posts WHERE group_id=$group_id GROUP BY hr ORDER BY cnt DESC LIMIT 1");
    $ph_row = $ph_res ? $ph_res->fetch_assoc() : null;
    $peak_hour = $ph_row ? sprintf('%d:00–%d:00', (int)$ph_row['hr'], (int)$ph_row['hr']+1) : 'N/A';

    /* ── Hashtags ── */
    $tag_res = $conn->query("SELECT m.caption FROM group_posts gp JOIN memes m ON m.id=gp.meme_id WHERE gp.group_id=$group_id AND m.caption IS NOT NULL LIMIT 80");
    $tag_counts = [];
    if ($tag_res) {
        while ($tr = $tag_res->fetch_assoc()) {
            preg_match_all('/#(\w+)/u', $tr['caption'], $mx);
            foreach ($mx[1] as $tg) { $t = strtolower($tg); $tag_counts[$t] = ($tag_counts[$t] ?? 0) + 1; }
        }
    }
    arsort($tag_counts);
    $top_tags = array_slice(array_keys($tag_counts), 0, 10);

    /* ── Similar pods ── */
    $sim_pods = [];
    $sim_res = $conn->query("SELECT g.id, g.title, g.group_pic, (SELECT COUNT(*) FROM group_members WHERE group_id=g.id) AS mc FROM groups g WHERE g.id != $group_id ORDER BY mc DESC LIMIT 4");
    if ($sim_res) while ($sr = $sim_res->fetch_assoc()) $sim_pods[] = $sr;

    /* ── Image path helper ── */
    $pim_banner = !empty($group['group_pic'])
        ? (strpos($group['group_pic'],'uploads/')===0 ? $group['group_pic'] : 'uploads/'.$group['group_pic'])
        : 'uploads/default_group.png';
    ?>

    <!-- TOP BAR -->
    <div class="pim-topbar">
        <button class="pim-back-btn" onclick="closePodInfoModal()" aria-label="Back">
            <i class="ri-arrow-left-line"></i>
        </button>
        <div>
            <div class="pim-topbar-title"><?php echo htmlspecialchars($group['title']); ?></div>
            <div class="pim-topbar-sub">Pod details · <?php echo number_format($stat_members); ?> members</div>
        </div>
    </div>

    <!-- HERO BANNER -->
    <div class="pim-hero">
        <img class="pim-hero-img"
             src="<?php echo htmlspecialchars($pim_banner); ?>"
             onerror="this.src='uploads/default_group.png';" alt="Banner">
        <div class="pim-hero-fade"></div>
            <div class="pim-hero-text">
                <div class="pim-pod-name">
                    <?php echo htmlspecialchars($group['title']); ?>
                    <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" style="flex-shrink:0;">
                        <circle cx="12" cy="12" r="12" fill="#0673f0"/>
                        <path d="M9 12l2 2 4-4" stroke="white" stroke-width="2.5" fill="none"/>
                    </svg>
                    <?php if (!empty($group['is_adult'])): ?>
                    <span style="font-size:9px;font-weight:700;background:rgba(255,45,45,0.25);color:#f87171;border:1px solid rgba(255,45,45,0.4);padding:2px 7px;border-radius:20px;font-family:'Poppins',sans-serif;">18+</span>
                    <?php endif; ?>
                </div>
                <div class="pim-pod-date">
                    <i class="ri-calendar-line" style="font-size:11px;"></i>
                    Created <?php echo date('M j, Y', strtotime($group['created_at'])); ?>
                </div>
            </div>
        </div>

    <!-- CONTENT -->
    <div class="pim-content">

        <!-- ── TOP STATS ── -->
        <div class="pim-stat-strip">
            <div class="pim-stat-block">
                <span class="pim-stat-num"><?php echo number_format($stat_members); ?></span>
                <span class="pim-stat-lbl">Members</span>
            </div>
            <div class="pim-stat-block">
                <span class="pim-stat-num"><?php echo number_format($stat_posts); ?></span>
                <span class="pim-stat-lbl">Posts</span>
            </div>
            <div class="pim-stat-block">
                <span class="pim-stat-num"><?php echo number_format($stat_discs); ?></span>
                <span class="pim-stat-lbl">Discussions</span>
            </div>
        </div>

        <!-- ── ABOUT ── -->
        <div class="pim-section-head">
            <i class="ri-information-line"></i> About this pod
        </div>
        <div class="pim-desc-box">
            <?php echo nl2br(htmlspecialchars($group['description'] ?? 'No description set.')); ?>
        </div>

        <!-- ── DETAILS TABLE (file-explorer style) ── -->
        <div class="pim-section-head">
            <i class="ri-list-check-2"></i> Details
        </div>
        <table class="pim-details-table">
            <tr>
                <td class="pim-dt-icon"><i class="ri-shield-star-line"></i></td>
                <td class="pim-dt-label">Admins</td>
                <td class="pim-dt-value gold"><?php echo number_format($stat_admins); ?></td>
            </tr>
            <tr>
                <td class="pim-dt-icon"><i class="ri-heart-line"></i></td>
                <td class="pim-dt-label">Total Likes</td>
                <td class="pim-dt-value red"><?php echo number_format($stat_likes); ?></td>
            </tr>
            <tr>
                <td class="pim-dt-icon"><i class="ri-repeat-line"></i></td>
                <td class="pim-dt-label">Total Reposts</td>
                <td class="pim-dt-value accent"><?php echo number_format($stat_reposts); ?></td>
            </tr>
            <tr>
                <td class="pim-dt-icon"><i class="ri-chat-3-line"></i></td>
                <td class="pim-dt-label">Discussion Replies</td>
                <td class="pim-dt-value"><?php echo number_format($stat_disc_replies); ?></td>
            </tr>
            <tr>
                <td class="pim-dt-icon"><i class="ri-time-line"></i></td>
                <td class="pim-dt-label">Peak Hour</td>
                <td class="pim-dt-value accent"><?php echo $peak_hour; ?></td>
            </tr>
            <tr>
                <td class="pim-dt-icon"><i class="ri-calendar-event-line"></i></td>
                <td class="pim-dt-label">Date Created</td>
                <td class="pim-dt-value"><?php echo date('d M Y', strtotime($group['created_at'])); ?></td>
            </tr>
            <tr>
                <td class="pim-dt-icon"><i class="ri-lock-line"></i></td>
                <td class="pim-dt-label">Content Rating</td>
                <td class="pim-dt-value <?php echo !empty($group['is_adult']) ? 'red' : 'green'; ?>">
                    <?php echo !empty($group['is_adult']) ? '18+ Only' : 'All Ages'; ?>
                </td>
            </tr>
            <tr>
                <td class="pim-dt-icon"><i class="ri-vip-crown-line"></i></td>
                <td class="pim-dt-label">Creator</td>
                <td class="pim-dt-value"><?php echo htmlspecialchars($group['creator_name']); ?></td>
            </tr>
        </table>

        <!-- ── ACTIVITY SNAPSHOT ── -->
        <div class="pim-section-head">
            <i class="ri-pulse-line"></i> Live activity
        </div>
        <div class="pim-activity-strip">
            <div class="pim-activity-block">
                <span class="pim-activity-num"><?php echo $act_posts_today; ?></span>
                <span class="pim-activity-lbl">Posts today</span>
            </div>
            <div class="pim-activity-block">
                <span class="pim-activity-num"><?php echo $act_new_members; ?></span>
                <span class="pim-activity-lbl">Joined this week</span>
            </div>
            <div class="pim-activity-block">
                <span class="pim-activity-num" style="color:<?php echo $act_active_now > 0 ? '#4cff91' : '#4dabff'; ?>;"><?php echo $act_active_now; ?></span>
                <span class="pim-activity-lbl">Online now</span>
            </div>
        </div>

        <!-- ── CREATOR ── -->
        <div class="pim-section-head">
            <i class="ri-vip-crown-line"></i> Creator
        </div>
        <a href="profile.php?user_id=<?php echo (int)$group['created_by']; ?>" class="pim-creator-row">
            <img src="<?php echo !empty($group['creator_pic']) ? htmlspecialchars($group['creator_pic']) : 'uploads/me.jpeg'; ?>"
                 onerror="this.src='uploads/me.jpeg';" alt="Creator">
            <div>
                <div class="pim-creator-label"><i class="ri-vip-crown-fill" style="font-size:10px;color:#FFD700;"></i> Pod Creator</div>
                <div class="pim-creator-name"><?php echo htmlspecialchars($group['creator_name']); ?></div>
            </div>
            <i class="ri-arrow-right-s-line" style="margin-left:auto;color:rgba(255,255,255,0.28);font-size:20px;flex-shrink:0;"></i>
        </a>

        <!-- ── ADMINS ── -->
        <?php if (!empty($admins)): ?>
        <div class="pim-section-head">
            <i class="ri-admin-line"></i> Admins (<?php echo count($admins); ?>)
        </div>
        <div class="pim-people-row">
            <?php foreach ($admins as $adm): ?>
            <a href="profile.php?user_id=<?php echo (int)$adm['id']; ?>" class="pim-person">
                <img src="<?php echo !empty($adm['profile_pic']) ? htmlspecialchars($adm['profile_pic']) : 'uploads/me.jpeg'; ?>"
                     onerror="this.src='uploads/me.jpeg';" alt="Admin">
                <span class="pim-person-name"><?php echo htmlspecialchars($adm['username']); ?></span>
                <span class="pim-person-role">ADMIN</span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- ── POPULAR TOPICS ── -->
        <div class="pim-section-head">
            <i class="ri-hashtag"></i> Popular topics
        </div>
        <?php if (!empty($top_tags)): ?>
        <div class="pim-tags-wrap">
            <?php foreach ($top_tags as $tag): ?>
            <span class="pim-tag">
                <i class="ri-hashtag" style="font-size:11px;"></i><?php echo htmlspecialchars($tag); ?>
            </span>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p style="font-size:12px;color:rgba(255,255,255,0.26);margin:0;font-family:'Poppins',sans-serif;">No hashtags posted yet — be the first!</p>
        <?php endif; ?>

        <!-- ── YOU MAY ALSO LIKE ── -->
        <?php if (!empty($sim_pods)): ?>
        <div class="pim-section-head">
            <i class="ri-compass-discover-line"></i> You may also like
        </div>
        <div class="pim-similar-list">
            <?php foreach ($sim_pods as $sp):
                $sp_img = !empty($sp['group_pic'])
                    ? (strpos($sp['group_pic'],'uploads/')===0 ? $sp['group_pic'] : 'uploads/'.$sp['group_pic'])
                    : 'uploads/default_group.png';
            ?>
            <a href="group.php?id=<?php echo (int)$sp['id']; ?>" class="pim-similar-row">
                <img class="pim-similar-img"
                     src="<?php echo htmlspecialchars($sp_img); ?>"
                     onerror="this.src='uploads/default_group.png';" alt="">
                <div style="flex:1;min-width:0;">
                    <div class="pim-similar-name"><?php echo htmlspecialchars($sp['title']); ?></div>
                    <div class="pim-similar-meta">
                        <i class="ri-team-line" style="font-size:11px;"></i>
                        <?php echo number_format((int)$sp['mc']); ?> members
                    </div>
                </div>
                <i class="ri-arrow-right-s-line pim-similar-arrow"></i>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div><!-- /pim-content -->
</div><!-- /podInfoModal -->

<script>
/* ══ Pod Info Modal JS ══ */
function openPodInfoModal() {
    var m = document.getElementById('podInfoModal');
    if (!m) return;
    m.classList.add('open');
    m.scrollTop = 0;
    document.body.style.overflow = 'hidden';
}

function closePodInfoModal() {
    var m = document.getElementById('podInfoModal');
    if (!m) return;
    m.style.animation = 'podPageOut 0.22s ease forwards';
    setTimeout(function () {
        m.classList.remove('open');
        m.style.animation = '';
        document.body.style.overflow = '';
    }, 210);
}

/* inject close animation once */
(function(){
    var s = document.createElement('style');
    s.textContent = '@keyframes podPageOut{to{opacity:0;transform:translateY(16px)}}';
    document.head.appendChild(s);
})();

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closePodInfoModal();
});
</script>

<div class="kebab-wrap" id="kebabWrap">
    <button class="kebab-btn" onclick="openKebabModal()" title="Group Guidelines">
        <i class="ri-menu-line"></i>
    </button>
</div>

<!-- ══════════════════════════════════════════
     GROUP GUIDELINES — FULL SCREEN MODAL
══════════════════════════════════════════ -->
<div id="kebabModal">

    <!-- TOP BAR -->
    <div class="kbm-topbar">
        <button class="kbm-back-btn" onclick="closeKebabModal()" aria-label="Close">
            <i class="ri-arrow-left-line"></i>
        </button>
        <div class="kbm-topbar-center">
            <div class="kbm-topbar-title">Group Guidelines</div>
            <div class="kbm-topbar-sub"><?php echo htmlspecialchars($group['title']); ?></div>
        </div>
        <div class="kbm-topbar-spacer"></div>
    </div>

    <!-- SCROLLABLE BODY -->
    <div class="kbm-body">

        <div class="kbm-section-head">
            <i class="ri-shield-check-line"></i> Universal Group Rules
        </div>

        <div class="kbm-rule">
            <div class="kbm-rule-icon"><i class="ri-focus-3-line"></i></div>
            <span>Post only content relevant to the group's genre/theme — off-topic posts get removed immediately</span>
        </div>
        <div class="kbm-rule">
            <div class="kbm-rule-icon"><i class="ri-links-line"></i></div>
            <span>Tag the original creator when remixing content</span>
        </div>
        <div class="kbm-rule">
            <div class="kbm-rule-icon"><i class="ri-spam-2-line"></i></div>
            <span>No spam — repeated posting of the same meme is an instant removal</span>
        </div>
        <div class="kbm-rule">
            <div class="kbm-rule-icon"><i class="ri-user-forbid-line"></i></div>
            <span>No impersonating other users or creators</span>
        </div>
        <div class="kbm-rule">
            <div class="kbm-rule-icon"><i class="ri-emotion-line"></i></div>
            <span>Respect the group's tone — every group has its own culture</span>
        </div>

        <!-- STRIKE SYSTEM CARD -->
        <div class="kbm-strike-card">
            <div class="kbm-strike-head">
                <i class="ri-alarm-warning-line"></i> Strike System
            </div>
            <div class="kbm-strike-row">
                <span class="kbm-strike-num">1</span>
                <span class="kbm-strike-text">Warning</span>
            </div>
            <div class="kbm-strike-row">
                <span class="kbm-strike-num">2</span>
                <span class="kbm-strike-text">Temporary removal</span>
            </div>
            <div class="kbm-strike-row danger">
                <span class="kbm-strike-num">3</span>
                <span class="kbm-strike-text">
                    Permanent ban — you can <strong>NEVER</strong> rejoin that group or
                    ANY group created by that creator, ever
                </span>
            </div>
        </div>

        <div class="kbm-section-head">
            <i class="ri-file-text-line"></i> Creator's Additional Guidelines
        </div>

        <?php if (!empty($group['custom_guidelines'])): ?>
            <div class="kbm-custom-text"><?php echo nl2br(htmlspecialchars($group['custom_guidelines'])); ?></div>
        <?php else: ?>
            <div class="kbm-no-custom">
                <i class="ri-file-info-line"></i>
                No additional guidelines set by the creator.
            </div>
        <?php endif; ?>

    </div><!-- /kbm-body -->
</div><!-- /kebabModal -->
</div>


<style>
/* ══════════════════════════════════════════
   GROUP GUIDELINES MODAL — FULL RESPONSIVE
   Breakpoints: 790 · 490 · 380 · 330px
══════════════════════════════════════════ */

#kebabModal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 9999999999;
    background: #030420;
    flex-direction: column;
    overflow: hidden;
}
#kebabModal.open {
    display: flex;
    animation: kbmSlideIn 0.28s cubic-bezier(0.22,1,0.36,1);
}
@keyframes kbmSlideIn {
    from { opacity: 0; transform: translateY(20px); }
    to   { opacity: 1; transform: translateY(0); }
}
@keyframes kbmSlideOut {
    to { opacity: 0; transform: translateY(16px); }
}

/* ── TOP BAR ── */
.kbm-topbar {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 20px;
    background: rgba(3,4,32,0.97);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    border-bottom: 1px solid rgba(255,255,255,0.07);
    flex-shrink: 0;
    position: sticky;
    top: 0;
    z-index: 10;
}
.kbm-back-btn {
    display: flex; align-items: center; justify-content: center;
    width: 36px; height: 36px;
    border-radius: 50%;
    border: 1px solid rgba(255,255,255,0.14);
    background: rgba(255,255,255,0.06);
    color: #fff;
    font-size: 20px;
    cursor: pointer;
    transition: 0.2s;
    flex-shrink: 0;
    -webkit-tap-highlight-color: transparent;
}
.kbm-back-btn:hover  { background: rgba(255,255,255,0.13); color: #fff; }
.kbm-back-btn:active { transform: scale(0.9); }

.kbm-topbar-center { flex: 1; min-width: 0; }
.kbm-topbar-title {
    font-size: 15px;
    font-weight: 700;
    color: #fff;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    font-family: 'Poppins', sans-serif;
}
.kbm-topbar-sub {
    font-size: 11px;
    color: rgba(255,255,255,0.38);
    font-weight: 400;
    margin-top: 1px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    font-family: 'Poppins', sans-serif;
}
.kbm-topbar-spacer { width: 36px; height: 36px; flex-shrink: 0; }

/* ── SCROLLABLE BODY ── */
.kbm-body {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
    scrollbar-color: rgba(77,171,255,0.18) transparent;
    padding: 20px 20px 48px;
}
.kbm-body::-webkit-scrollbar { width: 4px; }
.kbm-body::-webkit-scrollbar-track { background: transparent; }
.kbm-body::-webkit-scrollbar-thumb {
    background: rgba(77,171,255,0.22);
    border-radius: 99px;
}

/* ── SECTION HEAD ── */
.kbm-section-head {
    display: flex; align-items: center; gap: 7px;
    font-size: 10px; font-weight: 700;
    letter-spacing: 1.1px; text-transform: uppercase;
    color: rgba(77,171,255,0.65);
    font-family: 'Poppins', sans-serif;
    margin: 26px 0 12px;
    padding-bottom: 8px;
    border-bottom: 1px solid rgba(77,171,255,0.12);
}
.kbm-section-head:first-child { margin-top: 0; }
.kbm-section-head i { font-size: 13px; color: #4dabff; }

/* ── RULE ROW ── */
.kbm-rule {
    display: flex; align-items: flex-start; gap: 12px;
    padding: 12px 0;
    border-bottom: 1px solid rgba(255,255,255,0.05);
    font-size: 12.5px;
    color: rgba(255,255,255,0.68);
    line-height: 1.55;
    font-family: 'Poppins', sans-serif;
}
.kbm-rule:last-of-type { border-bottom: none; }
.kbm-rule-icon {
    width: 30px; height: 30px;
    border-radius: 9px;
    background: rgba(77,171,255,0.1);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    margin-top: 1px;
}
.kbm-rule-icon i { font-size: 14px; color: #4dabff; }

/* ── STRIKE SYSTEM CARD ── */
.kbm-strike-card {
    background: rgba(255,45,45,0.05);
    border: 1px solid rgba(255,45,45,0.18);
    border-radius: 16px;
    padding: 16px 16px 8px;
    margin: 18px 0 6px;
}
.kbm-strike-head {
    display: flex; align-items: center; gap: 7px;
    font-size: 12px; font-weight: 700;
    color: #ff7a7a;
    font-family: 'Poppins', sans-serif;
    margin-bottom: 12px;
}
.kbm-strike-head i { font-size: 15px; color: #ff2d2d; }

.kbm-strike-row {
    display: flex; align-items: flex-start; gap: 11px;
    padding: 8px 0;
    border-bottom: 1px solid rgba(255,255,255,0.05);
}
.kbm-strike-row:last-child { border-bottom: none; }
.kbm-strike-row.danger .kbm-strike-num {
    background: rgba(255,45,45,0.25);
    color: #ff7a7a;
    border-color: rgba(255,45,45,0.4);
}
.kbm-strike-row.danger .kbm-strike-text { color: rgba(255,255,255,0.82); }

.kbm-strike-num {
    width: 22px; height: 22px;
    border-radius: 50%;
    background: rgba(255,255,255,0.08);
    border: 1px solid rgba(255,255,255,0.14);
    color: rgba(255,255,255,0.6);
    font-size: 11px; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    font-family: 'Poppins', sans-serif;
}
.kbm-strike-text {
    font-size: 12px;
    color: rgba(255,255,255,0.6);
    line-height: 1.5;
    font-family: 'Poppins', sans-serif;
    padding-top: 1px;
}
.kbm-strike-text strong { color: #ff7a7a; }

/* ── CUSTOM GUIDELINES ── */
.kbm-custom-text {
    font-size: 12.5px;
    color: rgba(255,255,255,0.62);
    line-height: 1.65;
    white-space: pre-wrap;
    word-break: break-word;
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.07);
    border-radius: 14px;
    padding: 14px 16px;
    font-family: 'Poppins', sans-serif;
}
.kbm-no-custom {
    display: flex; align-items: center; gap: 8px;
    font-size: 12px;
    color: rgba(255,255,255,0.28);
    font-style: italic;
    font-family: 'Poppins', sans-serif;
    padding: 12px 0;
}
.kbm-no-custom i { font-size: 16px; color: rgba(255,255,255,0.2); }

/* ════════════════════════════════════════
   RESPONSIVE — 790px
   ════════════════════════════════════════ */
@media (max-width: 790px) {
    .kbm-topbar       { padding: 11px 16px; gap: 10px; }
    .kbm-back-btn     { width: 32px; height: 32px; font-size: 17px; }
    .kbm-topbar-spacer{ width: 32px; height: 32px; }
    .kbm-topbar-title { font-size: 13.5px; }
    .kbm-topbar-sub   { font-size: 10px; }

    .kbm-body         { padding: 17px 16px 40px; }

    .kbm-section-head { font-size: 9.5px; margin: 22px 0 10px; padding-bottom: 7px; }
    .kbm-section-head i { font-size: 12px; }

    .kbm-rule         { padding: 11px 0; gap: 11px; font-size: 12px; }
    .kbm-rule-icon    { width: 28px; height: 28px; border-radius: 8px; }
    .kbm-rule-icon i  { font-size: 13px; }

    .kbm-strike-card  { padding: 14px 14px 7px; margin: 16px 0 6px; border-radius: 14px; }
    .kbm-strike-head  { font-size: 11.5px; margin-bottom: 11px; }
    .kbm-strike-row   { padding: 7px 0; gap: 10px; }
    .kbm-strike-num   { width: 20px; height: 20px; font-size: 10.5px; }
    .kbm-strike-text  { font-size: 11.5px; }

    .kbm-custom-text  { font-size: 12px; padding: 13px 15px; border-radius: 13px; }
    .kbm-no-custom    { font-size: 11.5px; }
}

/* ════════════════════════════════════════
   RESPONSIVE — 490px
   ════════════════════════════════════════ */
@media (max-width: 490px) {
    .kbm-topbar       { padding: 10px 14px; gap: 9px; }
    .kbm-back-btn     { width: 30px; height: 30px; font-size: 16px; }
    .kbm-topbar-spacer{ width: 30px; height: 30px; }
    .kbm-topbar-title { font-size: 12.5px; }
    .kbm-topbar-sub   { font-size: 9.5px; }

    .kbm-body         { padding: 14px 13px 36px; }

    .kbm-section-head { font-size: 9px; letter-spacing: 1px; margin: 19px 0 9px; padding-bottom: 6px; }
    .kbm-section-head i { font-size: 11px; }

    .kbm-rule         { padding: 10px 0; gap: 10px; font-size: 11px; }
    .kbm-rule-icon    { width: 25px; height: 25px; border-radius: 7px; }
    .kbm-rule-icon i  { font-size: 12px; }

    .kbm-strike-card  { padding: 13px 12px 6px; margin: 14px 0 5px; border-radius: 13px; }
    .kbm-strike-head  { font-size: 11px; margin-bottom: 10px; gap: 6px; }
    .kbm-strike-head i{ font-size: 13px; }
    .kbm-strike-row   { padding: 6px 0; gap: 9px; }
    .kbm-strike-num   { width: 19px; height: 19px; font-size: 10px; }
    .kbm-strike-text  { font-size: 10.5px; }

    .kbm-custom-text  { font-size: 11px; padding: 12px 13px; border-radius: 12px; line-height: 1.6; }
    .kbm-no-custom    { font-size: 10.5px; padding: 10px 0; }
    .kbm-no-custom i  { font-size: 14px; }
}

/* ════════════════════════════════════════
   RESPONSIVE — 380px
   ════════════════════════════════════════ */
@media (max-width: 380px) {
    .kbm-topbar       { padding: 9px 12px; gap: 8px; }
    .kbm-back-btn     { width: 28px; height: 28px; font-size: 15px; }
    .kbm-topbar-spacer{ width: 28px; height: 28px; }
    .kbm-topbar-title { font-size: 11.5px; }
    .kbm-topbar-sub   { font-size: 9px; }

    .kbm-body         { padding: 12px 11px 32px; }

    .kbm-section-head { font-size: 8.5px; letter-spacing: 0.9px; margin: 16px 0 8px; padding-bottom: 6px; }
    .kbm-section-head i { font-size: 10px; }

    .kbm-rule         { padding: 9px 0; gap: 9px; font-size: 10.5px; line-height: 1.5; }
    .kbm-rule-icon    { width: 23px; height: 23px; border-radius: 7px; margin-top: 0; }
    .kbm-rule-icon i  { font-size: 11px; }

    .kbm-strike-card  { padding: 11px 11px 5px; margin: 13px 0 5px; border-radius: 12px; }
    .kbm-strike-head  { font-size: 10.5px; margin-bottom: 9px; gap: 5px; }
    .kbm-strike-head i{ font-size: 12px; }
    .kbm-strike-row   { padding: 6px 0; gap: 8px; }
    .kbm-strike-num   { width: 18px; height: 18px; font-size: 9.5px; }
    .kbm-strike-text  { font-size: 10px; line-height: 1.45; }

    .kbm-custom-text  { font-size: 10.5px; padding: 11px 12px; border-radius: 11px; line-height: 1.55; }
    .kbm-no-custom    { font-size: 10px; padding: 9px 0; gap: 6px; }
    .kbm-no-custom i  { font-size: 13px; }
}

/* ════════════════════════════════════════
   RESPONSIVE — 330px
   ════════════════════════════════════════ */
@media (max-width: 330px) {
    .kbm-topbar       { padding: 8px 10px; gap: 7px; }
    .kbm-back-btn     { width: 26px; height: 26px; font-size: 14px; }
    .kbm-topbar-spacer{ width: 26px; height: 26px; }
    .kbm-topbar-title { font-size: 10.5px; }
    .kbm-topbar-sub   { font-size: 8.5px; }

    .kbm-body         { padding: 10px 9px 26px; }

    .kbm-section-head { font-size: 8px; letter-spacing: 0.8px; margin: 14px 0 7px; padding-bottom: 5px; }
    .kbm-section-head i { font-size: 9.5px; }

    .kbm-rule         { padding: 8px 0; gap: 8px; font-size: 9.5px; line-height: 1.45; }
    .kbm-rule-icon    { width: 21px; height: 21px; border-radius: 6px; }
    .kbm-rule-icon i  { font-size: 10px; }

    .kbm-strike-card  { padding: 10px 9px 4px; margin: 11px 0 4px; border-radius: 11px; }
    .kbm-strike-head  { font-size: 9.5px; margin-bottom: 8px; gap: 5px; }
    .kbm-strike-head i{ font-size: 11px; }
    .kbm-strike-row   { padding: 5px 0; gap: 7px; }
    .kbm-strike-num   { width: 16px; height: 16px; font-size: 9px; }
    .kbm-strike-text  { font-size: 9px; line-height: 1.4; }

    .kbm-custom-text  { font-size: 9.5px; padding: 10px 11px; border-radius: 10px; line-height: 1.5; }
    .kbm-no-custom    { font-size: 9px; padding: 8px 0; gap: 5px; }
    .kbm-no-custom i  { font-size: 12px; }
}
</style>

<script>
/* ── KEBAB / GUIDELINES MODAL ── */
function openKebabModal() {
    var m = document.getElementById('kebabModal');
    if (!m) return;
    m.classList.add('open');
    m.querySelector('.kbm-body').scrollTop = 0;
    document.body.style.overflow = 'hidden';
}

function closeKebabModal() {
    var m = document.getElementById('kebabModal');
    if (!m) return;
    m.style.animation = 'kbmSlideOut 0.22s ease forwards';
    setTimeout(function () {
        m.classList.remove('open');
        m.style.animation = '';
        document.body.style.overflow = '';
    }, 210);
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        var m = document.getElementById('kebabModal');
        if (m && m.classList.contains('open')) closeKebabModal();
    }
});
</script>


        <!-- DESCRIPTION -->
       <?php
$desc = $group['description'] ?? '';
$short = mb_substr($desc, 0, 30);
$isLong = mb_strlen($desc) > 30;
?>
<p class="gh-desc">
    <?php echo htmlspecialchars($short); ?><?php if ($isLong): ?>...
    <a href="#" class="gh-desc-more" onclick="document.getElementById('descModal-<?php echo $group['id']; ?>').style.display='flex'; return false;">see more</a>
    <?php endif; ?>
</p>

<?php if ($isLong): ?>
<div id="descModal-<?php echo $group['id']; ?>" class="gh-desc-modal" onclick="if(event.target===this) this.style.display='none';">
    <div class="gh-desc-modal-content">
        <span class="gh-desc-modal-close" onclick="this.closest('.gh-desc-modal').style.display='none';">&times;</span>
        <h3>About this Pod</h3>
        <p><?php echo nl2br(htmlspecialchars($desc)); ?></p>
    </div>
</div>
<?php endif; ?>

<style>
.gh-desc-more {
    color: #006de1;
    text-decoration: none;
    font-weight: 500;
    cursor: pointer;
}

.gh-desc-modal {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0, 0, 0, 0.87);
    align-items: center;
    justify-content: center;
    z-index: 1000;
    padding: 16px;
    box-sizing: border-box;
}

.gh-desc-modal-content {
    background: linear-gradient(145deg, #050a16, #0a1224);
    border: 1px solid rgba(255,255,255,0.06);
    color: #d6e0f5;
    padding: 24px;
    border-radius: 14px;
    max-width: 480px;
    width: 100%;
    max-height: 80vh;
    overflow-y: auto;
    position: relative;
    box-shadow: 0 20px 60px rgba(0,0,0,0.6);
    font-size: 14px;
    line-height: 1.6;
}

.gh-desc-modal-content h3 {
    margin: 0 0 12px 0;
    font-size: 16px;
    font-weight: 600;
    color: #ffffff;
    border-bottom: 1px solid rgba(255,255,255,0.08);
    padding-bottom: 10px;
}

.gh-desc-modal-content p {
    margin: 0;
    color: #aab8d6;
    font-size: 13.5px;
}

.gh-desc-modal-close {
    position: absolute;
    top: 12px;
    right: 14px;
    cursor: pointer;
    font-size: 18px;
    color: #6b7a99;
    line-height: 1;
    transition: color 0.15s;
}
.gh-desc-modal-close:hover {
    color: #fff;
}

/* Responsive breakpoints */
@media (max-width: 760px) {
    .gh-desc-modal-content {
        max-width: 420px;
        padding: 20px;
    }
    .gh-desc-modal-content h3 {
        font-size: 14px;
    }
    .gh-desc-modal-content p {
        font-size: 12px;
    }
}

@media (max-width: 360px) {
    .gh-desc-modal-content {
        padding: 16px;
        border-radius: 10px;
    }
    .gh-desc-modal-content h3 {
        font-size: 13px;
    }
    .gh-desc-modal-content p {
        font-size: 11px;
    }
}

@media (max-width: 320px) {
    .gh-desc-modal-content {
        padding: 14px;
    }
    .gh-desc-modal-content h3 {
        font-size: 12px;
    }
    .gh-desc-modal-content p {
        font-size: 10.5px;
    }
    .gh-desc-modal-close {
        font-size: 16px;
        top: 8px;
        right: 10px;
    }
}
</style>

        <!-- CREATOR (inline) -->
        <div class="gh-meta">
            <a href="profile.php?user_id=<?php echo $group['created_by']; ?>" class="gh-creator-link">
                <img class="gh-creator-avatar"
                     src="<?php echo !empty($group['creator_pic']) ? $group['creator_pic'] : 'uploads/me.jpeg'; ?>"
                     onerror="this.src='uploads/me.jpeg';" alt="Creator">
                <span class="gh-creator-text">
                    Created by <strong><?php echo htmlspecialchars($group['creator_name']); ?></strong>
                </span>
            </a>
        </div>

        <!-- ACTIONS -->
        <div class="gh-actions">
            <?php
            $is_member = false;
            if (isset($conn) && isset($group_id) && isset($current_user)) {
                $query = $conn->query(
                    "SELECT 1 FROM group_members WHERE group_id = " . (int)$group_id .
                    " AND user_id = " . (int)$current_user . " LIMIT 1"
                );
                if ($query) $is_member = $query->num_rows > 0;
            }
            ?>

            <?php if (!$is_member): ?>

                <?php if ($is_private_group && $my_request_status === 'pending'): ?>
                    <!-- Requested — tap to withdraw -->
                    <form method="POST" style="display:contents"
                          onsubmit="return confirm('Withdraw your request to join this pod?');">
                        <button name="cancel_request" class="gh-btn gh-btn-leave">
                            <i class="ri-time-line"></i> Requested
                        </button>
                    </form>

                <?php elseif ($is_private_group && $my_request_status === 'denied'): ?>
                    <!-- Denied — tap to re-request -->
                    <form method="POST" style="display:contents"
                          onsubmit="<?php if (!empty($blocked_by_creator)) echo 'alert(\'You cannot join – blocked by creator.\'); return false;'; ?>">
                        <button name="join_group" class="gh-btn gh-btn-leave">
                            <i class="ri-close-circle-line"></i> Request Denied · Retry
                        </button>
                    </form>

                <?php else: ?>
                    <!-- No request yet — Join or Request to Join -->
                    <form method="POST" style="display:contents"
                          onsubmit="<?php if (!empty($blocked_by_creator)) echo 'alert(\'You cannot join – blocked by creator.\'); return false;'; ?>">
                        <button name="join_group" class="gh-btn gh-btn-join">
                            <?php if ($is_private_group): ?>
                                <i class="ri-lock-line"></i> Request to Join
                            <?php else: ?>
                                <i class="ri-group-line"></i> Join Pod
                            <?php endif; ?>
                        </button>
                    </form>
                <?php endif; ?>

            <?php else: ?>
                <form method="POST" style="display:contents">
                    <button name="leave_group" class="gh-btn gh-btn-leave">
                        <i class="ri-logout-box-line"></i> Leave
                    </button>
                </form>
                <button onclick="openReportGroupModal(<?php echo (int)$group_id; ?>)" class="gh-btn gh-btn-report">
                    <i class="ri-flag-line"></i> Report
                </button>
<!-- ══════════════════════════════════════════════════════════
     REPORT POD MODAL v2 — Full-screen style matching profile report modal
     Updated: CSS-only scrollbar, no X on success screen,
     all icons present, consistent with report-modal v3
══════════════════════════════════════════════════════════ -->

<style>
/* ═══════════════════════════════════════════
   REPORT POD MODAL — FULL SCREEN
═══════════════════════════════════════════ */
#dzGroupReportOverlay {
    display: none;
    position: fixed; inset: 0;
    z-index: 99999999;
    font-family: 'Poppins', sans-serif;
    background: linear-gradient(-45deg, #010218, #070924, #070924);
    overflow-y: scroll;
    overscroll-behavior: contain;
    opacity: 0;
    transform: translateX(28px);
    transition: opacity .22s ease, transform .26s cubic-bezier(.22,1,.36,1);
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
    scrollbar-color: rgba(255,255,255,.12) transparent;
}
#dzGroupReportOverlay.dzgrprpt-open {
    opacity: 1;
    transform: translateX(0);
}

/* Success screen */
#dzGrpRptStep2Screen {
    display: none;
    position: fixed; inset: 0;
    z-index: 99999999;
    font-family: 'Poppins', sans-serif;
    background: linear-gradient(-45deg, #010218, #070924, #070924);
    overflow-y: scroll;
    overscroll-behavior: contain;
    opacity: 0;
    transform: translateX(28px);
    transition: opacity .22s ease, transform .26s cubic-bezier(.22,1,.36,1);
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
    scrollbar-color: rgba(255,255,255,.12) transparent;
}
#dzGrpRptStep2Screen.dzgrprpt-open {
    opacity: 1;
    transform: translateX(0);
}

/* webkit scrollbar — CSS only */
#dzGroupReportOverlay::-webkit-scrollbar,
#dzGrpRptStep2Screen::-webkit-scrollbar {
    width: 4px;
}
#dzGroupReportOverlay::-webkit-scrollbar-track,
#dzGrpRptStep2Screen::-webkit-scrollbar-track {
    background: transparent;
}
#dzGroupReportOverlay::-webkit-scrollbar-thumb,
#dzGrpRptStep2Screen::-webkit-scrollbar-thumb {
    background: rgba(255,255,255,.14);
    border-radius: 999px;
}
#dzGroupReportOverlay::-webkit-scrollbar-thumb:hover,
#dzGrpRptStep2Screen::-webkit-scrollbar-thumb:hover {
    background: rgba(255,255,255,.22);
}

/* ── Topbar ── */
.dzgrprpt-topbar {
    display: flex; align-items: center; justify-content: space-between;
    padding: 15px 22px;
    border-bottom: 1px solid rgba(255,255,255,.06);
    position: sticky; top: 0;
    background: linear-gradient(-45deg, #010218, #070924);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    z-index: 3;
}
.dzgrprpt-topbar-title {
    font-size: 15px; font-weight: 700; color: #fff; letter-spacing: -.2px;
}
.dzgrprpt-topbar-sub {
    font-size: 10.5px; color: rgba(255,255,255,.32); margin-top: 1px;
}
.dzgrprpt-topbar-center { display: flex; flex-direction: column; }
.dzgrprpt-close-btn {
    width: 30px; height: 30px; border-radius: 50%;
    background: rgba(255,255,255,.06); border: none;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; color: rgba(255,255,255,.45); font-size: 17px;
    transition: background .18s, color .18s;
}
.dzgrprpt-close-btn:hover { background: rgba(255,45,45,.18); color: #ff6060; }

/* invisible spacer — keeps title centred when no button on one side */
.dzgrprpt-topbar-spacer {
    width: 30px; height: 30px; flex-shrink: 0;
}

/* ── Section label ── */
.dzgrprpt-section-label {
    font-size: 9.5px; font-weight: 700; letter-spacing: 1.5px;
    text-transform: uppercase; color: rgba(255,255,255,.24);
    padding: 20px 22px 8px;
}

/* ── Reason rows ── */
.dzgrprpt-reason-row {
    display: flex; align-items: center; gap: 14px;
    padding: 14px 22px;
    border-bottom: 1px solid rgba(255,255,255,.045);
    cursor: pointer; transition: background .18s;
    -webkit-tap-highlight-color: transparent;
}
.dzgrprpt-reason-row:hover  { background: rgba(255,255,255,.035); }
.dzgrprpt-reason-row:active { background: rgba(255,255,255,.065); }
.dzgrprpt-reason-row.selected { background: rgba(255,45,45,.09); border-left: 3px solid #FF2D2D; }
.dzgrprpt-reason-icon { font-size: 19px; color: #FF2D2D; flex-shrink: 0; width: 24px; text-align: center; }
.dzgrprpt-reason-text { flex: 1; font-size: 13.5px; font-weight: 500; color: rgba(255,255,255,.85); }
.dzgrprpt-reason-row.selected .dzgrprpt-reason-text { color: #fff; font-weight: 600; }
.dzgrprpt-check {
    width: 23px; height: 23px; border-radius: 50%;
    border: 1.5px solid rgba(255,255,255,.2);
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; color: transparent; flex-shrink: 0; transition: all .2s;
}
.dzgrprpt-reason-row.selected .dzgrprpt-check { background: #FF2D2D; border-color: transparent; color: #fff; }

/* ── Other textarea ── */
.dzgrprpt-other-wrap { padding: 10px 22px 14px; display: none; }
.dzgrprpt-other-textarea {
    width: 100%; min-height: 100px;
    background: rgba(255,255,255,.045);
    border: 1px solid rgba(255,255,255,.09);
    border-radius: 13px; padding: 13px 15px;
    color: #fff; font-size: 13px;
    font-family: 'Poppins', sans-serif;
    resize: vertical; outline: none; box-sizing: border-box;
    transition: border-color .2s, background .2s;
}
.dzgrprpt-other-textarea::placeholder { color: rgba(255,255,255,.22); }
.dzgrprpt-other-textarea:focus {
    border-color: rgba(255,45,45,.4);
    background: rgba(255,45,45,.04);
}
.dzgrprpt-char-count { text-align: right; font-size: 10px; color: rgba(255,255,255,.25); margin-top: 5px; }

/* ── Footer buttons ── */
.dzgrprpt-footer { padding: 16px 22px 40px; }
.dzgrprpt-submit-btn {
    width: 100%; padding: 14px; border-radius: 13px;
    background: #FF2D2D; border: none; color: #fff;
    font-size: 13.5px; font-weight: 700; font-family: 'Poppins', sans-serif;
    cursor: pointer; letter-spacing: .3px;
    transition: background .18s, transform .15s, box-shadow .2s;
}
.dzgrprpt-submit-btn:hover { background: #e01f1f; box-shadow: 0 4px 20px rgba(255,45,45,.35); }
.dzgrprpt-submit-btn:active { transform: scale(.98); }
.dzgrprpt-submit-btn:disabled { opacity: .55; pointer-events: none; }
.dzgrprpt-cancel-btn {
    width: 100%; padding: 12px; margin-top: 10px; border-radius: 13px;
    background: rgba(255,255,255,.05);
    border: 1px solid rgba(255,255,255,.09);
    color: rgba(255,255,255,.42); font-size: 13px; font-weight: 600;
    font-family: 'Poppins', sans-serif; cursor: pointer;
    transition: background .18s;
}
.dzgrprpt-cancel-btn:hover { background: rgba(255,255,255,.09); }

/* ── Success screen ── */
.dzgrprpt-success-body {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    min-height: calc(100vh - 70px);
    padding: 40px 28px 60px; text-align: center;
}
.dzgrprpt-success-icon {
    width: 86px; height: 86px; border-radius: 50%;
    background: rgba(77,222,138,.1);
    border: 2px solid rgba(77,222,138,.3);
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 24px; font-size: 38px; color: #4dde8a;
}
.dzgrprpt-success-title { font-size: 19px; font-weight: 700; color: #fff; margin-bottom: 12px; }
.dzgrprpt-success-desc {
    font-size: 13.5px; color: rgba(255,255,255,.42);
    line-height: 1.75; max-width: 300px; margin: 0 auto 36px;
}
.dzgrprpt-done-btn {
    padding: 14px 48px; border-radius: 50px;
    background: #FF2D2D; border: none; color: #fff;
    font-size: 14px; font-weight: 700; font-family: 'Poppins', sans-serif;
    cursor: pointer; transition: background .18s;
}
.dzgrprpt-done-btn:hover { background: #e01f1f; }

/* ── Toast ── */
.dzgrprpt-toast {
    position: fixed; bottom: 30px; left: 50%;
    transform: translateX(-50%) translateY(10px);
    background: rgba(8,8,22,.97);
    border: 1px solid rgba(255,45,45,.25);
    color: #fff; padding: 9px 22px; border-radius: 50px;
    font-size: 12px; font-family: 'Poppins', sans-serif; font-weight: 500;
    z-index: 999999999; pointer-events: none;
    box-shadow: 0 8px 28px rgba(0,0,0,.65);
    white-space: nowrap; opacity: 0;
    transition: opacity .22s ease, transform .22s ease;
}
.dzgrprpt-toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }

/* ══ RESPONSIVE ══ */
@media (max-width: 780px) {
    .dzgrprpt-topbar        { padding: 13px 18px; }
    .dzgrprpt-topbar-title  { font-size: 14.5px; }
    .dzgrprpt-section-label { padding: 18px 18px 7px; }
    .dzgrprpt-reason-row    { padding: 13px 18px; gap: 13px; }
    .dzgrprpt-other-wrap    { padding: 9px 18px 12px; }
    .dzgrprpt-footer        { padding: 14px 18px 36px; }
}
@media (max-width: 490px) {
    .dzgrprpt-topbar        { padding: 11px 16px; }
    .dzgrprpt-topbar-title  { font-size: 13.5px; }
    .dzgrprpt-topbar-sub    { font-size: 10px; }
    .dzgrprpt-close-btn     { width: 27px; height: 27px; font-size: 15px; }
    .dzgrprpt-section-label { font-size: 9px; letter-spacing: 1.3px; padding: 16px 16px 6px; }
    .dzgrprpt-reason-row    { padding: 12px 16px; gap: 12px; }
    .dzgrprpt-reason-icon   { font-size: 17px; width: 22px; }
    .dzgrprpt-reason-text   { font-size: 13px; }
    .dzgrprpt-check         { width: 21px; height: 21px; font-size: 11px; }
    .dzgrprpt-other-wrap    { padding: 8px 16px 10px; }
    .dzgrprpt-other-textarea{ font-size: 12.5px; padding: 12px 14px; border-radius: 12px; min-height: 90px; }
    .dzgrprpt-footer        { padding: 13px 16px 32px; }
    .dzgrprpt-submit-btn    { padding: 13px; font-size: 13px; border-radius: 12px; }
    .dzgrprpt-cancel-btn    { padding: 11px; font-size: 12.5px; border-radius: 12px; }
    .dzgrprpt-success-icon  { width: 76px; height: 76px; font-size: 32px; }
    .dzgrprpt-success-title { font-size: 17px; }
    .dzgrprpt-success-desc  { font-size: 13px; }
    .dzgrprpt-done-btn      { padding: 13px 40px; font-size: 13.5px; }
}
@media (max-width: 380px) {
    .dzgrprpt-topbar        { padding: 10px 14px; }
    .dzgrprpt-topbar-title  { font-size: 13px; }
    .dzgrprpt-close-btn     { width: 25px; height: 25px; font-size: 14px; }
    .dzgrprpt-section-label { font-size: 8.5px; padding: 14px 14px 5px; }
    .dzgrprpt-reason-row    { padding: 11px 14px; gap: 11px; }
    .dzgrprpt-reason-icon   { font-size: 16px; width: 20px; }
    .dzgrprpt-reason-text   { font-size: 12.5px; }
    .dzgrprpt-check         { width: 20px; height: 20px; }
    .dzgrprpt-other-wrap    { padding: 7px 14px 10px; }
    .dzgrprpt-other-textarea{ font-size: 12px; padding: 11px 13px; border-radius: 11px; }
    .dzgrprpt-footer        { padding: 12px 14px 28px; }
    .dzgrprpt-submit-btn    { padding: 12px; font-size: 12.5px; border-radius: 11px; }
    .dzgrprpt-cancel-btn    { padding: 10px; font-size: 12px; border-radius: 11px; }
    .dzgrprpt-success-icon  { width: 70px; height: 70px; font-size: 28px; margin-bottom: 18px; }
    .dzgrprpt-success-title { font-size: 16px; }
    .dzgrprpt-success-desc  { font-size: 12.5px; }
    .dzgrprpt-done-btn      { padding: 12px 36px; font-size: 13px; }
}
@media (max-width: 330px) {
    .dzgrprpt-topbar        { padding: 9px 12px; }
    .dzgrprpt-topbar-title  { font-size: 12.5px; }
    .dzgrprpt-close-btn     { width: 23px; height: 23px; font-size: 13px; }
    .dzgrprpt-section-label { font-size: 8px; padding: 12px 12px 4px; }
    .dzgrprpt-reason-row    { padding: 10px 12px; gap: 10px; }
    .dzgrprpt-reason-icon   { font-size: 15px; width: 18px; }
    .dzgrprpt-reason-text   { font-size: 12px; }
    .dzgrprpt-check         { width: 19px; height: 19px; font-size: 10px; }
    .dzgrprpt-other-textarea{ font-size: 11.5px; padding: 10px 12px; border-radius: 10px; }
    .dzgrprpt-footer        { padding: 10px 12px 24px; }
    .dzgrprpt-submit-btn    { padding: 11px; font-size: 12px; border-radius: 10px; }
    .dzgrprpt-cancel-btn    { padding: 9px; font-size: 11.5px; border-radius: 10px; }
    .dzgrprpt-success-icon  { width: 64px; height: 64px; font-size: 26px; }
    .dzgrprpt-success-title { font-size: 15px; }
    .dzgrprpt-success-desc  { font-size: 12px; }
}

@keyframes dzGrpRptShakeAnim {
    0%,100%{transform:translateX(0);}
    20%{transform:translateX(-6px);}
    40%{transform:translateX(6px);}
    60%{transform:translateX(-4px);}
    80%{transform:translateX(4px);}
}
</style>


<!-- ══ SCREEN 1: Reason picker — X button kept (this is the entry screen) ══ -->
<div id="dzGroupReportOverlay">

    <div class="dzgrprpt-topbar">
        <div class="dzgrprpt-topbar-spacer"></div>
        <div class="dzgrprpt-topbar-center">
            <div class="dzgrprpt-topbar-title">Why are you reporting this Pod?</div>
            <div class="dzgrprpt-topbar-sub">Reports are private and anonymous</div>
        </div>
        <button class="dzgrprpt-close-btn" onclick="closeReportGroupModal()" aria-label="Close">
            <i class="ri-close-line"></i>
        </button>
    </div>

    <div class="dzgrprpt-section-label">Select a reason</div>
    <div id="dzGrpRptReasonList"></div>

    <div class="dzgrprpt-other-wrap" id="dzGrpRptOtherWrap">
        <textarea class="dzgrprpt-other-textarea" id="dzGrpRptOtherInput"
                  placeholder="Please describe the issue in detail…"
                  maxlength="500"
                  oninput="dzGrpRptCharCount(this)"
                  onfocus="this.style.borderColor='rgba(255,45,45,0.45)'"
                  onblur="this.style.borderColor='rgba(255,255,255,0.09)'"></textarea>
        <div class="dzgrprpt-char-count" id="dzGrpRptCharLabel">0 / 500</div>
    </div>

    <div class="dzgrprpt-footer">
        <button class="dzgrprpt-submit-btn" id="dzGrpRptSubmitBtn" onclick="dzGrpRptSubmit()">
            Submit Report
        </button>
        <button class="dzgrprpt-cancel-btn" onclick="closeReportGroupModal()">Cancel</button>
    </div>

</div>


<!-- ══ SCREEN 2: Success — NO X button ══ -->
<div id="dzGrpRptStep2Screen">

    <div class="dzgrprpt-topbar">
        <div class="dzgrprpt-topbar-spacer"></div>
        <div class="dzgrprpt-topbar-center">
            <div class="dzgrprpt-topbar-title">Report Submitted</div>
        </div>
        <div class="dzgrprpt-topbar-spacer"></div>
    </div>

    <div class="dzgrprpt-success-body">
        <div class="dzgrprpt-success-icon"><i class="ri-shield-check-line"></i></div>
        <div class="dzgrprpt-success-title">Report Submitted</div>
        <div class="dzgrprpt-success-desc">
            Thank you. Your report has been submitted for review.
            The Pod will not know who reported it.
        </div>
        <button class="dzgrprpt-done-btn" onclick="closeReportGroupModal()">Done</button>
    </div>

</div>


<!-- Toast -->
<div id="dzGrpRptToastEl" class="dzgrprpt-toast"></div>


<script>
(function () {
    var _grState = { groupId: null, reason: null };

    var _podReasons = [
        { icon:'ri-prohibited-line',      text:'Hate Speech or Discrimination' },
        { icon:'ri-emotion-unhappy-line', text:'Harassment or Bullying' },
        { icon:'ri-spam-2-line',          text:'Spam or Scam Activity' },
        { icon:'ri-newspaper-line',       text:'Misinformation or Fake News' },
        { icon:'ri-knife-blood-line',     text:'Graphic Violence or Gore' },
        { icon:'ri-eye-2-line',           text:'Nudity or Sexual Content' },
        { icon:'ri-scales-3-line',        text:'Illegal or Dangerous Activity' },
        { icon:'ri-heart-pulse-line',     text:'Self-Harm or Suicide Content' },
        { icon:'ri-shield-user-line',     text:'Child Safety Concern' },
        { icon:'ri-spy-line',             text:'Impersonation or Fake Pod' },
        { icon:'ri-copyright-line',       text:'Copyright Infringement' },
        { icon:'ri-team-line',            text:'Coordinated Abuse' },
        { icon:'ri-question-line',        text:'Other' }
    ];

    /* ── helpers ── */
    function _openScreen(id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.style.display = 'block';
        document.body.style.overflow = 'hidden';
        requestAnimationFrame(function () {
            requestAnimationFrame(function () { el.classList.add('dzgrprpt-open'); });
        });
    }
    function _closeScreen(id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.classList.remove('dzgrprpt-open');
        setTimeout(function () { el.style.display = 'none'; }, 270);
    }
    function _closeAll() {
        ['dzGroupReportOverlay','dzGrpRptStep2Screen'].forEach(_closeScreen);
        document.body.style.overflow = '';
        setTimeout(function () {
            _grState = { groupId: null, reason: null };
            var ta = document.getElementById('dzGrpRptOtherInput');
            if (ta) ta.value = '';
            var cl = document.getElementById('dzGrpRptCharLabel');
            if (cl) cl.textContent = '0 / 500';
            document.getElementById('dzGrpRptOtherWrap').style.display = 'none';
        }, 280);
    }

    /* ── public API ── */
    window.openReportGroupModal = function (groupId) {
        _grState.groupId = groupId;
        _grState.reason  = null;
        _buildReasonScreen();
        _openScreen('dzGroupReportOverlay');
    };

    window.reportGroup = function () {
        if (_grState.groupId) openReportGroupModal(_grState.groupId);
    };

    window.closeReportGroupModal = function () { _closeAll(); };

    function _buildReasonScreen() {
        _grState.reason = null;
        document.getElementById('dzGrpRptOtherWrap').style.display = 'none';

        var list = document.getElementById('dzGrpRptReasonList');
        list.innerHTML = '';
        _podReasons.forEach(function (r) {
            var row = document.createElement('div');
            row.className = 'dzgrprpt-reason-row';
            row.dataset.reason = r.text;
            row.innerHTML =
                '<i class="' + r.icon + ' dzgrprpt-reason-icon" aria-hidden="true"></i>' +
                '<span class="dzgrprpt-reason-text">' + _esc(r.text) + '</span>' +
                '<div class="dzgrprpt-check"></div>';
            row.addEventListener('click', function () { _selectReason(r.text); });
            list.appendChild(row);
        });

        var screen = document.getElementById('dzGroupReportOverlay');
        if (screen) screen.scrollTop = 0;
    }

    function _selectReason(reason) {
        _grState.reason = reason;
        document.querySelectorAll('.dzgrprpt-reason-row').forEach(function (row) {
            var sel = row.dataset.reason === reason;
            row.classList.toggle('selected', sel);
            row.querySelector('.dzgrprpt-check').textContent = sel ? '✓' : '';
        });
        var ow = document.getElementById('dzGrpRptOtherWrap');
        ow.style.display = reason === 'Other' ? 'block' : 'none';
        if (reason !== 'Other') {
            var ta = document.getElementById('dzGrpRptOtherInput');
            if (ta) ta.value = '';
            var cl = document.getElementById('dzGrpRptCharLabel');
            if (cl) cl.textContent = '0 / 500';
        }
    }

    window.dzGrpRptCharCount = function (el) {
        var cl = document.getElementById('dzGrpRptCharLabel');
        if (cl) cl.textContent = el.value.length + ' / 500';
    };

    window.dzGrpRptSubmit = function () {
        if (!_grState.reason) {
            var list = document.getElementById('dzGrpRptReasonList');
            list.style.animation = 'none'; void list.offsetWidth;
            list.style.animation = 'dzGrpRptShakeAnim 0.35s ease';
            setTimeout(function () { list.style.animation = ''; }, 400);
            _showToast('Please select a reason first');
            return;
        }
        if (_grState.reason === 'Other') {
            var ta = document.getElementById('dzGrpRptOtherInput');
            if (!ta.value.trim()) {
                ta.style.borderColor = 'rgba(255,45,45,0.7)';
                _showToast('Please describe the issue');
                return;
            }
        }

        var btn = document.getElementById('dzGrpRptSubmitBtn');
        btn.disabled = true; btn.textContent = 'Submitting…';

        var fd = new FormData();
        fd.append('group_id', _grState.groupId);
        fd.append('reason', _grState.reason);
        var desc = (document.getElementById('dzGrpRptOtherInput').value || '').trim();
        if (desc) fd.append('description', desc);

        fetch('report_group.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                btn.disabled = false; btn.textContent = 'Submit Report';
                if (d.success || d.msg === 'already_reported') {
                    _closeScreen('dzGroupReportOverlay');
                    setTimeout(function () { _openScreen('dzGrpRptStep2Screen'); }, 80);
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
        var t = document.getElementById('dzGrpRptToastEl');
        if (!t) return;
        t.textContent = msg; t.classList.add('show');
        clearTimeout(t._tid);
        t._tid = setTimeout(function () { t.classList.remove('show'); }, 2600);
    }

    function _esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    /* ESC key */
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var s2 = document.getElementById('dzGrpRptStep2Screen');
        var s1 = document.getElementById('dzGroupReportOverlay');
        if (s2 && s2.classList.contains('dzgrprpt-open')) { _closeAll(); }
        else if (s1 && s1.classList.contains('dzgrprpt-open')) { _closeAll(); }
    });

})();
</script>
<?php endif; ?>

<?php
/*
 * ================================================================
 *  REPLACEMENT BLOCK — paste starting from <span class="gh-stat">
 *  Requires: $group_id, $current_user, $member_count, $conn,
 *            $group, $admins, $is_member all already in scope.
 * ================================================================
 */

/* ── CURRENT USER STATS IN THIS GROUP ── */
$cu_post_count    = 0;
$cu_join_date     = null;
$cu_total_likes   = 0;
$cu_total_reposts = 0;
$cu_total_saves   = 0;
$cu_posts         = [];   // for "Manage posts" list

if ($current_user && $is_member) {
    /* join date */
    $jd = $conn->query("SELECT joined_at FROM group_members WHERE group_id=$group_id AND user_id=$current_user");
    if ($jd && $jd->num_rows) { $cu_join_date = $jd->fetch_assoc()['joined_at']; }

    /* post count */
    $pc = $conn->query("SELECT COUNT(*) AS c FROM group_posts WHERE group_id=$group_id AND posted_by=$current_user");
    if ($pc) { $cu_post_count = (int)$pc->fetch_assoc()['c']; }

    /* aggregate interactions */
    $ia = $conn->query("
        SELECT
            COALESCE(SUM(lk.cnt),0)  AS total_likes,
            COALESCE(SUM(rp.cnt),0)  AS total_reposts,
            COALESCE(SUM(fv.cnt),0)  AS total_saves
        FROM group_posts gp
        LEFT JOIN (SELECT meme_id, COUNT(*) AS cnt FROM likes    GROUP BY meme_id) lk ON lk.meme_id = gp.meme_id
        LEFT JOIN (SELECT meme_id, COUNT(*) AS cnt FROM reposts  GROUP BY meme_id) rp ON rp.meme_id = gp.meme_id
        LEFT JOIN (SELECT meme_id, COUNT(*) AS cnt FROM favorites GROUP BY meme_id) fv ON fv.meme_id = gp.meme_id
        WHERE gp.group_id = $group_id AND gp.posted_by = $current_user
    ");
    if ($ia) {
        $ia_row          = $ia->fetch_assoc();
        $cu_total_likes  = (int)$ia_row['total_likes'];
        $cu_total_reposts= (int)$ia_row['total_reposts'];
        $cu_total_saves  = (int)$ia_row['total_saves'];
    }

    /* all posts for manage-posts list */
    $mp_res = $conn->query("
        SELECT gp.id AS gp_id, gp.created_at,
               m.id AS meme_id, m.file_name, m.caption, m.type,
               (SELECT COUNT(*) FROM likes    WHERE meme_id=m.id) AS lc,
               (SELECT COUNT(*) FROM reposts  WHERE meme_id=m.id) AS rc,
               (SELECT COUNT(*) FROM favorites WHERE meme_id=m.id) AS sc
        FROM group_posts gp
        JOIN memes m ON m.id = gp.meme_id
        WHERE gp.group_id = $group_id AND gp.posted_by = $current_user
        ORDER BY gp.created_at DESC
    ");
    if ($mp_res) {
        while ($row = $mp_res->fetch_assoc()) { $cu_posts[] = $row; }
    }
}

/* ── CURRENT USER PROFILE PIC ── */
$cu_pic_raw = $conn->query("SELECT profile_pic FROM users WHERE id=$current_user")->fetch_assoc()['profile_pic'] ?? '';
$cu_pic     = !empty($cu_pic_raw) ? $cu_pic_raw : 'uploads/me.jpeg';
?>

<!-- ════════════════════════════════════════════════════
     MEMBER COUNT STAT
════════════════════════════════════════════════════ -->
<span class="gh-stat">
    <i class="ri-team-line"></i>
    <strong><?php echo number_format($member_count ?? 0); ?></strong> members
    &nbsp;·&nbsp;
    <?php if ($is_private_group): ?>
        <i class="ri-lock-line"></i> Private
    <?php else: ?>
        <i class="ri-earth-line"></i> Public
    <?php endif; ?>
</span>

</div><!-- /gh-actions -->
</div><!-- /gh-body -->

<?php
/* ── TOP CONTRIBUTOR (posts + likes + reposts + saves weighted) ── */
$top_engager = null;
$te_res = $conn->query("
    SELECT u.id, u.username, u.profile_pic,
           COUNT(DISTINCT gp.id) AS post_count,
           COALESCE(SUM(lk.like_cnt), 0) AS total_likes,
           COALESCE(SUM(rp.repost_cnt), 0) AS total_reposts,
           COALESCE(SUM(fv.fav_cnt), 0) AS total_saves,
           (
               COUNT(DISTINCT gp.id) * 3 +
               COALESCE(SUM(lk.like_cnt), 0) * 1 +
               COALESCE(SUM(rp.repost_cnt), 0) * 2 +
               COALESCE(SUM(fv.fav_cnt), 0) * 2
           ) AS contribution_score
    FROM group_posts gp
    JOIN users u ON u.id = gp.posted_by
    LEFT JOIN (
        SELECT meme_id, COUNT(*) AS like_cnt
        FROM likes GROUP BY meme_id
    ) lk ON lk.meme_id = gp.meme_id
    LEFT JOIN (
        SELECT meme_id, COUNT(*) AS repost_cnt
        FROM reposts GROUP BY meme_id
    ) rp ON rp.meme_id = gp.meme_id
    LEFT JOIN (
        SELECT meme_id, COUNT(*) AS fav_cnt
        FROM favorites GROUP BY meme_id
    ) fv ON fv.meme_id = gp.meme_id
    WHERE gp.group_id = $group_id
    GROUP BY gp.posted_by
    ORDER BY contribution_score DESC
    LIMIT 1
");
if ($te_res && $te_res->num_rows > 0) {
    $top_engager = $te_res->fetch_assoc();
}
?>


<?php if ($is_owner || $is_admin): ?>
<?php
/* Reported posts count — computed here so it's available at this
   point in the page (the full query + list is built again later
   near the Group Controls modal, this is just the lightweight count) */
$gh_manage_reported_count = (int)$conn->query("
    SELECT COUNT(*) as c FROM group_post_reports
    WHERE group_id=$group_id AND status='pending'
")->fetch_assoc()['c'];
?>
<a href="group_controls.php?id=<?php echo $group_id; ?>" class="gh-manage-btn">
    <i class="ri-shield-star-fill"></i>
    <span><?php echo $is_owner ? 'Group Settings' : 'Admin Settings'; ?></span>

    <?php if ($is_private_group && $pending_request_count > 0): ?>
    <span class="gh-manage-badge">
        <?php echo $pending_request_count; ?> new
    </span>
    <?php endif; ?>

    <?php if ($gh_manage_reported_count > 0): ?>
    <span class="gh-manage-badge">
        <i class="ri-flag-fill"></i> <?php echo $gh_manage_reported_count; ?>
    </span>
    <?php endif; ?>
</a>
<?php endif; ?>


<?php if ($group_is_paused && $can_view_content): ?>
<div class="pause-notice-card" id="pauseNoticeCard">
    <div class="pnc-top">
        <div class="pnc-icon"><i class="ri-pause-circle-fill"></i></div>
        <div class="pnc-text">
            <div class="pnc-title">
                This group is temporarily paused<?php if ($group_pause_hours_left): ?> for <?php echo $group_pause_hours_left; ?> hour<?php echo $group_pause_hours_left == 1 ? '' : 's'; ?><?php endif; ?>
            </div>
        </div>
        <button class="pnc-close" onclick="dismissPauseNotice()" aria-label="Dismiss"><i class="ri-close-line"></i></button>
    </div>
    <div class="pnc-body">
        An <?php echo $pause_actor_label; ?> paused this group on <?php echo date('j F Y', strtotime($group['paused_at'])); ?>.
        <?php if ($group_pause_resumes_at): ?>
            Group activity will resume on <?php echo date('j F Y', $group_pause_resumes_at); ?> at <?php echo date('H:i', $group_pause_resumes_at); ?>.
        <?php endif; ?>
    </div>
    <div class="pnc-actions">
        <button class="pnc-btn pnc-btn-learn" onclick="openPauseLearnMore()">Learn more</button>
        <?php if ($is_owner || $is_admin): ?>
        <button class="pnc-btn pnc-btn-manage" onclick="openPauseManage()">Manage</button>
        <?php endif; ?>
    </div>
</div>

<!-- ══ PAUSE MANAGE (bottom sheet mobile / modal desktop) ══ -->
<div id="pauseManageOverlay" class="pnc-overlay" onclick="if(event.target===this) closePauseManage()">
    <div class="pnc-panel">
        <div class="pnc-panel-handle"></div>
        <div class="pnc-panel-header">
            <div class="pnc-panel-icon"><i class="ri-pause-circle-fill"></i></div>
            <div class="pnc-panel-header-text">
                <div class="pnc-panel-title">Manage Pause</div>
                <div class="pnc-panel-sub"><?php echo htmlspecialchars($group['title']); ?></div>
            </div>
            <button class="pnc-panel-close" onclick="closePauseManage()" aria-label="Close"><i class="ri-close-line"></i></button>
        </div>
        <div class="pnc-panel-body">
            <p>This group is currently paused. Members can't post, react, upload files, or request to join until it's resumed.</p>
            <?php if ($group_pause_resumes_at): ?>
            <p class="pnc-panel-auto">It resumes automatically on <strong><?php echo date('j F Y', $group_pause_resumes_at); ?></strong> at <strong><?php echo date('H:i', $group_pause_resumes_at); ?></strong> — or resume it now.</p>
            <?php endif; ?>
        </div>
        <form method="POST" action="group.php?id=<?php echo $group_id; ?>" onsubmit="return confirm('Resume this group now? Members will be able to post, react and join again.');">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <button type="submit" name="toggle_pause" class="pnc-unpause-btn">
                <i class="ri-play-circle-line"></i> Unpause Group
            </button>
        </form>
        <button class="pnc-cancel-btn" onclick="closePauseManage()">Cancel</button>
    </div>
</div>

<!-- ══ PAUSE LEARN MORE ══ -->
<div id="pauseLearnMoreOverlay" class="pnc-overlay" onclick="if(event.target===this) closePauseLearnMore()">
    <div class="pnc-panel">
        <div class="pnc-panel-handle"></div>
        <div class="pnc-panel-header">
            <div class="pnc-panel-icon" style="background:rgba(77,171,255,.12);color:#4dabff;"><i class="ri-information-line"></i></div>
            <div class="pnc-panel-header-text">
                <div class="pnc-panel-title">What pausing does</div>
                <div class="pnc-panel-sub">While this group is paused</div>
            </div>
            <button class="pnc-panel-close" onclick="closePauseLearnMore()" aria-label="Close"><i class="ri-close-line"></i></button>
        </div>
        <div class="pnc-panel-body">
            <ul class="pnc-learn-list">
                <li><i class="ri-close-circle-fill" style="color:#ff7a7a;"></i> Members can't create new posts</li>
                <li><i class="ri-close-circle-fill" style="color:#ff7a7a;"></i> Members can't react to posts</li>
                <li><i class="ri-close-circle-fill" style="color:#ff7a7a;"></i> Members can't create new events</li>
                <li><i class="ri-close-circle-fill" style="color:#ff7a7a;"></i> Members can't upload files</li>
                <li><i class="ri-close-circle-fill" style="color:#ff7a7a;"></i> New join requests are paused</li>
                <li><i class="ri-checkbox-circle-fill" style="color:#4ade80;"></i> Everyone can still browse existing posts, photos, videos, files and discussions</li>
                <li><i class="ri-checkbox-circle-fill" style="color:#4ade80;"></i> Admins and the owner can still moderate, change settings and post announcements</li>
            </ul>
        </div>
        <button class="pnc-cancel-btn" onclick="closePauseLearnMore()">Got it</button>
    </div>
</div>
<?php endif; ?>


<style>
/* ── PAUSE NOTICE CARD ── */
.pause-notice-card{
    background: rgba(1, 18, 66, 0.22);
    border: 1px solid  rgba(255,255,255,.09);
    border-radius: 13px;
    padding: 16px 16px 14px;
    margin: 4px 0 18px;
}
.pnc-top{ display:flex; align-items:flex-start; gap:12px; }
.pnc-icon{
    width:38px; height:38px; border-radius:50%; flex-shrink:0;
    background: rgba(255,149,0,.14); color:#FF9500;
    display:flex; align-items:center; justify-content:center; font-size:1.15rem;
}
.pnc-text{ flex:1; min-width:0; }
.pnc-title{ font-size:.92rem; font-weight:700; color:#fff; line-height:1.35; font-family:'Poppins',sans-serif; }
.pnc-close{
    width:28px; height:28px; border-radius:50%; flex-shrink:0;
    background: rgba(255,255,255,.06); border:none; color:rgba(255,255,255,.5);
    display:flex; align-items:center; justify-content:center; font-size:1rem; cursor:pointer;
    transition:.2s; -webkit-tap-highlight-color:transparent;
}
.pnc-close:hover{ background:rgba(255,255,255,.12); color:#fff; }
.pnc-body{
    font-size:.8rem; color:rgba(255,255,255,.55); line-height:1.6;
    margin:10px 0 16px; font-family:'Poppins',sans-serif;
}
.pnc-actions{ display:flex; gap:10px; }
.pnc-btn{
    flex:1; padding:11px; border-radius:12px; border:none;
    font-size:.82rem; font-weight:700; font-family:'Poppins',sans-serif;
    cursor:pointer; transition:.2s; -webkit-tap-highlight-color:transparent;
}
.pnc-btn-learn{ background:rgba(255,255,255,.08); color:rgba(255,255,255,.75); }
.pnc-btn-learn:hover{ background:rgba(255,255,255,.14); color:#fff; }
.pnc-btn-manage{ background:#045cc0; color:#fff; }
.pnc-btn-manage:hover{ background:#0a6ad9; }

/* ── SHARED OVERLAY (bottom sheet mobile / modal desktop) ── */
.pnc-overlay{
    display:none; position:fixed; inset:0; z-index:999999999;
    background:rgba(0,0,0,.6); backdrop-filter:blur(4px); -webkit-backdrop-filter:blur(4px);
    align-items:flex-end; justify-content:center;
}
.pnc-overlay.pnc-open{ display:flex; }
.pnc-panel{
    width:100%; max-width:480px;
    background:rgba(12,12,28,.98); backdrop-filter:blur(18px); -webkit-backdrop-filter:blur(18px);
    border:1px solid rgba(255,255,255,.08); border-top:none;
    border-radius:20px 20px 0 0;
    padding:8px 18px calc(18px + env(safe-area-inset-bottom));
    transform:translateY(100%); transition:transform .3s cubic-bezier(.32,.72,0,1);
    box-shadow:0 -10px 40px rgba(0,0,0,.5);
}
.pnc-overlay.pnc-open .pnc-panel{ transform:translateY(0); }
.pnc-panel-handle{ width:34px; height:3.5px; border-radius:99px; background:rgba(255,255,255,.2); margin:0 auto 14px; }
.pnc-panel-header{ display:flex; align-items:center; gap:12px; margin-bottom:14px; }
.pnc-panel-icon{
    width:40px; height:40px; border-radius:12px; flex-shrink:0;
    background:rgba(255,149,0,.14); color:#FF9500;
    display:flex; align-items:center; justify-content:center; font-size:1.15rem;
}
.pnc-panel-header-text{ flex:1; min-width:0; }
.pnc-panel-title{ font-size:1rem; font-weight:700; color:#fff; font-family:'Poppins',sans-serif; }
.pnc-panel-sub{ font-size:.72rem; color:rgba(255,255,255,.4); margin-top:2px; font-family:'Poppins',sans-serif; }
.pnc-panel-close{
    width:28px; height:28px; border-radius:50%; flex-shrink:0;
    background:rgba(255,255,255,.06); border:none; color:rgba(255,255,255,.5);
    display:flex; align-items:center; justify-content:center; font-size:1rem; cursor:pointer;
}
.pnc-panel-close:hover{ background:rgba(255,255,255,.12); color:#fff; }
.pnc-panel-body{ margin-bottom:16px; }
.pnc-panel-body p{
    font-size:.8rem; color:rgba(255,255,255,.6); line-height:1.65; font-family:'Poppins',sans-serif;
    margin-bottom:10px;
}
.pnc-panel-body p:last-child{ margin-bottom:0; }
.pnc-panel-auto strong{ color:#fff; }
.pnc-learn-list{ list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap:9px; }
.pnc-learn-list li{
    display:flex; align-items:flex-start; gap:9px;
    font-size:.78rem; color:rgba(255,255,255,.68); line-height:1.5; font-family:'Poppins',sans-serif;
}
.pnc-learn-list li i{ font-size:1rem; flex-shrink:0; margin-top:1px; }
.pnc-unpause-btn{
    width:100%; padding:13px; border-radius:14px; border:none;
    background:#045cc0; color:#fff; font-size:.85rem; font-weight:700;
    font-family:'Poppins',sans-serif; cursor:pointer; transition:.2s;
    display:flex; align-items:center; justify-content:center; gap:7px;
}
.pnc-unpause-btn:hover{ background:#0a6ad9; }
.pnc-cancel-btn{
    width:100%; padding:12px; margin-top:9px; border-radius:14px;
    background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.1);
    color:rgba(255,255,255,.65); font-size:.8rem; font-weight:600;
    font-family:'Poppins',sans-serif; cursor:pointer; transition:.2s;
}
.pnc-cancel-btn:hover{ background:rgba(255,255,255,.11); color:#fff; }

/* Desktop: centered modal instead of bottom sheet */
@media (min-width:791px){
    .pnc-overlay{ align-items:center; }
    .pnc-panel{
        border-radius:20px; border-top:1px solid rgba(255,255,255,.08);
        transform:scale(.94); opacity:0; transition:transform .22s ease, opacity .22s ease;
        padding:20px 22px calc(20px + env(safe-area-inset-bottom));
    }
    .pnc-overlay.pnc-open .pnc-panel{ transform:scale(1); opacity:1; }
    .pnc-panel-handle{ display:none; }
}

/* ══════════════════════════════════════════════════
   BREAKPOINT 1 — max-width: 790px
   ══════════════════════════════════════════════════ */
@media (max-width:790px){
    .pause-notice-card{ border-radius:6px; padding:13px 13px 11px; margin:3px 0 14px; }
    .pnc-top{ gap:9px; }
    .pnc-icon{ width:32px; height:32px; font-size:.98rem; }
    .pnc-title{ font-size:.84rem; }
    .pnc-close{ width:24px; height:24px; font-size:.85rem; }
    .pnc-body{ font-size:.73rem; margin:7px 0 12px; }
    .pnc-actions{ gap:7px; }
    .pnc-btn{ padding:9px; font-size:.73rem; border-radius:10px; }

    .pnc-panel{ max-width:430px; padding:7px 14px calc(14px + env(safe-area-inset-bottom)); }
    .pnc-panel-handle{ width:29px; height:3px; margin-bottom:10px; }
    .pnc-panel-header{ gap:9px; margin-bottom:10px; }
    .pnc-panel-icon{ width:33px; height:33px; border-radius:9px; font-size:.98rem; }
    .pnc-panel-title{ font-size:.87rem; }
    .pnc-panel-sub{ font-size:.64rem; }
    .pnc-panel-close{ width:24px; height:24px; font-size:.85rem; }
    .pnc-panel-body{ margin-bottom:12px; }
    .pnc-panel-body p{ font-size:.73rem; margin-bottom:7px; }
    .pnc-learn-list{ gap:6px; }
    .pnc-learn-list li{ font-size:.7rem; gap:6px; }
    .pnc-learn-list li i{ font-size:.87rem; }
    .pnc-unpause-btn{ padding:10.5px; border-radius:11px; font-size:.75rem; gap:5px; }
    .pnc-cancel-btn{ padding:9.5px; margin-top:7px; border-radius:11px; font-size:.72rem; }
}

/* ══════════════════════════════════════════════════
   BREAKPOINT 2 — max-width: 490px
   ══════════════════════════════════════════════════ */
@media (max-width:490px){
    .pause-notice-card{ border-radius:6px; padding:11px 11px 10px; margin:3px 0 11px; }
    .pnc-top{ gap:8px; }
    .pnc-icon{ width:29px; height:29px; font-size:.9rem; }
    .pnc-title{ font-size:.78rem; }
    .pnc-close{ width:22px; height:22px; font-size:.78rem; }
    .pnc-body{ font-size:.68rem; margin:6px 0 10px; }
    .pnc-actions{ gap:6px; }
    .pnc-btn{ padding:7.5px; font-size:.68rem; border-radius:9px; }

    .pnc-panel{ padding:7px 12px calc(12px + env(safe-area-inset-bottom)); }
    .pnc-panel-handle{ width:27px; height:3px; margin-bottom:9px; }
    .pnc-panel-header{ gap:8px; margin-bottom:9px; }
    .pnc-panel-icon{ width:30px; height:30px; border-radius:8px; font-size:.9rem; }
    .pnc-panel-title{ font-size:.81rem; }
    .pnc-panel-sub{ font-size:.6rem; }
    .pnc-panel-close{ width:22px; height:22px; font-size:.78rem; }
    .pnc-panel-body{ margin-bottom:10px; }
    .pnc-panel-body p{ font-size:.68rem; margin-bottom:6px; }
    .pnc-learn-list{ gap:5px; }
    .pnc-learn-list li{ font-size:.65rem; gap:5px; }
    .pnc-learn-list li i{ font-size:.8rem; }
    .pnc-unpause-btn{ padding:8.5px; border-radius:10px; font-size:.69rem; gap:4px; }
    .pnc-cancel-btn{ padding:8px; margin-top:6px; border-radius:10px; font-size:.66rem; }
}

/* ══════════════════════════════════════════════════
   BREAKPOINT 3 — max-width: 390px
   ══════════════════════════════════════════════════ */
@media (max-width:390px){
    .pause-notice-card{ border-radius:6px; padding:9.5px 9.5px 8.5px; margin:2px 0 9px; }
    .pnc-top{ gap:7px; }
    .pnc-icon{ width:25px; height:25px; font-size:.78rem; }
    .pnc-title{ font-size:.71rem; line-height:1.3; }
    .pnc-close{ width:19px; height:19px; font-size:.68rem; }
    .pnc-body{ font-size:.62rem; line-height:1.5; margin:5px 0 8px; }
    .pnc-actions{ gap:5px; }
    .pnc-btn{ padding:6.5px; font-size:.61rem; border-radius:8px; }

    .pnc-panel{ padding:6px 9px calc(9px + env(safe-area-inset-bottom)); }
    .pnc-panel-handle{ width:23px; height:2.5px; margin-bottom:8px; }
    .pnc-panel-header{ gap:7px; margin-bottom:8px; }
    .pnc-panel-icon{ width:26px; height:26px; border-radius:7px; font-size:.78rem; }
    .pnc-panel-title{ font-size:.73rem; }
    .pnc-panel-sub{ font-size:.56rem; }
    .pnc-panel-close{ width:19px; height:19px; font-size:.68rem; }
    .pnc-panel-body{ margin-bottom:8px; }
    .pnc-panel-body p{ font-size:.62rem; line-height:1.5; margin-bottom:5px; }
    .pnc-learn-list{ gap:4px; }
    .pnc-learn-list li{ font-size:.59rem; gap:4px; }
    .pnc-learn-list li i{ font-size:.72rem; }
    .pnc-unpause-btn{ padding:7.5px; border-radius:9px; font-size:.63rem; gap:4px; }
    .pnc-cancel-btn{ padding:7px; margin-top:5px; border-radius:9px; font-size:.61rem; }
}

/* ══════════════════════════════════════════════════
   BREAKPOINT 4 — max-width: 330px
   ══════════════════════════════════════════════════ */
@media (max-width:330px){
    .pause-notice-card{ border-radius:6px; padding:8px 8px 7px; margin:2px 0 8px; }
    .pnc-top{ gap:6px; }
    .pnc-icon{ width:22px; height:22px; font-size:.68rem; }
    .pnc-title{ font-size:.64rem; line-height:1.25; }
    .pnc-close{ width:17px; height:17px; font-size:.6rem; }
    .pnc-body{ font-size:.56rem; line-height:1.45; margin:4px 0 7px; }
    .pnc-actions{ gap:4px; flex-wrap:wrap; }
    .pnc-btn{ padding:6px; font-size:.56rem; border-radius:7px; min-width:100%; }
    .pnc-btn + .pnc-btn{ margin-top:0; }

    .pnc-panel{ padding:5px 7px calc(7px + env(safe-area-inset-bottom)); }
    .pnc-panel-handle{ width:20px; height:2.5px; margin-bottom:7px; }
    .pnc-panel-header{ gap:6px; margin-bottom:7px; }
    .pnc-panel-icon{ width:23px; height:23px; border-radius:6px; font-size:.68rem; }
    .pnc-panel-title{ font-size:.67rem; }
    .pnc-panel-sub{ font-size:.51rem; }
    .pnc-panel-close{ width:17px; height:17px; font-size:.6rem; }
    .pnc-panel-body{ margin-bottom:7px; }
    .pnc-panel-body p{ font-size:.56rem; line-height:1.45; margin-bottom:4px; }
    .pnc-learn-list{ gap:4px; }
    .pnc-learn-list li{ font-size:.53rem; gap:4px; }
    .pnc-learn-list li i{ font-size:.64rem; }
    .pnc-unpause-btn{ padding:6.5px; border-radius:8px; font-size:.58rem; gap:3px; }
    .pnc-cancel-btn{ padding:6px; margin-top:4px; border-radius:8px; font-size:.56rem; }
}
</style>

<script>
function openPauseManage(){
    document.getElementById('pauseManageOverlay').classList.add('pnc-open');
    document.body.style.overflow = 'hidden';
}
function closePauseManage(){
    document.getElementById('pauseManageOverlay').classList.remove('pnc-open');
    document.body.style.overflow = '';
}
function openPauseLearnMore(){
    document.getElementById('pauseLearnMoreOverlay').classList.add('pnc-open');
    document.body.style.overflow = 'hidden';
}
function closePauseLearnMore(){
    document.getElementById('pauseLearnMoreOverlay').classList.remove('pnc-open');
    document.body.style.overflow = '';
}
function dismissPauseNotice(){
    var card = document.getElementById('pauseNoticeCard');
    if (card) card.style.display = 'none';
    try { sessionStorage.setItem('pnc_dismissed_<?php echo (int)$group_id; ?>', '1'); } catch(e){}
}
(function(){
    try {
        if (sessionStorage.getItem('pnc_dismissed_<?php echo (int)$group_id; ?>')) {
            var c = document.getElementById('pauseNoticeCard');
            if (c) c.style.display = 'none';
        }
    } catch(e){}
})();
document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') { closePauseManage(); closePauseLearnMore(); }
});
</script>

<!-- ════════════════════════════════════════════════════
     NAV BAR
════════════════════════════════════════════════════ -->
<?php
$gh_member_locked = !($is_member || $is_owner || $is_admin);          // You / Events / Files
$gh_nav_locked     = $is_private_group && !($is_member || $is_owner || $is_admin); // See more only

// NEW: while the pod is paused, regular members (not owner/admin) lose
// access to everything except Memepedia and See more.
$gh_paused_locked  = $group_is_paused && !($is_owner || $is_admin);
$gh_pause_msg      = 'This pod is paused — check back later';
?>
<div class="gh-nav-bar">
    <a href="memepedia.php?group_id=<?php echo $group_id; ?>" class="gh-nav-item">Memepedia</a>
    <div class="gh-nav-sep"></div>

    <?php if ($gh_paused_locked): ?>
        <span class="gh-nav-item gh-nav-locked" onclick="showDiscLockToast('<?php echo htmlspecialchars($gh_pause_msg); ?>')">Highlights</span>
    <?php else: ?>
        <a href="group_highlights.php?group_id=<?php echo $group_id; ?>" class="gh-nav-item">Highlights</a>
    <?php endif; ?>
    <div class="gh-nav-sep"></div>

    <?php if ($gh_paused_locked): ?>
        <span class="gh-nav-item gh-nav-locked" onclick="showDiscLockToast('<?php echo htmlspecialchars($gh_pause_msg); ?>')" style="opacity:0.4;">You</span>
    <?php elseif ($is_member): ?>
        <div class="gh-nav-item gh-nav-you" onclick="openYouModal()">
            <img src="<?php echo htmlspecialchars($cu_pic); ?>"
                 onerror="this.src='uploads/me.jpeg'"
                 alt="You"
                 class="gh-nav-you-pic">
            <span>You</span>
        </div>
    <?php else: ?>
        <span class="gh-nav-item gh-nav-locked" onclick="showDiscLockToast('Join this pod to access You')" style="opacity:0.4;">You</span>
    <?php endif; ?>

    <div class="gh-nav-sep"></div>

    <?php if ($gh_nav_locked): ?>
        <span class="gh-nav-item gh-nav-locked" onclick="showDiscLockToast('Join this pod to access See more')">See more</span>
    <?php else: ?>
        <a href="group_more.php?id=<?php echo $group_id; ?>" class="gh-nav-item">See more</a>
    <?php endif; ?>

    <div class="gh-nav-sep"></div>

    <?php if ($gh_paused_locked): ?>
        <span class="gh-nav-item gh-nav-locked" onclick="showDiscLockToast('<?php echo htmlspecialchars($gh_pause_msg); ?>')">
            <i class="ri-calendar-event-line"></i> Events
        </span>
    <?php elseif ($gh_member_locked): ?>
        <span class="gh-nav-item gh-nav-locked" onclick="showDiscLockToast('Join this pod to access Events')">
            <i class="ri-calendar-event-line"></i> Events
        </span>
    <?php else: ?>
        <a href="group_events.php?id=<?php echo $group_id; ?>" class="gh-nav-item">
            <i class="ri-calendar-event-line"></i> Events
        </a>
    <?php endif; ?>

    <div class="gh-nav-sep"></div>

    <?php if ($gh_paused_locked): ?>
        <span class="gh-nav-item gh-nav-locked" onclick="showDiscLockToast('<?php echo htmlspecialchars($gh_pause_msg); ?>')">
            <i class="ri-folder-line"></i> Files
        </span>
    <?php elseif ($gh_member_locked): ?>
        <span class="gh-nav-item gh-nav-locked" onclick="showDiscLockToast('Join this pod to access Files')">
            <i class="ri-folder-line"></i> Files
        </span>
    <?php else: ?>
        <a href="group_files.php?id=<?php echo $group_id; ?>" class="gh-nav-item">
            <i class="ri-folder-line"></i> Files
        </a>
    <?php endif; ?>
<div class="gh-nav-sep"></div>

    <a href="group_help.php?id=<?php echo $group_id; ?>" class="gh-nav-item">
        <i class="ri-question-line"></i> Help Centre
    </a>
</div>

<!-- ════════════════════════════════════════════════════
     TOP CONTRIBUTOR OAT ROW — all-time score, never resets
════════════════════════════════════════════════════ -->
<?php if ($top_engager && $can_view_content): ?>
<?php
$te_pic = !empty($top_engager['profile_pic'])
    ? htmlspecialchars($top_engager['profile_pic'])
    : 'uploads/me.jpeg';
?>
<a href="profile.php?user_id=<?php echo (int)$top_engager['id']; ?>" class="te-row">
    <img src="<?php echo $te_pic; ?>" onerror="this.src='uploads/me.jpeg'" class="te-pic" alt="">
    <div class="te-text">
        <span class="te-label">
            <i class="ri-trophy-line"></i>
            <?php echo htmlspecialchars($group['title']); ?>'s Top Contributor
            <span class="te-oat-tag" title="Of All Time — this score never resets">OAT</span>
        </span>
        <span class="te-username"><?php echo htmlspecialchars($top_engager['username']); ?></span>
        <span class="te-posts">
            <?php echo (int)$top_engager['post_count']; ?> posts ·
            <?php echo number_format((int)$top_engager['total_likes']); ?> likes ·
            <?php echo number_format((int)$top_engager['total_reposts']); ?> reposts ·
            <?php echo number_format((int)$top_engager['total_saves']); ?> saves
        </span>
    </div>
    <i class="ri-arrow-right-s-line te-arrow"></i>
</a>
<?php endif; ?>

</div><!-- /gh -->

<!-- ════════════════════════════════════════════════════
     YOU MODAL
════════════════════════════════════════════════════ -->
<?php if ($is_member): ?>
<div id="youModal">

    <div class="ym-topbar">
        <button class="ym-back" onclick="closeYouModal()" aria-label="Close">
            <i class="ri-arrow-left-line"></i>
        </button>
        <span class="ym-topbar-title">You in this Pod</span>
        <div style="width:36px;"></div>
    </div>

    <div class="ym-body">

        <div class="ym-pic-wrap">
            <img src="<?php echo htmlspecialchars($cu_pic); ?>"
                 onerror="this.src='uploads/me.jpeg'"
                 class="ym-big-pic" alt="Your profile picture">
        </div>

        <div class="ym-pill-bar">
            <button class="ym-pill active" onclick="ymTab(this,'ym-overview')">Overview</button>
            <button class="ym-pill"        onclick="ymTab(this,'ym-manage')">Manage posts</button>
        </div>

       <!-- OVERVIEW TAB -->
<div id="ym-overview" class="ym-tab active">

    <div class="ym-accordion" onclick="ymToggleAccordion(this)">
        <div class="ym-acc-head">
            <i class="ri-question-line"></i>
            <span>Learn about contributor badges</span>
            <i class="ri-arrow-down-s-line ym-acc-chevron"></i>
        </div>
        <div class="ym-acc-body">
            <p>This pod tracks contribution in four different ways. Each badge measures something different on purpose — so recognition doesn't always go to the same one or two people.</p>

            <div class="ym-badge-explainer">
                <div class="ym-be-head">
                    <i class="ri-trophy-line" style="color:#8fb4ff;"></i>
                    <strong>Top Contributor OAT</strong>
                    <span class="ym-be-where">Group header</span>
                </div>
                <p class="ym-be-desc"><strong>OAT</strong> means "of all time." This is the single highest-scoring member across the pod's entire history — it never resets, so once someone earns it by racking up enough posts and engagement, they hold it until someone else's all-time score overtakes theirs.</p>
            </div>

            <div class="ym-badge-explainer">
                <div class="ym-be-head">
                    <i class="ri-trophy-line" style="color:#1a56db;"></i>
                    <strong>Top Contributor</strong>
                    <span class="ym-be-where">Members list</span>
                </div>
                <p class="ym-be-desc">The highest-scoring member over the <strong>last 30 days only</strong>. Unlike the OAT badge, this one rolls forward continuously — it always reflects who's been active recently, not who was active months or years ago. This is the badge that's actually winnable if you're new.</p>
            </div>

            <div class="ym-badge-explainer">
                <div class="ym-be-head">
                    <i class="ri-arrow-up-double-line" style="color:#4d8fff;"></i>
                    <strong>Rising Contributor</strong>
                    <span class="ym-be-where">Members list</span>
                </div>
                <p class="ym-be-desc">Goes to whoever's activity <strong>jumped the most</strong> compared to the week before — this week's score has to be at least 50% higher than last week's. It only fires on a genuine spike in activity, not normal week-to-week ups and downs.</p>
            </div>

            <div class="ym-badge-explainer">
                <div class="ym-be-head">
                    <i class="ri-calendar-check-line" style="color:#8fb4ff;"></i>
                    <strong>Most Consistent</strong>
                    <span class="ym-be-where">Members list</span>
                </div>
                <p class="ym-be-desc">Not about volume — this tracks how many <strong>different days</strong> you posted on in the last 30 days. Ten posts in one afternoon only counts as one active day; one post every few days for two weeks counts as several. It rewards showing up regularly over time, not one big burst.</p>
            </div>

            <hr class="ym-be-divider">

            <p>All four badges are built from the same underlying score:</p>
            <ul>
                <li><i class="ri-image-add-line"></i> <strong>Posts</strong> — each post adds 3 points to your score.</li>
                <li><i class="ri-heart-line"></i> <strong>Likes received</strong> — each like on your posts adds 1 point.</li>
                <li><i class="ri-repeat-line"></i> <strong>Reposts received</strong> — each repost adds 2 points.</li>
                <li><i class="ri-bookmark-line"></i> <strong>Saves received</strong> — each save adds 2 points.</li>
            </ul>
            <p>Tip: quality posts that get reposts and saves earn points faster than quantity alone. And since three of the four badges reset on rolling windows, being active <em>now</em> matters just as much as your history — only the OAT badge is locked to all-time totals.</p>
        </div>
    </div>

            <div class="ym-stats-grid">
                <div class="ym-stat-card">
                    <span class="ym-stat-num"><?php echo $cu_post_count; ?></span>
                    <span class="ym-stat-lbl">Posts</span>
                </div>
                <div class="ym-stat-card">
                    <span class="ym-stat-num"><?php echo number_format($cu_total_likes); ?></span>
                    <span class="ym-stat-lbl">Likes received</span>
                </div>
                <div class="ym-stat-card">
                    <span class="ym-stat-num"><?php echo number_format($cu_total_reposts); ?></span>
                    <span class="ym-stat-lbl">Reposts</span>
                </div>
                <div class="ym-stat-card">
                    <span class="ym-stat-num"><?php echo number_format($cu_total_saves); ?></span>
                    <span class="ym-stat-lbl">Saves</span>
                </div>
            </div>

            <?php if ($cu_join_date): ?>
            <div class="ym-join-row">
                <i class="ri-calendar-check-line"></i>
                Joined this pod on
                <strong><?php echo date('d M Y', strtotime($cu_join_date)); ?></strong>
            </div>
            <?php endif; ?>

        </div><!-- /ym-overview -->

        <!-- MANAGE POSTS TAB -->
        <div id="ym-manage" class="ym-tab">

            <div class="ym-sort-row">
                <button class="ym-sort-pill active" onclick="ymSort('newest',this)">Newest</button>
                <button class="ym-sort-pill"        onclick="ymSort('popular',this)">Popular</button>
                <button class="ym-sort-pill"        onclick="ymSort('oldest',this)">Oldest</button>
            </div>

            <?php if (empty($cu_posts)): ?>
            <div class="ym-no-posts">
                <i class="ri-image-line"></i>
                <p>You haven't posted in this pod yet.</p>
            </div>
            <?php else: ?>
            <div id="ym-post-list">
                <?php foreach ($cu_posts as $cp):
                    $cp_thumb = '';
                    if ($cp['type'] === 'image' && !empty($cp['file_name']) && $cp['file_name'] !== '0') {
                        $cp_thumb = 'uploads/' . $cp['file_name'];
                    } elseif ($cp['type'] === 'video' && !empty($cp['file_name']) && $cp['file_name'] !== '0') {
                        $cp_thumb = 'uploads/' . $cp['file_name'];
                    }
                    $cp_cap   = htmlspecialchars(mb_strlen($cp['caption']) > 60 ? mb_substr($cp['caption'],0,60).'…' : $cp['caption']);
                    $cp_date  = date('d M Y', strtotime($cp['created_at']));
                    $cp_total = (int)$cp['lc'] + (int)$cp['rc'] + (int)$cp['sc'];
                ?>
                <div class="ym-post-row"
                     data-newest="<?php echo strtotime($cp['created_at']); ?>"
                     data-popular="<?php echo $cp_total; ?>"
                     data-gp="<?php echo (int)$cp['gp_id']; ?>"
                     data-mid="<?php echo (int)$cp['meme_id']; ?>">

                    <div class="ym-post-thumb">
                        <?php if ($cp['type'] === 'video' && $cp_thumb): ?>
                            <video src="<?php echo htmlspecialchars($cp_thumb); ?>" muted playsinline preload="metadata"></video>
                            <i class="ri-play-fill ym-play-overlay"></i>
                        <?php elseif ($cp_thumb): ?>
                            <img src="<?php echo htmlspecialchars($cp_thumb); ?>" onerror="this.src='uploads/Text.jpg'" alt="">
                        <?php else: ?>
                            <div class="ym-text-thumb"><i class="ri-file-text-line"></i></div>
                        <?php endif; ?>
                    </div>

                    <div class="ym-post-info">
                        <div class="ym-post-cap"><?php echo $cp_cap; ?></div>
                        <div class="ym-post-meta">
                            <span><i class="ri-heart-line"></i> <?php echo (int)$cp['lc']; ?></span>
                            <span><i class="ri-repeat-line"></i> <?php echo (int)$cp['rc']; ?></span>
                            <span><i class="ri-bookmark-line"></i> <?php echo (int)$cp['sc']; ?></span>
                            <span class="ym-post-date"><?php echo $cp_date; ?></span>
                        </div>
                    </div>

                    <form method="POST" onclick="event.stopPropagation()"
                          onsubmit="return confirm('Permanently delete this post from the group?')">
                        <input type="hidden" name="delete_post_id"  value="<?php echo (int)$cp['gp_id']; ?>">
                        <input type="hidden" name="delete_meme_id"  value="<?php echo (int)$cp['meme_id']; ?>">
                        <button type="submit" name="delete_post" class="ym-del-btn" title="Delete post">
                            <i class="ri-delete-bin-line"></i>
                        </button>
                    </form>

                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

        </div><!-- /ym-manage -->

    </div><!-- /ym-body -->
</div><!-- /youModal -->
<?php endif; ?>


<style>
.gh-nav-locked {
    cursor: default;
    opacity: 0.45;
}
.gh-nav-locked:hover,
.gh-nav-locked:active {
    color: #1a56db;
    background: transparent;
}
.gh-nav-item i {
    margin-right: 6px;
}

/* ── MANAGE BUTTON — matches reference image ── */
.gh-manage-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    width: 100%;
    padding: 11px 16px;
    margin: 8px 0 3px;
    border-radius: 12px;
    background: #0a3d91;
    color: #fff;
    font-family: 'Poppins', sans-serif;
    font-size: 13.5px;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
    transition: background 0.2s ease, transform 0.15s ease;
    box-sizing: border-box;
    -webkit-tap-highlight-color: transparent;
    flex-wrap: wrap;
}
.gh-manage-btn i {
    font-size: 13.5px;
    color: #fff;
}
.gh-manage-btn:hover {
    background: #0d4bb3;
}
.gh-manage-btn:active {
    transform: scale(0.98);
}

.gh-manage-badge {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    background: #FF2D2D;
    color: #fff;
    font-size: 9px;
    font-weight: 700;
    padding: 1px 7px;
    border-radius: 20px;
    line-height: 1.6;
    flex-shrink: 0;
}
.gh-manage-badge i {
    font-size: 8px;
    color: #fff;
}

@media (max-width: 790px) {
    .gh-manage-btn { padding: 9px 13px; font-size: 12px; border-radius: 5px; margin: 6px 0 3px; gap: 5px; }
    .gh-manage-btn i { font-size: 12px; }
    .gh-manage-badge { font-size: 8.5px; padding: 1px 6px; }
    .gh-manage-badge i { font-size: 7.5px; }
}
@media (max-width: 490px) {
    .gh-manage-btn { padding: 7px 11px; font-size: 10.5px; border-radius: 5px; margin: 5px 0 2px; gap: 4px; }
    .gh-manage-btn i { font-size: 10.5px; }
    .gh-manage-badge { font-size: 8px; padding: 1px 5px; gap: 2px; }
    .gh-manage-badge i { font-size: 7px; }
}
@media (max-width: 380px) {
    .gh-manage-btn { padding: 6px 9px; font-size: 9.5px; border-radius: 5px; margin: 4px 0 2px; gap: 4px; }
    .gh-manage-btn i { font-size: 9.5px; }
    .gh-manage-badge { font-size: 7.5px; padding: 1px 5px; }
    .gh-manage-badge i { font-size: 6.5px; }
}
@media (max-width: 330px) {
    .gh-manage-btn { padding: 5px 8px; font-size: 9px; border-radius: 5px; margin: 3px 0 2px; }
    .gh-manage-btn i { font-size: 9px; }
    .gh-manage-badge { font-size: 7px; padding: 1px 4px; }
    .gh-manage-badge i { font-size: 6px; }
}

/* ── GH NAV BAR — scrolls horizontally, 4 items visible per view ── */
.gh-nav-bar {
    display: flex;
    align-items: center;
    width: 100%;
    background: linear-gradient(-45deg,#010218,#070924,#070924);
    border-top: 1px solid rgba(255,255,255,0.04);
    border-bottom: 1px solid rgba(255,255,255,0.07);
    margin-top: 14px;
    overflow-x: auto;
    overflow-y: hidden;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
}
.gh-nav-bar::-webkit-scrollbar { display: none; }

.gh-nav-item {
    flex: 0 0 25%;
    flex-shrink: 0;
    text-align: center;
    padding: 10px 6px;
    font-size: 11px;
    font-weight: 600;
    color: #1a56db;
    text-decoration: none;
    letter-spacing: 0.3px;
    transition: color 0.18s, background 0.18s;
    -webkit-tap-highlight-color: transparent;
    cursor: pointer;
    border: none;
    background: transparent;
    font-family: 'Poppins', sans-serif;
    white-space: nowrap;
}
.gh-nav-item:hover  { color: #3b82f6; background: rgba(26,86,219,0.07); }
.gh-nav-item:active { color: #3b82f6; background: rgba(26,86,219,0.12); }

.gh-nav-sep {
    width: 1px;
    height: 18px;
    background: rgba(255,255,255,0.12);
    flex-shrink: 0;
}

.gh-nav-you {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
}
.gh-nav-you-pic {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #1a56db;
    flex-shrink: 0;
}

@media (max-width: 790px) {
    .gh-nav-item { font-size: 10.5px; padding: 9px 5px; }
    .gh-nav-sep  { height: 16px; }
    .gh-nav-you-pic { width: 20px; height: 20px; }
}
@media (max-width: 490px) {
    .gh-nav-item { font-size: 10px; padding: 9px 4px; letter-spacing: 0.2px; }
    .gh-nav-sep  { height: 14px; }
    .gh-nav-you-pic { width: 18px; height: 18px; }
}
@media (max-width: 380px) {
    .gh-nav-item { font-size: 9.5px; padding: 8px 3px; }
    .gh-nav-you-pic { width: 16px; height: 16px; }
}
@media (max-width: 330px) {
    .gh-nav-item { font-size: 9px; padding: 7px 2px; }
}

/* ── TOP CONTRIBUTOR ROW ── */
.te-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 12px;
    background: rgba(24,95,165,0.08);
    border: 1px solid rgba(24,95,165,0.3);
    border-radius: 14px;
    text-decoration: none;
    transition: background 0.2s;
    margin: 10px 0 16px;
    cursor: pointer;
}
.te-row:hover { background: rgba(24,95,165,0.16); }
.te-pic {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #0b5aa9;
    flex-shrink: 0;
}
.te-text {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.te-label {
    font-size: 9.5px;
    font-weight: 700;
    color: rgba(55,138,221,0.9);
    text-transform: uppercase;
    letter-spacing: 0.6px;
    display: flex;
    align-items: center;
    gap: 4px;
    font-family: 'Poppins', sans-serif;
}
.te-label i { font-size: 11px; color: #378add; }
.te-username {
    font-size: 12.5px;
    font-weight: 700;
    color: #fff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    font-family: 'Poppins', sans-serif;
}
.te-posts {
    font-size: 10px;
    color: rgba(255,255,255,0.38);
    font-family: 'Poppins', sans-serif;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.te-arrow {
    color: rgba(24,95,165,0.6);
    font-size: 18px;
    flex-shrink: 0;
}

@media (max-width: 790px) {
    .te-row { padding: 9px 11px; gap: 9px; border-radius: 13px; margin: 8px 0 13px; }
    .te-pic { width: 34px; height: 34px; }
    .te-label { font-size: 9px; }
    .te-username { font-size: 12px; }
    .te-posts { font-size: 9.5px; }
    .te-arrow { font-size: 16px; }
}
@media (max-width: 490px) {
    .te-row { padding: 8px 10px; gap: 8px; border-radius: 11px; margin: 7px 0 12px; }
    .te-pic { width: 30px; height: 30px; }
    .te-label { font-size: 8.5px; }
    .te-username { font-size: 11px; }
    .te-posts { font-size: 9px; }
    .te-arrow { font-size: 15px; }
}
@media (max-width: 380px) {
    .te-row { padding: 7px 9px; gap: 7px; border-radius: 10px; margin: 6px 0 11px; }
    .te-pic { width: 26px; height: 26px; }
    .te-label { font-size: 8px; }
    .te-username { font-size: 10.5px; }
    .te-posts { font-size: 8.5px; }
    .te-arrow { font-size: 14px; }
}
@media (max-width: 330px) {
    .te-row { padding: 6px 8px; gap: 6px; border-radius: 9px; }
    .te-pic { width: 22px; height: 22px; }
    .te-label { font-size: 7.5px; }
    .te-username { font-size: 10px; }
    .te-posts { font-size: 8px; }
    .te-arrow { font-size: 13px; }
}

.te-oat-tag {
    font-size: 8px;
    font-weight: 800;
    letter-spacing: 0.5px;
    color: #8fb4ff;
    background: rgba(26,86,219,0.14);
    border: 1px solid rgba(26,86,219,0.35);
    padding: 1px 5px;
    border-radius: 10px;
    margin-left: 4px;
    vertical-align: middle;
}


.ym-badge-explainer {
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.07);
    border-radius: 12px;
    padding: 10px 12px;
    margin-bottom: 10px;
}
.ym-be-head {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 12.5px;
    font-weight: 700;
    color: #fff;
    font-family: 'Poppins', sans-serif;
    margin-bottom: 5px;
}
.ym-be-head i { font-size: 15px; flex-shrink: 0; }
.ym-be-where {
    margin-left: auto;
    font-size: 9px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    color: rgba(77,143,255,0.7);
    background: rgba(26,86,219,0.12);
    border: 1px solid rgba(26,86,219,0.3);
    padding: 2px 7px;
    border-radius: 20px;
    flex-shrink: 0;
    white-space: nowrap;
}
.ym-be-desc {
    font-size: 11.5px;
    color: rgba(255,255,255,0.55);
    line-height: 1.55;
    margin: 0;
    font-family: 'Poppins', sans-serif;
}
.ym-be-desc strong { color: rgba(255,255,255,0.85); }
.ym-be-divider {
    border: none;
    border-top: 1px solid rgba(255,255,255,0.08);
    margin: 14px 0 12px;
}

@media (max-width: 490px) {
    .ym-badge-explainer { padding: 9px 10px; margin-bottom: 8px; border-radius: 11px; }
    .ym-be-head { font-size: 11.5px; gap: 6px; }
    .ym-be-where { font-size: 8.5px; padding: 2px 6px; }
    .ym-be-desc { font-size: 11px; }
}
@media (max-width: 380px) {
    .ym-badge-explainer { padding: 8px 9px; margin-bottom: 7px; }
    .ym-be-head { font-size: 11px; }
    .ym-be-desc { font-size: 10.5px; line-height: 1.5; }
    .ym-be-where { font-size: 8px; padding: 1px 5px; }
}

/* ════════════════════════════════════════════════════
   YOU MODAL
════════════════════════════════════════════════════ */
#youModal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 9999999;
    background: #030420;
    flex-direction: column;
    overflow: hidden;
}
#youModal.ym-open {
    display: flex;
    animation: ymSlideIn 0.28s cubic-bezier(0.22,1,0.36,1);
}
@keyframes ymSlideIn {
    from { opacity:0; transform:translateY(18px); }
    to   { opacity:1; transform:translateY(0); }
}
@keyframes ymSlideOut {
    to { opacity:0; transform:translateY(14px); }
}

.ym-topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 13px 18px;
    background: rgba(3,4,32,0.97);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    border-bottom: 1px solid rgba(255,255,255,0.07);
    flex-shrink: 0;
    position: sticky;
    top: 0;
    z-index: 10;
}
.ym-back {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px; height: 36px;
    border-radius: 50%;
    border: 1px solid rgba(255,255,255,0.14);
    background: rgba(255,255,255,0.06);
    color: #fff;
    font-size: 20px;
    cursor: pointer;
    flex-shrink: 0;
    -webkit-tap-highlight-color: transparent;
    transition: 0.2s;
}
.ym-back:hover  { background: rgba(255,255,255,0.13); }
.ym-back:active { transform: scale(0.9); }

.ym-topbar-title {
    font-size: 15px;
    font-weight: 700;
    color: #fff;
    font-family: 'Poppins', sans-serif;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.ym-body {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
    padding: 0 0 50px 0;
    scrollbar-width: thin;
    scrollbar-color: rgba(255,45,45,0.15) transparent;
    -webkit-overflow-scrolling: touch;
}
.ym-body::-webkit-scrollbar { width: 4px; }
.ym-body::-webkit-scrollbar-thumb { background: rgba(255,45,45,0.2); border-radius: 99px; }

.ym-pic-wrap {
    display: flex;
    justify-content: center;
    padding: 32px 20px 20px;
}
.ym-big-pic {
    width: 110px;
    height: 110px;
    border-radius: 50%;
    object-fit: cover;
    border: 4px solid #FF2D2D;
    box-shadow: 0 0 30px rgba(255,45,45,0.25);
    display: block;
}

.ym-pill-bar {
    display: flex;
    gap: 8px;
    justify-content: center;
    padding: 0 20px 22px;
    flex-wrap: wrap;
}
.ym-pill {
    padding: 7px 18px;
    border-radius: 40px;
    border: 1px solid rgba(255,255,255,0.15);
    background: rgba(255,255,255,0.06);
    color: rgba(255,255,255,0.65);
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    font-family: 'Poppins', sans-serif;
    transition: 0.2s;
    -webkit-tap-highlight-color: transparent;
}
.ym-pill.active {
    background: rgba(255,45,45,0.2);
    border-color: rgba(255,45,45,0.5);
    color: #ff7a7a;
}

.ym-tab { display: none; padding: 0 18px; }
.ym-tab.active { display: block; }

.ym-accordion {
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 14px;
    margin-bottom: 18px;
    overflow: hidden;
    cursor: pointer;
    user-select: none;
}
.ym-acc-head {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 13px 14px;
    color: #4dabff;
    font-size: 13px;
    font-weight: 600;
    font-family: 'Poppins', sans-serif;
}
.ym-acc-head i { font-size: 16px; }
.ym-acc-head > span { flex: 1; }
.ym-acc-chevron {
    font-size: 18px;
    transition: transform 0.25s;
    color: rgba(255,255,255,0.4);
}
.ym-accordion.open .ym-acc-chevron { transform: rotate(180deg); }
.ym-acc-body {
    display: none;
    padding: 0 14px 14px;
    border-top: 1px solid rgba(255,255,255,0.06);
}
.ym-accordion.open .ym-acc-body { display: block; }
.ym-acc-body p {
    font-size: 12px;
    color: rgba(255,255,255,0.6);
    line-height: 1.6;
    margin: 10px 0 8px;
    font-family: 'Poppins', sans-serif;
}
.ym-acc-body ul {
    padding-left: 4px;
    list-style: none;
    margin: 0;
}
.ym-acc-body ul li {
    font-size: 12px;
    color: rgba(255,255,255,0.6);
    line-height: 1.6;
    margin-bottom: 6px;
    display: flex;
    align-items: flex-start;
    gap: 6px;
    font-family: 'Poppins', sans-serif;
}
.ym-acc-body ul li i { font-size: 13px; color: #4dabff; flex-shrink: 0; margin-top: 2px; }
.ym-acc-body ul li strong { color: #fff; }

.ym-stats-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
    margin-bottom: 16px;
}
.ym-stat-card {
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 14px;
    padding: 14px 12px;
    text-align: center;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.ym-stat-num {
    font-size: 22px;
    font-weight: 700;
    color: #fff;
    font-family: 'Poppins', sans-serif;
    line-height: 1;
}
.ym-stat-lbl {
    font-size: 11px;
    color: rgba(255,255,255,0.38);
    font-family: 'Poppins', sans-serif;
}

.ym-join-row {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 12px;
    color: rgba(255,255,255,0.45);
    font-family: 'Poppins', sans-serif;
    padding: 12px 0 6px;
}
.ym-join-row i { font-size: 15px; color: rgba(255,45,45,0.55); }
.ym-join-row strong { color: rgba(255,255,255,0.75); }

.ym-sort-row {
    display: flex;
    gap: 7px;
    margin-bottom: 14px;
    flex-wrap: wrap;
}
.ym-sort-pill {
    padding: 6px 14px;
    border-radius: 30px;
    border: 1px solid rgba(255,255,255,0.12);
    background: rgba(255,255,255,0.05);
    color: rgba(255,255,255,0.55);
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    font-family: 'Poppins', sans-serif;
    transition: 0.18s;
    -webkit-tap-highlight-color: transparent;
}
.ym-sort-pill.active {
    background: rgba(255,45,45,0.18);
    border-color: rgba(255,45,45,0.4);
    color: #ff7a7a;
}

.ym-no-posts {
    text-align: center;
    padding: 40px 20px;
    color: rgba(255,255,255,0.28);
    font-family: 'Poppins', sans-serif;
}
.ym-no-posts i { font-size: 38px; display: block; margin-bottom: 10px; }
.ym-no-posts p { font-size: 13px; }

.ym-post-row {
    display: flex;
    align-items: center;
    gap: 11px;
    padding: 10px 0;
    border-bottom: 1px solid rgba(255,255,255,0.05);
}
.ym-post-row:last-child { border-bottom: none; }

.ym-post-thumb {
    width: 54px;
    height: 54px;
    border-radius: 10px;
    overflow: hidden;
    flex-shrink: 0;
    position: relative;
    background: rgba(255,255,255,0.06);
    display: flex;
    align-items: center;
    justify-content: center;
}
.ym-post-thumb img,
.ym-post-thumb video {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.ym-play-overlay {
    position: absolute;
    font-size: 18px;
    color: rgba(255,255,255,0.85);
    pointer-events: none;
}
.ym-text-thumb {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
    color: rgba(255,255,255,0.3);
    font-size: 20px;
}

.ym-post-info {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.ym-post-cap {
    font-size: 12px;
    font-weight: 600;
    color: rgba(255,255,255,0.82);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    font-family: 'Poppins', sans-serif;
}
.ym-post-meta {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 10.5px;
    color: rgba(255,255,255,0.38);
    font-family: 'Poppins', sans-serif;
    flex-wrap: wrap;
}
.ym-post-meta i { font-size: 11px; }
.ym-post-meta span { display: inline-flex; align-items: center; gap: 3px; }
.ym-post-date { margin-left: auto; color: rgba(255,255,255,0.28); }

.ym-del-btn {
    width: 34px;
    height: 34px;
    border-radius: 10px;
    border: 1px solid rgba(255,45,45,0.3);
    background: rgba(255,45,45,0.08);
    color: #ff7a7a;
    font-size: 15px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    transition: 0.2s;
    -webkit-tap-highlight-color: transparent;
}
.ym-del-btn:hover { background: rgba(255,45,45,0.28); color: #fff; }


/* ════════════════════════════════════════════════════
   RESPONSIVE — TIER 1 (≤790px)
   Tablet / small laptop — modest tightening
════════════════════════════════════════════════════ */
@media (max-width: 790px) {
    .ym-topbar { padding: 12px 16px; }
    .ym-back { width: 34px; height: 34px; font-size: 19px; }
    .ym-topbar-title { font-size: 14.5px; }

    .ym-body { padding-bottom: 44px; }

    .ym-pic-wrap { padding: 28px 18px 18px; }
    .ym-big-pic { width: 100px; height: 100px; border-width: 3.5px; }

    .ym-pill-bar { gap: 7px; padding: 0 18px 20px; }
    .ym-pill { padding: 6px 16px; font-size: 11.5px; }

    .ym-tab { padding: 0 16px; }

    .ym-accordion { border-radius: 13px; margin-bottom: 16px; }
    .ym-acc-head { padding: 12px 13px; font-size: 12.5px; gap: 7px; }
    .ym-acc-head i { font-size: 15px; }
    .ym-acc-chevron { font-size: 17px; }
    .ym-acc-body { padding: 0 13px 13px; }
    .ym-acc-body p { font-size: 11.5px; margin: 9px 0 7px; }
    .ym-acc-body ul li { font-size: 11.5px; margin-bottom: 5px; }
    .ym-acc-body ul li i { font-size: 12.5px; }

    .ym-stats-grid { gap: 9px; margin-bottom: 14px; }
    .ym-stat-card { border-radius: 13px; padding: 12px 11px; }
    .ym-stat-num { font-size: 20px; }
    .ym-stat-lbl { font-size: 10.5px; }

    .ym-join-row { font-size: 11.5px; gap: 6px; padding: 11px 0 6px; }
    .ym-join-row i { font-size: 14px; }

    .ym-sort-row { gap: 6px; margin-bottom: 12px; }
    .ym-sort-pill { padding: 6px 13px; font-size: 10.5px; }

    .ym-no-posts { padding: 34px 18px; }
    .ym-no-posts i { font-size: 34px; margin-bottom: 9px; }
    .ym-no-posts p { font-size: 12.5px; }

    .ym-post-row { gap: 10px; padding: 9px 0; }
    .ym-post-thumb { width: 50px; height: 50px; border-radius: 9px; }
    .ym-play-overlay { font-size: 17px; }
    .ym-text-thumb { font-size: 18px; }

    .ym-post-info { gap: 3px; }
    .ym-post-cap { font-size: 11.5px; }
    .ym-post-meta { font-size: 10px; gap: 7px; }
    .ym-post-meta i { font-size: 10.5px; }

    .ym-del-btn { width: 32px; height: 32px; border-radius: 9px; font-size: 14px; }
}


/* ════════════════════════════════════════════════════
   RESPONSIVE — TIER 2 (≤490px)
   Standard mobile — noticeably tighter
════════════════════════════════════════════════════ */
@media (max-width: 490px) {
    .ym-topbar { padding: 10px 14px; gap: 10px; }
    .ym-back { width: 32px; height: 32px; font-size: 18px; }
    .ym-topbar-title { font-size: 13.5px; }

    .ym-body { padding-bottom: 38px; }

    .ym-pic-wrap { padding: 22px 16px 15px; }
    .ym-big-pic { width: 84px; height: 84px; border-width: 3px; box-shadow: 0 0 22px rgba(255,45,45,0.22); }

    .ym-pill-bar { gap: 6px; padding: 0 16px 16px; }
    .ym-pill { padding: 5px 13px; font-size: 10.5px; }

    .ym-tab { padding: 0 14px; }

    .ym-accordion { border-radius: 12px; margin-bottom: 13px; }
    .ym-acc-head { padding: 10px 12px; font-size: 11.5px; gap: 6px; }
    .ym-acc-head i { font-size: 14px; }
    .ym-acc-chevron { font-size: 16px; }
    .ym-acc-body { padding: 0 12px 12px; }
    .ym-acc-body p { font-size: 10.5px; margin: 8px 0 6px; line-height: 1.5; }
    .ym-acc-body ul li { font-size: 10.5px; margin-bottom: 5px; line-height: 1.5; }
    .ym-acc-body ul li i { font-size: 11.5px; }

    .ym-stats-grid { gap: 7px; margin-bottom: 12px; }
    .ym-stat-card { border-radius: 12px; padding: 10px 9px; gap: 3px; }
    .ym-stat-num { font-size: 17px; }
    .ym-stat-lbl { font-size: 9.5px; }

    .ym-join-row { font-size: 10.5px; gap: 5px; padding: 9px 0 5px; }
    .ym-join-row i { font-size: 13px; }

    .ym-sort-row { gap: 5px; margin-bottom: 10px; }
    .ym-sort-pill { padding: 5px 11px; font-size: 9.5px; }

    .ym-no-posts { padding: 28px 16px; }
    .ym-no-posts i { font-size: 30px; margin-bottom: 8px; }
    .ym-no-posts p { font-size: 11.5px; }

    .ym-post-row { gap: 9px; padding: 8px 0; }
    .ym-post-thumb { width: 44px; height: 44px; border-radius: 8px; }
    .ym-play-overlay { font-size: 15px; }
    .ym-text-thumb { font-size: 16px; }

    .ym-post-info { gap: 3px; }
    .ym-post-cap { font-size: 10.5px; }
    .ym-post-meta { font-size: 9px; gap: 6px; }
    .ym-post-meta i { font-size: 9.5px; }

    .ym-del-btn { width: 28px; height: 28px; border-radius: 8px; font-size: 12.5px; }
}


/* ════════════════════════════════════════════════════
   RESPONSIVE — TIER 3 (≤390px)
   Compact phones — tiny but readable
════════════════════════════════════════════════════ */
@media (max-width: 390px) {
    .ym-topbar { padding: 9px 12px; gap: 8px; }
    .ym-back { width: 29px; height: 29px; font-size: 16px; }
    .ym-topbar-title { font-size: 12.5px; }

    .ym-body { padding-bottom: 32px; }

    .ym-pic-wrap { padding: 18px 14px 12px; }
    .ym-big-pic { width: 72px; height: 72px; border-width: 2.5px; box-shadow: 0 0 16px rgba(255,45,45,0.2); }

    .ym-pill-bar { gap: 5px; padding: 0 14px 13px; }
    .ym-pill { padding: 5px 11px; font-size: 9.5px; }

    .ym-tab { padding: 0 12px; }

    .ym-accordion { border-radius: 11px; margin-bottom: 11px; }
    .ym-acc-head { padding: 9px 10px; font-size: 10.5px; gap: 5px; }
    .ym-acc-head i { font-size: 13px; }
    .ym-acc-chevron { font-size: 14.5px; }
    .ym-acc-body { padding: 0 10px 10px; }
    .ym-acc-body p { font-size: 9.5px; margin: 7px 0 5px; line-height: 1.45; }
    .ym-acc-body ul li { font-size: 9.5px; margin-bottom: 4px; gap: 5px; line-height: 1.45; }
    .ym-acc-body ul li i { font-size: 10.5px; }

    .ym-stats-grid { gap: 6px; margin-bottom: 10px; }
    .ym-stat-card { border-radius: 11px; padding: 9px 7px; gap: 2px; }
    .ym-stat-num { font-size: 15px; }
    .ym-stat-lbl { font-size: 8.5px; }

    .ym-join-row { font-size: 9.5px; gap: 5px; padding: 8px 0 4px; }
    .ym-join-row i { font-size: 12px; }

    .ym-sort-row { gap: 4px; margin-bottom: 9px; }
    .ym-sort-pill { padding: 4px 10px; font-size: 8.5px; }

    .ym-no-posts { padding: 22px 14px; }
    .ym-no-posts i { font-size: 26px; margin-bottom: 7px; }
    .ym-no-posts p { font-size: 10.5px; }

    .ym-post-row { gap: 8px; padding: 7px 0; }
    .ym-post-thumb { width: 40px; height: 40px; border-radius: 7px; }
    .ym-play-overlay { font-size: 13px; }
    .ym-text-thumb { font-size: 14px; }

    .ym-post-info { gap: 2px; }
    .ym-post-cap { font-size: 9.5px; }
    .ym-post-meta { font-size: 8.5px; gap: 5px; }
    .ym-post-meta i { font-size: 9px; }

    .ym-del-btn { width: 25px; height: 25px; border-radius: 7px; font-size: 11px; }
}


/* ════════════════════════════════════════════════════
   RESPONSIVE — TIER 4 (≤330px)
   Smallest phones — ultra compact, floor sizes
════════════════════════════════════════════════════ */
@media (max-width: 330px) {
    .ym-topbar { padding: 8px 10px; gap: 7px; }
    .ym-back { width: 26px; height: 26px; font-size: 15px; }
    .ym-topbar-title { font-size: 11.5px; }

    .ym-body { padding-bottom: 26px; }

    .ym-pic-wrap { padding: 15px 10px 10px; }
    .ym-big-pic { width: 60px; height: 60px; border-width: 2px; box-shadow: 0 0 12px rgba(255,45,45,0.18); }

    .ym-pill-bar { gap: 4px; padding: 0 10px 11px; }
    .ym-pill { padding: 4px 9px; font-size: 8.5px; }

    .ym-tab { padding: 0 10px; }

    .ym-accordion { border-radius: 10px; margin-bottom: 9px; }
    .ym-acc-head { padding: 8px 9px; font-size: 9.5px; gap: 4px; }
    .ym-acc-head i { font-size: 12px; }
    .ym-acc-chevron { font-size: 13px; }
    .ym-acc-body { padding: 0 9px 9px; }
    .ym-acc-body p { font-size: 8.5px; margin: 6px 0 4px; line-height: 1.4; }
    .ym-acc-body ul li { font-size: 8.5px; margin-bottom: 4px; gap: 4px; line-height: 1.4; }
    .ym-acc-body ul li i { font-size: 9.5px; }

    .ym-stats-grid { gap: 5px; margin-bottom: 9px; }
    .ym-stat-card { border-radius: 10px; padding: 7px 6px; gap: 2px; }
    .ym-stat-num { font-size: 13px; }
    .ym-stat-lbl { font-size: 7.5px; }

    .ym-join-row { font-size: 8.5px; gap: 4px; padding: 7px 0 4px; }
    .ym-join-row i { font-size: 11px; }

    .ym-sort-row { gap: 4px; margin-bottom: 8px; }
    .ym-sort-pill { padding: 4px 8px; font-size: 7.5px; }

    .ym-no-posts { padding: 18px 10px; }
    .ym-no-posts i { font-size: 22px; margin-bottom: 6px; }
    .ym-no-posts p { font-size: 9.5px; }

    .ym-post-row { gap: 7px; padding: 6px 0; }
    .ym-post-thumb { width: 34px; height: 34px; border-radius: 6px; }
    .ym-play-overlay { font-size: 11px; }
    .ym-text-thumb { font-size: 12px; }

    .ym-post-info { gap: 2px; }
    .ym-post-cap { font-size: 8.5px; }
    .ym-post-meta { font-size: 7.5px; gap: 4px; }
    .ym-post-meta i { font-size: 8px; }

    .ym-del-btn { width: 22px; height: 22px; border-radius: 6px; font-size: 10px; }
}
</style>

<script>
function openYouModal() {
    var m = document.getElementById('youModal');
    if (!m) return;
    m.classList.add('ym-open');
    document.body.style.overflow = 'hidden';
}

function closeYouModal() {
    var m = document.getElementById('youModal');
    if (!m) return;
    m.style.animation = 'ymSlideOut 0.22s ease forwards';
    setTimeout(function () {
        m.classList.remove('ym-open');
        m.style.animation = '';
        document.body.style.overflow = '';
    }, 210);
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeYouModal();
});

function ymTab(btn, tabId) {
    document.querySelectorAll('.ym-pill').forEach(function (p) { p.classList.remove('active'); });
    btn.classList.add('active');
    document.querySelectorAll('.ym-tab').forEach(function (t) { t.classList.remove('active'); });
    var tab = document.getElementById(tabId);
    if (tab) tab.classList.add('active');
}

function ymToggleAccordion(el) {
    el.classList.toggle('open');
}

function ymSort(key, btn) {
    document.querySelectorAll('.ym-sort-pill').forEach(function (p) { p.classList.remove('active'); });
    btn.classList.add('active');

    var list = document.getElementById('ym-post-list');
    if (!list) return;
    var rows = Array.prototype.slice.call(list.querySelectorAll('.ym-post-row'));

    rows.sort(function (a, b) {
        if (key === 'newest')  return parseInt(b.dataset.newest)  - parseInt(a.dataset.newest);
        if (key === 'oldest')  return parseInt(a.dataset.newest)  - parseInt(b.dataset.newest);
        if (key === 'popular') return parseInt(b.dataset.popular) - parseInt(a.dataset.popular);
        return 0;
    });

    var frag = document.createDocumentFragment();
    rows.forEach(function (r) { frag.appendChild(r); });
    list.appendChild(frag);
}
</script>

<!-- ─── DISCUSSIONS LINK ───────────────────────────────────── -->
<?php
$disc_active = $conn->query("
    SELECT COUNT(*) as c FROM group_members gm
    JOIN users u ON u.id = gm.user_id
    WHERE gm.group_id = $group_id
    AND u.last_seen >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
")->fetch_assoc()['c'];
?>

<style>
.disc-link-row {
    display: flex;
    justify-content: flex-end;
    width: 100%;
    margin-bottom: 14px;
     padding-right: 10px; /* ← increase this to push more left */
}
.disc-link {
    color: #FF2D2D;
    font-family: 'Poppins', sans-serif;
    font-size: 14px;
    font-weight: 700;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 16px;
    border-radius: 20px;
    border: 1px solid rgba(255,45,45,0.35);
    background: rgba(255,45,45,0.08);
    transition: all 0.2s;
    white-space: nowrap;
}
.disc-link:hover { background: rgba(255,45,45,0.18); }
.disc-link .disc-active-badge {
    background: #2ecc71;
    color: #000;
    font-size: 10px;
    font-weight: 800;
    padding: 2px 7px;
    border-radius: 20px;
}
.disc-link .disc-inactive-text {
    color: rgba(255,255,255,0.3);
    font-size: 11px;
    font-weight: 400;
}

@media (max-width: 768px) {
    .disc-link-row { margin-bottom: 12px;}
    .disc-link { font-size: 13px; padding: 6px 14px; gap: 5px; border-radius: 18px; }
    .disc-link .disc-active-badge { font-size: 9px; padding: 2px 6px; }
    .disc-link .disc-inactive-text { font-size: 10.5px; }
}

@media (max-width: 480px) {
    .disc-link-row { margin-bottom: 10px;}
    .disc-link { font-size: 12px; padding: 5px 12px; gap: 5px; border-radius: 16px; }
    .disc-link .disc-active-badge { font-size: 9px; padding: 1px 6px; }
    .disc-link .disc-inactive-text { font-size: 10px; }
}

@media (max-width: 360px) {
    .disc-link-row { margin-bottom: 8px;}
    .disc-link { font-size: 11px; padding: 4px 8px; gap: 4px; border-radius: 11px; }
    .disc-link .disc-active-badge { font-size: 8px; padding: 1px 4px; }
    .disc-link .disc-inactive-text { font-size: 9px; }
}

.disc-link-locked {
    opacity: 0.5;
    cursor: not-allowed;
    filter: grayscale(0.4);
}

#discLockToast {
    display: none;
    position: fixed;
    bottom: 90px;
    left: 50%;
    transform: translateX(-50%);
    background: rgba(20, 18, 18, 0.95);
    border: 1px solid rgba(255,45,45,0.35);
    color: #fff;
    font-family: 'Poppins', sans-serif;
    font-size: 13px;
    font-weight: 500;
    padding: 12px 20px;
    border-radius: 30px;
    z-index: 999999;
    white-space: nowrap;
    gap: 8px;
    align-items: center;
    box-shadow: 0 8px 30px rgba(0,0,0,0.5);
}

#discLockToast.show {
    display: flex;
    animation: toastIn 0.25s ease;
}

@keyframes toastIn {
    from { opacity: 0; transform: translateX(-50%) translateY(10px); }
    to   { opacity: 1; transform: translateX(-50%) translateY(0); }
}

#discLockToast i {
    color: #FF2D2D;
    font-size: 15px;
}

/* ═══ INVITE MODAL ═══ */
.invite-user-row {
    display: flex;
    align-items: center;
    gap: 11px;
    padding: 10px 16px;
    border-bottom: 1px solid rgba(255,255,255,0.04);
    transition: background 0.15s;
}
.invite-user-row:hover { background: rgba(255,255,255,0.04); }
.invite-user-row img {
    width: 40px; height: 40px;
    border-radius: 50%; object-fit: cover;
    border: 2px solid #FF2D2D; flex-shrink: 0;
}
.invite-user-row .inv-name {
    flex: 1; color: #fff;
    font-size: 13px; font-weight: 600;
    font-family: 'Poppins', sans-serif;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.invite-user-row .inv-nick {
    font-size: 11px; color: rgba(255,255,255,0.4);
    font-weight: 400; font-family: 'Poppins', sans-serif;
}
.inv-btn {
    padding: 6px 14px;
    border-radius: 20px;
    border: 1px solid rgba(77,171,255,0.5);
    background: rgba(77,171,255,0.1);
    color: #4dabff;
    font-size: 12px; font-weight: 700;
    font-family: 'Poppins', sans-serif;
    cursor: pointer; transition: all 0.2s;
    white-space: nowrap; flex-shrink: 0;
}
.inv-btn:hover { background: rgba(77,171,255,0.25); }
.inv-btn.sent {
    border-color: rgba(76,255,145,0.4);
    background: rgba(76,255,145,0.08);
    color: #4cff91; cursor: default;
}
.inv-btn:disabled { opacity: 0.6; cursor: not-allowed; }

@media (max-width: 480px) {
    #inviteModal > div {
        border-radius: 16px !important;
        max-height: 75vh !important;
    }
}

/* ═══════════════════════════════
   INVITE LINK STYLE
═══════════════════════════════ */

.invite-modal-btn{
    color: #2088e2;
    font-family:'Poppins',sans-serif;
    font-size:14px;
    font-weight:700;

    display:inline-flex;
    align-items:center;
    gap:5px;

    padding:0;
    margin:0;

    border:none;
    background:none;

    cursor:pointer;
    transition:color .2s ease, opacity .2s ease;

    white-space:nowrap;
}

.invite-modal-btn:hover{
    color:#76c0ff;
    opacity:.9;
    transform:none;
}

.invite-modal-btn i{
    font-size:15px;
}

/* ═══════════════════════════════
   TABLET ≤ 760px
═══════════════════════════════ */

@media (max-width:760px){

    .invite-modal-btn{
        font-size:12px;
        gap:4px;
    }

    .invite-modal-btn i{
        font-size:13px;
    }
}

/* ═══════════════════════════════
   MOBILE ≤ 480px
═══════════════════════════════ */

@media (max-width:480px){

    .invite-modal-btn{
        font-size:10px;
        gap:3px;
    }

    .invite-modal-btn i{
        font-size:11px;
    }
}

/* ═══════════════════════════════
   SMALL PHONES ≤ 360px
═══════════════════════════════ */

@media (max-width:360px){

    .invite-modal-btn{
        font-size:9px;
    }

    .invite-modal-btn i{
        font-size:10px;
    }
}

/* ═══════════════════════════════
   VERY SMALL ≤ 320px
═══════════════════════════════ */

@media (max-width:320px){

    .invite-modal-btn{
        font-size:8px;
    }

    .invite-modal-btn i{
        font-size:9px;
    }
}
</style>

<?php
// Reuse the same paused-lock flag used for Events/Files/Highlights.
// Owners/admins are exempt so they can still manage things while paused.
$gf_paused_locked = $group_is_paused && !($is_owner || $is_admin);
?>

<div class="disc-link-row" style="gap:10px; justify-content:flex-end; align-items:center;">

    <!-- INVITE BUTTON (left of discussions) -->
    <?php if ($is_member && !$gf_paused_locked): ?>
        <button class="invite-modal-btn" onclick="openInviteModal()">
            <i class="ri-user-add-line"></i>
            <span>Invite</span>
        </button>
    <?php elseif ($is_member): ?>
        <button type="button" class="invite-modal-btn" style="opacity:0.5;cursor:not-allowed;"
                onclick="showDiscLockToast('This pod is paused — invites are disabled')">
            <i class="ri-lock-line"></i>
            <span>Invite</span>
        </button>
    <?php endif; ?>

    <!-- DISCUSSIONS BUTTON -->
    <?php if ($is_member && !$gf_paused_locked): ?>
        <a href="discussions.php?pod_id=<?= $group_id ?>" class="disc-link">
            <i class="ri-discuss-line"></i> Discussions
            <?php if ($disc_active > 0): ?>
                <span class="disc-active-badge"><?= $disc_active ?> active</span>
            <?php else: ?>
                <span class="disc-inactive-text">inactive</span>
            <?php endif; ?>
        </a>
    <?php elseif ($is_member): ?>
        <a href="#" class="disc-link disc-link-locked"
           onclick="showDiscLockToast('This pod is paused — check back later'); return false;">
            <i class="ri-lock-line"></i> Discussions
            <span class="disc-inactive-text">paused</span>
        </a>
    <?php else: ?>
        <a href="#" class="disc-link disc-link-locked"
           onclick="showDiscLockToast('Join this pod to access Discussions'); return false;">
            <i class="ri-lock-line"></i> Discussions
            <span class="disc-inactive-text">members only</span>
        </a>
    <?php endif; ?>
</div>

<!-- TOAST -->
<div id="discLockToast">
    <i class="ri-lock-line"></i> Join this pod to access Discussions
</div>

<!-- ═══ INVITE MODAL ═══ -->
<div id="inviteModal" style="
    display: none;
    position: fixed; inset: 0;
    z-index: 999999999;
    background: rgba(0,0,0,0.88);
    backdrop-filter: blur(10px);
    align-items: center;
    justify-content: center;
    padding: 20px;
" onclick="if(event.target===this)closeInviteModal()">

    <div style="
        background: rgba(12,12,28,0.98);
        border: 1px solid rgba(255,255,255,0.09);
        border-radius: 20px;
        width: 100%;
        max-width: 420px;
        max-height: 80vh;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        box-shadow: 0 24px 64px rgba(0,0,0,0.8);
        animation: modalIn 0.22s ease;
    ">
        <!-- Header -->
        <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 18px;border-bottom:1px solid rgba(255,255,255,0.07);flex-shrink:0;">
            <div style="color:#fff;font-weight:700;font-size:14px;font-family:'Poppins',sans-serif;display:flex;align-items:center;gap:7px;">
                <i class="ri-user-add-line" style="color:#4dabff;"></i>
                Invite to <span style="color:#4dabff;">&nbsp;<?= htmlspecialchars($group['title']) ?></span>
            </div>
            <button onclick="closeInviteModal()" style="background:none;border:none;color:rgba(255,255,255,0.5);font-size:20px;cursor:pointer;padding:2px 6px;transition:0.2s;" onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,0.5)'">✕</button>
        </div>

        <!-- Search -->
        <div style="padding:12px 16px;border-bottom:1px solid rgba(255,255,255,0.06);flex-shrink:0;">
            <div style="display:flex;align-items:center;gap:8px;background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.12);border-radius:12px;padding:8px 14px;">
                <i class="ri-search-line" style="color:rgba(255,255,255,0.4);font-size:15px;flex-shrink:0;"></i>
                <input id="inviteSearchInput" type="text" placeholder="Search users…" oninput="searchInviteUsers(this.value)" style="
                    flex:1; border:none; background:transparent;
                    color:#fff; font-family:'Poppins',sans-serif;
                    font-size:13px; outline:none;
                " />
            </div>
        </div>

        <!-- User list -->
        <div id="inviteUserList" style="overflow-y:auto;flex:1;padding:6px 0;">
            <div style="text-align:center;padding:30px;color:rgba(255,255,255,0.3);font-size:13px;font-family:'Poppins',sans-serif;">
                <i class="ri-search-line" style="font-size:28px;display:block;margin-bottom:8px;opacity:0.4;"></i>
                Search for a user to invite
            </div>
        </div>
    </div>
</div>


<!-- SEARCH TRIGGER -->
<form method="GET" action="group.php" style="display:contents;">
<input type="hidden" name="id" value="<?php echo $group_id; ?>">
<input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">
<div class="search-wrapper" id="gsmTrigger">
    <i class="ri-search-line" onclick="openGroupSearchModal()" style="cursor:pointer;"></i>
    <input type="text" id="gsmTriggerInput" name="search" placeholder="Search members, posts, captions…"
           value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>"
           readonly onclick="openGroupSearchModal()" style="cursor:pointer;">
    <?php if (!empty($_GET['search'])): ?>
        <a href="group.php?id=<?php echo $group_id; ?>&sort=<?php echo htmlspecialchars($sort); ?>" class="gsm-trigger-clear" title="Clear search">
            <i class="ri-close-line"></i>
        </a>
    <?php else: ?>
        <button type="button" onclick="openGroupSearchModal()"><i class="ri-arrow-right-line"></i></button>
    <?php endif; ?>
</div>
</form>

<!-- ══════════════════════════════════════
     GROUP SEARCH MODAL
══════════════════════════════════════ -->
<div id="gsmModal">

    <div class="gsm-topbar">
        <button class="gsm-back-btn" onclick="closeGroupSearchModal()">
            <i class="ri-arrow-left-s-line"></i>
        </button>
        <div id="gsmSearchWrap">
            <span class="gsm-search-icon"><i class="ri-search-line"></i></span>
            <input type="text" id="gsmSearchInput" placeholder="Search members, posts, captions…" autocomplete="off">
            <button id="gsmClearBtn" onclick="gsmClearSearch()"><i class="ri-close-line"></i></button>
        </div>
        <button id="gsmSearchBtn" onclick="gsmFullSearch()" style="display:none">Search</button>
    </div>

    <!-- ══ SCOPE LABEL — confirms search is locked to this Pod ══ -->
    <div class="gsm-context-bar">
        <i class="ri-shield-check-line"></i>
        <span>Searching in <strong><?php echo htmlspecialchars($group['title']); ?></strong></span>
    </div>

    <div id="gsmContent">

        <div id="gsmRecentsWrap">
            <div class="gsm-recents-header">
                <span class="gsm-recents-title">Recent</span>
                <button id="gsmClearAllBtn" onclick="gsmClearAllRecents()" style="display:none">Clear all</button>
            </div>
            <div id="gsmRecentsList"></div>
        </div>

        <div id="gsmEmptyState" class="gsm-empty" style="display:none;">
            <i class="ri-search-2-line"></i>
            <p>Search this Pod</p>
            <small>Find members, posts &amp; captions</small>
        </div>

        <div id="gsmLiveResults" style="display:none;"></div>
    </div>
</div>

<style>
#gsmModal {
    display: none;
    position: fixed; inset: 0;
    z-index: 2147483647;
    background: #040617;
    flex-direction: column;
    overflow: hidden;
    touch-action: none;
    font-family: 'Poppins', sans-serif;
}
#gsmModal.gsm-open { animation: gsmSlideIn 0.22s cubic-bezier(0.4,0,0.2,1) forwards; }
@keyframes gsmSlideIn { from{opacity:0;transform:translateY(12px);} to{opacity:1;transform:translateY(0);} }

.gsm-topbar {
    display: flex; align-items: center; gap: 12px;
    padding: 18px 20px;
    padding-top: calc(env(safe-area-inset-top) + 18px);
    border-bottom: 1px solid rgba(255,255,255,0.07);
    flex-shrink: 0; background: #040617;
}
.gsm-back-btn {
    background: transparent; border: none; color: white;
    font-size: 28px; cursor: pointer; flex-shrink: 0;
    padding: 0 2px; margin-left: -9px; transition: opacity 0.2s;
    -webkit-tap-highlight-color: transparent;
}
.gsm-back-btn:hover { opacity: 0.7; }
.gsm-back-btn:active { opacity: 0.45; }

#gsmSearchWrap {
    flex: 1; display: flex; align-items: center;
    background: rgba(255,255,255,0.07);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 14px; overflow: hidden; height: 48px;
    transition: border-color 0.2s;
}
#gsmSearchWrap.focused { border-color: rgba(255,45,45,0.45); }

.gsm-search-icon { padding: 0 8px 0 16px; color: rgba(255,255,255,0.4); font-size: 16px; flex-shrink: 0; display:flex; align-items:center; }
#gsmSearchInput {
    flex: 1; padding: 12px 10px; background: transparent; border: none; outline: none;
    color: white; font-size: 14px; font-family: 'Poppins', sans-serif;
}
#gsmSearchInput::placeholder { color: rgba(255,255,255,0.35); }
#gsmClearBtn {
    display: none; background: transparent; border: none; color: rgba(255,255,255,0.4);
    padding: 0 14px; font-size: 18px; cursor: pointer; flex-shrink: 0; line-height: 1;
    transition: color 0.2s; -webkit-tap-highlight-color: transparent;
}
#gsmClearBtn:hover { color: rgba(255,255,255,0.75); }

#gsmSearchBtn {
    display: none; flex-shrink: 0; background: transparent; border: none;
    color:  #FF2D2D; font-size: 14px; font-weight: 600; cursor: pointer;
    padding: 4px 2px; white-space: nowrap; -webkit-tap-highlight-color: transparent;
}

/* ══ SCOPE LABEL — "Searching in [Group Name]" ══ */
.gsm-context-bar {
    display: flex;
    align-items: center;
    gap: 7px;
    padding: 11px 20px;
    font-size: 12.5px;
    font-weight: 500;
    color: #1f61f1;
    background:none;
    border-bottom: 1px solid rgba(77,171,255,0.12);
    flex-shrink: 0;
    font-family: 'Poppins', sans-serif;
}
.gsm-context-bar i {
    font-size: 14px;
    color: #1f61f1;
    flex-shrink: 0;
}
.gsm-context-bar strong {
    color: #7ec8ff;
    font-weight: 700;
}

#gsmContent {
    flex: 1; overflow-y: auto; overflow-x: hidden;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin; scrollbar-color: rgba(80,95,140,0.35) transparent;
}
#gsmContent::-webkit-scrollbar { width: 4px; }
#gsmContent::-webkit-scrollbar-thumb { background: rgba(80,95,140,0.35); border-radius: 999px; }

.gsm-empty {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    padding: 70px 20px; gap: 12px; color: rgba(255,255,255,0.3);
}
.gsm-empty i { font-size: 46px; }
.gsm-empty p { font-size: 14px; font-weight: 500; }
.gsm-empty small { font-size: 12px; }

.gsm-sec {
    font-size: 10px; font-weight: 700; letter-spacing: 1.1px;
    color: rgba(255,255,255,0.32); text-transform: uppercase;
    padding: 14px 22px 2px;
}

.gsm-row {
    display: flex; align-items: center; gap: 12px;
    padding: 11px 22px; cursor: pointer;
    transition: background 0.15s; -webkit-tap-highlight-color: transparent;
}
.gsm-row:hover, .gsm-row:active { background: rgba(255,255,255,0.04); }

.gsm-avatar { width: 46px; height: 46px; border-radius: 50%; object-fit: cover; border: 2px solid #FF2D2D; flex-shrink: 0; }
.gsm-avatar.meme { border-radius: 8px; border-color: rgba(255,255,255,0.15); }
.gsm-video-wrap {
    width: 46px; height: 46px; border-radius: 8px; border: 2px solid rgba(255,255,255,0.15);
    background: #111; display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; position: relative; overflow: hidden;
}
.gsm-video-wrap video { width: 100%; height: 100%; object-fit: cover; border-radius: 6px; }
.gsm-video-wrap .gsm-play-overlay { position: absolute; color: rgba(255,255,255,0.75); font-size: 16px; pointer-events: none; }

.gsm-query-icon {
    width: 46px; height: 46px; border-radius: 50%;
    background: rgba(255,255,255,0.06);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.gsm-query-icon i { font-size: 18px; color: rgba(255,255,255,0.4); }

.gsm-info { flex: 1; min-width: 0; }
.gsm-name {
    font-size: 13px; font-weight: 600; color: white;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.gsm-name.cap { color: rgba(255,255,255,0.85); }
.gsm-sub { font-size: 11px; color: rgba(255,255,255,0.4); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.gsm-hl { color: #FF2D2D; font-weight: 700; }

.gsm-rm-btn {
    background: transparent; border: none;
    color: rgba(255,255,255,0.28); font-size: 19px;
    cursor: pointer; padding: 4px 6px; flex-shrink: 0;
    -webkit-tap-highlight-color: transparent;
    line-height: 1; display: flex; align-items: center; justify-content: center;
    transition: color 0.2s;
}
.gsm-rm-btn:hover { color: rgba(255,255,255,0.65); }

.gsm-full-search-row { border-top: 1px solid rgba(255,255,255,0.06); margin-top: 6px; }
.gsm-full-search-icon {
    width: 46px; height: 46px; border-radius: 50%;
    background: rgba(255,45,45,0.1); border: 2px solid rgba(255,45,45,0.3);
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.gsm-full-search-icon i { color: #FF2D2D; font-size: 17px; }
.gsm-full-search-label { color: #FF2D2D; }

.gsm-loader { padding: 28px; text-align: center; color: rgba(255,255,255,0.3); }
.gsm-loader i { font-size: 22px; }
.gsm-spin { animation: gsmSpin 0.7s linear infinite; display: inline-block; }
@keyframes gsmSpin { to { transform: rotate(360deg); } }

/* ── RECENTS ── */
#gsmRecentsWrap { padding: 16px 0 0; }
.gsm-recents-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 0 22px 10px;
}
.gsm-recents-title { font-size: 14px; font-weight: 600; color: white; }
#gsmClearAllBtn {
    background: transparent; border: none; color: #FF2D2D;
    font-size: 12px; font-weight: 600; cursor: pointer;
    -webkit-tap-highlight-color: transparent; transition: opacity 0.2s;
}
#gsmClearAllBtn:hover { opacity: 0.75; }
.gsm-no-recents {
    color: rgba(255,255,255,0.28); font-size: 13px; padding: 4px 22px 22px;
}

/* ── TRIGGER CLEAR BUTTON ── */
.gsm-trigger-clear {
    margin: 7px; width: 38px; height: 38px;
    border-radius: 9px; cursor: pointer; color: white;
    background: #FF2D2D; display: flex; align-items: center;
    justify-content: center; flex-shrink: 0; text-decoration: none;
    font-size: 17px; transition: all 0.22s ease;
    -webkit-tap-highlight-color: transparent;
}
.gsm-trigger-clear:hover { background: #ff1a1a; box-shadow: 0 6px 20px rgba(255,45,45,0.45); transform: scale(1.04); }
.gsm-trigger-clear:active { transform: scale(0.96); }

@media (max-width: 780px) {
    .gsm-topbar { padding: 14px 16px; padding-top: calc(env(safe-area-inset-top) + 14px); gap: 10px; }
    .gsm-back-btn { font-size: 26px; }
    #gsmSearchInput { font-size: 13px; padding: 11px 8px; }
    .gsm-context-bar { padding: 9px 16px; font-size: 11.5px; }
    .gsm-context-bar i { font-size: 13px; }
    .gsm-sec { padding: 12px 16px 2px; }
    .gsm-row { padding: 10px 16px; gap: 11px; }
    .gsm-avatar, .gsm-video-wrap, .gsm-query-icon { width: 42px; height: 42px; }
    .gsm-recents-header { padding: 0 16px 9px; }
}
@media (max-width: 480px) {
    .gsm-topbar { padding: 12px 13px; gap: 9px; }
    #gsmSearchWrap { height: 38px; }
    .gsm-context-bar { padding: 8px 13px; font-size: 11px; gap: 6px; }
    .gsm-context-bar i { font-size: 12px; }
    .gsm-row { padding: 10px 13px; gap: 10px; }
    .gsm-avatar, .gsm-video-wrap, .gsm-query-icon { width: 40px; height: 40px; }
    .gsm-name { font-size: 12px; }
    .gsm-sub { font-size: 10px; }
    .gsm-recents-header { padding: 0 13px 8px; }
    .gsm-trigger-clear { width: 32px; height: 32px; font-size: 15px; margin: 5px; border-radius: 7px; }
}
@media (max-width: 380px) {
    #gsmSearchWrap { height: 34px; }
    .gsm-context-bar { padding: 7px 11px; font-size: 10.5px; gap: 5px; }
    .gsm-context-bar i { font-size: 11.5px; }
    .gsm-row { padding: 9px 11px; gap: 9px; }
    .gsm-avatar, .gsm-video-wrap, .gsm-query-icon { width: 37px; height: 37px; }
}
</style>

<script>
var GSM_GROUP_ID = <?php echo (int)$group_id; ?>;
var gsmTimer = null;
var gsmLastQ = '';
var gsmOpen  = false;
var _gsmRecentCache = {};

function gsmEsc(s){ var d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }
function gsmHL(str,q){
    if(!q) return gsmEsc(str);
    return gsmEsc(str).replace(new RegExp('('+q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+')','gi'),'<span class="gsm-hl">$1</span>');
}

/* ── server-side recents (Pod-scoped only — never touches global search) ── */
function gsmGetRecents(cb){
    fetch('group.php?id='+GSM_GROUP_ID+'&gsearch_recents=get')
        .then(function(r){ return r.json(); })
        .then(function(d){ cb(Array.isArray(d) ? d : []); })
        .catch(function(){ cb([]); });
}
function gsmAddRecent(item){
    fetch('group.php?id='+GSM_GROUP_ID+'&gsearch_recents=add', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
        body: JSON.stringify(item)
    }).catch(function(){});
}
function gsmRemoveRecent(type, id){
    fetch('group.php?id='+GSM_GROUP_ID+'&gsearch_recents=remove', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
        body: JSON.stringify({ type: type, id: id })
    })
    .then(function(){ gsmRenderRecents(); })
    .catch(function(){ gsmRenderRecents(); });
}
function gsmClearAllRecents(){
    fetch('group.php?id='+GSM_GROUP_ID+'&gsearch_recents=clear', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
        body: '{}'
    })
    .then(function(){ gsmRenderRecents(); })
    .catch(function(){ gsmRenderRecents(); });
}

function gsmRenderRecents(){
    var list = document.getElementById('gsmRecentsList');
    var clearBtn = document.getElementById('gsmClearAllBtn');
    if(!list) return;
    list.innerHTML = '<div class="gsm-loader"><i class="ri-loader-4-line gsm-spin"></i></div>';
    clearBtn.style.display = 'none';

    gsmGetRecents(function(arr){
        _gsmRecentCache = {};
        if(!arr.length){
            list.innerHTML = '<div class="gsm-no-recents">No recent searches yet</div>';
            return;
        }
        clearBtn.style.display = 'inline-block';

        list.innerHTML = arr.map(function(r, idx){
            _gsmRecentCache[idx] = r;
            var name, sub, avatarHtml;

            if(r.type === 'member'){
                var pic = r.profile_pic || 'uploads/me.jpeg';
                name = r.username || '';
                sub  = r.nickname ? '@'+r.nickname : 'Member';
                avatarHtml = '<img class="gsm-avatar" src="'+gsmEsc(pic)+'" onerror="this.src=\'uploads/me.jpeg\'">';
            } else if(r.type === 'gpost'){
                name = (r.caption || 'Post').length > 40 ? (r.caption||'Post').substring(0,40)+'…' : (r.caption||'Post');
                sub  = 'Post · by ' + (r.username || 'unknown');
                if(r.meme_type === 'video'){
                    avatarHtml = '<div class="gsm-video-wrap"><video src="uploads/'+gsmEsc(r.file_name)+'" muted playsinline preload="metadata" onerror="this.style.display=\'none\'"></video><i class="ri-play-fill gsm-play-overlay"></i></div>';
                } else {
                    var mpic = r.file_name ? 'uploads/'+r.file_name : 'uploads/Text.jpg';
                    avatarHtml = '<img class="gsm-avatar meme" src="'+gsmEsc(mpic)+'" onerror="this.src=\'uploads/Text.jpg\'">';
                }
            } else {
                name = r.query || '';
                sub  = 'Search';
                avatarHtml = '<div class="gsm-query-icon"><i class="ri-search-line"></i></div>';
            }

            return '<div class="gsm-row" data-idx="'+idx+'" onclick="gsmGoToIdx('+idx+')">'
                + avatarHtml
                + '<div class="gsm-info">'
                    + '<div class="gsm-name'+(r.type==='gpost'?' cap':'')+'">'+gsmEsc(name)+'</div>'
                    + '<div class="gsm-sub">'+gsmEsc(sub)+'</div>'
                + '</div>'
                + '<button class="gsm-rm-btn" onclick="event.stopPropagation();gsmRemoveRecent(\''+r.type+'\',\''+String(r.id)+'\')">'
                    + '<i class="ri-close-line"></i>'
                + '</button>'
            + '</div>';
        }).join('');
    });
}

function gsmGoToIdx(idx){
    var r = _gsmRecentCache[idx];
    if(!r) return;
    gsmAddRecent(r);
    gsmNavigate(r.type, r);
}

function openGroupSearchModal(){
    if(gsmOpen) return;
    gsmOpen = true;
    var m = document.getElementById('gsmModal');
    m.style.display = 'flex';
    m.classList.add('gsm-open');
    document.body.style.overflow = 'hidden';
    gsmRenderRecents();
    setTimeout(function(){
        var inp = document.getElementById('gsmSearchInput');
        if(inp) inp.focus();
    }, 150);
}

function closeGroupSearchModal(){
    if(!gsmOpen) return;
    gsmOpen = false;
    var m = document.getElementById('gsmModal');
    m.style.display = 'none';
    m.classList.remove('gsm-open');
    document.body.style.overflow = '';
    document.getElementById('gsmSearchInput').value = '';
    document.getElementById('gsmClearBtn').style.display = 'none';
    document.getElementById('gsmSearchBtn').style.display = 'none';
    document.getElementById('gsmLiveResults').style.display = 'none';
    document.getElementById('gsmEmptyState').style.display = 'none';
    document.getElementById('gsmRecentsWrap').style.display = 'block';
    gsmLastQ = '';
    clearTimeout(gsmTimer);
}

document.addEventListener('keydown', function(e){
    if(e.key === 'Escape' && gsmOpen) closeGroupSearchModal();
});

function gsmClearSearch(){
    var inp = document.getElementById('gsmSearchInput');
    inp.value = '';
    document.getElementById('gsmClearBtn').style.display = 'none';
    document.getElementById('gsmSearchBtn').style.display = 'none';
    document.getElementById('gsmLiveResults').style.display = 'none';
    document.getElementById('gsmEmptyState').style.display = 'none';
    document.getElementById('gsmRecentsWrap').style.display = 'block';
    gsmLastQ = '';
    clearTimeout(gsmTimer);
    inp.focus();
}

/* ── STAYS ON THIS PAGE — always redirects to group.php with this exact group_id ── */
function gsmFullSearch(){
    var q = document.getElementById('gsmSearchInput').value.trim();
    if(q){
        gsmAddRecent({ type:'query', id: btoa(encodeURIComponent(q)).slice(0,12), query: q });
        location.href = 'group.php?id='+GSM_GROUP_ID+'&search='+encodeURIComponent(q);
    }
}

/* ── STAYS ON THIS PAGE — every branch targets group.php?id=GSM_GROUP_ID
     (member rows still open the member's profile, which is normal navigation,
     not a "search" — it's the same as clicking their name anywhere else) ── */
function gsmNavigate(type, item){
    if(type === 'member'){
        location.href = 'profile.php?user_id='+item.id;
    } else if(type === 'gpost'){
        location.href = 'group.php?id='+GSM_GROUP_ID+'&search='+encodeURIComponent(item.caption||'');
    } else {
        location.href = 'group.php?id='+GSM_GROUP_ID+'&search='+encodeURIComponent(item.query||'');
    }
}

function gsmRenderResults(data, q){
    var box = document.getElementById('gsmLiveResults');
    if(!data || !data.length){
        box.innerHTML = '<div class="gsm-empty">'
            + '<i class="ri-search-2-line"></i>'
            + '<p>No results for "'+gsmEsc(q)+'"</p>'
            + '<small>Try a different word or member name</small>'
            + '</div>';
        return;
    }

    var members = data.filter(function(d){ return d.type === 'member'; });
    var posts   = data.filter(function(d){ return d.type === 'gpost'; });
    var html = '';

    if(members.length){
        html += '<div class="gsm-sec">Members</div>';
        html += members.map(function(u){
            var pic = u.profile_pic || 'uploads/me.jpeg';
            var enc = encodeURIComponent(JSON.stringify(u));
            return '<div class="gsm-row" onclick="gsmSelectResult(\''+enc+'\')">'
                + '<img class="gsm-avatar" src="'+gsmEsc(pic)+'" onerror="this.src=\'uploads/me.jpeg\'">'
                + '<div class="gsm-info">'
                    + '<div class="gsm-name">'+gsmHL(u.username,q)+'</div>'
                    + '<div class="gsm-sub">'+(u.nickname ? gsmHL('@'+u.nickname,q) : 'Member')+'</div>'
                + '</div>'
                + '<i class="ri-arrow-right-s-line" style="color:rgba(255,255,255,0.2);font-size:18px;flex-shrink:0;"></i>'
            + '</div>';
        }).join('');
    }

    if(posts.length){
        html += '<div class="gsm-sec">Posts</div>';
        html += posts.map(function(p){
            var capShort = (p.caption||'').length > 55 ? p.caption.substring(0,55)+'…' : (p.caption||'');
            var thumbHtml;
            if(p.meme_type === 'video'){
                thumbHtml = '<div class="gsm-video-wrap"><video src="uploads/'+gsmEsc(p.file_name)+'" muted playsinline preload="metadata" onerror="this.style.display=\'none\'"></video><i class="ri-play-fill gsm-play-overlay"></i></div>';
            } else {
                var thumb = p.file_name ? 'uploads/'+p.file_name : 'uploads/Text.jpg';
                thumbHtml = '<img class="gsm-avatar meme" src="'+gsmEsc(thumb)+'" onerror="this.src=\'uploads/Text.jpg\'">';
            }
            var enc = encodeURIComponent(JSON.stringify(p));
            return '<div class="gsm-row" onclick="gsmSelectResult(\''+enc+'\')">'
                + thumbHtml
                + '<div class="gsm-info">'
                    + '<div class="gsm-name cap">'+gsmHL(capShort,q)+'</div>'
                    + '<div class="gsm-sub">by '+gsmEsc(p.username)+'</div>'
                + '</div>'
                + '<i class="ri-image-line" style="color:rgba(255,255,255,0.18);font-size:15px;flex-shrink:0;"></i>'
            + '</div>';
        }).join('');
    }

    html += '<div class="gsm-row gsm-full-search-row" onclick="gsmFullSearch()">'
        + '<div class="gsm-full-search-icon"><i class="ri-search-line"></i></div>'
        + '<div class="gsm-info">'
            + '<div class="gsm-name gsm-full-search-label">Search for "'+gsmEsc(q)+'"</div>'
            + '<div class="gsm-sub">See all matching posts &amp; members in this Pod</div>'
        + '</div>'
        + '<i class="ri-arrow-right-line" style="color:#FF2D2D;font-size:15px;flex-shrink:0;"></i>'
    + '</div>';

    box.innerHTML = html;
}

function gsmSelectResult(enc){
    var item = {};
    try { item = JSON.parse(decodeURIComponent(enc)); } catch(e){}
    gsmAddRecent(item);
    gsmNavigate(item.type, item);
}

document.getElementById('gsmSearchInput').addEventListener('input', function(){
    var q = this.value.trim();
    var searchBtn = document.getElementById('gsmSearchBtn');

    document.getElementById('gsmClearBtn').style.display = q ? 'block' : 'none';
    searchBtn.style.display = q ? 'inline-block' : 'none';

    if(!q){
        clearTimeout(gsmTimer);
        document.getElementById('gsmLiveResults').style.display = 'none';
        document.getElementById('gsmEmptyState').style.display = 'none';
        document.getElementById('gsmRecentsWrap').style.display = 'block';
        gsmLastQ = '';
        return;
    }

    document.getElementById('gsmRecentsWrap').style.display = 'none';
    document.getElementById('gsmEmptyState').style.display = 'none';
    document.getElementById('gsmLiveResults').style.display = 'block';
    document.getElementById('gsmLiveResults').innerHTML = '<div class="gsm-loader"><i class="ri-loader-4-line gsm-spin"></i></div>';

    clearTimeout(gsmTimer);
    gsmLastQ = q;
    gsmTimer = setTimeout(function(){
        if(q !== gsmLastQ) return;
        /* Autocomplete endpoint is hard-scoped server-side to GSM_GROUP_ID — see group.php gsearch_type=autocomplete */
        fetch('group.php?id='+GSM_GROUP_ID+'&gsearch_type=autocomplete&q='+encodeURIComponent(q))
            .then(function(r){ return r.json(); })
            .then(function(d){ gsmRenderResults(d, q); })
            .catch(function(){ gsmRenderResults([], q); });
    }, 260);
});

document.getElementById('gsmSearchInput').addEventListener('keydown', function(e){
    if(e.key === 'Enter') gsmFullSearch();
});

document.getElementById('gsmSearchInput').addEventListener('focus', function(){
    document.getElementById('gsmSearchWrap').classList.add('focused');
});
document.getElementById('gsmSearchInput').addEventListener('blur', function(){
    document.getElementById('gsmSearchWrap').classList.remove('focused');
});
</script>


<?php if ($can_view_content): ?>
    <?php include 'group_more_members_strip.php'; ?>
<?php endif; ?>

<!-- ─── POST UPLOAD ───────────────────────────────────────── -->
<?php if($is_member): ?>
<div class="post-pill-wrap" onclick="openPostModal()">
    <img class="post-pill-pic" src="<?php echo htmlspecialchars($current_user_pic); ?>" onerror="this.src='uploads/me.jpeg';">
    <div class="post-pill"><span>Post something...</span><i class="ri-image-line"></i></div>
</div>
<?php else: ?>
<div class="post-pill-wrap locked">
    <img class="post-pill-pic" src="<?php echo htmlspecialchars($current_user_pic); ?>" onerror="this.src='uploads/me.jpeg';">
    <div class="post-pill"><span>Join this pod to post</span><i class="ri-lock-line"></i></div>
</div>
<?php endif; ?>

<!-- ─── POST MODAL (FULL SCREEN) ───────────────────────────── -->
<div id="postModal">

    <div class="pm-topbar">
        <button class="pm-back-btn" onclick="closePostModal()" aria-label="Close">
            <i class="ri-arrow-left-line"></i>
        </button>
        <div class="pm-topbar-center">
            <div class="pm-topbar-title">New Post</div>
            <div class="pm-topbar-sub"><?php echo htmlspecialchars($group['title']); ?></div>
        </div>
        <button class="pm-drafts-btn" onclick="pmToggleDraftsPanel()" aria-label="Drafts">
            <i class="ri-draft-line"></i>
            <span id="pmDraftsBadge" class="pm-drafts-badge" style="display:none;">0</span>
        </button>
    </div>

    <div class="pm-body">

        <?php if (!empty($upload_error)): ?>
            <div class="upload-error"><i class="ri-error-warning-line"></i> <?php echo $upload_error; ?></div>
        <?php endif; ?>

        <!-- ── DRAFTS PANEL — toggled via top bar button ── -->
        <div id="pmDraftsSection" class="pm-drafts-section" style="display:none;">
            <div class="pm-drafts-header">
                <div class="pm-drafts-title">
                    <i class="ri-draft-line"></i> Saved Drafts
                </div>
                <button type="button" class="pm-drafts-clear" id="pmDraftsClearBtn" onclick="pmClearAllDrafts()" style="display:none;">Clear all</button>
            </div>
            <div class="pm-drafts-note">
                <i class="ri-information-line"></i> Only captions are saved — you'll need to re-attach media.
            </div>
            <div id="pmDraftsList"></div>
        </div>

        <form method="POST" enctype="multipart/form-data" class="post-modal-form" id="postModalForm">

            <div class="pm-user-row">
                <img class="post-modal-pic" src="<?php echo htmlspecialchars($current_user_pic); ?>" onerror="this.src='uploads/me.jpeg';">
                <div class="pm-user-text">
                    <div class="pm-user-label">Posting to</div>
                    <div class="pm-user-name"><?php echo htmlspecialchars($group['title']); ?></div>
                </div>
            </div>

            <label class="post-modal-drop" id="postDropLabel">
                <i class="ri-image-add-line"></i>
                <span id="postDropText">Choose meme image or video</span>
                <input type="file" name="post_file" id="postFileInput" onchange="previewPostFile(this)">
            </label>

            <div id="postPreviewWrap"></div>

            <div class="caption-wrap">
                <input type="text" name="caption" id="captionInput" placeholder="Write a caption… include a #hashtag" oninput="checkHashtag(this)" autocomplete="off">
                <div class="hashtag-hint" id="hashtagHint">Must include at least one #hashtag to post</div>
            </div>

            <!-- ── POD HASHTAGS — tap to insert, no need to type ── -->
            <?php if (!empty($group_hashtags)): ?>
            <div class="pm-hashtag-suggest">
                <div class="pm-hashtag-suggest-label">
                    <i class="ri-hashtag"></i> Tap a pod hashtag to add it
                </div>
                <div class="pm-hashtag-pills">
                    <?php foreach ($group_hashtags as $tag): ?>
                    <button type="button" class="pm-hashtag-pill" data-tag="<?php echo htmlspecialchars($tag['tag']); ?>" onclick="insertHashtag(this.dataset.tag, this)">
                        #<?php echo htmlspecialchars($tag['tag']); ?>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="pm-actions-row">
                <button type="button" class="pm-draft-btn" onclick="pmSaveCurrentAsDraft()">
                    <i class="ri-save-line"></i> Save Draft
                </button>
                <button type="submit" name="upload_post" class="post-modal-submit">
                    <i class="ri-send-plane-fill"></i> Upload Post
                </button>
            </div>
        </form>
    </div>
</div>

<style>
/* ═══════════════════════════════════════════
   POST PILL (trigger, unchanged position/behavior)
═══════════════════════════════════════════ */
.post-pill-wrap {
    display: flex;
    align-items: center;
    gap: 10px;
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 999px;
    padding: 6px 16px 6px 6px;
    margin-bottom: 22px;
    cursor: pointer;
    transition: 0.25s;
}
.post-pill-wrap:hover { background: rgba(255,255,255,0.09); }
.post-pill-wrap.locked { cursor: default; opacity: 0.55; }
.post-pill-wrap.locked:hover { background: rgba(255,255,255,0.05); }

.post-pill-pic {
    width: 40px; height: 40px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #FF2D2D;
    flex-shrink: 0;
}
.post-pill {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: rgba(255,255,255,0.06);
    border-radius: 999px;
    padding: 11px 18px;
    color: rgba(255,255,255,0.55);
    font-size: 13px;
}
.post-pill i { font-size: 17px; color: rgba(255,255,255,0.4); }

@media (max-width: 780px) {
    .post-pill-wrap { padding: 4px 14px 4px 5px; gap: 8px; margin: 0 4px 16px; }
    .post-pill-pic { width: 32px; height: 32px; }
    .post-pill { padding: 7px 15px; font-size: 12px; }
} 

@media (max-width: 480px) {
    .post-pill-wrap { padding: 3px 12px 3px 4px; gap: 8px; margin: 0 4px 16px; }
    .post-pill-pic { width: 30px; height: 30px; }
    .post-pill { padding: 5px 13px; font-size: 11px; }
}

/* ═══════════════════════════════════════════
   POST MODAL — FULL SCREEN
   (matches #gcModal / #kebabModal pattern)
═══════════════════════════════════════════ */
#postModal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 999999999;
    background: #030420;
    flex-direction: column;
    overflow: hidden;
}
#postModal.open {
    display: flex;
    animation: pmSlideIn 0.28s cubic-bezier(0.22,1,0.36,1);
}
@keyframes pmSlideIn {
    from { opacity: 0; transform: translateY(20px); }
    to   { opacity: 1; transform: translateY(0); }
}
@keyframes pmSlideOut {
    to { opacity: 0; transform: translateY(16px); }
}

/* ── Top bar ── */
.pm-topbar {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 20px;
    padding-top: calc(env(safe-area-inset-top) + 14px);
    background: rgba(3,4,32,0.97);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    border-bottom: 1px solid rgba(255,255,255,0.07);
    flex-shrink: 0;
    position: sticky;
    top: 0;
    z-index: 10;
}
.pm-back-btn {
    display: flex; align-items: center; justify-content: center;
    width: 36px; height: 36px;
    border-radius: 50%;
    border: 1px solid rgba(255,255,255,0.14);
    background: rgba(255,255,255,0.06);
    color: #fff;
    font-size: 20px;
    cursor: pointer;
    transition: 0.2s;
    flex-shrink: 0;
    -webkit-tap-highlight-color: transparent;
}
.pm-back-btn:hover  { background: rgba(255,255,255,0.13); color: #fff; }
.pm-back-btn:active { transform: scale(0.9); }

.pm-topbar-center { flex: 1; min-width: 0; }
.pm-topbar-title {
    font-size: 15px; font-weight: 700;
    color: #fff;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    font-family: 'Poppins', sans-serif;
}
.pm-topbar-sub {
    font-size: 11px; color: rgba(255,255,255,0.38);
    font-weight: 400; margin-top: 1px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    font-family: 'Poppins', sans-serif;
}

/* ── Drafts button (top bar) ── */
.pm-drafts-btn {
    position: relative;
    display: flex; align-items: center; justify-content: center;
    width: 36px; height: 36px;
    border-radius: 50%;
    border: 1px solid rgba(77,171,255,0.25);
    background: rgba(77,171,255,0.08);
    color: #4dabff;
    font-size: 18px;
    cursor: pointer;
    transition: 0.2s;
    flex-shrink: 0;
    -webkit-tap-highlight-color: transparent;
}
.pm-drafts-btn:hover  { background: rgba(77,171,255,0.16); }
.pm-drafts-btn:active { transform: scale(0.9); }
.pm-drafts-btn.pm-drafts-active {
    background: rgba(77,171,255,0.22);
    border-color: rgba(77,171,255,0.5);
}
.pm-drafts-badge {
    position: absolute;
    top: -4px; right: -4px;
    min-width: 16px; height: 16px;
    padding: 0 4px;
    border-radius: 50px;
    background: #FF2D2D;
    color: #fff;
    font-size: 9.5px;
    font-weight: 700;
    display: flex; align-items: center; justify-content: center;
    border: 2px solid #030420;
    font-family: 'Poppins', sans-serif;
}

/* ── Scrollable body ── */
.pm-body {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
    scrollbar-color: rgba(255,45,45,0.18) transparent;
    padding: 20px 20px 48px;
}
.pm-body::-webkit-scrollbar { width: 4px; }
.pm-body::-webkit-scrollbar-thumb { background: rgba(255,45,45,0.2); border-radius: 99px; }

/* ── Drafts panel ── */
.pm-drafts-section {
    background: rgba(77,171,255,0.05);
    border: 1px solid rgba(77,171,255,0.18);
    border-radius: 16px;
    padding: 14px 14px 6px;
    margin-bottom: 18px;
}
.pm-drafts-header {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 8px;
}
.pm-drafts-title {
    display: flex; align-items: center; gap: 6px;
    font-size: 12.5px; font-weight: 700; color: #4dabff;
    font-family: 'Poppins', sans-serif;
}
.pm-drafts-title i { font-size: 14px; }
.pm-drafts-clear {
    background: none; border: none;
    color: rgba(255,45,45,0.75); font-size: 11px; font-weight: 600;
    cursor: pointer; font-family: 'Poppins', sans-serif;
    -webkit-tap-highlight-color: transparent;
}
.pm-drafts-clear:hover { color: #ff2d2d; }
.pm-drafts-note {
    display: flex; align-items: center; gap: 6px;
    font-size: 10.5px; color: rgba(255,255,255,0.32);
    margin-bottom: 10px;
    font-family: 'Poppins', sans-serif;
}
.pm-drafts-note i { font-size: 12px; flex-shrink: 0; }

.pm-drafts-empty {
    font-size: 11.5px; color: rgba(255,255,255,0.28);
    font-style: italic; padding: 8px 2px 12px;
    font-family: 'Poppins', sans-serif;
}

.pm-draft-row {
    display: flex; align-items: center; gap: 10px;
    padding: 9px 8px;
    border-top: 1px solid rgba(255,255,255,0.05);
}
.pm-draft-row:first-of-type { border-top: none; }
.pm-draft-info { flex: 1; min-width: 0; cursor: pointer; }
.pm-draft-caption {
    font-size: 12.5px; color: rgba(255,255,255,0.85); font-weight: 500;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    font-family: 'Poppins', sans-serif;
}
.pm-draft-time {
    font-size: 10px; color: rgba(255,255,255,0.32); margin-top: 2px;
    font-family: 'Poppins', sans-serif;
}
.pm-draft-use-btn {
    padding: 5px 11px; border-radius: 20px; flex-shrink: 0;
    background: rgba(77,171,255,0.14);
    border: 1px solid rgba(77,171,255,0.35);
    color: #4dabff; font-size: 11px; font-weight: 700;
    cursor: pointer; font-family: 'Poppins', sans-serif;
    -webkit-tap-highlight-color: transparent;
    transition: 0.2s;
}
.pm-draft-use-btn:hover { background: rgba(77,171,255,0.28); }
.pm-draft-del-btn {
    background: none; border: none;
    color: rgba(255,255,255,0.3); font-size: 17px;
    cursor: pointer; padding: 4px; flex-shrink: 0; line-height: 1;
    transition: 0.2s; -webkit-tap-highlight-color: transparent;
}
.pm-draft-del-btn:hover { color: #ff6b6b; }

/* ── User row ── */
.pm-user-row {
    display: flex; align-items: center; gap: 12px;
    margin-bottom: 18px;
}
.post-modal-pic {
    width: 44px; height: 44px;
    border-radius: 50%; object-fit: cover;
    border: 2px solid #FF2D2D;
    flex-shrink: 0;
}
.pm-user-label {
    font-size: 10px; color: rgba(255,255,255,0.38);
    font-family: 'Poppins', sans-serif;
}
.pm-user-name {
    font-size: 14px; font-weight: 700; color: #fff;
    font-family: 'Poppins', sans-serif;
}

.post-modal-form { display: flex; flex-direction: column; gap: 14px; }

.post-modal-drop {
    display: flex; align-items: center; justify-content: center; gap: 8px;
    border: 1.5px dashed rgba(255,255,255,0.15);
    border-radius: 16px;
    padding: 32px 16px;
    color: rgba(255,255,255,0.5);
    font-size: 13px;
    cursor: pointer;
    transition: 0.25s;
}
.post-modal-drop:hover { background: rgba(255,255,255,0.04); border-color: rgba(255,76,76,0.4); }
.post-modal-drop i { font-size: 22px; }
.post-modal-drop input { display: none; }

#postPreviewWrap img,
#postPreviewWrap video {
    width: 100%; max-height: 320px; object-fit: cover;
    border-radius: 16px; display: block;
}

.caption-wrap input[type="text"] {
    width: 100%;
    padding: 13px 16px;
    border-radius: 16px;
    border: 1px solid rgba(255,255,255,0.12);
    background: rgba(255,255,255,0.07);
    color: white;
    font-size: 14px;
    outline: none;
    transition: all 0.3s ease;
    font-family: 'Poppins', sans-serif;
    box-sizing: border-box;
}
.caption-wrap input[type="text"]::placeholder { color: rgba(255,255,255,0.38); }
.caption-wrap input[type="text"]:focus {
    border-color: rgba(255,76,76,0.85);
    box-shadow: 0 0 10px rgba(255,76,76,0.25);
    background: rgba(255,255,255,0.1);
}

.hashtag-hint {
    font-size: 11px; color: rgba(255,255,255,0.35);
    margin-top: 6px; padding-left: 4px;
    transition: color 0.25s;
}
.hashtag-hint.valid   { color: #4cff91; }
.hashtag-hint.invalid { color: #ff6b6b; }

/* ── Pod hashtag suggestions ── */
.pm-hashtag-suggest {
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(77,171,255,0.15);
    border-radius: 16px;
    padding: 12px 14px;
}
.pm-hashtag-suggest-label {
    display: flex; align-items: center; gap: 6px;
    font-size: 11px; font-weight: 700;
    color: rgba(77,171,255,0.75);
    text-transform: uppercase; letter-spacing: 0.6px;
    margin-bottom: 10px;
    font-family: 'Poppins', sans-serif;
}
.pm-hashtag-suggest-label i { font-size: 13px; color: #4dabff; }

.pm-hashtag-pills {
    display: flex; flex-wrap: wrap; gap: 8px;
}
.pm-hashtag-pill {
    padding: 7px 14px;
    border-radius: 30px;
    background: rgba(77,171,255,0.09);
    border: 1px solid rgba(77,171,255,0.28);
    color: #4dabff;
    font-size: 12.5px;
    font-weight: 600;
    font-family: 'Poppins', sans-serif;
    cursor: pointer;
    transition: 0.18s;
    -webkit-tap-highlight-color: transparent;
}
.pm-hashtag-pill:hover  { background: rgba(77,171,255,0.2); }
.pm-hashtag-pill:active { transform: scale(0.95); }
.pm-hashtag-pill.pm-tag-used {
    background: rgba(76,255,145,0.12);
    border-color: rgba(76,255,145,0.4);
    color: #4cff91;
}

/* ── Actions row (Save Draft + Upload) ── */
.pm-actions-row {
    display: flex; gap: 10px;
    margin-top: 4px;
}
.pm-draft-btn {
    display: flex; align-items: center; justify-content: center; gap: 7px;
    flex: 1;
    padding: 14px;
    border-radius: 16px;
    background: rgba(255,255,255,0.06);
    border: 1px solid rgba(255,255,255,0.14);
    color: rgba(255,255,255,0.75);
    font-size: 13.5px; font-weight: 700;
    cursor: pointer; transition: 0.25s;
    font-family: 'Poppins', sans-serif;
    -webkit-tap-highlight-color: transparent;
}
.pm-draft-btn:hover { background: rgba(255,255,255,0.11); color: #fff; }

.post-modal-submit {
    display: flex; align-items: center; justify-content: center; gap: 8px;
    flex: 1.4;
    padding: 14px;
    border: none; border-radius: 16px;
    background: rgba(255,45,45,0.8);
    color: #fff; font-size: 14px; font-weight: 700;
    cursor: pointer; transition: 0.25s;
    font-family: 'Poppins', sans-serif;
}
.post-modal-submit:hover { background: rgba(255,45,45,1); box-shadow: 0 0 16px rgba(255,45,45,0.5); }

.upload-error {
    background: rgba(255,45,45,0.12);
    border: 1px solid rgba(255,45,45,0.35);
    border-radius: 12px;
    padding: 10px 14px;
    color: #ff7a7a;
    font-size: 12px;
    margin-bottom: 14px;
    display: flex;
    align-items: center;
    gap: 7px;
}

/* ════════════════════════════════════════
   RESPONSIVE — 790px
   ════════════════════════════════════════ */
@media (max-width: 790px) {
    .pm-topbar        { padding: 11px 16px; gap: 10px; }
    .pm-back-btn      { width: 32px; height: 32px; font-size: 17px; }
    .pm-drafts-btn     { width: 32px; height: 32px; font-size: 16px; }
    .pm-topbar-title  { font-size: 13.5px; }
    .pm-topbar-sub    { font-size: 10px; }

    .pm-body          { padding: 17px 16px 40px; }
    .pm-user-row      { margin-bottom: 15px; gap: 10px; }
    .post-modal-pic   { width: 40px; height: 40px; }

    .pm-drafts-section { padding: 12px 12px 5px; border-radius: 14px; margin-bottom: 15px; }

    .post-modal-drop  { padding: 26px 14px; border-radius: 14px; }
    #postPreviewWrap img, #postPreviewWrap video { max-height: 260px; border-radius: 14px; }
    .caption-wrap input[type="text"] { font-size: 13px; padding: 11px 14px; border-radius: 14px; }

    .pm-hashtag-suggest { padding: 11px 12px; border-radius: 14px; }
    .pm-hashtag-pill   { padding: 6px 12px; font-size: 12px; }
    .pm-draft-btn      { padding: 13px; font-size: 12.5px; border-radius: 14px; }
    .post-modal-submit { padding: 13px; font-size: 13px; border-radius: 14px; }
}

/* ════════════════════════════════════════
   RESPONSIVE — 490px
   ════════════════════════════════════════ */
@media (max-width: 490px) {
    .pm-topbar        { padding: 10px 14px; gap: 9px; }
    .pm-back-btn      { width: 30px; height: 30px; font-size: 16px; }
    .pm-drafts-btn     { width: 30px; height: 30px; font-size: 15px; }
    .pm-topbar-title  { font-size: 12.5px; }
    .pm-topbar-sub    { font-size: 9.5px; }

    .pm-body          { padding: 14px 13px 36px; }
    .pm-user-row      { margin-bottom: 13px; gap: 9px; }
    .post-modal-pic   { width: 36px; height: 36px; }
    .pm-user-name     { font-size: 13px; }

    .pm-drafts-section { padding: 11px 11px 4px; border-radius: 13px; margin-bottom: 13px; }
    .pm-drafts-title   { font-size: 11.5px; }
    .pm-draft-caption  { font-size: 11.5px; }
    .pm-draft-time     { font-size: 9.5px; }
    .pm-draft-use-btn  { font-size: 10px; padding: 4px 9px; }

    .post-modal-drop  { padding: 22px 12px; border-radius: 13px; font-size: 12px; }
    .post-modal-drop i { font-size: 19px; }
    #postPreviewWrap img, #postPreviewWrap video { max-height: 220px; border-radius: 13px; }
    .caption-wrap input[type="text"] { font-size: 12.5px; padding: 10px 13px; border-radius: 13px; }
    .hashtag-hint     { font-size: 10.5px; }

    .pm-hashtag-suggest { padding: 10px 11px; border-radius: 13px; }
    .pm-hashtag-suggest-label { font-size: 10px; margin-bottom: 8px; }
    .pm-hashtag-pill   { padding: 5px 11px; font-size: 11px; }
    .pm-actions-row    { gap: 8px; }
    .pm-draft-btn      { padding: 12px; font-size: 11.5px; border-radius: 13px; }
    .post-modal-submit { padding: 12px; font-size: 12.5px; border-radius: 13px; }
}

/* ════════════════════════════════════════
   RESPONSIVE — 380px
   ════════════════════════════════════════ */
@media (max-width: 380px) {
    .pm-topbar        { padding: 9px 12px; gap: 8px; }
    .pm-back-btn      { width: 28px; height: 28px; font-size: 15px; }
    .pm-drafts-btn     { width: 28px; height: 28px; font-size: 14px; }
    .pm-topbar-title  { font-size: 11.5px; }
    .pm-topbar-sub    { font-size: 9px; }

    .pm-body          { padding: 12px 11px 32px; }
    .pm-user-row      { margin-bottom: 11px; gap: 8px; }
    .post-modal-pic   { width: 32px; height: 32px; }
    .pm-user-name     { font-size: 12px; }

    .pm-drafts-section { padding: 10px 10px 3px; border-radius: 12px; margin-bottom: 11px; }
    .pm-drafts-note    { font-size: 9.5px; }

    .post-modal-drop  { padding: 18px 10px; border-radius: 12px; font-size: 11px; }
    #postPreviewWrap img, #postPreviewWrap video { max-height: 190px; border-radius: 12px; }
    .caption-wrap input[type="text"] { font-size: 12px; padding: 9px 12px; border-radius: 12px; }

    .pm-hashtag-suggest { padding: 9px 10px; border-radius: 12px; }
    .pm-hashtag-pill   { padding: 5px 10px; font-size: 10.5px; }
    .pm-actions-row    { flex-direction: column; gap: 8px; }
    .pm-draft-btn      { padding: 11px; font-size: 12px; border-radius: 12px; }
    .post-modal-submit { padding: 11px; font-size: 12px; border-radius: 12px; }
}
</style>

<script>
/* ── Per-user, per-group draft storage ── */
var PM_DRAFTS_KEY = 'pm_drafts_group_<?php echo (int)$group_id; ?>_user_<?php echo (int)$current_user; ?>';
var PM_MAX_DRAFTS = 15;
var _pmOpen = false;
var _pmDraftsPanelOpen = false;

function pmLoadDrafts() {
    try {
        var raw = localStorage.getItem(PM_DRAFTS_KEY);
        var arr = raw ? JSON.parse(raw) : [];
        return Array.isArray(arr) ? arr : [];
    } catch (e) {
        return [];
    }
}

function pmSaveDraftsArr(arr) {
    try {
        localStorage.setItem(PM_DRAFTS_KEY, JSON.stringify(arr));
    } catch (e) { /* storage unavailable — fail silently */ }
}

function pmEsc(s) {
    var d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
}

function pmFormatDraftTime(iso) {
    var d = new Date(iso);
    if (isNaN(d.getTime())) return '';
    var diffMs = Date.now() - d.getTime();
    var mins = Math.floor(diffMs / 60000);
    if (mins < 1) return 'Just now';
    if (mins < 60) return mins + 'm ago';
    var hrs = Math.floor(mins / 60);
    if (hrs < 24) return hrs + 'h ago';
    var days = Math.floor(hrs / 24);
    if (days < 7) return days + 'd ago';
    return d.toLocaleDateString();
}

function pmUpdateDraftsBadge() {
    var drafts = pmLoadDrafts();
    var badge = document.getElementById('pmDraftsBadge');
    if (!badge) return;
    if (drafts.length > 0) {
        badge.textContent = drafts.length > 99 ? '99+' : drafts.length;
        badge.style.display = 'flex';
    } else {
        badge.style.display = 'none';
    }
}

function pmRenderDrafts() {
    var drafts = pmLoadDrafts();
    var list = document.getElementById('pmDraftsList');
    var clearBtn = document.getElementById('pmDraftsClearBtn');
    if (!list) return;

    if (!drafts.length) {
        list.innerHTML = '<div class="pm-drafts-empty">No drafts saved yet — write a caption and tap "Save Draft".</div>';
        if (clearBtn) clearBtn.style.display = 'none';
        pmUpdateDraftsBadge();
        return;
    }

    if (clearBtn) clearBtn.style.display = 'inline-block';

    // newest first
    var sorted = drafts.slice().sort(function (a, b) { return b.id - a.id; });

    list.innerHTML = sorted.map(function (d) {
        var capShort = (d.caption || '').length > 60 ? d.caption.substring(0, 60) + '…' : (d.caption || '(empty caption)');
        return '<div class="pm-draft-row">' +
            '<div class="pm-draft-info" onclick="pmUseDraft(' + d.id + ')">' +
                '<div class="pm-draft-caption">' + pmEsc(capShort) + '</div>' +
                '<div class="pm-draft-time">' + pmFormatDraftTime(d.savedAt) + '</div>' +
            '</div>' +
            '<button type="button" class="pm-draft-use-btn" onclick="pmUseDraft(' + d.id + ')">Use</button>' +
            '<button type="button" class="pm-draft-del-btn" onclick="pmDeleteDraft(' + d.id + ')" aria-label="Delete draft">' +
                '<i class="ri-close-line"></i>' +
            '</button>' +
        '</div>';
    }).join('');

    pmUpdateDraftsBadge();
}

function pmSaveCurrentAsDraft() {
    var input = document.getElementById('captionInput');
    var caption = (input.value || '').trim();
    if (!caption) {
        alert('Write a caption first — there\'s nothing to save yet.');
        input.focus();
        return;
    }

    var drafts = pmLoadDrafts();
    drafts.push({ id: Date.now(), caption: caption, savedAt: new Date().toISOString() });

    // cap the list, drop oldest first
    if (drafts.length > PM_MAX_DRAFTS) {
        drafts.sort(function (a, b) { return a.id - b.id; });
        drafts = drafts.slice(drafts.length - PM_MAX_DRAFTS);
    }

    pmSaveDraftsArr(drafts);
    pmRenderDrafts();

    var btn = event && event.currentTarget;
    if (btn) {
        var original = btn.innerHTML;
        btn.innerHTML = '<i class="ri-check-line"></i> Saved!';
        setTimeout(function () { btn.innerHTML = original; }, 1400);
    }
}

function pmUseDraft(id) {
    var drafts = pmLoadDrafts();
    var d = drafts.find(function (x) { return x.id === id; });
    if (!d) return;
    var input = document.getElementById('captionInput');
    input.value = d.caption;
    checkHashtag(input);
    pmCloseDraftsPanel();
    input.focus();
}

function pmDeleteDraft(id) {
    var drafts = pmLoadDrafts().filter(function (x) { return x.id !== id; });
    pmSaveDraftsArr(drafts);
    pmRenderDrafts();
}

function pmClearAllDrafts() {
    if (!confirm('Delete all saved drafts? This can\'t be undone.')) return;
    pmSaveDraftsArr([]);
    pmRenderDrafts();
}

function pmToggleDraftsPanel() {
    if (_pmDraftsPanelOpen) pmCloseDraftsPanel();
    else pmOpenDraftsPanel();
}

function pmOpenDraftsPanel() {
    _pmDraftsPanelOpen = true;
    document.getElementById('pmDraftsSection').style.display = 'block';
    document.querySelector('.pm-drafts-btn').classList.add('pm-drafts-active');
    pmRenderDrafts();
    var body = document.querySelector('.pm-body');
    if (body) body.scrollTop = 0;
}

function pmCloseDraftsPanel() {
    _pmDraftsPanelOpen = false;
    document.getElementById('pmDraftsSection').style.display = 'none';
    document.querySelector('.pm-drafts-btn').classList.remove('pm-drafts-active');
}

/* ── OPEN / CLOSE (full screen pattern) ── */
function openPostModal() {
    if (_pmOpen) return;
    _pmOpen = true;
    var m = document.getElementById('postModal');
    m.classList.add('open');
    m.querySelector('.pm-body').scrollTop = 0;
    document.body.style.overflow = 'hidden';
    pmUpdateDraftsBadge();
}

function closePostModal() {
    if (!_pmOpen) return;

    var input = document.getElementById('captionInput');
    var hasUnsavedText = input && input.value.trim().length > 0;

    if (hasUnsavedText && confirm('Save this caption as a draft before closing?')) {
        var drafts = pmLoadDrafts();
        drafts.push({ id: Date.now(), caption: input.value.trim(), savedAt: new Date().toISOString() });
        if (drafts.length > PM_MAX_DRAFTS) {
            drafts.sort(function (a, b) { return a.id - b.id; });
            drafts = drafts.slice(drafts.length - PM_MAX_DRAFTS);
        }
        pmSaveDraftsArr(drafts);
    }

    _pmOpen = false;
    var m = document.getElementById('postModal');
    m.style.animation = 'pmSlideOut 0.22s ease forwards';
    setTimeout(function () {
        m.classList.remove('open');
        m.style.animation = '';
        document.body.style.overflow = '';
        // Reset form/preview after close animation
        document.getElementById('postPreviewWrap').innerHTML = '';
        document.getElementById('postDropText').textContent = 'Choose meme image or video';
        document.getElementById('postFileInput').value = '';
        document.getElementById('captionInput').value = '';
        document.querySelectorAll('.pm-hashtag-pill').forEach(function (p) {
            p.classList.remove('pm-tag-used');
        });
        pmCloseDraftsPanel();
    }, 210);
}

function previewPostFile(input) {
    var file = input.files[0];
    var wrap = document.getElementById('postPreviewWrap');
    var label = document.getElementById('postDropText');
    if (!file) {
        wrap.innerHTML = '';
        label.textContent = 'Choose meme image or video';
        return;
    }
    label.textContent = file.name;
    var url = URL.createObjectURL(file);
    if (file.type.startsWith('video')) {
        wrap.innerHTML = '<video src="' + url + '" muted loop autoplay playsinline></video>';
    } else {
        wrap.innerHTML = '<img src="' + url + '">';
    }
}

/* ── Insert a pod hashtag into the caption with one tap ── */
function insertHashtag(tag, btnEl) {
    var input = document.getElementById('captionInput');
    var needle = '#' + tag.toLowerCase();
    var current = input.value;

    if (current.toLowerCase().indexOf(needle) !== -1) {
        // already present — just focus, don't duplicate
        input.focus();
        return;
    }

    var trimmed = current.replace(/\s+$/, '');
    input.value = trimmed.length ? (trimmed + ' #' + tag + ' ') : ('#' + tag + ' ');
    input.focus();
    input.setSelectionRange(input.value.length, input.value.length);

    if (btnEl) btnEl.classList.add('pm-tag-used');
    checkHashtag(input);
}

function checkHashtag(input) {
    var hint = document.getElementById('hashtagHint');
    var hasTag = input.value.indexOf('#') !== -1;
    if (hasTag) {
        hint.textContent = '✓ Hashtag detected — good to go!';
        hint.className = 'hashtag-hint valid';
    } else {
        hint.textContent = 'Must include at least one #hashtag to post';
        hint.className = 'hashtag-hint invalid';
    }

    // keep pill "used" highlighting in sync if the person edits the text by hand
    document.querySelectorAll('.pm-hashtag-pill').forEach(function (p) {
        var t = '#' + p.dataset.tag.toLowerCase();
        if (input.value.toLowerCase().indexOf(t) !== -1) {
            p.classList.add('pm-tag-used');
        } else {
            p.classList.remove('pm-tag-used');
        }
    });
}

/* Clear drafts once a post actually goes through — avoid orphaned drafts
   piling up after a successful upload that matches a saved draft caption. */
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('postModalForm');
    if (form) {
        form.addEventListener('submit', function () {
            var input = document.getElementById('captionInput');
            if (!input) return;
            var drafts = pmLoadDrafts().filter(function (d) {
                return d.caption.trim() !== input.value.trim();
            });
            pmSaveDraftsArr(drafts);
        });
    }
    pmUpdateDraftsBadge();
});

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && _pmOpen) {
        if (_pmDraftsPanelOpen) pmCloseDraftsPanel();
        else closePostModal();
    }
});
</script>


<!-- ══ FULL SCREEN SORT MODAL ══ -->
<div id="sortSheet" role="dialog" aria-modal="true" aria-label="Sort options">

  <div class="sort-topbar">
    <button class="sort-back-btn" onclick="closeSortSheet()" aria-label="Close">
      <i class="ri-arrow-left-line"></i>
    </button>
    <div class="sort-topbar-center">
      <div class="sort-topbar-title">Sort posts</div>
      <div class="sort-topbar-sub">Choose how the feed is ordered</div>
    </div>
    <div class="sort-topbar-spacer"></div>
  </div>

  <div class="sort-body">
    <div class="sort-options">

      <?php
      $filters = [
        'newest'    => ['ri-time-line',        'Newest',            'Most recently posted first',          'time'],
        'trending'  => ['ri-fire-line',         'Trending',          'Rising fast — likes per hour',        'engage'],
        'liked'     => ['ri-heart-line',        'Most liked',        'Highest like count first',            'engage'],
        'reposted'  => ['ri-repeat-line',       'Most reposted',     'Highest repost count first',          'engage'],
        'favorited' => ['ri-bookmark-line',     'Most saved',        'Highest bookmark count first',        'engage'],
        'downloaded'=> ['ri-download-line',     'Most downloaded',   'Highest download count first',        'engage'],
        'videos'    => ['ri-video-line',        'Videos only',       'Show video posts first',              'type'],
        'images'    => ['ri-image-line',        'Images only',       'Show image posts first',              'type'],
        'following' => ['ri-user-heart-line',   'People you follow', 'Posts from members you track',        'social'],
      ];

      $groups = [
        'time'   => 'Recency',
        'engage' => 'Engagement',
        'type'   => 'Content type',
        'social' => 'Social',
      ];

      $rendered_groups = [];
      foreach ($filters as $val => $info):
        $group = $info[3];
        if (!in_array($group, $rendered_groups)):
          $rendered_groups[] = $group;
          if (!empty($rendered_groups) && count($rendered_groups) > 1):
      ?>
      <div class="sort-group-divider"></div>
      <?php endif; ?>
      <div class="sort-group-label"><?php echo $groups[$group]; ?></div>
      <?php endif; ?>
        <?php $sel = ($sort === $val) ? ' sort-option-selected' : ''; ?>
      <div class="sort-option<?php echo $sel; ?>" onclick="applyFilter('<?php echo $val; ?>')">
        <div class="sort-option-icon"><i class="<?php echo $info[0]; ?>"></i></div>
        <div class="sort-option-text">
          <div class="sort-option-name"><?php echo $info[1]; ?></div>
          <div class="sort-option-sub"><?php echo $info[2]; ?></div>
        </div>
        <div class="sort-option-check"><?php echo ($sort === $val) ? '✓' : ''; ?></div>
      </div>
      <?php endforeach; ?>

    </div>
  </div>
</div>

<style>

/* ════════════════════════════════════════
   FULL SCREEN SORT MODAL
   ════════════════════════════════════════ */
#sortSheet {
  display: none;
  position: fixed;
  inset: 0;
  z-index: 9999999;
  background: #030420;
  flex-direction: column;
  overflow: hidden;
}
#sortSheet.open {
  display: flex;
  animation: sortSlideIn 0.28s cubic-bezier(0.22,1,0.36,1);
}
@keyframes sortSlideIn {
  from { opacity: 0; transform: translateY(20px); }
  to   { opacity: 1; transform: translateY(0); }
}
@keyframes sortSlideOut {
  to { opacity: 0; transform: translateY(16px); }
}

/* ── Top bar ── */
.sort-topbar {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px 20px;
  background: rgba(3,4,32,0.97);
  backdrop-filter: blur(16px);
  -webkit-backdrop-filter: blur(16px);
  border-bottom: 1px solid rgba(255,255,255,0.07);
  flex-shrink: 0;
  position: sticky;
  top: 0;
  z-index: 10;
}
.sort-back-btn {
  display: flex; align-items: center; justify-content: center;
  width: 36px; height: 36px;
  border-radius: 50%;
  border: 1px solid rgba(255,255,255,0.14);
  background: rgba(255,255,255,0.06);
  color: #fff;
  font-size: 20px;
  cursor: pointer;
  transition: 0.2s;
  flex-shrink: 0;
  -webkit-tap-highlight-color: transparent;
}
.sort-back-btn:hover  { background: rgba(255,255,255,0.13); color: #fff; }
.sort-back-btn:active { transform: scale(0.9); }

.sort-topbar-center { flex: 1; min-width: 0; }
.sort-topbar-title {
  font-size: 15px; font-weight: 700;
  color: #fff;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  font-family: 'Poppins', sans-serif;
}
.sort-topbar-sub {
  font-size: 11px; color: rgba(255,255,255,0.38);
  font-weight: 400; margin-top: 1px;
  font-family: 'Poppins', sans-serif;
}
.sort-topbar-spacer {
  width: 36px; height: 36px; flex-shrink: 0;
}

/* ── Scrollable body ── */
.sort-body {
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
  -webkit-overflow-scrolling: touch;
  scrollbar-width: thin;
  scrollbar-color: rgba(255,45,45,0.18) transparent;
  padding: 14px 0 40px;
}
.sort-body::-webkit-scrollbar { width: 4px; }
.sort-body::-webkit-scrollbar-track { background: transparent; }
.sort-body::-webkit-scrollbar-thumb {
  background: rgba(255,45,45,0.2);
  border-radius: 99px;
}
.sort-body::-webkit-scrollbar-thumb:hover { background: rgba(255,45,45,0.32); }

.sort-options { padding: 0; }

/* ── Group labels ── */
.sort-group-label {
  font-size: 9px; font-weight: 700;
  color: rgba(255,255,255,0.22);
  letter-spacing: 1.2px; text-transform: uppercase;
  padding: 14px 22px 6px;
  font-family: 'Poppins', sans-serif;
}

.sort-group-divider {
  height: 1px;
  background: rgba(255,255,255,0.05);
  margin: 8px 0;
}

/* ── Option rows ── */
.sort-option {
  display: flex; align-items: center; gap: 14px;
  padding: 13px 22px;
  cursor: pointer;
  border-left: 3px solid transparent;
  transition: background 0.15s, border-color 0.15s;
  -webkit-tap-highlight-color: transparent;
  position: relative;
}
.sort-option:hover  { background: rgba(255,255,255,0.04); }
.sort-option:active { background: rgba(255,255,255,0.07); }
.sort-option.sort-option-selected {
  background: rgba(255,45,45,0.07);
  border-left-color: #ff2d2d;
}

.sort-option-icon {
  width: 40px; height: 40px; border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  font-size: 19px; flex-shrink: 0;
  background: rgba(255,255,255,0.06);
  color: rgba(255,255,255,0.5);
  transition: background 0.15s, color 0.15s;
}
.sort-option.sort-option-selected .sort-option-icon {
  background: rgba(255,45,45,0.15);
  color: #ff6b6b;
}

/* Trending icon gets a warm tint */
.sort-option[onclick*="trending"] .sort-option-icon {
  background: rgba(255,160,30,0.1);
  color: rgba(255,160,30,0.7);
}
.sort-option[onclick*="trending"].sort-option-selected .sort-option-icon {
  background: rgba(255,45,45,0.15);
  color: #ff6b6b;
}

.sort-option-text { flex: 1; min-width: 0; }
.sort-option-name {
  font-size: 14px; font-weight: 600;
  color: rgba(255,255,255,0.8); line-height: 1.2;
  font-family: 'Poppins', sans-serif;
}
.sort-option.sort-option-selected .sort-option-name { color: #fff; }
.sort-option-sub {
  font-size: 11.5px; color: rgba(255,255,255,0.28);
  margin-top: 3px; font-family: 'Poppins', sans-serif;
}

.sort-option-check {
  width: 23px; height: 23px; border-radius: 50%;
  border: 1.5px solid rgba(255,255,255,0.14);
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; font-size: 12px; color: transparent;
  transition: all 0.2s;
}
.sort-option.sort-option-selected .sort-option-check {
  background: #ff2d2d; border-color: transparent; color: #fff;
}

/* ════════════════════════════════════════
   RESPONSIVE — 790px
   ════════════════════════════════════════ */
@media (max-width: 790px) {
  .sort-bar { height: 50px; padding: 0 15px; border-radius: 13px; }
  .sort-icon-wrap { width: 30px; height: 30px; font-size: 15px; border-radius: 8px; }
  .sort-bar-label { font-size: 9px; }
  .sort-bar-value { font-size: 13px; }
  #sortChevron { font-size: 17px; }

  .sort-topbar { padding: 11px 16px; gap: 10px; }
  .sort-back-btn { width: 32px; height: 32px; font-size: 17px; }
  .sort-topbar-spacer { width: 32px; height: 32px; }
  .sort-topbar-title { font-size: 13.5px; }
  .sort-topbar-sub { font-size: 10px; }

  .sort-body { padding: 12px 0 32px; }
  .sort-group-label { padding: 12px 18px 5px; font-size: 8.5px; }
  .sort-group-divider { margin: 7px 0; }

  .sort-option { padding: 11px 18px; gap: 12px; }
  .sort-option-icon { width: 36px; height: 36px; font-size: 17px; border-radius: 10px; }
  .sort-option-name { font-size: 13px; }
  .sort-option-sub  { font-size: 10.5px; }
  .sort-option-check { width: 21px; height: 21px; font-size: 11px; }
}

/* ════════════════════════════════════════
   RESPONSIVE — 490px
   ════════════════════════════════════════ */
@media (max-width: 490px) {
  .sort-bar-wrap { margin-bottom: 16px; }
  .sort-bar { height: 47px; padding: 0 13px; border-radius: 12px; }
  .sort-icon-wrap { width: 28px; height: 28px; font-size: 14px; border-radius: 8px; }
  .sort-bar-label { font-size: 8.5px; }
  .sort-bar-value { font-size: 12.5px; }
  #sortChevron { font-size: 16px; }

  .sort-topbar { padding: 10px 14px; gap: 9px; }
  .sort-back-btn { width: 30px; height: 30px; font-size: 16px; }
  .sort-topbar-spacer { width: 30px; height: 30px; }
  .sort-topbar-title { font-size: 13px; }
  .sort-topbar-sub { font-size: 9.5px; }

  .sort-body { padding: 10px 0 28px; }
  .sort-group-label { padding: 11px 16px 5px; font-size: 8px; }
  .sort-group-divider { margin: 6px 0; }

  .sort-option { padding: 10px 16px; gap: 11px; }
  .sort-option-icon { width: 34px; height: 34px; font-size: 16px; border-radius: 9px; }
  .sort-option-name { font-size: 12.5px; }
  .sort-option-sub  { font-size: 10px; }
  .sort-option-check { width: 20px; height: 20px; font-size: 10.5px; }
}

/* ════════════════════════════════════════
   RESPONSIVE — 380px
   ════════════════════════════════════════ */
@media (max-width: 380px) {
  .sort-bar { height: 44px; padding: 0 11px; border-radius: 11px; }
  .sort-icon-wrap { width: 26px; height: 26px; font-size: 13px; border-radius: 7px; }
  .sort-bar-label { font-size: 8px; }
  .sort-bar-value { font-size: 12px; }
  #sortChevron { font-size: 15px; }

  .sort-topbar { padding: 9px 12px; gap: 8px; }
  .sort-back-btn { width: 28px; height: 28px; font-size: 15px; }
  .sort-topbar-spacer { width: 28px; height: 28px; }
  .sort-topbar-title { font-size: 12px; }
  .sort-topbar-sub { font-size: 9px; }

  .sort-body { padding: 9px 0 24px; }
  .sort-group-label { padding: 10px 14px 4px; font-size: 7.5px; letter-spacing: 1px; }
  .sort-group-divider { margin: 6px 0; }

  .sort-option { padding: 9px 14px; gap: 10px; }
  .sort-option-icon { width: 31px; height: 31px; font-size: 14px; border-radius: 8px; }
  .sort-option-name { font-size: 12px; }
  .sort-option-sub  { font-size: 9.5px; }
  .sort-option-check { width: 19px; height: 19px; font-size: 10px; }
}

/* ════════════════════════════════════════
   RESPONSIVE — 330px
   ════════════════════════════════════════ */
@media (max-width: 330px) {
  .sort-bar { height: 41px; padding: 0 9px; border-radius: 10px; }
  .sort-icon-wrap { width: 24px; height: 24px; font-size: 12px; border-radius: 7px; }
  .sort-bar-label { font-size: 7.5px; }
  .sort-bar-value { font-size: 11px; }
  #sortChevron { font-size: 14px; }

  .sort-topbar { padding: 8px 10px; gap: 7px; }
  .sort-back-btn { width: 26px; height: 26px; font-size: 14px; }
  .sort-topbar-spacer { width: 26px; height: 26px; }
  .sort-topbar-title { font-size: 11px; }
  .sort-topbar-sub { font-size: 8.5px; }

  .sort-body { padding: 8px 0 20px; }
  .sort-group-label { padding: 9px 12px 4px; font-size: 7px; letter-spacing: 0.9px; }
  .sort-group-divider { margin: 5px 0; }

  .sort-option { padding: 8px 12px; gap: 9px; }
  .sort-option-icon { width: 28px; height: 28px; font-size: 13px; border-radius: 7px; }
  .sort-option-name { font-size: 11px; }
  .sort-option-sub  { font-size: 9px; }
  .sort-option-check { width: 18px; height: 18px; font-size: 9.5px; }
}
</style>

<script>
(function(){
  var _sheetOpen = false;

  window.openSortSheet = function() {
    if (_sheetOpen) return;
    _sheetOpen = true;
    var sh = document.getElementById('sortSheet');
    sh.classList.add('open');
    sh.querySelector('.sort-body').scrollTop = 0;
    document.body.style.overflow = 'hidden';
  };

  window.closeSortSheet = function() {
    if (!_sheetOpen) return;
    _sheetOpen = false;
    var sh = document.getElementById('sortSheet');
    sh.style.animation = 'sortSlideOut 0.22s ease forwards';
    setTimeout(function(){
      sh.classList.remove('open');
      sh.style.animation = '';
      document.body.style.overflow = '';
    }, 210);
  };

  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') closeSortSheet();
  });
})();

/* ══════════════════════════════════════════════════════════
   SHARED STATE — single source of truth for both the hashtag
   filter (defined earlier on the page, in the sticky topbar
   script) and the sort feature below. Both features now call
   into renderGroupFeedSort() so a hashtag pick and a sort pick
   always compose correctly, in whichever order they happen.
   ══════════════════════════════════════════════════════════ */
window._gfActiveTag   = null;      // lowercase tag string, or null
window._gfCurrentSort = 'newest';  // last applied sort/type/social value

function _gfHoursSince(t) {
  var d = new Date((t || '').replace(' ', 'T'));
  if (isNaN(d.getTime())) return 9999;
  var hrs = (Date.now() - d.getTime()) / 3600000;
  return hrs > 0.5 ? hrs : 0.5; // floor to avoid divide-by-near-zero blowups
}

/* ── Single renderer both the hashtag pills and the sort sheet call ── */
function renderGroupFeedSort(val) {
  if (val) window._gfCurrentSort = val;
  var activeVal = window._gfCurrentSort;

  var feed = document.getElementById('groupFeed');
  if (!feed) return;

  // Always use the shared, never-rebuilt unit cache so cards never get
  // orphaned between the tag filter and the sort feature.
  var units = window._gfGetAllUnits ? window._gfGetAllUnits() : [];

  var unitById = {};
  units.forEach(function (u) {
    var idEl = u.querySelector('[data-id]');
    if (idEl) unitById[String(idEl.dataset.id)] = u;
  });

  var stale = document.getElementById('ghcNoResults');
  if (stale) stale.remove();

  var items = (typeof peuraGroupFeed !== 'undefined') ? peuraGroupFeed.slice() : [];

  // 1) apply the active hashtag filter first, if any
  if (window._gfActiveTag) {
    var needle = '#' + window._gfActiveTag;
    items = items.filter(function (p) {
      return (p.caption || '').toLowerCase().indexOf(needle) !== -1;
    });
  }

  // 2) then apply the sort / type / social filter on top
  switch (activeVal) {
    case 'newest':
      items.sort(function (a, b) {
        var da = new Date((a.time || '').replace(' ', 'T')).getTime();
        var db = new Date((b.time || '').replace(' ', 'T')).getTime();
        return db - da;
      });
      break;

    case 'liked':
      items.sort(function (a, b) { return (b.likeCount || 0) - (a.likeCount || 0); });
      break;

    case 'reposted':
      items.sort(function (a, b) { return (b.repostCount || 0) - (a.repostCount || 0); });
      break;

    case 'favorited':
      items.sort(function (a, b) { return (b.favCount || 0) - (a.favCount || 0); });
      break;

    case 'downloaded':
      // download counts aren't tracked client-side in peuraGroupFeed —
      // keep current ordering rather than guessing.
      break;

    case 'videos':
      items = items.filter(function (p) { return p.type === 'video'; });
      break;

    case 'images':
      items = items.filter(function (p) { return p.type === 'image'; });
      break;

    case 'following':
      items = items.filter(function (p) { return !!p.following; });
      break;

    case 'trending':
      items.sort(function (a, b) {
        var sa = (a.likeCount || 0) / _gfHoursSince(a.time);
        var sb = (b.likeCount || 0) / _gfHoursSince(b.time);
        return sb - sa;
      });
      break;

    default:
      break; // 'newest' fallback already covers unrecognized values well enough
  }

  var matchedIds = {};
  items.forEach(function (p) { matchedIds[String(p.id)] = true; });

  // 3) hide non-matches. Matches get re-appended in sorted order below —
  //    appendChild on an already-attached node just MOVES it, so nothing
  //    is ever detached-and-lost the way the old code could do.
  units.forEach(function (u) {
    var idEl = u.querySelector('[data-id]');
    var id = idEl ? String(idEl.dataset.id) : null;
    if (!id || !matchedIds[id]) {
      u.style.display = 'none';
    }
  });

  items.forEach(function (p) {
    var u = unitById[String(p.id)];
    if (u) {
      u.style.display = '';
      feed.appendChild(u);
    }
  });

  if (!items.length) {
    var noRes = document.createElement('div');
    noRes.id = 'ghcNoResults';
    noRes.style.cssText = 'grid-column:1/-1;text-align:center;padding:40px 20px;color:rgba(255,255,255,0.3);font-size:14px;';
    var msg = window._gfActiveTag
      ? 'No posts tagged #' + window._gfActiveTag + ' match this filter.'
      : 'No posts match this filter.';
    noRes.innerHTML = '<i class="ri-image-line" style="font-size:36px;display:block;margin-bottom:10px;"></i>' + msg;
    feed.appendChild(noRes);
  }
}

function applyFilter(val) {
  var labels = {
    newest:     'Newest',
    liked:      'Most liked',
    reposted:   'Most reposted',
    favorited:  'Most saved',
    downloaded: 'Most downloaded',
    videos:     'Videos only',
    images:     'Images only',
    following:  'People you follow',
    trending:   'Trending',
  };
  var labelText = labels[val] || val;

  /* FIX: #sortBarValue doesn't exist anywhere in the page markup, so
     the old direct reference threw "Cannot set properties of null"
     and silently aborted the rest of this function — closeSortSheet()
     and renderGroupFeedSort() never ran, which is why clicking a sort
     option appeared to do nothing. Guard it, and update the label
     that actually exists (#ghcSortLabel, in the mobile sticky bar). */
  var sortBarValueEl = document.getElementById('sortBarValue');
  if (sortBarValueEl) sortBarValueEl.textContent = labelText;

  var ghcLabelEl = document.getElementById('ghcSortLabel');
  if (ghcLabelEl) ghcLabelEl.textContent = labelText;

  document.querySelectorAll('.sort-option').forEach(function(el){
    el.classList.remove('sort-option-selected');
    var chk = el.querySelector('.sort-option-check');
    if (chk) {
      chk.textContent = '';
      chk.style.color = 'transparent';
    }
  });

  var chosen = document.querySelector('.sort-option[onclick*="\''+val+'\'"]');
  if (chosen) {
    chosen.classList.add('sort-option-selected');
    var chk = chosen.querySelector('.sort-option-check');
    if (chk) {
      chk.textContent = '✓';
      chk.style.color = '#fff';
    }
  }

  setTimeout(function(){
    closeSortSheet();
    setTimeout(function(){
      renderGroupFeedSort(val);
    }, 220);
  }, 130);
}
</script>

<?php
$group_feed_rows = [];
if ($posts) {
    $posts->data_seek(0);
    while ($p = $posts->fetch_assoc()) {
        $group_feed_rows[] = $p;
    }
    $posts->data_seek(0);
}

// ── Build follow map for group feed posts ──
$_gf_follow_map = [];
if ($current_user && !empty($group_feed_rows)) {
    $gf_uids = array_unique(array_column($group_feed_rows, 'user_id'));
    if (!empty($gf_uids)) {
        $gf_uid_str = implode(',', array_map('intval', $gf_uids));
        $gf_follow_res = $conn->query("SELECT following_id FROM followers WHERE follower_id=$current_user AND following_id IN ($gf_uid_str)");
        if ($gf_follow_res) {
            while ($r = $gf_follow_res->fetch_assoc()) {
                $_gf_follow_map[(int)$r['following_id']] = true;
            }
        }
    }
}
?>

<style>
/* ── CARD GRID ── */
.group-feed {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(280px, 100%), 1fr));
    gap: clamp(14px, 3vw, 28px);
    padding: clamp(16px, 4vw, 40px) 0;
    max-width: 100%;
    align-items: start;
}
@media (min-width: 1024px) { .group-feed { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 768px)  { .group-feed { grid-template-columns: repeat(2, 1fr); gap: 14px; padding: 16px 0; } }
@media (max-width: 480px)  { .group-feed { grid-template-columns: 1fr; gap: 12px; padding: 12px 0; } }

.gf-card-unit { display: flex; flex-direction: column; }

.gf-card {
    position: relative;
    border-radius: 20px;
    cursor: pointer;
    transition: transform 0.35s, box-shadow 0.35s;
    background: transparent;
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    border: 1px solid rgba(255,255,255,0.08);
    overflow: hidden;
}
.gf-card:hover { transform: translateY(-3px) scale(1.02); box-shadow: 0 12px 30px rgba(0,0,0,0.4); }

.gf-card img,
.gf-card video {
    width: 100%;
    height: clamp(170px, 30vw, 280px);
    object-fit: cover;
    border-radius: 20px;
    display: block;
}
@media (max-width: 768px) { .gf-card img, .gf-card video { height: clamp(160px, 35vw, 240px); } }
@media (max-width: 480px) { .gf-card img, .gf-card video { height: clamp(220px, 60vw, 300px); } }

.gf-top-buttons {
    position: absolute;
    top: 12px; left: 12px; right: 12px;
    display: flex;
    justify-content: space-between;
    gap: 15px;
    z-index: 5;
}
.gf-btn-group { display: flex; align-items: center; gap: 6px; }

.gf-action-btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: clamp(4px,1vw,6px) clamp(8px,2vw,12px);
    border-radius: 20px;
    background: rgba(62,46,237,0.15);
    border: none;
    color: white;
    font-size: clamp(13px,2.5vw,13px);
    font-family: Poppins, sans-serif;
    cursor: pointer;
    transition: all 0.2s ease;
    min-height: 32px;
    -webkit-tap-highlight-color: transparent;
}
.gf-action-btn i { color: white; transition: color 0.2s; }
.gf-action-btn.active i { color: #ff2a2a; }
.gf-action-btn:hover { box-shadow: 0 0 20px rgba(255,255,255,0.3); }

/* ── POSTED BY row ── */
.gf-posted-by {
    position: absolute;
    top: 55px; left: 12px;
    font-size: clamp(0.7rem,2vw,0.7rem);
    font-weight: 500;
    z-index: 5;
    display: flex;
    align-items: center;
    gap: 4px;
    flex-wrap: nowrap;
    max-width: calc(100% - 16px);
}
.gf-posted-by a { color: white; text-decoration: underline; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 80px; }
.gf-posted-by span { color: #f24455; font-weight: 600; white-space: nowrap; }

/* ── TRACK BUTTON (card) ── */
.gf-track-btn {
    display: inline-flex;
    align-items: center;
    padding: 3px 9px;
    border-radius: 20px;
    border: none;
    font-size: clamp(11px,2.5vw,13px);
    font-weight: 600;
    background: rgba(255,43,43,0.51);
    color: white;
    cursor: pointer;
    transition: 0.25s;
    min-height: 24px;
    white-space: nowrap;
    flex-shrink: 0;
    font-family: Poppins, sans-serif;
    -webkit-tap-highlight-color: transparent;
}
.gf-track-btn.following {
    background: transparent;
    border: 1.5px solid rgba(255,43,43,0.51);
    color: #ff2b2b;
}
.gf-track-btn:hover { background: #ff2b2b; color: white; transform: scale(1.05); }

.gf-del-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 20px;
    background: rgba(255,45,45,0.2);
    border: none;
    color: #ff7a7a;
    font-size: clamp(10px,2.5vw,12px);
    font-family: Poppins, sans-serif;
    cursor: pointer;
    transition: all 0.2s;
    min-height: 32px;
    -webkit-tap-highlight-color: transparent;
}
.gf-del-btn:hover { background: rgba(255,45,45,0.45); color: white; }

.gf-user-bar {
    position: absolute;
    bottom: 10px;
    left: 50%;
    transform: translateX(-50%);
    width: calc(100% - clamp(16px,4vw,60px));
    display: flex;
    align-items: center;
    gap: clamp(5px,1.5vw,10px);
    padding: clamp(4px,1vw,8px) clamp(8px,2vw,12px);
    border-radius: clamp(10px,2vw,15px);
    background: rgba(14,10,239,0.15);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    z-index: 3;
    overflow: hidden;
    min-width: 0;
}
.gf-user-bar img {
    width: clamp(22px,5vw,38px);
    height: clamp(22px,5vw,38px);
    min-width: clamp(22px,5vw,38px);
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid red;
    display: block;
    flex-shrink: 0;
    transition: transform 0.2s, box-shadow 0.2s;
}
.gf-user-bar img:hover { transform: scale(1.08); box-shadow: 0 0 10px rgba(255,0,0,0.6); }
.gf-user-bar-caption {
    color: rgba(255,255,255,0.9);
    font-size: clamp(0.65rem,2.2vw,0.82rem);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    min-width: 0;
    flex: 1;
    font-weight: 500;
}

.gf-social-proof {
    position: absolute;
    bottom: 71px; left: 10px;
    display: flex; flex-direction: row;
    z-index: 3; pointer-events: none; gap: 7px;
}
.gf-sp-wrap {
    position: relative; pointer-events: all;
    display: inline-block; text-decoration: none;
    margin-right: 6px;
    animation: spFloat 3s ease-in-out infinite;
}
@keyframes spFloat { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-6px)} }
.gf-sp-avatar {
    width: 38px !important; height: 38px !important;
    min-width: 38px !important; max-width: 38px !important;
    border-radius: 50% !important; object-fit: cover !important;
    border: 2px solid red !important; display: block !important;
    box-shadow: 0 4px 12px rgba(0,0,0,0.7);
}
.gf-sp-badge {
    position: absolute; bottom: -2px; right: -2px;
    width: 15px; height: 15px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center; z-index: 21;
}
.gf-sp-badge i { font-size: 7px; color: white; line-height: 1; }
@media (max-width: 768px) {
    .gf-social-proof { bottom: 56px; left: 8px; }
    .gf-sp-avatar { width: 35px !important; height: 35px !important; min-width: 35px !important; max-width: 35px !important; }
}

.gf-eng-row {
    display: flex; align-items: center; gap: 8px;
    padding: 6px 4px; cursor: pointer;
    -webkit-tap-highlight-color: transparent;
}
.gf-eng-avatars { display: flex; flex-direction: row; }
.gf-eng-avatar {
    width: 20px !important; height: 20px !important;
    min-width: 20px !important; max-width: 20px !important;
    border-radius: 50% !important; object-fit: cover !important;
    border: 1.5px solid rgba(255,255,255,0.4) !important;
    margin-right: -5px; display: block !important;
}
.gf-eng-avatars .gf-eng-avatar:last-child { margin-right: 4px; }
.gf-eng-text { font-size: 0.7rem; color: rgba(255,255,255,0.75); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex: 1; }
.gf-eng-text strong { color: white; font-weight: 600; }

.gf-new-tag {
    position: absolute; bottom: 55px; left: 12px;
    background: transparent; color: #ffffff;
    border: transparent 1px solid; padding: 4px 10px;
    font-size: 0.72rem; font-weight: 600; border-radius: 10px;
    letter-spacing: 0.5px; display: inline-flex;
    align-items: center; justify-content: center;
    z-index: 20; pointer-events: none;
}

/* ══════════════════════════════════════════════
   MODAL
══════════════════════════════════════════════ */
#gfModal {
    position: fixed; inset: 0;
    z-index: 9999999999999;
    background: rgba(0,0,0,0.92);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
    flex-direction: row;
    gap: 12px;
    opacity: 0;
    pointer-events: none;
    visibility: hidden;
    transition: opacity 0.2s ease, visibility 0.2s ease;
}
#gfModal.gfm-open {
    opacity: 1;
    pointer-events: all;
    visibility: visible;
}

#gfm-card {
    background: rgba(18,17,17,0.95);
    border: 1px solid rgba(255,255,255,0.07);
    border-radius: 20px;
    width: 100%; max-width: 420px;
    overflow: hidden;
    display: flex; flex-direction: column;
    max-height: 90vh;
    transform: scale(0.95);
    transition: transform 0.2s ease;
}
#gfModal.gfm-open #gfm-card { transform: scale(1); }

#gfm-inner { display: flex; flex-direction: column; overflow: hidden; flex: 1; }

.gfm-caption-box::-webkit-scrollbar { width: 4px; }
.gfm-caption-box::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.15); border-radius: 999px; }

.gfm-action-bar {
    display: flex; align-items: center; gap: 6px;
    padding: 10px 14px;
    border-top: 1px solid rgba(255,255,255,0.06);
    flex-shrink: 0;
}
.gfm-action-btn {
    display: flex; align-items: center; justify-content: center; gap: 5px;
    background: transparent;
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 10px; padding: 7px 12px;
    color: white; cursor: pointer;
    font-size: 12px; font-weight: 600;
    font-family: Poppins, sans-serif;
    transition: background 0.2s; flex: 1;
}
.gfm-action-btn:hover { background: rgba(255,255,255,0.07); }
.gfm-close-btn {
    display: flex; align-items: center; justify-content: center;
    background: transparent;
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 10px; padding: 7px 12px;
    color: rgba(255,255,255,0.45); cursor: pointer;
    font-size: 12px; font-family: Poppins, sans-serif;
}

/* ── TRACK BUTTON inside modal ── */
.gfm-track-btn {
    display: inline-flex;
    align-items: center;
    padding: 4px 12px;
    border-radius: 20px;
    border: none;
    font-size: 10.4px;
    font-weight: 600;
    background: rgba(255,43,43,0.55);
    color: white;
    cursor: pointer;
    transition: 0.25s;
    min-height: 26px;
    white-space: nowrap;
    flex-shrink: 0;
    font-family: Poppins, sans-serif;
    -webkit-tap-highlight-color: transparent;
}
.gfm-track-btn.following {
    background: transparent;
    border: 1.5px solid rgba(255,43,43,0.55);
    color: #ff2b2b;
}
.gfm-track-btn:hover { background: #ff2b2b; color: white; transform: scale(1.05); }

#gfm-arrows {
    display: flex; flex-direction: column;
    align-items: center; gap: 10px; flex-shrink: 0;
}
#gfm-hint {
    position: absolute; bottom: 10px; left: 50%;
    transform: translateX(-50%);
    color: rgba(255,255,255,0.18);
    font-size: 10px; white-space: nowrap; pointer-events: none;
}

@media (max-width: 480px) {
    #gfm-arrows, #gfm-hint { display: none !important; }
    #gfModal { padding: 10px !important; }
    #gfm-card { border-radius: 20px 20px 14px 14px !important; max-height: 88vh !important; }
    .gfm-caption-box { font-size: 12px !important; max-height: 60px !important; }
    .gfm-action-btn { padding: 7px 8px !important; font-size: 13px !important; }
}
@media (max-width: 360px) {
    .gfm-caption-box { font-size: 11px !important; max-height: 55px !important; }
    .gfm-action-btn { padding: 6px !important; font-size: 12px !important; }
}

.gf-menu-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: rgba(62,46,237,0.15);
    border: none;
    color: white;
    font-size: 16px;
    cursor: pointer;
    transition: all 0.2s ease;
    -webkit-tap-highlight-color: transparent;
}
.gf-menu-btn:hover {
    box-shadow: 0 0 20px rgba(255,255,255,0.3);
}

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
    font-family: 'Poppins', sans-serif;
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
    font-family: 'Poppins', sans-serif;
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

/* ════════════════════════════════════════
   RESPONSIVE — 790px
   ════════════════════════════════════════ */
@media (max-width: 790px) {
    .gf-hidden-placeholder {
        min-height: 50px;
        border-radius: 14px;
        padding: 10px 12px 10px 15px;
        gap: 10px;
    }
    .gf-hidden-left { gap: 8px; }
    .gf-hidden-icon { width: 27px; height: 27px; font-size: 13px; }
    .gf-hidden-text { font-size: 12px; }
    .gf-undo-btn { font-size: 11px; padding: 6px 13px; gap: 4px; }
    .gf-undo-btn i { font-size: 12px; }
}

/* ════════════════════════════════════════
   RESPONSIVE — 490px
   ════════════════════════════════════════ */
@media (max-width: 490px) {
    .gf-hidden-placeholder {
        min-height: 46px;
        border-radius: 13px;
        padding: 9px 10px 9px 13px;
        gap: 8px;
    }
    .gf-hidden-left { gap: 7px; }
    .gf-hidden-icon { width: 24px; height: 24px; font-size: 12px; }
    .gf-hidden-text { font-size: 11.5px; }
    .gf-undo-btn { font-size: 10.5px; padding: 5px 12px; border-radius: 8px; }
    .gf-undo-btn i { font-size: 11px; }
}

/* ════════════════════════════════════════
   RESPONSIVE — 390px
   ════════════════════════════════════════ */
@media (max-width: 390px) {
    .gf-hidden-placeholder {
        min-height: 42px;
        border-radius: 12px;
        padding: 8px 9px 8px 11px;
        gap: 7px;
    }
    .gf-hidden-left { gap: 6px; }
    .gf-hidden-icon { width: 22px; height: 22px; font-size: 11px; }
    .gf-hidden-text { font-size: 11px; }
    .gf-undo-btn { font-size: 10px; padding: 5px 10px; border-radius: 8px; }
    .gf-undo-btn i { font-size: 10px; }
}

/* ════════════════════════════════════════
   RESPONSIVE — 330px
   ════════════════════════════════════════ */
@media (max-width: 330px) {
    .gf-hidden-placeholder {
        min-height: 38px;
        border-radius: 10px;
        padding: 7px 8px 7px 9px;
        gap: 6px;
    }
    .gf-hidden-left { gap: 5px; }
    .gf-hidden-icon { width: 20px; height: 20px; font-size: 10px; }
    .gf-hidden-text { font-size: 10px; }
    .gf-undo-btn { font-size: 9px; padding: 4px 9px; border-radius: 8px; gap: 3px; }
    .gf-undo-btn i { font-size: 9px; }
}
</style>

<?php
echo '<script>var peuraGroupFeed = [];</script>';
foreach($group_feed_rows as $p) {
    $mid        = (int)$p['meme_id'];
    $mtype      = $p['type'] ?? 'image';
    $mfile      = !empty($p['file_name']) ? 'uploads/' . $p['file_name'] : '';
    $mpic       = !empty($p['profile_pic']) ? $p['profile_pic'] : 'uploads/me.jpeg';
    $muid       = (int)$p['user_id'];
    $is_following_js = !empty($_gf_follow_map[$muid]) ? 'true' : 'false';
    echo '<script>peuraGroupFeed.push({
    id:          ' . $mid . ',
    groupPostId: ' . (int)$p['group_post_id'] . ',   // ← add this line
    file:        "' . addslashes($mfile) . '",
        type:        "' . addslashes($mtype) . '",
        pic:         "' . addslashes($mpic) . '",
        userId:      "' . $muid . '",
        username:    "' . addslashes(htmlspecialchars($p['username'])) . '",
        caption:     "' . addslashes($p['caption']) . '",
        time:        "' . addslashes($p['post_created']) . '",
        likeCount:   ' . (int)$p['like_count'] . ',
        repostCount: ' . (int)$p['repost_count'] . ',
        favCount:    ' . (int)$p['favorites_count'] . ',
        liked:       ' . ($p['is_liked'] ? 'true' : 'false') . ',
        reposted:    ' . ($p['is_reposted'] ? 'true' : 'false') . ',
        faved:       ' . ($p['is_favorited'] ? 'true' : 'false') . ',
        following:   ' . $is_following_js . '
    });</script>';
}
?>

<!-- GRID -->
<div class="group-feed" id="groupFeed">
<?php
foreach($group_feed_rows as $p):
    $mid          = (int)$p['meme_id'];
    $mtype        = $p['type'] ?? 'image';
    $mfile        = !empty($p['file_name']) ? 'uploads/' . $p['file_name'] : '';
    $mpic         = !empty($p['profile_pic']) ? $p['profile_pic'] : 'uploads/me.jpeg';
    $muname       = htmlspecialchars($p['username']);
    $mcap         = htmlspecialchars($p['caption']);
    $muid         = (int)$p['user_id'];
    $is_liked     = (bool)$p['is_liked'];
    $is_reposted  = (bool)$p['is_reposted'];
    $is_faved     = (bool)$p['is_favorited'];
    $like_count   = (int)$p['like_count'];
    $repost_count = (int)$p['repost_count'];
    $fav_count    = (int)$p['favorites_count'];
    $is_new       = (time() - strtotime($p['post_created'])) <= 172800;
    $can_delete   = ($is_owner || $is_admin);
    $poster_is_admin = isset($admins[$muid]);

    // Is current user following this post's author?
    $gf_is_following = !empty($_gf_follow_map[$muid]);

    $sp_list = [];
    if($current_user){
        $sp_res = $conn->query("
            SELECT u.id, u.username, u.profile_pic, 'like' AS action_type
            FROM likes l JOIN users u ON u.id=l.user_id
            JOIN followers f ON f.following_id=u.id
            WHERE l.meme_id=$mid AND f.follower_id=$current_user AND u.id!=$current_user
            UNION
            SELECT u.id, u.username, u.profile_pic, 'repost' AS action_type
            FROM reposts r JOIN users u ON u.id=r.user_id
            JOIN followers f ON f.following_id=u.id
            WHERE r.meme_id=$mid AND f.follower_id=$current_user AND u.id!=$current_user
            LIMIT 4
        ");
        while($sp = $sp_res->fetch_assoc()) $sp_list[] = $sp;
    }

    $js_file     = addslashes($mfile);
    $js_type     = addslashes($mtype);
    $js_pic      = addslashes($mpic);
    $js_username = addslashes($p['username']);
    $js_caption  = addslashes($p['caption']);
?>
<div class="gf-card-unit">
<div class="gf-card"
     onclick="openGFModal(<?= $mid ?>)">

    <!-- TOP BUTTONS -->
<div class="gf-top-buttons" onclick="event.stopPropagation()">
    <div class="gf-btn-group">
        <button class="gf-action-btn gf-like <?= $is_liked ? 'active' : '' ?>" data-id="<?= $mid ?>" onclick="event.stopPropagation()">
            <i class="ri-heart-3-fill"></i>
            <span class="gf-like-count"><?= $like_count ?></span>
        </button>
        <button class="gf-action-btn gf-repost <?= $is_reposted ? 'active' : '' ?>" data-id="<?= $mid ?>" onclick="event.stopPropagation()">
            <i class="ri-repeat-line"></i>
            <span class="gf-repost-count"><?= $repost_count ?></span>
        </button>
        <button class="gf-action-btn gf-fav <?= $is_faved ? 'active' : '' ?>" data-id="<?= $mid ?>" onclick="event.stopPropagation()">
            <i class="ri-bookmark-fill"></i>
            <span class="gf-fav-count"><?= $fav_count ?></span>
        </button>
    </div>
    <div class="gf-btn-group">
        <button class="gf-menu-btn" data-id="<?= $mid ?>" onclick="event.stopPropagation();openGfSheet(<?= $mid ?>)" aria-label="More options">
            <i class="ri-menu-line"></i>
        </button>
    </div>
</div>

    <!-- POSTED BY + TRACK BUTTON -->
    <div class="gf-posted-by" onclick="event.stopPropagation()">
        <span>Posted By</span>
        <strong>
            <a href="profile.php?user_id=<?= $muid ?>" onclick="event.stopPropagation()">
                <?= $muid == $current_user ? 'You' : (mb_strlen($p['username']) > 10 ? mb_substr($p['username'],0,10).'...' : $muname) ?>
            </a>
        </strong>
        <?php if($poster_is_admin): ?>
        <span style="color:#FFD700;font-size:9px;font-weight:700;background:rgba(255,215,0,0.1);padding:1px 6px;border-radius:8px;border:1px solid rgba(255,215,0,0.3);">ADMIN</span>
        <?php endif; ?>
        <?php if($muid != $current_user): ?>
        <button
            class="gf-track-btn <?= $gf_is_following ? 'following' : '' ?>"
            data-user="<?= $muid ?>"
            onclick="event.stopPropagation();gfToggleTrack(this)"
        ><?= $gf_is_following ? 'Untrack' : 'Track' ?></button>
        <?php endif; ?>
    </div>

    <!-- MEDIA -->
<?php if($mtype === 'video' && $mfile): ?>
    <video src="<?= htmlspecialchars($mfile) ?>" autoplay muted loop playsinline></video>
<?php elseif($mtype === 'image' && $mfile): ?>
    <img src="<?= htmlspecialchars($mfile) ?>" onerror="this.style.display='none'" alt="">
<?php else: ?>
    <div style="height:160px;background:rgba(255,255,255,0.04);display:flex;align-items:center;justify-content:center;">
        <i class="ri-image-line" style="font-size:32px;color:rgba(255,255,255,0.2);"></i>
    </div>
<?php endif; ?>

    <?php if($is_new): ?><div class="gf-new-tag">NEW</div><?php endif; ?>

    <!-- SOCIAL PROOF -->
    <?php if(!empty($sp_list)): ?>
    <div class="gf-social-proof">
        <?php foreach($sp_list as $i => $sp):
            $sp_pic   = !empty($sp['profile_pic']) ? $sp['profile_pic'] : 'uploads/me.jpeg';
            $sp_icon  = $sp['action_type'] === 'like' ? 'ri-heart-3-fill' : 'ri-repeat-line';
            $sp_color = $sp['action_type'] === 'like' ? '#f24455' : '#7a4aff';
        ?>
        <a href="profile.php?user_id=<?= $sp['id'] ?>" onclick="event.stopPropagation()" class="gf-sp-wrap" style="animation-delay:<?= $i*0.4 ?>s">
            <img src="<?= $sp_pic ?>" onerror="this.src='uploads/me.jpeg'" class="gf-sp-avatar">
            <span class="gf-sp-badge" style="background:<?= $sp_color ?>;"><i class="<?= $sp_icon ?>"></i></span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- USER BAR -->
    <div class="gf-user-bar" onclick="event.stopPropagation()">
        <a href="profile.php?user_id=<?= $muid ?>" onclick="event.stopPropagation()" style="flex-shrink:0;">
            <img src="<?= htmlspecialchars($mpic) ?>" onerror="this.src='uploads/me.jpeg'">
        </a>
        <div class="gf-user-bar-caption"><?= $mcap ?></div>
    </div>

</div><!-- /gf-card -->

<!-- ENGAGEMENT ROW -->
<?php
$eng_q = $conn->query("SELECT u.id, u.username, u.profile_pic FROM likes l JOIN users u ON u.id=l.user_id WHERE l.meme_id=$mid LIMIT 3");
$eng_users = [];
while($r = $eng_q->fetch_assoc()) $eng_users[] = $r;

if($like_count > 0 || $repost_count > 0):
?>
<div class="gf-eng-row" onclick="openEngModal(<?= $mid ?>, 'likes')" style="cursor:pointer;">
    <?php if(!empty($eng_users)): ?>
    <div class="gf-eng-avatars">
        <?php foreach($eng_users as $eu): 
            $ep = !empty($eu['profile_pic']) ? $eu['profile_pic'] : 'uploads/me.jpeg'; 
        ?>
        <img src="<?= $ep ?>" onerror="this.src='uploads/me.jpeg'" class="gf-eng-avatar">
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <span class="gf-eng-text">
        <?php
        if(!empty($eng_users)){
            echo 'Liked by <strong>' . htmlspecialchars($eng_users[0]['username']) . '</strong>';
            if($like_count > 1) echo ' and <strong>' . ($like_count-1) . ' others</strong>';
            if($repost_count > 0) echo ' · <strong>' . $repost_count . '</strong> reposts';
        } else {
            echo '<strong>' . $repost_count . '</strong> reposts';
        }
        ?>
    </span>
</div>
<?php endif; ?>

</div><!-- /gf-card-unit -->
<?php endforeach; ?>
</div><!-- /group-feed -->

<?php if(empty($group_feed_rows)): ?>
<div style="text-align:center;padding:40px 20px;color:rgba(255,255,255,0.3);font-size:14px;">
    <i class="ri-image-line" style="font-size:36px;display:block;margin-bottom:10px;"></i>
    No posts yet<?php if(!empty($search_query)) echo ' matching your search'; ?>.
</div>
<?php endif; ?>


<!-- ═══════════════════════════════════════════════════════
     GROUP FEED BOTTOM SHEET (mirrors home.php index sheet)
     ═══════════════════════════════════════════════════════ -->
<div id="gfSheetOverlay" class="idx-sheet-overlay" onclick="closeGfSheet()"></div>
<div id="gfSheetPanel" class="idx-sheet-panel">
    <div class="idx-sheet-handle"></div>
    <div class="idx-sheet-list" id="gfSheetList"></div>
    <button class="idx-sheet-cancel" onclick="closeGfSheet()">Cancel</button>
</div>

<style>
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
    z-index:99999999;
    max-height:80vh;
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
.idx-sheet-list::-webkit-scrollbar{width:4px;}
.idx-sheet-list::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.15);border-radius:999px;}

.idx-sheet-item{
    display:flex;align-items:center;gap:11px;
    padding:9px 18px;
    color:#fff;text-decoration:none;
    font-size:13px;font-weight:500;
    font-family:Poppins,sans-serif;
    cursor:pointer;
    -webkit-tap-highlight-color:transparent;
    transition:background 0.15s ease;
    border-bottom:1px solid rgba(255,255,255,0.06);
}
.idx-sheet-item:last-child{border-bottom:none;}
.idx-sheet-item:active{background:rgba(255,255,255,0.06);}
.idx-sheet-item i{font-size:15.5px;color:#f24455;width:18px;text-align:center;flex-shrink:0;}
.idx-sheet-item.danger{color:#ff4c4c;}
.idx-sheet-item.danger i{color:#ff4c4c;}

.idx-sheet-cancel{
    margin:6px 12px 0;
    padding:10px;
    background:rgba(255,255,255,0.06);
    border:none;border-radius:12px;
    color:rgba(255,255,255,0.7);
    font-weight:600;font-size:12.5px;
    font-family:Poppins,sans-serif;
    cursor:pointer;flex-shrink:0;
    -webkit-tap-highlight-color:transparent;
}
.idx-sheet-cancel:active{background:rgba(255,255,255,0.1);}

/* Desktop: center as compact modal instead of bottom sheet */
@media (min-width:791px){
    .idx-sheet-panel{
        left:50%;right:auto;bottom:auto;top:50%;
        width:100%;max-width:320px;
        border-radius:18px;
        transform:translate(-50%,-40%) scale(0.95);
        opacity:0;
        pointer-events:none;
        max-height:none;
    }
    .idx-sheet-panel.show{
        transform:translate(-50%,-50%) scale(1);
        opacity:1;
        pointer-events:all;
    }
    .idx-sheet-handle{display:none;}
}

@media (max-width:790px){
    .idx-sheet-panel{border-radius:18px 18px 0 0;max-height:78vh;padding:7px 0 max(9px, env(safe-area-inset-bottom));}
    .idx-sheet-handle{width:32px;height:3.5px;margin:0 auto 4px;}
    .idx-sheet-item{padding:8px 16px;font-size:12.5px;gap:10px;}
    .idx-sheet-item i{font-size:15px;width:17px;}
    .idx-sheet-cancel{margin:5px 11px 0;padding:9px;font-size:12px;border-radius:11px;}
}
@media (max-width:490px){
    .idx-sheet-panel{border-radius:16px 16px 0 0;max-height:76vh;padding:6px 0 max(8px, env(safe-area-inset-bottom));backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);}
    .idx-sheet-handle{width:30px;height:3px;margin:0 auto 3px;}
    .idx-sheet-item{padding:7px 14px;font-size:12px;gap:9px;}
    .idx-sheet-item i{font-size:14px;width:16px;}
    .idx-sheet-cancel{margin:5px 10px 0;padding:8px;font-size:11.5px;border-radius:10px;}
}
@media (max-width:390px){
    .idx-sheet-panel{border-radius:14px 14px 0 0;max-height:74vh;padding:6px 0 max(7px, env(safe-area-inset-bottom));backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);box-shadow:0 -8px 30px rgba(0,0,0,0.45);}
    .idx-sheet-handle{width:28px;height:3px;margin:0 auto 3px;}
    .idx-sheet-item{padding:6.5px 12px;font-size:11.5px;gap:8px;}
    .idx-sheet-item i{font-size:13.5px;width:15px;}
    .idx-sheet-cancel{margin:4px 9px 0;padding:7px;font-size:11px;border-radius:9px;}
}
@media (max-width:330px){
    .idx-sheet-panel{border-radius:12px 12px 0 0;max-height:72vh;padding:5px 0 max(6px, env(safe-area-inset-bottom));backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);box-shadow:0 -6px 24px rgba(0,0,0,0.4);}
    .idx-sheet-handle{width:24px;height:2.5px;margin:0 auto 3px;}
    .idx-sheet-list::-webkit-scrollbar{width:3px;}
    .idx-sheet-item{padding:5.5px 10px;font-size:10.5px;gap:7px;}
    .idx-sheet-item i{font-size:12.5px;width:13px;}
    .idx-sheet-cancel{margin:4px 8px 0;padding:6px;font-size:10px;border-radius:8px;}
}
</style>

<script>
/* Permission flags for the delete option — echoed from PHP */
var GF_IS_OWNER = <?php echo $is_owner ? 'true' : 'false'; ?>;
var GF_IS_ADMIN = <?php echo $is_admin ? 'true' : 'false'; ?>;
var GF_CSRF     = CSRF_TOKEN; // already defined globally in group.php

function _gfSheetHTML(d, flags){
    var items = [];
    var safeCaption = (d.caption || '').replace(/\\/g,'\\\\').replace(/'/g,"\\'");

    items.push('<div class="idx-sheet-item" onclick="gfCopyCaption(\''+safeCaption+'\');closeGfSheet();"><i class="ri-file-copy-line"></i> Copy Caption</div>');

    if(d.type !== 'text' && d.file){
        items.push('<a class="idx-sheet-item" href="'+d.file+'" download><i class="ri-download-2-line"></i> Download</a>');
    }

    items.push('<div class="idx-sheet-item" onclick="gfShowMemeQR('+d.id+');closeGfSheet();"><i class="ri-qr-code-line"></i> QR Code</div>');
    items.push('<a class="idx-sheet-item" href="account.php?user_id='+d.userId+'"><i class="ri-user-search-line"></i> About Account</a>');
    items.push('<div class="idx-sheet-item" onclick="gfShareMeme('+d.id+');closeGfSheet();"><i class="ri-share-line"></i> Share Post</div>');
    items.push('<div class="idx-sheet-item" onclick="gfHidePost('+d.id+');closeGfSheet();"><i class="ri-eye-off-line"></i> Hide Post</div>');

    if(flags.canDelete && d.groupPostId){
        items.push('<div class="idx-sheet-item danger" onclick="gfDeletePost('+d.groupPostId+','+d.id+')"><i class="ri-delete-bin-line"></i> Delete Post</div>');
    }

    if(!flags.isOwnPost){
        items.push('<div class="idx-sheet-item danger" onclick="closeGfSheet();gfBlockUser('+d.userId+')"><i class="ri-forbid-line"></i> Block User</div>');
    }

    items.push('<div class="idx-sheet-item danger" onclick="closeGfSheet();openGfPostReportModal('+d.id+','+d.userId+')"><i class="ri-flag-line"></i> Report</div>');

    return items.join('');
}

function openGfSheet(memeId){
    var d = peuraGroupFeed.find(function(p){ return String(p.id) === String(memeId); });
    if(!d) return;

    var isOwnPost = String(d.userId) === String(gfCurrentUserId);
    var canDelete = GF_IS_OWNER || GF_IS_ADMIN || isOwnPost;

    document.getElementById('gfSheetList').innerHTML = _gfSheetHTML(d, { canDelete: canDelete, isOwnPost: isOwnPost });
    document.getElementById('gfSheetOverlay').classList.add('show');
    document.getElementById('gfSheetPanel').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeGfSheet(){
    document.getElementById('gfSheetOverlay').classList.remove('show');
    document.getElementById('gfSheetPanel').classList.remove('show');
    document.body.style.overflow = '';
}

document.addEventListener('keydown', function(e){
    if(e.key === 'Escape'){
        var panel = document.getElementById('gfSheetPanel');
        if(panel && panel.classList.contains('show')) closeGfSheet();
    }
});

/* ── Sheet actions ── */
function gfCopyCaption(caption){
    navigator.clipboard.writeText(caption).then(function(){
        gfShowToast('Caption copied!');
    }).catch(function(){
        var el = document.createElement('textarea');
        el.value = caption;
        document.body.appendChild(el);
        el.select();
        document.execCommand('copy');
        document.body.removeChild(el);
        gfShowToast('Caption copied!');
    });
}

function gfShowMemeQR(memeId){
    var url = window.location.origin + window.location.pathname + '?id=' + gfGroupId + '&meme=' + memeId;
    var qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(url);
    var win = window.open('', '_blank', 'width=260,height=280');
    win.document.write('<body style="margin:0;background:#000;display:flex;align-items:center;justify-content:center;height:100vh;"><img src="'+qrUrl+'" style="border-radius:12px;"></body>');
}

function gfShareMeme(memeId){
    var url = window.location.origin + window.location.pathname + '?id=' + gfGroupId + '&meme=' + memeId;
    if(navigator.share){
        navigator.share({ title: 'Check this post', url: url });
    } else {
        navigator.clipboard.writeText(url);
        gfShowToast('Link copied to clipboard!');
    }
}

function gfDeletePost(groupPostId, memeId){
    if(!confirm('Permanently delete this post?')) return;
    var f = document.createElement('form');
    f.method = 'POST';
    f.action = 'group.php?id=' + gfGroupId;

    [['delete_post','1'],['delete_post_id',groupPostId],['delete_meme_id',memeId],['csrf_token',GF_CSRF]].forEach(function(pair){
        var inp = document.createElement('input');
        inp.type  = 'hidden';
        inp.name  = pair[0];
        inp.value = pair[1];
        f.appendChild(inp);
    });

    document.body.appendChild(f);
    f.submit();
}

/* ── HIDDEN POSTS — persisted per user+group in localStorage so
     they stay hidden across refreshes until Undo is clicked ── */
var GF_HIDDEN_KEY = 'gf_hidden_posts_group_<?php echo (int)$group_id; ?>_user_<?php echo (int)$current_user; ?>';
var _gfHiddenState = {}; // memeId -> { unit, originalHTML }

function gfLoadHiddenIds() {
    try {
        var raw = localStorage.getItem(GF_HIDDEN_KEY);
        var arr = raw ? JSON.parse(raw) : [];
        return Array.isArray(arr) ? arr : [];
    } catch (e) { return []; }
}

function gfSaveHiddenIds(arr) {
    try { localStorage.setItem(GF_HIDDEN_KEY, JSON.stringify(arr)); }
    catch (e) { /* storage unavailable — fail silently */ }
}

function gfAddHiddenId(memeId) {
    var ids = gfLoadHiddenIds();
    if (ids.indexOf(String(memeId)) === -1) {
        ids.push(String(memeId));
        gfSaveHiddenIds(ids);
    }
}

function gfRemoveHiddenId(memeId) {
    var ids = gfLoadHiddenIds().filter(function (id) { return id !== String(memeId); });
    gfSaveHiddenIds(ids);
}

/* skipServerCall = true when re-applying on page load (already reported once) */
function gfHidePost(memeId, skipServerCall){
    var btn  = document.querySelector('.gf-menu-btn[data-id="'+memeId+'"]');
    var unit = btn ? btn.closest('.gf-card-unit') : null;
    if(!unit) return;

    if(_gfHiddenState[memeId]) return; // already hidden

    var originalHTML = unit.innerHTML;

    unit.innerHTML =
        '<div class="gf-hidden-placeholder" data-hidden-id="'+memeId+'">' +
            '<div class="gf-hidden-left">' +
                '<div class="gf-hidden-icon"><i class="ri-eye-off-line"></i></div>' +
                '<div class="gf-hidden-text">Post Hidden</div>' +
            '</div>' +
            '<button type="button" class="gf-undo-btn" onclick="gfUndoHidePost('+memeId+')">' +
                '<i class="ri-arrow-go-back-line"></i> Undo' +
            '</button>' +
        '</div>';

    _gfHiddenState[memeId] = { unit: unit, originalHTML: originalHTML };
    gfAddHiddenId(memeId);

    if (!skipServerCall) {
        fetch('home.php', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: 'action=not_interested&meme_id=' + memeId
        }).catch(function(){ /* best-effort, ignore failures */ });
    }
}

function gfUndoHidePost(memeId){
    var pending = _gfHiddenState[memeId];
    if(!pending) return;

    pending.unit.innerHTML = pending.originalHTML;
    delete _gfHiddenState[memeId];
    gfRemoveHiddenId(memeId);

    gfShowToast('Post restored');
}

/* ── Re-apply hidden state on page load, before the user does anything ── */
document.addEventListener('DOMContentLoaded', function () {
    var hiddenIds = gfLoadHiddenIds();
    hiddenIds.forEach(function (id) {
        gfHidePost(id, true); // true = don't re-fire the server call
    });
});

/* ── BLOCK USER — reuses the same 'blocks' table logic as home.php,
     then removes every card by that user from this group feed ── */
function gfBlockUser(userId){
    if(!confirm('Block this user? You won\'t see their content anymore.')) return;

    fetch('home.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=block_user&target_id=' + userId
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        if(data.status === 'ok'){
            gfShowToast('User blocked');
            document.querySelectorAll('.gf-track-btn[data-user="'+userId+'"]').forEach(function(btn){
                var unit = btn.closest('.gf-card-unit');
                if(unit){
                    unit.style.transition = 'opacity 0.35s, transform 0.35s';
                    unit.style.opacity    = '0';
                    unit.style.transform  = 'scale(0.96)';
                    setTimeout(function(){ unit.remove(); }, 350);
                }
            });
        } else {
            gfShowToast('Could not block user');
        }
    })
    .catch(function(){ gfShowToast('Network error — try again'); });
}

function gfShowToast(msg){
    var t = document.createElement('div');
    t.textContent = msg;
    Object.assign(t.style, {
        position:'fixed', bottom:'90px', left:'50%', transform:'translateX(-50%)',
        background:'rgba(20,20,25,.95)', border:'1px solid rgba(255,45,45,.3)',
        color:'#fff', padding:'8px 20px', borderRadius:'50px', fontSize:'12px',
        zIndex:'9999999', pointerEvents:'none', boxShadow:'0 8px 24px rgba(0,0,0,.6)',
        transition:'opacity .3s', whiteSpace:'nowrap'
    });
    document.body.appendChild(t);
    setTimeout(function(){ t.style.opacity='0'; setTimeout(function(){ t.remove(); },300); }, 2200);
}
</script>


<!-- ═══════════════════════════════════════════════════════
     GROUP FEED POST REPORT MODAL
     Report Content / Report User / Report to Group Admins
     Same 3-screen pattern as the site-wide report modal on
     home.php, but scoped to this specific pod's posts.
     ═══════════════════════════════════════════════════════ -->
<style>
#gfPostReportOverlay,
#gfPostRptStep1Screen,
#gfPostRptStep2Screen {
    display: none;
    position: fixed; inset: 0;
    z-index: 999999999999;
    font-family: 'Poppins', sans-serif;
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
#gfPostReportOverlay.gfrpt-open,
#gfPostRptStep1Screen.gfrpt-open,
#gfPostRptStep2Screen.gfrpt-open { opacity: 1; transform: translateY(0); }

#gfPostReportOverlay::-webkit-scrollbar,
#gfPostRptStep1Screen::-webkit-scrollbar,
#gfPostRptStep2Screen::-webkit-scrollbar { width: 4px; }
#gfPostReportOverlay::-webkit-scrollbar-thumb,
#gfPostRptStep1Screen::-webkit-scrollbar-thumb,
#gfPostRptStep2Screen::-webkit-scrollbar-thumb { background: rgba(255,255,255,.14); border-radius: 999px; }

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
.gfrpt-back-btn{
    display:flex;align-items:center;background:none;border:none;
    color:rgba(255,255,255,.5);font-size:13px;font-family:'Poppins',sans-serif;
    cursor:pointer;padding:4px 2px;transition:color .18s;line-height:1;
}
.gfrpt-back-btn i{font-size:30px;}
.gfrpt-back-btn:hover{color:#fff;}
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

.gfrpt-cat-row{
    display:flex;align-items:center;gap:15px;
    padding:14px 22px;border-bottom:1px solid rgba(255,255,255,.048);
    cursor:pointer;transition:background .18s;-webkit-tap-highlight-color:transparent;
}
.gfrpt-cat-row:hover{background:rgba(255,255,255,.035);}
.gfrpt-cat-row:active{background:rgba(255,255,255,.065);}
.gfrpt-cat-icon{
    width:42px;height:42px;flex-shrink:0;border-radius:13px;
    background:rgba(255,45,45,.13);
    display:flex;align-items:center;justify-content:center;
    font-size:20px;color:#FF2D2D;
}
.gfrpt-cat-info{flex:1;min-width:0;}
.gfrpt-cat-label{font-size:13.5px;font-weight:600;color:#fff;}
.gfrpt-cat-sub{font-size:11px;color:rgba(255,255,255,.35);margin-top:3px;line-height:1.4;}
.gfrpt-cat-chevron{font-size:18px;color:rgba(255,255,255,.18);transition:transform .18s,color .18s;}
.gfrpt-cat-row:hover .gfrpt-cat-chevron{transform:translateX(3px);color:rgba(255,255,255,.35);}

.gfrpt-reason-row{
    display:flex;align-items:center;gap:14px;
    padding:14px 22px;border-bottom:1px solid rgba(255,255,255,.045);
    cursor:pointer;transition:background .18s;-webkit-tap-highlight-color:transparent;
}
.gfrpt-reason-row:hover{background:rgba(255,255,255,.035);}
.gfrpt-reason-row:active{background:rgba(255,255,255,.065);}
.gfrpt-reason-row.selected{background:rgba(255,45,45,.09);border-left:3px solid #FF2D2D;}
.gfrpt-reason-icon{font-size:19px;color:#FF2D2D;flex-shrink:0;width:24px;text-align:center;}
.gfrpt-reason-text{flex:1;font-size:13.5px;font-weight:500;color:rgba(255,255,255,.85);}
.gfrpt-reason-row.selected .gfrpt-reason-text{color:#fff;font-weight:600;}
.gfrpt-check{
    width:23px;height:23px;border-radius:50%;
    border:1.5px solid rgba(255,255,255,.2);
    display:flex;align-items:center;justify-content:center;
    font-size:12px;color:transparent;flex-shrink:0;transition:all .2s;
}
.gfrpt-reason-row.selected .gfrpt-check{background:#FF2D2D;border-color:transparent;color:#fff;}

.gfrpt-other-wrap{padding:10px 22px 14px;display:none;}
.gfrpt-other-textarea{
    width:100%;min-height:100px;
    background:rgba(255,255,255,.045);
    border:1px solid rgba(255,255,255,.09);
    border-radius:13px;padding:13px 15px;
    color:#fff;font-size:13px;font-family:'Poppins',sans-serif;
    resize:vertical;outline:none;box-sizing:border-box;
    transition:border-color .2s,background .2s;
}
.gfrpt-other-textarea::placeholder{color:rgba(255,255,255,.22);}
.gfrpt-other-textarea:focus{border-color:rgba(255,45,45,.4);background:rgba(255,45,45,.04);}
.gfrpt-char-count{text-align:right;font-size:10px;color:rgba(255,255,255,.25);margin-top:5px;}

.gfrpt-footer{padding:16px 22px 40px;}
.gfrpt-submit-btn{
    width:100%;padding:14px;border-radius:13px;
    background:#FF2D2D;border:none;color:#fff;
    font-size:13.5px;font-weight:700;font-family:'Poppins',sans-serif;
    cursor:pointer;letter-spacing:.3px;
    transition:background .18s,transform .15s,box-shadow .2s;
}
.gfrpt-submit-btn:hover{background:#e01f1f;box-shadow:0 4px 20px rgba(255,45,45,.35);}
.gfrpt-submit-btn:active{transform:scale(.98);}
.gfrpt-submit-btn:disabled{opacity:.55;pointer-events:none;}
.gfrpt-cancel-btn{
    width:100%;padding:12px;margin-top:10px;border-radius:13px;
    background:rgba(255,255,255,.05);
    border:1px solid rgba(255,255,255,.09);
    color:rgba(255,255,255,.42);font-size:13px;font-weight:600;
    font-family:'Poppins',sans-serif;cursor:pointer;transition:background .18s;
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
    background:#FF2D2D;border:none;color:#fff;
    font-size:14px;font-weight:700;font-family:'Poppins',sans-serif;
    cursor:pointer;transition:background .18s;
}
.gfrpt-done-btn:hover{background:#e01f1f;}

.gfrpt-toast{
    position:fixed;bottom:30px;left:50%;
    transform:translateX(-50%) translateY(10px);
    background:rgba(8,8,22,.97);
    border:1px solid rgba(255,45,45,.25);
    color:#fff;padding:9px 22px;border-radius:50px;
    font-size:12px;font-family:'Poppins',sans-serif;font-weight:500;
    z-index:9999999999;pointer-events:none;
    box-shadow:0 8px 28px rgba(0,0,0,.65);
    white-space:nowrap;opacity:0;
    transition:opacity .22s ease,transform .22s ease;
}
.gfrpt-toast.show{opacity:1;transform:translateX(-50%) translateY(0);}

@media (max-width:780px){
    .gfrpt-topbar{padding:13px 18px;}
    .gfrpt-topbar-title{font-size:14.5px;}
    .gfrpt-section-label{padding:18px 18px 7px;}
    .gfrpt-cat-row{padding:13px 18px;gap:14px;}
    .gfrpt-reason-row{padding:13px 18px;gap:13px;}
    .gfrpt-other-wrap{padding:9px 18px 12px;}
    .gfrpt-footer{padding:14px 18px 36px;}
    .gfrpt-back-btn i{font-size:28px;}
}
@media (max-width:490px){
    .gfrpt-topbar{padding:11px 16px;}
    .gfrpt-topbar-title{font-size:13.5px;}
    .gfrpt-topbar-sub{font-size:10px;}
    .gfrpt-back-btn i{font-size:26px;}
    .gfrpt-close-btn{width:27px;height:27px;font-size:15px;}
    .gfrpt-section-label{font-size:9px;letter-spacing:1.3px;padding:16px 16px 6px;}
    .gfrpt-cat-row{padding:12px 16px;gap:13px;}
    .gfrpt-cat-icon{width:38px;height:38px;font-size:18px;border-radius:11px;}
    .gfrpt-cat-label{font-size:13px;}
    .gfrpt-cat-sub{font-size:10.5px;}
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
    .gfrpt-back-btn i{font-size:24px;}
    .gfrpt-close-btn{width:25px;height:25px;font-size:14px;}
    .gfrpt-section-label{font-size:8.5px;padding:14px 14px 5px;}
    .gfrpt-cat-row{padding:11px 14px;gap:12px;}
    .gfrpt-cat-icon{width:36px;height:36px;font-size:17px;border-radius:10px;}
    .gfrpt-cat-label{font-size:12.5px;}
    .gfrpt-cat-sub{font-size:10px;}
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
    .gfrpt-back-btn i{font-size:22px;}
    .gfrpt-close-btn{width:23px;height:23px;font-size:13px;}
    .gfrpt-section-label{font-size:8px;padding:12px 12px 4px;}
    .gfrpt-cat-row{padding:10px 12px;gap:10px;}
    .gfrpt-cat-icon{width:33px;height:33px;font-size:15px;border-radius:9px;}
    .gfrpt-cat-label{font-size:12px;}
    .gfrpt-cat-sub{font-size:9.5px;}
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

@keyframes gfRptShakeAnim{
    0%,100%{transform:translateX(0);}
    20%{transform:translateX(-6px);}
    40%{transform:translateX(6px);}
    60%{transform:translateX(-4px);}
    80%{transform:translateX(4px);}
}
</style>

<!-- ══ SCREEN 0: Category picker ══ -->
<div id="gfPostReportOverlay">
    <div class="gfrpt-topbar">
        <div class="gfrpt-topbar-center">
            <div class="gfrpt-topbar-title">Report</div>
            <div class="gfrpt-topbar-sub">Reports are private and anonymous</div>
        </div>
        <button class="gfrpt-close-btn" onclick="closeGfPostReportModal()" aria-label="Close">
            <i class="ri-close-line"></i>
        </button>
    </div>

    <div class="gfrpt-section-label">Select what you want to report</div>

    <div class="gfrpt-cat-row" onclick="gfRptSelectCat('content')">
        <div class="gfrpt-cat-icon"><i class="ri-image-2-line"></i></div>
        <div class="gfrpt-cat-info">
            <div class="gfrpt-cat-label">Report Content</div>
            <div class="gfrpt-cat-sub">This post breaks the rules or is inappropriate</div>
        </div>
        <i class="ri-arrow-right-s-line gfrpt-cat-chevron"></i>
    </div>

    <div class="gfrpt-cat-row" onclick="gfRptSelectCat('user')">
        <div class="gfrpt-cat-icon"><i class="ri-user-forbid-line"></i></div>
        <div class="gfrpt-cat-info">
            <div class="gfrpt-cat-label">Report User</div>
            <div class="gfrpt-cat-sub">Fake accounts, spam, harassment &amp; more</div>
        </div>
        <i class="ri-arrow-right-s-line gfrpt-cat-chevron"></i>
    </div>

    <div class="gfrpt-cat-row" onclick="gfRptSelectCat('admins')">
        <div class="gfrpt-cat-icon"><i class="ri-shield-user-line"></i></div>
        <div class="gfrpt-cat-info">
            <div class="gfrpt-cat-label">Report to Group Admins</div>
            <div class="gfrpt-cat-sub">Flag this post for this pod's owner &amp; admins to review</div>
        </div>
        <i class="ri-arrow-right-s-line gfrpt-cat-chevron"></i>
    </div>
</div>


<!-- ══ SCREEN 1: Reason picker ══ -->
<div id="gfPostRptStep1Screen">
    <div class="gfrpt-topbar">
        <button class="gfrpt-back-btn" onclick="gfRptBack()" aria-label="Back">
            <i class="ri-arrow-left-s-line"></i>
        </button>
        <div class="gfrpt-topbar-center">
            <div class="gfrpt-topbar-title" id="gfRptTitle">Select a reason</div>
            <div class="gfrpt-topbar-sub" id="gfRptSubtitle">Your report is anonymous</div>
        </div>
        <div class="gfrpt-topbar-spacer"></div>
    </div>

    <div class="gfrpt-section-label" id="gfRptReasonLabel">Select a reason</div>
    <div id="gfRptReasonList"></div>

    <div class="gfrpt-other-wrap" id="gfRptOtherWrap">
        <textarea class="gfrpt-other-textarea" id="gfRptOtherInput"
                  placeholder="Please describe the issue in detail…"
                  maxlength="500"
                  oninput="gfRptCharCount(this)"
                  onfocus="this.style.borderColor='rgba(255,45,45,0.45)'"
                  onblur="this.style.borderColor='rgba(255,255,255,0.09)'"></textarea>
        <div class="gfrpt-char-count" id="gfRptCharLabel">0 / 500</div>
    </div>

    <div class="gfrpt-footer">
        <button class="gfrpt-submit-btn" id="gfRptSubmitBtn" onclick="gfRptSubmit()">
            Submit Report
        </button>
        <button class="gfrpt-cancel-btn" onclick="closeGfPostReportModal()">Cancel</button>
    </div>
</div>


<!-- ══ SCREEN 2: Success ══ -->
<div id="gfPostRptStep2Screen">
    <div class="gfrpt-topbar">
        <div class="gfrpt-topbar-spacer"></div>
        <div class="gfrpt-topbar-center">
            <div class="gfrpt-topbar-title">Report Submitted</div>
        </div>
        <div class="gfrpt-topbar-spacer"></div>
    </div>

    <div class="gfrpt-success-body">
        <div class="gfrpt-success-icon"><i class="ri-shield-check-line"></i></div>
        <div class="gfrpt-success-title" id="gfRptSuccessTitle">Report Submitted</div>
        <div class="gfrpt-success-desc" id="gfRptSuccessDesc">
            Thank you. Your report has been submitted for review.
        </div>
        <button class="gfrpt-done-btn" onclick="closeGfPostReportModal()">Done</button>
    </div>
</div>

<div id="gfRptToastEl" class="gfrpt-toast"></div>

<script>
(function () {
    var _gState = {
        step: 0, category: null,
        memeId: null, userId: null
    };

    var _contentReasons = [
        { icon:'ri-knife-blood-line',     text:'Graphic Violence or Gore' },
        { icon:'ri-eye-2-line',           text:'Nudity or Sexual Content' },
        { icon:'ri-newspaper-line',       text:'Misinformation or Fake News' },
        { icon:'ri-emotion-unhappy-line', text:'Harassment or Cyberbullying' },
        { icon:'ri-prohibited-line',      text:'Hate Speech or Discrimination' },
        { icon:'ri-spam-2-line',          text:'Spam or Clickbait' },
        { icon:'ri-copyright-line',       text:'Copyright Infringement' },
        { icon:'ri-alert-line',           text:'Illegal or Dangerous Content' },
        { icon:'ri-heart-pulse-line',     text:'Self-Harm or Suicide Content' },
        { icon:'ri-shield-user-line',     text:'Child Safety Concern' },
        { icon:'ri-question-line',        text:'Other' }
    ];
    var _userReasons = [
        { icon:'ri-ghost-line',           text:'Fake Account' },
        { icon:'ri-spy-line',             text:'Impersonation' },
        { icon:'ri-spam-2-line',          text:'Spam Activity' },
        { icon:'ri-emotion-unhappy-line', text:'Harassment or Bullying' },
        { icon:'ri-prohibited-line',      text:'Hate Speech' },
        { icon:'ri-suitcase-line',        text:'Scam or Fraud' },
        { icon:'ri-scales-3-line',        text:'Illegal Activity' },
        { icon:'ri-question-line',        text:'Other' }
    ];
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
        ['gfPostReportOverlay','gfPostRptStep1Screen','gfPostRptStep2Screen'].forEach(_closeScreen);
        document.body.style.overflow = '';
        setTimeout(function () {
            _gState = { step:0, category:null, memeId:null, userId:null };
            var ta = document.getElementById('gfRptOtherInput');
            if (ta) ta.value = '';
            var cl = document.getElementById('gfRptCharLabel');
            if (cl) cl.textContent = '0 / 500';
        }, 280);
    }

    window.openGfPostReportModal = function (memeId, userId) {
        _gState.memeId = memeId;
        _gState.userId = userId;
        _gState.category = null;
        _openScreen('gfPostReportOverlay');
    };

    window.closeGfPostReportModal = function () { _closeAll(); };

    window.gfRptSelectCat = function (cat) {
        _gState.category = cat;
        _buildReasonScreen(cat);
        _closeScreen('gfPostReportOverlay');
        setTimeout(function () { _openScreen('gfPostRptStep1Screen'); }, 80);
    };

    window.gfRptBack = function () {
        _gState.reason = null;
        _closeScreen('gfPostRptStep1Screen');
        setTimeout(function () { _openScreen('gfPostReportOverlay'); }, 80);
    };

    function _buildReasonScreen(cat) {
        var map = { content: _contentReasons, user: _userReasons, admins: _adminReasons };
        var titles = {
            content: 'Why are you reporting this content?',
            user:    'Why are you reporting this user?',
            admins:  'What should the admins know?'
        };
        var subs = {
            content: 'Your report is anonymous',
            user:    'Your report is anonymous',
            admins:  'Sent directly to this pod\'s owner & admins'
        };
        document.getElementById('gfRptTitle').textContent    = titles[cat] || 'Select a reason';
        document.getElementById('gfRptSubtitle').textContent = subs[cat] || 'Your report is anonymous';

        _gState.reason = null;
        document.getElementById('gfRptOtherWrap').style.display = 'none';

        var list = document.getElementById('gfRptReasonList');
        list.innerHTML = '';
        (map[cat] || []).forEach(function (r) {
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

        var screen = document.getElementById('gfPostRptStep1Screen');
        if (screen) screen.scrollTop = 0;
    }

    function _selectReason(reason) {
        _gState.reason = reason;
        document.querySelectorAll('.gfrpt-reason-row').forEach(function (row) {
            var sel = row.dataset.reason === reason;
            row.classList.toggle('selected', sel);
            row.querySelector('.gfrpt-check').textContent = sel ? '✓' : '';
        });
        var ow = document.getElementById('gfRptOtherWrap');
        ow.style.display = reason === 'Other' ? 'block' : 'none';
        if (reason !== 'Other') {
            var ta = document.getElementById('gfRptOtherInput');
            if (ta) ta.value = '';
            var cl = document.getElementById('gfRptCharLabel');
            if (cl) cl.textContent = '0 / 500';
        }
    }

    window.gfRptCharCount = function (el) {
        var cl = document.getElementById('gfRptCharLabel');
        if (cl) cl.textContent = el.value.length + ' / 500';
    };

    window.gfRptSubmit = function () {
        if (!_gState.reason) {
            var list = document.getElementById('gfRptReasonList');
            list.style.animation = 'none'; void list.offsetWidth;
            list.style.animation = 'gfRptShakeAnim 0.35s ease';
            setTimeout(function () { list.style.animation = ''; }, 400);
            _showToast('Please select a reason first');
            return;
        }
        if (_gState.reason === 'Other') {
            var ta = document.getElementById('gfRptOtherInput');
            if (!ta.value.trim()) {
                ta.style.borderColor = 'rgba(255,45,45,0.7)';
                _showToast('Please describe the issue');
                return;
            }
        }

        var btn = document.getElementById('gfRptSubmitBtn');
        btn.disabled = true; btn.textContent = 'Submitting…';

        var desc = (document.getElementById('gfRptOtherInput').value || '').trim();
        var cat  = _gState.category;
        var successTitle = 'Report Submitted';
        var successDesc  = 'Thank you. Your report has been submitted for review.';
        var req;

        if (cat === 'content') {
            var fd1 = new FormData();
            fd1.append('reported_id', _gState.memeId);
            fd1.append('content_type', 'meme');
            fd1.append('reason', _gState.reason);
            if (desc) fd1.append('description', desc);
            successDesc = 'Thank you. This post has been submitted for review.';
            req = fetch('report_content.php', { method: 'POST', body: fd1 });

        } else if (cat === 'user') {
            var fd2 = new FormData();
            fd2.append('type', 'user');
            fd2.append('reported_id', _gState.userId);
            fd2.append('reason', _gState.reason);
            if (desc) fd2.append('description', desc);
            successDesc = 'Thank you. This user has been submitted for review. They will not know who reported them.';
            req = fetch('report_user.php', { method: 'POST', body: fd2 });

        } else { // admins
            var body = new URLSearchParams();
            body.append('report_to_admins', '1');
            body.append('meme_id', _gState.memeId);
            body.append('reported_user_id', _gState.userId);
            body.append('reason', _gState.reason);
            if (desc) body.append('description', desc);
            body.append('csrf_token', CSRF_TOKEN);
            successTitle = 'Sent to Admins';
            successDesc  = 'This post has been flagged for the owner and admins of this pod to review.';
            req = fetch('group.php?id=' + gfGroupId, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            });
        }

        req.then(function (r) { return r.json(); })
           .then(function (d) {
                btn.disabled = false; btn.textContent = 'Submit Report';
                if (d.success || d.ok || d.msg === 'already_reported') {
                    document.getElementById('gfRptSuccessTitle').textContent = successTitle;
                    document.getElementById('gfRptSuccessDesc').textContent  = successDesc;
                    _closeScreen('gfPostRptStep1Screen');
                    setTimeout(function () { _openScreen('gfPostRptStep2Screen'); }, 80);
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
        var t = document.getElementById('gfRptToastEl');
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
        var s2 = document.getElementById('gfPostRptStep2Screen');
        var s1 = document.getElementById('gfPostRptStep1Screen');
        var s0 = document.getElementById('gfPostReportOverlay');
        if (s2 && s2.classList.contains('gfrpt-open')) { _closeAll(); }
        else if (s1 && s1.classList.contains('gfrpt-open')) { window.gfRptBack(); }
        else if (s0 && s0.classList.contains('gfrpt-open')) { _closeAll(); }
    });
})();
</script>

<!-- MODAL -->
<div id="gfModal" onclick="if(event.target===this)closeGFModal()">

    <div id="gfm-card">
        <div id="gfm-inner"></div>
    </div>

    <div id="gfm-arrows">
        <button onclick="gfmLoadIndex(_gfmIndex-1)"
            onmouseover="this.style.background='rgba(255,255,255,0.15)'"
            onmouseout="this.style.background='rgba(255,255,255,0.07)'"
            style="background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.12);border-radius:50%;width:40px;height:40px;display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;transition:background 0.2s;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.7)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"/></svg>
        </button>
        <button onclick="gfmLoadIndex(_gfmIndex+1)"
            onmouseover="this.style.background='rgba(255,255,255,0.15)'"
            onmouseout="this.style.background='rgba(255,255,255,0.07)'"
            style="background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.12);border-radius:50%;width:40px;height:40px;display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;transition:background 0.2s;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.7)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
    </div>

    <div id="gfm-hint">swipe or use arrows ↕</div>
</div>

<script>
/* ════════════════════════════════════════════════
   GROUP FEED JS
════════════════════════════════════════════════ */
function openGroupPicModal() {
    document.getElementById('groupPicModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeGroupPicModal() {
    document.getElementById('groupPicModal').classList.remove('active');
    document.body.style.overflow = '';
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeGroupPicModal();
});

var _gfmIndex  = 0;
var _gfmMemeId = null;
const gfCurrentUserId = <?php echo (int)$current_user; ?>;
const gfGroupId       = <?php echo (int)$group_id; ?>;

/* ── helpers ── */
function _gfmFormatDate(t) {
    if(!t) return '';
    var d = new Date(t.replace(' ','T'));
    if(isNaN(d)) return t;
    var mo = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return d.getDate()+' '+mo[d.getMonth()]+' '+d.getFullYear()+' · '+
           String(d.getHours()).padStart(2,'0')+':'+String(d.getMinutes()).padStart(2,'0');
}

function _gfmLinkify(cap) {
    return cap.replace(/@([a-zA-Z0-9_]+)/g, function(match, u) {
        return '<a href="profile.php?username='+encodeURIComponent(u)+'" onclick="event.stopPropagation()" '+
               'style="color:#f24455;font-weight:600;text-decoration:none;" '+
               'onmouseover="this.style.textDecoration=\'underline\'" '+
               'onmouseout="this.style.textDecoration=\'none\'">'+match+'</a>';
    });
}

/* ── render modal ── */
function _gfmRenderPost(p) {
    _gfmMemeId = p.id;
    var mediaHtml = '';
    if(p.type === 'image' && p.file)
        mediaHtml = '<img src="'+p.file+'" onerror="this.src=\'uploads/me.jpeg\'" style="width:100%;max-height:52vh;object-fit:contain;display:block;">';
    else if(p.type === 'video' && p.file)
        mediaHtml = '<video src="'+p.file+'" autoplay muted loop playsinline style="width:100%;max-height:52vh;object-fit:contain;display:block;"></video>';

    var li = p.liked||false, re = p.reposted||false, fa = p.faved||false;
    var lc = p.likeCount||0, rc = p.repostCount||0, fc = p.favCount||0;
    var isOwnPost = (String(p.userId) === String(gfCurrentUserId));
    var isFollowing = p.following || false;

    /* Track button for modal header — only if not own post */
    var trackBtnHtml = '';
    if(!isOwnPost) {
        trackBtnHtml = '<button class="gfm-track-btn '+(isFollowing?'following':'')+'" '+
            'data-user="'+p.userId+'" '+
            'onclick="gfmToggleTrack(this)" '+
            'style="margin-left:6px;">'+
            (isFollowing ? 'Untrack' : 'Track')+
        '</button>';
    }

    return ''+
    '<div style="display:flex;align-items:center;gap:10px;padding:14px 16px;flex-shrink:0;">'+
        '<img src="'+(p.pic||'uploads/me.jpeg')+'" onerror="this.src=\'uploads/me.jpeg\'"'+
             ' onclick="location.href=\'profile.php?user_id='+p.userId+'\'"'+
             ' style="width:40px;height:40px;border-radius:50%;border:2px solid #FF4C4C;cursor:pointer;object-fit:cover;flex-shrink:0;">'+
        '<div style="flex:1;min-width:0;">'+
            '<div style="display:flex;align-items:center;gap:6px;flex-wrap:nowrap;">'+
                '<div onclick="location.href=\'profile.php?user_id='+p.userId+'\'"'+
                     ' style="color:#FF4C4C;font-weight:700;font-size:14px;cursor:pointer;display:flex;align-items:center;gap:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'+
                    '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" style="flex-shrink:0;">'+
                        '<circle cx="12" cy="12" r="12" fill="#FF0000"/>'+
                        '<path d="M9 12l2 2 4-4" stroke="white" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>'+
                    '</svg>'+
                    '<span>'+(isOwnPost?'You':p.username)+'</span>'+
                '</div>'+
                trackBtnHtml+
            '</div>'+
            '<div style="font-size:11px;color:rgba(255,255,255,0.35);margin-top:3px;display:flex;align-items:center;gap:4px;">'+
                '<i class="ri-time-line" style="font-size:10px;"></i>'+
                '<span>'+_gfmFormatDate(p.time||'')+'</span>'+
            '</div>'+
        '</div>'+
        '<button onclick="closeGFModal()"'+
                ' style="background:rgba(255,255,255,0.06);border:none;border-radius:50%;width:30px;height:30px;'+
                        'display:flex;align-items:center;justify-content:center;cursor:pointer;'+
                        'color:rgba(255,255,255,0.5);font-size:16px;flex-shrink:0;">✕</button>'+
    '</div>'+
    (mediaHtml?'<div style="width:100%;background:#000;flex-shrink:0;">'+mediaHtml+'</div>':'')+
    '<div class="gfm-caption-box" style="padding:10px 16px;color:rgba(255,255,255,0.8);font-size:13px;'+
         'line-height:1.55;border-top:1px solid rgba(255,255,255,0.06);word-break:break-word;'+
         'white-space:pre-wrap;overflow-y:auto;max-height:80px;flex-shrink:0;">'+
        _gfmLinkify(p.caption||'')+
    '</div>'+
    '<div class="gfm-action-bar">'+
        '<button id="gfm-like-btn" class="gfm-action-btn" onclick="gfmAction(\'like\')"'+
                ' style="color:'+(li?'#ff4c4c':'white')+';border-color:'+(li?'rgba(255,76,76,0.4)':'rgba(255,255,255,0.1)')+';">'+
            '<i class="ri-heart-fill" style="font-size:14px;"></i><span id="gfm-like-count">'+lc+'</span>'+
        '</button>'+
        '<button id="gfm-repost-btn" class="gfm-action-btn" onclick="gfmAction(\'repost\')"'+
                ' style="color:'+(re?'#ff4c4c':'white')+';border-color:'+(re?'rgba(255,76,76,0.4)':'rgba(255,255,255,0.1)')+';">'+
            '<i class="ri-repeat-line" style="font-size:14px;"></i><span id="gfm-repost-count">'+rc+'</span>'+
        '</button>'+
        '<button id="gfm-fav-btn" class="gfm-action-btn" onclick="gfmAction(\'fav\')"'+
                ' style="color:'+(fa?'#ff4c4c':'white')+';border-color:'+(fa?'rgba(255,76,76,0.4)':'rgba(255,255,255,0.1)')+';">'+
            '<i class="ri-bookmark-fill" style="font-size:14px;"></i><span id="gfm-fav-count">'+fc+'</span>'+
        '</button>'+
        '<button class="gfm-close-btn" onclick="closeGFModal()">Close</button>'+
    '</div>';
}

function _gfmRenderSlot(index) {
    if(index < 0 || index >= peuraGroupFeed.length) return;
    var inner = document.getElementById('gfm-inner');
    inner.querySelectorAll('video').forEach(function(v){ v.pause(); v.src=''; });
    inner.innerHTML = _gfmRenderPost(peuraGroupFeed[index]);
}

/* ── OPEN — find by meme id ── */
function openGFModal(memeId) {
    var idx = peuraGroupFeed.findIndex(function(p){ return String(p.id) === String(memeId); });
    _gfmIndex = (idx >= 0) ? idx : 0;
    _gfmRenderSlot(_gfmIndex);
    document.getElementById('gfModal').classList.add('gfm-open');
    document.body.style.overflow = 'hidden';
}

/* ── CLOSE ── */
function closeGFModal() {
    document.getElementById('gfm-inner').querySelectorAll('video').forEach(function(v){
        v.pause(); v.src = '';
    });
    document.getElementById('gfModal').classList.remove('gfm-open');
    document.body.style.overflow = '';
    _gfmMemeId = null;
}

/* ── NAVIGATE ── */
function gfmLoadIndex(index) {
    if(index < 0 || index >= peuraGroupFeed.length) return;
    var card = document.getElementById('gfm-card');
    var dir  = index > _gfmIndex ? 1 : -1;
    card.style.transition = 'transform 0.22s ease, opacity 0.22s ease';
    card.style.transform  = 'translateY('+(dir*-30)+'px)';
    card.style.opacity    = '0';
    setTimeout(function(){
        _gfmIndex = index;
        _gfmRenderSlot(_gfmIndex);
        card.style.transition = 'none';
        card.style.transform  = 'translateY('+(dir*30)+'px)';
        card.style.opacity    = '0';
        requestAnimationFrame(function(){
            card.style.transition = 'transform 0.22s ease, opacity 0.22s ease';
            card.style.transform  = 'translateY(0)';
            card.style.opacity    = '1';
        });
    }, 220);
}

/* ── LIKE/REPOST/FAV in modal ── */
function gfmAction(type) {
    if(!_gfmMemeId) return;
    var btnMap    = { like:'gfm-like-btn',   repost:'gfm-repost-btn',   fav:'gfm-fav-btn'    };
    var cntMap    = { like:'gfm-like-count', repost:'gfm-repost-count', fav:'gfm-fav-count'  };
    var cntField  = { like:'likeCount',      repost:'repostCount',      fav:'favCount'        };
    var stateField= { like:'liked',          repost:'reposted',         fav:'faved'           };
    var gridSelMap= { like:'.gf-like',       repost:'.gf-repost',       fav:'.gf-fav'         };

    var gridBtn = document.querySelector(gridSelMap[type]+'[data-id="'+_gfmMemeId+'"]');
    if(gridBtn) gridBtn.click();

    setTimeout(function(){
        var feedItem = peuraGroupFeed.find(function(p){ return String(p.id)===String(_gfmMemeId); });
        var nowActive = feedItem ? feedItem[stateField[type]] : false;
        var mBtn  = document.getElementById(btnMap[type]);
        var mSpan = document.getElementById(cntMap[type]);
        if(mBtn){
            mBtn.style.color       = nowActive ? '#ff4c4c' : 'white';
            mBtn.style.borderColor = nowActive ? 'rgba(255,76,76,0.4)' : 'rgba(255,255,255,0.1)';
        }
        if(feedItem && gridBtn){
            var cntEl = gridBtn.querySelector('span');
            if(cntEl && mSpan) mSpan.textContent = cntEl.textContent;
            feedItem[cntField[type]] = parseInt(cntEl ? cntEl.textContent : '0') || 0;
        }
    }, 350);
}

/* ── TRACK TOGGLE (card grid buttons) ── */
function gfToggleTrack(btn) {
    var userId = btn.dataset.user;
    fetch('group.php?id=' + gfGroupId, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=toggle_follow&user_id=' + userId + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        if(data.status === 'ok') {
            /* update this card's button */
            if(data.following) {
                btn.textContent = 'Untrack';
                btn.classList.add('following');
            } else {
                btn.textContent = 'Track';
                btn.classList.remove('following');
            }
            /* sync peuraGroupFeed state */
            peuraGroupFeed.forEach(function(p){
                if(String(p.userId) === String(userId)) {
                    p.following = data.following;
                }
            });
            /* sync ALL cards for the same user */
            document.querySelectorAll('.gf-track-btn[data-user="'+userId+'"]').forEach(function(b){
                if(data.following) { b.textContent='Untrack'; b.classList.add('following'); }
                else               { b.textContent='Track';   b.classList.remove('following'); }
            });
        }
    })
    .catch(function(err){ console.error('gf track error', err); });
}

/* ── TRACK TOGGLE (inside modal) ── */
function gfmToggleTrack(btn) {
    var userId = btn.dataset.user;
    fetch('group.php?id=' + gfGroupId, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=toggle_follow&user_id=' + userId + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        if(data.status === 'ok') {
            /* update modal button */
            if(data.following) {
                btn.textContent = 'Untrack';
                btn.classList.add('following');
            } else {
                btn.textContent = 'Track';
                btn.classList.remove('following');
            }
            /* sync peuraGroupFeed state */
            peuraGroupFeed.forEach(function(p){
                if(String(p.userId) === String(userId)) {
                    p.following = data.following;
                }
            });
            /* sync ALL grid cards for same user */
            document.querySelectorAll('.gf-track-btn[data-user="'+userId+'"]').forEach(function(b){
                if(data.following) { b.textContent='Untrack'; b.classList.add('following'); }
                else               { b.textContent='Track';   b.classList.remove('following'); }
            });
        }
    })
    .catch(function(err){ console.error('gfm track error', err); });
}

/* ── GRID BUTTONS (like/repost/fav — capture phase) ── */
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.gf-like, .gf-repost, .gf-fav');
    if(!btn) return;
    e.stopPropagation();

    var mid      = btn.dataset.id;
    var isLike   = btn.classList.contains('gf-like');
    var isRepost = btn.classList.contains('gf-repost');
    var isFav    = btn.classList.contains('gf-fav');
    var postKey  = isLike ? 'ajax_like' : (isRepost ? 'ajax_repost' : 'ajax_favorite');
    var countEl  = btn.querySelector('span');

    fetch('group.php?id=' + gfGroupId, {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: postKey + '=1&meme_id=' + mid + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        if(countEl) countEl.textContent = data.count;
        var active = data[isLike ? 'liked' : (isRepost ? 'reposted' : 'favorited')];
        if(active) btn.classList.add('active');
        else       btn.classList.remove('active');
        var stateKey = isLike ? 'liked' : (isRepost ? 'reposted' : 'faved');
        var cntField = isLike ? 'likeCount' : (isRepost ? 'repostCount' : 'favCount');
        var item = peuraGroupFeed.find(function(p){ return String(p.id)===String(mid); });
        if(item){ item[cntField] = data.count; item[stateKey] = active; }
    })
    .catch(function(err){ console.error('gf action error', err); });
}, true);

/* ── WHEEL ── */
document.getElementById('gfModal').addEventListener('wheel', function(e){
    e.preventDefault();
    if(e.deltaY > 0) gfmLoadIndex(_gfmIndex+1);
    else             gfmLoadIndex(_gfmIndex-1);
}, {passive:false});

/* ── TOUCH SWIPE ── */
var _gfmTouchY = 0;
document.getElementById('gfModal').addEventListener('touchstart', function(e){
    _gfmTouchY = e.touches[0].clientY;
}, {passive:true});
document.getElementById('gfModal').addEventListener('touchend', function(e){
    var diff = _gfmTouchY - e.changedTouches[0].clientY;
    if(Math.abs(diff) > 40){
        if(diff > 0) gfmLoadIndex(_gfmIndex+1);
        else         gfmLoadIndex(_gfmIndex-1);
    }
}, {passive:true});

/* ── KEYBOARD ── */
document.addEventListener('keydown', function(e){
    if(document.getElementById('gfModal').classList.contains('gfm-open')){
        if(e.key === 'Escape')    closeGFModal();
        if(e.key === 'ArrowDown') gfmLoadIndex(_gfmIndex+1);
        if(e.key === 'ArrowUp')   gfmLoadIndex(_gfmIndex-1);
    }
});
</script>

<!-- Engagement Modal -->
<div id="gf-eng-modal" onclick="if(event.target===this) closeEngModal()">
    <div onclick="event.stopPropagation()" style="
        background: rgba(18,17,17,0.98);
        backdrop-filter: blur(20px);
        border-radius: 16px;
        width: 90%;
        max-width: 420px;
        max-height: 82vh;
        overflow: hidden;
        border: 1px solid rgba(255,255,255,0.12);
        display: flex;
        flex-direction: column;
        box-shadow: 0 20px 60px rgba(0,0,0,0.7);
    ">
        <!-- Header -->
        <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid rgba(255,255,255,0.1);flex-shrink:0;">
            <span style="color:white;font-weight:600;font-size:0.95rem;">Engagement</span>
            <button onclick="closeEngModal()" style="background:transparent;border:none;color:white;font-size:1.3rem;cursor:pointer;padding:0 8px;">✕</button>
        </div>

        <!-- Search -->
        <div style="padding:12px 16px;border-bottom:1px solid rgba(255,255,255,0.08);flex-shrink:0;">
            <input id="gf-eng-search" type="text" placeholder="Search users..." oninput="filterEngList()" 
            style="width:100%;background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.12);border-radius:10px;padding:9px 14px;color:white;font-size:0.82rem;outline:none;font-family:Poppins,sans-serif;">
        </div>

        <!-- Tabs -->
        <div style="display:flex;gap:10px;padding:12px 16px;border-bottom:1px solid rgba(255,255,255,0.08);flex-shrink:0;">
            <button id="gf-pill-likes" class="eng-pill active-pill" onclick="switchEngTab('likes')">
                <i class="ri-heart-3-fill"></i> Liked
            </button>
            <button id="gf-pill-reposts" class="eng-pill" onclick="switchEngTab('reposts')">
                <i class="ri-repeat-line"></i> Reposted
            </button>
        </div>

        <!-- List -->
        <div id="gf-eng-modal-list" style="overflow-y:auto;flex:1;padding:8px 0;"></div>
    </div>
</div>

<style>
/* CRITICAL FIX FOR MODAL */
#gf-eng-modal {
    display: none;
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    width: 100% !important;
    height: 100% !important;
    background: rgba(0,0,0,0.88) !important;
    z-index: 999999999 !important;
    align-items: center;
    justify-content: center;
    padding: 15px;
    overflow: hidden;
}

.eng-pill {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 7px 16px;
    border-radius: 999px;
    border: 1px solid rgba(255,255,255,0.15);
    background: transparent;
    color: rgba(255,255,255,0.6);
    font-size: 0.78rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    font-family: Poppins, sans-serif;
}

.eng-pill i {
    font-size: 0.85rem;
}

.active-pill {
    background: #f24455;
    border-color: #f24455;
    color: white;
}
</style>

<script>
function showDiscLockToast(msg) {
    var toast = document.getElementById('discLockToast');
    toast.innerHTML = '<i class="ri-lock-line"></i> ' + (msg || 'Join this pod to access this feature');
    toast.classList.add('show');
    clearTimeout(toast._tid);
    toast._tid = setTimeout(function() {
        toast.classList.remove('show');
    }, 2800);
}

// ═══ INVITE MODAL ═══
var _inviteDebounce = null;
var _inviteSent = {};

function openInviteModal() {
    var modal = document.getElementById('inviteModal');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    document.getElementById('inviteSearchInput').focus();
    document.getElementById('inviteSearchInput').value = '';
    document.getElementById('inviteUserList').innerHTML = '<div style="text-align:center;padding:30px;color:rgba(255,255,255,0.3);font-size:13px;font-family:Poppins,sans-serif;"><i class="ri-search-line" style="font-size:28px;display:block;margin-bottom:8px;opacity:0.4;"></i>Search for a user to invite</div>';
}

function closeInviteModal() {
    document.getElementById('inviteModal').style.display = 'none';
    document.body.style.overflow = '';
}

function searchInviteUsers(q) {
    clearTimeout(_inviteDebounce);
    var list = document.getElementById('inviteUserList');
    q = q.trim();
    if (q.length < 1) {
        list.innerHTML = '<div style="text-align:center;padding:30px;color:rgba(255,255,255,0.3);font-size:13px;font-family:Poppins,sans-serif;"><i class="ri-search-line" style="font-size:28px;display:block;margin-bottom:8px;opacity:0.4;"></i>Search for a user to invite</div>';
        return;
    }
    list.innerHTML = '<div style="text-align:center;padding:30px;color:rgba(255,255,255,0.35);font-size:13px;font-family:Poppins,sans-serif;">Searching…</div>';
    _inviteDebounce = setTimeout(function () {
        fetch('invite_search.php?q=' + encodeURIComponent(q) + '&group_id=<?= (int)$group_id ?>')
            .then(function (r) { return r.json(); })
            .then(function (users) {
                if (!Array.isArray(users)) {
                    console.error('invite_search error:', users);
                    list.innerHTML = '<div style="text-align:center;padding:30px;color:#ff7a7a;font-size:13px;font-family:Poppins,sans-serif;">Error: ' + (users.error || 'Unknown') + '</div>';
                    return;
                }
                renderInviteUsers(users);
            })
            .catch(function (err) {
                console.error('fetch error:', err);
                list.innerHTML = '<div style="text-align:center;padding:30px;color:#ff7a7a;font-size:13px;font-family:Poppins,sans-serif;">Could not load users</div>';
            });
    }, 320);
}

function renderInviteUsers(users) {
    var list = document.getElementById('inviteUserList');
    if (!users.length) {
        list.innerHTML = '<div style="text-align:center;padding:30px;color:rgba(255,255,255,0.3);font-size:13px;font-family:Poppins,sans-serif;">No users found</div>';
        return;
    }
    list.innerHTML = '';
    users.forEach(function (u) {
        var pic = u.profile_pic || 'uploads/me.jpeg';
        var alreadySent = !!_inviteSent[u.id];
        list.innerHTML += '<div class="invite-user-row">' +
            '<img src="' + pic + '" onerror="this.src=\'uploads/me.jpeg\'">' +
            '<div style="flex:1;min-width:0;">' +
                '<div class="inv-name">' + escHtml(u.username) + '</div>' +
                (u.nickname ? '<div class="inv-nick">' + escHtml(u.nickname) + '</div>' : '') +
            '</div>' +
            '<button id="inv-btn-' + u.id + '" class="inv-btn' + (alreadySent ? ' sent' : '') + '" ' +
                (alreadySent ? 'disabled' : 'onclick="sendInvite(' + u.id + ',\'' + escHtml(u.username) + '\')"') + '>' +
                (alreadySent ? '<i class="ri-check-line"></i> Sent' : 'Invite') +
            '</button>' +
        '</div>';
    });
}

function sendInvite(userId, username) {
    var btn = document.getElementById('inv-btn-' + userId);
    if (!btn || btn.disabled) return;
    btn.disabled = true;
    btn.textContent = '…';

    fetch('send_invite.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'user_id=' + userId + '&group_id=<?= (int)$group_id ?>'
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (data.ok) {
            _inviteSent[userId] = true;
            btn.className = 'inv-btn sent';
            btn.innerHTML = '<i class="ri-check-line"></i> Sent';
        } else {
            btn.disabled = false;
            btn.textContent = 'Invite';
        }
    })
    .catch(function () {
        btn.disabled = false;
        btn.textContent = 'Invite';
    });
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Close on Escape
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeInviteModal();
});

/* ═══════════════════════════════════════════
   SKELETON JS — paste just before </body>
   ═══════════════════════════════════════════ */
(function () {
  var sk = document.getElementById('skeletonLoader');
  var main = document.querySelector('.main') ||
             document.querySelector('.group-feed') ||
             document.querySelector('.gh');

  function hideSkeleton() {
    if (!sk) return;
    sk.style.transition = 'opacity 0.35s ease';
    sk.style.opacity = '0';
    setTimeout(function () {
      sk.style.display = 'none';
    }, 360);
  }

  if (document.readyState === 'complete') {
    hideSkeleton();
  } else {
    window.addEventListener('load', hideSkeleton);
    // Safety net — never block UI longer than 3 s
    setTimeout(hideSkeleton, 3000);
  }
})();


// Engagement Modal Logic
let currentEngMemeId = null;
let currentEngTab = 'likes';
let currentEngUsers = [];

function openEngModal(memeId, type = 'likes') {
    event.stopPropagation();
    currentEngMemeId = memeId;
    currentEngTab = type;

    const modal = document.getElementById('gf-eng-modal');
    modal.style.display = 'flex';

    document.getElementById('gf-eng-search').value = '';
    document.getElementById('gf-pill-likes').classList.add('active-pill');
    document.getElementById('gf-pill-reposts').classList.remove('active-pill');

    loadEngTab(type);
}

function switchEngTab(type) {
    currentEngTab = type;
    document.getElementById('gf-pill-likes').classList.toggle('active-pill', type === 'likes');
    document.getElementById('gf-pill-reposts').classList.toggle('active-pill', type === 'reposts');
    document.getElementById('gf-eng-search').value = '';
    loadEngTab(type);
}

function loadEngTab(type) {
    const list = document.getElementById('gf-eng-modal-list');
    list.innerHTML = '<div style="color:white;padding:40px;text-align:center;">Loading...</div>';

    fetch(`get_engagement.php?meme_id=${currentEngMemeId}&type=${type}`)
    .then(res => res.json())
    .then(users => {
        currentEngUsers = users;
        renderEngList(users);
    })
    .catch(() => {
        list.innerHTML = '<div style="color:rgba(255,255,255,0.5);padding:40px;text-align:center;">Could not load</div>';
    });
}

function renderEngList(users) {
    const list = document.getElementById('gf-eng-modal-list');
    list.innerHTML = '';

    if (users.length === 0) {
        list.innerHTML = '<div style="color:rgba(255,255,255,0.5);padding:40px;text-align:center;">No activity yet</div>';
        return;
    }

    users.forEach(u => {
        const pic = u.profile_pic || 'uploads/me.jpeg';
        list.innerHTML += `
        <div style="display:flex;align-items:center;gap:12px;padding:12px 16px;border-bottom:1px solid rgba(255,255,255,0.06);">
            <img src="${pic}" onerror="this.src='uploads/me.jpeg'" style="width:42px;height:42px;border-radius:50%;object-fit:cover;border:2px solid #ff2a2a;flex-shrink:0;">
            <span style="color:white;font-weight:500;flex:1;">${u.username}</span>
            <a href="profile.php?user_id=${u.id}" style="padding:6px 16px;border-radius:20px;background:transparent;border:1px solid rgba(255,43,43,0.6);color:#f24455;font-size:0.75rem;font-weight:600;text-decoration:none;white-space:nowrap;">View Profile</a>
        </div>`;
    });
}

function filterEngList() {
    const q = document.getElementById('gf-eng-search').value.toLowerCase().trim();
    const filtered = currentEngUsers.filter(u => u.username.toLowerCase().includes(q));
    renderEngList(filtered);
}

function closeEngModal() {
    document.getElementById('gf-eng-modal').style.display = 'none';
}
</script>


<!-- ── SCREEN TIME BREAK OVERLAY ── -->
<div id="breakOverlay">
    <div class="break-title">Time to Stretch<br>and Take a Break</div>
    <div class="break-subtitle">You've been scrolling for a while 🕐</div>
    <div class="break-angel">🪽</div>
    <div class="break-code-label">
        Type the code below to return
        <strong>1 2 1 2</strong>
    </div>
    <input class="break-input" id="breakCodeInput" type="tel" maxlength="4" placeholder="- - - -" oninput="checkBreakCode()">
    <button class="break-return-btn" onclick="tryBreakReturn()">Return to Feed</button>
    <div class="break-motivate">Stay positive and keep moving forward — you got this 💪</div>
</div>


<script>
// ─── AGE GATE ──────────────────────────────────────────────
var AGE_GATE_CONFIRMED = sessionStorage.getItem('ageConfirmed_<?php echo (int)$group_id; ?>');
var SHOW_AGE_GATE = <?php echo $show_age_gate ? 'true' : 'false'; ?>;

(function(){
    if (SHOW_AGE_GATE && !AGE_GATE_CONFIRMED) {
        document.getElementById('ageGateOverlay').classList.add('open');
        document.body.style.overflow = 'hidden';
    }
})();

function confirmAge() {
    sessionStorage.setItem('ageConfirmed_<?php echo (int)$group_id; ?>', '1');
    var overlay = document.getElementById('ageGateOverlay');
    if (overlay) {
        overlay.classList.remove('open');
        document.body.style.overflow = '';
    }
}

// ─── KEBAB MENU ────────────────────────────────────────────
function toggleKebab(e) {
    e.stopPropagation();
    var dd = document.getElementById('kebabDropdown');
    dd.classList.toggle('open');
}

document.addEventListener('click', function(e) {
    var wrap = document.getElementById('kebabWrap');
    if (wrap && !wrap.contains(e.target)) {
        var dd = document.getElementById('kebabDropdown');
        if (dd) dd.classList.remove('open');
    }
});

// ─── SCREEN TIME BREAK ─────────────────────────────────────
(function(){
    const LIMIT_MS  = 60 * 60 * 1000;
    const STORE_KEY = 'peura_session_start';
    const CODE      = '1212';

    if(!sessionStorage.getItem(STORE_KEY)){
        sessionStorage.setItem(STORE_KEY, Date.now());
    }

    function showBreak(){
        document.getElementById('breakOverlay').classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function hideBreak(){
        document.getElementById('breakOverlay').classList.remove('active');
        document.body.style.overflow = '';
        sessionStorage.setItem(STORE_KEY, Date.now());
    }

    function checkTime(){
        var start = parseInt(sessionStorage.getItem(STORE_KEY));
        if(Date.now() - start >= LIMIT_MS){
            showBreak();
        }
    }

    setInterval(checkTime, 30000);
    window.addEventListener('load', checkTime);

    window.checkBreakCode = function(){
        var val = document.getElementById('breakCodeInput').value;
        var input = document.getElementById('breakCodeInput');
        if(val.length === 4){
            if(val === CODE){
                input.style.borderColor = '#44ff88';
                setTimeout(hideBreak, 300);
                input.value = '';
            } else {
                input.classList.remove('shake');
                void input.offsetWidth;
                input.classList.add('shake');
                input.value = '';
                setTimeout(function(){ input.classList.remove('shake'); }, 400);
            }
        }
    };

    window.tryBreakReturn = function(){
        var val = document.getElementById('breakCodeInput').value;
        var input = document.getElementById('breakCodeInput');
        if(val === CODE){
            input.style.borderColor = '#44ff88';
            setTimeout(hideBreak, 300);
            input.value = '';
        } else {
            input.classList.remove('shake');
            void input.offsetWidth;
            input.classList.add('shake');
            input.value = '';
            setTimeout(function(){ input.classList.remove('shake'); }, 400);
        }
    };
})();

// ─── PARTICLES ─────────────────────────────────────────────
var container = document.getElementById('particles');
for (var i = 0; i < 70; i++) {
    var p = document.createElement('div');
    p.className = 'particle';
    var size = Math.random() * 2 + 3;
    p.style.width = size + 'px';
    p.style.height = size + 'px';
    p.style.left = Math.random() * 100 + 'vw';
    p.style.top = Math.random() * 100 + 'vh';
    p.style.setProperty('--x-move', (Math.random() * 200 - 100) + 'px');
    p.style.setProperty('--y-move', (Math.random() * -300 - 100) + 'px');
    p.style.animationDuration = (Math.random() * 15 + 10) + 's';
    p.style.opacity = Math.random() * 0.5 + 0.2;
    container.appendChild(p);
}


// ─── HASHTAG CHECK ─────────────────────────────────────────
function checkHashtag(input) {
    var hint = document.getElementById('hashtagHint');
    if (input.value.includes('#')) {
        hint.textContent = '✓ Hashtag detected — good to go!';
        hint.className = 'hashtag-hint valid';
    } else {
        hint.textContent = 'Must include at least one #hashtag to post';
        hint.className = 'hashtag-hint invalid';
    }
}

function showFileName(input) {
    var display = document.getElementById('fileNameDisplay');
    display.textContent = input.files[0] ? '📎 ' + input.files[0].name : '';
}

// ─── AJAX ENGAGEMENT ───────────────────────────────────────
var BASE = 'group.php?id=<?php echo $group_id; ?>';

document.querySelectorAll('.likeBtn').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        fetch(BASE, {
            method:'POST',
            headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:'ajax_like=1&meme_id='+btn.dataset.id+'&csrf_token='+encodeURIComponent(CSRF_TOKEN)
        }).then(function(r){ return r.json(); }).then(function(data) {
            btn.querySelector('.like-count').textContent = data.count;
            if (data.liked) {
                btn.classList.add('liked');
                btn.querySelector('i').className = 'ri-heart-fill';
            } else {
                btn.classList.remove('liked');
                btn.querySelector('i').className = 'ri-heart-line';
            }
        });
    });
});

document.querySelectorAll('.repostBtn').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        fetch(BASE, {
            method:'POST',
            headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:'ajax_repost=1&meme_id='+btn.dataset.id+'&csrf_token='+encodeURIComponent(CSRF_TOKEN)
        }).then(function(r){ return r.json(); }).then(function(data) {
            btn.querySelector('.repost-count').textContent = data.count;
            data.reposted ? btn.classList.add('reposted') : btn.classList.remove('reposted');
        });
    });
});

document.querySelectorAll('.favBtn').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        fetch(BASE, {
            method:'POST',
            headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:'ajax_favorite=1&meme_id='+btn.dataset.id+'&csrf_token='+encodeURIComponent(CSRF_TOKEN)
        }).then(function(r){ return r.json(); }).then(function(data) {
            btn.querySelector('.fav-count').textContent = data.count;
            if (data.favorited) {
                btn.classList.add('saved');
                btn.querySelector('i').className = 'ri-bookmark-fill';
            } else {
                btn.classList.remove('saved');
                btn.querySelector('i').className = 'ri-bookmark-line';
            }
        });
    });
});

// ─── MODAL ─────────────────────────────────────────────────
var _ccmUserId = 0;

function openCCM(type, src, userId, username, profile, caption) {
    _ccmUserId = userId;

    document.getElementById('ccm-pic').src = profile || 'uploads/me.jpeg';
    document.getElementById('ccm-username-text').textContent = '' + username;

    var mediaEl = document.getElementById('ccm-media');
    mediaEl.innerHTML = '';
    if (type === 'image') {
        var img = document.createElement('img');
        img.src = src;
        mediaEl.appendChild(img);
    } else if (type === 'video') {
        var vid = document.createElement('video');
        vid.src = src; vid.controls = true; vid.autoplay = true; vid.loop = true; vid.muted = false;
        mediaEl.appendChild(vid);
    }

    var capEl = document.getElementById('ccm-caption');
    capEl.innerHTML = caption
        ? caption.replace(/(#\w+)/g, '<span class="hashtag">$1</span>')
        : '';

    document.getElementById('cardCaptionModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeCCM() {
    document.getElementById('cardCaptionModal').classList.remove('open');
    document.body.style.overflow = '';
    var vid = document.querySelector('#ccm-media video');
    if (vid) vid.pause();
}

function goCCMProfile() {
    location.href = 'profile.php?user_id=' + _ccmUserId;
}

// ─── MISC ──────────────────────────────────────────────────
function reportGroup() {
    alert("Group reported. Our moderators will review it.");
}

document.querySelectorAll('video.post-media').forEach(function(v){ v.play().catch(function(){}); });
</script>
</body>
</html>