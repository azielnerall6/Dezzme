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

/* Set when arriving from a "View Zeem ↗" link on discussions.php
   (group.php?id=X&meme=Y) — used later on the page to auto-open
   the reels-style post modal on that exact post. */
$auto_open_meme_id = (int)($_GET['meme'] ?? 0);

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
            || isset($_POST['ajax_get_connected_meme'])
            || isset($_POST['dismiss_suggested_group'])
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

/* ══════════════════════════════════════════════════════════════════
   CATEGORY
   Pods created before the category feature shipped have NULL/empty
   category in the DB. Rather than a migration, we just fall back to
   a friendly default here wherever the page needs to display it.
   The list here should match the <select> options in community.php's
   create-pod form, so the edit dropdown below stays consistent with it.
   ══════════════════════════════════════════════════════════════════ */
$pod_category_default = 'Uncategorized';
$pod_category = !empty($group['category']) ? $group['category'] : $pod_category_default;

$pod_category_options = [
    'Memes & Humor',
    'Internet Culture',
    'Gaming',
    'Technology',
    'Science',
    'Space',
    'Education & Careers',
    'Dezzme Official',
    'History',
    'Books & Writing',
    'Art & Design',
    'Photography',
    'Movies & TV',
    'Anime & Manga',
    'Comics & Cartoons',
    'Music',
    'Podcasts',
    'Entertainment',
    'Fandom',
    'Pop Culture',
    'News',
    'Politics',
    'Business',
    'Finance',
    'Lifestyle',
    'Fashion',
    'Beauty & Skincare',
    'Health & Fitness',
    'Mental Wellness',
    'Relationships & Family',
    'Travel',
    'Food & Drinks',
    'Sports',
    'Esports',
    'Pets',
    'Animals',
    'Nature & Wildlife',
    'Home & DIY',
    'Architecture',
    'Collectibles',
    'Automotive',
    'Luxury',
    'Shopping',
    'Language Learning',
    'Culture',
    'Religion & Spirituality',
    'Philosophy',
    'LGBTQ+',
    'Identity & Communities',
    'Creators',
    'Streaming',
    'Marketplace',
    'Support',
    'Discussions & Q&A',
    'Events & Challenges',
    'Creative Edits',
    'Media Sharing',
    'Wallpapers',
    'Aesthetics',
    'Brainrot',
    'Shitposting',
    'Trending',
    'Throwbacks & Nostalgia',
    'Other'
];

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

// ─── DISMISS A SUGGESTED POD (Suggested for you, in pod info modal) ───────────
if (isset($_POST['dismiss_suggested_group'])) {
    header('Content-Type: application/json');
    $dismiss_gid = (int)($_POST['dismiss_group_id'] ?? 0);
    if ($dismiss_gid > 0) {
        $stmt = $conn->prepare("INSERT IGNORE INTO group_suggestion_dismissed (user_id, dismissed_group_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $current_user, $dismiss_gid);
        $stmt->execute();
        $stmt->close();
    }
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

// ─── CHANGE / SET GROUP CATEGORY (owner only) ─────────────────────────────────
// Lets owners of pods created before the category feature existed
// (or anyone who wants to re-categorize) set groups.category directly.
if (isset($_POST['change_group_category']) && $is_owner) {
    $new_category = trim((string)($_POST['new_category'] ?? ''));

    // Only accept values from the known category list, to keep data clean
    // and consistent with the dropdown used on the create-pod form.
    if ($new_category !== '' && in_array($new_category, $pod_category_options, true)) {
        $old_category = $group['category'] ?? '';

        if ($new_category !== $old_category) {
            $stmt = $conn->prepare("UPDATE groups SET category=? WHERE id=?");
            $stmt->bind_param("si", $new_category, $group_id);
            $stmt->execute();
            $stmt->close();

            $log_detail = ($old_category !== '' ? $old_category : 'Uncategorized') . ' → ' . $new_category;
            log_group_activity($conn, $group_id, $current_user, 'change_category', null, $log_detail);
        }
    }

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

// ─── FETCH CONNECTED ZEEM (for the gf modal's Connected Zeem overlay) ────────
// Called by gfdOpenConnectedZeem() in the gf modal JS. Takes the TARGET
// meme's own id (the post being linked to, not the post you're viewing)
// and returns its full post data in the same shape as a peuraGroupFeed
// item, so _gfdRenderPost() can render it as a new sliding layer.
if (isset($_POST['ajax_get_connected_meme'])) {
    header('Content-Type: application/json');

    $conn_mid = (int)($_POST['meme_id'] ?? 0);
    if ($conn_mid <= 0) {
        echo json_encode(['ok' => false, 'error' => 'invalid_id']);
        exit();
    }

    $stmt = $conn->prepare("
        SELECT m.id AS meme_id, m.file_name, m.caption, m.type,
               m.is_partnership, m.partner_brand,
               u.id AS user_id, u.username, u.nickname, u.profile_pic,
               m.created_at AS post_created,
               (SELECT COUNT(*) FROM likes l WHERE l.meme_id=m.id) AS like_count,
               (SELECT COUNT(*) FROM reposts r WHERE r.meme_id=m.id) AS repost_count,
               (SELECT COUNT(*) FROM favorites f WHERE f.meme_id=m.id) AS favorites_count,
               (SELECT COUNT(*) FROM shares s WHERE s.meme_id=m.id) AS share_count,
               (SELECT COUNT(*) FROM likes l WHERE l.meme_id=m.id AND l.user_id=?) AS is_liked,
               (SELECT COUNT(*) FROM reposts r WHERE r.meme_id=m.id AND r.user_id=?) AS is_reposted,
               (SELECT COUNT(*) FROM favorites f WHERE f.meme_id=m.id AND f.user_id=?) AS is_favorited,
               (SELECT COUNT(*) FROM followers f WHERE f.follower_id=? AND f.following_id=u.id) AS poster_is_followed
        FROM memes m
        JOIN users u ON u.id = m.uploaded_by
        WHERE m.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("iiiii", $current_user, $current_user, $current_user, $current_user, $conn_mid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        echo json_encode(['ok' => false, 'error' => 'not_found']);
        exit();
    }

    // ── never leak a blocked user's post through this endpoint ──
    $poster_id = (int)$row['user_id'];
    $blk = $conn->prepare("SELECT 1 FROM blocks WHERE (blocker_id=? AND blocked_id=?) OR (blocker_id=? AND blocked_id=?)");
    $blk->bind_param("iiii", $current_user, $poster_id, $poster_id, $current_user);
    $blk->execute();
    $is_blocked = $blk->get_result()->num_rows > 0;
    $blk->close();
    if ($is_blocked) {
        echo json_encode(['ok' => false, 'error' => 'not_found']);
        exit();
    }

    // ── build this post's full media array (cover + meme_media extras) ──
    $primary_file = !empty($row['file_name']) ? 'uploads/' . $row['file_name'] : '';
    $primary_type = $row['type'] ?? 'image';
    $media_items  = [];
    if (!empty($primary_file)) {
        $media_items[] = ['file' => $primary_file, 'type' => $primary_type];
    }
    $mm = $conn->prepare("SELECT file_name, type FROM meme_media WHERE meme_id=? ORDER BY position ASC");
    $mm->bind_param("i", $conn_mid);
    $mm->execute();
    $mm_res = $mm->get_result();
    while ($mrow = $mm_res->fetch_assoc()) {
        $media_items[] = ['file' => 'uploads/' . $mrow['file_name'], 'type' => $mrow['type']];
    }
    $mm->close();

    // ── attached soundtrack, if any ──
    $audio_info = null;
    $au = $conn->prepare("
        SELECT a.id AS audio_id, a.title, a.artist, a.file_name, ma.is_original,
               ma.music_volume, ma.video_audio_volume
        FROM meme_audio ma JOIN audios a ON a.id = ma.audio_id
        WHERE ma.meme_id=? LIMIT 1
    ");
    $au->bind_param("i", $conn_mid);
    $au->execute();
    $au_res = $au->get_result();
    if ($au_res && $au_res->num_rows) $audio_info = $au_res->fetch_assoc();
    $au->close();

    // ── social proof — people the viewer follows who liked/reposted this ──
    $social_proof = [];
    if ($current_user) {
        $sp = $conn->prepare("
            SELECT u2.id, u2.username, u2.profile_pic, 'like' AS action_type
            FROM likes l JOIN users u2 ON u2.id=l.user_id
            JOIN followers f ON f.following_id=u2.id
            WHERE l.meme_id=? AND f.follower_id=? AND u2.id!=?
            UNION
            SELECT u2.id, u2.username, u2.profile_pic, 'repost' AS action_type
            FROM reposts r JOIN users u2 ON u2.id=r.user_id
            JOIN followers f ON f.following_id=u2.id
            WHERE r.meme_id=? AND f.follower_id=? AND u2.id!=?
        ");
        $sp->bind_param("iiiiii", $conn_mid, $current_user, $current_user, $conn_mid, $current_user, $current_user);
        $sp->execute();
        $sp_res = $sp->get_result();
        $sp_raw = [];
        while ($sr = $sp_res->fetch_assoc()) $sp_raw[] = $sr;
        usort($sp_raw, function ($a, $b) {
            $aRank = $a['action_type'] === 'repost' ? 0 : 1;
            $bRank = $b['action_type'] === 'repost' ? 0 : 1;
            return $aRank <=> $bRank;
        });
        $social_proof = array_slice($sp_raw, 0, 4);
        $sp->close();
    }

    $post_data = [
        'id'               => (int)$row['meme_id'],
        'groupPostId'      => null,
        'file'             => $primary_file,
        'type'             => $primary_type,
        'media'            => $media_items,
        'pic'              => !empty($row['profile_pic']) ? $row['profile_pic'] : 'uploads/me.jpeg',
        'userId'           => (int)$row['user_id'],
        'username'         => $row['username'],
        'nickname'         => $row['nickname'] ?? '',
        'caption'          => $row['caption'],
        'time'             => $row['post_created'],
        'likeCount'        => (int)$row['like_count'],
        'repostCount'      => (int)$row['repost_count'],
        'favCount'         => (int)$row['favorites_count'],
        'shareCount'       => (int)$row['share_count'],
        'liked'            => (bool)$row['is_liked'],
        'reposted'         => (bool)$row['is_reposted'],
        'faved'            => (bool)$row['is_favorited'],
        'following'        => (bool)$row['poster_is_followed'],
        'isPartnership'    => !empty($row['is_partnership']),
        'partnerBrand'     => $row['partner_brand'] ?: null,
        'audioId'          => $audio_info ? (int)$audio_info['audio_id'] : null,
        'audioTitle'       => $audio_info ? $audio_info['title'] : null,
        'audioArtist'      => $audio_info && !empty($audio_info['artist']) ? $audio_info['artist'] : null,
        'audioFile'        => $audio_info ? 'uploads/' . $audio_info['file_name'] : null,
        'audioIsOriginal'  => $audio_info ? (bool)$audio_info['is_original'] : false,
        'musicVolume'      => $audio_info ? (int)$audio_info['music_volume'] : 80,
        'videoAudioVolume' => $audio_info ? (int)$audio_info['video_audio_volume'] : 40,
        'socialProof'      => array_map(function ($sp) {
            return [
                'id'          => (int)$sp['id'],
                'username'    => $sp['username'],
                'profile_pic' => $sp['profile_pic'],
                'action_type' => $sp['action_type'],
            ];
        }, $social_proof),
        // Never chain a second hop — a connected Zeem's own Connect
        // button is intentionally hidden, so tapping it can't lead to
        // a nested "View · Connected Zeem" chain inside the modal.
        'connectedMemeId'  => null,
        'connectedLabel'   => null,
    ];

    echo json_encode(['ok' => true, 'post' => $post_data]);
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
        $allowed_ext     = ['jpg','jpeg','png','gif','webp','mp4','webm','mov'];
        $uploaded_items  = []; // [ ['file'=>.., 'type'=>..], ... ] up to 20

        if (!empty($_FILES['post_file']['name']) && is_array($_FILES['post_file']['name'])) {
            $file_count = count($_FILES['post_file']['name']);
            if ($file_count > 20) {
                $upload_error = "You can upload up to 20 files per post.";
            } else {
                for ($i = 0; $i < $file_count; $i++) {
                    if (empty($_FILES['post_file']['name'][$i])) continue;
                    if ($_FILES['post_file']['error'][$i] !== UPLOAD_ERR_OK) continue;

                    $ext = strtolower(pathinfo($_FILES['post_file']['name'][$i], PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowed_ext, true)) {
                        $upload_error = "Unsupported file type: ." . htmlspecialchars($ext);
                        break;
                    }

                    $new_name    = time() . '_' . uniqid() . '_' . $i . '.' . $ext;
                    $upload_path = "uploads/" . $new_name;

                    if (!move_uploaded_file($_FILES['post_file']['tmp_name'][$i], $upload_path)) {
                        $upload_error = "File upload failed. Check that the uploads/ folder exists and is writable.";
                        break;
                    }

                    $item_type = in_array($ext, ['mp4','webm','mov'], true) ? 'video' : 'image';
                    $uploaded_items[] = ['file' => $new_name, 'type' => $item_type];
                }
            }
        }

        if (empty($upload_error)) {
            $is_adult_post = !empty($group['is_adult']) ? 1 : 0;

            // First uploaded item becomes the primary meme row (unchanged behavior
            // for text-only posts: file_name='' type='text').
            $primary_file = !empty($uploaded_items) ? $uploaded_items[0]['file'] : '';
            $primary_type = !empty($uploaded_items) ? $uploaded_items[0]['type'] : 'text';

            $is_partnership = (isset($_POST['is_partnership']) && $_POST['is_partnership'] == '1') ? 1 : 0;
            $partner_brand  = $is_partnership ? trim($_POST['partner_brand'] ?? '') : '';

            // ─── ZEEM CONNECT — optional link to one of the current user's
            // OWN existing Zeems (Part 1/Part 2, reactions, follow-ups, etc).
            // Stored purely as a reference (connected_meme_id) on this NEW
            // post — re-validated here against uploaded_by so a tampered
            // form value can never connect to someone else's post. The
            // connected Zeem's own row, media, and caption are left
            // completely untouched; nothing is merged or copied into it.
            $connected_meme_id = null;
            $connected_label   = null;
            if (!empty($_POST['connected_meme_id'])) {
                $candidate_id = (int)$_POST['connected_meme_id'];
                if ($candidate_id > 0) {
                    $cchk = $conn->prepare("SELECT id FROM memes WHERE id=? AND uploaded_by=? LIMIT 1");
                    $cchk->bind_param("ii", $candidate_id, $current_user);
                    $cchk->execute();
                    $cres = $cchk->get_result();
                    if ($cres && $cres->num_rows) {
                        $connected_meme_id = $candidate_id;
                        // Custom button label — optional. What the viewer sees is
                        // "▶ View · {label}". If left blank, the feed template
                        // should fall back to "▶ View · Connected Zeem". A label
                        // is only ever stored alongside a valid connected_meme_id.
                        $raw_label = trim($_POST['connected_label'] ?? '');
                        if ($raw_label !== '') {
                            $connected_label = mb_substr($raw_label, 0, 40);
                        }
                    }
                    $cchk->close();
                }
            }

            $stmt = $conn->prepare("INSERT INTO memes (uploaded_by, file_name, caption, type, is_adult, is_partnership, partner_brand, connected_meme_id, connected_label) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isssiisis", $current_user, $primary_file, $caption, $primary_type, $is_adult_post, $is_partnership, $partner_brand, $connected_meme_id, $connected_label);
            $stmt->execute();
            $meme_id = $conn->insert_id;
            $stmt->close();

            // ─── ATTACHED AUDIO (optional soundtrack) ─────────────────────────
            $audio_mode    = (string)($_POST['audio_mode'] ?? 'none');
            $music_volume  = (int)($_POST['music_volume'] ?? 80);
            $video_volume  = (int)($_POST['video_audio_volume'] ?? 40);
            $music_volume  = max(0, min(100, $music_volume));
            $video_volume  = max(0, min(100, $video_volume));

            $final_audio_id = null;

            if ($audio_mode === 'library') {
                $lib_id = (int)($_POST['selected_audio_id'] ?? 0);
                if ($lib_id > 0) {
                    // confirm it actually exists / is public
                    $check = $conn->prepare("SELECT id FROM audios WHERE id=? AND visibility='public'");
                    $check->bind_param("i", $lib_id);
                    $check->execute();
                    if ($check->get_result()->num_rows > 0) {
                        $final_audio_id = $lib_id;
                    }
                    $check->close();
                }
            } elseif ($audio_mode === 'upload') {
                if (!empty($_FILES['audio_file']['name']) && $_FILES['audio_file']['error'] === UPLOAD_ERR_OK) {
                    $allowed_audio_ext = ['mp3','wav','m4a','ogg','aac'];
                    $a_ext = strtolower(pathinfo($_FILES['audio_file']['name'], PATHINFO_EXTENSION));
                    if (in_array($a_ext, $allowed_audio_ext, true)) {
                        $a_new_name = time() . '_' . uniqid() . '_audio.' . $a_ext;
                        $a_path     = "uploads/" . $a_new_name;
                        if (move_uploaded_file($_FILES['audio_file']['tmp_name'], $a_path)) {
                            $a_title = pathinfo($_FILES['audio_file']['name'], PATHINFO_FILENAME);
                            $stmt = $conn->prepare("INSERT INTO audios (uploaded_by, title, file_name, visibility) VALUES (?, ?, ?, 'private')");
                            $stmt->bind_param("iss", $current_user, $a_title, $a_new_name);
                            $stmt->execute();
                            $final_audio_id = $conn->insert_id;
                            $stmt->close();
                        }
                    }
                }
            }
            // 'original' and 'none' both leave $final_audio_id as null —
            // 'original' just means "use the video's own audio", no attached track row needed.

            if ($final_audio_id) {
                $stmt = $conn->prepare("INSERT INTO meme_audio (meme_id, audio_id, music_volume, video_audio_volume) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("iiii", $meme_id, $final_audio_id, $music_volume, $video_volume);
                $stmt->execute();
                $stmt->close();
            }

            // Items 2–20 go into meme_media as extra gallery slots (position 1..19)
            if (count($uploaded_items) > 1) {
                $stmt = $conn->prepare("INSERT INTO meme_media (meme_id, file_name, type, position) VALUES (?, ?, ?, ?)");
                for ($pos = 1; $pos < count($uploaded_items); $pos++) {
                    $mf = $uploaded_items[$pos]['file'];
                    $mt = $uploaded_items[$pos]['type'];
                    $stmt->bind_param("issi", $meme_id, $mf, $mt, $pos);
                    $stmt->execute();
                }
                $stmt->close();
            }

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
// FIX: added u.nickname to the SELECT — the gf modal needs this to render
// the nickname line the same way the discover (search.php) modal does.
$post_sql = "
SELECT gp.id AS group_post_id, gp.created_at AS post_created,
       u.id AS user_id, u.username, u.nickname, u.profile_pic,
       m.id AS meme_id, m.file_name, m.caption, m.type,
       m.is_partnership, m.partner_brand,
       m.connected_meme_id, m.connected_label,
       (SELECT COUNT(*) FROM likes l     WHERE l.meme_id=m.id" . (!empty($viewer_excluded_sql) ? " AND l.user_id NOT IN ($viewer_excluded_sql)" : "") . ") AS like_count,
       (SELECT COUNT(*) FROM reposts r   WHERE r.meme_id=m.id" . (!empty($viewer_excluded_sql) ? " AND r.user_id NOT IN ($viewer_excluded_sql)" : "") . ") AS repost_count,
       (SELECT COUNT(*) FROM favorites f WHERE f.meme_id=m.id" . (!empty($viewer_excluded_sql) ? " AND f.user_id NOT IN ($viewer_excluded_sql)" : "") . ") AS favorites_count,
       (SELECT COUNT(*) FROM downloads d WHERE d.meme_id=m.id) AS download_count,
       (SELECT COUNT(*) FROM likes l     WHERE l.meme_id=m.id AND l.user_id=?) AS is_liked,
       (SELECT COUNT(*) FROM reposts r   WHERE r.meme_id=m.id AND r.user_id=?) AS is_reposted,
       (SELECT COUNT(*) FROM favorites f WHERE f.meme_id=m.id AND f.user_id=?) AS is_favorited,
       (SELECT COUNT(*) FROM shares s WHERE s.meme_id=m.id) AS share_count,
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

/* ─── GROUP FEED SOCIAL PROOF MAP ──────────────────────────────────────────
   NEW: mirrors the $social_proof_map built in search.php's discover feed —
   for each meme posted in THIS group, collect up to 4 likes/reposts made
   by people the viewer follows (excluding the viewer themself). Keyed by
   meme_id so the HTML/JS section further down the page can do
   $group_social_proof_map[$mid] ?? [] exactly like search.php does, and
   feed it into peuraGroupFeed.push({ ..., socialProof: [...] }).

   FIX: reposts now take priority over likes when the strip gets capped
   at 4. Previously the LIMIT-as-you-read approach meant the cap was
   applied purely based on which rows MySQL's UNION ALL happened to
   return first (likes first, since they're listed first in the query)
   — reposts could get crowded out entirely even when someone reposted.
   Now every row is collected first, reposts are sorted to the front,
   THEN the top 4 are taken.
   ───────────────────────────────────────────────────────────────────── */
$group_social_proof_map = [];
if ($current_user) {
    $sp_sql = "
        SELECT gp2.meme_id, u2.id, u2.username, u2.profile_pic, 'like' AS action_type
        FROM likes l
        JOIN users u2 ON u2.id = l.user_id
        JOIN followers f ON f.following_id = u2.id
        JOIN group_posts gp2 ON gp2.meme_id = l.meme_id
        WHERE gp2.group_id = ? AND f.follower_id = ? AND u2.id != ?

        UNION ALL

        SELECT gp2.meme_id, u2.id, u2.username, u2.profile_pic, 'repost' AS action_type
        FROM reposts r
        JOIN users u2 ON u2.id = r.user_id
        JOIN followers f ON f.following_id = u2.id
        JOIN group_posts gp2 ON gp2.meme_id = r.meme_id
        WHERE gp2.group_id = ? AND f.follower_id = ? AND u2.id != ?
    ";
    $stmt = $conn->prepare($sp_sql);
    $stmt->bind_param("iiiiii", $group_id, $current_user, $current_user, $group_id, $current_user, $current_user);
    $stmt->execute();
    $sp_res = $stmt->get_result();

    // collect ALL rows per meme first — no 4-cap here yet
    $_group_sp_raw = [];
    while ($sp = $sp_res->fetch_assoc()) {
        $mid = (int)$sp['meme_id'];
        if (!isset($_group_sp_raw[$mid])) $_group_sp_raw[$mid] = [];
        $_group_sp_raw[$mid][] = $sp;
    }
    $stmt->close();

    // sort reposts to the front, THEN take the top 4 —
    // reposts fill the strip first, likes only pad leftover slots
    foreach ($_group_sp_raw as $mid => $rows) {
        usort($rows, function($a, $b) {
            $aRank = $a['action_type'] === 'repost' ? 0 : 1;
            $bRank = $b['action_type'] === 'repost' ? 0 : 1;
            return $aRank <=> $bRank;
        });
        $group_social_proof_map[$mid] = array_slice($rows, 0, 4);
    }
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
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<script>
const CSRF_TOKEN = <?php echo json_encode($csrf_token); ?>;

var groupTitleForModal = <?php echo json_encode(htmlspecialchars($group['title'])); ?>;
var groupPicForModal   = <?php echo json_encode(htmlspecialchars($img_src)); ?>;

/* Required globally by every gf* function (gfdAction, gfShareMeme,
   gfDeletePost, gfBlockUser, gfShowMemeQR, _gfdRenderPost, etc.) —
   must exist before any click handler in the group feed runs, or
   openGFModal throws a ReferenceError on gfCurrentUserId and the
   modal never opens. */
var gfGroupId       = <?php echo (int)$group_id; ?>;
var gfCurrentUserId = <?php echo (int)$current_user; ?>;

/* Set when arriving from a "View Zeem ↗" link on discussions.php
   (group.php?id=X&meme=Y) — used at the bottom of the page to
   auto-open the reels-style post modal on that exact post. */
var GF_AUTO_OPEN_MEME_ID = <?php echo (int)$auto_open_meme_id; ?>;

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
    <?php if (!empty($group['category'])): ?>
<span class="gh-adult" style="background:rgba(9,38,110,.4);color:#4d8fff;border-color:rgba(26,86,219,.5);">
    <i class="ri-price-tag-3-line"></i> <?php echo htmlspecialchars($group['category']); ?>
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

    <div class="mobile-sticky-topbar" id="mobileStickyTopbar">
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

   FIX: the blur/background now lives ONLY on the wrap
   itself (applied when .visible), not on each child bar
   separately. Two independently-blurred siblings create
   a visible seam at their shared edge because each blurs
   content behind only itself. One shared blurred surface
   removes that seam completely, on every breakpoint.
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
    background: rgba(3,4,32,0.94);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
}

/* ── DESKTOP: keep the bar clear of the fixed 100px sidebar ── */
@media (min-width: 769px) {
    .mobile-sticky-wrap { left: 100px; }
}

/* ── TOPBAR — now a static child inside the wrap, no longer fixed itself ──
   background/blur removed: the wrap now provides the single shared
   blurred surface for both bars, so no seam can form between them. */
.mobile-sticky-topbar {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px 12px 16px;
    padding-top: calc(env(safe-area-inset-top) + 12px);
    background: transparent;
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

/* ── HASHTAG + SORT BAR — static child, sits directly under topbar, no gap ──
   background/blur/border-top/box-shadow all removed: those were the
   actual source of the visible seam (double blur + shadow implying a
   floating panel). The wrap's single background now shows through. */
.mobile-hashtag-bar {
    background: transparent;
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

<script>
function openGroupPicModal() {
    var modal = document.getElementById('groupPicModal');
    if (!modal) return;
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeGroupPicModal() {
    var modal = document.getElementById('groupPicModal');
    if (!modal) return;
    modal.classList.remove('active');
    document.body.style.overflow = '';
}
</script>

    <!-- BODY -->
    <div class="gh-body">

        <!-- TITLE + KEBAB -->
        <div class="gh-top">
            <!-- REPLACE your existing .gh-name div with this entire block -->

<div class="gh-name">
    <h1 id="podTitleH1"
    onclick="openPodInfoModal()"
    style="cursor:pointer;"
    title="<?php echo htmlspecialchars($group['title']); ?>"
    data-full-title="<?php echo htmlspecialchars($group['title'], ENT_QUOTES); ?>">
    <?php echo htmlspecialchars($group['title']); ?>
</h1>

<script>
(function () {
    function truncate(str, max) {
        return str.length > max ? str.slice(0, max) + '...' : str;
    }

    function applyPodTitleTruncation() {
        var el = document.getElementById('podTitleH1');
        if (!el) return;

        var full = el.getAttribute('data-full-title');
        var w = window.innerWidth;
        var max;

        if (w <= 330)      max = 10;
        else if (w <= 390) max = 14;
        else if (w <= 490) max = 14;
        else if (w <= 790) max = 40;
        else               max = full.length; // full title above 790px

        el.textContent = truncate(full, max);
    }

    applyPodTitleTruncation();
    window.addEventListener('resize', applyPodTitleTruncation);
})();
</script>
    <button class="pod-chevron-btn" onclick="openPodInfoModal()" title="View Pod details" aria-label="View Pod info">
        <i class="ri-arrow-right-s-line"></i>
    </button>
    <?php if ($can_view_content): ?>
    <?php include 'group_more_members_strip.php'; ?>
<?php endif; ?>

</div>


<!-- ══════════════════════════════════════════
     POD INFO MODAL — full-screen community hub
══════════════════════════════════════════ -->
<!-- ══════════════════════════════════════════
     POD INFO MODAL — full page details view
══════════════════════════════════════════ -->
<style>
/* ── Chevron Button (small chevron next to pod title) ── */
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

/* ── Back button: bigger chevron, shifted further left, no circle/background ── */
.pim-back-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 38px;
    height: 38px;
    border: none;
    background: transparent;
    color: #fff;
    font-size: 38px;
    cursor: pointer;
    transition: opacity 0.2s ease, transform 0.15s ease;
    flex-shrink: 0;
    -webkit-tap-highlight-color: transparent;
    padding: 0;
    margin-left: -12px;
}
.pim-back-btn:hover  { opacity: 0.7; }
.pim-back-btn:active { transform: scale(0.9); opacity: 0.55; }

.pim-topbar-title {
    flex: 1;
    min-width: 0;
    font-size: 15px;
    font-weight: 700;
    /* UPDATED: was #4d7fe0 (blue) — now white per request */
    color: #ffffff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    font-family: 'Poppins', sans-serif;
}

/* ── About strip (replaces the old name/date header; separated by
     horizontal lines only — no bubble/box container) ── */
.pim-about-strip {
    padding: 22px 20px 24px;
    border-bottom: 1px solid rgba(255,255,255,0.07);
    flex-shrink: 0;
}
.pim-about-head {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 17px;
    font-weight: 700;
    letter-spacing: 0.3px;
    color: rgba(255,255,255,0.9);
    text-transform: none;
    font-family: 'Poppins', sans-serif;
    margin-bottom: 10px;
}
.pim-about-head i { font-size: 19px; color: rgba(255,255,255,0.55); }
.pim-desc-text {
    font-size: 13.5px;
    color: rgba(255,255,255,0.75);
    line-height: 1.65;
    font-family: 'Poppins', sans-serif;
    word-break: break-word;
}
.pim-desc-toggle {
    display: inline-block;
    margin-left: 4px;
    color: #1a56db;
    font-weight: 800;
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
    white-space: nowrap;
}
.pim-desc-toggle:hover { color: #0673f0; text-decoration: underline; }

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

.pim-subtext {
    font-size: 11px;
    color: rgba(255,255,255,0.3);
    margin: -6px 0 12px;
    font-family: 'Poppins', sans-serif;
}

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
.pim-dt-icon { width: 40px; text-align: center; }
.pim-dt-icon i { font-size: 21px; color: rgba(255,255,255,0.3); }
.pim-dt-label {
    width: 38%;
    font-size: 12.5px;
    color: rgba(255,255,255,0.42);
    font-weight: 500;
    white-space: nowrap;
}
.pim-dt-value {
    font-size: 13.5px;
    color: rgba(255,255,255,0.9);
    font-weight: 600;
    text-align: right;
}
.pim-dt-value.accent { color: #4dabff; }
.pim-dt-value.green  { color: #1a6dff; }
.pim-dt-value.gold   { color: #8fb4ff; }
.pim-dt-value.red    { color: #2563ff; }

/* ── Top stats & Live activity now render as list rows using .pim-details-table (see markup below) ── */

/* ── Creator Strip ── */
.pim-creator-row {
    display: flex;
    align-items: center;
    gap: 12px;
    background: rgba(26,109,255,0.08);
    border: 1px solid rgba(26,109,255,0.22);
    border-radius: 16px;
    padding: 13px 15px;
    text-decoration: none;
    transition: background 0.2s;
}
.pim-creator-row:hover { background: rgba(26,109,255,0.15); }
.pim-creator-row img {
    width: 48px; height: 48px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid #1a6dff;
    box-shadow: 0 0 10px rgba(26,109,255,0.5);
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
    border: 3px solid #1a6dff;
    box-shadow: 0 0 10px rgba(26,109,255,0.5);
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
    color: #4dabff;
    font-weight: 700;
    margin-top: -4px;
    font-family: 'Poppins', sans-serif;
}

/* ── Tags — rectangular, blue, thin, horizontally-scrollable strips ── */
.pim-tag-wrap {
    position: relative;
}
.pim-tags-scroll {
    display: flex;
    flex-wrap: nowrap;
    gap: 8px;
    overflow-x: auto;
    scroll-behavior: smooth;
    scrollbar-width: none;
    -ms-overflow-style: none;
    -webkit-overflow-scrolling: touch;
    padding-bottom: 2px;
}
.pim-tags-scroll::-webkit-scrollbar { display: none; }

.pim-tag {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    flex: 0 0 auto;
    white-space: nowrap;
    padding: 6px 12px;
    border-radius: 7px;
    background: rgba(77,171,255,0.09);
    border: 1px solid rgba(77,171,255,0.25);
    color: #4dabff;
    font-size: 12px;
    font-weight: 600;
    font-family: 'Poppins', sans-serif;
    cursor: pointer;
    transition: 0.18s;
    -webkit-tap-highlight-color: transparent;
}
.pim-tag:hover { background: rgba(77,171,255,0.2); }

/* Pod's own set hashtags use the SAME blue treatment as popular topics now */
.pim-tag-official {
    background: rgba(77,171,255,0.09);
    border-color: rgba(77,171,255,0.25);
    color: #4dabff;
}
.pim-tag-official:hover { background: rgba(77,171,255,0.2); }

/* Arrows for tag strips — desktop hover only, hidden entirely on touch/responsive */
.pim-tag-arrow {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    width: 30px; height: 30px;
    border-radius: 50%;
    background: rgba(3,4,32,0.92);
    border: 1px solid rgba(255,255,255,0.14);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    cursor: pointer;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.2s ease;
    z-index: 3;
}
.pim-tag-wrap:hover .pim-tag-arrow { opacity: 1; pointer-events: all; }
.pim-tag-arrow-left  { left: -4px; }
.pim-tag-arrow-right { right: -4px; }

@media (max-width: 790px) {
    .pim-tag-arrow { display: none !important; }
}

/* ── Suggested For You ── */
.pim-sugg-wrap {
    position: relative;
}
.pim-sugg-scroll {
    display: flex;
    gap: 14px;
    overflow-x: auto;
    scroll-behavior: smooth;
    scrollbar-width: none;
    -ms-overflow-style: none;
    -webkit-overflow-scrolling: touch;
    padding-bottom: 2px;
}
.pim-sugg-scroll::-webkit-scrollbar { display: none; }

.pim-sugg-card {
    flex: 0 0 auto;
    /* UPDATED: wider card (was 260px) */
    width: 300px;
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 16px;
    overflow: hidden;
    transition: transform 0.25s ease, opacity 0.25s ease;
}
.pim-sugg-img-wrap {
    display: block;
    width: 100%;
    /* UPDATED: shorter than a full square so wider cards don't get taller too (was 1 / 1) */
    aspect-ratio: 16 / 10;
    overflow: hidden;
    background: rgba(255,255,255,0.06);
}
.pim-sugg-img-wrap img {
    width: 100%; height: 100%;
    object-fit: cover;
    display: block;
}
.pim-sugg-body { padding: 12px 12px 14px; }
.pim-sugg-name {
    font-size: 13.5px;
    font-weight: 700;
    color: #fff;
    font-family: 'Poppins', sans-serif;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    line-height: 1.3;
    min-height: 2.6em;
}
.pim-sugg-members {
    font-size: 11.5px;
    color: rgba(255,255,255,0.4);
    /* UPDATED: sits closer to the name now (was 5px 0 12px) */
    margin: 1px 0 12px;
    font-family: 'Poppins', sans-serif;
}
.pim-sugg-btns { display: flex; gap: 8px; }
.pim-sugg-join-btn, .pim-sugg-remove-btn {
    flex: 1;
    /* UPDATED: thinner buttons (was 10px 6px) */
    padding: 7px 6px;
    font-size: 13px;
    font-weight: 700;
    border-radius: 9px;
    border: none;
    cursor: pointer;
    font-family: 'Poppins', sans-serif;
    transition: 0.2s;
    -webkit-tap-highlight-color: transparent;
}
.pim-sugg-join-btn { background: #1a56db; color: #fff; }
.pim-sugg-join-btn:hover { background: #0673f0; }
.pim-sugg-remove-btn { background: rgba(255,255,255,0.08); color: rgba(255,255,255,0.6); }
.pim-sugg-remove-btn:hover { background: rgba(255,255,255,0.15); color: #fff; }
.pim-sugg-remove-btn:disabled { opacity: 0.5; cursor: default; }

/* Arrows — desktop hover only. Hidden entirely below 790px (see media query)
   so they never flash on tap/scroll on touch devices. */
.pim-sugg-arrow {
    position: absolute;
    top: 40%;
    transform: translateY(-50%);
    width: 34px; height: 34px;
    border-radius: 50%;
    background: rgba(3,4,32,0.92);
    border: 1px solid rgba(255,255,255,0.14);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    cursor: pointer;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.2s ease;
    z-index: 3;
}
.pim-sugg-wrap:hover .pim-sugg-arrow { opacity: 1; pointer-events: all; }
.pim-sugg-arrow-left  { left: -6px; }
.pim-sugg-arrow-right { right: -6px; }

/* FIX: arrows were only ever hidden via opacity + a desktop-only :hover rule.
   On touch devices, tapping a card / scrolling can still momentarily trigger
   a ":hover" state in some mobile browsers, causing the arrows to flash on
   screen for a split second. The tag-arrows already had a hard responsive
   cutoff — the suggested-pod arrows didn't. Bringing them in line: below
   790px the arrows are fully removed from layout and can never render,
   regardless of hover/touch state. */
@media (max-width: 790px) {
    .pim-sugg-arrow { display: none !important; }
}

/* ── Similar Pods (legacy row style, no longer used but kept in case referenced elsewhere) ── */
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
    border: 3px solid rgba(26,109,255,0.7);
    box-shadow: 0 0 8px rgba(26,109,255,0.4);
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



/* ════════════════════════════════════════
   RESPONSIVE — 770px
   ════════════════════════════════════════ */
@media (max-width: 770px) {
    .pim-topbar            { padding: 11px 16px; gap: 10px; }
    .pim-back-btn          { font-size: 32px; width: 34px; height: 34px; margin-left: -10px; }
    .pim-topbar-title      { font-size: 13px; }

    .pim-content           { padding: 28px 14px 40px; }

    .pim-section-head      { font-size: 10px; margin: 22px 0 10px; padding-bottom: 7px; }
    .pim-section-head i    { font-size: 12px; }

    .pim-details-table td  { padding: 10px 8px; }
    .pim-dt-icon i         { font-size: 19px; }
    .pim-dt-label          { font-size: 11.5px; }
    .pim-dt-value          { font-size: 12.5px; }



    .pim-creator-row       { padding: 11px 13px; gap: 10px; border-radius: 13px; }
    .pim-creator-row img   { width: 42px; height: 42px; border-width: 3px; }
    .pim-creator-label     { font-size: 9px; }
    .pim-creator-name      { font-size: 13px; }

    .pim-person img        { width: 44px; height: 44px; border-width: 3px; }
    .pim-person-name       { font-size: 10px; max-width: 50px; }
    .pim-person-role       { font-size: 8px; }

    .pim-tag               { padding: 5px 11px; font-size: 11px; }

    /* UPDATED: wider (was 220px) */
    .pim-sugg-card         { width: 250px; }
    .pim-sugg-name         { font-size: 12.5px; }
    .pim-sugg-members      { font-size: 10.5px; margin-top: 1px; }
    /* UPDATED: thinner (was 9px 5px) */
    .pim-sugg-join-btn, .pim-sugg-remove-btn { font-size: 12px; padding: 6px 5px; }

    .pim-desc-text          { font-size: 13px; }

    .pim-about-strip       { padding: 18px 14px 20px; }
    .pim-about-head        { font-size: 15px; }
    .pim-about-head i      { font-size: 17px; }
}


/* ════════════════════════════════════════
   RESPONSIVE — 480px
   ════════════════════════════════════════ */
@media (max-width: 480px) {
    .pim-topbar            { padding: 10px 14px; gap: 9px; }
    .pim-back-btn          { font-size: 30px; width: 32px; height: 32px; margin-left: -9px; }
    .pim-topbar-title      { font-size: 13px; }

    .pim-content           { padding: 22px 13px 36px; }

    .pim-section-head      { font-size: 9px; letter-spacing: 0.9px; margin: 20px 0 9px; padding-bottom: 6px; }
    .pim-section-head i    { font-size: 11px; }

    .pim-details-table td  { padding: 9px 6px; }
    .pim-dt-icon           { width: 32px; }
    .pim-dt-icon i         { font-size: 17px; }
    .pim-dt-label          { font-size: 11px; }
    .pim-dt-value          { font-size: 12px; }



    .pim-creator-row       { padding: 10px 12px; gap: 9px; border-radius: 13px; }
    .pim-creator-row img   { width: 40px; height: 40px; border-width: 3px; }
    .pim-creator-label     { font-size: 9px; }
    .pim-creator-name      { font-size: 13px; }

    .pim-people-row        { gap: 11px; }
    .pim-person img        { width: 42px; height: 42px; border-width: 3px; }
    .pim-person-name       { font-size: 10px; max-width: 48px; }
    .pim-person-role       { font-size: 8px; }

    .pim-tag               { padding: 5px 10px; font-size: 11px; gap: 3px; }

    /* UPDATED: wider (was 200px) */
    .pim-sugg-card         { width: 225px; }
    .pim-sugg-body         { padding: 10px 10px 12px; }
    .pim-sugg-name         { font-size: 12px; min-height: 2.4em; }
    .pim-sugg-members      { font-size: 10px; margin: 1px 0 10px; }
    /* UPDATED: thinner (was 8px 4px) */
    .pim-sugg-join-btn, .pim-sugg-remove-btn { font-size: 11.5px; padding: 6px 4px; border-radius: 8px; }

    .pim-desc-text          { font-size: 12.5px; line-height: 1.6; }

    .pim-about-strip       { padding: 16px 13px 18px; }
    .pim-about-head        { font-size: 14px; }
    .pim-about-head i      { font-size: 16px; }
}


/* ════════════════════════════════════════
   RESPONSIVE — 380px
   ════════════════════════════════════════ */
@media (max-width: 380px) {
    .pim-topbar            { padding: 9px 12px; gap: 8px; }
    .pim-back-btn          { font-size: 28px; width: 30px; height: 30px; margin-left: -8px; }
    .pim-topbar-title      { font-size: 12px; }

    .pim-content           { padding: 18px 12px 32px; }

    .pim-section-head      { font-size: 9px; letter-spacing: 0.8px; margin: 17px 0 8px; padding-bottom: 6px; }
    .pim-section-head i    { font-size: 11px; }

    .pim-details-table td  { padding: 8px 5px; }
    .pim-dt-icon           { width: 28px; }
    .pim-dt-icon i         { font-size: 15.5px; }
    .pim-dt-label          { font-size: 10.5px; }
    .pim-dt-value          { font-size: 11.5px; }



    .pim-creator-row       { padding: 9px 11px; gap: 8px; border-radius: 12px; }
    .pim-creator-row img   { width: 38px; height: 38px; border-width: 2.5px; }
    .pim-creator-label     { font-size: 9px; }
    .pim-creator-name      { font-size: 12px; }

    .pim-people-row        { gap: 10px; }
    .pim-person img        { width: 40px; height: 40px; border-width: 2.5px; }
    .pim-person-name       { font-size: 10px; max-width: 44px; }
    .pim-person-role       { font-size: 8px; }

    .pim-tag               { padding: 5px 10px; font-size: 11px; gap: 3px; }

    /* UPDATED: wider (was 180px) */
    .pim-sugg-card         { width: 200px; }
    .pim-sugg-name         { font-size: 11.5px; }
    .pim-sugg-members      { font-size: 9.5px; margin-top: 1px; }
    /* UPDATED: thinner (was 8px 4px) */
    .pim-sugg-join-btn, .pim-sugg-remove-btn { font-size: 11px; padding: 6px 4px; }

    .pim-desc-text          { font-size: 12px; line-height: 1.55; }

    .pim-about-strip       { padding: 14px 12px 16px; }
    .pim-about-head        { font-size: 13px; }
    .pim-about-head i      { font-size: 15px; }
}


/* ════════════════════════════════════════
   RESPONSIVE — 330px
   ════════════════════════════════════════ */
@media (max-width: 330px) {
    .pim-topbar            { padding: 8px 11px; gap: 7px; }
    .pim-back-btn          { font-size: 26px; width: 28px; height: 28px; margin-left: -7px; }
    .pim-topbar-title      { font-size: 11px; }

    .pim-content           { padding: 15px 10px 28px; }

    .pim-section-head      { font-size: 8px; letter-spacing: 0.7px; margin: 14px 0 7px; padding-bottom: 5px; }
    .pim-section-head i    { font-size: 10px; }

    .pim-details-table td  { padding: 7px 4px; }
    .pim-dt-icon           { width: 24px; }
    .pim-dt-icon i         { font-size: 14px; }
    .pim-dt-label          { font-size: 10px; }
    .pim-dt-value          { font-size: 11px; }



    .pim-creator-row       { padding: 8px 10px; gap: 7px; border-radius: 11px; }
    .pim-creator-row img   { width: 34px; height: 34px; border-width: 2.5px; }
    .pim-creator-label     { font-size: 9px; }
    .pim-creator-name      { font-size: 12px; }

    .pim-people-row        { gap: 9px; }
    .pim-person img        { width: 36px; height: 36px; border-width: 2.5px; }
    .pim-person-name       { font-size: 10px; max-width: 40px; }
    .pim-person-role       { font-size: 8px; }

    .pim-tag               { padding: 4px 9px; font-size: 10px; gap: 3px; }

    /* UPDATED: wider (was 160px) */
    .pim-sugg-card         { width: 178px; }
    .pim-sugg-body         { padding: 8px 8px 10px; }
    .pim-sugg-name         { font-size: 11px; min-height: 2.3em; }
    .pim-sugg-members      { font-size: 9px; margin: 1px 0 8px; }
    /* UPDATED: thinner (was 7px 3px) */
    .pim-sugg-join-btn, .pim-sugg-remove-btn { font-size: 10px; padding: 5px 3px; border-radius: 7px; }

    .pim-desc-text          { font-size: 11.5px; line-height: 1.5; }

    .pim-about-strip       { padding: 12px 10px 14px; }
    .pim-about-head        { font-size: 12px; }
    .pim-about-head i      { font-size: 14px; }
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

    /* ── Popular topics — auto-detected from post captions (separate from the pod's own set hashtags) ── */
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

    /* ── Image path helper ── */
    $pim_banner = !empty($group['group_pic'])
        ? (strpos($group['group_pic'],'uploads/')===0 ? $group['group_pic'] : 'uploads/'.$group['group_pic'])
        : 'uploads/default_group.png';

    /* ══════════════════════════════════════════════════════════════════
       SMART "SUGGESTED FOR YOU" PODS
       Matches on: (a) same category as the current pod, or
                   (b) a shared significant word between pod titles
                   (e.g. "Funny Memes Club" ↔ "Funny Memes Fan Page").
       Excludes: pods the viewer already belongs to, the current pod
       itself, and any pod the viewer has explicitly dismissed via the
       Remove button (stored permanently in group_suggestion_dismissed).
       Order is randomized every load so the same pods don't always sit
       in the same position. If nothing matches, the whole section is
       hidden — never shows random/unrelated pods as filler.
       ══════════════════════════════════════════════════════════════════ */
    $suggested_pods = [];

    // pods this user has permanently dismissed as a suggestion
    $dismissed_ids = [];
    $dstmt = $conn->prepare("SELECT dismissed_group_id FROM group_suggestion_dismissed WHERE user_id=?");
    $dstmt->bind_param("i", $current_user);
    $dstmt->execute();
    $dres = $dstmt->get_result();
    while ($row = $dres->fetch_assoc()) $dismissed_ids[] = (int)$row['dismissed_group_id'];
    $dstmt->close();

    // pods this user already belongs to (no point suggesting those) + current pod
    $joined_ids = [$group_id];
    $jstmt = $conn->prepare("SELECT group_id FROM group_members WHERE user_id=?");
    $jstmt->bind_param("i", $current_user);
    $jstmt->execute();
    $jres = $jstmt->get_result();
    while ($row = $jres->fetch_assoc()) $joined_ids[] = (int)$row['group_id'];
    $jstmt->close();

    $exclude_ids = array_unique(array_merge($dismissed_ids, $joined_ids));
    $exclude_sql = implode(',', array_map('intval', $exclude_ids));

    // pull meaningful keywords out of this pod's title (drop filler words/short words)
    $sugg_stopwords = ['the','a','an','of','for','and','or','fan','fans','page','club','group','pod','pods','official','community','to','in','on','with','my'];
    $title_words = preg_split('/[^a-z0-9]+/i', strtolower($group['title'] ?? ''));
    $sugg_keywords = [];
    foreach ($title_words as $w) {
        $w = trim($w);
        if ($w !== '' && mb_strlen($w) >= 4 && !in_array($w, $sugg_stopwords, true)) $sugg_keywords[] = $w;
    }
    $sugg_keywords = array_slice(array_unique($sugg_keywords), 0, 6);

    $current_category = $group['category'] ?? '';

    $sugg_where = [];
    $sugg_params = [];
    $sugg_types  = "";

    if (!empty($current_category)) {
        $sugg_where[] = "g.category = ?";
        $sugg_params[] = $current_category;
        $sugg_types   .= "s";
    }
    foreach ($sugg_keywords as $kw) {
        $sugg_where[] = "g.title LIKE ?";
        $sugg_params[] = "%$kw%";
        $sugg_types   .= "s";
    }

    if (!empty($sugg_where)) {
        $sugg_sql = "
            SELECT g.id, g.title, g.group_pic, g.category,
                   (SELECT COUNT(*) FROM group_members WHERE group_id=g.id) AS mc
            FROM groups g
            WHERE g.id NOT IN ($exclude_sql)
              AND (" . implode(' OR ', $sugg_where) . ")
            ORDER BY RAND()
            LIMIT 12
        ";
        $sstmt = $conn->prepare($sugg_sql);
        if ($sstmt) {
            if (!empty($sugg_params)) $sstmt->bind_param($sugg_types, ...$sugg_params);
            $sstmt->execute();
            $sres2 = $sstmt->get_result();
            while ($row = $sres2->fetch_assoc()) $suggested_pods[] = $row;
            $sstmt->close();
        }
    }
    ?>

    <!-- TOP BAR -->
    <div class="pim-topbar">
        <button class="pim-back-btn" onclick="closePodInfoModal()" aria-label="Back">
            <i class="ri-arrow-left-s-line"></i>
        </button>
        <div>
            <div class="pim-topbar-title"><?php echo htmlspecialchars($group['title']); ?></div>
        </div>
    </div>

    <!-- ABOUT (moved here; separated by horizontal lines, no bubble container) -->
    <?php
        $pim_desc_raw = $group['description'] ?? 'No description set.';
        $pim_desc_words = preg_split('/\s+/', trim($pim_desc_raw));
        $pim_desc_words = array_filter($pim_desc_words, function($w) { return $w !== ''; });
        $pim_desc_words = array_values($pim_desc_words);
        $pim_desc_needs_trunc = count($pim_desc_words) > 50;
        $pim_desc_short_raw = $pim_desc_needs_trunc
            ? implode(' ', array_slice($pim_desc_words, 0, 50))
            : $pim_desc_raw;
    ?>
    <div class="pim-about-strip">
        <div class="pim-about-head">
            <i class="ri-information-line"></i> About
        </div>
        <div class="pim-desc-text" id="pimDescText">
            <span id="pimDescShort"><?php echo nl2br(htmlspecialchars($pim_desc_short_raw)); ?></span><span id="pimDescFull" style="display:none;"><?php echo nl2br(htmlspecialchars($pim_desc_raw)); ?></span><?php if ($pim_desc_needs_trunc): ?><span class="pim-desc-toggle" id="pimDescToggleBtn" onclick="pimToggleDesc()">...more</span><?php endif; ?>
        </div>
    </div>

    <!-- CONTENT -->
    <div class="pim-content">

        <!-- ── TOP STATS (list view) ── -->
        <div class="pim-section-head">
            <i class="ri-bar-chart-2-line"></i> Overview
        </div>
        <table class="pim-details-table">
            <tr>
                <td class="pim-dt-icon"><i class="ri-group-line"></i></td>
                <td class="pim-dt-label">Members</td>
                <td class="pim-dt-value accent"><?php echo number_format($stat_members); ?></td>
            </tr>
            <tr>
                <td class="pim-dt-icon"><i class="ri-image-2-line"></i></td>
                <td class="pim-dt-label">Posts</td>
                <td class="pim-dt-value accent"><?php echo number_format($stat_posts); ?></td>
            </tr>
            <tr>
                <td class="pim-dt-icon"><i class="ri-discuss-line"></i></td>
                <td class="pim-dt-label">Discussions</td>
                <td class="pim-dt-value accent"><?php echo number_format($stat_discs); ?></td>
            </tr>
        </table>

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
                <td class="pim-dt-icon"><i class="ri-price-tag-3-line"></i></td>
                <td class="pim-dt-label">Category</td>
                <td class="pim-dt-value accent"><?php echo $pod_category; ?></td>
            </tr>
            <tr>
                <td class="pim-dt-icon"><i class="<?php echo $is_private_group ? 'ri-lock-2-line' : 'ri-earth-line'; ?>"></i></td>
                <td class="pim-dt-label">Privacy</td>
                <td class="pim-dt-value <?php echo $is_private_group ? 'red' : 'green'; ?>">
                    <?php echo $is_private_group ? 'Private' : 'Public'; ?>
                </td>
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
        <table class="pim-details-table">
            <tr>
                <td class="pim-dt-icon"><i class="ri-calendar-check-line"></i></td>
                <td class="pim-dt-label">Posts today</td>
                <td class="pim-dt-value accent"><?php echo $act_posts_today; ?></td>
            </tr>
            <tr>
                <td class="pim-dt-icon"><i class="ri-user-add-line"></i></td>
                <td class="pim-dt-label">Joined this week</td>
                <td class="pim-dt-value accent"><?php echo $act_new_members; ?></td>
            </tr>
            <tr>
                <td class="pim-dt-icon"><i class="ri-flashlight-line"></i></td>
                <td class="pim-dt-label">Online now</td>
                <td class="pim-dt-value" style="color:<?php echo $act_active_now > 0 ? '#4cff91' : '#4dabff'; ?>;"><?php echo $act_active_now; ?></td>
            </tr>
        </table>

        <!-- ── CREATOR ── -->
        <div class="pim-section-head">
            <i class="ri-vip-crown-line"></i> Creator
        </div>
        <a href="profile.php?user_id=<?php echo (int)$group['created_by']; ?>" class="pim-creator-row">
            <img src="<?php echo !empty($group['creator_pic']) ? htmlspecialchars($group['creator_pic']) : 'uploads/me.jpeg'; ?>"
                 onerror="this.src='uploads/me.jpeg';" alt="Creator">
            <div>
                <div class="pim-creator-label"><i class="ri-vip-crown-fill" style="font-size:10px;color:#4dabff;"></i> Pod Creator</div>
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

        <!-- ── POD HASHTAGS (set by the pod's owner — the same set used by the hashtag filter bar) ── -->
        <?php if (!empty($group_hashtags)): ?>
        <div class="pim-section-head">
            <i class="ri-price-tag-3-line"></i> Pod Hashtags
        </div>
        <p class="pim-subtext">Set by this pod's creator — also used to filter the feed</p>
        <div class="pim-tag-wrap" id="pimHashtagWrap">
            <div class="pim-tags-scroll" id="pimHashtagScroll">
                <?php foreach ($group_hashtags as $tag): ?>
                <span class="pim-tag pim-tag-official">
                    <i class="ri-hashtag" style="font-size:11px;"></i><?php echo htmlspecialchars($tag['tag']); ?>
                </span>
                <?php endforeach; ?>
            </div>
            <button type="button" class="pim-tag-arrow pim-tag-arrow-left" onclick="pimScrollTags('pimHashtagScroll',-1)" aria-label="Scroll left">
                <i class="ri-arrow-left-s-line"></i>
            </button>
            <button type="button" class="pim-tag-arrow pim-tag-arrow-right" onclick="pimScrollTags('pimHashtagScroll',1)" aria-label="Scroll right">
                <i class="ri-arrow-right-s-line"></i>
            </button>
        </div>
        <?php endif; ?>

        <!-- ── POPULAR TOPICS (auto-detected from post captions) ── -->
        <div class="pim-section-head">
            <i class="ri-hashtag"></i> Popular topics
        </div>
        <?php if (!empty($top_tags)): ?>
        <p class="pim-subtext">Automatically detected from what members are posting</p>
        <div class="pim-tag-wrap" id="pimTopicsWrap">
            <div class="pim-tags-scroll" id="pimTopicsScroll">
                <?php foreach ($top_tags as $tag): ?>
                <span class="pim-tag">
                    <i class="ri-hashtag" style="font-size:11px;"></i><?php echo htmlspecialchars($tag); ?>
                </span>
                <?php endforeach; ?>
            </div>
            <button type="button" class="pim-tag-arrow pim-tag-arrow-left" onclick="pimScrollTags('pimTopicsScroll',-1)" aria-label="Scroll left">
                <i class="ri-arrow-left-s-line"></i>
            </button>
            <button type="button" class="pim-tag-arrow pim-tag-arrow-right" onclick="pimScrollTags('pimTopicsScroll',1)" aria-label="Scroll right">
                <i class="ri-arrow-right-s-line"></i>
            </button>
        </div>
        <?php else: ?>
        <p style="font-size:12px;color:rgba(255,255,255,0.26);margin:0;font-family:'Poppins',sans-serif;">No hashtags posted yet — be the first!</p>
        <?php endif; ?>

        <!-- ── SUGGESTED FOR YOU ── -->
        <?php if (!empty($suggested_pods)): ?>
        <div class="pim-section-head">
            <i class="ri-compass-discover-line"></i> Suggested for you
        </div>
        <div class="pim-sugg-wrap" id="pimSuggWrap">
            <div class="pim-sugg-scroll" id="pimSuggScroll">
                <?php foreach ($suggested_pods as $sp):
                    $sp_img = !empty($sp['group_pic'])
                        ? (strpos($sp['group_pic'],'uploads/')===0 ? $sp['group_pic'] : 'uploads/'.$sp['group_pic'])
                        : 'uploads/default_group.png';
                ?>
                <div class="pim-sugg-card" id="pimSuggCard-<?php echo (int)$sp['id']; ?>">
                    <a href="group.php?id=<?php echo (int)$sp['id']; ?>" class="pim-sugg-img-wrap">
                        <img src="<?php echo htmlspecialchars($sp_img); ?>" onerror="this.src='uploads/default_group.png';" alt="">
                    </a>
                    <div class="pim-sugg-body">
                        <a href="group.php?id=<?php echo (int)$sp['id']; ?>" style="text-decoration:none;">
                            <div class="pim-sugg-name"><?php echo htmlspecialchars($sp['title']); ?></div>
                        </a>
                        <div class="pim-sugg-members"><?php echo number_format((int)$sp['mc']); ?> members</div>
                        <div class="pim-sugg-btns">
                            <form method="POST" action="group.php?id=<?php echo (int)$sp['id']; ?>" style="flex:1;display:contents;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <button type="submit" name="join_group" class="pim-sugg-join-btn">Join</button>
                            </form>
                            <button type="button" class="pim-sugg-remove-btn" onclick="pimDismissSuggestion(<?php echo (int)$sp['id']; ?>, this)">Remove</button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="pim-sugg-arrow pim-sugg-arrow-left" onclick="pimScrollSugg(-1)" aria-label="Scroll left">
                <i class="ri-arrow-left-s-line"></i>
            </button>
            <button type="button" class="pim-sugg-arrow pim-sugg-arrow-right" onclick="pimScrollSugg(1)" aria-label="Scroll right">
                <i class="ri-arrow-right-s-line"></i>
            </button>
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

/* ── About description: ...more / see less toggle ── */
function pimToggleDesc() {
    var shortEl = document.getElementById('pimDescShort');
    var fullEl = document.getElementById('pimDescFull');
    var btn = document.getElementById('pimDescToggleBtn');
    if (!shortEl || !fullEl || !btn) return;

    var isExpanded = fullEl.style.display !== 'none';
    if (isExpanded) {
        fullEl.style.display = 'none';
        shortEl.style.display = '';
        btn.textContent = '...more';
    } else {
        fullEl.style.display = '';
        shortEl.style.display = 'none';
        btn.textContent = 'see less';
    }
}

/* ── Hashtag / Popular Topics strips: horizontal scroll arrows ── */
function pimScrollTags(scrollId, dir) {
    var track = document.getElementById(scrollId);
    if (!track) return;
    var step = track.clientWidth * 0.7;
    track.scrollBy({ left: dir * step, behavior: 'smooth' });
}

/* ── Suggested For You: horizontal scroll arrows ── */
function pimScrollSugg(dir) {
    var track = document.getElementById('pimSuggScroll');
    if (!track) return;
    var card = track.querySelector('.pim-sugg-card');
    var step = card ? (card.offsetWidth + 14) * 2 : 400;
    track.scrollBy({ left: dir * step, behavior: 'smooth' });
}

/* ── Suggested For You: permanently dismiss a suggestion ── */
function pimDismissSuggestion(groupId, btn) {
    btn.disabled = true;
    var card = document.getElementById('pimSuggCard-' + groupId);

    fetch('group.php?id=<?php echo (int)$group_id; ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'dismiss_suggested_group=1&dismiss_group_id=' + groupId + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
    })
    .then(function (r) { return r.json(); })
    .then(function () {
        if (!card) return;
        card.style.transition = 'opacity 0.25s ease, transform 0.25s ease';
        card.style.opacity = '0';
        card.style.transform = 'scale(0.9)';
        setTimeout(function () {
            card.remove();
            var scroll = document.getElementById('pimSuggScroll');
            var wrap = document.getElementById('pimSuggWrap');
            if (scroll && wrap && !scroll.querySelector('.pim-sugg-card')) {
                var head = wrap.previousElementSibling;
                if (head && head.classList.contains('pim-section-head')) head.style.display = 'none';
                wrap.style.display = 'none';
            }
        }, 260);
    })
    .catch(function () {
        btn.disabled = false;
    });
}
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
    <a href="#" class="gh-desc-more" onclick="document.getElementById('descModal-<?php echo $group['id']; ?>').style.display='flex'; return false;">more</a>
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

<?php
/* ── Audio library for the post modal's "Choose from Library" picker ──
   Mirrors the query used in upload.php. Guarded so it won't clash if this
   partial is included on a page that already defines $library_audios. */
if (!isset($library_audios)) {
    $library_audios = [];
    if (isset($conn)) {
        $library_audios_res = $conn->query("
            SELECT a.*,
                   (SELECT COUNT(*) FROM meme_audio ma WHERE ma.audio_id=a.id) as uses_count
            FROM audios a
            WHERE a.visibility='public'
            ORDER BY uses_count DESC, a.created_at DESC
            LIMIT 20
        ");
        if ($library_audios_res) {
            while ($a = $library_audios_res->fetch_assoc()) $library_audios[] = $a;
        }
    }
}
?>

<?php
/* ═══════════════════════════════════════════════════════════
   ZEEM CONNECT — lets the user optionally connect this NEW
   group post to one of their OWN existing Zeems (Part 1/Part 2,
   reactions, follow-ups, context, etc). This is a lightweight
   reference stored on the NEW post (connected_meme_id) — it does
   NOT merge, embed, or copy the other post's media/caption. Both
   Zeems remain fully independent afterward. Only the current
   user's own Zeems can be selected (must be re-validated again
   server-side on submit, not just here).
═══════════════════════════════════════════════════════════ */
$my_zeems_stmt = $conn->prepare("
    SELECT m.id, m.file_name, m.type, m.caption,
           1 + (SELECT COUNT(*) FROM meme_media mm WHERE mm.meme_id = m.id) AS media_count
    FROM memes m
    WHERE m.uploaded_by=?
    ORDER BY m.created_at DESC
    LIMIT 50
");
$my_zeems_stmt->bind_param("i", $current_user);
$my_zeems_stmt->execute();
$my_zeems_res = $my_zeems_stmt->get_result();
$my_zeems = [];
if ($my_zeems_res) while ($z = $my_zeems_res->fetch_assoc()) $my_zeems[] = $z;
?>
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
                <span id="postDropText">Choose up to 20 images or videos</span>
                <input type="file" name="post_file[]" id="postFileInput" multiple accept="image/*,video/*" onchange="previewPostFiles(this)">
            </label>

            <div id="postPreviewGrid" class="pm-preview-grid"></div>


            <div id="postPreviewWrap"></div>

            <div class="caption-wrap">
                <input type="text" name="caption" id="captionInput" placeholder="Write a caption… include a #hashtag" oninput="checkHashtag(this)" autocomplete="off">
                <div class="hashtag-hint" id="hashtagHint">Must include at least one #hashtag to post</div>
            </div>
            
            <div class="checkbox-group" style="justify-content:flex-start;margin:0;">
    <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;color:rgba(255,255,255,0.5);cursor:pointer;">
        <input type="checkbox" id="pmPartnershipCheck" onchange="pmTogglePartnership()" style="accent-color:#4dde8a;width:14px;height:14px;">
        Paid Partnership
    </label>
</div>
<input type="hidden" name="is_partnership" id="pmIsPartnership" value="0">
<div id="pmPartnershipBrandArea" style="display:none;">
    <input type="text" id="pmPartnerBrandInput" name="partner_brand" placeholder="Brand name (optional)"
           style="width:100%;padding:10px 12px;border-radius:10px;background:rgba(0,0,0,0.22);border:1px solid rgba(255,255,255,0.08);color:white;font-size:12.5px;font-family:'Poppins',sans-serif;outline:none;box-sizing:border-box;">
    <div style="font-size:9.5px;color:rgba(255,255,255,0.3);margin-top:5px;">This will tag your post as a paid partnership.</div>
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

            <!-- ── ATTACHED AUDIO (optional soundtrack) ── -->
            <div class="pm-audio-block">
                <div class="pm-audio-block-label">
                    <i class="ri-music-2-line"></i> Add Sound
                    <span class="pm-audio-optional">Optional</span>
                </div>

                <div class="pm-audio-mode-tabs">
                    <button type="button" class="pm-audio-mode-btn active" data-mode="none" onclick="pmSetAudioMode('none')">
                        <i class="ri-volume-mute-line"></i> No Sound
                    </button>
                    <button type="button" class="pm-audio-mode-btn" data-mode="original" id="pmAudioOriginalBtn" onclick="pmSetAudioMode('original')" style="display:none;">
                        <i class="ri-film-line"></i> Original
                    </button>
                    <button type="button" class="pm-audio-mode-btn" data-mode="upload" onclick="pmSetAudioMode('upload')">
                        <i class="ri-upload-2-line"></i> Upload
                    </button>
                    <button type="button" class="pm-audio-mode-btn" data-mode="library" onclick="pmSetAudioMode('library')">
                        <i class="ri-play-list-2-line"></i> Library
                    </button>
                </div>

                <input type="hidden" name="audio_mode" id="pmAudioMode" value="none">
                <input type="hidden" name="selected_audio_id" id="pmSelectedAudioId" value="">

                <div id="pmAudioUploadArea" style="display:none;">
                    <label for="pmAudioFileInput" class="pm-audio-upload-area">
                        <i class="ri-mic-line"></i>
                        <span>Select an MP3, WAV, M4A, OGG or AAC file</span>
                        <small>One track per post</small>
                        <input type="file" id="pmAudioFileInput" name="audio_file" accept=".mp3,.wav,.m4a,.ogg,.aac,audio/*" style="display:none;" onchange="pmHandleAudioFile(this.files)">
                    </label>
                </div>

                <div id="pmAudioLibraryArea" style="display:none;">
                    <?php if (empty($library_audios)): ?>
                    <div class="pm-audio-empty"><i class="ri-music-2-line"></i> No library tracks yet — be the first to upload one!</div>
                    <?php else: ?>
                    <div class="pm-audio-lib-list">
                        <?php foreach ($library_audios as $a):
                            $lib_label = $a['title'] . (!empty($a['artist']) ? ' — ' . $a['artist'] : '');
                        ?>
                        <div class="pm-audio-lib-item" onclick='pmSelectLibraryAudio(<?php echo (int)$a["id"]; ?>, <?php echo json_encode($lib_label); ?>, <?php echo json_encode("uploads/" . $a["file_name"]); ?>)'>
                            <div class="pm-audio-lib-icon"><i class="ri-music-2-fill"></i></div>
                            <div class="pm-audio-lib-info">
                                <div class="pm-audio-lib-title"><?php echo htmlspecialchars($lib_label); ?></div>
                                <div class="pm-audio-lib-sub"><?php echo (int)$a['uses_count']; ?> uses</div>
                            </div>
                            <i class="ri-play-circle-line pm-audio-lib-play"></i>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <div id="pmAudioSelectedPill" class="pm-audio-pill" style="display:none;">
                    <i class="ri-music-2-fill"></i>
                    <span id="pmAudioPillText">Track</span>
                    <audio id="pmAudioPreviewPlayer" style="display:none;"></audio>
                    <button type="button" class="pm-audio-pill-play" onclick="pmToggleAudioPreview()"><i class="ri-play-fill" id="pmAudioPreviewIcon"></i></button>
                    <button type="button" class="pm-audio-pill-remove" onclick="pmClearAudio()">✕</button>
                </div>

                <div id="pmAudioVolumeControls" class="pm-audio-volume-controls" style="display:none;">
                    <div class="pm-audio-volume-row">
                        <span>🎵 Music</span>
                        <input type="range" id="pmMusicVolumeRange" name="music_volume" min="0" max="100" value="80" oninput="document.getElementById('pmMusicVolumeVal').textContent=this.value">
                        <span id="pmMusicVolumeVal">80</span>
                    </div>
                    <div class="pm-audio-volume-row" id="pmVideoAudioVolumeRow" style="display:none;">
                        <span>🎬 Video</span>
                        <input type="range" id="pmVideoAudioVolumeRange" name="video_audio_volume" min="0" max="100" value="40" oninput="document.getElementById('pmVideoAudioVolumeVal').textContent=this.value">
                        <span id="pmVideoAudioVolumeVal">40</span>
                    </div>
                </div>
            </div>

            <!-- ═══════════════════════════
                 ZEEM CONNECT (optional — connect this new post to
                 one of YOUR OWN existing Zeems: Part 1/Part 2,
                 reactions, follow-ups, context, etc). This is a
                 reference only — the connected Zeem itself is never
                 edited, merged, or embedded. Both posts stay fully
                 independent. Viewers will see a lower-left overlay
                 on the media reading "▶ View · Connected Zeem" (or
                 your custom label) that opens the connected post.
            ═══════════════════════════ -->
            <div class="pm-zeem-block">
                <button type="button" class="pm-zeem-toggle-btn" id="pmZeemToggleBtn" onclick="pmToggleZeemConnectPanel()">
                    <i class="ri-link"></i> Connect a Zeem
                    <span class="pm-audio-optional">Optional</span>
                </button>

                <div id="pmZeemConnectPanel" class="pm-zeem-panel" style="display:none;">
                    <div class="pm-zeem-panel-title"><i class="ri-link"></i> Connect Zeem</div>

                    <input type="text" id="pmZeemSearchInput" class="pm-zeem-search-input" placeholder="🔍 Search your Zeems..." oninput="pmFilterZeemList()">

                    <div class="pm-zeem-section-label">Your Zeems</div>
                    <div class="pm-zeem-lib-list" id="pmZeemLibList"></div>
                    <div class="pm-audio-empty" id="pmZeemLibEmpty" style="display:none;">
                        <i class="ri-links-line"></i> No matching Zeems found.
                    </div>
                </div>

                <input type="hidden" name="connected_meme_id" id="pmConnectedMemeId" value="">
                <input type="hidden" name="connected_label" id="pmConnectedLabel" value="">

                <div id="pmZeemConnectedCard" class="pm-zeem-connected-card" style="display:none;">
                    <div class="pm-zeem-connected-thumb" id="pmZeemConnectedThumb"></div>
                    <div class="pm-zeem-connected-info">
                        <div class="pm-zeem-connected-caption" id="pmZeemConnectedCaption">Connected Zeem</div>
                        <div class="pm-zeem-connected-badge"><i class="ri-checkbox-circle-fill"></i> Connected</div>
                    </div>
                    <button type="button" class="pm-zeem-remove-btn" onclick="pmClearConnectedZeem()">✕</button>
                </div>

                <!-- Custom button label — optional. Leave blank and viewers
                     see the generic "▶ View · Connected Zeem". Type
                     anything (e.g. "Part 2", "The Aftermath") and that
                     shows instead. -->
                <div id="pmZeemLabelArea" class="pm-zeem-label-area" style="display:none;">
                    <label class="pm-zeem-label-title" for="pmZeemLabelInput">Button label (optional)</label>
                    <input type="text" id="pmZeemLabelInput" class="pm-zeem-label-input" maxlength="40" placeholder="e.g. Part 2, The Aftermath, My Reaction 😂" oninput="pmSyncZeemLabel()">
                    <div class="pm-zeem-label-preview">▶ View · <span id="pmZeemLabelPreview">Connected Zeem</span></div>
                </div>
            </div>

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

/* ── Attached Audio (optional soundtrack) ── */
.pm-audio-block { }
.pm-audio-block-label {
    display: flex; align-items: center; gap: 6px;
    font-size: 12px; font-weight: 700; color: rgba(255,255,255,0.7);
    margin-bottom: 10px;
    font-family: 'Poppins', sans-serif;
}
.pm-audio-block-label i { color: #FF2D2D; font-size: 15px; }
.pm-audio-optional {
    font-size: 9px; font-weight: 600; color: rgba(255,255,255,0.35);
    background: rgba(255,255,255,0.06); padding: 2px 8px; border-radius: 20px;
    text-transform: uppercase; letter-spacing: 0.4px; margin-left: auto;
}
.pm-audio-mode-tabs { display: flex; gap: 6px; margin-bottom: 10px; flex-wrap: wrap; }
.pm-audio-mode-btn {
    flex: 1; min-width: 80px; padding: 9px 8px; border-radius: 12px;
    border: 1px solid rgba(255,255,255,0.12); background: rgba(255,255,255,0.05);
    color: rgba(255,255,255,0.55); font-size: 11px; font-weight: 600;
    cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 5px;
    transition: 0.2s; font-family: 'Poppins', sans-serif;
    -webkit-tap-highlight-color: transparent;
}
.pm-audio-mode-btn i { font-size: 14px; }
.pm-audio-mode-btn.active { background: rgba(255,45,45,0.85); border-color: rgba(255,45,45,0.85); color: #fff; }
.pm-audio-upload-area {
    display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px;
    border: 1.5px dashed rgba(255,255,255,0.15); border-radius: 16px;
    padding: 24px 16px; color: rgba(255,255,255,0.5); font-size: 12.5px;
    cursor: pointer; transition: 0.25s; text-align: center;
}
.pm-audio-upload-area:hover { background: rgba(255,255,255,0.04); border-color: rgba(255,45,45,0.4); }
.pm-audio-upload-area i { font-size: 20px; color: #FF2D2D; }
.pm-audio-upload-area small { color: rgba(255,255,255,0.3); font-size: 10px; }
.pm-audio-empty {
    font-size: 11.5px; color: rgba(255,255,255,0.3); font-style: italic;
    padding: 10px 2px; font-family: 'Poppins', sans-serif;
}
.pm-audio-lib-list { max-height: 220px; overflow-y: auto; display: flex; flex-direction: column; gap: 6px; padding-right: 2px; }
.pm-audio-lib-item {
    display: flex; align-items: center; gap: 10px; padding: 9px; border-radius: 13px;
    background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1);
    cursor: pointer; transition: 0.2s;
}
.pm-audio-lib-item:hover { border-color: rgba(255,45,45,0.35); background: rgba(255,45,45,0.06); }
.pm-audio-lib-icon {
    width: 34px; height: 34px; border-radius: 10px; background: rgba(255,45,45,0.12);
    border: 1px solid rgba(255,45,45,0.25); display: flex; align-items: center; justify-content: center;
    color: #FF2D2D; font-size: 15px; flex-shrink: 0;
}
.pm-audio-lib-info { flex: 1; min-width: 0; }
.pm-audio-lib-title { font-size: 12px; font-weight: 600; color: rgba(255,255,255,0.85); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pm-audio-lib-sub { font-size: 10px; color: rgba(255,255,255,0.32); margin-top: 1px; }
.pm-audio-lib-play { font-size: 20px; color: rgba(255,255,255,0.3); flex-shrink: 0; }
.pm-audio-pill {
    display: flex; align-items: center; gap: 8px; padding: 10px 12px; border-radius: 50px;
    background: rgba(255,45,45,0.1); border: 1px solid rgba(255,45,45,0.28); margin-top: 6px;
}
.pm-audio-pill i.ri-music-2-fill { color: #FF2D2D; font-size: 15px; flex-shrink: 0; }
.pm-audio-pill span { flex: 1; font-size: 12px; font-weight: 600; color: rgba(255,255,255,0.85); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pm-audio-pill-play, .pm-audio-pill-remove {
    width: 25px; height: 25px; border-radius: 50%; border: none; display: flex;
    align-items: center; justify-content: center; cursor: pointer; flex-shrink: 0; font-size: 12px;
}
.pm-audio-pill-play { background: #FF2D2D; color: #fff; }
.pm-audio-pill-remove { background: rgba(255,255,255,0.08); color: rgba(255,255,255,0.5); }
.pm-audio-pill-remove:hover { background: rgba(255,0,0,0.2); color: #ff4444; }
.pm-audio-volume-controls {
    margin-top: 10px; display: flex; flex-direction: column; gap: 8px; padding: 10px 12px;
    background: rgba(0,0,0,0.2); border-radius: 13px; border: 1px solid rgba(255,255,255,0.08);
}
.pm-audio-volume-row { display: flex; align-items: center; gap: 8px; font-size: 10.5px; color: rgba(255,255,255,0.5); }
.pm-audio-volume-row span:first-child { width: 55px; flex-shrink: 0; }
.pm-audio-volume-row input[type="range"] { flex: 1; accent-color: #FF2D2D; }
.pm-audio-volume-row span:last-child { width: 26px; text-align: right; font-weight: 700; color: #fff; flex-shrink: 0; }

/* ── Zeem Connect (optional link to one of your OWN existing Zeems) ── */
.pm-zeem-block { }
.pm-zeem-toggle-btn {
    width: 100%; display: flex; align-items: center; justify-content: center; gap: 8px;
    padding: 12px; border-radius: 14px; border: 1px solid rgba(255,255,255,0.12);
    background: rgba(255,255,255,0.05); color: rgba(255,255,255,0.75); font-weight: 700; font-size: 12px;
    font-family: 'Poppins', sans-serif; cursor: pointer; transition: 0.2s;
    -webkit-tap-highlight-color: transparent;
}
.pm-zeem-toggle-btn:hover { background: rgba(255,45,45,0.08); border-color: rgba(255,45,45,0.4); color: #fff; }
.pm-zeem-toggle-btn i.ri-link { color: #FF2D2D; font-size: 15px; }
.pm-zeem-panel {
    margin-top: 10px; padding: 14px; border-radius: 16px;
    background: rgba(0,0,0,0.22); border: 1px solid rgba(255,255,255,0.08);
    animation: pmZeemFadeIn 0.2s ease;
}
@keyframes pmZeemFadeIn { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: translateY(0); } }
.pm-zeem-panel-title { font-size: 11.5px; font-weight: 700; color: rgba(255,255,255,0.75); margin-bottom: 10px; display: flex; align-items: center; gap: 6px; }
.pm-zeem-panel-title i { color: #FF2D2D; font-size: 14px; }
.pm-zeem-search-input {
    width: 100%; padding: 10px 12px; border-radius: 12px; margin-bottom: 10px;
    background: rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.08);
    color: white; font-size: 12.5px; font-family: 'Poppins', sans-serif; outline: none;
    transition: border-color 0.2s; box-sizing: border-box;
}
.pm-zeem-search-input::placeholder { color: rgba(255,255,255,0.28); }
.pm-zeem-search-input:focus { border-color: rgba(255,45,45,0.4); }
.pm-zeem-section-label { font-size: 9.5px; font-weight: 700; color: rgba(255,255,255,0.3); text-transform: uppercase; letter-spacing: 0.6px; margin-bottom: 8px; }
.pm-zeem-lib-list { max-height: 260px; overflow-y: auto; display: flex; flex-direction: column; gap: 6px; padding-right: 2px; }
.pm-zeem-lib-item { display: flex; align-items: center; gap: 10px; padding: 8px; border-radius: 13px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); cursor: pointer; transition: 0.2s; }
.pm-zeem-lib-item:hover { border-color: rgba(255,45,45,0.35); background: rgba(255,45,45,0.06); }
.pm-zeem-lib-thumb { width: 42px; height: 42px; border-radius: 10px; overflow: hidden; background: #000; flex-shrink: 0; border: 1px solid rgba(255,45,45,0.25); }
.pm-zeem-lib-thumb img, .pm-zeem-lib-thumb video { width: 100%; height: 100%; object-fit: cover; display: block; pointer-events: none; }
.pm-zeem-lib-info { flex: 1; min-width: 0; }
.pm-zeem-lib-caption { font-size: 12px; font-weight: 600; color: rgba(255,255,255,0.8); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pm-zeem-lib-type { font-size: 10px; color: rgba(255,255,255,0.32); margin-top: 1px; text-transform: capitalize; }
.pm-zeem-lib-pick { font-size: 18px; color: rgba(255,255,255,0.3); flex-shrink: 0; }
.pm-zeem-lib-gallery-badge { display: inline-block; margin-left: 6px; font-size: 9px; font-weight: 700; color: rgba(255,255,255,0.3); background: rgba(255,255,255,0.06); padding: 1px 6px; border-radius: 20px; text-transform: none; }

.pm-zeem-connected-card {
    display: flex; align-items: center; gap: 10px; padding: 10px; border-radius: 15px;
    background: rgba(77,222,138,0.08); border: 1px solid rgba(77,222,138,0.28); margin-top: 6px;
}
.pm-zeem-connected-thumb { width: 46px; height: 46px; border-radius: 11px; overflow: hidden; background: #000; flex-shrink: 0; border: 1px solid rgba(77,222,138,0.3); }
.pm-zeem-connected-thumb img, .pm-zeem-connected-thumb video { width: 100%; height: 100%; object-fit: cover; display: block; pointer-events: none; }
.pm-zeem-connected-info { flex: 1; min-width: 0; }
.pm-zeem-connected-caption { font-size: 12.5px; font-weight: 600; color: rgba(255,255,255,0.9); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pm-zeem-connected-badge { font-size: 10px; font-weight: 700; color: #4dde8a; margin-top: 2px; display: flex; align-items: center; gap: 4px; }
.pm-zeem-remove-btn { width: 24px; height: 24px; border-radius: 50%; border: none; background: rgba(255,255,255,0.08); color: rgba(255,255,255,0.5); display: flex; align-items: center; justify-content: center; cursor: pointer; flex-shrink: 0; font-size: 12px; -webkit-tap-highlight-color: transparent; }
.pm-zeem-remove-btn:hover { background: rgba(255,0,0,0.2); color: #ff4444; }

.pm-zeem-label-area { margin-top: 8px; padding: 12px; border-radius: 13px; background: rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.08); }
.pm-zeem-label-title { display: block; font-size: 10px; font-weight: 700; color: rgba(255,255,255,0.5); text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 6px; }
.pm-zeem-label-input {
    width: 100%; padding: 9px 11px; border-radius: 10px;
    background: rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.08);
    color: white; font-size: 12px; font-family: 'Poppins', sans-serif; outline: none;
    transition: border-color 0.2s; box-sizing: border-box;
}
.pm-zeem-label-input::placeholder { color: rgba(255,255,255,0.28); }
.pm-zeem-label-input:focus { border-color: rgba(77,222,138,0.4); }
.pm-zeem-label-preview { margin-top: 8px; font-size: 11px; font-weight: 700; color: rgba(255,255,255,0.6); display: flex; align-items: center; gap: 5px; }
.pm-zeem-label-preview span { color: #4dde8a; }

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
    .pm-audio-mode-btn { min-width: 70px; font-size: 10px; }
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
    .pm-audio-mode-tabs { flex-wrap: wrap; }
    .pm-audio-mode-btn { min-width: 47%; }
    .pm-actions-row    { flex-direction: column; gap: 8px; }
    .pm-draft-btn      { padding: 11px; font-size: 12px; border-radius: 12px; }
    .post-modal-submit { padding: 11px; font-size: 12px; border-radius: 12px; }
}

.pm-preview-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 8px;
}
.pm-preview-tile {
    position: relative;
    aspect-ratio: 1;
    border-radius: 12px;
    overflow: hidden;
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.08);
}
.pm-preview-tile img,
.pm-preview-tile video {
    width: 100%; height: 100%;
    object-fit: cover;
    display: block;
}
.pm-preview-remove {
    position: absolute;
    top: 4px; right: 4px;
    width: 22px; height: 22px;
    border-radius: 50%;
    background: rgba(0,0,0,0.65);
    border: none;
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px;
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
    transition: background 0.2s;
}
.pm-preview-remove:hover { background: rgba(255,45,45,0.85); }
.pm-preview-count-badge {
    grid-column: 1 / -1;
    font-size: 11px;
    color: rgba(255,255,255,0.4);
    font-family: 'Poppins', sans-serif;
    padding: 2px 2px 4px;
}

@media (max-width: 490px) {
    .pm-preview-grid { grid-template-columns: repeat(3, 1fr); gap: 6px; }
    .pm-preview-remove { width: 19px; height: 19px; font-size: 11px; }
}
@media (max-width: 380px) {
    .pm-preview-grid { grid-template-columns: repeat(3, 1fr); gap: 5px; }
}
</style>

<script>
/* ── Per-user, per-group draft storage ── */
var PM_DRAFTS_KEY = 'pm_drafts_group_<?php echo (int)$group_id; ?>_user_<?php echo (int)$current_user; ?>';
var PM_MAX_DRAFTS = 15;
var PM_MAX_FILES = 20;
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

function pmTogglePartnership(){
    var active = document.getElementById('pmPartnershipCheck').checked;
    document.getElementById('pmIsPartnership').value = active ? '1' : '0';
    document.getElementById('pmPartnershipBrandArea').style.display = active ? 'block' : 'none';
    if(!active){ document.getElementById('pmPartnerBrandInput').value = ''; }
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
        pmSelectedFiles = [];
        document.getElementById('postPreviewGrid').innerHTML = '';
        document.getElementById('postDropText').textContent = 'Choose up to 20 images or videos';
        document.getElementById('postFileInput').value = '';
        document.getElementById('captionInput').value = '';
        document.querySelectorAll('.pm-hashtag-pill').forEach(function (p) {
            p.classList.remove('pm-tag-used');
        });
        pmSetAudioMode('none');
        pmClearConnectedZeem();
        pmCloseDraftsPanel();
    }, 210);
}

var pmSelectedFiles = []; // File objects currently attached to the post

function previewPostFiles(input) {
    var newFiles = Array.prototype.slice.call(input.files || []);
    var combined = pmSelectedFiles.concat(newFiles);

    if (combined.length > PM_MAX_FILES) {
        alert('You can attach up to ' + PM_MAX_FILES + ' files per post — only the first ' + PM_MAX_FILES + ' were kept.');
        combined = combined.slice(0, PM_MAX_FILES);
    }

    pmSelectedFiles = combined;
    pmSyncFileInput();
    pmRenderPreviewGrid();
    pmUpdateAudioOriginalVisibility();
}

function pmSyncFileInput() {
    var dt = new DataTransfer();
    pmSelectedFiles.forEach(function (f) { dt.items.add(f); });
    document.getElementById('postFileInput').files = dt.files;
}

function pmRenderPreviewGrid() {
    var grid  = document.getElementById('postPreviewGrid');
    var label = document.getElementById('postDropText');

    if (!pmSelectedFiles.length) {
        grid.innerHTML = '';
        label.textContent = 'Choose up to ' + PM_MAX_FILES + ' images or videos';
        return;
    }

    label.textContent = pmSelectedFiles.length + ' file' + (pmSelectedFiles.length > 1 ? 's' : '') + ' selected';

    var tilesHtml = pmSelectedFiles.map(function (f, i) {
        var url = URL.createObjectURL(f);
        var isVideo = f.type.indexOf('video') === 0;
        return '<div class="pm-preview-tile">' +
            (isVideo
                ? '<video src="' + url + '" muted playsinline preload="metadata"></video>'
                : '<img src="' + url + '">') +
            '<button type="button" class="pm-preview-remove" onclick="pmRemoveFile(' + i + ')" aria-label="Remove">' +
                '<i class="ri-close-line"></i>' +
            '</button>' +
        '</div>';
    }).join('');

    grid.innerHTML = tilesHtml +
        '<div class="pm-preview-count-badge">' + pmSelectedFiles.length + ' / ' + PM_MAX_FILES + ' files</div>';
}

function pmRemoveFile(idx) {
    pmSelectedFiles.splice(idx, 1);
    pmSyncFileInput();
    pmRenderPreviewGrid();
    pmUpdateAudioOriginalVisibility();
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

/* ═══════════════════════════════════════════════════════════
   ATTACHED AUDIO (optional soundtrack) — mirrors upload.php
═══════════════════════════════════════════════════════════ */
var pmSelectedAudio = null; // { mode:'upload'|'library', id?, file?, url, title }
var pmPreviewPlaying = false;

function pmSetAudioMode(mode) {
    document.querySelectorAll('.pm-audio-mode-btn').forEach(function (b) { b.classList.remove('active'); });
    var btn = document.querySelector('.pm-audio-mode-btn[data-mode="' + mode + '"]');
    if (btn) btn.classList.add('active');
    document.getElementById('pmAudioMode').value = mode;

    document.getElementById('pmAudioUploadArea').style.display = mode === 'upload' ? 'block' : 'none';
    document.getElementById('pmAudioLibraryArea').style.display = mode === 'library' ? 'block' : 'none';

    if (mode === 'none' || mode === 'original') {
        pmClearAudioSelectionOnly();
    }
    pmUpdateVolumeVisibility();
}

function pmHandleAudioFile(files) {
    if (!files || !files.length) return;
    var file = files[0];
    var url = URL.createObjectURL(file);
    pmSelectedAudio = { mode: 'upload', file: file, url: url, title: file.name };
    document.getElementById('pmSelectedAudioId').value = '';
    pmShowAudioPill(file.name);
    pmUpdateVolumeVisibility();
}

function pmSelectLibraryAudio(id, title, url) {
    pmSelectedAudio = { mode: 'library', id: id, url: url, title: title };
    document.getElementById('pmSelectedAudioId').value = id;
    pmShowAudioPill(title);
    pmUpdateVolumeVisibility();
}

function pmShowAudioPill(title) {
    document.getElementById('pmAudioSelectedPill').style.display = 'flex';
    document.getElementById('pmAudioPillText').textContent = title;
    var player = document.getElementById('pmAudioPreviewPlayer');
    player.pause();
    player.src = pmSelectedAudio.url;
    pmPreviewPlaying = false;
    document.getElementById('pmAudioPreviewIcon').className = 'ri-play-fill';
}

function pmToggleAudioPreview() {
    var player = document.getElementById('pmAudioPreviewPlayer');
    if (!player.src) return;
    if (pmPreviewPlaying) { player.pause(); } else { player.play(); }
    pmPreviewPlaying = !pmPreviewPlaying;
    document.getElementById('pmAudioPreviewIcon').className = pmPreviewPlaying ? 'ri-pause-fill' : 'ri-play-fill';
    player.onended = function () {
        pmPreviewPlaying = false;
        document.getElementById('pmAudioPreviewIcon').className = 'ri-play-fill';
    };
}

function pmClearAudio() {
    pmSetAudioMode('none');
}

function pmClearAudioSelectionOnly() {
    if (pmSelectedAudio && pmSelectedAudio.mode === 'upload' && pmSelectedAudio.url) URL.revokeObjectURL(pmSelectedAudio.url);
    pmSelectedAudio = null;
    document.getElementById('pmSelectedAudioId').value = '';
    document.getElementById('pmAudioSelectedPill').style.display = 'none';
    var player = document.getElementById('pmAudioPreviewPlayer');
    player.pause(); player.src = ''; pmPreviewPlaying = false;
    document.getElementById('pmAudioFileInput').value = '';
}

function pmUpdateVolumeVisibility() {
    var mode = document.getElementById('pmAudioMode').value;
    var hasVideo = pmSelectedFiles.some(function (f) { return f.type.indexOf('video') === 0; });
    var hasTrack = (mode === 'upload' || mode === 'library') && pmSelectedAudio;
    document.getElementById('pmAudioVolumeControls').style.display = hasTrack ? 'flex' : 'none';
    document.getElementById('pmVideoAudioVolumeRow').style.display = (hasTrack && hasVideo) ? 'flex' : 'none';
}

function pmUpdateAudioOriginalVisibility() {
    var hasVideo = pmSelectedFiles.some(function (f) { return f.type.indexOf('video') === 0; });
    var origBtn = document.getElementById('pmAudioOriginalBtn');
    if (origBtn) origBtn.style.display = hasVideo ? 'flex' : 'none';
    if (!hasVideo && document.getElementById('pmAudioMode').value === 'original') {
        pmSetAudioMode('none');
    }
    pmUpdateVolumeVisibility();
}

function pmValidateAudio() {
    var mode = document.getElementById('pmAudioMode').value;
    if (mode === 'upload' && !pmSelectedAudio) {
        alert('Please select an audio file, or switch back to "No Sound".');
        return false;
    }
    if (mode === 'library' && !document.getElementById('pmSelectedAudioId').value) {
        alert('Please pick a track from the library, or switch back to "No Sound".');
        return false;
    }
    return true;
}

/* ═══════════════════════════════════════════════════════════
   ZEEM CONNECT (optional — connect this new group post to one
   of YOUR OWN existing Zeems). Only the current user's own
   Zeems are ever loaded into this picker (server-side query
   already scoped to uploaded_by = current user); the server
   re-checks ownership again on submit as a second line of
   defense.
═══════════════════════════════════════════════════════════ */
var pmMyZeems = <?php echo json_encode(array_map(function ($z) {
    return [
        'id'          => (int)$z['id'],
        'type'        => $z['type'],
        'caption'     => $z['caption'] ?: '',
        'thumb'       => ($z['type'] === 'text') ? 'uploads/Text.jpg' : 'uploads/' . $z['file_name'],
        'media_count' => (int)$z['media_count']
    ];
}, $my_zeems), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

function pmToggleZeemConnectPanel() {
    var panel = document.getElementById('pmZeemConnectPanel');
    var willOpen = panel.style.display === 'none';
    panel.style.display = willOpen ? 'block' : 'none';
    if (willOpen) {
        pmRenderZeemList(pmMyZeems);
        document.getElementById('pmZeemSearchInput').value = '';
        document.getElementById('pmZeemSearchInput').focus();
    }
}

function pmRenderZeemList(list) {
    var container = document.getElementById('pmZeemLibList');
    var empty = document.getElementById('pmZeemLibEmpty');

    if (!list.length) {
        container.innerHTML = '';
        empty.style.display = 'block';
        return;
    }
    empty.style.display = 'none';

    container.innerHTML = list.map(function (z) {
        var captionText = z.caption.trim() ? pmEsc(pmTruncateText(z.caption.trim(), 42)) : '(no caption)';
        // Multi-media posts still only show their COVER here — connecting
        // never pulls in the rest of that post's media, this badge is
        // just letting you know it's a gallery post.
        var galleryBadge = z.media_count > 1 ? '<span class="pm-zeem-lib-gallery-badge">' + z.media_count + ' items</span>' : '';
        var thumbTag = z.type === 'video'
            ? '<video src="' + z.thumb + '" muted></video>'
            : '<img src="' + z.thumb + '" onerror="this.style.display=\'none\'">';
        return (
            '<div class="pm-zeem-lib-item" onclick="pmSelectZeem(' + z.id + ')">' +
                '<div class="pm-zeem-lib-thumb">' + thumbTag + '</div>' +
                '<div class="pm-zeem-lib-info">' +
                    '<div class="pm-zeem-lib-caption">' + captionText + '</div>' +
                    '<div class="pm-zeem-lib-type">' + z.type + galleryBadge + '</div>' +
                '</div>' +
                '<i class="ri-arrow-right-s-line pm-zeem-lib-pick"></i>' +
            '</div>'
        );
    }).join('');
}

function pmFilterZeemList() {
    var q = document.getElementById('pmZeemSearchInput').value.trim().toLowerCase();
    if (!q) { pmRenderZeemList(pmMyZeems); return; }
    var filtered = pmMyZeems.filter(function (z) { return (z.caption || '').toLowerCase().indexOf(q) !== -1; });
    pmRenderZeemList(filtered);
}

function pmSelectZeem(id) {
    var zeem = pmMyZeems.find(function (z) { return z.id === id; });
    if (!zeem) return;

    document.getElementById('pmConnectedMemeId').value = zeem.id;

    // Only the cover of the connected post is ever shown/referenced here —
    // even if that post has multiple media items, we never pull the rest
    // of its media or its caption into this upload.
    var thumbTag = zeem.type === 'video'
        ? '<video src="' + zeem.thumb + '" muted></video>'
        : '<img src="' + zeem.thumb + '" onerror="this.style.display=\'none\'">';
    document.getElementById('pmZeemConnectedThumb').innerHTML = thumbTag;
    document.getElementById('pmZeemConnectedCaption').textContent = zeem.caption.trim() ? pmTruncateText(zeem.caption.trim(), 60) : '(no caption)';

    document.getElementById('pmZeemConnectedCard').style.display = 'flex';
    document.getElementById('pmZeemConnectPanel').style.display = 'none';
    document.getElementById('pmZeemToggleBtn').style.display = 'none';

    // Reset the custom label whenever a (new) Zeem is connected.
    document.getElementById('pmZeemLabelInput').value = '';
    document.getElementById('pmConnectedLabel').value = '';
    document.getElementById('pmZeemLabelPreview').textContent = 'Connected Zeem';
    document.getElementById('pmZeemLabelArea').style.display = 'block';
}

function pmClearConnectedZeem() {
    document.getElementById('pmConnectedMemeId').value = '';
    document.getElementById('pmConnectedLabel').value = '';
    document.getElementById('pmZeemLabelInput').value = '';
    document.getElementById('pmZeemLabelPreview').textContent = 'Connected Zeem';
    document.getElementById('pmZeemLabelArea').style.display = 'none';
    document.getElementById('pmZeemConnectedCard').style.display = 'none';
    document.getElementById('pmZeemToggleBtn').style.display = 'flex';
    document.getElementById('pmZeemConnectPanel').style.display = 'none';
    var search = document.getElementById('pmZeemSearchInput');
    if (search) search.value = '';
}

/* Custom button label — what the viewer sees is "▶ View · {this}".
   Blank means the feed template falls back to "▶ View · Connected Zeem". */
function pmSyncZeemLabel() {
    var val = document.getElementById('pmZeemLabelInput').value.trim();
    document.getElementById('pmConnectedLabel').value = val;
    document.getElementById('pmZeemLabelPreview').textContent = val ? val : 'Connected Zeem';
}

function pmTruncateText(str, max) {
    return str.length > max ? str.slice(0, max - 1) + '…' : str;
}

/* ══════════════════════════════════════════════════════════
   SUBMIT VALIDATION + DRAFT CLEANUP
   FIX #1 (bug 1): hashtag is now checked client-side before the
   form is allowed to submit at all — previously this only ran
   pmValidateAudio() and let a caption with no "#" go straight to
   the server, where PHP would reject it into $upload_error with
   no way for the (closed) modal to ever show that message.
══════════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('postModalForm');
    if (form) {
        form.addEventListener('submit', function (e) {
            var captionVal = document.getElementById('captionInput').value;
            if (captionVal.indexOf('#') === -1) {
                e.preventDefault();
                var hint = document.getElementById('hashtagHint');
                if (hint) {
                    hint.textContent = 'Must include at least one #hashtag to post';
                    hint.className = 'hashtag-hint invalid';
                }
                document.getElementById('captionInput').focus();
                return;
            }
            if (!pmValidateAudio()) {
                e.preventDefault();
                return;
            }
            var input = document.getElementById('captionInput');
            if (!input) return;
            var drafts = pmLoadDrafts().filter(function (d) {
                return d.caption.trim() !== input.value.trim();
            });
            pmSaveDraftsArr(drafts);
        });
    }
    pmUpdateDraftsBadge();

    /* FIX #2 (bug 1, server side of the same fix): if PHP rejected
       the last upload attempt and set $upload_error, the modal
       needs to reopen itself on page load so the red error banner
       inside .pm-body is actually visible — otherwise it's sitting
       inside a display:none modal and the user sees nothing. */
    <?php if (!empty($upload_error)): ?>
    openPostModal();
    <?php endif; ?>
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

$_gf_media_map = [];
if (!empty($group_feed_rows)) {
    $gf_meme_ids = array_unique(array_column($group_feed_rows, 'meme_id'));
    if (!empty($gf_meme_ids)) {
        $gf_meme_id_str = implode(',', array_map('intval', $gf_meme_ids));
        $mm_res = $conn->query("
            SELECT meme_id, file_name, type, position
            FROM meme_media
            WHERE meme_id IN ($gf_meme_id_str)
            ORDER BY meme_id ASC, position ASC
        ");
        if ($mm_res) {
            while ($row = $mm_res->fetch_assoc()) {
                $_gf_media_map[(int)$row['meme_id']][] = [
                    'file' => $row['file_name'],
                    'type' => $row['type'],
                ];
            }
        }
    }
}

$_gf_audio_map = [];
if (!empty($group_feed_rows)) {
    $gf_meme_ids2 = array_unique(array_column($group_feed_rows, 'meme_id'));
    if (!empty($gf_meme_ids2)) {
        $gf_meme_id_str2 = implode(',', array_map('intval', $gf_meme_ids2));

        $au_res = $conn->query("
            SELECT ma.meme_id, a.id AS audio_id, a.title, a.artist, a.file_name,
                   ma.is_original, ma.music_volume, ma.video_audio_volume
            FROM meme_audio ma
            JOIN audios a ON a.id = ma.audio_id
            WHERE ma.meme_id IN ($gf_meme_id_str2)
        ");
        if ($au_res) while ($au = $au_res->fetch_assoc())
            $_gf_audio_map[(int)$au['meme_id']] = $au;

        foreach ($group_feed_rows as $grow) {
            $gmid = (int)$grow['meme_id'];
            if (($grow['type'] ?? '') !== 'video') continue;
            if (isset($_gf_audio_map[$gmid])) continue;

            $existing = $conn->query("SELECT id FROM audios WHERE source_meme_id=$gmid LIMIT 1")->fetch_assoc();
            if ($existing) {
                $aid = (int)$existing['id'];
            } else {
                $uploader_id = (int)$grow['user_id'];
                $file_name   = $conn->real_escape_string($grow['file_name']);
                $conn->query("
                    INSERT INTO audios (uploader_id, title, file_name, is_original, source_meme_id, created_at)
                    VALUES ($uploader_id, 'Original audio', '$file_name', 1, $gmid, NOW())
                ");
                $aid = $conn->insert_id;
                if ($aid) {
                    $conn->query("
                        INSERT INTO meme_audio (meme_id, audio_id, is_original, music_volume, video_audio_volume)
                        VALUES ($gmid, $aid, 1, 100, 100)
                    ");
                }
            }
            if ($aid) {
                $_gf_audio_map[$gmid] = [
                    'meme_id' => $gmid, 'audio_id' => $aid, 'title' => 'Original audio',
                    'artist' => null, 'file_name' => $grow['file_name'], 'is_original' => 1,
                    'music_volume' => 100, 'video_audio_volume' => 100,
                ];
            }
        }
    }
}
?>

<!-- ═══════════════════════════════════════════════════════
     GF FEED — home.php-style post cards. Desktop: 3-per-row grid,
     near-zero gap, sharp corners, no border. Mobile: single-column,
     full-bleed edge-to-edge, infinite scroll — proportions/sizing
     mirror home.php exactly, including per-post dynamic aspect
     ratio for single-media posts. Cards: "gf-post" (never "ipv").
     ═══════════════════════════════════════════════════════ -->
<style>
/* ═══════════ FEED CONTAINER ═══════════ */
.gf-feed{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:3px;
    padding:12px 0 40px;
}
@media(max-width:1024px){ .gf-feed{ grid-template-columns:repeat(2,1fr); gap:3px; } }
@media(max-width:768px){
    .gf-feed{
        display:block;
        gap:0;
        padding:0 0 30px;
        width:100vw;
        margin-left:calc(50% - 50vw);
        margin-right:calc(50% - 50vw);
    }
}

/* ═══════════ CARD SHELL — sharp corners, zero colored border ═══════════ */
.gf-card-unit{ display:flex; flex-direction:column; }
.gf-post{
    background:#070924;
    border:none;
    border-radius:0;
    overflow:hidden;
    scroll-margin-top:64px;
}
@media(max-width:768px){
    .gf-post{
        border-bottom:3px solid #02030e;
    }
    .gf-feed > .gf-card-unit:first-child .gf-post{ border-top:3px solid #02030e; }
}

/* ═══════════ HEADER ═══════════ */
.gf-header{ display:flex; align-items:center; gap:10px; padding:11px 12px; }
.gf-avatar{ width:34px; height:34px; border-radius:50%; object-fit:cover; border:1.6px solid #FF2D2D; cursor:pointer; flex-shrink:0; }
.gf-header-info{ flex:1; min-width:0; display:flex; flex-direction:column; }
.gf-username-row{ display:flex; align-items:center; gap:6px; min-width:0; margin-bottom:2px; }
.gf-username{ font-size:13px; font-weight:700; color:#fff; cursor:pointer; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.gf-admin-chip{ color:#FFD700; font-size:9px; font-weight:700; background:rgba(255,215,0,.1); padding:1px 6px; border-radius:8px; border:1px solid rgba(255,215,0,.3); flex-shrink:0; }
.gf-new-tag{ color:#FF2D2D; font-size:11px; font-weight:800; flex-shrink:0; }
.gf-dot-sep{ color:rgba(255,255,255,.35); font-size:12px; flex-shrink:0; }
.gf-track-btn{ background:none; border:none; padding:0; color:#FF2D2D; font-size:12px; font-weight:700; cursor:pointer; flex-shrink:0; text-decoration:underline; text-underline-offset:2px; -webkit-appearance:none; appearance:none; }
.gf-track-btn.following{ color:#FF2D2D; }
.gf-kebab{ background:none; border:none; box-shadow:none; outline:none; -webkit-appearance:none; appearance:none; color:#fff; font-size:20px; padding:6px; flex-shrink:0; -webkit-tap-highlight-color:transparent; }
/* ── nickname line, exactly like home.php's .ipv-sub-line ── */
.gf-sub-line{ font-size:11px; color:rgba(220,224,255,.55); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

/* ═══════════ MEDIA — dynamic aspect ratio (single-media posts),
   fixed standard ratio (multi-media posts), matching home.php's
   hpMeasure()/applyAspect() behaviour exactly. ═══════════ */
.gf-media-wrap{ position:relative; width:100%; background:#000; overflow:hidden; aspect-ratio:4/4.2; transition:height .18s ease; touch-action:pan-y; }
.gf-media-wrap.gf-multi{ aspect-ratio:4/3.5; }
.gf-media-track{ display:flex; height:100%; transition:transform .28s cubic-bezier(.22,1,.36,1); will-change:transform; }
.gf-media-item{ flex:0 0 100%; height:100%; position:relative; background:#000; }
.gf-media-item img,.gf-media-item video{ width:100%; height:100%; object-fit:cover; display:block; cursor:pointer; -webkit-user-drag:none; user-select:none; }
.gf-media-wrap.gf-multi .gf-media-item{ display:flex; align-items:center; justify-content:center; }
.gf-media-wrap.gf-multi .gf-media-item img,.gf-media-wrap.gf-multi .gf-media-item video{ object-fit:contain; background:#000; }
.gf-media-wrap.gf-multi .gf-media-item img.gf-fit-cover,.gf-media-wrap.gf-multi .gf-media-item video.gf-fit-cover{ object-fit:cover; }

.gf-dots{ position:absolute; left:0; right:0; bottom:10px; z-index:6; display:flex; align-items:center; justify-content:center; gap:5px; }
.gf-dot{ width:5px; height:5px; border-radius:50%; background:rgba(255,255,255,.35); box-shadow:0 0 3px rgba(0,0,0,.65); transition:.18s; flex-shrink:0; }
.gf-dot.active{ background:#FF2D2D; transform:scale(1.3); }

/* ── arrows: fully custom-styled circle, no native button chrome ── */
.gf-media-arrow{
    position:absolute; top:50%; transform:translateY(-50%); z-index:6;
    width:32px; height:32px; border-radius:50%;
    background:rgba(0,0,0,.42); color:#fff;
    display:flex; align-items:center; justify-content:center;
    border:none; box-shadow:none; outline:none;
    -webkit-appearance:none; appearance:none;
    padding:0; margin:0; cursor:pointer;
    -webkit-tap-highlight-color:transparent;
}
.gf-media-arrow i{ font-size:20px; line-height:1; display:block; }
.gf-arrow-left{ left:10px; } .gf-arrow-right{ right:10px; }

.gf-top-right-tags{ position:absolute; top:10px; right:10px; z-index:6; display:flex; flex-direction:column; align-items:flex-end; gap:5px; }
.gf-media-counter{ background:rgba(22,22,24,.78); color:#fff; font-size:9px; font-weight:800; padding:3px 7px; border-radius:5px; }
.gf-type-tag{ display:flex; align-items:center; gap:4px; background:rgba(22,22,24,.78); border-radius:5px; padding:3px 7px; }
.gf-type-tag i{ font-size:10.5px; color:#fff; }
.gf-type-tag span{ font-size:9px; font-weight:700; color:#fff; text-transform:uppercase; }

.gf-multi-badge{ position:absolute; top:10px; left:10px; z-index:6; display:flex; align-items:center; gap:4px; background:rgba(0,0,0,.55); color:#fff; font-size:10px; font-weight:700; padding:4px 8px; border-radius:20px; pointer-events:none; }

.gf-social-proof{ position:absolute; left:12px; bottom:36px; z-index:6; display:flex; flex-direction:row-reverse; gap:7px; }
.gf-social-proof.gf-sp-raised{ bottom:70px; }
.gf-sp-wrap{ position:relative; display:inline-block; text-decoration:none; animation:gfSpFloat 3s ease-in-out infinite; }
@keyframes gfSpFloat{ 0%,100%{transform:translateY(0)} 50%{transform:translateY(-6px)} }
.gf-sp-avatar{ width:36px; height:36px; border-radius:50%; object-fit:cover; border:2.5px solid #FF2D2D; display:block; box-shadow:0 4px 12px rgba(0,0,0,.7); }
.gf-sp-badge{ position:absolute; bottom:-2px; right:-2px; width:15px; height:15px; border-radius:50%; display:flex; align-items:center; justify-content:center; }
.gf-sp-badge i{ font-size:8px; color:#fff; }

/* ── PARTNERSHIP TAG — matched 1:1 to home.php's .ipv-partnership-tag,
   same base size and same responsive shrink at 790/490/380/330 ── */
.gf-partnership-tag{
    position:absolute; z-index:7; left:12px; bottom:34px;
    display:inline-flex; align-items:center; gap:6px;
    padding:5px 10px; border-radius:8px;
    background:rgba(0,0,0,.55); color:#ff4c4c;
    font-size:11px; font-weight:600;
    max-width:calc(100% - 24px);
    backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px);
    pointer-events:none;
}
.gf-partnership-tag i{ font-size:13px; flex-shrink:0; }
.gf-partnership-tag b{ color:#fff; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

/* ── DISCUSS BUTTON — raised clear of the dots; also lifts further
   when a partnership tag/social-proof strip shares the corner ── */
.gf-discuss-btn{
    position:absolute; right:10px; bottom:36px; z-index:7;
    display:inline-flex; align-items:center; gap:5px;
    background:rgba(10,10,14,.72);
    backdrop-filter:blur(8px); -webkit-backdrop-filter:blur(8px);
    border:1px solid rgba(255,255,255,.14);
    border-radius:999px; padding:6px 13px;
    color:#ff3b3b; font-family:Poppins,sans-serif; font-size:11.5px; font-weight:700;
    cursor:pointer; -webkit-tap-highlight-color:transparent;
    -webkit-appearance:none; appearance:none; box-shadow:none;
    transition:background .2s,border-color .2s,transform .15s;
}
.gf-discuss-btn i{ font-size:12.5px; color:#ff3b3b; }
.gf-discuss-btn:active{ transform:scale(.94); }

.gf-double-tap-heart{ position:absolute; z-index:14; pointer-events:none; transform:translate(-50%,-50%) scale(0); opacity:0; }
.gf-double-tap-heart i{ font-size:78px; color:#ff2d4f; filter:drop-shadow(0 6px 20px rgba(255,45,79,.6)); -webkit-text-stroke:1.2px rgba(255,255,255,.3); }
.gf-double-tap-heart.show{ animation:gfHeartBurst .9s cubic-bezier(.17,.89,.32,1.28) forwards; }
@keyframes gfHeartBurst{
    0%{transform:translate(-50%,-50%) scale(0) rotate(-25deg);opacity:0;}
    16%{transform:translate(-50%,-50%) scale(1.3) rotate(8deg);opacity:1;}
    40%{transform:translate(-50%,-50%) scale(.9);opacity:1;}
    100%{transform:translate(-50%,-50%) scale(1.1) translateY(-35px);opacity:0;}
}

/* ═══════════ ACTIONS ═══════════ */
.gf-actions{ display:flex; align-items:center; justify-content:space-between; padding:9px 10px 0; }
.gf-actions-left{ display:flex; align-items:center; gap:18px; }
.gf-action-group{ display:flex; align-items:center; gap:6px; }
.gf-action-btn{
    background:none; border:none; box-shadow:none; outline:none;
    -webkit-appearance:none; appearance:none;
    color:#fff; font-size:22px; padding:2px;
    display:flex; align-items:center; transition:transform .12s ease;
    -webkit-tap-highlight-color:transparent;
}
.gf-action-btn:active{ transform:scale(1.22); }
.gf-action-btn.active{ color:#ff2a2a; }
.gf-action-count{ font-size:12px; font-weight:700; color:#fff; }
@keyframes gfRepostArcSwap{0%{transform:rotate(0) scale(1);}35%{transform:rotate(140deg) scale(1.15);}50%{transform:rotate(180deg) scale(1.2);}65%{transform:rotate(220deg) scale(1.15);}100%{transform:rotate(360deg) scale(1);}}
.gf-action-btn.arc-swap-active i{ animation:gfRepostArcSwap .6s cubic-bezier(.33,1,.68,1); transition:none !important; }

/* ═══════════ SOCIAL / CAPTION / TIME ═══════════ */
.gf-social-row{ display:flex; align-items:center; gap:7px; padding:6px 12px 0; cursor:pointer; -webkit-tap-highlight-color:transparent; }
.gf-social-avatars{ display:flex; flex-shrink:0; }
.gf-social-avatars img{ width:20px; height:20px; border-radius:50%; object-fit:cover; border:1.6px solid rgba(200,203,214,.65); margin-left:-7px; display:block; }
.gf-social-avatars img:first-child{ margin-left:0; }
.gf-likes-text{ font-size:12.5px; color:#fff; }
.gf-likes-text b{ font-weight:700; } .gf-likes-dim{ color:rgba(220,224,255,.72); font-weight:400; }
.gf-caption{ padding:5px 12px 0; font-size:12.5px; line-height:1.5; color:#fff; word-break:break-word; }
.gf-cap-user{ margin-right:5px; color:#FF2D2D; font-weight:700; }
.gf-more-toggle{ color:#FF2D2D; cursor:pointer; font-weight:700; }
.gf-time{ padding:6px 12px 0; font-size:10px; color:rgba(220,224,255,.4); letter-spacing:.3px; padding-bottom:12px; }

/* ═══════════ SKELETON ═══════════ */
.gf-skel-post{ background:#070924; border-radius:0; overflow:hidden; }
@media(max-width:768px){ .gf-skel-post{ border-bottom:3px solid #02030e; } }
.gf-skel-header{ display:flex; align-items:center; gap:10px; padding:11px 12px; }
.gf-skel-avatar{ width:34px; height:34px; border-radius:50%; flex-shrink:0; }
.gf-skel-lines{ flex:1; display:flex; flex-direction:column; gap:6px; }
.gf-skel-line{ height:10px; border-radius:5px; } .gf-skel-line.w1{ width:120px; } .gf-skel-line.w2{ width:75px; height:8px; }
.gf-skel-media{ width:100%; aspect-ratio:4/4.2; }
.gf-skel-actions{ display:flex; gap:18px; padding:9px 12px 0; } .gf-skel-icon{ width:22px; height:22px; border-radius:6px; }
.gf-skel-cap{ margin:11px 12px 12px; height:10px; width:70%; border-radius:5px; }
.gf-skel-avatar,.gf-skel-line,.gf-skel-media,.gf-skel-icon,.gf-skel-cap{
    background:linear-gradient(90deg,rgba(255,255,255,.05) 25%,rgba(255,255,255,.13) 37%,rgba(255,255,255,.05) 63%);
    background-size:400% 100%; animation:gfSkelShimmer 1.4s ease-in-out infinite;
}
@keyframes gfSkelShimmer{ 0%{background-position:100% 50%;} 100%{background-position:0% 50%;} }
.gf-load-more{ display:flex; align-items:center; justify-content:center; padding:24px 0; grid-column:1/-1; }
.gf-load-dot{ width:6px; height:6px; border-radius:50%; background:rgba(255,255,255,.35); margin:0 3px; animation:gfLoadDot 1.1s ease-in-out infinite; }
.gf-load-dot:nth-child(2){ animation-delay:.15s; } .gf-load-dot:nth-child(3){ animation-delay:.3s; }
@keyframes gfLoadDot{ 0%,80%,100%{transform:scale(.6);opacity:.4;} 40%{transform:scale(1);opacity:1;} }
.gf-empty{ padding:70px 24px; text-align:center; color:rgba(220,224,255,.5); font-size:13.5px; grid-column:1/-1; }
.gf-empty i{ font-size:42px; display:block; margin-bottom:10px; color:rgba(255,255,255,.25); }

/* ═══════════ HIDDEN PLACEHOLDER (kept for gfHidePost compat) ═══════════ */
.gf-hidden-placeholder{ display:flex; align-items:center; justify-content:space-between; gap:12px; width:100%; min-height:56px; border-radius:0; background:#010723; padding:12px 14px 12px 18px; box-sizing:border-box; }
.gf-hidden-left{ display:flex; align-items:center; gap:9px; min-width:0; }
.gf-hidden-icon{ width:30px; height:30px; border-radius:50%; background:rgba(255,45,45,.1); border:1.5px solid rgba(255,45,45,.3); display:flex; align-items:center; justify-content:center; font-size:14px; color:#ff5c5c; flex-shrink:0; }
.gf-hidden-text{ font-size:13px; font-weight:600; color:rgba(255,255,255,.75); }
.gf-undo-btn{ display:inline-flex; align-items:center; gap:5px; background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.15); color:#fff; font-size:12px; font-weight:700; padding:7px 15px; border-radius:8px; cursor:pointer; flex-shrink:0; -webkit-appearance:none; }

/* ═══════════════════════════════════════════════
   RESPONSIVE — mirrors home.php's ipv-post exactly:
   790 / 490 / 380 / 330
   ═══════════════════════════════════════════════ */
@media(max-width:790px){
    .gf-header{ padding:10px 11px; gap:9px; }
    .gf-avatar{ width:32px; height:32px; }
    .gf-username{ font-size:12.5px; }
    .gf-sub-line{ font-size:10.5px; }
    .gf-kebab{ font-size:19px; }
    .gf-actions{ padding:8px 9px 0; }
    .gf-action-btn{ font-size:21px; }
    .gf-actions-left{ gap:15px; }
    .gf-social-row,.gf-caption,.gf-time{ padding-left:11px; padding-right:11px; }
    .gf-caption{ font-size:12px; }
    .gf-social-proof{ bottom:33px; }
    .gf-social-proof.gf-sp-raised{ bottom:62px; }
    .gf-partnership-tag{ font-size:10.5px; padding:4.5px 9px; left:11px; bottom:30px; gap:5px; }
    .gf-partnership-tag i{ font-size:12.5px; }
    .gf-discuss-btn{ bottom:33px; right:9px; font-size:11px; padding:5.5px 12px; }
    .gf-sp-avatar{ width:33px; height:33px; }
    .gf-media-arrow{ width:30px; height:30px; }
    .gf-media-arrow i{ font-size:18px; }
    .gf-double-tap-heart i{ font-size:66px; }
}
@media(max-width:490px){
    .gf-header{ padding:9px 10px; gap:8px; }
    .gf-avatar{ width:29px; height:29px; }
    .gf-username{ font-size:12px; }
    .gf-sub-line{ font-size:10px; }
    .gf-track-btn{ font-size:11px; }
    .gf-kebab{ font-size:18px; }
    .gf-actions{ padding:7px 8px 0; }
    .gf-actions-left{ gap:13px; }
    .gf-action-btn{ font-size:19px; }
    .gf-action-count{ font-size:11px; }
    .gf-social-avatars img{ width:17px; height:17px; margin-left:-6px; }
    .gf-likes-text{ font-size:11.5px; }
    .gf-social-row,.gf-caption,.gf-time{ padding-left:10px; padding-right:10px; }
    .gf-caption{ font-size:11.5px; }
    .gf-partnership-tag{ font-size:10px; padding:4px 8px; left:10px; bottom:26px; }
    .gf-social-proof{ left:10px; bottom:26px; }
    .gf-social-proof.gf-sp-raised{ bottom:58px; }
    .gf-discuss-btn{ bottom:26px; right:8px; font-size:10.5px; padding:5px 11px; }
    .gf-sp-avatar{ width:30px; height:30px; }
    .gf-top-right-tags,.gf-multi-badge{ top:8px; }
    .gf-top-right-tags{ right:8px; } .gf-multi-badge{ left:8px; }
    .gf-media-arrow{ width:27px; height:27px; }
    .gf-media-arrow i{ font-size:16px; }
    .gf-arrow-left{ left:8px; } .gf-arrow-right{ right:8px; }
    .gf-double-tap-heart i{ font-size:58px; }
}
@media(max-width:380px){
    .gf-avatar{ width:27px; height:27px; }
    .gf-sub-line{ font-size:9.5px; }
    .gf-action-btn{ font-size:18px; }
    .gf-actions-left{ gap:11px; }
    .gf-social-proof,.gf-discuss-btn{ bottom:22px; }
    .gf-social-proof.gf-sp-raised{ bottom:54px; }
    .gf-partnership-tag{ font-size:10px; padding:4px 8px; bottom:22px; left:10px; gap:5px; }
    .gf-partnership-tag i{ font-size:12px; }
    .gf-caption,.gf-header-caption{ font-size:11px; }
    .gf-sp-avatar{ width:28px; height:28px; }
    .gf-discuss-btn{ font-size:10px; padding:4.5px 10px; }
}
@media(max-width:330px){
    .gf-avatar{ width:25px; height:25px; }
    .gf-action-btn{ font-size:17px; }
    .gf-actions-left{ gap:9px; }
    .gf-social-proof,.gf-discuss-btn{ bottom:20px; }
    .gf-social-proof.gf-sp-raised{ bottom:48px; }
    .gf-partnership-tag{ bottom:20px; }
}
</style>

<div class="gf-feed" id="groupFeed"></div>
<audio id="gfAudioPlayer" loop muted style="display:none"></audio>

<script>var peuraGroupFeed = [];</script>
<?php
foreach($group_feed_rows as $p) {
    $mid   = (int)$p['meme_id'];
    $mtype = $p['type'] ?? 'image';
    $mfile = !empty($p['file_name']) ? 'uploads/' . $p['file_name'] : '';
    $mpic  = !empty($p['profile_pic']) ? $p['profile_pic'] : 'uploads/me.jpeg';
    $muid  = (int)$p['user_id'];
    $is_following_js = !empty($_gf_follow_map[$muid]) ? 'true' : 'false';
    $poster_is_admin_js = isset($admins[$muid]) ? 'true' : 'false';

    $gf_audio_info = $_gf_audio_map[$mid] ?? null;
    $gf_connected_mid   = !empty($p['connected_meme_id']) ? (int)$p['connected_meme_id'] : null;
    $gf_connected_label = !empty($p['connected_label']) ? $p['connected_label'] : null;

    $gf_media_items = [];
    if (!empty($mfile)) $gf_media_items[] = ['file' => $mfile, 'type' => $mtype];
    if (!empty($_gf_media_map[$mid])) {
        foreach ($_gf_media_map[$mid] as $extra) {
            $gf_media_items[] = ['file' => 'uploads/' . $extra['file'], 'type' => $extra['type']];
        }
    }

    $sp_for_this_meme = array_map(function($sp){
        return [
            'id'          => (int)$sp['id'],
            'username'    => $sp['username'],
            'profile_pic' => $sp['profile_pic'],
            'action_type' => $sp['action_type'],
        ];
    }, $group_social_proof_map[$mid] ?? []);

    echo '<script>peuraGroupFeed.push({
        id:          ' . $mid . ',
        groupPostId: ' . (int)$p['group_post_id'] . ',
        file:        "' . addslashes($mfile) . '",
        type:        "' . addslashes($mtype) . '",
        media:       ' . json_encode($gf_media_items) . ',
        pic:         "' . addslashes($mpic) . '",
        userId:      "' . $muid . '",
        username:    "' . addslashes(htmlspecialchars($p['username'])) . '",
        nickname:    "' . addslashes($p['nickname'] ?? '') . '",
        caption:     "' . addslashes($p['caption']) . '",
        time:        "' . addslashes($p['post_created']) . '",
        likeCount:   ' . (int)$p['like_count'] . ',
        repostCount: ' . (int)$p['repost_count'] . ',
        favCount:    ' . (int)$p['favorites_count'] . ',
        shareCount:  ' . (int)$p['share_count'] . ',
        liked:       ' . ($p['is_liked'] ? 'true' : 'false') . ',
        reposted:    ' . ($p['is_reposted'] ? 'true' : 'false') . ',
        faved:       ' . ($p['is_favorited'] ? 'true' : 'false') . ',
        following:   ' . $is_following_js . ',
        isAdmin:     ' . $poster_is_admin_js . ',
        isPartnership: ' . (!empty($p['is_partnership']) ? 'true' : 'false') . ',
        partnerBrand:  ' . (!empty($p['partner_brand']) ? '"' . addslashes($p['partner_brand']) . '"' : 'null') . ',
        connectedMemeId: ' . ($gf_connected_mid !== null ? $gf_connected_mid : 'null') . ',
        connectedLabel:  ' . ($gf_connected_label !== null ? '"' . addslashes($gf_connected_label) . '"' : 'null') . ',
        audioId:     ' . ($gf_audio_info ? (int)$gf_audio_info['audio_id'] : 'null') . ',
        audioTitle:  ' . ($gf_audio_info ? '"' . addslashes($gf_audio_info['title']) . '"' : 'null') . ',
        audioArtist: ' . ($gf_audio_info && !empty($gf_audio_info['artist']) ? '"' . addslashes($gf_audio_info['artist']) . '"' : 'null') . ',
        audioFile:   ' . ($gf_audio_info ? '"uploads/' . addslashes($gf_audio_info['file_name']) . '"' : 'null') . ',
        audioIsOriginal: ' . ($gf_audio_info && !empty($gf_audio_info['is_original']) ? 'true' : 'false') . ',
        musicVolume: ' . ($gf_audio_info ? (int)$gf_audio_info['music_volume'] : 80) . ',
        videoAudioVolume: ' . ($gf_audio_info ? (int)$gf_audio_info['video_audio_volume'] : 40) . ',
        socialProof: ' . json_encode($sp_for_this_meme) . '
    });</script>';
}
?>

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
var GF_IS_OWNER  = <?php echo $is_owner ? 'true' : 'false'; ?>;
var GF_IS_ADMIN  = <?php echo $is_admin ? 'true' : 'false'; ?>;
var GF_IS_MEMBER = <?php echo $is_member ? 'true' : 'false'; ?>;
/* NEW — mirrors the $gf_paused_locked pattern used elsewhere on this page:
   paused AND viewer is neither owner nor admin means gated features
   (Invite, Discussions nav link, and now this Discuss button) are locked. */
var GF_PAUSE_LOCKED = <?php echo ($group_is_paused && !($is_owner || $is_admin)) ? 'true' : 'false'; ?>;
var GF_CSRF      = CSRF_TOKEN; // already defined globally in group.php

function _gfSheetHTML(d, flags){
    var items = [];
    var safeCaption = (d.caption || '').replace(/\\/g,'\\\\').replace(/'/g,"\\'");

    items.push('<div class="idx-sheet-item" onclick="gfCopyCaption(\''+safeCaption+'\');closeGfSheet();"><i class="ri-file-copy-line"></i> Copy Caption</div>');

    if(d.type !== 'text' && d.file){
        items.push('<a class="idx-sheet-item" href="'+d.file+'" download><i class="ri-download-2-line"></i> Download</a>');
    }

    items.push('<div class="idx-sheet-item" onclick="closeGfSheet();gfdOpenDiscuss('+d.id+');"><i class="ri-discuss-line"></i> Discuss</div>');
    items.push('<div class="idx-sheet-item" onclick="gfShowMemeQR('+d.id+');closeGfSheet();"><i class="ri-qr-code-line"></i> QR Code</div>');
    items.push('<a class="idx-sheet-item" href="account.php?user_id='+d.userId+'"><i class="ri-user-search-line"></i> About Account</a>');
    items.push('<div class="idx-sheet-item" onclick="gfShareMeme('+d.id+');closeGfSheet();"><i class="ri-share-line"></i> Share Post</div>');
    items.push('<div class="idx-sheet-item" onclick="closeGfSheet();openSaveModal('+d.id+');"><i class="ri-bookmark-line"></i> Add to Collection</div>');
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
    var d = _gfdFindPostById(memeId);   // ← was: peuraGroupFeed.find(...)
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

/* ── Universal post lookup — checks the vertical feed first, then
   whatever's currently sitting in the Connected-Zeem stack, then
   the raw fetch cache. Needed because a connected post's data never
   lives in peuraGroupFeed. ── */
function _gfdFindPostById(memeId){
    var id = String(memeId);
    var found = peuraGroupFeed.find(function(p){ return String(p.id) === id; });
    if(found) return found;
    for (var i = _gfdHStack.length - 1; i >= 0; i--) {
        if (String(_gfdHStack[i].item.id) === id) return _gfdHStack[i].item;
    }
    if (_gfdConnectedCache[id]) return _gfdConnectedCache[id];
    return null;
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

/* ── DISCUSS — carries this meme's meme_id over to discussions.php,
     which prefills the create-discussion modal with this post's media,
     caption, and a default (changeable) tag. Members-only; non-members
     get the same lock-toast used elsewhere for gated pod features.
     NEW: also blocked while the pod is paused (unless the viewer is
     owner/admin), matching Invite / Discussions-nav / Events / Files. ── */
function gfdOpenDiscuss(memeId){
    if (!GF_IS_MEMBER) {
        if (typeof showDiscLockToast === 'function') {
            showDiscLockToast('Join this pod to access Discussions');
        }
        return;
    }
    if (GF_PAUSE_LOCKED) {
        if (typeof showDiscLockToast === 'function') {
            showDiscLockToast('This pod is paused — check back later');
        }
        return;
    }
    window.location.href = 'discussions.php?pod_id=' + gfGroupId + '&prefill_meme_id=' + memeId;
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

<!-- MODAL — discover-style full-bleed player, reskinned for group feed -->
<div id="gfModal" onclick="if(event.target===this)closeGFModal()">
    <div id="gfd-stage">
        <!-- REPLACE WITH -->
       <button type="button" id="gfdFixedBackBtn" class="gfd-icon-btn gfd-back-btn"
        onclick="event.stopPropagation();gfdHandleBackTap()">
        <i class="ri-arrow-left-s-line"></i>
       </button>
        <div id="gfd-inner"></div>
    </div>

    <div id="gfd-arrows">
        <button type="button" onclick="gfmLoadIndex(_gfmIndex-1)" title="Previous">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.85)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"/></svg>
        </button>
        <button type="button" onclick="gfmLoadIndex(_gfmIndex+1)" title="Next">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.85)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
    </div>
</div>

<style>
/* ══════════════════════════════════════════════════════════
   GF MODAL — REELS/TIKTOK STYLE FULL-SCREEN PLAYER
   Now ported onto the SAME gapless vertical-scroll engine as the
   discover/CCM modal: #gfd-inner hosts a #gfd-vtrack containing up
   to 3 .gfd-vslide wrappers (prev/current/next), each already fully
   rendered before the person ever swipes. Dragging moves the whole
   track by the raw touch delta, so the neighbor post is visibly
   there under your finger the instant you start dragging — no
   black gap, no rebuild-on-release. On a committed swipe, the
   already-mounted neighbor is promoted to "current" (its media
   element reused as-is, already decoded) — only the now-vacant
   side gets a fresh, silent, paused slide built in the background.
   Chrome (top bar, tags, rail, bottom panel) dims and drifts during
   the drag and snaps to full brightness once a post settles as
   current — identical to CCM.
   ══════════════════════════════════════════════════════════ */

#gfModal{
    display:none;
    position:fixed;inset:0;
    z-index:9999999;
    background:#000;
    align-items:center;
    justify-content:center;
}
#gfModal.gfm-open{ display:flex; }

#gfd-stage{
    position:relative;
    width:100%;
    height:100%;
    max-width:480px;
    background:#000;
    overflow:hidden;
    touch-action:pan-y;
    /* NEW — forces a clean GPU layer so the border-radius clip
       is recomputed correctly instead of leaking video content */
    transform: translateZ(0);
    -webkit-mask-image: -webkit-radial-gradient(white, black);
}

@media(min-width:791px){
    #gfd-stage{
        height:92vh;
        max-height:940px;
        border-radius:22px;
        box-shadow:0 25px 90px rgba(0,0,0,.65), 0 0 0 1px rgba(255,255,255,.06);
    }
}

#gfd-inner{ position:relative; width:100%; height:100%; overflow:hidden; }

/* ── vertical track: holds prev/current/next slides stacked so a
   swipe just translates this one element — nothing is destroyed
   or rebuilt mid-drag. Identical structure to CCM's .ccm-vtrack. ── */
.gfd-vtrack{ position:absolute; inset:0; will-change:transform; }
.gfd-vslide{
    position:absolute; left:0; right:0; top:0;
    height:100%;
    background:#000;
}
/* was: transform:translateY(-100%); / translateY(100%); */
.gfd-vslide[data-pos="prev"]{ transform:translateY(calc(-100% - 2px)); }
.gfd-vslide[data-pos="current"]{ transform:translateY(0); }
.gfd-vslide[data-pos="next"]{ transform:translateY(calc(100% + 2px)); }

.gfd-slide{
    position:absolute; inset:0;
    background:#000;
    /* No per-slide transition here — sliding is driven by
       .gfd-vtrack's own transform transition, and chrome dim/drift
       is driven per-element via inline styles from JS. */
}

.gfd-media-box{ width:100%; height:100%; position:relative; background:#000; overflow:hidden; }

.gfd-media-box .gfd-media{
    width:100%; height:100%;
    object-fit:contain;
    background:transparent;
    display:block;
    position:relative;
    z-index:1;
}

/* ══════════════════════════════════════════════════════════
   MULTI-MEDIA GALLERY (horizontal swipe within one post)
   ══════════════════════════════════════════════════════════ */
.gfd-media-gallery{
    display:flex; flex-direction:row;
    width:100%; height:100%;
    transition:transform .28s cubic-bezier(.22,1,.36,1);
}
.gfd-media-item{
    position:relative;
    flex:0 0 100%; width:100%; height:100%;
    background:#000;
}
.gfd-media-arrow{
    position:absolute; top:34%; transform:translateY(-50%);
    width:36px; height:36px; border-radius:50%;
    background:rgba(0,0,0,.35);
    display:flex; align-items:center; justify-content:center;
    color:#fff; cursor:pointer; z-index:6;
    opacity:0; transition:opacity .2s ease;
    -webkit-tap-highlight-color:transparent;
}
.gfd-media-box:hover .gfd-media-arrow{ opacity:1; }
.gfd-media-arrow-left{ left:56px; }
.gfd-media-arrow-right{ right:64px; }
.gfd-media-arrow i{ font-size:20px; }
@media (hover:none){ .gfd-media-arrow{ display:none !important; } }

.gfd-media-dashes{
    display:flex; justify-content:center; align-items:center; gap:6px;
    margin-top:18px;
    margin-left:-16px; margin-right:-78px;
    width:calc(100% + 94px);
    padding:0 12px;
    box-sizing:border-box;
}
.gfd-dash{
    flex:1 1 auto; max-width:400px; min-width:6px; height:3px; border-radius:2px;
    background:rgba(255,255,255,.32);
    transition:background .2s ease, transform .2s ease, max-width .2s ease;
}
.gfd-dash.active{
    background:#ff3b30;
}

@media (max-width:790px){ .gfd-media-dashes{ gap:5px; margin-left:-14px; margin-right:-70px; width:calc(100% + 84px); } }
@media (max-width:490px){ .gfd-media-dashes{ gap:5px; margin-left:-12px; margin-right:-62px; width:calc(100% + 74px); } }
@media (max-width:390px){ .gfd-media-dashes{ margin-left:-10px; margin-right:-56px; width:calc(100% + 66px); } }
@media (max-width:330px){ .gfd-media-dashes{ margin-left:-9px; margin-right:-50px; width:calc(100% + 59px); } }

#gfdFixedBackBtn{
    position:absolute;
    top:14px;
    left:4px;
    z-index:50;
}
@media (max-width:490px){
    #gfdFixedBackBtn{ top:11px; left:3px; }
}

/* ══════════════════════════════════════════════════════════
   VIDEO PROGRESS BAR — GF modal, same design as home.php's
   .ccm-video-progress, renamed to the gfd- prefix.
   ══════════════════════════════════════════════════════════ */
.gfd-video-progress{
    position:absolute; bottom:0; left:0; right:0;
    height:26px;
    z-index:11;
    display:flex;
    align-items:flex-end;
    padding:0 4px 6px;
    box-sizing:border-box;
    touch-action:none;
    cursor:pointer;
}
.gfd-video-progress-track{
    position:relative; width:100%; height:4px;
    border-radius:3px;
    background:rgba(255,255,255,.35);
    transition:height .15s ease;
}
.gfd-video-progress.scrubbing .gfd-video-progress-track{ height:6px; }
.gfd-video-progress-fill{
    position:absolute; left:0; top:0; bottom:0;
    width:0%; border-radius:3px;
    background:linear-gradient(90deg,#ff2b2b,#ff6b5f);
    box-shadow:0 0 6px rgba(255,43,43,.7);
}
.gfd-video-progress-thumb{
    position:absolute; top:50%;
    width:13px; height:13px; border-radius:50%;
    background:#fff; border:2px solid #ff2b2b;
    box-shadow:0 1px 5px rgba(0,0,0,.5);
    transform:translate(-50%,-50%) scale(0);
    transition:transform .15s ease;
    pointer-events:none;
}
.gfd-video-progress.scrubbing .gfd-video-progress-thumb{ transform:translate(-50%,-50%) scale(1); }

/* ══════════════════════════════════════════════════════════
   DOUBLE-TAP LIKE HEART BURST — red heart + pulse ring + particles
   ══════════════════════════════════════════════════════════ */
.gfd-double-tap-heart{
    position:absolute; z-index:14; pointer-events:none;
    transform:translate(-50%,-50%) scale(0) rotate(0deg);
    opacity:0;
}
.gfd-double-tap-heart i{
    font-size:100px; color:#ff2d4f;
    filter:drop-shadow(0 6px 22px rgba(255,45,79,.65));
    -webkit-text-stroke:1.5px rgba(255,255,255,.35);
}
.gfd-double-tap-heart.show{
    animation: gfdHeartBurst .95s cubic-bezier(.17,.89,.32,1.28) forwards;
}
@keyframes gfdHeartBurst{
    0%{   transform:translate(-50%,-50%) scale(0)    rotate(-28deg); opacity:0; }
    14%{  transform:translate(-50%,-50%) scale(1.35) rotate(10deg);  opacity:1; }
    28%{  transform:translate(-50%,-50%) scale(.88)  rotate(-6deg);  opacity:1; }
    42%{  transform:translate(-50%,-50%) scale(1.14) rotate(4deg);   opacity:1; }
    58%{  transform:translate(-50%,-50%) scale(.97)  rotate(-1deg);  opacity:1; }
    75%{  transform:translate(-50%,-50%) scale(1.05) rotate(0deg);   opacity:1; }
    100%{ transform:translate(-50%,-50%) scale(1.15) rotate(0deg) translateY(-46px); opacity:0; }
}

.gfd-heart-ring{
    position:absolute; z-index:13; pointer-events:none;
    width:110px; height:110px;
    margin-left:-55px; margin-top:-55px;
    border-radius:50%;
    border:3px solid rgba(255,45,79,.65);
    transform:scale(.25);
    opacity:0;
}
.gfd-heart-ring.show{
    animation: gfdRingPulse .75s cubic-bezier(.15,.6,.35,1) forwards;
}
@keyframes gfdRingPulse{
    0%{   transform:scale(.25); opacity:.9; border-width:4px; }
    100%{ transform:scale(2.4); opacity:0;  border-width:1px; }
}

.gfd-heart-particle{
    position:absolute; z-index:12; pointer-events:none;
    left:0; top:0;
    transform:translate(-50%,-50%) scale(0);
    opacity:0;
    color:#ff2d4f;
}
.gfd-heart-particle i{ filter:drop-shadow(0 2px 8px rgba(255,45,79,.55)); }
.gfd-heart-particle.show{
    animation: gfdParticleFly .8s cubic-bezier(.2,.7,.3,1) forwards;
}
@keyframes gfdParticleFly{
    0%{   transform:translate(-50%,-50%) scale(0)    rotate(0deg);        opacity:0; }
    12%{  opacity:1; }
    100%{ transform:translate(calc(-50% + var(--tx)), calc(-50% + var(--ty))) scale(1) rotate(var(--tr)); opacity:0; }
}

/* ── SKELETON LOADER ── */
.gfd-skeleton{
    position:absolute; inset:0; z-index:15;
    background:#0c0c10;
    opacity:1;
    transition:opacity .32s ease;
    pointer-events:none;
    overflow:hidden;
}
.gfd-skeleton.gfd-skeleton-hide{ opacity:0; }

.gfd-skel-block{
    position:absolute;
    background:linear-gradient(120deg,#17171b 25%,#232329 37%,#17171b 63%);
    background-size:400% 100%;
    animation:gfdSkelPulse 1.3s ease-in-out infinite;
    border-radius:8px;
}
@keyframes gfdSkelPulse{
    0%{ background-position:100% 50%; }
    100%{ background-position:0% 50%; }
}

.gfd-skel-media{ inset:0; border-radius:0; }
.gfd-skel-avatar{ left:16px; bottom:26px; width:48px; height:48px; border-radius:50%; }
.gfd-skel-line{ left:74px; height:11px; border-radius:5px; }
.gfd-skel-line1{ bottom:53px; width:130px; }
.gfd-skel-line2{ bottom:33px; width:190px; }
.gfd-skel-rail{ right:16px; width:27px; height:27px; border-radius:50%; }
.gfd-skel-rail1{ bottom:26px; }
.gfd-skel-rail2{ bottom:78px; }
.gfd-skel-rail3{ bottom:130px; }
.gfd-skel-rail4{ bottom:182px; }

.gfd-skel-shimmer{
    position:absolute; inset:0; z-index:1;
    background:linear-gradient(100deg,
        rgba(255,255,255,0) 30%,
        rgba(255,255,255,.05) 45%,
        rgba(255,255,255,.12) 50%,
        rgba(255,255,255,.05) 55%,
        rgba(255,255,255,0) 70%);
    background-size:250% 100%;
    animation:gfdShimmerSweep 1.3s ease-in-out infinite;
}
@keyframes gfdShimmerSweep{
    0%{ background-position:150% 0; }
    100%{ background-position:-150% 0; }
}

/* ── TAP-TO-PAUSE FEEDBACK ── */
.gfd-tap-flash{
    position:absolute; top:50%; left:50%;
    transform:translate(-50%,-50%) scale(.6);
    z-index:3; pointer-events:none;
    width:64px; height:64px; border-radius:50%;
    background:rgba(0,0,0,.45);
    display:flex; align-items:center; justify-content:center;
    opacity:0; transition:opacity .2s ease, transform .2s ease;
}
.gfd-tap-flash.show{ opacity:1; transform:translate(-50%,-50%) scale(1); }
.gfd-tap-flash i{ font-size:32px; color:#fff; }

/* ── PERSISTENT PAUSE + MUTE + SEEK CONTROLS ── */
.gfd-paused-controls{
    position:absolute; inset:0; z-index:4;
    display:flex; align-items:center; justify-content:center;
    pointer-events:none;
}
.gfd-paused-stack{
    display:flex; flex-direction:column; align-items:center; gap:14px;
}
.gfd-paused-row{
    display:flex; align-items:center; gap:22px;
}
.gfd-pause-icon{
    width:60px; height:60px; border-radius:50%;
    background:rgba(0,0,0,.45);
    display:flex; align-items:center; justify-content:center;
    flex-shrink:0;
}
.gfd-pause-icon i{ font-size:28px; color:#fff; }
.gfd-mute-btn{
    width:46px; height:46px; border-radius:50%;
    background:rgba(0,0,0,.45); border:none; cursor:pointer;
    display:flex; align-items:center; justify-content:center;
    pointer-events:all;
    -webkit-tap-highlight-color:transparent;
    transition:transform .15s ease, background .15s ease;
}
.gfd-mute-btn:active{ transform:scale(.9); background:rgba(0,0,0,.6); }
.gfd-mute-btn i{ font-size:20px; color:#fff; }

.gfd-seek-btn{
    width:38px; height:38px; border-radius:50%;
    background:rgba(0,0,0,.4); border:none; cursor:pointer;
    display:flex; align-items:center; justify-content:center;
    pointer-events:all;
    -webkit-tap-highlight-color:transparent;
    transition:transform .15s ease, background .15s ease;
    flex-shrink:0;
}
.gfd-seek-btn:active{ transform:scale(.88); background:rgba(0,0,0,.58); }
.gfd-seek-btn i{ font-size:17px; color:#fff; }

/* ── CAPTION-ONLY POSTS ── */
.gfd-textonly-bg{
    position:absolute; inset:0; z-index:0;
    background-image:url('uploads/Text.jpg');
    background-size:cover; background-position:center;
}
.gfd-textonly-overlay{
    position:absolute; inset:0; z-index:0;
    background:rgba(0,0,0,.45);
}
.gfd-center-caption-wrap{
    position:absolute; inset:0; z-index:1;
    display:flex; align-items:center; justify-content:center;
    padding:90px 34px 140px;
    text-align:center;
}
.gfd-center-caption-wrap.gfd-caption-overflow{ align-items:flex-start; }
.gfd-center-caption-text{
    color:#fff; font-size:16px; font-weight:500; line-height:1.5;
    text-shadow:0 1px 5px rgba(0,0,0,.4);
    max-height:100%; overflow-y:auto;
    word-break:break-word;
    scrollbar-width:none; -ms-overflow-style:none;
    overscroll-behavior:contain;
    touch-action:pan-y;
    -webkit-overflow-scrolling:touch;
}
.gfd-center-caption-text::-webkit-scrollbar{ display:none; }

/* ── TOP BAR ── */
.gfd-top-bar{
    position:absolute; top:0; left:0; right:0; z-index:8;
    padding:14px 12px 34px 4px;
    background:linear-gradient(to bottom, rgba(0,0,0,.68) 0%, rgba(0,0,0,.3) 60%, transparent 100%);
    pointer-events:none;
}
.gfd-top-bar-row{ display:flex; justify-content:space-between; align-items:center; pointer-events:all; }
.gfd-top-bar > *{ pointer-events:all; }
.gfd-back-btn{ padding-left:2px !important; }

.gfd-icon-btn{
    background:none; border:none; box-shadow:none;
    color:#fff; cursor:pointer; padding:6px;
    display:flex; align-items:center; justify-content:center;
    -webkit-tap-highlight-color:transparent;
    transition:transform .15s ease, opacity .15s ease;
}
.gfd-icon-btn:active{ transform:scale(.88); opacity:.7; }
.gfd-icon-btn i{ font-size:30px; filter:drop-shadow(0 2px 6px rgba(0,0,0,.7)); }
.gfd-search-btn i{ font-size:24px; }

.gfd-back-group{ display:flex; align-items:center; gap:2px; }
.gfd-brand-label{ color:#fff; font-weight:700; font-size:16px; text-shadow:0 2px 6px rgba(0,0,0,.6); font-family:Poppins,sans-serif; }
.gfd-brand-dot{ color:rgba(255,255,255,.6); font-size:14px; font-weight:700; margin:0 5px; }
.gfd-brand-date{ color:rgba(255,255,255,.75); font-size:12.5px; font-weight:500; text-shadow:0 1px 4px rgba(0,0,0,.6); font-family:Poppins,sans-serif; white-space:nowrap; }

.gfd-top-right-tags{
    position:absolute; top:80px; right:14px; z-index:8;
    display:flex; flex-direction:column; align-items:flex-end; gap:6px;
    pointer-events:none;
}

.gfd-media-counter{
    background:rgba(22,22,24,.82); color:#fff;
    font-size:10px; font-weight:800; letter-spacing:.3px;
    padding:5px 9px; border-radius:6px;
}

.gfd-type-tag{
    display:flex; align-items:center; gap:4px;
    background:rgba(22,22,24,.82); border:none;
    border-radius:6px; padding:5px 9px 5px 7px;
    pointer-events:none;
}
.gfd-type-tag i{ font-size:12.5px; color:#fff; }
.gfd-type-tag span{ font-size:10px; font-weight:700; color:#fff; text-transform:uppercase; letter-spacing:.4px; white-space:nowrap; }

.gfd-pod-tag{
    position:absolute; top:80px; left:14px; z-index:8;
    display:flex; align-items:center; gap:5px;
    background:none; border:none;
    max-width:150px;
    pointer-events:none;
}
.gfd-pod-tag i{
    font-size:14px; color:#1a56db;
    filter:drop-shadow(0 1px 3px rgba(0,0,0,.6));
    flex-shrink:0;
}
.gfd-pod-tag span{
    font-size:13px; font-weight:700; color:#1a56db;
    font-family:Poppins,sans-serif;
    text-shadow:0 1px 3px rgba(0,0,0,.6);
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}

.gfd-top-search-btn{
    position:absolute; top:20px; right:10px; z-index:9;
    background:none; border:none; box-shadow:none;
    color:#fff; cursor:pointer; padding:6px;
    display:flex; align-items:center; justify-content:center;
    -webkit-tap-highlight-color:transparent;
    transition:transform .15s ease, opacity .15s ease;
}
.gfd-top-search-btn:active{ transform:scale(.88); opacity:.7; }
.gfd-top-search-btn i{ font-size:28px; filter:drop-shadow(0 2px 6px rgba(0,0,0,.7)); }

/* ── RIGHT ACTION RAIL ── */
.gfd-rail{
    position:absolute; right:16px; bottom:26px; z-index:9;
    display:flex; flex-direction:column; align-items:center; gap:20px;
}
.gfd-rail.gfd-rail-raised{ bottom:42px; }
.gfd-rail-btn{
    display:flex; flex-direction:column; align-items:center; gap:5px;
    background:none; border:none; cursor:pointer;
    -webkit-tap-highlight-color:transparent;
}
.gfd-rail-btn i{
    font-size:23px; color:#fff;
    -webkit-text-stroke:0.6px currentColor;
    filter:drop-shadow(0 2px 5px rgba(0,0,0,.55));
    transition:transform .18s ease, color .18s ease;
}
.gfd-rail-btn.active i{ color:#ff2d55; }
.gfd-rail-btn:active i{ transform:scale(1.3); }
.gfd-rail-count{
    font-size:12px; font-weight:800;
    -webkit-text-stroke:0.2px currentColor;
    color:#fff;
    text-shadow:0 1px 4px rgba(0,0,0,.75), 0 0 8px rgba(255,255,255,.25);
}

@keyframes gfd-repost-arc-swap {
    0%   { transform: rotate(0deg) scale(1); }
    35%  { transform: rotate(140deg) scale(1.15); }
    50%  { transform: rotate(180deg) scale(1.2); }
    65%  { transform: rotate(220deg) scale(1.15); }
    100% { transform: rotate(360deg) scale(1); }
}
.gfd-rail-btn.arc-swap-active i {
    animation: gfd-repost-arc-swap 0.6s cubic-bezier(.33,1,.68,1);
    transition: none !important;
}

.gfd-rail-msg-pic{
    width:30px; height:30px; object-fit:cover;
    border-radius:10px; border:2px solid #fff;
    box-shadow:0 3px 8px rgba(0,0,0,.55);
    display:block;
}

/* ── BOTTOM INFO PANEL ── */
.gfd-bottom{
    position:absolute; left:0; right:0; bottom:0; z-index:7;
    padding:16px 78px 22px 16px;
    background:linear-gradient(to top, rgba(0,0,0,.88) 0%, rgba(0,0,0,.55) 55%, transparent 100%);
}
.gfd-bottom.gfd-no-rail{ padding-right:16px; }

.gfd-user-row{ display:flex; align-items:center; gap:10px; margin-bottom:5px; }
.gfd-user-pic{
    width:48px; height:48px; border-radius:50%; object-fit:cover;
    border:2.5px solid #ff2b2b; cursor:pointer; flex-shrink:0;
}
.gfd-user-info{ display:flex; flex-direction:column; justify-content:center; gap:0px; min-width:0; flex:1; }
.gfd-user-line1{ display:flex; align-items:center; gap:8px; min-width:0; line-height:1.1; margin-bottom:4px; }
.gfd-username{
    color:#fff; font-weight:700; font-size:14px; cursor:pointer;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    text-shadow:0 1px 3px rgba(0,0,0,.5); line-height:1.1;
}
.gfd-nickname{ color:rgba(255,255,255,.5); font-size:11.5px; font-weight:500; margin:0; line-height:1.1; }

.gfd-info-switcher{
    position:relative;
    min-height:14px;
}
.gfd-switch-pane{
    position:absolute;
    left:0; top:0; right:0;
    opacity:0;
    pointer-events:none;
    transition:opacity .35s ease;
}
.gfd-switch-pane.active{
    position:relative;
    opacity:1;
    pointer-events:all;
}

.gfd-audio-tag{
    display:flex;
    align-items:center;
    gap:7px;
    max-width:100%;
}
.gfd-audio-icon{
    display:inline-flex;
    align-items:flex-end;
    gap:2px;
    height:11px;
    flex-shrink:0;
}
.gfd-eq-bar{
    width:2.5px;
    border-radius:2px;
    background:rgba(255,255,255,.75);
    display:inline-block;
    transform-origin:bottom;
    animation:gfdEqBounce 0.9s ease-in-out infinite;
}
.gfd-eq-bar:nth-child(1){ height:11px; animation-delay:-0.6s; }
.gfd-eq-bar:nth-child(2){ height:11px; animation-delay:-0.2s; }
.gfd-eq-bar:nth-child(3){ height:11px; animation-delay:-0.9s; }
@keyframes gfdEqBounce{
    0%   { transform:scaleY(0.35); }
    25%  { transform:scaleY(1); }
    50%  { transform:scaleY(0.5); }
    75%  { transform:scaleY(0.85); }
    100% { transform:scaleY(0.35); }
}

.gfd-audio-track{
    position:relative;
    overflow:hidden;
    max-width:230px;
    flex:0 1 auto;
    display:flex;
    align-items:center;
}
.gfd-audio-track-text{
    display:inline-block;
    white-space:nowrap;
    font-size:12.5px;
    font-weight:500;
    line-height:1;
    color:rgba(255,255,255,.7);
}
.gfd-audio-track-text.gfd-marquee{
    animation: gfdAudioMarquee var(--marquee-dur,6s) ease-in-out infinite alternate;
}
@keyframes gfdAudioMarquee{
    0%   { transform:translateX(0); }
    12%  { transform:translateX(0); }
    88%  { transform:translateX(var(--marquee-dist,0px)); }
    100% { transform:translateX(var(--marquee-dist,0px)); }
}

.gfd-social-proof-strip{
    position:absolute; left:16px; bottom:175px; z-index:6;
    display:flex; flex-direction:row-reverse; gap:8px;
    pointer-events:none;
    transition:opacity .22s ease, transform .22s ease;
    opacity:1;
}
.gfd-social-proof-strip.gfd-sp-hidden{
    opacity:0;
    transform:translateY(10px);
}
.gfd-social-proof-strip.gfd-sp-raised{ bottom:195px; }
.gfd-sp-avatar-wrap{ position:relative; pointer-events:all; display:inline-block; text-decoration:none; }
.gfd-sp-avatar-wrap:nth-child(1){ animation:gfdSpFloat 3s ease-in-out infinite; }
.gfd-sp-avatar-wrap:nth-child(2){ animation:gfdSpFloat 3s ease-in-out infinite .4s; }
.gfd-sp-avatar-wrap:nth-child(3){ animation:gfdSpFloat 3s ease-in-out infinite .8s; }
.gfd-sp-avatar-wrap:nth-child(4){ animation:gfdSpFloat 3s ease-in-out infinite 1.2s; }
@keyframes gfdSpFloat{ 0%,100%{ transform:translateY(0); } 50%{ transform:translateY(-6px); } }
.gfd-sp-avatar{
    width:44px; height:44px; border-radius:50%; object-fit:cover;
    border:2.5px solid #ff2b2b; display:block;
    box-shadow:0 4px 12px rgba(0,0,0,.7);
}
.gfd-sp-badge{
    position:absolute; bottom:-2px; right:-2px;
    width:14px; height:14px; border-radius:50%;
    display:flex; align-items:center; justify-content:center; z-index:2;
}
.gfd-sp-badge i{ font-size:7px; color:#fff; line-height:1; }

.gfd-track-btn{
    padding:0; margin:0; border-radius:0;
    border:none; background:transparent;
    color:#ff2b2b; font-size:13px; font-weight:700;
    cursor:pointer; flex-shrink:0; font-family:Poppins,sans-serif;
    text-decoration:underline; text-decoration-color:#ff2b2b;
    text-underline-offset:2px; text-decoration-thickness:2.5px;
    transition:opacity .2s, transform .15s;
    -webkit-tap-highlight-color:transparent;
    position:relative; top:-2px;
}
.gfd-track-btn.following{ background:transparent; border:none; color:#ff2b2b; text-decoration:underline; text-decoration-color:#ff2b2b; }
.gfd-track-btn:active{ transform:scale(.94); opacity:.7; }

.gfd-user-dot{ color:#fff; font-size:15px; flex-shrink:0; }

.gfd-partnership-tag{
    display:inline-flex;align-items:center;gap:6px;
    margin-top:8px;
    padding:5px 10px;
    border-radius:8px;
    background:rgba(0,0,0,0.55);
    color:#ff4c4c;
    font-size:11px;
    font-weight:600;
    max-width:100%;
    backdrop-filter:blur(6px);
    -webkit-backdrop-filter:blur(6px);
}
.gfd-partnership-tag i{ font-size:13px; flex-shrink:0; }
.gfd-partnership-tag strong{ color:#fff; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

.gfd-caption-wrap{ max-width:100%; }
.gfd-caption-wrap.gfd-expanded{
    max-height:34vh;
    overflow-y:auto;
    -webkit-overflow-scrolling:touch;
    touch-action:pan-y;
    overscroll-behavior:contain;
    scrollbar-width:none; -ms-overflow-style:none;
    padding-right:2px;
}
.gfd-caption-wrap.gfd-expanded::-webkit-scrollbar{ display:none; }
.gfd-caption-text{ color:#fff; font-size:13px; line-height:1.5; word-break:break-word; text-shadow:0 1px 2px rgba(0,0,0,.35); display:inline; }
.gfd-more-toggle{ color:#ff2b2b; font-size:inherit; font-weight:600; line-height:inherit; cursor:pointer; display:inline; -webkit-tap-highlight-color:transparent; }
.gfd-ellipsis{ color:#fff; font-weight:500; }

#gfd-hint{
    position:absolute; bottom:8px; left:50%; transform:translateX(-50%);
    color:rgba(255,255,255,.28); font-size:10px; z-index:20;
    pointer-events:none; white-space:nowrap;
}

#gfd-arrows{
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    gap:14px; margin-left:16px; flex-shrink:0;
}
#gfd-arrows button{
    background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.14);
    border-radius:50%; width:44px; height:44px;
    display:flex; align-items:center; justify-content:center;
    cursor:pointer; transition:background .2s ease, transform .15s ease;
    -webkit-tap-highlight-color:transparent;
}
#gfd-arrows button:hover{ background:rgba(255,255,255,.18); }
#gfd-arrows button:active{ transform:scale(.9); }

/* ============================================================
   GFD HIDDEN OVERLAY — full stylesheet
   ============================================================ */

.gfd-hidden-overlay{
    position:absolute; inset:0; z-index:60;
    background:linear-gradient(-45deg, #010218, #070924, #070924);
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    text-align:center; padding:30px;
    font-family:Poppins,sans-serif;
    overflow:hidden;
    animation:gfdHiddenFadeIn .35s ease;
}
@keyframes gfdHiddenFadeIn{
    from{ opacity:0; }
    to{ opacity:1; }
}

/* ── soft ambient glow drifting behind everything ── */
.gfd-hidden-overlay::before{
    content:'';
    position:absolute;
    width:280px; height:280px;
    border-radius:50%;
    background:radial-gradient(circle, rgba(255,45,45,.16) 0%, rgba(255,45,45,0) 70%);
    top:50%; left:50%;
    transform:translate(-50%,-55%);
    animation:gfdGlowDrift 6s ease-in-out infinite;
    pointer-events:none;
}
@keyframes gfdGlowDrift{
    0%,100%{ transform:translate(-50%,-55%) scale(1); opacity:.8; }
    50%{ transform:translate(-50%,-50%) scale(1.15); opacity:1; }
}

/* ── close button (top-left) ── */
.gfd-hidden-close{
    position:absolute;
    top:16px;
    left:6px;
    animation:gfdHiddenSlideDown .4s ease .05s both;
}

/* ── search button (top-right) — same as post's top search button, no bg/border ── */
.gfd-hidden-search-btn{
    position:absolute;
    top:20px;
    right:10px;
    width:auto; height:auto;
    border-radius:0;
    background:none;
    border:none;
    box-shadow:none;
    display:flex; align-items:center; justify-content:center;
    color:#fff;
    padding:6px;
    cursor:pointer;
    -webkit-tap-highlight-color:transparent;
    transition:transform .15s ease, opacity .15s ease;
    z-index:2;
    animation:gfdHiddenSlideDown .4s ease .05s both;
}
.gfd-hidden-search-btn i{
    font-size:28px;
    filter:drop-shadow(0 2px 6px rgba(0,0,0,.7));
}
.gfd-hidden-search-btn:active{ transform:scale(.88); opacity:.7; }

/* ── pill row — sits just under the back/search buttons ── */
.gfd-hidden-pill-row{
    position:absolute;
    top:74px;
    left:0; right:0;
    display:flex;
    align-items:center;
    justify-content:center;
    flex-wrap:wrap;
    gap:10px;
    padding:0 20px;
    z-index:2;
    animation:gfdHiddenRise .4s ease .1s both;
}
.gfd-hidden-pill{
    display:flex;
    align-items:center;
    gap:8px;
    flex-shrink:0;
    background:rgba(60,60,60,.4);
    backdrop-filter:blur(6px);
    -webkit-backdrop-filter:blur(6px);
    border:none;
    color:#fff;
    font-family:Poppins,sans-serif;
    font-size:13px;
    font-weight:700;
    padding:10px 18px;
    border-radius:8px;
    cursor:pointer;
    -webkit-tap-highlight-color:transparent;
    transition:background .2s ease, transform .15s ease;
}
.gfd-hidden-pill i{ font-size:14px; }
.gfd-hidden-pill:hover{ background:rgba(75,75,75,.55); }
.gfd-hidden-pill:active{ transform:scale(.96); }

/* ── central icon ── */
.gfd-hidden-icon{
    width:64px; height:64px; border-radius:50%;
    background:rgba(255,45,45,.12); border:1.5px solid rgba(255,45,45,.3);
    display:flex; align-items:center; justify-content:center;
    font-size:27px; color:#ff5c5c; margin-bottom:16px;
    position:relative;
    animation:gfdHiddenPopIn .45s cubic-bezier(.34,1.56,.64,1) .1s both;
}
/* pulsing ring around the icon */
.gfd-hidden-icon::after{
    content:'';
    position:absolute; inset:-8px;
    border-radius:50%;
    border:1.5px solid rgba(255,45,45,.35);
    animation:gfdIconPulse 2.2s ease-out infinite;
}
@keyframes gfdIconPulse{
    0%{ transform:scale(.85); opacity:.9; }
    100%{ transform:scale(1.35); opacity:0; }
}
@keyframes gfdHiddenPopIn{
    from{ opacity:0; transform:scale(.4) rotate(-15deg); }
    to{ opacity:1; transform:scale(1) rotate(0deg); }
}

/* ── text content ── */
.gfd-hidden-title{
    font-size:17px; font-weight:700; color:#fff; margin-bottom:7px;
    letter-spacing:-.2px;
    animation:gfdHiddenRise .4s ease .18s both;
}
.gfd-hidden-sub{
    font-size:12.5px; color:rgba(255,255,255,.55);
    max-width:260px; line-height:1.55; margin-bottom:24px;
    animation:gfdHiddenRise .4s ease .25s both;
}
@keyframes gfdHiddenRise{
    from{ opacity:0; transform:translateY(10px); }
    to{ opacity:1; transform:translateY(0); }
}
@keyframes gfdHiddenSlideDown{
    from{ opacity:0; transform:translateY(-8px); }
    to{ opacity:1; transform:translateY(0); }
}

/* ── undo button ── */
.gfd-hidden-undo{
    display:inline-flex; align-items:center; gap:6px;
    background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.18);
    color:#fff; font-size:13px; font-weight:700; padding:11px 24px; border-radius:30px;
    cursor:pointer; -webkit-tap-highlight-color:transparent;
    transition:background .2s ease, border-color .2s ease, transform .15s ease, box-shadow .2s ease;
    position:relative;
    animation:gfdHiddenRise .4s ease .32s both;
}
.gfd-hidden-undo:hover{
    background:rgba(255,45,45,.2);
    border-color:rgba(255,45,45,.4);
    box-shadow:0 4px 20px rgba(255,45,45,.25);
    transform:translateY(-1px);
}
.gfd-hidden-undo:active{ transform:scale(.96) translateY(0); }
.gfd-hidden-undo i{
    font-size:15px;
    animation:gfdUndoSpinHint 2.5s ease-in-out infinite;
}
@keyframes gfdUndoSpinHint{
    0%,80%,100%{ transform:rotate(0deg); }
    88%{ transform:rotate(-25deg); }
    94%{ transform:rotate(8deg); }
}

/* ============================================================
   Responsive breakpoints
   ============================================================ */
@media (max-width:790px){
    .gfd-hidden-overlay{ padding:24px; }
    .gfd-hidden-overlay::before{ width:230px; height:230px; }
    .gfd-hidden-icon{ width:58px; height:58px; font-size:24px; margin-bottom:14px; }
    .gfd-hidden-title{ font-size:16px; }
    .gfd-hidden-sub{ font-size:12px; max-width:240px; margin-bottom:22px; }
    .gfd-hidden-undo{ font-size:12.5px; padding:10px 22px; }

    .gfd-hidden-search-btn{ top:17px; right:8px; }
    .gfd-hidden-search-btn i{ font-size:26px; }
    .gfd-hidden-pill-row{ top:68px; gap:9px; }
}

@media (max-width:490px){
    .gfd-hidden-overlay{ padding:20px; }
    .gfd-hidden-overlay::before{ width:190px; height:190px; }
    .gfd-hidden-close{ top:12px; left:5px; }
    .gfd-hidden-icon{ width:52px; height:52px; font-size:21px; margin-bottom:12px; }
    .gfd-hidden-title{ font-size:15px; }
    .gfd-hidden-sub{ font-size:11.5px; max-width:220px; line-height:1.5; margin-bottom:20px; }
    .gfd-hidden-undo{ font-size:12px; padding:9px 20px; gap:5px; }
    .gfd-hidden-undo i{ font-size:13px; }

    .gfd-hidden-search-btn{ top:15px; right:7px; padding:5px; }
    .gfd-hidden-search-btn i{ font-size:22px; }
    .gfd-hidden-pill-row{ top:60px; gap:7px; }
    .gfd-hidden-pill{ font-size:12px; padding:8px 14px; gap:6px; }
    .gfd-hidden-pill i{ font-size:12.5px; }
}

@media (max-width:330px){
    .gfd-hidden-overlay{ padding:16px; }
    .gfd-hidden-overlay::before{ width:150px; height:150px; }
    .gfd-hidden-close{ top:10px; left:4px; }
    .gfd-hidden-icon{ width:46px; height:46px; font-size:18px; margin-bottom:10px; }
    .gfd-hidden-title{ font-size:14px; }
    .gfd-hidden-sub{ font-size:11px; max-width:190px; line-height:1.45; margin-bottom:16px; }
    .gfd-hidden-undo{ font-size:11.5px; padding:8px 18px; gap:4px; }
    .gfd-hidden-undo i{ font-size:12px; }

    .gfd-hidden-search-btn{ top:12px; right:6px; padding:4px; }
    .gfd-hidden-search-btn i{ font-size:19px; }
    .gfd-hidden-pill-row{ top:52px; gap:6px; }
    .gfd-hidden-pill{ font-size:11px; padding:7px 12px; gap:4px; }
    .gfd-hidden-pill i{ font-size:11px; }
}

.gfd-saved-toast{
    position:fixed;
    left:6px;right:6px;
    bottom:calc(30px + env(safe-area-inset-bottom));
    background:rgba(12,12,28,0.98);
    backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);
    border:1px solid rgba(255,255,255,0.08);
    border-radius:8px;
    padding:13px 16px;
    display:flex;align-items:center;justify-content:space-between;
    gap:12px;
    z-index:999999998;
    font-family:Poppins,sans-serif;
    transform:translateY(120%);
    opacity:0;
    transition:transform .28s cubic-bezier(.32,.72,0,1), opacity .28s ease;
    box-shadow:0 8px 24px rgba(0,0,0,0.35);
}
.gfd-saved-toast.show{
    transform:translateY(0);
    opacity:1;
}
.gfd-saved-toast-left{
    display:flex;align-items:center;gap:10px;
    color:#fff;font-size:14.5px;font-weight:600;
}
.gfd-saved-toast-check{
    width:20px;height:20px;border-radius:50%;
    background:#f24455;color:#fff;
    display:flex;align-items:center;justify-content:center;
    font-size:12px;flex-shrink:0;
}
.gfd-saved-toast-right{
    display:flex;align-items:center;gap:2px;
    color:#f24455;font-size:14.5px;font-weight:600;
    cursor:pointer;-webkit-tap-highlight-color:transparent;
    flex-shrink:0;
    transition:opacity .15s ease;
}
.gfd-saved-toast-right:active{ opacity:0.7; }
.gfd-saved-toast-right i{ font-size:17px; }

/* 790px — TABLET */
@media (max-width:790px){
    .gfd-saved-toast{
        left:5px;right:5px;
        bottom:calc(26px + env(safe-area-inset-bottom));
        border-radius:7px;
        padding:12px 15px;
    }
    .gfd-saved-toast-left{ font-size:14px;gap:9px; }
    .gfd-saved-toast-check{ width:19px;height:19px;font-size:11.5px; }
    .gfd-saved-toast-right{ font-size:14px; }
    .gfd-saved-toast-right i{ font-size:16px; }
}

/* 490px — LARGE MOBILE */
@media (max-width:490px){
    .gfd-saved-toast{
        left:4px;right:4px;
        bottom:calc(20px + env(safe-area-inset-bottom));
        border-radius:6px;
        padding:11px 14px;
        backdrop-filter:blur(16px);
        -webkit-backdrop-filter:blur(16px);
    }
    .gfd-saved-toast-left{ font-size:13.5px;gap:8px; }
    .gfd-saved-toast-check{ width:18px;height:18px;font-size:11px; }
    .gfd-saved-toast-right{ font-size:13.5px;gap:1px; }
    .gfd-saved-toast-right i{ font-size:15.5px; }
}

/* 390px — STANDARD MOBILE */
@media (max-width:390px){
    .gfd-saved-toast{
        left:3px;right:3px;
        bottom:calc(14px + env(safe-area-inset-bottom));
        border-radius:5px;
        padding:10px 13px;
        backdrop-filter:blur(14px);
        -webkit-backdrop-filter:blur(14px);
        box-shadow:0 6px 20px rgba(0,0,0,0.3);
    }
    .gfd-saved-toast-left{ font-size:12.5px;gap:7px; }
    .gfd-saved-toast-check{ width:17px;height:17px;font-size:10px; }
    .gfd-saved-toast-right{ font-size:12.5px; }
    .gfd-saved-toast-right i{ font-size:14.5px; }
}

/* 330px — COMPACT */
@media (max-width:330px){
    .gfd-saved-toast{
        left:2px;right:2px;
        bottom:calc(10px + env(safe-area-inset-bottom));
        border-radius:4px;
        padding:9px 11px;
        backdrop-filter:blur(12px);
        -webkit-backdrop-filter:blur(12px);
    }
    .gfd-saved-toast-left{ font-size:11.5px;gap:6px; }
    .gfd-saved-toast-check{ width:15px;height:15px;font-size:9px; }
    .gfd-saved-toast-right{ font-size:11.5px; }
    .gfd-saved-toast-right i{ font-size:13.5px; }
}

@media(max-width:790px){ #gfd-arrows{ display:none; } }

/* ══════════════════════════════════════
   DISCUSS BUTTON — pill shaped,
   top-left, under the back chevron
   ══════════════════════════════════════ */
.gfd-discuss-wrap{
    position:absolute;
    top:78px; left:14px;
    z-index:8;
    pointer-events:none;
}
.gfd-discuss-btn{
    pointer-events:all;
    display:inline-flex;
    align-items:center;
    gap:6px;
    background:rgba(10,10,14,0.72);
    backdrop-filter:blur(8px);
    -webkit-backdrop-filter:blur(8px);
    border:1px solid rgba(255,255,255,0.14);
    border-radius:999px;
    padding:5px 14px;
    color:#ff3b3b;
    font-family:'Poppins', sans-serif;
    font-size:13.5px;
    font-weight:700;
    letter-spacing:0.1px;
    cursor:pointer;
    transition:background 0.2s ease, transform 0.15s ease, border-color 0.2s ease;
    -webkit-tap-highlight-color:transparent;
    white-space:nowrap;
}
.gfd-discuss-btn i{
    font-size:14.5px;
    flex-shrink:0;
    color:#ff3b3b;
}
.gfd-discuss-btn:hover{
    background:rgba(20,20,26,0.85);
    border-color:rgba(255,255,255,0.24);
}
.gfd-discuss-btn:active{
    transform:scale(0.94);
}

/* ══════════════════════════════════════
   RESPONSIVE — 790 / 490 / 390 / 330
   ══════════════════════════════════════ */
@media (max-width:790px){
    .gfd-discuss-wrap{ top:72px; left:13px; }
    .gfd-discuss-btn{ padding:4.5px 13px; font-size:12.5px; border-radius:999px; gap:5px; }
    .gfd-discuss-btn i{ font-size:13.5px; }
}
@media (max-width:490px){
    .gfd-discuss-wrap{ top:65px; left:12px; }
    .gfd-discuss-btn{ padding:4px 12px; font-size:11.5px; border-radius:999px; gap:4px; }
    .gfd-discuss-btn i{ font-size:12.5px; }
}
@media (max-width:390px){
    .gfd-discuss-wrap{ top:59px; left:10px; }
    .gfd-discuss-btn{ padding:3.5px 11px; font-size:11px; border-radius:999px; gap:4px; }
    .gfd-discuss-btn i{ font-size:12px; }
}
@media (max-width:330px){
    .gfd-discuss-wrap{ top:55px; left:9px; }
    .gfd-discuss-btn{ padding:3px 10px; font-size:10.5px; border-radius:999px; gap:3px; }
    .gfd-discuss-btn i{ font-size:11.5px; }
}

/* ══════════════════════════════════════════════════════════
   CONNECTED ZEEM — HORIZONTAL LAYER STACK
   #gfd-hstack sits as a full-bleed overlay INSIDE the modal's
   inner container (whatever plays the role of #ccm-inner —
   it must be position:relative), stacked above your vertical
   swipe track. Each .gfd-hlayer is itself a full-bleed absolute
   box so the connected-Zeem slide genuinely overlays and slides
   in on top of the current post, instead of pushing layout
   around in normal document flow.
   ══════════════════════════════════════════════════════════ */
#gfd-hstack{
    position:absolute;
    inset:0;
    z-index:12;
    overflow:hidden;
    pointer-events:none; /* flipped to 'all' via JS once a layer is pushed */
}

.gfd-hlayer{
    position:absolute;
    inset:0;
    width:100%;
    height:100%;
    background:#000;
    will-change:transform;
}

/* ══════════════════════════════════════════════════════════
   CONNECTED ZEEM PILL — occupies the same bottom-left slot as
   .gfd-social-proof-strip (mutually exclusive with it — a post
   is never both showing social proof and linking to a connected
   Zeem at once). Tapping it should trigger your gfd equivalent
   of ccmOpenConnectedZeem() to slide in the linked post.
   ══════════════════════════════════════════════════════════ */
.gfd-connected-tag{
    position:absolute; left:16px; bottom:175px; z-index:6;
    display:flex; align-items:center; gap:8px;
    padding:4px 13px 4px 10px;
    border-radius:8px;
    background:rgba(20,20,23,.96);
    border:1px solid rgba(20,20,23,.96);
    box-shadow:0 6px 18px rgba(0,0,0,.4);
    cursor:pointer;
    -webkit-tap-highlight-color:transparent;
    transition:background .18s ease, transform .15s ease, box-shadow .18s ease, opacity .22s ease;
    max-width:calc(100% - 32px);
}
.gfd-connected-tag:hover{
    background:rgba(30,30,34,.98);
    border-color:rgba(30,30,34,.98);
    box-shadow:0 8px 22px rgba(0,0,0,.5);
}
.gfd-connected-tag:active{
    transform:scale(.96);
}
.gfd-connected-tag.gfd-sp-raised{ bottom:195px; }
.gfd-connected-tag.gfd-sp-hidden{
    opacity:0;
    transform:translateX(10px);
    pointer-events:none;
}

/* Sits below the CTA pill when a post is both an ad and
   connected — dropped further down so it never touches the
   CTA pill above it. */
.gfd-connected-tag.gfd-connected-below-cta{ bottom:130px; }
.gfd-connected-tag.gfd-connected-below-cta.gfd-sp-raised{ bottom:150px; }

.gfd-connected-icon{
    display:flex; align-items:center; justify-content:center;
    flex-shrink:0;
}
.gfd-connected-icon i{
    font-size:15px; color:#fff;
}

.gfd-connected-text{
    display:flex; align-items:baseline; gap:5px;
    min-width:0;
    overflow:hidden;
    white-space:nowrap;
}
.gfd-connected-text-main{
    font-size:12.5px; font-weight:700; color:#fff;
    letter-spacing:.1px;
    flex-shrink:0;
}
.gfd-connected-text-dot{
    color:rgba(255,255,255,.4);
    font-size:11px;
    flex-shrink:0;
}
.gfd-connected-text-label{
    font-size:12px; font-weight:500; color:rgba(255,255,255,.72);
    overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
    min-width:0;
}

/* ══════════════════════════════════
   RESPONSIVE — 790 / 490 / 390 / 330
   ══════════════════════════════════ */
@media (max-width:790px){
    .gfd-connected-tag{ left:14px; bottom:162px; padding:3.5px 11px 3.5px 9px; gap:7px; border-radius:7px; }
    .gfd-connected-tag.gfd-sp-raised{ bottom:180px; }
    .gfd-connected-tag.gfd-connected-below-cta{ bottom:117px; }
    .gfd-connected-tag.gfd-connected-below-cta.gfd-sp-raised{ bottom:135px; }
    .gfd-connected-icon i{ font-size:12px; }
    .gfd-connected-text-main{ font-size:11.5px; }
    .gfd-connected-text-dot{ font-size:10px; }
    .gfd-connected-text-label{ font-size:11px; }
}

@media (max-width:490px){
    .gfd-connected-tag{ left:12px; bottom:140px; padding:3px 10px 3px 8px; gap:6px; border-radius:7px; }
    .gfd-connected-tag.gfd-sp-raised{ bottom:156px; }
    .gfd-connected-tag.gfd-connected-below-cta{ bottom:95px; }
    .gfd-connected-tag.gfd-connected-below-cta.gfd-sp-raised{ bottom:111px; }
    .gfd-connected-icon i{ font-size:11px; }
    .gfd-connected-text-main{ font-size:11px; }
    .gfd-connected-text-dot{ font-size:9.5px; }
    .gfd-connected-text-label{ font-size:10.5px; }
}

@media (max-width:390px){
    .gfd-connected-tag{ left:10px; bottom:125px; padding:3px 9px 3px 7px; gap:5.5px; border-radius:6px; }
    .gfd-connected-tag.gfd-sp-raised{ bottom:139px; }
    .gfd-connected-tag.gfd-connected-below-cta{ bottom:80px; }
    .gfd-connected-tag.gfd-connected-below-cta.gfd-sp-raised{ bottom:94px; }
    .gfd-connected-icon i{ font-size:10px; }
    .gfd-connected-text-main{ font-size:10.5px; }
    .gfd-connected-text-dot{ font-size:9px; }
    .gfd-connected-text-label{ font-size:10px; }
}

@media (max-width:330px){
    .gfd-connected-tag{ left:9px; bottom:110px; padding:2.5px 8px 2.5px 6px; gap:5px; border-radius:6px; }
    .gfd-connected-tag.gfd-sp-raised{ bottom:122px; }
    .gfd-connected-tag.gfd-connected-below-cta{ bottom:65px; }
    .gfd-connected-tag.gfd-connected-below-cta.gfd-sp-raised{ bottom:77px; }
    .gfd-connected-icon i{ font-size:9px; }
    .gfd-connected-text-main{ font-size:10px; }
    .gfd-connected-text-dot{ font-size:8.5px; }
    .gfd-connected-text-label{ font-size:9.5px; }
}

/* ══════════════════════════════════════
   RESPONSIVE BREAKPOINTS — 790 / 490 / 390 / 330
   ══════════════════════════════════════ */
@media (max-width:790px){
    .gfd-icon-btn i{ font-size:28px; }
    .gfd-search-btn i{ font-size:22px; }
    .gfd-back-btn i{ font-size:34px; }
    .gfd-brand-label{ font-size:15px; }
    .gfd-brand-date{ font-size:11.5px; }
    .gfd-top-right-tags{ top:73px; right:13px; gap:5px; }
    .gfd-type-tag{ padding:5px 8px 5px 6px; }
    .gfd-type-tag i{ font-size:12px; }
    .gfd-type-tag span{ font-size:9.5px; }
    .gfd-media-counter{ font-size:9.5px; padding:4.5px 8px; }
    .gfd-pod-tag{ top:73px; left:13px; max-width:150px; }
    .gfd-pod-tag i{ font-size:13px; }
    .gfd-pod-tag span{ font-size:12px; }
    .gfd-top-search-btn{ top:17px; right:8px; }
    .gfd-top-search-btn i{ font-size:26px; }
    .gfd-rail{ right:10px; bottom:24px; gap:25px; }
    .gfd-rail.gfd-rail-raised{ bottom:36px; }
    .gfd-rail-btn i{ font-size:25px; }
    .gfd-rail-msg-pic{ width:28px; height:28px; border-radius:9px; }
    .gfd-rail-count{ font-size:12px; }
    .gfd-bottom{ right:0; padding:16px 70px 20px 14px; }
    .gfd-bottom.gfd-no-rail{ padding-right:14px; }
    .gfd-social-proof-strip{ left:14px; bottom:162px; }
    .gfd-social-proof-strip.gfd-sp-raised{ bottom:180px; }
    .gfd-sp-avatar{ width:40px; height:40px; }
    .gfd-user-pic{ width:44px; height:44px; }
    .gfd-nickname{ font-size:11px; }
    .gfd-username{ font-size:13.5px; }
    .gfd-track-btn{ font-size:12px; text-decoration-thickness:2.5px; top:-2px; }
    .gfd-caption-text{ font-size:12px; }
    .gfd-partnership-tag{ font-size:10.5px; padding:4.5px 9px; margin-top:7px; gap:5px; }
    .gfd-partnership-tag i{ font-size:12.5px; }
    .gfd-center-caption-text{ font-size:15px; }
    .gfd-paused-stack{ gap:12px; }
    .gfd-paused-row{ gap:18px; }
    .gfd-pause-icon{ width:54px; height:54px; }
    .gfd-pause-icon i{ font-size:26px; }
    .gfd-mute-btn{ width:48px; height:48px; }
    .gfd-mute-btn i{ font-size:20px; }
    .gfd-seek-btn{ width:36px; height:36px; }
    .gfd-seek-btn i{ font-size:16px; }
    .gfd-skel-avatar{ width:44px; height:44px; }
    .gfd-skel-line{ left:68px; }
    .gfd-skel-line1{ bottom:50px; width:120px; }
    .gfd-skel-line2{ bottom:31px; width:175px; }
    .gfd-media-arrow-left{ left:48px; }
    .gfd-media-arrow-right{ right:56px; }
    .gfd-media-arrow{ width:32px; height:32px; }
    .gfd-media-arrow i{ font-size:18px; }
    .gfd-double-tap-heart i{ font-size:80px; }
    .gfd-audio-icon{ height:10px; }
    .gfd-eq-bar:nth-child(1){ height:4.5px; }
    .gfd-eq-bar:nth-child(2){ height:10px; }
    .gfd-eq-bar:nth-child(3){ height:6.5px; }
    .gfd-audio-track{ max-width:210px; }
    .gfd-audio-track-text{ font-size:10.8px; }
}
@media (max-width:490px){
    .gfd-icon-btn i{ font-size:26px; }
    .gfd-search-btn i{ font-size:20px; }
    .gfd-back-btn i{ font-size:32px; }
    .gfd-brand-label{ font-size:14px; }
    .gfd-brand-date{ font-size:11px; }
    .gfd-top-right-tags{ top:66px; right:12px; gap:5px; }
    .gfd-type-tag{ padding:4.5px 7px 4.5px 5px; }
    .gfd-type-tag i{ font-size:11px; }
    .gfd-type-tag span{ font-size:9px; }
    .gfd-media-counter{ font-size:9px; padding:4px 7px; }
    .gfd-pod-tag{ top:66px; left:12px; max-width:135px; }
    .gfd-pod-tag i{ font-size:12px; }
    .gfd-pod-tag span{ font-size:11px; }
    .gfd-top-search-btn{ top:15px; right:7px; }
    .gfd-top-search-btn i{ font-size:24px; }
    .gfd-rail{ right:9px; bottom:22px; gap:22px; }
    .gfd-rail.gfd-rail-raised{ bottom:34px; }
    .gfd-rail-btn i{ font-size:23px; }
    .gfd-rail-msg-pic{ width:26px; height:26px; border-radius:8px; border-width:1.5px; }
    .gfd-rail-count{ font-size:11.5px; }
    .gfd-bottom{ right:0; padding:13px 62px 18px 12px; }
    .gfd-bottom.gfd-no-rail{ padding-right:12px; }
    .gfd-social-proof-strip{ left:12px; bottom:140px; }
    .gfd-social-proof-strip.gfd-sp-raised{ bottom:156px; }
    .gfd-sp-avatar{ width:37px; height:37px; border-width:2px; }
    .gfd-user-pic{ width:41px; height:41px; border-width:2px; }
    .gfd-nickname{ font-size:10.5px; }
    .gfd-username{ font-size:13px; }
    .gfd-track-btn{ font-size:11.5px; text-decoration-thickness:2.25px; top:-1.5px; }
    .gfd-caption-text{ font-size:11.5px; }
    .gfd-partnership-tag{ font-size:10px; padding:4px 8px; margin-top:6px; gap:5px; }
    .gfd-partnership-tag i{ font-size:12px; }
    .gfd-top-bar{ padding:11px 10px 28px 3px; }
    #gfd-hint{ display:none; }
    .gfd-center-caption-text{ font-size:14px; }
    .gfd-center-caption-wrap{ padding:80px 26px 120px; }
    .gfd-paused-stack{ gap:11px; }
    .gfd-paused-row{ gap:16px; }
    .gfd-pause-icon{ width:50px; height:50px; }
    .gfd-pause-icon i{ font-size:24px; }
    .gfd-mute-btn{ width:44px; height:44px; }
    .gfd-mute-btn i{ font-size:19px; }
    .gfd-seek-btn{ width:33px; height:33px; }
    .gfd-seek-btn i{ font-size:15px; }
    .gfd-skel-avatar{ width:41px; height:41px; left:12px; }
    .gfd-skel-line{ left:63px; }
    .gfd-skel-line1{ bottom:47px; width:110px; }
    .gfd-skel-line2{ bottom:29px; width:160px; }
    .gfd-skel-rail{ width:23px; height:23px; right:13px; }
    .gfd-media-arrow-left{ left:42px; }
    .gfd-media-arrow-right{ right:50px; }
    .gfd-media-arrow{ width:30px; height:30px; }
    .gfd-media-arrow i{ font-size:16px; }
    .gfd-double-tap-heart i{ font-size:70px; }
    .gfd-audio-icon{ height:9px; gap:1.5px; }
    .gfd-eq-bar:nth-child(1){ height:4px; }
    .gfd-eq-bar:nth-child(2){ height:9px; }
    .gfd-eq-bar:nth-child(3){ height:6px; }
    .gfd-audio-track{ max-width:180px; }
    .gfd-audio-track-text{ font-size:10.6px; }
}
@media (max-width:390px){
    .gfd-icon-btn i{ font-size:24px; }
    .gfd-search-btn i{ font-size:19px; }
    .gfd-back-btn i{ font-size:30px; }
    .gfd-brand-label{ font-size:13px; }
    .gfd-brand-date{ font-size:10.5px; }
    .gfd-top-right-tags{ top:60px; right:10px; gap:4px; }
    .gfd-type-tag{ padding:4px 6.5px 4px 5px; }
    .gfd-type-tag i{ font-size:10px; }
    .gfd-type-tag span{ font-size:8.5px; }
    .gfd-media-counter{ font-size:8.5px; padding:3.5px 6px; }
    .gfd-pod-tag{ top:60px; left:10px; max-width:120px; }
    .gfd-pod-tag i{ font-size:11px; }
    .gfd-pod-tag span{ font-size:10.5px; }
    .gfd-top-search-btn{ top:13px; right:6px; }
    .gfd-top-search-btn i{ font-size:22px; }
    .gfd-rail{ right:8px; bottom:20px; gap:20px; }
    .gfd-rail.gfd-rail-raised{ bottom:31px; }
    .gfd-rail-btn i{ font-size:21px; }
    .gfd-rail-msg-pic{ width:24px; height:24px; border-radius:7px; border-width:1.5px; }
    .gfd-rail-count{ font-size:11px; }
    .gfd-bottom{ right:0; padding:11px 56px 16px 10px; }
    .gfd-bottom.gfd-no-rail{ padding-right:10px; }
    .gfd-social-proof-strip{ left:10px; bottom:125px; }
    .gfd-social-proof-strip.gfd-sp-raised{ bottom:139px; }
    .gfd-sp-avatar{ width:35px; height:35px; border-width:2px; }
    .gfd-user-pic{ width:39px; height:39px; border-width:2px; }
    .gfd-nickname{ font-size:10px; }
    .gfd-username{ font-size:12.5px; }
    .gfd-track-btn{ font-size:11px; text-decoration-thickness:2px; top:-1.5px; }
    .gfd-caption-text{ font-size:11px; line-height:1.45; }
    .gfd-partnership-tag{ font-size:9.5px; padding:3.5px 7px; margin-top:5px; gap:4px; }
    .gfd-partnership-tag i{ font-size:11px; }
    .gfd-user-row{ gap:8px; margin-bottom:4px; }
    .gfd-center-caption-text{ font-size:13px; }
    .gfd-center-caption-wrap{ padding:72px 22px 110px; }
    .gfd-paused-stack{ gap:10px; }
    .gfd-paused-row{ gap:14px; }
    .gfd-pause-icon{ width:46px; height:46px; }
    .gfd-pause-icon i{ font-size:22px; }
    .gfd-mute-btn{ width:40px; height:40px; }
    .gfd-mute-btn i{ font-size:18px; }
    .gfd-seek-btn{ width:30px; height:30px; }
    .gfd-seek-btn i{ font-size:14px; }
    .gfd-skel-avatar{ width:39px; height:39px; left:10px; }
    .gfd-skel-line{ left:59px; }
    .gfd-skel-line1{ bottom:44px; width:100px; }
    .gfd-skel-line2{ bottom:27px; width:145px; }
    .gfd-skel-rail{ width:21px; height:21px; right:12px; }
    .gfd-media-arrow-left{ left:38px; }
    .gfd-media-arrow-right{ right:46px; }
    .gfd-media-arrow{ width:28px; height:28px; }
    .gfd-media-arrow i{ font-size:15px; }
    .gfd-double-tap-heart i{ font-size:62px; }
    .gfd-audio-icon{ height:8.5px; gap:1.5px; }
    .gfd-eq-bar:nth-child(1){ height:3.5px; }
    .gfd-eq-bar:nth-child(2){ height:8.5px; }
    .gfd-eq-bar:nth-child(3){ height:5.5px; }
    .gfd-audio-track{ max-width:150px; }
    .gfd-audio-track-text{ font-size:9.6px; }
}
@media (max-width:330px){
    .gfd-icon-btn i{ font-size:22px; }
    .gfd-search-btn i{ font-size:18px; }
    .gfd-back-btn i{ font-size:28px; }
    .gfd-brand-label{ font-size:12px; }
    .gfd-brand-date{ font-size:10px; }
    .gfd-top-right-tags{ top:56px; right:9px; gap:4px; }
    .gfd-type-tag{ padding:3.5px 6px 3.5px 4.5px; }
    .gfd-type-tag i{ font-size:9px; }
    .gfd-type-tag span{ font-size:8px; }
    .gfd-media-counter{ font-size:8px; padding:3px 5.5px; }
    .gfd-pod-tag{ top:56px; left:9px; max-width:105px; }
    .gfd-pod-tag i{ font-size:10px; }
    .gfd-pod-tag span{ font-size:10px; }
    .gfd-top-search-btn{ top:12px; right:6px; }
    .gfd-top-search-btn i{ font-size:20px; }
    .gfd-rail{ right:7px; bottom:18px; gap:17px; }
    .gfd-rail.gfd-rail-raised{ bottom:28px; }
    .gfd-rail-btn i{ font-size:19px; }
    .gfd-rail-msg-pic{ width:22px; height:22px; border-radius:6px; border-width:1.5px; }
    .gfd-rail-count{ font-size:10.5px; }
    .gfd-bottom{ right:0; padding:10px 50px 14px 9px; }
    .gfd-bottom.gfd-no-rail{ padding-right:9px; }
    .gfd-social-proof-strip{ left:9px; bottom:110px; }
    .gfd-social-proof-strip.gfd-sp-raised{ bottom:122px; }
    .gfd-sp-avatar{ width:32px; height:32px; border-width:1.5px; }
    .gfd-user-pic{ width:35px; height:35px; border-width:1.5px; }
    .gfd-nickname{ font-size:9.5px; }
    .gfd-username{ font-size:11.5px; }
    .gfd-track-btn{ font-size:10.5px; text-decoration-thickness:2px; top:-1px; }
    .gfd-caption-text{ font-size:10.5px; line-height:1.4; }
    .gfd-partnership-tag{ font-size:9px; padding:3px 6px; margin-top:4px; gap:4px; }
    .gfd-partnership-tag i{ font-size:10px; }
    .gfd-top-bar{ padding:9px 8px 24px 3px; }
    .gfd-center-caption-text{ font-size:12px; }
    .gfd-center-caption-wrap{ padding:64px 18px 100px; }
    .gfd-paused-stack{ gap:9px; }
    .gfd-paused-row{ gap:12px; }
    .gfd-pause-icon{ width:42px; height:42px; }
    .gfd-pause-icon i{ font-size:20px; }
    .gfd-mute-btn{ width:37px; height:37px; }
    .gfd-mute-btn i{ font-size:17px; }
    .gfd-seek-btn{ width:27px; height:27px; }
    .gfd-seek-btn i{ font-size:13px; }
    .gfd-skel-avatar{ width:35px; height:35px; left:9px; }
    .gfd-skel-line{ left:53px; }
    .gfd-skel-line1{ bottom:41px; width:90px; }
    .gfd-skel-line2{ bottom:25px; width:130px; }
    .gfd-skel-rail{ width:19px; height:19px; right:11px; }
    .gfd-media-arrow-left{ left:34px; }
    .gfd-media-arrow-right{ right:42px; }
    .gfd-media-arrow{ width:26px; height:26px; }
    .gfd-media-arrow i{ font-size:14px; }
    .gfd-double-tap-heart i{ font-size:54px; }
    .gfd-audio-icon{ height:8px; gap:1.5px; }
    .gfd-eq-bar:nth-child(1){ height:3px; }
    .gfd-eq-bar:nth-child(2){ height:8px; }
    .gfd-eq-bar:nth-child(3){ height:5px; }
    .gfd-audio-track{ max-width:130px; }
    .gfd-audio-track-text{ font-size:9px; }
}

/* ══════════════════════════════════════
   SHARE MODAL — bottom sheet (mobile) / centered (desktop)
   ══════════════════════════════════════ */
.share-modal-overlay{
    position:fixed;inset:0;background:rgba(0,0,0,.6);
    backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);
    z-index:99999997;opacity:0;pointer-events:none;
    transition:opacity .25s ease;
}
.share-modal-overlay.show{opacity:1;pointer-events:all;}

.share-modal-panel{
    position:fixed;left:0;right:0;bottom:0;
    background:rgba(12,12,28,.98);
    backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);
    border-top:1px solid rgba(255,255,255,.08);
    border-radius:20px 20px 0 0;
    z-index:99999998;
    max-height:82vh;
    display:flex;flex-direction:column;
    padding:8px 0 max(10px, env(safe-area-inset-bottom));
    transform:translateY(100%);
    transition:transform .3s cubic-bezier(.32,.72,0,1);
    box-shadow:0 -10px 40px rgba(0,0,0,.5);
    font-family:Poppins,sans-serif;
}
.share-modal-panel.show{transform:translateY(0);}

.share-modal-handle{
    width:34px;height:3.5px;border-radius:999px;
    background:rgba(255,255,255,.2);
    margin:0 auto 6px;flex-shrink:0;
}

.share-modal-header{
    display:flex;align-items:center;justify-content:space-between;
    padding:6px 18px 12px;flex-shrink:0;
}
.share-modal-title{ font-size:15px;font-weight:700;color:#fff; }
.share-modal-close{
    width:28px;height:28px;border-radius:50%;
    background:rgba(255,255,255,.07);border:none;color:rgba(255,255,255,.6);
    display:flex;align-items:center;justify-content:center;cursor:pointer;
    -webkit-tap-highlight-color:transparent;
}
.share-modal-close:hover{background:rgba(255,45,45,.18);color:#ff4c4c;}

.share-apps-row{
    display:flex;gap:14px;overflow-x:auto;
    padding:4px 18px 16px;flex-shrink:0;
    scrollbar-width:none;-ms-overflow-style:none;
}
.share-apps-row::-webkit-scrollbar{display:none;}

.share-app-btn{
    display:flex;flex-direction:column;align-items:center;gap:6px;
    flex:0 0 auto;width:60px;
    background:none;border:none;cursor:pointer;
    -webkit-tap-highlight-color:transparent;
    transition:transform .15s ease;
}
.share-app-btn:active{transform:scale(.92);}

.share-app-icon{
    width:50px;height:50px;border-radius:50%;
    display:flex;align-items:center;justify-content:center;
    font-size:22px;color:#fff;
    box-shadow:0 4px 14px rgba(0,0,0,.35);
    transition:box-shadow .2s ease;
}
.share-app-btn:hover .share-app-icon{ box-shadow:0 6px 18px rgba(0,0,0,.5); }

.share-app-icon.copy       { background:linear-gradient(135deg,#4b4b56,#2a2a33); }
.share-app-icon.whatsapp   { background:linear-gradient(135deg,#25d366,#1fa855); }
.share-app-icon.telegram   { background:linear-gradient(135deg,#2ea6de,#229ed9); }
.share-app-icon.twitter    { background:linear-gradient(135deg,#111,#000); }
.share-app-icon.facebook   { background:linear-gradient(135deg,#1877f2,#0f5ec4); }
.share-app-icon.email      { background:linear-gradient(135deg,#ff9a56,#ff6a3d); }
.share-app-icon.more       { background:linear-gradient(135deg,#ff2b2b,#c0141e); }

.share-app-label{
    font-size:10.5px;font-weight:600;color:rgba(255,255,255,.65);
    white-space:nowrap;
}

.share-apps-divider{
    display:flex;align-items:center;gap:10px;
    padding:0 18px 10px;flex-shrink:0;
}
.share-apps-divider::before,
.share-apps-divider::after{ content:'';flex:1;height:1px;background:rgba(255,255,255,.08); }
.share-apps-divider span{
    font-size:10.5px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;
    color:rgba(255,255,255,.35);white-space:nowrap;
}

.share-modal-search{
    display:flex;align-items:center;gap:8px;
    margin:0 18px 12px;padding:9px 14px;
    background:rgba(255,255,255,.06);
    border:1px solid rgba(255,255,255,.1);
    border-radius:12px;flex-shrink:0;
}
.share-modal-search i{color:rgba(255,255,255,.4);font-size:15px;}
.share-modal-search input{
    flex:1;background:none;border:none;outline:none;
    color:#fff;font-size:13px;font-family:Poppins,sans-serif;
}
.share-modal-search input::placeholder{color:rgba(255,255,255,.3);}

.share-modal-list{
    overflow-y:auto;flex:1;padding:0 8px;
    -webkit-overflow-scrolling:touch;
}
.share-modal-list::-webkit-scrollbar{width:4px;}
.share-modal-list::-webkit-scrollbar-thumb{background:rgba(255,255,255,.15);border-radius:999px;}

.share-modal-empty{
    text-align:center;padding:40px 20px;color:rgba(255,255,255,.35);font-size:13px;
}

.share-user-row{
    display:flex;align-items:center;gap:12px;
    padding:10px 12px;border-radius:12px;
    cursor:pointer;transition:background .15s;
    -webkit-tap-highlight-color:transparent;
}
.share-user-row:hover{background:rgba(255,255,255,.05);}
.share-user-row img{
    width:42px;height:42px;border-radius:50%;object-fit:cover;
    border:2px solid #ff2b2b;flex-shrink:0;
}
.share-user-name{
    flex:1;color:#fff;font-size:13.5px;font-weight:500;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
.share-user-check{
    width:22px;height:22px;border-radius:50%;
    border:2px solid rgba(255,255,255,.25);
    display:flex;align-items:center;justify-content:center;
    font-size:12px;color:transparent;flex-shrink:0;
    transition:all .15s;
}
.share-user-row.selected .share-user-check{
    background:#ff2b2b;border-color:#ff2b2b;color:#fff;
}

.share-modal-footer{
    display:flex;align-items:center;gap:12px;
    padding:12px 18px 6px;flex-shrink:0;
    border-top:1px solid rgba(255,255,255,.06);
    margin-top:6px;
}
.share-modal-selcount{ font-size:12px;color:rgba(255,255,255,.5);flex:1; }
.share-send-btn{
    padding:10px 22px;border-radius:12px;border:none;
    background:#ff2b2b;color:#fff;font-weight:700;font-size:13px;
    cursor:pointer;font-family:Poppins,sans-serif;
    transition:opacity .2s;
}
.share-send-btn:disabled{ opacity:.4;cursor:not-allowed; }
.share-send-btn:not(:disabled):hover{ background:#e01f1f; }

@media (min-width:791px){
    .share-modal-panel{
        left:50%;right:auto;bottom:auto;top:50%;
        width:100%;max-width:400px;
        border-radius:20px;
        transform:translate(-50%,-40%) scale(.95);
        opacity:0;pointer-events:none;
        max-height:560px;
    }
    .share-modal-panel.show{
        transform:translate(-50%,-50%) scale(1);
        opacity:1;pointer-events:all;
    }
    .share-modal-handle{display:none;}
}

@media (max-width:790px){
    .share-modal-panel{ max-height:80vh; }
    .share-modal-header{ padding:6px 16px 10px; }
    .share-modal-title{ font-size:14.5px; }
    .share-modal-close{ width:27px;height:27px; }
    .share-apps-row{ gap:12px; padding:4px 16px 14px; }
    .share-app-btn{ width:56px; }
    .share-app-icon{ width:46px;height:46px;font-size:20px; }
    .share-app-label{ font-size:10px; }
    .share-apps-divider{ padding:0 16px 9px; }
    .share-apps-divider span{ font-size:10px; }
    .share-modal-search{ margin:0 16px 11px; padding:8px 13px; }
    .share-modal-search input{ font-size:12.5px; }
    .share-user-row{ padding:9px 11px; gap:11px; }
    .share-user-row img{ width:40px;height:40px; }
    .share-user-name{ font-size:13px; }
    .share-modal-footer{ padding:11px 16px 6px; }
    .share-send-btn{ padding:9px 20px; font-size:12.5px; }
}

@media (max-width:490px){
    .share-modal-panel{ max-height:78vh; }
    .share-modal-header{ padding:6px 14px 9px; }
    .share-modal-title{ font-size:14px; }
    .share-modal-close{ width:26px;height:26px;font-size:14px; }
    .share-apps-row{ gap:10px; padding:4px 14px 12px; }
    .share-app-btn{ width:52px; }
    .share-app-icon{ width:42px;height:42px;font-size:18px; }
    .share-app-label{ font-size:9.5px; }
    .share-apps-divider{ padding:0 14px 8px; }
    .share-apps-divider span{ font-size:9.5px; }
    .share-modal-search{ margin:0 14px 10px; padding:8px 12px; }
    .share-modal-search i{ font-size:14px; }
    .share-modal-search input{ font-size:12px; }
    .share-user-row{ padding:8px 10px; gap:10px; }
    .share-user-row img{ width:38px;height:38px; }
    .share-user-name{ font-size:12.5px; }
    .share-user-check{ width:20px;height:20px;font-size:11px; }
    .share-modal-footer{ padding:10px 14px 6px; gap:10px; }
    .share-modal-selcount{ font-size:11px; }
    .share-send-btn{ padding:8px 18px; font-size:12px; }
}

@media (max-width:390px){
    .share-modal-panel{ max-height:76vh; border-radius:18px 18px 0 0; }
    .share-modal-handle{ width:30px;height:3px; }
    .share-modal-header{ padding:5px 12px 8px; }
    .share-modal-title{ font-size:13.5px; }
    .share-modal-close{ width:25px;height:25px;font-size:13px; }
    .share-apps-row{ gap:9px; padding:4px 12px 11px; }
    .share-app-btn{ width:48px; }
    .share-app-icon{ width:40px;height:40px;font-size:17px; }
    .share-app-label{ font-size:9px; }
    .share-apps-divider{ padding:0 12px 7px; gap:8px; }
    .share-apps-divider span{ font-size:9px; }
    .share-modal-search{ margin:0 12px 9px; padding:7px 11px; border-radius:10px; }
    .share-modal-search i{ font-size:13px; }
    .share-modal-search input{ font-size:11.5px; }
    .share-modal-list{ padding:0 6px; }
    .share-user-row{ padding:7px 9px; gap:9px; border-radius:10px; }
    .share-user-row img{ width:35px;height:35px; }
    .share-user-name{ font-size:12px; }
    .share-user-check{ width:19px;height:19px;font-size:10px; }
    .share-modal-footer{ padding:9px 12px 6px; gap:9px; }
    .share-modal-selcount{ font-size:10.5px; }
    .share-send-btn{ padding:8px 16px; font-size:11.5px; border-radius:10px; }
}

@media (max-width:330px){
    .share-modal-panel{ max-height:74vh; border-radius:16px 16px 0 0; }
    .share-modal-handle{ width:26px;height:3px;margin:0 auto 5px; }
    .share-modal-header{ padding:5px 10px 7px; }
    .share-modal-title{ font-size:13px; }
    .share-modal-close{ width:23px;height:23px;font-size:12px; }
    .share-apps-row{ gap:7px; padding:3px 10px 9px; }
    .share-app-btn{ width:44px; }
    .share-app-icon{ width:36px;height:36px;font-size:15px; }
    .share-app-label{ font-size:8.5px; }
    .share-apps-divider{ padding:0 10px 6px; gap:7px; }
    .share-apps-divider span{ font-size:8.5px; }
    .share-modal-search{ margin:0 10px 8px; padding:6px 10px; border-radius:9px; }
    .share-modal-search i{ font-size:12px; }
    .share-modal-search input{ font-size:11px; }
    .share-modal-list{ padding:0 5px; }
    .share-user-row{ padding:6px 8px; gap:8px; border-radius:9px; }
    .share-user-row img{ width:32px;height:32px;border-width:1.5px; }
    .share-user-name{ font-size:11px; }
    .share-user-check{ width:18px;height:18px;font-size:9px;border-width:1.5px; }
    .share-modal-footer{ padding:8px 10px 5px; gap:8px; }
    .share-modal-selcount{ font-size:10px; }
    .share-send-btn{ padding:7px 14px; font-size:11px; border-radius:9px; }
}
</style>


<div id="shareModalOverlay" class="share-modal-overlay" onclick="closeShareModal()"></div>
<div id="shareModalPanel" class="share-modal-panel">
    <div class="share-modal-handle"></div>

    <div class="share-modal-header">
        <span class="share-modal-title">Share to</span>
        <button class="share-modal-close" onclick="closeShareModal()"><i class="ri-close-line"></i></button>
    </div>

    <div class="share-apps-row" id="shareAppsRow">
        <button type="button" class="share-app-btn" onclick="shareViaCopyLink()">
            <span class="share-app-icon copy"><i class="ri-link"></i></span>
            <span class="share-app-label">Copy Link</span>
        </button>
        <button type="button" class="share-app-btn" onclick="shareViaWhatsApp()">
            <span class="share-app-icon whatsapp"><i class="ri-whatsapp-fill"></i></span>
            <span class="share-app-label">WhatsApp</span>
        </button>
        <button type="button" class="share-app-btn" onclick="shareViaTelegram()">
            <span class="share-app-icon telegram"><i class="ri-telegram-fill"></i></span>
            <span class="share-app-label">Telegram</span>
        </button>
        <button type="button" class="share-app-btn" onclick="shareViaTwitter()">
            <span class="share-app-icon twitter"><i class="ri-twitter-x-fill"></i></span>
            <span class="share-app-label">X</span>
        </button>
        <button type="button" class="share-app-btn" onclick="shareViaFacebook()">
            <span class="share-app-icon facebook"><i class="ri-facebook-fill"></i></span>
            <span class="share-app-label">Facebook</span>
        </button>
        <button type="button" class="share-app-btn" onclick="shareViaEmail()">
            <span class="share-app-icon email"><i class="ri-mail-fill"></i></span>
            <span class="share-app-label">Email</span>
        </button>
        <button type="button" class="share-app-btn" id="shareNativeBtn" onclick="shareViaNative()" style="display:none;">
            <span class="share-app-icon more"><i class="ri-more-fill"></i></span>
            <span class="share-app-label">More</span>
        </button>
    </div>

    <div class="share-apps-divider"><span>Or share with people you track</span></div>

    <div class="share-modal-search">
        <i class="ri-search-line"></i>
        <input type="text" id="shareSearchInput" placeholder="Search people you track…" oninput="filterShareList()">
    </div>

    <div class="share-modal-list" id="shareModalList">
        <div class="share-modal-empty">Loading…</div>
    </div>

    <div class="share-modal-footer">
        <span class="share-modal-selcount" id="shareSelCount">0 selected</span>
        <button class="share-send-btn" id="shareSendBtn" onclick="sendShare()" disabled>Send</button>
    </div>
</div>


<script>
/* ════════════════════════════════════════════════════════════
   GF MODAL JS — ported onto the exact same gapless track engine,
   chrome dim/drift, and per-post state-persistence model as the
   discover/CCM modal. Data source (peuraGroupFeed), endpoints
   (group.php's ajax_like/ajax_repost/ajax_favorite/toggle_follow),
   and public function names (openGFModal, closeGFModal,
   gfmLoadIndex, gfmToggleTrack/gfToggleTrack) are unchanged so
   every existing onclick elsewhere on the page keeps working.
   Intentionally NOT ported: CCM's cross-page IPV/dropdown-grid
   sync (window.ipvSyncActionCount, _gSyncDropdownOnAction) — GF
   posts don't live in profile.php's grids, so there's nothing on
   this page for that sync to update.
   ════════════════════════════════════════════════════════════ */
var _gfmIndex            = 0;
var _gfmMemeId            = null;
var _gfmMediaIndex        = 0;
var _gfdCaptionFullHtml   = '';
var _gfdCaptionTruncHtml  = '';
var _gfdCaptionExpanded   = false;
var _gfdInfoSwitchTimer   = null;
var _gfdVSwipeLocked      = false;
var _gfdHStack             = [];
var _gfdConnectedCache     = {};
var _gfdConnectedFetchLock = false;

/* Per-post pause state, so returning to a post you manually paused
   before scrolling away keeps it paused instead of silently
   resuming audio/video behind a stale "paused" overlay. */
var _gfdPausedMemeIds = {};

/* Per-post horizontal media (gallery) index, keyed by post id. */
var _gfdMediaIndexByPost = {};

/* Per-post attached-soundtrack playback position, keyed by the
   post's own id — NOT the audio file URL — so two different posts
   sharing the same track file stay independent, and returning to a
   post resumes its track from where it left off. */
var _gfdTrackPositions = {};

/* gfCurrentUserId, gfGroupId, CSRF_TOKEN, peuraGroupFeed are already
   declared earlier on this page — reused here, not redeclared. */

/* ── helpers ── */
function _gfdFormatDate(t){
    if(!t) return '';
    var d = new Date(t.replace(' ','T'));
    if(isNaN(d)) return t;
    var mo = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return d.getDate()+' '+mo[d.getMonth()]+' '+d.getFullYear()+' · '+
           String(d.getHours()).padStart(2,'0')+':'+String(d.getMinutes()).padStart(2,'0');
}
function _gfdTruncateName(name){
    if(!name) return '';
    return name.length > 15 ? name.slice(0,15)+'....' : name;
}
function _gfdTypeTagInfo(rawType){
    var type = (rawType||'').toLowerCase();
    var map = {
        image:{ icon:'ri-file-copy-line', label:'Photo' },
        video:{ icon:'ri-vidicon-line',   label:'Video' },
        gif:{ icon:'ri-file-gif-line',    label:'GIF'   },
        text: { icon:'ri-file-text-line', label:'Text'  }
    };
    return map[type] || { icon:'ri-file-copy-line', label:'Photo' };
}

function _gfdLinkify(caption){
    return (caption||'')
        .replace(/#([a-zA-Z0-9_]+)/g, function(match, tag){
            var mq = encodeURIComponent('#'+tag);
            return '<a href="search.php?focus=1&mq='+mq+
                   '" onclick="event.stopPropagation()" style="color:#6366f1;font-weight:600;text-decoration:none;"'+
                   ' onmouseover="this.style.textDecoration=\'underline\'"'+
                   ' onmouseout="this.style.textDecoration=\'none\'">'+match+'</a>';
        })
        .replace(/@([a-zA-Z0-9_]+)/g, function(match, username){
            return '<a href="profile.php?username='+encodeURIComponent(username)+
                   '" onclick="event.stopPropagation()" style="color:#ff2b2b;font-weight:600;text-decoration:none;"'+
                   ' onmouseover="this.style.textDecoration=\'underline\'"'+
                   ' onmouseout="this.style.textDecoration=\'none\'">'+match+'</a>';
        });
}

function _gfdEscapeHtml(str){
    if(!str) return '';
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}


/* Small toast helper, falls back gracefully to whatever toast
   mechanism this page already exposes elsewhere. */
function gfdToast(msg){
    if (typeof showToast === 'function') { showToast(msg); return; }
    if (typeof gToast === 'function') { gToast(msg); return; }
    console.log(msg);
}

/* Consolidates the full/truncated caption HTML for a post — used
   both when rendering a slide and when activating it as current
   (so _gfdCaptionFullHtml/_gfdCaptionTruncHtml, read by the expand
   toggles, always match whichever post just became current). */
function _gfdComputeCaptionHtml(p){
    var media = (Array.isArray(p.media) && p.media.length) ? p.media.slice() : [{file:p.file, type:p.type}];
    var mediaSet = ['image','video','gif'];
    var firstType = (media[0].type || '').toLowerCase();
    var isTextOnly = media.length === 1 && mediaSet.indexOf(firstType) === -1;

    var captionHtml = _gfdLinkify(p.caption || '');
    var full  = captionHtml;
    var trunc = captionHtml;

    if(isTextOnly){
        var cWords = (p.caption || '').trim().split(/\s+/).filter(Boolean);
        if(cWords.length > 40){
            var cTrunc = cWords.slice(0, 40).join(' ');
            trunc = _gfdLinkify(cTrunc)+
                '<span class="gfd-more-toggle" onclick="event.stopPropagation();gfdToggleCenterCaption()">'+
                    '<span class="gfd-ellipsis">...</span>more'+
                '</span>';
        }
    } else {
        var words = (p.caption || '').trim().split(/\s+/).filter(Boolean);
        if(words.length > 10){
            var truncPlain = words.slice(0, 10).join(' ');
            trunc = _gfdLinkify(truncPlain)+
                '<span class="gfd-more-toggle" onclick="event.stopPropagation();gfdToggleCaption()">'+
                    '<span class="gfd-ellipsis">...</span>more'+
                '</span>';
        }
    }
    return { full: full, trunc: trunc, isTextOnly: isTextOnly };
}

/* ── caption expand/collapse — scoped to the CURRENT slide via
   document.getElementById('gfd-slide'), which is unique because
   only the current position's inner .gfd-slide ever carries that
   id (prev/next slides' inner elements have no id). This matters
   now that up to 3 slides are mounted simultaneously. ── */
function gfdUpdateSocialProofVisibility(){
    var slideEl = document.getElementById('gfd-slide');
    if(!slideEl) return;
    ['#gfd-social-proof', '#gfd-cta-pill', '#gfd-connected-tag'].forEach(function(sel){
        var el = slideEl.querySelector(sel);
        if(el) el.classList.toggle('gfd-sp-hidden', _gfdCaptionExpanded);
    });
}

function _gfdIsInsideExpandedCaption(target){
    if(!_gfdCaptionExpanded || !target || !target.closest) return false;
    return !!(target.closest('.gfd-caption-wrap') || target.closest('.gfd-center-caption-wrap'));
}

function _gfdCheckCaptionOverflow(slide){
    if(!slide) return;
    var wrap = slide.querySelector('.gfd-center-caption-wrap');
    var text = slide.querySelector('.gfd-center-caption-text');
    if(!wrap || !text) return;
    var isOverflowing = text.scrollHeight > wrap.clientHeight;
    wrap.classList.toggle('gfd-caption-overflow', isOverflowing);
}

function gfdToggleCaption(){
    var slideEl = document.getElementById('gfd-slide');
    if(!slideEl) return;
    var textEl = slideEl.querySelector('#gfd-caption-text');
    var wrapEl = slideEl.querySelector('#gfd-caption-wrap');
    if(!textEl) return;
    _gfdCaptionExpanded = !_gfdCaptionExpanded;
    if(_gfdCaptionExpanded){
        textEl.innerHTML = _gfdCaptionFullHtml+
            ' <span class="gfd-more-toggle" onclick="event.stopPropagation();gfdToggleCaption()"> see less</span>';
        if(wrapEl) wrapEl.classList.add('gfd-expanded');
    } else {
        textEl.innerHTML = _gfdCaptionTruncHtml;
        if(wrapEl){
            wrapEl.classList.remove('gfd-expanded');
            wrapEl.scrollTop = 0;
        }
    }
    gfdUpdateSocialProofVisibility();
}

function gfdToggleCenterCaption(){
    var slideEl = document.getElementById('gfd-slide');
    if(!slideEl) return;
    var textEl = slideEl.querySelector('#gfd-center-caption-text');
    var wrapEl = slideEl.querySelector('#gfd-center-caption-wrap');
    if(!textEl) return;
    _gfdCaptionExpanded = !_gfdCaptionExpanded;
    if(_gfdCaptionExpanded){
        textEl.innerHTML = _gfdCaptionFullHtml+
            ' <span class="gfd-more-toggle" onclick="event.stopPropagation();gfdToggleCenterCaption()"> see less</span>';
    } else {
        textEl.innerHTML = _gfdCaptionTruncHtml;
        textEl.scrollTop = 0;
    }
    gfdUpdateSocialProofVisibility();
    var slide = wrapEl ? wrapEl.closest('.gfd-slide') : null;
    if(slide) _gfdCheckCaptionOverflow(slide);
}

/* ── skeleton ── */
function _gfdSkeletonHtml(){
    return '<div class="gfd-skeleton" id="gfd-skeleton">'+
        '<div class="gfd-skel-block gfd-skel-media"></div>'+
        '<div class="gfd-skel-block gfd-skel-avatar"></div>'+
        '<div class="gfd-skel-block gfd-skel-line gfd-skel-line1"></div>'+
        '<div class="gfd-skel-block gfd-skel-line gfd-skel-line2"></div>'+
        '<div class="gfd-skel-block gfd-skel-rail gfd-skel-rail1"></div>'+
        '<div class="gfd-skel-block gfd-skel-rail gfd-skel-rail2"></div>'+
        '<div class="gfd-skel-block gfd-skel-rail gfd-skel-rail3"></div>'+
        '<div class="gfd-skel-block gfd-skel-rail gfd-skel-rail4"></div>'+
        '<div class="gfd-skel-shimmer"></div>'+
    '</div>';
}
function gfdHideSkeleton(root){
    var sk = root ? root.querySelector('#gfd-skeleton') : document.getElementById('gfd-skeleton');
    if(!sk) return;
    sk.classList.add('gfd-skeleton-hide');
    setTimeout(function(){ if(sk && sk.parentNode) sk.remove(); }, 340);
}

/* ── audio title marquee ── */
function _gfdInitAudioMarquee(slide){
    if(!slide) return;
    var track = slide.querySelector('#gfd-audio-track');
    var text  = slide.querySelector('#gfd-audio-track-text');
    if(!track || !text) return;
    requestAnimationFrame(function(){
        var overflow = text.scrollWidth - track.clientWidth;
        if(overflow > 4){
            text.classList.add('gfd-marquee');
            text.style.setProperty('--marquee-dist', (-overflow - 6)+'px');
            var dur = Math.max(4.5, Math.min(14, overflow / 22));
            text.style.setProperty('--marquee-dur', dur+'s');
        }
    });
}

/* ── nickname / audio-title switcher ── */
function _gfdInitInfoSwitcher(slide){
    if(_gfdInfoSwitchTimer){ clearInterval(_gfdInfoSwitchTimer); _gfdInfoSwitchTimer = null; }
    if(!slide) return;
    var switcher = slide.querySelector('#gfd-info-switcher');
    if(!switcher) return;
    var panes = switcher.querySelectorAll('.gfd-switch-pane');
    if(panes.length < 2) return;

    var activeIdx = 0;
    panes.forEach(function(p, i){ p.classList.toggle('active', i === 0); });

    _gfdInfoSwitchTimer = setInterval(function(){
        panes[activeIdx].classList.remove('active');
        activeIdx = (activeIdx + 1) % panes.length;
        panes[activeIdx].classList.add('active');
    }, 5000);
}

/* ── attached soundtrack playback (per-post-id position memory) ── */
function _gfdEnsureSoundtrackEl(){
    var stage = document.getElementById('gfd-stage');
    if(!stage) return null;
    var el = document.getElementById('gfdSoundtrackPlayer');
    if(!el){
        el = document.createElement('audio');
        el.id = 'gfdSoundtrackPlayer';
        el.loop = true;
        el.style.display = 'none';
        stage.appendChild(el);
    }
    return el;
}

function _gfdSyncSoundtrack(p){
    var audioEl = _gfdEnsureSoundtrackEl();
    if(!audioEl) return;

    var prevMemeId = audioEl.dataset.memeId || null;

    if(!p || !p.audioFile){
        if(prevMemeId) _gfdTrackPositions[prevMemeId] = audioEl.currentTime || 0;
        audioEl.pause();
        audioEl.removeAttribute('src');
        delete audioEl.dataset.memeId;
        audioEl.load();
        return;
    }

    var isSamePost = prevMemeId !== null && String(prevMemeId) === String(p.id);

    if(!isSamePost){
        if(prevMemeId) _gfdTrackPositions[prevMemeId] = audioEl.currentTime || 0;
        audioEl.src = p.audioFile;
        audioEl.dataset.memeId = p.id;
        var savedTime = _gfdTrackPositions[p.id] || 0;
        audioEl.currentTime = savedTime;
        audioEl.muted = false;
    }
    audioEl.volume = (p.musicVolume !== undefined && p.musicVolume !== null ? p.musicVolume : 80) / 100;

    var curSlide = document.getElementById('gfd-slide');
    var mb = curSlide ? curSlide.querySelector('#gfd-media-box') : null;

    if(_gfdPausedMemeIds[p.id]){
        audioEl.pause();
        if(mb){
            var activeItemForPause = mb.querySelector('.gfd-media-item[data-idx="'+_gfmMediaIndex+'"]') || mb;
            gfdShowPausedControls(activeItemForPause, audioEl);
        }
    } else {
        audioEl.play().catch(function(){});
    }

    var videoAudioVol = (p.videoAudioVolume !== undefined && p.videoAudioVolume !== null ? p.videoAudioVolume : 40) / 100;
    document.querySelectorAll('.gfd-vslide[data-pos="current"] video.gfd-media').forEach(function(v){
        v.volume = videoAudioVol;
    });
}

function _gfdPauseSoundtrack(){
    var el = document.getElementById('gfdSoundtrackPlayer');
    if(el) el.pause();
}
function _gfdResumeSoundtrack(){
    var el = document.getElementById('gfdSoundtrackPlayer');
    if(el && el.src) el.play().catch(function(){});
}
function _gfdStopSoundtrack(){
    var el = document.getElementById('gfdSoundtrackPlayer');
    if(el){
        var leavingMemeId = el.dataset.memeId || null;
        if(leavingMemeId) _gfdTrackPositions[leavingMemeId] = el.currentTime || 0;
        el.pause();
        el.removeAttribute('src');
        delete el.dataset.memeId;
        el.load();
    }
}

/* ══════════════════════════════════════════════════════════
   HIDE POST — modal-side counterpart to gfHidePost/gfUndoHidePost
   (those two live in an earlier <script> block, near
   gfLoadHiddenIds/gfSaveHiddenIds). Whenever a hide happens while
   the gf modal is open on that exact post, this overlays a
   "Post Hidden" card on top of #gfd-slide with its own Undo button,
   and pauses whatever media/audio was playing underneath it.

   The wiring that calls these two functions from gfHidePost/
   gfUndoHidePost lives in the monkey-patch block at the very
   bottom of this file (_gfdPatchHideFns) — it wraps those two
   functions in place once the page has fully loaded, so nothing
   needs to be hand-edited in that other script block anymore.
   ══════════════════════════════════════════════════════════ */
function gfdHideHiddenOverlay(memeId){
    var slideEl = document.getElementById('gfd-slide');
    if(!slideEl){ delete _gfdPausedMemeIds[memeId]; return; }
    var wasBuiltHidden = slideEl.classList.contains('gfd-slide-hidden');
    if(wasBuiltHidden){
        // This slide never had real media built for it — build it now.
        slideEl.classList.remove('gfd-slide-hidden');
        var item = peuraGroupFeed.find(function(p){ return String(p.id) === String(memeId); });
        if(item){
            slideEl.innerHTML = _gfdRenderPost(item, false);
            _gfmMediaIndex = _gfdGetSavedMediaIndex(item.id);
            delete _gfdPausedMemeIds[memeId];
            _gfdInitMediaSwipe(slideEl);
            _gfdInitAudioMarquee(slideEl);
            _gfdInitInfoSwitcher(slideEl);
            slideEl.querySelectorAll('.gfd-video-progress').forEach(gfdInitProgressScrub);
            gfdUpdateMediaGallery(slideEl, false);
            gfdHideSkeleton(slideEl);
            _gfdSyncSoundtrack(item);
        }
        return;
    }
    // Live-hide case: overlay was appended on top of already-rendered media.
    var overlay = slideEl.querySelector('.gfd-hidden-overlay');
    if(overlay) overlay.remove();
    delete _gfdPausedMemeIds[memeId];
    if (typeof _gfmMemeId !== 'undefined' && String(_gfmMemeId) === String(memeId)) {
        var video = slideEl.querySelector('.gfd-media-item[data-idx="'+_gfmMediaIndex+'"] video.gfd-media');
        if(video) video.play().catch(function(){});
        _gfdResumeSoundtrack();
    }
}

function _gfdIsHidden(id){
    return id !== null && id !== undefined
        && typeof gfLoadHiddenIds === 'function'
        && gfLoadHiddenIds().indexOf(String(id)) !== -1;
}

function _gfdRenderHiddenSlideContent(memeId){
    return '<div class="gfd-hidden-overlay">'+
        '<button type="button" class="gfd-hidden-search-btn" onclick="event.stopPropagation();if(typeof openGroupSearchModal===\'function\')openGroupSearchModal();"><i class="ri-search-line"></i></button>'+
        '<div class="gfd-hidden-pill-row">'+
            '<button type="button" class="gfd-hidden-pill" onclick="event.stopPropagation();window.location.href=\'memepedia.php\';">'+
                '<i class="ri-book-2-line"></i><span>Memepedia</span>'+
            '</button>'+
            '<button type="button" class="gfd-hidden-pill" onclick="event.stopPropagation();window.location.href=\'group_highlights.php\';">'+
                '<i class="ri-star-line"></i><span>Highlights</span>'+
            '</button>'+
            '<button type="button" class="gfd-hidden-pill" onclick="event.stopPropagation();window.location.href=\'group_eveents.php\';">'+
                '<i class="ri-calendar-event-line"></i><span>Events</span>'+
            '</button>'+
        '</div>'+
        '<div class="gfd-hidden-icon"><i class="ri-eye-off-line"></i></div>'+
        '<div class="gfd-hidden-title">Post Hidden</div>'+
        '<div class="gfd-hidden-sub">You won\'t see this post in your feed anymore.</div>'+
        '<button type="button" class="gfd-hidden-undo" onclick="event.stopPropagation();gfUndoHidePost('+memeId+')">'+
            '<i class="ri-arrow-go-back-line"></i> Undo'+
        '</button>'+
    '</div>';
}

/* Was referenced by the monkey-patch further down the file but never
   defined — this is what fires when a post gets hidden (via the kebab
   menu's "Hide Post") while it's the currently-open slide in the modal.
   Without this, that call silently threw and could leave the swipe
   engine in a broken state right at the point a hidden post appears. */
function gfdShowHiddenOverlay(memeId){
    var slideEl = document.getElementById('gfd-slide');
    if(!slideEl) return;
    if(typeof _gfmMemeId === 'undefined' || String(_gfmMemeId) !== String(memeId)) return;
    if(slideEl.querySelector('.gfd-hidden-overlay')) return;

    var activeItem = slideEl.querySelector('.gfd-media-item[data-idx="'+_gfmMediaIndex+'"]')
                   || slideEl.querySelector('#gfd-media-box');
    if(activeItem){
        var video = activeItem.querySelector('video.gfd-media');
        if(video) video.pause();
    }
    _gfdPauseSoundtrack();
    _gfdPausedMemeIds[memeId] = true;
    if(_gfdInfoSwitchTimer){ clearInterval(_gfdInfoSwitchTimer); _gfdInfoSwitchTimer = null; }

    var wrap = document.createElement('div');
    wrap.innerHTML = _gfdRenderHiddenSlideContent(memeId);
    slideEl.appendChild(wrap.firstElementChild);
}


/* ══════════════════════════════════════════════════════════
   VIDEO PROGRESS BAR — brought in line with home.php. Reads/writes
   a SINGLE bar per slide that lives as a sibling of .gfd-bottom
   (built once in _gfdRenderPost, appended after the .gfd-bottom
   block) — NOT one bar per .gfd-media-item nested inside the
   transformed .gfd-media-gallery. That nesting was the reason the
   old bar could only ever be a passive fill indicator: burying it
   inside a transform:translateX(...) element traps it in its own
   stacking context, and it also had no separate hit-target or drag
   handlers bound to it at all. Skips updating while the user is
   actively dragging the thumb (bar has the "scrubbing" class).
   ══════════════════════════════════════════════════════════ */
function gfdUpdateProgress(video){
    if(!video.duration || isNaN(video.duration)) return;
    var slide = video.closest('.gfd-slide');
    var bar = slide ? slide.querySelector('.gfd-video-progress') : null;
    if(!bar || bar.classList.contains('scrubbing')) return;
    var pct = video.currentTime / video.duration * 100;
    var fill  = bar.querySelector('.gfd-video-progress-fill');
    var thumb = bar.querySelector('.gfd-video-progress-thumb');
    if(fill)  fill.style.width = pct+'%';
    if(thumb) thumb.style.left = pct+'%';
}

/* ── tap handling: single tap (play/pause or gallery nav) vs
   double tap (like + heart burst) ── */
function gfdHandleMediaTap(e, mediaBox, isGallery){
    var now = Date.now();
    var lastTap = mediaBox._gfdLastTap || 0;
    var dt = now - lastTap;

    if(dt > 0 && dt < 320){
        mediaBox._gfdLastTap = 0;
        if(mediaBox._gfdTapTimer){ clearTimeout(mediaBox._gfdTapTimer); mediaBox._gfdTapTimer = null; }
        gfdDoubleTapLike(mediaBox, e);
    } else {
        mediaBox._gfdLastTap = now;
        mediaBox._gfdTapTimer = setTimeout(function(){
            if(isGallery) gfdMediaBoxTap(mediaBox);
            else gfdToggleVideoPlay(mediaBox);
        }, 260);
    }
}

function gfdDoubleTapLike(mediaBox, e){
    var rect = mediaBox.getBoundingClientRect();
    var cx = (e && typeof e.clientX === 'number' && e.clientX !== 0) ? e.clientX : (rect.left + rect.width/2);
    var cy = (e && typeof e.clientY === 'number' && e.clientY !== 0) ? e.clientY : (rect.top + rect.height/2);
    gfdShowHeartBurst(mediaBox, cx - rect.left, cy - rect.top);

    var slideEl = document.getElementById('gfd-slide');
    var likeBtn = slideEl ? slideEl.querySelector('#gfd-like-btn') : null;
    if(likeBtn && !likeBtn.classList.contains('active')){
        gfdAction('like');
    }
}

function gfdShowHeartBurst(mediaBox, x, y){
    mediaBox.querySelectorAll('.gfd-double-tap-heart, .gfd-heart-ring, .gfd-heart-particle')
        .forEach(function(el){ el.remove(); });

    var ring = document.createElement('div');
    ring.className = 'gfd-heart-ring';
    ring.style.left = x+'px';
    ring.style.top  = y+'px';
    mediaBox.appendChild(ring);
    requestAnimationFrame(function(){ ring.classList.add('show'); });
    setTimeout(function(){ ring.remove(); }, 800);

    var heart = document.createElement('div');
    heart.className = 'gfd-double-tap-heart';
    heart.style.left = x+'px';
    heart.style.top  = y+'px';
    heart.innerHTML = '<i class="ri-heart-3-fill"></i>';
    mediaBox.appendChild(heart);
    requestAnimationFrame(function(){ heart.classList.add('show'); });
    setTimeout(function(){ heart.remove(); }, 950);

    var particleCount = 8;
    for(var i = 0; i < particleCount; i++){
        (function(i){
            var angle    = (360 / particleCount) * i + (Math.random()*20 - 10);
            var rad      = angle * Math.PI / 180;
            var distance = 70 + Math.random()*50;
            var tx       = Math.cos(rad) * distance;
            var ty       = Math.sin(rad) * distance;
            var rot      = (Math.random()*80 - 40);
            var size     = 14 + Math.random()*12;

            var p = document.createElement('div');
            p.className = 'gfd-heart-particle';
            p.style.left = x+'px';
            p.style.top  = y+'px';
            p.style.setProperty('--tx', tx+'px');
            p.style.setProperty('--ty', ty+'px');
            p.style.setProperty('--tr', rot+'deg');
            p.innerHTML = '<i class="ri-heart-3-fill" style="font-size:'+size+'px;"></i>';
            mediaBox.appendChild(p);

            setTimeout(function(){
                requestAnimationFrame(function(){ p.classList.add('show'); });
            }, 40 + i*15);

            setTimeout(function(){ p.remove(); }, 950 + i*15);
        })(i);
    }
}

/* ── tap to pause/resume + mute + 10s seek — falls back to the
   attached soundtrack when the current slide has no local video
   (image/text post with a track attached). ── */
function gfdToggleVideoPlay(mediaBox){
    var video = mediaBox.querySelector('video.gfd-media');
    if(!video){
        var audioEl = document.getElementById('gfdSoundtrackPlayer');
        if(audioEl && audioEl.src){
            if(audioEl.paused){
                audioEl.play().catch(function(){});
                if(_gfmMemeId) delete _gfdPausedMemeIds[_gfmMemeId];
                gfdHidePausedControls(mediaBox);
                gfdFlashIcon(mediaBox,'ri-play-fill');
            } else {
                audioEl.pause();
                if(_gfmMemeId) _gfdPausedMemeIds[_gfmMemeId] = true;
                gfdShowPausedControls(mediaBox, audioEl);
            }
        }
        return;
    }
    if(video.paused){
        video.play();
        _gfdResumeSoundtrack();
        if(_gfmMemeId) delete _gfdPausedMemeIds[_gfmMemeId];
        gfdHidePausedControls(mediaBox);
        gfdFlashIcon(mediaBox, 'ri-play-fill');
    } else {
        video.pause();
        _gfdPauseSoundtrack();
        if(_gfmMemeId) _gfdPausedMemeIds[_gfmMemeId] = true;
        gfdShowPausedControls(mediaBox, video);
    }
}

function gfdMediaBoxTap(mediaBox){
    var activeItem = mediaBox.querySelector('.gfd-media-item[data-idx="'+_gfmMediaIndex+'"]');
    if(!activeItem) return;
    var video = activeItem.querySelector('video.gfd-media');

    if(video){
        if(video.paused){
            video.play();
            _gfdResumeSoundtrack();
            if(_gfmMemeId) delete _gfdPausedMemeIds[_gfmMemeId];
            gfdHidePausedControls(activeItem);
            gfdFlashIcon(activeItem, 'ri-play-fill');
        } else {
            video.pause();
            _gfdPauseSoundtrack();
            if(_gfmMemeId) _gfdPausedMemeIds[_gfmMemeId] = true;
            gfdShowPausedControls(activeItem, video);
        }
        return;
    }

    var audioEl = document.getElementById('gfdSoundtrackPlayer');
    if(audioEl && audioEl.src){
        if(audioEl.paused){
            audioEl.play().catch(function(){});
            if(_gfmMemeId) delete _gfdPausedMemeIds[_gfmMemeId];
            gfdHidePausedControls(activeItem);
            gfdFlashIcon(activeItem, 'ri-play-fill');
        } else {
            audioEl.pause();
            if(_gfmMemeId) _gfdPausedMemeIds[_gfmMemeId] = true;
            gfdShowPausedControls(activeItem, audioEl);
        }
    }
}

function gfdFlashIcon(mediaBox, iconClass){
    var existing = mediaBox.querySelector('.gfd-tap-flash');
    if(existing) existing.remove();
    var icon = document.createElement('div');
    icon.className = 'gfd-tap-flash';
    icon.innerHTML = '<i class="'+iconClass+'"></i>';
    mediaBox.appendChild(icon);
    requestAnimationFrame(function(){ icon.classList.add('show'); });
    setTimeout(function(){
        icon.classList.remove('show');
        setTimeout(function(){ icon.remove(); }, 220);
    }, 450);
}

function gfdShowPausedControls(mediaBox, video){
    gfdHidePausedControls(mediaBox);

    var trackEl  = document.getElementById('gfdSoundtrackPlayer');
    var hasTrack = trackEl && trackEl.src;
    var soundSrc = hasTrack ? trackEl : video;
    var isMuted  = soundSrc ? !!soundSrc.muted : false;

    var wrap = document.createElement('div');
    wrap.className = 'gfd-paused-controls';
    wrap.innerHTML =
        '<div class="gfd-paused-stack">'+
            '<button type="button" class="gfd-mute-btn" onclick="event.stopPropagation();gfdToggleMute(this)">'+
                '<i class="'+(isMuted ? 'ri-volume-mute-fill' : 'ri-volume-up-fill')+'"></i>'+
            '</button>'+
            '<div class="gfd-paused-row">'+
                '<button type="button" class="gfd-seek-btn" onclick="event.stopPropagation();gfdSeekVideo(this,-10)" title="Back 10s">'+
                    '<i class="ri-replay-10-line"></i>'+
                '</button>'+
                '<div class="gfd-pause-icon"><i class="ri-pause-fill"></i></div>'+
                '<button type="button" class="gfd-seek-btn" onclick="event.stopPropagation();gfdSeekVideo(this,10)" title="Forward 10s">'+
                    '<i class="ri-forward-10-line"></i>'+
                '</button>'+
            '</div>'+
        '</div>';
    mediaBox.appendChild(wrap);
}
function gfdHidePausedControls(mediaBox){
    var existing = mediaBox.querySelector('.gfd-paused-controls');
    if(existing) existing.remove();
}

function gfdToggleMute(btn){
    var container = btn.closest('.gfd-media-box') || btn.closest('.gfd-media-item');
    var video    = container ? container.querySelector('video.gfd-media') : null;
    var trackEl  = document.getElementById('gfdSoundtrackPlayer');
    var hasTrack = trackEl && trackEl.src;
    var media    = hasTrack ? trackEl : video;
    if(!media) return;

    media.muted = !media.muted;

    var icon = btn.querySelector('i');
    icon.className = media.muted ? 'ri-volume-mute-fill' : 'ri-volume-up-fill';
}

function gfdSeekVideo(btn, delta){
    var container = btn.closest('.gfd-media-box') || btn.closest('.gfd-media-item');
    var video    = container ? container.querySelector('video.gfd-media') : null;
    var trackEl  = document.getElementById('gfdSoundtrackPlayer');
    var hasTrack = trackEl && trackEl.src;
    var media    = hasTrack ? trackEl : video;
    if(!media || isNaN(media.duration)) return;

    var newTime = media.currentTime + delta;
    if(newTime < 0) newTime = 0;
    if(media.duration && newTime > media.duration) newTime = media.duration;
    media.currentTime = newTime;

    if(media === video) gfdUpdateProgress(video);
}

/* ══════════════════════════════════════════════════════════
   DRAG-TO-SEEK — ported from home.php, unmodified in behavior.
   ══════════════════════════════════════════════════════════ */
function gfdInitProgressScrub(bar){
    if(!bar || bar._gfdScrubBound) return;
    bar._gfdScrubBound = true;

    var track = bar.querySelector('.gfd-video-progress-track');
    var fill  = bar.querySelector('.gfd-video-progress-fill');
    var thumb = bar.querySelector('.gfd-video-progress-thumb');

    function getMedia(){
        var slide = bar.closest('.gfd-slide');
        var video = slide ? (
            slide.querySelector('.gfd-media-item[data-idx="'+_gfmMediaIndex+'"] video.gfd-media')
            || slide.querySelector('video.gfd-media')
        ) : null;
        var trackEl = document.getElementById('gfdSoundtrackPlayer');
        var hasTrack = trackEl && trackEl.src && trackEl.dataset.memeId && String(trackEl.dataset.memeId) === String(_gfmMemeId);
        return { video: video, audio: hasTrack ? trackEl : null };
    }
    function pctFromX(x){
        var r = track.getBoundingClientRect();
        return Math.max(0, Math.min(1, (x - r.left) / r.width));
    }
    function paint(pct){
        fill.style.width = (pct*100)+'%';
        thumb.style.left = (pct*100)+'%';
    }
    function seekTo(pct){
        var m = getMedia();
        if(m.video && !isNaN(m.video.duration)) m.video.currentTime = pct * m.video.duration;
        if(m.audio && !isNaN(m.audio.duration))  m.audio.currentTime = pct * m.audio.duration;
    }

    var dragging = false;

    function start(x){
        dragging = true;
        bar.classList.add('scrubbing');
        var m = getMedia();
        if(m.video) m.video.pause();
        if(m.audio) m.audio.pause();
        paint(pctFromX(x));
    }
    function move(x){
        if(!dragging) return;
        var pct = pctFromX(x);
        paint(pct);
        seekTo(pct);
    }
    function end(x){
        if(!dragging) return;
        dragging = false;
        seekTo(pctFromX(x));
        bar.classList.remove('scrubbing');
        var wasManuallyPaused = _gfmMemeId ? !!_gfdPausedMemeIds[_gfmMemeId] : false;
        if(!wasManuallyPaused){
            var m = getMedia();
            if(m.video) m.video.play().catch(function(){});
            if(m.audio) m.audio.play().catch(function(){});
        }
    }

    bar.addEventListener('touchstart', function(e){ e.stopPropagation(); start(e.touches[0].clientX); }, {passive:true});
    bar.addEventListener('touchmove',  function(e){ e.stopPropagation(); move(e.touches[0].clientX);  }, {passive:true});
    bar.addEventListener('touchend',   function(e){ e.stopPropagation(); end(e.changedTouches[0].clientX); }, {passive:true});

    bar.addEventListener('mousedown', function(e){
        e.stopPropagation();
        start(e.clientX);
        function onMove(ev){ move(ev.clientX); }
        function onUp(ev){
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
            end(ev.clientX);
        }
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
    });

    bar.addEventListener('click', function(e){ e.stopPropagation(); });
}

function gfdFixObjectFit(el){
    var box = el.closest('.gfd-media-item') || el.closest('.gfd-media-box');
    if(!box) return;
    var mW = el.tagName === 'VIDEO' ? el.videoWidth  : el.naturalWidth;
    var mH = el.tagName === 'VIDEO' ? el.videoHeight : el.naturalHeight;
    if(!mW || !mH) return;
    var cW = box.clientWidth, cH = box.clientHeight;
    if(!cW || !cH) return;
    var mediaAspect = mW / mH;
    var boxAspect   = cW / cH;

    // Taller/equal than the box → always cover. This scales by width to
    // fill it exactly and only trims overflow off the top/bottom — sides
    // are never touched in this case, so it's safe.
    if(mediaAspect < boxAspect){
        el.style.objectFit = 'cover';
        return;
    }

    // Wider than the box → 'contain' already fills the width exactly
    // (that's never the issue here), it only leaves letterbox bars top/
    // bottom. If the media's height comes close enough to filling the box
    // once scaled to full width, STRETCH it (never crop) to close that
    // gap. If it's far off, leave 'contain' so nothing gets cut off.
    var scaledHeight = cW / mediaAspect;   // height once scaled to fill box width
    var fillRatio    = scaledHeight / cH;  // how close that is to full box height

    // Tweak this threshold: raise it to be stricter (fewer items get
    // stretched/filled), lower it to be more lenient (more items fill
    // edge-to-edge instead of showing letterbox bars).
    var FILL_THRESHOLD = 0.84;

    // 'fill' stretches height only (width is already exactly cW under
    // 'contain', so it stays untouched) — this closes the gap without
    // ever cropping the sides, unlike 'cover' which would crop them.
    el.style.objectFit = (fillRatio >= FILL_THRESHOLD) ? 'fill' : 'contain';
}

function _gfdComputeDashGap(count){
    return Math.max(3, Math.min(6, Math.round(60 / count)));
}

/* ── per-post saved gallery index ── */
function _gfdGetSavedMediaIndex(postId){
    if(postId === null || postId === undefined) return 0;
    var v = _gfdMediaIndexByPost[postId];
    return (typeof v === 'number' && v >= 0) ? v : 0;
}
function _gfdSaveMediaIndex(postId, idx){
    if(postId === null || postId === undefined) return;
    _gfdMediaIndexByPost[postId] = idx;
}

/* ════════════════════════════════════════════════════════════
   SAVED TOAST
   ════════════════════════════════════════════════════════════ */
var _gfdSavedToastTimer = null;

function gfdShowSavedToast(memeId){
    var existing = document.getElementById('gfdSavedToast');
    if(existing) existing.remove();
    clearTimeout(_gfdSavedToastTimer);

    var toast = document.createElement('div');
    toast.id = 'gfdSavedToast';
    toast.className = 'gfd-saved-toast';
    toast.innerHTML =
        '<div class="gfd-saved-toast-left">'+
            '<span class="gfd-saved-toast-check"><i class="ri-check-line"></i></span>'+
            'Saved'+
        '</div>'+
        '<div class="gfd-saved-toast-right" onclick="gfdToastOpenCollections('+memeId+')">'+
            'Collections<i class="ri-arrow-right-s-line"></i>'+
        '</div>';

    document.body.appendChild(toast);
    requestAnimationFrame(function(){ toast.classList.add('show'); });

    _gfdSavedToastTimer = setTimeout(function(){
        toast.classList.remove('show');
        setTimeout(function(){ toast.remove(); }, 300);
    }, 3500);
}

function gfdHideSavedToast(){
    var toast = document.getElementById('gfdSavedToast');
    if(!toast) return;
    clearTimeout(_gfdSavedToastTimer);
    toast.classList.remove('show');
    setTimeout(function(){ toast.remove(); }, 300);
}

function gfdToastOpenCollections(memeId){
    gfdHideSavedToast();
    if(typeof openSaveModal === 'function'){
        openSaveModal(memeId);
    }
}

/* ════════════════════════════════════════════════════════════
   MULTI-MEDIA GALLERY — swipe/nav within one post
   ════════════════════════════════════════════════════════════ */
function gfdUpdateMediaGallery(slide, suppressAutoplay){
    var gallery = slide.querySelector('#gfd-media-gallery');
    if(!gallery) return;
    gallery.style.transform = 'translateX(-'+(_gfmMediaIndex*100)+'%)';

    gallery.querySelectorAll('.gfd-media-item').forEach(function(itemEl){
        var idx = parseInt(itemEl.dataset.idx, 10);
        var vid = itemEl.querySelector('video.gfd-media');
        if(vid){
            if(idx === _gfmMediaIndex){
                if(vid.preload !== 'auto'){ vid.preload = 'auto'; }
                if(suppressAutoplay){
                    vid.pause();
                    gfdShowPausedControls(itemEl, vid);
                } else {
                    vid.play().catch(function(){});
                }
            } else if(Math.abs(idx - _gfmMediaIndex) === 1){
                if(vid.preload !== 'auto'){ vid.preload = 'auto'; }
                vid.pause();
            } else {
                vid.pause();
            }
        }
        if(idx !== _gfmMediaIndex) gfdHidePausedControls(itemEl);
    });

    slide.querySelectorAll('.gfd-dash').forEach(function(d, i){
        d.classList.toggle('active', i === _gfmMediaIndex);
    });

    var counterEl = slide.querySelector('#gfd-media-counter');
    if(counterEl){
        var total = gallery.querySelectorAll('.gfd-media-item').length;
        counterEl.textContent = (_gfmMediaIndex+1)+'/'+total;
    }

    var activeItem = gallery.querySelector('.gfd-media-item[data-idx="'+_gfmMediaIndex+'"]');
    var typeTagEl  = slide.querySelector('#gfd-type-tag');
    if(activeItem && typeTagEl){
        var info = _gfdTypeTagInfo(activeItem.dataset.type);
        typeTagEl.innerHTML = '<i class="'+info.icon+'"></i><span>'+info.label+'</span>';
    }

    var progressBar = slide.querySelector('.gfd-video-progress');
    if(progressBar){
        var activeVid = activeItem ? activeItem.querySelector('video.gfd-media') : null;
        progressBar.style.display = activeVid ? 'flex' : 'none';
        gfdInitProgressScrub(progressBar);
    }
}

function gfdMediaNext(){
    var slide = document.getElementById('gfd-slide');
    if(!slide) return;
    var mediaBox = slide.querySelector('#gfd-media-box');
    if(!mediaBox) return;
    var count = parseInt(mediaBox.dataset.count || '1', 10);
    if(_gfmMediaIndex < count - 1){
        _gfmMediaIndex++;
        _gfdSaveMediaIndex(_gfmMemeId, _gfmMediaIndex);
        gfdUpdateMediaGallery(slide);
    }
}

function gfdMediaPrev(){
    var slide = document.getElementById('gfd-slide');
    if(!slide) return;
    if(_gfmMediaIndex > 0){
        _gfmMediaIndex--;
        _gfdSaveMediaIndex(_gfmMemeId, _gfmMediaIndex);
        gfdUpdateMediaGallery(slide);
    }
}

function _gfdInitMediaSwipe(slide){
    var gallery  = slide.querySelector('#gfd-media-gallery');
    var mediaBox = slide.querySelector('#gfd-media-box');
    if(!gallery || !mediaBox) return;
    if(mediaBox._gfdSwipeBound) return;
    var mediaCount = parseInt(mediaBox.dataset.count || '1', 10);
    if(mediaCount <= 1) return;
    mediaBox._gfdSwipeBound = true;

    var startX = 0, startY = 0, dragging = false, horizontal = false;

    mediaBox.addEventListener('touchstart', function(e){
        startX = e.touches[0].clientX;
        startY = e.touches[0].clientY;
        dragging = true;
        horizontal = false;
        gallery.style.transition = 'none';
    }, {passive:true});

    mediaBox.addEventListener('touchmove', function(e){
        if(!dragging) return;
        var dx = e.touches[0].clientX - startX;
        var dy = e.touches[0].clientY - startY;
        if(!horizontal && Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 8) horizontal = true;
        if(horizontal){
            e.stopPropagation();
            gallery.style.transform = 'translateX(calc(-'+(_gfmMediaIndex*100)+'% + '+dx+'px))';
        }
    }, {passive:true});

    mediaBox.addEventListener('touchend', function(e){
        if(!dragging) return;
        dragging = false;
        gallery.style.transition = 'transform .28s cubic-bezier(.22,1,.36,1)';
        if(horizontal){
            e.stopPropagation();
            var dx = e.changedTouches[0].clientX - startX;
            var count = parseInt(mediaBox.dataset.count || '1', 10);
            if(Math.abs(dx) > 50){
                if(dx < 0 && _gfmMediaIndex < count - 1) _gfmMediaIndex++;
                else if(dx > 0 && _gfmMediaIndex > 0) _gfmMediaIndex--;
            }
            _gfdSaveMediaIndex(_gfmMemeId, _gfmMediaIndex);
        }
        gfdUpdateMediaGallery(slide);
    }, {passive:true});
}

/* ── render a single post slide ── */
function _gfdRenderPost(p, isPreview){
    var isOwnPost = String(p.userId) === String(gfCurrentUserId);

    var media = (Array.isArray(p.media) && p.media.length) ? p.media.slice() : [{file:p.file, type:p.type}];
    var mediaCount = media.length;

    var mediaSet  = ['image','video','gif'];
    var firstType = (media[0].type || '').toLowerCase();
    var isTextOnly = mediaCount === 1 && mediaSet.indexOf(firstType) === -1;

    var hasSeparateTrack = !!(p.audioFile) && !p.audioIsOriginal;
    var isMemePaused = !!_gfdPausedMemeIds[p.id];

    var initIdx;
    if(isPreview){
        initIdx = 0;
    } else {
        initIdx = isTextOnly ? 0 : _gfdGetSavedMediaIndex(p.id);
        initIdx = Math.max(0, Math.min(initIdx, mediaCount - 1));
        _gfmMediaIndex = initIdx;
    }

    var isFollowing = !!p.following;
    var capState = _gfdComputeCaptionHtml(p);
    var localCaptionTruncHtml = capState.trunc;
    var mediaBoxInner;

    if(isTextOnly){
        mediaBoxInner = '<div class="gfd-textonly-bg"></div>'+
                    '<div class="gfd-textonly-overlay"></div>'+
                    '<div class="gfd-center-caption-wrap" id="gfd-center-caption-wrap">'+
                        '<div class="gfd-center-caption-text" id="gfd-center-caption-text">'+localCaptionTruncHtml+'</div>'+
                    '</div>';
    } else {
        mediaBoxInner = '<div class="gfd-media-gallery" id="gfd-media-gallery">'+
            media.map(function(m, i){
                var t = (m.type||'').toLowerCase();
                var isActive = (i === initIdx);
                var loadHook = isActive ? "gfdHideSkeleton(this.closest('.gfd-slide'))" : "";
                if(t === 'video'){
                    var mutedAttr = hasSeparateTrack ? 'muted ' : '';
                    var autoplayAttr = (isActive && !isPreview && !isMemePaused) ? 'autoplay' : '';
                    var isAdjacent = !isPreview && mediaCount > 1 && Math.abs(i - initIdx) === 1;
                    var preloadAttr = (isActive && !isPreview) ? 'preload="auto"' :
                                       (isAdjacent ? 'preload="auto"' : 'preload="metadata"');
                    return '<div class="gfd-media-item" data-idx="'+i+'" data-type="video">'+
                        '<video src="'+m.file+'" '+mutedAttr+autoplayAttr+' loop playsinline '+preloadAttr+' '+
                        'onloadedmetadata="gfdFixObjectFit(this);" '+
                        'onloadeddata="'+loadHook+'" '+
                        'ontimeupdate="gfdUpdateProgress(this)" class="gfd-media"></video>'+
                    '</div>';
                }
                return '<div class="gfd-media-item" data-idx="'+i+'" data-type="'+t+'">'+
                    '<img src="'+m.file+'" onerror="this.src=\'uploads/me.jpeg\'" '+
                    'onload="gfdFixObjectFit(this);'+loadHook+'" class="gfd-media">'+
                '</div>';
            }).join('')+
        '</div>'+
        (mediaCount > 1
            ? '<div class="gfd-media-arrow gfd-media-arrow-left" onclick="event.stopPropagation();gfdMediaPrev()"><i class="ri-arrow-left-s-line"></i></div>'+
              '<div class="gfd-media-arrow gfd-media-arrow-right" onclick="event.stopPropagation();gfdMediaNext()"><i class="ri-arrow-right-s-line"></i></div>'
            : '');
    }

    var typeInfo = _gfdTypeTagInfo(isTextOnly ? 'text' : ((media[initIdx] && media[initIdx].type) || firstType));
    var typeTagHtml = '<div class="gfd-type-tag" id="gfd-type-tag"><i class="'+typeInfo.icon+'"></i><span>'+typeInfo.label+'</span></div>';
    var counterHtml = mediaCount > 1
        ? '<div class="gfd-media-counter" id="gfd-media-counter">'+(initIdx+1)+'/'+mediaCount+'</div>'
        : '';

    var searchBtnHtml = '<button type="button" class="gfd-top-search-btn" onclick="event.stopPropagation();if(typeof openGroupSearchModal===\'function\')openGroupSearchModal();"><i class="ri-search-line"></i></button>';

    /* ── NEW: Discuss button — rectangular pill, top-left under the back chevron ── */
    var discussBtnHtml = '<div class="gfd-discuss-wrap" id="gfd-discuss-wrap">'+
        '<button type="button" class="gfd-discuss-btn" onclick="event.stopPropagation();gfdOpenDiscuss('+p.id+')">'+
            '<i class="ri-discuss-line"></i><span>Discuss</span>'+
        '</button>'+
    '</div>';

    var dashGap = mediaCount > 1 ? _gfdComputeDashGap(mediaCount) : 6;
    var dashesHtml = (mediaCount > 1)
        ? '<div class="gfd-media-dashes" id="gfd-media-dashes" style="gap:'+dashGap+'px;">'+
            media.map(function(_, i){ return '<span class="gfd-dash'+(i===initIdx?' active':'')+'"></span>'; }).join('')+
          '</div>'
        : '';

    var likeCount   = p.likeCount   || 0;
    var repostCount = p.repostCount || 0;
    var favCount    = p.favCount    || 0;
    var shareCount  = p.shareCount  || 0;
    var liked    = p.liked    || false;
    var reposted = p.reposted || false;
    var faved    = p.faved    || false;

    var trackBtnHtml = !isOwnPost
        ? '<span class="gfd-user-dot">·</span>'+
          '<button class="gfd-track-btn '+(isFollowing?'following':'')+'" data-user="'+p.userId+'" '+
              'onclick="event.stopPropagation();gfmToggleTrack(this)">'+(isFollowing?'Untrack':'Track')+'</button>'
        : '';

    var isNewPost = false;
    if(p.time){
        var _pd = new Date((p.time||'').replace(' ','T'));
        if(!isNaN(_pd)) isNewPost = (Date.now() - _pd.getTime()) <= (172800*1000);
    }
    var displayName = isOwnPost ? 'You' : _gfdTruncateName(p.username);
    var nicknameHtml = (!isOwnPost && p.nickname) ? '<div class="gfd-nickname">@'+p.nickname+'</div>' : '';

    var audioTagHtml = '';
    if(p.audioId !== undefined && p.audioId !== null){
        var audioLabel;
        if(p.audioIsOriginal){
            audioLabel = (isOwnPost ? p.username : displayName) + ' · Original audio';
        } else {
            audioLabel = (p.audioTitle || 'Audio') + (p.audioArtist ? ' — '+p.audioArtist : '');
        }

        audioTagHtml = '<div class="gfd-audio-tag" onclick="event.stopPropagation();gfdOpenSoundsPage('+p.userId+','+p.audioId+')" style="cursor:pointer;">'+
            '<span class="gfd-audio-icon" aria-hidden="true">'+
                '<span class="gfd-eq-bar"></span><span class="gfd-eq-bar"></span><span class="gfd-eq-bar"></span>'+
            '</span>'+
            '<div class="gfd-audio-track" id="gfd-audio-track">'+
                '<span class="gfd-audio-track-text" id="gfd-audio-track-text">'+_gfdEscapeHtml(audioLabel)+'</span>'+
            '</div>'+
        '</div>';
    }

    var switcherHtml = '';
    if(nicknameHtml && audioTagHtml){
        switcherHtml = '<div class="gfd-info-switcher" id="gfd-info-switcher">'+
            '<div class="gfd-switch-pane gfd-switch-nickname-pane active">'+nicknameHtml+'</div>'+
            '<div class="gfd-switch-pane gfd-switch-audio-pane">'+audioTagHtml+'</div>'+
        '</div>';
    } else if(nicknameHtml){
        switcherHtml = nicknameHtml;
    } else if(audioTagHtml){
        switcherHtml = audioTagHtml;
    }

    /* ── NEW: shared "should this bottom-left slot sit higher" flag.
       Raised either because of the paid-partnership badge sitting
       just below it, OR because this post has multi-media dashes
       running along the bottom of the slide — in both cases the
       strip/connected tag needs to lift clear of what's underneath
       it. Both .gfd-social-proof-strip and .gfd-connected-tag now
       share this single flag instead of each independently checking
       p.isPartnership on its own. ── */
    var _gfdNeedsRaise = p.isPartnership || mediaCount > 1;

    /* ── UPDATED: social proof never renders on a post that links to a
       Connected Zeem — the connected pill takes this exact strip slot
       instead. (See connectedHtml below, built in the same bottom-left
       slot as this strip.) ── */
    var socialProofHtml = '';
    if(!p.connectedMemeId && Array.isArray(p.socialProof) && p.socialProof.length){
        var spClass = 'gfd-social-proof-strip' + (_gfdNeedsRaise ? ' gfd-sp-raised' : '');
        socialProofHtml = '<div class="'+spClass+'" id="gfd-social-proof">'+
            p.socialProof.slice(0,4).map(function(sp){
                var spPic  = sp.profile_pic || 'uploads/me.jpeg';
                var isLike = sp.action_type === 'like';
                var icon   = isLike ? 'ri-heart-3-fill' : 'ri-repeat-line';
                var color  = isLike ? '#f24455' : '#7a4aff';
                return '<a href="profile.php?user_id='+sp.id+'" onclick="event.stopPropagation()" class="gfd-sp-avatar-wrap">'+
                    '<img src="'+spPic+'" onerror="this.src=\'uploads/me.jpeg\'" class="gfd-sp-avatar">'+
                    '<span class="gfd-sp-badge" style="background:'+color+';"><i class="'+icon+'"></i></span>'+
                '</a>';
            }).join('')+
        '</div>';
    }

    /* ── NEW: Connected-Zeem pill. Occupies the same bottom-left slot
       as social proof (see the guard above — mutually exclusive with
       it). Tapping it never navigates the page — it slides in a new
       horizontal layer over the modal via gfdOpenConnectedZeem(). ── */
    var connectedHtml = '';
    if(p.connectedMemeId){
        var raisedClass = _gfdNeedsRaise ? ' gfd-sp-raised' : '';
        var connLabel   = p.connectedLabel ? _gfdEscapeHtml(p.connectedLabel) : 'Connected Zeem';
        connectedHtml = '<a href="#" id="gfd-connected-tag" class="gfd-connected-tag'+raisedClass+'" '+
            'onclick="event.stopPropagation();event.preventDefault();gfdOpenConnectedZeem('+p.connectedMemeId+')">'+
            '<span class="gfd-connected-icon"><i class="ri-play-fill"></i></span>'+
            '<span class="gfd-connected-text">'+
                '<span class="gfd-connected-text-main">View</span>'+
                '<span class="gfd-connected-text-dot">·</span>'+
                '<span class="gfd-connected-text-label">'+connLabel+'</span>'+
            '</span>'+
        '</a>';
    }

    var bottomCaptionHtml = '';
    if(!isTextOnly){
        bottomCaptionHtml = '<div class="gfd-caption-wrap" id="gfd-caption-wrap">'+
              '<span class="gfd-caption-text" id="gfd-caption-text">'+localCaptionTruncHtml+'</span>'+
          '</div>';
    }
    
    /* Paid Partnership badge — sits under the caption, own line,
       never mixed into the caption text itself. */
    var partnershipHtml = '';
    if(p.isPartnership){
        var brandText = p.partnerBrand ? ' with <strong>'+_gfdEscapeHtml(p.partnerBrand)+'</strong>' : '';
        partnershipHtml = '<div class="gfd-partnership-tag"><i class="ri-handshake-line"></i>Paid partnership'+brandText+'</div>';
    }

    var railClass = 'gfd-rail' + (mediaCount > 1 ? ' gfd-rail-raised' : '');

    var initialActiveType = isTextOnly ? '' : ((media[initIdx] && media[initIdx].type) || firstType).toLowerCase();
    var progressBarHtml = !isTextOnly ? (
        '<div class="gfd-video-progress" style="display:'+(initialActiveType === 'video' ? 'flex' : 'none')+';">'+
            '<div class="gfd-video-progress-track">'+
                '<div class="gfd-video-progress-fill"></div>'+
                '<div class="gfd-video-progress-thumb"></div>'+
            '</div>'+
        '</div>'
    ) : '';

    return (
        _gfdSkeletonHtml()+

        '<div class="gfd-media-box" id="gfd-media-box" data-count="'+mediaCount+'" '+
            'onclick="event.stopPropagation();gfdHandleMediaTap(event,this,'+(mediaCount > 1)+')"'+
        '>'+ mediaBoxInner +'</div>'+

        '<div class="gfd-top-bar">'+
            '<div class="gfd-top-bar-row">'+
                '<div class="gfd-back-group">'+
                    '<span class="gfd-icon-btn gfd-back-btn" style="visibility:hidden;pointer-events:none;"><i class="ri-arrow-left-s-line"></i></span>'+
                    '<span class="gfd-brand-label">Zeems</span>'+
                    (p.time ? '<span class="gfd-brand-dot">·</span><span class="gfd-brand-date">'+_gfdFormatDate(p.time)+(isNewPost?' · <span style="color:#ff2b2b;font-weight:700;">New</span>':'')+'</span>' : '')+
                '</div>'+
            '</div>'+
        '</div>'+

        searchBtnHtml+
        discussBtnHtml+
        '<div class="gfd-top-right-tags">'+ counterHtml + typeTagHtml +'</div>'+
        socialProofHtml+
        connectedHtml+

       '<div class="'+railClass+'">'+
            '<button id="gfd-like-btn" class="gfd-rail-btn '+(liked?'active':'')+'" onclick="event.stopPropagation();gfdAction(\'like\')">'+
                '<i class="ri-heart-3-fill"></i><span id="gfd-like-count" class="gfd-rail-count">'+likeCount+'</span>'+
            '</button>'+
            '<button id="gfd-repost-btn" class="gfd-rail-btn '+(reposted?'active':'')+'" onclick="event.stopPropagation();gfdAction(\'repost\')">'+
                '<i class="ri-repeat-line"></i><span id="gfd-repost-count" class="gfd-rail-count">'+repostCount+'</span>'+
            '</button>'+
            '<button id="gfd-fav-btn" class="gfd-rail-btn '+(faved?'active':'')+'" onclick="event.stopPropagation();gfdAction(\'fav\')">'+
                '<i class="ri-bookmark-fill"></i><span id="gfd-fav-count" class="gfd-rail-count">'+favCount+'</span>'+
            '</button>'+
            '<button id="gfd-share-btn" class="gfd-rail-btn" onclick="event.stopPropagation();openShareModal('+p.id+')">'+
                '<i class="ri-share-forward-fill"></i><span id="gfd-share-count" class="gfd-rail-count">'+shareCount+'</span>'+
            '</button>'+
            '<button class="gfd-rail-btn" onclick="event.stopPropagation();openGfSheet('+p.id+')">'+
                '<i class="ri-menu-line"></i>'+
            '</button>'+
            (!isOwnPost
                ? '<button type="button" class="gfd-rail-btn gfd-rail-msg" onclick="event.stopPropagation();gfdOpenSoundsPage('+p.userId+(p.audioId !== undefined && p.audioId !== null ? ','+p.audioId : '')+')">'+
                      '<img src="'+(p.pic||'uploads/me.jpeg')+'" onerror="this.src=\'uploads/me.jpeg\'" class="gfd-rail-msg-pic">'+
                  '</button>'
                : '')+
        '</div>'+

        '<div class="gfd-bottom">'+
            '<div class="gfd-user-row">'+
                '<img src="'+(p.pic||'uploads/me.jpeg')+'" onerror="this.src=\'uploads/me.jpeg\'" class="gfd-user-pic" onclick="location.href=\'profile.php?user_id='+p.userId+'\'">'+
                '<div class="gfd-user-info">'+
                    '<div class="gfd-user-line1">'+
                        '<span class="gfd-username" onclick="location.href=\'profile.php?user_id='+p.userId+'\'">'+displayName+'</span>'+
                        trackBtnHtml+
                    '</div>'+
                    switcherHtml+
                '</div>'+
            '</div>'+
            bottomCaptionHtml+
            partnershipHtml+
            dashesHtml+
        '</div>'+

        progressBarHtml
    );
}

/* ── Discuss button handler — carries the meme_id over to discussions.php,
     which prefills the create-discussion modal with this post's media,
     caption, and a default (changeable) tag. Members-only, same lock
     pattern as the rest of the pod's member-gated actions. ── */
function gfdOpenDiscuss(memeId){
    if (typeof GF_IS_MEMBER === 'undefined' || !GF_IS_MEMBER) {
        if (typeof showDiscLockToast === 'function') {
            showDiscLockToast('Join this pod to access Discussions');
        }
        return;
    }
    // A Connected Zeem the user owns may never have a group_posts row
    // in THIS pod — flag it so discussions.php falls back to an
    // ownership check instead of requiring a group_posts match.
    var post = _gfdFindPostById(memeId);
    var isExternal = !post || !post.groupPostId;
    window.location.href = 'discussions.php?pod_id=' + gfGroupId +
        '&prefill_meme_id=' + memeId +
        (isExternal ? '&external_meme=1' : '');
}

function gfdOpenSoundsPage(userId, audioId){
    if(!userId) return;
    var url = 'audio.php?user_id=' + userId;
    if(audioId){ url += '&audio_id=' + audioId; }
    window.location.href = url;
}

/* ══════════════════════════════════════════════════════════
   CONNECTED ZEEM — horizontal linked-post navigation
   ══════════════════════════════════════════════════════════
   Separate from BOTH existing swipe systems:
     - vertical (_gfdVDragBound / _gfdCommitTrackMove) moves
       between posts in the current pod feed (peuraGroupFeed)
     - horizontal-in-gallery (_gfdInitMediaSwipe) moves between
       media items WITHIN one post
   This is a third system: horizontal, but slides the ENTIRE
   slide (header, media, rail, caption) to a totally different
   post that may not be anywhere in peuraGroupFeed. It lives in
   its own stack (_gfdHStack) of .gfd-hlayer elements inside
   #gfd-hstack (a sibling of the vertical track inside
   #gfd-inner). Only one layer is ever the "active" one —
   whichever is on top of the stack — and it's the one that gets
   id="gfd-slide" so every existing helper (gfdAction,
   gfmToggleTrack, _gfdActivateCurrentSlide, the media-gallery/
   scrub/skeleton code, etc.) keeps working completely
   unmodified, since none of it cares WHERE the slide physically
   lives, only that it can find it by id.
   ══════════════════════════════════════════════════════════ */

function _gfdEnsureHStackEl(){
    var inner = document.getElementById('gfd-inner');
    if(!inner) return null;
    var el = document.getElementById('gfd-hstack');
    if(!el){
        el = document.createElement('div');
        el.id = 'gfd-hstack';
        el.style.pointerEvents = 'none';
        inner.appendChild(el);
    }
    return el;
}

function gfdOpenConnectedZeem(memeId){
    if(!memeId) return;

    // Already showing this exact post on top — nothing to do.
    if(_gfdHStack.length){
        var topItem = _gfdHStack[_gfdHStack.length - 1].item;
        if(topItem && String(topItem.id) === String(memeId)) return;
    }

    var cached = _gfdConnectedCache[memeId];
    if(cached){
        _gfdPushConnectedLayer(cached);
        return;
    }

    if(_gfdConnectedFetchLock) return;
    _gfdConnectedFetchLock = true;

    fetch('group.php?id=' + gfGroupId, {
        method:  'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body:    'ajax_get_connected_meme=1&meme_id=' + encodeURIComponent(memeId) + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        _gfdConnectedFetchLock = false;
        if(!data || !data.ok || !data.post){
            gfdToast('Could not load this Zeem');
            return;
        }
        _gfdConnectedCache[memeId] = data.post;
        _gfdPushConnectedLayer(data.post);
    })
    .catch(function(){
        _gfdConnectedFetchLock = false;
        gfdToast('Network error — try again');
    });
}

function _gfdPushConnectedLayer(postData){
    var hstack = _gfdEnsureHStackEl();
    if(!hstack) return;

    // Detach id from whatever is currently the active slide (either
    // the vtrack's current post, or the previous top hlayer) so only
    // the new incoming layer ever owns #gfd-slide.
    var curSlide = document.getElementById('gfd-slide');
    if(curSlide) curSlide.removeAttribute('id');

    // Stop timers tied to the outgoing slide — they'll be restarted
    // fresh against the new slide inside _gfdActivateCurrentSlide.
    if(_gfdInfoSwitchTimer){ clearInterval(_gfdInfoSwitchTimer); _gfdInfoSwitchTimer = null; }

    var layer = document.createElement('div');
    layer.className = 'gfd-hlayer';

    var slideEl = document.createElement('div');
    slideEl.className = 'gfd-slide';
    slideEl.id = 'gfd-slide';
    slideEl.innerHTML = _gfdRenderPost(postData, false);
    layer.appendChild(slideEl);

    layer.style.transform  = 'translateX(100%)';
    layer.style.transition = 'none';
    hstack.appendChild(layer);
    hstack.style.pointerEvents = 'all';

    void layer.offsetHeight; // force reflow so the slide-in actually animates
    layer.style.transition = 'transform .32s cubic-bezier(.22,1,.36,1)';
    layer.style.transform  = 'translateX(0)';

    layer.querySelectorAll('.gfd-video-progress').forEach(gfdInitProgressScrub);

    _gfdHStack.push({ layer: layer, item: postData });
    _gfmMediaIndex = 0;

    _gfdActivateCurrentSlide(postData);
}

function gfdCloseConnectedZeem(){
    if(!_gfdHStack.length) return;

    var top   = _gfdHStack.pop();
    var layer = top.layer;

    if(_gfdInfoSwitchTimer){ clearInterval(_gfdInfoSwitchTimer); _gfdInfoSwitchTimer = null; }

    var outgoingSlide = layer.querySelector('.gfd-slide');
    if(outgoingSlide){
        outgoingSlide.removeAttribute('id');
        outgoingSlide.querySelectorAll('video').forEach(function(v){ v.pause(); });
    }

    layer.style.transition = 'transform .32s cubic-bezier(.22,1,.36,1)';
    layer.style.transform  = 'translateX(100%)';

    var removed = false;
    function finish(){
        if(removed) return;
        removed = true;
        layer.removeEventListener('transitionend', onEnd);
        if(layer.parentNode) layer.parentNode.removeChild(layer);

        var nextTop = _gfdHStack.length ? _gfdHStack[_gfdHStack.length - 1] : null;
        var nextItem;

        if(nextTop){
            var nextSlide = nextTop.layer.querySelector('.gfd-slide');
            if(nextSlide) nextSlide.id = 'gfd-slide';
            nextItem = nextTop.item;
        } else {
            var hstack = document.getElementById('gfd-hstack');
            if(hstack) hstack.style.pointerEvents = 'none';
            var curVSlide = document.querySelector('.gfd-vslide[data-pos="current"] .gfd-slide');
            if(curVSlide) curVSlide.id = 'gfd-slide';
            nextItem = _gfdSlideItem(_gfmIndex);
        }

        _gfmMediaIndex = _gfdGetSavedMediaIndex(nextItem ? nextItem.id : null);
        _gfdActivateCurrentSlide(nextItem);
    }
    var onEnd = function(e){ if(e.target === layer && e.propertyName === 'transform') finish(); };
    layer.addEventListener('transitionend', onEnd);
    setTimeout(finish, 360);
}

/* ── Back dispatcher — if a Connected-Zeem layer is open, one tap
   slides back exactly one hop (never closes the modal); only once
   the stack is empty does back close the modal, same as before.
   Wire this to whatever back-tap/escape control the gf modal uses. ── */
function gfdHandleBackTap(){
    if(_gfdHStack.length > 0){
        gfdCloseConnectedZeem();
    } else {
        closeGFModal();
    }
}

/* ════════════════════════════════════════════════════════════
   GAPLESS VERTICAL TRACK ENGINE
   ════════════════════════════════════════════════════════════ */
function _gfdSlideItem(idx){
    if(idx < 0 || idx >= peuraGroupFeed.length) return null;
    return peuraGroupFeed[idx];
}

function _gfdBuildSlideWrap(item, pos){
    var wrap = document.createElement('div');
    wrap.className = 'gfd-vslide';
    wrap.dataset.pos = pos;
    if(item){
        var slideEl = document.createElement('div');
        slideEl.className = 'gfd-slide';
        if(pos === 'current') slideEl.id = 'gfd-slide';

        if(_gfdIsHidden(item.id)){
            slideEl.classList.add('gfd-slide-hidden');
            slideEl.innerHTML = _gfdRenderHiddenSlideContent(item.id);
        } else {
            slideEl.innerHTML = _gfdRenderPost(item, pos !== 'current');
        }

        wrap.appendChild(slideEl);
        wrap.querySelectorAll('.gfd-video-progress').forEach(gfdInitProgressScrub);
    }
    return wrap;
}

function _gfdActivateCurrentSlide(currentItem){
    gfdHideSavedToast();

    var newMemeId = currentItem ? currentItem.id : null;
    var isHiddenPost = _gfdIsHidden(newMemeId);

    // Always reset caption state and timers, hidden or not — this is
    // what was leaking stale state across a hidden post before.
    if(currentItem){
        var capState = _gfdComputeCaptionHtml(currentItem);
        _gfdCaptionFullHtml  = capState.full;
        _gfdCaptionTruncHtml = capState.trunc;
    }
    _gfdCaptionExpanded = false;
    if(_gfdInfoSwitchTimer){ clearInterval(_gfdInfoSwitchTimer); _gfdInfoSwitchTimer = null; }

    if(isHiddenPost){
        _gfdPausedMemeIds[newMemeId] = true;
        _gfdStopSoundtrack();
        _gfmMemeId = newMemeId;
        return;
    }

    var shouldStayPaused = newMemeId !== null && !!_gfdPausedMemeIds[newMemeId];

    var curSlide = document.getElementById('gfd-slide');
    if(curSlide){
        _gfdInitMediaSwipe(curSlide);
        _gfdInitAudioMarquee(curSlide);
        _gfdInitInfoSwitcher(curSlide);
        gfdUpdateMediaGallery(curSlide, shouldStayPaused);

        var mediaImg = curSlide.querySelector('img.gfd-media');
        var textBg   = curSlide.querySelector('.gfd-textonly-bg');
        var activeVid = curSlide.querySelector('.gfd-media-item[data-idx="'+_gfmMediaIndex+'"] video.gfd-media')
                      || curSlide.querySelector('video.gfd-media');

        if(textBg){
            gfdHideSkeleton(curSlide);
            requestAnimationFrame(function(){ _gfdCheckCaptionOverflow(curSlide); });
        } else if(mediaImg && mediaImg.complete){
            gfdHideSkeleton(curSlide);
        } else if(activeVid && activeVid.readyState >= 2){
            gfdHideSkeleton(curSlide);
        }
        setTimeout(function(){ gfdHideSkeleton(curSlide); }, 2500);
    }

    _gfdSyncSoundtrack(currentItem);

    _gfmMemeId = newMemeId;
}

function _gfdMountTrack(centerIndex){
    _gfdVSwipeLocked = false;

    var inner = document.getElementById('gfd-inner');
    inner.querySelectorAll('video').forEach(function(v){ v.pause(); v.src=''; v.load(); });

    var track = document.createElement('div');
    track.className = 'gfd-vtrack';
    track.id = 'gfd-vtrack';
    track.appendChild(_gfdBuildSlideWrap(_gfdSlideItem(centerIndex - 1), 'prev'));
    track.appendChild(_gfdBuildSlideWrap(_gfdSlideItem(centerIndex),     'current'));
    track.appendChild(_gfdBuildSlideWrap(_gfdSlideItem(centerIndex + 1), 'next'));

    inner.innerHTML = '';
    inner.appendChild(track);

    _gfmIndex = centerIndex;
    _gfdActivateCurrentSlide(_gfdSlideItem(centerIndex));
}

var GFD_OVERLAY_SELECTOR = '.gfd-top-bar, .gfd-top-search-btn, .gfd-top-right-tags, .gfd-social-proof-strip, .gfd-rail, .gfd-bottom, .gfd-hidden-overlay';

function _gfdOverlayEls(slideEl){
    return slideEl ? slideEl.querySelectorAll(GFD_OVERLAY_SELECTOR) : [];
}

function _gfdSetOverlayProgress(slideEl, progress, dir){
    if(!slideEl) return;
    var opacity   = 1 - (progress * 0.7);
    var translate = (dir || 0) * progress * 16;
    _gfdOverlayEls(slideEl).forEach(function(el){
        el.style.opacity   = opacity;
        el.style.transform = 'translateY('+translate+'px)';
    });
}

function _gfdResetAllOverlayStyles(track){
    if(!track) return;
    track.querySelectorAll('.gfd-vslide .gfd-slide').forEach(function(slideEl){
        _gfdOverlayEls(slideEl).forEach(function(el){
            el.style.transition = '';
            el.style.opacity    = '';
            el.style.transform  = '';
        });
    });
}

var _gfdVDragBound = false;
function _gfdInitVerticalDrag(){
    if(_gfdVDragBound) return;
    _gfdVDragBound = true;
    var stage = document.getElementById('gfd-stage');
    if(!stage) return;

    var startX=0, startY=0, dragging=false, committedVertical=false, decided=false, stageH=0;

    stage.addEventListener('touchstart', function(e){
        if(e.touches.length !== 1) return;
        if(_gfdVSwipeLocked) return;
        // NEW: vertical feed navigation is fully locked while a
        // Connected-Zeem layer is open — the only way out is the
        // back control (gfdHandleBackTap), same as a linked reel.
        if(_gfdHStack.length > 0) return;
        if(_gfdIsInsideExpandedCaption(e.target)) return;
        startX = e.touches[0].clientX;
        startY = e.touches[0].clientY;
        dragging = true;
        committedVertical = false;
        decided = false;
        stageH = stage.clientHeight;
        var track = document.getElementById('gfd-vtrack');
        if(track){
            track.style.transition = 'none';
            track.querySelectorAll('.gfd-vslide .gfd-slide').forEach(function(slideEl){
                _gfdOverlayEls(slideEl).forEach(function(el){ el.style.transition = 'none'; });
            });
        }
    }, {passive:true});

    stage.addEventListener('touchmove', function(e){
        if(!dragging) return;
        var dx = e.touches[0].clientX - startX;
        var dy = e.touches[0].clientY - startY;

        if(!decided){
            if(Math.abs(dy) > Math.abs(dx) && Math.abs(dy) > 10){
                committedVertical = true; decided = true;
            } else if(Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 10){
                dragging = false; decided = true; return;
            }
        }
        if(committedVertical){
            var track = document.getElementById('gfd-vtrack');
            if(track){
                var atTop    = _gfmIndex <= 0;
                var atBottom = _gfmIndex >= peuraGroupFeed.length - 1;
                var clamped = dy;
                if(atTop && clamped > 0)    clamped = clamped * 0.35;
                if(atBottom && clamped < 0) clamped = clamped * 0.35;
                clamped = Math.max(-stageH, Math.min(stageH, clamped));
                track.style.transform = 'translateY('+clamped+'px)';

                var progress = Math.min(1, Math.abs(clamped) / (stageH * 0.35));
                var dirOut   = clamped < 0 ? -1 : (clamped > 0 ? 1 : 0);
                var curSlideEl = track.querySelector('.gfd-vslide[data-pos="current"] .gfd-slide');
                _gfdSetOverlayProgress(curSlideEl, progress, dirOut);

                var incomingPos = clamped < 0 ? 'next' : (clamped > 0 ? 'prev' : null);
                ['next','prev'].forEach(function(pos){
                    var nSlideEl = track.querySelector('.gfd-vslide[data-pos="'+pos+'"] .gfd-slide');
                    if(!nSlideEl) return;
                    if(pos === incomingPos){
                        _gfdSetOverlayProgress(nSlideEl, 1 - progress, -dirOut);
                    } else {
                        _gfdSetOverlayProgress(nSlideEl, 1, dirOut);
                    }
                });
            }
        }
    }, {passive:true});

    stage.addEventListener('touchend', function(e){
        if(!dragging) return;
        dragging = false;
        var track = document.getElementById('gfd-vtrack');
        if(!track || !committedVertical){ return; }

        var dy = e.changedTouches[0].clientY - startY;
        track.style.transition = 'transform .28s cubic-bezier(.22,1,.36,1)';

        var threshold = stageH * 0.18;
        if(dy < -threshold && _gfmIndex < peuraGroupFeed.length - 1){
            _gfdCommitTrackMove(1);
        } else if(dy > threshold && _gfmIndex > 0){
            _gfdCommitTrackMove(-1);
        } else {
            track.style.transform = 'translateY(0)';

            var cancelTransition = 'opacity .28s cubic-bezier(.22,1,.36,1), transform .28s cubic-bezier(.22,1,.36,1)';
            var curSlideEl = track.querySelector('.gfd-vslide[data-pos="current"] .gfd-slide');
            _gfdOverlayEls(curSlideEl).forEach(function(el){ el.style.transition = cancelTransition; });
            _gfdSetOverlayProgress(curSlideEl, 0, 0);

            ['next','prev'].forEach(function(pos){
                var nSlideEl = track.querySelector('.gfd-vslide[data-pos="'+pos+'"] .gfd-slide');
                if(!nSlideEl) return;
                _gfdOverlayEls(nSlideEl).forEach(function(el){ el.style.transition = cancelTransition; });
                _gfdSetOverlayProgress(nSlideEl, 1, 0);
            });
        }
    }, {passive:true});
}

function _gfdCommitTrackMove(dir){
    if(_gfdVSwipeLocked) return;
    if(_gfdHStack.length > 0) return; // locked while a Connected-Zeem layer is open

    var track = document.getElementById('gfd-vtrack');
    if(!track) return;

    _gfdVSwipeLocked = true;

    var stageH = document.getElementById('gfd-stage').clientHeight;
    track.style.transition = 'transform .28s cubic-bezier(.22,1,.36,1)';
    track.style.transform  = 'translateY('+(dir > 0 ? -stageH : stageH)+'px)';

    var keepPos     = dir > 0 ? 'next' : 'prev';
    var curSlideEl  = track.querySelector('.gfd-vslide[data-pos="current"] .gfd-slide');
    var nextSlideEl = track.querySelector('.gfd-vslide[data-pos="'+keepPos+'"] .gfd-slide');
    var commitTransition = 'opacity .28s cubic-bezier(.22,1,.36,1), transform .28s cubic-bezier(.22,1,.36,1)';
    _gfdOverlayEls(curSlideEl).forEach(function(el){ el.style.transition = commitTransition; });
    _gfdOverlayEls(nextSlideEl).forEach(function(el){ el.style.transition = commitTransition; });
    _gfdSetOverlayProgress(curSlideEl, 1, -dir);
    _gfdSetOverlayProgress(nextSlideEl, 0, dir);

    var newIndex = _gfmIndex + dir;
    var settled = false;
    var finish = function(){
        if(settled) return;
        settled = true;
        track.removeEventListener('transitionend', onEnd);
        _gfdFinishTrackMove(dir, newIndex);
    };
    var onEnd = function(e){
        if(e.target === track && e.propertyName === 'transform') finish();
    };
    track.addEventListener('transitionend', onEnd);
    setTimeout(finish, 320);
}

function _gfdFinishTrackMove(dir, newIndex){
    var track = document.getElementById('gfd-vtrack');
    if(!track){
        _gfdVSwipeLocked = false;
        return;
    }

    var keepPos = dir > 0 ? 'next' : 'prev';
    var dropPos = dir > 0 ? 'prev' : 'next';
    var keepNode = track.querySelector('.gfd-vslide[data-pos="'+keepPos+'"]');
    var dropNode = track.querySelector('.gfd-vslide[data-pos="'+dropPos+'"]');
    var oldCurrent = track.querySelector('.gfd-vslide[data-pos="current"]');

    if(dropNode){
        dropNode.querySelectorAll('video').forEach(function(v){ v.pause(); v.src=''; v.load(); });
        dropNode.remove();
    }
    if(oldCurrent){
        oldCurrent.dataset.pos = dropPos;
        oldCurrent.querySelectorAll('video').forEach(function(v){ v.pause(); });
        var oldSlideEl = oldCurrent.querySelector('.gfd-slide');
        if(oldSlideEl) oldSlideEl.removeAttribute('id');
        if(_gfdInfoSwitchTimer){ clearInterval(_gfdInfoSwitchTimer); _gfdInfoSwitchTimer = null; }
    }
    if(keepNode){
        keepNode.dataset.pos = 'current';
        var innerSlide = keepNode.querySelector('.gfd-slide');
        if(innerSlide) innerSlide.id = 'gfd-slide';
    }

    track.style.transition = 'none';
    track.style.transform  = 'translateY(0)';
    void track.offsetHeight;

    var missingIndex = keepPos === 'next' ? newIndex + 1 : newIndex - 1;
    track.appendChild(_gfdBuildSlideWrap(_gfdSlideItem(missingIndex), keepPos));

    var promotedItem = _gfdSlideItem(newIndex);
    var promotedId   = promotedItem ? promotedItem.id : null;

    _gfmIndex      = newIndex;
    _gfmMediaIndex = _gfdGetSavedMediaIndex(promotedId);
    _gfdActivateCurrentSlide(promotedItem);

    _gfdVSwipeLocked = false;
}

/* ── PUBLIC API — names kept identical ── */
function gfmLoadIndex(index){
    if(_gfdVSwipeLocked) return;
    if(_gfdHStack.length > 0) return; // locked while a Connected-Zeem layer is open
    if(index < 0 || index >= peuraGroupFeed.length || index === _gfmIndex) return;
    if(Math.abs(index - _gfmIndex) === 1){
        _gfdCommitTrackMove(index > _gfmIndex ? 1 : -1);
    } else {
        _gfdMountTrack(index);
    }
}

/* ══════════════════════════════════════════════════════════
   MONKEY-PATCH: wire gfHidePost / gfUndoHidePost (defined in
   the earlier script block, near gfLoadHiddenIds) so they call
   gfdShowHiddenOverlay / gfdHideHiddenOverlay the INSTANT a hide
   or undo happens — not just the next time the modal re-activates
   a slide. This is what fixes: (1) no overlay/no auto-pause when
   hiding while the modal is open on that post, and (2) Undo not
   resuming playback while the overlay is showing.

   Runs after the whole page (including the other script block)
   has parsed, so gfHidePost/gfUndoHidePost are guaranteed to
   already exist by the time this wraps them.
   ══════════════════════════════════════════════════════════ */
function _gfdPatchHideFns(){
    if (typeof window.gfHidePost === 'function' && !window.gfHidePost._gfdPatched) {
        var _origHide = window.gfHidePost;
        window.gfHidePost = function(memeId, skipServerCall){
            _origHide(memeId, skipServerCall);
            if (typeof _gfmMemeId !== 'undefined' && String(_gfmMemeId) === String(memeId)) {
                gfdShowHiddenOverlay(memeId);
            }
        };
        window.gfHidePost._gfdPatched = true;
    }

    if (typeof window.gfUndoHidePost === 'function' && !window.gfUndoHidePost._gfdPatched) {
        var _origUndo = window.gfUndoHidePost;
        window.gfUndoHidePost = function(memeId){
            _origUndo(memeId);
            gfdHideHiddenOverlay(memeId);
        };
        window.gfUndoHidePost._gfdPatched = true;
    }
}

if (document.readyState === 'complete' || document.readyState === 'interactive') {
    _gfdPatchHideFns();
} else {
    document.addEventListener('DOMContentLoaded', _gfdPatchHideFns);
}
window.addEventListener('load', _gfdPatchHideFns);

/* ════════════════════════════════════════════════════════════
   BODY SCROLL LOCK — iOS-safe (overflow:hidden alone doesn't stop
   touch scroll/rubber-banding on iOS Safari). Pins the body with
   position:fixed at the current scroll offset while the modal is
   open, and restores the exact scroll position on close.
   ════════════════════════════════════════════════════════════ */
var _gfdScrollLockY = 0;

function gfdLockBodyScroll(){
    _gfdScrollLockY = window.scrollY || window.pageYOffset || 0;
    document.body.style.position = 'fixed';
    document.body.style.top      = '-' + _gfdScrollLockY + 'px';
    document.body.style.left     = '0';
    document.body.style.right    = '0';
    document.body.style.width    = '100%';
    document.body.style.overflow = 'hidden';
}

function gfdUnlockBodyScroll(){
    document.body.style.position = '';
    document.body.style.top      = '';
    document.body.style.left     = '';
    document.body.style.right    = '';
    document.body.style.width    = '';
    document.body.style.overflow = '';
    window.scrollTo(0, _gfdScrollLockY);
}

function openGFModal(memeId){
    var idx = peuraGroupFeed.findIndex(function(p){ return String(p.id) === String(memeId); });
    var targetIdx = (idx >= 0) ? idx : 0;

    _gfdVSwipeLocked = false;

    // NEW: always start a fresh open with no Connected-Zeem layers
    // carried over from a previous session.
    var existingHstack = document.getElementById('gfd-hstack');
    if(existingHstack){
        existingHstack.querySelectorAll('video').forEach(function(v){ v.pause(); v.src=''; });
        existingHstack.innerHTML = '';
        existingHstack.style.pointerEvents = 'none';
    }
    _gfdHStack = [];

    _gfdMountTrack(targetIdx);
    _gfdEnsureHStackEl();
    _gfdInitVerticalDrag();

    document.getElementById('gfModal').classList.add('gfm-open');
    gfdLockBodyScroll();
}

function closeGFModal(){
    // clear hstack instantly so a fresh open never starts mid-chain
    var hstack = document.getElementById('gfd-hstack');
    if(hstack){
        hstack.querySelectorAll('video').forEach(function(v){ v.pause(); v.src=''; });
        hstack.innerHTML = '';
        hstack.style.pointerEvents = 'none';
    }
    _gfdHStack = [];

    document.getElementById('gfd-inner').querySelectorAll('video').forEach(function(v){ v.pause(); v.src=''; });
    _gfdStopSoundtrack();
    if(_gfdInfoSwitchTimer){ clearInterval(_gfdInfoSwitchTimer); _gfdInfoSwitchTimer = null; }
    gfdHideSavedToast();
    document.getElementById('gfModal').classList.remove('gfm-open');
    gfdUnlockBodyScroll();
    _gfmMemeId = null;
    _gfmMediaIndex = 0;
    _gfdVSwipeLocked = false;
}

/* ── ACTION HANDLER — hits group.php's ajax_like / ajax_repost /
     ajax_favorite endpoints; optimistic UI update with per-action
     lock and revert-on-failure, scoped to the CURRENT slide only
     (so with 3 slides mounted at once, an action never accidentally
     touches a prev/next slide's duplicate-id button). ── */
var _gfdActionLocks = {};

function gfdAction(type){
    if(!_gfmMemeId) return;
    var memeId  = _gfmMemeId;
    var lockKey = memeId + ':' + type;

    if(_gfdActionLocks[lockKey]) return;
    _gfdActionLocks[lockKey] = true;

    var actionParam       = { like:'ajax_like', repost:'ajax_repost', fav:'ajax_favorite' }[type];
    var stateKeyOnServer  = { like:'liked', repost:'reposted', fav:'favorited' }[type];
    var btnMap    = { like:'gfd-like-btn',   repost:'gfd-repost-btn',   fav:'gfd-fav-btn'    };
    var cntMap    = { like:'gfd-like-count', repost:'gfd-repost-count', fav:'gfd-fav-count'  };
    var cntField  = { like:'likeCount',      repost:'repostCount',      fav:'favCount'        };
    var stateField= { like:'liked',          repost:'reposted',         fav:'faved'           };
    var gridSel   = { like:'.gf-like',       repost:'.gf-repost',       fav:'.gf-fav'         };

    var slideEl = document.getElementById('gfd-slide');
    var cmBtn  = slideEl ? slideEl.querySelector('#'+btnMap[type]) : null;
    var cmSpan = slideEl ? slideEl.querySelector('#'+cntMap[type]) : null;

    var wasActive      = cmBtn ? cmBtn.classList.contains('active') : false;
    var optimisticNext = !wasActive;
    var prevCountText  = cmSpan ? cmSpan.textContent : '0';
    var prevCountNum   = parseInt(prevCountText, 10) || 0;
    var optimisticCount = optimisticNext ? prevCountNum + 1 : Math.max(0, prevCountNum - 1);

    if(cmBtn)  cmBtn.classList.toggle('active', optimisticNext);
    if(cmSpan) cmSpan.textContent = optimisticCount;

    if(cmBtn){
        var actionIcon = cmBtn.querySelector('i');
        if(actionIcon){
            cmBtn.classList.remove('arc-swap-active');
            void actionIcon.offsetWidth; // force reflow so rapid re-taps restart the animation
            cmBtn.classList.add('arc-swap-active');
            actionIcon.addEventListener('animationend', function handler(){
                cmBtn.classList.remove('arc-swap-active');
                actionIcon.removeEventListener('animationend', handler);
            });
        }
    }

    // Saving to favorites: show the "Saved · Collections" toast right
    // away, optimistically — same pattern as profile.php's CCM modal.
    if(type === 'fav'){
        if(optimisticNext) gfdShowSavedToast(memeId);
        else gfdHideSavedToast();
    }

    fetch('group.php?id=' + gfGroupId, {
        method:  'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body:    actionParam + '=1&meme_id=' + memeId + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        delete _gfdActionLocks[lockKey];

        if(data.count === undefined){
            if(cmBtn)  cmBtn.classList.toggle('active', wasActive);
            if(cmSpan) cmSpan.textContent = prevCountText;
            // Rollback: the save never actually happened, so the toast shouldn't stand.
            if(type === 'fav' && optimisticNext) gfdHideSavedToast();
            return;
        }

        var nowActive = !!data[stateKeyOnServer];
        if(cmBtn)  cmBtn.classList.toggle('active', nowActive);
        if(cmSpan) cmSpan.textContent = data.count;

        // Server disagreed with our optimistic guess — reconcile the toast.
        if(type === 'fav' && nowActive !== optimisticNext){
            if(nowActive) gfdShowSavedToast(memeId);
            else gfdHideSavedToast();
        }

        var gridBtn = document.querySelector(gridSel[type]+'[data-id="'+memeId+'"]');
        if(gridBtn){
            gridBtn.classList.toggle('active', nowActive);
            var gridCnt = gridBtn.querySelector('span');
            if(gridCnt) gridCnt.textContent = data.count;
        }

        var item = peuraGroupFeed.find(function(p){ return String(p.id) === String(memeId); });
        if(item){
            item[cntField[type]]   = data.count;
            item[stateField[type]] = nowActive;
        }
    })
    .catch(function(err){
        delete _gfdActionLocks[lockKey];
        console.error('gfdAction failed:', err);
        if(cmBtn)  cmBtn.classList.toggle('active', wasActive);
        if(cmSpan) cmSpan.textContent = prevCountText;
        if(type === 'fav' && optimisticNext) gfdHideSavedToast();
    });
}

/* ── desktop wheel + keyboard nav (unchanged behavior, now
   delegating to the track engine via gfmLoadIndex) ── */
document.getElementById('gfModal').addEventListener('wheel', function(e){
    if(_gfdIsInsideExpandedCaption(e.target)) return;

    e.preventDefault();
    var mediaBox = e.target.closest && e.target.closest('#gfd-media-box');
    var mediaCount = mediaBox ? parseInt(mediaBox.dataset.count || '1', 10) : 1;
    if(mediaBox && mediaCount > 1 && Math.abs(e.deltaX) > Math.abs(e.deltaY)){
        if(e.deltaX > 0) gfdMediaNext(); else gfdMediaPrev();
        return;
    }
    if(e.deltaY > 0) gfmLoadIndex(_gfmIndex+1);
    else             gfmLoadIndex(_gfmIndex-1);
}, { passive:false });

document.addEventListener('keydown', function(e){
    if(document.getElementById('gfModal').classList.contains('gfm-open')){
        if(e.key === 'Escape')    gfdHandleBackTap();
        if(e.key === 'ArrowDown') gfmLoadIndex(_gfmIndex+1);
        if(e.key === 'ArrowUp')   gfmLoadIndex(_gfmIndex-1);
        if(e.key === 'ArrowRight') gfdMediaNext();
        if(e.key === 'ArrowLeft')  gfdMediaPrev();
    }
});

window.addEventListener('resize', function(){
    var slide = document.getElementById('gfd-slide');
    if(slide){
        _gfdCheckCaptionOverflow(slide);
        _gfdInitAudioMarquee(slide);
    }
});

function gfmToggleTrack(btn) {
    var userId = btn.dataset.user;
    var wasFollowing = btn.classList.contains('following');

    btn.classList.toggle('following', !wasFollowing);
    btn.textContent = wasFollowing ? 'Track' : 'Untrack';

    fetch('group.php?id=<?php echo (int)$group_id; ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=toggle_follow&user_id=' + userId + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (data.status !== 'ok') {
            btn.classList.toggle('following', wasFollowing);
            btn.textContent = wasFollowing ? 'Untrack' : 'Track';
            return;
        }
        var nowFollowing = !!data.following;

        document.querySelectorAll(
            '.gf-track-btn[data-user="' + userId + '"], .gfd-track-btn[data-user="' + userId + '"]'
        ).forEach(function (b) {
            b.classList.toggle('following', nowFollowing);
            b.textContent = nowFollowing ? 'Untrack' : 'Track';
        });

        if (typeof peuraGroupFeed !== 'undefined') {
            peuraGroupFeed.forEach(function (p) {
                if (String(p.userId) === String(userId)) p.following = nowFollowing;
            });
        }
    })
    .catch(function () {
        btn.classList.toggle('following', wasFollowing);
        btn.textContent = wasFollowing ? 'Untrack' : 'Track';
    });
}

function gfToggleTrack(btn) {
    gfmToggleTrack(btn);
}

/* ────────────────────────────────────────────────────────────
   SHARE MODAL — external apps + follow-list picker → shares table + chat message
   (mirrors home.php's CCM share modal exactly, wired to peuraGroupFeed)
──────────────────────────────────────────────────────────── */
if (typeof showToast !== 'function') {
    window.showToast = function(msg){
        var existing = document.getElementById('peura-toast');
        if (existing) existing.remove();
        var t = document.createElement('div');
        t.id = 'peura-toast';
        t.textContent = msg;
        Object.assign(t.style, {
            position:'fixed', bottom:'90px', left:'50%', transform:'translateX(-50%)',
            background:'rgba(20,20,25,.95)', border:'1px solid rgba(255,45,45,.3)',
            color:'#fff', padding:'8px 20px', borderRadius:'50px', fontSize:'12px',
            zIndex:'9999999', pointerEvents:'none', boxShadow:'0 8px 24px rgba(0,0,0,.6)',
            transition:'opacity .3s', whiteSpace:'nowrap'
        });
        document.body.appendChild(t);
        setTimeout(function(){ t.style.opacity = '0'; setTimeout(function(){ t.remove(); }, 300); }, 2200);
    };
}

var _gfShareMemeId   = null;
var _gfShareAllUsers = [];
var _gfShareSelected = new Set();

function _gfShareGetLink(){
    return window.location.origin + '/home.php?meme_id=' + _gfShareMemeId;
}

function _gfShareGetCaption(){
    if(!_gfShareMemeId) return '';
    var item = peuraGroupFeed.find(function(p){ return String(p.id) === String(_gfShareMemeId); });
    return item && item.caption ? item.caption : 'Check this out on Zeems';
}

function openShareModal(memeId){
    _gfShareMemeId   = memeId;
    _gfShareSelected = new Set();
    document.getElementById('shareSearchInput').value = '';
    document.getElementById('shareModalList').innerHTML = '<div class="share-modal-empty">Loading…</div>';
    updateShareFooter();

    var nativeBtn = document.getElementById('shareNativeBtn');
    if(nativeBtn) nativeBtn.style.display = (navigator.share ? 'flex' : 'none');

    document.getElementById('shareModalOverlay').classList.add('show');
    document.getElementById('shareModalPanel').classList.add('show');
    document.body.style.overflow = 'hidden';

    fetch('home.php?type=following')
        .then(function(r){ return r.json(); })
        .then(function(users){
            _gfShareAllUsers = users || [];
            renderShareList(_gfShareAllUsers);
        })
        .catch(function(){
            document.getElementById('shareModalList').innerHTML =
                '<div class="share-modal-empty">Couldn\'t load your tracked people</div>';
        });
}

function closeShareModal(){
    document.getElementById('shareModalOverlay').classList.remove('show');
    document.getElementById('shareModalPanel').classList.remove('show');
    document.body.style.overflow = '';
    _gfShareMemeId = null;
}

function shareViaCopyLink(){
    var link = _gfShareGetLink();
    navigator.clipboard.writeText(link).then(function(){
        showToast('🔗 Link copied');
    }).catch(function(){
        var ta = document.createElement('textarea');
        ta.value = link;
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); showToast('🔗 Link copied'); } catch(e){ showToast('Could not copy link'); }
        ta.remove();
    });
}

function shareViaWhatsApp(){
    var text = encodeURIComponent(_gfShareGetCaption() + ' — ' + _gfShareGetLink());
    window.open('https://wa.me/?text=' + text, '_blank');
}

function shareViaTelegram(){
    var url  = encodeURIComponent(_gfShareGetLink());
    var text = encodeURIComponent(_gfShareGetCaption());
    window.open('https://t.me/share/url?url=' + url + '&text=' + text, '_blank');
}

function shareViaTwitter(){
    var text = encodeURIComponent(_gfShareGetCaption());
    var url  = encodeURIComponent(_gfShareGetLink());
    window.open('https://twitter.com/intent/tweet?text=' + text + '&url=' + url, '_blank');
}

function shareViaFacebook(){
    var url = encodeURIComponent(_gfShareGetLink());
    window.open('https://www.facebook.com/sharer/sharer.php?u=' + url, '_blank');
}

function shareViaEmail(){
    var subject = encodeURIComponent('Check this out on Zeems');
    var body    = encodeURIComponent(_gfShareGetCaption() + '\n\n' + _gfShareGetLink());
    window.location.href = 'mailto:?subject=' + subject + '&body=' + body;
}

function shareViaNative(){
    if(!navigator.share) return;
    navigator.share({
        title: 'Zeems',
        text:  _gfShareGetCaption(),
        url:   _gfShareGetLink()
    }).catch(function(){ /* user cancelled — no-op */ });
}

function renderShareList(users){
    var list = document.getElementById('shareModalList');
    if(!users.length){
        list.innerHTML = '<div class="share-modal-empty">You aren\'t tracking anyone yet</div>';
        return;
    }
    list.innerHTML = users.map(function(u){
        var pic = u.profile_pic || 'uploads/me.jpeg';
        var sel = _gfShareSelected.has(String(u.id));
        return '<div class="share-user-row'+(sel?' selected':'')+'" data-uid="'+u.id+'" '+
            'onclick="toggleShareUser('+u.id+', this)">'+
            '<img src="'+pic+'" onerror="this.src=\'uploads/me.jpeg\'">'+
            '<span class="share-user-name">'+u.username+'</span>'+
            '<span class="share-user-check"><i class="ri-check-line"></i></span>'+
        '</div>';
    }).join('');
}

function toggleShareUser(userId, el){
    var key = String(userId);
    if(_gfShareSelected.has(key)){ _gfShareSelected.delete(key); el.classList.remove('selected'); }
    else { _gfShareSelected.add(key); el.classList.add('selected'); }
    updateShareFooter();
}

function updateShareFooter(){
    var n = _gfShareSelected.size;
    document.getElementById('shareSelCount').textContent = n + ' selected';
    document.getElementById('shareSendBtn').disabled = n === 0;
}

function filterShareList(){
    var q = document.getElementById('shareSearchInput').value.toLowerCase();
    var filtered = _gfShareAllUsers.filter(function(u){
        return u.username.toLowerCase().includes(q);
    });
    renderShareList(filtered);
}

function sendShare(){
    if(!_gfShareMemeId || _gfShareSelected.size === 0) return;
    var btn = document.getElementById('shareSendBtn');
    btn.disabled = true;
    btn.textContent = 'Sending…';

    var ids = Array.from(_gfShareSelected).join(',');

    fetch('home.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=share_post&meme_id='+_gfShareMemeId+'&receiver_ids='+encodeURIComponent(ids)
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        btn.disabled = false;
        btn.textContent = 'Send';
        if(data.status === 'ok'){
            var countEl = document.getElementById('gfd-share-count');
            if(countEl) countEl.textContent = data.count;

            var gridShareBtn = document.querySelector('.gf-share[data-id="'+_gfShareMemeId+'"]');
            if(gridShareBtn){
                var gc = gridShareBtn.querySelector('span');
                if(gc) gc.textContent = data.count;
            }

            var item = peuraGroupFeed.find(function(p){ return String(p.id) === String(_gfShareMemeId); });
            if(item) item.shareCount = data.count;

            showToast('Shared with '+data.sent_to.length+' '+(data.sent_to.length===1?'person':'people'));
            closeShareModal();
        } else {
            showToast(data.message || 'Share failed');
        }
    })
    .catch(function(){
        btn.disabled = false;
        btn.textContent = 'Send';
        showToast('Network error');
    });
}
</script>

<div id="svshOverlay" class="svsh-overlay" onclick="svshClose()"></div>
<div id="svshPanel" class="svsh-panel">
    <div class="svsh-screens" id="svshScreens">

        <!-- SCREEN 1: main sheet -->
        <div class="svsh-screen">
            <div class="svsh-handle"></div>

            <div class="svsh-section-label">Add to a collection</div>

            <div class="svsh-row svsh-create-row" onclick="svshOpenCreate()">
                <i class="ri-folder-add-line svsh-row-icon"></i>
                <div class="svsh-row-text">
                    <div class="svsh-row-title">Create a new collection</div>
                    <div class="svsh-row-sub">Save posts to a new collection</div>
                </div>
                <i class="ri-arrow-right-s-line svsh-chevron"></i>
            </div>

            <div class="svsh-collections-list" id="svshCollectionsList"></div>
        </div>

        <!-- SCREEN 2: create collection -->
        <div class="svsh-screen">
            <div class="svsh-create-topbar">
                <button class="svsh-back-btn" onclick="svshBackToMain()"><i class="ri-arrow-left-s-line"></i></button>
                <div class="svsh-create-title">Create a collection</div>
                <button class="svsh-done-btn" id="svshDoneBtn" onclick="svshCreateCollection()">Done</button>
            </div>

            <div class="svsh-create-body">
                <input type="text" id="svshNameInput" class="svsh-name-input"
                       placeholder="Collection name" maxlength="60" oninput="svshValidateName()">
                <div class="svsh-required-hint" id="svshRequiredHint">Required</div>

                <div class="svsh-toggle-row">
                    <div class="svsh-toggle-text">
                        <div class="svsh-toggle-title">Set the collection to public</div>
                        <div class="svsh-toggle-sub">Anyone can view this collection</div>
                    </div>
                    <label class="svsh-switch">
                        <input type="checkbox" id="svshPublicToggle">
                        <span class="svsh-switch-slider"></span>
                    </label>
                </div>
            </div>
        </div>

    </div>
</div>
<style>
.svsh-overlay{
    position:fixed;inset:0;background:rgba(0,0,0,0.6);
    backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);
    z-index:999999996;opacity:0;pointer-events:none;
    transition:opacity 0.25s ease;
}
.svsh-overlay.show{opacity:1;pointer-events:all;}

.svsh-panel{
    position:fixed;left:0;right:0;bottom:0;
    background:rgba(12,12,28,0.98);
    backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);
    border-top:1px solid rgba(255,255,255,0.08);
    border-radius:20px 20px 0 0;
    z-index:999999997;
    overflow:hidden;
    max-height:82vh;
    display:flex;flex-direction:column;
    transform:translateY(100%);
    transition:transform 0.3s cubic-bezier(0.32,0.72,0,1);
    box-shadow:0 -10px 40px rgba(0,0,0,0.5);
    font-family:Poppins,sans-serif;
}
.svsh-panel.show{transform:translateY(0);}

.svsh-screens{
    display:flex;width:200%;
    transition:transform .3s cubic-bezier(.32,.72,0,1);
}
.svsh-panel.svsh-create-mode .svsh-screens{ transform:translateX(-50%); }

.svsh-screen{
    width:50%;
    flex-shrink:0;
    padding:8px 0 max(10px, env(safe-area-inset-bottom));
    max-height:82vh;
    overflow-y:auto;
    box-sizing:border-box;
    -webkit-overflow-scrolling:touch;
}
.svsh-screen::-webkit-scrollbar{width:4px;}
.svsh-screen::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.15);border-radius:999px;}

.svsh-handle{
    width:34px;height:3.5px;border-radius:999px;
    background:rgba(255,255,255,0.2);
    margin:0 auto 4px;flex-shrink:0;
}

.svsh-section-label{
    font-size:13px;font-weight:700;color:#fff;
    padding:12px 18px 6px;
}

/* ── ROW ITEMS — matched to idx-sheet-item ── */
.svsh-row{
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
.svsh-row:active{background:rgba(255,255,255,0.06);}
.svsh-row-icon{
    font-size:15.5px;color:#f24455;width:18px;text-align:center;flex-shrink:0;
}

.svsh-row-text{ flex:1; min-width:0; }
.svsh-row-title{
    font-size:13px;font-weight:600;color:#fff;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
.svsh-row-sub{
    font-size:11px;color:rgba(255,255,255,0.45);
    margin-top:2px;display:flex;align-items:center;gap:4px;
}

.svsh-chevron{ font-size:18px; color:rgba(255,255,255,0.25); flex-shrink:0; }

.svsh-collections-list{ margin-top:0; }
.svsh-col-thumb{
    width:34px;height:34px;flex-shrink:0;border-radius:9px;
    object-fit:cover;background:rgba(255,255,255,0.08);
}
.svsh-col-thumb-empty{
    display:flex;align-items:center;justify-content:center;
    color:#f24455;font-size:16px;
}

.svsh-add-btn{
    width:26px;height:26px;flex-shrink:0;border-radius:50%;
    border:1.5px solid rgba(255,255,255,0.22);
    display:flex;align-items:center;justify-content:center;
    color:#fff;font-size:15px;
    transition:all .15s ease;
    -webkit-tap-highlight-color:transparent;
}
.svsh-add-btn.svsh-added{
    background:#f24455;border-color:#f24455;
}

.svsh-loading{
    text-align:center;padding:24px;color:rgba(255,255,255,0.35);font-size:13px;
}

/* ── CREATE SCREEN ── */
.svsh-create-topbar{
    display:flex;align-items:center;justify-content:space-between;
    padding:6px 18px 14px;
    border-bottom:1px solid rgba(255,255,255,0.06);
}
.svsh-back-btn{
    width:30px;height:30px;border-radius:50%;
    background:rgba(255,255,255,0.06);border:none;color:#fff;
    display:flex;align-items:center;justify-content:center;
    font-size:19px;cursor:pointer;-webkit-tap-highlight-color:transparent;
    transition:background .18s;
}
.svsh-back-btn:hover{background:rgba(255,45,45,0.18);color:#ff6060;}
.svsh-create-title{ font-size:13.5px;font-weight:700;color:#fff; }
.svsh-done-btn{
    background:none;border:none;color:#f24455;
    font-size:13px;font-weight:700;cursor:pointer;padding:6px 2px;
    font-family:Poppins,sans-serif;-webkit-tap-highlight-color:transparent;
}
.svsh-done-btn:disabled{ opacity:.4; }

.svsh-create-body{ padding:16px 18px 10px; }
.svsh-name-input{
    width:100%;padding:13px 15px;
    background:rgba(255,255,255,0.045);
    border:1px solid rgba(255,255,255,0.09);
    border-radius:13px;color:#fff;font-size:13.5px;
    font-family:Poppins,sans-serif;outline:none;box-sizing:border-box;
    transition:border-color .2s, background .2s;
}
.svsh-name-input::placeholder{ color:rgba(255,255,255,0.22); }
.svsh-name-input:focus{
    border-color:rgba(255,45,45,0.4);
    background:rgba(255,45,45,0.04);
}

.svsh-required-hint{
    font-size:10.5px;color:rgba(255,255,255,0.25);
    margin:7px 2px 20px;
}
.svsh-required-hint.svsh-error{ color:#ff6060; }

.svsh-toggle-row{
    display:flex;align-items:center;justify-content:space-between;
    gap:14px;padding:6px 2px;
}
.svsh-toggle-title{ font-size:13px;font-weight:600;color:#fff; }
.svsh-toggle-sub{ font-size:10.5px;color:rgba(255,255,255,0.35);margin-top:2px; }

.svsh-switch{ position:relative;width:44px;height:26px;flex-shrink:0; }
.svsh-switch input{ opacity:0;width:0;height:0; }
.svsh-switch-slider{
    position:absolute;inset:0;background:rgba(255,255,255,0.15);
    border-radius:999px;cursor:pointer;transition:.2s;
}
.svsh-switch-slider::before{
    content:'';position:absolute;width:20px;height:20px;left:3px;top:3px;
    background:#fff;border-radius:50%;transition:.2s;
}
.svsh-switch input:checked + .svsh-switch-slider{ background:#f24455; }
.svsh-switch input:checked + .svsh-switch-slider::before{ transform:translateX(18px); }

/* Desktop: centered modal, same as idx-sheet */
@media (min-width:791px){
    .svsh-panel{
        left:50%;right:auto;bottom:auto;top:50%;
        width:100%;max-width:340px;
        border-radius:18px;
        transform:translate(-50%,-40%) scale(0.95);
        opacity:0;
        pointer-events:none;
        max-height:70vh;
    }
    .svsh-panel.show{
        transform:translate(-50%,-50%) scale(1);
        opacity:1;
        pointer-events:all;
    }
    .svsh-handle{display:none;}
}

/* 790px — TABLET */
@media (max-width:790px){
    .svsh-panel{
        border-radius:18px 18px 0 0;
        max-height:78vh;
    }
    .svsh-screen{ padding:7px 0 max(9px, env(safe-area-inset-bottom)); }
    .svsh-handle{ width:32px;height:3.5px;margin:0 auto 4px; }
    .svsh-section-label{ padding:11px 16px 5px; font-size:12.5px; }
    .svsh-row{ padding:8px 16px; font-size:12.5px; gap:10px; }
    .svsh-row-icon{ font-size:15px; width:17px; }
    .svsh-col-thumb{ width:32px;height:32px;border-radius:8px; }
    .svsh-add-btn{ width:25px;height:25px;font-size:14px; }
    .svsh-create-topbar{ padding:5px 16px 12px; }
    .svsh-create-body{ padding:14px 16px 9px; }
}

/* 490px — LARGE MOBILE */
@media (max-width:490px){
    .svsh-panel{
        border-radius:16px 16px 0 0;
        max-height:76vh;
        backdrop-filter:blur(16px);
        -webkit-backdrop-filter:blur(16px);
    }
    .svsh-screen{ padding:6px 0 max(8px, env(safe-area-inset-bottom)); }
    .svsh-handle{ width:30px;height:3px;margin:0 auto 3px; }
    .svsh-section-label{ padding:10px 14px 5px; font-size:12px; }
    .svsh-row{ padding:7px 14px; font-size:12px; gap:9px; }
    .svsh-row-icon{ font-size:14px; width:16px; }
    .svsh-col-thumb{ width:30px;height:30px;border-radius:8px; }
    .svsh-add-btn{ width:24px;height:24px;font-size:13px; }
    .svsh-create-topbar{ padding:5px 14px 11px; }
    .svsh-back-btn{ width:27px;height:27px;font-size:17px; }
    .svsh-create-title{ font-size:13px; }
    .svsh-create-body{ padding:13px 14px 8px; }
    .svsh-name-input{ font-size:13px;padding:12px 14px;border-radius:12px; }
}

/* 390px — STANDARD MOBILE */
@media (max-width:390px){
    .svsh-panel{
        border-radius:14px 14px 0 0;
        max-height:74vh;
        backdrop-filter:blur(14px);
        -webkit-backdrop-filter:blur(14px);
        box-shadow:0 -8px 30px rgba(0,0,0,0.45);
    }
    .svsh-screen{ padding:6px 0 max(7px, env(safe-area-inset-bottom)); }
    .svsh-handle{ width:28px;height:3px;margin:0 auto 3px; }
    .svsh-section-label{ padding:9px 12px 4px; font-size:11.5px; }
    .svsh-row{ padding:6.5px 12px; font-size:11.5px; gap:8px; }
    .svsh-row-icon{ font-size:13.5px; width:15px; }
    .svsh-col-thumb{ width:28px;height:28px;border-radius:7px; }
    .svsh-add-btn{ width:22px;height:22px;font-size:12px; }
    .svsh-create-topbar{ padding:4px 12px 10px; }
    .svsh-back-btn{ width:25px;height:25px;font-size:16px; }
    .svsh-create-title{ font-size:12.5px; }
    .svsh-create-body{ padding:12px 12px 7px; }
    .svsh-name-input{ font-size:12.5px;padding:11px 13px;border-radius:11px; }
}

/* 330px — SMALL / COMPACT MOBILE */
@media (max-width:330px){
    .svsh-panel{
        border-radius:12px 12px 0 0;
        max-height:72vh;
        backdrop-filter:blur(12px);
        -webkit-backdrop-filter:blur(12px);
        box-shadow:0 -6px 24px rgba(0,0,0,0.4);
    }
    .svsh-screen{ padding:5px 0 max(6px, env(safe-area-inset-bottom)); }
    .svsh-handle{ width:24px;height:2.5px;margin:0 auto 3px; }
    .svsh-screen::-webkit-scrollbar{width:3px;}
    .svsh-section-label{ padding:8px 10px 4px; font-size:11px; }
    .svsh-row{ padding:5.5px 10px; font-size:10.5px; gap:7px; }
    .svsh-row-icon{ font-size:12.5px; width:13px; }
    .svsh-col-thumb{ width:26px;height:26px;border-radius:6px; }
    .svsh-add-btn{ width:20px;height:20px;font-size:11px; }
    .svsh-create-topbar{ padding:4px 10px 8px; }
    .svsh-back-btn{ width:23px;height:23px;font-size:14px; }
    .svsh-create-title{ font-size:11.5px; }
    .svsh-create-body{ padding:10px 10px 6px; }
    .svsh-name-input{ font-size:11.5px;padding:10px 12px;border-radius:10px; }
}
</style>

<script>
/* Point this at wherever collections_api.php lives relative to the
   current page — same file works on home.php, profile.php, etc. */
const SVSH_ENDPOINT = 'collections_api.php';

let svshMemeId    = null;
let svshCollections = [];
let svshOnChange  = null; // optional callback(state) so the calling page can sync its own button/counts

function svshFetch(body){
    return fetch(SVSH_ENDPOINT, {
        method:  'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body:    body
    }).then(function(r){ return r.json(); });
}

function openSaveModal(memeId, onChange){
    svshMemeId   = memeId;
    svshOnChange = onChange || null;

    document.getElementById('svshPanel').classList.remove('svsh-create-mode');
    document.getElementById('svshCollectionsList').innerHTML = '<div class="svsh-loading">Loading…</div>';

    document.getElementById('svshOverlay').classList.add('show');
    document.getElementById('svshPanel').classList.add('show');
    document.body.style.overflow = 'hidden';

    svshFetch('col_action=get_my_collections&meme_id='+memeId).then(function(d){
        if(d.status !== 'ok') return;
        svshCollections = d.collections || [];
        svshRenderCollections();
    }).catch(function(){
        document.getElementById('svshCollectionsList').innerHTML =
            '<div class="svsh-loading">Couldn\'t load collections</div>';
    });
}

function svshClose(){
    document.getElementById('svshOverlay').classList.remove('show');
    document.getElementById('svshPanel').classList.remove('show');
    document.body.style.overflow = '';
    setTimeout(function(){
        svshMemeId = null;
        document.getElementById('svshPanel').classList.remove('svsh-create-mode');
        document.getElementById('svshNameInput').value = '';
        document.getElementById('svshPublicToggle').checked = false;
    }, 280);
}

function svshRenderCollections(){
    var list = document.getElementById('svshCollectionsList');
    if(!svshCollections.length){ list.innerHTML = ''; return; }

    list.innerHTML = svshCollections.map(function(c){
        var thumb = c.thumb
            ? '<img src="'+c.thumb+'" class="svsh-col-thumb" onerror="this.src=\'uploads/Text.jpg\'">'
            : '<div class="svsh-col-thumb svsh-col-thumb-empty"><i class="ri-folder-3-line"></i></div>';
        var inCol = !!c.in_collection;
        return '<div class="svsh-row svsh-col-row" onclick="svshToggleCollection('+c.id+')">'+
            thumb+
            '<div class="svsh-row-text">'+
                '<div class="svsh-row-title">'+svshEsc(c.name)+'</div>'+
                '<div class="svsh-row-sub"><i class="'+(c.is_public==1?'ri-earth-line':'ri-lock-line')+'"></i> '+
                    (c.is_public==1?'Public':'Only me')+'</div>'+
            '</div>'+
            '<div class="svsh-add-btn '+(inCol?'svsh-added':'')+'" id="svshAddBtn-'+c.id+'">'+
                '<i class="'+(inCol?'ri-check-line':'ri-add-line')+'"></i>'+
            '</div>'+
        '</div>';
    }).join('');
}

function svshToggleCollection(colId){
    if(!svshMemeId) return;
    var col = svshCollections.find(function(c){ return c.id == colId; });
    if(!col) return;

    var wasIn = !!col.in_collection;
    col.in_collection = !wasIn;
    svshUpdateAddBtn(colId, col.in_collection);

    var action = wasIn ? 'remove_from_collection' : 'add_to_collection';
    svshFetch('col_action='+action+'&col_id='+colId+'&meme_id='+svshMemeId).then(function(d){
        if(d.status !== 'ok' && d.status !== 'already'){
            col.in_collection = wasIn;
            svshUpdateAddBtn(colId, wasIn);
            return;
        }
        if(d.item_count !== undefined) col.item_count = d.item_count;
        svshToast(col.in_collection ? 'Added to '+col.name : 'Removed from '+col.name);
        if(svshOnChange) svshOnChange({ type:'collection', colId:colId, inCollection:col.in_collection });
    }).catch(function(){ col.in_collection = wasIn; svshUpdateAddBtn(colId, wasIn); });
}

function svshUpdateAddBtn(colId, inCol){
    var btn = document.getElementById('svshAddBtn-'+colId);
    if(!btn) return;
    btn.classList.toggle('svsh-added', inCol);
    btn.innerHTML = '<i class="'+(inCol?'ri-check-line':'ri-add-line')+'"></i>';
}

function svshOpenCreate(){
    document.getElementById('svshPanel').classList.add('svsh-create-mode');
    document.getElementById('svshNameInput').value = '';
    document.getElementById('svshPublicToggle').checked = false;
    document.getElementById('svshRequiredHint').classList.remove('svsh-error');
    setTimeout(function(){ document.getElementById('svshNameInput').focus(); }, 260);
}
function svshBackToMain(){
    document.getElementById('svshPanel').classList.remove('svsh-create-mode');
}
function svshValidateName(){
    document.getElementById('svshRequiredHint').classList.remove('svsh-error');
}

function svshCreateCollection(){
    var name = document.getElementById('svshNameInput').value.trim();
    if(!name){ document.getElementById('svshRequiredHint').classList.add('svsh-error'); return; }

    var isPublic = document.getElementById('svshPublicToggle').checked ? 1 : 0;
    var btn = document.getElementById('svshDoneBtn');
    btn.disabled = true;

    svshFetch('col_action=create_collection&name='+encodeURIComponent(name)+'&is_public='+isPublic+'&meme_id='+(svshMemeId||0))
    .then(function(d){
        btn.disabled = false;
        if(d.status !== 'ok'){ svshToast('Could not create collection'); return; }
        svshCollections.unshift({
            id: d.id, name: d.name, is_public: d.is_public,
            item_count: svshMemeId ? 1 : 0, thumb: null, in_collection: !!svshMemeId
        });
        svshRenderCollections();
        svshBackToMain();
        svshToast('Collection "'+d.name+'" created ✓');
        if(svshOnChange) svshOnChange({ type:'created', colId:d.id });
    }).catch(function(){ btn.disabled = false; svshToast('Network error'); });
}

function svshEsc(s){
    if(!s) return '';
    var div = document.createElement('div');
    div.textContent = s;
    return div.innerHTML;
}

function svshToast(msg){
    if(typeof showToast === 'function'){ showToast(msg); return; } // reuse page's toast if present
    var t = document.createElement('div');
    t.textContent = msg;
    t.style.cssText = 'position:fixed;bottom:90px;left:50%;transform:translateX(-50%);'+
        'background:rgba(20,20,25,.95);color:#fff;padding:8px 20px;border-radius:50px;'+
        'font-size:12px;z-index:999999999;';
    document.body.appendChild(t);
    setTimeout(function(){ t.remove(); }, 2200);
}
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
(function(){
    var FEED = peuraGroupFeed, BATCH = 6;
    var listEl = document.getElementById('groupFeed');
    var rendered = 0, loadingBatch = false, sentinelObs = null, postObs = null;
    var visibility = {};

    function esc(s){
        if(s===null||s===undefined) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function fmtNum(n){
        n = parseInt(n)||0;
        if(n>=1e6) return (n/1e6).toFixed(1).replace(/\.0$/,'')+'M';
        if(n>=1e3) return (n/1e3).toFixed(1).replace(/\.0$/,'')+'K';
        return n;
    }
    function linkify(raw){
        return esc(raw||'')
            .replace(/#([a-zA-Z0-9_]+)/g,function(m,tag){
                return '<a href="search.php?focus=1&mq='+encodeURIComponent('#'+tag)+'" onclick="event.stopPropagation()" style="color:#6366f1;font-weight:600;text-decoration:none;">'+m+'</a>';
            })
            .replace(/@([a-zA-Z0-9_]+)/g,function(m,u){
                return '<a href="profile.php?username='+encodeURIComponent(u)+'" onclick="event.stopPropagation()" style="color:#ff2b2b;font-weight:600;text-decoration:none;">'+m+'</a>';
            });
    }
    function typeTagInfo(t){
        t=(t||'').toLowerCase();
        return ({image:{icon:'ri-file-copy-line',label:'Photo'},video:{icon:'ri-vidicon-line',label:'Video'},gif:{icon:'ri-file-gif-line',label:'GIF'}})[t]||{icon:'ri-file-copy-line',label:'Photo'};
    }
    function fullDate(s){
        if(!s) return ''; var d=new Date(s.replace(' ','T')); if(isNaN(d)) return s;
        var M=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return d.getDate()+' '+M[d.getMonth()]+' '+d.getFullYear()+' · '+String(d.getHours()).padStart(2,'0')+':'+String(d.getMinutes()).padStart(2,'0');
    }
    function isNewPost(s){ if(!s) return false; var d=new Date(s.replace(' ','T')); return !isNaN(d)&&(Date.now()-d.getTime())<=172800*1000; }
    function likesText(p){
        var proof=Array.isArray(p.socialProof)?p.socialProof:[];
        var lp=proof.filter(function(sp){return sp.action_type==='like';});
        var c=p.likeCount||0, rc=p.repostCount||0, base='';
        if(c>0){
            if(lp.length){
                var rest=c-1, first=esc(lp[0].username||'');
                base = rest>0
                  ? '<span class="gf-likes-dim">Liked by</span> <b>'+first+'</b> <span class="gf-likes-dim">and</span> <b>'+fmtNum(rest)+' other'+(rest===1?'':'s')+'</b>'
                  : '<span class="gf-likes-dim">Liked by</span> <b>'+first+'</b>';
            } else base='<b>'+fmtNum(c)+' like'+(c===1?'':'s')+'</b>';
        }
        if(rc>0){ var rp='<b>'+fmtNum(rc)+' repost'+(rc===1?'':'s')+'</b>'; base=base?base+' · '+rp:rp; }
        return base;
    }
    function socialRowHtml(p){
        var lp=Array.isArray(p.socialProof)?p.socialProof.filter(function(sp){return sp.action_type==='like';}):[];
        var av=lp.length?'<div class="gf-social-avatars">'+lp.slice(0,3).map(function(sp){return '<img src="'+esc(sp.profile_pic||'uploads/me.jpeg')+'" onerror="this.src=\'uploads/me.jpeg\'">';}).join('')+'</div>':'';
        var tx=likesText(p);
        if(!av && !tx) return '';
        return '<div class="gf-social-row" onclick="event.stopPropagation();openEngModal('+p.id+',\'likes\')">'+av+(tx?'<div class="gf-likes-text">'+tx+'</div>':'')+'</div>';
    }

    function renderPost(p){
        var media=(Array.isArray(p.media)&&p.media.length)?p.media:[{file:p.file,type:p.type}];
        var mc=media.length, isOwn=(String(p.userId)===String(gfCurrentUserId));
        var mediaInner='<div class="gf-media-track">'+media.map(function(m,i){
            var t=(m.type||'').toLowerCase();
            if(t==='video'){
                return '<div class="gf-media-item" data-idx="'+i+'" data-type="video"><video src="'+esc(m.file)+'" muted loop playsinline preload="metadata" class="gf-video" data-idx="'+i+'" onloadedmetadata="gfMeasure(this)" onclick="event.stopPropagation();openGFModal('+p.id+')"></video></div>';
            }
            return '<div class="gf-media-item" data-idx="'+i+'" data-type="'+(t||'image')+'"><img src="'+esc(m.file)+'" loading="lazy" draggable="false" onerror="this.src=\'uploads/Text.jpg\'" onload="gfMeasure(this)" onclick="event.stopPropagation();openGFModal('+p.id+')"></div>';
        }).join('')+'</div>';

        var dotsHtml=(mc>1)?'<div class="gf-dots">'+media.slice(0,20).map(function(_,i){return '<span class="gf-dot'+(i===0?' active':'')+'"></span>';}).join('')+'</div>':'';
        var counterHtml=(mc>1)?'<div class="gf-media-counter">1/'+mc+'</div>':'';
        var ti=typeTagInfo(media[0].type);
        var topRight='<div class="gf-top-right-tags">'+counterHtml+'<div class="gf-type-tag"><i class="'+ti.icon+'"></i><span>'+ti.label+'</span></div></div>';
        var arrows=(mc>1)?'<button type="button" class="gf-media-arrow gf-arrow-left" onclick="event.stopPropagation();gfArrowNav(this,-1)"><i class="ri-arrow-left-s-line"></i></button><button type="button" class="gf-media-arrow gf-arrow-right" onclick="event.stopPropagation();gfArrowNav(this,1)"><i class="ri-arrow-right-s-line"></i></button>':'';

        var multiBadge = (mc>1) ? '<div class="gf-multi-badge"><i class="ri-stack-line"></i> 1/'+mc+'</div>' : '';

        var partner='';
        if(p.isPartnership) partner='<div class="gf-partnership-tag"><i class="ri-handshake-line"></i>Paid partnership'+(p.partnerBrand?' with <b>'+esc(p.partnerBrand)+'</b>':'')+'</div>';

        var strip='';
        if(!p.isPartnership && Array.isArray(p.socialProof) && p.socialProof.length){
            var spClass='gf-social-proof'+(mc>1?' gf-sp-raised':'');
            strip='<div class="'+spClass+'">'+p.socialProof.slice(0,4).map(function(sp){
                var like=sp.action_type==='like';
                return '<a href="profile.php?user_id='+sp.id+'" onclick="event.stopPropagation()" class="gf-sp-wrap"><img src="'+esc(sp.profile_pic||'uploads/me.jpeg')+'" onerror="this.src=\'uploads/me.jpeg\'" class="gf-sp-avatar"><span class="gf-sp-badge" style="background:'+(like?'#f24455':'#7a4aff')+';"><i class="'+(like?'ri-heart-3-fill':'ri-repeat-line')+'"></i></span></a>';
            }).join('')+'</div>';
        }

        var discussBtn = '<button type="button" class="gf-discuss-btn" data-id="'+p.id+'" onclick="event.stopPropagation();gfdOpenDiscuss('+p.id+')"><i class="ri-discuss-line"></i><span>Discuss</span></button>';

        var displayName=isOwn?'You':esc(p.username||'');
        var newTag=isNewPost(p.time)?'<span class="gf-dot-sep">·</span><span class="gf-new-tag">New</span>':'';
        var trackBtn=!isOwn?'<span class="gf-dot-sep">•</span><button type="button" class="gf-track-btn'+(p.following?' following':'')+'" data-user="'+p.userId+'" onclick="event.stopPropagation();gfToggleTrack(this)">'+(p.following?'Untrack':'Track')+'</button>':'';
        var adminChip = p.isAdmin ? '<span class="gf-admin-chip">ADMIN</span>' : '';

        /* ── nickname line under the username, like home.php's ipv-sub-line ── */
        var nickHtml = (!isOwn && p.nickname) ? '<div class="gf-sub-line">@'+esc(p.nickname)+'</div>' : '';

        var w=(p.caption||'').trim().split(/\s+/).filter(Boolean), capHtml;
        if(w.length>14){
            capHtml='<span class="gf-cap-short" data-full="'+esc(p.caption||'')+'" data-expanded="0">'+linkify(w.slice(0,14).join(' '))+'<span class="gf-more-toggle" onclick="event.stopPropagation();gfToggleCaption(this)"> ...more</span></span>';
        } else capHtml=linkify(p.caption||'');

        var wrapClass='gf-media-wrap'+(mc>1?' gf-multi':'');

        return '<div class="gf-post" data-id="'+p.id+'" data-user="'+p.userId+'">'+
            '<div class="gf-header">'+
                '<img class="gf-avatar" src="'+esc(p.pic||'uploads/me.jpeg')+'" onerror="this.src=\'uploads/me.jpeg\'" onclick="location.href=\'profile.php?user_id='+p.userId+'\'">'+
                '<div class="gf-header-info"><div class="gf-username-row"><span class="gf-username" onclick="location.href=\'profile.php?user_id='+p.userId+'\'">'+displayName+'</span>'+adminChip+newTag+trackBtn+'</div>'+nickHtml+'</div>'+
                '<button type="button" class="gf-kebab" data-id="'+p.id+'" onclick="event.stopPropagation();openGfSheet('+p.id+')"><i class="ri-menu-line"></i></button>'+
            '</div>'+
            '<div class="'+wrapClass+'" data-count="'+mc+'" data-active-idx="0" onclick="openGFModal('+p.id+')" ondblclick="event.stopPropagation();gfDoubleTapLike(this,'+p.id+',event)">'+
                topRight+multiBadge+mediaInner+arrows+strip+partner+discussBtn+dotsHtml+
            '</div>'+
            '<div class="gf-actions"><div class="gf-actions-left">'+
                '<div class="gf-action-group"><button type="button" class="gf-action-btn gf-like'+(p.liked?' active':'')+'" data-id="'+p.id+'" onclick="event.stopPropagation();gfToggleAction(\'like\',\'.gf-like-count\',this)"><i class="ri-heart-3-fill"></i></button><span class="gf-action-count gf-like-count">'+fmtNum(p.likeCount||0)+'</span></div>'+
                '<div class="gf-action-group"><button type="button" class="gf-action-btn gf-repost'+(p.reposted?' active':'')+'" data-id="'+p.id+'" onclick="event.stopPropagation();gfToggleAction(\'repost\',\'.gf-repost-count\',this)"><i class="ri-repeat-line"></i></button><span class="gf-action-count gf-repost-count">'+fmtNum(p.repostCount||0)+'</span></div>'+
                '<div class="gf-action-group"><button type="button" class="gf-action-btn gf-fav'+(p.faved?' active':'')+'" data-id="'+p.id+'" onclick="event.stopPropagation();gfToggleAction(\'fav\',\'.gf-fav-count\',this)"><i class="ri-bookmark-fill"></i></button><span class="gf-action-count gf-fav-count">'+fmtNum(p.favCount||0)+'</span></div>'+
                '<div class="gf-action-group"><button type="button" class="gf-action-btn gf-share" data-id="'+p.id+'" onclick="event.stopPropagation();openShareModal('+p.id+')"><i class="ri-share-forward-line"></i></button><span class="gf-action-count gf-share-count">'+fmtNum(p.shareCount||0)+'</span></div>'+
            '</div></div>'+
            socialRowHtml(p)+
            '<div class="gf-caption"><b class="gf-cap-user">'+displayName+'</b>'+capHtml+'</div>'+
            (p.time?'<div class="gf-time">'+esc(fullDate(p.time))+'</div>':'')+
        '</div>';
    }

    function skeletonHTML(n){
        var one='<div class="gf-card-unit"><div class="gf-skel-post"><div class="gf-skel-header"><div class="gf-skel-avatar"></div><div class="gf-skel-lines"><div class="gf-skel-line w1"></div><div class="gf-skel-line w2"></div></div></div><div class="gf-skel-media"></div><div class="gf-skel-actions"><div class="gf-skel-icon"></div><div class="gf-skel-icon"></div><div class="gf-skel-icon"></div><div class="gf-skel-icon"></div></div><div class="gf-skel-cap"></div></div></div>';
        return new Array(n+1).join(one);
    }

    function renderBatch(count,animate){
        var end=Math.min(rendered+count,FEED.length), html='';
        for(var i=rendered;i<end;i++){
            html+='<div class="gf-card-unit">'+renderPost(FEED[i])+'</div>';
        }
        var tmp=document.createElement('div'); tmp.innerHTML=html;
        var frag=document.createDocumentFragment(), newUnits=[];
        Array.prototype.slice.call(tmp.children).forEach(function(n){ newUnits.push(n); frag.appendChild(n); });
        var sentinel=document.getElementById('gfSentinel');
        if(sentinel) listEl.insertBefore(frag,sentinel); else listEl.appendChild(frag);
        rendered=end;

        bindCarousels(newUnits); observePosts(newUnits);

        if(rendered>=FEED.length){
            var s=document.getElementById('gfSentinel'); if(s) s.remove();
            if(sentinelObs){ sentinelObs.disconnect(); sentinelObs=null; }
        } else ensureSentinel();
    }

    function ensureSentinel(){
        var s=document.getElementById('gfSentinel');
        if(!s){ s=document.createElement('div'); s.id='gfSentinel'; s.className='gf-load-more'; s.innerHTML='<div class="gf-load-dot"></div><div class="gf-load-dot"></div><div class="gf-load-dot"></div>'; listEl.appendChild(s); }
        if(sentinelObs) sentinelObs.disconnect();
        if(!('IntersectionObserver' in window)){ renderBatch(FEED.length,false); return; }
        sentinelObs=new IntersectionObserver(function(es){
            es.forEach(function(e){
                if(e.isIntersecting && !loadingBatch && rendered<FEED.length){
                    loadingBatch=true;
                    setTimeout(function(){ renderBatch(BATCH,true); loadingBatch=false; },110);
                }
            });
        },{rootMargin:'600px 0px 600px 0px'});
        sentinelObs.observe(s);
    }

    function observePosts(units){
        if(!('IntersectionObserver' in window)) return;
        if(!postObs){
            postObs=new IntersectionObserver(function(es){
                es.forEach(function(e){
                    var post=e.target; var id=post.dataset.id;
                    visibility[id]=e.intersectionRatio;
                    var v=post.querySelector('.gf-video');
                    if(v){ if(e.isIntersecting&&e.intersectionRatio>.6) v.play().catch(function(){}); else v.pause(); }
                });
            },{threshold:[0,.6,1]});
        }
        units.forEach(function(u){ var p=u.querySelector('.gf-post'); if(p) postObs.observe(p); });
    }

    /* ── DYNAMIC ASPECT RATIO — mirrors home.php's hpMeasure()/applyAspect()
       exactly: measures the loaded media's own aspect ratio and resizes the
       single-media wrap to fit it (clamped), so tall/wide posts don't get
       cropped into a fixed square. Multi-media posts keep a fixed ratio
       (.gf-multi, aspect-ratio:4/3.5) and use object-fit:contain instead. ── */
    var MULTI_RATIO=3.5/4, MULTI_TOL=.15;
    window.gfMeasure=function(el){
        var wrap=el.closest('.gf-media-wrap'); if(!wrap) return;
        var w=el.tagName==='VIDEO'?el.videoWidth:el.naturalWidth;
        var h=el.tagName==='VIDEO'?el.videoHeight:el.naturalHeight;
        if(!w||!h) return;
        var ratio=h/w; el.dataset.ratio=ratio;
        if(wrap.classList.contains('gf-multi')){
            el.classList.toggle('gf-fit-cover',Math.abs(ratio-MULTI_RATIO)/MULTI_RATIO<=MULTI_TOL);
            return;
        }
        if(parseInt(el.dataset.idx||'0',10)===parseInt(wrap.dataset.activeIdx||'0',10)) applyAspect(wrap,ratio);
    };
    function applyAspect(wrap,ratio){
        var c=Math.max(.55,Math.min(ratio,1.6)), width=wrap.clientWidth||wrap.offsetWidth; if(!width) return;
        wrap.style.aspectRatio='auto';
        wrap.style.height=Math.round(width*c)+'px';
    }
    window.addEventListener('resize',function(){
        document.querySelectorAll('#groupFeed .gf-media-wrap:not(.gf-multi)').forEach(function(wrap){
            var i=parseInt(wrap.dataset.activeIdx||'0',10);
            var el=wrap.querySelector('.gf-media-item[data-idx="'+i+'"] img, .gf-media-item[data-idx="'+i+'"] video');
            if(el&&el.dataset.ratio) applyAspect(wrap,parseFloat(el.dataset.ratio));
        });
    });

    function bindCarousels(units){
        units.forEach(function(unit){
            var wrap=unit.querySelector('.gf-media-wrap[data-count]'); if(!wrap) return;
            var count=parseInt(wrap.dataset.count,10); if(count<=1) return;
            var track=wrap.querySelector('.gf-media-track'), dots=wrap.querySelectorAll('.gf-dot'), counter=wrap.querySelector('.gf-media-counter');
            var idx=0,startX=0,dx=0,dragging=false;
            function goTo(n){
                idx=Math.max(0,Math.min(n,count-1)); wrap.dataset.activeIdx=String(idx);
                track.style.transform='translateX(-'+(idx*100)+'%)';
                dots.forEach(function(d,i){ d.classList.toggle('active',i===idx); });
                if(counter) counter.textContent=(idx+1)+'/'+count;
                wrap.querySelectorAll('video').forEach(function(v){ var vi=parseInt(v.dataset.idx,10); if(vi!==idx) v.pause(); });
            }
            wrap._gfGoTo=function(d){ goTo(idx+d); };
            wrap.addEventListener('touchstart',function(e){ if(e.touches.length!==1) return; startX=e.touches[0].clientX; dx=0; dragging=true; },{passive:true});
            wrap.addEventListener('touchmove',function(e){ if(!dragging) return; dx=e.touches[0].clientX-startX; track.style.transform='translateX(calc(-'+(idx*100)+'% + '+dx+'px))'; },{passive:true});
            wrap.addEventListener('touchend',function(){ if(!dragging) return; dragging=false; var n=idx; if(Math.abs(dx)>60) n=dx<0?idx+1:idx-1; dx=0; goTo(n); },{passive:true});
        });
    }
    window.gfArrowNav=function(btn,dir){ var w=btn.closest('.gf-media-wrap'); if(w&&w._gfGoTo) w._gfGoTo(dir); };

    window.gfToggleCaption=function(el){
        var wrap=el.closest('.gf-cap-short'); if(!wrap) return;
        var open=wrap.dataset.expanded==='1', full=wrap.dataset.full||'';
        if(!open){ wrap.dataset.expanded='1'; wrap.innerHTML=linkify(full)+' <span class="gf-more-toggle" onclick="event.stopPropagation();gfToggleCaption(this)"> less</span>'; }
        else { wrap.dataset.expanded='0'; wrap.innerHTML=linkify(full.trim().split(/\s+/).slice(0,14).join(' '))+'<span class="gf-more-toggle" onclick="event.stopPropagation();gfToggleCaption(this)"> ...more</span>'; }
    };

    window.gfDoubleTapLike=function(box,memeId,e){
        var rect=box.getBoundingClientRect();
        var cx=(e&&e.clientX)?e.clientX-rect.left:rect.width/2;
        var cy=(e&&e.clientY)?e.clientY-rect.top:rect.height/2;
        var h=document.createElement('div'); h.className='gf-double-tap-heart'; h.style.left=cx+'px'; h.style.top=cy+'px'; h.innerHTML='<i class="ri-heart-3-fill"></i>';
        box.appendChild(h);
        requestAnimationFrame(function(){ h.classList.add('show'); });
        setTimeout(function(){ h.remove(); },950);
        var post=box.closest('.gf-post'); var likeBtn=post?post.querySelector('.gf-like'):null;
        if(likeBtn && !likeBtn.classList.contains('active')) gfToggleAction('like','.gf-like-count',likeBtn);
    };

    if(!FEED.length){
        listEl.innerHTML='<div class="gf-empty"><i class="ri-image-2-line"></i>No posts here yet.</div>';
    } else {
        listEl.innerHTML=skeletonHTML(3);
        setTimeout(function(){ listEl.innerHTML=''; renderBatch(BATCH,true); },400);
    }
})();
</script>
</body>
</html>