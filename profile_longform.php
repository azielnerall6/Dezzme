<?php
session_start();
include 'config.php';

/* ══════════════════════════════════════════════════════════════════
   TIMEZONE  (Lagos = UTC+1, no daylight saving) — PHP and MySQL agree
   ══════════════════════════════════════════════════════════════════ */
date_default_timezone_set('Africa/Lagos');
$conn->query("SET time_zone = '+01:00'");

/* ══════════════════════════════════════════════════════════════════
   SETTINGS — change these numbers to change the rules
   ══════════════════════════════════════════════════════════════════ */
define('LF_MIN_DURATION',   60);                      /* 1 minute, in seconds         */
define('LF_MAX_VIDEO_BYTES', 2 * 1024 * 1024 * 1024); /* 2 GB (PHP limits can be lower) */
define('LF_MAX_THUMB_BYTES', 8 * 1024 * 1024);        /* 8 MB thumbnail               */
define('LF_MAX_TITLE',      100);
define('LF_MAX_DESC',       5000);
define('LF_PAGE_SIZE',      10);                      /* posts loaded per scroll      */
define('LF_MAX_HASHTAGS',   30);
define('LF_MAX_REPORT_DETAILS', 500);
define('LF_VIDEO_DIR',      'uploads/longform/videos/');
define('LF_THUMB_DIR',      'uploads/longform/thumbs/');

/* Reasons shown in the Report step (key => label saved in longform_reports) */
$lf_report_reasons = [
    'spam'      => 'Spam or misleading',
    'sexual'    => 'Nudity or sexual content',
    'violence'  => 'Violent or dangerous',
    'hate'      => 'Hate or harassment',
    'copyright' => 'Copyright infringement',
    'other'     => 'Something else',
];

/* ══════════════════════════════════════════════════════════════════
   AUTH + WHOSE PAGE IS THIS
   ══════════════════════════════════════════════════════════════════ */
$current_user = (int)($_SESSION['user_id'] ?? 0);
if (!$current_user) { header("Location: login.php"); exit(); }

$profile_id = (isset($_GET['user_id']) && (int)$_GET['user_id'] > 0)
    ? (int)$_GET['user_id']
    : $current_user;
$is_own_profile = ($profile_id === $current_user);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

/* ══════════════════════════════════════════════════════════════════
   TABLES — created automatically from longform_tables.sql (once per
   login session, every statement is CREATE TABLE IF NOT EXISTS so it
   is always safe). You can also just import that file by hand.
   ══════════════════════════════════════════════════════════════════ */
if (empty($_SESSION['lf_schema_v1'])) {
    try {
        $sql_file = __DIR__ . '/longform_tables.sql';
        if (is_readable($sql_file)) {
            $sql = (string)file_get_contents($sql_file);
            $sql = preg_replace('~^\s*--.*$~m', '', $sql);
            foreach (explode(';', $sql) as $statement) {
                $statement = trim($statement);
                if ($statement !== '') { $conn->query($statement); }
            }
            $_SESSION['lf_schema_v1'] = 1;
        }
    } catch (Throwable $e) { /* import longform_tables.sql manually if this ever fails */ }
}

/* ══════════════════════════════════════════════════════════════════
   TABLE UPDATE (v2) — adds the "hide dislike / save / share count"
   switches to longform_posts. Only adds a column that is missing, so
   it is safe to run again and again (once per login session).
   If it ever fails, run this by hand instead:
     ALTER TABLE longform_posts
       ADD COLUMN hide_dislike_count TINYINT(1) NOT NULL DEFAULT 0,
       ADD COLUMN hide_save_count    TINYINT(1) NOT NULL DEFAULT 0,
       ADD COLUMN hide_share_count   TINYINT(1) NOT NULL DEFAULT 0;
   ══════════════════════════════════════════════════════════════════ */
if (empty($_SESSION['lf_schema_v2'])) {
    $lf_v2_ok = true;
    try {
        foreach (['hide_dislike_count', 'hide_save_count', 'hide_share_count'] as $lf_col) {
            $chk = $conn->query("SHOW COLUMNS FROM longform_posts LIKE '" . $lf_col . "'");
            if ($chk === false) { $lf_v2_ok = false; continue; }
            if ($chk->num_rows === 0) {
                $conn->query("ALTER TABLE longform_posts ADD COLUMN `" . $lf_col . "` TINYINT(1) NOT NULL DEFAULT 0");
            }
        }
    } catch (Throwable $e) { $lf_v2_ok = false; }
    if ($lf_v2_ok) { $_SESSION['lf_schema_v2'] = 1; }
}

/* ══════════════════════════════════════════════════════════════════
   PROFILE OWNER
   ══════════════════════════════════════════════════════════════════ */
$stmt = $conn->prepare("SELECT id, username, profile_pic FROM users WHERE id=? LIMIT 1");
$stmt->bind_param("i", $profile_id);
$stmt->execute();
$owner = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$owner) { header("Location: 404.php"); exit(); }

/* ══════════════════════════════════════════════════════════════════
   HELPERS
   ══════════════════════════════════════════════════════════════════ */
function lf_json($data) {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

/* "512M" / "2G" / "128K" from php.ini -> bytes */
function lf_ini_bytes($val) {
    $val = trim((string)$val);
    if ($val === '') return 0;
    $last = strtolower(substr($val, -1));
    $num  = (float)$val;
    switch ($last) {
        case 'g': $num *= 1024;   /* fall through */
        case 'm': $num *= 1024;   /* fall through */
        case 'k': $num *= 1024;
    }
    return (int)$num;
}

function lf_fmt_bytes($b) {
    $b = (float)$b;
    if ($b >= 1073741824) return round($b / 1073741824, 1) . ' GB';
    if ($b >= 1048576)    return round($b / 1048576, 1) . ' MB';
    if ($b >= 1024)       return round($b / 1024) . ' KB';
    return (int)$b . ' B';
}

function lf_fmt_duration($s) {
    $s = max(0, (int)$s);
    $h = intdiv($s, 3600);
    $m = intdiv($s % 3600, 60);
    $sec = $s % 60;
    return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $sec) : sprintf('%d:%02d', $m, $sec);
}

/* "1 minute" / "10 minutes" — built from LF_MIN_DURATION so text always matches the rule */
function lf_min_label() {
    $m = LF_MIN_DURATION / 60;
    if ($m >= 1 && floor($m) == $m) { return (int)$m . ' minute' . ($m == 1 ? '' : 's'); }
    return LF_MIN_DURATION . ' seconds';
}

function lf_fmt_count($n) {
    $n = (int)$n;
    if ($n >= 1000000000) { $v = $n / 1000000000; return (($v >= 10) ? round($v) : round($v, 1)) . 'B'; }
    if ($n >= 1000000)    { $v = $n / 1000000;    return (($v >= 10) ? round($v) : round($v, 1)) . 'M'; }
    if ($n >= 1000)       { $v = $n / 1000;       return (($v >= 10) ? round($v) : round($v, 1)) . 'K'; }
    return (string)$n;
}

function lf_time_ago($ts_str) {
    $t = strtotime((string)$ts_str);
    if (!$t) return '';
    $d = max(0, time() - $t);
    if ($d < 60) return 'Just now';
    $units = [['year', 31536000], ['month', 2592000], ['week', 604800], ['day', 86400], ['hour', 3600], ['minute', 60]];
    foreach ($units as $u) {
        if ($d >= $u[1]) {
            $n = (int)floor($d / $u[1]);
            return $n . ' ' . $u[0] . ($n > 1 ? 's' : '') . ' ago';
        }
    }
    return 'Just now';
}

/* Only ever delete files that live inside our own long form folders */
function lf_safe_unlink($path) {
    $path = (string)$path;
    if ($path === '' || strpos($path, '..') !== false) return;
    if (strpos($path, 'uploads/longform/') !== 0) return;
    if (is_file($path)) { @unlink($path); }
}

function lf_upload_error_message($code) {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE: return 'That file is larger than this server allows.';
        case UPLOAD_ERR_PARTIAL:   return 'The upload was interrupted. Please try again.';
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE: return 'The server could not store your upload. Please try again later.';
        default: return 'The upload failed. Please try again.';
    }
}

/* Real video length from the server itself, if ffprobe is installed.
   Returns null when ffprobe is not available. */
function lf_probe_duration($path) {
    if (!function_exists('shell_exec')) return null;
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (in_array('shell_exec', $disabled, true)) return null;
    $out = @shell_exec('ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($path) . ' 2>&1');
    if ($out === null) return null;
    $out = trim((string)$out);
    return is_numeric($out) ? (float)$out : null;
}

/* Pull #hashtags out of a description (lower-case, unique, no # sign).
   A # glued to a word or sitting inside a URL is NOT a hashtag. */
function lf_extract_hashtags($text) {
    preg_match_all('/(?<![\p{L}\p{N}_\/])#([\p{L}\p{N}_]{1,50})/u', (string)$text, $m);
    $seen = [];
    foreach ($m[1] as $tag) {
        $tag = mb_strtolower($tag, 'UTF-8');
        $seen[$tag] = true;
        if (count($seen) >= LF_MAX_HASHTAGS) break;
    }
    return array_keys($seen);
}

/* Description -> safe HTML with links and #hashtags.
   Used by the long form modal (longform_modal.php) for the description sheet. */
function lf_format_description($text) {
    $text = (string)$text;
    $esc  = function ($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
    $re   = '~(https?://[^\s]+|www\.[^\s]+)|(^|[^\p{L}\p{N}_/])(#[\p{L}\p{N}_]+)~u';
    if (!preg_match_all($re, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        return nl2br($esc($text));
    }
    $out = ''; $last = 0;
    foreach ($matches as $m) {
        $start = $m[0][1];
        $out  .= $esc(substr($text, $last, $start - $last));
        if (isset($m[1]) && $m[1][1] >= 0 && $m[1][0] !== '') {
            $url = $m[1][0]; $trail = '';
            if (preg_match('~[.,;:!?)\]}\'"]+$~', $url, $tm)) {
                $trail = $tm[0];
                $url   = substr($url, 0, -strlen($trail));
            }
            $href = preg_match('~^https?://~i', $url) ? $url : 'https://' . $url;
            $out .= '<a class="lf-link" href="' . $esc($href) . '" target="_blank" rel="noopener noreferrer nofollow ugc">' . $esc($url) . '</a>' . $esc($trail);
        } else {
            $out .= $esc($m[2][0]) . '<span class="lf-tag" data-tag="' . $esc(mb_strtolower(substr($m[3][0], 1), 'UTF-8')) . '">' . $esc($m[3][0]) . '</span>';
        }
        $last = $start + strlen($m[0][0]);
    }
    $out .= $esc(substr($text, $last));
    return nl2br($out);
}

/* A published post's id + owner, or null */
function lf_get_post($conn, $post_id) {
    $stmt = $conn->prepare("SELECT id, user_id FROM longform_posts WHERE id=? AND status='published' LIMIT 1");
    $stmt->bind_param("i", $post_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/* One page of posts for the grid (plus the viewer's watch progress + saved state) */
function lf_fetch_posts($conn, $profile_id, $viewer_id, $limit, $offset) {
    $stmt = $conn->prepare("
        SELECT p.id, p.user_id, p.title, p.video_path, p.thumbnail_path, p.duration_seconds, p.views_count, p.created_at,
               wp.progress_seconds,
               (SELECT COUNT(*) FROM longform_post_saves sv WHERE sv.post_id = p.id AND sv.user_id = ?) AS is_saved
        FROM longform_posts p
        LEFT JOIN longform_watch_progress wp ON wp.post_id = p.id AND wp.user_id = ?
        WHERE p.user_id = ? AND p.status = 'published'
        ORDER BY p.created_at DESC, p.id DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->bind_param("iiiii", $viewer_id, $viewer_id, $profile_id, $limit, $offset);
    $stmt->execute();
    $res  = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) { $rows[] = $r; }
    $stmt->close();
    return $rows;
}

/* One page of the CURRENT user's saved posts (most recently saved first), from any creator.
   It only ever uses the logged-in user's id, so nobody can read another person's saved list. */
function lf_fetch_saved($conn, $viewer_id, $limit, $offset) {
    $stmt = $conn->prepare("
        SELECT p.id, p.user_id, p.title, p.video_path, p.thumbnail_path, p.duration_seconds, p.views_count, p.created_at,
               wp.progress_seconds, 1 AS is_saved,
               u.username AS owner_username, u.profile_pic AS owner_profile_pic
        FROM longform_post_saves sv
        INNER JOIN longform_posts p ON p.id = sv.post_id AND p.status = 'published'
        INNER JOIN users u ON u.id = p.user_id
        LEFT JOIN longform_watch_progress wp ON wp.post_id = p.id AND wp.user_id = ?
        WHERE sv.user_id = ?
        ORDER BY sv.id DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->bind_param("iiii", $viewer_id, $viewer_id, $limit, $offset);
    $stmt->execute();
    $res  = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) { $rows[] = $r; }
    $stmt->close();
    return $rows;
}

/* One grid card. Layout: profile pic + title + username + time sit ABOVE
   the thumbnail; the thumbnail carries the duration badge and the red
   watch-progress bar. Hold / hover the thumbnail for 2 seconds and the
   video preview plays (see the PREVIEW section in the script). */
function lf_render_card($p, $owner, $is_own, $profile_id, $ctx = 'grid') {
    $id       = (int)$p['id'];
    $dom      = ($ctx === 'saved') ? 'lf-scard-' : 'lf-card-';   /* the same video can sit in the grid AND the saved list */
    $title    = htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8');
    $thumb    = htmlspecialchars($p['thumbnail_path'], ENT_QUOTES, 'UTF-8');
    $dur      = lf_fmt_duration($p['duration_seconds']);
    $views_n  = (int)$p['views_count'];
    $views    = lf_fmt_count($views_n) . ($views_n === 1 ? ' view' : ' views');
    $ago      = htmlspecialchars(lf_time_ago($p['created_at']), ENT_QUOTES, 'UTF-8');
    $uname    = htmlspecialchars($owner['username'], ENT_QUOTES, 'UTF-8');
    $avatar   = htmlspecialchars(!empty($owner['profile_pic']) ? $owner['profile_pic'] : 'uploads/default.jpg', ENT_QUOTES, 'UTF-8');
    $share    = htmlspecialchars('profile_longform.php?user_id=' . (int)$profile_id . '&post=' . $id, ENT_QUOTES, 'UTF-8');
    $video    = htmlspecialchars((string)($p['video_path'] ?? ''), ENT_QUOTES, 'UTF-8');
    $saved    = !empty($p['is_saved']) ? '1' : '0';
    $owner_id = (int)$owner['id'];
    $progress = 0;
    if (!empty($p['progress_seconds']) && (int)$p['duration_seconds'] > 0) {
        $progress = round(max(0, min(100, ((int)$p['progress_seconds'] / (int)$p['duration_seconds']) * 100)), 1);
    }
    ob_start(); ?>
<article class="lf-card" id="<?php echo $dom . $id; ?>" data-post-id="<?php echo $id; ?>"
    data-title="<?php echo $title; ?>" data-url="<?php echo $share; ?>" data-video="<?php echo $video; ?>"
    data-saved="<?php echo $saved; ?>" data-own="<?php echo $is_own ? '1' : '0'; ?>">
    <div class="lf-card-head">
        <a class="lf-avatar-wrap" href="profile.php?user_id=<?php echo $owner_id; ?>" aria-label="View <?php echo $uname; ?>'s profile"><img class="lf-avatar" src="<?php echo $avatar; ?>" alt="" loading="lazy" onerror="lfAvatarFail(this)"></a>
        <div class="lf-card-meta" onclick="openLongformModal(<?php echo $id; ?>)">
            <div class="lf-card-title"><?php echo $title; ?></div>
            <div class="lf-card-sub">
                <span class="lf-sub-user"><?php echo $uname; ?></span>
                <span class="lf-sub-dot">&middot;</span><span><?php echo $views; ?></span>
                <span class="lf-sub-dot">&middot;</span><span><?php echo $ago; ?></span>
            </div>
        </div>
        <button type="button" class="lf-dots" onclick="lfOpenActions(<?php echo $id; ?>, event)" aria-label="Post options" aria-haspopup="dialog">
            <svg viewBox="0 0 28 24" fill="currentColor" aria-hidden="true"><rect x="3" y="7.6" width="22" height="2.6" rx="1.3"/><rect x="3" y="13.8" width="22" height="2.6" rx="1.3"/></svg>
        </button>
    </div>
    <div class="lf-thumb" onclick="openLongformModal(<?php echo $id; ?>)" role="button" tabindex="0" aria-label="Open video: <?php echo $title; ?>">
        <i class="ri-movie-2-line lf-thumb-ph"></i>
        <img class="lf-thumb-img" src="<?php echo $thumb; ?>" alt="" loading="lazy" draggable="false" onerror="this.remove()">
        <span class="lf-duration"><?php echo $dur; ?></span>
        <?php if ($progress > 0): ?><div class="lf-progress"><span style="width:<?php echo $progress; ?>%"></span></div><?php endif; ?>
    </div>
</article>
<?php
    return ob_get_clean();
}

/* Effective upload limit = the smallest of our cap and what PHP allows */
$php_upload = lf_ini_bytes(ini_get('upload_max_filesize'));
$php_post   = lf_ini_bytes(ini_get('post_max_size'));
$limits     = [LF_MAX_VIDEO_BYTES];
if ($php_upload > 0) $limits[] = $php_upload;
if ($php_post   > 0) $limits[] = max(1048576, $php_post - LF_MAX_THUMB_BYTES - 262144);
$lf_max_video = (int)min($limits);

/* ══════════════════════════════════════════════════════════════════
   LONG FORM MODAL — its JSON endpoints (?lfm_post, ?lfm_more and the
   like / dislike / track / progress / view / download actions) and the
   lfm_render() function that prints the modal.
   It must be loaded HERE: after the helpers above, and before the
   POST handling below, so its actions are answered first.
   longform_modal.php must sit in the same folder as this file.
   ══════════════════════════════════════════════════════════════════ */
require __DIR__ . '/longform_modal.php';

/* ══════════════════════════════════════════════════════════════════
   POST HANDLING (everything answers with JSON — the page uses fetch/XHR)
   ══════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* A file bigger than post_max_size arrives as a completely empty request */
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        lf_json(['success' => false, 'error' => 'That video is larger than this server allows (limit ' . lf_fmt_bytes($lf_max_video) . '). Choose a smaller file.']);
    }

    $valid_csrf = isset($_POST['csrf_token']) && hash_equals($csrf_token, (string)$_POST['csrf_token']);
    if (!$valid_csrf) {
        lf_json(['success' => false, 'error' => 'Your session expired. Refresh the page and try again.']);
    }

    /* ── Create a long form post (own profile only) ── */
    if (isset($_POST['create_longform'])) {
        $saved_files = [];
        $fail = function ($msg) use (&$saved_files) {
            foreach ($saved_files as $f) { lf_safe_unlink($f); }
            lf_json(['success' => false, 'error' => $msg]);
        };

        if (!$is_own_profile) $fail('You can only upload to your own profile.');

        $title = trim((string)($_POST['title'] ?? ''));
        $title = mb_substr($title, 0, LF_MAX_TITLE);
        $description = trim((string)($_POST['description'] ?? ''));
        $description = mb_substr($description, 0, LF_MAX_DESC);
        $hide_like_count    = !empty($_POST['hide_like_count'])    ? 1 : 0;
        $hide_dislike_count = !empty($_POST['hide_dislike_count']) ? 1 : 0;
        $hide_save_count    = !empty($_POST['hide_save_count'])    ? 1 : 0;
        $hide_share_count   = !empty($_POST['hide_share_count'])   ? 1 : 0;
        $comments_disabled  = !empty($_POST['comments_disabled'])  ? 1 : 0;

        if ($title === '') $fail('Give your video a title.');

        /* thumbnail: required, must be a real image */
        $tf = $_FILES['thumbnail'] ?? null;
        if (!$tf || $tf['error'] === UPLOAD_ERR_NO_FILE) $fail('A thumbnail picture is required.');
        if ($tf['error'] !== UPLOAD_ERR_OK) $fail(lf_upload_error_message($tf['error']));
        if ($tf['size'] > LF_MAX_THUMB_BYTES) $fail('The thumbnail must be smaller than ' . lf_fmt_bytes(LF_MAX_THUMB_BYTES) . '.');
        $img_info = @getimagesize($tf['tmp_name']);
        $img_ext_map = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png'];
        if (defined('IMAGETYPE_WEBP')) { $img_ext_map[IMAGETYPE_WEBP] = 'webp'; }
        if (!$img_info || !isset($img_ext_map[$img_info[2]])) $fail('The thumbnail must be a JPG, PNG or WebP picture.');
        $thumb_ext = $img_ext_map[$img_info[2]];

        /* video: required, allowed type */
        $vf = $_FILES['video'] ?? null;
        if (!$vf || $vf['error'] === UPLOAD_ERR_NO_FILE) $fail('Choose a video to upload.');
        if ($vf['error'] !== UPLOAD_ERR_OK) $fail(lf_upload_error_message($vf['error']));
        if ($vf['size'] > $lf_max_video) $fail('That video is larger than this server allows (limit ' . lf_fmt_bytes($lf_max_video) . ').');

        $video_ext = strtolower(pathinfo($vf['name'], PATHINFO_EXTENSION));
        if (!in_array($video_ext, ['mp4', 'webm', 'mov', 'm4v'], true)) $fail('Videos must be MP4, WebM, MOV or M4V.');
        if (function_exists('finfo_open')) {
            $fi   = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $fi ? (string)finfo_file($fi, $vf['tmp_name']) : '';
            if ($fi) finfo_close($fi);
            $ok_mimes = ['video/mp4', 'video/x-m4v', 'video/quicktime', 'video/webm', 'video/x-matroska', 'application/mp4'];
            if ($mime !== '' && !in_array($mime, $ok_mimes, true)) $fail('That file does not look like a video.');
        }

        /* length: trust ffprobe if the server has it, otherwise the browser's reading */
        $duration = lf_probe_duration($vf['tmp_name']);
        if ($duration === null) {
            $duration = (float)($_POST['video_duration'] ?? 0);
            if ($duration <= 0) $fail('We could not read the length of this video. Try an MP4 (H.264) file.');
        }
        if ($duration < LF_MIN_DURATION) $fail('Long form videos must be at least ' . lf_min_label() . ' long.');
        $duration = (int)floor($duration);

        /* save both files */
        if (!is_dir(LF_VIDEO_DIR)) { @mkdir(LF_VIDEO_DIR, 0755, true); }
        if (!is_dir(LF_THUMB_DIR)) { @mkdir(LF_THUMB_DIR, 0755, true); }
        $video_path = LF_VIDEO_DIR . 'lf_' . uniqid() . '_' . bin2hex(random_bytes(4)) . '.' . $video_ext;
        $thumb_path = LF_THUMB_DIR . 'lft_' . uniqid() . '_' . bin2hex(random_bytes(4)) . '.' . $thumb_ext;
        if (!@move_uploaded_file($vf['tmp_name'], $video_path)) $fail('The server could not save your video.');
        $saved_files[] = $video_path;
        if (!@move_uploaded_file($tf['tmp_name'], $thumb_path)) $fail('The server could not save your thumbnail.');
        $saved_files[] = $thumb_path;

        /* database */
        try {
            $desc_db   = ($description !== '') ? $description : null;
            $file_size = (string)(int)$vf['size'];
            $stmt = $conn->prepare("INSERT INTO longform_posts (user_id, title, description, video_path, thumbnail_path, duration_seconds, file_size, hide_like_count, hide_dislike_count, hide_save_count, hide_share_count, comments_disabled) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param("issssisiiiii", $current_user, $title, $desc_db, $video_path, $thumb_path, $duration, $file_size, $hide_like_count, $hide_dislike_count, $hide_save_count, $hide_share_count, $comments_disabled);
            $stmt->execute();
            $post_id = (int)$stmt->insert_id;
            $stmt->close();
            if (!$post_id) throw new Exception('insert failed');

            $tags = lf_extract_hashtags($description);
            if (!empty($tags)) {
                $ins_tag = $conn->prepare("INSERT INTO longform_hashtags (tag, uses_count) VALUES (?, 1) ON DUPLICATE KEY UPDATE uses_count = uses_count + 1, id = LAST_INSERT_ID(id)");
                $link    = $conn->prepare("INSERT IGNORE INTO longform_post_hashtags (post_id, hashtag_id) VALUES (?, ?)");
                foreach ($tags as $tag) {
                    $ins_tag->bind_param("s", $tag);
                    $ins_tag->execute();
                    $tag_id = (int)$ins_tag->insert_id;
                    if ($tag_id) {
                        $link->bind_param("ii", $post_id, $tag_id);
                        $link->execute();
                    }
                }
                $ins_tag->close();
                $link->close();
            }
        } catch (Throwable $e) {
            $fail('We could not save your video. Please try again.');
        }

        $new_row = [
            'id' => $post_id, 'user_id' => $current_user, 'title' => $title,
            'video_path' => $video_path, 'thumbnail_path' => $thumb_path, 'duration_seconds' => $duration,
            'views_count' => 0, 'created_at' => date('Y-m-d H:i:s'), 'progress_seconds' => null, 'is_saved' => 0,
        ];
        lf_json([
            'success' => true,
            'post_id' => $post_id,
            'html'    => lf_render_card($new_row, $owner, true, $profile_id),
        ]);
    }

    /* ── Delete a long form post (its owner only) ── */
    if (isset($_POST['delete_longform'])) {
        $post_id = (int)($_POST['post_id'] ?? 0);
        $stmt = $conn->prepare("SELECT id, video_path, thumbnail_path FROM longform_posts WHERE id=? AND user_id=?");
        $stmt->bind_param("ii", $post_id, $current_user);
        $stmt->execute();
        $post = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$post) lf_json(['success' => false, 'error' => 'That video could not be found.']);

        try {
            $pid = (int)$post['id'];
            lfm_ensure_schema($conn); /* makes sure the modal's downloads table exists before we clean it */
            $conn->query("UPDATE longform_hashtags h INNER JOIN longform_post_hashtags ph ON ph.hashtag_id = h.id SET h.uses_count = GREATEST(h.uses_count - 1, 0) WHERE ph.post_id = $pid");
            $conn->query("DELETE FROM longform_post_hashtags WHERE post_id = $pid");
            $conn->query("DELETE cl FROM longform_comment_likes cl INNER JOIN longform_comments c ON c.id = cl.comment_id WHERE c.post_id = $pid");
            $conn->query("DELETE FROM longform_comments WHERE post_id = $pid");
            $conn->query("DELETE FROM longform_post_likes WHERE post_id = $pid");
            $conn->query("DELETE FROM longform_post_dislikes WHERE post_id = $pid");
            $conn->query("DELETE FROM longform_post_shares WHERE post_id = $pid");
            $conn->query("DELETE FROM longform_post_saves WHERE post_id = $pid");
            $conn->query("DELETE FROM longform_post_downloads WHERE post_id = $pid");
            $conn->query("DELETE FROM longform_post_views WHERE post_id = $pid");
            $conn->query("DELETE FROM longform_watch_progress WHERE post_id = $pid");
            $conn->query("DELETE FROM longform_reports WHERE post_id = $pid");
            $conn->query("DELETE FROM longform_posts WHERE id = $pid AND user_id = " . (int)$current_user);
        } catch (Throwable $e) {
            lf_json(['success' => false, 'error' => 'Could not delete this video. Please try again.']);
        }

        lf_safe_unlink($post['video_path']);
        lf_safe_unlink($post['thumbnail_path']);
        lf_json(['success' => true, 'post_id' => $post_id]);
    }

    /* ── Record a share (Share post / Copy link) ── */
    if (isset($_POST['share_longform'])) {
        $post_id = (int)($_POST['post_id'] ?? 0);
        $type    = (($_POST['share_type'] ?? '') === 'native') ? 'native' : 'copy_link';
        if (!lf_get_post($conn, $post_id)) lf_json(['success' => false, 'error' => 'That video could not be found.']);
        try {
            $stmt = $conn->prepare("INSERT INTO longform_post_shares (post_id, user_id, share_type) VALUES (?,?,?)");
            $stmt->bind_param("iis", $post_id, $current_user, $type);
            $stmt->execute();
            $stmt->close();
            $conn->query("UPDATE longform_posts SET shares_count = shares_count + 1 WHERE id = " . (int)$post_id);
        } catch (Throwable $e) {
            lf_json(['success' => false, 'error' => 'Could not record the share.']);
        }
        lf_json(['success' => true]);
    }

    /* ── Save / unsave a post (toggle) ── */
    if (isset($_POST['toggle_save_longform'])) {
        $post_id = (int)($_POST['post_id'] ?? 0);
        if (!lf_get_post($conn, $post_id)) lf_json(['success' => false, 'error' => 'That video could not be found.']);
        try {
            $stmt = $conn->prepare("SELECT id FROM longform_post_saves WHERE post_id=? AND user_id=?");
            $stmt->bind_param("ii", $post_id, $current_user);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($existing) {
                $del = $conn->prepare("DELETE FROM longform_post_saves WHERE id=?");
                $del->bind_param("i", $existing['id']);
                $del->execute();
                $del->close();
                $conn->query("UPDATE longform_posts SET saves_count = GREATEST(saves_count - 1, 0) WHERE id = " . (int)$post_id);
                $now_saved = false;
            } else {
                $ins = $conn->prepare("INSERT INTO longform_post_saves (post_id, user_id) VALUES (?,?)");
                $ins->bind_param("ii", $post_id, $current_user);
                $ins->execute();
                $ins->close();
                $conn->query("UPDATE longform_posts SET saves_count = saves_count + 1 WHERE id = " . (int)$post_id);
                $now_saved = true;
            }
        } catch (Throwable $e) {
            lf_json(['success' => false, 'error' => 'Could not update your saved posts.']);
        }
        lf_json(['success' => true, 'saved' => $now_saved]);
    }

    /* ── Report a post (someone else's only, once per person) ── */
    if (isset($_POST['report_longform'])) {
        $post_id = (int)($_POST['post_id'] ?? 0);
        $key     = (string)($_POST['reason'] ?? '');
        $details = mb_substr(trim((string)($_POST['details'] ?? '')), 0, LF_MAX_REPORT_DETAILS);

        if (!isset($lf_report_reasons[$key])) lf_json(['success' => false, 'error' => 'Choose a reason for your report.']);
        $post = lf_get_post($conn, $post_id);
        if (!$post) lf_json(['success' => false, 'error' => 'That video could not be found.']);
        if ((int)$post['user_id'] === $current_user) lf_json(['success' => false, 'error' => 'You cannot report your own video.']);

        try {
            $stmt = $conn->prepare("SELECT id FROM longform_reports WHERE post_id=? AND reporter_id=?");
            $stmt->bind_param("ii", $post_id, $current_user);
            $stmt->execute();
            $already = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($already) lf_json(['success' => false, 'error' => 'You have already reported this video.']);

            $reason_label = $lf_report_reasons[$key];
            $details_db   = ($details !== '') ? $details : null;
            $ins = $conn->prepare("INSERT INTO longform_reports (post_id, reporter_id, reason, details) VALUES (?,?,?,?)");
            $ins->bind_param("iiss", $post_id, $current_user, $reason_label, $details_db);
            $ins->execute();
            $ins->close();
        } catch (Throwable $e) {
            lf_json(['success' => false, 'error' => 'Could not send your report. Please try again.']);
        }
        lf_json(['success' => true]);
    }

    lf_json(['success' => false, 'error' => 'Unknown action.']);
}

/* ══════════════════════════════════════════════════════════════════
   GET ?lf_saved=1  →  the next page of the logged-in user's SAVED posts.
   Only answered on your own page, and always for the logged-in user.
   ══════════════════════════════════════════════════════════════════ */
if (isset($_GET['lf_saved'])) {
    if (!$is_own_profile) lf_json(['success' => false, 'error' => 'Not allowed.']);
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $rows = lf_fetch_saved($conn, $current_user, LF_PAGE_SIZE + 1, $offset);
    $has_more = count($rows) > LF_PAGE_SIZE;
    $rows = array_slice($rows, 0, LF_PAGE_SIZE);
    $html = '';
    foreach ($rows as $r) {
        $row_owner = ['id' => (int)$r['user_id'], 'username' => $r['owner_username'], 'profile_pic' => $r['owner_profile_pic']];
        $html .= lf_render_card($r, $row_owner, ((int)$r['user_id'] === $current_user), (int)$r['user_id'], 'saved');
    }
    lf_json(['success' => true, 'html' => $html, 'count' => count($rows), 'has_more' => $has_more]);
}

/* ══════════════════════════════════════════════════════════════════
   GET ?lf_more=1  →  the next page of cards for infinite scroll
   ══════════════════════════════════════════════════════════════════ */
if (isset($_GET['lf_more'])) {
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $rows = lf_fetch_posts($conn, $profile_id, $current_user, LF_PAGE_SIZE + 1, $offset);
    $has_more = count($rows) > LF_PAGE_SIZE;
    $rows = array_slice($rows, 0, LF_PAGE_SIZE);
    $html = '';
    foreach ($rows as $r) { $html .= lf_render_card($r, $owner, $is_own_profile, $profile_id); }
    lf_json(['success' => true, 'html' => $html, 'count' => count($rows), 'has_more' => $has_more]);
}

/* ══════════════════════════════════════════════════════════════════
   FIRST PAGE OF THE GRID
   ══════════════════════════════════════════════════════════════════ */
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM longform_posts WHERE user_id=? AND status='published'");
$stmt->bind_param("i", $profile_id);
$stmt->execute();
$total_posts = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$first_rows = lf_fetch_posts($conn, $profile_id, $current_user, LF_PAGE_SIZE + 1, 0);
$has_more   = count($first_rows) > LF_PAGE_SIZE;
$first_rows = array_slice($first_rows, 0, LF_PAGE_SIZE);

$open_post = (int)($_GET['post'] ?? 0);

$lf_config = [
    'csrf'          => $csrf_token,
    'endpoint'      => 'profile_longform.php?user_id=' . $profile_id,
    'minDuration'   => LF_MIN_DURATION,
    'minLabel'      => lf_min_label(),
    'minClock'      => lf_fmt_duration(LF_MIN_DURATION),
    'maxVideoBytes' => $lf_max_video,
    'maxVideoLabel' => lf_fmt_bytes($lf_max_video),
    'maxThumbBytes' => LF_MAX_THUMB_BYTES,
    'maxThumbLabel' => lf_fmt_bytes(LF_MAX_THUMB_BYTES),
    'maxTitle'      => LF_MAX_TITLE,
    'maxDesc'       => LF_MAX_DESC,
    'isOwn'         => $is_own_profile,
    'total'         => $total_posts,
    'offset'        => count($first_rows),
    'hasMore'       => $has_more,
    'openPost'      => $open_post,
    'profileId'     => $profile_id,
    'reasons'       => $lf_report_reasons,
    'maxReportDetails' => LF_MAX_REPORT_DETAILS,
];
$uname_safe = htmlspecialchars($owner['username'], ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $uname_safe; ?>&rsquo;s Videos | Dezzme</title>
<meta name="theme-color" content="#010218" id="metaTheme">
<!-- theme: applied before first paint so there is no dark flash in light mode (same key as every other Dezzme page) -->
<script>try{if(localStorage.getItem('dz_theme')==='light'){document.documentElement.setAttribute('data-theme','light');document.getElementById('metaTheme').setAttribute('content','#f4f5fb');}}catch(e){}</script>

<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">

<style>
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
* { font-family:'Poppins', sans-serif; }
button, a, label { cursor:pointer; outline:none; -webkit-tap-highlight-color:transparent; }
button:focus-visible, a:focus-visible, .lf-thumb:focus-visible { outline:2px solid var(--accent); outline-offset:2px; }

[class^="ri-"], [class*=" ri-"],
[class^="ri-"]::before, [class*=" ri-"]::before,
[class^="ri-"]::after,  [class*=" ri-"]::after {
    font-family:'remixicon' !important;
    font-style:normal; font-weight:normal; line-height:1;
    speak:none; text-transform:none;
    -webkit-font-smoothing:antialiased;
}

/* ═══════════════════════════════════════════════════════════════
   HOW THE RESPONSIVE SIZING WORKS
   --m = responsive boost. 1 on desktop, 1.188 on screens ≤790px (the old 1.1, plus another 8%).
   --w = fluid progress. clamp() holds it at 0px on a 330px screen and 460px on a 790px+ screen.
   Fluid sizes are written as:
       calc((A + D / 460 * var(--w)) * var(--m))
   A = the old size at 330px, D = (old size at 790px) - A.
   So the size grows smoothly between the old small-phone and old big-phone values,
   and on desktop (--w = 460px, --m = 1) it equals the original value exactly.
   This replaces the old 490 / 390 / 330 pixel steps.
═══════════════════════════════════════════════════════════════ */
:root{
    --m:1;
    --fg:255,255,255;                 /* colour of text/lines on the page — white in dark mode, navy in light mode */
    --panel:#080b28;
    --w:clamp(0px, calc(100vw - 330px), 460px);

    /* every colour is red or a shade of red (names kept so longform_modal.php keeps working) */
    --accent:#FF2D2D;
    --accent-soft:rgba(255,45,45,.14);
    --accent-border:rgba(255,45,45,.4);
    --accent-strong:#e01b1b;
    --accent-glow:rgba(255,45,45,.28);
    --accent-text:#ff8a8a;
    --surface:rgba(255,255,255,0.05);
    --surface-hover:rgba(255,255,255,0.08);
    --border:rgba(255,255,255,0.14);
    --text:#ffffff;
    --muted:rgba(255,255,255,0.45);
    --sub:rgba(255,255,255,0.62);
    --divider:rgba(255,255,255,0.09);
    --line:rgba(255,255,255,0.09);
    --bg:linear-gradient(-45deg,#010218,#070924,#070924);
    --chip:rgba(255,255,255,.08);
    --success:#ff8a8a;
    --success-soft:rgba(255,138,138,.1);
    --success-border:rgba(255,138,138,.45);
    --tag:#ff9a9a;

    --max-w:580px;
    --topbar-h:calc((44px + 10 / 460 * var(--w)) * var(--m));
    --pad:calc(clamp(9px, 3vw, 16px) * var(--m));
    --ctl-h:calc(clamp(30px, 8vw, 36px) * var(--m));
    --r:calc(5px * var(--m));
    --act-h:calc(clamp(25px, 7.2vw, 30px) * var(--m));
    --act-r:calc(4px * var(--m));
}
@media (max-width:790px){ :root{ --m:1.188; } }   /* responsive only: 1.1 was the old boost, +8% on top of it */

html{ -webkit-text-size-adjust:100%; scroll-behavior:smooth; }

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
    padding-bottom:calc(60px * var(--m));
}
@media (min-width:801px){
    .ac-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); }
}
@media (max-width:800px){ .ac-lane{ max-width:100%; } }

/* ══ TOPBAR — X left, title right, grey line pinned under it ══ */
.ac-topbar{
    position:sticky; top:0; z-index:100;
    height:var(--topbar-h); flex-shrink:0;
    display:flex; align-items:center; gap:calc(2px * var(--m));
    padding:0 calc(10px * var(--m));
    background:var(--bg); background-attachment:fixed;
}
.ac-topbar::after{
    content:''; position:absolute; left:0; right:0; bottom:0;
    height:1px; background:rgba(var(--fg),0.14);
    pointer-events:none; z-index:2;
}
.ac-x{
    width:calc((30px + 4 / 460 * var(--w)) * var(--m)); height:calc((30px + 4 / 460 * var(--w)) * var(--m));
    background:none; border:none; color:var(--text);
    display:flex; align-items:center; justify-content:center;
    opacity:.9; transition:opacity .2s, color .2s;
    padding:0; flex-shrink:0;
}
.ac-x:hover{ opacity:1; color:var(--accent); }
.ac-x svg{ width:calc(22px * var(--m)); height:calc(22px * var(--m)); display:block; }
.ac-title{
    flex:1; min-width:0;
    display:flex; align-items:center; justify-content:flex-end; gap:calc(6px * var(--m));
    font-size:calc((12.8px + 1.92 / 460 * var(--w)) * var(--m)); font-weight:700; color:var(--text); letter-spacing:-.1px;
    white-space:nowrap; margin:0 calc(6px * var(--m)) 0 calc(10px * var(--m));
}
.ac-title.left{ justify-content:flex-start; margin:0 calc(6px * var(--m)) 0 calc(4px * var(--m)); }
.tt-user{ min-width:0; overflow:hidden; text-overflow:ellipsis; }
.tt-dot{ flex-shrink:0; opacity:.55; }
.tt-label{ flex-shrink:0; font-weight:500; color:rgba(var(--fg),.78); }
.topbar-actions{ display:flex; align-items:center; gap:0; flex-shrink:0; }
.add-btn{
    width:calc((32px + 8 / 460 * var(--w)) * var(--m)); height:calc((32px + 8 / 460 * var(--w)) * var(--m));
    border:none; background:none; border-radius:50%;
    color:var(--text); font-size:calc((20px + 4.8 / 460 * var(--w)) * var(--m)); flex-shrink:0;
    display:flex; align-items:center; justify-content:center;
    transition:opacity .2s, transform .15s, color .2s;
}
.add-btn:hover{ color:var(--accent); }
.add-btn:active{ transform:scale(.9); }

/* ══ SKELETON LOADER ══ */
.skeleton-loader{
    position:fixed; inset:0; z-index:5000;
    background:var(--bg); background-attachment:fixed;
    display:flex; justify-content:center;
    transition:opacity .5s ease, visibility .5s ease;
}
.skeleton-loader.hidden{ opacity:0; visibility:hidden; pointer-events:none; }
.sk-lane{ width:100%; max-width:var(--max-w); display:flex; flex-direction:column; overflow:hidden; }
@media (min-width:801px){ .sk-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); } }
@media (max-width:800px){ .sk-lane{ max-width:100%; } }
.sk-shimmer{ position:relative; overflow:hidden; background:rgba(var(--fg),.06); border-radius:calc(6px * var(--m)); }
.sk-shimmer::after{
    content:''; position:absolute; inset:0;
    background:linear-gradient(90deg, transparent, rgba(var(--fg),.09), transparent);
    transform:translateX(-100%); animation:sk-shine 1.5s infinite;
}
@keyframes sk-shine{ 100%{ transform:translateX(100%); } }
.sk-topbar{ height:var(--topbar-h); display:flex; align-items:center; justify-content:space-between; padding:0 calc(16px * var(--m)) 0 calc(14px * var(--m)); border-bottom:1px solid rgba(var(--fg),0.14); }
.sk-back{ width:calc(20px * var(--m)); height:calc(20px * var(--m)); }
.sk-title{ width:calc(130px * var(--m)); height:calc(14px * var(--m)); }
.sk-card-head{ display:flex; gap:calc(11px * var(--m)); padding:calc(14px * var(--m)) var(--pad) calc(11px * var(--m)); align-items:flex-start; }
.sk-avatar{ width:calc((30px + 8 / 460 * var(--w)) * var(--m)); height:calc((30px + 8 / 460 * var(--w)) * var(--m)); border-radius:50%; flex-shrink:0; }
.sk-lines{ flex:1; display:flex; flex-direction:column; gap:calc(7px * var(--m)); padding-top:calc(2px * var(--m)); }
.sk-line{ border-radius:calc(6px * var(--m)); height:calc(11px * var(--m)); }
.sk-thumb{ width:100%; padding-top:56.25%; border-radius:0; }

/* ══ GRID — one wide card per video ══ */
.lf-main{ width:100%; flex:1; display:flex; flex-direction:column; }

.lf-card{ position:relative; border-bottom:1px solid var(--divider); padding-bottom:calc(16px * var(--m)); margin-bottom:calc(6px * var(--m)); }
.lf-card:last-child{ border-bottom:none; }
.lf-card.removing{ opacity:0; transform:scale(.98); transition:opacity .25s ease, transform .25s ease; }
.lf-card.just-added{ animation:lf-pop .45s ease; }
@keyframes lf-pop{ from{ opacity:0; transform:translateY(-8px); } to{ opacity:1; transform:none; } }

.lf-card-head{
    display:flex; align-items:flex-start; position:relative;
    gap:calc((8px + 3 / 460 * var(--w)) * var(--m));
    padding:calc((10px + 4 / 460 * var(--w)) * var(--m)) calc((2px + 6 / 460 * var(--w)) * var(--m)) calc((9px + 2 / 460 * var(--w)) * var(--m)) var(--pad);
}
.lf-avatar-wrap{
    width:calc((30px + 8 / 460 * var(--w)) * var(--m)); height:calc((30px + 8 / 460 * var(--w)) * var(--m));
    border-radius:50%; overflow:hidden; flex-shrink:0;
    border:2px solid var(--accent);
    background:linear-gradient(135deg,#262b3d,#171a24);
    display:flex; align-items:center; justify-content:center; color:var(--accent); font-size:calc((12.8px + 3.2 / 460 * var(--w)) * var(--m));
    text-decoration:none; transition:box-shadow .2s;
}
.lf-avatar-wrap:hover{ box-shadow:0 0 0 3px var(--accent-glow); }
.lf-avatar{ width:100%; height:100%; object-fit:cover; display:block; }
.lf-card-meta{ flex:1; min-width:0; cursor:pointer; padding-top:calc(1px * var(--m)); }
.lf-card-title{
    font-size:calc((11.84px + 2.24 / 460 * var(--w)) * var(--m)); font-weight:600; color:var(--text); line-height:1.38;
    display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;
    overflow-wrap:anywhere;
}
.lf-card-sub{
    display:flex; flex-wrap:wrap; align-items:center; gap:0 calc(5px * var(--m));
    font-size:calc((9.6px + 1.6 / 460 * var(--w)) * var(--m)); color:var(--muted); margin-top:calc(3px * var(--m)); line-height:1.5;
}
.lf-sub-user{ max-width:calc((64px + 66 / 460 * var(--w)) * var(--m)); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:var(--sub); }
.lf-sub-dot{ opacity:.7; }

.lf-dots{
    width:calc((34px + 8 / 460 * var(--w)) * var(--m)); height:calc((28px + 6 / 460 * var(--w)) * var(--m));
    margin-top:calc((-3px + -2 / 460 * var(--w)) * var(--m)); border-radius:var(--act-r); border:none; background:none;
    color:rgba(var(--fg),.88); flex-shrink:0;
    display:flex; align-items:center; justify-content:center; transition:.2s;
}
.lf-dots svg{ width:calc((22px + 6 / 460 * var(--w)) * var(--m)); height:calc((19px + 5 / 460 * var(--w)) * var(--m)); display:block; }
.lf-dots:hover{ background:rgba(var(--fg),.1); color:var(--text); }
.lf-dots:active{ transform:scale(.92); }

/* ══ POST ACTIONS — centered dialog on desktop, bottom sheet on small screens ══ */
.lf-act-overlay{
    position:fixed; inset:0; z-index:2000; display:none;
    align-items:center; justify-content:center; padding:20px;
    background:rgba(1,2,12,.72);
    backdrop-filter:blur(3px); -webkit-backdrop-filter:blur(3px);
}
.lf-act-overlay.open{ display:flex; animation:lf-fade .18s ease; }
@keyframes lf-fade{ from{ opacity:0; } to{ opacity:1; } }
@keyframes lf-act-in{ from{ opacity:0; transform:translateY(12px) scale(.98); } to{ opacity:1; transform:none; } }
@keyframes lf-sheet-in{ from{ transform:translateY(100%); } to{ transform:translateY(0); } }

.lf-act-panel{
    position:relative; width:100%; max-width:clamp(300px, 42vw, 400px); max-height:calc(100% - 10px); overflow-y:auto;
    background:rgba(12,12,28,0.98);
    border:1px solid var(--line); border-radius:10px;
    box-shadow:0 30px 70px rgba(0,0,0,.65);
    padding:6px 0 10px; scrollbar-width:none;
    animation:lf-act-in .24s cubic-bezier(.22,1,.36,1);
}
.lf-act-panel::-webkit-scrollbar{ display:none; }
.lf-act-handle{ display:none; justify-content:center; padding:10px 0 6px; touch-action:none; }
.lf-act-handle span{ width:36px; height:4px; border-radius:4px; background:rgba(var(--fg),.18); }

.lf-act-head{
    display:flex; align-items:center; gap:12px;
    padding:12px 16px; border-bottom:1px solid var(--line);
}
.lf-act-thumb{
    width:64px; height:36px; border-radius:4px; flex-shrink:0;
    background:var(--panel) center/cover no-repeat; border:1px solid var(--border);
}
.lf-act-title{
    flex:1; min-width:0; font-size:.8rem; font-weight:600; color:var(--text); line-height:1.4;
    display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; overflow-wrap:anywhere;
}
.lf-act-close{
    width:28px; height:28px; border-radius:4px; border:none; flex-shrink:0;
    background:rgba(var(--fg),.08); color:var(--sub); font-size:1.05rem;
    display:flex; align-items:center; justify-content:center; transition:.2s;
}
.lf-act-close:hover{ background:rgba(var(--fg),.18); color:var(--text); }
.lf-act-back{
    background:none; border:none; color:var(--text); font-size:1.8rem; padding:0; margin-left:-4px;
    display:flex; align-items:center; flex-shrink:0; transition:color .2s;
}
.lf-act-back:hover{ color:var(--accent); }

.lf-act-item{
    width:100%; display:flex; align-items:center; gap:14px;
    padding:14px 18px; border:none; background:none; border-radius:0;
    color:var(--text); font-size:.84rem; font-weight:500; text-align:left; transition:background .15s;
    min-height:clamp(46px, 12vw, 52px);
}
.lf-act-item + .lf-act-item{ border-top:1px solid var(--line); }
.lf-act-item i{ font-size:1.25rem; width:24px; text-align:center; flex-shrink:0; color:var(--accent); }
.lf-act-item:hover{ background:rgba(var(--fg),.05); }
.lf-act-item:active{ background:var(--accent-soft); }
.lf-act-item.danger, .lf-act-item.danger i{ color:#ff6b6b; }

.lf-act-sub{ font-size:.74rem; color:var(--muted); padding:12px 18px 10px; line-height:1.55; }
.lf-reasons{ display:flex; flex-direction:column; border-top:1px solid var(--line); }
.lf-reason{
    width:100%; display:flex; align-items:center; gap:12px; padding:13px 18px;
    border:none; background:none; border-radius:0;
    color:var(--text); font-size:.8rem; text-align:left; transition:background .15s;
}
.lf-reason + .lf-reason{ border-top:1px solid var(--line); }
.lf-reason:hover{ background:rgba(var(--fg),.05); }
.lf-radio{ position:relative; width:18px; height:18px; border-radius:50%; border:2px solid rgba(var(--fg),.4); flex-shrink:0; transition:.15s; }
.lf-reason.selected .lf-radio{ border-color:var(--accent); }
.lf-reason.selected .lf-radio::after{ content:''; position:absolute; inset:3px; border-radius:50%; background:var(--accent); }
.lf-report-details{
    display:block; width:calc(100% - 32px); margin:14px 16px 0; min-height:72px; max-height:140px;
    padding:10px 12px; border-radius:5px; resize:vertical;
    border:1px solid var(--border); background:var(--surface);
    color:var(--text); font-size:.76rem; line-height:1.5; outline:none; transition:.2s;
}
.lf-report-details:focus{ border-color:rgba(255,45,45,.7); background:rgba(var(--fg),.07); box-shadow:0 0 0 3px rgba(255,45,45,.12); }
.lf-report-details::placeholder{ color:var(--muted); }
.lf-act-pane .gc-btn{ width:calc(100% - 32px); margin:14px 16px 6px; }
.lf-act-pane .gc-btn:disabled{ opacity:.4; }

/* ══ THUMBNAIL (also the hover / hold preview area) ══ */
.lf-thumb{
    position:relative; width:100%; padding-top:56.25%;
    background:var(--panel); overflow:hidden; cursor:pointer;
    -webkit-touch-callout:none; -webkit-user-select:none; user-select:none;
    -webkit-tap-highlight-color:transparent;
}
.lf-thumb-ph{
    position:absolute; top:50%; left:50%; transform:translate(-50%,-50%);
    font-size:calc(2rem * var(--m)); color:rgba(var(--fg),.14); pointer-events:none;
}
.lf-thumb-img{
    position:absolute; inset:0; width:100%; height:100%; object-fit:cover; display:block;
    transition:transform .35s ease; pointer-events:none;
    -webkit-user-drag:none; user-select:none;
}
.lf-thumb:hover .lf-thumb-img{ transform:scale(1.025); }
.lf-thumb:active .lf-thumb-img{ transform:scale(1.01); }

.lf-thumb-vid{
    position:absolute; inset:0; width:100%; height:100%; object-fit:cover; display:block;
    background:#000; opacity:0; transition:opacity .3s ease; pointer-events:none;
}
.lf-thumb-vid.ready{ opacity:1; }
.lf-preview-mute{
    position:absolute; z-index:2;
    left:calc((7px + 1 / 460 * var(--w)) * var(--m)); top:calc((7px + 1 / 460 * var(--w)) * var(--m));
    width:calc((28px + 2 / 460 * var(--w)) * var(--m)); height:calc((28px + 2 / 460 * var(--w)) * var(--m));
    border-radius:var(--act-r); border:none; padding:0;
    background:rgba(0,0,0,.62); color:#fff; font-size:calc((15.2px + .8 / 460 * var(--w)) * var(--m));
    display:none; align-items:center; justify-content:center;
    -webkit-backdrop-filter:blur(4px); backdrop-filter:blur(4px);
    transition:background .2s, transform .15s;
}
.lf-preview-mute.show{ display:flex; }
.lf-preview-mute:hover{ background:rgba(0,0,0,.82); }
.lf-preview-mute:active{ transform:scale(.9); }

.lf-duration{
    position:absolute;
    right:calc((7px + 1 / 460 * var(--w)) * var(--m)); bottom:calc((9px + 1 / 460 * var(--w)) * var(--m));
    background:rgba(0,0,0,.78); color:#fff;
    font-size:calc((9.6px + 1.6 / 460 * var(--w)) * var(--m)); font-weight:600;
    padding:calc((1px + 1 / 460 * var(--w)) * var(--m)) calc((5px + 2 / 460 * var(--w)) * var(--m)); border-radius:calc(4px * var(--m));
    line-height:1.5; letter-spacing:.2px; font-variant-numeric:tabular-nums;
}
.lf-progress{ position:absolute; left:0; right:0; bottom:0; height:calc(3px * var(--m)); background:rgba(255,255,255,.28); }
.lf-progress span{ display:block; height:100%; background:var(--accent); }

/* ══ EMPTY STATE (same design as Untracked) ══ */
.lf-empty{
    display:none; flex:1; min-height:52vh;
    padding:calc(clamp(28px, 8vw, 48px) * var(--m)) calc(clamp(20px, 7vw, 36px) * var(--m)) calc(clamp(36px, 10vw, 60px) * var(--m));
    flex-direction:column; align-items:center; justify-content:center;
    text-align:center;
}
.lf-empty.show{ display:flex; animation:lf-fade .35s ease both; }
.es-title{ font-size:calc(clamp(.95rem, 3.4vw, 1.1rem) * var(--m)); font-weight:700; color:var(--text); letter-spacing:-.2px; }
.es-text{ margin-top:calc(6px * var(--m)); max-width:calc(340px * var(--m)); font-size:calc(clamp(.66rem, 2.1vw, .76rem) * var(--m)); color:var(--muted); line-height:1.7; }
.es-tips{ width:100%; max-width:calc(360px * var(--m)); margin-top:calc(clamp(16px, 5vw, 24px) * var(--m)); display:flex; flex-direction:column; gap:calc(7px * var(--m)); }
.es-tip{ display:flex; align-items:center; gap:calc(10px * var(--m)); padding:calc(10px * var(--m)) calc(12px * var(--m)); border-radius:var(--act-r); background:rgba(var(--fg),0.05); text-align:left; }
.es-tip i{ font-size:calc(1rem * var(--m)); color:var(--accent); flex-shrink:0; }
.es-tip span{ font-size:calc(clamp(.62rem, 2vw, .7rem) * var(--m)); color:var(--sub); line-height:1.5; }
.es-btn{
    margin-top:calc(clamp(16px, 5vw, 22px) * var(--m));
    display:flex; align-items:center; justify-content:center;
    height:var(--act-h); min-width:calc(150px * var(--m)); padding:0 calc(22px * var(--m));
    border:none; border-radius:var(--act-r);
    background:var(--accent); color:#000; font-size:calc((9.92px + 1.6 / 460 * var(--w)) * var(--m)); font-weight:700; text-decoration:none;
    transition:background .2s ease, transform .15s ease;
}
.es-btn:hover{ background:#ff1a1a; }
.es-btn:active{ transform:scale(0.98); }
.es-btn.grey{ background:rgba(var(--fg),0.1); color:var(--text); font-weight:600; backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px); }
.es-btn.grey:hover{ background:rgba(var(--fg),0.2); }

.lf-sentinel{ display:none; justify-content:center; padding:calc(22px * var(--m)) 0 calc(8px * var(--m)); }
.lf-sentinel.show{ display:flex; }
.lf-spinner{
    width:calc(22px * var(--m)); height:calc(22px * var(--m)); border-radius:50%;
    border:2.5px solid rgba(var(--fg),.14); border-top-color:var(--accent);
    animation:lf-spin .75s linear infinite;
}
@keyframes lf-spin{ to{ transform:rotate(360deg); } }

/* ══ UPLOAD + SAVED MODALS (full screen, same lane) ══ */
.modal-overlay{
    position:fixed; inset:0; z-index:1000; display:none;
    justify-content:center; background:var(--bg); background-attachment:fixed;
    overflow-y:auto; -webkit-overflow-scrolling:touch;
}
.modal-overlay.active{ display:flex; }
.modal-lane{
    width:100%; max-width:var(--max-w); min-height:100%;
    display:flex; flex-direction:column;
}
@media (min-width:801px){ .modal-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); } }
@media (max-width:800px){ .modal-lane{ max-width:100%; } }
.modal-topbar{ z-index:5; }
.modal-body{ flex:1; padding:calc(6px * var(--m)) var(--pad) calc(40px * var(--m)); width:100%; }

.form-error{
    display:none; align-items:flex-start; gap:calc(8px * var(--m));
    padding:calc(10px * var(--m)) calc(12px * var(--m)); border-radius:var(--act-r); margin:calc(14px * var(--m)) 0 0;
    background:var(--accent-soft); border:1px solid var(--accent-border);
    color:#ffb3b3; font-size:calc((10.88px + .64 / 460 * var(--w)) * var(--m)); line-height:1.5;
}
.form-error.show{ display:flex; animation:lf-shake .35s ease; }
.form-error i{ font-size:calc(.95rem * var(--m)); color:var(--accent); flex-shrink:0; margin-top:1px; }
@keyframes lf-shake{ 0%,100%{ transform:translateX(0); } 25%{ transform:translateX(-5px); } 75%{ transform:translateX(5px); } }

.copyright-note{
    display:flex; align-items:flex-start; gap:calc(11px * var(--m));
    padding:calc(12px * var(--m)) calc(13px * var(--m)); margin:calc(14px * var(--m)) 0 calc(4px * var(--m)); border-radius:var(--act-r);
    background:var(--accent-soft); border:1px solid var(--accent-border);
}
.copyright-icon{
    width:calc((30px + 2 / 460 * var(--w)) * var(--m)); height:calc((30px + 2 / 460 * var(--w)) * var(--m)); border-radius:var(--act-r); flex-shrink:0;
    background:rgba(255,45,45,.2); color:var(--accent);
    display:flex; align-items:center; justify-content:center; font-size:calc((15.2px + 1.6 / 460 * var(--w)) * var(--m));
}
.copyright-title{ font-size:calc((11.52px + .64 / 460 * var(--w)) * var(--m)); font-weight:700; color:var(--text); line-height:1.4; }
.copyright-desc{ font-size:calc((9.92px + .64 / 460 * var(--w)) * var(--m)); color:var(--sub); line-height:1.6; margin-top:calc(3px * var(--m)); }
.copyright-desc strong{ color:var(--text); font-weight:600; }
.rights-note{ display:flex; align-items:flex-start; gap:calc(7px * var(--m)); font-size:calc((9.6px + .64 / 460 * var(--w)) * var(--m)); color:var(--muted); line-height:1.55; margin:calc(18px * var(--m)) 0 calc(4px * var(--m)); }
.rights-note i{ font-size:calc(.85rem * var(--m)); color:var(--accent); flex-shrink:0; margin-top:1px; }

.field-block{ margin-top:calc(clamp(14px, 4vw, 20px) * var(--m)); }
.field-label{ font-size:calc(.6rem * var(--m)); font-weight:700; letter-spacing:1.5px; text-transform:uppercase; color:var(--muted); display:flex; align-items:center; gap:calc(6px * var(--m)); margin-bottom:calc(9px * var(--m)); }
.field-label i{ color:var(--accent); font-size:calc(.85rem * var(--m)); }
.field-tag{ margin-left:auto; font-size:calc(.56rem * var(--m)); font-weight:700; letter-spacing:.5px; padding:calc(2px * var(--m)) calc(8px * var(--m)); border-radius:var(--act-r); border:1px solid var(--border); color:var(--muted); }
.field-tag.req{ color:var(--accent-text); border-color:var(--accent-border); background:var(--accent-soft); }
.field-hint{ font-size:calc(.64rem * var(--m)); color:var(--muted); margin-top:calc(7px * var(--m)); line-height:1.55; }
.field-foot{ display:flex; align-items:flex-start; justify-content:space-between; gap:calc(10px * var(--m)); margin-top:calc(7px * var(--m)); }
.field-foot .field-hint{ margin-top:0; }
.char-count{ font-size:calc(.62rem * var(--m)); color:var(--muted); flex-shrink:0; font-variant-numeric:tabular-nums; }
.char-count.near{ color:var(--accent-text); }

/* video picker */
.video-drop{
    display:flex; flex-direction:column; align-items:center; text-align:center; gap:calc(5px * var(--m));
    padding:calc((16px + 10 / 460 * var(--w)) * var(--m)) calc((10px + 6 / 460 * var(--w)) * var(--m)); border-radius:var(--r);
    border:1.5px dashed var(--accent-border); background:var(--accent-soft);
    transition:.2s;
}
.video-drop:hover, .video-drop.dragover{ background:rgba(255,45,45,.2); border-color:var(--accent); }
.video-drop input{ display:none; }
.video-drop-icon{
    width:calc(46px * var(--m)); height:calc(46px * var(--m)); border-radius:50%; background:var(--accent); color:#000;
    display:flex; align-items:center; justify-content:center; font-size:calc(1.35rem * var(--m)); margin-bottom:calc(4px * var(--m));
    box-shadow:0 8px 22px var(--accent-glow);
}
.video-drop-title{ font-size:calc((12.16px + .64 / 460 * var(--w)) * var(--m)); font-weight:700; color:var(--text); }
.video-drop-sub{ font-size:calc((9.6px + .64 / 460 * var(--w)) * var(--m)); color:var(--muted); line-height:1.55; max-width:calc((230px + 50 / 460 * var(--w)) * var(--m)); }

.video-card{
    display:none; align-items:center; gap:calc(11px * var(--m));
    padding:calc(11px * var(--m)) calc(12px * var(--m)); border-radius:var(--r);
    background:var(--surface); border:1px solid var(--border);
}
.video-card.show{ display:flex; }
.video-card-icon{
    width:calc(38px * var(--m)); height:calc(38px * var(--m)); border-radius:var(--act-r); flex-shrink:0;
    display:flex; align-items:center; justify-content:center; font-size:calc(1.15rem * var(--m));
    background:var(--accent-soft); color:var(--accent);
}
.video-card-info{ flex:1; min-width:0; }
.video-card-name{ font-size:calc((11.52px + .64 / 460 * var(--w)) * var(--m)); font-weight:600; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.video-card-meta{ font-size:calc((9.92px + .64 / 460 * var(--w)) * var(--m)); color:var(--muted); margin-top:calc(2px * var(--m)); }
.video-card-remove{
    width:calc(28px * var(--m)); height:calc(28px * var(--m)); border-radius:var(--act-r); border:none; flex-shrink:0;
    background:rgba(var(--fg),.1); color:rgba(var(--fg),.7); font-size:calc(.95rem * var(--m));
    display:flex; align-items:center; justify-content:center; transition:.2s;
}
.video-card-remove:hover{ background:rgba(var(--fg),.2); color:var(--accent); }
.video-card.is-valid{ border-color:var(--success-border); background:var(--success-soft); }
.video-card.is-valid .video-card-icon{ background:rgba(255,138,138,.18); color:var(--success); }
.video-card.is-invalid{ border-color:rgba(255,45,45,.65); background:var(--accent-soft); }
.video-card.is-checking .video-card-icon i{ animation:lf-spin 1s linear infinite; }

.video-status{ display:none; align-items:flex-start; gap:calc(7px * var(--m)); margin-top:calc(8px * var(--m)); font-size:calc((10.24px + .64 / 460 * var(--w)) * var(--m)); line-height:1.5; }
.video-status.show{ display:flex; }
.video-status i{ font-size:calc(.9rem * var(--m)); flex-shrink:0; margin-top:1px; }
.video-status.ok{ color:var(--success); }
.video-status.bad{ color:#ff6b6b; }
.video-status.wait{ color:var(--muted); }

/* thumbnail picker */
.thumb-preview{
    position:relative; width:100%; padding-top:56.25%; border-radius:var(--r); overflow:hidden;
    border:1px dashed var(--border); background:var(--panel); margin-bottom:calc(9px * var(--m));
}
.thumb-preview img{ position:absolute; inset:0; width:100%; height:100%; object-fit:cover; display:block; }
.thumb-preview-empty{
    position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:calc(6px * var(--m));
    font-size:calc(.7rem * var(--m)); color:var(--muted);
}
.thumb-preview-empty i{ font-size:calc(1.5rem * var(--m)); color:rgba(var(--fg),.22); }
.thumb-preview .lf-duration{ z-index:1; }
.file-label{
    display:inline-flex; align-items:center; gap:calc(6px * var(--m)); height:var(--act-h); padding:0 calc((12px + 2 / 460 * var(--w)) * var(--m)); border-radius:var(--act-r);
    background:rgba(var(--fg),0.1); backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    color:var(--text); font-size:calc((9.92px + 1.6 / 460 * var(--w)) * var(--m)); font-weight:600;
    transition:background .2s; white-space:nowrap;
}
.file-label:hover{ background:rgba(var(--fg),0.2); }
.file-label input{ display:none; }

/* inputs */
.gc-input{
    width:100%; height:var(--ctl-h); padding:0 calc((10px + 2 / 460 * var(--w)) * var(--m)); border-radius:var(--r);
    border:1px solid var(--border); background:var(--surface);
    color:var(--text); font-size:calc((11.2px + .96 / 460 * var(--w)) * var(--m)); outline:none; transition:.2s;
    -webkit-appearance:none; appearance:none;
}
.gc-input:focus{ border-color:rgba(255,45,45,.7); background:rgba(var(--fg),.07); box-shadow:0 0 0 3px rgba(255,45,45,.12); }
.gc-input::placeholder{ color:var(--muted); }

/* description editor: links red, #hashtags light red, AS YOU TYPE */
.desc-editor{ position:relative; border-radius:var(--r); border:1px solid var(--border); background:var(--surface); transition:.2s; }
.desc-editor:focus-within{ border-color:rgba(255,45,45,.7); background:rgba(var(--fg),.07); box-shadow:0 0 0 3px rgba(255,45,45,.12); }
.desc-backdrop, .desc-input{
    font-family:'Poppins',sans-serif; font-size:calc((11.2px + .96 / 460 * var(--w)) * var(--m)); line-height:1.55; letter-spacing:normal;
    padding:calc((9px + 1 / 460 * var(--w)) * var(--m)) calc((10px + 2 / 460 * var(--w)) * var(--m)); white-space:pre-wrap; overflow-wrap:break-word; word-break:break-word;
}
.desc-backdrop{ position:absolute; inset:0; overflow:hidden; pointer-events:none; color:rgba(var(--fg),.92); border-radius:var(--r); }
.desc-input{
    position:relative; display:block; width:100%; min-height:calc((105px + 15 / 460 * var(--w)) * var(--m)); max-height:calc(240px * var(--m));
    background:transparent; color:transparent; -webkit-text-fill-color:transparent; caret-color:var(--text);
    border:0; border-radius:var(--r); outline:0; resize:none; margin:0;
    overflow-y:auto; scrollbar-width:none; -webkit-appearance:none; appearance:none;
}
.desc-input::-webkit-scrollbar{ display:none; }
.desc-input::placeholder{ color:var(--muted); -webkit-text-fill-color:var(--muted); }
.desc-input::selection{ background:rgba(255,45,45,.4); }
.hl-link{ color:var(--accent); }
.hl-tag{ color:var(--tag); }
.legend{ display:inline-flex; gap:calc(10px * var(--m)); flex-wrap:wrap; }

/* setting toggles */
.setting-row{
    display:flex; align-items:center; gap:calc((8px + 4 / 460 * var(--w)) * var(--m));
    padding:calc((9px + 3 / 460 * var(--w)) * var(--m)) calc((9px + 4 / 460 * var(--w)) * var(--m)); border:1px solid var(--border); background:var(--surface);
    border-radius:var(--r); transition:.2s;
}
.setting-row + .setting-row{ margin-top:calc(8px * var(--m)); }
.setting-row:hover{ background:var(--surface-hover); }
.setting-icon{
    width:calc((30px + 2 / 460 * var(--w)) * var(--m)); height:calc((30px + 2 / 460 * var(--w)) * var(--m)); border-radius:var(--act-r); flex-shrink:0;
    background:var(--accent-soft); color:var(--accent);
    display:flex; align-items:center; justify-content:center; font-size:calc((14.4px + 1.6 / 460 * var(--w)) * var(--m));
}
.setting-text{ flex:1; min-width:0; }
.setting-title{ font-size:calc((11.52px + .64 / 460 * var(--w)) * var(--m)); font-weight:600; color:var(--text); }
.setting-desc{ font-size:calc((9.6px + .64 / 460 * var(--w)) * var(--m)); color:var(--muted); line-height:1.5; margin-top:calc(2px * var(--m)); }
.switch{ position:relative; width:calc((38px + 4 / 460 * var(--w)) * var(--m)); height:calc((22px + 2 / 460 * var(--w)) * var(--m)); flex-shrink:0; }
.switch input{ position:absolute; inset:0; width:100%; height:100%; margin:0; opacity:0; cursor:pointer; z-index:1; }
.switch .slider{ position:absolute; inset:0; border-radius:999px; background:rgba(var(--fg),.16); transition:.2s; pointer-events:none; }
.switch .slider::after{ content:''; position:absolute; top:calc(3px * var(--m)); left:calc(3px * var(--m)); width:calc((16px + 2 / 460 * var(--w)) * var(--m)); height:calc((16px + 2 / 460 * var(--w)) * var(--m)); border-radius:50%; background:var(--text); transition:transform .2s; }
.switch input:checked + .slider{ background:var(--accent); }
.switch input:checked + .slider::after{ transform:translateX(calc((16px + 2 / 460 * var(--w)) * var(--m))); }
.switch input:focus-visible + .slider{ box-shadow:0 0 0 3px var(--accent-glow); }

/* submit button (doubles as the upload progress bar) */
.gc-btn{
    position:relative; overflow:hidden;
    height:var(--ctl-h); padding:0 calc(16px * var(--m)); border-radius:var(--r); border:none;
    background:var(--accent); color:#000; font-size:calc((11.52px + .96 / 460 * var(--w)) * var(--m)); font-weight:700;
    transition:background .2s, transform .15s; width:100%; display:flex; align-items:center; justify-content:center; gap:calc(7px * var(--m));
    margin-top:calc(6px * var(--m));
}
.gc-btn:hover{ background:#ff1a1a; }
.gc-btn:active{ transform:scale(.98); }
.gc-btn:disabled{ cursor:default; opacity:.92; }
.gc-btn-fill{ position:absolute; top:0; bottom:0; left:0; width:0; background:rgba(0,0,0,.22); transition:width .2s ease; }
.gc-btn-label{ position:relative; display:flex; align-items:center; gap:calc(7px * var(--m)); }
.gc-btn.uploading{ background:var(--accent-strong); }
.cancel-note{ display:none; text-align:center; font-size:calc(.64rem * var(--m)); color:var(--muted); margin-top:calc(10px * var(--m)); line-height:1.5; }
.cancel-note.show{ display:block; }

#savedModal{ z-index:500; }
.saved-main{ padding-bottom:calc(44px * var(--m)); }

/* toast */
#lfToast{
    position:fixed; bottom:calc((26px + 8 / 460 * var(--w)) * var(--m)); left:50%;
    transform:translateX(-50%) translateY(14px);
    background:rgba(8,8,22,.97); border:1px solid rgba(255,45,45,.25); color:#fff;
    font-size:calc((11.2px + .64 / 460 * var(--w)) * var(--m)); font-weight:500;
    padding:calc((8px + 1 / 460 * var(--w)) * var(--m)) calc((15px + 3 / 460 * var(--w)) * var(--m)); border-radius:var(--r);
    display:flex; align-items:center; gap:calc(7px * var(--m));
    opacity:0; pointer-events:none; transition:opacity .22s ease, transform .22s ease;
    z-index:6000; max-width:calc(100% - 32px); text-align:center; box-shadow:0 8px 24px rgba(0,0,0,.6);
}
#lfToast.show{ opacity:1; transform:translateX(-50%) translateY(0); }
#lfToast i{ color:var(--accent); }

@media (prefers-reduced-motion: reduce){
    .lf-card.just-added, .form-error.show, .lf-act-overlay.open, .lf-act-panel{ animation:none; }
    .lf-thumb-img, .lf-thumb-vid{ transition:none; }
}

/* ══ RESPONSIVE — one fluid block (≤790px) replaces the old 490 / 390 / 330 steps ══
   Post menu: dialog → bottom sheet, every size fluid + 10% bigger */
@media (max-width:790px){
    .lf-act-overlay{ align-items:flex-end; padding:0; }
    .lf-act-panel{
        --as:calc((12px + 6 / 460 * var(--w)) * var(--m));   /* side padding inside the sheet */
        max-width:100%; width:100%; max-height:88vh;
        border-radius:calc((14px + 2 / 460 * var(--w)) * var(--m)) calc((14px + 2 / 460 * var(--w)) * var(--m)) 0 0;
        border-left:none; border-right:none; border-bottom:none;
        padding:0 0 calc(10px * var(--m) + env(safe-area-inset-bottom, 0px));
        animation:lf-sheet-in .3s cubic-bezier(.22,1,.36,1);
    }
    .lf-act-handle{ display:flex; padding:calc(9px * var(--m)) 0 calc(5px * var(--m)); }
    .lf-act-handle span{ width:calc((32px + 4 / 460 * var(--w)) * var(--m)); height:calc((3.5px + .5 / 460 * var(--w)) * var(--m)); }
    .lf-act-close{ display:none; }

    .lf-act-head{
        gap:calc((9px + 3 / 460 * var(--w)) * var(--m));
        padding:calc((7px + 3 / 460 * var(--w)) * var(--m)) var(--as) calc((9px + 3 / 460 * var(--w)) * var(--m));
    }
    .lf-act-thumb{ width:calc((50px + 14 / 460 * var(--w)) * var(--m)); height:calc((29px + 7 / 460 * var(--w)) * var(--m)); }
    .lf-act-title{ font-size:calc((10.56px + 2.24 / 460 * var(--w)) * var(--m)); }

    .lf-act-item{
        padding:0 var(--as);
        font-size:calc((11.2px + 1.92 / 460 * var(--w)) * var(--m));
        gap:calc((10px + 4 / 460 * var(--w)) * var(--m));
        min-height:calc((40px + 12 / 460 * var(--w)) * var(--m));
    }
    .lf-act-item i{ font-size:calc((16px + 3.2 / 460 * var(--w)) * var(--m)); width:calc((18px + 4 / 460 * var(--w)) * var(--m)); }

    .lf-act-sub{
        font-size:calc((10.4px + 1.44 / 460 * var(--w)) * var(--m));
        padding:calc((9px + 3 / 460 * var(--w)) * var(--m)) var(--as) calc((7px + 3 / 460 * var(--w)) * var(--m));
    }
    .lf-reason{
        padding:0 var(--as);
        min-height:calc((38px + 8 / 460 * var(--w)) * var(--m));
        font-size:calc((10.56px + 1.92 / 460 * var(--w)) * var(--m));
        gap:calc((9px + 3 / 460 * var(--w)) * var(--m));
    }
    .lf-radio{ width:calc((16px + 2 / 460 * var(--w)) * var(--m)); height:calc((16px + 2 / 460 * var(--w)) * var(--m)); }
    .lf-reason.selected .lf-radio::after{ inset:calc((2.5px + .5 / 460 * var(--w)) * var(--m)); }
    .lf-report-details{
        width:calc(100% - 2 * var(--as));
        margin:calc((10px + 4 / 460 * var(--w)) * var(--m)) var(--as) 0;
        min-height:calc((64px + 8 / 460 * var(--w)) * var(--m));
        font-size:calc((10.24px + 1.92 / 460 * var(--w)) * var(--m));
    }
    .lf-act-pane .gc-btn{
        width:calc(100% - 2 * var(--as));
        margin:calc((10px + 4 / 460 * var(--w)) * var(--m)) var(--as) calc((4px + 2 / 460 * var(--w)) * var(--m));
    }
}
/* tiniest phones: hide the decorative bits (same as before) */
@media (max-width:330px){
    .lf-act-thumb, .copyright-icon, .setting-icon{ display:none; }
}
/* short landscape phones: keep the sheet scrollable and compact */
@media (max-height:480px){
    .lf-act-panel{ max-height:94vh; }
    .lf-act-item{ min-height:calc(38px * var(--m)); }
    .lf-reason{ min-height:calc(36px * var(--m)); }
}
/* ═══════════ LIGHT MODE — <html data-theme="light"> (dark mode is never touched) ═══════════
   Same palette as the other Dezzme pages. The red accent stays red; video thumbnails and the labels printed on top of them keep their dark layers. */
:root[data-theme="light"]{
    color-scheme:light;
    --fg:6,7,26;
    --text:#0b0d24;
    --bg:linear-gradient(-45deg,#ffffff,#f4f5fb,#f4f5fb);
    --surface:rgba(6,7,26,.05);
    --surface-hover:rgba(6,7,26,.08);
    --border:rgba(6,7,26,.16);
    --muted:rgba(6,7,26,.52);
    --sub:rgba(6,7,26,.66);
    --divider:rgba(6,7,26,.1);
    --line:rgba(6,7,26,.1);
    --chip:rgba(6,7,26,.07);
    --panel:#eceef5;
    --accent-soft:rgba(217,43,43,.1);
    --accent-border:rgba(217,43,43,.4);
    --accent-text:#d92b2b;
    --success:#d92b2b; --success-soft:rgba(217,43,43,.09); --success-border:rgba(217,43,43,.4);
    --tag:#d92b2b;
}
[data-theme="light"] .lf-act-panel{ background:#ffffff; box-shadow:0 30px 70px rgba(6,7,26,.28); }
[data-theme="light"] .lf-act-overlay{ background:rgba(6,7,26,.45); }
[data-theme="light"] .lf-thumb{ background:#e3e6f0; }
[data-theme="light"] .lf-thumb i{ color:rgba(6,7,26,.18); }
</style>
</head>
<body>

<!-- ══ SKELETON LOADER (page load) ══ -->
<div class="skeleton-loader" id="skeletonLoader">
    <div class="sk-lane">
        <div class="sk-topbar">
            <div class="sk-back sk-shimmer"></div>
        </div>
        <?php for ($i = 0; $i < 2; $i++): ?>
        <div class="sk-card">
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
</div>

<div class="ac-lane">

    <!-- ══ TOPBAR ══ -->
    <div class="ac-topbar">
        <button type="button" class="ac-x" onclick="acClose()" aria-label="Close">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
        <div class="ac-title left"></div>
        <?php if ($is_own_profile): ?>
        <div class="topbar-actions">
            <button type="button" class="add-btn" onclick="openSavedModal()" aria-label="Saved videos"><i class="ri-bookmark-line"></i></button>
            <button type="button" class="add-btn" onclick="openUploadModal()" aria-label="Upload a long form video"><i class="ri-edit-line"></i></button>
        </div>
        <?php endif; ?>
    </div>

    <!-- ══ GRID ══ -->
    <main class="lf-main">
        <div id="lfGrid">
            <?php foreach ($first_rows as $row) { echo lf_render_card($row, $owner, $is_own_profile, $profile_id); } ?>
        </div>

        <div class="lf-empty<?php echo empty($first_rows) ? ' show' : ''; ?>" id="lfEmpty">
            <?php if ($is_own_profile): ?>
                <div class="es-title">Post your first long form video</div>
                <p class="es-text">Long form is for videos that run <?php echo lf_min_label(); ?> or more. Share tutorials, stories, shows and anything that needs room to breathe.</p>
                <div class="es-tips">
                    <div class="es-tip"><i class="ri-time-line"></i><span>Videos must be <?php echo lf_min_label(); ?> or longer.</span></div>
                    <div class="es-tip"><i class="ri-image-2-line"></i><span>Add a 16:9 thumbnail so your video looks great on the grid.</span></div>
                    <div class="es-tip"><i class="ri-shield-check-line"></i><span>Only upload videos that are yours or that you have permission to use.</span></div>
                </div>
                <button type="button" class="es-btn" onclick="openUploadModal()">Upload video</button>
            <?php else: ?>
                <div class="es-title">No long form videos yet</div>
                <p class="es-text"><?php echo $uname_safe; ?> hasn't posted any videos of <?php echo lf_min_label(); ?> or more. When they do, they'll show up here.</p>
                <div class="es-tips">
                    <div class="es-tip"><i class="ri-movie-2-line"></i><span>Long form videos run <?php echo lf_min_label(); ?> or more.</span></div>
                    <div class="es-tip"><i class="ri-user-3-line"></i><span>Their shorter posts live on their profile.</span></div>
                </div>
                <a class="es-btn grey" href="profile.php?user_id=<?php echo (int)$profile_id; ?>">Back to profile</a>
            <?php endif; ?>
        </div>

        <div class="lf-sentinel<?php echo $has_more ? ' show' : ''; ?>" id="lfSentinel"><div class="lf-spinner"></div></div>
    </main>

</div><!-- /.ac-lane -->

<?php if ($is_own_profile): ?>
<!-- ══ UPLOAD MODAL ══ -->
<div class="modal-overlay" id="uploadModal" role="dialog" aria-modal="true" aria-labelledby="uploadModalTitle">
  <div class="modal-lane">
    <div class="ac-topbar modal-topbar">
        <button type="button" class="ac-x" onclick="closeUploadModal()" aria-label="Close">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
        <div class="ac-title" id="uploadModalTitle">Upload Long Form</div>
    </div>
    <div class="modal-body">

        <div class="form-error" id="lfFormError" role="alert"><i class="ri-error-warning-line"></i><span id="lfFormErrorText"></span></div>

        <!-- Copyright / originality notice -->
        <div class="copyright-note" role="note">
            <span class="copyright-icon"><i class="ri-shield-check-line"></i></span>
            <div>
                <div class="copyright-title">Only upload what&rsquo;s yours</div>
                <div class="copyright-desc">Post <strong>original content</strong> only, or copyrighted content that you have a <strong>licence or permission</strong> to use. This is not a place for pirated movies, TV shows, music or other people&rsquo;s videos. Videos that break these rules can be reported and removed.</div>
            </div>
        </div>

        <!-- Video -->
        <div class="field-block">
            <div class="field-label"><i class="ri-movie-2-line"></i> Video <span class="field-tag req">Required</span></div>
            <label class="video-drop" id="videoDrop">
                <input type="file" id="videoInput" accept="video/mp4,video/webm,video/quicktime,video/x-m4v,.mp4,.webm,.mov,.m4v">
                <span class="video-drop-icon"><i class="ri-upload-cloud-2-line"></i></span>
                <span class="video-drop-title">Choose your video</span>
                <span class="video-drop-sub"><?php echo lf_min_label(); ?> or longer &middot; MP4, WebM, MOV or M4V &middot; up to <?php echo htmlspecialchars(lf_fmt_bytes($lf_max_video)); ?></span>
            </label>
            <div class="video-card" id="videoCard">
                <div class="video-card-icon"><i class="ri-film-line" id="vcIcon"></i></div>
                <div class="video-card-info">
                    <div class="video-card-name" id="vcName"></div>
                    <div class="video-card-meta" id="vcMeta"></div>
                </div>
                <button type="button" class="video-card-remove" onclick="lfClearVideo()" aria-label="Remove video"><i class="ri-close-line"></i></button>
            </div>
            <div class="video-status" id="videoStatus"><i></i><span></span></div>
        </div>

        <!-- Thumbnail -->
        <div class="field-block">
            <div class="field-label"><i class="ri-image-2-line"></i> Thumbnail <span class="field-tag req">Required</span></div>
            <div class="thumb-preview" id="thumbPreview">
                <div class="thumb-preview-empty"><i class="ri-image-add-line"></i><span>Your thumbnail appears here</span></div>
            </div>
            <label class="file-label"><i class="ri-upload-cloud-line"></i> <span id="thumbBtnText">Choose Thumbnail</span>
                <input type="file" id="thumbInput" accept="image/jpeg,image/png,image/webp">
            </label>
            <div class="field-hint">This is the picture shown on your grid. Use a 16:9 image (like 1280&times;720) so nothing gets cropped.</div>
        </div>

        <!-- Title -->
        <div class="field-block">
            <div class="field-label"><i class="ri-text"></i> Title <span class="field-tag req">Required</span></div>
            <input class="gc-input" type="text" id="titleInput" placeholder="Give your video a title" maxlength="<?php echo LF_MAX_TITLE; ?>" autocomplete="off">
            <div class="field-foot"><span class="field-hint"></span><span class="char-count" id="titleCount">0/<?php echo LF_MAX_TITLE; ?></span></div>
        </div>

        <!-- Description -->
        <div class="field-block">
            <div class="field-label"><i class="ri-file-text-line"></i> Description <span class="field-tag">Optional</span></div>
            <div class="desc-editor">
                <div class="desc-backdrop" id="descBackdrop" aria-hidden="true"></div>
                <textarea class="desc-input" id="descInput" maxlength="<?php echo LF_MAX_DESC; ?>" placeholder="What is this video about? Add links or #hashtags."></textarea>
            </div>
            <div class="field-foot">
                <span class="field-hint legend"><span><span class="hl-link">Links</span> show in red</span><span><span class="hl-tag">#hashtags</span> show in light red</span></span>
                <span class="char-count" id="descCount">0/<?php echo LF_MAX_DESC; ?></span>
            </div>
        </div>

        <!-- Settings -->
        <div class="field-block">
            <div class="field-label"><i class="ri-settings-3-line"></i> Post settings</div>
            <label class="setting-row">
                <span class="setting-icon"><i class="ri-thumb-up-line"></i></span>
                <span class="setting-text">
                    <span class="setting-title" style="display:block;">Hide like count</span>
                    <span class="setting-desc" style="display:block;">People can still like the video, but the number stays hidden.</span>
                </span>
                <span class="switch"><input type="checkbox" id="hideLikesInput"><span class="slider"></span></span>
            </label>
            <label class="setting-row">
                <span class="setting-icon"><i class="ri-thumb-down-line"></i></span>
                <span class="setting-text">
                    <span class="setting-title" style="display:block;">Hide dislike count</span>
                    <span class="setting-desc" style="display:block;">People can still dislike the video, but the number stays hidden.</span>
                </span>
                <span class="switch"><input type="checkbox" id="hideDislikesInput"><span class="slider"></span></span>
            </label>
            <label class="setting-row">
                <span class="setting-icon"><i class="ri-bookmark-line"></i></span>
                <span class="setting-text">
                    <span class="setting-title" style="display:block;">Hide save count</span>
                    <span class="setting-desc" style="display:block;">People can still save the video, but the number stays hidden.</span>
                </span>
                <span class="switch"><input type="checkbox" id="hideSavesInput"><span class="slider"></span></span>
            </label>
            <label class="setting-row">
                <span class="setting-icon"><i class="ri-share-forward-line"></i></span>
                <span class="setting-text">
                    <span class="setting-title" style="display:block;">Hide share count</span>
                    <span class="setting-desc" style="display:block;">People can still share the video, but the number stays hidden.</span>
                </span>
                <span class="switch"><input type="checkbox" id="hideSharesInput"><span class="slider"></span></span>
            </label>
            <label class="setting-row">
                <span class="setting-icon"><i class="ri-chat-off-line"></i></span>
                <span class="setting-text">
                    <span class="setting-title" style="display:block;">Turn off commenting</span>
                    <span class="setting-desc" style="display:block;">No one can comment on this video.</span>
                </span>
                <span class="switch"><input type="checkbox" id="commentsOffInput"><span class="slider"></span></span>
            </label>
        </div>

        <div class="rights-note"><i class="ri-information-line"></i><span>By posting, you confirm this video is your own original work, or that you have the licence or permission to use everything in it.</span></div>

        <button type="button" class="gc-btn" id="postBtn" onclick="lfSubmitUpload()">
            <span class="gc-btn-fill" id="postBtnFill"></span>
            <span class="gc-btn-label" id="postBtnLabel"><i class="ri-send-plane-line"></i> Post Video</span>
        </button>
        <div class="cancel-note" id="cancelNote">Keep this page open until the upload finishes. Tap the X to cancel.</div>
    </div>
  </div>
</div>

<!-- ══ SAVED VIDEOS MODAL (only you can open this — every video you saved, from any creator) ══ -->
<div class="modal-overlay" id="savedModal" role="dialog" aria-modal="true" aria-labelledby="savedModalTitle">
  <div class="modal-lane">
    <div class="ac-topbar modal-topbar">
        <button type="button" class="ac-x" onclick="closeSavedModal()" aria-label="Close">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
        <div class="ac-title" id="savedModalTitle">Saved Videos</div>
    </div>
    <main class="lf-main saved-main">
        <div id="savedList"></div>
        <div class="lf-empty" id="savedEmpty">
            <div class="es-title">Nothing saved yet</div>
            <p class="es-text">Tap the menu on any video and choose &ldquo;Save post&rdquo;. Everything you save shows up here, from any creator.</p>
            <div class="es-tips">
                <div class="es-tip"><i class="ri-bookmark-line"></i><span>Save videos to watch them again later.</span></div>
                <div class="es-tip"><i class="ri-lock-line"></i><span>Only you can see your saved videos.</span></div>
            </div>
            <button type="button" class="es-btn grey" onclick="closeSavedModal()">Back to videos</button>
        </div>
        <div class="lf-sentinel" id="savedSentinel"><div class="lf-spinner"></div></div>
    </main>
  </div>
</div>
<?php endif; ?>

<!-- ══ POST ACTIONS (dialog on desktop, bottom sheet on small screens) ══ -->
<div class="lf-act-overlay" id="lfActOverlay" onclick="lfCloseActions()">
    <div class="lf-act-panel" id="lfActPanel" role="dialog" aria-modal="true" aria-labelledby="lfActTitle" onclick="event.stopPropagation()">
        <div class="lf-act-handle" id="lfActHandle"><span></span></div>

        <!-- step 1: the actions -->
        <div class="lf-act-pane" id="lfActMain">
            <div class="lf-act-head" id="lfActHead">
                <div class="lf-act-thumb" id="lfActThumb"></div>
                <div class="lf-act-title" id="lfActTitle"></div>
                <button type="button" class="lf-act-close" onclick="lfCloseActions()" aria-label="Close"><i class="ri-close-line"></i></button>
            </div>
            <button type="button" class="lf-act-item" onclick="lfActShare()"><i class="ri-share-forward-line"></i><span>Share post</span></button>
            <button type="button" class="lf-act-item" onclick="lfActCopy()"><i class="ri-link"></i><span>Copy link</span></button>
            <button type="button" class="lf-act-item" id="lfActSave" onclick="lfActSave()"><i class="ri-bookmark-line"></i><span>Save post</span></button>
            <button type="button" class="lf-act-item" onclick="lfActDownload()"><i class="ri-download-2-line"></i><span>Download</span></button>
            <button type="button" class="lf-act-item" id="lfActReport" onclick="lfActShowPane('report')"><i class="ri-flag-line"></i><span>Report</span></button>
            <button type="button" class="lf-act-item danger" id="lfActDelete" onclick="lfActDelete()"><i class="ri-delete-bin-line"></i><span>Delete video</span></button>
        </div>

        <!-- step 2: report -->
        <div class="lf-act-pane" id="lfActReportPane" style="display:none;">
            <div class="lf-act-head">
                <button type="button" class="lf-act-back" onclick="lfActShowPane('main')" aria-label="Back"><i class="ri-arrow-left-s-line"></i></button>
                <div class="lf-act-title">Report video</div>
                <button type="button" class="lf-act-close" onclick="lfCloseActions()" aria-label="Close"><i class="ri-close-line"></i></button>
            </div>
            <div class="lf-act-sub">Why are you reporting this video?</div>
            <div class="lf-reasons" id="lfReasons"></div>
            <textarea class="lf-report-details" id="lfReportDetails" maxlength="<?php echo LF_MAX_REPORT_DETAILS; ?>" placeholder="Add more details (optional)"></textarea>
            <button type="button" class="gc-btn" id="lfReportSubmit" onclick="lfSubmitReport()" disabled><span class="gc-btn-label" id="lfReportSubmitLabel"><i class="ri-flag-line"></i> Send report</span></button>
        </div>

        <button type="button" class="lf-act-cancel" onclick="lfCloseActions()">Close</button>
    </div>
</div>

<div id="lfToast" role="status" aria-live="polite"><i></i><span></span></div>


<script>
/* keep the theme in sync if it is switched in another tab */
window.addEventListener('storage',function(e){
    if(e.key!=='dz_theme') return;
    var light=e.newValue==='light';
    if(light) document.documentElement.setAttribute('data-theme','light'); else document.documentElement.removeAttribute('data-theme');
    var mt=document.getElementById('metaTheme'); if(mt) mt.setAttribute('content', light?'#f4f5fb':'#010218');
});

var LF_CFG = <?php echo json_encode($lf_config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var LF = { total: LF_CFG.total, offset: LF_CFG.offset, hasMore: LF_CFG.hasMore, loading: false };

/* ══════════════════════════════════════════════════════════════
   SMALL HELPERS
   ══════════════════════════════════════════════════════════════ */
function $(id){ return document.getElementById(id); }

/* X: go back, or to the profile if there is no history */
function acClose(){
    if (document.referrer && document.referrer.indexOf(window.location.host) !== -1) history.back();
    else location.href = 'profile.php?user_id=' + LF_CFG.profileId;
}

function lfEsc(s){
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
function lfFmtBytes(b){
    if (b >= 1073741824) return (b / 1073741824).toFixed(1).replace(/\.0$/, '') + ' GB';
    if (b >= 1048576)    return (b / 1048576).toFixed(1).replace(/\.0$/, '') + ' MB';
    if (b >= 1024)       return Math.round(b / 1024) + ' KB';
    return b + ' B';
}
function lfFmtDuration(sec){
    sec = Math.max(0, Math.floor(sec));
    var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
    var pad = function(n){ return (n < 10 ? '0' : '') + n; };
    return h > 0 ? (h + ':' + pad(m) + ':' + pad(s)) : (m + ':' + pad(s));
}

var lfToastTimer = null;
function lfToast(msg, type){
    var t = $('lfToast');
    t.className = type || '';
    t.querySelector('i').className = type === 'error' ? 'ri-error-warning-line' : 'ri-check-line';
    t.querySelector('span').textContent = msg;
    void t.offsetWidth;
    t.classList.add('show');
    clearTimeout(lfToastTimer);
    lfToastTimer = setTimeout(function(){ t.classList.remove('show'); }, 2600);
}

function lfSetTotal(n){
    LF.total = Math.max(0, n);
    var empty = $('lfEmpty');
    if (empty) empty.classList.toggle('show', LF.total === 0 && !$('lfGrid').children.length);
}
lfSetTotal(LF.total);

function lfAvatarFail(img){
    var p = img.parentElement;
    if (p) p.innerHTML = '<i class="ri-user-3-line"></i>';
}

/* ══════════════════════════════════════════════════════════════
   THUMBNAIL VIDEO PREVIEW
   Desktop: keep the mouse over a thumbnail for 2 seconds.
   Phone / tablet: press and hold a thumbnail for 2 seconds.
   The preview is muted, loops the first 30 seconds, and stops as
   soon as the mouse leaves. Works for cards added later too.
   ══════════════════════════════════════════════════════════════ */
var PV_DELAY       = 2000;
var PV_MAX_SECONDS = 30;
var PV_MOVE_LIMIT  = 14;
var PV = { timer: null, thumb: null, video: null, mute: null, active: false, touchOrigin: false,
           lastTouch: 0, suppressUntil: 0, x: 0, y: 0 };

function lfPreviewStop(){
    clearTimeout(PV.timer); PV.timer = null;
    if (PV.video) {
        try { PV.video.pause(); } catch (err) {}
        PV.video.removeAttribute('src');
        try { PV.video.load(); } catch (err) {}
        PV.video.remove();
    }
    if (PV.mute) PV.mute.remove();
    if (PV.thumb) PV.thumb.classList.remove('previewing');
    PV.video = null; PV.mute = null; PV.thumb = null; PV.active = false; PV.touchOrigin = false;
}

function lfPreviewSchedule(thumb){
    lfPreviewStop();
    PV.thumb = thumb;
    PV.timer = setTimeout(function(){ lfPreviewStart(thumb); }, PV_DELAY);
}

function lfPreviewMuteIcon(){
    if (!PV.mute || !PV.video) return;
    var m = PV.video.muted;
    PV.mute.querySelector('i').className = m ? 'ri-volume-mute-fill' : 'ri-volume-up-fill';
    PV.mute.setAttribute('aria-label', m ? 'Unmute preview' : 'Mute preview');
}
function lfPreviewToggleMute(){
    if (!PV.video) return;
    PV.video.muted = !PV.video.muted;
    if (!PV.video.muted) {
        PV.video.volume = 1;
        var pp = PV.video.play();
        if (pp && typeof pp.catch === 'function') pp.catch(function(){});
    }
    lfPreviewMuteIcon();
}

function lfPreviewStart(thumb){
    PV.timer = null;
    if (PV.thumb !== thumb) return;
    var card = thumb.closest('.lf-card');
    var src  = card ? card.getAttribute('data-video') : '';
    if (!src) { lfPreviewStop(); return; }

    var v = document.createElement('video');
    v.className = 'lf-thumb-vid';
    v.muted = true; v.defaultMuted = true; v.loop = true; v.controls = false;
    v.playsInline = true; v.disablePictureInPicture = true; v.preload = 'auto';
    v.setAttribute('muted', ''); v.setAttribute('playsinline', ''); v.setAttribute('webkit-playsinline', '');
    v.src = src;

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'lf-preview-mute';
    btn.innerHTML = '<i class="ri-volume-mute-fill"></i>';
    btn.setAttribute('aria-label', 'Unmute preview');

    var before = thumb.querySelector('.lf-duration');
    thumb.insertBefore(v, before);
    thumb.insertBefore(btn, before);
    thumb.classList.add('previewing');
    PV.video = v; PV.mute = btn; PV.active = true;

    v.addEventListener('playing', function(){ v.classList.add('ready'); btn.classList.add('show'); });
    v.addEventListener('timeupdate', function(){ if (v.currentTime > PV_MAX_SECONDS) v.currentTime = 0; });
    v.addEventListener('error', function(){ if (PV.video === v) lfPreviewStop(); });

    var p = v.play();
    if (p && typeof p.catch === 'function') {
        p.catch(function(){ if (PV.video === v) lfPreviewStop(); });
    }
}

function lfThumbOf(el){
    return (el && el.closest) ? el.closest('.lf-thumb') : null;
}

/* mouse: hover */
document.addEventListener('mouseover', function(e){
    if (Date.now() - PV.lastTouch < 1200) return;
    var t = lfThumbOf(e.target);
    if (!t || t === PV.thumb) return;
    lfPreviewSchedule(t);
});
document.addEventListener('mouseout', function(e){
    if (!PV.thumb || Date.now() - PV.lastTouch < 1200) return;
    if (lfThumbOf(e.target) !== PV.thumb) return;
    if (e.relatedTarget && PV.thumb.contains(e.relatedTarget)) return;
    lfPreviewStop();
});

/* touch: press and hold for 2 seconds. Once it plays, it KEEPS playing after you let go. */
document.addEventListener('touchstart', function(e){
    PV.lastTouch = Date.now();
    var t = (e.touches.length === 1) ? lfThumbOf(e.target) : null;
    if (!t) { lfPreviewStop(); return; }
    PV.x = e.touches[0].clientX; PV.y = e.touches[0].clientY;
    if (PV.active && t === PV.thumb) return;
    lfPreviewSchedule(t);
    PV.touchOrigin = true;
}, { passive: true });
document.addEventListener('touchmove', function(e){
    PV.lastTouch = Date.now();
    if (!PV.thumb) return;
    var tt = e.touches[0];
    if (Math.abs(tt.clientX - PV.x) > PV_MOVE_LIMIT || Math.abs(tt.clientY - PV.y) > PV_MOVE_LIMIT) lfPreviewStop();
}, { passive: true });
document.addEventListener('touchend', function(){
    PV.lastTouch = Date.now();
    if (!PV.touchOrigin) return;
    PV.touchOrigin = false;
    if (PV.active) PV.suppressUntil = Date.now() + 700;
    else lfPreviewStop();
});
document.addEventListener('touchcancel', function(){
    PV.lastTouch = Date.now();
    lfPreviewStop();
});

document.addEventListener('contextmenu', function(e){
    if (lfThumbOf(e.target) && Date.now() - PV.lastTouch < 4000) e.preventDefault();
});

document.addEventListener('click', function(e){
    if (!lfThumbOf(e.target)) return;
    if (Date.now() < PV.suppressUntil) { e.stopPropagation(); e.preventDefault(); return; }
    if (e.target.closest('.lf-preview-mute')) {
        e.stopPropagation(); e.preventDefault();
        lfPreviewToggleMute();
        return;
    }
    lfPreviewStop();
}, true);

document.addEventListener('scroll', function(){ if (PV.thumb) lfPreviewStop(); }, { passive: true, capture: true });
document.addEventListener('visibilitychange', function(){ if (document.hidden) lfPreviewStop(); });

/* ══════════════════════════════════════════════════════════════
   THE GRID — delete, infinite scroll
   ══════════════════════════════════════════════════════════════ */

/* The long form modal is defined by longform_modal.php (loaded after this
   script). This stub is replaced by the real function once it loads. */
function openLongformModal(postId){
}

/* ══════════════════════════════════════════════════════════════
   POST ACTIONS — share, copy link, save, download, report, delete
   ══════════════════════════════════════════════════════════════ */
var ACT = { id: 0, card: null, busy: false, reason: '' };

function lfPost(action, fields){
    var fd = new FormData();
    fd.append(action, '1');
    fd.append('csrf_token', LF_CFG.csrf);
    Object.keys(fields || {}).forEach(function(k){ fd.append(k, fields[k]); });
    return fetch(LF_CFG.endpoint, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
        .then(function(r){ return r.json(); });
}

function lfCopyText(text, okMsg){
    var ok  = function(){ lfToast(okMsg || 'Copied', 'success'); };
    var bad = function(){ lfToast('Could not copy the link', 'error'); };
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(ok).catch(bad);
        return;
    }
    var ta = document.createElement('textarea');
    ta.value = text; ta.style.cssText = 'position:fixed;top:-9999px;opacity:0;';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); ok(); } catch (err) { bad(); }
    document.body.removeChild(ta);
}

function lfActUrl(){
    return new URL(ACT.card.getAttribute('data-url') || '', window.location.href).href;
}

function lfOpenActions(id, e){
    if (e) { e.stopPropagation(); e.preventDefault(); }
    var card = null, src = e && (e.currentTarget || e.target);
    if (src && src.closest) card = src.closest('.lf-card');
    if (!card) card = $('lf-card-' + id);
    if (!card) return;
    ACT.id = id; ACT.card = card; ACT.busy = false;

    var own = card.getAttribute('data-own') === '1';
    $('lfActTitle').textContent = card.getAttribute('data-title') || '';
    var img = card.querySelector('.lf-thumb-img');
    $('lfActThumb').style.backgroundImage = img ? 'url("' + img.src.replace(/"/g, '%22') + '")' : 'none';

    $('lfActReport').style.display = own ? 'none' : '';
    $('lfActDelete').style.display = own ? '' : 'none';

    lfActRefreshSave();
    lfActShowPane('main');
    var panel = $('lfActPanel');
    panel.style.transition = ''; panel.style.transform = '';
    $('lfActOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function lfCloseActions(){
    $('lfActOverlay').classList.remove('open');
    var up = $('uploadModal'), sv = $('savedModal');
    if ((!up || !up.classList.contains('active')) && (!sv || !sv.classList.contains('active'))) document.body.style.overflow = '';
}

function lfActShowPane(name){
    $('lfActMain').style.display = (name === 'main') ? '' : 'none';
    $('lfActReportPane').style.display = (name === 'report') ? '' : 'none';
    if (name === 'report') {
        ACT.reason = '';
        $('lfReportDetails').value = '';
        $('lfReportSubmit').disabled = true;
        document.querySelectorAll('#lfReasons .lf-reason').forEach(function(r){ r.classList.remove('selected'); });
    }
    $('lfActPanel').scrollTop = 0;
}

function lfActRefreshSave(){
    var saved = ACT.card && ACT.card.getAttribute('data-saved') === '1';
    var btn = $('lfActSave');
    btn.classList.toggle('is-on', !!saved);
    btn.querySelector('i').className = saved ? 'ri-bookmark-fill' : 'ri-bookmark-line';
    btn.querySelector('span').textContent = saved ? 'Unsave post' : 'Save post';
}

function lfRecordShare(id, type){
    lfPost('share_longform', { post_id: id, share_type: type }).catch(function(){});
}

function lfActShare(){
    var id = ACT.id, url = lfActUrl(), title = ACT.card.getAttribute('data-title') || 'Video';
    if (navigator.share) {
        navigator.share({ title: title, url: url })
            .then(function(){ lfRecordShare(id, 'native'); })
            .catch(function(){});
    } else {
        lfCopyText(url, 'Link copied. Paste it anywhere to share.');
        lfRecordShare(id, 'copy_link');
    }
    lfCloseActions();
}

function lfActCopy(){
    lfCopyText(lfActUrl(), 'Link copied');
    lfRecordShare(ACT.id, 'copy_link');
    lfCloseActions();
}

function lfActSave(){
    if (ACT.busy) return;
    ACT.busy = true;
    var card = ACT.card;
    lfPost('toggle_save_longform', { post_id: ACT.id })
        .then(function(d){
            ACT.busy = false;
            if (!d || !d.success) { lfToast((d && d.error) || 'Could not update your saved posts.', 'error'); return; }
            var savedNow = d.saved ? '1' : '0';
            document.querySelectorAll('.lf-card[data-post-id="' + ACT.id + '"]').forEach(function(c){ c.setAttribute('data-saved', savedNow); });
            card.setAttribute('data-saved', savedNow);
            lfCloseActions();
            if (!d.saved && card.closest('#savedList') && typeof lfSavedRemoveCard === 'function') lfSavedRemoveCard(card);
            lfToast(d.saved ? 'Post saved' : 'Removed from saved', 'success');
        })
        .catch(function(){ ACT.busy = false; lfToast('Could not update your saved posts.', 'error'); });
}

function lfActDownload(){
    var card = ACT.card, path = card.getAttribute('data-video');
    if (!path) { lfToast('This video is not available to download.', 'error'); return; }
    var ext  = (path.match(/\.([a-z0-9]+)$/i) || [])[1] || 'mp4';
    var name = (card.getAttribute('data-title') || 'video').replace(/[\\\/:*"<>|]+/g, '').trim().slice(0, 80) || 'video';
    var a = document.createElement('a');
    a.href = path; a.download = name + '.' + ext; a.rel = 'noopener';
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
    lfCloseActions();
    lfToast('Download started', 'success');
}

function lfActDelete(){
    var id = ACT.id;
    lfCloseActions();
    lfDeletePost(id);
}

/* report step */
(function(){
    var box = $('lfReasons');
    if (!box || !LF_CFG.reasons) return;
    Object.keys(LF_CFG.reasons).forEach(function(key){
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'lf-reason';
        b.innerHTML = '<span class="lf-radio"></span><span>' + lfEsc(LF_CFG.reasons[key]) + '</span>';
        b.addEventListener('click', function(){
            ACT.reason = key;
            box.querySelectorAll('.lf-reason').forEach(function(x){ x.classList.toggle('selected', x === b); });
            $('lfReportSubmit').disabled = false;
        });
        box.appendChild(b);
    });
})();

function lfSubmitReport(){
    if (ACT.busy || !ACT.reason) return;
    ACT.busy = true;
    var btn = $('lfReportSubmit'), label = $('lfReportSubmitLabel');
    btn.disabled = true;
    label.innerHTML = '<i class="ri-loader-4-line" style="animation:lf-spin 1s linear infinite"></i> Sending…';
    lfPost('report_longform', { post_id: ACT.id, reason: ACT.reason, details: $('lfReportDetails').value.trim() })
        .then(function(d){
            ACT.busy = false;
            label.innerHTML = '<i class="ri-flag-line"></i> Send report';
            if (d && d.success) {
                lfCloseActions();
                lfToast('Report sent. Thank you.', 'success');
            } else {
                btn.disabled = false;
                lfToast((d && d.error) || 'Could not send your report.', 'error');
            }
        })
        .catch(function(){
            ACT.busy = false; btn.disabled = false;
            label.innerHTML = '<i class="ri-flag-line"></i> Send report';
            lfToast('Could not send your report.', 'error');
        });
}

/* swipe the sheet down to close it (small screens) */
(function(){
    var panel = $('lfActPanel');
    var zones = [$('lfActHandle'), $('lfActHead')];
    var startY = 0, dy = 0, dragging = false;
    function isSheet(){ return window.matchMedia('(max-width:790px)').matches; }
    function start(e){ if (!isSheet()) return; dragging = true; dy = 0; startY = e.touches[0].clientY; panel.style.transition = 'none'; }
    function move(e){ if (!dragging) return; dy = Math.max(0, e.touches[0].clientY - startY); panel.style.transform = 'translateY(' + dy + 'px)'; }
    function end(){
        if (!dragging) return;
        dragging = false;
        panel.style.transition = 'transform .22s ease';
        if (dy > 90) {
            panel.style.transform = 'translateY(100%)';
            setTimeout(function(){ lfCloseActions(); panel.style.transition = ''; panel.style.transform = ''; }, 200);
        } else {
            panel.style.transform = '';
        }
    }
    zones.forEach(function(z){
        if (!z) return;
        z.addEventListener('touchstart', start, { passive: true });
        z.addEventListener('touchmove', move, { passive: true });
        z.addEventListener('touchend', end);
        z.addEventListener('touchcancel', end);
    });
})();

document.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && $('lfActOverlay').classList.contains('open')) lfCloseActions();
});

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
            var card = $('lf-card-' + id);
            if (card) {
                card.classList.add('removing');
                setTimeout(function(){
                    card.remove();
                    LF.offset = Math.max(0, LF.offset - 1);
                    lfSetTotal(LF.total - 1);
                }, 260);
            } else {
                lfSetTotal(LF.total - 1);
            }
            if (typeof lfSavedRemoveCard === 'function') {
                document.querySelectorAll('#savedList .lf-card[data-post-id="' + id + '"]').forEach(lfSavedRemoveCard);
            }
            lfToast('Video deleted', 'success');
        })
        .catch(function(){ lfToast('Could not delete this video.', 'error'); });
}

/* Infinite scroll */
function lfLoadMore(){
    if (LF.loading || !LF.hasMore) return;
    LF.loading = true;
    fetch(LF_CFG.endpoint + '&lf_more=1&offset=' + LF.offset, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r){ return r.json(); })
        .then(function(d){
            LF.loading = false;
            if (!d || !d.success) return;
            $('lfGrid').insertAdjacentHTML('beforeend', d.html);
            LF.offset += d.count;
            LF.hasMore = d.has_more;
            var sent = $('lfSentinel');
            sent.classList.toggle('show', LF.hasMore);
            if (LF.hasMore && sent.getBoundingClientRect().top < window.innerHeight + 400) lfLoadMore();
        })
        .catch(function(){ LF.loading = false; });
}
(function(){
    var sentinel = $('lfSentinel');
    if (!sentinel || !('IntersectionObserver' in window)) return;
    new IntersectionObserver(function(entries){
        if (entries[0].isIntersecting) lfLoadMore();
    }, { rootMargin: '400px 0px' }).observe(sentinel);
})();

<?php if ($is_own_profile): ?>
/* ══════════════════════════════════════════════════════════════
   UPLOAD MODAL
   ══════════════════════════════════════════════════════════════ */
var UP = { file: null, duration: 0, valid: false, checking: false, thumb: null, thumbUrl: null, xhr: null, busy: false };

function openUploadModal(){
    lfPreviewStop();
    $('uploadModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function lfFormDirty(){
    return !!(UP.file || UP.thumb || $('titleInput').value.trim() || $('descInput').value.trim());
}

function closeUploadModal(force){
    if (UP.busy) {
        if (!confirm('Cancel this upload?')) return;
        if (UP.xhr) UP.xhr.abort();
        return;
    }
    if (!force && lfFormDirty() && !confirm('Discard this upload?')) return;
    $('uploadModal').classList.remove('active');
    document.body.style.overflow = '';
    lfResetUpload();
}

function lfShowError(msg){
    $('lfFormErrorText').textContent = msg;
    var box = $('lfFormError');
    box.classList.remove('show'); void box.offsetWidth; box.classList.add('show');
    $('uploadModal').scrollTo({ top: 0, behavior: 'smooth' });
}
function lfClearError(){ $('lfFormError').classList.remove('show'); }

/* ── video ── */
function lfVideoStatus(kind, text){
    var box = $('videoStatus');
    if (!kind) { box.className = 'video-status'; return; }
    var icons = { ok: 'ri-checkbox-circle-fill', bad: 'ri-error-warning-fill', wait: 'ri-loader-4-line' };
    box.className = 'video-status show ' + kind;
    box.querySelector('i').className = icons[kind];
    box.querySelector('span').textContent = text;
}

function lfReadDuration(file){
    return new Promise(function(resolve, reject){
        var url = URL.createObjectURL(file);
        var v = document.createElement('video');
        var finished = false;
        function finish(err, dur){
            if (finished) return;
            finished = true;
            clearTimeout(timer);
            URL.revokeObjectURL(url);
            v.removeAttribute('src'); v.load();
            err ? reject(err) : resolve(dur);
        }
        var timer = setTimeout(function(){ finish(new Error('timeout')); }, 20000);
        v.preload = 'metadata';
        v.muted = true;
        v.onloadedmetadata = function(){
            if (v.duration === Infinity) {
                v.currentTime = 1e101;
                v.ontimeupdate = function(){ v.ontimeupdate = null; finish(null, v.duration); };
                return;
            }
            finish(null, v.duration);
        };
        v.onerror = function(){ finish(new Error('unreadable')); };
        v.src = url;
    });
}

function lfHandleVideoFile(file){
    if (!file || UP.busy) return;
    lfClearError();

    if (!/\.(mp4|webm|mov|m4v)$/i.test(file.name)) {
        lfVideoStatus('bad', 'Choose an MP4, WebM, MOV or M4V video.');
        return;
    }
    if (file.size > LF_CFG.maxVideoBytes) {
        lfVideoStatus('bad', 'That video is ' + lfFmtBytes(file.size) + '. The limit is ' + LF_CFG.maxVideoLabel + '.');
        return;
    }

    UP.file = file; UP.duration = 0; UP.valid = false; UP.checking = true;
    var card = $('videoCard');
    card.className = 'video-card show is-checking';
    $('vcIcon').className = 'ri-loader-4-line';
    $('vcName').textContent = file.name;
    $('vcMeta').textContent = lfFmtBytes(file.size);
    $('videoDrop').style.display = 'none';
    lfVideoStatus('wait', 'Reading video length…');

    lfReadDuration(file).then(function(dur){
        if (UP.file !== file) return;
        UP.checking = false;
        UP.duration = dur;
        lfRefreshThumbBadge();
        $('vcMeta').textContent = lfFmtBytes(file.size) + ' \u00b7 ' + lfFmtDuration(dur);
        if (dur >= LF_CFG.minDuration) {
            UP.valid = true;
            card.className = 'video-card show is-valid';
            $('vcIcon').className = 'ri-film-line';
            lfVideoStatus('ok', 'Length ' + lfFmtDuration(dur) + ' — this video qualifies for long form.');
        } else {
            card.className = 'video-card show is-invalid';
            $('vcIcon').className = 'ri-film-line';
            lfVideoStatus('bad', 'This video is only ' + lfFmtDuration(dur) + '. Long form videos must be at least ' + LF_CFG.minClock + '. Remove it and choose a longer one.');
        }
    }).catch(function(){
        if (UP.file !== file) return;
        UP.checking = false;
        card.className = 'video-card show is-invalid';
        $('vcIcon').className = 'ri-film-line';
        lfVideoStatus('bad', 'We could not read this video. Try exporting it as an MP4 (H.264).');
    });
}

function lfClearVideo(){
    if (UP.busy) return;
    UP.file = null; UP.duration = 0; UP.valid = false; UP.checking = false;
    lfRefreshThumbBadge();
    $('videoCard').className = 'video-card';
    $('videoDrop').style.display = '';
    lfVideoStatus(null);
}

(function(){
    var input = $('videoInput'), drop = $('videoDrop');
    input.addEventListener('change', function(){
        lfHandleVideoFile(input.files && input.files[0]);
        input.value = '';
    });
    ['dragenter', 'dragover'].forEach(function(ev){
        drop.addEventListener(ev, function(e){ e.preventDefault(); drop.classList.add('dragover'); });
    });
    ['dragleave', 'drop'].forEach(function(ev){
        drop.addEventListener(ev, function(e){ e.preventDefault(); drop.classList.remove('dragover'); });
    });
    drop.addEventListener('drop', function(e){
        var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
        if (f) lfHandleVideoFile(f);
    });
})();

/* ── thumbnail ── */
function lfSetThumb(file){
    lfClearError();
    if (UP.thumbUrl) { URL.revokeObjectURL(UP.thumbUrl); UP.thumbUrl = null; }
    var box = $('thumbPreview');
    if (!file) {
        UP.thumb = null;
        box.innerHTML = '<div class="thumb-preview-empty"><i class="ri-image-add-line"></i><span>Your thumbnail appears here</span></div>';
        $('thumbBtnText').textContent = 'Choose Thumbnail';
        return;
    }
    if (!/\.(jpe?g|png|webp)$/i.test(file.name) && !/^image\/(jpeg|png|webp)$/.test(file.type)) {
        lfShowError('The thumbnail must be a JPG, PNG or WebP picture.');
        return;
    }
    if (file.size > LF_CFG.maxThumbBytes) {
        lfShowError('The thumbnail must be smaller than ' + LF_CFG.maxThumbLabel + '.');
        return;
    }
    UP.thumb = file;
    UP.thumbUrl = URL.createObjectURL(file);
    box.innerHTML = '<img src="' + UP.thumbUrl + '" alt="Thumbnail preview">' +
        '<span class="lf-duration" id="thumbBadge" style="bottom:8px;"></span>';
    $('thumbBtnText').textContent = 'Change Thumbnail';
    lfRefreshThumbBadge();
}
function lfRefreshThumbBadge(){
    var b = $('thumbBadge');
    if (!b) return;
    if (UP.duration) { b.textContent = lfFmtDuration(UP.duration); b.style.display = ''; }
    else { b.style.display = 'none'; }
}
$('thumbInput').addEventListener('change', function(){
    var f = this.files && this.files[0];
    if (f) lfSetThumb(f);
    this.value = '';
});

/* ── title + description (with live red links / light red hashtags) ── */
function lfUpdateCount(el, len, max){
    el.textContent = len + '/' + max;
    el.classList.toggle('near', len >= max * 0.9);
}
$('titleInput').addEventListener('input', function(){
    lfUpdateCount($('titleCount'), this.value.length, LF_CFG.maxTitle);
});

function lfHighlight(text){
    var re = /(https?:\/\/[^\s]+|www\.[^\s]+)|(^|[^\p{L}\p{N}_\/])(#[\p{L}\p{N}_]+)/gu;
    var out = '', last = 0, m;
    while ((m = re.exec(text)) !== null) {
        if (m[1]) {
            var url = m[1], trail = '';
            var tm = url.match(/[.,;:!?)\]}'"]+$/);
            if (tm) { trail = tm[0]; url = url.slice(0, url.length - trail.length); }
            out += lfEsc(text.slice(last, m.index)) + '<span class="hl-link">' + lfEsc(url) + '</span>' + lfEsc(trail);
        } else {
            out += lfEsc(text.slice(last, m.index)) + lfEsc(m[2]) + '<span class="hl-tag">' + lfEsc(m[3]) + '</span>';
        }
        last = m.index + m[0].length;
    }
    return out + lfEsc(text.slice(last));
}
(function(){
    var ta = $('descInput'), bd = $('descBackdrop');
    function sync(){
        bd.innerHTML = lfHighlight(ta.value) + '\n';
        ta.style.height = 'auto';
        ta.style.height = Math.min(ta.scrollHeight, 240) + 'px';
        bd.scrollTop = ta.scrollTop;
        lfUpdateCount($('descCount'), ta.value.length, LF_CFG.maxDesc);
    }
    ta.addEventListener('input', sync);
    ta.addEventListener('scroll', function(){ bd.scrollTop = ta.scrollTop; });
    window.lfSyncDesc = sync;
    sync();
})();

/* ── reset ── */
function lfResetUpload(){
    lfClearVideo();
    lfSetThumb(null);
    $('titleInput').value = '';
    $('descInput').value = '';
    $('hideLikesInput').checked = false;
    $('hideDislikesInput').checked = false;
    $('hideSavesInput').checked = false;
    $('hideSharesInput').checked = false;
    $('commentsOffInput').checked = false;
    lfUpdateCount($('titleCount'), 0, LF_CFG.maxTitle);
    window.lfSyncDesc();
    lfClearError();
    lfSetBusy(false);
}

/* ── submit ── */
function lfSetBusy(busy, pct, label){
    UP.busy = busy;
    var btn = $('postBtn');
    btn.disabled = busy;
    btn.classList.toggle('uploading', busy);
    $('cancelNote').classList.toggle('show', busy);
    $('postBtnFill').style.width = busy ? (pct || 0) + '%' : '0';
    $('postBtnLabel').innerHTML = busy
        ? '<i class="ri-loader-4-line" style="animation:lf-spin 1s linear infinite"></i> ' + lfEsc(label || 'Uploading…')
        : '<i class="ri-send-plane-line"></i> Post Video';
}

function lfSubmitUpload(){
    if (UP.busy) return;
    lfClearError();

    var title = $('titleInput').value.trim();
    if (!UP.file)     return lfShowError('Choose a video to upload.');
    if (UP.checking)  return lfShowError('We are still reading your video. Try again in a moment.');
    if (!UP.valid)    return lfShowError('This video is shorter than ' + LF_CFG.minLabel + ' or could not be read. Remove it and choose another.');
    if (!UP.thumb)    return lfShowError('A thumbnail picture is required.');
    if (!title)       { $('titleInput').focus(); return lfShowError('Give your video a title.'); }

    var fd = new FormData();
    fd.append('create_longform', '1');
    fd.append('csrf_token', LF_CFG.csrf);
    fd.append('title', title);
    fd.append('description', $('descInput').value);
    fd.append('video_duration', String(UP.duration));
    if ($('hideLikesInput').checked)    fd.append('hide_like_count', '1');
    if ($('hideDislikesInput').checked) fd.append('hide_dislike_count', '1');
    if ($('hideSavesInput').checked)    fd.append('hide_save_count', '1');
    if ($('hideSharesInput').checked)   fd.append('hide_share_count', '1');
    if ($('commentsOffInput').checked)  fd.append('comments_disabled', '1');
    fd.append('thumbnail', UP.thumb);
    fd.append('video', UP.file);

    var xhr = new XMLHttpRequest();
    UP.xhr = xhr;
    lfSetBusy(true, 0, 'Uploading 0%');

    xhr.open('POST', LF_CFG.endpoint);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

    xhr.upload.onprogress = function(e){
        if (!e.lengthComputable) return;
        var pct = Math.round((e.loaded / e.total) * 100);
        lfSetBusy(true, pct, pct >= 100 ? 'Finishing up…' : 'Uploading ' + pct + '%');
    };
    xhr.onload = function(){
        var d = null;
        try { d = JSON.parse(xhr.responseText); } catch (err) {}
        UP.xhr = null;
        if (!d || !d.success) {
            lfSetBusy(false);
            lfShowError((d && d.error) || 'The upload failed. Please try again.');
            return;
        }
        lfSetBusy(false);
        var grid = $('lfGrid');
        grid.insertAdjacentHTML('afterbegin', d.html);
        if (grid.firstElementChild) grid.firstElementChild.classList.add('just-added');
        LF.offset += 1;
        lfSetTotal(LF.total + 1);
        $('lfEmpty').classList.remove('show');
        closeUploadModal(true);
        window.scrollTo({ top: 0, behavior: 'smooth' });
        lfToast('Video posted', 'success');
    };
    xhr.onerror = function(){
        UP.xhr = null;
        lfSetBusy(false);
        lfShowError('The connection dropped during the upload. Check your internet and try again.');
    };
    xhr.onabort = function(){
        UP.xhr = null;
        lfSetBusy(false);
        lfToast('Upload cancelled', 'error');
    };
    xhr.send(fd);
}

window.addEventListener('beforeunload', function(e){
    if (UP.busy) { e.preventDefault(); e.returnValue = ''; }
});

document.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && $('uploadModal').classList.contains('active')) closeUploadModal();
});

/* ══════════════════════════════════════════════════════════════
   SAVED VIDEOS MODAL — everything you saved, same cards and same
   behaviour as the page. Loaded fresh every time it opens.
   ══════════════════════════════════════════════════════════════ */
var SV = { offset: 0, done: false, loading: false, seq: 0 };

function lfSavedRefreshEmpty(){
    var empty = !$('savedList').children.length && SV.done && !SV.loading;
    $('savedEmpty').classList.toggle('show', empty);
}

function lfSavedReset(){
    SV.seq++;
    SV.offset = 0; SV.done = false; SV.loading = false;
    $('savedList').innerHTML = '';
    $('savedEmpty').classList.remove('show');
    $('savedSentinel').classList.add('show');
}

function lfSavedLoad(){
    if (SV.loading || SV.done) return;
    SV.loading = true;
    var seq = SV.seq, sent = $('savedSentinel');
    sent.classList.add('show');
    fetch(LF_CFG.endpoint + '&lf_saved=1&offset=' + SV.offset, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (seq !== SV.seq) return;
            SV.loading = false;
            if (!d || !d.success) {
                sent.classList.remove('show');
                lfToast((d && d.error) || 'Could not load your saved videos.', 'error');
                return;
            }
            $('savedList').insertAdjacentHTML('beforeend', d.html);
            SV.offset += d.count;
            SV.done = !d.has_more;
            sent.classList.toggle('show', !SV.done);
            lfSavedRefreshEmpty();
            if (!SV.done && sent.getBoundingClientRect().top < window.innerHeight + 400) lfSavedLoad();
        })
        .catch(function(){
            if (seq !== SV.seq) return;
            SV.loading = false;
            sent.classList.remove('show');
            lfToast('Could not load your saved videos.', 'error');
        });
}

function lfSavedRemoveCard(card){
    if (!card || card.classList.contains('removing')) return;
    card.classList.add('removing');
    setTimeout(function(){
        card.remove();
        SV.offset = Math.max(0, SV.offset - 1);
        lfSavedRefreshEmpty();
    }, 260);
}

function openSavedModal(){
    lfPreviewStop();
    lfSavedReset();
    var m = $('savedModal');
    m.classList.add('active');
    m.scrollTop = 0;
    document.body.style.overflow = 'hidden';
    lfSavedLoad();
}

function closeSavedModal(){
    lfPreviewStop();
    $('savedModal').classList.remove('active');
    document.body.style.overflow = '';
    lfSavedReset();
    $('savedSentinel').classList.remove('show');
}

(function(){
    var sent = $('savedSentinel'), root = $('savedModal');
    if (!sent || !root || !('IntersectionObserver' in window)) return;
    new IntersectionObserver(function(entries){
        if (entries[0].isIntersecting && root.classList.contains('active')) lfSavedLoad();
    }, { root: root, rootMargin: '400px 0px' }).observe(sent);
})();
<?php endif; ?>

/* ══════════════════════════════════════════════════════════════
   PAGE LOAD
   ══════════════════════════════════════════════════════════════ */
(function(){
    function hideSkeleton(){
        var loader = $('skeletonLoader');
        if (!loader) return;
        loader.classList.add('hidden');
        setTimeout(function(){ loader.style.display = 'none'; }, 500);
    }
    if (document.readyState === 'complete') {
        setTimeout(hideSkeleton, 350);
    } else {
        window.addEventListener('load', function(){ setTimeout(hideSkeleton, 350); });
    }
    setTimeout(hideSkeleton, 3000);
})();

/* A shared link (?post=ID) opens the long form modal */
if (LF_CFG.openPost) { openLongformModal(LF_CFG.openPost); }
</script>

<?php lfm_render(); ?>
</body>
</html>