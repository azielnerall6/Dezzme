<?php
session_start();
include 'config.php';
include_once 'join_questions_helper.php';

/* ══════════════════════════════════════════════════════════════════
   AUTH + CORE IDENTIFIERS
   ══════════════════════════════════════════════════════════════════ */
$current_user = (int)($_SESSION['user_id'] ?? 0);
if (!$current_user) { header("Location: login.php"); exit(); }

$group_id = (int)($_GET['id'] ?? 0);
if (!$group_id) { echo "Group not found"; exit(); }

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

/* ══════════════════════════════════════════════════════════════════
   GROUP LOCATION
   Adds the column `location` to `groups` once.
   ══════════════════════════════════════════════════════════════════ */
const GC_LOC_LEN = 100;

function gc_loc_ensure_schema($conn) {
    if (!empty($_SESSION['gc_loc_schema_v1'])) return;
    try {
        $r = $conn->query("SHOW COLUMNS FROM `groups` LIKE 'location'");
        if ($r && $r->num_rows === 0) $conn->query("ALTER TABLE `groups` ADD COLUMN location VARCHAR(100) NULL");
        $_SESSION['gc_loc_schema_v1'] = 1;
    } catch (Throwable $e) { /* no ALTER permission: add the column by hand */ }
}
gc_loc_ensure_schema($conn);

/* ══════════════════════════════════════════════════════════════════
   MODERATION TOOLS: auto-moderation words, slow mode, ban notes
   Adds 4 columns to `groups` and the table group_ban_notes, once.
   ══════════════════════════════════════════════════════════════════ */
const GC_AM_MAX_WORDS = 100;
const GC_AM_WORD_LEN  = 40;
const GC_BAN_NOTE_LEN = 200;
const GC_SLOW_OPTS    = [0, 30, 60, 120, 300, 600, 1800, 3600];

function gc_mod_ensure_schema($conn) {
    if (!empty($_SESSION['gc_mod_schema_v1'])) return;
    try {
        $cols = [
            'automod_words'     => "TEXT NULL",
            'automod_action'    => "VARCHAR(10) NOT NULL DEFAULT 'block'",
            'automod_whole'     => "TINYINT(1) NOT NULL DEFAULT 1",
            'slow_mode_seconds' => "INT NOT NULL DEFAULT 0",
        ];
        foreach ($cols as $c => $def) {
            $r = $conn->query("SHOW COLUMNS FROM `groups` LIKE '$c'");
            if ($r && $r->num_rows === 0) $conn->query("ALTER TABLE `groups` ADD COLUMN $c $def");
        }
        $conn->query("CREATE TABLE IF NOT EXISTS group_ban_notes (
            id INT AUTO_INCREMENT PRIMARY KEY, group_id INT NOT NULL, user_id INT NOT NULL,
            reason VARCHAR(200) NOT NULL, set_by INT NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ban_note (group_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $_SESSION['gc_mod_schema_v1'] = 1;
    } catch (Throwable $e) { /* no ALTER/CREATE permission: run the SQL by hand */ }
}
gc_mod_ensure_schema($conn);

/* ── Warnings + new-member probation ── */
const GC_WARN_OPTS = [0, 2, 3, 4, 5];
const GC_PROB_OPTS = [0, 1, 2, 3, 5];

function gc_mod2_ensure_schema($conn) {
    if (!empty($_SESSION['gc_mod_schema_v2'])) return;
    try {
        foreach (['warn_limit' => "INT NOT NULL DEFAULT 0", 'probation_posts' => "INT NOT NULL DEFAULT 0"] as $c => $def) {
            $r = $conn->query("SHOW COLUMNS FROM `groups` LIKE '$c'");
            if ($r && $r->num_rows === 0) $conn->query("ALTER TABLE `groups` ADD COLUMN $c $def");
        }
        $conn->query("CREATE TABLE IF NOT EXISTS group_warnings (
            id INT AUTO_INCREMENT PRIMARY KEY, group_id INT NOT NULL, user_id INT NOT NULL,
            reason VARCHAR(200) NOT NULL, issued_by INT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX (group_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $r = $conn->query("SHOW COLUMNS FROM `group_posts` LIKE 'is_pending'");
        if ($r && $r->num_rows === 0) $conn->query("ALTER TABLE `group_posts` ADD COLUMN is_pending TINYINT(1) NOT NULL DEFAULT 0");
        $r = $conn->query("SHOW COLUMNS FROM `group_warnings` LIKE 'seen'");     // 0 = the member has not read it yet
        if ($r && $r->num_rows === 0) $conn->query("ALTER TABLE `group_warnings` ADD COLUMN seen TINYINT(1) NOT NULL DEFAULT 1");
        $r = $conn->query("SHOW COLUMNS FROM `group_post_reports` LIKE 'escalated'");
        if ($r && $r->num_rows === 0) $conn->query("ALTER TABLE `group_post_reports` ADD COLUMN escalated TINYINT(1) NOT NULL DEFAULT 0");
        $_SESSION['gc_mod_schema_v2'] = 1;
    } catch (Throwable $e) { /* no ALTER/CREATE permission: run the SQL by hand */ }
}
gc_mod2_ensure_schema($conn);

// Activity log entry that also records the member it is about
function gc_log_target($conn, $gid, $uid, $type, $target, $details) {
    try {
        $st = $conn->prepare("INSERT INTO group_activity_log (group_id, actor_id, action_type, target_user_id, details) VALUES (?,?,?,?,?)");
        $st->bind_param("iisis", $gid, $uid, $type, $target, $details); $st->execute(); $st->close();
    } catch (Throwable $e) { /* logging must never break the action */ }
}

/* ── Fetch group + permissions ── */
$stmt = $conn->prepare("SELECT g.*, u.username AS creator_name, u.profile_pic AS creator_pic FROM groups g JOIN users u ON g.created_by=u.id WHERE g.id=?");
$stmt->bind_param("i", $group_id);
$stmt->execute();
$group = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$group) { echo "Group not found"; exit(); }

$group_creator_id = (int)$group['created_by'];
$is_owner = ($group_creator_id === $current_user);

$is_admin = false;
$stmt = $conn->prepare("SELECT 1 FROM group_admins WHERE group_id=? AND user_id=?");
$stmt->bind_param("ii", $group_id, $current_user);
$stmt->execute();
if ($stmt->get_result()->num_rows > 0) $is_admin = true;
$stmt->close();

if (!$is_owner && !$is_admin) { header("Location: group.php?id=$group_id"); exit(); }

/* ══════════════════════════════════════════════════════════════════
   GROUP HIGHLIGHTS + POLLS + JOIN QUESTIONS  (backend)
   Every request below is CSRF-checked and scoped to THIS group only.
   ══════════════════════════════════════════════════════════════════ */
const HL_MAX_MEDIA = 100;
const HL_MAX_TEXT  = 1000;
const HL_POLL_MIN  = 2;
const HL_POLL_MAX  = 4;
const HL_DIR = 'uploads/group_highlights/';
const HL_TEXT_BG = 'Text.jpg';                 // background of text-only highlights (put Text.jpg next to this file)
const HL_USER_URL = 'profile.php?user_id=';    // where a tagged @username / @nickname goes
const HL_GROUP_URL = 'group.php?id=';          // where a tagged group name goes
const GC_MAX_RULES = 15;
const GC_RULE_LEN  = 150;

function hl_json($a) { header('Content-Type: application/json'); echo json_encode($a); exit; }
function hl_fail($m) { hl_json(['success' => false, 'msg' => $m]); }
function hl_title($s, $max) { $s = trim(strip_tags((string)$s)); return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max); }
function hl_text($s, $max) { $s = str_replace(["\r\n", "\r"], "\n", (string)$s); $s = trim(strip_tags($s)); return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max); }
function hl_unlink($path) { if ($path && strpos($path, HL_DIR) === 0) @unlink(__DIR__ . '/' . $path); }
function hl_fetch($conn, $hid, $gid) {
    $st = $conn->prepare("SELECT * FROM group_highlights WHERE id=? AND group_id=? LIMIT 1");
    $st->bind_param("ii", $hid, $gid); $st->execute();
    $r = $st->get_result()->fetch_assoc(); $st->close();
    return $r ?: null;
}
function hl_media($conn, $mid, $hid) {
    $st = $conn->prepare("SELECT * FROM group_highlight_media WHERE id=? AND highlight_id=? LIMIT 1");
    $st->bind_param("ii", $mid, $hid); $st->execute();
    $r = $st->get_result()->fetch_assoc(); $st->close();
    return $r ?: null;
}
function hl_types($kind) {
    $img = ['image/jpeg' => ['jpg', 'image'], 'image/png' => ['png', 'image'], 'image/gif' => ['gif', 'image'], 'image/webp' => ['webp', 'image']];
    if ($kind === 'image') return $img;
    if ($kind === 'audio') { $w = ['wav', 'audio']; return ['audio/wav' => $w, 'audio/x-wav' => $w, 'audio/wave' => $w, 'audio/vnd.wave' => $w]; }
    return $img + ['video/mp4' => ['mp4', 'video'], 'video/webm' => ['webm', 'video'], 'video/quicktime' => ['mov', 'video']];
}
// Song clips are trimmed in the browser and sent as WAV. This checks the clip really is short (30s max).
function hl_wav_ok($tmp) {
    $h = @file_get_contents($tmp, false, null, 0, 44);
    if ($h === false || strlen($h) < 44 || substr($h, 0, 4) !== 'RIFF' || substr($h, 8, 4) !== 'WAVE') return false;
    $br = unpack('V', substr($h, 28, 4))[1];
    if ($br <= 0) return false;
    return ((filesize($tmp) - 44) / $br) <= 31;
}
function hl_save($f, $allowed, $limit, $prefix, $gid) {
    if (!$f || !isset($f['error']) || $f['error'] !== UPLOAD_ERR_OK) {
        $e = $f['error'] ?? -1;
        return ['ok' => false, 'msg' => ($e === UPLOAD_ERR_INI_SIZE || $e === UPLOAD_ERR_FORM_SIZE) ? 'File is too large for the server.' : 'Upload failed.'];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if (!isset($allowed[$mime])) return ['ok' => false, 'msg' => 'That file type is not allowed.'];
    [$ext, $type] = $allowed[$mime];
    $lim = is_array($limit) ? ($limit[$type] ?? 0) : $limit;
    if ($f['size'] > $lim) return ['ok' => false, 'msg' => 'File is too large (max ' . round($lim / 1048576) . ' MB).'];
    if ($type === 'audio' && !hl_wav_ok($f['tmp_name'])) return ['ok' => false, 'msg' => 'Song clips can be 30 seconds at most.'];
    $dir = __DIR__ . '/' . HL_DIR;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = $prefix . '_g' . $gid . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . $name)) return ['ok' => false, 'msg' => 'Could not save the file.'];
    return ['ok' => true, 'path' => HL_DIR . $name, 'type' => $type];
}
function hl_log($conn, $gid, $uid, $type, $details) {
    try {
        $st = $conn->prepare("INSERT INTO group_activity_log (group_id, actor_id, action_type, target_user_id, details) VALUES (?,?,?,NULL,?)");
        $st->bind_param("iiss", $gid, $uid, $type, $details); $st->execute(); $st->close();
    } catch (Throwable $e) { /* logging must never break the action */ }
}

/* ── Polls ── */
function hl_poll_load($conn, $mid) {
    $st = $conn->prepare("SELECT id, question, multi, results_mode, closes_at FROM group_highlight_polls WHERE media_id=? LIMIT 1");
    $st->bind_param("i", $mid); $st->execute();
    $p = $st->get_result()->fetch_assoc(); $st->close();
    if (!$p) return null;
    $pid = (int)$p['id']; $opts = [];
    $st = $conn->prepare("SELECT o.id, o.label, (SELECT COUNT(*) FROM group_highlight_poll_votes v WHERE v.option_id=o.id) AS votes FROM group_highlight_poll_options o WHERE o.poll_id=? ORDER BY o.sort_order ASC, o.id ASC");
    $st->bind_param("i", $pid); $st->execute();
    $res = $st->get_result();
    while ($o = $res->fetch_assoc()) $opts[] = ['id' => (int)$o['id'], 'label' => $o['label'], 'votes' => (int)$o['votes']];
    $st->close();
    $st = $conn->prepare("SELECT COUNT(DISTINCT user_id) AS c FROM group_highlight_poll_votes WHERE poll_id=?");
    $st->bind_param("i", $pid); $st->execute();
    $total = (int)($st->get_result()->fetch_assoc()['c'] ?? 0); $st->close();
    $closed = !empty($p['closes_at']) && strtotime($p['closes_at']) <= time();
    return ['id' => $pid, 'q' => $p['question'], 'multi' => (int)$p['multi'], 'results' => $p['results_mode'],
        'closes' => !empty($p['closes_at']) ? date('j M Y, g:i A', strtotime($p['closes_at'])) : null,
        'closed' => $closed, 'options' => $opts, 'total' => $total];
}
function hl_poll_parse($raw) {
    $d = json_decode((string)$raw, true);
    if (!is_array($d)) hl_fail('That poll could not be read.');
    $q = hl_title($d['q'] ?? '', 140);
    if ($q === '') hl_fail('Write a question for your poll.');
    $opts = [];
    foreach ((array)($d['options'] ?? []) as $o) { $o = hl_title($o, 60); if ($o !== '') $opts[] = $o; }
    $opts = array_slice($opts, 0, HL_POLL_MAX);
    if (count($opts) < HL_POLL_MIN) hl_fail('A poll needs at least ' . HL_POLL_MIN . ' options.');
    $res = in_array($d['results'] ?? '', ['always', 'after_vote', 'after_close'], true) ? $d['results'] : 'always';
    $dur = (int)($d['duration'] ?? 0);
    if (!in_array($dur, [-1, 0, 24, 72, 168], true)) $dur = 0;
    return ['q' => $q, 'multi' => !empty($d['multi']) ? 1 : 0, 'results' => $res, 'duration' => $dur, 'options' => $opts];
}
function hl_poll_delete($conn, $mid) {
    $mid = (int)$mid;
    $conn->query("DELETE v FROM group_highlight_poll_votes v JOIN group_highlight_polls p ON p.id=v.poll_id WHERE p.media_id=$mid");
    $conn->query("DELETE o FROM group_highlight_poll_options o JOIN group_highlight_polls p ON p.id=o.poll_id WHERE p.media_id=$mid");
    $conn->query("DELETE FROM group_highlight_polls WHERE media_id=$mid");
}

// Creates any missing tables/columns once per login session.
function hl_ensure_schema($conn) {
    if (!empty($_SESSION['gh_schema_v1'])) return;
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS group_highlights (
            id INT AUTO_INCREMENT PRIMARY KEY, group_id INT NOT NULL, created_by INT NOT NULL,
            title VARCHAR(30) NOT NULL, cover_path VARCHAR(255) NULL, song_path VARCHAR(255) NULL, song_title VARCHAR(60) NULL,
            song_mode ENUM('whole','each') NOT NULL DEFAULT 'whole',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX (group_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("CREATE TABLE IF NOT EXISTS group_highlight_media (
            id INT AUTO_INCREMENT PRIMARY KEY, highlight_id INT NOT NULL, group_id INT NOT NULL, added_by INT NOT NULL,
            file_path VARCHAR(255) NOT NULL, media_type ENUM('image','video','text','poll') NOT NULL,
            caption TEXT NULL, audio_path VARCHAR(255) NULL, audio_title VARCHAR(60) NULL, mute_video TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX (highlight_id, id), INDEX (group_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("CREATE TABLE IF NOT EXISTS group_highlight_polls (
            id INT AUTO_INCREMENT PRIMARY KEY, media_id INT NOT NULL, highlight_id INT NOT NULL, group_id INT NOT NULL,
            question VARCHAR(140) NOT NULL, multi TINYINT(1) NOT NULL DEFAULT 0,
            results_mode ENUM('always','after_vote','after_close') NOT NULL DEFAULT 'always',
            closes_at DATETIME NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_media (media_id), INDEX (group_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("CREATE TABLE IF NOT EXISTS group_highlight_poll_options (
            id INT AUTO_INCREMENT PRIMARY KEY, poll_id INT NOT NULL, label VARCHAR(60) NOT NULL, sort_order TINYINT NOT NULL DEFAULT 0,
            INDEX (poll_id, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("CREATE TABLE IF NOT EXISTS group_highlight_poll_votes (
            id INT AUTO_INCREMENT PRIMARY KEY, poll_id INT NOT NULL, option_id INT NOT NULL, user_id INT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_vote (option_id, user_id), INDEX (poll_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $_SESSION['gh_schema_v1'] = 1;
    } catch (Throwable $e) { /* no CREATE permission: run the SQL by hand */ }
}
hl_ensure_schema($conn);
jq_ensure_schema($conn);

if (isset($_POST['hl_action'])) {
    try {
        if (!hash_equals($csrf_token, (string)($_POST['csrf_token'] ?? ''))) hl_fail('Your session expired. Refresh the page and try again.');
        $act = (string)$_POST['hl_action'];
        $MB = 1048576;

        if ($act === 'create') {
            $title = hl_title($_POST['title'] ?? '', 30);
            if ($title === '') hl_fail('Give your highlight a name.');
            $mode = (($_POST['mode'] ?? '') === 'each') ? 'each' : 'whole';
            $st = $conn->prepare("INSERT INTO group_highlights (group_id, created_by, title, song_mode) VALUES (?,?,?,?)");
            $st->bind_param("iiss", $group_id, $current_user, $title, $mode);
            $ok = $st->execute(); $new_id = $conn->insert_id; $st->close();
            if (!$ok) hl_fail('Could not create highlight.');
            hl_log($conn, $group_id, $current_user, 'create_highlight', $title);
            hl_json(['success' => true, 'id' => $new_id]);
        }

        // Owner only: save the join-request questions (max 10, all optional to set up)
        if ($act === 'jq_save') {
            if (!$is_owner) hl_fail('Only the group owner can set join questions.');
            $r = jq_save($conn, $group_id, $_POST['questions'] ?? '');
            if (!$r['ok']) hl_fail($r['msg']);
            hl_log($conn, $group_id, $current_user, 'update_questions', (string)$r['count']);
            hl_json(['success' => true, 'count' => $r['count']]);
        }

        // Owner + admins: auto-moderation word list
        if ($act === 'automod_save') {
            $d = json_decode((string)($_POST['words'] ?? ''), true);
            if (!is_array($d)) hl_fail('That word list could not be read.');
            $clean = [];
            foreach ($d as $w) {
                $w = trim((string)preg_replace('/\s+/u', ' ', hl_title($w, GC_AM_WORD_LEN)));
                if ($w === '') continue;
                $clean[mb_strtolower($w)] = $w;
            }
            $clean  = array_slice(array_values($clean), 0, GC_AM_MAX_WORDS);
            $am_act = (($_POST['mode'] ?? '') === 'report') ? 'report' : 'block';
            $am_wh  = !empty($_POST['whole']) ? 1 : 0;
            $store  = implode("\n", $clean);
            $st = $conn->prepare("UPDATE `groups` SET automod_words=?, automod_action=?, automod_whole=? WHERE id=?");
            $st->bind_param("ssii", $store, $am_act, $am_wh, $group_id);
            $ok = $st->execute(); $st->close();
            if (!$ok) hl_fail('Could not save. Make sure the auto-moderation columns exist.');
            hl_log($conn, $group_id, $current_user, 'update_automod', (string)count($clean));
            hl_json(['success' => true, 'count' => count($clean)]);
        }

        // Owner + admins: slow mode (seconds between posts, 0 = off)
        if ($act === 'slowmode_save') {
            $secs = (int)($_POST['seconds'] ?? 0);
            if (!in_array($secs, GC_SLOW_OPTS, true)) hl_fail('Pick one of the listed times.');
            $st = $conn->prepare("UPDATE `groups` SET slow_mode_seconds=? WHERE id=?");
            $st->bind_param("ii", $secs, $group_id);
            $ok = $st->execute(); $st->close();
            if (!$ok) hl_fail('Could not save. Make sure the slow_mode_seconds column exists.');
            hl_log($conn, $group_id, $current_user, 'update_slowmode', (string)$secs);
            hl_json(['success' => true, 'seconds' => $secs]);
        }

        // Owner + admins: optional reason saved with a ban (empty reason removes the note)
        if ($act === 'ban_note_set') {
            $uid = (int)($_POST['user_id'] ?? 0);
            if (!$uid || $uid === $group_creator_id) hl_fail('Member not found.');
            $reason = hl_title($_POST['reason'] ?? '', GC_BAN_NOTE_LEN);
            $known = false;
            $st = $conn->prepare("SELECT 1 FROM group_members WHERE group_id=? AND user_id=? LIMIT 1");
            $st->bind_param("ii", $group_id, $uid); $st->execute();
            if ($st->get_result()->num_rows > 0) $known = true; $st->close();
            if (!$known) {
                $st = $conn->prepare("SELECT 1 FROM group_bans WHERE group_id=? AND banned_id=? LIMIT 1");
                $st->bind_param("ii", $group_id, $uid); $st->execute();
                if ($st->get_result()->num_rows > 0) $known = true; $st->close();
            }
            if (!$known && $is_owner) {
                $st = $conn->prepare("SELECT 1 FROM blocks WHERE blocker_id=? AND blocked_id=? LIMIT 1");
                $st->bind_param("ii", $current_user, $uid); $st->execute();
                if ($st->get_result()->num_rows > 0) $known = true; $st->close();
            }
            if (!$known) hl_fail('Member not found.');
            if ($reason === '') {
                $st = $conn->prepare("DELETE FROM group_ban_notes WHERE group_id=? AND user_id=?");
                $st->bind_param("ii", $group_id, $uid);
            } else {
                $st = $conn->prepare("INSERT INTO group_ban_notes (group_id, user_id, reason, set_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE reason=VALUES(reason), set_by=VALUES(set_by)");
                $st->bind_param("iisi", $group_id, $uid, $reason, $current_user);
            }
            $ok = $st->execute(); $st->close();
            if (!$ok) hl_fail('Could not save the ban note.');
            hl_json(['success' => true]);
        }

        // Owner only: hand the group to an admin (old owner becomes an admin)
        if ($act === 'transfer_owner') {
            if (!$is_owner) hl_fail('Only the group owner can transfer ownership.');
            $to = (int)($_POST['user_id'] ?? 0);
            if (!$to || $to === $current_user) hl_fail('Choose an admin to receive ownership.');
            $st = $conn->prepare("SELECT 1 FROM group_admins WHERE group_id=? AND user_id=? LIMIT 1");
            $st->bind_param("ii", $group_id, $to); $st->execute();
            $is_target_admin = $st->get_result()->num_rows > 0; $st->close();
            if (!$is_target_admin) hl_fail('Ownership can only be given to one of the group admins.');
            if (strcasecmp(trim((string)($_POST['confirm'] ?? '')), trim((string)$group['title'])) !== 0) hl_fail('Type the group name exactly to confirm.');
            $conn->begin_transaction();
            try {
                $st = $conn->prepare("UPDATE `groups` SET created_by=? WHERE id=? AND created_by=?");
                $st->bind_param("iii", $to, $group_id, $current_user); $st->execute();
                $aff = $st->affected_rows; $st->close();
                if ($aff !== 1) throw new Exception('not updated');
                $st = $conn->prepare("DELETE FROM group_admins WHERE group_id=? AND user_id=?");
                $st->bind_param("ii", $group_id, $to); $st->execute(); $st->close();
                $st = $conn->prepare("INSERT INTO group_admins (group_id, user_id) SELECT ?,? FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM group_admins WHERE group_id=? AND user_id=?)");
                $st->bind_param("iiii", $group_id, $current_user, $group_id, $current_user); $st->execute(); $st->close();
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                hl_fail('Could not transfer ownership. Nothing was changed.');
            }
            try {
                $st = $conn->prepare("INSERT INTO group_activity_log (group_id, actor_id, action_type, target_user_id, details) VALUES (?,?,?,?,?)");
                $dt = 'transfer'; $act_t = 'transfer_owner';
                $st->bind_param("iisis", $group_id, $current_user, $act_t, $to, $dt); $st->execute(); $st->close();
            } catch (Throwable $e) { /* logging must never break the action */ }
            hl_json(['success' => true]);
        }

        // Owner + admins: strike limit
        if ($act === 'warn_limit_save') {
            $n = (int)($_POST['limit'] ?? 0);
            if (!in_array($n, GC_WARN_OPTS, true)) hl_fail('Pick one of the listed limits.');
            $st = $conn->prepare("UPDATE `groups` SET warn_limit=? WHERE id=?");
            $st->bind_param("ii", $n, $group_id);
            $ok = $st->execute(); $st->close();
            if (!$ok) hl_fail('Could not save. Make sure the warn_limit column exists.');
            hl_log($conn, $group_id, $current_user, 'update_warnlimit', (string)$n);
            hl_json(['success' => true]);
        }

        // Owner + admins: how many first posts of a new member need approval
        if ($act === 'probation_save') {
            $n = (int)($_POST['posts'] ?? 0);
            if (!in_array($n, GC_PROB_OPTS, true)) hl_fail('Pick one of the listed numbers.');
            $st = $conn->prepare("UPDATE `groups` SET probation_posts=? WHERE id=?");
            $st->bind_param("ii", $n, $group_id);
            $ok = $st->execute(); $st->close();
            if (!$ok) hl_fail('Could not save. Make sure the probation_posts column exists.');
            hl_log($conn, $group_id, $current_user, 'update_probation', (string)$n);
            hl_json(['success' => true]);
        }

        // Owner + admins: give a member a strike
        if ($act === 'warn_issue') {
            $uid = (int)($_POST['user_id'] ?? 0);
            $reason = hl_title($_POST['reason'] ?? '', GC_BAN_NOTE_LEN);
            if ($reason === '') hl_fail('Add a short reason for the warning.');
            if (!$uid || $uid === $group_creator_id || $uid === $current_user) hl_fail('You cannot warn that person.');
            $st = $conn->prepare("SELECT u.username FROM group_members gm JOIN users u ON u.id=gm.user_id WHERE gm.group_id=? AND gm.user_id=? LIMIT 1");
            $st->bind_param("ii", $group_id, $uid); $st->execute();
            $mrow = $st->get_result()->fetch_assoc(); $st->close();
            if (!$mrow) hl_fail('That person is not a member of this group.');
            if (!$is_owner) {
                $st = $conn->prepare("SELECT 1 FROM group_admins WHERE group_id=? AND user_id=? LIMIT 1");
                $st->bind_param("ii", $group_id, $uid); $st->execute();
                $tgt_admin = $st->get_result()->num_rows > 0; $st->close();
                if ($tgt_admin) hl_fail('Admins can only warn regular members.');
            }
            $st = $conn->prepare("INSERT INTO group_warnings (group_id, user_id, reason, issued_by, seen) VALUES (?,?,?,?,0)");
            $st->bind_param("iisi", $group_id, $uid, $reason, $current_user);
            $ok = $st->execute(); $st->close();
            if (!$ok) hl_fail('Could not save the warning.');
            $st = $conn->prepare("SELECT COUNT(*) AS c FROM group_warnings WHERE group_id=? AND user_id=?");
            $st->bind_param("ii", $group_id, $uid); $st->execute();
            $cnt = (int)($st->get_result()->fetch_assoc()['c'] ?? 0); $st->close();
            $lim = (int)($group['warn_limit'] ?? 0);
            gc_log_target($conn, $group_id, $current_user, 'warn_member', $uid, $reason);
            hl_json(['success' => true, 'count' => $cnt, 'limit' => $lim, 'reached' => ($lim > 0 && $cnt >= $lim), 'name' => $mrow['username'], 'uid' => $uid]);
        }

        if ($act === 'warn_history') {
            $uid = (int)($_POST['user_id'] ?? 0);
            $st = $conn->prepare("SELECT w.id, w.reason, w.created_at, u.username AS by_name FROM group_warnings w LEFT JOIN users u ON u.id=w.issued_by WHERE w.group_id=? AND w.user_id=? ORDER BY w.id DESC");
            $st->bind_param("ii", $group_id, $uid); $st->execute();
            $res = $st->get_result(); $items = [];
            while ($r = $res->fetch_assoc()) $items[] = ['id' => (int)$r['id'], 'reason' => $r['reason'], 'by' => (string)($r['by_name'] ?? 'Unknown'), 'date' => date('j M Y', strtotime($r['created_at']))];
            $st->close();
            hl_json(['success' => true, 'items' => $items]);
        }

        if ($act === 'warn_remove') {
            $wid = (int)($_POST['warning_id'] ?? 0);
            $st = $conn->prepare("SELECT user_id FROM group_warnings WHERE id=? AND group_id=? LIMIT 1");
            $st->bind_param("ii", $wid, $group_id); $st->execute();
            $w = $st->get_result()->fetch_assoc(); $st->close();
            if (!$w) hl_fail('Warning not found.');
            $st = $conn->prepare("DELETE FROM group_warnings WHERE id=? AND group_id=?");
            $st->bind_param("ii", $wid, $group_id); $st->execute(); $st->close();
            gc_log_target($conn, $group_id, $current_user, 'remove_warning', (int)$w['user_id'], '1');
            hl_json(['success' => true]);
        }

        if ($act === 'warn_clear') {
            $uid = (int)($_POST['user_id'] ?? 0);
            if (!$uid) hl_fail('Member not found.');
            $st = $conn->prepare("DELETE FROM group_warnings WHERE group_id=? AND user_id=?");
            $st->bind_param("ii", $group_id, $uid); $st->execute();
            $n = $st->affected_rows; $st->close();
            gc_log_target($conn, $group_id, $current_user, 'remove_warning', $uid, (string)max(0, $n));
            hl_json(['success' => true]);
        }

        // Owner + admins: approve posts held back by new-member probation
        if ($act === 'post_approve') {
            $gpid = (int)($_POST['group_post_id'] ?? 0);
            $st = $conn->prepare("UPDATE group_posts SET is_pending=0 WHERE id=? AND group_id=? AND is_pending=1");
            $st->bind_param("ii", $gpid, $group_id); $st->execute();
            $n = $st->affected_rows; $st->close();
            if ($n < 1) hl_fail('That post is no longer waiting.');
            hl_log($conn, $group_id, $current_user, 'approve_post', '1');
            hl_json(['success' => true]);
        }
        if ($act === 'post_approve_all') {
            $st = $conn->prepare("UPDATE group_posts SET is_pending=0 WHERE group_id=? AND is_pending=1");
            $st->bind_param("i", $group_id); $st->execute();
            $n = $st->affected_rows; $st->close();
            hl_log($conn, $group_id, $current_user, 'approve_post', (string)max(0, $n));
            hl_json(['success' => true, 'count' => $n]);
        }

        // Owner only: group location text
        if ($act === 'location_save') {
            if (!$is_owner) hl_fail('Only the group owner can change the location.');
            $loc = hl_title($_POST['location'] ?? '', GC_LOC_LEN);
            $loc_db = ($loc !== '') ? $loc : null;
            $st = $conn->prepare("UPDATE `groups` SET location=? WHERE id=?");
            $st->bind_param("si", $loc_db, $group_id);
            $ok = $st->execute(); $st->close();
            if (!$ok) hl_fail('Could not save the location. Make sure the location column exists.');
            hl_log($conn, $group_id, $current_user, 'update_location', $loc);
            hl_json(['success' => true, 'location' => $loc]);
        }

        // Finds which @usernames / @nicknames and which group names inside a text really exist.
        if ($act === 'resolve') {
            $text = hl_text($_POST['text'] ?? '', HL_MAX_TEXT);
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
            if ($text !== '') {
                try {
                    $st = $conn->prepare("SELECT id, title FROM `groups` WHERE CHAR_LENGTH(title) >= 2 AND LOCATE(LOWER(title), LOWER(?)) > 0 ORDER BY CHAR_LENGTH(title) DESC LIMIT 20");
                    $st->bind_param("s", $text); $st->execute();
                    $res = $st->get_result();
                    while ($g = $res->fetch_assoc()) $groups[] = ['id' => (int)$g['id'], 'name' => $g['title']];
                    $st->close();
                } catch (Throwable $e) { $groups = []; }
            }
            hl_json(['success' => true, 'users' => (object)$users, 'groups' => $groups]);
        }

        $hid = (int)($_POST['highlight_id'] ?? 0);
        $H = $hid ? hl_fetch($conn, $hid, $group_id) : null;
        if (!$H) hl_fail('Highlight not found.');

        switch ($act) {

            case 'get':
                $st = $conn->prepare("SELECT id, file_path, media_type, caption, audio_path, audio_title, mute_video, created_at FROM group_highlight_media WHERE highlight_id=? ORDER BY id ASC");
                $st->bind_param("i", $hid); $st->execute();
                $res = $st->get_result(); $media = [];
                while ($m = $res->fetch_assoc()) {
                    $row = ['id' => (int)$m['id'], 'path' => $m['file_path'], 'type' => $m['media_type'], 'caption' => (string)($m['caption'] ?? ''),
                        'audio' => $m['audio_path'], 'audio_title' => $m['audio_title'], 'mute' => (int)$m['mute_video'],
                        'added' => date('j M Y', strtotime($m['created_at']))];
                    if ($m['media_type'] === 'poll') $row['poll'] = hl_poll_load($conn, (int)$m['id']);
                    $media[] = $row;
                }
                $st->close();
                hl_json(['success' => true, 'h' => ['id' => (int)$H['id'], 'title' => $H['title'], 'cover' => $H['cover_path'],
                    'song' => $H['song_path'], 'song_title' => $H['song_title'], 'mode' => ($H['song_mode'] ?? 'whole'), 'media' => $media]]);

            case 'set_mode':
                $mode = (($_POST['mode'] ?? '') === 'each') ? 'each' : 'whole';
                $st = $conn->prepare("UPDATE group_highlights SET song_mode=? WHERE id=?");
                $st->bind_param("si", $mode, $hid); $st->execute(); $st->close();
                hl_json(['success' => true]);

            case 'rename':
                $title = hl_title($_POST['title'] ?? '', 30);
                if ($title === '') hl_fail('Give your highlight a name.');
                $st = $conn->prepare("UPDATE group_highlights SET title=? WHERE id=?");
                $st->bind_param("si", $title, $hid); $st->execute(); $st->close();
                hl_json(['success' => true]);

            case 'add_media':
            case 'add_text':
            case 'add_poll':
                $st = $conn->prepare("SELECT COUNT(*) AS c FROM group_highlight_media WHERE highlight_id=?");
                $st->bind_param("i", $hid); $st->execute();
                $have = (int)($st->get_result()->fetch_assoc()['c'] ?? 0); $st->close();
                if ($have >= HL_MAX_MEDIA) hl_fail('A highlight can hold up to ' . HL_MAX_MEDIA . ' media.');

                if ($act === 'add_media') {
                    $r = hl_save($_FILES['media'] ?? null, hl_types('media'), ['image' => 10 * $MB, 'video' => 50 * $MB], 'gh', $group_id);
                    if (!$r['ok']) hl_fail($r['msg']);
                    $cap = hl_text($_POST['caption'] ?? '', HL_MAX_TEXT);
                    $st = $conn->prepare("INSERT INTO group_highlight_media (highlight_id, group_id, added_by, file_path, media_type, caption) VALUES (?,?,?,?,?,?)");
                    $st->bind_param("iiisss", $hid, $group_id, $current_user, $r['path'], $r['type'], $cap);
                    $ok = $st->execute(); $new_mid = $conn->insert_id; $st->close();
                    if (!$ok) { hl_unlink($r['path']); hl_fail('Could not save the file.'); }
                    hl_json(['success' => true, 'id' => $new_mid]);
                }
                if ($act === 'add_text') {
                    $txt = hl_text($_POST['text'] ?? '', HL_MAX_TEXT);
                    if ($txt === '') hl_fail('Write some text first.');
                    $st = $conn->prepare("INSERT INTO group_highlight_media (highlight_id, group_id, added_by, file_path, media_type, caption) VALUES (?,?,?,'','text',?)");
                    $st->bind_param("iiis", $hid, $group_id, $current_user, $txt);
                    $ok = $st->execute(); $new_mid = $conn->insert_id; $st->close();
                    if (!$ok) hl_fail('Could not save the text.');
                    hl_json(['success' => true, 'id' => $new_mid]);
                }
                // add_poll: a poll slide members can vote on
                $P = hl_poll_parse($_POST['poll'] ?? '');
                $st = $conn->prepare("INSERT INTO group_highlight_media (highlight_id, group_id, added_by, file_path, media_type, caption) VALUES (?,?,?,'','poll','')");
                $st->bind_param("iii", $hid, $group_id, $current_user);
                $ok = $st->execute(); $new_mid = $conn->insert_id; $st->close();
                if (!$ok) hl_fail('Could not save the poll.');
                $closes = $P['duration'] > 0 ? date('Y-m-d H:i:s', time() + $P['duration'] * 3600) : null;
                $st = $conn->prepare("INSERT INTO group_highlight_polls (media_id, highlight_id, group_id, question, multi, results_mode, closes_at) VALUES (?,?,?,?,?,?,?)");
                $st->bind_param("iiisiss", $new_mid, $hid, $group_id, $P['q'], $P['multi'], $P['results'], $closes);
                $ok = $st->execute(); $pid = $conn->insert_id; $st->close();
                if (!$ok) { $conn->query("DELETE FROM group_highlight_media WHERE id=" . (int)$new_mid); hl_fail('Could not save the poll.'); }
                $st = $conn->prepare("INSERT INTO group_highlight_poll_options (poll_id, label, sort_order) VALUES (?,?,?)");
                foreach ($P['options'] as $i => $label) { $st->bind_param("isi", $pid, $label, $i); $st->execute(); }
                $st->close();
                hl_log($conn, $group_id, $current_user, 'add_poll', $P['q']);
                hl_json(['success' => true, 'id' => $new_mid]);

            case 'set_poll':
                $mid = (int)($_POST['media_id'] ?? 0);
                $M = $mid ? hl_media($conn, $mid, $hid) : null;
                if (!$M || $M['media_type'] !== 'poll') hl_fail('Poll not found.');
                $P = hl_poll_parse($_POST['poll'] ?? '');
                $cur = hl_poll_load($conn, $mid);
                if (!$cur) hl_fail('Poll not found.');
                $pid = (int)$cur['id'];
                $locked = $cur['total'] > 0;
                if ($locked && count($P['options']) !== count($cur['options'])) hl_fail('This poll already has votes, so options cannot be added or removed.');
                $multi = $locked ? (int)$cur['multi'] : $P['multi'];
                if ($P['duration'] === -1) {
                    $st = $conn->prepare("UPDATE group_highlight_polls SET question=?, multi=?, results_mode=? WHERE id=?");
                    $st->bind_param("sisi", $P['q'], $multi, $P['results'], $pid);
                } else {
                    $closes = $P['duration'] > 0 ? date('Y-m-d H:i:s', time() + $P['duration'] * 3600) : null;
                    $st = $conn->prepare("UPDATE group_highlight_polls SET question=?, multi=?, results_mode=?, closes_at=? WHERE id=?");
                    $st->bind_param("sissi", $P['q'], $multi, $P['results'], $closes, $pid);
                }
                $st->execute(); $st->close();
                if ($locked) {
                    $st = $conn->prepare("UPDATE group_highlight_poll_options SET label=? WHERE id=? AND poll_id=?");
                    foreach ($P['options'] as $i => $label) { $oid = (int)$cur['options'][$i]['id']; $st->bind_param("sii", $label, $oid, $pid); $st->execute(); }
                    $st->close();
                } else {
                    $conn->query("DELETE FROM group_highlight_poll_options WHERE poll_id=" . $pid);
                    $st = $conn->prepare("INSERT INTO group_highlight_poll_options (poll_id, label, sort_order) VALUES (?,?,?)");
                    foreach ($P['options'] as $i => $label) { $st->bind_param("isi", $pid, $label, $i); $st->execute(); }
                    $st->close();
                }
                hl_json(['success' => true]);

            case 'set_caption':
                $mid = (int)($_POST['media_id'] ?? 0);
                $M = $mid ? hl_media($conn, $mid, $hid) : null;
                if (!$M || $M['media_type'] === 'poll') hl_fail('Media not found.');
                $txt = hl_text($_POST['text'] ?? '', HL_MAX_TEXT);
                if ($M['media_type'] === 'text' && $txt === '') hl_fail('A text media needs some text.');
                $st = $conn->prepare("UPDATE group_highlight_media SET caption=? WHERE id=?");
                $st->bind_param("si", $txt, $mid); $st->execute(); $st->close();
                hl_json(['success' => true]);

            case 'del_media':
                $mid = (int)($_POST['media_id'] ?? 0);
                $M = $mid ? hl_media($conn, $mid, $hid) : null;
                if (!$M) hl_fail('Media not found.');
                $st = $conn->prepare("SELECT COUNT(*) AS c FROM group_highlight_media WHERE highlight_id=?");
                $st->bind_param("i", $hid); $st->execute();
                $have = (int)($st->get_result()->fetch_assoc()['c'] ?? 0); $st->close();
                if ($have <= 1) hl_fail('A highlight needs at least one media. Delete the highlight instead.');
                if ($M['media_type'] === 'poll') hl_poll_delete($conn, $mid);
                hl_unlink($M['file_path']); hl_unlink($M['audio_path']);
                $st = $conn->prepare("DELETE FROM group_highlight_media WHERE id=? AND highlight_id=?");
                $st->bind_param("ii", $mid, $hid); $st->execute(); $st->close();
                hl_json(['success' => true]);

            case 'set_cover':
                $r = hl_save($_FILES['cover'] ?? null, hl_types('image'), 10 * $MB, 'gcover', $group_id);
                if (!$r['ok']) hl_fail($r['msg']);
                hl_unlink($H['cover_path']);
                $st = $conn->prepare("UPDATE group_highlights SET cover_path=? WHERE id=?");
                $st->bind_param("si", $r['path'], $hid); $st->execute(); $st->close();
                hl_json(['success' => true]);

            case 'rm_cover':
                hl_unlink($H['cover_path']);
                $st = $conn->prepare("UPDATE group_highlights SET cover_path=NULL WHERE id=?");
                $st->bind_param("i", $hid); $st->execute(); $st->close();
                hl_json(['success' => true]);

            case 'set_song':
                $r = hl_save($_FILES['song'] ?? null, hl_types('audio'), 7 * $MB, 'gsong', $group_id);
                if (!$r['ok']) hl_fail($r['msg']);
                hl_unlink($H['song_path']);
                $t = hl_title($_POST['title'] ?? '', 60);
                $st = $conn->prepare("UPDATE group_highlights SET song_path=?, song_title=? WHERE id=?");
                $st->bind_param("ssi", $r['path'], $t, $hid); $st->execute(); $st->close();
                hl_json(['success' => true]);

            case 'rm_song':
                hl_unlink($H['song_path']);
                $st = $conn->prepare("UPDATE group_highlights SET song_path=NULL, song_title=NULL WHERE id=?");
                $st->bind_param("i", $hid); $st->execute(); $st->close();
                hl_json(['success' => true]);

            case 'media_song':
                $mid = (int)($_POST['media_id'] ?? 0);
                $M = $mid ? hl_media($conn, $mid, $hid) : null;
                if (!$M) hl_fail('Media not found.');
                $r = hl_save($_FILES['song'] ?? null, hl_types('audio'), 7 * $MB, 'gmsong', $group_id);
                if (!$r['ok']) hl_fail($r['msg']);
                hl_unlink($M['audio_path']);
                $t = hl_title($_POST['title'] ?? '', 60);
                $st = $conn->prepare("UPDATE group_highlight_media SET audio_path=?, audio_title=? WHERE id=?");
                $st->bind_param("ssi", $r['path'], $t, $mid); $st->execute(); $st->close();
                hl_json(['success' => true]);

            case 'media_song_rm':
                $mid = (int)($_POST['media_id'] ?? 0);
                $M = $mid ? hl_media($conn, $mid, $hid) : null;
                if (!$M) hl_fail('Media not found.');
                hl_unlink($M['audio_path']);
                $st = $conn->prepare("UPDATE group_highlight_media SET audio_path=NULL, audio_title=NULL WHERE id=?");
                $st->bind_param("i", $mid); $st->execute(); $st->close();
                hl_json(['success' => true]);

            case 'media_mute':
                $mid = (int)($_POST['media_id'] ?? 0);
                $M = $mid ? hl_media($conn, $mid, $hid) : null;
                if (!$M) hl_fail('Media not found.');
                $mute = !empty($_POST['mute']) ? 1 : 0;
                $st = $conn->prepare("UPDATE group_highlight_media SET mute_video=? WHERE id=?");
                $st->bind_param("ii", $mute, $mid); $st->execute(); $st->close();
                hl_json(['success' => true]);

            case 'delete':
                $st = $conn->prepare("SELECT id, file_path, audio_path, media_type FROM group_highlight_media WHERE highlight_id=?");
                $st->bind_param("i", $hid); $st->execute();
                $res = $st->get_result();
                while ($r = $res->fetch_assoc()) {
                    if ($r['media_type'] === 'poll') hl_poll_delete($conn, (int)$r['id']);
                    hl_unlink($r['file_path']); hl_unlink($r['audio_path']);
                }
                $st->close();
                hl_unlink($H['cover_path']); hl_unlink($H['song_path']);
                $st = $conn->prepare("DELETE FROM group_highlight_media WHERE highlight_id=?");
                $st->bind_param("i", $hid); $st->execute(); $st->close();
                $st = $conn->prepare("DELETE FROM group_highlights WHERE id=? AND group_id=?");
                $st->bind_param("ii", $hid, $group_id); $st->execute(); $st->close();
                hl_log($conn, $group_id, $current_user, 'delete_highlight', $H['title']);
                hl_json(['success' => true]);
        }
        hl_fail('Unknown action.');
    } catch (Throwable $e) { hl_fail('Something went wrong.'); }
}

$is_private_group = ($group['privacy'] ?? 'public') === 'private';
$is_adult_group    = !empty($group['is_adult']);
$is_paused         = !empty($group['is_paused']);

/* ── Group location ── */
$group_location = trim((string)($group['location'] ?? ''));
$location_summary = $group_location !== '' ? $group_location : 'No location set';

/* ── Moderation tools ── */
$am_words = array_values(array_filter(preg_split('/\r\n|\r|\n/', (string)($group['automod_words'] ?? '')), function ($w) { return $w !== ''; }));
$am_mode  = (($group['automod_action'] ?? 'block') === 'report') ? 'report' : 'block';
$am_whole = (!isset($group['automod_whole']) || !empty($group['automod_whole'])) ? 1 : 0;
$slow_secs = (int)($group['slow_mode_seconds'] ?? 0);
$warn_limit = (int)($group['warn_limit'] ?? 0);
$prob_posts = (int)($group['probation_posts'] ?? 0);

$warn_rows = []; $warn_counts = []; $warn_total = 0;
try {
    $res = $conn->query("SELECT w.user_id, COUNT(*) AS c, MAX(w.created_at) AS last_at, u.username, u.profile_pic,
        (SELECT w2.reason FROM group_warnings w2 WHERE w2.group_id=$group_id AND w2.user_id=w.user_id ORDER BY w2.id DESC LIMIT 1) AS last_reason
        FROM group_warnings w JOIN users u ON u.id=w.user_id WHERE w.group_id=$group_id
        GROUP BY w.user_id, u.username, u.profile_pic ORDER BY c DESC, last_at DESC");
    if ($res) while ($r = $res->fetch_assoc()) { $warn_rows[] = $r; $warn_counts[(int)$r['user_id']] = (int)$r['c']; $warn_total += (int)$r['c']; }
} catch (Throwable $e) { /* warnings table not created yet */ }

$pending_posts = [];
try {
    $res = $conn->query("SELECT gp.id AS group_post_id, m.id AS meme_id, m.file_name, m.caption, u.username AS poster_username
        FROM group_posts gp JOIN memes m ON m.id=gp.meme_id JOIN users u ON u.id=gp.posted_by
        WHERE gp.group_id=$group_id AND gp.is_pending=1 ORDER BY gp.id ASC");
    if ($res) while ($r = $res->fetch_assoc()) $pending_posts[] = $r;
} catch (Throwable $e) { /* is_pending column not created yet */ }
$pending_post_count = count($pending_posts);

/* ── Category (with fallback for pods created before this feature) ── */
$pod_category_options = [
    'Memes & Humor','Internet Culture','Gaming','Technology','Science','Space','Education & Careers','Dezzme Official',
    'History','Books & Writing','Art & Design','Photography','Movies & TV','Anime & Manga','Comics & Cartoons','Music',
    'Podcasts','Entertainment','Fandom','Pop Culture','News','Politics','Business','Finance','Lifestyle','Fashion',
    'Beauty & Skincare','Health & Fitness','Mental Wellness','Relationships & Family','Travel','Food & Drinks','Sports',
    'Esports','Pets','Animals','Nature & Wildlife','Nature','Home & DIY','Architecture','Collectibles','Automotive',
    'Luxury','Shopping','Language Learning','Culture','Religion & Spirituality','Philosophy','LGBTQ+',
    'Identity & Communities','Creators','Streaming','Marketplace','Support','Discussions & Q&A','Events & Challenges',
    'Creative Edits','Media Sharing','Wallpapers','Aesthetics','Brainrot','Shitposting','Trending',
    'Throwbacks & Nostalgia','Other'
];
$pod_category = !empty($group['category']) ? $group['category'] : '';
$pod_category_display = $pod_category !== '' ? $pod_category : 'Not set';

/* ── Counts / lists ── */
$member_count = (int)$conn->query("SELECT COUNT(*) c FROM group_members WHERE group_id=$group_id")->fetch_assoc()['c'];

$admins = [];
$res = $conn->query("SELECT u.id,u.username,u.profile_pic FROM group_admins ga JOIN users u ON u.id=ga.user_id WHERE ga.group_id=$group_id");
while ($r = $res->fetch_assoc()) $admins[$r['id']] = $r;

$moderators = [];
try {
    $conn->query("CREATE TABLE IF NOT EXISTS group_moderators (id INT AUTO_INCREMENT PRIMARY KEY, group_id INT NOT NULL, user_id INT NOT NULL, added_by INT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_group_mod (group_id, user_id))");
    $res = $conn->query("SELECT u.id,u.username,u.profile_pic FROM group_moderators gmo JOIN users u ON u.id=gmo.user_id JOIN group_members gm ON gm.group_id=gmo.group_id AND gm.user_id=gmo.user_id WHERE gmo.group_id=$group_id");
    while ($res && ($r = $res->fetch_assoc())) $moderators[$r['id']] = $r;
} catch (Throwable $e) { /* moderators unavailable */ }

$group_hashtags = [];
$res = $conn->query("SELECT id,tag FROM group_hashtags WHERE group_id=$group_id ORDER BY created_at ASC");
while ($r = $res->fetch_assoc()) $group_hashtags[] = $r;

$pending_join_requests = [];
if ($is_private_group) {
    $res = $conn->query("SELECT jr.user_id, jr.requested_at, u.username, u.profile_pic FROM group_join_requests jr JOIN users u ON u.id=jr.user_id WHERE jr.group_id=$group_id AND jr.status='pending' ORDER BY jr.requested_at ASC");
    while ($r = $res->fetch_assoc()) $pending_join_requests[] = $r;
}
$pending_request_count = count($pending_join_requests);
$request_answers = $is_private_group ? jq_answers_for($conn, $group_id) : [];
$join_questions = jq_fetch($conn, $group_id);
$join_q_count = count($join_questions);
$join_q_js = array_map(function ($q) { return ['q' => $q['question'], 'req' => (int)$q['is_required']]; }, $join_questions);

$reported_posts = [];
$res = $conn->query("
    SELECT gpr.id AS report_id, gpr.reason, gpr.description, gpr.escalated,
           gp.id AS group_post_id, m.id AS meme_id, m.file_name, m.caption, m.type,
           u.username AS poster_username
    FROM group_post_reports gpr
    JOIN group_posts gp ON gp.id = gpr.group_post_id
    JOIN memes m ON m.id = gp.meme_id
    JOIN users u ON u.id = gp.posted_by
    WHERE gpr.group_id=$group_id AND gpr.status='pending'
    ORDER BY gpr.created_at ASC
");
while ($r = $res->fetch_assoc()) $reported_posts[] = $r;
$reported_post_count = count($reported_posts);

$panel_members_sql = "SELECT u.id,u.username,u.profile_pic FROM group_members gm JOIN users u ON u.id=gm.user_id WHERE gm.group_id=$group_id AND u.id!=$current_user AND u.id!=$group_creator_id";
$panel_members = $conn->query($panel_members_sql);

$owner_bans = [];
if ($is_owner) {
    $res = $conn->query("SELECT u.id,u.username,u.profile_pic FROM blocks b JOIN users u ON u.id=b.blocked_id WHERE b.blocker_id=$current_user");
    while ($r = $res->fetch_assoc()) $owner_bans[$r['id']] = $r;
}
$group_ban_list = [];
$res = $conn->query("SELECT u.id,u.username,u.profile_pic FROM group_bans gb JOIN users u ON u.id=gb.banned_id WHERE gb.group_id=$group_id");
while ($r = $res->fetch_assoc()) $group_ban_list[$r['id']] = $r;
$visible_bans = $is_owner ? ($owner_bans + $group_ban_list) : $group_ban_list;
$banned_count = count($visible_bans);
$ban_notes = [];
try {
    $res = $conn->query("SELECT user_id, reason FROM group_ban_notes WHERE group_id=$group_id");
    if ($res) while ($r = $res->fetch_assoc()) $ban_notes[(int)$r['user_id']] = $r['reason'];
} catch (Throwable $e) { /* table not created yet */ }

/* ── Group highlights list (cover = custom cover, else first media) + poll stats ── */
$group_highlights = []; $gh_poll_total = 0; $gh_vote_total = 0;
try {
    $stmt = $conn->prepare("SELECT h.id, h.title, h.cover_path, h.song_path,
        (SELECT COUNT(*) FROM group_highlight_media m WHERE m.highlight_id = h.id) AS media_count,
        (SELECT m2.file_path FROM group_highlight_media m2 WHERE m2.highlight_id = h.id ORDER BY m2.id ASC LIMIT 1) AS first_path,
        (SELECT m3.media_type FROM group_highlight_media m3 WHERE m3.highlight_id = h.id ORDER BY m3.id ASC LIMIT 1) AS first_type,
        (SELECT m4.caption FROM group_highlight_media m4 WHERE m4.highlight_id = h.id ORDER BY m4.id ASC LIMIT 1) AS first_caption,
        (SELECT gp.question FROM group_highlight_polls gp WHERE gp.highlight_id = h.id ORDER BY gp.media_id ASC LIMIT 1) AS first_poll_q,
        (SELECT COUNT(*) FROM group_highlight_media mp WHERE mp.highlight_id = h.id AND mp.media_type = 'poll') AS poll_count
        FROM group_highlights h WHERE h.group_id = ? HAVING media_count > 0 ORDER BY h.created_at DESC, h.id DESC");
    $stmt->bind_param("i", $group_id); $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $group_highlights[] = $row;
    $stmt->close();
    $q = $conn->query("SELECT COUNT(*) c FROM group_highlight_polls WHERE group_id=$group_id");
    if ($q) $gh_poll_total = (int)($q->fetch_assoc()['c'] ?? 0);
    $q = $conn->query("SELECT COUNT(*) c FROM group_highlight_poll_votes v JOIN group_highlight_polls p ON p.id=v.poll_id WHERE p.group_id=$group_id");
    if ($q) $gh_vote_total = (int)($q->fetch_assoc()['c'] ?? 0);
} catch (Throwable $e) { /* highlight tables do not exist yet */ }
$gh_count = count($group_highlights);

$img_src = empty($group['group_pic']) ? 'uploads/default_group.png' : (strpos($group['group_pic'],'uploads/')===0 ? $group['group_pic'] : 'uploads/'.$group['group_pic']);
$banner_src = !empty($group['banner_pic']) ? (strpos($group['banner_pic'],'uploads/')===0 ? $group['banner_pic'] : 'uploads/'.$group['banner_pic']) : $img_src;

$desc_preview = trim($group['description'] ?? '');
$desc_preview = $desc_preview !== '' ? (mb_strlen($desc_preview) > 42 ? mb_substr($desc_preview,0,42).'…' : $desc_preview) : 'No description set';

/* ── Group rules: each line of custom_guidelines is one rule (shown as a bar) ── */
$rules_list = [];
foreach (preg_split('/\r\n|\r|\n/', (string)($group['custom_guidelines'] ?? '')) as $ln) {
    $ln = trim(preg_replace('/^\s*(?:[-*•·]+|\d+[.)])\s*/u', '', $ln));
    if ($ln !== '') $rules_list[] = $ln;
}
$rules_list = array_slice($rules_list, 0, GC_MAX_RULES);
$rules_count = count($rules_list);
$rules_preview = $rules_count ? ($rules_count . ' rule' . ($rules_count === 1 ? '' : 's') . ' set') : 'No rules set yet';

/* ── Owner profile (for the Pod Roles modal) ── */
$owner_profile_pic = !empty($group['creator_pic']) ? $group['creator_pic'] : 'mee.jpg';
$owner_username = $group['creator_name'];

/* ══════════════════════════════════════════════════════════════════
   ACTIVITY LOG
   ══════════════════════════════════════════════════════════════════ */
$activity_log = [];
$log_res = $conn->query("
    SELECT gal.*, u.username AS actor_username, u.profile_pic AS actor_pic,
           tu.username AS target_username
    FROM group_activity_log gal
    JOIN users u ON u.id = gal.actor_id
    LEFT JOIN users tu ON tu.id = gal.target_user_id
    WHERE gal.group_id = $group_id
    ORDER BY gal.created_at DESC
    LIMIT 150
");
while ($r = $log_res->fetch_assoc()) $activity_log[] = $r;

/* ── extra activity: what members did (joins, posts, requests, reports), merged into the log ── */
function gc_qrows($conn, $sql) {
    try { $r = $conn->query($sql); if (!($r instanceof mysqli_result)) return []; $o = []; while ($x = $r->fetch_assoc()) $o[] = $x; return $o; }
    catch (Throwable $e) { return []; }
}
function gc_col($conn, $table, array $cands) {
    foreach ($cands as $c) {
        try { $r = $conn->query("SHOW COLUMNS FROM `$table` LIKE '" . $conn->real_escape_string($c) . "'"); if ($r && $r->num_rows) return $c; } catch (Throwable $e) {}
    }
    return null;
}
$gc_join_col = gc_col($conn, 'group_members', ['joined_at', 'created_at', 'join_date', 'date_joined']);
$gc_post_col = gc_col($conn, 'group_posts', ['created_at', 'posted_at']);
$gc_derived = [];
if ($gc_join_col) {
    foreach (gc_qrows($conn, "SELECT u.username AS actor_username, u.profile_pic AS actor_pic, gm.`$gc_join_col` AS created_at
            FROM group_members gm JOIN users u ON u.id = gm.user_id
            WHERE gm.group_id = $group_id AND gm.user_id <> " . (int)$group_creator_id . " AND gm.`$gc_join_col` IS NOT NULL
            ORDER BY gm.`$gc_join_col` DESC LIMIT 40") as $r) { $r['action_type'] = 'ev_join'; $r['target_username'] = null; $r['details'] = ''; $gc_derived[] = $r; }
}
if ($gc_post_col) {
    foreach (gc_qrows($conn, "SELECT u.username AS actor_username, u.profile_pic AS actor_pic, gp.`$gc_post_col` AS created_at, gp.is_pending
            FROM group_posts gp JOIN users u ON u.id = gp.posted_by
            WHERE gp.group_id = $group_id ORDER BY gp.`$gc_post_col` DESC LIMIT 40") as $r) {
        $r['action_type'] = !empty($r['is_pending']) ? 'ev_post_pending' : 'ev_post'; $r['target_username'] = null; $r['details'] = ''; $gc_derived[] = $r;
    }
}
foreach (gc_qrows($conn, "SELECT u.username AS actor_username, u.profile_pic AS actor_pic, jr.requested_at AS created_at
        FROM group_join_requests jr JOIN users u ON u.id = jr.user_id
        WHERE jr.group_id = $group_id AND jr.status = 'pending' ORDER BY jr.requested_at DESC LIMIT 30") as $r) {
    $r['action_type'] = 'ev_request'; $r['target_username'] = null; $r['details'] = ''; $gc_derived[] = $r;
}
foreach (gc_qrows($conn, "SELECT gpr.created_at AS created_at, u.username AS actor_username, u.profile_pic AS actor_pic
        FROM group_post_reports gpr JOIN group_posts gp ON gp.id = gpr.group_post_id JOIN users u ON u.id = gp.posted_by
        WHERE gpr.group_id = $group_id ORDER BY gpr.created_at DESC LIMIT 30") as $r) {
    $r['action_type'] = 'ev_report'; $r['target_username'] = null; $r['details'] = ''; $gc_derived[] = $r;   /* actor = the post's author */
}
$activity_log = array_merge($activity_log, $gc_derived);
usort($activity_log, function ($a, $b) { return strtotime($b['created_at']) <=> strtotime($a['created_at']); });
$activity_log = array_slice($activity_log, 0, 220);
function gc_activity_cat($t) {
    if (in_array($t, ['ev_join', 'ev_request', 'approve_request', 'deny_request', 'kick_member', 'ban_member', 'unban_member', 'appoint_admin', 'remove_admin', 'appoint_mod', 'remove_mod', 'transfer_owner', 'warn_member', 'remove_warning', 'mute_member', 'unmute_member'], true)) return 'members';
    if (in_array($t, ['ev_post', 'ev_post_pending', 'ev_report', 'approve_post', 'delete_post', 'dismiss_report', 'escalate_report', 'create_highlight', 'delete_highlight', 'add_poll'], true)) return 'posts';
    return 'settings';
}

/* ── Insights: last 7 days vs the 7 before ── */
$ins_days = []; for ($i = 6; $i >= 0; $i--) $ins_days[date('Y-m-d', strtotime("-$i day"))] = ['v' => 0, 'p' => 0, 'm' => 0];
$ins_tot = ['v' => [0, 0], 'p' => [0, 0], 'm' => [0, 0]];   /* [this week, previous week] */
foreach (gc_qrows($conn, "SELECT DATE(viewed_at) d, COUNT(DISTINCT viewer_id) c FROM group_views WHERE group_id = $group_id AND viewed_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) GROUP BY DATE(viewed_at)") as $r) {
    if (isset($ins_days[$r['d']])) $ins_days[$r['d']]['v'] = (int)$r['c'];
    $k = isset($ins_days[$r['d']]) ? 0 : 1; $ins_tot['v'][$k] += (int)$r['c'];
}
if ($gc_post_col) foreach (gc_qrows($conn, "SELECT DATE(`$gc_post_col`) d, COUNT(*) c FROM group_posts WHERE group_id = $group_id AND is_pending = 0 AND `$gc_post_col` >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) GROUP BY DATE(`$gc_post_col`)") as $r) {
    if (isset($ins_days[$r['d']])) $ins_days[$r['d']]['p'] = (int)$r['c'];
    $k = isset($ins_days[$r['d']]) ? 0 : 1; $ins_tot['p'][$k] += (int)$r['c'];
}
if ($gc_join_col) foreach (gc_qrows($conn, "SELECT DATE(`$gc_join_col`) d, COUNT(*) c FROM group_members WHERE group_id = $group_id AND `$gc_join_col` >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) GROUP BY DATE(`$gc_join_col`)") as $r) {
    if (isset($ins_days[$r['d']])) $ins_days[$r['d']]['m'] = (int)$r['c'];
    $k = isset($ins_days[$r['d']]) ? 0 : 1; $ins_tot['m'][$k] += (int)$r['c'];
}
$ins_active = 0; $ins_top_poster = null; $ins_top_post = null; $ins_likes_total = 0; $ins_posts_total = 0;
if ($gc_post_col) {
    $x = gc_qrows($conn, "SELECT COUNT(DISTINCT posted_by) c FROM group_posts WHERE group_id = $group_id AND is_pending = 0 AND `$gc_post_col` >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $ins_active = (int)($x[0]['c'] ?? 0);
}
$x = gc_qrows($conn, "SELECT u.id, u.username, COUNT(*) c FROM group_posts gp JOIN users u ON u.id = gp.posted_by WHERE gp.group_id = $group_id AND gp.is_pending = 0 GROUP BY gp.posted_by ORDER BY c DESC LIMIT 1");
$ins_top_poster = $x[0] ?? null;
$x = gc_qrows($conn, "SELECT COUNT(*) c FROM group_posts WHERE group_id = $group_id AND is_pending = 0"); $ins_posts_total = (int)($x[0]['c'] ?? 0);
$x = gc_qrows($conn, "SELECT COUNT(*) c FROM likes l JOIN group_posts gp ON gp.meme_id = l.meme_id WHERE gp.group_id = $group_id"); $ins_likes_total = (int)($x[0]['c'] ?? 0);
$x = gc_qrows($conn, "SELECT gp.meme_id, m.caption, u.username, COUNT(l.meme_id) c FROM group_posts gp JOIN memes m ON m.id = gp.meme_id JOIN users u ON u.id = gp.posted_by LEFT JOIN likes l ON l.meme_id = gp.meme_id WHERE gp.group_id = $group_id AND gp.is_pending = 0 GROUP BY gp.id ORDER BY c DESC, gp.id DESC LIMIT 1");
if (!empty($x[0]) && (int)$x[0]['c'] > 0) $ins_top_post = $x[0];
function gc_trend($cur, $prev) {
    if ($cur === 0 && $prev === 0) return ['—', ''];
    if ($prev === 0) return ['New', 'up'];
    $p = (int)round(($cur - $prev) / $prev * 100);
    return [($p >= 0 ? '▲ ' : '▼ ') . abs($p) . '%', $p >= 0 ? 'up' : 'down'];
}
function gc_bars($days, $key) {
    $max = max(1, max(array_map(function ($d) use ($key) { return $d[$key]; }, $days)));
    $o = '<div class="ins-bars">';
    foreach ($days as $date => $d) {
        $h = $d[$key] > 0 ? max(8, (int)round($d[$key] / $max * 100)) : 4;
        $o .= '<div class="ins-bar" title="' . htmlspecialchars(date('D j M', strtotime($date))) . ': ' . (int)$d[$key] . '"><span class="' . ($d[$key] > 0 ? 'on' : '') . '" style="height:' . $h . '%"></span><i>' . substr(date('D', strtotime($date)), 0, 1) . '</i></div>';
    }
    return $o . '</div>';
}

function gc_activity_render($row) {
    $actor  = htmlspecialchars($row['actor_username']);
    $target = $row['target_username'] ? htmlspecialchars($row['target_username']) : null;
    $d      = htmlspecialchars($row['details'] ?? '');

    switch ($row['action_type']) {
        case 'update_location':   return ["<strong>$actor</strong> updated the group location" . ($d !== '' ? " ($d)" : ''), 'ri-map-pin-line'];
        case 'mute_member':       return ["<strong>$actor</strong> muted <strong>$target</strong>" . ($d !== '' ? " for $d" : ''), 'ri-volume-mute-line'];
        case 'unmute_member':     return ["<strong>$actor</strong> unmuted <strong>$target</strong>", 'ri-volume-up-line'];
        case 'escalate_report':   return ["<strong>$actor</strong> escalated a reported post to the admins", 'ri-flag-line'];
        case 'warn_member':       return ["<strong>$actor</strong> warned <strong>$target</strong>" . ($d !== '' ? " (\"$d\")" : ''), 'ri-alert-line'];
        case 'remove_warning':    return ["<strong>$actor</strong> removed " . ((int)$d > 1 ? (int)$d . ' warnings' : 'a warning') . " from <strong>$target</strong>", 'ri-shield-check-line'];
        case 'update_warnlimit':  return ["<strong>$actor</strong> " . ((int)$d > 0 ? 'set the strike limit to ' . (int)$d : 'turned the strike limit off'), 'ri-alert-line'];
        case 'update_probation':  return ["<strong>$actor</strong> " . ((int)$d > 0 ? 'now holds the first ' . (int)$d . ' post' . ((int)$d === 1 ? '' : 's') . ' of new members for approval' : 'turned off new member probation'), 'ri-user-search-line'];
        case 'approve_post':      return ["<strong>$actor</strong> approved " . ((int)$d > 1 ? (int)$d . ' pending posts' : 'a pending post'), 'ri-checkbox-circle-line'];
        case 'update_automod':    return ["<strong>$actor</strong> updated the auto-moderation word list" . ($d !== '' ? " ($d words)" : ''), 'ri-spam-2-line'];
        case 'update_slowmode':   return ["<strong>$actor</strong> " . ((int)$d > 0 ? 'set slow mode to one post every ' . gc_dur_label((int)$d) : 'turned slow mode off'), 'ri-timer-line'];
        case 'transfer_owner':    return ["<strong>$actor</strong> transferred ownership to <strong>$target</strong>", 'ri-user-shared-line'];
        case 'kick_member':       return ["<strong>$actor</strong> kicked <strong>$target</strong> from the group", 'ri-logout-box-line'];
        case 'ban_member':        return ["<strong>$actor</strong> banned <strong>$target</strong>", 'ri-user-forbid-line'];
        case 'unban_member':      return ["<strong>$actor</strong> unbanned <strong>$target</strong>", 'ri-user-follow-line'];
        case 'appoint_admin':     return ["<strong>$actor</strong> appointed <strong>$target</strong> as admin", 'ri-shield-star-line'];
        case 'remove_admin':      return ["<strong>$actor</strong> removed <strong>$target</strong> as admin", 'ri-shield-line'];
        case 'appoint_mod':       return ["<strong>$actor</strong> appointed <strong>$target</strong> as moderator", 'ri-hammer-line'];
        case 'remove_mod':        return ["<strong>$actor</strong> removed <strong>$target</strong> as moderator", 'ri-hammer-line'];
        case 'approve_request':   return ["<strong>$actor</strong> approved <strong>$target</strong>'s join request", 'ri-user-add-line'];
        case 'deny_request':      return ["<strong>$actor</strong> denied <strong>$target</strong>'s join request", 'ri-close-circle-line'];
        case 'delete_post':       return ["<strong>$actor</strong> removed a post" . ($d ? " (\"$d\")" : ''), 'ri-delete-bin-line'];
        case 'dismiss_report':    return ["<strong>$actor</strong> dismissed a report", 'ri-flag-line'];
        case 'add_hashtag':       return ["<strong>$actor</strong> added the hashtag #$d", 'ri-hashtag'];
        case 'remove_hashtag':    return ["<strong>$actor</strong> removed the hashtag #$d", 'ri-hashtag'];
        case 'change_desc':       return ["<strong>$actor</strong> updated the group description", 'ri-align-left'];
        case 'change_guidelines': return ["<strong>$actor</strong> updated the group rules", 'ri-list-check-2'];
        case 'update_questions':  return ["<strong>$actor</strong> updated the join questions" . ($d !== '' ? " ($d set)" : ''), 'ri-questionnaire-line'];
        case 'rename_group':      return ["<strong>$actor</strong> renamed the group" . ($d ? " ($d)" : ''), 'ri-text'];
        case 'enable_adult':      return ["<strong>$actor</strong> marked this group as 18+", 'ri-error-warning-line'];
        case 'disable_adult':     return ["<strong>$actor</strong> removed the 18+ label", 'ri-error-warning-line'];
        case 'make_private':      return ["<strong>$actor</strong> made the group private", 'ri-lock-line'];
        case 'make_public':       return ["<strong>$actor</strong> made the group public", 'ri-lock-unlock-line'];
        case 'pause_group':       return ["<strong>$actor</strong> paused the group", 'ri-pause-circle-line'];
        case 'resume_group':      return ["<strong>$actor</strong> resumed the group", 'ri-play-circle-line'];
        case 'change_group_pic':  return ["<strong>$actor</strong> updated the group picture", 'ri-image-2-line'];
        case 'change_banner_pic': return ["<strong>$actor</strong> updated the banner", 'ri-landscape-line'];
        case 'create_highlight':  return ["<strong>$actor</strong> created the highlight" . ($d ? " \"$d\"" : ''), 'ri-trophy-line'];
        case 'delete_highlight':  return ["<strong>$actor</strong> deleted the highlight" . ($d ? " \"$d\"" : ''), 'ri-delete-bin-line'];
        case 'add_poll':          return ["<strong>$actor</strong> added a poll" . ($d ? " \"$d\"" : '') . " to a highlight", 'ri-bar-chart-horizontal-line'];
        case 'ev_join':           return ["<strong>$actor</strong> joined the group", 'ri-user-add-line'];
        case 'ev_post':           return ["<strong>$actor</strong> posted in the group", 'ri-image-add-line'];
        case 'ev_post_pending':   return ["<strong>$actor</strong> posted — waiting for approval", 'ri-time-line'];
        case 'ev_request':        return ["<strong>$actor</strong> asked to join the group", 'ri-user-received-line'];
        case 'ev_report':         return ["A post by <strong>$actor</strong> was reported", 'ri-flag-line'];
        default:                  return ["<strong>$actor</strong> made a change", 'ri-history-line'];
    }
}

function gc_activity_bucket($timestamp) {
    $day       = date('Y-m-d', strtotime($timestamp));
    $today     = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    if ($day === $today)     return 'Today';
    if ($day === $yesterday) return 'Yesterday';
    return 'Earlier';
}

function gc_dur_label($s) {
    $s = (int)$s;
    if ($s < 60) return $s . ' second' . ($s === 1 ? '' : 's');
    if ($s < 3600) { $m = (int)round($s / 60); return $m . ' minute' . ($m === 1 ? '' : 's'); }
    $h = (int)round($s / 3600); return $h . ' hour' . ($h === 1 ? '' : 's');
}

function gc_confirm_js($str) { return htmlspecialchars(json_encode($str), ENT_QUOTES, 'UTF-8'); }

// One tappable list row (opens a modal)
function gc_row($icon, $title, $sub, $modal, $badge = null, $danger = false, $badgeStyle = '') {
    echo '<div class="list-row' . ($danger ? ' danger' : '') . '" onclick="openModal(\'' . $modal . '\')">';
    echo '<div class="row-icon"><i class="' . $icon . '"></i></div>';
    echo '<div class="row-body"><div class="row-title">' . htmlspecialchars($title) . '</div><div class="row-sub">' . htmlspecialchars($sub) . '</div></div>';
    if ($badge !== null && $badge !== '' && $badge !== 0) echo '<span class="row-count"' . ($badgeStyle ? ' style="' . $badgeStyle . '"' : '') . '>' . htmlspecialchars((string)$badge) . '</span>';
    echo '<i class="ri-arrow-right-s-line chevron"></i></div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script>try{if(localStorage.getItem('dz_theme')==='light')document.documentElement.setAttribute('data-theme','light');}catch(e){}</script>
<title><?php echo htmlspecialchars($group['title']); ?> | <?php echo $is_owner ? 'Group Settings' : 'Admin Settings'; ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; font-family:'Poppins', sans-serif; }
button, a { cursor:pointer; outline:none; -webkit-tap-highlight-color:transparent; }
a { color:inherit; text-decoration:none; }
img, video { max-width:100%; display:block; }
[class^="ri-"], [class*=" ri-"], [class^="ri-"]::before, [class*=" ri-"]::before{
    font-family:'remixicon' !important; font-style:normal; font-weight:normal; line-height:1;
    speak:none; text-transform:none; -webkit-font-smoothing:antialiased;
}

/* ══════════ THEME — blue & black (same as Memepedia) ══════════ */
:root{
    --accent:#2a5fd8; --accent-hover:#234fb5; --accent-soft:rgba(42,95,216,.18);
    --accent-border:rgba(42,95,216,.42); --accent-fill:rgba(42,95,216,.24); --accent-glow:rgba(42,95,216,.34);
    --accent-text:#7ea6ff; --accent-focus:rgba(42,95,216,.85); --accent-ring:rgba(42,95,216,.18);
    --accent-sel:rgba(42,95,216,.4); --scroll-thumb:rgba(42,95,216,.55);
    --danger:#ff6b6b; --danger-soft:rgba(255,107,107,.14); --warn:#ff9500;
    --fg:255,255,255; --inset:rgba(0,0,0,.2); --on-accent:#fff; --solid:#070924;
    --surface:rgba(var(--fg),.05); --surface-2:rgba(var(--fg),.08);
    --border:rgba(var(--fg),.14); --line:rgba(var(--fg),.09);
    --text:#ffffff; --muted:rgba(var(--fg),.45); --muted-2:rgba(var(--fg),.3); --sub:rgba(var(--fg),.62);
    --bg:linear-gradient(-45deg,#010218,#070924,#070924);
    --max-w:580px; --topbar-h:54px; --pad:clamp(10px,3vw,16px);
    --ctl-h:clamp(32px,8vw,38px); --r:5px; --act-h:clamp(26px,7.2vw,30px); --act-r:4px;
    /* aliases used by highlights + poll components */
    --accent-dim:rgba(42,95,216,.18); --accent-light:#7ea6ff; --accent-strong:#234fb5;
    --border-focus:rgba(42,95,216,.85); --text-sub:rgba(var(--fg),.55); --text-muted:rgba(var(--fg),.32);
    --divider:rgba(var(--fg),.09); --red:#dc3838; --r-sm:6px; --tr:.2s cubic-bezier(.4,0,.2,1);
}

/* ══════════ LIGHT MODE — <html data-theme="light">, switched by the same site-wide setting (dz_theme) as every other Dezzme page ══════════ */
:root[data-theme="light"]{
    color-scheme:light;
    --fg:6,7,26; --inset:rgba(6,7,26,.045);
    --solid:#ffffff; --text:#0b0d24;
    --muted:rgba(6,7,26,.55); --muted-2:rgba(6,7,26,.38); --sub:rgba(6,7,26,.7);
    --surface:rgba(6,7,26,.045); --surface-2:rgba(6,7,26,.075);
    --border:rgba(6,7,26,.16); --line:rgba(6,7,26,.1);
    --bg:linear-gradient(-45deg,#ffffff,#f4f5fb,#f4f5fb);
    --accent-text:#1d4fc4; --accent-soft:rgba(42,95,216,.1); --accent-fill:rgba(42,95,216,.14); --accent-border:rgba(42,95,216,.38);
    --danger:#d62f2f; --danger-soft:rgba(214,47,47,.1); --warn:#c76a00;
    --accent-dim:rgba(42,95,216,.1); --accent-light:#1d4fc4; --text-sub:rgba(6,7,26,.6); --text-muted:rgba(6,7,26,.4); --divider:rgba(6,7,26,.1);
    --scroll-thumb:rgba(42,95,216,.4);
}
:root[data-theme="light"] .perm-item.yes i{ color:#15803d; }
:root[data-theme="light"] .hl-status.error{ color:#c62828; }
:root[data-theme="light"] .hl-pick.poll i{ color:#6d4be0; }
:root[data-theme="light"] .hle-btn.poll{ background:rgba(123,92,255,.14); color:#5a3fd0; }
:root[data-theme="light"] .loc-list, :root[data-theme="light"] .gc-select option, :root[data-theme="light"] select.f-select option{ background:#ffffff; }
:root[data-theme="light"] .dz-toast{ background:rgba(20,22,52,.96); color:#fff; }
@media (max-width:490px){ :root{ --topbar-h:48px; } }
@media (max-width:380px){ :root{ --topbar-h:44px; } }

html{ scroll-behavior:smooth; -webkit-text-size-adjust:100%; }
@media (max-width:768px){
    html, body, *{ scrollbar-width:none !important; -ms-overflow-style:none !important; }
    *::-webkit-scrollbar{ display:none !important; width:0 !important; height:0 !important; }
}
::selection{ background:var(--accent-sel); color:#fff; }
body{ background:var(--bg); background-attachment:fixed; color:var(--text); min-height:100vh; overflow-x:hidden; -webkit-font-smoothing:antialiased; }

/* ══ SHELL: main lane left, info column right ══ */
.mp-shell{ width:100%; display:flex; align-items:flex-start; justify-content:center; }
.mp-side{ display:none; }
@media (min-width:1100px){
    .mp-shell{ justify-content:flex-start; max-width:1240px; margin:0 auto; padding-left:22px; }
    .mp-side{ display:block; flex:1 1 0; min-width:0; position:sticky; top:0; height:100vh; overflow-y:auto; overflow-x:hidden; padding:0 22px 30px 20px; scrollbar-width:thin; scrollbar-color:var(--scroll-thumb) transparent; }
    .mp-side::-webkit-scrollbar{ width:5px; }
    .mp-side::-webkit-scrollbar-thumb{ background:var(--scroll-thumb); border-radius:99px; }
}
.ac-lane{ width:100%; max-width:var(--max-w); min-height:100vh; align-self:stretch; display:flex; flex-direction:column; padding-bottom:48px; }
@media (min-width:801px){ .ac-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); } }
@media (max-width:800px){ .ac-lane{ max-width:100%; } }
@media (min-width:1100px){ .ac-lane{ flex:0 0 var(--max-w); width:var(--max-w); } }

/* ══ TOPBAR ══ */
.ac-topbar{ position:sticky; top:0; z-index:200; height:var(--topbar-h); flex-shrink:0; display:flex; align-items:center; gap:2px; padding:0 10px; background:var(--bg); background-attachment:fixed; }
.ac-topbar::after{ content:''; position:absolute; left:0; right:0; bottom:0; height:1px; background:rgba(var(--fg),.14); pointer-events:none; }
.ac-x{ width:34px; height:34px; background:none; border:none; color:var(--text); display:flex; align-items:center; justify-content:center; opacity:.9; transition:opacity .2s,color .2s; padding:0; flex-shrink:0; }
.ac-x:hover{ opacity:1; color:var(--accent-text); }
.ac-x i{ font-size:28px; }
.ac-title{ flex:1; min-width:0; font-size:.92rem; font-weight:700; color:var(--text); letter-spacing:.2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin:0 8px 0 4px; display:flex; align-items:center; justify-content:flex-end; text-align:right; gap:8px; }
.ac-title i{ color:var(--accent-text); font-size:1.05rem; }
@media (max-width:490px){ .ac-x{ width:30px; height:30px; } .ac-title{ font-size:.84rem; } }

/* ══ GROUP HEADER ══ */
.group-row{ display:flex; align-items:center; gap:13px; padding:15px var(--pad); border-bottom:1px solid var(--line); cursor:pointer; transition:background .18s; }
.group-row:hover{ background:rgba(var(--fg),.04); }
.gr-pic{ position:relative; flex-shrink:0; display:block; }
.gr-cam{ position:absolute; right:-5px; bottom:-5px; width:22px; height:22px; border-radius:50%; background:var(--accent); color:#fff; display:flex; align-items:center; justify-content:center; font-size:.72rem; border:2px solid var(--solid,#070924); }
.gr-hint{ margin-top:6px; display:inline-flex; align-items:center; gap:5px; font-size:.62rem; font-weight:600; color:var(--accent-text); }
.group-row img{ width:clamp(46px,13vw,56px); height:clamp(46px,13vw,56px); border-radius:8px; object-fit:cover; border:2px solid var(--accent); flex-shrink:0; }
.g-body{ flex:1; min-width:0; }
.g-name{ font-size:clamp(.88rem,2.8vw,1rem); font-weight:700; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.g-sub{ font-size:.68rem; color:var(--muted); margin-top:4px; display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
.role-pill{ display:inline-flex; align-items:center; gap:4px; border:1px solid var(--accent-border); background:var(--accent-soft); border-radius:var(--act-r); padding:2px 8px; font-size:.58rem; font-weight:700; color:var(--accent-text); }
.role-pill.warn{ color:var(--warn); border-color:rgba(255,149,0,.4); background:rgba(255,149,0,.12); }
.group-row > .chevron{ color:var(--muted); font-size:1.3rem; flex-shrink:0; }

/* ══ SECTIONS + ROWS ══ */
.section-title{ padding:clamp(16px,4vw,20px) var(--pad) 8px; font-size:.6rem; font-weight:700; text-transform:uppercase; letter-spacing:1.5px; color:var(--muted); }
.list-section{ border-top:1px solid var(--line); }
.list-row{ display:flex; align-items:center; gap:12px; padding:clamp(11px,3vw,13px) var(--pad); border:none; border-bottom:1px solid var(--line); background:none; width:100%; text-align:left; color:inherit; transition:background .18s; }
.list-row:hover{ background:rgba(var(--fg),.04); }
.row-icon{ width:clamp(30px,8vw,34px); height:clamp(30px,8vw,34px); border-radius:var(--r); flex-shrink:0; background:var(--accent-soft); color:var(--accent-text); display:flex; align-items:center; justify-content:center; font-size:1rem; }
.row-body{ flex:1; min-width:0; }
.row-title{ font-size:.74rem; font-weight:600; color:var(--text); }
@media (max-width:490px){ .row-title{ font-size:.7rem; } }
.row-sub{ font-size:clamp(.6rem,2vw,.67rem); color:var(--muted); margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.row-count{ background:var(--accent); color:#fff; font-size:.62rem; font-weight:700; padding:2px 8px; border-radius:var(--act-r); flex-shrink:0; }
.chevron{ color:var(--muted); font-size:1.15rem; flex-shrink:0; }
.list-row.danger .row-icon{ background:var(--danger-soft); color:var(--danger); }
.list-row.danger .row-title{ color:var(--danger); }

/* ═════════ RIGHT COLUMN CARDS ═════════ */
.gs-cards{ padding:18px 0 2px; }
.gs-card{ margin-bottom:12px; }
.gs-bar{ display:flex; align-items:center; gap:7px; width:100%; height:30px; padding:0 12px; border:none; border-radius:var(--act-r); background:rgba(var(--fg),.1); backdrop-filter:blur(10px); color:var(--text); font-size:.72rem; font-weight:600; line-height:1; text-align:left; transition:background .2s,transform .15s; }
.gs-bar:hover{ background:rgba(var(--fg),.2); }
.gs-bar-ic{ font-size:14px; color:var(--accent-text); flex-shrink:0; }
.gs-bar-t{ flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.gs-bar-chev{ font-size:18px; color:rgba(var(--fg),.75); flex-shrink:0; transition:transform .25s; }
.gs-card.collapsed .gs-bar-chev{ transform:rotate(-90deg); }
.gs-body{ display:grid; grid-template-rows:1fr; transition:grid-template-rows .3s ease; }
.gs-inner{ min-height:0; overflow:hidden; }
.gs-pad{ padding-top:8px; }
.gs-card.collapsed .gs-body{ grid-template-rows:0fr; }
.gs-p{ font-size:.72rem; line-height:1.65; color:rgba(var(--fg),.7); overflow-wrap:anywhere; padding:2px 2px 4px; }
.gs-p b{ color:var(--text); font-weight:600; }
.gs-callout{ display:flex; gap:9px; margin:8px 0 4px; padding:10px 11px; border-radius:var(--act-r); background:var(--accent-soft); border:1px solid var(--accent-border); font-size:.67rem; line-height:1.6; color:rgba(var(--fg),.8); }
.gs-callout i{ color:var(--accent-text); font-size:1rem; flex-shrink:0; margin-top:1px; }
.gs-callout b{ color:var(--text); }
.gs-step{ display:flex; gap:11px; padding:10px 2px; border-bottom:1px solid rgba(var(--fg),.06); }
.gs-step:last-child{ border-bottom:none; }
.gs-sn{ width:22px; height:22px; border-radius:50%; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:.62rem; font-weight:700; color:#fff; background:var(--accent); }
.gs-st b{ display:block; font-size:.72rem; margin-bottom:2px; }
.gs-st span{ display:block; font-size:.65rem; line-height:1.55; color:rgba(var(--fg),.58); overflow-wrap:anywhere; }
.gs-rule{ display:flex; gap:9px; padding:6px 2px; font-size:.68rem; line-height:1.55; color:rgba(var(--fg),.74); overflow-wrap:anywhere; }
.gs-rule i{ color:var(--accent-text); font-size:.95rem; flex-shrink:0; margin-top:1px; }
.gs-topic{ display:flex; align-items:center; gap:10px; width:100%; padding:9px 2px; border:none; border-bottom:1px solid rgba(var(--fg),.06); background:none; color:inherit; font:inherit; text-align:left; }
.gs-topic:last-child{ border-bottom:none; }
.gs-topic:hover .gs-tn b{ text-decoration:underline; }
.gs-ti{ width:28px; height:28px; border-radius:var(--act-r); display:flex; align-items:center; justify-content:center; font-size:.95rem; flex-shrink:0; background:var(--accent-soft); color:var(--accent-text); }
.gs-tx{ flex:1; min-width:0; }
.gs-tn{ display:flex; align-items:center; gap:7px; }
.gs-tn b{ font-size:.72rem; }
.gs-tn small{ font-size:.56rem; font-weight:700; color:#fff; padding:1px 7px; border-radius:4px; background:var(--accent); }
.gs-tn small.zero{ background:rgba(255,255,255,.08); color:var(--muted); }
.gs-td{ display:block; font-size:.63rem; line-height:1.5; color:rgba(var(--fg),.55); margin-top:2px; }

/* ══ MODAL SHELL (full screen, same lane) ══ */
.modal-overlay{ position:fixed; inset:0; z-index:1000; display:none; justify-content:center; align-items:flex-start; background:var(--bg); background-attachment:fixed; overflow-y:auto; overflow-x:hidden; -webkit-overflow-scrolling:touch; overscroll-behavior:contain; }
.modal-overlay.active{ display:flex; }
@media (min-width:801px) and (hover:hover) and (pointer:fine){
    .modal-overlay{ scrollbar-width:thin; scrollbar-color:var(--scroll-thumb) transparent; }
    .modal-overlay::-webkit-scrollbar{ display:block; width:5px; }
    .modal-overlay::-webkit-scrollbar-thumb{ background:var(--scroll-thumb); border-radius:99px; }
}
.modal-lane{ width:100%; max-width:var(--max-w); min-height:100%; display:flex; flex-direction:column; }
@media (min-width:801px){ .modal-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); } }
@media (max-width:800px){ .modal-lane{ max-width:100%; } }
.modal-topbar{ z-index:5; }
.modal-body{ flex:1; position:relative; padding:clamp(14px,4vw,20px) var(--pad) 40px; width:100%; }
.modal-skel{ position:absolute; inset:0; background:var(--solid); z-index:5; display:flex; flex-direction:column; gap:14px; padding:clamp(14px,4vw,20px) var(--pad) 0; opacity:1; transition:opacity .28s ease; }
.modal-skel.hide{ opacity:0; pointer-events:none; }
.sk-bar{ border-radius:var(--r); flex-shrink:0; background:linear-gradient(90deg,rgba(var(--fg),.03) 25%,rgba(var(--fg),.08) 50%,rgba(var(--fg),.03) 75%); background-size:600px 100%; animation:shimmer 1.5s infinite linear; }
@keyframes shimmer{ 0%{ background-position:-600px 0; } 100%{ background-position:600px 0; } }

/* ══ MODAL CONTENT PRIMITIVES ══ */
.m-sec{ margin-top:clamp(18px,5vw,24px); }
.m-sec:first-child{ margin-top:0; }
.m-label{ font-size:.6rem; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; color:var(--muted); display:flex; align-items:center; gap:6px; margin-bottom:9px; }
.m-label i{ color:var(--accent-text); font-size:.85rem; }
.m-label .m-count{ margin-left:auto; letter-spacing:.3px; text-transform:none; font-weight:600; color:var(--muted-2); }
.m-hint{ font-size:.66rem; color:var(--muted); line-height:1.6; margin-top:10px; }
.m-hint b{ color:var(--text); font-weight:600; }
.m-info{ display:flex; gap:10px; align-items:flex-start; background:var(--accent-soft); border:1px solid var(--accent-border); border-radius:var(--r); padding:11px 13px; margin-bottom:16px; font-size:.7rem; line-height:1.6; color:rgba(var(--fg),.8); }
.m-info i{ color:var(--accent-text); font-size:1.05rem; flex-shrink:0; margin-top:1px; }
.m-info b{ color:var(--text); }
.m-divider{ height:1px; background:var(--line); margin:clamp(18px,5vw,24px) 0; }

.gc-input, .gc-textarea, .gc-select{ width:100%; padding:0 12px; height:var(--ctl-h); border-radius:var(--r); border:1px solid var(--border); background:var(--surface); color:var(--text); font-size:.76rem; outline:none; transition:border-color .2s,background .2s,box-shadow .2s; -webkit-appearance:none; appearance:none; }
.gc-textarea{ height:auto; padding:10px 12px; resize:vertical; min-height:90px; line-height:1.6; }
.gc-input:focus, .gc-textarea:focus, .gc-select:focus{ border-color:var(--accent-focus); background:rgba(var(--fg),.07); box-shadow:0 0 0 3px var(--accent-ring); }
.gc-input::placeholder, .gc-textarea::placeholder{ color:var(--muted); }
.gc-select{ cursor:pointer; padding-right:36px; color-scheme:dark; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='rgba(var(--fg),0.5)' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 12px center; }
.gc-select option{ background:var(--solid); color:var(--text); }
.gc-select option:disabled{ color:rgba(var(--fg),.4); }
.form-row{ display:flex; gap:8px; align-items:center; }
.form-row .gc-input, .form-row .gc-select{ flex:1; min-width:0; }

.gc-btn{ height:var(--act-h); padding:0 14px; border-radius:var(--act-r); border:none; background:var(--accent); color:#000; font-size:.72rem; font-weight:700; transition:background .2s,transform .15s; display:flex; align-items:center; justify-content:center; gap:7px; white-space:nowrap; flex-shrink:0; }
.gc-btn:hover{ background:var(--accent-hover); }
.gc-btn:active{ transform:scale(.98); }
.gc-btn:disabled{ opacity:.55; cursor:default; }
.gc-btn.wide{ width:100%; margin-top:clamp(14px,4vw,18px); }
.gc-btn.subtle{ background:rgba(var(--fg),.1); color:var(--text); }
.gc-btn.subtle:hover{ background:rgba(var(--fg),.2); }
.file-label{ display:inline-flex; align-items:center; gap:7px; height:var(--ctl-h); padding:0 14px; border-radius:var(--r); background:var(--surface-2); border:1px dashed var(--accent-border); color:var(--text); font-size:.74rem; transition:.2s; white-space:nowrap; flex:1; min-width:0; overflow:hidden; }
.file-label:hover{ background:var(--accent-soft); }
.file-label input{ display:none; }
.file-label span{ overflow:hidden; text-overflow:ellipsis; }
.preview-box{ border-radius:var(--r); overflow:hidden; height:clamp(80px,22vw,110px); border:1px solid var(--line); background:var(--surface); display:flex; align-items:center; justify-content:center; margin-bottom:10px; }
.preview-box img{ width:100%; height:100%; object-fit:cover; }
.preview-empty{ font-size:.7rem; color:var(--muted); }

.toggle-row{ display:flex; align-items:center; gap:12px; padding:12px 13px; background:var(--surface); border:1px solid var(--line); border-radius:var(--r); }
.toggle-note{ font-size:.74rem; color:var(--sub); flex:1; line-height:1.5; }
.toggle-note strong{ color:var(--text); }
.toggle-btn{ height:var(--act-h); padding:0 16px; border-radius:var(--act-r); border:none; font-size:.7rem; font-weight:700; transition:.2s; white-space:nowrap; flex-shrink:0; }
.toggle-btn.on{ background:var(--accent); color:#000; }
.toggle-btn.on:hover{ background:var(--accent-hover); }
.toggle-btn.off{ background:rgba(var(--fg),.1); color:var(--text); }
.toggle-btn.off:hover{ background:rgba(var(--fg),.2); }

/* bars (rules, questions, answers, people) */
.entry-list{ display:flex; flex-direction:column; gap:8px; }
.row-item{ display:flex; align-items:center; gap:10px; padding:10px 12px; background:var(--surface); border:1px solid var(--line); border-radius:var(--r); }
.row-item img.av{ width:36px; height:36px; border-radius:50%; object-fit:cover; border:2px solid var(--accent); flex-shrink:0; }
.row-item img.dim{ filter:grayscale(.4); }
.row-info{ flex:1; min-width:0; }
.row-name{ font-size:.76rem; font-weight:600; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.row-desc-sub{ font-size:.64rem; color:var(--muted); margin-top:2px; }
.row-desc-sub.admin{ color:var(--accent-text); font-weight:700; }
.row-actions{ display:flex; gap:6px; flex-shrink:0; }
.tiny-btn{ height:var(--act-h); padding:0 11px; border-radius:var(--act-r); font-size:.66rem; font-weight:700; transition:.2s; border:none; display:inline-flex; align-items:center; gap:4px; white-space:nowrap; }
.tiny-btn.kick{ background:rgba(var(--fg),.1); color:var(--text); }
.tiny-btn.kick:hover{ background:rgba(var(--fg),.2); }
.tiny-btn.ban{ background:var(--danger-soft); color:var(--danger); }
.tiny-btn.ban:hover{ background:rgba(255,107,107,.26); }
.tiny-btn.approve{ background:var(--accent); color:#000; }
.tiny-btn.approve:hover{ background:var(--accent-hover); }
.report-reason{ font-size:.66rem; color:var(--accent-text); margin-top:2px; display:flex; align-items:center; gap:4px; }
.report-desc{ font-size:.66rem; color:var(--muted); font-style:italic; margin-top:2px; }
.no-items{ font-size:.74rem; color:var(--muted); font-style:italic; padding:6px 2px; }

.chip-wrap{ display:flex; flex-wrap:wrap; gap:7px; margin-top:12px; }
.chip{ display:flex; align-items:center; gap:7px; background:var(--accent-soft); border:1px solid var(--accent-border); border-radius:var(--act-r); padding:4px 9px 4px 6px; font-size:.7rem; font-weight:600; color:var(--accent-text); }
.chip img{ width:22px; height:22px; border-radius:50%; border:1.5px solid var(--accent); object-fit:cover; }
.chip button{ background:none; border:none; color:rgba(126,166,255,.7); font-size:15px; padding:0 0 0 2px; line-height:1; }
.chip button:hover{ color:var(--text); }

/* join requests: Q&A under each request */
.req-card{ background:var(--surface); border:1px solid var(--line); border-radius:var(--r); overflow:hidden; }
.req-card .row-item{ border:none; border-radius:0; background:none; }
.qa-box{ border-top:1px solid var(--line); }
.qa-box summary{ list-style:none; display:flex; align-items:center; gap:8px; padding:9px 12px; font-size:.68rem; font-weight:600; color:var(--accent-text); cursor:pointer; }
.qa-box summary::-webkit-details-marker{ display:none; }
.qa-box summary .caret{ margin-left:auto; transition:transform .2s; }
.qa-box[open] summary .caret{ transform:rotate(180deg); }
.qa-list{ display:flex; flex-direction:column; gap:7px; padding:0 12px 12px; }
.qa-item{ padding:9px 11px; border-radius:var(--act-r); background:rgba(var(--fg),.04); border-left:2px solid var(--accent); }
.qa-q{ font-size:.66rem; font-weight:700; color:var(--text); line-height:1.5; }
.qa-a{ font-size:.72rem; color:var(--sub); line-height:1.6; margin-top:3px; white-space:pre-wrap; overflow-wrap:anywhere; }
.qa-none{ border-top:1px solid var(--line); padding:8px 12px; font-size:.64rem; color:var(--muted-2); }

/* rule / question editor bars */
.bar-item{ display:flex; align-items:center; gap:9px; padding:7px 8px 7px 12px; background:var(--surface); border:1px solid var(--line); border-radius:var(--r); margin-bottom:8px; }
.bar-item:focus-within{ border-color:var(--accent-focus); box-shadow:0 0 0 3px var(--accent-ring); }
.bdot{ width:7px; height:7px; border-radius:50%; background:var(--accent-text); flex-shrink:0; }
.bnum{ width:20px; height:20px; border-radius:50%; background:var(--accent-soft); color:var(--accent-text); font-size:.62rem; font-weight:700; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.bar-item input{ flex:1; min-width:0; background:transparent; border:none; outline:none; color:var(--text); font-size:.76rem; height:28px; }
.bar-item input::placeholder{ color:var(--muted); }
.bx{ width:26px; height:26px; border-radius:var(--act-r); border:none; background:rgba(var(--fg),.08); color:rgba(var(--fg),.7); font-size:1rem; display:flex; align-items:center; justify-content:center; flex-shrink:0; transition:.2s; }
.bx:hover{ background:var(--danger-soft); color:var(--danger); }
.req-chip{ height:24px; padding:0 9px; border-radius:var(--act-r); border:none; background:rgba(var(--fg),.08); color:var(--muted); font-size:.6rem; font-weight:700; flex-shrink:0; transition:.2s; }
.req-chip.on{ background:var(--accent); color:#000; }
.add-bar{ width:100%; height:var(--ctl-h); border-radius:var(--r); border:1px dashed var(--accent-border); background:var(--accent-soft); color:var(--accent-text); font-size:.72rem; font-weight:700; display:flex; align-items:center; justify-content:center; gap:6px; transition:.2s; }
.add-bar:hover{ background:var(--accent-fill); }
.add-bar:disabled{ opacity:.45; cursor:default; }
.bar-status{ font-size:.66rem; min-height:16px; margin-top:8px; color:var(--muted); }
.bar-status.error{ color:var(--danger); }
.bar-status.ok{ color:var(--accent-text); }

/* empty / stats */
.empty-state{ min-height:44vh; display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; padding:clamp(28px,8vw,48px) clamp(20px,7vw,36px) clamp(36px,10vw,60px); }
.es-title,.empty-title{ font-size:clamp(.95rem,3.4vw,1.1rem); font-weight:700; color:var(--text); letter-spacing:-.2px; }
.es-text,.empty-desc{ margin-top:6px; max-width:340px; font-size:clamp(.66rem,2.1vw,.76rem); color:var(--muted); line-height:1.7; }
.es-tips{ width:100%; max-width:360px; margin-top:clamp(16px,5vw,24px); display:flex; flex-direction:column; gap:7px; }
.es-tip{ display:flex; align-items:center; gap:10px; padding:10px 12px; border-radius:var(--act-r); background:rgba(var(--fg),.05); text-align:left; }
.es-tip i{ font-size:1rem; color:var(--accent); flex-shrink:0; }
.es-tip span{ font-size:clamp(.62rem,2vw,.7rem); color:var(--sub,var(--muted)); line-height:1.5; }
.es-btn{ margin-top:clamp(16px,5vw,22px); display:flex; align-items:center; justify-content:center; gap:6px; height:var(--act-h); min-width:130px; padding:0 18px; border:none; border-radius:var(--act-r); background:var(--accent); color:#000; font-size:.72rem; font-weight:700; cursor:pointer; transition:background .2s,transform .15s; }
.es-btn:hover{ background:var(--accent-hover,#3a6fe8); } .es-btn:active{ transform:scale(.98); }
.stat-grid{ display:grid; grid-template-columns:repeat(3,1fr); gap:8px; }
.stat-card{ display:flex; flex-direction:column; align-items:center; gap:6px; background:var(--surface); border:1px solid var(--line); border-radius:var(--r); padding:14px 6px; }
.stat-icon{ width:30px; height:30px; border-radius:var(--r); background:var(--accent-soft); color:var(--accent-text); display:flex; align-items:center; justify-content:center; font-size:.95rem; flex-shrink:0; }
.stat-value{ font-size:1.05rem; font-weight:700; color:var(--text); }
.stat-label{ font-size:.58rem; color:var(--muted); text-align:center; }
@media (max-width:420px){ .stat-grid{ grid-template-columns:repeat(2,1fr); } }

/* roles */
.role-pick-row{ display:flex; align-items:center; gap:12px; padding:13px; border-radius:var(--r); background:var(--surface); border:1px solid var(--line); cursor:pointer; transition:background .15s; margin-bottom:8px; }
.role-pick-row:hover{ background:rgba(var(--fg),.08); }
.role-pick-icon{ width:38px; height:38px; border-radius:var(--r); flex-shrink:0; background:var(--accent-soft); color:var(--accent-text); display:flex; align-items:center; justify-content:center; font-size:1.1rem; }
.role-pick-body{ flex:1; min-width:0; }
.role-pick-title{ font-size:.82rem; font-weight:700; color:var(--text); }
.role-pick-sub{ font-size:.66rem; color:var(--muted); margin-top:3px; line-height:1.5; }
.role-profile-card{ display:flex; align-items:center; gap:12px; background:var(--surface); border:1px solid var(--line); border-radius:var(--r); padding:13px; margin-bottom:18px; }
.role-profile-card img{ width:48px; height:48px; border-radius:50%; object-fit:cover; border:2px solid var(--accent); flex-shrink:0; }
.role-profile-name{ font-size:.86rem; font-weight:700; color:var(--text); }
.role-profile-sub{ font-size:.66rem; color:var(--muted); margin-top:3px; }
.perm-list{ display:flex; flex-direction:column; gap:8px; }
.perm-item{ display:flex; align-items:flex-start; gap:10px; background:var(--surface); border:1px solid var(--line); border-radius:var(--r); padding:10px 12px; }
.perm-item i{ font-size:1rem; margin-top:1px; flex-shrink:0; }
.perm-item.yes i{ color:#4ADE80; }
.perm-item.no i{ color:rgba(var(--fg),.32); }
.perm-item-title{ font-size:.74rem; font-weight:600; color:var(--text); }
.perm-item-sub{ font-size:.64rem; color:var(--muted); margin-top:2px; line-height:1.5; }
.perm-item.no .perm-item-title{ color:rgba(var(--fg),.55); }
.role-tabs{ display:flex; gap:6px; margin-bottom:16px; background:var(--surface); border:1px solid var(--line); border-radius:var(--r); padding:4px; }
.role-tab-btn{ flex:1; height:var(--act-h); border-radius:var(--act-r); border:none; background:none; color:var(--muted); font-size:.7rem; font-weight:700; transition:.2s; }
.role-tab-btn.active{ background:var(--accent); color:#000; }
.role-tab-panel{ display:none; }
.role-tab-panel.active{ display:block; }

/* activity */

/* ── Insights charts + activity filters (themed through --fg / --accent) ── */
.ins-card{ background:var(--surface); border:1px solid var(--line); border-radius:var(--r); padding:12px 12px 10px; margin-bottom:8px; }
.ins-head{ display:flex; align-items:center; gap:10px; }
.ins-meta{ flex:1; min-width:0; }
.ins-title{ font-size:.78rem; font-weight:700; color:var(--text); }
.ins-sub{ font-size:.62rem; color:var(--muted); margin-top:1px; }
.ins-num{ text-align:right; display:flex; flex-direction:column; align-items:flex-end; gap:3px; }
.ins-big{ font-size:1.15rem; font-weight:700; color:var(--text); line-height:1; }
.ins-chip{ font-size:.58rem; font-weight:700; padding:2px 6px; border-radius:99px; background:rgba(var(--fg),.08); color:var(--muted); }
.ins-chip.up{ background:rgba(74,222,128,.16); color:#4ade80; }
.ins-chip.down{ background:var(--danger-soft); color:var(--danger); }
:root[data-theme="light"] .ins-chip.up{ color:#15803d; }
.ins-bars{ display:flex; align-items:flex-end; gap:6px; height:76px; margin-top:12px; }
.ins-bar{ flex:1; height:100%; display:flex; flex-direction:column; align-items:center; justify-content:flex-end; gap:4px; }
.ins-bar span{ width:100%; border-radius:3px 3px 0 0; background:rgba(var(--fg),.12); min-height:3px; }
.ins-bar span.on{ background:var(--accent); }
.ins-bar i{ font-style:normal; font-size:.54rem; color:var(--muted); }
.ins-list{ display:flex; flex-direction:column; gap:8px; margin-top:8px; }
.ins-row{ display:flex; align-items:center; gap:11px; padding:11px 12px; border-radius:var(--r); background:var(--surface); border:1px solid var(--line); color:inherit; text-decoration:none; }
.ins-row > i:first-child{ font-size:1.1rem; color:var(--accent-text); flex-shrink:0; }
.ins-row > div{ flex:1; min-width:0; }
.ins-row-t{ font-size:.74rem; font-weight:700; color:var(--text); }
.ins-row-s{ font-size:.66rem; color:var(--sub); margin-top:2px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.act-filters{ display:flex; gap:6px; overflow-x:auto; margin-bottom:14px; scrollbar-width:none; }
.act-filters::-webkit-scrollbar{ display:none; }
.act-f{ flex-shrink:0; background:var(--surface); border:1px solid var(--line); color:var(--sub); font:inherit; font-size:.68rem; font-weight:700; padding:6px 13px; border-radius:99px; cursor:pointer; }
.act-f.active{ background:var(--accent-soft); border-color:var(--accent-border); color:var(--accent-text); }
.act-none{ text-align:center; color:var(--muted); font-size:.74rem; padding:26px 0; }
.act-cat-title{ display:flex; align-items:center; gap:8px; font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:1.4px; color:var(--accent-text); margin:22px 0 10px; }
.act-cat-title:first-child{ margin-top:0; }
.act-entry{ display:flex; align-items:flex-start; gap:11px; padding:10px 12px; border-radius:var(--r); background:var(--surface); border:1px solid var(--line); margin-bottom:8px; }
.act-entry-icon{ width:28px; height:28px; border-radius:var(--act-r); flex-shrink:0; background:var(--accent-soft); color:var(--accent-text); display:flex; align-items:center; justify-content:center; font-size:.85rem; margin-top:1px; }
.act-entry-text{ font-size:.74rem; color:rgba(var(--fg),.85); line-height:1.5; }
.act-entry-text strong{ color:var(--text); }
.act-entry-time{ font-size:.6rem; color:var(--muted); margin-top:3px; }

/* page skeleton */
#skeleton-loader{ position:fixed; inset:0; z-index:5000; background:var(--bg); background-attachment:fixed; display:flex; justify-content:center; transition:opacity .45s, visibility .45s; }
#skeleton-loader.hide{ opacity:0; visibility:hidden; pointer-events:none; }
.sk-lane{ width:100%; max-width:var(--max-w); overflow:hidden; }
@media (min-width:801px){ .sk-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); } }
@media (min-width:1100px){ #skeleton-loader{ justify-content:flex-start; padding-left:max(22px, calc((100vw - 1240px) / 2 + 22px)); } .sk-lane{ flex:0 0 var(--max-w); } }
.sk{ position:relative; overflow:hidden; background:rgba(var(--fg),.06); border-radius:6px; }
.sk::after{ content:''; position:absolute; inset:0; background:linear-gradient(90deg,transparent,rgba(var(--fg),.09),transparent); transform:translateX(-100%); animation:sk-shine 1.5s infinite; }
@keyframes sk-shine{ 100%{ transform:translateX(100%); } }
.sk-topbar{ height:var(--topbar-h); display:flex; align-items:center; gap:12px; padding:0 16px; border-bottom:1px solid rgba(var(--fg),.14); }
.sk-row{ display:flex; align-items:center; gap:12px; padding:14px var(--pad); border-bottom:1px solid var(--line); }
.sk-ic{ width:34px; height:34px; flex-shrink:0; }
.sk-ln{ flex:1; display:flex; flex-direction:column; gap:7px; }

/* toast */
.dz-toast{ position:fixed; bottom:max(22px,env(safe-area-inset-bottom)); left:50%; transform:translateX(-50%) translateY(8px); background:rgba(8,8,22,.97); border:1px solid var(--accent-border); color:#fff; padding:9px 18px; border-radius:var(--r); font-size:.72rem; font-weight:500; z-index:99999; pointer-events:none; box-shadow:0 8px 24px rgba(0,0,0,.6); white-space:nowrap; max-width:92vw; overflow:hidden; text-overflow:ellipsis; opacity:0; transition:opacity .2s, transform .2s; }
.dz-toast.show{ opacity:1; transform:translateX(-50%) translateY(0); }

/* ══════════ GROUP HIGHLIGHTS ══════════ */
#modal-hl-edit{ z-index:1100; } #modal-clip{ z-index:1200; } #modal-ht{ z-index:1300; } #modal-poll{ z-index:1350; }
.f-input, input.f-input, textarea.f-input, select.f-select{ width:100%; min-width:0; padding:10px 13px; border-radius:var(--r); border:1px solid var(--border); background:var(--surface); color:var(--text); font-size:.76rem; outline:none; transition:var(--tr); resize:none; -webkit-appearance:none; }
input.f-input:focus, textarea.f-input:focus, select.f-select:focus{ border-color:var(--border-focus); box-shadow:0 0 0 3px var(--accent-ring); }
input.f-input::placeholder, textarea.f-input::placeholder{ color:var(--muted); }
textarea.f-input{ min-height:84px; line-height:1.6; }
select.f-select{ color-scheme:dark; cursor:pointer; }
select.f-select option{ background:var(--solid); color:var(--text); }
.field-block{ margin-bottom:18px; }
.field-label{ font-size:.6rem; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; color:var(--muted); display:flex; align-items:center; gap:6px; margin-bottom:9px; }
.field-label i{ color:var(--accent-text); }
.field-row{ display:flex; flex-direction:column; gap:7px; padding:0 0 15px; }
.field-control{ display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.field-help{ font-size:.66rem; color:var(--text-muted); line-height:1.6; }
.save-btn-full{ width:100%; height:var(--act-h); margin-top:8px; border-radius:var(--act-r); border:none; background:var(--accent); color:#000; font-size:.72rem; font-weight:700; transition:var(--tr); }
.save-btn-full:hover{ background:var(--accent-hover); }
.save-btn-full:active{ transform:scale(.98); }
.save-btn-full:disabled{ opacity:.5; cursor:not-allowed; }
.hl-add{ display:flex; flex-direction:column; gap:9px; }
.hl-pick{ display:flex; align-items:center; gap:11px; padding:10px 12px; border-radius:var(--r); border:1px dashed rgba(var(--fg),.2); background:rgba(var(--fg),.02); cursor:pointer; transition:var(--tr); color:var(--text-sub); font-size:.74rem; }
.hl-pick:hover{ border-color:var(--accent-border); background:var(--accent-dim); color:var(--text); }
.hl-pick i{ font-size:20px; color:var(--accent-light); flex-shrink:0; }
.hl-pick input{ display:none; }
button.hl-pick{ width:100%; text-align:left; }
.hl-pick.small{ padding:8px 11px; }
.hl-pick.poll{ border-color:rgba(123,92,255,.5); background:rgba(123,92,255,.08); }
.hl-pick.poll i{ color:#b7a2ff; }
.hl-pick-text{ flex:1; min-width:0; line-height:1.45; }
.hl-pick-text strong{ display:block; font-size:.76rem; font-weight:600; color:var(--text); }
.hl-pick-text span{ display:block; font-size:.66rem; color:var(--text-muted); }
.hl-cover-prev{ width:46px; height:46px; border-radius:var(--r); object-fit:cover; background:var(--surface-2); border:1px solid var(--border); flex-shrink:0; display:none; }
.hl-cover-prev.show{ display:block; }
.hl-progress{ height:4px; border-radius:2px; background:rgba(var(--fg),.08); overflow:hidden; display:none; }
.hl-progress.show{ display:block; }
.hl-progress span{ display:block; height:100%; width:0; background:var(--accent); transition:width .2s ease; }
.hl-status{ font-size:.68rem; color:var(--text-sub); min-height:15px; line-height:1.4; }
.hl-status.error{ color:#f87171; }
.hl-list-head{ display:flex; align-items:baseline; justify-content:space-between; margin:24px 0 11px; padding-top:18px; border-top:1px solid var(--divider); }
.hl-list-title{ font-size:.6rem; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; color:var(--muted); }
.hl-list-count{ font-size:.68rem; color:var(--text-muted); }
.hl-grid{ display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:16px 11px; }
.hl-item{ min-width:0; }
.hl-sq{ position:relative; width:100%; aspect-ratio:1/1; border-radius:var(--r); overflow:hidden; background:var(--surface-2); border:1px solid var(--border); }
.hl-sq img, .hl-sq video{ position:absolute; inset:0; width:100%; height:100%; object-fit:cover; display:block; pointer-events:none; }
.hl-vid, .hl-song, .hl-pollbadge{ position:absolute; bottom:6px; width:20px; height:20px; border-radius:50%; background:rgba(0,0,0,.55); display:flex; align-items:center; justify-content:center; font-size:12px; color:#fff; }
.hl-vid{ left:6px; } .hl-song{ right:6px; font-size:11px; } .hl-pollbadge{ left:6px; background:rgba(123,92,255,.85); }
.hl-del{ position:absolute; top:5px; right:5px; width:24px; height:24px; border-radius:var(--act-r); border:none; background:rgba(0,0,0,.65); color:#fff; font-size:13px; display:flex; align-items:center; justify-content:center; transition:var(--tr); z-index:2; }
.hl-del:hover{ background:var(--accent); }
.hl-name{ margin-top:7px; font-size:.72rem; font-weight:600; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.hl-count{ margin-top:1px; font-size:.64rem; color:var(--text-muted); }
.hl-empty{ grid-column:1/-1; text-align:center; padding:16px 10px 4px; color:var(--text-muted); }
.hl-tilecap{ position:absolute; inset:0; display:flex; align-items:center; justify-content:center; padding:7px; text-align:center; font-size:10px; font-weight:600; line-height:1.35; color:#fff; text-shadow:0 1px 4px rgba(0,0,0,.85); overflow:hidden; pointer-events:none; background:rgba(0,0,0,.18); word-break:break-word; }
.hl-pollTile{ position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:5px; padding:8px; text-align:center; background:linear-gradient(160deg,#1a2a6c,#2a5fd8 60%,#7b5cff); color:#fff; overflow:hidden; }
.hl-pollTile i{ font-size:20px; opacity:.95; }
.hl-pollTile span{ font-size:10px; font-weight:700; line-height:1.3; display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden; word-break:break-word; }
.hl-x{ margin-left:auto; width:22px; height:22px; border-radius:var(--act-r); border:none; background:rgba(var(--fg),.1); color:var(--text-sub); flex-shrink:0; display:none; align-items:center; justify-content:center; font-size:13px; }
.hl-x.show{ display:flex; }
.hl-x:hover{ background:var(--red); color:#fff; }
.hle-sec{ margin-top:20px; padding-top:18px; border-top:1px solid var(--divider); }
.hle-h{ font-size:.6rem; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; color:var(--muted); margin-bottom:9px; }
.hle-cover{ display:flex; align-items:center; gap:12px; }
.hle-cover .hl-sq{ width:76px; flex-shrink:0; }
.hle-info{ flex:1; min-width:0; }
.hle-info strong{ display:block; font-size:.74rem; font-weight:600; color:var(--text); }
.hle-info span{ display:block; font-size:.66rem; color:var(--text-muted); margin-top:1px; line-height:1.5; }
.hle-btns{ display:flex; flex-wrap:wrap; gap:6px; margin-top:8px; }
.hle-btn{ height:var(--act-h); padding:0 12px; border-radius:var(--act-r); background:rgba(var(--fg),.1); border:none; color:var(--text); font-size:.68rem; font-weight:600; transition:var(--tr); position:relative; overflow:hidden; display:inline-flex; align-items:center; gap:4px; }
.hle-btn:hover{ background:rgba(var(--fg),.2); }
.hle-btn.accent{ background:var(--accent); color:#000; } .hle-btn.accent:hover{ background:var(--accent-hover); }
.hle-btn.poll{ background:rgba(123,92,255,.25); color:#d6ccff; }
.hle-btn.danger:hover{ background:var(--danger-soft); color:var(--danger); }
.hle-btn:disabled{ opacity:.5; pointer-events:none; }
.hle-btn input{ position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; font-size:0; }
.hle-song{ display:flex; align-items:center; gap:10px; padding:10px 12px; border:1px solid var(--border); border-radius:var(--r); background:rgba(var(--fg),.02); }
.hle-song > i{ font-size:20px; color:var(--accent-light); flex-shrink:0; }
.hle-grid{ display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:13px 9px; }
.hle-item{ min-width:0; }
.hle-date{ margin-top:5px; font-size:.62rem; color:var(--text-muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.hle-actions{ display:flex; flex-wrap:wrap; gap:4px; margin-top:5px; }
.hle-chip{ display:inline-flex; align-items:center; gap:3px; padding:3px 7px; border-radius:var(--act-r); border:none; background:rgba(var(--fg),.08); color:var(--text-sub); font-size:.62rem; font-weight:600; max-width:100%; overflow:hidden; white-space:nowrap; text-overflow:ellipsis; transition:var(--tr); }
.hle-chip.on{ background:var(--accent-dim); color:var(--accent-light); }
.hle-chip:hover{ background:var(--accent-soft); }
.hle-chip.x{ padding:3px 6px; }
.hle-danger{ margin-top:24px; }
.hl-seg{ display:flex; padding:3px; border-radius:var(--r); background:var(--surface); border:1px solid var(--line); gap:3px; }
.hl-seg button{ flex:1; height:var(--act-h); border:none; border-radius:var(--act-r); background:none; color:var(--text-sub); font-size:.68rem; font-weight:700; transition:var(--tr); }
.hl-seg button.on{ background:var(--accent); color:#000; }
.hl-seg button:disabled{ opacity:.45; cursor:not-allowed; }
.hl-rows{ max-height:270px; overflow-y:auto; display:flex; flex-direction:column; gap:6px; padding-right:2px; scrollbar-width:thin; scrollbar-color:var(--scroll-thumb) transparent; }
.hl-row{ display:flex; align-items:center; gap:9px; padding:7px 9px; border:1px solid var(--border); border-radius:var(--r); background:rgba(var(--fg),.02); }
.hl-row-th{ width:38px; height:38px; border-radius:var(--act-r); overflow:hidden; background:var(--surface-2); flex-shrink:0; display:flex; align-items:center; justify-content:center; color:var(--text-sub); font-size:18px; }
.hl-row-th.poll{ background:linear-gradient(160deg,#1a2a6c,#7b5cff); color:#fff; }
.hl-row-th img{ width:100%; height:100%; object-fit:cover; }
.hl-row-info{ flex:1; min-width:0; }
.hl-row-info strong{ display:block; font-size:.68rem; font-weight:600; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.hl-row-info .hle-actions{ margin-top:4px; }
.hl-rows-head{ font-size:.66rem; color:var(--text-muted); margin:2px 0 6px; line-height:1.5; }
.hl-view{ position:relative; width:100%; aspect-ratio:4/5; max-height:58vh; margin:0 auto; border-radius:var(--r); overflow:hidden; background:#000; border:1px solid var(--border); }
.hl-view > img, .hl-view > video{ position:absolute; inset:0; width:100%; height:100%; object-fit:cover; display:block; }
#ht-capbox{ position:absolute; inset:0; }
.hl-cap-wrap{ position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:6px; padding:18px; text-align:center; min-height:0; }
.hl-cap{ max-width:100%; min-height:0; padding:10px 13px; border-radius:var(--r); background:rgba(0,0,0,.34); color:#fff; font-size:15px; font-weight:600; line-height:1.5; text-shadow:0 1px 6px rgba(0,0,0,.85); word-break:break-word; overflow-wrap:anywhere; }
.hl-cap.clamp{ display:-webkit-box; -webkit-line-clamp:4; -webkit-box-orient:vertical; overflow:hidden; }
.hl-cap:not(.clamp){ max-height:100%; overflow-y:auto; scrollbar-width:none; }
.hl-cap:not(.clamp)::-webkit-scrollbar{ display:none; }
.hl-cap-empty{ opacity:.6; font-weight:500; }
.hl-more{ flex-shrink:0; background:rgba(0,0,0,.5); border:1px solid rgba(255,255,255,.25); border-radius:var(--act-r); color:#fff; font-size:11px; font-weight:600; padding:3px 12px; }
.hl-men{ font-weight:700; text-decoration:none; text-shadow:0 1px 5px rgba(0,0,0,.9); }
.hl-men.user{ color:#ff3b4e; } .hl-men.group{ color:#3ea6ff; } .hl-men:hover{ text-decoration:underline; }
.ht-count{ font-size:.66rem; color:var(--text-muted); text-align:right; margin-top:5px; }
#ht-input{ width:100%; min-height:110px; }
.ht-preview-wrap{ margin-top:16px; }
.ht-actions{ display:flex; gap:8px; margin-top:12px; }
.ht-actions .save-btn-full{ margin-top:0; }
.ht-remove{ flex-shrink:0; height:var(--act-h); padding:0 14px; border-radius:var(--r); background:rgba(var(--fg),.08); border:none; color:var(--text-sub); font-size:.72rem; font-weight:600; transition:var(--tr); }
.ht-remove:hover{ background:var(--danger-soft); color:var(--danger); }
.hle-footer{ position:sticky; bottom:0; flex-shrink:0; width:100%; padding:10px var(--pad) max(12px,env(safe-area-inset-bottom)); border-top:1px solid var(--divider); background:var(--solid); z-index:6; }
.hle-note{ font-size:.66rem; color:var(--text-muted); min-height:15px; margin-bottom:7px; text-align:center; line-height:1.4; }
.hle-note.warn{ color:var(--accent-light); }
.hle-footer .save-btn-full{ margin-top:0; }
.cp-times{ display:flex; justify-content:space-between; font-size:.7rem; color:var(--text-sub); margin:14px 0 6px; }
.cp-times b{ color:var(--text); font-weight:600; }
#cp-start{ width:100%; accent-color:var(--accent); }
.cp-row{ display:flex; align-items:center; justify-content:space-between; gap:12px; margin:12px 0; }
#cp-prev-btn{ width:100%; margin-bottom:8px; text-align:left; }
.hl-poll{ position:absolute; inset:0; display:flex; flex-direction:column; justify-content:center; gap:9px; padding:18px; background:linear-gradient(160deg,#1a2a6c,#2a5fd8 60%,#7b5cff); }
.hl-poll-tag{ align-self:center; display:inline-flex; align-items:center; gap:5px; font-size:10px; font-weight:700; letter-spacing:1px; text-transform:uppercase; color:rgba(var(--fg),.85); }
.hl-poll-q{ font-size:15px; font-weight:700; text-align:center; line-height:1.4; color:#fff; margin-bottom:4px; text-shadow:0 1px 6px rgba(0,0,0,.4); word-break:break-word; }
.hl-poll-opt{ position:relative; overflow:hidden; border-radius:var(--r); background:rgba(255,255,255,.18); padding:9px 12px; font-size:12.5px; font-weight:600; color:#fff; display:flex; justify-content:space-between; gap:10px; }
.hl-poll-opt .bar{ position:absolute; left:0; top:0; bottom:0; background:rgba(255,255,255,.3); width:0; transition:width .3s ease; }
.hl-poll-opt span{ position:relative; word-break:break-word; }
.hl-poll-meta{ text-align:center; font-size:10.5px; color:rgba(var(--fg),.8); margin-top:2px; }
.pl-opt-row{ display:flex; align-items:center; gap:8px; margin-bottom:7px; }
.pl-opt-row .num{ width:22px; height:22px; border-radius:50%; background:var(--accent-dim); color:var(--accent-light); font-size:11px; font-weight:700; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.pl-opt-row .hl-x{ display:flex; margin-left:0; }
.pl-count{ font-size:.64rem; color:var(--text-muted); text-align:right; margin-top:3px; }
.pl-lock{ display:flex; gap:9px; align-items:flex-start; background:var(--surface); border:1px dashed var(--border); border-radius:var(--r); padding:10px 12px; margin-bottom:14px; font-size:.68rem; color:var(--text-sub); line-height:1.55; }
.pl-lock i{ color:var(--accent-light); font-size:15px; flex-shrink:0; margin-top:1px; }

@media (max-width:490px){
    .hl-grid, .hle-grid{ gap:12px 8px; }
    .hl-cap{ font-size:14px; } .hl-poll-q{ font-size:14px; }
    .toggle-row{ flex-direction:column; align-items:stretch; }
    .toggle-btn{ height:var(--act-h); }
    .req-chip{ padding:0 7px; }
    .row-item{ padding:9px 10px; gap:8px; }
}
@media (max-width:380px){ .form-row{ flex-direction:column; align-items:stretch; } .form-row .gc-btn{ width:100%; } }
@media (max-width:330px){ .hl-grid, .hle-grid{ grid-template-columns:repeat(2,minmax(0,1fr)); } .row-actions{ flex-direction:column; } }
@media (prefers-reduced-motion:reduce){ *{ transition-duration:.01ms !important; animation-duration:.01ms !important; } }

/* ══════════ MODAL POLISH LAYER — one type scale, one spacing rhythm, grouped cards ══════════ */
:root{
    --fs-item:.78rem; --fs-body:.72rem; --fs-sub:.64rem; --fs-cap:.58rem;
    --gap:12px; --card-pad:14px; --row-pad:11px 13px;
}
@media (max-width:490px){ :root{ --fs-item:.74rem; --fs-body:.68rem; --fs-sub:.62rem; --fs-cap:.56rem; --gap:10px; --card-pad:12px; --row-pad:10px 12px; } }
@media (min-width:801px){ .modal-body{ padding-left:clamp(16px,3vw,24px); padding-right:clamp(16px,3vw,24px); } }

/* body = one vertical rhythm */
.modal-body{ display:flex; flex-direction:column; gap:var(--gap); padding-top:clamp(14px,4vw,20px); padding-bottom:56px; }
.modal-body > *{ margin-top:0; margin-bottom:0; }
.modal-body > .m-label{ margin-bottom:calc(var(--gap) * -.45); padding:0 2px; }
.modal-body > .m-hint{ margin-top:calc(var(--gap) * -.4); padding:0 2px; }
.modal-body > form, .m-sec > form{ display:flex; flex-direction:column; gap:10px; }
.modal-body > form > *, .m-sec > form > *{ margin-top:0; margin-bottom:0; }
.gc-btn.wide, .modal-body .save-btn-full{ margin-top:2px; }

.modal-body > form{ padding:var(--card-pad); background:var(--surface); border:1px solid var(--line); border-radius:var(--r); }
.modal-body > .perm-list{ margin-bottom:0 !important; }
/* each section = one grouped card */
.m-sec{ margin:0 !important; padding:var(--card-pad); background:var(--surface); border:1px solid var(--line); border-radius:var(--r); display:flex; flex-direction:column; gap:10px; }
.m-sec > *{ margin-top:0; margin-bottom:0; }
.m-sec > .m-label{ margin-bottom:0; }
.m-sec > .m-hint{ margin-top:-2px; }
.m-sec .toggle-row, .m-sec .m-info{ background:var(--inset); }
.m-sec .m-info{ border-color:var(--accent-border); background:var(--accent-soft); }
.m-sec .bar-item{ margin-bottom:0; background:var(--inset); }
.m-divider{ display:none; }
.bar-status{ margin-top:-4px; min-height:0; }
.bar-status:empty{ display:none; }

/* label / hint / info — same sizes everywhere */
.m-label, .field-label, .act-cat-title{ font-size:var(--fs-cap); letter-spacing:1.3px; }
.m-hint, .field-help{ font-size:var(--fs-sub); line-height:1.6; }
.m-info{ font-size:var(--fs-body); padding:11px 13px; }

/* rows: one size for titles, one for subs */
.row-item{ padding:var(--row-pad); gap:11px; }
.row-item img.av{ width:38px; height:38px; }
.row-name, .role-pick-title, .perm-item-title, .role-profile-name{ font-size:var(--fs-item); }
.row-desc-sub, .role-pick-sub, .perm-item-sub, .role-profile-sub, .act-entry-time, .report-desc, .report-reason{ font-size:var(--fs-sub); }
.act-entry-text, .toggle-note, .qa-a, .no-items{ font-size:var(--fs-body); }
.qa-q{ font-size:var(--fs-sub); }
.qa-box summary{ font-size:var(--fs-sub); padding:10px 13px; }
.qa-list{ padding:0 13px 13px; }
.entry-list{ gap:10px; }
.perm-list{ gap:10px; }
.perm-item, .act-entry{ padding:var(--row-pad); margin-bottom:0; }
.act-entry{ gap:12px; }
#modal-activity .modal-body > .act-entry{ margin-bottom:0; }
.act-cat-title{ margin:10px 0 0; padding:0 2px; }
.act-cat-title:first-child{ margin-top:0; }
.role-pick-row{ padding:var(--row-pad); margin-bottom:0; }
.role-profile-card{ padding:var(--row-pad); margin-bottom:0; }
.role-tabs{ margin-bottom:0; }

/* inputs + controls share one height and radius */
.gc-input, .gc-select, .add-bar{ height:var(--ctl-h); font-size:var(--fs-body); }
.gc-textarea{ font-size:var(--fs-body); }
.bar-item{ padding:6px 8px 6px 12px; gap:10px; }
.bar-item input{ font-size:var(--fs-body); }
.add-bar{ font-size:var(--fs-body); }
.preview-box{ margin-bottom:0; }

/* toggle row: note left, button right — never stacks */
.toggle-row{ padding:var(--row-pad); gap:12px; flex-wrap:nowrap; }
.toggle-row form{ flex-shrink:0; margin:0; }
.toggle-note{ min-width:0; }
.toggle-btn{ min-width:96px; font-size:var(--fs-sub); }
.toggle-btn, .gc-btn, .tiny-btn, .save-btn-full, .es-btn, .hle-btn{ font-size:var(--fs-sub); }
.gc-btn, .save-btn-full{ font-size:var(--fs-body); }

/* join request card: actions drop under the name on small phones */
.req-card .row-item{ flex-wrap:wrap; }
@media (max-width:420px){
    .req-card .row-info{ flex:1 1 calc(100% - 60px); }
    .req-card .row-actions{ flex:1 1 100%; }
    .req-card .row-actions form{ flex:1; }
    .req-card .row-actions .tiny-btn{ width:100%; justify-content:center; }
}
@media (max-width:420px){
    .row-item .row-actions .tiny-btn{ padding:0 9px; }
    .toggle-btn{ min-width:84px; padding:0 12px; }
    .form-row{ flex-wrap:wrap; }
    .form-row .gc-btn{ flex:1 1 100%; }
}

/* empty states keep their own centred scale but follow the same gutters */
.modal-body > .empty-state{ margin:0; }

/* ══════════ LOCATION MODAL ══════════ */
.loc-wrap{ position:relative; }
.loc-list{ display:none; position:absolute; left:0; right:0; top:calc(100% + 6px); z-index:30; background:var(--solid); border:1px solid var(--accent-border); border-radius:var(--r); max-height:260px; overflow-y:auto; box-shadow:0 12px 28px rgba(0,0,0,.6); }
.loc-list.show{ display:block; }
.loc-item{ display:flex; align-items:flex-start; gap:9px; width:100%; padding:10px 12px; border:none; border-bottom:1px solid rgba(var(--fg),.06); background:none; color:var(--text); font-size:var(--fs-body); line-height:1.5; text-align:left; transition:background .15s; }
.loc-item:last-child{ border-bottom:none; }
.loc-item:hover{ background:var(--accent-soft); }
.loc-item i{ color:var(--accent-text); font-size:1rem; flex-shrink:0; margin-top:1px; }
.loc-item span{ min-width:0; overflow-wrap:anywhere; }
.loc-msg{ padding:11px 12px; font-size:var(--fs-sub); color:var(--muted); }
.loc-tag-box{ display:none; }
.loc-tag-box.show{ display:block; }
.loc-tag-box .chip{ max-width:100%; padding:7px 10px; font-size:var(--fs-body); }
.loc-tag-box .chip span{ min-width:0; overflow-wrap:anywhere; }
.loc-tag-box .chip i{ flex-shrink:0; }
.loc-tag-box .chip button{ margin-left:auto; font-size:18px; }

/* ══════════ RESPONSIVE ONLY: everything about 10% bigger on tablets and phones ══════════
   Desktop / normal view (wider than 800px) is NOT touched. */
@media (max-width:800px){
    html{ font-size:110%; }
    :root{
        --topbar-h:59px; --pad:clamp(11px,3.3vw,18px);
        --ctl-h:clamp(35px,8.8vw,42px); --act-h:clamp(29px,7.9vw,33px);
        --gap:13px; --card-pad:15px; --row-pad:12px 14px;
    }
    .ac-x{ width:37px; height:37px; }
    .ac-x i{ font-size:31px; }
    .row-icon{ width:clamp(33px,8.8vw,37px); height:clamp(33px,8.8vw,37px); }
    .group-row img{ width:clamp(51px,14.3vw,62px); height:clamp(51px,14.3vw,62px); }
    .gr-cam{ width:24px; height:24px; }
    .row-item img.av{ width:42px; height:42px; }
    .role-pick-icon{ width:42px; height:42px; }
    .role-profile-card img{ width:53px; height:53px; }
    .stat-icon{ width:33px; height:33px; }
    .bx{ width:29px; height:29px; }
    .bnum{ width:22px; height:22px; }
    .req-chip{ height:26px; }
    .hl-row-th{ width:42px; height:42px; }
    .hl-cap{ font-size:16.5px; }
    .hl-poll-q{ font-size:16.5px; }
    .hl-poll-opt{ font-size:13.5px; }
    .toast, .dz-toast{ font-size:.78rem; }
}
@media (max-width:490px){
    :root{ --topbar-h:53px; --gap:11px; --card-pad:13px; --row-pad:11px 13px; }
    .ac-x{ width:33px; height:33px; }
    .hl-cap{ font-size:15.5px; }
    .hl-poll-q{ font-size:15.5px; }
}
@media (max-width:380px){ :root{ --topbar-h:48px; } }

/* ══════════ MODERATION TOOLS ══════════ */
#modal-banreason{ z-index:1400; }
.gc-btn.danger{ background:var(--danger); color:#000; }
.gc-btn.danger:hover{ background:#ff4d4d; }
.ban-note{ display:flex; align-items:flex-start; gap:5px; margin-top:3px; font-size:var(--fs-sub); color:var(--sub); line-height:1.5; overflow-wrap:anywhere; }
.ban-note i{ color:var(--accent-text); flex-shrink:0; margin-top:2px; }
.ban-note.none{ color:var(--muted-2); font-style:italic; }
.q-chip{ border:1px solid var(--accent-border); background:var(--accent-soft); color:var(--accent-text); border-radius:var(--act-r); padding:5px 10px; font-size:var(--fs-sub); font-weight:600; transition:.2s; }
.q-chip:hover{ background:var(--accent-fill); color:var(--text); }
.sm-grid{ display:grid; grid-template-columns:repeat(2,1fr); gap:8px; }
.sm-opt{ height:calc(var(--ctl-h) + 4px); border-radius:var(--r); border:1px solid var(--line); background:var(--inset); color:var(--text); font-size:var(--fs-body); font-weight:600; transition:.2s; }
.sm-opt:hover{ background:rgba(var(--fg),.07); }
.sm-opt.on{ background:var(--accent); border-color:var(--accent); color:#000; font-weight:700; }
.row-item .row-actions{ align-items:center; }
#modal-warnhist{ z-index:1350; } #modal-warn{ z-index:1400; } #modal-limit{ z-index:1450; }
.tiny-btn.warn{ background:rgba(255,149,0,.16); color:var(--warn); }
.tiny-btn.warn:hover{ background:rgba(255,149,0,.28); }
.strike-badge{ display:inline-block; padding:1px 7px; border-radius:var(--act-r); background:rgba(255,149,0,.16); color:var(--warn); font-size:var(--fs-sub); font-weight:700; }
.strike-badge.hit{ background:var(--danger-soft); color:var(--danger); }
.row-actions{ flex-wrap:wrap; justify-content:flex-end; }
</style>
</head>
<body>

<!-- ══ SKELETON LOADER ══ -->
<div id="skeleton-loader" aria-hidden="true">
    <div class="sk-lane">
        <div class="sk-topbar"><div class="sk" style="width:22px;height:22px"></div><div class="sk" style="width:130px;height:14px"></div></div>
        <div class="sk-row"><div class="sk" style="width:52px;height:52px"></div><div class="sk-ln"><div class="sk" style="width:55%;height:13px"></div><div class="sk" style="width:35%;height:10px"></div></div></div>
        <?php for ($i = 0; $i < 7; $i++): ?>
        <div class="sk-row"><div class="sk sk-ic"></div><div class="sk-ln"><div class="sk" style="width:48%;height:12px"></div><div class="sk" style="width:70%;height:9px"></div></div></div>
        <?php endfor; ?>
    </div>
</div>

<div class="mp-shell">

<div class="ac-lane">

    <!-- ══ TOPBAR ══ -->
    <div class="ac-topbar">
        <button type="button" class="ac-x" onclick="location.href='group.php?id=<?php echo $group_id; ?>'" aria-label="Back to group"><i class="ri-close-line"></i></button>
        <div class="ac-title"><?php echo $is_owner ? 'Group Settings' : 'Admin Settings'; ?></div>
    </div>

    <!-- ══ GROUP HEADER ROW ══ -->
    <div class="group-row" onclick="openModal('modal-profile')" role="button" tabindex="0" aria-label="<?php echo $is_owner ? 'Change banner and group picture' : 'View group photos'; ?>">
        <span class="gr-pic"><img src="<?php echo htmlspecialchars($img_src); ?>" alt="" onerror="this.src='uploads/default_group.png'"><?php if ($is_owner): ?><span class="gr-cam"><i class="ri-camera-line"></i></span><?php endif; ?></span>
        <div class="g-body">
            <div class="g-name"><?php echo htmlspecialchars($group['title']); ?></div>
            <div class="g-sub">
                <span><?php echo $is_private_group ? 'Private' : 'Public'; ?> · <?php echo number_format($member_count); ?> member<?php echo $member_count==1?'':'s'; ?></span>
                <span class="role-pill"><i class="ri-<?php echo $is_owner ? 'vip-crown-line' : 'shield-check-line'; ?>"></i> <?php echo $is_owner ? 'Owner' : 'Admin'; ?></span>
                <?php if ($is_paused): ?><span class="role-pill warn"><i class="ri-pause-circle-line"></i> Paused</span><?php endif; ?>
            </div>
            <div class="gr-hint"><i class="ri-image-edit-line"></i> <?php echo $is_owner ? 'Tap to change banner &amp; group picture' : 'Tap to view banner &amp; group picture'; ?></div>
        </div>
        <i class="ri-arrow-right-s-line chevron"></i>
    </div>

    <!-- ══ ACTION ITEMS ══ -->
    <div class="section-title">Action items</div>
    <div class="list-section">
        <?php if ($is_private_group) gc_row('ri-user-add-line', 'Join Requests', 'People asking to join, with their answers', 'modal-requests', $pending_request_count); ?>
        <?php if ($prob_posts > 0 || $pending_post_count > 0) gc_row('ri-time-line', 'Pending Posts', 'New member posts waiting for your approval', 'modal-pending', $pending_post_count); ?>
        <?php gc_row('ri-flag-line', 'Reported Posts', 'Posts flagged by members', 'modal-reports', $reported_post_count); ?>
    </div>

    <!-- ══ COMMUNITY ══ -->
    <div class="section-title">Community</div>
    <div class="list-section">
        <?php
        gc_row('ri-team-line', 'Members', number_format($member_count) . ' in this group', 'modal-members');
        gc_row('ri-shield-star-line', 'Admins', count($admins) . ' managing this group', 'modal-admins');
        gc_row('ri-hammer-line', 'Moderators', count($moderators) . ' removing rule-breaking posts', 'modal-mods');
        gc_row('ri-user-forbid-line', 'Banned Members', $banned_count . ' banned from this group', 'modal-banned');
        gc_row('ri-hashtag', 'Pod Hashtags', count($group_hashtags) . ' hashtag' . (count($group_hashtags)==1?'':'s') . ' for members to filter by', 'modal-hashtags');
        ?>
    </div>

    <!-- ══ HIGHLIGHTS ══ -->
    <div class="section-title">Highlights</div>
    <div class="list-section">
        <?php gc_row('ri-trophy-line', 'Group Highlights & Polls', $gh_count . ' highlight' . ($gh_count==1?'':'s') . ' · ' . $gh_poll_total . ' poll' . ($gh_poll_total==1?'':'s') . ' · ' . number_format($gh_vote_total) . ' vote' . ($gh_vote_total==1?'':'s'), 'modal-highlights', $gh_count); ?>
    </div>

    <!-- ══ MODERATION TOOLS ══ -->
    <div class="section-title">Moderation Tools</div>
    <div class="list-section">
        <?php
        gc_row('ri-spam-2-line', 'Auto-moderation', count($am_words) ? count($am_words) . ' banned word' . (count($am_words)==1?'':'s') . ' · ' . ($am_mode === 'report' ? 'send to reports' : 'block posts') : 'Off · no banned words', 'modal-automod');
        gc_row('ri-timer-line', 'Slow Mode', $slow_secs > 0 ? 'One post every ' . gc_dur_label($slow_secs) : 'Off', 'modal-slowmode');
        gc_row('ri-alert-line', 'Warnings', count($warn_rows) ? count($warn_rows) . ' member' . (count($warn_rows)==1?'':'s') . ' with strikes · ' . ($warn_limit > 0 ? 'limit ' . $warn_limit : 'no limit') : 'No active warnings · ' . ($warn_limit > 0 ? 'limit ' . $warn_limit : 'no limit'), 'modal-warnings', count($warn_rows) ?: null);
        gc_row('ri-user-search-line', 'New Member Probation', $prob_posts > 0 ? 'First ' . $prob_posts . ' post' . ($prob_posts==1?'':'s') . ' of a new member need approval' : 'Off', 'modal-probation');
        ?>
    </div>

    <!-- ══ ROLES ══ -->
    <div class="section-title">Roles</div>
    <div class="list-section">
        <?php gc_row('ri-user-settings-line', 'Pod Roles', 'What owners and admins can each do', 'modal-roles'); ?>
    </div>

    <!-- ══ GROUP SETTINGS ══ -->
    <div class="section-title">Group Settings</div>
    <div class="list-section">
        <?php
        gc_row('ri-align-left', 'Description', $desc_preview, 'modal-desc');
        gc_row('ri-list-check-2', 'Group Rules', $rules_preview, 'modal-rules');
        ?>
    </div>

    <!-- ══ SUPPORT ══ -->
    <div class="section-title">Support</div>
    <div class="list-section">
        <?php
        gc_row('ri-history-line', 'Activity Log', 'Recent moderation actions in this group', 'modal-activity');
        gc_row('ri-bar-chart-2-line', 'Insights & Analytics', 'How this group is growing and performing', 'modal-insights');
        gc_row('ri-question-line', 'Help Centre', 'Guides and answers for group admins', 'modal-help');
        gc_row('ri-feedback-line', 'Feedback', 'Tell us how we can improve group tools', 'modal-feedback');
        ?>
    </div>

    <?php if ($is_owner): ?>
    <!-- ══ OWNER SETTINGS ══ -->
    <div class="section-title">Owner Settings</div>
    <div class="list-section">
        <?php
        gc_row('ri-text', 'Change Group Name', $group['title'], 'modal-name');
        gc_row('ri-map-pin-line', 'Location', $location_summary, 'modal-location');
        gc_row('ri-user-shared-line', 'Transfer Ownership', count($admins) ? 'Hand this group to one of your ' . count($admins) . ' admin' . (count($admins)==1?'':'s') : 'Appoint an admin first', 'modal-transfer');
        gc_row('ri-price-tag-3-line', 'Pod Category', $pod_category_display, 'modal-category');
        gc_row('ri-error-warning-line', '18+ Content', 'Currently ' . ($is_adult_group ? 'enabled' : 'disabled'), 'modal-adult');
        gc_row('ri-lock-line', 'Group Privacy', 'Currently ' . ($is_private_group ? 'private' : 'public') . ' · ' . ($join_q_count ? $join_q_count . ' join question' . ($join_q_count==1?'':'s') : 'no join questions'), 'modal-privacy');
        ?>
    </div>
    <?php endif; ?>

    <!-- ══ DANGER ZONE ══ -->
    <div class="section-title">Danger Zone</div>
    <div class="list-section">
        <?php gc_row('ri-pause-circle-line', $is_paused ? 'Resume Group' : 'Pause Group', $is_paused ? 'Currently paused. Tap to resume activity' : 'Temporarily freeze posting, reactions & joining', 'modal-pause', $is_paused ? 'Paused' : null, true, 'background:var(--warn);'); ?>
        <?php if ($is_owner): ?>
        <form method="POST" action="group.php?id=<?php echo $group_id; ?>" onsubmit="return confirm(<?php echo gc_confirm_js('⚠️ Delete this group forever? This cannot be undone.'); ?>)">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <button type="submit" name="delete_group" class="list-row danger">
                <div class="row-icon"><i class="ri-delete-bin-line"></i></div>
                <div class="row-body"><div class="row-title">Delete Group Permanently</div><div class="row-sub">Removes this group and all its content for everyone</div></div>
            </button>
        </form>
        <?php endif; ?>
    </div>

</div><!-- /.ac-lane -->

<!-- ═════════ RIGHT COLUMN (laptop view): what this page is about ═════════ -->
<aside class="mp-side" aria-label="About group controls">
  <div class="gs-cards">

    <div class="gs-card" data-k="about">
      <button type="button" class="gs-bar" onclick="gsToggle(this)" aria-expanded="true"><i class="ri-shield-star-line gs-bar-ic"></i><span class="gs-bar-t">About Group Settings</span><i class="ri-arrow-down-s-line gs-bar-chev"></i></button>
      <div class="gs-body"><div class="gs-inner"><div class="gs-pad">
        <p class="gs-p">This is the control room for <b><?php echo htmlspecialchars($group['title']); ?></b>. Everything you change here updates the group page for every member straight away.</p>
        <div class="gs-callout"><i class="ri-information-line"></i><span>You are signed in as <b><?php echo $is_owner ? 'the owner' : 'an admin'; ?></b>. <?php echo $is_owner ? 'Some settings are yours alone, like privacy, join questions, location and deleting the group.' : 'Settings that define the group itself stay with the owner.'; ?></span></div>
      </div></div></div>
    </div>

    <div class="gs-card" data-k="attention">
      <button type="button" class="gs-bar" onclick="gsToggle(this)" aria-expanded="true"><i class="ri-notification-3-line gs-bar-ic"></i><span class="gs-bar-t">Needs your attention</span><i class="ri-arrow-down-s-line gs-bar-chev"></i></button>
      <div class="gs-body"><div class="gs-inner"><div class="gs-pad">
        <?php if ($is_private_group): ?>
        <button type="button" class="gs-topic" onclick="openModal('modal-requests')"><span class="gs-ti"><i class="ri-user-add-line"></i></span><span class="gs-tx"><span class="gs-tn"><b>Join requests</b><small class="<?php echo $pending_request_count ? '' : 'zero'; ?>"><?php echo $pending_request_count; ?></small></span><span class="gs-td">Read answers, then approve or deny.</span></span></button>
        <?php endif; ?>
        <button type="button" class="gs-topic" onclick="openModal('modal-reports')"><span class="gs-ti"><i class="ri-flag-line"></i></span><span class="gs-tx"><span class="gs-tn"><b>Reported posts</b><small class="<?php echo $reported_post_count ? '' : 'zero'; ?>"><?php echo $reported_post_count; ?></small></span><span class="gs-td">Posts members flagged for review.</span></span></button>
        <button type="button" class="gs-topic" onclick="openModal('modal-activity')"><span class="gs-ti"><i class="ri-history-line"></i></span><span class="gs-tx"><span class="gs-tn"><b>Activity log</b></span><span class="gs-td">See what your team did recently.</span></span></button>
      </div></div></div>
    </div>

    <div class="gs-card" data-k="flow">
      <button type="button" class="gs-bar" onclick="gsToggle(this)" aria-expanded="true"><i class="ri-route-line gs-bar-ic"></i><span class="gs-bar-t">A simple moderation routine</span><i class="ri-arrow-down-s-line gs-bar-chev"></i></button>
      <div class="gs-body"><div class="gs-inner"><div class="gs-pad">
        <div class="gs-step"><span class="gs-sn">1</span><span class="gs-st"><b>Clear the queue</b><span>Start with join requests and reports so nobody waits long.</span></span></div>
        <div class="gs-step"><span class="gs-sn">2</span><span class="gs-st"><b>Act in proportion</b><span>Dismiss a mistake, kick for a reset, ban only when it keeps happening.</span></span></div>
        <div class="gs-step"><span class="gs-sn">3</span><span class="gs-st"><b>Keep the group lively</b><span>Refresh highlights, run a poll, and keep hashtags tidy.</span></span></div>
      </div></div></div>
    </div>

    <div class="gs-card" data-k="tips">
      <button type="button" class="gs-bar" onclick="gsToggle(this)" aria-expanded="true"><i class="ri-lightbulb-line gs-bar-ic"></i><span class="gs-bar-t">Tips for a healthy group</span><i class="ri-arrow-down-s-line gs-bar-chev"></i></button>
      <div class="gs-body"><div class="gs-inner"><div class="gs-pad">
        <div class="gs-rule"><i class="ri-check-line"></i><span>Keep rules short and specific. One idea per rule bar.</span></div>
        <div class="gs-rule"><i class="ri-check-line"></i><span>Treat every report the same way, whoever posted it.</span></div>
        <div class="gs-rule"><i class="ri-check-line"></i><span>Private group? A couple of friendly join questions filter spam fast.</span></div>
        <div class="gs-rule"><i class="ri-check-line"></i><span>Use Pause when things get heated. Nothing is deleted.</span></div>
      </div></div></div>
    </div>

  </div>
</aside>

</div><!-- /.mp-shell -->

<!-- ══════════════════════════════════════════════
     MODALS  (all share the same centered lane)
     ══════════════════════════════════════════════ -->

<!-- Group Photos -->
<div class="modal-overlay" id="modal-profile"><div class="modal-lane">
    <div class="ac-topbar modal-topbar"><button type="button" class="ac-x" onclick="closeModal('modal-profile')" aria-label="Close"><i class="ri-close-line"></i></button><div class="ac-title">Banner &amp; Group Picture</div></div>
    <div class="modal-body">
        <?php if (!$is_owner): ?><div class="m-info"><i class="ri-lock-line"></i><span>Only the group owner can update photos.</span></div><?php endif; ?>
        <div class="m-sec">
            <div class="m-label"><i class="ri-landscape-line"></i> Banner picture</div>
            <div class="preview-box"><?php if (!empty($group['banner_pic'])): ?><img src="<?php echo htmlspecialchars($banner_src); ?>" alt="" onerror="this.style.display='none'"><?php else: ?><span class="preview-empty">No banner set</span><?php endif; ?></div>
            <?php if ($is_owner): ?>
            <form method="POST" action="group.php?id=<?php echo $group_id; ?>" enctype="multipart/form-data">
                <div class="form-row">
                    <label class="file-label"><i class="ri-upload-cloud-line"></i> <span>Choose banner</span><input type="file" name="banner_pic" accept="image/*"></label>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <button class="gc-btn" type="submit" name="change_banner_pic">Save</button>
                </div>
            </form>
            <?php endif; ?>
        </div>
        <div class="m-sec">
            <div class="m-label"><i class="ri-image-2-line"></i> Group picture</div>
            <div class="preview-box"><?php if (!empty($group['group_pic'])): ?><img src="<?php echo htmlspecialchars($img_src); ?>" alt="" onerror="this.style.display='none'"><?php else: ?><span class="preview-empty">No group picture set</span><?php endif; ?></div>
            <?php if ($is_owner): ?>
            <form method="POST" action="group.php?id=<?php echo $group_id; ?>" enctype="multipart/form-data">
                <div class="form-row">
                    <label class="file-label"><i class="ri-upload-cloud-line"></i> <span>Choose picture</span><input type="file" name="group_pic" accept="image/*"></label>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <button class="gc-btn" type="submit" name="change_group_pic">Save</button>
                </div>
            </form>
            <?php endif; ?>
        </div>
        <p class="m-hint">No banner? Your group picture is used instead. Square pictures look best.</p>
    </div>
</div></div>

<!-- Join Requests -->
<?php if ($is_private_group): ?>
<div class="modal-overlay" id="modal-requests"><div class="modal-lane">
    <div class="ac-topbar modal-topbar"><button type="button" class="ac-x" onclick="closeModal('modal-requests')" aria-label="Close"><i class="ri-close-line"></i></button><div class="ac-title">Join Requests</div></div>
    <div class="modal-body">
        <div class="m-label"><i class="ri-user-add-line"></i> Waiting for approval <span class="m-count"><?php echo $pending_request_count; ?></span></div>
        <div class="entry-list">
        <?php if (!empty($pending_join_requests)): foreach ($pending_join_requests as $req):
            $req_pic = !empty($req['profile_pic']) ? $req['profile_pic'] : 'mee.jpg';
            $deny_msg = 'Deny ' . $req['username'] . "'s request to join?";
            $ans = $request_answers[(int)$req['user_id']] ?? [];
        ?>
        <div class="req-card">
            <div class="row-item">
                <img class="av" src="<?php echo htmlspecialchars($req_pic); ?>" alt="" onerror="this.src='mee.jpg'">
                <div class="row-info">
                    <div class="row-name"><?php echo htmlspecialchars($req['username']); ?></div>
                    <div class="row-desc-sub">Requested <?php echo date('d M Y', strtotime($req['requested_at'])); ?></div>
                </div>
                <div class="row-actions">
                    <form method="POST" action="group.php?id=<?php echo $group_id; ?>">
                        <input type="hidden" name="request_user_id" value="<?php echo (int)$req['user_id']; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <button type="submit" name="approve_request" class="tiny-btn approve"><i class="ri-check-line"></i> Approve</button>
                    </form>
                    <form method="POST" action="group.php?id=<?php echo $group_id; ?>">
                        <input type="hidden" name="request_user_id" value="<?php echo (int)$req['user_id']; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <button type="submit" name="deny_request" class="tiny-btn kick" onclick="return confirm(<?php echo gc_confirm_js($deny_msg); ?>)"><i class="ri-close-line"></i> Deny</button>
                    </form>
                </div>
            </div>
            <?php if ($ans): ?>
            <details class="qa-box" <?php echo count($pending_join_requests) === 1 ? 'open' : ''; ?>>
                <summary><i class="ri-questionnaire-line"></i> Their answers (<?php echo count($ans); ?>)<i class="ri-arrow-down-s-line caret"></i></summary>
                <div class="qa-list">
                    <?php foreach ($ans as $qa): ?>
                    <div class="qa-item"><div class="qa-q"><?php echo htmlspecialchars($qa['q']); ?></div><div class="qa-a"><?php echo htmlspecialchars($qa['a']); ?></div></div>
                    <?php endforeach; ?>
                </div>
            </details>
            <?php elseif ($join_q_count): ?>
            <div class="qa-none"><?php echo !empty($GLOBALS['jq_last_error']) ? htmlspecialchars($GLOBALS['jq_last_error']) : 'No answers were saved with this request.'; ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; else: ?>
        <div class="empty-state"><div class="es-title">All caught up</div><p class="es-text">Nobody is waiting to join. New requests, with any answers to your join questions, will show up here.</p><div class="es-tips"><div class="es-tip"><i class="ri-question-answer-line"></i><span>Answers to your join questions appear with each request.</span></div><div class="es-tip"><i class="ri-check-double-line"></i><span>Owner and admins can both approve or deny.</span></div></div><button type="button" class="es-btn" onclick="closeModal('modal-requests')"><i class="ri-close-line"></i> Close</button></div>
        <?php endif; ?>
        </div>
        <p class="m-hint">Owner and admins can both approve or deny. Decisions are saved in the Activity Log. Switching to public auto-approves anyone still waiting.</p>
    </div>
</div></div>
<?php endif; ?>

<!-- Reported Posts -->
<div class="modal-overlay" id="modal-reports"><div class="modal-lane">
    <div class="ac-topbar modal-topbar"><button type="button" class="ac-x" onclick="closeModal('modal-reports')" aria-label="Close"><i class="ri-close-line"></i></button><div class="ac-title">Reported Posts</div></div>
    <div class="modal-body">
        <div class="m-label"><i class="ri-flag-line"></i> Open reports <span class="m-count"><?php echo $reported_post_count; ?></span></div>
        <div class="entry-list">
        <?php if (!empty($reported_posts)): foreach ($reported_posts as $rp):
            $rp_thumb = (!empty($rp['file_name']) && $rp['file_name']!=='0') ? 'uploads/'.$rp['file_name'] : '';
            $rp_cap = mb_strlen($rp['caption']) > 50 ? mb_substr($rp['caption'],0,50).'…' : $rp['caption'];
            $del_msg = 'Delete this reported post by '.$rp['poster_username']."?\nThis cannot be undone.";
            $dis_msg = 'Dismiss this report? The post will stay up.';
        ?>
        <div class="row-item" style="align-items:flex-start;">
            <?php if ($rp_thumb): ?>
            <img class="av" src="<?php echo htmlspecialchars($rp_thumb); ?>" alt="" onerror="this.src='uploads/Text.jpg'">
            <?php else: ?>
            <div style="width:36px;height:36px;border-radius:50%;flex-shrink:0;background:var(--surface);display:flex;align-items:center;justify-content:center;border:2px solid var(--accent-border);"><i class="ri-file-text-line" style="color:var(--muted-2);"></i></div>
            <?php endif; ?>
            <div class="row-info">
                <div class="row-name"><?php echo $rp_cap!=='' ? htmlspecialchars($rp_cap) : '<em style="color:var(--muted-2);">(no caption)</em>'; ?></div>
                <div class="row-desc-sub">by <?php echo htmlspecialchars($rp['poster_username']); ?><?php if (!empty($rp['escalated'])): ?> <span style="display:inline-block;margin-left:6px;padding:1px 6px;border-radius:3px;font-size:9px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;background:rgba(255,45,45,.18);color:#ff6b6b;">Escalated by a moderator</span><?php endif; ?></div>
                <div class="report-reason"><i class="ri-flag-fill"></i> <?php echo htmlspecialchars($rp['reason']); ?></div>
                <?php if (!empty($rp['description'])): ?><div class="report-desc">"<?php echo htmlspecialchars(mb_strlen($rp['description'])>80 ? mb_substr($rp['description'],0,80).'…' : $rp['description']); ?>"</div><?php endif; ?>
            </div>
            <div class="row-actions" style="flex-direction:column;">
                <form method="POST" action="group.php?id=<?php echo $group_id; ?>" onsubmit="return confirm(<?php echo gc_confirm_js($del_msg); ?>)">
                    <input type="hidden" name="delete_post_id" value="<?php echo (int)$rp['group_post_id']; ?>">
                    <input type="hidden" name="delete_meme_id" value="<?php echo (int)$rp['meme_id']; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <button type="submit" name="delete_post" class="tiny-btn ban"><i class="ri-delete-bin-line"></i> Delete</button>
                </form>
                <form method="POST" action="group.php?id=<?php echo $group_id; ?>" onsubmit="return confirm(<?php echo gc_confirm_js($dis_msg); ?>)">
                    <input type="hidden" name="report_id" value="<?php echo (int)$rp['report_id']; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <button type="submit" name="dismiss_report" class="tiny-btn kick"><i class="ri-close-line"></i> Dismiss</button>
                </form>
            </div>
        </div>
        <?php endforeach; else: ?>
        <div class="empty-state"><div class="es-title">Nothing flagged</div><p class="es-text">No member has reported a post. Reports will land here for you to review.</p><div class="es-tips"><div class="es-tip"><i class="ri-flag-line"></i><span>Members can report any post from the group feed.</span></div><div class="es-tip"><i class="ri-history-line"></i><span>Every decision is saved in the Activity Log.</span></div></div><button type="button" class="es-btn" onclick="closeModal('modal-reports')"><i class="ri-close-line"></i> Close</button></div>
        <?php endif; ?>
        </div>
        <p class="m-hint"><b>Delete</b> removes the post for everyone. <b>Dismiss</b> closes the report and keeps the post. Both are logged.</p>
    </div>
</div></div>

<!-- Members -->
<div class="modal-overlay" id="modal-members"><div class="modal-lane">
    <div class="ac-topbar modal-topbar"><button type="button" class="ac-x" onclick="closeModal('modal-members')" aria-label="Close"><i class="ri-close-line"></i></button><div class="ac-title">Manage Members</div></div>
    <div class="modal-body">
        <div class="m-label"><i class="ri-team-line"></i> Members</div>
        <div class="entry-list">
        <?php if ($panel_members && $panel_members->num_rows > 0): while ($pm = $panel_members->fetch_assoc()):
            $pm_pic = !empty($pm['profile_pic']) ? $pm['profile_pic'] : 'mee.jpg';
            $pm_is_admin = isset($admins[$pm['id']]);
            $can_act = $is_owner || ($is_admin && !$pm_is_admin);
            $kick_msg = 'Kick '.$pm['username']."?\nThey can rejoin.";
            $ban_msg  = 'BAN '.$pm['username']."?\n".($is_owner ? 'Blocked from ALL your groups.' : 'Cannot rejoin THIS group.');
        ?>
        <div class="row-item">
            <img class="av" src="<?php echo htmlspecialchars($pm_pic); ?>" alt="" onerror="this.src='mee.jpg'">
            <div class="row-info">
                <div class="row-name"><?php echo htmlspecialchars($pm['username']); ?></div>
                <?php if ($pm_is_admin): ?><div class="row-desc-sub admin">ADMIN</div><?php endif; ?>
                <?php if (!empty($warn_counts[(int)$pm['id']])): ?><div class="row-desc-sub"><span class="strike-badge<?php echo ($warn_limit > 0 && $warn_counts[(int)$pm['id']] >= $warn_limit) ? ' hit' : ''; ?>"><?php echo (int)$warn_counts[(int)$pm['id']]; ?> strike<?php echo $warn_counts[(int)$pm['id']] == 1 ? '' : 's'; ?></span></div><?php endif; ?>
            </div>
            <?php if ($can_act): ?>
            <div class="row-actions">
                <button type="button" class="tiny-btn warn" data-uid="<?php echo (int)$pm['id']; ?>" data-name="<?php echo htmlspecialchars($pm['username'], ENT_QUOTES); ?>" onclick="gcWarn(this)"><i class="ri-alert-line"></i> Warn</button>
                <form method="POST" action="group.php?id=<?php echo $group_id; ?>">
                    <input type="hidden" name="remove_member_id" value="<?php echo $pm['id']; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <button type="submit" name="remove_member" class="tiny-btn kick" onclick="return confirm(<?php echo gc_confirm_js($kick_msg); ?>)"><i class="ri-logout-box-line"></i> Kick</button>
                </form>
                <form method="POST" action="group.php?id=<?php echo $group_id; ?>">
                    <input type="hidden" name="ban_user_id" value="<?php echo $pm['id']; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <button type="button" class="tiny-btn ban" data-name="<?php echo htmlspecialchars($pm['username'], ENT_QUOTES); ?>" data-scope="<?php echo $is_owner ? 'Blocked from ALL your groups.' : 'Cannot rejoin THIS group.'; ?>" onclick="gcBan(this)"><i class="ri-user-forbid-line"></i> Ban</button>
                </form>
            </div>
            <?php else: ?><span class="row-desc-sub" style="font-style:italic;">Owner only</span><?php endif; ?>
        </div>
        <?php endwhile; else: ?>
        <div class="empty-state"><div class="es-title">No one to manage yet</div><p class="es-text">Members who join will appear here so you can kick or ban them if needed.</p><div class="es-tips"><div class="es-tip"><i class="ri-user-add-line"></i><span>New members show up here as soon as they join.</span></div><div class="es-tip"><i class="ri-user-forbid-line"></i><span>You can kick or ban anyone who breaks the rules.</span></div></div><button type="button" class="es-btn" onclick="closeModal('modal-members')"><i class="ri-close-line"></i> Close</button></div>
        <?php endif; ?>
        </div>
        <p class="m-hint"><b>Kick</b> removes someone but they can rejoin. <b>Ban</b> keeps them out: an admin ban applies to this group, an owner ban to all of the owner's groups. Admins can't act on each other.</p>
    </div>
</div></div>

<!-- Admins -->
<?php
/* searchable member picker with profile pictures (used for Appoint admin / Appoint moderator) */
function gc_member_picker($conn, $group_id, $exclude_id, $field, $btn_name, $btn_label, $csrf, $tags) {
    static $n = 0; $n++;
    $id = 'mp' . $n;
    $rows = $conn->query("SELECT u.id, u.username, u.profile_pic FROM group_members gm JOIN users u ON u.id=gm.user_id WHERE gm.group_id=" . (int)$group_id . " AND gm.user_id<>" . (int)$exclude_id . " ORDER BY u.username ASC LIMIT 500");
    ?>
    <form method="POST" action="group.php?id=<?php echo (int)$group_id; ?>" class="mp" id="<?php echo $id; ?>">
        <div class="mp-search"><i class="ri-search-line"></i><input type="text" class="mp-q" placeholder="Search members" autocomplete="off" aria-label="Search members"></div>
        <div class="mp-list" role="listbox">
        <?php while ($rows && ($m = $rows->fetch_assoc())):
            $tag = $tags[(int)$m['id']] ?? ''; $pic = !empty($m['profile_pic']) ? $m['profile_pic'] : 'mee.jpg'; ?>
            <label class="mp-row<?php echo $tag ? ' off' : ''; ?>" data-n="<?php echo htmlspecialchars(strtolower($m['username'])); ?>">
                <input type="radio" name="<?php echo $field; ?>" value="<?php echo (int)$m['id']; ?>" <?php if ($tag) echo 'disabled'; ?>>
                <img src="<?php echo htmlspecialchars($pic); ?>" alt="" onerror="this.src='mee.jpg'">
                <span class="mp-name"><?php echo htmlspecialchars($m['username']); ?></span>
                <?php if ($tag): ?><span class="mp-tag"><?php echo htmlspecialchars($tag); ?></span><?php else: ?><i class="ri-check-line mp-ck"></i><?php endif; ?>
            </label>
        <?php endwhile; ?>
            <div class="mp-none" hidden>No members found</div>
        </div>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
        <button class="gc-btn mp-go" type="submit" name="<?php echo $btn_name; ?>" disabled><?php echo htmlspecialchars($btn_label); ?></button>
    </form>
    <?php
}
?>
<style>
.mp-search{position:relative;margin-bottom:8px}
.mp-search i{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:rgba(var(--fg),.45);font-size:.9rem}
.mp-search .mp-q{width:100%;height:var(--ctl-h);padding:0 12px 0 32px;border-radius:var(--r);border:1px solid var(--border);background:var(--surface);color:var(--text);font-size:.76rem;outline:none;font-family:inherit}
.mp-search .mp-q:focus{border-color:var(--accent-focus);box-shadow:0 0 0 3px var(--accent-ring)}
.mp-list{max-height:260px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--r);background:var(--surface);scrollbar-width:thin}
.mp-row{display:flex;align-items:center;gap:10px;padding:8px 10px;cursor:pointer;border-top:1px solid var(--border);transition:background .15s}
.mp-row:first-child{border-top:0}
.mp-row:hover{background:rgba(var(--fg),.06)}
.mp-row input{position:absolute;opacity:0;pointer-events:none}
.mp-row img{width:34px;height:34px;border-radius:50%;object-fit:cover;flex:none;border:2px solid #FF2D2D;background:rgba(var(--fg),.1)}
.mp-name{flex:1;min-width:0;font-size:.78rem;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mp-ck{display:none;color:#FF2D2D;font-size:1.1rem}
.mp-row:has(input:checked){background:rgba(255,45,45,.1)}
.mp-row:has(input:checked) .mp-ck{display:block}
.mp-row.off{opacity:.5;cursor:default}
.mp-tag{font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:rgba(var(--fg),.55)}
.mp-none{padding:18px;text-align:center;font-size:.72rem;color:rgba(var(--fg),.5)}
.mp-go{width:100%;margin-top:8px}
.mp-go:disabled{opacity:.45;cursor:default}
</style>
<script>
document.addEventListener('DOMContentLoaded',function(){
  document.querySelectorAll('form.mp').forEach(function(f){
    var q=f.querySelector('.mp-q'), rows=f.querySelectorAll('.mp-row'), none=f.querySelector('.mp-none'), go=f.querySelector('.mp-go');
    q.addEventListener('input',function(){
      var v=q.value.trim().toLowerCase(), n=0;
      rows.forEach(function(r){ var ok=!v||r.getAttribute('data-n').indexOf(v)!==-1; r.style.display=ok?'':'none'; if(ok)n++; });
      none.hidden=n>0;
    });
    f.addEventListener('change',function(){ go.disabled=!f.querySelector('input:checked'); if(window.dzHp) dzHp('selection'); });
  });
});
</script>
<div class="modal-overlay" id="modal-admins"><div class="modal-lane">
    <div class="ac-topbar modal-topbar"><button type="button" class="ac-x" onclick="closeModal('modal-admins')" aria-label="Close"><i class="ri-close-line"></i></button><div class="ac-title">Admins</div></div>
    <div class="modal-body">
        <?php if ($is_owner): ?>
        <div class="m-sec">
            <div class="m-label"><i class="ri-admin-line"></i> Appoint admin</div>
            <?php gc_member_picker($conn, $group_id, $current_user, 'appoint_user_id', 'appoint_admin', 'Appoint admin', $csrf_token, array_map(function ($x) { return 'Admin'; }, $admins) + [$group_creator_id => 'Owner']); ?>
        </div>
        <?php else: ?>
        <div class="m-info"><i class="ri-lock-line"></i><span>Only the group owner can appoint or remove admins.</span></div>
        <?php endif; ?>
        <div class="m-sec">
            <div class="m-label"><i class="ri-shield-star-line"></i> Current admins <span class="m-count"><?php echo count($admins); ?></span></div>
            <?php if (!empty($admins)): ?>
            <div class="chip-wrap" style="margin-top:0;">
                <?php foreach ($admins as $adm): ?>
                <div class="chip">
                    <img src="<?php echo !empty($adm['profile_pic']) ? htmlspecialchars($adm['profile_pic']) : 'mee.jpg'; ?>" alt="" onerror="this.src='mee.jpg'">
                    <?php echo htmlspecialchars($adm['username']); ?>
                    <?php if ($is_owner): ?>
                    <form method="POST" action="group.php?id=<?php echo $group_id; ?>" style="display:inline;">
                        <input type="hidden" name="remove_admin_id" value="<?php echo $adm['id']; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <button type="submit" name="remove_admin" title="Remove admin">×</button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-state"><div class="es-title">No admins yet</div><p class="es-text"><?php echo $is_owner ? "Appoint a trusted member above to help you moderate." : "Only the owner can appoint admins."; ?></p><div class="es-tips"><div class="es-tip"><i class="ri-shield-star-line"></i><span>Admins can approve requests and review reports.</span></div><div class="es-tip"><i class="ri-user-star-line"></i><span>Pick someone active and trusted.</span></div></div><button type="button" class="es-btn" onclick="closeModal('modal-admins')"><i class="ri-close-line"></i> Close</button></div>
            <?php endif; ?>
        </div>
        <p class="m-hint">See <b>Pod Roles</b> for exactly what admins can and can't do.</p>
    </div>
</div></div>

<!-- Moderators -->
<div class="modal-overlay" id="modal-mods"><div class="modal-lane">
    <div class="ac-topbar modal-topbar"><button type="button" class="ac-x" onclick="closeModal('modal-mods')" aria-label="Close"><i class="ri-close-line"></i></button><div class="ac-title">Moderators</div></div>
    <div class="modal-body">
        <div class="m-sec">
            <div class="m-label"><i class="ri-hammer-line"></i> Appoint moderator</div>
            <?php gc_member_picker($conn, $group_id, $group_creator_id, 'mod_user_id', 'appoint_mod', 'Appoint moderator', $csrf_token, array_map(function ($x) { return 'Admin'; }, $admins) + array_map(function ($x) { return 'Moderator'; }, $moderators)); ?>
        </div>
        <div class="m-sec">
            <div class="m-label"><i class="ri-hammer-line"></i> Current moderators <span class="m-count"><?php echo count($moderators); ?></span></div>
            <?php if (!empty($moderators)): ?>
            <div class="chip-wrap" style="margin-top:0;">
                <?php foreach ($moderators as $md): ?>
                <div class="chip">
                    <img src="<?php echo !empty($md['profile_pic']) ? htmlspecialchars($md['profile_pic']) : 'mee.jpg'; ?>" alt="" onerror="this.src='mee.jpg'">
                    <?php echo htmlspecialchars($md['username']); ?>
                    <form method="POST" action="group.php?id=<?php echo $group_id; ?>" style="display:inline;">
                        <input type="hidden" name="mod_user_id" value="<?php echo $md['id']; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <button type="submit" name="remove_mod" title="Remove moderator">×</button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-state"><div class="es-title">No moderators yet</div><p class="es-text">Appoint trusted members above to help remove inappropriate or rule-breaking posts.</p></div>
            <?php endif; ?>
        </div>
        <p class="m-hint">Moderators can remove posts that break the group's rules. They can't remove posts by the owner or admins, and can't change settings, kick, ban or approve members. Every removal is recorded in the Activity Log.</p>
    </div>
</div></div>

<!-- Banned Members -->
<div class="modal-overlay" id="modal-banned"><div class="modal-lane">
    <div class="ac-topbar modal-topbar"><button type="button" class="ac-x" onclick="closeModal('modal-banned')" aria-label="Close"><i class="ri-close-line"></i></button><div class="ac-title">Banned Members</div></div>
    <div class="modal-body">
        <div class="m-label"><i class="ri-user-forbid-line"></i> Banned <span class="m-count"><?php echo $banned_count; ?></span></div>
        <div class="entry-list">
        <?php if (!empty($visible_bans)): foreach ($visible_bans as $banned):
            $bp = !empty($banned['profile_pic']) ? $banned['profile_pic'] : 'mee.jpg';
            $is_owner_ban = isset($owner_bans[$banned['id']]);
            $is_group_ban = isset($group_ban_list[$banned['id']]);
            $can_unban = $is_owner || ($is_admin && $is_group_ban && !$is_owner_ban);
            $unban_msg = 'Unban '.$banned['username']."?\nThey can rejoin the group.";
        ?>
        <div class="row-item">
            <img class="av dim" src="<?php echo htmlspecialchars($bp); ?>" alt="" onerror="this.src='mee.jpg'">
            <div class="row-info">
                <div class="row-name" style="color:var(--sub);"><?php echo htmlspecialchars($banned['username']); ?></div>
                <div class="row-desc-sub"><?php echo ($is_owner_ban && $is_owner) ? '⚡ Creator ban: all your groups' : '🔒 Group ban: this group only'; ?></div>
                <?php $bn = $ban_notes[(int)$banned['id']] ?? ''; ?>
                <?php if ($bn !== ''): ?><div class="ban-note"><i class="ri-draft-line"></i><span><?php echo htmlspecialchars($bn); ?></span></div><?php else: ?><div class="ban-note none">No reason added</div><?php endif; ?>
            </div>
            <?php if ($can_unban): ?>
            <div class="row-actions">
            <button type="button" class="tiny-btn kick" data-uid="<?php echo (int)$banned['id']; ?>" data-name="<?php echo htmlspecialchars($banned['username'], ENT_QUOTES); ?>" data-reason="<?php echo htmlspecialchars($bn, ENT_QUOTES); ?>" onclick="gcBanNote(this)"><i class="ri-edit-line"></i> Reason</button>
            <form method="POST" action="group.php?id=<?php echo $group_id; ?>">
                <input type="hidden" name="unban_user_id" value="<?php echo (int)$banned['id']; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <button type="submit" name="unban_member" class="tiny-btn approve" onclick="return confirm(<?php echo gc_confirm_js($unban_msg); ?>)"><i class="ri-user-follow-line"></i> Unban</button>
            </form>
            </div>
            <?php else: ?><span class="row-desc-sub" style="font-style:italic;">Creator ban only</span><?php endif; ?>
        </div>
        <?php endforeach; else: ?>
        <div class="empty-state"><div class="es-title">Clean record</div><p class="es-text">Nobody is banned from this group.</p><div class="es-tips"><div class="es-tip"><i class="ri-shield-check-line"></i><span>Banned members can no longer see or join this group.</span></div><div class="es-tip"><i class="ri-arrow-go-back-line"></i><span>You can lift a ban at any time.</span></div></div><button type="button" class="es-btn" onclick="closeModal('modal-banned')"><i class="ri-close-line"></i> Close</button></div>
        <?php endif; ?>
        </div>
        <p class="m-hint">Admins can lift group bans. Only the owner can undo a creator ban.</p>
    </div>
</div></div>

<!-- Hashtags -->
<div class="modal-overlay" id="modal-hashtags"><div class="modal-lane">
    <div class="ac-topbar modal-topbar"><button type="button" class="ac-x" onclick="closeModal('modal-hashtags')" aria-label="Close"><i class="ri-close-line"></i></button><div class="ac-title">Pod Hashtags</div></div>
    <div class="modal-body">
        <div class="m-sec">
            <div class="m-label"><i class="ri-filter-3-line"></i> Add a hashtag</div>
            <form method="POST" action="group.php?id=<?php echo $group_id; ?>">
                <div class="form-row">
                    <input class="gc-input" type="text" name="new_hashtag" placeholder="e.g. memes (no # needed)" maxlength="30">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <button class="gc-btn" type="submit" name="add_group_hashtag">Add</button>
                </div>
            </form>
        </div>
        <div class="m-sec">
            <div class="m-label"><i class="ri-hashtag"></i> Current hashtags <span class="m-count"><?php echo count($group_hashtags); ?></span></div>
            <?php if (!empty($group_hashtags)): ?>
            <div class="chip-wrap" style="margin-top:0;">
                <?php foreach ($group_hashtags as $tag): ?>
                <div class="chip">#<?php echo htmlspecialchars($tag['tag']); ?>
                    <form method="POST" action="group.php?id=<?php echo $group_id; ?>" style="display:inline;">
                        <input type="hidden" name="remove_tag_id" value="<?php echo (int)$tag['id']; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <button type="submit" name="remove_group_hashtag" title="Remove tag">×</button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-state"><div class="es-title">No hashtags set</div><p class="es-text">Add hashtags so members can filter this group's posts by topic.</p><div class="es-tips"><div class="es-tip"><i class="ri-hashtag"></i><span>Hashtags help members filter posts by topic.</span></div><div class="es-tip"><i class="ri-edit-line"></i><span>Add them using the box above.</span></div></div><button type="button" class="es-btn" onclick="closeModal('modal-hashtags')"><i class="ri-close-line"></i> Close</button></div>
            <?php endif; ?>
        </div>
        <p class="m-hint">Keep them short (30 characters max) and easy to guess.</p>
    </div>
</div></div>

<!-- Pod Roles -->
<div class="modal-overlay" id="modal-roles"><div class="modal-lane">
    <div class="ac-topbar modal-topbar"><button type="button" class="ac-x" onclick="closeModal('modal-roles')" aria-label="Close"><i class="ri-close-line"></i></button><div class="ac-title">Pod Roles</div></div>
    <div class="modal-body">
        <div class="m-info"><i class="ri-information-line"></i><span>This pod is run by an <b>owner</b> and optional <b>admins</b>. Tap a role to see what it can and can't do.</span></div>
        <div class="role-pick-row" onclick="openModal('modal-role-owner')">
            <div class="role-pick-icon"><i class="ri-vip-crown-line"></i></div>
            <div class="role-pick-body"><div class="role-pick-title">Owner role details</div><div class="role-pick-sub">Full control, the only role that can rename, delete or hand out admin powers</div></div>
            <i class="ri-arrow-right-s-line chevron"></i>
        </div>
        <div class="role-pick-row" onclick="openModal('modal-role-admin')">
            <div class="role-pick-icon"><i class="ri-shield-check-line"></i></div>
            <div class="role-pick-body"><div class="role-pick-title">Admin role details</div><div class="role-pick-sub"><?php echo count($admins); ?> admin<?php echo count($admins)==1?'':'s'; ?> · day-to-day moderation powers</div></div>
            <i class="ri-arrow-right-s-line chevron"></i>
        </div>
    </div>
</div></div>

<!-- Owner role details -->
<div class="modal-overlay" id="modal-role-owner"><div class="modal-lane">
    <div class="ac-topbar modal-topbar"><button type="button" class="ac-x" onclick="closeModal('modal-role-owner')" aria-label="Close"><i class="ri-close-line"></i></button><div class="ac-title">Owner Role Details</div></div>
    <div class="modal-body">
        <div class="role-profile-card">
            <img src="<?php echo htmlspecialchars($owner_profile_pic); ?>" alt="" onerror="this.src='mee.jpg'">
            <div><div class="role-profile-name"><?php echo htmlspecialchars($owner_username); ?></div><div class="role-profile-sub"><i class="ri-vip-crown-fill" style="color:#FFD700;"></i> Owner of this pod</div></div>
        </div>
        <div class="m-label"><i class="ri-shield-star-line"></i> What the owner can do</div>
        <div class="perm-list">
            <?php
            $owner_perms = [
                ['Change group photos', 'Upload a new banner and group picture.'],
                ['Rename the group', 'Only the owner can change the pod\'s name.'],
                ['Set group location', 'Search and set where the group is based.'],
                ['Transfer ownership', 'Hand the group to one of the admins.'],
                ['Toggle 18+ content', 'Mark or unmark this group as adult.'],
                ['Toggle Public / Private', 'Decide whether new members need approval.'],
                ['Set join questions', 'Up to 10 optional questions requesters can answer.'],
                ['Delete the group permanently', 'Removes the group and its content. Cannot be undone.'],
                ['Appoint or remove admins', 'Only the owner decides who gets admin powers.'],
                ['Act against other admins', 'Can kick or ban a fellow admin.'],
                ['Account-wide bans', 'A ban from the owner blocks that person from all of the owner\'s groups.'],
                ['Undo any ban', 'Group bans and the owner\'s own account-wide bans.'],
                ['Everything admins can do', 'Requests, reports, hashtags, description, rules, highlights & polls, member moderation.'],
            ];
            foreach ($owner_perms as $p): ?>
            <div class="perm-item yes"><i class="ri-checkbox-circle-fill"></i><div><div class="perm-item-title"><?php echo htmlspecialchars($p[0]); ?></div><div class="perm-item-sub"><?php echo htmlspecialchars($p[1]); ?></div></div></div>
            <?php endforeach; ?>
        </div>
    </div>
</div></div>

<!-- Admin role details -->
<div class="modal-overlay" id="modal-role-admin"><div class="modal-lane">
    <div class="ac-topbar modal-topbar"><button type="button" class="ac-x" onclick="closeModal('modal-role-admin')" aria-label="Close"><i class="ri-close-line"></i></button><div class="ac-title">Admin Role Details</div></div>
    <div class="modal-body">
        <div class="role-tabs">
            <button type="button" class="role-tab-btn active" id="roleTabBtnAdmins" onclick="switchRoleTab('admins')">Admins (<?php echo count($admins); ?>)</button>
            <button type="button" class="role-tab-btn" id="roleTabBtnDetails" onclick="switchRoleTab('details')">Role details</button>
        </div>
        <div class="role-tab-panel active" id="roleTabPanelAdmins">
            <?php if (!empty($admins)): ?>
            <div class="entry-list">
                <?php foreach ($admins as $adm): ?>
                <div class="row-item">
                    <img class="av" src="<?php echo !empty($adm['profile_pic']) ? htmlspecialchars($adm['profile_pic']) : 'mee.jpg'; ?>" alt="" onerror="this.src='mee.jpg'">
                    <div class="row-info"><div class="row-name"><?php echo htmlspecialchars($adm['username']); ?></div><div class="row-desc-sub admin"><i class="ri-shield-check-fill"></i> Admin</div></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-state"><div class="es-title">No admins appointed</div><p class="es-text"><?php echo $is_owner ? "Appoint one from the Admins section on the main page." : "Only the owner can appoint admins."; ?></p><div class="es-tips"><div class="es-tip"><i class="ri-shield-star-line"></i><span>Admins help moderate day to day.</span></div><div class="es-tip"><i class="ri-user-settings-line"></i><span>Appoint them from the Admins section.</span></div></div><button type="button" class="es-btn" onclick="closeModal('modal-role-admin')"><i class="ri-close-line"></i> Close</button></div>
            <?php endif; ?>
        </div>
        <div class="role-tab-panel" id="roleTabPanelDetails">
            <div class="perm-list">
                <?php
                $admin_perms = [
                    [1, 'Approve or deny join requests', 'Admins can read requesters\' answers and decide.'],
                    [1, 'Review reported posts', 'Delete a reported post or dismiss the report.'],
                    [1, 'Kick or ban regular members', 'Admin bans only apply to this group.'],
                    [1, 'Create & manage highlights and polls', 'Build highlights and see live vote counts.'],
                    [1, 'Manage pod hashtags', 'Add or remove filter hashtags.'],
                    [1, 'Edit description & group rules', 'Keep the group\'s info and rule bars up to date.'],
                    [1, 'Undo group bans', 'Unban people banned at the group level.'],
                    [1, 'Warn members and manage strikes', 'Give strikes with a reason, forgive them, and set the strike limit.'],
                    [1, 'Run new member probation', 'Approve or reject the first posts of new members.'],
                    [1, 'Set up auto-moderation', 'Manage the banned word list and what happens to matching posts.'],
                    [1, 'Control slow mode', 'Set how often members can post.'],
                    [1, 'Add a reason when banning', 'Notes are shown to owner and admins in the Banned list.'],
                    [0, 'Change photos, name, location, 18+, privacy or join questions', 'Locked to the owner.'],
                    [0, 'Delete the group', 'Owner only.'],
                    [0, 'Transfer ownership', 'Only the owner can hand the group over.'],
                    [0, 'Appoint or remove admins', 'Including themselves.'],
                    [0, 'Act against another admin', 'Admins only moderate regular members.'],
                    [0, 'Undo the owner\'s account-wide bans', 'Only the owner can lift those.'],
                ];
                foreach ($admin_perms as $p): ?>
                <div class="perm-item <?php echo $p[0] ? 'yes' : 'no'; ?>"><i class="<?php echo $p[0] ? 'ri-checkbox-circle-fill' : 'ri-close-circle-fill'; ?>"></i><div><div class="perm-item-title"><?php echo htmlspecialchars($p[1]); ?></div><div class="perm-item-sub"><?php echo htmlspecialchars($p[2]); ?></div></div></div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div></div>

<!-- Description -->
<div class="modal-overlay" id="modal-desc"><div class="modal-lane">
    <div class="ac-topbar modal-topbar"><button type="button" class="ac-x" onclick="closeModal('modal-desc')" aria-label="Close"><i class="ri-close-line"></i></button><div class="ac-title">Description</div></div>
    <div class="modal-body">
        <form method="POST" action="group.php?id=<?php echo $group_id; ?>">
            <div class="m-label"><i class="ri-align-left"></i> Group description</div>
            <input class="gc-input" type="text" name="new_desc" placeholder="Update description..." value="<?php echo htmlspecialchars($group['description'] ?? ''); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <button class="gc-btn wide" type="submit" name="change_group_desc"><i class="ri-save-line"></i> Update Description</button>
        </form>
        <p class="m-hint">The first thing people read when deciding to join. Say what the group is about and who it's for.</p>
    </div>
</div></div>

<!-- Group Rules (each rule is one bar) -->
<div class="modal-overlay" id="modal-rules"><div class="modal-lane">
    <div class="ac-topbar modal-topbar"><button type="button" class="ac-x" onclick="closeModal('modal-rules')" aria-label="Close"><i class="ri-close-line"></i></button><div class="ac-title">Group Rules</div></div>
    <div class="modal-body">
        <form method="POST" action="group.php?id=<?php echo $group_id; ?>" onsubmit="return rlSubmit(this)">
            <div class="m-label"><i class="ri-list-check-2"></i> Your rules <span class="m-count" id="rl-count">0/<?php echo GC_MAX_RULES; ?></span></div>
            <div id="rl-list"></div>
            <button type="button" class="add-bar" id="rl-add" onclick="rlAdd()"><i class="ri-add-line"></i> Add a rule</button>
            <div class="bar-status" id="rl-status" aria-live="polite"></div>
            <input type="hidden" name="custom_guidelines" id="rl-value" value="">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <button class="gc-btn wide" type="submit" name="save_guidelines"><i class="ri-save-line"></i> Save Rules</button>
        </form>
        <p class="m-hint">One rule per bar, up to <?php echo GC_MAX_RULES; ?> rules of <?php echo GC_RULE_LEN; ?> characters each. Think "stay on topic" or "credit the creator". Empty bars are ignored when you save.</p>
    </div>
</div></div>

<!-- Activity Log -->
<div class="modal-overlay" id="modal-activity"><div class="modal-lane">
    <div class="ac-topbar modal-topbar"><button type="button" class="ac-x" onclick="closeModal('modal-activity')" aria-label="Close"><i class="ri-close-line"></i></button><div class="ac-title">Activity Log</div></div>
    <div class="modal-body">
        <?php if (empty($activity_log)): ?>
        <div class="empty-state"><div class="es-title">No activity yet</div><p class="es-text">Every kick, ban, appointment and settings change by you or your admins will show up here.</p><div class="es-tips"><div class="es-tip"><i class="ri-shield-user-line"></i><span>Kicks, bans and appointments are logged here.</span></div><div class="es-tip"><i class="ri-settings-3-line"></i><span>Settings changes are recorded too.</span></div></div><button type="button" class="es-btn" onclick="closeModal('modal-activity')"><i class="ri-close-line"></i> Close</button></div>
        <?php else: ?>
        <div class="act-filters" id="actFilters">
            <button type="button" class="act-f active" data-f="all" onclick="actFilter(this,'all')">All</button>
            <button type="button" class="act-f" data-f="members" onclick="actFilter(this,'members')">Members</button>
            <button type="button" class="act-f" data-f="posts" onclick="actFilter(this,'posts')">Posts</button>
            <button type="button" class="act-f" data-f="settings" onclick="actFilter(this,'settings')">Settings</button>
        </div>
        <div id="actList">
        <?php
            $current_bucket = null;
            foreach ($activity_log as $row):
                $bucket = gc_activity_bucket($row['created_at']);
                if ($bucket !== $current_bucket): $current_bucket = $bucket; ?>
        <div class="act-cat-title"><i class="ri-calendar-line"></i> <?php echo $bucket; ?></div>
        <?php endif; [$text, $icon] = gc_activity_render($row); ?>
        <div class="act-entry" data-cat="<?php echo gc_activity_cat($row['action_type']); ?>" data-bucket="<?php echo htmlspecialchars($bucket); ?>">
            <div class="act-entry-icon"><i class="<?php echo $icon; ?>"></i></div>
            <div><div class="act-entry-text"><?php echo $text; ?></div><div class="act-entry-time"><?php echo date('g:i A · M j, Y', strtotime($row['created_at'])); ?></div></div>
        </div>
        <?php endforeach; ?>
        </div>
        <div class="act-none" id="actNone" hidden>Nothing here yet.</div>
        <p class="m-hint">Shows what admins changed and what members did lately (joins, posts, requests, reports). Moderation entries can't be edited or removed.</p>
        <?php endif; ?>
    </div>
</div></div>

<!-- Insights -->
<div class="modal-overlay" id="modal-insights"><div class="modal-lane">
    <div class="ac-topbar modal-topbar"><button type="button" class="ac-x" onclick="closeModal('modal-insights')" aria-label="Close"><i class="ri-close-line"></i></button><div class="ac-title">Insights &amp; Analytics</div></div>
    <div class="modal-body">
        <div class="m-label"><i class="ri-line-chart-line"></i> Last 7 days</div>
        <?php
        $ins_cards = [
            ['v', 'ri-eye-line',        'Visitors',     'People who opened the group'],
            ['p', 'ri-image-add-line',  'New posts',    'Posts published in the group'],
            ['m', 'ri-user-add-line',   'New members',  'People who joined'],
        ];
        foreach ($ins_cards as $c):
            [$tt, $tc] = gc_trend($ins_tot[$c[0]][0], $ins_tot[$c[0]][1]); ?>
        <div class="ins-card">
            <div class="ins-head">
                <div class="stat-icon"><i class="<?php echo $c[1]; ?>"></i></div>
                <div class="ins-meta"><div class="ins-title"><?php echo $c[2]; ?></div><div class="ins-sub"><?php echo $c[3]; ?></div></div>
                <div class="ins-num"><div class="ins-big"><?php echo number_format($ins_tot[$c[0]][0]); ?></div><span class="ins-chip <?php echo $tc; ?>" title="vs the 7 days before"><?php echo $tt; ?></span></div>
            </div>
            <?php echo gc_bars($ins_days, $c[0]); ?>
        </div>
        <?php endforeach; ?>

        <div class="m-label" style="margin-top:6px"><i class="ri-heart-3-line"></i> Engagement</div>
        <div class="stat-grid">
            <div class="stat-card"><div class="stat-icon"><i class="ri-user-star-line"></i></div><div class="stat-value"><?php echo number_format($ins_active); ?></div><div class="stat-label">Members who posted this week</div></div>
            <div class="stat-card"><div class="stat-icon"><i class="ri-image-2-line"></i></div><div class="stat-value"><?php echo number_format($ins_posts_total); ?></div><div class="stat-label">Posts in total</div></div>
            <div class="stat-card"><div class="stat-icon"><i class="ri-heart-3-line"></i></div><div class="stat-value"><?php echo number_format($ins_likes_total); ?></div><div class="stat-label">Likes on posts</div></div>
        </div>
        <?php if ($ins_top_poster || $ins_top_post): ?>
        <div class="ins-list">
            <?php if ($ins_top_poster): ?>
            <a class="ins-row" href="profile.php?user_id=<?php echo (int)$ins_top_poster['id']; ?>"><i class="ri-trophy-line"></i><div><div class="ins-row-t">Top contributor</div><div class="ins-row-s"><?php echo htmlspecialchars($ins_top_poster['username']); ?> · <?php echo (int)$ins_top_poster['c']; ?> post<?php echo (int)$ins_top_poster['c'] === 1 ? '' : 's'; ?></div></div><i class="ri-arrow-right-s-line chevron"></i></a>
            <?php endif; ?>
            <?php if ($ins_top_post): ?>
            <div class="ins-row"><i class="ri-fire-line"></i><div><div class="ins-row-t">Most liked post</div><div class="ins-row-s"><?php echo htmlspecialchars($ins_top_post['username']); ?> · <?php echo (int)$ins_top_post['c']; ?> like<?php echo (int)$ins_top_post['c'] === 1 ? '' : 's'; ?><?php $cp = trim((string)$ins_top_post['caption']); if ($cp !== '') echo ' · “' . htmlspecialchars(mb_strimwidth($cp, 0, 60, '…')) . '”'; ?></div></div></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="m-label" style="margin-top:6px"><i class="ri-bar-chart-2-line"></i> Group health</div>
        <div class="stat-grid">
            <?php
            $stats = [
                ['ri-team-line', number_format($member_count), 'Members'],
                ['ri-shield-star-line', count($admins), 'Admins'],
                ['ri-user-forbid-line', $banned_count, 'Banned'],
                ['ri-flag-line', $reported_post_count, 'Open reports'],
            ];
            if ($is_private_group) { $stats[] = ['ri-user-add-line', $pending_request_count, 'Pending requests']; $stats[] = ['ri-questionnaire-line', $join_q_count, 'Join questions']; }
            $stats[] = ['ri-hashtag', count($group_hashtags), 'Hashtags'];
            $stats[] = ['ri-list-check-2', $rules_count, 'Rules'];
            $stats[] = ['ri-spam-2-line', count($am_words), 'Banned words'];
            $stats[] = ['ri-alert-line', $warn_total, 'Active strikes'];
            $stats[] = ['ri-time-line', $pending_post_count, 'Pending posts'];
            $stats[] = ['ri-trophy-line', $gh_count, 'Highlights'];
            $stats[] = ['ri-bar-chart-horizontal-line', $gh_poll_total, 'Polls'];
            $stats[] = ['ri-checkbox-circle-line', number_format($gh_vote_total), 'Poll votes'];
            foreach ($stats as $s): ?>
            <div class="stat-card"><div class="stat-icon"><i class="<?php echo $s[0]; ?>"></i></div><div class="stat-value"><?php echo $s[1]; ?></div><div class="stat-label"><?php echo $s[2]; ?></div></div>
            <?php endforeach; ?>
        </div>
    </div>
</div></div>

<!-- Help Centre -->
<div class="modal-overlay" id="modal-help"><div class="modal-lane">
    <div class="ac-topbar modal-topbar"><button type="button" class="ac-x" onclick="closeModal('modal-help')" aria-label="Close"><i class="ri-close-line"></i></button><div class="ac-title">Help Centre</div></div>
    <div class="modal-body">
        <div class="m-label"><i class="ri-question-line"></i> Popular topics</div>
        <div class="entry-list">
            <?php
            $help = [
                ['ri-user-add-line', 'Approving join requests', 'How private requests and join answers work'],
                ['ri-user-forbid-line', 'Kicking vs banning', 'The difference and when to use each'],
                ['ri-shield-star-line', 'Admin permissions', 'What admins can and can\'t do'],
                ['ri-flag-line', 'Handling reported posts', 'Reviewing flagged content fairly'],
                ['ri-list-check-2', 'Writing group rules', 'Short, clear rule bars members will read'],
                ['ri-trophy-line', 'Highlights & polls', 'Build highlights and run polls'],
            ];
            foreach ($help as $h): ?>
            <div class="row-item"><div class="stat-icon"><i class="<?php echo $h[0]; ?>"></i></div><div class="row-info"><div class="row-name"><?php echo $h[1]; ?></div><div class="row-desc-sub"><?php echo $h[2]; ?></div></div></div>
            <?php endforeach; ?>
        </div>
        <div class="empty-state"><div class="es-title">Still stuck?</div><p class="es-text">Send us a note from Feedback and describe what you were trying to do.</p><div class="es-tips"><div class="es-tip"><i class="ri-feedback-line"></i><span>Describe what you were trying to do.</span></div><div class="es-tip"><i class="ri-send-plane-line"></i><span>Send it from the Feedback section.</span></div></div><button type="button" class="es-btn" onclick="closeModal('modal-help')"><i class="ri-close-line"></i> Close</button></div>
    </div>
</div></div>

<!-- Feedback -->
<div class="modal-overlay" id="modal-feedback"><div class="modal-lane">
    <div class="ac-topbar modal-topbar"><button type="button" class="ac-x" onclick="closeModal('modal-feedback')" aria-label="Close"><i class="ri-close-line"></i></button><div class="ac-title">Feedback</div></div>
    <div class="modal-body">
        <div class="m-info"><i class="ri-send-plane-line"></i><span>Share ideas, report bugs, or tell us what would make managing this group easier.</span></div>
        <form method="POST" action="group.php?id=<?php echo $group_id; ?>">
            <div class="m-sec">
                <div class="m-label"><i class="ri-price-tag-3-line"></i> Category</div>
                <select name="feedback_category" class="gc-select"><option value="suggestion">Suggestion</option><option value="bug">Bug report</option><option value="moderation">Moderation tools</option><option value="other">Other</option></select>
            </div>
            <div class="m-sec">
                <div class="m-label"><i class="ri-edit-2-line"></i> Your feedback</div>
                <textarea class="gc-textarea" name="feedback_message" placeholder="What's on your mind?"></textarea>
            </div>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <button class="gc-btn wide" type="submit" name="submit_feedback"><i class="ri-send-plane-line"></i> Send Feedback</button>
        </form>
        <p class="m-hint">For bugs, say what you tapped and what you expected. We read every message.</p>
    </div>
</div></div>

<?php if ($is_owner): ?>
<!-- Change Group Name -->
<div class="modal-overlay" id="modal-name"><div class="modal-lane">
    <div class="ac-topbar modal-topbar"><button type="button" class="ac-x" onclick="closeModal('modal-name')" aria-label="Close"><i class="ri-close-line"></i></button><div class="ac-title">Change Group Name</div></div>
    <div class="modal-body">
        <form method="POST" action="group.php?id=<?php echo $group_id; ?>">
            <div class="m-label"><i class="ri-text"></i> Group name</div>
            <input class="gc-input" type="text" name="new_title" value="<?php echo htmlspecialchars($group['title']); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <button class="gc-btn wide" type="submit" name="change_group_title"><i class="ri-save-line"></i> Rename Group</button>
        </form>
        <p class="m-hint">The name shows in search and lists. Members tag a group by typing its name in highlight text, so renaming changes what they type.</p>
    </div>
</div></div>

<!-- Location -->
<div class="modal-overlay" id="modal-location"><div class="modal-lane">
    <div class="ac-topbar modal-topbar"><button type="button" class="ac-x" onclick="closeModal('modal-location')" aria-label="Close"><i class="ri-close-line"></i></button><div class="ac-title">Location</div></div>
    <div class="modal-body">
        <div class="m-info"><i class="ri-map-pin-line"></i><span>Set where this group is based. Only the owner can change it.</span></div>

        <div class="m-sec">
            <div class="m-label"><i class="ri-map-pin-line"></i> Group location</div>
            <div class="loc-wrap" id="th-loc-wrap">
                <input class="gc-input" type="text" id="th-loc-input" placeholder="Search a city or place (3+ letters)" autocomplete="off" oninput="thLocInput()" onfocus="thLocFocus()">
                <div class="loc-list" id="th-loc-list"></div>
            </div>
            <div class="loc-tag-box" id="th-loc-tag"></div>
            <p class="m-hint">Optional. Search powered by OpenStreetMap. Only the place name is saved, never coordinates.</p>
        </div>

        <div class="bar-status" id="th-status" aria-live="polite"></div>
        <button type="button" class="gc-btn wide" id="th-save" onclick="thSave(this)"><i class="ri-save-line"></i> Save Location</button>
    </div>
</div></div>

<!-- Transfer Ownership -->
<div class="modal-overlay" id="modal-transfer"><div class="modal-lane">
    <div class="ac-topbar modal-topbar"><button type="button" class="ac-x" onclick="closeModal('modal-transfer')" aria-label="Close"><i class="ri-close-line"></i></button><div class="ac-title">Transfer Ownership</div></div>
    <div class="modal-body">
        <div class="m-info"><i class="ri-error-warning-line"></i><span>Give this group to one of its admins. <b>You cannot undo this yourself.</b> Only the new owner could give it back.</span></div>
        <?php if (empty($admins)): ?>
        <div class="empty-state"><div class="es-title">No admins to choose from</div><p class="es-text">Ownership can only go to an admin. Appoint a trusted member as admin first, then come back here.</p><button type="button" class="es-btn" onclick="closeModal('modal-transfer');openModal('modal-admins')"><i class="ri-shield-star-line"></i> Open Admins</button></div>
        <?php else: ?>
        <div class="m-sec">
            <div class="m-label"><i class="ri-user-shared-line"></i> New owner</div>
            <select class="gc-select" id="tr-user">
                <option value="">Choose an admin</option>
                <?php foreach ($admins as $adm): ?><option value="<?php echo (int)$adm['id']; ?>"><?php echo htmlspecialchars($adm['username']); ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="perm-list">
            <div class="perm-item yes"><i class="ri-checkbox-circle-fill"></i><div><div class="perm-item-title">You stay in the group as an admin</div><div class="perm-item-sub">You keep moderation powers but lose owner-only settings.</div></div></div>
            <div class="perm-item no"><i class="ri-close-circle-fill"></i><div><div class="perm-item-title">You lose owner-only powers</div><div class="perm-item-sub">Rename, privacy, 18+, location, join questions, appointing admins and deleting the group.</div></div></div>
            <div class="perm-item no"><i class="ri-close-circle-fill"></i><div><div class="perm-item-title">Your account-wide bans stay yours</div><div class="perm-item-sub">People you banned from all your groups are not moved to the new owner.</div></div></div>
        </div>
        <div class="m-sec">
            <div class="m-label"><i class="ri-shield-keyhole-line"></i> Type the group name to confirm</div>
            <input class="gc-input" type="text" id="tr-confirm" placeholder="<?php echo htmlspecialchars($group['title'], ENT_QUOTES); ?>" autocomplete="off">
        </div>
        <div class="bar-status" id="tr-status" aria-live="polite"></div>
        <button type="button" class="gc-btn wide danger" id="tr-btn" onclick="trSave(this)"><i class="ri-user-shared-line"></i> Transfer Ownership</button>
        <?php endif; ?>
    </div>
</div></div>

<!-- Pod Category -->
<div class="modal-overlay" id="modal-category"><div class="modal-lane">
    <div class="ac-topbar modal-topbar"><button type="button" class="ac-x" onclick="closeModal('modal-category')" aria-label="Close"><i class="ri-close-line"></i></button><div class="ac-title">Pod Category</div></div>
    <div class="modal-body">
        <div class="m-info"><i class="ri-information-line"></i><span><?php echo $pod_category === '' ? 'This pod has no category yet. Choose one so it shows up correctly around the app.' : 'Pick the category that best describes this pod.'; ?></span></div>
        <form method="POST" action="group.php?id=<?php echo $group_id; ?>">
            <div class="m-label"><i class="ri-price-tag-3-line"></i> Category</div>
            <select name="new_category" class="gc-select">
                <option value="" disabled <?php echo $pod_category === '' ? 'selected' : ''; ?>>Choose a category</option>
                <?php foreach ($pod_category_options as $opt): ?>
                <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo ($opt === $pod_category) ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <button class="gc-btn wide" type="submit" name="change_group_category"><i class="ri-save-line"></i> Save Category</button>
        </form>
        <p class="m-hint">The category decides where your pod appears when people browse and search.</p>
    </div>
</div></div>

<!-- 18+ -->
<div class="modal-overlay" id="modal-adult"><div class="modal-lane">
    <div class="ac-topbar modal-topbar"><button type="button" class="ac-x" onclick="closeModal('modal-adult')" aria-label="Close"><i class="ri-close-line"></i></button><div class="ac-title">18+ Content</div></div>
    <div class="modal-body">
        <div class="m-info"><i class="ri-error-warning-line"></i><span>When enabled, this group is labeled as adult content across the app.</span></div>
        <div class="toggle-row">
            <span class="toggle-note"><?php echo $is_adult_group ? 'Currently: <strong>18+ Enabled</strong>' : 'Currently: <strong>Not 18+</strong>'; ?></span>
            <form method="POST" action="group.php?id=<?php echo $group_id; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <button type="submit" name="toggle_adult" class="toggle-btn <?php echo $is_adult_group ? 'on' : 'off'; ?>"><?php echo $is_adult_group ? 'Disable 18+' : 'Enable 18+'; ?></button>
            </form>
        </div>
        <p class="m-hint">Be honest here. It protects your community and keeps the group in good standing.</p>
    </div>
</div></div>

<!-- Group Privacy + Join Questions -->
<div class="modal-overlay" id="modal-privacy"><div class="modal-lane">
    <div class="ac-topbar modal-topbar"><button type="button" class="ac-x" onclick="closeModal('modal-privacy')" aria-label="Close"><i class="ri-close-line"></i></button><div class="ac-title">Group Privacy</div></div>
    <div class="modal-body">
        <div class="m-sec">
            <div class="m-label"><i class="ri-lock-line"></i> Who can join</div>
            <div class="toggle-row">
                <span class="toggle-note"><?php echo $is_private_group ? 'Currently: <strong>Private</strong>. Requests required.' : 'Currently: <strong>Public</strong>. Instant join.'; ?></span>
                <?php $priv_msg = $is_private_group ? 'Make this group public? Pending requests will be auto-approved.' : 'Make this group private? New members must request to join.'; ?>
                <form method="POST" action="group.php?id=<?php echo $group_id; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <button type="submit" name="toggle_privacy" class="toggle-btn <?php echo $is_private_group ? 'on' : 'off'; ?>" onclick="return confirm(<?php echo gc_confirm_js($priv_msg); ?>)"><?php echo $is_private_group ? 'Make Public' : 'Make Private'; ?></button>
                </form>
            </div>
            <p class="m-hint"><b>Private</b> groups make people ask first. <b>Public</b> groups let anyone in instantly.</p>
        </div>

        <div class="m-divider"></div>

        <div class="m-sec">
            <div class="m-label"><i class="ri-questionnaire-line"></i> Join questions <span class="m-count" id="jq-count">0/<?php echo JQ_MAX; ?></span></div>
            <div class="m-info"><i class="ri-information-line"></i><span>Optional. Ask people who request to join up to <b><?php echo JQ_MAX; ?> questions</b>. They answer on the group page, and you read their answers in <b>Join Requests</b> before you approve.<?php echo $is_private_group ? '' : ' These only appear while the group is private.'; ?></span></div>
            <div id="jq-list"></div>
            <button type="button" class="add-bar" id="jq-add" onclick="jqAdd()"><i class="ri-add-line"></i> Add a question</button>
            <div class="bar-status" id="jq-status" aria-live="polite"></div>
            <button type="button" class="gc-btn wide" id="jq-save" onclick="jqSave(this)"><i class="ri-save-line"></i> Save Questions</button>
            <p class="m-hint">Tap <b>Optional</b> on a bar to make that question required. Leave every bar empty to switch questions off.</p>
        </div>
    </div>
</div></div>
<?php endif; ?>

<!-- Auto-moderation -->
<div class="modal-overlay" id="modal-automod"><div class="modal-lane">
    <div class="ac-topbar modal-topbar"><button type="button" class="ac-x" onclick="closeModal('modal-automod')" aria-label="Close"><i class="ri-close-line"></i></button><div class="ac-title">Auto-moderation</div></div>
    <div class="modal-body">
        <div class="m-info"><i class="ri-spam-2-line"></i><span>Posts whose caption contains a banned word are handled for you. The owner and admins are never checked.</span></div>

        <div class="m-sec">
            <div class="m-label"><i class="ri-shield-flash-line"></i> When a post has a banned word</div>
            <div class="hl-seg" id="am-mode">
                <button type="button" data-mode="block" onclick="amMode('block')">Block the post</button>
                <button type="button" data-mode="report" onclick="amMode('report')">Send to Reports</button>
            </div>
            <p class="m-hint" id="am-mode-help"></p>
        </div>

        <div class="m-sec">
            <div class="m-label"><i class="ri-text-wrap"></i> Matching</div>
            <div class="toggle-row">
                <span class="toggle-note" id="am-whole-note"></span>
                <button type="button" class="toggle-btn" id="am-whole-btn" onclick="amWhole()"></button>
            </div>
        </div>

        <div class="m-sec">
            <div class="m-label"><i class="ri-spam-2-line"></i> Banned words <span class="m-count" id="am-count">0/<?php echo GC_AM_MAX_WORDS; ?></span></div>
            <div class="form-row">
                <input class="gc-input" type="text" id="am-input" maxlength="200" placeholder="Type a word or phrase, or paste a list with commas" autocomplete="off" onkeydown="if(event.key==='Enter'){event.preventDefault();amAdd();}">
                <button type="button" class="gc-btn" onclick="amAdd()">Add</button>
            </div>
            <div class="chip-wrap" id="am-chips" style="margin-top:0"></div>
        </div>

        <div class="bar-status" id="am-status" aria-live="polite"></div>
        <button type="button" class="gc-btn wide" id="am-save" onclick="amSave(this)"><i class="ri-save-line"></i> Save Auto-moderation</button>
        <p class="m-hint">Up to <?php echo GC_AM_MAX_WORDS; ?> words, <?php echo GC_AM_WORD_LEN; ?> characters each. Capital letters are ignored. Every change is saved in the Activity Log.</p>
    </div>
</div></div>

<!-- Slow Mode -->
<div class="modal-overlay" id="modal-slowmode"><div class="modal-lane">
    <div class="ac-topbar modal-topbar"><button type="button" class="ac-x" onclick="closeModal('modal-slowmode')" aria-label="Close"><i class="ri-close-line"></i></button><div class="ac-title">Slow Mode</div></div>
    <div class="modal-body">
        <div class="m-info"><i class="ri-timer-line"></i><span>Members can post only once every few minutes. It slows spam and keeps busy groups readable. The owner and admins are exempt.</span></div>
        <div class="m-sec">
            <div class="m-label"><i class="ri-timer-line"></i> Time between posts</div>
            <div class="sm-grid" id="sm-grid"></div>
            <p class="m-hint" id="sm-help"></p>
        </div>
        <div class="bar-status" id="sm-status" aria-live="polite"></div>
        <button type="button" class="gc-btn wide" id="sm-save" onclick="smSave(this)"><i class="ri-save-line"></i> Save Slow Mode</button>
    </div>
</div></div>

<!-- Ban reason / note -->
<div class="modal-overlay" id="modal-banreason"><div class="modal-lane">
    <div class="ac-topbar modal-topbar"><button type="button" class="ac-x" onclick="closeModal('modal-banreason')" aria-label="Close"><i class="ri-close-line"></i></button><div class="ac-title" id="br-title">Ban member</div></div>
    <div class="modal-body">
        <div class="m-info"><i class="ri-user-forbid-line"></i><span id="br-info"></span></div>
        <div class="m-sec">
            <div class="m-label"><i class="ri-draft-line"></i> Reason (optional) <span class="m-count" id="br-count">0/<?php echo GC_BAN_NOTE_LEN; ?></span></div>
            <div class="chip-wrap" style="margin-top:0" id="br-quick"></div>
            <textarea class="gc-textarea" id="br-text" maxlength="<?php echo GC_BAN_NOTE_LEN; ?>" placeholder="Why is this member being banned?" oninput="brCount()"></textarea>
            <p class="m-hint">Only the owner and admins can see this. It helps your team stay consistent. The member never sees it.</p>
        </div>
        <div class="bar-status" id="br-status" aria-live="polite"></div>
        <button type="button" class="gc-btn wide" id="br-btn" onclick="brConfirm(this)">Ban member</button>
    </div>
</div></div>

<!-- Warnings -->
<div class="modal-overlay" id="modal-warnings"><div class="modal-lane">
    <div class="ac-topbar modal-topbar"><button type="button" class="ac-x" onclick="closeModal('modal-warnings')" aria-label="Close"><i class="ri-close-line"></i></button><div class="ac-title">Warnings</div></div>
    <div class="modal-body">
        <div class="m-info"><i class="ri-alert-line"></i><span>Give a member a <b>strike</b> with a reason before you kick or ban. Strikes stay until an owner or admin removes them. Warn someone from <b>Members</b>.</span></div>

        <div class="m-sec">
            <div class="m-label"><i class="ri-alert-line"></i> Strike limit</div>
            <div class="sm-grid" id="wl-grid"></div>
            <p class="m-hint" id="wl-help"></p>
            <div class="bar-status" id="wl-status" aria-live="polite"></div>
            <button type="button" class="gc-btn wide" id="wl-save" onclick="wlSave(this)"><i class="ri-save-line"></i> Save Strike Limit</button>
        </div>

        <div class="m-label"><i class="ri-user-unfollow-line"></i> Members with strikes <span class="m-count"><?php echo count($warn_rows); ?></span></div>
        <div class="entry-list">
        <?php if (!empty($warn_rows)): foreach ($warn_rows as $w):
            $wp = !empty($w['profile_pic']) ? $w['profile_pic'] : 'mee.jpg';
            $w_is_admin = isset($admins[(int)$w['user_id']]);
            $w_hit = ($warn_limit > 0 && (int)$w['c'] >= $warn_limit);
        ?>
        <div class="row-item">
            <img class="av" src="<?php echo htmlspecialchars($wp); ?>" alt="" onerror="this.src='mee.jpg'">
            <div class="row-info">
                <div class="row-name"><?php echo htmlspecialchars($w['username']); ?></div>
                <div class="row-desc-sub"><span class="strike-badge<?php echo $w_hit ? ' hit' : ''; ?>"><?php echo (int)$w['c']; ?><?php echo $warn_limit > 0 ? ' / ' . $warn_limit : ''; ?> strike<?php echo (int)$w['c'] == 1 ? '' : 's'; ?></span><?php echo $w_hit ? ' · at the limit' : ''; ?></div>
                <div class="ban-note"><i class="ri-draft-line"></i><span><?php echo htmlspecialchars((string)$w['last_reason']); ?></span></div>
            </div>
            <div class="row-actions">
                <button type="button" class="tiny-btn kick" data-uid="<?php echo (int)$w['user_id']; ?>" data-name="<?php echo htmlspecialchars($w['username'], ENT_QUOTES); ?>" onclick="whOpen(this)"><i class="ri-history-line"></i> History</button>
                <?php if ($is_owner || !$w_is_admin): ?><button type="button" class="tiny-btn warn" data-uid="<?php echo (int)$w['user_id']; ?>" data-name="<?php echo htmlspecialchars($w['username'], ENT_QUOTES); ?>" onclick="gcWarn(this)"><i class="ri-alert-line"></i> Warn</button><?php endif; ?>
            </div>
        </div>
        <?php endforeach; else: ?>
        <div class="empty-state"><div class="es-title">No active warnings</div><p class="es-text">Nobody has strikes right now. Warn a member from the Members list when a gentle nudge is enough.</p><button type="button" class="es-btn" onclick="closeModal('modal-warnings');openModal('modal-members')"><i class="ri-team-line"></i> Open Members</button></div>
        <?php endif; ?>
        </div>
        <p class="m-hint">When a member reaches the strike limit you are asked whether to kick or ban them. Warnings are saved in the Activity Log.</p>
    </div>
</div></div>

<!-- Warn a member -->
<div class="modal-overlay" id="modal-warn"><div class="modal-lane">
    <div class="ac-topbar modal-topbar"><button type="button" class="ac-x" onclick="closeModal('modal-warn')" aria-label="Close"><i class="ri-close-line"></i></button><div class="ac-title">Warn member</div></div>
    <div class="modal-body">
        <div class="m-info"><i class="ri-alert-line"></i><span id="wn-info"></span></div>
        <div class="m-sec">
            <div class="m-label"><i class="ri-draft-line"></i> Reason <span class="m-count" id="wn-count">0/<?php echo GC_BAN_NOTE_LEN; ?></span></div>
            <div class="chip-wrap" style="margin-top:0" id="wn-quick"></div>
            <textarea class="gc-textarea" id="wn-text" maxlength="<?php echo GC_BAN_NOTE_LEN; ?>" placeholder="What did they do?" oninput="wnCount()"></textarea>
            <p class="m-hint">A reason is required. Only the owner and admins can see it.</p>
        </div>
        <div class="bar-status" id="wn-status" aria-live="polite"></div>
        <button type="button" class="gc-btn wide" id="wn-btn" onclick="wnConfirm(this)"><i class="ri-alert-line"></i> Give a strike</button>
    </div>
</div></div>

<!-- Warning history -->
<div class="modal-overlay" id="modal-warnhist"><div class="modal-lane">
    <div class="ac-topbar modal-topbar"><button type="button" class="ac-x" onclick="whClose()" aria-label="Close"><i class="ri-close-line"></i></button><div class="ac-title" id="wh-title">Warning history</div></div>
    <div class="modal-body">
        <div class="entry-list" id="wh-list"></div>
        <div class="bar-status" id="wh-status" aria-live="polite"></div>
        <button type="button" class="gc-btn wide subtle" id="wh-clear" onclick="whClear(this)" style="display:none"><i class="ri-delete-bin-line"></i> Clear all strikes</button>
        <p class="m-hint">Remove a strike to forgive it. Removed strikes no longer count toward the limit.</p>
    </div>
</div></div>

<!-- Strike limit reached -->
<div class="modal-overlay" id="modal-limit"><div class="modal-lane">
    <div class="ac-topbar modal-topbar"><button type="button" class="ac-x" onclick="limLater()" aria-label="Close"><i class="ri-close-line"></i></button><div class="ac-title">Strike limit reached</div></div>
    <div class="modal-body">
        <div class="m-info"><i class="ri-alert-line"></i><span id="lim-info"></span></div>
        <div class="bar-status" id="lim-status" aria-live="polite"></div>
        <button type="button" class="gc-btn wide subtle" onclick="limAct('kick')"><i class="ri-logout-box-line"></i> Kick (they can rejoin)</button>
        <button type="button" class="gc-btn wide danger" onclick="limAct('ban')"><i class="ri-user-forbid-line"></i> Ban</button>
        <button type="button" class="gc-btn wide subtle" onclick="limLater()">Not now</button>
    </div>
</div></div>

<!-- New Member Probation -->
<div class="modal-overlay" id="modal-probation"><div class="modal-lane">
    <div class="ac-topbar modal-topbar"><button type="button" class="ac-x" onclick="closeModal('modal-probation')" aria-label="Close"><i class="ri-close-line"></i></button><div class="ac-title">New Member Probation</div></div>
    <div class="modal-body">
        <div class="m-info"><i class="ri-user-search-line"></i><span>The first posts of a new member wait for an owner or admin to approve them. Once that many of their posts are live they are trusted automatically. Owner and admins are never held back.</span></div>
        <div class="m-sec">
            <div class="m-label"><i class="ri-time-line"></i> Posts that need approval</div>
            <div class="sm-grid" id="pb-grid"></div>
            <p class="m-hint" id="pb-help"></p>
        </div>
        <div class="bar-status" id="pb-status" aria-live="polite"></div>
        <button type="button" class="gc-btn wide" id="pb-save" onclick="pbSave(this)"><i class="ri-save-line"></i> Save Probation</button>
        <p class="m-hint">Posts waiting for approval show up under <b>Pending Posts</b> in Action items. Members who joined before you turned this on are not affected if they already have posts.</p>
    </div>
</div></div>

<!-- Pending Posts -->
<div class="modal-overlay" id="modal-pending"><div class="modal-lane">
    <div class="ac-topbar modal-topbar"><button type="button" class="ac-x" onclick="closeModal('modal-pending')" aria-label="Close"><i class="ri-close-line"></i></button><div class="ac-title">Pending Posts</div></div>
    <div class="modal-body">
        <div class="m-label"><i class="ri-time-line"></i> Waiting for approval <span class="m-count" id="pp-count"><?php echo $pending_post_count; ?></span></div>
        <div class="entry-list" id="pp-list">
        <?php if (!empty($pending_posts)): foreach ($pending_posts as $pp):
            $pp_thumb = (!empty($pp['file_name']) && $pp['file_name'] !== '0') ? 'uploads/' . $pp['file_name'] : '';
            $pp_cap = mb_strlen((string)$pp['caption']) > 60 ? mb_substr($pp['caption'], 0, 60) . '…' : (string)$pp['caption'];
            $rej_msg = 'Reject and delete this post by ' . $pp['poster_username'] . "?\nThis cannot be undone.";
        ?>
        <div class="row-item" style="align-items:flex-start;">
            <?php if ($pp_thumb): ?><img class="av" src="<?php echo htmlspecialchars($pp_thumb); ?>" alt="" onerror="this.src='uploads/Text.jpg'">
            <?php else: ?><div style="width:38px;height:38px;border-radius:50%;flex-shrink:0;background:var(--surface);display:flex;align-items:center;justify-content:center;border:2px solid var(--accent-border);"><i class="ri-file-text-line" style="color:var(--muted-2);"></i></div><?php endif; ?>
            <div class="row-info">
                <div class="row-name"><?php echo $pp_cap !== '' ? htmlspecialchars($pp_cap) : '<em style="color:var(--muted-2);">(no caption)</em>'; ?></div>
                <div class="row-desc-sub">by <?php echo htmlspecialchars($pp['poster_username']); ?></div>
            </div>
            <div class="row-actions" style="flex-direction:column;">
                <button type="button" class="tiny-btn approve" onclick="ppApprove(this,<?php echo (int)$pp['group_post_id']; ?>)"><i class="ri-check-line"></i> Approve</button>
                <form method="POST" action="group.php?id=<?php echo $group_id; ?>" onsubmit="return confirm(<?php echo gc_confirm_js($rej_msg); ?>)">
                    <input type="hidden" name="delete_post_id" value="<?php echo (int)$pp['group_post_id']; ?>">
                    <input type="hidden" name="delete_meme_id" value="<?php echo (int)$pp['meme_id']; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <button type="submit" name="delete_post" class="tiny-btn ban"><i class="ri-delete-bin-line"></i> Reject</button>
                </form>
            </div>
        </div>
        <?php endforeach; else: ?>
        <div class="empty-state"><div class="es-title">Nothing waiting</div><p class="es-text">Posts from new members will appear here when probation is on.</p><button type="button" class="es-btn" onclick="closeModal('modal-pending')"><i class="ri-close-line"></i> Close</button></div>
        <?php endif; ?>
        </div>
        <?php if ($pending_post_count > 1): ?><button type="button" class="gc-btn wide subtle" onclick="ppApproveAll(this)"><i class="ri-check-double-line"></i> Approve all</button><?php endif; ?>
        <p class="m-hint"><b>Approve</b> publishes the post to the group. <b>Reject</b> deletes it. Approvals are saved in the Activity Log.</p>
    </div>
</div></div>

<!-- Pause Group -->
<div class="modal-overlay" id="modal-pause"><div class="modal-lane">
    <div class="ac-topbar modal-topbar"><button type="button" class="ac-x" onclick="closeModal('modal-pause')" aria-label="Close"><i class="ri-close-line"></i></button><div class="ac-title"><?php echo $is_paused ? 'Resume Group' : 'Pause Group'; ?></div></div>
    <div class="modal-body">
        <div class="m-info"><i class="ri-pause-circle-line"></i><span>Pausing freezes activity without deleting anything. You can resume at any time.</span></div>
        <div class="perm-list" style="margin-bottom:20px;">
            <?php
            $pause_no = [['New posts', 'Members can\'t upload new posts.'], ['Comments & reactions', 'Likes, reposts, saves and comments are disabled.'], ['Poll voting', 'No voting on highlight polls.'], ['New events', 'No new pod events.'], ['File uploads', 'Nobody can add files.'], ['Join requests', 'New requests are paused too.']];
            foreach ($pause_no as $p): ?>
            <div class="perm-item no"><i class="ri-close-circle-fill"></i><div><div class="perm-item-title"><?php echo htmlspecialchars($p[0]); ?></div><div class="perm-item-sub"><?php echo htmlspecialchars($p[1]); ?></div></div></div>
            <?php endforeach; ?>
            <div class="perm-item yes"><i class="ri-checkbox-circle-fill"></i><div><div class="perm-item-title">Browsing existing content</div><div class="perm-item-sub">Members can still view posts, files, highlights and discussions.</div></div></div>
            <div class="perm-item yes"><i class="ri-checkbox-circle-fill"></i><div><div class="perm-item-title">Owner &amp; admin controls</div><div class="perm-item-sub">You keep full moderation access.</div></div></div>
        </div>
        <div class="toggle-row">
            <span class="toggle-note"><?php echo $is_paused ? 'This pod is currently <strong>paused</strong>.' : 'This pod is currently <strong>active</strong>.'; ?></span>
            <form method="POST" action="group.php?id=<?php echo $group_id; ?>" onsubmit="return confirm(<?php echo gc_confirm_js($is_paused ? 'Resume this pod? Members will be able to post, react, and join again.' : "Pause this pod? Members won't be able to post, comment, react, upload files, or send join requests until you resume it."); ?>)">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <button type="submit" name="toggle_pause" class="toggle-btn <?php echo $is_paused ? 'off' : 'on'; ?>"><?php echo $is_paused ? 'Resume Group' : 'Pause Group'; ?></button>
            </form>
        </div>
    </div>
</div></div>

<!-- ══════════ GROUP HIGHLIGHTS ══════════ -->
<div class="modal-overlay" id="modal-highlights"><div class="modal-lane">
    <div class="ac-topbar modal-topbar"><button type="button" class="ac-x" onclick="closeModal('modal-highlights')" aria-label="Close"><i class="ri-close-line"></i></button><div class="ac-title">Group Highlights &amp; Polls</div></div>
    <div class="modal-body">
        <div class="m-label"><i class="ri-add-circle-line"></i> New highlight</div>
        <div class="hl-add">
            <input type="text" class="f-input" id="hl-title" maxlength="30" placeholder="Highlight name" autocomplete="off">
            <label class="hl-pick" for="hl-files">
                <img class="hl-cover-prev" id="hl-cover-prev" alt="">
                <i class="ri-image-add-line" id="hl-pick-icon"></i>
                <div class="hl-pick-text"><strong id="hl-pick-title">Choose photos or videos</strong><span id="hl-pick-sub">Add 1 to 100 per highlight.</span></div>
                <input type="file" id="hl-files" accept="image/*,video/*" multiple>
            </label>
            <button type="button" class="hl-pick small" onclick="hlAddText('c')"><i class="ri-text"></i><div class="hl-pick-text"><strong>Add a text highlight</strong><span>Words on a Text.jpg background.</span></div></button>
            <button type="button" class="hl-pick small poll" onclick="hlAddPoll('c')"><i class="ri-bar-chart-horizontal-line"></i><div class="hl-pick-text"><strong>Add a poll</strong><span>2 to 4 options. Members vote inside the highlight.</span></div></button>
            <div id="hl-items"></div>
            <label class="hl-pick small" for="hl-cover-file">
                <img class="hl-cover-prev" id="hl-cv-prev" alt="" style="width:34px;height:34px">
                <i class="ri-image-line" id="hl-cv-icon"></i>
                <div class="hl-pick-text"><strong id="hl-cv-title">Cover image (optional)</strong><span id="hl-cv-sub">Skip it and your first media becomes the cover.</span></div>
                <button type="button" class="hl-x" id="hl-cv-x" aria-label="Remove cover" onclick="event.preventDefault();event.stopPropagation();hlCoverClear()"><i class="ri-close-line"></i></button>
                <input type="file" id="hl-cover-file" accept="image/*">
            </label>
            <div class="hl-seg" id="hl-mode">
                <button type="button" class="on" data-mode="whole" onclick="hlSetMode('whole')">One song for all</button>
                <button type="button" data-mode="each" onclick="hlSetMode('each')">Song per media</button>
            </div>
            <p class="field-help" id="hl-mode-help">One song clip plays across every media in this highlight.</p>
            <div id="hl-song-wrap">
                <div class="hl-pick small" onclick="hlSongPick()" role="button" tabindex="0">
                    <i class="ri-music-2-line"></i>
                    <div class="hl-pick-text"><strong id="hl-song-title">Song clip (optional)</strong><span id="hl-song-sub">Pick the part of a song you want, up to 30 seconds.</span></div>
                    <button type="button" class="hl-x" id="hl-song-x" aria-label="Remove song" onclick="event.stopPropagation();hlSongClear()"><i class="ri-close-line"></i></button>
                </div>
            </div>
            <div class="hl-progress" id="hl-progress"><span id="hl-progress-bar"></span></div>
            <div class="hl-status" id="hl-status" aria-live="polite"></div>
            <button class="save-btn-full" id="hl-submit" style="margin-top:0" onclick="hlUpload(this)">Create highlight</button>
        </div>

        <div class="hl-list-head"><span class="hl-list-title">Group highlights</span><span class="hl-list-count" id="hl-total"><?php echo $gh_count; ?></span></div>
        <div class="hl-grid" id="hl-grid">
            <?php if (empty($group_highlights)): ?>
                <div class="hl-empty" id="hl-empty"><div class="empty-title">No highlights yet</div><div class="empty-desc" style="margin:4px auto 0;">Name your first highlight and add media or a poll above.</div></div>
            <?php else: foreach ($group_highlights as $h): ?>
                <div class="hl-item" id="hl-<?php echo (int)$h['id']; ?>">
                    <div class="hl-sq">
                        <?php if (!empty($h['cover_path'])): ?><img src="<?php echo htmlspecialchars($h['cover_path']); ?>" alt="" loading="lazy">
                        <?php elseif ($h['first_type'] === 'poll'): ?><div class="hl-pollTile"><i class="ri-bar-chart-horizontal-fill"></i><span><?php echo htmlspecialchars((string)$h['first_poll_q']); ?></span></div>
                        <?php elseif ($h['first_type'] === 'text'): ?><img src="<?php echo htmlspecialchars(HL_TEXT_BG); ?>" alt="" loading="lazy"><span class="hl-tilecap"><?php echo htmlspecialchars(function_exists('mb_substr') ? mb_substr((string)$h['first_caption'], 0, 90) : substr((string)$h['first_caption'], 0, 90)); ?></span>
                        <?php elseif ($h['first_type'] === 'video'): ?><video src="<?php echo htmlspecialchars($h['first_path']); ?>#t=0.1" muted playsinline preload="metadata"></video><span class="hl-vid"><i class="ri-play-fill"></i></span>
                        <?php else: ?><img src="<?php echo htmlspecialchars($h['first_path']); ?>" alt="" loading="lazy"><?php endif; ?>
                        <?php if (!empty($h['song_path'])): ?><span class="hl-song"><i class="ri-music-2-fill"></i></span><?php endif; ?>
                        <?php if ((int)$h['poll_count'] > 0 && $h['first_type'] !== 'poll'): ?><span class="hl-pollbadge" title="Has a poll"><i class="ri-bar-chart-horizontal-fill"></i></span><?php endif; ?>
                        <button class="hl-del" type="button" aria-label="Edit highlight" onclick="hlEdit(<?php echo (int)$h['id']; ?>)"><i class="ri-pencil-line"></i></button>
                    </div>
                    <div class="hl-name"><?php echo htmlspecialchars($h['title']); ?></div>
                    <div class="hl-count"><?php echo (int)$h['media_count']; ?> media<?php echo (int)$h['poll_count'] > 0 ? ' · ' . (int)$h['poll_count'] . ' poll' . ((int)$h['poll_count'] === 1 ? '' : 's') : ''; ?></div>
                </div>
            <?php endforeach; endif; ?>
        </div>
        <p class="m-hint">Tap the pencil on a tile to edit it. Changes are collected and only saved when you tap <b>Save new changes</b>. Tag people with @username (red) or a group by its name (blue). Both owner and admins can manage highlights and polls.</p>
    </div>
</div></div>

<!-- HIGHLIGHT EDITOR -->
<div class="modal-overlay" id="modal-hl-edit"><div class="modal-lane">
    <div class="ac-topbar modal-topbar"><button type="button" class="ac-x" onclick="closeModal('modal-hl-edit')" aria-label="Close"><i class="ri-close-line"></i></button><div class="ac-title">Edit highlight</div></div>
    <div class="modal-body"><div id="hle-root"></div></div>
    <div class="hle-footer">
        <div class="hle-note" id="hle-note" aria-live="polite">No new changes.</div>
        <button class="save-btn-full" id="hle-save" onclick="hlSaveAll()" disabled>Save new changes</button>
    </div>
</div></div>

<!-- TEXT EDITOR -->
<div class="modal-overlay" id="modal-ht"><div class="modal-lane">
    <div class="ac-topbar modal-topbar"><button type="button" class="ac-x" onclick="htClose()" aria-label="Close"><i class="ri-close-line"></i></button><div class="ac-title">Highlight text</div></div>
    <div class="modal-body">
        <textarea class="f-input" id="ht-input" maxlength="1000" placeholder="Write something. Tag people with @username or @nickname, and groups by their name." oninput="htUpdate()"></textarea>
        <div class="ht-count" id="ht-count">0/1000</div>
        <div class="hl-status" id="ht-status" aria-live="polite"></div>
        <div class="ht-preview-wrap"><div class="hle-h">Preview</div><div id="ht-preview"></div></div>
        <div class="ht-actions">
            <button type="button" class="ht-remove" id="ht-remove" onclick="htRemove()" style="display:none">Remove text</button>
            <button type="button" class="save-btn-full" onclick="htDone()">Done</button>
        </div>
        <p class="m-hint">Long text is shortened with a "more" button. Done keeps the text in your edit. It is saved for real when you tap Save new changes.</p>
    </div>
</div></div>

<!-- POLL EDITOR -->
<div class="modal-overlay" id="modal-poll"><div class="modal-lane">
    <div class="ac-topbar modal-topbar"><button type="button" class="ac-x" onclick="plClose()" aria-label="Close"><i class="ri-close-line"></i></button><div class="ac-title">Highlight poll</div></div>
    <div class="modal-body">
        <div id="pl-lock"></div>
        <div class="field-block">
            <div class="field-label"><i class="ri-question-line"></i> Question</div>
            <input type="text" class="f-input" id="pl-q" maxlength="140" placeholder="Ask your members something…" autocomplete="off" oninput="plQ()">
            <div class="pl-count" id="pl-q-count">0/140</div>
        </div>
        <div class="field-block">
            <div class="field-label"><i class="ri-list-check-2"></i> Options (2 to 4)</div>
            <div id="pl-opts"></div>
            <button type="button" class="hle-btn" id="pl-add" onclick="plOptAdd()"><i class="ri-add-line"></i> Add option</button>
        </div>
        <div class="field-block">
            <div class="field-label"><i class="ri-checkbox-multiple-line"></i> Answer type</div>
            <div class="hl-seg" id="pl-multi-seg"><button type="button" data-m="0" onclick="plMulti(0)">Pick one</button><button type="button" data-m="1" onclick="plMulti(1)">Pick several</button></div>
        </div>
        <div class="field-block">
            <div class="field-label"><i class="ri-eye-line"></i> Who sees the results</div>
            <select class="f-select" id="pl-res" onchange="PL.results=this.value;plPreview()">
                <option value="always">Everyone, always</option><option value="after_vote">Only after they vote</option><option value="after_close">Hidden until the poll closes</option>
            </select>
        </div>
        <div class="field-block">
            <div class="field-label"><i class="ri-timer-line"></i> Poll length</div>
            <select class="f-select" id="pl-dur" onchange="PL.duration=+this.value;plPreview()"></select>
        </div>
        <div class="hle-h">Preview</div>
        <div class="hl-view" id="pl-preview"></div>
        <div class="hl-status" id="pl-status" aria-live="polite" style="margin-top:8px"></div>
        <button type="button" class="save-btn-full" onclick="plDone()">Done</button>
        <p class="m-hint">Done keeps the poll in your edit. It is saved when you tap Save new changes or Create highlight.</p>
    </div>
</div></div>

<!-- SONG CLIP PICKER -->
<div class="modal-overlay" id="modal-clip"><div class="modal-lane">
    <div class="ac-topbar modal-topbar"><button type="button" class="ac-x" onclick="cpClose()" aria-label="Close"><i class="ri-close-line"></i></button><div class="ac-title">Choose a song clip</div></div>
    <div class="modal-body">
        <label class="hl-pick" for="cp-file">
            <i class="ri-music-2-line"></i>
            <div class="hl-pick-text"><strong id="cp-file-title">Choose a song</strong><span>MP3, M4A, WAV and more. Only the part you pick is kept.</span></div>
            <input type="file" id="cp-file" accept="audio/*">
        </label>
        <div id="cp-editor" style="display:none">
            <input type="text" class="f-input" id="cp-title" maxlength="60" placeholder="Song name (optional)" style="margin-top:12px">
            <div class="cp-times"><span>Starts at <b id="cp-start-lbl">0:00</b></span><span>Ends at <b id="cp-end-lbl">0:15</b></span></div>
            <input type="range" id="cp-start" min="0" max="100" step="0.1" value="0" oninput="cpUpdate()">
            <div class="cp-row">
                <span class="field-label" style="margin:0">Clip length</span>
                <select class="f-select" id="cp-len" style="width:auto" onchange="cpUpdate()"><option value="10">10 seconds</option><option value="15" selected>15 seconds</option><option value="20">20 seconds</option><option value="30">30 seconds</option></select>
            </div>
            <button type="button" class="hl-pick small" id="cp-prev-btn" onclick="cpPreview()"><i class="ri-play-circle-line"></i><div class="hl-pick-text"><strong id="cp-prev-txt">Preview clip</strong></div></button>
            <button class="save-btn-full" id="cp-use" onclick="cpUse(this)">Use this clip</button>
        </div>
        <div class="hl-status" id="cp-status" style="margin-top:10px"></div>
        <p class="m-hint">Only the short clip is uploaded. The full song never leaves your device.</p>
    </div>
</div></div>

<div class="dz-toast" id="dz-toast" role="status" aria-live="polite"></div>

<script>
const GC_CSRF = <?php echo json_encode($csrf_token); ?>;
const GC_GID  = <?php echo (int)$group_id; ?>;
const HL_TEXT_BG   = <?php echo json_encode(HL_TEXT_BG); ?>;
const HL_USER_URL  = <?php echo json_encode(HL_USER_URL); ?>;
const HL_GROUP_URL = <?php echo json_encode(HL_GROUP_URL); ?>;
const RULES_INIT = <?php echo json_encode($rules_list); ?>;
const JQ_INIT    = <?php echo json_encode($join_q_js); ?>;
const RULES_MAX = <?php echo GC_MAX_RULES; ?>, RULE_LEN = <?php echo GC_RULE_LEN; ?>;
const JQ_MAX = <?php echo JQ_MAX; ?>, JQ_LEN = <?php echo JQ_Q_LEN; ?>;
const TH_INIT = <?php echo json_encode(['loc' => $group_location]); ?>;
const TH_LOC_MAX = <?php echo GC_LOC_LEN; ?>;
const AM_INIT = <?php echo json_encode(['words' => $am_words, 'mode' => $am_mode, 'whole' => $am_whole]); ?>;
const AM_MAX = <?php echo GC_AM_MAX_WORDS; ?>, AM_LEN = <?php echo GC_AM_WORD_LEN; ?>;
const SM_OPTS = <?php echo json_encode(GC_SLOW_OPTS); ?>;
const SM_INIT = <?php echo (int)$slow_secs; ?>;
const BR_LEN = <?php echo GC_BAN_NOTE_LEN; ?>;
const TR_TITLE = <?php echo json_encode((string)$group['title']); ?>;
const WL_OPTS = <?php echo json_encode(GC_WARN_OPTS); ?>, WL_INIT = <?php echo (int)$warn_limit; ?>;
const PB_OPTS = <?php echo json_encode(GC_PROB_OPTS); ?>, PB_INIT = <?php echo (int)$prob_posts; ?>;
const $id = id => document.getElementById(id);

/* ══════════ MODAL CONTROL + LOADING SKELETON ══════════ */
function openModal(id){
    var m = document.getElementById(id);
    if(!m) return;
    m.classList.add('active');
    document.body.style.overflow = 'hidden';
    m.scrollTop = 0;
    showModalSkeleton(m);
}
function showModalSkeleton(el){
    var body = el.querySelector('.modal-body');
    if (!body) return;
    var skel = body.querySelector(':scope > .modal-skel');
    if (!skel) {
        skel = document.createElement('div');
        skel.className = 'modal-skel';
        skel.innerHTML = '<div class="sk-bar" style="height:11px;width:36%"></div><div class="sk-bar" style="height:44px;width:100%"></div><div class="sk-bar" style="height:44px;width:100%"></div><div class="sk-bar" style="height:11px;width:50%"></div><div class="sk-bar" style="height:44px;width:100%"></div><div class="sk-bar" style="height:84px;width:100%"></div>';
        body.prepend(skel);
    }
    skel.classList.remove('hide');
    clearTimeout(skel._t);
    skel._t = setTimeout(function(){ skel.classList.add('hide'); }, 340);
}
function closeModal(id){
    var m = document.getElementById(id);
    if(!m) return;
    if (id === 'modal-clip') { cpClose(); return; }
    if (id === 'modal-ht')   { htClose(); return; }
    if (id === 'modal-poll') { plClose(); return; }
    if (id === 'modal-hl-edit') {
        if (hlHasPending() && !confirm('You have changes that are not saved yet. Leave without saving?')) return;
        hlDiscard();
    }
    m.classList.remove('active');
    if (!document.querySelector('.modal-overlay.active')) document.body.style.overflow = '';
    if (id === 'modal-hl-edit' && hlDirty) { hlDirty = false; hlReload(); }
}
document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') {
        var open = Array.from(document.querySelectorAll('.modal-overlay.active'));
        if (!open.length) return;
        open.sort(function(a, b){ return (parseInt(getComputedStyle(a).zIndex) || 0) - (parseInt(getComputedStyle(b).zIndex) || 0); });
        closeModal(open[open.length - 1].id);
    }
});
window.addEventListener('beforeunload', function(e){
    if (typeof hlHasPending === 'function' && hlHasPending()) { e.preventDefault(); e.returnValue = ''; }
});
function hlReload(){ window.location.href = window.location.pathname + '?id=' + GC_GID + '&open=highlights'; }

/* Auto-open a modal from ?open=... */
(function(){
    var params = new URLSearchParams(window.location.search), t = params.get('open');
    if (!t) return;
    var map = { highlights:'modal-highlights', requests:'modal-requests', reports:'modal-reports', members:'modal-members', activity:'modal-activity', roles:'modal-roles', privacy:'modal-privacy', rules:'modal-rules', location:'modal-location', automod:'modal-automod', slowmode:'modal-slowmode', banned:'modal-banned', warnings:'modal-warnings', probation:'modal-probation', pending:'modal-pending' };
    var mid = map[t]; if (!mid) return;
    window.addEventListener('load', function(){
        setTimeout(function(){
            if (document.getElementById(mid)) openModal(mid);
            params.delete('open');
            history.replaceState(null, '', window.location.pathname + '?' + params.toString());
        }, 700);
    });
})();

function switchRoleTab(tab){
    var a = tab === 'admins';
    $id('roleTabBtnAdmins').classList.toggle('active', a); $id('roleTabBtnDetails').classList.toggle('active', !a);
    $id('roleTabPanelAdmins').classList.toggle('active', a); $id('roleTabPanelDetails').classList.toggle('active', !a);
}

/* ── Right-column cards: collapse + remember ── */
window.gsToggle = function(btn){
    var card = btn && btn.closest ? btn.closest('.gs-card') : null; if (!card) return;
    var c = card.classList.toggle('collapsed');
    btn.setAttribute('aria-expanded', c ? 'false' : 'true');
    try { localStorage.setItem('gc_gs_' + (card.getAttribute('data-k')||''), c ? '1' : '0'); } catch(e){}
};
(function(){
    document.querySelectorAll('.gs-card').forEach(function(card){
        try {
            if (localStorage.getItem('gc_gs_' + (card.getAttribute('data-k')||'')) === '1') {
                card.classList.add('collapsed');
                var b = card.querySelector('.gs-bar'); if (b) b.setAttribute('aria-expanded','false');
            }
        } catch(e){}
    });
})();

/* ── Page skeleton ── */
(function(){
    var loader = $id('skeleton-loader');
    if (!loader) return;
    var minDelay = 500, start = Date.now();
    function reveal(){
        var wait = Math.max(0, minDelay - (Date.now() - start));
        setTimeout(function(){
            loader.classList.add('hide');
            setTimeout(function(){ if (loader.parentNode) loader.parentNode.removeChild(loader); }, 500);
        }, wait);
    }
    if (document.readyState === 'complete') reveal(); else window.addEventListener('load', reveal);
    setTimeout(reveal, 3000);
})();

function showToast(msg){
    var t = $id('dz-toast');
    t.textContent = msg; t.classList.add('show');
    clearTimeout(t._tid);
    t._tid = setTimeout(function(){ t.classList.remove('show'); }, 2800);
}

const esc = v => String(v == null ? '' : v).replace(/[&<>"']/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));
const hlShort = (v, n) => { v = String(v || ''); return v.length > n ? v.slice(0, n - 1) + '…' : v; };

/* One helper for every request on this page (CSRF included). Files always go one per request. */
async function hlCall(action, fields, files){
    const fd = new FormData();
    fd.append('hl_action', action); fd.append('csrf_token', GC_CSRF);
    Object.keys(fields || {}).forEach(k => fd.append(k, fields[k]));
    Object.keys(files || {}).forEach(k => fd.append(k, files[k], files[k].name || 'clip.wav'));
    let d;
    try { const r = await fetch(window.location.pathname + '?id=' + GC_GID, { method:'POST', body:fd }); d = await r.json(); }
    catch (e) { throw new Error('Upload failed. The file may be too large for the server.'); }
    if (!d.success) throw new Error(d.msg || 'Something went wrong.');
    return d;
}

/* ══════════ GROUP LOCATION (owner only) ══════════
   Location search uses OpenStreetMap Nominatim straight from the browser, same as About Me:
   3+ characters, 0.45s pause, up to 8 matches, stale answers ignored, only the place name is saved. */
const TH = { loc:TH_INIT.loc || '', timer:null, seq:0, results:[] };

function thStatus(m, kind){ const s = $id('th-status'); if (s) { s.textContent = m || ''; s.className = 'bar-status' + (kind ? ' ' + kind : ''); } }
/* cut a long place name to 100 characters, at a comma */
function thCut(name){
    name = String(name || '').trim();
    if (name.length <= TH_LOC_MAX) return name;
    const cut = name.slice(0, TH_LOC_MAX), i = cut.lastIndexOf(',');
    return (i > 0 ? cut.slice(0, i) : cut).trim();
}
function thLocRenderTag(){
    const tag = $id('th-loc-tag'), wrap = $id('th-loc-wrap');
    if (TH.loc) {
        tag.innerHTML = '<div class="chip"><i class="ri-map-pin-2-fill"></i><span>' + esc(TH.loc) + '</span><button type="button" aria-label="Clear location" onclick="thLocClear()">×</button></div>';
        tag.classList.add('show'); wrap.style.display = 'none';
    } else {
        tag.innerHTML = ''; tag.classList.remove('show'); wrap.style.display = '';
    }
}
function thLocClear(){
    TH.loc = ''; thLocRenderTag(); thStatus('');
    const inp = $id('th-loc-input'); inp.value = '';
    thLocHide();
    setTimeout(function(){ inp.focus({ preventScroll:true }); }, 30);
}
function thLocHide(){ const l = $id('th-loc-list'); l.classList.remove('show'); l.innerHTML = ''; }
function thLocFocus(){ if ($id('th-loc-list').innerHTML) $id('th-loc-list').classList.add('show'); }
function thLocInput(){
    clearTimeout(TH.timer);
    const q = $id('th-loc-input').value.trim();
    if (q.length < 3) { TH.seq++; thLocHide(); return; }
    TH.timer = setTimeout(function(){ thLocSearch(q); }, 450);
}
async function thLocSearch(q){
    const seq = ++TH.seq, list = $id('th-loc-list');
    list.innerHTML = '<div class="loc-msg">Searching…</div>'; list.classList.add('show');
    try {
        const r = await fetch('https://nominatim.openstreetmap.org/search?format=jsonv2&limit=8&accept-language=en&q=' + encodeURIComponent(q));
        if (!r.ok) throw new Error('bad');
        const d = await r.json();
        if (seq !== TH.seq) return;                       // an older answer arrived late: ignore it
        TH.results = (Array.isArray(d) ? d : []).map(x => String(x.display_name || '')).filter(Boolean);
        if (!TH.results.length) { list.innerHTML = '<div class="loc-msg">No matching places found</div>'; return; }
        list.innerHTML = TH.results.map((n, i) =>
            '<button type="button" class="loc-item" onclick="thLocPick(' + i + ')"><i class="ri-map-pin-line"></i><span>' + esc(n) + '</span></button>').join('');
    } catch (e) {
        if (seq !== TH.seq) return;
        list.innerHTML = '<div class="loc-msg">Search failed — try again</div>';
    }
}
function thLocPick(i){
    const n = TH.results[i]; if (!n) return;
    TH.loc = thCut(n);
    $id('th-loc-input').value = '';
    thLocHide(); thLocRenderTag(); thStatus('');
}
document.addEventListener('click', function(e){
    const w = $id('th-loc-wrap');
    if (w && !w.contains(e.target)) $id('th-loc-list').classList.remove('show');
});
async function thSave(btn){
    btn.disabled = true; thStatus('Saving…');
    try {
        await hlCall('location_save', { location:TH.loc });
        thStatus('Saved. Reloading…', 'ok'); showToast('Location saved');
        setTimeout(function(){ window.location.href = window.location.pathname + '?id=' + GC_GID + '&open=location'; }, 600);
    } catch (e) { thStatus(e.message, 'error'); btn.disabled = false; }
}
thLocRenderTag();

/* ══════════ AUTO-MODERATION (owner + admins) ══════════ */
const AM = { words:AM_INIT.words.slice(), mode:AM_INIT.mode, whole:+AM_INIT.whole ? 1 : 0 };
function amStatus(m, kind){ const s = $id('am-status'); if (s) { s.textContent = m || ''; s.className = 'bar-status' + (kind ? ' ' + kind : ''); } }
function amRender(){
    const box = $id('am-chips'); if (!box) return;
    box.innerHTML = AM.words.length
        ? AM.words.map((w, i) => '<div class="chip"><span>' + esc(w) + '</span><button type="button" aria-label="Remove word" onclick="amDel(' + i + ')">×</button></div>').join('')
        : '<p class="no-items">No banned words yet.</p>';
    $id('am-count').textContent = AM.words.length + '/' + AM_MAX;
    document.querySelectorAll('#am-mode button').forEach(b => b.classList.toggle('on', b.dataset.mode === AM.mode));
    $id('am-mode-help').innerHTML = AM.mode === 'block'
        ? '<b>Block</b>: the post is not published and the member sees a short message.'
        : '<b>Send to Reports</b>: the post goes live but is added to Reported Posts for you to review.';
    $id('am-whole-note').innerHTML = AM.whole
        ? '<strong>Whole words only.</strong> "ass" will not match "class".'
        : '<strong>Any match.</strong> "ass" also matches "class".';
    const wb = $id('am-whole-btn');
    wb.textContent = AM.whole ? 'Use any match' : 'Whole words only';
    wb.className = 'toggle-btn ' + (AM.whole ? 'off' : 'on');
}
function amAdd(){
    const inp = $id('am-input'), parts = inp.value.split(/[,\n]/);
    let added = 0, skipped = 0;
    parts.forEach(p => {
        const w = p.replace(/\s+/g, ' ').trim().slice(0, AM_LEN);
        if (!w) return;
        if (AM.words.some(x => x.toLowerCase() === w.toLowerCase())) { skipped++; return; }
        if (AM.words.length >= AM_MAX) { skipped++; return; }
        AM.words.push(w); added++;
    });
    inp.value = ''; amRender();
    amStatus(skipped ? skipped + ' skipped (already in the list or the list is full).' : '', skipped ? 'error' : '');
    inp.focus({ preventScroll:true });
}
function amDel(i){ AM.words.splice(i, 1); amRender(); amStatus(''); }
function amMode(m){ AM.mode = m; amRender(); }
function amWhole(){ AM.whole = AM.whole ? 0 : 1; amRender(); }
async function amSave(btn){
    btn.disabled = true; amStatus('Saving…');
    try {
        await hlCall('automod_save', { words:JSON.stringify(AM.words), mode:AM.mode, whole:AM.whole });
        amStatus('Saved.', 'ok'); showToast('Auto-moderation saved');
        setTimeout(function(){ window.location.href = window.location.pathname + '?id=' + GC_GID + '&open=automod'; }, 600);
    } catch (e) { amStatus(e.message, 'error'); btn.disabled = false; }
}
amRender();

/* ══════════ SLOW MODE (owner + admins) ══════════ */
const SM = { secs:SM_INIT };
function smLabel(s){
    if (!s) return 'Off';
    if (s < 60) return s + ' seconds';
    if (s < 3600) { const m = Math.round(s / 60); return m + (m === 1 ? ' minute' : ' minutes'); }
    const h = Math.round(s / 3600); return h + (h === 1 ? ' hour' : ' hours');
}
function smStatus(m, kind){ const s = $id('sm-status'); if (s) { s.textContent = m || ''; s.className = 'bar-status' + (kind ? ' ' + kind : ''); } }
function smRender(){
    const g = $id('sm-grid'); if (!g) return;
    g.innerHTML = SM_OPTS.map(s => '<button type="button" class="sm-opt' + (s === SM.secs ? ' on' : '') + '" onclick="smPick(' + s + ')">' + smLabel(s) + '</button>').join('');
    $id('sm-help').textContent = SM.secs ? 'Each member can post once every ' + smLabel(SM.secs).toLowerCase() + '.' : 'Slow mode is off. Members can post as often as they like.';
}
function smPick(s){ SM.secs = s; smRender(); smStatus(''); }
async function smSave(btn){
    btn.disabled = true; smStatus('Saving…');
    try {
        await hlCall('slowmode_save', { seconds:SM.secs });
        smStatus('Saved.', 'ok'); showToast('Slow mode saved');
        setTimeout(function(){ window.location.href = window.location.pathname + '?id=' + GC_GID + '&open=slowmode'; }, 600);
    } catch (e) { smStatus(e.message, 'error'); btn.disabled = false; }
}
smRender();

/* ══════════ BAN REASON / NOTE ══════════ */
const BR = { mode:'ban', uid:0, name:'', form:null };
const BR_QUICK = ['Spam', 'Harassment', 'Hate speech', 'Broke group rules', 'Inappropriate content'];
function brStatus(m, kind){ const s = $id('br-status'); if (s) { s.textContent = m || ''; s.className = 'bar-status' + (kind ? ' ' + kind : ''); } }
function brCount(){ $id('br-count').textContent = $id('br-text').value.length + '/' + BR_LEN; }
function brQuick(i){ $id('br-text').value = BR_QUICK[i]; brCount(); }
function brOpen(o){
    Object.assign(BR, o);
    $id('br-title').textContent = o.mode === 'ban' ? 'Ban member' : 'Ban reason';
    $id('br-info').innerHTML = o.mode === 'ban'
        ? 'You are about to ban <b>' + esc(o.name) + '</b>. ' + esc(o.scope || '')
        : 'Reason for banning <b>' + esc(o.name) + '</b>. Leave it empty to remove the note.';
    $id('br-text').value = o.reason || ''; brCount(); brStatus('');
    $id('br-quick').innerHTML = BR_QUICK.map((q, i) => '<button type="button" class="q-chip" onclick="brQuick(' + i + ')">' + esc(q) + '</button>').join('');
    const b = $id('br-btn'); b.disabled = false; b.textContent = o.mode === 'ban' ? 'Ban member' : 'Save reason';
    openModal('modal-banreason');
}
function gcBan(btn){
    const form = btn.closest('form'), uid = +form.querySelector('[name=ban_user_id]').value;
    brOpen({ mode:'ban', uid:uid, name:btn.dataset.name || '', scope:btn.dataset.scope || '', reason:'', form:form });
}
function gcBanNote(btn){ brOpen({ mode:'note', uid:+btn.dataset.uid, name:btn.dataset.name || '', scope:'', reason:btn.dataset.reason || '', form:null }); }
async function brConfirm(btn){
    btn.disabled = true; brStatus(BR.mode === 'ban' ? 'Banning…' : 'Saving…');
    try {
        await hlCall('ban_note_set', { user_id:BR.uid, reason:$id('br-text').value.trim() });
        if (BR.mode === 'ban') {
            const f = BR.form, h = document.createElement('input');
            h.type = 'hidden'; h.name = 'ban_member'; h.value = '1'; f.appendChild(h); f.submit(); return;
        }
        showToast('Reason saved');
        window.location.href = window.location.pathname + '?id=' + GC_GID + '&open=banned';
    } catch (e) { brStatus(e.message, 'error'); btn.disabled = false; }
}

/* ══════════ WARNINGS (owner + admins) ══════════ */
const WL = { n:WL_INIT };
function wlStatus(m, kind){ const s = $id('wl-status'); if (s) { s.textContent = m || ''; s.className = 'bar-status' + (kind ? ' ' + kind : ''); } }
function wlRender(){
    const g = $id('wl-grid'); if (!g) return;
    g.innerHTML = WL_OPTS.map(n => '<button type="button" class="sm-opt' + (n === WL.n ? ' on' : '') + '" onclick="wlPick(' + n + ')">' + (n ? n + ' strikes' : 'No limit') + '</button>').join('');
    $id('wl-help').textContent = WL.n ? 'When a member gets ' + WL.n + ' strikes you are asked whether to kick or ban them.' : 'No limit. Strikes are only a record and nothing is suggested automatically.';
}
function wlPick(n){ WL.n = n; wlRender(); wlStatus(''); }
async function wlSave(btn){
    btn.disabled = true; wlStatus('Saving…');
    try {
        await hlCall('warn_limit_save', { limit:WL.n });
        wlStatus('Saved.', 'ok'); showToast('Strike limit saved');
        setTimeout(function(){ window.location.href = window.location.pathname + '?id=' + GC_GID + '&open=warnings'; }, 600);
    } catch (e) { wlStatus(e.message, 'error'); btn.disabled = false; }
}
wlRender();

const WN = { uid:0, name:'' };
const WN_QUICK = ['Off-topic post', 'Disrespectful comment', 'Spam', 'Low effort or repost', 'Broke group rules'];
function wnStatus(m, kind){ const s = $id('wn-status'); if (s) { s.textContent = m || ''; s.className = 'bar-status' + (kind ? ' ' + kind : ''); } }
function wnCount(){ $id('wn-count').textContent = $id('wn-text').value.length + '/' + BR_LEN; }
function wnQuick(i){ $id('wn-text').value = WN_QUICK[i]; wnCount(); }
function gcWarn(btn){
    WN.uid = +btn.dataset.uid; WN.name = btn.dataset.name || '';
    $id('wn-info').innerHTML = 'You are giving <b>' + esc(WN.name) + '</b> a strike. They keep their place in the group.';
    $id('wn-text').value = ''; wnCount(); wnStatus('');
    $id('wn-quick').innerHTML = WN_QUICK.map((q, i) => '<button type="button" class="q-chip" onclick="wnQuick(' + i + ')">' + esc(q) + '</button>').join('');
    const b = $id('wn-btn'); b.disabled = false;
    openModal('modal-warn');
}
async function wnConfirm(btn){
    const reason = $id('wn-text').value.trim();
    if (!reason) { wnStatus('Add a short reason for the warning.', 'error'); return; }
    btn.disabled = true; wnStatus('Saving…');
    try {
        const d = await hlCall('warn_issue', { user_id:WN.uid, reason:reason });
        showToast(d.name + ' now has ' + d.count + ' strike' + (d.count === 1 ? '' : 's'));
        $id('modal-warn').classList.remove('active');
        if (d.reached) { limOpen(d); return; }
        setTimeout(function(){ window.location.href = window.location.pathname + '?id=' + GC_GID + '&open=warnings'; }, 500);
    } catch (e) { wnStatus(e.message, 'error'); btn.disabled = false; }
}

const LIM = { uid:0, name:'' };
function limOpen(d){
    LIM.uid = d.uid; LIM.name = d.name;
    $id('lim-info').innerHTML = '<b>' + esc(d.name) + '</b> now has <b>' + d.count + '</b> strike' + (d.count === 1 ? '' : 's') + ' and has reached the limit of ' + d.limit + '. What would you like to do?';
    $id('lim-status').textContent = '';
    openModal('modal-limit');
}
function limLater(){ window.location.href = window.location.pathname + '?id=' + GC_GID + '&open=warnings'; }
function limPost(fields){
    const f = document.createElement('form');
    f.method = 'POST'; f.action = 'group.php?id=' + GC_GID;
    Object.keys(fields).forEach(k => { const i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = fields[k]; f.appendChild(i); });
    document.body.appendChild(f); f.submit();
}
async function limAct(kind){
    const st = $id('lim-status');
    if (!confirm((kind === 'ban' ? 'Ban ' : 'Kick ') + LIM.name + '?')) return;
    st.className = 'bar-status'; st.textContent = 'Working…';
    try {
        if (kind === 'ban') {
            try { await hlCall('ban_note_set', { user_id:LIM.uid, reason:'Reached the warning limit' }); } catch (e) {}
            limPost({ csrf_token:GC_CSRF, ban_user_id:LIM.uid, ban_member:'1' });
        } else {
            limPost({ csrf_token:GC_CSRF, remove_member_id:LIM.uid, remove_member:'1' });
        }
    } catch (e) { st.className = 'bar-status error'; st.textContent = e.message; }
}

const WH = { uid:0, name:'', changed:false };
function whStatus(m, kind){ const s = $id('wh-status'); if (s) { s.textContent = m || ''; s.className = 'bar-status' + (kind ? ' ' + kind : ''); } }
function whOpen(btn){
    WH.uid = +btn.dataset.uid; WH.name = btn.dataset.name || ''; WH.changed = false;
    $id('wh-title').textContent = WH.name + ' · strikes';
    $id('wh-list').innerHTML = '<p class="no-items">Loading…</p>'; $id('wh-clear').style.display = 'none'; whStatus('');
    openModal('modal-warnhist'); whLoad();
}
async function whLoad(){
    try {
        const items = (await hlCall('warn_history', { user_id:WH.uid })).items;
        $id('wh-list').innerHTML = items.length
            ? items.map(w => '<div class="row-item" style="align-items:flex-start"><div class="row-info"><div class="row-name" style="white-space:normal">' + esc(w.reason) + '</div><div class="row-desc-sub">by ' + esc(w.by) + ' · ' + esc(w.date) + '</div></div>' +
                '<div class="row-actions"><button type="button" class="tiny-btn kick" onclick="whDel(' + w.id + ')"><i class="ri-close-line"></i> Remove</button></div></div>').join('')
            : '<p class="no-items">No active strikes. This member is clear.</p>';
        $id('wh-clear').style.display = items.length > 1 ? '' : 'none';
    } catch (e) { whStatus(e.message, 'error'); }
}
async function whDel(id){
    try { await hlCall('warn_remove', { warning_id:id }); WH.changed = true; whStatus(''); await whLoad(); }
    catch (e) { whStatus(e.message, 'error'); }
}
async function whClear(btn){
    if (!confirm('Remove all strikes from ' + WH.name + '?')) return;
    btn.disabled = true;
    try { await hlCall('warn_clear', { user_id:WH.uid }); WH.changed = true; await whLoad(); }
    catch (e) { whStatus(e.message, 'error'); }
    btn.disabled = false;
}
function whClose(){
    closeModal('modal-warnhist');
    if (WH.changed) window.location.href = window.location.pathname + '?id=' + GC_GID + '&open=warnings';
}

/* ══════════ NEW MEMBER PROBATION + PENDING POSTS ══════════ */
const PB = { n:PB_INIT };
function pbStatus(m, kind){ const s = $id('pb-status'); if (s) { s.textContent = m || ''; s.className = 'bar-status' + (kind ? ' ' + kind : ''); } }
function pbRender(){
    const g = $id('pb-grid'); if (!g) return;
    g.innerHTML = PB_OPTS.map(n => '<button type="button" class="sm-opt' + (n === PB.n ? ' on' : '') + '" onclick="pbPick(' + n + ')">' + (n ? 'First ' + n + (n === 1 ? ' post' : ' posts') : 'Off') + '</button>').join('');
    $id('pb-help').textContent = PB.n ? 'A new member\'s first ' + PB.n + (PB.n === 1 ? ' post waits' : ' posts wait') + ' for approval. After that they post freely.' : 'Probation is off. Everyone can post straight away.';
}
function pbPick(n){ PB.n = n; pbRender(); pbStatus(''); }
async function pbSave(btn){
    btn.disabled = true; pbStatus('Saving…');
    try {
        await hlCall('probation_save', { posts:PB.n });
        pbStatus('Saved.', 'ok'); showToast('Probation saved');
        setTimeout(function(){ window.location.href = window.location.pathname + '?id=' + GC_GID + '&open=probation'; }, 600);
    } catch (e) { pbStatus(e.message, 'error'); btn.disabled = false; }
}
pbRender();
async function ppApprove(btn, id){
    btn.disabled = true;
    try {
        await hlCall('post_approve', { group_post_id:id });
        const row = btn.closest('.row-item'); if (row) row.remove();
        const left = document.querySelectorAll('#pp-list .row-item').length;
        $id('pp-count').textContent = left;
        showToast('Post approved');
        if (!left) setTimeout(function(){ window.location.href = window.location.pathname + '?id=' + GC_GID + '&open=pending'; }, 500);
    } catch (e) { showToast(e.message); btn.disabled = false; }
}
async function ppApproveAll(btn){
    if (!confirm('Approve every post that is waiting?')) return;
    btn.disabled = true;
    try { await hlCall('post_approve_all', {}); showToast('All posts approved'); setTimeout(function(){ window.location.href = window.location.pathname + '?id=' + GC_GID + '&open=pending'; }, 500); }
    catch (e) { showToast(e.message); btn.disabled = false; }
}

/* ══════════ TRANSFER OWNERSHIP (owner only) ══════════ */
function trStatus(m, kind){ const s = $id('tr-status'); if (s) { s.textContent = m || ''; s.className = 'bar-status' + (kind ? ' ' + kind : ''); } }
async function trSave(btn){
    const uid = $id('tr-user').value, typed = $id('tr-confirm').value.trim();
    if (!uid) { trStatus('Choose an admin to receive ownership.', 'error'); return; }
    if (typed.toLowerCase() !== TR_TITLE.trim().toLowerCase()) { trStatus('Type the group name exactly to confirm.', 'error'); return; }
    const name = $id('tr-user').options[$id('tr-user').selectedIndex].text;
    if (!confirm('Make ' + name + ' the owner of this group? You will become an admin.')) return;
    btn.disabled = true; trStatus('Transferring…');
    try {
        await hlCall('transfer_owner', { user_id:uid, confirm:typed });
        showToast('Ownership transferred');
        setTimeout(function(){ window.location.href = window.location.pathname + '?id=' + GC_GID; }, 700);
    } catch (e) { trStatus(e.message, 'error'); btn.disabled = false; }
}

/* ══════════ GROUP RULES (each rule = one bar, saved one per line) ══════════ */
let rules = RULES_INIT.length ? RULES_INIT.slice() : [''];
function rlStatus(m, err){ const s = $id('rl-status'); if (s) { s.textContent = m || ''; s.className = 'bar-status' + (err ? ' error' : ''); } }
function rlRender(){
    const box = $id('rl-list'); if (!box) return;
    box.innerHTML = rules.map((v, i) =>
        '<div class="bar-item"><span class="bdot"></span>' +
        '<input type="text" maxlength="' + RULE_LEN + '" placeholder="Rule ' + (i + 1) + ', e.g. Stay on topic" value="' + esc(v) + '" oninput="rules[' + i + ']=this.value">' +
        '<button type="button" class="bx" aria-label="Remove rule" onclick="rlDel(' + i + ')"><i class="ri-close-line"></i></button></div>').join('');
    $id('rl-count').textContent = rules.filter(r => r.trim()).length + '/' + RULES_MAX;
    $id('rl-add').disabled = rules.length >= RULES_MAX;
}
function rlAdd(){ if (rules.length >= RULES_MAX) return; rules.push(''); rlRender(); const ins = $id('rl-list').querySelectorAll('input'); if (ins.length) ins[ins.length - 1].focus(); }
function rlDel(i){ rules.splice(i, 1); if (!rules.length) rules = ['']; rlRender(); }
function rlSubmit(form){
    const clean = rules.map(r => r.replace(/\s+/g, ' ').trim()).filter(Boolean).slice(0, RULES_MAX);
    $id('rl-value').value = clean.join('\n');
    return true;
}
rlRender();

/* ══════════ JOIN QUESTIONS (owner only, max 10, all optional to set up) ══════════ */
let jq = JQ_INIT.length ? JQ_INIT.map(x => ({ q:x.q, req:+x.req ? 1 : 0 })) : [];
function jqStatus(m, kind){ const s = $id('jq-status'); if (s) { s.textContent = m || ''; s.className = 'bar-status' + (kind ? ' ' + kind : ''); } }
function jqRender(){
    const box = $id('jq-list'); if (!box) return;
    box.innerHTML = jq.map((it, i) =>
        '<div class="bar-item"><span class="bnum">' + (i + 1) + '</span>' +
        '<input type="text" maxlength="' + JQ_LEN + '" placeholder="Question ' + (i + 1) + ', e.g. Why do you want to join?" value="' + esc(it.q) + '" oninput="jq[' + i + '].q=this.value;jqCount()">' +
        '<button type="button" class="req-chip' + (it.req ? ' on' : '') + '" onclick="jqReq(' + i + ')">' + (it.req ? 'Required' : 'Optional') + '</button>' +
        '<button type="button" class="bx" aria-label="Remove question" onclick="jqDel(' + i + ')"><i class="ri-close-line"></i></button></div>').join('');
    jqCount();
    $id('jq-add').disabled = jq.length >= JQ_MAX;
}
function jqCount(){ const n = jq.filter(x => x.q.trim()).length; const c = $id('jq-count'); if (c) c.textContent = n + '/' + JQ_MAX; }
function jqAdd(){ if (jq.length >= JQ_MAX) return; jq.push({ q:'', req:0 }); jqRender(); const ins = $id('jq-list').querySelectorAll('input'); if (ins.length) ins[ins.length - 1].focus(); }
function jqDel(i){ jq.splice(i, 1); jqRender(); }
function jqReq(i){ jq[i].req = jq[i].req ? 0 : 1; jqRender(); }
async function jqSave(btn){
    const list = jq.map(x => ({ q:x.q.replace(/\s+/g, ' ').trim(), req:x.req })).filter(x => x.q);
    if (list.length > JQ_MAX) { jqStatus('You can set up to ' + JQ_MAX + ' questions.', 'error'); return; }
    btn.disabled = true; jqStatus('Saving…');
    try {
        await hlCall('jq_save', { questions:JSON.stringify(list) });
        jq = list.map(x => ({ q:x.q, req:x.req })); jqRender();
        jqStatus(list.length ? list.length + ' question' + (list.length === 1 ? '' : 's') + ' saved.' : 'Join questions are switched off.', 'ok');
        showToast('Join questions saved');
    } catch (e) { jqStatus(e.message, 'error'); }
    btn.disabled = false;
}
jqRender();

/* ══════════ GROUP HIGHLIGHTS ══════════ */
const HL_MAX = 100, HL_IMG_MB = 10, HL_VID_MB = 50;
let hlItems = [], hlMode = 'whole', hlBusy = false, hlCover = null, hlSong = null, hlCur = null, hlDirty = false, hlStage = [];
let hlP = null, hlSaving = false;

const hlResolveCache = {};
async function hlResolve(text){
    const t = String(text || '').trim();
    if (!t) return { users:{}, groups:[] };
    if (hlResolveCache[t]) return hlResolveCache[t];
    try {
        const d = await hlCall('resolve', { text:t });
        const map = { users:d.users || {}, groups:d.groups || [] };
        hlResolveCache[t] = map; return map;
    } catch (e) { return { users:{}, groups:[] }; }
}
function hlRichText(text, map){
    text = String(text || ''); map = map || {};
    const users = map.users || {};
    const groups = (map.groups || []).slice().sort((a, b) => b.name.length - a.name.length);
    const gid = {}; groups.forEach(g => { gid[g.name.toLowerCase()] = g.id; });
    let re = '(@[A-Za-z0-9_.]+)';
    if (groups.length) re += '|(^|[^\\w@])(' + groups.map(g => g.name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|') + ')(?![\\w])';
    const rx = new RegExp(re, 'gi');
    let out = '', last = 0, m;
    while ((m = rx.exec(text)) !== null) {
        if (m[1]) {
            const core = m[1].slice(1).replace(/\.+$/, ''), id = users[core.toLowerCase()];
            if (!id) continue;
            out += esc(text.slice(last, m.index)) + '<a class="hl-men user" href="' + esc(HL_USER_URL + id) + '">@' + esc(core) + '</a>';
            last = m.index + 1 + core.length; rx.lastIndex = last;
        } else {
            const start = m.index + m[2].length, id = gid[m[3].toLowerCase()];
            if (!id) continue;
            out += esc(text.slice(last, start)) + '<a class="hl-men group" href="' + esc(HL_GROUP_URL + id) + '">' + esc(m[3]) + '</a>';
            last = start + m[3].length;
        }
    }
    return (out + esc(text.slice(last))).replace(/\n/g, '<br>');
}
function hlCapHTML(text, map){
    const long = text.length > 120 || (text.match(/\n/g) || []).length >= 3;
    return '<div class="hl-cap-wrap"><div class="hl-cap' + (long ? ' clamp' : '') + '">' + hlRichText(text, map) + '</div>' +
        (long ? '<button type="button" class="hl-more" onclick="hlMore(this)">more</button>' : '') + '</div>';
}
function hlMore(btn){
    const cap = btn.previousElementSibling, collapsed = cap.classList.toggle('clamp');
    btn.textContent = collapsed ? 'more' : 'less';
}
function hlPollCard(q, labels, counts, meta){
    const tot = counts ? counts.reduce((a, b) => a + b, 0) : 0;
    return '<div class="hl-poll"><div class="hl-poll-tag"><i class="ri-bar-chart-horizontal-fill"></i> Poll</div>' +
        '<div class="hl-poll-q">' + (String(q).trim() ? esc(q) : 'Your question appears here') + '</div>' +
        labels.map((l, i) => {
            const pct = tot ? Math.round((counts[i] || 0) / tot * 100) : 0;
            return '<div class="hl-poll-opt"><i class="bar" style="width:' + pct + '%"></i><span>' + (String(l).trim() ? esc(l) : 'Option ' + (i + 1)) + '</span>' + (counts ? '<span>' + pct + '%</span>' : '') + '</div>';
        }).join('') +
        (meta ? '<div class="hl-poll-meta">' + esc(meta) + '</div>' : '') + '</div>';
}
function hlFilterFiles(list, max){
    let ok = [], skipped = 0;
    list.forEach(f => {
        const isImg = f.type.startsWith('image/'), isVid = f.type.startsWith('video/');
        if (!isImg && !isVid) { skipped++; return; }
        if (f.size > (isImg ? HL_IMG_MB : HL_VID_MB) * 1048576) { skipped++; return; }
        ok.push(f);
    });
    let trimmed = false;
    if (ok.length > max) { ok = ok.slice(0, max); trimmed = true; }
    return { ok, skipped, trimmed };
}
const hlMake = files => files.map(f => ({ file:f, kind:f.type.startsWith('video/') ? 'video' : 'image', text:'', poll:null, song:null, mute:false, url:URL.createObjectURL(f) }));
const hlFree = arr => arr.forEach(it => { if (it.url) URL.revokeObjectURL(it.url); });
const hlIsVid = it => it.kind === 'video';
const hlIsExtra = it => it.kind === 'text' || it.kind === 'poll';
const hlArr = ctx => ctx === 'c' ? hlItems : hlStage;

(function initHighlights(){
    const input = $id('hl-files');
    if (input) input.addEventListener('change', function(){
        const extras = hlItems.filter(hlIsExtra);
        const r = hlFilterFiles(Array.from(input.files || []), Math.max(0, HL_MAX - extras.length));
        let msg = '';
        if (r.trimmed) msg = 'Only the first ' + HL_MAX + ' items were kept.';
        if (r.skipped) msg = (msg ? msg + ' ' : '') + r.skipped + ' file' + (r.skipped === 1 ? ' was' : 's were') + ' skipped (wrong type or too large).';
        hlFree(hlItems.filter(i => !hlIsExtra(i)));
        hlItems = hlMake(r.ok).concat(extras);
        hlRenderPick(); hlRenderItems('c'); hlSetStatus(msg, !!msg && !hlItems.length);
        input.value = '';
    });
    const cv = $id('hl-cover-file');
    if (cv) cv.addEventListener('change', function(){
        const f = cv.files && cv.files[0]; cv.value = '';
        if (!f) return;
        if (!f.type.startsWith('image/') || f.size > HL_IMG_MB * 1048576) { hlSetStatus('The cover must be an image under ' + HL_IMG_MB + ' MB.', true); return; }
        hlCover = f; hlRenderExtras(); hlSetStatus('');
    });
})();

function hlSetStatus(msg, isErr){
    const s = $id('hl-status'); if (!s) return;
    s.textContent = msg || ''; s.className = 'hl-status' + (isErr ? ' error' : '');
}
function hlRenderPick(){
    const title = $id('hl-pick-title'), sub = $id('hl-pick-sub');
    const prev = $id('hl-cover-prev'), icon = $id('hl-pick-icon');
    if (prev._url) { URL.revokeObjectURL(prev._url); prev._url = null; }
    if (!hlItems.length) {
        title.textContent = 'Choose photos or videos'; sub.textContent = 'Add 1 to 100 per highlight.';
        prev.classList.remove('show'); icon.style.display = ''; icon.className = 'ri-image-add-line'; return;
    }
    title.textContent = hlItems.length + ' of ' + HL_MAX + ' selected';
    sub.textContent = 'Tap to change your photos and videos.';
    const first = hlItems[0];
    if (first.kind === 'image') {
        prev._url = URL.createObjectURL(first.file); prev.src = prev._url; prev.classList.add('show'); icon.style.display = 'none';
    } else {
        prev.classList.remove('show'); icon.style.display = '';
        icon.className = first.kind === 'video' ? 'ri-video-line' : first.kind === 'poll' ? 'ri-bar-chart-horizontal-line' : 'ri-text';
    }
}
function hlRenderExtras(){
    const cvPrev = $id('hl-cv-prev'), cvIcon = $id('hl-cv-icon');
    if (cvPrev._url) { URL.revokeObjectURL(cvPrev._url); cvPrev._url = null; }
    if (hlCover) {
        cvPrev._url = URL.createObjectURL(hlCover); cvPrev.src = cvPrev._url; cvPrev.classList.add('show'); cvIcon.style.display = 'none';
        $id('hl-cv-title').textContent = 'Cover image added';
        $id('hl-cv-sub').textContent = hlShort(hlCover.name, 34);
    } else {
        cvPrev.classList.remove('show'); cvIcon.style.display = '';
        $id('hl-cv-title').textContent = 'Cover image (optional)';
        $id('hl-cv-sub').textContent = 'Skip it and your first media becomes the cover.';
    }
    $id('hl-cv-x').classList.toggle('show', !!hlCover);
    $id('hl-song-title').textContent = hlSong ? '♪ ' + hlShort(hlSong.title || 'Song clip', 30) : 'Song clip (optional)';
    $id('hl-song-sub').textContent = hlSong ? Math.round(hlSong.secs) + ' second clip. Tap to change it.' : 'Pick the part of a song you want, up to 30 seconds.';
    $id('hl-song-x').classList.toggle('show', !!hlSong);
}
function hlCoverClear(){ hlCover = null; hlRenderExtras(); }
function hlSongClear(){ hlSong = null; hlRenderExtras(); }
function hlSongPick(){ cpOpen(res => { hlSong = res; hlRenderExtras(); }); }

function hlSetMode(mode){
    hlMode = mode;
    document.querySelectorAll('#hl-mode button').forEach(b => b.classList.toggle('on', b.dataset.mode === mode));
    $id('hl-song-wrap').style.display = mode === 'whole' ? '' : 'none';
    $id('hl-mode-help').textContent = mode === 'whole'
        ? 'One song clip plays across every media in this highlight.'
        : 'Each media can have its own song clip. Choose your media, then tap Add song on the ones you want.';
    hlRenderItems('c');
}

function hlRows(ctx, mode){
    const arr = hlArr(ctx);
    return arr.map((it, i) => {
        let acts = '';
        if (it.kind === 'poll') acts += '<button type="button" class="hle-chip on" onclick="hlItemPoll(\'' + ctx + '\',' + i + ')"><i class="ri-bar-chart-horizontal-line"></i>Edit poll</button>';
        else acts += '<button type="button" class="hle-chip' + (it.text ? ' on' : '') + '" onclick="hlItemText(\'' + ctx + '\',' + i + ')"><i class="ri-text"></i>' + (it.text ? 'Edit text' : 'Add text') + '</button>';
        if (mode === 'each') {
            acts += '<button type="button" class="hle-chip' + (it.song ? ' on' : '') + '" onclick="hlItemSong(\'' + ctx + '\',' + i + ')"><i class="ri-music-2-line"></i>' + (it.song ? esc(hlShort(it.song.title || 'Song', 12)) : 'Add song') + '</button>';
            if (it.song) acts += '<button type="button" class="hle-chip x" aria-label="Remove song" onclick="hlItemSongRm(\'' + ctx + '\',' + i + ')"><i class="ri-close-line"></i></button>';
        }
        if (hlIsVid(it)) acts += '<button type="button" class="hle-chip' + (it.mute ? ' on' : '') + '" onclick="hlItemMute(\'' + ctx + '\',' + i + ')"><i class="' + (it.mute ? 'ri-volume-mute-line' : 'ri-volume-up-line') + '"></i>' + (it.mute ? 'Muted' : 'Sound') + '</button>';
        const th = it.kind === 'image'
            ? '<div class="hl-row-th"><img src="' + it.url + '" alt=""></div>'
            : '<div class="hl-row-th' + (it.kind === 'poll' ? ' poll' : '') + '"><i class="' + (it.kind === 'video' ? 'ri-video-line' : it.kind === 'poll' ? 'ri-bar-chart-horizontal-line' : 'ri-text') + '"></i></div>';
        const nm = it.kind === 'text' ? 'Text: ' + hlShort(it.text.replace(/\s+/g, ' '), 24)
                 : it.kind === 'poll' ? 'Poll: ' + hlShort(it.poll.q, 24) : hlShort(it.file.name, 28);
        return '<div class="hl-row">' + th +
            '<div class="hl-row-info"><strong>' + esc(nm) + '</strong><div class="hle-actions">' + acts + '</div></div>' +
            '<button type="button" class="hl-x show" aria-label="Remove from selection" onclick="hlItemDel(\'' + ctx + '\',' + i + ')"><i class="ri-close-line"></i></button></div>';
    }).join('');
}
function hlRenderItems(ctx){
    if (ctx === 'e') { hlRenderStageBox(); return; }
    const box = $id('hl-items'); if (!box) return;
    const show = hlItems.length > 0;
    box.innerHTML = show
        ? '<div class="hl-rows-head">Tap Add text to write words on a media (tag people with @name).' + (hlMode === 'each' ? ' Tap Add song on any media that should have its own song.' : '') + (hlItems.some(hlIsVid) ? ' Videos: Sound keeps the video\'s own audio, Muted lets only the song play.' : '') + '</div><div class="hl-rows">' + hlRows('c', hlMode) + '</div>'
        : '';
    box.style.marginBottom = show ? '2px' : '0';
}
function hlItemSong(ctx, i){ cpOpen(res => { const it = hlArr(ctx)[i]; if (it) { it.song = res; hlRenderItems(ctx); } }); }
function hlItemSongRm(ctx, i){ const it = hlArr(ctx)[i]; if (it) { it.song = null; hlRenderItems(ctx); } }
function hlItemMute(ctx, i){ const it = hlArr(ctx)[i]; if (it) { it.mute = !it.mute; hlRenderItems(ctx); } }
function hlItemDel(ctx, i){
    const arr = hlArr(ctx), it = arr[i]; if (!it) return;
    if (it.url) URL.revokeObjectURL(it.url);
    arr.splice(i, 1);
    if (ctx === 'c') hlRenderPick();
    hlRenderItems(ctx);
}
function hlItemText(ctx, i){
    const it = hlArr(ctx)[i]; if (!it) return;
    htOpen({ src:it.url, type:it.kind, text:it.text, required:it.kind === 'text', allowRemove:it.kind !== 'text' && !!it.text }, t => {
        const cur = hlArr(ctx)[i]; if (!cur) return;
        cur.text = t; if (ctx === 'c') hlRenderPick(); hlRenderItems(ctx);
    });
}
function hlItemPoll(ctx, i){
    const it = hlArr(ctx)[i]; if (!it) return;
    plOpen({ poll:it.poll, existing:false }, p => { const cur = hlArr(ctx)[i]; if (cur) { cur.poll = p; hlRenderItems(ctx); } });
}
const hlUsed = ctx => ctx === 'c' ? hlItems.length : (hlCur.media.length - hlP.del.length + hlStage.length);
function hlAddText(ctx){
    if (hlUsed(ctx) >= HL_MAX) { showToast('A highlight can hold up to ' + HL_MAX + ' media.'); return; }
    htOpen({ src:null, type:'text', text:'', required:true, allowRemove:false }, t => {
        hlArr(ctx).push({ file:null, kind:'text', text:t, poll:null, song:null, mute:false, url:null });
        if (ctx === 'c') hlRenderPick();
        hlRenderItems(ctx);
    });
}
function hlAddPoll(ctx){
    if (hlUsed(ctx) >= HL_MAX) { showToast('A highlight can hold up to ' + HL_MAX + ' media.'); return; }
    plOpen({ poll:null, existing:false }, p => {
        hlArr(ctx).push({ file:null, kind:'poll', text:'', poll:p, song:null, mute:false, url:null });
        if (ctx === 'c') hlRenderPick();
        hlRenderItems(ctx);
    });
}
const hlAddCall = (id, it) =>
    it.kind === 'text' ? hlCall('add_text', { highlight_id:id, text:it.text })
    : it.kind === 'poll' ? hlCall('add_poll', { highlight_id:id, poll:JSON.stringify(it.poll) })
    : hlCall('add_media', { highlight_id:id, caption:it.text || '' }, { media:it.file });

async function hlUpload(btn){
    if (hlBusy) return;
    const titleEl = $id('hl-title'), name = titleEl.value.trim();
    if (!name) { hlSetStatus('Give your highlight a name.', true); titleEl.focus(); return; }
    if (!hlItems.length) { hlSetStatus('Choose at least one photo or video, or add a text highlight or a poll.', true); return; }

    hlBusy = true; btn.disabled = true;
    const orig = btn.textContent; btn.textContent = 'Uploading…';
    const bar = $id('hl-progress'), fill = $id('hl-progress-bar');
    bar.classList.add('show'); fill.style.width = '0';
    hlSetStatus('Creating highlight…');

    const useSong = hlMode === 'whole' && hlSong;
    const items = hlItems.slice();
    let hid = 0, done = 0, failed = 0, lastErr = '', extraErr = '';
    const steps = items.length + (hlCover ? 1 : 0) + (useSong ? 1 : 0); let stepNo = 0;
    const tick = () => { stepNo++; fill.style.width = Math.round(stepNo / steps * 100) + '%'; };
    try {
        hid = (await hlCall('create', { title:name, mode:hlMode })).id;
        for (let i = 0; i < items.length; i++) {
            const it = items[i];
            hlSetStatus('Uploading ' + (i + 1) + ' of ' + items.length + '…');
            try {
                const r = await hlAddCall(hid, it);
                done++;
                if (hlMode === 'each' && it.song) {
                    try { await hlCall('media_song', { highlight_id:hid, media_id:r.id, title:it.song.title || '' }, { song:it.song.blob }); }
                    catch (e) { extraErr = 'Some songs did not upload (' + e.message + ')'; }
                }
                if (hlIsVid(it) && it.mute) { try { await hlCall('media_mute', { highlight_id:hid, media_id:r.id, mute:1 }); } catch (e) {} }
            } catch (e) { failed++; lastErr = e.message; }
            tick();
        }
        if (done === 0) { try { await hlCall('delete', { highlight_id:hid }); } catch (e) {} throw new Error(lastErr || 'No files could be uploaded.'); }
        if (hlCover) { hlSetStatus('Uploading cover…'); try { await hlCall('set_cover', { highlight_id:hid }, { cover:hlCover }); } catch (e) { extraErr += (extraErr ? ' ' : '') + 'Cover: ' + e.message; } tick(); }
        if (useSong) { hlSetStatus('Uploading song clip…'); try { await hlCall('set_song', { highlight_id:hid, title:hlSong.title || '' }, { song:hlSong.blob }); } catch (e) { extraErr += (extraErr ? ' ' : '') + 'Song: ' + e.message; } tick(); }
        showToast(extraErr ? 'Highlight created. ' + extraErr : 'Highlight created' + (failed ? ' (' + failed + ' item' + (failed === 1 ? '' : 's') + ' failed)' : ''));
        hlSetStatus('Done.');
        setTimeout(hlReload, extraErr ? 1800 : 700);
    } catch (err) {
        hlSetStatus(err.message || 'Upload failed.', true);
        bar.classList.remove('show'); btn.disabled = false; btn.textContent = orig; hlBusy = false;
    }
}

/* ── Text editor sheet ── */
const HT = { cb:null, o:null, timer:null, seq:0, map:null };
function htOpen(o, cb){
    HT.cb = cb; HT.o = o; HT.map = null;
    $id('ht-input').value = o.text || '';
    $id('ht-status').textContent = ''; $id('ht-status').className = 'hl-status';
    $id('ht-remove').style.display = o.allowRemove ? '' : 'none';
    let bg;
    if (o.type === 'image' && o.src) bg = '<img src="' + esc(o.src) + '" alt="">';
    else if (o.type === 'video' && o.src) bg = '<video src="' + esc(o.src) + '" muted playsinline loop autoplay></video>';
    else bg = '<img src="' + esc(HL_TEXT_BG) + '" alt="">';
    $id('ht-preview').innerHTML = '<div class="hl-view">' + bg + '<div id="ht-capbox"></div></div>';
    const m = $id('modal-ht'); m.classList.add('active'); document.body.style.overflow = 'hidden'; m.scrollTop = 0;
    htUpdate(true);
    setTimeout(() => $id('ht-input').focus({ preventScroll:true }), 80);
}
function htRender(t){
    const box = $id('ht-capbox'); if (!box) return;
    box.innerHTML = t.trim() ? hlCapHTML(t, HT.map) : '<div class="hl-cap-wrap"><div class="hl-cap hl-cap-empty">Your text appears here</div></div>';
}
function htUpdate(now){
    const t = $id('ht-input').value;
    $id('ht-count').textContent = t.length + '/1000';
    htRender(t);
    clearTimeout(HT.timer);
    HT.timer = setTimeout(async () => {
        const seq = ++HT.seq;
        const map = await hlResolve(t);
        if (seq !== HT.seq) return;
        HT.map = map; htRender($id('ht-input').value);
    }, now ? 0 : 350);
}
function htDone(){
    const t = $id('ht-input').value.trim();
    if (HT.o && HT.o.required && !t) { const s = $id('ht-status'); s.textContent = 'Write something first.'; s.className = 'hl-status error'; return; }
    const cb = HT.cb; htClose(); if (cb) cb(t);
}
function htRemove(){ const cb = HT.cb; htClose(); if (cb) cb(''); }
function htClose(){
    clearTimeout(HT.timer); HT.seq++; HT.cb = null;
    $id('modal-ht').classList.remove('active');
    $id('ht-preview').innerHTML = '';
    if (!document.querySelector('.modal-overlay.active')) document.body.style.overflow = '';
}

/* ══════════ POLL EDITOR ══════════ */
const PL = { cb:null, o:null, opts:['', ''], multi:0, results:'always', duration:0 };
function plOpen(o, cb){
    PL.cb = cb; PL.o = o;
    const p = o.poll;
    PL.opts = p ? p.options.slice() : ['', ''];
    PL.multi = p ? (+p.multi ? 1 : 0) : 0;
    PL.results = p ? p.results : 'always';
    PL.duration = p ? +p.duration : 0;
    $id('pl-q').value = p ? p.q : '';
    $id('pl-status').textContent = ''; $id('pl-status').className = 'hl-status';
    $id('pl-dur').innerHTML = (o.existing ? '<option value="-1">Keep current end time</option>' : '') +
        '<option value="0">No end time</option><option value="24">Ends after 1 day</option><option value="72">Ends after 3 days</option><option value="168">Ends after 7 days</option>';
    $id('pl-dur').value = String(PL.duration);
    $id('pl-res').value = PL.results;
    const lock = $id('pl-lock');
    if (o.existing && o.locked) {
        lock.innerHTML = '<div class="pl-lock"><i class="ri-lock-line"></i><div>This poll already has <strong style="color:var(--text)">' + o.total + ' voter' + (o.total === 1 ? '' : 's') + '</strong>. You can fix typos and change the question, results and end time, but options can\'t be added or removed and the answer type is locked so the votes stay fair.</div></div>';
    } else if (o.existing && o.closed) {
        lock.innerHTML = '<div class="pl-lock"><i class="ri-timer-line"></i><div>This poll has ended. Choose a new poll length to open it again.</div></div>';
    } else lock.innerHTML = '';
    plQ(); plOptsRender(); plMultiRender(); plPreview();
    const m = $id('modal-poll'); m.classList.add('active'); document.body.style.overflow = 'hidden'; m.scrollTop = 0;
}
const plLocked = () => !!(PL.o && PL.o.existing && PL.o.locked);
function plQ(){ $id('pl-q-count').textContent = $id('pl-q').value.length + '/140'; plPreview(); }
function plOptsRender(){
    const lk = plLocked();
    $id('pl-opts').innerHTML = PL.opts.map((v, i) =>
        '<div class="pl-opt-row"><span class="num">' + (i + 1) + '</span>' +
        '<input type="text" class="f-input" maxlength="60" placeholder="Option ' + (i + 1) + '" value="' + esc(v) + '" oninput="PL.opts[' + i + ']=this.value;plPreview()">' +
        (!lk && PL.opts.length > 2 ? '<button type="button" class="hl-x" style="display:flex" aria-label="Remove option" onclick="plOptDel(' + i + ')"><i class="ri-close-line"></i></button>' : '') + '</div>').join('');
    $id('pl-add').style.display = (!lk && PL.opts.length < 4) ? '' : 'none';
}
function plOptAdd(){ if (PL.opts.length < 4) { PL.opts.push(''); plOptsRender(); plPreview(); } }
function plOptDel(i){ if (PL.opts.length > 2) { PL.opts.splice(i, 1); plOptsRender(); plPreview(); } }
function plMultiRender(){
    document.querySelectorAll('#pl-multi-seg button').forEach(b => { b.classList.toggle('on', +b.dataset.m === PL.multi); b.disabled = plLocked(); });
}
function plMulti(v){ if (plLocked()) return; PL.multi = v; plMultiRender(); plPreview(); }
function plPreview(){
    const box = $id('pl-preview'); if (!box) return;
    const o = PL.o || {};
    const counts = (o.existing && o.counts) ? PL.opts.map((_, i) => o.counts[i] || 0) : null;
    let meta = PL.multi ? 'Members can pick several' : 'Members pick one';
    if (o.existing && o.total != null) meta += ' · ' + o.total + ' voter' + (o.total === 1 ? '' : 's');
    box.innerHTML = hlPollCard($id('pl-q').value, PL.opts, counts, meta);
}
function plDone(){
    const q = $id('pl-q').value.trim();
    const opts = PL.opts.map(s => s.trim());
    const st = $id('pl-status'); st.className = 'hl-status error';
    if (!q) { st.textContent = 'Write a question first.'; return; }
    if (opts.some(s => !s)) { st.textContent = 'Fill in every option, or remove the empty ones.'; return; }
    if (opts.length < 2) { st.textContent = 'A poll needs at least 2 options.'; return; }
    const cb = PL.cb, poll = { q:q, multi:PL.multi, results:PL.results, duration:PL.duration, options:opts };
    plClose(); if (cb) cb(poll);
}
function plClose(){
    PL.cb = null;
    $id('modal-poll').classList.remove('active');
    if (!document.querySelector('.modal-overlay.active')) document.body.style.overflow = '';
}

/* ── Highlight editor: edits are collected first, then saved together ── */
const hlPNew = () => ({ title:null, mode:null, cover:undefined, coverUrl:null, song:undefined, media:{}, del:[] });
const hlPm = id => (hlP.media[id] = hlP.media[id] || {});
const hlFind = id => hlCur.media.find(m => m.id === id);
const hlEffMode = () => (hlP.mode !== null ? hlP.mode : hlCur.mode);
const hlPollToClient = sp => ({ q:sp.q, multi:sp.multi, results:sp.results, duration:-1, options:sp.options.map(o => o.label) });
function hlEffMedia(m){
    const p = hlP.media[m.id] || {};
    let audio = null;
    if (p.song === undefined) { if (m.audio) audio = { title:m.audio_title, pending:false }; }
    else if (p.song) audio = { title:p.song.title, pending:true };
    return { id:m.id, path:m.path, type:m.type, added:m.added,
        text:p.text !== undefined ? p.text : (m.caption || ''),
        mute:p.mute !== undefined ? p.mute : m.mute, audio:audio,
        poll:m.type === 'poll' ? (p.poll !== undefined ? p.poll : hlPollToClient(m.poll)) : null,
        votes:(m.poll ? m.poll.total : 0) };
}
function hlHasPending(){
    if (!hlCur || !hlP) return false;
    if (hlP.title !== null && hlP.title.trim() !== hlCur.title) return true;
    if (hlP.mode !== null && hlP.mode !== hlCur.mode) return true;
    if (hlP.cover !== undefined || hlP.song !== undefined || hlP.del.length || hlStage.length) return true;
    return Object.keys(hlP.media).some(k => Object.keys(hlP.media[k]).length > 0);
}
function hlPendUI(){
    const b = $id('hle-save'), n = $id('hle-note'); if (!b || !n) return;
    const d = hlHasPending();
    b.disabled = !d || hlSaving;
    if (!hlSaving) { n.textContent = d ? 'You have changes that are not saved yet.' : 'No new changes.'; n.className = 'hle-note' + (d ? ' warn' : ''); }
}
function hlDiscard(){
    hlFree(hlStage); hlStage = [];
    if (hlP && hlP.coverUrl) URL.revokeObjectURL(hlP.coverUrl);
    hlP = null;
}

async function hlEdit(id){
    const m = $id('modal-hl-edit'), root = $id('hle-root');
    hlDirty = false; hlDiscard(); hlP = hlPNew(); hlCur = null;
    root.innerHTML = '<div class="empty-state"><div class="empty-title">Loading…</div></div>';
    hlPendUI();
    m.classList.add('active'); document.body.style.overflow = 'hidden'; m.scrollTop = 0;
    try { hlCur = (await hlCall('get', { highlight_id:id })).h; hlRenderEdit(); }
    catch (e) { showToast(e.message); hlDiscard(); closeModal('modal-hl-edit'); }
}
async function hlRefresh(){ hlCur = (await hlCall('get', { highlight_id:hlCur.id })).h; hlRenderEdit(); }

function hlThumb(path, type, text, q){
    if (type === 'poll') return '<div class="hl-pollTile"><i class="ri-bar-chart-horizontal-fill"></i><span>' + esc(hlShort(q || 'Poll', 80)) + '</span></div>';
    let h = type === 'video'
        ? '<video src="' + esc(path) + '#t=0.1" muted playsinline preload="metadata"></video><span class="hl-vid"><i class="ri-play-fill"></i></span>'
        : type === 'text' ? '<img src="' + esc(HL_TEXT_BG) + '" alt="">'
        : '<img src="' + esc(path) + '" alt="" loading="lazy">';
    if (text) h += '<span class="hl-tilecap">' + esc(hlShort(text.replace(/\s+/g, ' '), 90)) + '</span>';
    return h;
}
function hlRenderEdit(){
    const h = hlCur, mode = hlEffMode(), each = mode === 'each';
    const med = h.media.filter(m => hlP.del.indexOf(m.id) < 0).map(hlEffMedia);
    const first = med[0];
    const hasCover = (hlP.cover instanceof File) || (hlP.cover === undefined && !!h.cover);
    let coverHtml;
    if (hlP.cover instanceof File) coverHtml = '<img src="' + hlP.coverUrl + '" alt="">';
    else if (hlP.cover === undefined && h.cover) coverHtml = '<img src="' + esc(h.cover) + '" alt="">';
    else coverHtml = first ? hlThumb(first.path, first.type, '', first.poll ? first.poll.q : '') : '<img src="' + esc(HL_TEXT_BG) + '" alt="">';

    const tiles = med.map(m => {
        const isPoll = m.type === 'poll';
        return '<div class="hle-item" id="hlm-' + m.id + '">' +
            '<div class="hl-sq">' + hlThumb(m.path, m.type, m.text, m.poll ? m.poll.q : '') +
                '<button class="hl-del" type="button" aria-label="Remove media" onclick="hlMediaDel(' + m.id + ')"><i class="ri-close-line"></i></button></div>' +
            '<div class="hle-date">' + (isPoll ? m.votes + ' voter' + (m.votes === 1 ? '' : 's') + ' · ' : '') + 'Added ' + esc(m.added) + '</div>' +
            '<div class="hle-actions">' +
                (isPoll
                    ? '<button type="button" class="hle-chip on" onclick="hlMediaPoll(' + m.id + ')"><i class="ri-bar-chart-horizontal-line"></i>Edit poll</button>'
                    : '<button type="button" class="hle-chip' + (m.text ? ' on' : '') + '" onclick="hlMediaText(' + m.id + ')"><i class="ri-text"></i>' + (m.text ? 'Edit text' : 'Add text') + '</button>') +
                (each ? '<button type="button" class="hle-chip' + (m.audio ? ' on' : '') + '" onclick="hlMediaSong(' + m.id + ')"><i class="ri-music-2-line"></i>' + (m.audio ? esc(hlShort(m.audio.title || 'Song', 9)) : 'Add song') + '</button>' +
                    (m.audio ? '<button type="button" class="hle-chip x" aria-label="Remove song" onclick="hlMediaSongRm(' + m.id + ')"><i class="ri-close-line"></i></button>' : '') : '') +
                (m.type === 'video' ? '<button type="button" class="hle-chip' + (m.mute ? ' on' : '') + '" onclick="hlMediaMute(' + m.id + ')"><i class="' + (m.mute ? 'ri-volume-mute-line' : 'ri-volume-up-line') + '"></i>' + (m.mute ? 'Muted' : 'Sound') + '</button>' : '') +
            '</div></div>';
    }).join('');

    const ws = hlP.song === undefined ? (h.song ? { title:h.song_title, path:h.song } : null) : (hlP.song ? { title:hlP.song.title, pending:true } : null);
    let music;
    if (!each) {
        music = ws
            ? '<div class="hle-song"><i class="ri-music-2-fill"></i><div class="hle-info"><strong>' + esc(ws.title || 'Song clip') + '</strong><span>' + (ws.pending ? 'New clip. It is saved when you tap Save new changes.' : 'Plays across every media in this highlight.') + '</span></div></div>' +
              (ws.path ? '<audio controls preload="none" src="' + esc(ws.path) + '" style="width:100%;height:32px;margin-top:8px"></audio>' : '') +
              '<div class="hle-btns"><button class="hle-btn" onclick="hlSongSet()">Change song</button><button class="hle-btn danger" onclick="hlSongRm()">Remove song</button></div>'
            : '<p class="field-help">No song yet. Add one clip and it plays across every media in this highlight.</p>' +
              '<div class="hle-btns"><button class="hle-btn accent" onclick="hlSongSet()">Add a song clip</button></div>';
    } else {
        music = '<p class="field-help">Each media has its own song. Tap Add song under a media below to give it one, or the × to remove it. A media with no song stays silent (a video keeps its own sound unless you mute it).</p>' +
            (ws ? '<p class="field-help" style="margin-top:6px">Your one-song clip "' + esc(ws.title || 'Song clip') + '" is kept and comes back if you switch to One song for all.</p>' : '');
    }

    const titleVal = hlP.title !== null ? hlP.title : h.title;
    const count = med.length + hlStage.length;
    $id('hle-root').innerHTML =
        '<div class="field-row"><div class="m-label"><i class="ri-text"></i> Name</div>' +
            '<div class="field-control"><input type="text" class="f-input" id="hle-title" maxlength="30" value="' + esc(titleVal) + '" oninput="hlP.title=this.value;hlPendUI()"></div></div>' +

        '<div class="hle-sec"><div class="hle-h">Cover</div><div class="hle-cover"><div class="hl-sq">' + coverHtml + '</div>' +
            '<div class="hle-info"><strong>' + (hasCover ? 'Custom cover' : 'Using your first media') + '</strong><span>' + (hasCover ? 'Not part of the media in this highlight.' : 'Upload an image to use a different cover.') + '</span>' +
            '<div class="hle-btns"><span class="hle-btn accent">' + (hasCover ? 'Change cover' : 'Upload cover') + '<input type="file" accept="image/*" onchange="hlCoverPicked(this)"></span>' +
            (hasCover ? '<button class="hle-btn danger" onclick="hlCoverRm()">Remove</button>' : '') + '</div></div></div></div>' +

        '<div class="hle-sec"><div class="hle-h">Music</div>' +
            '<div class="hl-seg"><button type="button" class="' + (each ? '' : 'on') + '" onclick="hlModeSet(\'whole\')">One song for all</button><button type="button" class="' + (each ? 'on' : '') + '" onclick="hlModeSet(\'each\')">Song per media</button></div>' +
            '<div style="margin-top:12px">' + music + '</div></div>' +

        '<div class="hle-sec"><div class="hle-h">Media · ' + count + ' of ' + HL_MAX + '</div>' +
            '<div class="hle-grid">' + tiles + '</div>' +
            '<p class="field-help" style="margin-top:12px">Tap Add text to write words in the middle of a photo, video or GIF. Tap Edit poll to change a poll and see how many members have voted. For videos, Sound keeps the video\'s own audio and Muted silences it so only the song plays.</p>' +
            (count < HL_MAX
                ? '<div class="hle-btns"><span class="hle-btn accent">Add more media<input type="file" accept="image/*,video/*" multiple onchange="hlAddMore(this)"></span><button class="hle-btn" onclick="hlAddText(\'e\')">Add text highlight</button><button class="hle-btn poll" onclick="hlAddPoll(\'e\')">Add poll</button></div><div id="hle-stage" style="margin-top:10px"></div>'
                : '<p class="field-help" style="margin-top:8px">This highlight is full (' + HL_MAX + ' media).</p><div id="hle-stage" style="margin-top:10px"></div>') + '</div>' +

        '<div class="hle-sec hle-danger"><button class="hle-btn danger" onclick="hlDeleteAll()">Delete this highlight</button>' +
            '<p class="field-help" style="margin-top:8px">This permanently deletes the highlight, its media, polls and votes, cover and songs right away.</p></div>';
    hlRenderStageBox();
}

function hlModeSet(mode){ hlP.mode = (mode === hlCur.mode) ? null : mode; hlRenderEdit(); }
function hlCoverPicked(input){
    const f = input.files && input.files[0]; input.value = '';
    if (!f) return;
    if (!f.type.startsWith('image/') || f.size > HL_IMG_MB * 1048576) { showToast('The cover must be an image under ' + HL_IMG_MB + ' MB.'); return; }
    if (hlP.coverUrl) URL.revokeObjectURL(hlP.coverUrl);
    hlP.cover = f; hlP.coverUrl = URL.createObjectURL(f);
    hlRenderEdit();
}
function hlCoverRm(){
    if (hlP.coverUrl) { URL.revokeObjectURL(hlP.coverUrl); hlP.coverUrl = null; }
    hlP.cover = hlCur.cover ? null : undefined;
    hlRenderEdit();
}
function hlSongSet(){ cpOpen(res => { hlP.song = res; hlRenderEdit(); }); }
function hlSongRm(){ hlP.song = hlCur.song ? null : undefined; hlRenderEdit(); }
function hlMediaSong(mid){ cpOpen(res => { hlPm(mid).song = res; hlRenderEdit(); }); }
function hlMediaSongRm(mid){
    const m = hlFind(mid), pm = hlPm(mid);
    if (m && m.audio) pm.song = null; else delete pm.song;
    hlRenderEdit();
}
function hlMediaMute(mid){
    const m = hlFind(mid); if (!m) return;
    const pm = hlPm(mid), cur = pm.mute !== undefined ? pm.mute : m.mute, next = cur ? 0 : 1;
    if (next === m.mute) delete pm.mute; else pm.mute = next;
    hlRenderEdit();
}
function hlMediaText(mid){
    const m = hlFind(mid); if (!m) return;
    const e = hlEffMedia(m);
    htOpen({ src:m.path, type:m.type, text:e.text, required:m.type === 'text', allowRemove:m.type !== 'text' && !!e.text }, t => {
        const pm = hlPm(mid);
        if (t === (m.caption || '')) delete pm.text; else pm.text = t;
        hlRenderEdit();
    });
}
function hlMediaPoll(mid){
    const m = hlFind(mid); if (!m || !m.poll) return;
    const e = hlEffMedia(m), sp = m.poll;
    plOpen({ poll:e.poll, existing:true, locked:sp.total > 0, total:sp.total, counts:sp.options.map(o => o.votes), closes:sp.closes, closed:sp.closed }, p => {
        hlPm(mid).poll = p;
        hlRenderEdit();
    });
}
function hlMediaDel(mid){
    const left = hlCur.media.length - hlP.del.length + hlStage.length;
    if (left <= 1) { showToast('A highlight needs at least one media. Delete the highlight instead.'); return; }
    const m = hlFind(mid);
    if (m && m.poll && m.poll.total > 0 && !confirm('This poll has ' + m.poll.total + ' voter' + (m.poll.total === 1 ? '' : 's') + '. Removing it deletes their votes when you save. Remove it?')) return;
    if (hlP.del.indexOf(mid) < 0) hlP.del.push(mid);
    delete hlP.media[mid];
    hlRenderEdit();
}
function hlAddMore(input){
    const room = HL_MAX - (hlCur.media.length - hlP.del.length) - hlStage.length;
    const r = hlFilterFiles(Array.from(input.files || []), Math.max(0, room)); input.value = '';
    if (!r.ok.length) { showToast(room <= 0 ? 'This highlight is full.' : 'No valid files selected'); return; }
    hlStage = hlStage.concat(hlMake(r.ok));
    hlRenderEdit();
    if (r.trimmed || r.skipped) showToast((r.trimmed ? 'Extra files skipped (100 max). ' : '') + (r.skipped ? r.skipped + ' skipped (wrong type or too large).' : ''));
}
function hlRenderStageBox(){
    const box = $id('hle-stage');
    if (box) {
        if (!hlStage.length) box.innerHTML = '';
        else box.innerHTML =
            '<div class="hl-rows-head">' + hlStage.length + ' waiting to be added.' + (hlEffMode() === 'each' ? ' Tap Add song on any that should have its own song.' : '') + ' They upload when you tap Save new changes.</div>' +
            '<div class="hl-rows">' + hlRows('e', hlEffMode()) + '</div>' +
            '<div class="hle-btns"><button class="hle-btn" onclick="hlStageClear()">Clear these</button></div>';
    }
    hlPendUI();
}
function hlStageClear(){ hlFree(hlStage); hlStage = []; hlRenderEdit(); }

async function hlSaveAll(){
    if (hlSaving || !hlCur || !hlP || !hlHasPending()) return;
    const title = (hlP.title !== null ? hlP.title : hlCur.title).trim();
    if (!title) { showToast('Give your highlight a name.'); return; }
    for (const m of hlCur.media) {
        if (hlP.del.indexOf(m.id) >= 0) continue;
        if (m.type === 'text' && !hlEffMedia(m).text.trim()) { showToast('A text media needs some text. Write something or remove it.'); return; }
    }
    const P = hlP, S = hlStage.slice(), id = hlCur.id, mode = hlEffMode(), errs = [];
    const remaining = hlCur.media.length - P.del.length + S.length;
    if (remaining < 1) { showToast('A highlight needs at least one media. Delete the highlight instead.'); return; }

    hlSaving = true;
    const btn = $id('hle-save'), note = $id('hle-note');
    btn.disabled = true; btn.textContent = 'Saving…';

    const T = [], add = (label, fn) => T.push({ label, fn });
    if (P.title !== null && title !== hlCur.title) add('Saving name…', () => hlCall('rename', { highlight_id:id, title:title }));
    if (P.mode !== null && P.mode !== hlCur.mode) add('Saving music mode…', () => hlCall('set_mode', { highlight_id:id, mode:P.mode }));
    if (P.cover instanceof File) add('Uploading cover…', () => hlCall('set_cover', { highlight_id:id }, { cover:P.cover }));
    else if (P.cover === null) add('Removing cover…', () => hlCall('rm_cover', { highlight_id:id }));
    if (P.song) add('Uploading song clip…', () => hlCall('set_song', { highlight_id:id, title:P.song.title || '' }, { song:P.song.blob }));
    else if (P.song === null) add('Removing song…', () => hlCall('rm_song', { highlight_id:id }));

    const dels = P.del.slice();
    const delTasks = () => dels.forEach(mid => add('Removing media…', () => hlCall('del_media', { highlight_id:id, media_id:mid })));
    const delFirst = (hlCur.media.length + S.length > HL_MAX) && (hlCur.media.length - dels.length >= 1);
    if (delFirst) delTasks();

    S.forEach((it, i) => add('Adding ' + (i + 1) + ' of ' + S.length + '…', async () => {
        const r = await hlAddCall(id, it);
        if (mode === 'each' && it.song) { try { await hlCall('media_song', { highlight_id:id, media_id:r.id, title:it.song.title || '' }, { song:it.song.blob }); } catch (e) { errs.push('Song: ' + e.message); } }
        if (hlIsVid(it) && it.mute) { try { await hlCall('media_mute', { highlight_id:id, media_id:r.id, mute:1 }); } catch (e) {} }
    }));

    Object.keys(P.media).forEach(k => {
        const mid = +k, p = P.media[k];
        if (dels.indexOf(mid) >= 0) return;
        if (p.text !== undefined) add('Saving text…', () => hlCall('set_caption', { highlight_id:id, media_id:mid, text:p.text }));
        if (p.poll !== undefined) add('Saving poll…', () => hlCall('set_poll', { highlight_id:id, media_id:mid, poll:JSON.stringify(p.poll) }));
        if (p.mute !== undefined) add('Saving sound…', () => hlCall('media_mute', { highlight_id:id, media_id:mid, mute:p.mute }));
        if (p.song) add('Uploading song…', () => hlCall('media_song', { highlight_id:id, media_id:mid, title:p.song.title || '' }, { song:p.song.blob }));
        else if (p.song === null) add('Removing song…', () => hlCall('media_song_rm', { highlight_id:id, media_id:mid }));
    });
    if (!delFirst) delTasks();

    for (let i = 0; i < T.length; i++) {
        note.textContent = T[i].label + ' (' + (i + 1) + '/' + T.length + ')'; note.className = 'hle-note warn';
        try { await T[i].fn(); } catch (e) { errs.push(e.message); }
    }

    hlFree(hlStage); hlStage = [];
    if (P.coverUrl) URL.revokeObjectURL(P.coverUrl);
    hlP = hlPNew(); hlDirty = true; hlSaving = false;
    btn.textContent = 'Save new changes';
    try { await hlRefresh(); } catch (e) {}
    hlPendUI();
    showToast(errs.length ? 'Saved, but ' + errs.length + ' change' + (errs.length === 1 ? '' : 's') + ' failed: ' + errs[0] : 'All changes saved');
}

async function hlDeleteAll(){
    if (!confirm('Delete this highlight and everything in it, including any polls and their votes?')) return;
    try { await hlCall('delete', { highlight_id:hlCur.id }); hlDirty = true; hlDiscard(); showToast('Highlight deleted'); closeModal('modal-hl-edit'); }
    catch (e) { showToast(e.message); }
}

/* ══════════ SONG CLIP PICKER ══════════ */
const CP = { file:null, url:null, dur:0, cb:null, audio:null, timer:null };
const cpEl = id => document.getElementById(id);
const cpFmt = t => { t = Math.max(0, t); return Math.floor(t / 60) + ':' + String(Math.floor(t % 60)).padStart(2, '0'); };
const cpLen = () => Math.min(+cpEl('cp-len').value, CP.dur || 0);

function cpOpen(cb){
    CP.cb = cb; cpReset();
    const m = cpEl('modal-clip'); m.classList.add('active'); document.body.style.overflow = 'hidden'; m.scrollTop = 0;
}
function cpReset(){
    cpStop();
    if (CP.url) URL.revokeObjectURL(CP.url);
    CP.file = null; CP.url = null; CP.dur = 0; CP.audio = null;
    cpEl('cp-editor').style.display = 'none'; cpEl('cp-file-title').textContent = 'Choose a song';
    cpEl('cp-title').value = ''; cpEl('cp-status').textContent = ''; cpEl('cp-status').className = 'hl-status';
    cpEl('cp-use').disabled = false; cpEl('cp-use').textContent = 'Use this clip';
}
function cpClose(){
    cpReset(); CP.cb = null;
    cpEl('modal-clip').classList.remove('active');
    if (!document.querySelector('.modal-overlay.active')) document.body.style.overflow = '';
}
function cpStatus(msg, err){ const s = cpEl('cp-status'); s.textContent = msg || ''; s.className = 'hl-status' + (err ? ' error' : ''); }

cpEl('cp-file').addEventListener('change', function(){
    const f = this.files && this.files[0]; this.value = '';
    if (!f) return;
    if (!f.type.startsWith('audio/') && !/\.(mp3|m4a|aac|wav|ogg|flac|opus)$/i.test(f.name)) { cpStatus('Please choose an audio file.', true); return; }
    if (f.size > 40 * 1048576) { cpStatus('That file is too large (40 MB max).', true); return; }
    cpStop(); if (CP.url) URL.revokeObjectURL(CP.url);
    CP.file = f; CP.url = URL.createObjectURL(f);
    const a = new Audio(); a.preload = 'metadata'; a.src = CP.url; CP.audio = a;
    cpStatus('Loading song…');
    a.addEventListener('loadedmetadata', function(){
        if (!isFinite(a.duration) || a.duration < 1) { cpStatus('Could not read that song.', true); return; }
        CP.dur = a.duration;
        cpEl('cp-file-title').textContent = hlShort(f.name, 34);
        cpEl('cp-title').value = f.name.replace(/\.[^.]+$/, '').slice(0, 60);
        cpEl('cp-start').value = 0;
        cpEl('cp-editor').style.display = 'block'; cpStatus('');
        cpUpdate();
    }, { once:true });
    a.addEventListener('error', () => cpStatus('This browser cannot play that file. Try MP3, M4A or WAV.', true), { once:true });
});

function cpUpdate(){
    if (!CP.dur) return;
    const len = cpLen(), sl = cpEl('cp-start');
    sl.max = Math.max(0, CP.dur - len);
    if (+sl.value > +sl.max) sl.value = sl.max;
    const st = +sl.value;
    cpEl('cp-start-lbl').textContent = cpFmt(st);
    cpEl('cp-end-lbl').textContent = cpFmt(st + len);
    if (CP.audio && !CP.audio.paused) cpStop();
}
function cpStop(){
    clearInterval(CP.timer);
    if (CP.audio) CP.audio.pause();
    const t = cpEl('cp-prev-txt'); if (t) t.textContent = 'Preview clip';
}
function cpPreview(){
    const a = CP.audio; if (!a || !CP.dur) return;
    if (!a.paused) { cpStop(); return; }
    const st = +cpEl('cp-start').value, len = cpLen();
    a.currentTime = st; a.play().catch(() => cpStatus('Could not play the preview.', true));
    cpEl('cp-prev-txt').textContent = 'Stop preview';
    clearInterval(CP.timer);
    CP.timer = setInterval(() => { if (a.currentTime >= st + len || a.ended) cpStop(); }, 100);
}
async function cpBuildClip(file, start, len){
    const AC = window.AudioContext || window.webkitAudioContext;
    const ctx = new AC();
    let buf;
    try { buf = await ctx.decodeAudioData(await file.arrayBuffer()); }
    finally { if (ctx.close) ctx.close(); }
    const sr = buf.sampleRate, ch = Math.min(2, buf.numberOfChannels);
    const from = Math.floor(start * sr), to = Math.min(buf.length, Math.floor((start + len) * sr)), frames = to - from;
    if (frames < sr) throw new Error('That clip is too short.');
    const RATE = 32000;
    const off = new OfflineAudioContext(ch, Math.max(1, Math.ceil(frames * RATE / sr)), RATE);
    const slice = off.createBuffer(ch, frames, sr);
    for (let c = 0; c < ch; c++) slice.copyToChannel(buf.getChannelData(c).subarray(from, to), c);
    const src = off.createBufferSource(); src.buffer = slice; src.connect(off.destination); src.start();
    return cpWav(await off.startRendering());
}
function cpWav(b){
    const ch = b.numberOfChannels, n = b.length, sr = b.sampleRate, bytes = n * ch * 2;
    const ab = new ArrayBuffer(44 + bytes), v = new DataView(ab);
    const w = (o, t) => { for (let i = 0; i < t.length; i++) v.setUint8(o + i, t.charCodeAt(i)); };
    w(0, 'RIFF'); v.setUint32(4, 36 + bytes, true); w(8, 'WAVE'); w(12, 'fmt ');
    v.setUint32(16, 16, true); v.setUint16(20, 1, true); v.setUint16(22, ch, true);
    v.setUint32(24, sr, true); v.setUint32(28, sr * ch * 2, true); v.setUint16(32, ch * 2, true); v.setUint16(34, 16, true);
    w(36, 'data'); v.setUint32(40, bytes, true);
    const fade = Math.min(Math.floor(sr * 0.15), Math.floor(n / 2)), d = [];
    for (let c = 0; c < ch; c++) d.push(b.getChannelData(c));
    let o = 44;
    for (let i = 0; i < n; i++) {
        const g = i < fade ? i / fade : (i > n - fade ? (n - i) / fade : 1);
        for (let c = 0; c < ch; c++) { const x = Math.max(-1, Math.min(1, d[c][i] * g)); v.setInt16(o, x < 0 ? x * 0x8000 : x * 0x7fff, true); o += 2; }
    }
    return new Blob([ab], { type:'audio/wav' });
}
async function cpUse(btn){
    if (!CP.file || !CP.dur) return;
    const len = cpLen(), start = +cpEl('cp-start').value, title = cpEl('cp-title').value.trim();
    cpStop(); btn.disabled = true; btn.textContent = 'Preparing clip…'; cpStatus('');
    try {
        const blob = await cpBuildClip(CP.file, start, len);
        if (blob.size > 6.5 * 1048576) throw new Error('That clip is too big. Try a shorter length.');
        const cb = CP.cb;
        await cb({ blob:blob, title:title, secs:len });
        cpClose();
    } catch (e) {
        btn.disabled = false; btn.textContent = 'Use this clip';
        cpStatus(e && e.message ? e.message : 'Could not prepare that clip.', true);
    }
}

/* ── Accessibility ── */
(function(){
    document.querySelectorAll('.list-row[onclick]').forEach(function(row){
        row.setAttribute('role', 'button'); row.tabIndex = 0;
        row.addEventListener('keydown', function(e){ if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); row.click(); } });
    });
    document.querySelectorAll('.modal-overlay').forEach(function(m){
        m.setAttribute('role', 'dialog'); m.setAttribute('aria-modal', 'true');
        var t = m.querySelector('.ac-title');
        if (t) { t.id = t.id || (m.id + '-title'); m.setAttribute('aria-labelledby', t.id); }
    });
})();

function actFilter(btn, f){
    document.querySelectorAll('#actFilters .act-f').forEach(function(b){ b.classList.toggle('active', b === btn); });
    var list = document.getElementById('actList'); if(!list) return;
    var shown = 0, lastBucket = null;
    list.querySelectorAll('.act-entry').forEach(function(e){ var ok = (f === 'all' || e.dataset.cat === f); e.style.display = ok ? '' : 'none'; if(ok) shown++; });
    /* hide a day heading when none of its entries are showing */
    var heads = list.querySelectorAll('.act-cat-title');
    heads.forEach(function(h){
        var n = h.nextElementSibling, any = false;
        while(n && !n.classList.contains('act-cat-title')){ if(n.classList.contains('act-entry') && n.style.display !== 'none') any = true; n = n.nextElementSibling; }
        h.style.display = any ? '' : 'none';
    });
    var none = document.getElementById('actNone'); if(none) none.hidden = shown > 0;
}
</script>
<script>
/* Banner / group picture: picking a file shows it straight away in the preview and saves it (no second tap on Save) */
(function(){
  document.querySelectorAll('.file-label input[type=file][name=banner_pic], .file-label input[type=file][name=group_pic]').forEach(function(inp){
    inp.addEventListener('change', function(){
      var f = inp.files && inp.files[0]; if(!f) return;
      var form = inp.closest('form'), lab = inp.closest('.file-label'), sp = lab && lab.querySelector('span');
      if(f.type && f.type.indexOf('image/') !== 0){ if(sp) sp.textContent = 'Choose an image'; inp.value = ''; return; }
      if(f.size > 10*1024*1024){ if(sp) sp.textContent = 'Image too big (max 10 MB)'; inp.value = ''; return; }
      var box = form && form.previousElementSibling;
      if(box && box.classList.contains('preview-box')){
        var img = box.querySelector('img'), empty = box.querySelector('.preview-empty');
        if(empty) empty.remove();
        if(!img){ img = document.createElement('img'); box.appendChild(img); }
        img.style.display = ''; img.src = URL.createObjectURL(f);
      }
      if(sp) sp.textContent = 'Saving…';
      var btn = form.querySelector('button[type=submit]');
      try{ form.requestSubmit ? form.requestSubmit(btn) : form.submit(); }catch(e){ form.submit(); }
      if(btn) setTimeout(function(){ btn.disabled = true; }, 0);
    });
  });
})();
</script>

</body>
</html>