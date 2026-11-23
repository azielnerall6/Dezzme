<?php
$dz_lazy = (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && in_array($_GET['dz_lazy'] ?? '', ['reposts','tags','favs'], true)) ? $_GET['dz_lazy'] : '';   /* tab fragment request: only that tab's data is loaded */
if ($dz_lazy !== '') ob_start();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($_GET['type'] ?? '') === 'ping') { header('Cache-Control: no-store'); http_response_code(204); exit; }   /* connection check used by offline.php: answers instantly, runs nothing else */
/* ══════════════════════════════════════════════════════════════════════════
   🔒 SECURITY GATE — CSRF PROTECTION (keep this block as the FIRST thing in profile.php;
      put your own top PHP (session / queries / POST handlers) BELOW it.)

   What it does
   • Hardened session cookie: HttpOnly, SameSite=Lax, Secure on HTTPS, strict mode.
   • One random 256-bit token per session, rotated every 2 hours (the previous token is
     still accepted for a grace period so open tabs don't break).
   • EVERY state-changing request (any POST/PUT/PATCH/DELETE) to this page — including the
     ones your own top PHP handles (follow, block, mute, vibe, avatar upload, notifications…)
     — must carry the token (X-CSRF-Token header or csrf_token field). Compared timing-safe.
   • Second layer: Sec-Fetch-Site / Origin / Referer must be same-site.
   • Front-end: fetch() and XMLHttpRequest are wrapped so the token is attached automatically
     to every same-origin write, forms are armed with a hidden field, and an expired token is
     silently refreshed and the request retried once.
   • Extra headers: clickjacking protection (X-Frame-Options), nosniff, referrer policy.
   Failed checks answer 403 and change nothing.
   ══════════════════════════════════════════════════════════════════════════ */
if (!defined('DZ_CSRF_LOADED')) {
    define('DZ_CSRF_LOADED', true);

    /* actions that must stay open to outside callers (e.g. payment webhooks). Keep empty unless needed. */
    $DZ_CSRF_EXEMPT_POST_KEYS = [];

    if (session_status() === PHP_SESSION_NONE) {
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.use_only_cookies', '1');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        @session_cache_limiter('');
        session_start();
    }

    if (!headers_sent()) {
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Cache-Control: private, no-cache, max-age=0');   // never let a shared cache keep a page with a token in it
    }

    function dz_csrf_token(): string {
        $now = time();
        $c   = $_SESSION['dz_csrf_pf'] ?? null;
        if (!is_array($c) || empty($c['t'])) {
            $c = ['t' => bin2hex(random_bytes(32)), 'p' => '', 'at' => $now];
        } elseif ($now - (int)$c['at'] > 7200) {                 // rotate every 2h, keep the old one for a grace period
            $c = ['t' => bin2hex(random_bytes(32)), 'p' => $c['t'], 'at' => $now];
        }
        $_SESSION['dz_csrf_pf'] = $c;
        return $c['t'];
    }
    function dz_csrf_field(): string {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(dz_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
    }
    function dz_csrf_same_origin(): bool {
        $sfs = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
        if ($sfs === 'cross-site') return false;                 // browser itself says it's another site
        $host = strtolower((string)parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
        foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $h) {
            if (!empty($_SERVER[$h])) {
                $src = strtolower((string)parse_url($_SERVER[$h], PHP_URL_HOST));
                return ($src !== '' && $src === $host);          // first header present decides
            }
        }
        return true;   // neither header sent (privacy tools) — the token still has to match
    }
    function dz_csrf_valid(): bool {
        $c = $_SESSION['dz_csrf_pf'] ?? null;
        if (!is_array($c) || empty($c['t'])) return false;
        $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
        if (!is_string($sent) || $sent === '' || strlen($sent) > 128) return false;
        $ok = hash_equals($c['t'], $sent) || (!empty($c['p']) && hash_equals($c['p'], $sent));
        return $ok && dz_csrf_same_origin();
    }
    function dz_csrf_wants_json(): bool {
        $mode = strtolower((string)($_SERVER['HTTP_SEC_FETCH_MODE'] ?? ''));
        return !empty($_SERVER['HTTP_X_CSRF_TOKEN'])
            || stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'json') !== false
            || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
            || ($mode !== '' && $mode !== 'navigate');
    }
    function dz_csrf_deny(): void {
        http_response_code(403);
        if (dz_csrf_wants_json()) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'msg' => 'csrf']);
        } else {
            header('Content-Type: text/html; charset=UTF-8');
            echo '<!DOCTYPE html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
               . '<body style="margin:0;background:#06071A;color:#fff;font-family:system-ui,sans-serif;display:flex;min-height:100vh;align-items:center;justify-content:center;text-align:center">'
               . '<div style="padding:24px"><p style="font-size:15px;font-weight:700;margin:0 0 6px">Your session expired</p>'
               . '<p style="font-size:13px;opacity:.6;margin:0 0 16px">For your security this request was blocked. Please refresh and try again.</p>'
               . '<a href="' . htmlspecialchars(strtok($_SERVER['REQUEST_URI'] ?? '', '#'), ENT_QUOTES, 'UTF-8') . '" style="color:#fff;background:#FF2D2D;padding:9px 22px;border-radius:4px;text-decoration:none;font-weight:700;font-size:13px">Refresh</a></div>';
        }
        exit;
    }

    $dz_csrf_token = dz_csrf_token();
    $dz_method     = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    /* token refresh endpoint (used by the front-end to recover from an expired token) */
    if ($dz_method === 'GET' && isset($_GET['csrf_refresh'])) {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        if (!dz_csrf_same_origin()) { http_response_code(403); echo json_encode(['status' => 'error']); exit; }
        echo json_encode(['status' => 'ok', 'token' => dz_csrf_token()]);
        exit;
    }

    /* the gate itself */
    if (!in_array($dz_method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        $dz_exempt = false;
        foreach ($DZ_CSRF_EXEMPT_POST_KEYS as $k) { if (isset($_POST[$k])) { $dz_exempt = true; break; } }
        if (!$dz_exempt && !dz_csrf_valid()) dz_csrf_deny();
    }
}

/* ══════════════════════════════════════════════════════════════════════════
   PROFILE TOP PHP  (everything below runs AFTER the CSRF gate, so every POST
   handler in this file is protected automatically)

   Order of this file:
     1. includes + empty caches
     2. identity / block / follow / privacy state
     3. AJAX + POST handlers (answered and exit BEFORE the heavy page queries)
     4. page queries (memes, reposts, tags, saved, messages)
     5. collections, unread counts, notification dot

   Already handled by the CSRF page below this block, so NOT repeated here:
     • schema setup, notification poll / mark-seen, vibe check, online ping
     • "Request to Track" / "Cancel request" handlers
     • visit counting for Insights (profile_views)
     • online status, live/active status, profile links/emails, highlights strip,
       topbar track vars, pending track requests, right-hand column
   ══════════════════════════════════════════════════════════════════════════ */
include 'config.php';

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
require_once 'offline.php';          /* shared No-internet / Retry state (same as home.php) */
require_once 'mute_restrict_api.php';

/* Empty caches so every AJAX-only path (col_action, get_connected_meme …) has valid arrays
   for the get_*() helpers defined in the page below. The page re-primes them later. */
$NICK_CACHE = [];
$FOLLOW_CACHE = [];
$SOCIAL_CACHE = [];
$MEDIA_CACHE = [];
$GROUP_CACHE = [];
$AUDIO_CACHE = [];
$COUNT_CACHE = [];
$INTERACTION_CACHE = [];
$REPOST_TS_CACHE = [];
$FAV_TS_CACHE = [];
$PRIMED_MEME_IDS = [];
$PRIMED_UPLOADER_IDS = [];

$current_user = (int)($_SESSION['user_id'] ?? 0);
if ($current_user) {
    $conn->query("UPDATE users SET last_active = NOW() WHERE id = $current_user");
}

/* collab posts, download switch and view insights (the three helper files sit next to this page) */
foreach (['dz_privacy.php', 'dz_collab.php', 'dz_views.php'] as $__dzf) {
    if (is_file(__DIR__ . '/' . $__dzf)) require_once __DIR__ . '/' . $__dzf;
}
unset($__dzf);
$user_id = $current_user;   /* the name the new handlers use for "who is logged in" */

/* collab data for a post: one batch query for the grid (primed below), a single lookup anywhere else */
$DZ_COLLAB_CACHE = [];
function dz_pf_collab_prime($conn, array $ids, $uid) {
    global $DZ_COLLAB_CACHE;
    if (!function_exists('dz_collab_payload')) return;
    $cache = $DZ_COLLAB_CACHE;
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($i) use ($cache) { return $i > 0 && !array_key_exists($i, $cache); })));
    if (!$ids) return;
    $map = dz_collab_payload($conn, $ids, (int)$uid);
    foreach ($ids as $i) $DZ_COLLAB_CACHE[$i] = $map[$i] ?? null;
}
function dz_pf_collab($conn, $mid, $uid) {
    global $DZ_COLLAB_CACHE;
    $mid = (int)$mid;
    if (!function_exists('dz_collab_payload')) return null;
    if (!array_key_exists($mid, $DZ_COLLAB_CACHE)) dz_pf_collab_prime($conn, [$mid], $uid);
    return $DZ_COLLAB_CACHE[$mid] ?? null;
}

/* poll data for the feed items: primed in one batch where the grid is built, single lookup anywhere else (same pattern as collab) */
$DZ_POLL_CACHE = [];
function dz_pf_poll_prime($conn, array $ids, $uid) {
    global $DZ_POLL_CACHE;
    $cache = $DZ_POLL_CACHE;
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($i) use ($cache) { return $i > 0 && !array_key_exists($i, $cache); })));
    if (!$ids) return;
    dz_poll_ensure($conn);
    $map = dz_polls_for_memes($conn, (int)$uid, $ids);
    foreach ($ids as $i) $DZ_POLL_CACHE[$i] = $map[$i] ?? null;
}
function dz_pf_poll($conn, $mid, $uid) {
    global $DZ_POLL_CACHE;
    $mid = (int)$mid;
    if (!array_key_exists($mid, $DZ_POLL_CACHE)) dz_pf_poll_prime($conn, [$mid], $uid);
    return $DZ_POLL_CACHE[$mid] ?? null;
}

/* ── archived posts + deactivated accounts stay out of every list ──
   memes.is_archived and users.deactivated_at come from the Settings page. Each fragment is only added when its column
   exists, so this page keeps working even before the SQL has been run. m = memes alias, u = users alias in the queries. */
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
$DZ_NOT_ARCH    = dz_has_col($conn, 'memes', 'is_archived')      ? ' AND m.is_archived = 0'        : '';
$DZ_NOT_DEACT_U = dz_has_col($conn, 'users',  'deactivated_at')  ? ' AND u.deactivated_at IS NULL' : '';
$DZ_DEACT_SEL   = dz_has_col($conn, 'users',  'deactivated_at')  ? ', u.deactivated_at'            : '';

/* ?username=foo → redirect to that user's profile */
if (!empty($_GET['username'])) {
    $uname_q = (string)$_GET['username'];
    $st = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
    $st->bind_param("s", $uname_q);
    $st->execute();
    $found = $st->get_result()->fetch_assoc();
    $st->close();
    header($found ? "Location: profile.php?user_id=" . (int)$found['id'] : "Location: index.php");
    exit();
}

/* logged-in user's own picture for the nav */
$nav_profile_pic = 'mee.jpg';
if ($current_user) {
    $nav_res = $conn->query("SELECT profile_pic FROM users WHERE id = $current_user LIMIT 1");
    if ($nav_res && $nav_row = $nav_res->fetch_assoc()) {
        $nav_profile_pic = !empty($nav_row['profile_pic']) ? $nav_row['profile_pic'] : 'mee.jpg';
    }
}

/* ── JSON data feeds: ?type=ads | sugg | following ── */
if (isset($_GET['type']) && $_GET['type'] !== 'poll') {   /* type=poll is answered below, once dz_meme_visible() exists */
    $type = $_GET['type'];
    header('Content-Type: application/json');

    if ($type === 'ads') {
        $ads = [];
        $res = $conn->query("SELECT * FROM sponsorships WHERE status='approved' ORDER BY RAND() LIMIT 10");
        while ($row = $res->fetch_assoc()) { $ads[] = $row; }
        echo json_encode($ads);

    } elseif ($type === 'sugg') {
        $sugg = [];
        if ($current_user) {
            $res = $conn->query("
                SELECT u.id, u.username, u.profile_pic,
                (SELECT COUNT(*) FROM followers f WHERE f.follower_id=$current_user AND f.following_id=u.id) as is_following
                FROM users u
                WHERE u.id != $current_user $DZ_NOT_DEACT_U
                ORDER BY RAND()
                LIMIT 20
            ");
            while ($row = $res->fetch_assoc()) { $sugg[] = $row; }
        }
        echo json_encode($sugg);

    } elseif ($type === 'following') {
        /* powers the Share Modal's "share with people you track" list */
        $following_list = [];
        if ($current_user) {
            $stmt = $conn->prepare("
                SELECT u.id, u.username, u.profile_pic
                FROM followers f
                JOIN users u ON u.id = f.following_id
                WHERE f.follower_id = ? $DZ_NOT_DEACT_U
                ORDER BY u.username ASC
            ");
            $stmt->bind_param("i", $current_user);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) { $following_list[] = $row; }
            $stmt->close();
        }
        echo json_encode($following_list);

    } else {
        echo json_encode([]);
    }
    exit;
}

/* ── TOGGLE FOLLOW (AJAX) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_follow') {
    header('Content-Type: application/json');
    $target_id = (int)($_POST['user_id'] ?? 0);
    if (!$current_user || !$target_id || $target_id === $current_user) { echo json_encode(['status' => 'error']); exit; }
    $stmt = $conn->prepare("SELECT id FROM followers WHERE follower_id=? AND following_id=?");
    $stmt->bind_param("ii", $current_user, $target_id);
    $stmt->execute();
    $already = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    if ($already) {
        $stmt = $conn->prepare("DELETE FROM followers WHERE follower_id=? AND following_id=?");
        $stmt->bind_param("ii", $current_user, $target_id);
        $stmt->execute();
        $stmt->close();

        /* clear the track request so a private wall shows correctly again */
        $stmt2 = $conn->prepare("DELETE FROM track_requests WHERE requester_id=? AND target_id=?");
        $stmt2->bind_param("ii", $current_user, $target_id);
        $stmt2->execute();
        $stmt2->close();

        echo json_encode(['status' => 'ok', 'following' => false]);
    } else {
    /* can't track someone who blocked you / whom you blocked */
    $__bk = $conn->prepare("SELECT 1 FROM blocks WHERE (blocker_id=? AND blocked_id=?) OR (blocker_id=? AND blocked_id=?) LIMIT 1");
    $__bk->bind_param("iiii", $current_user, $target_id, $target_id, $current_user); $__bk->execute();
    $__bkd = (bool)$__bk->get_result()->fetch_row(); $__bk->close();
    if ($__bkd) { echo json_encode(['status' => 'error']); exit; }

        $stmt = $conn->prepare("INSERT INTO followers (follower_id, following_id) VALUES (?,?)");
        $stmt->bind_param("ii", $current_user, $target_id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['status' => 'ok', 'following' => true]);
    }
    exit;
}

/* ── PROFILE CTA PILL (save / remove) — one pill per user, only while the profile is Public or Limited ── */
if (!function_exists('dz_cta_colors')) {
    function dz_cta_colors() {   // key => [background, text colour, name]
        return [
            'red'     => ['#FF2D2D', '#ffffff', 'Red'],
            'crimson' => ['#B3122B', '#ffffff', 'Crimson'],
            'coral'   => ['#FF6B5B', '#ffffff', 'Coral'],
            'orange'  => ['#F07A1A', '#ffffff', 'Orange'],
            'amber'   => ['#F5B623', '#1a1300', 'Amber'],
            'gold'    => ['#9A8431', '#ffffff', 'Gold'],
            'olive'   => ['#66742B', '#ffffff', 'Olive'],
            'lime'    => ['#9BD13B', '#101a00', 'Lime'],
            'green'   => ['#1FA45B', '#ffffff', 'Green'],
            'mint'    => ['#3EDDB0', '#06231a', 'Mint'],
            'teal'    => ['#14A3A3', '#ffffff', 'Teal'],
            'sky'     => ['#4CBDFF', '#04202f', 'Sky'],
            'blue'    => ['#3B6EF6', '#ffffff', 'Blue'],
            'navy'    => ['#1B2A6B', '#ffffff', 'Navy'],
            'indigo'  => ['#4338CA', '#ffffff', 'Indigo'],
            'purple'  => ['#7C4DFF', '#ffffff', 'Purple'],
            'violet'  => ['#A855F7', '#ffffff', 'Violet'],
            'magenta' => ['#C026D3', '#ffffff', 'Magenta'],
            'pink'    => ['#E8458B', '#ffffff', 'Pink'],
            'brown'   => ['#7A4B2A', '#ffffff', 'Brown'],
            'grey'    => ['#3A3F52', '#ffffff', 'Grey'],
            'black'   => ['#0B0B12', '#ffffff', 'Black'],
            'white'   => ['#F2F2F2', '#111111', 'White'],
        ];
    }
}
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cta_action'])) {
    header('Content-Type: application/json');
    $cta_uid = (int)$current_user;
    if ($cta_uid <= 0) { echo json_encode(['status' => 'error', 'message' => 'Log in first']); exit; }
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS profile_cta (
            user_id INT NOT NULL PRIMARY KEY,
            label VARCHAR(60) NOT NULL,
            url VARCHAR(500) NOT NULL,
            color VARCHAR(16) NOT NULL DEFAULT 'blue',
            pic VARCHAR(255) NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pc = $conn->query("SHOW COLUMNS FROM profile_cta LIKE 'pic'");
        if ($pc instanceof mysqli_result && $pc->num_rows === 0) { try { $conn->query("ALTER TABLE profile_cta ADD COLUMN pic VARCHAR(255) NULL AFTER color"); } catch (Throwable $e) {} }
        $pc2 = $conn->query("SHOW COLUMNS FROM profile_cta LIKE 'pic'");
        $has_pic_col = ($pc2 instanceof mysqli_result && $pc2->num_rows > 0);

        $existing_pic = '';
        if ($has_pic_col) {
            $er = $conn->query("SELECT pic FROM profile_cta WHERE user_id = $cta_uid LIMIT 1");
            if ($er instanceof mysqli_result && ($erw = $er->fetch_assoc())) $existing_pic = (string)($erw['pic'] ?? '');
        }
        $own_pic_rx = '#^uploads/cta_' . $cta_uid . '_\d+\.(png|jpg)$#';

        if ($_POST['cta_action'] === 'delete') {
            if ($existing_pic !== '' && preg_match($own_pic_rx, $existing_pic)) @unlink(__DIR__ . '/' . $existing_pic);
            $d = $conn->prepare("DELETE FROM profile_cta WHERE user_id = ?");
            $d->bind_param('i', $cta_uid); $d->execute(); $d->close();
            echo json_encode(['status' => 'ok']); exit;
        }

        if ($_POST['cta_action'] === 'save') {
            if (!in_array(dz_cta_level($conn, $cta_uid), ['public', 'limited'], true)) {
                echo json_encode(['status' => 'error', 'message' => 'Your profile must be Public or Limited to have a CTA pill']); exit;
            }
            $label = trim(preg_replace('/\s+/', ' ', strip_tags((string)($_POST['label'] ?? ''))));
            $label = function_exists('mb_substr') ? mb_substr($label, 0, 30) : substr($label, 0, 30);
            if ($label === '') { echo json_encode(['status' => 'error', 'message' => 'Write what the pill should say']); exit; }

            $url = trim((string)($_POST['url'] ?? ''));
            if ($url !== '' && !preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url)) $url = 'https://' . $url;
            $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
            $host   = (string)parse_url($url, PHP_URL_HOST);
            if ($url === '' || strlen($url) > 500 || !filter_var($url, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true) || $host === '') {
                echo json_encode(['status' => 'error', 'message' => 'Enter a valid link (https://…)']); exit;
            }

            $color = (string)($_POST['color'] ?? 'blue');
            if (!isset(dz_cta_colors()[$color])) $color = 'blue';

            /* optional picture shown beside the text */
            $new_pic = $existing_pic !== '' ? $existing_pic : null;
            if (!empty($_POST['remove_pic'])) $new_pic = null;
            if (!empty($_FILES['pic']) && (int)$_FILES['pic']['error'] !== UPLOAD_ERR_NO_FILE && (int)$_FILES['pic']['error'] !== UPLOAD_ERR_OK) {
                $ue = (int)$_FILES['pic']['error'];
                $um = ($ue === UPLOAD_ERR_INI_SIZE || $ue === UPLOAD_ERR_FORM_SIZE) ? 'Picture is bigger than the server allows — try a smaller one' : 'The picture did not upload (error ' . $ue . ') — try again';
                echo json_encode(['status' => 'error', 'message' => $um]); exit;
            }
            if (!empty($_FILES['pic']) && (int)$_FILES['pic']['error'] === UPLOAD_ERR_OK && is_uploaded_file($_FILES['pic']['tmp_name'])) {
                if (!$has_pic_col) { echo json_encode(['status' => 'error', 'message' => 'Database needs the picture column — run: ALTER TABLE profile_cta ADD COLUMN pic VARCHAR(255) NULL AFTER color;']); exit; }
                if ($_FILES['pic']['size'] > 4 * 1024 * 1024) { echo json_encode(['status' => 'error', 'message' => 'Picture is too big (max 4 MB)']); exit; }
                $info = @getimagesize($_FILES['pic']['tmp_name']);
                $okTypes = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];
                if (!$info || !in_array($info[2], $okTypes, true)) { echo json_encode(['status' => 'error', 'message' => 'Choose a JPG, PNG, WEBP or GIF picture']); exit; }
                $dir = __DIR__ . '/uploads';
                if (!is_dir($dir) || !is_writable($dir)) { echo json_encode(['status' => 'error', 'message' => 'Could not store the picture (uploads folder)']); exit; }
                $usePng = ($info[2] !== IMAGETYPE_JPEG);
                $rel    = 'uploads/cta_' . $cta_uid . '_' . time() . ($usePng ? '.png' : '.jpg');
                $saved  = false;
                if (function_exists('imagecreatefromstring')) {
                    $src = @imagecreatefromstring((string)file_get_contents($_FILES['pic']['tmp_name']));
                    if ($src) {
                        $w = imagesx($src); $h = imagesy($src); $side = min($w, $h);
                        $dst = imagecreatetruecolor(240, 240);
                        if ($usePng) { imagealphablending($dst, false); imagesavealpha($dst, true); imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127)); }
                        imagecopyresampled($dst, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), 240, 240, $side, $side);
                        $saved = $usePng ? imagepng($dst, __DIR__ . '/' . $rel, 6) : imagejpeg($dst, __DIR__ . '/' . $rel, 86);
                        imagedestroy($src); imagedestroy($dst);
                    }
                }
                if (!$saved && !function_exists('imagecreatefromstring')) {   // no image library on the server: keep the (already small) upload as it is
                    $rel   = 'uploads/cta_' . $cta_uid . '_' . time() . ($info[2] === IMAGETYPE_JPEG ? '.jpg' : '.png');
                    $saved = move_uploaded_file($_FILES['pic']['tmp_name'], __DIR__ . '/' . $rel);
                }
                if (!$saved) { echo json_encode(['status' => 'error', 'message' => 'Could not read that picture — try another one']); exit; }
                $new_pic = $rel;
            }
            if ($existing_pic !== '' && $existing_pic !== $new_pic && preg_match($own_pic_rx, $existing_pic)) @unlink(__DIR__ . '/' . $existing_pic);

            if ($has_pic_col) {
                $sv = $conn->prepare("INSERT INTO profile_cta (user_id, label, url, color, pic) VALUES (?,?,?,?,?)
                                      ON DUPLICATE KEY UPDATE label = VALUES(label), url = VALUES(url), color = VALUES(color), pic = VALUES(pic)");
                $sv->bind_param('issss', $cta_uid, $label, $url, $color, $new_pic);
            } else {
                $new_pic = null;
                $sv = $conn->prepare("INSERT INTO profile_cta (user_id, label, url, color) VALUES (?,?,?,?)
                                      ON DUPLICATE KEY UPDATE label = VALUES(label), url = VALUES(url), color = VALUES(color)");
                $sv->bind_param('isss', $cta_uid, $label, $url, $color);
            }
            $sv->execute(); $sv->close();
            echo json_encode(['status' => 'ok', 'label' => $label, 'url' => $url, 'color' => $color, 'pic' => $new_pic]); exit;
        }
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'message' => 'Could not save — try again']); exit;
    }
    echo json_encode(['status' => 'error']); exit;
}

/* collab requests + post menu actions for the owner (View insights, turn downloads on/off).
   These sit AFTER the CSRF gate on purpose: they change data, so they stay token-protected (the page's fetch adds the token). */
if (function_exists('dz_collab_handle_request')) dz_collab_handle_request($conn, (int)$user_id);

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

/* ── views seen on this page: the grid reports the cards that were really on screen (>= 60% for 1 s), same rules as the feed (dz_views.php) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dz_pf_views'])) {
    header('Content-Type: application/json');
    if (session_status() === PHP_SESSION_ACTIVE) { @session_write_close(); }   /* don't hold the session lock */
    $pv_ids = [];
    foreach (explode(',', (string)($_POST['ids'] ?? '')) as $pv_x) { $pv_x = (int)$pv_x; if ($pv_x > 0) $pv_ids[$pv_x] = $pv_x; }
    $pv_ids = array_slice(array_values($pv_ids), 0, 50);
    $pv_out = [];
    try {
        if ($pv_ids && is_file(__DIR__ . '/dz_views.php')) {
            require_once __DIR__ . '/dz_views.php';
            dz_record_views($conn, (int)$current_user, $pv_ids, 'profile');
            $pv_out = dz_view_counts($conn, $pv_ids);
        }
    } catch (Throwable $e) {}
    echo json_encode(['status' => 'ok', 'views' => (object)$pv_out]);
    exit;
}

/* ── hide / show my "Untracked" button (saved on the account so it follows you across devices) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dz_loc_vis'])) {
    header('Content-Type: application/json');
    $lv_uid = (int)$current_user;
    if ($lv_uid <= 0) { echo json_encode(['status' => 'error']); exit; }
    try {
        $c = $conn->query("SHOW COLUMNS FROM users LIKE 'hide_location'");
        if ($c instanceof mysqli_result && $c->num_rows === 0) { try { $conn->query("ALTER TABLE users ADD COLUMN hide_location TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {} }
        $v = ($_POST['dz_loc_vis'] === '1') ? 0 : 1;   // dz_loc_vis=1 → show on profile (default), 0 → hide
        $st = $conn->prepare("UPDATE users SET hide_location = ? WHERE id = ?");
        $st->bind_param('ii', $v, $lv_uid); $st->execute(); $st->close();
        echo json_encode(['status' => 'ok', 'show' => $v ? 0 : 1]);
    } catch (Throwable $e) { echo json_encode(['status' => 'error', 'message' => 'Could not save']); }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['untr_hide'])) {
    header('Content-Type: application/json');
    $uh_uid = (int)$current_user;
    if ($uh_uid <= 0) { echo json_encode(['status' => 'error']); exit; }
    try {
        $c = $conn->query("SHOW COLUMNS FROM users LIKE 'hide_untracked_btn'");
        if ($c instanceof mysqli_result && $c->num_rows === 0) { try { $conn->query("ALTER TABLE users ADD COLUMN hide_untracked_btn TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {} }
        $v = !empty($_POST['untr_hide']) && $_POST['untr_hide'] !== '0' ? 1 : 0;
        $st = $conn->prepare("UPDATE users SET hide_untracked_btn = ? WHERE id = ?");
        $st->bind_param('ii', $v, $uh_uid); $st->execute(); $st->close();
        echo json_encode(['status' => 'ok', 'hidden' => $v]);
    } catch (Throwable $e) { echo json_encode(['status' => 'error', 'message' => 'Could not save']); }
    exit;
}

/* ── VISITOR TOOLS: first-visit note, guest book, legacy contact, translate ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fvx_action'])) {
    header('Content-Type: application/json');
    $fx_uid = (int)$current_user;
    $fx_act = (string)$_POST['fvx_action'];
    $fx_out = function ($a) { echo json_encode($a); exit; };
    if ($fx_uid <= 0) $fx_out(['status' => 'error', 'message' => 'Log in first']);
    $fx_str = function ($s, $max) {
        $s = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$s)));
        return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
    };
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS guestbook_entries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            owner_id INT NOT NULL,
            author_id INT NOT NULL,
            message VARCHAR(120) NOT NULL,
            status ENUM('pending','approved') NOT NULL DEFAULT 'pending',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_gb (owner_id, author_id),
            KEY idx_gb_owner (owner_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        if ($fx_act === 'gb_sign') {
            $oid = (int)($_POST['owner_id'] ?? 0);
            $msg = $fx_str($_POST['message'] ?? '', 100);
            if ($oid <= 0 || $oid === $fx_uid) $fx_out(['status' => 'error', 'message' => 'You cannot sign your own guest book']);
            if ($msg === '') $fx_out(['status' => 'error', 'message' => 'Write one line first']);
            $b = $conn->prepare("SELECT 1 FROM blocks WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?) LIMIT 1");
            $b->bind_param('iiii', $fx_uid, $oid, $oid, $fx_uid); $b->execute(); $b->store_result();
            if ($b->num_rows > 0) { $b->close(); $fx_out(['status' => 'error', 'message' => 'You cannot sign this guest book']); }
            $b->close();
            if (!in_array(dz_cta_level($conn, $oid), ['public', 'limited'], true)) {
                $f = $conn->prepare("SELECT 1 FROM followers WHERE follower_id = ? AND following_id = ? LIMIT 1");
                $f->bind_param('ii', $fx_uid, $oid); $f->execute(); $f->store_result();
                $ok = $f->num_rows > 0; $f->close();
                if (!$ok) $fx_out(['status' => 'error', 'message' => 'Track this person to sign their guest book']);
            }
            $s = $conn->prepare("INSERT INTO guestbook_entries (owner_id, author_id, message, status) VALUES (?,?,?,'pending')
                                 ON DUPLICATE KEY UPDATE message = VALUES(message), status = 'pending', created_at = NOW()");
            $s->bind_param('iis', $oid, $fx_uid, $msg); $s->execute(); $s->close();
            $fx_out(['status' => 'ok', 'message' => $msg, 'pending' => 1]);
        }

        if ($fx_act === 'gb_mine_del') {
            $oid = (int)($_POST['owner_id'] ?? 0);
            $d = $conn->prepare("DELETE FROM guestbook_entries WHERE owner_id = ? AND author_id = ?"); $d->bind_param('ii', $oid, $fx_uid); $d->execute(); $d->close();
            $fx_out(['status' => 'ok']);
        }

        if ($fx_act === 'gb_list') {   // owner: everything waiting + approved
            $rows = [];
            $q = $conn->prepare("SELECT g.id, g.message, g.status, u.username, u.profile_pic FROM guestbook_entries g JOIN users u ON u.id = g.author_id WHERE g.owner_id = ? ORDER BY (g.status = 'pending') DESC, g.created_at DESC LIMIT 100");
            $q->bind_param('i', $fx_uid); $q->execute(); $r = $q->get_result();
            while ($row = $r->fetch_assoc()) $rows[] = ['id' => (int)$row['id'], 'message' => $row['message'], 'status' => $row['status'], 'username' => $row['username'], 'pic' => $row['profile_pic']];
            $q->close();
            $fx_out(['status' => 'ok', 'rows' => $rows]);
        }

        if ($fx_act === 'gb_mod') {
            $id = (int)($_POST['id'] ?? 0); $do = (string)($_POST['do'] ?? '');
            if ($do === 'approve') { $s = $conn->prepare("UPDATE guestbook_entries SET status = 'approved' WHERE id = ? AND owner_id = ?"); }
            else                   { $s = $conn->prepare("DELETE FROM guestbook_entries WHERE id = ? AND (owner_id = ? OR author_id = ?)"); $s->bind_param('iii', $id, $fx_uid, $fx_uid); $s->execute(); $s->close(); $fx_out(['status' => 'ok']); }
            $s->bind_param('ii', $id, $fx_uid); $s->execute(); $s->close();
            $fx_out(['status' => 'ok']);
        }

    } catch (Throwable $e) {
        $fx_out(['status' => 'error', 'message' => 'Something went wrong — try again']);
    }
    $fx_out(['status' => 'error']);
}

/* ── ACCOUNT SWITCHER ── */
if ($current_user) {
    $_SESSION['accounts'] = [$current_user];
    $linked = $conn->query("SELECT linked_user_id FROM linked_accounts WHERE primary_user_id = $current_user");
    while ($row = $linked->fetch_assoc()) {
        $_SESSION['accounts'][] = (int)$row['linked_user_id'];
    }
    $_SESSION['accounts'] = array_values(array_unique($_SESSION['accounts']));
}

/* ── whose profile is this? ── */
$profile_id = (int)($_GET['user_id'] ?? 0) ?: $current_user;

/* ── VIEW AS — the owner previews their own profile the way another Dezzmer sees it (GET only, look-only).
   The page below is built for a signed-in stranger: not tracking, not blocked, not the owner. Nothing here
   writes anything; the page's own JS also stops every click that isn't browsing (see the VIEW AS script at the bottom). ── */
$dz_real_user = $current_user;
$dz_view_as   = false;
$dz_va_tracker = false;
if ($current_user && isset($_GET['view_as']) && strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && $profile_id === $current_user) {
    $dz_view_as   = true;
    $dz_va_tracker = (strtolower((string)$_GET['view_as']) === 'tracker');   // ?view_as=tracker → as someone who tracks you · anything else → as someone who doesn't
    $current_user = -1;   // nobody: every "is this me / do I track them / am I blocked" check answers like a stranger
}
if (!$profile_id) {
    header("Location: login.php");
    exit();
}

/* ── blocks (ONE query, both directions) ──
   $is_blocked_by_owner = the profile owner blocked me
   $is_blocked          = I blocked the profile owner
   $blocked_check       = same as $is_blocked (the page below reuses it, so it skips its own query) */
$is_blocked_by_owner = false;
$is_blocked          = false;
if ($current_user && $current_user != $profile_id) {
    $st = $conn->prepare("SELECT blocker_id FROM blocks WHERE (blocker_id=? AND blocked_id=?) OR (blocker_id=? AND blocked_id=?)");
    $st->bind_param("iiii", $profile_id, $current_user, $current_user, $profile_id);
    $st->execute();
    $br = $st->get_result();
    while ($b = $br->fetch_assoc()) {
        if ((int)$b['blocker_id'] === $profile_id)   $is_blocked_by_owner = true;
        if ((int)$b['blocker_id'] === $current_user) $is_blocked = true;
    }
    $st->close();
}
$blocked_check = $is_blocked;

/* ── muted / restricted by me ── */
$is_muted_by_me      = false;
$is_restricted_by_me = false;
if ($current_user && $current_user != $profile_id) {
    $s_mu = $conn->prepare("SELECT id FROM muted_users WHERE muter_id=? AND muted_id=?");
    $s_mu->bind_param("ii", $current_user, $profile_id);
    $s_mu->execute();
    $is_muted_by_me = $s_mu->get_result()->num_rows > 0;
    $s_mu->close();

    $s_re = $conn->prepare("SELECT id FROM restricted_users WHERE restrictor_id=? AND restricted_id=?");
    $s_re->bind_param("ii", $current_user, $profile_id);
    $s_re->execute();
    $is_restricted_by_me = $s_re->get_result()->num_rows > 0;
    $s_re->close();
}

/* ── profile header info (+ privacy flag, so no separate user_settings query) ── */
$stmt = $conn->prepare("
    SELECT u.username, u.nickname, u.profile_pic, u.is_verified, u.user_rank$DZ_DEACT_SEL,
           s.bio, s.pronouns, s.activity_vibe, s.user_type, s.is_fully_private
    FROM users u
    LEFT JOIN user_settings s ON s.user_id = u.id
    WHERE u.id = ?
");
$stmt->bind_param("i", $profile_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {            // profile doesn't exist
    header("Location: index.php");
    exit();
}

/* deactivated account: nobody but the account itself (which turns it back on at login) can open the profile */
if (!empty($user['deactivated_at']) && (int)$profile_id !== (int)$current_user) {
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Account not available</title>'
       . '<body style="margin:0;background:var(--ink,#06071A);color:#fff;font-family:system-ui,sans-serif;display:flex;min-height:100vh;align-items:center;justify-content:center;text-align:center">'
       . '<div style="padding:24px"><p style="font-size:15px;font-weight:700;margin:0 0 6px">This account isn\'t available</p>'
       . '<p style="font-size:13px;opacity:.6;margin:0 0 16px">It may have been deactivated.</p>'
       . '<a href="index.php" style="color:#fff;background:#FF2D2D;padding:9px 22px;border-radius:4px;text-decoration:none;font-weight:700;font-size:13px">Go home</a></div>';
    exit();
}

/* ══════════════ SUSPENDED ACCOUNT (an admin pressed Suspend in the Reports Center) ══════════════
   The profile keeps its normal layout, but: grey picture, only username + nickname, no stats / bio / buttons / tabs / posts,
   the Menu button does nothing, and a notice sits where the content would be.
   • other people: "This account has been suspended" (it didn't follow the Community Guidelines)
   • the account's owner: why, what it means, days left to appeal, Appeal button → "You submitted an appeal on …"
   • admins: the same plus Unsuspend (?admin_peek=1 opens the normal profile)
   Needs users.user_rank ('Suspended' is written by report_center.php). users.suspended_at and account_appeals create themselves. */
$DZ_SUSP = null;
if (strtolower((string)($user['user_rank'] ?? '')) === 'suspended') {
    $dz_sp_viewer_rank = '';
    if ($current_user) {
        $__q = $conn->prepare("SELECT user_rank FROM users WHERE id=? LIMIT 1");
        $__q->bind_param("i", $current_user); $__q->execute();
        $dz_sp_viewer_rank = strtolower((string)(($__q->get_result()->fetch_assoc()['user_rank'] ?? '')));
        $__q->close();
    }
    $dz_sp_is_admin = in_array($dz_sp_viewer_rank, ['admin', 'super_admin'], true);

    if (!$dz_sp_is_admin || empty($_GET['admin_peek'])) {
        if (!dz_has_col($conn, 'users', 'suspended_at')) { @$conn->query("ALTER TABLE users ADD COLUMN suspended_at DATETIME NULL DEFAULT NULL"); }
        @$conn->query("CREATE TABLE IF NOT EXISTS account_appeals (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, message VARCHAR(1000) NOT NULL DEFAULT '', status VARCHAR(20) NOT NULL DEFAULT 'pending', admin_notes TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_appeal_user (user_id, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $dz_sp_at = null;
        $__q = @$conn->query("SELECT suspended_at FROM users WHERE id=" . (int)$profile_id . " LIMIT 1");
        if ($__q instanceof mysqli_result) { $dz_sp_at = ($__q->fetch_assoc()['suspended_at'] ?? null) ?: null; }
        $dz_sp_ts   = $dz_sp_at ? strtotime($dz_sp_at) : 0;
        $dz_sp_left = $dz_sp_ts ? max(0, 180 - (int)floor((time() - $dz_sp_ts) / 86400)) : 180;
        $dz_sp_own  = ($current_user && (int)$profile_id === (int)$current_user);

        $dz_sp_appeal = null;
        if ($dz_sp_own) {
            $__q = $conn->prepare("SELECT status, created_at FROM account_appeals WHERE user_id=? ORDER BY id DESC LIMIT 1");
            $__q->bind_param("i", $current_user); $__q->execute();
            $dz_sp_appeal = $__q->get_result()->fetch_assoc(); $__q->close();
        }
        $dz_sp_rejected = $dz_sp_appeal && $dz_sp_appeal['status'] === 'rejected';
        $dz_sp_pending  = $dz_sp_appeal && $dz_sp_appeal['status'] === 'pending';
        $dz_sp_expired  = !$dz_sp_pending && $dz_sp_left <= 0;

        /* submit an appeal (CSRF is already checked by the guard at the top of this file) */
        if ($dz_sp_own && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'submit_appeal') {
            header('Content-Type: application/json');
            if ($dz_sp_pending || $dz_sp_rejected || $dz_sp_expired) { echo json_encode(['ok' => false, 'error' => 'You can\'t appeal this suspension.']); exit; }
            $msg = trim((string)($_POST['message'] ?? ''));
            $msg = function_exists('mb_substr') ? mb_substr($msg, 0, 1000) : substr($msg, 0, 1000);
            $__q = $conn->prepare("INSERT INTO account_appeals (user_id, message) VALUES (?, ?)");
            $__q->bind_param("is", $current_user, $msg); $ok = $__q->execute(); $__q->close();
            echo json_encode(['ok' => (bool)$ok]);
            exit;
        }

        $DZ_SUSP = [
            'own' => $dz_sp_own, 'admin' => $dz_sp_is_admin, 'ts' => $dz_sp_ts, 'left' => $dz_sp_left,
            'pending' => (bool)$dz_sp_pending, 'rejected' => (bool)$dz_sp_rejected, 'expired' => (bool)$dz_sp_expired,
            'appeal_at' => $dz_sp_appeal['created_at'] ?? null,
        ];
    }
}

$display_name       = $user['nickname'] ?: $user['username'];
$activity_vibe      = $user['activity_vibe'] ?? '';
$profile_is_private = !empty($user['is_fully_private']);

/* ── tracking state + counts ── */
$follow_check    = false;
$followers_count = 0;
$following_count = 0;

if ($current_user) {
    $stmt = $conn->prepare("SELECT id FROM followers WHERE follower_id=? AND following_id=?");
    $stmt->bind_param("ii", $current_user, $profile_id);
    $stmt->execute();
    $follow_check = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    $cnt = $conn->query("SELECT
            (SELECT COUNT(*) FROM followers WHERE following_id=$profile_id) AS fr,
            (SELECT COUNT(*) FROM followers WHERE follower_id=$profile_id)  AS fg")->fetch_assoc();
    $followers_count = (int)($cnt['fr'] ?? 0);
    $following_count = (int)($cnt['fg'] ?? 0);
}

if (!empty($dz_va_tracker)) $follow_check = true;   // View As → "someone who tracks you": everything downstream (privacy wall, counts, Message, Shop Now…) reads this
$is_owner = ($current_user == $profile_id);
$can_view = $is_owner || $follow_check;
/* Long form modal runs inside this page (Videos tab) — needs profile_lf_embed.php + longform_modal.php next to this file */
if (is_file(__DIR__ . '/profile_lf_embed.php') && is_file(__DIR__ . '/longform_modal.php')) { require __DIR__ . '/profile_lf_embed.php'; }

/* my pending / approved / denied track request to this (private) profile.
   The page's topbar + Track buttons read this to show "Requested" after a reload. */
$track_request_status = null;
if ($current_user && !$is_owner) {
    $st = $conn->prepare("SELECT status FROM track_requests WHERE requester_id=? AND target_id=? LIMIT 1");
    $st->bind_param("ii", $current_user, $profile_id);
    $st->execute();
    $trr = $st->get_result()->fetch_assoc();
    $st->close();
    $track_request_status = $trr ? $trr['status'] : null;
}

/* ── small helpers (guarded so a re-include can't redeclare them) ── */
if (!function_exists('get_likes')) {
    function get_likes($conn, $meme_id) {
        $meme_id = (int)$meme_id;
        $res = $conn->query("SELECT COUNT(*) as cnt FROM likes WHERE meme_id=$meme_id");
        $row = $res->fetch_assoc();
        return $row['cnt'] ?? 0;
    }
}
if (!function_exists('user_liked')) {
    function user_liked($conn, $meme_id, $user_id) {
        if (!$user_id) return false;
        $meme_id = (int)$meme_id; $user_id = (int)$user_id;
        $res = $conn->query("SELECT id FROM likes WHERE meme_id=$meme_id AND user_id=$user_id");
        return ($res && $res->num_rows > 0);
    }
}
/* the owner's collections (all) or a visitor's view (public only) — ONE query for every use */
if (!function_exists('dz_collections_rows')) {
    function dz_collections_rows($conn, $owner_id, $public_only) {
        $owner_id = (int)$owner_id;
        $where    = "c.user_id=$owner_id" . ($public_only ? " AND c.is_public=1" : "");
        $rows = [];
        $res = $conn->query("
            SELECT c.id, c.name, COALESCE(c.is_public,0) AS is_public,
                   UNIX_TIMESTAMP(c.created_at) AS created_at,
                   (SELECT COUNT(*) FROM collection_items ci WHERE ci.collection_id=c.id) AS item_count,
                   (SELECT UNIX_TIMESTAMP(MAX(ci.added_at)) FROM collection_items ci WHERE ci.collection_id=c.id) AS latest_addition
            FROM collections c
            WHERE $where
            ORDER BY c.created_at DESC
        ");
        if ($res) { while ($r = $res->fetch_assoc()) $rows[] = $r; }
        /* cover = the last post added to each collection (newest first) */
        try {
            $tq = $conn->query("
                SELECT c.id,
                       (SELECT m.file_name FROM collection_items ci JOIN memes m ON m.id=ci.meme_id WHERE ci.collection_id=c.id ORDER BY ci.added_at DESC, ci.id DESC LIMIT 1) AS tf,
                       (SELECT m.type      FROM collection_items ci JOIN memes m ON m.id=ci.meme_id WHERE ci.collection_id=c.id ORDER BY ci.added_at DESC, ci.id DESC LIMIT 1) AS tt
                FROM collections c WHERE $where");
            $tmap = [];
            if ($tq instanceof mysqli_result) { while ($t = $tq->fetch_assoc()) $tmap[(int)$t['id']] = $t; }
            foreach ($rows as &$rr) {
                $t = $tmap[(int)$rr['id']] ?? null;
                $rr['thumb'] = null; $rr['thumb_type'] = '';
                if ($t && ($t['tf'] !== null || $t['tt'] === 'text')) {
                    $tt = (string)$t['tt'];
                    $tf = (string)$t['tf'];
                    if ($tt === 'text' || $tf === '' || $tf === '0') { $rr['thumb'] = 'uploads/Text.jpg'; $rr['thumb_type'] = 'text'; }
                    else { $rr['thumb'] = 'uploads/' . $tf; $rr['thumb_type'] = ($tt === 'video') ? 'video' : 'image'; }
                }
            }
            unset($rr);
        } catch (Throwable $e) {}
        return $rows;
    }
}

/* ══════════════════════════════════════════════════════════════
   AJAX HANDLERS — answered here and exit, BEFORE the page queries
   run, so a like / repost / save no longer waits for the whole page.
   (All of them are CSRF-checked by the gate at the top of this file.)
   ══════════════════════════════════════════════════════════════ */

/* the shared modal (zeems_modal.php) names its actions toggle_like / toggle_repost / toggle_favorite; this page's own handlers below call them like / repost / fav */
if (isset($_POST['action'])) {
    $__dz_alias = ['toggle_like' => 'like', 'toggle_repost' => 'repost', 'toggle_favorite' => 'fav'];
    if (isset($__dz_alias[$_POST['action']])) $_POST['action'] = $__dz_alias[$_POST['action']];
    unset($__dz_alias);
}

/* ══════════════ SECURITY: object-level access (IDOR) helpers ══════════════
   A post id sent by the browser is never trusted: before liking / reposting / saving / sharing / listing the
   likers of / pinning / collecting a post, the viewer must be allowed to SEE it (same rules as "Connected Zeem"). */
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
if (!function_exists('dz_deny_json')) {
    function dz_deny_json($extra = []) { echo json_encode(array_merge(['ok' => false, 'status' => 'error', 'message' => 'Not available'], $extra)); exit; }
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
if (isset($_POST['action']) && $_POST['action'] === 'poll_vote') {
    $action = 'poll_vote'; header('Content-Type: application/json');
    /* ── poll_vote: vote / change vote / remove vote (option_id 0). Open polls only; same visibility rules as liking the post ── */
    if($action === 'poll_vote'){
        /* CSRF: every POST is already checked by the guard at the top of this page */
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

/* ── Like toggle ── */
if (isset($_POST['action']) && $_POST['action'] === 'like' && $current_user) {
    header('Content-Type: application/json');
    $meme_id = (int)($_POST['meme_id'] ?? 0);
    if (!dz_meme_visible($conn, $meme_id, $current_user)) { dz_deny_json(); }
    $st = $conn->prepare("SELECT id FROM likes WHERE meme_id=? AND user_id=?");
    $st->bind_param("ii", $meme_id, $current_user); $st->execute();
    $has = $st->get_result()->num_rows > 0; $st->close();
    if ($has) {
        $st = $conn->prepare("DELETE FROM likes WHERE meme_id=? AND user_id=?");
        $st->bind_param("ii", $meme_id, $current_user); $st->execute(); $st->close();
        $state = false;
    } else {
        $st = $conn->prepare("INSERT INTO likes (meme_id, user_id) VALUES (?, ?)");
        $st->bind_param("ii", $meme_id, $current_user); $st->execute(); $st->close();
        $state = true;
    }
    $st = $conn->prepare("SELECT COUNT(*) AS c FROM likes WHERE meme_id=?");
    $st->bind_param("i", $meme_id); $st->execute();
    $count = (int)$st->get_result()->fetch_assoc()['c']; $st->close();
    echo json_encode(['ok' => true, 'liked' => $state, 'count' => $count]);
    exit();
}

/* ── Repost toggle ── */
if (isset($_POST['action']) && $_POST['action'] === 'repost' && $current_user) {
    header('Content-Type: application/json');
    $meme_id = (int)($_POST['meme_id'] ?? 0);
    if (!dz_meme_visible($conn, $meme_id, $current_user)) { dz_deny_json(); }
    $st = $conn->prepare("SELECT id FROM reposts WHERE meme_id=? AND user_id=?");
    $st->bind_param("ii", $meme_id, $current_user); $st->execute();
    $has = $st->get_result()->num_rows > 0; $st->close();
    if ($has) {
        $st = $conn->prepare("DELETE FROM reposts WHERE meme_id=? AND user_id=?");
        $st->bind_param("ii", $meme_id, $current_user); $st->execute(); $st->close();
        $state = false;
    } else {
        $st = $conn->prepare("INSERT INTO reposts (meme_id, user_id) VALUES (?, ?)");
        $st->bind_param("ii", $meme_id, $current_user); $st->execute(); $st->close();
        $state = true;
    }
    $st = $conn->prepare("SELECT COUNT(*) AS c FROM reposts WHERE meme_id=?");
    $st->bind_param("i", $meme_id); $st->execute();
    $count = (int)$st->get_result()->fetch_assoc()['c']; $st->close();
    echo json_encode(['ok' => true, 'reposted' => $state, 'count' => $count]);
    exit();
}

/* ── Favorite (save) toggle ── */
if (isset($_POST['action']) && $_POST['action'] === 'fav' && $current_user) {
    header('Content-Type: application/json');
    $meme_id = (int)($_POST['meme_id'] ?? 0);
    if (!dz_meme_visible($conn, $meme_id, $current_user)) { dz_deny_json(); }
    $st = $conn->prepare("SELECT id FROM favorites WHERE meme_id=? AND user_id=?");
    $st->bind_param("ii", $meme_id, $current_user); $st->execute();
    $has = $st->get_result()->num_rows > 0; $st->close();
    if ($has) {
        $st = $conn->prepare("DELETE FROM favorites WHERE meme_id=? AND user_id=?");
        $st->bind_param("ii", $meme_id, $current_user); $st->execute(); $st->close();
        $state = false;
    } else {
        $st = $conn->prepare("INSERT INTO favorites (meme_id, user_id, created_at) VALUES (?, ?, NOW())");
        $st->bind_param("ii", $meme_id, $current_user); $st->execute(); $st->close();
        $state = true;
    }
    $st = $conn->prepare("SELECT COUNT(*) AS c FROM favorites WHERE meme_id=?");
    $st->bind_param("i", $meme_id); $st->execute();
    $count = (int)$st->get_result()->fetch_assoc()['c']; $st->close();
    echo json_encode(['ok' => true, 'faved' => $state, 'count' => $count]);
    exit();
}

/* ── Share a post (Share Modal "Send"): one `shares` row + one "[meme:ID]" chat message per recipient ── */
if (isset($_POST['action']) && $_POST['action'] === 'share_post' && $current_user) {
    header('Content-Type: application/json');

    $meme_id = (int)($_POST['meme_id'] ?? 0);
    $receiver_ids = array_values(array_unique(array_filter(
        array_map('intval', explode(',', (string)($_POST['receiver_ids'] ?? ''))),
        function ($v) use ($current_user) { return $v > 0 && $v !== $current_user; }
    )));

    if (!$meme_id || empty($receiver_ids)) {
        echo json_encode(["status" => "error", "message" => "invalid request"]);
        exit;
    }

    if (!dz_meme_visible($conn, $meme_id, $current_user)) {
        echo json_encode(["status" => "error", "message" => "meme not found"]);
        exit;
    }
    /* recipients: at most 50, must exist, and nobody who blocked you / you blocked */
    $receiver_ids = array_slice($receiver_ids, 0, 50);
    $ok_ids = [];
    $rq = $conn->prepare("SELECT u.id FROM users u WHERE u.id = ?" . ($DZ_NOT_DEACT_U ?? '') . "
        AND NOT EXISTS (SELECT 1 FROM blocks b WHERE (b.blocker_id = u.id AND b.blocked_id = ?) OR (b.blocker_id = ? AND b.blocked_id = u.id)) LIMIT 1");
    if ($rq) {
        foreach ($receiver_ids as $__rid) {
            $rq->bind_param("iii", $__rid, $current_user, $current_user); $rq->execute();
            if ($rq->get_result()->fetch_row()) $ok_ids[] = $__rid;
        }
        $rq->close();
    }
    $receiver_ids = $ok_ids;
    if (!$receiver_ids) { echo json_encode(["status" => "error", "message" => "invalid request"]); exit; }

    $sent_to    = [];
    $stmt_share = $conn->prepare("INSERT INTO shares (user_id, meme_id, share_type) VALUES (?, ?, 'message')");
    $stmt_msg   = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message, seen) VALUES (?, ?, ?, 0)");

    foreach ($receiver_ids as $rid) {
        $stmt_share->bind_param("ii", $current_user, $meme_id);
        $stmt_share->execute();

        $msg_text = "[meme:$meme_id]";
        $stmt_msg->bind_param("iis", $current_user, $rid, $msg_text);
        $stmt_msg->execute();

        $sent_to[] = $rid;
    }
    $stmt_share->close();
    $stmt_msg->close();

    $cs = $conn->prepare("SELECT COUNT(*) AS c FROM shares WHERE meme_id=?");
    $cs->bind_param("i", $meme_id); $cs->execute();
    $count = (int)$cs->get_result()->fetch_assoc()['c']; $cs->close();
    echo json_encode(["status" => "ok", "count" => $count, "sent_to" => $sent_to]);
    exit;
}

/* ── Full likers / reposters list (Immersive Post Viewer "Liked by …" sheet) ── */
if (isset($_POST['action']) && $_POST['action'] === 'get_meme_social') {
    header('Content-Type: application/json');
    $meme_id = (int)($_POST['meme_id'] ?? 0);
    if (!$meme_id || !dz_meme_visible($conn, $meme_id, $current_user)) { echo json_encode(['ok' => false]); exit(); }

    $likes = [];
    $lres = $conn->query("
        SELECT u.id, u.username, u.profile_pic, u.is_verified
        FROM likes l JOIN users u ON u.id = l.user_id
        WHERE l.meme_id = $meme_id
        ORDER BY l.created_at DESC
    ");
    if ($lres) { while ($row = $lres->fetch_assoc()) { $likes[] = $row; } }

    $reposts = [];
    $rres = $conn->query("
        SELECT u.id, u.username, u.profile_pic, u.is_verified
        FROM reposts r JOIN users u ON u.id = r.user_id
        WHERE r.meme_id = $meme_id
        ORDER BY r.id DESC
    ");
    if ($rres) { while ($row = $rres->fetch_assoc()) { $reposts[] = $row; } }

    echo json_encode(['ok' => true, 'likes' => $likes, 'reposts' => $reposts]);
    exit();
}

/* ── Connected Zeem ("▶ Watch · Connected Zeem" pill in the reel modal) ── */
if (isset($_POST['action']) && $_POST['action'] === 'get_connected_meme') {
    header('Content-Type: application/json');
    $meme_id = (int)($_POST['meme_id'] ?? 0);
    if (!$meme_id) { echo json_encode(['ok' => false]); exit; }

    $res = $conn->query("
        SELECT m.*, u.username, u.profile_pic AS uploader_pic,
            ma.cta_text  AS ad_cta_text,
            ma.cta_url   AS ad_cta_url,
            ma.cta_color AS ad_cta_color
        FROM memes m
        JOIN users u ON m.uploaded_by = u.id
        LEFT JOIN meme_ads ma ON ma.meme_id = m.id
        WHERE m.id = $meme_id $DZ_NOT_ARCH $DZ_NOT_DEACT_U
        AND (
            m.is_adult = 0
            OR $current_user = m.uploaded_by
            OR EXISTS (
                SELECT 1 FROM group_posts gp
                JOIN group_members gm ON gm.group_id = gp.group_id
                WHERE gp.meme_id = m.id AND gm.user_id = $current_user
            )
        )
        AND (m.is_ad = 0 OR ma.status = 'approved' OR $current_user = m.uploaded_by)
    ");
    $row = $res ? $res->fetch_assoc() : null;
    if (!$row) { echo json_encode(['ok' => false]); exit; }

    /* blocked in either direction? hide it */
    if ($current_user) {
        $blk = $conn->query("
            SELECT id FROM blocks
            WHERE (blocker_id=$current_user AND blocked_id={$row['uploaded_by']})
               OR (blocker_id={$row['uploaded_by']} AND blocked_id=$current_user)
        ");
        if ($blk && $blk->num_rows > 0) { echo json_encode(['ok' => false]); exit; }
    }

    $mid         = (int)$row['id'];
    $uploaded_by = (int)$row['uploaded_by'];
    $_meta       = get_poster_meta($conn, $mid, $uploaded_by, $current_user);

    $_norm_type = ($row['type'] === 'text' || empty($row['file_name']) || $row['file_name'] === '0') ? 'text' : $row['type'];
    $_main_file = ($_norm_type === 'text') ? 'uploads/Text.jpg' : 'uploads/' . $row['file_name'];
    $_media     = get_meme_media_items($conn, $mid, $_main_file, $_norm_type);

    $_group  = get_meme_group_info($conn, $mid);
    $_audio  = get_meme_audio_info($conn, $mid);
    $_counts = get_meme_counts($mid);
    $_inter  = get_meme_interactions($mid, $current_user);

    $__dzpost = [
        'id'               => $mid,
        'file'             => $_main_file,
        'type'             => $row['type'],
        'pic'              => $row['uploader_pic'] ?: 'mee.jpg',
        'userId'           => $uploaded_by,
        'username'         => $row['username'],
        'isVerified'       => dz_vb_flag($conn, $uploaded_by),
        'caption'          => $row['caption'],
        'time'             => $row['created_at'],
        'isYou'            => ($current_user == $uploaded_by),
        'likeCount'        => (int)$_counts['like'],
        'repostCount'      => (int)$_counts['repost'],
        'favCount'         => (int)$_counts['fav'],
        'shareCount'       => (int)$_counts['share'],
        'liked'            => $_inter['liked'],
        'reposted'         => $_inter['reposted'],
        'faved'            => $_inter['faved'],
        'nickname'         => $_meta['nickname'],
        'isFollowing'      => $_meta['is_following'],
        'socialProof'      => $_meta['social_proof'],
        'socialSpare'      => $_meta['social_spare'] ?? [],
        'media'            => $_media,
        'context'          => '',
        'groupId'          => $_group ? (int)$_group['group_id'] : null,
        'groupTitle'       => $_group ? $_group['group_name'] : null,
        'audioId'          => $_audio ? (int)$_audio['audio_id'] : null,
        'audioIsOriginal'  => $_audio ? (bool)($_audio['is_original'] ?? false) : false,
        'allowDownload' => (int)($row['allow_download'] ?? 1),
        'collab'        => dz_pf_collab($conn, $mid, $current_user),
        'poll'          => dz_pf_poll($conn, $mid, $current_user),
        'audioFile'        => $_audio ? 'uploads/' . $_audio['file_name'] : null,
        'audioTitle'       => $_audio ? $_audio['title'] : null,
        'audioArtist'      => ($_audio && !empty($_audio['artist'])) ? $_audio['artist'] : null,
        'musicVolume'      => ($_audio && $_audio['music_volume'] !== '' && $_audio['music_volume'] !== null) ? (int)$_audio['music_volume'] : 80,
        'videoAudioVolume' => ($_audio && $_audio['video_audio_volume'] !== '' && $_audio['video_audio_volume'] !== null) ? (int)$_audio['video_audio_volume'] : 40,
        'isPartnership'    => !empty($row['is_partnership']),
        'partnerBrand'     => $row['partner_brand'] ?? null,
        'isAd'             => !empty($row['is_ad']),
        'adCtaText'        => $row['ad_cta_text']  ?? null,
        'adCtaUrl'         => $row['ad_cta_url']   ?? null,
        'adCtaColor'       => $row['ad_cta_color'] ?? null,
        'isAiGenerated'    => !empty($row['is_ai_generated']),
        'connectedMemeId'  => !empty($row['connected_meme_id']) ? (int)$row['connected_meme_id'] : null,
        'connectedLabel'   => $row['connected_label'] ?? null,
    ];
    echo json_encode(['ok' => true, 'post' => dzx_apply_hide($__dzpost, $conn, $mid, $current_user)]);
    exit;
}

/* ── Profile picture upload (Edit Picture modal) ── */
if (isset($_POST['action']) && $_POST['action'] === 'update_profile_pic' && $current_user) {
    header('Content-Type: application/json');

    if (empty($_FILES['profile_pic_file']) || $_FILES['profile_pic_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'msg' => 'Upload error']);
        exit;
    }

    $file     = $_FILES['profile_pic_file'];
    $maxBytes = 5 * 1024 * 1024; // 5 MB

    if ($file['size'] > $maxBytes) {
        echo json_encode(['success' => false, 'msg' => 'File too large (max 5 MB)']);
        exit;
    }

    $finfo    = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    if (!isset($allowedMimes[$mimeType])) {
        echo json_encode(['success' => false, 'msg' => 'Only JPG, PNG, GIF and WebP are allowed']);
        exit;
    }
    $ext = $allowedMimes[$mimeType];

    $uploadDir = 'uploads/';
    if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }

    $pic_filename = 'profile_' . $current_user . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $pic_destPath = $uploadDir . $pic_filename;

    if (!move_uploaded_file($file['tmp_name'], $pic_destPath)) {
        echo json_encode(['success' => false, 'msg' => 'Could not save file — check server permissions']);
        exit;
    }
    /* shrink big phone photos (max 640px, webp when the server can) — avatars are shown small, so the original weight is wasted */
    $__small = dz_resize_image($pic_destPath, $uploadDir . 'profile_' . $current_user . '_' . time() . '_' . bin2hex(random_bytes(4)), 640, 82);
    if ($__small && file_exists($__small) && filesize($__small) > 0) {
        @unlink($pic_destPath);
        $pic_destPath = $__small;
    }

    /* delete the old picture (never the defaults) */
    $old_pic_q = $conn->prepare("SELECT profile_pic FROM users WHERE id = ? LIMIT 1");
    $old_pic_q->bind_param("i", $current_user);
    $old_pic_q->execute();
    $old_pic_row = $old_pic_q->get_result()->fetch_assoc();
    $old_pic_q->close();

    if (!empty($old_pic_row['profile_pic'])) {
        $oldPicPath = ltrim($old_pic_row['profile_pic'], '/');
        if (strpos($oldPicPath, 'uploads/') === 0
            && strpos($oldPicPath, 'mee.jp') === false
            && strpos($oldPicPath, 'default') === false
            && file_exists($oldPicPath)
        ) {
            @unlink($oldPicPath);
        }
    }

    $upd_pic = $conn->prepare("UPDATE users SET profile_pic = ? WHERE id = ?");
    $upd_pic->bind_param("si", $pic_destPath, $current_user);
    $upd_pic->execute();
    $upd_pic->close();

    echo json_encode(['success' => true, 'url' => $pic_destPath]);
    exit;
}

/* ── HIGHLIGHTS: load / like / message / my comments / delete my comment ── */
if (isset($_POST['action']) && in_array($_POST['action'], ['get_profile_highlights', 'hl_like', 'hl_comment', 'hl_messages', 'hl_my_comments', 'hl_delete_comment', 'hl_list', 'hl_set_opts'], true)) {
    header('Content-Type: application/json');
    $act      = $_POST['action'];
    $hl_owner = ($current_user == $profile_id);

    /* who may see this profile's highlights */
    $hl_blocked = (!$hl_owner && ($is_blocked_by_owner || ($profile_is_private && !$follow_check)));

    /* the owner can switch comments / sharing off per highlight: the two columns are created here the first time they are needed */
    try {
        foreach (['comments_off', 'shares_off'] as $__c) {
            $__r = $conn->query("SHOW COLUMNS FROM highlights LIKE '$__c'");
            if ($__r && $__r->num_rows === 0) { $conn->query("ALTER TABLE highlights ADD COLUMN $__c TINYINT(1) NOT NULL DEFAULT 0"); }
        }
        $__r = $conn->query("SHOW COLUMNS FROM highlight_messages LIKE 'parent_id'");
        if ($__r && $__r->num_rows === 0) { $conn->query("ALTER TABLE highlight_messages ADD COLUMN parent_id INT NULL DEFAULT NULL"); }
    } catch (Throwable $e) { /* ignore */ }

    /* owner: switch comments / sharing on or off for one highlight */
    if ($act === 'hl_set_opts') {
        if (!$hl_owner || !$current_user) { echo json_encode(['ok' => false]); exit; }
        $hid = (int)($_POST['highlight_id'] ?? 0);
        $co  = !empty($_POST['comments_off']) ? 1 : 0;
        $so  = !empty($_POST['shares_off']) ? 1 : 0;
        if (!$hid) { echo json_encode(['ok' => false]); exit; }
        $st = $conn->prepare("UPDATE highlights SET comments_off=?, shares_off=? WHERE id=? AND user_id=?");
        $st->bind_param("iiii", $co, $so, $hid, $current_user);
        $st->execute();
        $st->close();
        echo json_encode(['ok' => true, 'commentsOff' => (bool)$co, 'sharesOff' => (bool)$so]);
        exit;
    }

    /* finds which @usernames / @nicknames and which group names inside a text really exist */
    function hlp_resolve($conn, $text) {
        static $ucache = [];
        static $gcache = [];
        $users = []; $groups = [];

        if (preg_match_all('/@([A-Za-z0-9_.]{2,40})/u', $text, $mm)) {
            $names = [];
            foreach ($mm[1] as $n) {
                $n = rtrim($n, '.');
                if (strlen($n) >= 2) $names[strtolower($n)] = $n;
            }
            foreach (array_slice($names, 0, 30, true) as $low => $n) {
                if (!array_key_exists($low, $ucache)) {
                    $ucache[$low] = 0;
                    try {
                        $st = $conn->prepare("SELECT id FROM users WHERE username=? OR nickname=? LIMIT 1");
                        $st->bind_param("ss", $n, $n);
                        $st->execute();
                        $u = $st->get_result()->fetch_assoc();
                        $st->close();
                        if ($u) $ucache[$low] = (int)$u['id'];
                    } catch (Throwable $e) { /* ignore */ }
                }
                if ($ucache[$low]) $users[$low] = $ucache[$low];
            }
        }

        $key = md5($text);
        if (!isset($gcache[$key])) {
            $gcache[$key] = [];
            try {
                $st = $conn->prepare("SELECT id, title FROM `groups` WHERE CHAR_LENGTH(title) >= 2 AND LOCATE(LOWER(title), LOWER(?)) > 0 ORDER BY CHAR_LENGTH(title) DESC LIMIT 20");
                $st->bind_param("s", $text);
                $st->execute();
                $res = $st->get_result();
                while ($g = $res->fetch_assoc()) $gcache[$key][] = ['id' => (int)$g['id'], 'name' => $g['title']];
                $st->close();
            } catch (Throwable $e) { $gcache[$key] = []; }
        }
        $groups = $gcache[$key];

        return ['users' => (object)$users, 'groups' => $groups];
    }

    /* like / unlike ONE media of a highlight (each media keeps its own like; no counts are ever sent) */
    if ($act === 'hl_like') {
        if (!$current_user || $hl_blocked) { echo json_encode(['ok' => false]); exit; }
        $hid = (int)($_POST['highlight_id'] ?? 0);
        $mid_ = (int)($_POST['media_id'] ?? 0);
        if (!$hid || !$mid_) { echo json_encode(['ok' => false]); exit; }
        $conn->query("CREATE TABLE IF NOT EXISTS highlight_media_likes (media_id INT UNSIGNED NOT NULL, user_id INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (media_id, user_id), KEY k_hml_user (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        /* the media must really belong to this highlight */
        $st = $conn->prepare("SELECT 1 FROM highlight_media WHERE id=? AND highlight_id=? LIMIT 1");
        $st->bind_param("ii", $mid_, $hid);
        $st->execute();
        $okm = $st->get_result()->num_rows > 0;
        $st->close();
        if (!$okm) { echo json_encode(['ok' => false]); exit; }

        $st = $conn->prepare("SELECT 1 FROM highlight_media_likes WHERE media_id=? AND user_id=?");
        $st->bind_param("ii", $mid_, $current_user);
        $st->execute();
        $exists = $st->get_result()->num_rows > 0;
        $st->close();

        if ($exists) {
            $st = $conn->prepare("DELETE FROM highlight_media_likes WHERE media_id=? AND user_id=?");
            $liked = false;
        } else {
            $st = $conn->prepare("INSERT IGNORE INTO highlight_media_likes (media_id, user_id) VALUES (?,?)");
            $liked = true;
        }
        $st->bind_param("ii", $mid_, $current_user);
        $st->execute();
        $st->close();

        echo json_encode(['ok' => true, 'liked' => $liked]);
        exit;
    }

    /* post a message on a highlight */
    if ($act === 'hl_comment') {
        if (!$current_user || $hl_blocked) { echo json_encode(['ok' => false]); exit; }
        $hid = (int)($_POST['highlight_id'] ?? 0);
        $msg = trim((string)($_POST['message'] ?? ''));
        $msg = function_exists('mb_substr') ? mb_substr($msg, 0, 200) : substr($msg, 0, 200);
        if (!$hid || $msg === '') { echo json_encode(['ok' => false]); exit; }

        /* highlight must belong to the profile being viewed */
        $c = $conn->prepare("SELECT id, comments_off FROM highlights WHERE id=? AND user_id=? LIMIT 1");
        $c->bind_param("ii", $hid, $profile_id);
        $c->execute();
        $hrow = $c->get_result()->fetch_assoc();
        $c->close();
        if (!$hrow) { echo json_encode(['ok' => false]); exit; }
        if (!empty($hrow['comments_off'])) { echo json_encode(['ok' => false, 'error' => 'off']); exit; }

        /* flood guard: 1 message / second per user */
        $f = $conn->prepare("SELECT id FROM highlight_messages WHERE user_id=? AND created_at > (NOW() - INTERVAL 1 SECOND) LIMIT 1");
        $f->bind_param("i", $current_user);
        $f->execute();
        $flood = $f->get_result()->num_rows > 0;
        $f->close();
        if ($flood) { echo json_encode(['ok' => false, 'error' => 'slow']); exit; }

        /* a reply hangs under the top-level comment it belongs to (replies to replies are filed under that same comment) */
        $par = null;
        $pid = (int)($_POST['parent_id'] ?? 0);
        if ($pid > 0) {
            $pq = $conn->prepare("SELECT id, parent_id FROM highlight_messages WHERE id=? AND highlight_id=? LIMIT 1");
            $pq->bind_param("ii", $pid, $hid);
            $pq->execute();
            $prow = $pq->get_result()->fetch_assoc();
            $pq->close();
            if (!$prow) { echo json_encode(['ok' => false]); exit; }
            $par = !empty($prow['parent_id']) ? (int)$prow['parent_id'] : (int)$prow['id'];
        }
        $ins = $conn->prepare("INSERT INTO highlight_messages (highlight_id, user_id, message, parent_id) VALUES (?,?,?,?)");
        $ins->bind_param("iisi", $hid, $current_user, $msg, $par);
        $ins->execute();
        $newId = (int)$conn->insert_id;
        $ins->close();

        echo json_encode(['ok' => true, 'id' => $newId]);
        exit;
    }

    /* my own comments on this highlight */
    if ($act === 'hl_my_comments') {
        if (!$current_user) { echo json_encode(['ok' => true, 'comments' => []]); exit; }
        $hid = (int)($_POST['highlight_id'] ?? 0);
        $q = $conn->prepare("SELECT id, message, created_at FROM highlight_messages WHERE highlight_id=? AND user_id=? ORDER BY id DESC LIMIT 100");
        $q->bind_param("ii", $hid, $current_user);
        $q->execute();
        $res  = $q->get_result();
        $list = [];
        while ($m = $res->fetch_assoc()) {
            $list[] = ['id' => (int)$m['id'], 'text' => $m['message'], 'at' => $m['created_at']];
        }
        $q->close();
        echo json_encode(['ok' => true, 'comments' => $list]);
        exit;
    }

    /* delete one of MY comments (user_id must match, so nobody can delete someone else's) */
    if ($act === 'hl_delete_comment') {
        if (!$current_user) { echo json_encode(['ok' => false]); exit; }
        $cid = (int)($_POST['comment_id'] ?? 0);
        if (!$cid) { echo json_encode(['ok' => false]); exit; }
        $d = $conn->prepare("DELETE FROM highlight_messages WHERE id=? AND user_id=?");
        $d->bind_param("ii", $cid, $current_user);
        $d->execute();
        $done = $d->affected_rows > 0;
        $d->close();
        if ($done) {   /* its replies go with it */
            $d2 = $conn->prepare("DELETE FROM highlight_messages WHERE parent_id=?");
            $d2->bind_param("i", $cid);
            $d2->execute();
            $d2->close();
        }
        echo json_encode(['ok' => $done]);
        exit;
    }

    /* every comment of one highlight (the comments section under the viewer) */
    if ($act === 'hl_list') {
        if ($hl_blocked) { echo json_encode(['ok' => true, 'comments' => []]); exit; }
        $hid  = (int)($_POST['highlight_id'] ?? 0);
        if (!$hid) { echo json_encode(['ok' => false]); exit; }
        $ord  = (($_POST['sort'] ?? 'new') === 'old') ? 'ASC' : 'DESC';
        if (!$hl_owner) {
            $__o = $conn->query("SELECT comments_off FROM highlights WHERE id=" . (int)$hid . " LIMIT 1");
            $__or = $__o ? $__o->fetch_assoc() : null;
            if ($__or && !empty($__or['comments_off'])) { echo json_encode(['ok' => true, 'comments' => [], 'off' => true]); exit; }
        }
        $q = $conn->prepare("
            SELECT m.id, m.message, m.created_at, m.user_id, m.parent_id, u.username, u.profile_pic, u.is_verified
            FROM highlight_messages m
            JOIN users u ON u.id = m.user_id
            WHERE m.highlight_id = ?
            ORDER BY m.id $ord LIMIT 300
        ");
        $q->bind_param("i", $hid);
        $q->execute();
        $res = $q->get_result();
        $list = [];
        while ($m = $res->fetch_assoc()) {
            $list[] = [
                'id'       => (int)$m['id'],
                'text'     => $m['message'],
                'uid'      => (int)$m['user_id'],
                'parent'   => (int)($m['parent_id'] ?? 0),
                'name'     => $m['username'],
                'pic'      => !empty($m['profile_pic']) ? $m['profile_pic'] : 'mee.jpg',
                'at'       => $m['created_at'],
                'mine'     => ((int)$m['user_id'] === (int)$current_user),
                'verified' => !empty($m['is_verified']),
            ];
        }
        $q->close();
        echo json_encode(['ok' => true, 'comments' => $list]);
        exit;
    }

    /* poll messages (after_id < 0 → current last id + a pool of recent messages) */
    if ($act === 'hl_messages') {
        if ($hl_blocked) { echo json_encode(['ok' => true, 'last' => 0, 'messages' => []]); exit; }
        $hid   = (int)($_POST['highlight_id'] ?? 0);
        $after = (int)($_POST['after_id'] ?? -1);
        if (!$hid) { echo json_encode(['ok' => false]); exit; }

        if ($after < 0) {
            $q = $conn->prepare("
                SELECT m.id, m.message, u.username, u.profile_pic
                FROM highlight_messages m
                JOIN users u ON u.id = m.user_id
                WHERE m.highlight_id=?
                ORDER BY m.id DESC LIMIT 40
            ");
            $q->bind_param("i", $hid);
            $q->execute();
            $res = $q->get_result();
            $pool = []; $last = 0;
            while ($m = $res->fetch_assoc()) {
                $mid = (int)$m['id'];
                if ($mid > $last) $last = $mid;
                $pool[] = [
                    'id'   => $mid,
                    'text' => $m['message'],
                    'user' => $m['username'],
                    'pic'  => !empty($m['profile_pic']) ? $m['profile_pic'] : 'mee.jpg',
                ];
            }
            $q->close();
            echo json_encode(['ok' => true, 'last' => $last, 'messages' => $pool]);
            exit;
        }

        $q = $conn->prepare("
            SELECT m.id, m.message, u.username, u.profile_pic
            FROM highlight_messages m
            JOIN users u ON u.id = m.user_id
            WHERE m.highlight_id=? AND m.id>?
            ORDER BY m.id ASC LIMIT 10
        ");
        $q->bind_param("ii", $hid, $after);
        $q->execute();
        $res = $q->get_result();
        $msgs = []; $last = $after;
        while ($m = $res->fetch_assoc()) {
            $last = (int)$m['id'];
            $msgs[] = [
                'id'   => $last,
                'text' => $m['message'],
                'user' => $m['username'],
                'pic'  => !empty($m['profile_pic']) ? $m['profile_pic'] : 'mee.jpg',
            ];
        }
        $q->close();
        echo json_encode(['ok' => true, 'last' => $last, 'messages' => $msgs]);
        exit;
    }

    /* load every highlight (with media) for the profile being viewed */
    if ($hl_blocked) {
        echo json_encode(['ok' => true, 'highlights' => [], 'owner' => null]);
        exit;
    }

    $u = $conn->prepare("SELECT username, profile_pic FROM users WHERE id=? LIMIT 1");
    $u->bind_param("i", $profile_id);
    $u->execute();
    $urow = $u->get_result()->fetch_assoc();
    $u->close();

    /* song / mute / caption columns are created by the settings page; only read them if they exist */
    $c1 = $conn->query("SHOW COLUMNS FROM highlights LIKE 'song_path'");
    $has_song = $c1 && $c1->num_rows > 0;
    $c2 = $conn->query("SHOW COLUMNS FROM highlight_media LIKE 'audio_path'");
    $has_maud = $c2 && $c2->num_rows > 0;
    $c3 = $conn->query("SHOW COLUMNS FROM highlight_media LIKE 'caption'");
    $has_cap = $c3 && $c3->num_rows > 0;
    $song_cols  = $has_song ? "h.song_path, h.song_title, h.song_mode," : "";
    $media_cols = ($has_maud ? ", audio_path, audio_title, mute_video" : "") . ($has_cap ? ", caption" : "");

    $hs = $conn->prepare("
        SELECT h.id, h.title, h.created_at, h.comments_off, h.shares_off, $song_cols
               (SELECT COUNT(*) FROM highlight_likes hl WHERE hl.highlight_id = h.id) AS like_count,
               (SELECT COUNT(*) FROM highlight_likes hl2 WHERE hl2.highlight_id = h.id AND hl2.user_id = $current_user) AS liked
        FROM highlights h
        WHERE h.user_id = ?
        ORDER BY h.created_at DESC, h.id DESC
    ");
    $hs->bind_param("i", $profile_id);
    $hs->execute();
    $hres = $hs->get_result();

    $conn->query("CREATE TABLE IF NOT EXISTS highlight_media_likes (media_id INT UNSIGNED NOT NULL, user_id INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (media_id, user_id), KEY k_hml_user (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $out = [];
    while ($h = $hres->fetch_assoc()) {
        $hid   = (int)$h['id'];
        $media = [];
        $mr = $conn->query("SELECT hm.id AS mid, hm.file_path, hm.media_type" . str_replace(', ', ', hm.', $media_cols) . ", (SELECT COUNT(*) FROM highlight_media_likes ml WHERE ml.media_id = hm.id AND ml.user_id = " . (int)$current_user . ") AS m_liked FROM highlight_media hm WHERE hm.highlight_id=$hid ORDER BY hm.id ASC");
        while ($m = $mr->fetch_assoc()) {
            $cap = $has_cap ? trim((string)($m['caption'] ?? '')) : '';
            $media[] = [
                'id'         => (int)$m['mid'],
                'liked'      => ((int)$m['m_liked']) > 0,
                'path'       => $m['file_path'],
                'type'       => $m['media_type'],
                'caption'    => $cap,
                'men'        => ($cap !== '') ? hlp_resolve($conn, $cap) : null,
                'audio'      => !empty($m['audio_path']) ? $m['audio_path'] : null,
                'audioTitle' => $m['audio_title'] ?? '',
                'mute'       => ((int)($m['mute_video'] ?? 0)) === 1,
            ];
        }
        if (!$media) continue;
        $out[] = [
            'id'         => $hid,
            'title'      => $h['title'],
            'created_at' => $h['created_at'],
            'commentsOff' => !empty($h['comments_off']),
            'sharesOff'   => !empty($h['shares_off']),
            'likeCount'  => 0,
            'media'      => $media,
            'song'       => ($has_song && !empty($h['song_path'])) ? $h['song_path'] : null,
            'songTitle'  => $h['song_title'] ?? '',
            'mode'       => (($h['song_mode'] ?? 'whole') === 'each') ? 'each' : 'whole',
        ];
    }
    $hs->close();

    echo json_encode([
        'ok'         => true,
        'highlights' => $out,
        'owner'      => [
            'id'        => (int)$profile_id,
            'username'  => $urow['username'] ?? '',
            'pic'       => !empty($urow['profile_pic']) ? $urow['profile_pic'] : 'mee.jpg',
            'isYou'     => $hl_owner,
            'following' => (bool)$follow_check,
        ],
    ]);
    exit;
}

/* ids sent by the page: meme_ids = "1,2,3" (bulk) or meme_id = 1 (single) → clean list of positive ints */
function dz_post_ids_from_request() {
    $raw = (string)($_POST['meme_ids'] ?? ($_POST['meme_id'] ?? ''));
    $out = [];
    foreach (explode(',', $raw) as $p) { $n = (int)trim($p); if ($n > 0) $out[$n] = $n; }
    return array_slice(array_values($out), 0, 200);
}

/* ── DELETE MY POSTS (Post options → Delete post, and Select → Delete). JSON; only the owner's own memes ── */
if (($_POST['post_action'] ?? '') === 'delete_post' && $current_user) {
    header('Content-Type: application/json');
    $del_ids = dz_post_ids_from_request();
    if (!$del_ids) { echo json_encode(['status' => 'error', 'message' => 'Missing post']); exit; }
    $done = [];
    foreach ($del_ids as $del_id) {
        $dst = $conn->prepare("DELETE FROM memes WHERE id=? AND uploaded_by=?");
        $dst->bind_param("ii", $del_id, $current_user);
        $dst->execute();
        $gone = $dst->affected_rows > 0;
        $dst->close();
        if (!$gone) continue;
        $done[] = $del_id;
        foreach (['collection_items', 'pinned_posts'] as $tbl) { @$conn->query("DELETE FROM $tbl WHERE meme_id=$del_id"); }
        try { $conn->query("DELETE FROM profile_featured WHERE meme_id=$del_id"); } catch (Throwable $e) { /* tables not created yet */ }
    }
    if (!$done) { echo json_encode(['status' => 'error', 'message' => 'Could not delete this post']); exit; }
    echo json_encode(['status' => 'ok', 'ids' => $done]);
    exit;
}

/* ── FEATURED POST (one per profile, with an optional one-line note). set_featured / clear_featured ── */
if (in_array(($_POST['post_action'] ?? ''), ['set_featured', 'clear_featured'], true) && $current_user) {
    header('Content-Type: application/json');
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS profile_featured (user_id INT NOT NULL PRIMARY KEY, meme_id INT NOT NULL, note VARCHAR(100) NOT NULL DEFAULT '', updated_at DATETIME NOT NULL)");
        if ($_POST['post_action'] === 'clear_featured') {
            $conn->query("DELETE FROM profile_featured WHERE user_id=" . (int)$current_user);
            echo json_encode(['status' => 'ok']); exit;
        }
        $fid  = (int)($_POST['meme_id'] ?? 0);
        $note = trim((string)($_POST['note'] ?? ''));
        $note = function_exists('mb_substr') ? mb_substr($note, 0, 80) : substr($note, 0, 80);
        $q = $conn->prepare("SELECT id FROM memes WHERE id=? AND uploaded_by=? LIMIT 1");
        $q->bind_param("ii", $fid, $current_user); $q->execute();
        $mine = $q->get_result()->num_rows > 0; $q->close();
        if (!$mine) { echo json_encode(['status' => 'error', 'message' => 'You can only feature your own posts']); exit; }
        $q = $conn->prepare("INSERT INTO profile_featured (user_id, meme_id, note, updated_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE meme_id = VALUES(meme_id), note = VALUES(note), updated_at = NOW()");
        $q->bind_param("iis", $current_user, $fid, $note); $q->execute(); $q->close();
        echo json_encode(['status' => 'ok', 'meme_id' => $fid, 'note' => $note]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'message' => 'Could not save']);
    }
    exit;
}

/* ── GRID LAYOUT (default | large | list) for Memes and Saved — only the profile owner can change it; stored per user ── */
if (($_POST['post_action'] ?? '') === 'set_memes_layout' && $current_user) {
    header('Content-Type: application/json');
    $lay = (string)($_POST['layout'] ?? '');
    $tab = (string)($_POST['tab'] ?? 'memes');
    if (!in_array($lay, ['default', 'large'], true) || !in_array($tab, ['memes', 'favorites'], true)) { echo json_encode(['status' => 'error', 'message' => 'Bad layout']); exit; }
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS profile_layouts (user_id INT NOT NULL, tab VARCHAR(12) NOT NULL, layout VARCHAR(10) NOT NULL DEFAULT 'default', PRIMARY KEY (user_id, tab))");
        $lst = $conn->prepare("INSERT INTO profile_layouts (user_id, tab, layout) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE layout = VALUES(layout)");
        $lst->bind_param("iss", $current_user, $tab, $lay);
        $lst->execute();
        $lst->close();
        echo json_encode(['status' => 'ok', 'layout' => $lay, 'tab' => $tab]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'message' => 'Could not save layout']);
    }
    exit;
}

/* ── COLLECTIONS + PINS (col_action) ── */
if (isset($_POST['col_action']) && $current_user) {
    header('Content-Type: application/json');
    $col_action = trim($_POST['col_action'] ?? '');

    if ($col_action === 'create_collection') {
        $name      = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 60);
        $is_public = ((int)($_POST['is_public'] ?? 0)) ? 1 : 0;   // 0 = private, 1 = public
        if ($name === '') { echo json_encode(['status' => 'error', 'message' => 'Name required']); exit; }
        $__ci = $conn->prepare("INSERT INTO collections (user_id, name, is_public, created_at) VALUES (?, ?, ?, NOW())");
        $__ci->bind_param("isi", $current_user, $name, $is_public); $__ci->execute(); $__ci->close();
        $new_id = $conn->insert_id;
        $rows = [];
        $res = $conn->query("SELECT c.id, c.name, c.is_public, c.created_at, (SELECT COUNT(*) FROM collection_items ci WHERE ci.collection_id=c.id) as item_count FROM collections c WHERE c.user_id=$current_user ORDER BY c.created_at DESC");
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        echo json_encode(['status' => 'ok', 'id' => $new_id, 'name' => $name, 'is_public' => $is_public, 'collections' => $rows]);
        exit;
    }

    if ($col_action === 'delete_collection') {
        $col_id = (int)($_POST['col_id'] ?? 0);
        if (!$col_id) { echo json_encode(['status' => 'error']); exit; }
        $conn->query("DELETE FROM collections WHERE id=$col_id AND user_id=$current_user");
        if ($conn->affected_rows > 0) {   // only clean up if it really was MY collection
            $conn->query("DELETE FROM collection_items WHERE collection_id=$col_id");
            $conn->query("DELETE FROM pinned_collections WHERE collection_id=$col_id AND user_id=$current_user");
        }
        echo json_encode(['status' => 'ok']);
        exit;
    }

    if ($col_action === 'set_visibility') {
        $col_id    = (int)($_POST['col_id'] ?? 0);
        $is_public = (int)($_POST['is_public'] ?? 0);
        if (!$col_id) { echo json_encode(['status' => 'error']); exit; }
        $conn->query("UPDATE collections SET is_public=$is_public WHERE id=$col_id AND user_id=$current_user");
        echo json_encode(['status' => 'ok', 'is_public' => $is_public]);
        exit;
    }

    if ($col_action === 'add_to_collection') {
        $col_id  = (int)($_POST['col_id']  ?? 0);
        $meme_id = (int)($_POST['meme_id'] ?? 0);
        if (!$col_id || !$meme_id) { echo json_encode(['status' => 'error']); exit; }
        $own = $conn->query("SELECT id FROM collections WHERE id=$col_id AND user_id=$current_user");
        if (!$own->num_rows) { echo json_encode(['status' => 'error', 'message' => 'Not your collection']); exit; }
        if (!dz_meme_visible($conn, $meme_id, $current_user)) { echo json_encode(['status' => 'error', 'message' => 'Post not available']); exit; }
        $check = $conn->query("SELECT id FROM collection_items WHERE collection_id=$col_id AND meme_id=$meme_id");
        if ($check->num_rows) { echo json_encode(['status' => 'already']); exit; }
        $conn->query("INSERT INTO collection_items (collection_id, meme_id, added_at) VALUES ($col_id, $meme_id, NOW())");
        $count = $conn->query("SELECT COUNT(*) as c FROM collection_items WHERE collection_id=$col_id")->fetch_assoc()['c'];
        echo json_encode(['status' => 'ok', 'item_count' => (int)$count]);
        exit;
    }

    if ($col_action === 'remove_from_collection') {
        $col_id  = (int)($_POST['col_id']  ?? 0);
        $meme_id = (int)($_POST['meme_id'] ?? 0);
        if (!$col_id || !$meme_id) { echo json_encode(['status' => 'error']); exit; }
        /* must be MY collection */
        $own = $conn->query("SELECT id FROM collections WHERE id=$col_id AND user_id=$current_user");
        if (!$own || !$own->num_rows) { echo json_encode(['status' => 'error', 'message' => 'Not your collection']); exit; }
        $conn->query("DELETE FROM collection_items WHERE collection_id=$col_id AND meme_id=$meme_id");
        $count = $conn->query("SELECT COUNT(*) as c FROM collection_items WHERE collection_id=$col_id")->fetch_assoc()['c'];
        echo json_encode(['status' => 'ok', 'item_count' => (int)$count]);
        exit;
    }

    if ($col_action === 'meme_in_cols') {
        $meme_id = (int)($_POST['meme_id'] ?? 0);
        $ids = [];
        if ($meme_id) {
            $r = $conn->query("SELECT ci.collection_id FROM collection_items ci JOIN collections c ON c.id=ci.collection_id WHERE ci.meme_id=$meme_id AND c.user_id=$current_user");
            if ($r) { while ($x = $r->fetch_assoc()) $ids[] = (int)$x['collection_id']; }
        }
        echo json_encode(['status' => 'ok', 'ids' => $ids]);
        exit;
    }

    if ($col_action === 'get_collections') {
        echo json_encode(['status' => 'ok', 'collections' => dz_collections_rows($conn, $current_user, false)]);
        exit;
    }

    if ($col_action === 'get_public_collections') {
        $owner_id = (int)($_POST['owner_id'] ?? 0);
        if (!$owner_id) { echo json_encode(['status' => 'error', 'collections' => []]); exit; }
        echo json_encode(['status' => 'ok', 'collections' => dz_collections_rows($conn, $owner_id, true)]);
        exit;
    }

    if ($col_action === 'get_collection_items') {
        $col_id = (int)($_POST['col_id'] ?? 0);
        if (!$col_id) { echo json_encode(['status' => 'error', 'items' => []]); exit; }
        $owner_check = $conn->query("SELECT id, user_id, is_public FROM collections WHERE id=$col_id");
        if (!$owner_check->num_rows) { echo json_encode(['status' => 'error', 'items' => []]); exit; }
        $col_row = $owner_check->fetch_assoc();
        if ($col_row['user_id'] != $current_user && !$col_row['is_public']) {
            echo json_encode(['status' => 'error', 'items' => []]); exit;
        }
        $rows = [];
        $res = $conn->query("
            SELECT m.id, m.caption, m.type, m.file_name, m.uploaded_by, m.created_at,
                   u.username, u.profile_pic AS uploader_pic
            FROM collection_items ci
            JOIN memes m ON m.id = ci.meme_id
            JOIN users u ON u.id = m.uploaded_by
            WHERE ci.collection_id = $col_id $DZ_NOT_ARCH $DZ_NOT_DEACT_U
            ORDER BY ci.added_at DESC
        ");
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        echo json_encode(['status' => 'ok', 'items' => $rows]);
        exit;
    }

    if ($col_action === 'rename_collection') {
        $col_id = (int)($_POST['col_id'] ?? 0);
        $name   = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 60);
        if (!$col_id || $name === '') { echo json_encode(['status' => 'error']); exit; }
        $__cr = $conn->prepare("UPDATE collections SET name=? WHERE id=? AND user_id=?");
        $__cr->bind_param("sii", $name, $col_id, $current_user); $__cr->execute(); $__cr->close();
        echo json_encode(['status' => 'ok']);
        exit;
    }

    if ($col_action === 'get_previews') {
        $col_id = (int)($_POST['col_id'] ?? 0);
        if (!$col_id) { echo json_encode(['status' => 'ok', 'items' => []]); exit; }
        $col_check = $conn->query("SELECT user_id, is_public FROM collections WHERE id=$col_id");
        if ($col_check->num_rows) {
            $cc = $col_check->fetch_assoc();
            if ($cc['user_id'] != $current_user && !$cc['is_public']) {
                echo json_encode(['status' => 'ok', 'items' => []]); exit;
            }
        }
        $rows = [];
        $res = $conn->query("SELECT m.file_name, m.type FROM collection_items ci JOIN memes m ON m.id=ci.meme_id WHERE ci.collection_id=$col_id ORDER BY ci.added_at DESC LIMIT 4");
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        echo json_encode(['status' => 'ok', 'items' => $rows]);
        exit;
    }

    /* full state of every post in a collection (opened in the reel / immersive viewers).
       Counts + "did I like it" now come from the same cached helpers the grid uses,
       instead of 6 separate queries per post. */
    if ($col_action === 'get_col_item_states') {
        $col_id = (int)($_POST['col_id'] ?? 0);
        if (!$col_id) { echo json_encode(['status' => 'error', 'states' => []]); exit; }
        $col_check = $conn->query("SELECT user_id, is_public FROM collections WHERE id=$col_id");
        if (!$col_check->num_rows) { echo json_encode(['status' => 'error', 'states' => []]); exit; }
        $cc = $col_check->fetch_assoc();
        if ($cc['user_id'] != $current_user && !$cc['is_public']) {
            echo json_encode(['status' => 'error', 'states' => []]); exit;
        }

        $states = [];
        $res = $conn->query("
            SELECT ci.meme_id, m.uploaded_by, m.file_name, m.type, m.is_partnership, m.partner_brand,
                   m.is_ad, m.is_ai_generated,
                   m.connected_meme_id, m.connected_label,
                   ma.cta_text  AS ad_cta_text,
                   ma.cta_url   AS ad_cta_url,
                   ma.cta_color AS ad_cta_color
            FROM collection_items ci
            JOIN memes m ON m.id = ci.meme_id
            LEFT JOIN meme_ads ma ON ma.meme_id = m.id
            WHERE ci.collection_id=$col_id $DZ_NOT_ARCH
        ");

        while ($r = $res->fetch_assoc()) {
            $mid         = (int)$r['meme_id'];
            $uploaded_by = (int)$r['uploaded_by'];

            $_meta   = get_poster_meta($conn, $mid, $uploaded_by, $current_user);
            $_counts = get_meme_counts($mid);
            $_inter  = get_meme_interactions($mid, $current_user);

            $_norm_type   = ($r['type'] === 'text' || empty($r['file_name']) || $r['file_name'] === '0') ? 'text' : $r['type'];
            $_main_file   = ($_norm_type === 'text') ? 'uploads/Text.jpg' : 'uploads/' . $r['file_name'];
            $_media_items = get_meme_media_items($conn, $mid, $_main_file, $_norm_type);

            $_audio = get_meme_audio_info($conn, $mid);
            $_group = get_meme_group_info($conn, $mid);

            $states[] = dzx_apply_hide([
                'id'               => $mid,
                'likeCount'        => (int)$_counts['like'],
                'repostCount'      => (int)$_counts['repost'],
                'favCount'         => (int)$_counts['fav'],
                'shareCount'       => (int)$_counts['share'],
                'liked'            => $_inter['liked'],
                'reposted'         => $_inter['reposted'],
                'faved'            => $_inter['faved'],
                'nickname'         => $_meta['nickname'],
                'isFollowing'      => $_meta['is_following'],
                'socialProof'      => $_meta['social_proof'],
                'socialSpare'      => $_meta['social_spare'] ?? [],
        'socialSpare'      => $_meta['social_spare'] ?? [],
                'media'            => $_media_items,
                'audioId'          => $_audio ? (int)$_audio['audio_id'] : null,
                'audioIsOriginal'  => $_audio ? (bool)($_audio['is_original'] ?? false) : false,
                'allowDownload' => (int)($r['allow_download'] ?? 1),
                'collab'        => dz_pf_collab($conn, $mid, $current_user),
        'poll'          => dz_pf_poll($conn, $mid, $current_user),
                'audioFile'        => $_audio ? 'uploads/' . $_audio['file_name'] : null,
                'audioTitle'       => $_audio ? $_audio['title'] : null,
                'audioArtist'      => ($_audio && !empty($_audio['artist'])) ? $_audio['artist'] : null,
                'musicVolume'      => ($_audio && $_audio['music_volume'] !== '' && $_audio['music_volume'] !== null) ? (int)$_audio['music_volume'] : 80,
                'videoAudioVolume' => ($_audio && $_audio['video_audio_volume'] !== '' && $_audio['video_audio_volume'] !== null) ? (int)$_audio['video_audio_volume'] : 40,
                'groupId'          => $_group ? (int)$_group['group_id'] : null,
                'groupTitle'       => $_group ? $_group['group_name'] : null,
                'isPartnership'    => !empty($r['is_partnership']),
                'partnerBrand'     => $r['partner_brand'] ?? null,
                'isAd'             => !empty($r['is_ad']),
                'adCtaText'        => $r['ad_cta_text']  ?? null,
                'adCtaUrl'         => $r['ad_cta_url']   ?? null,
                'adCtaColor'       => $r['ad_cta_color'] ?? null,
                'isAiGenerated'    => !empty($r['is_ai_generated']),
                'connectedMemeId'  => !empty($r['connected_meme_id']) ? (int)$r['connected_meme_id'] : null,
                'connectedLabel'   => $r['connected_label'] ?? null,
            ], $conn, $mid, $current_user);
        }
        echo json_encode(['status' => 'ok', 'states' => $states]);
        exit;
    }

    /* pin / unpin posts (max 3, own profile only) */
    if ($col_action === 'pin_post') {
        $meme_id = (int)($_POST['meme_id'] ?? 0);
        if (!$meme_id) { echo json_encode(['status' => 'error', 'message' => 'Missing meme_id']); exit; }
        if ($current_user != $profile_id) { echo json_encode(['status' => 'error', 'message' => 'Not your profile']); exit; }

        $cnt = $conn->query("SELECT COUNT(*) as c FROM pinned_posts WHERE user_id=$current_user")->fetch_assoc()['c'];
        if ((int)$cnt >= 3) { echo json_encode(['status' => 'error', 'message' => 'Max 3 pins allowed. Unpin one first.']); exit; }

        /* only YOUR OWN posts (or ones you reposted) can be pinned to your profile */
        $__pk = $conn->prepare("SELECT 1 FROM memes WHERE id=? AND uploaded_by=? UNION SELECT 1 FROM reposts WHERE meme_id=? AND user_id=? LIMIT 1");
        $__pk->bind_param("iiii", $meme_id, $current_user, $meme_id, $current_user); $__pk->execute();
        $__pk_ok = (bool)$__pk->get_result()->fetch_row(); $__pk->close();
        if (!$__pk_ok || !dz_meme_visible($conn, $meme_id, $current_user)) { echo json_encode(['status' => 'error', 'message' => 'Meme not found']); exit; }

        $stmt = $conn->prepare("INSERT INTO pinned_posts (user_id, meme_id, pinned_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE pinned_at=NOW()");
        $stmt->bind_param("ii", $current_user, $meme_id);
        if (!$stmt->execute()) { echo json_encode(['status' => 'error', 'message' => 'DB error: ' . $conn->error]); exit; }
        $stmt->close();
        echo json_encode(['status' => 'ok', 'pinned' => true]);
        exit;
    }

    if ($col_action === 'unpin_post') {
        $meme_id = (int)($_POST['meme_id'] ?? 0);
        if (!$meme_id) { echo json_encode(['status' => 'error', 'message' => 'Missing meme_id']); exit; }
        if ($current_user != $profile_id) { echo json_encode(['status' => 'error', 'message' => 'Not your profile']); exit; }
        $stmt = $conn->prepare("DELETE FROM pinned_posts WHERE user_id=? AND meme_id=?");
        $stmt->bind_param("ii", $current_user, $meme_id);
        if (!$stmt->execute()) { echo json_encode(['status' => 'error', 'message' => 'DB error: ' . $conn->error]); exit; }
        $stmt->close();
        echo json_encode(['status' => 'ok', 'pinned' => false]);
        exit;
    }

    /* pin / unpin collections (max 3) */
    if ($col_action === 'pin_collection') {
        $col_id = (int)($_POST['col_id'] ?? 0);
        if (!$col_id) { echo json_encode(['status' => 'error', 'message' => 'Missing col_id']); exit; }

        $own = $conn->query("SELECT id FROM collections WHERE id=$col_id AND user_id=$current_user");
        if (!$own->num_rows) { echo json_encode(['status' => 'error', 'message' => 'Not your collection']); exit; }

        $cnt = $conn->query("SELECT COUNT(*) as c FROM pinned_collections WHERE user_id=$current_user")->fetch_assoc()['c'];
        if ((int)$cnt >= 3) { echo json_encode(['status' => 'error', 'message' => 'Max 3 pinned collections. Unpin one first.']); exit; }

        $stmt = $conn->prepare("INSERT INTO pinned_collections (user_id, collection_id, pinned_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE pinned_at=NOW()");
        $stmt->bind_param("ii", $current_user, $col_id);
        if (!$stmt->execute()) { echo json_encode(['status' => 'error', 'message' => 'DB error: ' . $conn->error]); exit; }
        $stmt->close();
        echo json_encode(['status' => 'ok', 'pinned' => true]);
        exit;
    }

    if ($col_action === 'unpin_collection') {
        $col_id = (int)($_POST['col_id'] ?? 0);
        if (!$col_id) { echo json_encode(['status' => 'error', 'message' => 'Missing col_id']); exit; }
        $stmt = $conn->prepare("DELETE FROM pinned_collections WHERE user_id=? AND collection_id=?");
        $stmt->bind_param("ii", $current_user, $col_id);
        if (!$stmt->execute()) { echo json_encode(['status' => 'error', 'message' => 'DB error: ' . $conn->error]); exit; }
        $stmt->close();
        echo json_encode(['status' => 'ok', 'pinned' => false]);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
    exit;
}

/* ══════════════════════════════════════════════════════════════
   PAGE-FORM POST ACTIONS (one block, one place):
   Block · Unblock · Track · Untrack · Delete meme
   ("Request to Track" / "Cancel request" are handled by the page below.)
   ══════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $current_user) {

    /* actions on ANOTHER person's profile */
    if ($current_user != $profile_id) {

        /* Block */
        if (isset($_POST['block'])) {
            $stmt = $conn->prepare("INSERT IGNORE INTO blocks (blocker_id, blocked_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $current_user, $profile_id);
            $stmt->execute();
            $stmt->close();

            $stmt2 = $conn->prepare("DELETE FROM followers WHERE follower_id = ? AND following_id = ?");
            $stmt2->bind_param("ii", $current_user, $profile_id);
            $stmt2->execute();
            $stmt2->close();

            try {   // legacy table, may not exist on every install
                $stmt3 = $conn->prepare("DELETE FROM follows WHERE follower_id = ? AND following_id = ?");
                if ($stmt3) { $stmt3->bind_param("ii", $current_user, $profile_id); $stmt3->execute(); $stmt3->close(); }
            } catch (Throwable $e) { /* ignore */ }

            $stmt4 = $conn->prepare("DELETE FROM track_requests WHERE requester_id = ? AND target_id = ?");
            $stmt4->bind_param("ii", $current_user, $profile_id);
            $stmt4->execute();
            $stmt4->close();

            header("Location: profile.php?user_id=" . $profile_id);
            exit();
        }

        /* Unblock */
        if (isset($_POST['unblock'])) {
            $stmt = $conn->prepare("DELETE FROM blocks WHERE blocker_id = ? AND blocked_id = ?");
            $stmt->bind_param("ii", $current_user, $profile_id);
            $stmt->execute();
            $stmt->close();
            header("Location: profile.php?user_id=" . $profile_id);
            exit();
        }

        /* Track — not allowed if blocked either way, or if the profile is private and the request wasn't approved */
        if (isset($_POST['follow'])) {
            $allowed = !$is_blocked_by_owner && !$is_blocked;
            if ($allowed && $profile_is_private) {
                $ap = $conn->prepare("SELECT id FROM track_requests WHERE requester_id=? AND target_id=? AND status='approved' LIMIT 1");
                $ap->bind_param("ii", $current_user, $profile_id);
                $ap->execute();
                $allowed = $ap->get_result()->num_rows > 0;
                $ap->close();
            }
            if ($allowed) {
                $stmt = $conn->prepare("INSERT IGNORE INTO followers (follower_id, following_id) VALUES (?, ?)");
                $stmt->bind_param("ii", $current_user, $profile_id);
                $stmt->execute();
                $stmt->close();

                /* they tracked back → no longer shows on the other person's untracked list */
                $stmt = $conn->prepare("DELETE FROM unfollow_log WHERE unfollowed_by=? AND user_id=?");
                $stmt->bind_param("ii", $current_user, $profile_id);
                $stmt->execute();
                $stmt->close();
            }
            header("Location: profile.php?user_id=" . $profile_id);
            exit();
        }

        /* Untrack */
        if (isset($_POST['unfollow'])) {
            $stmt = $conn->prepare("DELETE FROM followers WHERE follower_id=? AND following_id=?");
            $stmt->bind_param("ii", $current_user, $profile_id);
            $stmt->execute();
            $stmt->close();

            /* log it so the other person can see it on untracked.php */
            $stmt = $conn->prepare("INSERT INTO unfollow_log (unfollowed_by, user_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $current_user, $profile_id);
            $stmt->execute();
            $stmt->close();

            /* clear the track request so a private wall shows "Request to Track" again */
            $stmt = $conn->prepare("DELETE FROM track_requests WHERE requester_id=? AND target_id=?");
            $stmt->bind_param("ii", $current_user, $profile_id);
            $stmt->execute();
            $stmt->close();

            header("Location: profile.php?user_id=" . $profile_id);
            exit();
        }
    }

    /* Delete one of MY memes */
    if (isset($_POST['delete_meme'])) {
        $mid = (int)($_POST['meme_id'] ?? 0);
        if ($mid) {
            $stmt = $conn->prepare("DELETE FROM memes WHERE id=? AND uploaded_by=?");
            $stmt->bind_param("ii", $mid, $current_user);
            $stmt->execute();
            $stmt->close();
        }
        header("Location: profile.php?user_id=" . $profile_id . "#meme-grid");
        exit();
    }
}

/* ══════════════════════════════════════════════════════════════
   PAGE QUERIES — only reached for a normal page view
   (Likes / Uploads tabs were removed from the page, so their
    queries are gone too.)
   ══════════════════════════════════════════════════════════════ */

/* same "adult / ad" visibility rule for every grid */
$vis_rule = "
    AND (
        m.is_adult = 0
        OR $current_user = $profile_id
        OR EXISTS (
            SELECT 1 FROM group_posts gp
            JOIN group_members gm ON gm.group_id = gp.group_id
            WHERE gp.meme_id = m.id AND gm.user_id = $current_user
        )
    )";
$vis_rule .= $DZ_NOT_ARCH . $DZ_NOT_DEACT_U;   /* archived posts and posts by deactivated accounts never show in any grid */

/* MEMES — things this user uploaded (and posts they collaborate on, once the collaborator accepted) */
$dz_posts_clause = function_exists('dz_collab_profile_clause') ? dz_collab_profile_clause('m', $profile_id) : ('m.uploaded_by = ' . (int)$profile_id);
$memes = $dz_lazy !== '' ? null : $conn->query("
    SELECT m.*, u.username, u.profile_pic AS uploader_pic,
        ma.cta_text  AS ad_cta_text,
        ma.cta_url   AS ad_cta_url,
        ma.cta_color AS ad_cta_color
    FROM memes m
    JOIN users u ON m.uploaded_by = u.id
    LEFT JOIN meme_ads ma ON ma.meme_id = m.id
    WHERE ($dz_posts_clause)
    $vis_rule
    AND (m.is_ad = 0 OR ma.status = 'approved' OR $current_user = $profile_id)
    ORDER BY m.created_at DESC
");
if ($memes instanceof mysqli_result && function_exists('dz_collab_payload')) {   /* one batch lookup for every card in the grid */
    $__cids = [];
    while ($__cr = $memes->fetch_assoc()) $__cids[] = (int)$__cr['id'];
    if ($memes->num_rows > 0) $memes->data_seek(0);
    dz_pf_collab_prime($conn, $__cids, $current_user);
    dz_pf_poll_prime($conn, $__cids, $current_user);
    unset($__cids, $__cr);
}

/* REPOSTS — things this user shared (hand-picked columns, incl. Zeem Connect) */
$reposts = ($dz_lazy === 'reposts') ? $conn->query("
    SELECT m.id, m.uploaded_by, m.file_name, m.caption, m.type,
           m.created_at, m.is_partnership, m.partner_brand,
           m.is_ad, m.is_ai_generated, m.cover_image,
           m.connected_meme_id, m.connected_label,
           ma.cta_text  AS ad_cta_text,
           ma.cta_url   AS ad_cta_url,
           ma.cta_color AS ad_cta_color,
           u.username as creator, u.profile_pic as creator_pic
    FROM reposts r
    JOIN memes m ON r.meme_id = m.id
    JOIN users u ON m.uploaded_by = u.id
    LEFT JOIN meme_ads ma ON ma.meme_id = m.id
    WHERE r.user_id = $profile_id
    $vis_rule
    AND (m.is_ad = 0 OR ma.status = 'approved')
    ORDER BY r.id DESC
") : null;

/* TAGS — memes where @username appears in the caption (LIKE wildcards escaped) */
$profile_username = $user['username'];
$tag_like = $conn->real_escape_string(addcslashes('@' . $profile_username, '%_\\'));
$tagged_posts = ($dz_lazy === 'tags') ? $conn->query("
    SELECT m.*, u.username, u.profile_pic as uploader_pic,
        ma.cta_text  AS ad_cta_text,
        ma.cta_url   AS ad_cta_url,
        ma.cta_color AS ad_cta_color
    FROM memes m
    JOIN users u ON m.uploaded_by = u.id
    LEFT JOIN meme_ads ma ON ma.meme_id = m.id
    WHERE m.caption LIKE '%{$tag_like}%'
    AND m.uploaded_by != $profile_id
    $vis_rule
    AND (m.is_ad = 0 OR ma.status = 'approved')
    ORDER BY m.created_at DESC
") : null;

/* SAVED */
$favorites = ($dz_lazy === 'favs') ? $conn->query("
    SELECT m.*, u.username, u.profile_pic as uploader_pic,
        ma.cta_text  AS ad_cta_text,
        ma.cta_url   AS ad_cta_url,
        ma.cta_color AS ad_cta_color
    FROM favorites f
    JOIN memes m ON f.meme_id = m.id
    JOIN users u ON m.uploaded_by = u.id
    LEFT JOIN meme_ads ma ON ma.meme_id = m.id
    WHERE f.user_id = $profile_id
    $vis_rule
    AND (m.is_ad = 0 OR ma.status = 'approved')
    ORDER BY f.created_at DESC
") : null;

/* MESSAGES — own profile only. message_count + last_message_time feed the
   "Dry Chat" filter in the Messages tab (it reads those two columns). */
$messages = [];
if ($current_user == $profile_id && $dz_lazy === '') {
    $messages = $conn->query("
        SELECT
            u.id,
            u.username,
            u.profile_pic,
            m.message         AS last_message,
            m.file_attachment AS last_attachment,
            m.created_at      AS last_time,
            m.created_at      AS last_message_time,
            m.sender_id,
            m.seen,
            (SELECT COUNT(*) FROM messages
              WHERE sender_id = u.id AND receiver_id = $current_user AND seen = 0) AS unread_count,
            (SELECT COUNT(*) FROM messages mm
              WHERE (mm.sender_id = u.id AND mm.receiver_id = $current_user)
                 OR (mm.sender_id = $current_user AND mm.receiver_id = u.id)) AS message_count
        FROM (
            SELECT
                CASE WHEN sender_id = $current_user THEN receiver_id ELSE sender_id END AS other_user_id,
                MAX(id) AS last_msg_id
            FROM messages
            WHERE sender_id = $current_user OR receiver_id = $current_user
            GROUP BY other_user_id
        ) AS convos
        JOIN messages m ON m.id = convos.last_msg_id
        JOIN users u    ON u.id = convos.other_user_id
        WHERE 1=1 $DZ_NOT_DEACT_U
        ORDER BY m.created_at DESC
    ");
}

$post_count = ($memes instanceof mysqli_result) ? (int)$memes->num_rows : 0;
$profilePic = !empty($user['profile_pic']) ? $user['profile_pic'] : 'mee.jpg';

/* ── collections: the profile being viewed (all for the owner, public only for visitors) ── */
$user_collections = dz_collections_rows($conn, $profile_id, $current_user != $profile_id);

/* the LOGGED-IN user's own collections (used by the "Add to Collection" modal — you always save into your own) */
$my_collections = [];
if ($current_user) {
    $my_collections = ($current_user == $profile_id)
        ? $user_collections
        : dz_collections_rows($conn, $current_user, false);
}

/* ── unread messages across all linked accounts (ONE query) ── */
$total_unread = 0;
if (!empty($_SESSION['accounts'])) {
    $acc_ids = implode(',', array_map('intval', $_SESSION['accounts']));
    $ur = $conn->query("SELECT COUNT(*) AS cnt FROM messages WHERE receiver_id IN ($acc_ids) AND seen = 0");
    $total_unread = $ur ? (int)($ur->fetch_assoc()['cnt'] ?? 0) : 0;
}
/* group chat unreads (msg_gc_*) for all linked accounts */
if (!empty($acc_ids)) {
    try {
        $gur = $conn->query("SELECT COUNT(*) AS cnt FROM msg_gc_messages x JOIN msg_gc_members m ON m.group_id=x.group_id AND m.user_id IN ($acc_ids) LEFT JOIN msg_gc_reads r ON r.group_id=x.group_id AND r.user_id=m.user_id WHERE x.id > COALESCE(r.last_read_id,0) AND x.sender_id != m.user_id");
        if ($gur instanceof mysqli_result) $total_unread += (int)($gur->fetch_assoc()['cnt'] ?? 0);
    } catch (Throwable $e) {}
}

/* ── unseen profile views + bell dot (own profile only) ── */
$new_views = 0;
$notif_has_unread = false;
if ($current_user && $current_user == $profile_id) {
    $nv = $conn->query("SELECT COUNT(*) AS cnt FROM profile_views WHERE profile_id = $current_user AND seen = 0");
    $new_views = $nv ? (int)($nv->fetch_assoc()['cnt'] ?? 0) : 0;

    $last_read_row = $conn->query("SELECT last_read_at FROM notification_reads WHERE user_id=$current_user")->fetch_assoc();
    $last_read_esc = $conn->real_escape_string($last_read_row['last_read_at'] ?? '1970-01-01 00:00:00');

    $notif_check = $conn->query("
        SELECT
        (
            (SELECT COUNT(*) FROM likes l WHERE l.meme_id IN (SELECT id FROM memes WHERE uploaded_by=$current_user) AND l.user_id != $current_user AND l.created_at > '$last_read_esc')
            +
            (SELECT COUNT(*) FROM followers f WHERE f.following_id=$current_user AND f.follower_id != $current_user AND f.created_at > '$last_read_esc')
            +
            (SELECT COUNT(*) FROM reposts r WHERE r.meme_id IN (SELECT id FROM memes WHERE uploaded_by=$current_user) AND r.user_id != $current_user AND r.created_at > '$last_read_esc')
            +
            (SELECT COUNT(*) FROM favorites fv WHERE fv.meme_id IN (SELECT id FROM memes WHERE uploaded_by=$current_user) AND fv.user_id != $current_user AND fv.created_at > '$last_read_esc')
            +
            (SELECT COUNT(*) FROM profile_views pv WHERE pv.profile_id=$current_user AND pv.viewer_id != $current_user AND pv.viewed_at > '$last_read_esc')
            +
            (SELECT COUNT(*) FROM notifications n WHERE n.user_id=$current_user AND n.is_read=0)
        ) AS cnt
    ")->fetch_assoc();

    $notif_has_unread = ((int)($notif_check['cnt'] ?? 0)) > 0;
}
?>
<?php
/* ══════════════════════════════════════════════════════════════════════════
   🔒 SECURITY GATE — CSRF PROTECTION (keep this block as the FIRST thing in profile.php;
      put your own top PHP (session / queries / POST handlers) BELOW it.)

   What it does
   • Hardened session cookie: HttpOnly, SameSite=Lax, Secure on HTTPS, strict mode.
   • One random 256-bit token per session, rotated every 2 hours (the previous token is
     still accepted for a grace period so open tabs don't break).
   • EVERY state-changing request (any POST/PUT/PATCH/DELETE) to this page — including the
     ones your own top PHP handles (follow, block, mute, vibe, avatar upload, notifications…)
     — must carry the token (X-CSRF-Token header or csrf_token field). Compared timing-safe.
   • Second layer: Sec-Fetch-Site / Origin / Referer must be same-site.
   • Front-end: fetch() and XMLHttpRequest are wrapped so the token is attached automatically
     to every same-origin write, forms are armed with a hidden field, and an expired token is
     silently refreshed and the request retried once.
   • Extra headers: clickjacking protection (X-Frame-Options), nosniff, referrer policy.
   Failed checks answer 403 and change nothing.
   ══════════════════════════════════════════════════════════════════════════ */
if (!defined('DZ_CSRF_LOADED')) {
    define('DZ_CSRF_LOADED', true);

    /* actions that must stay open to outside callers (e.g. payment webhooks). Keep empty unless needed. */
    $DZ_CSRF_EXEMPT_POST_KEYS = [];

    if (session_status() === PHP_SESSION_NONE) {
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.use_only_cookies', '1');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        @session_cache_limiter('');
        session_start();
    }

    if (!headers_sent()) {
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Cache-Control: private, no-cache, max-age=0');   // never let a shared cache keep a page with a token in it
    }

    function dz_csrf_token(): string {
        $now = time();
        $c   = $_SESSION['dz_csrf_pf'] ?? null;
        if (!is_array($c) || empty($c['t'])) {
            $c = ['t' => bin2hex(random_bytes(32)), 'p' => '', 'at' => $now];
        } elseif ($now - (int)$c['at'] > 7200) {                 // rotate every 2h, keep the old one for a grace period
            $c = ['t' => bin2hex(random_bytes(32)), 'p' => $c['t'], 'at' => $now];
        }
        $_SESSION['dz_csrf_pf'] = $c;
        return $c['t'];
    }
    function dz_csrf_field(): string {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(dz_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
    }
    function dz_csrf_same_origin(): bool {
        $sfs = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
        if ($sfs === 'cross-site') return false;                 // browser itself says it's another site
        $host = strtolower((string)parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
        foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $h) {
            if (!empty($_SERVER[$h])) {
                $src = strtolower((string)parse_url($_SERVER[$h], PHP_URL_HOST));
                return ($src !== '' && $src === $host);          // first header present decides
            }
        }
        return true;   // neither header sent (privacy tools) — the token still has to match
    }
    function dz_csrf_valid(): bool {
        $c = $_SESSION['dz_csrf_pf'] ?? null;
        if (!is_array($c) || empty($c['t'])) return false;
        $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
        if (!is_string($sent) || $sent === '' || strlen($sent) > 128) return false;
        $ok = hash_equals($c['t'], $sent) || (!empty($c['p']) && hash_equals($c['p'], $sent));
        return $ok && dz_csrf_same_origin();
    }
    function dz_csrf_wants_json(): bool {
        $mode = strtolower((string)($_SERVER['HTTP_SEC_FETCH_MODE'] ?? ''));
        return !empty($_SERVER['HTTP_X_CSRF_TOKEN'])
            || stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'json') !== false
            || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
            || ($mode !== '' && $mode !== 'navigate');
    }
    function dz_csrf_deny(): void {
        http_response_code(403);
        if (dz_csrf_wants_json()) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'msg' => 'csrf']);
        } else {
            header('Content-Type: text/html; charset=UTF-8');
            echo '<!DOCTYPE html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
               . '<body style="margin:0;background:#06071A;color:#fff;font-family:system-ui,sans-serif;display:flex;min-height:100vh;align-items:center;justify-content:center;text-align:center">'
               . '<div style="padding:24px"><p style="font-size:15px;font-weight:700;margin:0 0 6px">Your session expired</p>'
               . '<p style="font-size:13px;opacity:.6;margin:0 0 16px">For your security this request was blocked. Please refresh and try again.</p>'
               . '<a href="' . htmlspecialchars(strtok($_SERVER['REQUEST_URI'] ?? '', '#'), ENT_QUOTES, 'UTF-8') . '" style="color:#fff;background:#FF2D2D;padding:9px 22px;border-radius:4px;text-decoration:none;font-weight:700;font-size:13px">Refresh</a></div>';
        }
        exit;
    }

    $dz_csrf_token = dz_csrf_token();
    $dz_method     = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    /* token refresh endpoint (used by the front-end to recover from an expired token) */
    if ($dz_method === 'GET' && isset($_GET['csrf_refresh'])) {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        if (!dz_csrf_same_origin()) { http_response_code(403); echo json_encode(['status' => 'error']); exit; }
        echo json_encode(['status' => 'ok', 'token' => dz_csrf_token()]);
        exit;
    }

    /* the gate itself */
    if (!in_array($dz_method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        $dz_exempt = false;
        foreach ($DZ_CSRF_EXEMPT_POST_KEYS as $k) { if (isset($_POST[$k])) { $dz_exempt = true; break; } }
        if (!$dz_exempt && !dz_csrf_valid()) dz_csrf_deny();
    }
}
/* ════════════════════════════════════════════════════════════
   SAFETY GUARD — belongs at the very top of profile.php, ABOVE
   this markup. Every var/function here is normally produced by
   your existing "top PHP" block (session, queries, follow/block
   checks, etc). These guards only fire if that top PHP hasn't
   run yet / hasn't set a given value — so once you paste your
   real top PHP back in above this file, these no-ops silently
   step aside and your real values are used everywhere below.
   ════════════════════════════════════════════════════════════ */

/* ── ONE-TIME DB SETUP ──
   The old per-request CREATE TABLE IF NOT EXISTS / SHOW COLUMNS / ALTER calls now live in
   profile_setup.php. dz_schema_ensure() runs that file once (when the flag file is missing)
   and from then on costs a single file_exists() per request.
   Delete profile_schema_v1.flag to run the setup again. */
if (!function_exists('dz_schema_ensure')) {
    function dz_schema_ensure() {
        static $done = false;
        if ($done) return;
        $done  = true;
        $flags = [__DIR__ . '/profile_schema_v1.flag', rtrim(sys_get_temp_dir(), '/\\') . '/dz_profile_schema_v1.flag'];
        foreach ($flags as $f) { if (@file_exists($f)) return; }
        global $conn;
        if (!isset($conn) || !$conn) return;
        $setup = __DIR__ . '/profile_setup.php';
        if (!is_file($setup)) return;
        require_once $setup;
        if (function_exists('dz_profile_setup') && dz_profile_setup($conn)) {
            foreach ($flags as $f) { if (@file_put_contents($f, date('c')) !== false) break; }
        }
    }
}
dz_schema_ensure();

/* ══════════════════════════════════════════════════════════════
   PRIVACY HELPER — reads what a user chose in Settings → Privacy Controls.
     level          : public | limited | private | full
     is_private     : true for private + full (needs a track request)
     allow_messages : "Allow messages" switch
     show_online    : "Show online status" switch
   Same reconciliation rule as settings.php (the old is_fully_private flag wins
   if it disagrees with the saved label). Never throws.
   ══════════════════════════════════════════════════════════════ */
if (!function_exists('dz_privacy_load')) {
    function dz_privacy_load($uid) {
        global $conn;
        static $cache = [];
        $uid = (int)$uid;
        if (isset($cache[$uid])) return $cache[$uid];
        $out = ['level' => 'public', 'is_private' => false, 'allow_messages' => true, 'show_online' => true];
        if ($uid <= 0 || !isset($conn) || !$conn) return $cache[$uid] = $out;

        $flag = false; $lvl = '';
        try {
            $r = $conn->query("SELECT is_fully_private FROM user_settings WHERE user_id = $uid LIMIT 1");
            if ($r instanceof mysqli_result && ($row = $r->fetch_assoc())) $flag = !empty($row['is_fully_private']);
        } catch (Throwable $e) {}
        try {
            $r = $conn->query("SELECT allow_messages, show_online_status FROM user_settings WHERE user_id = $uid LIMIT 1");
            if ($r instanceof mysqli_result && ($row = $r->fetch_assoc())) {
                if ($row['allow_messages'] !== null)      $out['allow_messages'] = ((int)$row['allow_messages'] === 1);
                if ($row['show_online_status'] !== null)  $out['show_online']    = ((int)$row['show_online_status'] === 1);
            }
        } catch (Throwable $e) {}
        try {
            $r = $conn->query("SELECT privacy_level FROM user_privacy WHERE user_id = $uid LIMIT 1");
            if ($r instanceof mysqli_result && ($row = $r->fetch_assoc())) $lvl = (string)$row['privacy_level'];
        } catch (Throwable $e) {}

        $needs = in_array($lvl, ['private', 'full'], true);
        if ($flag && !$needs)       $lvl = 'full';
        elseif (!$flag && $needs)   $lvl = 'public';
        elseif (!in_array($lvl, ['public', 'limited', 'private', 'full'], true)) $lvl = 'public';

        $out['level']      = $lvl;
        $out['is_private'] = in_array($lvl, ['private', 'full'], true);
        return $cache[$uid] = $out;
    }
}

/* ── Notification AJAX (poll / mark-seen) is answered by profile_notifications.php.
   It has to run BEFORE any HTML is printed, otherwise the JSON comes back glued to the page
   and the live updates / "mark as seen" silently fail. ── */
if (isset($_GET['poll_count']) || isset($_POST['mark_seen'])) {
    if (session_status() === PHP_SESSION_NONE) @session_start();
    include 'profile_notifications.php';   // answers with JSON and exits
    exit;
}

if (!function_exists('shortNum')) {
    function shortNum($n) {
        $n = (int)$n;
        if ($n >= 1000000000) return round($n / 1000000000, 1) . 'B';
        if ($n >= 1000000)    return round($n / 1000000, 1) . 'M';
        if ($n >= 1000)       return round($n / 1000, 1) . 'K';
        return $n;
    }
}

/* Kept for compatibility with any other page that still calls it.
   The bio below now uses dz_bio_html() instead. */
if (!function_exists('linkifyBio')) {
    function linkifyBio($text) {
        $text = preg_replace(
            '/(https?:\/\/[^\s<]+)/i',
            '<a href="$1" target="_blank" class="dz-link">$1</a>',
            $text
        );
        return preg_replace(
            '/([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})/i',
            '<a href="mailto:$1" class="dz-link">$1</a>',
            $text
        );
    }
}

/* ── bio renderer.
   • URLs and emails → links (one consistent purple, set in CSS)
   • @username / @nickname → RED link straight to that user's profile
     (only when the name matches a real user; otherwise stays plain text)
   Takes the RAW bio text (not pre-escaped) and returns safe HTML. ── */
if (!function_exists('dz_bio_html')) {
    function dz_bio_html($raw) {
        global $conn;
        static $cache = [];
        $db   = (isset($conn) && $conn) ? $conn : null;
        $safe = htmlspecialchars((string)$raw, ENT_QUOTES, 'UTF-8');

        $pattern = '~(https?://[^\s<]+)'
                 . '|([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9\-]+(?:\.[a-zA-Z0-9\-]+)*\.[a-zA-Z]{2,})'
                 . '|(?<![\w.@])@([A-Za-z0-9_.]{1,40})~iu';

        return preg_replace_callback($pattern, function($m) use (&$cache, $db) {
            if (!empty($m[1])) {
                return '<a href="' . $m[1] . '" target="_blank" rel="noopener" class="dz-link">' . $m[1] . '</a>';
            }
            if (!empty($m[2])) {
                return '<a href="mailto:' . $m[2] . '" class="dz-link">' . $m[2] . '</a>';
            }
            if (isset($m[3]) && $m[3] !== '') {
                $name  = rtrim($m[3], '.');
                $trail = substr($m[3], strlen($name));      // trailing dots stay outside the link
                if ($name === '') return $m[0];

                $key = strtolower($name);
                if (!array_key_exists($key, $cache)) {
                    $cache[$key] = 0;
                    if ($db) {
                        $st = $db->prepare("SELECT id FROM users WHERE username = ? OR nickname = ? LIMIT 1");
                        if ($st) {
                            $st->bind_param("ss", $name, $name);
                            $st->execute();
                            $row = $st->get_result()->fetch_assoc();
                            if ($row) $cache[$key] = (int)$row['id'];
                            $st->close();
                        }
                    }
                }
                if ($cache[$key] > 0) {
                    return '<a href="profile.php?user_id=' . $cache[$key] . '" class="dz-mention">@' . $name . '</a>' . $trail;
                }
            }
            return $m[0];
        }, $safe);
    }
}

/* ── the whole bio block (short text + "more" / "close" toggles).
   Used by the page AND by the live-update endpoint, so a bio edited in the
   profile modal looks exactly like a freshly loaded page. Takes RAW text. ── */
if (!function_exists('dz_bio_block_html')) {
    function dz_bio_block_html($bio_raw, $bio_limit = 150) {
        $bio_raw       = (string)$bio_raw;
        $bio_short_raw = $bio_raw;
        $bio_rest_raw  = '';
        if (mb_strlen($bio_raw) > $bio_limit) {
            $bio_short_raw = mb_substr($bio_raw, 0, $bio_limit);
            $bio_rest_raw  = mb_substr($bio_raw, $bio_limit);
            /* don't cut a word / link / @mention in half — finish the current word first */
            if (preg_match('/^\S+/u', $bio_rest_raw, $bio_tail)) {
                $bio_short_raw .= $bio_tail[0];
                $bio_rest_raw   = mb_substr($bio_rest_raw, mb_strlen($bio_tail[0]));
            }
        }
        if (trim($bio_rest_raw) === '') {
            return nl2br(dz_bio_html($bio_raw));
        }
        ob_start(); ?>
        <span id="rp-bio-short"><?= nl2br(dz_bio_html($bio_short_raw)) ?><span id="rp-bio-dots">...</span></span>
        <span id="rp-bio-rest" style="display:none;"><?= nl2br(dz_bio_html($bio_rest_raw)) ?></span>
        <span id="rp-bio-more" class="rp-bio-toggle"
              onclick="document.getElementById('rp-bio-rest').style.display='inline';document.getElementById('rp-bio-dots').style.display='none';document.getElementById('rp-bio-less').style.display='inline';this.style.display='none';(window.dzBioRefit||function(){})();">more</span>
        <span id="rp-bio-less" class="rp-bio-toggle" style="display:none;"
              onclick="document.getElementById('rp-bio-rest').style.display='none';document.getElementById('rp-bio-dots').style.display='inline';document.getElementById('rp-bio-more').style.display='inline';this.style.display='none';(window.dzBioRefit||function(){})();">close</span>
        <?php
        return ob_get_clean();
    }
}

/* ── pulls the first email address out of any string ── */
if (!function_exists('dz_extract_email')) {
    function dz_extract_email($text) {
        if (!$text) return '';
        if (preg_match('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9\-]+(?:\.[a-zA-Z0-9\-]+)*\.[a-zA-Z]{2,}/', $text, $m)) {
            return $m[0];
        }
        return '';
    }
}

/* ── builds the LIST of every email link on a profile (links first, then the bio).
   Used by the page and by the live-update endpoint. ── */
if (!function_exists('dz_collect_emails')) {
    function dz_collect_emails($links, $bio) {
        $out = [];
        $seen = [];

        foreach ((array)$links as $pl_row) {
            $pl_url = trim($pl_row['url'] ?? '');
            if ($pl_url === '') continue;
            $pl_title = trim($pl_row['title'] ?? '');

            $pl_em = dz_extract_email($pl_url);
            if ($pl_em !== '') {
                $pe_href  = 'mailto:' . $pl_em;
                $pe_label = $pl_em;
                $pe_ext   = false;
            } elseif (preg_match('~^(https?://)?(www\.)?(mail\.google\.com|gmail\.com)~i', $pl_url)) {
                $pe_href  = preg_match('~^https?://~i', $pl_url) ? $pl_url : 'https://' . $pl_url;
                $pe_label = ($pl_title !== '') ? $pl_title : 'Gmail';
                $pe_ext   = true;
            } else {
                continue;
            }

            $pe_key = strtolower($pe_href);
            if (isset($seen[$pe_key])) continue;
            $seen[$pe_key] = true;
            $out[] = ['href' => $pe_href, 'label' => $pe_label, 'external' => $pe_ext, 'type' => 'email'];
        }

        $bio = (string)$bio;
        if ($bio !== '') {
            if (preg_match_all('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9\-]+(?:\.[a-zA-Z0-9\-]+)*\.[a-zA-Z]{2,}/', $bio, $bio_matches)) {
                foreach ($bio_matches[0] as $bio_em) {
                    $pe_href = 'mailto:' . $bio_em;
                    $pe_key  = strtolower($pe_href);
                    if (isset($seen[$pe_key])) continue;
                    $seen[$pe_key] = true;
                    $out[] = ['href' => $pe_href, 'label' => $bio_em, 'external' => false, 'type' => 'email'];
                }
            }
            /* Gmail web links typed into the bio (mail.google.com / gmail.com URLs) */
            if (preg_match_all('~(?<![@\w.\-/])(?:https?://(?:www\.)?|(?:www\.)?)(?:mail\.google\.com|gmail\.com)(?:/[^\s<]*)?~i', $bio, $bio_gm)) {
                foreach ($bio_gm[0] as $gm_url) {
                    $pe_href = preg_match('~^https?://~i', $gm_url) ? $gm_url : 'https://' . $gm_url;
                    $pe_key  = strtolower($pe_href);
                    if (isset($seen[$pe_key])) continue;
                    $seen[$pe_key] = true;
                    $out[] = ['href' => $pe_href, 'label' => 'Gmail', 'external' => true, 'type' => 'email'];
                }
            }
        }
        return $out;
    }
}

/* ── phone numbers found in a text (bio): 8–15 digits, optional + and common separators ── */
if (!function_exists('dz_extract_phones')) {
    function dz_extract_phones($text) {
        $out = [];
        if (!$text) return $out;
        if (preg_match_all('/(?<![\w@.\/:\-])\+?\(?\d[\d\s().\-]{6,20}\d(?![\w@])/', (string)$text, $m)) {
            foreach ($m[0] as $raw) {
                $raw = trim($raw);
                if (preg_match('/^\d{4}[\-\/.]\d{1,2}[\-\/.]\d{1,2}$/', $raw) || preg_match('/^\d{1,2}[\-\/.]\d{1,2}[\-\/.]\d{2,4}$/', $raw)) continue;   // a date
                $digits = preg_replace('/\D+/', '', $raw);
                if (strlen($digits) < 8 || strlen($digits) > 15) continue;
                $out[] = $raw;
            }
        }
        return $out;
    }
}

/* ── the About me "Current location" (shown under the links). Table found by its current_location column. ── */
if (!function_exists('dz_about_location')) {
    function dz_about_location($conn, $uid) {
        static $tbl = null;
        $uid = (int)$uid;
        if ($uid <= 0 || !$conn) return '';
        try {
            if ($tbl === null && isset($_SESSION['dz_tbl_loc'])) $tbl = (string)$_SESSION['dz_tbl_loc'];   // remembered for the session: no information_schema scan on every page view
            if ($tbl === null) {
                $tbl = '';
                $q = $conn->query("SELECT c.TABLE_NAME FROM information_schema.COLUMNS c
                                   JOIN information_schema.COLUMNS u ON u.TABLE_SCHEMA = c.TABLE_SCHEMA AND u.TABLE_NAME = c.TABLE_NAME AND u.COLUMN_NAME = 'user_id'
                                   WHERE c.TABLE_SCHEMA = DATABASE() AND c.COLUMN_NAME = 'current_location' LIMIT 1");
                if ($q instanceof mysqli_result && ($qr = $q->fetch_assoc())) $tbl = (string)$qr['TABLE_NAME'];
                $_SESSION['dz_tbl_loc'] = $tbl;
            }
            if ($tbl === '' || !preg_match('/^[A-Za-z0-9_]+$/', $tbl)) return '';
            $r = $conn->query("SELECT current_location FROM `$tbl` WHERE user_id = $uid LIMIT 1");
            if ($r instanceof mysqli_result && ($row = $r->fetch_assoc())) return trim((string)($row['current_location'] ?? ''));
        } catch (Throwable $e) {}
        return '';
    }
}

/* ── the About me "Contact" tab (email + phone). Only people who track the owner (and the owner) get these.
   The table is found by its contact_email column, so it works whatever that table is called. ── */
if (!function_exists('dz_about_contacts')) {
    function dz_about_contacts($conn, $uid) {
        static $tbl = null;
        $uid = (int)$uid; $res = ['email' => '', 'phone' => ''];
        if ($uid <= 0 || !$conn) return $res;
        try {
            if ($tbl === null && isset($_SESSION['dz_tbl_contact'])) $tbl = (string)$_SESSION['dz_tbl_contact'];
            if ($tbl === null) {
                $tbl = '';
                $q = $conn->query("SELECT c.TABLE_NAME FROM information_schema.COLUMNS c
                                   JOIN information_schema.COLUMNS u ON u.TABLE_SCHEMA = c.TABLE_SCHEMA AND u.TABLE_NAME = c.TABLE_NAME AND u.COLUMN_NAME = 'user_id'
                                   WHERE c.TABLE_SCHEMA = DATABASE() AND c.COLUMN_NAME = 'contact_email' LIMIT 1");
                if ($q instanceof mysqli_result && ($qr = $q->fetch_assoc())) $tbl = (string)$qr['TABLE_NAME'];
                $_SESSION['dz_tbl_contact'] = $tbl;
            }
            if ($tbl === '' || !preg_match('/^[A-Za-z0-9_]+$/', $tbl)) return $res;
            $hasPhone = false;
            $pc = $conn->query("SHOW COLUMNS FROM `$tbl` LIKE 'contact_phone'");
            if ($pc instanceof mysqli_result && $pc->num_rows > 0) $hasPhone = true;
            $r = $conn->query("SELECT contact_email" . ($hasPhone ? ", contact_phone" : "") . " FROM `$tbl` WHERE user_id = $uid LIMIT 1");
            if ($r instanceof mysqli_result && ($row = $r->fetch_assoc())) {
                $res['email'] = trim((string)($row['contact_email'] ?? ''));
                $res['phone'] = $hasPhone ? trim((string)($row['contact_phone'] ?? '')) : '';
            }
        } catch (Throwable $e) {}
        return $res;
    }
}

/* ── every way to contact a profile: emails (links, bio, About me) and phone numbers (bio, About me).
   $about = result of dz_about_contacts() (pass [] when the viewer must not see it). Used by the page and the live endpoint. ── */
if (!function_exists('dz_collect_contacts')) {
    function dz_collect_contacts($links, $bio, $about = []) {
        $out  = dz_collect_emails($links, $bio);
        $seen = [];
        foreach ($out as $o) $seen[strtolower($o['href'])] = true;
        $seenPh = [];
        $pk = function ($d) { return substr(preg_replace('/\D/', '', (string)$d), -10); };   // same number written +234… or 0… counts once
        foreach ((array)$links as $pl_row) {   // tel: links
            $u = trim($pl_row['url'] ?? '');
            if (preg_match('~^tel:\s*(\+?[\d\s().\-]{6,})$~i', $u, $tm)) {
                $d = preg_replace('/[^\d+]/', '', $tm[1]);
                if (!isset($seenPh[$pk($d)])) { $seenPh[$pk($d)] = true; $out[] = ['href' => 'tel:' . $d, 'label' => trim($tm[1]), 'external' => false, 'type' => 'phone']; }
            }
        }
        foreach (dz_extract_phones((string)$bio) as $ph) {
            $dg = preg_replace('/\D/', '', $ph);
            if (isset($seenPh[$pk($dg)])) continue;
            $seenPh[$pk($dg)] = true;
            $out[] = ['href' => 'tel:' . (strpos($ph, '+') === 0 ? '+' : '') . $dg, 'label' => $ph, 'external' => false, 'type' => 'phone'];
        }
        if (!empty($about['email']) && ($em = dz_extract_email($about['email'])) !== '' && !isset($seen['mailto:' . strtolower($em)])) {
            $seen['mailto:' . strtolower($em)] = true;
            $out[] = ['href' => 'mailto:' . $em, 'label' => $em, 'external' => false, 'type' => 'email'];
        }
        if (!empty($about['phone'])) {
            $dg = preg_replace('/\D/', '', (string)$about['phone']);
            if (strlen($dg) >= 6 && strlen($dg) <= 18 && !isset($seenPh[$pk($dg)])) {
                $seenPh[$pk($dg)] = true;
                $out[] = ['href' => 'tel:' . (strpos(trim($about['phone']), '+') === 0 ? '+' : '') . $dg, 'label' => trim((string)$about['phone']), 'external' => false, 'type' => 'phone'];
            }
        }
        return $out;
    }
}

/* ── renders the Contact button (it used to be the Email button).
   • 0 contacts → nothing
   • 1 contact  → plain "Contact" button that opens it (mail app / phone dialler)
   • 2+         → "Contact ▾" (filled chevron) that opens a dropdown listing every email and phone number ── */
if (!function_exists('dz_render_email_btn')) {
    function dz_render_email_btn($emails) {
        if (empty($emails) || !is_array($emails)) return;

        if (count($emails) === 1) {
            $e = $emails[0];
            echo '<a href="' . htmlspecialchars($e['href']) . '" class="rp-rect-btn email"'
               . (!empty($e['external']) ? ' target="_blank" rel="noopener"' : '')
               . '>Contact</a>';
            return;
        }

        echo '<div class="rp-email-wrap" id="rpEmailWrap">';
        echo '<button type="button" class="rp-rect-btn email has-chevron" id="rpEmailBtn" onclick="dzToggleEmailMenu(event)" aria-haspopup="true" aria-expanded="false">';
        echo '<span>Contact</span><i class="ri-arrow-drop-down-fill" id="rpEmailChevron"></i>';
        echo '</button>';
        echo '<div class="rp-email-menu" id="rpEmailMenu" role="menu">';
        foreach ($emails as $e) {
            echo '<a role="menuitem" class="rp-email-item" href="' . htmlspecialchars($e['href']) . '"'
               . (!empty($e['external']) ? ' target="_blank" rel="noopener"' : '') . '>';
            echo '<i class="' . (($e['type'] ?? 'email') === 'phone' ? 'ri-phone-line' : 'ri-mail-line') . '"></i><span>' . htmlspecialchars($e['label']) . '</span>';
            echo '</a>';
        }
        echo '</div></div>';
    }
}

/* ══════════════════════════════════════════════════════════════
   VIBE CHECK — backend
   • Table: profile_vibes (target_id, voter_id, vibe) — created / upgraded
     automatically the first time it is used.
   • One vote per person per profile (voting again CHANGES their vote).
   • A voter can UNDO their own vote (vibe_action=undo).
   • You can NOT vote on your own profile — you only see the results.
   • Only people who track the profile can vote (and not if blocked either way).
   • The vibe with the MOST votes is the result shown everywhere.
   • The owner can switch Vibe Check on / off. It is ON by default.
     Stored in user_settings.vibe_check_enabled (column is added
     automatically the first time the owner flips the switch).
   If your real top PHP already answers `vibe_action` requests and exits
   before reaching this point, that one wins and this block is skipped.
   ══════════════════════════════════════════════════════════════ */
if (!function_exists('dz_vibe_prepare')) {
    /* the vibe tables are created once by profile_setup.php — nothing to check per request */
    function dz_vibe_prepare() { dz_schema_ensure(); }
}
if (!function_exists('dz_vibe_enabled')) {
    function dz_vibe_enabled($uid) {
        global $conn;
        if (!isset($conn) || !$conn) return true;
        $uid = (int)$uid;
        try {
            $r = $conn->query("SELECT enabled FROM profile_vibe_settings WHERE user_id = $uid LIMIT 1");
            if ($r instanceof mysqli_result) {
                $row = $r->fetch_assoc();
                if ($row) return ((int)$row['enabled'] === 1);
            }
        } catch (Throwable $e) {}
        /* older installs: value may still live in user_settings.vibe_check_enabled */
        try {
            $r = $conn->query("SELECT vibe_check_enabled FROM user_settings WHERE user_id = $uid LIMIT 1");
            if ($r instanceof mysqli_result) {
                $row = $r->fetch_assoc();
                if ($row && $row['vibe_check_enabled'] !== null) return ((int)$row['vibe_check_enabled'] === 1);
            }
        } catch (Throwable $e) {}
        return true;   // default = enabled
    }
}
if (!function_exists('dz_vibe_set_enabled')) {
    function dz_vibe_set_enabled($uid, $on) {
        global $conn;
        if (!isset($conn) || !$conn) return false;
        $uid = (int)$uid; $on = $on ? 1 : 0;
        try {
            $conn->query("INSERT INTO profile_vibe_settings (user_id, enabled) VALUES ($uid, $on)
                          ON DUPLICATE KEY UPDATE enabled = $on");
            return true;
        } catch (Throwable $e) { return false; }
    }
}
/* headline for a vote list sorted by votes DESC: one winner → "Chill"; a tie → "Chill & Funny"; 3+ tied → "Chill, Funny & 1 more" */
if (!function_exists('dz_vibe_headline')) {
    function dz_vibe_headline(array $rows) {
        if (!$rows) return '';
        $max = (int)$rows[0]['c']; $names = [];
        foreach ($rows as $r) { if ((int)$r['c'] === $max) $names[] = (string)$r['vibe']; else break; }
        $n = count($names);
        if ($n === 1) return $names[0];
        if ($n === 2) return $names[0] . ' & ' . $names[1];
        return $names[0] . ', ' . $names[1] . ' & ' . ($n - 2) . ' more';
    }
}
if (!function_exists('dz_vibe_results')) {
    function dz_vibe_results($tid, $uid) {
        global $conn;
        dz_vibe_prepare();
        $out = ['top' => '', 'total' => 0, 'mine' => '', 'rows' => []];
        if (!isset($conn) || !$conn) return $out;
        $tid = (int)$tid; $uid = (int)$uid;
        try {
            $r = $conn->query("SELECT vibe, COUNT(*) AS c FROM profile_vibes WHERE target_id = $tid GROUP BY vibe ORDER BY c DESC, vibe ASC");
            if ($r instanceof mysqli_result) {
                $all = [];
                while ($row = $r->fetch_assoc()) {
                    $all[] = ['vibe' => (string)$row['vibe'], 'c' => (int)$row['c']];
                    $out['total'] += (int)$row['c'];
                }
                $out['top']  = dz_vibe_headline($all);          // tie-aware headline
                $out['rows'] = array_slice($all, 0, 3);          // top 3 with counts (the breakdown)
            }
            if ($uid > 0) {
                $m = $conn->query("SELECT vibe FROM profile_vibes WHERE target_id = $tid AND voter_id = $uid LIMIT 1");
                if ($m instanceof mysqli_result && ($mr = $m->fetch_assoc())) $out['mine'] = $mr['vibe'];
            }
        } catch (Throwable $e) {}
        return $out;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vibe_action']) && isset($conn) && $conn) {
    if (session_status() === PHP_SESSION_NONE) @session_start();
    header('Content-Type: application/json');
    $vb_uid = (int)($_SESSION['user_id'] ?? 0);
    $vb_tid = (int)($_POST['target_id'] ?? 0);
    $vb_act = (string)$_POST['vibe_action'];
    $vb_en  = dz_vibe_enabled($vb_tid);

    if ($vb_uid <= 0 || $vb_tid <= 0) { echo json_encode(['status' => 'error', 'msg' => 'login']); exit; }

    if ($vb_act === 'toggle') {
        if ($vb_uid !== $vb_tid) { echo json_encode(['status' => 'error', 'msg' => 'not allowed']); exit; }
        $vb_new = !empty($_POST['enabled']) && $_POST['enabled'] !== '0';
        $ok = dz_vibe_set_enabled($vb_tid, $vb_new);
        echo json_encode(['status' => $ok ? 'ok' : 'error', 'enabled' => $ok ? $vb_new : $vb_en]);
        exit;
    }

    /* can this viewer vote? (not themselves, vibe check on, and they track the profile) */
    $vb_can = false; $vb_follow = false;
    if ($vb_uid !== $vb_tid && $vb_en) {
        try {
            $f = $conn->prepare("SELECT 1 FROM followers WHERE follower_id = ? AND following_id = ? LIMIT 1");
            if ($f) {
                $f->bind_param("ii", $vb_uid, $vb_tid);
                $f->execute();
                $vb_can = $f->get_result()->num_rows > 0; $vb_follow = $vb_can;
                $f->close();
            }
        } catch (Throwable $e) {}
    }

    /* blocked either way → no voting and no results */
    $vb_blocked = false;
    if ($vb_uid !== $vb_tid) {
        try {
            $bq = $conn->prepare("SELECT 1 FROM blocks WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?) LIMIT 1");
            if ($bq) {
                $bq->bind_param("iiii", $vb_uid, $vb_tid, $vb_tid, $vb_uid);
                $bq->execute();
                $vb_blocked = $bq->get_result()->num_rows > 0;
                $bq->close();
            }
        } catch (Throwable $e) {}
    }
    if ($vb_blocked) $vb_can = false;

    /* UNDO: removes the viewer's own vote (never anyone else's), then falls through to return the fresh result */
    if ($vb_act === 'undo') {
        if ($vb_uid === $vb_tid) { echo json_encode(['status' => 'error', 'msg' => 'not allowed']); exit; }
        dz_vibe_prepare();
        try {
            $ud = $conn->prepare("DELETE FROM profile_vibes WHERE target_id = ? AND voter_id = ?");
            if (!$ud) throw new Exception('prepare');
            $ud->bind_param("ii", $vb_tid, $vb_uid);
            $ud->execute();
            $ud->close();
        } catch (Throwable $e) {
            echo json_encode(['status' => 'error', 'msg' => 'save failed']); exit;
        }
    }

    if ($vb_act === 'submit') {
        $vb_choice = trim((string)($_POST['vibe'] ?? ''));
        if (!$vb_can || $vb_choice === '' || mb_strlen($vb_choice) > 100) {
            echo json_encode(['status' => 'error', 'msg' => 'not allowed']); exit;
        }
        dz_vibe_prepare();
        try {
            $d = $conn->prepare("DELETE FROM profile_vibes WHERE target_id = ? AND voter_id = ?");
            if ($d) { $d->bind_param("ii", $vb_tid, $vb_uid); $d->execute(); $d->close(); }
            $i = $conn->prepare("INSERT INTO profile_vibes (target_id, voter_id, vibe) VALUES (?, ?, ?)");
            if (!$i) throw new Exception('prepare');
            $i->bind_param("iis", $vb_tid, $vb_uid, $vb_choice);
            $i->execute();
            $i->close();
        } catch (Throwable $e) {
            echo json_encode(['status' => 'error', 'msg' => 'save failed']); exit;
        }
    }

    $vb_res = dz_vibe_results($vb_tid, $vb_uid);
    /* hidden when Vibe Check is OFF, when blocked either way, or when the profile is private and the viewer doesn't track it */
    if ($vb_uid !== $vb_tid && (!$vb_en || $vb_blocked || (!$vb_follow && dz_privacy_load($vb_tid)['is_private']))) {
        $vb_res = ['top' => '', 'total' => 0, 'mine' => '', 'rows' => []];
    }
    echo json_encode([
        'status'   => 'ok',
        'top'      => $vb_res['top'],
        'total'    => $vb_res['total'],
        'mine'     => $vb_res['mine'],
        'rows'     => $vb_res['rows'],
        'enabled'  => $vb_en,
        'can_vote' => $vb_can
    ]);
    exit;
}

/* ── "Online" heartbeat: stamps the logged-in user's activity so other people see the green dot.
   • Creates users.last_seen (DATETIME) automatically if none of last_seen / last_active /
     last_activity / last_online exists yet.
   • Only writes to `last_seen` (it never touches an activity column you already maintain).
   • Skipped when the user turned "Show online status" off in Privacy Controls.
   • The page also pings every 60s while it is open (see the script at the bottom).
   NOTE: for the dot to be accurate site-wide, put the same one-line UPDATE in a file
   every page includes (your header / session file). ── */
if (!function_exists('dz_touch_last_seen')) {
    function dz_touch_last_seen($uid) {
        global $conn;
        $uid = (int)$uid;
        if ($uid <= 0 || !isset($conn) || !$conn) return;
        if (!empty($_SESSION['dz_seen_bad'])) return;      // no last_seen column on this install → stop trying
        $dz_p = dz_privacy_load($uid);
        if (empty($dz_p['show_online'])) return;           // "Show online status" is off
        try {
            $ok = $conn->query("UPDATE users SET last_seen = NOW() WHERE id = $uid");
            if ($ok === false) $_SESSION['dz_seen_bad'] = 1;
        } catch (Throwable $e) { $_SESSION['dz_seen_bad'] = 1; }
    }
}
/* ── PROFILE VIEWER LOCK: the owner can stop other people from opening the enlarged profile picture viewer ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pic_viewer_action']) && isset($conn) && $conn) {
    header('Content-Type: application/json');
    $pv_uid = (int)$current_user;
    if (!$pv_uid) { echo json_encode(['status' => 'error']); exit; }
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS profile_pic_lock (user_id INT NOT NULL PRIMARY KEY, locked TINYINT(1) NOT NULL DEFAULT 0)");
        $pv_lock = !empty($_POST['locked']) ? 1 : 0;
        $st = $conn->prepare("INSERT INTO profile_pic_lock (user_id, locked) VALUES (?, ?) ON DUPLICATE KEY UPDATE locked = VALUES(locked)");
        $st->bind_param("ii", $pv_uid, $pv_lock); $st->execute(); $st->close();
        echo json_encode(['status' => 'ok', 'locked' => (bool)$pv_lock]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dz_ping']) && isset($conn) && $conn) {
    if (session_status() === PHP_SESSION_NONE) @session_start();
    dz_touch_last_seen((int)($_SESSION['user_id'] ?? 0));
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok']);
    exit;
}

// Core identity — only fall back if truly missing.
$current_user = isset($current_user) ? $current_user : ($_SESSION['user_id'] ?? 0);
$profile_id   = isset($profile_id)   ? $profile_id   : (isset($_GET['user_id']) ? (int)$_GET['user_id'] : $current_user);

/* stamp my own activity (throttled to once a minute) */
if (!empty($current_user) && (empty($_SESSION['dz_seen_ts']) || time() - (int)$_SESSION['dz_seen_ts'] > 60)) {
    dz_touch_last_seen($current_user);
    $_SESSION['dz_seen_ts'] = time();
}

/* Vibe Check on/off for THIS profile (default ON) */
if (!isset($vibe_enabled)) $vibe_enabled = dz_vibe_enabled($profile_id);

/* Default profile picture = mee.jpg (used whenever a user has no pic) */
if (!isset($nav_profile_pic) || $nav_profile_pic === '' || substr($nav_profile_pic, -1) === '/') $nav_profile_pic = 'mee.jpg';
if (!isset($profilePic)      || $profilePic === ''      || substr($profilePic, -1) === '/')      $profilePic      = 'mee.jpg';
if (!empty($DZ_SUSP)) { $profilePic = 'data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 10 10%27%3E%3Crect width=%2710%27 height=%2710%27 fill=%27none%27/%3E%3C/svg%3E'; }   /* suspended: plain grey picture */
if (!isset($user) || !is_array($user)) $user = ['username' => 'user', 'nickname' => '', 'pronouns' => '', 'user_type' => '', 'bio' => ''];

if (!isset($followers_count)) $followers_count = 0;
if (!isset($following_count)) $following_count = 0;

/* Tracking = people you track. */
if (!isset($post_count))      $post_count = 0;
if (!isset($activity_vibe))   $activity_vibe = '';
if (!isset($show_bio))        $show_bio = !empty($user['bio']);

/* ── Profile type (picked in Settings → Profile Type, stored in
   user_settings.profile_type). Shown as dark grey plain text above the bio.
   No-op if your real top PHP already set $profile_type. Wrapped in try/catch
   so a missing column can never crash the profile page. ── */
if (!isset($profile_type)) {
    $profile_type = '';
    if (isset($conn) && $conn && !empty($profile_id)) {
        try {
            $pt_stmt = $conn->prepare("SELECT profile_type FROM user_settings WHERE user_id = ? LIMIT 1");
            if ($pt_stmt) {
                $pt_stmt->bind_param("i", $profile_id);
                $pt_stmt->execute();
                $pt_row = $pt_stmt->get_result()->fetch_assoc();
                $profile_type = trim((string)($pt_row['profile_type'] ?? ''));
                $pt_stmt->close();
            }
        } catch (Throwable $e) {
            $profile_type = '';
        }
    }
}

/* ── Pods count — how many pods (groups) this profile has joined.
   Mirrors the COUNT(*) group_list.php runs against group_members. */
if (!isset($pod_count)) {
    $pod_count = 0;
    if (isset($conn)) {
        $pc_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM group_members WHERE user_id = ?");
        if ($pc_stmt) {
            $pc_stmt->bind_param("i", $profile_id);
            $pc_stmt->execute();
            $pod_count = (int)$pc_stmt->get_result()->fetch_assoc()['c'];
            $pc_stmt->close();
        }
    }
}

if (!isset($blocked_check)) {
    $blocked_check = false;
    if (!empty($current_user) && !empty($profile_id) && $current_user != $profile_id && isset($conn)) {
        $bc = $conn->prepare("SELECT id FROM blocks WHERE blocker_id = ? AND blocked_id = ?");
        $bc->bind_param("ii", $current_user, $profile_id);
        $bc->execute();
        $blocked_check = $bc->get_result()->num_rows > 0;
        $bc->close();
    }
}
if (!isset($is_blocked))          $is_blocked = $blocked_check;
if (!isset($is_blocked_by_owner)) $is_blocked_by_owner = false;
if (!isset($can_view))            $can_view = !$blocked_check;
if (!isset($follow_check))        $follow_check = false;
if (!isset($profile_is_private))  $profile_is_private = false;
if (!isset($track_request_status)) $track_request_status = null;
if (!isset($is_muted_by_me))       $is_muted_by_me = false;
if (!isset($is_restricted_by_me))  $is_restricted_by_me = false;

/* ══════════════════════════════════════════════════════════════
   ACCESS CHECKS — blocked / private wall / track-request buttons
   (moved up here from the grids section so they run BEFORE any HTML is
   printed: the redirects below need that)
   ══════════════════════════════════════════════════════════════ */
$is_blocked_by_owner = false;
if (!empty($current_user) && $current_user != $profile_id && isset($conn) && $conn) {
    $block_check = $conn->prepare("SELECT id FROM blocks WHERE blocker_id=? AND blocked_id=?");
    if ($block_check) {
        $block_check->bind_param("ii", $profile_id, $current_user);
        $block_check->execute();
        $block_check->store_result();
        $is_blocked_by_owner = $block_check->num_rows > 0;
        $block_check->close();
    }
}

/* does the viewer already track this profile? (people who tracked BEFORE it went private keep access) */
$dz_follows = false;
if (!empty($current_user) && $current_user != $profile_id && isset($conn) && $conn) {
    $fq = $conn->prepare("SELECT 1 FROM followers WHERE follower_id=? AND following_id=? LIMIT 1");
    if ($fq) {
        $fq->bind_param("ii", $current_user, $profile_id);
        $fq->execute();
        $dz_follows = $fq->get_result()->num_rows > 0;
        $fq->close();
    }
}
if (!empty($follow_check)) $dz_follows = true;

/* Privacy Controls of the profile owner + of the viewer */
$dz_priv        = dz_privacy_load($profile_id);
$dz_viewer_priv = (!empty($current_user) && $current_user != $profile_id) ? dz_privacy_load($current_user) : $dz_priv;

/* fully private profile? (your settings row decides, plus the level chosen in Privacy Controls) */
if (isset($settings) && is_array($settings)) $profile_is_private = !empty($settings['is_fully_private']);
$profile_is_private = ($profile_is_private || $dz_priv['is_private']);

$viewer_is_approved = false;
$wall_status        = null;
if ($profile_is_private && !empty($current_user) && $current_user != $profile_id && isset($conn) && $conn) {
    $apr = $conn->prepare("SELECT id FROM track_requests WHERE requester_id=? AND target_id=? AND status='approved'");
    if ($apr) {
        $apr->bind_param("ii", $current_user, $profile_id);
        $apr->execute();
        $viewer_is_approved = $apr->get_result()->num_rows > 0;
        $apr->close();
    }
    $wrs = $conn->prepare("SELECT status FROM track_requests WHERE requester_id=? AND target_id=?");
    if ($wrs) {
        $wrs->bind_param("ii", $current_user, $profile_id);
        $wrs->execute();
        $wrs_row     = $wrs->get_result()->fetch_assoc();
        $wall_status = $wrs_row ? $wrs_row['status'] : null;
        $wrs->close();
    }
}
if ($dz_follows) $viewer_is_approved = true;
if ($track_request_status === null && $wall_status === 'pending') $track_request_status = 'pending';

/* Request to Track / cancel the request
   • only on PRIVATE profiles, never when the owner blocked you, never when you already track them
   • an already-approved request is never reset back to pending */
if (isset($_POST['send_track_request']) && !empty($current_user) && $current_user != $profile_id && isset($conn) && $conn && $profile_is_private && !$is_blocked_by_owner && !$dz_follows) {
    $str = $conn->prepare("INSERT INTO track_requests (requester_id,target_id,status,created_at) VALUES (?,?,'pending',NOW()) ON DUPLICATE KEY UPDATE created_at=IF(status='approved', created_at, NOW()), status=IF(status='approved', 'approved', 'pending')");
    if ($str) { $str->bind_param("ii", $current_user, $profile_id); $str->execute(); $str->close(); }
    header("Location: profile.php?user_id=" . (int)$profile_id); exit;
}
if (isset($_POST['cancel_track_request']) && !empty($current_user) && $current_user != $profile_id && isset($conn) && $conn) {
    $ctr = $conn->prepare("DELETE FROM track_requests WHERE requester_id=? AND target_id=? AND status='pending'");
    if ($ctr) { $ctr->bind_param("ii", $current_user, $profile_id); $ctr->execute(); $ctr->close(); }
    header("Location: profile.php?user_id=" . (int)$profile_id); exit;
}

$show_private_wall = (
    $profile_is_private &&
    $current_user != $profile_id &&
    !$viewer_is_approved
);

/* ── Privacy Controls enforcement ──
   Private + Full Private : memes / reposts / tags / highlights / right column are walled until approved
   Full Private           : also hides bio, links, email, pills and profile type (only name + photo show)
   Limited + all private  : Tracker / Tracking counts hidden from people who don't track this profile
   (a visitor who tracks the profile, or the owner, always sees everything their level allows) */
$dz_hide_extra = ($show_private_wall && $dz_priv['level'] === 'full');
if ($show_private_wall) { $profile_highlights = []; }
if ($dz_hide_extra) {
    $show_bio = false; $profile_links = []; $profile_emails = []; $profile_type = '';
    $user['pronouns'] = ''; $user['user_type'] = ''; $activity_vibe = '';
}
$dz_hide_counts = ($current_user != $profile_id) && !$dz_follows && ($dz_priv['level'] !== 'public');
$stat_locked    = (!$can_view || $dz_hide_counts);

/* ── Online status of the profile owner (other users' profiles only).
   Online = active within the last 5 minutes. Looks for the first of these columns
   on `users`: last_seen / last_active / last_activity / last_online (datetime or unix time),
   then falls back to an `is_online` flag. If your real top PHP already sets
   $profile_online (true/false) that value is used instead.
   Hidden when EITHER person turned "Show online status" off. ── */
if (!isset($profile_online)) {
    $profile_online = false;
    if ($current_user != $profile_id && !empty($profile_id) && isset($conn) && $conn && !$blocked_check && !$is_blocked_by_owner && !empty($dz_priv['show_online']) && !empty($dz_viewer_priv['show_online'])
        && (!function_exists('dz_presence_allowed') || dz_presence_allowed($conn, (int)$profile_id, (int)$current_user, 'online'))) {
        $po_found = false;
        foreach (['last_seen', 'last_active', 'last_activity', 'last_online'] as $po_col) {
            try {
                $po_r = $conn->query("SELECT `$po_col` AS t, TIMESTAMPDIFF(SECOND, `$po_col`, NOW()) AS d FROM users WHERE id = " . (int)$profile_id . " LIMIT 1");
                if ($po_r instanceof mysqli_result) {
                    $po_row = $po_r->fetch_assoc();
                    if ($po_row && !empty($po_row['t'])) {
                        $po_found = true;
                        if ($po_row['d'] !== null)      $po_age = (int)$po_row['d'];
                        elseif (is_numeric($po_row['t'])) $po_age = time() - (int)$po_row['t'];
                        else                             $po_age = time() - (int)strtotime((string)$po_row['t']);
                        if ($po_age <= 300) { $profile_online = true; break; }
                    }
                }
            } catch (Throwable $e) {}
        }
        if (!$po_found) {
            try {
                $po_r = $conn->query("SELECT is_online AS t FROM users WHERE id = " . (int)$profile_id . " LIMIT 1");
                if ($po_r instanceof mysqli_result && ($po_row = $po_r->fetch_assoc())) $profile_online = ((int)$po_row['t'] === 1);
            } catch (Throwable $e) {}
        }
    }
}

/* ── Linked accounts (for the username-row account switcher) ──
   No-op once your real top PHP populates $_SESSION['accounts']. */
if (!isset($_SESSION['accounts']) || !is_array($_SESSION['accounts'])) {
    $_SESSION['accounts'] = $current_user ? [$current_user] : [];
}
if (!isset($linked_count)) $linked_count = count($_SESSION['accounts']);
if (!isset($at_limit))     $at_limit = $linked_count >= 5;

if (!isset($topbar_show_track))  $topbar_show_track = ($current_user != $profile_id) && !$follow_check && !$blocked_check;
if (!isset($topbar_is_private))  $topbar_is_private = $profile_is_private;
if (!isset($topbar_req_status))  $topbar_req_status = $track_request_status;
if (!isset($notif_has_unread))   $notif_has_unread = false;
if (!isset($total_unread))       $total_unread = 0;

/* ── Pending track requests (owner only, private inbox):
   count + the LATEST requester's profile pic for the topbar.
   Uses the same track_requests table notifications.php reads
   (requester_id, target_id, status, created_at). ── */
if (!isset($track_req_count)) {
    $track_req_count = 0;
    $track_req_pic   = '';
    if ($current_user == $profile_id && isset($conn) && $conn) {
        try {
            $q = $conn->prepare("SELECT COUNT(*) AS c FROM track_requests WHERE target_id = ? AND status = 'pending'");
            if ($q) {
                $q->bind_param("i", $profile_id);
                $q->execute();
                $track_req_count = (int)$q->get_result()->fetch_assoc()['c'];
                $q->close();
            }
            if ($track_req_count > 0) {
                $q2 = $conn->prepare("SELECT u.profile_pic FROM track_requests r
                                      JOIN users u ON u.id = r.requester_id
                                      WHERE r.target_id = ? AND r.status = 'pending' $DZ_NOT_DEACT_U
                                      ORDER BY r.created_at DESC LIMIT 1");
                if ($q2) {
                    $q2->bind_param("i", $profile_id);
                    $q2->execute();
                    $rw = $q2->get_result()->fetch_assoc();
                    $track_req_pic = !empty($rw['profile_pic']) ? $rw['profile_pic'] : 'mee.jpg';
                    $q2->close();
                }
            }
        } catch (Throwable $e) {
            $track_req_count = 0;
        }
    }
}
if (!isset($track_req_pic)) $track_req_pic = 'mee.jpg';

/* ── Profile links (same visibility rule as the bio) ──
   No-op if your real top PHP already populated $profile_links. */
if (!isset($profile_links) || !is_array($profile_links)) {
    $profile_links = [];
    if (($show_bio || $current_user == $profile_id) && isset($conn)) {
        $pl_stmt = $conn->prepare("SELECT id, title, url, icon FROM links WHERE user_id=? ORDER BY created_at DESC");
        if ($pl_stmt) {
            $pl_stmt->bind_param("i", $profile_id);
            $pl_stmt->execute();
            $pl_res = $pl_stmt->get_result();
            while ($l = $pl_res->fetch_assoc()) $profile_links[] = $l;
            $pl_stmt->close();
        }
    }
}
if (!isset($profile_link_count)) $profile_link_count = count($profile_links);

/* ── Profile highlights (created in Settings → Highlights).
   Each row carries:
     title       → the highlight name (rename in Settings shows up here)
     cover_path  → the custom cover art picked in Settings (may be empty)
     first_path / first_type → the first media uploaded (used when there is no custom cover)
     song_path   → the highlight's song clip (may be empty)
   Cover rule = custom cover if set, otherwise the first media.
   Hidden when the viewer is blocked or can't view the profile. Wrapped in
   try/catch (with an old-schema fallback) so missing columns never crash the page.
   No-op if your real top PHP already populated $profile_highlights. ── */
if (!isset($profile_highlights) || !is_array($profile_highlights)) {
    $profile_highlights = [];
    if (isset($conn) && $conn && !empty($profile_id) && ($can_view || !$profile_is_private || $current_user == $profile_id) && !$blocked_check && !$is_blocked_by_owner) {
        $hl_sql_new = "SELECT h.id, h.title, h.cover_path, h.song_path,
                (SELECT COUNT(*) FROM highlight_media m WHERE m.highlight_id = h.id) AS media_count,
                (SELECT m2.file_path FROM highlight_media m2 WHERE m2.highlight_id = h.id ORDER BY m2.id ASC LIMIT 1) AS first_path,
                (SELECT m3.media_type FROM highlight_media m3 WHERE m3.highlight_id = h.id ORDER BY m3.id ASC LIMIT 1) AS first_type
                FROM highlights h WHERE h.user_id = ? HAVING media_count > 0 ORDER BY h.created_at DESC, h.id DESC";
        $hl_sql_old = "SELECT h.id, h.title, NULL AS cover_path, NULL AS song_path,
                (SELECT COUNT(*) FROM highlight_media m WHERE m.highlight_id = h.id) AS media_count,
                (SELECT m2.file_path FROM highlight_media m2 WHERE m2.highlight_id = h.id ORDER BY m2.id ASC LIMIT 1) AS first_path,
                (SELECT m3.media_type FROM highlight_media m3 WHERE m3.highlight_id = h.id ORDER BY m3.id ASC LIMIT 1) AS first_type
                FROM highlights h WHERE h.user_id = ? HAVING media_count > 0 ORDER BY h.created_at DESC, h.id DESC";
        foreach ([$hl_sql_new, $hl_sql_old] as $hl_sql) {
            try {
                $hl_stmt = $conn->prepare($hl_sql);
                if (!$hl_stmt) continue;               // new columns missing → try the old query
                $hl_stmt->bind_param("i", $profile_id);
                $hl_stmt->execute();
                $hl_res = $hl_stmt->get_result();
                $profile_highlights = [];
                while ($hl_row = $hl_res->fetch_assoc()) $profile_highlights[] = $hl_row;
                $hl_stmt->close();
                break;
            } catch (Throwable $e) {
                $profile_highlights = [];              // try the next query
            }
        }
    }
}
if (!isset($profile_highlight_count)) $profile_highlight_count = count($profile_highlights);

/* ── Profile emails (links first, then bio as a fallback) ──
   Builds a LIST of every email link on the profile (see dz_collect_emails above).
   1 entry → plain Email button. 2+ entries → Email ▾ with a dropdown.
   If nothing is found, the list stays empty (Block remains on other
   profiles; no Email button on your own). */
if (!isset($profile_emails) || !is_array($profile_emails)) {
    /* emails + phone numbers (links, bio, and — for the owner or people who track them — the About me Contact tab) */
    $dz_about_ct = (empty($dz_hide_extra) && empty($is_blocked) && empty($is_blocked_by_owner) && ($current_user == $profile_id || !empty($follow_check)))
                 ? dz_about_contacts($conn, $profile_id) : [];
    $profile_emails = dz_collect_contacts($profile_links, ($show_bio && !empty($user['bio'])) ? (string)$user['bio'] : '', $dz_about_ct);
}
/* kept for compatibility with any other code that still reads these */
if (!isset($profile_email_href)) {
    $profile_email_href     = !empty($profile_emails) ? $profile_emails[0]['href'] : '';
    $profile_email_external = !empty($profile_emails) ? !empty($profile_emails[0]['external']) : false;
}

/* ══════════════════════════════════════════════════════════════
   LIVE PROFILE UPDATE endpoint
   When the owner edits something in the profile modal (bio, username, …) the
   page updates itself instantly. Only the BIO needs the server (to render
   links / @mentions / the "more" toggle and to rebuild the Email button), so
   the browser sends the new bio here and gets the ready-made HTML back.
   Nothing is written to the database here — the modal already saved it.
   Only the owner of THIS page can use it.
   ══════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dz_live'])) {
    if (session_status() === PHP_SESSION_NONE) @session_start();
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    $lv_uid = (int)($_SESSION['user_id'] ?? 0);
    if ($lv_uid <= 0 || $lv_uid !== (int)$profile_id) { echo json_encode(['status' => 'error', 'msg' => 'login']); exit; }

    if ((string)$_POST['dz_live'] === 'bio') {
        $lv_bio = mb_substr((string)($_POST['value'] ?? ''), 0, 600);
        ob_start();
        dz_render_email_btn(dz_collect_contacts($profile_links, $lv_bio, dz_about_contacts($conn, $profile_id)));
        $lv_email = ob_get_clean();
        echo json_encode([
            'status'     => 'ok',
            'has_bio'    => (trim($lv_bio) !== ''),
            'bio_html'   => dz_bio_block_html($lv_bio),
            'email_html' => $lv_email,
        ]);
        exit;
    }
    echo json_encode(['status' => 'error', 'msg' => 'unknown']);
    exit;
}

/* ══════════════════════════════════════════════════════════════
   RIGHT-HAND COLUMN DATA (laptop / normal desktop view only shows it,
   but the data is prepared here).
   Every query goes through pf_q()/pf_rows(), which swallow errors, so a
   missing table or column can never crash the profile page — that card
   just doesn't show.
   ══════════════════════════════════════════════════════════════ */
/* view count under every grid card (memes.views, kept by dz_views.php) — 1.2K / 3.4M style */
if (!function_exists('pf_fmt_views')) { function pf_fmt_views($n) { $n = (int)$n; if (function_exists('dz_view_fmt')) return dz_view_fmt($n); if ($n >= 1000000) return rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.') . 'M'; if ($n >= 1000) return rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'K'; return (string)$n; } }
if (!function_exists('pf_view_count')) { function pf_view_count($conn, $mid) { static $memo = []; $mid = (int)$mid; if (isset($memo[$mid])) return $memo[$mid]; $v = 0; try { $q = @$conn->query('SELECT views FROM memes WHERE id = ' . $mid . ' LIMIT 1'); if ($q instanceof mysqli_result && ($r = $q->fetch_assoc())) $v = (int)($r['views'] ?? 0); } catch (Throwable $e) {} return $memo[$mid] = $v; } }
if (!function_exists('pf_h'))  { function pf_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('pf_q'))  {
    function pf_q($sql) {
        global $conn;
        if (!isset($conn) || !$conn) return false;
        try { return $conn->query($sql); } catch (Throwable $e) { return false; }
    }
}
/* returns an array of rows, or NULL when the query itself failed */
if (!function_exists('pf_rows')) {
    function pf_rows($sql) {
        $r = pf_q($sql);
        if (!($r instanceof mysqli_result)) return null;
        $o = [];
        while ($x = $r->fetch_assoc()) $o[] = $x;
        return $o;
    }
}
if (!function_exists('pf_ago')) {
    function pf_ago($t) {
        $ts = strtotime((string)$t);
        if (!$ts) return '';
        $d = time() - $ts;
        if ($d < 60)    return 'just now';
        if ($d < 3600)  return floor($d / 60) . 'm ago';
        if ($d < 86400) return floor($d / 3600) . 'h ago';
        return floor($d / 86400) . 'd ago';
    }
}
/* one square post tile (used by Top memes / On this day / Top-performing post).
   Text-only posts (no media, or the default Text.jpg placeholder) are drawn on
   uploads/Text.jpg with the caption written over it — same as the profile grid. */
if (!function_exists('pf_tile')) {
    function pf_tile($m, $badge = '') {
        $id = (int)$m['id'];
        $t  = strtolower((string)($m['type'] ?? ''));
        $fn = (string)($m['file_name'] ?? '');
        $is_text_file = ($fn === '' || stripos($fn, 'text.jpg') !== false);
        $f  = (!$is_text_file) ? 'uploads/' . $fn : '';
        $media = ($f !== '' && in_array($t, ['image', 'video', 'gif', 'sticker', 'stickers'], true));
        if ($media && $t === 'video') {
            $inner = '<video src="' . pf_h($f) . '#t=0.1" muted playsinline preload="metadata"></video>';
        } elseif ($media) {
            $inner = '<img src="' . pf_h($f) . '" alt="" loading="lazy" draggable="false" onerror="this.onerror=null;this.src=\'uploads/Text.jpg\'">';
        } else {
            $inner = '<div class="pf-tile-text"><span>' . pf_h($m['caption'] ?? '') . '</span></div>';
        }
        $vid = ($media && $t === 'video') ? '<span class="pf-tile-vid"><i class="ri-video-fill"></i></span>' : '';
        $ov  = '<div class="pf-tile-ov"><span><i class="ri-heart-3-fill"></i>' . shortNum($m['lk'] ?? 0) . '</span>'
             . '<span><i class="ri-repeat-line"></i>' . shortNum($m['rp'] ?? 0) . '</span></div>';
        return '<button type="button" class="pf-tile" onclick="pfOpenPost(' . $id . ')" aria-label="Open post">' . $inner . $vid . $badge . $ov . '</button>';
    }
}
/* card wrapper: grey "bubble bar" header (same look as the action buttons) + collapsible body */
if (!function_exists('pf_open')) {
    function pf_open($k, $icon, $title, $count = '') {
        echo '<section class="pf-card" data-k="' . pf_h($k) . '">'
           . '<button type="button" class="pf-bar" onclick="pfToggle(this)" aria-expanded="true">'
           . '<i class="' . pf_h($icon) . ' pf-bar-ic"></i><span class="pf-bar-t">' . pf_h($title) . '</span>'
           . ($count !== '' ? '<span class="pf-bar-c">' . pf_h($count) . '</span>' : '')
           . '<i class="ri-arrow-down-s-fill pf-bar-chev"></i></button>'
           . '<div class="pf-body"><div class="pf-inner"><div class="pf-pad">';
    }
}
if (!function_exists('pf_close')) {
    function pf_close() { echo '</div></div></div></section>'; }
}
/* "See more" / "See less" button — only when there is more than the first batch */
if (!function_exists('pf_more_btn')) {
    function pf_more_btn($total, $init) {
        if ($total > $init) echo '<button type="button" class="pf-more" data-mode="more" onclick="pfMore(this)">See more</button>';
    }
}

/* ── the right-hand column cards, rendered by the AJAX endpoint (?dz_side=1) ── */
if (!function_exists('dz_side_cards_html')) {
    function dz_side_cards_html() {
        global $user, $pf_side_locked, $pf_own, $pf_pid, $vibe_enabled, $pf_can_vibe, $pf_vibe_total, $pf_vibe_rows,
               $pf_top_memes, $pf_otd, $pf_topc, $pf_tags, $pf_people, $pf_people_total, $pf_people_kind,
               $pf_visits_week, $pf_visitors, $pf_newtr, $pf_newtr_label, $pf_top_post, $pf_top_post_range,
               $pf_pending, $track_req_count, $pf_check, $pf_check_done;
        ob_start(); ?>
  <?php if ($pf_side_locked): ?>
    <?php pf_open('locked', 'ri-lock-2-line', 'Track to see more'); ?>
      <div class="pf-note">This profile is private. Track <?php echo pf_h($user['username']); ?> to see their top memes, vibe, pods and more.</div>
    <?php pf_close(); ?>

  <?php else: ?>

    <?php /* ───────── TOP MEMES (tap a tile → opens the same post modal as the grid) ───────── */ ?>
    <?php if (!empty($pf_top_memes)): ?>
      <?php pf_open('topmemes', 'ri-trophy-line', 'Top memes', (string)count($pf_top_memes)); ?>
        <div class="pf-grid">
          <?php foreach ($pf_top_memes as $i => $m) echo pf_tile($m, '<span class="pf-rank">#' . ($i + 1) . '</span>'); ?>
        </div>
      <?php pf_close(); ?>
    <?php endif; ?>

    <?php /* ───────── VIBE CHECK ─────────
         • Owner: always sees the card (results only, can never vote on themselves)
         • Visitors: only when Vibe Check is ON, and they can vote only if they track you */ ?>
    <?php if ($pf_own || ($vibe_enabled && ($pf_can_vibe || $pf_vibe_total > 0))): ?>
      <?php pf_open('vibe', 'ri-sparkling-2-line', 'Vibe check'); ?>
        <div class="pf-vibe" id="pfVibeBox"<?php echo $vibe_enabled ? '' : ' style="display:none"'; ?>>
          <div class="pf-vibe-lbl"><?php echo $pf_own ? 'Your vibe is' : pf_h($user['username']) . ' is giving'; ?></div>
          <div class="pf-vibe-top" id="pfVibeTop"><?php echo !empty($pf_vibe_rows) ? pf_h(dz_vibe_headline($pf_vibe_rows)) : 'No vibes yet'; ?></div>
          <div class="pf-vibe-sub" id="pfVibeSub"><?php echo $pf_vibe_total > 0 ? number_format($pf_vibe_total) . ' vote' . ($pf_vibe_total === 1 ? '' : 's') : ($pf_own ? 'Nobody has given a vibe yet' : 'Be the first to give a vibe'); ?></div>
          <?php if (count($pf_vibe_rows) > 1): ?>
            <div class="pf-chips">
              <?php foreach (array_slice($pf_vibe_rows, 0, 3) as $vr): ?>
                <span class="pf-chip"><?php echo pf_h($vr['vibe']); ?> <b><?php echo (int)$vr['c']; ?></b></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
        <?php if ($pf_own): ?>
          <div class="pf-vibe-toggle">
            <div class="pf-vibe-toggle-t">Allow vibe check</div>
            <button type="button" class="vibe-switch<?php echo $vibe_enabled ? ' on' : ''; ?>" id="pfVibeSwitch" role="switch" aria-checked="<?php echo $vibe_enabled ? 'true' : 'false'; ?>" aria-label="Turn Vibe Check on or off" onclick="vibeToggle()"></button>
          </div>
          <button type="button" class="pf-more" onclick="vibeCheck(<?php echo $pf_pid; ?>)">Open vibe check</button>
          <div class="pf-note" id="pfVibeOff" style="margin-top:6px;<?php echo $vibe_enabled ? 'display:none;' : ''; ?>">Vibe check is off — nobody can give you a vibe right now</div>
        <?php elseif ($pf_can_vibe): ?>
          <button type="button" class="pf-more" onclick="vibeCheck(<?php echo $pf_pid; ?>)">Give your vibe</button>
        <?php else: ?>
          <div class="pf-note pf-note-gap">Track <?php echo pf_h($user['username']); ?> to give your vibe</div>
        <?php endif; ?>
      <?php pf_close(); ?>
    <?php endif; ?>

    <?php if ($pf_own): ?>

      <?php /* ───────── INSIGHTS — profile visits this week (5 at a time) ───────── */ ?>
      <?php $vs_init = 5; pf_open('insights', 'ri-eye-line', 'Insights', 'this week'); ?>
        <div class="pf-stat"><b><?php echo number_format($pf_visits_week); ?></b><span>profile visit<?php echo $pf_visits_week === 1 ? '' : 's'; ?> this week</span></div>
        <?php if (!empty($pf_visitors)): ?>
          <div class="pf-list" data-init="<?php echo $vs_init; ?>" data-step="5" data-shown="<?php echo $vs_init; ?>">
            <?php foreach ($pf_visitors as $i => $vs): ?>
              <a class="pf-row<?php echo $i >= $vs_init ? ' pf-hold' : ''; ?>" href="profile.php?user_id=<?php echo (int)$vs['id']; ?>">
                <img class="pf-av" src="<?php echo pf_h(!empty($vs['profile_pic']) ? $vs['profile_pic'] : 'mee.jpg'); ?>" onerror="this.onerror=null;this.src='mee.jpg'" alt="">
                <div class="pf-row-info">
                  <div class="pf-row-t"><?php echo pf_h($vs['username']); ?></div>
                  <div class="pf-row-s">Visited <?php echo pf_h(pf_ago($vs['last_at'])); ?></div>
                </div>
              </a>
            <?php endforeach; ?>
          </div>
          <?php pf_more_btn(count($pf_visitors), $vs_init); ?>
        <?php else: ?>
          <div class="pf-note">No visits yet this week.</div>
        <?php endif; ?>
      <?php pf_close(); ?>

      <?php /* ───────── NEW TRACKERS (5 at a time) ───────── */ ?>
      <?php if (!empty($pf_newtr)): $nt_init = 5; ?>
        <?php pf_open('newtrackers', 'ri-user-add-line', $pf_newtr_label, (string)count($pf_newtr)); ?>
          <div class="pf-list" data-init="<?php echo $nt_init; ?>" data-step="5" data-shown="<?php echo $nt_init; ?>">
            <?php foreach ($pf_newtr as $i => $nt): ?>
              <a class="pf-row<?php echo $i >= $nt_init ? ' pf-hold' : ''; ?>" href="profile.php?user_id=<?php echo (int)$nt['id']; ?>">
                <img class="pf-av" src="<?php echo pf_h(!empty($nt['profile_pic']) ? $nt['profile_pic'] : 'mee.jpg'); ?>" onerror="this.onerror=null;this.src='mee.jpg'" alt="">
                <div class="pf-row-info">
                  <div class="pf-row-t"><?php echo pf_h($nt['username']); ?></div>
                  <?php if (!empty($nt['at'])): ?><div class="pf-row-s">Started tracking you <?php echo pf_h(pf_ago($nt['at'])); ?></div><?php endif; ?>
                </div>
              </a>
            <?php endforeach; ?>
          </div>
          <?php pf_more_btn(count($pf_newtr), $nt_init); ?>
        <?php pf_close(); ?>
      <?php endif; ?>

    <?php endif; /* /owner: insights + new trackers */ ?>

    <?php /* ───────── ON THIS DAY ───────── */ ?>
    <?php if (!empty($pf_otd)): ?>
      <?php pf_open('otd', 'ri-history-line', 'On this day', (string)count($pf_otd)); ?>
        <div class="pf-grid">
          <?php foreach ($pf_otd as $m) echo pf_tile($m, '<span class="pf-rank">' . (int)$m['yr'] . '</span>'); ?>
        </div>
      <?php pf_close(); ?>
    <?php endif; ?>

    <?php /* ───────── TOP CONTRIBUTOR (pods where they're #1) ───────── */ ?>
    <?php if (!empty($pf_topc)): $tc_init = 3; ?>
      <?php pf_open('topcontrib', 'ri-medal-line', 'Top contributor', (string)count($pf_topc)); ?>
        <div class="pf-list" data-init="<?php echo $tc_init; ?>" data-step="3" data-shown="<?php echo $tc_init; ?>">
          <?php foreach ($pf_topc as $i => $tc): ?>
            <a class="pf-row<?php echo $i >= $tc_init ? ' pf-hold' : ''; ?>" href="group.php?id=<?php echo (int)$tc['id']; ?>">
              <img class="pf-av sq" src="<?php echo pf_h($tc['img']); ?>" onerror="this.onerror=null;this.src='uploads/default_group.png'" alt="">
              <div class="pf-row-info">
                <div class="pf-row-t"><?php echo pf_h($tc['title']); ?></div>
                <div class="pf-row-s">#1 contributor · <?php echo shortNum($tc['posts']); ?> post<?php echo $tc['posts'] === 1 ? '' : 's'; ?></div>
              </div>
              <i class="ri-trophy-fill pf-row-end"></i>
            </a>
          <?php endforeach; ?>
        </div>
        <?php pf_more_btn(count($pf_topc), $tc_init); ?>
      <?php pf_close(); ?>
    <?php endif; ?>

    <?php /* ───────── TOP HASHTAGS ───────── */ ?>
    <?php if (!empty($pf_tags)): ?>
      <?php pf_open('hashtags', 'ri-hashtag', 'Top hashtags', (string)count($pf_tags)); ?>
        <div class="pf-chips">
          <?php foreach ($pf_tags as $tg => $n): ?>
            <a class="pf-chip" href="search.php?focus=1&amp;mq=<?php echo rawurlencode('#' . $tg); ?>">#<?php echo pf_h($tg); ?> <b><?php echo (int)$n; ?></b></a>
          <?php endforeach; ?>
        </div>
      <?php pf_close(); ?>
    <?php endif; ?>

    <?php /* ───────── MUTUALS (other users) / CIRCLE (you) — grid view ───────── */ ?>
    <?php if (!empty($pf_people)): ?>
      <?php pf_open('people', $pf_own ? 'ri-team-line' : 'ri-exchange-line', $pf_own ? 'Circle' : 'Mutuals', number_format($pf_people_total)); ?>
        <div class="pf-grid">
          <?php foreach ($pf_people as $pp): ?>
            <a class="pf-utile" href="profile.php?user_id=<?php echo (int)$pp['id']; ?>">
              <img src="<?php echo pf_h(!empty($pp['profile_pic']) ? $pp['profile_pic'] : 'mee.jpg'); ?>" onerror="this.onerror=null;this.src='mee.jpg'" alt="" loading="lazy">
              <span><?php echo pf_h($pp['username']); ?></span>
            </a>
          <?php endforeach; ?>
        </div>
        <?php if ($pf_people_total > count($pf_people)): ?>
          <a class="pf-more" href="tracks.php?user_id=<?php echo $pf_pid; ?>&amp;type=<?php echo $pf_people_kind; ?>">See all <?php echo number_format($pf_people_total); ?></a>
        <?php endif; ?>
      <?php pf_close(); ?>
    <?php endif; ?>

    <?php if ($pf_own): ?>

      <?php /* ───────── TOP-PERFORMING POST (this week only) ───────── */ ?>
      <?php if (!empty($pf_top_post)): ?>
        <?php pf_open('toppost', 'ri-rocket-2-line', 'Top-performing post', $pf_top_post_range); ?>
          <div class="pf-grid one"><?php echo pf_tile($pf_top_post); ?></div>
          <div class="pf-post-stats">
            <span><i class="ri-heart-3-fill"></i><?php echo shortNum($pf_top_post['lk']); ?></span>
            <span><i class="ri-repeat-line"></i><?php echo shortNum($pf_top_post['rp']); ?></span>
            <span><i class="ri-bookmark-fill"></i><?php echo shortNum($pf_top_post['fv']); ?></span>
          </div>
        <?php pf_close(); ?>
      <?php endif; ?>

      <?php /* ───────── PENDING TRACK REQUESTS ───────── */ ?>
      <?php if (!empty($pf_pending) || $track_req_count > 0): $pd_init = 3; ?>
        <?php pf_open('pending', 'ri-user-received-line', 'Pending track requests', (string)max((int)$track_req_count, count($pf_pending))); ?>
          <?php if (!empty($pf_pending)): ?>
          <div class="pf-list" data-init="<?php echo $pd_init; ?>" data-step="5" data-shown="<?php echo $pd_init; ?>">
            <?php foreach ($pf_pending as $i => $pr): ?>
              <a class="pf-row<?php echo $i >= $pd_init ? ' pf-hold' : ''; ?>" href="profile.php?user_id=<?php echo (int)$pr['id']; ?>">
                <img class="pf-av" src="<?php echo pf_h(!empty($pr['profile_pic']) ? $pr['profile_pic'] : 'mee.jpg'); ?>" onerror="this.onerror=null;this.src='mee.jpg'" alt="">
                <div class="pf-row-info">
                  <div class="pf-row-t"><?php echo pf_h($pr['username']); ?></div>
                  <div class="pf-row-s">Requested <?php echo pf_h(pf_ago($pr['at'])); ?></div>
                </div>
              </a>
            <?php endforeach; ?>
          </div>
          <?php pf_more_btn(count($pf_pending), $pd_init); ?>
          <?php endif; ?>
          <a class="pf-more red" href="setting.php?open=trackreqs">Review requests</a>
        <?php pf_close(); ?>
      <?php endif; ?>

      <?php /* ───────── COMPLETE YOUR PROFILE ───────── */ ?>
      <?php if ($pf_check_done < count($pf_check)): $pf_pct = (int)round($pf_check_done / max(1, count($pf_check)) * 100); ?>
        <?php pf_open('checklist', 'ri-checkbox-circle-line', 'Complete your profile', $pf_check_done . '/' . count($pf_check)); ?>
          <div class="pf-prog"><i style="width:<?php echo $pf_pct; ?>%"></i></div>
          <?php foreach ($pf_check as $ck): ?>
            <?php if ($ck[1]): ?>
              <div class="pf-check done"><i class="ri-checkbox-circle-fill ck"></i><span class="tx"><?php echo pf_h($ck[0]); ?></span></div>
            <?php else: ?>
              <a class="pf-check" href="<?php echo pf_h($ck[3] === 'ri-team-line' ? 'community.php' : $ck[2]); ?>"><i class="ri-checkbox-blank-circle-line ck"></i><span class="tx"><?php echo pf_h($ck[0]); ?></span><i class="ri-arrow-right-s-line go"></i></a>
            <?php endif; ?>
          <?php endforeach; ?>
        <?php pf_close(); ?>
      <?php endif; ?>

    <?php endif; /* /owner only */ ?>

  <?php endif; /* /locked vs ok */ ?>
<?php
        return ob_get_clean();
    }
}

$dz_side_req = ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['dz_side']));   // AJAX request for the right column
$pf_pid  = (int)$profile_id;
$pf_cu   = (int)$current_user;
$pf_own  = ($pf_cu === $pf_pid);

/* The "Track to see more" lock card appears whenever the profile is walled (private and you're not approved).
   Normal (public) profiles show the full right column even when you don't track them. */
$pf_side_ok     = (isset($conn) && $conn && !$show_private_wall && ($can_view || !$profile_is_private) && !$blocked_check && !$is_blocked_by_owner);
$pf_side_locked = (isset($conn) && $conn && $show_private_wall && !$blocked_check && !$is_blocked_by_owner);

$pf_top_memes = []; $pf_otd = []; $pf_tags = []; $pf_topc = [];
$pf_people = []; $pf_people_total = 0; $pf_people_kind = '';
$pf_vibe_rows = []; $pf_vibe_total = 0;
$pf_visits_week = 0; $pf_visitors = [];
$pf_newtr = []; $pf_newtr_label = 'New trackers'; $pf_newtr_fallback = false;
$pf_top_post = null; $pf_top_post_range = 'this week';
$pf_pending = [];
$pf_check = []; $pf_check_done = 0;
/* Vibe Check: you can never vote on your own profile; visitors must track you
   and the owner must have Vibe Check switched on. */
$pf_can_vibe = (!$pf_own && !empty($follow_check) && $vibe_enabled);

/* ── count this visit (once per viewer per day) for the owner's Insights ── */
if (!$dz_side_req && empty($_GET['dz_peek']) && $dz_lazy === '' && ($pf_side_ok || $pf_side_locked)) {   // dz_peek = preview frame inside the Zeems swipe: not a real visit
    if ($pf_cu > 0 && !$pf_own && !$blocked_check && !$is_blocked_by_owner) {
        $pf_chk = pf_q("SELECT 1 FROM profile_views WHERE profile_id=$pf_pid AND viewer_id=$pf_cu AND viewed_at>=CURDATE() LIMIT 1");
        if ($pf_chk instanceof mysqli_result && $pf_chk->num_rows === 0) {
            pf_q("INSERT INTO profile_views (profile_id, viewer_id) VALUES ($pf_pid, $pf_cu)");
        }
    }
}

if ($pf_side_ok && $dz_side_req) {
    $pf_cols = "m.id, m.file_name, m.type, m.caption, m.created_at,
                (SELECT COUNT(*) FROM likes l WHERE l.meme_id = m.id) AS lk,
                (SELECT COUNT(*) FROM reposts r WHERE r.meme_id = m.id) AS rp,
                (SELECT COUNT(*) FROM favorites f WHERE f.meme_id = m.id) AS fv";

    /* Top memes — best 6 by likes + reposts×2 + saves×2 (needs a score above 0) */
    $pf_top_memes = pf_rows("SELECT $pf_cols FROM memes m WHERE m.uploaded_by = $pf_pid $DZ_NOT_ARCH
                             HAVING (lk + rp*2 + fv*2) > 0
                             ORDER BY (lk + rp*2 + fv*2) DESC, m.created_at DESC LIMIT 6") ?: [];

    /* On this day — posts from today's date in earlier years */
    $pf_otd = pf_rows("SELECT $pf_cols, YEAR(m.created_at) AS yr FROM memes m
                       WHERE m.uploaded_by = $pf_pid $DZ_NOT_ARCH
                         AND DATE_FORMAT(m.created_at, '%m-%d') = DATE_FORMAT(NOW(), '%m-%d')
                         AND YEAR(m.created_at) < YEAR(NOW())
                       ORDER BY m.created_at DESC LIMIT 6") ?: [];

    /* Vibe Check — the vibe with the MOST votes is the result */
    dz_vibe_prepare();
    $pf_vibe_rows = pf_rows("SELECT vibe, COUNT(*) AS c FROM profile_vibes WHERE target_id = $pf_pid GROUP BY vibe ORDER BY c DESC, vibe ASC LIMIT 12") ?: [];
    $pf_vt = pf_rows("SELECT COUNT(*) AS c FROM profile_vibes WHERE target_id = $pf_pid");
    $pf_vibe_total = $pf_vt ? (int)$pf_vt[0]['c'] : 0;

    /* Top hashtags — parsed from their recent captions */
    $pf_cap_rows = pf_rows("SELECT caption FROM memes WHERE uploaded_by = $pf_pid AND caption LIKE '%#%' ORDER BY created_at DESC LIMIT 300") ?: [];
    $pf_tag_count = [];
    foreach ($pf_cap_rows as $cr) {
        if (preg_match_all('/#([\p{L}\p{N}_]+)/u', (string)$cr['caption'], $tm)) {
            foreach ($tm[1] as $tg) {
                $k = mb_strtolower($tg);
                $pf_tag_count[$k] = ($pf_tag_count[$k] ?? 0) + 1;
            }
        }
    }
    arsort($pf_tag_count);
    $pf_tags = array_slice($pf_tag_count, 0, 10, true);

    /* pods this profile is in — private pods only if the viewer is a member (or it's their own profile) */
    $pf_vis = $pf_own ? '' : " AND (g.privacy IS NULL OR g.privacy <> 'private' OR EXISTS(SELECT 1 FROM group_members v WHERE v.group_id = g.id AND v.user_id = $pf_cu))";
    $pf_pods = pf_rows("SELECT g.id, g.title, g.group_pic FROM group_members gm
                        JOIN `groups` g ON g.id = gm.group_id
                        WHERE gm.user_id = $pf_pid AND (g.is_adult = 0 OR g.is_adult IS NULL)$pf_vis
                        ORDER BY g.id DESC LIMIT 12") ?: [];

    /* Top contributor — pods where this person is #1 (same all-time score as the pod feed) */
    foreach ($pf_pods as $pd) {
        $gid = (int)$pd['id'];
        $sub = "SELECT meme_id FROM group_posts WHERE group_id = $gid";
        $t = pf_rows("SELECT gp.posted_by AS uid, COUNT(DISTINCT gp.id) AS pc,
                             (COUNT(DISTINCT gp.id)*3 + COALESCE(SUM(lk.c),0) + COALESCE(SUM(rp.c),0)*2 + COALESCE(SUM(fv.c),0)*2) AS score
                      FROM group_posts gp
                      LEFT JOIN (SELECT meme_id, COUNT(*) c FROM likes     WHERE meme_id IN ($sub) GROUP BY meme_id) lk ON lk.meme_id = gp.meme_id
                      LEFT JOIN (SELECT meme_id, COUNT(*) c FROM reposts   WHERE meme_id IN ($sub) GROUP BY meme_id) rp ON rp.meme_id = gp.meme_id
                      LEFT JOIN (SELECT meme_id, COUNT(*) c FROM favorites WHERE meme_id IN ($sub) GROUP BY meme_id) fv ON fv.meme_id = gp.meme_id
                      WHERE gp.group_id = $gid
                      GROUP BY gp.posted_by
                      ORDER BY score DESC LIMIT 1");
        if ($t && (int)$t[0]['uid'] === $pf_pid && (int)$t[0]['score'] > 0) {
            $pf_topc[] = [
                'id'    => $gid,
                'title' => $pd['title'],
                'img'   => !empty($pd['group_pic'])
                              ? (strpos($pd['group_pic'], 'uploads/') === 0 ? $pd['group_pic'] : 'uploads/' . $pd['group_pic'])
                              : 'uploads/default_group.png',
                'posts' => (int)$t[0]['pc'],
            ];
        }
    }

    /* Mutuals (other users' profiles) / Circle (your own profile) */
    if ($pf_cu > 0) {
        if ($pf_own) {
            $pf_people_kind = 'circle';
            $from = "FROM followers f1
                     JOIN followers f2 ON f2.follower_id = f1.following_id AND f2.following_id = $pf_cu
                     JOIN users u ON u.id = f1.following_id
                     WHERE f1.follower_id = $pf_cu AND f1.following_id != $pf_cu $DZ_NOT_DEACT_U";
        } else {
            $pf_people_kind = 'mutuals';
            $from = "FROM followers f1
                     JOIN followers f2 ON f2.follower_id = f1.following_id
                     JOIN users u ON u.id = f1.following_id
                     WHERE f1.follower_id = $pf_cu AND f2.following_id = $pf_pid
                       AND f1.following_id != $pf_cu AND f1.following_id != $pf_pid $DZ_NOT_DEACT_U";
        }
        $pc = pf_rows("SELECT COUNT(*) AS c $from");
        $pf_people_total = $pc ? (int)$pc[0]['c'] : 0;
        if ($pf_people_total > 0) {
            $pf_people = pf_rows("SELECT u.id, u.username, u.profile_pic $from ORDER BY RAND() LIMIT 9") ?: [];
        }
    }

    /* ── OWNER ONLY ── */
    if ($pf_own) {
        /* Insights: visits this week + who (latest 50) */
        $pv = pf_rows("SELECT COUNT(DISTINCT viewer_id) AS c FROM profile_views WHERE profile_id = $pf_pid AND viewed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $pf_visits_week = $pv ? (int)$pv[0]['c'] : 0;
        $pf_visitors = pf_rows("SELECT u.id, u.username, u.profile_pic, MAX(v.viewed_at) AS last_at
                                FROM profile_views v JOIN users u ON u.id = v.viewer_id
                                WHERE v.profile_id = $pf_pid AND v.viewed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) $DZ_NOT_DEACT_U
                                GROUP BY u.id, u.username, u.profile_pic
                                ORDER BY last_at DESC LIMIT 50") ?: [];

        /* New trackers (last 7 days). If followers has no created_at, fall back to the latest trackers. */
        $pf_newtr = pf_rows("SELECT u.id, u.username, u.profile_pic, f.created_at AS at
                             FROM followers f JOIN users u ON u.id = f.follower_id
                             WHERE f.following_id = $pf_pid AND f.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) $DZ_NOT_DEACT_U
                             ORDER BY f.created_at DESC LIMIT 30");
        if ($pf_newtr === null) {
            $pf_newtr_fallback = true;
            $pf_newtr_label = 'Latest trackers';
            $pf_newtr = pf_rows("SELECT u.id, u.username, u.profile_pic, NULL AS at
                                 FROM followers f JOIN users u ON u.id = f.follower_id
                                 WHERE f.following_id = $pf_pid $DZ_NOT_DEACT_U ORDER BY f.id DESC LIMIT 10") ?: [];
        }

        /* Top-performing post: best post of the LAST 7 DAYS only.
           (No "all time" fallback — if nothing performed this week the card is hidden.) */
        $tp = pf_rows("SELECT $pf_cols FROM memes m WHERE m.uploaded_by = $pf_pid $DZ_NOT_ARCH AND m.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                       HAVING (lk + rp*2 + fv*2) > 0 ORDER BY (lk + rp*2 + fv*2) DESC LIMIT 1");
        $pf_top_post = !empty($tp) ? $tp[0] : null;

        /* Pending track requests */
        $pf_pending = pf_rows("SELECT u.id, u.username, u.profile_pic, r.created_at AS at
                               FROM track_requests r JOIN users u ON u.id = r.requester_id
                               WHERE r.target_id = $pf_pid AND r.status = 'pending' $DZ_NOT_DEACT_U
                               ORDER BY r.created_at DESC LIMIT 20") ?: [];

        /* Complete your profile */
        $pf_check = [
            ['Add a bio',                     !empty(trim((string)($user['bio'] ?? ''))), 'setting.php',                  'ri-user-heart-line'],
            ['Add a link',                    ($profile_link_count > 0),                  'setting.php',                  'ri-link-m'],
            ['Create your first highlight',   ($profile_highlight_count > 0),             'setting.php?open=highlights',  'ri-pushpin-2-line'],
            ['Join your first pod',           ($pod_count > 0),                           'community.php',                 'ri-team-line'],
        ];
        foreach ($pf_check as $ck) if ($ck[1]) $pf_check_done++;
    }
}

/* ── AJAX endpoint: answers ?dz_side=1 with ONLY the right-column cards, then stops ── */
if ($dz_side_req) {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: private, no-store');
    echo ($pf_side_ok || $pf_side_locked) ? dz_side_cards_html() : '';
    exit;
}

/* ── Dashboard button: an owner who has posts / memes sees "Dashboard" (→ profile_dashboard.php)
   instead of "Edit Picture". Owners with no posts keep "Edit Picture" + its modal. ── */
$is_owner_view = ($current_user == $profile_id);
$has_posts     = ((int)$post_count > 0);
$ep_enabled    = ($is_owner_view && !$has_posts);

/* ── starting values the page hands to the live-update script ── */
$dz_live_state = [
    'owner'     => $is_owner_view,
    'username'  => (string)($user['username'] ?? ''),
    'nickname'  => (string)($user['nickname'] ?? ''),
    'pronouns'  => (string)($user['pronouns'] ?? ''),
    'user_type' => (string)($user['user_type'] ?? ''),
    'vibe'      => (string)$activity_vibe,
    'ptype'     => (string)$profile_type,
    'posts'     => (string)shortNum($post_count),
];

/* ── values from the privacy block (moved up here from the HTML; safe fallbacks if one is missing) ── */
if (!isset($dz_hide_extra)) $dz_hide_extra = false;
if (!isset($dz_follows))    $dz_follows    = !empty($follow_check);
if (!isset($dz_priv) || !is_array($dz_priv)) $dz_priv = ['level' => 'public', 'is_private' => false, 'allow_messages' => true, 'show_online' => true];

$dz_is_owner  = ($current_user == $profile_id);
$dz_level     = $dz_priv['level'] ?? 'public';
$dz_any_block = (!empty($blocked_check) || !empty($is_blocked_by_owner));

/* Tracker / Tracking counts — decided ONLY by Privacy Controls (not by $can_view):
   • Public                              → everyone sees them
   • Limited / Private / Full Private    → hidden until the viewer tracks the profile
   • The owner always sees their own; a blocked viewer never sees them */
$dz_stats_locked = !$dz_is_owner && ($dz_any_block || (!$dz_follows && $dz_level !== 'public'));

/* Profile CTA pill: visitors (and View As) see it; the owner only edits it from the kebab menu.
   Needs a Public or Limited profile and nobody blocked. */
$dz_cta = null; $dz_cta_own = null;
try {
    if (isset($conn) && $conn && (int)$profile_id > 0) {
        $cta_r = $conn->query("SELECT label, url, color, pic FROM profile_cta WHERE user_id = " . (int)$profile_id . " LIMIT 1");
        if (!($cta_r instanceof mysqli_result)) $cta_r = $conn->query("SELECT label, url, color FROM profile_cta WHERE user_id = " . (int)$profile_id . " LIMIT 1");   // table without the picture column yet
        $cta_row = ($cta_r instanceof mysqli_result) ? $cta_r->fetch_assoc() : null;
        if ($cta_row) {
            if (!isset($cta_row['pic'])) $cta_row['pic'] = '';
            if ($dz_is_owner) $dz_cta_own = $cta_row;
            elseif (!$dz_any_block && in_array($dz_level, ['public', 'limited'], true)) $dz_cta = $cta_row;
        }
    }
} catch (Throwable $e) { $dz_cta = null; $dz_cta_own = null; }
if (!empty($DZ_SUSP)) { $dz_cta = null; $dz_cta_own = null; }   /* suspended: no call-to-action pill */

/* Pods count — locked only while the profile is walled (Private / Full Private, not approved) or blocked */
$dz_pods_locked  = !$dz_is_owner && ($dz_any_block || !empty($show_private_wall));

/* Message button — works by default, changes only with Privacy Controls:
   • "Allow messages" OFF         → no Message button
   • Public                       → anyone can message
   • Limited / Private / Full     → only people who track the profile */
$dz_msg_enabled = !empty($dz_priv['allow_messages']);
$dz_msg_open    = ($dz_level === 'public') || $dz_follows;

/* ── Smaller + flash-free page ─────────────────────────────────────────────────────────────
   1) gzip the page (about a quarter of the size) when the server isn't already compressing it;
   2) move every <style> block that ended up inside <body> (the modals bundle, strips, …) into <head>,
      so the browser has ALL the CSS before it paints the first card — that late CSS is what caused the
      occasional split-second "no CSS" flash. <script>, comments, <template> and <noscript> are left alone. ── */
/* Page-load speed: every <img> that is not already lazy (the ones inside closed menus and sheets especially) is told to load only when it is
   about to be seen, so the browser's blue load bar finishes as soon as the visible page is ready. Skips <script>/<style> text and the main avatar. */
if (!function_exists('dz_lazy_imgs')) {
    function dz_lazy_imgs($seg) {
        if ($seg === '' || stripos($seg, '<img') === false) return $seg;
        return preg_replace('~<img(?=\s)(?![^>]*\b(?:loading|fetchpriority)\s*=)~i', '<img loading="lazy" decoding="async"', $seg);
    }
}
if (!function_exists('dz_hoist_styles')) {
    function dz_hoist_styles($html) {
        if (!is_string($html) || strlen($html) < 2000) return $html;
        $he = stripos($html, '</head>');
        if ($he === false) return $html;
        $head = substr($html, 0, $he);
        $body = substr($html, $he);
        $moved = [];
        $out = '';
        $pos = 0;
        $n = strlen($body);
        /* linear scanner (no backtracking, so big inline scripts can never make it fail) */
        while ($pos < $n && preg_match('~<(script|style|template|noscript|textarea)\b|<!--~i', $body, $mm, PREG_OFFSET_CAPTURE, $pos)) {
            $start = $mm[0][1];
            if ($mm[0][0] === '<!--') { $close = '-->'; }
            else { $close = '</' . strtolower($mm[1][0]); }
            $e = stripos($body, $close, $start + 1);
            if ($e === false) break;
            $gt = strpos($body, '>', $e);
            $end = ($gt === false) ? $n : $gt + 1;
            if (strtolower($mm[1][0] ?? '') === 'style') {
                $moved[] = substr($body, $start, $end - $start);
                $out .= dz_lazy_imgs(substr($body, $pos, $start - $pos));
            } else {
                $out .= dz_lazy_imgs(substr($body, $pos, $start - $pos)) . substr($body, $start, $end - $start);   /* plain HTML before the tag is lazified; the <script>/<template>/comment itself is untouched */
            }
            $pos = $end;
        }
        $out .= dz_lazy_imgs(substr($body, $pos));
        if (!$moved) return $head . $out;
        return $head . "\n" . implode("\n", $moved) . "\n" . $out;
    }
}
if (!defined('DZ_PAGE_BUFFERED') && PHP_SAPI !== 'cli') {
    define('DZ_PAGE_BUFFERED', 1);
    /* gzip only while headers can still be sent; the style hoist works either way */
    if (!headers_sent() && extension_loaded('zlib') && !ini_get('zlib.output_compression')) { @ob_start('ob_gzhandler'); }
    @ob_start('dz_hoist_styles');
}
?>
<!DOCTYPE html>
<html lang="en">
<!-- profile.php: the server spent <?= (int)round((microtime(true)-($_SERVER['REQUEST_TIME_FLOAT']??microtime(true)))*1000) ?> ms (database + building) before sending the page. Under ~300 ms = the server is fine and any wait is media/scripts loading in the browser. -->
<head>
<script>
/* Haptics only ever answer a real touch / click / key press. Opening or refreshing the page can never buzz the phone:
   defined first, so it wins over the later `window.dzHp = window.dzHp || …` fallbacks. */
(function(){
  var last = 0;
  function mark(){ last = Date.now(); }
  ['touchstart','touchend','pointerdown','mousedown','click','keydown','touchmove'].forEach(function(t){ window.addEventListener(t, mark, {capture:true, passive:true}); });
  window.dzHp = function(k){
    try{
      if(Date.now() - last > 2500) return;            /* no recent tap → no buzz (page load / refresh / background timers) */
      if(window.dzHaptic && typeof window.dzHaptic[k] === 'function') window.dzHaptic[k]();
    }catch(e){}
  };
})();
</script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($user['username']); ?> | Dezzme</title>
<meta name="csrf-token" content="<?php echo htmlspecialchars($dz_csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
<script>
(function(){try{
  var f=sessionStorage.getItem('dzProfileEnter'); if(!f) return;
  sessionStorage.removeItem('dzProfileEnter');
  if(Date.now()-JSON.parse(f).t<5000) document.documentElement.classList.add('dz-enter-right');
}catch(e){}})();
</script>
<script src="theme_css.php?boot=1"></script><link rel="stylesheet" href="theme_css.php?vars=1&v=<?= @filemtime(__DIR__.'/theme_css.php') ?>"><?php /* colours only, from theme_css.php (same as home / search / text feed) */ ?>
<style>
html{ background:var(--lane,#070924); }  /* no white flash while loading (matches the page's dark background) */
.dz-enter-right body{ animation:dzEnterRight .28s cubic-bezier(.22,1,.36,1) backwards; }
@keyframes dzEnterRight{ from{ transform:translate3d(100%,0,0); } }
</style>
<?php dz_offline_styles(); ?>
<script>
/* 🔒 CSRF front-end: attaches the token to EVERY same-origin write (fetch, XHR, forms),
   and silently refreshes + retries once if the token expired. */
(function(){
    var meta = document.querySelector('meta[name="csrf-token"]');
    window.DZ_CSRF = meta ? meta.content : '';
    var origFetch = window.fetch ? window.fetch.bind(window) : null;

    function sameOrigin(u){ try { return new URL(u, location.href).origin === location.origin; } catch(e){ return true; } }
    function isWrite(m){ return !/^(GET|HEAD|OPTIONS)$/i.test(m || 'GET'); }

    window.dzCsrfArm = function(root){
        var list = (root && root.querySelectorAll) ? root.querySelectorAll('form') : [];
        Array.prototype.forEach.call(list, function(f){
            if((f.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
            var i = f.querySelector('input[name="csrf_token"]');
            if(!i){ i = document.createElement('input'); i.type = 'hidden'; i.name = 'csrf_token'; f.appendChild(i); }
            if(i.value !== window.DZ_CSRF) i.value = window.DZ_CSRF;
        });
    };

    function refreshToken(){
        return origFetch('profile.php?csrf_refresh=1', { credentials:'same-origin', cache:'no-store' })
            .then(function(r){ return r.json(); })
            .then(function(d){
                if(d && d.token){ window.DZ_CSRF = d.token; if(meta) meta.content = d.token; window.dzCsrfArm(document); }
                return window.DZ_CSRF;
            });
    }

    if(origFetch){
        window.fetch = function(input, init){
            var url = (typeof input === 'string') ? input : ((input && input.url) || '');
            var method = (init && init.method) || (input && input.method) || 'GET';
            if(!isWrite(method) || !sameOrigin(url)) return origFetch(input, init);

            function send(token){
                var i2 = Object.assign({}, init || {});
                var h  = new Headers(i2.headers || (input && input.headers) || {});
                h.set('X-CSRF-Token', token);
                i2.headers = h;
                if(!i2.credentials) i2.credentials = 'same-origin';
                return origFetch(input, i2);
            }
            return send(window.DZ_CSRF).then(function(res){
                if(res.status !== 403) return res;
                return res.clone().json().then(function(d){
                    if(d && d.msg === 'csrf') return refreshToken().then(send);   // expired token → refresh + retry once
                    return res;
                }).catch(function(){ return res; });
            });
        };
    }

    var xo = XMLHttpRequest.prototype.open, xs = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open = function(m, u){ this._dzM = m; this._dzU = u; return xo.apply(this, arguments); };
    XMLHttpRequest.prototype.send = function(){
        if(isWrite(this._dzM) && sameOrigin(this._dzU)){ try { this.setRequestHeader('X-CSRF-Token', window.DZ_CSRF); } catch(e){} }
        return xs.apply(this, arguments);
    };

    document.addEventListener('DOMContentLoaded', function(){
        window.dzCsrfArm(document);
        var t = 0;
        new MutationObserver(function(){
            if(t) return;
            t = requestAnimationFrame(function(){ t = 0; window.dzCsrfArm(document); });   // forms added / rebuilt later get armed too
        }).observe(document.body, { childList:true, subtree:true });
    });
})();
</script>

<link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<link rel="preload" as="image" href="<?= htmlspecialchars($profilePic) ?>" fetchpriority="high">

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
    --red-dim:    rgba(255,45,45,0.16);
    --red-glow:   rgba(255,45,45,0.35);
    /* colour of BOTH Track buttons (page + topbar) and the pic-modal Track button */
    --track-red:       #FF2D2D;
    --track-red-hover: #ff1a1a;

    /* original page background — gradient, unchanged */
    --bg:         linear-gradient(-45deg,var(--bg-start,#010218),var(--bg-end,var(--lane,#070924)),var(--bg-end,var(--lane,#070924)));
    --bg-solid:   var(--ink,#06071A);

    --surface:    rgba(var(--fg,255,255,255),0.05);
    --surface-2:  rgba(var(--fg,255,255,255),0.08);
    --muted-2:    rgba(var(--fg,255,255,255),0.28);
    /* ONE purple for links/emails typed inside the bio and the email dropdown icons. */
    --link:       #8B5CF6;
    --link-hover: #A78BFA;
    /* Colour of the Links area under the bio — SAME as the bio text. */
    --links-color: rgba(var(--fg,255,255,255),0.85);
    --max-w:      620px;
    --sidebar-w:  100px;   /* space reserved for the nav.php sidebar on desktop */
    --cm-s: 1;   /* Collections + Messages areas: +10% on phones */
    --pv-s: 1;   /* profile picture viewer buttons: +10% on phones (not the picture) */
    --bs-s: 1;   /* bottom sheets on the profile page: +9% on phones */
    --sb-s: 1;   /* Newest / Oldest / Collections / search bar: +8.9% on phones */
    --act-t: 1;   /* action button words: extra +1.5% on phones */
    --act-s: 1;   /* action buttons (words + icons): +3% on phones */
    --hd-s: 1;   /* phone-only header size multiplier (topbar → tab buttons): 1 on desktop, 1.1 on screens up to 768px */
    --rp-sidebar-offset: 0px;
    --topbar-h:   clamp(44px, 9.8vw, 54px);   /* JS overwrites this with the exact height */

    --rp-header-pad-x: clamp(9px, 3vw, 16px);
    --rp-avatar:        calc(clamp(72px, 20vw, 112px) * var(--hd-s));
    --rp-avatar-border: calc(3.4px * var(--hd-s));
    --rp-avatar-plus:   calc(clamp(24px, 7vw, 32px) * var(--hd-s));
    --rp-username-fs:   calc(clamp(0.798rem, 4.09vw, 1.07rem) * var(--hd-s));
    --rp-username-maxw: calc(clamp(112px, 24vw + 33px, 210px) * var(--hd-s));
    --rp-nickname-fs:   calc(clamp(0.612rem, 2.17vw, 0.750rem) * var(--hd-s));
    --rp-stat-gap:      calc(clamp(8px, 3vw, 16px) * var(--hd-s));
    --rp-stat-num-fs:   calc(clamp(0.74rem, 2.2vw, 0.88rem) * var(--hd-s) * 1.03);
    --rp-stat-lbl-fs:   calc(clamp(0.58rem, 1.9vw, 0.68rem) * var(--hd-s));
    --rp-pill-fs:       calc(clamp(0.62rem, 2.2vw, 0.76rem) * var(--hd-s));
    --rp-pill-pad-y:    calc(clamp(2px, .6vw, 3px) * var(--hd-s));
    --rp-pill-pad-x:    calc(clamp(9px, 1.8vw + 3px, 12px) * var(--hd-s));
    --rp-pill-radius:   calc(3px * var(--hd-s));
    --rp-bio-fs:        calc(clamp(0.64rem, 1.93vw, 0.75rem) * var(--hd-s) * 1.03);

    /* height of every action button (Track / Message / Block / Email /
       Edit Picture / Dashboard / Untracked / About). */
    --rp-action-h:      calc(clamp(25px, 7.2vw, 30px) * var(--hd-s));

    /* corner radius of every action button. */
    --rp-action-radius: calc(4px * var(--hd-s));

    /* HIGHLIGHTS — square tile size (fluid, follows the page) + ring details. */
    --rp-hl-size:       calc(clamp(51px, 13.7vw, 64.5px) * var(--hd-s)); /* outer square incl. the grey ring (~5% smaller than original) */
    --rp-hl-radius:     calc(clamp(10.5px, 2.85vw, 14.2px) * var(--hd-s)); /* corner radius of the ring */
    --rp-hl-gap:        calc(clamp(9.5px, 2.65vw, 13.3px) * var(--hd-s)); /* space between tiles */
    --rp-hl-ring:       calc(3.17px * var(--hd-s));                      /* ring thickness (grows OUTWARD, picture + gap stay put) */
    --rp-hl-ring-base:  calc(2.6px * var(--hd-s));                       /* the old thickness that defines the gap to the picture */
    --rp-hl-out:        calc(var(--rp-hl-ring-base) - var(--rp-hl-ring));  /* how far the ring sticks out (negative) */
    --rp-hl-inset:      calc(4.85px * var(--hd-s));                      /* ring + empty gap before the picture */
    --rp-hl-name-fs:    calc(clamp(0.55rem, 1.75vw, 0.64rem) * var(--hd-s));
}
@media (max-width:768px){ :root{ --hd-s:1.1; --act-s:1.03; --act-t:1.015; --sb-s:1.0809; --bs-s:1.1; --cm-s:1.1; --pv-s:1.1; }}
/* About-me star + contact chevron: a bit heavier line (in proportion to their size) on every screen */
.tab-ic-video{ --tic:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23000' stroke-width='2.06' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M7 21h10'/%3E%3Crect width='20' height='14' x='2' y='3' rx='2'/%3E%3C/svg%3E"); }
.rp-tab-btn i.tab-ic-video{ font-size:calc(clamp(21px, .8vw + 17px, 23px) * var(--hd-s) * 1.05); }
/* ── VIDEOS tab: long-form videos as a list — thumbnail left, title + "4:55 · 2w ago" right ── */
.vd-list{ display:flex; flex-direction:column; gap:calc(14px * var(--hd-s)); padding:calc(14px * var(--hd-s)) calc(14px * var(--hd-s)) 30px; }
.vd-row{ display:flex; align-items:flex-start; gap:calc(14px * var(--hd-s)); text-decoration:none; color:inherit; -webkit-tap-highlight-color:transparent; cursor:pointer; }
.vd-thumb{ position:relative; flex:0 0 42%; max-width:42%; aspect-ratio:16/9; border-radius:calc(7px * var(--hd-s)); overflow:hidden; background:rgba(var(--fg,255,255,255),.08); }
.vd-thumb img{ position:absolute; inset:0; width:100%; height:100%; object-fit:cover; display:block; }
.vd-prog{ position:absolute; left:calc(6px * var(--hd-s)); right:calc(6px * var(--hd-s)); bottom:calc(6px * var(--hd-s)); height:3px; border-radius:3px; background:rgba(255,255,255,.35); overflow:hidden; }
.vd-prog span{ display:block; height:100%; background:#fff; border-radius:3px; }
.vd-body{ flex:1; min-width:0; padding-top:calc(2px * var(--hd-s)); }
.vd-title{ font-size:calc(clamp(.78rem, 1.4vw + .5rem, .92rem) * var(--hd-s)); font-weight:700; line-height:1.3; color:var(--text,#F0F2FF); display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; word-break:break-word; }
.vd-meta{ margin-top:calc(6px * var(--hd-s)); font-size:calc(clamp(.64rem, .9vw + .4rem, .74rem) * var(--hd-s)); color:rgba(var(--fg,255,255,255),.55); }
.vd-row:active .vd-title{ opacity:.8; }
.vd-all{ display:block; text-align:center; padding:calc(10px * var(--hd-s)); font-size:calc(.74rem * var(--hd-s)); font-weight:600; color:rgba(var(--fg,255,255,255),.7); text-decoration:none; }
.tab-ic-userstar{ --tic:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23000' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M8 7a4 4 0 1 0 8 0a4 4 0 0 0 -8 0'/%3E%3Cpath d='M6 21v-2a4 4 0 0 1 4 -4h.5'/%3E%3Cpath d='M17.8 20.817l-2.172 1.138a.392 .392 0 0 1 -.568 -.41l.415 -2.411l-1.757 -1.707a.389 .389 0 0 1 .217 -.665l2.428 -.352l1.086 -2.193a.392 .392 0 0 1 .702 0l1.086 2.193l2.428 .352a.39 .39 0 0 1 .217 .665l-1.757 1.707l.414 2.41a.39 .39 0 0 1 -.567 .411l-2.172 -1.138z'/%3E%3C/svg%3E"); }
.rp-square-btn.rp-email-sq i{ -webkit-text-stroke:.03em currentColor; }
/* action buttons, phones only: words and font icons 3% thicker (a hairline text stroke), the About star drawn with a 3% heavier line */
@media (max-width:768px){
    /* the thickening is kept on the icons only; text stroke gives words bumpy, spiky corners, so the words get a soft glow of their own colour instead (smooth) */
    .rp-rect-btn i, .rp-square-btn i{ -webkit-text-stroke:.005em currentColor; }
    .rp-rect-btn, .rp-square-btn, .rp-email-sq, .rp-tb-track-btn{ text-shadow:0 0 .35px currentColor; }
    .rp-square-btn .tab-ic-userstar{ --tic:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23000' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M8 7a4 4 0 1 0 8 0a4 4 0 0 0 -8 0'/%3E%3Cpath d='M6 21v-2a4 4 0 0 1 4 -4h.5'/%3E%3Cpath d='M17.8 20.817l-2.172 1.138a.392 .392 0 0 1 -.568 -.41l.415 -2.411l-1.757 -1.707a.389 .389 0 0 1 .217 -.665l2.428 -.352l1.086 -2.193a.392 .392 0 0 1 .702 0l1.086 2.193l2.428 .352a.39 .39 0 0 1 .217 .665l-1.757 1.707l.414 2.41a.39 .39 0 0 1 -.567 .411l-2.172 -1.138z'/%3E%3C/svg%3E"); }}


html{ scroll-behavior:smooth; }

/* RESPONSIVE view: no scrollbars anywhere (page + every popup / list) — scrolling still works.
   Normal view keeps its scrollbars. */
@media (max-width:768px){
    html, body, *{ scrollbar-width:none !important; -ms-overflow-style:none !important; }
    *::-webkit-scrollbar, html::-webkit-scrollbar, body::-webkit-scrollbar{ display:none !important; width:0 !important; height:0 !important; background:transparent !important; }}
body{
    background:var(--bg);
    background-attachment:fixed;
    color:var(--text);
    min-height:100vh;
    display:flex; flex-direction:column; align-items:center;
    padding:0 0 76px;
    overflow-x:hidden;
}

/* ── PAGE OFFSET for the sidebar that nav.php draws ──
   (only spacing — the sidebar itself and ALL of its CSS now live in nav.php.
    If nav.php already offsets the page by itself, delete these two blocks.) */
@media (max-width:768px){
    body{ padding-bottom:76px; align-items:center; padding-left:0; }}
@media (min-width:769px){
    body{ padding-left:var(--sidebar-w); align-items:flex-start; }
    :root{ --rp-sidebar-offset: var(--sidebar-w); }}
@media (min-width:490px) and (max-width:800px){ :root{ --max-w:100%; }}
/* NORMAL (desktop) VIEW — profile lane is a little narrower than before, but wider than the pod feed (520px) */
@media (min-width:801px){ :root{ --max-w:580px; }}

.rp-page{ width:100%; max-width:var(--max-w); margin:0 auto; }

@media (min-width:769px){
    .rp-page{
        border-left:1px solid rgba(var(--fg,255,255,255),0.09);
        border-right:1px solid rgba(var(--fg,255,255,255),0.09);
        min-height:100vh;
    }}

/* ══════════════════════════════════════
   TOPBAR — sits directly inside .rp-page (NOT inside #dzTopReal),
   so it stays pinned for the entire scroll. No bottom border, so
   the tabs bar docks flush against it and the two read as one bar.
══════════════════════════════════════ */
.rp-topbar{
    position:sticky; top:0; z-index:500;
    width:100%; max-width:var(--max-w); margin:0 auto;
    height:var(--topbar-h);
    display:flex; align-items:center; justify-content:space-between;
    padding:0 calc(clamp(10px, 3vw, 16px) * var(--hd-s)) 0 calc(clamp(3px, 1.2vw, 6px) * var(--hd-s));
    background:var(--bg);
    background-attachment:fixed;
    border-bottom:none;
}

/* Username that fades in beside the back/left icon once the real
   username (in the header below) has scrolled up behind the topbar. */
.rp-topbar-username{
    position:absolute;
    left:calc(clamp(43px, 6vw + 19px, 54px) * var(--hd-s)); right:calc(clamp(60px, 9vw + 26px, 90px) * var(--hd-s)); top:0; bottom:0;
    display:flex; align-items:center;
    font-size:calc(clamp(.8rem, .6rem + 1vw, .92rem) * var(--hd-s)); font-weight:700; color:#fff;
    opacity:0; transform:translateY(4px);
    transition:opacity .28s ease, transform .28s ease;
    pointer-events:none;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    z-index:1;
}
.rp-topbar-username.show-title{ opacity:1; transform:translateY(0); }
/* extra room when the track-request avatar sits before the bell */
.rp-topbar.has-req .rp-topbar-username{ right:calc(clamp(100px, 9vw + 66px, 135px) * var(--hd-s)); }
/* NORMAL view only: no back button on other people's profiles, so close the empty gap */
@media (min-width:769px){
    .rp-topbar.not-owner .rp-topbar-username{ left:calc(16px * var(--hd-s)); }}

.rp-tb-side{ display:flex; align-items:center; gap:calc(10px * var(--hd-s)); flex:0 0 auto; min-width:calc(34px * var(--hd-s)); }
.rp-tb-side.right{ justify-content:flex-end; }
.rp-tb-btn{
    background:none; border:none; color:var(--text,#F0F2FF); font-size:calc(clamp(1.25rem, 1.1rem + 1vw, 1.55rem) * var(--hd-s));
    display:flex; align-items:center; justify-content:center;
    opacity:.9; transition:opacity .2s, color .2s; text-decoration:none; padding:2px;
}
.rp-tb-btn:hover{ opacity:1; color:var(--red); }
/* nav-style thick search icon (same drawing as the sidebar), sized to the button font so it matches Menu / bell */
.tb-sicon{width:1em;height:1em;display:block;flex-shrink:0}
/* Search + Memepedia use the exact same fluid size as the Menu and bell icons so the whole topbar row looks even */
.rp-tb-btn.search, .rp-tb-btn.memepedia{ font-size:calc(clamp(1.25rem, 1.1rem + 1vw, 1.55rem) * var(--hd-s)); }
.rp-tb-btn.memepedia{ margin-left:calc(4px * var(--hd-s)); }
.dz-va-seg{ display:flex; margin:2px 0 8px; width:100%; max-width:none; border-radius:6px; background:rgba(var(--fg,255,255,255),0.07); padding:3px; gap:3px; }
.dz-va-seg a{ flex:1; text-align:center; text-decoration:none; color:rgba(var(--fg,255,255,255),0.6); font-size:clamp(.58rem, .5rem + .4vw, .7rem); font-weight:500; padding:6px 8px; border-radius:4px; letter-spacing:.01em; }
.dz-va-seg a.on{ background:rgba(var(--fg,255,255,255),0.14); color:var(--text,#F0F2FF); }
.dz-va-note{ color:#FF2D2D; }
/* Exit View As X = the same X as the profile menu (thin 2.4 stroke, 22px glyph in a 34px box; 30px on phones) */
#dzVaExit{ width:calc(34px * var(--md-s,1)); height:calc(34px * var(--md-s,1)); padding:0; margin-left:0; font-size:0; }
#dzVaExit svg{ width:calc(22px * var(--md-s,1)); height:calc(22px * var(--md-s,1)); display:block; }
@media (max-width:490px){ #dzVaExit{ width:calc(30px * var(--md-s,1)); height:calc(30px * var(--md-s,1)); } }
.dz-va-strip{ text-align:center; font-size:clamp(.54rem, .46rem + .4vw, .66rem); letter-spacing:.04em; color:#FF2D2D; padding:6px var(--rp-header-pad-x) 0; font-weight:400; }
#dzVaToast{ position:fixed; left:50%; bottom:78px; transform:translateX(-50%) translateY(8px); background:rgba(12,12,28,.96); color:#fff; font-size:.68rem; padding:8px 14px; border-radius:99px; border:1px solid rgba(255,255,255,.12); opacity:0; pointer-events:none; transition:opacity .2s ease, transform .2s ease; z-index:2147483647; white-space:nowrap; }
#dzVaToast.show{ opacity:1; transform:translateX(-50%) translateY(0); }
.rp-tb-btn.back{ font-size:calc(clamp(26px, 24.03px + 1.18vw, 32px) * 1.155); margin-left:calc(8px * var(--hd-s, 1)); }
.rp-tb-btn.back .dz-back-svg{ height:calc(clamp(18px, 16px + 0.6vw, 22px) * 1.155); width:auto; aspect-ratio:14 / 24; display:block; overflow:visible; }   /* thin, tall chevron — same arrow as zeems */   /* same size as the immersive modal's back icon; nudged 3% right */
/* Back button: HIDDEN on normal (desktop) view — it only appears on
   responsive / mobile view (<= 768px, i.e. when the sidebar becomes the bottom bar). */
@media (min-width:769px){
    .rp-tb-btn.back{ display:none; }}
.rp-tb-btn.bell{ position:relative; }
/* bell from profile_notifications.php — same size / look as the other topbar icons */
.rp-tb-side .notif-bell{ font-size:calc(clamp(1.25rem, 1.1rem + 1vw, 1.55rem) * var(--hd-s)); padding:2px; opacity:.9; }
.rp-tb-side .notif-bell:hover{ opacity:1; }
.rp-tb-dot{
    position:absolute; top:2px; right:2px; width:calc(8px * var(--hd-s)); height:calc(8px * var(--hd-s));
    background:var(--red); border-radius:50%; border:1.5px solid var(--ink,#06071A);
}

/* ── Latest track requester (owner only) — small avatar + bold count,
   sits right before the notification bell. Tap → Settings track requests. ── */
.rp-tb-req{
    position:relative; display:flex; align-items:center; justify-content:center;
    width:calc(clamp(27px, 5.5vw, 30px) * var(--hd-s)); height:calc(clamp(27px, 5.5vw, 30px) * var(--hd-s)); margin-right:calc(6px * var(--hd-s)); text-decoration:none; flex-shrink:0;
}
.rp-tb-req img{
    width:100%; height:100%; border-radius:50%; object-fit:cover; display:block;
    border:none;
}
.rp-tb-req-count{
    position:absolute; bottom:calc(-7px * var(--hd-s)); right:calc(-10px * var(--hd-s));
    min-width:calc(18px * var(--hd-s)); height:calc(18px * var(--hd-s)); padding:0 calc(5px * var(--hd-s));
    display:flex; align-items:center; justify-content:center;
    border-radius:calc(99px * var(--hd-s));
    background:var(--lane,#070924);
    border:none;
    font-size:calc(.66rem * var(--hd-s)); font-weight:700; color:var(--text,#F0F2FF); line-height:1;
    pointer-events:none;
}

.rp-tb-default{ display:flex; align-items:center; gap:calc(10px * var(--hd-s)); }
.rp-tb-track-wrap{ display:none; align-items:center; }
.rp-topbar.tabs-stuck:not(.no-track) .rp-tb-default{ display:none; }
.rp-topbar.tabs-stuck:not(.no-track) .rp-tb-track-wrap{ display:flex; }

/* Topbar Track button — no icon, solid red background + red border,
   black bold "Track" text. Behaves like the Track button in the page. */
.rp-tb-track-btn{
    display:flex; align-items:center; justify-content:center;
    background:var(--track-red); border:1.5px solid var(--track-red);
    color:#000; font-size:calc(clamp(.58rem, .5rem + .6vw, .72rem) * var(--hd-s) * var(--act-t)); font-weight:700; line-height:1;   /* same word size as the Track button in the action row (incl. its phone boost) */
    height:var(--rp-action-h);              /* same thickness as the Track button in the page */
    padding:0 calc(24px * var(--hd-s)); min-width:calc(92px * var(--hd-s));         /* width unchanged */
    border-radius:var(--rp-action-radius); cursor:pointer; white-space:nowrap;
    transition:background .2s, border-color .2s, transform .15s;
}
.rp-tb-track-btn:hover{ background:var(--track-red-hover); border-color:var(--track-red-hover); }
.rp-tb-track-btn:active{ transform:scale(.94); }
.rp-tb-track-btn:disabled{ cursor:default; }
.rp-tb-track-btn.pending{
    background:rgba(var(--fg,255,255,255),0.06); border-color:rgba(var(--fg,255,255,255),0.15);
    color:rgba(var(--fg,255,255,255),0.45); cursor:pointer;
}
.rp-tb-track-btn.pending:hover{ background:rgba(var(--fg,255,255,255),0.1); border-color:rgba(var(--fg,255,255,255),0.15); }
/* spinner inside the topbar Track button keeps the button height steady */
.rp-tb-track-btn .spinner-icon{ height:calc(15px * var(--hd-s)); }

.rp-header{
    display:flex; align-items:flex-start; justify-content:space-between;
    gap:16px; padding:6px var(--rp-header-pad-x) 0;
}
.rp-info-block{ flex:1; min-width:0; display:flex; flex-direction:column; gap:0; }

.rp-username-row{
    display:flex; align-items:center; gap:calc(4px * var(--hd-s)); cursor:pointer; user-select:none;
}
.rp-username-row.not-owner{ cursor:default; }
.rp-username{
    font-size:var(--rp-username-fs); font-weight:700; color:rgba(var(--fg,255,255,255),0.94); letter-spacing:-.2px;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:var(--rp-username-maxw);
    transition:font-size .15s ease;
}
/* Long usernames: shrink a little to stay on one line; if it still does not fit it ends with "…" (set by dzUsernameFit) */
.rp-username.u-s1{ font-size:calc(var(--rp-username-fs) * .9); }
.rp-username.u-s2{ font-size:calc(var(--rp-username-fs) * .8); }
/* Other users' username is a link to their account.php page */
a.rp-username{ text-decoration:none; cursor:pointer; display:block; }
a.rp-username:hover{ color:var(--text,#F0F2FF); text-decoration:none; }
.rp-chevron{
    font-size:calc(20px * var(--hd-s)); color:var(--text,#F0F2FF); transition:transform .25s ease, color .2s ease;
}
.rp-chev-wrap{ position:relative; display:inline-flex; align-items:center; flex-shrink:0; }
/* sits beside the chevron with a clear gap, raised slightly, well clear of the topbar */
.rp-chev-wrap{ gap:2px; }
.rp-chev-dot{
    position:relative; top:calc(-5px * var(--hd-s));
    flex-shrink:0; width:calc(6px * var(--hd-s)); height:calc(6px * var(--hd-s)); border-radius:50%;
    background:var(--red);
    pointer-events:none;
}
.rp-nickname{ cursor:pointer; width:fit-content; max-width:100%; -webkit-user-select:none; user-select:none; transition:opacity .15s ease; }
.rp-nickname:active{ opacity:.55; }
.rp-nickname{
    font-size:var(--rp-nickname-fs); font-weight:500; color:var(--muted); margin-top:0px;
}

.rp-avatar-wrap{ position:relative; flex-shrink:0; }
.rp-avatar{
    width:var(--rp-avatar); height:var(--rp-avatar); border-radius:50%; object-fit:cover;
    border:var(--rp-avatar-border) solid var(--red); display:block; cursor:pointer;
    box-shadow:none;   /* no glow behind the profile picture */
    transition:width .15s ease, height .15s ease, transform .3s;
}
.rp-avatar:hover{ transform:scale(1.04); box-shadow:none; }

/* Vibe Check nudge — 3 filled red icons around the avatar, popping up ONE AT A TIME (3 pops) */
.dz-vbn{
    position:absolute; z-index:3; padding:0; margin:0; border:0; background:none; cursor:pointer;
    display:flex; align-items:center; justify-content:center; line-height:0;
    visibility:hidden; opacity:0; transform:scale(.2) rotate(-25deg);
    animation:dzVbnPop 1.9s ease forwards;
    -webkit-tap-highlight-color:transparent;
    filter:drop-shadow(0 2px 5px rgba(255,45,45,.55));
}
.dz-vbn svg{ width:100%; height:100%; fill:#ff2d2d; stroke:#ff2d2d; stroke-width:1; stroke-linejoin:round; }
.dz-vbn1{ width:21px; height:21px; top:44%;    left:-13px;  animation-delay:.4s; }
.dz-vbn2{ width:17px; height:17px; top:67%;    left:-9px;   animation-delay:2.5s; }
.dz-vbn3{ width:19px; height:19px; bottom:-7px; left:7%;    animation-delay:4.6s; }
.dz-vbn.gone{ animation:dzVbnOut .25s ease forwards; pointer-events:none; }
@keyframes dzVbnPop{
    0%  { visibility:visible; opacity:0; transform:scale(.2) rotate(-25deg); }
    14% { visibility:visible; opacity:1; transform:scale(1.2) rotate(6deg); }
    24% { visibility:visible; opacity:1; transform:scale(1) rotate(0deg); }
    80% { visibility:visible; opacity:1; transform:scale(1) rotate(0deg); }
    99% { visibility:visible; opacity:0; transform:scale(.5) rotate(0deg); }
    100%{ visibility:hidden;  opacity:0; transform:scale(.5); }
}
@keyframes dzVbnOut{ from{ opacity:1; transform:scale(1); } to{ opacity:0; transform:scale(.2); } }
.rp-online-dot{
    --rp-online: calc(clamp(16px, 4.7vw, 22px) * var(--hd-s));
    position:absolute; z-index:5; pointer-events:none; box-sizing:border-box;
    /* centred on exactly the same point as the + button (which sits on the edge of the circle) */
    right:calc((var(--rp-avatar-plus) - var(--rp-online)) / 2);
    bottom:calc(2px + (var(--rp-avatar-plus) - var(--rp-online)) / 2);
    width:var(--rp-online); height:var(--rp-online);
    border-radius:50%;
    background:#17895a;                                   /* dark mid-tone green */
    border:3px solid var(--surface-deep,#05061a);                             /* same colour as the page (like the + button's ring) */
    box-shadow:none;
}
/* responsive view: the avatar is smaller, so push the dot out to the edge like on normal view */
@media (max-width:768px){
    .rp-online-dot{
        right:calc((var(--rp-avatar-plus) - var(--rp-online)) / 2 - calc(4px * var(--hd-s)));
        bottom:calc(2px + (var(--rp-avatar-plus) - var(--rp-online)) / 2 - calc(4px * var(--hd-s)));
    }}
.rp-avatar-plus{
    position:absolute; bottom:2px; right:0; width:var(--rp-avatar-plus); height:var(--rp-avatar-plus); border-radius:50%;
    background:var(--red); border:3.5px solid var(--surface-deep,#05061a);
    display:flex; align-items:center; justify-content:center; cursor:pointer; z-index:5;
    transition:width .15s ease, height .15s ease, transform .18s ease, box-shadow .18s ease;
}
.rp-avatar-plus:hover{ transform:scale(1.12); box-shadow:0 4px 14px rgba(255,45,45,.7); }
.rp-avatar-plus{ padding:0; box-sizing:border-box; }
.rp-avatar-plus svg{ display:block; flex:0 0 auto; width:calc(var(--rp-avatar-plus) * 0.375); height:calc(var(--rp-avatar-plus) * 0.375); }

.rp-stats-row{
    display:flex; align-items:center; gap:0;
    padding:calc(8px * var(--hd-s)) var(--rp-header-pad-x) 0 0;
    margin-top:2px;
}
.rp-stat{
    display:flex; flex-direction:column; align-items:flex-start;
    padding-right:var(--rp-stat-gap); margin-right:var(--rp-stat-gap); border-right:1px solid rgba(var(--fg,255,255,255),0.1);
    text-decoration:none; color:inherit;
}
.rp-stat:last-child{ border-right:none; padding-right:0; margin-right:0; }
/* Only applied to the Pods stat when the Posts stat sits right beside it. */
.rp-stat.rp-stat-wide{
    padding-right:calc(var(--rp-stat-gap) * 1.8);
    margin-right:calc(var(--rp-stat-gap) * 1.8);
}
.rp-stat-num{ font-size:var(--rp-stat-num-fs); font-weight:700; color:var(--text,#F0F2FF); line-height:1.1; }
.rp-stat-lbl{ font-size:var(--rp-stat-lbl-fs); color:var(--muted); margin-top:2px; font-weight:500; }
.rp-stat-num.blur{
    color:transparent;
    text-shadow:0 0 14px rgba(255,255,255,0.25);
    user-select:none;
    pointer-events:none;
}
.rp-pills{ padding:calc(8px * var(--hd-s)) var(--rp-header-pad-x) 0; display:flex; flex-direction:column; gap:calc(7px * var(--hd-s)); }
.rp-pills-top{ display:flex; align-items:center; gap:calc(7px * var(--hd-s)); flex-wrap:wrap; }
.rp-pill{
    display:inline-flex; align-items:center;
    background:var(--surface); border:1px solid var(--border);
    border-radius:var(--rp-pill-radius); padding:var(--rp-pill-pad-y) var(--rp-pill-pad-x);
    font-size:var(--rp-pill-fs); font-weight:500; color:rgba(var(--fg,255,255,255),0.85);
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.rp-pill.pronouns{ flex:0 1 auto; }
.rp-pill.type{ flex:0 1 auto; }
.rp-pill.type .rp-pill-val{ color:var(--red); font-weight:600; }
.rp-pill.active-hours{
    display:inline-flex; width:fit-content; min-width:55%; max-width:85%;
}
.rp-pill.active-hours{ gap:calc(6px * var(--hd-s)); }
.rp-pill.active-hours .rp-pill-lbl{
    flex-shrink:0;
    color:rgba(var(--fg,255,255,255),0.4);          /* "A.H :" — grey */
    font-weight:600;
}
.rp-pill.active-hours .rp-pill-val{
    flex:1; min-width:0;
    text-align:left;
    color:rgba(var(--fg,255,255,255),0.92);         /* the hours the user picked — brighter */
    overflow:hidden; text-overflow:ellipsis;
}
.rp-pill-val{ color:rgba(var(--fg,255,255,255),0.75); }

.rp-post-count{
    background:none; border:none; padding:0; cursor:pointer;
    font-size:var(--rp-pill-fs);
    font-weight:500;
    color:rgba(var(--fg,255,255,255),0.55);
    display:inline-flex;
    align-items:center;
    white-space:nowrap;
}
.rp-post-count b{ color:var(--text,#F0F2FF); font-weight:700; margin-right:calc(3px * var(--hd-s)); }

/* ── PROFILE TYPE — the type picked in Settings → Profile Type.
   Sits directly ABOVE the bio. Dark grey PLAIN TEXT: no bubble, no bar, no pill. ── */
.rp-profile-type{
    padding:calc(8px * var(--hd-s)) var(--rp-header-pad-x) 0;
    font-size:var(--rp-bio-fs);
    font-weight:500;
    line-height:1.4;
    color:#6F7282;              /* dark grey */
    background:none;
    border:none;
    border-radius:0;
    max-width:min(560px, 92vw);
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}
/* when a profile type is shown, tuck the bio a little closer beneath it */
.rp-profile-type:not(.is-empty) + .rp-bio{ padding-top:2px; }

.rp-bio{
    padding:calc(8px * var(--hd-s)) var(--rp-header-pad-x) 0; font-size:var(--rp-bio-fs); line-height:1.5; color:rgba(var(--fg,255,255,255),0.85);
    max-width:min(560px, 92vw);
}
/* Every link / email typed inside the bio uses the SAME purple. */
.rp-bio a,
.rp-bio a:visited,
.rp-bio a.dz-link{
    color:var(--link) !important; text-decoration:none; font-weight:500; font-size:1em;
}
.rp-bio a:hover{ color:var(--link-hover) !important; }
/* @username / @nickname mentions — red, click → that user's profile */
.rp-bio a.dz-mention,
.rp-bio a.dz-mention:visited{
    color:var(--red) !important; font-weight:600; font-size:1em; text-decoration:none;
}
.rp-bio a.dz-mention:hover{ color:#ff5a5a !important; }

/* "more" / "close" toggles — same size as the bio text, semi-bold, bright white. */
.rp-bio-toggle{
    color:var(--text,#F0F2FF);
    font-size:var(--rp-bio-fs);
    font-weight:600;
    cursor:pointer;
    margin-left:calc(4px * var(--hd-s));
    letter-spacing:.1px;
    user-select:none;
    -webkit-user-select:none;
    transition:color .18s ease, opacity .18s ease;
}
.rp-bio.dz-bio-fitting #rp-bio-more{ visibility:hidden; }
.rp-bio-toggle:hover{ color:var(--text,#F0F2FF); opacity:.8; }
.rp-bio-toggle:active{ color:var(--text,#F0F2FF); opacity:.6; }

/* ══════════════════════════════════════
   PROFILE LINKS PREVIEW (sits directly below the bio)
   Same colour as the bio text (NOT purple).
══════════════════════════════════════ */
.plp-wrap{
    padding:calc(6px * var(--hd-s)) var(--rp-header-pad-x) 0;
    display:flex;
    flex-direction:column;
    gap:calc(5px * var(--hd-s));
    cursor:pointer;
    max-width:100%;
}
.plp-row{
    display:flex;
    align-items:center;
    gap:calc(6px * var(--hd-s));
    min-width:0;
    text-decoration:none;
    cursor:pointer;
}
.plp-row i{
    font-size:calc(18px * var(--hd-s));
    color:var(--links-color);
    flex-shrink:0;
}
.plp-text{
    font-size:var(--rp-bio-fs);
    font-weight:500;
    color:var(--links-color);
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
    max-width:min(230px, 60vw);
    transition:color 0.18s;
}
.plp-more{
    font-size:var(--rp-bio-fs);
    font-weight:600;
    color:var(--links-color);
    flex-shrink:0;
    white-space:nowrap;
}
.plp-wrap:hover .plp-text{ color:var(--text,#F0F2FF); text-decoration:underline; }

.plp-loc{
    display:flex; align-items:center; gap:calc(4px * var(--hd-s)); min-width:0;
    padding:calc(6px * var(--hd-s)) var(--rp-header-pad-x) 0; max-width:100%;
}
.plp-loc[hidden]{ display:none; }
/* Dezzme Portfolio link under the location: same size / spacing as the location row, red, one unbroken red underline that runs under the arrow too */
.plp-pf{ display:flex; width:fit-content; max-width:100%; padding:calc(6px * var(--hd-s)) var(--rp-header-pad-x) 0; color:var(--track-red,#FF2D2D); text-decoration:none; -webkit-tap-highlight-color:transparent; }
.plp-pf-in{ display:inline-flex; align-items:center; gap:calc(4px * var(--hd-s)); min-width:0; padding-bottom:1px; border-bottom:1px solid var(--track-red,#FF2D2D); }
.plp-pf-in span{ font-size:var(--rp-bio-fs); font-weight:500; line-height:normal; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:min(260px, 70vw); }
.plp-pf-in i{ font-size:calc(14px * var(--hd-s)); flex-shrink:0; }
.plp-pf:active{ opacity:.7; }
.plp-pf:hover{ filter:brightness(1.15); }
.plp-loc{ cursor:pointer; -webkit-tap-highlight-color:transparent; width:fit-content; }
.plp-loc i{ font-size:calc(14px * var(--hd-s)); color:rgba(var(--fg,255,255,255),0.45); flex-shrink:0; }
.plp-loc .plp-loc-chev{ font-size:calc(15px * var(--hd-s)); margin-left:-2px; }
.plp-loc:hover span, .plp-loc:hover i{ color:rgba(var(--fg,255,255,255),0.7); }
.plp-loc span{
    font-size:var(--rp-bio-fs); font-weight:500; color:rgba(var(--fg,255,255,255),0.45);
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:min(260px, 70vw);
}

.rp-strip-wrap{
    margin:calc(clamp(10px, 2.4vw, 12px) * var(--hd-s)) calc(clamp(12px, 3vw, 16px) * var(--hd-s)) 0;
}
.rp-strip-wrap > .rp-strip-section + .rp-strip-section{ margin-top:calc(6px * var(--hd-s)); }
.rp-strip-section{ padding:0; }

/* ══════════════════════════════════════
   ACTION BUTTONS — every button (Track / Message / Block / Email /
   Edit Picture / Dashboard / Untracked / About) shares --rp-action-h (height) and
   --rp-action-radius (corner roundness). See :root.
   (10px bottom padding = the only gap left before highlights / tabs)
══════════════════════════════════════ */
.rp-actions{
    display:flex; flex-direction:row; flex-wrap:nowrap; align-items:stretch; gap:calc(clamp(5px, 1.5vw, 8px) * var(--hd-s));
    padding:calc(10px * var(--hd-s)) var(--rp-header-pad-x) calc(10px * var(--hd-s));
}
.rp-actions form{ margin:0; flex:1; min-width:0; display:flex; }
.rp-actions form.rp-block-form{ flex:1; min-width:0; }

/* Shop — same glass pill as Message / Edit Picture */
.rp-rect-btn.shop{
    background:rgba(var(--fg,255,255,255),0.1); backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    border:none; color:var(--text,#F0F2FF);
}
.rp-rect-btn.shop:hover{ background:rgba(var(--fg,255,255,255),0.2); }
/* Email square (chevron) → reveals the Email button row underneath */
.rp-email-sq i{ font-size:calc(clamp(17px, 1vw + 12px, 20px) * var(--hd-s) * var(--act-s)); transition:transform .22s ease; }
.rp-email-sq.open i{ transform:rotate(180deg); }
.rp-emailrow{ display:none; padding:0 var(--rp-header-pad-x) calc(10px * var(--hd-s)); }
.rp-emailrow.open{ display:flex; animation:rpEmailIn .18s ease; }
.rp-emailrow > .rp-rect-btn, .rp-emailrow > .rp-email-wrap{ flex:1; width:100%; }

.rp-rect-btn{
    display:flex; align-items:center; justify-content:center; gap:calc(clamp(4px, 1vw, 6px) * var(--hd-s));
    flex:1; min-width:0; width:100%; padding:calc(clamp(3px, .4vw + 1.5px, 4px) * var(--hd-s)) calc(clamp(7px, 1vw + 4px, 10px) * var(--hd-s)); border-radius:var(--rp-action-radius);
    height:var(--rp-action-h); line-height:1;   /* same fixed height on EVERY action button */
    font-size:calc(clamp(.58rem, .5rem + .6vw, .72rem) * var(--hd-s) * var(--act-t)); font-weight:600; color:var(--text,#F0F2FF); text-decoration:none;
    border:none;
    background:rgba(var(--fg,255,255,255),0.07);
    transition:background .2s ease, border-color .2s ease, transform .15s ease;
    white-space:nowrap; overflow:hidden;
}
.rp-rect-btn i{ font-size:calc(clamp(12px, .3vw + 11px, 13px) * var(--hd-s) * var(--act-s)); }
.rp-rect-btn:hover{ background:rgba(var(--fg,255,255,255),0.14); }
.rp-rect-btn:active{ transform:scale(0.98); }

/* TRACK / REQUEST TO TRACK — solid red background, black text (bolder) */
.rp-rect-btn.track{
    background:var(--track-red);
    border:none;
    color:#000;
    font-weight:700;
}
.rp-rect-btn.track:hover{ background:var(--track-red-hover); }
.rp-rect-btn.track.pending{
    background:rgba(var(--fg,255,255,255),0.1);
    border:none;
    color:rgba(var(--fg,255,255,255),0.45);
    font-weight:600;
}
.rp-rect-btn.track.pending:hover{ background:rgba(var(--fg,255,255,255),0.16); }

.rp-rect-btn.message{
    background:rgba(var(--fg,255,255,255),0.1); backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    border:none; color:var(--text,#F0F2FF);
}
.rp-rect-btn.message:hover{ background:rgba(var(--fg,255,255,255),0.2); }

.rp-rect-btn.track.has-chevron{
    justify-content:center;
    gap:calc(5px * var(--hd-s));
    border:none;
    color:var(--text,#F0F2FF);
    font-weight:600;
    background:rgba(var(--fg,255,255,255),0.1);
    backdrop-filter:blur(10px);
    -webkit-backdrop-filter:blur(10px);
}
.rp-rect-btn.track.has-chevron:hover{
    background:rgba(var(--fg,255,255,255),0.2);
}
.rp-rect-btn.track.has-chevron i{
    font-size:1.3em;
    line-height:1;
}

.rp-rect-btn.block, .rp-rect-btn.unblock{
    flex:1; width:100%; padding:calc(4px * var(--hd-s)) calc(10px * var(--hd-s));
    background:rgba(var(--fg,255,255,255),0.1); backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    border:none; color:var(--text,#F0F2FF);
}
.rp-rect-btn.block{ color:var(--red); font-weight:700; }
.rp-rect-btn.block:hover, .rp-rect-btn.unblock:hover{ background:rgba(var(--fg,255,255,255),0.2); }

/* Email button — same shape/size as Edit Picture / Untracked / Block / Message. */
.rp-rect-btn.email{
    flex:1; width:100%; padding:calc(4px * var(--hd-s)) calc(10px * var(--hd-s));
    background:rgba(var(--fg,255,255,255),0.1); backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    border:none; color:var(--text,#F0F2FF); font-weight:600;
}
.rp-rect-btn.email:hover{ background:rgba(var(--fg,255,255,255),0.2); }

/* Email with 2+ links → filled inverted-triangle chevron + dropdown */
.rp-rect-btn.email.has-chevron{ gap:2px; padding-left:calc(20px * var(--hd-s)); }
.rp-rect-btn.email.has-chevron i{
    position:static;
    font-size:calc(clamp(18px, 1.2vw + 12px, 20px) * var(--hd-s) * var(--act-s)); line-height:1;
    margin-right:calc(-3px * var(--hd-s));
    transition:transform .22s ease;
}
.rp-email-wrap{
    position:relative; flex:1; min-width:0; display:flex;
}
.rp-email-wrap.open .rp-rect-btn.email.has-chevron i{ transform:rotate(180deg); }
.rp-email-menu{
    display:none;
    position:absolute; top:calc(100% + calc(6px * var(--hd-s))); right:0;
    min-width:calc(clamp(210px, 60vw, 230px) * var(--hd-s)); max-width:min(300px, 88vw); max-height:260px; overflow-y:auto;
    padding:calc(6px * var(--hd-s));
    background:rgba(12,12,28,0.98);
    border:1px solid rgba(var(--fg,255,255,255),0.09);
    border-radius:calc(14px * var(--hd-s));
    box-shadow:0 14px 34px rgba(0,0,0,0.6);
    z-index:450;
    scrollbar-width:thin; scrollbar-color:rgba(255,255,255,0.15) transparent;
}
.rp-email-wrap.open .rp-email-menu{ display:block; animation:rpEmailIn .18s ease; }
@keyframes rpEmailIn{ from{ opacity:0; transform:translateY(-4px); } to{ opacity:1; transform:translateY(0); } }
.rp-email-item{
    display:flex; align-items:center; gap:calc(10px * var(--hd-s));
    padding:calc(clamp(9px, 2vw, 10px) * var(--hd-s)) calc(clamp(11px, 2.4vw, 12px) * var(--hd-s)); border-radius:calc(9px * var(--hd-s));
    color:var(--text,#F0F2FF); text-decoration:none;
    font-size:calc(clamp(.68rem, .58rem + .4vw, .74rem) * var(--hd-s)); font-weight:500;
    transition:background .18s ease;
}
.rp-email-item:hover{ background:rgba(var(--fg,255,255,255),0.07); }
.rp-email-item i{ font-size:calc(16px * var(--hd-s)); color:var(--link); flex-shrink:0; }
.rp-email-item span{ min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

/* Edit Picture / Dashboard */
.rp-rect-btn.pic{
    background:rgba(var(--fg,255,255,255),0.1); backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    border:none; color:var(--text,#F0F2FF);
}
.rp-rect-btn.pic:hover{ background:rgba(var(--fg,255,255,255),0.2); }

.rp-rect-btn.untracked{
    background:rgba(var(--fg,255,255,255),0.1); backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    border:none; color:var(--text,#F0F2FF);
}
.rp-rect-btn.untracked:hover{ background:rgba(var(--fg,255,255,255),0.2); }
/* red dot beside the word "Untracked" */
.rp-rect-btn.untracked{ position:relative; }
.rp-untr-txt{ position:relative; display:inline-block; }
.rp-untr-dot{
    position:absolute; left:100%; top:calc(-4px * var(--hd-s)); margin-left:calc(5px * var(--hd-s));   /* hangs off the end of the word: the word itself stays dead-centre */
    width:calc(6px * var(--hd-s)); height:calc(6px * var(--hd-s)); border-radius:50%;
    background:var(--red);
    pointer-events:none;
}

/* Small square "About me" box */
.rp-square-btn{
    flex:0 0 auto;
    width:var(--rp-action-h);
    height:var(--rp-action-h);
    border-radius:var(--rp-action-radius);
    display:flex; align-items:center; justify-content:center;
    background:rgba(var(--fg,255,255,255),0.1);
    backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    border:none; color:var(--text,#F0F2FF);
    font-size:calc(clamp(14px, .6vw + 11px, 16px) * var(--hd-s) * var(--act-s));
    transition:background .2s ease, transform .15s ease;
    padding:0;
    align-self:center;
}
.rp-square-btn:hover{ background:rgba(var(--fg,255,255,255),0.2); }
.rp-square-btn:active{ transform:scale(0.94); }


/* ══════════════════════════════════════
   SPINNING WHEEL on the About-me square button (own profile)
   12 rounded bars, darkest at 5 o'clock fading round to the lightest at 6,
   turning in 12 ticks. Moved here from <style id="dzWheelCss"> — delete that tag.
   • size  → --wheel (button is 25–30px, up to ~20px fits)
   • colour→ color on .dz-wheel (white because the button is dark)
   • KEEP steps(12): it gives the classic ticking look (linear would look smooth)
══════════════════════════════════════ */
.rp-square-btn.is-loading{ pointer-events:none; }
.rp-square-btn.is-loading > i{ display:none; }
.dz-wheel{
    --wheel:18px;
    position:relative; display:block; flex-shrink:0;
    width:var(--wheel); height:var(--wheel);
    color:var(--text,#F0F2FF);
    animation:dzWheelSpin .8s steps(8) infinite;
}
.dz-wheel span{
    position:absolute; left:50%; top:0;
    width:calc(var(--wheel) * .13); height:calc(var(--wheel) * .27);
    margin-left:calc(var(--wheel) * -.065);
    border-radius:99px; background:currentColor;
    transform-origin:50% calc(var(--wheel) / 2);
}
@keyframes dzWheelSpin{ to{ transform:rotate(360deg); } }
@media (prefers-reduced-motion:reduce){ .dz-wheel{ animation-duration:2s; }}

/* Equal widths: Edit Picture / Untracked / Email (and Track / Message / Block) */
.rp-actions > .rp-rect-btn,
.rp-actions > .rp-email-wrap,
.rp-actions > form{
    flex:1 1 0px; width:0; min-width:0;
}
.rp-actions > .rp-rect-btn{ padding-left:calc(4px * var(--hd-s)); padding-right:calc(4px * var(--hd-s)); }


<?php if ($profile_highlight_count > 0): /* highlights CSS only when this profile has highlights */ ?>
/* ══════════════════════════════════════
   HIGHLIGHTS STRIP — sits directly under the action buttons.
   Each tile = a square GREY ring, an empty gap, then the cover
   picture centered inside.

   INSTAGRAM-STORY STYLE ANIMATION: the ring stays grey and square.
   When you press a tile, a bright arc with a fading tail travels
   around the ring continuously (like the story-loading ring), then
   the Highlights modal opens. Only the name sits under the square.
   Strip scrolls horizontally.
══════════════════════════════════════ */
.rp-highlights{
    display:flex; flex-direction:row; flex-wrap:nowrap; align-items:flex-start;
    gap:var(--rp-hl-gap);
    padding:4px var(--rp-header-pad-x) 10px;
    overflow-x:auto; overflow-y:hidden;
    -webkit-overflow-scrolling:touch;
    scrollbar-width:none; -ms-overflow-style:none;
    overscroll-behavior-x:contain;
}
.rp-highlights::-webkit-scrollbar{ display:none; width:0; height:0; }

.rp-hl{
    flex:0 0 auto;
    width:var(--rp-hl-size);
    display:flex; flex-direction:column; align-items:center; gap:clamp(5px, 1.2vw, 6px);
    background:none; border:none; padding:0; color:var(--text,#F0F2FF);
    text-align:center; font-family:inherit;
    transition:transform .18s ease;
}
.rp-hl:active{ transform:scale(.95); }

/* the square that holds ring + picture (display:block is REQUIRED —
   spans are inline by default and would ignore width/height) */
.rp-hl-ring{
    position:relative;
    display:block;
    width:var(--rp-hl-size); height:var(--rp-hl-size);
    border-radius:var(--rp-hl-radius);
}

/* ── infinite loading: tiles after the first 8 stay hidden until you scroll near the end ── */
.rp-hl.rp-hl-hold{ display:none; }
.rp-hl.rp-hl-in{ animation:rpHlIn .3s cubic-bezier(.22,1,.36,1) both; }
@keyframes rpHlIn{ from{ opacity:0; transform:translateX(8px) scale(.94); } to{ opacity:1; transform:none; } }
.rp-hl-skel{
    flex:0 0 auto; width:var(--rp-hl-size);
    display:flex; flex-direction:column; align-items:center; gap:6px;
}
.rp-hl-skel .dz-sk.sq{ display:block; width:var(--rp-hl-size); height:var(--rp-hl-size); border-radius:var(--rp-hl-radius); }
.rp-hl-skel .dz-sk.nm{ display:block; width:70%; height:8px; border-radius:4px; }

/* ── scroll arrows for the highlights strip ──
   ALWAYS visible (normal view AND responsive view) whenever there is more
   to scroll in that direction — no hover needed. */
.rp-hl-wrap{ position:relative; }
.rp-hl-arrow{
    position:absolute;
    top:calc(calc(4px * var(--hd-s)) + var(--rp-hl-size) / 2);
    transform:translateY(-50%) scale(.88);
    width:calc(28px * var(--hd-s)); height:calc(28px * var(--hd-s)); padding:0;
    border-radius:calc(4px * var(--hd-s));
    border:1px solid rgba(255,255,255,0.2);
    background:#0b0d2b;
    -webkit-backdrop-filter:blur(6px); backdrop-filter:blur(6px);
    color:#fff; font-size:calc(18px * var(--hd-s));
    display:flex; align-items:center; justify-content:center;
    opacity:0; pointer-events:none; visibility:hidden; outline:none;
    z-index:20;
    box-shadow:0 2px 10px rgba(0,0,0,0.5);
    transition:opacity .18s ease, transform .18s ease, background .18s ease;
}
.rp-hl-arrow.left{ left:calc(6px * var(--hd-s)); }
.rp-hl-arrow.right{ right:calc(6px * var(--hd-s)); }
.rp-hl-arrow:hover{ background:#15183f; border-color:rgba(255,255,255,0.3); }
.rp-hl-arrow:active{ transform:translateY(-50%) scale(.94); }
.rp-hl-arrow i{ pointer-events:none; }

.rp-hl-wrap.can-left  .rp-hl-arrow.left,
.rp-hl-wrap.can-right .rp-hl-arrow.right{
    opacity:1; pointer-events:auto; visibility:visible; transform:translateY(-50%) scale(1);
}
/* responsive view: arrows are completely hidden (swipe instead) */
@media (max-width:768px){
    .rp-hl-arrow{ display:none !important; }}

/* static GREY border (always visible while resting) */
.rp-hl-ring::after{
    content:'';
    position:absolute; inset:var(--rp-hl-out);
    border-radius:calc(var(--rp-hl-radius) - var(--rp-hl-out));
    border:var(--rp-hl-ring) solid rgba(255,255,255,0.28);
    pointer-events:none;
    transition:border-color .12s ease;
}
/* while animating, the solid border hides — the SVG ring below takes over */
.rp-hl.spin .rp-hl-ring::after{ border-color:transparent; }

/* COMET RING (Instagram story style, but grey + square).
   The ring is drawn as an SVG outline. On press it turns into:
     • one long SOLID stretch that stays connected, and
     • a trail of dashes that get shorter and further apart (the "split" end),
   and that whole pattern SPINS around the square. At the end the trail
   closes up again into one solid ring.
   pathLength=100. The 14 numbers below always add up to 100:
   40 solid | then gap,dash pairs that shrink: 10, 8, 6, 4.5, 3, 2 | final gap 4 */
.rp-hl-svg{
    position:absolute;
    left:var(--rp-hl-out); top:var(--rp-hl-out);
    width:calc(100% - 2 * var(--rp-hl-out)); height:calc(100% - 2 * var(--rp-hl-out));
    display:block; overflow:visible;
    opacity:0;
    pointer-events:none;
    z-index:2;
}
.rp-hl-svg rect{
    fill:none;
    stroke:rgba(255,255,255,0.62);
    stroke-width:5.35;
    stroke-linecap:round;   /* rounded ends on every dash (lengths/gaps below are adjusted so the cuts keep the same look) */
    stroke-dasharray:100 0 0 0 0 0 0 0 0 0 0 0 0 0;   /* solid ring */
    stroke-dashoffset:0;
}
.rp-hl.spin .rp-hl-svg{ opacity:1; }
.rp-hl.spin .rp-hl-svg rect{
    animation:
        rpHlShape .9s ease-in-out 1 both,   /* solid → comet trail → solid */
        rpHlRun   .9s linear      1 both;   /* the whole pattern spins      */
}
@keyframes rpHlShape{
    0%   { stroke-dasharray:100 0 0 0 0 0 0 0 0 0 0 0 0 0; }             /* solid ring         */
    18%  { stroke-dasharray:38.44 4.06 8.44 4.56 6.44 5.06 4.44 5.56 2.94 6.06 1.44 6.56 .44 5.56; }     /* solid + dash trail */
    84%  { stroke-dasharray:38.44 4.06 8.44 4.56 6.44 5.06 4.44 5.56 2.94 6.06 1.44 6.56 .44 5.56; }     /* still spinning     */
    100% { stroke-dasharray:100 0 0 0 0 0 0 0 0 0 0 0 0 0; }             /* joined back solid  */
}
@keyframes rpHlRun{
    0%   { stroke-dashoffset:0;    }
    100% { stroke-dashoffset:-150; }   /* 1.5 laps clockwise around the square */
}

/* the picture — centered, with an empty gap between it and the ring */
.rp-hl-sq{
    position:absolute;
    inset:var(--rp-hl-inset);
    display:block;
    border-radius:calc(var(--rp-hl-radius) - var(--rp-hl-inset) + 2px);
    overflow:hidden;
    background:var(--surface-2,#0d1030);
}
.rp-hl-sq img,
.rp-hl-sq video{
    position:absolute; inset:0;
    width:100%; height:100%; object-fit:cover; display:block;
    pointer-events:none;
}
.rp-hl-name{
    display:block;
    width:100%;
    font-size:var(--rp-hl-name-fs); font-weight:500; line-height:1.3;
    color:rgba(255,255,255,0.85);
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}

@media (prefers-reduced-motion:reduce){
    .rp-hl.spin .rp-hl-svg rect{ animation-duration:1.5s, 1.5s; }}

<?php endif; ?>
/* ══════════════════════════════════════
   TABS — sticks directly beneath the topbar (top:var(--topbar-h)).
   (the old dark thick divider above the tabs has been removed)
══════════════════════════════════════ */
.rp-tabs-sentinel{ height:1px; }
.rp-tabs{
    position:sticky; top:calc(var(--topbar-h) - 1px); z-index:400;
    width:100%; max-width:var(--max-w); margin:0 auto;
    background:var(--bg);
    background-attachment:fixed;
    display:flex; align-items:stretch;
    overflow-x:auto; scrollbar-width:none; -ms-overflow-style:none;
    -webkit-overflow-scrolling:touch;
    border-top:1px solid rgba(var(--fg,255,255,255),0.09);
    border-bottom:1px solid rgba(var(--fg,255,255,255),0.09);
    margin-top:0;
    padding-top:0;
    transition:box-shadow .2s ease;
}
.rp-tabs.is-stuck{ box-shadow:0 8px 16px -10px rgba(0,0,0,0.65); }
.rp-tabs-track{
    position:relative;
    display:flex; align-items:stretch;
    flex:1 0 auto;
}
.rp-tab-indicator{
    position:absolute; bottom:0; left:0; height:2px;
    width:0;
    background:rgb(var(--fg,255,255,255)); border-radius:2px 2px 0 0;   /* white in dark mode, dark navy in light mode */
    transition:left .28s cubic-bezier(.4,0,.2,1), width .28s cubic-bezier(.4,0,.2,1), opacity .2s ease;
    opacity:0;
    pointer-events:none;
}
.rp-tab-indicator.ready{ opacity:1; }
[data-theme="light"] .rp-tab-indicator{ background:#0b0d24 !important; }   /* white underline is invisible on a white page */
/* while a finger is dragging the pager the underline follows it with no easing lag */
.rp-tab-indicator.live{ transition:none !important; }

.rp-tabs::-webkit-scrollbar{ display:none; }
.rp-tab-btn{
    flex:1 0 auto; min-width:calc(clamp(48px, 1.5vw + 41px, 56px) * var(--hd-s));
    display:flex; align-items:center; justify-content:center; gap:calc(clamp(5px, 1.2vw, 6px) * var(--hd-s));
    padding:calc(clamp(11px, 1.2vw + 5px, 13px) * var(--hd-s)) calc(clamp(12px, 1vw + 7px, 14px) * var(--hd-s)); background:none; border:none; position:relative;
    color:rgba(var(--fg,255,255,255),0.5); transition:color .2s ease;
}
/* tab icons: tiny built-in drawings (no icon-font download) — coloured by the button's own text colour */
.tab-ic{ display:inline-block; width:1em; height:1em; flex-shrink:0; background-color:currentColor; -webkit-mask:var(--tic) center/100% 100% no-repeat; mask:var(--tic) center/100% 100% no-repeat; }
.tab-ic-grid{ --tic:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23000' stroke-width='2.06' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M4 4h6v6h-6z'/%3E%3Cpath d='M14 4h6v6h-6z'/%3E%3Cpath d='M4 14h6v6h-6z'/%3E%3Cpath d='M14 14h6v6h-6z'/%3E%3C/svg%3E"); }
.tab-ic-repeat{ --tic:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23000' stroke-width='2.06' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M4 13.5V9.276A4.276 4.276 0 0 1 8.276 5H16M12.5 1.5L16 5L12.5 8.5'/%3E%3Cpath d='M4 13.5V9.276A4.276 4.276 0 0 1 8.276 5H16M12.5 1.5L16 5L12.5 8.5' transform='rotate(180 12 12)'/%3E%3C/svg%3E"); }
.tab-ic-usersq{ --tic:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23000' stroke-width='2.06' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M9 10a3 3 0 1 0 6 0a3 3 0 0 0 -6 0'/%3E%3Cpath d='M6 21v-1a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v1'/%3E%3Cpath d='M3 5a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v14a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2z'/%3E%3C/svg%3E"); }
.tab-ic-bookmark{ --tic:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23000' stroke-width='2.06' stroke-linecap='round' stroke-linejoin='miter'%3E%3Cpath d='M18 4v17l-6 -4l-6 4v-17a1 1 0 0 1 1 -1h10a1 1 0 0 1 1 1z'/%3E%3C/svg%3E"); }
.tab-ic-message{ --tic:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23000' stroke-width='2.06' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M8 9h8'/%3E%3Cpath d='M8 13h6'/%3E%3Cpath d='M18 4a3 3 0 0 1 3 3v8a3 3 0 0 1 -3 3h-5l-5 3v-3h-2a3 3 0 0 1 -3 -3v-8a3 3 0 0 1 3 -3h12z'/%3E%3C/svg%3E"); }
.tab-ic-userstar{ --tic:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23000' stroke-width='2.06' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M8 7a4 4 0 1 0 8 0a4 4 0 0 0 -8 0'/%3E%3Cpath d='M6 21v-2a4 4 0 0 1 4 -4h.5'/%3E%3Cpath d='M17.8 20.817l-2.172 1.138a.392 .392 0 0 1 -.568 -.41l.415 -2.411l-1.757 -1.707a.389 .389 0 0 1 .217 -.665l2.428 -.352l1.086 -2.193a.392 .392 0 0 1 .702 0l1.086 2.193l2.428 .352a.39 .39 0 0 1 .217 .665l-1.757 1.707l.414 2.41a.39 .39 0 0 1 -.567 .411l-2.172 -1.138z'/%3E%3C/svg%3E"); }
.rp-tab-btn i{ font-size:calc(clamp(18px, .8vw + 14px, 20px) * var(--hd-s) * 1.05); }   /* tab icons: original size +5% */
.rp-tab-btn i.tab-ic-usersq{ font-size:calc(clamp(21px, .8vw + 17px, 23px) * var(--hd-s) * 1.05); }
.rp-tab-btn i.tab-ic-grid, .rp-tab-btn i.tab-ic-repeat, .rp-tab-btn i.tab-ic-bookmark, .rp-tab-btn i.tab-ic-message{ font-size:calc(clamp(21px, .8vw + 17px, 23px) * var(--hd-s) * 1.05); }
.rp-tab-btn:hover{ color:rgba(var(--fg,255,255,255),0.85); }
.rp-tab-btn.active{ color:var(--text,#F0F2FF); }
.rp-tab-content{
    display:inline-flex; align-items:center; gap:calc(6px * var(--hd-s));
    width:fit-content;
}
.rp-tab-label{
    display:none;
    font-size:calc(clamp(.62rem, .4rem + .6vw, .78rem) * var(--hd-s) * 1.11); font-weight:700; color:var(--text,#F0F2FF); white-space:nowrap;   /* original size, +11% */
    position:relative; top:2px;
}
.rp-tab-btn.active .rp-tab-label{ display:inline; }
.rp-tab-badge{
    position:absolute; top:calc(8px * var(--hd-s)); right:22%;
    min-width:calc(8px * var(--hd-s)); height:calc(8px * var(--hd-s)); background:var(--red); border-radius:50%;
    border:1.5px solid var(--ink,#06071A);
}

/* ══════════════════════════════════════
   POST GRID (cards 3:4 — Large layout keeps 4:5)
   Every card is forced to the SAME 4:5 box, and whatever sits inside
   (image / video / carousel / collection wrapper) is stretched to fill
   it — so no card can ever come out shorter or cut off.
══════════════════════════════════════ */
.grid{
    display:grid;
    grid-template-columns:repeat(3, minmax(0, 1fr));
    grid-auto-rows:auto;
    align-items:stretch;
    gap:2.5px;
    width:100%; max-width:var(--max-w); margin:0 auto;
    padding:2.5px 0 0;
}
.grid .meme-card{
    display:block;
    border-radius:0; background:rgba(var(--fg,255,255,255),0.03); position:relative; overflow:hidden;
    aspect-ratio:3/4;
    width:100%; height:auto; min-height:0; max-height:none; margin:0;
}
.grid .meme-card .media-box{
    position:absolute; inset:0;
    width:100%; height:100%; max-height:none; margin:0; padding:0;
    display:block; overflow:hidden; border-radius:0; cursor:pointer;
}
.grid .meme-card .media-box img,
.grid .meme-card .media-box video{
    position:absolute; inset:0;
    width:100%; height:100%; max-height:none; object-fit:cover; display:block; border-radius:0;
    cursor:pointer;
}
/* direct wrappers inside the media box (carousels / collection slides) also fill the card */
.grid .meme-card .media-box > div:not(.card-badges):not(.card-text-overlay):not(.card-pin-indicator):not(.new-badge):not(.card-views){
    position:absolute; inset:0; width:100%; height:100%; max-height:none;
}
.grid .meme-card > img,
.grid .meme-card > video{
    position:absolute; inset:0; width:100%; height:100%; object-fit:cover; display:block; cursor:pointer;
}
.grid .meme-card .top-bar,
.grid .meme-card .caption,
.grid .meme-card .icon-bar{ display:none; }
.grid .meme-card{ container-type:inline-size; }   /* so icon / label sizes follow the tile width, like Instagram */
/* "New" label — Instagram style (big plain white text), top-left */
.grid .meme-card .new-badge{
    position:absolute; top:clamp(7px, 3.6cqw, 13px); left:clamp(8px, 4cqw, 15px); right:auto; bottom:auto; z-index:6;
    font-size:clamp(12px, 7.2cqw, 21px); font-weight:600; line-height:1.1; letter-spacing:0; text-transform:none;
    padding:0; margin:0; background:none; border:0; border-radius:0; box-shadow:none; color:#fff;
    text-shadow:0 1px 4px rgba(0,0,0,.45);
    pointer-events:none;
}
/* view count: filled eye + number, bottom-left (same as the History page) */
.grid .meme-card .card-views{
    position:absolute; left:clamp(6px, 3.2cqw, 12px); bottom:clamp(6px, 3.2cqw, 12px); z-index:2; display:flex; align-items:center; gap:clamp(3px, 1.6cqw, 6px);
    color:#fff; font-size:clamp(11px, 5cqw, 15px); font-weight:600; line-height:1; pointer-events:none;
    text-shadow:0 1px 3px rgba(0,0,0,.28);
}
.grid .meme-card .card-views i{ font-size:clamp(13px, 5.8cqw, 17px); line-height:1; filter:drop-shadow(0 1px 2px rgba(0,0,0,.25)); }
.grid .meme-card:hover::after{
    content:''; position:absolute; inset:0; background:rgba(0,0,0,0.15);
    pointer-events:none;   /* overlay never swallows the click */
}

/* ── top-right badges on a grid card (pin icon + media-type icon, side by side) ── */
.grid .meme-card .card-badges{
    position:absolute; top:clamp(6px, 1.4vw, 7px); right:clamp(6px, 1.4vw, 7px); z-index:6;
    display:flex; align-items:center; gap:clamp(5px, 1.2vw, 6px);
    pointer-events:none;
    filter:drop-shadow(0 1px 3px rgba(0,0,0,0.75));
}
.card-pin-indicator{
    position:static; top:auto; right:auto;
    font-size:14px; color:#ff2d2d; display:flex; align-items:center;
    filter:none;
}
.grid .meme-card .card-badges .dz-type-icon{
    display:flex; align-items:center; justify-content:center;
    width:clamp(19px, 1.5vw + 12px, 21px); height:clamp(19px, 1.5vw + 12px, 21px); color:#fff; line-height:1;
}
.grid .meme-card .card-badges .dz-type-icon svg{
    width:100%; height:100%; display:block; fill:currentColor;
}
/* GIF: small solid badge, like the little labels Instagram uses */
.grid .meme-card .card-badges .dz-type-icon.dz-gif{
    width:auto; height:auto; padding:2px 5px; border-radius:5px;
    background:#fff; color:#111;
    font-size:clamp(8.5px, .2vw + 7.5px, 9px); font-weight:800; letter-spacing:.4px; line-height:1;
}
.grid .meme-card .media-box > .card-pin-indicator{ top:7px; right:28px; }
/* inside the badges row the pin sits in the flow (beats the plain .card-pin-indicator rule further down) */
.grid .meme-card .card-badges .card-pin-indicator{ position:static; top:auto; right:auto; font-size:clamp(19px, 11.5cqw, 34px); line-height:1; display:flex; align-items:center; filter:none; }
/* owner's pin toggle gets the same size as the pin indicator */
.grid .meme-card .card-badges .card-pin-btn{ font-size:clamp(19px, 11.5cqw, 34px); line-height:1; padding:0; }
.grid .meme-card .card-badges .card-pin-btn i{ font-size:inherit; line-height:1; }
.grid .meme-card .card-badges .dz-type-icon{ width:clamp(20px, 11cqw, 32px); height:clamp(20px, 11cqw, 32px); }
.grid .meme-card .card-badges{ top:clamp(6px, 3.2cqw, 12px); right:clamp(6px, 3.2cqw, 12px); gap:clamp(5px, 2.6cqw, 9px); }

/* ── text post caption written on the default text image ── */
.grid .meme-card .card-text-overlay{
    position:absolute; inset:0; z-index:2;
    display:flex; align-items:center; justify-content:center;
    padding:clamp(22px, 5vw, 26px) clamp(9px, 2.5vw, 12px) clamp(14px, 3.5vw, 18px);
    background:rgba(0,0,0,0.45);
    pointer-events:none; text-align:center;
}
.grid .meme-card .card-text-overlay span{
    display:-webkit-box;
    -webkit-box-orient:vertical;
    -webkit-line-clamp:8;      /* too long → cut off with "…" */
    overflow:hidden;
    font-size:clamp(.55rem, .4rem + .9vw, .7rem); line-height:1.45; font-weight:500; color:#fff;
    word-break:break-word;
    text-shadow:0 1px 4px rgba(0,0,0,0.6);
}
@media (max-width:490px){ .grid .meme-card .card-text-overlay span{ -webkit-line-clamp:7; }}

/* ── paginated grids (first 9, then next 9 on scroll) ── */
.grid.dz-paged:not(.dz-live) > .meme-card{ display:none; }
.grid.dz-paged > .meme-card.dz-hold{ display:none; }
.grid .meme-card.dz-reveal{ animation:dzCardIn .45s cubic-bezier(.22,1,.36,1) both; }
@keyframes dzCardIn{ from{ opacity:0; transform:translateY(10px); } to{ opacity:1; transform:translateY(0); } }

/* ── grid skeleton cards ── */
.dz-skel-card{
    position:relative; overflow:hidden;
    aspect-ratio:3/4;
    background:rgba(255,255,255,0.05);
}
.dz-skel-card::after{
    content:''; position:absolute; inset:0;
    background:linear-gradient(90deg, transparent, rgba(255,255,255,0.1), transparent);
    transform:translateX(-100%);
    animation:dzGridShimmer 1.4s ease-in-out infinite;
}
@keyframes dzGridShimmer{ 100%{ transform:translateX(100%); } }
.dz-sentinel{ grid-column:1/-1; height:1px; pointer-events:none; }

/* ══════════════════════════════════════
   FEATURED POST · ARCHIVE · SELECT MODE · BLOCK POPUP · SHARE CARD
══════════════════════════════════════ */
.ft-card{
    display:flex; align-items:center; gap:12px; box-sizing:border-box;
    width:calc(100% - 24px); max-width:calc(var(--max-w) - 24px); margin:12px auto 14px; padding:7px 10px;
    border-radius:5px; background:rgba(var(--fg,255,255,255),0.05); cursor:pointer; -webkit-tap-highlight-color:transparent;
}
.ft-thumb{ flex:0 0 auto; width:44px; aspect-ratio:1/1; border-radius:6px; overflow:hidden; background:rgba(255,255,255,0.06); }
.ft-thumb img, .ft-thumb video{ width:100%; height:100%; object-fit:cover; display:block; }
.ft-body{ flex:1 1 0; min-width:0; }
.ft-tag{ display:flex; align-items:center; gap:4px; font-size:10px; font-weight:700; letter-spacing:.8px; text-transform:uppercase; color:#FF2D2D; }
.ft-note{ margin-top:2px; font-size:13px; font-weight:600; line-height:1.35; color:var(--text,#F0F2FF); display:-webkit-box; -webkit-box-orient:vertical; -webkit-line-clamp:1; overflow:hidden; word-break:break-word; }
.ft-cap{ margin-top:1px; font-size:11.5px; line-height:1.35; color:rgba(var(--fg,255,255,255),.55); display:-webkit-box; -webkit-box-orient:vertical; -webkit-line-clamp:1; overflow:hidden; word-break:break-word; }
.dz-filtering #featuredPost{ display:none; }
@media (max-width:430px){ .ft-thumb{ width:40px; } .ft-note{ font-size:12px; } .ft-cap{ font-size:11px; }}

/* select mode (Memes grid, owner) */
.dz-pick-dot{ display:none; position:absolute; top:8px; left:8px; z-index:8; width:22px; height:22px; border-radius:50%; box-sizing:border-box;
    border:2px solid #fff; background:rgba(0,0,0,.35); align-items:center; justify-content:center; color:#fff; font-size:13px; line-height:1; pointer-events:none; }
body.dz-selecting #memesGrid .dz-pick-dot{ display:flex; }
#memesGrid .meme-card.dz-picked .dz-pick-dot{ background:#FF2D2D; border-color:#FF2D2D; }
#memesGrid .meme-card.dz-picked .dz-pick-dot::after{ content:'✓'; font-weight:700; }
body.dz-selecting #memesGrid .meme-card .card-pin-btn,
body.dz-selecting #memesGrid .meme-card .card-add-col-btn{ pointer-events:none; }
body.dz-selecting #memesGrid .meme-card.dz-picked .media-box{ outline:2px solid #FF2D2D; outline-offset:-2px; }
#bulkBar{
    display:none; position:fixed; left:0; right:0; bottom:0; z-index:2147482000;
    padding:10px 12px calc(10px + env(safe-area-inset-bottom, 0px)); background:var(--lane,#070924); box-shadow:0 -8px 24px rgba(0,0,0,.5);
    align-items:center; gap:8px;
}
body.dz-selecting #bulkBar{ display:flex; }
#bulkBar .bk-count{ flex:1 1 auto; min-width:0; font-size:13px; font-weight:600; color:var(--text,#F0F2FF); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
#bulkBar button{ flex:0 0 auto; display:flex; align-items:center; gap:5px; padding:9px 12px; border:none; border-radius:8px; background:rgba(var(--fg,255,255,255),.08); color:var(--text,#F0F2FF);
    font-size:12.5px; font-weight:600; font-family:inherit; cursor:pointer; -webkit-tap-highlight-color:transparent; }
#bulkBar button:disabled{ opacity:.35; pointer-events:none; }
#bulkBar button.bk-del{ color:#FF2D2D; }
#bulkBar button.bk-x{ background:none; padding:9px 6px; color:rgba(var(--fg,255,255,255),.7); font-size:18px; }
@media (max-width:430px){ #bulkBar button{ padding:9px 9px; font-size:12px; } #bulkBar button span{ display:none; }}

/* ══════════════════════════════════════
   MEMES GRID LAYOUTS — Default (3 columns, above) · Large · List.
   The owner picks one in the Memes ⌄ sheet; it is saved per user and everybody sees it.
   Only #memesGrid ever gets lay-large / lay-list.
══════════════════════════════════════ */
.lst-body{ display:none; }

/* Large — 2 columns, gaps all round, nothing touches the screen edge, rounded corners */
.grid.lay-large{ grid-template-columns:repeat(2, minmax(0, 1fr)); gap:10px; padding:10px 12px 0; }
.grid.lay-large .meme-card,
.grid.lay-large .dz-skel-card{ border-radius:16px; aspect-ratio:4/5; }
.grid.lay-large .meme-card .media-box{ border-radius:16px; }

/* List — one row per post: rounded thumbnail on the left, caption (max 2 lines, then "…") on the right.
   No likes / reposts are shown. The :not(.dz-hold) / .dz-live parts keep paging + search exactly as before. */
.grid.lay-list{ grid-template-columns:minmax(0, 1fr); gap:14px; padding:12px 14px 0; }
.grid.lay-list.dz-live > .meme-card:not(.dz-hold),
.grid.lay-list.dz-paged > .meme-card.dz-sr-show,
.grid.lay-list.dz-paged > .meme-card.dz-hold.dz-sr-show{ display:flex; align-items:flex-start; gap:12px; }
.grid.lay-list .meme-card{ aspect-ratio:auto; background:none; overflow:visible; border-radius:0; }
.grid.lay-list .meme-card:hover::after{ display:none; }
.grid.lay-list .meme-card .media-box{
    position:relative; inset:auto; flex:0 0 auto;
    width:44%; max-width:180px; height:auto; aspect-ratio:16/10;
    border-radius:12px; background:rgba(255,255,255,0.05);
    container-type:inline-size;   /* badge sizes (cqw) follow the small thumbnail — not the full-width row — so pin + type icons match the default grid */
}
.grid.lay-list .meme-card .card-text-overlay{ display:none; }
.grid.lay-list .meme-card .card-add-col-btn{ width:26px; height:26px; font-size:12px; bottom:6px; right:6px; }
.grid.lay-list .meme-card .lst-body{ display:block; flex:1 1 0; min-width:0; cursor:pointer; padding:1px 0; }
.grid.lay-list .meme-card .lst-cap{
    display:-webkit-box; -webkit-box-orient:vertical; -webkit-line-clamp:2; overflow:hidden;
    font-size:12.5px; line-height:1.35; font-weight:600; color:var(--text,#F0F2FF); word-break:break-word; text-align:left;
}
.grid.lay-list .meme-card .lst-cap:empty::before{ content:'No caption'; font-weight:500; color:rgba(var(--fg,255,255,255),.4); }
.grid.lay-list .meme-card .lst-meta{ margin-top:5px; font-size:10.5px; color:rgba(var(--fg,255,255,255),.5); }
.grid.lay-list .dz-skel-card{ aspect-ratio:auto; height:84px; border-radius:12px; }
@media (max-width:430px){
    .grid.lay-list{ gap:12px; padding:10px 12px 0; }
    .grid.lay-list > .meme-card.dz-sr-show, .grid.lay-list.dz-live > .meme-card:not(.dz-hold){ gap:10px; }
    .grid.lay-list .meme-card .lst-cap{ font-size:11.5px; line-height:1.35; }
    .grid.lay-list .meme-card .lst-meta{ font-size:10px; margin-top:4px; }}

.rp-empty-state{
    grid-column:1/-1; text-align:center; padding:46px 20px 30px; color:var(--muted);
}
.rp-empty-state i{ font-size:2.3rem; display:block; margin-bottom:10px; color:var(--red); }
.rp-empty-state .t{ color:var(--text,#F0F2FF); font-weight:600; font-size:0.92rem; }

/* ══════════════════════════════════════
   PREMIUM SKELETON — scoped to ONLY the profile header area
══════════════════════════════════════ */
.dz-sk{
    position:relative; overflow:hidden;
    background:rgba(255,255,255,0.06);
    border-radius:6px;
}
.dz-sk::after{
    content:'';
    position:absolute; inset:0;
    background:linear-gradient(90deg, transparent, rgba(var(--fg,255,255,255),0.09), transparent);
    transform:translateX(-100%);
    animation:dzShimmer 1.5s infinite;
}
@keyframes dzShimmer{ 100%{ transform:translateX(100%); } }

.spinner-icon {
    display: inline-flex; align-items: center; justify-content: center;
    gap: 3px; height: 16px; vertical-align: middle;
}
.spinner-icon span {
    display: block; width: 3px; background: currentColor; border-radius: 2px;
    animation: btn-bar 1s ease-in-out infinite;
}
.spinner-icon span:nth-child(1) { animation-delay: 0s; }
.spinner-icon span:nth-child(2) { animation-delay: 0.15s; }
.spinner-icon span:nth-child(3) { animation-delay: 0.3s; }
.spinner-icon span:nth-child(4) { animation-delay: 0.45s; }
@keyframes btn-bar {
    0%,100% { height: 4px; opacity: 0.4; }
    50%      { height: 10px; opacity: 1; }
}

.mr-toast{
    position:fixed;bottom:calc(90px * var(--bs-s));left:50%;transform:translateX(-50%) translateY(10px);
    background:rgba(8,8,22,.97);border:1px solid rgba(255,45,45,.25);color:#fff;
    padding:calc(9px * var(--bs-s)) calc(24px * var(--bs-s));border-radius:calc(50px * var(--bs-s));font-size:calc(12px * var(--bs-s));
    font-family:'Poppins',sans-serif;z-index:9999999;pointer-events:none;
    box-shadow:0 8px 24px rgba(0,0,0,.6);white-space:nowrap;
    opacity:0;transition:opacity .22s ease,transform .22s ease;
}
.mr-toast.show{opacity:1;transform:translateX(-50%) translateY(0);}
.cm-row-flush{ padding-left:0; padding-right:0; border-top:none; }

.svsh-add-btn{
    width:calc(26px * var(--bs-s));height:calc(26px * var(--bs-s));flex-shrink:0;border-radius:50%;
    border:1.5px solid rgba(var(--fg,255,255,255),0.22);
    display:flex;align-items:center;justify-content:center;
    color:var(--text,#F0F2FF);font-size:calc(15px * var(--bs-s));
    transition:all .15s ease;
    -webkit-tap-highlight-color:transparent;
}
.svsh-add-btn.svsh-added{
    background:#f24455;border-color:#f24455;
}

/* 790px — TABLET */
@media (max-width:790px){
    .svsh-add-btn{ width:calc(25px * var(--bs-s));height:calc(25px * var(--bs-s));font-size:calc(14px * var(--bs-s)); }}

/* 490px — LARGE MOBILE */
@media (max-width:490px){
    .svsh-add-btn{ width:calc(24px * var(--bs-s));height:calc(24px * var(--bs-s));font-size:calc(13px * var(--bs-s)); }}

/* 390px — STANDARD MOBILE */
@media (max-width:390px){
    .svsh-add-btn{ width:calc(22px * var(--bs-s));height:calc(22px * var(--bs-s));font-size:calc(12px * var(--bs-s)); }}

/* 330px — SMALL / COMPACT MOBILE */
@media (max-width:330px){
    .svsh-add-btn{ width:calc(20px * var(--bs-s));height:calc(20px * var(--bs-s));font-size:calc(11px * var(--bs-s)); }}

/* ══════════════════════════════════════
   PROFILE PIC MODAL (Vibe Check / Share / Copy Link / QR / Track)
══════════════════════════════════════ */
.modal {
    display: none;
    position: fixed;
    z-index: 2147483647;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.985);
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 32px;
}
.modal[style*="flex"] {
    display: flex !important;
    align-items: center;
    justify-content: center;
}
.modal-content {
    display: block !important;
    border-radius: 50%;
    object-fit: cover;
    transition: transform 0.3s ease;
    width: min(60vw, 60vh) !important;
    height: min(60vw, 60vh) !important;
    max-width: 700px !important;
    max-height: 700px !important;
    margin: 0 !important;
    padding: 0 !important;
}
@media (max-width:768px){ .modal-content{ max-width:300px !important; max-height:300px !important; }}
.modal-actions{
    display:flex; align-items:center; justify-content:center; gap:calc(clamp(10px, 4vw, 20px) * var(--pv-s));
}

/* Track button inside the pic modal — identical to the solid red Track button. */
.modal-track-btn{
    display:inline-flex; align-items:center; justify-content:center;
    padding:calc(5px * var(--pv-s)) calc(clamp(60px, 12.5vw, 80px) * var(--pv-s)); min-width:calc(clamp(250px, 52vw, 320px) * var(--pv-s)); border-radius:calc(7px * var(--pv-s));
    font-size:calc(clamp(.84rem, .72rem + .5vw, .92rem) * var(--pv-s)); font-weight:700;
    border:1.5px solid var(--track-red); color:#000; background:var(--track-red);
    text-decoration:none; white-space:nowrap;
    margin-top:calc(clamp(14px, 2.9vw, 18px) * var(--pv-s));
    transition:background .2s ease, transform .15s ease;
}
.modal-track-btn:hover{ background:var(--track-red-hover); border-color:var(--track-red-hover); }
.modal-track-btn:active{ transform:scale(0.97); }
.modal-track-btn.pending{
    border-color:rgba(var(--fg,255,255,255),0.15); color:rgba(var(--fg,255,255,255),0.45); background:rgba(var(--fg,255,255,255),0.1);
    font-weight:600;
    cursor:pointer;
}
.modal-track-btn.pending:hover{ background:rgba(var(--fg,255,255,255),0.16); border-color:rgba(var(--fg,255,255,255),0.15); }
.modal-action-btn{
    display:flex; flex-direction:column; align-items:center; gap:calc(8px * var(--pv-s));
    text-decoration:none; cursor:pointer;
}
.modal-action-icon{
    width:calc(clamp(42px, 6.7vw + 18px, 56px) * var(--pv-s)); height:calc(clamp(42px, 6.7vw + 18px, 56px) * var(--pv-s)); border-radius:50%;
    background:rgba(30,30,30,0.95);
    display:flex; align-items:center; justify-content:center;
    transition:background 0.2s, transform 0.2s;
}
.modal-action-icon svg{ width:calc(clamp(18px, 1.5vw + 12px, 22px) * var(--pv-s)); height:calc(clamp(18px, 1.5vw + 12px, 22px) * var(--pv-s)); stroke:var(--red); }
.modal-action-btn span{
    font-size:calc(clamp(10px, .4vw + 8.5px, 11px) * var(--pv-s)); color:rgba(var(--fg,255,255,255),0.85); font-weight:500;
    white-space:nowrap; letter-spacing:0.2px;
}
.modal-action-btn:hover .modal-action-icon{ background:rgba(50,50,50,0.95); transform:translateY(-2px); }
/* Vibe Check beside the Track button: Track gets a little narrower so both fit on one row */
.close{
    position:fixed; top:20px; right:25px; width:36px; height:36px;
    border-radius:50%; background:transparent; border:transparent; color:var(--text,#F0F2FF);
    font-size:38px; font-weight:bold; cursor:pointer; transition:all 0.25s ease;
    z-index:1000001; display:flex; align-items:center; justify-content:center; line-height:1;
    backdrop-filter:blur(8px); -webkit-backdrop-filter:blur(8px);
}
.close:hover{ background:rgba(255,0,0,0.25); border-color:rgba(255,0,0,0.4); color:var(--red); transform:scale(1.08); }
.close:active{ transform:scale(0.93); }
@media (max-width:768px){ .close{ display:none; }}
.vibe-bd-row{ display:flex; align-items:center; gap:8px; margin-top:7px; font-size:11px; color:rgba(var(--fg,255,255,255),.75); }
.vibe-bd-name{ flex:0 0 auto; max-width:46%; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.vibe-bd-bar{ flex:1; height:5px; border-radius:99px; background:rgba(var(--fg,255,255,255),.1); overflow:hidden; }
.vibe-bd-bar i{ display:block; height:100%; border-radius:99px; background:#FF2D2D; }
.vibe-bd-n{ flex:0 0 auto; min-width:14px; text-align:right; color:rgba(var(--fg,255,255,255),.5); font-size:10.5px; }
.vibe-opt{
    padding:9px 6px; border-radius:4px; border:none;
    background:rgba(var(--fg,255,255,255),0.05); color:rgba(var(--fg,255,255,255),0.85); font-size:11.5px;
    cursor:pointer; transition:background .2s ease, transform .15s ease;
    text-align:center; width:100%; line-height:1.35;
}
.vibe-opt:hover{ background:rgba(var(--fg,255,255,255),0.1); color:var(--text,#F0F2FF); }
.vibe-opt:active{ transform:scale(.97); }
.vibe-opt.selected{ background:var(--red-dim); color:#fff; font-weight:600; box-shadow:inset 0 0 0 1.5px var(--red); }
.vibe-opt:disabled{ opacity:.5; pointer-events:none; }
.vibe-switch{
    position:relative; flex-shrink:0; width:44px; height:25px; border-radius:99px; border:none; padding:0;
    background:rgba(var(--fg,255,255,255),0.18); transition:background .22s ease;
}
.vibe-switch::after{
    content:''; position:absolute; top:3px; left:3px; width:19px; height:19px; border-radius:50%;
    background:#fff; transition:transform .22s ease;
}
.vibe-switch.on{ background:var(--red); }
.vibe-switch.on::after{ transform:translateX(19px); }
.vibe-switch:disabled{ opacity:.6; pointer-events:none; }

@media (max-width:790px){
    .vibe-opt{ padding:7px 4px; font-size:10.5px; border-radius:4px; }
    .vibe-switch{ width:40px; height:23px; }
    .vibe-switch::after{ width:17px; height:17px; }
    .vibe-switch.on::after{ transform:translateX(17px); }}

/* ══════════════════════════════════════════════════════════════
   SHELL + RIGHT-HAND COLUMN (laptop / normal desktop view only)
   Same idea as the pod page: the profile lane sits on the LEFT and a
   sticky, independently-scrolling column of cards sits on the RIGHT.
   Below 1100px the column doesn't exist and nothing changes.
   The column now GROWS to fill the free space (no empty filler).
   ══════════════════════════════════════════════════════════════ */
.pf-shell{ width:100%; display:flex; align-items:flex-start; justify-content:center; }
.pf-side{ display:none; }

@media (min-width:1100px){
    .pf-shell{ justify-content:flex-start; padding-left:22px; }
    .rp-page{ margin:0; flex:0 0 var(--max-w); width:var(--max-w); }
    .pf-side{
        display:block; flex:1 1 0; min-width:0; max-width:720px;
        position:sticky; top:0; height:100vh; overflow-y:auto; overflow-x:hidden;
        padding:14px 26px 40px 22px;
        scrollbar-width:thin; scrollbar-color:rgba(255,255,255,.22) transparent;
    }
    .pf-side::-webkit-scrollbar{ width:5px; }
    .pf-side::-webkit-scrollbar-track{ background:transparent; }
    .pf-side::-webkit-scrollbar-thumb{ background:rgba(255,255,255,.22); border-radius:99px; }
    .pf-side::-webkit-scrollbar-thumb:hover{ background:rgba(255,255,255,.34); }}


/* card order — change the numbers to re-order the column.
   Owner view: Top memes → Vibe → Insights → New trackers → … → Top post */
.pf-cards{ display:flex; flex-direction:column; }
.pf-sk-bar{ height:var(--rp-action-h); border-radius:var(--rp-action-radius); margin-bottom:0; }
.pf-sk-grid{ display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:4px; margin:8px 0 16px; }
.pf-sk-grid .dz-sk{ aspect-ratio:1; border-radius:4px; }
.pf-cards.pf-loaded{ animation:pfFade .3s ease both; }
@keyframes pfFade{ from{ opacity:0; transform:translateY(6px); } to{ opacity:1; transform:none; } }
.pf-card{ margin-bottom:14px; }
.pf-card[data-k="topmemes"]   { order:1; }
.pf-card[data-k="vibe"]       { order:2; }
.pf-card[data-k="insights"]   { order:3; }
.pf-card[data-k="newtrackers"]{ order:4; }
.pf-card[data-k="otd"]        { order:5; }
.pf-card[data-k="topcontrib"] { order:6; }
.pf-card[data-k="hashtags"]   { order:7; }
.pf-card[data-k="people"]     { order:8; }
.pf-card[data-k="toppost"]    { order:9; }
.pf-card[data-k="pending"]    { order:10; }
.pf-card[data-k="checklist"]  { order:11; }
.pf-card[data-k="locked"]     { order:1; }

/* the bubble bar — looks exactly like the action buttons: grey, no border */
.pf-bar{
    display:flex; align-items:center; gap:7px; width:100%;
    height:var(--rp-action-h); padding:0 12px;
    border:none; border-radius:var(--rp-action-radius);
    background:rgba(var(--fg,255,255,255),0.1);
    backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    color:var(--text,#F0F2FF); font-size:.72rem; font-weight:600; line-height:1; text-align:left;
    transition:background .2s ease, transform .15s ease;
}
.pf-bar:hover{ background:rgba(var(--fg,255,255,255),0.2); }
.pf-bar:active{ transform:scale(.99); }
.pf-bar-ic{ font-size:14px; color:var(--red); flex-shrink:0; }
.pf-bar-t{ flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.pf-bar-c{ font-size:.64rem; font-weight:600; color:rgba(var(--fg,255,255,255),.55); flex-shrink:0; }
.pf-bar-chev{ font-size:18px; color:rgba(var(--fg,255,255,255),.75); flex-shrink:0; transition:transform .25s ease; }
.pf-card.collapsed .pf-bar-chev{ transform:rotate(-90deg); }

.pf-body{ display:grid; grid-template-rows:1fr; transition:grid-template-rows .3s ease; }
.pf-inner{ min-height:0; overflow:hidden; }
.pf-pad{ padding-top:8px; }
.pf-card.collapsed .pf-body{ grid-template-rows:0fr; }

/* "See more / See less" + link bars — same grey bubble */
.pf-more{
    display:flex; align-items:center; justify-content:center; width:100%;
    height:var(--rp-action-h); margin-top:8px; padding:0 12px;
    border:none; border-radius:var(--rp-action-radius);
    background:rgba(var(--fg,255,255,255),0.1);
    backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    color:var(--text,#F0F2FF); font-size:.7rem; font-weight:600; text-decoration:none; line-height:1;
    transition:background .2s ease, transform .15s ease;
}
.pf-more:hover{ background:rgba(var(--fg,255,255,255),0.2); }
.pf-more:active{ transform:scale(.98); }
.pf-more.red{ color:#000; background:var(--red); font-weight:700; }
.pf-more.red:hover{ background:#ff1a1a; }

/* square tiles (same look as the pod page's right grid) */
.pf-grid{ display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:4px; }
.pf-grid.one{ grid-template-columns:minmax(0,1fr); }
.pf-tile{
    position:relative; display:block; aspect-ratio:1; overflow:hidden; padding:0; border:none;
    border-radius:4px; background:var(--surface-2,#0d1030); text-align:left; width:100%;
}
.pf-grid.one .pf-tile{ aspect-ratio:1.15; }
.pf-tile img, .pf-tile video{
    position:absolute; inset:0; width:100%; height:100%; object-fit:cover; display:block;
    pointer-events:none; transition:transform .3s ease;
}
.pf-tile:hover img, .pf-tile:hover video{ transform:scale(1.06); }
/* text-only post → drawn on uploads/Text.jpg with the caption over it (same as the profile grid) */
.pf-tile-text{
    position:absolute; inset:0; padding:10px; display:flex; align-items:center; justify-content:center; text-align:center;
    font-size:.66rem; font-weight:500; line-height:1.45; color:var(--text,#F0F2FF); word-break:break-word; overflow:hidden;
    background:var(--surface-2,#0d1030) url('uploads/Text.jpg') center / cover no-repeat;
}
.pf-tile-text::before{
    content:''; position:absolute; inset:0; background:rgba(0,0,0,0.45); pointer-events:none;
}
.pf-tile-text span{
    position:relative; z-index:1;
    display:-webkit-box; -webkit-line-clamp:6; -webkit-box-orient:vertical; overflow:hidden;
    text-shadow:0 1px 4px rgba(0,0,0,0.6);
}
.pf-tile-vid{
    position:absolute; top:6px; right:6px; z-index:2; width:20px; height:20px; border-radius:6px;
    background:rgba(0,0,0,.55); color:#fff; display:flex; align-items:center; justify-content:center; font-size:14px; pointer-events:none;
}
.pf-rank{
    position:absolute; top:6px; left:6px; z-index:2; padding:2px 7px; border-radius:6px;
    background:rgba(0,0,0,.6); color:#fff; font-size:.6rem; font-weight:700; letter-spacing:.2px; pointer-events:none;
}
.pf-tile-ov{
    position:absolute; inset:0; z-index:1; display:flex; align-items:center; justify-content:center; gap:12px;
    background:rgba(2,3,20,.55); color:#fff; font-size:.68rem; font-weight:700;
    opacity:0; transition:opacity .2s ease; pointer-events:none;
}
.pf-tile:hover .pf-tile-ov{ opacity:1; }
.pf-tile-ov span{ display:inline-flex; align-items:center; gap:4px; }
.pf-tile-ov i{ font-size:.85rem; }

/* people grid (Mutuals / Circle) */
.pf-utile{
    position:relative; display:block; aspect-ratio:1; overflow:hidden; border-radius:4px; background:var(--surface-2,#0d1030); text-decoration:none;
}
.pf-utile img{ position:absolute; inset:0; width:100%; height:100%; object-fit:cover; display:block; transition:transform .3s ease; }
.pf-utile:hover img{ transform:scale(1.06); }
.pf-utile span{
    position:absolute; left:0; right:0; bottom:0; padding:16px 6px 5px; color:#fff; font-size:.62rem; font-weight:600;
    background:linear-gradient(180deg,transparent,rgba(0,0,0,.8)); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; text-align:center;
}

/* list rows (pods, visitors, trackers, requests) */
.pf-row{
    display:flex; align-items:center; gap:10px; padding:8px 2px; color:var(--text,#F0F2FF); text-decoration:none;
    border-bottom:1px solid rgba(var(--fg,255,255,255),.06);
}
.pf-row:last-of-type{ border-bottom:none; }
a.pf-row:hover .pf-row-t{ text-decoration:underline; }
.pf-hold{ display:none; }
.pf-in{ animation:pfRowIn .3s cubic-bezier(.22,1,.36,1) both; }
@keyframes pfRowIn{ from{ opacity:0; transform:translateY(6px); } to{ opacity:1; transform:none; } }
.pf-av{ width:34px; height:34px; border-radius:50%; object-fit:cover; border:1.5px solid var(--red); flex-shrink:0; }
.pf-av.sq{ border-radius:8px; }
.pf-row-info{ flex:1; min-width:0; }
.pf-row-t{ font-size:.76rem; font-weight:600; color:var(--text,#F0F2FF); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.pf-row-s{ font-size:.64rem; color:rgba(var(--fg,255,255,255),.5); margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.pf-row-end{ font-size:.9rem; color:var(--red); flex-shrink:0; }

/* chips (hashtags, runner-up vibes) */
.pf-chips{ display:flex; flex-wrap:wrap; gap:6px; }
.pf-chip{
    display:inline-flex; align-items:center; gap:5px; padding:5px 10px; border-radius:var(--rp-action-radius);
    background:rgba(var(--fg,255,255,255),.07); color:var(--text,#F0F2FF); font-size:.68rem; font-weight:600; text-decoration:none; line-height:1;
    transition:background .2s ease;
}
a.pf-chip:hover{ background:rgba(var(--fg,255,255,255),.16); }
.pf-chip b{ color:rgba(var(--fg,255,255,255),.55); font-weight:600; font-size:.62rem; }

/* vibe */
.pf-vibe{ text-align:center; padding:6px 4px 2px; }
.pf-vibe-lbl{ font-size:.6rem; letter-spacing:1px; text-transform:uppercase; color:rgba(var(--fg,255,255,255),.45); margin-bottom:6px; }
.pf-vibe-top{ font-size:1.05rem; font-weight:700; color:var(--text,#F0F2FF); line-height:1.3; word-break:break-word; }
.pf-vibe-sub{ font-size:.66rem; color:rgba(var(--fg,255,255,255),.45); margin-top:5px; }
.pf-vibe .pf-chips{ justify-content:center; margin-top:10px; }
.pf-note{ font-size:.68rem; color:rgba(var(--fg,255,255,255),.5); line-height:1.5; text-align:center; padding:4px 6px; }

.pf-vibe-toggle{
    display:flex; align-items:center; justify-content:space-between; gap:12px;
    margin-top:10px; padding:8px 12px; border-radius:var(--rp-action-radius);
    background:rgba(var(--fg,255,255,255),.05);
}
.pf-vibe-toggle-t{ font-size:.7rem; font-weight:600; color:var(--text,#F0F2FF); }

/* insights big number */
.pf-stat{ display:flex; align-items:baseline; gap:8px; padding:2px 2px 8px; }
.pf-stat b{ font-size:1.5rem; font-weight:700; color:var(--text,#F0F2FF); line-height:1; }
.pf-stat span{ font-size:.68rem; color:rgba(var(--fg,255,255,255),.55); }

/* top-performing post stats */
.pf-post-stats{ display:flex; align-items:center; gap:14px; padding:8px 2px 0; font-size:.7rem; font-weight:600; color:var(--text,#F0F2FF); }
.pf-post-stats span{ display:inline-flex; align-items:center; gap:5px; }
.pf-post-stats i{ color:var(--red); font-size:.9rem; }

/* checklist */
.pf-prog{ height:5px; border-radius:99px; background:rgba(var(--fg,255,255,255),.1); overflow:hidden; margin:2px 2px 8px; }
.pf-prog i{ display:block; height:100%; border-radius:99px; background:var(--red); transition:width .4s ease; }
.pf-check{
    display:flex; align-items:center; gap:10px; padding:8px 2px; text-decoration:none; color:var(--text,#F0F2FF);
    border-bottom:1px solid rgba(var(--fg,255,255,255),.06);
}
.pf-check:last-child{ border-bottom:none; }
.pf-check .ck{ font-size:1.05rem; color:rgba(var(--fg,255,255,255),.3); flex-shrink:0; }
.pf-check.done .ck{ color:#4ade80; }
.pf-check.done .tx{ color:rgba(var(--fg,255,255,255),.45); text-decoration:line-through; }
.pf-check .tx{ flex:1; font-size:.74rem; font-weight:500; }
.pf-check .go{ font-size:1rem; color:rgba(var(--fg,255,255,255),.4); }
a.pf-check:hover .go{ color:var(--text,#F0F2FF); }

/* ══════════════════════════════════════════════════════════════════════════
   PROFILE CONTENT — tabs, sort bar, Collections, Messages, empty states
   (all CSS for the grid / messages area lives here — none is inline)
══════════════════════════════════════════════════════════════════════════ */

/* ══════════════════════════════════════════════════════════
   TAB PAGER — the tab panels sit side by side and follow your finger,
   exactly like the Tracks page. The pager (not CSS display rules on the
   panels) decides which panel is visible, so the old
   `.dropdown-section{display:none}` rules are gone.
   Keep these properties exactly as they are:
   • display:none on idle, inactive panels → correct page height, hidden
     videos never play, hidden images never load.
   • NO will-change / transform while idle → a transform on the track would
     break position:fixed elements inside a panel (collection menus etc).
   • overflow-x:clip, not hidden → hidden would make the pager a scroll
     container and break sticky behaviour.
══════════════════════════════════════════════════════════ */
.rp-pager{ width:100%; position:relative; touch-action:pan-y; }
@supports (overflow:clip){ .rp-pager{ overflow-x:clip; }}
@supports not (overflow:clip){ .rp-pager{ overflow:hidden; }}
.rp-pager.dragging{ user-select:none; -webkit-user-select:none; cursor:grabbing; }
.rp-pager img{ -webkit-user-drag:none; }
.rp-track{ display:flex; align-items:flex-start; width:100%; }
.rp-pager:not(.idle) .rp-track{ will-change:transform; backface-visibility:hidden; }
.rp-track > .dropdown-section{ display:block !important; flex:0 0 100%; width:100%; min-width:0; }
.rp-pager.idle .rp-track > .dropdown-section:not(.rp-active){ display:none !important; }

.meme-card .caption i{ color:#FF4C4C; }

/* ══════════════════════════════════════════════════════════
   SORT + COLLECTIONS BAR  (Newest | Popular | Oldest | Collections)
   and the Messages filter bar (All | Unread | Dry Chat | Requests)
   ONE set of rules for BOTH bars so size, weight and height always match.
══════════════════════════════════════════════════════════ */
.grid-sort-bar,
.msg-filter-bar{
    --tab-btn-h: calc(clamp(22px, 6vw, 25.5px) * var(--sb-s));
    --tab-btn-radius: 4px;
    --tab-btn-bg: rgba(var(--fg,255,255,255),0.075);
    --tab-btn-bg-hover: rgba(var(--fg,255,255,255),0.15);
    display:flex; align-items:center; justify-content:flex-start;
    flex-wrap:nowrap; gap:calc(clamp(6px, 1.6vw, 8px) * var(--sb-s));
    width:100%; max-width:var(--max-w); margin:0 auto;
    padding:calc(10px * var(--sb-s)) var(--rp-header-pad-x);
    border:none;
    overflow-x:auto; scrollbar-width:none; -ms-overflow-style:none;
    -webkit-overflow-scrolling:touch;
}
.grid-sort-bar::-webkit-scrollbar,
.msg-filter-bar::-webkit-scrollbar{ display:none; }

.g-pill,
.msg-pill{
    flex:0 0 auto; width:auto; min-width:0;
    position:relative;
    display:inline-flex; align-items:center; justify-content:center; gap:calc(clamp(3px, 1.2vw, 6px) * var(--sb-s));
    height:var(--tab-btn-h); padding:0 calc(clamp(9px, 2.5vw + 1px, 16px) * var(--sb-s)); line-height:1;
    border:none; border-radius:var(--tab-btn-radius);
    background:var(--tab-btn-bg);
    backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    box-shadow:none;
    color:var(--text,#F0F2FF); font-family:Poppins,sans-serif;
    font-size:calc(clamp(.57rem, .35rem + 1.1vw, .72rem) * var(--sb-s)); font-weight:600;
    white-space:nowrap; cursor:pointer;
    transition:background .2s ease, color .2s ease, transform .15s ease;
    -webkit-tap-highlight-color:transparent;
}
.g-pill *:not(i),
.msg-pill *:not(i){
    font-family:inherit !important;
    font-size:inherit !important;
    font-weight:inherit !important;
    line-height:1;
}
.g-pill i,
.msg-pill i{ font-size:calc(clamp(11px, 1.6vw + 5px, 14px) * var(--sb-s)) !important; margin-right:0 !important; }

.g-pill:hover,
.msg-pill:hover{ background:var(--tab-btn-bg-hover); color:var(--text,#F0F2FF); border:none; }
.g-pill:active,
.msg-pill:active{ transform:scale(0.98); }

.g-pill.active,
.msg-pill.active,
.g-pill.active:hover,
.msg-pill.active:hover{
    background:var(--tab-btn-bg);
    color:#FF2D2D;
    font-weight:800;
    border:none; box-shadow:none;
}

.g-pill .g-pill-count,
.msg-pill .req-badge,
.g-pill.active .g-pill-count,
.msg-pill.active .req-badge{
    display:inline-block; background:none; border-radius:0; padding:0; min-width:0; height:auto;
    color:#fff !important; margin-left:2px; line-height:1; letter-spacing:0;
}
.g-pill-count:empty{ display:none; }


/* ══════════════════════════════════════════════════════════
   EMPTY STATES — same design as the Untracked page.
   Used by every empty grid (different words for the profile owner and for a
   visitor), by the empty inbox, AND by the private-profile / blocked screens.
══════════════════════════════════════════════════════════ */
.es-wrap{
    --es-s:1.1;   /* empty states are 10% bigger */
    grid-column:1/-1; width:100%; min-height:46vh;
    padding:calc(clamp(28px, 8vw, 48px) * var(--es-s)) calc(clamp(20px, 7vw, 36px) * var(--es-s)) calc(clamp(36px, 10vw, 60px) * var(--es-s));
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    text-align:center;
}
.es-title{ font-size:calc(clamp(.95rem, 3.4vw, 1.1rem) * var(--es-s, 1.1)); font-weight:700; color:var(--text,#F0F2FF); letter-spacing:-.2px; }
.es-text{
    margin-top:calc(6px * var(--es-s, 1.1)); max-width:calc(340px * var(--es-s, 1.1));
    font-size:calc(clamp(.66rem, 2.1vw, .76rem) * var(--es-s, 1.1)); color:var(--muted); line-height:1.7;
}
.es-tips{
    width:100%; max-width:calc(360px * var(--es-s, 1.1));
    margin-top:calc(clamp(16px, 5vw, 24px) * var(--es-s, 1.1));
    display:flex; flex-direction:column; gap:calc(7px * var(--es-s, 1.1));
}
.es-tip{
    display:flex; align-items:center; gap:calc(10px * var(--es-s, 1.1));
    padding:calc(10px * var(--es-s, 1.1)) calc(12px * var(--es-s, 1.1)); border-radius:var(--rp-action-radius);
    background:rgba(var(--fg,255,255,255),0.05);
    text-align:left;
}
.es-tip i{ font-size:calc(1rem * var(--es-s, 1.1)); color:var(--red); flex-shrink:0; }
.es-tip span{ font-size:calc(clamp(.62rem, 2vw, .7rem) * var(--es-s, 1.1)); color:rgba(var(--fg,255,255,255),0.62); line-height:1.5; }
.es-btn{
    margin-top:calc(clamp(16px, 5vw, 22px) * var(--es-s, 1.1));
    display:flex; align-items:center; justify-content:center;
    height:calc(var(--rp-action-h) * var(--es-s, 1.1)); min-width:calc(150px * var(--es-s, 1.1)); padding:0 calc(22px * var(--es-s, 1.1));
    border-radius:var(--rp-action-radius);
    background:rgba(var(--fg,255,255,255),0.1); color:var(--text,#F0F2FF);
    font-size:calc(clamp(.62rem, .5rem + .5vw, .72rem) * var(--es-s, 1.1)); font-weight:600; text-decoration:none;
    transition:background .2s ease, transform .15s ease;
}
.es-btn:hover{ background:rgba(var(--fg,255,255,255),0.2); }
.es-btn:active{ transform:scale(0.98); }

/* ── buttons inside the empty / private / blocked states (boxy, same as the action buttons) ── */
button.es-btn{ border:none; font-family:'Poppins',sans-serif; cursor:pointer; }
.es-btn.track{ background:var(--track-red); color:#000; font-weight:700; }
.es-btn.track:hover{ background:var(--track-red-hover); }
.es-form{ margin:0; display:flex; justify-content:center; }

/* ── Messages empty states: .msg-empty-wrap wraps a normal .es-wrap.
   The JS toggles display inline and only one shows at a time. ── */
.msg-empty-wrap{ width:100%; }
.msg-empty-wrap .es-wrap{ min-height:38vh; }   /* shorter than the grid tabs because the filter bar sits above it */
/* .msg-list has its own side padding, .es-wrap already has some — cancel the doubling */
.msg-list > .msg-empty-wrap{ margin:0 calc(var(--rp-header-pad-x) * -1); width:calc(100% + var(--rp-header-pad-x) * 2); }
.msg-list > .es-wrap{ margin:0 calc(var(--rp-header-pad-x) * -1); width:calc(100% + var(--rp-header-pad-x) * 2); }

.col-section{ display:none; padding-top:calc(4px * var(--cm-s)); }

.col-search-wrap{ position:relative; margin:calc(6px * var(--cm-s)) var(--rp-header-pad-x) calc(12px * var(--cm-s)); }
.col-search-input{
    width:100%; background:rgba(var(--fg,255,255,255),0.06); border:none;
    border-radius:calc(4px * var(--cm-s)); padding:calc(clamp(6px, 1.5vw, 10px) * var(--cm-s)) calc(clamp(26px, 1.7vw + 20px, 34px) * var(--cm-s)) calc(clamp(6px, 1.5vw, 10px) * var(--cm-s)) calc(clamp(10px, 1vw + 7px, 14px) * var(--cm-s)); color:var(--text,#F0F2FF); font-size:calc(clamp(10px, 8px + .5vw, 12px) * var(--cm-s));
    font-family:Poppins,sans-serif; outline:none; transition:background .2s; box-sizing:border-box;
}
.col-search-input:focus{ background:rgba(var(--fg,255,255,255),0.1); }
.col-search-input::placeholder{ color:rgba(var(--fg,255,255,255),0.25); }
.col-search-icon{ display:none !important; }
.col-search-clear{ position:absolute; right:calc(12px * var(--cm-s)); top:50%; transform:translateY(-50%); font-size:calc(13px * var(--cm-s)); color:rgba(var(--fg,255,255,255),0.3); cursor:pointer; display:none; background:none; border:none; padding:0; line-height:1; }
.col-search-clear:hover{ color:rgba(var(--fg,255,255,255),0.7); }

.col-folders-grid{
    display:grid; grid-template-columns:repeat(auto-fill,minmax(clamp(140px, 28vw, 165px),1fr)); gap:calc(clamp(8px, 2vw, 14px) * var(--cm-s));
    padding:calc(18px * var(--cm-s)) var(--rp-header-pad-x) calc(20px * var(--cm-s)); row-gap:calc(clamp(24px, 6vw, 30px) * var(--cm-s));
}
.col-folders-grid > .es-wrap, .col-detail .grid > .es-wrap{ grid-column:1/-1; }
.col-folder-card{ border-radius:calc(8px * var(--cm-s)); background:rgba(var(--fg,255,255,255),0.04); border:1px solid rgba(var(--fg,255,255,255),0.08); overflow:hidden; cursor:pointer; transition:all .25s; position:relative;
    /* two more grey "cards" peeking out from behind the top edge, so every collection looks like a small stack */
    box-shadow:0 -8px 0 -3px #2b2c3f, 0 -15px 0 -6px #1d1e30; }
.col-folder-card:hover{ border-color:rgba(255,45,45,0.35); transform:translateY(-2px); box-shadow:0 -8px 0 -3px #33344a, 0 -15px 0 -6px #222338, 0 8px 22px rgba(0,0,0,0.3); }
.col-folder-card.is-public{ border-color:rgba(255,85,102,0.2); }
.col-folder-card.is-pinned-col{ border-color:rgba(255,45,45,0.5); box-shadow:0 -8px 0 -3px #2b2c3f, 0 -15px 0 -6px #1d1e30, 0 0 18px rgba(255,45,45,0.12); }
.col-folder-preview{ width:100%; aspect-ratio:1; display:grid; grid-template-columns:1fr 1fr; grid-template-rows:1fr 1fr; gap:2px; background:rgba(var(--fg,255,255,255),0.03); overflow:hidden; }
.col-fp-item{ background:rgba(var(--fg,255,255,255),0.06); overflow:hidden; display:flex; align-items:center; justify-content:center; }
.col-fp-item img,.col-fp-item video{ width:100%; height:100%; object-fit:cover; display:block; }
.col-fp-empty{ background:rgba(var(--fg,255,255,255),0.04); display:flex; align-items:center; justify-content:center; }
.col-fp-empty i{ font-size:calc(14px * var(--cm-s)); color:rgba(var(--fg,255,255,255),0.08); }
.col-folder-info{ padding:calc(10px * var(--cm-s)) calc(12px * var(--cm-s)); }
.col-folder-name{ font-size:calc(clamp(12px, 1vw + 7.5px, 13px) * var(--cm-s)); font-weight:600; color:var(--text,#F0F2FF); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-bottom:calc(3px * var(--cm-s)); }
.col-folder-count{ font-size:calc(11px * var(--cm-s)); color:rgba(var(--fg,255,255,255),0.4); margin-bottom:calc(5px * var(--cm-s)); }
.col-folder-timeline{ display:flex; flex-direction:column; gap:2px; border-top:1px solid rgba(var(--fg,255,255,255),0.06); padding-top:calc(6px * var(--cm-s)); margin-top:calc(4px * var(--cm-s)); }
.col-folder-tl-row{ font-size:calc(9.5px * var(--cm-s)); color:rgba(var(--fg,255,255,255),0.28); display:flex; align-items:center; gap:calc(4px * var(--cm-s)); letter-spacing:.1px; }
.col-folder-tl-row i{ font-size:calc(10px * var(--cm-s)); flex-shrink:0; color:rgba(var(--fg,255,255,255),0.2); }
.col-folder-tl-row span{ white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.col-folder-vis{ font-size:calc(10px * var(--cm-s)); font-weight:600; margin-top:calc(5px * var(--cm-s)); display:flex; align-items:center; gap:calc(3px * var(--cm-s)); letter-spacing:.3px; }
.col-folder-vis.pub{ color:#4dde8a; }
.col-folder-vis.priv{ color:rgba(var(--fg,255,255,255),0.3); }
.col-folder-opts{ position:absolute; top:calc(8px * var(--cm-s)); right:calc(8px * var(--cm-s)); width:calc(28px * var(--cm-s)); height:calc(28px * var(--cm-s)); border-radius:50%; background:rgba(0,0,0,0.6); backdrop-filter:blur(8px); border:none; color:#fff; font-size:calc(14px * var(--cm-s)); display:flex; align-items:center; justify-content:center; cursor:pointer; opacity:0; transition:opacity .2s; z-index:5; -webkit-tap-highlight-color:transparent; }
.col-folder-card:hover .col-folder-opts{ opacity:1; }
@media(max-width:768px){ .col-folder-opts{ opacity:1; }}
.col-new-btn{ border-radius:calc(6px * var(--cm-s)); background:rgba(255,45,45,0.07); border:1.5px dashed rgba(255,45,45,0.3); cursor:pointer; transition:all .25s; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:calc(8px * var(--cm-s)); min-height:calc(clamp(120px, 32vw, 150px) * var(--cm-s)); color:rgba(255,85,102,0.7); font-family:Poppins,sans-serif; font-size:calc(clamp(11px, 2vw + 2px, 12px) * var(--cm-s)); font-weight:600; -webkit-tap-highlight-color:transparent; width:100%; order:-1; }
.col-new-btn:hover{ border-color:rgba(255,45,45,0.6); color:rgba(255,85,102,1); background:rgba(255,45,45,0.12); }
.col-new-btn i{ font-size:calc(26px * var(--cm-s)); }

.col-public-badge{ position:absolute; top:calc(8px * var(--cm-s)); left:calc(8px * var(--cm-s)); background:rgba(0,0,0,0.65); backdrop-filter:blur(8px); border:1px solid rgba(77,222,138,0.45); border-radius:calc(20px * var(--cm-s)); padding:calc(3px * var(--cm-s)) calc(8px * var(--cm-s)); font-size:calc(9px * var(--cm-s)); font-weight:700; color:#4dde8a; letter-spacing:.5px; text-transform:uppercase; z-index:5; pointer-events:none; }
.col-pin-badge{ position:absolute; top:calc(8px * var(--cm-s)); left:calc(8px * var(--cm-s)); background:rgba(0,0,0,0.65); backdrop-filter:blur(8px); border:1px solid rgba(255,45,45,0.5); border-radius:calc(20px * var(--cm-s)); padding:calc(3px * var(--cm-s)) calc(8px * var(--cm-s)); font-size:calc(9px * var(--cm-s)); font-weight:700; color:#ff5566; letter-spacing:.5px; z-index:5; pointer-events:none; }
.col-pin-badge.with-pub{ top:calc(30px * var(--cm-s)); }

/* ══ COLLECTION DETAIL ══ */
.col-detail{ display:none; }
.col-detail-hdr{ display:flex; align-items:center; gap:calc(12px * var(--cm-s)); padding:calc(6px * var(--cm-s)) var(--rp-header-pad-x) calc(12px * var(--cm-s)); }
.col-back-btn{ width:calc(clamp(22px, 6vw, 30px) * var(--cm-s)); height:calc(clamp(22px, 6vw, 30px) * var(--cm-s)); border-radius:calc(6px * var(--cm-s)); background:rgba(var(--fg,255,255,255),0.06); border:1px solid rgba(var(--fg,255,255,255),0.1); color:var(--text,#F0F2FF); font-size:calc(clamp(11px, 1.8vw + 4px, 14px) * var(--cm-s)); display:flex; align-items:center; justify-content:center; cursor:pointer; flex-shrink:0; transition:all .2s; -webkit-tap-highlight-color:transparent; }
.col-back-btn:hover{ background:rgba(255,45,45,0.15); border-color:rgba(255,45,45,0.4); }
.col-detail-title{ font-size:calc(clamp(11.5px, 1vw + 7px, 12.5px) * var(--cm-s)); font-weight:600; color:var(--text,#F0F2FF); flex:1; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.col-detail-count{ font-size:calc(12px * var(--cm-s)); color:rgba(var(--fg,255,255,255),0.4); flex-shrink:0; }

.col-detail-search-wrap{ position:relative; margin:calc(6px * var(--cm-s)) var(--rp-header-pad-x) calc(12px * var(--cm-s)); }
.col-detail-search-input{
    width:100%; background:rgba(var(--fg,255,255,255),0.06); border:none;
    border-radius:calc(4px * var(--cm-s)); padding:calc(clamp(6px, 1.5vw, 10px) * var(--cm-s)) calc(clamp(26px, 1.7vw + 20px, 34px) * var(--cm-s)) calc(clamp(6px, 1.5vw, 10px) * var(--cm-s)) calc(clamp(10px, 1vw + 7px, 14px) * var(--cm-s)); color:var(--text,#F0F2FF); font-size:calc(clamp(10px, 8px + .5vw, 12px) * var(--cm-s));
    font-family:Poppins,sans-serif; outline:none; transition:background .2s; box-sizing:border-box;
}
.col-detail-search-input:focus{ background:rgba(var(--fg,255,255,255),0.1); }
.col-detail-search-input::placeholder{ color:rgba(var(--fg,255,255,255),0.25); }
.col-detail-search-icon{ display:none !important; }
.col-detail-search-clear{ position:absolute; right:calc(12px * var(--cm-s)); top:50%; transform:translateY(-50%); font-size:calc(13px * var(--cm-s)); color:rgba(var(--fg,255,255,255),0.3); cursor:pointer; display:none; background:none; border:none; padding:0; line-height:1; }
.col-detail-search-clear:hover{ color:rgba(var(--fg,255,255,255),0.7); }
.col-detail-no-results{ grid-column:1/-1; text-align:center; padding:calc(30px * var(--cm-s)) calc(20px * var(--cm-s)); color:rgba(var(--fg,255,255,255),0.3); font-size:calc(13px * var(--cm-s)); display:none; }
.col-detail-no-results i{ font-size:calc(2rem * var(--cm-s)); display:block; margin-bottom:calc(8px * var(--cm-s)); color:rgba(255,85,102,0.35); }

/* ══ BUTTONS ON A CARD ══ */
.card-add-col-btn{ position:absolute; bottom:8px; right:8px; width:32px; height:32px; border-radius:50%; background:rgba(0,0,0,0.65); backdrop-filter:blur(8px); border:0; color:#fff; font-size:15px; display:flex; align-items:center; justify-content:center; cursor:pointer; z-index:5; padding:0; transition:all .2s; -webkit-tap-highlight-color:transparent; }
.card-add-col-btn:hover{ background:rgba(255,45,45,0.75); transform:scale(1.1); }
.card-add-col-btn .cab-plus{ position:absolute; top:0; right:0; bottom:0; left:0; width:50%; height:50%; margin:auto; display:block; }
.card-add-col-btn{ box-sizing:border-box; line-height:0; overflow:hidden; }
.card-pin-btn{ background:none; border:none; color:rgba(var(--fg,255,255,255),0.4); font-size:16px; display:flex; align-items:center; justify-content:center; cursor:pointer; padding:0 2px; transition:color .2s; -webkit-tap-highlight-color:transparent; line-height:1; }
.card-pin-btn:hover{ color:rgba(255,85,102,0.85); }
.meme-card.dz-lp{ -webkit-touch-callout:none; -webkit-user-select:none; user-select:none; }
.meme-card.dz-lp-arm{ transform:scale(.97); transition:transform .25s linear; }
.card-pin-btn.pinned{ color:#ff2d2d; }
.card-pin-indicator{ position:absolute; top:10px; right:50px; font-size:14px; color:#ff2d2d; pointer-events:none; z-index:6; filter:drop-shadow(0 1px 3px rgba(0,0,0,0.7)); }
.card-remove-btn{ position:absolute; bottom:8px; right:8px; width:32px; height:32px; border-radius:50%; background:rgba(180,0,0,0.75); backdrop-filter:blur(8px); border:none; color:var(--text,#F0F2FF); font-size:15px; display:flex; align-items:center; justify-content:center; cursor:pointer; z-index:5; transition:all .2s; -webkit-tap-highlight-color:transparent; }
.card-remove-btn:hover{ background:rgba(255,0,0,0.95); transform:scale(1.1); }

/* ══ TOAST + small states ══ */
.g-toast{ position:fixed; bottom:90px; left:50%; transform:translateX(-50%); background:rgba(14,14,30,0.97); border:1px solid rgba(255,45,45,0.3); color:#fff; padding:9px 24px; border-radius:50px; font-size:12px; font-family:Poppins,sans-serif; z-index:99999999; pointer-events:none; box-shadow:0 8px 24px rgba(0,0,0,0.6); white-space:nowrap; }
.g-toast-in{ animation:toastIn .25s ease forwards; }
@keyframes toastIn{ from{ opacity:0; transform:translateX(-50%) translateY(8px); } to{ opacity:1; transform:translateX(-50%) translateY(0); } }
.col-visitor-empty{ grid-column:1/-1; text-align:center; padding:calc(40px * var(--cm-s)) calc(20px * var(--cm-s)); color:rgba(var(--fg,255,255,255),0.3); font-size:calc(13px * var(--cm-s)); }
.col-visitor-empty i{ font-size:calc(2.5rem * var(--cm-s)); display:block; margin-bottom:calc(10px * var(--cm-s)); color:rgba(255,85,102,0.4); }
.col-search-no-results{ grid-column:1/-1; text-align:center; padding:calc(30px * var(--cm-s)) calc(20px * var(--cm-s)); color:rgba(var(--fg,255,255,255),0.3); font-size:calc(13px * var(--cm-s)); display:none; }
.col-search-no-results i{ font-size:calc(1.8rem * var(--cm-s)); display:block; margin-bottom:calc(8px * var(--cm-s)); color:rgba(255,85,102,0.3); }
@keyframes spin{ to{ transform:rotate(360deg); } }

/* ══ COLLECTIONS RESPONSIVE ══ */
/* ══════════════════════════════════════════════════════════
   MESSAGES TAB
   Radius tokens for the whole tab: 4px for small controls, 6px for cards / rows.
══════════════════════════════════════════════════════════ */
.msg-search-wrap{ position:relative; margin:calc(6px * var(--cm-s)) var(--rp-header-pad-x) calc(12px * var(--cm-s)); }
.msg-search-input{
    width:100%; background:rgba(var(--fg,255,255,255),0.06); border:none;
    border-radius:calc(4px * var(--cm-s)); padding:calc(clamp(6px, 1.5vw, 10px) * var(--cm-s)) calc(clamp(26px, 1.7vw + 20px, 34px) * var(--cm-s)) calc(clamp(6px, 1.5vw, 10px) * var(--cm-s)) calc(clamp(10px, 1vw + 7px, 14px) * var(--cm-s)); color:var(--text,#F0F2FF); font-size:calc(clamp(10px, 8px + .5vw, 12px) * var(--cm-s));
    font-family:Poppins,sans-serif; outline:none; transition:background .2s; box-sizing:border-box;
}
.msg-search-input:focus{ background:rgba(var(--fg,255,255,255),0.1); }
.msg-search-input::placeholder{ color:rgba(var(--fg,255,255,255),0.25); }
.msg-search-icon{ display:none !important; }
.msg-search-clear{ position:absolute; right:calc(12px * var(--cm-s)); top:50%; transform:translateY(-50%); font-size:calc(13px * var(--cm-s)); color:rgba(var(--fg,255,255,255),0.3); cursor:pointer; display:none; background:none; border:none; padding:0; line-height:1; }
.msg-search-clear:hover{ color:rgba(var(--fg,255,255,255),0.7); }

.msg-list{ display:flex; flex-direction:column; padding:0 var(--rp-header-pad-x); }

/* message requests */
.msg-request-item{
    display:flex; align-items:center; gap:calc(clamp(8px, 2.2vw, 12px) * var(--cm-s));
    background:rgba(255,45,45,0.07); border:none;
    padding:calc(clamp(7px, 2vw, 10px) * var(--cm-s)) calc(clamp(9px, 2.8vw, 15px) * var(--cm-s)); border-radius:calc(6px * var(--cm-s)); margin-bottom:calc(6px * var(--cm-s));
    transition:background .2s, opacity .3s, transform .3s;
}
.msg-request-item:hover{ background:rgba(255,45,45,0.1); }
.msg-request-item.msg-req-out-right{ opacity:0; transform:translateX(20px); }
.msg-request-item.msg-req-out-left{ opacity:0; transform:translateX(-20px); }
.msg-request-item img{ width:calc(clamp(32px, 4.5vw + 17px, 44px) * var(--cm-s)); height:calc(clamp(32px, 4.5vw + 17px, 44px) * var(--cm-s)); border-radius:50%; object-fit:cover; border:2px solid #FF2D2D !important; flex-shrink:0; cursor:pointer; }
.msg-request-info{ flex:1; min-width:0; cursor:pointer; }
.msg-request-name{ font-size:calc(clamp(11px, 1vw + 7.5px, 13px) * var(--cm-s)); font-weight:600; color:var(--text,#F0F2FF); display:flex; align-items:center; gap:calc(6px * var(--cm-s)); margin-bottom:2px; flex-wrap:wrap; }
.msg-request-tag{ font-size:calc(9px * var(--cm-s)); font-weight:700; padding:2px calc(6px * var(--cm-s)); border-radius:calc(4px * var(--cm-s)); background:rgba(255,45,45,0.15); border:1px solid rgba(255,45,45,0.3); color:#ff5566; letter-spacing:.3px; }
.msg-req-count{ background:#FF4C4C; color:#fff; font-size:calc(8px * var(--cm-s)); font-weight:700; padding:1px calc(5px * var(--cm-s)); border-radius:calc(4px * var(--cm-s)); }
.msg-request-preview{ font-size:calc(clamp(9.5px, .6vw + 7px, 11px) * var(--cm-s)); color:rgba(var(--fg,255,255,255),0.4); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.msg-deleted-req{ color:rgba(var(--fg,255,255,255),0.25); }
.msg-request-actions{ display:flex; flex-direction:column; gap:calc(5px * var(--cm-s)); flex-shrink:0; }
.msg-req-accept{ padding:calc(clamp(3px, .5vw + 1.5px, 5px) * var(--cm-s)) calc(clamp(8px, 1vw + 5px, 12px) * var(--cm-s)); border-radius:calc(4px * var(--cm-s)); background:rgba(77,222,138,0.15); border:1px solid rgba(77,222,138,0.35); color:#4dde8a; font-size:calc(clamp(8px, .4vw + 7px, 10px) * var(--cm-s)); font-weight:700; cursor:pointer; font-family:Poppins,sans-serif; transition:background .2s; white-space:nowrap; }
.msg-req-accept:hover{ background:rgba(77,222,138,0.25); }
.msg-req-decline{ padding:calc(clamp(3px, .5vw + 1.5px, 5px) * var(--cm-s)) calc(clamp(8px, 1vw + 5px, 12px) * var(--cm-s)); border-radius:calc(4px * var(--cm-s)); background:rgba(255,45,45,0.1); border:1px solid rgba(255,45,45,0.25); color:rgba(var(--fg,255,255,255),0.4); font-size:calc(clamp(8px, .4vw + 7px, 10px) * var(--cm-s)); font-weight:700; cursor:pointer; font-family:Poppins,sans-serif; transition:background .2s; white-space:nowrap; }
.msg-req-decline:hover{ background:rgba(255,45,45,0.18); color:#ff5566; }

.msg-requests-section{ display:none; padding:calc(14px * var(--cm-s)) var(--rp-header-pad-x) 0; }
.msg-requests-section.active{ display:block; }
.msg-requests-header{ font-size:calc(10px * var(--cm-s)); font-weight:700; color:rgba(var(--fg,255,255,255),0.3); text-transform:uppercase; letter-spacing:1px; padding:0 2px calc(10px * var(--cm-s)); display:flex; align-items:center; gap:calc(5px * var(--cm-s)); }
.msg-requests-header i{ font-size:calc(10px * var(--cm-s)); }

/* conversation rows */
.message-user{
    display:flex; align-items:center; gap:calc(clamp(8px, 2vw, 12px) * var(--cm-s)); padding:calc(clamp(6px, 1.2vw + 2px, 10px) * var(--cm-s)) calc(clamp(8px, 1.8vw + 2px, 12px) * var(--cm-s)); cursor:pointer;
    background:rgba(var(--fg,255,255,255),0.06); border:none;
    transition:background .2s ease; border-radius:calc(6px * var(--cm-s)); margin-bottom:calc(6px * var(--cm-s));
}
.message-user:hover{ background:rgba(var(--fg,255,255,255),0.1); }
.message-user img{ width:calc(clamp(32px, 5vw + 16px, 48px) * var(--cm-s)); height:calc(clamp(32px, 5vw + 16px, 48px) * var(--cm-s)); border-radius:50%; object-fit:cover; flex-shrink:0; border:2px solid #FF2D2D !important; }
.gstack{position:relative;display:block;width:clamp(32px, 5vw + 16px, 48px);height:clamp(32px, 5vw + 16px, 48px);flex-shrink:0}
.message-user .gstack img{position:absolute;width:62%;height:62%;border-radius:50%;object-fit:cover;border:2px solid #FF2D2D !important;box-sizing:border-box;background:#10143a}
.gstack.n3 img:nth-child(1){left:19%;top:0;z-index:3}
.gstack.n3 img:nth-child(2){left:0;bottom:0;z-index:2}
.gstack.n3 img:nth-child(3){right:0;bottom:0;z-index:1}
.gstack.n2 img:nth-child(1){left:0;top:0;z-index:2}
.gstack.n2 img:nth-child(2){right:0;bottom:0;z-index:1}
.message-user .gstack.n1 img{width:100%;height:100%}
.message-user-info{ display:flex; flex-direction:column; flex:1; min-width:0; }
.message-user-name{ font-size:calc(clamp(.68rem, .55rem + .5vw, .86rem) * var(--cm-s)); font-weight:600; color:var(--text,#F0F2FF); display:flex; align-items:center; gap:calc(5px * var(--cm-s)); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.message-user-preview{ font-size:calc(clamp(.6rem, .48rem + .45vw, .76rem) * var(--cm-s)); color:rgba(var(--fg,255,255,255),0.5); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-top:calc(3px * var(--cm-s)); }
.message-unread-badge{ background:#f24455; color:#fff; min-width:calc(clamp(12px, 1.2vw + 8px, 18px) * var(--cm-s)); height:calc(clamp(12px, 1.2vw + 8px, 18px) * var(--cm-s)); border-radius:calc(4px * var(--cm-s)); display:flex; align-items:center; justify-content:center; padding:0 calc(clamp(3px, .5vw + 1px, 5px) * var(--cm-s)); flex-shrink:0; font-size:calc(clamp(8px, .4vw + 7px, 10px) * var(--cm-s)); font-weight:700; }
.msg-seen{ color:#227cd6; font-size:calc(10px * var(--cm-s)); font-weight:600; margin-left:calc(4px * var(--cm-s)); letter-spacing:.2px; }
.msg-unread-pill{ background:#FF4C4C; color:#fff; font-size:calc(9px * var(--cm-s)); font-weight:700; padding:1px calc(6px * var(--cm-s)); border-radius:calc(4px * var(--cm-s)); margin-left:calc(4px * var(--cm-s)); }
.msg-dry-tag{ font-size:calc(9px * var(--cm-s)); color:rgba(var(--fg,255,255,255),0.25); font-weight:600; letter-spacing:.3px; flex-shrink:0; }
.msg-you{ color:#d62222; font-weight:500; }
.msg-deleted{ color:rgba(var(--fg,255,255,255),0.3); font-style:italic; }
.msg-att-ico{ color:#2231d6; font-size:calc(13px * var(--cm-s)); vertical-align:-2px; }

/* ══ TAB PAGER (moved up from the body) ══ */
.rp-pager{ width:100%; position:relative; touch-action:pan-y; }
@supports (overflow:clip){ .rp-pager{ overflow-x:clip; }}
@supports not (overflow:clip){ .rp-pager{ overflow:hidden; }}
.rp-pager.dragging{ user-select:none; -webkit-user-select:none; cursor:grabbing; }
.rp-pager img{ -webkit-user-drag:none; }
.rp-track{ display:flex; align-items:flex-start; width:100%; }
.rp-pager:not(.idle) .rp-track{ will-change:transform; backface-visibility:hidden; }
.rp-track > .dropdown-section{ display:block !important; flex:0 0 100%; width:100%; min-width:0; }
.rp-pager.idle .rp-track > .dropdown-section:not(.rp-active){ display:none !important; }
.rp-tab-indicator.live{ transition:none !important; }
/* the pin button lives inside .card-badges, which ignores the pointer — give the button its own */
.grid .meme-card .card-badges .card-pin-btn{ pointer-events:auto; }
.dz-ptr{ cursor:pointer; }
.dz-sent1{ height:1px; }
.rp-tab-btn[data-panel="messages"]{ position:relative; }

.rp-profile-type.is-link{ cursor:pointer; -webkit-tap-highlight-color:transparent; transition:color .2s; }
.rp-profile-type.is-link:hover{ color:var(--text,#F0F2FF); }
.rp-profile-type.is-link:focus-visible{ outline:2px solid #FF2D2D; outline-offset:2px; border-radius:calc(4px * var(--hd-s)); }

/* ── Complete-your-profile box (under the action buttons): no border, same fill as the action buttons ── */
.rp-complete{ position:relative; margin:0 var(--rp-header-pad-x) calc(10px * var(--hd-s)); padding:calc(12px * var(--hd-s)) calc(14px * var(--hd-s)); border-radius:var(--rp-action-radius); background:rgba(var(--fg,255,255,255),0.07); border:none; }
.rp-complete[hidden]{ display:none; }
.rp-complete-t, .rp-complete-s{ padding-right:calc(24px * var(--hd-s)); }   /* room for the ✕ on the text rows only, so the buttons get equal space left and right */
.rp-complete-t{ font-size:calc(clamp(.72rem, .6rem + .6vw, .85rem) * var(--hd-s)); font-weight:700; color:var(--text,#F0F2FF); }
.rp-complete-s{ font-size:calc(clamp(.6rem, .5rem + .5vw, .7rem) * var(--hd-s)); color:rgba(var(--fg,255,255,255),.5); margin:calc(3px * var(--hd-s)) 0 calc(10px * var(--hd-s)); line-height:1.4; }
.rp-complete-btns{ display:flex; gap:calc(clamp(5px, 1.5vw, 8px) * var(--hd-s)); }
.rp-complete-btns .rp-rect-btn{ background:rgba(var(--fg,255,255,255),0.1); cursor:pointer; font-family:inherit; }
.rp-complete-btns .rp-rect-btn:hover{ background:rgba(var(--fg,255,255,255),0.18); }
.rp-complete-x{ position:absolute; top:calc(8px * var(--hd-s)); right:calc(8px * var(--hd-s)); width:calc(26px * var(--hd-s)); height:calc(26px * var(--hd-s)); border:none; background:none; color:rgba(var(--fg,255,255,255),.55); font-size:calc(17px * var(--hd-s)); display:flex; align-items:center; justify-content:center; border-radius:50%; cursor:pointer; -webkit-tap-highlight-color:transparent; }
.rp-complete-x:hover{ color:var(--text,#F0F2FF); background:rgba(var(--fg,255,255,255),.08); }

/* ── search icon in the sort bar + the drop-down search (own profile; Memes / Reposts / Tags / Saved) ── */
.g-pill.g-search-btn{ margin-left:auto; position:sticky; right:0; z-index:2; padding:0; width:var(--tab-btn-h); background:linear-gradient(var(--tab-btn-bg),var(--tab-btn-bg)),var(--ink,#06071A); }
.g-pill.g-search-btn i{ font-size:calc(17px * var(--sb-s)); line-height:1; display:block; }
.g-pill.g-search-btn .tb-sicon{ width:calc(18px * var(--sb-s)); height:calc(18px * var(--sb-s)); display:block; margin:0 auto; }   /* the top bar's magnifier, scaled down */
.g-pill.g-search-btn.active{ background:var(--tab-btn-bg-hover); }
.grid-search-wrap{ display:grid; grid-template-rows:0fr; transition:grid-template-rows .26s ease; }
.grid-search-wrap.open{ grid-template-rows:1fr; }
.grid-search-inner{ position:relative; overflow:hidden; min-height:0; margin:0 var(--rp-header-pad-x); }
.grid-search-wrap.open .grid-search-inner{ padding:0 0 10px; }
.grid-search-field{ position:relative; }
.grid-search-inner .msg-search-input{ display:block; padding-right:calc(36px * var(--cm-s)); }
.grid-search-clear{ position:absolute; right:6px; top:50%; transform:translateY(-50%); width:24px; height:24px; border:none; border-radius:50%; background:none; color:rgba(var(--fg,255,255,255),.45); font-size:16px; display:none; align-items:center; justify-content:center; cursor:pointer; padding:0; line-height:1; -webkit-tap-highlight-color:transparent; }
.grid-search-clear:hover{ color:var(--text,#F0F2FF); background:rgba(var(--fg,255,255,255),.1); }
.grid-search-clear.show{ display:flex; }
.grid-search-recent{ display:none; flex-wrap:wrap; gap:6px; padding-top:8px; }
.grid-search-recent.show{ display:flex; }
.grid-search-chip{ display:inline-flex; align-items:center; gap:5px; max-width:100%; height:24px; padding:0 10px; border:none; border-radius:4px; background:rgba(var(--fg,255,255,255),.075); color:rgba(var(--fg,255,255,255),.75); font-size:11px; font-family:Poppins,sans-serif; cursor:pointer; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.grid-search-chip:hover{ background:rgba(var(--fg,255,255,255),.15); color:var(--text,#F0F2FF); }
.grid-search-chip i{ font-size:12px; opacity:.6; }
.grid-search-none{ display:none; text-align:center; padding:26px 16px; font-size:12px; color:rgba(var(--fg,255,255,255),.4); }
.grid-search-none.show{ display:block; }
.grid .meme-card.dz-sr-hide{ display:none !important; }
.grid.dz-paged > .meme-card.dz-hold.dz-sr-show{ display:block; }
.grid.dz-searching .dz-sentinel, .grid.dz-searching .dz-skel-card{ display:none !important; }

/* ── Memes tab: ⌄ chevron beside the word (opens the All / Videos / Text sheet) ── */
/* ══════════ GUIDE (Memes tab → ⌄ → Guide) — minimal: no boxes, hairlines, red for what matters ══════════ */
#dzInfoPanel{ display:none; }
#memes.dz-info-on > *{ display:none !important; }
#memes.dz-info-on > #dzInfoPanel{ display:flex !important; }
/* the Newest / Oldest / Collections bar and the search button sit in the shared dock — hide the Memes ones while the Guide is open */
body.dz-guide-on #memesSortBar{ display:none !important; }
.dzi-wrap{ flex-direction:column; padding:clamp(14px,4vw,22px) var(--rp-header-pad-x) 36px; }
.dzi-wrap .r{ color:#FF2D2D; font-weight:400; }
.dzi-hero{ padding:0 0 clamp(16px,4.5vw,24px); }
.dzi-hero-t{ font-size:calc(clamp(1.15rem, 1rem + 1vw, 1.5rem) * 1.1); font-weight:300; letter-spacing:.01em; color:var(--text,#F0F2FF); line-height:1.1; }
.dzi-hero-t::after{ content:''; display:block; width:22px; height:2px; background:#FF2D2D; margin-top:8px; border-radius:2px; }
.dzi-hero-s{ font-size:calc(clamp(.6rem, .5rem + .5vw, .72rem) * 1.1); line-height:1.6; color:rgba(var(--fg,255,255,255),0.55); margin-top:10px; font-weight:400; }
.dzi-group{ display:flex; flex-direction:column; margin-bottom:clamp(18px,5vw,26px); }
.dzi-glabel{ font-size:calc(clamp(.5rem, .42rem + .4vw, .62rem) * 1.1); font-weight:400; letter-spacing:.16em; text-transform:uppercase; color:#FF2D2D; padding-bottom:8px; border-bottom:1px solid rgba(var(--fg,255,255,255),0.14); }
.dzi-gsub{ font-size:calc(clamp(.58rem, .5rem + .45vw, .7rem) * 1.1); line-height:1.6; color:rgba(var(--fg,255,255,255),0.55); padding-top:9px; font-weight:400; }
.dzi-gact{ margin-top:8px; padding-bottom:2px; }
.dzi-list{ display:flex; flex-direction:column; }
.dzi-item{ padding:clamp(11px,3vw,16px) 0 clamp(12px,3.2vw,17px); border-bottom:1px solid rgba(var(--fg,255,255,255),0.08); }
.dzi-item:last-child{ border-bottom:none; }
.dzi-head{ display:flex; align-items:baseline; gap:10px; }
.dzi-t{ flex:1; min-width:0; font-size:calc(clamp(.7rem, .6rem + .5vw, .86rem) * 1.1); font-weight:500; color:var(--text,#F0F2FF); letter-spacing:.005em; }
.dzi-chip{ flex-shrink:0; max-width:55%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-size:calc(clamp(.5rem, .42rem + .4vw, .62rem) * 1.1); font-weight:400; color:rgba(var(--fg,255,255,255),0.38); }
.dzi-chip::before{ content:''; display:inline-block; width:4px; height:4px; border-radius:50%; background:rgba(var(--fg,255,255,255),0.3); margin-right:5px; vertical-align:1px; }
.dzi-chip.on{ color:rgba(var(--fg,255,255,255),0.75); }
.dzi-chip.on::before{ background:#FF2D2D; }
.dzi-demo{ display:flex; flex-wrap:wrap; align-items:center; gap:5px; margin-top:10px; }
.dzi-pill{ display:inline-flex; align-items:center; justify-content:center; height:clamp(19px,5.2vw,23px); padding:0 clamp(9px,2.6vw,12px); border-radius:4px; background:rgba(var(--fg,255,255,255),0.09); color:var(--text,#F0F2FF); font-size:calc(clamp(.5rem, .42rem + .4vw, .62rem) * 1.1); font-weight:500; line-height:1; }
.dzi-link{ font-size:calc(clamp(.56rem, .48rem + .4vw, .68rem) * 1.1); color:rgba(var(--fg,255,255,255),0.85); display:inline-flex; align-items:center; gap:5px; }
.dzi-link i{ font-size:13px; }
.dzi-grey{ font-size:calc(clamp(.56rem, .48rem + .4vw, .68rem) * 1.1); color:rgba(var(--fg,255,255,255),0.45); }
.dzi-dot{ width:9px; height:9px; border-radius:50%; background:#3ccf7a; box-shadow:0 0 0 3px var(--surface-deep,#05061a); display:inline-block; margin-right:6px; }
.dzi-b{ margin:9px 0 0; font-size:calc(clamp(.58rem, .5rem + .45vw, .7rem) * 1.1); line-height:1.65; color:rgba(var(--fg,255,255,255),0.58); font-weight:400; }
.dzi-act{ margin-top:10px; display:flex; flex-wrap:wrap; align-items:center; gap:16px; }
.dzi-btn{ display:inline-flex; align-items:center; gap:5px; padding:2px 0; border:none; background:none; color:#FF2D2D; font-size:calc(clamp(.58rem, .5rem + .45vw, .68rem) * 1.1); font-weight:400; text-decoration:none; cursor:pointer; line-height:1.2; letter-spacing:.01em; -webkit-tap-highlight-color:transparent; }
.dzi-btn::after{ content:'→'; transition:transform .2s ease; }
.dzi-btn:hover::after{ transform:translateX(3px); }
.dzi-plus{ display:inline-flex; align-items:center; justify-content:center; width:14px; height:14px; border-radius:50%; background:#FF2D2D; vertical-align:-3px; margin:0 1px; }
.dzi-plus i{ font-size:10px; color:var(--surface-deep,#05061a); line-height:1; }
.rp-tab-chev{ display:none; align-items:center; justify-content:center; width:1.15em; height:1.15em; margin-left:calc(-3px * var(--hd-s)); border-radius:50%; color:var(--text,#F0F2FF); font-size:calc(clamp(.62rem, .4rem + .6vw, .78rem) * var(--hd-s) * 1.11); line-height:1; cursor:pointer; position:relative; -webkit-tap-highlight-color:transparent; }
.rp-tab-chev i{ font-size:1.29em; line-height:1; display:block; transform:translateY(1.5px); }
.rp-tab-btn.active .rp-tab-chev{ display:inline-flex; }
.rp-tab-chev:hover{ background:rgba(var(--fg,255,255,255),.1); }
.rp-tab-chev.has-filter{ color:#FF2D2D; }
.rp-tab-chev.has-filter::after{ content:''; position:absolute; top:1px; right:0; width:calc(5px * var(--hd-s)); height:calc(5px * var(--hd-s)); border-radius:50%; background:#FF2D2D; }

/* ── new-vibes banner (own profile, top) ── */
.rp-vibe-banner{ position:relative; display:flex; align-items:center; gap:calc(10px * var(--hd-s)); margin:calc(10px * var(--hd-s)) var(--rp-header-pad-x) 0; padding:calc(12px * var(--hd-s)) calc(40px * var(--hd-s)) calc(12px * var(--hd-s)) calc(14px * var(--hd-s)); border-radius:var(--rp-action-radius); background:rgba(255,45,45,0.14); border:none; cursor:pointer; -webkit-tap-highlight-color:transparent; }
.rp-vibe-banner[hidden]{ display:none; }
.rp-vibe-banner:hover{ background:rgba(255,45,45,0.2); }
.rp-vibe-banner-ic{ font-size:calc(20px * var(--hd-s)); color:#FF2D2D; flex-shrink:0; line-height:1; }
.rp-vibe-banner-t{ flex:1; min-width:0; font-size:calc(clamp(.6rem, .3rem + 1.7vw, .8rem) * var(--hd-s)); font-weight:600; color:var(--text,#F0F2FF); line-height:1.35; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.rp-vibe-banner-t .vb-short{ display:none; }
@media (max-width:430px){ .rp-vibe-banner{ padding:calc(11px * var(--hd-s)) calc(36px * var(--hd-s)) calc(11px * var(--hd-s)) calc(12px * var(--hd-s)); gap:calc(8px * var(--hd-s)); } .rp-vibe-banner-t .vb-full{ display:none; } .rp-vibe-banner-t .vb-short{ display:inline; }}
.rp-vibe-banner-x{ position:absolute; top:50%; right:calc(8px * var(--hd-s)); transform:translateY(-50%); width:calc(26px * var(--hd-s)); height:calc(26px * var(--hd-s)); border:none; background:none; color:rgba(var(--fg,255,255,255),.6); font-size:calc(17px * var(--hd-s)); display:flex; align-items:center; justify-content:center; border-radius:50%; cursor:pointer; }
.rp-vibe-banner-x:hover{ color:var(--text,#F0F2FF); background:rgba(var(--fg,255,255,255),.1); }
html.dz-hide-untracked .rp-rect-btn.untracked{ display:none !important; }
/* the pill — slim, softly rounded, drag it up or down with your finger */
.dz-cta{
    position:fixed; left:var(--dz-cta-cx, 50%); bottom:calc(var(--dz-cta-b, 0px) + 14px + env(safe-area-inset-bottom, 0px)); z-index:850;
    width:var(--dz-cta-w, calc(100% - 16px)); max-width:480px; box-sizing:border-box;
    display:flex; align-items:center; gap:10px;
    padding:8px 13px 8px 8px; border-radius:5px;
    background:var(--cta-bg,#3B6EF6); color:var(--cta-fg,#fff); text-decoration:none;
    box-shadow:0 6px 18px rgba(0,0,0,.42), 0 0 0 1px rgba(var(--fg,255,255,255),.07) inset;
    transform:translate(calc(-50% + var(--cta-dx, 0px)), 160%); opacity:0;
    animation:dzCtaIn .5s .35s cubic-bezier(.22,1,.36,1) forwards;
    -webkit-tap-highlight-color:transparent; font-family:'Poppins',sans-serif;
    touch-action:none; -webkit-user-select:none; user-select:none; cursor:grab;
}
@keyframes dzCtaIn{ to{ transform:translate(calc(-50% + var(--cta-dx, 0px)), var(--cta-dy, 0px)); opacity:1; } }
.dz-cta.rdy{ animation:none; opacity:1; transform:translate(calc(-50% + var(--cta-dx, 0px)), var(--cta-dy, 0px)); transition:transform .18s ease, opacity .22s ease; }
.dz-cta.gone{ opacity:0 !important; transform:translate(calc(-50% + var(--cta-dx, 0px)), calc(var(--cta-dy, 0px) + 30px)) scale(.96) !important; pointer-events:none; animation:none; }
.dz-cta.rdy.drag{ transition:none; cursor:grabbing; box-shadow:0 10px 26px rgba(0,0,0,.5), 0 0 0 1px rgba(var(--fg,255,255,255),.1) inset; }
.dz-cta:active{ filter:brightness(.94); }
.dz-cta-pic{ width:32px; height:32px; border-radius:4px; object-fit:cover; flex-shrink:0; background:rgba(0,0,0,.2); pointer-events:none; }
.dz-cta-t{ flex:1; min-width:0; font-size:13.5px; font-weight:600; line-height:1.2; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; pointer-events:none; }
.dz-cta-go{ font-size:20px; flex-shrink:0; opacity:.9; pointer-events:none; }
.dz-cta-x{ pointer-events:auto; cursor:pointer; padding:7px; margin:-7px -5px -7px 0; border-radius:50%; font-size:calc(18px * 1.5); line-height:1; }
.dz-cta-x:hover{ background:rgba(0,0,0,.14); }
@media (max-width:430px){ .dz-cta{ padding:7px 11px 7px 7px; gap:9px; } .dz-cta-pic{ width:29px; height:29px; } .dz-cta-t{ font-size:13px; }}
/* responsive: the pill is 21% bigger than the base size (the first +10%, then another +10% on top) */
@media (max-width:768px){
    .dz-cta{ padding:calc(8px * 1.21) calc(13px * 1.21) calc(8px * 1.21) calc(8px * 1.21); gap:calc(10px * 1.21); }
    .dz-cta-pic{ width:calc(32px * 1.21); height:calc(32px * 1.21); }
    .dz-cta-t{ font-size:calc(13.5px * 1.21 * .9); }
    .dz-cta-go{ font-size:calc(20px * 1.21); }
    .dz-cta-x{ padding:calc(7px * 1.21); margin:calc(-7px * 1.21) calc(-5px * 1.21) calc(-7px * 1.21) 0; font-size:calc(18px * 1.21 * 1.5 * .9); }}
@media (max-width:430px){
    .dz-cta{ padding:calc(7px * 1.21) calc(11px * 1.21) calc(7px * 1.21) calc(7px * 1.21); gap:calc(9px * 1.21); }
    .dz-cta-pic{ width:calc(29px * 1.21); height:calc(29px * 1.21); }
    .dz-cta-t{ font-size:calc(13px * 1.21 * .9); }}
@media (max-width:768px){ .dz-cta{ bottom:calc(var(--dz-cta-b, 0px) + 8px); } }
body.dz-has-cta{ padding-bottom:calc(82px + env(safe-area-inset-bottom, 0px)); }

/* before-you-go warning */
#dzCtaWarn{ display:none; position:fixed; inset:0; z-index:2147483646; align-items:center; justify-content:center; padding:16px; background:rgba(0,0,0,.66); backdrop-filter:blur(3px); -webkit-backdrop-filter:blur(3px); font-family:'Poppins',sans-serif; }
#dzCtaWarn.open{ display:flex; animation:ukbFadeIn .2s ease; }
.cw-box{ width:100%; max-width:360px; max-height:90vh; overflow-y:auto; background:var(--lane,#070924); border-radius:8px; padding:18px 18px 12px; box-shadow:0 16px 40px rgba(0,0,0,.6); text-align:center; animation:dzmPopIn .22s cubic-bezier(.34,1.56,.64,1); color:#fff; }
.cw-ic{ width:42px; height:42px; margin:0 auto 8px; border-radius:50%; background:rgba(255,45,45,.14); color:#FF2D2D; display:flex; align-items:center; justify-content:center; font-size:23px; }
.cw-t{ font-size:15px; font-weight:700; }
.cw-host{ display:inline-flex; align-items:center; gap:6px; max-width:100%; margin:9px 0 2px; padding:5px 11px; border-radius:999px; background:rgba(var(--fg,255,255,255),.08); font-size:12px; font-weight:600; color:var(--text,#F0F2FF); }
.cw-host span{ overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.cw-p{ margin:12px 0 0; font-size:12px; line-height:1.55; color:rgba(var(--fg,255,255,255),.72); }
.cw-ul{ margin:10px 0 0; padding:0 0 0 18px; text-align:left; font-size:11.5px; line-height:1.55; color:rgba(var(--fg,255,255,255),.62); }
.cw-ul li{ margin:4px 0; } .cw-ul b{ color:#ff7a7a; font-weight:600; }
.cw-row{ display:flex; gap:8px; margin-top:14px; }
.cw-row button{ flex:1; padding:7px 8px; border:none; border-radius:5px; font-size:12.5px; font-weight:600; font-family:inherit; cursor:pointer; -webkit-tap-highlight-color:transparent; }
.cw-cancel{ background:rgba(var(--fg,255,255,255),.09); color:var(--text,#F0F2FF); }
.cw-continue{ background:#FF2D2D; color:#000; }
.cw-report{ display:flex; align-items:center; justify-content:center; gap:6px; width:100%; margin-top:4px; padding:7px; border:none; background:none; color:#FF2D2D; font-size:12px; font-weight:600; font-family:inherit; cursor:pointer; -webkit-tap-highlight-color:transparent; }
@media (max-width:430px){ .cw-box{ padding:15px 15px 10px; } .cw-t{ font-size:13.5px; } .cw-p{ font-size:11px; } .cw-ul{ font-size:10.5px; } .cw-row button{ font-size:12px; padding:6px 6px; }}
.fvx-sw{ display:flex; align-items:center; gap:8px; margin-top:10px; font-size:12.5px; color:rgba(var(--fg,255,255,255),.85); cursor:pointer; }
.fvx-sw input{ accent-color:#FF2D2D; width:16px; height:16px; }
.fvx-tr{ margin-top:10px; padding:10px 12px; border-radius:6px; background:var(--lane,#070924); font-size:12.5px; line-height:1.5; word-break:break-word; }
.fvx-tr small{ display:block; font-size:9.5px; letter-spacing:.8px; text-transform:uppercase; color:rgba(var(--fg,255,255,255),.4); margin-bottom:3px; }
.fvx-lang{ display:flex; justify-content:space-between; align-items:center; gap:10px; padding:10px 8px; border-top:1px solid rgba(var(--fg,255,255,255),.07); cursor:pointer; font-size:13px; -webkit-tap-highlight-color:transparent; }
.fvx-lang:hover{ background:rgba(var(--fg,255,255,255),.06); }
.fvx-lang.on{ color:#FF2D2D; font-weight:700; }
.fvx-lang small{ font-size:10.5px; color:rgba(var(--fg,255,255,255),.4); }
body{ top:0 !important; }
.goog-te-banner-frame, body > .skiptranslate, .goog-te-balloon-frame, #goog-gt-tt, .goog-tooltip{ display:none !important; }
.goog-text-highlight{ background:none !important; box-shadow:none !important; }
.rp-username{ -webkit-touch-callout:none; -webkit-tap-highlight-color:transparent; }
.fvx-lang{ padding:8px 6px; font-size:12px; } .fvx-lang small{ font-size:9.5px; }
.fvx-tr{ font-size:11.5px; padding:8px 10px; }
@media (max-width:790px){
    .fvx-lang{ padding:6px 4px; font-size:11.5px; }}
.fvx-lang{ padding:calc(8px * var(--bs-s)) calc(6px * var(--bs-s)); font-size:calc(12px * var(--bs-s)); } .fvx-lang small{ font-size:calc(9.5px * var(--bs-s)); }
.fvx-tr{ font-size:calc(11.5px * var(--bs-s)); padding:calc(8px * var(--bs-s)) calc(10px * var(--bs-s)); }
@media (max-width:790px){
    .fvx-lang{ padding:calc(6px * var(--bs-s)) calc(4px * var(--bs-s)); font-size:calc(11.5px * var(--bs-s)); }}

/* ── helpers (replace inline style="display:none" etc.) ── */
.dz-hide{ display:none !important; }
.dz-invis{ visibility:hidden; }
.fvx-gt{ position:absolute; left:-9999px; top:0; width:1px; height:1px; overflow:hidden; }
.pf-note.gap, .pf-note-gap{ margin-top:6px; }
html.dz-hide-untracked .rp-rect-btn.untracked{ display:none !important; }
<?php foreach (dz_cta_colors() as $ck_ => $cv_): ?>
.dz-cta.cc-<?php echo $ck_; ?>{ --cta-bg:<?php echo $cv_[0]; ?>; --cta-fg:<?php echo $cv_[1]; ?>; }
.cta-sw .sw-<?php echo $ck_; ?>{ --c:<?php echo $cv_[0]; ?>; }
<?php endforeach; ?>
</style>
<?php
$dz_hide_untr = null;   // null = column not there yet (falls back to this browser's saved choice)
if ((int)$current_user === (int)$profile_id && (int)$current_user > 0) {
    try {
        $hc = $conn->query("SELECT hide_untracked_btn FROM users WHERE id = " . (int)$current_user . " LIMIT 1");
        if ($hc instanceof mysqli_result && ($hr = $hc->fetch_assoc())) $dz_hide_untr = (int)$hr['hide_untracked_btn'];
    } catch (Throwable $e) { $dz_hide_untr = null; }
?>
<script>try{ var __h = <?php echo $dz_hide_untr === null ? 'null' : $dz_hide_untr; ?>; if(__h === null) __h = (localStorage.getItem('dzHideUntracked_<?php echo (int)$current_user; ?>')==='1') ? 1 : 0; if(__h) document.documentElement.classList.add('dz-hide-untracked'); }catch(e){}</script>
<?php } ?>
</head>
<body>
<?php /* Dezzme Verified badge sprite (filled flower, check cut out — takes the text colour) */ ?>
<svg width="0" height="0" style="position:absolute;width:0;height:0;overflow:hidden" aria-hidden="true" focusable="false">
  <defs><path id="dz-fp" fill-rule="evenodd" d="M245.9 128.0L245.5 132.6L244.2 137.1L242.3 141.5L239.7 145.7L236.5 149.6L233.0 153.2L229.3 156.6L225.6 159.7L221.9 162.6L218.5 165.5L215.4 168.3L212.7 171.2L210.5 174.2L208.7 177.5L207.3 181.0L206.3 184.9L205.4 189.1L204.7 193.5L204.0 198.2L203.1 203.1L201.9 208.0L200.4 212.8L198.4 217.4L196.0 221.6L193.0 225.3L189.5 228.4L185.6 230.8L181.3 232.5L176.6 233.5L171.8 233.7L166.9 233.4L161.9 232.4L157.1 231.1L152.4 229.5L147.8 227.8L143.5 226.1L139.4 224.6L135.5 223.5L131.7 222.8L128.0 222.5L124.3 222.8L120.5 223.5L116.6 224.6L112.5 226.1L108.2 227.8L103.6 229.5L98.9 231.1L94.1 232.4L89.1 233.4L84.2 233.7L79.4 233.5L74.7 232.5L70.4 230.8L66.5 228.4L63.0 225.3L60.0 221.6L57.6 217.4L55.6 212.8L54.1 208.0L52.9 203.1L52.0 198.2L51.3 193.5L50.6 189.1L49.7 184.9L48.7 181.0L47.3 177.5L45.5 174.2L43.3 171.2L40.6 168.3L37.5 165.5L34.1 162.6L30.4 159.7L26.7 156.6L23.0 153.2L19.5 149.6L16.3 145.7L13.7 141.5L11.8 137.1L10.5 132.6L10.1 128.0L10.5 123.4L11.8 118.9L13.7 114.5L16.3 110.3L19.5 106.4L23.0 102.8L26.7 99.4L30.4 96.3L34.1 93.4L37.5 90.5L40.6 87.7L43.3 84.8L45.5 81.8L47.3 78.5L48.7 75.0L49.7 71.1L50.6 66.9L51.3 62.5L52.0 57.8L52.9 52.9L54.1 48.0L55.6 43.2L57.6 38.6L60.0 34.4L63.0 30.7L66.5 27.6L70.4 25.2L74.7 23.5L79.4 22.5L84.2 22.3L89.1 22.6L94.1 23.6L98.9 24.9L103.6 26.5L108.2 28.2L112.5 29.9L116.6 31.4L120.5 32.5L124.3 33.2L128.0 33.5L131.7 33.2L135.5 32.5L139.4 31.4L143.5 29.9L147.8 28.2L152.4 26.5L157.1 24.9L161.9 23.6L166.9 22.6L171.8 22.3L176.6 22.5L181.3 23.5L185.6 25.2L189.5 27.6L193.0 30.7L196.0 34.4L198.4 38.6L200.4 43.2L201.9 48.0L203.1 52.9L204.0 57.8L204.7 62.5L205.4 66.9L206.3 71.1L207.3 75.0L208.7 78.5L210.5 81.8L212.7 84.8L215.4 87.7L218.5 90.5L221.9 93.4L225.6 96.3L229.3 99.4L233.0 102.8L236.5 106.4L239.7 110.3L242.3 114.5L244.2 118.9L245.5 123.4ZM84.1 131.9Q78.2 137.8 84.1 143.6L108.3 167.9Q114.1 173.7 119.9 167.8L174.2 111.6Q179.9 105.6 174.0 99.9L170.0 96.1Q164.1 90.4 158.4 96.3L119.6 136.4Q113.9 142.3 108.0 136.5L99.6 128.1Q93.8 122.2 87.9 128.1Z"/></defs>
  <symbol id="dz-flower-f" viewBox="0 0 256 256"><use href="#dz-fp" fill="currentColor"/></symbol>
</svg>
<script>window.DZ_VB = <?php echo json_encode(dz_vb_svg(), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>; window.DZ_VB_NAME = <?php echo json_encode((string)$user['username'], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;</script>
<script>window.dzHp = window.dzHp || function(k){ try{ if(window.dzHaptic && typeof window.dzHaptic[k]==='function') window.dzHaptic[k](); }catch(e){} };</script>
<style>
.dz-vb{ width:1em; height:1em; flex-shrink:0; display:inline-block; vertical-align:-.15em; color:inherit; }
.rp-username-row > .dz-vb{ width:calc(var(--rp-username-fs) * .83); height:calc(var(--rp-username-fs) * .83); color:var(--red,#FF2D2D); margin-left:0; }
/* same gap between username and badge as in the top bar (.28em of the top bar's text size) */
.rp-username-row:has(> .dz-vb){ gap:calc(.28 * clamp(.8rem, .6rem + 1vw, .92rem) * var(--hd-s)); }
.rp-topbar-username{ gap:.28em; }
.rp-topbar-username .dz-un-t{ min-width:0; overflow:hidden; text-overflow:ellipsis; }
.rp-topbar-username .dz-vb{ width:.94em; height:.94em; color:var(--red,#FF2D2D); }
</style>
<?php dz_offline_ui('body'); ?>
<script>try{ if('serviceWorker' in navigator) navigator.serviceWorker.register('service-worker.js').catch(function(){}); }catch(e){}   /* same service worker as the home page: shows offline.html when a page can't load at all */</script>
<?php if (!empty($dz_view_as)) { $__va_cu = $current_user; $current_user = $dz_real_user; } include 'nav.php'; if (!empty($dz_view_as)) { $current_user = $__va_cu; } ?>

<div class="pf-shell<?= !empty($DZ_SUSP) ? ' dz-susp' : '' ?>">
<div class="rp-page">

<!-- ══════════════════════════════════════
     TOPBAR — lives directly inside .rp-page (outside #dzTopReal)
     so `position:sticky` keeps it pinned for the whole scroll.
══════════════════════════════════════ -->
<div class="rp-topbar<?php echo ($current_user == $profile_id) ? '' : ' not-owner'; ?><?php echo ($topbar_show_track ? '' : ' no-track'); ?><?php echo (($current_user == $profile_id) && $track_req_count > 0) ? ' has-req' : ''; ?>" id="rpTopbar">

    <div class="rp-tb-side left">
        <?php if(!empty($dz_view_as)): ?>
            <a href="profile.php" id="dzVaExit" class="rp-tb-btn memepedia" title="Exit View As" aria-label="Exit View As"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></a>
        <?php elseif($current_user == $profile_id): ?>
            <a href="memepedia.php" class="rp-tb-btn memepedia" title="Memepedia"><i class="ri-book-open-line"></i></a>
        <?php else: ?>
            <!-- Back button: hidden on desktop (CSS), visible on responsive/mobile view -->
            <button type="button" class="rp-tb-btn back" aria-label="Go back"
                onclick="if(document.referrer && document.referrer.indexOf(window.location.host) !== -1){history.back();}else{location.href='profile.php?user_id=<?php echo (int)$profile_id; ?>';}">
                <svg class="dz-back-svg" viewBox="0 0 14 24" aria-hidden="true"><path d="M12.4 1.6 L2 12 L12.4 22.4" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linejoin="miter" stroke-linecap="butt"/></svg>
            </button>
        <?php endif; ?>
    </div>

    <span class="rp-topbar-username" id="rpTopbarUsername"><span class="dz-un-t"><?php echo htmlspecialchars($user['username']); ?></span><?php if (!empty($user['is_verified'])) echo dz_vb_svg(); ?></span>

    <div class="rp-tb-side right">
        <?php if($current_user == $profile_id): ?>

            <?php if($track_req_count > 0): ?>
            <!-- Latest person who requested to track you + how many are waiting.
                 Opens Settings → Track Requests (modal-trackreqs). -->
            <a href="setting.php?open=trackreqs" class="rp-tb-req" title="Track requests">
                <img src="<?php echo htmlspecialchars($track_req_pic); ?>" onerror="this.onerror=null;this.src='mee.jpg'" alt="">
                <span class="rp-tb-req-count"><?php echo $track_req_count > 99 ? '99+' : (int)$track_req_count; ?></span>
            </a>
            <?php endif; ?>

            <?php include 'profile_notifications.php'; /* notification bell + its own popup */ ?>
            <button type="button" class="rp-tb-btn" title="Menu" onclick="openKebabModal()"><i class="ri-menu-line"></i></button>
        <?php else: ?>
            <div class="rp-tb-default">
                <a href="search.php?focus=1" class="rp-tb-btn search" title="Search"><svg class="tb-sicon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="10" cy="10" r="6.5" fill="none" stroke="currentColor" stroke-width="2.5"/><path fill="currentColor" d="M15.37 14.23L21.41 18.59A2 2 0 0 1 18.59 21.41L14.23 15.37Z"/></svg></a>
                <button type="button" class="rp-tb-btn" title="Menu" onclick="openProfileKebabModal()"><i class="ri-menu-line"></i></button>
            </div>
            <?php if($topbar_show_track): ?>
            <div class="rp-tb-track-wrap">
                <?php if($topbar_is_private && $topbar_req_status === 'pending'): ?>
                    <button type="button" class="rp-tb-track-btn pending" onclick="dzCancelRequest(this)">Requested</button>
                <?php else: ?>
                    <button type="button" class="rp-tb-track-btn" id="rpTopbarTrackBtn"
                            data-private="<?= $topbar_is_private ? '1' : '0' ?>"
                            data-target="<?= (int)$profile_id ?>"
                            onclick="dzTopbarTrack(this)"><?= $topbar_is_private ? 'Request to Track' : 'Track' ?></button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

</div>
<?php if(!empty($dz_view_as)): ?>
<?php $dz_va_lvl = (string)($dz_priv['level'] ?? 'public'); ?>
<div class="dz-va-strip">
<?php if($dz_va_lvl === 'public'): ?>
    This is how other Dezzmers see your profile
<?php else: ?>
    <div class="dz-va-seg" role="tablist">
        <a href="profile.php?view_as=public" class="<?php echo empty($dz_va_tracker) ? 'on' : ''; ?>">Doesn't track you</a>
        <a href="profile.php?view_as=tracker" class="<?php echo !empty($dz_va_tracker) ? 'on' : ''; ?>">Tracks you</a>
    </div>
    <div class="dz-va-note"><?php echo !empty($dz_va_tracker) ? 'How someone who tracks you sees your profile' : 'How someone who doesn\'t track you sees your profile'; ?> · <?php echo htmlspecialchars(['limited'=>'Limited','private'=>'Private','full'=>'Full Private'][$dz_va_lvl] ?? 'Public'); ?></div>
<?php endif; ?>
</div>
<?php endif; ?>

<div id="dzTopReal">

<?php
$has_pronouns = !empty($user['pronouns']);
$has_vibe     = !empty($activity_vibe);
$has_type     = !empty($user['user_type']);
$has_any_pill = $has_pronouns || $has_vibe || $has_type;

/* red dot beside the username chevron (owner only): unread messages on your OTHER
   linked accounts. (Unread on the account you're viewing shows on the Messages tab.) */
/* red dot beside "Untracked" (owner only): someone untracked or blocked me since I last opened
   untracked.php. First time (never opened) it looks at the last 7 days. */
$untracked_dot = false;
if ($current_user == $profile_id && isset($conn) && $conn) {
    try {
        $ut_uid = (int)$current_user;
        $ut_since = "COALESCE((SELECT seen_at FROM untracked_seen WHERE user_id = $ut_uid), DATE_SUB(NOW(), INTERVAL 7 DAY))";
        $ut_c = 0;
        try {
            $ut_r = $conn->query("SELECT COUNT(*) AS c FROM blocks b WHERE b.blocked_id = $ut_uid AND b.created_at > $ut_since");
            if ($ut_r instanceof mysqli_result) { $ut_row = $ut_r->fetch_assoc(); $ut_c += (int)($ut_row['c'] ?? 0); }
        } catch (Throwable $e) {}
        try {
            $ut_r = $conn->query("SELECT COUNT(*) AS c FROM unfollow_log ul WHERE ul.user_id = $ut_uid AND ul.unfollowed_at > $ut_since
                                  AND NOT EXISTS (SELECT 1 FROM followers f WHERE f.follower_id = ul.unfollowed_by AND f.following_id = $ut_uid)");
            if ($ut_r instanceof mysqli_result) { $ut_row = $ut_r->fetch_assoc(); $ut_c += (int)($ut_row['c'] ?? 0); }
        } catch (Throwable $e) {}
        $untracked_dot = ($ut_c > 0);
    } catch (Throwable $e) { $untracked_dot = false; }
}

$acct_unread_dot = false;
$cur_unread = 0;   // unread messages of the account being viewed (drives the Messages tab dot)
if ($current_user == $profile_id) {
    if (isset($conn) && $conn) {
        try {
            $cu = $conn->query("SELECT COUNT(*) AS cnt FROM messages WHERE receiver_id=" . (int)$current_user . " AND seen=0");
            if ($cu instanceof mysqli_result) { $curow = $cu->fetch_assoc(); $cur_unread = (int)($curow['cnt'] ?? 0); }
        } catch (Throwable $e) { $cur_unread = 0; }
        try {
            $cg = $conn->query("SELECT COUNT(*) AS cnt FROM msg_gc_messages x JOIN msg_gc_members m ON m.group_id=x.group_id AND m.user_id=" . (int)$current_user . " LEFT JOIN msg_gc_reads r ON r.group_id=x.group_id AND r.user_id=m.user_id WHERE x.id > COALESCE(r.last_read_id,0) AND x.sender_id != m.user_id");
            if ($cg instanceof mysqli_result) $cur_unread += (int)($cg->fetch_assoc()['cnt'] ?? 0);
        } catch (Throwable $e) {}
    }
    if (isset($conn) && $conn && !empty($_SESSION['accounts'])) {
        foreach ($_SESSION['accounts'] as $ua_id) {
            if ((int)$ua_id === (int)$current_user) continue;   // this account's unread goes on the Messages tab
            try {
                $ur = $conn->query("SELECT COUNT(*) AS cnt FROM messages WHERE receiver_id=" . (int)$ua_id . " AND seen=0");
                if ($ur instanceof mysqli_result) {
                    $urow = $ur->fetch_assoc();
                    if ((int)($urow['cnt'] ?? 0) > 0) { $acct_unread_dot = true; break; }
                }
            } catch (Throwable $e) {}
        }
    }
}
?>

<?php /* ── NEW VIBES banner — own profile, Vibe Check ON, votes the owner hasn't looked at yet. Tap = opens Vibe Check and clears it; ✕ clears it too. ── */
if ($current_user == $profile_id && !empty($vibe_enabled)):
    $vb_total = 0;
    try { $vbq = $conn->query("SELECT COUNT(*) AS c FROM profile_vibes WHERE target_id = " . (int)$profile_id); if ($vbq instanceof mysqli_result) $vb_total = (int)($vbq->fetch_assoc()['c'] ?? 0); } catch (Throwable $e) { $vb_total = 0; }
    if ($vb_total > 0): ?>
<div class="rp-vibe-banner" id="rpVibeBanner" role="button" tabindex="0" data-uid="<?php echo (int)$profile_id; ?>" data-total="<?php echo $vb_total; ?>" hidden>
    <i class="ri-sparkling-2-line rp-vibe-banner-ic"></i>
    <div class="rp-vibe-banner-t"><span class="vb-full">You've got new vibes — see what people think of you</span><span class="vb-short">You've got new vibes — tap to see</span></div>
    <button type="button" class="rp-vibe-banner-x" aria-label="Remove" onclick="event.stopPropagation();rpVibeBannerClear()"><i class="ri-close-line"></i></button>
</div>
<script>
(function(){
    var b = document.getElementById('rpVibeBanner'); if(!b) return;
    var key = 'dzVibeSeen_' + b.getAttribute('data-uid'), total = parseInt(b.getAttribute('data-total'), 10) || 0, seen = 0;
    try { seen = parseInt(localStorage.getItem(key) || '0', 10) || 0; } catch(e){}
    if(total < seen){ seen = total; try { localStorage.setItem(key, String(total)); } catch(e){} }   // votes were undone
    if(total > seen) b.hidden = false;
    window.rpVibeBannerClear = function(){ b.hidden = true; try { localStorage.setItem(key, String(total)); } catch(e){} };
    b.addEventListener('click', function(){ rpVibeBannerClear(); if(typeof vibeCheck === 'function') vibeCheck(parseInt(b.getAttribute('data-uid'), 10)); });
    b.addEventListener('keydown', function(e){ if(e.key === 'Enter' || e.key === ' '){ e.preventDefault(); b.click(); } });
})();
</script>
<?php endif; endif; ?>
<div class="rp-header">

    <div class="rp-info-block">
        <div class="rp-username-row<?php echo ($current_user == $profile_id) ? '' : ' not-owner'; ?>"
             <?php echo ($current_user == $profile_id) ? 'onclick="toggleUserKebab(event)" role="button" aria-label="Account menu"' : ''; ?>>
            <?php if($current_user == $profile_id): ?>
                <span class="rp-username"><?php echo htmlspecialchars($user['username']); ?></span><?php if (!empty($user['is_verified'])) echo dz_vb_svg(); ?>
                <span class="rp-chev-wrap">
                    <i class="ri-arrow-down-s-line rp-chevron" id="rpUserChevron"></i>
                    <?php if($acct_unread_dot): ?><span class="rp-chev-dot" aria-label="Unread messages"></span><?php endif; ?>
                </span>
            <?php else: ?>
                <!-- Other users only: username is a link to their account.php page -->
                <a class="rp-username" href="account.php?user_id=<?php echo (int)$profile_id; ?>"><?php echo htmlspecialchars($user['username']); ?></a><?php if (!empty($user['is_verified'])) echo dz_vb_svg(); ?>
            <?php endif; ?>
        </div>
        <?php /* @nickname — when no nickname is set it shows @username in the same spot, looking and behaving exactly the same;
                  once a nickname is created it replaces it */
              $rp_nick_txt = (!empty($user['nickname']) && $user['nickname'] !== $user['username']) ? $user['nickname'] : $user['username']; ?>
        <div class="rp-nickname" role="button" title="Tap to copy" data-nick="@<?php echo htmlspecialchars($rp_nick_txt, ENT_QUOTES); ?>" onclick="dzCopyNickname(this)">@<?php echo htmlspecialchars($rp_nick_txt); ?></div>

        <div class="rp-stats-row">
            <a href="<?php echo ($blocked_check || $dz_stats_locked) ? '#' : 'tracks.php?user_id='.$profile_id.'&type=followers'; ?>" class="rp-stat"
               <?php if($blocked_check) echo 'onclick="alert(\'You are blocked, you cannot view this.\'); return false;"';
                     elseif($dz_stats_locked) echo 'onclick="alert(\'Track this Peumer to view their Trackers.\'); return false;"'; ?>>
                <span class="rp-stat-num<?php echo $dz_stats_locked ? ' blur' : ''; ?>"><?php echo shortNum($followers_count); ?></span>
                <span class="rp-stat-lbl">Trackers</span>
            </a>
            <a href="<?php echo ($blocked_check || $dz_stats_locked) ? '#' : 'tracks.php?user_id='.$profile_id.'&type=following'; ?>" class="rp-stat"
               <?php if($blocked_check) echo 'onclick="alert(\'You are blocked, you cannot view this.\'); return false;"';
                     elseif($dz_stats_locked) echo 'onclick="alert(\'Track this Peumer to view their Trackings.\'); return false;"'; ?>>
                <span class="rp-stat-num<?php echo $dz_stats_locked ? ' blur' : ''; ?>"><?php echo shortNum($following_count); ?></span>
                <span class="rp-stat-lbl">Tracking</span>
            </a>
            <!-- Pods: gets the wider gap ONLY when the Posts stat sits right beside it (no pills) -->
            <a href="<?php echo (!$blocked_check && !$dz_pods_locked) ? 'group_list.php?user_id='.$profile_id : '#'; ?>" id="rpStatPods" class="rp-stat<?php echo !$has_any_pill ? ' rp-stat-wide' : ''; ?>"
               <?php if($blocked_check) echo 'onclick="alert(\'You are blocked, you cannot view this.\'); return false;"';
                     elseif($dz_pods_locked) echo 'onclick="alert(\'Track this Peumer to view their pods\'); return false;"'; ?>>
                <span class="rp-stat-num<?php echo $dz_pods_locked ? ' blur' : ''; ?>"><?php echo shortNum($pod_count); ?></span>
                <span class="rp-stat-lbl">Pods</span>
            </a>
            <?php if(!$has_any_pill): ?>
            <div class="rp-stat dz-ptr" id="rpStatPosts" onclick="dzGoToPosts()" role="button" aria-label="Go to posts">
                <span class="rp-stat-num"><?php echo shortNum($post_count); ?></span>
                <span class="rp-stat-lbl">Posts</span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="rp-avatar-wrap">
        <img src="<?= htmlspecialchars($profilePic) ?>" alt="Profile" id="profilePic" fetchpriority="high" decoding="async" class="rp-avatar" onerror="this.onerror=null;this.src='mee.jpg';" onclick="openPicModal()">
        <?php if($current_user != $profile_id && !empty($profile_online)): ?>
        <span class="rp-online-dot" title="Online" aria-label="Online"></span>
        <?php endif; ?>
        <?php
        /* Vibe Check nudge: 3 filled red vibe icons around the picture, only while the viewer
           has NOT given this profile any vibe (and is actually allowed to). Shown on every load. */
        $dz_vb_nudge = false;
        if (!empty($pf_can_vibe) && !empty($follow_check) && (int)$current_user > 0 && (int)$current_user !== (int)$profile_id) {
            try { $dz_vbm = dz_vibe_results((int)$profile_id, (int)$current_user); $dz_vb_nudge = ((string)($dz_vbm['mine'] ?? '') === ''); } catch (Throwable $e) { $dz_vb_nudge = false; }
        }
        if ($dz_vb_nudge):
            $dz_vb_svg = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l1.8 4.2L18 9l-4.2 1.8L12 15l-1.8-4.2L6 9l4.2-1.8L12 3z"/><path d="M5 16l.8 2.2L8 19l-2.2.8L5 22l-.8-2.2L2 19l2.2-.8L5 16z"/><path d="M19 14l1 2.5L22.5 17 20 18l-1 2.5L18 18l-2.5-1 2.5-.5L19 14z"/></svg>';
            for ($__i = 1; $__i <= 3; $__i++): ?>
        <button type="button" class="dz-vbn dz-vbn<?php echo $__i; ?>" aria-label="Give a vibe" onclick="event.stopPropagation(); vibeCheck(<?php echo (int)$profile_id; ?>)"><?php echo $dz_vb_svg; ?></button>
        <?php endfor; endif; ?>
        <?php if($current_user == $profile_id): ?>
        <button id="plusTriggerBtn" class="rp-avatar-plus" onclick="event.stopPropagation(); pmOpenMain()" aria-label="Quick actions">
            <svg viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M6 1.2V10.8M1.2 6H10.8" stroke="#05061a" stroke-width="3.3" stroke-linecap="round"/>
            </svg>
        </button>
        <?php endif; ?>
    </div>

</div>

<?php if($has_any_pill): ?>
<div class="rp-pills" id="rpPills">
    <div class="rp-pills-top">
        <?php if($has_pronouns): ?>
        <div class="rp-pill pronouns"><span class="rp-pill-val"><?php echo htmlspecialchars($user['pronouns']); ?></span></div>
        <?php endif; ?>
        <?php if($has_type): ?>
        <div class="rp-pill type"><span class="rp-pill-val"><?php echo htmlspecialchars($user['user_type']); ?></span></div>
        <?php endif; ?>
        <button type="button" class="rp-post-count" onclick="dzGoToPosts()" aria-label="Go to posts"><b><?php echo shortNum($post_count); ?></b> Posts</button>
    </div>
    <?php if($has_vibe): ?>
    <div class="rp-pill active-hours"><span class="rp-pill-lbl">A.H :</span><span class="rp-pill-val"><?php echo htmlspecialchars($activity_vibe); ?></span></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php
/* ── PROFILE TYPE — shows whether or not the viewer is tracking.
   Always rendered (hidden when empty) so a change made in the profile modal can appear instantly. ── */
?>
<?php $pt_href = 'profile_type.php' . (($current_user == $profile_id) ? '' : '?user_id=' . (int)$profile_id); ?>
<div class="rp-profile-type is-link<?php echo empty($profile_type) ? ' is-empty' : ''; ?>" id="rpProfileType"<?php echo empty($profile_type) ? ' style="display:none;"' : ''; ?> role="link" tabindex="0" data-href="<?php echo htmlspecialchars($pt_href, ENT_QUOTES); ?>" onclick="location.href=this.dataset.href" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();location.href=this.dataset.href;}"><?php echo htmlspecialchars($profile_type); ?></div>

<?php if($show_bio || $current_user == $profile_id): ?>
<?php /* Bio: URLs / emails → purple links, @username / @nickname → red link to that profile.
         dz_bio_block_html() takes the RAW text and escapes it itself.
         The owner always gets the box (hidden while empty) so a new bio can appear instantly. */ ?>
<div class="rp-bio" id="rpBio"<?php echo (trim((string)($user['bio'] ?? '')) === '') ? ' style="display:none;"' : ''; ?>>
    <?= dz_bio_block_html((string)($user['bio'] ?? '')) ?>
</div>
<?php endif; ?>
<script>
/* Bio "...more": keep the "more" button ON the last line of the bio text, never wrapped onto a new line under it.
   If "...more" would fall onto its own line, the last few characters of the visible text are moved into the hidden
   rest (they show again when "more" is tapped) until "...more" fits. It is redone whenever the width changes
   (rotation, responsive view, window resize), so it also works after resizing. */
(function(){
    var movedNode = null;
    function lastTextNode(root){
        var w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null), n, last = null;
        while((n = w.nextNode())){
            if(n.parentNode && n.parentNode.id === 'rp-bio-dots') continue;
            if(n.nodeValue && n.nodeValue.replace(/\s+/g, '') !== '') last = n;
        }
        return last;
    }
    function undo(short, rest){
        if(movedNode && movedNode.parentNode === rest){
            var tn = lastTextNode(short);
            if(tn) tn.nodeValue += movedNode.nodeValue;
            rest.removeChild(movedNode);
        }
        movedNode = null;
    }
    function fit(){
        var short = document.getElementById('rp-bio-short');
        var more  = document.getElementById('rp-bio-more');
        var rest  = document.getElementById('rp-bio-rest');
        if(!short || !more || !rest) return;
        undo(short, rest);
        if(more.style.display === 'none' || more.offsetParent === null) return;
        var moved = '', guard = 0;
        while(guard++ < 600){
            var tn = lastTextNode(short);
            if(!tn) break;
            var len = tn.nodeValue.length;
            if(len < 1) break;
            var r = document.createRange();
            r.setStart(tn, len - 1); r.setEnd(tn, len);
            var rc = r.getClientRects();
            if(!rc.length){ tn.nodeValue = tn.nodeValue.slice(0, -1); moved = ' ' + moved; continue; }
            var last = rc[rc.length - 1];
            var moreTop = more.getBoundingClientRect().top;
            if(moreTop - last.top < (last.height || 8) * 0.6) break;   /* already on the last line */
            moved = tn.nodeValue.charAt(len - 1) + moved;
            tn.nodeValue = tn.nodeValue.slice(0, -1);
        }
        if(moved){
            var tn2 = lastTextNode(short);
            if(tn2) tn2.nodeValue = tn2.nodeValue.replace(/\s+$/, function(sp){ moved = sp + moved; return ''; });
            movedNode = document.createTextNode(moved);
            rest.insertBefore(movedNode, rest.firstChild);
        }
    }
    var box = document.getElementById('rpBio');
    var busy = false;
    function show(){ if(box) box.classList.remove('dz-bio-fitting'); }
    function now(){ if(busy) return; busy = true; try { fit(); } finally { busy = false; } }
    window.dzBioFit = function(){ movedNode = null; now(); };
    window.dzBioRefit = function(){ now(); };   /* called right after more / close is tapped */
    /* first paint: "more" stays invisible (it still holds its place) until it has been put on the last line,
       so it can never be seen sitting under the text, not even for a split second */
    if(box) box.classList.add('dz-bio-fitting');
    now();
    var done = false;
    function settle(){ if(done) return; done = true; now(); show(); }
    if(document.fonts && document.fonts.ready) document.fonts.ready.then(function(){ now(); settle(); }); else window.addEventListener('load', settle);
    window.addEventListener('load', function(){ now(); settle(); });
    setTimeout(settle, 1500);   /* safety net: never leave "more" hidden */
    /* any later width change is re-fitted immediately (synchronously, before the browser paints) */
    /* when the bio is open, tapping the bio text itself closes it (links and the more / close words keep their own job) */
    if(box) box.addEventListener('click', function(e){
        var rest = document.getElementById('rp-bio-rest'), less = document.getElementById('rp-bio-less');
        if(!rest || !less || rest.offsetParent === null) return;
        if(e.target.closest('a') || e.target.closest('.rp-bio-toggle')) return;
        less.click();
    });
    window.addEventListener('resize', now);
    window.addEventListener('orientationchange', now);
    if(window.ResizeObserver && box) new ResizeObserver(function(){ now(); }).observe(box);
})();
</script>

<!-- ══════════════════════════════════════
     PROFILE LINKS (directly below the bio) — same colour as the bio
     1 link  → clicking goes straight to that URL
     2 links → each row clicks straight to its own URL
     3+ links → clicking opens the full links modal
══════════════════════════════════════ -->
<?php if ($profile_link_count > 0): ?>

    <?php if ($profile_link_count == 1): ?>
    <div class="plp-wrap">
        <a class="plp-row" href="<?= htmlspecialchars($profile_links[0]['url']) ?>" target="_blank" rel="noopener">
            <i class="ri-link-m"></i>
            <span class="plp-text"><?= htmlspecialchars($profile_links[0]['url']) ?></span>
        </a>
    </div>

    <?php elseif ($profile_link_count == 2): ?>
    <div class="plp-wrap">
        <a class="plp-row" href="<?= htmlspecialchars($profile_links[0]['url']) ?>" target="_blank" rel="noopener">
            <i class="ri-link-m"></i>
            <span class="plp-text"><?= htmlspecialchars($profile_links[0]['url']) ?></span>
        </a>
        <a class="plp-row" href="<?= htmlspecialchars($profile_links[1]['url']) ?>" target="_blank" rel="noopener">
            <i class="ri-link-m"></i>
            <span class="plp-text"><?= htmlspecialchars($profile_links[1]['url']) ?></span>
        </a>
    </div>

    <?php else: ?>
    <div class="plp-wrap" onclick="openProfileLinksModal()">
        <div class="plp-row">
            <i class="ri-link-m"></i>
            <span class="plp-text"><?= htmlspecialchars($profile_links[0]['url']) ?></span>
        </div>
        <div class="plp-row">
            <i class="ri-link-m"></i>
            <span class="plp-text"><?= htmlspecialchars($profile_links[1]['url']) ?></span>
            <span class="plp-more">+<?= $profile_link_count - 2 ?> more</span>
        </div>
    </div>
    <?php endif; ?>

<?php endif; ?>



<?php
$dz_loc_hidden = false;   // the owner can switch the location off from About me (users.hide_location, shown by default)
try { $lvq = $conn->query("SELECT hide_location FROM users WHERE id = " . (int)$profile_id . " LIMIT 1"); if ($lvq instanceof mysqli_result && ($lvr = $lvq->fetch_assoc())) $dz_loc_hidden = !empty($lvr['hide_location']); } catch (Throwable $e) {}
$dz_loc_txt = (!$dz_hide_extra) ? dz_about_location($conn, $profile_id) : '';
/* the owner always gets the row (hidden while switched off) so the About me switch can show/hide it live; visitors only get it when it is on */
if ($dz_loc_txt !== '' && (!$dz_loc_hidden || $current_user == $profile_id)): ?>
<?php $dz_loc_me = ($current_user && $current_user != $profile_id) ? dz_about_location($conn, $current_user) : ''; ?>
<div class="plp-loc"<?= $dz_loc_hidden ? ' hidden' : '' ?> role="button" tabindex="0" data-loc="<?= htmlspecialchars($dz_loc_txt) ?>" data-me="<?= htmlspecialchars($dz_loc_me) ?>" data-own="<?= ($current_user == $profile_id) ? '1' : '0' ?>" onclick="dzLocOpen(this)" onkeydown="if(event.key==='Enter')dzLocOpen(this)"><i class="ri-map-pin-2-line"></i><span><?= htmlspecialchars($dz_loc_txt) ?></span><i class="ri-arrow-right-s-line plp-loc-chev"></i></div>
<?php endif; ?>

<?php /* Portfolio link — only when this person's portfolio is set to public. "My Portfolio Page" for visitors, "Your Portfolio Page" on your own profile. */
$dz_pf_public = false;
if (!$dz_hide_extra && empty($DZ_SUSP) && empty($is_blocked) && empty($is_blocked_by_owner)) {
    try { $pfq = $conn->query("SELECT is_public FROM portfolios WHERE user_id = " . (int)$profile_id . " LIMIT 1"); if ($pfq instanceof mysqli_result && ($pfr = $pfq->fetch_assoc())) $dz_pf_public = !empty($pfr['is_public']); } catch (Throwable $e) { $dz_pf_public = false; }
}
if ($dz_pf_public): ?>
<a class="plp-pf" href="portfolio.php?user_id=<?= (int)$profile_id ?>"><span class="plp-pf-in"><span>View Portfolio Page</span><i class="ri-arrow-right-up-line"></i></span></a>
<?php endif; ?>

<?php if($current_user == $profile_id): ?>
<?php ob_start(); include 'profile_crossroads.php'; $dz_strip_out = trim((string)ob_get_clean()); ?>
<?php if ($dz_strip_out !== ''): ?>
<div class="rp-strip-wrap">
    <div class="rp-strip-section">
        <?php echo $dz_strip_out; ?>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php /* Mutuals reveal who tracks this profile, so they follow the same rule as the Tracker / Tracking counts */ ?>
<?php if($current_user != $profile_id && !$is_blocked && !$is_blocked_by_owner && !$dz_stats_locked): ?>
<?php ob_start(); include 'profile_mutuals.php'; $dz_strip_out = trim((string)ob_get_clean()); ?>
<?php if ($dz_strip_out !== ''): ?>
<div class="rp-strip-wrap">
    <div class="rp-strip-section">
        <?php echo $dz_strip_out; ?>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php
/* Shop Now — shown under the action buttons when this profile has at least one active shop product
   (other users only — the owner opens their shop from the kebab menu; hidden from blocked viewers and, on private profiles, from non-trackers). */
$dz_has_shop = false;
$dz_shops = [];                 // the profile's visible shops (up to 5) — same rules as profile_shop.php
$dz_shop_param = 'shop';        // query-string name profile_shop.php reads to pick a shop
if ($current_user != $profile_id && empty($is_blocked) && empty($is_blocked_by_owner) && empty($dz_stats_locked)) {
    try {
        $shr = $conn->query("SELECT id, shop_name, avatar FROM shops WHERE user_id = " . (int)$profile_id . " AND is_active = 1 ORDER BY id ASC LIMIT 5");
        if ($shr instanceof mysqli_result) {
            $shi = 0;
            while ($shw = $shr->fetch_assoc()) {
                $shn = trim((string)($shw['shop_name'] ?? ''));
                if ($shn === '') $shn = ($shi === 0) ? (string)($user['username'] ?? 'Shop') : 'Shop ' . ($shi + 1);   // same naming as the shop page
                $dz_shops[] = [
                    'id'     => (int)$shw['id'],
                    'name'   => $shn,
                    'avatar' => !empty($shw['avatar']) ? 'uploads/products/' . basename((string)$shw['avatar']) : '',   // the shop's own picture
                ];
                $shi++;
            }
        }
    } catch (Throwable $e) { $dz_shops = []; }
    try {   // Shop Now still needs at least one active product, like before
        $shq = $conn->prepare("SELECT 1 FROM products WHERE user_id = ? AND is_active = 1 LIMIT 1");
        if ($shq) {
            $shq->bind_param('i', $profile_id);
            $shq->execute();
            $shq->store_result();
            $dz_has_shop = $shq->num_rows > 0;
            $shq->close();
        }
    } catch (Throwable $e) { $dz_has_shop = false; }
    if (!$dz_has_shop) $dz_shops = [];
}
?>
<?php $dz_email_reveal = ($dz_has_shop && !empty($profile_emails)); /* Shop takes Email's slot; Email moves behind a chevron square */ ?>
<div class="rp-actions<?php echo $dz_email_reveal ? ' has-shop' : ''; ?>">

<?php if($current_user == $profile_id): ?>
    <?php if($has_posts): ?>
    <!-- Has posts / memes → Dashboard (replaces Edit Picture) -->
    <a href="profile_dashboard.php" class="rp-rect-btn pic">Dashboard</a>
    <?php else: ?>
    <button type="button" class="rp-rect-btn pic" onclick="epOpenModal()">Edit Picture</button>
    <?php endif; ?>
    <a href="untracked.php" class="rp-rect-btn untracked"><span class="rp-untr-txt">Untracked<?php if($untracked_dot): ?><span class="rp-untr-dot" aria-label="New activity"></span><?php endif; ?></span></a>
    <?php dz_render_email_btn($profile_emails); /* Email — same size as Edit Picture / Untracked; 2+ links → chevron dropdown */ ?>
    <?php /* spinning wheel shows on this button while the About modal loads (see dzAboutBtn in the script at the bottom) */ ?>
    <button type="button" class="rp-square-btn" id="rpAboutBtn" onclick="dzAboutBtn(this, <?php echo (int)$current_user; ?>)" aria-label="About me" title="About">
        <i class="tab-ic tab-ic-userstar"></i>
    </button>

<?php else: ?>
    <form method="POST" id="rpTrackForm">
    <?php echo dz_csrf_field(); ?>
    <?php if($follow_check): ?>
        <div class="rp-rect-btn track has-chevron" onclick="openMrSheet(event)" role="button">
            <span id="untrackMainBtn">Untrack</span>
            <i class="ri-arrow-down-s-line" id="untrackChevron"></i>
        </div>
    <?php elseif($profile_is_private): ?>
        <?php if($track_request_status === 'pending'): ?>
        <!-- Requested → tap to cancel the request -->
        <button type="button" class="rp-rect-btn track pending" onclick="dzCancelRequest(this)">Requested</button>
        <?php else: ?>
        <!-- Request to Track → posts in the background, then flips straight to "Requested" -->
        <button type="button" class="rp-rect-btn track" onclick="dzRequestTrack(this)">Request to Track</button>
        <?php endif; ?>
    <?php else: ?>
        <button type="submit" name="follow" class="rp-rect-btn track" onclick="showSpinner(this);">
            Track
        </button>
    <?php endif; ?>
    </form>

    <?php /* MESSAGE — only when the owner allows messages (Privacy Controls) and nobody is blocked.
             Public: anyone can use it. Limited / Private / Full Private: it appears after you track them
             (hidden now, shown by the page the moment tracking succeeds). */ ?>
    <?php if(!$is_blocked && !$is_blocked_by_owner && $dz_msg_enabled): ?>
    <a href="messages.php?user=<?php echo (int)$profile_id; ?>"
       id="rpMessageBtn"
       class="rp-rect-btn message"
       style="<?php echo $dz_msg_open ? '' : 'display:none;'; ?>">
        Message
    </a>
    <?php endif; ?>

    <?php if($is_blocked): ?>
        <!-- You've blocked them → always Unblock -->
        <form method="POST" class="rp-block-form">
            <?php echo dz_csrf_field(); ?>
            <button type="submit" name="unblock" class="rp-rect-btn unblock">Unblock</button>
        </form>
    <?php elseif($dz_has_shop): ?>
        <!-- Profile has a shop → Shop takes the Email / Block slot. Email (if any) hides behind the chevron square. -->
        <?php if (count($dz_shops) > 1): ?>
        <button type="button" class="rp-rect-btn shop" onclick="dzShopSheetOpen()" aria-haspopup="dialog">Shop Now</button>
        <?php elseif (count($dz_shops) === 1): ?>
        <a href="profile_shop.php?user_id=<?php echo (int)$profile_id; ?>&amp;<?php echo $dz_shop_param; ?>=<?php echo (int)$dz_shops[0]['id']; ?>" class="rp-rect-btn shop">Shop Now</a>
        <?php else: ?>
        <a href="profile_shop.php?user_id=<?php echo (int)$profile_id; ?>" class="rp-rect-btn shop">Shop Now</a>
        <?php endif; ?>
        <?php if(!empty($profile_emails)): ?>
        <button type="button" class="rp-square-btn rp-email-sq" id="rpEmailSq" onclick="dzToggleEmailReveal(event)" aria-label="Contact" aria-expanded="false" title="Contact">
            <i class="ri-arrow-down-s-line"></i>
        </button>
        <?php endif; ?>
    <?php elseif(!empty($profile_emails)): ?>
        <!-- Profile has email link(s) → Email replaces Block.
             1 email → plain button. 2+ emails → filled chevron + dropdown of all of them. -->
        <?php dz_render_email_btn($profile_emails); ?>
    <?php else: ?>
        <!-- No email → stays Block -->
        <form method="POST" class="rp-block-form">
            <?php echo dz_csrf_field(); ?>
            <button type="submit" name="block" class="rp-rect-btn block" data-name="<?php echo htmlspecialchars((string)($user['username'] ?? ''), ENT_QUOTES); ?>" onclick="return dzBlockAsk(event,this)">Block</button>
        </form>
    <?php endif; ?>


<?php endif; ?>

</div>
<?php if($dz_email_reveal): ?>
<div class="rp-emailrow" id="rpEmailRow">
    <?php dz_render_email_btn($profile_emails); /* the Email button — opens the email(s) */ ?>
</div>
<?php endif; ?>

<?php /* ── COMPLETE YOUR PROFILE — own profile only, sits under the action buttons.
         Shown while 2 or more of the 7 profile fields are empty (hidden when complete / mostly complete).
         ✕ removes it (remembered in this browser). "Edit profile" opens the Profile modal on the Nickname field. ── */
if ($current_user == $profile_id):
    $cp_pic_ok = !empty($user['profile_pic']) && basename((string)$user['profile_pic']) !== 'mee.jpg' && basename((string)$user['profile_pic']) !== 'default.jpg';
    $cp_fields = [
        $cp_pic_ok,
        trim((string)($user['nickname'] ?? '')) !== '',
        trim((string)($user['bio'] ?? '')) !== '',
        trim((string)($user['pronouns'] ?? '')) !== '',
        trim((string)$profile_type) !== '',
        trim((string)($user['user_type'] ?? '')) !== '',
        trim((string)$activity_vibe) !== '',
    ];
    $cp_done = count(array_filter($cp_fields));
    if (count($cp_fields) - $cp_done >= 2):
?>
<div class="rp-complete" id="rpComplete" data-uid="<?php echo (int)$profile_id; ?>" hidden>
    <button type="button" class="rp-complete-x" onclick="rpCompleteDismiss()" aria-label="Remove"><i class="ri-close-line"></i></button>
    <div class="rp-complete-t">Complete your profile</div>
    <div class="rp-complete-s"><?php echo (int)$cp_done; ?> of <?php echo count($cp_fields); ?> done — add the rest so people get to know you</div>
    <div class="rp-complete-btns">
        <button type="button" class="rp-rect-btn" onclick="rpCompleteEdit()">Edit profile</button>
        <button type="button" class="rp-rect-btn" onclick="shareProfile(<?php echo (int)$profile_id; ?>)">Share profile</button>
    </div>
</div>
<script>
(function(){
    var box = document.getElementById('rpComplete'); if(!box) return;
    var key = 'dzCompleteHidden_' + box.getAttribute('data-uid'), gone = false;
    try { gone = localStorage.getItem(key) === '1'; } catch(e){}
    if(!gone) box.hidden = false;
    window.rpCompleteDismiss = function(){
        box.hidden = true;
        try { localStorage.setItem(key, '1'); } catch(e){}
    };
    window.rpCompleteEdit = function(){
        if(typeof pmOpenMain !== 'function') return;
        pmOpenMain();
        setTimeout(function(){ if(typeof pmOpenNested === 'function') pmOpenNested('pmEditNickname'); }, 320);
    };
})();
</script>
<?php endif; endif; ?>

<!-- ══════════════════════════════════════
     HIGHLIGHTS — square tiles directly under the action buttons.
     Created in Settings → Highlights. Horizontally scrollable.
     Grey ring → gap → cover picture centered. Pressing a tile runs the
     Instagram-style arc around the ring, then calls
     openHighlightsModal(highlightId) — the "Highlights modal"
     (you build that modal yourself; NOT included).
══════════════════════════════════════ -->
<?php if ($profile_highlight_count > 0): ?>
<div class="rp-hl-wrap" id="rpHlWrap">
<button type="button" class="rp-hl-arrow left"  id="rpHlLeft"  aria-label="Scroll highlights left"><i class="ri-arrow-left-s-line"></i></button>
<button type="button" class="rp-hl-arrow right" id="rpHlRight" aria-label="Scroll highlights right"><i class="ri-arrow-right-s-line"></i></button>
<?php $hl_batch = 8; $hl_n = 0; ?>
<div class="rp-highlights" id="rpHighlights" data-batch="<?= (int)$hl_batch ?>">
    <?php foreach ($profile_highlights as $hl):
        /* cover rule: custom cover art (Settings) → otherwise the first media.
           (also understands the older row shape where cover_path/cover_type = first media) */
        if (isset($hl['first_path'])) {
            $hl_src  = !empty($hl['cover_path']) ? $hl['cover_path'] : $hl['first_path'];
            $hl_vid  = empty($hl['cover_path']) && (($hl['first_type'] ?? '') === 'video');
        } else {
            $hl_src  = $hl['cover_path'] ?? '';
            $hl_vid  = (($hl['cover_type'] ?? '') === 'video');
        }
        $hl_hold = ($hl_n >= $hl_batch);   // 9th tile onwards load later, 8 at a time
        $hl_n++;
    ?>
    <button type="button" class="rp-hl<?= $hl_hold ? ' rp-hl-hold' : '' ?>" data-hl-id="<?= (int)$hl['id'] ?>"
            aria-label="Open highlight <?= htmlspecialchars($hl['title']) ?>"
            onclick="dzOpenHighlight(this, <?= (int)$hl['id'] ?>)">
        <span class="rp-hl-ring">
            <span class="rp-hl-sq">
                <?php if ($hl_vid): ?>
                    <?php if ($hl_hold): ?>
                    <video data-src="<?= htmlspecialchars($hl_src) ?>#t=0.1" muted playsinline preload="none"></video>
                    <?php else: ?>
                    <video src="<?= htmlspecialchars($hl_src) ?>#t=0.1" muted playsinline preload="metadata"></video>
                    <?php endif; ?>
                <?php else: ?>
                    <?php if ($hl_hold): ?>
                    <img data-src="<?= htmlspecialchars($hl_src) ?>" alt="" onerror="this.onerror=null;this.src='mee.jpg'">
                    <?php else: ?>
                    <img src="<?= htmlspecialchars($hl_src) ?>" alt="" loading="lazy" onerror="this.onerror=null;this.src='mee.jpg'">
                    <?php endif; ?>
                <?php endif; ?>
            </span>
            <svg class="rp-hl-svg" viewBox="0 0 100 100" aria-hidden="true" focusable="false"><rect x="2.68" y="2.68" width="94.64" height="94.64" rx="20" ry="20" pathLength="100"/></svg>
        </span>
        <span class="rp-hl-name"><?= htmlspecialchars($hl['title']) ?></span>
    </button>
    <?php endforeach; ?>
</div>
</div><!-- /.rp-hl-wrap -->
<?php endif; ?>

<?php if (!empty($DZ_SUSP)): $S = $DZ_SUSP; $__e = function($t){ return htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8'); }; ?>
<style>
/* suspended account: same page, but only the top bar, the grey picture, username + nickname and the notice below */
.pf-shell.dz-susp #dzTopReal > *:not(.rp-header):not(.dz-susp-note){ display:none !important; }
.pf-shell.dz-susp .rp-header .rp-pills,
.pf-shell.dz-susp .rp-header .rp-profile-type,
.pf-shell.dz-susp .rp-header .rp-bio,
.pf-shell.dz-susp .rp-header .rp-avatar-plus,
.pf-shell.dz-susp .rp-header .rp-online-dot,
.pf-shell.dz-susp .rp-header .rp-vibe-nudge,
.pf-shell.dz-susp .rp-header .rp-actions,
.pf-shell.dz-susp #rpVibeBanner,
.pf-shell.dz-susp .dz-va-strip,
.pf-shell.dz-susp .rp-tabs, .pf-shell.dz-susp #rpTabs, .pf-shell.dz-susp #dzInfoPanel,
.pf-shell.dz-susp #rpSortDock, .pf-shell.dz-susp #rpPager, .pf-shell.dz-susp .rp-track,
.pf-shell.dz-susp .dropdown-section, .pf-shell.dz-susp .rp-topbar .rp-tb-track-wrap{ display:none !important; }
.pf-shell.dz-susp .rp-avatar{ background:rgba(var(--fg,255,255,255),.1) !important; }
.pf-shell.dz-susp .rp-avatar-wrap > *:not(.rp-avatar){ display:none !important; }
.pf-shell.dz-susp .rp-username-row{ pointer-events:none; }
.pf-shell.dz-susp .rp-stats-row, .pf-shell.dz-susp .rp-stats-row *{ pointer-events:none !important; cursor:default !important; }
.dz-susp-note{ padding:calc(22px * var(--hd-s,1)) var(--rp-header-pad-x,16px) calc(40px * var(--hd-s,1)); color:rgba(var(--fg,255,255,255),.88); }
.dz-susp-note .sp-box{ padding:0; }
.dz-susp-note h3.sp-red{ color:#FF2D2D; font-size:1.05rem; }
.dz-susp-note a.sp-learn{ width:fit-content; margin:8px 0 0; }
.dz-susp-note .sp-box[style*="center"] a.sp-learn{ margin:8px auto 0; }
.dz-susp-note h3{ font-size:.95rem; font-weight:700; margin:0 0 6px; color:rgba(var(--fg,255,255,255),.96); }
.dz-susp-note h4{ font-size:.82rem; font-weight:700; margin:16px 0 4px; color:rgba(var(--fg,255,255,255),.96); }
.dz-susp-note p{ font-size:.8rem; line-height:1.55; margin:0 0 8px; color:rgba(var(--fg,255,255,255),.72); }
.dz-susp-note .sp-date{ font-size:.72rem; color:rgba(var(--fg,255,255,255),.5); }
.dz-susp-note .sp-btn, .dz-susp-note a.sp-learn{
    display:flex; align-items:center; justify-content:center; box-sizing:border-box;
    height:var(--rp-action-h); line-height:1; border:none; border-radius:var(--rp-action-radius);
    font-family:inherit; font-size:calc(clamp(.58rem, .5rem + .6vw, .72rem) * var(--hd-s) * var(--act-t)); font-weight:600;
    padding:0 calc(clamp(7px, 1vw + 4px, 10px) * var(--hd-s)); cursor:pointer; text-decoration:none; white-space:nowrap;
    transition:transform .15s ease, opacity .2s ease;
}
.dz-susp-note .sp-btn{ width:100%; margin-top:10px; }
.dz-susp-note .sp-btn:active, .dz-susp-note a.sp-learn:active{ transform:scale(.98); }
.dz-susp-note .sp-btn.primary, .dz-susp-note .sp-btn.ghost, .dz-susp-note a.sp-learn{ background:#FF2D2D; color:#000 !important; }   /* red buttons, black words */
.dz-susp-note .sp-btn.green{ background:#4dde8a; color:#000; }
.dz-susp-note .sp-btn:disabled{ opacity:.5; }
.dz-susp-note .sp-form{ display:none; margin-top:10px; }
.dz-susp-note .sp-form.open{ display:block; }
.dz-susp-note textarea{ width:100%; min-height:100px; border-radius:8px; border:1px solid rgba(var(--fg,255,255,255),.18); background:rgba(var(--fg,255,255,255),.05); color:inherit; padding:10px 12px; font-size:.82rem; resize:vertical; outline:none; font-family:inherit; }
.dz-susp-note .sp-err{ color:#FF7A85; font-size:.74rem; margin-top:6px; min-height:1em; }
</style>
<div class="dz-susp-note" id="dzSuspNote">
<?php if ($S['own']): ?>
  <div class="sp-box">
  <?php if ($S['pending']): ?>
    <h3>You submitted an appeal on <?= $__e(date('j F Y', strtotime($S['appeal_at']))) ?></h3>
    <p style="font-style:italic">It usually takes us just over a day to review your information. Check again here.</p>
    <p>Your account is not visible to people on Dezzme and you can't use it.</p>
    <h4>What happens next?</h4>
    <p>We'll take another look at your account.</p>
    <p>If we find that your account does follow our Community Guidelines, you'll be able to use Dezzme again.</p>
    <p>If we find that your account doesn't follow our Community Guidelines, it will be permanently disabled and you won't be able to appeal again.</p>
  <?php elseif ($S['rejected'] || $S['expired']): ?>
    <h3>Your account has been disabled</h3>
    <p><?= $S['rejected'] ? "We reviewed your appeal and your account stays permanently disabled. You can't appeal again." : "The time to appeal has ended, so your account is permanently disabled." ?></p>
    <?php if ($S['ts']): ?><div class="sp-date">Suspended on <?= $__e(date('F j, Y', $S['ts'])) ?></div><?php endif; ?>
  <?php else: ?>
    <h3 class="sp-red">We suspended your account</h3>
    <p><?= (int)$S['left'] ?> day<?= $S['left'] === 1 ? '' : 's' ?> left to appeal or we'll permanently disable your account</p>
    <?php if ($S['ts']): ?><div class="sp-date">Suspended on <?= $__e(date('F j, Y', $S['ts'])) ?></div><?php endif; ?>
    <h4>Why this happened</h4>
    <p>Your account didn't follow the Dezzme Community Guidelines.</p>
    <a class="sp-learn" href="guidelines.php">Learn more</a>
    <h4>What this means</h4>
    <p>Your account is not visible to people on Dezzme right now, and you cannot use it.</p>
    <h4>What you can do</h4>
    <p>If you think we made a mistake, you can appeal. We'll take another look at your account.</p>
    <button type="button" class="sp-btn primary" id="spOpen" onclick="document.getElementById('spForm').classList.add('open');this.style.display='none'">Appeal</button>
    <form class="sp-form" id="spForm" onsubmit="return dzSpSend(event)">
      <textarea id="spMsg" maxlength="1000" placeholder="Tell us why you think this was a mistake (optional)"></textarea>
      <div class="sp-err" id="spErr"></div>
      <button type="submit" class="sp-btn primary" id="spSub">Submit appeal</button>
    </form>
  <?php endif; ?>
    <a class="sp-btn ghost" href="logout.php">Log out</a>
  </div>
<?php else: ?>
  <div class="sp-box" style="text-align:center">
    <h3 class="sp-red">This account has been suspended</h3>
    <p>This account didn't follow Dezzme's Community Guidelines.</p>
    <a class="sp-learn" href="guidelines.php">Learn more</a>
    <?php if ($S['admin']): ?>
      <div style="margin-top:18px">
        <div class="sp-date" style="margin-bottom:8px">Admin view<?= $S['ts'] ? ' · Suspended on ' . $__e(date('F j, Y', $S['ts'])) : '' ?></div>
        <button type="button" class="sp-btn green" id="spUnsus">Unsuspend</button>
        <a class="sp-btn ghost" href="profile.php?user_id=<?= (int)$profile_id ?>&amp;admin_peek=1">View profile anyway</a>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>
</div>
<script>
(function(){
  /* the Menu button does nothing on a suspended account */
  document.addEventListener('click', function(e){
    var m = e.target.closest && e.target.closest('.rp-tb-btn[title="Menu"], .rp-username-row');
    if (m) { e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation(); }
  }, true);
  var u = document.getElementById('spUnsus');
  if (u) u.onclick = function(){
    var b=this; b.disabled=true;
    fetch('report_center.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'rc_action=unsuspend_user&rtype=user&rid=0&uid=<?= (int)$profile_id ?>'})
      .then(function(r){return r.text();}).then(function(t){
        var d=null; try{ var i=t.indexOf('{'); d=JSON.parse(i>0?t.slice(i):t); }catch(e){}
        if(d&&d.ok) location.reload(); else { b.disabled=false; alert((d&&d.msg)||('Could not unsuspend: '+t.replace(/<[^>]*>/g,' ').trim().slice(0,160))); }
      }).catch(function(){ b.disabled=false; alert('Network error'); });
  };
})();
function dzSpSend(ev){
  ev.preventDefault();
  var b=document.getElementById('spSub'), er=document.getElementById('spErr'); b.disabled=true; er.textContent='';
  var t=(window.DZ_CSRF||(document.querySelector('meta[name="csrf-token"]')||{}).content||'');
  var body='action=submit_appeal&csrf_token='+encodeURIComponent(t)+'&message='+encodeURIComponent(document.getElementById('spMsg').value);
  fetch('profile.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':t},body:body})
    .then(function(r){return r.json();}).then(function(d){ if(d&&d.ok) location.reload(); else { er.textContent=(d&&d.error)||'Could not send your appeal. Try again.'; b.disabled=false; } })
    .catch(function(){ er.textContent='Network error. Try again.'; b.disabled=false; });
  return false;
}
</script>
<?php endif; ?>
</div><!-- /#dzTopReal -->

<div id="dzTabsSentinel" class="dz-sent1"></div>

<?php
/* Videos tab: only for people VIEWING someone else's page, and only when that person has long form videos (never on your own page) */
$dz_has_videos = false;
if ($current_user && $current_user != $profile_id && empty($show_private_wall) && empty($is_blocked_by_owner) && empty($is_blocked)) {
    try {
        if ($__vq = $conn->prepare("SELECT 1 FROM longform_posts WHERE user_id = ? AND status = 'published' LIMIT 1")) {
            $__vq->bind_param('i', $profile_id); $__vq->execute();
            $dz_has_videos = (bool)$__vq->get_result()->fetch_row(); $__vq->close();
        }
    } catch (Throwable $e) { $dz_has_videos = false; }
}
?>
<?php /* every tab that opens a swipeable panel carries data-panel="<section id>" — About (a modal) does not */ ?>
<div class="rp-tabs" id="rpTabs">
  <div class="rp-tabs-track" id="rpTabsTrack">
    <?php if($current_user == $profile_id): ?>
        <button class="rp-tab-btn active" id="tabMemes" data-panel="memes" title="Posts" onclick="rpSelectTab(this,'memes')"><span class="rp-tab-content"><i class="tab-ic tab-ic-grid"></i><span class="rp-tab-label">Posts</span><span class="rp-tab-chev" role="button" tabindex="0" aria-label="Filter memes" onclick="event.stopPropagation();mfOpen()" onkeydown="if(event.key==='Enter'||event.key===' '){event.stopPropagation();event.preventDefault();mfOpen();}"><i class="ri-arrow-down-s-line"></i></span></span></button>
        <button class="rp-tab-btn" data-panel="reposts" title="Reposts" onclick="rpSelectTab(this,'reposts')"><span class="rp-tab-content"><i class="tab-ic tab-ic-repeat"></i><span class="rp-tab-label">Reposts</span></span></button>
        <button class="rp-tab-btn" data-panel="tags" title="Tags" onclick="rpSelectTab(this,'tags')"><span class="rp-tab-content"><i class="tab-ic tab-ic-usersq"></i><span class="rp-tab-label">Tags</span></span></button>
        <button class="rp-tab-btn" data-panel="favorites" title="Saved" onclick="rpSelectTab(this,'favorites')"><span class="rp-tab-content"><i class="tab-ic tab-ic-bookmark"></i><span class="rp-tab-label">Saved</span><span class="rp-tab-chev" role="button" tabindex="0" aria-label="Saved layout" onclick="event.stopPropagation();sfOpen()" onkeydown="if(event.key==='Enter'||event.key===' '){event.stopPropagation();event.preventDefault();sfOpen();}"><i class="ri-arrow-down-s-line"></i></span></span></button>
        <button class="rp-tab-btn" data-panel="messages" title="Messages" onclick="rpSelectTab(this,'messages')">
            <span class="rp-tab-content"><i class="tab-ic tab-ic-message"></i><span class="rp-tab-label">Messages</span></span>
            <?php if ($cur_unread > 0): ?><span class="rp-tab-badge"></span><?php endif; ?>
        </button>
    <?php else: ?>
        <button class="rp-tab-btn active" id="tabMemes" data-panel="memes" title="Posts" onclick="<?php echo $meme_action ?? "rpSelectTab(this,'memes')"; ?>"><span class="rp-tab-content"><i class="tab-ic tab-ic-grid"></i><span class="rp-tab-label">Posts</span><span class="rp-tab-chev" role="button" tabindex="0" aria-label="Filter memes" onclick="event.stopPropagation();mfOpen()" onkeydown="if(event.key==='Enter'||event.key===' '){event.stopPropagation();event.preventDefault();mfOpen();}"><i class="ri-arrow-down-s-line"></i></span></span></button>
        <button class="rp-tab-btn" data-panel="reposts" title="Reposts" onclick="<?php echo $repost_action ?? "rpSelectTab(this,'reposts')"; ?>"><span class="rp-tab-content"><i class="tab-ic tab-ic-repeat"></i><span class="rp-tab-label">Reposts</span></span></button>
        <?php if ($dz_has_videos): ?>
        <button class="rp-tab-btn" data-panel="videos" title="Videos" onclick="rpSelectTab(this,'videos')"><span class="rp-tab-content"><i class="tab-ic tab-ic-video"></i><span class="rp-tab-label">Videos</span></span></button>
        <?php endif; ?>
        <button class="rp-tab-btn" data-panel="tags" title="Tags" onclick="<?php echo $tags_action ?? "rpSelectTab(this,'tags')"; ?>"><span class="rp-tab-content"><i class="tab-ic tab-ic-usersq"></i><span class="rp-tab-label">Tags</span></span></button>
        <?php /* Full Private + not approved: only the name and photo are public, so About stays closed too */ ?>
        <?php if(!$dz_hide_extra): ?>
        <button class="rp-tab-btn" title="About" onclick="dzOpenAboutMe(<?php echo $profile_id; ?>)"><span class="rp-tab-content"><i class="tab-ic tab-ic-userstar"></i><span class="rp-tab-label">About</span></span></button>
        <?php endif; ?>
    <?php endif; ?>
    <span class="rp-tab-indicator" id="rpTabIndicator"></span>
  </div>
</div>

<?php
/* ══════════════════════════════════════════════════════════════════════════
   PROFILE CONTENT — Memes · Reposts · Tags · Saved · Messages  (+ Collections)

   • Every tab area (Memes, Reposts, Tags, Saved, Messages) uses the SAME boxy
     empty-state design as the Home page — no big icon, no big round pill button.
   • The same design is used when the profile is Private / Full Private (you must
     request a track) and when someone is blocked, and it shows in EVERY tab,
     not only in Memes.
   • All CSS lives in the main <style> block at the top of this file.
   • Access checks (blocked / private wall / track-request POSTs) run at the top of
     the file, before any HTML — see "ACCESS CHECKS".
   ══════════════════════════════════════════════════════════════════════════ */

$is_owner_view_c = ($current_user == $profile_id);
$_tabs_allowed   = !$show_private_wall && !$is_blocked_by_owner && !$is_blocked;
$_uname_c        = (string)($user['username'] ?? 'this user');

/* Grid layouts chosen by the profile owner (default | large | list) for Memes and Saved — everyone who opens the profile sees the Memes one */
$memes_layout = 'default';
$favs_layout  = 'default';
try {
    $_lr = $conn->query("SELECT tab, layout FROM profile_layouts WHERE user_id = " . (int)$profile_id);
    while ($_lr && ($_lrow = $_lr->fetch_assoc())) {
        $_lv = ($_lrow['layout'] === 'large') ? 'large' : 'default';   /* no list layout any more: a stored "list" falls back to default */
        if ($_lrow['tab'] === 'memes')     { $memes_layout = ($_lv === 'list') ? 'default' : $_lv; $_memes_saved = true; }   /* the Posts grid no longer has a list layout (it moved to the Videos tab) */   /* a saved "default" counts too */
        if ($_lrow['tab'] === 'favorites') $favs_layout  = $_lv;
    }
} catch (Throwable $e) { /* table not created yet → default */ }
if (empty($_memes_saved)) {   /* only when nothing is saved in the new table: older installs stored the Memes layout in its own table */
    try {
        $_lr = $conn->query("SELECT memes_layout FROM profile_layout_settings WHERE user_id = " . (int)$profile_id . " LIMIT 1");
        if ($_lr && ($_lrow = $_lr->fetch_assoc()) && in_array($_lrow['memes_layout'], ['large'], true)) { $memes_layout = $_lrow['memes_layout']; }
    } catch (Throwable $e) { /* none */ }
}

/* ── pinned posts / pinned collections / collection count (only when the content is viewable) ── */
$pinned_meme_ids = [];
$pinned_col_ids  = [];
$col_count_total = 0;
if ($_tabs_allowed) {
    $pp = $conn->prepare("SELECT meme_id FROM pinned_posts WHERE user_id=? ORDER BY pinned_at DESC LIMIT 3");
    if ($pp) {
        $pp->bind_param("i", $profile_id);
        $pp->execute();
        $pp_res = $pp->get_result();
        while ($pp_row = $pp_res->fetch_assoc()) $pinned_meme_ids[] = (int)$pp_row['meme_id'];
        $pp->close();
    }
    $pc = $conn->prepare("SELECT collection_id FROM pinned_collections WHERE user_id=? ORDER BY pinned_at DESC LIMIT 3");
    if ($pc) {
        $pc->bind_param("i", $profile_id);
        $pc->execute();
        $pc_res = $pc->get_result();
        while ($pc_row = $pc_res->fetch_assoc()) $pinned_col_ids[] = (int)$pc_row['collection_id'];
        $pc->close();
    }
    $cc = $conn->query("SELECT COUNT(*) AS c FROM collections WHERE user_id=" . (int)$profile_id);
    $col_count_total = $cc ? (int)($cc->fetch_assoc()['c'] ?? 0) : 0;
}

/* ══════════════════════════════════════════════════════════
   SMALL HELPERS
══════════════════════════════════════════════════════════ */

/* 1200 → 1.2k, 3400000 → 3.4m */
function dezzFmt($n) {
    $n = (int)$n;
    if ($n >= 1000000000) return round($n / 1000000000, 1) . 'b';
    if ($n >= 1000000)    return round($n / 1000000, 1) . 'm';
    if ($n >= 1000)       return round($n / 1000, 1) . 'k';
    return $n;
}

function dezz_fetch_all($result) {
    $rows = [];
    if ($result) { while ($row = $result->fetch_assoc()) $rows[] = $row; }
    return $rows;
}

/* pinned posts first (in pin order), then everything else in its original order */
function dezz_pinned_first(array $rows, array $pinned_ids) {
    if (!$pinned_ids) return $rows;
    $pinned = []; $normal = [];
    foreach ($rows as $row) {
        if (in_array((int)$row['id'], $pinned_ids, true)) $pinned[(int)$row['id']] = $row;
        else $normal[] = $row;
    }
    $ordered = [];
    foreach ($pinned_ids as $pid) { if (isset($pinned[$pid])) $ordered[] = $pinned[$pid]; }
    return array_merge($ordered, $normal);
}

/* ── the boxy state design used everywhere (same look as the Home page / Untracked page) ──
   $tips = [ ['ri-icon-name', 'tip text'], ... ]
   $btn  = ['Label', 'href']  (link)   |   a ready-made HTML string (e.g. a form button)   |   null */
function dz_empty_state($title, $text, array $tips = [], $btn = null) {
    $h  = '<div class="es-wrap">';
    $h .= '<div class="es-title">' . pf_h($title) . '</div>';
    $h .= '<p class="es-text">' . pf_h($text) . '</p>';
    if ($tips) {
        $h .= '<div class="es-tips">';
        foreach ($tips as $t) {
            $h .= '<div class="es-tip"><i class="' . pf_h($t[0]) . '"></i><span>' . pf_h($t[1]) . '</span></div>';
        }
        $h .= '</div>';
    }
    if (is_array($btn))       $h .= '<a class="es-btn" href="' . pf_h($btn[1]) . '">' . pf_h($btn[0]) . '</a>';
    elseif (is_string($btn))  $h .= $btn;
    return $h . '</div>';
}

/* ── what a visitor sees INSTEAD of a tab's grid when they can't open it ──
   Blocked by the owner → blocked by you → private wall (Private / Full Private, not approved).
   $what = the words for this tab ("memes", "reposts", "tagged posts"). */
function dz_wall_state_html($what) {
    global $user, $current_user, $wall_status, $show_private_wall, $is_blocked_by_owner, $is_blocked, $dz_priv;
    $uname = (string)($user['username'] ?? 'this user');

    if ($is_blocked_by_owner) {
        return dz_empty_state(
            "You've been blocked",
            $uname . " has blocked you, so you can't see their " . $what . '.',
            [['ri-lock-line', "You can't view this profile, track it or message it."]]);
    }

    if ($is_blocked) {
        $btn = '<form method="POST" class="es-form">' . dz_csrf_field()
             . '<button type="submit" name="unblock" class="es-btn">Unblock</button></form>';
        return dz_empty_state(
            'You have blocked ' . $uname,
            'Unblock ' . $uname . ' to see their ' . $what . ' again.',
            [['ri-user-forbid-line', "They can't see your profile while they are blocked."]],
            $btn);
    }

    /* private wall: Private and Full Private profiles */
    $full = (($dz_priv['level'] ?? '') === 'full');
    if ($wall_status === 'pending') {
        $title = 'Request sent';
        $text  = 'Your track request is waiting for ' . $uname . ' to answer. Their ' . $what . ' will unlock if they approve it.';
    } elseif ($wall_status === 'denied') {
        $title = 'Request not approved';
        $text  = 'Your last request was not approved. You can send ' . $uname . ' a new one to see their ' . $what . '.';
    } else {
        $title = 'This profile is private';
        $text  = 'You must request to track ' . $uname . ' to see their ' . $what . '.';
    }
    $tips = [
        $full
            ? ['ri-lock-2-line', 'Only their name and photo are public. Everything else is for approved trackers.']
            : ['ri-lock-line',   'You can see their name, photo and bio. Their memes are for approved trackers.'],
        ['ri-notification-3-line', 'Everything unlocks as soon as ' . $uname . ' approves your request.'],
    ];

    if (!$current_user) {
        $btn = '<a href="login.php" class="es-btn track">Login to request</a>';
    } elseif ($wall_status === 'pending') {
        $btn = '<form method="POST" class="es-form">' . dz_csrf_field()
             . '<button type="submit" name="cancel_track_request" class="es-btn">Requested · Cancel</button></form>';
    } else {
        $btn = '<form method="POST" class="es-form">' . dz_csrf_field()
             . '<button type="submit" name="send_track_request" class="es-btn track">' . ($wall_status === 'denied' ? 'Request to Track again' : 'Request to Track') . '</button></form>';
    }
    return dz_empty_state($title, $text, $tips, $btn);
}

/* the "Newest | Popular | Oldest | Collections" bar of one tab. $p = tab key (memes, reposts, tags, favs).
   These bars no longer live inside the sliding panels: they sit together in the shared dock above the pager
   (#rpSortDock) so they stay put while the grids slide. $hidden = every bar except the open tab's. */
function dz_sort_bar_html($p, $col_count, $hidden = false, $search = false) {
    ob_start(); ?>
    <div class="grid-sort-bar" id="<?= $p ?>SortBar"<?= $hidden ? ' style="display:none"' : '' ?>>
        <button type="button" class="g-pill active" id="<?= $p ?>N" onclick="dzSort('<?= $p ?>Grid','newest','<?= $p ?>')">Newest</button>
        <button type="button" class="g-pill g-pill-pop" id="<?= $p ?>P" onclick="dzSort('<?= $p ?>Grid','popular','<?= $p ?>')">Popular</button>
        <button type="button" class="g-pill" id="<?= $p ?>O" onclick="dzSort('<?= $p ?>Grid','oldest','<?= $p ?>')">Oldest</button>
        <button type="button" class="g-pill" id="<?= $p ?>C" onclick="gOpenCol('<?= $p ?>')"><i class="ri-folder-3-line"></i>Collections<span class="g-pill-count"><?= $col_count > 0 ? dezzFmt($col_count) : '' ?></span></button>
        <?php if ($search): /* own profile only: search icon at the far right of the bar */ ?>
        <button type="button" class="g-pill g-search-btn" id="<?= $p ?>Srch" onclick="dzGridSearchToggle()" aria-label="Search posts" aria-expanded="false"><svg class="tb-sicon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="10" cy="10" r="6.5" fill="none" stroke="currentColor" stroke-width="2.5"/><path fill="currentColor" d="M15.37 14.23L21.41 18.59A2 2 0 0 1 18.59 21.41L14.23 15.37Z"/></svg></button>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

/* the Collections panel (folder grid + detail view) of one tab */
function dz_col_section_html($p, $show_new) {
    ob_start(); ?>
    <div class="col-section" id="<?= $p ?>ColSec">
        <div id="<?= $p ?>ColFolders">
            <div class="col-search-wrap" id="<?= $p ?>ColSearchWrap">
                <i class="ri-search-line col-search-icon"></i>
                <input type="text" class="col-search-input" id="<?= $p ?>ColSearch" placeholder="Search collections…" oninput="gSearchFolders('<?= $p ?>',this.value)" autocomplete="off">
                <button type="button" class="col-search-clear" id="<?= $p ?>ColSearchClear" onclick="gClearFolderSearch('<?= $p ?>')">✕</button>
            </div>
            <div class="col-folders-grid" id="<?= $p ?>ColGrid">
                <?php if ($show_new): ?>
                <button type="button" class="col-new-btn" onclick="gOpenNameModal('create','<?= $p ?>')"><i class="ri-folder-add-line"></i>New Collection</button>
                <?php endif; ?>
            </div>
            <div class="col-search-no-results" id="<?= $p ?>ColNoResults"><i class="ri-folder-search-line"></i>No collections match</div>
        </div>
        <div class="col-detail" id="<?= $p ?>ColDetail">
            <div class="col-detail-hdr">
                <button type="button" class="col-back-btn" onclick="gCloseDetail('<?= $p ?>')"><i class="ri-arrow-left-line"></i></button>
                <div class="col-detail-title" id="<?= $p ?>ColTitle">Collection</div>
                <div class="col-detail-count" id="<?= $p ?>ColCount">0 items</div>
            </div>
            <div class="col-detail-search-wrap">
                <i class="ri-hashtag col-detail-search-icon"></i>
                <input type="text" class="col-detail-search-input" id="<?= $p ?>DetailSearch" placeholder="Search by caption or #hashtag…" oninput="gSearchDetailItems('<?= $p ?>ColDetailGrid','<?= $p ?>DetailSearch','<?= $p ?>DetailNoResults')" autocomplete="off">
                <button type="button" class="col-detail-search-clear" id="<?= $p ?>DetailSearchClear" onclick="gClearDetailSearch('<?= $p ?>ColDetailGrid','<?= $p ?>DetailSearch','<?= $p ?>DetailNoResults','<?= $p ?>DetailSearchClear')">✕</button>
            </div>
            <div class="col-detail-no-results" id="<?= $p ?>DetailNoResults"><i class="ri-search-eye-line"></i>No memes found</div>
            <div class="grid" id="<?= $p ?>ColDetailGrid"></div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/* one whole tab: (paged) grid + collections panel. (The sort bar is in the shared dock above the pager.)
   The Memes panel starts with .rp-active (it is the tab that is open on page load). */
function dz_featured_html($f, $is_owner) {
    if (!$f) return '';
    $r = $f['row']; $id = (int)$r['id'];
    $is_text = ($r['type'] === 'text' || empty($r['file_name']) || $r['file_name'] === '0');
    $thumb = $is_text ? 'uploads/Text.jpg' : ((!empty($r['cover_image'])) ? 'uploads/' . $r['cover_image'] : 'uploads/' . $r['file_name']);
    $cap = trim((string)($r['caption'] ?? ''));
    $note = trim($f['note']);
    /* opens exactly like a grid card: the same openMemeModal(...) call, fed by the same profileFeed entry */
    $__js = function ($v) { return htmlspecialchars(json_encode((string)$v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR), ENT_QUOTES); };
    $mf    = $is_text ? 'uploads/Text.jpg' : 'uploads/' . $r['file_name'];
    $upic  = !empty($r['uploader_pic']) ? $r['uploader_pic'] : 'mee.jpg';
    $open  = 'openMemeModal(' . $__js($mf) . ',' . $__js($is_text ? 'image' : $r['type']) . ',' . $__js(htmlspecialchars((string)($r['caption'] ?? ''))) . ',' . $__js($upic) . ',' . $__js(htmlspecialchars((string)($r['username'] ?? ''))) . ',' . (int)$r['uploaded_by'] . ',' . $__js('profileFeed') . ',' . $id . ')';
    ob_start(); ?>
    <div id="featuredPost" class="ft-card" data-id="<?= $id ?>" role="button" tabindex="0" onclick="<?= $open ?>" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();<?= $open ?>;}">
        <div class="ft-thumb">
            <?php if (!$is_text && $r['type'] === 'video' && empty($r['cover_image'])): ?>
                <video src="uploads/<?= htmlspecialchars($r['file_name']) ?>#t=0.1" muted playsinline preload="metadata"></video>
            <?php else: ?>
                <img src="<?= htmlspecialchars($thumb) ?>" alt="" loading="lazy" decoding="async">
            <?php endif; ?>
        </div>
        <div class="ft-body">
            <div class="ft-tag"><i class="ri-star-fill"></i> Featured</div>
            <?php if ($note !== ''): ?><div class="ft-note"><?= htmlspecialchars($note) ?></div><?php endif; ?>
            <?php if ($cap !== ''): ?><div class="ft-cap"><?= htmlspecialchars($cap) ?></div><?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
/* ══════════════════════════════════════════════════════════
   GUIDE — shown in the Memes tab (owner only, picked from the ⌄ sheet).
   Explains how each part of the profile header looks to OTHER Dezzmers (some of it you can't see
   on your own profile), and where each part can be changed. Status chips are live.
══════════════════════════════════════════════════════════ */
function dz_profile_info_html() {
    global $conn, $current_user, $profile_id, $user, $profile_emails, $profile_link_count, $profile_type, $activity_vibe, $dz_priv;
    $uid = (int)$profile_id;
    $h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

    $n_products = 0;
    try {
        $q = $conn->prepare("SELECT COUNT(*) FROM products WHERE user_id = ? AND is_active = 1");
        if ($q) { $q->bind_param('i', $uid); $q->execute(); $q->bind_result($n_products); $q->fetch(); $q->close(); }
    } catch (Throwable $e) { $n_products = 0; }
    $n_products = (int)$n_products;

    $n_emails = is_array($profile_emails ?? null) ? count($profile_emails) : 0;
    $n_links  = (int)($profile_link_count ?? 0);
    $msg_on   = !empty($dz_priv['allow_messages']);
    $on_on    = !empty($dz_priv['show_online']);
    $lvl      = (string)($dz_priv['level'] ?? 'public');
    $lvl_txt  = ['public' => 'Public', 'limited' => 'Limited', 'private' => 'Private', 'full' => 'Full Private'][$lvl] ?? 'Public';
    $v_type   = trim((string)$profile_type);
    $v_pron   = trim((string)($user['pronouns'] ?? ''));
    $v_utype  = trim((string)($user['user_type'] ?? ''));
    $v_ah     = trim((string)$activity_vibe);
    $has_nick = trim((string)($user['nickname'] ?? '')) !== '';
    $has_bio  = trim((string)($user['bio'] ?? '')) !== '';

    $chip = function (bool $on, string $yes, string $no) use ($h) {
        return '<span class="dzi-chip' . ($on ? ' on' : '') . '">' . $h($on ? $yes : $no) . '</span>';
    };
    $pill = function (string $t) { return '<span class="dzi-pill">' . $t . '</span>'; };
    $item = function (string $icon, string $title, string $chipHtml, string $demo, string $body, string $actions) {
        return '<div class="dzi-item">'
             . '<div class="dzi-head"><span class="dzi-t">' . $title . '</span>' . $chipHtml . '</div>'
             . ($demo !== '' ? '<div class="dzi-demo">' . $demo . '</div>' : '')
             . '<p class="dzi-b">' . $body . '</p>'
             . ($actions !== '' ? '<div class="dzi-act">' . $actions . '</div>' : '')
             . '</div>';
    };
    $group = function (string $label, string $sub, string $inner, string $acts = '') {
        return '<section class="dzi-group"><div class="dzi-glabel">' . $label . '</div>'
             . ($sub !== '' ? '<div class="dzi-gsub">' . $sub . '</div>' : '')
             . ($acts !== '' ? '<div class="dzi-act dzi-gact">' . $acts . '</div>' : '')
             . '<div class="dzi-list">' . $inner . '</div></section>';
    };
    $editBtn = '<button type="button" class="dzi-btn" onclick="dzInfoEdit()">Edit with +</button>'
             . '<a class="dzi-btn" href="setting.php">Settings</a>';
    $changeTxt = 'Change it any time: tap the <span class="dzi-plus"><i class="ri-add-line"></i></span> on your profile picture or go to <span class="r">Settings</span>. This applies to everything below.';

    ob_start(); ?>
<div id="dzInfoPanel" class="dzi-wrap">
    <div class="dzi-hero">
        <div class="dzi-hero-t">Guide</div>
        <div class="dzi-hero-s">How your profile looks to other Dezzmers. Some things only show when <span class="r">someone else</span> views you, so you won't see them on your own page. Switch back from the ⌄ beside this tab.</div>
    </div>
<?php
    echo $group('Buttons in your header', 'What other Dezzmers see under your bio',
        $item('ri-store-2-line', 'Shop Now',
            $chip($n_products > 0, $n_products . ($n_products === 1 ? ' product' : ' products'), 'No products yet'),
            $pill('Track') . $pill('Message') . $pill('Shop Now'),
            'With at least one product in your shop, a <span class="r">Shop Now</span> button appears in your header for everyone else and takes them to your shop. You don\'t see it on your own profile: open your shop from the ⋮ menu → <span class="r">Your Shop</span>.',
            '<a class="dzi-btn" href="profile_shop.php?user_id=' . $uid . '">Open your shop</a>')
      . $item('ri-contacts-line', 'Contact',
            $chip($n_emails > 0, $n_emails . ($n_emails === 1 ? ' contact found' : ' contacts found'), 'No contact found'),
            $pill('Track') . $pill('Message') . $pill('Contact'),
            'Any email or phone number you share becomes a <span class="r">Contact</span> button. It picks them up from your <span class="r">Links</span>, from your bio, and from the Contact tab of your <span class="r">About me</span> (About me details are only shown to people who track you). One contact opens straight away (your mail app or phone dialler); two or more open a list of every email and phone number. With a shop, Shop Now takes that spot and Contact tucks behind a small <span class="r">⌄</span> square beside it. With nothing to show, Dezzmers see Block there instead.',
            '<a class="dzi-btn" href="links.php?user_id=' . $uid . '">Edit links</a>')
      . $item('ri-chat-1-line', 'Message',
            $chip($msg_on, 'On · ' . $lvl_txt, 'Turned off'),
            $pill('Track') . $pill('Message'),
            'Shown only when <span class="r">Allow messages</span> is on in <span class="r">Settings → Privacy Controls</span>. On a Public profile anyone can message you; on Limited, Private or Full Private it appears once they track you. Hidden if either of you has blocked the other.',
            '<a class="dzi-btn" href="setting.php">Settings</a>'));

    echo $group('Preview', '',
        $item('ri-eye-line', 'View As', '', '',
            'See your profile exactly the way other Dezzmers see it: the Track, Message and Shop Now buttons, the Contact square and everything else you can\'t see on your own page. It is <span class="r">look-only</span>, so nothing you tap changes anything. If your profile is Limited, Private or Full Private you can switch between someone who <span class="r">doesn\'t track you</span> and someone who <span class="r">tracks you</span>. Tap the <span class="r">✕</span> at the top left to come back.',
            '<a class="dzi-btn" href="profile.php?view_as=1">View As</a>'));

    echo $group('Hold to open', 'Two quick shortcuts. A normal tap still does what it always did.',
        $item('ri-gallery-line', 'Hold a post', '', '',
            'Press and hold any post in your Memes grid for a quarter of a second and the <span class="r">Post options</span> sheet slides up: <span class="r">Pin</span> it to the top of your profile, add it to a <span class="r">Collection</span>, or <span class="r">Delete</span> it. Let go before then and the post just opens as usual. Moving your finger or scrolling cancels the hold.',
            '')
      . $item('ri-user-3-line', 'Hold your username', '', '',
            'When a long username is cut off with <span class="r">…</span>, press and hold it for a moment and a sheet shows the <span class="r">full name</span>. Other Dezzmers can do the same on your profile.',
            ''));

    echo $group('Under your bio', '',
        $item('ri-link-m', 'Links',
            $chip($n_links > 0, $n_links . ($n_links === 1 ? ' link' : ' links'), 'No links yet'),
            '<span class="dzi-link"><i class="ri-link-m"></i>yourlink.com</span>',
            'One or two links show right under your bio and open when tapped. Three or more show the first one, and tapping it opens the full list. Email links don\'t show here; they become the Contact button.',
            '<a class="dzi-btn" href="links.php?user_id=' . $uid . '">Edit links</a>'));

    echo $group('About you', $changeTxt,
        $item('ri-user-3-line', 'Pronouns',
            $chip($v_pron !== '', $v_pron, 'Not set'),
            $pill($v_pron !== '' ? $h($v_pron) : 'she/her'),
            'A small pill beside your post count. Leave it empty and it simply doesn\'t show.', '')
      . $item('ri-user-star-line', 'User type',
            $chip($v_utype !== '', $v_utype, 'Not set'),
            $pill($v_utype !== '' ? $h($v_utype) : 'Creator'),
            'Tells people what kind of Dezzmer you are. It sits as a pill next to your pronouns.', '')
      . $item('ri-time-line', 'Active hours',
            $chip($v_ah !== '', $v_ah, 'Not set'),
            $pill('A.H : ' . ($v_ah !== '' ? $h($v_ah) : 'Evenings')),
            'Lets people know when you\'re usually around. Shows as an <span class="r">A.H</span> pill in your header.', '')
      . $item('ri-price-tag-3-line', 'Professional mode type',
            $chip($v_type !== '', $v_type, 'Not set'),
            '<span class="dzi-grey">' . ($v_type !== '' ? $h($v_type) : 'Personal Blog') . '</span>',
            'Grey text just above your bio, shown whether or not the viewer tracks you. Tapping it explains the type.', '')
      . $item('ri-id-card-line', 'Nickname & bio',
            $chip($has_nick && $has_bio, 'Both set', ($has_nick || $has_bio) ? 'Partly set' : 'Not set'),
            '',
            'Your nickname sits under your username and your bio below your header. Long bios fold behind <span class="r">more</span>, and @mentions and links in it are tappable.', ''), $editBtn);

    echo $group('Privacy & your buttons', 'Your privacy level is <span class="r">' . $h($lvl_txt) . '</span>. It decides which buttons other Dezzmers get. Change it in <span class="r">Settings → Privacy Controls</span>.',
        $item('ri-user-follow-line', 'Track',
            $chip(true, $h($lvl_txt), ''),
            $pill($lvl === 'public' || $lvl === 'limited' ? 'Track' : 'Request to Track'),
            'On <span class="r">Public</span> and <span class="r">Limited</span> profiles anyone can tap <span class="r">Track</span> and follow you straight away. On <span class="r">Private</span> and <span class="r">Full Private</span> they see <span class="r">Request to Track</span> instead, and only people you approve can track you.',
            '<a class="dzi-btn" href="setting.php">Privacy Controls</a>')
      . $item('ri-lock-line', 'What people see before they track you',
            '',
            '',
            'On <span class="r">Limited</span>, <span class="r">Private</span> and <span class="r">Full Private</span>, people who don\'t track you can\'t see your Tracker and Tracking counts, Message stays hidden, and so does Shop Now, until they track you. On <span class="r">Full Private</span> they only see your name and photo; bio, links, email and the pills stay hidden until you approve them.',
            '')
      . $item('ri-forbid-line', 'Blocked people',
            '',
            '',
            'If you block someone, or they block you, there is no Message, Shop Now or Contact button between you, and they can\'t see your posts.',
            '<a class="dzi-btn" href="privacy_moderation.php">Privacy &amp; Moderation</a>')
      . $item('ri-checkbox-blank-circle-fill', 'Online dot',
            $chip($on_on, 'Visible', 'Hidden'),
            '<span class="dzi-dot"></span><span class="dzi-grey">Green dot on your profile picture</span>',
            'Shows when you\'re online, only if <span class="r">Show online status</span> is on in <span class="r">Settings → Privacy Controls</span>.',
            '<a class="dzi-btn" href="setting.php">Settings</a>'));
?>
</div>
<?php
    return ob_get_clean();
}

function dz_tab_section_html($sec_id, $p, $grid_inner, $show_new, $col_count) {
    $lay = ($p === 'memes') ? $GLOBALS['memes_layout'] : (($p === 'favs') ? $GLOBALS['favs_layout'] : 'default');
    return '<div id="' . $sec_id . '" class="dropdown-section' . ($sec_id === 'memes' ? ' rp-active' : '') . '">'
         . (($p === 'memes' && $GLOBALS['current_user'] == $GLOBALS['profile_id']) ? dz_profile_info_html() : '')
         . ($p === 'memes' ? dz_featured_html($GLOBALS['dz_featured'], ($GLOBALS['current_user'] == $GLOBALS['profile_id'])) : '')
         . '<div class="grid dz-paged' . ($lay !== 'default' ? ' lay-' . $lay : '') . '" id="' . $p . 'Grid" data-feed="' . ['memes'=>'profileFeed','reposts'=>'repostFeed','tags'=>'tagsFeed','favs'=>'favsFeed'][$p] . '">' . $grid_inner . '</div>'
         . dz_col_section_html($p, $show_new)
         . '</div>';
}

if (is_file(__DIR__ . '/dz_thumb.php')) require_once __DIR__ . '/dz_thumb.php';

/* Reposts / Tags / Saved are loaded the first time their tab is opened (?dz_lazy=reposts|tags|favs).
   Normal page load → a light placeholder; fragment request → just that tab's HTML, then stop. */
function dz_lazy_section($sec_id, $p, $inner, $own, $cc) {
    global $dz_lazy;
    if ($dz_lazy === $p) {
        $html = dz_tab_section_html($sec_id, $p, $inner, $own, $cc);
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store'); header('X-Dz-Lazy: 1');
        echo $html; exit;
    }
    $ph = '<div class="dz-lazy-ph" style="grid-column:1/-1;text-align:center;padding:36px 12px;color:#8b8fa3;font-size:.8rem">Loading…</div>';
    return str_replace('class="dropdown-section"', 'class="dropdown-section" data-dz-lazy="' . $p . '"', dz_tab_section_html($sec_id, $p, $ph, $own, $cc));
}

/* ══════════════════════════════════════════════════════════
   BATCH DATA LOADING — a fixed number of queries for the WHOLE page,
   no matter how many memes are shown (instead of ~12 per card).

   Every tab's result set is read into an array first, so we know every
   meme_id / uploader_id on the page BEFORE rendering. dezz_prime_caches()
   then runs one batched query per data type. The get_*() helpers below
   keep their per-meme signatures but only look in the caches — and if an ID
   was never primed (e.g. a Collections item), they lazy-fill with a one-off
   query instead of returning blank defaults.
══════════════════════════════════════════════════════════ */
$memes_rows   = ($_tabs_allowed && isset($memes))        ? dezz_fetch_all($memes)        : [];
$reposts_rows = ($_tabs_allowed && isset($reposts))      ? dezz_fetch_all($reposts)      : [];
$tags_rows    = ($_tabs_allowed && isset($tagged_posts)) ? dezz_fetch_all($tagged_posts) : [];
$favs_rows    = ($_tabs_allowed && isset($favorites))    ? dezz_fetch_all($favorites)    : [];

/* ── featured post (one, with a note) — shown above the Memes grid ── */
$dz_featured = null;
if ($_tabs_allowed) {
    try {
        $_fr = $conn->query("SELECT meme_id, note FROM profile_featured WHERE user_id = " . (int)$profile_id . " LIMIT 1");
        if ($_fr && ($_frow = $_fr->fetch_assoc())) {
            foreach ($memes_rows as $_r) {
                if ((int)$_r['id'] === (int)$_frow['meme_id']) { $dz_featured = ['row' => $_r, 'note' => (string)$_frow['note']]; break; }
            }
        }
    } catch (Throwable $e) { /* table not created yet */ }
}

$_all_meme_ids = [];
$_all_uploader_ids = [];
foreach ([$memes_rows, $reposts_rows, $tags_rows, $favs_rows] as $_set) {
    foreach ($_set as $_r) {
        $_all_meme_ids[(int)$_r['id']] = true;
        if (isset($_r['uploaded_by'])) $_all_uploader_ids[(int)$_r['uploaded_by']] = true;
    }
}
$_all_meme_ids     = array_keys($_all_meme_ids);
$_all_uploader_ids = array_keys($_all_uploader_ids);

$PRIMED_MEME_IDS     = array_flip($_all_meme_ids);
$PRIMED_UPLOADER_IDS = array_flip($_all_uploader_ids);
if (function_exists('dzx_prime_hide')) dzx_prime_hide($conn, $_all_meme_ids);   /* hidden-count flags for every post on the page in ONE query */

$NICK_CACHE = []; $FOLLOW_CACHE = []; $SOCIAL_CACHE = []; $MEDIA_CACHE = []; $GROUP_CACHE = [];
$AUDIO_CACHE = []; $COUNT_CACHE = []; $INTERACTION_CACHE = []; $REPOST_TS_CACHE = []; $FAV_TS_CACHE = [];

function dezz_prime_caches($conn, $meme_ids, $uploader_ids, $current_user, $profile_id) {
    global $NICK_CACHE, $FOLLOW_CACHE, $SOCIAL_CACHE, $MEDIA_CACHE, $GROUP_CACHE,
           $AUDIO_CACHE, $COUNT_CACHE, $INTERACTION_CACHE, $REPOST_TS_CACHE, $FAV_TS_CACHE;

    /* nicknames + follow state (batched by uploader) */
    if (!empty($uploader_ids)) {
        $ids = implode(',', array_map('intval', $uploader_ids));
        $r = $conn->query("SELECT id, nickname FROM users WHERE id IN ($ids)");
        while ($row = $r->fetch_assoc()) $NICK_CACHE[(int)$row['id']] = $row['nickname'];
        if ($current_user) {
            $r = $conn->query("SELECT following_id FROM followers WHERE follower_id=" . (int)$current_user . " AND following_id IN ($ids)");
            while ($row = $r->fetch_assoc()) $FOLLOW_CACHE[(int)$row['following_id']] = true;
        }
    }

    if (empty($meme_ids)) return;
    $mids = implode(',', array_map('intval', $meme_ids));

    /* social proof: likes / reposts by people the viewer follows */
    if ($current_user) {
        $r = $conn->query("
            SELECT meme_id, id, username, profile_pic, action_type, acted_at FROM (
                SELECT l.meme_id AS meme_id, u2.id, u2.username, u2.profile_pic, 'like' AS action_type, l.created_at AS acted_at
                FROM likes l JOIN users u2 ON u2.id = l.user_id
                JOIN followers f ON f.following_id = u2.id
                WHERE l.meme_id IN ($mids) AND f.follower_id = " . (int)$current_user . " AND u2.id != " . (int)$current_user . "
                UNION ALL
                SELECT r.meme_id AS meme_id, u2.id, u2.username, u2.profile_pic, 'repost' AS action_type, r.created_at AS acted_at
                FROM reposts r JOIN users u2 ON u2.id = r.user_id
                JOIN followers f ON f.following_id = u2.id
                WHERE r.meme_id IN ($mids) AND f.follower_id = " . (int)$current_user . " AND u2.id != " . (int)$current_user . "
                UNION ALL
                SELECT r.meme_id AS meme_id, u2.id, u2.username, u2.profile_pic, 'repost' AS action_type, r.created_at AS acted_at
                FROM reposts r JOIN users u2 ON u2.id = r.user_id
                WHERE r.meme_id IN ($mids) AND r.user_id = " . (int)$current_user . "
            ) sp
        ");
        $_social_raw = [];
        while ($row = $r->fetch_assoc()) {
            $mid = (int)$row['meme_id'];
            $_social_raw[$mid][] = ['id' => $row['id'], 'username' => $row['username'], 'profile_pic' => $row['profile_pic'], 'action_type' => $row['action_type'], 'acted_at' => $row['acted_at']];
        }
        foreach ($_social_raw as $mid => $rows) {          // reposts first (newest first), likes after, THEN cap at 4
            $SOCIAL_CACHE[$mid] = dz_sp_sort($rows, (int)$mid);
        }
    }

    /* extra media items */
    $r = $conn->query("SELECT meme_id, file_name, type FROM meme_media WHERE meme_id IN ($mids) ORDER BY meme_id, position ASC");
    while ($row = $r->fetch_assoc()) {
        $MEDIA_CACHE[(int)$row['meme_id']][] = ['file' => 'uploads/' . $row['file_name'], 'type' => $row['type']];
    }

    /* group / pod info */
    $r = $conn->query("
        SELECT gp.meme_id AS meme_id, gp.group_id AS group_id, g.title AS group_name
        FROM group_posts gp JOIN `groups` g ON g.id = gp.group_id
        WHERE gp.meme_id IN ($mids)
    ");
    while ($row = $r->fetch_assoc()) {
        $mid = (int)$row['meme_id'];
        if (!isset($GROUP_CACHE[$mid])) $GROUP_CACHE[$mid] = ['group_id' => $row['group_id'], 'group_name' => $row['group_name']];
    }

    /* attached soundtrack (audio_id + is_original included for the modal's audio pill) */
    $r = $conn->query("
        SELECT ma.meme_id AS meme_id, a.id AS audio_id, a.title, a.artist, a.file_name,
               ma.is_original, ma.music_volume, ma.video_audio_volume
        FROM meme_audio ma JOIN audios a ON a.id = ma.audio_id
        WHERE ma.meme_id IN ($mids)
    ");
    while ($row = $r->fetch_assoc()) {
        $mid = (int)$row['meme_id'];
        if (!isset($AUDIO_CACHE[$mid])) $AUDIO_CACHE[$mid] = $row;
    }

    /* like / repost / favorite / share counts */
    foreach (['like' => 'likes', 'repost' => 'reposts', 'fav' => 'favorites', 'share' => 'shares'] as $key => $table) {
        $r = $conn->query("SELECT meme_id, COUNT(*) c FROM $table WHERE meme_id IN ($mids) GROUP BY meme_id");
        while ($row = $r->fetch_assoc()) $COUNT_CACHE[(int)$row['meme_id']][$key] = (int)$row['c'];
    }

    /* did the viewer like / repost / favorite it */
    if ($current_user) {
        foreach (['liked' => 'likes', 'reposted' => 'reposts', 'faved' => 'favorites'] as $key => $table) {
            $r = $conn->query("SELECT meme_id FROM $table WHERE meme_id IN ($mids) AND user_id=" . (int)$current_user);
            while ($row = $r->fetch_assoc()) $INTERACTION_CACHE[(int)$row['meme_id']][$key] = true;
        }
    }

    /* when the profile owner reposted / saved each meme (drives Newest / Oldest in those tabs) */
    $r = $conn->query("SELECT meme_id, MAX(created_at) mx FROM reposts WHERE meme_id IN ($mids) AND user_id=" . (int)$profile_id . " GROUP BY meme_id");
    while ($row = $r->fetch_assoc()) $REPOST_TS_CACHE[(int)$row['meme_id']] = $row['mx'];

    $r = $conn->query("SELECT meme_id, MAX(created_at) mx FROM favorites WHERE meme_id IN ($mids) AND user_id=" . (int)$profile_id . " GROUP BY meme_id");
    while ($row = $r->fetch_assoc()) $FAV_TS_CACHE[(int)$row['meme_id']] = $row['mx'];
}

dezz_prime_caches($conn, $_all_meme_ids, $_all_uploader_ids, $current_user, $profile_id);

/* nickname, real follow state and social proof for a poster + meme (lazy-fills un-primed IDs) */
function get_poster_meta($conn, $meme_id, $uploaded_by, $current_user) {
    global $NICK_CACHE, $FOLLOW_CACHE, $SOCIAL_CACHE, $PRIMED_UPLOADER_IDS, $PRIMED_MEME_IDS;
    $uploaded_by = (int)$uploaded_by;
    $meme_id     = (int)$meme_id;

    if (!isset($PRIMED_UPLOADER_IDS[$uploaded_by])) {
        $r   = $conn->query("SELECT nickname FROM users WHERE id=$uploaded_by LIMIT 1");
        $row = $r ? $r->fetch_assoc() : null;
        $NICK_CACHE[$uploaded_by] = $row ? $row['nickname'] : '';
        if ($current_user) {
            $chk = $conn->query("SELECT id FROM followers WHERE follower_id=" . (int)$current_user . " AND following_id=$uploaded_by LIMIT 1");
            if ($chk && $chk->num_rows > 0) $FOLLOW_CACHE[$uploaded_by] = true;
        }
        $PRIMED_UPLOADER_IDS[$uploaded_by] = true;
    }

    if (!isset($PRIMED_MEME_IDS[$meme_id]) && !array_key_exists($meme_id, $SOCIAL_CACHE)) {
        $sp = [];
        if ($current_user) {
            $r = $conn->query("
                SELECT id, username, profile_pic, action_type, acted_at FROM (
                    SELECT u2.id, u2.username, u2.profile_pic, 'like' AS action_type, l.created_at AS acted_at
                    FROM likes l JOIN users u2 ON u2.id = l.user_id
                    JOIN followers f ON f.following_id = u2.id
                    WHERE l.meme_id=$meme_id AND f.follower_id=" . (int)$current_user . " AND u2.id != " . (int)$current_user . "
                    UNION ALL
                    SELECT u2.id, u2.username, u2.profile_pic, 'repost' AS action_type, r.created_at AS acted_at
                    FROM reposts r JOIN users u2 ON u2.id = r.user_id
                    JOIN followers f ON f.following_id = u2.id
                    WHERE r.meme_id=$meme_id AND f.follower_id=" . (int)$current_user . " AND u2.id != " . (int)$current_user . "
                    UNION ALL
                    SELECT u2.id, u2.username, u2.profile_pic, 'repost' AS action_type, r.created_at AS acted_at
                    FROM reposts r JOIN users u2 ON u2.id = r.user_id
                    WHERE r.meme_id=$meme_id AND r.user_id=" . (int)$current_user . "
                ) sp
            ");
            if ($r) { while ($row = $r->fetch_assoc()) $sp[] = $row; }
            $sp = dz_sp_sort($sp, (int)$meme_id);
        }
        $SOCIAL_CACHE[$meme_id] = $sp;
    }

    return [
        'nickname'     => $NICK_CACHE[$uploaded_by] ?? '',
        'is_following' => $current_user ? !empty($FOLLOW_CACHE[$uploaded_by]) : false,
        'social_spare' => dz_sp_spare($meme_id),
        'social_proof' => array_map(function ($x) use ($conn) { $x['isVerified'] = dz_vb_flag($conn, (int)($x['id'] ?? 0)); return $x; }, $SOCIAL_CACHE[$meme_id] ?? []),
    ];
}

/* which group / pod (if any) a meme was posted into */
function get_meme_group_info($conn, $meme_id) {
    global $GROUP_CACHE, $PRIMED_MEME_IDS;
    $meme_id = (int)$meme_id;
    if (!isset($PRIMED_MEME_IDS[$meme_id]) && !array_key_exists($meme_id, $GROUP_CACHE)) {
        $r = $conn->query("
            SELECT gp.group_id AS group_id, g.title AS group_name
            FROM group_posts gp JOIN `groups` g ON g.id = gp.group_id
            WHERE gp.meme_id=$meme_id LIMIT 1
        ");
        $row = $r ? $r->fetch_assoc() : null;
        $GROUP_CACHE[$meme_id] = $row ? ['group_id' => $row['group_id'], 'group_name' => $row['group_name']] : null;
    }
    return $GROUP_CACHE[$meme_id] ?? null;
}

/* the attached soundtrack (audio_id, is_original, title, artist, file, volumes) */
function get_meme_audio_info($conn, $meme_id) {
    global $AUDIO_CACHE, $PRIMED_MEME_IDS;
    $meme_id = (int)$meme_id;
    if (!isset($PRIMED_MEME_IDS[$meme_id]) && !array_key_exists($meme_id, $AUDIO_CACHE)) {
        $r = $conn->query("
            SELECT a.id AS audio_id, a.title, a.artist, a.file_name,
                   ma.is_original, ma.music_volume, ma.video_audio_volume
            FROM meme_audio ma JOIN audios a ON a.id = ma.audio_id
            WHERE ma.meme_id=$meme_id LIMIT 1
        ");
        $AUDIO_CACHE[$meme_id] = $r ? $r->fetch_assoc() : null;
    }
    return $AUDIO_CACHE[$meme_id] ?? null;
}

/* the full ordered media array of a Zeem: main item + extra rows from meme_media */
function get_meme_media_items($conn, $meme_id, $main_file, $main_type) {
    global $MEDIA_CACHE, $PRIMED_MEME_IDS;
    $meme_id = (int)$meme_id;
    if (!isset($PRIMED_MEME_IDS[$meme_id]) && !array_key_exists($meme_id, $MEDIA_CACHE)) {
        $extra = [];
        $r = $conn->query("SELECT file_name, type FROM meme_media WHERE meme_id=$meme_id ORDER BY position ASC");
        if ($r) { while ($row = $r->fetch_assoc()) $extra[] = ['file' => 'uploads/' . $row['file_name'], 'type' => $row['type']]; }
        $MEDIA_CACHE[$meme_id] = $extra;
    }
    $media = [['file' => $main_file, 'type' => $main_type]];
    if (!empty($MEDIA_CACHE[$meme_id])) $media = array_merge($media, $MEDIA_CACHE[$meme_id]);
    return $media;
}

/* like / repost / fav / share counts */
function get_meme_counts($meme_id) {
    global $COUNT_CACHE, $PRIMED_MEME_IDS, $conn;
    $meme_id  = (int)$meme_id;
    $defaults = ['like' => 0, 'repost' => 0, 'fav' => 0, 'share' => 0];
    if (!isset($PRIMED_MEME_IDS[$meme_id]) && !array_key_exists($meme_id, $COUNT_CACHE)) {
        $counts = [];
        foreach (['like' => 'likes', 'repost' => 'reposts', 'fav' => 'favorites', 'share' => 'shares'] as $key => $table) {
            $r = $conn->query("SELECT COUNT(*) c FROM $table WHERE meme_id=$meme_id");
            $row = $r ? $r->fetch_assoc() : null;
            $counts[$key] = $row ? (int)$row['c'] : 0;
        }
        $COUNT_CACHE[$meme_id] = $counts;
    }
    return ($COUNT_CACHE[$meme_id] ?? []) + $defaults;
}

/* did the current viewer like / repost / favorite this meme */
function get_meme_interactions($meme_id, $current_user) {
    global $INTERACTION_CACHE, $PRIMED_MEME_IDS, $conn;
    if (!$current_user) return ['liked' => false, 'reposted' => false, 'faved' => false];
    $meme_id = (int)$meme_id;
    if (!isset($PRIMED_MEME_IDS[$meme_id])) {
        $c = [];
        foreach (['liked' => 'likes', 'reposted' => 'reposts', 'faved' => 'favorites'] as $key => $table) {
            $r = $conn->query("SELECT id FROM $table WHERE meme_id=$meme_id AND user_id=" . (int)$current_user . " LIMIT 1");
            if ($r && $r->num_rows > 0) $c[$key] = true;
        }
        $INTERACTION_CACHE[$meme_id] = $c;
    }
    $c = $INTERACTION_CACHE[$meme_id] ?? [];
    return ['liked' => !empty($c['liked']), 'reposted' => !empty($c['reposted']), 'faved' => !empty($c['faved'])];
}

/* ══════════════════════════════════════════════════════════
   ONE card builder for every tab.
   $feedVarName = JS array the modal reads (profileFeed / repostFeed / tagsFeed / favsFeed)
   $ts_override = the time the OWNER reposted / saved it (drives Newest / Oldest), or null
   $context     = label shown in the post modal ('Reposted' | 'Tagged' | 'Saved' | '')
   $cap_icon    = small icon in front of the caption
══════════════════════════════════════════════════════════ */
/* green dot in the reel / immersive viewers — any poster who is online right now (last activity within 5 minutes), never your own posts,
   and nothing shows when EITHER person has "Show online status" switched off. Cached per request. */
if (!function_exists('dz_poster_online')) {
    function dz_poster_online($uid) {
        global $conn, $current_user;
        static $cache = [];
        $uid = (int)$uid; $viewer = (int)($current_user ?? 0);
        if ($uid <= 0 || $uid === $viewer || !isset($conn) || !$conn) return false;
        if (isset($cache[$uid])) return $cache[$uid];
        $cache[$uid] = false;
        try {
            if (function_exists('dz_privacy_load')) {
                $a = dz_privacy_load($uid); $b = dz_privacy_load($viewer);
                if (empty($a['show_online']) || empty($b['show_online'])) return false;
            }
            foreach (['last_seen', 'last_active', 'last_activity', 'last_online'] as $col) {
                $r = @$conn->query("SELECT TIMESTAMPDIFF(SECOND, `$col`, NOW()) AS d FROM users WHERE id = " . $uid . " AND `$col` IS NOT NULL LIMIT 1");
                if ($r instanceof mysqli_result) { $row = $r->fetch_assoc(); if ($row && $row['d'] !== null && (int)$row['d'] <= 300) { $cache[$uid] = true; break; } }
            }
        } catch (Throwable $e) {}
        return $cache[$uid];
    }
}

function buildMemeCard($row, $conn, $current_user, $profile_id, $pinned_meme_ids, $feedVarName, $ts_override = null, $context = '', $cap_icon = 'ri-home-5-line') {
    global $profile_online;
    $created   = strtotime($row['created_at'] ?? 'now');
    $diff      = time() - $created;
    $is_new    = ($diff <= 86400);
    $is_fading = ($diff > 86400 && $diff <= 172800);
    $mid       = (int)$row['id'];
    $uid       = (int)$row['uploaded_by'];
    $isOwner   = ($current_user == $profile_id);
    $isPinned  = ($context === '') && in_array($mid, $pinned_meme_ids, true);   /* pin = Memes grid only */

    /* reposts come back as creator / creator_pic, everything else as username / uploader_pic */
    $uname = (string)($row['username'] ?? ($row['creator'] ?? ''));
    $upic  = !empty($row['uploader_pic']) ? $row['uploader_pic'] : (!empty($row['creator_pic']) ? $row['creator_pic'] : 'mee.jpg');

    $is_text = ($row['type'] === 'text' || empty($row['file_name']) || $row['file_name'] === '0');
    $mf      = $is_text ? 'uploads/Text.jpg' : 'uploads/' . $row['file_name'];
    $ntype   = $is_text ? 'text' : $row['type'];

    $meta   = get_poster_meta($conn, $mid, $uid, $current_user);
    $media  = get_meme_media_items($conn, $mid, $mf, $ntype);
    $group  = get_meme_group_info($conn, $mid);
    $audio  = get_meme_audio_info($conn, $mid);
    $counts = get_meme_counts($mid);
    $inter  = get_meme_interactions($mid, $current_user);

    /* everything the post modal needs. Strings go through json_encode, so quotes / line breaks in a
       caption can never break the page. caption + username stay HTML-escaped, exactly as before. */
    $feed = [
        'id' => $mid, 'file' => $mf, 'type' => $row['type'],
        'pic' => $upic, 'userId' => $uid, 'username' => htmlspecialchars($uname), 'isVerified' => dz_vb_flag($conn, $uid),
        'caption' => htmlspecialchars((string)($row['caption'] ?? '')), 'time' => (string)($row['created_at'] ?? ''),
        'isYou' => ($current_user == $uid),
        'likeCount' => $counts['like'], 'repostCount' => $counts['repost'], 'favCount' => $counts['fav'], 'shareCount' => $counts['share'],
        'liked' => $inter['liked'], 'reposted' => $inter['reposted'], 'faved' => $inter['faved'],
        'isPinned' => $isPinned,
        'nickname' => (string)$meta['nickname'], 'isFollowing' => (bool)$meta['is_following'], 'socialProof' => $meta['social_proof'], 'socialSpare' => $meta['social_spare'] ?? [],
        'media' => $media, 'context' => $context,
        'groupId' => $group ? (int)$group['group_id'] : null,
        'groupTitle' => $group ? $group['group_name'] : null,
        'connectedMemeId' => !empty($row['connected_meme_id']) ? (int)$row['connected_meme_id'] : null,
        'connectedLabel' => !empty($row['connected_label']) ? $row['connected_label'] : null,
        'audioId' => $audio ? (int)$audio['audio_id'] : null,
        'audioIsOriginal' => $audio ? (bool)(int)($audio['is_original'] ?? 0) : false,
        'allowDownload' => (int)($row['allow_download'] ?? 1),
        'collab' => dz_pf_collab($conn, $mid, $current_user), 'poll' => dz_pf_poll($conn, $mid, $current_user),
        'audioFile' => $audio ? 'uploads/' . $audio['file_name'] : null,
        'audioTitle' => $audio ? $audio['title'] : null,
        'audioArtist' => ($audio && !empty($audio['artist'])) ? $audio['artist'] : null,
        'musicVolume' => ($audio && $audio['music_volume'] !== '' && $audio['music_volume'] !== null) ? (int)$audio['music_volume'] : 80,
        'videoAudioVolume' => ($audio && $audio['video_audio_volume'] !== '' && $audio['video_audio_volume'] !== null) ? (int)$audio['video_audio_volume'] : 40,
        'isPartnership' => !empty($row['is_partnership']),
        'partnerBrand' => !empty($row['partner_brand']) ? $row['partner_brand'] : '',
        'isAd' => !empty($row['is_ad']),
        'adCtaText' => !empty($row['ad_cta_text']) ? $row['ad_cta_text'] : null,
        'adCtaUrl' => !empty($row['ad_cta_url']) ? $row['ad_cta_url'] : null,
        'adCtaColor' => !empty($row['ad_cta_color']) ? $row['ad_cta_color'] : null,
        'isAiGenerated' => !empty($row['is_ai_generated']),
        'online' => ((!empty($profile_online) && (int)$uid === (int)$profile_id) || dz_poster_online($uid)),   // green dot in the reel modal (poster = this profile's owner, only when online status is visible)
    ];
    $feed = dzx_apply_hide($feed, $conn, $mid, $current_user);
    $feed_json = json_encode($feed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR);

    /* arguments for the inline openMemeModal(...) click handler.
       Each text argument is JSON-encoded with the quote/&/< characters hex-escaped (\u0027, \u0022 …) and line breaks
       escaped, so an apostrophe, a quote or a line break in a caption / username can never break the onclick attribute. */
    $__js = function ($v) { return htmlspecialchars(json_encode((string)$v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR), ENT_QUOTES); };   /* the JSON's own " delimiters become &quot; so they sit safely inside the double-quoted attribute */
    $js_type = $is_text ? 'image' : $row['type'];
    $open    = 'openMemeModal(' . $__js($mf) . ',' . $__js($js_type) . ',' . $__js(htmlspecialchars((string)($row['caption'] ?? ''))) . ',' . $__js($upic) . ',' . $__js(htmlspecialchars($uname)) . ',' . $uid . ',' . $__js($feedVarName) . ',' . $mid . ')';

    $data_ts = ($ts_override !== null) ? (int)$ts_override : (int)$created;
    $data_pop = (int)$counts['like'] + 2 * (int)$counts['repost'] + 2 * (int)$counts['fav'];   // Popular = likes + 2×reposts + 2×saves

    ob_start(); ?>
    <script><?= $feedVarName ?>.push(<?= $feed_json ?>);</script>
    <div class="meme-card<?= $is_new ? ' is-new' : '' ?><?= $is_fading ? ' is-fading' : '' ?>" data-id="<?= $mid ?>" data-ts="<?= $data_ts ?>" data-pop="<?= $data_pop ?>" data-pinned="<?= $isPinned ? '1' : '0' ?>" data-uid="<?= $uid ?>" data-kind="<?php $__k = $is_text ? 'text' : (($row['type'] === 'video') ? 'video' : 'image'); if (!$is_text) { foreach ((array)$media as $__mi) { if (strtolower((string)($__mi['type'] ?? '')) === 'video') { $__k = 'video'; break; } } } echo $__k; ?>" data-search="<?= htmlspecialchars(mb_strtolower(trim((string)($row['caption'] ?? '') . ' ' . $uname)), ENT_QUOTES) ?>">
        <div class="top-bar">
            <img src="<?= htmlspecialchars(function_exists('dz_thumb_url') ? dz_thumb_url($upic, 96) : $upic) ?>" loading="lazy" decoding="async" onerror="this.onerror=null;this.src='<?= htmlspecialchars($upic, ENT_QUOTES) ?>'" onclick="window.location.href='profile.php?user_id=<?= $uid ?>'">
            <div class="username" onclick="window.location.href='profile.php?user_id=<?= $uid ?>'"><?= ($current_user == $uid) ? 'You' : htmlspecialchars($uname) ?></div>
        </div>
        <div class="media-box">
            <?php /* cover photo: when the post has one, every grid uses it as the thumbnail (the post itself still opens the real file) */
                  $thumb = (!$is_text && !empty($row['cover_image'])) ? 'uploads/' . $row['cover_image'] : 'uploads/' . $row['file_name']; ?>
            <?php if ($row['type'] === 'image' || ($row['type'] === 'video' && !empty($row['cover_image']))): ?>
                <img src="<?= htmlspecialchars(function_exists('dz_thumb_url') ? dz_thumb_url($thumb, 480) : $thumb) ?>" loading="lazy" decoding="async" onerror="this.onerror=null;this.src='<?= htmlspecialchars($thumb, ENT_QUOTES) ?>'" onclick="<?= $open ?>">
            <?php elseif ($row['type'] === 'video'): ?>
                <video class="lazy-video" data-src="uploads/<?= htmlspecialchars($row['file_name']) ?>" muted playsinline preload="none" onclick="<?= $open ?>"></video>
            <?php else: ?>
                <img src="uploads/Text.jpg" loading="lazy" decoding="async" onclick="<?= $open ?>">
            <?php endif; ?>
            <?php if ($diff <= 172800): ?><div class="new-badge">New</div><?php endif; ?>
            <?php if (empty($row['is_ad'])): /* ads are never counted, so they carry no view icon / number */ ?>
            <div class="card-views" title="Views"><i class="ri-eye-fill"></i><span><?= pf_h(pf_fmt_views(isset($row['views']) ? (int)$row['views'] : pf_view_count($conn, $mid))) ?></span></div>
            <?php endif; ?>
            <?php /* PIN — owner: a pin button (white = not pinned, red = pinned, tap to toggle).
                     Everybody else only sees the red pin on a pinned post and can never unpin it. */ ?>
            <?php if ($isOwner && $context === ''): ?>
            <button type="button" class="card-pin-btn<?= $isPinned ? ' pinned' : '' ?>" data-meme-id="<?= $mid ?>" aria-pressed="<?= $isPinned ? 'true' : 'false' ?>" title="<?= $isPinned ? 'Unpin from profile' : 'Pin to profile' ?>" aria-label="Pin post" onclick="event.stopPropagation();gTogglePin(<?= $mid ?>,this)"><i class="ri-pushpin-<?= $isPinned ? 'fill' : 'line' ?>"></i></button>
            <?php elseif ($isPinned): ?><div class="card-pin-indicator"><i class="ri-pushpin-fill"></i></div><?php endif; ?>
            <?php /* long-press (0.25s) on a Memes-grid card opens the Post options sheet (script near #pmSheet) */ ?>
            <?php /* + circle — opens the Save-to-Collection sheet (openSaveModal) */ ?>
            <?php if ($current_user): ?>
            <button type="button" class="card-add-col-btn" title="Add to Collection" aria-label="Add to Collection" onclick="event.stopPropagation();dzSaveOpen(<?= $mid ?>)"><svg class="cab-plus" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v18M3 12h18" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round"/></svg></button>
            <?php endif; ?>
        </div>
        <div class="caption"><i class="<?= htmlspecialchars($cap_icon) ?>"></i><?= htmlspecialchars((string)($row['caption'] ?? '')) ?></div>
        <?php if ($context === '' || $context === 'Saved'): /* List layout (Memes + Saved grids): caption + small meta line to the right of the thumbnail */ ?>
        <div class="lst-body" onclick="<?= $open ?>">
            <div class="lst-cap"><?= htmlspecialchars((string)($row['caption'] ?? '')) ?></div>
            <div class="lst-meta"><?= date('M j, Y', $created) ?> · <?= ['video' => 'Video', 'text' => 'Text'][$__k] ?? 'Photo' ?></div>
        </div>
        <?php endif; ?>
        <div class="icon-bar">
            <a href="download.php?file=<?= urlencode((string)($row['file_name'] ?? '')) ?>" title="Download"><i class="ri-download-line"></i></a>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
?>

<?php /* ══════════════════════════════════════════════════════════════════
   TAB PAGER — the tab panels sit side by side and follow your finger,
   exactly like the Tracks page. Only the open panel is laid out while idle,
   so the page height stays correct. (Its CSS now lives in the main <style> block at the top.)
══════════════════════════════════════════════════════════════════ */ ?>

<?php /* Messages data (conversations + message requests) is read HERE, before the dock, because the Requests
   count is needed by the filter buttons in the dock. The Messages panel further down just uses the results. */ ?>
<?php if ($_tabs_allowed && $is_owner_view_c): ?>
<?php
/* message requests (restricted users) */
$profile_req_count = 0;
$profile_requests  = [];
$_cu = (int)$current_user;
$req_res = $conn->query("
    SELECT
        u.id, u.username, u.profile_pic,
        m.message AS last_message,
        m.file_attachment AS last_attachment,
        m.created_at AS last_time,
        m.sender_id,
        m.seen,
        (SELECT COUNT(*) FROM messages
         WHERE sender_id = u.id AND receiver_id = $_cu AND seen = 0) AS unread_count
    FROM (
        SELECT
            CASE WHEN sender_id = $_cu THEN receiver_id ELSE sender_id END AS other_user_id,
            MAX(id) AS last_msg_id
        FROM messages
        WHERE sender_id = $_cu OR receiver_id = $_cu
        GROUP BY other_user_id
    ) AS convos
    JOIN messages m ON m.id = convos.last_msg_id
    JOIN users u ON u.id = convos.other_user_id
    WHERE 1=1 $DZ_NOT_DEACT_U AND u.id IN (
        SELECT restricted_id FROM restricted_users WHERE restrictor_id = $_cu
    )
    ORDER BY m.created_at DESC
");
if ($req_res && $req_res->num_rows > 0) {
    $profile_req_count = $req_res->num_rows;
    while ($r = $req_res->fetch_assoc()) $profile_requests[] = $r;
}
$restricted_ids = array_column($profile_requests, 'id');

/* the normal conversation list (restricted users only appear under Requests) */
$all_messages = [];
if (isset($messages) && $messages && $messages->num_rows > 0) {
    while ($m = $messages->fetch_assoc()) {
        if (in_array($m['id'], $restricted_ids)) continue;
        $all_messages[] = $m;
    }
}

/* ── GROUP CHATS (msg_gc_* tables) — merged into the same list, newest first ── */
/* where a group chat opens. Change this ONE line if your group chat lives elsewhere (%d = group id) */
$GC_LINK    = 'messages.php?group=%d';
$group_rows = [];
$gq = $conn->query("
    SELECT g.id, g.name,
           COALESCE(lm.message, '')         AS last_message,
           COALESCE(lm.file_attachment, '') AS last_attachment,
           COALESCE(lm.created_at, g.created_at, mem.joined_at) AS last_message_time,
           COALESCE(lm.sender_id, 0)        AS sender_id,
           su.username                      AS sender_name,
           (SELECT COUNT(*) FROM msg_gc_messages x
             WHERE x.group_id = g.id AND x.id > COALESCE(r.last_read_id, 0)
               AND x.sender_id != $_cu) AS unread_count,
           (SELECT COUNT(*) FROM msg_gc_messages y WHERE y.group_id = g.id) AS message_count
    FROM msg_gc_members mem
    JOIN msg_gc_groups g     ON g.id = mem.group_id
    LEFT JOIN msg_gc_reads r ON r.group_id = g.id AND r.user_id = $_cu
    LEFT JOIN msg_gc_messages lm
           ON lm.id = (SELECT MAX(id) FROM msg_gc_messages WHERE group_id = g.id)
    LEFT JOIN users su       ON su.id = lm.sender_id
    WHERE mem.user_id = $_cu
");
if ($gq) {
    while ($gr = $gq->fetch_assoc()) {
        $gr['is_group']          = 1;
        $gr['seen']              = 0;
        $gr['username']          = $gr['name'];
        $gr['profile_pic']       = '';
        $group_rows[]            = $gr;
    }
}
/* group avatars: up to 3 member pics (others first, joined order; viewer only if <3 others) — ONE query for all groups */
$gpics = [];
if ($group_rows) {
    $gids = implode(',', array_map(function ($g) { return (int)$g['id']; }, $group_rows));
    $gmq = $conn->query("SELECT gm.group_id, u.id, u.profile_pic FROM msg_gc_members gm JOIN users u ON u.id = gm.user_id WHERE gm.group_id IN ($gids) ORDER BY gm.group_id, gm.joined_at ASC, u.id ASC");
    $gmem = [];
    if ($gmq) { while ($x = $gmq->fetch_assoc()) $gmem[(int)$x['group_id']][] = $x; }
    foreach ($gmem as $gid => $mems) {
        $pp = [];
        foreach ($mems as $mm) if ((int)$mm['id'] !== $_cu) $pp[] = $mm['profile_pic'] ?: 'mee.jpg';
        if (count($pp) < 3) foreach ($mems as $mm) if ((int)$mm['id'] === $_cu) { $pp[] = $mm['profile_pic'] ?: 'mee.jpg'; break; }
        $gpics[$gid] = array_slice($pp, 0, 3);
    }
}
foreach ($all_messages as &$__m) { $__m['is_group'] = 0; } unset($__m);
$all_messages = array_merge($all_messages, $group_rows);
usort($all_messages, function ($a, $b) {
    return strtotime((string)($b['last_message_time'] ?? '')) <=> strtotime((string)($a['last_message_time'] ?? ''));
});
?>
<?php endif; ?>

<?php /* ══ SHARED DOCK — the buttons that must NOT slide with the panels ══
   Newest · Popular · Oldest · Collections (one bar per grid tab) and All · Unread · Dry Chat · Requests
   (Messages) all sit HERE, above the pager, so they stay exactly where they are while the panels slide
   underneath. Only the open tab's bar shows; the pager script swaps them. Every id (memesSortBar, memesN,
   memesP, memesO, memesC … and msgFilterBar, mpAll, mpUnread, mpDry, mpRequests) is unchanged. */ ?>
<?php if ($_tabs_allowed): ?>
<div class="rp-sortdock" id="rpSortDock">
    <?= dz_sort_bar_html('memes',   $col_count_total, false, $is_owner_view_c && !empty($memes_rows)) ?>
    <?= dz_sort_bar_html('reposts', $col_count_total, true, $is_owner_view_c) ?>
    <?= dz_sort_bar_html('tags',    $col_count_total, true, $is_owner_view_c) ?>
    <?php if ($is_owner_view_c): ?><?= dz_sort_bar_html('favs', $col_count_total, true, true) ?><?php endif; ?>
    <?php if (!empty($dz_has_videos)): /* Videos tab: Newest | Popular | Oldest (same pills as the other grids) */ ?>
    <div class="grid-sort-bar" id="videosSortBar" style="display:none">
        <button type="button" class="g-pill active" id="videosN" onclick="dzVdSort('newest')">Newest</button>
        <button type="button" class="g-pill g-pill-pop" id="videosP" onclick="dzVdSort('popular')">Popular</button>
        <button type="button" class="g-pill" id="videosO" onclick="dzVdSort('oldest')">Oldest</button>
        <button type="button" class="g-pill" id="videosC" onclick="gOpenCol('videos')"><i class="ri-folder-3-line"></i>Collections<span class="g-pill-count"><?= $col_count_total > 0 ? dezzFmt($col_count_total) : '' ?></span></button>
    </div>
    <?php endif; ?>
    <?php if ($is_owner_view_c): ?>
    <div class="msg-filter-bar" id="msgFilterBar" style="display:none">
        <button type="button" class="msg-pill active" id="mpAll" onclick="msgFilter('all')">All</button>
        <button type="button" class="msg-pill" id="mpUnread" onclick="msgFilter('unread')"><i class="ri-mail-unread-line"></i>Unread</button>
        <button type="button" class="msg-pill" id="mpDry" onclick="msgFilter('dry')"><i class="ri-zzz-line"></i>Dry Chat</button>
        <?php if ($profile_req_count > 0): ?>
        <button type="button" class="msg-pill" id="mpRequests" onclick="msgFilter('requests')"><i class="ri-lock-line"></i>Requests<span class="req-badge"><?= $profile_req_count ?></span></button>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($is_owner_view_c): /* search drop-down for Memes / Reposts / Tags / Saved (opened by the search icon in the bar) */ ?>
    <div class="grid-search-wrap" id="gridSearchWrap" aria-hidden="true">
        <div class="grid-search-inner">
            <div class="grid-search-field">
                <input type="text" class="msg-search-input" id="gridSearchInput" placeholder="Search posts…" autocomplete="off" oninput="dzGridSearch(this.value)">
                <button type="button" class="grid-search-clear" id="gridSearchClear" onclick="dzGridSearchClear()" aria-label="Clear search"><i class="ri-close-line"></i></button>
            </div>
            <div class="grid-search-recent" id="gridSearchRecent"></div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="rp-pager idle" id="rpPager">
<div class="rp-track" id="rpTrack">

<?php if (!$_tabs_allowed): ?>
<?php
/* ══ WALLED / BLOCKED ══
   Private & Full Private (not approved yet), blocked by the owner, or blocked by you.
   EVERY tab a visitor can open (Memes, Reposts, Tags) gets the same boxy state, so a
   locked profile never shows an empty or broken tab. */
foreach (['memes' => 'memes and posts', 'reposts' => 'reposts', 'tags' => 'tagged posts'] as $_sid => $_what): ?>
<div id="<?= $_sid ?>" class="dropdown-section<?= $_sid === 'memes' ? ' rp-active' : '' ?>">
    <?= dz_wall_state_html($_what) ?>
</div>
<?php endforeach; ?>

<?php else: ?>

<?php
/* ══════════ MEMES ══════════ */
ob_start();
if (empty($memes_rows)) {
    echo $is_owner_view_c
        ? dz_empty_state(
            'No memes yet',
            'Your profile is a blank canvas. Post your first meme and it will show up here for everyone who tracks you.',
            [['ri-upload-2-line', 'Images, videos, GIFs and text posts all work.'],
             ['ri-team-line',     'Post into a Pod to reach people who love the same humor.']],
            ['Create your first meme', 'upload.php'])
        : dz_empty_state(
            'Nothing posted yet',
            $_uname_c . " hasn't shared any memes. When they do, they'll appear here.",
            [['ri-user-add-line',        'Track ' . $_uname_c . ' so their first post lands in your feed.'],
             ['ri-notification-3-line',  'New posts show up as soon as they go live.']],
            ['Explore memes', 'home.php']);
} else {
    echo '<script>var profileFeed = [];</script>';
    foreach (dezz_pinned_first($memes_rows, $pinned_meme_ids) as $row) {
        echo buildMemeCard($row, $conn, $current_user, $profile_id, $pinned_meme_ids, 'profileFeed', null, '', 'ri-home-5-line');
    }
}
echo dz_tab_section_html('memes', 'memes', ob_get_clean(), $is_owner_view_c, $col_count_total);


/* ══════════ REPOSTS ══════════ */
ob_start();
if (empty($reposts_rows)) {
    echo $is_owner_view_c
        ? dz_empty_state(
            "You haven't reposted anything",
            'Memes you repost will show up here, right on your profile.',
            [['ri-repeat-line', 'Tap repost on any meme you want to share with the people who track you.'],
             ['ri-time-line',   'Your newest reposts appear first. Flip to Oldest any time.']],
            ['Find memes to repost', 'home.php'])
        : dz_empty_state(
            'No reposts yet',
            $_uname_c . " hasn't reposted anything. Reposts they share will appear here.",
            [['ri-repeat-line', 'Reposts show the memes a person loves enough to share.']],
            ['Explore memes', 'home.php']);
} else {
    echo '<script>var repostFeed = [];</script>';
    foreach ($reposts_rows as $row) {
        $_ts = $REPOST_TS_CACHE[(int)$row['id']] ?? null;
        echo buildMemeCard($row, $conn, $current_user, $profile_id, $pinned_meme_ids, 'repostFeed', $_ts ? strtotime($_ts) : null, 'Reposted', 'ri-repeat-line');
    }
}
echo dz_lazy_section('reposts', 'reposts', ob_get_clean(), $is_owner_view_c, $col_count_total);

/* ══════════ VIDEOS (long-form) — list layout; tapping a row opens the video in the long form modal ══════════ */
$_vd_rows = []; $_vd_more = false;
try {
    $_vdq = $conn->prepare("
        SELECT p.*, wp.progress_seconds
        FROM longform_posts p
        LEFT JOIN longform_watch_progress wp ON wp.post_id = p.id AND wp.user_id = ?
        WHERE p.user_id = ? AND p.status = 'published'
        ORDER BY p.created_at DESC, p.id DESC
        LIMIT 101");
    if ($_vdq) {
        $_vd_viewer = (int)$current_user; $_vd_owner = (int)$profile_id;
        $_vdq->bind_param('ii', $_vd_viewer, $_vd_owner);
        $_vdq->execute();
        $_vdr = $_vdq->get_result();
        while ($_vdr && ($_vrow = $_vdr->fetch_assoc())) $_vd_rows[] = $_vrow;
        $_vdq->close();
    }
} catch (Throwable $e) { $_vd_rows = []; }   /* long form tables not created yet */
if (count($_vd_rows) > 100) { $_vd_more = true; $_vd_rows = array_slice($_vd_rows, 0, 100); }
/* Popular = likes + saves + comments + shares of each video (a missing table counts as 0) */
$_vd_pop = [];
if ($_vd_rows) {
    $_vd_ids = implode(',', array_map(function ($r) { return (int)$r['id']; }, $_vd_rows));
    foreach (['longform_post_likes', 'longform_post_saves', 'longform_comments', 'longform_post_shares'] as $_vt) {
        try {
            $__q = $conn->query("SELECT post_id, COUNT(*) AS c FROM $_vt WHERE post_id IN ($_vd_ids) GROUP BY post_id");
            while ($__q instanceof mysqli_result && ($__r = $__q->fetch_assoc())) $_vd_pop[(int)$__r['post_id']] = ($_vd_pop[(int)$__r['post_id']] ?? 0) + (int)$__r['c'];
        } catch (Throwable $e) {}
    }
}
$_vd_fmt_dur = function ($sec) { $sec = max(0, (int)$sec); $h = intdiv($sec, 3600); $m = intdiv($sec % 3600, 60); $x = $sec % 60; return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $x) : sprintf('%d:%02d', $m, $x); };
$_vd_fmt_ago = function ($ts) { $t = strtotime((string)$ts); if (!$t) return ''; $d = max(0, time() - $t);
    if ($d < 60) return 'now'; foreach ([['y', 31536000], ['mo', 2592000], ['w', 604800], ['d', 86400], ['h', 3600], ['m', 60]] as $u) { if ($d >= $u[1]) return floor($d / $u[1]) . $u[0] . ' ago'; } return 'now'; };
ob_start();
if (empty($_vd_rows)) {
    echo $is_owner_view_c
        ? dz_empty_state('No videos yet', 'Long form videos you post will show up here for everyone who visits your profile.',
            [['ri-movie-2-line', 'Share tutorials, stories and anything longer than a normal post.']],
            ['Post a video', 'profile_longform.php?user_id=' . (int)$profile_id])
        : dz_empty_state('No videos yet', $_uname_c . " hasn't posted any long form videos. When they do, they'll appear here.",
            [['ri-user-add-line', 'Track ' . $_uname_c . ' so you see their videos first.']],
            ['Explore memes', 'home.php']);
} else {
    echo '<div class="vd-list" id="videosGrid">';
    foreach ($_vd_rows as $_v) {
        $_vid = (int)$_v['id'];
        $_vp  = (!empty($_v['progress_seconds']) && (int)$_v['duration_seconds'] > 0) ? round(max(0, min(100, ((int)$_v['progress_seconds'] / (int)$_v['duration_seconds']) * 100)), 1) : 0;
        /* Newest / Oldest use the posted time; Popular = likes + saves + comments + shares */
        $_vts = (int)strtotime((string)$_v['created_at']); $_vpop = (int)($_vd_pop[$_vid] ?? 0);
        echo '<a class="vd-row" data-ts="' . $_vts . '" data-pop="' . $_vpop . '" data-id="' . $_vid . '" href="profile_longform.php?user_id=' . (int)$profile_id . '&amp;post=' . $_vid . '" data-lf-id="' . $_vid . '" onclick="return dzOpenLf(' . $_vid . ',event)">'
           . '<div class="vd-thumb"><img src="' . htmlspecialchars((string)$_v['thumbnail_path'], ENT_QUOTES, 'UTF-8') . '" alt="" loading="lazy" draggable="false" onerror="this.style.display=\'none\'">'
           . ($_vp > 0 ? '<div class="vd-prog"><span style="width:' . $_vp . '%"></span></div>' : '') . '</div>'
           . '<div class="vd-body"><div class="vd-title">' . htmlspecialchars((string)$_v['title'], ENT_QUOTES, 'UTF-8') . '</div>'
           . '<div class="vd-meta">' . htmlspecialchars($_vd_fmt_dur($_v['duration_seconds']) . ' · ' . $_vd_fmt_ago($_v['created_at']), ENT_QUOTES, 'UTF-8') . '</div></div></a>';
    }
    echo '</div>';
    if ($_vd_more) echo '<a class="vd-all" href="profile_longform.php?user_id=' . (int)$profile_id . '">See all videos</a>';
}
if ($dz_has_videos) { echo '<div id="videos" class="dropdown-section">' . ob_get_clean() . dz_col_section_html('videos', false) . '</div>'; } else { ob_end_clean(); }

/* ══════════ TAGS ══════════ */
ob_start();
if (empty($tags_rows)) {
    echo $is_owner_view_c
        ? dz_empty_state(
            "You haven't been tagged yet",
            'When someone tags you in a meme, it will show up here.',
            [['ri-share-forward-line', 'Share your profile link so friends can find and tag you.'],
             ['ri-account-pin-box-line', 'Tagged memes show who posted them and when.']],
            ['Explore memes', 'home.php'])
        : dz_empty_state(
            'Not tagged yet',
            $_uname_c . " hasn't been tagged in any memes. Tagged posts will appear here.",
            [['ri-account-pin-box-line', 'Tagged memes show who posted them and when.']],
            ['Explore memes', 'home.php']);
} else {
    echo '<script>var tagsFeed = [];</script>';
    foreach ($tags_rows as $row) {
        echo buildMemeCard($row, $conn, $current_user, $profile_id, $pinned_meme_ids, 'tagsFeed', null, 'Tagged', 'ri-account-pin-box-line');
    }
}
echo dz_lazy_section('tags', 'tags', ob_get_clean(), $is_owner_view_c, $col_count_total);

/* ══════════ SAVED (favorites) — private: only the owner has this tab, so only the owner gets the panel ══════════ */
if ($is_owner_view_c) {
    ob_start();
    if (empty($favs_rows)) {
        echo dz_empty_state(
            'Nothing saved yet',
            'Save the memes you love and find them again here any time.',
            [['ri-bookmark-line', 'Tap the bookmark on any meme to save it.'],
             ['ri-lock-line',     'Your saved memes are private. Only you can see this tab.']],
            ['Browse memes', 'home.php']);
    } else {
        echo '<script>var favsFeed = [];</script>';
        foreach ($favs_rows as $row) {
            $_ts = $FAV_TS_CACHE[(int)$row['id']] ?? null;
            echo buildMemeCard($row, $conn, $current_user, $profile_id, $pinned_meme_ids, 'favsFeed', $_ts ? strtotime($_ts) : null, 'Saved', 'ri-bookmark-line');
        }
    }
    echo dz_lazy_section('favorites', 'favs', ob_get_clean(), $is_owner_view_c, $col_count_total);
}
?>

<script>
/* ══════════════════════════════════════════════════════════
   LAZY VIDEO PLAYBACK
   Videos ship with preload="none" + data-src. Nothing is fetched until the
   card is near the viewport; the first frame is shown as a still (no playback). Cards inside hidden tabs never intersect, so they
   load nothing until their tab is opened.
══════════════════════════════════════════════════════════ */
(function() {
    if (!('IntersectionObserver' in window)) {
        document.querySelectorAll('video.lazy-video[data-src]').forEach(function(v) { v.src = v.dataset.src; });
        return;
    }
    var videoObserver = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            var video = entry.target;
            if (entry.isIntersecting) {
                /* static thumbnail: load only the first frame (metadata), never play */
                if (!video.src && video.dataset.src) { video.preload = 'metadata'; video.src = video.dataset.src + '#t=0.1'; }
                videoObserver.unobserve(video);
            }
        });
    }, { root: null, rootMargin: '150px 0px', threshold: 0.25 });

    function observeAll(root) {
        (root || document).querySelectorAll('video.lazy-video[data-src]').forEach(function(v) {
            if (!v.dataset.observed) { videoObserver.observe(v); v.dataset.observed = '1'; }
        });
    }
    observeAll(document);

    /* cards added later (Collections detail, etc.) are picked up automatically */
    new MutationObserver(function(mutations) {
        mutations.forEach(function(m) {
            m.addedNodes && m.addedNodes.forEach(function(node) {
                if (node.nodeType !== 1) return;
                if (node.matches && node.matches('video.lazy-video[data-src]')) { videoObserver.observe(node); node.dataset.observed = '1'; }
                else if (node.querySelectorAll) observeAll(node);
            });
        });
    }).observe(document.body, { childList: true, subtree: true });
})();
</script>

<script>
/* ══════════════════════════════════════════════════════════
   SORT BUTTONS — Newest · Popular · Oldest  (one sorter for every tab)

   • Newest  = most recent first      (data-ts, newest → oldest)
   • Oldest  = oldest first           (data-ts, oldest → newest)
   • Popular = most popular first     (data-pop = likes + 2×reposts + 2×saves)
   Pinned posts always stay on top. Ties fall back to the post id, so the order
   is always stable and predictable.

   It re-orders ALL cards (including the ones not shown yet), re-orders the
   matching JS feed so the post modal's next / previous follows the new order,
   brings you back from the Collections view, then tells the infinite-scroll
   module to rebuild its pages from the new order.
══════════════════════════════════════════════════════════ */
function dzSort(gridId, mode, key) {
    dzHp('selection');
    var grid = document.getElementById(gridId);
    if (!grid) return;

    /* coming back from Collections: show the grid again */
    var colSec = document.getElementById(key + 'ColSec');
    if (colSec && window.getComputedStyle(colSec).display !== 'none') {
        colSec.style.display = 'none';
        grid.style.display = '';
        var colBtn = document.getElementById(key + 'C');
        if (colBtn) colBtn.classList.remove('active');
    }

    var cards = Array.prototype.filter.call(grid.children, function(el) {
        return el.classList && el.classList.contains('meme-card');
    });
    function n(el, k) { return +el.dataset[k] || 0; }

    cards.sort(function(a, b) {
        var pa = a.dataset.pinned === '1', pb = b.dataset.pinned === '1';
        if (pa !== pb) return pa ? -1 : 1;                       // pinned first
        var d;
        if (mode === 'oldest')       d = n(a, 'ts') - n(b, 'ts');
        else if (mode === 'popular') d = (n(b, 'pop') - n(a, 'pop')) || (n(b, 'ts') - n(a, 'ts'));
        else                         d = n(b, 'ts') - n(a, 'ts');
        if (d) return d;
        return mode === 'oldest' ? n(a, 'id') - n(b, 'id') : n(b, 'id') - n(a, 'id');
    });

    /* move every card in one go, before the loading sentinel / skeleton (if any) */
    var ref  = grid.querySelector('.dz-skel-card, .dz-sentinel');
    var frag = document.createDocumentFragment();
    cards.forEach(function(c) { frag.appendChild(c); });
    grid.insertBefore(frag, ref || null);

    /* keep the post modal's feed in the same order as the grid */
    var feed = grid.getAttribute('data-feed') ? window[grid.getAttribute('data-feed')] : null;
    if (Array.isArray(feed)) {
        var pos = {};
        cards.forEach(function(c, i) { pos[c.getAttribute('data-id')] = i; });
        feed.sort(function(a, b) {
            var ia = (pos[a.id] === undefined) ? 1e9 : pos[a.id];
            var ib = (pos[b.id] === undefined) ? 1e9 : pos[b.id];
            return ia - ib;
        });
    }

    /* highlight the chosen button */
    var bar = document.getElementById(key + 'SortBar');
    if (bar) bar.querySelectorAll('.g-pill').forEach(function(b) { b.classList.remove('active'); });
    var id = { newest: 'N', popular: 'P', oldest: 'O' }[mode] || 'N';
    var me = document.getElementById(key + id);
    if (me) me.classList.add('active');

    /* rebuild the infinite-scroll pages from the new order */
    function again() { if (typeof window.dzGridRefresh === 'function') window.dzGridRefresh(gridId); }
    setTimeout(again, 90);
    setTimeout(again, 380);
}

/* Videos tab: same Newest / Popular / Oldest pills, re-orders the rows of the video list */
function dzVdSort(mode) {
    try { dzHp('selection'); } catch (e) {}
    var list = document.querySelector('#videos .vd-list'); if (!list) return;
    var rows = Array.prototype.slice.call(list.querySelectorAll('.vd-row'));
    function n(el, k) { return +el.dataset[k] || 0; }
    rows.sort(function(a, b) {
        var d;
        if (mode === 'oldest')       d = n(a, 'ts') - n(b, 'ts');
        else if (mode === 'popular') d = (n(b, 'pop') - n(a, 'pop')) || (n(b, 'ts') - n(a, 'ts'));
        else                         d = n(b, 'ts') - n(a, 'ts');
        if (d) return d;
        return mode === 'oldest' ? n(a, 'id') - n(b, 'id') : n(b, 'id') - n(a, 'id');
    });
    var frag = document.createDocumentFragment(); rows.forEach(function(r) { frag.appendChild(r); }); list.appendChild(frag);
    var bar = document.getElementById('videosSortBar');
    if (bar) bar.querySelectorAll('.g-pill').forEach(function(b) { b.classList.remove('active'); });
    var me = document.getElementById({ newest: 'videosN', popular: 'videosP', oldest: 'videosO' }[mode] || 'videosN'); if (me) me.classList.add('active');
}

/* anything else on the page that still calls gSort() uses the same sorter */
window.gSort = function(gridId, mode, key) {
    dzSort(gridId, mode === 'oldest' ? 'oldest' : (mode === 'popular' ? 'popular' : 'newest'), key);
};
</script>

<!-- ══════════ MESSAGES (owner only — it is the only profile that has a Messages tab) ══════════ -->
<?php if ($is_owner_view_c): ?>
<div id="messages" class="dropdown-section">

<?php /* The All / Unread / Dry Chat / Requests buttons live in the shared dock above the pager (see #msgFilterBar), so they stay put while the panels slide. */ ?>

<?php if (!empty($all_messages) || $profile_req_count > 0): ?>
<!-- ── SEARCH BAR ── -->
<div class="msg-search-wrap" id="msgSearchWrap">
    <i class="ri-search-line msg-search-icon"></i>
    <input type="text" class="msg-search-input" id="msgSearchInput" placeholder="Search messages…" autocomplete="off" oninput="msgSearch(this.value)">
    <button type="button" class="msg-search-clear" id="msgSearchClear" onclick="msgClearSearch()">✕</button>
</div>

<!-- search found nobody → the same boxy empty state the grid tabs use -->
<div class="msg-empty-wrap" id="msgSearchNoResults" style="display:none;">
    <div class="es-wrap">
        <div class="es-title">No user found</div>
        <p class="es-text" id="msgSearchText">Nobody in your conversations matches your search.</p>
        <div class="es-tips">
            <div class="es-tip"><i class="ri-search-line"></i><span>Check the spelling or try another username.</span></div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── REQUESTS SECTION ── -->
<?php if ($profile_req_count > 0): ?>
<div class="msg-requests-section" id="msgRequestsSection">
    <div class="msg-requests-header"><i class="ri-lock-line"></i>Message Requests — only you can see these</div>
    <?php foreach ($profile_requests as $r):
        $r_preview = $r['last_message'] ?? '';
        $r_unread  = (int)$r['unread_count'];
        $r_attach  = $r['last_attachment'] ?? '';
    ?>
    <div class="msg-request-item" id="msgReq-<?= (int)$r['id'] ?>" data-username="<?= strtolower(htmlspecialchars($r['username'])) ?>">
        <img src="<?= htmlspecialchars($r['profile_pic'] ?? 'mee.jpg') ?>" onerror="this.onerror=null;this.src='mee.jpg'"
             onclick="event.stopPropagation();window.location.href='messages.php?user=<?= (int)$r['id'] ?>'" alt="">
        <div class="msg-request-info" onclick="window.location.href='messages.php?user=<?= (int)$r['id'] ?>'">
            <div class="msg-request-name">
                <?= htmlspecialchars($r['username']) ?>
                <span class="msg-request-tag">Request</span>
                <?php if ($r_unread > 0): ?><span class="msg-req-count"><?= $r_unread ?></span><?php endif; ?>
            </div>
            <div class="msg-request-preview">
                <?php
                if (!empty($r_attach)) {
                    $ext = strtolower(pathinfo($r_attach, PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg','jpeg','png','gif','webp']))  echo '📷 Photo';
                    elseif (in_array($ext, ['mp4','webm','mov','avi']))     echo '🎥 Video';
                    elseif (in_array($ext, ['mp3','aac','ogg','wav']))      echo '🎵 Audio';
                    else echo '📎 File';
                } elseif (trim($r_preview) === '[deleted]') {
                    echo '<i class="msg-deleted-req">Message deleted</i>';
                } else {
                    echo htmlspecialchars(mb_substr($r_preview, 0, 45)) . (mb_strlen($r_preview) > 45 ? '…' : '');
                }
                ?>
            </div>
        </div>
        <div class="msg-request-actions">
            <button type="button" class="msg-req-accept" onclick="event.stopPropagation();msgAcceptRequest(<?= (int)$r['id'] ?>, this)">Accept</button>
            <button type="button" class="msg-req-decline" onclick="event.stopPropagation();msgDeclineRequest(<?= (int)$r['id'] ?>, this)">Decline</button>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── CONVERSATIONS ── -->
<div class="msg-list" id="msgList">
<?php if (empty($all_messages)): ?>
    <?= dz_empty_state(
        'No messages yet',
        'Your conversations will show up here. Start one with someone you track.',
        [['ri-chat-1-line', 'Open anyone\'s profile and tap Message to say hi.'],
         ['ri-zzz-line',    'Chats that go quiet for a week are marked Dry, so you can pick them back up.']],
        ['Find people', 'search.php?focus=1']) ?>
<?php else: ?>
    <?php foreach ($all_messages as $m):
        $is_group = !empty($m['is_group']);
        $row_name = $is_group ? (string)$m['name'] : (string)$m['username'];
        $row_link = $is_group ? sprintf($GC_LINK, (int)$m['id']) : 'messages.php?user=' . (int)$m['id'];
        $has_unread = ($m['sender_id'] != $current_user && isset($m['unread_count']) && $m['unread_count'] > 0);
        if ($is_group) $has_unread = ((int)$m['unread_count'] > 0);
        $show_seen  = ($m['sender_id'] == $current_user && isset($m['seen']) && $m['seen'] == 1);
        $msg_count  = isset($m['message_count']) ? (int)$m['message_count'] : 0;
        $last_ts    = isset($m['last_message_time']) ? strtotime($m['last_message_time']) : 0;
        $inactive   = ($last_ts > 0 && (time() - $last_ts) > (7 * 86400));
    ?>
    <div class="message-user"
         data-unread="<?= $has_unread ? '1' : '0' ?>"
         data-count="<?= $msg_count ?>"
         data-ts="<?= $last_ts ?>"
         data-inactive="<?= $inactive ? '1' : '0' ?>"
         data-username="<?= strtolower(htmlspecialchars($row_name)) ?>"
         onclick="window.location.href='<?= htmlspecialchars($row_link, ENT_QUOTES) ?>'">

        <?php if ($is_group): ?>
        <?php $gp = $gpics[(int)$m['id']] ?? []; ?>
        <span class="gstack n<?= max(1, count($gp)) ?>">
          <?php if ($gp): foreach ($gp as $gpp): ?><img src="<?= htmlspecialchars($gpp) ?>" onerror="this.onerror=null;this.src='mee.jpg'" alt=""><?php endforeach; else: ?><img src="mee.jpg" alt=""><?php endif; ?>
        </span>
        <?php else: ?>
        <img src="<?= htmlspecialchars($m['profile_pic'] ?? 'mee.jpg') ?>" onerror="this.onerror=null;this.src='mee.jpg'" alt="">
        <?php endif; ?>

        <div class="message-user-info">
            <div class="message-user-name">
                <?= htmlspecialchars($row_name) ?>
                <?php if ($show_seen): ?><span class="msg-seen">Seen</span><?php endif; ?>
                <?php if ($has_unread): ?><span class="msg-unread-pill"><?= (int)$m['unread_count'] ?></span><?php endif; ?>
            </div>
            <div class="message-user-preview">
            <?php
                $preview    = $m['last_message'];
                $attachment = $m['last_attachment'] ?? '';
                $prefix     = ($m['sender_id'] == $current_user) ? '<span class="msg-you">You:</span> ' : (($is_group && !empty($m['sender_name'])) ? htmlspecialchars($m['sender_name']) . ': ' : '');

                if (!empty($attachment)) {
                    $ext = strtolower(pathinfo($attachment, PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg','jpeg','png','gif','webp']))  $ml = '<i class="ri-image-line msg-att-ico"></i> Photo';
                    elseif (in_array($ext, ['mp4','webm','mov','avi']))     $ml = '<i class="ri-video-line msg-att-ico"></i> Video';
                    elseif (in_array($ext, ['mp3','aac','ogg','wav']))      $ml = '<i class="ri-music-2-line msg-att-ico"></i> Audio';
                    else                                                    $ml = '<i class="ri-file-line msg-att-ico"></i> File';
                    if (!empty(trim($preview)) && trim($preview) !== '[deleted]') echo $prefix . $ml . ' · ' . htmlspecialchars(mb_substr($preview, 0, 30));
                    else echo $prefix . $ml;
                } elseif (trim($preview) === '[deleted]') {
                    echo $prefix . '<span class="msg-deleted">Message deleted</span>';
                } else {
                    echo $prefix . htmlspecialchars(mb_substr($preview, 0, 50)) . (mb_strlen($preview) > 50 ? '…' : '');
                }
            ?>
            </div>
        </div>

        <?php if ($inactive && !$has_unread): ?><span class="msg-dry-tag">DRY</span><?php endif; ?>
    </div>
    <?php endforeach; ?>

    <!-- filter (Unread / Dry Chat) found nothing → same boxy empty state; the text is filled in by msgFilter() -->
    <div class="msg-empty-wrap" id="msgFilterEmpty" style="display:none;">
        <div class="es-wrap">
            <div class="es-title" id="msgFETitle">Nothing here</div>
            <p class="es-text" id="msgFEText"></p>
            <div class="es-tips">
                <div class="es-tip"><i class="ri-chat-1-line" id="msgFEIcon"></i><span id="msgFETip"></span></div>
            </div>
        </div>
    </div>
<?php endif; ?>
</div>

<script>
var _msgFilter = 'all';
var _msgQuery  = '';
var _msgRaw    = '';

/* words for each filter's empty state */
var MSG_EMPTY = {
    all:    { icon:'ri-chat-1-line',        title:'No messages yet',
              text:'Your conversations will show up here. Start one with someone you track.',
              tip:'Open anyone\'s profile and tap Message to say hi.' },
    unread: { icon:'ri-mail-unread-line',   title:'No unread messages',
              text:'You are all caught up. Messages you have not opened yet will show up here.',
              tip:'A chat lands here the moment someone writes to you.' },
    active: { icon:'ri-fire-line',          title:'No highly active chats',
              text:'Chats with lots of back and forth will show up here.',
              tip:'Keep a conversation going and it will appear here.' },
    dry:    { icon:'ri-zzz-line',           title:'No dry chats',
              text:'Every conversation is still alive. Chats that go quiet for a week will show up here.',
              tip:'Send a message to bring a dry chat back to life.' }
};

function msgFillFilterEmpty(type) {
    var d = MSG_EMPTY[type] || MSG_EMPTY.all;
    var t = document.getElementById('msgFETitle'), x = document.getElementById('msgFEText'),
        i = document.getElementById('msgFEIcon'),  p = document.getElementById('msgFETip');
    if (t) t.textContent = d.title;
    if (x) x.textContent = d.text;
    if (i) i.className   = d.icon;
    if (p) p.textContent = d.tip;
}

/* one place decides which empty state (if any) is showing */
function msgSyncEmpty(visible) {
    var fe = document.getElementById('msgFilterEmpty');
    var se = document.getElementById('msgSearchNoResults');
    var searching = !!_msgQuery;
    if (fe) fe.style.display = (!searching && visible === 0) ? 'block' : 'none';
    if (se) {
        var showSearch = searching && visible === 0;
        se.style.display = showSearch ? 'block' : 'none';
        if (showSearch) {
            var tx = document.getElementById('msgSearchText');
            if (tx) tx.textContent = 'Nobody in your conversations matches \u201c' + _msgRaw + '\u201d. Check the spelling or try another name.';
        }
    }
}

function msgFilter(type) {
    dzHp('selection');
    _msgFilter = type;
    _msgQuery  = '';
    _msgRaw    = '';

    var si = document.getElementById('msgSearchInput');
    var sc = document.getElementById('msgSearchClear');
    var nr = document.getElementById('msgSearchNoResults');
    var fe = document.getElementById('msgFilterEmpty');
    if (si) si.value = '';
    if (sc) sc.style.display = 'none';
    if (nr) nr.style.display = 'none';
    if (fe) fe.style.display = 'none';

    ['All','Unread','Active','Dry','Requests'].forEach(function(k){
        var p = document.getElementById('mp' + k);
        if (p) p.classList.remove('active');
    });
    var activeMap = {all:'All', unread:'Unread', active:'Active', dry:'Dry', requests:'Requests'};
    var ap = document.getElementById('mp' + activeMap[type]);
    if (ap) ap.classList.add('active');

    var reqSec = document.getElementById('msgRequestsSection');
    var list   = document.getElementById('msgList');
    var searchWrap = document.getElementById('msgSearchWrap');

    if (type === 'requests') {
        if (reqSec) reqSec.classList.add('active');
        if (list) list.style.display = 'none';
        if (searchWrap) searchWrap.style.display = 'none';
        return;
    }

    if (reqSec) reqSec.classList.remove('active');
    if (list) list.style.display = 'flex';
    if (searchWrap) searchWrap.style.display = '';

    var items = document.querySelectorAll('#msgList .message-user');
    var visible = 0;
    items.forEach(function(el) {
        var unread   = el.dataset.unread === '1';
        var count    = parseInt(el.dataset.count) || 0;
        var inactive = el.dataset.inactive === '1';
        var show = false;
        if (type === 'all')    show = true;
        if (type === 'unread') show = unread;
        if (type === 'active') show = count >= 10;
        if (type === 'dry')    show = inactive;
        el.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    msgFillFilterEmpty(type);
    msgSyncEmpty(visible);
}

function msgSearch(query) {
    _msgRaw   = query.trim();
    _msgQuery = _msgRaw.toLowerCase();
    var sc = document.getElementById('msgSearchClear');
    if (sc) sc.style.display = _msgQuery ? 'block' : 'none';

    if (_msgFilter === 'requests') {
        var reqItems = document.querySelectorAll('.msg-request-item');
        var vis = 0;
        reqItems.forEach(function(el) {
            var name = el.dataset.username || '';
            var show = !_msgQuery || name.includes(_msgQuery);
            el.style.display = show ? '' : 'none';
            if (show) vis++;
        });
        /* with no query there is nothing to report in Requests, so keep both empty states hidden */
        msgSyncEmpty(_msgQuery ? vis : 1);
        return;
    }

    var items = document.querySelectorAll('#msgList .message-user');
    var visible = 0;
    items.forEach(function(el) {
        var name = el.dataset.username || '';
        var unread   = el.dataset.unread === '1';
        var count    = parseInt(el.dataset.count) || 0;
        var inactive = el.dataset.inactive === '1';
        var passesFilter = false;
        if (_msgFilter === 'all')    passesFilter = true;
        if (_msgFilter === 'unread') passesFilter = unread;
        if (_msgFilter === 'active') passesFilter = count >= 10;
        if (_msgFilter === 'dry')    passesFilter = inactive;
        var show = passesFilter && (!_msgQuery || name.includes(_msgQuery));
        el.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    msgFillFilterEmpty(_msgFilter);
    msgSyncEmpty(visible);
}

function msgClearSearch() {
    var si = document.getElementById('msgSearchInput');
    if (si) { si.value = ''; si.focus(); }
    msgSearch('');
}

function msgAcceptRequest(userId, btn) {
    dzHp('light');
    btn.disabled = true;
    btn.textContent = '…';
    fetch('messages.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'accept_request=1&from_user=' + userId
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if (d.success) {
            var item = document.getElementById('msgReq-' + userId);
            if (item) {
                item.classList.add('msg-req-out-right');
                setTimeout(function(){
                    item.remove();
                    window.location.href = 'messages.php?user=' + userId;   // open the now-accepted chat
                }, 300);
            }
            _updateReqBadge(-1);
        }
    })
    .catch(function(){ btn.disabled = false; btn.textContent = 'Accept'; });
}

function msgDeclineRequest(userId, btn) {
    dzHp('light');
    if (!confirm('Delete all messages from this user?')) return;
    btn.disabled = true;
    btn.textContent = '…';
    fetch('messages.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'decline_request=1&from_user=' + userId
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if (d.success) {
            var item = document.getElementById('msgReq-' + userId);
            if (item) {
                item.classList.add('msg-req-out-left');
                setTimeout(function(){ item.remove(); }, 300);
            }
            _updateReqBadge(-1);
        }
    })
    .catch(function(){ btn.disabled = false; btn.textContent = 'Decline'; });
}

function _updateReqBadge(delta) {
    var badge = document.querySelector('#mpRequests .req-badge');
    if (!badge) return;
    var count = parseInt(badge.textContent) + delta;
    if (count <= 0) {
        var btn = document.getElementById('mpRequests');
        if (btn) btn.remove();
        msgFilter('all');
    } else {
        badge.textContent = count;
    }
}
</script>

</div><!-- /#messages -->
<?php endif; ?>

<?php endif; ?>   <!-- /walled-or-blocked vs normal tabs -->

</div><!-- /.rp-track -->
</div><!-- /.rp-pager -->

</div><!-- /.rp-page -->

<!-- ═════════════════════════════════════════════════════════════
     RIGHT-HAND COLUMN — laptop / normal desktop view only (≥1100px).
     Every card = a grey bubble bar (like the action buttons) + its content.
     Cards with nothing to show are simply not rendered.
     ═════════════════════════════════════════════════════════════ -->
<?php if ($pf_side_ok || $pf_side_locked || !empty($DZ_SUSP)): ?>
<aside class="pf-side" id="pfSide" aria-label="Profile highlights and insights">
  <div class="pf-side-real" id="pfSideReal">
<?php if (!empty($DZ_SUSP)): /* suspended: no highlights / insights, just how suspensions work */ ?>
<style>
.dz-susp-side{ padding:2px 0 24px; }
.dz-susp-side .ss-bar{ display:flex; align-items:center; gap:7px; width:100%; height:calc(30px * var(--hd-s,1)); padding:0 12px; border-radius:var(--action-r,var(--rp-action-radius,5px)); background:rgba(var(--fg,255,255,255),.1); color:var(--text,#F0F2FF); font-size:.72rem; font-weight:600; }
.dz-susp-side .ss-bar i{ font-size:14px; color:var(--ss-accent,#FF2D2D); }
.dz-susp-side .ss-item{ padding:12px 4px 0; }
.dz-susp-side .ss-item b{ display:block; font-size:.76rem; font-weight:700; color:rgba(var(--fg,255,255,255),.95); margin-bottom:3px; }
.dz-susp-side .ss-item span{ display:block; font-size:.72rem; line-height:1.55; color:rgba(var(--fg,255,255,255),.62); }
.dz-susp-side .ss-link{ display:inline-block; margin:14px 4px 0; font-size:.72rem; font-weight:700; color:var(--ss-accent,#FF2D2D); text-decoration:none; }
</style>
<div class="dz-susp-side">
  <div class="ss-bar"><i class="ri-shield-keyhole-line"></i><span>About suspensions</span></div>
  <div class="ss-item"><b>What leads to a suspension</b><span>Harassment, hate speech, spam or bot activity, impersonation, or sharing harmful content.</span></div>
  <div class="ss-item"><b>How appeals work</b><span>Only the account's owner can appeal, within 180 days of the suspension. Most appeals are reviewed in a day or two.</span></div>
  <div class="ss-item"><b>If an appeal is approved</b><span>The account comes back exactly as it was, with nothing lost.</span></div>
  <div class="ss-item"><b>If it isn't</b><span>The account is permanently disabled and can't be appealed again.</span></div>
  <div class="ss-item"><b>See something wrong?</b><span>You can report any account or post from its menu.</span></div>
  <a class="ss-link" href="guidelines.php">Read the Community Guidelines</a>
</div>
<?php else: ?>
    <!-- cards are fetched AFTER the page has rendered (see the loader script at the bottom);
         the placeholder below shows only while that request is in flight -->
    <div class="pf-cards" id="pfCards">
      <?php for ($i = 0; $i < 3; $i++): ?>
        <div class="dz-sk pf-sk-bar"></div>
        <div class="pf-sk-grid"><?php for ($j = 0; $j < 6; $j++) echo '<div class="dz-sk"></div>'; ?></div>
      <?php endfor; ?>
    </div>
<?php endif; ?>
  </div>
</aside>
<?php endif; ?>

</div><!-- /.pf-shell -->



<!-- ══════════════════════════════════════
     PROFILE PIC MODAL — opens from tapping the avatar.
══════════════════════════════════════ -->
<?php
$pv_locked = false;   /* has the owner locked the profile picture viewer for other people? */
try {
    $__pvr = $conn->query("SELECT locked FROM profile_pic_lock WHERE user_id = " . (int)$profile_id . " LIMIT 1");
    if ($__pvr instanceof mysqli_result && ($__pvrow = $__pvr->fetch_assoc())) $pv_locked = !empty($__pvrow['locked']);
} catch (Throwable $e) { $pv_locked = false; }
?>
<script>window.DZ_PIC_LOCK = { locked: <?= $pv_locked ? 'true' : 'false' ?>, own: <?= ($current_user == $profile_id) ? 'true' : 'false' ?>, name: <?= json_encode((string)($user['username'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?> };</script>
<div id="picModal" class="modal">
    <span class="close" onclick="closePicModal()">&times;</span>
    <img class="modal-content" id="modalImg" src="<?= htmlspecialchars($profilePic) ?>" alt="Profile">

    <div class="modal-actions" id="picModalActions">
        <?php if($current_user != $profile_id && !$follow_check): ?>
            <?php if($profile_is_private && $track_request_status === 'pending'): ?>
            <a href="#" onclick="dzCancelRequest(this); return false;" class="modal-track-btn pending">Requested</a>
            <?php elseif($profile_is_private): ?>
            <a href="#" onclick="modalTrackUser('send_track_request', this); return false;" class="modal-track-btn">Request to Track</a>
            <?php else: ?>
            <a href="#" onclick="modalTrackUser('follow', this); return false;" class="modal-track-btn">Track</a>
            <?php endif; ?>
        <?php else: ?>
            <?php if($current_user == $profile_id || $vibe_enabled): ?>
            <a href="#" onclick="vibeCheck(<?php echo (int)$profile_id; ?>); return false;" class="modal-action-btn">
                <div class="modal-action-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 3l1.8 4.2L18 9l-4.2 1.8L12 15l-1.8-4.2L6 9l4.2-1.8L12 3z"/>
                        <path d="M5 16l.8 2.2L8 19l-2.2.8L5 22l-.8-2.2L2 19l2.2-.8L5 16z"/>
                        <path d="M19 14l1 2.5L22.5 17 20 18l-1 2.5L18 18l-2.5-1 2.5-.5L19 14z"/>
                    </svg>
                </div>
                <span>Vibe Check</span>
            </a>
            <?php endif; ?>

            <a href="#" onclick="shareProfile(<?php echo (int)$profile_id; ?>); return false;" class="modal-action-btn">
                <div class="modal-action-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/>
                        <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/>
                        <line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>
                    </svg>
                </div>
                <span>Share</span>
            </a>

            <a href="#" onclick="copyProfileLink(<?php echo (int)$profile_id; ?>); return false;" class="modal-action-btn">
                <div class="modal-action-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
                        <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
                    </svg>
                </div>
                <span>Copy Link</span>
            </a>

            <a href="#" onclick="showQR(<?php echo (int)$profile_id; ?>); return false;" class="modal-action-btn">
                <div class="modal-action-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
                        <rect x="3" y="14" width="7" height="7"/>
                        <rect x="5" y="5" width="3" height="3" fill="currentColor" stroke="none"/>
                        <rect x="16" y="5" width="3" height="3" fill="currentColor" stroke="none"/>
                        <rect x="5" y="16" width="3" height="3" fill="currentColor" stroke="none"/>
                        <line x1="14" y1="14" x2="17" y2="14"/><line x1="17" y1="14" x2="17" y2="17"/>
                        <line x1="17" y1="17" x2="21" y2="17"/><line x1="21" y1="17" x2="21" y2="21"/>
                        <line x1="14" y1="21" x2="17" y2="21"/><line x1="14" y1="17" x2="14" y2="21"/>
                    </svg>
                </div>
                <span>QR Code</span>
            </a>

            <?php if($current_user == $profile_id): /* owner only: let / stop other people opening this viewer */ ?>
            <a href="#" id="pvLockBtn" onclick="dzPicLockToggle(); return false;" class="modal-action-btn">
                <div class="modal-action-icon" id="pvLockIc"></div>
                <span id="pvLockLbl"></span>
            </a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div id="picModalToast" class="mr-toast"></div>



<?php if ($current_user): ?>
 
 
 

<script>
// ─────────────────────────────────────────────────────────
//  STATE (fed from PHP)
// ─────────────────────────────────────────────────────────
var _gProfileId    = <?= (int)$profile_id ?>;
var _gIsOwner      = <?= ($current_user == $profile_id) ? 'true' : 'false' ?>;
var _gCols         = <?= json_encode(array_values($user_collections), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;   // collections of the profile being viewed
var _gMyCols       = <?= json_encode(array_values($my_collections), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;     // ALWAYS the logged-in user's own
var _gPinnedColIds = <?= json_encode($pinned_col_ids, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
var _gActiveMemeId=null,_gActiveColId=null,_gActiveColCtx=null,_gColCtx='favs',_gNmMode='create',
    _gOptsColId=null,_gOptsColName=null,_gOptsColCtx=null,_gOptsColPublic=false,_gOptsColPinned=false;
 
function _gNormalizeCols(arr){
    (arr||[]).forEach(function(c){
        c.id              = parseInt(c.id, 10);
        c.item_count      = parseInt(c.item_count, 10) || 0;
        c.is_public       = parseInt(c.is_public, 10) || 0;
        c.created_at      = c.created_at      ? parseInt(c.created_at, 10)      : null;
        c.latest_addition = c.latest_addition ? parseInt(c.latest_addition, 10) : null;
    });
    return arr || [];
}
_gCols         = _gNormalizeCols(_gCols);
_gMyCols       = _gNormalizeCols(_gMyCols);
_gPinnedColIds = (_gPinnedColIds || []).map(function(id){ return parseInt(id, 10); });
 
function _gIsColPinned(id){ id = parseInt(id, 10); return _gPinnedColIds.indexOf(id) !== -1; }
function _gEl(id){ return document.getElementById(id); }
 
function _gFmtNum(n){
    n=parseInt(n)||0;
    if(n>=1000000000)return(n/1000000000).toFixed(1).replace(/\.0$/,'')+'b';
    if(n>=1000000)return(n/1000000).toFixed(1).replace(/\.0$/,'')+'m';
    if(n>=1000)return(n/1000).toFixed(1).replace(/\.0$/,'')+'k';
    return n;
}
 
function _gFetch(body,cb){
    fetch(window.location.href,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
    .then(function(r){return r.json();}).then(cb)
    .catch(function(err){console.error('Col err:',err);gToast('Something went wrong');});
}
 
function gToast(msg){
    var ex=document.querySelector('.g-toast');if(ex)ex.remove();
    var t=document.createElement('div');t.className='g-toast g-toast-in';t.textContent=msg;
    document.body.appendChild(t);
    setTimeout(function(){t.style.transition='opacity 0.3s';t.style.opacity='0';setTimeout(function(){t.remove();},300);},2400);
}
function _gEsc(s){if(!s)return'';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');}
 
// Updates the count badge shown on folder cards
function _gRefreshCountBadge(colId){
    var col=_gCols.find(function(c){return c.id==colId;}) || _gMyCols.find(function(c){return c.id==colId;});
    if(!col)return;
    document.querySelectorAll('[id="gfc-'+colId+'"]').forEach(function(el){
        el.textContent=_gFmtNum(col.item_count)+' item'+(col.item_count==1?'':'s');
    });
}
 
function _gUpdateAllColCounts(){
    var count=_gCols.length;
    var countStr=count>0?_gFmtNum(count):'';
    document.querySelectorAll('.g-pill-count').forEach(function(el){ el.textContent=countStr; });
}
 
function _gRenderAllFolders(){
    if(typeof gRenderFolders!=='function') return;
    ['memes','reposts','tags','favs'].forEach(function(c){
        if(_gEl(c+'ColGrid')){ try{ gRenderFolders(c); }catch(e){} }
    });
}
 
// one body-scroll lock for every sheet on this page
function _gLock(on){ document.body.style.overflow = on ? 'hidden' : ''; }
 
// ─────────────────────────────────────────────────────────
//  COLLECTIONS — folders list, folder detail, search (the part that draws the Collections pill's pages)
// ─────────────────────────────────────────────────────────
var dzCurUid = <?= (int)$current_user ?>;
var _gMap={};
['memes','reposts','tags','favs','likes','uploads','videos'].forEach(function(c){
    _gMap[c]={sortBar:c+'SortBar',grid:c+'Grid',colSec:c+'ColSec',folders:c+'ColFolders',foldGrid:c+'ColGrid',detail:c+'ColDetail',dtTitle:c+'ColTitle',dtCount:c+'ColCount',dtGrid:c+'ColDetailGrid',nPill:c+'N',oPill:c+'O',cPill:c+'C',searchInput:c+'ColSearch',searchClear:c+'ColSearchClear',noResults:c+'ColNoResults',dtSearch:c+'DetailSearch',dtNoResults:c+'DetailNoResults',dtSearchClear:c+'DetailSearchClear'};
});

function _gRelTime(ts){
    if(!ts)return'—';
    var now=Math.floor(Date.now()/1000),diff=now-ts;
    if(diff<60)return Math.max(0,diff)+'s ago';
    if(diff<3600)return Math.floor(diff/60)+'m ago';
    if(diff<86400)return Math.floor(diff/3600)+'h ago';
    if(diff<604800)return Math.floor(diff/86400)+'d ago';
    return _gFmtDate(ts);
}
function _gFmtDate(ts){
    if(!ts)return'—';
    var d=new Date(ts*1000), months=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return months[d.getMonth()]+' '+d.getDate()+', '+d.getFullYear();
}

/* same look as the empty states of the Memes / Reposts / Tags / Saved grids (.es-wrap) */
function _gEmptyHtml(title,text,tips,btnHtml){
    var h='<div class="es-wrap"><div class="es-title">'+_gEsc(title)+'</div><p class="es-text">'+_gEsc(text)+'</p>';
    if(tips&&tips.length){h+='<div class="es-tips">';tips.forEach(function(t){h+='<div class="es-tip"><i class="'+t[0]+'"></i><span>'+_gEsc(t[1])+'</span></div>';});h+='</div>';}
    return h+(btnHtml||'')+'</div>';
}

function gOpenCol(ctx){
    dzHp('light');
    var m=_gMap[ctx];if(!m)return;
    var ge=_gEl(m.grid),se=_gEl(m.colSec),np=_gEl(m.nPill),op=_gEl(m.oPill),cp=_gEl(m.cPill),pp=_gEl(ctx+'P');
    if(ge)ge.style.display='none';
    var ft=_gEl('featuredPost');if(ft&&ctx==='memes')ft.style.display='none';
    if(np)np.style.display='none';if(op)op.style.display='none';if(pp)pp.style.display='none';
    if(cp){cp.classList.add('active');cp.innerHTML='<i class="ri-arrow-left-s-line" style="margin-right:3px;font-size:13px;"></i>Back';cp.onclick=function(){gCloseCol(ctx);};}
    if(se)se.style.display='block';
    gRenderFolders(ctx);
}

function gCloseCol(ctx){
    var m=_gMap[ctx];if(!m)return;
    var ge=_gEl(m.grid),se=_gEl(m.colSec),np=_gEl(m.nPill),op=_gEl(m.oPill),cp=_gEl(m.cPill),pp=_gEl(ctx+'P');
    var si=_gEl(m.searchInput);if(si)si.value='';
    var sc=_gEl(m.searchClear);if(sc)sc.style.display='none';
    var nr=_gEl(m.noResults);if(nr)nr.style.display='none';
    if(ge)ge.style.display='';if(se)se.style.display='none';
    var ft=_gEl('featuredPost');if(ft&&ctx==='memes')ft.style.display='';
    if(np)np.style.display='';if(op)op.style.display='';if(pp)pp.style.display='';
    if(cp){
        cp.classList.remove('active');
        var colCount=_gCols.length;
        var countStr=colCount>0?'<span class="g-pill-count">'+_gFmtNum(colCount)+'</span>':'';
        cp.innerHTML='<i class="ri-folder-3-line" style="margin-right:5px;font-size:11px;"></i>Collections'+countStr;
        cp.onclick=function(){gOpenCol(ctx);};
    }
    if(typeof window.dzGridRefresh==='function')window.dzGridRefresh(m.grid);
}

function gRenderFolders(ctx){
    var m=_gMap[ctx];if(!m)return;
    var fg=_gEl(m.foldGrid),fw=_gEl(m.folders),dw=_gEl(m.detail);
    if(!fg)return;
    if(fw)fw.style.display='block';if(dw)dw.style.display='none';
    Array.prototype.slice.call(fg.querySelectorAll('.col-folder-card,.col-visitor-empty,.es-wrap')).forEach(function(e){e.remove();});
    var newBtn=fg.querySelector('.col-new-btn'), sw=_gEl(ctx+'ColSearchWrap'), nr=_gEl(m.noResults);
    if(nr)nr.style.display='none';

    if(_gCols.length===0){
        if(newBtn)newBtn.style.display='none';
        if(sw)sw.style.display='none';
        fg.insertAdjacentHTML('beforeend', _gIsOwner
            ? _gEmptyHtml('No collections yet','Group your favourite memes into folders and find them again in one tap.',
                [['ri-folder-add-line','Tap the folder button on any meme to save it into a collection.'],['ri-earth-line','Make a collection public to share it, or keep it private.']],
                '<button type="button" class="es-btn" onclick="gOpenNameModal(\'create\',\''+ctx+'\')">New collection</button>')
            : _gEmptyHtml('No public collections yet',"When this person makes a collection public, it will show up here.",
                [['ri-lock-line','Private collections are only visible to their owner.']]));
        return;
    }
    if(newBtn)newBtn.style.display='';
    if(sw)sw.style.display='';

    var pinnedCols=_gCols.filter(function(c){return _gIsColPinned(c.id);});
    var normalCols=_gCols.filter(function(c){return !_gIsColPinned(c.id);});
    pinnedCols.sort(function(a,b){return _gPinnedColIds.indexOf(parseInt(a.id,10))-_gPinnedColIds.indexOf(parseInt(b.id,10));});
    pinnedCols.concat(normalCols).forEach(function(col){
        var isPublic=col.is_public==1, isPinnedCol=_gIsColPinned(col.id);
        var card=document.createElement('div');
        card.className='col-folder-card'+(isPublic?' is-public':'')+(isPinnedCol?' is-pinned-col':'');
        card.dataset.colId=col.id;
        card.dataset.colName=(col.name||'').toLowerCase();
        card.dataset.pinned=isPinnedCol?'1':'0';
        var createdStr=col.created_at?_gFmtDate(col.created_at):'—';
        var latestStr=col.latest_addition?_gRelTime(col.latest_addition):'No items yet';
        var itemCountFmt=_gFmtNum(col.item_count||0);
        var optsBtn=_gIsOwner
            ? '<button class="col-folder-opts" onclick="event.stopPropagation();gOpenOpts(event,'+col.id+','+_gEsc(JSON.stringify(col.name||''))+',\''+ctx+'\','+(isPublic?'true':'false')+','+(isPinnedCol?'true':'false')+')" title="Options"><i class="ri-more-2-line"></i></button>'
            : '';
        var pubBadge=isPublic?'<div class="col-public-badge">Public</div>':'';
        var pinBadge=isPinnedCol?'<div class="col-pin-badge'+(isPublic?' with-pub':'')+'">📌 Pinned</div>':'';
        card.innerHTML=
            pubBadge+pinBadge+
            '<div class="col-folder-preview" id="gfp-'+ctx+'-'+col.id+'"><div class="col-fp-empty"><i class="ri-image-line"></i></div><div class="col-fp-empty"><i class="ri-image-line"></i></div><div class="col-fp-empty"><i class="ri-image-line"></i></div><div class="col-fp-empty"><i class="ri-image-line"></i></div></div>'+
            '<div class="col-folder-info">'+
                '<div class="col-folder-name">'+_gEsc(col.name)+'</div>'+
                '<div class="col-folder-count" id="gfc-'+col.id+'">'+itemCountFmt+' item'+(col.item_count==1?'':'s')+'</div>'+
                '<div class="col-folder-timeline">'+
                    '<div class="col-folder-tl-row"><i class="ri-calendar-line"></i><span>Created: '+_gEsc(createdStr)+'</span></div>'+
                    '<div class="col-folder-tl-row" id="gflt-'+col.id+'"><i class="ri-time-line"></i><span>Latest: '+_gEsc(latestStr)+'</span></div>'+
                '</div>'+
                (_gIsOwner?'<div class="col-folder-vis '+(isPublic?'pub':'priv')+'" id="gfv-'+col.id+'">'+(isPublic?'<i class="ri-earth-line"></i> Public':'<i class="ri-lock-line"></i> Private')+'</div>':'')+
            '</div>'+optsBtn;
        card.addEventListener('click',function(){gOpenDetail(ctx,col.id,col.name);});
        fg.appendChild(card);
        _gLoadPreview(ctx,col.id);
    });
}

/* Deep-link: profile.php?user_id=X&open_col=ID opens that collection directly */
(function(){
    var id=0;try{id=parseInt(new URLSearchParams(location.search).get('open_col'),10)||0;}catch(e){}
    if(!id)return;
    function go(){
        var col=null;(_gCols||[]).forEach(function(c){if(c.id===id)col=c;});
        if(!col)return;                                   // private / missing: just stay on the profile
        var ctx='memes';
        try{ gOpenCol(ctx); gOpenDetail(ctx,col.id,col.name); }catch(e){console.error(e);return;}
        var el=_gEl(_gMap[ctx].colSec);if(el&&el.scrollIntoView)setTimeout(function(){el.scrollIntoView({block:'start'});},50);
    }
    if(document.readyState==='complete')setTimeout(go,0);else window.addEventListener('load',function(){setTimeout(go,50);});
})();

function _gLoadPreview(ctx,colId){
    _gFetch('col_action=get_previews&col_id='+colId,function(data){
        var el=_gEl('gfp-'+ctx+'-'+colId);if(!el)return;
        el.innerHTML='';
        for(var i=0;i<4;i++){
            var div=document.createElement('div'),item=data.items&&data.items[i];
            if(item){
                div.className='col-fp-item';
                var f=item.file_name,t=item.type;
                if(t==='video')div.innerHTML='<video src="uploads/'+_gEsc(f)+'" muted style="width:100%;height:100%;object-fit:cover;"></video>';
                else if(t==='text'||!f||f==='0')div.innerHTML='<img src="uploads/Text.jpg">';
                else div.innerHTML='<img src="uploads/'+_gEsc(f)+'" onerror="this.onerror=null;this.src=\'uploads/Text.jpg\'">';
            }else{div.className='col-fp-empty';div.innerHTML='<i class="ri-image-line"></i>';}
            el.appendChild(div);
        }
    });
}

function gSearchFolders(ctx,query){
    var m=_gMap[ctx];if(!m)return;
    var fg=_gEl(m.foldGrid),nr=_gEl(m.noResults),sc=_gEl(m.searchClear);
    var q=(query||'').trim().toLowerCase();
    if(sc)sc.style.display=q?'block':'none';
    var visible=0;
    Array.prototype.slice.call(fg.querySelectorAll('.col-folder-card')).forEach(function(card){
        var show=!q||(card.dataset.colName||'').indexOf(q)!==-1;
        card.style.display=show?'':'none';
        if(show)visible++;
    });
    if(nr)nr.style.display=(visible===0&&q)?'block':'none';
}
function gClearFolderSearch(ctx){
    var m=_gMap[ctx];if(!m)return;
    var si=_gEl(m.searchInput);
    if(si){si.value='';si.focus();}
    gSearchFolders(ctx,'');
}

function gSearchDetailItems(gridId,searchId,noResultsId){
    var grid=_gEl(gridId),si=_gEl(searchId),nr=_gEl(noResultsId),clearBtn=_gEl(searchId.replace('Search','SearchClear'));
    if(!grid||!si)return;
    var q=(si.value||'').trim().toLowerCase();
    if(clearBtn)clearBtn.style.display=q?'block':'none';
    var visible=0;
    Array.prototype.slice.call(grid.querySelectorAll('.meme-card')).forEach(function(card){
        var cap=((card.querySelector('.caption')||{}).textContent||'').toLowerCase();
        var show=!q||cap.indexOf(q)!==-1;
        card.style.display=show?'':'none';
        if(show)visible++;
    });
    if(nr)nr.style.display=(visible===0&&q)?'block':'none';
}
function gClearDetailSearch(gridId,searchId,noResultsId,clearBtnId){
    var si=_gEl(searchId),nr=_gEl(noResultsId),cb=_gEl(clearBtnId);
    if(si){si.value='';si.focus();}
    if(nr)nr.style.display='none';
    if(cb)cb.style.display='none';
    var grid=_gEl(gridId);
    if(grid)Array.prototype.slice.call(grid.querySelectorAll('.meme-card')).forEach(function(c){c.style.display='';});
}

function gOpenDetail(ctx,colId,colName){
    dzHp('light');
    var m=_gMap[ctx];if(!m)return;
    colId=parseInt(colId,10);
    _gActiveColId=colId;_gActiveColCtx=ctx;
    var fw=_gEl(m.folders),dw=_gEl(m.detail),dt=_gEl(m.dtTitle),dc=_gEl(m.dtCount),dg=_gEl(m.dtGrid);
    var dsi=_gEl(m.dtSearch);if(dsi)dsi.value='';
    var dsc=_gEl(m.dtSearchClear);if(dsc)dsc.style.display='none';
    var dnr=_gEl(m.dtNoResults);if(dnr)dnr.style.display='none';
    if(fw)fw.style.display='none';
    if(dw)dw.style.display='block';
    if(dt)dt.textContent=colName;
    if(dc)dc.textContent='Loading...';
    if(dg)dg.innerHTML='<div style="grid-column:1/-1;text-align:center;padding:30px;color:rgba(var(--fg,255,255,255),0.45);font-size:13px;display:flex;align-items:center;justify-content:center;gap:8px;"><i class="ri-loader-4-line" style="font-size:20px;animation:spin 0.8s linear infinite;"></i> Loading...</div>';

    _gFetch('col_action=get_collection_items&col_id='+colId,function(data){
        if(data.status!=='ok'){
            if(dg)dg.innerHTML='<div style="grid-column:1/-1;text-align:center;padding:30px;color:#f24455;font-size:13px;">Error loading items</div>';
            return;
        }
        var items=data.items||[];
        if(dc)dc.textContent=_gFmtNum(items.length)+' item'+(items.length==1?'':'s');
        if(!dg)return;
        dg.innerHTML='';
        if(items.length===0){
            dg.innerHTML=_gEmptyHtml('Nothing in this collection yet','Memes you add to it will show up here.',
                [['ri-folder-add-line','Tap the folder button on any meme to add it to this collection.']]);
            return;
        }
        var colFeedName='colFeed_'+colId;
        _gFetch('col_action=get_col_item_states&col_id='+colId,function(stateData){
            var stateMap={};
            if(stateData.status==='ok'&&stateData.states){stateData.states.forEach(function(s){stateMap[s.id]=s;});}
            window[colFeedName]=items.map(function(item){
                var f=item.file_name||'',t=item.type||'image';
                var mf=(t==='text'||!f||f==='0')?'uploads/Text.jpg':'uploads/'+f;
                var s=stateMap[item.id]||{};
                return {
                    id:item.id,file:mf,type:t,
                    pic:item.uploader_pic||'mee.jpg',
                    userId:item.uploaded_by,username:item.username||'',
                    caption:item.caption||'',time:item.created_at||'',
                    isYou:(typeof currentUserId!=='undefined'&&String(item.uploaded_by)===String(currentUserId)),
                    likeCount:s.likeCount||0,repostCount:s.repostCount||0,favCount:s.favCount||0,shareCount:s.shareCount||0,
                    hideLikes:!!s.hideLikes,hideReposts:!!s.hideReposts,hideSaves:!!s.hideSaves,hideShares:!!s.hideShares,   /* hidden counts the poster chose — the server already sends the flags */
                    liked:s.liked||false,reposted:s.reposted||false,faved:s.faved||false,
                    nickname:s.nickname||'',isFollowing:s.isFollowing||false,socialProof:s.socialProof||[],
                    media:(s.media&&s.media.length)?s.media:[{file:mf,type:t}],
                    context:'Collections',
                    groupId:(s.groupId!==undefined&&s.groupId!==null)?s.groupId:null,
                    groupTitle:s.groupTitle||null,
                    audioId:(s.audioId!==undefined&&s.audioId!==null)?s.audioId:null,
                    audioIsOriginal:!!s.audioIsOriginal,
                    audioFile:(s.audioFile!==undefined&&s.audioFile!==null)?s.audioFile:null,
                    audioTitle:(s.audioTitle!==undefined&&s.audioTitle!==null)?s.audioTitle:null,
                    audioArtist:(s.audioArtist!==undefined&&s.audioArtist!==null)?s.audioArtist:null,
                    musicVolume:(s.musicVolume!==undefined&&s.musicVolume!==null)?s.musicVolume:80,
                    videoAudioVolume:(s.videoAudioVolume!==undefined&&s.videoAudioVolume!==null)?s.videoAudioVolume:40,
                    isPartnership:!!s.isPartnership,partnerBrand:s.partnerBrand||null,
                    isAd:!!s.isAd,
                    adCtaText:(s.adCtaText!==undefined&&s.adCtaText!==null)?s.adCtaText:null,
                    adCtaUrl:(s.adCtaUrl!==undefined&&s.adCtaUrl!==null)?s.adCtaUrl:null,
                    adCtaColor:(s.adCtaColor!==undefined&&s.adCtaColor!==null)?s.adCtaColor:null,
                    isAiGenerated:!!s.isAiGenerated
                };
            });

            items.forEach(function(item){
                var f=item.file_name||'',t=item.type||'image';
                var cap=item.caption||'',up=item.uploader_pic||'mee.jpg',un=item.username||'';
                var mf=(t==='text'||!f||f==='0')?'uploads/Text.jpg':'uploads/'+f;
                var mediaHtml=(t==='video')
                    ? '<video src="uploads/'+_gEsc(f)+'#t=0.1" muted playsinline preload="metadata" style="width:100%;height:100%;object-fit:cover;display:block;"></video>'
                    : '<img src="'+_gEsc(mf)+'" onerror="this.onerror=null;this.src=\'uploads/Text.jpg\'" style="width:100%;height:100%;object-fit:cover;display:block;">';
                var addColBtnHtml=dzCurUid
                    ? '<button class="card-add-col-btn" title="Add to Collection" onclick="event.stopPropagation();gOpenAddToCol('+item.id+')"><svg class="cab-plus" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v18M3 12h18" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round"/></svg></button>'
                    : '';
                var removeBtn=_gIsOwner
                    ? '<button class="card-remove-btn" title="Remove from collection" onclick="event.stopPropagation();gRemoveItem('+item.id+',this)"><i class="ri-folder-reduce-line"></i></button>'
                    : '';
                var card=document.createElement('div');
                card.className='meme-card';
                card.dataset.caption=(cap||'').toLowerCase();
                card.innerHTML=
                    '<div class="top-bar"><img src="'+_gEsc(up)+'" onerror="this.onerror=null;this.src=\'mee.jpg\'"><div class="username">'+_gEsc(un)+'</div></div>'+
                    '<div class="media-box" style="position:relative;">'+mediaHtml+addColBtnHtml+removeBtn+'</div>'+
                    '<div class="caption"><i class="ri-folder-3-line" style="color:#FF4C4C;"></i> '+_gEsc(cap)+'</div>'+
                    '<div class="icon-bar"><a href="download.php?file='+encodeURIComponent(f)+'" title="Download"><i class="ri-download-line"></i></a></div>';
                card.querySelector('.media-box').addEventListener('click',function(e){
                    if(e.target.closest('.card-remove-btn')||e.target.closest('.card-add-col-btn'))return;
                    if(typeof openMemeModal==='function')openMemeModal(mf,t==='text'?'image':t,cap,up,un,item.uploaded_by,colFeedName,item.id);
                });
                dg.appendChild(card);
            });

            var col=_gCols.find(function(c){return c.id==colId;});
            if(col&&items.length>0){
                var maxTs=0;
                items.forEach(function(it){var ts=parseInt(it.added_at||it.created_at_ts||0);if(ts>maxTs)maxTs=ts;});
                if(maxTs>0){
                    col.latest_addition=maxTs;
                    var ltEl=_gEl('gflt-'+colId);
                    if(ltEl)ltEl.querySelector('span').textContent='Latest: '+_gRelTime(maxTs);
                }
            }
        });
    });
}

function gCloseDetail(ctx){
    var m=_gMap[ctx];if(!m)return;
    _gActiveColId=null;_gActiveColCtx=null;
    var dsi=_gEl(m.dtSearch);if(dsi)dsi.value='';
    var dsc=_gEl(m.dtSearchClear);if(dsc)dsc.style.display='none';
    var dnr=_gEl(m.dtNoResults);if(dnr)dnr.style.display='none';
    var fw=_gEl(m.folders),dw=_gEl(m.detail);
    if(fw)fw.style.display='block';if(dw)dw.style.display='none';
}

function gRemoveItem(memeId,btn){
    dzHp('light');
    if(!_gActiveColId||!_gIsOwner)return;
    var ctx=_gActiveColCtx;
    _gFetch('col_action=remove_from_collection&col_id='+_gActiveColId+'&meme_id='+memeId,function(d){
        if(d.status!=='ok'){gToast('Could not remove');return;}
        var card=btn.closest('.meme-card');
        if(card){card.style.transition='opacity 0.3s,transform 0.3s';card.style.opacity='0';card.style.transform='scale(0.9)';setTimeout(function(){card.remove();if(ctx)_gUpdateDetailCount(ctx);},300);}
        _gCols.forEach(function(c){if(c.id==_gActiveColId)c.item_count=Math.max(0,c.item_count-1);});
        _gRefreshCountBadge(_gActiveColId);
        gToast('Removed from collection');
    });
}
function _gUpdateDetailCount(ctx){
    var m=_gMap[ctx];if(!m)return;
    var dg=_gEl(m.dtGrid),dc=_gEl(m.dtCount);if(!dg||!dc)return;
    var r=dg.querySelectorAll('.meme-card:not([style*="display: none"])').length;
    dc.textContent=_gFmtNum(r)+' item'+(r==1?'':'s');
}
</script>
 
<?php endif; ?>

<?php if ($is_owner_view_c): ?>


<!-- SELECT MODE — bottom bar + collection picker -->
<div id="bulkBar">
  <div class="bk-count" id="bkCount">0 selected</div>
  <button type="button" id="bkCol" onclick="dzBulkCollection()" disabled><i class="ri-bookmark-line"></i><span>Collection</span></button>
  <button type="button" id="bkDel" class="bk-del" onclick="dzBulkDelete()" disabled><i class="ri-delete-bin-line"></i><span>Delete</span></button>
  <button type="button" class="bk-x" onclick="dzSelCancel()" aria-label="Cancel selection"><i class="ri-close-line"></i></button>
</div>
<?php endif; ?>


<?php if($ep_enabled): ?>


<?php endif; ?>


<?php if ($profile_highlight_count > 0): /* highlights script only when this profile has highlights */ ?>
<script>
/* ══════════════════════════════════════════════════════════════
   HIGHLIGHTS — press a square: the grey ring stays put while a
   bright arc runs around it (Instagram-story style), then the
   "Highlights modal" opens.

   The modal itself is NOT in this file. Create it yourself and
   define a global function:

       function openHighlightsModal(highlightId){ ... }

   dzOpenHighlight() calls it after the ring has spun for a moment.
   Until you define it, tapping a highlight just plays the animation.
══════════════════════════════════════════════════════════════ */
(function(){
    var SPIN_MS = 900;      // = length of the spin animation (matches the CSS .9s)
    var busy    = false;

    /* Start the spin the instant a finger / mouse goes down on a tile,
       and stop it if the press turns into a scroll or is cancelled. */
    function bind(){
        var strip = document.getElementById('rpHighlights');
        if(!strip) return;

        function tileOf(t){ return t && t.closest ? t.closest('.rp-hl') : null; }

        strip.addEventListener('pointerdown', function(e){
            var tile = tileOf(e.target);
            if(tile && !busy) tile.classList.add('spin');
        }, { passive:true });

        function release(){
            if(busy) return;
            strip.querySelectorAll('.rp-hl.spin').forEach(function(t){ t.classList.remove('spin'); });
        }
        /* ── scroll arrows + infinite loading (8 tiles at a time) ── */
        var wrap = document.getElementById('rpHlWrap');
        var btnL = document.getElementById('rpHlLeft');
        var btnR = document.getElementById('rpHlRight');
        var BATCH   = parseInt(strip.getAttribute('data-batch'), 10) || 8;
        var loading = false;

        function held(){ return strip.querySelectorAll('.rp-hl.rp-hl-hold'); }

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
                k.className = 'rp-hl-skel';
                k.innerHTML = '<span class="dz-sk sq"></span><span class="dz-sk nm"></span>';
                strip.appendChild(k);
            }
        }
        function skeletonOff(){
            strip.querySelectorAll('.rp-hl-skel').forEach(function(k){ k.remove(); });
        }
        function loadMore(){
            if(loading) return;
            var h = held();
            if(!h.length) return;
            loading = true;
            skeletonOn(Math.min(BATCH, h.length));
            setTimeout(function(){                       // short flash of skeletons, then the next 8
                skeletonOff();
                Array.prototype.slice.call(held(), 0, BATCH).forEach(function(t){
                    t.classList.remove('rp-hl-hold');
                    t.classList.add('rp-hl-in');
                    hydrate(t);
                });
                loading = false;
                arrowsUpdate();
                checkMore();
            }, 110);
        }
        /* start loading the next 8 BEFORE you reach the end (1.5 screens ahead),
           and keep going if the first tiles don't even fill the row */
        function checkMore(){
            if(loading || !held().length) return;
            var remaining = strip.scrollWidth - strip.clientWidth - strip.scrollLeft;
            if(remaining < strip.clientWidth * 1.5) loadMore();
        }
        /* arrows are shown whenever there is more to scroll in that direction
           (works the same on phones / responsive view and on normal view) */
        function arrowsUpdate(){
            if(!wrap) return;
            var max = strip.scrollWidth - strip.clientWidth;
            wrap.classList.toggle('can-left',  strip.scrollLeft > 2);
            wrap.classList.toggle('can-right', strip.scrollLeft < max - 2 || loading || held().length > 0);
        }
        function scrollByDir(dir){
            strip.scrollBy({ left: dir * Math.max(120, strip.clientWidth * 0.7), behavior:'smooth' });
        }
        if(btnL) btnL.addEventListener('click', function(e){ e.stopPropagation(); scrollByDir(-1); });
        if(btnR) btnR.addEventListener('click', function(e){ e.stopPropagation(); scrollByDir(1); checkMore(); });
        strip.addEventListener('scroll', function(){ arrowsUpdate(); checkMore(); }, { passive:true });
        function refresh(){ arrowsUpdate(); checkMore(); }
        window.addEventListener('resize', refresh);
        window.addEventListener('load', refresh);
        if('ResizeObserver' in window){ try { new ResizeObserver(refresh).observe(strip); } catch(e){} }
        refresh();
        setTimeout(refresh, 300);
        setTimeout(refresh, 1000);
        setTimeout(refresh, 2000);

        strip.addEventListener('pointercancel', release, { passive:true });
        strip.addEventListener('pointerleave',  release, { passive:true });
        strip.addEventListener('scroll',        release, { passive:true });
    }

    window.dzOpenHighlight = function(tile, id){
        if(busy || !tile) return;
        dzHp('light');
        busy = true;
        tile.classList.add('spin');

        setTimeout(function(){
            if(typeof window.openHighlightsModal === 'function'){
                try { window.openHighlightsModal(id); } catch(err){ console.error(err); }
            }
            /* let the modal fade in, then rest the ring again */
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

<script>
/* ── Email dropdown (only rendered when a profile has 2+ email links) ── */
function dzToggleEmailMenu(e){
    if(e) e.stopPropagation();
    var wrap = document.getElementById('rpEmailWrap');
    var btn  = document.getElementById('rpEmailBtn');
    if(!wrap) return;
    var open = wrap.classList.toggle('open');
    if(btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
}
function dzToggleEmailReveal(e){
    if(e) e.stopPropagation();
    var row = document.getElementById('rpEmailRow');
    var sq  = document.getElementById('rpEmailSq');
    if(!row) return;
    var open = row.classList.toggle('open');
    if(sq){ sq.classList.toggle('open', open); sq.setAttribute('aria-expanded', open ? 'true' : 'false'); }
    if(!open) dzCloseEmailMenu();
}
function dzCloseEmailMenu(){
    var wrap = document.getElementById('rpEmailWrap');
    var btn  = document.getElementById('rpEmailBtn');
    if(!wrap) return;
    wrap.classList.remove('open');
    if(btn) btn.setAttribute('aria-expanded', 'false');
}
document.addEventListener('click', function(e){
    var wrap = document.getElementById('rpEmailWrap');
    if(wrap && !wrap.contains(e.target)) dzCloseEmailMenu();
});
document.addEventListener('keydown', function(e){
    if(e.key === 'Escape') dzCloseEmailMenu();
});
</script>

<script>
/* ══════════════════════════════════════════════════════════════
   RIGHT-HAND COLUMN — collapse cards, See more / See less, open a post,
   skeleton → cards reveal, live Vibe update.
══════════════════════════════════════════════════════════════ */
function pfToggle(bar){
    dzHp('light');
    var c = bar && bar.closest ? bar.closest('.pf-card') : null;
    if(!c) return;
    var collapsed = c.classList.toggle('collapsed');
    bar.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
}

/* See more → reveals the next batch; once everything is showing the button
   turns into See less, which folds the list back to its first batch. */
function pfMore(btn){
    dzHp('light');
    var list = btn.previousElementSibling;
    if(!list) return;
    var rows  = Array.prototype.filter.call(list.children, function(r){ return r.classList.contains('pf-row'); });
    var init  = parseInt(list.dataset.init, 10)  || 5;
    var step  = parseInt(list.dataset.step, 10)  || 5;
    var shown = parseInt(list.dataset.shown, 10) || init;

    if(btn.dataset.mode === 'less'){
        shown = init;
        rows.forEach(function(r, i){
            r.classList.remove('pf-in');
            r.classList.toggle('pf-hold', i >= shown);
        });
    } else {
        var next = Math.min(rows.length, shown + step);
        rows.forEach(function(r, i){
            if(i >= shown && i < next){ r.classList.remove('pf-hold'); r.classList.add('pf-in'); }
        });
        shown = next;
    }
    list.dataset.shown = shown;
    var done = shown >= rows.length;
    btn.dataset.mode = done ? 'less' : 'more';
    btn.textContent  = done ? 'See less' : 'See more';
}

/* Open a post from the right column in the SAME modal the profile grid uses.
   It finds that post's card in the grid and fires a real click on it (on the
   image / video first, then the media box, then the card), so whatever opens
   the modal for the grid opens it here too. If the post isn't in the grid at
   all, it falls back to any known opener, then to the post page. */
function pfOpenPost(id){
    var card = document.querySelector('.meme-card[data-id="' + id + '"]');
    if(card){
        var targets = [
            card.querySelector('.media-box img'),
            card.querySelector('.media-box video'),
            card.querySelector('.media-box'),
            card.querySelector('img'),
            card.querySelector('video'),
            card
        ];
        for(var i = 0; i < targets.length; i++){
            if(targets[i]){
                targets[i].dispatchEvent(new MouseEvent('click', { bubbles:true, cancelable:true, view:window }));
                return;
            }
        }
    }
    var openers = ['openMemeModal', 'openPostModal', 'openMeme', 'openPost', 'openViewer', 'openFeedModal'];
    for(var j = 0; j < openers.length; j++){
        if(typeof window[openers[j]] === 'function'){
            try { window[openers[j]](id); return; } catch(e){}
        }
    }
    location.href = 'home.php?meme_id=' + encodeURIComponent(id);
}

/* after someone gives (or undoes) a vibe, refresh the Vibe card without a reload */
function pfVibeUpdate(top, total, rows){
    var t = document.getElementById('pfVibeTop');
    var s = document.getElementById('pfVibeSub');
    if(t) t.textContent = top ? top : 'No vibes yet';
    if(s){
        var n = parseInt(total, 10) || 0;
        s.textContent = n > 0 ? n + ' vote' + (n === 1 ? '' : 's') : 'Nobody has given a vibe yet';
    }
    /* breakdown chips: the top 3 vibes with their counts */
    var box = document.getElementById('pfVibeBox');
    if(box && Array.isArray(rows)){
        var old = box.querySelector('.pf-chips'); if(old) old.remove();
        if(rows.length > 1){
            var c = document.createElement('div'); c.className = 'pf-chips';
            rows.slice(0, 3).forEach(function(r){
                var sp = document.createElement('span'); sp.className = 'pf-chip';
                sp.appendChild(document.createTextNode(r.vibe + ' '));
                var bb = document.createElement('b'); bb.textContent = r.c; sp.appendChild(bb);
                c.appendChild(sp);
            });
            box.appendChild(c);
        }
    }
}
/* owner switched Vibe Check on/off → show / hide the "off" note under the card */
function pfVibeState(enabled){
    var off = document.getElementById('pfVibeOff');
    if(off) off.style.display = enabled ? 'none' : '';
    var box = document.getElementById('pfVibeBox');                 // results: hidden while Vibe Check is off
    if(box) box.style.display = enabled ? '' : 'none';
}

/* ══════════════════════════════════════════════════════════════
   RIGHT-HAND COLUMN — loaded AFTER the page has rendered.
   • Only requested on screens wide enough to SHOW the column (≥1100px),
     so phones / tablets never pay for those queries at all.
   • The page itself (header, grid, highlights) renders first; this request
     starts once the browser is idle.
   • If the window is resized wider later, it loads then (once).
══════════════════════════════════════════════════════════════ */
(function(){
    var box = document.getElementById('pfCards');
    if(!box) return;
    var mq = window.matchMedia ? window.matchMedia('(min-width:1100px)') : null;
    var state = 0;   // 0 = not requested, 1 = loading, 2 = done

    function load(){
        if(state) return;
        state = 1;
        var u = new URL(window.location.href);
        u.searchParams.set('dz_side', '1');
        fetch(u.toString(), { credentials:'same-origin', headers:{ 'Accept':'text/html' } })
            .then(function(r){ if(!r.ok) throw new Error('side'); return r.text(); })
            .then(function(html){
                box.innerHTML = html;
                box.classList.add('pf-loaded');
                state = 2;
            })
            .catch(function(){
                box.innerHTML = '';   // fail quietly: the page works without the column
                state = 0;            // …and it may retry on the next resize
            });
    }
    function go(){ if(!mq || mq.matches) load(); }

    function start(){
        if('requestIdleCallback' in window) requestIdleCallback(go, { timeout:600 });
        else setTimeout(go, 120);
    }
    if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();

    if(mq){
        if(mq.addEventListener) mq.addEventListener('change', go);
        else if(mq.addListener) mq.addListener(go);
    }
})();

</script>

<script>
/* ══════════════════════════════════════════════════════════════
   NOTIFICATION BELL — red dot behaviour
   • Tap the bell → the dot disappears immediately, the page tells
     the server "everything up to now is read" (same request the
     "Mark All Read" button on notifications.php sends), then the
     notifications page opens.
   • The dot only comes back when a NEW notification arrives
     (your top PHP sets $notif_has_unread from notification_reads).
══════════════════════════════════════════════════════════════ */
function dzBellClick(e, a){
    if(e && !(e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1)) dzHp('light');
    /* let ctrl / cmd / shift / middle-click open normally */
    if(e && (e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1)) return;
    if(e) e.preventDefault();

    var dot = document.getElementById('rpBellDot');
    if(dot && dot.parentNode) dot.parentNode.removeChild(dot);

    var href = (a && a.href) ? a.href : 'notifications.php';
    var went = false;
    function go(){ if(went) return; went = true; window.location.href = href; }

    try {
        fetch('notifications.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'mark_all_read=1',
            credentials: 'same-origin',
            keepalive: true
        }).then(go).catch(go);
    } catch(err){ go(); }
    setTimeout(go, 700);   /* never let a slow request block navigation */
}
</script>

<script>
/* ── Profile username: always one line — longer names shrink a little, then end with "…" ── */
(function(){
    var STEPS = ['', 'u-s1', 'u-s2'];
    function fit(){
        document.querySelectorAll('.rp-username').forEach(function(el){
            el.classList.remove('u-s1', 'u-s2');
            if(el.clientWidth === 0) return;
            for(var i = 0; i < STEPS.length; i++){
                if(STEPS[i]) el.classList.add(STEPS[i]);
                if(el.scrollWidth <= el.clientWidth + 1) return;       /* fits on one line */
                if(STEPS[i]) el.classList.remove(STEPS[i]);
            }
            el.classList.add('u-s2');                                     /* still too long: smallest size, one line, ends with … */
        });
    }
    window.dzUsernameFit = fit;
    if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fit); else fit();
    window.addEventListener('load', fit);
    window.addEventListener('resize', fit);
    if(document.fonts && document.fonts.ready) document.fonts.ready.then(fit);
})();

/* ── Topbar username reveal on scroll ── */
(function(){
    function init(){
        var topbar = document.getElementById('rpTopbar');
        var usernameEl = document.querySelector('.rp-username-row .rp-username');
        var topbarUsername = document.getElementById('rpTopbarUsername');
        if(!topbar || !usernameEl || !topbarUsername) return;

        function onScroll(){
            var barHeight = topbar.offsetHeight || 54;
            var rect = usernameEl.getBoundingClientRect();
            var show = rect.bottom <= barHeight;   // fully past the topbar, not half cut
            topbarUsername.classList.toggle('show-title', show);
        }
        window.addEventListener('scroll', onScroll, { passive:true });
        onScroll();
    }
    if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();

/* ── Topbar + tabs: merge into ONE bar when scrolling down, separate
     again when scrolling back up (Instagram / X style). ── */
(function(){
    function init(){
        var sentinel = document.getElementById('dzTabsSentinel');
        var topbar   = document.getElementById('rpTopbar');
        var tabs     = document.getElementById('rpTabs');
        if(!sentinel || !topbar || !tabs || !('IntersectionObserver' in window)) return;

        var io;
        function observe(){
            if(io) io.disconnect();
            var exact = topbar.getBoundingClientRect().height || 54;
            document.documentElement.style.setProperty('--topbar-h', exact + 'px');
            var h = Math.round(exact);
            io = new IntersectionObserver(function(entries){
                entries.forEach(function(entry){
                    var stuck = !entry.isIntersecting && entry.boundingClientRect.top < h;
                    topbar.classList.toggle('tabs-stuck', stuck);
                    tabs.classList.toggle('is-stuck', stuck);
                });
            }, { threshold:0, rootMargin:'-' + h + 'px 0px 0px 0px' });
            io.observe(sentinel);
        }
        observe();
        window.addEventListener('resize', observe);
    }
    if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();

/* ── Account chevron rotates with the kebab menu ── */
(function(){
    function init(){
        var kebab = document.getElementById('userKebab');
        var chevron = document.getElementById('rpUserChevron');
        if(!kebab || !chevron) return;
        function sync(){
            chevron.style.transform = kebab.classList.contains('open') ? 'rotate(180deg)' : 'rotate(0deg)';
        }
        sync();
        new MutationObserver(sync).observe(kebab, { attributes:true, attributeFilter:['class'] });
    }
    if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();

/* Tap the post count → switch to the Memes tab and scroll down to the post grid */
function dzGoToPosts(){
    var memesBtn = document.getElementById('tabMemes');
    if(memesBtn && !memesBtn.classList.contains('active')) memesBtn.click();
    var grid   = document.getElementById('memes');
    var tabs   = document.getElementById('rpTabs');
    var topbar = document.getElementById('rpTopbar');
    if(!grid) return;
    var offset = (topbar ? topbar.offsetHeight : 54) + (tabs ? tabs.offsetHeight : 44);
    var y = grid.getBoundingClientRect().top + (window.pageYOffset || document.documentElement.scrollTop) - offset;
    window.scrollTo({ top: Math.max(0, y), behavior:'smooth' });
}

/* A tab button was pressed. When the swipe pager owns that panel it slides there;
   otherwise (no pager / unknown panel) the old show-and-hide behaviour is used. */
function rpSelectTab(btn, sectionId){
    dzHp('selection');
    if(typeof window.rpPagerGo === 'function' && window.rpPagerGo(sectionId)) return;

    document.querySelectorAll('.rp-tab-btn').forEach(function(b){ b.classList.remove('active'); });
    btn.classList.add('active');
    rpMoveTabIndicator(btn);
    if(typeof toggleSection === 'function'){
        toggleSection(sectionId);
    } else {
        document.querySelectorAll('.dropdown-section').forEach(function(s){ s.style.display = 'none'; });
        var target = document.getElementById(sectionId);
        if(target) target.style.display = (sectionId === 'messages') ? 'block' : 'grid';
    }
    /* kick off the grid skeleton + first-9 load for this tab (once) */
    if(typeof window.dzGridActivate === 'function') window.dzGridActivate(sectionId);
}

/* ── Smooth sliding underline indicator for the tab bar ── */
function rpMoveTabIndicator(btn){
    var track = document.getElementById('rpTabsTrack');
    var indicator = document.getElementById('rpTabIndicator');
    if(!track || !indicator || !btn) return;
    var content = btn.querySelector('.rp-tab-content') || btn;
    var trackRect = track.getBoundingClientRect();
    var contentRect = content.getBoundingClientRect();
    indicator.style.left = (contentRect.left - trackRect.left) + 'px';
    indicator.style.width = contentRect.width + 'px';
    indicator.classList.add('ready');
}
(function(){
    function init(){
        var active = document.querySelector('.rp-tab-btn.active') || document.getElementById('tabMemes');
        if(active) rpMoveTabIndicator(active);
        window.addEventListener('resize', function(){
            var current = document.querySelector('.rp-tab-btn.active');
            if(current) rpMoveTabIndicator(current);
        });
    }
    if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();

/* ══════════════════════════════════════════════════════════════
   TAB PAGER — swipe between Memes · Reposts · Tags · Saved · Messages
   (same engine as the Tracks page)

   • While idle only the open panel is laid out (page height stays right,
     nothing hidden is loading or playing).
   • The moment you touch and drag sideways, the neighbouring panels are
     laid out beside it and the whole strip follows your finger.
   • Direction is decided after a few pixels: vertical = normal scroll.
   • Quick flick (speed) OR a pull past ~22% of the width changes tab.
   • Pulling past the first / last tab is rubbery.
   • The tab underline follows your finger; release glides with easing.
   • A panel that was never opened gets its card-grid skeleton the moment
     it starts sliding in (the grid module's dzGridActivate).
   • Swipes that start in a search box, a horizontally scrolling row
     (sort bar, filter pills…) or anything marked data-no-swipe are ignored.
   • The click right after a drag is swallowed so a swipe never opens a post.
══════════════════════════════════════════════════════════════ */
(function(){
    var pager = document.getElementById('rpPager');
    var track = document.getElementById('rpTrack');
    var ind   = document.getElementById('rpTabIndicator');
    var tabsTrack = document.getElementById('rpTabsTrack');
    if(!pager || !track) return;

    var tabBtns = [], ids = [], panels = [];
    Array.prototype.forEach.call(document.querySelectorAll('.rp-tab-btn[data-panel]'), function(b){
        var id = b.getAttribute('data-panel');
        var p  = document.getElementById(id);
        if(p && p.parentNode === track){ tabBtns.push(b); ids.push(id); panels.push(p); }
    });
    if(!panels.length) return;

    var idx = Math.max(0, tabBtns.findIndex(function(b){ return b.classList.contains('active'); }));
    var pageW = 0, settleTimer = null;

    function width(){ return pager.clientWidth; }
    function clamp(i){ return Math.max(0, Math.min(panels.length - 1, i)); }

    function setX(x, animate){
        track.style.transition = animate ? 'transform .4s cubic-bezier(.22,1,.36,1)' : 'none';
        track.style.transform  = 'translate3d(' + x + 'px,0,0)';
    }

    /* leave the idle layout without any visible change: lay out the neighbours and
       shift the strip so the open panel stays exactly where it was */
    function wake(){
        if(!pager.classList.contains('idle')) return;
        pageW = width();
        pager.classList.remove('idle');
        setX(-idx * pageW, false);
        void track.offsetWidth;
    }

    /* back to idle: only the open panel is laid out again, no transform left behind */
    function sleep(){
        pager.classList.add('idle');
        pager.classList.remove('dragging');
        track.style.transition = 'none';
        track.style.transform  = 'none';
        if(ind) ind.classList.remove('live');
    }

    function warm(i){
        if(i < 0 || i >= ids.length) return;
        if(typeof window.dzGridActivate === 'function') window.dzGridActivate(ids[i]);
    }

    /* ── SHARED DOCK ──
       The Newest / Popular / Oldest / Collections bars AND the Messages filter buttons live above the pager,
       so none of them move while the panels slide. Switching tab only swaps WHICH bar shows — they all
       share one size, so nothing jumps. */
    var dock = document.getElementById('rpSortDock');
    var DOCK_BAR = { videos:'videosSortBar', memes:'memesSortBar', reposts:'repostsSortBar', tags:'tagsSortBar', favorites:'favsSortBar', messages:'msgFilterBar' };   // panel id → bar id

    function dockShow(panelId){
        if(!dock) return;
        var want = DOCK_BAR[panelId];
        if(want === undefined) return;
        Array.prototype.forEach.call(dock.querySelectorAll('.grid-sort-bar, .msg-filter-bar'), function(b){
            b.style.display = (b.id === want) ? '' : 'none';
        });
    }

    /* tab underline at a fractional position (follows the finger) */
    function indicatorAt(p){
        if(!ind || !tabBtns.length) return;
        p = Math.max(0, Math.min(tabBtns.length - 1, p));
        var tr = tabsTrack.getBoundingClientRect();
        function box(b){
            var c = b.querySelector('.rp-tab-content') || b, r = c.getBoundingClientRect();
            return { l: r.left - tr.left, w: r.width };
        }
        var i = Math.floor(p), f = p - i;
        var a = box(tabBtns[i]), b = box(tabBtns[Math.min(i + 1, tabBtns.length - 1)]);
        ind.style.left  = (a.l + (b.l - a.l) * f) + 'px';
        ind.style.width = (a.w + (b.w - a.w) * f) + 'px';
        ind.classList.add('ready');
    }

    function settle(){
        clearTimeout(settleTimer);
        sleep();
    }

    function goTo(i, animate){
        i = clamp(i);
        var prev = idx;
        wake();                       // uses the OLD idx for the starting offset
        idx = i;

        tabBtns.forEach(function(b, k){ b.classList.toggle('active', k === i); });
        panels.forEach(function(p, k){ p.classList.toggle('rp-active', k === i); });

        warm(i);                      // skeleton + first 9 for a panel that was never opened
        dockShow(ids[i]);             // the dock bar of the tab you are going to

        if(ind) ind.classList.remove('live');
        rpMoveTabIndicator(tabBtns[i]);
        try { tabBtns[i].scrollIntoView({ inline:'nearest', block:'nearest' }); } catch(e){}

        pageW = width();
        setX(-i * pageW, animate);

        clearTimeout(settleTimer);
        if(animate) settleTimer = setTimeout(settle, 440);
        else settle();
    }

    /* called by rpSelectTab(); true = handled here */
    window.rpPagerGo = function(sectionId){
        var i = ids.indexOf(sectionId);
        if(i < 0) return false;
        if(i !== idx) goTo(i, true);
        else {
            tabBtns.forEach(function(b, k){ b.classList.toggle('active', k === i); });
            rpMoveTabIndicator(tabBtns[i]);
        }
        return true;
    };

    window.addEventListener('resize', function(){
        pageW = width();
        if(!pager.classList.contains('idle')) setX(-idx * pageW, false);
        rpMoveTabIndicator(tabBtns[idx]);
    });

    /* ── touch / mouse drag ── */
    var st = null, swallowClick = false;

    document.addEventListener('click', function(e){
        if(swallowClick){ e.stopPropagation(); e.preventDefault(); }
    }, true);

    function hScrollable(el){
        while(el && el !== pager){
            if(el.nodeType === 1){
                if(el.hasAttribute && el.hasAttribute('data-no-swipe')) return true;
                var cs = window.getComputedStyle(el);
                if((cs.overflowX === 'auto' || cs.overflowX === 'scroll') && el.scrollWidth > el.clientWidth + 1) return true;
            }
            el = el.parentNode;
        }
        return false;
    }

    pager.addEventListener('pointerdown', function(e){
        if(e.pointerType === 'mouse' && e.button !== 0) return;
        if(panels.length < 2) return;
        var t = e.target;
        if(t && t.closest && t.closest('input, textarea, select')) return;
        if(hScrollable(t)) return;
        st = { id:e.pointerId, x:e.clientX, y:e.clientY, lock:null, base:0, samples:[{ x:e.clientX, t:performance.now() }] };
    });

    pager.addEventListener('pointermove', function(e){
        if(!st || e.pointerId !== st.id) return;
        var dx = e.clientX - st.x, dy = e.clientY - st.y;

        if(!st.lock){
            if(Math.abs(dx) < 6 && Math.abs(dy) < 6) return;
            if(Math.abs(dy) > Math.abs(dx)){ st = null; return; }   // vertical → normal page scroll
            st.lock = 'x';
            clearTimeout(settleTimer);
            wake();
            pageW = width();
            pager.classList.add('dragging');
            if(ind) ind.classList.add('live');
            warm(idx - 1); warm(idx + 1);                           // neighbours show their skeleton as they slide in
            try { pager.setPointerCapture(e.pointerId); } catch(err){}
            var m = /translate3d\((-?[\d.]+)px/.exec(track.style.transform);
            st.base = m ? parseFloat(m[1]) : -idx * pageW;
            st.x = e.clientX; dx = 0;
        }

        var x = st.base + dx, minX = -(panels.length - 1) * pageW;
        if(x > 0)    x = x * 0.35;                                  // rubber band at the first tab
        if(x < minX) x = minX + (x - minX) * 0.35;                  // …and at the last
        setX(x, false);
        indicatorAt(-x / pageW);

        var now = performance.now();
        st.samples.push({ x:e.clientX, t:now });
        while(st.samples.length > 2 && now - st.samples[0].t > 110) st.samples.shift();
    });

    function release(e){
        if(!st || e.pointerId !== st.id) return;
        var g = st; st = null;
        if(g.lock !== 'x') return;

        try { pager.releasePointerCapture(e.pointerId); } catch(err){}
        swallowClick = true;
        setTimeout(function(){ swallowClick = false; }, 60);

        var dx = e.clientX - g.x;
        var a = g.samples[0], b = g.samples[g.samples.length - 1];
        var v = (b.t - a.t) > 10 ? (b.x - a.x) / (b.t - a.t) : 0;

        var target = idx;
        if(Math.abs(v) > 0.35)                target = idx + (v < 0 ? 1 : -1);
        else if(Math.abs(dx) > pageW * 0.22)  target = idx + (dx < 0 ? 1 : -1);
        goTo(target, true);
    }
    pager.addEventListener('pointerup', release);
    pager.addEventListener('pointercancel', release);
})();

</script>

<script>
/* ══════════════════════════════════════════════════════════════
   GRID PAGINATION MODULE — skeleton + infinite scroll (9 at a time)

   • Your PHP still renders every .meme-card into its grid. Grids with
     class "dz-paged" keep those cards hidden until this module runs.
   • The FIRST time a tab is opened (clicked OR swiped toward) that tab's
     grid shows a shimmer skeleton, then reveals the first 9 posts.
   • A sentinel at the bottom loads the next 9 as you scroll.
   • Each tab paginates independently; the skeleton plays once per tab.
   • It also enriches every card: media-type fill icon (top right,
     beside the pin icon) and, for text posts, the caption written on
     the default text image.
   • If cards are re-ordered, added or removed by your other scripts,
     the module re-applies the pagination automatically.
   • A panel counts as "open" when it carries the .rp-active class
     (the tab pager sets it) — display no longer decides, because
     every panel is laid out while a swipe is in progress.
══════════════════════════════════════════════════════════════ */
(function(){
    var PAGE       = 12;     // posts per batch
    var SKEL_FIRST = 330;   // ms the first skeleton shows
    var SKEL_MORE  = 240;   // ms the "load more" skeleton shows

    /* section id → grid id */
    var MAP = {
        memes:     'memesGrid',
        reposts:   'repostsGrid',
        tags:      'tagsGrid',
        favorites: 'favsGrid'
    };

    var states  = {};
    var feedIdx = null;

    /* ── lookup of every post in every *Feed array your PHP already builds ── */
    function dzLazyTab(sec, done){
        if(sec._dzLoading) return; sec._dzLoading = true;
        var key = sec.getAttribute('data-dz-lazy');
        function fail(){
            sec._dzLoading = false;
            var ph = sec.querySelector('.dz-lazy-ph');
            if(ph){ ph.innerHTML = 'Couldn\u2019t load. <a href="#" style="color:#ff2d2d;text-decoration:underline">Try again</a>';
                ph.querySelector('a').onclick = function(e){ e.preventDefault(); ph.textContent = 'Loading\u2026'; dzLazyTab(sec, done); }; }
        }
        var u = new URL(location.href); u.searchParams.set('dz_lazy', key); u.hash = '';
        fetch(u.toString(), { credentials:'same-origin', headers:{ 'X-Requested-With':'fetch' } }).then(function(r){
            if(!r.ok || r.headers.get('X-Dz-Lazy') !== '1') throw 0;
            return r.text();
        }).then(function(html){
            var tmp = document.createElement('div'); tmp.innerHTML = html;
            var src = tmp.firstElementChild; if(!src) throw 0;
            var scripts = [].slice.call(src.querySelectorAll('script'));
            scripts.forEach(function(sc){ sc.parentNode.removeChild(sc); });
            sec.innerHTML = src.innerHTML;
            sec.removeAttribute('data-dz-lazy');
            /* run the inline scripts (feed data) in order */
            scripts.forEach(function(sc){ var n = document.createElement('script'); n.text = sc.textContent; sec.appendChild(n); });
            sec._dzLoading = false;
            try { window.dispatchEvent(new Event('resize')); } catch(e){}
            done();
        }).catch(fail);
    }

    function buildFeedIndex(){
        feedIdx = {};
        Object.keys(window).forEach(function(k){
            if(!/Feed$/.test(k)) return;
            var arr = window[k];
            if(!Array.isArray(arr)) return;
            arr.forEach(function(it){
                if(it && it.id != null && !feedIdx[it.id]) feedIdx[it.id] = it;
            });
        });
    }

    function cardsOf(grid){
        return Array.prototype.filter.call(grid.children, function(el){
            return el.classList && el.classList.contains('meme-card');
        });
    }
    function heldOf(grid){
        return cardsOf(grid).filter(function(c){ return c.classList.contains('dz-hold'); });
    }

    /* ── card enrichment: type icon + text caption ── */
    var MEDIA_TYPES = ['image','video','gif','sticker','stickers'];
    var ICONS = {
        multi:    'ri-stack-fill',
        image:    'ri-image-fill',
        video:    'ri-vidicon-fill',
        gif:      'ri-file-gif-fill',
        sticker:  'ri-shapes-fill',
        stickers: 'ri-shapes-fill'
    };

    /* Instagram-style glyphs (solid white, drawn as one path each)
       • video  → Reels-style: rounded square, clapper lines on top, play triangle
       • multi  → the carousel icon: two overlapping rounded squares
       • sticker→ a square with a peeled corner
       • photo  → no icon, same as Instagram                                     */
    var TYPE_SVG = {
        video:
          '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="5.5" width="14" height="13" rx="3.4"/><path d="M17.6 10.3l3.9-2.5a.7.7 0 0 1 1.1.6v7.2a.7.7 0 0 1-1.1.6l-3.9-2.5z"/></svg>',
        multi:
          '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8.6 2.6h8.8a4.2 4.2 0 0 1 4.2 4.2v8.8" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round"/><rect x="2.4" y="7.4" width="14.2" height="14.2" rx="3.6"/></svg>',
        sticker:
          '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill-rule="evenodd" d="M6 2.5h12A3.5 3.5 0 0 1 21.5 6v7.6l-7.9 7.9H6A3.5 3.5 0 0 1 2.5 18V6A3.5 3.5 0 0 1 6 2.5zM13.6 21.5V17a3.5 3.5 0 0 1 3.5-3.5h4.4z"/></svg>'
    };
    TYPE_SVG.stickers = TYPE_SVG.sticker;

    function enrich(card){
        if(card.dataset.dzEnriched) return;
        card.dataset.dzEnriched = '1';

        var box = card.querySelector('.media-box');
        if(!box) return;

        var p = feedIdx ? feedIdx[card.dataset.id] : null;
        var isText = false, type = 'image', count = 1;

        if(p){
            var m = (Array.isArray(p.media) && p.media.length) ? p.media : null;
            count = m ? m.length : 1;
            var t0 = ((m ? m[0].type : p.type) || '').toLowerCase();
            if(count === 1 && MEDIA_TYPES.indexOf(t0) === -1) isText = true;
            else type = t0;
        } else if(box.querySelector('video')){
            type = 'video';
        } else {
            var img = box.querySelector('img');
            if(img && (img.getAttribute('src') || '').indexOf('Text.jpg') > -1) isText = true;
        }

        /* text post → caption on the card (CSS line-clamp adds the "…") */
        if(isText && !box.querySelector('.card-text-overlay')){
            var capEl = card.querySelector('.caption');
            var txt = capEl ? capEl.textContent.trim() : '';
            if(txt){
                var ov = document.createElement('div');
                ov.className = 'card-text-overlay';
                var sp = document.createElement('span');
                sp.textContent = txt;
                ov.appendChild(sp);
                box.appendChild(ov);
            }
        }

        /* top-right badges: pin icon (if pinned) + media type icon */
        var badges = box.querySelector('.card-badges');
        if(!badges){
            badges = document.createElement('div');
            badges.className = 'card-badges';
        }
        var pin = box.querySelector('.card-pin-indicator');
        if(pin && pin.parentNode !== badges) badges.appendChild(pin);
        var pinBtn = box.querySelector('.card-pin-btn');                 // owner's pin button: first, then the type icon
        if(pinBtn && pinBtn.parentNode !== badges) badges.insertBefore(pinBtn, badges.firstChild);

        if(!isText && !badges.querySelector('.dz-type-icon')){
            var kind = (count > 1) ? 'multi' : type;          // several media → carousel icon
            var svg  = TYPE_SVG[kind];
            if(kind === 'gif'){
                var g = document.createElement('span');
                g.className = 'dz-type-icon dz-gif';
                g.textContent = 'GIF';
                badges.appendChild(g);
            } else if(svg){
                var ic = document.createElement('span');
                ic.className = 'dz-type-icon';
                ic.innerHTML = svg;
                badges.appendChild(ic);
            }
            /* single photos get no icon (exactly like Instagram) */
        }
        if(badges.children.length && badges.parentNode !== box) box.appendChild(badges);
    }

    /* ── skeleton helpers ── */
    function showSkeleton(grid, n){
        removeSkeleton(grid);
        var sentinel = grid.querySelector('.dz-sentinel');
        for(var i = 0; i < n; i++){
            var s = document.createElement('div');
            s.className = 'dz-skel-card';
            if(sentinel) grid.insertBefore(s, sentinel);
            else grid.appendChild(s);
        }
    }
    function removeSkeleton(grid){
        grid.querySelectorAll('.dz-skel-card').forEach(function(s){ s.remove(); });
    }

    /* ── reveal the next batch ── */
    function reveal(grid, n, animate){
        heldOf(grid).slice(0, n).forEach(function(c){
            c.classList.remove('dz-hold');
            if(animate){
                c.classList.add('dz-reveal');
                setTimeout(function(){ c.classList.remove('dz-reveal'); }, 700);
            }
        });
    }

    /* ── sentinel + observer for the next batch ── */
    function watch(grid){
        var st = states[grid.id];
        if(st.io){ st.io.disconnect(); st.io = null; }

        var sentinel = grid.querySelector('.dz-sentinel');
        if(!heldOf(grid).length){
            if(sentinel) sentinel.remove();
            return;
        }
        if(!sentinel){
            sentinel = document.createElement('div');
            sentinel.className = 'dz-sentinel';
        }
        grid.appendChild(sentinel);   // always last

        if(!('IntersectionObserver' in window)){
            reveal(grid, 9999, false);
            sentinel.remove();
            return;
        }
        st.io = new IntersectionObserver(function(entries){
            if(entries[0].isIntersecting) loadMore(grid);
        }, { rootMargin:'500px 0px' });
        st.io.observe(sentinel);
    }

    function loadMore(grid){
        var st = states[grid.id];
        if(!st || st.loading || !st.ready) return;
        var remaining = heldOf(grid).length;
        if(!remaining) return;

        st.loading = true;
        if(st.io){ st.io.disconnect(); st.io = null; }
        showSkeleton(grid, Math.min(PAGE, remaining));

        setTimeout(function(){
            removeSkeleton(grid);
            reveal(grid, PAGE, true);
            st.loading = false;
            watch(grid);
        }, SKEL_MORE);
    }

    /* ── first time a tab is opened: skeleton → first 9 → infinite scroll ── */
    function activate(sectionId){
        var gid  = MAP[sectionId];
        var grid = gid ? document.getElementById(gid) : null;
        if(!grid || states[gid]) return;          // only ever once per tab

        /* Reposts / Tags / Saved: fetch the tab's posts the first time it opens, then activate it */
        var lsec = document.getElementById(sectionId);
        if(lsec && lsec.getAttribute('data-dz-lazy')){
            dzLazyTab(lsec, function(){ feedIdx = null; activate(sectionId); });
            return;
        }

        var st = states[gid] = { ready:false, loading:false, io:null, timer:null };
        if(!feedIdx) buildFeedIndex();

        var cards = cardsOf(grid);
        if(!cards.length){                         // empty tab → nothing to page
            grid.classList.add('dz-live');
            st.ready = true;
            return;
        }

        cards.forEach(function(c){
            c.classList.add('dz-hold');
            enrich(c);
        });
        grid.classList.add('dz-live');

        showSkeleton(grid, Math.min(PAGE, cards.length));
        setTimeout(function(){
            removeSkeleton(grid);
            reveal(grid, PAGE, true);
            st.ready = true;
            watch(grid);
        }, SKEL_FIRST);
    }

    /* ── re-apply pagination when cards are re-ordered / added / removed ── */
    function refresh(grid){
        var st = states[grid.id];
        if(!st || !st.ready || st.loading) return;

        buildFeedIndex();
        var cards   = cardsOf(grid);
        var visible = cards.filter(function(c){ return !c.classList.contains('dz-hold'); }).length;
        var n       = Math.max(PAGE, visible);

        cards.forEach(function(c){ c.classList.add('dz-hold'); });
        cards.forEach(function(c, i){
            if(i < n) c.classList.remove('dz-hold');
            enrich(c);
        });
        watch(grid);
    }

    function isOwnNode(n){
        return n.nodeType !== 1 ||
               n.classList.contains('dz-skel-card') ||
               n.classList.contains('dz-sentinel');
    }

    function observeGridChanges(grid){
        var mo = new MutationObserver(function(muts){
            var changed = false;
            muts.forEach(function(m){
                Array.prototype.forEach.call(m.addedNodes,   function(n){ if(!isOwnNode(n)) changed = true; });
                Array.prototype.forEach.call(m.removedNodes, function(n){ if(!isOwnNode(n)) changed = true; });
            });
            if(!changed) return;
            var st = states[grid.id];
            if(!st) return;
            clearTimeout(st.timer);
            st.timer = setTimeout(function(){ refresh(grid); }, 60);
        });
        mo.observe(grid, { childList:true });
    }

    /* ── start: each tab section activates when it becomes the open (.rp-active) panel ── */
    function init(){
        Object.keys(MAP).forEach(function(sectionId){
            var sec  = document.getElementById(sectionId);
            var grid = document.getElementById(MAP[sectionId]);
            if(!sec) return;
            if(grid) observeGridChanges(grid);

            function check(){
                if(sec.classList.contains('rp-active')) activate(sectionId);
            }
            new MutationObserver(check).observe(sec, { attributes:true, attributeFilter:['class'] });
            check();
        });
    }

    window.dzGridActivate = activate;
    window.dzGridRefresh  = function(gridId){
        var g = document.getElementById(gridId);
        if(g) refresh(g);
    };

    if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
</script>

<script>
/* ══════════════════════════════════════════════════════════════
   GRID VIEW FILTER — one place decides which cards of the open tab show.
   • window.dzSearchQ  (the post search, own profile)  • window.dzMemeKind  (Memes tab: all | video | text)
   A card shows when it passes BOTH, including cards the pager hasn't revealed yet.
══════════════════════════════════════════════════════════════ */
(function(){
    var MAP = { memes:'memesGrid', reposts:'repostsGrid', tags:'tagsGrid', favorites:'favsGrid' };
    window.dzSearchQ = ''; window.dzMemeKind = 'all';
    var none = document.createElement('div'); none.className = 'grid-search-none';
    var pager = document.getElementById('rpPager');
    if(pager && pager.parentNode) pager.parentNode.insertBefore(none, pager);
    function activeKey(){ for(var k in MAP){ var s = document.getElementById(k); if(s && s.classList.contains('rp-active')) return k; } return null; }
    function clearGrid(g){
        g.classList.remove('dz-searching');
        Array.prototype.forEach.call(g.children, function(c){ if(c.classList) c.classList.remove('dz-sr-hide', 'dz-sr-show'); });
    }
    window.dzGridView = function(){
        var key = activeKey(), shown = 0;
        Object.keys(MAP).forEach(function(k){ var g = document.getElementById(MAP[k]); if(g && k !== key) clearGrid(g); });
        var q = window.dzSearchQ || '', kind = (key === 'memes') ? window.dzMemeKind : 'all';
        document.documentElement.classList.toggle('dz-filtering', key === 'memes' && (!!q || kind !== 'all'));   // hides the Featured card while searching / filtering
        if(!key){ none.classList.remove('show'); return; }
        var grid = document.getElementById(MAP[key]); if(!grid) return;
        if(!q && kind === 'all'){ clearGrid(grid); none.classList.remove('show'); if(typeof window.dzGridRefresh === 'function') window.dzGridRefresh(grid.id); return; }
        grid.classList.add('dz-searching');
        Array.prototype.forEach.call(grid.children, function(c){
            if(!c.classList || !c.classList.contains('meme-card')) return;
            var hit = (!q || (c.getAttribute('data-search') || '').indexOf(q) !== -1) && (kind === 'all' || c.getAttribute('data-kind') === kind);
            c.classList.toggle('dz-sr-hide', !hit);
            c.classList.toggle('dz-sr-show', hit);
            if(hit) shown++;
        });
        none.textContent = q ? 'No posts match your search' : (kind === 'video' ? 'No videos here yet' : 'No text posts here yet');
        none.classList.toggle('show', shown === 0);
    };
    Object.keys(MAP).concat(['messages']).forEach(function(id){
        var sec = document.getElementById(id);
        if(sec) new MutationObserver(function(){ window.dzGridView(); }).observe(sec, { attributes:true, attributeFilter:['class'] });
    });
})();
</script>




<?php if ($is_owner_view_c): ?>
<script>
/* ══════════════════════════════════════════════════════════════
   POST SEARCH — own profile, Memes / Reposts / Tags / Saved.
   The search icon at the right end of the sort bar drops the search bar down (like Messages);
   pressing it again closes it and clears the search. Matches caption + poster name across EVERY post
   of the open tab (including ones the pager hasn't revealed yet). Switching tab re-applies it to that tab.
══════════════════════════════════════════════════════════════ */
(function(){
    var MAP = { memes:'memesGrid', reposts:'repostsGrid', tags:'tagsGrid', favorites:'favsGrid' };
    var wrap = document.getElementById('gridSearchWrap'), inp = document.getElementById('gridSearchInput'), clr = document.getElementById('gridSearchClear');
    if(!wrap || !inp) return;
    var q = '', isOpen = false, rec = document.getElementById('gridSearchRecent');
    var UID = <?= (int)$profile_id ?>;

    /* recent searches: the last 5 unique ones for each tab grid (kept in this browser) */
    var MAXREC = 5;
    function rkey(k){ return 'dzRecentSearch_' + UID + '_' + k; }
    function getRecent(k){ try { var a = JSON.parse(localStorage.getItem(rkey(k)) || '[]'); return Array.isArray(a) ? a.slice(0, MAXREC) : []; } catch(e){ return []; } }
    function saveRecent(k, term){
        term = (term || '').trim(); if(!k || term.length < 2) return;
        var low = term.toLowerCase(), a = getRecent(k).filter(function(t){ return t.toLowerCase() !== low; });
        a.unshift(term); a = a.slice(0, MAXREC);
        try { localStorage.setItem(rkey(k), JSON.stringify(a)); } catch(e){}
    }
    function renderRecent(){
        var k = activeKey(), a = (k && !q) ? getRecent(k) : [];
        rec.innerHTML = '';
        a.forEach(function(t){
            var b = document.createElement('button'); b.type = 'button'; b.className = 'grid-search-chip';
            var i = document.createElement('i'); i.className = 'ri-history-line';
            b.appendChild(i); b.appendChild(document.createTextNode(t));
            b.onclick = function(){ inp.value = t; dzGridSearch(t); saveRecent(k, t); inp.focus(); };
            rec.appendChild(b);
        });
        rec.classList.toggle('show', a.length > 0 && isOpen);
    }
    var saveT = 0;

    function activeKey(){
        for(var k in MAP){ var s = document.getElementById(k); if(s && s.classList.contains('rp-active')) return k; }
        return null;
    }
    function apply(){
        var key = activeKey();
        var PFX = { memes:'memes', reposts:'reposts', tags:'tags', favorites:'favs' };
        var onGridTab = !!key && !!document.getElementById(PFX[key] + 'Srch');   // a tab with no posts has no search button → no search bar
        wrap.style.display = onGridTab ? '' : 'none';
        if(!onGridTab) rec.classList.remove('show');
        window.dzSearchQ = q;
        if(typeof window.dzGridView === 'function') window.dzGridView();
    }
    function applyAll(){ apply(); renderRecent(); }
    function setBtns(on){
        Array.prototype.forEach.call(document.querySelectorAll('.g-search-btn'), function(b){ b.classList.toggle('active', on); b.setAttribute('aria-expanded', on ? 'true' : 'false'); });
    }
    window.dzGridSearchToggle = function(){
        dzHp('light');
        isOpen = !isOpen;
        wrap.classList.toggle('open', isOpen);
        wrap.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
        setBtns(isOpen);
        if(isOpen){ setTimeout(function(){ inp.focus(); }, 260); }
        else { inp.value = ''; q = ''; clr.classList.remove('show'); apply(); }
        renderRecent();
    };
    window.dzGridSearch = function(v){
        q = (v || '').trim().toLowerCase();
        clr.classList.toggle('show', !!v);
        apply(); renderRecent();
        clearTimeout(saveT);
        if(q) saveT = setTimeout(function(){ saveRecent(activeKey(), v); }, 1200);   // remembered once you pause typing
    };
    window.dzGridSearchClear = function(){ inp.value = ''; dzGridSearch(''); inp.focus(); };
    inp.addEventListener('keydown', function(e){ if(e.key === 'Enter'){ clearTimeout(saveT); saveRecent(activeKey(), inp.value); renderRecent(); inp.blur(); } });
    /* tab changed → apply to the new tab (and show that tab's recent searches) */
    Object.keys(MAP).concat(['messages']).forEach(function(id){
        var sec = document.getElementById(id);
        if(sec) new MutationObserver(function(){ applyAll(); }).observe(sec, { attributes:true, attributeFilter:['class'] });
    });
    applyAll();
})();
</script>
<?php endif; ?>

<?php /* ══════════════════════════════════════════════════════════════
   LOADING WHEEL — 12 rounded bars in a circle, darkest bar at 5 o'clock fading
   round to the lightest at 6 o'clock, turning in 12 steps (the classic spinner).
   Used on the About-me square button. Its CSS lives in the main <style> block at the top.
══════════════════════════════════════════════════════════════ */ ?>

<script>
/* ══════════════════════════════════════════════════════════════
   ABOUT-ME BUTTON (own profile) — spinning wheel while the modal loads
   • Tap → the icon is replaced by the wheel at once.
   • After MIN_SPIN ms it asks dzOpenAboutMe() to open the modal.
   • The icon comes back as soon as the modal is open (detected from the
     function's own promise, or from a new "about" element appearing, or from
     the page scroll-lock every modal here sets), or after MAX_SPIN at the latest.
   The About tab other people see on your profile is NOT touched.
══════════════════════════════════════════════════════════════ */
(function(){
    var MIN_SPIN = 350;     // ms the wheel spins before the modal is asked to open
    var MAX_SPIN = 6000;    // safety: the wheel never spins longer than this
    var busy = false;

    function wheelHTML(){
        var h = '<span class="dz-wheel" aria-hidden="true">';
        for (var k = 0; k < 8; k++){
            var d = (3 - k + 8) % 8;                            // 0 = darkest bar (head) ... 7 = faintest tail
            var o = (1 - d * (0.82 / 7)).toFixed(3);
            h += '<span style="transform:rotate(' + (k * 45) + 'deg);opacity:' + o + '"></span>';
        }
        return h + '</span>';
    }

    function visibleAbout(btn){
        var out = [];
        var list = document.querySelectorAll('[id*="bout" i], [class*="bout" i]');
        for (var i = 0; i < list.length; i++){
            var n = list[i];
            if (n === btn || n.contains(btn) || btn.contains(n)) continue;
            var cs = window.getComputedStyle(n);
            if (cs.display !== 'none' && cs.visibility !== 'hidden' && n.offsetWidth > 0 && n.offsetHeight > 0) out.push(n);
        }
        return out;
    }

    window.dzAboutBtn = function(btn, uid){
        if (!btn || busy) return;
        dzHp('light');
        busy = true;

        var original = btn.innerHTML;
        var finished = false, mo = null, tMax = null;

        btn.classList.add('is-loading');
        btn.setAttribute('aria-busy', 'true');
        btn.innerHTML = wheelHTML();

        function finish(){
            if (finished) return;
            finished = true;
            if (mo) mo.disconnect();
            clearTimeout(tMax);
            btn.innerHTML = original;
            btn.classList.remove('is-loading');
            btn.removeAttribute('aria-busy');
            busy = false;
        }

        setTimeout(function(){
            if (typeof window.dzOpenAboutMe !== 'function'){ finish(); return; }

            var baseLocked = (document.body.style.overflow === 'hidden');
            var baseAbout  = visibleAbout(btn);

            function opened(){
                if (!baseLocked && document.body.style.overflow === 'hidden') return true;
                var now = visibleAbout(btn);
                for (var i = 0; i < now.length; i++){ if (baseAbout.indexOf(now[i]) === -1) return true; }
                return false;
            }
            function check(){ if (!finished && opened()) setTimeout(finish, 120); }

            mo = new MutationObserver(check);
            mo.observe(document.documentElement, { childList:true, subtree:true, attributes:true, attributeFilter:['class','style','hidden','open'] });
            tMax = setTimeout(finish, MAX_SPIN);

            var ret;
            try { ret = window.dzOpenAboutMe(uid); }
            catch(err){ console.error(err); finish(); return; }

            if (ret && typeof ret.then === 'function') ret.then(function(){ setTimeout(finish, 150); }, finish);
            else check();
        }, MIN_SPIN);
    };
})();
</script>

<script>
/* ══════════════════════════════════════════════════════════════
   POPULAR PILL FOLLOWS NEWEST / OLDEST

   When Collections opens, the Newest and Oldest pills get hidden (and a Back
   control shows) — but Popular was added later and was never part of that, so it
   stayed on screen next to Back. This keeps Popular in step with them in every
   tab (Memes, Reposts, Tags, Saved): hidden whenever Newest or Oldest is hidden,
   shown again as soon as they are. It only ever restores a pill IT hid.
══════════════════════════════════════════════════════════════ */
(function(){
    var TABS = { memes:'memes', reposts:'reposts', tags:'tags', favs:'favorites' };   // pill prefix → panel id

    function hid(el){
        if (!el) return false;
        if (el.hidden) return true;
        var cs = window.getComputedStyle(el);
        return cs.display === 'none' || cs.visibility === 'hidden';
    }

    Object.keys(TABS).forEach(function(k){
        var bar = document.getElementById(k + 'SortBar');
        var N = document.getElementById(k + 'N'), O = document.getElementById(k + 'O'), P = document.getElementById(k + 'P');
        if (!bar || !N || !P) return;
        var sec = bar;                                       // the bars live in the shared dock now
        var panel = document.getElementById(TABS[k]);
        var raf = 0;

        function sync(){
            raf = 0;
            var hide = hid(N) || hid(O);
            if (hide && P.style.display !== 'none'){
                P.style.display = 'none';
                P.setAttribute('data-dz-sync', '1');
            } else if (!hide && P.getAttribute('data-dz-sync') === '1'){
                P.style.display = '';
                P.removeAttribute('data-dz-sync');
            }
        }
        function queue(){ if (!raf) raf = requestAnimationFrame(sync); }

        new MutationObserver(function(muts){
            for (var i = 0; i < muts.length; i++){
                var t = muts[i].target;
                if (t && t.closest && t.closest('.grid')) continue;     // card changes are not interesting
                queue();
                return;
            }
        }).observe(sec, { attributes:true, subtree:true, attributeFilter:['style','class','hidden'] });

        /* any press in the bar (Collections / Back / Newest …) → re-check after the other script has run */
        function after(){ setTimeout(queue, 0); setTimeout(queue, 120); setTimeout(queue, 450); }
        sec.addEventListener('click', after, true);
        if(panel) panel.addEventListener('click', after, true);      // Back inside the Collections view

        queue();
    });
})();
</script>

<script>
/* ══════════════════════════════════════════════════════════════
   PIN BUTTON (own profile only) — keeps every copy of a post in step

   The pin button on a grid card is white (not pinned) and turns red (pinned).
   gTogglePin() still does the real pin / unpin; this only keeps the page honest afterwards:
     • icon / title / aria-pressed follow the button's .pinned class
     • card data-pinned follows it (that is what "pinned first" sorting reads)
     • the same post in another tab (Reposts, Saved …) and the modal feeds follow it
     • a stray red pin badge is removed (the button already shows the state)
     • the grids re-sort so pinned posts jump to the front, or fall back into place
   Other people never get a button — they only see the red pin on a pinned post.
══════════════════════════════════════════════════════════════ */
(function(){
    /* these two are defined in the collection modals file; the shims only matter if that file loads later */
    if(typeof window.dzSaveOpen !== 'function') window.dzSaveOpen = function(id){ if(typeof openSaveModal === 'function') openSaveModal(id); };

    if(!<?= ($current_user == $profile_id) ? 'true' : 'false' ?>) return;

    var FEEDS = ['profileFeed', 'repostFeed', 'tagsFeed', 'favsFeed'];
    var raf = 0;

    function resortAll(){
        if(typeof window.dzSort !== 'function') return;
        ['memes', 'reposts', 'tags', 'favs'].forEach(function(key){
            var grid = document.getElementById(key + 'Grid');
            if(!grid) return;
            var col = document.getElementById(key + 'ColSec');
            if(col && window.getComputedStyle(col).display !== 'none') return;     // Collections is open in this tab: leave it alone
            var bar = document.getElementById(key + 'SortBar'), mode = 'newest';
            if(bar){
                var a = bar.querySelector('.g-pill.active');
                if(a && a.id === key + 'P') mode = 'popular';
                else if(a && a.id === key + 'O') mode = 'oldest';
            }
            window.dzSort(grid.id, mode, key);
        });
    }

    function sync(){
        raf = 0;
        var changed = {};
        document.querySelectorAll('.meme-card .card-pin-btn').forEach(function(btn){
            var card = btn.closest('.meme-card');
            if(!card) return;
            var on  = btn.classList.contains('pinned');
            var was = card.getAttribute('data-pinned') === '1';
            if(was !== on){ card.setAttribute('data-pinned', on ? '1' : '0'); changed[card.getAttribute('data-id')] = on; }

            var i = btn.querySelector('i'), want = on ? 'ri-pushpin-fill' : 'ri-pushpin-line';
            if(i && i.className !== want) i.className = want;
            var t = on ? 'Unpin from profile' : 'Pin to profile';
            if(btn.title !== t) btn.title = t;
            var ap = on ? 'true' : 'false';
            if(btn.getAttribute('aria-pressed') !== ap) btn.setAttribute('aria-pressed', ap);

            card.querySelectorAll('.card-pin-indicator').forEach(function(x){ x.remove(); });
        });

        var keys = Object.keys(changed);
        if(!keys.length) return;
        keys.forEach(function(id){
            var on = changed[id];
            document.querySelectorAll('.meme-card[data-id="' + id + '"]').forEach(function(c){
                if(!c.querySelector('.card-pin-btn')) return;   /* only the Memes grid carries pins */
                if(c.getAttribute('data-pinned') !== (on ? '1' : '0')) c.setAttribute('data-pinned', on ? '1' : '0');
                var b = c.querySelector('.card-pin-btn');
                if(b && b.classList.contains('pinned') !== on) b.classList.toggle('pinned', on);
            });
            FEEDS.forEach(function(n){
                var f = window[n];
                if(Array.isArray(f)) f.forEach(function(p){ if(String(p.id) === String(id)) p.isPinned = on; });
            });
        });
        resortAll();
    }
    function queue(){ if(!raf) raf = requestAnimationFrame(sync); }

    /* the real pin / unpin call — used by the card pin button AND the Post options sheet */
    function pinToast(m){ if(typeof gToast === 'function') gToast(m); }
    function pinTogglePost(id, btn){
        id = parseInt(id, 10);
        if(!id || !btn || btn.dataset.busy) return;
        var on = btn.classList.contains('pinned');
        btn.dataset.busy = '1';
        fetch(window.location.pathname + window.location.search, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
            body: 'col_action=' + (on ? 'unpin_post' : 'pin_post') + '&meme_id=' + id
        }).then(function(r){ return r.json(); }).then(function(d){
            delete btn.dataset.busy;
            if(!d || d.status !== 'ok'){ pinToast((d && (d.message || d.msg)) || 'Could not update pin'); return; }
            var now = !!d.pinned;
            btn.classList.toggle('pinned', now);
            /* every copy of this post on the page + the modal feeds follow at once */
            document.querySelectorAll('.meme-card[data-id="' + id + '"]').forEach(function(c){
                c.setAttribute('data-pinned', now ? '1' : '0');
                var b = c.querySelector('.card-pin-btn');
                if(b) b.classList.toggle('pinned', now);
            });
            FEEDS.forEach(function(n){
                var f = window[n];
                if(Array.isArray(f)) f.forEach(function(p){ if(String(p.id) === String(id)) p.isPinned = now; });
            });
            sync();                       // icon / title / aria + re-sort (pinned first)
            pinToast(now ? 'Pinned to your profile' : 'Unpinned');
        }).catch(function(){ delete btn.dataset.busy; pinToast('Network error'); });
    }
    window.gTogglePin = pinTogglePost;
    /* if another script defines gTogglePin after this one, ours still wins */
    window.addEventListener('load', function(){ window.gTogglePin = pinTogglePost; });

    function relevant(m){
        if(m.type === 'attributes'){
            var t = m.target;
            return !!(t && t.closest && t.closest('.card-pin-btn'));
        }
        for(var i = 0; i < m.addedNodes.length; i++){
            var n = m.addedNodes[i];
            if(n.nodeType === 1 && ((n.matches && n.matches('.card-pin-indicator')) || (n.querySelector && n.querySelector('.card-pin-indicator')))) return true;
        }
        return false;
    }
    var mo = new MutationObserver(function(muts){
        for(var i = 0; i < muts.length; i++){ if(relevant(muts[i])){ queue(); return; } }
    });
    ['memesGrid', 'repostsGrid', 'tagsGrid', 'favsGrid'].forEach(function(id){
        var el = document.getElementById(id);
        if(el) mo.observe(el, { subtree:true, childList:true, attributes:true, attributeFilter:['class'] });
    });
    queue();
})();
</script>

<script>
/* keep me "online" while this page is open */
setInterval(function(){
    if(document.hidden) return;
    fetch('profile.php', { method:'POST', headers:{ 'Content-Type':'application/x-www-form-urlencoded' }, credentials:'same-origin', body:'dz_ping=1' }).catch(function(){});
}, 60000);
</script>

<script>
/* ── Track (public profiles) — spinner then submit ── */
function showSpinner(btn) {
    if (btn.name === 'follow' && window._dzLiveTrack) {   // public profile → track in the background, no page refresh
        dzLiveFollow(btn, true);
        return;
    }
    var actionName = btn.name;
    var form0 = btn.form || document.getElementById('rpTrackForm');   // own profile: the Track / Untrack form of its own
    var color = getComputedStyle(btn).color;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-icon" style="color:' + color + ';"><span></span><span></span><span></span><span></span></span>';

    setTimeout(function() {
        var form = form0;
        if (!form) return;
        var hidden = document.createElement('input');
        hidden.type  = 'hidden';
        hidden.name  = actionName;
        hidden.value = '1';
        form.appendChild(hidden);
        form.submit();
    }, 120);
}

/* ══════════════════════════════════════════════════════════════
   TOPBAR TRACK BUTTON
   • Public profile  → spinner, then submits the real #rpTrackForm ("follow")
   • Private profile → same background "Request to Track" flow as the main
     button, so it flips straight to "Requested" everywhere.
══════════════════════════════════════════════════════════════ */
function dzTopbarTrack(btn) {
    if (!btn || btn.disabled) return;
    dzHp('medium');

    if (btn.getAttribute('data-private') === '1') { dzRequestTrack(btn); return; }

    if (window._dzLiveTrack) { dzLiveFollow(btn, true); return; }

    var form = document.getElementById('rpTrackForm');
    if (!form) return;

    btn.style.minWidth = btn.offsetWidth + 'px';
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-icon" style="color:#000;"><span></span><span></span><span></span><span></span></span>';

    setTimeout(function() {
        var hidden = document.createElement('input');
        hidden.type  = 'hidden';
        hidden.name  = 'follow';
        hidden.value = '1';
        form.appendChild(hidden);
        form.submit();
    }, 120);
}

/* keeps the topbar Track button in step when tracking happens elsewhere
   (main button / pic modal) so it never shows a stale label. */
function syncTopbarTrack(state) {
    var tb   = document.getElementById('rpTopbar');
    var wrap = tb ? tb.querySelector('.rp-tb-track-wrap') : null;
    if (!tb || !wrap) return;
    if (state === 'tracked') {
        if (wrap.parentNode) wrap.parentNode.removeChild(wrap);
        tb.classList.add('no-track');
    } else if (state === 'pending') {
        wrap.innerHTML = '<button type="button" class="rp-tb-track-btn pending" onclick="dzCancelRequest(this)">Requested</button>';
    } else if (state === 'none') {
        wrap.innerHTML = '<button type="button" class="rp-tb-track-btn" id="rpTopbarTrackBtn" data-private="1" onclick="dzTopbarTrack(this)">Request to Track</button>';
    }
}

/* ══════════════════════════════════════════════════════════════
   REQUEST TO TRACK / REQUESTED  (private profiles)
   Posts in the background (same field names your PHP already reads:
   send_track_request / cancel_track_request), then swaps EVERY button
   (main, topbar, profile-pic modal) at once — no reload needed.
══════════════════════════════════════════════════════════════ */
function dzTrackPost(action, ok, fail) {
    var id = (typeof _picModalProfileId !== 'undefined') ? _picModalProfileId : 0;
    fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: action + '=1&target_id=' + id
    }).then(function(r){
        if (!r.ok) throw new Error('failed');
        ok();
    }).catch(function(){ fail(); });
}

function dzSetTrackState(state) {
    var form = document.getElementById('rpTrackForm');
    if (state === 'pending') {
        if (form) form.innerHTML = '<button type="button" class="rp-rect-btn track pending" onclick="dzCancelRequest(this)">Requested</button>';
        syncTopbarTrack('pending');
        var w1 = document.getElementById('picModalActions');
        if (w1) w1.innerHTML = '<a href="#" onclick="dzCancelRequest(this); return false;" class="modal-track-btn pending">Requested</a>';
    } else {
        if (form) form.innerHTML = '<button type="button" class="rp-rect-btn track" onclick="dzRequestTrack(this)">Request to Track</button>';
        syncTopbarTrack('none');
        var w2 = document.getElementById('picModalActions');
        if (w2) w2.innerHTML = '<a href="#" onclick="modalTrackUser(\'send_track_request\', this); return false;" class="modal-track-btn">Request to Track</a>';
    }
}

function dzRequestTrack(btn) {
    if (!btn || btn.disabled) return;
    dzHp('medium');
    var label = btn.textContent;
    btn.style.minWidth = btn.offsetWidth + 'px';
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-icon" style="color:currentColor;"><span></span><span></span><span></span><span></span></span>';
    dzTrackPost('send_track_request',
        function(){ dzSetTrackState('pending'); picModalToast('Request sent'); },
        function(){ dzHp('error'); btn.disabled = false; btn.textContent = label; picModalToast('Something went wrong'); });
}

function dzCancelRequest(btn) {
    if (!btn || btn.disabled) return;
    dzHp('light');
    var label = btn.textContent;
    btn.style.pointerEvents = 'none';
    btn.innerHTML = '<span class="spinner-icon" style="color:currentColor;"><span></span><span></span><span></span><span></span></span>';
    dzTrackPost('cancel_track_request',
        function(){ dzSetTrackState('none'); picModalToast('Request cancelled'); },
        function(){ dzHp('error'); btn.style.pointerEvents = ''; btn.textContent = label; picModalToast('Something went wrong'); });
}
</script>



<script>
/* ── Profile pic modal (Track / Vibe Check / Share / Copy Link / QR) ── */
var _picModalProfileId = <?= (int)$profile_id ?>;
var _vibeOwner   = <?= ($current_user == $profile_id) ? 'true' : 'false' ?>;
var _vibeEnabled = <?= $vibe_enabled ? 'true' : 'false' ?>;

/* profile viewer lock — the owner can switch the enlarged picture viewer off for everybody else */
function dzPicLockIcon(locked){
    return locked
      ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>'
      : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 7.5-1.9"/></svg>';
}
function dzPicLockPaint(){
    var L = window.DZ_PIC_LOCK || {};
    var ic = document.getElementById('pvLockIc'), lb = document.getElementById('pvLockLbl');
    if(ic) ic.innerHTML = dzPicLockIcon(!!L.locked);
    if(lb) lb.textContent = L.locked ? 'Viewer: Off' : 'Viewer: On';
    var av = document.getElementById('profilePic');
    if(av && !L.own) av.style.cursor = L.locked ? 'default' : '';
}
function dzPicLockToggle(){
    var L = window.DZ_PIC_LOCK; if(!L || !L.own) return;
    var next = !L.locked;
    L.locked = next; dzPicLockPaint();                       // instant
    fetch('profile.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'pic_viewer_action=set&locked=' + (next ? 1 : 0) })
      .then(function(r){ return r.json(); })
      .then(function(d){
          if(d && d.status === 'ok'){ picModalToast(next ? 'Others can no longer open your profile picture viewer' : 'Others can open your profile picture viewer again'); }
          else { L.locked = !next; dzPicLockPaint(); picModalToast('Could not change this — try again'); }
      })
      .catch(function(){ L.locked = !next; dzPicLockPaint(); picModalToast('Could not change this — try again'); });
}
document.addEventListener('DOMContentLoaded', dzPicLockPaint);

function openPicModal(){
    dzHp('light');
    if(window._dzNoPicUntil && Date.now() < window._dzNoPicUntil) return;   /* the tap that closed Vibe check must not re-open the viewer */
    var _L = window.DZ_PIC_LOCK;
    if(_L && _L.locked && !_L.own){
        if(typeof gToast === 'function') gToast((_L.name ? _L.name + ' has' : 'This user has') + ' turned off the profile picture viewer');
        return;
    }
    var modal = document.getElementById('picModal');
    var img = document.getElementById('modalImg');
    var avatar = document.getElementById('profilePic');
    if(!modal) return;
    if(img && avatar) img.src = avatar.src;
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function closePicModal(){
    var modal = document.getElementById('picModal');
    if(!modal) return;
    modal.style.display = 'none';
    document.body.style.overflow = '';
}
/* tap on any empty (black) part of the viewer closes it — also the empty strip around the buttons, and after Vibe check was opened and closed */
document.addEventListener('click', function(e){
    var modal = document.getElementById('picModal');
    if(!modal || modal.style.display !== 'flex') return;
    var t = e.target;
    if(!(t instanceof Element) || !modal.contains(t)) return;
    if(t.closest('.modal-content, .modal-action-btn, .modal-track-btn, .close, a, button')) return;
    closePicModal();
}, true);
document.addEventListener('keydown', function(e){
    if(e.key === 'Escape') closePicModal();
});

function picModalToast(msg){
    var t = document.getElementById('picModalToast');
    if(!t) return;
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(t._tid);
    t._tid = setTimeout(function(){ t.classList.remove('show'); }, 2400);
}

/* tap the nickname → copies "@nickname" */
function dzCopyNickname(el){
    var txt = el ? (el.getAttribute('data-nick') || el.textContent || '').trim() : '';
    if(!txt) return;
    function done(ok){ dzHp(ok ? 'success' : 'error'); picModalToast(ok ? 'Nickname copied' : 'Could not copy'); }
    if(navigator.clipboard && navigator.clipboard.writeText){
        navigator.clipboard.writeText(txt).then(function(){ done(true); }).catch(function(){ fallback(); });
    } else { fallback(); }
    function fallback(){
        try {
            var ta = document.createElement('textarea');
            ta.value = txt; ta.setAttribute('readonly', '');
            ta.style.cssText = 'position:fixed;top:-1000px;opacity:0;';
            document.body.appendChild(ta); ta.select(); ta.setSelectionRange(0, txt.length);
            var ok = document.execCommand('copy');
            document.body.removeChild(ta);
            done(ok);
        } catch(e){ done(false); }
    }
}

/* Track from inside the modal — posts in the background, then swaps the
   modal row / main button / topbar button without leaving the page. */
function modalTrackUser(actionName, btn){
    dzHp(actionName === 'follow' || actionName === 'send_track_request' ? 'medium' : 'light');
    /* public profile: behave exactly like the page + topbar Track buttons —
       spinner, then submit the real form (page refreshes) */
    if(actionName === 'follow'){
        var trackForm = document.getElementById('rpTrackForm');
        if(window._dzLiveTrack){ dzLiveFollow(btn, true); return; }
        if(trackForm){
            if(btn){
                btn.style.minWidth = btn.offsetWidth + 'px';
                btn.style.pointerEvents = 'none';
                btn.innerHTML = '<span class="spinner-icon" style="color:#000;"><span></span><span></span><span></span><span></span></span>';
            }
            setTimeout(function(){
                var hidden = document.createElement('input');
                hidden.type  = 'hidden';
                hidden.name  = 'follow';
                hidden.value = '1';
                trackForm.appendChild(hidden);
                trackForm.submit();
            }, 120);
            return;
        }
    }
    if(btn){ btn.style.pointerEvents = 'none'; btn.dataset.origText = btn.textContent; btn.textContent = '...'; }

    /* Post to the CURRENT url (pathname + querystring) so the ?user_id=…
       target is kept; also send target_id explicitly as a fallback. */
    var postUrl = window.location.pathname + window.location.search;
    fetch(postUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: actionName + '=1&target_id=' + _picModalProfileId
    }).then(function(r){
        if(!r.ok) throw new Error('Track request failed');
        return r;
    }).then(function(){
        if(actionName === 'follow'){
            renderPicModalTrackedRow();
            syncMainTrackBtnToTracked();
            picModalToast('Now tracking');
        } else if(actionName === 'send_track_request'){
            dzSetTrackState('pending');
            picModalToast('Request sent');
        }
    }).catch(function(){
        picModalToast('Something went wrong');
        if(btn){ btn.style.pointerEvents = ''; btn.textContent = btn.dataset.origText; }
    });
}

function renderPicModalTrackedRow(){
    var wrap = document.getElementById('picModalActions');
    if(!wrap) return;
    var vibeBtn = _vibeEnabled ?
        '<a href="#" onclick="vibeCheck(' + _picModalProfileId + '); return false;" class="modal-action-btn">' +
            '<div class="modal-action-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
                '<path d="M12 3l1.8 4.2L18 9l-4.2 1.8L12 15l-1.8-4.2L6 9l4.2-1.8L12 3z"/>' +
                '<path d="M5 16l.8 2.2L8 19l-2.2.8L5 22l-.8-2.2L2 19l2.2-.8L5 16z"/>' +
                '<path d="M19 14l1 2.5L22.5 17 20 18l-1 2.5L18 18l-2.5-1 2.5-.5L19 14z"/>' +
            '</svg></div><span>Vibe Check</span></a>' : '';
    wrap.innerHTML = vibeBtn +
        '<a href="#" onclick="shareProfile(' + _picModalProfileId + '); return false;" class="modal-action-btn">' +
            '<div class="modal-action-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
                '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/>' +
                '<line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>' +
            '</svg></div><span>Share</span></a>' +
        '<a href="#" onclick="copyProfileLink(' + _picModalProfileId + '); return false;" class="modal-action-btn">' +
            '<div class="modal-action-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
                '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>' +
                '<path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>' +
            '</svg></div><span>Copy Link</span></a>' +
        '<a href="#" onclick="showQR(' + _picModalProfileId + '); return false;" class="modal-action-btn">' +
            '<div class="modal-action-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
                '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>' +
                '<rect x="5" y="5" width="3" height="3" fill="currentColor" stroke="none"/>' +
                '<rect x="16" y="5" width="3" height="3" fill="currentColor" stroke="none"/>' +
                '<rect x="5" y="16" width="3" height="3" fill="currentColor" stroke="none"/>' +
                '<line x1="14" y1="14" x2="17" y2="14"/><line x1="17" y1="14" x2="17" y2="17"/>' +
                '<line x1="17" y1="17" x2="21" y2="17"/><line x1="21" y1="17" x2="21" y2="21"/>' +
                '<line x1="14" y1="21" x2="17" y2="21"/><line x1="14" y1="17" x2="14" y2="21"/>' +
            '</svg></div><span>QR Code</span></a>';
}

function renderPicModalRequestedRow(){
    var wrap = document.getElementById('picModalActions');
    if(!wrap) return;
    wrap.innerHTML = '<a href="#" onclick="dzCancelRequest(this); return false;" class="modal-track-btn pending">Requested</a>';
}

/* Keep the main .rp-actions Track pill AND the topbar Track button in
   sync once tracking a public profile succeeds from the modal. */
function syncMainTrackBtnToTracked(){
    if(typeof window.dzSetFollowState === 'function') window.dzSetFollowState(<?php echo (int)$profile_id; ?>, true, true);
    var form = document.getElementById('rpTrackForm');
    if(form){
        form.innerHTML = '<div class="rp-rect-btn track has-chevron" onclick="openMrSheet(event)" role="button">' +
            '<span id="untrackMainBtn">Untrack</span><i class="ri-arrow-down-s-line" id="untrackChevron"></i></div>';
    }
    if(typeof syncTopbarTrack === 'function') syncTopbarTrack('tracked');
    var msg = document.getElementById('rpMessageBtn');   // only exists when the owner allows messages
    if(msg) msg.style.display = '';
}
function syncMainTrackBtnToPending(){ dzSetTrackState('pending'); }

/* Untrack done elsewhere (immersive modal) → put the whole page back to its "not tracking" look
   without a reload: main pill, topbar Track, profile-viewer row, Message button, vibe nudge. */
var _dzMsgOpenNow = <?php echo !empty($dz_msg_open) && (($dz_level ?? '') === 'public') ? 'true' : 'false'; ?>;
function syncMainTrackBtnToUntracked(){
    var isPriv = <?php echo $profile_is_private ? 'true' : 'false'; ?>;
    if(isPriv){
        dzSetTrackState('none');
    } else {
        var form = document.getElementById('rpTrackForm');
        if(form){
            var inp = form.querySelector('input[name="follow"],input[name="unfollow"]');
            while(inp){ inp.parentNode.removeChild(inp); inp = form.querySelector('input[name="follow"],input[name="unfollow"]'); }
            var old = form.querySelector('.rp-rect-btn.track');
            if(old && old.parentNode) old.parentNode.removeChild(old);
            var b = document.createElement('button');
            b.type = 'submit'; b.name = 'follow'; b.className = 'rp-rect-btn track'; b.textContent = 'Track';
            b.setAttribute('onclick', 'showSpinner(this);');
            form.appendChild(b);
        }
        var tb = document.getElementById('rpTopbar');
        if(tb){
            var wrap = tb.querySelector('.rp-tb-track-wrap');
            if(!wrap){
                wrap = document.createElement('div');
                wrap.className = 'rp-tb-track-wrap';
                tb.appendChild(wrap);
            }
            wrap.innerHTML = '<button type="button" class="rp-tb-track-btn" id="rpTopbarTrackBtn" data-private="0" data-target="<?php echo (int)$profile_id; ?>" onclick="dzTopbarTrack(this)">Track</button>';
            tb.classList.remove('no-track');
        }
        var w = document.getElementById('picModalActions');
        if(w) w.innerHTML = '<a href="#" onclick="modalTrackUser(\'follow\', this); return false;" class="modal-track-btn">Track</a>';
    }
    var msg = document.getElementById('rpMessageBtn');
    if(msg && !_dzMsgOpenNow) msg.style.display = 'none';
    Array.prototype.forEach.call(document.querySelectorAll('.dz-vbn'), function(el){ if(el.parentNode) el.parentNode.removeChild(el); });
}
/* Live Track / Untrack — same spinner as before, but the page is NOT refreshed.
   Only used on public profiles with nothing walled off (otherwise tracking unlocks content, so the page reloads). */
window._dzLiveTrack = <?php echo (($dz_level ?? 'public') === 'public' && empty($show_private_wall) && !$profile_is_private && empty($dz_view_as) && (int)$current_user > 0 && $current_user != $profile_id) ? 'true' : 'false'; ?>;
window._dzFollowing = <?php echo !empty($follow_check) ? 'true' : 'false'; ?>;
window._dzTrackers  = <?php echo (int)$followers_count; ?>;
function dzFmtCount(n){
    if(n >= 1000000) return (Math.floor(n/100000)/10).toString().replace(/\.0$/,'') + 'M';
    if(n >= 1000)    return (Math.floor(n/100)/10).toString().replace(/\.0$/,'') + 'K';
    return String(n);
}
function dzLiveFollow(btn, want, onFail){
    if(btn){
        if(btn.dataset && btn.dataset.busy === '1') return;
        dzHp(want ? 'medium' : 'light');
        if(btn.dataset) btn.dataset.busy = '1';
        btn._dzHTML = btn.innerHTML;
        var clr = getComputedStyle(btn).color;
        btn.style.minWidth = btn.offsetWidth + 'px';
        btn.disabled = true;   // also stops the surrounding <form> from submitting (no refresh)
        btn.innerHTML = '<span class="spinner-icon" style="color:' + clr + ';"><span></span><span></span><span></span><span></span></span>';
    }
    var spinT0 = Date.now();   // topbar Track keeps its bouncing bars on screen long enough to be seen, like the action button
    var spinHold = (btn && btn.classList && btn.classList.contains('rp-tb-track-btn')) ? 600 : 0;
    var fail = function(){
        if(btn){ btn.innerHTML = btn._dzHTML; btn.disabled = false; btn.style.pointerEvents = ''; btn.style.minWidth = ''; if(btn.dataset) btn.dataset.busy = ''; }
        if(typeof onFail === 'function') onFail();
        if(typeof picModalToast === 'function') picModalToast('Something went wrong');
    };
    fetch('profile.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        credentials: 'same-origin',
        body: 'action=toggle_follow&user_id=<?php echo (int)$profile_id; ?>'
    }).then(function(r){ return r.json(); }).then(function(d){
        if(!d || d.status !== 'ok') throw new Error('x');
        var wait = Math.max(0, spinHold - (Date.now() - spinT0));
        setTimeout(function(){ window.dzSyncPageTrack(!!d.following); }, wait);
    }).catch(fail);
}
/* the immersive modal's Track buttons for this profile owner follow the page */
function syncImmersiveTrack(following){
    Array.prototype.forEach.call(document.querySelectorAll('.ipv-track-btn[data-user="<?php echo (int)$profile_id; ?>"]'), function(b){
        b.textContent = following ? 'Untrack' : 'Track';
        if(b.classList.contains('ipv-topbar-track-btn')) b.classList.toggle('ipv-untrack', !!following);
    });
}
/* called by the immersive modal after it tracks / untracks the profile owner */
window.dzSyncPageTrack = function(following){
    try{
        if(typeof window.dzSetFollowState === 'function') window.dzSetFollowState(<?php echo (int)$profile_id; ?>, following, true);
        if(window._dzFollowing !== following){   // trackers counter follows the change, live
            window._dzFollowing = following;
            window._dzTrackers  = Math.max(0, (window._dzTrackers || 0) + (following ? 1 : -1));
            var cn = document.querySelector('.rp-stats-row .rp-stat .rp-stat-num');
            if(cn && !cn.classList.contains('blur')) cn.textContent = dzFmtCount(window._dzTrackers);
        }
        if(following){
            if(typeof renderPicModalTrackedRow === 'function') renderPicModalTrackedRow();
            if(typeof syncMainTrackBtnToTracked === 'function') syncMainTrackBtnToTracked();
        } else {
            syncMainTrackBtnToUntracked();
        }
    }catch(e){}
};

/* Share / Copy Link / QR */
function shareProfile(profileId){
    dzHp('light');
    if(typeof dzShcOpen === 'function'){ dzShcOpen(); return; }   // the share-card sheet
    var url = window.location.origin + '/profile.php?user_id=' + profileId;
    if(navigator.share){
        navigator.share({ url: url }).catch(function(){});
    } else {
        copyProfileLink(profileId);
    }
}
function copyProfileLink(profileId){
    var url = window.location.origin + '/profile.php?user_id=' + profileId;
    if(navigator.clipboard && navigator.clipboard.writeText){
        navigator.clipboard.writeText(url).then(function(){
            dzHp('success'); picModalToast('Link copied');
        }).catch(function(){
            dzHp('error'); picModalToast('Could not copy link');
        });
    } else {
        dzHp('error'); picModalToast('Could not copy link');
    }
}
function showQR(profileId){
    dzHp('light');
    /* open the Dezzme Code (QR) modal that lives in profile_modals.php — the same one the profile menu opens */
    if(typeof openProfileQr === 'function'){ openProfileQr(); return; }
    var url = window.location.origin + '/profile.php?user_id=' + profileId;
    var qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' + encodeURIComponent(url);
    window.open(qrUrl, '_blank');
}
</script>

<?php if($is_owner_view): ?>
<script>
/* ══════════════════════════════════════════════════════════════
   LIVE PROFILE UPDATES  (owner only)

   Whenever the profile modal saves something, this page updates ITSELF
   instantly — no reload:
     username · nickname · bio (+ Email button) · pronouns · user type ·
     activity vibe · profile type · profile picture

   It works with your profile modal exactly as it is: it quietly watches the
   modal's two save requests (ajax_update_user.php for text fields and the
   update_profile_pic upload) and, as soon as one succeeds, refreshes the
   matching part of the page.

   You can also call it by hand from anywhere:
       dzLive('bio', 'new bio text');   dzLive('pronouns', 'He/Him');
       dzLive('profile_pic', 'uploads/new.jpg');
   or fire an event:
       window.dispatchEvent(new CustomEvent('dz:profile-updated', { detail:{ field:'bio', value:'…' } }));
══════════════════════════════════════════════════════════════ */
(function(){
    var S = <?= json_encode($dz_live_state, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    function esc(s){
        return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
            return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
        });
    }

    /* the row of the ACTIVE account inside the linked-accounts modal */
    function activeKebabRow(){
        var row = document.querySelector('#userKebab .ukb-row.ukb-active');
        return row || null;
    }

    /* ── username ── */
    function renderUsername(){
        document.querySelectorAll('.rp-username').forEach(function(el){ el.textContent = S.username; });
        if(window.dzUsernameFit) window.dzUsernameFit();
        var tb = document.getElementById('rpTopbarUsername');
        if(tb){ var tt = tb.querySelector('.dz-un-t'); (tt || tb).textContent = S.username; }
        /* the badge is tied to the username: a changed name loses it (same rule as the server) */
        if(window.DZ_VB_NAME !== undefined && S.username !== window.DZ_VB_NAME){ document.querySelectorAll('.rp-username-row > .dz-vb, #rpTopbarUsername .dz-vb').forEach(function(b){ b.remove(); }); }
        document.title = S.username + ' | Dezzme';
        var ar = activeKebabRow();
        if(ar){ var n = ar.querySelector('.ukb-name'); if(n) n.textContent = S.username; }
        renderNick();   // @nickname, or @username while there is no nickname
    }

    /* ── nickname ── */
    function renderNick(){
        var el   = document.querySelector('.rp-nickname');
        var nick = (S.nickname && S.nickname !== S.username) ? S.nickname : S.username;   // no nickname → @username, same look + behaviour
        if(!nick){ if(el && el.parentNode) el.parentNode.removeChild(el); return; }
        if(!el){
            el = document.createElement('div');
            el.className = 'rp-nickname';
            el.setAttribute('role', 'button');
            el.setAttribute('title', 'Tap to copy');
            el.addEventListener('click', function(){ if(typeof dzCopyNickname === 'function') dzCopyNickname(el); });
            var row = document.querySelector('.rp-username-row');
            if(row && row.parentNode) row.parentNode.insertBefore(el, row.nextSibling);
        }
        el.setAttribute('data-nick', '@' + nick);
        el.textContent = '@' + nick;
    }

    /* ── pronouns / user type / activity vibe pills (+ the Posts stat that swaps with them) ── */
    function renderPills(){
        var header = document.querySelector('.rp-header');
        if(!header) return;
        var any = !!(S.pronouns || S.user_type || S.vibe);

        var old = document.getElementById('rpPills');
        if(old && old.parentNode) old.parentNode.removeChild(old);

        if(any){
            var d = document.createElement('div');
            d.className = 'rp-pills';
            d.id = 'rpPills';
            var h = '<div class="rp-pills-top">';
            if(S.pronouns)  h += '<div class="rp-pill pronouns"><span class="rp-pill-val">' + esc(S.pronouns) + '</span></div>';
            if(S.user_type) h += '<div class="rp-pill type"><span class="rp-pill-val">' + esc(S.user_type) + '</span></div>';
            h += '<button type="button" class="rp-post-count" onclick="dzGoToPosts()" aria-label="Go to posts"><b>' + esc(S.posts) + '</b> Posts</button></div>';
            if(S.vibe) h += '<div class="rp-pill active-hours"><span class="rp-pill-lbl">A.H :</span><span class="rp-pill-val">' + esc(S.vibe) + '</span></div>';
            d.innerHTML = h;
            header.parentNode.insertBefore(d, header.nextSibling);
        }

        var row   = document.querySelector('.rp-stats-row');
        var posts = document.getElementById('rpStatPosts');
        var pods  = document.getElementById('rpStatPods');
        if(any){
            if(posts && posts.parentNode) posts.parentNode.removeChild(posts);
            if(pods) pods.classList.remove('rp-stat-wide');
        } else {
            if(!posts && row){
                posts = document.createElement('div');
                posts.className = 'rp-stat';
                posts.id = 'rpStatPosts';
                posts.style.cursor = 'pointer';
                posts.setAttribute('role', 'button');
                posts.setAttribute('aria-label', 'Go to posts');
                posts.addEventListener('click', function(){ if(typeof dzGoToPosts === 'function') dzGoToPosts(); });
                posts.innerHTML = '<span class="rp-stat-num">' + esc(S.posts) + '</span><span class="rp-stat-lbl">Posts</span>';
                row.appendChild(posts);
            }
            if(pods) pods.classList.add('rp-stat-wide');
        }
    }

    /* ── profile type (grey text above the bio) ── */
    function renderPType(){
        var el = document.getElementById('rpProfileType');
        if(!el) return;
        el.textContent = S.ptype || '';
        el.classList.toggle('is-empty', !S.ptype);
        el.style.display = S.ptype ? '' : 'none';
    }

    /* ── bio: the server renders links / @mentions / "more" toggle and rebuilds the Email button ── */
    function applyBio(bioHtml, hasBio, emailHtml){
        var el = document.getElementById('rpBio');
        if(el){
            el.innerHTML = bioHtml;
            el.style.display = hasBio ? '' : 'none';
            if(window.dzBioFit) window.dzBioFit();
        }
        var actions = document.querySelector('.rp-actions');
        if(actions){
            var oldWrap = document.getElementById('rpEmailWrap');
            if(oldWrap && oldWrap.parentNode) oldWrap.parentNode.removeChild(oldWrap);
            actions.querySelectorAll(':scope > a.rp-rect-btn.email').forEach(function(a){ a.parentNode.removeChild(a); });
            if(emailHtml){
                var anchor = actions.querySelector('.rp-square-btn');
                if(anchor) anchor.insertAdjacentHTML('beforebegin', emailHtml);
                else actions.insertAdjacentHTML('beforeend', emailHtml);
            }
        }
    }
    function renderBio(value){
        fetch('profile.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            credentials: 'same-origin',
            body: 'dz_live=bio&value=' + encodeURIComponent(value)
        }).then(function(r){ return r.json(); }).then(function(d){
            if(d && d.status === 'ok') applyBio(d.bio_html, !!d.has_bio, d.email_html || '');
            else throw new Error('live bio failed');
        }).catch(function(){
            /* offline fallback: plain text so the page still reflects the change */
            applyBio(esc(value).replace(/\n/g, '<br>'), String(value).trim() !== '', null);
        });
    }

    /* ── profile picture: every copy on the page ── */
    function renderPic(url){
        if(!url) return;
        ['profilePic', 'modalImg', 'epCurrentPicImg'].forEach(function(id){
            var el = document.getElementById(id);
            if(el) el.src = url;
        });
        document.querySelectorAll('.nav-profile-pic').forEach(function(el){ el.src = url; });
        var ar = activeKebabRow();
        if(ar){ var a = ar.querySelector('.ukb-avatar'); if(a) a.src = url; }
    }

    /* ── the one entry point ── */
    window.dzLive = function(field, value){
        value = (value == null) ? '' : String(value);
        switch(field){
            case 'username':      S.username  = value; renderUsername(); break;
            case 'nickname':      S.nickname  = value; renderNick();      break;
            case 'bio':           renderBio(value);                       break;
            case 'pronouns':      S.pronouns  = value; renderPills();     break;
            case 'user_type':     S.user_type = value; renderPills();     break;
            case 'activity_vibe': S.vibe      = value; renderPills();     break;
            case 'profile_type':  S.ptype     = value; renderPType();     break;
            case 'profile_pic':   renderPic(value);                       break;
        }
    };

    /* custom event version */
    window.addEventListener('dz:profile-updated', function(e){
        var d = (e && e.detail) || {};
        if(d.field) window.dzLive(d.field, d.value);
    });

    /* watch the profile modal's saves (no change needed inside the modal) */
    var prevFetch = window.fetch;
    if(prevFetch){
        window.fetch = function(input, init){
            var p = prevFetch.apply(this, arguments);
            try {
                var url  = (typeof input === 'string') ? input : ((input && input.url) || '');
                var body = init && init.body;

                if(/ajax_update_user\.php/.test(url) && typeof body === 'string'){
                    var q = new URLSearchParams(body);
                    var field = q.get('field'), val = q.get('value') || '';
                    p.then(function(res){
                        return res.clone().json().then(function(d){
                            if(d && d.success) window.dzLive(field, val);
                        });
                    }).catch(function(){});
                }
                else if(typeof FormData !== 'undefined' && body instanceof FormData && body.get('action') === 'update_profile_pic'){
                    p.then(function(res){
                        return res.clone().json().then(function(d){
                            if(d && d.success && d.url) window.dzLive('profile_pic', d.url);
                        });
                    }).catch(function(){});
                }
            } catch(err){}
            return p;
        };
    }
})();
</script>
<?php endif; ?>



<?php if(!empty($dz_view_as)): ?>
<div id="dzVaToast">Preview only · tap ✕ to exit</div>
<script>
/* VIEW AS — look-only. Scrolling, tabs, sort pills, bio "more" and the Email reveal work; every other click is stopped
   and nothing is ever sent to the server (the real user would otherwise "track" or "message" themselves). */
(function(){
    var ALLOW = '#dzVaExit, .dz-va-seg a, .rp-tab-btn, .rp-bio-toggle, #rp-bio-more, #rp-bio-less, .g-pill, .rp-email-sq, #rpEmailBtn, #dzCtaPill, #dzCtaWarn .cw-cancel, #dzCtaWarn .cw-continue';
    var tt = null;
    function toast(){ var t = document.getElementById('dzVaToast'); if(!t) return; t.classList.add('show'); clearTimeout(tt); tt = setTimeout(function(){ t.classList.remove('show'); }, 1600); }
    document.addEventListener('click', function(e){
        var t = e.target;
        if(t && t.closest && t.closest(ALLOW)) return;
        e.preventDefault(); e.stopPropagation(); if(e.stopImmediatePropagation) e.stopImmediatePropagation();
        toast();
    }, true);
    document.addEventListener('submit', function(e){ e.preventDefault(); e.stopPropagation(); toast(); }, true);
    var _f = window.fetch;
    if(_f) window.fetch = function(u, o){
        var m = String((o && o.method) || (u && u.method) || 'GET').toUpperCase();
        if(m !== 'GET' && m !== 'HEAD') return Promise.resolve(new Response('{"status":"error","msg":"preview"}', { headers:{ 'Content-Type':'application/json' } }));
        return _f.apply(this, arguments);
    };
    var _s = XMLHttpRequest.prototype.send, _o = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function(m){ this.__dzm = String(m || 'GET').toUpperCase(); return _o.apply(this, arguments); };
    XMLHttpRequest.prototype.send = function(){ if(this.__dzm && this.__dzm !== 'GET' && this.__dzm !== 'HEAD') return; return _s.apply(this, arguments); };
    if(navigator.sendBeacon) navigator.sendBeacon = function(){ return true; };
})();
</script>
<?php endif; ?>
<?php /* ?report=1 (e.g. from the shop page) → open the Report modal straight on "Why are you reporting this user?" */ ?>
<?php if(isset($_GET['report']) && $current_user != $profile_id && (int)$current_user > 0): ?>
<script>window.addEventListener('load', function(){
    if (typeof openReportModal === 'function') openReportModal({userId: <?php echo (int)$profile_id; ?>});
    try { var u = new URL(location.href); u.searchParams.delete('report'); history.replaceState(null, '', u.pathname + u.search + u.hash); } catch(e){}
});</script>
<?php endif; ?>
<?php /* ═══ "Remove Untracked button" (own kebab) — hides the Untracked button in your action row; the same row brings it back ═══ */ ?>
<?php if ((int)$current_user === (int)$profile_id && (int)$current_user > 0): ?>
<script>
(function(){
    var KEY = 'dzHideUntracked_<?php echo (int)$current_user; ?>';
    var DB = <?php echo $dz_hide_untr === null ? 'null' : $dz_hide_untr; ?>;
    function on(){ if(DB !== null) return DB === 1; try { return localStorage.getItem(KEY) === '1'; } catch(e){ return false; } }
    function apply(){
        document.documentElement.classList.toggle('dz-hide-untracked', on());
        var l = document.getElementById('pkbmUntrackedLbl'); if(l) l.textContent = on() ? 'Show Untracked button' : 'Remove Untracked button';
        var i = document.getElementById('pkbmUntrackedIc'); if(i) i.className = on() ? 'ri-eye-line' : 'ri-eye-off-line';
    }
    window.dzToggleUntrackedBtn = function(){
        var want = on() ? 0 : 1;
        try { if(want) localStorage.setItem(KEY, '1'); else localStorage.removeItem(KEY); } catch(e){}
        if(DB !== null) DB = want;
        apply();
        if(typeof gToast === 'function') gToast(want ? 'Untracked button removed — open the menu to bring it back' : 'Untracked button is back');
        var fd = new FormData(); fd.append('untr_hide', want);
        fetch('profile.php', { method:'POST', body:fd, credentials:'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(d){ if(d && d.status === 'ok' && DB === null){ DB = want; } })
            .catch(function(){});
    };
    apply(); document.addEventListener('DOMContentLoaded', apply); window.addEventListener('load', apply);
})();
</script>
<?php endif; ?>
<?php /* ═══ PROFILE CTA PILL ═══ shown to visitors (and in View As) — never to the owner on their own page */ ?>
<?php if (!empty($dz_cta)):
    $dz_cta_c   = dz_cta_colors()[$dz_cta['color']] ?? dz_cta_colors()['blue'];
    $dz_cta_img = !empty($dz_cta['pic']) ? $dz_cta['pic'] : $profilePic;
    $dz_cta_host = (string)parse_url($dz_cta['url'], PHP_URL_HOST);
    if (stripos($dz_cta_host, 'www.') === 0) $dz_cta_host = substr($dz_cta_host, 4);
?>
<a id="dzCtaPill" class="dz-cta cc-<?php echo isset(dz_cta_colors()[$dz_cta['color']]) ? $dz_cta['color'] : 'blue'; ?>" href="<?php echo htmlspecialchars($dz_cta['url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer nofollow"
   data-host="<?php echo htmlspecialchars($dz_cta_host, ENT_QUOTES, 'UTF-8'); ?>">
    <img class="dz-cta-pic" src="<?php echo htmlspecialchars($dz_cta_img); ?>" alt="" draggable="false" onerror="this.onerror=null;this.classList.add('dz-hide');">
    <span class="dz-cta-t"><?php echo htmlspecialchars($dz_cta['label']); ?></span>
    <i class="ri-close-line dz-cta-go dz-cta-x" role="button" aria-label="Hide this for now" title="Hide"></i>
</a>

<!-- "You're leaving Dezzme" — shown before the CTA link opens -->
<div id="dzCtaWarn" role="dialog" aria-modal="true" aria-labelledby="dzCtaWarnT">
  <div class="cw-box">
    <div class="cw-ic"><i class="ri-shield-keyhole-line"></i></div>
    <div class="cw-t" id="dzCtaWarnT">You're about to leave Dezzme</div>
    <p class="cw-p">Only continue if you trust <b><?php echo htmlspecialchars((string)($user['username'] ?? 'this person')); ?></b>. Dezzme can't see or control what's on other websites, so the choice to open this link is yours.</p>
    <ul class="cw-ul">
      <li>Look through their profile first — their posts, who tracks them and how long they've been around.</li>
      <li>Never share passwords, verification codes or card details with a site you got to from a link.</li>
      <li>Be careful if the page asks you to log in, pay, send money or install something.</li>
      <li>If this link looks like a scam or could cause harm, <b>report this user</b> and Dezzme will look into it.</li>
    </ul>
    <div class="cw-row">
      <button type="button" class="cw-cancel" onclick="dzCtaWarnClose()">Cancel</button>
      <button type="button" class="cw-continue" onclick="dzCtaGo()">Continue</button>
    </div>
    <button type="button" class="cw-report" onclick="dzCtaReport()"><i class="ri-flag-line"></i> Report this user</button>
  </div>
</div>
<?php endif; ?>
<?php if (!empty($dz_cta)): ?>
<script>
(function(){
    var pill = document.getElementById('dzCtaPill'), warn = document.getElementById('dzCtaWarn');
    if(!pill) return;
    document.body.classList.add('dz-has-cta');
    var KEY = 'dzCtaPos', dx = 0, dy = 0, sx = 0, sy = 0, bx = 0, by = 0, pid = null, down = false, moved = false, swallow = false, onX = false;
    var lim = { x0:0, x1:0, y0:0, y1:0 };
    var useDefault = true;   /* until the visitor drags it: it starts directly under the tab buttons ... */
    var settled = false;     /* ... and once the page is first scrolled (or ~1.3s after load) it stays EXACTLY where it is on screen until the visitor moves it */

    function defaultDy(){
        if(window.innerWidth <= 768) return 0;                 /* responsive: rests directly above the bottom nav bar */
        var tabs = document.querySelector('.rp-tabs');
        if(!tabs) return 0;
        var r = tabs.getBoundingClientRect(), vh = window.innerHeight;
        var b = parseFloat(getComputedStyle(pill).bottom) || 14, h = pill.offsetHeight;
        return (r.bottom + 8) - (vh - b - h);                  /* pill top = tab bar bottom + 8px */
    }

    /* centre the pill on the profile column (not the whole window) and sit above a fixed bottom bar */
    function place(){
        var root = document.documentElement, vw = window.innerWidth;
        var col = document.querySelector('.rp-tabs') || document.querySelector('.rp-pills') || document.querySelector('.rp-header');
        var cx = vw / 2, w = Math.min(480, vw - 16);
        if(col){
            var r = col.getBoundingClientRect();
            if(r.width > 120){ cx = r.left + r.width / 2; w = Math.min(480, r.width - 16); }
        }
        root.style.setProperty('--dz-cta-cx', cx + 'px');
        root.style.setProperty('--dz-cta-w', w + 'px');
        var off = 0, els = document.querySelectorAll('nav, .sidebar, .bottom-nav, .mobile-nav, #bottomNav, .nav-bottom');
        Array.prototype.forEach.call(els, function(el){
            if(el.id === 'rpTopbar') return;
            var cs = getComputedStyle(el), rr = el.getBoundingClientRect();
            if(cs.position === 'fixed' && cs.display !== 'none' && rr.height > 0 && rr.height < 160 && rr.bottom >= window.innerHeight - 2 && rr.top > window.innerHeight / 2) off = Math.max(off, window.innerHeight - rr.top);
        });
        root.style.setProperty('--dz-cta-b', off + 'px');
        apply(dx, (useDefault && !settled) ? defaultDy() : dy);
    }
    function limits(){
        var h = pill.offsetHeight, vw = window.innerWidth, vh = window.innerHeight;
        var cs = getComputedStyle(pill), b = parseFloat(cs.bottom) || 14, w = pill.offsetWidth;
        var cx = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--dz-cta-cx')) || vw / 2;
        lim.y0 = -(vh - b - h - 64);  lim.y1 = Math.max(0, b - 8);              // under the top bar … down to the screen edge
        lim.x0 = (8 + w / 2) - cx;    lim.x1 = (vw - 8 - w / 2) - cx;            // never past the screen sides
    }
    function apply(x, y){
        limits();
        dx = Math.max(lim.x0, Math.min(lim.x1, x)); dy = Math.max(lim.y0, Math.min(lim.y1, y));
        pill.style.setProperty('--cta-dx', dx + 'px'); pill.style.setProperty('--cta-dy', dy + 'px');
    }
    function ready(){ pill.classList.add('rdy'); }
    pill.addEventListener('animationend', ready);
    setTimeout(ready, 1300);
    try { var sv = (window.innerWidth <= 768) ? null : JSON.parse(localStorage.getItem(KEY) || 'null'); if(sv){ dx = +sv.x || 0; dy = +sv.y || 0; useDefault = false; } } catch(e){}
    /* it does NOT follow the tabs while scrolling — it only moves when the visitor drags it. The first scroll (or 1.3s after load) fixes its starting spot under the tabs. */
    window.addEventListener('scroll', function(){ settled = true; }, { passive:true, once:true });
    setTimeout(function(){ settled = true; }, 1300);
    place(); window.addEventListener('resize', place); window.addEventListener('load', place);
    setTimeout(place, 400); setTimeout(place, 1200);

    /* drag in any direction with a finger or the mouse */
    pill.addEventListener('pointerdown', function(e){
        if(e.pointerType === 'mouse' && e.button !== 0) return;
        if(!pill.classList.contains('rdy')) pill.classList.add('rdy');
        onX = !!(e.target.closest && e.target.closest('.dz-cta-x'));
        down = true; moved = false; pid = e.pointerId; sx = e.clientX; sy = e.clientY; bx = dx; by = dy;
    });
    pill.addEventListener('pointermove', function(e){
        if(!down || e.pointerId !== pid) return;
        var mx = e.clientX - sx, my = e.clientY - sy;
        if(!moved && Math.abs(mx) < 6 && Math.abs(my) < 6) return;
        if(!moved){ moved = true; useDefault = false; pill.style.transition = ''; pill.classList.add('drag'); try { pill.setPointerCapture(pid); } catch(_){} }
        apply(bx + mx, by + my);
    });
    function end(e){
        if(!down || (e && e.pointerId !== pid)) return;
        down = false; pill.classList.remove('drag');
        if(moved){ swallow = true; setTimeout(function(){ swallow = false; }, 120); try { if(window.innerWidth > 768) localStorage.setItem(KEY, JSON.stringify({ x:dx, y:dy })); } catch(_){} }
    }
    pill.addEventListener('pointerup', end); pill.addEventListener('pointercancel', end);

    /* X hides the pill for this visit only — it is back the next time the page loads */
    function hide(){ pill.classList.add('gone'); document.body.classList.remove('dz-has-cta'); }
    window.addEventListener('pageshow', function(e){ if(e.persisted){ pill.classList.remove('gone'); document.body.classList.add('dz-has-cta'); place(); } });

    /* a tap (not a drag) shows the "you're leaving Dezzme" notice first */
    pill.addEventListener('click', function(e){
        e.preventDefault();
        if(swallow || moved){ moved = false; return; }
        if(onX || (e.target.closest && e.target.closest('.dz-cta-x'))){ onX = false; e.stopPropagation(); hide(); return; }
        warn.classList.add('open'); document.body.style.overflow = 'hidden';
    });
    window.dzCtaWarnClose = function(){ warn.classList.remove('open'); document.body.style.overflow = ''; };
    window.dzCtaGo = function(){
        dzCtaWarnClose();
        window.open(pill.href, '_blank', 'noopener,noreferrer');
    };
    window.dzCtaReport = function(){
        dzCtaWarnClose();
        if(typeof openReportModal === 'function') openReportModal({ userId: <?php echo (int)$profile_id; ?> });
    };
    warn.addEventListener('click', function(e){ if(e.target === warn) dzCtaWarnClose(); });
    document.addEventListener('keydown', function(e){ if(e.key === 'Escape' && warn.classList.contains('open')) dzCtaWarnClose(); });
})();
</script>
<?php endif; ?>

<?php if ((int)$current_user === (int)$profile_id && (int)$current_user > 0): ?>
<?php $cta_sel = (string)($dz_cta_own['color'] ?? 'blue'); $dz_cta_ok = in_array($dz_level, ['public', 'limited'], true); $dz_cta_ownpic = (string)($dz_cta_own['pic'] ?? ''); ?>
<?php endif; ?>

<?php
/* ═══════ VISITOR TOOLS (first-visit note · guest book · legacy contact · translate · tracking since) ═══════ */
$fvx_pid   = (int)$profile_id;
$fvx_owner = ((int)$current_user === $fvx_pid && (int)$current_user > 0);
$fvx_va    = !empty($dz_view_as);
$fvx_since = ''; $fvx_gb = []; $fvx_mine = null;
try {
    if (isset($conn) && $conn && $fvx_pid > 0) {
        $gr = $conn->query("SELECT g.message, u.username, u.profile_pic FROM guestbook_entries g JOIN users u ON u.id = g.author_id WHERE g.owner_id = $fvx_pid AND g.status = 'approved' ORDER BY g.created_at DESC LIMIT 40");
        if ($gr instanceof mysqli_result) while ($grow = $gr->fetch_assoc()) $fvx_gb[] = ['m' => $grow['message'], 'u' => $grow['username'], 'p' => $grow['profile_pic']];
        if ((int)$current_user > 0 && !$fvx_owner) {
            $mr = $conn->query("SELECT message, status FROM guestbook_entries WHERE owner_id = $fvx_pid AND author_id = " . (int)$current_user . " LIMIT 1");
            $fvx_mine = ($mr instanceof mysqli_result) ? ($mr->fetch_assoc() ?: null) : null;
            $sr = $conn->query("SELECT created_at FROM followers WHERE follower_id = " . (int)$current_user . " AND following_id = $fvx_pid LIMIT 1");
            $srow = ($sr instanceof mysqli_result) ? $sr->fetch_assoc() : null;
            if ($srow && !empty($srow['created_at'])) $fvx_since = date('Y-m-d', strtotime($srow['created_at']));
        }
    }
} catch (Throwable $e) {}
$fvx_uname = (string)($user['username'] ?? '');
?>

<div id="fvxGT" class="notranslate fvx-gt"></div>


<script>
/* VIEW COUNTS — a grid card counts as seen when >= 60% of it is on screen for 1 second (same as the feed). Reported in batches; the numbers on the cards update from the answer. Ads carry no count, so they are never watched. */
(function(){
  if(!('IntersectionObserver' in window)) return;
  var timers={}, sent={}, queue=[];
  function fmt(n){ n=+n||0; if(n>=1e6) return (n/1e6).toFixed(1).replace(/\.0$/,'')+'M'; if(n>=1e3) return (n/1e3).toFixed(1).replace(/\.0$/,'')+'K'; return String(n); }
  var io=new IntersectionObserver(function(es){
    es.forEach(function(e){
      var id=e.target.getAttribute('data-id'); if(!id||sent[id]) return;
      if(e.isIntersecting && e.intersectionRatio>=.6){
        if(!timers[id]) timers[id]=setTimeout(function(){ delete timers[id]; sent[id]=1; queue.push(id); },1000);
      } else if(timers[id]){ clearTimeout(timers[id]); delete timers[id]; }
    });
  },{threshold:[0,.6,1]});
  function watch(){
    document.querySelectorAll('.grid .meme-card[data-id]').forEach(function(c){
      if(c.__dzVw || !c.querySelector('.card-views')) return;
      c.__dzVw=1; io.observe(c);
    });
  }
  function apply(map){
    Object.keys(map||{}).forEach(function(id){
      document.querySelectorAll('.meme-card[data-id="'+id+'"] .card-views span').forEach(function(sp){ sp.textContent=fmt(map[id]); });
    });
  }
  function flush(beacon){
    if(!queue.length) return;
    var body='dz_pf_views=1&ids='+encodeURIComponent(queue.splice(0,50).join(','));
    try{
      if(beacon && navigator.sendBeacon) navigator.sendBeacon('profile.php', new Blob([body],{type:'application/x-www-form-urlencoded'}));
      else fetch('profile.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body,keepalive:true,credentials:'same-origin'})
        .then(function(r){ return r.json(); }).then(function(d){ if(d && d.views) apply(d.views); }).catch(function(){});
    }catch(err){}
  }
  watch();
  try{ new MutationObserver(watch).observe(document.body,{childList:true,subtree:true}); }catch(e){}
  setInterval(function(){ flush(false); },4000);
  document.addEventListener('visibilitychange',function(){ if(document.hidden) flush(true); });
  window.addEventListener('pagehide',function(){ flush(true); });
})();
</script>
<!-- profile.php: total server time <?= (int)round((microtime(true)-($_SERVER['REQUEST_TIME_FLOAT']??microtime(true)))*1000) ?> ms -->
<?php /* all the modals / sheets / viewers live in their own file now — it is included here, at the page's top level, so it sees every variable of this page exactly as if it were pasted here */
$dz_loc_hide_own = !empty($dz_loc_hidden) && $current_user == $profile_id;
include __DIR__ . '/profile_modals.php'; ?>
<?php if(is_file(__DIR__ . '/tv_nav.php')) include __DIR__ . '/tv_nav.php'; ?>
<script>
/* swipe right to go back — the page follows the finger; past a third of the screen (or a flick) it slides out and goes back */
(function(){
  var body = document.body, st = null, busy = false;
  var FALLBACK = 'home.php';                      /* used when there is no page to go back to */

  function overlayOpen(){
    if(document.body.style.overflow === 'hidden' || document.documentElement.style.overflow === 'hidden') return true;   /* any modal / sheet that locks scrolling */
    return !!document.querySelector('#cardCaptionModal.ccm-open, #memeModal.ccm-open, #ipvOverlay.ipv-open, #ipvLikersOverlay.ipv-open, #hlModal.open, .share-modal-panel.show, .pkbm-overlay.show, .pkbm-overlay.open, .am-modal.open, .am-modal.show');
  }
  function inHScroller(el){
    while(el && el !== document.body){
      var cs = getComputedStyle(el);
      if((cs.overflowX === 'auto' || cs.overflowX === 'scroll') && el.scrollWidth > el.clientWidth + 2) return true;
      el = el.parentElement;
    }
    return false;
  }
  function reset(){
    body.style.transition = ''; body.style.transform = '';
    body.style.boxShadow = ''; body.style.willChange = '';
    busy = false;
  }
  function goBack(){
    if(history.length > 1) history.back(); else location.href = FALLBACK;
  }

  document.addEventListener('touchstart', function(e){
    st = null;
    if(busy || e.touches.length !== 1 || overlayOpen()) return;
    if(e.target.closest && e.target.closest('input, textarea, [contenteditable="true"]')) return;
    if(inHScroller(e.target)) return;
    if(e.target.closest && e.target.closest('#rpPager')) return;   /* the tab grid has its own left/right swipe — leave it alone */
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
      body.style.transition = 'none';
      body.style.willChange = 'transform';
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
      setTimeout(reset, 8000);                      /* safety net */
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
/* Videos tab: open the long form modal right here. If the modal files are missing, say so instead of leaving the page. */
if (typeof window.dzOpenLf !== 'function') window.dzOpenLf = function(id, ev){
    if (ev) ev.preventDefault();
    var m = 'Long form modal not loaded. Put profile_lf_embed.php and longform_modal.php in the same folder as profile.php.';
    try { console.error(m); } catch (e) {}
    alert(m);
    return false;
};
</script>
<?php if (function_exists('lf_embed_render')) { lf_embed_render(); } else { echo '<!-- lf embed NOT loaded: profile_lf_embed.php or longform_modal.php missing next to profile.php -->'; } ?>
</body>
</html>