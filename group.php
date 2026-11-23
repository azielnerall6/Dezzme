<?php
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($_GET['type'] ?? '') === 'ping') { header('Cache-Control: no-store'); http_response_code(204); exit; }   /* connection check used by offline.php: answers instantly, runs nothing else */
/* ════════════════════════════════════════════════════════════════
   group.php — COMPLETE PAGE (one file)

   FILE ORDER
     1. CSRF protection + hardened session cookie (runs FIRST on every request)
     2. Page logic (all your actions / AJAX endpoints / queries) — join questions included
     3. Page prelude + HTML / CSS / JavaScript

   CSRF: every POST / PUT / PATCH / DELETE must carry the session token
   (form field "csrf_token" or header "X-CSRF-Token") or it is rejected with 403.
   The page adds the token to every form, fetch() and XMLHttpRequest automatically.
   ════════════════════════════════════════════════════════════════ */

if (session_status() === PHP_SESSION_NONE) {
    $gp_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'secure' => $gp_https,
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_start();
}

if (!function_exists('gp_csrf_token')) {
    function gp_csrf_token() {
        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}
if (!function_exists('gp_csrf_is_ajax')) {
    function gp_csrf_is_ajax() {
        $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
        $xhr    = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
        return $xhr === 'xmlhttprequest'
            || strpos($accept, 'application/json') !== false
            || isset($_POST['ajax_like']) || isset($_POST['ajax_repost']) || isset($_POST['ajax_favorite'])
            || isset($_POST['dismiss_suggested_group']) || isset($_POST['toggle_save'])
            || isset($_POST['mute_group']) || isset($_POST['notif_group']) || isset($_POST['report_to_admins'])
            || isset($_POST['ajax_get_connected_meme']) || isset($_GET['gsearch_recents'])
            || (isset($_POST['action']) && is_string($_POST['action']) && $_POST['action'] !== '');
    }
}
if (!function_exists('gp_csrf_fail')) {
    function gp_csrf_fail($why = 'Invalid or missing security token.') {
        http_response_code(403);
        if (gp_csrf_is_ajax()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'error' => $why . ' Please refresh the page and try again.']);
        } else {
            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
               . '<body style="background:#070924;color:#fff;font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;text-align:center;padding:20px">'
               . '<div><h2 style="margin:0 0 8px">Security check failed</h2>'
               . '<p style="color:rgba(255,255,255,.6);margin:0 0 16px">' . htmlspecialchars($why) . ' Please go back, refresh the page and try again.</p>'
               . '<a href="javascript:history.back()" style="color:#5b8fe6">Go back</a></div></body>';
        }
        exit;
    }
}
if (!function_exists('gp_csrf_verify')) {
    function gp_csrf_verify() {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) return;

        /* same-site check: Origin first, Referer as fallback */
        $host   = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
        $source = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
        if ($source !== '' && $source !== 'null') {
            $src_host = strtolower((string)parse_url($source, PHP_URL_HOST));
            if ($src_host !== '' && $host !== '' && $src_host !== $host) gp_csrf_fail('Cross-site request blocked.');
        }

        /* token check */
        $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!is_string($sent) || $sent === '' || !hash_equals(gp_csrf_token(), $sent)) gp_csrf_fail();
    }
}
gp_csrf_verify();                 /* ← blocks forged requests before any code below can act on them */
$csrf_token = gp_csrf_token();    /* used by the page's forms + JavaScript */


/* ════════════ 2. PAGE LOGIC ════════════ */
include 'config.php';
if (is_file(__DIR__ . '/dz_thumb.php')) require_once __DIR__ . '/dz_thumb.php';   /* small WebP copies for grids / avatars */

/* ══════════════════ POLLS (read for the feed + vote). Creating a poll lives in the upload page. ══════════════════
   Tables create themselves the first time (CREATE TABLE IF NOT EXISTS, checked once per login session). */
if(!function_exists('dz_poll_ensure')){
    function dz_poll_ensure($conn){
        if(!empty($_SESSION['dz_poll_schema_v1'])) return;
        try{
            $conn->query("CREATE TABLE IF NOT EXISTS polls (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, meme_id INT NOT NULL, ends_at DATETIME NULL DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_polls_meme (meme_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $conn->query("CREATE TABLE IF NOT EXISTS poll_options (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, poll_id INT UNSIGNED NOT NULL, position TINYINT UNSIGNED NOT NULL, label VARCHAR(60) NOT NULL, KEY k_opt_poll (poll_id, position)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $conn->query("CREATE TABLE IF NOT EXISTS poll_votes (poll_id INT UNSIGNED NOT NULL, user_id INT NOT NULL, option_id INT UNSIGNED NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (poll_id, user_id), KEY k_vote_option (option_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            if(session_status() === PHP_SESSION_ACTIVE) $_SESSION['dz_poll_schema_v1'] = 1;
        }catch(Throwable $e){ error_log('[dz poll] schema: '.$e->getMessage()); }
    }
}
/* Polls for a list of posts, keyed by post id. Results (votes per choice) are only sent to someone who already voted, the poll's owner, or once it is closed. */
if(!function_exists('dz_polls_for_memes')){
    function dz_polls_for_memes($conn, $uid, array $ids){
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function($v){ return $v > 0; })));
        if(!$ids) return [];
        $uid = (int)$uid; $out = [];
        try{
            $in = implode(',', $ids);
            $res = $conn->query("SELECT p.id, p.meme_id, p.ends_at, m.uploaded_by AS owner,
                                        (p.ends_at IS NOT NULL AND p.ends_at <= NOW()) AS closed,
                                        CASE WHEN p.ends_at IS NULL THEN NULL ELSE GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), p.ends_at)) END AS ends_in
                                 FROM polls p JOIN memes m ON m.id = p.meme_id WHERE p.meme_id IN ($in)");
            if(!$res) return [];
            $polls = [];
            while($r = $res->fetch_assoc()) $polls[(int)$r['id']] = $r;
            if(!$polls) return [];
            $pin = implode(',', array_keys($polls));
            $opts = []; $res = $conn->query("SELECT id, poll_id, label FROM poll_options WHERE poll_id IN ($pin) ORDER BY poll_id, position");
            if($res) while($r = $res->fetch_assoc()) $opts[(int)$r['poll_id']][] = ['id'=>(int)$r['id'], 'label'=>$r['label'], 'votes'=>0];
            $cnt = []; $res = $conn->query("SELECT poll_id, option_id, COUNT(*) AS n FROM poll_votes WHERE poll_id IN ($pin) GROUP BY poll_id, option_id");
            if($res) while($r = $res->fetch_assoc()) $cnt[(int)$r['poll_id']][(int)$r['option_id']] = (int)$r['n'];
            $mine = [];
            if($uid > 0){
                $st = $conn->prepare("SELECT poll_id, option_id FROM poll_votes WHERE user_id = ? AND poll_id IN ($pin)");
                $st->bind_param("i", $uid); $st->execute();
                $rs = $st->get_result(); while($r = $rs->fetch_assoc()) $mine[(int)$r['poll_id']] = (int)$r['option_id'];
                $st->close();
            }
            foreach($polls as $pid => $p){
                $list = $opts[$pid] ?? []; if(count($list) < 2) continue;
                $total = 0; foreach($list as &$o){ $o['votes'] = $cnt[$pid][$o['id']] ?? 0; $total += $o['votes']; } unset($o);
                $closed = !empty($p['closed']);
                $my = $mine[$pid] ?? null;
                $show = $closed || $my !== null || ($uid > 0 && (int)$p['owner'] === $uid);
                if(!$show){ foreach($list as &$o){ $o['votes'] = null; } unset($o); }
                $out[(int)$p['meme_id']] = [
                    'id'=>$pid, 'memeId'=>(int)$p['meme_id'], 'options'=>$list, 'total'=>$total, 'myVote'=>$my,
                    'closed'=>$closed, 'endsIn'=>($p['ends_in'] === null ? null : (int)$p['ends_in']), 'forever'=>($p['ends_at'] === null),
                    'showResults'=>$show,
                ];
            }
        }catch(Throwable $e){ error_log('[dz poll] read failed: '.$e->getMessage()); return []; }
        return $out;
    }
}

/* ── Dezzme Verified badge (same flower badge as history.php / profile_verified.php) ── */
if (!function_exists('dz_vb_flag')) {
    function dz_vb_flag($conn, $uid): bool {
        static $c = [];
        $uid = (int)$uid;
        if ($uid <= 0) return false;
        if (!isset($c[$uid])) {
            $c[$uid] = false;
            try {
                $r = $conn->query("SELECT is_verified FROM users WHERE id = $uid LIMIT 1");
                if ($r instanceof mysqli_result) { $x = $r->fetch_assoc(); $c[$uid] = !empty($x['is_verified']); }
            } catch (Throwable $e) {}
        }
        return $c[$uid];
    }
}
if (!function_exists('dz_sp_sort')) {
    /* social-proof strip order: reposts first (newest first), then likes (newest first), cut to 4 — my own repost takes a slot.
       The rows that did not make the cut are kept as "spares" for the post ($mid): if I un-repost, the person my repost pushed out comes straight back. */
    function dz_sp_sort(array $rows, int $mid = 0): array {
        usort($rows, function ($a, $b) {
            $ra = (($a['action_type'] ?? '') === 'repost') ? 0 : 1;
            $rb = (($b['action_type'] ?? '') === 'repost') ? 0 : 1;
            if ($ra !== $rb) return $ra <=> $rb;
            return strcmp((string)($b['acted_at'] ?? ''), (string)($a['acted_at'] ?? ''));
        });
        if ($mid > 0) $GLOBALS['DZ_SP_SPARE'][$mid] = array_slice($rows, 4, 8);
        return array_slice($rows, 0, 4);
    }
    function dz_sp_spare(int $mid): array {
        $out = [];
        foreach (($GLOBALS['DZ_SP_SPARE'][$mid] ?? []) as $x) {
            $out[] = ['id' => (int)($x['id'] ?? 0), 'username' => (string)($x['username'] ?? ''), 'profile_pic' => (string)($x['profile_pic'] ?? ''),
                      'action_type' => (string)($x['action_type'] ?? 'like'), 'acted_at' => (string)($x['acted_at'] ?? ''),
                      'isVerified' => function_exists('dz_vb_flag') && isset($GLOBALS['conn']) ? dz_vb_flag($GLOBALS['conn'], (int)($x['id'] ?? 0)) : false];
        }
        return $out;
    }
}
if (!function_exists('dz_vb_svg')) {
    function dz_vb_svg(string $cls = 'dz-vb'): string {
        return '<svg class="' . $cls . '" viewBox="0 0 256 256" aria-label="Verified" role="img" focusable="false"><use href="#dz-flower-f"/></svg>';
    }
}
if (isset($_GET['dz_vb_ids']) && isset($_SESSION['user_id'])) {   /* which of these user ids are verified (read-only) */
    header('Content-Type: application/json');
    $__o = [];
    foreach (array_slice(array_unique(array_filter(array_map('intval', explode(',', (string)$_GET['dz_vb_ids'])))), 0, 300) as $__i) {
        if (dz_vb_flag($conn, $__i)) $__o[] = $__i;
    }
    echo json_encode($__o);
    exit;
}
require_once 'offline.php';          /* shared No-internet / Retry state (same as home.php) */

/* server-side gate for tags / mentions (pv_ids, pv_tag_check, pv_text_guard,
   pv_save_tags, pv_save_feature_ok). The upload_post handler below calls it. */
require_once __DIR__ . '/tag_guard.php';

/* join questions (owner sets them in Group Settings; members answer after requesting to join).
   The helper is loaded here so the join / answer / cancel handlers below can use it. */
if (file_exists(__DIR__ . '/join_questions_helper.php')) {
    include_once __DIR__ . '/join_questions_helper.php';
    if (function_exists('jq_ensure_schema') && !file_exists(__DIR__ . '/group_jq_schema.flag')) {
        jq_ensure_schema($conn);
        @file_put_contents(__DIR__ . '/group_jq_schema.flag', date('c'));
    }
}

/* ═════════════════════════════════════════════════════════════
   EXTRAS — small prepared-statement helpers, safe image upload,
   Open Graph share previews, and the schema for the new pod features
   (pinned posts · weekly challenge · catch-me-up). Everything below
   is new; nothing above this line changed.
   ═════════════════════════════════════════════════════════════ */
/* online dot in the group feed — same rule as the profile page: the poster is "online" when their last activity is within 5 minutes,
   and nothing shows when EITHER person turned "Show online status" off. Cached per request, never throws. */
if (!function_exists('gx_user_online')) {
    function gx_user_online($conn, $uid, $viewer) {
        static $cache = [];
        $uid = (int)$uid; $viewer = (int)$viewer;
        if ($uid <= 0 || $uid === $viewer || !$conn) return false;
        if (isset($cache[$uid])) return $cache[$uid];
        $cache[$uid] = false;
        if (function_exists('dz_presence_allowed') && !dz_presence_allowed($conn, $uid, $viewer, 'online')) return false;   /* the viewer-aware privacy check from dz_privacy.php */
        try {
            foreach ([$uid, $viewer] as $who) {
                if ($who <= 0) continue;
                $r = @$conn->query("SELECT show_online_status FROM user_settings WHERE user_id = " . $who . " LIMIT 1");
                if ($r instanceof mysqli_result) { $row = $r->fetch_assoc(); if ($row && $row['show_online_status'] !== null && (int)$row['show_online_status'] !== 1) return $cache[$uid]; }
            }
            foreach (['last_seen', 'last_active', 'last_activity', 'last_online'] as $col) {
                $r = @$conn->query("SELECT TIMESTAMPDIFF(SECOND, `$col`, NOW()) AS d FROM users WHERE id = " . $uid . " AND `$col` IS NOT NULL LIMIT 1");
                if ($r instanceof mysqli_result) { $row = $r->fetch_assoc(); if ($row && $row['d'] !== null) { if ((int)$row['d'] <= 300) { $cache[$uid] = true; break; } } }
            }
        } catch (Throwable $e) {}
        return $cache[$uid];
    }
}
if (!function_exists('gx_q')) {
    /* run one prepared SELECT → mysqli_result (or null on any problem) */
    function gx_q($conn, $sql, $types = '', array $params = []) {
        try {
            $st = $conn->prepare($sql);
            if (!$st) return null;
            if ($types !== '') $st->bind_param($types, ...$params);
            $st->execute();
            $res = $st->get_result();
            $st->close();
            return ($res instanceof mysqli_result) ? $res : null;
        } catch (Throwable $e) { return null; }
    }
    function gx_row($conn, $sql, $types = '', array $params = []) {
        $r = gx_q($conn, $sql, $types, $params);
        return $r ? ($r->fetch_assoc() ?: null) : null;
    }
    function gx_int($conn, $sql, $types = '', array $params = []) {
        $row = gx_row($conn, $sql, $types, $params);
        return $row ? (int)reset($row) : 0;
    }
    /* run one prepared INSERT / UPDATE / DELETE → true / false */
    function gx_exec($conn, $sql, $types = '', array $params = []) {
        try {
            $st = $conn->prepare($sql);
            if (!$st) return false;
            if ($types !== '') $st->bind_param($types, ...$params);
            $ok = $st->execute();
            $st->close();
            return (bool)$ok;
        } catch (Throwable $e) { return false; }
    }
}

if (!function_exists('gx_save_pod_image')) {
    /* Validates a pod picture / banner upload (extension list + real image check + size) and stores it
       under a random name. Returns the stored file name, or null with $err set ('' = nothing uploaded). */
    function gx_save_pod_image($field, $prefix, &$err, $maxSide = 640) {
        $err = '';
        if (empty($_FILES[$field]['name'])) return null;
        $f = $_FILES[$field];
        if ((int)$f['error'] !== UPLOAD_ERR_OK) { $err = (in_array((int)$f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) ? 'size' : 'upload'; return null; }
        if ((int)$f['size'] > 8 * 1024 * 1024) { $err = 'size'; return null; }
        if (!is_uploaded_file($f['tmp_name'])) { $err = 'upload'; return null; }
        $ext     = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        $by_mime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array($ext, $allowed_ext, true)) { $err = 'type'; return null; }
        $info = @getimagesize($f['tmp_name']);
        if (!$info || !isset($by_mime[$info['mime'] ?? ''])) { $err = 'type'; return null; }
        $name = $prefix . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $by_mime[$info['mime']];   // extension comes from the real image type
        $dir  = __DIR__ . '/uploads/';
        if (!is_dir($dir) || !is_writable($dir)) { $err = 'upload'; return null; }
        if (!move_uploaded_file($f['tmp_name'], $dir . $name)) { $err = 'upload'; return null; }
        /* shrink big phone photos (webp when the server can) — the original weight is wasted on a picture shown this small; falls back to the original on any failure */
        $small = dz_resize_image($dir . $name, $dir . $prefix . '_' . time() . '_' . bin2hex(random_bytes(4)), $maxSide, 82);
        if ($small && file_exists($small) && filesize($small) > 0) { @unlink($dir . $name); $name = basename($small); }
        return $name;
    }
}

if (!function_exists('gx_base_url')) {
    function gx_base_url() {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $host  = (string)($_SERVER['HTTP_HOST'] ?? '');
        if (!preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host)) $host = 'localhost';
        $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
        return ($https ? 'https://' : 'http://') . $host . $dir;
    }
}
if (!function_exists('gx_og_build')) {
    /* Open Graph data for a pod (and for one post when ?meme= is set).
       Private and 18+ pods only ever get a generic preview — nothing from inside them. */
    function gx_og_build($conn, $gid, $mid = 0) {
        $g = gx_row($conn, "SELECT id, title, description, privacy, is_adult, group_pic, banner_pic FROM groups WHERE id = ?", 'i', [(int)$gid]);
        if (!$g) return null;
        $base = gx_base_url();
        $url  = $base . '/group.php?id=' . (int)$gid . ((int)$mid > 0 ? '&meme=' . (int)$mid : '');
        $cut  = function ($s, $n) {
            $s = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$s)));
            if (function_exists('mb_strlen') && mb_strlen($s) > $n) return rtrim(mb_substr($s, 0, $n - 1)) . '…';
            return (!function_exists('mb_strlen') && strlen($s) > $n) ? rtrim(substr($s, 0, $n - 1)) . '…' : $s;
        };
        if (($g['privacy'] ?? 'public') === 'private') {
            return ['title' => 'A private pod on Dezzme', 'desc' => 'This pod is private. Log in to Dezzme to request access.', 'image' => '', 'url' => $url];
        }
        if (!empty($g['is_adult'])) {
            return ['title' => 'A pod on Dezzme', 'desc' => 'This pod has age-restricted content. Log in to Dezzme to continue.', 'image' => '', 'url' => $url];
        }
        $file = function ($raw) { $raw = trim((string)$raw); return $raw === '' ? '' : 'uploads/' . rawurlencode(basename($raw)); };
        $pic  = $file($g['banner_pic'] ?? '') ?: ($file($g['group_pic'] ?? '') ?: 'uploads/default_group.png');
        $out  = [
            'title' => $cut($g['title'], 70) . ' · Dezzme Pods',
            'desc'  => $cut($g['description'] ?? '', 200) ?: 'Join this pod on Dezzme.',
            'image' => $base . '/' . $pic,
            'url'   => $url,
        ];
        if ((int)$mid > 0) {
            $m = gx_row($conn, "SELECT m.caption, m.file_name, m.type FROM group_posts gp JOIN memes m ON m.id = gp.meme_id WHERE gp.group_id = ? AND gp.meme_id = ? LIMIT 1", 'ii', [(int)$gid, (int)$mid]);
            if ($m) {
                $cap = $cut($m['caption'] ?? '', 90);
                $out['title'] = ($cap !== '' ? $cap : 'A post') . ' — in ' . $cut($g['title'], 50);
                $out['desc']  = 'Posted in ' . $cut($g['title'], 60) . ' on Dezzme.';
                $mext = strtolower(pathinfo((string)($m['file_name'] ?? ''), PATHINFO_EXTENSION));
                if (!empty($m['file_name']) && in_array($mext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                    $out['image'] = $base . '/uploads/' . rawurlencode(basename((string)$m['file_name']));
                }
            }
        }
        return $out;
    }
}
if (!function_exists('gx_og_meta_html')) {
    function gx_og_meta_html($og) {
        if (!$og) return '';
        $e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
        $h  = '<meta name="description" content="' . $e($og['desc']) . '">' . "\n";
        $h .= '<meta property="og:site_name" content="Dezzme">' . "\n";
        $h .= '<meta property="og:type" content="website">' . "\n";
        $h .= '<meta property="og:title" content="' . $e($og['title']) . '">' . "\n";
        $h .= '<meta property="og:description" content="' . $e($og['desc']) . '">' . "\n";
        $h .= '<meta property="og:url" content="' . $e($og['url']) . '">' . "\n";
        if ($og['image'] !== '') $h .= '<meta property="og:image" content="' . $e($og['image']) . '">' . "\n";
        $h .= '<meta name="twitter:card" content="' . ($og['image'] !== '' ? 'summary_large_image' : 'summary') . '">' . "\n";
        $h .= '<meta name="twitter:title" content="' . $e($og['title']) . '">' . "\n";
        $h .= '<meta name="twitter:description" content="' . $e($og['desc']) . '">' . "\n";
        if ($og['image'] !== '') $h .= '<meta name="twitter:image" content="' . $e($og['image']) . '">' . "\n";
        return $h;
    }
}
if (!function_exists('gx_og_stub')) {
    /* Logged-out visitors (and link-preview bots) get a tiny page with the preview tags, then go to the login page.
       Prints and exits only when the pod exists; otherwise returns and the normal redirect runs. */
    function gx_og_stub($conn, $gid, $mid) {
        if ((int)$gid <= 0) return;
        $og = gx_og_build($conn, (int)$gid, (int)$mid);
        if (!$og) return;
        $e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
           . '<title>' . $e($og['title']) . '</title>' . "\n" . gx_og_meta_html($og)
           . '<script>window.location.replace("login.php");</script></head>'
           . '<body style="background:#070924;color:#fff;font-family:sans-serif;text-align:center;padding:40px 16px">'
           . '<p>' . $e($og['title']) . '</p><p><a style="color:#5b8fe6" href="login.php">Log in to Dezzme</a></p></body></html>';
        exit();
    }
}

if (!function_exists('gx_ensure_schema')) {
    /* creates the tables of the new pod features once (a flag file stops it running on every load) */
    function gx_ensure_schema($conn) {
        $flag = __DIR__ . '/group_gx_schema2.flag';
        if (file_exists($flag)) return;
        try {
            $conn->query("CREATE TABLE IF NOT EXISTS group_post_pins (
                id INT AUTO_INCREMENT PRIMARY KEY,
                group_id INT NOT NULL,
                meme_id INT NOT NULL,
                pinned_by INT NOT NULL,
                pinned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_group_pin (group_id, meme_id),
                KEY idx_gx_post_pins (group_id, pinned_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $conn->query("CREATE TABLE IF NOT EXISTS group_challenges (
                id INT AUTO_INCREMENT PRIMARY KEY,
                group_id INT NOT NULL,
                title VARCHAR(60) NOT NULL,
                description VARCHAR(240) NOT NULL DEFAULT '',
                hashtag VARCHAR(30) NOT NULL DEFAULT '',
                starts_at DATETIME NOT NULL,
                ends_at DATETIME NOT NULL,
                created_by INT NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_group_challenge (group_id, is_active, ends_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $conn->query("CREATE TABLE IF NOT EXISTS group_last_seen (
                group_id INT NOT NULL,
                user_id INT NOT NULL,
                last_seen DATETIME NOT NULL,
                prev_seen DATETIME NULL,
                PRIMARY KEY (group_id, user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            @file_put_contents($flag, date('c'));
        } catch (Throwable $e) {}
    }
}

/* ══════════════════════════════════════════════════════════════════
   AUTH + CORE IDENTIFIERS
   ══════════════════════════════════════════════════════════════════ */
$current_user = (int)($_SESSION['user_id'] ?? 0);

/* ── archived posts + deactivated accounts stay out of every list (columns come from the Settings page; each fragment is only used
   when its column exists, so the page keeps working before the SQL has been run). m = memes alias, u = users alias. ── */
/* ── hidden counts: a poster can hide likes / reposts / saves / shares on a post (memes.hide_likes, hide_reposts, hide_saves, hide_shares).
   For everyone except the poster the matching count is blanked in the data we send out and a flag (hideLikes, hideReposts, hideSaves,
   hideShares) tells the page to show nothing there. Columns are only used when they exist, so this is safe before the SQL runs. ── */
if (!function_exists('dzx_hide_cols')) {
    function dzx_hide_cols($conn) {
        static $cols = null;
        if ($cols === null) {
            $cols = [];
            foreach (['hide_likes', 'hide_reposts', 'hide_saves', 'hide_shares'] as $c) {
                $q = $conn->query("SHOW COLUMNS FROM memes LIKE '$c'");
                if ($q instanceof mysqli_result && $q->num_rows) $cols[] = $c;
            }
        }
        return $cols;
    }
}
/* one query for a whole page of posts (instead of one per post) — call it with every meme id the page is about to show */
if (!function_exists('dzx_prime_hide')) {
    function dzx_prime_hide($conn, array $ids) {
        global $DZX_HIDE_CACHE;
        if (!is_array($DZX_HIDE_CACHE)) $DZX_HIDE_CACHE = [];
        $cols = dzx_hide_cols($conn);
        if (!$cols) return;
        $ids = array_values(array_diff(array_unique(array_map('intval', $ids)), array_keys($DZX_HIDE_CACHE), [0]));
        foreach (array_chunk($ids, 500) as $chunk) {
            $q = $conn->query("SELECT id, uploaded_by, " . implode(', ', $cols) . " FROM memes WHERE id IN (" . implode(',', $chunk) . ")");
            if ($q instanceof mysqli_result) { while ($r = $q->fetch_assoc()) $DZX_HIDE_CACHE[(int)$r['id']] = $r; }
            foreach ($chunk as $id) { if (!array_key_exists($id, $DZX_HIDE_CACHE)) $DZX_HIDE_CACHE[$id] = null; }
        }
    }
}
if (!function_exists('dzx_apply_hide')) {
    function dzx_apply_hide(array $post, $conn, $mid, $viewer) {
        global $DZX_HIDE_CACHE;
        if (!is_array($DZX_HIDE_CACHE)) $DZX_HIDE_CACHE = [];
        $mid = (int)$mid; $viewer = (int)$viewer;
        if ($mid <= 0) return $post;
        $cols = dzx_hide_cols($conn);
        if (!$cols) return $post;
        if (!array_key_exists($mid, $DZX_HIDE_CACHE)) dzx_prime_hide($conn, [$mid]);   /* fallback when the page did not prime it */
        $r = $DZX_HIDE_CACHE[$mid] ?? null;
        if (!$r || (int)$r['uploaded_by'] === $viewer) return $post;
        $map = ['hide_likes' => ['hideLikes', 'likeCount'], 'hide_reposts' => ['hideReposts', 'repostCount'],
                'hide_saves' => ['hideSaves', 'favCount'],  'hide_shares'  => ['hideShares', 'shareCount']];
        foreach ($map as $col => $m) {
            if (!empty($r[$col])) { $post[$m[0]] = true; $post[$m[1]] = 0; }
        }
        return $post;
    }
}

/* ── resize + compress an uploaded picture (GD). Keeps the picture's look, drops the weight. Returns the saved path, or false so the caller can keep the original. ── */
if (!function_exists('dz_resize_image')) {
    function dz_resize_image($srcPath, $destBase, $maxSide = 640, $quality = 82) {
        if (!function_exists('imagecreatefromstring') || !is_readable($srcPath)) return false;
        $info = @getimagesize($srcPath);
        if (!$info || empty($info[0]) || empty($info[1])) return false;
        if (($info['mime'] ?? '') === 'image/gif') return false;            /* keep animated gifs untouched */
        $img = @imagecreatefromstring(@file_get_contents($srcPath));
        if (!$img) return false;
        if (($info['mime'] ?? '') === 'image/jpeg' && function_exists('exif_read_data')) {   /* upright phone photos */
            $ex = @exif_read_data($srcPath);
            $o  = (int)($ex['Orientation'] ?? 1);
            $rot = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
            if ($rot) { $r = @imagerotate($img, $rot, 0); if ($r) { imagedestroy($img); $img = $r; } }
        }
        $w = imagesx($img); $h = imagesy($img);
        $scale = min(1, $maxSide / max($w, $h));
        $nw = max(1, (int)round($w * $scale)); $nh = max(1, (int)round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false); imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);
        if (function_exists('imagewebp')) { $out = $destBase . '.webp'; $ok = @imagewebp($dst, $out, $quality); }
        else { $out = $destBase . '.jpg'; $bg = imagecreatetruecolor($nw, $nh); imagefill($bg, 0, 0, imagecolorallocate($bg, 0, 0, 0)); imagecopy($bg, $dst, 0, 0, 0, 0, $nw, $nh); $ok = @imagejpeg($bg, $out, $quality + 3); imagedestroy($bg); }
        imagedestroy($dst);
        return $ok ? $out : false;
    }
}

if (!function_exists('dz_has_col')) {
    function dz_has_col($conn, $table, $col) {
        static $seen = [];
        $k = $table . '.' . $col;
        if (!isset($seen[$k])) {
            $r = @$conn->query("SHOW COLUMNS FROM `" . $conn->real_escape_string($table) . "` LIKE '" . $conn->real_escape_string($col) . "'");
            $seen[$k] = ($r instanceof mysqli_result && $r->num_rows > 0);
        }
        return $seen[$k];
    }
}
$DZ_NOT_ARCH    = dz_has_col($conn, 'memes', 'is_archived')     ? ' AND m.is_archived = 0'        : '';
$DZ_NOT_DEACT_U = dz_has_col($conn, 'users',  'deactivated_at') ? ' AND u.deactivated_at IS NULL' : '';
if (!$current_user) {
    gx_og_stub($conn, (int)($_GET['id'] ?? 0), (int)($_GET['meme'] ?? 0));   /* link-preview tags for logged-out visitors / bots, then on to the login page */
    header("Location: login.php");
    exit();
}

/* collab requests + post menu actions for the owner (View insights, turn downloads on/off).
   These sit AFTER this page's CSRF check on purpose: they change data, so they stay token-protected. */
foreach (['dz_privacy.php', 'dz_collab.php', 'dz_views.php'] as $__dzf) {
    if (is_file(__DIR__ . '/' . $__dzf)) require_once __DIR__ . '/' . $__dzf;
}
unset($__dzf);
$user_id = $current_user;
if (function_exists('dz_collab_handle_request')) dz_collab_handle_request($conn, (int)$user_id);

/* poll for a feed item (one lookup per post, cached) */
if (!function_exists('dz_gp_poll')) {
    function dz_gp_poll($conn, $mid, $uid) {
        static $c = [];
        $mid = (int)$mid;
        if ($mid <= 0) return null;
        if (!array_key_exists($mid, $c)) { dz_poll_ensure($conn); $m = dz_polls_for_memes($conn, (int)$uid, [$mid]); $c[$mid] = $m[$mid] ?? null; }
        return $c[$mid];
    }
}

/* collab data for a feed item (group posts can't take collaborators, so this is normally null; it keeps the three-dot menu and download button correct) */
if (!function_exists('dz_gp_collab')) {
    function dz_gp_collab($conn, $mid, $uid) {
        static $c = [];
        $mid = (int)$mid;
        if (!function_exists('dz_collab_payload') || $mid <= 0) return null;
        if (!array_key_exists($mid, $c)) { $m = dz_collab_payload($conn, [$mid], (int)$uid); $c[$mid] = $m[$mid] ?? null; }
        return $c[$mid];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_download') {
    header('Content-Type: application/json');
    $mid = (int)($_POST['meme_id'] ?? 0);
    $v = ($user_id && $mid > 0 && function_exists('dz_toggle_download')) ? dz_toggle_download($conn, $user_id, $mid) : null;
    echo json_encode($v === null ? ['status' => 'error', 'message' => 'only the owner can change this'] : ['status' => 'ok', 'allow_download' => $v]); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'view_stats') {
    header('Content-Type: application/json');
    $mid = (int)($_POST['meme_id'] ?? 0); $own = false;
    if ($user_id && $mid > 0) { $st = $conn->prepare("SELECT uploaded_by FROM memes WHERE id=?"); $st->bind_param("i", $mid); $st->execute(); $rw = $st->get_result()->fetch_assoc(); $own = $rw && (int)$rw['uploaded_by'] === (int)$user_id; }
    echo json_encode(($own && function_exists('dz_view_stats')) ? ['status' => 'ok', 'stats' => dz_view_stats($conn, $mid)] : ['status' => 'error', 'message' => 'not available']); exit;
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

/* (CSRF token + verification now live in the CSRF block at the very top of this file.) */

/* current user's profile pic — prepared */
$stmt = $conn->prepare("SELECT profile_pic, username FROM users WHERE id=?");
$stmt->bind_param("i", $current_user);
$stmt->execute();
$__me_row = $stmt->get_result()->fetch_assoc() ?: [];
$current_user_pic = $__me_row['profile_pic'] ?? '';
$current_user_name = (string)($__me_row['username'] ?? '');
$stmt->close();
if (empty($current_user_pic)) $current_user_pic = 'mee.jpg';

/* ── new-member probation: posts held for approval live in group_posts.is_pending (added once, then a flag file skips the check) ── */
if (!file_exists(__DIR__ . '/group_pending_col.flag')) {
    try {
        $gx_pc = $conn->query("SHOW COLUMNS FROM `group_posts` LIKE 'is_pending'");
        if ($gx_pc && $gx_pc->num_rows === 0) $conn->query("ALTER TABLE `group_posts` ADD COLUMN is_pending TINYINT(1) NOT NULL DEFAULT 0");
        $gx_pc = $conn->query("SHOW COLUMNS FROM `group_posts` LIKE 'is_pending'");
        if ($gx_pc && $gx_pc->num_rows > 0) @file_put_contents(__DIR__ . '/group_pending_col.flag', '1');
    } catch (Throwable $e) { /* feed stays as it was if the column cannot be created */ }
}

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

// ─── Moderators: table is created on first use ──────────────────────────────
try {
    $conn->query("CREATE TABLE IF NOT EXISTS group_moderators (
        id INT AUTO_INCREMENT PRIMARY KEY,
        group_id INT NOT NULL,
        user_id INT NOT NULL,
        added_by INT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_group_mod (group_id, user_id)
    )");
} catch (Throwable $e) { /* table may already exist / no CREATE rights */ }

// ─── Moderator tools: warnings (shared with Group Controls), mutes, escalated reports ───
try {
    $conn->query("CREATE TABLE IF NOT EXISTS group_warnings (
        id INT AUTO_INCREMENT PRIMARY KEY, group_id INT NOT NULL, user_id INT NOT NULL,
        reason VARCHAR(200) NOT NULL, issued_by INT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX (group_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $c = $conn->query("SHOW COLUMNS FROM `group_warnings` LIKE 'seen'");
    if ($c && $c->num_rows === 0) $conn->query("ALTER TABLE `group_warnings` ADD COLUMN seen TINYINT(1) NOT NULL DEFAULT 1");
    $conn->query("CREATE TABLE IF NOT EXISTS group_mutes (
        id INT AUTO_INCREMENT PRIMARY KEY, group_id INT NOT NULL, user_id INT NOT NULL,
        until_at DATETIME NOT NULL, muted_by INT NOT NULL, seen TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_group_mute (group_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $c = $conn->query("SHOW COLUMNS FROM `group_post_reports` LIKE 'escalated'");
    if ($c && $c->num_rows === 0) $conn->query("ALTER TABLE `group_post_reports` ADD COLUMN escalated TINYINT(1) NOT NULL DEFAULT 0");
} catch (Throwable $e) { /* no CREATE/ALTER rights: create these by hand */ }

// logging must never break a moderation action
function gp_log_t($conn, $gid, $actor, $type, $target, $details) {
    try {
        $st = $conn->prepare("INSERT INTO group_activity_log (group_id, actor_id, action_type, target_user_id, details) VALUES (?,?,?,?,?)");
        $st->bind_param("iisis", $gid, $actor, $type, $target, $details); $st->execute(); $st->close();
    } catch (Throwable $e) {}
}

// A moderator removes rule-breaking posts. Owner/admins are never "moderators".
$is_mod = false;
if (!$is_owner && !$is_admin) {
    try {
        $stmt = $conn->prepare("SELECT 1 FROM group_moderators gmo JOIN group_members gm ON gm.group_id=gmo.group_id AND gm.user_id=gmo.user_id WHERE gmo.group_id=? AND gmo.user_id=? LIMIT 1");
        $stmt->bind_param("ii", $group_id, $current_user);
        $stmt->execute();
        $is_mod = $stmt->get_result()->num_rows > 0;
        $stmt->close();
    } catch (Throwable $e) { $is_mod = false; }
}
// ids of everyone a moderator may NOT act on (owner + admins)
$gf_staff_ids = [$group_creator_id];
try {
    $rs = $conn->query("SELECT user_id FROM group_admins WHERE group_id=".(int)$group_id);
    while ($rs && ($r0 = $rs->fetch_assoc())) $gf_staff_ids[] = (int)$r0['user_id'];
} catch (Throwable $e) {}

// ─── Notices for ME: an unread warning or an active mute from the moderators ────
$my_mute_secs = 0; $my_mute_unseen = false; $my_warns_unseen = [];
if (!$is_owner && !$is_admin) {
    try {
        $st = $conn->prepare("SELECT GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), until_at)) AS s, seen FROM group_mutes WHERE group_id=? AND user_id=? LIMIT 1");
        $st->bind_param("ii", $group_id, $current_user); $st->execute();
        $mr = $st->get_result()->fetch_assoc(); $st->close();
        if ($mr && (int)$mr['s'] > 0) { $my_mute_secs = (int)$mr['s']; $my_mute_unseen = ((int)$mr['seen'] === 0); }
        $st = $conn->prepare("SELECT id, reason FROM group_warnings WHERE group_id=? AND user_id=? AND seen=0 ORDER BY id ASC LIMIT 5");
        $st->bind_param("ii", $group_id, $current_user); $st->execute();
        $my_warns_unseen = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
    } catch (Throwable $e) {}
}
function gp_mute_human($s) {
    $s = (int)$s;
    if ($s >= 172800) return ceil($s / 86400) . ' days';
    if ($s >= 86400)  return 'about a day';
    if ($s >= 7200)   return ceil($s / 3600) . ' hours';
    if ($s >= 3600)   return 'about an hour';
    return max(1, (int)ceil($s / 60)) . ' minutes';
}

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

/* ══════════════ SUSPENDED POD (an admin pressed Suspend on this pod in the Reports Center) ══════════════
   Same page, but: grey picture/banner, only the pod name + stats (not tappable), the menu / search / join / post buttons do nothing,
   and a notice sits where the content would be. Needs groups.is_suspended + groups.suspended_at and the table pod_appeals (all created here). */
$GP_SUSP = null;
try {
    foreach (['is_suspended' => "TINYINT(1) NOT NULL DEFAULT 0", 'suspended_at' => "DATETIME NULL DEFAULT NULL"] as $__c => $__def) {
        $__r = $conn->query("SHOW COLUMNS FROM `groups` LIKE '$__c'");
        if (!$__r || $__r->num_rows === 0) { $conn->query("ALTER TABLE `groups` ADD COLUMN `$__c` $__def"); }
    }
    $__r = $conn->prepare("SELECT is_suspended, suspended_at FROM `groups` WHERE id=? LIMIT 1");
    $__r->bind_param("i", $group_id); $__r->execute();
    $__sg = $__r->get_result()->fetch_assoc(); $__r->close();
    if (!empty($__sg['is_suspended'])) {
        $__vr = '';
        if ($current_user) {
            $__q = $conn->prepare("SELECT user_rank FROM users WHERE id=? LIMIT 1");
            $__q->bind_param("i", $current_user); $__q->execute();
            $__vr = strtolower((string)(($__q->get_result()->fetch_assoc()['user_rank'] ?? '')));
            $__q->close();
        }
        $__adm = in_array($__vr, ['admin', 'super_admin'], true);
        if (!$__adm || empty($_GET['admin_peek'])) {
            $conn->query("CREATE TABLE IF NOT EXISTS pod_appeals (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, group_id INT NOT NULL, user_id INT NOT NULL, message VARCHAR(1000) NOT NULL DEFAULT '', status VARCHAR(20) NOT NULL DEFAULT 'pending', admin_notes TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_pa_group (group_id, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $__ts   = !empty($__sg['suspended_at']) ? strtotime($__sg['suspended_at']) : 0;
            $__left = $__ts ? max(0, 180 - (int)floor((time() - $__ts) / 86400)) : 180;
            $__own  = ($current_user && $is_owner);
            $__ap = null;
            if ($__own) {
                $__q = $conn->prepare("SELECT status, created_at FROM pod_appeals WHERE group_id=? ORDER BY id DESC LIMIT 1");
                $__q->bind_param("i", $group_id); $__q->execute();
                $__ap = $__q->get_result()->fetch_assoc(); $__q->close();
            }
            $__pend = $__ap && $__ap['status'] === 'pending';
            $__rej  = $__ap && $__ap['status'] === 'rejected';
            $__exp  = !$__pend && $__left <= 0;
            /* the pod's owner appeals (CSRF is already checked by gp_csrf_verify() at the top of this file) */
            if ($__own && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'submit_pod_appeal') {
                header('Content-Type: application/json');
                if ($__pend || $__rej || $__exp) { echo json_encode(['ok' => false, 'error' => 'You can\'t appeal this suspension.']); exit; }
                $__m = trim((string)($_POST['message'] ?? ''));
                $__m = function_exists('mb_substr') ? mb_substr($__m, 0, 1000) : substr($__m, 0, 1000);
                $__q = $conn->prepare("INSERT INTO pod_appeals (group_id, user_id, message) VALUES (?, ?, ?)");
                $__q->bind_param("iis", $group_id, $current_user, $__m); $__ok = $__q->execute(); $__q->close();
                echo json_encode(['ok' => (bool)$__ok]);
                exit;
            }
            $GP_SUSP = ['own' => $__own, 'admin' => $__adm, 'ts' => $__ts, 'left' => $__left,
                        'pending' => (bool)$__pend, 'rejected' => (bool)$__rej, 'expired' => (bool)$__exp, 'appeal_at' => $__ap['created_at'] ?? null];
        }
    }
} catch (Throwable $e) { $GP_SUSP = null; }
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

// ─── EXTRAS: pinned posts + weekly challenge (owner / admins only) ───────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && strpos((string)$_POST['action'], 'gx_') === 0) {
    header('Content-Type: application/json; charset=utf-8');
    $gx_a   = (string)$_POST['action'];
    $gx_out = function ($a) { echo json_encode($a); exit; };
    if (!($is_owner || $is_admin)) $gx_out(['status' => 'error', 'error' => 'Only the owner and admins can do this']);
    gx_ensure_schema($conn);

    if ($gx_a === 'gx_pin') {
        $mid = (int)($_POST['meme_id'] ?? 0);
        if (!gx_row($conn, "SELECT id FROM group_posts WHERE group_id=? AND meme_id=? AND is_pending=0 LIMIT 1", 'ii', [$group_id, $mid])) $gx_out(['status' => 'error', 'error' => 'That post is not in this pod']);
        if (gx_row($conn, "SELECT id FROM group_post_pins WHERE group_id=? AND meme_id=? LIMIT 1", 'ii', [$group_id, $mid])) $gx_out(['status' => 'ok']);
        if (gx_int($conn, "SELECT COUNT(*) FROM group_post_pins WHERE group_id=?", 'i', [$group_id]) >= 3) $gx_out(['status' => 'error', 'error' => 'You can pin up to 3 posts — unpin one first']);
        if (!gx_exec($conn, "INSERT INTO group_post_pins (group_id, meme_id, pinned_by) VALUES (?, ?, ?)", 'iii', [$group_id, $mid, $current_user])) {
            $gx_out(['status' => 'error', 'error' => 'Could not save the pin — check the group_post_pins table']);
        }
        $gx_out(['status' => 'ok']);
    }
    if ($gx_a === 'gx_unpin') {
        gx_exec($conn, "DELETE FROM group_post_pins WHERE group_id=? AND meme_id=?", 'ii', [$group_id, (int)($_POST['meme_id'] ?? 0)]);
        $gx_out(['status' => 'ok']);
    }
    if ($gx_a === 'gx_challenge_save') {
        $clean = function ($s, $n) {
            $s = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$s)));
            return function_exists('mb_substr') ? mb_substr($s, 0, $n) : substr($s, 0, $n);
        };
        $title = $clean($_POST['title'] ?? '', 60);
        $desc  = $clean($_POST['description'] ?? '', 240);
        $tag   = ltrim(trim((string)($_POST['hashtag'] ?? '')), '#');
        $days  = (int)($_POST['days'] ?? 7);
        if ($title === '') $gx_out(['status' => 'error', 'error' => 'Give the challenge a title']);
        if ($tag !== '' && !preg_match('/^[A-Za-z0-9_]{2,30}$/', $tag)) $gx_out(['status' => 'error', 'error' => 'Hashtag: 2–30 letters, numbers or _']);
        if (!in_array($days, [1, 3, 7, 14, 30], true)) $days = 7;
        gx_exec($conn, "UPDATE group_challenges SET is_active=0 WHERE group_id=? AND is_active=1", 'i', [$group_id]);
        $ok = gx_exec($conn, "INSERT INTO group_challenges (group_id, title, description, hashtag, starts_at, ends_at, created_by) VALUES (?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? DAY), ?)",
                      'isssii', [$group_id, $title, $desc, $tag, $days, $current_user]);
        $gx_out($ok ? ['status' => 'ok'] : ['status' => 'error', 'error' => 'Could not save the challenge']);
    }
    if ($gx_a === 'gx_challenge_end') {
        gx_exec($conn, "UPDATE group_challenges SET is_active=0 WHERE group_id=? AND is_active=1", 'i', [$group_id]);
        $gx_out(['status' => 'ok']);
    }
    $gx_out(['status' => 'error', 'error' => 'Unknown action']);
}

// ─── OWNER ACTIONS ────────────────────────────────────────────────────────────

if (isset($_POST['change_group_pic']) && $is_owner) {
    $gx_pic_err = '';
    $file = gx_save_pod_image('group_pic', 'pod_' . (int)$group_id, $gx_pic_err);   // extension list + real image check + size limit
    if ($file !== null) {
        $stmt = $conn->prepare("UPDATE groups SET group_pic=? WHERE id=?");
        $stmt->bind_param("si", $file, $group_id);
        $stmt->execute();
        $stmt->close();

        log_group_activity($conn, $group_id, $current_user, 'change_group_pic');
    }
    header("Location: group.php?id=$group_id" . ($gx_pic_err !== '' ? '&pic_err=' . $gx_pic_err : '')); exit();
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
    $gx_pic_err = '';
    $file = gx_save_pod_image('banner_pic', 'banner_' . (int)$group_id, $gx_pic_err, 1600);   // extension list + real image check + size limit
    if ($file !== null) {
        $stmt = $conn->prepare("UPDATE groups SET banner_pic=? WHERE id=?");
        $stmt->bind_param("si", $file, $group_id);
        $stmt->execute();
        $stmt->close();

        log_group_activity($conn, $group_id, $current_user, 'change_banner_pic');
    }
    header("Location: group.php?id=$group_id" . ($gx_pic_err !== '' ? '&pic_err=' . $gx_pic_err : '')); exit();
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
    /* IDOR: only a CURRENT MEMBER of this pod can be made an admin (not any user id) */
    if ($appoint_id > 0) {
        $__am = $conn->prepare("SELECT 1 FROM group_members WHERE group_id=? AND user_id=? LIMIT 1");
        $__am->bind_param("ii", $group_id, $appoint_id); $__am->execute();
        if (!$__am->get_result()->fetch_row()) $appoint_id = 0;
        $__am->close();
    }
    if ($appoint_id > 0) {
        $stmt = $conn->prepare("INSERT IGNORE INTO group_admins (group_id, user_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $group_id, $appoint_id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected > 0) {
            try { $stm = $conn->prepare("DELETE FROM group_moderators WHERE group_id=? AND user_id=?"); $stm->bind_param("ii", $group_id, $appoint_id); $stm->execute(); $stm->close(); } catch (Throwable $e) {}
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

// ─── MODERATOR TOOLS (JSON): reports, pending posts, warn, mute ──────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mod_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $ma  = (string)$_POST['mod_action'];
    $out = function ($a) { echo json_encode($a); exit; };
    $mq  = function (string $sql, string $types = '', array $p = []) use ($conn) {
        $st = $conn->prepare($sql); if (!$st) return [];
        if ($types !== '') $st->bind_param($types, ...$p);
        $st->execute(); $res = $st->get_result();
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : []; $st->close(); return $rows;
    };
    try {
        // anyone: "I read it" for my own warning / mute notice
        if ($ma === 'ack') {
            $st = $conn->prepare("UPDATE group_warnings SET seen=1 WHERE group_id=? AND user_id=?"); $st->bind_param("ii", $group_id, $current_user); $st->execute(); $st->close();
            $st = $conn->prepare("UPDATE group_mutes SET seen=1 WHERE group_id=? AND user_id=?");   $st->bind_param("ii", $group_id, $current_user); $st->execute(); $st->close();
            $out(['status' => 'ok']);
        }
        if (!($is_mod || $is_owner || $is_admin)) $out(['status' => 'error', 'error' => 'Only moderators can do this']);

        $file = function ($raw) { $raw = trim((string)$raw); return $raw === '' ? '' : 'uploads/' . rawurlencode(basename($raw)); };
        $stf  = implode(',', array_map('intval', $gf_staff_ids));
        // the member being acted on: must be a regular member (never me, the owner or an admin)
        $target = function () use ($conn, $group_id, $current_user, $gf_staff_ids, $out) {
            $uid = (int)($_POST['user_id'] ?? 0);
            if ($uid <= 0 || $uid === $current_user || in_array($uid, $gf_staff_ids, true)) $out(['status' => 'error', 'error' => 'You can only act on regular members']);
            $st = $conn->prepare("SELECT u.username FROM group_members gm JOIN users u ON u.id=gm.user_id WHERE gm.group_id=? AND gm.user_id=? LIMIT 1");
            $st->bind_param("ii", $group_id, $uid); $st->execute();
            $r = $st->get_result()->fetch_assoc(); $st->close();
            if (!$r) $out(['status' => 'error', 'error' => 'That person is not a member of this pod']);
            return [$uid, (string)$r['username']];
        };

        if ($ma === 'list') {
            $reports = $mq("SELECT gpr.id AS report_id, gpr.reason, gpr.description, gpr.escalated, gp.id AS group_post_id, m.id AS meme_id, m.file_name, m.caption, m.type,
                                   u.id AS poster_id, u.username AS poster, u.profile_pic AS poster_pic, ru.username AS reporter
                            FROM group_post_reports gpr
                            JOIN group_posts gp ON gp.id = gpr.group_post_id
                            JOIN memes m ON m.id = gp.meme_id
                            JOIN users u ON u.id = gp.posted_by
                            LEFT JOIN users ru ON ru.id = gpr.reported_by
                            WHERE gpr.group_id=? AND gpr.status='pending' ORDER BY gpr.id ASC LIMIT 100", 'i', [$group_id]);
            $pending = $mq("SELECT gp.id AS group_post_id, m.id AS meme_id, m.file_name, m.caption, m.type, u.id AS poster_id, u.username AS poster, u.profile_pic AS poster_pic
                            FROM group_posts gp JOIN memes m ON m.id = gp.meme_id JOIN users u ON u.id = gp.posted_by
                            WHERE gp.group_id=? AND gp.is_pending=1 ORDER BY gp.id ASC LIMIT 100", 'i', [$group_id]);
            $pic = function ($p) { return trim((string)$p) !== '' ? 'uploads/' . rawurlencode(basename((string)$p)) : 'mee.jpg'; };
            foreach ($reports as &$r) { $r['pic'] = $pic($r['poster_pic']); $r['verified'] = dz_vb_flag($conn, (int)$r['poster_id']); unset($r['poster_pic']); $r['thumb'] = $file($r['file_name']); $r['poster_staff'] = in_array((int)$r['poster_id'], $gf_staff_ids, true); unset($r['file_name']); } unset($r);
            foreach ($pending as &$r) { $r['pic'] = $pic($r['poster_pic']); $r['verified'] = dz_vb_flag($conn, (int)$r['poster_id']); unset($r['poster_pic']); $r['thumb'] = $file($r['file_name']); unset($r['file_name']); } unset($r);
            $out(['status' => 'ok', 'reports' => $reports, 'pending' => $pending]);
        }
        if ($ma === 'members') {
            $q = trim((string)($_POST['q'] ?? ''));
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $rows = $mq("SELECT u.id, u.username, u.profile_pic,
                                (SELECT GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), gmu.until_at)) FROM group_mutes gmu WHERE gmu.group_id=gm.group_id AND gmu.user_id=u.id LIMIT 1) AS mute_secs,
                                (SELECT COUNT(*) FROM group_warnings w WHERE w.group_id=gm.group_id AND w.user_id=u.id) AS strikes
                         FROM group_members gm JOIN users u ON u.id = gm.user_id
                         WHERE gm.group_id=? AND u.id NOT IN ($stf) AND u.id <> ? AND u.username LIKE ?
                         ORDER BY u.username ASC LIMIT 60", 'iis', [$group_id, $current_user, $like]);
            foreach ($rows as &$r) { $r['verified'] = dz_vb_flag($conn, (int)$r['id']); $r['pic'] = $r['profile_pic'] ? 'uploads/' . rawurlencode(basename((string)$r['profile_pic'])) : 'mee.jpg'; unset($r['profile_pic']); } unset($r);
            $out(['status' => 'ok', 'members' => $rows]);
        }
        if ($ma === 'dismiss') {
            $rid = (int)($_POST['report_id'] ?? 0);
            $st = $conn->prepare("UPDATE group_post_reports SET status='dismissed' WHERE id=? AND group_id=? AND status='pending'");
            $st->bind_param("ii", $rid, $group_id); $st->execute(); $n = $st->affected_rows; $st->close();
            if ($n > 0) log_group_activity($conn, $group_id, $current_user, 'dismiss_report');
            $out(['status' => 'ok']);
        }
        if ($ma === 'escalate') {
            $rid = (int)($_POST['report_id'] ?? 0);
            $st = $conn->prepare("UPDATE group_post_reports SET escalated=1 WHERE id=? AND group_id=? AND status='pending'");
            $st->bind_param("ii", $rid, $group_id); $st->execute(); $n = $st->affected_rows; $st->close();
            if ($n > 0) gp_log_t($conn, $group_id, $current_user, 'escalate_report', null, '');
            $out(['status' => 'ok']);
        }
        if ($ma === 'approve') {
            $pid = (int)($_POST['group_post_id'] ?? 0);
            $st = $conn->prepare("UPDATE group_posts SET is_pending=0 WHERE id=? AND group_id=? AND is_pending=1");
            $st->bind_param("ii", $pid, $group_id); $st->execute(); $n = $st->affected_rows; $st->close();
            if ($n > 0) gp_log_t($conn, $group_id, $current_user, 'approve_post', null, '1');
            $out(['status' => 'ok']);
        }
        if ($ma === 'warn') {
            [$uid, $uname] = $target();
            $reason = trim((string)($_POST['reason'] ?? ''));
            $reason = function_exists('mb_substr') ? mb_substr($reason, 0, 200) : substr($reason, 0, 200);
            if ($reason === '') $out(['status' => 'error', 'error' => 'Add a short reason for the warning']);
            $st = $conn->prepare("INSERT INTO group_warnings (group_id, user_id, reason, issued_by, seen) VALUES (?,?,?,?,0)");
            $st->bind_param("iisi", $group_id, $uid, $reason, $current_user); $st->execute(); $st->close();
            gp_log_t($conn, $group_id, $current_user, 'warn_member', $uid, $reason);
            $out(['status' => 'ok', 'name' => $uname]);
        }
        if ($ma === 'mute') {
            [$uid, $uname] = $target();
            $mins = (int)($_POST['minutes'] ?? 0);
            if ($mins === 0) {
                $st = $conn->prepare("DELETE FROM group_mutes WHERE group_id=? AND user_id=?"); $st->bind_param("ii", $group_id, $uid); $st->execute(); $st->close();
                gp_log_t($conn, $group_id, $current_user, 'unmute_member', $uid, '');
                $out(['status' => 'ok', 'name' => $uname, 'unmuted' => true]);
            }
            if (!in_array($mins, [60, 1440, 10080], true)) $out(['status' => 'error', 'error' => 'Pick 1 hour, 1 day or 7 days']);
            $st = $conn->prepare("INSERT INTO group_mutes (group_id, user_id, until_at, muted_by, seen) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), ?, 0)
                                  ON DUPLICATE KEY UPDATE until_at = DATE_ADD(NOW(), INTERVAL ? MINUTE), muted_by = VALUES(muted_by), seen = 0");
            $st->bind_param("iiiii", $group_id, $uid, $mins, $current_user, $mins); $st->execute(); $st->close();
            gp_log_t($conn, $group_id, $current_user, 'mute_member', $uid, $mins >= 1440 ? ($mins / 1440) . ' day' . ($mins >= 2880 ? 's' : '') : '1 hour');
            $out(['status' => 'ok', 'name' => $uname]);
        }
        $out(['status' => 'error', 'error' => 'Unknown action']);
    } catch (Throwable $e) {
        $out(['status' => 'error', 'error' => 'Something went wrong — please try again']);
    }
}

// ─── APPOINT / REMOVE MODERATORS (owner + admins) ────────────────────────────
if (isset($_POST['appoint_mod']) && ($is_owner || $is_admin)) {
    $mid = (int)($_POST['mod_user_id'] ?? 0);
    if ($mid > 0 && $mid !== $group_creator_id && !in_array($mid, $gf_staff_ids, true)) {
        $stmt = $conn->prepare("SELECT 1 FROM group_members WHERE group_id=? AND user_id=? LIMIT 1");
        $stmt->bind_param("ii", $group_id, $mid);
        $stmt->execute();
        $mem_ok = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if ($mem_ok) {
            $stmt = $conn->prepare("INSERT IGNORE INTO group_moderators (group_id, user_id, added_by) VALUES (?,?,?)");
            $stmt->bind_param("iii", $group_id, $mid, $current_user);
            $stmt->execute();
            $aff = $stmt->affected_rows;
            $stmt->close();
            if ($aff > 0) gp_log_t($conn, $group_id, $current_user, 'appoint_mod', $mid, '');
        }
    }
    header("Location: group.php?id=$group_id"); exit();
}
if (isset($_POST['remove_mod']) && ($is_owner || $is_admin)) {
    $mid = (int)($_POST['mod_user_id'] ?? 0);
    $stmt = $conn->prepare("DELETE FROM group_moderators WHERE group_id=? AND user_id=?");
    $stmt->bind_param("ii", $group_id, $mid);
    $stmt->execute();
    $aff = $stmt->affected_rows;
    $stmt->close();
    if ($aff > 0) gp_log_t($conn, $group_id, $current_user, 'remove_mod', $mid, '');
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
        $tag_count = gx_int($conn, "SELECT COUNT(*) AS c FROM group_hashtags WHERE group_id=?", 'i', [$group_id]);
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
    $del_poster_id = 0;
    if ($own_check && $own_check->num_rows > 0) {
        $row = $own_check->fetch_assoc();
        $del_poster_id = (int)$row['posted_by'];
        $is_post_owner = ($del_poster_id === $current_user);
    }
    $stmt->close();

    // Moderators may remove any member's post, but never one by the owner or an admin.
    $mod_can_remove = ($is_mod && $del_poster_id > 0 && !in_array($del_poster_id, $gf_staff_ids, true));

    if ($is_owner || $is_admin || $is_post_owner || $mod_can_remove) {
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
        if (($is_owner || $is_admin || $mod_can_remove) && !($is_post_owner && !$is_owner && !$is_admin)) {
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
    if ($ban_user && !$is_owner) {   /* an admin can't ban another admin — only the owner can */
        $__ba = $conn->prepare("SELECT 1 FROM group_admins WHERE group_id=? AND user_id=? LIMIT 1");
        $__ba->bind_param("ii", $group_id, $ban_user); $__ba->execute();
        if ($__ba->get_result()->fetch_row()) $ban_user = 0;
        $__ba->close();
    }
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

/* live Join / Leave / Cancel: when the page posts with gp_ajax=1 it gets JSON back instead of a redirect (no page refresh) */
function gp_join_done($conn, $group_id, $current_user) {
    if (!isset($_POST['gp_ajax'])) { header("Location: group.php?id=$group_id"); exit(); }
    header('Content-Type: application/json');
    $m = 0; $r = '';
    try {
        $s = $conn->prepare("SELECT 1 FROM group_members WHERE group_id=? AND user_id=? LIMIT 1");
        $s->bind_param("ii", $group_id, $current_user); $s->execute();
        $m = $s->get_result()->num_rows > 0 ? 1 : 0; $s->close();
        $s = $conn->prepare("SELECT status FROM group_join_requests WHERE group_id=? AND user_id=?");
        $s->bind_param("ii", $group_id, $current_user); $s->execute();
        $row = $s->get_result()->fetch_assoc(); $s->close();
        $r = $row ? (string)$row['status'] : '';
    } catch (Throwable $e) {}
    echo json_encode(['status' => 'ok', 'member' => $m, 'request' => $r]);
    exit();
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
    if (function_exists('jq_clear_answers')) jq_clear_answers($conn, $group_id, $current_user);   /* withdrawn request → drop its answers */
    unset($_SESSION['jq_done_' . $group_id]);
    $stmt = $conn->prepare("DELETE FROM group_join_requests WHERE group_id=? AND user_id=? AND status='pending'");
    $stmt->bind_param("ii", $group_id, $current_user);
    $stmt->execute();
    $stmt->close();
    gp_join_done($conn, $group_id, $current_user);
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

// ─── ANSWER THE OWNER'S JOIN QUESTIONS (after the request exists — "Answer questions" button) ───
if (isset($_POST['submit_join_answers'])) {
    if ($is_private_group && !$is_member && $my_request_status === 'pending' && function_exists('jq_submit_answers')) {
        $jq_res = jq_submit_answers($conn, $group_id, $current_user, $_POST['jq'] ?? []);
        if (empty($jq_res['ok'])) {
            $_SESSION['jq_error'] = $jq_res['msg'] ?? 'Please answer the required questions.';
        } else {
            $_SESSION['jq_done_' . $group_id] = 1;
        }
    }
    header("Location: group.php?id=$group_id"); exit();
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
            /* the request is created first; the owner's questions are answered afterwards
               from the "Your membership is pending" box (see submit_join_answers above) */
            $stmt = $conn->prepare("
                INSERT INTO group_join_requests (group_id, user_id, status, requested_at)
                VALUES (?, ?, 'pending', NOW())
                ON DUPLICATE KEY UPDATE status='pending', requested_at=NOW(), decided_at=NULL
            ");
            $stmt->bind_param("ii", $group_id, $current_user);
            $stmt->execute();
            $stmt->close();
            unset($_SESSION['jq_done_' . $group_id]);
        } else {
            $stmt = $conn->prepare("INSERT INTO group_members (group_id,user_id) VALUES (?,?)");
            $stmt->bind_param("ii", $group_id, $current_user);
            $stmt->execute();
            $stmt->close();
        }
    }
    gp_join_done($conn, $group_id, $current_user);
}

if (isset($_POST['leave_group'])) {
    $stmt = $conn->prepare("DELETE FROM group_members WHERE group_id=? AND user_id=?");
    $stmt->bind_param("ii", $group_id, $current_user);
    $stmt->execute();
    $stmt->close();
    gp_join_done($conn, $group_id, $current_user);
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
              $DZ_NOT_DEACT_U
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
            WHERE gp.group_id = ? AND gp.is_pending = 0 AND m.caption LIKE ? $DZ_NOT_ARCH $DZ_NOT_DEACT_U
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
        /* can't track someone who blocked you / whom you blocked */
        $__bk = $conn->prepare("SELECT 1 FROM blocks WHERE (blocker_id=? AND blocked_id=?) OR (blocker_id=? AND blocked_id=?) LIMIT 1");
        $__bk->bind_param("iiii", $current_user, $target_id, $target_id, $current_user); $__bk->execute();
        $__bkd = (bool)$__bk->get_result()->fetch_row(); $__bk->close();
        if ($__bkd) { echo json_encode(['status' => 'error', 'message' => 'invalid']); exit(); }
        $stmt = $conn->prepare("INSERT INTO followers (follower_id, following_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $current_user, $target_id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['status' => 'ok', 'following' => true]);
    }
    exit();
}

/* ══════════════ SECURITY: object-level access (IDOR) — can this viewer SEE this post? ══════════════ */
if (!function_exists('dz_cta_level')) {
    function dz_cta_level($conn, $uid) {   // same rule as the privacy block: public | limited | private | full
        $uid = (int)$uid; $lvl = ''; $flag = false;
        try { $r = $conn->query("SELECT is_fully_private FROM user_settings WHERE user_id = $uid LIMIT 1"); if ($r instanceof mysqli_result && ($row = $r->fetch_assoc())) $flag = !empty($row['is_fully_private']); } catch (Throwable $e) {}
        try { $r = $conn->query("SELECT privacy_level FROM user_privacy WHERE user_id = $uid LIMIT 1"); if ($r instanceof mysqli_result && ($row = $r->fetch_assoc())) $lvl = (string)$row['privacy_level']; } catch (Throwable $e) {}
        $needs = in_array($lvl, ['private', 'full'], true);
        if ($flag && !$needs) $lvl = 'full';
        elseif (!$flag && $needs) $lvl = 'public';
        elseif (!in_array($lvl, ['public', 'limited', 'private', 'full'], true)) $lvl = 'public';
        return $lvl;
    }
}
if (!function_exists('dz_meme_visible')) {
    function dz_meme_visible($conn, $meme_id, $viewer) {
        global $DZ_NOT_ARCH, $DZ_NOT_DEACT_U;
        $meme_id = (int)$meme_id; $viewer = (int)$viewer;
        if ($meme_id <= 0) return false;
        $sql = "SELECT m.id, m.uploaded_by, m.is_adult, m.is_ad
                FROM memes m JOIN users u ON u.id = m.uploaded_by
                WHERE m.id = ? " . ($DZ_NOT_ARCH ?? '') . ($DZ_NOT_DEACT_U ?? '') . " LIMIT 1";
        $st = $conn->prepare($sql);
        if (!$st) return false;
        $st->bind_param('i', $meme_id); $st->execute();
        $m = $st->get_result()->fetch_assoc(); $st->close();
        if (!$m) return false;
        $owner = (int)$m['uploaded_by'];
        if ($viewer > 0 && $viewer === $owner) return true;
        if ($viewer <= 0) return false;
        /* approved ads only */
        if (!empty($m['is_ad'])) {
            $a = $conn->prepare("SELECT 1 FROM meme_ads WHERE meme_id = ? AND status = 'approved' LIMIT 1");
            if ($a) { $a->bind_param('i', $meme_id); $a->execute(); $ok = (bool)$a->get_result()->fetch_row(); $a->close(); if (!$ok) return false; }
        }
        /* blocked in either direction */
        $b = $conn->prepare("SELECT 1 FROM blocks WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?) LIMIT 1");
        if ($b) { $b->bind_param('iiii', $viewer, $owner, $owner, $viewer); $b->execute(); $bl = (bool)$b->get_result()->fetch_row(); $b->close(); if ($bl) return false; }
        /* 18+ posts: only the poster, or members of a group the post lives in */
        if (!empty($m['is_adult'])) {
            $g = $conn->prepare("SELECT 1 FROM group_posts gp JOIN group_members gm ON gm.group_id = gp.group_id WHERE gp.meme_id = ? AND gm.user_id = ? LIMIT 1");
            if ($g) { $g->bind_param('ii', $meme_id, $viewer); $g->execute(); $ok = (bool)$g->get_result()->fetch_row(); $g->close(); if (!$ok) return false; }
        }
        /* posts that live in a PRIVATE group: members only */
        $pg = $conn->prepare("SELECT gp.group_id FROM group_posts gp JOIN groups g ON g.id = gp.group_id WHERE gp.meme_id = ? AND g.privacy = 'private' LIMIT 1");
        if ($pg) {
            $pg->bind_param('i', $meme_id); $pg->execute(); $prow = $pg->get_result()->fetch_assoc(); $pg->close();
            if ($prow) {
                $gm = $conn->prepare("SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ? LIMIT 1");
                if ($gm) { $gid_ = (int)$prow['group_id']; $gm->bind_param('ii', $gid_, $viewer); $gm->execute(); $ok = (bool)$gm->get_result()->fetch_row(); $gm->close(); if (!$ok) return false; }
            }
        }
        /* private profiles: only people who track them */
        if (function_exists('dz_cta_level') && in_array(dz_cta_level($conn, $owner), ['private', 'full'], true)) {
            $f = $conn->prepare("SELECT 1 FROM followers WHERE follower_id = ? AND following_id = ? LIMIT 1");
            if ($f) { $f->bind_param('ii', $viewer, $owner); $f->execute(); $ok = (bool)$f->get_result()->fetch_row(); $f->close(); if (!$ok) return false; }
        }
        return true;
    }
}
/* ══════ POLLS: read (?type=poll&meme_id=ID) + vote (action=poll_vote). Same code as shared_core.php; the visibility check is this page's dz_meme_visible(). ══════ */
if (isset($_GET['type']) && $_GET['type'] === 'poll') {
    $type = 'poll'; header('Content-Type: application/json');
    if($type === 'poll'){
        /* one post's poll (the comment sheet loads it) */
        $mid = (int)($_GET['meme_id'] ?? 0);
        if(!$user_id || $mid <= 0 || !dz_meme_visible($conn, $mid, (int)$user_id)){ echo json_encode(['ok'=>true,'poll'=>null]); exit; }
        dz_poll_ensure($conn);
        $pm = dz_polls_for_memes($conn, (int)$user_id, [$mid]);
        echo json_encode(['ok'=>true,'poll'=>$pm[$mid] ?? null]);
        exit;
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'poll_vote') {
    $action = 'poll_vote'; header('Content-Type: application/json');
    /* ── poll_vote: vote / change vote / remove vote (option_id 0). Open polls only; same visibility rules as liking the post ── */
    if($action === 'poll_vote'){
        /* CSRF: every POST is already checked by gp_csrf_verify() at the top of this file */
        if(!$user_id){ echo json_encode(['ok'=>false,'error'=>'Log in to vote.']); exit; }
        $poll_id = (int)($_POST['poll_id'] ?? 0); $opt_id = (int)($_POST['option_id'] ?? 0);
        if($poll_id <= 0 || $opt_id < 0){ echo json_encode(['ok'=>false,'error'=>'invalid request']); exit; }
        dz_poll_ensure($conn);
        try{
            $now = time(); $hits = $_SESSION['dz_poll_hits'] ?? [];
            $hits = array_values(array_filter(is_array($hits) ? $hits : [], function($t) use ($now){ return (int)$t > $now - 60; }));
            if(count($hits) >= 30){ echo json_encode(['ok'=>false,'error'=>'Slow down a little.']); exit; }
            $hits[] = $now; $_SESSION['dz_poll_hits'] = $hits;
            $st = $conn->prepare("SELECT meme_id, (ends_at IS NOT NULL AND ends_at <= NOW()) AS closed FROM polls WHERE id = ? LIMIT 1");
            $st->bind_param("i", $poll_id); $st->execute();
            $p = $st->get_result()->fetch_assoc(); $st->close();
            if(!$p || !dz_meme_visible($conn, (int)$p['meme_id'], (int)$user_id)){ echo json_encode(['ok'=>false,'error'=>'Poll not found.']); exit; }
            $pmid = (int)$p['meme_id'];
            if(!empty($p['closed'])){
                $pm = dz_polls_for_memes($conn, (int)$user_id, [$pmid]);
                echo json_encode(['ok'=>false,'error'=>'This poll has ended.','poll'=>$pm[$pmid] ?? null]); exit;
            }
            if($opt_id === 0){
                $d = $conn->prepare("DELETE FROM poll_votes WHERE poll_id = ? AND user_id = ?");
                $d->bind_param("ii", $poll_id, $user_id); $d->execute(); $d->close();
            } else {
                $o = $conn->prepare("SELECT 1 FROM poll_options WHERE id = ? AND poll_id = ? LIMIT 1");
                $o->bind_param("ii", $opt_id, $poll_id); $o->execute();
                $okOpt = $o->get_result()->num_rows > 0; $o->close();
                if(!$okOpt){ echo json_encode(['ok'=>false,'error'=>'invalid choice']); exit; }
                $v = $conn->prepare("INSERT INTO poll_votes (poll_id, user_id, option_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE option_id = VALUES(option_id)");
                $v->bind_param("iii", $poll_id, $user_id, $opt_id); $v->execute(); $v->close();
            }
            $pm = dz_polls_for_memes($conn, (int)$user_id, [$pmid]);
            echo json_encode(['ok'=>true,'poll'=>$pm[$pmid] ?? null]);
        }catch(Throwable $e){ error_log('[dz poll] vote failed: '.$e->getMessage()); echo json_encode(['ok'=>false,'error'=>'Could not save your vote. Try again.']); }
        exit;
    }
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
        WHERE m.id = ? $DZ_NOT_ARCH $DZ_NOT_DEACT_U
        LIMIT 1
    ");
    $stmt->bind_param("iiiii", $current_user, $current_user, $current_user, $current_user, $conn_mid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || !dz_meme_visible($conn, $conn_mid, $current_user)) {
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
            SELECT u2.id, u2.username, u2.profile_pic, 'like' AS action_type, l.created_at AS acted_at
            FROM likes l JOIN users u2 ON u2.id=l.user_id
            JOIN followers f ON f.following_id=u2.id
            WHERE l.meme_id=? AND f.follower_id=? AND u2.id!=?
            UNION ALL
            SELECT u2.id, u2.username, u2.profile_pic, 'repost' AS action_type, r.created_at AS acted_at
            FROM reposts r JOIN users u2 ON u2.id=r.user_id
            JOIN followers f ON f.following_id=u2.id
            WHERE r.meme_id=? AND f.follower_id=? AND u2.id!=?
            UNION ALL
            SELECT u2.id, u2.username, u2.profile_pic, 'repost' AS action_type, r.created_at AS acted_at
            FROM reposts r JOIN users u2 ON u2.id=r.user_id
            WHERE r.meme_id=? AND r.user_id=?
        ");
        $sp->bind_param("iiiiiiii", $conn_mid, $current_user, $current_user, $conn_mid, $current_user, $current_user, $conn_mid, $current_user);
        $sp->execute();
        $sp_res = $sp->get_result();
        $sp_raw = [];
        while ($sr = $sp_res->fetch_assoc()) $sp_raw[] = $sr;
        $social_proof = array_map(function ($x) use ($conn) { $x['isVerified'] = dz_vb_flag($conn, (int)($x['id'] ?? 0)); return $x; }, dz_sp_sort($sp_raw, (int)$conn_mid));
        $sp->close();
    }

    $post_data = [
        'id'               => (int)$row['meme_id'],
        'groupPostId'      => null,
        'file'             => $primary_file,
        'type'             => $primary_type,
        'media'            => $media_items,
        'pic'              => !empty($row['profile_pic']) ? $row['profile_pic'] : 'mee.jpg',
        'userId'           => (int)$row['user_id'],
        'online'           => gx_user_online($conn, (int)$row['user_id'], (int)($current_user ?? 0)),
        'username'         => $row['username'],
        'isVerified'       => dz_vb_flag($conn, (int)$row['user_id']),
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
        'allowDownload' => (int)($row['allow_download'] ?? 1),
        'collab'        => dz_gp_collab($conn, (int)($row['meme_id'] ?? 0), $current_user),
        'poll'          => dz_gp_poll($conn, (int)($row['meme_id'] ?? 0), $current_user),
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
                'isVerified'  => !empty($sp['isVerified']),
            ];
        }, $social_proof),
        'socialSpare'      => dz_sp_spare((int)$conn_mid),
        // Never chain a second hop — a connected Zeem's own Connect
        // button is intentionally hidden, so tapping it can't lead to
        // a nested "View · Connected Zeem" chain inside the modal.
        'connectedMemeId'  => null,
        'connectedLabel'   => null,
    ];

    $post_data = dzx_apply_hide($post_data, $conn, (int)$row['meme_id'], $current_user);
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
    if ($my_mute_secs > 0) {
        $upload_error = "A moderator has muted you in this pod. You can post again in " . gp_mute_human($my_mute_secs) . ".";
    } elseif (strpos($caption, '#') === false) {
        $upload_error = "You must include at least one hashtag (#) in your caption.";
    } else {
        /* ── SERVER-SIDE TAG / MENTION GATE (tag_guard.php is the real gate;
              the modal's checks are only an early hint) ── */
        $tag_ids = pv_ids($_POST['tagged_users'] ?? '');
        $tg = pv_tag_check($conn, $tag_ids, $current_user);
        $mg = pv_text_guard($conn, $caption, $current_user);
        if ($tg['err'] !== '' || $mg !== '') $upload_error = trim($tg['err'] . ' ' . $mg);

        /* ── GROUP MODERATION (set in group controls): slow mode + banned-word list. Owner and admins are never checked. ── */
        $gx_report_word = null;
        if (empty($upload_error) && file_exists(__DIR__ . '/group_moderation_helper.php')) {
            include_once __DIR__ . '/group_moderation_helper.php';
            if (function_exists('gcm_slowmode_check')) {
                $gx_staff = ($is_owner || $is_admin);
                $gx_sm = gcm_slowmode_check($conn, $group_id, $current_user, $gx_staff);
                if (empty($gx_sm['ok'])) {
                    $upload_error = $gx_sm['msg'] ?? 'Slow mode is on. Please wait before posting again.';
                } else {
                    $gx_wc = gcm_check_text($conn, $group_id, $caption, $gx_staff);
                    if (empty($gx_wc['ok'])) $upload_error = $gx_wc['msg'] ?? 'Your post contains a word this group does not allow.';
                    elseif (($gx_wc['action'] ?? '') === 'report') $gx_report_word = (string)($gx_wc['word'] ?? '');
                }
            }
        }

        $allowed_ext     = ['jpg','jpeg','png','gif','webp','mp4','webm','mov'];
        $uploaded_items  = []; // [ ['file'=>.., 'type'=>..], ... ] up to 20

        /* a rejected post never stores files */
        if (empty($upload_error) && !empty($_FILES['post_file']['name']) && is_array($_FILES['post_file']['name'])) {
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
            if ($meme_id > 0 && function_exists('dz_apply_post_defaults')) dz_apply_post_defaults($conn, (int)$current_user, (int)$meme_id);   /* the author's default settings (downloads, hidden counts...) */

            /* hide counts (likes / reposts / saves / shares) — only the four switches the poster turned on are saved; columns are checked first so this is safe before the SQL is run */
            $hide_set = [];
            foreach (['hide_likes', 'hide_reposts', 'hide_saves', 'hide_shares'] as $hc) {
                if (dz_has_col($conn, 'memes', $hc)) $hide_set[$hc] = (($_POST[$hc] ?? '') === '1') ? 1 : 0;
            }
            if ($hide_set && $meme_id > 0 && array_sum($hide_set) > 0) {
                $hide_sql = implode(', ', array_map(function ($c) { return "$c = ?"; }, array_keys($hide_set)));
                if ($hst = $conn->prepare("UPDATE memes SET $hide_sql WHERE id = ? AND uploaded_by = ?")) {
                    $hvals = array_values($hide_set);
                    $hvals[] = (int)$meme_id; $hvals[] = (int)$current_user;
                    $hst->bind_param(str_repeat('i', count($hvals)), ...$hvals);
                    $hst->execute();
                    $hst->close();
                }
            }

            /* save the validated tags + feature flag for this new post */
            pv_save_tags($conn, $meme_id, $tg['ids'], $current_user);
            pv_save_feature_ok($conn, $meme_id, $caption, $current_user);

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

            /* new-member probation: the first N posts of a member (N = groups.probation_posts) wait for owner / admin approval. Owner and admins are never held. */
            $gx_hold = 0;
            $gx_prob = (int)($group['probation_posts'] ?? 0);
            if ($gx_prob > 0 && !$is_owner && !$is_admin) {
                $gx_prior = gx_int($conn, "SELECT COUNT(*) FROM group_posts WHERE group_id=? AND posted_by=?", 'ii', [$group_id, $current_user]);
                if ($gx_prior < $gx_prob) $gx_hold = 1;
            }
            $stmt = $conn->prepare("INSERT INTO group_posts (group_id, posted_by, meme_id, is_pending) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("iiii", $group_id, $current_user, $meme_id, $gx_hold);
            $stmt->execute();
            $gx_new_gp_id = (int)$stmt->insert_id;
            $stmt->close();

            /* moderation follow-up: remember the post time for slow mode; a banned word in "report" mode sends the post to Reported Posts */
            if ($gx_new_gp_id > 0 && function_exists('gcm_slowmode_record')) {
                if (!$is_owner && !$is_admin) gcm_slowmode_record($conn, $group_id, $current_user);
                if ($gx_report_word !== null && $gx_report_word !== '') {
                    try {
                        $gx_rr = 'Auto-moderation'; $gx_rd = 'Contains a banned word: ' . $gx_report_word;
                        $st = $conn->prepare("INSERT INTO group_post_reports (group_id, group_post_id, meme_id, reported_by, reported_user_id, reason, description) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $st->bind_param("iiiiiss", $group_id, $gx_new_gp_id, $meme_id, $current_user, $current_user, $gx_rr, $gx_rd);
                        $st->execute(); $st->close();
                    } catch (Throwable $e) { /* the post stays published; it just is not flagged */ }
                }
            }

            header("Location: group.php?id=$group_id"); exit();
        }
    }
}

// ─── MEMBER COUNT ─────────────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM group_members gm JOIN users u ON u.id = gm.user_id WHERE gm.group_id=?" . $DZ_NOT_DEACT_U);
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
        WHERE jr.group_id = ? AND jr.status = 'pending' $DZ_NOT_DEACT_U
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

$members_sql = "SELECT DISTINCT u.id, u.username, u.profile_pic FROM group_members gm JOIN users u ON u.id=gm.user_id WHERE gm.group_id=?" . $DZ_NOT_DEACT_U;
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

$members = false;   /* the full member list isn't used on this page (the members strip/modal load their own), so it's no longer queried on every visit */

// ─── ADMINS LIST ──────────────────────────────────────────────────────────────
$admins = [];
$stmt = $conn->prepare("SELECT u.id, u.username, u.profile_pic FROM group_admins ga JOIN users u ON u.id=ga.user_id WHERE ga.group_id=?" . $DZ_NOT_DEACT_U);
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
WHERE gp.group_id=? AND gp.is_pending = 0 $DZ_NOT_ARCH $DZ_NOT_DEACT_U
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
$gp_feed_cap = 400;   /* newest posts sent with the page — keeps big pods fast (raise if you want more) */
$post_sql .= " ORDER BY gp.created_at DESC LIMIT " . (int)$gp_feed_cap;

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
        SELECT gp2.meme_id, u2.id, u2.username, u2.profile_pic, 'like' AS action_type, l.created_at AS acted_at
        FROM likes l
        JOIN users u2 ON u2.id = l.user_id
        JOIN followers f ON f.following_id = u2.id
        JOIN group_posts gp2 ON gp2.meme_id = l.meme_id
        WHERE gp2.group_id = ? AND f.follower_id = ? AND u2.id != ?

        UNION ALL

        SELECT gp2.meme_id, u2.id, u2.username, u2.profile_pic, 'repost' AS action_type, r.created_at AS acted_at
        FROM reposts r
        JOIN users u2 ON u2.id = r.user_id
        JOIN followers f ON f.following_id = u2.id
        JOIN group_posts gp2 ON gp2.meme_id = r.meme_id
        WHERE gp2.group_id = ? AND f.follower_id = ? AND u2.id != ?

        UNION ALL

        /* my own reposts take a slot in the strip too */
        SELECT gp2.meme_id, u2.id, u2.username, u2.profile_pic, 'repost' AS action_type, r.created_at AS acted_at
        FROM reposts r
        JOIN users u2 ON u2.id = r.user_id
        JOIN group_posts gp2 ON gp2.meme_id = r.meme_id
        WHERE gp2.group_id = ? AND r.user_id = ?
    ";
    $stmt = $conn->prepare($sp_sql);
    $stmt->bind_param("iiiiiiii", $group_id, $current_user, $current_user, $group_id, $current_user, $current_user, $group_id, $current_user);
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
        $group_social_proof_map[$mid] = dz_sp_sort($rows, (int)$mid);
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


/* ════════════ 3. PAGE PRELUDE + HTML ════════════ */
/* ── one-time index setup: runs once, then a single file_exists() per request ── */
if (!file_exists(__DIR__ . '/group_schema_v1.flag') && file_exists(__DIR__ . '/group_setup.php')) {
    include_once __DIR__ . '/group_setup.php';
}

/* ── prelude: only derives display values from vars your PHP already made ── */
$gp_desc_raw   = trim((string)($group['description'] ?? ''));
$gp_words      = preg_split('/\s+/', $gp_desc_raw, -1, PREG_SPLIT_NO_EMPTY);
$gp_desc_long  = count($gp_words) > 19;
$gp_desc_short = $gp_desc_long ? implode(' ', array_slice($gp_words, 0, 19)) : $gp_desc_raw;

$gp_locked_member = !($is_member || $is_owner || $is_admin);            // You / Events / Files
/* age gate: only for 18+ / NSFW pods you have NOT joined yet — gone for good once you join.
   Always computed here (it deliberately overrides any older $show_age_gate from the old code). */
$gp_is_adult_pod = false;
foreach (['is_adult', 'is_nsfw', 'nsfw', 'adult', 'is_18plus', 'is_18', 'age_restricted'] as $gp_ak) {
    if (isset($group[$gp_ak]) && !in_array(strtolower((string)$group[$gp_ak]), ['', '0', 'no', 'false', 'null'], true)) { $gp_is_adult_pod = true; break; }
}
if (!$gp_is_adult_pod && !empty($pod_category) && preg_match('/nsfw|18\+|adult/i', (string)$pod_category)) { $gp_is_adult_pod = true; }
$show_age_gate = $gp_is_adult_pod && !($is_member || $is_owner || $is_admin);
$gp_locked_more   = $is_private_group && $gp_locked_member;             // See more
/* ── JOIN QUESTIONS (private pods you haven't joined) — needs join_questions_helper.php next to group.php ── */
$gp_jq_list = []; $gp_jq_err = '';
if (!empty($_SESSION['jq_error'])) { $gp_jq_err = (string)$_SESSION['jq_error']; unset($_SESSION['jq_error']); }
if (file_exists(__DIR__ . '/join_questions_helper.php')) {
    include_once __DIR__ . '/join_questions_helper.php';
    if (function_exists('jq_ensure_schema') && !file_exists(__DIR__ . '/group_jq_schema.flag')) {
        try { jq_ensure_schema($conn); @file_put_contents(__DIR__ . '/group_jq_schema.flag', date('c')); } catch (Throwable $e) {}
    }
    if (function_exists('jq_fetch') && $is_private_group && $gp_locked_member && $my_request_status === 'pending') {
        try {
            $gp_jq_rows = jq_fetch($conn, $group_id);
            if (is_array($gp_jq_rows)) foreach ($gp_jq_rows as $gp_jq_r) {
                $gp_qid = (int)($gp_jq_r['id'] ?? 0); $gp_qt = '';
                foreach (['question', 'text', 'label', 'title', 'q'] as $gp_k) { if (!empty($gp_jq_r[$gp_k])) { $gp_qt = (string)$gp_jq_r[$gp_k]; break; } }
                if ($gp_qid > 0 && $gp_qt !== '') $gp_jq_list[] = ['id' => $gp_qid, 'q' => $gp_qt, 'req' => !empty($gp_jq_r['required']) || !empty($gp_jq_r['is_required'])];
            }
        } catch (Throwable $e) { $gp_jq_list = []; }
    }
}
$gp_jq_has  = !empty($gp_jq_list);
$gp_jq_done = !empty($_SESSION['jq_done_' . (int)$group_id]);   /* set by group.php after the answers were saved */
$gp_jq_box  = $gp_jq_has && $is_private_group && $gp_locked_member && $my_request_status === 'pending';
if (!function_exists('gp_jq_modal')) {
    /* the "Join request" modal — sits INSIDE the join form so the answers (jq[id]) are submitted with it */
    function gp_jq_modal($list, $err) {
        echo '<form method="POST" id="gpJqForm" class="gpjq-form"><div id="gpJqModal" role="dialog" aria-modal="true" aria-label="Answer questions"><div class="gpjq-lane">'
           . '<div class="gpjq-top"><div class="gpjq-side"><button type="button" class="gpjq-x" onclick="gpJqClose()" aria-label="Close">'
           . '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button></div>'
           . '<div class="gpjq-title">Answer questions</div><div class="gpjq-side right"></div></div>'
           . '<div class="gpjq-body"><div class="gpjq-note">Answer a few questions to help the group admins review your request to join.</div>';
        if ($err !== '') echo '<div class="gpjq-err" id="gpJqErr"><i class="ri-error-warning-line"></i> ' . htmlspecialchars($err) . '</div>';
        else echo '<div class="gpjq-err gp-off" id="gpJqErr"></div>';
        foreach ($list as $q) {
            echo '<div class="gpjq-q"><label for="gpjq_' . (int)$q['id'] . '"><span>' . htmlspecialchars($q['q']) . '</span>'
               . '<em class="' . ($q['req'] ? 'req' : 'opt') . '">' . ($q['req'] ? 'Required' : 'Optional') . '</em></label>'
               . '<textarea id="gpjq_' . (int)$q['id'] . '" name="jq[' . (int)$q['id'] . ']" rows="2" maxlength="500" data-req="' . ($q['req'] ? '1' : '0') . '" placeholder="Your answer…"></textarea></div>';
        }
        echo '</div><div class="gpjq-bar"><button type="button" class="gpjq-btn ghost" onclick="gpJqClose()">Cancel</button>'
           . '<button type="submit" name="submit_join_answers" value="1" class="gpjq-btn" onclick="return gpJqCheck()"><i class="ri-send-plane-fill"></i> Send answers</button></div>'
           . '</div></div></form>';
    }
}
$gp_private_locked = $gp_locked_more;                                   // private pod + not in it yet → show the "what is a private pod" explainer instead of an empty feed
$gp_paused_lock   = !empty($group_is_paused) && !($is_owner || $is_admin);
$gp_pause_msg     = 'This pod is paused — check back later';
$gp_pause_visible = !empty($group_is_paused) && $can_view_content;

/* files + upcoming events in the feed follow the same access rules as the Files / Events pages */
$gp_extras_ok = !empty($can_view_content) && ($is_member || $is_owner || $is_admin) && !$gp_paused_lock;

/* dismiss key is tied to THIS pause (paused_at), so a new pause shows the block again */
$gp_pause_key = !empty($group_is_paused)
    ? 'gp_pause_dismissed_' . (int)$group_id . '_' . (string)($group['paused_at'] ?? '')
    : '';

$gp_report_count = 0;
if ($is_owner || $is_admin || $is_mod) {
    $gp_report_count = gx_int($conn, "SELECT COUNT(*) c FROM group_post_reports WHERE group_id=? AND status='pending'", 'i', [$group_id]);
}
$gp_invite_locked = !empty($group_is_paused) && !($is_owner || $is_admin);

/* ── label for the sticky top-bar Join pill (mirrors the page's main join button) ── */
$gp_pill_label = 'Join Pod';
$gp_pill_class = '';
if ($is_private_group) {
    if ($my_request_status === 'pending')     { $gp_pill_label = 'Requested'; $gp_pill_class = ' pending'; }
    elseif ($my_request_status === 'denied')  { $gp_pill_label = 'Retry'; }
    else                                      { $gp_pill_label = 'Request'; }
}

/* banner stats (same queries as the old page) */
$_v = gx_q($conn, "SELECT 1 FROM group_views WHERE group_id=? AND viewer_id=? AND viewed_at>=CURDATE() LIMIT 1", 'ii', [$group_id, $current_user]);
if ($_v && $_v->num_rows === 0) gx_exec($conn, "INSERT INTO group_views (group_id, viewer_id) VALUES (?, ?)", 'ii', [$group_id, $current_user]);
$visitors_week = gx_int($conn, "SELECT COUNT(DISTINCT viewer_id) t FROM group_views WHERE group_id=? AND viewed_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)", 'i', [$group_id]);
$contributions_week = gx_int($conn, "SELECT (SELECT COUNT(*) FROM group_posts WHERE group_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)) + (SELECT COUNT(*) FROM pod_discussion_replies pdr JOIN pod_discussions pd ON pd.id=pdr.discussion_id WHERE pd.group_id=? AND pdr.created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)) t", 'ii', [$group_id, $group_id]);
$total_posts_group = gx_int($conn, "SELECT COUNT(*) c FROM group_posts WHERE group_id=? AND is_pending=0", 'i', [$group_id]);

/* ══════════════════════════════════════════════════════════════
   EXTRAS (read side): pinned posts · weekly challenge · catch-me-up · admin insights
   Everything is wrapped so a missing table just means "nothing to show".
   ══════════════════════════════════════════════════════════════ */
$gx_staff   = ($is_owner || $is_admin);
$gx_member  = ($is_member || $is_owner || $is_admin);
$gx_can_see = !empty($can_view_content) && empty($gp_private_locked) && empty($show_age_gate);
$gx_pins = []; $gx_challenge = null; $gx_catch = null; $gx_ins = null;
gx_ensure_schema($conn);
if ($gx_can_see) {
    try {
        /* pinned posts (max 3) */
        $r = gx_q($conn, "SELECT meme_id FROM group_post_pins WHERE group_id=? ORDER BY id DESC LIMIT 3", 'i', [$group_id]);
        if (!$r) $r = gx_q($conn, "SELECT meme_id FROM group_post_pins WHERE group_id=? LIMIT 3", 'i', [$group_id]);
        if ($r) while ($x = $r->fetch_assoc()) $gx_pins[] = (int)$x['meme_id'];

        /* weekly challenge */
        $c = gx_row($conn, "SELECT id, title, description, hashtag, starts_at, ends_at, TIMESTAMPDIFF(SECOND, NOW(), ends_at) AS secs_left
                            FROM group_challenges WHERE group_id=? AND is_active=1 AND ends_at>NOW() ORDER BY id DESC LIMIT 1", 'i', [$group_id]);
        if ($c) {
            $like = ($c['hashtag'] !== '') ? '%#' . $c['hashtag'] . '%' : '%';
            $e = gx_row($conn, "SELECT COUNT(*) AS n, COUNT(DISTINCT gp.posted_by) AS people
                                FROM group_posts gp JOIN memes m ON m.id = gp.meme_id
                                WHERE gp.group_id=? AND gp.is_pending = 0 AND gp.created_at>=? AND m.caption LIKE ?" . $DZ_NOT_ARCH, 'iss', [$group_id, $c['starts_at'], $like]);
            $c['entries'] = (int)($e['n'] ?? 0);
            $c['people']  = (int)($e['people'] ?? 0);
            $c['secs_left'] = max(0, (int)$c['secs_left']);
            $gx_challenge = $c;
        }

        /* catch me up: what happened since this member's previous visit (a visit = 30+ minutes after the last one) */
        if ($gx_member) {
            $ls = gx_row($conn, "SELECT last_seen, prev_seen, TIMESTAMPDIFF(MINUTE, last_seen, NOW()) AS mins FROM group_last_seen WHERE group_id=? AND user_id=?", 'ii', [$group_id, $current_user]);
            $prev = null;
            if (!$ls) {
                gx_exec($conn, "INSERT INTO group_last_seen (group_id, user_id, last_seen, prev_seen) VALUES (?, ?, NOW(), NULL)", 'ii', [$group_id, $current_user]);
            } else {
                if ((int)$ls['mins'] >= 30) {
                    gx_exec($conn, "UPDATE group_last_seen SET prev_seen=last_seen, last_seen=NOW() WHERE group_id=? AND user_id=?", 'ii', [$group_id, $current_user]);
                    $prev = $ls['last_seen'];
                } else {
                    $prev = $ls['prev_seen'];
                }
            }
            if ($prev) {
                $hrs = gx_int($conn, "SELECT TIMESTAMPDIFF(HOUR, ?, NOW())", 's', [$prev]);
                if ($hrs >= 1) {
                    $np = gx_int($conn, "SELECT COUNT(*) FROM group_posts WHERE group_id=? AND created_at>? AND posted_by<>?", 'isi', [$group_id, $prev, $current_user]);
                    $nm = 0;
                    foreach (['joined_at', 'created_at'] as $mcol) {
                        $rr = gx_q($conn, "SELECT COUNT(*) AS c FROM group_members WHERE group_id=? AND $mcol>? AND user_id<>?", 'isi', [$group_id, $prev, $current_user]);
                        if ($rr) { $nm = (int)($rr->fetch_assoc()['c'] ?? 0); break; }
                    }
                    $ne = gx_int($conn, "SELECT COUNT(*) FROM group_events WHERE group_id=? AND created_at>?", 'is', [$group_id, $prev]);
                    if ($np > 0) $gx_catch = ['hours' => $hrs, 'posts' => $np, 'members' => $nm, 'events' => $ne, 'key' => md5((string)$prev)];
                }
            }
        }

        /* admin insights */
        if ($gx_staff) {
            $vis = []; $pst = [];
            $r = gx_q($conn, "SELECT DATE(viewed_at) d, COUNT(DISTINCT viewer_id) c FROM group_views WHERE group_id=? AND viewed_at>=DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY DATE(viewed_at)", 'i', [$group_id]);
            if ($r) while ($x = $r->fetch_assoc()) $vis[$x['d']] = (int)$x['c'];
            $r = gx_q($conn, "SELECT DATE(created_at) d, COUNT(*) c FROM group_posts WHERE group_id=? AND created_at>=DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY DATE(created_at)", 'i', [$group_id]);
            if ($r) while ($x = $r->fetch_assoc()) $pst[$x['d']] = (int)$x['c'];
            $days = [];
            for ($i = 6; $i >= 0; $i--) {
                $d = date('Y-m-d', strtotime("-$i day"));
                $days[] = ['l' => date('D', strtotime($d)), 'v' => $vis[$d] ?? 0, 'p' => $pst[$d] ?? 0];
            }
            $nm = 0;
            foreach (['joined_at', 'created_at'] as $mcol) {
                $rr = gx_q($conn, "SELECT COUNT(*) AS c FROM group_members WHERE group_id=? AND $mcol>=DATE_SUB(NOW(), INTERVAL 7 DAY)", 'i', [$group_id]);
                if ($rr) { $nm = (int)($rr->fetch_assoc()['c'] ?? 0); break; }
            }
            $tc = gx_row($conn, "SELECT u.username, COUNT(*) c FROM group_posts gp JOIN users u ON u.id=gp.posted_by WHERE gp.group_id=? AND gp.created_at>=DATE_SUB(NOW(), INTERVAL 7 DAY)" . $DZ_NOT_DEACT_U . " GROUP BY gp.posted_by, u.username ORDER BY c DESC LIMIT 1", 'i', [$group_id]);
            $tp = gx_row($conn, "SELECT gp.meme_id, COUNT(*) c FROM group_posts gp JOIN likes l ON l.meme_id=gp.meme_id WHERE gp.group_id=? AND gp.created_at>=DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY gp.meme_id ORDER BY c DESC LIMIT 1", 'i', [$group_id]);
            $gx_ins = [
                'days'        => $days,
                'visitors'    => (int)$visitors_week,
                'visitors_prev' => gx_int($conn, "SELECT COUNT(DISTINCT viewer_id) FROM group_views WHERE group_id=? AND viewed_at>=DATE_SUB(NOW(), INTERVAL 14 DAY) AND viewed_at<DATE_SUB(NOW(), INTERVAL 7 DAY)", 'i', [$group_id]),
                'posts'       => gx_int($conn, "SELECT COUNT(*) FROM group_posts WHERE group_id=? AND created_at>=DATE_SUB(NOW(), INTERVAL 7 DAY)", 'i', [$group_id]),
                'posts_prev'  => gx_int($conn, "SELECT COUNT(*) FROM group_posts WHERE group_id=? AND created_at>=DATE_SUB(NOW(), INTERVAL 14 DAY) AND created_at<DATE_SUB(NOW(), INTERVAL 7 DAY)", 'i', [$group_id]),
                'new_members' => $nm,
                'top_user'    => $tc ? ['name' => $tc['username'], 'n' => (int)$tc['c']] : null,
                'top_post'    => $tp ? ['id' => (int)$tp['meme_id'], 'likes' => (int)$tp['c']] : null,
                'requests'    => gx_int($conn, "SELECT COUNT(*) FROM group_join_requests WHERE group_id=? AND status='pending'", 'i', [$group_id]),
                'reports'     => (int)$gp_report_count,
            ];
        }
    } catch (Throwable $e) {}
}

/* ══════════════════════════════════════════════════════════════
   RIGHT-HAND COLUMN CARDS (laptop / normal view) — shown ABOVE the
   all-posts grid: Pod info (the 5 most important facts), Admins,
   Universal pod rules, and the owner's own rules (guidelines).
   Only for people who are allowed to see the pod's content.
   ══════════════════════════════════════════════════════════════ */
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

$gp_side_ok       = true;   /* Pod info + rules show even for private pods you haven't joined */
$gp_side_admins   = [];
$gp_side_creator  = ['id' => (int)($group['created_by'] ?? 0), 'name' => ''];
$gp_side_members  = 0;
$gp_name_changes  = 0;
$gp_side_created  = !empty($group['created_at']) ? date('F j, Y', strtotime($group['created_at'])) : '—';
$gp_guidelines    = trim((string)($group['custom_guidelines'] ?? ''));

if ($gp_side_ok) {
    try {
        $r = !empty($can_view_content) ? gx_q($conn, "SELECT u.id, u.username, u.profile_pic FROM group_admins ga JOIN users u ON u.id = ga.user_id WHERE ga.group_id = ?", 'i', [(int)$group_id]) : null;
        if ($r instanceof mysqli_result) while ($x = $r->fetch_assoc()) $gp_side_admins[] = $x;
    } catch (Throwable $e) { $gp_side_admins = []; }
    try {
        $r = gx_q($conn, "SELECT username FROM users WHERE id = ? LIMIT 1", 'i', [(int)$gp_side_creator['id']]);
        if ($r instanceof mysqli_result && ($x = $r->fetch_assoc())) $gp_side_creator['name'] = (string)$x['username'];
    } catch (Throwable $e) {}
    try {
        $r = gx_q($conn, "SELECT COUNT(*) c FROM group_members gm JOIN users u ON u.id = gm.user_id WHERE gm.group_id = ?" . $DZ_NOT_DEACT_U, 'i', [(int)$group_id]);
        if ($r instanceof mysqli_result) $gp_side_members = (int)($r->fetch_assoc()['c'] ?? 0);
    } catch (Throwable $e) {}
    try {
        $r = gx_q($conn, "SELECT COUNT(*) c FROM group_name_history WHERE group_id = ?", 'i', [(int)$group_id]);
        if ($r instanceof mysqli_result) $gp_name_changes = (int)($r->fetch_assoc()['c'] ?? 0);
    } catch (Throwable $e) { $gp_name_changes = 0; }
}

/* ── Pod Highlights (created in Group Controls → Group Highlights & Polls). ── */
$gp_hl_text_bg = 'Text.jpg';
$gp_highlights = [];
if (!empty($can_view_content)) {
    try {
        $gp_hl_stmt = $conn->prepare("SELECT h.id, h.title, h.cover_path, h.song_path,
            (SELECT COUNT(*) FROM group_highlight_media m WHERE m.highlight_id = h.id) AS media_count,
            (SELECT m2.file_path FROM group_highlight_media m2 WHERE m2.highlight_id = h.id ORDER BY m2.id ASC LIMIT 1) AS first_path,
            (SELECT m3.media_type FROM group_highlight_media m3 WHERE m3.highlight_id = h.id ORDER BY m3.id ASC LIMIT 1) AS first_type,
            (SELECT m4.caption FROM group_highlight_media m4 WHERE m4.highlight_id = h.id ORDER BY m4.id ASC LIMIT 1) AS first_caption,
            (SELECT gp.question FROM group_highlight_polls gp WHERE gp.highlight_id = h.id ORDER BY gp.media_id ASC LIMIT 1) AS first_poll_q
            FROM group_highlights h WHERE h.group_id = ? HAVING media_count > 0 ORDER BY h.created_at DESC, h.id DESC");
        if ($gp_hl_stmt) {
            $gp_hl_stmt->bind_param("i", $group_id);
            $gp_hl_stmt->execute();
            $gp_hl_res = $gp_hl_stmt->get_result();
            while ($gp_hl_row = $gp_hl_res->fetch_assoc()) $gp_highlights[] = $gp_hl_row;
            $gp_hl_stmt->close();
        }
    } catch (Throwable $e) {
        $gp_highlights = [];
    }
}
$gp_hl_count = count($gp_highlights);

/* ══════════════════════════════════════════════════════════════
   UPCOMING EVENTS (shown after Pod Highlights, before the first post).
   Same "not ended yet" rule as group_events.php: full date + time
   compared against Africa/Lagos time. Soonest first.
   ══════════════════════════════════════════════════════════════ */
$gp_events = [];
if ($gp_extras_ok) {
    try {
        $gp_now_lagos = (new DateTime('now', new DateTimeZone('Africa/Lagos')))->format('Y-m-d H:i:s');
        $st = $conn->prepare("SELECT id, event_name, event_image, event_date, event_time, location_type
                              FROM group_events
                              WHERE group_id = ? AND TIMESTAMP(event_date, event_time) > ?
                              ORDER BY event_date ASC, event_time ASC LIMIT 20");
        if ($st) {
            $st->bind_param("is", $group_id, $gp_now_lagos);
            $st->execute();
            $rs = $st->get_result();
            while ($r = $rs->fetch_assoc()) $gp_events[] = $r;
            $st->close();
        }
    } catch (Throwable $e) { $gp_events = []; }
}
$gp_ev_count = count($gp_events);

/* ══════════════════════════════════════════════════════════════
   FEED DATA (moved here from the old HTML section)
   ══════════════════════════════════════════════════════════════ */
$group_feed_rows = [];
if (!empty($posts)) {
    $posts->data_seek(0);
    while ($p = $posts->fetch_assoc()) { $group_feed_rows[] = $p; }
    $posts->data_seek(0);
}

$_gf_follow_map = [];
if ($current_user && !empty($group_feed_rows)) {
    $gf_uids = array_unique(array_column($group_feed_rows, 'user_id'));
    if (!empty($gf_uids)) {
        $gf_uid_list = array_values(array_map('intval', $gf_uids));
        $gf_follow_res = gx_q($conn, "SELECT following_id FROM followers WHERE follower_id=? AND following_id IN (" . implode(',', array_fill(0, count($gf_uid_list), '?')) . ")",
                              str_repeat('i', count($gf_uid_list) + 1), array_merge([$current_user], $gf_uid_list));
        if ($gf_follow_res) {
            while ($r = $gf_follow_res->fetch_assoc()) $_gf_follow_map[(int)$r['following_id']] = true;
        }
    }
}

$_gf_media_map = [];
$_gf_audio_map = [];
$_gf_ai_map    = [];
if (!empty($group_feed_rows)) {
    $gf_meme_id_str = implode(',', array_map('intval', array_unique(array_column($group_feed_rows, 'meme_id'))));
    dzx_prime_hide($conn, array_map('intval', array_unique(array_column($group_feed_rows, 'meme_id'))));   /* hidden-count flags for the whole feed in ONE query */

    $mm_res = $conn->query("SELECT meme_id, file_name, type, position FROM meme_media WHERE meme_id IN ($gf_meme_id_str) ORDER BY meme_id ASC, position ASC");
    if ($mm_res) {
        while ($row = $mm_res->fetch_assoc()) {
            $_gf_media_map[(int)$row['meme_id']][] = ['file' => $row['file_name'], 'type' => $row['type']];
        }
    }

    /* AI-generated flag (same flag home.php uses) */
    try {
        $ai_res = $conn->query("SELECT id, is_ai_generated FROM memes WHERE id IN ($gf_meme_id_str)");
        if ($ai_res) while ($r = $ai_res->fetch_assoc()) $_gf_ai_map[(int)$r['id']] = !empty($r['is_ai_generated']);
    } catch (Throwable $e) { $_gf_ai_map = []; }

    $au_res = $conn->query("
        SELECT ma.meme_id, a.id AS audio_id, a.title, a.artist, a.file_name,
               ma.is_original, ma.music_volume, ma.video_audio_volume
        FROM meme_audio ma
        JOIN audios a ON a.id = ma.audio_id
        WHERE ma.meme_id IN ($gf_meme_id_str)
    ");
    if ($au_res) while ($au = $au_res->fetch_assoc()) $_gf_audio_map[(int)$au['meme_id']] = $au;

    foreach ($group_feed_rows as $grow) {
        $gmid = (int)$grow['meme_id'];
        if (($grow['type'] ?? '') !== 'video') continue;
        if (isset($_gf_audio_map[$gmid])) continue;

        $existing = gx_row($conn, "SELECT id FROM audios WHERE source_meme_id=? LIMIT 1", 'i', [$gmid]);
        if ($existing) {
            $aid = (int)$existing['id'];
        } else {
            $uploader_id = (int)$grow['user_id'];
            $file_name   = (string)$grow['file_name'];
            $aid = 0;
            if (gx_exec($conn, "INSERT INTO audios (uploader_id, title, file_name, is_original, source_meme_id, created_at) VALUES (?, 'Original audio', ?, 1, ?, NOW())", 'isi', [$uploader_id, $file_name, $gmid])) {
                $aid = (int)$conn->insert_id;
            }
            if ($aid) {
                gx_exec($conn, "INSERT INTO meme_audio (meme_id, audio_id, is_original, music_volume, video_audio_volume) VALUES (?, ?, 1, 100, 100)", 'ii', [$gmid, $aid]);
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

/* build the JSON the feed (and the old gf modal) reads */
$gp_feed_out = [];
foreach ($group_feed_rows as $p) {
    $mid   = (int)$p['meme_id'];
    $mtype = $p['type'] ?? 'image';
    $mfile = !empty($p['file_name']) ? 'uploads/' . $p['file_name'] : '';
    $mpic  = !empty($p['profile_pic']) ? $p['profile_pic'] : 'mee.jpg';
    $muid  = (int)$p['user_id'];
    $au    = $_gf_audio_map[$mid] ?? null;

    $media = [];
    if ($mfile !== '') $media[] = ['file' => $mfile, 'type' => $mtype];
    if (!empty($_gf_media_map[$mid])) {
        foreach ($_gf_media_map[$mid] as $extra) $media[] = ['file' => 'uploads/' . $extra['file'], 'type' => $extra['type']];
    }

    $sp = array_map(function ($s) use ($conn) {
        return ['id' => (int)$s['id'], 'username' => $s['username'], 'profile_pic' => $s['profile_pic'], 'action_type' => $s['action_type'], 'isVerified' => dz_vb_flag($conn, (int)$s['id'])];
    }, $group_social_proof_map[$mid] ?? []);

    $gp_feed_out[] = dzx_apply_hide([
        'online'          => gx_user_online($conn, $muid, (int)$current_user),
        'id'              => $mid,
        'groupPostId'     => (int)$p['group_post_id'],
        'file'            => $mfile,
        'thumb'           => (function_exists('dz_thumb_url') && $mfile !== '' && in_array($mtype, ['image','photo','meme'], true)) ? dz_thumb_url($mfile, 480) : '',
        'type'            => $mtype,
        'media'           => $media,
        'pic'             => $mpic,
        'picT'            => function_exists('dz_thumb_url') ? dz_thumb_url($mpic, 96) : $mpic,
        'userId'          => (string)$muid,
        'username'        => htmlspecialchars($p['username']),
        'isVerified'      => dz_vb_flag($conn, (int)$muid),
        'nickname'        => htmlspecialchars($p['nickname'] ?? ''),
        'caption'         => (string)$p['caption'],
        'time'            => $p['post_created'],
        'likeCount'       => (int)$p['like_count'],
        'repostCount'     => (int)$p['repost_count'],
        'favCount'        => (int)$p['favorites_count'],
        'shareCount'      => (int)$p['share_count'],
        'downloadCount'   => (int)($p['download_count'] ?? 0),
        'liked'           => (bool)$p['is_liked'],
        'reposted'        => (bool)$p['is_reposted'],
        'faved'           => (bool)$p['is_favorited'],
        'following'       => !empty($_gf_follow_map[$muid]),
        'isFollowing'     => !empty($_gf_follow_map[$muid]),   /* the shared modal (zeems_modal.php) reads this name */
        'isAdmin'         => isset($admins[$muid]),
        'isPartnership'   => !empty($p['is_partnership']),
        'partnerBrand'    => !empty($p['partner_brand']) ? $p['partner_brand'] : null,
        'isAiGenerated'   => !empty($_gf_ai_map[$mid]),
        'connectedMemeId' => !empty($p['connected_meme_id']) ? (int)$p['connected_meme_id'] : null,
        'connectedLabel'  => !empty($p['connected_label']) ? $p['connected_label'] : null,
        'audioId'         => $au ? (int)$au['audio_id'] : null,
        'audioTitle'      => $au ? $au['title'] : null,
        'audioArtist'     => ($au && !empty($au['artist'])) ? $au['artist'] : null,
        'allowDownload' => (int)($p['allow_download'] ?? 1),
        'collab'          => dz_gp_collab($conn, (int)$mid, $current_user),
        'poll'            => dz_gp_poll($conn, (int)$mid, $current_user),
        'audioFile'       => $au ? 'uploads/' . $au['file_name'] : null,
        'audioIsOriginal' => ($au && !empty($au['is_original'])),
        'musicVolume'     => $au ? (int)$au['music_volume'] : 80,
        'videoAudioVolume'=> $au ? (int)$au['video_audio_volume'] : 40,
        'socialProof'     => $sp,
        'socialSpare'     => dz_sp_spare((int)$mid),
    ], $conn, $mid, $current_user);
}
$gp_json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE;

/* ══════════════════════════════════════════════════════════════
   GROUP FILES → feed items. Merged with posts by time on the client,
   so new files sit above and older files sit lower, just like posts.
   ══════════════════════════════════════════════════════════════ */
if (!function_exists('gp_file_icon')) {
    function gp_file_icon($ext) {
        $ext = strtolower($ext);
        $map = [
            'pdf'  => ['ri-file-pdf-2-fill', '#f04747'],
            'doc'  => ['ri-file-word-2-fill', '#3b82f6'],  'docx' => ['ri-file-word-2-fill', '#3b82f6'],  'odt' => ['ri-file-word-2-fill', '#3b82f6'],
            'xls'  => ['ri-file-excel-2-fill', '#22c55e'], 'xlsx' => ['ri-file-excel-2-fill', '#22c55e'], 'csv' => ['ri-file-excel-2-fill', '#22c55e'],
            'ppt'  => ['ri-file-ppt-2-fill', '#f97316'],   'pptx' => ['ri-file-ppt-2-fill', '#f97316'],
            'zip'  => ['ri-file-zip-fill', '#eab308'],     'rar'  => ['ri-file-zip-fill', '#eab308'],     '7z'  => ['ri-file-zip-fill', '#eab308'],
            'txt'  => ['ri-file-text-fill', '#94a3b8'],
            'jpg'  => ['ri-image-2-fill', '#ec4899'],      'jpeg' => ['ri-image-2-fill', '#ec4899'],      'png' => ['ri-image-2-fill', '#ec4899'],
            'gif'  => ['ri-image-2-fill', '#ec4899'],      'webp' => ['ri-image-2-fill', '#ec4899'],
            'mp3'  => ['ri-file-music-fill', '#a855f7'],   'mp4'  => ['ri-file-video-fill', '#a855f7'],
        ];
        return $map[$ext] ?? ['ri-file-fill', '#7fa0ff'];
    }
}
if (!function_exists('gp_fmt_size')) {
    function gp_fmt_size($bytes) {
        $bytes = (int)$bytes;
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024)    return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }
}

$gp_files_out = [];
if ($gp_extras_ok) {
    try {
        $fx = '';
        foreach ([$blocked_sql ?? '', $group_banned_sql ?? '', $viewer_excluded_sql ?? ''] as $lst) {
            if (!empty($lst)) $fx .= " AND gf.uploaded_by NOT IN ($lst)";
        }
        $fr = $conn->query("SELECT gf.id, gf.title, gf.stored_name, gf.file_ext, gf.file_size, gf.is_pinned, gf.created_at,
                                   u.id AS uid, u.username, u.profile_pic
                            FROM group_files gf
                            JOIN users u ON u.id = gf.uploaded_by
                            WHERE gf.group_id = " . (int)$group_id . $fx . $DZ_NOT_DEACT_U . "
                            ORDER BY gf.created_at DESC LIMIT 60");
        $gp_f_rows = []; $gp_f_ids = [];
        if ($fr) while ($r = $fr->fetch_assoc()) { $gp_f_rows[] = $r; $gp_f_ids[] = (int)$r['id']; }

        $gp_f_saved = [];
        if (!empty($gp_f_ids)) {
            $sr = $conn->query("SELECT file_id FROM group_file_saves WHERE user_id=" . (int)$current_user . " AND file_id IN (" . implode(',', $gp_f_ids) . ")");
            if ($sr) while ($x = $sr->fetch_assoc()) $gp_f_saved[(int)$x['file_id']] = true;
        }

        foreach ($gp_f_rows as $r) {
            [$ic, $col] = gp_file_icon($r['file_ext']);
            $is_img = in_array(strtolower($r['file_ext']), ['jpg','jpeg','png','gif','webp'], true);
            $gp_files_out[] = [
                'id'       => (int)$r['id'],
                'title'    => (string)$r['title'],
                'ext'      => strtoupper((string)$r['file_ext']),
                'size'     => gp_fmt_size($r['file_size']),
                'icon'     => $ic,
                'color'    => $col,
                'thumb'    => $is_img ? 'uploads/files/' . $r['stored_name'] : null,
                'pinned'   => !empty($r['is_pinned']),
                'saved'    => !empty($gp_f_saved[(int)$r['id']]),
                'userId'   => (string)(int)$r['uid'],
                'online'   => gx_user_online($conn, (int)$r['uid'], (int)$current_user),
                'username' => (string)$r['username'],
                'isVerified' => dz_vb_flag($conn, (int)$r['uid']),
                'pic'      => !empty($r['profile_pic']) ? $r['profile_pic'] : 'mee.jpg',
                'time'     => $r['created_at'],
            ];
        }
    } catch (Throwable $e) { $gp_files_out = []; }
}

/* ══════════════════════════════════════════════════════════════
   TOP CONTRIBUTOR (all-time score: posts×3 + likes + reposts×2 + saves×2).
   Blocked / banned members are excluded so they can never be shown.
   Rendered in the feed right after the FIRST item.
   ══════════════════════════════════════════════════════════════ */
$gp_top_out = null;
if (!empty($can_view_content)) {
    try {
        $gp_top_ex = '';
        if (!empty($blocked_sql))      $gp_top_ex .= " AND gp.posted_by NOT IN ($blocked_sql)";
        if (!empty($group_banned_sql)) $gp_top_ex .= " AND gp.posted_by NOT IN ($group_banned_sql)";
        $gp_te = $conn->query("
            SELECT u.id, u.username, u.profile_pic,
                   COUNT(DISTINCT gp.id) AS post_count,
                   COALESCE(SUM(lk.c), 0) AS total_likes,
                   COALESCE(SUM(rp.c), 0) AS total_reposts,
                   COALESCE(SUM(fv.c), 0) AS total_saves,
                   (COUNT(DISTINCT gp.id) * 3 + COALESCE(SUM(lk.c), 0) + COALESCE(SUM(rp.c), 0) * 2 + COALESCE(SUM(fv.c), 0) * 2) AS score
            FROM group_posts gp
            JOIN users u ON u.id = gp.posted_by
            LEFT JOIN (SELECT x.meme_id, COUNT(*) c FROM likes x JOIN group_posts g2 ON g2.meme_id = x.meme_id AND g2.group_id = $group_id GROUP BY x.meme_id) lk ON lk.meme_id = gp.meme_id
            LEFT JOIN (SELECT x.meme_id, COUNT(*) c FROM reposts x JOIN group_posts g2 ON g2.meme_id = x.meme_id AND g2.group_id = $group_id GROUP BY x.meme_id) rp ON rp.meme_id = gp.meme_id
            LEFT JOIN (SELECT x.meme_id, COUNT(*) c FROM favorites x JOIN group_posts g2 ON g2.meme_id = x.meme_id AND g2.group_id = $group_id GROUP BY x.meme_id) fv ON fv.meme_id = gp.meme_id
            WHERE gp.group_id = $group_id AND gp.is_pending = 0 $gp_top_ex $DZ_NOT_DEACT_U
            GROUP BY u.id, u.username, u.profile_pic
            ORDER BY score DESC
            LIMIT 1
        ");
        if ($gp_te && $gp_te->num_rows > 0) {
            $r = $gp_te->fetch_assoc();
            $gp_top_out = [
                'id'       => (int)$r['id'],
                'username' => $r['username'],
                'pic'      => !empty($r['profile_pic']) ? $r['profile_pic'] : 'mee.jpg',
                'posts'    => (int)$r['post_count'],
                'likes'    => (int)$r['total_likes'],
                'reposts'  => (int)$r['total_reposts'],
                'saves'    => (int)$r['total_saves'],
                'pod'      => $group['title'],
            ];
        }
    } catch (Throwable $e) { $gp_top_out = null; }
}

/* ══════════════════════════════════════════════════════════════
   SUGGESTED PODS — identical rules to the Pod Info modal:
   same category OR a shared title keyword. Never pods you're in,
   never pods you dismissed (Remove). NO filler: if nothing matches,
   the whole section simply doesn't show. (Adult pods are skipped.)
   ══════════════════════════════════════════════════════════════ */
$gp_sugg_out = [];
if (!empty($can_view_content)) {
    try {
        $gp_dismissed = [];
        $st = $conn->prepare("SELECT dismissed_group_id FROM group_suggestion_dismissed WHERE user_id=?");
        $st->bind_param("i", $current_user);
        $st->execute();
        $rs = $st->get_result();
        while ($r = $rs->fetch_assoc()) $gp_dismissed[] = (int)$r['dismissed_group_id'];
        $st->close();

        $gp_joined = [(int)$group_id];
        $st = $conn->prepare("SELECT group_id FROM group_members WHERE user_id=?");
        $st->bind_param("i", $current_user);
        $st->execute();
        $rs = $st->get_result();
        while ($r = $rs->fetch_assoc()) $gp_joined[] = (int)$r['group_id'];
        $st->close();

        $gp_ex_ids = array_values(array_unique(array_merge($gp_dismissed, $gp_joined)));
        $gp_ex_sql = implode(',', array_map('intval', $gp_ex_ids));

        $gp_fmt = function ($r) {
            return [
                'id'       => (int)$r['id'],
                'title'    => $r['title'],
                'img'      => !empty($r['group_pic'])
                                ? (strpos($r['group_pic'], 'uploads/') === 0 ? $r['group_pic'] : 'uploads/' . $r['group_pic'])
                                : 'uploads/default_group.png',
                'members'  => (int)$r['mc'],
                'category' => (string)($r['category'] ?? ''),
                'private'  => (($r['privacy'] ?? 'public') === 'private'),
            ];
        };

        $gp_stop = ['the','a','an','of','for','and','or','fan','fans','page','club','group','pod','pods','official','community','to','in','on','with','my'];
        $gp_kw = [];
        foreach (preg_split('/[^a-z0-9]+/i', strtolower($group['title'] ?? '')) as $w) {
            $w = trim($w);
            if ($w !== '' && mb_strlen($w) >= 4 && !in_array($w, $gp_stop, true)) $gp_kw[] = $w;
        }
        $gp_kw = array_slice(array_unique($gp_kw), 0, 6);

        $gp_where = []; $gp_params = []; $gp_types = '';
        if (!empty($group['category'])) { $gp_where[] = "g.category = ?"; $gp_params[] = $group['category']; $gp_types .= 's'; }
        foreach ($gp_kw as $kw) { $gp_where[] = "g.title LIKE ?"; $gp_params[] = "%$kw%"; $gp_types .= 's'; }

        if (!empty($gp_where)) {
            $sql = "SELECT g.id, g.title, g.group_pic, g.category, g.privacy,
                           (SELECT COUNT(*) FROM group_members WHERE group_id=g.id) AS mc
                    FROM groups g
                    WHERE g.id NOT IN ($gp_ex_sql)
                      AND (g.is_adult = 0 OR g.is_adult IS NULL)
                      AND (" . implode(' OR ', $gp_where) . ")
                    ORDER BY RAND() LIMIT 12";
            $st = $conn->prepare($sql);
            if ($st) {
                $st->bind_param($gp_types, ...$gp_params);
                $st->execute();
                $rs = $st->get_result();
                while ($r = $rs->fetch_assoc()) $gp_sugg_out[] = $gp_fmt($r);
                $st->close();
            }
        }
    } catch (Throwable $e) {
        error_log('group.php suggested pods: ' . $e->getMessage());
        $gp_sugg_out = [];
    }
}

/* ══════════════════════════════════════════════════════════════
   YOU MODAL DATA — the current member's own activity in THIS pod
   (posts, likes / reposts / saves received, join date)
   ══════════════════════════════════════════════════════════════ */
$cu_pic          = $current_user_pic;
$cu_posts        = [];
$cu_post_count   = 0;
$cu_total_likes  = 0;
$cu_total_reposts = 0;
$cu_total_saves  = 0;
$cu_join_date    = null;

if ($is_member) {
    try {
        $st = $conn->prepare("
            SELECT gp.id AS gp_id, gp.created_at, m.id AS meme_id, m.file_name, m.caption, m.type,
                   (SELECT COUNT(*) FROM likes     l WHERE l.meme_id = m.id) AS lc,
                   (SELECT COUNT(*) FROM reposts   r WHERE r.meme_id = m.id) AS rc,
                   (SELECT COUNT(*) FROM favorites f WHERE f.meme_id = m.id) AS sc
            FROM group_posts gp
            JOIN memes m ON m.id = gp.meme_id
            WHERE gp.group_id = ? AND gp.posted_by = ?
            ORDER BY gp.created_at DESC
        ");
        if ($st) {
            $st->bind_param("ii", $group_id, $current_user);
            $st->execute();
            $rs = $st->get_result();
            while ($r = $rs->fetch_assoc()) {
                $r['caption'] = (string)($r['caption'] ?? '');
                $cu_posts[] = $r;
                $cu_total_likes   += (int)$r['lc'];
                $cu_total_reposts += (int)$r['rc'];
                $cu_total_saves   += (int)$r['sc'];
            }
            $st->close();
        }
        $cu_post_count = count($cu_posts);
    } catch (Throwable $e) {
        error_log('group.php you modal posts: ' . $e->getMessage());
    }

    /* join date — uses whichever timestamp column your group_members table has */
    try {
        $st = $conn->prepare("SELECT * FROM group_members WHERE group_id = ? AND user_id = ? LIMIT 1");
        if ($st) {
            $st->bind_param("ii", $group_id, $current_user);
            $st->execute();
            $jr = $st->get_result()->fetch_assoc();
            $st->close();
            if ($jr) {
                foreach (['joined_at', 'created_at', 'join_date', 'date_joined'] as $col) {
                    if (!empty($jr[$col])) { $cu_join_date = $jr[$col]; break; }
                }
            }
        }
    } catch (Throwable $e) { $cu_join_date = null; }
}

/* ══════════════════════════════════════════════════════════════
   RULES GRID DATA — shown when the "Rules" button in the nav bar is
   pressed (replaces highlights / events / feed until you press "Feed").
   Universal rules + the owner's own guidelines (one card per line).
   ══════════════════════════════════════════════════════════════ */
$gp_universal_rules = [
    ['ri-focus-3-line',   "Post only content relevant to the pod's genre/theme — off-topic posts get removed immediately"],
    ['ri-links-line',     'Tag the original creator when remixing content'],
    ['ri-spam-2-line',    'No spam — repeated posting of the same meme is an instant removal'],
    ['ri-user-forbid-line','No impersonating other users or creators'],
    ['ri-emotion-line',   "Respect the pod's tone — every pod has its own culture"],
];
$gp_owner_lines = [];
if ($gp_guidelines !== '') {
    foreach (preg_split('/\r\n|\r|\n/', $gp_guidelines) as $gp_ln) {
        $gp_ln = trim($gp_ln);
        if ($gp_ln !== '') $gp_owner_lines[] = $gp_ln;
    }
}
if (!function_exists('dz_hoist_styles')) {
    function dz_hoist_styles($html) {
        if (!is_string($html) || strlen($html) < 2000) return $html;
        $he = stripos($html, '</head>');
        if ($he === false) return $html;
        $head = substr($html, 0, $he);
        $body = substr($html, $he);
        $moved = []; $out = ''; $pos = 0; $n = strlen($body);
        while ($pos < $n && preg_match('~<(script|style|template|noscript|textarea)\b|<!--~i', $body, $mm, PREG_OFFSET_CAPTURE, $pos)) {
            $start = $mm[0][1];
            $close = ($mm[0][0] === '<!--') ? '-->' : '</' . strtolower($mm[1][0]);
            $e = stripos($body, $close, $start + 1);
            if ($e === false) break;
            $gt = strpos($body, '>', $e);
            $end = ($gt === false) ? $n : $gt + 1;
            if (strtolower($mm[1][0] ?? '') === 'style') { $moved[] = substr($body, $start, $end - $start); $out .= substr($body, $pos, $start - $pos); }
            else { $out .= substr($body, $pos, $end - $pos); }
            $pos = $end;
        }
        $out .= substr($body, $pos);
        if (!$moved) return $html;
        return $head . "\n" . implode("\n", $moved) . "\n" . $out;
    }
}
/* no-flash + smaller transfer: every <style> block (the modals bundle prints its own inside the body) is moved into <head>
   before the page is sent, and the page is gzipped when the server allows it */
if (!defined('DZ_PAGE_BUFFERED') && PHP_SAPI !== 'cli') {
    define('DZ_PAGE_BUFFERED', 1);
    if (!headers_sent() && extension_loaded('zlib') && !ini_get('zlib.output_compression')) { @ob_start('ob_gzhandler'); }
    @ob_start('dz_hoist_styles');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
<title><?php echo htmlspecialchars($group['title']); ?> - Dezzme Pods</title>
<?php echo gx_og_meta_html(gx_og_build($conn, (int)$group_id, (int)$auto_open_meme_id)); /* link-preview tags (Open Graph + Twitter card) */ ?>
<link rel="preload" as="image" href="<?php echo htmlspecialchars($banner_src); ?>" fetchpriority="high">
<link rel="preload" as="image" href="<?php echo htmlspecialchars($img_src); ?>" fetchpriority="high">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<script>
const CSRF_TOKEN = <?php echo json_encode($csrf_token, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
window.DZ_CSRF = CSRF_TOKEN;   /* the comment sheet (comment_section.php) sends it with poll votes */
var gfGroupId = <?php echo (int)$group_id; ?>, gfCurrentUserId = <?php echo (int)$current_user; ?>;
var GF_AUTO_OPEN_MEME_ID = <?php echo (int)$auto_open_meme_id; ?>;
var GP_PAUSE_KEY = <?php echo json_encode($gp_pause_key, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
var GP_IS_MEMBER    = <?php echo $is_member ? 'true' : 'false'; ?>;
var GP_PAUSE_LOCKED = <?php echo $gp_paused_lock ? 'true' : 'false'; ?>;
var GP_PRIVATE_LOCKED = <?php echo $gp_private_locked ? 'true' : 'false'; ?>;
var GP_SEARCH       = <?php echo json_encode(((string)($search_query ?? '')) !== ''); ?>;
/* ═════════ CSRF — the token rides along on EVERYTHING that changes data ═════════
   forms (also forms added later) · fetch() · XMLHttpRequest · same-site only (never sent to other sites) */
(function () {
    var T = CSRF_TOKEN;
    function sameOrigin(u) {
        try { return new URL(u || '', location.href).origin === location.origin; } catch (e) { return true; }
    }
    function isPostForm(f) { return f && f.tagName === 'FORM' && String(f.method || 'get').toLowerCase() === 'post'; }
    function inject(f) {
        if (!isPostForm(f) || !sameOrigin(f.getAttribute('action') || location.href)) return;
        var ex = f.querySelector('input[name="csrf_token"]');
        if (ex) { ex.value = T; return; }
        var i = document.createElement('input');
        i.type = 'hidden'; i.name = 'csrf_token'; i.value = T; f.appendChild(i);
    }

    /* fetch */
    var _fetch = window.fetch;
    if (_fetch) {
        window.fetch = function (input, init) {
            try {
                var isReq = (typeof Request !== 'undefined') && (input instanceof Request);
                var url = isReq ? input.url : String(input || '');
                var method = String((init && init.method) || (isReq && input.method) || 'GET').toUpperCase();
                if (method !== 'GET' && method !== 'HEAD' && sameOrigin(url)) {
                    init = init || {};
                    var h = new Headers(init.headers || (isReq ? input.headers : undefined) || {});
                    if (!h.has('X-CSRF-Token')) h.set('X-CSRF-Token', T);
                    init.headers = h;
                    if (!init.credentials) init.credentials = 'same-origin';
                }
            } catch (e) {}
            return _fetch.call(this, input, init);
        };
    }

    /* XMLHttpRequest (e.g. the post-upload progress bar) */
    if (window.XMLHttpRequest) {
        var xo = XMLHttpRequest.prototype.open, xs = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.open = function (m, u) { this._gpM = String(m || 'GET').toUpperCase(); this._gpU = u; return xo.apply(this, arguments); };
        XMLHttpRequest.prototype.send = function () {
            try { if (this._gpM !== 'GET' && this._gpM !== 'HEAD' && sameOrigin(this._gpU)) this.setRequestHeader('X-CSRF-Token', T); } catch (e) {}
            return xs.apply(this, arguments);
        };
    }

    /* forms: right before ANY submit (also covers forms built later by modals) + on load + when added */
    document.addEventListener('submit', function (e) { inject(e.target); }, true);
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('form').forEach(inject);
        if ('MutationObserver' in window) {
            new MutationObserver(function (list) {
                list.forEach(function (m) {
                    m.addedNodes && m.addedNodes.forEach(function (n) {
                        if (n.nodeType !== 1) return;
                        if (n.tagName === 'FORM') inject(n);
                        else if (n.querySelectorAll) n.querySelectorAll('form').forEach(inject);
                    });
                });
            }).observe(document.documentElement, { childList: true, subtree: true });
        }
    });
})();
</script>
<script src="theme_css.php?boot=1"></script><link rel="stylesheet" href="theme_css.php?vars=1&v=<?= @filemtime(__DIR__.'/theme_css.php') ?>"><?php /* colours only, from theme_css.php (same as home / search / text feed) */ ?>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;font-family:'Poppins',sans-serif}
button,a{cursor:pointer;outline:none;-webkit-tap-highlight-color:transparent}
button{-webkit-appearance:none;appearance:none}
[class^="ri-"],[class*=" ri-"],[class^="ri-"]::before,[class*=" ri-"]::before,[class^="ri-"]::after,[class*=" ri-"]::after{font-family:'remixicon'!important;font-style:normal;font-weight:normal;line-height:1;-webkit-font-smoothing:antialiased}

:root{
  /* phone-only size multipliers: header = +10%, group feed + feed modal = 1.1 x 1.05 = +15.5% (1 on desktop) */
  --hd-s:1;
  --gf-s:1;
  --md-s:1;
  --bg:linear-gradient(-45deg,var(--bg-start,#010218),var(--bg-end,var(--lane,#070924)),var(--bg-end,var(--lane,#070924)));
  --solid:var(--lane,#070924);
  --surface:rgba(var(--fg,255,255,255),.05);
  --grey-text:#6F7282;
  --blue:#2459B8;
  --blue-hover:#2E68C9;
  --blue-soft:#5b8fe6;
  --feed-blue:#2b63d1;
  --feed-blue-rgb:43,99,209;
  --btn-grey:rgba(var(--fg,255,255,255),.1);
  --max-w:620px;
  --topbar-h:calc(52px * var(--hd-s));
  --topbar-pad-l:calc(4px * var(--hd-s));
  --back-x:calc(10px * var(--hd-s));
  --bbtn:calc(36px * var(--hd-s));
  --sidebar-w:100px;
  --pad-x:clamp(9px,3vw,16px);
  --banner-h:clamp(104px,29vw,178px);
  --av:calc(clamp(72px,20.5vw,112px) * var(--hd-s));
  --action-h:calc(clamp(23px,6.7vw,28px) * var(--hd-s));
  --action-r:4px;
  /* header buttons: 4px rounded corners */
  --btn-r:4px;
  /* scroll arrows (nav bar, filter bar, suggested pods, pod highlights): boxy with rounded corners */
  --arrow-r:8px;
  --fs-name:calc(clamp(.86rem,3.7vw,1rem) * var(--hd-s));
  --fs-meta:calc(clamp(.64rem,2.2vw,.76rem) * var(--hd-s) * 1.03);
  --fs-desc:calc(clamp(.64rem,1.93vw,.75rem) * var(--hd-s) * 1.03);

  --gh-w:        clamp(78px, 21vw, 98px);
  --gh-h:        calc(var(--gh-w) * 1.3);
  --gh-radius:   clamp(12px, 3.2vw, 16px);
  --gh-gap:      clamp(10px, 2.8vw, 14px);
  --gh-inset:    5px;
  --gh-name-fs:  clamp(.58rem, 1.85vw, .68rem);
}
html{scroll-behavior:smooth}
body{background:var(--bg);background-attachment:fixed;color:var(--text);min-height:100vh;
  display:flex;flex-direction:column;align-items:center;padding:0 0 76px;overflow-x:hidden}

/* ── RESPONSIVE VIEW: no scroll bars at all (page, sheets, modals, lists). Normal view keeps them. ── */
@media(max-width:768px){
  :root{--hd-s:1.1;--gf-s:1.155;--md-s:1.1}
  *{scrollbar-width:none;-ms-overflow-style:none}
  *::-webkit-scrollbar{display:none;width:0;height:0}
}

/* ── SIDEBAR: comes from nav.php (its own CSS) ── */
@media(min-width:769px){body{padding-left:var(--sidebar-w);align-items:flex-start}}
@media(min-width:490px) and (max-width:800px){:root{--max-w:100%}}
/* NORMAL (desktop) VIEW — feed lane is as narrow as the home feed (520px) */
@media(min-width:801px){:root{--max-w:520px}}
@media(max-width:490px){:root{--topbar-h:calc(48px * var(--hd-s));--bbtn:calc(32px * var(--hd-s))}}

/* ── SHELL: feed column + (laptop only) grid column ── */
.gp-shell{width:100%;display:flex;align-items:flex-start;justify-content:center}
.gp-page{width:100%;max-width:var(--max-w);margin:0 auto}
@media(min-width:769px){.gp-page{border-left:1px solid var(--border);border-right:1px solid var(--border);min-height:100vh}}

/* the right-hand column only exists on laptop / normal desktop widths — not responsive at all */
.gp-grid-col{display:none}
@media(min-width:1100px){
  .gp-shell{justify-content:flex-start;padding-left:22px}
  .gp-page{margin:0;flex:0 0 var(--max-w);width:var(--max-w)}
  .gp-grid-col{display:block;flex:1 1 auto;min-width:0;position:sticky;top:0;height:100vh;overflow-y:auto;overflow-x:hidden;
    padding:0 22px 30px 20px;scrollbar-width:thin;scrollbar-color:#2e68c9 rgba(255,255,255,.07)}
  .gp-grid-col::-webkit-scrollbar{width:9px}
  .gp-grid-col::-webkit-scrollbar-track{background:rgba(var(--fg,255,255,255),.07);border-radius:99px}
  .gp-grid-col::-webkit-scrollbar-thumb{background:#2e68c9;border-radius:99px;border:2px solid transparent;background-clip:padding-box}
  .gp-grid-col::-webkit-scrollbar-thumb:hover{background:#4a82e6;background-clip:padding-box}
}

/* ═════════ RIGHT COLUMN CARDS — pod info / admins ═════════
   Same grey "bubble bar" look as the action buttons, collapsible. */
.gs-cards{padding:18px 0 2px}
.gs-card{margin-bottom:12px}
.gs-bar{display:flex;align-items:center;gap:7px;width:100%;height:30px;padding:0 12px;border:none;border-radius:var(--action-r);
  background:rgba(var(--fg,255,255,255),.1);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);
  color:var(--text,#F0F2FF);font-size:.72rem;font-weight:600;line-height:1;text-align:left;transition:background .2s ease,transform .15s ease}
.gs-bar:hover{background:rgba(var(--fg,255,255,255),.2)}
.gs-bar:active{transform:scale(.99)}
.gs-bar-ic{font-size:14px;color:var(--blue-soft);flex-shrink:0}
.gs-bar-t{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.gs-bar-c{font-size:.64rem;font-weight:600;color:rgba(var(--fg,255,255,255),.55);flex-shrink:0}
.gs-bar-chev{font-size:18px;color:rgba(var(--fg,255,255,255),.75);flex-shrink:0;transition:transform .25s ease}
.gs-card.collapsed .gs-bar-chev{transform:rotate(-90deg)}
.gs-body{display:grid;grid-template-rows:1fr;transition:grid-template-rows .3s ease}
.gs-inner{min-height:0;overflow:hidden}
.gs-pad{padding-top:8px}
.gs-card.collapsed .gs-body{grid-template-rows:0fr}

.gs-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:9px 2px;border-bottom:1px solid rgba(var(--fg,255,255,255),.06)}
.gs-row:last-of-type{border-bottom:none}
.gs-row-l{font-size:.66rem;color:rgba(var(--fg,255,255,255),.5);font-weight:500;white-space:nowrap}
.gs-row-v{font-size:.74rem;font-weight:600;color:var(--text,#F0F2FF);text-align:right;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;text-decoration:none}
a.gs-row-v:hover{color:#8fb4ff;text-decoration:underline}
.gs-more{display:flex;align-items:center;justify-content:center;width:100%;height:30px;margin-top:8px;padding:0 12px;border:none;border-radius:var(--action-r);
  background:rgba(var(--fg,255,255,255),.1);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);
  color:var(--text,#F0F2FF);font-size:.7rem;font-weight:600;text-decoration:none;line-height:1;transition:background .2s ease,transform .15s ease}
.gs-more:hover{background:rgba(var(--fg,255,255,255),.2)}
.gs-more:active{transform:scale(.98)}

.gs-admin{display:flex;align-items:center;gap:10px;padding:8px 2px;color:var(--text,#F0F2FF);text-decoration:none;border-bottom:1px solid rgba(var(--fg,255,255,255),.06)}
.gs-admin:last-of-type{border-bottom:none}
.gs-admin img{width:32px;height:32px;border-radius:50%;object-fit:cover;border:2px solid var(--feed-blue);flex-shrink:0}
.gs-admin-n{flex:1;min-width:0;font-size:.76rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.gs-admin:hover .gs-admin-n{text-decoration:underline}
.gs-admin-tag{font-size:.56rem;font-weight:700;letter-spacing:.5px;color:#8fb4ff;background:rgba(var(--feed-blue-rgb),.16);border:1px solid rgba(var(--feed-blue-rgb),.4);padding:2px 7px;border-radius:4px;flex-shrink:0}
.gs-note{font-size:.68rem;color:rgba(var(--fg,255,255,255),.5);line-height:1.55;padding:4px 2px}


/* ═════════ RIGHT GRID (all posts) — 3 per row, 4 on wide screens, fills the column ═════════ */
.gp-grid-head{position:sticky;top:0;z-index:5;display:flex;align-items:center;gap:8px;padding:18px 0 12px;
  background:#04051a;font-size:.8rem;font-weight:700;color:#fff}
.gp-grid-head i{color:var(--blue-soft);font-size:1rem}
.gp-grid-count{margin-left:auto;font-size:.68rem;font-weight:600;color:var(--muted)}
.gp-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:4px}
@media(min-width:1500px){.gp-grid{grid-template-columns:repeat(4,minmax(0,1fr))}}
.gp-tile{position:relative;aspect-ratio:1;overflow:hidden;background:var(--surface-2,#0d1030);border:none;padding:0;border-radius:4px;cursor:pointer;display:block;text-align:left}
.gp-tile img,.gp-tile video{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;display:block;pointer-events:none;transition:transform .3s ease}
.gp-tile:hover img,.gp-tile:hover video{transform:scale(1.06)}
.gp-tile-text{position:absolute;inset:0;padding:12px;display:flex;align-items:center;justify-content:center;text-align:center;
  font-size:.7rem;font-weight:500;line-height:1.45;color:#fff;background:linear-gradient(145deg,#111a52,#1c3486 60%,#2459B8);word-break:break-word;overflow:hidden}
.gp-tile-text span{display:-webkit-box;-webkit-line-clamp:6;-webkit-box-orient:vertical;overflow:hidden}
.gp-tile-badge{position:absolute;top:7px;right:7px;z-index:2;width:22px;height:22px;border-radius:6px;background:rgba(0,0,0,.55);color:#fff;
  display:flex;align-items:center;justify-content:center;font-size:12px;pointer-events:none}
.gp-tile-ov{position:absolute;inset:0;z-index:1;display:flex;align-items:center;justify-content:center;gap:14px;background:rgba(2,3,20,.55);
  color:#fff;font-size:.72rem;font-weight:700;opacity:0;transition:opacity .2s ease;pointer-events:none}
.gp-tile:hover .gp-tile-ov{opacity:1}
.gp-tile-ov span{display:inline-flex;align-items:center;gap:4px}
.gp-tile-ov i{font-size:.9rem}
.gp-tile-sk{aspect-ratio:1;border-radius:4px}
.gp-grid-empty{grid-column:1/-1;padding:60px 10px;text-align:center;color:var(--muted);font-size:.8rem}
.gp-grid-more{grid-column:1/-1;display:flex;justify-content:center;padding:18px 0}

/* ── BANNER + BUTTONS + GROUP PIC ── */
.gp-banner{position:relative;width:100%;height:var(--banner-h);background:#0a0c30;overflow:visible}
.gp-banner-img{width:100%;height:100%;object-fit:cover;display:block}
.gp-banner-shade{position:absolute;inset:0;background:linear-gradient(to bottom,rgba(0,0,0,.45),rgba(0,0,0,.05) 45%,rgba(0,0,0,.55))}
.gp-topbar{position:sticky;top:0;z-index:500;height:var(--topbar-h);margin-bottom:calc(var(--topbar-h) * -1);display:flex;align-items:center;justify-content:space-between;
  padding:0 calc(8px * var(--hd-s)) 0 var(--topbar-pad-l);border:none;transition:background .25s}
.gp-topbar.scrolled{background:var(--bg);background-attachment:fixed;backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px)}
/* the + (new post) icon in the top bar: 2% bigger and 2% heavier */
@media(max-width:768px){.gp-bbtn .ri-add-line{font-size:1.0404em;-webkit-text-stroke:.003em currentColor}}
.gp-topbar.scrolled .gp-bbtn{background:rgba(var(--fg,255,255,255),.08)}
.gp-topbar.scrolled .gp-bbtn:hover,
.gp-topbar.scrolled .gp-bbtn.back:hover{background:rgba(var(--fg,255,255,255),.14)}
.gp-topbar.scrolled .gp-bbtn.back{margin-left:calc(var(--back-x) - var(--topbar-pad-l))}
/* the back arrow never sits in a circle (not even when the bar is scrolled / hovered): just the thin chevron, same as the profile page */
.gp-topbar.scrolled .gp-bbtn.back,.gp-topbar.scrolled .gp-bbtn.back:hover,.gp-bbtn.back:hover,.gp-bbtn.back:active{background:transparent}
.gp-bbtn.back .dz-back-svg{height:calc(clamp(18px, 16px + 0.6vw, 22px) * 1.155 * 0.98);width:auto;aspect-ratio:14 / 24;display:block;overflow:visible}
.gp-topbar-title{position:absolute;left:calc(var(--back-x) + var(--bbtn) + calc(10px * var(--hd-s)));right:calc(130px * var(--hd-s));top:0;bottom:0;display:flex;align-items:center;font-size:calc(clamp(.8rem, .6rem + 1vw, .92rem) * var(--hd-s));font-weight:700;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;opacity:0;transform:translateY(4px);transition:opacity .25s,transform .25s,right .2s;pointer-events:none}
.gp-topbar.show-title .gp-topbar-title{opacity:1;transform:none}
.gp-topbar.has-join .gp-topbar-title{right:200px}
.gp-filterbar.is-stuck{box-shadow:0 8px 16px -10px rgba(0,0,0,.65)}
.gp-adult-badge{height:calc(22px * var(--hd-s));padding:0 calc(8px * var(--hd-s));border-radius:calc(4px * var(--hd-s));background:rgba(255,45,45,.15);border:1px solid rgba(255,45,45,.4);color:#ff5a5a;font-size:calc(.64rem * var(--hd-s));font-weight:700;display:flex;align-items:center;margin-right:2px}
.gp-stats{position:absolute;bottom:calc(7px * var(--hd-s));left:var(--pad-x);right:calc(var(--pad-x) + var(--av) + calc(10px * var(--hd-s)));display:flex;align-items:center;justify-content:flex-end;gap:calc(clamp(6px,2.2vw,12px) * var(--hd-s));z-index:5;min-width:0}
.gp-stat{display:flex;flex-direction:column;align-items:flex-end;gap:1px;min-width:0}
.gp-stat b{font-size:calc(clamp(9.5px,2.6vw,12px) * var(--hd-s) * 1.03);font-weight:700;color:rgba(var(--fg,255,255,255),.8);line-height:1.1}
.gp-stat span{font-size:calc(clamp(7.5px,2.1vw,9.5px) * var(--hd-s));font-weight:500;color:rgba(var(--fg,255,255,255),.5);white-space:nowrap;max-width:100%;overflow:hidden;text-overflow:ellipsis}
.gp-stat-div{width:1px;height:calc(clamp(16px,4.6vw,24px) * var(--hd-s));background:rgba(var(--fg,255,255,255),.22);flex-shrink:0}
@media(max-width:340px){.gp-stat-div{display:none}}
.gp-banner-right{display:flex;align-items:center;gap:calc(6px * var(--hd-s));margin-left:auto}
.gp-bbtn{width:var(--bbtn);height:var(--bbtn);flex:0 0 auto;padding:0;border-radius:50%;border:none;background:rgba(0,0,0,.45);backdrop-filter:blur(8px);
  -webkit-backdrop-filter:blur(8px);color:#fff;font-size:calc(18px * var(--hd-s));display:flex;align-items:center;justify-content:center;
  transition:background .2s,transform .15s}
.gp-bbtn:hover{background:rgba(0,0,0,.65)}
/* nav-style thick search icon, same size as the other top bar buttons */
.tb-sicon{width:1em;height:1em;display:block;flex-shrink:0}
.gp-bbtn:active{transform:scale(.92)}
/* back button is ~8.7% bigger than before (24px → 26.1px, 22px → 23.9px on phones) */
.gp-bbtn.back{background:transparent;backdrop-filter:none;-webkit-backdrop-filter:none;font-size:calc(clamp(26px, 24.03px + 1.18vw, 32px) * 1.155);margin-left:-2px;
  transition:background .2s,transform .15s,margin-left .25s ease}
.gp-bbtn.back:hover{background:transparent}
/* normal (desktop) view: no back button at all — the sidebar handles navigation, title starts at the left edge */
@media(min-width:769px){
  .gp-bbtn.back{display:none}
  .gp-topbar-title{left:calc(14px * var(--hd-s))}
}

/* ── STICKY "JOIN POD" PILL (blue button → black text) ── */
.gp-joinpill{display:none;align-items:center;justify-content:center;height:calc(30px * var(--hd-s));padding:0 calc(14px * var(--hd-s));border:none;border-radius:var(--btn-r);
  background:var(--blue);color:#000;font-size:calc(.68rem * var(--hd-s));font-weight:800;line-height:1;white-space:nowrap;
  transition:background .2s,transform .15s}
.gp-joinpill.show{display:inline-flex;animation:gpPillIn .25s ease both}
.gp-joinpill:hover{background:var(--blue-hover)}
.gp-joinpill:active{transform:scale(.95)}
.gp-joinpill.pending{background:rgba(var(--fg,255,255,255),.14);color:rgba(var(--fg,255,255,255),.85)}
@keyframes gpPillIn{from{opacity:0;transform:translateX(6px)}to{opacity:1;transform:none}}
@media(max-width:490px){.gp-joinpill{height:calc(28px * var(--hd-s));padding:0 calc(11px * var(--hd-s));font-size:calc(.62rem * var(--hd-s))}.gp-topbar.has-join .gp-topbar-title{right:calc(186px * var(--hd-s))}}

/* group picture — no drop shadow behind it any more.
   cursor:pointer = the "thumb" (hand) cursor on hover; touch gets a quick press feedback instead of the tap flash */
.gp-pic{position:absolute;right:var(--pad-x);bottom:0;transform:translateY(50%);width:var(--av);height:var(--av);
  border-radius:calc(10px * var(--hd-s));border:5px solid var(--lane,#070924);overflow:hidden;background:var(--surface-2,#0d1030);z-index:6;
  box-shadow:none;cursor:pointer;-webkit-tap-highlight-color:transparent;touch-action:manipulation;
  transition:transform .25s ease}
.gp-pic:hover{transform:translateY(50%) scale(1.04)}
.gp-pic:active{transform:translateY(50%) scale(.96)}
.gp-pic img{width:100%;height:100%;object-fit:cover;display:block;cursor:pointer;-webkit-user-drag:none;user-select:none;-webkit-user-select:none}
@media(max-width:490px){.gp-pic{border-radius:calc(8px * var(--hd-s));border-width:4px}.gp-bbtn{font-size:calc(16px * var(--hd-s))}.gp-bbtn.back{font-size:calc(clamp(26px, 24.03px + 1.18vw, 32px) * 1.155)}.gp-topbar-title{font-size:calc(clamp(.8rem, .6rem + 1vw, .92rem) * var(--hd-s))}}
/* phone view: search / new post / menu icons and circles: former size +5% */
@media(max-width:768px){:root{--bbtn:calc(36px * var(--hd-s) * 1.05)}.gp-bbtn{font-size:calc(18px * var(--hd-s) * 1.05)}}
@media(max-width:490px){:root{--bbtn:calc(32px * var(--hd-s) * 1.05)}.gp-bbtn{font-size:calc(16px * var(--hd-s) * 1.05)}}

/* ── HEADER BODY ── */
.gp-head{padding:calc(10px * var(--hd-s)) var(--pad-x) 0}
.gp-top-rows{padding-right:calc(var(--av) + calc(10px * var(--hd-s)));min-height:calc(var(--av) / 2 + calc(6px * var(--hd-s)))}
.gp-name-row{display:flex;align-items:center;gap:2px;cursor:pointer;width:fit-content;max-width:100%}
.gp-name{font-size:var(--fs-name);font-weight:700;letter-spacing:-.2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.gp-chev{font-size:1.05em;color:var(--text,#F0F2FF);flex-shrink:0;transition:transform .2s}
@media(max-width:768px){.gp-chev{font-size:1.113em}}   /* chevron beside the group name: +6% on phones */
.gp-name-row:hover .gp-chev{transform:translateX(3px)}
/* tighter gap between Public/Private line and the category line */
.gp-meta{display:flex;align-items:center;gap:calc(7px * var(--hd-s));margin-top:calc(3px * var(--hd-s));font-size:var(--fs-meta);font-weight:500;color:rgba(var(--fg,255,255,255),.75);flex-wrap:wrap}
.gp-status{display:inline-flex;align-items:center;gap:calc(4px * var(--hd-s));white-space:nowrap}
.gp-status i{font-size:1.15em}
.gp-dot{width:3px;height:3px;border-radius:50%;background:rgba(255,255,255,.4);flex-shrink:0}
.gp-members{display:inline-flex;align-items:center;gap:6px;color:rgba(var(--fg,255,255,255),.75)}
.gp-members b{color:var(--text,#F0F2FF);font-weight:700}
.gp-cat-row{display:flex;align-items:center;gap:calc(8px * var(--hd-s));margin-top:2px;min-width:0}
/* tight gap ONLY when the members strip is showing (public pods / members) */
.gp-top-rows .gp-meta{margin-top:calc(3px * var(--hd-s))}
.gp-meta.has-strip{line-height:1;margin-bottom:0}
.gp-meta.has-strip .gp-members,.gp-meta.has-strip .gp-members > *{margin-top:0;margin-bottom:0}
/* strip showing → shorter gap above the description (when the strip is hidden, e.g. private pod you can't see, the gap stays as it was) */
.gp-top-rows:has(.gp-meta.has-strip){min-height:calc(var(--av) / 2 - calc(4px * var(--hd-s)))}
.gp-top-rows:has(.gp-meta.has-strip) + .gp-desc{margin-top:1px}
.gp-category{font-size:var(--fs-meta);font-weight:700;color:var(--grey-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}
.gp-adult{color:#ff5a5a;font-weight:700}

.gp-desc{margin-top:calc(5px * var(--hd-s));font-size:var(--fs-desc);line-height:1.5;color:rgba(var(--fg,255,255,255),.72);max-width:min(560px,92vw);word-break:break-word}
.gp-loc{display:flex;align-items:center;gap:calc(4px * var(--hd-s));min-width:0;max-width:100%;width:fit-content;margin-top:calc(6px * var(--hd-s));cursor:pointer;text-decoration:none;-webkit-tap-highlight-color:transparent}
.gp-loc i{font-size:calc(14px * var(--hd-s));color:rgba(var(--fg,255,255,255),.45);flex-shrink:0}
.gp-loc .gp-loc-chev{font-size:calc(15px * var(--hd-s));margin-left:-2px}
.gp-loc span{font-size:var(--fs-desc);font-weight:500;color:rgba(var(--fg,255,255,255),.45);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:min(260px,70vw)}
.gp-loc:hover span,.gp-loc:hover i{color:rgba(var(--fg,255,255,255),.7)}
.gp-toggle{color:var(--text,#F0F2FF);font-weight:700;font-size:var(--fs-desc);cursor:pointer;margin-left:calc(4px * var(--hd-s));user-select:none;
  filter:brightness(1.35);transition:opacity .18s}
.gp-toggle:hover{opacity:.8}

/* ── ACTION BUTTONS ── */
.gp-actions{display:flex;flex-wrap:nowrap;align-items:stretch;gap:calc(8px * var(--hd-s));padding:calc(12px * var(--hd-s)) 0 calc(10px * var(--hd-s))}
.gp-actions form{margin:0;display:flex;flex:1 1 0;width:0;min-width:0}
.gp-btn{display:flex;align-items:center;justify-content:center;gap:calc(6px * var(--hd-s));flex:1 1 0;width:100%;min-width:0;height:var(--action-h);
  padding:calc(4px * var(--hd-s)) calc(10px * var(--hd-s));border:none;border-radius:var(--btn-r);font-size:calc(clamp(.58rem, .5rem + .6vw, .72rem) * var(--hd-s));font-weight:600;line-height:1;white-space:nowrap;
  overflow:hidden;text-decoration:none;color:var(--text,#F0F2FF);background:var(--btn-grey);backdrop-filter:blur(10px);
  -webkit-backdrop-filter:blur(10px);transition:background .2s,transform .15s}
.gp-btn:hover{background:rgba(var(--fg,255,255,255),.2)}
.gp-btn:active{transform:scale(.98)}
.gp-btn i{font-size:calc(12px * var(--hd-s))}
/* blue button → black text */
.gp-btn.join,.gp-btn.join:hover,.gp-btn.join:active{color:#000}
.gp-btn.join{background:var(--blue);font-weight:800;letter-spacing:.1px}
.gp-btn.join:hover{background:var(--blue-hover)}
.gp-btn.leave{color:var(--text,#F0F2FF)}
.gp-btn.report{color:var(--text,#F0F2FF)}
.gp-btn.invite{color:var(--blue);font-weight:700}
.gp-btn.pending{color:rgba(var(--fg,255,255,255),.55)}
.gp-btn.locked{opacity:.5;cursor:not-allowed}
/* live Join / Leave: same bouncing bars as the Track button on profiles */
.gp-btn .spinner-icon,.gp-joinpill .spinner-icon{display:inline-flex;align-items:center;justify-content:center;gap:3px;height:16px;vertical-align:middle}
.gp-btn .spinner-icon span,.gp-joinpill .spinner-icon span{display:block;width:3px;background:currentColor;border-radius:2px;animation:gp-btn-bar 1s ease-in-out infinite}
.spinner-icon span:nth-child(2){animation-delay:.15s}.spinner-icon span:nth-child(3){animation-delay:.3s}.spinner-icon span:nth-child(4){animation-delay:.45s}
@keyframes gp-btn-bar{0%,100%{height:4px;opacity:.4}50%{height:10px;opacity:1}}


/* Settings (blue) → black text. Discussions: soft white beside Settings, black when alone. */
.gp-dual{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));align-items:stretch;gap:calc(8px * var(--hd-s));padding:0 0 calc(10px * var(--hd-s));margin-top:-2px}
.gp-dual .gp-btn{width:100%;min-width:0;background:var(--blue);color:#000;font-weight:700}
.gp-dual .gp-btn:hover{background:var(--blue-hover)}
.gp-dual .gp-btn.settings{grid-column:span 2}
.gp-dual .gp-btn.discuss{grid-column:span 1;color:#d3d8ea}
/* not joined yet: Settings/Admin text is WHITE until you join */
.gp-dual.nm .gp-btn.settings{color:var(--text,#F0F2FF)}
/* Discussions: BLACK when it is alone, soft WHITE when the Settings/Admin button is beside it */
.gp-dual.solo .gp-btn.discuss{color:#000}
.gp-dual.owner{grid-template-columns:minmax(0,7fr) minmax(0,3fr)}
.gp-dual.owner .gp-btn.settings,.gp-dual.owner .gp-btn.discuss{grid-column:auto}
.gp-dual.solo{grid-template-columns:minmax(0,1fr)}
.gp-dual.solo .gp-btn{grid-column:span 1}
.gp-dual .gp-btn.discuss.locked:hover{background:var(--blue)}
.gp-badge{display:inline-flex;align-items:center;gap:3px;background:#14306e;color:#fff;font-size:.58rem;font-weight:700;padding:2px 6px;border-radius:4px;line-height:1}
@media(max-width:490px){.gp-dual{gap:calc(6px * var(--hd-s))}}

@media(max-width:490px){.gp-actions{gap:calc(6px * var(--hd-s))}.gp-btn{padding:calc(3px * var(--hd-s)) calc(8px * var(--hd-s))}.gp-btn i{font-size:calc(12px * var(--hd-s))}}
@media(max-width:360px){.gp-btn{padding:calc(3px * var(--hd-s)) calc(6px * var(--hd-s))}}
/* action-button words: same size as the profile page's (incl. its +1.5% on phones) */
@media(max-width:768px){.gp-btn{font-size:calc(clamp(.58rem, .5rem + .6vw, .72rem) * var(--hd-s) * 1.015)}}

/* ── NAV BAR ── */
.gp-nav-wrap{position:relative}
.gp-nav{display:flex;align-items:stretch;width:100%;overflow-x:auto;scrollbar-width:none;-webkit-overflow-scrolling:touch;
  scroll-snap-type:x mandatory;overscroll-behavior-x:contain;
  border-top:1px solid var(--border);border-bottom:1px solid var(--border);margin-top:0}
.gp-nav::-webkit-scrollbar{display:none}
.gp-nav-item{flex:0 0 25%;width:25%;min-width:0;display:flex;align-items:center;justify-content:center;gap:calc(5px * var(--hd-s));padding:calc(8px * var(--hd-s)) calc(4px * var(--hd-s));
  color:var(--blue);font-size:calc(.7rem * var(--hd-s));font-weight:600;text-decoration:none;white-space:nowrap;overflow:hidden;background:none;border:none;
  position:relative;transition:color .2s,background .2s;scroll-snap-align:none}
.gp-nav-item:nth-child(4n+1){scroll-snap-align:start}
.gp-nav-item:hover{color:var(--blue-hover);background:rgba(36,89,184,.1)}
.gp-nav-item + .gp-nav-item::before{content:'';position:absolute;left:0;top:50%;height:calc(16px * var(--hd-s));width:1px;transform:translateY(-50%);background:rgba(var(--fg,255,255,255),.12)}
.gp-nav-item.locked{opacity:.4;cursor:default}
.gp-nav-item img{width:calc(20px * var(--hd-s));height:calc(20px * var(--hd-s));border-radius:50%;object-fit:cover;border:2px solid var(--blue);flex-shrink:0}
@media(max-width:490px){.gp-nav-item{padding:calc(7px * var(--hd-s)) calc(3px * var(--hd-s));font-size:calc(.62rem * var(--hd-s));gap:calc(4px * var(--hd-s))}.gp-nav-item img{width:calc(17px * var(--hd-s));height:calc(17px * var(--hd-s))}}

/* scroll arrows for the nav bar — NORMAL VIEW ONLY (appear on hover). Boxy with rounded corners. */
.gp-nav-arrow{position:absolute;top:50%;transform:translateY(-50%);z-index:10;width:calc(30px * var(--hd-s));height:calc(30px * var(--hd-s));padding:0 0 calc(3px * var(--hd-s));border-radius:var(--arrow-r);background:rgba(0,0,0,.7);border:1.5px solid rgba(var(--fg,255,255,255),.25);color:#fff;display:flex;align-items:center;justify-content:center;font-size:calc(26px * var(--hd-s));line-height:1;opacity:0;pointer-events:none;transition:opacity .25s ease,background .2s ease}
.gp-nav-arrow-l{left:calc(6px * var(--hd-s))}.gp-nav-arrow-r{right:calc(6px * var(--hd-s))}
.gp-nav-arrow:hover{background:rgba(36,89,184,.9)}
.gp-nav-wrap:hover .gp-nav-arrow:not(.hidden){opacity:1;pointer-events:auto}
.gp-nav-arrow.hidden{opacity:0!important;pointer-events:none!important}
@media(max-width:768px){.gp-nav-arrow{display:none!important}}

/* ── FILTER BAR (second nav bar) ── */
.gp-filterbar{position:sticky;top:calc(var(--topbar-h) - 1px);z-index:400;background:var(--bg);background-attachment:fixed;
  border-bottom:1px solid var(--border);padding:calc(7px * var(--hd-s)) 0}
.gp-filter-scroll{display:flex;align-items:center;gap:calc(7px * var(--hd-s));overflow-x:auto;scrollbar-width:none;-webkit-overflow-scrolling:touch;padding:0 var(--pad-x)}
.gp-filter-scroll::-webkit-scrollbar{display:none}
.gp-fpill{flex:0 0 auto;display:inline-flex;align-items:center;gap:calc(5px * var(--hd-s));height:calc(26px * var(--hd-s));padding:0 calc(11px * var(--hd-s));border-radius:var(--action-r);
  background:rgba(36,89,184,.14);border:1px solid rgba(36,89,184,.5);color:var(--blue-hover);
  font-size:calc(.66rem * var(--hd-s));font-weight:700;line-height:1;white-space:nowrap;cursor:pointer;
  transition:background .2s,color .2s,border-color .2s,transform .15s}
.gp-fpill:hover{background:rgba(36,89,184,.26)}
.gp-fpill:active{transform:scale(.96)}
.gp-fpill i{font-size:calc(12px * var(--hd-s))}
.gp-fpill.active-tag{background:var(--blue);border-color:var(--blue);color:#000}
.gp-fpill.none{color:rgba(var(--fg,255,255,255),.4);background:rgba(var(--fg,255,255,255),.05);border-color:rgba(var(--fg,255,255,255),.12);cursor:default}
.gp-filter-sep{flex:0 0 1px;height:calc(16px * var(--hd-s));background:rgba(var(--fg,255,255,255),.14)}
@media(max-width:490px){.gp-filterbar{padding:calc(6px * var(--hd-s)) 0}.gp-filter-scroll{gap:calc(6px * var(--hd-s))}.gp-fpill{height:calc(24px * var(--hd-s));padding:0 calc(9px * var(--hd-s));font-size:calc(.62rem * var(--hd-s))}}

/* scroll arrows for the filter bar — same look as the nav-bar arrows, NORMAL VIEW ONLY (appear on hover) */
.gp-f-arrow{position:absolute;top:50%;transform:translateY(-50%);z-index:10;width:calc(26px * var(--hd-s));height:calc(26px * var(--hd-s));padding:0 0 2px;border-radius:var(--arrow-r);background:rgba(0,0,0,.7);border:1.5px solid rgba(var(--fg,255,255,255),.25);color:#fff;display:flex;align-items:center;justify-content:center;font-size:calc(23px * var(--hd-s));line-height:1;opacity:0;pointer-events:none;transition:opacity .25s ease,background .2s ease}
.gp-f-arrow-l{left:calc(6px * var(--hd-s))}.gp-f-arrow-r{right:calc(6px * var(--hd-s))}
.gp-f-arrow:hover{background:rgba(36,89,184,.9)}
.gp-filterbar:hover .gp-f-arrow:not(.hidden){opacity:1;pointer-events:auto}
.gp-f-arrow.hidden{opacity:0!important;pointer-events:none!important}
@media(max-width:768px){.gp-f-arrow{display:none!important}}

/* ═════════ RULES VIEW — the Rules button swaps the highlights / events / feed for this grid ═════════ */
#gpRulesView{display:none}
.gp-page.rules-mode #gpRulesView{display:block}
.gp-page.rules-mode #gpFeedView{display:none}
.gp-page.rules-mode .gp-filterbar{display:none}
.gp-rules{background:var(--solid);padding:14px var(--pad-x) 30px;border-top:3px solid var(--post-sep);border-bottom:3px solid var(--post-sep);min-height:60vh}
.gp-rules-head{display:flex;align-items:center;gap:7px;margin:4px 0 10px;font-size:.66rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:rgba(var(--fg,255,255,255),.62)}
.gp-rules-head i{color:var(--blue-soft);font-size:.9rem}
.gp-rules-head + .gp-rules-grid{margin-bottom:20px}
.gp-rules-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.gp-rule-card{position:relative;display:flex;flex-direction:column;gap:8px;padding:12px;border-radius:10px;background:rgba(var(--fg,255,255,255),.04);border:1px solid var(--border);
  font-size:.72rem;line-height:1.55;color:rgba(var(--fg,255,255,255),.75);word-break:break-word;transition:background .2s,border-color .2s}
.gp-rule-card:hover{background:rgba(var(--fg,255,255,255),.065);border-color:rgba(36,89,184,.45)}
.gp-rule-top{display:flex;align-items:center;justify-content:space-between;gap:8px}
.gp-rule-ic{width:28px;height:28px;border-radius:var(--action-r);background:rgba(var(--feed-blue-rgb),.16);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.gp-rule-ic i{font-size:15px;color:var(--blue-soft)}
.gp-rule-num{font-size:.6rem;font-weight:700;color:rgba(var(--fg,255,255,255),.35)}
.gp-rule-card.wide{grid-column:1/-1}
.gp-rule-card.strike{background:rgba(239,68,68,.05);border-color:rgba(239,68,68,.22)}
.gp-rule-card.strike .gp-rule-ic{background:rgba(239,68,68,.16)}
.gp-rule-card.strike .gp-rule-ic i{color:#ef4444}
.gp-rule-title{font-size:.78rem;font-weight:700;color:var(--text,#F0F2FF)}
.gp-rule-card.strike .gp-rule-title{color:#ff8a8a}
.gp-rule-steps{display:flex;flex-direction:column}
.gp-rule-step{display:flex;align-items:flex-start;gap:9px;padding:7px 0;border-top:1px solid rgba(var(--fg,255,255,255),.06);font-size:.7rem;line-height:1.5;color:rgba(var(--fg,255,255,255),.65)}
.gp-rule-step .n{width:19px;height:19px;border-radius:50%;background:rgba(var(--fg,255,255,255),.08);border:1px solid rgba(var(--fg,255,255,255),.14);font-size:.6rem;font-weight:700;color:rgba(var(--fg,255,255,255),.65);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.gp-rule-step.danger .n{background:rgba(239,68,68,.25);border-color:rgba(239,68,68,.4);color:#ff8a8a}
.gp-rule-step strong{color:#ff8a8a}
.gp-rule-card.empty{color:var(--muted);font-style:italic;align-items:center;text-align:center}
@media(max-width:360px){.gp-rules-grid{grid-template-columns:minmax(0,1fr)}}
@media(max-width:490px){.gp-rule-card{padding:10px;font-size:.66rem}.gp-rule-title{font-size:.72rem}.gp-rule-step{font-size:.66rem}}

/* ═════════ POD HIGHLIGHTS ═════════ */
.gh-section{padding:8px 0 4px}
.gh-head{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:0 var(--pad-x) 5px}
.gh-title{display:flex;align-items:center;gap:5px;font-size:clamp(.6rem,2vw,.68rem);font-weight:600;line-height:1;color:rgba(var(--fg,255,255,255),.72)}
.gh-title i{font-size:1.2em;flex-shrink:0}
.gh-toggle{width:26px;height:26px;flex:0 0 auto;padding:0;margin-right:-5px;border:none;border-radius:50%;background:none;
  color:rgba(var(--fg,255,255,255),.72);font-size:20px;display:flex;align-items:center;justify-content:center;
  transition:transform .25s ease,background .2s ease}
.gh-toggle:hover{background:rgba(var(--fg,255,255,255),.08)}
.gh-section.collapsed .gh-toggle{transform:rotate(-90deg)}
.gh-body{display:grid;grid-template-rows:1fr;transition:grid-template-rows .3s ease}
.gh-body > .gh-wrap{min-height:0;overflow:hidden;transition:visibility .3s}
.gh-section.collapsed .gh-body{grid-template-rows:0fr}
.gh-section.collapsed .gh-wrap{visibility:hidden}

.gh-wrap{position:relative}
.gh-strip{display:flex;flex-direction:row;flex-wrap:nowrap;align-items:flex-start;gap:var(--gh-gap);
  padding:2px var(--pad-x) 10px;overflow-x:auto;overflow-y:hidden;-webkit-overflow-scrolling:touch;
  scrollbar-width:none;-ms-overflow-style:none;overscroll-behavior-x:contain}
.gh-strip::-webkit-scrollbar{display:none;width:0;height:0}

.gh-tile{flex:0 0 auto;width:var(--gh-w);display:flex;flex-direction:column;align-items:center;gap:7px;
  background:none;border:none;padding:0;color:var(--text,#F0F2FF);text-align:center;font-family:inherit;transition:transform .18s ease}
.gh-tile:active{transform:scale(.95)}
.gh-ring{position:relative;display:block;width:var(--gh-w);height:var(--gh-h);border-radius:var(--gh-radius)}

.gh-tile.gh-hold{display:none}
.gh-tile.gh-in{animation:ghIn .3s cubic-bezier(.22,1,.36,1) both}
@keyframes ghIn{from{opacity:0;transform:translateX(8px) scale(.94)}to{opacity:1;transform:none}}
.gh-skel{flex:0 0 auto;width:var(--gh-w);display:flex;flex-direction:column;align-items:center;gap:7px}
.gh-skel .sk.sq{display:block;width:var(--gh-w);height:var(--gh-h);border-radius:var(--gh-radius)}
.gh-skel .sk.nm{display:block;width:70%;height:8px;border-radius:4px}

.gh-ring::after{content:'';position:absolute;inset:0;border-radius:var(--gh-radius);
  border:3px solid rgba(var(--fg,255,255,255),.28);pointer-events:none;transition:border-color .12s ease}
.gh-tile.spin .gh-ring::after{border-color:transparent}

.gh-svg{position:absolute;inset:0;width:100%;height:100%;display:block;overflow:visible;opacity:0;pointer-events:none;z-index:2}
.gh-svg rect{fill:none;stroke:rgba(255,255,255,.62);stroke-width:3.2;stroke-linecap:butt;
  stroke-dasharray:100 0 0 0 0 0 0 0 0 0 0 0 0 0;stroke-dashoffset:0}
.gh-tile.spin .gh-svg{opacity:1}
.gh-tile.spin .gh-svg rect{animation:ghShape 1.4s ease-in-out 1 both,ghRun 1.4s linear 1 both}
@keyframes ghShape{
  0%  {stroke-dasharray:100 0 0 0 0 0 0 0 0 0 0 0 0 0}
  18% {stroke-dasharray:40 2.5 10 3 8 3.5 6 4 4.5 4.5 3 5 2 4}
  84% {stroke-dasharray:40 2.5 10 3 8 3.5 6 4 4.5 4.5 3 5 2 4}
  100%{stroke-dasharray:100 0 0 0 0 0 0 0 0 0 0 0 0 0}
}
@keyframes ghRun{0%{stroke-dashoffset:0}100%{stroke-dashoffset:-150}}

.gh-sq{position:absolute;inset:var(--gh-inset);display:block;border-radius:calc(var(--gh-radius) - var(--gh-inset) + 3px);overflow:hidden;background:var(--surface-2,#0d1030)}
.gh-sq img,.gh-sq video{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;display:block;pointer-events:none}
.gh-cap{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;padding:6px;text-align:center;
  font-size:.56rem;font-weight:600;line-height:1.35;color:#fff;text-shadow:0 1px 4px rgba(0,0,0,.85);
  background:rgba(0,0,0,.2);overflow:hidden;word-break:break-word;pointer-events:none}
.gh-poll{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;padding:7px;text-align:center;
  background:linear-gradient(160deg,#1a2a6c,#4C8DFF 60%,#7b5cff);color:#fff;overflow:hidden}
.gh-poll i{font-size:18px;opacity:.95}
.gh-poll span{font-size:.56rem;font-weight:700;line-height:1.3;display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;overflow:hidden;word-break:break-word}
.gh-vid{position:absolute;left:5px;bottom:5px;width:18px;height:18px;border-radius:50%;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;font-size:11px;color:#fff}
.gh-name{display:block;width:100%;font-size:var(--gh-name-fs);font-weight:500;line-height:1.3;color:rgba(var(--fg,255,255,255),.85);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

/* highlight arrows — EXACT same arrows as the nav bar / suggested pods row (boxy, rounded corners, hover-only,
   hidden at the ends). NORMAL VIEW ONLY. */
.gh-arrow{position:absolute;top:calc(2px + var(--gh-h) / 2);transform:translateY(-50%);z-index:10;width:30px;height:30px;padding:0 0 3px;border-radius:var(--arrow-r);
  background:rgba(0,0,0,.7);border:1.5px solid rgba(var(--fg,255,255,255),.25);color:#fff;
  display:flex;align-items:center;justify-content:center;font-size:26px;line-height:1;
  opacity:0;pointer-events:none;transition:opacity .25s ease,background .2s ease}
.gh-arrow.left{left:6px}
.gh-arrow.right{right:6px}
.gh-arrow:hover{background:rgba(36,89,184,.9)}
.gh-wrap:hover .gh-arrow:not(.hidden){opacity:1;pointer-events:auto}
.gh-arrow.hidden{opacity:0!important;pointer-events:none!important}
@media(max-width:768px){.gh-arrow{display:none!important}}
@media(prefers-reduced-motion:reduce){.gh-tile.spin .gh-svg rect{animation-duration:2.4s,2.4s}.gh-body{transition:none}}

/* ═════════ UPCOMING EVENTS (list view, 2 at a time) ═════════ */
.gp-ev{background:var(--solid);border-bottom:none;padding:10px 0 2px;margin-top:4px}
.gp-ev-head{display:flex;align-items:center;gap:6px;padding:0 var(--pad-x) 6px;font-size:.66rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:rgba(var(--fg,255,255,255),.62)}
.gp-ev-head i{color:var(--blue-soft);font-size:.9rem}
.gp-ev-count{margin-left:2px;padding:1px 8px;border-radius:20px;background:rgba(36,89,184,.18);border:1px solid rgba(36,89,184,.45);color:#8fb4ff;font-size:.58rem;letter-spacing:0}
.gp-ev-row{display:flex;align-items:flex-start;gap:12px;padding:11px var(--pad-x);text-decoration:none;color:inherit;border-bottom:1px solid var(--border);transition:background .2s}
.gp-ev-row:hover{background:rgba(var(--fg,255,255,255),.035)}
.gp-ev-hold{display:none}
.gp-ev-in{animation:gpEvIn .32s cubic-bezier(.22,1,.36,1) both}
@keyframes gpEvIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
.gp-ev-thumb{position:relative;width:84px;height:84px;border-radius:9px;overflow:hidden;flex-shrink:0;
  background:linear-gradient(145deg,var(--bg-start,#010218),#0b1a5c 60%,#1d3f9e);display:flex;align-items:center;justify-content:center}
.gp-ev-thumb .gp-ev-fb{font-size:1.9rem;color:#7fa0ff;opacity:.85}
.gp-ev-thumb img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;display:block}
.gp-ev-info{flex:1;min-width:0;padding-top:1px}
.gp-ev-date{font-size:.7rem;font-weight:600;color:#8aa8ff;margin-bottom:3px}
.gp-ev-title{font-size:.88rem;font-weight:700;color:var(--text,#F0F2FF);line-height:1.32;margin-bottom:6px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;word-break:break-word}
.gp-ev-type{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:4px;background:rgba(var(--fg,255,255,255),.07);
  font-size:.64rem;font-weight:600;color:rgba(var(--fg,255,255,255),.72)}
.gp-ev-type i{font-size:.8rem;color:#8aa8ff}
.gp-ev-more{display:block;width:calc(100% - (var(--pad-x) * 2));margin:9px var(--pad-x) 10px;padding:9px;border-radius:var(--action-r);
  background:rgba(36,89,184,.14);border:1px solid rgba(36,89,184,.5);color:var(--blue-hover);font-size:.72rem;font-weight:700;transition:background .2s,transform .15s}
.gp-ev-more:hover{background:rgba(36,89,184,.26)}
.gp-ev-more:active{transform:scale(.98)}
@media(max-width:490px){
  .gp-ev-thumb{width:74px;height:74px}.gp-ev-title{font-size:.8rem}.gp-ev-date{font-size:.64rem}.gp-ev-type{font-size:.6rem}
}

/* ═════════ ANSWER-QUESTIONS MODAL — same design language + fluid (premium) sizing as the other modals ═════════ */
#gpJqModal{
  --jq-blue:#2a5fd8; --jq-blue-h:#234fb5; --jq-blue-l:#7ea6ff;
  --jq-line:rgba(var(--fg,255,255,255),.09); --jq-line2:rgba(var(--fg,255,255,255),.14);
  --jq-mut:rgba(var(--fg,255,255,255),.55);
  --jq-pad:clamp(10px,3.4vw,18px);
  --jq-act-h:clamp(26px,7.4vw,32px);
  --jq-fs:clamp(.68rem,2.3vw,.8rem);
  --jq-tb-h:clamp(44px,12vw,54px);
  display:none;position:fixed;inset:0;z-index:999999999;justify-content:center;overflow:hidden;
  background:linear-gradient(-45deg,var(--bg-start,#010218),var(--bg-end,var(--lane,#070924)),var(--bg-end,var(--lane,#070924)));background-attachment:fixed;color:#fff;
  font-family:'Poppins',sans-serif;font-size:var(--jq-fs);-webkit-font-smoothing:antialiased}
#gpJqModal.open{display:flex;animation:gpsIn .28s cubic-bezier(.22,1,.36,1)}
#gpJqModal *,#gpJqModal *::before,#gpJqModal *::after{box-sizing:border-box}
#gpJqModal button,#gpJqModal textarea{font-family:inherit}
.gpjq-lane{width:100%;max-width:580px;height:100%;display:flex;flex-direction:column;min-height:0}
@media(min-width:801px){.gpjq-lane{border-left:1px solid var(--jq-line);border-right:1px solid var(--jq-line)}}
@media(max-width:800px){.gpjq-lane{max-width:100%}}
.gpjq-top{flex-shrink:0;display:flex;align-items:center;min-height:var(--jq-tb-h);padding:env(safe-area-inset-top) 10px 0}
.gpjq-side{flex:0 0 38px;display:flex;align-items:center}.gpjq-side.right{justify-content:flex-end}
.gpjq-x{width:clamp(30px,8.4vw,34px);height:clamp(30px,8.4vw,34px);background:none;border:none;color:var(--text,#F0F2FF);display:flex;align-items:center;justify-content:center;padding:0;opacity:.9;cursor:pointer;transition:.2s}
.gpjq-x:hover{color:var(--jq-blue-l);opacity:1}
.gpjq-title{flex:1;min-width:0;text-align:center;font-size:clamp(.8rem,2.7vw,.92rem);font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.gpjq-body{flex:1;min-height:0;overflow-y:auto;overflow-x:hidden;-webkit-overflow-scrolling:touch;padding:4px var(--jq-pad) clamp(16px,5vw,26px);
  scrollbar-width:thin;scrollbar-color:#2e68c9 rgba(255,255,255,.06)}
.gpjq-body::-webkit-scrollbar{width:8px}
.gpjq-body::-webkit-scrollbar-thumb{background:#2e68c9;border-radius:99px;border:2px solid transparent;background-clip:padding-box}
.gpjq-note{font-size:clamp(.62rem,2vw,.7rem);color:var(--jq-mut);line-height:1.55;margin:clamp(4px,1.6vw,8px) 0 clamp(10px,3vw,14px)}
.gpjq-err{display:flex;align-items:flex-start;gap:7px;padding:clamp(8px,2.4vw,10px) clamp(9px,2.8vw,12px);margin-bottom:clamp(10px,3vw,14px);border-radius:8px;
  background:rgba(255,45,45,.12);border:1px solid rgba(255,45,45,.35);color:#ff8a8a;font-size:clamp(.62rem,2vw,.7rem);line-height:1.5}
.gpjq-q{margin-bottom:clamp(8px,2.6vw,12px);padding:clamp(10px,3vw,14px);border-radius:10px;background:rgba(var(--fg,255,255,255),.04);border:1px solid var(--jq-line)}
.gpjq-q label{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:clamp(6px,1.8vw,8px);
  font-size:clamp(.68rem,2.3vw,.78rem);font-weight:600;line-height:1.4}
.gpjq-q em{font-style:normal;flex-shrink:0;font-size:clamp(.52rem,1.7vw,.58rem);font-weight:700;letter-spacing:.4px;text-transform:uppercase;padding:2px 8px;border-radius:4px}
.gpjq-q em.req{background:#14306e;color:#fff}.gpjq-q em.opt{background:rgba(var(--fg,255,255,255),.08);color:var(--jq-mut)}
.gpjq-q textarea{width:100%;min-height:clamp(58px,17vw,74px);padding:clamp(8px,2.4vw,10px) clamp(9px,2.8vw,12px);border-radius:6px;border:1px solid var(--jq-line2);
  background:rgba(var(--fg,255,255,255),.05);color:var(--text,#F0F2FF);font-size:clamp(.7rem,2.4vw,.8rem);line-height:1.55;resize:none;outline:none;transition:.2s}
.gpjq-q textarea::placeholder{color:rgba(var(--fg,255,255,255),.4)}
.gpjq-q textarea:focus{border-color:rgba(42,95,216,.85);box-shadow:0 0 0 3px rgba(42,95,216,.18)}
.gpjq-q textarea.bad{border-color:#ff5a5a;box-shadow:0 0 0 3px rgba(255,90,90,.14)}
.gpjq-bar{flex-shrink:0;display:flex;gap:clamp(6px,2vw,8px);padding:clamp(6px,2vw,8px) var(--jq-pad) calc(clamp(8px,2.4vw,12px) + env(safe-area-inset-bottom))}
.gpjq-btn{flex:1 1 0;min-width:0;display:flex;align-items:center;justify-content:center;gap:6px;height:var(--jq-act-h);padding:0 clamp(8px,2.6vw,14px);border:none;border-radius:var(--btn-r,4px);
  background:#2a5fd8;color:#000;font-size:clamp(.63rem,2.1vw,.74rem);font-weight:800;line-height:1;white-space:nowrap;cursor:pointer;transition:background .2s,transform .15s}
.gpjq-btn:hover{background:#234fb5}.gpjq-btn:active{transform:scale(.98)}
.gpjq-btn i{font-size:clamp(12px,3.4vw,14px)}
.gpjq-btn.ghost{background:rgba(var(--fg,255,255,255),.1);color:var(--text,#F0F2FF);font-weight:600}.gpjq-btn.ghost:hover{background:rgba(var(--fg,255,255,255),.2)}
@media(max-width:800px){#gpJqModal .gpjq-body{scrollbar-width:none}#gpJqModal .gpjq-body::-webkit-scrollbar{display:none}}
@media(prefers-reduced-motion:reduce){#gpJqModal *{transition-duration:.01ms!important;animation-duration:.01ms!important}}

/* owner's rules: one bullet bar per line */
.gp-rules-bars{grid-template-columns:minmax(0,1fr)!important;gap:6px}
.gp-rule-bar{display:flex;align-items:flex-start;gap:10px;padding:10px 12px;border-radius:8px;background:rgba(var(--fg,255,255,255),.04);border:1px solid var(--border);
  font-size:.72rem;line-height:1.55;color:rgba(var(--fg,255,255,255),.78);word-break:break-word}
.gp-rule-bar::before{content:'';width:6px;height:6px;border-radius:50%;background:var(--blue-soft);flex-shrink:0;margin-top:.55em}
@media(max-width:490px){.gp-rule-bar{font-size:.66rem;padding:9px 10px}}
/* phone view: the Rules grid is 10% bigger */
@media(max-width:768px){.gp-rules{padding:calc(14px * 1.1) var(--pad-x) calc(30px * 1.1)}.gp-rules-head{font-size:calc(0.66rem * 1.1);gap:calc(7px * 1.1);margin:calc(4px * 1.1) 0 calc(10px * 1.1)}.gp-rules-head i{font-size:calc(0.9rem * 1.1)}.gp-rules-grid{gap:calc(8px * 1.1)}.gp-rule-card{padding:calc(12px * 1.1);gap:calc(8px * 1.1);font-size:calc(0.72rem * 1.1);border-radius:calc(10px * 1.1)}.gp-rule-ic{width:calc(28px * 1.1);height:calc(28px * 1.1)}.gp-rule-ic i{font-size:calc(15px * 1.1)}.gp-rule-num{font-size:calc(0.6rem * 1.1)}.gp-rule-title{font-size:calc(0.78rem * 1.1)}.gp-rule-step{font-size:calc(0.7rem * 1.1);gap:calc(9px * 1.1);padding:calc(7px * 1.1) 0}.gp-rule-step .n{width:calc(19px * 1.1);height:calc(19px * 1.1);font-size:calc(0.6rem * 1.1)}.gp-rule-bar{font-size:calc(0.72rem * 1.1);padding:calc(10px * 1.1) calc(12px * 1.1);gap:calc(10px * 1.1)}}
@media(max-width:490px){.gp-rules{padding:calc(14px * 1.1) var(--pad-x) calc(30px * 1.1)}.gp-rules-head{font-size:calc(0.66rem * 1.1);gap:calc(7px * 1.1);margin:calc(4px * 1.1) 0 calc(10px * 1.1)}.gp-rules-head i{font-size:calc(0.9rem * 1.1)}.gp-rules-grid{gap:calc(8px * 1.1)}.gp-rule-card{padding:calc(10px * 1.1);gap:calc(8px * 1.1);font-size:calc(0.66rem * 1.1);border-radius:calc(10px * 1.1)}.gp-rule-ic{width:calc(28px * 1.1);height:calc(28px * 1.1)}.gp-rule-ic i{font-size:calc(15px * 1.1)}.gp-rule-num{font-size:calc(0.6rem * 1.1)}.gp-rule-title{font-size:calc(0.72rem * 1.1)}.gp-rule-step{font-size:calc(0.66rem * 1.1);gap:calc(9px * 1.1);padding:calc(7px * 1.1) 0}.gp-rule-step .n{width:calc(19px * 1.1);height:calc(19px * 1.1);font-size:calc(0.6rem * 1.1)}.gp-rule-bar{font-size:calc(0.66rem * 1.1);padding:calc(9px * 1.1) calc(10px * 1.1);gap:calc(10px * 1.1)}}

/* ═════════ SORT MODAL — exact same design as the post modal ═════════
   full-screen overlay (covers the sidebar too) · centered lane · two grey side lines (normal view)
   · X on the left · title centered · rectangular 5px blue accents, black text on blue */
#sortSheet{display:none!important}

#gpSortSheet{
  --pm-blue:#2a5fd8; --pm-blue-h:#234fb5; --pm-blue-l:#7ea6ff;
  --pm-blue-dim:rgba(42,95,216,.18); --pm-blue-glow:rgba(42,95,216,.34);
  --pm-on-blue:#000;
  --pm-card:rgba(var(--fg,255,255,255),.05); --pm-card2:rgba(var(--fg,255,255,255),.08);
  --pm-line:rgba(var(--fg,255,255,255),.09); --pm-line2:rgba(var(--fg,255,255,255),.14);
  --pm-sub:rgba(var(--fg,255,255,255),.62); --pm-mut:rgba(var(--fg,255,255,255),.45);
  --pm-pad:clamp(9px,3vw,16px);
  --pm-act-h:clamp(25px,7.2vw,30px);
  --pm-r:5px; --pm-fs:.8rem; --pm-tb-h:54px;

  display:none;position:fixed;inset:0;z-index:999999999;
  background:linear-gradient(-45deg,var(--bg-start,#010218),var(--bg-end,var(--lane,#070924)),var(--bg-end,var(--lane,#070924)));background-attachment:fixed;
  justify-content:center;overflow:hidden;font-family:'Poppins',sans-serif;font-size:var(--pm-fs);color:#fff;-webkit-font-smoothing:antialiased}
@media(max-width:490px){#gpSortSheet{--pm-fs:.74rem;--pm-tb-h:48px}}
@media(max-width:390px){#gpSortSheet{--pm-fs:.7rem;--pm-tb-h:44px}}
#gpSortSheet.open{display:flex;animation:gpsIn .28s cubic-bezier(.22,1,.36,1)}
@keyframes gpsIn{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
@keyframes gpsOut{to{opacity:0;transform:translateY(16px)}}
#gpSortSheet *,#gpSortSheet *::before,#gpSortSheet *::after{box-sizing:border-box}
#gpSortSheet button{cursor:pointer;-webkit-tap-highlight-color:transparent;outline:none}

/* the lane + two grey lines */
.gps-inner{width:100%;max-width:580px;height:100%;display:flex;flex-direction:column;min-height:0;overflow:hidden}
@media(min-width:801px){.gps-inner{border-left:1px solid var(--pm-line);border-right:1px solid var(--pm-line)}}
@media(max-width:800px){.gps-inner{max-width:100%}}

/* top bar: X left · title centered */
.gps-topbar{flex-shrink:0;display:flex;align-items:center;min-height:var(--pm-tb-h);padding:env(safe-area-inset-top) 10px 0;background:transparent}
.gps-tb-side{flex:0 0 38px;display:flex;align-items:center}
.gps-tb-side.right{justify-content:flex-end}
.gps-x{width:34px;height:34px;background:none;border:none;color:var(--text,#F0F2FF);display:flex;align-items:center;justify-content:center;opacity:.9;padding:0;transition:.2s}
.gps-x:hover{color:var(--pm-blue-l);opacity:1}
.gps-x svg{width:22px;height:22px;display:block}
.gps-topbar-center{flex:1;min-width:0;text-align:center}
.gps-title{font-size:.92rem;font-weight:700;color:var(--text,#F0F2FF);line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
@media(max-width:490px){.gps-title{font-size:.84rem}.gps-x{width:30px;height:30px}}
@media(max-width:390px){.gps-title{font-size:.8rem}}

/* scroll area */
.gps-body{flex:1;min-height:0;overflow-y:auto;overflow-x:hidden;-webkit-overflow-scrolling:touch;padding:4px var(--pm-pad) 22px;
  scrollbar-width:thin;scrollbar-color:rgba(42,95,216,.4) transparent}
.gps-body::-webkit-scrollbar{width:4px}
.gps-body::-webkit-scrollbar-thumb{background:rgba(42,95,216,.45);border-radius:99px}
.gps-note{font-size:.66rem;color:var(--pm-mut);line-height:1.5;margin:6px 0 2px}

/* group headings = the post modal's section headings */
.gps-group{display:flex;align-items:center;gap:6px;margin:16px 0 9px;padding-top:14px;border-top:1px solid var(--pm-line);
  font-size:.64rem;font-weight:600;letter-spacing:.6px;text-transform:uppercase;color:var(--pm-sub)}
.gps-note + .gps-group{margin-top:10px;padding-top:0;border-top:none}

/* options = the post modal's list rows */
.gps-option{display:flex;align-items:center;gap:10px;padding:8px;margin-bottom:6px;border-radius:8px;background:var(--pm-card);
  border:1px solid var(--pm-line);cursor:pointer;transition:.2s;-webkit-tap-highlight-color:transparent}
.gps-option:hover{background:rgba(var(--fg,255,255,255),.08)}
.gps-option.selected:hover{background:#1b3873}
.gps-option:active{transform:scale(.99)}
.gps-option.selected{background:#1b3873;border-color:#1b3873;box-shadow:none}
.gps-icon{width:34px;height:34px;border-radius:6px;background:var(--pm-blue-dim);display:flex;align-items:center;justify-content:center;color:var(--pm-blue-l);font-size:15px;flex-shrink:0}
.gps-option.selected .gps-icon{background:rgba(var(--fg,255,255,255),.1);color:var(--text,#F0F2FF)}
.gps-text{flex:1;min-width:0}
.gps-name{font-size:.74rem;font-weight:600;color:var(--text,#F0F2FF);line-height:1.25;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.gps-desc{font-size:.64rem;color:var(--pm-mut);margin-top:2px}
.gps-check{width:20px;height:20px;border-radius:5px;border:1.5px solid var(--pm-line2);display:flex;align-items:center;justify-content:center;
  flex-shrink:0;font-size:12px;font-weight:700;color:transparent;transition:.2s}
.gps-option.selected .gps-check{background:none;border-color:transparent;color:var(--text,#F0F2FF)}
@media(max-width:490px){
  .gps-name{font-size:.7rem}.gps-desc{font-size:.6rem}
  .gps-icon{width:32px;height:32px;font-size:14px}
}
/* responsive view: no scrollbar at all — not even a remnant (still scrolls by touch / wheel) */
@media(max-width:800px){
  #gpSortSheet,#gpSortSheet *{scrollbar-width:none!important;-ms-overflow-style:none!important}
  #gpSortSheet *::-webkit-scrollbar,#gpSortSheet::-webkit-scrollbar,
  .gps-body::-webkit-scrollbar{display:none!important;width:0!important;height:0!important;background:transparent!important}
  .gps-body{padding-right:var(--pm-pad)}
}
@media(prefers-reduced-motion:reduce){#gpSortSheet *{transition-duration:.01ms!important;animation-duration:.01ms!important}}

/* ── PAUSE NOTICE ── */
.gp-pause{margin:0 var(--pad-x) 10px;padding:11px 12px 10px;border-radius:10px;background:rgba(1,18,66,.22);border:1px solid var(--border)}
.gp-pause-top{display:flex;align-items:flex-start;gap:9px}
.gp-pause-ic{width:28px;height:28px;border-radius:50%;flex-shrink:0;background:rgba(255,149,0,.14);color:#FF9500;display:flex;align-items:center;justify-content:center;font-size:.9rem}
.gp-pause-title{flex:1;font-size:.76rem;font-weight:700;line-height:1.35}
.gp-x{width:22px;height:22px;border-radius:50%;border:none;background:rgba(var(--fg,255,255,255),.06);color:rgba(var(--fg,255,255,255),.55);font-size:.85rem;display:flex;align-items:center;justify-content:center}
.gp-x:hover{background:rgba(var(--fg,255,255,255),.12);color:var(--text,#F0F2FF)}
.gp-pause-body{font-size:.68rem;line-height:1.55;color:rgba(var(--fg,255,255,255),.55);margin:7px 0 10px}
.gp-pause-btns{display:flex;gap:8px}
.gp-pause-btns form{flex:1 1 0;min-width:0;display:flex;margin:0}
/* same size / thickness / corners as the header action buttons (.gp-btn) */
.gp-pbtn{flex:1 1 0;width:100%;min-width:0;display:flex;align-items:center;justify-content:center;gap:calc(6px * var(--hd-s));height:var(--action-h);padding:calc(4px * var(--hd-s)) calc(10px * var(--hd-s));
  border:none;border-radius:var(--btn-r);font-size:calc(.67rem * var(--hd-s));font-weight:600;line-height:1;white-space:nowrap;overflow:hidden;text-decoration:none;
  transition:background .2s,transform .15s}
.gp-pbtn:active{transform:scale(.98)}
.gp-pbtn.learn{background:var(--btn-grey);color:var(--text,#F0F2FF);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px)}
.gp-pbtn.learn:hover{background:rgba(var(--fg,255,255,255),.2)}
.gp-pbtn.manage{background:var(--blue);color:#000;font-weight:800;letter-spacing:.1px}
.gp-pbtn.manage:hover{background:var(--blue-hover)}
@media(max-width:490px){.gp-pause{padding:10px;border-radius:9px}.gp-pause-title{font-size:.7rem}.gp-pause-body{font-size:.64rem}.gp-pause-btns{gap:6px}.gp-pbtn{font-size:.63rem;padding:3px 8px}}
@media(max-width:360px){.gp-pbtn{font-size:.6rem;padding:3px 6px}}
/* RESPONSIVE ONLY (max-width:768px): pause card, "Your membership is pending" card and Answer-questions modal get the same +10% (--hd-s) as the header;
   their buttons use exactly the header action-button (.gp-btn) height, text size, weight and padding */
@media(max-width:768px){
  .gp-pause{padding:calc(11px * var(--hd-s)) calc(12px * var(--hd-s)) calc(10px * var(--hd-s));border-radius:calc(10px * var(--hd-s))}
  .gp-pause-top{gap:calc(9px * var(--hd-s))}
  .gp-pause-ic{width:calc(28px * var(--hd-s));height:calc(28px * var(--hd-s));font-size:calc(.9rem * var(--hd-s))}
  .gp-pause-title{font-size:calc(.76rem * var(--hd-s))}
  .gp-x{width:calc(22px * var(--hd-s));height:calc(22px * var(--hd-s));font-size:calc(.85rem * var(--hd-s))}
  .gp-pause-body{font-size:calc(.68rem * var(--hd-s));margin:calc(7px * var(--hd-s)) 0 calc(10px * var(--hd-s))}
  .gp-pause-btns{gap:calc(8px * var(--hd-s))}
  .gp-pbtn,.gpjq-btn{height:var(--action-h);padding:calc(4px * var(--hd-s)) calc(10px * var(--hd-s));gap:calc(6px * var(--hd-s));
    font-size:calc(clamp(.58rem, .5rem + .6vw, .72rem) * var(--hd-s) * 1.015);line-height:1}
  .gpjq-btn i{font-size:calc(12px * var(--hd-s))}
  .gpjq-btn.ghost{font-weight:600}
  #gpJqModal{--jq-pad:calc(clamp(10px,3.4vw,18px) * var(--hd-s));--jq-fs:calc(clamp(.68rem,2.3vw,.8rem) * var(--hd-s));--jq-tb-h:calc(clamp(44px,12vw,54px) * var(--hd-s))}
  .gpjq-bar{gap:calc(clamp(6px,2vw,8px) * var(--hd-s))}
  .gpjq-x{width:calc(clamp(30px,8.4vw,34px) * var(--hd-s));height:calc(clamp(30px,8.4vw,34px) * var(--hd-s))}
  .gpjq-title{font-size:calc(clamp(.8rem,2.7vw,.92rem) * var(--hd-s))}
  .gpjq-note{font-size:calc(clamp(.62rem,2vw,.7rem) * var(--hd-s))}
  .gpjq-err{font-size:calc(clamp(.62rem,2vw,.7rem) * var(--hd-s))}
  .gpjq-q{padding:calc(clamp(10px,3vw,14px) * var(--hd-s));margin-bottom:calc(clamp(8px,2.6vw,12px) * var(--hd-s));border-radius:calc(10px * var(--hd-s))}
  .gpjq-q label{font-size:calc(clamp(.68rem,2.3vw,.78rem) * var(--hd-s))}
  .gpjq-q em{font-size:calc(clamp(.52rem,1.7vw,.58rem) * var(--hd-s))}
  .gpjq-q textarea{min-height:calc(clamp(58px,17vw,74px) * var(--hd-s));font-size:calc(clamp(.7rem,2.4vw,.8rem) * var(--hd-s))}
}

/* ── shared shimmer (used by the highlights / feed-grid / post skeletons) ── */
.sk{background:linear-gradient(90deg,rgba(255,255,255,.05) 25%,rgba(255,255,255,.12) 50%,rgba(255,255,255,.05) 75%);
  background-size:200% 100%;animation:gpShim 1.3s linear infinite;border-radius:6px}
@keyframes gpShim{to{background-position:-200% 0}}

/* pause sheets + file menu sheet */
.gp-ov{display:none;position:fixed;inset:0;z-index:999999999;background:rgba(0,0,0,.62);backdrop-filter:blur(4px);align-items:flex-end;justify-content:center}
.gp-ov.open{display:flex}
.gp-sheet{width:100%;max-width:400px;background:rgba(12,12,28,.98);border:1px solid var(--border);border-radius:20px 20px 0 0;
  padding:8px 14px calc(14px + env(safe-area-inset-bottom));animation:gpUp .3s cubic-bezier(.32,.72,0,1)}
@keyframes gpUp{from{transform:translateY(60px);opacity:0}to{transform:none;opacity:1}}
.gp-handle{width:34px;height:4px;border-radius:4px;background:rgba(var(--fg,255,255,255),.2);margin:0 auto 14px}
.gp-sheet h4{font-size:.86rem;margin-bottom:3px}
.gp-sheet .sub{font-size:.66rem;color:var(--muted);margin-bottom:10px}
.gp-sheet p{font-size:.72rem;line-height:1.6;color:rgba(var(--fg,255,255,255),.62);margin-bottom:8px}
.gp-list{list-style:none;display:flex;flex-direction:column;gap:7px;margin-bottom:12px}
.gp-list li{display:flex;gap:8px;font-size:.7rem;line-height:1.5;color:rgba(var(--fg,255,255,255),.7)}
.gp-list i{font-size:.9rem;flex-shrink:0}
.gp-sheet .primary{width:100%;padding:10px;border:none;border-radius:var(--action-r);background:var(--blue);color:#000;font-size:.74rem;font-weight:700;display:flex;align-items:center;justify-content:center;gap:6px}
.gp-sheet .cancel{width:100%;padding:9px;margin-top:8px;border-radius:var(--action-r);background:rgba(var(--fg,255,255,255),.06);border:1px solid var(--border);color:rgba(var(--fg,255,255,255),.7);font-size:.72rem;font-weight:600}
@media(min-width:791px){.gp-ov{align-items:center}.gp-sheet{border-radius:20px;padding-top:22px}.gp-handle{display:none}}

/* ── FILE MENU SHEET — compact on normal view, lines between the buttons ── */
.gp-sheet.gp-fs-sheet{max-width:270px;padding:8px 12px calc(10px + env(safe-area-inset-bottom));border-radius:16px 16px 0 0}
.gp-fs-sheet .gp-handle{margin-bottom:8px}
.gp-fs-title{font-size:.64rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);padding:2px 4px 8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;border-bottom:1px solid var(--border)}
.gp-fs-item{display:flex;align-items:center;gap:9px;width:100%;padding:9px 4px;border:none;border-bottom:1px solid var(--border);border-radius:0;background:none;color:rgba(var(--fg,255,255,255),.88);
  font-size:.72rem;font-weight:500;text-align:left;text-decoration:none}
.gp-fs-item:hover{background:rgba(36,89,184,.16)}
.gp-fs-item i{font-size:.92rem;color:var(--blue-soft);width:16px;text-align:center;flex-shrink:0}
.gp-fs-item.active i{color:#8fb4ff}
.gp-fs-sheet .cancel{margin-top:8px;padding:7px;font-size:.68rem}
@media(min-width:791px){.gp-sheet.gp-fs-sheet{border-radius:16px;padding-top:12px}}

@media(max-width:490px){.gp-sheet h4{font-size:.8rem}.gp-sheet p,.gp-list li{font-size:.66rem}.gp-sheet .primary,.gp-sheet .cancel{font-size:.68rem;padding:8px}.gp-fs-item{font-size:.68rem;padding:8px 4px}.gp-fs-sheet .cancel{padding:7px;font-size:.64rem}}

/* RESPONSIVE VIEW: the file menu sheet touches both ends of the screen, with fluid (premium) sizing —
   comfortable to tap, but smaller than before */
@media(max-width:790px){
  .gp-sheet.gp-fs-sheet{max-width:100%;width:100%;border-left:none;border-right:none;border-bottom:none;border-radius:calc(18px * var(--md-s)) calc(18px * var(--md-s)) 0 0;
    padding:calc(7px * var(--md-s)) var(--pad-x) max(calc(9px * var(--md-s)),env(safe-area-inset-bottom))}
  .gp-fs-title{font-size:calc(11px * var(--md-s));padding:calc(2px * var(--md-s)) 2px calc(7px * var(--md-s))}
  .gp-fs-item{padding:calc(8px * var(--md-s)) 2px;font-size:calc(12.5px * var(--md-s));gap:calc(10px * var(--md-s))}
  .gp-fs-item i{font-size:calc(15px * var(--md-s));width:calc(17px * var(--md-s))}
  .gp-fs-sheet .cancel{padding:calc(9px * var(--md-s));font-size:calc(12px * var(--md-s));border-radius:calc(11px * var(--md-s))}
}
@media(max-width:490px){
  .gp-fs-item{padding:calc(7px * var(--md-s)) 2px;font-size:calc(12px * var(--md-s));gap:calc(9px * var(--md-s))}
  .gp-fs-item i{font-size:calc(14px * var(--md-s));width:calc(16px * var(--md-s))}
  .gp-fs-sheet .cancel{padding:calc(8px * var(--md-s));font-size:calc(11.5px * var(--md-s));border-radius:calc(10px * var(--md-s))}
}
@media(max-width:390px){
  .gp-fs-item{padding:calc(6.5px * var(--md-s)) 2px;font-size:calc(11.5px * var(--md-s));gap:calc(8px * var(--md-s))}
  .gp-fs-item i{font-size:calc(13.5px * var(--md-s));width:calc(15px * var(--md-s))}
  .gp-fs-sheet .cancel{padding:calc(7px * var(--md-s));font-size:calc(11px * var(--md-s));border-radius:calc(9px * var(--md-s))}
}

#discLockToast{display:none;position:fixed;bottom:90px;left:50%;transform:translateX(-50%);background:rgba(20,18,18,.95);
  border:1px solid rgba(36,89,184,.55);color:#fff;font-size:13px;font-weight:500;padding:12px 20px;border-radius:30px;z-index:2147483600;white-space:nowrap;align-items:center;gap:8px;box-shadow:0 8px 30px rgba(0,0,0,.5)}
#discLockToast.show{display:flex}
#discLockToast i{color:var(--blue)}

/* ═════════ PRIVATE POD EXPLAINER (shown instead of the feed to people who haven't joined a private pod) ═════════ */
.gp-priv{margin:0;padding:18px var(--pad-x) 26px;background:var(--solid);border-top:none;border-bottom:3px solid var(--post-sep)}
.gp-priv-hero{text-align:center;padding:6px 4px 16px}
.gp-priv-hero h2{font-size:1rem;font-weight:700;margin-bottom:6px}
.gp-priv-hero p{font-size:.74rem;line-height:1.65;color:rgba(var(--fg,255,255,255),.62);max-width:430px;margin:0 auto}
.gp-priv-status{display:flex;align-items:flex-start;gap:10px;padding:11px 12px;margin-bottom:14px;border-radius:var(--action-r);
  background:rgba(var(--feed-blue-rgb),.12);border:1px solid rgba(var(--feed-blue-rgb),.4);font-size:.72rem;line-height:1.55;color:rgba(var(--fg,255,255,255),.82)}
.gp-priv-status i{font-size:1.1rem;color:var(--blue-soft);flex-shrink:0;margin-top:1px}
.gp-priv-status.warn{background:rgba(239,68,68,.08);border-color:rgba(239,68,68,.3)}
.gp-priv-status.warn i{color:#f87171}
.gp-priv-sec{padding:14px 0;border-top:1px solid var(--border)}
.gp-priv-sec h3{display:flex;align-items:center;gap:8px;font-size:.8rem;font-weight:700;margin-bottom:7px}
.gp-priv-sec h3 i{width:26px;height:26px;border-radius:var(--action-r);background:rgba(var(--feed-blue-rgb),.16);color:var(--blue-soft);display:flex;align-items:center;justify-content:center;font-size:.95rem;flex-shrink:0}
.gp-priv-sec p{font-size:.72rem;line-height:1.65;color:rgba(var(--fg,255,255,255),.62)}
.gp-priv-sec p + p{margin-top:6px}
.gp-priv-ul{list-style:none;display:flex;flex-direction:column;gap:6px;margin-top:6px}
.gp-priv-ul li{display:flex;align-items:flex-start;gap:8px;font-size:.72rem;line-height:1.55;color:rgba(var(--fg,255,255,255),.68)}
.gp-priv-ul li i{font-size:.9rem;flex-shrink:0;margin-top:1px}
.gp-priv-ul li i.ok{color:#4ade80}
.gp-priv-ul li i.no{color:#f87171}
.gp-priv-steps{list-style:none;display:flex;flex-direction:column;gap:9px;margin-top:8px;counter-reset:pst}
.gp-priv-steps li{position:relative;padding-left:30px;font-size:.72rem;line-height:1.55;color:rgba(var(--fg,255,255,255),.68);counter-increment:pst}
.gp-priv-steps li::before{content:counter(pst);position:absolute;left:0;top:0;width:21px;height:21px;border-radius:50%;background:var(--blue);color:#fff;font-size:.64rem;font-weight:700;display:flex;align-items:center;justify-content:center}
.gp-priv-steps li b{color:var(--text,#F0F2FF)}
/* blue button → black text */
.gp-priv-cta{display:flex;align-items:center;justify-content:center;gap:6px;width:100%;height:34px;margin-top:6px;border:none;border-radius:var(--btn-r);
  background:var(--blue);color:#000;font-size:.74rem;font-weight:700;transition:background .2s,transform .15s}
.gp-priv-cta:hover{background:var(--blue-hover)}
.gp-priv-cta:active{transform:scale(.98)}
@media(max-width:490px){
  .gp-priv-hero h2{font-size:.9rem}.gp-priv-hero p,.gp-priv-sec p,.gp-priv-ul li,.gp-priv-steps li,.gp-priv-status{font-size:.66rem}
  .gp-priv-sec h3{font-size:.74rem}
}

/* ══════════════════════════════════════════════════════════════
   GROUP FEED — home.php-style. Every former red is now --feed-blue.
   ══════════════════════════════════════════════════════════════ */
#groupFeed{padding:0 0 30px}
/* the very first item in the feed never gets a dark line above it */
#groupFeed > .gf-card-unit:first-child > .gf-post,
#groupFeed > .gf-card-unit:first-child > .gf-file{border-top:none!important}
#groupFeed > .gf-card-unit:first-child{margin-top:0}
/* FEED AREA IS RED: avatar borders, action buttons, carousel dots, "more", mentions, top-posts strip */
#groupFeed{--feed-blue:#ff2b2b;--feed-blue-rgb:255,43,43;--blue:#ff2b2b;--blue-hover:#ff4c4c;--blue-soft:#ff6b6b}
/* the empty state and the private state stay BLUE inside the red feed area */
#groupFeed .gp-empty-post,.gp-priv,.gp-empty-post{--feed-blue:#2b63d1;--feed-blue-rgb:43,99,209;--blue:#2459B8;--blue-hover:#2E68C9;--blue-soft:#5b8fe6}
.gp-top{--feed-blue:#ff2b2b;--blue:#2459B8;--blue-hover:#2E68C9;--blue-soft:#5b8fe6}   /* only its avatar border is red; text, icon, badge, chevron and lines stay blue */
.gf-card-unit{display:block}

.gf-post{position:relative;background:var(--solid);border-bottom:3px solid var(--post-sep);padding-bottom:calc(12px * var(--gf-s));scroll-margin-top:110px}
.gf-card-unit:has(+ .gf-card-unit > .gf-post.gf-long-header) > .gf-post{border-bottom:none}
.gp-sugg:has(+ .gf-card-unit > .gf-post.gf-long-header){border-bottom:none}

.gf-post-enter{animation:gfPostFadeIn .45s cubic-bezier(.22,1,.36,1) both}
@keyframes gfPostFadeIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}

.gf-header{display:flex;align-items:center;gap:calc(10px * var(--gf-s));padding:calc(11px * var(--gf-s)) calc(12px * var(--gf-s))}
/* thicker blue ring on the feed profile pictures */
.gf-header .av-wrap{position:relative;display:block;flex-shrink:0;line-height:0}.gf-header .av-wrap .on-dot{position:absolute;right:2%;bottom:2%;width:clamp(9px,32%,16px);height:clamp(9px,32%,16px);border-radius:50%;background:#22c55e;border:clamp(1.5px,.5vw,2.5px) solid var(--lane,#070924);box-sizing:border-box;pointer-events:none}@media (max-width:768px){.gf-header .av-wrap .on-dot{border-width:calc(clamp(1.5px,.5vw,2.5px) * 1.155)}}
.gf-avatar{width:calc(34px * var(--gf-s));height:calc(34px * var(--gf-s));border-radius:50%;object-fit:cover;border:1.6px solid var(--red,#FF2D2D);cursor:pointer;flex-shrink:0}
.gf-header-info{flex:1;min-width:0;display:flex;flex-direction:column}
.gf-username-row{display:flex;align-items:center;gap:calc(6px * var(--gf-s));min-width:0;margin-bottom:2px}
.gf-username{font-size:calc(13px * var(--gf-s));font-weight:700;color:#fff;cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.gf-admin-chip{color:#FFD700;font-size:calc(9px * var(--gf-s));font-weight:700;background:rgba(255,215,0,.1);padding:1px calc(6px * var(--gf-s));border-radius:calc(8px * var(--gf-s));border:1px solid rgba(255,215,0,.3);flex-shrink:0}
.gf-likes-text .dz-vb,.gf-caption .dz-vb,.ipv-liker-name .dz-vb{width:1em;height:1em;margin-left:.28em}
.gf-caption b.gf-cap-user .dz-vb{margin-left:.22em}
.gf-new-flag{position:absolute;right:calc(clamp(6px,5.02px + .59vw,9px) * var(--gf-s));top:50%;transform:translateY(-50%);font-size:calc(clamp(10.5px,10.01px + .3vw,12.5px) * var(--gf-s));font-weight:800;color:var(--red,#FF2D2D);letter-spacing:.03em;text-transform:uppercase;pointer-events:none;user-select:none}
.gf-new-tag{color:var(--feed-blue);font-size:calc(11px * var(--gf-s));font-weight:800;flex-shrink:0}
.gf-dot-sep{color:rgba(255,255,255,.35);font-size:calc(12px * var(--gf-s));flex-shrink:0}
.gf-dot-sep-red{color:var(--feed-blue);font-size:calc(13px * var(--gf-s));flex-shrink:0;line-height:1}
.gf-track-btn{background:none;border:none;padding:0;color:var(--feed-blue);font-size:calc(12px * var(--gf-s));font-weight:700;cursor:pointer;flex-shrink:0;text-decoration:underline;text-decoration-color:var(--feed-blue);text-underline-offset:2px;text-decoration-thickness:1.5px;position:relative;top:-1px}
.gf-info-switcher{position:relative;min-height:calc(14px * var(--gf-s))}
.gf-switch-pane{position:absolute;left:0;top:0;right:0;opacity:0;pointer-events:none;transition:opacity .35s ease}
.gf-switch-pane.active{position:relative;opacity:1;pointer-events:all}
.gf-sub-line.gf-partner-line{color:#fff;font-weight:500}
.gf-sub-line.gf-partner-line span{font-size:.92em}
.gf-sub-line.gf-partner-line b{color:#fff;font-weight:600}
.gf-sub-line{font-size:calc(clamp(10.1px, 9.93px + 0.046vw, 10.6px) * var(--gf-s));color:rgba(220,224,255,.55);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.gf-audio-tag{display:flex;align-items:center;gap:calc(6px * var(--gf-s));cursor:pointer}
.gf-audio-icon{display:inline-flex;align-items:flex-end;gap:2px;height:calc(9px * var(--gf-s));flex-shrink:0}
.gf-eq-bar{width:2px;height:calc(9px * var(--gf-s));border-radius:2px;background:rgba(255,255,255,.65);display:inline-block;transform-origin:bottom;animation:gfEq .9s ease-in-out infinite}
.gf-eq-bar:nth-child(1){animation-delay:-.6s}.gf-eq-bar:nth-child(2){animation-delay:-.2s}.gf-eq-bar:nth-child(3){animation-delay:-.9s}
@keyframes gfEq{0%{transform:scaleY(.35)}25%{transform:scaleY(1)}50%{transform:scaleY(.5)}75%{transform:scaleY(.85)}100%{transform:scaleY(.35)}}
.gf-audio-text{font-size:calc(clamp(10.1px, 9.93px + 0.046vw, 10.6px) * var(--gf-s));font-weight:500;color:rgba(220,224,255,.6);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:200px}
.gf-kebab{background:none;border:none;color:#fff;font-size:calc(22px * var(--gf-s));padding:calc(6px * var(--gf-s)) calc(4px * var(--gf-s)) calc(6px * var(--gf-s)) calc(10px * var(--gf-s));margin-right:calc(-6px * var(--gf-s));flex-shrink:0;position:relative;top:calc(-3px * var(--gf-s));display:flex;align-items:center;justify-content:center;line-height:1}
.gf-kebab i{display:inline-block;transform:scaleX(1.18)}
.gf-kebab:active{transform:scale(1.08)}

.gf-header-caption{padding:0 calc(12px * var(--gf-s)) calc(9px * var(--gf-s));font-size:calc(12.5px * var(--gf-s));line-height:1.5;color:var(--cap,rgba(255,255,255,.85));word-break:break-word}
.gf-post.gf-long-header .gf-header-caption{display:none}
.gf-post.gf-long-header{position:relative}
.gf-post.gf-long-header .gf-header{position:absolute;top:0;left:0;right:0;z-index:8;padding-bottom:calc(22px * var(--gf-s));background:linear-gradient(180deg,rgba(0,0,0,.68) 0%,rgba(0,0,0,.34) 55%,transparent 100%)}
.gf-post.gf-long-header .gf-username,.gf-post.gf-long-header .gf-sub-line,.gf-post.gf-long-header .gf-audio-text,.gf-post.gf-long-header .gf-kebab{color:#fff;text-shadow:0 1px 4px rgba(0,0,0,.5)}
.gf-post.gf-long-header .gf-top-left-tags,.gf-post.gf-long-header .gf-top-right-tags{top:calc(58px * var(--gf-s))}

.gf-media-wrap{position:relative;width:100%;background:#000;overflow:hidden;aspect-ratio:4/4.2;transition:height .18s ease;touch-action:pan-y}
.gf-media-track{display:flex;height:100%;transition:transform .28s cubic-bezier(.22,1,.36,1);will-change:transform}
.gf-media-item{flex:0 0 100%;height:100%;position:relative;background:#000}
.gf-media-item img,.gf-media-item video{width:100%;height:100%;object-fit:cover;display:block;cursor:pointer;-webkit-user-drag:none;user-select:none;-webkit-user-select:none}
.gf-media-wrap.gf-multi{aspect-ratio:4/3.5}
.gf-media-wrap.gf-multi .gf-media-item{display:flex;align-items:center;justify-content:center}
.gf-media-wrap.gf-multi .gf-media-item img,.gf-media-wrap.gf-multi .gf-media-item video{object-fit:contain;background:#000}
.gf-media-wrap.gf-multi .gf-media-item img.gf-fit-cover,.gf-media-wrap.gf-multi .gf-media-item video.gf-fit-cover{object-fit:cover}
.gf-media-wrap.gf-textonly{aspect-ratio:auto;min-height:calc(130px * var(--gf-s));background:var(--solid);display:flex;flex-direction:column}
.gf-media-wrap.gf-textonly .gf-social-proof-strip,
.gf-media-wrap.gf-textonly .gf-partnership-tag{position:relative;left:auto;bottom:auto;align-self:flex-start;margin:0 calc(18px * var(--gf-s)) calc(20px * var(--gf-s))}
.gf-dots{position:absolute;left:0;right:0;bottom:calc(10px * var(--gf-s));z-index:6;display:flex;align-items:center;justify-content:center;gap:calc(5px * var(--gf-s));flex-wrap:wrap;row-gap:calc(5px * var(--gf-s))}
.gf-dot{width:calc(5px * var(--gf-s));height:calc(5px * var(--gf-s));border-radius:50%;background:rgba(255,255,255,.35);box-shadow:0 0 3px rgba(0,0,0,.65);transition:background .18s,transform .18s;flex-shrink:0}
.gf-dot.active{background:var(--feed-blue);transform:scale(1.3)}

.gf-media-arrow{position:absolute;top:50%;transform:translateY(-50%);z-index:6;width:calc(32px * var(--gf-s));height:calc(32px * var(--gf-s));padding:0;margin:0;
  border:none;outline:none;border-radius:50%;background:rgba(0,0,0,.5);color:#fff;box-shadow:none;
  -webkit-appearance:none;appearance:none;display:flex;align-items:center;justify-content:center;overflow:hidden;
  cursor:pointer;-webkit-tap-highlight-color:transparent;transition:background .2s,transform .15s}
.gf-media-arrow:hover{background:rgba(0,0,0,.68)}
.gf-media-arrow:active{transform:translateY(-50%) scale(.92)}
.gf-media-arrow i{font-size:calc(20px * var(--gf-s));color:#fff;line-height:1;display:block}
.gf-arrow-left{left:calc(10px * var(--gf-s))}.gf-arrow-right{right:calc(10px * var(--gf-s))}

.gf-top-left-tags{position:absolute;top:calc(10px * var(--gf-s));left:calc(10px * var(--gf-s));z-index:6;display:flex;flex-direction:column;align-items:flex-start;gap:calc(5px * var(--gf-s));pointer-events:none}
.gf-top-left-tags>*{pointer-events:all;flex:none;width:max-content}
.gf-ai-tag{display:flex;align-items:center;gap:calc(4px * var(--gf-s));background:rgba(59,130,246,.18);border:1px solid rgba(59,130,246,.35);border-radius:calc(6px * var(--gf-s));padding:calc(4px * var(--gf-s)) calc(8px * var(--gf-s)) calc(4px * var(--gf-s)) calc(6px * var(--gf-s));font-size:calc(9px * var(--gf-s));font-weight:600;color:#fff;backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);white-space:nowrap}
.gf-ai-tag i{font-size:calc(11px * var(--gf-s));color:#7fb2ff}
.gf-top-right-tags{position:absolute;top:calc(10px * var(--gf-s));right:calc(10px * var(--gf-s));z-index:6;display:flex;flex-direction:column;align-items:flex-end;gap:calc(5px * var(--gf-s));pointer-events:none}
.gf-media-counter{background:rgba(22,22,24,.78);color:#fff;font-size:calc(9px * var(--gf-s));font-weight:800;letter-spacing:.3px;padding:calc(3px * var(--gf-s)) calc(7px * var(--gf-s));border-radius:calc(5px * var(--gf-s))}
.gf-type-tag{display:flex;align-items:center;gap:calc(4px * var(--gf-s));background:rgba(22,22,24,.78);border-radius:calc(5px * var(--gf-s));padding:calc(3px * var(--gf-s)) calc(7px * var(--gf-s)) calc(3px * var(--gf-s)) calc(5px * var(--gf-s))}
.gf-type-tag i{font-size:calc(10.5px * var(--gf-s));color:#fff}
.gf-type-tag span{font-size:calc(9px * var(--gf-s));font-weight:700;color:#fff;text-transform:uppercase;letter-spacing:.3px;white-space:nowrap}

.gf-social-proof-strip{position:absolute;left:calc(12px * var(--gf-s));bottom:calc(30px * var(--gf-s));z-index:6;display:flex;flex-direction:row-reverse;gap:calc(7px * var(--gf-s));pointer-events:none;transition:opacity .25s ease,visibility .25s ease}
.gf-social-proof-strip.gf-sp-raised{bottom:calc(76px * var(--gf-s))}
.gf-sp-avatar-wrap{position:relative;pointer-events:all;display:inline-block;text-decoration:none}
.gf-sp-avatar-wrap:nth-child(1){animation:gfSpFloat 3s ease-in-out infinite}
.gf-sp-avatar-wrap:nth-child(2){animation:gfSpFloat 3s ease-in-out infinite .4s}
.gf-sp-avatar-wrap:nth-child(3){animation:gfSpFloat 3s ease-in-out infinite .8s}
.gf-sp-avatar-wrap:nth-child(4){animation:gfSpFloat 3s ease-in-out infinite 1.2s}
@keyframes gfSpFloat{0%,100%{transform:translateY(0)}50%{transform:translateY(-6px)}}
.gf-sp-avatar{width:calc(46px * var(--gf-s));height:calc(46px * var(--gf-s));border-radius:50%;object-fit:cover;border:2.5px solid var(--red);display:block;box-shadow:0 4px 12px rgba(0,0,0,.7)}
.gf-sp-badge{position:absolute;bottom:-2px;right:-2px;width:calc(16px * var(--gf-s));height:calc(16px * var(--gf-s));border-radius:50%;display:flex;align-items:center;justify-content:center;z-index:2}
.gf-sp-badge i{font-size:calc(8px * var(--gf-s));color:#fff;line-height:1}
/* both badges (heart + repost) are the size of the like button; the like badge is a real heart outlined in the feed background */
.gf-sp-badge.is-like,.gf-sp-badge.is-rep{--spb:calc(clamp(17px, 15.02px + 0.87vw, 22px) * var(--gf-s));width:var(--spb);height:var(--spb)}
.gf-sp-badge.is-like{background:none;bottom:calc(-5px * var(--gf-s));right:calc(-6px * var(--gf-s))}
.gf-sp-badge.is-like svg{width:100%;height:100%;display:block;overflow:visible}
.gf-sp-badge.is-like .sp-ring{fill:var(--solid,var(--lane,#070924));stroke:var(--solid,var(--lane,#070924))}
.gf-sp-badge.is-like .sp-fill{fill:#FF2D2D}
.gf-sp-badge.is-rep{width:calc(var(--spb) * .88);height:calc(var(--spb) * .88);bottom:calc(-4px * var(--gf-s));right:calc(-5px * var(--gf-s));box-shadow:0 0 0 calc(2px * var(--gf-s)) var(--solid,var(--lane,#070924))}
.gf-sp-badge.is-rep i{font-size:calc(var(--spb) * .6);color:#fff;line-height:1;display:block}
.gf-partnership-tag{position:absolute;z-index:7;left:calc(12px * var(--gf-s));bottom:calc(34px * var(--gf-s));display:inline-flex;align-items:center;gap:calc(6px * var(--gf-s));padding:calc(5px * var(--gf-s)) calc(10px * var(--gf-s));border-radius:calc(8px * var(--gf-s));background:rgba(0,0,0,.55);color:var(--feed-blue);font-size:calc(11px * var(--gf-s));font-weight:600;max-width:calc(100% - calc(24px * var(--gf-s)));backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);pointer-events:none;transition:opacity .25s ease,visibility .25s ease}
.gf-partnership-tag i{font-size:calc(13px * var(--gf-s));flex-shrink:0}
.gf-partnership-tag b{color:#fff;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

.gf-post.gf-cap-open .gf-social-proof-strip,
.gf-post.gf-cap-open .gf-partnership-tag{opacity:0;visibility:hidden;pointer-events:none}
.gf-post.gf-cap-open .gf-textonly .gf-social-proof-strip,
.gf-post.gf-cap-open .gf-textonly .gf-partnership-tag{display:none}

.gf-media-controls{position:absolute;bottom:calc(12px * var(--gf-s));right:calc(12px * var(--gf-s));z-index:6;display:flex;flex-direction:column;gap:calc(8px * var(--gf-s))}
.gf-mute-btn{width:calc(28px * var(--gf-s));height:calc(28px * var(--gf-s));padding:0;border-radius:50%;background:rgba(0,0,0,.5);color:#fff;display:flex;align-items:center;justify-content:center;border:none}
.gf-mute-btn i{font-size:calc(14px * var(--gf-s))}
/* mute / unmute icon (custom speaker) */
.dz-snd{display:inline-block;width:1.12em;height:1em;background-color:currentColor;-webkit-mask:var(--dz-snd) center/contain no-repeat;mask:var(--dz-snd) center/contain no-repeat;vertical-align:middle;font-style:normal}
.dz-snd.on{--dz-snd:url(data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%22261%20218%20681%20603%22%3E%3Cpath%20fill-rule%3D%22evenodd%22%20d%3D%22M576.2%2C820.0C560.6%2C817.2%20551.8%2C812.4%20515.6%2C787.7C500.1%2C777.2%20474.4%2C759.8%20458.5%2C749.0C442.6%2C738.3%20425.4%2C726.5%20420.5%2C722.9C415.6%2C719.3%20405.2%2C712.2%20397.5%2C707.0C381.1%2C696.0%20346.4%2C671.6%20333.5%2C661.9C314.1%2C647.4%20292.3%2C621.5%20281.6%2C600.2C258.9%2C555.2%20255.1%2C512.3%20270.0%2C467.9C277.7%2C444.5%20282.6%2C435.5%20299.8%2C412.0C309.3%2C399.1%20335.0%2C374.5%20346.8%2C367.0C365.5%2C355.1%20391.4%2C337.8%20401.0%2C330.6C412.7%2C322.0%20438.1%2C304.3%20460.9%2C289.0C470.3%2C282.7%20487.8%2C270.8%20499.8%2C262.6C559.6%2C221.4%20567.2%2C217.6%20589.5%2C217.6C628.4%2C217.6%20651.1%2C243.1%20654.0%2C290.0C655.3%2C310.7%20655.3%2C742.7%20654.0%2C756.0C649.5%2C802.0%20618.5%2C827.6%20576.2%2C820.0ZM747.9%2C729.0C732.2%2C726.4%20723.6%2C712.1%20725.3%2C691.7C727.0%2C671.8%20735.1%2C664.0%20760.3%2C658.0C794.4%2C649.8%20822.2%2C631.8%20843.7%2C603.9C862.9%2C578.9%20876.0%2C533.5%20872.1%2C504.8C869.5%2C485.1%20856.5%2C452.0%20846.5%2C439.5C819.9%2C406.3%20796.9%2C390.9%20760.7%2C382.0C736.8%2C376.1%20727.9%2C367.8%20724.9%2C348.7C721.1%2C324.7%20735.0%2C309.3%20758.5%2C311.4C807.1%2C315.8%20864.9%2C348.4%20895.2%2C388.5C915.9%2C416.0%20920.6%2C425.4%20936.6%2C471.0C943.6%2C491.1%20944.3%2C540.8%20938.0%2C565.7C923.1%2C623.6%20884.6%2C673.9%20832.7%2C702.9C803.4%2C719.3%20763.6%2C731.6%20747.9%2C729.0ZM738.0%2C584.8C732.1%2C581.6%20727.6%2C574.3%20726.0%2C565.2C724.3%2C556.1%20724.4%2C479.3%20726.1%2C472.4C737.4%2C425.3%20805.6%2C472.9%20801.7%2C525.2C799.0%2C559.4%20759.6%2C596.3%20738.0%2C584.8Z%22%2F%3E%3C%2Fsvg%3E)}
.dz-snd.off{--dz-snd:url(data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%22261%20218%20681%20603%22%3E%3Cpath%20d%3D%22M576.2%2C820.0C560.6%2C817.2%20551.8%2C812.4%20515.6%2C787.7C500.1%2C777.2%20474.4%2C759.8%20458.5%2C749.0C442.6%2C738.3%20425.4%2C726.5%20420.5%2C722.9C415.6%2C719.3%20405.2%2C712.2%20397.5%2C707.0C381.1%2C696.0%20346.4%2C671.6%20333.5%2C661.9C314.1%2C647.4%20292.3%2C621.5%20281.6%2C600.2C258.9%2C555.2%20255.1%2C512.3%20270.0%2C467.9C277.7%2C444.5%20282.6%2C435.5%20299.8%2C412.0C309.3%2C399.1%20335.0%2C374.5%20346.8%2C367.0C365.5%2C355.1%20391.4%2C337.8%20401.0%2C330.6C412.7%2C322.0%20438.1%2C304.3%20460.9%2C289.0C470.3%2C282.7%20487.8%2C270.8%20499.8%2C262.6C559.6%2C221.4%20567.2%2C217.6%20589.5%2C217.6C628.4%2C217.6%20651.1%2C243.1%20654.0%2C290.0C655.3%2C310.7%20655.3%2C742.7%20654.0%2C756.0C649.5%2C802.0%20618.5%2C827.6%20576.2%2C820.0Z%22%2F%3E%3Cpath%20d%3D%22M730%20400L910%20620M910%20400L730%20620%22%20stroke%3D%22%23000%22%20stroke-width%3D%2264%22%20stroke-linecap%3D%22round%22%20fill%3D%22none%22%2F%3E%3C%2Fsvg%3E)}

/* Discuss button — text only, dark blue, no icon, no border — boxy like the other buttons */
.gf-discuss-btn{position:absolute;right:calc(12px * var(--gf-s));bottom:calc(12px * var(--gf-s));z-index:9;display:inline-flex;align-items:center;justify-content:center;
  min-height:calc(32px * var(--gf-s));background:rgba(10,10,14,.78);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);
  border:none;outline:none;border-radius:var(--action-r);padding:calc(8px * var(--gf-s)) calc(17px * var(--gf-s));color:#fff;
  font-family:Poppins,sans-serif;font-size:calc(12.5px * var(--gf-s));font-weight:700;line-height:1;cursor:pointer;
  -webkit-appearance:none;appearance:none;box-shadow:none;transition:background .2s,transform .15s}
.gf-discuss-btn:hover{background:rgba(20,20,28,.88)}
.gf-discuss-btn:active{transform:scale(.94)}
.gf-media-wrap.gf-ctl1 .gf-discuss-btn{bottom:calc(50px * var(--gf-s))}
.gf-media-wrap.gf-ctl2 .gf-discuss-btn{bottom:calc(86px * var(--gf-s))}

.gf-textonly-clickable{flex:1 1 auto;display:flex;align-items:flex-start;justify-content:flex-start;width:100%;padding:calc(14px * var(--gf-s)) calc(18px * var(--gf-s)) calc(18px * var(--gf-s));cursor:pointer}
.gf-media-wrap.gf-textonly.gf-tl .gf-textonly-clickable{padding-top:calc(70px * var(--gf-s))}
.gf-media-wrap.gf-textonly .gf-top-right-tags{top:calc(12px * var(--gf-s));right:calc(14px * var(--gf-s))}
.gf-center-caption-wrap{width:100%;display:block;text-align:left}
.gf-tag-gap{display:block;float:right;width:calc(62px * var(--gf-s));height:1em;font-size:calc(12.5px * var(--gf-s));pointer-events:none}
.gf-center-caption-text{color:var(--cap,rgba(255,255,255,.85));font-size:calc(12.5px * var(--gf-s));font-weight:400;line-height:1.6;max-width:100%;word-break:break-word;text-align:left}
.gf-center-caption-text.gf-t-s{font-size:calc(clamp(.95rem,2.8vw + .1rem,1.15rem) * var(--gf-s));font-weight:700;line-height:1.4}
.gf-center-caption-text.gf-t-m{font-size:calc(clamp(.86rem,1.5vw + .4rem,.95rem) * var(--gf-s));font-weight:600;line-height:1.5}
.gf-center-caption-text.gf-t-l{font-size:calc(clamp(.8rem,.8vw + .55rem,.86rem) * var(--gf-s));font-weight:500;line-height:1.6}

.gf-actions{display:flex;align-items:center;justify-content:space-between;padding:calc(9px * var(--gf-s)) calc(10px * var(--gf-s)) 0;position:relative}
.gf-actions-left{display:flex;align-items:center;gap:calc(18px * var(--gf-s))}
.gf-action-group{display:flex;align-items:center;gap:calc(6px * var(--gf-s))}
.gf-action-btn{background:none;border:none;color:#fff;font-size:calc(22px * var(--gf-s));padding:2px;display:flex;align-items:center;transition:transform .12s ease}
/* comments turned off for a post: the same "chat-off" icon as home instead of the speech bubble, and no number (the button still opens the sheet) */
.gf-comment .cm-off-ic{ display:none; }
.gf-comment.cm-off svg{ display:none !important; }
.gf-comment.cm-off .cm-off-ic{ display:block; width:100%; height:100%; font-size:1.166em; line-height:1; text-align:center; font-style:normal; -webkit-text-stroke:0; }
.gf-comment-count[data-off="1"]{ display:none !important; }
.gf-action-btn:active{transform:scale(1.22)}
.gf-action-btn .gf-ic-on{display:none}
/* rail icons + repost badge drawn exactly like the home page: even-line SVGs */
.sp-rp{display:inline-block;width:1.166em;height:1.166em;margin:-.083em;line-height:0;font-style:normal;vertical-align:middle}
.sp-rp svg{display:block;width:100%;height:100%;overflow:visible}
.sp-ring{stroke:#000}
:root[data-theme="light"] .sp-ring{stroke:#fff}
.gf-action-group.active .sp-fillable svg{fill:currentColor}
.gf-action-group.active .gf-action-btn .gf-ic-on{display:inline}
.gf-action-group.active .gf-action-btn .gf-ic-off{display:none}
.gf-action-group.active .gf-action-btn{color:var(--feed-blue)}
.gf-action-count[data-hidden="1"]{display:none !important}
.gf-action-count{font-size:calc(12px * var(--gf-s));font-weight:700;color:#fff}
@keyframes gf-repost-arc-swap{0%{transform:rotate(0) scale(1)}35%{transform:rotate(140deg) scale(1.15)}50%{transform:rotate(180deg) scale(1.2)}65%{transform:rotate(220deg) scale(1.15)}100%{transform:rotate(360deg) scale(1)}}
.gf-action-btn.arc-swap-active i{animation:gf-repost-arc-swap .6s cubic-bezier(.33,1,.68,1);transition:none!important}

.gf-social-row{display:flex;align-items:center;gap:calc(7px * var(--gf-s));padding:calc(6px * var(--gf-s)) calc(12px * var(--gf-s)) 0;cursor:pointer}
.gf-social-avatars{display:flex;flex-shrink:0}
.gf-social-avatars img{width:calc(20px * var(--gf-s));height:calc(20px * var(--gf-s));border-radius:50%;object-fit:cover;border:1.6px solid rgba(200,203,214,.65);margin-left:calc(-7px * var(--gf-s))}
.gf-social-avatars img:first-child{margin-left:0}
.gf-social-avatars img.gf-sp-rep{border-color:rgba(200,203,214,.65)}
.gf-likes-text{font-size:calc(12.5px * var(--gf-s));color:#fff}.gf-likes-text b{font-weight:700}.gf-likes-dim{color:rgba(220,224,255,.72);font-weight:400}
.gf-caption{display:none;padding:calc(5px * var(--gf-s)) calc(12px * var(--gf-s)) 0;font-size:calc(12.5px * var(--gf-s));line-height:1.5;color:var(--cap,rgba(255,255,255,.85));word-break:break-word}
.gf-post.gf-long-header .gf-caption{display:block}
.gf-caption b.gf-cap-user{margin-right:calc(5px * var(--gf-s));color:var(--feed-blue)}
.gf-more-toggle{color:var(--more,#fff);cursor:pointer;font-weight:700}.gf-more-wrap{cursor:pointer}.gf-ellipsis{color:var(--cap,rgba(255,255,255,.85));font-weight:400}
.gf-time{padding:calc(6px * var(--gf-s)) calc(12px * var(--gf-s)) 0;font-size:calc(10px * var(--gf-s));color:rgba(220,224,255,.4);letter-spacing:.3px}
.gf-time.gf-time-row{display:flex;align-items:center;justify-content:space-between;gap:calc(8px * var(--gf-s))}
.gf-time-left{display:flex;align-items:center;flex-wrap:wrap;gap:2px calc(10px * var(--gf-s));min-width:0}
.gf-zeem-hint{color:var(--feed-blue);font-weight:300;font-size:calc(10px * var(--gf-s));letter-spacing:.2px}

.gf-post.gf-post-text .gf-header{border-bottom:1px solid rgba(255,255,255,.13)}
.gf-post.gf-post-text .gf-actions{border-top:1px solid rgba(255,255,255,.13)}

/* ═════════ FILE ITEMS IN THE FEED (compact, fluid) ═════════ */
.gf-file{position:relative;background:var(--solid);border-bottom:3px solid var(--post-sep);padding-bottom:calc(9px * var(--gf-s))}
.gf-file .gf-header{padding:calc(8px * var(--gf-s)) var(--pad-x)}
.gf-file .gf-time{padding:calc(5px * var(--gf-s)) var(--pad-x) 0}
.gf-file-card{display:flex;align-items:center;gap:calc(clamp(8px,2.6vw,11px) * var(--gf-s));margin:0;width:100%;
  padding:calc(clamp(9px,2.4vw,11px) * var(--gf-s)) var(--pad-x);border-radius:0;background:rgba(255,255,255,.08);
  border:none;text-decoration:none;color:inherit;transition:background .2s}
.gf-file-card:hover{background:rgba(255,255,255,.12)}
.gf-file-card:active{background:rgba(255,255,255,.16)}
.gf-file-ic{width:calc(clamp(28px,8vw,34px) * var(--gf-s));height:calc(clamp(34px,9.6vw,42px) * var(--gf-s));display:flex;align-items:center;justify-content:center;
  font-size:calc(clamp(1.4rem,5.4vw,1.7rem) * var(--gf-s));flex-shrink:0}
.gf-file-thumb{width:calc(clamp(36px,10.5vw,42px) * var(--gf-s));height:calc(clamp(36px,10.5vw,42px) * var(--gf-s));border-radius:calc(8px * var(--gf-s));object-fit:cover;flex-shrink:0;display:block}
.gf-file-info{flex:1;min-width:0}
.gf-file-title{display:flex;align-items:center;gap:calc(5px * var(--gf-s));font-size:calc(clamp(.72rem,2.6vw,.8rem) * var(--gf-s));font-weight:600;color:#fff;min-width:0;line-height:1.25}
.gf-file-title span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}
.gf-file-title i{color:var(--blue-soft);font-size:calc(.72rem * var(--gf-s));flex-shrink:0}
.gf-file-sub{font-size:calc(clamp(.6rem,2vw,.66rem) * var(--gf-s));color:rgba(255,255,255,.5);margin-top:2px}
.gf-file-dl{width:calc(clamp(26px,7.4vw,30px) * var(--gf-s));height:calc(clamp(26px,7.4vw,30px) * var(--gf-s));border-radius:50%;background:#2459B8;color:#000;
  display:flex;align-items:center;justify-content:center;font-size:calc(clamp(.85rem,3vw,.95rem) * var(--gf-s));flex-shrink:0}

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

/* feed skeleton + loader */
.gf-skel-post{border-bottom:3px solid var(--post-sep);padding-bottom:calc(12px * var(--gf-s));background:var(--solid)}
.gf-skel-header{display:flex;align-items:center;gap:calc(10px * var(--gf-s));padding:calc(11px * var(--gf-s)) calc(12px * var(--gf-s))}
.gf-skel-avatar{width:calc(34px * var(--gf-s));height:calc(34px * var(--gf-s));border-radius:50%;flex-shrink:0}
.gf-skel-header-lines{flex:1;display:flex;flex-direction:column;gap:calc(6px * var(--gf-s))}
.gf-skel-line{height:calc(10px * var(--gf-s));border-radius:calc(5px * var(--gf-s))}.gf-skel-line.w1{width:calc(120px * var(--gf-s))}.gf-skel-line.w2{width:calc(75px * var(--gf-s));height:calc(8px * var(--gf-s))}
.gf-skel-media{width:100%;aspect-ratio:4/3.5}
.gf-skel-actions{display:flex;gap:calc(18px * var(--gf-s));padding:calc(9px * var(--gf-s)) calc(12px * var(--gf-s)) 0}.gf-skel-icon{width:calc(22px * var(--gf-s));height:calc(22px * var(--gf-s));border-radius:calc(6px * var(--gf-s))}
.gf-skel-cap{margin:calc(11px * var(--gf-s)) calc(12px * var(--gf-s)) 0;height:calc(10px * var(--gf-s));border-radius:calc(5px * var(--gf-s));width:82%}.gf-skel-cap2{margin:calc(6px * var(--gf-s)) calc(12px * var(--gf-s)) 0;height:calc(10px * var(--gf-s));border-radius:calc(5px * var(--gf-s));width:52%}
.gf-skel-avatar,.gf-skel-line,.gf-skel-media,.gf-skel-icon,.gf-skel-cap,.gf-skel-cap2{background:linear-gradient(90deg,rgba(255,255,255,.05) 25%,rgba(255,255,255,.13) 37%,rgba(255,255,255,.05) 63%);background-size:400% 100%;animation:gfSkelShimmer 1.4s ease-in-out infinite}
@keyframes gfSkelShimmer{0%{background-position:100% 50%}100%{background-position:0% 50%}}
.gf-load-more{display:flex;align-items:center;justify-content:center;padding:calc(24px * var(--gf-s)) 0}
.gf-load-more-dot{width:calc(6px * var(--gf-s));height:calc(6px * var(--gf-s));border-radius:50%;background:rgba(255,255,255,.35);margin:0 calc(3px * var(--gf-s));animation:gfLoadDot 1.1s ease-in-out infinite}
.gf-load-more-dot:nth-child(2){animation-delay:.15s}.gf-load-more-dot:nth-child(3){animation-delay:.3s}
@keyframes gfLoadDot{0%,80%,100%{transform:scale(.6);opacity:.4}40%{transform:scale(1);opacity:1}}
.gf-empty{padding:calc(70px * var(--gf-s)) calc(24px * var(--gf-s));text-align:center;color:rgba(220,224,255,.6);font-size:calc(13.5px * var(--gf-s));font-weight:500}

/* ═════════ EMPTY POD — detailed "Be the first to post" (no icon) ═════════ */
.gp-empty-post{padding:26px var(--pad-x) 30px;background:var(--solid);border-bottom:3px solid var(--post-sep);border-top:none}
.gp-ep-hero{text-align:center;padding:4px 4px 14px}
.gp-ep-hero h2{font-size:1rem;font-weight:700;margin-bottom:6px}
.gp-ep-hero p{font-size:.74rem;line-height:1.65;color:rgba(var(--fg,255,255,255),.62);max-width:430px;margin:0 auto}
.gp-ep-sec{padding:13px 0;border-top:1px solid var(--border)}
.gp-ep-sec h3{display:flex;align-items:center;gap:8px;font-size:.78rem;font-weight:700;margin-bottom:7px}
.gp-ep-sec h3 i{width:26px;height:26px;border-radius:var(--action-r);background:rgba(var(--feed-blue-rgb),.16);color:var(--blue-soft);display:flex;align-items:center;justify-content:center;font-size:.95rem;flex-shrink:0}
.gp-ep-sec p{font-size:.72rem;line-height:1.65;color:rgba(var(--fg,255,255,255),.62)}
.gp-ep-ul{list-style:none;display:flex;flex-direction:column;gap:6px;margin-top:4px}
.gp-ep-ul li{display:flex;align-items:flex-start;gap:8px;font-size:.72rem;line-height:1.55;color:rgba(var(--fg,255,255,255),.68)}
.gp-ep-ul li i{font-size:.9rem;flex-shrink:0;margin-top:1px;color:var(--blue-soft)}
.gp-ep-steps{list-style:none;display:flex;flex-direction:column;gap:9px;margin-top:6px;counter-reset:eps}
.gp-ep-steps li{position:relative;padding-left:30px;font-size:.72rem;line-height:1.55;color:rgba(var(--fg,255,255,255),.68);counter-increment:eps}
.gp-ep-steps li::before{content:counter(eps);position:absolute;left:0;top:0;width:21px;height:21px;border-radius:50%;background:var(--blue);color:#fff;font-size:.64rem;font-weight:700;display:flex;align-items:center;justify-content:center}
.gp-ep-steps li b{color:var(--text,#F0F2FF)}
/* blue button → black text */
.gp-ep-cta{display:flex;align-items:center;justify-content:center;gap:6px;width:100%;height:34px;margin-top:8px;border:none;border-radius:var(--btn-r);background:var(--blue);color:#000;font-size:.74rem;font-weight:700;transition:background .2s,transform .15s}
.gp-ep-cta:hover{background:var(--blue-hover)}
.gp-ep-cta:active{transform:scale(.98)}
.gp-ep-note{display:flex;align-items:flex-start;gap:9px;padding:10px 12px;margin-top:6px;border-radius:var(--action-r);background:rgba(255,149,0,.08);border:1px solid rgba(255,149,0,.3);font-size:.72rem;line-height:1.55;color:rgba(var(--fg,255,255,255),.8)}
.gp-ep-note i{font-size:1rem;color:#FF9500;flex-shrink:0;margin-top:1px}
@media(max-width:490px){.gp-ep-hero h2{font-size:.9rem}.gp-ep-hero p,.gp-ep-sec p,.gp-ep-ul li,.gp-ep-steps li,.gp-ep-note{font-size:.66rem}.gp-ep-sec h3{font-size:.72rem}}

/* hidden-post placeholder */
.gf-hidden-placeholder{display:flex;align-items:center;justify-content:space-between;gap:calc(12px * var(--gf-s));width:100%;min-height:calc(56px * var(--gf-s));background:#010723;padding:calc(12px * var(--gf-s)) calc(14px * var(--gf-s)) calc(12px * var(--gf-s)) calc(18px * var(--gf-s));border-bottom:3px solid var(--post-sep);animation:gfPostFadeIn .3s ease both}
.gf-hidden-left{display:flex;align-items:center;gap:calc(9px * var(--gf-s));min-width:0}
.gf-hidden-icon{width:calc(30px * var(--gf-s));height:calc(30px * var(--gf-s));border-radius:50%;background:rgba(var(--feed-blue-rgb),.12);border:1.5px solid rgba(var(--feed-blue-rgb),.4);display:flex;align-items:center;justify-content:center;font-size:calc(14px * var(--gf-s));color:var(--feed-blue);flex-shrink:0}
.gf-hidden-text{font-size:calc(13px * var(--gf-s));font-weight:600;color:rgba(255,255,255,.75)}
.gf-undo-btn{display:inline-flex;align-items:center;gap:calc(5px * var(--gf-s));background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.15);color:#fff;font-size:calc(12px * var(--gf-s));font-weight:700;padding:calc(7px * var(--gf-s)) calc(15px * var(--gf-s));border-radius:var(--action-r);cursor:pointer;flex-shrink:0;-webkit-appearance:none}
.gf-undo-btn:active{transform:scale(.96)}

/* ═════════ TOP CONTRIBUTOR ═════════ */
.gp-top{display:flex;align-items:center;gap:calc(10px * var(--gf-s));padding:calc(10px * var(--gf-s)) var(--pad-x);background:none;text-decoration:none;color:inherit;
  border:none;border-top:1px solid rgba(43,99,209,.75);border-bottom:1px solid rgba(43,99,209,.75);
  transition:background .2s}
.gp-top:hover{background:rgba(var(--feed-blue-rgb),.07)}
.gp-top-pic{width:calc(38px * var(--gf-s));height:calc(38px * var(--gf-s));border-radius:50%;object-fit:cover;border:2px solid var(--feed-blue);flex-shrink:0}
.gp-top-text{flex:1;min-width:0;display:flex;flex-direction:column;gap:calc(2px * var(--gf-s))}
.gp-top-label{display:flex;align-items:center;gap:calc(4px * var(--gf-s));font-size:calc(9.5px * var(--gf-s));font-weight:700;color:var(--blue-soft);text-transform:uppercase;letter-spacing:.6px;min-width:0;white-space:nowrap;overflow:hidden}
.gp-top-label i{font-size:calc(11px * var(--gf-s));flex-shrink:0}
.gp-top-label .gp-top-lbl-txt{overflow:hidden;text-overflow:ellipsis}
.gp-top-oat{font-size:calc(8px * var(--gf-s));font-weight:800;letter-spacing:.5px;color:#8fb4ff;background:rgba(43,99,209,.16);border:1px solid rgba(43,99,209,.4);padding:calc(1px * var(--gf-s)) calc(5px * var(--gf-s));border-radius:10px;flex-shrink:0}
.gp-top-user{font-size:calc(12.5px * var(--gf-s));font-weight:700;color:var(--text,#F0F2FF);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.gp-top-stats{font-size:calc(10px * var(--gf-s));color:rgba(var(--fg,255,255,255),.4);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.gp-top-arrow{color:rgba(43,99,209,.8);font-size:calc(18px * var(--gf-s));flex-shrink:0}
@media(max-width:490px){
  .gp-top{gap:calc(8px * var(--gf-s));padding:calc(9px * var(--gf-s)) var(--pad-x)}.gp-top-pic{width:calc(32px * var(--gf-s));height:calc(32px * var(--gf-s))}
  .gp-top-label{font-size:calc(8.5px * var(--gf-s))}.gp-top-user{font-size:calc(11.5px * var(--gf-s))}.gp-top-stats{font-size:calc(9px * var(--gf-s))}
}

/* ═════════ SUGGESTED PODS ROW ═════════ */
.gp-sugg{padding:12px 0 2px;background:var(--solid);border-bottom:3px solid var(--post-sep)}
.gp-sugg-head{display:flex;align-items:center;justify-content:space-between;padding:0 14px 10px}
.gp-sugg-kicker{font-size:.62rem;color:rgba(var(--fg,255,255,255),.45);line-height:1.2}
.gp-sugg-title{font-size:.95rem;font-weight:600;color:var(--text,#F0F2FF);line-height:1.3}
.gp-sugg-x{color:rgba(var(--fg,255,255,255),.7);font-size:22px;display:flex;padding:2px;background:none;border:none}
.gp-sugg-scroll-wrap{position:relative}
.gp-sugg-scroll{display:flex;gap:8px;overflow-x:auto;padding:0 14px 14px;scroll-padding-left:14px;scroll-padding-right:14px;scroll-snap-type:x proximity;scrollbar-width:none;-ms-overflow-style:none;-webkit-overflow-scrolling:touch}
.gp-sugg-scroll::-webkit-scrollbar{display:none}
.gp-sugg-scroll::after{content:"";flex:0 0 6px}
.gp-sugg-arrow{position:absolute;top:42%;transform:translateY(-50%);z-index:10;width:30px;height:30px;padding:0 0 3px;border-radius:var(--arrow-r);background:rgba(0,0,0,.7);border:1.5px solid rgba(var(--fg,255,255,255),.25);color:#fff;display:flex;align-items:center;justify-content:center;font-size:26px;line-height:1;opacity:0;pointer-events:none;transition:opacity .25s ease,background .2s ease}
.gp-sugg-arrow-l{left:6px}.gp-sugg-arrow-r{right:6px}
.gp-sugg-arrow:hover{background:rgba(36,89,184,.9)}
.gp-sugg-scroll-wrap:hover .gp-sugg-arrow:not(.hidden){opacity:1;pointer-events:auto}
.gp-sugg-arrow.hidden{opacity:0!important;pointer-events:none!important}
@media(max-width:768px){.gp-sugg-arrow{display:none!important}}
.gp-sugg-card{flex:0 0 205px;scroll-snap-align:start;background:var(--solid);border:1px solid rgba(36,89,184,.4);border-radius:10px;overflow:hidden;display:flex;flex-direction:column}
.gp-sugg-photo{display:block;width:100%;aspect-ratio:1/1.05;object-fit:cover;background:var(--solid)}
.gp-sugg-info{padding:9px 10px 0}
.gp-sugg-name{display:block;font-size:.82rem;font-weight:600;color:var(--text,#F0F2FF);text-decoration:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.gp-sugg-reason{font-size:.65rem;color:var(--blue-soft);margin-top:2px;min-height:1em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.gp-sugg-btns{display:flex;gap:6px;padding:8px 8px 10px;margin-top:auto}
.gp-sugg-btn,.gp-sugg-rm{flex:1;padding:7px 0;border-radius:var(--action-r);font-size:.72rem;font-weight:600;color:var(--text,#F0F2FF);text-align:center;border:none}
/* blue button → black text */
.gp-sugg-btn{flex:1.35;background:var(--blue);color:#000}
.gp-sugg-btn:hover{background:var(--blue-hover)}
.gp-sugg-btn.done{background:rgba(36,89,184,.18);color:var(--blue-soft);pointer-events:none}
.gp-sugg-rm{background:rgba(var(--fg,255,255,255),.10);border:1px solid rgba(var(--fg,255,255,255),.16);color:#e6e8f5}
@media(max-width:490px){
  .gp-sugg-card{flex-basis:172px}
  .gp-sugg-kicker{font-size:.56rem}.gp-sugg-title{font-size:.82rem}
  .gp-sugg-name{font-size:.74rem}.gp-sugg-reason{font-size:.58rem}
  .gp-sugg-btn,.gp-sugg-rm{font-size:.66rem;padding:6px 0}
}

/* ── feed responsive (790 / 490 / 390 / 330) ── */
@media(max-width:790px){
  .gf-header{padding:calc(10px * var(--gf-s)) calc(11px * var(--gf-s));gap:calc(9px * var(--gf-s))}.gf-avatar{width:calc(32px * var(--gf-s));height:calc(32px * var(--gf-s))}.gf-username{font-size:calc(12.5px * var(--gf-s))}
  .gf-sub-line,.gf-audio-text{font-size:calc(10.04px * var(--gf-s))}.gf-actions{padding:calc(8px * var(--gf-s)) calc(9px * var(--gf-s)) 0}.gf-action-btn{font-size:calc(21px * var(--gf-s))}.gf-actions-left{gap:calc(15px * var(--gf-s))}
  .gf-social-row,.gf-caption,.gf-header-caption,.gf-time{padding-left:calc(11px * var(--gf-s));padding-right:calc(11px * var(--gf-s))}.gf-caption,.gf-header-caption{font-size:calc(12px * var(--gf-s))}
  .gf-social-proof-strip.gf-sp-raised{bottom:calc(62px * var(--gf-s))}.gf-sp-avatar{width:calc(42px * var(--gf-s));height:calc(42px * var(--gf-s))}
  .gf-post.gf-long-header .gf-top-left-tags,.gf-post.gf-long-header .gf-top-right-tags{top:calc(54px * var(--gf-s))}
  .gf-double-tap-heart i{font-size:calc(70px * var(--gf-s))}
  .gf-discuss-btn{font-size:calc(12px * var(--gf-s));padding:calc(7.5px * var(--gf-s)) calc(16px * var(--gf-s));min-height:calc(31px * var(--gf-s))}
}
@media(max-width:490px){
  .gf-header{padding:calc(9px * var(--gf-s)) calc(10px * var(--gf-s));gap:calc(8px * var(--gf-s))}.gf-avatar{width:calc(29px * var(--gf-s));height:calc(29px * var(--gf-s))}.gf-username{font-size:calc(12px * var(--gf-s))}.gf-track-btn{font-size:calc(11px * var(--gf-s))}
  .gf-kebab{font-size:calc(20px * var(--gf-s))}.gf-actions{padding:calc(7px * var(--gf-s)) calc(8px * var(--gf-s)) 0}.gf-actions-left{gap:calc(13px * var(--gf-s))}.gf-action-btn{font-size:calc(19px * var(--gf-s))}.gf-action-count{font-size:calc(11px * var(--gf-s))}
  .gf-sp-avatar{width:calc(38px * var(--gf-s));height:calc(38px * var(--gf-s))}.gf-social-proof-strip.gf-sp-raised{bottom:calc(58px * var(--gf-s))}
  .gf-social-avatars img{width:calc(17px * var(--gf-s));height:calc(17px * var(--gf-s));margin-left:calc(-6px * var(--gf-s))}.gf-likes-text{font-size:calc(11.5px * var(--gf-s))}
  .gf-caption,.gf-header-caption,.gf-time{padding-left:calc(10px * var(--gf-s));padding-right:calc(10px * var(--gf-s))}.gf-caption,.gf-header-caption{font-size:calc(11.5px * var(--gf-s))}
  .gf-partnership-tag{font-size:calc(10px * var(--gf-s));padding:calc(4px * var(--gf-s)) calc(8px * var(--gf-s));left:calc(10px * var(--gf-s));bottom:calc(26px * var(--gf-s))}
  .gf-top-left-tags,.gf-top-right-tags{top:calc(8px * var(--gf-s))}.gf-top-left-tags{left:calc(8px * var(--gf-s))}.gf-top-right-tags{right:calc(8px * var(--gf-s))}
  .gf-media-wrap.gf-textonly .gf-top-right-tags{top:calc(10px * var(--gf-s));right:calc(12px * var(--gf-s))}
  .gf-post.gf-long-header .gf-top-left-tags,.gf-post.gf-long-header .gf-top-right-tags{top:calc(50px * var(--gf-s))}
  .gf-media-arrow{width:calc(26px * var(--gf-s));height:calc(26px * var(--gf-s))}.gf-media-arrow i{font-size:calc(16px * var(--gf-s))}.gf-double-tap-heart i{font-size:calc(60px * var(--gf-s))}
  .gf-discuss-btn{font-size:calc(11.5px * var(--gf-s));padding:calc(7px * var(--gf-s)) calc(15px * var(--gf-s));min-height:calc(30px * var(--gf-s));right:calc(10px * var(--gf-s));bottom:calc(10px * var(--gf-s))}
  .gf-media-wrap.gf-ctl1 .gf-discuss-btn{bottom:calc(46px * var(--gf-s))}.gf-media-wrap.gf-ctl2 .gf-discuss-btn{bottom:calc(82px * var(--gf-s))}
  .gf-media-controls{right:calc(10px * var(--gf-s));bottom:calc(10px * var(--gf-s))}
}
@media(max-width:390px){
  .gf-avatar{width:calc(27px * var(--gf-s));height:calc(27px * var(--gf-s))}.gf-username{font-size:calc(11.5px * var(--gf-s))}.gf-action-btn{font-size:calc(18px * var(--gf-s))}.gf-actions-left{gap:calc(11px * var(--gf-s))}
  .gf-sp-avatar{width:calc(34px * var(--gf-s));height:calc(34px * var(--gf-s))}.gf-social-proof-strip.gf-sp-raised{bottom:calc(54px * var(--gf-s))}
  .gf-caption,.gf-header-caption{font-size:calc(11px * var(--gf-s))}
  .gf-post.gf-long-header .gf-top-left-tags,.gf-post.gf-long-header .gf-top-right-tags{top:calc(46px * var(--gf-s))}
  .gf-discuss-btn{font-size:calc(11px * var(--gf-s));padding:calc(6.5px * var(--gf-s)) calc(14px * var(--gf-s));min-height:calc(29px * var(--gf-s))}
}
@media(max-width:330px){
  .gf-avatar{width:calc(25px * var(--gf-s));height:calc(25px * var(--gf-s))}.gf-action-btn{font-size:calc(17px * var(--gf-s))}.gf-actions-left{gap:calc(9px * var(--gf-s))}
  .gf-social-proof-strip.gf-sp-raised{bottom:calc(48px * var(--gf-s))}
  .gf-post.gf-long-header .gf-top-left-tags,.gf-post.gf-long-header .gf-top-right-tags{top:calc(42px * var(--gf-s))}
}

/* ═════ EXTRAS: catch-me-up · weekly challenge · pinned posts · admin insights · staff sheets ═════ */
.gx-banner{display:flex;align-items:center;gap:9px;margin:8px var(--pad-x) 0;padding:8px 10px;border-radius:var(--action-r);background:rgba(36,89,184,.14);border:1px solid rgba(91,143,230,.28);color:var(--text,#F0F2FF)}
.gx-banner .gx-ic{font-size:1rem;color:var(--blue-soft);flex-shrink:0}
.gx-bt{flex:1;min-width:0}
.gx-bt b{display:block;font-size:.7rem;font-weight:700;line-height:1.3}
.gx-bt span{display:block;font-size:.6rem;color:rgba(var(--fg,255,255,255),.62);line-height:1.4;margin-top:1px}
.gx-bt .gx-meta{color:rgba(var(--fg,255,255,255),.8);font-weight:600}
.gx-bt a{color:var(--blue-soft);text-decoration:none;font-weight:700}
/* challenge card = the paused card (same classes, size and responsive scale); only the icon colour and the text lines are its own */
.gx-chal .gp-pause-ic{background:rgba(59,130,246,.14);color:#3B82F6}
.gx-chal .gx-desc{margin-bottom:6px}
.gx-chal .gx-meta{color:rgba(var(--fg,255,255,255),.8);font-weight:600}
.gx-chal .gx-meta a{color:var(--blue-soft);text-decoration:none;font-weight:700}
.gx-acts{display:flex;gap:6px;flex-shrink:0}
.gx-btn{border:none;border-radius:var(--action-r);padding:5px 10px;font-size:.6rem;font-weight:700;background:var(--blue);color:#fff;white-space:nowrap;font-family:inherit}
.gx-btn:hover{background:var(--blue-hover)}
.gx-btn.ghost{background:rgba(var(--fg,255,255,255),.1)}
.gx-btn.ghost:hover{background:rgba(var(--fg,255,255,255),.17)}
.gx-btn:disabled{opacity:.5;cursor:not-allowed}
.gx-x{border:none;background:none;color:rgba(var(--fg,255,255,255),.5);font-size:1rem;line-height:1;padding:0 2px}
.gx-x:hover{color:var(--text,#F0F2FF)}
.gx-add{display:flex;align-items:center;justify-content:center;gap:6px;width:calc(100% - (var(--pad-x) * 2));margin:8px var(--pad-x) 0;padding:7px 10px;border-radius:var(--action-r);border:1px dashed rgba(var(--fg,255,255,255),.22);background:none;color:rgba(var(--fg,255,255,255),.7);font-size:.64rem;font-weight:600;font-family:inherit}
.gx-add:hover{color:var(--text,#F0F2FF);border-color:rgba(var(--fg,255,255,255),.4)}
.gx-add i{font-size:.9rem;color:var(--blue-soft)}
.gx-pins{margin:8px var(--pad-x) 0}
.gx-pins[hidden]{display:none}
.gx-pins-head{display:flex;align-items:center;gap:6px;padding-bottom:6px;font-size:.66rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:rgba(var(--fg,255,255,255),.62)}
.gx-pins-head i{color:var(--blue-soft);font-size:.9rem}
.gx-pins-head .gx-btn{margin-left:auto}
.gx-pin-row{display:flex;gap:8px;overflow-x:auto;scrollbar-width:none;padding-bottom:2px}
.gx-pin-row::-webkit-scrollbar{display:none}
.gx-pin{position:relative;cursor:pointer;flex:0 0 200px;width:200px;border-radius:var(--action-r);overflow:hidden;background:var(--surface);border:1px solid var(--border);color:var(--text,#F0F2FF);text-align:left;padding:0;font-family:inherit}
.gx-pin-th{display:block;width:100%;height:108px;object-fit:cover;background:var(--post-sep,#02030e)}
.gx-pin-tx{display:block;padding:7px 10px 8px}
.gx-pin-tx b{display:block;font-size:.66rem;font-weight:700;color:var(--blue-soft);margin-bottom:1px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.gx-pin-tx span{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;font-size:.66rem;line-height:1.35;color:rgba(var(--fg,255,255,255),.8)}
.gx-pin-badge{position:absolute;top:5px;left:5px;display:flex;align-items:center;gap:3px;padding:2px 6px;border-radius:3px;background:rgba(1,2,24,.78);font-size:.54rem;font-weight:700;color:#fff}
.gx-pin-badge i{color:var(--blue-soft);font-size:.7rem}
.gx-pin-un{cursor:pointer;position:absolute;top:4px;right:4px;width:20px;height:20px;border:none;border-radius:50%;background:rgba(1,2,24,.78);color:#fff;font-size:.8rem;line-height:1;display:flex;align-items:center;justify-content:center}
.gx-pin-un:hover{background:var(--red)}
.gx-ins-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px}
.gx-ins-t{padding:8px 9px;border-radius:var(--action-r);background:var(--surface);border:1px solid var(--border)}
.gx-ins-t b{display:block;font-size:.9rem;font-weight:700;line-height:1.2}
.gx-ins-t span{display:block;font-size:.58rem;color:var(--muted);margin-top:2px}
.gx-ins-t em{font-style:normal;font-size:.58rem;font-weight:700;margin-left:4px}
.gx-ins-t em.up{color:#3ddc84}.gx-ins-t em.down{color:#ff6b6b}.gx-ins-t em.flat{color:var(--muted)}
.gx-bars{display:flex;align-items:flex-end;gap:5px;height:56px;margin:10px 2px 2px}
.gx-bar{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%}
.gx-b{display:block;width:100%;border-radius:2px 2px 0 0;background:var(--blue);min-height:3px}
.gx-b.h0{height:3px;background:rgba(var(--fg,255,255,255),.12)}
.gx-b.h1{height:10%}.gx-b.h2{height:20%}.gx-b.h3{height:30%}.gx-b.h4{height:40%}.gx-b.h5{height:50%}.gx-b.h6{height:60%}.gx-b.h7{height:70%}.gx-b.h8{height:80%}.gx-b.h9{height:90%}.gx-b.h10{height:100%}
.gx-bar-l{display:flex;gap:5px;margin:3px 2px 8px}
.gx-bar-l span{flex:1;text-align:center;font-size:.5rem;color:var(--muted)}
.gx-ins-cap{font-size:.56rem;color:var(--muted);margin:8px 2px 0;text-transform:uppercase;letter-spacing:.06em}
.gx-ins-btn{border:none;background:none;color:var(--blue-soft);font-weight:700;font-size:inherit;font-family:inherit;padding:0}
.gx-ov{display:none;position:fixed;inset:0;z-index:2147483000;align-items:center;justify-content:center;background:rgba(0,0,0,.6);backdrop-filter:blur(2px);-webkit-backdrop-filter:blur(2px)}
.gx-ov.open{display:flex}
.gx-sheet{position:relative;width:360px;max-width:92vw;max-height:80vh;display:flex;flex-direction:column;background:var(--lane,#070924);border-radius:8px;padding:12px 14px 14px;box-shadow:0 16px 40px rgba(0,0,0,.6);color:#fff}
.gx-sh-t{font-size:.66rem;letter-spacing:.08em;text-transform:uppercase;color:rgba(var(--fg,255,255,255),.4);padding:0 0 8px}
.gx-sh-b{overflow-y:auto;min-height:0}
.gx-note{font-size:.62rem;line-height:1.5;color:rgba(var(--fg,255,255,255),.5);margin-bottom:6px}
.gx-lbl{display:block;margin:9px 0 4px;font-size:.54rem;letter-spacing:.08em;text-transform:uppercase;color:rgba(var(--fg,255,255,255),.4)}
.gx-in{width:100%;padding:8px 10px;border-radius:5px;border:1px solid rgba(var(--fg,255,255,255),.12);background:var(--post-sep,#02030e);color:var(--text,#F0F2FF);font-size:.74rem;font-family:inherit;outline:none;resize:none}
.gx-in:focus{border-color:rgba(91,143,230,.7)}
.gx-sh-act{display:flex;gap:7px;margin-top:12px}
.gx-sh-act .gx-btn{flex:1;padding:9px;font-size:.7rem}
.gx-pick-q{margin:0 0 8px}
.gx-pick-q::-webkit-search-cancel-button{opacity:.6}
.gx-pick{display:grid;grid-template-columns:repeat(3,1fr);gap:6px}
.gx-pick-i{cursor:pointer;position:relative;border:none;padding:0;border-radius:5px;overflow:hidden;background:var(--post-sep,#02030e);aspect-ratio:1/1;color:var(--text,#F0F2FF)}
.gx-pick-i .gx-pin-th{height:100%}
.gx-pick-i:hover{outline:2px solid var(--blue-soft)}
.gx-empty{padding:14px 0;text-align:center;font-size:.66rem;color:rgba(var(--fg,255,255,255),.45)}
@media(max-width:790px){
  .gx-ov{align-items:flex-end}
  .gx-sheet{width:100%;max-width:100%;max-height:70vh;border-radius:10px 10px 0 0;padding:10px 12px calc(env(safe-area-inset-bottom,0px) + 12px)}
  .gx-banner{padding:7px 9px}
  .gx-bt b{font-size:.66rem}.gx-bt span{font-size:.58rem}
  .gx-pin{flex-basis:228px;width:228px}.gx-pin-th{height:140px}
}

/* ══════════════════════════════════════════════════════════
   AGE GATE — same design language as Post Modal
   • full-screen overlay, content in a CENTERED LANE
   • two grey lines separate the lane from the empty space
   • rectangular 5px buttons, fluid premium sizing
   • dark mid-tone BLUE accent, text/icons on solid blue are BLACK
   ══════════════════════════════════════════════════════════ */
#ageGateOverlay, #ageGateLearnMoreModal {
    --ag-blue:#2a5fd8; --ag-blue-h:#234fb5; --ag-blue-l:#7ea6ff;
    --ag-blue-dim:rgba(42,95,216,.18); --ag-blue-glow:rgba(42,95,216,.34);
    --ag-on-blue:#000;
    --ag-card:rgba(var(--fg,255,255,255),.05);
    --ag-line:rgba(var(--fg,255,255,255),.09); --ag-line2:rgba(var(--fg,255,255,255),.14);
    --ag-sub:rgba(var(--fg,255,255,255),.62); --ag-mut:rgba(var(--fg,255,255,255),.45);
    --ag-pad:clamp(9px,3vw,16px);
    --ag-act-h:clamp(25px,7.2vw,30px);
    --ag-r:5px;
    --ag-tb-h:54px;

    position:fixed; inset:0;
    background:linear-gradient(-45deg,var(--bg-start,#010218),var(--bg-end,var(--lane,#070924)),var(--bg-end,var(--lane,#070924)));
    background-attachment:fixed;
    justify-content:center;
    font-family:'Poppins',sans-serif;
    color:#fff;
    -webkit-font-smoothing:antialiased;
}
@media (max-width:490px){ #ageGateOverlay, #ageGateLearnMoreModal{ --ag-tb-h:48px; } }
@media (max-width:390px){ #ageGateOverlay, #ageGateLearnMoreModal{ --ag-tb-h:44px; } }

#ageGateOverlay *, #ageGateOverlay *::before, #ageGateOverlay *::after,
#ageGateLearnMoreModal *, #ageGateLearnMoreModal *::before, #ageGateLearnMoreModal *::after { box-sizing:border-box; }
#ageGateOverlay button, #ageGateLearnMoreModal button { font-family:'Poppins',sans-serif; cursor:pointer; -webkit-tap-highlight-color:transparent; outline:none; }
#ageGateOverlay button:focus-visible, #ageGateLearnMoreModal button:focus-visible { outline:2px solid var(--ag-blue-l); outline-offset:2px; }
#ageGateOverlay [class^="ri-"], #ageGateOverlay [class*=" ri-"],
#ageGateLearnMoreModal [class^="ri-"], #ageGateLearnMoreModal [class*=" ri-"],
#ageGateOverlay [class^="ri-"]::before, #ageGateOverlay [class*=" ri-"]::before,
#ageGateLearnMoreModal [class^="ri-"]::before, #ageGateLearnMoreModal [class*=" ri-"]::before {
    font-family:'remixicon' !important; font-style:normal; font-weight:normal; line-height:1;
}

/* ═════════ AGE GATE OVERLAY ═════════ */
#ageGateOverlay {
    display:none;
    z-index:999999999999;
    overflow-y:auto;
}
#ageGateOverlay.open { display:flex; animation:agv2FadeIn .32s cubic-bezier(0.22,1,0.36,1); }
@keyframes agv2FadeIn { from{opacity:0;transform:translateY(14px);} to{opacity:1;transform:translateY(0);} }

.ag-lane {
    width:100%; max-width:580px; min-height:100%;
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    padding:clamp(22px,6vw,32px) clamp(18px,6vw,28px);
    text-align:center;
}
@media (min-width:801px){ .ag-lane { border-left:1px solid var(--ag-line); border-right:1px solid var(--ag-line); } }
@media (max-width:800px){ .ag-lane { max-width:100%; } }

/* icon: dark mid-tone blue box, black shield */
.agv2-icon-wrap {
    width:clamp(62px,19vw,92px); height:clamp(62px,19vw,92px);
    border-radius:10px;
    background:var(--ag-blue);
    display:flex; align-items:center; justify-content:center;
    margin-bottom:clamp(16px,4.5vw,24px);
    box-shadow:0 14px 40px var(--ag-blue-glow);
}
.agv2-icon-shield { font-size:clamp(32px,10vw,48px); color:var(--ag-on-blue); }

.agv2-title {
    font-size:clamp(15px,4.6vw,21px); font-weight:700; color:var(--text,#F0F2FF);
    margin:0 0 10px; line-height:1.3; max-width:340px;
}
.agv2-sub {
    font-size:clamp(11px,3.2vw,13px); color:var(--ag-sub);
    line-height:1.65; max-width:340px; margin:0 0 clamp(18px,5vw,28px);
}
.agv2-sub strong { color:var(--ag-blue-l); font-weight:700; }

/* buttons: boxy 5px, same sizing as post modal */
.agv2-btns { display:flex; flex-direction:column; gap:8px; width:100%; max-width:300px; }
.agv2-btn {
    display:flex; align-items:center; justify-content:center; gap:6px;
    width:100%; height:calc(var(--ag-act-h) + 6px); padding:0 14px;
    border:none; border-radius:var(--ag-r);
    font-size:clamp(.68rem,2.4vw,.79rem); font-weight:700; line-height:1; white-space:nowrap;
    transition:background .2s ease, transform .15s ease, box-shadow .2s ease;
}
.agv2-btn i { font-size:clamp(12px,3.4vw,14px); flex-shrink:0; }
.agv2-btn:active { transform:scale(.98); }

.agv2-btn-continue { background:var(--ag-blue); color:var(--ag-on-blue); }
.agv2-btn-continue:hover { background:var(--ag-blue-h); box-shadow:0 4px 16px var(--ag-blue-glow); }

.agv2-btn-learn, .agv2-btn-back {
    background:rgba(var(--fg,255,255,255),.1); color:var(--text,#F0F2FF);
    backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
}
.agv2-btn-learn i { color:var(--ag-blue-l); }
.agv2-btn-learn:hover, .agv2-btn-back:hover { background:rgba(var(--fg,255,255,255),.2); }

/* ═════════ LEARN MORE MODAL ═════════ */
#ageGateLearnMoreModal {
    display:none;
    z-index:9999999999999;
    overflow:hidden;
}
#ageGateLearnMoreModal.open { display:flex; animation:aglmSlideIn .28s cubic-bezier(0.22,1,0.36,1); }
@keyframes aglmSlideIn  { from{opacity:0;transform:translateY(20px);} to{opacity:1;transform:translateY(0);} }
@keyframes aglmSlideOut { to{opacity:0;transform:translateY(16px);} }

.aglm-lane { width:100%; max-width:580px; height:100%; display:flex; flex-direction:column; min-height:0; }
@media (min-width:801px){ .aglm-lane { border-left:1px solid var(--ag-line); border-right:1px solid var(--ag-line); } }
@media (max-width:800px){ .aglm-lane { max-width:100%; } }

/* top bar: back left · title centered */
.aglm-topbar { flex-shrink:0; display:flex; align-items:center; min-height:var(--ag-tb-h); padding:env(safe-area-inset-top) 10px 0; }
.aglm-tb-side { flex:0 0 38px; display:flex; align-items:center; }
.aglm-tb-side.right { justify-content:flex-end; }
.aglm-back-btn { width:34px; height:34px; background:none; border:none; color:var(--text,#F0F2FF); display:flex; align-items:center; justify-content:center; opacity:.9; padding:0; transition:.2s; }
.aglm-back-btn:hover { color:var(--ag-blue-l); opacity:1; }
.aglm-back-btn svg { width:22px; height:22px; display:block; }
.aglm-topbar-center { flex:1; min-width:0; text-align:center; }
.aglm-topbar-title { font-size:clamp(.8rem,2.6vw,.92rem); font-weight:700; color:var(--text,#F0F2FF); line-height:1.2; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.aglm-topbar-sub { font-size:clamp(.58rem,1.9vw,.66rem); color:var(--ag-mut); margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
@media (max-width:490px){ .aglm-back-btn{ width:30px; height:30px; } }

/* scroll body */
.aglm-body {
    flex:1; min-height:0; overflow-y:auto; overflow-x:hidden; -webkit-overflow-scrolling:touch;
    padding:6px var(--ag-pad) 18px;
    scrollbar-width:thin; scrollbar-color:rgba(42,95,216,.4) transparent;
}
.aglm-body::-webkit-scrollbar { width:4px; }
.aglm-body::-webkit-scrollbar-thumb { background:rgba(42,95,216,.45); border-radius:99px; }
/* responsive view: no scrollbar at all */
@media (max-width:800px){
    #ageGateOverlay, .aglm-body { scrollbar-width:none !important; -ms-overflow-style:none !important; }
    #ageGateOverlay::-webkit-scrollbar, .aglm-body::-webkit-scrollbar { display:none !important; width:0 !important; height:0 !important; }
}

.aglm-lead-icon {
    width:clamp(40px,11vw,52px); height:clamp(40px,11vw,52px);
    border-radius:var(--ag-r); background:var(--ag-blue); color:var(--ag-on-blue);
    display:flex; align-items:center; justify-content:center;
    font-size:clamp(20px,5.5vw,26px);
    margin:8px 0 14px;
    box-shadow:0 10px 26px var(--ag-blue-glow);
}
.aglm-h1 { font-size:clamp(.88rem,3vw,1.05rem); font-weight:700; color:var(--text,#F0F2FF); margin:0 0 12px; line-height:1.3; }
.aglm-p { font-size:clamp(.68rem,2.3vw,.78rem); color:var(--ag-sub); line-height:1.7; margin:0 0 14px; }

.aglm-section-head {
    display:flex; align-items:center; gap:6px;
    margin:22px 0 10px; padding-top:14px; border-top:1px solid var(--ag-line);
    font-size:clamp(.58rem,1.9vw,.64rem); font-weight:600; letter-spacing:.6px; text-transform:uppercase; color:var(--ag-sub);
}
.aglm-section-head i { font-size:14px; color:var(--ag-blue-l); }

.aglm-list { list-style:none; margin:0 0 6px; padding:0; display:flex; flex-direction:column; gap:7px; }
.aglm-list li {
    display:flex; align-items:flex-start; gap:9px;
    font-size:clamp(.64rem,2.2vw,.74rem); color:rgba(var(--fg,255,255,255),.75); line-height:1.55;
    background:var(--ag-card); border:1px solid var(--ag-line); border-radius:8px; padding:9px 11px;
}
.aglm-list li i { font-size:14px; color:var(--ag-blue-l); flex-shrink:0; margin-top:1px; }

.aglm-note {
    display:flex; align-items:flex-start; gap:9px;
    background:var(--ag-blue-dim); border:1px solid rgba(42,95,216,.35); border-radius:10px;
    padding:12px 13px; margin:22px 0 4px;
    font-size:clamp(.64rem,2.1vw,.72rem); color:rgba(var(--fg,255,255,255),.8); line-height:1.6;
}
.aglm-note i { font-size:15px; color:var(--ag-blue-l); flex-shrink:0; margin-top:1px; }

/* action bar with "Got it" — blue box, black text */
.aglm-actionbar { flex-shrink:0; display:flex; padding:8px var(--ag-pad) calc(10px + env(safe-area-inset-bottom)); }
.aglm-done-btn {
    display:flex; align-items:center; justify-content:center; gap:6px;
    flex:1; min-width:0; height:var(--ag-act-h); padding:0 14px;
    border:none; border-radius:var(--ag-r); background:var(--ag-blue); color:var(--ag-on-blue);
    font-size:clamp(.68rem,2.4vw,.79rem); font-weight:700; line-height:1; white-space:nowrap;
    transition:background .2s ease, transform .15s ease, box-shadow .2s ease;
}
.aglm-done-btn i { font-size:clamp(12px,3.4vw,14px); }
.aglm-done-btn:hover { background:var(--ag-blue-h); box-shadow:0 4px 16px var(--ag-blue-glow); }
.aglm-done-btn:active { transform:scale(.98); }

@media (prefers-reduced-motion:reduce){
    #ageGateOverlay *, #ageGateLearnMoreModal *, #ageGateOverlay, #ageGateLearnMoreModal { transition-duration:.01ms !important; animation-duration:.01ms !important; }
}

/* ═════ inline-style clean-up: everything that used to be style="" lives here ═════ */
.gp-off{display:none !important}
.gpjq-form{margin:0}
/* pause / pending card: the page's own colour, a touch lighter in dark mode and a touch darker in light mode (the text colour at 5% does both), sharper but still rounded corners */
.gp-pause, .gp-pause.gp-pend{background:rgba(var(--fg,255,255,255),.05);border:1px solid rgba(var(--fg,255,255,255),.11);border-radius:calc(5px * var(--hd-s,1))}   /* clearly visible border: 20% of the text colour (light line in dark mode, dark line in light mode) */
@media(max-width:490px){.gp-pause{border-radius:calc(5px * var(--hd-s,1))}}

/* ── Manage pause / What pausing does: the same sheet design as the profile page (.dzm-sheet) ── */
.gp-sheet.pf{width:360px;max-width:90vw;max-height:80vh;background:var(--lane,#070924);border:none;border-radius:calc(8px * var(--bs-s,1));
  padding:calc(14px * var(--bs-s,1)) calc(14px * var(--bs-s,1)) calc(16px * var(--bs-s,1));box-shadow:0 16px 40px rgba(0,0,0,.6);
  display:flex;flex-direction:column;gap:calc(12px * var(--bs-s,1));animation:gpPop .22s cubic-bezier(.34,1.56,.64,1)}
@keyframes gpPop{from{opacity:0;transform:translateY(10px) scale(.96)}to{opacity:1;transform:none}}
.pf-handle{display:none;justify-content:center;padding:2px 0;flex-shrink:0}
.pf-handle span{width:calc(38px * var(--bs-s,1));height:calc(4px * var(--bs-s,1));border-radius:2px;background:rgba(var(--fg,255,255,255),.22);display:block}
.pf-card{background:var(--surface-3,#090b29);border-radius:calc(4px * var(--bs-s,1));overflow:hidden;flex-shrink:1;min-height:0;display:flex;flex-direction:column}
.pf-scroll{overflow-y:auto;min-height:0;scrollbar-width:thin;scrollbar-color:rgba(255,255,255,.15) transparent}
.pf-header{padding:calc(12px * var(--bs-s,1)) calc(16px * var(--bs-s,1)) calc(6px * var(--bs-s,1));font-size:calc(10px * var(--bs-s,1));color:rgba(var(--fg,255,255,255),.35);letter-spacing:1px;text-transform:uppercase}
.pf-name{padding:0 calc(16px * var(--bs-s,1)) calc(4px * var(--bs-s,1));font-size:calc(15px * var(--bs-s,1));font-weight:700;line-height:1.35;color:var(--blue-soft,#6FA1FF);word-break:break-word}
.pf-text{padding:calc(4px * var(--bs-s,1)) calc(16px * var(--bs-s,1)) calc(10px * var(--bs-s,1));font-size:calc(11.5px * var(--bs-s,1));line-height:1.6;color:rgba(var(--fg,255,255,255),.6)}
.pf-text p{margin:0 0 calc(6px * var(--bs-s,1));font-size:inherit;color:inherit;line-height:inherit}
.pf-text strong.gp-hl{color:var(--text,#F0F2FF)}
.pf-item{display:flex;align-items:center;gap:calc(10px * var(--bs-s,1));padding:calc(10px * var(--bs-s,1)) calc(14px * var(--bs-s,1));border-top:1px solid rgba(255,255,255,.08);width:100%;
  background:none;border-left:none;border-right:none;border-bottom:none;text-align:left;color:var(--text,#F0F2FF);font-family:inherit;cursor:pointer;transition:background .2s;-webkit-tap-highlight-color:transparent}
.pf-item:hover{background:rgba(var(--fg,255,255,255),.06)}
.pf-ic{width:calc(34px * var(--bs-s,1));height:calc(34px * var(--bs-s,1));border-radius:50%;flex-shrink:0;border:1.5px dashed rgba(var(--fg,255,255,255),.3);display:flex;align-items:center;justify-content:center;font-size:calc(17px * var(--bs-s,1));color:rgba(var(--fg,255,255,255),.6)}
.pf-ic.no{color:#ff7a7a;border-color:rgba(255,122,122,.4)} .pf-ic.yes{color:#4ade80;border-color:rgba(74,222,128,.4)} .pf-ic.go{color:var(--blue-soft,#6FA1FF);border-color:rgba(var(--feed-blue-rgb,36,89,184),.5)}
.pf-tx{flex:1;min-width:0}
.pf-t{font-size:calc(13px * var(--bs-s,1));font-weight:600;line-height:1.4}
.pf-s{font-size:calc(10px * var(--bs-s,1));color:rgba(var(--fg,255,255,255),.4);margin-top:1px;line-height:1.35}
.pf-item.danger .pf-t{color:var(--blue-soft,#6FA1FF)}
.pf-close{display:flex;align-items:center;justify-content:center;gap:calc(8px * var(--bs-s,1));width:100%;padding:calc(14px * var(--bs-s,1));background:var(--surface-3,#090b29);border:none;border-radius:calc(4px * var(--bs-s,1));
  color:rgba(var(--fg,255,255,255),.85);font-size:calc(14px * var(--bs-s,1));font-weight:600;cursor:pointer;font-family:inherit;flex-shrink:0;transition:background .2s,color .2s}
.pf-close:hover{background:rgba(var(--fg,255,255,255),.08);color:var(--blue-soft,#6FA1FF)}
.gp-sheet.pf form{margin:0;display:block}
@media(max-width:790px){
  .gp-ov.pf-ov{align-items:flex-end}
  .gp-sheet.pf{width:100%;max-width:100%;max-height:60vh;border-radius:0;padding:calc(5px * var(--bs-s,1)) calc(10px * var(--bs-s,1)) calc(env(safe-area-inset-bottom,0px) + calc(10px * var(--bs-s,1)));gap:calc(8px * var(--bs-s,1));animation:gpUp .28s cubic-bezier(.32,.72,0,1)}
  .pf-handle{display:flex}
  .pf-handle span{width:calc(30px * var(--bs-s,1));height:calc(3px * var(--bs-s,1))}
  .pf-card{border-radius:calc(6px * var(--bs-s,1))}
  .pf-header{font-size:calc(8.5px * var(--bs-s,1));padding:calc(7px * var(--bs-s,1)) calc(12px * var(--bs-s,1)) calc(3px * var(--bs-s,1))}
  .pf-name{font-size:calc(13.5px * var(--bs-s,1));padding:0 calc(12px * var(--bs-s,1)) calc(3px * var(--bs-s,1))}
  .pf-text{font-size:calc(10.5px * var(--bs-s,1));padding:calc(3px * var(--bs-s,1)) calc(12px * var(--bs-s,1)) calc(8px * var(--bs-s,1))}
  .pf-item{padding:calc(6px * var(--bs-s,1)) calc(12px * var(--bs-s,1));gap:calc(9px * var(--bs-s,1))}
  .pf-ic{width:calc(27px * var(--bs-s,1));height:calc(27px * var(--bs-s,1));font-size:calc(14px * var(--bs-s,1))}
  .pf-t{font-size:calc(12px * var(--bs-s,1))}
  .pf-close{padding:calc(10px * var(--bs-s,1));font-size:calc(12px * var(--bs-s,1));border-radius:calc(6px * var(--bs-s,1))}
}
/* the small text in the private-pod explainer, the "Be the first to post" empty state and "No posts yet" = the same size as the description words in the group header */
.gp-priv-hero p,.gp-priv-sec p,.gp-priv-ul li,.gp-priv-steps li,.gp-priv-status,
.gp-ep-hero p,.gp-ep-sec p,.gp-ep-ul li,.gp-ep-steps li,.gp-ep-note,.gp-grid-empty{font-size:var(--fs-desc)}
/* stats on the banner picture are always white (the picture is always dark-shaded), in light mode too */
.gp-stat b{color:rgba(255,255,255,.92);text-shadow:0 1px 3px rgba(0,0,0,.45)}
.gp-stat span{color:rgba(255,255,255,.72);text-shadow:0 1px 3px rgba(0,0,0,.45)}
.gp-stat-div{background:rgba(255,255,255,.3)}
.gp-pause-title.gp-tight{margin-bottom:5px}
.gp-pause-body.gp-tight0{margin-top:0}
.gp-pause-body strong.gp-hl{color:var(--text,#F0F2FF)}
.gp-list i.gp-ic-no{color:#ff7a7a}
.gp-list i.gp-ic-yes{color:#4ade80}
.gp-sheet .cancel.gp-mt0{margin-top:0}
#gpNavSentinel{height:1px}
#gpAudioPlayer{display:none}
.gf-tag-link{color:var(--gw,rgba(255,255,255,.65));font-weight:600;text-decoration:none}
.gf-mention-link{color:var(--feed-blue);font-weight:600;text-decoration:none}
/* rail sizes: exactly the home page's (same clamp formulas), so icons, gaps and numbers match it at every screen width */
.gf-actions{padding:calc(clamp(7px,.65vw + 3.8px,9px) * var(--gf-s)) calc(clamp(8px,.65vw + 4.8px,10px) * var(--gf-s)) 0}
.gf-actions-left{gap:calc(clamp(9px,1.9vw + 2.7px,18px) * var(--gf-s))}
.gf-action-btn{font-size:calc(clamp(17px,1.06vw + 13.5px,22px) * var(--gf-s))}
.gf-action-count{font-size:calc(clamp(11px,.9vw + 6.6px,12px) * var(--gf-s))}
.gf-sp-badge.is-like{background:none}
.gf-sp-badge.is-rep{background:#7a4aff}
.gf-file-ic.fc-f04747{color:#f04747}.gf-file-ic.fc-3b82f6{color:#3b82f6}.gf-file-ic.fc-22c55e{color:#22c55e}
.gf-file-ic.fc-f97316{color:#f97316}.gf-file-ic.fc-eab308{color:#eab308}.gf-file-ic.fc-94a3b8{color:#94a3b8}
.gf-file-ic.fc-ec4899{color:#ec4899}.gf-file-ic.fc-a855f7{color:#a855f7}.gf-file-ic.fc-7fa0ff{color:#7fa0ff}
.gf-heart-particle i.gf-hs0{font-size:calc(12px * var(--gf-s))}.gf-heart-particle i.gf-hs1{font-size:calc(14px * var(--gf-s))}.gf-heart-particle i.gf-hs2{font-size:calc(16px * var(--gf-s))}
.gf-heart-particle i.gf-hs3{font-size:calc(18px * var(--gf-s))}.gf-heart-particle i.gf-hs4{font-size:calc(20px * var(--gf-s))}.gf-heart-particle i.gf-hs5{font-size:calc(22px * var(--gf-s))}
#gp-toast{position:fixed;bottom:90px;left:50%;transform:translateX(-50%);background:rgba(20,20,25,.95);border:1px solid rgba(36,89,184,.5);color:#fff;padding:8px 20px;border-radius:50px;font-size:12px;z-index:2147483600;pointer-events:none;box-shadow:0 8px 24px rgba(0,0,0,.6);transition:opacity .3s;white-space:nowrap}
#gp-toast.out{opacity:0}
/* media-post captions (not text-only): 11 to 12.5px fluid, x the phone multiplier, line height 1.5 */
.gf-caption, .gf-header-caption{ font-size:calc(clamp(11px, .37vw + 9.6px, 12.5px) * var(--gf-s)); line-height:1.5; }

:root{--ccm-s:1}@media (max-width:768px){:root{--ccm-s:1.155}}   /* phone scale the offline banner and card use (same value as the home page) */
</style>
<?php dz_offline_styles(); ?>
<style>
/* collab post header: poster + collaborators in one avatar cluster; "Name and Sam" opens the collaborators sheet (the group card avatar class is gf-avatar) */
.gf-header .av-wrap.ipv-collab{position:relative;display:block;flex:none;width:var(--cw,46px);height:var(--cw,46px)}
.av-wrap.ipv-collab .on-dot{display:none}
.av-wrap.ipv-collab img.gf-avatar{position:absolute;box-sizing:border-box;width:66%;height:66%;margin:0}
.av-wrap.ipv-collab.n1 img.gf-avatar:not(.ipv-co-pic){left:0;bottom:0;z-index:2}
.av-wrap.ipv-collab.n1 img.ipv-co-pic{right:0;top:0;z-index:1}
.av-wrap.ipv-collab.n2 img.gf-avatar{width:58%;height:58%}
.av-wrap.ipv-collab.n2 img.gf-avatar:not(.ipv-co-pic){left:21%;top:0;z-index:3}
.av-wrap.ipv-collab.n2 img.ipv-co-pic.c1{left:0;bottom:0;z-index:2}
.av-wrap.ipv-collab.n2 img.ipv-co-pic.c2{right:0;bottom:0;z-index:1}
.gf-username.ipv-co-name{cursor:pointer}
</style>
</head>
<body>
<?php /* Dezzme Verified badge sprite (filled flower, check cut out — takes the text colour) */ ?>
<svg width="0" height="0" style="position:absolute;width:0;height:0;overflow:hidden" aria-hidden="true" focusable="false">
  <defs><path id="dz-fp" fill-rule="evenodd" d="M245.9 128.0L245.5 132.6L244.2 137.1L242.3 141.5L239.7 145.7L236.5 149.6L233.0 153.2L229.3 156.6L225.6 159.7L221.9 162.6L218.5 165.5L215.4 168.3L212.7 171.2L210.5 174.2L208.7 177.5L207.3 181.0L206.3 184.9L205.4 189.1L204.7 193.5L204.0 198.2L203.1 203.1L201.9 208.0L200.4 212.8L198.4 217.4L196.0 221.6L193.0 225.3L189.5 228.4L185.6 230.8L181.3 232.5L176.6 233.5L171.8 233.7L166.9 233.4L161.9 232.4L157.1 231.1L152.4 229.5L147.8 227.8L143.5 226.1L139.4 224.6L135.5 223.5L131.7 222.8L128.0 222.5L124.3 222.8L120.5 223.5L116.6 224.6L112.5 226.1L108.2 227.8L103.6 229.5L98.9 231.1L94.1 232.4L89.1 233.4L84.2 233.7L79.4 233.5L74.7 232.5L70.4 230.8L66.5 228.4L63.0 225.3L60.0 221.6L57.6 217.4L55.6 212.8L54.1 208.0L52.9 203.1L52.0 198.2L51.3 193.5L50.6 189.1L49.7 184.9L48.7 181.0L47.3 177.5L45.5 174.2L43.3 171.2L40.6 168.3L37.5 165.5L34.1 162.6L30.4 159.7L26.7 156.6L23.0 153.2L19.5 149.6L16.3 145.7L13.7 141.5L11.8 137.1L10.5 132.6L10.1 128.0L10.5 123.4L11.8 118.9L13.7 114.5L16.3 110.3L19.5 106.4L23.0 102.8L26.7 99.4L30.4 96.3L34.1 93.4L37.5 90.5L40.6 87.7L43.3 84.8L45.5 81.8L47.3 78.5L48.7 75.0L49.7 71.1L50.6 66.9L51.3 62.5L52.0 57.8L52.9 52.9L54.1 48.0L55.6 43.2L57.6 38.6L60.0 34.4L63.0 30.7L66.5 27.6L70.4 25.2L74.7 23.5L79.4 22.5L84.2 22.3L89.1 22.6L94.1 23.6L98.9 24.9L103.6 26.5L108.2 28.2L112.5 29.9L116.6 31.4L120.5 32.5L124.3 33.2L128.0 33.5L131.7 33.2L135.5 32.5L139.4 31.4L143.5 29.9L147.8 28.2L152.4 26.5L157.1 24.9L161.9 23.6L166.9 22.6L171.8 22.3L176.6 22.5L181.3 23.5L185.6 25.2L189.5 27.6L193.0 30.7L196.0 34.4L198.4 38.6L200.4 43.2L201.9 48.0L203.1 52.9L204.0 57.8L204.7 62.5L205.4 66.9L206.3 71.1L207.3 75.0L208.7 78.5L210.5 81.8L212.7 84.8L215.4 87.7L218.5 90.5L221.9 93.4L225.6 96.3L229.3 99.4L233.0 102.8L236.5 106.4L239.7 110.3L242.3 114.5L244.2 118.9L245.5 123.4ZM84.1 131.9Q78.2 137.8 84.1 143.6L108.3 167.9Q114.1 173.7 119.9 167.8L174.2 111.6Q179.9 105.6 174.0 99.9L170.0 96.1Q164.1 90.4 158.4 96.3L119.6 136.4Q113.9 142.3 108.0 136.5L99.6 128.1Q93.8 122.2 87.9 128.1Z"/></defs>
  <symbol id="dz-flower-f" viewBox="0 0 256 256"><use href="#dz-fp" fill="currentColor"/></symbol>
</svg>
<script>window.DZ_VB = <?php echo json_encode(dz_vb_svg()); ?>;</script>
<script>
/* haptics: uses the existing window.dzHaptic engine from zeems_modal.php only */
window.dzMe = window.dzMe || <?php echo json_encode(['id' => (int)$current_user, 'username' => $current_user_name, 'profile_pic' => (string)$current_user_pic], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
window.dzHp = function(k){ try{ if(window.dzHaptic && typeof window.dzHaptic[k]==='function') window.dzHaptic[k](); }catch(e){} };
(function(){
  /* page buttons whose handlers live in other includes: tap-time light buzz (menus / sheets / share / comments) */
  var LIGHT=['openShareModal','dzOpenComments','openGfSheet','openEngModal','openGroupOptionsModal','openInviteModal','openGroupSearchModal','openYouModal','openReportGroupModal','openPodInfoModal','openGroupPicModal','openPostModal','gxChOpen','gpOpen','gpJqOpen','gpOpenSounds','gpDiscuss'];
  var SEL=['gplSwitch'];
  function wrap(name,kind){
    var f=window[name]; if(typeof f!=='function'||f._dzh) return;
    var w=function(){ window.dzHp(kind); return f.apply(this,arguments); };
    w._dzh=1; window[name]=w;
  }
  function all(){ LIGHT.forEach(function(n){ wrap(n,'light'); }); SEL.forEach(function(n){ wrap(n,'selection'); }); }
  document.addEventListener('DOMContentLoaded',function(){ all(); setTimeout(all,600); });
  window.addEventListener('load',function(){ all(); setTimeout(all,1500); });
})();
</script>
<script>
/* likes / reposts sheet on the group page: add the verified badge next to verified names */
(function(){
  var cache = {}, wait = {}, timer = null;
  function put(row){
    var n = row.querySelector('.ipv-liker-name, .eng-name, .gf-eng-name'); if(!n || n.querySelector('.dz-vb')) return;
    n.insertAdjacentHTML('beforeend', window.DZ_VB || '');
  }
  function flush(){
    timer = null;
    var ids = Object.keys(wait); if(!ids.length) return;
    var batch = wait; wait = {};
    fetch(location.pathname + '?dz_vb_ids=' + ids.join(','), { credentials:'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(list){
        var ok = {}; (list||[]).forEach(function(i){ ok[i] = 1; });
        ids.forEach(function(i){ cache[i] = !!ok[i]; if(cache[i]) batch[i].forEach(put); });
      }).catch(function(){});
  }
  function scan(root){
    root.querySelectorAll('.ipv-liker-row').forEach(function(row){
      var m = /user_id=(\d+)/.exec(row.getAttribute('href') || ''); if(!m) return;
      var id = m[1];
      if(cache[id] === true) put(row);
      else if(cache[id] === undefined){ (wait[id] = wait[id] || []).push(row); if(!timer) timer = setTimeout(flush, 60); }
    });
  }
  new MutationObserver(function(muts){
    for(var i=0;i<muts.length;i++){ var t = muts[i].target; if(t && t.nodeType===1 && (t.id==='ipvLikersList' || (t.closest && t.closest('#ipvLikersList')))){ scan(document); return; } }
  }).observe(document.documentElement, { childList:true, subtree:true });
})();
</script>
<style>
.dz-vb{ width:1em; height:1em; flex-shrink:0; display:inline-block; vertical-align:-.15em; color:inherit; }
.gf-username-row > .dz-vb{ width:calc(13px * var(--gf-s)); height:calc(13px * var(--gf-s)); margin-left:calc(-2px * var(--gf-s)); color:rgb(var(--fg,255,255,255)); }
.gf-post.gf-long-header .gf-username-row > .dz-vb{ color:#fff; filter:drop-shadow(0 1px 4px rgba(0,0,0,.5)); }
.gs-admin-n + .dz-vb{ width:.8rem; height:.8rem; margin-left:-4px; }
.gp-top-user + .dz-vb{ width:calc(12.5px * var(--gf-s)); height:calc(12.5px * var(--gf-s)); }
@media (max-width:420px){ .gf-username-row > .dz-vb{ width:calc(12px * var(--gf-s)); height:calc(12px * var(--gf-s)); } }
</style>
<?php dz_offline_ui('body'); ?>
<script>try{ if('serviceWorker' in navigator) navigator.serviceWorker.register('service-worker.js').catch(function(){}); }catch(e){}   /* same service worker as the home page: shows offline.html when a page can't load at all */</script>

<!-- ═════════ SIDEBAR — nav.php ═════════ -->
<?php include 'nav.php'; ?>

<div class="gp-shell">
<div class="gp-page<?= !empty($GP_SUSP) ? ' dz-susp' : '' ?>" id="gpPage">

  <script>
/* ── Join / Leave / Requested without a page refresh (same idea as Track on profiles) ──
   The action posts in the background with the bouncing bars on the button; then the header
   (top bar buttons, action buttons, Discussion / Settings row, nav) is swapped from a fresh copy of the page. */
(function(){
  var busy=false;
  var BARS='<span class="spinner-icon" style="color:currentColor;"><span></span><span></span><span></span><span></span></span>';
  function swap(doc){
    var pairs=[['#gpTopbar .gp-banner-right'],['.gp-actions'],['.gp-dual'],['#gpNav']];
    var prev=null;
    pairs.forEach(function(s){
      var o=document.querySelector(s[0]), n=doc.querySelector(s[0]);
      if(o&&n){ o.replaceWith(document.importNode(n,true)); prev=document.querySelector(s[0]); }
      else if(o&&!n){ o.remove(); }
      else if(!o&&n&&prev){ prev.insertAdjacentElement('afterend',document.importNode(n,true)); prev=document.querySelector(s[0]); }
      else if(o){ prev=o; }
    });
    var m=doc.documentElement.innerHTML.match(/var GP_IS_MEMBER\s*=\s*(true|false)/);
    if(m) window.GP_IS_MEMBER=(m[1]==='true');
    try{ if(typeof gpNavArrows==='function') gpNavArrows(); }catch(e){}
  }
  document.addEventListener('submit',function(e){
    var f=e.target; if(!f||e.defaultPrevented||!f.closest||!f.closest('.gp-actions')) return;
    var b=e.submitter||f.querySelector('button[name]'); if(!b||!b.name) return;
    if(['join_group','leave_group','cancel_request'].indexOf(b.name)<0) return;
    e.preventDefault();
    if(busy) return; busy=true;
    dzHp(b.name==='join_group'?'medium':'light');
    var old=b.innerHTML; b.style.minWidth=b.offsetWidth+'px'; b.disabled=true; b.innerHTML=BARS;
    var pill=document.getElementById('gpJoinPill'), pillOld=pill?pill.innerHTML:''; if(pill){ pill.style.minWidth=pill.offsetWidth+'px'; pill.innerHTML=BARS; }
    var t0=Date.now();
    var fail=function(){ busy=false; b.innerHTML=old; b.disabled=false; b.style.minWidth=''; if(pill){ pill.innerHTML=pillOld; pill.style.minWidth=''; } if(window.gpToast) window.gpToast('Something went wrong — try again'); };
    fetch('group.php?id='+gfGroupId,{method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:b.name+'=1&gp_ajax=1&csrf_token='+encodeURIComponent(CSRF_TOKEN)})
    .then(function(r){ return r.json(); })
    .then(function(d){ if(!d||d.status!=='ok') throw 0;
      return fetch('group.php?id='+gfGroupId,{credentials:'same-origin'}).then(function(r){ return r.text(); }).then(function(h){
        var doc=new DOMParser().parseFromString(h,'text/html');
        setTimeout(function(){ swap(doc); busy=false; },Math.max(0,500-(Date.now()-t0)));
      });
    }).catch(fail);
  },false);
})();
</script>
<!-- ═════════ STICKY TOP BAR ═════════ -->
  <div class="gp-topbar" id="gpTopbar">
    <button class="gp-bbtn back" onclick="if(document.referrer&&document.referrer.indexOf(location.host)!==-1){history.back()}else{location.href='community.php'}" aria-label="Back"><svg class="dz-back-svg" viewBox="0 0 14 24" aria-hidden="true"><path d="M12.4 1.6 L2 12 L12.4 22.4" fill="none" stroke="currentColor" stroke-width="1.98" stroke-linejoin="miter" stroke-linecap="butt"/></svg></button>
    <span class="gp-topbar-title"><?php echo htmlspecialchars($group['title']); ?></span>
    <div class="gp-banner-right">
      <?php if (!empty($group['is_adult'])): ?><span class="gp-adult-badge">18+</span><?php endif; ?>
      <button class="gp-bbtn" onclick="openGroupSearchModal()" aria-label="Search"><svg class="tb-sicon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.2" cy="10.2" r="7.9" fill="none" stroke="currentColor" stroke-width="2.5"/><path fill="currentColor" d="M16.2 15.1L21.41 18.59A2 2 0 0 1 18.59 21.41L15.1 16.2Z"/></svg></button>
      <?php if ($is_member): ?>
      <button class="gp-bbtn" onclick="openPostModal()" aria-label="New post"><i class="ri-add-line"></i></button>
      <?php endif; ?>
      <button class="gp-bbtn" onclick="openGroupOptionsModal()" aria-label="More options"><i class="ri-more-fill"></i></button>
      <?php if (!$is_member): ?>
      <button type="button" class="gp-joinpill<?php echo $gp_pill_class; ?>" id="gpJoinPill"
              onclick="var b=document.getElementById('gpMainJoin'); if(b) b.click();"><?php echo htmlspecialchars($gp_pill_label); ?></button>
      <?php endif; ?>
    </div>
  </div>

  <!-- ═════════ BANNER ═════════ -->
  <div class="gp-banner" id="gpBanner">
    <img class="gp-banner-img" fetchpriority="high" decoding="async" src="<?php echo htmlspecialchars($banner_src); ?>" onerror="this.src='uploads/default_group.png'" alt="Banner">
    <div class="gp-banner-shade"></div>

    <div class="gp-stats">
      <div class="gp-stat"><b><?php echo number_format($visitors_week); ?></b><span>Visitors / wk</span></div>
      <div class="gp-stat-div"></div>
      <div class="gp-stat"><b><?php echo number_format($contributions_week); ?></b><span>Contributions / wk</span></div>
      <div class="gp-stat-div"></div>
      <div class="gp-stat"><b><?php echo number_format($total_posts_group); ?></b><span>Total posts</span></div>
    </div>

    <div class="gp-pic" onclick="openGroupPicModal()" role="button" tabindex="0" aria-label="View group picture">
      <img fetchpriority="high" decoding="async" src="<?php echo htmlspecialchars($img_src); ?>" onerror="this.src='uploads/default_group.png'" alt="Group picture" draggable="false">
    </div>
  </div>

  <!-- ═════════ HEADER BODY ═════════ -->
  <div class="gp-head">

    <div class="gp-top-rows">
      <div class="gp-name-row" onclick="openPodInfoModal()" role="button" title="Pod details">
        <h1 class="gp-name"><?php echo htmlspecialchars($group['title']); ?></h1>
        <i class="ri-arrow-right-s-line gp-chev"></i>
      </div>

      <div class="gp-cat-row">
        <span class="gp-category"><?php echo htmlspecialchars($pod_category); ?></span>
      </div>

      <!-- public/private + members strip: after the category, before the description -->
      <div class="gp-meta<?php echo !empty($can_view_content) ? ' has-strip' : ''; ?>">
          <span class="gp-status">
            <i class="<?php echo $is_private_group ? 'ri-lock-2-line' : 'ri-earth-line'; ?>"></i>
            <?php echo $is_private_group ? 'Private' : 'Public'; ?>
          </span>
          <?php if (!empty($can_view_content)): ?>
          <span class="gp-dot"></span>
          <span class="gp-members"><?php include 'group_more_members_strip.php'; ?></span>
          <?php endif; ?>
        </div>
    </div>

    <?php if ($gp_desc_raw !== '' && !$gp_private_locked): ?>
    <div class="gp-desc">
      <?php if ($gp_desc_long): ?>
        <span id="gpDescShort"><?php echo nl2br(htmlspecialchars($gp_desc_short)); ?><span id="gpDescDots">...</span></span>
        <span id="gpDescFull" class="gp-off"><?php echo nl2br(htmlspecialchars($gp_desc_raw)); ?></span>
        <span class="gp-toggle" id="gpDescBtn" onclick="gpToggleDesc()">more</span>
      <?php else: ?>
        <?php echo nl2br(htmlspecialchars($gp_desc_raw)); ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php $gp_loc_txt = trim((string)($group['location'] ?? '')); if ($gp_loc_txt !== '' && !$gp_private_locked): ?>
    <a class="gp-loc" href="earth.php?loc=<?php echo rawurlencode($gp_loc_txt); ?>" title="<?php echo htmlspecialchars($gp_loc_txt); ?>"><i class="ri-map-pin-2-line"></i><span><?php echo htmlspecialchars($gp_loc_txt); ?></span><i class="ri-arrow-right-s-line gp-loc-chev"></i></a>
    <?php endif; ?>

    <!-- ═════════ ACTION BUTTONS ═════════ -->
    <div class="gp-actions">
      <?php if (!$is_member): ?>

        <?php if ($is_private_group && $my_request_status === 'pending'): ?>
          <form method="POST" onsubmit="return confirm('Withdraw your request to join this pod?');">
            <button name="cancel_request" id="gpMainJoin" class="gp-btn leave pending"><i class="ri-time-line"></i> Requested</button>
          </form>
        <?php elseif ($is_private_group && $my_request_status === 'denied'): ?>
          <form method="POST" <?php if (!empty($blocked_by_creator)) echo 'onsubmit="alert(\'You cannot join – blocked by creator.\');return false;"'; ?>>
            <button name="join_group" id="gpMainJoin" class="gp-btn leave"><i class="ri-close-circle-line"></i> Denied · Retry</button>
          </form>
        <?php else: ?>
          <form method="POST" <?php if (!empty($blocked_by_creator)) echo 'onsubmit="alert(\'You cannot join – blocked by creator.\');return false;"'; ?>>
            <button name="join_group" id="gpMainJoin" class="gp-btn join">
              <?php if ($is_private_group): ?><i class="ri-lock-line"></i> Request to Join<?php else: ?>Join Pod<?php endif; ?>
            </button>
          </form>
        <?php endif; ?>

      <?php else: ?>

        <form method="POST"><button name="leave_group" class="gp-btn leave">Leave</button></form>

      <?php endif; ?>

      <?php if (!$is_owner): ?>
        <button type="button" class="gp-btn report" onclick="openReportGroupModal(<?php echo (int)$group_id; ?>)">Report</button>
      <?php endif; ?>

      <?php if ($is_member): ?>
        <?php if (!$gp_invite_locked): ?>
          <button type="button" class="gp-btn invite" onclick="openInviteModal()">Invite</button>
        <?php else: ?>
          <button type="button" class="gp-btn invite locked" onclick="showDiscLockToast('This pod is paused — invites are disabled')"><i class="ri-lock-line"></i> Invite</button>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <!-- ═════════ SETTINGS + DISCUSSION ═════════ -->
    <?php $gp_show_settings = ($is_owner || $is_admin); $gp_show_mod = (!empty($is_mod) && !$gp_show_settings); $gp_show_disc = (bool)$is_member; $gp_left = ($gp_show_settings || $gp_show_mod); ?>
    <?php if ($gp_left || $gp_show_disc): ?>
    <div class="gp-dual<?php echo ($gp_left && $gp_show_disc) ? ($is_owner ? ' owner' : '') : ' solo'; ?><?php echo $is_member ? '' : ' nm'; ?>">

      <?php if ($gp_show_mod): ?>
      <button type="button" class="gp-btn settings" onclick="modOpen()">
        <i class="ri-hammer-line"></i>
        <span>Mod Tools</span>
        <?php if ($gp_report_count > 0): ?><span class="gp-badge"><i class="ri-flag-fill"></i> <?php echo $gp_report_count; ?></span><?php endif; ?>
      </button>
      <?php endif; ?>

      <?php if ($gp_show_settings): ?>
      <a href="group_controls.php?id=<?php echo $group_id; ?>" class="gp-btn settings">
        <i class="ri-shield-star-fill"></i>
        <span><?php echo $is_owner ? 'Group Settings' : 'Admin Settings'; ?></span>
        <?php if ($is_private_group && $pending_request_count > 0): ?>
          <span class="gp-badge"><?php echo $pending_request_count; ?> new</span>
        <?php endif; ?>
        <?php if ($gp_report_count > 0): ?>
          <span class="gp-badge"><i class="ri-flag-fill"></i> <?php echo $gp_report_count; ?></span>
        <?php endif; ?>
      </a>
      <?php endif; ?>

      <?php if ($gp_show_disc): ?>
        <?php if (!$gp_paused_lock): ?>
          <a class="gp-btn discuss" href="discussions.php?pod_id=<?php echo (int)$group_id; ?>">Discussions</a>
        <?php else: ?>
          <button type="button" class="gp-btn discuss locked" onclick="showDiscLockToast('This pod is paused — check back later')">Discussions</button>
        <?php endif; ?>
      <?php endif; ?>

    </div>
    <?php endif; ?>
  </div>

  <?php if (!empty($GP_SUSP)): $S = $GP_SUSP; $__e = function($t){ return htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8'); }; ?>
  <style>
  /* suspended pod: same page, only the top bar, the grey picture, the pod name + stats and the notice below */
  .gp-page.dz-susp > *:not(.gp-topbar):not(.gp-banner):not(.gp-head):not(.dz-susp-note){ display:none !important; }
  .gp-page.dz-susp .gp-head > *:not(.gp-top-rows){ display:none !important; }
  .gp-page.dz-susp .gp-top-rows > *:not(.gp-name-row){ display:none !important; }
  .gp-page.dz-susp .gp-name-row{ pointer-events:none; }
  .gp-page.dz-susp .gp-name-row .gp-chev{ display:none !important; }
  .gp-page.dz-susp .gp-banner-img{ opacity:0 !important; }
  .gp-page.dz-susp .gp-banner{ background:rgba(var(--fg,255,255,255),.07) !important; }
  .gp-page.dz-susp .gp-banner-shade{ display:none !important; }
  .gp-page.dz-susp .gp-pic{ background:rgba(var(--fg,255,255,255),.1) !important; pointer-events:none; }
  .gp-page.dz-susp .gp-pic img{ opacity:0 !important; }
  .gp-page.dz-susp .gp-stats, .gp-page.dz-susp .gp-stats *{ pointer-events:none !important; }
  .gp-page.dz-susp .gp-joinpill, .gp-page.dz-susp .gp-adult-badge, .gp-page.dz-susp .gp-topbar [aria-label="New post"]{ display:none !important; }
  .dz-susp-note{ padding:22px 16px 40px; color:rgba(var(--fg,255,255,255),.88); }
  .dz-susp-note h3{ font-size:1.05rem; font-weight:700; margin:0 0 6px; color:var(--blue-soft,#5b8fe6); }
  .dz-susp-note h4{ font-size:.82rem; font-weight:700; margin:16px 0 4px; color:rgba(var(--fg,255,255,255),.96); }
  .dz-susp-note p{ font-size:.8rem; line-height:1.55; margin:0 0 8px; color:rgba(var(--fg,255,255,255),.72); }
  .dz-susp-note .sp-date{ font-size:.72rem; color:rgba(var(--fg,255,255,255),.5); }
  .dz-susp-note .sp-btn, .dz-susp-note a.sp-learn{ display:flex; align-items:center; justify-content:center; box-sizing:border-box; height:calc(30px * var(--hd-s,1)); line-height:1; border:none; border-radius:5px; font-family:inherit; font-size:.72rem; font-weight:600; padding:0 14px; cursor:pointer; text-decoration:none; white-space:nowrap; background:var(--blue,#2459B8); color:#000 !important; }
  .dz-susp-note .sp-btn{ width:100%; margin-top:10px; }
  .dz-susp-note a.sp-learn{ width:fit-content; margin:8px 0 0; }
  .dz-susp-note .sp-center{ text-align:center; } .dz-susp-note .sp-center a.sp-learn{ margin:8px auto 0; }
  .dz-susp-note .sp-btn.green{ background:#4dde8a; }
  .dz-susp-note .sp-btn:disabled{ opacity:.5; }
  .dz-susp-note .sp-form{ display:none; margin-top:10px; } .dz-susp-note .sp-form.open{ display:block; }
  .dz-susp-note textarea{ width:100%; min-height:100px; border-radius:8px; border:1px solid rgba(var(--fg,255,255,255),.18); background:rgba(var(--fg,255,255,255),.05); color:inherit; padding:10px 12px; font-size:.82rem; resize:vertical; outline:none; font-family:inherit; }
  .dz-susp-note .sp-err{ color:#FF7A85; font-size:.74rem; margin-top:6px; min-height:1em; }
  </style>
  <div class="dz-susp-note" id="dzSuspNote">
  <?php if ($S['own']): ?>
    <?php if ($S['pending']): ?>
      <h3>You submitted an appeal on <?= $__e(date('j F Y', strtotime($S['appeal_at']))) ?></h3>
      <p style="font-style:italic">It usually takes us just over a day to review your information. Check again here.</p>
      <p>Your pod is not visible to people on Dezzme.</p>
      <h4>What happens next?</h4>
      <p>We'll take another look at your pod.</p>
      <p>If we find that your pod does follow our Community Guidelines, it will be visible again.</p>
      <p>If we find that your pod doesn't follow our Community Guidelines, it will be permanently disabled and you won't be able to appeal again.</p>
    <?php elseif ($S['rejected'] || $S['expired']): ?>
      <h3>Your pod has been disabled</h3>
      <p><?= $S['rejected'] ? "We reviewed your appeal and your pod stays permanently disabled. You can't appeal again." : "The time to appeal has ended, so your pod is permanently disabled." ?></p>
      <?php if ($S['ts']): ?><div class="sp-date">Suspended on <?= $__e(date('F j, Y', $S['ts'])) ?></div><?php endif; ?>
    <?php else: ?>
      <h3>We suspended your pod</h3>
      <p><?= (int)$S['left'] ?> day<?= $S['left'] === 1 ? '' : 's' ?> left to appeal or we'll permanently disable your pod</p>
      <?php if ($S['ts']): ?><div class="sp-date">Suspended on <?= $__e(date('F j, Y', $S['ts'])) ?></div><?php endif; ?>
      <h4>Why this happened</h4>
      <p>Your pod didn't follow the Dezzme Community Guidelines.</p>
      <a class="sp-learn" href="guidelines.php">Learn more</a>
      <h4>What this means</h4>
      <p>Your pod is not visible to people on Dezzme right now, and nobody can post in it.</p>
      <h4>What you can do</h4>
      <p>If you think we made a mistake, you can appeal. We'll take another look at your pod.</p>
      <button type="button" class="sp-btn" id="spOpen" onclick="document.getElementById('spForm').classList.add('open');this.style.display='none'">Appeal</button>
      <form class="sp-form" id="spForm" onsubmit="return gpSpSend(event)">
        <textarea id="spMsg" maxlength="1000" placeholder="Tell us why you think this was a mistake (optional)"></textarea>
        <div class="sp-err" id="spErr"></div>
        <button type="submit" class="sp-btn" id="spSub">Submit appeal</button>
      </form>
    <?php endif; ?>
  <?php else: ?>
    <div class="sp-center">
      <h3>This pod has been suspended</h3>
      <p>This pod didn't follow Dezzme's Community Guidelines.</p>
      <a class="sp-learn" href="guidelines.php">Learn more</a>
      <?php if ($S['admin']): ?>
      <div style="margin-top:18px">
        <div class="sp-date" style="margin-bottom:8px">Admin view<?= $S['ts'] ? ' · Suspended on ' . $__e(date('F j, Y', $S['ts'])) : '' ?></div>
        <button type="button" class="sp-btn green" id="spUnsus">Unsuspend</button>
        <a class="sp-btn" href="group.php?id=<?= (int)$group_id ?>&amp;admin_peek=1">View pod anyway</a>
      </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  </div>
  <script>
  (function(){
    /* menu / search / join / post / picture do nothing on a suspended pod */
    document.addEventListener('click', function(e){
      var m = e.target.closest && e.target.closest('.gp-topbar .gp-bbtn:not(.back), .gp-joinpill, .gp-pic, .gp-name-row');
      if (m) { e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation(); }
    }, true);
    var u = document.getElementById('spUnsus');
    if (u) u.onclick = function(){
      var b=this; b.disabled=true;
      fetch('report_center.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'rc_action=unsuspend_pod&rtype=pod&rid=0&gid=<?= (int)$group_id ?>'})
        .then(function(r){return r.text();}).then(function(t){
          var d=null; try{ var i=t.indexOf('{'); d=JSON.parse(i>0?t.slice(i):t); }catch(e){}
          if(d&&d.ok) location.reload(); else { b.disabled=false; alert((d&&d.msg)||('Could not unsuspend: '+t.replace(/<[^>]*>/g,' ').trim().slice(0,160))); }
        }).catch(function(){ b.disabled=false; alert('Network error'); });
    };
  })();
  function gpSpSend(ev){
    ev.preventDefault();
    var b=document.getElementById('spSub'), er=document.getElementById('spErr'); b.disabled=true; er.textContent='';
    var t=(typeof CSRF_TOKEN!=='undefined'?CSRF_TOKEN:(window.DZ_CSRF||''));
    var body='action=submit_pod_appeal&csrf_token='+encodeURIComponent(t)+'&message='+encodeURIComponent(document.getElementById('spMsg').value);
    fetch(location.pathname+location.search,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':t},body:body})
      .then(function(r){return r.json();}).then(function(d){ if(d&&d.ok) location.reload(); else { er.textContent=(d&&d.error)||'Could not send your appeal. Try again.'; b.disabled=false; } })
      .catch(function(){ er.textContent='Network error. Try again.'; b.disabled=false; });
    return false;
  }
  </script>
  <?php endif; ?>

  <!-- ═════════ MEMBERSHIP PENDING BOX (private pod with join questions) ═════════ -->
  <?php if ($gp_jq_box): ?>
  <div class="gp-pause gp-pend" id="gpPendBox">
    <div class="gp-pause-title gp-tight">Your membership is pending</div>
    <div class="gp-pause-body gp-tight0">
      <?php echo $gp_jq_done ? 'Your answers were sent. The group admins will review your request to join.' : 'Answer a few questions to help the group admins review your request to join.'; ?>
    </div>
    <div class="gp-pause-btns">
      <button type="button" class="gp-pbtn manage" onclick="gpJqOpen()"><?php echo $gp_jq_done ? 'Edit answers' : 'Answer questions'; ?></button>
      <form method="POST" onsubmit="return confirm('Withdraw your request to join this pod?');">
        <button type="submit" name="cancel_request" class="gp-pbtn learn">Cancel request</button>
      </form>
    </div>
  </div>
  <?php gp_jq_modal($gp_jq_list, $gp_jq_err); ?>
  <?php endif; ?>

  <!-- ═════════ PAUSE NOTICE ═════════ -->
  <?php if ($gp_pause_visible): ?>
  <div class="gp-pause" id="gpPauseCard">
    <div class="gp-pause-top">
      <div class="gp-pause-ic"><i class="ri-pause-circle-fill"></i></div>
      <div class="gp-pause-title">This group is temporarily paused<?php if ($group_pause_hours_left): ?> for <?php echo $group_pause_hours_left; ?> hour<?php echo $group_pause_hours_left == 1 ? '' : 's'; ?><?php endif; ?></div>
      <button class="gp-x" onclick="gpDismissPause()" aria-label="Dismiss"><i class="ri-close-line"></i></button>
    </div>
    <div class="gp-pause-body">
      An <?php echo $pause_actor_label; ?> paused this group on <?php echo date('j F Y', strtotime($group['paused_at'])); ?>.
      <?php if ($group_pause_resumes_at): ?>Group activity will resume on <?php echo date('j F Y', $group_pause_resumes_at); ?> at <?php echo date('H:i', $group_pause_resumes_at); ?>.<?php endif; ?>
    </div>
    <div class="gp-pause-btns">
      <button class="gp-pbtn learn" onclick="gpOpen('gpLearnOv')">Learn more</button>
      <?php if ($is_owner || $is_admin): ?><button class="gp-pbtn manage" onclick="gpOpen('gpManageOv')">Manage</button><?php endif; ?>
    </div>
  </div>

  <div class="gp-ov pf-ov" id="gpManageOv" onclick="if(event.target===this)gpClose('gpManageOv')">
    <div class="gp-sheet pf" role="dialog" aria-modal="true" aria-label="Manage pause">
      <div class="pf-handle"><span></span></div>
      <div class="pf-card">
        <div class="pf-scroll">
          <div class="pf-header">Manage pause</div>
          <div class="pf-name"><?php echo htmlspecialchars($group['title']); ?></div>
          <div class="pf-text">
            <p>This group is currently paused. Members can't post, react, upload files, or request to join until it's resumed.</p>
            <?php if ($group_pause_resumes_at): ?><p>It resumes automatically on <strong class="gp-hl"><?php echo date('j F Y', $group_pause_resumes_at); ?></strong> at <strong class="gp-hl"><?php echo date('H:i', $group_pause_resumes_at); ?></strong> — or resume it now.</p><?php endif; ?>
          </div>
          <form method="POST" action="group.php?id=<?php echo $group_id; ?>" onsubmit="return confirm('Resume this group now? Members will be able to post, react and join again.');">
            <button type="submit" name="toggle_pause" class="pf-item">
              <span class="pf-ic go"><i class="ri-play-circle-line"></i></span>
              <span class="pf-tx"><span class="pf-t">Unpause group</span><span class="pf-s" style="display:block">Members can post and join again right away</span></span>
            </button>
          </form>
        </div>
      </div>
      <button type="button" class="pf-close" onclick="gpClose('gpManageOv')">Cancel</button>
    </div>
  </div>

  <div class="gp-ov pf-ov" id="gpLearnOv" onclick="if(event.target===this)gpClose('gpLearnOv')">
    <div class="gp-sheet pf" role="dialog" aria-modal="true" aria-label="What pausing does">
      <div class="pf-handle"><span></span></div>
      <div class="pf-card">
        <div class="pf-scroll">
          <div class="pf-header">What pausing does</div>
          <div class="pf-name">While this group is paused</div>
          <div style="height:calc(6px * var(--bs-s,1))"></div>
          <div class="pf-item"><span class="pf-ic no"><i class="ri-close-line"></i></span><span class="pf-tx"><span class="pf-t">Members can't create new posts</span></span></div>
          <div class="pf-item"><span class="pf-ic no"><i class="ri-close-line"></i></span><span class="pf-tx"><span class="pf-t">Members can't react to posts</span></span></div>
          <div class="pf-item"><span class="pf-ic no"><i class="ri-close-line"></i></span><span class="pf-tx"><span class="pf-t">Members can't create new events</span></span></div>
          <div class="pf-item"><span class="pf-ic no"><i class="ri-close-line"></i></span><span class="pf-tx"><span class="pf-t">Members can't upload files</span></span></div>
          <div class="pf-item"><span class="pf-ic no"><i class="ri-close-line"></i></span><span class="pf-tx"><span class="pf-t">New join requests are paused</span></span></div>
          <div class="pf-item"><span class="pf-ic yes"><i class="ri-check-line"></i></span><span class="pf-tx"><span class="pf-t">Everyone can still browse</span><span class="pf-s" style="display:block">Existing posts, photos, videos, files and discussions</span></span></div>
          <div class="pf-item"><span class="pf-ic yes"><i class="ri-check-line"></i></span><span class="pf-tx"><span class="pf-t">Admins and the owner can still moderate</span><span class="pf-s" style="display:block">Change settings and post announcements</span></span></div>
        </div>
      </div>
      <button type="button" class="pf-close" onclick="gpClose('gpLearnOv')">Got it</button>
    </div>
  </div>
  <?php endif; ?>

  <!-- ═════════ NAV BAR (normal view: hover arrows to scroll, same as Suggested Pods) ═════════ -->
  <div class="gp-nav-wrap" id="gpNavWrap">
  <button type="button" class="gp-nav-arrow gp-nav-arrow-l hidden" aria-label="Scroll left" onclick="gpNavScroll(-1)">&#8249;</button>
  <div class="gp-nav" id="gpNav">
    <a href="memepedia.php?group_id=<?php echo $group_id; ?>" class="gp-nav-item">Memepedia</a>

    <!-- Rules ⇄ Feed: swaps the highlights / events / posts for the rules grid (and back). Does NOT open another page. -->
    <button type="button" class="gp-nav-item" id="gpRulesBtn" onclick="gpToggleRules()" aria-pressed="false">Rules</button>

    <?php if ($gp_paused_lock): ?>
      <span class="gp-nav-item locked" onclick="showDiscLockToast('<?php echo htmlspecialchars($gp_pause_msg); ?>')">You</span>
    <?php elseif ($is_member): ?>
      <button type="button" class="gp-nav-item" onclick="openYouModal()">
        <img src="<?php echo htmlspecialchars($cu_pic ?? $current_user_pic); ?>" onerror="this.src='mee.jpg'" alt=""> You
      </button>
    <?php else: ?>
      <span class="gp-nav-item locked" onclick="showDiscLockToast('Join this pod to access You')">You</span>
    <?php endif; ?>

    <?php if ($gp_locked_more): ?>
      <span class="gp-nav-item locked" onclick="showDiscLockToast('Join this pod to access See more')">See more</span>
    <?php else: ?>
      <a href="group_more.php?id=<?php echo $group_id; ?>" class="gp-nav-item">See more</a>
    <?php endif; ?>

    <?php if ($gp_paused_lock): ?>
      <span class="gp-nav-item locked" onclick="showDiscLockToast('<?php echo htmlspecialchars($gp_pause_msg); ?>')">Events</span>
    <?php elseif ($gp_locked_member): ?>
      <span class="gp-nav-item locked" onclick="showDiscLockToast('Join this pod to access Events')">Events</span>
    <?php else: ?>
      <a href="group_events.php?id=<?php echo $group_id; ?>" class="gp-nav-item">Events</a>
    <?php endif; ?>

    <?php if ($gp_paused_lock): ?>
      <span class="gp-nav-item locked" onclick="showDiscLockToast('<?php echo htmlspecialchars($gp_pause_msg); ?>')">Files</span>
    <?php elseif ($gp_locked_member): ?>
      <span class="gp-nav-item locked" onclick="showDiscLockToast('Join this pod to access Files')">Files</span>
    <?php else: ?>
      <a href="group_files.php?id=<?php echo $group_id; ?>" class="gp-nav-item">Files</a>
    <?php endif; ?>

    <a href="help.php" class="gp-nav-item">Help Centre</a>
  </div>
  <button type="button" class="gp-nav-arrow gp-nav-arrow-r" aria-label="Scroll right" onclick="gpNavScroll(1)">&#8250;</button>
  </div>

  <!-- ═════════ FILTER BAR (second nav bar — has hover arrows too; hidden while the Rules grid is open) ═════════ -->
  <div id="gpNavSentinel"></div>
  <div class="gp-filterbar" id="gpFilterBar">
    <button type="button" class="gp-f-arrow gp-f-arrow-l hidden" aria-label="Scroll left" onclick="gpFilterScroll(-1)">&#8249;</button>
    <div class="gp-filter-scroll" id="gpFilterScrollEl">
      <button type="button" class="gp-fpill" onclick="gpOpenSort()">
        <i class="ri-arrow-up-down-line"></i> Sort: <span id="gpSortLabel">Newest</span>
      </button>
      <span class="gp-filter-sep"></span>
      <?php if (empty($group_hashtags)): ?>
        <span class="gp-fpill none">None</span>
      <?php else: ?>
        <?php foreach ($group_hashtags as $gp_tag): ?>
        <button type="button" class="gp-fpill gp-tag-pill" data-tag="<?php echo htmlspecialchars($gp_tag['tag']); ?>"
                onclick="gpFilterTag(this.dataset.tag, this)">#<?php echo htmlspecialchars($gp_tag['tag']); ?></button>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <button type="button" class="gp-f-arrow gp-f-arrow-r hidden" aria-label="Scroll right" onclick="gpFilterScroll(1)">&#8250;</button>
  </div>

  <!-- ═════════ RULES GRID — opened by the Rules button, starts exactly where Pod Highlights start ═════════ -->
  <div id="gpRulesView">
    <div class="gp-rules">

      <div class="gp-rules-head"><i class="ri-book-2-line"></i> Universal pod rules</div>
      <div class="gp-rules-grid">
        <?php foreach ($gp_universal_rules as $gp_ri => $gp_ru): ?>
        <div class="gp-rule-card">
          <div class="gp-rule-top">
            <div class="gp-rule-ic"><i class="<?php echo gs_h($gp_ru[0]); ?>"></i></div>
            <span class="gp-rule-num">#<?php echo $gp_ri + 1; ?></span>
          </div>
          <span><?php echo gs_h($gp_ru[1]); ?></span>
        </div>
        <?php endforeach; ?>

        <div class="gp-rule-card strike wide">
          <div class="gp-rule-top">
            <div class="gp-rule-ic"><i class="ri-alarm-warning-line"></i></div>
          </div>
          <div class="gp-rule-title">Strike system</div>
          <div class="gp-rule-steps">
            <div class="gp-rule-step"><span class="n">1</span><span>Warning</span></div>
            <div class="gp-rule-step"><span class="n">2</span><span>Temporary removal</span></div>
            <div class="gp-rule-step danger"><span class="n">3</span><span>Permanent ban — you can <strong>NEVER</strong> rejoin that pod or ANY pod created by that creator, ever</span></div>
          </div>
        </div>
      </div>

      <div class="gp-rules-head"><i class="ri-vip-crown-line"></i> Owner's rules</div>
      <div class="gp-rules-grid gp-rules-bars">
        <?php if (!empty($gp_owner_lines)): ?>
          <?php foreach ($gp_owner_lines as $gp_oi => $gp_ol): ?>
          <div class="gp-rule-bar"><span><?php echo gs_h($gp_ol); ?></span></div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="gp-rule-card empty wide">No additional guidelines set by the creator.</div>
        <?php endif; ?>
      </div>

    </div>
  </div>

  <!-- ═════════ FEED VIEW (highlights → events → private explainer / posts) — hidden while the Rules grid is open ═════════ -->
  <div id="gpFeedView">

  <?php /* ═════════ CATCH ME UP + WEEKLY CHALLENGE — top of the feed view ═════════ */ ?>
  <?php if ($gx_catch):
      $gx_h = (int)$gx_catch['hours'];
      $gx_ago = $gx_h >= 48 ? floor($gx_h / 24) . ' days ago' : ($gx_h >= 24 ? 'yesterday' : $gx_h . ' hour' . ($gx_h === 1 ? '' : 's') . ' ago');
      $gx_bits = [$gx_catch['posts'] . ' new post' . ($gx_catch['posts'] === 1 ? '' : 's')];
      if ($gx_catch['members'] > 0) $gx_bits[] = $gx_catch['members'] . ' new member' . ($gx_catch['members'] === 1 ? '' : 's');
      if ($gx_catch['events'] > 0)  $gx_bits[] = $gx_catch['events'] . ' new event' . ($gx_catch['events'] === 1 ? '' : 's');
  ?>
  <div class="gx-banner" id="gxCatch" data-key="<?php echo htmlspecialchars($gx_catch['key']); ?>" hidden>
    <i class="ri-flashlight-line gx-ic"></i>
    <div class="gx-bt"><b>Catch me up</b><span>Since you were last here (<?php echo htmlspecialchars($gx_ago); ?>): <?php echo htmlspecialchars(implode(' · ', $gx_bits)); ?></span></div>
    <div class="gx-acts"><button type="button" class="gx-btn" onclick="gxCatchGo()">Show me</button><button type="button" class="gx-x" aria-label="Dismiss" onclick="gxCatchX()">&times;</button></div>
  </div>
  <?php endif; ?>

  <?php if ($gx_can_see && $gx_challenge):
      $gx_len = (int)round((strtotime($gx_challenge['ends_at']) - strtotime($gx_challenge['starts_at'])) / 86400);
  ?>
  <div class="gp-pause gx-chal" id="gxChal" data-left="<?php echo (int)$gx_challenge['secs_left']; ?>">
    <div class="gp-pause-top">
      <div class="gp-pause-ic"><i class="ri-trophy-fill"></i></div>
      <div class="gp-pause-title"><?php echo $gx_len === 7 ? 'Weekly challenge' : 'Pod challenge'; ?> · <?php echo htmlspecialchars($gx_challenge['title']); ?></div>
    </div>
    <div class="gp-pause-body">
      <?php if ($gx_challenge['description'] !== ''): ?><div class="gx-desc"><?php echo htmlspecialchars($gx_challenge['description']); ?></div><?php endif; ?>
      <div class="gx-meta"><span id="gxChLeft"></span> · <?php echo (int)$gx_challenge['entries']; ?> entr<?php echo (int)$gx_challenge['entries'] === 1 ? 'y' : 'ies'; ?> from <?php echo (int)$gx_challenge['people']; ?> <?php echo (int)$gx_challenge['people'] === 1 ? 'person' : 'people'; ?>
      <?php if ($gx_challenge['hashtag'] !== '' && (int)$gx_challenge['entries'] > 0): ?> · <a href="group.php?id=<?php echo (int)$group_id; ?>&amp;search=<?php echo rawurlencode('#' . $gx_challenge['hashtag']); ?>">See entries</a><?php endif; ?></div>
    </div>
    <?php if (($gx_member && !$gp_paused_lock) || $gx_staff): ?>
    <div class="gp-pause-btns">
      <?php if ($gx_member && !$gp_paused_lock): ?><button type="button" class="gp-pbtn manage" onclick="gxChJoin()">Join in</button><?php endif; ?>
      <?php if ($gx_staff): ?><button type="button" class="gp-pbtn learn" onclick="gxChOpen()">Edit</button><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php elseif ($gx_can_see && $gx_staff): ?>
  <button type="button" class="gx-add" onclick="gxChOpen()"><i class="ri-trophy-line"></i> Start a weekly challenge</button>
  <?php endif; ?>


  <!-- ═════════ POD HIGHLIGHTS ═════════ -->
  <?php if ($gp_hl_count > 0): ?>
  <div class="gh-section" id="ghSection">
    <div class="gh-head">
      <div class="gh-title"><i class="ri-pushpin-2-fill"></i><span>Pod Highlights</span></div>
      <button type="button" class="gh-toggle" id="ghToggle" onclick="ghToggle()" aria-expanded="true" aria-controls="ghBody" aria-label="Hide or show pod highlights"><i class="ri-arrow-down-s-fill"></i></button>
    </div>
    <div class="gh-body" id="ghBody">
    <div class="gh-wrap" id="ghWrap">
      <button type="button" class="gh-arrow left hidden"  id="ghLeft"  aria-label="Scroll highlights left">&#8249;</button>
      <button type="button" class="gh-arrow right" id="ghRight" aria-label="Scroll highlights right">&#8250;</button>
      <?php $gh_batch = 8; $gh_n = 0; ?>
      <div class="gh-strip" id="ghStrip" data-batch="<?php echo (int)$gh_batch; ?>">
        <?php foreach ($gp_highlights as $gh):
            $gh_hold = ($gh_n >= $gh_batch);
            $gh_n++;
            $gh_type = (string)($gh['first_type'] ?? '');
            $gh_src  = !empty($gh['cover_path']) ? $gh['cover_path'] : (string)($gh['first_path'] ?? '');
            $gh_custom = !empty($gh['cover_path']);
            $gh_cap  = trim((string)($gh['first_caption'] ?? ''));
            $gh_cap  = function_exists('mb_substr') ? mb_substr($gh_cap, 0, 70) : substr($gh_cap, 0, 70);
        ?>
        <button type="button" class="gh-tile<?php echo $gh_hold ? ' gh-hold' : ''; ?>" data-hl-id="<?php echo (int)$gh['id']; ?>"
                aria-label="Open highlight <?php echo htmlspecialchars($gh['title']); ?>"
                onclick="gpOpenHl(this, <?php echo (int)$gh['id']; ?>)">
          <span class="gh-ring">
            <span class="gh-sq">
              <?php if ($gh_custom): ?>
                <?php if ($gh_hold): ?><img data-src="<?php echo htmlspecialchars($gh_src); ?>" alt="" onerror="this.src='uploads/default_group.png'">
                <?php else: ?><img src="<?php echo htmlspecialchars($gh_src); ?>" alt="" loading="lazy" onerror="this.src='uploads/default_group.png'"><?php endif; ?>
              <?php elseif ($gh_type === 'poll'): ?>
                <span class="gh-poll"><i class="ri-bar-chart-horizontal-fill"></i><span><?php echo htmlspecialchars((string)($gh['first_poll_q'] ?? 'Poll')); ?></span></span>
              <?php elseif ($gh_type === 'text'): ?>
                <?php if ($gh_hold): ?><img data-src="<?php echo htmlspecialchars($gp_hl_text_bg); ?>" alt="">
                <?php else: ?><img src="<?php echo htmlspecialchars($gp_hl_text_bg); ?>" alt="" loading="lazy"><?php endif; ?>
                <?php if ($gh_cap !== ''): ?><span class="gh-cap"><?php echo htmlspecialchars($gh_cap); ?></span><?php endif; ?>
              <?php elseif ($gh_type === 'video'): ?>
                <?php if ($gh_hold): ?><video data-src="<?php echo htmlspecialchars($gh_src); ?>#t=0.1" muted playsinline preload="none"></video>
                <?php else: ?><video src="<?php echo htmlspecialchars($gh_src); ?>#t=0.1" muted playsinline preload="metadata"></video><?php endif; ?>
                <span class="gh-vid"><i class="ri-play-fill"></i></span>
              <?php else: ?>
                <?php if ($gh_hold): ?><img data-src="<?php echo htmlspecialchars($gh_src); ?>" alt="" onerror="this.src='uploads/default_group.png'">
                <?php else: ?><img src="<?php echo htmlspecialchars($gh_src); ?>" alt="" loading="lazy" onerror="this.src='uploads/default_group.png'"><?php endif; ?>
              <?php endif; ?>
            </span>
            <svg class="gh-svg" viewBox="0 0 100 130" aria-hidden="true" focusable="false"><rect x="1.6" y="1.6" width="96.8" height="126.8" rx="16" ry="16" pathLength="100"/></svg>
          </span>
          <span class="gh-name"><?php echo htmlspecialchars($gh['title']); ?></span>
        </button>
        <?php endforeach; ?>
      </div>
    </div>
    </div><!-- /.gh-body -->
  </div>
  <script>
  (function(){
    try{
      if(localStorage.getItem('gp_hl_hidden_'+gfGroupId)==='1'){
        var s=document.getElementById('ghSection'), b=document.getElementById('ghToggle');
        if(s) s.classList.add('collapsed');
        if(b) b.setAttribute('aria-expanded','false');
      }
    }catch(e){}
  })();
  </script>
  <?php endif; ?>

  <!-- ═════════ UPCOMING EVENTS — after Pod Highlights, before the first post ═════════ -->
  <?php if ($gp_ev_count > 0): ?>
  <div class="gp-ev" id="gpEv">
    <div class="gp-ev-head"><i class="ri-calendar-event-line"></i> Upcoming events <span class="gp-ev-count"><?php echo $gp_ev_count; ?></span></div>
    <?php foreach ($gp_events as $gp_i => $gp_e):
        $gp_e_virtual = ($gp_e['location_type'] === 'virtual');
        $gp_e_when = date('D, j M · g:i A', strtotime($gp_e['event_date'] . ' ' . $gp_e['event_time']));
        $gp_e_img  = !empty($gp_e['event_image']) ? 'uploads/' . $gp_e['event_image'] : '';
    ?>
    <a class="gp-ev-row<?php echo $gp_i >= 2 ? ' gp-ev-hold' : ''; ?>"
       href="group_events.php?id=<?php echo (int)$group_id; ?>#event-detail-<?php echo (int)$gp_e['id']; ?>">
      <div class="gp-ev-thumb">
        <i class="ri-calendar-event-fill gp-ev-fb"></i>
        <?php if ($gp_e_img): ?><img src="<?php echo htmlspecialchars($gp_e_img); ?>" alt="" loading="lazy" onerror="this.classList.add('gp-off')"><?php endif; ?>
      </div>
      <div class="gp-ev-info">
        <div class="gp-ev-date"><?php echo htmlspecialchars($gp_e_when); ?></div>
        <div class="gp-ev-title"><?php echo htmlspecialchars($gp_e['event_name']); ?></div>
        <span class="gp-ev-type"><i class="ri-<?php echo $gp_e_virtual ? 'live-line' : 'map-pin-line'; ?>"></i> <?php echo $gp_e_virtual ? 'Virtual meetup' : 'Physical meetup'; ?></span>
      </div>
    </a>
    <?php endforeach; ?>
    <?php if ($gp_ev_count > 2): ?>
    <button type="button" class="gp-ev-more" id="gpEvMore" onclick="gpEvMore()">See more</button>
    <?php endif; ?>
  </div>
  <script>
  function gpEvMore(){
    var holds=document.querySelectorAll('#gpEv .gp-ev-row.gp-ev-hold');
    for(var i=0;i<holds.length && i<2;i++){
      holds[i].classList.remove('gp-ev-hold');
      holds[i].classList.add('gp-ev-in');
    }
    if(holds.length<=2){ var b=document.getElementById('gpEvMore'); if(b) b.remove(); }
  }
  </script>
  <?php endif; ?>

  <!-- ═════════ PRIVATE POD EXPLAINER — replaces the empty feed ("Be the first to post") for anyone who hasn't joined a private pod ═════════ -->
  <?php if ($gp_private_locked): ?>
  <div class="gp-priv" id="gpPrivate">
    <div class="gp-priv-hero">
      <h2>This is a private pod</h2>
      <p>Everything shared inside <b><?php echo htmlspecialchars($group['title']); ?></b> — posts, highlights, events, files and discussions — is only visible to approved members. To get in, you send a request and the pod's creator or admins decide.</p>
    </div>

    <?php if (!empty($blocked_by_creator)): ?>
    <div class="gp-priv-status warn"><i class="ri-forbid-2-line"></i><div><b>You can't join this pod.</b> The creator has restricted your access, so requests from your account can't be sent.</div></div>
    <?php elseif ($my_request_status === 'pending'): ?>
    <div class="gp-priv-status"><i class="ri-time-line"></i><div><b>Your request is pending.</b> You'll get full access as soon as the creator or an admin approves it. Changed your mind? Use the <b>Requested</b> button above to withdraw it.</div></div>
    <?php elseif ($my_request_status === 'denied'): ?>
    <div class="gp-priv-status warn"><i class="ri-close-circle-line"></i><div><b>Your last request wasn't approved.</b> You're allowed to ask again — read the pod's description and rules first (tap <b>Rules</b> above) so your new request is a good fit.</div></div>
    <?php endif; ?>

    <div class="gp-priv-sec">
      <h3><i class="ri-question-line"></i> What is a private pod?</h3>
      <p>A pod is a community built around one theme, interest or crowd. A <b>private pod</b> is the closed version: it isn't open to just anyone who finds it. Joining is by approval only, and the people who run the pod (its creator and admins) choose who gets in.</p>
      <p>This keeps the conversation inside a group the pod actually picked, so what members post, say and share stays between them rather than being open to anybody browsing Dezzme.</p>
    </div>

    <div class="gp-priv-sec">
      <h3><i class="ri-eye-line"></i> What you can see right now</h3>
      <ul class="gp-priv-ul">
        <li><i class="ri-check-line ok"></i><span>The pod's name, picture, banner, description and category</span></li>
        <li><i class="ri-check-line ok"></i><span>The Help Centre, so you can understand how pods work</span></li>
        <li><i class="ri-check-line ok"></i><span>The universal pod rules and the creator's own rules (tap <b>Rules</b> in the bar above)</span></li>
      </ul>
    </div>

    <div class="gp-priv-sec">
      <h3><i class="ri-eye-off-line"></i> What stays hidden until you're approved</h3>
      <ul class="gp-priv-ul">
        <li><i class="ri-lock-line no"></i><span>Every post, photo, video and text post shared in the pod</span></li>
        <li><i class="ri-lock-line no"></i><span>Pod Highlights, upcoming events and shared files</span></li>
        <li><i class="ri-lock-line no"></i><span>Discussions and replies, plus the member list and activity</span></li>
        <li><i class="ri-lock-line no"></i><span>The admins list and the full "See more" pod details</span></li>
      </ul>
    </div>

    <div class="gp-priv-sec">
      <h3><i class="ri-door-open-line"></i> How joining works</h3>
      <ol class="gp-priv-steps">
        <li><b>Send a request.</b> Tap <b>Request to Join</b>. Nothing is shared with the pod until you do.</li>
        <li><b>The creator or an admin reviews it.</b> They decide whether you're a good fit for the pod's theme and community.</li>
        <li><b>If you're approved,</b> you become a member and the whole pod opens up — posts, highlights, events, files and discussions.</li>
        <li><b>If it's denied,</b> nothing bad happens to your account, and you can request again later. You can also withdraw a pending request at any time.</li>
      </ol>
    </div>

    <div class="gp-priv-sec">
      <h3><i class="ri-shield-check-line"></i> Once you're in</h3>
      <p>Members can post, react, repost, join discussions and invite others (unless the pod is paused). In return, everyone follows Dezzme's universal pod rules plus any extra guidelines the creator has set:</p>
      <ul class="gp-priv-ul">
        <li><i class="ri-focus-3-line ok"></i><span>Post only content that fits the pod's theme</span></li>
        <li><i class="ri-spam-2-line ok"></i><span>No spam, impersonation or repeated reposting</span></li>
        <li><i class="ri-alarm-warning-line ok"></i><span>Strikes escalate: a warning, then temporary removal, then a permanent ban from that pod <i>and</i> every pod by the same creator</span></li>
      </ul>
    </div>

    <?php if (empty($blocked_by_creator) && $my_request_status !== 'pending'): ?>
    <button type="button" class="gp-priv-cta" onclick="var b=document.getElementById('gpMainJoin'); if(b) b.click();">
      <i class="ri-lock-line"></i> <?php echo $my_request_status === 'denied' ? 'Request again' : 'Request to Join'; ?>
    </button>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php /* ═════════ PINNED POSTS — filled in by the script near the bottom (GX_PINS) ═════════ */ ?>
  <?php if ($gx_can_see && ($gx_pins || $gx_staff)): ?>
  <div class="gx-pins" id="gxPins" hidden></div>
  <?php endif; ?>

  <!-- ═════════ GROUP FEED ═════════ -->
  <div id="groupFeed"></div>
  <audio id="gpAudioPlayer" loop muted></audio>

  </div><!-- /#gpFeedView -->

</div><!-- /.gp-page -->

<!-- ═════════ RIGHT COLUMN — laptop / normal view only.
     Order: Pod info (5 key facts) → Admins → all posts grid ═════════ -->
<aside class="gp-grid-col" id="gpGridCol" aria-label="Pod info and all posts">

  <?php if (!empty($GP_SUSP)): /* suspended: no insights / admins / posts grid, just how suspensions work */ ?>
<style>
.dz-susp-side{ padding:2px 0 24px; }
.dz-susp-side .ss-bar{ display:flex; align-items:center; gap:7px; width:100%; height:calc(30px * var(--hd-s,1)); padding:0 12px; border-radius:var(--action-r,var(--rp-action-radius,5px)); background:rgba(var(--fg,255,255,255),.1); color:var(--text,#F0F2FF); font-size:.72rem; font-weight:600; }
.dz-susp-side .ss-bar i{ font-size:14px; color:var(--ss-accent,var(--blue-soft,#5b8fe6)); }
.dz-susp-side .ss-item{ padding:12px 4px 0; }
.dz-susp-side .ss-item b{ display:block; font-size:.76rem; font-weight:700; color:rgba(var(--fg,255,255,255),.95); margin-bottom:3px; }
.dz-susp-side .ss-item span{ display:block; font-size:.72rem; line-height:1.55; color:rgba(var(--fg,255,255,255),.62); }
.dz-susp-side .ss-link{ display:inline-block; margin:14px 4px 0; font-size:.72rem; font-weight:700; color:var(--ss-accent,var(--blue-soft,#5b8fe6)); text-decoration:none; }
</style>
<div class="dz-susp-side">
  <div class="ss-bar"><i class="ri-shield-keyhole-line"></i><span>About suspensions</span></div>
  <div class="ss-item"><b>What leads to a suspension</b><span>Harassment, hate speech, spam or bot activity, impersonation, or sharing harmful content.</span></div>
  <div class="ss-item"><b>How appeals work</b><span>Only the pod's creator can appeal, within 180 days of the suspension. Most appeals are reviewed in a day or two.</span></div>
  <div class="ss-item"><b>If an appeal is approved</b><span>The pod comes back exactly as it was, with nothing lost.</span></div>
  <div class="ss-item"><b>If it isn't</b><span>The pod is permanently disabled and can't be appealed again.</span></div>
  <div class="ss-item"><b>See something wrong?</b><span>You can report any pod or post from its menu.</span></div>
  <a class="ss-link" href="guidelines.php">Read the Community Guidelines</a>
</div>
  <?php elseif ($gp_side_ok): ?>
  <div class="gs-cards">

    <?php /* ───────── ADMIN INSIGHTS — owner / admins only ───────── */ ?>
    <?php if ($gx_ins):
        $gx_trend = function ($cur, $prev) {
            if ($prev <= 0) return $cur > 0 ? ['up', 'new'] : ['flat', '—'];
            $d = (int)round((($cur - $prev) / $prev) * 100);
            return [$d > 0 ? 'up' : ($d < 0 ? 'down' : 'flat'), ($d > 0 ? '▲ ' : ($d < 0 ? '▼ ' : '')) . abs($d) . '%'];
        };
        $gx_tv = $gx_trend($gx_ins['visitors'], $gx_ins['visitors_prev']);
        $gx_tp = $gx_trend($gx_ins['posts'], $gx_ins['posts_prev']);
        $gx_max = 1; foreach ($gx_ins['days'] as $gx_d) $gx_max = max($gx_max, $gx_d['v']);
    ?>
    <?php gs_open('insights', 'ri-bar-chart-2-line', 'Admin insights', 'Last 7 days'); ?>
      <div class="gx-ins-grid">
        <div class="gx-ins-t"><b><?php echo number_format($gx_ins['visitors']); ?><em class="<?php echo $gx_tv[0]; ?>"><?php echo gs_h($gx_tv[1]); ?></em></b><span>Visitors</span></div>
        <div class="gx-ins-t"><b><?php echo number_format($gx_ins['posts']); ?><em class="<?php echo $gx_tp[0]; ?>"><?php echo gs_h($gx_tp[1]); ?></em></b><span>New posts</span></div>
        <div class="gx-ins-t"><b><?php echo number_format($gx_ins['new_members']); ?></b><span>New members</span></div>
        <div class="gx-ins-t"><b><?php echo number_format($gx_ins['requests'] + $gx_ins['reports']); ?></b><span><?php echo (int)$gx_ins['requests']; ?> join request<?php echo (int)$gx_ins['requests'] === 1 ? '' : 's'; ?> · <?php echo (int)$gx_ins['reports']; ?> report<?php echo (int)$gx_ins['reports'] === 1 ? '' : 's'; ?></span></div>
      </div>
      <div class="gx-ins-cap">Visitors per day</div>
      <div class="gx-bars">
        <?php foreach ($gx_ins['days'] as $gx_d): ?>
        <div class="gx-bar" title="<?php echo (int)$gx_d['v']; ?> visitor<?php echo (int)$gx_d['v'] === 1 ? '' : 's'; ?> · <?php echo (int)$gx_d['p']; ?> post<?php echo (int)$gx_d['p'] === 1 ? '' : 's'; ?>"><i class="gx-b h<?php echo $gx_d['v'] > 0 ? max(1, (int)round($gx_d['v'] / $gx_max * 10)) : 0; ?>"></i></div>
        <?php endforeach; ?>
      </div>
      <div class="gx-bar-l"><?php foreach ($gx_ins['days'] as $gx_d): ?><span><?php echo gs_h(substr($gx_d['l'], 0, 2)); ?></span><?php endforeach; ?></div>
      <div class="gs-row"><span class="gs-row-l">Top contributor</span><span class="gs-row-v"><?php echo $gx_ins['top_user'] ? gs_h($gx_ins['top_user']['name']) . ' · ' . (int)$gx_ins['top_user']['n'] : '—'; ?></span></div>
      <div class="gs-row"><span class="gs-row-l">Most-liked post</span><span class="gs-row-v"><?php if ($gx_ins['top_post']): ?><button type="button" class="gx-ins-btn" onclick="if(window.gpGridOpen)gpGridOpen(<?php echo (int)$gx_ins['top_post']['id']; ?>)">Open · <?php echo (int)$gx_ins['top_post']['likes']; ?> like<?php echo (int)$gx_ins['top_post']['likes'] === 1 ? '' : 's'; ?></button><?php else: ?>—<?php endif; ?></span></div>
    <?php gs_close(); ?>
    <?php endif; ?>

    <?php /* ───────── POD INFO — only the five most important facts ───────── */ ?>
    <?php gs_open('info', 'ri-information-line', 'Pod info'); ?>
      <div class="gs-row"><span class="gs-row-l">Date created</span><span class="gs-row-v"><?php echo gs_h($gp_side_created); ?></span></div>
      <div class="gs-row"><span class="gs-row-l">Pod creator</span>
        <?php if ($gp_side_creator['name'] !== ''): ?>
          <a class="gs-row-v" href="profile.php?user_id=<?php echo (int)$gp_side_creator['id']; ?>"><?php echo gs_h($gp_side_creator['name']); ?></a>
        <?php else: ?><span class="gs-row-v">—</span><?php endif; ?>
      </div>
      <div class="gs-row"><span class="gs-row-l">Members</span><span class="gs-row-v"><?php echo number_format($gp_side_members); ?></span></div>
      <div class="gs-row"><span class="gs-row-l">Total posts</span><span class="gs-row-v"><?php echo number_format($total_posts_group); ?></span></div>
      <div class="gs-row"><span class="gs-row-l">Former pod names</span><span class="gs-row-v"><?php echo $gp_name_changes === 0 ? 'Never changed' : $gp_name_changes . ' change' . ($gp_name_changes === 1 ? '' : 's'); ?></span></div>
      <?php if (!$gp_locked_more): ?>
      <a class="gs-more" href="group_more.php?id=<?php echo (int)$group_id; ?>">See full pod info</a>
      <?php endif; ?>
    <?php gs_close(); ?>

    <?php /* ───────── ADMINS (hidden until you can see the pod's content) ───────── */ ?>
    <?php if (!empty($can_view_content)): ?>
    <?php gs_open('admins', 'ri-shield-star-line', 'Admins', (string)count($gp_side_admins)); ?>
      <?php if (!empty($gp_side_admins)): ?>
        <?php foreach ($gp_side_admins as $gs_ad): ?>
        <a class="gs-admin" href="profile.php?user_id=<?php echo (int)$gs_ad['id']; ?>">
          <img loading="lazy" decoding="async" src="<?php echo gs_h(!empty($gs_ad['profile_pic']) ? $gs_ad['profile_pic'] : 'mee.jpg'); ?>" onerror="this.src='mee.jpg'" alt="">
          <span class="gs-admin-n"><?php echo gs_h($gs_ad['username']); ?></span><?php if (dz_vb_flag($conn, (int)$gs_ad['id'])) echo dz_vb_svg(); ?>
          <span class="gs-admin-tag">ADMIN</span>
        </a>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="gs-note">No admins besides the pod creator yet.</div>
      <?php endif; ?>
    <?php gs_close(); ?>
    <?php endif; ?>


  </div>
  <?php endif; ?>

  <?php if (!$gp_private_locked && empty($GP_SUSP)): ?>
  <div class="gp-grid-head"><i class="ri-layout-grid-line"></i> All posts <span class="gp-grid-count" id="gpGridCount"></span></div>
  <div class="gp-grid" id="gpGrid"></div>
  <?php endif; ?>
</aside>
</div><!-- /.gp-shell -->

<style>
/* ═══ FILE MENU SHEET — same design as the Post options sheet in shared_modals: lane-coloured sheet, thin handle,
   one rounded inner card with the file name as its tiny uppercase title, round dashed icons, separate Cancel card ═══ */
#gpFsOv{ background:rgba(0,0,0,.6); backdrop-filter:blur(2px); -webkit-backdrop-filter:blur(2px); }
#gpFsOv .gp-sheet.gp-fs-sheet{ --dz-s:1.1; --dz-lane:#070924; --dz-card:#090b29; --dz-fg:255,255,255; --dz-text:#F0F2FF; --dz-red:#FF2D2D;
    width:100%; max-width:100%; background:var(--dz-lane); border:0; border-radius:0; box-shadow:none;
    padding:calc(5px * var(--dz-s)) calc(10px * var(--dz-s)) calc(env(safe-area-inset-bottom,0px) + 10px * var(--dz-s));
    display:flex; flex-direction:column; gap:calc(8px * var(--dz-s)); color:var(--dz-text); font-family:Poppins,sans-serif; max-height:70vh; }
:root[data-theme="light"] #gpFsOv .gp-sheet.gp-fs-sheet{ --dz-lane:#ffffff; --dz-card:#eef1f7; --dz-fg:15,20,40; --dz-text:#14171f; }
#gpFsOv .gp-handle{ display:block; width:calc(30px * var(--dz-s)); height:calc(3px * var(--dz-s)); border-radius:2px; background:rgba(var(--dz-fg),.22); margin:0 auto; flex-shrink:0; }
#gpFsOv .gp-fs-card{ background:var(--dz-card); border-radius:calc(6px * var(--dz-s)); display:flex; flex-direction:column; min-height:0; overflow-y:auto; }
#gpFsOv .gp-fs-title{ font-size:calc(8.5px * var(--dz-s)); font-weight:400; color:rgba(var(--dz-fg),.35); letter-spacing:1px; text-transform:uppercase; padding:calc(7px * var(--dz-s)) calc(12px * var(--dz-s)) calc(3px * var(--dz-s)); border:0; }
#gpFsOv .gp-fs-item{ display:flex; align-items:center; gap:calc(9px * var(--dz-s)); width:100%; padding:calc(6px * var(--dz-s)) calc(12px * var(--dz-s)); border:0; border-top:1px solid rgba(var(--dz-fg),.08); border-radius:0; background:none; color:var(--dz-text); font-family:inherit; font-size:calc(11.5px * var(--dz-s)); font-weight:600; text-align:left; text-decoration:none; transition:background .2s; }
#gpFsOv .gp-fs-item:hover,#gpFsOv .gp-fs-item:active{ background:rgba(var(--dz-fg),.06); }
#gpFsOv .gp-fs-item i,#gpFsOv .gp-fs-item.active i{ width:calc(27px * var(--dz-s)); height:calc(27px * var(--dz-s)); border-radius:50%; flex-shrink:0; box-sizing:border-box; display:flex; align-items:center; justify-content:center; text-align:center; border:1.5px dashed rgba(var(--dz-fg),.3); color:rgba(var(--dz-fg),.6); font-size:calc(14px * var(--dz-s)); }
#gpFsOv .gp-fs-item.active i{ color:var(--dz-red); border-color:rgba(255,45,45,.45); }
#gpFsOv .gp-fs-sheet .cancel,#gpFsOv .gp-sheet.gp-fs-sheet > .cancel{ display:flex; align-items:center; justify-content:center; width:100%; margin:0; padding:calc(10px * var(--dz-s)); background:var(--dz-card); border:0; border-radius:calc(6px * var(--dz-s)); color:rgba(var(--dz-fg),.85); font-size:calc(12px * var(--dz-s)); font-weight:600; font-family:Poppins,sans-serif; flex-shrink:0; transition:background .2s, color .2s; }
#gpFsOv .gp-sheet.gp-fs-sheet > .cancel:hover{ color:var(--dz-red); }
@media (min-width:791px){
    #gpFsOv .gp-sheet.gp-fs-sheet{ max-width:360px; width:90vw; border-radius:calc(8px * var(--dz-s)); padding:calc(14px * var(--dz-s)); gap:calc(12px * var(--dz-s)); box-shadow:0 16px 40px rgba(0,0,0,.6); }
    #gpFsOv .gp-handle{ display:none; }
    #gpFsOv .gp-fs-card{ border-radius:calc(4px * var(--dz-s)); }
    #gpFsOv .gp-fs-title{ padding:calc(12px * var(--dz-s)) calc(16px * var(--dz-s)) calc(6px * var(--dz-s)); font-size:calc(10px * var(--dz-s)); }
    #gpFsOv .gp-fs-item{ gap:calc(10px * var(--dz-s)); padding:calc(10px * var(--dz-s)) calc(14px * var(--dz-s)); font-size:calc(13px * var(--dz-s)); }
    #gpFsOv .gp-fs-item i,#gpFsOv .gp-fs-item.active i{ width:calc(34px * var(--dz-s)); height:calc(34px * var(--dz-s)); font-size:calc(17px * var(--dz-s)); }
}
</style>
<!-- ═════════ FILE MENU SHEET (feed file items) — compact on normal view, full-width on responsive, lines between buttons ═════════ -->
<div class="gp-ov" id="gpFsOv" onclick="if(event.target===this)gpClose('gpFsOv')">
  <div class="gp-sheet gp-fs-sheet">
    <div class="gp-handle"></div>
    <div class="gp-fs-card">
      <div class="gp-fs-title" id="gpFsTitle">File</div>
      <a class="gp-fs-item" id="gpFsDl" href="#"><i class="ri-download-2-line"></i> Download</a>
      <button type="button" class="gp-fs-item" id="gpFsSave" onclick="gpFsSave()"><i class="ri-bookmark-line"></i> <span>Save</span></button>
      <button type="button" class="gp-fs-item" onclick="gpFsShare()"><i class="ri-share-forward-line"></i> Share</button>
      <a class="gp-fs-item" id="gpFsOpen" href="#"><i class="ri-folder-open-line"></i> View in Files</a>
    </div>
    <button type="button" class="cancel" onclick="gpClose('gpFsOv')">Cancel</button>
  </div>
</div>


<!-- ═══════════════════════════════════════════════════════
     AGE GATE OVERLAY — only for 18+ / NSFW pods you have not joined yet
     (full screen, centered lane, dark mid-tone blue)
═══════════════════════════════════════════════════════ -->
<?php if ($show_age_gate): ?>
<div id="ageGateOverlay" class="open" role="dialog" aria-modal="true" aria-labelledby="agTitle">
  <div class="ag-lane">

    <div class="agv2-icon-wrap">
        <i class="ri-shield-line agv2-icon-shield"></i>
    </div>

    <h2 class="agv2-title" id="agTitle">This group shares 18+ content</h2>

    <p class="agv2-sub">
        Their content may be inappropriate for people under 18.
        You must confirm you are <strong>18 years of age or older</strong>
        to view, join, or interact with this pod.
    </p>

    <div class="agv2-btns">
        <button type="button" class="agv2-btn agv2-btn-continue" onclick="confirmAge()">
            <i class="ri-checkbox-circle-line"></i> Continue
        </button>
        <button type="button" class="agv2-btn agv2-btn-learn" onclick="openAgeLearnMore()">
            <i class="ri-information-line"></i> Learn more
        </button>
        <button type="button" class="agv2-btn agv2-btn-back" onclick="history.back()">
            <i class="ri-arrow-left-line"></i> Go Back
        </button>
    </div>

  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     LEARN MORE MODAL — full screen, centered lane + grey side lines
═══════════════════════════════════════════════════════ -->
<div id="ageGateLearnMoreModal" role="dialog" aria-modal="true" aria-label="About 18+ pods">
  <div class="aglm-lane">

    <div class="aglm-topbar">
        <div class="aglm-tb-side">
            <button type="button" class="aglm-back-btn" onclick="closeAgeLearnMore()" aria-label="Back">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M11 5l-7 7 7 7"/></svg>
            </button>
        </div>
        <div class="aglm-topbar-center">
            <div class="aglm-topbar-title">About 18+ Pods</div>
            <div class="aglm-topbar-sub">Guidelines &amp; safety information</div>
        </div>
        <div class="aglm-tb-side right"></div>
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
            <span>By entering an 18+ NSFW Pod, you confirm you are at least 18 years old and agree to follow both the pod's rules and Dezzme's Community Guidelines at all times.</span>
        </div>

    </div>

    <div class="aglm-actionbar">
        <button type="button" class="aglm-done-btn" onclick="closeAgeLearnMore()">
            <i class="ri-check-line"></i> Got it
        </button>
    </div>

  </div>
</div>

<script>
/* ═══════════════════════════════════════════
   AGE GATE JS
═══════════════════════════════════════════ */
/* Continue only hides the gate for THIS page view. Nothing is remembered, so a refresh or coming back
   to the pod shows the gate again until you actually join (the server stops rendering it once you're a member). */
function confirmAge() {
    var overlay = document.getElementById('ageGateOverlay');
    if (overlay) {
        overlay.classList.remove('open');
        document.body.style.overflow = '';
    }
}

/* Lock page scroll behind the gate while it is showing. */
(function () {
    var overlay = document.getElementById('ageGateOverlay');
    if (overlay && overlay.classList.contains('open')) document.body.style.overflow = 'hidden';
})();

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
        /* keep the page locked while the gate is still up */
        var g = document.getElementById('ageGateOverlay');
        document.body.style.overflow = (g && g.classList.contains('open')) ? 'hidden' : '';
    }, 210);
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && _aglmOpen) closeAgeLearnMore();
});
</script>
<?php endif; ?>

<!-- ═════════ FEED DATA ═════════ -->
<script>
var peuraGroupFeed = <?php echo json_encode($gp_feed_out, $gp_json_flags); ?>;
window.GP_ALL = peuraGroupFeed.slice();
var GP_SUGG_PODS = <?php echo json_encode($gp_sugg_out, $gp_json_flags); ?>;
var GP_TOP = <?php echo json_encode($gp_top_out, $gp_json_flags); ?>;
var GP_FILES = <?php echo json_encode($gp_files_out, $gp_json_flags); ?>;
</script>

<!-- ═════════ EXTRAS: pinned posts · weekly challenge · catch-me-up (sheets + script) ═════════ -->
<?php if ($gx_can_see && $gx_staff): ?>
<div class="gx-ov" id="gxChModal" onclick="if(event.target===this)gxClose('gxChModal')">
  <div class="gx-sheet" role="dialog" aria-modal="true" aria-label="Weekly challenge">
    <div class="gx-sh-t">Weekly challenge</div>
    <div class="gx-sh-b">
      <p class="gx-note">Members see it as a banner above the feed with a countdown. Saving starts a fresh countdown and replaces any challenge that is running.</p>
      <label class="gx-lbl" for="gxChTitle">Title</label>
      <input class="gx-in" id="gxChTitle" maxlength="60" placeholder="Caption this!" autocomplete="off" value="<?php echo htmlspecialchars($gx_challenge['title'] ?? ''); ?>">
      <label class="gx-lbl" for="gxChDesc">What to post (optional)</label>
      <textarea class="gx-in" id="gxChDesc" rows="3" maxlength="240" placeholder="Post your funniest edit of this week's template"><?php echo htmlspecialchars($gx_challenge['description'] ?? ''); ?></textarea>
      <label class="gx-lbl" for="gxChTag">Hashtag to count entries (optional)</label>
      <input class="gx-in" id="gxChTag" maxlength="31" placeholder="#caption" autocomplete="off" autocapitalize="off" spellcheck="false" value="<?php echo !empty($gx_challenge['hashtag']) ? '#' . htmlspecialchars($gx_challenge['hashtag']) : ''; ?>">
      <label class="gx-lbl" for="gxChDays">Runs for</label>
      <select class="gx-in" id="gxChDays"><option value="1">1 day</option><option value="3">3 days</option><option value="7" selected>7 days (weekly)</option><option value="14">14 days</option><option value="30">30 days</option></select>
      <div class="gx-sh-act">
        <?php if ($gx_challenge): ?><button type="button" class="gx-btn ghost" onclick="gxChEnd()">End challenge</button><?php endif; ?>
        <button type="button" class="gx-btn" id="gxChSave" onclick="gxChSave()">Start challenge</button>
      </div>
    </div>
  </div>
</div>
<div class="gx-ov" id="gxPinModal" onclick="if(event.target===this)gxClose('gxPinModal')">
  <div class="gx-sheet" role="dialog" aria-modal="true" aria-label="Pin a post">
    <div class="gx-sh-t">Pin a post</div>
    <div class="gx-sh-b"><p class="gx-note">Pinned posts show above the feed for everyone. You can pin up to 3.</p><input type="search" class="gx-in gx-pick-q" id="gxPickQ" placeholder="Search posts to pin (caption, name, #tag)" autocomplete="off" aria-label="Search posts to pin"><div class="gx-pick" id="gxPickGrid"></div></div>
  </div>
</div>
<?php endif; ?>
<script>
window.GX_PINS = <?php echo json_encode($gx_pins, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
window.GX_STAFF = <?php echo ($gx_staff && $gx_can_see) ? 'true' : 'false'; ?>;
(function(){
    var GID = <?php echo (int)$group_id; ?>;
    function $(id){ return document.getElementById(id); }
    function toast(m){ if(window.gpToast) window.gpToast(m); }
    function post(data){
        var fd = new FormData(); for(var k in data) fd.append(k, data[k]);
        return fetch('group.php?id=' + GID, { method:'POST', body:fd, credentials:'same-origin', headers:{ 'X-CSRF-Token': CSRF_TOKEN } }).then(function(r){ return r.json(); });
    }
    window.gxClose = function(id){ var e = $(id); if(e) e.classList.remove('open'); };
    document.addEventListener('keydown', function(e){ if(e.key === 'Escape'){ gxClose('gxChModal'); gxClose('gxPinModal'); } });

    /* ── a picture / banner upload that was refused ── */
    (function(){
        var m = /[?&]pic_err=([a-z]+)/.exec(location.search); if(!m) return;
        var t = { size:'That picture is too big (max 8 MB)', type:'Use a JPG, PNG, GIF or WEBP picture', upload:'The picture did not upload — try again' }[m[1]] || 'The picture did not upload';
        window.addEventListener('load', function(){ setTimeout(function(){ toast(t); }, 500); });
        try{ var u = new URL(location.href); u.searchParams.delete('pic_err'); history.replaceState(null, '', u.pathname + u.search + u.hash); }catch(e){}
    })();

    /* ── catch me up ── */
    var cb = $('gxCatch');
    if(cb){
        var ck = 'gxCatch_' + GID + '_' + cb.getAttribute('data-key'), gone = false;
        try{ gone = sessionStorage.getItem(ck) === '1'; }catch(e){}
        if(!gone) cb.hidden = false;
        window.gxCatchX = function(){ cb.hidden = true; try{ sessionStorage.setItem(ck, '1'); }catch(e){} };
        window.gxCatchGo = function(){ var f = $('groupFeed'); if(f) f.scrollIntoView({ behavior:'smooth', block:'start' }); gxCatchX(); };
    }

    /* ── weekly challenge ── */
    var ch = $('gxChal');
    if(ch){
        var left = parseInt(ch.getAttribute('data-left'), 10) || 0, t0 = Date.now(), lbl = $('gxChLeft');
        function tick(){
            var s = Math.max(0, left - Math.floor((Date.now() - t0) / 1000));
            var d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60);
            lbl.textContent = s <= 0 ? 'Ended' : (d > 0 ? d + 'd ' + h + 'h left' : (h > 0 ? h + 'h ' + m + 'm left' : Math.max(1, m) + 'm left'));
            if(s <= 0) ch.hidden = true;
        }
        tick(); setInterval(tick, 30000);
    }
    window.gxChJoin = function(){
        var tg = $('gxChTag') ? $('gxChTag').value : '';
        if(!tg){ var el = document.querySelector('#gxChal .gx-meta a'); if(el){ var mm = /search=%23([^&]+)/.exec(el.getAttribute('href') || ''); if(mm) tg = '#' + decodeURIComponent(mm[1]); } }
        if(tg && navigator.clipboard){ try{ navigator.clipboard.writeText(tg + ' '); dzHp('success'); toast(tg + ' copied — add it to your post'); }catch(e){} }
        if(typeof openPostModal === 'function') openPostModal();
    };
    window.gxChOpen = function(){ var m = $('gxChModal'); if(m) m.classList.add('open'); };
    window.gxChSave = function(){
        var b = $('gxChSave'); b.disabled = true;
        dzHp('medium');
        post({ action:'gx_challenge_save', title:$('gxChTitle').value, description:$('gxChDesc').value, hashtag:$('gxChTag').value, days:$('gxChDays').value }).then(function(d){
            if(d.status !== 'ok'){ dzHp('error'); toast(d.error || 'Could not save'); b.disabled = false; return; }
            location.reload();
        }).catch(function(){ toast('Something went wrong'); b.disabled = false; });
    };
    window.gxChEnd = function(){
        post({ action:'gx_challenge_end' }).then(function(d){ if(d.status === 'ok') location.reload(); else toast(d.error || 'Could not end it'); });
    };

    /* ── pinned posts ── */
    function find(id){ return (window.GP_ALL || []).filter(function(x){ return String(x.id) === String(id); })[0]; }
    function thumb(p){
        var m = (p.media && p.media[0]) || { file:p.file, type:p.type };
        if(m.type === 'video'){ var v = document.createElement('video'); v.className = 'gx-pin-th'; v.src = m.file + '#t=0.1'; v.muted = true; v.preload = 'metadata'; v.playsInline = true; return v; }
        var i = document.createElement('img'); i.className = 'gx-pin-th'; i.src = m.file; i.alt = ''; i.loading = 'lazy'; i.onerror = function(){ i.onerror = null; i.src = 'uploads/Text.jpg'; }; return i;
    }
    function renderPins(){
        var box = $('gxPins'); if(!box) return;
        var items = (window.GX_PINS || []).map(find).filter(Boolean);
        if(!items.length && !window.GX_STAFF){ box.hidden = true; return; }
        box.hidden = false; box.textContent = '';
        var head = document.createElement('div'); head.className = 'gx-pins-head';
        head.innerHTML = '<i class="ri-pushpin-2-fill"></i><span>Pinned</span>';
        if(window.GX_STAFF && (window.GX_PINS || []).length < 3){
            var add = document.createElement('button'); add.type = 'button'; add.className = 'gx-btn ghost'; add.textContent = 'Pin a post'; add.onclick = openPicker; head.appendChild(add);
        }
        box.appendChild(head);
        if(!items.length){ var e = document.createElement('div'); e.className = 'gx-empty'; e.textContent = 'Nothing pinned yet — pin up to 3 posts everyone should see first.'; box.appendChild(e); return; }
        var row = document.createElement('div'); row.className = 'gx-pin-row';
        items.forEach(function(p){
            var c = document.createElement('div'); c.className = 'gx-pin'; c.setAttribute('role', 'button'); c.tabIndex = 0;
            c.appendChild(thumb(p));
            var tx = document.createElement('span'); tx.className = 'gx-pin-tx';
            var b = document.createElement('b'); b.textContent = '@' + (p.username || '').replace(/&amp;/g, '&');
            var s = document.createElement('span'); s.textContent = (p.caption || '').trim() || 'Open post';
            tx.appendChild(b); tx.appendChild(s); c.appendChild(tx);
            var bd = document.createElement('span'); bd.className = 'gx-pin-badge'; bd.innerHTML = '<i class="ri-pushpin-2-fill"></i>Pinned'; c.appendChild(bd);
            if(window.GX_STAFF){
                var x = document.createElement('button'); x.type = 'button'; x.className = 'gx-pin-un'; x.setAttribute('aria-label', 'Unpin'); x.innerHTML = '&times;';
                x.onclick = function(ev){ ev.stopPropagation(); unpin(p.id); }; c.appendChild(x);
            }
            var open = function(){ var ids = items.map(function(x){ return x.id; }); if(window.gpPinOpen) window.gpPinOpen(p.id, ids); else if(window.gpGridOpen) window.gpGridOpen(p.id); };
            c.onclick = open; c.onkeydown = function(ev){ if(ev.key === 'Enter' || ev.key === ' '){ ev.preventDefault(); open(); } };
            row.appendChild(c);
        });
        box.appendChild(row);
    }
    function unpin(id){
        post({ action:'gx_unpin', meme_id:id }).then(function(d){
            if(d.status !== 'ok'){ toast(d.error || 'Could not unpin'); return; }
            window.GX_PINS = window.GX_PINS.filter(function(x){ return String(x) !== String(id); }); renderPins(); toast('Unpinned');
        }).catch(function(){ toast('Something went wrong'); });
    }
    function pin(id){
        post({ action:'gx_pin', meme_id:id }).then(function(d){
            if(d.status !== 'ok'){ toast(d.error || 'Could not pin'); return; }
            window.GX_PINS = [id].concat(window.GX_PINS.filter(function(x){ return String(x) !== String(id); })).slice(0, 3);
            gxClose('gxPinModal'); renderPins(); toast('Pinned');
        }).catch(function(){ toast('Something went wrong'); });
    }
    function fillPicker(){
        var g = $('gxPickGrid'); if(!g) return; g.textContent = '';
        var q = (($('gxPickQ') || {}).value || '').trim().toLowerCase();
        var pinned = (window.GX_PINS || []).map(String);
        var list = (window.GP_ALL || []).filter(function(p){
            if(pinned.indexOf(String(p.id)) >= 0) return false;
            if(!q) return true;
            return ((p.caption || '') + ' ' + (p.username || '') + ' ' + (p.hashtags || p.tags || '')).toLowerCase().indexOf(q) !== -1;
        }).slice(0, q ? 120 : 60);
        if(!list.length){ var e = document.createElement('div'); e.className = 'gx-empty'; e.textContent = q ? 'No posts match your search' : 'No posts to pin yet'; g.appendChild(e); return; }
        list.forEach(function(p){
            var b = document.createElement('button'); b.type = 'button'; b.className = 'gx-pick-i'; b.title = (p.caption || '').slice(0, 80);
            b.appendChild(thumb(p)); b.onclick = function(){ pin(p.id); }; g.appendChild(b);
        });
    }
    function openPicker(){
        var q = $('gxPickQ'); if(q){ q.value = ''; if(!q.dataset.b){ q.dataset.b = '1'; q.addEventListener('input', fillPicker); } }
        fillPicker();
        $('gxPinModal').classList.add('open');
    }
    if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', renderPins); else renderPins();
})();
</script>

<!-- ═════════════════════════════════════════════════════════
     KEEP ALL YOUR EXISTING MODAL MARKUP + SCRIPTS BELOW THIS LINE
     (gf sheet, gf post-report modal, gfModal + its JS, share modal,
      save-to-collection sheet, engagement modal, pod info modal,
      group options, group search, post modal, invite, You modal,
      group pic modal, report pod modal, break overlay, etc.)

      ⚠ DO NOT paste these old pieces again — they're replaced here:
        • old sort modal (markup + CSS + JS)
        • old .gf-feed CSS and <div class="gf-feed" id="groupFeed">
        • old "var peuraGroupFeed = []" + the old PHP foreach that pushed posts
        • the old feed-renderer IIFE at the very bottom (var FEED = peuraGroupFeed …)
        • old gfHidePost / gfUndoHidePost / gfLoadHiddenIds (this file now defines
          them itself, using the same localStorage key, so old hidden posts stay hidden)
        • old gfdOpenDiscuss (this file redefines it so it can't be overridden)
     ═════════════════════════════════════════════════════════ -->
<?php /* all the pod's modals / sheets / viewers live in their own file — included here at the page's top level so it sees every variable of this page exactly as if it were pasted here */
include __DIR__ . '/group_modals.php'; ?>
<?php if(is_file(__DIR__ . '/tv_nav.php')) include __DIR__ . '/tv_nav.php'; ?>


<script>
/* top bar: transparent on banner; page-colored after; title after the name scrolls away;
   Join pill appears once the page's own Join button has scrolled under the bar */
(function(){
  var bar=document.getElementById('gpTopbar'), banner=document.getElementById('gpBanner'),
      nameEl=document.querySelector('.gp-name'), nav=document.getElementById('gpFilterBar'), sent=document.getElementById('gpNavSentinel'),
      joinBtn=document.getElementById('gpMainJoin'), joinPill=document.getElementById('gpJoinPill');
  if(!bar||!banner) return;
  function on(){
    var h=bar.offsetHeight;
    document.documentElement.style.setProperty('--topbar-h',h+'px');
    bar.classList.toggle('scrolled', window.scrollY > banner.offsetHeight - h);
    if(nameEl) bar.classList.toggle('show-title', nameEl.getBoundingClientRect().bottom <= h);
    if(joinBtn && joinPill){
      var gone = joinBtn.getBoundingClientRect().bottom <= h;
      joinPill.classList.toggle('show', gone);
      bar.classList.toggle('has-join', gone);
    }
  }
  window.addEventListener('scroll',on,{passive:true}); window.addEventListener('resize',on); on();
  if(sent && nav && 'IntersectionObserver' in window){
    var io;
    function watch(){
      if(io) io.disconnect();
      var h=Math.round(bar.offsetHeight);
      io=new IntersectionObserver(function(es){
        es.forEach(function(e){
          var stuck=!e.isIntersecting && e.boundingClientRect.top < h;
          bar.classList.toggle('tabs-stuck',stuck); nav.classList.toggle('is-stuck',stuck);
        });
      },{threshold:0,rootMargin:'-'+h+'px 0px 0px 0px'});
      io.observe(sent);
    }
    watch(); window.addEventListener('resize',watch);
  }
})();

function gpToggleDesc(){
  dzHp('light');
  var s=document.getElementById('gpDescShort'), f=document.getElementById('gpDescFull'), b=document.getElementById('gpDescBtn');
  if(!s||!f||!b) return;
  var open = !f.classList.contains('gp-off');
  f.classList.toggle('gp-off', open);
  s.classList.toggle('gp-off', !open);
  b.textContent   = open ? 'more' : 'close';
}

(function(){
  var d=document.querySelector('.gp-desc'); if(!d) return;
  d.addEventListener('click',function(e){
    var f=document.getElementById('gpDescFull'), b=document.getElementById('gpDescBtn');
    if(!f||!b||f.classList.contains('gp-off')) return;          /* only when it is open */
    if(e.target.closest('a')||e.target===b) return;       /* links and the close word keep their own job */
    gpToggleDesc();
  });
})();

/* ═════════ RULES ⇄ FEED — the nav-bar button swaps what shows where Pod Highlights start.
   "Rules" → rules grid (universal + owner's rules, scrollable). The button then says "Feed";
   pressing it brings the normal post feed back and the button says "Rules" again. ═════════ */
window.gpToggleRules = function(){
  dzHp('selection');
  var page = document.getElementById('gpPage'), btn = document.getElementById('gpRulesBtn');
  if(!page || !btn) return;
  var on = page.classList.toggle('rules-mode');
  btn.textContent = on ? 'Feed' : 'Rules';
  btn.setAttribute('aria-pressed', on ? 'true' : 'false');
  if(on){
    document.querySelectorAll('#groupFeed video').forEach(function(v){ try{ v.pause(); }catch(e){} });
  }
  /* if you're scrolled deep into the feed, jump back up to just under the nav bar so the new content is in view */
  var nw = document.getElementById('gpNavWrap'), bar = document.getElementById('gpTopbar');
  if(nw){
    var limit = nw.getBoundingClientRect().bottom + window.scrollY - (bar ? bar.offsetHeight : 0);
    if(window.scrollY > limit) window.scrollTo(0, Math.max(0, limit));
  }
  setTimeout(function(){ window.dispatchEvent(new Event('resize')); window.dispatchEvent(new Event('scroll')); }, 30);
};

/* ═════════ HOVER ARROWS — nav bar + filter bar (normal view). Shared helper. ═════════ */
(function(){
  function setup(scrollEl, leftBtn, rightBtn){
    if(!scrollEl) return null;
    function update(){
      var max = scrollEl.scrollWidth - scrollEl.clientWidth;
      if(leftBtn)  leftBtn.classList.toggle('hidden',  scrollEl.scrollLeft <= 4);
      if(rightBtn) rightBtn.classList.toggle('hidden', max <= 4 || scrollEl.scrollLeft >= max - 4);
    }
    scrollEl.addEventListener('scroll', update, {passive:true});
    window.addEventListener('resize', update);
    window.addEventListener('load', update);
    update(); setTimeout(update, 300); setTimeout(update, 1000);
    return update;
  }

  var navEl = document.getElementById('gpNav');
  var navWrap = document.getElementById('gpNavWrap');
  var navL = navWrap ? navWrap.querySelector('.gp-nav-arrow-l') : null;
  var navR = navWrap ? navWrap.querySelector('.gp-nav-arrow-r') : null;
  setup(navEl, navL, navR);

  var fEl = document.getElementById('gpFilterScrollEl');
  var fBar = document.getElementById('gpFilterBar');
  var fL = fBar ? fBar.querySelector('.gp-f-arrow-l') : null;
  var fR = fBar ? fBar.querySelector('.gp-f-arrow-r') : null;
  var fUpdate = setup(fEl, fL, fR);
  window.gpFilterArrowsUpdate = fUpdate || function(){};

  function go(el, dir){
    if(!el) return;
    el.scrollBy({ left: dir * Math.max(120, el.clientWidth * 0.75), behavior: 'smooth' });
  }
  window.gpNavScroll    = function(dir){ go(navEl, dir); };
  window.gpFilterScroll = function(dir){ go(fEl, dir); };
})();

/* ═════════ RIGHT-COLUMN CARD BARS — collapse / expand (Pod info, Admins) ═════════ */
window.gsToggle = function(btn){
  dzHp('light');
  var card = btn && btn.closest ? btn.closest('.gs-card') : null;
  if(!card) return;
  var collapsed = card.classList.toggle('collapsed');
  btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
  try{ localStorage.setItem('gp_gs_' + gfGroupId + '_' + (card.getAttribute('data-k') || ''), collapsed ? '1' : '0'); }catch(e){}
};
(function(){
  document.querySelectorAll('.gs-card').forEach(function(card){
    try{
      if(localStorage.getItem('gp_gs_' + gfGroupId + '_' + (card.getAttribute('data-k') || '')) === '1'){
        card.classList.add('collapsed');
        var b = card.querySelector('.gs-bar'); if(b) b.setAttribute('aria-expanded','false');
      }
    }catch(e){}
  });
})();

function showDiscLockToast(msg){
  /* private pod + not joined: no "Join this pod to access…" message and no lock icon at all */
  if(typeof GP_PRIVATE_LOCKED!=='undefined' && GP_PRIVATE_LOCKED && !GP_IS_MEMBER &&
     (!msg || /^\s*join this pod/i.test(String(msg)))) return;
  if(!msg) return;                       /* never show a blank / default "access this feature" message */
  var t=document.getElementById('discLockToast');
  if(!t){
    t=document.createElement('div'); t.id='discLockToast';
    t.innerHTML='<i class="ri-lock-line"></i> <span id="discLockMsg"></span>';
    document.body.appendChild(t);
  }
  var m=document.getElementById('discLockMsg'); if(m) m.textContent=msg;
  t.classList.add('show'); clearTimeout(t._tid);
  t._tid=setTimeout(function(){ t.classList.remove('show'); },2800);
}

/* join-request modal */
function gpJqOpen(){ var m=document.getElementById('gpJqModal'); if(!m) return; m.classList.add('open'); document.body.style.overflow='hidden'; }
function gpJqClose(){ var m=document.getElementById('gpJqModal'); if(!m) return; m.classList.remove('open'); document.body.style.overflow=''; }
function gpJqCheck(){
  var m=document.getElementById('gpJqModal'); if(!m) return true;
  var bad=false;
  m.querySelectorAll('textarea').forEach(function(t){
    var miss = t.getAttribute('data-req')==='1' && !t.value.trim();
    t.classList.toggle('bad', miss); if(miss) bad=true;
  });
  if(bad){
    var e=document.getElementById('gpJqErr');
    if(e){ e.classList.remove('gp-off'); e.innerHTML='<i class="ri-error-warning-line"></i> Please answer the required questions.'; }
    return false;
  }
  return true;
}
document.addEventListener('keydown',function(e){ if(e.key==='Escape') gpJqClose(); });
(function(){ var e=document.getElementById('gpJqErr'); if(e && !e.classList.contains('gp-off') && e.textContent.trim()) document.addEventListener('DOMContentLoaded',gpJqOpen); })();

function gpOpen(id){var e=document.getElementById(id); if(e){e.classList.add('open');document.body.style.overflow='hidden';}}
function gpClose(id){var e=document.getElementById(id); if(e){e.classList.remove('open');document.body.style.overflow='';}}

function gpDismissPause(){
  var c=document.getElementById('gpPauseCard'); if(c) c.classList.add('gp-off');
  try{ if(GP_PAUSE_KEY) sessionStorage.setItem(GP_PAUSE_KEY,'1'); }catch(e){}
}
(function(){
  try{
    var prefix='gp_pause_dismissed_'+gfGroupId;
    for(var i=sessionStorage.length-1;i>=0;i--){
      var k=sessionStorage.key(i);
      if(k && k.indexOf(prefix)===0 && k!==GP_PAUSE_KEY) sessionStorage.removeItem(k);
    }
    if(GP_PAUSE_KEY && sessionStorage.getItem(GP_PAUSE_KEY)){
      var c=document.getElementById('gpPauseCard'); if(c) c.classList.add('gp-off');
    }
  }catch(e){}
})();
document.addEventListener('keydown',function(e){
  if(e.key==='Escape'){gpClose('gpManageOv');gpClose('gpLearnOv');gpClose('gpFsOv');}
});
</script>

<?php if ($gp_hl_count > 0): ?>
<script>
/* POD HIGHLIGHTS — comet ring on press, then openGroupHighlightsModal(id) (you build that modal). */
function ghToggle(){
    dzHp('light');
    var s = document.getElementById('ghSection'), b = document.getElementById('ghToggle');
    if(!s) return;
    var hidden = s.classList.toggle('collapsed');
    if(b) b.setAttribute('aria-expanded', hidden ? 'false' : 'true');
    try{ localStorage.setItem('gp_hl_hidden_' + gfGroupId, hidden ? '1' : '0'); }catch(e){}
}

(function(){
    var SPIN_MS = 1400;
    var busy    = false;

    function bind(){
        var strip = document.getElementById('ghStrip');
        if(!strip) return;

        function tileOf(t){ return t && t.closest ? t.closest('.gh-tile') : null; }

        strip.addEventListener('pointerdown', function(e){
            var tile = tileOf(e.target);
            if(tile && !busy) tile.classList.add('spin');
        }, { passive:true });
        function release(){
            if(busy) return;
            strip.querySelectorAll('.gh-tile.spin').forEach(function(t){ t.classList.remove('spin'); });
        }
        strip.addEventListener('pointercancel', release, { passive:true });
        strip.addEventListener('pointerleave',  release, { passive:true });
        strip.addEventListener('scroll',        release, { passive:true });

        var wrap = document.getElementById('ghWrap');
        var btnL = document.getElementById('ghLeft');
        var btnR = document.getElementById('ghRight');
        var BATCH   = parseInt(strip.getAttribute('data-batch'), 10) || 8;
        var loading = false;

        function held(){ return strip.querySelectorAll('.gh-tile.gh-hold'); }
        function hydrate(tile){
            tile.querySelectorAll('[data-src]').forEach(function(m){
                m.setAttribute('src', m.getAttribute('data-src'));
                m.removeAttribute('data-src');
                if(m.tagName === 'VIDEO') m.setAttribute('preload', 'metadata');
            });
        }
        function skeletonOn(n){
            for(var i = 0; i < n; i++){
                var k = document.createElement('span');
                k.className = 'gh-skel';
                k.innerHTML = '<span class="sk sq"></span><span class="sk nm"></span>';
                strip.appendChild(k);
            }
        }
        function skeletonOff(){
            strip.querySelectorAll('.gh-skel').forEach(function(k){ k.remove(); });
        }
        function loadMore(){
            if(loading) return;
            var h = held();
            if(!h.length) return;
            loading = true;
            skeletonOn(Math.min(BATCH, h.length));
            setTimeout(function(){
                skeletonOff();
                Array.prototype.slice.call(held(), 0, BATCH).forEach(function(t){
                    t.classList.remove('gh-hold');
                    t.classList.add('gh-in');
                    hydrate(t);
                });
                loading = false;
                arrowsUpdate();
                checkMore();
            }, 160);
        }
        function checkMore(){
            if(loading || !held().length) return;
            var remaining = strip.scrollWidth - strip.clientWidth - strip.scrollLeft;
            if(remaining < strip.clientWidth * 1.5) loadMore();
        }
        /* same show/hide logic as the nav bar + suggested pods arrows: they get the "hidden" class at the ends */
        function arrowsUpdate(){
            var max = strip.scrollWidth - strip.clientWidth;
            var more = loading || held().length > 0;
            if(btnL) btnL.classList.toggle('hidden', strip.scrollLeft <= 4);
            if(btnR) btnR.classList.toggle('hidden', !more && (max <= 4 || strip.scrollLeft >= max - 4));
        }
        function scrollByDir(dir){
            strip.scrollBy({ left: dir * Math.max(120, strip.clientWidth * 0.75), behavior:'smooth' });
        }
        if(btnL) btnL.addEventListener('click', function(){ scrollByDir(-1); });
        if(btnR) btnR.addEventListener('click', function(){ scrollByDir(1); checkMore(); });
        strip.addEventListener('scroll', function(){ arrowsUpdate(); checkMore(); }, { passive:true });
        function refresh(){ arrowsUpdate(); checkMore(); }
        window.addEventListener('resize', refresh);
        window.addEventListener('load', refresh);
        refresh();
        setTimeout(refresh, 300);
        setTimeout(refresh, 1000);
    }

    window.gpOpenHl = function(tile, id){
        if(busy || !tile) return;
        dzHp('light');
        busy = true;
        tile.classList.add('spin');

        setTimeout(function(){
            if(typeof window.openGroupHighlightsModal === 'function'){
                try { window.openGroupHighlightsModal(id); } catch(err){ console.error(err); }
            }
            setTimeout(function(){
                tile.classList.remove('spin');
                busy = false;
            }, 250);
        }, SPIN_MS);
    };

    if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bind);
    else bind();
})();
</script>
<?php endif; ?>

<!-- ══ SORT MODAL ══ -->
<?php
$gps_cur = $sort ?? 'newest';
$gps_filters = [
  'newest'     => ['ri-time-line',      'Newest',            'Most recently posted first',   'time'],
  'trending'   => ['ri-fire-line',      'Trending',          'Rising fast — likes per hour', 'engage'],
  'liked'      => ['ri-heart-line',     'Most liked',        'Highest like count first',     'engage'],
  'reposted'   => ['ri-repeat-line',    'Most reposted',     'Highest repost count first',   'engage'],
  'favorited'  => ['ri-bookmark-line',  'Most saved',        'Highest bookmark count first', 'engage'],
  'downloaded' => ['ri-download-line',  'Most downloaded',   'Highest download count first', 'engage'],
  'videos'     => ['ri-video-line',     'Videos only',       'Show video posts first',       'type'],
  'images'     => ['ri-image-line',     'Images only',       'Show image posts first',       'type'],
  'following'  => ['ri-user-heart-line','People you Track', 'Posts from members you track', 'social'],
];
$gps_group_labels = ['time'=>'Recency','engage'=>'Engagement','type'=>'Content type','social'=>'Social'];
$gps_seen = [];
?>
<div id="gpSortSheet" role="dialog" aria-modal="true" aria-label="Sort options">
  <div class="gps-inner">

    <!-- TOPBAR: X left · title centered (same as the post modal) -->
    <div class="gps-topbar">
      <div class="gps-tb-side">
        <button type="button" class="gps-x" onclick="gpCloseSort()" aria-label="Close">
          <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
      </div>
      <div class="gps-topbar-center"><div class="gps-title">Sort posts</div></div>
      <div class="gps-tb-side right"></div>
    </div>

    <div class="gps-body">
      <div class="gps-note">Choose how the feed is ordered</div>
      <?php foreach ($gps_filters as $sv => $si):
          $sg = $si[3];
          if (!in_array($sg, $gps_seen)):
              $gps_seen[] = $sg; ?>
              <div class="gps-group"><?php echo $gps_group_labels[$sg]; ?></div>
          <?php endif; ?>
          <div class="gps-option<?php echo ($gps_cur === $sv) ? ' selected' : ''; ?>" data-sort="<?php echo $sv; ?>" onclick="gpApplySort('<?php echo $sv; ?>')">
            <div class="gps-icon"><i class="<?php echo $si[0]; ?>"></i></div>
            <div class="gps-text">
              <div class="gps-name"><?php echo $si[1]; ?></div>
              <div class="gps-desc"><?php echo $si[2]; ?></div>
            </div>
            <div class="gps-check"><?php echo ($gps_cur === $sv) ? '✓' : ''; ?></div>
          </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script>
/* ═══════════════════════════════════════════════════════════
   SORT + HASHTAG FILTER — works on the DATA (GP_ALL), then asks the
   feed to rebuild itself (gpRebuildFeed).
   ═══════════════════════════════════════════════════════════ */
window._gfActiveTag   = null;
window._gfCurrentSort = 'newest';

(function () {
  var isOpen = false;
  window.gpOpenSort = function () {
    if (isOpen) return; isOpen = true;
    dzHp('light');
    var sh = document.getElementById('gpSortSheet');
    if (!sh) return;
    sh.style.animation = '';
    sh.classList.add('open');
    var b = sh.querySelector('.gps-body'); if (b) b.scrollTop = 0;
    document.body.style.overflow = 'hidden';
  };
  window.gpCloseSort = function () {
    if (!isOpen) return; isOpen = false;
    var sh = document.getElementById('gpSortSheet');
    if (!sh) return;
    sh.style.animation = 'gpsOut .22s ease forwards';
    setTimeout(function () { sh.classList.remove('open'); sh.style.animation = ''; document.body.style.overflow = ''; }, 210);
  };
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') gpCloseSort(); });
})();

function gpFilterTag(tag, btn) {
    dzHp('selection');
    var wasActive = btn.classList.contains('active-tag');
    document.querySelectorAll('.gp-tag-pill').forEach(function (p) { p.classList.remove('active-tag'); });
    if (wasActive) { window._gfActiveTag = null; }
    else { btn.classList.add('active-tag'); window._gfActiveTag = tag.toLowerCase(); }
    gpRenderSort(window._gfCurrentSort);
}
window.filterFeedByHashtag = gpFilterTag;

function _gfHoursSince(t) {
    var d = new Date((t || '').replace(' ', 'T'));
    if (isNaN(d.getTime())) return 9999;
    var hrs = (Date.now() - d.getTime()) / 3600000;
    return hrs > 0.5 ? hrs : 0.5;
}

function gpRenderSort(val) {
    if (val) window._gfCurrentSort = val;
    var activeVal = window._gfCurrentSort;
    if (!window.GP_ALL) return;

    var items = window.GP_ALL.slice();

    if (window._gfActiveTag) {
        var needle = '#' + window._gfActiveTag;
        items = items.filter(function (p) { return (p.caption || '').toLowerCase().indexOf(needle) !== -1; });
    }

    switch (activeVal) {
        case 'newest':
            items.sort(function (a, b) {
                return new Date((b.time || '').replace(' ', 'T')).getTime() - new Date((a.time || '').replace(' ', 'T')).getTime();
            }); break;
        case 'liked':      items.sort(function (a, b) { return (b.likeCount   || 0) - (a.likeCount   || 0); }); break;
        case 'reposted':   items.sort(function (a, b) { return (b.repostCount || 0) - (a.repostCount || 0); }); break;
        case 'favorited':  items.sort(function (a, b) { return (b.favCount    || 0) - (a.favCount    || 0); }); break;
        case 'downloaded': items.sort(function (a, b) { return (b.downloadCount || 0) - (a.downloadCount || 0); }); break;
        case 'videos':     items = items.filter(function (p) { return p.type === 'video'; }); break;
        case 'images':     items = items.filter(function (p) { return p.type === 'image'; }); break;
        case 'following':  items = items.filter(function (p) { return !!p.following; }); break;
        case 'trending':
            items.sort(function (a, b) {
                return ((b.likeCount || 0) / _gfHoursSince(b.time)) - ((a.likeCount || 0) / _gfHoursSince(a.time));
            }); break;
    }

    if (typeof window.gpRebuildFeed === 'function') window.gpRebuildFeed(items);
}
window.renderGroupFeedSort = gpRenderSort;

function gpApplySort(val) {
    dzHp('selection');
    var labels = {
        newest:'Newest', liked:'Most liked', reposted:'Most reposted', favorited:'Most saved', downloaded:'Most downloaded',
        videos:'Videos only', images:'Images only', following:'People you Track', trending:'Trending'
    };
    var lab = document.getElementById('gpSortLabel');
    if (lab) lab.textContent = labels[val] || val;

    document.querySelectorAll('#gpSortSheet .gps-option').forEach(function (el) {
        var on = el.getAttribute('data-sort') === val;
        el.classList.toggle('selected', on);
        var chk = el.querySelector('.gps-check');
        if (chk) chk.textContent = on ? '✓' : '';
    });

    setTimeout(function () {
        gpCloseSort();
        setTimeout(function () { gpRenderSort(val); }, 220);
    }, 130);
}
</script>

<!-- ═══════════════════════════════════════════════════════════════
     GROUP FEED ENGINE — batches of 4 + infinite scroll, skeleton first,
     dynamic aspect ratio, long-post overlay header, no-sway carousel,
     autoplay on scroll, attached track audio, social proof.
     Files are merged in by time (Newest sort, no tag filter, no search).
     Order in the feed: item 1 → TOP CONTRIBUTOR (blue lines only) →
     items 2-3 → SUGGESTED PODS (only when there are matches) → the rest.
     ═══════════════════════════════════════════════════════════════ -->
<script>
(function(){
    var FEED = [], BATCH = 4, SUGG_AFTER = 3, TOP_AFTER = 1;
    var listEl = document.getElementById('groupFeed');
    var rendered = 0, loadingBatch = false, sentinelObs = null, postObs = null, buildId = 0, suggDone = false, topDone = false;
    var visibility = {}, switchers = {}, curAudioPost = null, trackMuted = true, originMemeId = null;
    var pendingAudio = null;   /* {id,time}: the gf modal's track position, applied when the feed starts that post's track again */
    var suggRemoved = {};

    /* ───────── helpers ───────── */
var GF_HEART_SVG = '<svg viewBox="0 0 24 24" aria-hidden="true"><path class="sp-ring" d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z" stroke-width="3.67" stroke-linejoin="round"/><path class="sp-fill" d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>';
    function esc(s){
        if(s===null||s===undefined) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }
    function fmtNum(n){
        n = parseInt(n)||0;
        if(n>=1e9) return (n/1e9).toFixed(1).replace(/\.0$/,'')+'B';
        if(n>=1e6) return (n/1e6).toFixed(1).replace(/\.0$/,'')+'M';
        if(n>=1e3) return (n/1e3).toFixed(1).replace(/\.0$/,'')+'K';
        return n;
    }
    function linkify(raw){
        return esc(raw||'')
            .replace(/#([a-zA-Z0-9_]+)/g,function(m,tag){
                return '<a href="search.php?focus=1&mq='+encodeURIComponent('#'+tag)+'" onclick="event.stopPropagation()" class="gf-tag-link">'+m+'</a>';
            })
            .replace(/@([a-zA-Z0-9_]+)/g,function(m,u){
                return '<a href="profile.php?username='+encodeURIComponent(u)+'" onclick="event.stopPropagation()" class="gf-mention-link">'+m+'</a>';
            });
    }
    function typeTagInfo(t){
        t=(t||'').toLowerCase();
        return ({image:{icon:'ri-file-copy-line',label:'Photo'},video:{icon:'ri-vidicon-line',label:'Video'},gif:{icon:'ri-file-gif-line',label:'GIF'},sticker:{icon:'ri-shapes-line',label:'Sticker'},stickers:{icon:'ri-shapes-line',label:'Sticker'},text:{icon:'ri-file-text-line',label:'Text'}})[t]||{icon:'ri-file-copy-line',label:'Photo'};
    }
    function fullDate(s){
        if(!s) return ''; var d=new Date(String(s).replace(' ','T')); if(isNaN(d)) return s;
        var D=['Sun','Mon','Tue','Wed','Thu','Fri','Sat'],M=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return D[d.getDay()]+', '+d.getDate()+' '+M[d.getMonth()]+' '+d.getFullYear()+' · '+String(d.getHours()).padStart(2,'0')+':'+String(d.getMinutes()).padStart(2,'0');
    }
    function isNewPost(s){ if(!s) return false; var d=new Date(String(s).replace(' ','T')); return !isNaN(d) && (Date.now()-d.getTime())<=172800*1000; }
    function tsOf(x){ var d=new Date(String(x.time||'').replace(' ','T')); return isNaN(d)?0:d.getTime(); }
    function toast(msg){
        var ex=document.getElementById('gp-toast'); if(ex) ex.remove();
        var t=document.createElement('div'); t.id='gp-toast'; t.textContent=msg;
        document.body.appendChild(t);
        setTimeout(function(){ t.classList.add('out'); setTimeout(function(){ t.remove(); },300); },2200);
    }
    window.gpToast = toast;
    function findAll(id){ return (window.GP_ALL||[]).find(function(x){ return String(x.id)===String(id); }); }
    function modalOpen(){ var m=document.getElementById('cardCaptionModal'); return !!(m && m.classList.contains('ccm-open')); }

    function likesText(p){
        var proof=Array.isArray(p.socialProof)?p.socialProof:[];
        var lp=proof.filter(function(sp){ return sp.action_type==='like'; });
        var c=p.hideLikes?0:(p.likeCount||0), rc=p.hideReposts?0:(p.repostCount||0), base='';
        if(c>0){
            if(lp.length){
                var rest=c-1, first=esc(lp[0].username||'')+(lp[0].isVerified?(window.DZ_VB||''):'');
                base = rest>0
                  ? '<span class="gf-likes-dim">Liked by</span> <b>'+first+'</b> <span class="gf-likes-dim">and</span> <b>'+fmtNum(rest)+' other'+(rest===1?'':'s')+'</b>'
                  : '<span class="gf-likes-dim">Liked by</span> <b>'+first+'</b>';
            } else base='<b>'+fmtNum(c)+' like'+(c===1?'':'s')+'</b>';
        }
        if(rc>0){ var rp='<b>'+fmtNum(rc)+' repost'+(rc===1?'':'s')+'</b>'; base=base?base+' · '+rp:rp; }
        return base;
    }
    function socialRowHtml(p){
        var sp0=Array.isArray(p.socialProof)?p.socialProof:[];
        var av=sp0.length?'<div class="gf-social-avatars">'+sp0.slice(0,3).map(function(sp){ return '<img'+(sp.action_type==='repost'?' class="gf-sp-rep"':'')+' src="'+esc(sp.profile_pic||'mee.jpg')+'" onerror="this.src=\'mee.jpg\'">'; }).join('')+'</div>':'';
        var tx=likesText(p);
        if(!av && !tx) return '';
        return '<div class="gf-social-row" onclick="event.stopPropagation();openEngModal('+p.id+',\'likes\')">'+av+(tx?'<div class="gf-likes-text">'+tx+'</div>':'')+'</div>';
    }

    /* ═══════════ RENDER ONE POST ═══════════ */
    window.hpCollabOf=function(id){var a=[].concat((typeof peuraGroupFeed!=='undefined'&&peuraGroupFeed)||[],window._risePool||[]);for(var i=0;i<a.length;i++){if(String(a[i].id)===String(id))return a[i].collab||null}return null};
    window.dzCollabFit=function(){document.querySelectorAll('.av-wrap.ipv-collab:not([data-fit])').forEach(function(w){var h=w.closest('.gf-header')||w.parentNode;var pr=document.createElement('span');pr.className='av-wrap';pr.style.cssText='position:absolute;visibility:hidden;left:-9999px;top:0';pr.innerHTML='<img class="gf-avatar" alt="">';h.appendChild(pr);var s=pr.firstChild.getBoundingClientRect().width;h.removeChild(pr);if(s>8){w.style.setProperty('--cw',Math.round(s*1.45)+'px');w.setAttribute('data-fit','1');}});};
    (function(){var t=0;function q(){if(t)return;t=requestAnimationFrame(function(){t=0;dzCollabFit();});}try{new MutationObserver(q).observe(document.documentElement,{childList:true,subtree:true});}catch(e){}window.addEventListener('resize',function(){document.querySelectorAll('.av-wrap.ipv-collab[data-fit]').forEach(function(w){w.removeAttribute('data-fit');});q();});q();})();
    function renderPost(p){
        var media=(Array.isArray(p.media)&&p.media.length)?p.media:[{file:p.file,type:p.type}];
        var mc=media.length, isOwn=(String(p.userId)===String(gfCurrentUserId));
        var mediaSet=['image','video','gif','sticker','stickers'];
        var firstType=((media[0].type)||'').toLowerCase();
        var isTextOnly=(mc===1 && mediaSet.indexOf(firstType)===-1);
        var hasVideo=media.some(function(m){ return (m.type||'').toLowerCase()==='video'; });
        var mediaInner;

        if(isTextOnly){
            var words=(p.caption||'').trim().split(/\s+/).filter(Boolean);
            var trunc=words.length>40
                ? linkify(words.slice(0,40).join(' '))+' <span class="gf-more-wrap" onclick="event.stopPropagation();gfToggleCenterCaption(this)"><span class="gf-ellipsis">...</span><span class="gf-more-toggle"> more</span></span>'
                : linkify(p.caption||'');
            mediaInner='<div class="gf-textonly-clickable" onclick="gpMediaTap(event,this,'+p.id+')">'+
                '<div class="gf-center-caption-wrap"><span class="gf-tag-gap"></span><div class="gf-center-caption-text gf-t-'+((p.caption||'').trim().length<=90?'s':((p.caption||'').trim().length<=220?'m':'l'))+'" data-full="'+esc(p.caption||'')+'">'+trunc+'</div></div></div>';
        } else {
            mediaInner='<div class="gf-media-track">'+media.map(function(m,i){
                var t=(m.type||'').toLowerCase();
                if(t==='video'){
                    return '<div class="gf-media-item" data-idx="'+i+'" data-type="video"><video src="'+esc(m.file)+'" muted loop playsinline preload="metadata" class="gf-video" data-idx="'+i+'" onloadedmetadata="gfMeasure(this)" onclick="event.stopPropagation();gpMediaTap(event,this,'+p.id+')"></video></div>';
                }
                var src=(t==='text'||!m.file)?'uploads/Text.jpg':m.file;
                return '<div class="gf-media-item" data-idx="'+i+'" data-type="'+(t||'image')+'"><img src="'+esc(src)+'" data-idx="'+i+'" loading="lazy" decoding="async" draggable="false" onerror="this.src=\'uploads/Text.jpg\'" onload="gfMeasure(this)" onclick="event.stopPropagation();gpMediaTap(event,this,'+p.id+')"></div>';
            }).join('')+'</div>';
        }

        var dotsHtml=(!isTextOnly&&mc>1)?'<div class="gf-dots">'+media.slice(0,20).map(function(_,i){ return '<span class="gf-dot'+(i===0?' active':'')+'"></span>'; }).join('')+'</div>':'';
        var counterHtml=(!isTextOnly&&mc>1)?'<div class="gf-media-counter">1/'+mc+'</div>':'';
        var ti=typeTagInfo(isTextOnly?'text':firstType);
        var topRight='<div class="gf-top-right-tags">'+counterHtml+'</div>';   /* media type tag removed */
        var arrows=(!isTextOnly&&mc>1)?'<button type="button" class="gf-media-arrow gf-arrow-left" aria-label="Previous" onclick="event.stopPropagation();gfArrowNav(this,-1)"><i class="ri-arrow-left-s-line"></i></button><button type="button" class="gf-media-arrow gf-arrow-right" aria-label="Next" onclick="event.stopPropagation();gfArrowNav(this,1)"><i class="ri-arrow-right-s-line"></i></button>':'';

        var topLeft='';

        var partner='';
        /* paid partnership now flips under the username */

        var strip='';
        if(Array.isArray(p.socialProof) && p.socialProof.length){
            strip='<div class="gf-social-proof-strip'+''+'">'+p.socialProof.slice(0,4).map(function(sp){
                var like=sp.action_type==='like';
                return '<a href="profile.php?user_id='+sp.id+'" onclick="event.stopPropagation()" class="gf-sp-avatar-wrap"><img src="'+esc(sp.profile_pic||'mee.jpg')+'" onerror="this.src=\'mee.jpg\'" class="gf-sp-avatar"><span class="gf-sp-badge '+(like?'is-like">':'is-rep" style="background:none;box-shadow:none;border:0;">')+(like?GF_HEART_SVG:'<svg viewBox="0 0 100 100" aria-hidden="true" style="display:block;width:100%;height:100%;overflow:visible"><circle class="sp-ring" cx="50" cy="50" r="55.75" fill="none" stroke-width="11.5"/><circle cx="50" cy="50" r="50" fill="#7a4aff"/><g transform="translate(18.8 18.8) scale(2.6)" fill="none" stroke="#fff" stroke-width="2.04" stroke-linecap="round" stroke-linejoin="round"><path d="M4 13.5V9.276A4.276 4.276 0 0 1 8.276 5H16M12.5 1.5L16 5L12.5 8.5"/><path d="M4 13.5V9.276A4.276 4.276 0 0 1 8.276 5H16M12.5 1.5L16 5L12.5 8.5" transform="rotate(180 12 12)"/></g></svg>')+'</span></a>';
            }).join('')+'</div>';
        }

        /* ONE sound button per post. A video's "Original audio" row points at the video file itself, so it is never also played as a separate track
           (that played the same sound twice and each button only controlled one copy). */
        /* the "track" must never be the video's own sound: flagged original, OR the very same file as one of the post's videos (that played the sound twice, a beat apart = the echo) */
        var fileBase=function(u){ return String(u||'').split('?')[0].split('/').pop().toLowerCase(); };
        var audioIsVideoFile=!!p.audioFile && (Array.isArray(p.media)?p.media:[{file:p.file,type:p.type}]).some(function(m){ return (m.type||'').toLowerCase()==='video' && fileBase(m.file)===fileBase(p.audioFile); });
        var useTrack=!!p.audioFile && !p.isAd && !(p.audioIsOriginal && hasVideo) && !audioIsVideoFile;
        var ctlCount=0, controls='';
        if(!isTextOnly && (hasVideo||p.audioFile)){
            controls='<div class="gf-media-controls">';
            if(hasVideo){ ctlCount++; controls+='<button type="button" class="gf-mute-btn gf-video-mute-btn'+((mc>1&&firstType!=='video'&&!useTrack)?' gp-off':'')+'" onclick="event.stopPropagation();gfToggleVideoMute(this)"><i class="dz-snd off"></i></button>'; }
            else { ctlCount++; controls+='<button type="button" class="gf-mute-btn gf-track-mute-btn" onclick="event.stopPropagation();gfToggleTrackMute()"><i class="dz-snd off"></i></button>'; }
            controls+='</div>';
        }

        var discuss='<button type="button" class="gf-discuss-btn" data-id="'+p.id+'" onclick="event.stopPropagation();gpDiscuss('+p.id+')">Discuss</button>';

        var displayName=isOwn?'You':(p.username||'');
        var newTag='';   /* NEW now sits at the right end of the action row (where AD shows in the viewer) */
        var newFlag=isNewPost(p.time)?'<div class="gf-new-flag">New</div>':'';
        var adminChip=p.isAdmin?'<span class="gf-admin-chip">ADMIN</span>':'';
        var trackBtn=!isOwn?'<span class="gf-dot-sep-red">•</span><button type="button" class="gf-track-btn'+(p.following?' following':'')+'" data-user="'+p.userId+'" onclick="event.stopPropagation();gpTrack(this)">'+(p.following?'Untrack':'Track')+'</button>':'';

        var nick=(!isOwn&&p.nickname)?'@'+p.nickname:'';
        var _co=(p.collab&&p.collab.list&&p.collab.list.length)?p.collab:null,coName=displayName,coPics='',coOpen='';
        if(_co){
          coOpen='event.stopPropagation();dzCollabSheet('+p.id+',hpCollabOf('+p.id+'))';
          coName=displayName+' and '+(_co.list.length===1?esc(_co.list[0].username):_co.list.length+' others');
          nick='';trackBtn='';
          _co.list.slice(0,2).forEach(function(c,i){coPics+='<img class="gf-avatar ipv-co-pic c'+(i+1)+'" src="'+esc(c.pic||'mee.jpg')+'" onerror="this.src=\'mee.jpg\'" onclick="'+coOpen+'">';});
        }
        var audioHtml='';
        if(p.audioId!==undefined&&p.audioId!==null){
            var label=p.audioIsOriginal
                ? (displayName+' · Original audio')
                : (esc(p.audioTitle||'Audio')+(p.audioArtist?' — '+esc(p.audioArtist):''));
            audioHtml='<div class="gf-audio-tag" onclick="event.stopPropagation();gpOpenSounds('+p.userId+','+p.audioId+')"><span class="gf-audio-icon"><span class="gf-eq-bar"></span><span class="gf-eq-bar"></span><span class="gf-eq-bar"></span></span><span class="gf-audio-text">'+label+'</span></div>';
        }
        var switcher='';
        /* nickname, audio and "AI Generated" take turns in the same spot; a lone line just stays put */
        var lines=[];
        if(nick) lines.push('<div class="gf-sub-line">'+nick+'</div>');
        if(p.isPartnership) lines.push('<div class="gf-sub-line gf-partner-line"><span>Paid partnership'+(p.partnerBrand?' with <b>'+esc(p.partnerBrand)+'</b>':'')+'</span></div>');
        if(audioHtml) lines.push(audioHtml);
        if(p.isAiGenerated) lines.push('<div class="gf-sub-line">AI Generated</div>');
        if(lines.length>1) switcher='<div class="gf-info-switcher" data-switcher="1">'+lines.map(function(h,i){ return '<div class="gf-switch-pane'+(i===0?' active':'')+'">'+h+'</div>'; }).join('')+'</div>';
        else if(lines.length) switcher=lines[0];

        var headerCap='',cap='';
        if(!isTextOnly){
            var w=(p.caption||'').trim().split(/\s+/).filter(Boolean), capHtml;
            if(w.length>14){
                capHtml='<span class="gf-cap-short" data-full="'+esc(p.caption||'')+'" data-expanded="0">'+linkify(w.slice(0,14).join(' '))+'<span class="gf-more-wrap" onclick="event.stopPropagation();gfToggleCaption(this)"><span class="gf-ellipsis">...</span><span class="gf-more-toggle"> more</span></span></span>';
            } else capHtml=linkify(p.caption||'');
            headerCap='<div class="gf-header-caption">'+capHtml+'</div>';
            cap='<div class="gf-caption"><b class="gf-cap-user">'+displayName+(p.isVerified?(window.DZ_VB||''):'')+'</b>'+capHtml+'</div>';
        }

        var wrapClass='gf-media-wrap'+((!isTextOnly&&mc>1)?' gf-multi':'')+(isTextOnly?' gf-textonly'+(tl?' gf-tl':''):'')+(ctlCount?' gf-ctl'+ctlCount:'');
        var audioAttrs=useTrack?' data-audio="'+esc(p.audioFile)+'" data-music-vol="'+(p.musicVolume!=null?p.musicVolume:80)+'" data-video-vol="'+(p.videoAudioVolume!=null?p.videoAudioVolume:40)+'"':'';

        return '<div class="gf-post'+(isTextOnly?' gf-post-text':'')+'" data-id="'+p.id+'" data-user="'+p.userId+'"'+audioAttrs+'>'+
            '<div class="gf-header">'+
                '<span class="av-wrap'+(_co?' ipv-collab n'+Math.min(2,_co.list.length):'')+'">'+'<img class="gf-avatar" src="'+esc(p.picT||p.pic||'mee.jpg')+'" loading="lazy" decoding="async" onerror="this.src=\'mee.jpg\'" onclick="'+(_co?coOpen:'location.href=\'profile.php?user_id='+p.userId+'\'')+'">'+coPics+(p.online?'<span class="on-dot"></span>':'')+'</span>'+
                '<div class="gf-header-info"><div class="gf-username-row"><span class="gf-username'+(_co?' ipv-co-name':'')+'" onclick="'+(_co?coOpen:'location.href=\'profile.php?user_id='+p.userId+'\'')+'">'+coName+'</span>'+((p.isVerified&&!_co)?(window.DZ_VB||''):'')+adminChip+newTag+trackBtn+'</div>'+switcher+'</div>'+
                '<button type="button" class="gf-kebab gf-menu-btn" data-id="'+p.id+'" onclick="event.stopPropagation();openGfSheet('+p.id+')"><i class="ri-menu-line"></i></button>'+
            '</div>'+
            headerCap+
            '<div class="'+wrapClass+'" data-count="'+mc+'" data-active-idx="0">'+(isTextOnly?(topLeft+topRight+mediaInner+strip+partner+discuss):(topLeft+topRight+strip+mediaInner+arrows+controls+partner+dotsHtml+discuss))+'</div>'+
            '<div class="gf-actions"><div class="gf-actions-left">'+
                '<div class="gf-action-group gf-like'+(p.liked?' active':'')+'" data-id="'+p.id+'"><button type="button" class="gf-action-btn" onclick="event.stopPropagation();gfAct('+p.id+',\'like\',this)"><i class="sp-rp sp-ic sp-fillable"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg></i></button><span class="gf-action-count gf-like-count"'+(p.hideLikes?' data-hidden="1"':'')+'>'+fmtNum(p.likeCount||0)+'</span></div>'+
                '<div class="gf-action-group gf-repost'+(p.reposted?' active':'')+'" data-id="'+p.id+'"><button type="button" class="gf-action-btn" onclick="event.stopPropagation();gfAct('+p.id+',\'repost\',this)"><i class="sp-rp sp-ic"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 13.5V9.276A4.276 4.276 0 0 1 8.276 5H16M12.5 1.5L16 5L12.5 8.5"/><path d="M4 13.5V9.276A4.276 4.276 0 0 1 8.276 5H16M12.5 1.5L16 5L12.5 8.5" transform="rotate(180 12 12)"/></svg></i></button><span class="gf-action-count gf-repost-count"'+(p.hideReposts?' data-hidden="1"':'')+'>'+fmtNum(p.repostCount||0)+'</span></div>'+
                '<div class="gf-action-group gf-comment" data-id="'+p.id+'"><button type="button" class="gf-action-btn" aria-label="Comments" onclick="event.stopPropagation();dzOpenComments('+p.id+')"><i class="sp-rp sp-ic"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4.5A1.5 1.5 0 0 1 4.5 3h15A1.5 1.5 0 0 1 21 4.5v11a1.5 1.5 0 0 1-1.5 1.5H7l-4 4z"/></svg><i class="ri-chat-off-line cm-off-ic" aria-hidden="true"></i></i></button><span class="gf-action-count gf-comment-count" data-cm-count="'+p.id+'">0</span></div>'+
                '<div class="gf-action-group gf-fav'+(p.faved?' active':'')+'" data-id="'+p.id+'"><button type="button" class="gf-action-btn" onclick="event.stopPropagation();gfAct('+p.id+',\'fav\',this)"><i class="sp-rp sp-ic sp-fillable"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M19 21l-7-5-7 5V4a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1z"/></svg></i></button><span class="gf-action-count gf-fav-count"'+(p.hideSaves?' data-hidden="1"':'')+'>'+fmtNum(p.favCount||0)+'</span></div>'+
                '<div class="gf-action-group gf-share" data-id="'+p.id+'"><button type="button" class="gf-action-btn" onclick="event.stopPropagation();openShareModal('+p.id+')"><i class="sp-rp sp-ic"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M13.4 4.3c0-.7.8-1 1.3-.6l6.9 6.2c.4.4.4 1 0 1.4l-6.9 6.2c-.5.4-1.3.1-1.3-.6v-3.3c-4.3-.1-7.3 1-9.4 4-.4.6-1.3.3-1.2-.4.7-6 4-10.2 10.6-10.7z"/></svg></i></button><span class="gf-action-count gf-share-count"'+(p.hideShares?' data-hidden="1"':'')+'>'+fmtNum(p.shareCount||0)+'</span></div>'+
            '</div>'+newFlag+'</div>'+
            socialRowHtml(p)+cap+
            (isTextOnly?'<div class="gf-time gf-time-row"><div class="gf-time-left"><span>'+(p.time?esc(fullDate(p.time)):'')+'</span><span class="gf-zeem-hint">Click Text to zeem</span></div></div>':(p.time?'<div class="gf-time">'+esc(fullDate(p.time))+'</div>':''))+
        '</div>';
    }

    /* ═══════════ RENDER ONE FILE (same layout as the Files page row, wrapped like a post) ═══════════ */
    function gfFc(c){ return 'fc-' + String(c || '').replace(/[^0-9a-fA-F]/g, ''); }
    function renderFile(f){
        var isOwn=(String(f.userId)===String(gfCurrentUserId));
        var name=isOwn?'You':esc(f.username||'');
        var newTag=isNewPost(f.time)?'<span class="gf-dot-sep">·</span><span class="gf-new-tag">New</span>':'';
        var lead=f.thumb
            ? '<img class="gf-file-thumb" src="'+esc(f.thumb)+'" alt="" onerror="this.outerHTML=\'<div class=&quot;gf-file-ic '+gfFc(f.color)+'&quot;><i class=&quot;'+esc(f.icon)+'&quot;></i></div>\'">'
            : '<div class="gf-file-ic '+gfFc(f.color)+'"><i class="'+esc(f.icon)+'"></i></div>';
        var dl='group_files.php?id='+encodeURIComponent(gfGroupId)+'&dl='+encodeURIComponent(f.id);
        return '<div class="gf-file" data-file-id="'+f.id+'">'+
            '<div class="gf-header">'+
                '<span class="av-wrap">'+'<img class="gf-avatar" src="'+esc(f.pic||'mee.jpg')+'" onerror="this.src=\'mee.jpg\'" onclick="location.href=\'profile.php?user_id='+f.userId+'\'">'+(f.online?'<span class="on-dot"></span>':'')+'</span>'+
                '<div class="gf-header-info"><div class="gf-username-row"><span class="gf-username" onclick="location.href=\'profile.php?user_id='+f.userId+'\'">'+name+'</span>'+(f.isVerified?(window.DZ_VB||''):'')+newTag+'</div>'+
                '<div class="gf-sub-line">shared a file</div></div>'+
                '<button type="button" class="gf-kebab" onclick="event.stopPropagation();gpFileMenu('+f.id+')"><i class="ri-menu-line"></i></button>'+
            '</div>'+
            '<a class="gf-file-card" href="'+dl+'">'+lead+
                '<div class="gf-file-info">'+
                    '<div class="gf-file-title">'+(f.pinned?'<i class="ri-pushpin-2-fill" title="Pinned"></i>':'')+'<span>'+esc(f.title)+'</span></div>'+
                    '<div class="gf-file-sub">'+esc(f.ext)+' · '+esc(f.size)+'</div>'+
                '</div>'+
                '<span class="gf-file-dl"><i class="ri-download-2-line"></i></span>'+
            '</a>'+
            (f.time?'<div class="gf-time">'+esc(fullDate(f.time))+'</div>':'')+
        '</div>';
    }

    /* file menu sheet */
    var curFile=null;
    function findFile(id){ return (window.GP_FILES||[]).find(function(x){ return String(x.id)===String(id); }); }
    function fileUrl(f){ return new URL('group_files.php?id='+encodeURIComponent(gfGroupId)+'&dl='+encodeURIComponent(f.id), location.href).href; }
    function paintFileSave(){
        var b=document.getElementById('gpFsSave'); if(!b||!curFile) return;
        b.classList.toggle('active',!!curFile.saved);
        b.querySelector('i').className=curFile.saved?'ri-bookmark-fill':'ri-bookmark-line';
        b.querySelector('span').textContent=curFile.saved?'Unsave':'Save';
    }
    window.gpFileMenu=function(id){
        var f=findFile(id); if(!f) return;
        dzHp('light');
        curFile=f;
        document.getElementById('gpFsTitle').textContent=f.title;
        document.getElementById('gpFsDl').setAttribute('href','group_files.php?id='+encodeURIComponent(gfGroupId)+'&dl='+encodeURIComponent(f.id));
        document.getElementById('gpFsOpen').setAttribute('href','group_files.php?id='+encodeURIComponent(gfGroupId));
        paintFileSave();
        gpOpen('gpFsOv');
    };
    window.gpFsSave=function(){
        if(!curFile) return;
        dzHp('light');
        var f=curFile; f.saved=!f.saved; paintFileSave();
        fetch('group_files.php?id='+encodeURIComponent(gfGroupId),{
            method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:'toggle_save=1&file_id='+encodeURIComponent(f.id)+'&csrf_token='+encodeURIComponent(CSRF_TOKEN)
        }).then(function(){ toast(f.saved?'File saved':'File removed from saved'); })
          .catch(function(){ f.saved=!f.saved; paintFileSave(); toast('Network error — try again'); });
    };
    window.gpFsShare=function(){
        if(!curFile) return;
        dzHp('light');
        var url=fileUrl(curFile), title=curFile.title;
        gpClose('gpFsOv');
        if(navigator.share){ navigator.share({title:title,url:url}).catch(function(){}); }
        else if(navigator.clipboard&&navigator.clipboard.writeText){ navigator.clipboard.writeText(url).then(function(){ toast('Link copied to clipboard'); }); }
        else toast('Copy this link: '+url);
    };

    function skeletonHTML(n){
        var one='<div class="gf-skel-post"><div class="gf-skel-header"><div class="gf-skel-avatar"></div><div class="gf-skel-header-lines"><div class="gf-skel-line w1"></div><div class="gf-skel-line w2"></div></div></div><div class="gf-skel-media"></div><div class="gf-skel-actions"><div class="gf-skel-icon"></div><div class="gf-skel-icon"></div><div class="gf-skel-icon"></div><div class="gf-skel-icon"></div></div><div class="gf-skel-cap"></div><div class="gf-skel-cap2"></div></div>';
        return new Array(n+1).join(one);
    }

    /* ───────── caption expand / collapse ───────── */
    function syncCapState(post){
        if(!post) return;
        var open = post.querySelector('.gf-cap-short[data-expanded="1"], .gf-center-caption-text[data-open="1"]');
        post.classList.toggle('gf-cap-open', !!open);
    }
    window.gfToggleCaption=function(el){
        var wrap=el.closest('.gf-cap-short'); if(!wrap) return;
        var post=el.closest('.gf-post');
        var open=wrap.dataset.expanded==='1', full=wrap.dataset.full||'';
        if(!open){ wrap.dataset.expanded='1'; wrap.innerHTML=linkify(full)+' <span class="gf-more-toggle" onclick="event.stopPropagation();gfToggleCaption(this)"> less</span>'; }
        else { wrap.dataset.expanded='0'; wrap.innerHTML=linkify(full.trim().split(/\s+/).slice(0,14).join(' '))+'<span class="gf-more-wrap" onclick="event.stopPropagation();gfToggleCaption(this)"><span class="gf-ellipsis">...</span><span class="gf-more-toggle"> more</span></span>'; }
        syncCapState(post);
    };
    window.gfToggleCenterCaption=function(el){
        var t=el.closest('.gf-center-caption-text'); if(!t) return;
        var post=el.closest('.gf-post');
        var full=t.dataset.full||'';
        if(t.dataset.open!=='1'){ t.dataset.open='1'; t.innerHTML=linkify(full)+' <span class="gf-more-toggle" onclick="event.stopPropagation();gfToggleCenterCaption(this)"> less</span>'; }
        else { t.dataset.open='0'; t.innerHTML=linkify(full.trim().split(/\s+/).slice(0,40).join(' '))+'<span class="gf-more-wrap" onclick="event.stopPropagation();gfToggleCenterCaption(this)"><span class="gf-ellipsis">...</span><span class="gf-more-toggle"> more</span></span>'; }
        syncCapState(post);
    };

    /* ═══════════ DISCUSS — self-contained ═══════════ */
    function discussGo(memeId){
        if(!GP_IS_MEMBER){ showDiscLockToast('Join this pod to access Discussions'); return; }
        if(GP_PAUSE_LOCKED){ showDiscLockToast('This pod is paused — check back later'); return; }
        var item=findAll(memeId);
        var external=(!item || !item.groupPostId);
        window.location.href='discussions.php?pod_id='+encodeURIComponent(gfGroupId)+'&prefill_meme_id='+encodeURIComponent(memeId)+(external?'&external_meme=1':'');
    }
    window.gpDiscuss=discussGo;
    window.gfdOpenDiscuss=discussGo;

    /* ═══════════ HIDE / UNDO ═══════════ */
    var HKEY='gf_hidden_posts_group_'+gfGroupId+'_user_'+gfCurrentUserId;
    function loadHidden(){
        try{ var a=JSON.parse(localStorage.getItem(HKEY)||'[]'); return Array.isArray(a)?a.map(String):[]; }catch(e){ return []; }
    }
    function saveHidden(a){ try{ localStorage.setItem(HKEY,JSON.stringify(a)); }catch(e){} }
    window.gfLoadHiddenIds=loadHidden;
    window.gfSaveHiddenIds=saveHidden;

    window.gfHidePost=function(memeId,skipServer){
        dzHp('light');
        var id=String(memeId);
        var ids=loadHidden();
        if(ids.indexOf(id)===-1){ ids.push(id); saveHidden(ids); }

        var post=document.querySelector('#groupFeed .gf-post[data-id="'+id+'"]');
        if(!post) return;
        var unit=post.closest('.gf-card-unit'); if(!unit) return;

        post.querySelectorAll('video').forEach(function(v){ v.pause(); });
        if(switchers[id]){ clearInterval(switchers[id]); delete switchers[id]; }
        if(postObs) postObs.unobserve(post);
        delete visibility[id];
        if(curAudioPost===id) stopAudio();

        unit.innerHTML=
            '<div class="gf-hidden-placeholder" data-hidden-id="'+esc(id)+'">'+
                '<div class="gf-hidden-left"><div class="gf-hidden-icon"><i class="ri-eye-off-line"></i></div><div class="gf-hidden-text">Post hidden</div></div>'+
                '<button type="button" class="gf-undo-btn" onclick="gfUndoHidePost('+esc(id)+')"><i class="ri-arrow-go-back-line"></i> Undo</button>'+
            '</div>';

        if(!skipServer){
            fetch('home.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=not_interested&meme_id='+encodeURIComponent(id)+'&csrf_token='+encodeURIComponent(CSRF_TOKEN)}).catch(function(){});
            toast('Post hidden');
        }
        setTimeout(syncAudioFocus,60);
    };

    window.gfUndoHidePost=function(memeId){
        dzHp('light');
        var id=String(memeId);
        saveHidden(loadHidden().filter(function(x){ return x!==id; }));
        var ph=document.querySelector('#groupFeed .gf-hidden-placeholder[data-hidden-id="'+id+'"]');
        var p=findAll(id);
        if(!ph || !p) return;
        var unit=ph.closest('.gf-card-unit'); if(!unit) return;
        unit.innerHTML=renderPost(p);
        unit.firstElementChild.classList.add('gf-post-enter');
        bindCarousels([unit]); initSwitchers([unit]); observePosts([unit]);
        toast('Post restored');
    };

    /* ═══════════ TOP CONTRIBUTOR ═══════════ */
    function topHtml(){
        var t=window.GP_TOP||(typeof GP_TOP!=='undefined'?GP_TOP:null);
        if(!t) return '';
        var pod=String(t.pod||'Pod');
        return '<a class="gp-top" href="profile.php?user_id='+t.id+'">'+
            '<img class="gp-top-pic" src="'+esc(t.pic||'mee.jpg')+'" onerror="this.src=\'mee.jpg\'" alt="">'+
            '<div class="gp-top-text">'+
                '<span class="gp-top-label"><i class="ri-trophy-line"></i><span class="gp-top-lbl-txt">'+esc(pod)+'\'s Top Contributor</span><span class="gp-top-oat" title="Of All Time — this score never resets">OAT</span></span>'+
                '<span class="gp-top-user">'+esc(t.username)+'</span>'+
                '<span class="gp-top-stats">'+fmtNum(t.posts)+' posts · '+fmtNum(t.likes)+' likes · '+fmtNum(t.reposts)+' reposts · '+fmtNum(t.saves)+' saves</span>'+
            '</div>'+
            '<i class="ri-arrow-right-s-line gp-top-arrow"></i>'+
        '</a>';
    }

    /* ═══════════ SUGGESTED PODS ROW — same rules as the Pod Info modal; hidden when empty ═══════════ */
    function suggHtml(){
        var pods=(window.GP_SUGG_PODS||GP_SUGG_PODS||[]).filter(function(g){ return !suggRemoved[g.id]; });
        if(!pods.length) return '';
        var cards=pods.map(function(g){
            var meta=fmtNum(g.members)+' member'+(g.members===1?'':'s')+(g.category?' · '+esc(g.category):'');
            return '<div class="gp-sugg-card" data-gid="'+g.id+'">'+
                '<a href="group.php?id='+g.id+'"><img class="gp-sugg-photo" src="'+esc(g.img)+'" onerror="this.src=\'uploads/default_group.png\'" alt=""></a>'+
                '<div class="gp-sugg-info"><a class="gp-sugg-name" href="group.php?id='+g.id+'">'+esc(g.title)+'</a><div class="gp-sugg-reason">'+meta+'</div></div>'+
                '<div class="gp-sugg-btns">'+
                    '<button type="button" class="gp-sugg-btn" onclick="event.stopPropagation();gpSuggJoin(this,'+g.id+','+(g.private?'true':'false')+')">'+(g.private?'Request':'Join')+'</button>'+
                    '<button type="button" class="gp-sugg-rm" onclick="gpSuggRemove(this,'+g.id+')">Remove</button>'+
                '</div></div>';
        }).join('');
        return '<div class="gp-sugg"><div class="gp-sugg-head"><div><div class="gp-sugg-kicker">Suggested for you</div><div class="gp-sugg-title">Pods you may like</div></div><button type="button" class="gp-sugg-x" aria-label="Hide suggestions" onclick="gpSuggClose(this)"><i class="ri-close-line"></i></button></div>'+
            '<div class="gp-sugg-scroll-wrap">'+
                '<button type="button" class="gp-sugg-arrow gp-sugg-arrow-l hidden" aria-label="Scroll left" onclick="gpSuggScroll(this,-1)">&#8249;</button>'+
                '<div class="gp-sugg-scroll" onscroll="gpSuggArrows(this)">'+cards+'</div>'+
                '<button type="button" class="gp-sugg-arrow gp-sugg-arrow-r" aria-label="Scroll right" onclick="gpSuggScroll(this,1)">&#8250;</button>'+
            '</div></div>';
    }
    window.gpSuggArrows=function(sc){
        if(!sc) return;
        var wrap=sc.closest('.gp-sugg-scroll-wrap'); if(!wrap) return;
        var l=wrap.querySelector('.gp-sugg-arrow-l'), r=wrap.querySelector('.gp-sugg-arrow-r');
        if(l) l.classList.toggle('hidden', sc.scrollLeft<=4);
        if(r) r.classList.toggle('hidden', sc.scrollLeft>=sc.scrollWidth-sc.clientWidth-4);
    };
    window.gpSuggScroll=function(btn,dir){
        var wrap=btn.closest('.gp-sugg-scroll-wrap'); if(!wrap) return;
        var sc=wrap.querySelector('.gp-sugg-scroll'); if(!sc) return;
        sc.scrollBy({left:dir*Math.max(200,sc.clientWidth*0.75),behavior:'smooth'});
    };
    window.gpSuggClose=function(btn){ var s=btn.closest('.gp-sugg'); if(s) s.remove(); };
    /* Remove: stored permanently (group_suggestion_dismissed), card fades out, and the
       whole section disappears once the last card is gone — same as the Pod Info modal. */
    window.gpSuggRemove=function(btn,gid){
        suggRemoved[gid]=true;
        btn.disabled=true;
        var card=btn.closest('.gp-sugg-card'), sec=btn.closest('.gp-sugg');
        fetch('group.php?id='+gfGroupId,{
            method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:'dismiss_suggested_group=1&dismiss_group_id='+gid+'&csrf_token='+encodeURIComponent(CSRF_TOKEN)
        }).catch(function(){});
        if(card){
            card.style.transition='opacity .25s ease, transform .25s ease';
            card.style.opacity='0'; card.style.transform='scale(.9)';
            setTimeout(function(){
                var sc=card.parentNode; card.remove();
                if(sc) window.gpSuggArrows(sc);
                if(sec && !sec.querySelector('.gp-sugg-card')) sec.remove();
            },260);
        }
    };
    window.gpSuggJoin=function(btn,gid,isPrivate){
        if(btn.classList.contains('done')) return;
        dzHp('medium');
        btn.classList.add('done'); btn.textContent='…';
        fetch('group.php?id='+gid,{
            method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:'join_group=1&csrf_token='+encodeURIComponent(CSRF_TOKEN)
        }).then(function(){
            btn.textContent=isPrivate?'Requested':'Joined';
            toast(isPrivate?'Join request sent':'You joined this pod');
        }).catch(function(){
            btn.classList.remove('done'); btn.textContent=isPrivate?'Request':'Join'; toast('Network error — try again');
        });
    };
    window.addEventListener('resize',function(){ document.querySelectorAll('.gp-sugg-scroll').forEach(window.gpSuggArrows); });

    /* ───────── batch renderer (posts + files) ───────── */
    function renderBatch(count,animate){
        var end=Math.min(rendered+count,FEED.length), html='';
        for(var i=rendered;i<end;i++){
            var it=FEED[i];
            html+='<div class="gf-card-unit">'+(it._file?renderFile(it):renderPost(it))+'</div>';
            if(!topDone && (i+1)===TOP_AFTER){ html+=topHtml(); topDone=true; }
            if(!suggDone && (i+1)===SUGG_AFTER){ html+=suggHtml(); suggDone=true; }
        }
        if(!suggDone && end>=FEED.length && FEED.length<SUGG_AFTER){ html+=suggHtml(); suggDone=true; }

        var tmp=document.createElement('div'); tmp.innerHTML=html;
        var frag=document.createDocumentFragment(), newUnits=[];
        Array.prototype.slice.call(tmp.children).forEach(function(n){
            if(n.classList.contains('gf-card-unit')){ newUnits.push(n); if(animate && n.firstElementChild) n.firstElementChild.classList.add('gf-post-enter'); }
            frag.appendChild(n);
        });
        var sentinel=document.getElementById('gfSentinel');
        if(sentinel) listEl.insertBefore(frag,sentinel); else listEl.appendChild(frag);
        rendered=end;

        applyHidden(newUnits);
        bindCarousels(newUnits); initSwitchers(newUnits); observePosts(newUnits);
        document.querySelectorAll('.gp-sugg-scroll').forEach(function(s){ if(!s.dataset.init){ s.dataset.init='1'; s.scrollLeft=0; } window.gpSuggArrows(s); });

        if(rendered>=FEED.length){
            var s=document.getElementById('gfSentinel'); if(s) s.remove();
            if(sentinelObs){ sentinelObs.disconnect(); sentinelObs=null; }
        } else ensureSentinel();
    }
    function ensureSentinel(){
        var s=document.getElementById('gfSentinel');
        if(!s){ s=document.createElement('div'); s.id='gfSentinel'; s.className='gf-load-more'; s.innerHTML='<div class="gf-load-more-dot"></div><div class="gf-load-more-dot"></div><div class="gf-load-more-dot"></div>'; listEl.appendChild(s); }
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

    function applyHidden(units){
        var ids=loadHidden();
        if(!ids.length) return;
        units.forEach(function(u){
            var p=u.querySelector('.gf-post');
            if(p && ids.indexOf(String(p.dataset.id))!==-1) window.gfHidePost(p.dataset.id,true);
        });
    }

    /* ───────── autoplay on scroll + attached-track sync ───────── */
    function observePosts(units){
        if(!('IntersectionObserver' in window)) return;
        if(!postObs){
            postObs=new IntersectionObserver(function(es){
                es.forEach(function(e){
                    var post=e.target; visibility[post.dataset.id]=e.intersectionRatio;
                    var v=activeVideo(post);
                    if(e.isIntersecting&&e.intersectionRatio>.6&&!modalOpen()){ applyVideoSound(post); if(v) v.play().catch(function(){}); }
                    else post.querySelectorAll('video.gf-video').forEach(function(x){ x.pause(); });
                });
                syncAudioFocus();
            },{threshold:[0,.6,1]});
        }
        units.forEach(function(u){ var p=u.querySelector('.gf-post'); if(p) postObs.observe(p); });
    }
    /* the video of a post that is ON SCREEN (a gallery has several; never just the first one) */
    function activeVideo(post){
        if(!post) return null;
        var wrap=post.querySelector('.gf-media-wrap[data-count]');
        if(wrap){ var i=parseInt(wrap.dataset.activeIdx||'0',10)||0; return wrap.querySelector('.gf-media-item[data-idx="'+i+'"] video'); }
        return post.querySelector('.gf-video');
    }
    /* ONE sound at a time: only the video on screen can be heard; for a post without music it follows the person's unmute choice
       (post.dataset.vsound='1' once they tapped unmute) so it carries on from video to video without tapping again */
    function applyVideoSound(post){
        if(!post) return;
        var act=activeVideo(post), on=post.dataset.vsound==='1';
        post.querySelectorAll('video.gf-video').forEach(function(v){ if(v!==act){ if(!v.paused) v.pause(); v.muted=true; } });
        if(act && !post.dataset.audio) act.muted=!on;
        var ic=post.querySelector('.gf-video-mute-btn i');
        if(ic && !post.dataset.audio) ic.className=on?'dz-snd on':'dz-snd off';
    }
    function syncAudioFocus(){
        var a=document.getElementById('gpAudioPlayer'); if(!a) return;
        if(modalOpen()){ stopAudio(); return; }
        var bestId=null,best=0;
        Object.keys(visibility).forEach(function(id){ if(visibility[id]>best){ best=visibility[id]; bestId=id; } });
        if(best<.6||!bestId){ stopAudio(); return; }
        if(bestId===curAudioPost) return;
        var post=document.querySelector('.gf-post[data-id="'+bestId+'"]'); if(!post) return;
        var url=post.dataset.audio;
        curAudioPost=bestId;
        if(!url){ a.pause(); return; }
        if(a.getAttribute('src')!==url){ a.src=url; a.currentTime=0; }
        if(pendingAudio && String(pendingAudio.id)===String(bestId)){ try{ a.currentTime=pendingAudio.time; }catch(e){} pendingAudio=null; }
        a.muted=trackMuted; a.volume=(parseInt(post.dataset.musicVol,10)||80)/100;
        a.play().catch(function(){});
        var vv=(parseInt(post.dataset.videoVol,10)||40)/100;
        post.querySelectorAll('video.gf-video').forEach(function(v){ v.volume=vv; v.muted=trackMuted; });   /* video + music: both follow the one button */
        updateTrackMuteIcons();
    }
    function stopAudio(){ var a=document.getElementById('gpAudioPlayer'); if(a) a.pause(); curAudioPost=null; }
    function pauseAllVideos(){ document.querySelectorAll('#groupFeed video').forEach(function(v){ v.pause(); }); }
    function resumeFocused(){
        var mid=window.innerHeight/2;
        document.querySelectorAll('#groupFeed .gf-post').forEach(function(post){
            var r=post.getBoundingClientRect();
            if(r.top<mid&&r.bottom>mid){ var v=activeVideo(post); applyVideoSound(post); if(v) v.play().catch(function(){}); }
        });
        syncAudioFocus();
    }

    function initSwitchers(units){
        units.forEach(function(u){
            var post=u.querySelector('.gf-post'); if(!post) return;
            var sw=post.querySelector('[data-switcher="1"]'); if(!sw) return;
            var panes=sw.querySelectorAll('.gf-switch-pane'); if(panes.length<2) return;
            var i=0, id=post.dataset.id;
            if(switchers[id]) clearInterval(switchers[id]);
            switchers[id]=setInterval(function(){ panes[i].classList.remove('active'); i=(i+1)%panes.length; panes[i].classList.add('active'); },5000);
        });
    }

    /* ───────── dynamic aspect ratio + long-post detection ───────── */
    var MULTI_RATIO=3.5/4, MULTI_TOL=.15, LONG_RATIO=1.2;
    window.gfMeasure=function(el){
        var wrap=el.closest('.gf-media-wrap'); if(!wrap) return;
        var w=el.tagName==='VIDEO'?el.videoWidth:el.naturalWidth, h=el.tagName==='VIDEO'?el.videoHeight:el.naturalHeight;
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
        wrap.style.aspectRatio='auto'; wrap.style.height=Math.round(width*c)+'px';
        var post=wrap.closest('.gf-post'); if(post) post.classList.toggle('gf-long-header',c>=LONG_RATIO);
    }
    window.addEventListener('resize',function(){
        document.querySelectorAll('#groupFeed .gf-media-wrap:not(.gf-multi):not(.gf-textonly)').forEach(function(wrap){
            var i=parseInt(wrap.dataset.activeIdx||'0',10);
            var el=wrap.querySelector('.gf-media-item[data-idx="'+i+'"] img, .gf-media-item[data-idx="'+i+'"] video');
            if(el&&el.dataset.ratio) applyAspect(wrap,parseFloat(el.dataset.ratio));
        });
    });

    /* ───────── multi-media carousel (won't sway while you scroll) ───────── */
    function bindCarousels(units){
        units.forEach(function(unit){
            var post=unit.querySelector('.gf-post'); if(!post) return;
            var wrap=post.querySelector('.gf-media-wrap[data-count]'); if(!wrap) return;
            var count=parseInt(wrap.dataset.count,10); if(count<=1) return;
            var track=wrap.querySelector('.gf-media-track'), dots=wrap.querySelectorAll('.gf-dot'), counter=wrap.querySelector('.gf-media-counter');
            if(!track) return;
            var idx=0,startX=0,startY=0,dx=0,dragging=false,lock=null;
            var DEAD=12;
            function goTo(n){
                idx=Math.max(0,Math.min(n,count-1)); wrap.dataset.activeIdx=String(idx);
                track.style.transform='translateX(-'+(idx*100)+'%)';
                dots.forEach(function(d,i){ d.classList.toggle('active',i===idx); });
                if(counter) counter.textContent=(idx+1)+'/'+count;
                wrap.querySelectorAll('.gf-media-item video').forEach(function(v){ var vi=parseInt(v.dataset.idx,10); if(vi!==idx) v.pause(); });
                applyVideoSound(post);          /* the new slide follows the unmute choice; every other video stays silent */
                wrap.querySelectorAll('.gf-media-item video').forEach(function(v){ if(parseInt(v.dataset.idx,10)===idx && (visibility[post.dataset.id]||0)>.6 && !modalOpen()) v.play().catch(function(){}); });
                var vm=wrap.querySelector('.gf-video-mute-btn');
                if(vm) vm.classList.toggle('gp-off', !wrap.querySelector('.gf-media-item[data-idx="'+idx+'"] video') && !post.dataset.audio);   /* keep the only sound button on photo slides when the post has music */
                var tt=wrap.querySelector('.gf-type-tag'), it=wrap.querySelector('.gf-media-item[data-idx="'+idx+'"]');
                if(tt&&it){ var info=typeTagInfo(it.dataset.type||'image'); tt.innerHTML='<i class="'+info.icon+'"></i><span>'+info.label+'</span>'; }
            }
            wrap._gfGoTo=function(d){ goTo(idx+d); };

            wrap.addEventListener('touchstart',function(e){
                if(e.touches.length!==1){ dragging=false; lock=null; return; }
                startX=e.touches[0].clientX; startY=e.touches[0].clientY;
                dx=0; lock=null; dragging=true;
            },{passive:true});

            wrap.addEventListener('touchmove',function(e){
                if(!dragging) return;
                var rawX=e.touches[0].clientX-startX, rawY=e.touches[0].clientY-startY;
                if(lock===null){
                    if(Math.abs(rawX)<DEAD && Math.abs(rawY)<DEAD) return;
                    lock=(Math.abs(rawX)>Math.abs(rawY)*2)?'h':'v';
                    if(lock==='h') track.style.transition='none';
                }
                if(lock!=='h') return;
                dx=rawX>0?rawX-DEAD:rawX+DEAD;
                var atEdge=(idx===0&&dx>0)||(idx===count-1&&dx<0);
                track.style.transform='translateX(calc(-'+(idx*100)+'% + '+(atEdge?dx*0.25:dx)+'px))';
            },{passive:true});

            function endTouch(){
                if(!dragging) return; dragging=false;
                track.style.transition='transform .28s cubic-bezier(.22,1,.36,1)';
                var n=idx;
                if(lock==='h'){
                    var need=Math.min(90,(wrap.clientWidth||300)*0.2);
                    if(Math.abs(dx)>need) n=dx<0?idx+1:idx-1;
                }
                lock=null; dx=0;
                goTo(n);
            }
            wrap.addEventListener('touchend',endTouch,{passive:true});
            wrap.addEventListener('touchcancel',endTouch,{passive:true});
        });
    }
    window.gfArrowNav=function(btn,dir){ var w=btn.closest('.gf-media-wrap'); if(w&&w._gfGoTo) w._gfGoTo(dir); };

    window.gfToggleVideoMute=function(btn){
        var wrap=btn.closest('.gf-media-wrap'), i=parseInt(wrap.dataset.activeIdx||'0',10);
        var v=wrap.querySelector('.gf-media-item[data-idx="'+i+'"] video');
        var post=btn.closest('.gf-post'), hasTrack=!!(post && post.dataset.audio), nowMuted;
        if(v){ v.muted=!v.muted; nowMuted=v.muted; }
        else if(hasTrack){ nowMuted=!trackMuted; }          /* gallery slide that is a photo */
        else return;
        if(!hasTrack && post){ post.dataset.vsound=nowMuted?'0':'1'; applyVideoSound(post); }   /* remember it for the next video of this post */
        if(hasTrack){                                       /* video + music: one button, both sounds */
            trackMuted=nowMuted;
            var a=document.getElementById('gpAudioPlayer'); if(a) a.muted=trackMuted;
            wrap.querySelectorAll('video').forEach(function(x){ x.muted=nowMuted; });
            updateTrackMuteIcons();
        }
        btn.querySelector('i').className=nowMuted?'dz-snd off':'dz-snd on';
    };
    window.gfToggleTrackMute=function(){
        var a=document.getElementById('gpAudioPlayer'); if(!a) return;
        trackMuted=!trackMuted; a.muted=trackMuted; updateTrackMuteIcons();
    };
    function updateTrackMuteIcons(){
        document.querySelectorAll('#groupFeed .gf-track-mute-btn i, #groupFeed .gf-post[data-audio] .gf-video-mute-btn i').forEach(function(i){ i.className=trackMuted?'dz-snd off':'dz-snd on'; });
    }
    window.gpOpenSounds=function(userId,audioId){
        if(!userId) return;
        var url='audio.php?user_id='+userId; if(audioId) url+='&audio_id='+audioId;
        window.location.href=url;
    };

    /* ───────── tap = open gf modal, double tap = like ───────── */
    window.gpMediaTap=function(e,el,memeId){
        var box=el.closest('.gf-media-wrap')||el, now=Date.now(), dt=now-(box._lastTap||0);
        if(dt>0&&dt<320){
            box._lastTap=0; if(box._tapTimer){ clearTimeout(box._tapTimer); box._tapTimer=null; }
            doubleTapLike(box,e,memeId);
        } else {
            box._lastTap=now;
            box._tapTimer=setTimeout(function(){ openModal(memeId); },260);
        }
    };
    /* skipIdx = true when opened from the right-hand grid (start on the first media item) */
    function openModal(memeId,skipIdx){
        if(!skipIdx){
            var post=document.querySelector('.gf-post[data-id="'+memeId+'"]');
            var wrap=post&&post.querySelector('.gf-media-wrap[data-count]');
            if(wrap && typeof _gfdMediaIndexByPost!=='undefined') _gfdMediaIndexByPost[memeId]=parseInt(wrap.dataset.activeIdx||'0',10)||0;
        } else if(typeof _gfdMediaIndexByPost!=='undefined'){
            _gfdMediaIndexByPost[memeId]=0;
        }
        originMemeId=memeId;
        /* where the feed is on this post right now: video time + attached-track time (taken before anything is paused) */
        var startIdx=0, startTime=0;
        if(typeof _gfdTrackPositions!=='undefined') delete _gfdTrackPositions[memeId];   /* a track starts from the feed's spot, or from 0 */
        if(!skipIdx){
            var fp=document.querySelector('.gf-post[data-id="'+memeId+'"]');
            if(fp){
                var fw=fp.querySelector('.gf-media-wrap[data-count]');
                startIdx=fw?(parseInt(fw.dataset.activeIdx||'0',10)||0):0;
                var fv=fp.querySelector('.gf-media-item[data-idx="'+startIdx+'"] video')||fp.querySelector('video.gf-video');
                if(fv && !isNaN(fv.currentTime)) startTime=fv.currentTime||0;
                var fa=document.getElementById('gpAudioPlayer');
                if(fa && fp.dataset.audio && String(curAudioPost)===String(memeId) && !isNaN(fa.currentTime) && typeof _gfdTrackPositions!=='undefined') _gfdTrackPositions[memeId]=fa.currentTime||0;
            }
        }
        pauseAllVideos(); stopAudio();
        if(typeof openGFModal==='function') openGFModal(memeId);
        if(startTime>0){
            var mv=document.querySelector('#cardCaptionModal .ccm-vslide[data-pos="current"] .ccm-media-item[data-idx="'+startIdx+'"] video.ccm-media')||document.querySelector('#ccm-slide .ccm-media-item[data-idx="'+startIdx+'"] video.ccm-media')||document.querySelector('#ccm-slide video.ccm-media');
            setVideoTime(mv,startTime);
        }
    }
    /* set a video's position now, or as soon as it knows its length */
    function setVideoTime(v,t){
        if(!v||!(t>0)) return;
        var go=function(){ try{ v.currentTime=t; }catch(e){} };
        if(v.readyState>=1) go(); else v.addEventListener('loadedmetadata',go,{once:true});
    }
    /* the modal closed: put the feed post on the same slide, video time and track time the modal ended on */
    function applyPlaybackToFeed(pb){
        if(!pb||pb.id==null) return;
        var post=document.querySelector('#groupFeed .gf-post[data-id="'+pb.id+'"]'); if(!post) return;
        var wrap=post.querySelector('.gf-media-wrap[data-count]');
        if(wrap && typeof wrap._gfGoTo==='function'){
            var cur=parseInt(wrap.dataset.activeIdx||'0',10)||0, count=parseInt(wrap.dataset.count||'1',10)||1;
            var tgt=Math.max(0,Math.min(pb.idx||0,count-1));
            if(tgt!==cur) wrap._gfGoTo(tgt-cur);
        }
        if(pb.videoTime>0){
            var i=wrap?(parseInt(wrap.dataset.activeIdx||'0',10)||0):0;
            setVideoTime(post.querySelector('.gf-media-item[data-idx="'+i+'"] video')||post.querySelector('video.gf-video'),pb.videoTime);
        }
        if(pb.audioTime>0 && post.dataset.audio) pendingAudio={id:pb.id,time:pb.audioTime};
    }
    window.gpOpenModal=openModal;

    /* grid tile → same gf modal. If the feed is currently filtered/sorted and this post isn't in
       the modal's list, load the full list so the modal can find it and swipe through everything. */
    window.gpGridOpen=function(id){
        var inList=(window.peuraGroupFeed||[]).some(function(p){ return String(p.id)===String(id); });
        if(!inList) window.peuraGroupFeed=(window.GP_ALL||[]).slice();
        window._gpFromGrid=true;
        openModal(id,true);
    };

    /* pinned card → the modal swipes through the PINNED posts in the order shown in the pinned area (not the feed's order);
       the feed's own list comes back when the modal closes */
    window.gpPinOpen=function(id,ids){
        var list=(ids||[]).map(findAll).filter(Boolean);
        if(!list.length || !list.some(function(p){ return String(p.id)===String(id); })) return window.gpGridOpen(id);
        if(!window._gpSavedFeed) window._gpSavedFeed=window.peuraGroupFeed;
        window.peuraGroupFeed=list;
        window._gpFromGrid=true;
        openModal(id,true);
    };

    function doubleTapLike(box,e,memeId){
        var r=box.getBoundingClientRect();
        var cx=(e&&typeof e.clientX==='number'&&e.clientX!==0)?e.clientX:(r.left+r.width/2);
        var cy=(e&&typeof e.clientY==='number'&&e.clientY!==0)?e.clientY:(r.top+r.height/2);
        var post=box.closest('.gf-post'), grp=post?post.querySelector('.gf-like'):null, b=grp?grp.querySelector('.gf-action-btn'):null, done=false;
        if(grp&&!grp.classList.contains('active')) dzHp('medium');
        var go=function(){ if(done) return; done=true; if(grp&&!grp.classList.contains('active')&&b) gfAct(memeId,'like',b); };
        /* same animation as the home page / Zeems modal: the heart pops where you tapped, jumps to this post's like button, and the like happens when it lands */
        if(typeof ccmShowHeartBurst==='function'){ ccmShowHeartBurst(box,cx-r.left,cy-r.top,go,b?b.querySelector('i'):null); setTimeout(go,1800); }
        else { heartBurst(box,cx-r.left,cy-r.top); go(); }
    }
    function heartBurst(box,x,y){
        box.querySelectorAll('.gf-double-tap-heart,.gf-heart-ring,.gf-heart-particle').forEach(function(el){ el.remove(); });
        var ring=document.createElement('div'); ring.className='gf-heart-ring'; ring.style.left=x+'px'; ring.style.top=y+'px'; box.appendChild(ring);
        requestAnimationFrame(function(){ ring.classList.add('show'); }); setTimeout(function(){ ring.remove(); },800);
        var h=document.createElement('div'); h.className='gf-double-tap-heart'; h.style.left=x+'px'; h.style.top=y+'px'; h.innerHTML='<i class="ri-heart-3-fill"></i>'; box.appendChild(h);
        requestAnimationFrame(function(){ h.classList.add('show'); }); setTimeout(function(){ h.remove(); },950);
        for(var i=0;i<8;i++)(function(i){
            var rad=((360/8)*i+(Math.random()*20-10))*Math.PI/180, dist=60+Math.random()*42;
            var p=document.createElement('div'); p.className='gf-heart-particle'; p.style.left=x+'px'; p.style.top=y+'px';
            p.style.setProperty('--tx',Math.cos(rad)*dist+'px'); p.style.setProperty('--ty',Math.sin(rad)*dist+'px'); p.style.setProperty('--tr',(Math.random()*80-40)+'deg');
            p.innerHTML='<i class="ri-heart-3-fill gf-hs'+Math.floor(Math.random()*6)+'"></i>'; box.appendChild(p);
            setTimeout(function(){ requestAnimationFrame(function(){ p.classList.add('show'); }); },40+i*15); setTimeout(function(){ p.remove(); },950+i*15);
        })(i);
    }

    /* ───────── like / repost / save ───────── */
    var actionLock={};
    var MAP={
        like:  {a:'ajax_like',     k:'liked',     c:'likeCount',   s:'liked'},
        repost:{a:'ajax_repost',   k:'reposted',  c:'repostCount', s:'reposted'},
        fav:   {a:'ajax_favorite', k:'favorited', c:'favCount',    s:'faved'}
    };
    /* my repost takes a slot in the strip; un-repost gives the slot back to the one it displaced — instantly */
    var spBak={};
    function spApply(it,active){
        var me=window.dzMe||{}, meId=String(me.id||0);
        var key=function(x){ return x.id+'|'+x.action_type; };
        var strip=(Array.isArray(it.socialProof)?it.socialProof:[]).filter(function(x){ return !(String(x.id)===meId&&x.action_type==='repost'); });
        var spare=(Array.isArray(it.socialSpare)?it.socialSpare:[]).filter(function(x){ return String(x.id)!==meId||x.action_type!=='repost'; });
        if(active){
            strip.unshift({id:parseInt(meId,10),username:me.username||'',profile_pic:me.profile_pic||'mee.jpg',action_type:'repost',isVerified:false});
            var cut=strip.splice(4), have={}; cut.forEach(function(x){ have[key(x)]=1; });
            spare=cut.concat(spare.filter(function(x){ return !have[key(x)]; }));
        } else {
            var h={}; strip.forEach(function(x){ h[key(x)]=1; });
            while(strip.length<4&&spare.length){ var n=spare.shift(); if(!h[key(n)]&&String(n.id)!==meId){ strip.push(n); h[key(n)]=1; } }
        }
        it.socialProof=strip; it.socialSpare=spare;
    }
    function spRepaint(memeId,it){
        document.querySelectorAll('#groupFeed .gf-post[data-id="'+memeId+'"]').forEach(function(post){ updateSocialRow(post,it); });
    }
    function spOptimistic(memeId,active){
        var it=findAll(memeId); if(!it) return;
        spBak[memeId]={p:it.socialProof,s:it.socialSpare,c:it.repostCount};
        spApply(it,active); it.repostCount=Math.max(0,(it.repostCount||0)+(active?1:-1));
        spRepaint(memeId,it);
    }
    function spRestore(memeId){
        var b=spBak[memeId], it=findAll(memeId); if(!b||!it) return;
        it.socialProof=b.p; it.socialSpare=b.s; it.repostCount=b.c; delete spBak[memeId]; spRepaint(memeId,it);
    }
    /* repost / un-repost made inside the post modal */
    document.addEventListener('zeems:action',function(e){
        var d=e.detail||{}; if(d.type!=='repost') return;
        var it=findAll(d.memeId); if(!it) return;
        if(d.item&&d.item!==it&&Array.isArray(d.item.socialProof)){ it.socialProof=d.item.socialProof; if(Array.isArray(d.item.socialSpare)) it.socialSpare=d.item.socialSpare; }
        spApply(it,!!d.active); spRepaint(d.memeId,it);
    });
    window.gfAct=function(memeId,type,btn){
        var key=memeId+':'+type; if(actionLock[key]) return; actionLock[key]=true;
        var m=MAP[type], grp=btn.closest('.gf-action-group'); if(!grp){ delete actionLock[key]; return; }
        var was=grp.classList.contains('active'), next=!was;
        dzHp(type==='fav'?'light':(next?'medium':'light'));
        if(type==='repost') spOptimistic(memeId,next);
        var cntEl=grp.querySelector('.gf-action-count');
        var prev=cntEl?(parseInt((cntEl.textContent||'0').replace(/[^\d.]/g,''))||0):0;
        grp.classList.toggle('active',next);
        if(cntEl) cntEl.textContent=fmtNum(next?prev+1:Math.max(0,prev-1));
        if(type==='repost'){
            var ic=btn.querySelector('i'); btn.classList.remove('arc-swap-active'); void ic.offsetWidth; btn.classList.add('arc-swap-active');
            ic.addEventListener('animationend',function h(){ btn.classList.remove('arc-swap-active'); ic.removeEventListener('animationend',h); });
        }
        if(type==='fav'){
            if(next&&typeof gfdShowSavedToast==='function') gfdShowSavedToast(memeId);
            else if(!next&&typeof gfdHideSavedToast==='function') gfdHideSavedToast();
        }
        function revert(msg){
            delete actionLock[key]; grp.classList.toggle('active',was); if(cntEl) cntEl.textContent=fmtNum(prev);
            if(type==='fav'&&next&&typeof gfdHideSavedToast==='function') gfdHideSavedToast();
            dzHp('error');
            if(type==='repost') spRestore(memeId);
            if(msg) toast(msg);
        }
        fetch('group.php?id='+gfGroupId,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:m.a+'=1&meme_id='+memeId+'&csrf_token='+encodeURIComponent(CSRF_TOKEN)})
        .then(function(r){ return r.json(); }).then(function(d){
            delete actionLock[key];
            if(d.count===undefined){ revert(d&&d.error?d.error:''); return; }
            var now=!!d[m.k];
            grp.classList.toggle('active',now);
            if(cntEl) cntEl.textContent=fmtNum(d.count);
            var item=findAll(memeId);
            if(item){ item[m.c]=d.count; item[m.s]=now; }
            if(type==='repost'){ delete spBak[memeId]; if(item&&now!==next) spApply(item,now); }
            if(type==='like'||type==='repost'){ var post=btn.closest('.gf-post'); if(post&&item) updateSocialRow(post,item); }
        }).catch(function(){ revert('Network error'); });
    };
    function updateSocialRow(post,p){
        var ex=post.querySelector('.gf-social-row'); if(ex) ex.remove();
        var html=socialRowHtml(p); if(html){ var a=post.querySelector('.gf-actions'); if(a) a.insertAdjacentHTML('afterend',html); }
    }

    function syncAllFromFeed(){
        document.querySelectorAll('#groupFeed .gf-post').forEach(function(post){
            var p=findAll(post.dataset.id); if(!p) return;
            [['.gf-like','.gf-like-count','liked','likeCount'],['.gf-repost','.gf-repost-count','reposted','repostCount'],['.gf-fav','.gf-fav-count','faved','favCount']].forEach(function(t){
                var g=post.querySelector(t[0]), c=post.querySelector(t[1]);
                if(g) g.classList.toggle('active',!!p[t[2]]); if(c) c.textContent=fmtNum(p[t[3]]||0);
            });
            var sc=post.querySelector('.gf-share-count'); if(sc) sc.textContent=fmtNum(p.shareCount||0);
            updateSocialRow(post,p);
        });
    }

    /* ───────── track / untrack ───────── */
    window.gpTrack=function(btn){
        var uid=btn.dataset.user;
        dzHp(/^\s*Track\s*$/i.test(btn.textContent||'')?'medium':'light');
        fetch('group.php?id='+gfGroupId,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:'action=toggle_follow&user_id='+uid+'&csrf_token='+encodeURIComponent(CSRF_TOKEN)})
        .then(function(r){ return r.json(); }).then(function(d){
            if(d.status!=='ok') return;
            document.querySelectorAll('.gf-track-btn[data-user="'+uid+'"], .ccm-track-btn[data-user="'+uid+'"]').forEach(function(b){
                b.textContent=d.following?'Untrack':'Track'; b.classList.toggle('following',!!d.following);
            });
            (window.GP_ALL||[]).forEach(function(p){ if(String(p.userId)===String(uid)){ p.following=!!d.following; p.isFollowing=!!d.following; } });
        });
    };

    /* ───────── rebuild (sort / hashtag filter) ─────────
       Files are only mixed in for the plain "Newest" view (no tag filter, no search),
       so they sit among the posts by time: newer above, older below. */
    function canMixFiles(){
        return (window.GP_FILES||[]).length>0 && window._gfCurrentSort==='newest' && !window._gfActiveTag && !GP_SEARCH;
    }
    function withFiles(items){
        var files=(window.GP_FILES||[]).map(function(f){ var o={}; for(var k in f) o[k]=f[k]; o._file=true; return o; });
        return items.concat(files).sort(function(a,b){ return tsOf(b)-tsOf(a); });
    }
    /* detailed empty-pod message: explains what the pod is for and exactly how to post (no icon) */
    function emptyPodHtml(){
        var cta, note='';
        if(GP_PAUSE_LOCKED){
            note='<div class="gp-ep-note"><i class="ri-pause-circle-line"></i><div><b>This pod is paused right now.</b> Posting is switched off until an admin or the owner resumes it, so check back later.</div></div>';
            cta='';
        } else if(GP_IS_MEMBER){
            cta='<button type="button" class="gp-ep-cta" onclick="if(typeof openPostModal===\'function\')openPostModal()"><i class="ri-add-line"></i> Create the first post</button>';
        } else {
            cta='<button type="button" class="gp-ep-cta" onclick="var b=document.getElementById(\'gpMainJoin\');if(b)b.click();"><i class="ri-user-add-line"></i> Join this pod to post</button>';
        }
        var how = GP_IS_MEMBER
          ? '<li><b>Tap the + button</b> at the top right of this page.</li><li><b>Pick what to share</b> — a photo, a video, several images in one post, or a text post.</li><li><b>Add a caption</b> and hashtags so people can find it from the filter bar.</li><li><b>Post it.</b> It shows up here for every member, and others can like, repost, save and discuss it.</li>'
          : '<li><b>Join the pod</b> using the button near the top of this page.</li><li><b>Tap the + button</b> that appears at the top right once you are a member.</li><li><b>Choose your photo, video or text</b>, add a caption and post it.</li>';
        return '<div class="gp-empty-post">'+
            '<div class="gp-ep-hero">'+
            '<h2>Be the first to post</h2>'+
            '<p>Nothing has been shared in this pod yet — which means the first post sets the tone. Pods come alive when members start sharing, and the first few posts are what everyone else sees when they arrive.</p></div>'+
            note+
            '<div class="gp-ep-sec"><h3><i class="ri-lightbulb-line"></i> What you can post</h3>'+
                '<ul class="gp-ep-ul"><li><i class="ri-image-line"></i><span>Memes, photos and screenshots that fit this pod\'s theme</span></li>'+
                '<li><i class="ri-vidicon-line"></i><span>Short videos, with their own audio or an attached track</span></li>'+
                '<li><i class="ri-stack-line"></i><span>Several images in one post that people can swipe through</span></li>'+
                '<li><i class="ri-file-text-line"></i><span>Text posts for questions, opinions or announcements</span></li></ul></div>'+
            '<div class="gp-ep-sec"><h3><i class="ri-route-line"></i> How to post</h3><ol class="gp-ep-steps">'+how+'</ol></div>'+
            '<div class="gp-ep-sec"><h3><i class="ri-shield-check-line"></i> Keep it welcome</h3>'+
                '<p>Stay on the pod\'s theme, tag the original creator when you remix something, and avoid spam or repeated posts. The pod\'s rules are in the Rules button above, and breaking them can lead to a warning, removal or a ban.</p></div>'+
            cta+
        '</div>';
    }

    function rebuild(items){
        var myId=++buildId;
        Object.keys(switchers).forEach(function(k){ clearInterval(switchers[k]); }); switchers={};
        if(sentinelObs){ sentinelObs.disconnect(); sentinelObs=null; }
        if(postObs){ postObs.disconnect(); postObs=null; }
        visibility={}; stopAudio(); loadingBatch=false;
        window.peuraGroupFeed=items;                       /* the modal only ever sees posts */
        FEED=canMixFiles()?withFiles(items):items;
        rendered=0; suggDone=false; topDone=false;

        if(!FEED.length){
            if(typeof GP_PRIVATE_LOCKED!=='undefined' && GP_PRIVATE_LOCKED){ listEl.innerHTML=''; return; }
            if(window._gfActiveTag){ listEl.innerHTML='<div class="gf-empty">No posts tagged #'+esc(window._gfActiveTag)+' match this filter.</div>'; return; }
            if(GP_SEARCH){ listEl.innerHTML='<div class="gf-empty">No posts match your search.</div>'; return; }
            if((window.GP_ALL||[]).length){ listEl.innerHTML='<div class="gf-empty">No posts match this filter.</div>'; return; }
            listEl.innerHTML=emptyPodHtml();
            return;
        }
        listEl.innerHTML=skeletonHTML(3);
        setTimeout(function(){
            if(myId!==buildId) return;
            listEl.innerHTML='';
            renderBatch(BATCH,true);
        },120);
    }
    window.gpRebuildFeed=rebuild;

    /* ───────── hook the old gf modal (close → sync + land on the post you ended on) ───────── */
    function patchModal(){
        window.gfdOpenDiscuss=discussGo;

        if(typeof window.closeGFModal==='function' && !window.closeGFModal._gp){
            var orig=window.closeGFModal;
            window.closeGFModal=function(){
                var landing=null, fromGrid=!!window._gpFromGrid;
                var pb=null;
                try{
                    /* read the modal's slide / video time / track time BEFORE it is torn down */
                    var cp=(typeof window.ccmGetCurrentPlayback==='function')?window.ccmGetCurrentPlayback():null;
                    var it=window.peuraGroupFeed[_gfmIndex];
                    landing=cp?cp.id:(it?it.id:(typeof _gfmMemeId!=='undefined'?_gfmMemeId:null));
                    if(landing!=null){
                        pb={ id:landing, idx:cp?(cp.mediaIndex||0):0, videoTime:cp?(cp.videoTime||0):0, audioTime:cp?(cp.audioTime||0):0 };
                    }
                }catch(e){}
                orig.apply(this,arguments);
                /* tracking done inside the shared modal updates isFollowing; keep the page's own flag in step */
                (window.GP_ALL||[]).forEach(function(p){ if(p.isFollowing!==undefined) p.following=!!p.isFollowing; });
                if(window._gpSavedFeed){ window.peuraGroupFeed=window._gpSavedFeed; window._gpSavedFeed=null; }
                syncAllFromFeed();
                if(!fromGrid) applyPlaybackToFeed(pb);
                if(!fromGrid && landing!=null && String(landing)!==String(originMemeId)){
                    var el=document.querySelector('#groupFeed .gf-post[data-id="'+landing+'"]'); if(el) el.scrollIntoView({block:'start'});
                }
                window._gpFromGrid=false;
                originMemeId=null;
                setTimeout(resumeFocused,80);
            };
            window.closeGFModal._gp=true;
        }
    }
    window.addEventListener('load',patchModal);

    /* ───────── start ───────── */
    function start(){
        window.peuraGroupFeed=(window.GP_ALL||[]).slice();
        rebuild(window.peuraGroupFeed);
        patchModal();
        if(typeof GF_AUTO_OPEN_MEME_ID!=='undefined' && GF_AUTO_OPEN_MEME_ID>0){
            var _gfTries=0, _gfFetching=false;
            var _gfDropParam=function(){ try{ var u=new URL(location.href); u.searchParams.delete('meme'); history.replaceState(null,'',u.pathname+u.search+u.hash); }catch(e){} };
            /* the post is not in the loaded feed (collab / external Zeem): fetch it from the same endpoint the Zeems modal uses for connected posts, then open it in the Zeems modal */
            var _gfOpenConnected=function(){
                if(_gfFetching) return; _gfFetching=true;
                var id=GF_AUTO_OPEN_MEME_ID;
                fetch(window.location.href,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},credentials:'same-origin',
                    body:'ajax_get_connected_meme=1&meme_id='+encodeURIComponent(id)+'&csrf_token='+encodeURIComponent(CSRF_TOKEN)})
                .then(function(r){ return r.json(); })
                .then(function(d){
                    if(!d || !d.ok){ console.warn('[auto-open] Zeem not available:', d && d.error); return; }
                    if(typeof window.openCardModal==='function'){
                        window.openCardModal({ id:id, feed:window.peuraGroupFeed||[], mediaIndex:0, startAudioTime:0 });
                    } else if(typeof window.openGFModal==='function'){
                        window.openGFModal(id);
                    }
                    _gfDropParam();
                })
                .catch(function(e){ console.warn('[auto-open] fetch failed', e); });
            };
            (function tryOpen(){
                var inFeed = !!findAll(GF_AUTO_OPEN_MEME_ID);
                var modalReady = typeof window.openGFModal==='function' && !!document.getElementById('cardCaptionModal');
                if(inFeed && modalReady){
                    setTimeout(function(){ openModal(GF_AUTO_OPEN_MEME_ID); _gfDropParam(); },250);
                    return;
                }
                /* a few quick retries in case the page is still loading, then go and fetch it */
                if(!inFeed && modalReady && _gfTries>=6){ _gfOpenConnected(); return; }
                if(++_gfTries<60) setTimeout(tryOpen,150);   // keeps trying for about 9 seconds
                else console.warn('[auto-open] gave up — post in feed: '+inFeed+', openGFModal: '+typeof window.openGFModal);
            })();
        }
    }
    if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',start); else start();
})();
</script>

<!-- ═══════════════════════════════════════════════════════════════
     RIGHT-HAND GRID — all posts in the group, laptop / normal view only.
     Skeleton tiles first, then real tiles in batches as you scroll the
     column. Every tile opens the same gf modal (gpGridOpen).
     ═══════════════════════════════════════════════════════════════ -->
<script>
(function(){
    var mq = window.matchMedia('(min-width:1100px)');
    var col = document.getElementById('gpGridCol'), grid = document.getElementById('gpGrid'), countEl = document.getElementById('gpGridCount');
    if(!col || !grid) return;

    var BATCH = 24, started = false, posts = [], rendered = 0, obs = null, loading = false;

    function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
    function fmt(n){
        n = parseInt(n)||0;
        if(n>=1e6) return (n/1e6).toFixed(1).replace(/\.0$/,'')+'M';
        if(n>=1e3) return (n/1e3).toFixed(1).replace(/\.0$/,'')+'K';
        return n;
    }
    function tsOf(p){ var d=new Date(String(p.time||'').replace(' ','T')); return isNaN(d)?0:d.getTime(); }

    function skeleton(n){
        var h=''; for(var i=0;i<n;i++) h+='<div class="sk gp-tile-sk"></div>';
        return h;
    }

    function tileHtml(p){
        var media = (Array.isArray(p.media)&&p.media.length) ? p.media : [{file:p.file,type:p.type}];
        var first = media[0] || {}, t = String(first.type||'').toLowerCase();
        var isMedia = (t==='image'||t==='video'||t==='gif'||t==='sticker'||t==='stickers') && first.file;
        var inner, badge = '';
        if(isMedia){
            if(t==='video'){
                inner = '<video src="'+esc(first.file)+'#t=0.1" muted playsinline preload="metadata"></video>';
                badge = '<span class="gp-tile-badge"><i class="ri-play-fill"></i></span>';
            } else {
                var tsrc = (p.thumb && first.file===p.file) ? p.thumb : first.file;
                inner = '<img src="'+esc(tsrc)+'" alt="" loading="lazy" decoding="async" draggable="false" onerror="'+(tsrc!==first.file?'this.onerror=function(){this.src=\'uploads/Text.jpg\'};this.src=\''+esc(first.file)+'\'':'this.src=\'uploads/Text.jpg\'')+'">';
            }
            if(media.length>1) badge = '<span class="gp-tile-badge"><i class="ri-stack-fill"></i></span>';
        } else {
            inner = '<div class="gp-tile-text"><span>'+esc(p.caption||'')+'</span></div>';
        }
        var ov = '<div class="gp-tile-ov"><span><i class="ri-heart-3-fill"></i>'+fmt(p.likeCount)+'</span><span><i class="ri-repeat-line"></i>'+fmt(p.repostCount)+'</span></div>';
        return '<button type="button" class="gp-tile" data-id="'+p.id+'" onclick="gpGridOpen('+p.id+')" aria-label="Open post">'+inner+badge+ov+'</button>';
    }

    function renderMore(){
        if(loading || rendered>=posts.length) return;
        loading = true;
        var s = document.getElementById('gpGridSentinel'); if(s) s.remove();
        var end = Math.min(rendered+BATCH, posts.length), html = '';
        for(var i=rendered;i<end;i++) html += tileHtml(posts[i]);
        grid.insertAdjacentHTML('beforeend', html);
        rendered = end;
        loading = false;
        if(rendered < posts.length) addSentinel();
        else if(obs){ obs.disconnect(); obs=null; }
    }

    function addSentinel(){
        var s = document.createElement('div');
        s.id = 'gpGridSentinel'; s.className = 'gp-grid-more';
        s.innerHTML = '<div class="gf-load-more-dot"></div><div class="gf-load-more-dot"></div><div class="gf-load-more-dot"></div>';
        grid.appendChild(s);
        if(!('IntersectionObserver' in window)){ renderMore(); return; }
        if(obs) obs.disconnect();
        obs = new IntersectionObserver(function(es){
            es.forEach(function(e){ if(e.isIntersecting) setTimeout(renderMore, 120); });
        }, { root: col, rootMargin: '500px 0px' });
        obs.observe(s);
    }

    function start(){
        if(started) return;
        started = true;
        posts = (window.GP_ALL||[]).slice().sort(function(a,b){ return tsOf(b)-tsOf(a); });
        if(countEl) countEl.textContent = posts.length ? posts.length+' post'+(posts.length===1?'':'s') : '';

        if(!posts.length){
            grid.innerHTML = '<div class="gp-grid-empty">No posts yet</div>';
            return;
        }
        grid.innerHTML = skeleton(Math.min(BATCH, Math.max(posts.length, 9)));
        setTimeout(function(){ grid.innerHTML=''; renderMore(); }, 150);
    }

    function check(){ if(mq.matches) start(); }
    if(mq.addEventListener) mq.addEventListener('change', check); else if(mq.addListener) mq.addListener(check);
    if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', check); else check();
})();
</script>
<script>
/* swipe right to go back — same as the profile page: the page follows the finger; past a third of the screen (or a flick) it slides out and goes back */
(function(){
  var body = document.body, st = null, busy = false;
  var FALLBACK = 'community.php';                 /* same fallback as the back button */
  function overlayOpen(){
    if(document.body.style.overflow === 'hidden' || document.documentElement.style.overflow === 'hidden') return true;   /* any modal / sheet that locks scrolling */
    return !!document.querySelector('#cardCaptionModal.ccm-open, .share-modal-panel.show');
  }
  function inHScroller(el){
    while(el && el !== document.body){
      var cs = getComputedStyle(el);
      if((cs.overflowX === 'auto' || cs.overflowX === 'scroll') && el.scrollWidth > el.clientWidth + 2) return true;
      el = el.parentElement;
    }
    return false;
  }
  function reset(){ body.style.transition = ''; body.style.transform = ''; body.style.boxShadow = ''; body.style.willChange = ''; busy = false; }
  function goBack(){
    if(document.referrer && document.referrer.indexOf(location.host) !== -1 && history.length > 1) history.back(); else location.href = FALLBACK;
  }
  document.addEventListener('touchstart', function(e){
    st = null;
    if(window.innerWidth > 768) return;           /* phones / responsive view only (the back button is hidden on desktop too) */
    if(busy || e.touches.length !== 1 || overlayOpen()) return;
    if(e.target.closest && e.target.closest('input, textarea, select, [contenteditable="true"], [data-no-swipe]')) return;
    if(inHScroller(e.target)) return;
    var t = e.touches[0];
    st = { x0:t.clientX, y0:t.clientY, mode:'wait', x:0, w:window.innerWidth, samples:[{t:performance.now(), x:0}] };
  }, { passive:true });
  document.addEventListener('touchmove', function(e){
    if(!st || st.mode === 'ignore') return;
    var t = e.touches[0], dx = t.clientX - st.x0, dy = t.clientY - st.y0;
    if(st.mode === 'wait'){
      if(Math.max(Math.abs(dx), Math.abs(dy)) < 8) return;
      if(dx <= 0 || Math.abs(dy) > Math.abs(dx) * 0.8){ st.mode = 'ignore'; return; }   /* left or mostly vertical: not ours */
      st.mode = 'drag';
      body.style.transition = 'none'; body.style.willChange = 'transform';
    }
    if(e.cancelable) e.preventDefault();
    var x = Math.max(0, dx);
    st.x = x;
    body.style.transform = 'translate3d(' + x + 'px,0,0)';
    body.style.boxShadow = '-14px 0 40px rgba(0,0,0,' + (0.5 * Math.min(1, x / 120)) + ')';
    var now = performance.now();
    st.samples.push({ t:now, x:x });
    while(st.samples.length > 2 && now - st.samples[0].t > 100) st.samples.shift();
  }, { passive:false });
  function finish(cancelled){
    if(!st) return;
    var s = st; st = null;
    if(s.mode !== 'drag') return;
    var a = s.samples[0], b = s.samples[s.samples.length - 1];
    var v = b.t > a.t ? (b.x - a.x) / (b.t - a.t) : 0;
    var go = !cancelled && (s.x > s.w * 0.33 || (v > 0.5 && s.x > 40));
    busy = true;
    if(go){
      body.style.transition = 'transform .24s cubic-bezier(.22,.8,.3,1)';
      body.style.transform = 'translate3d(' + (s.w + 40) + 'px,0,0)';
      setTimeout(goBack, 230);
      setTimeout(reset, 8000);                    /* safety net */
    } else {
      body.style.transition = 'transform .26s cubic-bezier(.22,1,.36,1), box-shadow .26s ease';
      body.style.transform = 'translate3d(0,0,0)';
      body.style.boxShadow = 'none';
      setTimeout(reset, 280);
    }
  }
  document.addEventListener('touchend',    function(){ finish(false); }, { passive:true });
  document.addEventListener('touchcancel', function(){ finish(true);  }, { passive:true });
  window.addEventListener('pageshow', function(e){ if(e.persisted) reset(); });
})();
</script>
<script>
/* ── comments turned off: the feed's comment button shows the "chat-off" icon (same as home) and hides its number ── */
(function(){
  var OFF = {}, ASKED = {}, busy = false, timer = 0;
  function paint(g){
    var id = g.getAttribute('data-id'); if(!(id in OFF)) return;
    var off = !!OFF[id];
    g.classList.toggle('cm-off', off);
    var c = g.querySelector('.gf-comment-count'); if(c){ if(off) c.setAttribute('data-off','1'); else c.removeAttribute('data-off'); }
  }
  function scan(){
    timer = 0;
    var groups = document.querySelectorAll('.gf-comment[data-id]'); if(!groups.length) return;
    var ask = [];
    Array.prototype.forEach.call(groups, function(g){
      var id = g.getAttribute('data-id');
      if(id in OFF) paint(g); else if(!ASKED[id] && ask.length < 50){ ASKED[id] = 1; ask.push(id); }
    });
    if(!ask.length || busy || !window.CommentSection || typeof window.CommentSection.status !== 'function') { ask.forEach(function(id){ delete ASKED[id]; }); return; }
    busy = true;
    window.CommentSection.status(ask).then(function(r){
      var off = (r && r.off) || {};
      ask.forEach(function(id){ OFF[id] = !!off[id]; });
      Array.prototype.forEach.call(document.querySelectorAll('.gf-comment[data-id]'), paint);
    }).catch(function(){ ask.forEach(function(id){ delete ASKED[id]; }); }).then(function(){ busy = false; if(document.querySelector('.gf-comment[data-id]')) later(); });
  }
  function later(){ if(timer) return; timer = setTimeout(scan, 250); }
  new MutationObserver(later).observe(document.documentElement, {childList:true, subtree:true});
  document.addEventListener('DOMContentLoaded', later); later();
})();
</script>

<!-- ═══ Likes / Reposts bottom sheet — same design + behaviour as the profile page's ═══ -->
<style>
.gpl-overlay{ display:none; position:fixed; inset:0; z-index:999999; background:rgba(0,0,0,.55); align-items:flex-end; justify-content:center; opacity:0; transition:opacity .2s ease; }
.gpl-overlay.gpl-open{ display:flex; opacity:1; }
.gpl-panel{ width:100%; max-width:470px; max-height:70vh; background:var(--lane,#0d1030); border-radius:16px 16px 0 0; border:1px solid rgba(var(--fg,255,255,255),.08); border-bottom:none; padding:10px 0 18px; display:flex; flex-direction:column; transform:translateY(100%); transition:transform .28s cubic-bezier(.22,1,.36,1); font-family:'Poppins',sans-serif; }
.gpl-overlay.gpl-open .gpl-panel{ transform:translateY(0); }
.gpl-handle{ width:36px; height:4px; border-radius:3px; background:rgba(var(--fg,255,255,255),.25); margin:0 auto 12px; }
.gpl-tabs{ display:flex; gap:6px; padding:0 16px 10px; border-bottom:1px solid rgba(var(--fg,255,255,255),.07); }
.gpl-tab{ background:none; border:none; font-family:inherit; font-size:12.5px; font-weight:700; color:rgba(var(--fg,255,255,255),.5); padding:6px 14px; border-radius:20px; cursor:pointer; -webkit-tap-highlight-color:transparent; transition:color .15s ease, background .15s ease; }
.gpl-tab.active{ color:rgb(var(--fg,240,242,255)); background:rgba(255,45,45,.14); }
.gpl-list{ overflow-y:auto; padding:2px 0 6px; }
.gpl-row{ display:flex; align-items:center; gap:10px; padding:10px 16px; border-bottom:1px solid rgba(var(--fg,255,255,255),.07); text-decoration:none; }
.gpl-avatar{ width:38px; height:38px; border-radius:50%; object-fit:cover; border:1.5px solid #FF2D2D; flex-shrink:0; }
.gpl-name{ font-size:13px; font-weight:600; color:rgb(var(--fg,240,242,255)); flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.gpl-name .dz-vb{ width:1em; height:1em; margin-left:.28em; }
.gpl-icon{ font-size:14px; color:#FF2D2D; flex-shrink:0; }
.gpl-empty{ padding:24px 16px; text-align:center; font-size:12.5px; color:rgba(var(--fg,255,255,255),.5); }
</style>
<div id="gplOverlay" class="gpl-overlay" onclick="if(event.target===this)closeEngModal()">
  <div class="gpl-panel">
    <div class="gpl-handle"></div>
    <div class="gpl-tabs" id="gplTabs">
      <button type="button" class="gpl-tab active" data-tab="likes" onclick="gplSwitch(this,'likes')">Likes</button>
      <button type="button" class="gpl-tab" data-tab="reposts" onclick="gplSwitch(this,'reposts')">Reposts</button>
    </div>
    <div class="gpl-list" id="gplList"></div>
  </div>
</div>
<script>
(function(){
  var D = { likes:[], reposts:[] }, tok = 0, cur = 'likes';
  function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
  function render(tab){
    var el = document.getElementById('gplList'); if(!el) return;
    var list = D[tab] || [];
    if(!list.length){ el.innerHTML = '<div class="gpl-empty">No '+tab+' yet</div>'; return; }
    var icon = tab === 'reposts' ? 'ri-repeat-line' : 'ri-heart-3-fill';
    el.innerHTML = list.map(function(sp){
      return '<a href="profile.php?user_id='+sp.id+'" class="gpl-row">'+
        '<img class="gpl-avatar" src="'+esc(sp.profile_pic||'mee.jpg')+'" onerror="this.onerror=null;this.src=\'mee.jpg\'">'+
        '<span class="gpl-name">'+esc(sp.username||'')+(sp.isVerified?(window.DZ_VB||''):'')+'</span>'+
        '<i class="gpl-icon '+icon+'"></i></a>';
    }).join('');
  }
  window.openEngModal = function(memeId, tab){
    var p = (window.GP_ALL||[]).find(function(x){ return String(x.id)===String(memeId); });
    var proof = (p && Array.isArray(p.socialProof)) ? p.socialProof : [];
    D.likes   = proof.filter(function(sp){ return sp.action_type==='like'; });
    D.reposts = proof.filter(function(sp){ return sp.action_type==='repost'; });
    cur = (tab === 'reposts') ? 'reposts' : 'likes';
    document.querySelectorAll('#gplTabs .gpl-tab').forEach(function(b){ b.classList.toggle('active', b.dataset.tab===cur); });
    render(cur);
    document.getElementById('gplOverlay').classList.add('gpl-open');
    /* the quick list is only people you track — swap in the full lists as soon as they arrive */
    var my = ++tok;
    fetch('profile.php', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=get_meme_social&meme_id='+encodeURIComponent(memeId) })
      .then(function(r){ return r.json(); })
      .then(function(d){
        if(my !== tok || !d || !d.ok) return;
        if(Array.isArray(d.likes))   D.likes   = d.likes.map(function(u){ return { id:u.id, username:u.username, profile_pic:u.profile_pic, isVerified:!!(+u.is_verified) }; });
        if(Array.isArray(d.reposts)) D.reposts = d.reposts.map(function(u){ return { id:u.id, username:u.username, profile_pic:u.profile_pic, isVerified:!!(+u.is_verified) }; });
        render(cur);
      }).catch(function(){});
  };
  window.gplSwitch = function(btn, tab){
    cur = tab;
    document.querySelectorAll('#gplTabs .gpl-tab').forEach(function(b){ b.classList.toggle('active', b===btn); });
    render(tab);
  };
  window.closeEngModal = function(){ var el = document.getElementById('gplOverlay'); if(el) el.classList.remove('gpl-open'); };
  document.addEventListener('keydown', function(e){ if(e.key==='Escape') window.closeEngModal(); });
})();
</script>
<style>
.mt-tabs{display:flex;gap:3px;margin:2px 0 8px;padding:3px;border-radius:6px;background:rgba(var(--fg,255,255,255),.07)}
.mt-tab{flex:1;border:0;background:none;color:rgba(var(--fg,255,255,255),.6);font:inherit;font-size:calc(11px * var(--bs-s,1));font-weight:600;padding:calc(7px * var(--bs-s,1)) 4px;border-radius:4px;cursor:pointer}
.mt-tab.on{background:rgba(var(--fg,255,255,255),.14);color:var(--text,#F0F2FF)}
.mt-tab em{font-style:normal;margin-left:4px;color:#ff6b6b}
.mt-body{padding:0 calc(10px * var(--bs-s,1)) calc(10px * var(--bs-s,1))}
.mt-empty{padding:calc(26px * var(--bs-s,1)) 10px;text-align:center;font-size:calc(11.5px * var(--bs-s,1));color:rgba(var(--fg,255,255,255),.45)}
.mt-card{padding:calc(10px * var(--bs-s,1)) 0;border-top:1px solid rgba(255,255,255,.08)}
.mt-card:first-child{border-top:0}
.mt-row{display:flex;gap:10px;align-items:flex-start}
.mt-th{width:calc(46px * var(--bs-s,1));height:calc(46px * var(--bs-s,1));border-radius:6px;background:rgba(var(--fg,255,255,255),.08);flex:none;object-fit:cover;display:flex;align-items:center;justify-content:center;color:rgba(var(--fg,255,255,255),.5);font-size:18px;overflow:hidden}
.mt-th img{width:100%;height:100%;object-fit:cover}
.mt-av{border-radius:50%;border:2px solid #FF2D2D}
.mt-who{display:flex;align-items:center;gap:6px}
.mt-uav{width:calc(20px * var(--bs-s,1));height:calc(20px * var(--bs-s,1));border-radius:50%;object-fit:cover;border:1.5px solid #FF2D2D;flex:none;background:rgba(var(--fg,255,255,255),.1)}
.mt-who .dz-vb{width:1.05em;height:1.05em;flex:none;display:inline-block;vertical-align:middle}
.mt-tx{min-width:0;flex:1}
.mt-who{font-size:calc(12.5px * var(--bs-s,1));font-weight:700;color:var(--text,#F0F2FF)}
.mt-cap{font-size:calc(11px * var(--bs-s,1));color:rgba(var(--fg,255,255,255),.6);display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;word-break:break-word}
.mt-why{font-size:calc(10.5px * var(--bs-s,1));color:#ff9a9a;margin-top:2px}
.mt-badge{display:inline-block;margin-left:6px;padding:1px 6px;border-radius:3px;font-size:9px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;background:rgba(255,45,45,.18);color:#ff6b6b;vertical-align:middle}
.mt-acts{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.mt-b{border:0;border-radius:4px;padding:calc(6px * var(--bs-s,1)) calc(10px * var(--bs-s,1));font:inherit;font-size:calc(10.5px * var(--bs-s,1));font-weight:700;background:rgba(var(--fg,255,255,255),.1);color:var(--text,#F0F2FF);cursor:pointer}
.mt-b:hover{background:rgba(var(--fg,255,255,255),.18)}
.mt-b.red{background:#FF2D2D;color:#000}
.mt-b.ok{background:rgba(74,222,128,.2);color:#4ade80}
.mt-search{width:100%;margin:0 0 6px;padding:calc(8px * var(--bs-s,1)) 10px;border-radius:5px;border:1px solid rgba(255,255,255,.1);background:rgba(var(--fg,255,255,255),.05);color:var(--text,#F0F2FF);font:inherit;font-size:calc(12px * var(--bs-s,1))}
.mt-muted{color:#ffb454;font-size:calc(10.5px * var(--bs-s,1));margin-top:1px}
.gp-sheet.pf .mt-scroll{overflow-y:auto;min-height:0;max-height:55vh}
</style>
<?php if ($is_mod): ?>
<!-- ═════════ MOD TOOLS (moderators only) ═════════ -->
<div class="gp-ov pf-ov" id="gpModOv" onclick="if(event.target===this)gpClose('gpModOv')">
  <div class="gp-sheet pf" role="dialog" aria-modal="true" aria-label="Mod tools">
    <div class="pf-handle"><span></span></div>
    <div class="pf-card">
      <div class="pf-scroll mt-scroll">
        <div class="pf-header">Mod tools</div>
        <div class="pf-name"><?php echo htmlspecialchars($group['title']); ?></div>
        <div class="mt-body">
          <div class="mt-tabs" role="tablist">
            <button type="button" class="mt-tab on" data-tab="reports">Reports<em id="mtCntR"></em></button>
            <button type="button" class="mt-tab" data-tab="pending">Pending<em id="mtCntP"></em></button>
            <button type="button" class="mt-tab" data-tab="members">Members</button>
          </div>
          <div id="mtList"></div>
        </div>
      </div>
    </div>
    <button type="button" class="pf-close" onclick="gpClose('gpModOv')">Close</button>
  </div>
</div>
<?php endif; ?>
<?php if (!empty($my_warns_unseen) || $my_mute_unseen): ?>
<!-- ═════════ NOTICE: a moderator warned / muted me ═════════ -->
<div class="gp-ov pf-ov open" id="gpNoticeOv">
  <div class="gp-sheet pf" role="dialog" aria-modal="true" aria-label="Message from the moderators">
    <div class="pf-handle"><span></span></div>
    <div class="pf-card"><div class="pf-scroll">
      <div class="pf-header">From the moderators</div>
      <div class="pf-name"><?php echo htmlspecialchars($group['title']); ?></div>
      <div class="pf-text">
        <?php foreach ($my_warns_unseen as $w): ?><p><strong class="gp-hl">Warning:</strong> <?php echo htmlspecialchars($w['reason']); ?></p><?php endforeach; ?>
        <?php if ($my_mute_unseen): ?><p><strong class="gp-hl">You're muted</strong> and can't post in this pod for <?php echo gp_mute_human($my_mute_secs); ?>.</p><?php endif; ?>
        <p>Please follow the pod's rules. Repeated problems can lead to removal from the pod.</p>
      </div>
    </div></div>
    <button type="button" class="pf-close" id="gpNoticeOk">I understand</button>
  </div>
</div>
<script>
(function(){
  var b=document.getElementById('gpNoticeOk'), o=document.getElementById('gpNoticeOv'); if(!b||!o) return;
  document.body.style.overflow='hidden';
  b.addEventListener('click',function(){
    o.classList.remove('open'); document.body.style.overflow='';
    fetch('group.php?id=<?php echo (int)$group_id; ?>',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},body:'mod_action=ack&csrf_token='+encodeURIComponent(CSRF_TOKEN)}).catch(function(){});
  });
})();
</script>
<?php endif; ?>
<?php if ($is_mod): ?>
<script>
/* MOD TOOLS: reports, pending posts, warn and mute (moderators only; the server checks every action) */
(function(){
  var GID = <?php echo (int)$group_id; ?>;
  var ov = document.getElementById('gpModOv'); if(!ov) return;
  var list = document.getElementById('mtList'), tabs = ov.querySelectorAll('.mt-tab');
  var tab = 'reports', data = {reports:[], pending:[]}, members = [], q = '', qT = null;
  function hp(k){ try{ if(window.dzHp) dzHp(k); }catch(e){} }
  function toast(m){ if(window.showDiscLockToast) showDiscLockToast(m); }
  function api(p){
    var b = new URLSearchParams(); Object.keys(p).forEach(function(k){ b.append(k, p[k]); }); b.append('csrf_token', CSRF_TOKEN);
    return fetch('group.php?id=' + GID, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'}, body:b.toString()}).then(function(r){ return r.json(); });
  }
  function el(t, c, x){ var e = document.createElement(t); if(c) e.className = c; if(x != null) e.textContent = x; return e; }
  function btn(label, cls, fn){ var b = el('button', 'mt-b' + (cls ? ' ' + cls : ''), label); b.type = 'button'; b.onclick = function(ev){ ev.stopPropagation(); hp('light'); fn(b); }; return b; }
  function thumb(it){
    var t = el('div', 'mt-th');
    if(it.thumb && (it.type === 'image' || /\.(jpe?g|png|gif|webp)$/i.test(decodeURIComponent(it.thumb)))){ var i = document.createElement('img'); i.src = it.thumb; i.alt = ''; t.appendChild(i); }
    else { var ic = document.createElement('i'); ic.className = it.type === 'video' ? 'ri-movie-line' : 'ri-image-line'; t.appendChild(ic); }
    return t;
  }
  function counts(){
    var r = document.getElementById('mtCntR'), p = document.getElementById('mtCntP');
    r.textContent = data.reports.length ? data.reports.length : ''; p.textContent = data.pending.length ? data.pending.length : '';
  }
  function load(){
    return api({mod_action:'list'}).then(function(d){ if(d.status === 'ok'){ data = d; counts(); } render(); }).catch(function(){ render(); });
  }
  function loadMembers(){
    return api({mod_action:'members', q:q}).then(function(d){ members = d.members || []; render(); }).catch(function(){});
  }
  function removePost(it){
    if(!confirm('Remove this post from the pod? This can\'t be undone.')) return;
    try{ sessionStorage.setItem('gp_modreopen', String(GID)); }catch(e){}
    var f = document.createElement('form'); f.method = 'POST'; f.action = 'group.php?id=' + GID;
    [['delete_post','1'],['delete_post_id', it.group_post_id],['delete_meme_id', it.meme_id],['csrf_token', CSRF_TOKEN]].forEach(function(p){ var i = document.createElement('input'); i.type = 'hidden'; i.name = p[0]; i.value = p[1]; f.appendChild(i); });
    document.body.appendChild(f); f.submit();
  }
  function warn(uid, name){
    var r = prompt('Warn @' + name + ' — what did they do wrong?'); if(r === null) return; r = r.trim(); if(!r){ toast('Add a short reason'); return; }
    api({mod_action:'warn', user_id:uid, reason:r}).then(function(d){ hp(d.status === 'ok' ? 'success' : 'error'); toast(d.status === 'ok' ? 'Warning sent to @' + name : d.error); if(tab === 'members') loadMembers(); });
  }
  function muteMenu(acts, uid, name){
    acts.textContent = '';
    [['1 hour',60],['1 day',1440],['7 days',10080]].forEach(function(o){
      acts.appendChild(btn(o[0], 'red', function(){ api({mod_action:'mute', user_id:uid, minutes:o[1]}).then(function(d){ hp(d.status === 'ok' ? 'success' : 'error'); toast(d.status === 'ok' ? '@' + name + ' muted for ' + o[0] : d.error); if(tab === 'members') loadMembers(); else render(); }); }));
    });
    acts.appendChild(btn('Cancel', '', function(){ render(); }));
  }
  function memberActs(acts, uid, name, muted){
    acts.appendChild(btn('Warn', '', function(){ warn(uid, name); }));
    if(muted) acts.appendChild(btn('Unmute', 'ok', function(){ api({mod_action:'mute', user_id:uid, minutes:0}).then(function(d){ hp('success'); toast('@' + name + ' unmuted'); if(tab === 'members') loadMembers(); else render(); }); }));
    else acts.appendChild(btn('Mute…', '', function(){ muteMenu(acts, uid, name); }));
  }
  var VB = <?php echo json_encode(dz_vb_svg()); ?>;
  function who(name, pic, verified, showAv){
    var w = el('div', 'mt-who');
    if(showAv){ var a = document.createElement('img'); a.className = 'mt-uav'; a.src = pic; a.alt = ''; a.onerror = function(){ a.src = 'mee.jpg'; }; w.appendChild(a); }
    w.appendChild(el('span', '', '@' + name));
    if(verified){ var b = document.createElement('span'); b.style.display = 'inline-flex'; b.innerHTML = VB; w.appendChild(b); }
    return w;
  }
  function empty(t){ list.appendChild(el('div', 'mt-empty', t)); }
  function render(){
    list.textContent = '';
    tabs.forEach(function(t){ t.classList.toggle('on', t.getAttribute('data-tab') === tab); });
    if(tab === 'reports'){
      if(!data.reports.length) return empty('No reported posts. Nice and quiet.');
      data.reports.forEach(function(it){
        var c = el('div', 'mt-card'), row = el('div', 'mt-row'); row.appendChild(thumb(it));
        var tx = el('div', 'mt-tx'), w = who(it.poster, it.pic, +it.verified, true);
        if(+it.escalated){ w.appendChild(el('span', 'mt-badge', 'Escalated')); }
        tx.appendChild(w);
        if((it.caption || '').trim()) tx.appendChild(el('div', 'mt-cap', it.caption));
        tx.appendChild(el('div', 'mt-why', 'Reported' + (it.reporter ? ' by @' + it.reporter : '') + ': ' + (it.reason || 'No reason') + (it.description ? ' — ' + it.description : '')));
        row.appendChild(tx); c.appendChild(row);
        var acts = el('div', 'mt-acts');
        if(!it.poster_staff){
          acts.appendChild(btn('Remove post', 'red', function(){ removePost(it); }));
          acts.appendChild(btn('Dismiss', '', function(){ api({mod_action:'dismiss', report_id:it.report_id}).then(function(){ load(); }); }));
          acts.appendChild(btn('Warn', '', function(){ warn(it.poster_id, it.poster); }));
          acts.appendChild(btn('Mute…', '', function(){ muteMenu(acts, it.poster_id, it.poster); }));
        } else {
          acts.appendChild(el('span', 'mt-why', 'Posted by an admin — only admins can act on this.'));
        }
        if(!+it.escalated) acts.appendChild(btn('Escalate to admins', '', function(){ api({mod_action:'escalate', report_id:it.report_id}).then(function(){ toast('Sent to the admins'); load(); }); }));
        c.appendChild(acts); list.appendChild(c);
      });
    } else if(tab === 'pending'){
      if(!data.pending.length) return empty('No posts waiting for approval.');
      data.pending.forEach(function(it){
        var c = el('div', 'mt-card'), row = el('div', 'mt-row'); row.appendChild(thumb(it));
        var tx = el('div', 'mt-tx'); tx.appendChild(who(it.poster, it.pic, +it.verified, true));
        if((it.caption || '').trim()) tx.appendChild(el('div', 'mt-cap', it.caption));
        row.appendChild(tx); c.appendChild(row);
        var acts = el('div', 'mt-acts');
        acts.appendChild(btn('Approve', 'ok', function(){ api({mod_action:'approve', group_post_id:it.group_post_id}).then(function(){ hp('success'); load(); }); }));
        acts.appendChild(btn('Reject', 'red', function(){ removePost(it); }));
        c.appendChild(acts); list.appendChild(c);
      });
    } else {
      var s = document.createElement('input'); s.className = 'mt-search'; s.placeholder = 'Search members'; s.value = q;
      s.oninput = function(){ q = s.value; clearTimeout(qT); qT = setTimeout(loadMembers, 250); };
      list.appendChild(s);
      if(!members.length) return empty('No members found.');
      members.forEach(function(m){
        var c = el('div', 'mt-card'), row = el('div', 'mt-row'), th = el('div', 'mt-th mt-av'), i = document.createElement('img'); i.src = m.pic; i.alt = ''; i.onerror = function(){ i.src = 'mee.jpg'; }; th.appendChild(i);
        row.appendChild(th);
        var tx = el('div', 'mt-tx'); tx.appendChild(who(m.username, m.pic, +m.verified, false));
        var sub = []; if(+m.strikes) sub.push(m.strikes + ' warning' + (+m.strikes === 1 ? '' : 's'));
        if(sub.length) tx.appendChild(el('div', 'mt-cap', sub.join(' · ')));
        if(+m.mute_secs > 0) tx.appendChild(el('div', 'mt-muted', 'Muted for ' + (m.mute_secs >= 86400 ? Math.ceil(m.mute_secs / 86400) + ' more day(s)' : m.mute_secs >= 3600 ? Math.ceil(m.mute_secs / 3600) + ' more hour(s)' : Math.max(1, Math.ceil(m.mute_secs / 60)) + ' more min')));
        row.appendChild(tx); c.appendChild(row);
        var acts = el('div', 'mt-acts'); memberActs(acts, +m.id, m.username, +m.mute_secs > 0); c.appendChild(acts); list.appendChild(c);
      });
    }
  }
  tabs.forEach(function(t){ t.onclick = function(){ hp('selection'); tab = t.getAttribute('data-tab'); if(tab === 'members' && !members.length) loadMembers(); render(); }; });
  window.modOpen = function(){ hp('light'); gpOpen('gpModOv'); load(); };
  window.addEventListener('keydown', function(e){ if(e.key === 'Escape') gpClose('gpModOv'); });
  try{ if(sessionStorage.getItem('gp_modreopen') === String(GID)){ sessionStorage.removeItem('gp_modreopen'); modOpen(); } }catch(e){}
  load();
})();
</script>
<?php endif; ?>
</body>
</html>