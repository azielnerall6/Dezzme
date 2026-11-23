<?php
/* ══════════════════════════════════════════════════════════════
   SESSION — hardened cookie (same as the Tracks page)
══════════════════════════════════════════════════════════════ */
if (session_status() === PHP_SESSION_NONE) {
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

/* CSRF token (same session slot as profile.php): the Zeems modal posts its like / repost / save requests there */
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

$current_user = (int)($_SESSION['user_id'] ?? 0);
if(!$current_user){ header("Location: login.php"); exit(); }

$profile_id = (isset($_GET['user_id']) && (int)$_GET['user_id'] > 0) ? (int)$_GET['user_id'] : $current_user;
$is_owner   = ($current_user === $profile_id);

$user = $conn->query("SELECT id, username, profile_pic FROM users WHERE id=$profile_id LIMIT 1")->fetch_assoc();
if(!$user){ header("Location: 404.php"); exit(); }

/* ══════════════════════════════════════════════════════════════
   AJAX — sound collections, audio detail, post card
══════════════════════════════════════════════════════════════ */
/* ── builders for the Zeems modal (same shape profile.php / history build) ── */
function au_rows($conn, string $sql): array {
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

function au_prime($conn, array $rows, int $uid): array {
    $P = ['follow' => [], 'social' => [], 'media' => [], 'group' => [], 'audio' => [], 'counts' => [], 'inter' => []];
    $mids = []; $ups = [];
    foreach ($rows as $r) { $mids[(int)$r['id']] = 1; $ups[(int)$r['uploaded_by']] = 1; }
    if (!$mids) return $P;
    $m = implode(',', array_keys($mids));
    $u = implode(',', array_keys($ups));

    /* do I track the uploader */
    foreach (au_rows($conn, "SELECT following_id FROM followers WHERE follower_id=$uid AND following_id IN ($u)") as $x) {
        $P['follow'][(int)$x['following_id']] = true;
    }

    /* social proof: likes / reposts by people I track (reposts first, max 4) */
    $raw = [];
    $sp = au_rows($conn, "
        SELECT meme_id, id, username, profile_pic, action_type FROM (
            SELECT l.meme_id AS meme_id, u2.id, u2.username, u2.profile_pic, 'like' AS action_type
            FROM likes l JOIN users u2 ON u2.id = l.user_id
            JOIN followers f ON f.following_id = u2.id
            WHERE l.meme_id IN ($m) AND f.follower_id = $uid AND u2.id != $uid
            UNION
            SELECT r.meme_id AS meme_id, u2.id, u2.username, u2.profile_pic, 'repost' AS action_type
            FROM reposts r JOIN users u2 ON u2.id = r.user_id
            JOIN followers f ON f.following_id = u2.id
            WHERE r.meme_id IN ($m) AND f.follower_id = $uid AND u2.id != $uid
        ) sp
    ");
    foreach ($sp as $x) {
        $raw[(int)$x['meme_id']][] = ['id' => $x['id'], 'username' => $x['username'], 'profile_pic' => $x['profile_pic'], 'action_type' => $x['action_type']];
    }
    foreach ($raw as $mid => $list) {
        usort($list, function ($a, $b) { return ($a['action_type'] === 'repost' ? 0 : 1) <=> ($b['action_type'] === 'repost' ? 0 : 1); });
        $P['social'][$mid] = array_slice($list, 0, 4);
    }

    /* extra media of multi-media posts */
    foreach (au_rows($conn, "SELECT meme_id, file_name, type FROM meme_media WHERE meme_id IN ($m) ORDER BY meme_id, position ASC") as $x) {
        $P['media'][(int)$x['meme_id']][] = ['file' => 'uploads/' . $x['file_name'], 'type' => $x['type']];
    }

    /* pod / group */
    foreach (au_rows($conn, "SELECT gp.meme_id AS meme_id, gp.group_id AS group_id, g.title AS group_name FROM group_posts gp JOIN `groups` g ON g.id = gp.group_id WHERE gp.meme_id IN ($m)") as $x) {
        $mid = (int)$x['meme_id'];
        if (!isset($P['group'][$mid])) $P['group'][$mid] = $x;
    }

    /* attached sound */
    foreach (au_rows($conn, "SELECT ma.meme_id AS meme_id, a.id AS audio_id, a.title, a.artist, a.file_name, ma.is_original, ma.music_volume, ma.video_audio_volume FROM meme_audio ma JOIN audios a ON a.id = ma.audio_id WHERE ma.meme_id IN ($m)") as $x) {
        $mid = (int)$x['meme_id'];
        if (!isset($P['audio'][$mid])) $P['audio'][$mid] = $x;
    }

    /* counts */
    foreach (['like' => 'likes', 'repost' => 'reposts', 'fav' => 'favorites', 'share' => 'shares'] as $k => $t) {
        foreach (au_rows($conn, "SELECT meme_id, COUNT(*) c FROM `$t` WHERE meme_id IN ($m) GROUP BY meme_id") as $x) {
            $P['counts'][(int)$x['meme_id']][$k] = (int)$x['c'];
        }
    }

    /* did I like / repost / save it */
    foreach (['liked' => 'likes', 'reposted' => 'reposts', 'faved' => 'favorites'] as $k => $t) {
        foreach (au_rows($conn, "SELECT meme_id FROM `$t` WHERE meme_id IN ($m) AND user_id=$uid") as $x) {
            $P['inter'][(int)$x['meme_id']][$k] = true;
        }
    }
    return $P;
}

/* one post → the exact array the immersive modal + meme modal read (same shape profile.php builds) */
function au_feed_item(array $r, array $P, int $uid, string $context): array {
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
        'isVerified' => !empty($r['uploader_verified']),   /* modal: show the badge next to the username when true */
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


if(isset($_POST['sc_action'])){
    header('Content-Type: application/json');
    $sc_action = trim($_POST['sc_action']);

    if($sc_action === 'create_folder'){
        if(!$is_owner){ echo json_encode(['status'=>'error']); exit; }
        $name = trim($conn->real_escape_string($_POST['name'] ?? ''));
        $is_public = (int)($_POST['is_public'] ?? 0);
        if(empty($name)){ echo json_encode(['status'=>'error','message'=>'Name required']); exit; }
        $conn->query("INSERT INTO sound_folders (user_id, name, is_public, created_at) VALUES ($current_user, '$name', $is_public, NOW())");
        $new_id = $conn->insert_id;
        echo json_encode(['status'=>'ok','id'=>$new_id,'name'=>stripslashes($name),'is_public'=>$is_public]);
        exit;
    }
    if($sc_action === 'delete_folder'){
        if(!$is_owner){ echo json_encode(['status'=>'error']); exit; }
        $fid = (int)($_POST['folder_id'] ?? 0);
        $conn->query("DELETE FROM sound_folders WHERE id=$fid AND user_id=$current_user");
        if($conn->affected_rows > 0) $conn->query("DELETE FROM sound_folder_items WHERE folder_id=$fid");
        echo json_encode(['status'=>'ok']);
        exit;
    }
    if($sc_action === 'rename_folder'){
        if(!$is_owner){ echo json_encode(['status'=>'error']); exit; }
        $fid  = (int)($_POST['folder_id'] ?? 0);
        $name = trim($conn->real_escape_string($_POST['name'] ?? ''));
        if(!$fid || empty($name)){ echo json_encode(['status'=>'error']); exit; }
        $conn->query("UPDATE sound_folders SET name='$name' WHERE id=$fid AND user_id=$current_user");
        echo json_encode(['status'=>'ok']);
        exit;
    }
    if($sc_action === 'set_visibility'){
        if(!$is_owner){ echo json_encode(['status'=>'error']); exit; }
        $fid = (int)($_POST['folder_id'] ?? 0);
        $vis = (int)($_POST['is_public'] ?? 0);
        $conn->query("UPDATE sound_folders SET is_public=$vis WHERE id=$fid AND user_id=$current_user");
        echo json_encode(['status'=>'ok','is_public'=>$vis]);
        exit;
    }

    /* ── SET FOLDER COVER ── */
    if($sc_action === 'set_folder_cover'){
        if(!$is_owner){ echo json_encode(['status'=>'error']); exit; }
        $fid = (int)($_POST['folder_id'] ?? 0);
        $own = $conn->query("SELECT id FROM sound_folders WHERE id=$fid AND user_id=$current_user");
        if(!$fid || !$own->num_rows){ echo json_encode(['status'=>'error']); exit; }
        if(isset($_FILES['cover']) && $_FILES['cover']['error']==0){
            $ext = strtolower(pathinfo($_FILES['cover']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','webp','gif'];
            if(in_array($ext,$allowed)){
                $filename = 'folder_'.time().'_'.uniqid().'.'.$ext;
                @mkdir('uploads/folder_covers', 0775, true);
                move_uploaded_file($_FILES['cover']['tmp_name'], "uploads/folder_covers/".$filename);
                $conn->query("UPDATE sound_folders SET cover_image='".$conn->real_escape_string($filename)."' WHERE id=$fid AND user_id=$current_user");
                echo json_encode(['status'=>'ok','cover_image'=>$filename]);
                exit;
            }
        }
        echo json_encode(['status'=>'error','message'=>'Invalid file']);
        exit;
    }

    if($sc_action === 'add_to_folder'){
        if(!$is_owner){ echo json_encode(['status'=>'error']); exit; }
        $fid = (int)($_POST['folder_id'] ?? 0);
        $sid = (int)($_POST['sound_id']  ?? 0);
        $own = $conn->query("SELECT id FROM sound_folders WHERE id=$fid AND user_id=$current_user");
        if(!$own->num_rows){ echo json_encode(['status'=>'error']); exit; }
        $chk = $conn->query("SELECT id FROM sound_folder_items WHERE folder_id=$fid AND sound_id=$sid AND (item_type IS NULL OR item_type='sound')");
        if($chk->num_rows){ echo json_encode(['status'=>'already']); exit; }
        $conn->query("INSERT INTO sound_folder_items (folder_id, sound_id, added_at) VALUES ($fid, $sid, NOW())");
        $cnt = $conn->query("SELECT COUNT(*) as c FROM sound_folder_items WHERE folder_id=$fid")->fetch_assoc()['c'];
        echo json_encode(['status'=>'ok','item_count'=>(int)$cnt]);
        exit;
    }
    if($sc_action === 'remove_from_folder'){
        if(!$is_owner){ echo json_encode(['status'=>'error']); exit; }
        $fid = (int)($_POST['folder_id'] ?? 0);
        $sid = (int)($_POST['sound_id']  ?? 0);
        $own = $conn->query("SELECT id FROM sound_folders WHERE id=$fid AND user_id=$current_user");
        if(!$own->num_rows){ echo json_encode(['status'=>'error']); exit; }
        $conn->query("DELETE FROM sound_folder_items WHERE folder_id=$fid AND sound_id=$sid AND (item_type IS NULL OR item_type='sound')");
        $cnt = $conn->query("SELECT COUNT(*) as c FROM sound_folder_items WHERE folder_id=$fid")->fetch_assoc()['c'];
        echo json_encode(['status'=>'ok','item_count'=>(int)$cnt]);
        exit;
    }

    /* ── GET FOLDER ITEMS ── */
    if($sc_action === 'get_folder_items'){
        $fid = (int)($_POST['folder_id'] ?? 0);
        $frow = $conn->query("SELECT user_id, is_public FROM sound_folders WHERE id=$fid")->fetch_assoc();
        if(!$frow || ($frow['user_id'] != $current_user && !$frow['is_public'])){
            echo json_encode(['status'=>'error','items'=>[]]); exit;
        }
        $rows = [];

        $res  = $conn->query("
            SELECT s.id, s.title, s.file_name, s.picture, u.username, u.profile_pic, u.id AS original_user_id,
                   sfi.added_at, 'sound' AS kind
            FROM sound_folder_items sfi
            JOIN sounds s ON s.id = sfi.sound_id
            JOIN users  u ON u.id = s.original_user_id
            WHERE sfi.folder_id = $fid AND (sfi.item_type IS NULL OR sfi.item_type='sound')
            ORDER BY sfi.added_at DESC
        ");
        while($r=$res->fetch_assoc()) $rows[]=$r;

        $res2 = $conn->query("
            SELECT a.id, a.title, a.file_name, a.cover_art, u.username, u.profile_pic, u.id AS original_user_id,
                   sfi.added_at, 'audio' AS kind
            FROM sound_folder_items sfi
            JOIN audios a ON a.id = sfi.audio_id
            JOIN users  u ON u.id = a.uploader_id
            WHERE sfi.folder_id = $fid AND sfi.item_type = 'audio'
            ORDER BY sfi.added_at DESC
        ");
        while($r=$res2->fetch_assoc()) $rows[]=$r;

        usort($rows, function($a,$b){ return strtotime($b['added_at']) - strtotime($a['added_at']); });

        echo json_encode(['status'=>'ok','items'=>$rows]);
        exit;
    }
    if($sc_action === 'get_folders'){
        $rows = [];
        $vis_sql = $is_owner ? '' : ' AND f.is_public=1';
        $res = $conn->query("SELECT f.id, f.name, f.is_public, f.cover_image, f.created_at, (SELECT COUNT(*) FROM sound_folder_items fi WHERE fi.folder_id=f.id) as item_count FROM sound_folders f WHERE f.user_id=$profile_id$vis_sql ORDER BY f.created_at DESC");
        while($r=$res->fetch_assoc()) $rows[]=$r;
        echo json_encode(['status'=>'ok','folders'=>$rows]);
        exit;
    }

    /* ── AUDIO DETAIL (Original Audio page) ── */
    if($sc_action === 'get_audio_detail'){
        $aid = (int)($_POST['audio_id'] ?? 0);
        if(!$aid){ echo json_encode(['status'=>'error']); exit; }

        $audio = $conn->query("
            SELECT a.id, a.title, a.artist, a.album, a.file_name, a.cover_art,
                   a.duration, a.is_original, a.copyright_status,
                   a.play_count, a.save_count, a.repost_count, a.created_at,
                   u.id AS uploader_id, u.username, u.profile_pic
            FROM audios a
            JOIN users u ON u.id = a.uploader_id
            WHERE a.id=$aid
        ")->fetch_assoc();

        if(!$audio){ echo json_encode(['status'=>'error']); exit; }

        $usage_count = $conn->query("SELECT COUNT(*) AS c FROM meme_audio WHERE audio_id=$aid")->fetch_assoc()['c'] ?? 0;

        /* every post that uses this sound, with everything the Zeems modal shows */
        $arows = au_rows($conn, "
            SELECT m.*, u.username, u.nickname, u.profile_pic AS uploader_pic, u.is_verified AS uploader_verified,
                   mad.cta_text AS ad_cta_text, mad.cta_url AS ad_cta_url, mad.cta_color AS ad_cta_color
            FROM meme_audio ma
            JOIN memes m ON m.id = ma.meme_id
            JOIN users u ON u.id = m.uploaded_by
            LEFT JOIN meme_ads mad ON mad.meme_id = m.id
            WHERE ma.audio_id = $aid
              AND (m.is_adult = 0 OR m.uploaded_by = $current_user
                   OR EXISTS (SELECT 1 FROM group_posts gp JOIN group_members gm ON gm.group_id = gp.group_id WHERE gp.meme_id = m.id AND gm.user_id = $current_user))
              AND NOT EXISTS (SELECT 1 FROM blocks b WHERE (b.blocker_id = $current_user AND b.blocked_id = m.uploaded_by) OR (b.blocker_id = m.uploaded_by AND b.blocked_id = $current_user))
            ORDER BY m.created_at DESC
            LIMIT 60
        ");
        $AP = au_prime($conn, $arows, $current_user);
        $posts = []; $feed = []; $kebab = [];
        foreach($arows as $r){
            $mid = (int)$r['id'];
            $posts[] = ['id'=>$mid, 'file_name'=>$r['file_name'], 'type'=>$r['type'], 'views'=>$r['views'] ?? 0, 'cover_image'=>$r['cover_image'] ?? ''];
            $feed[]  = au_feed_item($r, $AP, $current_user, '');
            $kebab[$mid] = [
                'id'=>$mid, 'fileName'=>$r['file_name'], 'type'=>$r['type'], 'uploadedBy'=>(int)$r['uploaded_by'],
                'remixOf'=>!empty($r['remix_of']) ? (int)$r['remix_of'] : null,
                'caption'=>(string)($r['caption'] ?? ''), 'isAd'=>!empty($r['is_ad']),
                'posterPic'=>!empty($r['uploader_pic']) ? $r['uploader_pic'] : 'uploads/me.jpeg',
                'posterUsername'=>(string)($r['username'] ?? ''), 'posterVerified'=>!empty($r['uploader_verified']),
            ];
        }

        $sv = $conn->query("SELECT id FROM audio_saves WHERE user_id=$current_user AND audio_id=$aid");
        $is_saved = $sv && $sv->num_rows > 0;

        $fw = $conn->query("SELECT id FROM followers WHERE follower_id=$current_user AND following_id=".(int)$audio['uploader_id']);
        $is_following = $fw && $fw->num_rows > 0;

        $conn->query("UPDATE audios SET play_count = play_count + 1 WHERE id=$aid");

        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;
        echo json_encode([
            'status'       => 'ok',
            'audio'        => $audio,
            'usage_count'  => (int)$usage_count,
            'posts'        => $posts,
            'feed'         => $feed,
            'kebab'        => $kebab,
            'is_saved'     => $is_saved,
            'is_following' => $is_following
        ], $flags);
        exit;
    }

    /* ── SAVE / UNSAVE AUDIO ── */
    if($sc_action === 'toggle_save_audio'){
        $aid = (int)($_POST['audio_id'] ?? 0);
        if(!$aid){ echo json_encode(['status'=>'error']); exit; }

        $chk = $conn->query("SELECT id FROM audio_saves WHERE user_id=$current_user AND audio_id=$aid");
        if($chk->num_rows){
            $conn->query("DELETE FROM audio_saves WHERE user_id=$current_user AND audio_id=$aid");
            $conn->query("UPDATE audios SET save_count = GREATEST(save_count - 1, 0) WHERE id=$aid");
            echo json_encode(['status'=>'ok','saved'=>false]);
        } else {
            $conn->query("INSERT INTO audio_saves (user_id, audio_id) VALUES ($current_user, $aid)");
            $conn->query("UPDATE audios SET save_count = save_count + 1 WHERE id=$aid");
            echo json_encode(['status'=>'ok','saved'=>true]);
        }
        exit;
    }

    /* ── TOGGLE FOLLOW (Track/Untrack) ── */
    if($sc_action === 'toggle_follow'){
        $target_id = (int)($_POST['user_id'] ?? 0);
        if(!$target_id || $target_id === $current_user){ echo json_encode(['status'=>'error']); exit; }

        $chk = $conn->query("SELECT id FROM followers WHERE follower_id=$current_user AND following_id=$target_id");
        if($chk->num_rows){
            $conn->query("DELETE FROM followers WHERE follower_id=$current_user AND following_id=$target_id");
            echo json_encode(['status'=>'ok','following'=>false]);
        } else {
            $conn->query("INSERT INTO followers (follower_id, following_id) VALUES ($current_user, $target_id)");
            echo json_encode(['status'=>'ok','following'=>true]);
        }
        exit;
    }

    /* ── MY OWN FOLDERS (for the "add to collection" sheet) ── */
    if($sc_action === 'get_my_folders'){
        $rows = [];
        $res = $conn->query("SELECT f.id, f.name, f.is_public, f.cover_image,
            (SELECT COUNT(*) FROM sound_folder_items fi WHERE fi.folder_id=f.id) as item_count
            FROM sound_folders f WHERE f.user_id=$current_user ORDER BY f.created_at DESC");
        while($r=$res->fetch_assoc()) $rows[]=$r;
        echo json_encode(['status'=>'ok','folders'=>$rows]);
        exit;
    }

    /* ── ADD AUDIO TO A COLLECTION ── */
    if($sc_action === 'add_audio_to_folder'){
        $fid = (int)($_POST['folder_id'] ?? 0);
        $aid = (int)($_POST['audio_id'] ?? 0);
        if(!$fid || !$aid){ echo json_encode(['status'=>'error']); exit; }

        $own = $conn->query("SELECT id FROM sound_folders WHERE id=$fid AND user_id=$current_user");
        if(!$own->num_rows){ echo json_encode(['status'=>'error']); exit; }

        $chk = $conn->query("SELECT id FROM sound_folder_items WHERE folder_id=$fid AND item_type='audio' AND audio_id=$aid");
        if($chk->num_rows){ echo json_encode(['status'=>'already']); exit; }

        $conn->query("INSERT INTO sound_folder_items (folder_id, sound_id, audio_id, item_type, added_at) VALUES ($fid, NULL, $aid, 'audio', NOW())");
        $cnt = $conn->query("SELECT COUNT(*) as c FROM sound_folder_items WHERE folder_id=$fid")->fetch_assoc()['c'];
        echo json_encode(['status'=>'ok','item_count'=>(int)$cnt]);
        exit;
    }

    /* ── REMOVE AUDIO FROM A COLLECTION ── */
    if($sc_action === 'remove_audio_from_folder'){
        $fid = (int)($_POST['folder_id'] ?? 0);
        $aid = (int)($_POST['audio_id'] ?? 0);
        $own = $conn->query("SELECT id FROM sound_folders WHERE id=$fid AND user_id=$current_user");
        if(!$own->num_rows){ echo json_encode(['status'=>'error']); exit; }
        $conn->query("DELETE FROM sound_folder_items WHERE folder_id=$fid AND item_type='audio' AND audio_id=$aid");
        $cnt = $conn->query("SELECT COUNT(*) as c FROM sound_folder_items WHERE folder_id=$fid")->fetch_assoc()['c'];
        echo json_encode(['status'=>'ok','item_count'=>(int)$cnt]);
        exit;
    }

    /* ── GET POST CARD (reels-style viewer: "Zeems using this audio") ── */
    if($sc_action === 'get_post_card'){
        $mid = (int)($_POST['meme_id'] ?? 0);
        if(!$mid){ echo json_encode(['status'=>'error']); exit; }

        $post = $conn->query("
            SELECT m.id, m.file_name, m.type, m.caption, m.uploaded_by, m.created_at,
                   u.username, u.nickname, u.profile_pic
            FROM memes m
            JOIN users u ON u.id = m.uploaded_by
            WHERE m.id=$mid
        ")->fetch_assoc();

        if(!$post){ echo json_encode(['status'=>'error']); exit; }

        $like_count   = $conn->query("SELECT COUNT(*) AS c FROM likes WHERE meme_id=$mid")->fetch_assoc()['c'] ?? 0;
        $repost_count = $conn->query("SELECT COUNT(*) AS c FROM reposts WHERE meme_id=$mid")->fetch_assoc()['c'] ?? 0;
        $fav_count    = $conn->query("SELECT COUNT(*) AS c FROM favorites WHERE meme_id=$mid")->fetch_assoc()['c'] ?? 0;
        $share_count  = $conn->query("SELECT COUNT(*) AS c FROM shares WHERE meme_id=$mid")->fetch_assoc()['c'] ?? 0;

        $liked     = $conn->query("SELECT id FROM likes WHERE meme_id=$mid AND user_id=$current_user")->num_rows > 0;
        $reposted  = $conn->query("SELECT id FROM reposts WHERE meme_id=$mid AND user_id=$current_user")->num_rows > 0;
        $faved     = $conn->query("SELECT id FROM favorites WHERE meme_id=$mid AND user_id=$current_user")->num_rows > 0;
        $following = $conn->query("SELECT id FROM followers WHERE follower_id=$current_user AND following_id=".(int)$post['uploaded_by'])->num_rows > 0;

        $media = [[ 'file' => 'uploads/'.$post['file_name'], 'type' => $post['type'] ]];
        $mm = $conn->query("SELECT file_name, type FROM meme_media WHERE meme_id=$mid ORDER BY position ASC");
        if($mm) while($m = $mm->fetch_assoc()){
            $media[] = [ 'file' => 'uploads/'.$m['file_name'], 'type' => $m['type'] ];
        }

        $social_proof = [];
        $sp = $conn->query("
            SELECT u.id, u.username, u.profile_pic, 'like' AS action_type
            FROM likes l
            JOIN users u ON u.id = l.user_id
            JOIN followers f ON f.following_id = u.id
            WHERE l.meme_id = $mid AND f.follower_id = $current_user AND u.id != $current_user
            UNION
            SELECT u.id, u.username, u.profile_pic, 'repost' AS action_type
            FROM reposts r
            JOIN users u ON u.id = r.user_id
            JOIN followers f ON f.following_id = u.id
            WHERE r.meme_id = $mid AND f.follower_id = $current_user AND u.id != $current_user
            LIMIT 4
        ");
        if($sp) while($s = $sp->fetch_assoc()) $social_proof[] = $s;

        $audio_info = $conn->query("
            SELECT ma.meme_id, a.id AS audio_id, a.title, a.artist, a.file_name,
                   ma.is_original, ma.music_volume, ma.video_audio_volume
            FROM meme_audio ma
            JOIN audios a ON a.id = ma.audio_id
            WHERE ma.meme_id = $mid
            LIMIT 1
        ")->fetch_assoc();

        echo json_encode([
            'status' => 'ok',
            'post' => [
                'id'           => (int)$post['id'],
                'file_name'    => $post['file_name'],
                'type'         => $post['type'],
                'caption'      => $post['caption'],
                'time'         => $post['created_at'],
                'uploader_id'  => (int)$post['uploaded_by'],
                'username'     => $post['username'],
                'nickname'     => $post['nickname'] ?? '',
                'profile_pic'  => $post['profile_pic'] ?: 'uploads/me.jpeg',
                'like_count'   => (int)$like_count,
                'repost_count' => (int)$repost_count,
                'fav_count'    => (int)$fav_count,
                'share_count'  => (int)$share_count,
                'liked'        => $liked,
                'reposted'     => $reposted,
                'faved'        => $faved,
                'following'    => $following,
                'media'        => $media,
                'social_proof' => $social_proof,
                'audio_id'           => $audio_info ? (int)$audio_info['audio_id'] : null,
                'audio_title'        => $audio_info ? $audio_info['title'] : null,
                'audio_artist'       => $audio_info && !empty($audio_info['artist']) ? $audio_info['artist'] : null,
                'audio_file'         => $audio_info ? 'uploads/'.$audio_info['file_name'] : null,
                'audio_is_original'  => $audio_info ? (bool)$audio_info['is_original'] : false,
                'music_volume'       => $audio_info ? (int)$audio_info['music_volume'] : 80,
                'video_audio_volume' => $audio_info ? (int)$audio_info['video_audio_volume'] : 40,
            ]
        ]);
        exit;
    }

    /* ── TOGGLE LIKE / REPOST / FAV from the post-card viewer ── */
    if($sc_action === 'toggle_meme_action'){
        $mid  = (int)($_POST['meme_id'] ?? 0);
        $type = $_POST['type'] ?? '';
        $allowed_types = ['like'=>'likes', 'repost'=>'reposts', 'fav'=>'favorites'];
        if(!$mid || !isset($allowed_types[$type])){
            echo json_encode(['status'=>'error']); exit;
        }
        $table = $allowed_types[$type];

        $chk = $conn->query("SELECT id FROM $table WHERE meme_id=$mid AND user_id=$current_user");
        if($chk->num_rows){
            $conn->query("DELETE FROM $table WHERE meme_id=$mid AND user_id=$current_user");
            $active = false;
        } else {
            $conn->query("INSERT INTO $table (user_id, meme_id) VALUES ($current_user, $mid)");
            $active = true;
        }
        $count = $conn->query("SELECT COUNT(*) AS c FROM $table WHERE meme_id=$mid")->fetch_assoc()['c'] ?? 0;

        echo json_encode(['status'=>'ok', 'active'=>$active, 'count'=>(int)$count]);
        exit;
    }

    echo json_encode(['status'=>'error','message'=>'Unknown action']);
    exit;
}

/* ══════════════════════════════════════════════════════════════
   UPLOAD A SOUND (owner only)
══════════════════════════════════════════════════════════════ */
if(isset($_POST['upload_sound']) && $is_owner){
    if(isset($_FILES['audio']) && $_FILES['audio']['error']==0){
        $title = $conn->real_escape_string(trim($_POST['title'] ?? ''));
        $file  = $_FILES['audio'];
        $ext   = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['mp3','wav','ogg'];
        if($title !== '' && in_array($ext, $allowed)){
            $filename = time().'_'.uniqid().'.'.$ext;
            @mkdir('uploads/sounds', 0775, true);
            move_uploaded_file($file['tmp_name'], "uploads/sounds/".$filename);

            $picture_filename = null;
            if(isset($_FILES['picture']) && $_FILES['picture']['error']==0){
                $pext = strtolower(pathinfo($_FILES['picture']['name'], PATHINFO_EXTENSION));
                if(in_array($pext, ['jpg','jpeg','png','webp','gif'])){
                    $picture_filename = 'cover_'.time().'_'.uniqid().'.'.$pext;
                    @mkdir('uploads/sounds/covers', 0775, true);
                    move_uploaded_file($_FILES['picture']['tmp_name'], "uploads/sounds/covers/".$picture_filename);
                }
            }

            if($picture_filename){
                $conn->query("INSERT INTO sounds (user_id, original_user_id, title, file_name, picture) VALUES ($current_user,$current_user,'$title','$filename','".$conn->real_escape_string($picture_filename)."')");
            } else {
                $conn->query("INSERT INTO sounds (user_id, original_user_id, title, file_name) VALUES ($current_user,$current_user,'$title','$filename')");
            }
        }
    }
}

/* ── Save / Unsave a sound ── */
if(isset($_POST['action'])){
    $sound_id = (int)($_POST['sound_id'] ?? 0);
    if($_POST['action']=='save')   $conn->query("INSERT IGNORE INTO saved_sounds (user_id,sound_id) VALUES ($current_user,$sound_id)");
    if($_POST['action']=='unsave') $conn->query("DELETE FROM saved_sounds WHERE user_id=$current_user AND sound_id=$sound_id");
    exit();
}

/* ══════════════════════════════════════════════════════════════
   PAGE DATA
══════════════════════════════════════════════════════════════ */
$my_sounds = $conn->query("
    SELECT s.*, u.username, u.profile_pic
    FROM sounds s JOIN users u ON s.original_user_id=u.id
    WHERE s.user_id=$profile_id ORDER BY s.created_at DESC
");

/* saved = sounds + original audios, merged and sorted */
$added_sounds_rows = [];

$r1 = $conn->query("
    SELECT s.id, s.title, s.file_name, s.picture, u.username, u.profile_pic, u.id AS original_user_id,
           ss.created_at AS saved_at, 'sound' AS kind
    FROM saved_sounds ss
    JOIN sounds s ON ss.sound_id=s.id
    JOIN users  u ON u.id=s.original_user_id
    WHERE ss.user_id=$profile_id
");
if($r1) while($row=$r1->fetch_assoc()) $added_sounds_rows[] = $row;

$r2 = $conn->query("
    SELECT a.id, a.title, a.file_name, a.cover_art, u.username, u.profile_pic, u.id AS original_user_id,
           asv.created_at AS saved_at, 'audio' AS kind
    FROM audio_saves asv
    JOIN audios a ON asv.audio_id=a.id
    JOIN users  u ON u.id=a.uploader_id
    WHERE asv.user_id=$profile_id
");
if($r2) while($row=$r2->fetch_assoc()) $added_sounds_rows[] = $row;

usort($added_sounds_rows, function($a,$b){ return strtotime($b['saved_at']) - strtotime($a['saved_at']); });

/* collections */
$sound_folders = [];
$vis_sql = $is_owner ? '' : ' AND f.is_public=1';
$fr = $conn->query("SELECT f.id, f.name, f.is_public, f.cover_image, (SELECT COUNT(*) FROM sound_folder_items fi WHERE fi.folder_id=f.id) as item_count FROM sound_folders f WHERE f.user_id=$profile_id$vis_sql ORDER BY f.created_at DESC");
while($r=$fr->fetch_assoc()) $sound_folders[]=$r;

$sounds_count  = (int)($conn->query("SELECT COUNT(*) AS c FROM sounds WHERE user_id=$profile_id")->fetch_assoc()['c'] ?? 0);
$added_count   = count($added_sounds_rows);
$folders_count = count($sound_folders);

/* thumbnail path for a sound / audio row */
function sc_thumb_path($row){
    $kind = $row['kind'] ?? 'sound';
    if($kind === 'audio') return !empty($row['cover_art']) ? 'uploads/'.$row['cover_art'] : '';
    return !empty($row['picture']) ? 'uploads/sounds/covers/'.$row['picture'] : '';
}

/* one row of the sound list — used by "Sounds" and "Saved" */
function sc_render_row($s, $is_owner, $mode){
    $kind     = $s['kind'] ?? 'sound';
    $basePath = ($kind === 'audio') ? 'uploads/' : 'uploads/sounds/';
    $thumb    = sc_thumb_path($s + ['kind' => $kind]);
    $id       = (int)$s['id'];
    $src      = $basePath . $s['file_name'];

    /* what the "save" button inside the player should do for this row */
    $save = '';
    if($mode === 'saved' && $is_owner)                 $save = 'unsave';
    elseif($mode === 'saved' && $kind === 'sound')     $save = 'save';
    elseif($mode === 'uploaded' && !$is_owner)         $save = 'save';

    $who = ($mode === 'uploaded' && $is_owner) ? 'You' : $s['username'];
?>
    <div class="sound-row"
         data-id="<?=$id?>" data-kind="<?=htmlspecialchars($kind)?>" data-save="<?=$save?>"
         data-title="<?=htmlspecialchars(strtolower($s['title']))?>"
         data-user="<?=htmlspecialchars(strtolower($s['username']))?>"
         data-src="<?=htmlspecialchars($src)?>"
         data-thumb="<?=htmlspecialchars($thumb)?>"
         onclick="scOpenListenModal(this)">
        <div class="sound-thumb">
            <?php if($thumb): ?><img src="<?=htmlspecialchars($thumb)?>" alt="" loading="lazy" onerror="this.parentElement.innerHTML='<i class=&quot;ri-music-2-line&quot;></i>'"><?php else: ?><i class="ri-music-2-line"></i><?php endif; ?>
        </div>
        <div class="sound-meta">
            <div class="sound-title"><?=htmlspecialchars($s['title'])?></div>
            <div class="sound-artist"><?=htmlspecialchars($who)?></div>
        </div>
        <div class="sound-actions" onclick="event.stopPropagation()">
            <a class="s-btn" href="<?=htmlspecialchars($src)?>" download title="Download" aria-label="Download"><i class="ri-download-line"></i></a>
            <?php if($is_owner): ?>
                <button type="button" class="s-btn" title="Add to collection" aria-label="Add to collection" onclick="scOpenAddToFolder(<?=$id?>,'<?=$kind?>')"><i class="ri-folder-add-line"></i></button>
                <?php if($mode === 'uploaded'): ?>
                <button type="button" class="s-btn danger" title="Delete" aria-label="Delete" onclick="scDeleteSound(<?=$id?>)"><i class="ri-delete-bin-line"></i></button>
                <?php else: ?>
                <button type="button" class="s-btn danger" title="Remove from saved" aria-label="Remove from saved" onclick="scUnsaveSound(<?=$id?>,'<?=$kind?>')"><i class="ri-close-circle-line"></i></button>
                <?php endif; ?>
            <?php elseif($save === 'save'): ?>
                <button type="button" class="s-btn" title="Save" aria-label="Save" onclick="scSaveSound(<?=$id?>,'<?=$kind?>')"><i class="ri-add-circle-line"></i></button>
            <?php endif; ?>
        </div>
    </div>
<?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script>try{var _t=localStorage.getItem('dz_theme');if(!_t){var _m=document.cookie.match(/(?:^|; )dz_theme=(light|dark)/);_t=_m?_m[1]:'';}if(_t==='light'){document.documentElement.setAttribute('data-theme','light');var _mc=document.querySelector('meta[name="theme-color"]');if(_mc)_mc.setAttribute('content','#ffffff');}}catch(e){}</script>
<meta name="csrf-token" content="<?php echo htmlspecialchars($dz_csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
<script>
/* 🔒 CSRF front-end — the Zeems modal posts likes, reposts, saves and shares to profile.php,
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
<title><?php echo htmlspecialchars($user['username']); ?> · Audio Library</title>

<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">

<style>
/* ══════════════════════════════════════
   BASE + TOKENS
   --m = responsive scale. 1 on desktop, 1.1 on screens ≤800px.
   Change 1.1 to any number to resize the whole responsive view.
══════════════════════════════════════ */
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; font-family:'Poppins', sans-serif; }
button, a { cursor:pointer; outline:none; -webkit-tap-highlight-color:transparent; }
a{ color:inherit; text-decoration:none; }
button{ border:none; background:none; color:inherit; }
button:focus-visible, a:focus-visible, input:focus-visible{ outline:2px solid rgba(255,45,45,.75); outline-offset:2px; }

[class^="ri-"], [class*=" ri-"],
[class^="ri-"]::before, [class*=" ri-"]::before,
[class^="ri-"]::after,  [class*=" ri-"]::after {
    font-family:'remixicon' !important;
    font-style:normal; font-weight:normal; line-height:1;
    speak:none; text-transform:none;
    -webkit-font-smoothing:antialiased;
}

:root{
    --m:        1;
    --red:      #FF2D2D;
    --fg:       255,255,255;
    --ink:      #ffffff;
    --solid:    #070924;
    --tile:     #0b0c24;
    --cover-a:  #3a3b46;
    --cover-b:  #171822;
    --red-hover:#ff1a1a;
    --red-soft: rgba(255,45,45,.14);
    --red-text: #ff8a8a;
    --bg:       linear-gradient(-45deg,#010218,#070924,#070924);
    --surface:  rgba(var(--fg),0.05);
    --line:     rgba(var(--fg),0.09);
    --line2:    rgba(var(--fg),0.14);
    --text:     var(--ink);
    --muted:    rgba(var(--fg),0.45);
    --sub:      rgba(var(--fg),0.62);
    --panel:    rgba(12,12,28,0.98);

    --max-w:    580px;
    --topbar-h: calc(54px * var(--m));
    --pad:      calc(clamp(9px, 3vw, 16px) * var(--m));
    --ctl-h:    calc(clamp(30px, 8vw, 36px) * var(--m));
    --act-h:    calc(clamp(25px, 7.2vw, 30px) * var(--m));
    --r:        calc(5px * var(--m));
    --r-btn:    calc(4px * var(--m));
}
@media (max-width:800px){ :root{ --m:1.1; } }
@media (max-width:490px){ :root{ --topbar-h:calc(48px * var(--m)); } }
@media (max-width:380px){ :root{ --topbar-h:calc(44px * var(--m)); } }

html{ scroll-behavior:smooth; }

@media (max-width:768px){
    html, body, *{ scrollbar-width:none !important; -ms-overflow-style:none !important; }
    *::-webkit-scrollbar, html::-webkit-scrollbar, body::-webkit-scrollbar{ display:none !important; width:0 !important; height:0 !important; background:transparent !important; }
}

body{
    background:var(--bg); background-attachment:fixed;
    color:var(--text); min-height:100vh;
    display:flex; justify-content:center;
    overflow-x:hidden; -webkit-font-smoothing:antialiased;
}

@media (prefers-reduced-motion:reduce){
    *, *::before, *::after{ animation-duration:.01ms !important; animation-iteration-count:1 !important; transition-duration:.01ms !important; scroll-behavior:auto !important; }
}

/* centered lane with the two grey side lines */
.au-lane{
    width:100%; max-width:var(--max-w); min-height:100vh;
    display:flex; flex-direction:column; padding-bottom:calc(96px * var(--m));
}
@media (min-width:801px){ .au-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); } }
@media (max-width:800px){ .au-lane{ max-width:100%; } }

/* ══════════════════════════════════════
   TOPBAR
══════════════════════════════════════ */
.au-topbar{
    position:sticky; top:0; z-index:500; height:var(--topbar-h);
    display:flex; align-items:center; gap:calc(2px * var(--m)); padding:0 calc(10px * var(--m));
    background:var(--solid);
}
.au-topbar::after{
    content:''; position:absolute; left:0; right:0; bottom:0;
    height:1px; background:var(--line2); pointer-events:none; z-index:2;
}
.au-x{
    width:calc(34px * var(--m)); height:calc(34px * var(--m)); color:var(--ink); flex-shrink:0;
    display:flex; align-items:center; justify-content:center;
    opacity:.9; transition:opacity .2s, color .2s;
}
.au-x:hover{ opacity:1; color:var(--red); }
.au-x svg{ width:calc(22px * var(--m)); height:calc(22px * var(--m)); display:block; }
.au-title{
    flex:1; min-width:0; margin:0 calc(6px * var(--m)) 0 calc(10px * var(--m)); text-align:right;
    font-size:calc(.92rem * var(--m)); font-weight:700; letter-spacing:-.1px;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
@media (max-width:490px){ .au-x{ width:calc(30px * var(--m)); height:calc(30px * var(--m)); } .au-title{ font-size:calc(.84rem * var(--m)); } }
@media (max-width:380px){ .au-title{ font-size:calc(.8rem * var(--m)); } }

/* ══════════════════════════════════════
   UPLOAD (owner) — collapsible info row
══════════════════════════════════════ */
.up-toggle{
    display:flex; align-items:center; gap:calc(clamp(10px, 3vw, 14px) * var(--m)); width:100%; text-align:left;
    padding:calc(clamp(11px, 3.2vw, 16px) * var(--m)) var(--pad); border-bottom:1px solid var(--line);
    transition:background .18s;
}
.up-toggle:hover{ background:rgba(var(--fg),0.04); }
.info-icon{
    width:calc(clamp(27px, 7.6vw, 36px) * var(--m)); height:calc(clamp(27px, 7.6vw, 36px) * var(--m));
    border-radius:calc(6px * var(--m)); background:var(--surface); color:var(--red);
    display:flex; align-items:center; justify-content:center; flex-shrink:0;
    font-size:calc(clamp(.78rem, 2.6vw, 1rem) * var(--m)); transition:background .2s;
}
.up-toggle:hover .info-icon{ background:var(--red-soft); }
.info-content{ flex:1; min-width:0; }
.info-label{ font-size:calc(clamp(.54rem, 1.8vw, .66rem) * var(--m)); color:var(--muted); text-transform:uppercase; letter-spacing:.05em; line-height:1.2; }
.info-value{ font-size:calc(clamp(.72rem, 2.4vw, .86rem) * var(--m)); font-weight:500; line-height:1.4; margin-top:calc(2px * var(--m)); }
.chevron{ color:var(--muted); font-size:calc(1rem * var(--m)); flex-shrink:0; transition:transform .25s ease; }
.up-toggle.open .chevron, .hp-toggle.open .chevron{ transform:rotate(90deg); color:var(--ink); }

.up-section{ display:none; padding:calc(12px * var(--m)) var(--pad) calc(16px * var(--m)); border-bottom:1px solid var(--line); }
.up-section.open{ display:block; animation:auFade .2s ease both; }
@keyframes auFade{ from{ opacity:0; } to{ opacity:1; } }
.up-form{ display:flex; flex-direction:column; gap:calc(10px * var(--m)); }
.up-field, .up-file{
    width:100%; height:var(--ctl-h); padding:0 calc(12px * var(--m));
    background:var(--surface); border:1px solid var(--line2); border-radius:var(--r);
    color:var(--ink); font-size:calc(.76rem * var(--m)); outline:none; -webkit-appearance:none; appearance:none;
    transition:border-color .2s, background .2s, box-shadow .2s;
}
.up-field::placeholder{ color:var(--muted); }
.up-field:focus{ border-color:rgba(255,45,45,.7); background:rgba(var(--fg),0.07); box-shadow:0 0 0 3px rgba(255,45,45,.12); }
.up-file{ display:flex; align-items:center; gap:calc(8px * var(--m)); color:var(--muted); cursor:pointer; }
.up-file:hover{ color:var(--ink); border-color:rgba(255,45,45,.45); }
.up-file i{ color:var(--red); font-size:calc(.95rem * var(--m)); }
.up-file span{ flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.up-file input{ display:none; }

.au-btn{
    display:flex; align-items:center; justify-content:center; gap:calc(6px * var(--m)); flex-shrink:0;
    height:var(--ctl-h); padding:0 calc(14px * var(--m)); border-radius:var(--r-btn);
    font-size:calc(.74rem * var(--m)); font-weight:700; line-height:1; white-space:nowrap;
    transition:background .2s, transform .15s;
}
.au-btn:active{ transform:scale(.97); }
.au-btn.primary{ background:var(--red); color:#000; }
.au-btn.primary:hover{ background:var(--red-hover); }
.au-btn.ghost{ background:rgba(var(--fg),.1); color:var(--ink); font-weight:600; backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px); }
.au-btn.ghost:hover{ background:rgba(var(--fg),.2); }

/* ══════════════════════════════════════
   TABS
══════════════════════════════════════ */
.au-tabs{
    position:sticky; top:calc(var(--topbar-h) - 1px); z-index:400;
    display:flex; background:var(--solid);
    border-top:1px solid var(--line); border-bottom:1px solid var(--line);
}
.au-tabs-track{ position:relative; display:flex; flex:1; }
.au-tab-ind{
    position:absolute; bottom:0; left:0; height:2px; width:0; background:#fff; border-radius:2px 2px 0 0;
    transition:left .28s cubic-bezier(.4,0,.2,1), width .28s cubic-bezier(.4,0,.2,1), opacity .2s ease;
    opacity:0; pointer-events:none;
}
.au-tab-ind.ready{ opacity:1; }
.au-tab{
    flex:1; min-width:0; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:calc(2px * var(--m));
    padding:calc(10px * var(--m)) calc(8px * var(--m)); color:rgba(var(--fg),.5); transition:color .2s;
}
.au-tab:hover{ color:rgba(var(--fg),.85); }
.au-tab.active{ color:var(--ink); }
.au-tab-count{ font-size:calc(.9rem * var(--m)); font-weight:700; color:var(--ink); line-height:1.1; }
.au-tab-label{ font-size:calc(.66rem * var(--m)); font-weight:500; letter-spacing:.02em; }
@media (max-width:490px){ .au-tab{ padding:calc(9px * var(--m)) calc(6px * var(--m)); } .au-tab-count{ font-size:calc(.82rem * var(--m)); } .au-tab-label{ font-size:calc(.6rem * var(--m)); } }
@media (max-width:380px){ .au-tab-count{ font-size:calc(.76rem * var(--m)); } .au-tab-label{ font-size:calc(.56rem * var(--m)); } }

/* search */
.au-search{ position:relative; display:flex; align-items:center; padding:calc(12px * var(--m)) var(--pad) calc(4px * var(--m)); }
.au-search i{ position:absolute; left:calc(var(--pad) + 11px * var(--m)); color:var(--muted); font-size:calc(1rem * var(--m)); pointer-events:none; }
.au-search input{
    width:100%; height:var(--ctl-h); padding:0 calc(12px * var(--m)) 0 calc(34px * var(--m));
    background:var(--surface); border:1px solid var(--line2); border-radius:var(--r);
    font-size:calc(.76rem * var(--m)); color:var(--ink); outline:none; -webkit-appearance:none; appearance:none;
    transition:border-color .2s, background .2s, box-shadow .2s;
}
.au-search input::placeholder{ color:var(--muted); }
.au-search input:focus{ border-color:rgba(255,45,45,.7); background:rgba(var(--fg),.07); box-shadow:0 0 0 3px rgba(255,45,45,.12); }
.au-search.off{ display:none; }
@media (max-width:490px){ .au-search input{ font-size:calc(.7rem * var(--m)); padding-left:calc(32px * var(--m)); } .au-search i{ left:calc(var(--pad) + 10px * var(--m)); font-size:calc(.92rem * var(--m)); } }

/* panels */
.au-pager{ width:100%; overflow:hidden; touch-action:pan-y; }
.au-pager.dragging{ user-select:none; -webkit-user-select:none; cursor:grabbing; }
.au-track{ display:flex; align-items:flex-start; width:100%; will-change:transform; backface-visibility:hidden; }
.au-panel{ flex:0 0 100%; width:100%; min-width:0; }
.au-pager.idle .au-panel:not(.active){ max-height:0; overflow:hidden; visibility:hidden; }
.au-note{ font-size:calc(.7rem * var(--m)); color:var(--muted); line-height:1.55; padding:calc(12px * var(--m)) var(--pad) calc(10px * var(--m)); border-bottom:1px solid var(--line); }
@media (max-width:490px){ .au-note{ font-size:calc(.64rem * var(--m)); } }

/* ══════════════════════════════════════
   SOUND ROW
══════════════════════════════════════ */
.sound-row{
    display:flex; align-items:center; gap:calc(clamp(10px, 3vw, 14px) * var(--m));
    padding:calc(12px * var(--m)) var(--pad); border-bottom:1px solid rgba(var(--fg),0.06);
    cursor:pointer; transition:background .15s;
}
.sound-row:hover{ background:rgba(var(--fg),0.03); }
.sound-row:active{ background:rgba(var(--fg),0.06); }
.sound-thumb{
    width:calc(clamp(38px, 10.5vw, 46px) * var(--m)); height:calc(clamp(38px, 10.5vw, 46px) * var(--m));
    border-radius:calc(6px * var(--m)); flex-shrink:0; overflow:hidden;
    background:var(--surface); border:2px solid var(--red);
    display:flex; align-items:center; justify-content:center;
}
.sound-thumb img{ width:100%; height:100%; object-fit:cover; display:block; }
.sound-thumb i{ font-size:calc(1.15rem * var(--m)); color:var(--red); }
.sound-meta{ flex:1; min-width:0; }
.sound-title{ font-size:calc(.84rem * var(--m)); font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; transition:color .2s; }
.sound-artist{
    display:block; margin-top:calc(2px * var(--m)); max-width:100%;
    font-size:calc(.7rem * var(--m)); color:var(--muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; transition:color .2s;
}
.sound-row.is-playing .sound-title{ color:var(--red); }
.sound-row.is-playing .sound-thumb{ box-shadow:0 0 0 3px var(--red-soft); }
@media (max-width:490px){ .sound-thumb{ border-width:1.5px; } }
@media (max-width:490px){ .sound-title{ font-size:calc(.78rem * var(--m)); } .sound-artist{ font-size:calc(.64rem * var(--m)); } }
@media (max-width:380px){ .sound-title{ font-size:calc(.74rem * var(--m)); } .sound-artist{ font-size:calc(.6rem * var(--m)); } }

.sound-actions{ display:flex; align-items:center; gap:calc(2px * var(--m)); flex-shrink:0; }
.s-btn{
    width:calc(clamp(28px, 7.6vw, 32px) * var(--m)); height:calc(clamp(28px, 7.6vw, 32px) * var(--m)); border-radius:var(--r-btn);
    color:var(--muted); font-size:calc(.92rem * var(--m)); flex-shrink:0;
    display:flex; align-items:center; justify-content:center; transition:background .2s, color .2s;
}
.s-btn:hover{ background:rgba(var(--fg),.1); color:var(--ink); }
.s-btn.danger:hover{ background:var(--red-soft); color:var(--red); }
.s-remove{ width:calc(26px * var(--m)); height:calc(26px * var(--m)); border-radius:50%; background:var(--red-soft); color:var(--red); font-size:calc(.8rem * var(--m)); display:flex; align-items:center; justify-content:center; transition:background .2s, color .2s; }
.s-remove:hover{ background:var(--red); color:#000; }

/* empty states */
.empty-state{ padding:calc(48px * var(--m)) calc(20px * var(--m)) calc(32px * var(--m)); text-align:center; color:var(--muted); display:flex; flex-direction:column; align-items:center; gap:calc(10px * var(--m)); }
.empty-state i{ font-size:calc(2.1rem * var(--m)); color:var(--red); opacity:.85; }
.empty-state span{ font-size:calc(.8rem * var(--m)); }
.empty-state small{ font-size:calc(.68rem * var(--m)); opacity:.7; max-width:calc(260px * var(--m)); line-height:1.55; }
.au-noresult{ display:none; }

/* ══════════════════════════════════════
   COLLECTIONS — stacked cover cards
══════════════════════════════════════ */
.folders-grid{ display:grid; grid-template-columns:repeat(auto-fill, minmax(calc(140px * var(--m)),1fr)); gap:calc(22px * var(--m)) calc(14px * var(--m)); padding:calc(16px * var(--m)) var(--pad) calc(8px * var(--m)); }
.folder-card{ cursor:pointer; }
.fc-wrap{ position:relative; margin:calc(8px * var(--m)) 0 calc(10px * var(--m)); }
.fc-wrap::before{
    content:''; position:absolute; left:50%; top:calc(-7px * var(--m)); width:86%; height:100%; margin-left:-43%; z-index:0;
    border-radius:calc(8px * var(--m)); background:var(--cover-a); border:1px solid rgba(var(--fg),.1); transition:transform .22s ease;
}
.folder-card:hover .fc-wrap::before{ transform:translateY(-2px); }
.folder-card-stack{
    position:relative; z-index:1; width:100%; aspect-ratio:1; overflow:hidden; border-radius:calc(8px * var(--m));
    background:var(--cover-b); border:1px solid var(--line2); transition:border-color .22s;
}
.folder-card:hover .folder-card-stack{ border-color:rgba(255,45,45,.45); }
.stack-front{
    position:absolute; inset:0; display:grid; grid-template-columns:1fr 1fr; grid-template-rows:1fr 1fr; gap:2px;
    background-size:cover; background-position:center;
}
.stack-front.has-cover .fp-cell{ display:none; }
.fp-cell{ background:rgba(var(--fg),.03); display:flex; align-items:center; justify-content:center; }
.fp-cell i{ font-size:calc(.9rem * var(--m)); color:rgba(var(--fg),.1); }
.folder-pub-badge{
    position:absolute; top:calc(6px * var(--m)); left:calc(6px * var(--m)); z-index:5; pointer-events:none;
    display:flex; align-items:center; gap:calc(3px * var(--m)); padding:calc(3px * var(--m)) calc(7px * var(--m)); border-radius:calc(4px * var(--m));
    background:rgba(8,8,22,.78); color:#fff; font-size:calc(.56rem * var(--m)); font-weight:700; letter-spacing:.3px; text-transform:uppercase;
}
.folder-pub-badge.priv{ color:var(--sub); }
.folder-opts-btn{
    position:absolute; top:calc(6px * var(--m)); right:calc(6px * var(--m)); z-index:5; width:calc(26px * var(--m)); height:calc(26px * var(--m)); border-radius:50%;
    background:rgba(8,8,22,.78); color:#fff; font-size:calc(.85rem * var(--m));
    display:flex; align-items:center; justify-content:center; opacity:.9; transition:background .2s;
}
.folder-opts-btn:hover{ background:var(--red); color:#000; }
@media (hover:hover){ .folder-opts-btn{ opacity:0; } .folder-card:hover .folder-opts-btn, .folder-opts-btn:focus-visible{ opacity:1; } }
.folder-info{ padding:0 calc(2px * var(--m)); }
.folder-name{ font-size:calc(.8rem * var(--m)); font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.folder-count{ font-size:calc(.66rem * var(--m)); color:var(--muted); margin-top:calc(2px * var(--m)); }

.new-folder-btn{ width:100%; text-align:left; }
.nfb-stack{
    aspect-ratio:1; width:100%; margin-bottom:calc(10px * var(--m)); border-radius:calc(8px * var(--m));
    border:1.5px dashed var(--line2); color:var(--muted);
    display:flex; align-items:center; justify-content:center; transition:border-color .22s, color .22s, background .22s;
}
.nfb-stack i{ font-size:calc(1.4rem * var(--m)); }
.new-folder-btn:hover .nfb-stack{ border-color:rgba(255,45,45,.5); color:var(--red); background:rgba(255,45,45,.05); }
.nfb-label{ font-size:calc(.8rem * var(--m)); font-weight:600; color:var(--muted); padding:0 calc(2px * var(--m)); transition:color .22s; }
.new-folder-btn:hover .nfb-label{ color:var(--red); }
@media (max-width:490px){ .folders-grid{ grid-template-columns:repeat(auto-fill, minmax(calc(120px * var(--m)),1fr)); gap:calc(18px * var(--m)) calc(10px * var(--m)); } .folder-name{ font-size:calc(.72rem * var(--m)); } .folder-count{ font-size:calc(.6rem * var(--m)); } }
@media (max-width:380px){ .folders-grid{ grid-template-columns:repeat(2,1fr); } }

.folder-detail{ display:none; }
.folder-detail-hdr{ display:flex; align-items:center; gap:calc(10px * var(--m)); padding:calc(12px * var(--m)) var(--pad); border-bottom:1px solid var(--line); }
.folder-back{ width:calc(30px * var(--m)); height:calc(30px * var(--m)); border-radius:var(--r-btn); background:rgba(var(--fg),.1); display:flex; align-items:center; justify-content:center; font-size:calc(1.05rem * var(--m)); flex-shrink:0; transition:background .2s; }
.folder-back:hover{ background:rgba(var(--fg),.2); }
.folder-detail-title{ flex:1; min-width:0; font-size:calc(.88rem * var(--m)); font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.folder-detail-count{ font-size:calc(.68rem * var(--m)); color:var(--muted); flex-shrink:0; }
.au-loading{ display:flex; align-items:center; justify-content:center; gap:calc(8px * var(--m)); padding:calc(32px * var(--m)); color:var(--muted); font-size:calc(.78rem * var(--m)); }
.au-loading i{ font-size:calc(1.1rem * var(--m)); animation:auSpin .8s linear infinite; }
@keyframes auSpin{ to{ transform:rotate(360deg); } }

/* ── Who saved ── */
.hp-toggle{ display:flex; align-items:center; gap:calc(clamp(10px, 3vw, 14px) * var(--m)); width:100%; text-align:left; padding:calc(clamp(11px, 3.2vw, 16px) * var(--m)) var(--pad); border-bottom:1px solid var(--line); transition:background .18s; }
.hp-toggle:hover{ background:rgba(var(--fg),.04); }
.hp-list{ display:none; flex-direction:column; border-bottom:1px solid var(--line); }
.hp-list.open{ display:flex; }
.hp-user{ display:flex; align-items:center; gap:calc(clamp(10px, 3vw, 14px) * var(--m)); padding:calc(12px * var(--m)) var(--pad); border-bottom:1px solid rgba(var(--fg),.06); transition:background .15s; }
.hp-user:hover{ background:rgba(var(--fg),.03); }
.hp-user:last-child{ border-bottom:none; }
.hp-user img{ width:calc(clamp(38px, 10.5vw, 46px) * var(--m)); height:calc(clamp(38px, 10.5vw, 46px) * var(--m)); border-radius:50%; object-fit:cover; border:2px solid var(--red); flex-shrink:0; }
.hp-user-name{ font-size:calc(.84rem * var(--m)); font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.hp-user-count{ font-size:calc(.7rem * var(--m)); color:var(--muted); margin-top:calc(2px * var(--m)); }
.hp-empty{ font-size:calc(.74rem * var(--m)); color:var(--muted); padding:calc(20px * var(--m)) var(--pad); }

/* ══════════════════════════════════════
   SHEETS / MODALS
══════════════════════════════════════ */
.md-overlay{
    position:fixed; inset:0; display:none; align-items:flex-end; justify-content:center;
    background:rgba(0,0,0,.7); backdrop-filter:blur(4px); -webkit-backdrop-filter:blur(4px);
}
.md-overlay.open{ display:flex; animation:auFade .18s ease both; }
#scAddFolderModal{ z-index:999999995; }
#scNameModal{ z-index:999999997; }
.md-card{
    position:relative; width:100%; max-width:calc(480px * var(--m)); max-height:80vh; display:flex; flex-direction:column; overflow:hidden;
    background:var(--panel); border:1px solid var(--line); border-bottom:none; border-radius:calc(10px * var(--m)) calc(10px * var(--m)) 0 0;
    animation:auSlideUp .28s cubic-bezier(.32,.72,0,1);
}
.md-card::before{ content:''; display:block; flex-shrink:0; width:calc(36px * var(--m)); height:calc(4px * var(--m)); background:rgba(var(--fg),.18); border-radius:4px; margin:calc(10px * var(--m)) auto calc(2px * var(--m)); }
@keyframes auSlideUp{ from{ transform:translateY(60px); opacity:0; } to{ transform:none; opacity:1; } }
.md-hdr{ display:flex; align-items:center; justify-content:space-between; padding:calc(10px * var(--m)) var(--pad); border-bottom:1px solid var(--line); flex-shrink:0; }
.md-title{ font-size:calc(.88rem * var(--m)); font-weight:700; display:flex; align-items:center; gap:calc(8px * var(--m)); }
.md-close{ width:calc(28px * var(--m)); height:calc(28px * var(--m)); border-radius:var(--r-btn); background:rgba(var(--fg),.08); color:var(--sub); font-size:calc(1.05rem * var(--m)); display:flex; align-items:center; justify-content:center; transition:background .2s, color .2s; }
.md-close:hover{ background:rgba(var(--fg),.18); color:var(--ink); }
.md-body{ padding:calc(14px * var(--m)) var(--pad) calc(env(safe-area-inset-bottom, 0px) + 16px * var(--m)); overflow-y:auto; }
.md-list{ overflow-y:auto; flex:1; padding:calc(2px * var(--m)) 0; }
.md-foot{ display:flex; align-items:center; gap:calc(10px * var(--m)); padding:calc(13px * var(--m)) var(--pad) calc(env(safe-area-inset-bottom, 0px) + 13px * var(--m)); border-top:1px solid var(--line); color:var(--red); font-size:calc(.8rem * var(--m)); font-weight:600; flex-shrink:0; transition:background .15s; width:100%; text-align:left; }
.md-foot:hover{ background:rgba(255,45,45,.06); }
.md-foot i{ font-size:calc(1.05rem * var(--m)); }
.md-msg{ padding:calc(28px * var(--m)) calc(20px * var(--m)); text-align:center; color:var(--muted); font-size:calc(.78rem * var(--m)); line-height:1.6; }
@media (min-width:600px){
    .md-overlay{ align-items:center; padding:calc(20px * var(--m)); }
    .md-card{ border-radius:calc(8px * var(--m)); border-bottom:1px solid var(--line); max-width:calc(400px * var(--m)); }
    .md-card::before{ display:none; }
}

.atf-item{ display:flex; align-items:center; gap:calc(13px * var(--m)); padding:calc(11px * var(--m)) var(--pad); cursor:pointer; transition:background .15s; }
.atf-item:hover{ background:rgba(var(--fg),.05); }
.atf-icon{ width:calc(40px * var(--m)); height:calc(40px * var(--m)); border-radius:calc(6px * var(--m)); background:var(--surface); border:1px solid var(--line); display:flex; align-items:center; justify-content:center; flex-shrink:0; overflow:hidden; }
.atf-icon img{ width:100%; height:100%; object-fit:cover; }
.atf-icon i{ font-size:calc(1rem * var(--m)); color:var(--red); }
.atf-info{ flex:1; min-width:0; }
.atf-name{ font-size:calc(.82rem * var(--m)); font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.atf-count{ font-size:calc(.66rem * var(--m)); color:var(--muted); margin-top:calc(2px * var(--m)); }
.atf-check{ width:calc(22px * var(--m)); height:calc(22px * var(--m)); border-radius:50%; border:1.5px solid var(--line2); display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:calc(.65rem * var(--m)); color:transparent; transition:all .2s; }
.atf-check.on{ background:var(--red); border-color:transparent; color:#000; font-weight:700; }

/* name modal */
.nm-label{ display:block; font-size:calc(.6rem * var(--m)); font-weight:700; text-transform:uppercase; letter-spacing:1.5px; color:var(--muted); margin-bottom:calc(6px * var(--m)); }
.nm-cover-row{ display:flex; align-items:center; gap:calc(10px * var(--m)); margin:calc(12px * var(--m)) 0; }
.nm-cover-preview{ width:var(--ctl-h); height:var(--ctl-h); min-width:calc(36px * var(--m)); min-height:calc(36px * var(--m)); border-radius:var(--r); background:var(--surface); border:1px solid var(--line); display:flex; align-items:center; justify-content:center; overflow:hidden; flex-shrink:0; color:var(--muted); }
.nm-cover-preview img{ width:100%; height:100%; object-fit:cover; }
.nm-cover-label{ flex:1; min-width:0; height:calc(36px * var(--m)); padding:0 calc(12px * var(--m)); border-radius:var(--r); border:1px dashed var(--line2); color:var(--muted); font-size:calc(.72rem * var(--m)); display:flex; align-items:center; justify-content:center; cursor:pointer; transition:border-color .2s, color .2s; }
.nm-cover-label:hover{ border-color:rgba(255,45,45,.45); color:var(--ink); }
.nm-cover-label span{ white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.nm-cover-label input{ display:none; }
.nm-vis{ display:flex; align-items:center; justify-content:space-between; margin-bottom:calc(14px * var(--m)); padding:calc(10px * var(--m)) calc(12px * var(--m)); background:var(--surface); border:1px solid var(--line); border-radius:var(--r); }
.nm-vis-label{ font-size:calc(.74rem * var(--m)); font-weight:500; color:var(--sub); display:flex; align-items:center; gap:calc(7px * var(--m)); }
.nm-vis-label i{ color:var(--red); font-size:calc(.95rem * var(--m)); }
.sw{ position:relative; width:calc(40px * var(--m)); height:calc(22px * var(--m)); flex-shrink:0; }
.sw input{ opacity:0; width:0; height:0; position:absolute; }
.sw-slider{ position:absolute; inset:0; border-radius:calc(22px * var(--m)); background:rgba(var(--fg),.12); cursor:pointer; transition:background .22s; }
.sw-slider::before{ content:''; position:absolute; width:calc(16px * var(--m)); height:calc(16px * var(--m)); border-radius:50%; background:#fff; top:calc(3px * var(--m)); left:calc(3px * var(--m)); transition:transform .22s; }
.sw input:checked + .sw-slider{ background:var(--red); }
.sw input:checked + .sw-slider::before{ transform:translateX(calc(18px * var(--m))); }
.sw input:focus-visible + .sw-slider{ outline:2px solid rgba(255,45,45,.75); outline-offset:2px; }
.nm-btns{ display:flex; gap:calc(10px * var(--m)); }
.nm-btns .au-btn{ flex:1; }

/* collection options rows */
.opt-list{ overflow-y:auto; padding-bottom:env(safe-area-inset-bottom, 0px); }
.opt-row{ display:flex; align-items:center; gap:calc(clamp(10px, 3vw, 14px) * var(--m)); width:100%; text-align:left; padding:calc(clamp(11px, 3.2vw, 14px) * var(--m)) var(--pad); border-bottom:1px solid var(--line); transition:background .18s; }
.opt-row:last-child{ border-bottom:none; }
.opt-row:hover{ background:rgba(var(--fg),.04); }
.opt-row:hover .info-icon{ background:var(--red-soft); }
.opt-row .info-value{ margin-top:0; }
.opt-row .info-label{ text-transform:none; letter-spacing:0; margin-top:calc(2px * var(--m)); }
.opt-row.danger .info-value{ color:var(--red-text); }
.md-title-text{ max-width:calc(220px * var(--m)); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
#scOptsDd{ z-index:999999996; }

/* intro note */
.au-intro{ padding:calc(clamp(12px, 3.4vw, 16px) * var(--m)) var(--pad); border-bottom:1px solid var(--line); font-size:calc(clamp(.62rem, 1.9vw, .7rem) * var(--m)); color:var(--muted); line-height:1.65; }
.au-intro b{ display:block; color:var(--ink); font-weight:600; font-size:calc(clamp(.7rem, 2.2vw, .78rem) * var(--m)); margin-bottom:calc(2px * var(--m)); }
.au-intro a{ color:var(--red); font-weight:600; margin-left:calc(2px * var(--m)); }
.au-intro a:hover{ text-decoration:underline; }

/* toast */
.sc-toast{
    position:fixed; bottom:calc(96px * var(--m)); left:50%; transform:translateX(-50%) translateY(12px);
    background:rgba(8,8,22,.97); border:1px solid var(--line2); color:#fff;
    font-size:calc(.74rem * var(--m)); font-weight:500; padding:calc(9px * var(--m)) calc(18px * var(--m)); border-radius:var(--r);
    opacity:0; pointer-events:none; white-space:nowrap; max-width:92vw; overflow:hidden; text-overflow:ellipsis;
    transition:opacity .22s ease, transform .22s ease; z-index:9999999999; box-shadow:0 8px 24px rgba(0,0,0,.6);
}
.sc-toast.show{ opacity:1; transform:translateX(-50%) translateY(0); }

/* ══════════════════════════════════════
   PAGE SKELETON
══════════════════════════════════════ */
#page-skeleton{
    position:fixed; inset:0; z-index:9999; background:var(--bg); background-attachment:fixed;
    display:flex; justify-content:center; opacity:1; transition:opacity .35s ease; pointer-events:none;
}
#page-skeleton.hide{ opacity:0; }
.sk-lane{ width:100%; max-width:var(--max-w); display:flex; flex-direction:column; }
@media (min-width:801px){ .sk-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); } }
@media (max-width:800px){ .sk-lane{ max-width:100%; } }
.sk{ position:relative; overflow:hidden; background:rgba(var(--fg),.06); border-radius:calc(6px * var(--m)); }
.sk::after{ content:''; position:absolute; inset:0; background:linear-gradient(90deg, transparent, rgba(var(--fg),.09), transparent); transform:translateX(-100%); animation:skShimmer 1.5s infinite; }
@keyframes skShimmer{ 100%{ transform:translateX(100%); } }
.sk-topbar{ height:var(--topbar-h); display:flex; align-items:center; justify-content:space-between; padding:0 calc(16px * var(--m)) 0 calc(14px * var(--m)); border-bottom:1px solid var(--line2); }
.sk-x{ width:calc(20px * var(--m)); height:calc(20px * var(--m)); }
.sk-ttl{ width:calc(130px * var(--m)); height:calc(14px * var(--m)); }
.sk-tabs{ display:flex; border-bottom:1px solid var(--line); }
.sk-tab{ flex:1; height:calc(46px * var(--m)); border-radius:0; }
.sk-tab + .sk-tab{ margin-left:2px; }
.sk-row{ display:flex; align-items:center; gap:calc(14px * var(--m)); padding:calc(12px * var(--m)) var(--pad); border-bottom:1px solid rgba(var(--fg),.06); }
.sk-thumb{ width:calc(clamp(38px,10.5vw,46px) * var(--m)); height:calc(clamp(38px,10.5vw,46px) * var(--m)); flex-shrink:0; }
.sk-info{ flex:1; display:flex; flex-direction:column; gap:calc(7px * var(--m)); }
.sk-name{ width:55%; height:calc(12px * var(--m)); }
.sk-sub{ width:32%; height:calc(9px * var(--m)); }
.sk-act{ width:calc(72px * var(--m)); height:var(--act-h); border-radius:var(--r-btn); flex-shrink:0; }

/* ══════════════════════════════════════
   LISTEN MODAL — full-screen player
══════════════════════════════════════ */
#scListenModal{ display:none; position:fixed; inset:0; z-index:999999998; background:var(--bg); background-attachment:fixed; overflow:hidden; }
#scListenModal.open{ display:block; }
.slm-bg{
    position:absolute; inset:-40px; z-index:0; background-size:cover; background-position:center;
    filter:blur(60px) saturate(1.3); opacity:0; transform:scale(1.1); transition:opacity .6s ease; pointer-events:none;
}
.slm-bg.on{ opacity:.35; }
.slm-bg-fade{ position:absolute; inset:0; z-index:0; pointer-events:none; background:linear-gradient(to bottom, rgba(1,2,24,.35), rgba(7,9,36,.92) 70%); }
.slm-page{ position:relative; z-index:1; width:100%; height:100%; height:100dvh; display:flex; justify-content:center; overflow-y:auto; -webkit-overflow-scrolling:touch; }
.slm-lane{ transition:transform .3s cubic-bezier(.22,1,.36,1);
    width:100%; max-width:var(--max-w); min-height:100%; display:flex; flex-direction:column;
    padding-bottom:calc(env(safe-area-inset-bottom, 0px) + 24px * var(--m));
}
@media (min-width:801px){ .slm-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); } }
#scListenModal.open.animate-in .slm-lane{ animation:slmUp .38s cubic-bezier(.22,1,.36,1) both; }
@keyframes slmUp{ from{ transform:translateY(100%); } to{ transform:none; } }
#scListenModal.dragging .slm-lane{ transition:none; }

.slm-topbar{ position:relative; height:var(--topbar-h); display:flex; align-items:center; gap:calc(2px * var(--m)); padding:0 calc(10px * var(--m)); flex-shrink:0; touch-action:none; cursor:grab; }
.slm-topbar::after{ content:''; position:absolute; left:0; right:0; bottom:0; height:1px; background:var(--line2); }
.slm-min{ width:calc(34px * var(--m)); height:calc(34px * var(--m)); color:var(--ink); font-size:calc(1.6rem * var(--m)); display:flex; align-items:center; justify-content:center; opacity:.9; transition:opacity .2s, color .2s; flex-shrink:0; }
.slm-min:hover{ opacity:1; color:var(--red); }
.slm-top-label{ flex:1; min-width:0; text-align:right; margin:0 calc(6px * var(--m)) 0 calc(10px * var(--m)); line-height:1.25; }
.slm-top-label small{ display:block; font-size:calc(.58rem * var(--m)); color:var(--muted); font-weight:500; text-transform:uppercase; letter-spacing:.08em; }
.slm-top-label span{ display:block; font-size:calc(.84rem * var(--m)); font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

.slm-stage{ flex:1; display:flex; flex-direction:column; justify-content:center; padding:calc(20px * var(--m)) var(--pad) calc(8px * var(--m)); }
.slm-art-wrap{ display:flex; justify-content:center; padding:calc(6px * var(--m)) 0 calc(26px * var(--m)); }
.slm-art{
    width:calc(min(76vw, 320px) * var(--m)); max-width:100%; aspect-ratio:1; border-radius:calc(10px * var(--m)); overflow:hidden;
    background:var(--surface); border:2px solid var(--red);
    display:flex; align-items:center; justify-content:center;
    box-shadow:0 22px 60px rgba(0,0,0,.55), 0 0 0 1px rgba(var(--fg),.03);
    transition:transform .45s cubic-bezier(.22,1,.36,1), box-shadow .45s ease;
}
.slm-art img{ width:100%; height:100%; object-fit:cover; display:block; }
.slm-art i{ font-size:calc(3.4rem * var(--m)); color:var(--red); }
#scListenModal.is-playing .slm-art{ box-shadow:0 26px 70px rgba(0,0,0,.6), 0 0 60px rgba(255,45,45,.22); }
#scListenModal:not(.is-playing) .slm-art{ transform:scale(.93); }

.slm-meta-row{ display:flex; align-items:center; gap:calc(14px * var(--m)); }
.slm-meta{ flex:1; min-width:0; }
.slm-title{ font-size:calc(1.18rem * var(--m)); font-weight:700; line-height:1.3; word-break:break-word; }
.slm-artist{ display:block; font-size:calc(.8rem * var(--m)); color:var(--sub); margin-top:calc(3px * var(--m)); font-weight:500; transition:color .2s; }
.slm-save{
    width:calc(34px * var(--m)); height:calc(34px * var(--m)); border-radius:50%; flex-shrink:0; border:1.5px solid var(--line2); color:var(--sub);
    display:flex; align-items:center; justify-content:center; font-size:calc(1.05rem * var(--m)); transition:all .2s;
}
.slm-save:hover{ border-color:rgba(255,45,45,.6); color:var(--ink); }
.slm-save.saved{ background:var(--red); border-color:var(--red); color:#000; }
.slm-save[hidden]{ display:none; }

.slm-progress{ padding:calc(24px * var(--m)) 0 calc(4px * var(--m)); }
.slm-bar{ position:relative; height:calc(20px * var(--m)); display:flex; align-items:center; cursor:pointer; touch-action:none; }
.slm-bar-track{ position:relative; width:100%; height:calc(4px * var(--m)); border-radius:4px; background:rgba(var(--fg),.16); transition:height .15s; }
.slm-bar:hover .slm-bar-track, .slm-bar.seeking .slm-bar-track{ height:calc(6px * var(--m)); }
.slm-fill{ height:100%; width:0; border-radius:4px; background:var(--red); box-shadow:0 0 8px rgba(255,45,45,.55); }
.slm-knob{ position:absolute; top:50%; left:0; width:calc(13px * var(--m)); height:calc(13px * var(--m)); border-radius:50%; background:#fff; border:2px solid var(--red); transform:translate(-50%,-50%) scale(0); transition:transform .15s; pointer-events:none; box-shadow:0 2px 6px rgba(0,0,0,.45); }
.slm-bar:hover .slm-knob, .slm-bar.seeking .slm-knob{ transform:translate(-50%,-50%) scale(1); }
.slm-times{ display:flex; justify-content:space-between; font-size:calc(.66rem * var(--m)); color:var(--muted); font-weight:500; font-variant-numeric:tabular-nums; }

.slm-controls{ display:flex; align-items:center; justify-content:center; gap:calc(clamp(14px, 5vw, 26px) * var(--m)); padding:calc(14px * var(--m)) 0 calc(6px * var(--m)); }
.slm-ctrl{ width:calc(46px * var(--m)); height:calc(46px * var(--m)); border-radius:50%; font-size:calc(1.7rem * var(--m)); color:var(--ink); display:flex; align-items:center; justify-content:center; transition:color .2s, transform .15s, background .2s; }
.slm-ctrl:hover{ color:var(--red); }
.slm-ctrl:active{ transform:scale(.9); }
.slm-ctrl[disabled]{ opacity:.3; pointer-events:none; }
.slm-mode{ width:calc(38px * var(--m)); height:calc(38px * var(--m)); border-radius:var(--r-btn); font-size:calc(1.15rem * var(--m)); color:var(--muted); position:relative; display:flex; align-items:center; justify-content:center; transition:color .2s, background .2s; }
.slm-mode:hover{ color:var(--ink); background:rgba(var(--fg),.08); }
.slm-mode.on{ color:var(--red); }
.slm-mode.on::after{ content:''; position:absolute; bottom:calc(3px * var(--m)); left:50%; width:calc(4px * var(--m)); height:calc(4px * var(--m)); margin-left:calc(-2px * var(--m)); border-radius:50%; background:var(--red); }
.slm-play{ width:calc(60px * var(--m)); height:calc(60px * var(--m)); border-radius:50%; background:var(--red); color:#000; font-size:calc(1.6rem * var(--m)); display:flex; align-items:center; justify-content:center; flex-shrink:0; transition:transform .15s, background .2s; box-shadow:0 8px 24px rgba(255,45,45,.35); }
.slm-play:hover{ background:var(--red-hover); }
.slm-play:active{ transform:scale(.92); }
.slm-hint{ text-align:center; font-size:calc(.68rem * var(--m)); color:var(--muted); padding:calc(6px * var(--m)) 0 0; }

/* eq bars beside "Playing from" */
.slm-eq{ display:inline-flex; align-items:flex-end; gap:calc(2px * var(--m)); height:calc(10px * var(--m)); margin-right:calc(6px * var(--m)); vertical-align:middle; }
.slm-eq b{ width:calc(2.5px * var(--m)); height:100%; border-radius:2px; background:var(--red); transform-origin:bottom; transform:scaleY(.3); }
#scListenModal.is-playing .slm-eq b{ animation:slmEq .9s ease-in-out infinite; }
#scListenModal.is-playing .slm-eq b:nth-child(2){ animation-delay:-.3s; }
#scListenModal.is-playing .slm-eq b:nth-child(3){ animation-delay:-.6s; }
@keyframes slmEq{ 0%,100%{ transform:scaleY(.3); } 25%{ transform:scaleY(1); } 55%{ transform:scaleY(.5); } 80%{ transform:scaleY(.85); } }

/* player skeleton */
.slm-skeleton{ display:none; flex-direction:column; padding:calc(20px * var(--m)) var(--pad); flex:1; }
.slm-skeleton.show{ display:flex; }
.slm-lane .slm-hidden{ display:none; }
.slm-sk-art{ width:calc(min(76vw,320px) * var(--m)); max-width:100%; aspect-ratio:1; margin:calc(6px * var(--m)) auto calc(26px * var(--m)); border-radius:calc(10px * var(--m)); }
.slm-sk-t{ height:calc(20px * var(--m)); width:65%; margin-bottom:calc(10px * var(--m)); }
.slm-sk-a{ height:calc(13px * var(--m)); width:38%; }
.slm-sk-b{ height:calc(4px * var(--m)); margin-top:calc(30px * var(--m)); }
.slm-sk-c{ display:flex; justify-content:center; align-items:center; gap:calc(26px * var(--m)); padding-top:calc(28px * var(--m)); }
.slm-sk-c .sk{ width:calc(46px * var(--m)); height:calc(46px * var(--m)); border-radius:50%; }
.slm-sk-c .sk:nth-child(2){ width:calc(60px * var(--m)); height:calc(60px * var(--m)); }

/* mini player */
#scMiniPlayer{
    position:fixed; left:0; right:0; bottom:0; z-index:999999997; display:flex; justify-content:center;
    padding:0 calc(10px * var(--m)) calc(env(safe-area-inset-bottom, 0px) + 10px * var(--m));
    transform:translateY(130%); transition:transform .32s cubic-bezier(.22,1,.36,1); pointer-events:none;
}
#scMiniPlayer.show{ transform:none; pointer-events:auto; }
.smp-bar{ width:100%; max-width:calc(var(--max-w) - 20px * var(--m)); overflow:hidden; background:var(--panel); border:1px solid var(--line2); border-radius:calc(8px * var(--m)); box-shadow:0 12px 40px rgba(0,0,0,.55); touch-action:pan-x; }
.smp-progress{ height:calc(2.5px * var(--m)); background:rgba(var(--fg),.1); }
.smp-fill{ height:100%; width:0; background:var(--red); }
.smp-body{ display:flex; align-items:center; gap:calc(11px * var(--m)); padding:calc(8px * var(--m)) calc(8px * var(--m)) calc(8px * var(--m)) calc(10px * var(--m)); cursor:pointer; }
.smp-thumb{ width:calc(40px * var(--m)); height:calc(40px * var(--m)); border-radius:calc(6px * var(--m)); flex-shrink:0; overflow:hidden; background:var(--surface); border:2px solid var(--red); display:flex; align-items:center; justify-content:center; }
.smp-thumb img{ width:100%; height:100%; object-fit:cover; }
.smp-thumb i{ color:var(--red); font-size:calc(1.1rem * var(--m)); }
.smp-meta{ flex:1; min-width:0; }
.smp-title{ font-size:calc(.8rem * var(--m)); font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.smp-artist{ font-size:calc(.66rem * var(--m)); color:var(--muted); margin-top:calc(1px * var(--m)); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.smp-btn{ width:calc(34px * var(--m)); height:calc(34px * var(--m)); border-radius:50%; font-size:calc(1.2rem * var(--m)); color:var(--ink); display:flex; align-items:center; justify-content:center; flex-shrink:0; transition:background .2s, color .2s; }
.smp-btn:hover{ background:rgba(var(--fg),.1); }
.smp-btn.close{ font-size:calc(1rem * var(--m)); color:var(--muted); }
@media (max-width:490px){ .slm-title{ font-size:calc(1.05rem * var(--m)); } .slm-art{ width:calc(min(78vw, 270px) * var(--m)); } .slm-play{ width:calc(56px * var(--m)); height:calc(56px * var(--m)); } .slm-ctrl{ width:calc(42px * var(--m)); height:calc(42px * var(--m)); font-size:calc(1.55rem * var(--m)); } }
@media (max-width:380px){ .slm-art{ width:calc(min(78vw, 230px) * var(--m)); } .slm-title{ font-size:calc(1rem * var(--m)); } .slm-controls{ gap:calc(10px * var(--m)); } }

/* ══════════ LIGHT MODE ( <html data-theme="light"> ) — same palette as the other pages ══════════ */
:root[data-theme="light"]{
    color-scheme:light;
    --fg:6,7,26; --ink:#0b0d24;
    --solid:#f4f5fb; --tile:#e6e8f1; --cover-a:#d9dcea; --cover-b:#e9ebf5;
    --bg:linear-gradient(-45deg,#ffffff,#f4f5fb,#f4f5fb);
    --panel:#ffffff; --red-text:#d42525; --red-hover:#e01e1e;
    --surface:rgba(6,7,26,.045);
}
:root[data-theme="light"] .au-btn.primary, :root[data-theme="light"] .slm-save.on{ color:#fff; }
/* the full-screen listen player follows the page theme (dark by default, light when <html data-theme="light">) */
#scListenModal{
    --text:var(--ink); --muted:rgba(var(--fg),.5); --sub:rgba(var(--fg),.7);
    --line:rgba(var(--fg),.09); --line2:rgba(var(--fg),.14); color:var(--ink);
}
:root:not([data-theme="light"]) #scListenModal{ --solid:#070924; --bg:linear-gradient(-45deg,#010218,#070924,#070924); --red-text:#ff8a8a; --surface:rgba(255,255,255,.05); }
:root[data-theme="light"] #scListenModal{ --bg:linear-gradient(-45deg,#ffffff,#f4f5fb,#f4f5fb); }
:root[data-theme="light"] .slm-bg-fade{ background:linear-gradient(to bottom, rgba(255,255,255,.35), rgba(244,245,251,.94) 70%); }
:root[data-theme="light"] .slm-bg.on{ opacity:.22; }

/* ══ "Now playing" look — exact port of the reference player (midnight-blue palette kept) ══ */
#scListenModal{ --tile-blue:rgba(120,170,255,.30); --wave-off:rgba(var(--fg),.30); }
:root[data-theme="light"] #scListenModal{ --tile-blue:#cfe3fa; --wave-off:rgba(6,7,26,.22); }
/* top bar: back chevron · NOW PLAYING · spacer */
.slm-topbar{ justify-content:space-between; }
.slm-topbar::after{ display:none; }
.slm-top-label{ flex:1; text-align:center; margin:0; }
.slm-top-label small{ font-size:calc(.6rem * var(--m)); font-weight:600; letter-spacing:.34em; margin:0; color:var(--sub); }
.slm-top-label span#slmContext{ display:none; }
.slm-topbar::before{ content:''; order:3; width:calc(34px * var(--m)); flex-shrink:0; }
.slm-min{ color:var(--ink); width:38px; height:38px; margin-left:-4px; padding-left:2px; }
.slm-min .ms-back-svg{ height:23px; width:auto; aspect-ratio:14 / 24; display:block; overflow:visible; }
.slm-topbar::before{ width:34px; }
@media (max-width:490px){ .slm-min{ width:36px; height:36px; } }
/* cover: round, thin ring */
.slm-art-wrap{ padding:calc(4px * var(--m)) 0 0; position:relative; z-index:0; }
.slm-meta, .slm-artist{ position:relative; z-index:3; }
.slm-art{ width:calc(min(70vw, 290px) * var(--m)); border-radius:50%; border:calc(1.5px * var(--m)) solid rgba(var(--fg),.55); box-shadow:none; background:var(--surface); }
#scListenModal.is-playing .slm-art{ box-shadow:none; }
.slm-art img{ border-radius:50%; }
/* title: huge heavy condensed, overlapping the bottom of the cover */
.slm-meta-row{ position:relative; z-index:2; justify-content:center; margin-top:calc(-34px * var(--m)); }
.slm-meta{ text-align:center; padding:0; width:100%; }
.slm-title{ position:relative; z-index:3; font-family:'Anton','Impact','Haettenschweiler','Arial Narrow Bold','Arial Black',sans-serif; font-weight:400; font-size:calc(2.9rem * var(--m)); line-height:.98; text-transform:uppercase; letter-spacing:.01em; color:var(--ink); text-shadow:0 0 calc(14px * var(--m)) var(--solid); transform:scaleX(1.06); white-space:nowrap; }
.slm-title.wrap{ white-space:normal; }
.slm-artist{ font-size:calc(.72rem * var(--m)); font-weight:500; text-transform:uppercase; letter-spacing:.42em; margin-top:calc(8px * var(--m)); color:var(--sub); padding-left:.42em; }
.slm-save{ position:absolute; right:0; top:calc(100% + 0px); transform:none; display:none !important; }
/* wavy seek bar: thin wave = whole track, thick wave = played part, round knob on the line */
.slm-progress{ padding:calc(16px * var(--m)) 0 calc(2px * var(--m)); }
.slm-bar{ height:calc(26px * var(--m)); }
.slm-bar-track{ height:calc(20px * var(--m)); background:none; border:0; border-radius:0; box-sizing:border-box; overflow:visible; }
.slm-bar:hover .slm-bar-track, .slm-bar.seeking .slm-bar-track{ height:calc(20px * var(--m)); }
.slm-bar-track::before, .slm-fill{
    content:''; position:absolute; left:0; top:0; height:100%;
    -webkit-mask-repeat:repeat-x; mask-repeat:repeat-x; -webkit-mask-position:left center; mask-position:left center;
    -webkit-mask-size:calc(64px * var(--m)) 100%; mask-size:calc(64px * var(--m)) 100%;
}
.slm-bar-track::before{ width:100%; background:var(--wave-off);
    -webkit-mask-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 20' preserveAspectRatio='none'%3E%3Cpath d='M0 10 Q16 3 32 10 T64 10' fill='none' stroke='%23000' stroke-width='2.2' stroke-linecap='round'/%3E%3C/svg%3E");
            mask-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 20' preserveAspectRatio='none'%3E%3Cpath d='M0 10 Q16 3 32 10 T64 10' fill='none' stroke='%23000' stroke-width='2.2' stroke-linecap='round'/%3E%3C/svg%3E"); }
.slm-fill{ width:0; background:var(--red); box-shadow:none; border-radius:0;
    -webkit-mask-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 20' preserveAspectRatio='none'%3E%3Cpath d='M0 10 Q16 3 32 10 T64 10' fill='none' stroke='%23000' stroke-width='5' stroke-linecap='round'/%3E%3C/svg%3E");
            mask-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 20' preserveAspectRatio='none'%3E%3Cpath d='M0 10 Q16 3 32 10 T64 10' fill='none' stroke='%23000' stroke-width='5' stroke-linecap='round'/%3E%3C/svg%3E"); }
.slm-knob{ width:calc(17px * var(--m)); height:calc(17px * var(--m)); border:0; background:var(--red); box-shadow:0 0 0 calc(2px * var(--m)) var(--solid); transform:translate(-50%,-50%) scale(1); }
.slm-bar:hover .slm-knob, .slm-bar.seeking .slm-knob{ transform:translate(-50%,-50%) scale(1.15); }
.slm-times{ font-size:calc(.62rem * var(--m)); font-weight:500; color:var(--sub); margin-top:calc(-2px * var(--m)); }
/* controls: red tiles · blue tiles · wide pill play */
.slm-controls{ gap:calc(8px * var(--m)); padding:calc(18px * var(--m)) 0 calc(14px * var(--m)); }
.slm-controls{ width:100%; }
.slm-ctrl, .slm-mode{ width:calc(46px * var(--m)); height:calc(46px * var(--m)); border:0; border-radius:calc(14px * var(--m)); box-sizing:border-box; flex:0 0 auto; }
.slm-ctrl{ background:var(--tile-blue); color:var(--ink); font-size:calc(1.55rem * var(--m)); }
.slm-ctrl:hover{ background:var(--tile-blue); color:var(--ink); filter:brightness(1.08); }
.slm-mode{ background:var(--tile-blue); color:var(--ink); font-size:calc(1.3rem * var(--m)); opacity:.6; }
.slm-mode:hover{ background:var(--tile-blue); color:var(--ink); opacity:.85; }
.slm-mode.on{ background:var(--tile-blue); color:var(--red); opacity:1; }
.slm-mode.on::after{ display:none; }
.slm-play{ flex:1 1 auto; min-width:0; max-width:calc(130px * var(--m)); height:calc(58px * var(--m)); border-radius:calc(29px * var(--m)); background:var(--ink); color:var(--solid); border:0; box-shadow:none; font-size:calc(2.1rem * var(--m)); }
.slm-play:hover{ background:var(--ink); filter:brightness(.94); }
.slm-play:active{ transform:scale(.96); box-shadow:none; }
/* Up next row */
.slm-next{ display:none; align-items:center; gap:calc(12px * var(--m)); margin-top:calc(6px * var(--m)); padding:calc(14px * var(--m)) calc(2px * var(--m)) 0; border-top:1px solid var(--line2); cursor:pointer; }
.slm-next.show{ display:flex; }
.slm-next-lbl{ font-size:calc(.74rem * var(--m)); font-weight:800; flex-shrink:0; }
.slm-next-thumb{ width:calc(44px * var(--m)); height:calc(44px * var(--m)); border-radius:calc(5px * var(--m)); overflow:hidden; background:var(--surface); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.slm-next-thumb img{ width:100%; height:100%; object-fit:cover; display:block; }
.slm-next-thumb i{ color:var(--red); font-size:calc(1.1rem * var(--m)); }
.slm-next-meta{ flex:1; min-width:0; }
.slm-next-t{ font-size:calc(.82rem * var(--m)); font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.slm-next-a{ font-size:calc(.68rem * var(--m)); color:var(--muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-top:calc(1px * var(--m)); }
.slm-next > i{ font-size:calc(1.3rem * var(--m)); color:var(--sub); flex-shrink:0; }
.slm-hint{ display:none; }
@media (max-width:490px){ .slm-title{ font-size:calc(2.55rem * var(--m)); } .slm-art{ width:calc(min(68vw, 260px) * var(--m)); } }
@media (max-width:380px){ .slm-title{ font-size:calc(2.15rem * var(--m)); } .slm-ctrl,.slm-mode{ width:calc(40px * var(--m)); height:calc(40px * var(--m)); border-radius:calc(12px * var(--m)); } .slm-play{ height:calc(52px * var(--m)); } .slm-controls{ gap:calc(6px * var(--m)); } }
</style>
<link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Anton&display=swap" rel="stylesheet">
</head>
<body>

<!-- ══ PAGE SKELETON ══ -->
<div id="page-skeleton">
    <div class="sk-lane">
        <div class="sk-topbar"><div class="sk sk-x"></div><div class="sk sk-ttl"></div></div>
        <div class="sk-tabs"><div class="sk sk-tab"></div><div class="sk sk-tab"></div><div class="sk sk-tab"></div></div>
        <?php for($i=0;$i<7;$i++): ?>
        <div class="sk-row">
            <div class="sk sk-thumb"></div>
            <div class="sk-info"><div class="sk sk-name"></div><div class="sk sk-sub"></div></div>
            <div class="sk sk-act"></div>
        </div>
        <?php endfor; ?>
    </div>
</div>

<div class="au-lane">

    <!-- ══ TOPBAR ══ -->
    <div class="au-topbar">
        <button type="button" class="au-x" onclick="auClose()" aria-label="Close">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
        <span class="au-title"><?php echo $is_owner ? 'Your Audio Library' : htmlspecialchars($user['username']) . '’s Audio Library'; ?></span>
    </div>

    <!-- ══ WHAT THIS PAGE IS ══ -->
    <div class="au-intro">
        <?php if($is_owner): ?>
        <b>Your audio library</b>
        Everything you upload, save and collect lives here. Play any sound, organize favorites into collections, and choose which collections are shown to people who visit your sound page.
        <?php else: ?>
        <b><?php echo htmlspecialchars($user['username']); ?>’s audio library</b>
        Sounds <?php echo htmlspecialchars($user['username']); ?> has uploaded and saved, plus their public collections. Tap any sound to listen.
        <?php endif; ?>
        <a href="help.php">Learn more</a>
    </div>

    <!-- ══ CONDUCT NOTICE ══ -->
    <div class="au-intro au-notice">
        <?php if($is_owner): ?>
        <b>Keep your library clean</b>
        Don’t upload or save pirated, copyrighted, illegal or harmful sounds. Accounts that post this kind of content will be reported.
        <?php else: ?>
        <b>See something wrong?</b>
        If this sound page has pirated or illegal content, or anything harmful, report <?php echo htmlspecialchars($user['username']); ?> from their profile page.
        <a href="profile.php?user_id=<?php echo (int)$profile_id; ?>">Go to profile</a>
        <?php endif; ?>
    </div>

    <!-- ══ UPLOAD (owner only) ══ -->
    <?php if($is_owner): ?>
    <button type="button" class="up-toggle" id="uploadToggle" onclick="scToggleUpload()" aria-expanded="false" aria-controls="uploadSection">
        <div class="info-icon"><i class="ri-upload-cloud-2-line"></i></div>
        <div class="info-content">
            <div class="info-label">Add to your library</div>
            <div class="info-value">Upload a sound</div>
        </div>
        <i class="ri-arrow-right-s-line chevron"></i>
    </button>
    <div class="up-section" id="uploadSection">
        <form class="up-form" method="POST" enctype="multipart/form-data">
            <input type="text" class="up-field" name="title" placeholder="Sound title" maxlength="120" required autocomplete="off">
            <label class="up-file">
                <i class="ri-music-line"></i>
                <span id="audioFileText">Choose audio file (mp3, wav, ogg)</span>
                <input type="file" name="audio" accept=".mp3,.wav,.ogg,audio/*" required>
            </label>
            <label class="up-file">
                <i class="ri-image-add-line"></i>
                <span id="pictureFileText">Choose cover picture (optional)</span>
                <input type="file" name="picture" accept="image/*">
            </label>
            <button name="upload_sound" type="submit" class="au-btn primary"><i class="ri-upload-cloud-2-line"></i> Upload sound</button>
        </form>
    </div>
    <?php endif; ?>

    <!-- ══ WHO SAVED (owner only) ══ -->
    <?php if($is_owner): ?>
    <button type="button" class="hp-toggle" id="hpToggle" aria-expanded="false" onclick="scToggleSavers(this)">
        <div class="info-icon"><i class="ri-group-line"></i></div>
        <div class="info-content">
            <div class="info-label">Your audience</div>
            <div class="info-value">Who saved your sounds</div>
        </div>
        <i class="ri-arrow-right-s-line chevron"></i>
    </button>
    <div class="hp-list" id="hpList">
        <?php
        $added_by = $conn->query("
            SELECT u.id, u.username, u.profile_pic, COUNT(ss.sound_id) AS cnt
            FROM saved_sounds ss
            JOIN sounds s ON ss.sound_id=s.id
            JOIN users u ON ss.user_id=u.id
            WHERE s.user_id=$profile_id
            GROUP BY u.id, u.username, u.profile_pic ORDER BY cnt DESC
        ");
        if($added_by && $added_by->num_rows>0):
            while($ab=$added_by->fetch_assoc()):
        ?>
        <a class="hp-user" href="profile.php?user_id=<?=(int)$ab['id']?>">
            <img src="<?=htmlspecialchars($ab['profile_pic'] ?: 'uploads/me.png')?>" alt="" onerror="this.src='uploads/me.png'">
            <div class="info-content">
                <div class="hp-user-name"><?=htmlspecialchars($ab['username'])?></div>
                <div class="hp-user-count"><?=(int)$ab['cnt']?> sound<?=$ab['cnt']!=1?'s':''?> saved</div>
            </div>
            <i class="ri-arrow-right-s-line chevron"></i>
        </a>
        <?php endwhile; else: ?>
        <div class="hp-empty">No one has saved your sounds yet.</div>
        <?php endif; ?>
    </div>
    <?php endif; ?>


    <!-- ══ TABS ══ -->
    <div class="au-tabs">
        <div class="au-tabs-track" id="auTabsTrack">
            <button type="button" class="au-tab active" id="tabUploaded" onclick="scSwitchTab('uploaded')">
                <span class="au-tab-count"><?=number_format($sounds_count)?></span>
                <span class="au-tab-label"><?=$is_owner ? 'Your Sounds' : 'Sounds'?></span>
            </button>
            <button type="button" class="au-tab" id="tabAdded" onclick="scSwitchTab('added')">
                <span class="au-tab-count"><?=number_format($added_count)?></span>
                <span class="au-tab-label"><?=$is_owner ? 'Saved' : 'Saves'?></span>
            </button>
            <button type="button" class="au-tab" id="tabFolders" onclick="scSwitchTab('folders')">
                <span class="au-tab-count"><?=number_format($folders_count)?></span>
                <span class="au-tab-label">Collections</span>
            </button>
            <span class="au-tab-ind" id="auTabInd"></span>
        </div>
    </div>

    <!-- ══ SEARCH ══ -->
    <div class="au-search" id="searchRow">
        <i class="ri-search-line"></i>
        <input type="text" id="scSearch" placeholder="Search by title or user..." autocomplete="off">
    </div>

    <div class="au-pager idle" id="auPager"><div class="au-track" id="auTrack">

    <!-- ══ TAB: SOUNDS ══ -->
    <div class="au-panel active" id="panel-uploaded">
        <div class="sound-grid" id="grid-uploaded">
            <?php if(!$my_sounds || $my_sounds->num_rows===0): ?>
            <div class="empty-state"><i class="ri-music-2-line"></i><span>No sounds uploaded yet</span></div>
            <?php else: while($s=$my_sounds->fetch_assoc()){ $s['kind']='sound'; sc_render_row($s, $is_owner, 'uploaded'); } endif; ?>
        </div>
        <div class="empty-state au-noresult"><i class="ri-search-line"></i><span>No sounds found</span></div>
    </div>

    <!-- ══ TAB: SAVED ══ -->
    <div class="au-panel" id="panel-added">
        <div class="sound-grid" id="grid-added">
            <?php if(empty($added_sounds_rows)): ?>
            <div class="empty-state"><i class="ri-bookmark-line"></i><span>No saved sounds yet</span></div>
            <?php else: foreach($added_sounds_rows as $s){ sc_render_row($s, $is_owner, 'saved'); } endif; ?>
        </div>
        <div class="empty-state au-noresult"><i class="ri-search-line"></i><span>No sounds found</span></div>
    </div>

    <!-- ══ TAB: COLLECTIONS ══ -->
    <div class="au-panel" id="panel-folders">
        <div id="scFoldersList">
            <div class="folders-grid" id="scFoldersGrid">
                <?php if($is_owner): ?>
                <button type="button" class="new-folder-btn" onclick="scOpenNameModal('create')">
                    <div class="nfb-stack"><i class="ri-folder-add-line"></i></div>
                    <div class="nfb-label">New collection</div>
                </button>
                <?php endif; ?>
            </div>
        </div>
        <div class="folder-detail" id="scFolderDetail">
            <div class="folder-detail-hdr">
                <button type="button" class="folder-back" onclick="scCloseDetail()" aria-label="Back to collections"><i class="ri-arrow-left-line"></i></button>
                <div class="folder-detail-title" id="scDetailTitle">Collection</div>
                <div class="folder-detail-count" id="scDetailCount">0 tracks</div>
            </div>
            <div class="sound-grid" id="scDetailGrid"></div>
        </div>
    </div>

    </div></div><!-- /.au-track /.au-pager -->

</div><!-- /.au-lane -->

<!-- ══ ADD TO COLLECTION SHEET ══ -->
<div class="md-overlay" id="scAddFolderModal" onclick="if(event.target===this)scCloseAddToFolder()">
    <div class="md-card">
        <div class="md-hdr">
            <span class="md-title"><i class="ri-folder-music-line" style="color:var(--red)"></i> Add to collection</span>
            <button type="button" class="md-close" onclick="scCloseAddToFolder()" aria-label="Close"><i class="ri-close-line"></i></button>
        </div>
        <div class="md-list" id="atfList"></div>
        <button type="button" class="md-foot" onclick="scCloseAddToFolder();scOpenNameModal('create');"><i class="ri-add-circle-line"></i> New collection</button>
    </div>
</div>

<!-- ══ COLLECTION OPTIONS ══ -->
<div class="md-overlay" id="scOptsDd" onclick="if(event.target===this)scCloseOpts()">
    <div class="md-card">
        <div class="md-hdr">
            <span class="md-title"><i class="ri-folder-music-line" style="color:var(--red)"></i><span class="md-title-text" id="scOptsName">Collection</span></span>
            <button type="button" class="md-close" onclick="scCloseOpts()" aria-label="Close"><i class="ri-close-line"></i></button>
        </div>
        <div class="opt-list">
            <button type="button" class="opt-row" id="scOptsVisItem" onclick="scOptsDo('toggle_vis')">
                <div class="info-icon"><i class="ri-earth-line"></i></div>
                <div class="info-content"><div class="info-value" id="scOptsVisLabel">Make public</div><div class="info-label" id="scOptsVisSub">Shown on your sound page</div></div>
                <i class="ri-arrow-right-s-line chevron"></i>
            </button>
            <button type="button" class="opt-row" onclick="scOptsDo('cover')">
                <div class="info-icon"><i class="ri-image-edit-line"></i></div>
                <div class="info-content"><div class="info-value">Change cover</div><div class="info-label">Pick a new cover image</div></div>
                <i class="ri-arrow-right-s-line chevron"></i>
            </button>
            <button type="button" class="opt-row" onclick="scOptsDo('rename')">
                <div class="info-icon"><i class="ri-edit-line"></i></div>
                <div class="info-content"><div class="info-value">Rename</div><div class="info-label">Change the collection name</div></div>
                <i class="ri-arrow-right-s-line chevron"></i>
            </button>
            <button type="button" class="opt-row danger" onclick="scOptsDo('delete')">
                <div class="info-icon"><i class="ri-delete-bin-line"></i></div>
                <div class="info-content"><div class="info-value">Delete</div><div class="info-label">Sounds inside stay in your library</div></div>
            </button>
        </div>
    </div>
</div>

<!-- ══ NAME / COVER MODAL ══ -->
<div class="md-overlay" id="scNameModal" onclick="if(event.target===this)scCloseNameModal()">
    <div class="md-card">
        <div class="md-hdr">
            <span class="md-title" id="scNmTitle">New collection</span>
            <button type="button" class="md-close" onclick="scCloseNameModal()" aria-label="Close"><i class="ri-close-line"></i></button>
        </div>
        <div class="md-body">
            <div id="scNmNameBlock">
                <label class="nm-label" for="scNmInput">Name</label>
                <input type="text" class="up-field" id="scNmInput" placeholder="e.g. Chill vibes" maxlength="60" autocomplete="off" onkeydown="if(event.key==='Enter')scSaveName()">
            </div>
            <div class="nm-cover-row">
                <div class="nm-cover-preview" id="scNmCoverPreview"><i class="ri-image-line"></i></div>
                <label class="nm-cover-label">
                    <span id="scNmCoverLabelText">Add cover image (optional)</span>
                    <input type="file" id="scNmCoverInput" accept="image/*" onchange="scNmCoverChange(this)">
                </label>
            </div>
            <div class="nm-vis" id="scVisRow">
                <label class="nm-vis-label" for="scVisTog"><i class="ri-earth-line"></i> Make public</label>
                <label class="sw"><input type="checkbox" id="scVisTog"><span class="sw-slider"></span></label>
            </div>
            <div class="nm-btns">
                <button type="button" class="au-btn ghost" onclick="scCloseNameModal()">Cancel</button>
                <button type="button" class="au-btn primary" onclick="scSaveName()">Save</button>
            </div>
        </div>
    </div>
</div>

<!-- ══ PLAYER — full screen ══ -->
<div id="scListenModal" role="dialog" aria-modal="true" aria-label="Audio player">
    <div class="slm-bg" id="slmBg"></div>
    <div class="slm-bg-fade"></div>
    <div class="slm-page">
        <div class="slm-lane">
            <div class="slm-topbar" id="slmTopbar">
                <button type="button" class="slm-min" onclick="scMinimizePlayer()" aria-label="Minimize player"><svg class="ms-back-svg" viewBox="0 0 14 24" aria-hidden="true"><path d="M12.4 1.6 L2 12 L12.4 22.4" fill="none" stroke="currentColor" stroke-width="1.94" stroke-linejoin="miter" stroke-linecap="butt"/></svg></button>
                <div class="slm-top-label">
                    <small><span class="slm-eq" aria-hidden="true"><b></b><b></b><b></b></span>Now playing</small>
                    <span id="slmContext">Your Sounds</span>
                </div>
            </div>

            <div class="slm-skeleton" id="slmSkeleton">
                <div class="sk slm-sk-art"></div>
                <div class="sk slm-sk-t"></div>
                <div class="sk slm-sk-a"></div>
                <div class="sk slm-sk-b"></div>
                <div class="slm-sk-c"><div class="sk"></div><div class="sk"></div><div class="sk"></div></div>
            </div>

            <div class="slm-stage" id="slmContent">
                <div class="slm-art-wrap"><div class="slm-art" id="slmArt"><i class="ri-music-2-line"></i></div></div>
                <div class="slm-meta-row">
                    <div class="slm-meta">
                        <div class="slm-title" id="slmTitle">Title</div>
                        <div class="slm-artist" id="slmArtist">Artist</div>
                    </div>
                    <button type="button" class="slm-save" id="slmSaveBtn" onclick="scToggleSaveFromModal()" hidden aria-label="Save"><i class="ri-add-line"></i></button>
                </div>
                <div class="slm-progress">
                    <div class="slm-bar" id="slmBar" role="slider" aria-label="Seek" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" tabindex="0">
                        <div class="slm-bar-track"><div class="slm-fill" id="slmFill"></div><div class="slm-knob" id="slmKnob"></div></div>
                    </div>
                    <div class="slm-times"><span id="slmCur">0:00</span><span id="slmDur">0:00</span></div>
                </div>
                <div class="slm-controls">
                    <button type="button" class="slm-mode" id="slmShuffleBtn" onclick="scLmToggleShuffle()" aria-label="Shuffle" title="Shuffle"><i class="ri-shuffle-line"></i></button>
                    <button type="button" class="slm-ctrl" id="slmPrevBtn" onclick="scLmPrev()" aria-label="Previous"><i class="ri-skip-back-mini-fill"></i></button>
                    <button type="button" class="slm-play" id="slmPlayBtn" onclick="scLmTogglePlay()" aria-label="Play"><i class="ri-play-fill"></i></button>
                    <button type="button" class="slm-ctrl" id="slmNextBtn" onclick="scLmNext()" aria-label="Next"><i class="ri-skip-forward-mini-fill"></i></button>
                    <button type="button" class="slm-mode" id="slmRepeatBtn" onclick="scLmToggleRepeat()" aria-label="Repeat" title="Repeat"><i class="ri-repeat-2-line"></i></button>
                </div>
                <div class="slm-hint" id="slmQueueHint"></div>
                <div class="slm-next" id="slmNext" onclick="scLmNext()" role="button" aria-label="Play next">
                    <span class="slm-next-lbl">Up next:</span>
                    <div class="slm-next-thumb" id="slmNextThumb"><i class="ri-music-2-line"></i></div>
                    <div class="slm-next-meta"><div class="slm-next-t" id="slmNextTitle"></div><div class="slm-next-a" id="slmNextArtist"></div></div>
                    <i class="ri-arrow-right-s-line"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══ MINI PLAYER ══ -->
<div id="scMiniPlayer">
    <div class="smp-bar" id="smpBar">
        <div class="smp-progress"><div class="smp-fill" id="smpFill"></div></div>
        <div class="smp-body" onclick="scExpandPlayer()">
            <div class="smp-thumb" id="smpThumb"><i class="ri-music-2-line"></i></div>
            <div class="smp-meta"><div class="smp-title" id="smpTitle">Title</div><div class="smp-artist" id="smpArtist">Artist</div></div>
            <button type="button" class="smp-btn" id="smpPlayBtn" onclick="event.stopPropagation();scLmTogglePlay()" aria-label="Play"><i class="ri-play-fill"></i></button>
            <button type="button" class="smp-btn close" onclick="event.stopPropagation();scCloseListenModal()" aria-label="Close player"><i class="ri-close-line"></i></button>
        </div>
    </div>
</div>

<!-- ══ TOAST ══ -->
<div class="sc-toast" id="scToast" role="status" aria-live="polite"></div>
<!-- ══════════════════════════════════════════════════════════
     AUDIO DETAIL PANEL (Original Audio)
══════════════════════════════════════════════════════════ -->
<div id="audioDetailOverlay" class="adm-overlay" onclick="closeAudioDetailModal()"></div>
<div id="audioDetailPanel" class="adm-panel" onclick="if(event.target===this)closeAudioDetailModal()">
  <div class="adm-lane">

    <div class="adm-topbar">
        <button type="button" class="adm-ico adm-back" onclick="closeAudioDetailModal()" aria-label="Close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button>
        <button type="button" class="adm-trending" onclick="admOpenTrendModal()"><i class="ri-arrow-left-up-line"></i><span>Trending</span></button>
        <div class="adm-topbar-right">
            <button type="button" class="adm-ico" onclick="admOpenSearch()" aria-label="Search"><i class="ri-search-line"></i></button>
            <div class="adm-menu-wrap">
                <button type="button" class="adm-ico" onclick="admToggleMenu(event)" aria-label="More"><i class="ri-more-2-fill"></i></button>
                <div class="adm-menu-dd" id="admMenuDd">
                    <div class="adm-menu-item" onclick="admMenuShare()"><i class="ri-share-forward-line"></i> Share</div>
                    <div class="adm-menu-item" onclick="admCopyLink()"><i class="ri-link"></i> Copy link</div>
                    <div class="adm-menu-item" onclick="admShowQr()"><i class="ri-qr-code-line"></i> QR code</div>
                </div>
            </div>
        </div>
    </div>

    <!-- skeleton while get_audio_detail loads -->
    <div class="adm-skeleton" id="admSkeleton">
        <div class="adm-sk-head">
            <div class="sk adm-sk-art"></div>
            <div class="adm-sk-text"><div class="sk adm-sk-t"></div><div class="sk adm-sk-u"></div></div>
        </div>
        <div class="adm-sk-actions"><div class="sk adm-sk-meta"></div><div class="adm-sk-gap"></div><div class="sk adm-sk-round"></div><div class="sk adm-sk-round"></div></div>
        <div class="sk adm-sk-use"></div>
        <div class="sk adm-sk-label"></div>
        <div class="adm-sk-grid"><?php for($i=0;$i<9;$i++): ?><div class="sk adm-sk-tile"></div><?php endfor; ?></div>
    </div>

    <div id="admContent">
        <div class="adm-head">
            <div class="adm-art-wrap" onclick="admTogglePlay()" role="button" tabindex="0" aria-label="Play or pause" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();admTogglePlay();}">
                <svg class="adm-ring" viewBox="0 0 84 84" aria-hidden="true">
                    <circle class="adm-ring-bg" cx="42" cy="42" r="38"></circle>
                    <circle class="adm-ring-fill" id="admProgressFill" cx="42" cy="42" r="38"></circle>
                </svg>
                <div class="adm-art" id="admArtwork"><i class="ri-play-fill" id="admPlayIcon"></i></div>
            </div>
            <div class="adm-head-text">
                <div class="adm-title-wrap"><div class="adm-title" id="admAudioTitle">Original audio</div></div>
                <div class="adm-creator" id="admCreatorRow">
                    <img class="adm-creator-pic" id="admCreatorPic" src="uploads/me.jpeg" alt="" onerror="this.src='uploads/me.jpeg'" onclick="admGoToProfile()">
                    <span class="adm-creator-name" id="admCreatorUsername" onclick="admGoToProfile()">username</span>
                    <span class="adm-dot-sep" id="admCreatorDot">·</span>
                    <button type="button" class="adm-track" id="admTrackBtn" onclick="admToggleTrack()">Track</button>
                </div>
            </div>
        </div>

        <div class="adm-actions">
            <div class="adm-meta" id="admAudioMeta"><span id="admDuration">0:00</span><span class="adm-dot-sep">·</span><span id="admUsageCount">0 zeems</span></div>
            <div class="adm-gap"></div>
            <button type="button" class="adm-round" id="admSaveBtn" onclick="admToggleSave()" title="Save" aria-label="Save"><i class="ri-bookmark-line"></i></button>
            <button type="button" class="adm-round" onclick="admOpenCollectionSheet()" title="Add to collection" aria-label="Add to collection"><i class="ri-folder-add-line"></i></button>
        </div>

        <div class="adm-use-wrap"><button type="button" class="adm-use" id="admUseBtn" onclick="admUseAudio()"><i class="ri-music-2-fill"></i> Use sound</button></div>

        <div class="adm-section-label">Zeems using this audio</div>
        <div class="adm-grid" id="admPostsGrid"></div>
    </div>
  </div>
</div>

<!-- QR overlay -->
<div class="md-overlay" id="admQrOverlay" style="z-index:999999999;" onclick="if(event.target===this)admCloseQr()">
    <div class="md-card" style="max-width:300px;">
        <div class="md-hdr"><span class="md-title"><i class="ri-qr-code-line" style="color:var(--red)"></i> QR code</span><button type="button" class="md-close" onclick="admCloseQr()" aria-label="Close"><i class="ri-close-line"></i></button></div>
        <div class="md-body" style="display:flex;flex-direction:column;align-items:center;gap:12px;">
            <img id="admQrImg" src="" alt="QR code" style="width:100%;max-width:200px;border-radius:8px;background:#fff;padding:8px;">
            <div style="font-size:.74rem;color:var(--muted);text-align:center;">Scan to open this audio</div>
        </div>
    </div>
</div>

<style>
/* Uses the same --m multiplier as the main stylesheet (1 on desktop, 1.1 on screens ≤800px).
   --pad, --topbar-h, --ctl-h, --r-btn are already scaled there, so they are not scaled again here. */
.adm-overlay{ position:fixed; inset:0; z-index:999999990; background:rgba(0,0,0,.65); backdrop-filter:blur(4px); -webkit-backdrop-filter:blur(4px); opacity:0; pointer-events:none; transition:opacity .25s ease; }
.adm-overlay.show{ opacity:1; pointer-events:all; }
.adm-panel{
    position:fixed; inset:0; z-index:999999991; overflow-y:auto; -webkit-overflow-scrolling:touch;
    background:var(--solid);
    transform:translateX(100%); transition:transform .32s cubic-bezier(.32,.72,0,1);
}
.adm-panel.show{ transform:none; }
.adm-lane{ width:100%; max-width:var(--max-w); min-height:100%; margin:0 auto; display:flex; flex-direction:column; padding-bottom:calc(40px * var(--m)); }
@media (min-width:801px){
    .adm-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); }
    .adm-panel{ transform:none; opacity:0; pointer-events:none; transition:opacity .25s ease; }
    .adm-panel.show{ opacity:1; pointer-events:all; }
}

.adm-topbar{
    position:sticky; top:0; z-index:5; height:var(--topbar-h); display:flex; align-items:center; padding:0 calc(10px * var(--m));
    background:var(--solid);
}
.adm-topbar::after{ content:''; position:absolute; left:0; right:0; bottom:0; height:1px; background:var(--line2); pointer-events:none; }
.adm-ico{ width:calc(34px * var(--m)); height:calc(34px * var(--m)); color:var(--ink); font-size:calc(1.35rem * var(--m)); display:flex; align-items:center; justify-content:center; opacity:.9; transition:opacity .2s, color .2s; flex-shrink:0; }
.adm-ico:hover{ opacity:1; color:var(--red); }
.adm-back svg{ width:calc(22px * var(--m)); height:calc(22px * var(--m)); display:block; }
.adm-trending{ position:absolute; left:50%; top:50%; transform:translate(-50%,-50%); display:flex; align-items:center; gap:calc(5px * var(--m)); font-size:calc(.86rem * var(--m)); font-weight:700; padding:calc(6px * var(--m)) calc(8px * var(--m)); white-space:nowrap; }
.adm-trending i{ font-size:calc(1rem * var(--m)); }
.adm-topbar-right{ margin-left:auto; display:flex; align-items:center; gap:calc(2px * var(--m)); }
.adm-menu-wrap{ position:relative; }
.adm-menu-dd{ display:none; position:absolute; top:calc(100% + 6px * var(--m)); right:0; min-width:calc(170px * var(--m)); z-index:10; overflow:hidden; background:var(--panel); border:1px solid var(--line2); border-radius:calc(8px * var(--m)); box-shadow:0 16px 50px rgba(0,0,0,.6); }
.adm-menu-dd.open{ display:block; animation:auFade .15s ease both; }
.adm-menu-item{ display:flex; align-items:center; gap:calc(10px * var(--m)); padding:calc(12px * var(--m)) calc(15px * var(--m)); font-size:calc(.8rem * var(--m)); font-weight:500; color:var(--sub); cursor:pointer; border-bottom:1px solid var(--line); transition:background .15s; }
.adm-menu-item:last-child{ border-bottom:none; }
.adm-menu-item:hover{ background:rgba(var(--fg),.05); color:var(--ink); }
.adm-menu-item i{ color:var(--red); font-size:calc(.95rem * var(--m)); }

/* skeleton */
.adm-skeleton{ display:none; flex-direction:column; }
.adm-skeleton.adm-sk-show{ display:flex; }
#admContent{ display:flex; flex-direction:column; }
.adm-sk-head{ display:flex; align-items:center; gap:calc(16px * var(--m)); padding:calc(22px * var(--m)) var(--pad) calc(8px * var(--m)); }
.adm-sk-art{ width:calc(84px * var(--m)); height:calc(84px * var(--m)); border-radius:50%; flex-shrink:0; }
.adm-sk-text{ flex:1; display:flex; flex-direction:column; gap:calc(12px * var(--m)); }
.adm-sk-t{ height:calc(16px * var(--m)); width:70%; }
.adm-sk-u{ height:calc(12px * var(--m)); width:calc(110px * var(--m)); }
.adm-sk-actions{ display:flex; align-items:center; gap:calc(10px * var(--m)); padding:calc(18px * var(--m)) var(--pad) calc(6px * var(--m)); }
.adm-sk-meta{ height:calc(14px * var(--m)); width:calc(120px * var(--m)); }
.adm-sk-gap{ flex:1; }
.adm-sk-round{ width:calc(40px * var(--m)); height:calc(40px * var(--m)); border-radius:var(--r-btn); }
.adm-sk-use{ height:calc(34px * var(--m)); margin:calc(14px * var(--m)) var(--pad) calc(6px * var(--m)); border-radius:var(--r-btn); }
.adm-sk-label{ height:calc(11px * var(--m)); width:calc(160px * var(--m)); margin:calc(22px * var(--m)) var(--pad) calc(10px * var(--m)); }
.adm-sk-grid{ display:grid; grid-template-columns:repeat(3,1fr); gap:1px; }
.adm-sk-tile{ aspect-ratio:1; border-radius:0; }

/* head */
.adm-head{ display:flex; align-items:center; gap:calc(16px * var(--m)); padding:calc(22px * var(--m)) var(--pad) calc(8px * var(--m)); }
.adm-art-wrap{ position:relative; width:calc(84px * var(--m)); height:calc(84px * var(--m)); flex-shrink:0; cursor:pointer; transition:transform .15s ease; }
.adm-art-wrap:active{ transform:scale(.94); }
.adm-ring{ position:absolute; inset:0; width:100%; height:100%; transform:rotate(-90deg); }
.adm-ring-bg{ fill:none; stroke:rgba(var(--fg),.1); stroke-width:3; }
.adm-ring-fill{ fill:none; stroke:var(--red); stroke-width:3; stroke-linecap:round; stroke-dasharray:238.76; stroke-dashoffset:238.76; transition:stroke-dashoffset .15s linear; }
.adm-art{ position:absolute; inset:calc(6px * var(--m)); border-radius:50%; overflow:hidden; background:var(--surface) center/cover no-repeat; border:1px solid var(--line2); display:flex; align-items:center; justify-content:center; }
.adm-art i{ font-size:calc(1.7rem * var(--m)); color:var(--red); filter:drop-shadow(0 2px 6px rgba(0,0,0,.6)); }
.adm-head-text{ flex:1; min-width:0; }
.adm-title-wrap{ overflow:hidden; width:100%; }
.adm-title{ display:inline-block; max-width:100%; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; font-size:calc(1.05rem * var(--m)); font-weight:700; }
.adm-title.adm-marquee{ text-overflow:clip; animation:admMarquee var(--marquee-dur,6s) ease-in-out infinite alternate; }
@keyframes admMarquee{ 0%,12%{ transform:translateX(0); } 88%,100%{ transform:translateX(var(--marquee-dist,0px)); } }
.adm-creator{ display:flex; align-items:center; gap:calc(7px * var(--m)); margin-top:calc(6px * var(--m)); }
.adm-creator-pic{ width:calc(22px * var(--m)); height:calc(22px * var(--m)); border-radius:50%; object-fit:cover; border:2px solid var(--red); cursor:pointer; flex-shrink:0; }
.adm-creator-name{ font-size:calc(.82rem * var(--m)); font-weight:600; cursor:pointer; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:calc(120px * var(--m)); }
.adm-dot-sep{ color:var(--muted); font-size:calc(.85rem * var(--m)); }
.adm-track{ color:var(--red); font-size:calc(.78rem * var(--m)); font-weight:600; padding:0 0 calc(2px * var(--m)); border-bottom:1.5px solid var(--red); line-height:1.1; flex-shrink:0; }
.adm-actions{ display:flex; align-items:center; gap:calc(8px * var(--m)); padding:calc(16px * var(--m)) var(--pad) calc(4px * var(--m)); }
.adm-meta{ display:flex; align-items:center; gap:calc(6px * var(--m)); font-size:calc(.82rem * var(--m)); color:var(--sub); }
.adm-gap{ flex:1; }
.adm-round{ width:calc(38px * var(--m)); height:calc(38px * var(--m)); border-radius:var(--r-btn); background:rgba(var(--fg),.1); color:var(--ink); font-size:calc(1.1rem * var(--m)); display:flex; align-items:center; justify-content:center; backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px); transition:background .2s; }
.adm-round:hover{ background:rgba(var(--fg),.2); }
.adm-use-wrap{ padding:calc(14px * var(--m)) var(--pad) calc(4px * var(--m)); }
.adm-use{ display:flex; align-items:center; justify-content:center; gap:calc(6px * var(--m)); width:100%; height:var(--ctl-h); border-radius:var(--r-btn); background:var(--red); color:#000; font-size:calc(.78rem * var(--m)); font-weight:700; transition:background .2s, transform .15s; }
.adm-use:hover{ background:var(--red-hover); }
.adm-use:active{ transform:scale(.98); }
.adm-section-label{ padding:calc(22px * var(--m)) var(--pad) calc(8px * var(--m)); font-size:calc(.6rem * var(--m)); font-weight:700; text-transform:uppercase; letter-spacing:1.5px; color:var(--muted); }
.adm-grid{ display:grid; grid-template-columns:repeat(3,1fr); gap:1px; padding:0 0 calc(30px * var(--m)); border-top:1px solid var(--line); }
.adm-tile{ position:relative; aspect-ratio:1; background:var(--tile); overflow:hidden; cursor:pointer; }
.adm-tile img, .adm-tile video{ width:100%; height:100%; object-fit:cover; display:block; transition:transform .25s ease; }
.adm-tile:hover img, .adm-tile:hover video{ transform:scale(1.04); }
.adm-tile-vid{ position:absolute; top:calc(6px * var(--m)); right:calc(6px * var(--m)); color:#fff; font-size:calc(.85rem * var(--m)); text-shadow:0 1px 4px rgba(0,0,0,.8); }
.adm-empty{ grid-column:1/-1; text-align:center; padding:calc(40px * var(--m)) calc(20px * var(--m)); color:var(--muted); font-size:calc(.78rem * var(--m)); }
@media (max-width:490px){
    .adm-head{ padding:calc(16px * var(--m)) var(--pad) calc(5px * var(--m)); gap:calc(12px * var(--m)); }
    .adm-art-wrap{ width:calc(68px * var(--m)); height:calc(68px * var(--m)); } .adm-art i{ font-size:calc(1.4rem * var(--m)); }
    .adm-sk-art{ width:calc(68px * var(--m)); height:calc(68px * var(--m)); }
    .adm-title{ font-size:calc(.95rem * var(--m)); } .adm-creator-name{ font-size:calc(.76rem * var(--m)); max-width:calc(96px * var(--m)); } .adm-track{ font-size:calc(.72rem * var(--m)); }
    .adm-meta{ font-size:calc(.76rem * var(--m)); } .adm-round{ width:calc(34px * var(--m)); height:calc(34px * var(--m)); font-size:calc(1rem * var(--m)); }
    .adm-trending{ font-size:calc(.8rem * var(--m)); } .adm-use{ font-size:calc(.74rem * var(--m)); }
}
@media (max-width:380px){ .adm-art-wrap, .adm-sk-art{ width:calc(58px * var(--m)); height:calc(58px * var(--m)); } .adm-title{ font-size:calc(.88rem * var(--m)); } .adm-creator-name{ max-width:calc(72px * var(--m)); } }
</style>

<script>
/* ══════════════════════════════════════════════════════════
   AUDIO DETAIL PANEL logic
══════════════════════════════════════════════════════════ */
var _admAudioId = null, _admAudio = null, _admIsSaved = false;
var _admRingCirc = 2 * Math.PI * 38;
var _admFeed = [], _admPostsList = [], _admCreatorId = null, _admIsTracking = false, _admTotalDurationText = '0:00';
var _admCurrentUserId = <?php echo (int)$current_user; ?>;

function admToast(msg){ scToast(msg); }

function _admFmtTime(s){
    if(!s || isNaN(s)) return '0:00';
    return Math.floor(s/60)+':'+String(Math.floor(s%60)).padStart(2,'0');
}

function _admInitTitleMarquee(){
    var wrap = document.querySelector('.adm-title-wrap'), text = document.getElementById('admAudioTitle');
    if(!wrap || !text) return;
    text.classList.remove('adm-marquee');
    text.style.removeProperty('--marquee-dist'); text.style.removeProperty('--marquee-dur');
    requestAnimationFrame(function(){
        var overflow = text.scrollWidth - wrap.clientWidth;
        if(overflow > 4){
            text.classList.add('adm-marquee');
            text.style.setProperty('--marquee-dist', (-overflow - 6)+'px');
            text.style.setProperty('--marquee-dur', Math.max(4.5, Math.min(14, overflow / 22))+'s');
        }
    });
}
window.addEventListener('resize', _admInitTitleMarquee);

function _admPost(body){
    return fetch(window.location.pathname, {
        method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body
    }).then(function(r){ return r.json(); });
}

function openAudioDetailModal(audioId){
    if(!audioId) return;
    _admAudioId = audioId;

    document.getElementById('audioDetailOverlay').classList.add('show');
    document.getElementById('audioDetailPanel').classList.add('show');
    document.body.style.overflow = 'hidden';

    document.getElementById('admContent').style.display = 'none';
    document.getElementById('admSkeleton').classList.add('adm-sk-show');
    document.getElementById('admPostsGrid').innerHTML = '';

    var fill = document.getElementById('admProgressFill');
    if(fill){ fill.style.strokeDasharray = _admRingCirc; fill.style.strokeDashoffset = _admRingCirc; }
    document.getElementById('admPlayIcon').className = 'ri-play-fill';

    _admPost('sc_action=get_audio_detail&audio_id=' + audioId).then(function(data){
        if(data.status !== 'ok'){ closeAudioDetailModal(); scToast('This audio is not available'); return; }

        document.getElementById('admAudioTitle').textContent = data.audio.title || 'Original audio';
        var usageEl = document.getElementById('admUsageCount');
        if(usageEl) usageEl.textContent = data.usage_count + (data.usage_count===1 ? ' zeem' : ' zeems');
        _admInitTitleMarquee();

        _admCreatorId = data.audio.uploader_id || null;
        document.getElementById('admCreatorUsername').textContent = data.audio.username;
        document.getElementById('admCreatorPic').src = data.audio.profile_pic || 'uploads/me.jpeg';

        var trackBtn = document.getElementById('admTrackBtn'), trackDot = document.getElementById('admCreatorDot');
        var isOwnAudio = String(_admCreatorId) === String(_admCurrentUserId);
        trackBtn.style.display = trackDot.style.display = isOwnAudio ? 'none' : '';
        if(!isOwnAudio){
            _admIsTracking = !!data.is_following;
            trackBtn.textContent = _admIsTracking ? 'Untrack' : 'Track';
            trackBtn.classList.toggle('tracking', _admIsTracking);
        }

        _admIsSaved = !!data.is_saved;
        var saveBtn = document.getElementById('admSaveBtn');
        saveBtn.classList.toggle('saved', _admIsSaved);
        saveBtn.innerHTML = _admIsSaved ? '<i class="ri-bookmark-fill"></i>' : '<i class="ri-bookmark-line"></i>';

        if(_admAudio) _admAudio.pause();
        _admAudio = new Audio('uploads/' + data.audio.file_name);
        _admTotalDurationText = '0:00';
        if(data.audio.duration){
            _admTotalDurationText = _admFmtTime(data.audio.duration);
            document.getElementById('admDuration').textContent = _admTotalDurationText;
        } else {
            _admAudio.addEventListener('loadedmetadata', function(){
                _admTotalDurationText = _admFmtTime(this.duration);
                document.getElementById('admDuration').textContent = _admTotalDurationText;
            });
        }
        _admAudio.addEventListener('timeupdate', function(){
            if(!this.duration || isNaN(this.duration)) return;
            var f = document.getElementById('admProgressFill');
            if(f) f.style.strokeDashoffset = _admRingCirc * (1 - this.currentTime / this.duration);
            document.getElementById('admDuration').textContent = _admFmtTime(this.currentTime);
        });
        _admAudio.addEventListener('pause', function(){
            document.getElementById('admDuration').textContent = _admTotalDurationText;
            document.getElementById('admPlayIcon').className = 'ri-play-fill';
        });
        _admAudio.addEventListener('ended', function(){
            document.getElementById('admPlayIcon').className = 'ri-play-fill';
            var f = document.getElementById('admProgressFill');
            if(f) f.style.strokeDashoffset = _admRingCirc;
            document.getElementById('admDuration').textContent = _admTotalDurationText;
        });

        var artEl = document.getElementById('admArtwork');
        if(data.audio.cover_art) artEl.style.backgroundImage = "url('uploads/" + String(data.audio.cover_art).replace(/'/g, "%27") + "')";
        else artEl.style.backgroundImage = '';

        _admPostsList = data.posts || [];
        _admFeed = data.feed || [];
        window.kebabData = Object.assign(window.kebabData || {}, data.kebab || {});
        var grid = document.getElementById('admPostsGrid');
        if(!_admPostsList.length){
            grid.innerHTML = '<div class="adm-empty">No zeems use this audio yet</div>';
        } else {
            _admPostsList.forEach(function(p, idx){
                var tile = document.createElement('div');
                tile.className = 'adm-tile';
                tile.onclick = function(){ admOpenZeems(p.id); };
                if(p.type === 'video')     tile.innerHTML = '<video src="uploads/'+_scEsc(p.file_name)+'#t=0.1" '+(p.cover_image ? 'poster="uploads/'+_scEsc(p.cover_image)+'" ' : '')+'muted playsinline preload="metadata"></video><div class="adm-tile-vid"><i class="ri-play-fill"></i></div>';
                else if(p.type === 'text') tile.innerHTML = '<img src="uploads/Text.jpg" alt="">';
                else                       tile.innerHTML = '<img src="uploads/'+_scEsc(p.file_name)+'" alt="" onerror="this.src=\'uploads/me.jpeg\'">';
                grid.appendChild(tile);
            });
        }

        document.getElementById('admSkeleton').classList.remove('adm-sk-show');
        document.getElementById('admContent').style.display = '';
    }).catch(function(){ closeAudioDetailModal(); scToast('Something went wrong'); });
}

/* a tapped zeem opens in the shared Zeems modal (zeems_modal.php): swipe up / down moves through the rest of this sound's zeems,
   and it carries a red "Use this sound" button where "Share this in the Chat" sits on the chat page */
function admOpenZeems(id){
    if(typeof window.openCardModal !== 'function'){ scToast('Zeems is not ready yet'); return; }
    if(!_admFeed.some(function(p){ return String(p.id) === String(id); })) return;
    if(_admAudio && !_admAudio.paused) admTogglePlay();      /* only one thing audible at a time */
    var aid = _admAudioId;
    window.CCM_USE_SOUND = { audioId: aid, go: function(){ if(aid) window.location.href = 'uploads.php?audio_id=' + aid; } };
    window.openCardModal({ id: id, feed: _admFeed, mediaIndex: 0, startTime: 0 });
    /* the sound panel sits at a very high z-index: lift the Zeems modal above it */
    var zm = document.getElementById('cardCaptionModal');
    if(zm && typeof bringModalToFront === 'function'){ bringModalToFront(zm); setTimeout(function(){ bringModalToFront(zm); }, 120); }
}
document.addEventListener('zeems:closed', function(){
    window.CCM_USE_SOUND = null;
    var pn = document.getElementById('audioDetailPanel');
    if(pn && pn.classList.contains('show')) setTimeout(function(){ document.body.style.overflow = 'hidden'; }, 60);   /* the sound panel is still open behind it */
});

function closeAudioDetailModal(){
    document.getElementById('audioDetailOverlay').classList.remove('show');
    document.getElementById('audioDetailPanel').classList.remove('show');
    document.getElementById('admMenuDd').classList.remove('open');
    if(!document.getElementById('scListenModal').classList.contains('open')) document.body.style.overflow = '';
    if(_admAudio){ _admAudio.pause(); _admAudio = null; }
    document.getElementById('admPlayIcon').className = 'ri-play-fill';
    _admAudioId = null;
    if(window.history.replaceState){
        var url = new URL(window.location.href);
        url.searchParams.delete('audio_id');
        window.history.replaceState({}, '', url);
    }
}

function admTogglePlay(){
    if(!_admAudio) return;
    var icon = document.getElementById('admPlayIcon');
    if(_admAudio.paused){
        /* only one thing audible at a time */
        if(typeof _scLmAudio !== 'undefined' && _scLmAudio && !_scLmAudio.paused) scLmTogglePlay();
        _admAudio.play(); icon.className = 'ri-pause-fill';
    } else { _admAudio.pause(); icon.className = 'ri-play-fill'; }
}

function admGoToProfile(){ if(_admCreatorId) window.location.href = 'profile.php?user_id=' + _admCreatorId; }

function admToggleTrack(){
    if(!_admCreatorId) return;
    _admPost('sc_action=toggle_follow&user_id=' + _admCreatorId).then(function(data){
        if(data.status !== 'ok') return;
        _admIsTracking = !!data.following;
        var btn = document.getElementById('admTrackBtn');
        btn.textContent = _admIsTracking ? 'Untrack' : 'Track';
        btn.classList.toggle('tracking', _admIsTracking);
    });
}

function admOpenTrendModal(){
    var m = document.getElementById('trendmodal');
    if(!m) return;
    m.classList.add('open'); m.classList.add('show');
}
function admOpenSearch(){
    var m = document.getElementById('searchModal');
    if(m){ m.classList.add('open'); m.classList.add('show'); return; }
    window.location.href = 'search.php';
}

function admToggleSave(){
    if(!_admAudioId) return;
    _admPost('sc_action=toggle_save_audio&audio_id=' + _admAudioId).then(function(data){
        if(data.status !== 'ok') return;
        _admIsSaved = data.saved;
        var saveBtn = document.getElementById('admSaveBtn');
        saveBtn.classList.toggle('saved', _admIsSaved);
        saveBtn.innerHTML = _admIsSaved ? '<i class="ri-bookmark-fill"></i>' : '<i class="ri-bookmark-line"></i>';
        scToast(_admIsSaved ? 'Audio saved' : 'Removed from saved');
    });
}

function admOpenCollectionSheet(){
    if(!_admAudioId) return;
    window._admAddToFolderAudioId = _admAudioId;
    var list = document.getElementById('atfList');
    list.innerHTML = '<div class="au-loading"><i class="ri-loader-4-line"></i> Loading...</div>';
    document.getElementById('scAddFolderModal').classList.add('open');

    _scFetch('sc_action=get_my_folders', function(d){
        var folders = (d.status==='ok') ? d.folders : [];
        if(!folders.length){ list.innerHTML = '<div class="md-msg">No collections yet.<br>Create one below.</div>'; return; }
        list.innerHTML = '';
        folders.forEach(function(f){
            var item = document.createElement('div');
            item.className = 'atf-item';
            var icon = f.cover_image ? '<img src="uploads/folder_covers/'+_scEsc(f.cover_image)+'" alt="">' : '<i class="ri-folder-music-line"></i>';
            item.innerHTML =
                '<div class="atf-icon">'+icon+'</div>'+
                '<div class="atf-info"><div class="atf-name">'+_scEsc(f.name)+'</div><div class="atf-count">'+f.item_count+' track'+(f.item_count==1?'':'s')+'</div></div>'+
                '<div class="atf-check" id="atfk-audio-'+f.id+'">✓</div>';
            item.addEventListener('click', function(){ admAddAudioToFolder(f.id); });
            list.appendChild(item);
        });
    });
}

function admAddAudioToFolder(folderId){
    _scFetch('sc_action=add_audio_to_folder&folder_id='+folderId+'&audio_id='+window._admAddToFolderAudioId, function(d){
        if(d.status==='already'){ scToast('Already in this collection'); return; }
        if(d.status!=='ok'){ scToast('Could not add'); return; }
        var chk = document.getElementById('atfk-audio-'+folderId);
        if(chk) chk.classList.add('on');
        scToast('Added to collection');
    });
}

function _admLink(){ return window.location.origin + window.location.pathname + '?audio_id=' + _admAudioId; }
function admMenuShare(){
    document.getElementById('admMenuDd').classList.remove('open');
    if(!_admAudioId) return;
    if(navigator.share) navigator.share({ title:'Check this audio', url:_admLink() }).catch(function(){});
    else navigator.clipboard.writeText(_admLink()).then(function(){ scToast('Link copied'); });
}
function admCopyLink(){
    document.getElementById('admMenuDd').classList.remove('open');
    if(!_admAudioId) return;
    navigator.clipboard.writeText(_admLink()).then(function(){ scToast('Link copied'); }).catch(function(){ scToast('Could not copy link'); });
}
function admShowQr(){
    document.getElementById('admMenuDd').classList.remove('open');
    if(!_admAudioId) return;
    document.getElementById('admQrImg').src = 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=' + encodeURIComponent(_admLink());
    document.getElementById('admQrOverlay').classList.add('open');
}
function admCloseQr(){ document.getElementById('admQrOverlay').classList.remove('open'); }

function admUseAudio(){ if(_admAudioId) window.location.href = 'uploads.php?audio_id=' + _admAudioId; }

function admToggleMenu(e){ e.stopPropagation(); document.getElementById('admMenuDd').classList.toggle('open'); }
document.addEventListener('click', function(e){
    var dd = document.getElementById('admMenuDd'), wrap = document.querySelector('.adm-menu-wrap');
    if(dd && wrap && !wrap.contains(e.target)) dd.classList.remove('open');
});

document.addEventListener('DOMContentLoaded', function(){
    var aid = new URLSearchParams(window.location.search).get('audio_id');
    if(aid) openAudioDetailModal(parseInt(aid, 10));
});
</script>

<?php /* ══ SHARED ZEEMS MODAL: likes / reposts / saves / shares / follow post to profile.php (the CSRF token is added by the fetch wrapper in <head>) ══ */ ?>
<script>
window.CCM_ENDPOINT = 'profile.php';
window.currentUserId = <?php echo (int)$current_user; ?>;
window.showToast = window.showToast || function(m){ if(typeof scToast === 'function') scToast(m); };
window.kebabData = {};                                /* filled with each sound's posts when its panel opens */
var _appTopZIndex = 2147483000;
function bringModalToFront(el){ if(el) el.style.zIndex = ++_appTopZIndex; }
window.peuraFeed = window.peuraFeed || [];            /* the list comes from admOpenZeems(); this only keeps the old global defined */
window._suggPromise = window._suggPromise || Promise.resolve([]);
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



<script>
/* ══════════════════════════════════════════════════════════
   AUDIO LIBRARY — page logic
══════════════════════════════════════════════════════════ */
var _scIsOwner   = <?= $is_owner ? 'true' : 'false' ?>;
var _scProfileId = <?= (int)$profile_id ?>;
var _scFolders   = <?= json_encode(array_values($sound_folders), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
var _scActiveSound = null, _scActiveFolderId = null;
var _scNmMode = 'create', _scNmCoverFile = null;
var _scOptsId = null, _scOptsName = null, _scOptsPublic = false;

/* ── helpers ── */
function _scEsc(s){
    if(s === null || s === undefined) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
function scFmt(s){ if(!isFinite(s) || s < 0) return '0:00'; return Math.floor(s/60)+':'+String(Math.floor(s%60)).padStart(2,'0'); }
function _scPlural(n){ return n+' track'+(n==1?'':'s'); }

var _scToastTimer = null;
function scToast(msg){
    var t = document.getElementById('scToast');
    if(!t) return;
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(_scToastTimer);
    _scToastTimer = setTimeout(function(){ t.classList.remove('show'); }, 2300);
}

function _scFetch(body, cb){
    fetch(window.location.href, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body })
    .then(function(r){ return r.json(); }).then(cb)
    .catch(function(){ scToast('Something went wrong'); });
}
function _scFetchForm(formData, cb){
    fetch(window.location.href, { method:'POST', body: formData })
    .then(function(r){ return r.json(); }).then(cb)
    .catch(function(){ scToast('Something went wrong'); });
}

function auClose(){
    if (document.referrer && document.referrer.indexOf(window.location.host) !== -1) history.back();
    else location.href = 'profile.php?user_id=' + _scProfileId;
}

/* ── skeleton ── */
window.addEventListener('load', function(){
    scMoveInd();
    var sk = document.getElementById('page-skeleton');
    if(!sk) return;
    setTimeout(function(){ sk.classList.add('hide'); setTimeout(function(){ sk.remove(); }, 380); }, 280);
});
document.addEventListener('DOMContentLoaded', function(){ scMoveInd(); scRenderFolders(); });

/* ── tabs + finger-following swipe pager (same engine as Tracks) ── */
var _scTabNames = ['uploaded','added','folders'];
var _auPager = document.getElementById('auPager'), _auTrack = document.getElementById('auTrack');
var _auPanels = _scTabNames.map(function(n){ return document.getElementById('panel-'+n); });
var _auTabBtns = Array.prototype.slice.call(document.querySelectorAll('.au-tab'));
var _auInd = document.getElementById('auTabInd');
var _auIdx = 0, _auW = 0, _auSettle = null;

function _scSetTabCount(tabId, n){
    var el = document.querySelector('#'+tabId+' .au-tab-count');
    if(el) el.textContent = Number(n).toLocaleString();
}
function _scBumpTab(tabId, delta){
    var el = document.querySelector('#'+tabId+' .au-tab-count');
    if(!el) return;
    var n = parseInt(String(el.textContent).replace(/[^0-9]/g,''), 10) || 0;
    _scSetTabCount(tabId, Math.max(0, n + delta));
}

function scMoveInd(){
    var b = _auTabBtns[_auIdx];
    if(!b || !_auInd) return;
    _auInd.style.left = b.offsetLeft + 'px';
    _auInd.style.width = b.offsetWidth + 'px';
    _auInd.classList.add('ready');
}
function _auIndicatorAt(p){
    if(!_auInd || !_auTabBtns.length) return;
    p = Math.max(0, Math.min(_auTabBtns.length - 1, p));
    var i = Math.floor(p), f = p - i, a = _auTabBtns[i], b = _auTabBtns[Math.min(i + 1, _auTabBtns.length - 1)];
    _auInd.style.left  = (a.offsetLeft  + (b.offsetLeft  - a.offsetLeft)  * f) + 'px';
    _auInd.style.width = (a.offsetWidth + (b.offsetWidth - a.offsetWidth) * f) + 'px';
    _auInd.classList.add('ready');
}
function _auSetX(x, animate){
    _auTrack.style.transition = animate ? 'transform .4s cubic-bezier(.22,1,.36,1)' : 'none';
    _auTrack.style.transform  = 'translate3d(' + x + 'px,0,0)';
}
function _auFinish(){
    clearTimeout(_auSettle);
    _auPager.classList.add('idle');
    _auPager.classList.remove('dragging');
}

function _auGoTo(i, animate){
    i = Math.max(0, Math.min(_auPanels.length - 1, i));
    var prev = _auIdx;
    _auIdx = i;

    _auTabBtns.forEach(function(b, k){ b.classList.toggle('active', k === i); });
    _auPanels.forEach(function(p, k){ p.classList.toggle('active', k === i); });
    document.getElementById('searchRow').classList.toggle('off', i === 2);

    if(prev !== i){
        var s = document.getElementById('scSearch');
        if(s && s.value){ s.value = ''; s.dispatchEvent(new Event('input')); }
        if(i === 2) scRenderFolders();
    }

    _auPager.classList.remove('idle');
    _auInd.classList.remove('live');
    scMoveInd();
    try { _auTabBtns[i].scrollIntoView({ inline:'nearest', block:'nearest' }); } catch(e){}

    _auW = _auPager.clientWidth;
    _auSetX(-i * _auW, animate);

    clearTimeout(_auSettle);
    if(animate) _auSettle = setTimeout(_auFinish, 440); else _auFinish();
}

function scSwitchTab(name){
    var i = _scTabNames.indexOf(name);
    if(i > -1 && i !== _auIdx) _auGoTo(i, true);
}

window.addEventListener('resize', function(){
    _auW = _auPager.clientWidth;
    _auSetX(-_auIdx * _auW, false);
    scMoveInd();
});

(function(){
    var st = null, swallowClick = false;

    document.addEventListener('click', function(e){
        if(swallowClick){ e.stopPropagation(); e.preventDefault(); }
    }, true);

    _auPager.addEventListener('pointerdown', function(e){
        if(e.pointerType === 'mouse' && e.button !== 0) return;
        if(e.target.closest && e.target.closest('input')) return;
        st = { id:e.pointerId, x:e.clientX, y:e.clientY, lock:null, samples:[{ x:e.clientX, t:performance.now() }] };
    });

    _auPager.addEventListener('pointermove', function(e){
        if(!st || e.pointerId !== st.id) return;
        var dx = e.clientX - st.x, dy = e.clientY - st.y;

        if(!st.lock){
            if(Math.abs(dx) < 6 && Math.abs(dy) < 6) return;
            if(Math.abs(dy) > Math.abs(dx)){ st = null; return; }
            st.lock = 'x';
            clearTimeout(_auSettle);
            _auW = _auPager.clientWidth;
            _auPager.classList.remove('idle');
            _auPager.classList.add('dragging');
            _auInd.classList.add('live');
            try { _auPager.setPointerCapture(e.pointerId); } catch(err){}
            var m = /translate3d\((-?[\d.]+)px/.exec(_auTrack.style.transform);
            st.base = m ? parseFloat(m[1]) : -_auIdx * _auW;
            st.x = e.clientX; dx = 0;
        }

        var x = st.base + dx, minX = -(_auPanels.length - 1) * _auW;
        if(x > 0)    x = x * 0.35;
        if(x < minX) x = minX + (x - minX) * 0.35;
        _auSetX(x, false);
        _auIndicatorAt(-x / _auW);

        var now = performance.now();
        st.samples.push({ x:e.clientX, t:now });
        while(st.samples.length > 2 && now - st.samples[0].t > 110) st.samples.shift();
    });

    function release(e){
        if(!st || e.pointerId !== st.id) return;
        var g = st; st = null;
        if(g.lock !== 'x') return;

        try { _auPager.releasePointerCapture(e.pointerId); } catch(err){}
        swallowClick = true;
        setTimeout(function(){ swallowClick = false; }, 60);

        var dx = e.clientX - g.x;
        var a = g.samples[0], b = g.samples[g.samples.length - 1];
        var v = (b.t - a.t) > 10 ? (b.x - a.x) / (b.t - a.t) : 0;

        var target = _auIdx;
        if(Math.abs(v) > 0.35)                 target = _auIdx + (v < 0 ? 1 : -1);
        else if(Math.abs(dx) > _auW * 0.22)    target = _auIdx + (dx < 0 ? 1 : -1);
        _auGoTo(target, true);
    }
    _auPager.addEventListener('pointerup', release);
    _auPager.addEventListener('pointercancel', release);
})();

/* ── search ── */
document.getElementById('scSearch').addEventListener('input', function(){
    var q = this.value.trim().toLowerCase();
    ['uploaded','added'].forEach(function(n){
        var panel = document.getElementById('panel-'+n);
        var rows = panel.querySelectorAll('.sound-row'), vis = 0;
        rows.forEach(function(r){
            var m = !q || (r.dataset.title||'').indexOf(q) > -1 || (r.dataset.user||'').indexOf(q) > -1;
            r.style.display = m ? '' : 'none';
            if(m) vis++;
        });
        var nr = panel.querySelector('.au-noresult');
        if(nr) nr.style.display = (q && rows.length && !vis) ? 'flex' : 'none';
    });
});

/* ── upload + savers toggles ── */
function scToggleUpload(){
    var sec = document.getElementById('uploadSection'), tog = document.getElementById('uploadToggle');
    if(!sec || !tog) return;
    var open = !sec.classList.contains('open');
    sec.classList.toggle('open', open);
    tog.classList.toggle('open', open);
    tog.setAttribute('aria-expanded', open ? 'true' : 'false');
}
function scToggleSavers(btn){
    var list = document.getElementById('hpList');
    var open = !list.classList.contains('open');
    list.classList.toggle('open', open);
    btn.classList.toggle('open', open);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
}
(function(){
    var fi = document.querySelector('input[name="audio"]'), pi = document.querySelector('input[name="picture"]');
    if(fi) fi.addEventListener('change', function(){ document.getElementById('audioFileText').textContent = fi.files[0] ? fi.files[0].name : 'Choose audio file (mp3, wav, ogg)'; });
    if(pi) pi.addEventListener('change', function(){ document.getElementById('pictureFileText').textContent = pi.files[0] ? pi.files[0].name : 'Choose cover picture (optional)'; });
})();

/* ══════════════════════════════════════════════════════════
   PLAYER — queue, shuffle, repeat, mini bar
══════════════════════════════════════════════════════════ */
var _scQueue = [], _scQIndex = -1, _scQLabel = '', _scLmAudio = null;
var _scShuffle = false, _scRepeat = 'off', _scHist = [];

function _scBuildQueue(fromEl){
    var grid = fromEl.closest('.sound-grid');
    var rows = grid ? Array.from(grid.querySelectorAll('.sound-row')).filter(function(r){ return r.style.display !== 'none'; }) : [fromEl];

    var label = 'Sounds';
    if(grid){
        if(grid.id === 'grid-uploaded') label = _scIsOwner ? 'Your Sounds' : 'Sounds';
        else if(grid.id === 'grid-added') label = _scIsOwner ? 'Saved' : 'Saves';
        else if(grid.id === 'scDetailGrid') label = (document.getElementById('scDetailTitle')||{}).textContent || 'Collection';
    }
    var queue = rows.map(function(r){
        var t = r.querySelector('.sound-title'), a = r.querySelector('.sound-artist');
        return {
            id: r.dataset.id, kind: r.dataset.kind || 'sound', saveMode: r.dataset.save || null,
            title: t ? t.textContent : 'Untitled',
            artist: a ? a.textContent.trim() : '',
            src: r.dataset.src, thumb: r.dataset.thumb
        };
    });
    return { queue: queue, index: rows.indexOf(fromEl), label: label };
}

function _scMarkPlaying(){
    var cur = _scQueue[_scQIndex];
    document.querySelectorAll('.sound-row').forEach(function(r){
        r.classList.toggle('is-playing', !!cur && r.dataset.src === cur.src);
    });
}

function scOpenListenModal(el){
    /* one thing audible at a time */
    if(typeof _admAudio !== 'undefined' && _admAudio && !_admAudio.paused) admTogglePlay();

    var built = _scBuildQueue(el);
    _scQueue = built.queue; _scQIndex = built.index; _scQLabel = built.label; _scHist = [];
    document.getElementById('slmContext').textContent = _scQLabel;

    var modal = document.getElementById('scListenModal');
    modal.classList.remove('animate-in'); void modal.offsetWidth;
    modal.classList.add('open', 'animate-in');
    document.body.style.overflow = 'hidden';
    document.getElementById('scMiniPlayer').classList.remove('show');

    var single = _scQueue.length <= 1;
    document.getElementById('slmPrevBtn').disabled = single;
    document.getElementById('slmNextBtn').disabled = single;
    document.getElementById('slmShuffleBtn').disabled = single;

    scLmLoadTrack(_scQIndex, true);
}

function _scShowSkeleton(show){
    document.getElementById('slmSkeleton').classList.toggle('show', show);
    document.getElementById('slmContent').classList.toggle('slm-hidden', show);
    if(!show) setTimeout(scLmFitTitle, 0);
}

function _scSetPlayIcon(playing){
    var icon = playing ? 'ri-pause-fill' : 'ri-play-fill', label = playing ? 'Pause' : 'Play';
    ['slmPlayBtn','smpPlayBtn'].forEach(function(id){
        var b = document.getElementById(id);
        b.innerHTML = '<i class="'+icon+'"></i>';
        b.setAttribute('aria-label', label);
    });
    document.getElementById('scListenModal').classList.toggle('is-playing', playing);
}

function scLmLoadTrack(index, autoplay){
    if(index < 0 || index >= _scQueue.length) return;
    _scQIndex = index;
    var t = _scQueue[index];
    _scShowSkeleton(true);
    _scMarkPlaying();

    document.getElementById('slmTitle').textContent = t.title;
    document.getElementById('slmArtist').textContent = t.artist;
    setTimeout(scLmFitTitle, 0); setTimeout(scLmFitTitle, 350);
    document.getElementById('smpTitle').textContent = t.title;
    document.getElementById('smpArtist').textContent = t.artist;
    scLmRenderNext();
    document.getElementById('slmQueueHint').textContent = _scQueue.length > 1 ? (index+1)+' of '+_scQueue.length+' · '+_scQLabel : _scQLabel;

    var artEl = document.getElementById('slmArt'), thumbEl = document.getElementById('smpThumb'), bg = document.getElementById('slmBg');
    var imgLoaded = new Promise(function(resolve){
        if(!t.thumb){
            artEl.innerHTML = thumbEl.innerHTML = '<i class="ri-music-2-line"></i>';
            bg.classList.remove('on'); bg.style.backgroundImage = '';
            resolve(); return;
        }
        var img = new Image();
        img.onload = img.onerror = function(){
            var ok = !!img.naturalWidth;
            artEl.innerHTML = ok ? '<img src="'+_scEsc(t.thumb)+'" alt="">' : '<i class="ri-music-2-line"></i>';
            thumbEl.innerHTML = ok ? '<img src="'+_scEsc(t.thumb)+'" alt="">' : '<i class="ri-music-2-line"></i>';
            if(ok){ bg.style.backgroundImage = "url('"+t.thumb.replace(/'/g,'%27')+"')"; bg.classList.add('on'); }
            else { bg.classList.remove('on'); }
            resolve();
        };
        img.src = t.thumb;
    });

    var saveBtn = document.getElementById('slmSaveBtn');
    if(t.saveMode){
        saveBtn.hidden = false;
        saveBtn.classList.toggle('saved', t.saveMode === 'unsave');
        saveBtn.querySelector('i').className = t.saveMode === 'unsave' ? 'ri-check-line' : 'ri-add-line';
        saveBtn.setAttribute('aria-label', t.saveMode === 'unsave' ? 'Remove from saved' : 'Save');
    } else { saveBtn.hidden = true; }

    if(_scLmAudio){ _scLmAudio.pause(); _scLmAudio = null; }
    ['slmFill','smpFill'].forEach(function(id){ document.getElementById(id).style.width = '0%'; });
    document.getElementById('slmKnob').style.left = '0%';
    document.getElementById('slmCur').textContent = '0:00';
    document.getElementById('slmDur').textContent = '0:00';
    _scSetPlayIcon(false);

    var minDelay = new Promise(function(r){ setTimeout(r, 300); });
    if(!t.src){
        Promise.all([imgLoaded, minDelay]).then(function(){ _scShowSkeleton(false); scToast('No audio file for this item'); });
        return;
    }

    var audio = _scLmAudio = new Audio(t.src);
    audio.loop = (_scRepeat === 'one');
    var metaLoaded = new Promise(function(resolve){
        audio.addEventListener('loadedmetadata', function(){ document.getElementById('slmDur').textContent = scFmt(this.duration); resolve(); }, { once:true });
        audio.addEventListener('error', resolve, { once:true });
    });
    audio.addEventListener('timeupdate', function(){
        if(!this.duration) return;
        var pct = this.currentTime / this.duration * 100;
        document.getElementById('slmFill').style.width = pct+'%';
        document.getElementById('slmKnob').style.left = pct+'%';
        document.getElementById('smpFill').style.width = pct+'%';
        document.getElementById('slmCur').textContent = scFmt(this.currentTime);
        document.getElementById('slmBar').setAttribute('aria-valuenow', Math.round(pct));
    });
    audio.addEventListener('play',  function(){ _scSetPlayIcon(true); });
    audio.addEventListener('pause', function(){ _scSetPlayIcon(false); });
    audio.addEventListener('ended', _scLmOnEnded);
    audio.addEventListener('error', function(){ scToast('This audio file could not be loaded'); _scSetPlayIcon(false); });

    _scUpdateMediaSession(t);

    Promise.all([imgLoaded, metaLoaded, minDelay]).then(function(){
        if(_scLmAudio !== audio) return;   /* user already moved on */
        _scShowSkeleton(false);
        if(autoplay !== false) audio.play().catch(function(){ _scSetPlayIcon(false); });
    });
}

function _scUpdateMediaSession(t){
    if(!('mediaSession' in navigator)) return;
    try {
        navigator.mediaSession.metadata = new MediaMetadata({ title: t.title, artist: t.artist, album: _scQLabel, artwork: t.thumb ? [{ src: t.thumb }] : [] });
        navigator.mediaSession.setActionHandler('play',  function(){ if(_scLmAudio) _scLmAudio.play(); });
        navigator.mediaSession.setActionHandler('pause', function(){ if(_scLmAudio) _scLmAudio.pause(); });
        navigator.mediaSession.setActionHandler('previoustrack', scLmPrev);
        navigator.mediaSession.setActionHandler('nexttrack', scLmNext);
    } catch(e){}
}

function scLmTogglePlay(){
    if(!_scLmAudio) return;
    if(_scLmAudio.paused) _scLmAudio.play().catch(function(){}); else _scLmAudio.pause();
}

function _scLmOnEnded(){
    if(_scQueue.length > 1){
        if(_scShuffle || _scQIndex < _scQueue.length - 1){ scLmNext(); return; }
        if(_scRepeat === 'all'){ scLmLoadTrack(0, true); return; }
    } else if(_scRepeat === 'all' && _scLmAudio){
        _scLmAudio.currentTime = 0; _scLmAudio.play().catch(function(){}); return;
    }
    _scSetPlayIcon(false);
    if(_scLmAudio) _scLmAudio.currentTime = 0;
}

function scLmNext(){
    if(_scQueue.length <= 1) return;
    var next;
    if(_scShuffle){
        do { next = Math.floor(Math.random() * _scQueue.length); } while(next === _scQIndex);
        _scHist.push(_scQIndex);
    } else {
        next = (_scQIndex + 1) % _scQueue.length;
    }
    scLmLoadTrack(next, true);
}
function scLmPrev(){
    if(_scQueue.length <= 1) return;
    if(_scLmAudio && _scLmAudio.currentTime > 3){ _scLmAudio.currentTime = 0; return; }
    var prev;
    if(_scShuffle && _scHist.length) prev = _scHist.pop();
    else prev = (_scQIndex - 1 + _scQueue.length) % _scQueue.length;
    scLmLoadTrack(prev, true);
}

function scLmFitTitle(){
    var t = document.getElementById('slmTitle'); if(!t || !t.parentNode) return;
    t.classList.remove('wrap'); t.style.fontSize = '';
    var box = t.parentNode.clientWidth; if(!box) return;
    var base = parseFloat(getComputedStyle(t).fontSize), w = t.scrollWidth * 1.04;
    if(w > box){
        var fs = base * box / w;
        if(fs < base * 0.5){ t.style.fontSize = (base * 0.5) + 'px'; t.classList.add('wrap'); }
        else t.style.fontSize = fs + 'px';
    }
}
function scLmRenderNext(){
    var row = document.getElementById('slmNext'); if(!row) return;
    if(_scQueue.length <= 1 || _scShuffle){ row.classList.remove('show'); return; }
    var n = _scQueue[(_scQIndex + 1) % _scQueue.length];
    document.getElementById('slmNextTitle').textContent = n.title || '';
    document.getElementById('slmNextArtist').textContent = n.artist || '';
    document.getElementById('slmNextThumb').innerHTML = n.thumb ? '<img src="'+_scEsc(n.thumb)+'" alt="">' : '<i class="ri-music-2-line"></i>';
    row.classList.add('show');
}
function scLmToggleShuffle(){
    _scShuffle = !_scShuffle; _scHist = [];
    scLmRenderNext();
    var b = document.getElementById('slmShuffleBtn');
    b.classList.toggle('on', _scShuffle);
    b.setAttribute('aria-pressed', _scShuffle ? 'true' : 'false');
    scToast(_scShuffle ? 'Shuffle on' : 'Shuffle off');
}
function scLmToggleRepeat(){
    _scRepeat = _scRepeat === 'off' ? 'all' : (_scRepeat === 'all' ? 'one' : 'off');
    var b = document.getElementById('slmRepeatBtn');
    b.classList.toggle('on', _scRepeat !== 'off');
    b.innerHTML = '<i class="'+(_scRepeat === 'one' ? 'ri-repeat-one-line' : 'ri-repeat-2-line')+'"></i>';
    if(_scLmAudio) _scLmAudio.loop = (_scRepeat === 'one');
    scToast(_scRepeat === 'off' ? 'Repeat off' : (_scRepeat === 'all' ? 'Repeat all' : 'Repeat this track'));
}

function scToggleSaveFromModal(){
    var t = _scQueue[_scQIndex];
    if(!t || !t.saveMode) return;
    if(t.saveMode === 'save') scSaveSound(t.id, t.kind); else scUnsaveSound(t.id, t.kind);
}

function scMinimizePlayer(){
    var modal = document.getElementById('scListenModal');
    modal.classList.remove('open', 'animate-in');
    document.body.style.overflow = '';
    document.getElementById('scMiniPlayer').classList.add('show');
}
function scExpandPlayer(){
    if(_scQIndex === -1) return;
    var modal = document.getElementById('scListenModal');
    modal.classList.remove('animate-in'); void modal.offsetWidth;
    modal.classList.add('open', 'animate-in');
    document.body.style.overflow = 'hidden';
    document.getElementById('scMiniPlayer').classList.remove('show');
}
function scCloseListenModal(){
    if(_scLmAudio){ _scLmAudio.pause(); _scLmAudio = null; }
    document.getElementById('scListenModal').classList.remove('open', 'animate-in', 'is-playing');
    document.getElementById('scMiniPlayer').classList.remove('show');
    document.getElementById('slmBg').classList.remove('on');
    document.body.style.overflow = '';
    _scQueue = []; _scQIndex = -1;
    _scMarkPlaying();
}

/* seek bar (pointer + keyboard) */
(function(){
    var bar = document.getElementById('slmBar'), track = bar.querySelector('.slm-bar-track');
    function seekFrom(e){
        if(!_scLmAudio || !_scLmAudio.duration) return;
        var r = track.getBoundingClientRect();
        _scLmAudio.currentTime = Math.min(Math.max((e.clientX - r.left) / r.width, 0), 1) * _scLmAudio.duration;
    }
    bar.addEventListener('pointerdown', function(e){
        if(!_scLmAudio || !_scLmAudio.duration) return;
        bar.classList.add('seeking');
        try { bar.setPointerCapture(e.pointerId); } catch(err){}
        seekFrom(e);
    });
    bar.addEventListener('pointermove', function(e){ if(bar.classList.contains('seeking')) seekFrom(e); });
    ['pointerup','pointercancel'].forEach(function(ev){ bar.addEventListener(ev, function(){ bar.classList.remove('seeking'); }); });
    bar.addEventListener('keydown', function(e){
        if(!_scLmAudio) return;
        if(e.key === 'ArrowRight'){ _scLmAudio.currentTime = Math.min(_scLmAudio.duration||0, _scLmAudio.currentTime + 5); e.preventDefault(); }
        if(e.key === 'ArrowLeft'){ _scLmAudio.currentTime = Math.max(0, _scLmAudio.currentTime - 5); e.preventDefault(); }
    });
})();

/* swipe the top bar down to minimize */
(function(){
    var topbar = document.getElementById('slmTopbar'), modal = document.getElementById('scListenModal');
    var lane = modal.querySelector('.slm-lane'), startY = null, curY = 0;
    topbar.addEventListener('pointerdown', function(e){
        if(e.target.closest('button')) return;
        startY = curY = e.clientY;
        modal.classList.add('dragging');
        try { topbar.setPointerCapture(e.pointerId); } catch(err){}
    });
    topbar.addEventListener('pointermove', function(e){
        if(startY === null) return;
        curY = e.clientY;
        lane.style.transform = 'translateY('+Math.max(0, curY - startY)+'px)';
    });
    function end(){
        if(startY === null) return;
        var delta = curY - startY;
        startY = null;
        modal.classList.remove('dragging');
        lane.style.transform = '';
        if(delta > 90) scMinimizePlayer();
    }
    topbar.addEventListener('pointerup', end);
    topbar.addEventListener('pointercancel', end);
})();

/* swipe the mini bar down to close */
(function(){
    var smp = document.getElementById('smpBar'), y0 = null;
    smp.addEventListener('touchstart', function(e){ y0 = e.touches[0].clientY; }, { passive:true });
    smp.addEventListener('touchmove', function(e){
        if(y0 === null) return;
        if(e.touches[0].clientY - y0 > 50){ scCloseListenModal(); y0 = null; }
    }, { passive:true });
    smp.addEventListener('touchend', function(){ y0 = null; });
})();

/* ══════════════════════════════════════════════════════════
   SAVE / UNSAVE / DELETE
══════════════════════════════════════════════════════════ */
function _scRowGone(id, kind, panelId, tabId){
    var panel = document.getElementById(panelId);
    var row = panel ? panel.querySelector('.sound-row[data-id="'+id+'"][data-kind="'+kind+'"]') : null;
    if(row){
        row.style.transition = 'opacity .25s, transform .25s';
        row.style.opacity = '0'; row.style.transform = 'scale(.97)';
        setTimeout(function(){
            row.remove();
            var grid = panel.querySelector('.sound-grid');
            if(grid && !grid.querySelector('.sound-row')){
                grid.innerHTML = '<div class="empty-state"><i class="ri-music-2-line"></i><span>Nothing here yet</span></div>';
            }
        }, 260);
    }
    _scBumpTab(tabId, -1);
}

function scSaveSound(id, kind){
    if(kind === 'audio'){ scToast('Open the audio to save it'); return; }
    fetch(window.location.href, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=save&sound_id='+id })
    .then(function(){ scToast('Saved'); })
    .catch(function(){ scToast('Something went wrong'); });
}
function scUnsaveSound(id, kind){
    var done = function(){ scToast('Removed from saved'); _scRowGone(id, kind || 'sound', 'panel-added', 'tabAdded'); };
    if(kind === 'audio'){
        _scFetch('sc_action=toggle_save_audio&audio_id='+id, function(d){ if(d.status === 'ok') done(); else scToast('Could not remove'); });
        return;
    }
    fetch(window.location.href, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=unsave&sound_id='+id })
    .then(done).catch(function(){ scToast('Something went wrong'); });
}
function scDeleteSound(id){
    if(!confirm('Delete this sound?')) return;
    fetch('delete_sound.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'sound_id='+id })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if(d.status === 'success'){ scToast('Sound deleted'); _scRowGone(id, 'sound', 'panel-uploaded', 'tabUploaded'); }
        else scToast(d.message || 'Could not delete');
    })
    .catch(function(){ scToast('Something went wrong'); });
}

/* ══════════════════════════════════════════════════════════
   COLLECTIONS
══════════════════════════════════════════════════════════ */
function scRenderFolders(){
    var grid = document.getElementById('scFoldersGrid'), fl = document.getElementById('scFoldersList'), det = document.getElementById('scFolderDetail');
    if(!grid) return;
    fl.style.display = 'block'; det.style.display = 'none';
    Array.from(grid.querySelectorAll('.folder-card, .au-folder-empty')).forEach(function(c){ c.remove(); });
    _scSetTabCount('tabFolders', _scFolders.length);

    if(!_scFolders.length && !_scIsOwner){
        var emp = document.createElement('div');
        emp.className = 'empty-state au-folder-empty';
        emp.style.gridColumn = '1 / -1';
        emp.innerHTML = '<i class="ri-folder-open-line"></i><span>No public collections yet</span>';
        grid.appendChild(emp);
        return;
    }

    _scFolders.forEach(function(f){
        var isP = f.is_public == 1, hasCover = !!f.cover_image;
        var card = document.createElement('div');
        card.className = 'folder-card';
        card.tabIndex = 0;
        card.setAttribute('role', 'button');
        card.setAttribute('aria-label', 'Open collection '+f.name);
        var cells = '<div class="fp-cell"><i class="ri-music-line"></i></div>'.repeat(4);
        card.innerHTML =
            '<div class="fc-wrap"><div class="folder-card-stack">'+
                (isP ? '<div class="folder-pub-badge"><i class="ri-earth-line"></i> Public</div>' : (_scIsOwner ? '<div class="folder-pub-badge priv"><i class="ri-lock-line"></i> Private</div>' : ''))+
                '<div class="stack-front'+(hasCover?' has-cover':'')+'"'+(hasCover?' style="background-image:url(\'uploads/folder_covers/'+_scEsc(f.cover_image)+'\')"':'')+'>'+cells+'</div>'+
            '</div></div>'+
            '<div class="folder-info">'+
                '<div class="folder-name">'+_scEsc(f.name)+'</div>'+
                '<div class="folder-count" id="sfc-'+f.id+'">'+_scPlural(f.item_count)+'</div>'+
            '</div>';

        if(_scIsOwner){
            var ob = document.createElement('button');
            ob.type = 'button'; ob.className = 'folder-opts-btn'; ob.title = 'Options';
            ob.setAttribute('aria-label', 'Collection options');
            ob.innerHTML = '<i class="ri-more-2-line"></i>';
            ob.addEventListener('click', function(e){ e.stopPropagation(); scOpenOpts(e, f.id, f.name, isP); });
            card.querySelector('.folder-card-stack').appendChild(ob);
        }
        card.addEventListener('click', function(){ scOpenDetail(f.id, f.name); });
        card.addEventListener('keydown', function(e){ if(e.key === 'Enter'){ scOpenDetail(f.id, f.name); } });
        grid.appendChild(card);
    });
    var nb = grid.querySelector('.new-folder-btn');
    if(nb) grid.prepend(nb);
}

function scOpenDetail(folderId, folderName){
    _scActiveFolderId = folderId;
    var fl = document.getElementById('scFoldersList'), det = document.getElementById('scFolderDetail');
    var dt = document.getElementById('scDetailTitle'), dc = document.getElementById('scDetailCount'), dg = document.getElementById('scDetailGrid');
    fl.style.display = 'none'; det.style.display = 'block';
    dt.textContent = folderName; dc.textContent = 'Loading...';
    dg.innerHTML = '<div class="au-loading"><i class="ri-loader-4-line"></i> Loading...</div>';
    document.getElementById('searchRow').classList.add('off');

    _scFetch('sc_action=get_folder_items&folder_id='+folderId, function(data){
        if(data.status !== 'ok'){ dg.innerHTML = '<div class="empty-state"><i class="ri-folder-open-line"></i><span>Could not load this collection</span></div>'; dc.textContent = ''; return; }
        var items = data.items || [];
        dc.textContent = _scPlural(items.length);
        dg.innerHTML = '';
        if(!items.length){
            dg.innerHTML = '<div class="empty-state"><i class="ri-folder-open-line"></i><span>No sounds yet</span>'+
                (_scIsOwner ? '<small>Use the folder button on any sound to add it here.</small>' : '')+'</div>';
            return;
        }
        items.forEach(function(item){
            var kind = item.kind || 'sound';
            var base = kind === 'audio' ? 'uploads/' : 'uploads/sounds/';
            var thumb = kind === 'audio' ? (item.cover_art ? 'uploads/'+item.cover_art : '') : (item.picture ? 'uploads/sounds/covers/'+item.picture : '');
            var row = document.createElement('div');
            row.className = 'sound-row';
            row.dataset.id = item.id; row.dataset.kind = kind; row.dataset.save = '';
            row.dataset.src = base + item.file_name; row.dataset.thumb = thumb;
            row.setAttribute('onclick', 'scOpenListenModal(this)');
            row.innerHTML =
                '<div class="sound-thumb">'+(thumb ? '<img src="'+_scEsc(thumb)+'" alt="" loading="lazy">' : '<i class="ri-music-2-line"></i>')+'</div>'+
                '<div class="sound-meta">'+
                    '<div class="sound-title">'+_scEsc(item.title)+'</div>'+
                    '<div class="sound-artist">'+_scEsc(item.username)+'</div>'+
                '</div>'+
                '<div class="sound-actions" onclick="event.stopPropagation()">'+
                    '<a class="s-btn" href="'+_scEsc(base+item.file_name)+'" download title="Download" aria-label="Download"><i class="ri-download-line"></i></a>'+
                '</div>';
            if(_scIsOwner){
                var rb = document.createElement('button');
                rb.type = 'button'; rb.className = 's-remove'; rb.title = 'Remove from collection';
                rb.setAttribute('aria-label', 'Remove from collection');
                rb.innerHTML = '<i class="ri-close-line"></i>';
                rb.addEventListener('click', function(e){ e.stopPropagation(); scRemoveFromFolder(item.id, rb, kind); });
                row.querySelector('.sound-actions').appendChild(rb);
            }
            dg.appendChild(row);
        });
        _scMarkPlaying();
    });
}

function scCloseDetail(){
    _scActiveFolderId = null;
    document.getElementById('scFoldersList').style.display = 'block';
    document.getElementById('scFolderDetail').style.display = 'none';
    document.getElementById('searchRow').classList.add('off');
}

function scRemoveFromFolder(itemId, btn, kind){
    if(!_scActiveFolderId || !_scIsOwner) return;
    var isAudio = kind === 'audio';
    var body = 'sc_action='+(isAudio ? 'remove_audio_from_folder' : 'remove_from_folder')+'&folder_id='+_scActiveFolderId+'&'+(isAudio ? 'audio_id=' : 'sound_id=')+itemId;
    _scFetch(body, function(d){
        if(d.status !== 'ok'){ scToast('Could not remove'); return; }
        var row = btn.closest('.sound-row');
        if(row){
            row.style.transition = 'opacity .25s, transform .25s'; row.style.opacity = '0'; row.style.transform = 'scale(.97)';
            setTimeout(function(){ row.remove(); _scUpdateDetailCount(); }, 260);
        }
        _scFolders.forEach(function(f){ if(f.id == _scActiveFolderId) f.item_count = Math.max(0, f.item_count - 1); });
        scToast('Removed from collection');
    });
}
function _scUpdateDetailCount(){
    var dg = document.getElementById('scDetailGrid'), dc = document.getElementById('scDetailCount');
    var n = dg.querySelectorAll('.sound-row').length;
    dc.textContent = _scPlural(n);
    if(!n) dg.innerHTML = '<div class="empty-state"><i class="ri-folder-open-line"></i><span>No sounds yet</span></div>';
}

/* ── add to collection sheet ── */
function scOpenAddToFolder(id, kind){
    if(!_scIsOwner){ scToast('Log in to use collections'); return; }
    kind = kind || 'sound';
    _scActiveSound = { id: id, kind: kind };
    var list = document.getElementById('atfList');
    document.getElementById('scAddFolderModal').classList.add('open');
    if(!_scFolders.length){ list.innerHTML = '<div class="md-msg">No collections yet.<br>Create one below.</div>'; return; }
    list.innerHTML = '';
    _scFolders.forEach(function(f){
        var item = document.createElement('div');
        item.className = 'atf-item';
        var icon = f.cover_image ? '<img src="uploads/folder_covers/'+_scEsc(f.cover_image)+'" alt="">' : '<i class="ri-folder-music-line"></i>';
        item.innerHTML =
            '<div class="atf-icon">'+icon+'</div>'+
            '<div class="atf-info"><div class="atf-name">'+_scEsc(f.name)+'</div><div class="atf-count" id="atfc-'+f.id+'">'+_scPlural(f.item_count)+'</div></div>'+
            '<div class="atf-check" id="atfk-'+f.id+'">✓</div>';
        item.addEventListener('click', function(){ scToggleFolderItem(f.id); });
        list.appendChild(item);
        _scFetch('sc_action=get_folder_items&folder_id='+f.id, function(d){
            if(d.status !== 'ok') return;
            var has = (d.items || []).some(function(i){ return i.id == id && (i.kind || 'sound') === kind; });
            var chk = document.getElementById('atfk-'+f.id);
            if(chk) chk.classList.toggle('on', has);
        });
    });
}
function scCloseAddToFolder(){
    document.getElementById('scAddFolderModal').classList.remove('open');
    _scActiveSound = null;
}
function _scFolderCountEls(folderId){
    var f = _scFolders.find(function(x){ return x.id == folderId; });
    if(!f) return;
    ['atfc-','sfc-'].forEach(function(p){ var el = document.getElementById(p+folderId); if(el) el.textContent = _scPlural(f.item_count); });
}
function scToggleFolderItem(folderId){
    if(!_scActiveSound) return;
    var id = _scActiveSound.id, isAudio = _scActiveSound.kind === 'audio';
    var chk = document.getElementById('atfk-'+folderId), isAdded = chk && chk.classList.contains('on');
    var key = isAudio ? 'audio_id=' : 'sound_id=';
    if(isAdded){
        _scFetch('sc_action='+(isAudio ? 'remove_audio_from_folder' : 'remove_from_folder')+'&folder_id='+folderId+'&'+key+id, function(d){
            if(d.status !== 'ok'){ scToast('Could not remove'); return; }
            if(chk) chk.classList.remove('on');
            _scFolders.forEach(function(f){ if(f.id == folderId) f.item_count = d.item_count !== undefined ? d.item_count : Math.max(0, f.item_count - 1); });
            _scFolderCountEls(folderId);
            scToast('Removed from collection');
        });
    } else {
        _scFetch('sc_action='+(isAudio ? 'add_audio_to_folder' : 'add_to_folder')+'&folder_id='+folderId+'&'+key+id, function(d){
            if(d.status === 'already'){ if(chk) chk.classList.add('on'); scToast('Already in this collection'); return; }
            if(d.status !== 'ok'){ scToast('Could not add'); return; }
            if(chk) chk.classList.add('on');
            _scFolders.forEach(function(f){ if(f.id == folderId) f.item_count = d.item_count !== undefined ? d.item_count : f.item_count + 1; });
            _scFolderCountEls(folderId);
            scToast('Added to collection');
        });
    }
}

/* ── collection options ── */
function scOpenOpts(e, fid, fname, isPublic){
    if(!_scIsOwner) return;
    _scOptsId = fid; _scOptsName = fname; _scOptsPublic = isPublic;
    document.getElementById('scOptsName').textContent = fname;
    document.getElementById('scOptsVisLabel').textContent = isPublic ? 'Make private' : 'Make public';
    document.getElementById('scOptsVisSub').textContent = isPublic ? 'Only you will see it' : 'Shown to people who visit your sound page';
    document.querySelector('#scOptsVisItem i').className = isPublic ? 'ri-lock-line' : 'ri-earth-line';
    document.getElementById('scOptsDd').classList.add('open');
}
function scCloseOpts(){ document.getElementById('scOptsDd').classList.remove('open'); }
function scOptsDo(action){
    scCloseOpts();
    if(action === 'rename'){ scOpenNameModal('rename', _scOptsName); }
    else if(action === 'cover'){ scOpenNameModal('cover', _scOptsName); }
    else if(action === 'delete'){
        if(!confirm('Delete "'+_scOptsName+'"?\nThe sounds inside will not be deleted.')) return;
        _scFetch('sc_action=delete_folder&folder_id='+_scOptsId, function(d){
            if(d.status !== 'ok'){ scToast('Could not delete'); return; }
            _scFolders = _scFolders.filter(function(f){ return f.id != _scOptsId; });
            scRenderFolders(); scToast('Collection deleted');
        });
    } else if(action === 'toggle_vis'){
        var nv = _scOptsPublic ? 0 : 1;
        _scFetch('sc_action=set_visibility&folder_id='+_scOptsId+'&is_public='+nv, function(d){
            if(d.status !== 'ok'){ scToast('Could not update'); return; }
            _scFolders.forEach(function(f){ if(f.id == _scOptsId) f.is_public = nv; });
            scRenderFolders(); scToast(nv ? 'Collection is now public' : 'Collection is now private');
        });
    }
}
/* ── name / cover modal ── */
function scOpenNameModal(mode, currentName){
    _scNmMode = mode; _scNmCoverFile = null;
    var inp = document.getElementById('scNmInput'), coverRow = document.querySelector('#scNameModal .nm-cover-row');
    document.getElementById('scNmNameBlock').style.display = mode === 'cover' ? 'none' : 'block';
    document.getElementById('scVisRow').style.display = mode === 'create' ? 'flex' : 'none';
    coverRow.style.display = mode === 'rename' ? 'none' : 'flex';
    document.getElementById('scNmTitle').textContent = mode === 'cover' ? 'Change cover' : (mode === 'rename' ? 'Rename collection' : 'New collection');
    inp.value = mode === 'rename' ? (currentName || '') : '';
    if(mode === 'create') document.getElementById('scVisTog').checked = false;
    document.getElementById('scNmCoverPreview').innerHTML = '<i class="ri-image-line"></i>';
    document.getElementById('scNmCoverInput').value = '';
    document.getElementById('scNmCoverLabelText').textContent = mode === 'cover' ? 'Choose a cover image' : 'Add cover image (optional)';
    document.getElementById('scNameModal').classList.add('open');
    if(mode !== 'cover') setTimeout(function(){ inp.focus(); }, 280);
}
function scCloseNameModal(){ document.getElementById('scNameModal').classList.remove('open'); }

function scNmCoverChange(input){
    var f = input.files[0];
    if(!f) return;
    _scNmCoverFile = f;
    var reader = new FileReader();
    reader.onload = function(e){ document.getElementById('scNmCoverPreview').innerHTML = '<img src="'+e.target.result+'" alt="">'; };
    reader.readAsDataURL(f);
    document.getElementById('scNmCoverLabelText').textContent = f.name;
}

function scSaveName(){
    if(_scNmMode === 'cover'){
        if(!_scNmCoverFile || !_scOptsId){ scToast('Please choose an image'); return; }
        var fd = new FormData();
        fd.append('sc_action', 'set_folder_cover'); fd.append('folder_id', _scOptsId); fd.append('cover', _scNmCoverFile);
        _scFetchForm(fd, function(d){
            if(d.status !== 'ok'){ scToast('Could not update cover'); return; }
            _scFolders.forEach(function(f){ if(f.id == _scOptsId) f.cover_image = d.cover_image; });
            scCloseNameModal(); scRenderFolders(); scToast('Cover updated');
        });
        return;
    }
    var name = (document.getElementById('scNmInput').value || '').trim();
    if(!name){ scToast('Please enter a name'); return; }

    if(_scNmMode === 'create'){
        var isP = document.getElementById('scVisTog').checked ? 1 : 0;
        _scFetch('sc_action=create_folder&name='+encodeURIComponent(name)+'&is_public='+isP, function(d){
            if(d.status !== 'ok'){ scToast('Could not create collection'); return; }
            var nf = { id:d.id, name:d.name, is_public:d.is_public || 0, cover_image:null, item_count:0 };
            _scFolders.unshift(nf);
            var finish = function(){ scCloseNameModal(); scSwitchTab('folders'); scRenderFolders(); scToast('Collection created'); };
            if(_scNmCoverFile){
                var fd2 = new FormData();
                fd2.append('sc_action', 'set_folder_cover'); fd2.append('folder_id', d.id); fd2.append('cover', _scNmCoverFile);
                _scFetchForm(fd2, function(cd){ if(cd.status === 'ok') nf.cover_image = cd.cover_image; finish(); });
            } else finish();
        });
    } else {
        _scFetch('sc_action=rename_folder&folder_id='+_scOptsId+'&name='+encodeURIComponent(name), function(d){
            if(d.status !== 'ok'){ scToast('Could not rename'); return; }
            _scFolders.forEach(function(f){ if(f.id == _scOptsId) f.name = name; });
            scCloseNameModal(); scRenderFolders(); scToast('Collection renamed');
        });
    }
}

/* ── keyboard ── */
document.addEventListener('keydown', function(e){
    var _zm = document.getElementById('cardCaptionModal'); if(_zm && _zm.classList.contains('ccm-open')) return;   /* the Zeems modal handles its own keys */

    if(e.key === 'Escape'){
        if(document.getElementById('scOptsDd').classList.contains('open')){ scCloseOpts(); return; }
        if(document.getElementById('scNameModal').classList.contains('open')){ scCloseNameModal(); return; }
        if(document.getElementById('scAddFolderModal').classList.contains('open')){ scCloseAddToFolder(); return; }
        if(document.getElementById('admQrOverlay').classList.contains('open')){ admCloseQr(); return; }
        if(document.getElementById('scListenModal').classList.contains('open')){ scMinimizePlayer(); return; }
        if(document.getElementById('audioDetailPanel').classList.contains('show')){ closeAudioDetailModal(); return; }
    }

    if(!document.getElementById('scListenModal').classList.contains('open')) return;
    var tag = (e.target.tagName || '').toLowerCase();
    if(tag === 'input' || tag === 'textarea') return;
    if(e.key === ' ' && tag !== 'button'){ e.preventDefault(); scLmTogglePlay(); }
    else if(e.key === 'ArrowRight' && tag !== 'div' && _scLmAudio){ _scLmAudio.currentTime = Math.min(_scLmAudio.duration || 0, _scLmAudio.currentTime + 5); }
    else if(e.key === 'ArrowLeft' && tag !== 'div' && _scLmAudio){ _scLmAudio.currentTime = Math.max(0, _scLmAudio.currentTime - 5); }
});
</script>
</body>
</html>