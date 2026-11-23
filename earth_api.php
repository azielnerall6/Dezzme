<?php
// earth_api.php — data for Earth.php (Dezzmers map + tap a country). Only people who opted in are ever shown, and only by country.
session_start();
include 'config.php';
include_once 'earth_countries.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ── CONFIG — change these if your pages are named differently ──
$ZEEM_URL    = 'meme.php?id=';        // page that opens one Zeem
$POD_URL     = 'group.php?id=';       // page that opens one Pod
$PROFILE_URL = 'profile.php?user_id=';

function out($d){ echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

$uid = (int)($_SESSION['user_id'] ?? 0);
if (!$uid) { http_response_code(401); out(['error' => 'Please log in.']); }

// ── small helpers: look at the real table columns so a different column name does not break the page ──
function has_table($conn, $t){
    $r = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($t) . "'");
    return $r && $r->num_rows > 0;
}
function cols($conn, $t){
    static $cache = [];
    if (!isset($cache[$t])) {
        $cache[$t] = [];
        $r = $conn->query("SHOW COLUMNS FROM `" . str_replace('`', '', $t) . "`");
        if ($r) while ($x = $r->fetch_assoc()) $cache[$t][] = $x['Field'];
    }
    return $cache[$t];
}
function pick($conn, $t, $cands){
    $have = cols($conn, $t);
    foreach ($cands as $c) if (in_array($c, $have, true)) return $c;
    return null;
}
function fetch_all($st){
    $st->execute();
    $res = $st->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    return $rows;
}

try {
    $action = $_GET['action'] ?? '';

    // setup check: user_about needs country_code + show_on_globe (see the SQL)
    $ua = cols($conn, 'user_about');
    if (!in_array('country_code', $ua, true) || !in_array('show_on_globe', $ua, true)) out(['setup' => false]);

    // ── all countries that have Dezzmers on the globe ──
    if ($action === 'summary') {
        $res = $conn->query("SELECT country_code AS c, COUNT(*) AS n FROM user_about WHERE show_on_globe=1 AND country_code IS NOT NULL AND country_code<>'' GROUP BY country_code ORDER BY n DESC");
        $list = [];
        while ($r = $res->fetch_assoc()) {
            if (isset($EARTH_COUNTRIES[$r['c']])) $list[] = ['c' => $r['c'], 'n' => (int)$r['n']];
        }
        out(['countries' => $list]);
    }

    // ── this person's own setting ──
    if ($action === 'me') {
        $st = $conn->prepare("SELECT country_code, show_on_globe FROM user_about WHERE user_id=? LIMIT 1");
        $st->bind_param('i', $uid);
        $rows = fetch_all($st);
        $r = $rows[0] ?? [];
        out(['country' => $r['country_code'] ?? '', 'show' => (int)($r['show_on_globe'] ?? 0)]);
    }

    // ── save this person's setting ──
    if ($action === 'save') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals((string)($_SESSION['earth_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
            out(['error' => 'Please reload the page and try again.']);
        }
        $cc   = strtoupper(trim((string)($_POST['country'] ?? '')));
        $show = !empty($_POST['show']) ? 1 : 0;
        if ($cc !== '' && !isset($EARTH_COUNTRIES[$cc])) out(['error' => 'Unknown country.']);
        if ($show && $cc === '') out(['error' => 'Pick your country first.']);
        $val = ($cc === '') ? null : $cc;

        $st = $conn->prepare("SELECT 1 FROM user_about WHERE user_id=? LIMIT 1");
        $st->bind_param('i', $uid);
        $exists = count(fetch_all($st)) > 0;

        if ($exists) {
            $st = $conn->prepare("UPDATE user_about SET country_code=?, show_on_globe=? WHERE user_id=?");
            $st->bind_param('sii', $val, $show, $uid);
        } else {
            $st = $conn->prepare("INSERT INTO user_about (user_id, country_code, show_on_globe) VALUES (?,?,?)");
            $st->bind_param('isi', $uid, $val, $show);
        }
        $st->execute();
        out(['ok' => true]);
    }

    // ── one country: Zeems, Pods, newest Dezzmers ──
    if ($action === 'country') {
        $code = strtoupper(trim((string)($_GET['code'] ?? '')));
        if (!isset($EARTH_COUNTRIES[$code])) out(['error' => 'Unknown country.']);

        // skip private accounts if the users table has such a column
        $pc = pick($conn, 'users', ['is_private', 'private', 'private_account']);
        $privSql = $pc ? " AND (u.`$pc` IS NULL OR u.`$pc`=0)" : '';

        // newest Dezzmers
        $st = $conn->prepare("SELECT u.id, u.username, u.profile_pic, u.created_at FROM user_about a JOIN users u ON u.id=a.user_id WHERE a.show_on_globe=1 AND a.country_code=?$privSql ORDER BY u.created_at DESC LIMIT 12");
        $st->bind_param('s', $code);
        $dezzmers = [];
        foreach (fetch_all($st) as $r) {
            $dezzmers[] = [
                'name'   => $r['username'],
                'pic'    => $r['profile_pic'] ?: 'uploads/me.png',
                'joined' => $r['created_at'] ? date('M Y', strtotime($r['created_at'])) : '',
                'href'   => $PROFILE_URL . (int)$r['id'],
            ];
        }

        // top Zeems
        $media = pick($conn, 'memes', ['thumbnail', 'cover', 'cover_photo', 'cover_image', 'image', 'image_path', 'file_path', 'file', 'media', 'media_path', 'filename']);
        $cap   = pick($conn, 'memes', ['caption', 'title', 'description', 'text']);
        $lk    = pick($conn, 'memes', ['likes', 'likes_count', 'like_count']);
        $sel = 'm.id'
             . ($media ? ", m.`$media` AS media" : ", '' AS media")
             . ($cap   ? ", m.`$cap` AS cap"     : ", '' AS cap")
             . ($lk    ? ", m.`$lk` AS likes"    : ", NULL AS likes");
        $ord = $lk ? "m.`$lk` DESC, m.id DESC" : "m.id DESC";
        $st = $conn->prepare("SELECT $sel FROM memes m JOIN user_about a ON a.user_id=m.uploaded_by JOIN users u ON u.id=m.uploaded_by WHERE a.show_on_globe=1 AND a.country_code=?$privSql ORDER BY $ord LIMIT 12");
        $st->bind_param('s', $code);
        $zeems = [];
        foreach (fetch_all($st) as $r) {
            $img = (string)($r['media'] ?? '');
            if ($img !== '' && strpos($img, '/') === false && stripos($img, 'http') !== 0) $img = 'uploads/' . $img;
            $zeems[] = [
                'href'    => $ZEEM_URL . (int)$r['id'],
                'img'     => $img,
                'caption' => mb_substr((string)($r['cap'] ?? ''), 0, 80),
                'likes'   => $r['likes'] === null ? null : (int)$r['likes'],
            ];
        }

        // Pods started by Dezzmers in this country (null = pods table not recognised)
        $pods = null;
        $pt = null;
        foreach (['groups', 'pods', 'dezzme_groups', 'dz_groups'] as $t) { if (has_table($conn, $t)) { $pt = $t; break; } }
        if ($pt) {
            $nameC = pick($conn, $pt, ['name', 'group_name', 'pod_name', 'title']);
            $ownC  = pick($conn, $pt, ['created_by', 'owner_id', 'creator_id', 'user_id']);
            if ($nameC && $ownC) {
                $mt = null; $fk = null;
                foreach (['group_members', 'pod_members', 'members'] as $t) { if (has_table($conn, $t)) { $mt = $t; $fk = pick($conn, $t, ['group_id', 'pod_id']); break; } }
                $cnt = ($mt && $fk) ? "(SELECT COUNT(*) FROM `$mt` mm WHERE mm.`$fk`=g.id)" : "0";
                $st = $conn->prepare("SELECT g.id, g.`$nameC` AS name, $cnt AS members FROM `$pt` g JOIN user_about a ON a.user_id=g.`$ownC` WHERE a.show_on_globe=1 AND a.country_code=? ORDER BY members DESC, g.id DESC LIMIT 8");
                $st->bind_param('s', $code);
                $pods = [];
                foreach (fetch_all($st) as $r) {
                    $pods[] = ['name' => $r['name'], 'members' => (int)$r['members'], 'href' => $POD_URL . (int)$r['id']];
                }
            }
        }

        out(['code' => $code, 'name' => $EARTH_COUNTRIES[$code]['n'], 'dezzmers' => $dezzmers, 'zeems' => $zeems, 'pods' => $pods]);
    }

    out(['error' => 'Unknown action.']);
} catch (Throwable $e) {
    out(['error' => 'Something went wrong.']);
}