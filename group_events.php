<?php
session_start();
include 'config.php';

/* ══════════════════════════════════════════════════════════════════
   TIMEZONE + EVENT-ENDED SETTINGS
   ══════════════════════════════════════════════════════════════════ */
date_default_timezone_set('Africa/Lagos');

/* Make MySQL agree with PHP (Lagos = UTC+1, no daylight saving) so that
   TIMESTAMP columns such as comment times display correctly. */
$conn->query("SET time_zone = '+01:00'");

/* How long after the start time an event still counts as "happening".
   0 = moves to Past the moment its start time passes.
   e.g. 3 * 3600 keeps it in Current for 3 hours after it starts. */
if (!defined('GE_EVENT_GRACE_SECONDS')) {
    define('GE_EVENT_GRACE_SECONDS', 0);
}

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
   AUTO-CREATE TABLES (so this page never errors on a fresh install)
   ══════════════════════════════════════════════════════════════════ */
$conn->query("
    CREATE TABLE IF NOT EXISTS group_events (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        group_id INT UNSIGNED NOT NULL,
        created_by INT UNSIGNED NOT NULL,
        event_name VARCHAR(150) NOT NULL,
        event_image VARCHAR(255) DEFAULT NULL,
        event_date DATE NOT NULL,
        event_time TIME NOT NULL,
        location_type ENUM('physical','virtual') NOT NULL DEFAULT 'physical',
        summary TEXT,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_group (group_id),
        INDEX idx_date (event_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$conn->query("
    CREATE TABLE IF NOT EXISTS group_event_rsvps (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        event_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        rsvp_type ENUM('interested','going') NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_event_user_type (event_id, user_id, rsvp_type),
        INDEX idx_event (event_id),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* ── discussion comments + comment likes ── */
$conn->query("
    CREATE TABLE IF NOT EXISTS group_event_comments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        event_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        comment TEXT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_event (event_id),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$conn->query("
    CREATE TABLE IF NOT EXISTS group_event_comment_likes (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        comment_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_comment_user (comment_id, user_id),
        INDEX idx_comment (comment_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* ── guarded ALTER so re-running this file never errors ── */
$col_check = $conn->query("
    SELECT COUNT(*) AS c FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'group_events' AND COLUMN_NAME = 'location_text'
");
$col_row = $col_check ? $col_check->fetch_assoc() : ['c' => 0];
if ((int)($col_row['c'] ?? 0) === 0) {
    $conn->query("
        ALTER TABLE group_events
        ADD COLUMN location_text VARCHAR(255) DEFAULT NULL AFTER location_type,
        ADD COLUMN visibility ENUM('public','private') NOT NULL DEFAULT 'private' AFTER location_text
    ");
}

/* ── guarded ALTER — let discussion comments carry attached images.
     Stored as a JSON array of relative "uploads/xxx.ext" paths so a
     single comment can hold multiple images. ── */
$col_check3 = $conn->query("
    SELECT COUNT(*) AS c FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'group_event_comments' AND COLUMN_NAME = 'comment_media'
");
$col_row3 = $col_check3 ? $col_check3->fetch_assoc() : ['c' => 0];
if ((int)($col_row3['c'] ?? 0) === 0) {
    $conn->query("
        ALTER TABLE group_event_comments
        ADD COLUMN comment_media TEXT DEFAULT NULL AFTER comment
    ");
}

/* ── Fetch group ── */
$stmt = $conn->prepare("SELECT g.*, u.username AS creator_name FROM groups g JOIN users u ON g.created_by=u.id WHERE g.id=?");
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

$is_member = $is_owner || $is_admin;
if (!$is_member) {
    $stmt = $conn->prepare("SELECT 1 FROM group_members WHERE group_id=? AND user_id=?");
    $stmt->bind_param("ii", $group_id, $current_user);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) $is_member = true;
    $stmt->close();
}
if (!$is_member) { header("Location: group.php?id=$group_id"); exit(); }

function ge_confirm_js($str) { return htmlspecialchars(json_encode($str), ENT_QUOTES, 'UTF-8'); }

/* Whether the current context is allowed to comment/like on a given event:
   public events -> anyone reaching this page; private events -> group members only. */
function ge_can_interact($visibility, $is_member) {
    return ($visibility === 'public') || $is_member;
}

/* Has this event's start time (+ grace period) already passed?
   Compares full date AND time, not just the date. */
function ge_event_has_ended($event_date, $event_time) {
    $start = strtotime($event_date . ' ' . $event_time);
    if ($start === false) return false;
    return ($start + GE_EVENT_GRACE_SECONDS) <= time();
}

/* Detect fetch()-based AJAX requests so handlers can respond with
   JSON instead of redirecting, letting the page update without a reload. */
function ge_is_ajax() {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}
function ge_json($data) {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

/* Decode a comment's stored media JSON into a plain array of paths. */
function ge_decode_media($raw) {
    if (empty($raw)) return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/* ══════════════════════════════════════════════════════════════════
   LOCATION LINK HELPERS
   ══════════════════════════════════════════════════════════════════ */
function ge_looks_like_url($str) {
    $str = trim((string)$str);
    if ($str === '') return false;
    if (preg_match('~^https?://~i', $str)) return true;
    if (preg_match('~^(www\.)?[a-z0-9\-]+(\.[a-z0-9\-]{2,})+(/\S*)?$~i', $str)) return true;
    return false;
}
function ge_normalize_url($str) {
    $str = trim((string)$str);
    if (preg_match('~^https?://~i', $str)) return $str;
    return 'https://' . $str;
}
function ge_map_url($location_text) {
    return 'https://www.google.com/maps/search/?api=1&query=' . urlencode($location_text);
}

/* ══════════════════════════════════════════════════════════════════
   GEOMETRIC COVER ART
   Events that have no picture (or whose picture fails to load) get a
   generated cover: a deep-navy background with glowing bright-blue
   geometry. Seeded by the event id, so every event gets its OWN
   unique-looking cover, the same on every page load. Pure inline SVG.
   (The covers stay dark navy in light mode too — they are artwork.)
   ══════════════════════════════════════════════════════════════════ */
function ge_geo_art($seed, $w, $h, $uid) {
    $seed  = (int)$seed;
    $state = (($seed * 7919) + 104729) & 0x7fffffff;

    $rnd = function () use (&$state) {
        $state = (($state * 1103515245) + 12345) & 0x7fffffff;
        return (($state >> 8) & 0xffff) / 65535;
    };
    for ($i = 0; $i < 4; $i++) { $rnd(); }

    $rng  = function ($a, $b) use ($rnd) { return $a + ($b - $a) * $rnd(); };
    $pick = function (array $arr) use ($rnd) { return $arr[(int)floor($rnd() * count($arr)) % count($arr)]; };
    $f    = function ($v) { return number_format((float)$v, 1, '.', ''); };
    $o    = function ($v) { return number_format((float)$v, 2, '.', ''); };

    $w = (float)$w; $h = (float)$h;
    $m = min($w, $h);
    $u = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$uid);

    $palettes = [
        ['bg1' => '#010218', 'bg2' => '#0b1a5c', 'a1' => '#1a56c4', 'a2' => '#4f8dff', 'hi' => '#9dbcff'],
        ['bg1' => '#020420', 'bg2' => '#0a2470', 'a1' => '#1a4cc4', 'a2' => '#3b9bff', 'hi' => '#a5d0ff'],
        ['bg1' => '#01041a', 'bg2' => '#12206b', 'a1' => '#13449d', 'a2' => '#5b7dff', 'hi' => '#b3c4ff'],
        ['bg1' => '#010214', 'bg2' => '#0a2a6e', 'a1' => '#1d63e0', 'a2' => '#38b6ff', 'hi' => '#a8e0ff'],
    ];
    $p = $pick($palettes);
    $dir = $pick([[0, 0, 1, 1], [1, 0, 0, 1], [0, 1, 1, 0], [0, 0, 0, 1]]);

    $sw = max(1.2, $m * 0.012);
    $ps = max(9, $m * 0.07);

    $defs  = '<linearGradient id="bg-' . $u . '" x1="' . $dir[0] . '" y1="' . $dir[1] . '" x2="' . $dir[2] . '" y2="' . $dir[3] . '">'
           . '<stop offset="0" stop-color="' . $p['bg1'] . '"/><stop offset="1" stop-color="' . $p['bg2'] . '"/></linearGradient>';
    $defs .= '<radialGradient id="gl-' . $u . '"><stop offset="0" stop-color="' . $p['a2'] . '" stop-opacity=".62"/><stop offset="1" stop-color="' . $p['a2'] . '" stop-opacity="0"/></radialGradient>';
    $defs .= '<radialGradient id="gm-' . $u . '"><stop offset="0" stop-color="' . $p['a1'] . '" stop-opacity=".55"/><stop offset="1" stop-color="' . $p['a1'] . '" stop-opacity="0"/></radialGradient>';
    $defs .= '<linearGradient id="sa-' . $u . '" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="' . $p['a2'] . '"/><stop offset="1" stop-color="' . $p['a1'] . '"/></linearGradient>';
    $defs .= '<linearGradient id="sb-' . $u . '" x1="1" y1="0" x2="0" y2="1"><stop offset="0" stop-color="' . $p['hi'] . '"/><stop offset="1" stop-color="' . $p['a2'] . '"/></linearGradient>';
    $defs .= '<pattern id="dt-' . $u . '" width="' . $f($ps) . '" height="' . $f($ps) . '" patternUnits="userSpaceOnUse">'
           . '<circle cx="' . $f($ps / 2) . '" cy="' . $f($ps / 2) . '" r="' . $f(max(0.9, $ps * 0.09)) . '" fill="' . $p['hi'] . '" fill-opacity=".55"/></pattern>';

    $back  = '<rect width="' . $f($w) . '" height="' . $f($h) . '" fill="url(#bg-' . $u . ')"/>';
    $gx = $rng(0.15, 0.85) * $w;  $gy = $rng(0.15, 0.85) * $h;  $gr = $m * $rng(0.75, 1.05);
    $back .= '<circle cx="' . $f($gx) . '" cy="' . $f($gy) . '" r="' . $f($gr) . '" fill="url(#gl-' . $u . ')"/>';
    $back .= '<circle cx="' . $f($w - $gx) . '" cy="' . $f($h - $gy) . '" r="' . $f($m * 0.62) . '" fill="url(#gm-' . $u . ')"/>';

    $pw = $w * 0.55; $ph = $h * 0.62;
    $px = ($rnd() > 0.5) ? 0 : ($w - $pw);
    $py = ($rnd() > 0.5) ? 0 : ($h - $ph);
    $back .= '<rect x="' . $f($px) . '" y="' . $f($py) . '" width="' . $f($pw) . '" height="' . $f($ph) . '" fill="url(#dt-' . $u . ')" opacity=".5"/>';

    $poly = function ($cx, $cy, $r, $n, $rot) use ($f) {
        $pts = [];
        for ($i = 0; $i < $n; $i++) {
            $a = deg2rad($rot + (360 / $n) * $i - 90);
            $pts[] = $f($cx + $r * cos($a)) . ',' . $f($cy + $r * sin($a));
        }
        return implode(' ', $pts);
    };
    $shape = function ($type, $cx, $cy, $r, $rot, $attrs) use ($poly, $f) {
        switch ($type) {
            case 'hex':
                return '<polygon points="' . $poly($cx, $cy, $r, 6, $rot) . '" ' . $attrs . '/>';
            case 'tri':
                return '<polygon points="' . $poly($cx, $cy, $r, 3, $rot) . '" ' . $attrs . '/>';
            case 'square':
                return '<polygon points="' . $poly($cx, $cy, $r, 4, $rot + 45) . '" ' . $attrs . '/>';
            case 'ring':
                return '<circle cx="' . $f($cx) . '" cy="' . $f($cy) . '" r="' . $f($r) . '" ' . $attrs . '/>';
            case 'diamond':
                $pts = $f($cx) . ',' . $f($cy - $r) . ' ' . $f($cx + $r * 0.6) . ',' . $f($cy) . ' '
                     . $f($cx) . ',' . $f($cy + $r) . ' ' . $f($cx - $r * 0.6) . ',' . $f($cy);
                return '<polygon points="' . $pts . '" transform="rotate(' . $f($rot) . ' ' . $f($cx) . ' ' . $f($cy) . ')" ' . $attrs . '/>';
            case 'half':
                return '<path d="M' . $f($cx - $r) . ' ' . $f($cy) . ' A' . $f($r) . ' ' . $f($r) . ' 0 0 1 ' . $f($cx + $r) . ' ' . $f($cy) . ' Z" transform="rotate(' . $f($rot) . ' ' . $f($cx) . ' ' . $f($cy) . ')" ' . $attrs . '/>';
        }
        return '';
    };

    $types = ['hex', 'tri', 'diamond', 'square', 'ring', 'half'];
    $count = (int)max(4, min(7, round(($w * $h) / 17000)));
    $big = '';
    for ($i = 0; $i < $count; $i++) {
        $type  = $pick($types);
        $style = $pick(['fill', 'fill', 'outline', 'duo']);
        $cx  = $rng(-0.02, 1.02) * $w;
        $cy  = $rng(-0.02, 1.02) * $h;
        $r   = $m * $rng(0.14, 0.36);
        $rot = $rng(0, 360);
        if ($i === 0) { $style = 'fill'; $r = $m * $rng(0.26, 0.40); }
        $grad = ($i % 2 === 0) ? 'sa' : 'sb';
        $fillAttrs   = 'fill="url(#' . $grad . '-' . $u . ')" fill-opacity="' . $o($rng(0.35, 0.80)) . '"';
        $strokeAttrs = 'fill="none" stroke="' . $p['hi'] . '" stroke-width="' . $f($sw) . '" stroke-opacity="' . $o($rng(0.40, 0.85)) . '" stroke-linejoin="round"';

        if ($style === 'fill') {
            $big .= $shape($type, $cx, $cy, $r, $rot, $fillAttrs);
        } elseif ($style === 'outline') {
            $big .= $shape($type, $cx, $cy, $r, $rot, $strokeAttrs);
        } else {
            $big .= $shape($type, $cx, $cy, $r, $rot, $fillAttrs);
            $big .= $shape($type, $cx + $r * 0.18, $cy - $r * 0.18, $r * 1.3, $rot + 12, $strokeAttrs);
        }
    }

    $lines = '';
    for ($i = 0; $i < 3; $i++) {
        if ($i % 2 === 0) { $x1 = $rng(0, 1) * $w; $y1 = 0;              $x2 = $rng(0, 1) * $w; $y2 = $h; }
        else              { $x1 = 0;              $y1 = $rng(0, 1) * $h; $x2 = $w;              $y2 = $rng(0, 1) * $h; }
        $lines .= '<line x1="' . $f($x1) . '" y1="' . $f($y1) . '" x2="' . $f($x2) . '" y2="' . $f($y2) . '" stroke="' . $p['hi'] . '" stroke-opacity="' . $o($rng(0.16, 0.30)) . '" stroke-width="' . $f(max(0.8, $m * 0.005)) . '"/>';
    }

    $acc = '';
    $na  = $count + 4;
    for ($i = 0; $i < $na; $i++) {
        $k  = $pick(['dot', 'dot', 'plus', 'tri']);
        $x  = $rng(0.04, 0.96) * $w;
        $y  = $rng(0.06, 0.94) * $h;
        $s  = max(2.4, $m * $rng(0.022, 0.05));
        $op = $o($rng(0.55, 1.0));
        if ($k === 'dot') {
            $acc .= '<circle cx="' . $f($x) . '" cy="' . $f($y) . '" r="' . $f($s * 0.45) . '" fill="' . $p['hi'] . '" fill-opacity="' . $op . '"/>';
        } elseif ($k === 'plus') {
            $lw = $f(max(1.0, $m * 0.008));
            $acc .= '<g stroke="' . $p['hi'] . '" stroke-opacity="' . $op . '" stroke-width="' . $lw . '" stroke-linecap="round">'
                  . '<line x1="' . $f($x - $s) . '" y1="' . $f($y) . '" x2="' . $f($x + $s) . '" y2="' . $f($y) . '"/>'
                  . '<line x1="' . $f($x) . '" y1="' . $f($y - $s) . '" x2="' . $f($x) . '" y2="' . $f($y + $s) . '"/></g>';
        } else {
            $acc .= '<polygon points="' . $poly($x, $y, $s, 3, $rng(0, 120)) . '" fill="none" stroke="' . $p['hi'] . '" stroke-opacity="' . $op . '" stroke-width="' . $f(max(1.0, $m * 0.008)) . '" stroke-linejoin="round"/>';
        }
    }

    return '<svg class="geo-art-svg" viewBox="0 0 ' . $f($w) . ' ' . $f($h) . '" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">'
         . '<defs>' . $defs . '</defs>'
         . $back
         . '<g class="geo-drift">' . $big . '</g>'
         . '<g class="geo-drift-2">' . $lines . $acc . '</g>'
         . '</svg>';
}

function ge_cover_thumb($seed) {
    return '<div class="thumb-no-img">' . ge_geo_art($seed, 200, 200, 't' . (int)$seed) . '</div>';
}
function ge_cover_hero($seed) {
    return '<div class="no-img">' . ge_geo_art($seed, 520, 230, 'h' . (int)$seed) . '</div>';
}

/* ══════════════════════════════════════════════════════════════════
   POST HANDLING (self-contained, redirect after to avoid resubmit)
   ══════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $valid_csrf = isset($_POST['csrf_token']) && hash_equals($csrf_token, $_POST['csrf_token']);

    /* ── Create event (any member) ── */
    if ($valid_csrf && isset($_POST['create_event'])) {
        $event_name = trim($_POST['event_name'] ?? '');
        $event_date = trim($_POST['event_date'] ?? '');
        $event_time = trim($_POST['event_time'] ?? '');
        $location_type = ($_POST['location_type'] ?? '') === 'virtual' ? 'virtual' : 'physical';
        $location_text = trim($_POST['location_text'] ?? '');
        $visibility = ($_POST['visibility'] ?? '') === 'public' ? 'public' : 'private';
        $summary = trim($_POST['summary'] ?? '');

        $date_ok = (bool)DateTime::createFromFormat('Y-m-d', $event_date);
        $time_ok = (bool)DateTime::createFromFormat('H:i', $event_time);

        if ($event_name !== '' && $date_ok && $time_ok) {
            $event_image = null;
            if (!empty($_FILES['event_image']['name']) && $_FILES['event_image']['error'] === UPLOAD_ERR_OK) {
                $allowed_ext = ['jpg','jpeg','png','gif','webp'];
                $ext = strtolower(pathinfo($_FILES['event_image']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, $allowed_ext, true)) {
                    if (!is_dir('uploads')) { @mkdir('uploads', 0755, true); }
                    $fname = 'evt_' . uniqid() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    if (@move_uploaded_file($_FILES['event_image']['tmp_name'], 'uploads/' . $fname)) {
                        $event_image = $fname;
                    }
                }
            }
            $stmt = $conn->prepare("INSERT INTO group_events (group_id, created_by, event_name, event_image, event_date, event_time, location_type, location_text, visibility, summary) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param("iissssssss", $group_id, $current_user, $event_name, $event_image, $event_date, $event_time, $location_type, $location_text, $visibility, $summary);
            $stmt->execute();
            $stmt->close();
        }
        header("Location: group_events.php?id=$group_id");
        exit();
    }

    /* ── Delete event (creator OR admin OR owner only) ── */
    if ($valid_csrf && isset($_POST['delete_event'])) {
        $event_id = (int)($_POST['event_id'] ?? 0);
        if ($event_id) {
            $stmt = $conn->prepare("SELECT created_by, event_image FROM group_events WHERE id=? AND group_id=?");
            $stmt->bind_param("ii", $event_id, $group_id);
            $stmt->execute();
            $ev = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($ev && ((int)$ev['created_by'] === $current_user || $is_owner || $is_admin)) {
                $del = $conn->prepare("DELETE FROM group_events WHERE id=? AND group_id=?");
                $del->bind_param("ii", $event_id, $group_id);
                $del->execute();
                $del->close();
                $conn->query("DELETE FROM group_event_rsvps WHERE event_id=" . $event_id);

                $media_res = $conn->query("SELECT comment_media FROM group_event_comments WHERE event_id=" . $event_id);
                if ($media_res) {
                    while ($mr = $media_res->fetch_assoc()) {
                        foreach (ge_decode_media($mr['comment_media'] ?? null) as $mf) {
                            @unlink($mf);
                        }
                    }
                }

                $conn->query("DELETE lk FROM group_event_comment_likes lk INNER JOIN group_event_comments c ON c.id = lk.comment_id WHERE c.event_id=" . $event_id);
                $conn->query("DELETE FROM group_event_comments WHERE event_id=" . $event_id);
                if (!empty($ev['event_image'])) {
                    @unlink('uploads/' . $ev['event_image']);
                }
            }
        }
        header("Location: group_events.php?id=$group_id");
        exit();
    }

    /* ── Toggle RSVP (interested / going) — blocked once the event has started ── */
    if ($valid_csrf && isset($_POST['toggle_rsvp'])) {
        $event_id = (int)($_POST['event_id'] ?? 0);
        $rsvp_type = ($_POST['rsvp_type'] ?? '') === 'going' ? 'going' : 'interested';

        $stmt = $conn->prepare("SELECT event_date, event_time FROM group_events WHERE id=? AND group_id=?");
        $stmt->bind_param("ii", $event_id, $group_id);
        $stmt->execute();
        $ev = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($ev && !ge_event_has_ended($ev['event_date'], $ev['event_time'])) {
            $stmt = $conn->prepare("SELECT id FROM group_event_rsvps WHERE event_id=? AND user_id=? AND rsvp_type=?");
            $stmt->bind_param("iis", $event_id, $current_user, $rsvp_type);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($existing) {
                $del = $conn->prepare("DELETE FROM group_event_rsvps WHERE id=?");
                $del->bind_param("i", $existing['id']);
                $del->execute();
                $del->close();
            } else {
                $ins = $conn->prepare("INSERT INTO group_event_rsvps (event_id, user_id, rsvp_type) VALUES (?,?,?)");
                $ins->bind_param("iis", $event_id, $current_user, $rsvp_type);
                $ins->execute();
                $ins->close();
            }
        }

        if (ge_is_ajax()) {
            $counts = $conn->query("SELECT
                SUM(CASE WHEN rsvp_type='interested' THEN 1 ELSE 0 END) AS ic,
                SUM(CASE WHEN rsvp_type='going' THEN 1 ELSE 0 END) AS gc
                FROM group_event_rsvps WHERE event_id=" . (int)$event_id)->fetch_assoc();
            $mine = $conn->query("SELECT rsvp_type FROM group_event_rsvps WHERE event_id=" . (int)$event_id . " AND user_id=" . (int)$current_user);
            $my_now = [];
            while ($m = $mine->fetch_assoc()) { $my_now[$m['rsvp_type']] = true; }

            ge_json([
                'success' => true,
                'event_id' => $event_id,
                'interested_count' => (int)($counts['ic'] ?? 0),
                'going_count' => (int)($counts['gc'] ?? 0),
                'interested_active' => isset($my_now['interested']),
                'going_active' => isset($my_now['going']),
                'ended' => ($ev && ge_event_has_ended($ev['event_date'], $ev['event_time'])),
            ]);
        }

        header("Location: group_events.php?id=$group_id");
        exit();
    }

    /* ── Post a discussion comment (blocked once the event has started) —
       accepts up to 4 attached images via comment_images[]; a comment
       can be text only, images only, or both. ── */
    if ($valid_csrf && isset($_POST['add_comment'])) {
        $event_id = (int)($_POST['event_id'] ?? 0);
        $comment_text = trim($_POST['comment_text'] ?? '');
        $new_comment_id = 0;
        $allowed = false;
        $comment_media = [];
        $ev_chk = null;

        if (!empty($_FILES['comment_images']) && !empty($_FILES['comment_images']['name'][0])) {
            $allowed_ext = ['jpg','jpeg','png','gif','webp'];
            $names = $_FILES['comment_images']['name'];
            $total = count($names);
            for ($i = 0; $i < $total && count($comment_media) < 4; $i++) {
                if (($_FILES['comment_images']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
                $ext = strtolower(pathinfo($names[$i], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed_ext, true)) continue;
                if (!is_dir('uploads')) { @mkdir('uploads', 0755, true); }
                $mname = 'cmt_' . uniqid() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (@move_uploaded_file($_FILES['comment_images']['tmp_name'][$i], 'uploads/' . $mname)) {
                    $comment_media[] = 'uploads/' . $mname;
                }
            }
        }

        if ($event_id && ($comment_text !== '' || !empty($comment_media))) {
            $stmt = $conn->prepare("SELECT visibility, event_date, event_time FROM group_events WHERE id=? AND group_id=?");
            $stmt->bind_param("ii", $event_id, $group_id);
            $stmt->execute();
            $ev_chk = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($ev_chk && !ge_event_has_ended($ev_chk['event_date'], $ev_chk['event_time']) && ge_can_interact($ev_chk['visibility'], $is_member)) {
                $allowed = true;
                $comment_text = mb_substr($comment_text, 0, 2000);
                $comment_media_json = !empty($comment_media) ? json_encode($comment_media) : null;
                $ins = $conn->prepare("INSERT INTO group_event_comments (event_id, user_id, comment, comment_media) VALUES (?,?,?,?)");
                $ins->bind_param("iiss", $event_id, $current_user, $comment_text, $comment_media_json);
                $ins->execute();
                $new_comment_id = $ins->insert_id;
                $ins->close();
            } else {
                foreach ($comment_media as $mf) { @unlink($mf); }
            }
        } else {
            foreach ($comment_media as $mf) { @unlink($mf); }
        }

        if (ge_is_ajax()) {
            if (!$allowed || !$new_comment_id) {
                ge_json([
                    'success' => false,
                    'ended' => (!empty($ev_chk) && ge_event_has_ended($ev_chk['event_date'], $ev_chk['event_time'])),
                ]);
            }
            $stmt = $conn->prepare("SELECT c.*, u.username, u.profile_pic FROM group_event_comments c JOIN users u ON u.id=c.user_id WHERE c.id=?");
            $stmt->bind_param("i", $new_comment_id);
            $stmt->execute();
            $c = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $count_row = $conn->query("SELECT COUNT(*) AS c FROM group_event_comments WHERE event_id=" . (int)$event_id)->fetch_assoc();

            ge_json([
                'success' => true,
                'event_id' => $event_id,
                'comment_id' => (int)$c['id'],
                'username' => $c['username'],
                'profile_pic' => $c['profile_pic'],
                'comment' => $c['comment'],
                'media' => ge_decode_media($c['comment_media'] ?? null),
                'time' => ge_fmt_comment_time($c['created_at']),
                'comment_count' => (int)$count_row['c'],
            ]);
        }

        header("Location: group_events.php?id=$group_id&discuss=$event_id#event-detail-$event_id");
        exit();
    }

    /* ── Toggle like on a discussion comment ── */
    if ($valid_csrf && isset($_POST['toggle_comment_like'])) {
        $comment_id = (int)($_POST['comment_id'] ?? 0);
        $event_id = (int)($_POST['event_id'] ?? 0);
        $now_liked = false;

        if ($comment_id) {
            $stmt = $conn->prepare("
                SELECT ge.visibility FROM group_event_comments c
                JOIN group_events ge ON ge.id = c.event_id
                WHERE c.id=? AND ge.group_id=?
            ");
            $stmt->bind_param("ii", $comment_id, $group_id);
            $stmt->execute();
            $c_chk = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($c_chk && ge_can_interact($c_chk['visibility'], $is_member)) {
                $stmt = $conn->prepare("SELECT id FROM group_event_comment_likes WHERE comment_id=? AND user_id=?");
                $stmt->bind_param("ii", $comment_id, $current_user);
                $stmt->execute();
                $existing = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($existing) {
                    $del = $conn->prepare("DELETE FROM group_event_comment_likes WHERE id=?");
                    $del->bind_param("i", $existing['id']);
                    $del->execute();
                    $del->close();
                    $now_liked = false;
                } else {
                    $ins = $conn->prepare("INSERT INTO group_event_comment_likes (comment_id, user_id) VALUES (?,?)");
                    $ins->bind_param("ii", $comment_id, $current_user);
                    $ins->execute();
                    $ins->close();
                    $now_liked = true;
                }
            }
        }

        if (ge_is_ajax()) {
            $count_row = $conn->query("SELECT COUNT(*) AS c FROM group_event_comment_likes WHERE comment_id=" . (int)$comment_id)->fetch_assoc();
            ge_json([
                'success' => true,
                'comment_id' => $comment_id,
                'liked' => $now_liked,
                'like_count' => (int)$count_row['c'],
            ]);
        }

        header("Location: group_events.php?id=$group_id&discuss=$event_id#event-detail-$event_id");
        exit();
    }

    /* ── Delete a discussion comment — the comment's own author,
       or a group owner/admin, can remove it. ── */
    if ($valid_csrf && isset($_POST['delete_comment'])) {
        $comment_id = (int)($_POST['comment_id'] ?? 0);
        $event_id = (int)($_POST['event_id'] ?? 0);
        $deleted = false;

        if ($comment_id) {
            $stmt = $conn->prepare("
                SELECT c.user_id, c.comment_media FROM group_event_comments c
                JOIN group_events ge ON ge.id = c.event_id
                WHERE c.id=? AND ge.group_id=?
            ");
            $stmt->bind_param("ii", $comment_id, $group_id);
            $stmt->execute();
            $c_chk = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($c_chk && ((int)$c_chk['user_id'] === $current_user || $is_owner || $is_admin)) {
                foreach (ge_decode_media($c_chk['comment_media'] ?? null) as $mf) {
                    @unlink($mf);
                }
                $conn->query("DELETE FROM group_event_comment_likes WHERE comment_id=" . (int)$comment_id);
                $del = $conn->prepare("DELETE FROM group_event_comments WHERE id=?");
                $del->bind_param("i", $comment_id);
                $del->execute();
                $del->close();
                $deleted = true;
            }
        }

        if (ge_is_ajax()) {
            $count_row = $conn->query("SELECT COUNT(*) AS c FROM group_event_comments WHERE event_id=" . (int)$event_id)->fetch_assoc();
            ge_json([
                'success' => $deleted,
                'comment_id' => $comment_id,
                'event_id' => $event_id,
                'comment_count' => (int)($count_row['c'] ?? 0),
            ]);
        }

        header("Location: group_events.php?id=$group_id&discuss=$event_id#event-detail-$event_id");
        exit();
    }
}

/* ══════════════════════════════════════════════════════════════════
   FETCH EVENTS + COUNTS + MY RSVP STATE
   ══════════════════════════════════════════════════════════════════ */
$events = [];
$res = $conn->query("
    SELECT ge.*, u.username AS creator_name, u.profile_pic AS creator_pic,
           SUM(CASE WHEN r.rsvp_type='interested' THEN 1 ELSE 0 END) AS interested_count,
           SUM(CASE WHEN r.rsvp_type='going' THEN 1 ELSE 0 END) AS going_count
    FROM group_events ge
    JOIN users u ON u.id = ge.created_by
    LEFT JOIN group_event_rsvps r ON r.event_id = ge.id
    WHERE ge.group_id = $group_id
    GROUP BY ge.id
    ORDER BY ge.event_date DESC, ge.event_time DESC
");
while ($r = $res->fetch_assoc()) $events[] = $r;

$my_rsvp = [];
$comments_by_event = [];
$my_comment_likes = [];
if (!empty($events)) {
    $ids = implode(',', array_map(fn($e) => (int)$e['id'], $events));

    $res = $conn->query("SELECT event_id, rsvp_type FROM group_event_rsvps WHERE user_id=$current_user AND event_id IN ($ids)");
    while ($r = $res->fetch_assoc()) {
        $my_rsvp[$r['event_id']][$r['rsvp_type']] = true;
    }

    $res = $conn->query("
        SELECT c.*, u.username, u.profile_pic,
               (SELECT COUNT(*) FROM group_event_comment_likes cl WHERE cl.comment_id = c.id) AS like_count
        FROM group_event_comments c
        JOIN users u ON u.id = c.user_id
        WHERE c.event_id IN ($ids)
        ORDER BY c.created_at ASC
    ");
    while ($r = $res->fetch_assoc()) {
        $comments_by_event[$r['event_id']][] = $r;
    }

    $res = $conn->query("SELECT comment_id FROM group_event_comment_likes WHERE user_id=$current_user");
    while ($r = $res->fetch_assoc()) {
        $my_comment_likes[(int)$r['comment_id']] = true;
    }
}

/* bucket by full date + time, not just date */
$today = date('Y-m-d');
$past_events = []; $current_events = []; $future_events = [];
foreach ($events as $ev) {
    if (ge_event_has_ended($ev['event_date'], $ev['event_time'])) $past_events[] = $ev;
    elseif ($ev['event_date'] === $today) $current_events[] = $ev;
    else $future_events[] = $ev;
}

/* total events in this pod — when zero, the page shows ONE empty state
   instead of three separate section empty states. */
$total_events = count($events);

/* which event's discussion panel + modal should auto-open on load */
$discuss_open_id = (int)($_GET['discuss'] ?? 0);

function ge_fmt_date($d) { return date('D, j M Y', strtotime($d)); }
function ge_fmt_time($t) { return date('g:i A', strtotime($t)); }
function ge_fmt_comment_time($t) { return date('j M, g:i A', strtotime($t)); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($group['title']); ?> | Group Events</title>
<script>try{if(localStorage.getItem('dz_theme')==='light')document.documentElement.setAttribute('data-theme','light');}catch(e){}</script>

<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">

<style>
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; font-family:'Poppins', sans-serif; }
button, a { cursor:pointer; outline:none; -webkit-tap-highlight-color:transparent; }

[class^="ri-"], [class*=" ri-"],
[class^="ri-"]::before, [class*=" ri-"]::before,
[class^="ri-"]::after,  [class*=" ri-"]::after {
    font-family:'remixicon' !important;
    font-style:normal; font-weight:normal; line-height:1;
    speak:none; text-transform:none;
    -webkit-font-smoothing:antialiased;
}

:root{
    --accent:        #1a56c4;
    --accent-soft:   rgba(26,86,196,.15);
    --accent-border: rgba(26,86,196,.40);
    --accent-strong: #13449d;
    --accent-bright: #2a68de;
    --accent-glow:   rgba(26,86,196,.30);
    --accent-text:   #1a56c4;      /* icons / hover text on the page background */
    --blue-text:     #7fa0ff;      /* light-blue text (dates, counts, "more") */
    --blue-link:     #6fa0ff;      /* links + liked state */
    --on-accent:     #000;         /* text on accent-coloured buttons */
    --fg:            255,255,255;  /* R,G,B of the tint used for text, lines and soft fills (dark theme = white) */

    --bg:       linear-gradient(-45deg,#010218,#070924,#070924);
    --surface:  rgba(var(--fg),0.05);
    --line:     rgba(var(--fg),0.09);
    --line2:    rgba(var(--fg),0.14);
    --text:     #ffffff;
    --ui-text:  #ffffff;
    --muted:    rgba(var(--fg),0.45);
    --sub:      rgba(var(--fg),0.62);
    --danger:   #e0384d;
    --danger-soft: rgba(224,56,77,.14);

    --sheet:        rgba(12,12,28,.98);   /* suggestions dropdown background */
    --ph:           #050a26;              /* picture placeholder */
    --avatar-bg:    linear-gradient(135deg,#262b3d,#171a24);
    --picked-text:  #cfe0ff;
    --hero-fade-end:rgba(6,8,20,.55);
    --cs:           dark;                 /* color-scheme for date/time inputs */

    --max-w:    580px;
    --topbar-h: 54px;
    --pad:      clamp(9px, 3vw, 16px);
    --ctl-h:    clamp(30px, 8vw, 36px);

    /* one rounded-corner size for every box and button on the page */
    --r:        5.5px;

    /* same size as the buttons on the untracked + files pages */
    --act-h:    clamp(25px, 7.2vw, 30px);
    --act-r:    5.5px;
}
@media (max-width:490px){ :root{ --topbar-h:48px; } }
@media (max-width:380px){ :root{ --topbar-h:44px; } }

html{ scroll-behavior:smooth; -webkit-text-size-adjust:100%; }

/* RESPONSIVE view: no scrollbars anywhere — scrolling still works. */
@media (max-width:768px){
    html, body, *{ scrollbar-width:none !important; -ms-overflow-style:none !important; }
    *::-webkit-scrollbar, html::-webkit-scrollbar, body::-webkit-scrollbar{ display:none !important; width:0 !important; height:0 !important; background:transparent !important; }
}

body{
    background:var(--bg);
    background-attachment:fixed;
    color:var(--text);
    min-height:100vh;
    display:flex; justify-content:center;
    overflow-x:hidden;
    -webkit-font-smoothing:antialiased;
}

/* ── centered lane + grey side lines ── */
.ac-lane{
    width:100%; max-width:var(--max-w);
    min-height:100vh;
    display:flex; flex-direction:column;
    padding-bottom:72px;
}
@media (min-width:801px){
    .ac-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); }
}
@media (max-width:800px){ .ac-lane{ max-width:100%; } }

/* ══════════════════════════════════════
   TOPBAR — X on the left, + on the right
══════════════════════════════════════ */
.ac-topbar{
    position:sticky; top:0; z-index:500;
    height:var(--topbar-h);
    display:flex; align-items:center; gap:2px;
    padding:0 10px;
    background:var(--bg);
    background-attachment:fixed;
    border-bottom:1px solid var(--line);
}
.ac-x{
    width:34px; height:34px;
    background:none; border:none; color:var(--ui-text);
    display:flex; align-items:center; justify-content:center;
    opacity:.9; transition:opacity .2s, color .2s;
    padding:0; flex-shrink:0; text-decoration:none;
}
.ac-x:hover{ opacity:1; color:var(--accent-text); }
.ac-x svg{ width:22px; height:22px; display:block; }
.ac-actions{ display:flex; align-items:center; gap:6px; margin-left:auto; flex-shrink:0; }

/* plain + (no box) — same size as the X, dark blue like the page buttons */
.add-btn{
    width:34px; height:34px;
    background:none; border:none; padding:0;
    color:var(--accent-text);
    display:flex; align-items:center; justify-content:center;
    flex-shrink:0;
    transition:color .2s ease, transform .15s ease;
}
.add-btn svg{ width:22px; height:22px; display:block; }
.add-btn:hover{ color:var(--accent-bright); }
.add-btn:active{ transform:scale(.92); }
@media (max-width:490px){
    .ac-x{ width:30px; height:30px; }
    .add-btn{ width:30px; height:30px; }
}

/* ── Section label ── */
.section-label{
    padding:clamp(14px, 4vw, 20px) var(--pad) 8px;
    font-size:.6rem; font-weight:700; text-transform:uppercase; letter-spacing:1.5px; color:var(--muted);
    display:flex; align-items:center; gap:8px;
    border-top:1px solid var(--line);
}
.section-label.first{ border-top:none; }
.section-label i{ color:var(--accent-text); font-size:.85rem; }
.count-badge{
    background:var(--accent-soft); color:var(--blue-text);
    font-size:.6rem; font-weight:700; padding:2px 8px; border-radius:var(--r); letter-spacing:0;
    text-transform:none;
}

/* ══════════════════════════════════════
   EVENT LIST — flush rows with full-width dividers
══════════════════════════════════════ */
.events-list{ width:100%; border-top:1px solid var(--line); }
.event-row{
    display:flex; align-items:flex-start; gap:clamp(10px, 3vw, 14px);
    padding:clamp(12px, 3.4vw, 16px) var(--pad);
    border-bottom:1px solid var(--line);
    transition:background .2s;
    position:relative; cursor:pointer;
}
.event-row:hover{ background:rgba(var(--fg),.04); }
.event-row.is-past .event-thumb{ opacity:.6; }
.event-row.is-past .ev-title,
.event-row.is-past .ev-loc,
.event-row.is-past .ev-counts{ opacity:.6; }

.event-thumb{
    width:clamp(74px, 21vw, 96px); height:clamp(74px, 21vw, 96px); border-radius:var(--r); overflow:hidden; flex-shrink:0;
    background:var(--ph); position:relative;
}
.event-thumb img{ width:100%; height:100%; object-fit:cover; display:block; }

.geo-art-svg{ width:100%; height:100%; display:block; }
.event-thumb .thumb-no-img{
    width:100%; height:100%; position:relative; overflow:hidden;
    background:#010218;
    box-shadow:inset 0 0 0 1px rgba(127,160,255,.18);
    border-radius:inherit;
}

@keyframes geo-float-a{ from{ transform:translate(0,0); } to{ transform:translate(7px,-9px); } }
@keyframes geo-float-b{ from{ transform:translate(0,0); } to{ transform:translate(-6px,7px); } }
.detail-hero .geo-drift{   animation:geo-float-a 9s  ease-in-out infinite alternate; }
.detail-hero .geo-drift-2{ animation:geo-float-b 12s ease-in-out infinite alternate; }
@media (prefers-reduced-motion: reduce){
    .detail-hero .geo-drift, .detail-hero .geo-drift-2{ animation:none; }
}

/* badges that sit ON the picture stay dark + white in both themes */
.thumb-past-badge{
    position:absolute; bottom:4px; left:4px;
    background:rgba(0,0,0,.7); color:rgba(255,255,255,.85);
    font-size:.5rem; font-weight:700; padding:2px 6px; border-radius:var(--r);
}
.thumb-meetup-tag{
    position:absolute; bottom:4px; right:4px;
    display:flex; align-items:center; gap:3px;
    background:rgba(4,6,18,.72);
    backdrop-filter:blur(3px); -webkit-backdrop-filter:blur(3px);
    color:rgba(255,255,255,.9);
    font-size:.5rem; font-weight:600; letter-spacing:.2px;
    padding:2px 6px; border-radius:var(--r);
    line-height:1.4;
}
.thumb-meetup-tag i{ font-size:.6rem; color:#8aa8ff; }
.hero-meetup-tag{
    bottom:14px; right:14px;
    font-size:.7rem; font-weight:600; padding:4px 12px; border-radius:var(--r);
    z-index:2; gap:5px;
}
.hero-meetup-tag i{ font-size:.85rem; }

.row-del-form{ position:absolute; top:clamp(12px, 3.4vw, 16px); right:calc(var(--pad) - 4px); z-index:3; }
.row-del-btn{
    width:clamp(26px, 7.5vw, 31px); height:clamp(26px, 7.5vw, 31px); border-radius:var(--act-r);
    background:rgba(var(--fg),0.1); border:none;
    color:rgba(var(--fg),.7); font-size:.85rem; display:flex; align-items:center; justify-content:center;
    transition:background .2s ease, color .2s ease;
}
.row-del-btn:hover{ background:var(--danger-soft); color:var(--danger); }

.event-info{ flex:1; min-width:0; padding-top:1px; padding-right:34px; }
.ev-datetime{ font-size:clamp(.64rem, 2vw, .72rem); color:var(--blue-text); font-weight:600; margin-bottom:3px; }
.ev-title{
    font-size:clamp(.78rem, 2.6vw, .9rem); font-weight:700; color:var(--ui-text); line-height:1.32; margin-bottom:3px;
    display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;
}
.ev-loc{ font-size:clamp(.62rem, 2vw, .72rem); color:var(--muted); margin-bottom:3px; display:flex; align-items:center; gap:4px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.ev-loc i{ font-size:.78rem; flex-shrink:0; }
.ev-loc span{ overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.ev-counts{ font-size:clamp(.62rem, 2vw, .72rem); color:var(--muted); margin-bottom:10px; }

.ev-actions{ display:flex; align-items:stretch; gap:6px; margin-right:-34px; }
.ev-actions > form,
.ev-actions > a.ev-action-btn{ flex:1 1 0; min-width:0; display:flex; }
.ev-actions > form .ev-action-btn{ flex:1; width:100%; }
.ev-actions .ev-action-btn{ white-space:nowrap; }
.ev-action-btn{
    display:flex; align-items:center; justify-content:center; gap:5px;
    height:var(--act-h); padding:0 8px; border-radius:var(--act-r); border:none;
    background:rgba(var(--fg),0.1);
    backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    color:var(--ui-text);
    font-size:.72rem; font-weight:600; line-height:1; text-decoration:none;
    transition:background .2s ease, transform .15s ease;
}
.ev-action-btn:hover{ background:rgba(var(--fg),0.2); }
.ev-action-btn:active{ transform:scale(.98); }
.ev-action-btn i{ font-size:.82rem; }
@media (max-width:490px){
    .ev-action-btn{ font-size:.62rem; padding:0 6px; gap:4px; }
    .ev-action-btn i{ font-size:.74rem; }
}

/* BLUE buttons carry BLACK words (active RSVP + "Click Link") */
.ev-action-btn.active,
.detail-rsvp-btn.active,
.ev-action-btn.link-btn,
.detail-rsvp-btn.link-btn,
.detail-event-ended-btn.link-btn{
    background:var(--accent); color:var(--on-accent); font-weight:700; cursor:pointer;
}
.ev-action-btn.active:hover,
.detail-rsvp-btn.active:hover,
.ev-action-btn.link-btn:hover,
.detail-rsvp-btn.link-btn:hover,
.detail-event-ended-btn.link-btn:hover{ background:var(--accent-bright); }
.ev-action-btn.link-btn:hover, .ev-action-btn.link-btn:focus, .ev-action-btn.link-btn:active, .ev-action-btn.link-btn:visited,
.detail-rsvp-btn.link-btn:hover, .detail-rsvp-btn.link-btn:focus, .detail-rsvp-btn.link-btn:active, .detail-rsvp-btn.link-btn:visited,
.detail-event-ended-btn.link-btn:hover, .detail-event-ended-btn.link-btn:focus, .detail-event-ended-btn.link-btn:active, .detail-event-ended-btn.link-btn:visited{
    text-decoration:none; color:var(--on-accent);
}

.ev-share-btn{
    width:clamp(26px, 7.5vw, 30px); height:var(--act-h); border-radius:var(--act-r); border:none;
    background:rgba(var(--fg),0.1); color:rgba(var(--fg),.75);
    display:flex; align-items:center; justify-content:center; font-size:1rem; flex-shrink:0;
    transition:background .2s ease, color .2s ease;
}
.ev-share-btn:hover{ background:rgba(var(--fg),0.2); color:var(--ui-text); }
.ev-share-btn.copied, .detail-share-btn.copied{ background:var(--accent); color:var(--on-accent); }

/* ══════════════════════════════════════
   EMPTY PAGE STATE — shown ONLY when the pod has no events at all.
   Centered in the lane, simple title + text + one button.
══════════════════════════════════════ */
.empty-page{
    flex:1;
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    text-align:center;
    padding:clamp(40px, 12vw, 80px) clamp(20px, 7vw, 36px);
    min-height:calc(100vh - var(--topbar-h) - 72px);
}
.ep-title{ font-size:clamp(1rem, 3.6vw, 1.2rem); font-weight:700; color:var(--ui-text); letter-spacing:-.2px; }
.ep-text{
    margin-top:8px; max-width:320px;
    font-size:clamp(.68rem, 2.2vw, .78rem); color:var(--muted); line-height:1.7;
}
.ep-btn{
    margin-top:clamp(18px, 5vw, 24px);
    display:flex; align-items:center; justify-content:center;
    height:var(--act-h); min-width:150px; padding:0 22px;
    border-radius:var(--act-r); border:none;
    background:var(--accent); color:var(--on-accent);
    font-size:.72rem; font-weight:700; text-decoration:none;
    transition:background .2s ease, transform .15s ease;
}
.ep-btn:hover{ background:var(--accent-bright); }
.ep-btn:active{ transform:scale(0.98); }
@media (max-width:490px){ .ep-btn{ font-size:.62rem; min-width:130px; } }

/* ══════════════════════════════════════
   MODALS — full-screen overlays that sit in the same centered lane
══════════════════════════════════════ */
.modal-overlay{
    position:fixed; inset:0; z-index:1000; display:none;
    justify-content:center; align-items:flex-start;
    background:var(--bg); background-attachment:fixed;
    overflow-y:auto;
}
.modal-overlay.active{ display:flex; }
.modal-lane{
    width:100%; max-width:var(--max-w); min-height:100%;
    display:flex; flex-direction:column;
}
@media (min-width:801px){ .modal-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); } }
@media (max-width:800px){ .modal-lane{ max-width:100%; } }

.modal-topbar{
    position:sticky; top:0; z-index:20;
    height:var(--topbar-h); display:flex; align-items:center; gap:2px; padding:0 10px;
    background:var(--bg); background-attachment:fixed;
    border-bottom:1px solid var(--line); flex-shrink:0;
}
.modal-title{
    flex:1; min-width:0; font-size:.92rem; font-weight:700; text-align:right; margin:0 6px 0 10px;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis; color:var(--ui-text);
}
@media (max-width:490px){ .modal-title{ font-size:.84rem; } }
.modal-body{ flex:1; padding:clamp(14px, 4vw, 20px) var(--pad) 40px; width:100%; }

/* ── Create event form ── */
.field-block{ margin-bottom:20px; }
.field-block:last-child{ margin-bottom:0; }
.field-label{
    font-size:.6rem; font-weight:700; text-transform:uppercase; letter-spacing:1.2px; color:var(--muted);
    display:flex; align-items:center; gap:6px; margin-bottom:9px;
}
.field-label i{ color:var(--accent-text); font-size:.85rem; }

.preview-box{
    border-radius:var(--r); overflow:hidden; height:clamp(86px, 24vw, 110px);
    border:1px dashed var(--line2); background:var(--ph);
    display:flex; align-items:center; justify-content:center; margin-bottom:10px; position:relative;
}
.preview-box img{ width:100%; height:100%; object-fit:cover; display:block; }
.preview-cover{ position:absolute; inset:0; }
/* sits on top of the dark cover art, so it stays dark + white in both themes */
.preview-empty{
    position:relative; z-index:1;
    font-size:clamp(.62rem, 2vw, .7rem); color:rgba(255,255,255,.9);
    display:flex; flex-direction:row; align-items:center; gap:6px;
    background:rgba(2,4,20,.66); border:1px solid rgba(127,160,255,.22);
    padding:6px 12px; border-radius:var(--act-r);
    backdrop-filter:blur(3px); -webkit-backdrop-filter:blur(3px);
}
.preview-empty i{ font-size:1rem; color:#7fa0ff; }

.gc-input, .gc-select{
    width:100%; height:var(--ctl-h); min-height:38px; padding:0 12px; border-radius:var(--r);
    border:1px solid var(--line2); background:var(--surface);
    color:var(--ui-text); font-size:clamp(.74rem, 2.4vw, .82rem); outline:none; transition:.2s;
    -webkit-appearance:none; appearance:none;
}
.gc-input:focus, .gc-select:focus{ border-color:var(--accent); background:rgba(var(--fg),.07); box-shadow:0 0 0 3px var(--accent-soft); }
.gc-input::placeholder{ color:var(--muted); }
.gc-input[type="date"], .gc-input[type="time"]{ color-scheme:var(--cs); }

.gc-textarea{
    width:100%; min-height:100px; padding:11px 12px; border-radius:var(--r);
    border:1px solid var(--line2); background:var(--surface);
    color:var(--ui-text); font-size:clamp(.72rem, 2.3vw, .8rem); outline:none; resize:vertical; transition:.2s;
}
.gc-textarea:focus{ border-color:var(--accent); background:rgba(var(--fg),.07); box-shadow:0 0 0 3px var(--accent-soft); }
.gc-textarea::placeholder{ color:var(--muted); }

.date-time-row{ display:flex; gap:9px; }
.date-time-row .gc-input{ flex:1; min-width:0; }

.loc-toggle{ display:flex; gap:8px; }
.loc-opt{
    flex:1; height:var(--act-h); min-height:34px; padding:0 8px; border-radius:var(--act-r); border:none;
    background:rgba(var(--fg),0.1); color:var(--ui-text); text-align:center;
    font-size:.72rem; font-weight:600; transition:background .2s ease, transform .15s ease;
    display:flex; flex-direction:row; align-items:center; justify-content:center; gap:6px; cursor:pointer;
}
.loc-opt:hover{ background:rgba(var(--fg),0.2); }
.loc-opt i{ font-size:.9rem; }
.loc-opt input{ display:none; }
.loc-opt.selected, .loc-opt.vis-opt.selected{ background:var(--accent); color:var(--on-accent); font-weight:700; }
@media (max-width:490px){ .loc-opt{ font-size:.62rem; } }

.file-label{
    display:inline-flex; align-items:center; justify-content:center; gap:6px;
    height:var(--act-h); padding:0 14px; border-radius:var(--act-r);
    background:rgba(var(--fg),0.1); border:none; color:var(--ui-text);
    font-size:.72rem; font-weight:600;
    cursor:pointer; transition:background .2s ease, transform .15s ease; white-space:nowrap;
}
.file-label:hover{ background:rgba(var(--fg),0.2); }
.file-label:active{ transform:scale(0.98); }
.file-label input{ display:none; }
@media (max-width:490px){ .file-label{ font-size:.62rem; } }

.gc-btn{
    height:var(--act-h); padding:0 14px; border-radius:var(--act-r); border:none;
    background:var(--accent); color:var(--on-accent); font-size:.72rem; font-weight:700;
    cursor:pointer; transition:background .2s ease, transform .15s ease; width:100%;
    display:flex; align-items:center; justify-content:center; gap:6px;
    margin-top:6px; line-height:1;
}
.gc-btn i{ color:var(--on-accent); font-size:.85rem; }
.gc-btn:hover{ background:var(--accent-bright); }
.gc-btn:active{ transform:scale(0.98); }
@media (max-width:490px){ .gc-btn{ font-size:.62rem; } }

/* ── Location search (physical meetups) ── */
.location-search-wrap{ position:relative; }
.location-search-input{
    width:100%; height:var(--ctl-h); min-height:38px; padding:0 38px 0 12px; border-radius:var(--r);
    border:1px solid var(--line2); background:var(--surface);
    color:var(--ui-text); font-size:clamp(.74rem, 2.4vw, .82rem); outline:none; transition:.2s;
}
.location-search-input:focus{ border-color:var(--accent); background:rgba(var(--fg),.07); box-shadow:0 0 0 3px var(--accent-soft); }
.location-search-input::placeholder{ color:var(--muted); }
.location-search-spinner{
    position:absolute; right:11px; top:50%; transform:translateY(-50%);
    width:15px; height:15px; border-radius:50%;
    border:2px solid rgba(var(--fg),.18); border-top-color:var(--accent);
    display:none; animation:loc-spin .7s linear infinite;
}
.location-search-spinner.active{ display:block; }
@keyframes loc-spin{ to{ transform:translateY(-50%) rotate(360deg); } }

.location-suggestions{
    position:absolute; left:0; right:0; top:calc(100% + 6px); z-index:40;
    background:var(--sheet); border:1px solid var(--line); border-radius:var(--r);
    max-height:230px; overflow-y:auto; display:none;
    box-shadow:0 14px 30px var(--shadow-strong, rgba(0,0,0,.5));
}
.location-suggestions.active{ display:block; }
.location-suggestion-item{
    display:flex; align-items:flex-start; gap:9px;
    padding:10px 12px; border-bottom:1px solid var(--line);
    font-size:.74rem; color:rgba(var(--fg),.85); line-height:1.4; cursor:pointer;
}
.location-suggestion-item:last-child{ border-bottom:none; }
.location-suggestion-item:hover, .location-suggestion-item.highlighted{ background:rgba(var(--fg),.06); }
.location-suggestion-item i{ color:var(--accent-text); font-size:.9rem; margin-top:2px; flex-shrink:0; }
.location-suggestion-empty, .location-suggestion-loading{
    padding:12px; font-size:.72rem; color:var(--muted); text-align:center;
}
.location-picked{
    display:none; align-items:center; gap:8px;
    margin-top:8px; padding:9px 11px; border-radius:var(--act-r);
    background:var(--accent-soft);
    font-size:.72rem; color:var(--picked-text);
}
.location-picked.active{ display:flex; }
.location-picked i.pin{ color:var(--blue-text); font-size:.95rem; flex-shrink:0; }
.location-picked span{ flex:1; line-height:1.4; }
.location-picked button{ background:none; border:none; color:rgba(var(--fg),.55); font-size:.95rem; flex-shrink:0; }
.location-picked button:hover{ color:var(--danger); }
.location-hint{ font-size:.64rem; color:var(--muted); margin-top:6px; line-height:1.5; }

/* ══════════════════════════════════════
   EVENT DETAIL MODAL
══════════════════════════════════════ */
/* sticky INSIDE the centered lane (not fixed to the viewport), so it is
   always exactly as wide as, and centered with, the page layout. The
   negative bottom margin lets it float over the hero picture. */
.detail-stickybar{
    position:sticky; top:0;
    width:100%; height:var(--topbar-h); z-index:60;
    margin-bottom:calc(var(--topbar-h) * -1);
    flex-shrink:0;
    pointer-events:none;
}
.detail-stickybar::before{
    content:''; position:absolute; inset:0;
    background:var(--bg); background-attachment:fixed;
    border-bottom:1px solid var(--line);
    opacity:0; transition:opacity .28s ease;
    pointer-events:none;
}
.detail-stickybar.is-scrolled::before{ opacity:1; }

.stickybar-title{
    position:absolute; top:0; left:54px; right:54px; height:var(--topbar-h);
    display:flex; align-items:center;
    font-weight:700; font-size:.86rem; color:var(--ui-text);
    opacity:0; transform:translateY(4px); transition:opacity .28s ease, transform .28s ease;
    pointer-events:none; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; z-index:1;
}
.stickybar-title.show-title{ opacity:1; transform:translateY(0); }

/* these float over the hero picture, so they are white with a shadow */
.detail-icon-btn{
    position:absolute; top:calc((var(--topbar-h) - 40px) / 2);
    width:40px; height:40px;
    background:none; border:none; color:#ffffff;
    display:flex; align-items:center; justify-content:center;
    font-size:1.6rem;
    filter:drop-shadow(0 1px 2px rgba(0,0,0,.9)) drop-shadow(0 0 7px rgba(0,0,0,.6));
    transition:opacity .2s ease, transform .15s ease;
    z-index:2; pointer-events:auto;
}
.detail-icon-btn:hover{ opacity:.8; }
.detail-icon-btn:active{ transform:scale(.9); }
.detail-icon-btn svg{ width:26px; height:26px; display:block; }
.detail-back-btn{ left:-2px; }
/* delete icon sized to match the chevron back button */
.detail-delete-icon-btn{ right:8px; font-size:1.75rem; }

.detail-hero{
    --hero-max:420px;
    width:100%; margin:0 auto; height:auto; min-height:140px; position:relative; flex-shrink:0;
    background:var(--ph); overflow:hidden;
}
.detail-hero img{ width:100%; height:auto; max-height:var(--hero-max); object-fit:cover; display:block; }
.detail-hero .no-img{
    width:100%; height:clamp(170px, 46vw, 230px); position:relative; overflow:hidden;
    background:#010218;
}
.detail-hero-fade{
    position:absolute; left:0; right:0; bottom:0; height:70px;
    background:linear-gradient(to bottom, rgba(6,8,20,0), var(--hero-fade-end));
    pointer-events:none;
}
@media (max-width:490px){ .detail-hero{ --hero-max:360px; } }

.detail-modal-body{
    --dpad:var(--pad);
    padding:clamp(14px, 4vw, 18px) var(--dpad) 32px;
    overflow:visible;
}

.detail-datetime{ font-size:clamp(.66rem, 2.1vw, .74rem); color:var(--blue-text); font-weight:600; display:flex; align-items:center; gap:6px; margin-bottom:6px; }
.detail-title{ font-size:clamp(.95rem, 3.4vw, 1.1rem); font-weight:700; color:var(--ui-text); line-height:1.35; margin-bottom:12px; }

.detail-summary-wrap{ margin-bottom:16px; }
.detail-summary{ font-size:clamp(.72rem, 2.3vw, .8rem); color:var(--sub); line-height:1.6; }
.more-toggle{
    background:none; border:none; color:var(--blue-text); font-size:.74rem; font-weight:700;
    padding:0; margin-left:4px; display:inline;
}
.more-toggle:hover{ text-decoration:underline; }

/* Interested / Going / Event Ended / Click Link — larger words */
.detail-rsvp-row{ display:flex; gap:8px; margin-bottom:14px; }
.detail-rsvp-row > form{ flex:1; min-width:0; display:flex; }
.detail-rsvp-btn{
    flex:1; display:flex; align-items:center; justify-content:center; gap:6px;
    height:var(--act-h); min-height:38px; padding:0 10px; border-radius:var(--act-r); border:none;
    background:rgba(var(--fg),0.1);
    backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    color:var(--ui-text);
    font-size:.8rem; font-weight:600; line-height:1; text-decoration:none;
    transition:background .2s ease, transform .15s ease;
}
.detail-rsvp-btn i{ font-size:.95rem; }
.detail-rsvp-btn:hover{ background:rgba(var(--fg),0.2); }
.detail-rsvp-btn:active{ transform:scale(.98); }
.detail-event-ended-btn{
    flex:1; display:flex; align-items:center; justify-content:center; gap:8px;
    height:var(--act-h); min-height:38px; padding:0 10px; border-radius:var(--act-r); border:none;
    background:rgba(var(--fg),.06); color:var(--muted);
    font-size:.8rem; font-weight:700; cursor:not-allowed; line-height:1;
    text-decoration:none;
}
.detail-event-ended-btn i{ font-size:1rem; }
.detail-share-btn{
    width:clamp(34px, 9vw, 40px); min-height:38px; height:var(--act-h); flex-shrink:0; display:flex; align-items:center; justify-content:center;
    border-radius:var(--act-r); border:none;
    background:rgba(var(--fg),0.1); color:rgba(var(--fg),.75);
    transition:background .2s ease, color .2s ease;
}
.detail-share-btn i{ font-size:1.2rem; }
.detail-share-btn:hover{ background:rgba(var(--fg),0.2); color:var(--ui-text); }
@media (max-width:490px){
    .detail-rsvp-btn, .detail-event-ended-btn{ font-size:.72rem; gap:5px; }
    .detail-rsvp-btn i{ font-size:.86rem; }
    .detail-event-ended-btn i{ font-size:.9rem; }
}

.detail-meta-list{
    display:flex; flex-direction:column; gap:9px;
    margin-bottom:18px;
    padding:12px 14px;
    background:var(--surface); border:1px solid var(--line); border-radius:var(--r);
}
.meta-row{ display:flex; align-items:center; gap:9px; font-size:clamp(.68rem, 2.2vw, .76rem); color:rgba(var(--fg),.75); }
.meta-row i{ font-size:.9rem; color:var(--accent-text); width:16px; text-align:center; flex-shrink:0; }
.meta-row .meta-label{ min-width:0; overflow-wrap:anywhere; white-space:normal; }
.meta-row a.meta-label{ text-decoration:underline; cursor:pointer; }
.meta-row a.meta-label.loc-virtual{ color:var(--blue-link); }
.meta-row a.meta-label.loc-physical{ color:rgba(var(--fg),.55); }
.meta-row a.meta-label:hover{ opacity:.85; }
.visibility-badge{ font-weight:600; color:var(--blue-text); }
.visibility-note{ color:rgba(var(--fg),.55); font-weight:500; }

/* ── Discussions toggle + panel ── */
.discuss-toggle-btn{
    width:100%; display:flex; align-items:center; justify-content:center; gap:8px;
    height:var(--act-h); min-height:36px; padding:0 14px; border-radius:var(--act-r); border:none;
    background:rgba(var(--fg),0.1); color:var(--ui-text); font-size:.72rem; font-weight:600;
    transition:background .2s ease, transform .15s ease; margin-bottom:14px;
}
.discuss-toggle-btn:hover{ background:rgba(var(--fg),0.2); }
.discuss-toggle-btn i{ font-size:.95rem; color:var(--blue-text); }
.discuss-toggle-btn i.chevron{ transition:transform .2s ease; margin-left:auto; }
.discuss-toggle-btn.open i.chevron{ transform:rotate(180deg); }
.discuss-toggle-btn .discuss-count{
    background:var(--accent-soft); color:var(--blue-text); font-size:.64rem; font-weight:700;
    padding:2px 8px; border-radius:var(--r);
}
@media (max-width:490px){ .discuss-toggle-btn{ font-size:.62rem; } }

.discuss-panel{ display:none; margin-bottom:20px; }
.discuss-panel.open{ display:block; }

.comment-list{ display:flex; flex-direction:column; gap:16px; margin-bottom:16px; }
.discuss-skeleton{ display:none; flex-direction:column; gap:14px; margin-bottom:16px; }
.discuss-skeleton.active{ display:flex; }
.dsk-row{ display:flex; gap:10px; align-items:flex-start; }
.dsk-avatar{ width:32px; height:32px; border-radius:50%; flex-shrink:0; }
.dsk-lines{ flex:1; display:flex; flex-direction:column; gap:7px; padding-top:4px; }
.dsk-line{ height:10px; border-radius:var(--r); }

.comment-item{ display:flex; flex-direction:column; }
.comment-item-top{ display:flex; align-items:center; gap:10px; }
.comment-avatar{
    width:32px; height:32px; border-radius:50%; overflow:hidden; flex-shrink:0;
    background:var(--avatar-bg);
    display:flex; align-items:center; justify-content:center; color:var(--accent-text); font-size:.9rem;
    border:2px solid var(--accent);
}
.comment-avatar img{ width:100%; height:100%; object-fit:cover; display:block; }
.comment-author-meta{ flex:1; min-width:0; }
.comment-username{ font-size:.74rem; font-weight:700; color:var(--ui-text); }
.comment-time-inline{ font-size:.6rem; color:var(--muted); margin-top:1px; display:flex; align-items:center; gap:4px; }
.comment-time-inline i{ font-size:.62rem; opacity:.8; }

.comment-text{
    margin:0 calc(var(--dpad) * -1);
    padding:9px var(--dpad) 0;
    font-size:.78rem; color:rgba(var(--fg),.8); line-height:1.55; word-break:break-word;
}
.comment-media-row{
    margin:0 calc(var(--dpad) * -1);
    padding:10px 0 0;
    display:flex; gap:6px;
}
.comment-media-item{
    flex:1 1 0; min-width:0; position:relative; overflow:hidden; border-radius:0;
    background:rgba(var(--fg),.04); cursor:pointer; aspect-ratio:1/1;
}
.comment-media-row[data-count="1"] .comment-media-item{ aspect-ratio:16/9; }
.comment-media-item img{ width:100%; height:100%; object-fit:cover; display:block; transition:transform .25s ease; }
.comment-media-item:hover img{ transform:scale(1.05); }

.comment-hr{
    margin:0 calc(var(--dpad) * -1);
    height:1px; background:var(--line2);
}
.comment-meta{ display:flex; align-items:center; gap:26px;
    margin:6px calc(var(--dpad) * -1) 0;
    padding:9px var(--dpad);
}
.comment-like-btn{
    display:flex; align-items:center; gap:4px; background:none; border:none;
    font-size:.66rem; font-weight:700; color:var(--muted); transition:.2s;
}
.comment-like-btn:hover{ color:var(--blue-text); }
.comment-like-btn.liked{ color:var(--blue-link); }
.comment-like-btn i{ font-size:.85rem; }
.comment-delete-btn{
    display:flex; align-items:center; gap:4px; background:none; border:none;
    font-size:.85rem; color:var(--muted); transition:.2s; margin-left:auto;
}
.comment-delete-btn:hover{ color:var(--danger); }
.no-comments{ font-size:.72rem; color:var(--muted); text-align:center; padding:14px 0; }

/* ── Comment composer ── */
.comment-form{ display:flex; flex-direction:column; gap:9px; }
.comment-media-preview{ display:flex; gap:7px; flex-wrap:wrap; }
.comment-media-preview:empty{ display:none; }
.comment-media-preview-item{
    position:relative; width:58px; height:58px; border-radius:var(--r); overflow:hidden;
    border:1px solid var(--line2); flex-shrink:0;
}
.comment-media-preview-item img{ width:100%; height:100%; object-fit:cover; display:block; }
.comment-media-preview-item button{
    position:absolute; top:2px; right:2px; width:17px; height:17px; border-radius:50%;
    background:rgba(0,0,0,.75); border:none; color:#fff; font-size:.62rem;
    display:flex; align-items:center; justify-content:center;
}
.comment-form-row{ display:flex; gap:8px; align-items:flex-end; }
.comment-attach-btn{
    width:36px; height:36px; border-radius:var(--act-r); border:none; flex-shrink:0;
    background:rgba(var(--fg),0.1); color:rgba(var(--fg),.75);
    display:flex; align-items:center; justify-content:center; font-size:1.05rem;
    transition:background .2s ease, color .2s ease; cursor:pointer;
}
.comment-attach-btn:hover{ background:rgba(var(--fg),0.2); color:var(--ui-text); }
.comment-form textarea{
    flex:1; min-height:36px; max-height:100px; padding:8px 11px; border-radius:var(--r);
    border:1px solid var(--line2); background:var(--surface);
    color:var(--ui-text); font-size:.76rem; outline:none; resize:none; line-height:1.4;
}
.comment-form textarea:focus{ border-color:var(--accent); box-shadow:0 0 0 3px var(--accent-soft); }
.comment-form textarea::placeholder{ color:var(--muted); }
.comment-form button[type="submit"]{
    width:36px; height:36px; border-radius:var(--act-r); border:none; flex-shrink:0;
    background:var(--accent); color:var(--on-accent); font-size:1rem;
    display:flex; align-items:center; justify-content:center;
    transition:background .2s ease, transform .15s ease;
}
.comment-form button[type="submit"]:hover{ background:var(--accent-bright); }
.comment-form button[type="submit"]:active{ transform:scale(.96); }
.discuss-locked{ font-size:.7rem; color:var(--muted); text-align:center; padding:8px 0 0; }

/* ── Comment image lightbox (dark scrim in both themes) ── */
.comment-image-lightbox{
    position:fixed; inset:0; z-index:3000; background:rgba(2,3,12,.92);
    display:none; align-items:center; justify-content:center; padding:24px;
}
.comment-image-lightbox.active{ display:flex; }
.comment-image-lightbox img{ max-width:100%; max-height:100%; border-radius:0; box-shadow:0 20px 60px rgba(0,0,0,.6); }
.comment-lightbox-close{
    position:absolute; top:14px; right:14px; width:44px; height:44px;
    background:none; border:none; border-radius:0; padding:0;
    color:#fff;
    display:flex; align-items:center; justify-content:center;
}
.comment-lightbox-close svg{ width:30px; height:30px; display:block; }

/* ── Meet your event holder ── */
.host-section{ margin:4px calc(var(--dpad) * -1) 0; padding:20px var(--dpad) 0; border-top:1px solid var(--line); }
.host-section-title{
    font-size:.6rem; font-weight:700; text-transform:uppercase; letter-spacing:1.5px;
    color:var(--muted); margin-bottom:14px; text-align:center;
}
.host-card{
    display:flex; flex-direction:column; align-items:center; gap:5px;
    background:var(--surface); border:1px solid var(--line); border-radius:var(--r);
    padding:22px 16px 18px;
}
.host-avatar-wrap{
    width:clamp(70px, 20vw, 88px); height:clamp(70px, 20vw, 88px); border-radius:50%;
    border:3px solid var(--accent);
    padding:3px;
    display:flex; align-items:center; justify-content:center;
    margin-bottom:6px;
}
.host-avatar{ width:100%; height:100%; border-radius:50%; object-fit:cover; display:block; }
.host-avatar-fallback{
    width:100%; height:100%; border-radius:50%;
    display:flex; align-items:center; justify-content:center;
    background:var(--avatar-bg); color:var(--accent-text); font-size:1.7rem;
}
.host-username{ font-size:clamp(.78rem, 2.6vw, .88rem); font-weight:700; color:var(--ui-text); }
.host-role{ font-size:.62rem; color:var(--muted); text-transform:uppercase; letter-spacing:1.1px; margin-bottom:10px; }
.host-view-btn{
    display:inline-flex; align-items:center; justify-content:center; gap:6px;
    height:var(--act-h); min-height:32px; padding:0 22px; border-radius:var(--act-r);
    background:rgba(var(--fg),0.1); border:none;
    color:var(--ui-text); font-size:.72rem; font-weight:600; text-decoration:none;
    transition:background .2s ease, transform .15s ease;
}
.host-view-btn:hover{ background:rgba(var(--fg),0.2); }
.host-view-btn:active{ transform:scale(.98); }
@media (max-width:490px){ .host-view-btn{ font-size:.62rem; } }

/* ══════════════════════════════════════
   SKELETONS — same lane, same lines
══════════════════════════════════════ */
.sk-shimmer{ position:relative; overflow:hidden; background:rgba(var(--fg),.06); border-radius:var(--r); }
.sk-shimmer::after{
    content:''; position:absolute; inset:0;
    background:linear-gradient(90deg, transparent, rgba(var(--fg),.09), transparent);
    transform:translateX(-100%); animation:skShimmer 1.5s infinite;
}
@keyframes skShimmer{ 100%{ transform:translateX(100%); } }

#page-skeleton{
    position:fixed; inset:0; z-index:9999;
    background:var(--bg); background-attachment:fixed;
    display:flex; justify-content:center;
    opacity:1; transition:opacity .35s ease; pointer-events:none;
}
#page-skeleton.hide{ opacity:0; }
.sk-lane{ width:100%; max-width:var(--max-w); display:flex; flex-direction:column; }
@media (min-width:801px){ .sk-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); } }
@media (max-width:800px){ .sk-lane{ max-width:100%; } }
.sk-topbar{ height:var(--topbar-h); display:flex; align-items:center; justify-content:space-between; padding:0 14px; border-bottom:1px solid var(--line); }
.sk-back{ width:20px; height:20px; }
.sk-add{ width:22px; height:22px; border-radius:var(--r); }
.sk-label{ padding:clamp(14px,4vw,20px) var(--pad) 8px; }
.sk-label-bar{ width:130px; height:10px; }
.sk-row-item{ display:flex; align-items:flex-start; gap:14px; padding:clamp(12px,3.4vw,16px) var(--pad); border-top:1px solid var(--line); }
.sk-thumb{ width:clamp(74px,21vw,96px); height:clamp(74px,21vw,96px); border-radius:var(--r); flex-shrink:0; }
.sk-lines{ flex:1; display:flex; flex-direction:column; gap:8px; padding-top:2px; }

/* detail skeleton */
.event-detail-skeleton{ display:none; flex-direction:column; align-items:stretch; width:100%; }
.evsk-hero{ width:100%; height:clamp(170px, 46vw, 230px); flex-shrink:0; border-radius:0; }
.evsk-body{ width:100%; padding:clamp(14px,4vw,18px) var(--pad) 28px; }
.evsk-line{ border-radius:var(--r); }
.evsk-row{ display:flex; align-items:center; gap:10px; margin-bottom:12px; }
.evsk-pill{ height:var(--act-h); min-height:38px; flex:1; border-radius:var(--act-r); }
.evsk-avatar{ width:32px; height:32px; border-radius:50%; flex-shrink:0; }
.evsk-meta-block{
    padding:12px 14px; border:1px solid var(--line); border-radius:var(--r);
    margin-bottom:18px; background:var(--surface); display:flex; flex-direction:column; gap:10px;
}
.evsk-toggle{ width:100%; height:36px; border-radius:var(--act-r); margin-bottom:18px; }
.evsk-host{
    display:flex; flex-direction:column; align-items:center; gap:10px;
    padding:22px 16px 18px; border:1px solid var(--line); border-radius:var(--r); margin-top:6px;
}
.evsk-host-avatar{ width:76px; height:76px; border-radius:50%; }

.event-detail-content{ width:100%; flex:1; display:flex; flex-direction:column; }

/* ═══════════════════════════════════════════════════════════════
   RESPONSIVE ONLY — everything 10% bigger (desktop untouched)
   Kept near the end so it wins over the rules above.
═══════════════════════════════════════════════════════════════ */
@media (max-width:800px){
    :root{
        --topbar-h:59px;
        --pad:clamp(10px, 3.3vw, 17.6px);
        --ctl-h:clamp(33px, 8.8vw, 39.6px);
        --r:6.05px;
        --act-h:clamp(27.5px, 7.92vw, 33px);
        --act-r:6.05px;
    }

    /* topbar */
    .ac-topbar{ padding:0 11px; }
    .ac-x{ width:37px; height:37px; }
    .ac-x svg{ width:24px; height:24px; }
    .ac-actions{ gap:6.6px; }
    .add-btn{ width:37px; height:37px; }
    .add-btn svg{ width:24px; height:24px; }

    /* section label */
    .section-label{ padding:clamp(15px, 4.4vw, 22px) var(--pad) 8.8px; font-size:.66rem; letter-spacing:1.65px; gap:8.8px; }
    .section-label i{ font-size:.94rem; }
    .count-badge{ font-size:.66rem; padding:2.2px 8.8px; }

    /* event rows */
    .event-row{ gap:clamp(11px, 3.3vw, 15px); padding:clamp(13px, 3.74vw, 17.6px) var(--pad); }
    .event-thumb{ width:clamp(81px, 23.1vw, 106px); height:clamp(81px, 23.1vw, 106px); }
    .thumb-past-badge{ bottom:4.4px; left:4.4px; font-size:.55rem; padding:2.2px 6.6px; }
    .thumb-meetup-tag{ bottom:4.4px; right:4.4px; gap:3.3px; font-size:.55rem; padding:2.2px 6.6px; }
    .thumb-meetup-tag i{ font-size:.66rem; }
    .hero-meetup-tag{ bottom:15.4px; right:15.4px; font-size:.77rem; padding:4.4px 13px; gap:5.5px; }
    .hero-meetup-tag i{ font-size:.94rem; }
    .row-del-form{ top:clamp(13px, 3.74vw, 17.6px); }
    .row-del-btn{ width:clamp(29px, 8.25vw, 34px); height:clamp(29px, 8.25vw, 34px); font-size:.94rem; }
    .event-info{ padding-top:1.1px; padding-right:37px; }
    .ev-datetime{ font-size:clamp(.7rem, 2.2vw, .79rem); margin-bottom:3.3px; }
    .ev-title{ font-size:clamp(.86rem, 2.86vw, .99rem); margin-bottom:3.3px; }
    .ev-loc{ font-size:clamp(.68rem, 2.2vw, .79rem); margin-bottom:3.3px; gap:4.4px; }
    .ev-loc i{ font-size:.86rem; }
    .ev-counts{ font-size:clamp(.68rem, 2.2vw, .79rem); margin-bottom:11px; }
    .ev-actions{ gap:6.6px; margin-right:-37px; }
    .ev-action-btn{ gap:5.5px; padding:0 8.8px; font-size:.79rem; }
    .ev-action-btn i{ font-size:.9rem; }
    .ev-share-btn{ width:clamp(29px, 8.25vw, 33px); font-size:1.1rem; }

    /* empty page */
    .empty-page{ padding:clamp(44px, 13.2vw, 88px) clamp(22px, 7.7vw, 40px); }
    .ep-title{ font-size:clamp(1.1rem, 3.96vw, 1.32rem); }
    .ep-text{ margin-top:8.8px; max-width:352px; font-size:clamp(.75rem, 2.42vw, .86rem); }
    .ep-btn{ margin-top:clamp(20px, 5.5vw, 26px); min-width:165px; padding:0 24px; font-size:.79rem; }

    /* full-screen modals */
    .modal-topbar{ padding:0 11px; }
    .modal-title{ font-size:.99rem; margin:0 7px 0 11px; }
    .modal-body{ padding:clamp(15px, 4.4vw, 22px) var(--pad) 44px; }

    /* create-event form */
    .field-block{ margin-bottom:22px; }
    .field-label{ font-size:.66rem; letter-spacing:1.32px; gap:6.6px; margin-bottom:10px; }
    .field-label i{ font-size:.94rem; }
    .preview-box{ height:clamp(95px, 26.4vw, 121px); margin-bottom:11px; }
    .preview-empty{ font-size:clamp(.68rem, 2.2vw, .77rem); gap:6.6px; padding:6.6px 13px; }
    .preview-empty i{ font-size:1.1rem; }
    .gc-input, .gc-select{ min-height:42px; padding:0 13px; font-size:clamp(.81rem, 2.64vw, .9rem); }
    .gc-textarea{ min-height:110px; padding:12px 13px; font-size:clamp(.79rem, 2.53vw, .88rem); }
    .date-time-row{ gap:9.9px; }
    .loc-toggle{ gap:8.8px; }
    .loc-opt{ min-height:37.4px; padding:0 8.8px; font-size:.79rem; gap:6.6px; }
    .loc-opt i{ font-size:.99rem; }
    .file-label{ gap:6.6px; padding:0 15.4px; font-size:.79rem; }
    .gc-btn{ padding:0 15.4px; font-size:.79rem; gap:6.6px; margin-top:6.6px; }
    .gc-btn i{ font-size:.94rem; }

    /* location search */
    .location-search-input{ min-height:42px; padding:0 42px 0 13px; font-size:clamp(.81rem, 2.64vw, .9rem); }
    .location-search-spinner{ right:12px; width:16.5px; height:16.5px; }
    .location-suggestions{ top:calc(100% + 6.6px); max-height:253px; }
    .location-suggestion-item{ gap:10px; padding:11px 13px; font-size:.81rem; }
    .location-suggestion-item i{ font-size:.99rem; }
    .location-suggestion-empty, .location-suggestion-loading{ padding:13px; font-size:.79rem; }
    .location-picked{ margin-top:8.8px; padding:10px 12px; gap:8.8px; font-size:.79rem; }
    .location-picked i.pin{ font-size:1.045rem; }
    .location-picked button{ font-size:1.045rem; }
    .location-hint{ font-size:.7rem; margin-top:6.6px; }

    /* event detail: floating top bar */
    .stickybar-title{ left:59px; right:59px; font-size:.95rem; }
    .detail-icon-btn{ top:calc((var(--topbar-h) - 44px) / 2); width:44px; height:44px; font-size:1.76rem; }
    .detail-icon-btn svg{ width:28.6px; height:28.6px; }
    .detail-delete-icon-btn{ right:8.8px; font-size:1.925rem; }

    /* event detail: hero + body */
    .detail-hero{ --hero-max:462px; min-height:154px; }
    .detail-hero .no-img{ height:clamp(187px, 50.6vw, 253px); }
    .detail-hero-fade{ height:77px; }
    .detail-modal-body{ padding:clamp(15px, 4.4vw, 20px) var(--dpad) 35px; }
    .detail-datetime{ font-size:clamp(.73rem, 2.31vw, .81rem); gap:6.6px; margin-bottom:6.6px; }
    .detail-title{ font-size:clamp(1.045rem, 3.74vw, 1.21rem); margin-bottom:13px; }
    .detail-summary-wrap{ margin-bottom:17.6px; }
    .detail-summary{ font-size:clamp(.79rem, 2.53vw, .88rem); }
    .more-toggle{ font-size:.81rem; margin-left:4.4px; }

    /* RSVP row */
    .detail-rsvp-row{ gap:8.8px; margin-bottom:15.4px; }
    .detail-rsvp-btn{ gap:6.6px; min-height:41.8px; padding:0 11px; font-size:.88rem; }
    .detail-rsvp-btn i{ font-size:1.045rem; }
    .detail-event-ended-btn{ gap:8.8px; min-height:41.8px; padding:0 11px; font-size:.88rem; }
    .detail-event-ended-btn i{ font-size:1.1rem; }
    .detail-share-btn{ width:clamp(37px, 9.9vw, 44px); min-height:41.8px; }
    .detail-share-btn i{ font-size:1.32rem; }

    /* meta list */
    .detail-meta-list{ gap:9.9px; margin-bottom:19.8px; padding:13px 15.4px; }
    .meta-row{ gap:9.9px; font-size:clamp(.75rem, 2.42vw, .84rem); }
    .meta-row i{ font-size:.99rem; width:17.6px; }

    /* discussions */
    .discuss-toggle-btn{ gap:8.8px; min-height:39.6px; padding:0 15.4px; font-size:.79rem; margin-bottom:15.4px; }
    .discuss-toggle-btn i{ font-size:1.045rem; }
    .discuss-toggle-btn .discuss-count{ font-size:.7rem; padding:2.2px 8.8px; }
    .discuss-panel{ margin-bottom:22px; }
    .comment-list{ gap:17.6px; margin-bottom:17.6px; }
    .discuss-skeleton{ gap:15.4px; margin-bottom:17.6px; }
    .dsk-row{ gap:11px; }
    .dsk-avatar{ width:35px; height:35px; }
    .dsk-lines{ gap:7.7px; padding-top:4.4px; }
    .dsk-line{ height:11px; }
    .comment-item-top{ gap:11px; }
    .comment-avatar{ width:35px; height:35px; border-width:2.2px; font-size:.99rem; }
    .comment-username{ font-size:.81rem; }
    .comment-time-inline{ font-size:.66rem; gap:4.4px; }
    .comment-time-inline i{ font-size:.68rem; }
    .comment-text{ padding:10px var(--dpad) 0; font-size:.86rem; }
    .comment-media-row{ padding:11px 0 0; gap:6.6px; }
    .comment-meta{ gap:28.6px; margin:6.6px calc(var(--dpad) * -1) 0; padding:10px var(--dpad); }
    .comment-like-btn{ gap:4.4px; font-size:.73rem; }
    .comment-like-btn i{ font-size:.94rem; }
    .comment-delete-btn{ gap:4.4px; font-size:.94rem; }
    .no-comments{ font-size:.79rem; padding:15.4px 0; }

    /* comment composer */
    .comment-form{ gap:10px; }
    .comment-media-preview{ gap:7.7px; }
    .comment-media-preview-item{ width:64px; height:64px; }
    .comment-media-preview-item button{ width:18.7px; height:18.7px; font-size:.68rem; }
    .comment-form-row{ gap:8.8px; }
    .comment-attach-btn{ width:40px; height:40px; font-size:1.155rem; }
    .comment-form textarea{ min-height:40px; max-height:110px; padding:9px 12px; font-size:.84rem; }
    .comment-form button[type="submit"]{ width:40px; height:40px; font-size:1.1rem; }
    .discuss-locked{ font-size:.77rem; padding:8.8px 0 0; }

    /* lightbox */
    .comment-image-lightbox{ padding:26px; }
    .comment-lightbox-close{ top:15px; right:15px; width:48px; height:48px; }
    .comment-lightbox-close svg{ width:33px; height:33px; }

    /* event holder */
    .host-section{ padding:22px var(--dpad) 0; }
    .host-section-title{ font-size:.66rem; letter-spacing:1.65px; margin-bottom:15.4px; }
    .host-card{ gap:5.5px; padding:24px 17.6px 20px; }
    .host-avatar-wrap{ width:clamp(77px, 22vw, 97px); height:clamp(77px, 22vw, 97px); border-width:3.3px; padding:3.3px; margin-bottom:6.6px; }
    .host-avatar-fallback{ font-size:1.87rem; }
    .host-username{ font-size:clamp(.86rem, 2.86vw, .97rem); }
    .host-role{ font-size:.68rem; margin-bottom:11px; }
    .host-view-btn{ gap:6.6px; min-height:35px; padding:0 24px; font-size:.79rem; }

    /* page skeleton */
    .sk-topbar{ padding:0 15.4px; }
    .sk-back{ width:22px; height:22px; }
    .sk-add{ width:24px; height:24px; }
    .sk-label{ padding:clamp(15px, 4.4vw, 22px) var(--pad) 8.8px; }
    .sk-label-bar{ width:143px; height:11px; }
    .sk-row-item{ gap:15px; padding:clamp(13px, 3.74vw, 17.6px) var(--pad); }
    .sk-thumb{ width:clamp(81px, 23.1vw, 106px); height:clamp(81px, 23.1vw, 106px); }
    .sk-lines{ gap:8.8px; padding-top:2.2px; }

    /* detail skeleton */
    .evsk-hero{ height:clamp(187px, 50.6vw, 253px); }
    .evsk-body{ padding:clamp(15px, 4.4vw, 20px) var(--pad) 31px; }
    .evsk-row{ gap:11px; margin-bottom:13px; }
    .evsk-pill{ min-height:41.8px; }
    .evsk-avatar{ width:35px; height:35px; }
    .evsk-meta-block{ padding:13px 15.4px; margin-bottom:19.8px; gap:11px; }
    .evsk-toggle{ height:39.6px; margin-bottom:19.8px; }
    .evsk-host{ gap:11px; padding:24px 17.6px 20px; margin-top:6.6px; }
    .evsk-host-avatar{ width:84px; height:84px; }
}

@media (max-width:490px){
    :root{ --topbar-h:53px; }
    .ac-x{ width:33px; height:33px; }
    .add-btn{ width:33px; height:33px; }
    .ev-action-btn{ font-size:.68rem; padding:0 6.6px; gap:4.4px; }
    .ev-action-btn i{ font-size:.81rem; }
    .ep-btn{ font-size:.68rem; min-width:143px; }
    .modal-title{ font-size:.92rem; }
    .loc-opt{ font-size:.68rem; }
    .file-label{ font-size:.68rem; }
    .gc-btn{ font-size:.68rem; }
    .detail-hero{ --hero-max:396px; }
    .detail-rsvp-btn, .detail-event-ended-btn{ font-size:.79rem; gap:5.5px; }
    .detail-rsvp-btn i{ font-size:.95rem; }
    .detail-event-ended-btn i{ font-size:.99rem; }
    .discuss-toggle-btn{ font-size:.68rem; }
    .host-view-btn{ font-size:.68rem; }
}

@media (max-width:380px){
    :root{ --topbar-h:48px; }
}

/* ═══════════ LIGHT MODE — <html data-theme="light"> (dark mode is never touched) ═══════════
   Same palette as the community + files pages. The theme is read from localStorage 'dz_theme'.
   The geometric covers, the badges on pictures and the image lightbox stay dark on purpose. */
:root[data-theme="light"]{
    color-scheme:light;
    --fg:6,7,26;
    --ui-text:#0b0d24;
    --text:#0b0d24;
    --bg:linear-gradient(-45deg,#ffffff,#f4f5fb,#f4f5fb);
    --surface:rgba(6,7,26,.05);
    --line:rgba(6,7,26,.1);
    --line2:rgba(6,7,26,.2);
    --muted:rgba(6,7,26,.52);
    --sub:rgba(6,7,26,.66);

    --accent:#2a5fd8;
    --accent-bright:#234fb5;
    --accent-soft:rgba(42,95,216,.14);
    --accent-border:rgba(42,95,216,.42);
    --accent-text:#1a56db;
    --blue-text:#1a56db;
    --blue-link:#1a56db;

    --danger:#d92b2b;
    --danger-soft:rgba(217,43,43,.12);

    --sheet:#ffffff;
    --shadow-strong:rgba(0,0,0,.16);
    --ph:#eceef5;
    --avatar-bg:#eceef5;
    --picked-text:#1a3f9e;
    --hero-fade-end:rgba(255,255,255,.55);
    --cs:light;
}
/* back / delete icons: white over the picture, but dark once the bar has turned solid white */
[data-theme="light"] .detail-stickybar.is-scrolled .detail-icon-btn{ color:var(--ui-text); filter:none; }
</style>
</head>
<body>

<!-- ══ SKELETON (page load) ══ -->
<div id="page-skeleton">
    <div class="sk-lane">
        <div class="sk-topbar">
            <div class="sk-shimmer sk-back"></div>
            <div class="sk-shimmer sk-add"></div>
        </div>
        <?php if ($total_events > 0): ?>
        <div class="sk-label"><div class="sk-shimmer sk-label-bar"></div></div>
        <?php for($i=0;$i<4;$i++): ?>
        <div class="sk-row-item">
            <div class="sk-shimmer sk-thumb"></div>
            <div class="sk-lines">
                <div class="sk-shimmer" style="width:70px;height:9px;"></div>
                <div class="sk-shimmer" style="width:140px;height:12px;"></div>
                <div class="sk-shimmer" style="width:90px;height:9px;"></div>
            </div>
        </div>
        <?php endfor; ?>
        <?php endif; ?>
    </div>
</div>

<div class="ac-lane">

    <!-- ══ TOPBAR ══ -->
    <div class="ac-topbar">
        <button type="button" class="ac-x" onclick="acClose()" aria-label="Close">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
        <div class="ac-actions">
            <button type="button" class="add-btn" onclick="openModal('modal-create-event')" aria-label="Create event">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 4v16M4 12h16"/></svg>
            </button>
        </div>
    </div>

<?php
/* ═══ Reusable event row + detail-modal renderer ═══ */
function render_event_row($ev, $bucket, $current_user, $is_owner, $is_admin, $csrf_token, $my_rsvp, $group_id, $comments_by_event, $my_comment_likes, $is_member, $discuss_open_id) {
    $img = !empty($ev['event_image']) ? 'uploads/' . $ev['event_image'] : '';
    $can_delete = ((int)$ev['created_by'] === $current_user) || $is_owner || $is_admin;
    $is_past = ($bucket === 'past');
    $is_virtual = $ev['location_type'] === 'virtual';
    $interested_active = isset($my_rsvp[$ev['id']]['interested']);
    $going_active = isset($my_rsvp[$ev['id']]['going']);
    $del_msg = 'Delete "' . $ev['event_name'] . '" permanently? This cannot be undone.';
    $modal_id = 'event-detail-' . $ev['id'];
    $share_url = 'group_events.php?id=' . $group_id . '#' . $modal_id;
    $datetime_str = ge_fmt_date($ev['event_date']) . ' at ' . ge_fmt_time($ev['event_time']);

    $cover_thumb = ge_cover_thumb($ev['id']);
    $cover_hero  = ge_cover_hero($ev['id']);

    /* unix timestamp (seconds) at which this event flips to "past" */
    $end_ts = strtotime($ev['event_date'] . ' ' . $ev['event_time']);
    $end_ts = ($end_ts === false) ? 0 : ($end_ts + GE_EVENT_GRACE_SECONDS);

    $location_text = trim($ev['location_text'] ?? '');
    $visibility = ($ev['visibility'] ?? 'private') === 'public' ? 'public' : 'private';
    $can_interact = ge_can_interact($visibility, $is_member);
    $loc_display = $location_text !== '' ? $location_text : ($is_virtual ? 'Virtual meetup' : 'Physical meetup');

    $loc_display_row = (mb_strlen($loc_display) > 15) ? (mb_substr($loc_display, 0, 15) . '…') : $loc_display;

    $has_link = $is_virtual && ge_looks_like_url($location_text);
    $virtual_link_url = $has_link ? ge_normalize_url($location_text) : '';
    $has_map = (!$is_virtual) && ($location_text !== '');
    $map_url = $has_map ? ge_map_url($location_text) : '';

    $raw_summary = $ev['summary'] ?? '';
    $needs_trunc = mb_strlen($raw_summary) > 180;
    $short_raw = $needs_trunc ? mb_substr($raw_summary, 0, 180) : $raw_summary;
    $full_html = nl2br(htmlspecialchars($raw_summary));
    $short_html = nl2br(htmlspecialchars($short_raw)) . ($needs_trunc ? '&hellip;' : '');

    $host_name = $ev['creator_name'] ?? '';
    $host_pic = $ev['creator_pic'] ?? '';
    $host_id = (int)$ev['created_by'];

    $comments = $comments_by_event[$ev['id']] ?? [];
    $comment_count = count($comments);

    $show_discussions = true;
    $auto_open_discuss = ($discuss_open_id === (int)$ev['id']);
    ?>
    <div class="event-row<?php echo $is_past ? ' is-past' : ''; ?>"<?php echo (!$is_past && $end_ts) ? ' data-end-ts="' . (int)$end_ts . '"' : ''; ?> onclick="openEventModal('<?php echo $modal_id; ?>', <?php echo (int)$ev['id']; ?>)">
        <div class="event-thumb">
            <?php if ($img): ?>
                <img src="<?php echo htmlspecialchars($img); ?>" alt="" onerror="geImgFail(this)">
                <template class="geo-tpl"><?php echo $cover_thumb; ?></template>
            <?php else: ?>
                <?php echo $cover_thumb; ?>
            <?php endif; ?>
            <?php if ($is_past): ?><div class="thumb-past-badge">Past</div><?php endif; ?>
        </div>
        <?php if ($can_delete): ?>
        <form class="row-del-form" method="POST" action="group_events.php?id=<?php echo $group_id; ?>" onclick="event.stopPropagation()" onsubmit="return confirm(<?php echo ge_confirm_js($del_msg); ?>)">
            <input type="hidden" name="event_id" value="<?php echo (int)$ev['id']; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <button type="submit" name="delete_event" class="row-del-btn" title="Delete event permanently"><i class="ri-delete-bin-line"></i></button>
        </form>
        <?php endif; ?>
        <div class="event-info">
            <div class="ev-datetime"><?php echo $datetime_str; ?></div>
            <div class="ev-title"><?php echo htmlspecialchars($ev['event_name']); ?></div>
            <div class="ev-loc" title="<?php echo htmlspecialchars($loc_display); ?>">
                <i class="ri-map-pin-2-line"></i>
                <span><?php echo htmlspecialchars($loc_display_row); ?></span>
            </div>
            <div class="ev-counts" id="ev-counts-<?php echo (int)$ev['id']; ?>">
                <?php echo (int)$ev['interested_count']; ?> interested ·
                <?php echo (int)$ev['going_count']; ?> <?php echo $is_past ? 'went' : 'going'; ?>
            </div>

            <?php if (!$is_past): ?>
            <div class="ev-actions" onclick="event.stopPropagation()">
                <?php if ($has_link): ?>
                <a href="<?php echo htmlspecialchars($virtual_link_url); ?>" target="_blank" rel="noopener noreferrer" class="ev-action-btn link-btn">
                    <i class="ri-external-link-line"></i> Click Link
                </a>
                <?php else: ?>
                <form class="ajax-form" data-kind="rsvp" method="POST" action="group_events.php?id=<?php echo $group_id; ?>">
                    <input type="hidden" name="event_id" value="<?php echo (int)$ev['id']; ?>">
                    <input type="hidden" name="rsvp_type" value="interested">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <button type="submit" name="toggle_rsvp" value="1" id="ev-int-<?php echo (int)$ev['id']; ?>" class="ev-action-btn<?php echo $interested_active ? ' active' : ''; ?>">
                        <i class="ri-star-<?php echo $interested_active ? 'fill' : 'line'; ?>"></i> Interested
                    </button>
                </form>
                <?php endif; ?>
                <form class="ajax-form" data-kind="rsvp" method="POST" action="group_events.php?id=<?php echo $group_id; ?>">
                    <input type="hidden" name="event_id" value="<?php echo (int)$ev['id']; ?>">
                    <input type="hidden" name="rsvp_type" value="going">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <button type="submit" name="toggle_rsvp" value="1" id="ev-going-<?php echo (int)$ev['id']; ?>" class="ev-action-btn<?php echo $going_active ? ' active' : ''; ?>">
                        <i class="ri-check-<?php echo $going_active ? 'double-' : ''; ?>line"></i> Going
                    </button>
                </form>
                <button type="button" class="ev-share-btn" title="Share event"
                    data-name="<?php echo htmlspecialchars($ev['event_name'], ENT_QUOTES); ?>"
                    data-url="<?php echo htmlspecialchars($share_url, ENT_QUOTES); ?>"
                    onclick="shareEventRow(this, event)">
                    <i class="ri-share-forward-fill"></i>
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══ EVENT DETAIL MODAL ══ -->
    <div class="modal-overlay" id="<?php echo $modal_id; ?>">
      <div class="modal-lane">

        <!-- premium skeleton shown briefly the first time this event's modal is opened -->
        <div class="event-detail-skeleton" id="event-skeleton-<?php echo $ev['id']; ?>">
            <div class="evsk-hero sk-shimmer"></div>
            <div class="evsk-body">
                <div class="evsk-line sk-shimmer" style="width:150px;height:11px;margin-bottom:14px;"></div>
                <div class="evsk-line sk-shimmer" style="width:88%;height:20px;margin-bottom:8px;"></div>
                <div class="evsk-line sk-shimmer" style="width:58%;height:20px;margin-bottom:18px;"></div>
                <div class="evsk-line sk-shimmer" style="width:100%;height:13px;margin-bottom:6px;"></div>
                <div class="evsk-line sk-shimmer" style="width:94%;height:13px;margin-bottom:6px;"></div>
                <div class="evsk-line sk-shimmer" style="width:72%;height:13px;margin-bottom:20px;"></div>
                <div class="evsk-row">
                    <div class="evsk-pill sk-shimmer"></div>
                    <div class="evsk-pill sk-shimmer"></div>
                    <div class="evsk-pill sk-shimmer" style="flex:0 0 42px;"></div>
                </div>
                <div class="evsk-meta-block">
                    <div class="evsk-line sk-shimmer" style="width:78%;height:11px;"></div>
                    <div class="evsk-line sk-shimmer" style="width:58%;height:11px;"></div>
                    <div class="evsk-line sk-shimmer" style="width:44%;height:11px;"></div>
                </div>
                <div class="evsk-toggle sk-shimmer"></div>
                <div class="evsk-row" style="align-items:flex-start;">
                    <div class="evsk-avatar sk-shimmer"></div>
                    <div style="flex:1;">
                        <div class="evsk-line sk-shimmer" style="width:80px;height:9px;margin-bottom:7px;"></div>
                        <div class="evsk-line sk-shimmer" style="width:100%;height:36px;"></div>
                    </div>
                </div>
                <div class="evsk-host">
                    <div class="evsk-host-avatar sk-shimmer"></div>
                    <div class="evsk-line sk-shimmer" style="width:90px;height:12px;"></div>
                    <div class="evsk-line sk-shimmer" style="width:60px;height:9px;"></div>
                </div>
            </div>
        </div>

        <div class="event-detail-content" id="event-content-<?php echo $ev['id']; ?>">

        <div class="detail-stickybar" id="stickybar-<?php echo $ev['id']; ?>">
            <button type="button" class="detail-icon-btn detail-back-btn" onclick="closeModal('<?php echo $modal_id; ?>')" aria-label="Back">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 4.5L7.5 12L15 19.5"/></svg>
            </button>
            <span class="stickybar-title"><?php echo htmlspecialchars($ev['event_name']); ?></span>
            <?php if ($can_delete): ?>
            <form onclick="event.stopPropagation()" onsubmit="return confirm(<?php echo ge_confirm_js($del_msg); ?>)" method="POST" action="group_events.php?id=<?php echo $group_id; ?>" style="position:absolute; top:0; right:0; pointer-events:auto;">
                <input type="hidden" name="event_id" value="<?php echo (int)$ev['id']; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <button type="submit" name="delete_event" class="detail-icon-btn detail-delete-icon-btn" aria-label="Delete event" title="Delete event permanently">
                    <i class="ri-delete-bin-line"></i>
                </button>
            </form>
            <?php endif; ?>
        </div>

        <div class="detail-hero">
            <?php if ($img): ?>
                <img src="<?php echo htmlspecialchars($img); ?>" alt="" onerror="geImgFail(this)">
                <template class="geo-tpl"><?php echo $cover_hero; ?></template>
            <?php else: ?>
                <?php echo $cover_hero; ?>
            <?php endif; ?>
            <div class="detail-hero-fade"></div>
            <div class="thumb-meetup-tag hero-meetup-tag"><i class="ri-<?php echo $is_virtual ? 'live-line' : 'map-pin-line'; ?>"></i> <?php echo $is_virtual ? 'Virtual' : 'Physical'; ?></div>
        </div>

        <div class="modal-body detail-modal-body">
            <div class="detail-datetime"><i class="ri-calendar-line"></i> <?php echo $datetime_str; ?></div>
            <div class="detail-title"><?php echo htmlspecialchars($ev['event_name']); ?></div>

            <div class="detail-summary-wrap">
                <span class="detail-summary" id="sum-<?php echo $ev['id']; ?>"><?php echo $short_html; ?></span>
                <?php if ($needs_trunc): ?>
                <button type="button" class="more-toggle" data-expanded="0"
                    data-short="<?php echo htmlspecialchars($short_html, ENT_QUOTES); ?>"
                    data-full="<?php echo htmlspecialchars($full_html, ENT_QUOTES); ?>"
                    onclick="toggleSummary(this)">more</button>
                <?php endif; ?>
            </div>

            <?php if (!$is_past): ?>
            <div class="detail-rsvp-row">
                <?php if ($has_link): ?>
                <a href="<?php echo htmlspecialchars($virtual_link_url); ?>" target="_blank" rel="noopener noreferrer" class="detail-rsvp-btn link-btn">
                    <i class="ri-external-link-line"></i> Click Link
                </a>
                <?php else: ?>
                <form class="ajax-form" data-kind="rsvp" method="POST" action="group_events.php?id=<?php echo $group_id; ?>">
                    <input type="hidden" name="event_id" value="<?php echo (int)$ev['id']; ?>">
                    <input type="hidden" name="rsvp_type" value="interested">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <button type="submit" name="toggle_rsvp" value="1" id="detail-int-<?php echo (int)$ev['id']; ?>" class="detail-rsvp-btn<?php echo $interested_active ? ' active' : ''; ?>">
                        <i class="ri-star-<?php echo $interested_active ? 'fill' : 'line'; ?>"></i> <span class="rsvp-count"><?php echo (int)$ev['interested_count']; ?></span> Interested
                    </button>
                </form>
                <?php endif; ?>
                <form class="ajax-form" data-kind="rsvp" method="POST" action="group_events.php?id=<?php echo $group_id; ?>">
                    <input type="hidden" name="event_id" value="<?php echo (int)$ev['id']; ?>">
                    <input type="hidden" name="rsvp_type" value="going">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <button type="submit" name="toggle_rsvp" value="1" id="detail-going-<?php echo (int)$ev['id']; ?>" class="detail-rsvp-btn<?php echo $going_active ? ' active' : ''; ?>">
                        <i class="ri-check-<?php echo $going_active ? 'double-' : ''; ?>line"></i> <span class="rsvp-count"><?php echo (int)$ev['going_count']; ?></span> Going
                    </button>
                </form>
                <button type="button" class="detail-share-btn" title="Share event"
                    data-name="<?php echo htmlspecialchars($ev['event_name'], ENT_QUOTES); ?>"
                    data-url="<?php echo htmlspecialchars($share_url, ENT_QUOTES); ?>"
                    onclick="shareEventRow(this, event)">
                    <i class="ri-share-forward-fill"></i>
                </button>
            </div>
            <?php else: ?>
            <div class="detail-rsvp-row">
                <?php if ($has_link): ?>
                <a href="<?php echo htmlspecialchars($virtual_link_url); ?>" target="_blank" rel="noopener noreferrer" class="detail-event-ended-btn link-btn">
                    <i class="ri-external-link-line"></i> Click Link
                </a>
                <?php else: ?>
                <button type="button" class="detail-event-ended-btn" disabled tabindex="-1">
                    <i class="ri-time-line"></i> Event Ended
                </button>
                <?php endif; ?>
                <button type="button" class="detail-share-btn" title="Share event"
                    data-name="<?php echo htmlspecialchars($ev['event_name'], ENT_QUOTES); ?>"
                    data-url="<?php echo htmlspecialchars($share_url, ENT_QUOTES); ?>"
                    onclick="shareEventRow(this, event)">
                    <i class="ri-share-forward-fill"></i>
                </button>
            </div>
            <?php endif; ?>

            <!-- ══ location / counts / visibility meta block ══ -->
            <div class="detail-meta-list">
                <div class="meta-row">
                    <i class="ri-<?php echo $is_virtual ? 'live-line' : 'map-pin-line'; ?>"></i>
                    <?php if ($has_link): ?>
                        <a class="meta-label loc-virtual" href="<?php echo htmlspecialchars($virtual_link_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars($loc_display); ?></a>
                    <?php elseif ($has_map): ?>
                        <a class="meta-label loc-physical" href="<?php echo htmlspecialchars($map_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars($loc_display); ?></a>
                    <?php else: ?>
                        <span class="meta-label"><?php echo htmlspecialchars($loc_display); ?></span>
                    <?php endif; ?>
                </div>
                <div class="meta-row">
                    <i class="ri-group-line"></i>
                    <span class="meta-label" id="meta-counts-<?php echo (int)$ev['id']; ?>"><?php echo (int)$ev['interested_count']; ?> interested · <?php echo (int)$ev['going_count']; ?> <?php echo $is_past ? 'went' : 'going'; ?></span>
                </div>
                <div class="meta-row">
                    <i class="ri-<?php echo $visibility === 'public' ? 'earth-line' : 'lock-line'; ?>"></i>
                    <?php if ($visibility === 'public'): ?>
                        <span class="meta-label"><span class="visibility-badge public">Public</span> <span class="visibility-note">· For Any User on Dezzme</span></span>
                    <?php else: ?>
                        <span class="meta-label"><span class="visibility-badge private">Private · Group Members Only</span></span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($show_discussions): ?>
            <!-- ══ Discussions (visible on future, current AND past events) ══ -->
            <button type="button" class="discuss-toggle-btn<?php echo $auto_open_discuss ? ' open' : ''; ?>" id="discuss-toggle-<?php echo $ev['id']; ?>" onclick="toggleDiscuss(<?php echo (int)$ev['id']; ?>)">
                <i class="ri-chat-3-line"></i> Discussions <span class="discuss-count" id="discuss-count-<?php echo (int)$ev['id']; ?>"><?php echo $comment_count; ?></span> <i class="ri-arrow-down-s-line chevron"></i>
            </button>
            <div class="discuss-panel<?php echo $auto_open_discuss ? ' open' : ''; ?>" id="discuss-panel-<?php echo $ev['id']; ?>">
                <div class="discuss-skeleton" id="discuss-skeleton-<?php echo $ev['id']; ?>">
                    <div class="dsk-row"><div class="dsk-avatar sk-shimmer"></div><div class="dsk-lines"><div class="dsk-line sk-shimmer" style="width:80px;"></div><div class="dsk-line sk-shimmer" style="width:70%;"></div></div></div>
                    <div class="dsk-row"><div class="dsk-avatar sk-shimmer"></div><div class="dsk-lines"><div class="dsk-line sk-shimmer" style="width:100px;"></div><div class="dsk-line sk-shimmer" style="width:50%;"></div></div></div>
                </div>
                <div class="comment-list" id="comment-list-<?php echo (int)$ev['id']; ?>">
                    <div class="no-comments" id="no-comments-<?php echo (int)$ev['id']; ?>"<?php echo empty($comments) ? '' : ' style="display:none;"'; ?>><?php echo $is_past ? 'No comments were made on this event.' : 'No comments yet — start the discussion.'; ?></div>
                    <?php foreach ($comments as $c):
                        $c_liked = isset($my_comment_likes[(int)$c['id']]);
                        $c_pic = $c['profile_pic'] ?? '';
                        $c_can_delete = ((int)$c['user_id'] === $current_user) || $is_owner || $is_admin;
                        $c_media = ge_decode_media($c['comment_media'] ?? null);
                        $c_text = trim($c['comment'] ?? '');

                        $c_needs_trunc = mb_strlen($c_text) > 180;
                        $c_short_raw = $c_needs_trunc ? mb_substr($c_text, 0, 180) : $c_text;
                        $c_full_html = nl2br(htmlspecialchars($c_text));
                        $c_short_html = nl2br(htmlspecialchars($c_short_raw)) . ($c_needs_trunc ? '&hellip;' : '');
                    ?>
                    <div class="comment-item" data-comment-id="<?php echo (int)$c['id']; ?>">
                        <div class="comment-item-top">
                            <div class="comment-avatar">
                                <?php if (!empty($c_pic)): ?>
                                    <img src="<?php echo htmlspecialchars($c_pic); ?>" onerror="this.parentElement.innerHTML='<i class=&quot;ri-user-3-line&quot;></i>'">
                                <?php else: ?>
                                    <i class="ri-user-3-line"></i>
                                <?php endif; ?>
                            </div>
                            <div class="comment-author-meta">
                                <div class="comment-username"><?php echo htmlspecialchars($c['username']); ?></div>
                                <div class="comment-time-inline"><i class="ri-earth-line"></i> <?php echo ge_fmt_comment_time($c['created_at']); ?></div>
                            </div>
                        </div>
                        <?php if ($c_text !== ''): ?>
                        <div class="comment-text"><span class="comment-body"><?php echo $c_short_html; ?></span><?php if ($c_needs_trunc): ?><button type="button" class="more-toggle" data-expanded="0"
                            data-short="<?php echo htmlspecialchars($c_short_html, ENT_QUOTES); ?>"
                            data-full="<?php echo htmlspecialchars($c_full_html, ENT_QUOTES); ?>"
                            onclick="toggleSummary(this)">more</button><?php endif; ?></div>
                        <?php endif; ?>
                        <?php if (!empty($c_media)): ?>
                        <div class="comment-media-row" data-count="<?php echo count($c_media); ?>">
                            <?php foreach ($c_media as $mimg): ?>
                            <div class="comment-media-item" onclick="openCommentLightbox('<?php echo htmlspecialchars($mimg, ENT_QUOTES); ?>')">
                                <img src="<?php echo htmlspecialchars($mimg); ?>" loading="lazy" alt="Attached image">
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <div class="comment-meta">
                            <form class="ajax-form" data-kind="like" method="POST" action="group_events.php?id=<?php echo $group_id; ?>" onclick="event.stopPropagation()">
                                <input type="hidden" name="comment_id" value="<?php echo (int)$c['id']; ?>">
                                <input type="hidden" name="event_id" value="<?php echo (int)$ev['id']; ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <button type="submit" name="toggle_comment_like" value="1" id="comment-like-<?php echo (int)$c['id']; ?>" class="comment-like-btn<?php echo $c_liked ? ' liked' : ''; ?>">
                                    <i class="ri-thumb-up-<?php echo $c_liked ? 'fill' : 'line'; ?>"></i> <span class="like-count"><?php echo (int)$c['like_count']; ?></span>
                                </button>
                            </form>
                            <?php if ($c_can_delete): ?>
                            <form class="ajax-form" data-kind="delete-comment" method="POST" action="group_events.php?id=<?php echo $group_id; ?>" onclick="event.stopPropagation()">
                                <input type="hidden" name="comment_id" value="<?php echo (int)$c['id']; ?>">
                                <input type="hidden" name="event_id" value="<?php echo (int)$ev['id']; ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <button type="submit" name="delete_comment" value="1" class="comment-delete-btn" title="Delete comment"><i class="ri-delete-bin-line"></i></button>
                            </form>
                            <?php endif; ?>
                        </div>
                        <div class="comment-hr"></div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($is_past): ?>
                <div class="discuss-locked"><i class="ri-lock-line"></i> Commenting is closed because this event has ended.</div>
                <?php elseif ($can_interact): ?>
                <form class="comment-form ajax-form" data-kind="comment" method="POST" action="group_events.php?id=<?php echo $group_id; ?>" enctype="multipart/form-data" onclick="event.stopPropagation()">
                    <input type="hidden" name="event_id" value="<?php echo (int)$ev['id']; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <div class="comment-media-preview" id="comment-media-preview-<?php echo (int)$ev['id']; ?>"></div>
                    <div class="comment-form-row">
                        <label class="comment-attach-btn" title="Attach images">
                            <i class="ri-image-add-line"></i>
                            <input type="file" name="comment_images[]" accept="image/*" multiple style="display:none"
                                   data-event-id="<?php echo (int)$ev['id']; ?>"
                                   onchange="previewCommentMedia(this, <?php echo (int)$ev['id']; ?>)">
                        </label>
                        <textarea name="comment_text" placeholder="Write a comment..."></textarea>
                        <button type="submit" name="add_comment" value="1" aria-label="Post comment"><i class="ri-send-plane-fill"></i></button>
                    </div>
                </form>
                <?php else: ?>
                <div class="discuss-locked"><i class="ri-lock-line"></i> Only group members can comment on this event.</div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- ══ Meet your event holder ══ -->
            <div class="host-section">
                <div class="host-section-title">Meet your event holder</div>
                <div class="host-card">
                    <div class="host-avatar-wrap">
                        <?php if (!empty($host_pic)): ?>
                            <img class="host-avatar" src="<?php echo htmlspecialchars($host_pic); ?>" onerror="this.outerHTML='<div class=&quot;host-avatar-fallback&quot;><i class=&quot;ri-user-3-line&quot;></i></div>'">
                        <?php else: ?>
                            <div class="host-avatar-fallback"><i class="ri-user-3-line"></i></div>
                        <?php endif; ?>
                    </div>
                    <div class="host-username"><?php echo htmlspecialchars($host_name); ?></div>
                    <div class="host-role">Event Holder</div>
                    <a class="host-view-btn" href="profile.php?id=<?php echo $host_id; ?>">
                        <i class="ri-user-line"></i> View Profile
                    </a>
                </div>
            </div>
        </div>
        </div><!-- /.event-detail-content -->
      </div><!-- /.modal-lane -->
    </div>
    <?php
}

/* only the sections that actually have events are shown; the first one
   shown gets the "first" class so it has no top border. */
$section_is_first = true;
?>

<?php if ($total_events === 0): ?>

    <!-- ══ NO EVENTS AT ALL — single empty page state ══ -->
    <div class="empty-page">
        <div class="ep-title">No events yet</div>
        <p class="ep-text">Events created in this pod will show up here. Be the first to put one on the calendar.</p>
        <button type="button" class="ep-btn" onclick="openModal('modal-create-event')">Create Event</button>
    </div>

<?php else: ?>

    <?php if (!empty($future_events)): ?>
    <!-- ══ FUTURE EVENTS ══ -->
    <div class="section-label<?php echo $section_is_first ? ' first' : ''; ?>"><i class="ri-calendar-line"></i> Future Events <span class="count-badge"><?php echo count($future_events); ?></span></div>
    <div class="events-list">
        <?php foreach ($future_events as $ev) render_event_row($ev, 'future', $current_user, $is_owner, $is_admin, $csrf_token, $my_rsvp, $group_id, $comments_by_event, $my_comment_likes, $is_member, $discuss_open_id); ?>
    </div>
    <?php $section_is_first = false; endif; ?>

    <?php if (!empty($current_events)): ?>
    <!-- ══ CURRENT EVENTS ══ -->
    <div class="section-label<?php echo $section_is_first ? ' first' : ''; ?>"><i class="ri-flashlight-line"></i> Current Events <span class="count-badge"><?php echo count($current_events); ?></span></div>
    <div class="events-list">
        <?php foreach ($current_events as $ev) render_event_row($ev, 'current', $current_user, $is_owner, $is_admin, $csrf_token, $my_rsvp, $group_id, $comments_by_event, $my_comment_likes, $is_member, $discuss_open_id); ?>
    </div>
    <?php $section_is_first = false; endif; ?>

    <?php if (!empty($past_events)): ?>
    <!-- ══ PAST EVENTS ══ -->
    <div class="section-label<?php echo $section_is_first ? ' first' : ''; ?>"><i class="ri-history-line"></i> Past Events <span class="count-badge"><?php echo count($past_events); ?></span></div>
    <div class="events-list">
        <?php foreach ($past_events as $ev) render_event_row($ev, 'past', $current_user, $is_owner, $is_admin, $csrf_token, $my_rsvp, $group_id, $comments_by_event, $my_comment_likes, $is_member, $discuss_open_id); ?>
    </div>
    <?php $section_is_first = false; endif; ?>

<?php endif; ?>

</div><!-- /.ac-lane -->

<!-- ══ CREATE EVENT MODAL (full screen) ══ -->
<div class="modal-overlay" id="modal-create-event">
  <div class="modal-lane">
    <div class="modal-topbar">
        <button type="button" class="ac-x" onclick="closeModal('modal-create-event')" aria-label="Close">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
        <span class="modal-title">Create Event</span>
    </div>
    <div class="modal-body">
        <form method="POST" action="group_events.php?id=<?php echo $group_id; ?>" enctype="multipart/form-data">
            <div class="field-block">
                <div class="field-label"><i class="ri-image-2-line"></i> Event Picture</div>
                <div class="preview-box" id="img-preview-box">
                    <div class="preview-cover"><?php echo ge_geo_art(7, 440, 100, 'pv'); ?></div>
                    <span class="preview-empty"><i class="ri-image-add-line"></i> No picture &mdash; a designed cover will be used</span>
                </div>
                <label class="file-label"><i class="ri-upload-cloud-line"></i> <span>Choose Picture</span>
                    <input type="file" name="event_image" id="event_image_input" accept="image/*" onchange="previewEventImage(this)">
                </label>
            </div>

            <div class="field-block">
                <div class="field-label"><i class="ri-text"></i> Event Name</div>
                <input class="gc-input" type="text" name="event_name" placeholder="e.g. Weekend Community Meetup" maxlength="150" required>
            </div>

            <div class="field-block">
                <div class="field-label"><i class="ri-calendar-line"></i> Date &amp; Time</div>
                <div class="date-time-row">
                    <input class="gc-input" type="date" name="event_date" required min="<?php echo date('Y-m-d'); ?>">
                    <input class="gc-input" type="time" name="event_time" required>
                </div>
            </div>

            <div class="field-block">
                <div class="field-label"><i class="ri-map-pin-line"></i> Meetup Type</div>
                <div class="loc-toggle">
                    <label class="loc-opt selected" id="loc-physical-label">
                        <input type="radio" name="location_type" value="physical" checked onchange="setLocType(this)">
                        <i class="ri-map-pin-line"></i> Physical
                    </label>
                    <label class="loc-opt" id="loc-virtual-label">
                        <input type="radio" name="location_type" value="virtual" onchange="setLocType(this)">
                        <i class="ri-live-line"></i> Virtual
                    </label>
                </div>
            </div>

            <div class="field-block" id="location-field-block">

                <div id="location-physical-wrap">
                    <div class="field-label"><i class="ri-map-pin-2-line"></i> Location</div>
                    <div class="location-search-wrap">
                        <input class="location-search-input" type="text" id="location_search_input"
                               placeholder="Search a venue, street, city, state or country..."
                               autocomplete="off">
                        <div class="location-search-spinner" id="location_search_spinner"></div>
                        <div class="location-suggestions" id="location_suggestions"></div>
                    </div>
                    <div class="location-picked" id="location_picked">
                        <i class="ri-map-pin-fill pin"></i>
                        <span id="location_picked_text"></span>
                        <button type="button" onclick="clearPickedLocation()" aria-label="Clear location"><i class="ri-close-line"></i></button>
                    </div>
                    <div class="location-hint">Start typing to search anywhere in the world, then tap a result to pick it.</div>
                </div>

                <div id="location-virtual-wrap" style="display:none;">
                    <div class="field-label"><i class="ri-live-line"></i> Meeting Link / Details</div>
                    <input class="gc-input" type="text" id="location_virtual_input" placeholder="e.g. Zoom / Google Meet link" maxlength="255">
                </div>

                <input type="hidden" name="location_text" id="location_text_hidden">
            </div>

            <div class="field-block">
                <div class="field-label"><i class="ri-eye-line"></i> Who Can See This</div>
                <div class="loc-toggle">
                    <label class="loc-opt vis-opt selected" data-vis="private" id="vis-private-label">
                        <input type="radio" name="visibility" value="private" checked onchange="setVisType(this)">
                        <i class="ri-lock-line"></i> Private (Members)
                    </label>
                    <label class="loc-opt vis-opt" data-vis="public" id="vis-public-label">
                        <input type="radio" name="visibility" value="public" onchange="setVisType(this)">
                        <i class="ri-global-line"></i> Public
                    </label>
                </div>
            </div>

            <div class="field-block">
                <div class="field-label"><i class="ri-file-text-line"></i> Summary</div>
                <textarea class="gc-textarea" name="summary" placeholder="What's this event about?" required></textarea>
            </div>

            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <button class="gc-btn" type="submit" name="create_event"><i class="ri-send-plane-line"></i> Post Event</button>
        </form>
    </div>
  </div>
</div>

<!-- ══ COMMENT IMAGE LIGHTBOX ══ -->
<div class="comment-image-lightbox" id="commentImageLightbox" onclick="closeCommentLightbox()">
    <button type="button" class="comment-lightbox-close" onclick="event.stopPropagation(); closeCommentLightbox()" aria-label="Close"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 5L19 19M19 5L5 19" stroke="currentColor" stroke-width="4" stroke-linecap="square"/></svg></button>
    <img id="commentLightboxImg" src="" alt="Attached image" onclick="event.stopPropagation()">
</div>

<script>
var autoOpenModalId = <?php echo $discuss_open_id ? json_encode('event-detail-' . $discuss_open_id) : 'null'; ?>;
var autoOpenEventId = <?php echo $discuss_open_id ? (int)$discuss_open_id : 'null'; ?>;

/* difference between the server clock and this device's clock (ms), so
   "has this event ended?" is judged on SERVER time. */
var geClockOffset = <?php echo time() * 1000; ?> - Date.now();

/* ── X: go back, or to the pod if there is no history ── */
function acClose(){
    if (document.referrer && document.referrer.indexOf(window.location.host) !== -1) history.back();
    else location.href = 'group.php?id=<?php echo $group_id; ?>';
}

/* when an event's picture is missing or broken, swap in the geometric
   cover that was pre-rendered (inside a <template>) next to it. */
function geImgFail(img){
    img.onerror = null;
    var host = img.parentElement;
    if (host) {
        var tpl = host.querySelector('template.geo-tpl');
        if (tpl && tpl.content && tpl.content.firstElementChild) {
            host.insertBefore(tpl.content.firstElementChild.cloneNode(true), img);
        }
    }
    img.remove();
}

function openModal(id){
    var m = document.getElementById(id);
    if(!m) return;
    m.classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeModal(id){
    var m = document.getElementById(id);
    if(!m) return;
    m.classList.remove('active');
    document.body.style.overflow = '';
}

/* ── Event detail modal: open + run the skeleton-then-reveal sequence
     the first time this particular event is opened. ── */
function openEventModal(modalId, eventId){
    openModal(modalId);
    initDetailScroll(modalId);
    showEventSkeleton(eventId);
}

function showEventSkeleton(eventId){
    var skeleton = document.getElementById('event-skeleton-' + eventId);
    var content = document.getElementById('event-content-' + eventId);
    if (!skeleton || !content) return;

    if (skeleton.dataset.shown) {
        skeleton.style.display = 'none';
        content.style.display = '';
        return;
    }
    skeleton.dataset.shown = '1';
    skeleton.style.display = 'flex';
    content.style.display = 'none';
    setTimeout(function(){
        skeleton.style.display = 'none';
        content.style.display = '';
    }, 750);
}

function initDetailScroll(id){
    var modal = document.getElementById(id);
    if (!modal || modal.dataset.scrollInit) return;
    modal.dataset.scrollInit = '1';

    var hero = modal.querySelector('.detail-hero');
    var bar = modal.querySelector('.detail-stickybar');
    var titleEl = modal.querySelector('.detail-title');
    var titleSpan = modal.querySelector('.stickybar-title');
    if (!hero || !bar) return;

    modal.addEventListener('scroll', function(){
        var heroThreshold = Math.max(hero.offsetHeight - 50, 0);
        bar.classList.toggle('is-scrolled', modal.scrollTop > heroThreshold);

        if (titleEl && titleSpan) {
            var barHeight = bar.offsetHeight || 50;
            var titleRect = titleEl.getBoundingClientRect();
            titleSpan.classList.toggle('show-title', titleRect.bottom <= barHeight);
        }
    }, { passive: true });
}

document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.active').forEach(function(m){
            m.classList.remove('active');
        });
        closeCommentLightbox();
        document.body.style.overflow = '';
    }
});

function previewEventImage(input){
    var box = document.getElementById('img-preview-box');
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e){
            box.innerHTML = '<img src="' + e.target.result + '">';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function setLocType(radio){
    document.getElementById('loc-physical-label').classList.toggle('selected', radio.value === 'physical');
    document.getElementById('loc-virtual-label').classList.toggle('selected', radio.value === 'virtual');

    var physicalWrap = document.getElementById('location-physical-wrap');
    var virtualWrap = document.getElementById('location-virtual-wrap');
    var hidden = document.getElementById('location_text_hidden');

    if (radio.value === 'virtual') {
        physicalWrap.style.display = 'none';
        virtualWrap.style.display = '';
        hidden.value = document.getElementById('location_virtual_input').value;
    } else {
        physicalWrap.style.display = '';
        virtualWrap.style.display = 'none';
        hidden.value = document.getElementById('location_picked_text').textContent || '';
    }
}

function setVisType(radio){
    document.getElementById('vis-private-label').classList.toggle('selected', radio.value === 'private');
    document.getElementById('vis-public-label').classList.toggle('selected', radio.value === 'public');
}

/* ── worldwide location search using OpenStreetMap's free Nominatim API. ── */
(function(){
    var searchInput = document.getElementById('location_search_input');
    var suggestionsBox = document.getElementById('location_suggestions');
    var spinner = document.getElementById('location_search_spinner');
    var pickedBox = document.getElementById('location_picked');
    var pickedText = document.getElementById('location_picked_text');
    var hiddenField = document.getElementById('location_text_hidden');
    var virtualInput = document.getElementById('location_virtual_input');
    if (!searchInput) return;

    var debounceTimer = null;
    var currentRequestId = 0;

    function buildDisplayName(place){
        return place.display_name;
    }

    function renderSuggestions(items){
        suggestionsBox.innerHTML = '';
        if (!items || items.length === 0) {
            suggestionsBox.innerHTML = '<div class="location-suggestion-empty">No matching places found</div>';
            suggestionsBox.classList.add('active');
            return;
        }
        items.forEach(function(place){
            var row = document.createElement('div');
            row.className = 'location-suggestion-item';
            row.innerHTML = '<i class="ri-map-pin-2-fill"></i><span></span>';
            row.querySelector('span').textContent = buildDisplayName(place);
            row.addEventListener('click', function(){
                pickLocation(buildDisplayName(place));
            });
            suggestionsBox.appendChild(row);
        });
        suggestionsBox.classList.add('active');
    }

    function pickLocation(text){
        pickedText.textContent = text;
        pickedBox.classList.add('active');
        hiddenField.value = text;
        searchInput.value = '';
        suggestionsBox.classList.remove('active');
        suggestionsBox.innerHTML = '';
    }

    window.clearPickedLocation = function(){
        pickedBox.classList.remove('active');
        pickedText.textContent = '';
        hiddenField.value = '';
    };

    searchInput.addEventListener('input', function(){
        var q = searchInput.value.trim();
        clearTimeout(debounceTimer);
        if (q.length < 3) {
            suggestionsBox.classList.remove('active');
            suggestionsBox.innerHTML = '';
            spinner.classList.remove('active');
            return;
        }
        spinner.classList.add('active');
        debounceTimer = setTimeout(function(){
            var reqId = ++currentRequestId;
            var url = 'https://nominatim.openstreetmap.org/search?format=json&addressdetails=1&limit=8&q=' + encodeURIComponent(q);
            fetch(url, { headers: { 'Accept': 'application/json' } })
                .then(function(r){ return r.json(); })
                .then(function(data){
                    if (reqId !== currentRequestId) return;
                    spinner.classList.remove('active');
                    renderSuggestions(data);
                })
                .catch(function(){
                    if (reqId !== currentRequestId) return;
                    spinner.classList.remove('active');
                    suggestionsBox.innerHTML = '<div class="location-suggestion-empty">Search failed — try again</div>';
                    suggestionsBox.classList.add('active');
                });
        }, 450);
    });

    document.addEventListener('click', function(e){
        if (!suggestionsBox.contains(e.target) && e.target !== searchInput) {
            suggestionsBox.classList.remove('active');
        }
    });

    if (virtualInput) {
        virtualInput.addEventListener('input', function(){
            hiddenField.value = virtualInput.value;
        });
    }
})();

/* ── Share an event ── */
function shareEventRow(btn, e){
    if (e) e.stopPropagation();
    var name = btn.getAttribute('data-name') || 'Event';
    var relUrl = btn.getAttribute('data-url') || '';
    var url = relUrl ? new URL(relUrl, window.location.href).href : window.location.href;

    if (navigator.share) {
        navigator.share({ title: name, url: url }).catch(function(){});
    } else if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(function(){
            btn.classList.add('copied');
            setTimeout(function(){ btn.classList.remove('copied'); }, 1200);
        }).catch(function(){});
    }
}

/* ── More / less expand-collapse (event summary AND long comments) ── */
function toggleSummary(btn){
    var wrap = btn.previousElementSibling;
    if (!wrap) return;
    var expanded = btn.getAttribute('data-expanded') === '1';
    if (expanded) {
        wrap.innerHTML = btn.getAttribute('data-short');
        btn.textContent = 'more';
        btn.setAttribute('data-expanded', '0');
    } else {
        wrap.innerHTML = btn.getAttribute('data-full');
        btn.textContent = 'less';
        btn.setAttribute('data-expanded', '1');
    }
}

/* ── Discussions toggle. First time a given event's panel is opened,
     briefly show a skeleton placeholder before revealing the comments. ── */
function toggleDiscuss(eventId){
    var panel = document.getElementById('discuss-panel-' + eventId);
    var btn = document.getElementById('discuss-toggle-' + eventId);
    if (!panel || !btn) return;
    var open = panel.classList.toggle('open');
    btn.classList.toggle('open', open);

    if (open && !panel.dataset.loadedOnce) {
        panel.dataset.loadedOnce = '1';
        var skeleton = document.getElementById('discuss-skeleton-' + eventId);
        var list = document.getElementById('comment-list-' + eventId);
        var footer = panel.querySelector('.comment-form, .discuss-locked');
        if (skeleton) {
            skeleton.classList.add('active');
            if (list) list.style.display = 'none';
            if (footer) footer.style.display = 'none';
            setTimeout(function(){
                skeleton.classList.remove('active');
                if (list) list.style.display = '';
                if (footer) footer.style.display = '';
            }, 600);
        }
    }
}

/* ══════════════════════════════════════════════════════════════
   comment image attachments — preview, remove, sync to the file
   input (via DataTransfer), up to 4 images.
   ══════════════════════════════════════════════════════════════ */
var commentFilesMap = {};

function previewCommentMedia(input, eventId){
    var files = Array.from(input.files || []).slice(0, 4);
    commentFilesMap[eventId] = files;
    syncCommentFileInput(eventId, input);
    renderCommentMediaPreview(eventId);
}

function renderCommentMediaPreview(eventId){
    var preview = document.getElementById('comment-media-preview-' + eventId);
    if (!preview) return;
    var files = commentFilesMap[eventId] || [];
    preview.innerHTML = '';
    files.forEach(function(file, idx){
        var reader = new FileReader();
        reader.onload = function(e){
            var item = document.createElement('div');
            item.className = 'comment-media-preview-item';
            item.innerHTML = '<img src="' + e.target.result + '">' +
                '<button type="button" onclick="removeCommentMediaItem(' + eventId + ',' + idx + ')" aria-label="Remove image"><i class="ri-close-line"></i></button>';
            preview.appendChild(item);
        };
        reader.readAsDataURL(file);
    });
}

function removeCommentMediaItem(eventId, idx){
    var files = commentFilesMap[eventId] || [];
    files.splice(idx, 1);
    commentFilesMap[eventId] = files;
    var input = document.querySelector('input[name="comment_images[]"][data-event-id="' + eventId + '"]');
    syncCommentFileInput(eventId, input);
    renderCommentMediaPreview(eventId);
}

function syncCommentFileInput(eventId, input){
    if (!input || typeof DataTransfer === 'undefined') return;
    var dt = new DataTransfer();
    (commentFilesMap[eventId] || []).forEach(function(f){ dt.items.add(f); });
    input.files = dt.files;
}

/* ── Comment image lightbox ── */
function openCommentLightbox(src){
    var box = document.getElementById('commentImageLightbox');
    var img = document.getElementById('commentLightboxImg');
    if (!box || !img) return;
    img.src = src;
    box.classList.add('active');
}
function closeCommentLightbox(){
    var box = document.getElementById('commentImageLightbox');
    if (box) box.classList.remove('active');
}

/* ── AJAX form handling — RSVP, comments, likes all happen without a page reload. ── */
function geEscapeHtml(str){
    var div = document.createElement('div');
    div.textContent = str == null ? '' : str;
    return div.innerHTML;
}

function buildCommentMediaHtml(mediaArr){
    if (!mediaArr || !mediaArr.length) return '';
    var itemsHtml = mediaArr.map(function(src){
        var safeSrc = geEscapeHtml(src);
        return '<div class="comment-media-item" onclick="openCommentLightbox(\'' + safeSrc.replace(/'/g, "&#39;") + '\')"><img src="' + safeSrc + '" loading="lazy"></div>';
    }).join('');
    return '<div class="comment-media-row" data-count="' + mediaArr.length + '">' + itemsHtml + '</div>';
}

/* fills a freshly-posted comment's text block, adding the blue
   "more / less" button when the comment is long (matches PHP). */
function fillCommentText(el, text){
    var LIMIT = 180;
    var fullHtml = geEscapeHtml(text).replace(/\n/g, '<br>');
    if (text.length <= LIMIT) {
        el.innerHTML = '<span class="comment-body">' + fullHtml + '</span>';
        return;
    }
    var shortHtml = geEscapeHtml(text.substring(0, LIMIT)).replace(/\n/g, '<br>') + '&hellip;';
    el.innerHTML = '<span class="comment-body">' + shortHtml + '</span>';
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'more-toggle';
    btn.setAttribute('data-expanded', '0');
    btn.setAttribute('data-short', shortHtml);
    btn.setAttribute('data-full', fullHtml);
    btn.textContent = 'more';
    btn.addEventListener('click', function(){ toggleSummary(btn); });
    el.appendChild(btn);
}

document.addEventListener('submit', function(e){
    var form = e.target;
    if (!form.classList || !form.classList.contains('ajax-form')) return;
    e.preventDefault();

    var kind = form.getAttribute('data-kind');

    /* the delete confirmation is checked here, BEFORE sending, so
       pressing Cancel never deletes the comment. */
    if (kind === 'delete-comment') {
        if (!confirm('Delete this comment?')) return;
    }

    if (kind === 'comment') {
        var textarea = form.querySelector('textarea[name="comment_text"]');
        var fileInput = form.querySelector('input[name="comment_images[]"]');
        var hasText = textarea && textarea.value.trim() !== '';
        var hasFiles = fileInput && fileInput.files && fileInput.files.length > 0;
        if (!hasText && !hasFiles) {
            if (textarea) textarea.focus();
            return;
        }
    }

    var submitBtn = e.submitter || form.querySelector('button[type="submit"]');

    var formData = new FormData(form);
    if (submitBtn && submitBtn.name) {
        formData.append(submitBtn.name, submitBtn.value || '1');
    }

    if (submitBtn) submitBtn.disabled = true;

    fetch(form.getAttribute('action'), {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        if (submitBtn) submitBtn.disabled = false;

        /* the server says this event has ended — reload so it shows up in Past Events. */
        if (data && data.ended) {
            geReloadPage();
            return;
        }

        if (!data || !data.success) return;
        if (kind === 'rsvp') applyRsvpUpdate(data);
        else if (kind === 'like') applyLikeUpdate(data);
        else if (kind === 'comment') applyNewComment(data, form);
        else if (kind === 'delete-comment') applyDeleteComment(data);
    })
    .catch(function(){
        if (submitBtn) submitBtn.disabled = false;
    });
}, true);

function applyRsvpUpdate(data){
    var id = data.event_id;

    var evInt = document.getElementById('ev-int-' + id);
    var evGoing = document.getElementById('ev-going-' + id);
    if (evInt) {
        evInt.classList.toggle('active', data.interested_active);
        evInt.querySelector('i').className = 'ri-star-' + (data.interested_active ? 'fill' : 'line');
    }
    if (evGoing) {
        evGoing.classList.toggle('active', data.going_active);
        evGoing.querySelector('i').className = 'ri-check-' + (data.going_active ? 'double-' : '') + 'line';
    }
    var evCounts = document.getElementById('ev-counts-' + id);
    if (evCounts) {
        evCounts.innerHTML = data.interested_count + ' interested \u00b7 ' + data.going_count + ' going';
    }

    var detInt = document.getElementById('detail-int-' + id);
    var detGoing = document.getElementById('detail-going-' + id);
    if (detInt) {
        detInt.classList.toggle('active', data.interested_active);
        detInt.querySelector('i').className = 'ri-star-' + (data.interested_active ? 'fill' : 'line');
        var c1 = detInt.querySelector('.rsvp-count');
        if (c1) c1.textContent = data.interested_count;
    }
    if (detGoing) {
        detGoing.classList.toggle('active', data.going_active);
        detGoing.querySelector('i').className = 'ri-check-' + (data.going_active ? 'double-' : '') + 'line';
        var c2 = detGoing.querySelector('.rsvp-count');
        if (c2) c2.textContent = data.going_count;
    }
    var meta = document.getElementById('meta-counts-' + id);
    if (meta) {
        meta.textContent = data.interested_count + ' interested \u00b7 ' + data.going_count + ' going';
    }
}

function applyLikeUpdate(data){
    var btn = document.getElementById('comment-like-' + data.comment_id);
    if (!btn) return;
    btn.classList.toggle('liked', data.liked);
    var icon = btn.querySelector('i');
    if (icon) icon.className = 'ri-thumb-up-' + (data.liked ? 'fill' : 'line');
    var countEl = btn.querySelector('.like-count');
    if (countEl) countEl.textContent = data.like_count;
}

function applyDeleteComment(data){
    if (!data.success) return;
    var item = document.querySelector('.comment-item[data-comment-id="' + data.comment_id + '"]');
    if (item) item.remove();
    var countBadge = document.getElementById('discuss-count-' + data.event_id);
    if (countBadge) countBadge.textContent = data.comment_count;
    if (data.comment_count === 0) {
        var noComments = document.getElementById('no-comments-' + data.event_id);
        if (noComments) noComments.style.display = '';
    }
}

function applyNewComment(data, form){
    var id = data.event_id;
    var list = document.getElementById('comment-list-' + id);
    var noComments = document.getElementById('no-comments-' + id);
    if (noComments) noComments.style.display = 'none';

    if (list) {
        var avatarInner = data.profile_pic
            ? '<img src="' + geEscapeHtml(data.profile_pic) + '" onerror="this.parentElement.innerHTML=\'<i class=&quot;ri-user-3-line&quot;></i>\'">'
            : '<i class="ri-user-3-line"></i>';

        var hasText = data.comment && data.comment.trim() !== '';
        var textHtml = hasText ? '<div class="comment-text"></div>' : '';
        var mediaHtml = buildCommentMediaHtml(data.media);

        var item = document.createElement('div');
        item.className = 'comment-item';
        item.setAttribute('data-comment-id', data.comment_id);
        item.innerHTML =
            '<div class="comment-item-top">' +
                '<div class="comment-avatar">' + avatarInner + '</div>' +
                '<div class="comment-author-meta">' +
                    '<div class="comment-username">' + geEscapeHtml(data.username) + '</div>' +
                    '<div class="comment-time-inline"><i class="ri-earth-line"></i> ' + geEscapeHtml(data.time) + '</div>' +
                '</div>' +
            '</div>' +
            textHtml +
            mediaHtml +
            '<div class="comment-meta">' +
                '<form class="ajax-form" data-kind="like" method="POST" action="group_events.php?id=<?php echo $group_id; ?>">' +
                    '<input type="hidden" name="comment_id" value="' + data.comment_id + '">' +
                    '<input type="hidden" name="event_id" value="' + id + '">' +
                    '<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">' +
                    '<button type="submit" name="toggle_comment_like" value="1" id="comment-like-' + data.comment_id + '" class="comment-like-btn">' +
                        '<i class="ri-thumb-up-line"></i> <span class="like-count">0</span>' +
                    '</button>' +
                '</form>' +
                '<form class="ajax-form" data-kind="delete-comment" method="POST" action="group_events.php?id=<?php echo $group_id; ?>">' +
                    '<input type="hidden" name="comment_id" value="' + data.comment_id + '">' +
                    '<input type="hidden" name="event_id" value="' + id + '">' +
                    '<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">' +
                    '<button type="submit" name="delete_comment" value="1" class="comment-delete-btn" title="Delete comment"><i class="ri-delete-bin-line"></i></button>' +
                '</form>' +
            '</div>' +
            '<div class="comment-hr"></div>';

        if (hasText) {
            var textEl = item.querySelector('.comment-text');
            if (textEl) fillCommentText(textEl, data.comment.trim());
        }
        list.appendChild(item);
    }

    var countBadge = document.getElementById('discuss-count-' + id);
    if (countBadge) countBadge.textContent = data.comment_count;

    if (form) {
        var ta = form.querySelector('textarea');
        if (ta) ta.value = '';
        var fileInput = form.querySelector('input[name="comment_images[]"]');
        if (fileInput) fileInput.value = '';
        commentFilesMap[id] = [];
        var preview = document.getElementById('comment-media-preview-' + id);
        if (preview) preview.innerHTML = '';
    }
}

/* ══════════════════════════════════════════════════════════════
   LIVE "EVENT HAS ENDED" WATCHER
   Every not-yet-past event row carries data-end-ts (unix seconds).
   When the server-corrected clock passes it, the page reloads so the
   server re-buckets the event into Past Events. It never reloads while
   a modal is open, so nobody loses a half-typed comment.
   ══════════════════════════════════════════════════════════════ */
function geReloadPage(){
    try {
        var last = parseInt(sessionStorage.getItem('geLastAutoReload') || '0', 10);
        if (Date.now() - last < 10000) return;
        sessionStorage.setItem('geLastAutoReload', String(Date.now()));
    } catch (err) {}
    window.location.href = 'group_events.php?id=<?php echo $group_id; ?>';
}

(function(){
    var CHECK_EVERY_MS = 15000;
    var SAFETY_MARGIN_MS = 2000;

    function serverNow(){ return Date.now() + geClockOffset; }

    function anyModalOpen(){
        return document.querySelector('.modal-overlay.active') !== null;
    }

    function hasEndedRow(){
        var rows = document.querySelectorAll('.event-row[data-end-ts]');
        var now = serverNow();
        for (var i = 0; i < rows.length; i++) {
            var endMs = parseInt(rows[i].getAttribute('data-end-ts'), 10) * 1000;
            if (endMs && endMs + SAFETY_MARGIN_MS <= now) return true;
        }
        return false;
    }

    function check(){
        if (!hasEndedRow()) return;
        if (anyModalOpen()) return;
        geReloadPage();
    }

    setInterval(check, CHECK_EVERY_MS);
    document.addEventListener('visibilitychange', function(){
        if (!document.hidden) check();
    });
})();

/* ── Page skeleton dismissal ── */
(function(){
    var sk = document.getElementById('page-skeleton');
    if(!sk) return;
    var done = false;
    function hide(){
        if(done) return; done = true;
        sk.classList.add('hide');
        setTimeout(function(){ if(sk.parentNode) sk.remove(); }, 380);
    }
    window.addEventListener('load', function(){ setTimeout(hide, 280); });
    setTimeout(hide, 1500);   // safety net
})();

/* ── Auto-open a shared event / a discussion that was just posted to ── */
(function(){
    var hash = window.location.hash;
    var targetId = null;
    if (autoOpenModalId) {
        targetId = autoOpenModalId;
    } else if (hash && hash.indexOf('#event-detail-') === 0) {
        targetId = hash.substring(1);
    }
    if (targetId && document.getElementById(targetId)) {
        var m = targetId.match(/^event-detail-(\d+)$/);
        var parsedEventId = m ? parseInt(m[1], 10) : null;
        openEventModal(targetId, parsedEventId);
        if (autoOpenEventId) {
            var panel = document.getElementById('discuss-panel-' + autoOpenEventId);
            var btn = document.getElementById('discuss-toggle-' + autoOpenEventId);
            if (panel && btn) {
                panel.classList.add('open');
                btn.classList.add('open');
            }
            setTimeout(function(){
                var commentEl = document.getElementById('discuss-panel-' + autoOpenEventId);
                if (commentEl) commentEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }, 800);
        }
    }
})();
</script>

</body>
</html>