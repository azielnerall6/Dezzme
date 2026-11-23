<?php
/* ── hardened session (same cookie settings as profile.php) ── */
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
    session_start();
}
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$current_user = (int)$_SESSION['user_id'];

/* verified badge: dz_badge() / dz_badge_sprite() + the rule that a changed username removes the badge */
require_once __DIR__ . '/verification_common.php';
require_once __DIR__ . '/verified_badge.php';

/* view counts for posts opened in the Zeems modal on this page (same rules as the feed: dz_views.php) */
require_once __DIR__ . '/dz_views.php';
require_once __DIR__ . '/dz_privacy.php';
require_once __DIR__ . '/dz_collab.php';
if (!empty($current_user)) dz_collab_handle_request($conn, (int)$current_user);   /* collab_* actions from the Collaborators sheet */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string)($_POST['action'] ?? ''), ['impressions', 'view_stats', 'toggle_download'], true)) {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    header('Content-Type: application/json');
    if ($_POST['action'] === 'impressions') {
        $__v = []; $__d = [];
        foreach (explode(',', (string)($_POST['ids'] ?? '')) as $x) { $x = (int)$x; if ($x > 0) $__v[$x] = $x; }
        foreach (explode(',', (string)($_POST['dw'] ?? '')) as $pair) { $pp = explode(':', $pair); if (count($pp) === 2 && (int)$pp[0] > 0) $__d[(int)$pp[0]] = (int)$pp[1]; }
        if ($__v) dz_record_views($conn, $current_user, array_values($__v), 'history' === basename($_SERVER['SCRIPT_NAME'], '.php') || strpos($_SERVER['SCRIPT_NAME'], 'history') !== false ? 'history' : 'zeems');
        if ($__d) dz_view_add_watch($conn, $current_user, $__d);
        echo json_encode(['status' => 'ok', 'views' => dz_view_counts($conn, array_values($__v))]);
    } elseif ($_POST['action'] === 'toggle_download') {
        $__v = dz_toggle_download($conn, (int)$current_user, (int)($_POST['meme_id'] ?? 0));
        echo json_encode($__v === null ? ['status' => 'error', 'message' => 'only the owner can change this'] : ['status' => 'ok', 'allow_download' => $__v]);
    } else {
        $mid = (int)($_POST['meme_id'] ?? 0);
        $st = $conn->prepare("SELECT uploaded_by FROM memes WHERE id = ?");
        $st->bind_param("i", $mid); $st->execute();
        $rw = $st->get_result()->fetch_assoc(); $st->close();
        if ($rw && (int)$rw['uploaded_by'] === $current_user) echo json_encode(['status' => 'ok', 'stats' => dz_view_stats($conn, $mid)]);
        else echo json_encode(['status' => 'error', 'message' => 'only the owner can see this']);
    }
    exit;
}

/* anyone whose username changed after they were verified loses the badge (and has to apply again) */
vf_sweep_username_changes($conn);

/* ══════════════════════════════════════════════════════════════
   Values your immersive modal + meme modal script reads.
   (their consts use $profile_id and $follow_check — on this page
    "the profile" is simply you)
══════════════════════════════════════════════════════════════ */
$profile_id   = $current_user;
$follow_check = false;

if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Cache-Control: private, no-cache, must-revalidate');
}

/* ══════════════════════════════════════════════════════════════
   CSRF token — same session slot as profile.php, so the like / repost /
   save / share requests the modals send to profile.php are accepted.
══════════════════════════════════════════════════════════════ */
if (!function_exists('dz_csrf_token')) {
    function dz_csrf_token(): string {
        $now = time();
        $c   = $_SESSION['dz_csrf_pf'] ?? null;
        if (!is_array($c) || empty($c['t'])) {
            $c = ['t' => bin2hex(random_bytes(32)), 'p' => '', 'at' => $now];
        } elseif ($now - (int)$c['at'] > 7200) {
            $c = ['t' => bin2hex(random_bytes(32)), 'p' => $c['t'], 'at' => $now];
        }
        $_SESSION['dz_csrf_pf'] = $c;
        return $c['t'];
    }
}
$dz_csrf_token = dz_csrf_token();

// ── helper: group rows by date label ────────────────────────
function groupByDate(array $rows, string $dateField): array {
    $groups = [];
    foreach ($rows as $row) {
        $ts    = strtotime($row[$dateField]);
        $today = strtotime('today');
        $yest  = strtotime('yesterday');

        if ($ts >= $today) {
            $label = 'Today';
        } elseif ($ts >= $yest) {
            $label = 'Yesterday';
        } elseif ($ts >= strtotime('-7 days')) {
            $label = 'This Week';
        } elseif ($ts >= strtotime('-30 days')) {
            $label = 'This Month';
        } else {
            $label = date('F Y', $ts);
        }
        $groups[$label][] = $row;
    }
    return $groups;
}

/* safe query → array of rows (never throws) */
function hs_rows($conn, string $sql): array {
    try {
        $r = $conn->query($sql);
        if (!($r instanceof mysqli_result)) return [];
        $o = [];
        while ($x = $r->fetch_assoc()) $o[] = $x;
        return $o;
    } catch (Throwable $e) {
        return [];
    }
}

/* ── memes the user acted on (likes / reposts / favourites) — full row, with everything
      the immersive modal needs (ad CTA, partnership, AI tag, connected zeem, cover…) ── */
function fetchMemeActions($conn, string $table, int $uid): array {
    $uid = (int)$uid;
    /* 'views' = every post this person has looked at (user_interactions 'view'), one row per post, newest view first,
       without their own posts */
    $adflag = 0;
    if ($table === 'ads') {
        /* ADS history: every ad you have seen or acted on (viewed, liked, reposted, saved), one row per ad, latest first */
        $adflag = 1;
        $src  = "(SELECT $uid AS user_id, meme_id, MAX(ts) AS created_at FROM (
                    SELECT meme_id, created_at AS ts FROM user_interactions WHERE user_id = $uid AND interaction_type = 'view'
                    UNION ALL SELECT meme_id, created_at FROM likes     WHERE user_id = $uid
                    UNION ALL SELECT meme_id, created_at FROM reposts   WHERE user_id = $uid
                    UNION ALL SELECT meme_id, created_at FROM favorites WHERE user_id = $uid
                 ) x GROUP BY meme_id) a";
        $own  = " AND m.uploaded_by <> $uid AND m.is_archived = 0 ";
    } elseif ($table === 'views') {
        $src  = "(SELECT user_id, meme_id, MAX(created_at) AS created_at FROM user_interactions WHERE user_id = $uid AND interaction_type = 'view' GROUP BY meme_id) a";
        $own  = " AND m.uploaded_by <> $uid AND m.is_archived = 0 ";
    } else {
        $src  = "`$table` a";
        $own  = '';
    }
    $sql = "
        SELECT m.*, u.username, u.nickname, u.profile_pic AS uploader_pic,
               u.is_verified AS uploader_verified,
               ma.cta_text  AS ad_cta_text,
               ma.cta_url   AS ad_cta_url,
               ma.cta_color AS ad_cta_color,
               a.created_at AS action_at
        FROM $src
        JOIN memes m ON m.id = a.meme_id
        JOIN users u ON u.id = m.uploaded_by
        LEFT JOIN meme_ads ma ON ma.meme_id = m.id
        WHERE a.user_id = $uid
          AND (
                m.is_adult = 0
                OR m.uploaded_by = $uid
                OR EXISTS (
                    SELECT 1 FROM group_posts gp
                    JOIN group_members gm ON gm.group_id = gp.group_id
                    WHERE gp.meme_id = m.id AND gm.user_id = $uid
                )
          )
          AND m.is_ad = $adflag   /* ads only appear in the Ads tab, never in likes / reposts / saved / viewed */
          $own
          AND NOT EXISTS (
                SELECT 1 FROM blocks b
                WHERE (b.blocker_id = $uid AND b.blocked_id = m.uploaded_by)
                   OR (b.blocker_id = m.uploaded_by AND b.blocked_id = $uid)
          )
        ORDER BY a.created_at DESC
        LIMIT 600
    ";
    return hs_rows($conn, $sql);
}

$likes   = fetchMemeActions($conn, 'likes',     $current_user);
$reposts = fetchMemeActions($conn, 'reposts',   $current_user);
$favs    = fetchMemeActions($conn, 'favorites', $current_user);
$views   = fetchMemeActions($conn, 'views',     $current_user);
$adhist  = fetchMemeActions($conn, 'ads',       $current_user);

/* ── saved comments (comment_saves, written by comment_section.php): one row per saved comment, newest saved first,
      with the post it belongs to so "View post" can open it ── */
function fetchSavedComments($conn, int $uid): array {
    $sql = "
        SELECT m.*, u.username, u.nickname, u.profile_pic AS uploader_pic, u.is_verified AS uploader_verified,
               ma.cta_text AS ad_cta_text, ma.cta_url AS ad_cta_url, ma.cta_color AS ad_cta_color,
               s.created_at AS action_at,
               c.id AS cm_id, c.text AS cm_text, c.media_type AS cm_media_type, c.media_url AS cm_media_url,
               cu.id AS cm_uid, cu.username AS cm_user, cu.profile_pic AS cm_pic, COALESCE(cu.is_verified,0) AS cm_ver
        FROM comment_saves s
        JOIN comments c  ON c.id = s.comment_id
        JOIN memes m     ON m.id = c.meme_id
        JOIN users u     ON u.id = m.uploaded_by
        JOIN users cu    ON cu.id = c.user_id
        LEFT JOIN meme_ads ma ON ma.meme_id = m.id
        WHERE s.user_id = $uid
          AND m.is_archived = 0
          AND (m.is_adult = 0 OR m.uploaded_by = $uid OR EXISTS (
                SELECT 1 FROM group_posts gp JOIN group_members gm ON gm.group_id = gp.group_id
                WHERE gp.meme_id = m.id AND gm.user_id = $uid))
          AND NOT EXISTS (SELECT 1 FROM blocks b WHERE (b.blocker_id = $uid AND b.blocked_id = m.uploaded_by)
                                                     OR (b.blocker_id = m.uploaded_by AND b.blocked_id = $uid))
        ORDER BY s.created_at DESC
        LIMIT 300";
    return hs_rows($conn, $sql);
}
$cmts = fetchSavedComments($conn, $current_user);

/* ══════════════════════════════════════════════════════════════
   BATCH-LOAD everything the modals show, for ALL posts in a handful of queries
══════════════════════════════════════════════════════════════ */
function hs_prime($conn, array $rows, int $uid): array {
    $P = ['follow' => [], 'social' => [], 'media' => [], 'group' => [], 'audio' => [], 'counts' => [], 'inter' => []];
    $mids = []; $ups = [];
    foreach ($rows as $r) { $mids[(int)$r['id']] = 1; $ups[(int)$r['uploaded_by']] = 1; }
    if (!$mids) return $P;
    $m = implode(',', array_keys($mids));
    $u = implode(',', array_keys($ups));

    /* do I track the uploader */
    foreach (hs_rows($conn, "SELECT following_id FROM followers WHERE follower_id=$uid AND following_id IN ($u)") as $x) {
        $P['follow'][(int)$x['following_id']] = true;
    }

    /* social proof: likes / reposts by people I track (reposts first, max 4) */
    $raw = [];
    $sp = hs_rows($conn, "
        SELECT meme_id, id, username, profile_pic, action_type, acted_at FROM (
            SELECT l.meme_id AS meme_id, u2.id, u2.username, u2.profile_pic, 'like' AS action_type, NULL AS acted_at
            FROM likes l JOIN users u2 ON u2.id = l.user_id
            JOIN followers f ON f.following_id = u2.id
            WHERE l.meme_id IN ($m) AND f.follower_id = $uid AND u2.id != $uid
            UNION
            SELECT r.meme_id AS meme_id, u2.id, u2.username, u2.profile_pic, 'repost' AS action_type, r.created_at AS acted_at
            FROM reposts r JOIN users u2 ON u2.id = r.user_id
            JOIN followers f ON f.following_id = u2.id
            WHERE r.meme_id IN ($m) AND f.follower_id = $uid AND u2.id != $uid
            UNION
            SELECT r.meme_id AS meme_id, u2.id, u2.username, u2.profile_pic, 'repost' AS action_type, r.created_at AS acted_at
            FROM reposts r JOIN users u2 ON u2.id = r.user_id
            WHERE r.meme_id IN ($m) AND r.user_id = $uid
        ) sp
    ");
    foreach ($sp as $x) {
        $raw[(int)$x['meme_id']][] = ['id' => $x['id'], 'username' => $x['username'], 'profile_pic' => $x['profile_pic'], 'action_type' => $x['action_type'], 'acted_at' => $x['acted_at'] ?? null];
    }
    foreach ($raw as $mid => $list) {
        /* my own repost first (so it is still there after a refresh), then reposts newest first, then likes — like the home feed */
        usort($list, function ($a, $b) use ($uid) {
            if ((int)$a['id'] === (int)$uid) return -1; if ((int)$b['id'] === (int)$uid) return 1;
            $ar = $a['action_type'] === 'repost'; $br = $b['action_type'] === 'repost';
            if ($ar !== $br) return $ar ? -1 : 1;
            return strcmp((string)($b['acted_at'] ?? ''), (string)($a['acted_at'] ?? ''));
        });
        foreach ($list as &$__l) unset($__l['acted_at']); unset($__l);
        $P['social'][$mid] = array_slice($list, 0, 4);
    }

    /* extra media of multi-media posts */
    foreach (hs_rows($conn, "SELECT meme_id, file_name, type FROM meme_media WHERE meme_id IN ($m) ORDER BY meme_id, position ASC") as $x) {
        $P['media'][(int)$x['meme_id']][] = ['file' => 'uploads/' . $x['file_name'], 'type' => $x['type']];
    }

    /* pod / group */
    foreach (hs_rows($conn, "SELECT gp.meme_id AS meme_id, gp.group_id AS group_id, g.title AS group_name FROM group_posts gp JOIN `groups` g ON g.id = gp.group_id WHERE gp.meme_id IN ($m)") as $x) {
        $mid = (int)$x['meme_id'];
        if (!isset($P['group'][$mid])) $P['group'][$mid] = $x;
    }

    /* attached sound */
    foreach (hs_rows($conn, "SELECT ma.meme_id AS meme_id, a.id AS audio_id, a.title, a.artist, a.file_name, ma.is_original, ma.music_volume, ma.video_audio_volume FROM meme_audio ma JOIN audios a ON a.id = ma.audio_id WHERE ma.meme_id IN ($m)") as $x) {
        $mid = (int)$x['meme_id'];
        if (!isset($P['audio'][$mid])) $P['audio'][$mid] = $x;
    }

    /* counts */
    foreach (['like' => 'likes', 'repost' => 'reposts', 'fav' => 'favorites', 'share' => 'shares'] as $k => $t) {
        foreach (hs_rows($conn, "SELECT meme_id, COUNT(*) c FROM `$t` WHERE meme_id IN ($m) GROUP BY meme_id") as $x) {
            $P['counts'][(int)$x['meme_id']][$k] = (int)$x['c'];
        }
    }

    /* did I like / repost / save it */
    foreach (['liked' => 'likes', 'reposted' => 'reposts', 'faved' => 'favorites'] as $k => $t) {
        foreach (hs_rows($conn, "SELECT meme_id FROM `$t` WHERE meme_id IN ($m) AND user_id=$uid") as $x) {
            $P['inter'][(int)$x['meme_id']][$k] = true;
        }
    }
    return $P;
}

/* one post → the exact array the immersive modal + meme modal read (same shape profile.php builds) */
function hs_feed_item(array $r, array $P, int $uid, string $context): array {
    $mid  = (int)$r['id'];
    $upl  = (int)$r['uploaded_by'];
    $file = (string)($r['file_name'] ?? '');
    $is_text = ($r['type'] === 'text' || $file === '' || $file === '0');
    $mf    = $is_text ? 'uploads/Text.jpg' : 'uploads/' . $file;
    $ntype = $is_text ? 'text' : $r['type'];

    $media = [['file' => $mf, 'type' => $ntype]];
    foreach (($P['media'][$mid] ?? []) as $x) $media[] = $x;

    $grp = $P['group'][$mid] ?? null;
    $aud = $P['audio'][$mid] ?? null;
    $cnt = ($P['counts'][$mid] ?? []) + ['like' => 0, 'repost' => 0, 'fav' => 0, 'share' => 0];
    $int = $P['inter'][$mid] ?? [];

    return [
        'id' => $mid, 'file' => $mf, 'type' => $r['type'],
        'pic' => !empty($r['uploader_pic']) ? $r['uploader_pic'] : 'uploads/default.jpg',
        'userId' => $upl, 'username' => htmlspecialchars((string)($r['username'] ?? '')),
        'isVerified' => !empty($r['uploader_verified']),
        'collab' => $GLOBALS['HS_COLLAB'][$mid] ?? null,   /* modal: show the badge next to the username when true */
        'caption' => htmlspecialchars((string)($r['caption'] ?? '')), 'time' => (string)($r['created_at'] ?? ''),
        'isYou' => ($uid === $upl),
        'likeCount' => $cnt['like'], 'repostCount' => $cnt['repost'], 'favCount' => $cnt['fav'], 'shareCount' => $cnt['share'],
        'liked' => !empty($int['liked']), 'reposted' => !empty($int['reposted']), 'faved' => !empty($int['faved']),
        'isPinned' => false,
        'nickname' => (string)($r['nickname'] ?? ''),
        'isFollowing' => !empty($P['follow'][$upl]),
        'socialProof' => $P['social'][$mid] ?? [],
        'media' => $media, 'context' => '',   /* the Zeems modal's top label stays "Zeems" */
        'groupId' => $grp ? (int)$grp['group_id'] : null,
        'groupTitle' => $grp ? $grp['group_name'] : null,
        'connectedMemeId' => !empty($r['connected_meme_id']) ? (int)$r['connected_meme_id'] : null,
        'connectedLabel' => !empty($r['connected_label']) ? $r['connected_label'] : null,
        'audioId' => $aud ? (int)$aud['audio_id'] : null,
        'audioIsOriginal' => $aud ? (bool)(int)($aud['is_original'] ?? 0) : false,
        'audioFile' => $aud ? 'uploads/' . $aud['file_name'] : null,
        'audioTitle' => $aud ? $aud['title'] : null,
        'audioArtist' => ($aud && !empty($aud['artist'])) ? $aud['artist'] : null,
        'musicVolume' => ($aud && $aud['music_volume'] !== '' && $aud['music_volume'] !== null) ? (int)$aud['music_volume'] : 80,
        'videoAudioVolume' => ($aud && $aud['video_audio_volume'] !== '' && $aud['video_audio_volume'] !== null) ? (int)$aud['video_audio_volume'] : 40,
        'isPartnership' => !empty($r['is_partnership']),
        'partnerBrand' => !empty($r['partner_brand']) ? $r['partner_brand'] : '',
        'isAd' => !empty($r['is_ad']),
        'adCtaText' => !empty($r['ad_cta_text']) ? $r['ad_cta_text'] : null,
        'adCtaUrl' => !empty($r['ad_cta_url']) ? $r['ad_cta_url'] : null,
        'adCtaColor' => !empty($r['ad_cta_color']) ? $r['ad_cta_color'] : null,
        'isAiGenerated' => !empty($r['is_ai_generated']),
        'poster' => (!$is_text && !empty($r['cover_image'])) ? 'uploads/' . $r['cover_image'] : '',   /* still shown while a video loads (zeems_modal reads p.poster) */
    ];
}

$P = hs_prime($conn, array_merge($likes, $reposts, $favs, $views, $adhist, $cmts), $current_user);
$HS_COLLAB = dz_collab_payload($conn, array_column(array_merge($likes, $reposts, $favs, $views, $adhist, $cmts), 'id'), (int)$current_user);

/* view count shown on each card = the post's own counter (memes.views, kept by dz_views.php) */
$HS_VC = [];
foreach (array_merge($likes, $reposts, $favs, $views, $adhist, $cmts) as $_r) $HS_VC[(int)$_r['id']] = (int)($_r['views'] ?? 0);
function hs_fmt_views(int $n): string { return dz_view_fmt($n); }
/* wrap every row with its modal data, so a group can hold it */
function hs_wrap(array $rows, array $P, int $uid, string $context): array {
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['row' => $r, 'p' => hs_feed_item($r, $P, $uid, $context), 'action_at' => $r['action_at']];
    }
    return $out;
}
$likes_w   = hs_wrap($likes,   $P, $current_user, 'Liked');
$reposts_w = hs_wrap($reposts, $P, $current_user, 'Reposted');
$favs_w    = hs_wrap($favs,    $P, $current_user, 'Saved');
$views_w   = hs_wrap($views,   $P, $current_user, 'Viewed');
$ads_w     = hs_wrap($adhist,  $P, $current_user, 'Ad');
$cmts_w    = hs_wrap($cmts,    $P, $current_user, 'Comment');

$likes_grouped   = groupByDate($likes_w,   'action_at');
$reposts_grouped = groupByDate($reposts_w, 'action_at');
$favs_grouped    = groupByDate($favs_w,    'action_at');
$views_grouped   = groupByDate($views_w,   'action_at');
$ads_grouped     = groupByDate($ads_w,     'action_at');
$cmts_grouped    = groupByDate($cmts_w,    'action_at');

/* feeds the modals read (names end in "Feed" so the modal's like/repost sync finds them) */
$FEED_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR;
$feed_likes   = json_encode(array_column($likes_w,   'p'), $FEED_FLAGS);
$feed_reposts = json_encode(array_column($reposts_w, 'p'), $FEED_FLAGS);
$feed_saved   = json_encode(array_column($favs_w,    'p'), $FEED_FLAGS);
$feed_viewed  = json_encode(array_column($views_w,   'p'), $FEED_FLAGS);
$feed_ads     = json_encode(array_column($ads_w,     'p'), $FEED_FLAGS);
$_cf = []; foreach ($cmts_w as $_w) { $_cf[(int)$_w['row']['id']] = $_w['p']; }     /* one entry per post, even if several of its comments are saved */
$feed_comments = json_encode(array_values($_cf), $FEED_FLAGS);

/* data for the ⋯ post menu (shared_modals.php), one entry per post on this page — same shape shared_core.php builds */
$kebab_data = [];
foreach ([$likes, $reposts, $favs, $views, $adhist, $cmts] as $_set) {
    foreach ($_set as $r) {
        $mid = (int)$r['id'];
        if (isset($kebab_data[$mid])) continue;
        $kebab_data[$mid] = [
            'id' => $mid, 'fileName' => $r['file_name'], 'type' => $r['type'], 'uploadedBy' => (int)$r['uploaded_by'], 'allowDownload' => (int)($r['allow_download'] ?? 1), 'collab' => $HS_COLLAB[$mid] ?? null,
            'remixOf' => !empty($r['remix_of']) ? (int)$r['remix_of'] : null,
            'caption' => (string)($r['caption'] ?? ''), 'isAd' => !empty($r['is_ad']),
            'posterPic' => !empty($r['uploader_pic']) ? $r['uploader_pic'] : 'uploads/me.jpeg',
            'posterUsername' => (string)($r['username'] ?? ''),
            'posterVerified' => !empty($r['uploader_verified']),
        ];
    }
}

// ── PROFILES visited ─────────────────────────────────────────
$profiles_result = $conn->prepare("
    SELECT u.id, u.username, u.nickname, u.profile_pic, u.is_verified,
           MAX(pv.viewed_at) AS action_at
    FROM profile_views pv
    JOIN users u ON u.id = pv.profile_id
    WHERE pv.viewer_id = ?
    GROUP BY pv.profile_id
    ORDER BY action_at DESC
    LIMIT 30
");
$profiles_result->bind_param("i", $current_user);
$profiles_result->execute();
$profiles_visited = $profiles_result->get_result()->fetch_all(MYSQLI_ASSOC);
$profiles_result->close();
$profiles_grouped = groupByDate($profiles_visited, 'action_at');

/* ══════════════════════════════════════════════════════════════
   GRID CARD — same look as the profile page's default grid:
   3 per row, 3:4 tiles, type icon top-right, text posts written on Text.jpg
══════════════════════════════════════════════════════════════ */
$HS_SVG = [
    'video' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="5.5" width="14" height="13" rx="3.4"/><path d="M17.6 10.3l3.9-2.5a.7.7 0 0 1 1.1.6v7.2a.7.7 0 0 1-1.1.6l-3.9-2.5z"/></svg>',
    'multi' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8.6 2.6h8.8a4.2 4.2 0 0 1 4.2 4.2v8.8" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round"/><rect x="2.4" y="7.4" width="14.2" height="14.2" rx="3.6"/></svg>',
    'sticker' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill-rule="evenodd" d="M6 2.5h12A3.5 3.5 0 0 1 21.5 6v7.6l-7.9 7.9H6A3.5 3.5 0 0 1 2.5 18V6A3.5 3.5 0 0 1 6 2.5zM13.6 21.5V17a3.5 3.5 0 0 1 3.5-3.5h4.4z"/></svg>',
];
$HS_SVG['stickers'] = $HS_SVG['sticker'];

function renderMemeCard(array $item, string $feedName): string {
    global $HS_SVG, $HS_VC;
    $m  = $item['row'];
    $p  = $item['p'];
    $id = (int)$m['id'];

    $media = $p['media'];
    $count = count($media);
    $t0    = strtolower((string)($media[0]['type'] ?? ''));
    $is_text = ($count === 1 && !in_array($t0, ['image', 'video', 'gif', 'sticker', 'stickers'], true));
    $cover = (!$is_text && !empty($m['cover_image'])) ? 'uploads/' . $m['cover_image'] : '';
    $raw_cap = (string)($m['caption'] ?? '');

    if ($is_text) {
        $inner = "<img src='uploads/Text.jpg' alt='' loading='lazy' decoding='async'>"
               . "<div class='card-text-overlay'><span>" . htmlspecialchars($raw_cap) . "</span></div>";
    } elseif ($t0 === 'video' && $cover === '') {
        $inner = "<video class='lazy-video' data-src='" . htmlspecialchars($p['file']) . "' muted loop playsinline preload='none'></video>";
    } else {
        $src = $cover !== '' ? $cover : $p['file'];
        $inner = "<img src='" . htmlspecialchars($src) . "' alt='' loading='lazy' decoding='async' onerror=\"this.onerror=null;this.src='uploads/Text.jpg'\">";
    }

    $badges = '';
    if (!$is_text) {
        $kind = ($count > 1) ? 'multi' : $t0;
        if ($kind === 'gif')                    $badges = "<span class='dz-type-icon dz-gif'>GIF</span>";
        elseif (isset($HS_SVG[$kind]))          $badges = "<span class='dz-type-icon'>" . $HS_SVG[$kind] . "</span>";
    }
    $badges_html = $badges !== '' ? "<div class='card-badges'>$badges</div>" : '';

    $vc = hs_fmt_views((int)($HS_VC[$id] ?? 0));
    $views_html = "<div class='card-views' title='Views'><i class='ri-eye-line'></i><span>$vc</span></div>";

    $feed = htmlspecialchars($feedName, ENT_QUOTES);
    return "<div class='meme-card' data-id='$id' role='button' tabindex='0' aria-label='Open post'"
         . " onclick=\"hsOpen('$feed',$id)\""
         . " onkeydown=\"if(event.key==='Enter'||event.key===' '){event.preventDefault();hsOpen('$feed',$id);}\">"
         . "<div class='media-box'>$inner</div>$badges_html$views_html</div>";
}

// ── helper: render a date group ───────────────────────────────
function renderGroup(string $label, array $items, string $feedName, string $word = 'item'): string {
    $cards = '';
    foreach ($items as $item) $cards .= renderMemeCard($item, $feedName);
    $count = count($items);
    $label = htmlspecialchars($label);
    return "
    <div class='date-group'>
        <div class='date-label'>
            <span class='date-pill'>$label</span>
            <span class='date-count'>$count $word" . ($count !== 1 ? 's' : '') . "</span>
        </div>
        <div class='grid'>$cards</div>
    </div>";
}

// ── helper: render profile row (verified badge next to the username, same size as the text) ──
function renderProfileRow(array $p): string {
    $id   = (int)$p['id'];
    $user = htmlspecialchars($p['username']);
    $vbdg = !empty($p['is_verified']) ? ' ' . dz_badge('f') : '';
    $nick = !empty($p['nickname']) ? '<span class="pr-nick">@' . htmlspecialchars($p['nickname']) . '</span>' : '';
    $pic  = htmlspecialchars(!empty($p['profile_pic']) ? $p['profile_pic'] : 'uploads/me.png');
    $time = date('M j, g:ia', strtotime($p['action_at']));
    return "
    <a href='profile.php?user_id=$id' class='profile-row'>
        <img src='$pic' class='pr-pic' alt='' onerror=\"this.src='uploads/me.png'\">
        <div class='pr-info'>
            <span class='pr-name'>$user$vbdg</span>
            $nick
            <span class='pr-time'>Visited $time</span>
        </div>
        <i class='ri-arrow-right-s-line pr-chev'></i>
    </a>";
}

// which tab opens first (?tab=profiles etc.)
$tab_names  = ['posts', 'ads', 'comments', 'profiles'];
$sub_names  = ['likes', 'reposts', 'favourites', 'viewed'];
$req_tab    = $_GET['tab'] ?? '';
$start_sub  = in_array($req_tab, $sub_names, true) ? $req_tab : 'likes';      /* ?tab=favourites etc. still work: they open Posts on that list */
$start_tab  = in_array($req_tab, $sub_names, true) ? 'posts' : (in_array($req_tab, $tab_names, true) ? $req_tab : 'posts');
$start_idx  = (int)array_search($start_tab, $tab_names, true);
$posts_total = count($likes) + count($reposts) + count($favs) + count($views);

$tabs = [
    ['posts',    'ri-layout-grid-line',   'Posts',    $posts_total],
    ['ads',      'ri-advertisement-line', 'Ads',      count($adhist)],
    ['comments', 'ri-chat-3-line',        'Comments', count($cmts)],
    ['profiles', 'ri-user-search-line',   'Profiles', count($profiles_visited)],
];
$subs = [
    ['likes',      'ri-heart-line',    'Liked',    count($likes)],
    ['reposts',    'ri-repeat-2-line', 'Reposted', count($reposts)],
    ['favourites', 'ri-bookmark-line', 'Saved',    count($favs)],
    ['viewed',     'ri-eye-line',      'Viewed',   count($views)],
];

/* a saved comment: who wrote it, what it says, and a button that opens the post it came from */
function renderCommentRow(array $item): string {
    $m = $item['row']; $pid = (int)$m['id'];
    $pic = htmlspecialchars(!empty($m['cm_pic']) ? $m['cm_pic'] : 'uploads/me.png');
    $name = htmlspecialchars((string)$m['cm_user']);
    $vb = !empty($m['cm_ver']) ? ' <svg class="cm-vb" viewBox="0 0 256 256" aria-label="Verified"><use href="#ccm-vflower"/></svg>' : '';
    $txt = trim((string)$m['cm_text']);
    $words = preg_split('/\s+/u', $txt, -1, PREG_SPLIT_NO_EMPTY);
    if ($txt !== '' && count($words) > 30) {
        $short = implode(' ', array_slice($words, 0, 30));
        $body = '<div class="cm-text cm-long"><span class="cm-short">' . htmlspecialchars($short) . '… </span>'
              . '<span class="cm-full" hidden>' . nl2br(htmlspecialchars($txt)) . '</span>'
              . '<button type="button" class="cm-more" onclick="hsCmMore(this)">...more</button></div>';
    } else {
        $body = $txt !== '' ? '<div class="cm-text">' . nl2br(htmlspecialchars($txt)) . '</div>' : '';
    }
    if (!empty($m['cm_media_url'])) $body .= '<div class="cm-media"><i class="ri-image-line"></i> Photo</div>';
    if ($body === '') $body = '<div class="cm-text cm-dim">Comment</div>';
    $on = htmlspecialchars((string)$m['username']);
    return "<div class='cm-row'>
        <img class='cm-pic' src='$pic' alt='' onerror=\"this.src='uploads/me.png'\">
        <div class='cm-main'>
            <div class='cm-top'><a class='cm-name' href='profile.php?user_id=" . (int)$m['cm_uid'] . "'>$name</a>$vb<span class='cm-on'>on $on's post</span></div>
            $body
            <button type='button' class='cm-view' onclick=\"hsOpen('historyCommentsFeed',$pid)\"><i class='ri-play-circle-line'></i>View post</button>
        </div>
    </div>";
}

function hs_head($icon, $title, $sub) {
    echo '<div class="sec-head"><div class="sec-icon"><i class="' . $icon . '"></i></div>'
       . '<div><div class="sec-title">' . $title . '</div><div class="sec-sub">' . $sub . '</div></div></div>';
}
function hs_chips($chips) {
    echo '<div class="chips">';
    foreach ($chips as $c) echo '<span class="chip"><i class="' . $c[0] . '"></i>' . $c[1] . '</span>';
    echo '</div>';
}
function hs_empty($icon, $title, $hint) {
    echo '<div class="empty-state"><i class="' . $icon . '"></i><p>' . $title . '</p><small>' . $hint . '</small></div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<!-- THEME: same switch as community.php — runs first so there is no dark → light flash -->
<script>
try{
    if (localStorage.getItem('dz_theme') === 'light') {
        document.documentElement.setAttribute('data-theme', 'light');
    }
}catch(e){}
</script>

<!-- PWA -->
<link rel="manifest" href="/Dezzme/manifest.json">
<meta name="theme-color" content="#070924" id="dzThemeColor">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Dezzme">
<link rel="apple-touch-icon" href="/Dezzme/Dezzme.jpg">

<script>
/* browser bar colour follows the theme */
(function(){
    try{
        if (document.documentElement.getAttribute('data-theme') === 'light') {
            var m = document.getElementById('dzThemeColor');
            if (m) m.setAttribute('content', '#ffffff');
        }
    }catch(e){}
})();
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/Dezzme/service-worker.js')
            .then(reg => console.log('Dezzme SW registered'))
            .catch(err => console.log('SW error:', err));
    });
}
</script>
<meta name="csrf-token" content="<?php echo htmlspecialchars($dz_csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
<title>History — Dezzme</title>

<script>
/* 🔒 CSRF front-end — the immersive / meme modals post likes, reposts, saves and shares to profile.php,
   which requires the token. This attaches it to every same-origin write and refreshes it once if it expired. */
(function(){
    var meta = document.querySelector('meta[name="csrf-token"]');
    window.DZ_CSRF = meta ? meta.content : '';
    var origFetch = window.fetch ? window.fetch.bind(window) : null;

    function sameOrigin(u){ try { return new URL(u, location.href).origin === location.origin; } catch(e){ return true; } }
    function isWrite(m){ return !/^(GET|HEAD|OPTIONS)$/i.test(m || 'GET'); }

    function refreshToken(){
        return origFetch('profile.php?csrf_refresh=1', { credentials:'same-origin', cache:'no-store' })
            .then(function(r){ return r.json(); })
            .then(function(d){
                if(d && d.token){ window.DZ_CSRF = d.token; if(meta) meta.content = d.token; }
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
                    if(d && d.msg === 'csrf') return refreshToken().then(send);
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
})();
</script>

<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">

<style>
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; font-family:'Poppins', sans-serif; }
button, a { cursor:pointer; outline:none; -webkit-tap-highlight-color:transparent; }
a { text-decoration:none; color:inherit; }

[class^="ri-"], [class*=" ri-"],
[class^="ri-"]::before, [class*=" ri-"]::before,
[class^="ri-"]::after,  [class*=" ri-"]::after {
    font-family:'remixicon' !important;
    font-style:normal; font-weight:normal; line-height:1;
    speak:none; text-transform:none;
    -webkit-font-smoothing:antialiased;
}

:root{
    --red:    #FF2D2D;
    --red-1:  #ff6b6b;
    --red-2:  #e02424;
    --red-4:  #ff9a9a;

    --fg:      255,255,255;   /* R,G,B of the text-on-surface tint used for lines + soft fills (dark theme = white) */
    --ui-text: #ffffff;

    /* page background behind the lane (desktop sides) */
    --bg:      linear-gradient(-45deg,#010218,#070924,#070924);
    /* the lane itself — ONE solid colour for the topbar, the tabs and the content */
    --lane:    #070924;
    --surface: rgba(var(--fg),0.05);
    --line:    rgba(var(--fg),0.09);
    --border:  rgba(var(--fg),0.09);
    --line2:   rgba(var(--fg),0.14);
    --text:    #ffffff;
    --muted:   rgba(var(--fg),0.45);
    --muted-2: rgba(var(--fg),0.3);
    --sub:     rgba(var(--fg),0.62);

    --max-w:    580px;
    --topbar-h: 54px;
    --pad:      clamp(9px, 3vw, 16px);
    --r:        6px;
}
@media (max-width:490px){ :root{ --topbar-h:48px; } }
@media (max-width:380px){ :root{ --topbar-h:44px; } }

html{ scroll-behavior:smooth; }

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

/* ── centered lane + grey side lines — solid colour all the way down ── */
.hs-lane{
    width:100%; max-width:var(--max-w);
    min-height:100vh;
    display:flex; flex-direction:column;
    padding-bottom:50px;
    background:var(--lane);
}
@media (min-width:801px){
    .hs-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); }
}
@media (max-width:800px){ .hs-lane{ max-width:100%; } }

/* ══════════════════════════════════════
   TOPBAR — X on the left, title on the right (solid colour)
══════════════════════════════════════ */
.hs-topbar{
    position:sticky; top:0; z-index:500;
    height:var(--topbar-h);
    display:flex; align-items:center; gap:2px;
    padding:0 10px;
    background:var(--lane);
}
@media (max-width:768px){ .hs-topbar{ border-bottom:1px solid var(--line); } }
.hs-x{
    width:34px; height:34px;
    background:none; border:none; color:var(--ui-text);
    display:flex; align-items:center; justify-content:center;
    opacity:.9; transition:opacity .2s, color .2s;
    padding:0; flex-shrink:0;
}
.hs-x:hover{ opacity:1; color:var(--red); }
.hs-x svg{ width:22px; height:22px; display:block; }
.hs-title{
    flex:1; min-width:0;
    font-size:.92rem; font-weight:700; color:var(--ui-text); letter-spacing:-.1px;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    text-align:right; margin:0 6px 0 10px;
}
@media (max-width:490px){ .hs-x{ width:30px; height:30px; } .hs-title{ font-size:.84rem; } }
@media (max-width:380px){ .hs-title{ font-size:.8rem; } }

/* ── page-load reveal ── */
.rv{ opacity:0; }
body.ready .rv{ animation:hsIn .55s cubic-bezier(.22,1,.36,1) both; animation-delay:calc(var(--i, 0) * 60ms); }
@keyframes hsIn{ from{ opacity:0; transform:translateY(12px); } to{ opacity:1; transform:none; } }
@media (prefers-reduced-motion:reduce){ body.ready .rv{ animation:none; opacity:1; } }

/* ══════════════════════════════════════
   TABS — docked under the topbar, sliding underline, ONE solid colour
══════════════════════════════════════ */
.hs-tabs{
    position:sticky; top:calc(var(--topbar-h) - 1px); z-index:400;
    display:flex; align-items:stretch;
    background:var(--lane);
    border-top:1px solid var(--line); border-bottom:1px solid var(--line);
    overflow-x:auto; scrollbar-width:none; -ms-overflow-style:none;
    -webkit-overflow-scrolling:touch;
}
.hs-tabs::-webkit-scrollbar{ display:none; }
.hs-tabs-track{ position:relative; display:flex; align-items:stretch; flex:1 0 auto; }
.hs-ind{
    position:absolute; bottom:0; left:0; height:2px; width:0;
    background:var(--ui-text); border-radius:2px 2px 0 0;
    transition:left .28s cubic-bezier(.4,0,.2,1), width .28s cubic-bezier(.4,0,.2,1), opacity .2s;
    opacity:0; pointer-events:none;
}
.hs-ind.ready{ opacity:1; }
.hs-ind.live{ transition:none; }
.hs-tab{
    flex:1 0 auto; min-width:56px;
    display:flex; flex-direction:column; align-items:center; justify-content:center; gap:3px;
    padding:10px 12px; background:none; border:none;
    color:rgba(var(--fg),.5); transition:color .2s;
}
.hs-tab:hover{ color:rgba(var(--fg),.85); }
.hs-tab.active{ color:var(--ui-text); }
.hs-tab-top{ display:flex; align-items:center; gap:6px; }
.hs-tab i{ font-size:19px; }
.hs-tab.active i{ color:var(--red); }
.hs-tab-count{
    font-size:.6rem; font-weight:700; line-height:1;
    padding:3px 6px; border-radius:4px; background:rgba(var(--fg),.08);
}
.hs-tab.active .hs-tab-count{ background:rgba(255,45,45,.16); color:var(--red); }
.hs-tab-label{ font-size:.62rem; font-weight:600; letter-spacing:.02em; }
@media (max-width:490px){
    .hs-tab{ padding:9px 8px; min-width:48px; }
    .hs-tab i{ font-size:17px; }
    .hs-tab-count{ font-size:.54rem; padding:2px 5px; }
    .hs-tab-label{ font-size:.56rem; }
}

/* ══════════════════════════════════════
   PAGER — the four lists sit side by side and follow your finger
══════════════════════════════════════ */
.hs-pager{ width:100%; overflow:hidden; touch-action:pan-y; }
.hs-pager.dragging{ user-select:none; -webkit-user-select:none; }
.hs-pager img{ -webkit-user-drag:none; }
.hs-track{ display:flex; align-items:flex-start; width:100%; will-change:transform; backface-visibility:hidden; }
.hs-panel{ flex:0 0 100%; width:100%; min-width:0; padding:clamp(14px, 4vw, 20px) 0 30px; }
.hs-pager.idle .hs-panel:not(.active){ max-height:0; overflow:hidden; padding:0; visibility:hidden; }

/* ── section header + chips ── */
.sec-head{ display:flex; align-items:center; gap:12px; padding:0 var(--pad) 12px; }
.sec-icon{
    width:clamp(28px, 7.6vw, 36px); height:clamp(28px, 7.6vw, 36px);
    border-radius:6px; background:var(--surface);
    display:flex; align-items:center; justify-content:center;
    font-size:clamp(.82rem, 2.6vw, 1rem); color:var(--red); flex-shrink:0;
}
.sec-title{ font-size:clamp(.82rem, 2.8vw, .96rem); font-weight:700; letter-spacing:-.1px; color:var(--ui-text); }
.sec-sub{ font-size:clamp(.56rem, 1.9vw, .68rem); color:var(--muted); margin-top:2px; }

.chips{ display:flex; flex-wrap:wrap; gap:6px; padding:0 var(--pad) clamp(14px, 4vw, 20px); }
.chip{
    display:inline-flex; align-items:center; gap:6px;
    height:clamp(25px, 7.2vw, 30px); padding:0 12px;
    background:rgba(var(--fg),.1);
    backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    border-radius:4px; font-size:.68rem; font-weight:600; color:var(--ui-text);
}
.chip i{ font-size:.88rem; color:var(--red); }
@media (max-width:490px){ .chip{ font-size:.62rem; padding:0 10px; } }

/* ── date groups ── */
.date-group{ margin-bottom:clamp(20px, 5.4vw, 30px); }
.date-label{
    display:flex; align-items:center; justify-content:space-between; gap:10px;
    padding:0 var(--pad) 10px;
}
.date-pill{
    display:inline-flex; align-items:center; height:22px; padding:0 10px; border-radius:4px;
    background:rgba(255,45,45,.12); color:var(--red);
    font-size:.58rem; font-weight:700; letter-spacing:.07em; text-transform:uppercase;
}
.date-count{ font-size:.64rem; color:var(--muted); }

/* ══════════════════════════════════════
   POST GRID — identical to the profile page's default grid:
   3 per row, 3:4 tiles, hairline gaps, edge to edge
══════════════════════════════════════ */
.grid{
    display:grid;
    grid-template-columns:repeat(3, minmax(0, 1fr));
    grid-auto-rows:auto;
    align-items:stretch;
    gap:2.5px;
    width:100%; margin:0 auto;
    padding:2.5px 0 0;
}
.grid .meme-card{
    display:block;
    border-radius:0; background:rgba(var(--fg),0.03); position:relative; overflow:hidden;
    aspect-ratio:3/4;
    width:100%; height:auto; min-height:0; margin:0;
    cursor:pointer;
}
.grid .meme-card .media-box{
    position:absolute; inset:0;
    width:100%; height:100%; margin:0; padding:0;
    display:block; overflow:hidden; border-radius:0;
}
.grid .meme-card .media-box img,
.grid .meme-card .media-box video{
    position:absolute; inset:0;
    width:100%; height:100%; object-fit:cover; display:block; border-radius:0;
    pointer-events:none;
}
.grid .meme-card:hover::after{
    content:''; position:absolute; inset:0; background:rgba(0,0,0,0.15);
    pointer-events:none;
}
.grid .meme-card:active{ opacity:.9; }
.grid .meme-card:focus-visible{ outline:2px solid var(--red); outline-offset:-2px; }

.grid .meme-card .card-views{
    position:absolute; left:6px; bottom:6px; z-index:2; display:flex; align-items:center; gap:4px;
    color:#fff; font-size:11px; font-weight:600; line-height:1; pointer-events:none;
    text-shadow:0 1px 4px rgba(0,0,0,.85), 0 0 2px rgba(0,0,0,.7);
}
.grid .meme-card .card-views i{ font-size:13px; filter:drop-shadow(0 1px 3px rgba(0,0,0,.8)); }

/* top-right badge (media type icon) — always white, it sits on top of the picture */
.grid .meme-card .card-badges{
    position:absolute; top:clamp(6px, 1.4vw, 7px); right:clamp(6px, 1.4vw, 7px); z-index:6;
    display:flex; align-items:center; gap:clamp(5px, 1.2vw, 6px);
    pointer-events:none;
    filter:drop-shadow(0 1px 3px rgba(0,0,0,0.75));
}
.grid .meme-card .card-badges .dz-type-icon{
    display:flex; align-items:center; justify-content:center;
    width:clamp(19px, 1.5vw + 12px, 21px); height:clamp(19px, 1.5vw + 12px, 21px); color:#fff; line-height:1;
}
.grid .meme-card .card-badges .dz-type-icon svg{ width:100%; height:100%; display:block; fill:currentColor; }
.grid .meme-card .card-badges .dz-type-icon.dz-gif{
    width:auto; height:auto; padding:2px 5px; border-radius:5px;
    background:#fff; color:#111;
    font-size:clamp(8.5px, .2vw + 7.5px, 9px); font-weight:800; letter-spacing:.4px; line-height:1;
}

/* infinite scroll: cards past the first batch wait hidden; an empty date group folds away; a small shimmer row shows while the next batch loads */
.grid .meme-card.hs-wait{ display:none; }
.date-group.hs-gone{ display:none; }
.hs-sentinel{ height:1px; pointer-events:none; }
.hs-skel-wrap{ margin-top:2.5px; }
.dz-skel-card{ position:relative; overflow:hidden; aspect-ratio:3/4; background:rgba(var(--fg),0.05); }
.dz-skel-card::after{
    content:''; position:absolute; inset:0;
    background:linear-gradient(90deg, transparent, rgba(var(--fg),0.1), transparent);
    transform:translateX(-100%); animation:dzGridShimmer 1.4s ease-in-out infinite;
}
@keyframes dzGridShimmer{ 100%{ transform:translateX(100%); } }
.grid .meme-card.dz-reveal{ animation:dzCardIn .45s cubic-bezier(.22,1,.36,1) both; }
@keyframes dzCardIn{ from{ opacity:0; transform:translateY(10px); } to{ opacity:1; transform:translateY(0); } }

/* text post caption written on the default text image (white on a dark tint in both themes) */
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
    -webkit-line-clamp:8;
    overflow:hidden;
    font-size:clamp(.55rem, .4rem + .9vw, .7rem); line-height:1.45; font-weight:500; color:#fff;
    word-break:break-word;
    text-shadow:0 1px 4px rgba(0,0,0,0.6);
}
@media (max-width:490px){ .grid .meme-card .card-text-overlay span{ -webkit-line-clamp:7; } }

/* ── profile rows ── */
.profile-list{ border-top:1px solid var(--line); }
.profile-row{
    display:flex; align-items:center; gap:clamp(10px, 3vw, 14px);
    padding:clamp(10px, 3vw, 13px) var(--pad);
    border-bottom:1px solid rgba(var(--fg),.06);
    transition:background .15s;
}
.profile-row:hover{ background:rgba(var(--fg),.03); }
.pr-pic{
    width:clamp(38px, 10.5vw, 46px); height:clamp(38px, 10.5vw, 46px);
    border-radius:50%; object-fit:cover; flex-shrink:0; border:2px solid var(--red);
}
@media (max-width:490px){ .pr-pic{ border-width:1.5px; } }
.pr-info{ flex:1; min-width:0; }
.pr-name{ display:block; font-size:clamp(.74rem, 2.5vw, .86rem); font-weight:600; color:var(--ui-text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.pr-nick{ display:block; font-size:clamp(.6rem, 2vw, .7rem); color:var(--muted); margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.pr-time{ display:block; font-size:clamp(.54rem, 1.8vw, .64rem); color:var(--muted-2); margin-top:2px; }
.pr-chev{ color:var(--muted); font-size:1rem; flex-shrink:0; }

/* ── empty state ── */
.empty-state{
    padding:48px 20px 32px; text-align:center; color:var(--muted);
    display:flex; flex-direction:column; align-items:center; gap:8px;
}
.empty-state i{ font-size:2.2rem; color:var(--red); opacity:.85; }
.empty-state p{ font-size:.82rem; color:var(--sub); font-weight:600; }
.empty-state small{ font-size:.68rem; }

/* ══════════════════════════════════════
   PAGE-LOAD SKELETON
══════════════════════════════════════ */
#page-skeleton{
    position:fixed; inset:0; z-index:9999;
    background:var(--bg); background-attachment:fixed;
    display:flex; justify-content:center;
    opacity:1; transition:opacity .45s ease, visibility .45s ease; pointer-events:none;
}
#page-skeleton.hide{ opacity:0; visibility:hidden; }
.sk-lane{ width:100%; max-width:var(--max-w); display:flex; flex-direction:column; overflow:hidden; background:var(--lane); }
@media (min-width:801px){ .sk-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); } }
@media (max-width:800px){ .sk-lane{ max-width:100%; } }
@media (max-width:768px){ .sk-topbar{ border-bottom:1px solid var(--line); } }
.sk{ position:relative; overflow:hidden; background:rgba(var(--fg),.06); border-radius:6px; }
.sk::after{
    content:''; position:absolute; inset:0;
    background:linear-gradient(90deg, transparent, rgba(var(--fg),.09), transparent);
    transform:translateX(-100%); animation:skShimmer 1.5s infinite;
}
@keyframes skShimmer{ 100%{ transform:translateX(100%); } }
.sk-topbar{ height:var(--topbar-h); display:flex; align-items:center; justify-content:space-between; padding:0 16px 0 14px; flex-shrink:0; }
.sk-x{ width:20px; height:20px; } .sk-ttl{ width:100px; height:13px; }
.sk-tabs{ display:flex; gap:2px; border-top:1px solid var(--line); border-bottom:1px solid var(--line); }
.sk-tab{ flex:1; height:50px; border-radius:0; }
.sk-sec{ padding:clamp(16px,4.4vw,22px) 0 0; }
.sk-sec-h{ display:flex; align-items:center; gap:12px; margin-bottom:14px; padding:0 var(--pad); }
.sk-ico{ width:clamp(28px,7.6vw,36px); height:clamp(28px,7.6vw,36px); border-radius:6px; flex-shrink:0; }
.sk-t{ display:flex; flex-direction:column; gap:6px; } .sk-t1{ width:130px; height:12px; } .sk-t2{ width:180px; height:9px; }
.sk-pill{ width:70px; height:22px; border-radius:4px; margin:6px var(--pad) 10px; }
.sk-grid{ display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:2.5px; }
.sk-card{ aspect-ratio:3/4; border-radius:0; }

/* ═══════════════════════════════════════════════════════════════
   RESPONSIVE ONLY — everything 10% bigger (desktop untouched)
   Kept at the very end so it wins over the rules above.
═══════════════════════════════════════════════════════════════ */
@media (max-width:800px){
    :root{
        --topbar-h:59px;
        --pad:clamp(10px, 3.3vw, 17.6px);
        --r:6.6px;
    }

    /* topbar */
    .hs-topbar{ padding:0 11px; }
    .hs-x{ width:37px; height:37px; }
    .hs-x svg{ width:24px; height:24px; }
    .hs-title{ font-size:1.01rem; margin:0 7px 0 11px; }

    /* tabs */
    .hs-ind{ height:2.2px; }
    .hs-tab{ min-width:62px; gap:3.3px; padding:11px 13px; }
    .hs-tab-top{ gap:6.6px; }
    .hs-tab i{ font-size:21px; }
    .hs-tab-count{ font-size:.66rem; padding:3.3px 6.6px; border-radius:4.4px; }
    .hs-tab-label{ font-size:.68rem; }

    /* panels + section header */
    .hs-panel{ padding:clamp(15px, 4.4vw, 22px) 0 33px; }
    .sec-head{ gap:13px; padding:0 var(--pad) 13px; }
    .sec-icon{ width:clamp(31px, 8.36vw, 40px); height:clamp(31px, 8.36vw, 40px); border-radius:6.6px; font-size:clamp(.9rem, 2.86vw, 1.1rem); }
    .sec-title{ font-size:clamp(.9rem, 3.08vw, 1.056rem); }
    .sec-sub{ font-size:clamp(.62rem, 2.09vw, .75rem); margin-top:2.2px; }

    /* chips */
    .chips{ gap:6.6px; padding:0 var(--pad) clamp(15px, 4.4vw, 22px); }
    .chip{ gap:6.6px; height:clamp(27.5px, 7.92vw, 33px); padding:0 13px; font-size:.75rem; }
    .chip i{ font-size:.97rem; }

    /* date groups */
    .date-group{ margin-bottom:clamp(22px, 5.94vw, 33px); }
    .date-label{ gap:11px; padding:0 var(--pad) 11px; }
    .date-pill{ height:24.2px; padding:0 11px; font-size:.64rem; }
    .date-count{ font-size:.7rem; }

    /* post grid (hairline gaps stay at 2.5px) */
    .grid .meme-card .card-badges{ top:clamp(6.6px, 1.54vw, 7.7px); right:clamp(6.6px, 1.54vw, 7.7px); gap:clamp(5.5px, 1.32vw, 6.6px); }
    .grid .meme-card .card-badges .dz-type-icon{ width:clamp(21px, 1.65vw + 13px, 23px); height:clamp(21px, 1.65vw + 13px, 23px); }
    .grid .meme-card .card-badges .dz-type-icon.dz-gif{ padding:2.2px 5.5px; border-radius:5.5px; font-size:clamp(9.4px, .22vw + 8.25px, 9.9px); }
    .grid .meme-card .card-text-overlay{ padding:clamp(24px, 5.5vw, 29px) clamp(10px, 2.75vw, 13px) clamp(15px, 3.85vw, 20px); }
    .grid .meme-card .card-text-overlay span{ font-size:clamp(.6rem, .44rem + .99vw, .77rem); }

    /* profile rows */
    .profile-row{ gap:clamp(11px, 3.3vw, 15px); padding:clamp(11px, 3.3vw, 14px) var(--pad); }
    .pr-pic{ width:clamp(42px, 11.55vw, 51px); height:clamp(42px, 11.55vw, 51px); border-width:2.2px; }
    .pr-name{ font-size:clamp(.81rem, 2.75vw, .95rem); }
    .pr-nick{ font-size:clamp(.66rem, 2.2vw, .77rem); margin-top:2.2px; }
    .pr-time{ font-size:clamp(.59rem, 1.98vw, .7rem); margin-top:2.2px; }
    .pr-chev{ font-size:1.1rem; }

    /* empty state */
    .empty-state{ padding:53px 22px 35px; gap:8.8px; }
    .empty-state i{ font-size:2.42rem; }
    .empty-state p{ font-size:.9rem; }
    .empty-state small{ font-size:.75rem; }

    /* skeleton */
    .sk-topbar{ padding:0 17.6px 0 15px; }
    .sk-x{ width:22px; height:22px; } .sk-ttl{ width:110px; height:14.3px; }
    .sk-tab{ height:55px; }
    .sk-sec{ padding:clamp(18px, 4.84vw, 24px) 0 0; }
    .sk-sec-h{ gap:13px; margin-bottom:15px; }
    .sk-ico{ width:clamp(31px, 8.36vw, 40px); height:clamp(31px, 8.36vw, 40px); border-radius:6.6px; }
    .sk-t{ gap:6.6px; } .sk-t1{ width:143px; height:13px; } .sk-t2{ width:198px; height:10px; }
    .sk-pill{ width:77px; height:24px; margin:6.6px var(--pad) 11px; }
}

@media (max-width:490px){
    :root{ --topbar-h:53px; }
    .hs-x{ width:33px; height:33px; }
    .hs-title{ font-size:.92rem; }
    .hs-tab{ padding:10px 9px; min-width:53px; }
    .hs-tab i{ font-size:18.7px; }
    .hs-tab-count{ font-size:.6rem; padding:2.2px 5.5px; }
    .hs-tab-label{ font-size:.62rem; }
    .chip{ font-size:.68rem; padding:0 11px; }
    .pr-pic{ border-width:1.65px; }
}

@media (max-width:380px){
    :root{ --topbar-h:48px; }
    .hs-title{ font-size:.88rem; }
}

/* ═══════════ LIGHT MODE — <html data-theme="light"> (dark mode is never touched) ═══════════
   Same switch + same palette as community.php. */
:root[data-theme="light"]{
    color-scheme:light;
    --fg:6,7,26;
    --ui-text:#0b0d24;
    --text:#0b0d24;
    --lane:#ffffff;
    --bg:linear-gradient(-45deg,#ffffff,#f4f5fb,#f4f5fb);
    --surface:rgba(6,7,26,.05);
    --line:rgba(6,7,26,.1);
    --border:rgba(6,7,26,.2);
    --line2:rgba(6,7,26,.16);
    --muted:rgba(6,7,26,.52);
    --muted-2:rgba(6,7,26,.38);
    --sub:rgba(6,7,26,.66);
    --red:#e62020;
}
[data-theme="light"] .date-pill{ background:rgba(230,32,32,.1); }
[data-theme="light"] .hs-tab.active .hs-tab-count{ background:rgba(230,32,32,.12); color:var(--red); }
[data-theme="light"] .hs-tab.active i{ color:var(--red); }
/* ── Posts tab: sub-tabs + saved comments ── */
.hs-subbar{ display:flex; gap:6px; padding:0 var(--pad) 14px; overflow-x:auto; scrollbar-width:none; }
.hs-subbar::-webkit-scrollbar{ display:none; }
.hs-sub{
    flex:1 0 auto; display:inline-flex; align-items:center; justify-content:center; gap:6px;
    height:clamp(30px, 8.4vw, 36px); padding:0 12px; border:0; border-radius:4px; cursor:pointer;
    background:rgba(var(--fg),.07); color:var(--sub); font:600 .68rem inherit; font-family:inherit;
    -webkit-tap-highlight-color:transparent; transition:background .15s, color .15s;
}
.hs-sub i{ font-size:.95rem; }
.hs-sub b{ font-weight:700; font-size:.6rem; opacity:.75; }
.hs-sub.active{ background:rgba(255,45,45,.16); color:var(--red); }
.hs-sub-panel{ display:none; }
.hs-sub-panel.active{ display:block; }
.cm-row{ display:flex; gap:11px; padding:12px var(--pad); border-bottom:1px solid rgba(var(--fg),.06); }
.cm-pic{ width:36px; height:36px; border-radius:50%; object-fit:cover; flex-shrink:0; border:2px solid var(--red); }
.cm-main{ flex:1; min-width:0; }
.cm-top{ display:flex; align-items:center; gap:5px; flex-wrap:wrap; font-size:.78rem; }
.cm-name{ font-weight:700; color:var(--ui-text); text-decoration:none; }
.cm-vb{ width:.95em; height:.95em; flex-shrink:0; }
.cm-on{ font-size:.64rem; color:var(--muted); }
.cm-text{ margin-top:4px; font-size:.82rem; line-height:1.4; color:var(--ui-text); word-break:break-word; }
.cm-dim{ color:var(--muted); }
.cm-more{ border:0; background:none; padding:0; font:inherit; color:var(--muted); cursor:pointer; }
.cm-full[hidden]{ display:none; }
.cm-media{ margin-top:5px; font-size:.7rem; color:var(--sub); display:flex; align-items:center; gap:4px; }
.cm-view{
    margin-top:9px; display:inline-flex; align-items:center; gap:6px; height:30px; padding:0 12px; border:0; border-radius:4px;
    background:rgba(var(--fg),.09); color:var(--ui-text); font:600 .68rem inherit; font-family:inherit; cursor:pointer;
}
.cm-view i{ color:var(--red); font-size:.95rem; }
.cm-view:active{ background:rgba(var(--fg),.16); }
</style>
<noscript><style>.rv{opacity:1 !important} #page-skeleton{display:none}</style></noscript>
</head>
<body>

<!-- ══ PAGE-LOAD SKELETON ══ -->
<div id="page-skeleton" aria-hidden="true">
    <div class="sk-lane">
        <div class="sk-topbar"><div class="sk sk-x"></div><div class="sk sk-ttl"></div></div>
        <div class="sk-tabs"><div class="sk sk-tab"></div><div class="sk sk-tab"></div><div class="sk sk-tab"></div><div class="sk sk-tab"></div></div>
        <div class="sk-sec">
            <div class="sk-sec-h"><div class="sk sk-ico"></div><div class="sk-t"><div class="sk sk-t1"></div><div class="sk sk-t2"></div></div></div>
            <div class="sk sk-pill"></div>
            <div class="sk-grid"><?php for ($i = 0; $i < 6; $i++): ?><div class="sk sk-card"></div><?php endfor; ?></div>
        </div>
    </div>
</div>

<div class="hs-lane">

    <!-- ═══ TOP BAR ═══ -->
    <header class="hs-topbar">
        <button type="button" class="hs-x" onclick="hsClose()" aria-label="Close">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
        <span class="hs-title">Your History</span>
    </header>

    <!-- ═══ TABS ═══ -->
    <nav class="hs-tabs rv" id="hsTabs" style="--i:0;">
        <div class="hs-tabs-track" id="hsTabsTrack">
            <?php foreach ($tabs as $i => $t): ?>
            <button type="button" class="hs-tab<?php echo $i === $start_idx ? ' active' : ''; ?>" data-tab="<?php echo $t[0]; ?>" aria-label="<?php echo $t[2]; ?>">
                <span class="hs-tab-top"><i class="<?php echo $t[1]; ?>"></i><span class="hs-tab-count"><?php echo $t[3]; ?></span></span>
                <span class="hs-tab-label"><?php echo $t[2]; ?></span>
            </button>
            <?php endforeach; ?>
            <span class="hs-ind" id="hsInd"></span>
        </div>
    </nav>

    <!-- ═══ PAGER ═══ -->
    <div class="hs-pager idle rv" id="hsPager" style="--i:1;">
    <div class="hs-track" id="hsTrack" style="transform:translate3d(-<?php echo $start_idx * 100; ?>%,0,0);">

        <!-- ── POSTS (Liked / Reposted / Saved / Viewed) ── -->
        <section class="hs-panel<?php echo $start_idx === 0 ? ' active' : ''; ?>" id="sec-posts">
            <div class="hs-subbar" id="hsSubbar">
                <?php foreach ($subs as $sb): ?>
                <button type="button" class="hs-sub<?php echo $start_sub === $sb[0] ? ' active' : ''; ?>" data-sub="<?php echo $sb[0]; ?>"><i class="<?php echo $sb[1]; ?>"></i><?php echo $sb[2]; ?> <b><?php echo $sb[3]; ?></b></button>
                <?php endforeach; ?>
            </div>
        <div class="hs-sub-panel<?php echo $start_sub === 'likes' ? ' active' : ''; ?>" id="sec-likes">
            <?php hs_chips(array_filter([
                ['ri-heart-fill', count($likes) . ' total likes'],
                !empty($likes) ? ['ri-calendar-line', 'Since ' . date('M Y', strtotime(end($likes)['action_at']))] : null,
            ])); ?>
            <?php if (empty($likes_grouped)): hs_empty('ri-heart-line', 'No liked memes yet', 'Tap the heart on any meme to like it');
            else: foreach ($likes_grouped as $label => $items) echo renderGroup($label, $items, 'historyLikesFeed'); endif; ?>
        </div>

        <div class="hs-sub-panel<?php echo $start_sub === 'reposts' ? ' active' : ''; ?>" id="sec-reposts">
            <?php hs_chips([['ri-repeat-2-line', count($reposts) . ' reposts']]); ?>
            <?php if (empty($reposts_grouped)): hs_empty('ri-repeat-2-line', 'No reposts yet', 'Repost memes to share them with your trackers');
            else: foreach ($reposts_grouped as $label => $items) echo renderGroup($label, $items, 'historyRepostsFeed'); endif; ?>
        </div>

        <div class="hs-sub-panel<?php echo $start_sub === 'favourites' ? ' active' : ''; ?>" id="sec-favourites">
            <?php hs_chips([['ri-bookmark-fill', count($favs) . ' saved']]); ?>
            <?php if (empty($favs_grouped)): hs_empty('ri-bookmark-line', 'Nothing saved yet', 'Bookmark memes to find them here instantly');
            else: foreach ($favs_grouped as $label => $items) echo renderGroup($label, $items, 'historySavedFeed'); endif; ?>
        </div>

        <div class="hs-sub-panel<?php echo $start_sub === 'viewed' ? ' active' : ''; ?>" id="sec-viewed">
            <?php hs_chips(array_filter([
                ['ri-eye-fill', count($views) . ' posts viewed'],
                !empty($views) ? ['ri-calendar-line', 'Since ' . date('M Y', strtotime(end($views)['action_at']))] : null,
            ])); ?>
            <?php if (empty($views_grouped)): hs_empty('ri-eye-line', 'No viewed posts yet', 'Posts you open or watch in your feed will show here');
            else: foreach ($views_grouped as $label => $items) echo renderGroup($label, $items, 'historyViewedFeed'); endif; ?>
        </div>

        </section>

        <!-- ── ADS ── -->
        <section class="hs-panel<?php echo $start_idx === 1 ? ' active' : ''; ?>" id="sec-ads">
            <?php hs_head('ri-advertisement-fill', 'Ads', 'Ads you have seen or interacted with, latest first'); ?>
            <?php hs_chips(array_filter([
                ['ri-advertisement-fill', count($adhist) . ' ads'],
                !empty($adhist) ? ['ri-calendar-line', 'Since ' . date('M Y', strtotime(end($adhist)['action_at']))] : null,
            ])); ?>
            <?php if (empty($ads_grouped)): hs_empty('ri-advertisement-line', 'No ads yet', 'Ads you see or interact with will show here');
            else: foreach ($ads_grouped as $label => $items) echo renderGroup($label, $items, 'historyAdsFeed'); endif; ?>
        </section>

        <!-- ── SAVED COMMENTS ── -->
        <section class="hs-panel<?php echo $start_idx === 2 ? ' active' : ''; ?>" id="sec-comments">
            <?php hs_head('ri-chat-3-fill', 'Saved Comments', 'Comments you saved — tap View post to see where they came from'); ?>
            <?php hs_chips([['ri-chat-3-fill', count($cmts) . ' saved']]); ?>
            <?php if (empty($cmts_grouped)): hs_empty('ri-chat-3-line', 'No saved comments yet', 'Save a comment from any post and it will show here');
            else: foreach ($cmts_grouped as $label => $items):
                $count = count($items); ?>
                <div class="date-group">
                    <div class="date-label">
                        <span class="date-pill"><?php echo htmlspecialchars($label); ?></span>
                        <span class="date-count"><?php echo $count; ?> comment<?php echo $count !== 1 ? 's' : ''; ?></span>
                    </div>
                    <div class="profile-list"><?php foreach ($items as $it) echo renderCommentRow($it); ?></div>
                </div>
            <?php endforeach; endif; ?>
        </section>

        <!-- ── PROFILES VISITED ── -->
        <section class="hs-panel<?php echo $start_idx === 3 ? ' active' : ''; ?>" id="sec-profiles">
            <?php hs_head('ri-user-search-line', 'Profiles Visited', "People whose pages you've checked out"); ?>
            <?php hs_chips([['ri-user-line', count($profiles_visited) . ' profiles']]); ?>
            <?php if (empty($profiles_grouped)): hs_empty('ri-user-search-line', 'No profile visits logged', 'Profiles you visit will show here');
            else: foreach ($profiles_grouped as $label => $items):
                $count = count($items); ?>
                <div class="date-group">
                    <div class="date-label">
                        <span class="date-pill"><?php echo htmlspecialchars($label); ?></span>
                        <span class="date-count"><?php echo $count; ?> visit<?php echo $count !== 1 ? 's' : ''; ?></span>
                    </div>
                    <div class="profile-list"><?php foreach ($items as $p) echo renderProfileRow($p); ?></div>
                </div>
            <?php endforeach; endif; ?>
        </section>

    </div><!-- /.hs-track -->
    </div><!-- /.hs-pager -->

</div><!-- /.hs-lane -->

<script>
/* feeds read by the immersive modal + meme modal (names end in "Feed") */
window.historyLikesFeed   = <?php echo $feed_likes; ?>;
window.historyRepostsFeed = <?php echo $feed_reposts; ?>;
window.historySavedFeed   = <?php echo $feed_saved; ?>;
window.historyViewedFeed  = <?php echo $feed_viewed; ?>;
window.historyAdsFeed     = <?php echo $feed_ads; ?>;
window.historyCommentsFeed = <?php echo $feed_comments; ?>;

/* open a post in the shared Zeems modal (zeems_modal.php, the same one home / profile / text feed use).
   The tab's whole list is passed as the feed, so swiping up / down moves through the rest of that tab. */
function hsOpen(feed, id){
    var arr = window[feed] || [];
    if (typeof window.openCardModal !== 'function') { console.warn('zeems_modal is not loaded yet'); return; }
    var found = false;
    for (var i = 0; i < arr.length; i++) { if (String(arr[i].id) === String(id)) { found = true; break; } }
    if (!found) return;
    window.openCardModal({ id: id, feed: arr, mediaIndex: 0, startTime: 0 });
}

// ── X: go back, or to Settings if there is no history ──
function hsClose(){
    if (document.referrer && document.referrer.indexOf(window.location.host) !== -1) history.back();
    else location.href = 'setting.php';
}

// ── PAGE-LOAD SKELETON → staggered reveal ──
(function(){
    var sk = document.getElementById('page-skeleton');
    var shownAt = Date.now(), done = false, MIN = 450;
    function reveal(){
        if (done) return; done = true;
        var wait = Math.max(0, MIN - (Date.now() - shownAt));
        setTimeout(function(){
            document.body.classList.add('ready');
            if (sk){
                sk.classList.add('hide');
                setTimeout(function(){ if (sk.parentNode) sk.remove(); }, 520);
            }
            syncIndicator();
        }, wait);
    }
    if (document.readyState === 'complete') reveal();
    else window.addEventListener('load', reveal);
    setTimeout(reveal, 1800);   // safety net
})();

// ═════════════════════════════════════════════════════════════
// PAGER — tabs bar + live finger-following swipe between lists
// ═════════════════════════════════════════════════════════════
var pager   = document.getElementById('hsPager');
var track   = document.getElementById('hsTrack');
var panels  = Array.prototype.slice.call(track.querySelectorAll('.hs-panel'));
var tabBtns = Array.prototype.slice.call(document.querySelectorAll('.hs-tab'));
var tabInd  = document.getElementById('hsInd');
var curIdx  = Math.max(0, tabBtns.findIndex(function(b){ return b.classList.contains('active'); }));
var pageW   = pager.clientWidth;
var settleTimer = null;

function setX(x, animate){
    track.style.transition = animate ? 'transform .4s cubic-bezier(.22,1,.36,1)' : 'none';
    track.style.transform  = 'translate3d(' + x + 'px,0,0)';
}
function moveIndicator(btn){
    if (!tabInd || !btn) return;
    tabInd.style.left  = btn.offsetLeft + 'px';
    tabInd.style.width = btn.offsetWidth + 'px';
    tabInd.classList.add('ready');
}
function syncIndicator(){ moveIndicator(tabBtns[curIdx]); }
function indicatorAt(p){                         // underline follows the finger
    if (!tabInd || !tabBtns.length) return;
    p = Math.max(0, Math.min(tabBtns.length - 1, p));
    var i = Math.floor(p), f = p - i, a = tabBtns[i], b = tabBtns[Math.min(i + 1, tabBtns.length - 1)];
    tabInd.style.left  = (a.offsetLeft  + (b.offsetLeft  - a.offsetLeft)  * f) + 'px';
    tabInd.style.width = (a.offsetWidth + (b.offsetWidth - a.offsetWidth) * f) + 'px';
    tabInd.classList.add('ready');
}
function pauseVideos(){
    track.querySelectorAll('video').forEach(function(v){ try { v.pause(); } catch(e){} });
}
function finishSettle(){
    clearTimeout(settleTimer);
    pager.classList.add('idle');
    pager.classList.remove('dragging');
}
function goTo(i, animate){
    i = Math.max(0, Math.min(panels.length - 1, i));
    var changed = (i !== curIdx);
    curIdx = i;
    tabBtns.forEach(function(b, k){ b.classList.toggle('active', k === i); });
    panels.forEach(function(p, k){ p.classList.toggle('active', k === i); });

    pager.classList.remove('idle');
    tabInd.classList.remove('live');
    moveIndicator(tabBtns[i]);
    try { tabBtns[i].scrollIntoView({ inline:'nearest', block:'nearest' }); } catch(e){}
    if (changed){ pauseVideos(); if (window.pageYOffset > 0) window.scrollTo({ top:0, behavior:'smooth' }); }

    pageW = pager.clientWidth;
    setX(-i * pageW, animate);
    clearTimeout(settleTimer);
    if (animate) settleTimer = setTimeout(finishSettle, 440); else finishSettle();
}
tabBtns.forEach(function(btn, i){ btn.addEventListener('click', function(){ if (i !== curIdx) goTo(i, true); }); });
window.addEventListener('resize', function(){
    pageW = pager.clientWidth;
    setX(-curIdx * pageW, false);
    syncIndicator();
});

(function(){
    var st = null, swallowClick = false;

    // a drag must never also count as a tap on a post
    document.addEventListener('click', function(e){
        if (swallowClick){ e.stopPropagation(); e.preventDefault(); }
    }, true);

    pager.addEventListener('pointerdown', function(e){
        if (e.pointerType === 'mouse' && e.button !== 0) return;
        st = { id:e.pointerId, x:e.clientX, y:e.clientY, lock:null, samples:[{ x:e.clientX, t:performance.now() }] };
    });

    pager.addEventListener('pointermove', function(e){
        if (!st || e.pointerId !== st.id) return;
        var dx = e.clientX - st.x, dy = e.clientY - st.y;

        if (!st.lock){
            if (Math.abs(dx) < 6 && Math.abs(dy) < 6) return;
            if (Math.abs(dy) > Math.abs(dx)){ st = null; return; }     // vertical scroll — leave it alone
            st.lock = 'x';
            clearTimeout(settleTimer);
            pageW = pager.clientWidth;
            pager.classList.remove('idle');
            pager.classList.add('dragging');
            tabInd.classList.add('live');
            try { pager.setPointerCapture(e.pointerId); } catch(err){}
            var m = /translate3d\((-?[\d.]+)px/.exec(track.style.transform);
            st.base = m ? parseFloat(m[1]) : -curIdx * pageW;
            st.x = e.clientX; dx = 0;
        }

        var x = st.base + dx, minX = -(panels.length - 1) * pageW;
        if (x > 0)    x = x * 0.35;                    // rubber band at the first tab
        if (x < minX) x = minX + (x - minX) * 0.35;    // …and at the last
        setX(x, false);
        indicatorAt(-x / pageW);

        var now = performance.now();
        st.samples.push({ x:e.clientX, t:now });
        while (st.samples.length > 2 && now - st.samples[0].t > 110) st.samples.shift();
    });

    function release(e){
        if (!st || e.pointerId !== st.id) return;
        var g = st; st = null;
        if (g.lock !== 'x') return;
        try { pager.releasePointerCapture(e.pointerId); } catch(err){}
        swallowClick = true;
        setTimeout(function(){ swallowClick = false; }, 60);

        var dx = e.clientX - g.x;
        var a = g.samples[0], b = g.samples[g.samples.length - 1];
        var v = (b.t - a.t) > 10 ? (b.x - a.x) / (b.t - a.t) : 0;      // px per ms

        var target = curIdx;
        if (Math.abs(v) > 0.35)                 target = curIdx + (v < 0 ? 1 : -1);   // a flick
        else if (Math.abs(dx) > pageW * 0.22)   target = curIdx + (dx < 0 ? 1 : -1);  // dragged far enough
        goTo(target, true);
    }
    pager.addEventListener('pointerup', release);
    pager.addEventListener('pointercancel', release);
})();

// ── VIDEOS: load + play only when a card is on screen (same as the profile grid) ──
(function(){
    var vids = document.querySelectorAll('video.lazy-video[data-src]');
    if (!('IntersectionObserver' in window)) {
        vids.forEach(function(v){ v.src = v.dataset.src; });
        return;
    }
    var io = new IntersectionObserver(function(entries){
        entries.forEach(function(entry){
            var v = entry.target;
            if (entry.isIntersecting) {
                if (!v.src && v.dataset.src) v.src = v.dataset.src;
                var p = v.play();
                if (p && p.catch) p.catch(function(){});
            } else if (!v.paused) {
                v.pause();
            }
        });
    }, { rootMargin:'150px 0px', threshold:0.25 });
    vids.forEach(function(v){ io.observe(v); });
})();

function hsCmMore(b){ var p = b.parentNode; p.querySelector('.cm-short').hidden = true; p.querySelector('.cm-full').hidden = false; b.remove(); }

// ── Posts sub-tabs (Liked / Reposted / Saved / Viewed) ──
(function(){
    var btns = Array.prototype.slice.call(document.querySelectorAll('.hs-sub'));
    btns.forEach(function(b){
        b.addEventListener('click', function(){
            var id = 'sec-' + b.getAttribute('data-sub');
            btns.forEach(function(x){ x.classList.toggle('active', x === b); });
            document.querySelectorAll('.hs-sub-panel').forEach(function(p){ p.classList.toggle('active', p.id === id); });
            pauseVideos();
        });
    });
})();

// ── KEYBOARD: 1–4 switch tabs, ← → move between them (off while a viewer is open) ──
document.addEventListener('keydown', function(e){
    var reel = document.getElementById('cardCaptionModal');
    if (reel && reel.classList.contains('ccm-open')) return;
    if (e.target && e.target.closest && e.target.closest('input, textarea, select')) return;
    var n = parseInt(e.key, 10);
    if (n >= 1 && n <= tabBtns.length) goTo(n - 1, true);
    else if (e.key === 'ArrowRight') goTo(curIdx + 1, true);
    else if (e.key === 'ArrowLeft')  goTo(curIdx - 1, true);
});
</script>

<script>
/* ── GRID SKELETON + INFINITE SCROLL (same behaviour as the profile page grids) ──
   • the FIRST time a tab is opened (tap, swipe, or the tab the page starts on) its grid shows the shimmer skeleton, then reveals the first 9 posts
   • a sentinel at the bottom loads the next 9 as you scroll, with a short skeleton row each time
   • every tab does this on its own, and the first-open skeleton plays once per tab */
(function(){
    var PAGE = 9, SKEL_FIRST = 330, SKEL_MORE = 240;
    ['sec-likes','sec-reposts','sec-favourites','sec-viewed','sec-ads'].forEach(function(id){
        var panel = document.getElementById(id);
        if(!panel) return;
        var cards = Array.prototype.slice.call(panel.querySelectorAll('.meme-card'));
        if(!cards.length) return;
        var shown = 0, busy = false, started = false, io = null, sentinel = null;

        cards.forEach(function(c){ c.classList.add('hs-wait'); });
        panel.querySelectorAll('.date-group').forEach(function(g){ g.classList.add('hs-gone'); });

        function fold(){   /* a date group with no visible card yet stays folded away */
            panel.querySelectorAll('.date-group').forEach(function(g){
                g.classList.toggle('hs-gone', !g.querySelector('.meme-card:not(.hs-wait)'));
            });
        }
        function reveal(n){
            var end = Math.min(cards.length, shown + n);
            for(var i = shown; i < end; i++){
                (function(c){
                    c.classList.remove('hs-wait');
                    c.classList.add('dz-reveal');
                    setTimeout(function(){ c.classList.remove('dz-reveal'); }, 700);
                })(cards[i]);
            }
            shown = end;
            fold();
        }
        function skel(n){
            var w = document.createElement('div');
            w.className = 'hs-skel-wrap';
            var h = '<div class="grid">';
            for(var i = 0; i < n; i++) h += '<div class="dz-skel-card"></div>';
            w.innerHTML = h + '</div>';
            panel.insertBefore(w, sentinel || null);
            return w;
        }
        function watch(){
            if(shown >= cards.length){ if(io){ io.disconnect(); io = null; } if(sentinel){ sentinel.remove(); sentinel = null; } return; }
            if(!sentinel){ sentinel = document.createElement('div'); sentinel.className = 'hs-sentinel'; panel.appendChild(sentinel); }
            if(!('IntersectionObserver' in window)){ reveal(cards.length); watch(); return; }
            if(!io){
                io = new IntersectionObserver(function(en){
                    if(en.some(function(e){ return e.isIntersecting; })) more();
                }, { rootMargin:'600px 0px' });
            }
            io.unobserve(sentinel); io.observe(sentinel);        /* observing again re-checks: still on screen → next batch */
        }
        function more(){
            if(busy || !started || shown >= cards.length) return;
            busy = true;
            var d = skel(Math.min(3, cards.length - shown));
            setTimeout(function(){
                d.remove(); reveal(PAGE); busy = false; watch();
            }, SKEL_MORE);
        }
        function first(){
            if(started) return;
            started = true; busy = true;
            var d = skel(Math.min(PAGE, cards.length));
            setTimeout(function(){
                d.remove(); reveal(PAGE); busy = false; watch();
            }, SKEL_FIRST);
        }

        /* open for the first time: now (once the page skeleton has gone) if it is the starting tab, or when it becomes the active tab */
        function isOpen(){ var par = panel.closest('.hs-panel'); return panel.classList.contains('active') && (!par || par.classList.contains('active')); }
        function tryStart(){ if(isOpen()) first(); }
        new MutationObserver(tryStart).observe(panel, { attributes:true, attributeFilter:['class'] });
        var _par = panel.closest('.hs-panel'); if(_par && _par !== panel) new MutationObserver(tryStart).observe(_par, { attributes:true, attributeFilter:['class'] });
        if(isOpen()) setTimeout(tryStart, 520);
    });
})();
</script>

<?php /* ══ SHARED ZEEMS MODAL: likes / reposts / saves / shares / follow post to profile.php (the CSRF token is added by the fetch wrapper in <head>) ══ */ ?>
<script>
window.CCM_ENDPOINT = 'profile.php';
window.currentUserId = <?php echo (int)$current_user; ?>;
window.kebabData = <?php echo json_encode($kebab_data, $FEED_FLAGS | JSON_FORCE_OBJECT); ?>;
var _appTopZIndex = 2147483000;
function bringModalToFront(el){ if(el) el.style.zIndex = ++_appTopZIndex; }
window.peuraFeed = window.peuraFeed || [];            /* the list comes from hsOpen(); this only keeps the old global defined */
window._suggPromise = window._suggPromise || Promise.resolve([]);
</script>
<script>
/* me (name + picture) for the Zeems modal: my own repost shows in the social-proof strip the moment I tap, and leaves when I undo */
window.dzMe = <?php
    $__me = ['id'=>(int)$current_user, 'username'=>'', 'profile_pic'=>'uploads/mee.jpeg'];
    try{ $__mq = $conn->prepare('SELECT username, profile_pic FROM users WHERE id = ?'); $__mq->bind_param('i', $__me['id']); $__mq->execute(); $__mr = $__mq->get_result()->fetch_assoc();
         if($__mr){ $__me['username'] = (string)$__mr['username']; if(!empty($__mr['profile_pic'])) $__me['profile_pic'] = $__mr['profile_pic']; } }catch(Throwable $e){}
    echo json_encode($__me, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
?>;
</script>
<?php include __DIR__ . '/zeems_modal.php'; ?>
<?php include __DIR__ . '/shared_modals.php'; /* post menu (⋯), Save to Collection and Report — the same ones the other pages use */ ?>
<script>
/* the "⋯" button in the Zeems modal opens the shared post menu (Save, Report, Not interested…) — same as home and search —
   instead of the small built-in sheet that is used when a post is opened by id */
(function(){
    var orig = window.ccmMenuTap;
    window.ccmMenuTap = function(postId){
        if(typeof window.openIndexSheet === 'function' && window.kebabData && window.kebabData[postId]){ window.openIndexSheet(postId); return; }
        if(typeof orig === 'function') return orig.apply(this, arguments);
    };
})();
</script>
<script>
/* the menu, Save and Report sheets open on top of the Zeems modal (the share sheet keeps the very top) */
(function(){
    var MENU = '2147483637', BACK = '2147483636';
    var HIT  = /(index[-_]?sheet|index[-_]?menu|index[-_]?overlay|index[-_]?backdrop|post[-_]?menu|post[-_]?sheet|kebab[-_]?(menu|sheet)|options[-_]?(sheet|menu)|report|collection|save[-_]?(modal|sheet|to))/i;
    var SCRIM = /(overlay|backdrop|scrim|mask|dim)/i;
    function tag(el){ return ((el.id || '') + ' ' + (typeof el.className === 'string' ? el.className : '')); }
    function lift(){
        Array.prototype.forEach.call(document.body.children, function(el){
            if(el.nodeType !== 1 || el.tagName === 'SCRIPT' || el.tagName === 'STYLE') return;
            var t = tag(el);
            if(/share/i.test(t) || !HIT.test(t)) return;
            var cs = getComputedStyle(el);
            if(cs.position !== 'fixed' && cs.position !== 'absolute') return;
            el.style.setProperty('z-index', (SCRIM.test(t) && !/sheet|menu|modal|panel/i.test(t.replace(SCRIM, ''))) ? BACK : MENU, 'important');
        });
    }
    function wrap(name){
        var orig = window[name];
        if(typeof orig !== 'function' || orig._mnTop) return;
        var w = function(){ var r = orig.apply(this, arguments); lift(); setTimeout(lift, 60); setTimeout(lift, 300); return r; };
        w._mnTop = true; window[name] = w;
    }
    function wrapAll(){ ['openIndexSheet','openReportModal','openSaveModal','openCollectionsModal','openSaveToCollection'].forEach(wrap); }
    wrapAll(); window.addEventListener('load', wrapAll);
    new MutationObserver(function(muts){
        for(var i = 0; i < muts.length; i++){
            var added = muts[i].addedNodes;
            for(var j = 0; j < added.length; j++){
                var n = added[j];
                if(n.nodeType === 1 && HIT.test(tag(n))){ lift(); setTimeout(lift, 60); wrapAll(); return; }
            }
        }
    }).observe(document.body, {childList:true, subtree:false});
})();
</script>

</body>
</html>