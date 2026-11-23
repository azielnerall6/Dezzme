<?php
/* ══════════════════════════════════════════════════════════════════
   profile_lf_embed.php — runs the LONG FORM MODAL inside profile.php
   (so tapping a video in the Videos tab opens it right on the profile
   page instead of jumping to profile_longform.php).

   Put this file NEXT TO profile.php, together with longform_modal.php
   (the same file profile_longform.php already uses).

   profile.php loads it with:   require __DIR__ . '/profile_lf_embed.php';
   and prints the modal with:   lf_embed_render();
   ══════════════════════════════════════════════════════════════════ */
if (!isset($conn, $current_user, $profile_id)) { http_response_code(403); exit('Include this file from profile.php'); }

if (!defined('LF_MIN_DURATION')) { define('LF_MIN_DURATION',   60);                      /* 1 minute, in seconds         */ }
if (!defined('LF_MAX_VIDEO_BYTES')) { define('LF_MAX_VIDEO_BYTES', 2 * 1024 * 1024 * 1024); /* 2 GB (PHP limits can be lower) */ }
if (!defined('LF_MAX_THUMB_BYTES')) { define('LF_MAX_THUMB_BYTES', 8 * 1024 * 1024);        /* 8 MB thumbnail               */ }
if (!defined('LF_MAX_TITLE')) { define('LF_MAX_TITLE',      100); }
if (!defined('LF_MAX_DESC')) { define('LF_MAX_DESC',       5000); }
if (!defined('LF_PAGE_SIZE')) { define('LF_PAGE_SIZE',      10);                      /* posts loaded per scroll      */ }
if (!defined('LF_MAX_HASHTAGS')) { define('LF_MAX_HASHTAGS',   30); }
if (!defined('LF_MAX_REPORT_DETAILS')) { define('LF_MAX_REPORT_DETAILS', 500); }
if (!defined('LF_VIDEO_DIR')) { define('LF_VIDEO_DIR',      'uploads/longform/videos/'); }
if (!defined('LF_THUMB_DIR')) { define('LF_THUMB_DIR',      'uploads/longform/thumbs/'); }

$lf_report_reasons = [
    'spam'      => 'Spam or misleading',
    'sexual'    => 'Nudity or sexual content',
    'violence'  => 'Violent or dangerous',
    'hate'      => 'Hate or harassment',
    'copyright' => 'Copyright infringement',
    'other'     => 'Something else',
];


$csrf_token = dz_csrf_token();
$is_own_profile = ($profile_id === (int)$current_user);
$owner = null;
if ($__lfo = $conn->prepare("SELECT id, username, profile_pic FROM users WHERE id=? LIMIT 1")) {
    $__lfo->bind_param("i", $profile_id); $__lfo->execute();
    $owner = $__lfo->get_result()->fetch_assoc(); $__lfo->close();
}
if (!$owner) { $owner = ['id' => $profile_id, 'username' => '', 'profile_pic' => '']; }

/* ── helpers (copied from profile_longform.php) ── */
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

require __DIR__ . '/longform_modal.php';

/* ── actions that profile_longform.php answers with POST (delete / share / save / report) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['create_longform']) || isset($_POST['delete_longform']) || isset($_POST['share_longform']) || isset($_POST['toggle_save_longform']) || isset($_POST['report_longform']))) {

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


$lf_embed_open = (int)($_GET['lfpost'] ?? 0);

function lf_embed_render() {
    global $lf_report_reasons, $profile_id, $csrf_token, $is_own_profile, $lf_embed_open;
    $cfg = [
        'csrf' => $csrf_token, 'endpoint' => 'profile.php?user_id=' . (int)$profile_id,
        'isOwn' => $is_own_profile, 'total' => 0, 'offset' => 0, 'hasMore' => false,
        'openPost' => (int)$lf_embed_open, 'profileId' => (int)$profile_id,
        'reasons' => $lf_report_reasons, 'maxReportDetails' => LF_MAX_REPORT_DETAILS,
    ];
?>
<style>
/* the modal and its menu read these (profile.php's own colours are left alone) */
.lfm-overlay, .lf-act-overlay, #lfToast{
    --m:1; --w:clamp(0px, calc(100vw - 330px), 460px);
    --fg:255,255,255; --panel:#080b28;
    --accent:#FF2D2D; --accent-soft:rgba(255,45,45,.14); --accent-border:rgba(255,45,45,.4); --accent-strong:#e01b1b; --accent-glow:rgba(255,45,45,.28); --accent-text:#ff8a8a;
    --surface:rgba(255,255,255,.05); --surface-hover:rgba(255,255,255,.08); --border:rgba(255,255,255,.14);
    --text:#ffffff; --muted:rgba(255,255,255,.45); --sub:rgba(255,255,255,.62); --divider:rgba(255,255,255,.09); --line:rgba(255,255,255,.09);
    --bg:linear-gradient(-45deg,#010218,#070924,#070924); --chip:rgba(255,255,255,.08);
    --success:#ff8a8a; --success-soft:rgba(255,138,138,.1); --success-border:rgba(255,138,138,.45); --tag:#ff9a9a;
    --pad:calc(clamp(9px, 3vw, 16px) * var(--m)); --ctl-h:calc(clamp(30px, 8vw, 36px) * var(--m)); --r:calc(5px * var(--m));
    font-family:'Poppins', sans-serif;
}
:root[data-theme="light"] .lfm-overlay, :root[data-theme="light"] .lf-act-overlay, :root[data-theme="light"] #lfToast{
    --fg:6,7,26; --text:#0b0d24; --bg:linear-gradient(-45deg,#ffffff,#f4f5fb,#f4f5fb);
    --surface:rgba(6,7,26,.05); --surface-hover:rgba(6,7,26,.08); --border:rgba(6,7,26,.16); --muted:rgba(6,7,26,.52); --sub:rgba(6,7,26,.66);
    --divider:rgba(6,7,26,.1); --line:rgba(6,7,26,.1); --chip:rgba(6,7,26,.07); --panel:#eceef5;
    --accent-soft:rgba(217,43,43,.1); --accent-border:rgba(217,43,43,.4); --accent-text:#d92b2b;
}
.lf-act-overlay *, .lfm-overlay *{ box-sizing:border-box; }
.lf-act-overlay button, #lfToast{ font-family:inherit; }
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
.lf-act-pane .gc-btn{
    position:relative; overflow:hidden;
    height:var(--ctl-h); padding:0 calc(16px * var(--m)); border-radius:var(--r); border:none;
    background:var(--accent); color:#000; font-size:calc((11.52px + .96 / 460 * var(--w)) * var(--m)); font-weight:700;
    transition:background .2s, transform .15s; width:100%; display:flex; align-items:center; justify-content:center; gap:calc(7px * var(--m));
    margin-top:calc(6px * var(--m));
}
.lf-act-pane .gc-btn:hover{ background:#ff1a1a; }
.lf-act-pane .gc-btn:active{ transform:scale(.98); }
.lf-act-pane .gc-btn:disabled{ cursor:default; opacity:.92; }
.lf-act-pane .gc-btn-fill{ position:absolute; top:0; bottom:0; left:0; width:0; background:rgba(0,0,0,.22); transition:width .2s ease; }
.lf-act-pane .gc-btn-label{ position:relative; display:flex; align-items:center; gap:calc(7px * var(--m)); }
.lf-act-pane .gc-btn.uploading{ background:var(--accent-strong); }
@keyframes lf-spin{ to{ transform:rotate(360deg); } }
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
}
[data-theme="light"] .lf-act-panel{ background:#ffffff; box-shadow:0 30px 70px rgba(6,7,26,.28); }
[data-theme="light"] .lf-act-overlay{ background:rgba(6,7,26,.45); }
/* sizes: same responsive boost as profile_longform.php (1 on desktop, 1.188 on screens up to 790px) */
@media (max-width:790px){ .lfm-overlay, .lf-act-overlay, #lfToast{ --m:1.188; } }
/* stacking: full player = same layer as the immersive post modal (999998); mini player floats just above the nav like the CTA pill.
   "html" in front makes these win over longform_modal.php's own z-index rules. */
html .lfm-overlay{ z-index:999998; --lfm-mini-bottom:calc(var(--lfe-nav-b, 0px) + 14px); }
html .lfm-overlay.mini{ z-index:999998; }
@media (max-width:768px){ html .lfm-overlay{ --lfm-mini-bottom:calc(var(--lfe-nav-b, 0px) + 8px); } html .lfm-overlay.mini{ bottom:var(--lfm-mini-bottom); } }
html .lf-act-overlay{ z-index:1000000; }
html #lfToast{ z-index:1000001; }
/* video cards in the modal's scrolling feed (copied from profile_longform.php) */
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

[data-theme="light"] .lf-thumb{ background:#e3e6f0; }
[data-theme="light"] .lf-thumb i{ color:rgba(6,7,26,.18); }
.lf-card button{ font-family:inherit; }
</style>
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
var LF_CFG = <?php echo json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var LF = { total: 0, offset: 0, hasMore: false, loading: false };
var $ = function(id){ return document.getElementById(id); };


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


/* height of a fixed bottom bar (nav) so the mini player sits right above it, like the CTA pill */
(function(){
    function navOff(){
        var off = 0, els = document.querySelectorAll('nav, .sidebar, .bottom-nav, .mobile-nav, #bottomNav, .nav-bottom');
        Array.prototype.forEach.call(els, function(el){
            if (el.id === 'rpTopbar') return;
            var cs = getComputedStyle(el), r = el.getBoundingClientRect();
            if (cs.position === 'fixed' && cs.display !== 'none' && r.height > 0 && r.height < 160 && r.bottom >= window.innerHeight - 2 && r.top > window.innerHeight / 2) off = Math.max(off, window.innerHeight - r.top);
        });
        document.documentElement.style.setProperty('--lfe-nav-b', off + 'px');
    }
    window.addEventListener('resize', navOff);
    window.addEventListener('load', navOff);
    window.addEventListener('load', function(){
        var ov = document.getElementById('lfmOverlay');
        if (ov && window.MutationObserver) new MutationObserver(navOff).observe(ov, { attributes: true, attributeFilter: ['class'] });
    });
    navOff();
})();
function lfSavedRemoveCard(){}
function lfPreviewStop(){}
/* Videos tab rows */
function dzOpenLf(id, ev){
    if (ev && (ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.button === 1)) return true;
    if (typeof openLongformModal !== 'function') return true;   /* modal not loaded: fall back to the link */
    if (ev) ev.preventDefault();
    openLongformModal(id);
    return false;
}
</script>
<?php lfm_render(); ?>
<?php
}